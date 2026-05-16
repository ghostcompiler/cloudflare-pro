# Cloudflare Pro for Plesk

<p align="center">
  <img src="htdocs/images/cloudflare-logo.svg" alt="Cloudflare Pro" width="180">
</p>

<h1 align="center">Cloudflare Pro for Plesk</h1>

<p align="center">
  Sync Plesk DNS records with Cloudflare using token-scoped access, per-user settings, API logs, and background sync jobs.
</p>

---

## Overview

Cloudflare Pro is a Plesk extension for connecting Plesk DNS zones to Cloudflare zones and keeping records aligned. It stores Cloudflare API tokens per logged-in Plesk user, discovers matching Cloudflare zones, compares local Plesk records with Cloudflare records, and provides import, export, sync, delete, proxy, and API log tools from the Plesk UI.

The extension is built for the Plesk extension pattern: PHP/Zend controllers in `plib`, static assets in `htdocs`, extension metadata in `meta.xml`, and React UI compiled into `htdocs/public/assets`.

## Creator

- **Name:** Ghost Compiler
- **GitHub:** [ghostcompiler/cloudflare-pro](https://github.com/ghostcompiler/cloudflare-pro)
- **Profile:** [github.com/ghostcompiler](https://github.com/ghostcompiler)

## Documentation

Developer and operator documentation lives in [`docs`](docs):

- [Installation](docs/INSTALL.md)
- [Architecture](docs/ARCHITECTURE.md)
- [API and controller actions](docs/API.md)
- [Security](docs/SECURITY.md)
- [GitHub Pages index](docs/index.html)

## Features

- Plesk UI Library based interface with Domains, Tokens, API Logs, Settings, and About tabs.
- Per-user Cloudflare API token storage with token name, status, validation, update, and delete actions.
- Cloudflare zone discovery that only shows zones matching accessible Plesk domains.
- Domain record viewer with search, pagination, type sorting, proxy toggles, and record status.
- Import all records from Cloudflare to Plesk.
- Export all local Plesk records to Cloudflare.
- Sync all records through persisted background jobs with Plesk progress toasts.
- Automatic DNS push on Plesk domain, DNS, record, site, and subdomain events when Auto Sync is enabled.
- Per-user settings saved as one JSON payload row.
- API Logs table with search, pagination, detail drawer, request/response copy actions, and remove logs action.
- Plesk service-plan permission hook for controlling extension visibility.
- SQLite storage removed during extension uninstall.

## Complete Option Reference

### Domains

- **View:** Opens the domain record page for the linked Cloudflare zone.
- **Export:** Pushes all local Plesk DNS records to Cloudflare.
- **Import:** Pulls all Cloudflare DNS records into Plesk.
- **Sync:** Runs a Cloudflare sync job using the current local Plesk DNS source.
- **Auto Sync:** Enables automatic event-based pushing for that linked domain.

### Domain Record Page

- **Back:** Returns to the Domains tab.
- **Import:** Imports all Cloudflare records for the zone into Plesk.
- **Export:** Exports all local Plesk records for the domain to Cloudflare.
- **Sync All:** Starts a persisted sync job and shows progress in a Plesk toaster.
- **Search records:** Searches all displayed records, not only the current page.
- **Type sorting:** Sorts records by DNS type and then name.
- **Proxy:** Toggles Cloudflare proxy status for A, AAAA, and CNAME records.
- **Push:** Appears for local-only or mismatched records and pushes the local record to Cloudflare.
- **Pull:** Appears for Cloudflare-only or mismatched records and pulls the Cloudflare record into Plesk.
- **Delete:** Deletes the record from both Plesk and Cloudflare where it exists.

### Tokens

- **Add Token:** Opens a Plesk Drawer for credentials.
- **Token name:** Human label for the stored token.
- **API token:** Password field. Paste only the token value, not `Bearer ...`.
- **Validate:** Calls Cloudflare `/user/tokens/verify`, stores status, and logs the API request.
- **Edit:** Updates token name or replaces the token value.
- **Delete:** Removes the token and linked domain rows for that token.

### API Logs

- **Search logs:** Searches all API log rows server-side.
- **View:** Opens a drawer with request, response, route, HTTP status, duration, and error message.
- **Copy:** Copies request and response data.
- **Remove Logs:** Clears stored API call logs for the current user.

### Settings

Settings are per user and stored in one JSON row.

- **Enable Autosync:** Allows automatic DNS pushes from Plesk events.
- **Remove records automatically on domain delete:** Enables Cloudflare cleanup for concrete deleted child hostnames. Ambiguous apex events are skipped so a subdomain delete cannot remove every zone record.
- **Validate token before sync:** Verifies token status before sync jobs.
- **Log Cloudflare API calls:** Stores request/response metadata in API Logs.
- **Create www record for subdomains:** When autosync handles a subdomain, also creates matching `www.<subdomain>` records in Cloudflare without requiring that hostname to exist in Plesk. When that subdomain is deleted, the companion `www.<subdomain>` record is removed only if this toggle is enabled.
- **Enable proxy for A records:** Default Cloudflare proxy state for A records.
- **Enable proxy for AAAA records:** Default Cloudflare proxy state for AAAA records.
- **Enable proxy for CNAME records:** Default Cloudflare proxy state for CNAME records.

## Requirements

- Plesk Obsidian 18.0.0 or newer.
- PHP with PDO SQLite and cURL available to the Plesk PHP runtime.
- Node.js 20 or newer for local frontend builds and GitHub Actions runners.
- A Cloudflare API token with zone access, including DNS edit permissions for zones that should be synced.

Recommended Cloudflare token permissions:

- `Zone:Zone:Read`
- `Zone:DNS:Read`
- `Zone:DNS:Edit`

## Installation

Install the latest runner-built package directly from GitHub:

```sh
plesk bin extension --install-url https://github.com/ghostcompiler/cloudflare-pro/releases/download/latest/cloudflare-pro.zip
```

This URL points to the rolling `latest` pre-release asset. The **Package Latest** workflow rebuilds `cloudflare-pro.zip` from the current `main` branch on every push and whenever it is started manually.

Pinned version installs are available after publishing a versioned release:

```sh
plesk bin extension --install-url https://github.com/ghostcompiler/cloudflare-pro/releases/download/v1.0.5/cloudflare-pro-1.0.5.zip
```

Build the extension ZIP locally:

```sh
sh packaging/build.sh
```

Install the local archive through Plesk CLI:

```sh
plesk bin extension --install cloudflare-pro-1.0.5.zip
```

Or install through Plesk UI:

1. Open **Plesk Admin**.
2. Go to **Extensions**.
3. Click **Upload Extension**.
4. Upload `cloudflare-pro-1.0.5.zip`.
5. Open **Cloudflare Pro** from the Plesk sidebar.

## Testing

Run the same local checks used by the CI runner:

```sh
npm install --ignore-scripts --legacy-peer-deps
npm test
find plib htdocs \( -name '*.php' -o -name '*.phtml' \) -print0 | sort -z | xargs -0 -n1 php -l
xmllint --noout meta.xml
node -e "JSON.parse(require('fs').readFileSync('packaging/manifest.json', 'utf8'))"
sh -n packaging/build.sh
sh packaging/build.sh
zip -T cloudflare-pro-1.0.5.zip
```

GitHub Actions runners are included:

- **CI** runs on every branch push, pull request, and manual dispatch. It validates PHP, frontend build, docs, metadata, packaging script, ZIP build, and uploads package artifacts.
- **Package Latest** runs on `main` and manual dispatch. It builds and publishes `cloudflare-pro.zip` to the rolling `latest` GitHub pre-release.
- **Release** runs on `v<version>` tags and manual dispatch. It verifies the tag matches `meta.xml`, builds `cloudflare-pro-<version>.zip`, and publishes the versioned release.
- **Pages** runs when docs change on `main` and manual dispatch. It validates the `docs/` folder and deploys it to GitHub Pages.

## First Run

1. Open a service plan or subscription.
2. Enable **Cloudflare Pro access**.
3. Add a Cloudflare API token in the Tokens tab.
4. Validate the token.
5. Open the Domains tab and confirm matching Cloudflare zones are linked.
6. Enable Auto Sync for domains that should push Plesk DNS changes automatically.
7. Use View to inspect record status and run Import, Export, or Sync All as needed.

## Troubleshooting Logs

When the UI reports a 500 error or autosync does not trigger, check:

```sh
tail -n 200 /var/log/plesk/panel.log
tail -n 200 /var/log/sw-cp-server/error_log
```

On some Plesk builds, the panel log is stored at:

```sh
tail -n 200 /usr/local/psa/admin/logs/panel.log
```

Autosync writes messages with the `Cloudflare Pro autosync` prefix.
