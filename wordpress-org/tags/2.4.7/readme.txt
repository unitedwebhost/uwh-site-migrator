=== UWH Site Migrator ===
Contributors: unitedwebhost
Tags: backup, migration, restore, google-drive, site-migration
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.4.7
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore, and migrate WordPress sites with resumable jobs, rollback checkpoints, direct migration, and optional Google Drive.

== Description ==

United Web Host Site Migrator helps WordPress administrators create full-site backups, restore completed backup packages, move sites between WordPress installations, and optionally send backups to Google Drive.

The plugin is designed around resumable jobs and recovery controls so larger backups and migrations can continue in smaller steps instead of relying on one long browser request.

= Features =

* Full WordPress files and database backup packages using the `.uwh` package format.
* Local restore with resumable processing and integrity checks.
* Direct Site-to-Site Migration using an expiring migration key and HTTPS server-to-server transfer.
* Automatic restore safety checkpoints with rollback support.
* Automatic scheduled backups.
* Optional Google Drive upload, inventory, import, and retention controls.
* Overview dashboard with local/cloud backup status and scheduling information.
* Troubleshooting diagnostics and downloadable diagnostic reports.
* Activity logs for backup, restore, rollback, Drive, scheduling, and migration events.
* Compatibility migration for Site Migrator 2.1.x settings and scheduled jobs when upgrading from the 2.1.x line.

Site Migrator does not require a third-party service for local backup, local restore, or local backup management. Google Drive features and Direct Site-to-Site Migration use external network requests only when an administrator configures or starts those features.

== Installation ==

1. Upload the `uwh-site-migrator` folder to `/wp-content/plugins/`, or install the plugin ZIP from the WordPress Plugins screen.
2. Activate **United Web Host Site Migrator**.
3. Open **Site Migrator > Overview**.
4. Use **Import / Export** to create or restore local backups.
5. Optional: open **Drive Settings** to connect Google Drive.
6. Optional: open **Direct Migration** on both the source and destination site to perform a server-to-server migration.

For Direct Site-to-Site Migration, install a compatible Site Migrator version on both sites and use HTTPS. The source and destination WordPress versions should match before the restore begins.

== Frequently Asked Questions ==

= What is stored inside a Site Migrator backup? =

A full backup can contain WordPress files and database data required to restore or migrate the site. Treat backup files as sensitive data and store them securely.

= Does Site Migrator support large sites? =

Backup, restore, Drive transfer, and Direct Migration workflows use chunked or resumable processing where applicable. Actual limits still depend on available disk space, PHP/server limits, WordPress hosting configuration, and remote service limits.

= Can I restore a backup created by Site Migrator 2.1.x? =

Version 2.4.6 keeps the `.uwh` package format and includes compatibility handling for existing Site Migrator 2.1.x settings, schedules, and unfinished restore state.

= Does the plugin require Google Drive? =

No. Google Drive is optional. Local backup, local restore, and local migration workflows can be used without connecting Google Drive.

= What happens before a destructive restore? =

Site Migrator creates a restore safety checkpoint before destructive restore phases begin. If the restore fails during protected phases, the plugin can use that checkpoint for rollback and also exposes available checkpoints in the recovery interface.

= Does Site Migrator support WordPress Multisite? =

No. The current release is intended for standard single-site WordPress installations.

== External Services ==

Site Migrator can communicate with external services when an administrator explicitly configures or uses the related feature.

= Google Drive and the Site Migrator OAuth gateway =

When an administrator connects or uses Google Drive, Site Migrator communicates with Google services for Drive authorization and file operations. It also communicates with the Site Migrator OAuth gateway at `https://migrator.unitedwebhost.com/` to coordinate the Google authorization flow and obtain short-lived access needed for the requested Drive operation.

Data sent as part of this flow can include Site Migrator connection identifiers, authorization state, Google authorization tickets/tokens required by the flow, backup metadata, and backup file content when the administrator uploads a backup to Google Drive. Google account information returned by the connection flow can include the connected account email address, which is stored locally in encrypted Site Migrator connection data.

Google services are provided by Google and are subject to Google's applicable terms and privacy policies. The Site Migrator OAuth gateway is operated by United Web Host. The following policy pages apply to the documented external-service flow:

* Site Migrator Privacy Policy: `https://site-migrator.unitedwebhost.com/privacy-policy/`
* Site Migrator Terms of Service: `https://site-migrator.unitedwebhost.com/terms-of-service/`
* United Web Host Privacy Policy: `https://unitedwebhost.com/privacy-policy/`
* United Web Host Terms and Conditions: `https://unitedwebhost.com/terms-conditions/`
* Google Privacy Policy: `https://policies.google.com/privacy`
* Google Terms of Service: `https://policies.google.com/terms`

