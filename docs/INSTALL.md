# Installation

## Requirements

- Plesk Obsidian 18.0.0 or newer.
- PHP with PDO SQLite and cURL available to the Plesk PHP runtime.
- Cloudflare API token with zone read and DNS edit permissions.
- Node.js 20 or newer for local builds and GitHub Actions.

## Install Latest

Install the latest runner-built package directly from GitHub:

```sh
plesk bin extension --install-url https://github.com/ghostcompiler/cloudflare-pro/releases/download/latest/cloudflare-pro.zip
```

The `latest` release is maintained by `.github/workflows/package-latest.yml`.
It rebuilds `cloudflare-pro.zip` from `main` on every push and when the workflow is started manually.

## Install Pinned Version

After publishing a versioned release:

```sh
plesk bin extension --install-url https://github.com/ghostcompiler/cloudflare-pro/releases/download/v1.0.5/cloudflare-pro-1.0.5.zip
```

## Build Package Locally

Run from the extension root:

```sh
sh packaging/build.sh
```

The script creates:

```text
cloudflare-pro-1.0.5.zip
```

## Install Local Package

Install through Plesk CLI:

```sh
plesk bin extension --install cloudflare-pro-1.0.5.zip
```

Or install through Plesk UI:

1. Open **Plesk Admin**.
2. Go to **Extensions**.
3. Click **Upload Extension**.
4. Upload `cloudflare-pro-1.0.5.zip`.
5. Open **Cloudflare Pro** from the Plesk sidebar.

## Test Before Packaging

Run the same checks used by the GitHub Actions runners:

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

## GitHub Runners

- **CI** validates PHP, JavaScript, docs, metadata, packaging, and ZIP integrity.
- **Package Latest** publishes `cloudflare-pro.zip` to the rolling `latest` pre-release.
- **Release** publishes `cloudflare-pro-<version>.zip` for `v<version>` tags.
- **Pages** deploys the `docs/` folder to GitHub Pages.

## First Run

1. Enable **Cloudflare Pro access** on the service plan or subscription.
2. Add a Cloudflare token from the Tokens tab.
3. Validate the token.
4. Open Domains and confirm matching Plesk domains are linked to Cloudflare zones.
5. Use Import, Export, Sync, or Auto Sync as needed.

## Troubleshooting Logs

```sh
tail -n 200 /var/log/plesk/panel.log
tail -n 200 /var/log/sw-cp-server/error_log
tail -n 200 /usr/local/psa/admin/logs/panel.log
```
