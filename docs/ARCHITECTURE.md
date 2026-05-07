# Architecture

Cloudflare Pro follows the standard Plesk extension layout.

## Extension Layout

- `meta.xml`: Plesk extension metadata.
- `htdocs/index.php`: Plesk entrypoint that boots `pm_Application`.
- `htdocs/public/assets`: compiled React and Plesk UI Library bundle.
- `htdocs/css`: small extension-specific CSS.
- `plib/controllers/IndexController.php`: Zend controller actions and JSON endpoints.
- `plib/views/scripts/index/index.phtml`: host page that passes server data into the frontend.
- `plib/hooks`: Plesk navigation, custom button, and permission hooks.
- `plib/library`: repositories and Cloudflare/Plesk DNS services.
- `plib/scripts`: install and uninstall lifecycle scripts.
- `src/tokens.jsx`: React UI source.
- `packaging/build.sh`: repeatable package builder for local use and GitHub runners.

## Data Storage

The extension stores data in SQLite at the Plesk module `var` directory:

```text
cloudflare-pro.sqlite
```

Tables are initialized by repository classes:

- `tokens`: per-user Cloudflare token metadata and encrypted token values.
- `domain_links`: matched Plesk domain and Cloudflare zone rows.
- `settings`: one JSON payload row per user.
- `api_logs`: Cloudflare API request/response audit logs.
- `sync_jobs`: persisted background sync job progress.

The database is removed by `plib/scripts/pre-uninstall.php`.

## Request Flow

1. Plesk loads `htdocs/index.php`.
2. `pm_Application` routes into `IndexController`.
3. Controller actions collect Plesk data, Cloudflare data, and JSON endpoint URLs.
4. `index.phtml` injects bootstrapped data and loads `tokens.js`.
5. React renders the Plesk UI Library interface.
6. Mutations use controller JSON actions and Plesk forgery-protected POST requests.

## Cloudflare Sync Flow

- Tokens are validated with `/user/tokens/verify`.
- Zones are discovered with `/zones`.
- DNS records are read from Plesk through `pm_Domain` and `pm_Dns_Zone`.
- Cloudflare DNS records are read from `/zones/{zone_id}/dns_records`.
- Export and sync create or patch matching Cloudflare records.
- Import writes Cloudflare records into Plesk DNS.
- Background jobs process records in small batches and store progress in SQLite.

## Autosync Flow

`Modules_CloudflarePro_EventListener` receives Plesk events, filters DNS/domain/subdomain events, extracts the host name, finds linked Cloudflare zones, and calls `CloudflarePro_DomainSyncService::autoSyncHost()`.

Autosync only runs when:

- the user setting `enable_autosync` is enabled
- the linked domain row has `auto_sync` enabled
- the event host belongs to a linked Cloudflare zone

## Permissions

`plib/hooks/Permissions.php` registers **Cloudflare Pro access**. Navigation and custom buttons are shown only when `CloudflarePro_Permissions::hasAccess()` passes for the current user context.
