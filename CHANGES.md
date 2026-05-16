# Changelog

## 1.0.5

- Add optional Cloudflare-only www companion records for subdomain autosync.
- Protect delete autosync so only concrete child hostnames are removed from Cloudflare.
- Improve DNS record sync reliability by skipping unchanged Cloudflare updates.
- Refresh extension documentation and screenshots for clearer installation, setup, and troubleshooting.
- Add multiple light-theme screenshots for the Plesk extension information page.

## 1.0.4

- Refresh the public documentation site with install, feature, screenshot, and troubleshooting sections.
- Add copy controls to documentation command examples.
- Improve the extension information page copy and version display.

## 1.0.3

- Harden install-time storage initialization for Plesk CLI lifecycle scripts.
- Rename the public package and release format to 1.0.3.
- Add selectable DNS record rows with bulk Push, Pull, and Delete actions.
- Disable Cloudflare proxy defaults for new settings by default.
- Refresh the About and Plesk extension information pages for the 1.0.3 release.
- Add TLSA DNS record sync support with Cloudflare structured data fields.
- Add SRV DNS record sync support.
- Add API log table pagination.
- Move selected record actions below the Back, Import, Export, and Sync All controls.
- Respect the Log Cloudflare API calls setting before writing API log entries.
- Do not write API log entries for passive page refresh reads.
- Reduce Sync All Cloudflare calls by processing records in larger batches and skipping unchanged updates.

## 1.0.2

- Preserve existing Cloudflare proxy status when autosync or manual sync updates DNS records.
- Apply proxy default settings only when creating new Cloudflare DNS records.

## 1.0.1

- Initial Cloudflare Pro extension setup.
