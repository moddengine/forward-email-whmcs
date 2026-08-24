<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/forward_email.php';

add_hook('ClientAreaProductDetailsOutput', 1, function ($vars) {
    $service = $vars['service'] ?? null;
    if (empty($_SESSION['uid']) || !is_object($service)) {
        return '';
    }
    try {
        $clientId = (int) $_SESSION['uid'];
        forward_email_load_dns();
        if (!whmcs_dns_can_manage_domains($clientId)) {
            return '';
        }
        $context = forward_email_service_context($clientId, (int) ($service->id ?? 0));
        if ($context === null) {
            return '';
        }
        $row = forward_email_domain_row($clientId, $context['domain']);
        if ($row && (int) ($row->service_id ?? 0) !== (int) $service->id) {
            return '';
        }
        $status = whmcs_dns_integration_status($clientId, $context['domain']);
        if (($status['enabled'] ?? false) !== true) {
            return '';
        }
        forward_email_api_key();
        $url = 'index.php?' . http_build_query(['m' => 'forward_email', 'service_id' => (int) $service->id]);
        return '<a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '"><i class="fas fa-envelope" aria-hidden="true"></i> Email Forwarding</a>';
    } catch (Throwable $e) {
        logModuleCall('forward_email', 'product_link', [], null, $e->getMessage());
        return '';
    }
});

add_hook('AfterCronJob', 1, function (): void {
    try {
        forward_email_load_dns();
        $apiKey = forward_email_api_key();
        $rows = Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)
            ->whereIn('status', ['enabling', 'pending_verification', 'disabling'])
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', date('Y-m-d H:i:s'));
            })
            ->orderBy('id')
            ->limit(25)
            ->get();
        foreach ($rows as $row) {
            try {
                if (forward_email_row_context($row, $row->status !== 'disabling') === null) {
                    Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->update([
                        'last_error' => 'The connected WHMCS service is missing, inactive, or has a different domain.',
                        'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
                    ]);
                    continue;
                }
                if ($row->status === 'enabling') {
                    forward_email_with_operation((int) $row->client_id, (string) $row->domain_name, 'enable',
                        ['enabling'], static fn (): bool => forward_email_enable_domain(
                            (int) $row->client_id, (string) $row->domain_name, $apiKey
                        ));
                } elseif ($row->status === 'pending_verification') {
                    forward_email_with_operation((int) $row->client_id, (string) $row->domain_name, 'verify',
                        ['pending_verification'], static fn (): bool => forward_email_verify_domain(
                            (int) $row->client_id, (string) $row->domain_name, $apiKey
                        ));
                } else {
                    forward_email_with_operation((int) $row->client_id, (string) $row->domain_name, 'disable',
                        ['disabling'], static function () use ($row, $apiKey): void {
                            forward_email_disable_domain((int) $row->client_id, (string) $row->domain_name, $apiKey);
                        });
                }
            } catch (Throwable $e) {
                Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $row->id)->update([
                    'last_error' => substr($e->getMessage(), 0, 65535),
                    'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
                ]);
                logModuleCall('forward_email', 'cron_retry', ['domain' => (string) $row->domain_name], null, $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        logModuleCall('forward_email', 'cron', [], null, $e->getMessage());
    }
});

add_hook('AfterModuleTerminate', 1, function ($vars): void {
    $serviceId = (int) ($vars['params']['serviceid'] ?? $vars['params']['accountid'] ?? 0);
    $cleanupRow = null;
    try {
        $context = forward_email_service_context(0, $serviceId, false);
        if ($context === null) {
            return;
        }
        $cleanupRow = forward_email_domain_row($context['client_id'], $context['domain']);
        if (!$cleanupRow || (int) ($cleanupRow->service_id ?? 0) !== $serviceId) {
            return;
        }
        $apiKey = forward_email_api_key();
        forward_email_with_operation($context['client_id'], $context['domain'], 'disable',
            ['connected', 'enabling', 'pending_verification', 'active', 'disabling'],
            static function () use ($context, $apiKey): void {
                forward_email_disable_domain($context['client_id'], $context['domain'], $apiKey);
            });
    } catch (Throwable $e) {
        if (isset($context) && $cleanupRow) {
            Capsule::table(FORWARD_EMAIL_TABLE_DOMAINS)->where('id', $cleanupRow->id)->update([
                'last_error' => substr($e->getMessage(), 0, 65535),
                'next_retry_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
        }
        logModuleCall('forward_email', 'terminate_cleanup', ['service_id' => $serviceId], null, $e->getMessage());
    }
});
