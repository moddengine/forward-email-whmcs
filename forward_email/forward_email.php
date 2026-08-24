<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Addon\Setting;

if (!function_exists('forward_email_config')) {
define('FORWARD_EMAIL_TABLE_DOMAINS', 'mod_forward_email_domains');
define('FORWARD_EMAIL_TABLE_RATE_LIMITS', 'mod_forward_email_rate_limits');
define('FORWARD_EMAIL_MUTATION_LIMIT', 30);
define('FORWARD_EMAIL_MUTATION_WINDOW', 60);
define('FORWARD_EMAIL_OPERATION_LEASE', 900);
define('FORWARD_EMAIL_API_BASE', 'https://api.forwardemail.net');

final class ForwardEmailApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 0)
    {
        parent::__construct($message, $httpStatus);
    }
}

/** @return array<string, mixed> */
function forward_email_config(): array
{
    return [
        'name' => 'Forward Email',
        'description' => 'Email forwarding for hosting services using Forward Email and WHMCS-DNS',
        'author' => 'Modd Engine',
        'language' => 'english',
        'version' => '1.1.1',
        'fields' => [
            'api_key' => [
                'FriendlyName' => 'Forward Email API Key',
                'Type' => 'password',
                'Size' => '64',
                'Description' => 'API token from forwardemail.net.',
            ],
        ],
    ];
}

