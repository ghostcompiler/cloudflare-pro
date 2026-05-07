# Security

## Token Storage

Cloudflare API tokens are stored per Plesk user. The UI treats token values as secrets:

- token input uses a password field
- list rows do not show token values
- API logs redact authorization headers
- token validation stores only status metadata

## Token Permissions

Use the narrowest Cloudflare API token possible. Recommended permissions:

- `Zone:Zone:Read`
- `Zone:DNS:Read`
- `Zone:DNS:Edit`

Scope the token to the exact Cloudflare zones managed by the Plesk user.

## Plesk Access Control

The extension registers a service-plan permission named **Cloudflare Pro access**. Users without effective permission should not see or access the extension UI.

## Request Protection

Mutating controller actions require POST requests and run inside Plesk's normal forgery-protection flow.

## Logging

API logs are useful for debugging but can include DNS record names and values. Disable `Log Cloudflare API calls` if a hosting policy requires minimal operational logging.

## Uninstall Behavior

The extension removes its SQLite database during uninstall through `plib/scripts/pre-uninstall.php`. Plesk-managed DNS records and Cloudflare records are not removed by uninstall.
