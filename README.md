# FTP Sync

A Grav plugin that adds a dedicated Admin Panel page to sync site content between a local (DDEV) environment and hosting over FTP/FTPS — no Git or SSH required on the hosting side, which makes it a good fit for shared hosting plans that only offer FTP.

## Features

- **Check differences**: scans local + hosting, compares by `mtime` + `size`, and reports new/deleted/conflicting files (changed on both sides) per content group (Pages, Themes, Plugins, Config, Accounts).
- **Sync now**: applies your per-file choice (keep Local / keep Hosting / delete on either side) after running Check differences.
- **Replace local sync to hosting**: deletes everything currently on hosting (within the checked groups) and re-uploads everything from local — use this to make hosting match local exactly.
- **Full deploy to hosting**: ignores the checkboxes/config entirely — scans the **whole site** (`system/`, `vendor/`, all of `user/`, root files like `index.php`/`.htaccess`...), excluding only what has no effect on the running site (dev/test/docs files, runtime caches, `.git`...). Deletes the corresponding content on hosting, bundles everything else into **one `.zip` file**, and uploads it to the hosting root. Use this for a brand-new hosting deploy or to fully reset a broken/messy hosting copy. **You need to extract the zip yourself on hosting** (File Manager / SSH) — the plugin does not auto-extract it.
- **Automatic backups**: before overwriting or deleting any file on hosting, the previous version is automatically zipped into `user/data/ftp-sync/backups/*.zip`. Backups can be viewed/deleted right from the Admin page.
- **Real progress bars**: multi-file uploads/syncs are split into small sequential AJAX batches instead of one long-running request, so large sites don't time out.
- **Automatic local-environment detection**: every action is locked unless a `.ddev/` folder is detected at the project root — on a live hosting copy of this same plugin, nothing ever runs (FTP credentials are never exercised from production).

## Requirements

- Grav >= 1.7.0, with the **Admin** plugin.
- PHP `ftp` extension (enabled by default in most PHP setups).
- A valid FTP/FTPS account on the target hosting.
- A local environment with `.ddev/` present (or `force_allow_remote` enabled if you really want to bypass this check — not recommended).
- `admin.super` permission on the logged-in Admin account.

## Installation

The plugin already lives in `user/plugins/ftp-sync/` in this repo (not installed via GPM). After pulling the code, just make sure it's **Enabled** in the Admin Panel (`Admin > Plugins > FTP Sync`).

## Configuration

Go to `Admin > Plugins > FTP Sync` and fill in:

| Field | Description |
|---|---|
| **Plugin status** | Enable/disable the whole plugin |
| **Allow running even when not detected as local** | Bypasses the local-environment check — **not recommended**, only enable if you understand the risk |
| **Auto-backup before overwriting** | Enable/disable the automatic backup mechanism |
| **Root directory on hosting** | Absolute FTP path matching the site's webroot on hosting, e.g. `/public_html/eznotary` |
| **Plugins to sync** | List of plugin names (under `user/plugins/`) to sync via "Sync now"/"Replace local sync" — leave empty to auto-sync ALL plugins. Does not affect "Full deploy" (which always includes everything). |
| **File/folder patterns to skip when syncing** | Comma-separated exclude patterns, supports `*`, applies to "Check differences"/"Sync now"/"Replace local sync" and is also respected on top of "Full deploy"'s own exclusions |
| **FTP Host / Port / Username / Password** | FTP connection details |
| **Use FTPS** | Enable if hosting requires FTP over SSL |
| **Passive mode** | Most shared hosting requires passive mode enabled |

## Usage

1. Go to **Admin > FTP Sync** (left menu, exchange ⇄ icon).
2. Pick the content groups you want to act on in the left panel: **Pages / Themes / Plugins / Config / Accounts**.
3. Pick an action in the right panel:
   - **Check differences** → review the list of differing files → choose an action per row (or bulk-apply to a selection) → **Sync now**.
   - **Replace local sync to hosting** → confirm in the warning dialog → hosting will match local 100% (within the checked groups).
   - **Full deploy to hosting** → confirm in the warning dialog → the entire site is bundled into one `.zip` and uploaded to the hosting root → **you extract it yourself** on hosting.
4. **Show backups** → view/delete automatically created backups.

### Notes on "Full deploy to hosting"

- Great for a first-time deploy to a fresh hosting account, or to fully reset hosting to match local.
- The zip is named `deploy-<timestamp>.zip` and placed right at the hosting root — extract it in place (no need to create a subfolder), then delete the zip.
- Make sure hosting runs a compatible PHP version (PHP >= 8.1 recommended, matching the local build environment) and has the `zip` extension available to extract it.
- After extracting, check the "Essential Folders" page in `/admin` (if the `problems` plugin is installed) to confirm `cache/logs/tmp/backup/images/assets` exist and are writable.

## Security

- The FTP password is stored in plaintext in the config (`user/config/plugins/ftp-sync.yaml`) — this file is **always excluded** from every sync/deploy to avoid accidentally uploading it to hosting.
- Other sensitive files (`security.yaml`, `security-private.php`, `versions.yaml`) are excluded by default too.
- Every write action (upload/delete) is fully locked unless the plugin detects it's running in a local environment (`.ddev/`) — unless `force_allow_remote` is explicitly enabled.

## Author

**tipforeveryone** — MIT License.