= Direct Site-to-Site Migration =

When an administrator starts Direct Site-to-Site Migration, the destination WordPress site connects directly to the source WordPress site selected by the administrator. The destination requests migration metadata and the selected backup package in chunks. Authentication uses the temporary migration key created on the source. The destination sends that migration key in the `X-SVM-Migration-Key` request header while the transfer is active.

No Direct Migration connection is started automatically; the administrator supplies the source site URL and migration key and starts the transfer.

== Privacy ==

Site Migrator stores operational data in the WordPress installation and in the plugin's protected storage directory. Depending on the features used, this can include Site Migrator configuration/options, encrypted Google Drive connection metadata, completed local backup archives, restore safety checkpoints, temporary job/transfer state, Direct Migration session state, and activity logs.

Activity logs can contain the WordPress user ID and login name of the administrator performing an action, timestamps, operation status, and a limited allowlist of operational context such as backup names, job IDs, progress, source domain, and error messages. The activity log keeps up to 500 recent events while the plugin is installed.

Google Drive connection data is stored locally in an encrypted WordPress option bound to the current site and WordPress security salts. Backup archives can contain personal data or other sensitive site content from the WordPress database and uploaded files. Site owners are responsible for applying an appropriate retention policy and for handling exported backups according to their own privacy and legal requirements.

When Site Migrator is deleted from WordPress, it intentionally preserves Site Migrator configuration/options, the encrypted Google Drive connection metadata, completed local `.uwh` backups and completion markers, restore/rollback checkpoints, and backups already stored in Google Drive. This preservation is intended to make reinstall and recovery possible without silently deleting user backup data or configuration.

The uninstall cleanup removes runtime-only data such as activity logs and their deduplication state, temporary Direct Migration sessions, Site Migrator cron schedules, Site Migrator Drive-notice transients, temporary job/import/Drive-transfer/direct-pull files, locks, staging database files, encrypted in-progress Drive transfer state, and temporary restore guard state. Interrupted restore progress tables are preserved as recovery state instead of being deleted during uninstall. Unrelated WordPress options, files, transients, and database tables are not targeted.

Site Migrator does not intentionally send local backup or activity-log data to United Web Host unless an administrator uses a feature that requires the documented external service, such as the Google Drive connection gateway. Direct Migration sends backup data directly between the administrator-selected WordPress sites.

== Uninstall ==

Deleting Site Migrator removes runtime-only logs, temporary sessions, scheduled plugin jobs, temporary notices, locks, staging/import/transfer files, encrypted in-progress Drive transfer state, and temporary restore guard state. Interrupted restore progress tables are retained as recovery state.

For safety, uninstall does **not** delete Site Migrator settings/options, encrypted Google Drive connection metadata, completed local backups, restore/rollback checkpoints, or backups already stored in Google Drive. These items remain available for reinstall or recovery and can be removed manually by the site owner when they are no longer needed.

== Screenshots ==

1. Overview dashboard with backup, storage, schedule, and Drive status.
2. Import / Export screen for creating, restoring, downloading, and moving backups.
3. Direct Migration source and destination workflow.
4. Google Drive connection and backup inventory.
5. Troubleshooting diagnostic center and restore safety checkpoints.
6. Activity log view accessible from Troubleshooting.

== Changelog ==

= 2.4.7 =
* Show durable background backup progress and worker status across admin page navigation and reloads.
* Reduce cron idle gaps and database export round trips for faster background backups where hosting cron runs reliably.
* Retain the transferred source .uwh archive as a completed, downloadable destination backup after successful Direct Migration; the destination restore checkpoint remains separate.

= 2.4.6 =
* Preserve the live options table during staging, then atomically switch while retaining the destination uploads settings.
* Provide token-protected, login-independent restore resume from the original browser.
* Retain restore maintenance guard until the completed job is durably saved; detect already completed state.
* Keep Google Drive, Direct Migration, custom paths and non-InnoDB support from 2.4.5.

= 2.4.5 =
* Updated release version metadata to 2.4.5; no changes to backup, restore, Drive, or migration behavior.

= 2.4.1 =
* Fixed Google Drive Connect after the safe-redirect compliance change by temporarily allowlisting only the trusted Site Migrator OAuth gateway host during authorization redirects.

= 2.4.3 =
* Fixed the final Plugin Check slow-query false positive in restored usermeta row rewriting without changing migration behavior.

