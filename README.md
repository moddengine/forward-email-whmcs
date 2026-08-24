# Forward Email for WHMCS

WHMCS addon for managing Forward Email domains and forwarding aliases for hosting services.

## Requirements

- PHP 8.3 and WHMCS 8.13 or newer.
- The sibling `whmcs_dns` addon with integration API version 1, activated and configured.
- A Forward Email API key.

## Install

Copy `forward_email/` to `modules/addons/forward_email/`, activate **Forward Email** in WHMCS, and configure its API key. Email forwarding appears on active hosting-service pages only when WHMCS-DNS already manages the registrable domain.

Enabling forwarding removes Forward Email's automatic catch-all alias, replaces MX records, and adds the exact Forward Email verification record. Displaced MX records are saved to the customer's WHMCS notes and are not restored automatically.

Existing Forward Email domains must first be connected to one matching active WHMCS service from the addon administration page. The same page reports missing provider domains and missing, inactive, or mismatched WHMCS services; connect, repair, and detach actions never change provider or DNS state.

Clients can separately configure Forward Email's SPF, DKIM, Return-Path, and DMARC sender-verification records after forwarding is active. This explicitly confirmed action replaces conflicting sender DNS. These records are not removed when forwarding is disabled and must be maintained or removed manually.

## Development

```sh
composer test --working-dir=forward_email
composer analyse --working-dir=forward_email
```
