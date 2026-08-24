<?php

declare(strict_types=1);

define('WHMCS', true);
require dirname(__DIR__) . '/forward_email.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

check(forward_email_supported_dns_version(1) && !forward_email_supported_dns_version(2),
    'WHMCS-DNS integration version validation failed.');

$domain = [
    'id' => 'abc123',
    'verification_record' => 'verify-me',
    'smtp_dns_records' => [
        'dkim' => ['name' => 'fe-key._domainkey', 'value' => 'v=DKIM1; p=abc'],
        'return_path' => ['name' => 'fe-bounces', 'value' => 'forwardemail.net'],
        'dmarc' => ['name' => '_dmarc', 'value' => 'v=DMARC1; p=reject; rua=mailto:dmarc-abc123@forwardemail.net;'],
    ],
];
$desired = forward_email_forwarding_dns_records($domain);
$sender = forward_email_sender_dns_records($domain);
check(count($desired) === 3, 'Forwarding must only manage MX and site verification.');
check($desired[0]['type'] === 'MX' && $desired[0]['priority'] === 0, 'MX record is invalid.');
check(count($sender) === 4 && $sender[0]['value'] === 'v=spf1 include:spf.forwardemail.net ~all',
    'Sender verification records are invalid.');
check($sender[1]['name'] === 'fe-key._domainkey', 'DKIM record is invalid.');

$current = [
    forward_email_record('@', 'MX', 'mail.example.com', 10),
    forward_email_record('@', 'TXT', 'v=spf1 include:old.example -all'),
    forward_email_record('@', 'TXT', 'unrelated=value'),
    forward_email_record('@', 'TXT', 'forward-email-site-verification=keep-me'),
    forward_email_record('_dmarc', 'TXT', 'v=DMARC1; p=none'),
    forward_email_record('fe-bounces', 'A', '192.0.2.1'),
    $desired[0],
    $desired[0],
];
$delete = forward_email_dns_delete_plan($current, $desired);
check(count($delete) === 2, 'Forwarding DNS replacement selected records outside MX and exact verification.');
check(!in_array('unrelated=value', array_column($delete, 'value'), true), 'Unrelated TXT record was selected.');
check(!in_array('forward-email-site-verification=keep-me', array_column($delete, 'value'), true),
    'A different site verification record was selected.');
check(count(array_filter($delete, static fn (array $record): bool => $record['value'] === 'mx1.forwardemail.net')) === 1,
    'Desired record duplicates were not reduced to one.');
$changedVerification = $desired[2];
$changedVerification['ttl'] = 300;
check(forward_email_dns_delete_plan([$changedVerification], $desired) === [$changedVerification],
    'The exact verification value must be replaced when its stored shape differs.');

$senderCurrent = [
    forward_email_record('@', 'TXT', 'v=spf1 include:old.example -all'),
    forward_email_record('fe-key._domainkey', 'TXT', 'unrelated=keep-me'),
    forward_email_record('fe-key._domainkey', 'TXT', 'v=DKIM1; p=old'),
    forward_email_record('fe-bounces', 'A', '192.0.2.1'),
    forward_email_record('_dmarc', 'TXT', 'v=DMARC1; p=none'),
];
$senderDelete = forward_email_sender_dns_delete_plan($senderCurrent, $sender);
check(count($senderDelete) === 4, 'Sender DNS replacement did not select the four conflicting records.');
check(!in_array('unrelated=keep-me', array_column($senderDelete, 'value'), true),
    'Unrelated TXT at the DKIM selector was selected.');

$changedTtl = $desired[0];
$changedTtl['ttl'] = 300;
$changedTtl['priority'] = 10;
$present = forward_email_managed_records_present([$changedTtl, $current[2]], [$desired[0]]);
check(count($present) === 1 && $present[0]['ttl'] === 300, 'Managed cleanup should tolerate TTL and priority changes.');

$aliases = forward_email_alias_rows([
    ['id' => '1', 'name' => '*', 'recipients' => ['all@example.net']],
    ['id' => '2', 'name' => 'sales', 'recipients' => ['one@example.net', 'two@example.net']],
]);
check(forward_email_catchall_ids($aliases) === ['1'], 'Catch-all alias was not selected.');
check(forward_email_recipients_display($aliases[1]['recipients']) === 'one@example.net, two@example.net', 'Recipients were not displayed safely.');
check(!forward_email_valid_local_part('*') && forward_email_valid_local_part('sales'), 'Catch-all local part validation failed.');
check(forward_email_valid_destination('user@example.net') && !forward_email_valid_destination('invalid'), 'Destination validation failed.');

foreach ([[], ['verification_record' => '']] as $invalid) {
    try {
        forward_email_forwarding_dns_records($invalid);
        throw new RuntimeException('Incomplete Forward Email DNS response was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Incomplete Forward Email DNS response was accepted.') {
            throw $e;
        }
    }
}
try {
    forward_email_sender_dns_records(['smtp_dns_records' => []]);
    throw new RuntimeException('Incomplete sender DNS response was accepted.');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Incomplete sender DNS response was accepted.') {
        throw $e;
    }
}

$source = file_get_contents(dirname(__DIR__) . '/forward_email.php');
$template = file_get_contents(dirname(__DIR__) . '/templates/clientarea.tpl');
check($source !== false && $template !== false, 'Addon source files could not be read.');
check(str_contains($source, "'catchall' => false"), 'Domain creation must disable the automatic catch-all.');
check(strpos($source, 'forward_email_remove_catchalls($apiKey, $domain);')
    < strpos($source, 'whmcs_dns_integration_apply_records($clientId, $domain, $delete'),
    'Catch-all removal must happen before DNS mutation.');
check(substr_count($template, '<form') === substr_count($template, 'name="token"'),
    'Every client POST form must include a CSRF token.');
check(str_contains($source, "'forcessl' => true"), 'The client page must require HTTPS.');
check(!str_contains($source, "\$_REQUEST['service_id']"), 'Service selection must not use $_REQUEST.');

echo "Forward Email unit checks passed.\n";
