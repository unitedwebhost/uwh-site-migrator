=== UWH Site Migrator – WP Migration, Backup & Restore ===
Contributors: unitedwebhost
Tags: backup, migration, restore, google-drive, site-migration
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 2.4.15
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore, and migrate WordPress sites with resumable jobs, rollback checkpoints, direct migration, and optional Google Drive.

== Description ==

United Web Host Site Migrator helps WordPress administrators create full-site backups, restore completed backup packages, move sites between WordPress installations, and optionally send backups to Google Drive.

The plugin is designed around resumable jobs and recovery controls so larger backups and migrations can continue in smaller steps instead of relying on one long browser request.

= Features =

* Full WordPress files and database backup packages using the `.uwh` package format, including all WordPress-prefixed tables such as posts, postmeta, theme, and page-builder settings.
* Local restore with resumable processing and integrity checks.
* Direct Site-to-Site Migration using an expiring migration key and HTTPS server-to-server transfer.
* Optional pre-restore safety checkpoints with automatic rollback support when enabled.
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

For a cross-domain full-database migration, use a fresh dedicated destination database. The backup includes non-prefixed tables found in the source database; existing non-prefixed destination tables are never silently overwritten. Unsupported database views/storage engines and database changes detected during export produce an explicit error rather than an incomplete success.

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

Restore safety checkpoints are optional and enabled by default. On the Import / Export and Direct Migration screens, **Create restore safety checkpoint** is preselected to back up the current destination site before it is replaced; you can uncheck it to skip the checkpoint. When enabled, that checkpoint may be used for automatic rollback if the restore fails during protected phases. When unchecked, restore starts directly without an additional checkpoint, and **automatic rollback is unavailable**. This does not bypass backup integrity checks or resumable restore steps. Existing checkpoints remain available in the recovery interface.

= Why can a database backup report that a source table changed? =

Live WordPress sites can add postmeta, scheduler or other rows during a resumable database export. Site Migrator preserves all core, Elementor/theme and plugin tables; it does not remove postmeta or unknown plugin tables simply to bypass this warning. New backups capture a stable high-water key per table when the table uses an indexed, integer auto-increment primary key. Records added after that boundary are reported as a warning rather than wrongly treated as missing original rows. Changes to previously existing rows are not covered by a single site-wide transaction. Pause content edits, jobs and source database writes during a critical migration backup if a consistent point-in-time copy is required. For backups begun before this fix, start a new backup to apply the new boundary logic.

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

= 2.4.15 =
* Improve reliability of background backups when administrators switch tabs or close the browser: recover missing scheduled events and retry transient failures with limits.
* Use adaptive file and database batches to reduce backup processing overhead on varied servers, retaining integrity and resume protection.
* Preserve existing backup, restore, direct migration, Google Drive, maintenance and recovery functionality.

= 2.4.14 =
* Allow final verification to finish if the specific host-managed `.private/.htaccess` was removed or rewritten after restore; record a security warning requiring destination access-control review.
* Keep all other file-integrity checks, archive checks and restore features unchanged.

= 2.4.13 =
* Treat `.htaccess.bk` as an optional inactive server configuration backup copy during backup, restore and final file verification. Existing archives and paused verification jobs can complete when this nonessential copy is missing or altered.
* Preserve full checksum verification for all required website files, database and Elementor settings, active server configuration safety, Direct Migration, and resumable restore.

= 2.4.12 =
* Restore v2.4.5-style Elementor finalization: avoid reprocessing imported template postmeta at 99% while retaining database/file integrity and template-presence checks. Previously interrupted jobs can resume without the retired verification step.
* Improve cross-domain restore finalization by preserving malformed source serialized values rather than rewriting their invalid string lengths. Valid serialized settings continue to be rewritten with correct byte lengths.
* Retain Direct Migration, backup/restore, Google Drive, Skip Missing Plugins, and recovery functionality.

= 2.4.11 =
* Add explicit administrator-approved Skip & Resume for orphaned active plugin entries missing from a Direct Migration archive. Skipped plugins remain inactive at the destination; all database, theme and file integrity checks remain enforced.


= 2.4.10 =
* Source-first post-restore processing: preserve imported Elementor Theme Builder conditions and source logo identity; only rebuild missing conditions cache.
* Restore cache refresh remains deferred until source plugins load; previous incomplete restore jobs keep their earlier recovery behavior.