= 2.4.2 =
* Fixed Direct Site-to-Site Migration restore failures caused by runtime WordPress options being recreated between resumable database restore batches.
* Compatibility migrations now stay read-only during restore-step requests so partially restored `wp_options` data is not mutated between batches.
* `wp_options` rows from the source backup are authoritative during restore and safely replace duplicate runtime-created option keys; other database tables keep normal insert behavior.
* Includes the WordPress.org review hardening from the 2.4.1 line: scoped admin assets, sanitized request handling, encrypted Drive state/options, no generated MU-plugin loader, clean activation without ZipArchive, and HTTPS-only Direct Migration.
* Scheduled-backup startup failures now stay in Site Migrator Activity Logs instead of writing a duplicate raw PHP server error log.
* Cleaned the reported Plugin Check warning set by centralizing sanitized request reads, using safe redirects, and documenting intentional low-level database/streaming operations without changing backup, restore, Drive, or Direct Migration workflows.

= 2.4.1 =

* Fixed resumable Direct Site-to-Site Migration restores that could collide with live WordPress option rows between database batches; restore-step boot is now isolated from compatibility option/cron migration and source wp_options rows safely replace runtime collisions.

* Addressed WordPress.org review findings by enqueueing admin CSS on plugin screens, tightening request sanitization/authorization, and allowing clean activation when ZipArchive is unavailable.
* Replaced dynamically generated restore MU-plugin code with an in-plugin restore guard and maintenance response during destructive restore phases.
* Moved Google Drive connection and in-progress transfer state to encrypted WordPress options; existing legacy Drive configuration is migrated on first read.
* Renamed the WordPress.org plugin identity to **United Web Host Site Migrator** with the `uwh-site-migrator` slug while preserving existing UWHSM compatibility keys and internal data paths.
* Added explicit Site Migrator, United Web Host, and Google Privacy/Terms links to the External Services disclosure for Plugin Directory review clarity.
* Removed the global upgrader download interception while retaining the normal upload prefilter and installer-side `.uwh` guidance.
* Removed the admin hosting promotion and duplicate View details link; the remaining Plugin Website link now points to the public Site Migrator website.
* Improved plugin upload/overwrite compatibility on hosting platforms with aggressive ModSecurity/Imunify-style archive scanning.
* Removed uninstall-time raw database table deletion and preserved interrupted restore progress tables as recovery state.
* Switched uninstall temporary filesystem cleanup to WordPress' direct filesystem API while retaining strict path allowlists and preserving settings, backups, checkpoints, and Drive configuration.

= 2.4.0 =
* Added a guarded WordPress uninstall handler that removes only runtime/log/temp state while preserving Site Migrator settings/options, encrypted Google Drive configuration, completed local backups, restore/rollback checkpoints, and remote Drive backups.
* Added cleanup for activity logs/deduplication state, Direct Migration sessions, Site Migrator cron hooks and Drive-notice transients, exact temporary restore-progress tables, job/import/Drive-transfer/direct-pull state, locks, staging database files, and the temporary restore MU-plugin loader.
* Added strict preservation guards so broad `uwhsm_*`/`svm_*` option or database deletion is not performed during uninstall.
* Improved PHP readability and WordPress Coding Standards alignment across core classes, including expanded control flow, consistent indentation/spacing, clearer one-statement-per-line formatting in high-risk runtime code, and safer superglobal unslash/sanitization paths.
* Kept the `.uwh` format, admin slugs, AJAX/cron identifiers, Google Drive storage format, Direct Migration wire protocol, and restore/rollback behavior unchanged.

= 2.3.0 =
* Replaced the remaining raw cURL Drive download path with the WordPress HTTP API using safe streamed downloads to disk.
* Added full PHP admin/runtime internationalization using the `site-migrator` text domain.
* Added native JavaScript translations through `wp.i18n` and `wp_set_script_translations()`.
* Added `languages/uwh-site-migrator.pot` for translation tooling.
* Preserved the `.uwh` package format, existing admin slugs, `uwhsm_*` routes, and Direct Migration wire compatibility.

= 2.2.0 =
* Renamed plugin-owned PHP, WordPress hook/action, option, cron, nonce, and browser-global prefixes to `UWHSM_` / `uwhsm_`.
* Added an explicit backward-compatibility layer for Site Migrator 2.1.x options, scheduled events, legacy request routes, existing Direct Migration sessions, and unfinished restore progress tables.
* Kept the existing `.uwh` backup format, backup filenames, plugin slug, admin page slugs, and Direct Migration wire key/header protocol unchanged.
* Converted `readme.txt` to the WordPress Plugin Directory format.
* Added GPLv2-or-later metadata and the full GNU GPL version 2 license text.

For older release history, see `CHANGELOG.txt` included with the plugin.

== Filesystem compatibility ==

Backup packages use portable logical paths. Physical WordPress content, plugin and uploads directories are resolved using the installation configuration (including custom locations) rather than requiring a literal wp-content directory next to core. Existing backup archive format version 1 stays supported. Backup data and temporary processing are stored under the WordPress uploads basedir /uwh-site-migrator.