function forward_email_create_tables(): void
{
    if (!Capsule::schema()->hasTable(FORWARD_EMAIL_TABLE_DOMAINS)) {
        Capsule::schema()->create(FORWARD_EMAIL_TABLE_DOMAINS, function ($table): void {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->bigIncrements('id');
            $table->bigInteger('client_id')->unsigned()->index();
            $table->bigInteger('service_id')->unsigned()->nullable()->unique();
            $table->string('domain_name', 253)->unique();
            $table->string('forward_email_id', 100)->nullable();
            $table->string('status', 32);
            $table->text('managed_dns_records')->nullable();
            $table->string('operation_token', 64)->nullable();
            $table->string('operation_type', 32)->nullable();
            $table->dateTime('operation_started_at')->nullable();
            $table->dateTime('sender_dns_configured_at')->nullable();
            $table->dateTime('next_retry_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->dateTime('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    if (!Capsule::schema()->hasTable(FORWARD_EMAIL_TABLE_RATE_LIMITS)) {
        Capsule::schema()->create(FORWARD_EMAIL_TABLE_RATE_LIMITS, function ($table): void {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->bigInteger('client_id')->unsigned()->unique();
            $table->integer('window_started_at');
            $table->integer('attempts');
        });
    }

    foreach ([
        'service_id' => static fn ($table) => $table->bigInteger('service_id')->unsigned()->nullable()->unique(),
        'operation_token' => static fn ($table) => $table->string('operation_token', 64)->nullable(),
        'operation_type' => static fn ($table) => $table->string('operation_type', 32)->nullable(),
        'operation_started_at' => static fn ($table) => $table->dateTime('operation_started_at')->nullable(),
        'sender_dns_configured_at' => static fn ($table) => $table->dateTime('sender_dns_configured_at')->nullable(),
    ] as $column => $add) {
        if (!Capsule::schema()->hasColumn(FORWARD_EMAIL_TABLE_DOMAINS, $column)) {
            Capsule::schema()->table(FORWARD_EMAIL_TABLE_DOMAINS, $add);
        }
    }

    forward_email_backfill_services();
}

function forward_email_backfill_services(): void
{
    if (!function_exists('whmcs_dns_registrable_domain')) {
        return;
    }
    $rows = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->whereNull('service_id')->get();
    foreach ($rows as $row) {
        $matches = [];
        $services = Capsule::table('tblhosting')->select('id', 'domain')
            ->where('userid', (int) $row->client_id)->where('domainstatus', 'Active')->where('domain', '<>', '')->get();
        foreach ($services as $service) {
            if (whmcs_dns_registrable_domain((string) $service->domain) === (string) $row->domain_name) {
                $matches[] = (int) $service->id;
            }
        }
        if (count($matches) === 1) {
            Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->update(['service_id' => $matches[0]]);
        }
    }
}

/** @return array<string, string> */
function forward_email_activate(): array
{
    try {
        forward_email_load_dns();
        forward_email_create_tables();
        return ['status' => 'success', 'description' => 'Forward Email addon activated.'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/** @return array<string, string> */
function forward_email_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Forward Email addon deactivated; forwarding state was preserved.',
    ];
}

/** @param array<string, mixed> $vars */
function forward_email_upgrade(array $vars): void
{
    forward_email_load_dns();
    forward_email_create_tables();
}

function forward_email_load_dns(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    if (defined('WHMCSDNS_INTEGRATION_API_VERSION')) {
        if (!forward_email_supported_dns_version(constant('WHMCSDNS_INTEGRATION_API_VERSION'))) {
            throw new RuntimeException('WHMCS-DNS integration API version 1 is required.');
        }
        forward_email_require_dns_activation();
        $loaded = true;
        return;
    }

    $file = dirname(__DIR__) . '/whmcs_dns/whmcs_dns.php';
    if (!is_file($file)) {
        throw new RuntimeException('The WHMCS-DNS addon is required.');
    }
    require_once $file;

    $version = defined('WHMCSDNS_INTEGRATION_API_VERSION')
        ? constant('WHMCSDNS_INTEGRATION_API_VERSION')
        : null;
    if (!forward_email_supported_dns_version($version) || !function_exists('whmcs_dns_integration_status')
        || !function_exists('whmcs_dns_integration_list_records')
        || !function_exists('whmcs_dns_integration_apply_records')) {
        throw new RuntimeException('WHMCS-DNS integration API version 1 is required.');
    }
    forward_email_require_dns_activation();
    $loaded = true;
}

function forward_email_supported_dns_version(mixed $version): bool
{
    return $version === 1;
}

function forward_email_require_dns_activation(): void
{
    if (!Capsule::table('tbladdonmodules')->where('module', 'whmcs_dns')->exists()) {
        throw new RuntimeException('The WHMCS-DNS addon must be activated.');
    }
}

function forward_email_api_key(): string
{
    $key = (string) (Setting::getSettingValueForModule('forward_email', 'api_key') ?? '');
    if ($key === '') {
        throw new RuntimeException('Forward Email is not configured.');
    }
    return $key;
}

/**
 * @param array<string, scalar|array<int, string>>|null $data
 * @return array<string, mixed>|array<int, mixed>
 */
function forward_email_api_request(string $apiKey, string $method, string $path, ?array $data = null): array
{
    $curl = curl_init(FORWARD_EMAIL_API_BASE . $path);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the Forward Email request.');
    }

    $fields = [];
    foreach ($data ?? [] as $key => $value) {
        $fields[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $apiKey . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    if ($fields !== []) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($response === false) {
        throw new ForwardEmailApiException('Forward Email request failed: ' . $error);
    }
    if ($response === '' && $status >= 200 && $status < 300) {
        return [];
    }

    try {
        $body = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new ForwardEmailApiException('Forward Email returned invalid JSON.', $status);
    }
    if (!is_array($body)) {
        throw new ForwardEmailApiException('Forward Email returned an invalid response.', $status);
    }
    if ($status < 200 || $status >= 300) {
        $message = $body['message'] ?? $body['error'] ?? 'Forward Email rejected the request.';
        throw new ForwardEmailApiException(is_string($message) ? $message : 'Forward Email rejected the request.', $status);
    }
    return $body;
}

/** @return array<string, mixed>|null */
function forward_email_get_domain(string $apiKey, string $domain): ?array
{
    try {
        $result = forward_email_api_request($apiKey, 'GET', '/v1/domains/' . rawurlencode($domain));
        return $result;
    } catch (ForwardEmailApiException $e) {
        if ($e->httpStatus === 404) {
            return null;
        }
        throw $e;
    }
}

function forward_email_remote_verified(string $apiKey, string $domain): bool
{
    $result = forward_email_api_request($apiKey, 'GET', '/v1/domains/' . rawurlencode($domain) . '/verify-records');
    return ($result['has_mx_record'] ?? false) === true && ($result['has_txt_record'] ?? false) === true;
}

/** @return array<int, array<string, mixed>> */
function forward_email_list_domains(string $apiKey): array
{
    $domains = [];
    $firstId = null;
    for ($page = 1; $page <= 100; $page++) {
        $response = forward_email_api_request($apiKey, 'GET', '/v1/domains?pagination=true&limit=50&page=' . $page);
        $rows = array_is_list($response) ? $response : ($response['domains'] ?? $response['results'] ?? $response['data'] ?? []);
        if (!is_array($rows)) {
            throw new RuntimeException('Forward Email returned an invalid domain list.');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
                throw new RuntimeException('Forward Email returned an invalid domain.');
            }
        }
        if ($page > 1 && isset($rows[0]['id']) && $rows[0]['id'] === $firstId) {
            break;
        }
        $firstId ??= $rows[0]['id'] ?? null;
        array_push($domains, ...$rows);
        if (count($rows) < 50) {
            break;
        }
    }
    return $domains;
}

/**
 * @param array<int|string, mixed> $response
 * @return array<int, array<string, mixed>>
 */
function forward_email_alias_rows(array $response): array
{
    $rows = array_is_list($response) ? $response : ($response['aliases'] ?? $response['results'] ?? $response['data'] ?? []);
    if (!is_array($rows)) {
        throw new RuntimeException('Forward Email returned an invalid alias list.');
    }
    foreach ($rows as $row) {
        if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
            throw new RuntimeException('Forward Email returned an invalid alias.');
        }
    }
    /** @var array<int, array<string, mixed>> $rows */
    return array_values($rows);
}

/** @return array<int, array<string, mixed>> */
function forward_email_list_aliases(string $apiKey, string $domain): array
{
    return forward_email_alias_rows(forward_email_api_request(
        $apiKey,
        'GET',
        '/v1/domains/' . rawurlencode($domain) . '/aliases?limit=1000'
    ));
}

function forward_email_remove_catchalls(string $apiKey, string $domain): int
{
    $deleted = 0;
    foreach (forward_email_catchall_ids(forward_email_list_aliases($apiKey, $domain)) as $aliasId) {
        forward_email_api_request(
            $apiKey,
            'DELETE',
            '/v1/domains/' . rawurlencode($domain) . '/aliases/' . rawurlencode($aliasId)
        );
        $deleted++;
    }
    return $deleted;
}

/**
 * @param array<int, array<string, mixed>> $aliases
 * @return array<int, string>
 */
function forward_email_catchall_ids(array $aliases): array
{
    return array_values(array_map(
        static fn (array $alias): string => (string) $alias['id'],
        array_filter($aliases, static fn (array $alias): bool => ($alias['name'] ?? null) === '*')
    ));
}

/** @param mixed $recipients */
function forward_email_recipients_display(mixed $recipients): string
{
    if (is_array($recipients)) {
        return implode(', ', array_filter($recipients, 'is_string'));
    }
    return is_string($recipients) ? $recipients : '';
}

/** @return array{name: string, type: string, value: string, ttl: int, priority: int|null, weight: null, port: null} */
function forward_email_record(string $name, string $type, string $value, ?int $priority = null): array
{
    return [
        'name' => $name === '' ? '@' : strtolower(rtrim($name, '.')),
        'type' => strtoupper($type),
        'value' => trim($value),
        'ttl' => 3600,
        'priority' => $priority,
        'weight' => null,
        'port' => null,
    ];
}

/**
 * @param array<string, mixed> $domain
 * @return array<int, array{name: string, type: string, value: string, ttl: int, priority: int|null, weight: null, port: null}>
 */
function forward_email_forwarding_dns_records(array $domain): array
{
    $verification = $domain['verification_record'] ?? null;
    if (!is_string($verification) || $verification === '') {
        throw new RuntimeException('Forward Email did not return domain verification settings.');
    }
    return [
        forward_email_record('@', 'MX', 'mx1.forwardemail.net', 0),
        forward_email_record('@', 'MX', 'mx2.forwardemail.net', 0),
        forward_email_record('@', 'TXT', 'forward-email-site-verification=' . $verification),
    ];
}

/**
 * @param array<string, mixed> $domain
 * @return array<int, array{name: string, type: string, value: string, ttl: int, priority: int|null, weight: null, port: null}>
 */
function forward_email_sender_dns_records(array $domain): array
{
    $smtp = $domain['smtp_dns_records'] ?? null;
    if (!is_array($smtp)) {
        throw new RuntimeException('Forward Email did not return sender verification settings.');
    }
    $records = [forward_email_record('@', 'TXT', 'v=spf1 include:spf.forwardemail.net ~all')];
    foreach (['dkim' => 'TXT', 'return_path' => 'CNAME', 'dmarc' => 'TXT'] as $key => $type) {
        $item = $smtp[$key] ?? null;
        if (!is_array($item) || !is_string($item['name'] ?? null) || !is_string($item['value'] ?? null)
            || $item['name'] === '' || $item['value'] === '') {
            throw new RuntimeException("Forward Email did not return the {$key} DNS record.");
        }
        $records[] = forward_email_record($item['name'], $type, $item['value']);
    }
    return $records;
}

/** @param array<string, mixed> $record */
function forward_email_record_key(array $record, bool $ignoreTtl = false): string
{
    $name = strtolower(rtrim((string) ($record['name'] ?? $record['host'] ?? ''), '.'));
    if ($name === '') {
        $name = '@';
    }
    $parts = [
        $name,
        strtoupper((string) ($record['type'] ?? '')),
        trim((string) ($record['value'] ?? '')),
    ];
    if (!$ignoreTtl) {
        $parts[] = (string) ($record['priority'] ?? '');
        $parts[] = (string) ($record['ttl'] ?? 3600);
    }
    return implode("\0", $parts);
}

/**
 * @param array<int, array<string, mixed>> $current
 * @param array<int, array<string, mixed>> $desired
 * @return array<int, array<string, mixed>>
 */
function forward_email_dns_delete_plan(array $current, array $desired): array
{
    $wanted = array_fill_keys(array_map('forward_email_record_key', $desired), true);
    $wantedValues = array_fill_keys(array_map(
        static fn (array $record): string => forward_email_record_key($record, true),
        $desired
    ), true);
    $delete = [];
    $kept = [];
    foreach ($current as $record) {
        $key = forward_email_record_key($record);
        if (isset($wanted[$key]) && !isset($kept[$key])) {
            $kept[$key] = true;
            continue;
        }
        $name = strtolower(rtrim((string) ($record['name'] ?? $record['host'] ?? ''), '.'));
        $name = $name === '' ? '@' : $name;
        $type = strtoupper((string) ($record['type'] ?? ''));
        $replace = ($name === '@' && $type === 'MX')
            || isset($wantedValues[forward_email_record_key($record, true)]);
        if ($replace || isset($wanted[$key])) {
            $delete[$key] = $record;
        }
    }
    return array_values($delete);
}

/**
 * @param array<int, array<string, mixed>> $current
 * @param array<int, array<string, mixed>> $desired
 * @return array<int, array<string, mixed>>
 */
function forward_email_sender_dns_delete_plan(array $current, array $desired): array
{
    $wanted = array_fill_keys(array_map('forward_email_record_key', $desired), true);
    $dkimName = $returnPathName = $dmarcName = null;
    foreach ($desired as $record) {
        $name = strtolower((string) $record['name']);
        if (str_contains($name, '._domainkey')) {
            $dkimName = $name;
        } elseif (strtoupper((string) $record['type']) === 'CNAME') {
            $returnPathName = $name;
        } elseif ($name === '_dmarc') {
            $dmarcName = $name;
        }
    }
    $delete = [];
    $kept = [];
    foreach ($current as $record) {
        $key = forward_email_record_key($record);
        if (isset($wanted[$key]) && !isset($kept[$key])) {
            $kept[$key] = true;
            continue;
        }
        $name = strtolower(rtrim((string) ($record['name'] ?? $record['host'] ?? ''), '.')) ?: '@';
        $type = strtoupper((string) ($record['type'] ?? ''));
        $value = ltrim(trim((string) ($record['value'] ?? '')), '"');
        $replace = ($name === '@' && $type === 'TXT' && str_starts_with(strtolower($value), 'v=spf1'))
            || ($name === $returnPathName)
            || ($name === $dmarcName
                && $type === 'TXT' && str_starts_with(strtoupper($value), 'V=DMARC1'))
            || ($name === $dkimName
                && ($type === 'CNAME' || ($type === 'TXT' && str_starts_with(strtoupper($value), 'V=DKIM1'))));
        if ($replace || isset($wanted[$key])) {
            $delete[$key] = $record;
        }
    }
    return array_values($delete);
}

/**
 * @param array<int, array<string, mixed>> $current
 * @param array<int, array<string, mixed>> $managed
 * @return array<int, array<string, mixed>>
 */
function forward_email_managed_records_present(array $current, array $managed): array
{
    $managedKeys = array_fill_keys(array_map(
        static fn (array $record): string => forward_email_record_key($record, true),
        $managed
    ), true);
    return array_values(array_filter(
        $current,
        static fn (array $record): bool => isset($managedKeys[forward_email_record_key($record, true)])
    ));
}

function forward_email_enforce_mutation_limit(int $clientId): void
{
    $now = time();
    Capsule::table(FORWARD_EMAIL_TABLE_RATE_LIMITS)->insertOrIgnore([
        'client_id' => $clientId,
        'window_started_at' => 0,
        'attempts' => 0,
    ]);
    Capsule::connection()->transaction(function () use ($clientId, $now): void {
        $query = Capsule::table(FORWARD_EMAIL_TABLE_RATE_LIMITS)->where('client_id', $clientId);
        $row = $query->lockForUpdate()->first();
        if (!$row) {
            throw new RuntimeException('Email forwarding rate limit is unavailable.');
        }
        $started = (int) $row->window_started_at;
        if ($now < $started || $now - $started >= FORWARD_EMAIL_MUTATION_WINDOW) {
            $query->update(['window_started_at' => $now, 'attempts' => 1]);
            return;
        }
        if ((int) $row->attempts >= FORWARD_EMAIL_MUTATION_LIMIT) {
            throw new RuntimeException('Too many email forwarding changes. Please wait a minute.');
        }
        $query->increment('attempts');
    });
}

/** @return array{client_id: int, service_id: int, domain: string}|null */
function forward_email_service_context(int $clientId, int $serviceId, bool $activeOnly = true): ?array
{
    $query = Capsule::table('tblhosting')->select('userid', 'domain', 'domainstatus')->where('id', $serviceId);
    if ($clientId > 0) {
        $query->where('userid', $clientId);
    }
    $service = $query->first();
    if (!$service || ($activeOnly && $service->domainstatus !== 'Active')) {
        return null;
    }
    forward_email_load_dns();
    $domain = whmcs_dns_registrable_domain((string) $service->domain);
    return $domain === null ? null : [
        'client_id' => (int) $service->userid,
        'service_id' => $serviceId,
        'domain' => $domain,
    ];
}

/** @return array{client_id: int, service_id: int, domain: string}|null */
function forward_email_row_context(object $row, bool $activeOnly = true): ?array
{
    $serviceId = (int) ($row->service_id ?? 0);
    if ($serviceId < 1) {
        return null;
    }
    $context = forward_email_service_context((int) $row->client_id, $serviceId, $activeOnly);
    return $context && $context['domain'] === (string) $row->domain_name ? $context : null;
}

/** @return object|null */
function forward_email_domain_row(int $clientId, string $domain): ?object
{
    $row = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('domain_name', $domain)->first();
    if ($row && (int) $row->client_id !== $clientId) {
        throw new RuntimeException('This forwarding domain belongs to another customer.');
    }
    return $row ?: null;
}

/** @param array<string, mixed> $values */
function forward_email_save_domain(int $clientId, string $domain, array $values): void
{
    $now = date('Y-m-d H:i:s');
    $row = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('domain_name', $domain)->first();
    if ($row && (int) $row->client_id !== $clientId) {
        throw new RuntimeException('This forwarding domain belongs to another customer.');
    }
    if ($row) {
        Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->update(
            $values + ['updated_at' => $now]
        );
        return;
    }
    if (empty($values['service_id'])) {
        throw new RuntimeException('A WHMCS service is required for this forwarding domain.');
    }
    Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->insert(
        $values + ['client_id' => $clientId, 'domain_name' => $domain, 'created_at' => $now, 'updated_at' => $now]
    );
}


/**
 * @param array<int, string> $allowedStatuses
 */
function forward_email_with_operation(
    int $clientId,
    string $domain,
    string $operation,
    array $allowedStatuses,
    callable $callback
): mixed {
    $token = bin2hex(random_bytes(16));
    $stale = date('Y-m-d H:i:s', time() - FORWARD_EMAIL_OPERATION_LEASE);
    $claimed = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)
        ->where('client_id', $clientId)->where('domain_name', $domain)->whereIn('status', $allowedStatuses)
        ->where(function ($query) use ($stale): void {
            $query->whereNull('operation_token')->orWhere('operation_started_at', '<=', $stale);
        })->update([
            'operation_token' => $token,
            'operation_type' => $operation,
            'operation_started_at' => date('Y-m-d H:i:s'),
    ]);
    if ($claimed !== 1) {
        throw new RuntimeException('This email forwarding operation is not allowed in the current state or another operation is in progress.');
    }
    try {
        return $callback();
    } finally {
        Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('client_id', $clientId)
            ->where('domain_name', $domain)->where('operation_token', $token)->update([
                'operation_token' => null,
                'operation_type' => null,
                'operation_started_at' => null,
            ]);
    }
}

function forward_email_verify_domain(int $clientId, string $domain, string $apiKey): bool
{
    $verified = forward_email_remote_verified($apiKey, $domain);
    forward_email_save_domain($clientId, $domain, [
        'status' => $verified ? 'active' : 'pending_verification',
        'next_retry_at' => $verified ? null : date('Y-m-d H:i:s', time() + 3600),
        'last_error' => null,
    ]);
    return $verified;
}

function forward_email_enable_domain(int $clientId, string $domain, string $apiKey): bool
{
    forward_email_load_dns();
    $row = forward_email_domain_row($clientId, $domain);
    if (!$row || forward_email_row_context($row) === null) {
        throw new RuntimeException('This forwarding domain is not connected to an active WHMCS service.');
    }
    $status = whmcs_dns_integration_status($clientId, $domain);
    if (($status['enabled'] ?? false) !== true) {
        throw new RuntimeException('DNS must be enabled for this domain first.');
    }

    forward_email_save_domain($clientId, $domain, [
        'status' => 'enabling',
        'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
        'last_error' => null,
    ]);
    $remote = forward_email_get_domain($apiKey, $domain);
    if ($remote !== null && (string) ($row->forward_email_id ?? '') !== (string) ($remote['id'] ?? '')) {
        throw new RuntimeException('This domain already exists in Forward Email and requires an administrator connection.');
    }
    if ($remote === null) {
        if (!empty($row->forward_email_id)) {
            throw new RuntimeException('The connected Forward Email domain no longer exists.');
        }
        $remote = forward_email_api_request($apiKey, 'POST', '/v1/domains', [
            'domain' => $domain,
            'catchall' => false,
        ]);
        if (!is_string($remote['id'] ?? null) || $remote['id'] === '') {
            throw new RuntimeException('Forward Email did not return a domain ID.');
        }
        forward_email_save_domain($clientId, $domain, ['forward_email_id' => $remote['id']]);
    }
    forward_email_remove_catchalls($apiKey, $domain);
    $remote = forward_email_get_domain($apiKey, $domain) ?? $remote;
    $desired = forward_email_forwarding_dns_records($remote);
    $current = whmcs_dns_integration_list_records($clientId, $domain);
    $delete = forward_email_dns_delete_plan($current, $desired);
    whmcs_dns_integration_apply_records($clientId, $domain, $delete, $desired, 'enable');

    forward_email_save_domain($clientId, $domain, [
        'forward_email_id' => (string) $remote['id'],
        'status' => 'pending_verification',
        'managed_dns_records' => json_encode($desired, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
        'last_error' => null,
    ]);
    return forward_email_verify_domain($clientId, $domain, $apiKey);
}

function forward_email_configure_sender_dns(int $clientId, string $domain, string $apiKey): void
{
    forward_email_load_dns();
    $row = forward_email_domain_row($clientId, $domain);
    if (!$row || forward_email_row_context($row) === null || $row->status !== 'active') {
        throw new RuntimeException('Active email forwarding is required before configuring sender verification.');
    }
    $remote = forward_email_get_domain($apiKey, $domain);
    if (!$remote || (string) ($remote['id'] ?? '') !== (string) $row->forward_email_id) {
        throw new RuntimeException('The connected Forward Email domain is unavailable.');
    }
    $desired = forward_email_sender_dns_records($remote);
    $current = whmcs_dns_integration_list_records($clientId, $domain);
    whmcs_dns_integration_apply_records(
        $clientId,
        $domain,
        forward_email_sender_dns_delete_plan($current, $desired),
        $desired,
        'sender_verification'
    );
    forward_email_save_domain($clientId, $domain, [
        'sender_dns_configured_at' => date('Y-m-d H:i:s'),
        'last_error' => null,
    ]);
}

function forward_email_disable_domain(int $clientId, string $domain, string $apiKey): void
{
    forward_email_load_dns();
    $row = forward_email_domain_row($clientId, $domain);
    if (!$row) {
        return;
    }
    forward_email_save_domain($clientId, $domain, [
        'status' => 'disabling',
        'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
    ]);

    $managed = json_decode((string) ($row->managed_dns_records ?? '[]'), true);
    if (!is_array($managed)) {
        throw new RuntimeException('Stored DNS state is invalid.');
    }
    $dnsStatus = whmcs_dns_integration_status($clientId, $domain);
    if (($dnsStatus['enabled'] ?? false) === true) {
        $current = whmcs_dns_integration_list_records($clientId, $domain);
        whmcs_dns_integration_apply_records(
            $clientId,
            $domain,
            forward_email_managed_records_present($current, $managed),
            [],
            'disable'
        );
    }

    try {
        forward_email_api_request($apiKey, 'DELETE', '/v1/domains/' . rawurlencode($domain));
    } catch (ForwardEmailApiException $e) {
        if ($e->httpStatus !== 404) {
            throw $e;
        }
    }
    Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->delete();
}

function forward_email_valid_local_part(string $value): bool
{
    return $value !== '*' && strlen($value) <= 64
        && preg_match('/^[a-z0-9](?:[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]{0,62}[a-z0-9])?$/iD', $value) === 1;
}

function forward_email_valid_destination(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false && strlen($value) <= 254;
}

/** @return array<int, object> */
function forward_email_admin_services(): array
{
    return Capsule::table('tblhosting')->select('id', 'userid', 'domain')
        ->where('domainstatus', 'Active')->where('domain', '<>', '')->orderBy('id')->get()->all();
}

/** @param array<int, object> $services */
function forward_email_admin_service_options(array $services, string $domain): string
{
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $options = '';
    foreach ($services as $service) {
        if (whmcs_dns_registrable_domain((string) $service->domain) !== $domain) {
            continue;
        }
        $label = '#' . (int) $service->id . ' / client #' . (int) $service->userid . ' — ' . (string) $service->domain;
        $options .= '<option value="' . (int) $service->id . '">' . $escape($label) . '</option>';
    }
    return $options;
}

function forward_email_admin_connect(string $domain, int $serviceId, string $apiKey): string
{
    $context = forward_email_service_context(0, $serviceId);
    if (!$context || $context['domain'] !== $domain || !whmcs_dns_can_manage_domains($context['client_id'])) {
        throw new InvalidArgumentException('Select an active DNS-enabled service for the same registrable domain.');
    }
    $dnsStatus = whmcs_dns_integration_status($context['client_id'], $domain);
    if (($dnsStatus['enabled'] ?? false) !== true) {
        throw new InvalidArgumentException('DNS must be enabled for the selected service domain.');
    }
    $remote = forward_email_get_domain($apiKey, $domain);
    if (!$remote || !is_string($remote['id'] ?? null) || $remote['id'] === '') {
        throw new InvalidArgumentException('The Forward Email domain does not exist.');
    }
    $status = forward_email_remote_verified($apiKey, $domain) ? 'active' : 'connected';
    $now = date('Y-m-d H:i:s');
    $row = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('domain_name', $domain)->first();
    $values = [
        'client_id' => $context['client_id'],
        'service_id' => $serviceId,
        'forward_email_id' => $remote['id'],
        'status' => $status,
        'next_retry_at' => null,
        'last_error' => null,
        'operation_token' => null,
        'operation_type' => null,
        'operation_started_at' => null,
        'updated_at' => $now,
    ];
    if ($row) {
        $started = strtotime((string) ($row->operation_started_at ?? '')) ?: 0;
        if (!empty($row->operation_token) && $started > time() - FORWARD_EMAIL_OPERATION_LEASE) {
            throw new RuntimeException('The domain has an operation in progress.');
        }
        Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->update($values);
    } else {
        Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->insert($values + [
            'domain_name' => $domain,
            'managed_dns_records' => '[]',
            'created_at' => $now,
        ]);
    }
    return $status;
}

/** @param array<string, mixed> $vars */
function forward_email_output(array $vars): void
{
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $link = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=forward_email');
    try {
        forward_email_load_dns();
        $apiKey = forward_email_api_key();
        $notice = null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_token('WHMCS.admin.default');
            $action = (string) ($_POST['admin_action'] ?? '');
            $domain = strtolower(trim((string) ($_POST['domain'] ?? '')));
            if ($action === 'connect') {
                $serviceId = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($domain === '' || $serviceId === false) {
                    throw new InvalidArgumentException('A domain and service are required.');
                }
                $status = forward_email_admin_connect($domain, (int) $serviceId, $apiKey);
                $notice = ['success', "{$domain} connected with status {$status}; DNS was not changed."];
            } elseif ($action === 'detach') {
                if ($domain === '' || (string) ($_POST['confirm_domain'] ?? '') !== $domain) {
                    throw new InvalidArgumentException('Enter the domain name to confirm detaching.');
                }
                $row = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('domain_name', $domain)->first();
                if (!$row) {
                    throw new InvalidArgumentException('The local connection does not exist.');
                }
                $started = strtotime((string) ($row->operation_started_at ?? '')) ?: 0;
                if (!empty($row->operation_token) && $started > time() - FORWARD_EMAIL_OPERATION_LEASE) {
                    throw new RuntimeException('The domain has an operation in progress.');
                }
                Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->delete();
                $notice = ['success', "{$domain} detached; provider and DNS state were not changed."];
            } else {
                throw new InvalidArgumentException('Invalid administrator action.');
            }
        }

        $providers = [];
        foreach (forward_email_list_domains($apiKey) as $domain) {
            $providers[strtolower((string) $domain['name'])] = $domain;
        }
        $locals = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->orderBy('domain_name')->get();
        $localNames = [];
        foreach ($locals as $row) {
            $localNames[(string) $row->domain_name] = true;
        }
        $services = forward_email_admin_services();
        $token = generate_token('plain');
        if ($notice) {
            echo '<div class="alert alert-' . $notice[0] . '">' . $escape($notice[1]) . '</div>';
        }
        echo '<h2>Forward Email Connections</h2><p>Connecting, repairing, and detaching only change the WHMCS mapping. They do not change DNS or Forward Email aliases.</p>';
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr><th>Domain</th><th>Provider</th><th>WHMCS service</th><th>State</th><th>Actions</th></tr></thead><tbody>';

        foreach ($locals as $row) {
            $domain = (string) $row->domain_name;
            $provider = $providers[$domain] ?? null;
            $context = forward_email_row_context($row);
            $remoteMatches = $provider && (string) $provider['id'] === (string) ($row->forward_email_id ?? '');
            $state = !$provider ? 'Missing in Forward Email'
                : (!$remoteMatches ? 'Remote ID mismatch'
                    : (!$context ? 'Missing or invalid service' : 'Connected (' . (string) $row->status . ')'));
            $rawService = Capsule::table('tblhosting')->where('id', (int) ($row->service_id ?? 0))->first();
            $serviceLabel = $rawService
                ? '#' . (int) $rawService->id . ' / client #' . (int) $rawService->userid . ' — ' . (string) $rawService->domain
                : 'None';
            echo '<tr><td>' . $escape($domain) . '</td><td>' . ($provider ? 'Present' : 'Missing') . '</td><td>'
                . $escape($serviceLabel) . '</td><td>' . $escape($state) . '</td><td>';
            if ($provider) {
                $options = forward_email_admin_service_options($services, $domain);
                if ($options !== '') {
                    echo '<form method="post" action="' . $escape($link) . '" class="form-inline" style="display:inline-block">'
                        . '<input type="hidden" name="token" value="' . $escape($token) . '"><input type="hidden" name="admin_action" value="connect">'
                        . '<input type="hidden" name="domain" value="' . $escape($domain) . '"><select name="service_id" class="form-control input-sm">'
                        . $options . '</select> <button class="btn btn-primary btn-sm" type="submit">Repair</button></form> ';
                }
            }
            echo '<form method="post" action="' . $escape($link) . '" class="form-inline" style="display:inline-block">'
                . '<input type="hidden" name="token" value="' . $escape($token) . '"><input type="hidden" name="admin_action" value="detach">'
                . '<input type="hidden" name="domain" value="' . $escape($domain) . '"><input class="form-control input-sm" name="confirm_domain" placeholder="Type domain" required> '
                . '<button class="btn btn-danger btn-sm" type="submit">Detach</button></form></td></tr>';
        }

        foreach ($providers as $domain => $provider) {
            if (isset($localNames[$domain])) {
                continue;
            }
            $options = forward_email_admin_service_options($services, $domain);
            echo '<tr><td>' . $escape($domain) . '</td><td>Present</td><td>None</td><td>Unconnected provider domain</td><td>';
            if ($options !== '') {
                echo '<form method="post" action="' . $escape($link) . '" class="form-inline">'
                    . '<input type="hidden" name="token" value="' . $escape($token) . '"><input type="hidden" name="admin_action" value="connect">'
                    . '<input type="hidden" name="domain" value="' . $escape($domain) . '"><select name="service_id" class="form-control input-sm">'
                    . $options . '</select> <button class="btn btn-primary btn-sm" type="submit">Connect</button></form>';
            } else {
                echo 'No matching active service';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    } catch (Throwable $e) {
        logModuleCall('forward_email', 'admin', [], null, $e->getMessage());
        echo '<div class="alert alert-danger">' . $escape($e instanceof InvalidArgumentException
            ? $e->getMessage() : 'Forward Email connections are temporarily unavailable.') . '</div>';
    }
}

/**
 * @param array<string, mixed> $vars
 * @return array<string, mixed>
 */
function forward_email_clientarea(array $vars): array
{
    $page = [
        'pagetitle' => 'Email Forwarding',
        'breadcrumb' => ['index.php?m=forward_email' => 'Email Forwarding'],
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => ['available' => false, 'message' => null, 'domain' => '', 'serviceId' => 0,
            'state' => null, 'aliases' => [], 'senderDnsRecords' => []],
    ];
    if (empty($_SESSION['uid'])) {
        return $page;
    }

    $clientId = (int) $_SESSION['uid'];
    $serviceId = filter_var($_GET['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        forward_email_load_dns();
        if (!whmcs_dns_can_manage_domains($clientId)) {
            throw new RuntimeException('You do not have permission to manage domains.');
        }
        $context = $serviceId === false ? null : forward_email_service_context($clientId, (int) $serviceId);
        if ($context === null) {
            throw new RuntimeException('An active hosting service with a valid domain is required.');
        }
        $domain = $context['domain'];
        $dnsStatus = whmcs_dns_integration_status($clientId, $domain);
        if (($dnsStatus['enabled'] ?? false) !== true) {
            throw new RuntimeException('DNS must be enabled for this domain first.');
        }
        $apiKey = forward_email_api_key();
        $existing = forward_email_domain_row($clientId, $domain);
        if ($existing && (int) ($existing->service_id ?? 0) !== (int) $serviceId) {
            throw new RuntimeException('This forwarding domain requires an administrator connection repair.');
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_token();
            forward_email_enforce_mutation_limit($clientId);
            $action = (string) ($_POST['action'] ?? '');
            if ($action !== 'enable' && $existing === null) {
                throw new InvalidArgumentException('Email forwarding is not enabled for this domain.');
            }
            if ($action === 'enable') {
                if (($_POST['confirm_mx_replacement'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('You must acknowledge the existing mail warning.');
                }
                if ($existing === null) {
                    if (forward_email_get_domain($apiKey, $domain) !== null) {
                        throw new InvalidArgumentException(
                            'This domain already exists in Forward Email. Ask an administrator to connect it.'
                        );
                    }
                    forward_email_save_domain($clientId, $domain, [
                        'service_id' => (int) $serviceId,
                        'status' => 'connected',
                        'managed_dns_records' => '[]',
                        'next_retry_at' => null,
                        'last_error' => null,
                    ]);
                }
                $verified = forward_email_with_operation(
                    $clientId,
                    $domain,
                    'enable',
                    ['connected', 'enabling'],
                    static fn (): bool => forward_email_enable_domain($clientId, $domain, $apiKey)
                );
                $message = ['type' => 'success', 'text' => $verified
                    ? 'Email forwarding enabled and verified.'
                    : 'Email forwarding enabled; DNS verification is pending.'];
            } elseif ($action === 'verify') {
                $verified = forward_email_with_operation(
                    $clientId,
                    $domain,
                    'verify',
                    ['pending_verification', 'active'],
                    static fn (): bool => forward_email_verify_domain($clientId, $domain, $apiKey)
                );
                $message = ['type' => 'success', 'text' => $verified
                    ? 'Domain verified.' : 'DNS verification is still pending.'];
            } elseif ($action === 'add_alias') {
                $name = strtolower(trim((string) ($_POST['name'] ?? '')));
                $destination = trim((string) ($_POST['destination'] ?? ''));
                if (!forward_email_valid_local_part($name) || !forward_email_valid_destination($destination)) {
                    throw new InvalidArgumentException('A valid non-catch-all address and destination email are required.');
                }
                forward_email_with_operation($clientId, $domain, 'alias', ['pending_verification', 'active'],
                    static fn (): array => forward_email_api_request(
                        $apiKey,
                        'POST',
                        '/v1/domains/' . rawurlencode($domain) . '/aliases',
                        ['name' => $name, 'recipients' => $destination, 'has_imap' => false]
                    ));
                $message = ['type' => 'success', 'text' => 'Forwarder added.'];
            } elseif ($action === 'update_alias') {
                $aliasId = trim((string) ($_POST['alias_id'] ?? ''));
                $destination = trim((string) ($_POST['destination'] ?? ''));
                if ($aliasId === '' || !forward_email_valid_destination($destination)) {
                    throw new InvalidArgumentException('A valid forwarder and destination email are required.');
                }
                forward_email_with_operation($clientId, $domain, 'alias', ['pending_verification', 'active'],
                    static fn (): array => forward_email_api_request(
                        $apiKey,
                        'PUT',
                        '/v1/domains/' . rawurlencode($domain) . '/aliases/' . rawurlencode($aliasId),
                        ['recipients' => $destination, 'has_imap' => false]
                    ));
                $message = ['type' => 'success', 'text' => 'Forwarder updated.'];
            } elseif ($action === 'delete_alias') {
                $aliasId = trim((string) ($_POST['alias_id'] ?? ''));
                if ($aliasId === '') {
                    throw new InvalidArgumentException('A valid forwarder is required.');
                }
                forward_email_with_operation($clientId, $domain, 'alias', ['pending_verification', 'active'],
                    static fn (): array => forward_email_api_request(
                        $apiKey,
                        'DELETE',
                        '/v1/domains/' . rawurlencode($domain) . '/aliases/' . rawurlencode($aliasId)
                    ));
                $message = ['type' => 'success', 'text' => 'Forwarder deleted.'];
            } elseif ($action === 'configure_sender_dns') {
                if (($_POST['confirm_sender_dns'] ?? '') !== 'yes') {
                    throw new InvalidArgumentException('You must acknowledge the sender DNS replacement warning.');
                }
                forward_email_with_operation($clientId, $domain, 'sender_dns', ['active'],
                    static function () use ($clientId, $domain, $apiKey): void {
                        forward_email_configure_sender_dns($clientId, $domain, $apiKey);
                    });
                $message = ['type' => 'success', 'text' => 'Sender verification DNS configured. Future removal is manual.'];
            } elseif ($action === 'disable') {
                if (($_POST['confirm_disable'] ?? '') !== $domain) {
                    throw new InvalidArgumentException('Enter the domain name to confirm disabling.');
                }
                forward_email_with_operation($clientId, $domain, 'disable',
                    ['connected', 'enabling', 'pending_verification', 'active', 'disabling'],
                    static function () use ($clientId, $domain, $apiKey): void {
                        forward_email_disable_domain($clientId, $domain, $apiKey);
                    });
                $message = ['type' => 'success', 'text' => 'Email forwarding disabled.'];
            } else {
                throw new InvalidArgumentException('Invalid action.');
            }
        }

        $row = forward_email_domain_row($clientId, $domain);
        $aliases = $row && in_array($row->status, ['pending_verification', 'active'], true)
            ? array_map(static function (array $alias): array {
            $alias['recipients_display'] = forward_email_recipients_display($alias['recipients'] ?? null);
            return $alias;
        }, forward_email_list_aliases($apiKey, $domain)) : [];
        $senderDnsRecords = [];
        if ($row && $row->status === 'active') {
            $remote = forward_email_get_domain($apiKey, $domain);
            if ($remote && (string) ($remote['id'] ?? '') === (string) $row->forward_email_id) {
                $senderDnsRecords = forward_email_sender_dns_records($remote);
            }
        }
        $page['vars'] = [
            'available' => true,
            'message' => $message ?? null,
            'domain' => $domain,
            'serviceId' => (int) $serviceId,
            'state' => $row ? (array) $row : null,
            'aliases' => $aliases,
            'senderDnsRecords' => $senderDnsRecords,
            'token' => generate_token('plain'),
        ];
    } catch (Throwable $e) {
        if (isset($domain) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && in_array((string) ($_POST['action'] ?? ''), ['enable', 'verify', 'configure_sender_dns', 'disable'], true)) {
            $failedRow = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('domain_name', $domain)->first();
            if ($failedRow && (int) $failedRow->client_id === $clientId) {
                Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $failedRow->id)->update([
                    'last_error' => substr($e->getMessage(), 0, 65535),
                    'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
                ]);
            }
        }
        logModuleCall('forward_email', 'clientarea', ['service_id' => (int) ($serviceId ?: 0)], null, $e->getMessage());
        $page['vars']['message'] = ['type' => 'error', 'text' => $e instanceof InvalidArgumentException
            ? $e->getMessage() : 'Email forwarding is temporarily unavailable.'];
    }
    return $page;
}
}
