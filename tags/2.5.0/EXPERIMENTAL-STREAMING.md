# UWH Site Migrator experimental append-only ZIP writer (v2.4.18)

**Not enabled by default. Do not publish as a production WordPress.org release yet.**

This experiment addresses high ZipArchive::close() time on growing site backups. It creates a standard ZIP64 .uwh archive with the same manifest.json, site/, database/ and integrity.json layout as the legacy writer. Temporary partial backups are not valid ZIP archives until finalization. Only NEW MANUAL BACKUPS use this writer when enabled. Completed and existing legacy backup jobs are unaffected. Scheduled automatic backups, restore checkpoints and restore operations remain on the legacy engine.

To opt in on a disposable staging site, add the following to wp-config.php, ABOVE the WordPress bootstrap line:

    define( 'UWHSM_EXPERIMENTAL_STREAM_ZIP', true );

Then create a fresh manual backup. Do not enable during a running backup. Turn it off by deleting the definition; it affects only new manual backups, not unfinished jobs that already use the stream writer. Pause/Resume/Stop and source integrity verification are kept; failed writes truncate to the last durable saved position. On completion, the writer creates the ZIP64 central directory once and the plugin checks the final archive with ZipArchive before marking the backup complete.

## Required checks before a public release

1. Create a fresh backup in the legacy mode and the experimental mode on the same quiet staging site.
2. Test a small archive, >1 GB site with many small files, >4 GB ZIP64, and >65,535 entries (the last two require dedicated testing).
3. Close the browser mid-backup, recover via server cron, pause/resume, stop an unfinished job, and simulate PHP termination during archive append and finalization.
4. Restore the resulting .uwh archive on an isolated destination. Compare table counts, theme, Elementor, media, users, URLs and file hashes.
5. Repeat on Apache, LiteSpeed, Nginx and managed hosting. Capture batch performance and PHP errors before deciding on default enablement.

The archive format is unchanged. The extra temporary `.entries` journal is never part of the completed `.uwh` archive; it is removed at successful completion or Stop/Delete.
