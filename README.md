# Forward Email for WHMCS

WHMCS addon for managing Forward Email domains and forwarding aliases from active hosting services.

> **Forwarding only:** this addon does not provide or manage Forward Email mailboxes, IMAP, POP3, webmail, or mailbox storage. Alias changes made through WHMCS explicitly use forwarding-only aliases (`has_imap=false`). Manage mailbox-enabled aliases directly in Forward Email.

## Features

- Adds an **Email Forwarding** link to eligible WHMCS hosting-service pages.
- Creates Forward Email domains and connects them to one active WHMCS service and customer.
- Creates, edits, and deletes forwarding aliases with one or more destination email addresses.
- Replaces forwarding MX records and manages the exact Forward Email site-verification TXT record through WHMCS-DNS.
- Removes Forward Email's automatic catch-all alias during setup; customers cannot create catch-all aliases through this addon.
- Checks forwarding DNS verification immediately and provides manual verification retries while background cron retries recover pending setup and removal operations.
- Optionally configures SPF, DKIM, Return-Path, and DMARC sender-verification DNS after forwarding is active.
- Checks sender verification immediately, shows required or pending status, and provides manual verification retries until Forward Email confirms it.
- Lets administrators connect, repair, or detach domains that already exist in Forward Email without changing their DNS or provider state.
- Cleans up the Forward Email domain and managed forwarding DNS when forwarding is disabled or the connected WHMCS service is terminated.
- Uses CSRF protection, HTTPS-only client pages, per-client mutation limits, and operation leases for provider and DNS changes.

## Requirements

- PHP 8.3 and WHMCS 8.13 or newer.
- The `whmcs_dns` addon REST API, with an automation key granting DNS read and write access to eligible domains.
- A Forward Email API key.

## Install

Copy `forward_email/` to `modules/addons/forward_email/`, activate **Forward Email** in WHMCS, and configure the Forward Email and WHMCS DNS API keys. Email forwarding appears on active hosting-service pages only when the API key can manage the service domain.

## Behavior and limitations

Enabling forwarding removes Forward Email's automatic catch-all alias, replaces MX records, and adds the exact Forward Email verification record. Displaced MX records are saved to the customer's WHMCS notes and are not restored automatically.

Existing Forward Email domains must first be connected to one matching active WHMCS service from the addon administration page. The same page reports missing provider domains and missing, inactive, or mismatched WHMCS services; connect, repair, and detach actions never change provider or DNS state.

Clients can separately configure Forward Email's SPF, DKIM, Return-Path, and DMARC sender-verification records after forwarding is active. This explicitly confirmed action replaces conflicting sender DNS. These records are not removed when forwarding is disabled and must be maintained or removed manually.

Disabling forwarding permanently deletes the Forward Email domain and its aliases, and removes only the forwarding DNS records recorded as managed by this addon. Sender-verification DNS remains in place. Detaching from the administration page removes only the local WHMCS connection.

## Development

```sh
composer test --working-dir=forward_email
composer analyse --working-dir=forward_email
```