= 2.4.21 =
* Preserve WordPress core Site Logo references, reconcile Elementor Site Identity image URLs after migration, and provide logo diagnostics and a guarded manual repair.

= 2.4.20 =
* Verify WordPress Custom Logo, Site Icon and Elementor Site Identity attachment files during migration. Restore source custom logo setting when changed during transfer.
* Process larger but CPU/memory-bounded batches during final slug and builder-template verification.


= 2.4.19 =
* Fixed restore state progression from database import into the required resumable template and permalink verification phase.
* Existing paused 99% restore jobs can resume verification without starting a new migration.
* Clearer step labels during large template and slug verification.

= 2.4.18 =
* Rebuild Elementor Pro Theme Builder display conditions after migration, in addition to Elementor CSS.
* Add read-only Elementor template diagnostics and safe manual repair in Troubleshooting.
* Preserve original header/footer condition cache if regeneration fails or drops assigned templates.


= 2.4.17 =
* Preserve source page/post and taxonomy slugs, and source permalink settings during cross-domain restore.
* Verify restored Elementor header/footer metadata and display conditions before completion.
* Confirm missing or changed template metadata rather than reporting a false successful migration.


= 2.4.16 =
* Keep destination credentials active until the database, homepage, theme and site identity pass verification; publish the restored users/usermeta tables together at finalization.
* Preserve valid empty tagline settings and recreate only archive-verified site identity option rows when genuinely absent.
* Retain import receipts until the completed restore is durably saved; support safe retries and older interrupted restore states.

= 2.4.14 =
* Avoid false postmeta import failures when WordPress adds rows during an interrupted or long-running migration: verify every archived postmeta row and its content before accepting extra runtime rows.

= 2.4.13 =
* Restore the source active parent/child theme when the destination default theme changes the saved options during migration. Verify source backup theme options against archived database rows and validate theme file checksums before activation. Keep incomplete restore recovery resumable.



= 2.4.11 =
* WordPress.org release version updated; migration, database export, backup/restore, recovery, and no-file-staging logic remain unchanged from the final 2.4.10 package.
* Added authoritative source Reading Settings snapshot and verified restored homepage options and referenced page.
* Verify physical imported table row counts before a restore is declared complete; fail safely on mismatches.
* Keep existing .uwh archives readable and retain backup/restore, Drive and Direct Migration features.

= 2.4.10 =
* Added optional per-operation restore safety checkpoints for uploaded, local and direct restores. With the option disabled, restores skip the pre-restore backup and automatic rollback is unavailable.

* Validate source active and parent theme files before restore; verify restored theme settings and files before reporting completion.
* Queue an automatic, deferred Elementor CSS cache refresh and soft permalink refresh after migration.
* Rewrite alternate HTTP/HTTPS source URLs in migrated data, preserving serialized values.


= 2.4.9 =
* Improved responsive layouts on desktop, tablet and mobile, including long filenames, navigation, cards and scrollable tables.
* Improved restore confirmation keyboard accessibility and restore file selection feedback.


= 2.4.8 =
* Preserve .uwh backup filenames when downloading on mobile by serving local backups as binary attachments with an explicit .uwh filename.
* Harden Google Drive backup row rendering against malicious file names.
* Preserve WordPress core maintenance files owned by independent updates.
* Close archive handles on checksum failures without altering backup contents.
* Prevent uninstall cleanup from following unsafe or symlinked backup storage overrides.
* Restrict legacy background schedule migration to Site Migrator admin pages to reduce unrelated wp-admin I/O.
* Guard against unsafe legacy/custom backup storage paths that could block access to WordPress.
* Retain all previously merged backup, migration, selected-file and recovery improvements.

= 2.4.7 =
* Keep one server-backed progress indicator across Site Migrator screens; reconcile lost or invalid responses with the saved backup job.
* Use smaller database batches to reduce request timeouts, and preserve resumable jobs after refresh.
* Improve restore upload UX with a prominent selected-backup card, filename, size and remove action.
* Retain transferred source backups after Direct Migration while keeping restore checkpoints separate.
* Isolate WPvivid, All-in-One WP Migration and other external backup repositories from Site Migrator backup/restore file inventory.
* Avoid interfering with third-party plugin AJAX operations and maintain WordPress.org code checks for recovery requests/scripts/schema metadata.
* Keep safer login-independent restore recovery and directory compatibility updates from earlier releases.

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
