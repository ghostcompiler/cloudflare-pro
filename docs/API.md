# API And Controller Actions

All JSON actions live in `plib/controllers/IndexController.php` and are called from the Plesk UI with POST requests.

## Page Actions

- `domains`: lists linked Plesk domains and matching Cloudflare zones.
- `tokens`: lists stored Cloudflare tokens.
- `api-logs`: lists Cloudflare API logs.
- `settings`: loads per-user settings.
- `records`: shows DNS records for one linked domain.

## Token Actions

- `add-token`: stores a new token after Cloudflare validation.
- `update-token`: updates the token name and optionally replaces the token value.
- `validate-token`: verifies a stored token and updates status.
- `delete-token`: removes a token and related linked domain rows.

## Domain Actions

- `sync-domain`: runs a direct full export from Plesk to Cloudflare.
- `toggle-autosync`: updates the per-domain Auto Sync flag.
- `start-sync-job`: starts an import, export, or sync background job.
- `process-sync-job`: processes the next batch for a background job.
- `sync-job-status`: returns persisted job progress for page refresh recovery.

## Record Actions

- `set-record-proxy`: toggles Cloudflare proxy on A, AAAA, and CNAME records.
- `record-action`: pushes, pulls, or deletes an individual DNS record.

## API Log Actions

- `api-logs-data`: returns searchable and paginated log rows.
- `clear-api-logs`: removes stored API logs for the current user.

## Settings Actions

- `update-settings`: updates one setting inside the per-user JSON payload.

## Cloudflare Routes Used

- `GET /user/tokens/verify`
- `GET /zones`
- `GET /zones/{zone_id}/dns_records`
- `POST /zones/{zone_id}/dns_records`
- `PATCH /zones/{zone_id}/dns_records/{record_id}`
- `DELETE /zones/{zone_id}/dns_records/{record_id}`

Requests and responses are logged when the setting `log_api_calls` is enabled.
