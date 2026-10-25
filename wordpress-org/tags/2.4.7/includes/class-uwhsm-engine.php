<?php
if (!defined('ABSPATH')) {
    exit;
}

final class UWHSM_Engine {
    private static $instance;
    private $base;
    private $backup_dir;
    private $import_dir;
    private $checkpoint_dir;
    private $batch_files = 1000;
    private $batch_rows = 1000; // Fewer round trips on normal installations; preserves resumable state.

    public static function instance() {
        return self::$instance ?: (self::$instance = new self());
    }

    public static function activate() {
        // WordPress enforces the Requires PHP header before activation. Optional runtime
        // capabilities such as ZipArchive are reported in wp-admin instead of aborting activation.
        self::instance()->ensure_dirs();
    }

    private function __construct() {
        $uploads = wp_upload_dir();
        $default_base = trailingslashit($uploads['basedir']) . 'uwh-site-migrator';
        $legacy_bases = array(
            trailingslashit($uploads['basedir']) . 'site-migrator',
            trailingslashit($uploads['basedir']) . 'site-vault-migrator',
        );
        $this->base = defined('UWHSM_STORAGE_PATH') ? UWHSM_STORAGE_PATH : $default_base;
        if (!defined('UWHSM_STORAGE_PATH') && !is_dir($default_base)) {
            foreach ($legacy_bases as $legacy_base) {
                if (!is_dir($legacy_base)) {
                    continue;
                }
                @wp_mkdir_p(dirname($default_base));
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
                if (@rename($legacy_base, $default_base)) {
                    $this->base = $default_base;
                } else {
                    $this->base = $legacy_base;
                }
                break;
            }
        }
        $this->backup_dir = $this->base . '/backups';
        $this->import_dir = $this->base . '/imports';
        $this->checkpoint_dir = $this->base . '/checkpoints';
        $this->ensure_dirs();
        foreach (array('uwhsm_backup_start', 'uwhsm_backup_step', 'uwhsm_backup_status', 'uwhsm_upload_start', 'uwhsm_upload_chunk', 'uwhsm_restore_start', 'uwhsm_restore_local_start', 'uwhsm_restore_checkpoint_finish', 'uwhsm_restore_recover', 'uwhsm_restore_step', 'uwhsm_checkpoint_rollback_start', 'uwhsm_checkpoint_delete', 'uwhsm_delete_backup') as $action) {
            add_action('wp_ajax_' . $action, array($this, $action));
        }



        // Auto Backup er AJAX ebong Cron hooks autobackup
        add_action('wp_ajax_uwhsm_save_auto_backup', array($this, 'uwhsm_save_auto_backup'));
        add_filter('cron_schedules', array($this, 'uwhsm_custom_cron_schedules'));
        add_action('uwhsm_auto_backup_event', array($this, 'run_auto_backup'));



        // Restore may replace users and authentication cookies; the unprivileged route remains protected by a 256-bit per-job token.
        add_action('wp_ajax_nopriv_uwhsm_restore_step', array($this, 'uwhsm_restore_step'));
        add_action('wp_ajax_nopriv_uwhsm_restore_recovery_page', array($this, 'restore_recovery_page'));
        add_action('wp_ajax_uwhsm_restore_recovery_page', array($this, 'restore_recovery_page'));
        add_action('admin_post_uwhsm_download', array($this, 'download'));
        add_action('template_redirect', array($this, 'maybe_block_frontend_during_restore'), 0);
        add_filter('login_message', array($this, 'restore_login_message'));
    }

    private function enable_restore_guard(array &$state) {
        $restore_id = (string) ($state['restore_id'] ?? '');
        if (!$this->safe_id($restore_id)) {
            throw new RuntimeException(esc_html__('Invalid restore guard state.', 'uwh-site-migrator'));
        }
        $self = plugin_basename(UWHSM_FILE);
        $active = get_option('active_plugins', array());
        $active = is_array($active) ? $active : array();
        if (empty($state['destination_active_plugins_before_restore'])) {
            $state['destination_active_plugins_before_restore'] = array_values($active);
        }
        if ($active !== array($self)) {
            update_option('active_plugins', array($self));
        }
        update_option('uwhsm_restore_guard', array('restore_id' => $restore_id, 'started' => time()), false);
        $state['restore_guard_active'] = true;
    }

    private function clear_restore_guard() {
        delete_option('uwhsm_restore_guard');
    }

    private function restore_plugin_option_hygiene(array $state) {
        $indexes = array(
            'uwhsm_drive_transfer_state_ids' => 'uwhsm_drive_transfer_state_',
            'uwhsm_drive_import_state_ids'   => 'uwhsm_drive_import_state_',
        );
        foreach ($indexes as $index_option => $state_prefix) {
            $ids = get_option($index_option, array());
            $ids = is_array($ids) ? $ids : array();
            foreach ($ids as $id) {
                $id = is_scalar($id) ? (string) $id : '';
                if (preg_match('/^[a-f0-9]{32,64}$/D', $id)) {
                    delete_option($state_prefix . $id);
                }
            }
            delete_option($index_option);
        }
        // Keep the guard until the finalized restore state is durably written.

        $source_mode = (string) ($state['source_mode'] ?? '');
        if (!in_array($source_mode, array('rollback', 'checkpoint'), true)) {
            delete_option('uwhsm_google_drive_settings_v1');
            $destination = $state['destination_drive_settings'] ?? '';
            if (is_string($destination) && '' !== $destination) {
                update_option('uwhsm_google_drive_settings_v1', $destination, false);
            }
        }
    }

    public function maybe_block_frontend_during_restore() {
        $guard = get_option('uwhsm_restore_guard', array());
        if (!is_array($guard) || !$this->safe_id((string) ($guard['restore_id'] ?? ''))) {
            return;
        }
        $state = $this->read_state((string) $guard['restore_id']);
        if (is_array($state) && 'done' === ($state['phase'] ?? '')) {
            // The previous request may have finished but lost its HTTP response.
            $this->clear_restore_guard();
            return;
        }
        status_header(503);
        nocache_headers();
        if (!headers_sent()) {
            header('Retry-After: 60');
        }
        $recovery_url = admin_url('admin-ajax.php?action=uwhsm_restore_recovery_page');
        $message = '<p>' . esc_html__('Website migration is paused or in progress. Your data is not automatically deleted.', 'uwh-site-migrator') . '</p>';
        $message .= '<p><a href="' . esc_url($recovery_url) . '">' . esc_html__('Resume a restore saved in this browser', 'uwh-site-migrator') . '</a></p>';
        $message .= '<p>' . esc_html__('If this browser has no saved restore session, contact your hosting administrator. Do not start another restore or delete the safety checkpoint.', 'uwh-site-migrator') . '</p>';
        wp_die($message, esc_html__('Site Migrator maintenance', 'uwh-site-migrator'), array('response' => 503)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is composed solely of escaped strings and URLs.
    }

    /** Give a signed-out administrator the same browser-local recovery path. */
    public function restore_login_message($message) {
        $guard = get_option('uwhsm_restore_guard', array());
        if (!is_array($guard) || !$this->safe_id((string) ($guard['restore_id'] ?? ''))) {
            return $message;
        }
        $url = admin_url('admin-ajax.php?action=uwhsm_restore_recovery_page');
        return $message . '<div class="message"><p>' . esc_html__('A website restore may have signed you out. You can resume the saved restore from the original browser without signing in.', 'uwh-site-migrator') . '</p><p><a href="' . esc_url($url) . '">' . esc_html__('Resume interrupted restore', 'uwh-site-migrator') . '</a></p></div>';
    }

    /**
     * Public recovery view contains no job ID or credential. Only a token already
     * saved in this browser can authorize the existing restore-step endpoint.
     */
    public function restore_recovery_page() {
        if ('GET' !== strtoupper(sanitize_text_field(wp_unslash((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))))) {
            status_header(405);
            exit;
        }
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow', true);
        header('X-Content-Type-Options: nosniff', true);
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'unsafe-inline'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'", true);
        header('Content-Type: text/html; charset=' . get_option('blog_charset', 'UTF-8'), true);
        $ajax_url = admin_url('admin-ajax.php');
        // The recovery page is intentionally standalone (outside wp_head/wp_footer),
        // but its external script still goes through WordPress's enqueue API.
        wp_enqueue_script('uwhsm-restore-recovery', UWHSM_URL . 'assets/restore-recovery.js', array(), UWHSM_VERSION, false);
        ?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php esc_html_e('Site Migrator restore recovery', 'uwh-site-migrator'); ?></title>
<style>body{font-family:system-ui,-apple-system,sans-serif;background:#eef2ff;color:#1e293b;margin:0;padding:5vh 18px}main{max-width:600px;margin:auto;background:#fff;border:1px solid #c7d2fe;border-radius:16px;padding:26px;box-shadow:0 20px 55px #1e293b15}h1{font-size:24px}p{line-height:1.65}button{background:#4338ca;color:#fff;padding:13px 20px;border:0;border-radius:9px;cursor:pointer;font-weight:700}button:disabled{opacity:.55;cursor:wait}#status{white-space:pre-wrap;word-break:break-word;padding:14px;background:#f8fafc;border-radius:8px;margin-top:16px}</style></head>
<body><main data-ajax="<?php echo esc_url($ajax_url); ?>">
<h1><?php esc_html_e('Resume your interrupted migration', 'uwh-site-migrator'); ?></h1>
<p><?php esc_html_e('Use the same browser and browser profile that started the restore. This page only resumes the already saved restore; it cannot start a new migration.', 'uwh-site-migrator'); ?></p>
<p><?php esc_html_e('Before resuming, retain a hosting snapshot and the safety checkpoint. Do not delete files or reset the database while restoring.', 'uwh-site-migrator'); ?></p>
<button type="button" id="resume" disabled><?php esc_html_e('Resume saved restore', 'uwh-site-migrator'); ?></button>
<p id="status" role="status" aria-live="polite"><?php esc_html_e('Checking this browser for a saved restore session...', 'uwh-site-migrator'); ?></p>
</main><?php wp_print_scripts(array('uwhsm-restore-recovery')); ?></body></html>
        <?php
        exit;
    }

    public function uwhsm_custom_cron_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = array('interval' => 604800, 'display' => __('Once Weekly', 'uwh-site-migrator'));
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array('interval' => 2592000, 'display' => __('Once Monthly', 'uwh-site-migrator'));
        }
        return $schedules;
    }

    /**
    * Calculate the next local occurrence of HH:MM and return its Unix timestamp.
    * The timestamp can be passed directly to wp_schedule_event().
    */
    private static function calculate_auto_backup_first_run($backup_time, DateTimeZone $timezone, $now_timestamp = null) {
        if (!is_string($backup_time) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $backup_time)) {
            throw new InvalidArgumentException(esc_html__('Invalid automatic backup time.', 'uwh-site-migrator'));
        }

        list($hour, $minute) = array_map('intval', explode(':', $backup_time, 2));

        if (null === $now_timestamp) {
            $now = new DateTimeImmutable('now', $timezone);
        } else {
            $now = (new DateTimeImmutable('@' . (int) $now_timestamp))->setTimezone($timezone);
        }

        $next = $now->setTime($hour, $minute, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    public function uwhsm_save_auto_backup() {
        $this->guard();

        $frequency_raw = UWHSM_Compat::post_value('frequency');
        $backup_time_raw = UWHSM_Compat::post_value('backup_time');
        $retention_raw = UWHSM_Compat::post_value('drive_retention');
        $frequency = '' !== $frequency_raw ? sanitize_key($frequency_raw) : 'none';
        $backup_time = '' !== $backup_time_raw ? $backup_time_raw : '03:00';
        $drive_retention = '' !== $retention_raw ? absint($retention_raw) : absint(get_option('uwhsm_auto_drive_retention', 7));
        $allowed = array('none', 'daily', 'weekly', 'monthly');

        if (!in_array($frequency, $allowed, true)) {
            $this->fail(__('Invalid automatic backup frequency.', 'uwh-site-migrator'), 400);
            return;
        }

        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $backup_time)) {
            $this->fail(__('Invalid automatic backup time. Use HH:MM, for example 03:00.', 'uwh-site-migrator'), 400);
            return;
        }
        if ($drive_retention < 1 || $drive_retention > 100) {
            $this->fail(__('Automatic Drive retention must be between 1 and 100 backups.', 'uwh-site-migrator'), 400);
            return;
        }

        // Persist the UI choice first so a reload always reflects the administrator's selection.
        update_option('uwhsm_auto_backup_frequency', $frequency);
        update_option('uwhsm_auto_backup_time', $backup_time);
        update_option('uwhsm_auto_drive_retention', $drive_retention);

        // Always remove the previous recurrence before creating the new one.
        wp_clear_scheduled_hook('uwhsm_auto_backup_event');

        if ('none' === $frequency) {
            UWHSM_Logger::log('schedule', 'success', __('Automatic backups disabled.', 'uwh-site-migrator'), array('frequency' => 'none'));
            wp_send_json_success(array(
                'message' => __('Automatic backups disabled. Manual backups remain available.', 'uwh-site-migrator'),
                'frequency' => 'none',
                'backup_time' => $backup_time,
                'drive_retention' => $drive_retention,
                'next_run' => 0,
            ));
            return;
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        try {
            $first_run = self::calculate_auto_backup_first_run($backup_time, $timezone);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 400);
            return;
        }

        $scheduled = wp_schedule_event($first_run, $frequency, 'uwhsm_auto_backup_event');
        $next_run = wp_next_scheduled('uwhsm_auto_backup_event');

        if (!$scheduled || !$next_run) {
            UWHSM_Logger::log('schedule', 'failed', __('Automatic backup schedule could not be registered.', 'uwh-site-migrator'), array('frequency' => $frequency, 'message' => __('WP-Cron event registration failed', 'uwh-site-migrator')));
            // Keep the saved preference visible in the UI, but report that cron registration failed.
            wp_send_json_error(array(
                'message' => sprintf(
                /* translators: %s: automatic backup frequency. */
                __('The setting was saved as %s, but WordPress could not create the WP-Cron event. Check DISABLE_WP_CRON / hosting cron configuration.', 'uwh-site-migrator'),
                array('daily' => __('Daily', 'uwh-site-migrator'), 'weekly' => __('Weekly', 'uwh-site-migrator'), 'monthly' => __('Monthly', 'uwh-site-migrator'))[$frequency] ?? $frequency
                ),
                'frequency' => $frequency,
                'backup_time' => $backup_time,
                'drive_retention' => $drive_retention,
                ), 500);
            return;
        }

        UWHSM_Logger::log('schedule', 'success', __('Automatic backup schedule updated.', 'uwh-site-migrator'), array('frequency' => $frequency, 'next_run' => (int) $next_run));
        wp_send_json_success(array(
            'message' => __('Auto backup settings saved successfully.', 'uwh-site-migrator'),
            'frequency' => $frequency,
            'backup_time' => $backup_time,
            'drive_retention' => $drive_retention,
            'next_run' => (int) $next_run,
        ));
    }

    private function clean_backup_name($prefix, $directory = null) {
        $prefix = preg_replace('/[^a-z0-9_.-]/i', '', strtolower((string) $prefix));
        if ($prefix === '') {
            $prefix = 'site-migrator';
        }
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $date = strtolower((new DateTimeImmutable('now', $timezone))->format('d-M-Y'));
        $directory = $directory ?: $this->backup_dir;
        $base = $prefix . '-' . $date;
        $name = $base . '.uwh';
        $counter = 2;
        while (is_file($directory . '/' . $name) || is_file($directory . '/' . $name . '.json')) {
            $name = $base . '-' . $counter . '.uwh';
            $counter++;
        }
        return $name;
    }

    public function run_auto_backup() {
        // Drive connect kora na thakle auto backup hobe na
        try {
            if (!class_exists('UWHSM_Drive')) return;
            $drive = UWHSM_Drive::status();
            if (empty($drive['connected'])) return;
            UWHSM_Drive::site_identifier();
        } catch (Throwable $e) {
            return;
        }

        try {
            $job = bin2hex(random_bytes(16));

            $domain = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $domain = preg_replace('/[^a-z0-9_.-]/i', '', strtolower($domain));
            $prefix = $domain ? $domain : 'site-migrator';

            $name = $this->clean_backup_name($prefix);
            $zip_path = $this->backup_dir . '/' . $name;

            $zip = new ZipArchive();
            if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                return;
            }

            $manifest = array(
                'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
                'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
                'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix,
                'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION, 'active_plugins' => get_option('active_plugins', array()),
            );

            $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $files = $this->collect_files();
            $tables = $this->collect_tables();
            $file_bytes = 0;
            foreach ($files as $file) {
                $file_bytes += (int) $file['size'];
            }
            $db_stats = $this->database_stats();
            $db_bytes = $db_stats['size'];

            // Use the same safety checks as a manual backup before starting a large automatic archive.
            $estimated_total = $file_bytes + $db_bytes;
            $free = @disk_free_space($this->base);
            if ($estimated_total > 2 * 1024 * 1024 * 1024 && PHP_INT_SIZE < 8) {
                throw new RuntimeException(__('This server uses 32-bit PHP and cannot reliably create backups larger than 2 GB. Ask the host to enable 64-bit PHP.', 'uwh-site-migrator'));
            }
            $required = (int) ($estimated_total * 2.5) + 256 * 1024 * 1024;
            if (false !== $free && $free < $required) {
                throw new RuntimeException(__('Not enough free disk space for the scheduled backup. Estimated requirement: ', 'uwh-site-migrator') . size_format($required, 2) . sprintf(
                    /* translators: %s: available disk space. */
                    __('; available: %s.', 'uwh-site-migrator'),
                    esc_html(size_format($free, 2))
                ));
            }

            $db_stage = $this->base . '/db-' . $job;
            wp_mkdir_p($db_stage);

            // Ekhane force delivery 'drive' kora hocche ebong 'is_auto' flag deya hocche
            $state = array('type' => 'backup', 'job' => $job, 'phase' => 'files', 'delivery' => 'drive', 'is_auto' => true, 'delivery_status' => 'pending', 'zip' => $zip_path, 'db_stage' => $db_stage, 'name' => $name, 'files' => $files, 'file_index' => 0, 'file_bytes' => $file_bytes, 'file_bytes_done' => 0, 'database_bytes' => $db_bytes, 'database_rows_total' => $db_stats['rows'], 'tables' => $tables, 'table_index' => 0, 'row_offset' => 0, 'rows_done' => 0, 'inventory' => array(), 'table_rows' => array(), 'total_units' => max(1, count($files)+count($tables)*3), 'done_units' => 0, 'created' => time());

            $this->write_state($job, $state);
            UWHSM_Logger::log('backup', 'started', __('Scheduled backup started.', 'uwh-site-migrator'), array('job' => $job, 'backup' => $name, 'delivery' => 'drive'));

            // A scheduled backup has no browser tab to keep calling uwhsm_backup_step().
            // Hand it to the existing background worker so the job actually progresses to completion.
            if (!class_exists('UWHSM_Background')) {
                throw new RuntimeException(__('Background backup worker is unavailable.', 'uwh-site-migrator'));
            }
            UWHSM_Background::set_enabled($job, true);

        } catch (Throwable $e) {
            if (!empty($job)) {
                // If worker setup failed, remove the unfinished state so it is not shown as a stuck backup forever.
                wp_delete_file($this->state_path($job));
                wp_delete_file($this->lock_path($job));
            }
            if (!empty($db_stage) && is_dir($db_stage)) {
                try {
                    $this->remove_tree($db_stage);
                } catch (Throwable $ignored) {
                    /* Best effort cleanup. */
                }
            }
            if (!empty($zip_path)) {
                wp_delete_file($zip_path);
            }
            UWHSM_Logger::log('backup', 'failed', __('Scheduled backup could not start.', 'uwh-site-migrator'), array('job' => (string) ($job ?? ''), 'backup' => (string) ($name ?? ''), 'error' => $e->getMessage()));
        }
    }







    public function ensure_dirs() {
        foreach (array($this->base, $this->backup_dir, $this->import_dir, $this->checkpoint_dir) as $dir) {
            if (!$dir) {
                continue;
            }
            wp_mkdir_p($dir);
            if (!file_exists($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
            if (!file_exists($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
        }
    }

    public function integration_directory() {
        return $this->base;
    }

    private function guard() {
        UWHSM_Compat::verify_ajax_nonce('uwhsm_admin');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Administrator permission required.', 'uwh-site-migrator')), 403);
        }
        if (is_multisite()) {
            wp_send_json_error(array('message' => __('WordPress Multisite is not supported. Use Site Migrator on a standard single-site installation.', 'uwh-site-migrator')), 400);
        }
    }

    private function fail($message, $code = 400) {
        wp_send_json_error(array('message' => $message), $code);
    }

    private function state_path($job) {
        return $this->base . '/job-' . sanitize_file_name($job) . '.json';
    }

    private function cancel_path($job) {
        return $this->base . '/cancel-' . sanitize_file_name($job) . '.flag';
    }

    private function lock_path($job) {
        return $this->base . '/job-' . sanitize_file_name($job) . '.lock';
    }

    private function read_state($job) {
        $path = $this->state_path($job);
        if (!is_file($path)) {
            return false;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : false;
    }

    private function write_state($job, array $state) {
        $tmp = $this->state_path($job) . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
        if (false === file_put_contents($tmp, wp_json_encode($state), LOCK_EX) || !@rename($tmp, $this->state_path($job))) {
            throw new RuntimeException(esc_html__('Could not save migration progress. Check uploads directory permissions.', 'uwh-site-migrator'));
        }
    }

    private function safe_id($id) {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{20,64}$/', $id);
    }

    public function system_status() {
        $free = @disk_free_space($this->base);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only diagnostics must check the actual local filesystem permissions without changing them.
        $writable = is_writable($this->base) && is_writable($this->backup_dir) && is_writable($this->import_dir);
        return array(
            array('label' => __('PHP runtime', 'uwh-site-migrator'), 'value' => PHP_VERSION . (PHP_INT_SIZE >= 8 ? __(' · 64-bit', 'uwh-site-migrator') : __(' · 32-bit', 'uwh-site-migrator')), 'ok' => version_compare(PHP_VERSION, '7.4', '>=') && PHP_INT_SIZE >= 8),
            array('label' => __('UWH / ZIP', 'uwh-site-migrator'), 'value' => class_exists('ZipArchive') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator'), 'ok' => class_exists('ZipArchive') && PHP_INT_SIZE >= 8),
            array('label' => __('Storage access', 'uwh-site-migrator'), 'value' => $writable ? __('Writable', 'uwh-site-migrator') : __('Not writable', 'uwh-site-migrator'), 'ok' => $writable),
            array('label' => __('Free disk space', 'uwh-site-migrator'), 'value' => false === $free ? __('Host did not report', 'uwh-site-migrator') : esc_html(size_format($free, 2)), 'ok' => false === $free || $free >= 512 * 1024 * 1024),
        );
    }

    private function database_stats() {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $like), ARRAY_A);
        $size = 0;
        $count = 0;
        foreach ((array) $rows as $row) {
            $size += (int) ($row['Data_length'] ?? 0)+(int) ($row['Index_length'] ?? 0);
            $count += max(0, (int) ($row['Rows'] ?? 0));
        }
        return array('size' => $size, 'rows' => $count);
    }

    /** Create a hidden full-site safety backup before any destructive restore begins. */
    private function start_checkpoint_job($restore_id) {
        if (!$this->safe_id($restore_id)) {
            throw new RuntimeException(esc_html__('Invalid restore checkpoint request.', 'uwh-site-migrator'));
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(esc_html__('PHP ZipArchive is required to create the restore checkpoint.', 'uwh-site-migrator'));
        }
        $job = bin2hex(random_bytes(16));
        $domain = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $domain = preg_replace('/[^a-z0-9_.-]/i', '', strtolower($domain));
        $prefix = 'checkpoint-' . ($domain ?: 'site') . '-' . substr($restore_id, 0, 8);
        $name = $this->clean_backup_name($prefix, $this->checkpoint_dir);
        $zip_path = $this->checkpoint_dir . '/' . $name;
        $zip = new ZipArchive();
        if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException(esc_html__('Could not create the restore safety checkpoint.', 'uwh-site-migrator'));
        }
        $manifest = array(
            'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
            'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
            'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix,
            'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION,
            'active_plugins' => get_option('active_plugins', array()), 'checkpoint_for' => $restore_id,
        );
        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        $files = $this->collect_files();
        $tables = $this->collect_tables();
        $file_bytes = 0;
        foreach ($files as $file) {
            $file_bytes += (int) $file['size'];
        }
        $db_stats = $this->database_stats();
        $db_bytes = (int) $db_stats['size'];
        $estimated_total = $file_bytes + $db_bytes;
        $free = @disk_free_space($this->base);
        $required = (int) ($estimated_total * 2.5) + 256 * 1024 * 1024;
        if ($estimated_total > 2 * 1024 * 1024 * 1024 && PHP_INT_SIZE < 8) {
            wp_delete_file($zip_path);
            throw new RuntimeException(esc_html__('This server uses 32-bit PHP and cannot safely create the rollback checkpoint.', 'uwh-site-migrator'));
        }
        if (false !== $free && $free < $required) {
            wp_delete_file($zip_path); throw new RuntimeException(esc_html__('Not enough free disk space for the automatic rollback checkpoint. Required approximately ', 'uwh-site-migrator') . esc_html(size_format($required, 2)) . sprintf(
                /* translators: %s: available disk space. */
                esc_html__('; available %s.', 'uwh-site-migrator'),
                esc_html(size_format($free, 2))
            ));
        }
        $db_stage = $this->base . '/db-' . $job;
        if (!wp_mkdir_p($db_stage)) {
            wp_delete_file($zip_path);
            throw new RuntimeException(esc_html__('Could not create checkpoint database staging.', 'uwh-site-migrator'));
        }
        $state = array(
            'type' => 'backup', 'purpose' => 'checkpoint', 'checkpoint_for' => $restore_id, 'job' => $job, 'phase' => 'files',
            'delivery' => 'local', 'delivery_status' => 'local', 'zip' => $zip_path, 'db_stage' => $db_stage, 'name' => $name,
            'files' => $files, 'file_index' => 0, 'file_bytes' => $file_bytes, 'file_bytes_done' => 0,
            'database_bytes' => $db_bytes, 'database_rows_total' => $db_stats['rows'], 'tables' => $tables,
            'table_index' => 0, 'row_offset' => 0, 'rows_done' => 0, 'inventory' => array(), 'table_rows' => array(),
            'total_units' => max(1, count($files) + count($tables) * 3), 'done_units' => 0, 'created' => time(),
        );
        $this->write_state($job, $state);
        UWHSM_Logger::log('checkpoint', 'started', __('Restore safety checkpoint started.', 'uwh-site-migrator'), array('job' => $job, 'checkpoint' => $name, 'restore_id' => $restore_id));
        return array('job' => $job, 'name' => $name);
    }

    public function uwhsm_backup_start() {
        $this->guard();
        if (!class_exists('ZipArchive')) {
            $this->fail(__('PHP ZipArchive is not enabled.', 'uwh-site-migrator'));
        }
        $delivery = UWHSM_Compat::post_value('delivery');
        if ('' === $delivery) {
            $delivery = 'local';
        }
        if (!in_array($delivery, array('local', 'drive'), true)) {
            $this->fail(__('Invalid backup delivery choice.', 'uwh-site-migrator'));
            return;
        }
        if ('drive' === $delivery) {
            try {
                $drive = UWHSM_Drive::status();
                if (empty($drive['connected'])) {
                    throw new RuntimeException(__('Connect Google Drive before creating a Drive backup.', 'uwh-site-migrator'));
                }
                UWHSM_Drive::site_identifier();
            } catch (Throwable $e) {
                $this->fail($e->getMessage(), 400);
                return;
            }
        }
        try {
            $job = bin2hex(random_bytes(16));

            // Domain name ber kora hocche
            $domain = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
            $domain = preg_replace('/[^a-z0-9_.-]/i', '', strtolower($domain));
            $prefix = $domain ? $domain : 'site-migrator';

            $name = $this->clean_backup_name($prefix);
            $zip_path = $this->backup_dir . '/' . $name;
            $zip = new ZipArchive();
            if (true !== $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
                throw new RuntimeException(__('Could not create the backup archive. Check disk space and permissions.', 'uwh-site-migrator'));
            }
            $manifest = array(
                'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
                'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
                'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix,
                'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION, 'active_plugins' => get_option('active_plugins', array()),
            );
            $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
            $files = $this->collect_files();
            $tables = $this->collect_tables();
            $file_bytes = 0;
            foreach ($files as $file) {
                $file_bytes += (int) $file['size'];
            }
            $db_stats = $this->database_stats();
            $db_bytes = $db_stats['size'];
            $estimated_total = $file_bytes+$db_bytes;
            $free = @disk_free_space($this->base);
            if ($estimated_total > 2*1024*1024*1024 && PHP_INT_SIZE < 8) {
                throw new RuntimeException(__('This server uses 32-bit PHP and cannot reliably create backups larger than 2 GB. Ask the host to enable 64-bit PHP.', 'uwh-site-migrator'));
            }
            $required = (int) ($estimated_total*2.5)+256*1024*1024;
            if (false !== $free && $free < $required) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: required disk space, 2: available disk space. */
                    __('Not enough free disk space. Estimated requirement: %1$s; available: %2$s.', 'uwh-site-migrator'),
                    size_format($required, 2),
                    esc_html(size_format($free, 2))
                ));
            }
            $db_stage = $this->base . '/db-' . $job;
            wp_mkdir_p($db_stage);
            $state = array('type' => 'backup', 'job' => $job, 'phase' => 'files', 'delivery' => $delivery, 'delivery_status' => 'local' === $delivery?'local':'pending', 'zip' => $zip_path, 'db_stage' => $db_stage, 'name' => $name, 'files' => $files, 'file_index' => 0, 'file_bytes' => $file_bytes, 'file_bytes_done' => 0, 'database_bytes' => $db_bytes, 'database_rows_total' => $db_stats['rows'], 'tables' => $tables, 'table_index' => 0, 'row_offset' => 0, 'rows_done' => 0, 'inventory' => array(), 'table_rows' => array(), 'total_units' => max(1, count($files)+count($tables)*3), 'done_units' => 0, 'created' => time());
            $this->write_state($job, $state);
            UWHSM_Logger::log('backup', 'started', __('Backup started.', 'uwh-site-migrator'), array('job' => $job, 'backup' => $name, 'delivery' => $delivery));
        } catch (Throwable $e) {
            if (!empty($zip_path)) {
                wp_delete_file($zip_path);
            }
            UWHSM_Logger::log('backup', 'failed', __('Backup could not start.', 'uwh-site-migrator'), array('job' => (string) ($job ?? ''), 'backup' => (string) ($name ?? ''), 'delivery' => $delivery, 'error' => $e->getMessage()));
            $this->fail($e->getMessage(), 500);
        }
        wp_send_json_success(array('job' => $job, 'done' => false, 'progress' => 1, 'label' => __('Scanning website files', 'uwh-site-migrator'), 'delivery' => $delivery));
    }

    private function collect_files() {
        // Archive-relative site/wp-content/ keys are logical; actual roots are supplied
        // by WordPress, including configurations with moved content and media.
        return UWHSM_Paths::files($this->base, function($relative) { return $this->is_ephemeral_file($relative); });
    }

    private function is_ephemeral_file($relative) {
        $relative = wp_normalize_path((string) $relative);
        $name = basename($relative);
        if (preg_match('/^\.wp-object-cache-[a-z0-9_-]+\.dat$/i', $name)) {
            return true;
        }
        if (in_array(strtolower($name), array('error_log', 'debug.log', '.ds_store', 'thumbs.db'), true)) {
            return true;
        }
        // Never exclude code directories merely because their name is Cache, tmp or temp.
        return false;
    }

    private function collect_tables() {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($wpdb->last_error || !$tables) {
            throw new RuntimeException(esc_html__('Could not read database tables.', 'uwh-site-migrator'));
        }
        return array_values(array_filter(array_map('strval', (array) $tables)));
    }

    public function uwhsm_backup_step() {
        $this->guard();
        $job = UWHSM_Compat::post_value('job');
        try {
            $result = $this->run_backup_batch($job);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 500);
            return;
        }
        wp_send_json_success($result);
    }
    /** Authenticated, read-only progress endpoint; state lives on the server, not the browser. */
    public function uwhsm_backup_status() {
        $this->guard();
        $job = UWHSM_Compat::post_value('job');
        if ('' !== $job) {
            if (!$this->safe_id($job)) {
                $this->fail(__('Invalid backup job ID.', 'uwh-site-migrator'), 400);
                return;
            }
            $state = $this->background_state($job);
            wp_send_json_success(array('found' => (bool) $state, 'job' => $state ? $this->safe_backup_status($state) : null));
            return;
        }
        $rows = array();
        foreach ($this->background_jobs() as $item) {
            $state = $this->background_state($item['job']);
            if ($state) {
                $rows[] = $this->safe_backup_status($state);
            }
        }
        usort($rows, static function($a, $b) {
            return $b['created'] <=> $a['created'];
        });
        wp_send_json_success(array('jobs' => array_slice($rows, 0, 5)));
    }

    private function safe_backup_status(array $s) {
        $b = isset($s['background']) && is_array($s['background']) ? $s['background'] : array();
        $metrics = $this->backup_metrics($s);
        $scheduled = !empty($b['enabled']) && (bool) wp_next_scheduled(UWHSM_Background::HOOK, array($s['job']));
        return array(
            'job' => (string) $s['job'],
            'name' => (string) ($s['name'] ?? ''),
            'phase' => (string) ($s['phase'] ?? ''),
            'created' => (int) ($s['created'] ?? 0),
            'done' => 'done' === ($s['phase'] ?? ''),
            'progress' => (float) $metrics['progress'],
            'label' => (string) $metrics['label'],
            'background_enabled' => !empty($b['enabled']),
            'scheduled' => $scheduled,
            'last_tick' => (int) ($b['last_tick'] ?? 0),
            'error' => (string) ($b['error'] ?? ''),
            'delivery' => (string) ($s['delivery'] ?? 'local'),
        );
    }

    // Shared by authenticated AJAX and the locally scheduled background worker.
    public function run_backup_batch($job) {
        if (!$this->safe_id($job) || !($s = $this->read_state($job)) || 'backup' !== ($s['type'] ?? '')) {
            throw new RuntimeException(esc_html__('Backup job was not found or expired.', 'uwh-site-migrator'));
        }
        @ignore_user_abort(true);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
        @set_time_limit(0);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen($this->lock_path($job), 'c');
        if (!$lock) {
            throw new RuntimeException(esc_html__('Cannot create backup lock. Check storage permissions.', 'uwh-site-migrator'));
        }
        if (!@flock($lock, LOCK_EX|LOCK_NB)) {
            $metrics = $this->backup_metrics($s);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
            return array('job' => $job, 'done' => false, 'progress' => $metrics['progress'], 'label' => __('Previous backup batch is still finishing…', 'uwh-site-migrator'), 'warnings' => count($s['warnings'] ?? array()), 'busy' => true);
        }
        try {
            $s = $this->read_state($job);
            if (!$s) {
                throw new RuntimeException(__('Backup job was removed.', 'uwh-site-migrator'));
            }
            if (is_file($this->cancel_path($job))) {
                throw new RuntimeException(__('Deletion requested. Retry Delete temporary to finish cleanup.', 'uwh-site-migrator'));
            }
            if ('files' === $s['phase']) {
                $this->backup_files($s);
            } elseif ('database' === $s['phase']) {
                $this->backup_database($s);
            } elseif ('finalize' === $s['phase']) {
                $this->backup_finalize($s);
            } elseif ('done' === $s['phase']) {
                /* Idempotent completion after a lost response. */
            } else {
                throw new RuntimeException(__('Invalid backup state.', 'uwh-site-migrator'));
            }
            if (is_file($this->cancel_path($job))) {
                if (!empty($s['zip'])) {
                    wp_delete_file($s['zip']);
                }
                if (!empty($s['db_stage'])) {
                    $this->remove_tree($s['db_stage']);
                }
                wp_delete_file($s['zip'] . '.json');
                wp_delete_file($this->state_path($job));
                wp_delete_file($this->cancel_path($job));
                throw new RuntimeException(__('Backup was cancelled and its temporary files were removed.', 'uwh-site-migrator'));
            }
            $this->write_state($job, $s);
            if ('done' === ($s['phase'] ?? '')) {
                $this->ensure_drive_delivery($s);
                $this->write_state($job, $s);
            }
        } catch (Throwable $e) {
            UWHSM_Logger::log_once('backup-failed-' . $job . '-' . md5($e->getMessage()), ($s['purpose'] ?? 'backup') === 'checkpoint'?'checkpoint':'backup', 'failed', ($s['purpose'] ?? 'backup') === 'checkpoint' ? __('Safety checkpoint failed.', 'uwh-site-migrator') : __('Backup failed.', 'uwh-site-migrator'), array('job' => $job, 'backup' => (string) ($s['name'] ?? ''), 'checkpoint' => (string) ($s['name'] ?? ''), 'phase' => (string) ($s['phase'] ?? ''), 'error' => $e->getMessage()), 3600);
            if ($lock) {
                @flock($lock, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                @fclose($lock);
            }
            throw $e;
        }
        if ($lock) {
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
        }
        $metrics = $this->backup_metrics($s);
        $done = 'done' === $s['phase'];
        if ($done && !empty($s['db_stage'])) {
            try {
                $this->remove_tree($s['db_stage']);
            } catch (Throwable $e) {
                $s['warnings'][] = __('Temporary cleanup is pending.', 'uwh-site-migrator');
            }
        }
        // Keep the small completion receipt so a lost final response can be retried.
        if ($done && ($s['purpose'] ?? 'backup') === 'checkpoint') {
            $this->prune_checkpoints(3, array((string) ($s['name'] ?? '')));
        }
        if ($done) {
            $category = (($s['purpose'] ?? 'backup') === 'checkpoint')?'checkpoint':'backup';
            UWHSM_Logger::log_once($category . '-complete-' . $job, $category, 'success', $category === 'checkpoint' ? __('Safety checkpoint completed.', 'uwh-site-migrator') : __('Backup completed.', 'uwh-site-migrator'), array('job' => $job, 'backup' => (string) ($s['name'] ?? ''), 'checkpoint' => (string) ($s['name'] ?? ''), 'delivery' => (string) ($s['delivery'] ?? 'local'), 'size' => is_file((string) ($s['zip'] ?? ''))?(int) @filesize($s['zip']):0), 7*DAY_IN_SECONDS);
        }
        return array('job' => $job, 'name' => (string) ($s['name'] ?? ''), 'purpose' => (string) ($s['purpose'] ?? 'backup'), 'done' => $done, 'progress' => $metrics['progress'], 'label' => (($s['purpose'] ?? 'backup') === 'checkpoint' && !$done ? __('Creating restore safety checkpoint · ', 'uwh-site-migrator') : '') . $metrics['label'], 'warnings' => count($s['warnings'] ?? array()), 'delivery_status' => (string) ($s['delivery_status'] ?? 'local'), 'delivery_warning' => (string) ($s['delivery_warning'] ?? ''), 'drive_transfer_id' => (string) ($s['drive_transfer_id'] ?? ''));
    }

    private function ensure_drive_delivery(array &$state) {
        if (($state['phase'] ?? '') !== 'done' || ($state['purpose'] ?? 'backup') === 'checkpoint' || ($state['delivery'] ?? 'local') !== 'drive' || !empty($state['drive_transfer_id']) || !empty($state['delivery_attempted'])) {
            return;
        }
        $state['delivery_attempted'] = true;
        try {
            $queued = UWHSM_Drive_Transfer::queue_backup($state['name'], !empty($state['is_auto']));
            if (empty($queued['transfer_id'])) {
                throw new RuntimeException(__('Drive transfer queue did not return an ID.', 'uwh-site-migrator'));
            }
            $state['drive_transfer_id'] = $queued['transfer_id'];
            $state['delivery_status'] = (string) ($queued['status'] ?? 'queued');
            $state['delivery_warning'] = '';
        } catch (Throwable $error) {
            $state['delivery_status'] = 'failed';
            $state['delivery_warning'] = __('Backup completed locally, but Drive delivery could not be queued: ', 'uwh-site-migrator') . substr($error->getMessage(), 0, 220);
            if (!isset($state['warnings']) || !is_array($state['warnings'])) {
                $state['warnings'] = array();
            }
            $state['warnings'][] = $state['delivery_warning'];
        }
    }

    public function background_state($job) {
        if (!$this->safe_id($job)) {
            return false;
        }
        $s = $this->read_state($job);
        return $s && 'backup' === ($s['type'] ?? '') ? $s : false;
    }

    public function background_update($job, array $background, $expected = null) {
        if (!$this->safe_id($job)) {
            throw new RuntimeException(esc_html__('Invalid backup job.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen($this->lock_path($job), 'c');
        if (!$lock) {
            throw new RuntimeException(esc_html__('Cannot lock backup settings.', 'uwh-site-migrator'));
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            throw new RuntimeException(esc_html__('Backup batch is running. Try again after it finishes.', 'uwh-site-migrator'));
        }
        try {
            $s = $this->background_state($job);
            if (!$s || is_file($this->cancel_path($job))) {
                throw new RuntimeException(__('Backup is missing or deletion was requested.', 'uwh-site-migrator'));
            }
            if (null !== $expected && ($s['background'] ?? array()) !== $expected) {
                return false;
            }
            $s['background'] = $background;
            $this->write_state($job, $s);
            return true;
        } finally {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
        }
    }

    public function background_jobs() {
        $jobs = array();
        foreach ((array) glob($this->base . '/job-*.json') as $path) {
            $s = json_decode((string) @file_get_contents($path), true);
            // 100% complete ('done' phase) hoye jawa job gulo hide kora hocche
            if (is_array($s) && 'backup' === ($s['type'] ?? '') && !empty($s['background']) && ($s['phase'] ?? '') !== 'done') {
                $metrics = $this->backup_metrics($s);
                $jobs[] = array('job' => $s['job'], 'name' => $s['name'], 'phase' => $s['phase'], 'background' => $s['background'], 'label' => $metrics['label'], 'progress' => $metrics['progress']);
            }
        }
        return $jobs;
    }

    private function backup_metrics(array $s) {
        $phase = $s['phase'] ?? 'files';
        if ('done' === $phase) {
            return array('progress' => 100, 'label' => __('Backup complete', 'uwh-site-migrator'));
        }
        if ('finalize' === $phase) {
            return array('progress' => 99, 'label' => __('Finalizing ZIP and checking package structure', 'uwh-site-migrator'));
        }
        if ('database' === $phase) {
            $tables = max(1, count($s['tables'] ?? array()));
            $table_index = (int) ($s['table_index'] ?? 0);
            $total_rows = (int) ($s['database_rows_total'] ?? 0);
            $rows = (int) ($s['rows_done'] ?? 0);
            $ratio = $total_rows > 0?min(1, $rows/$total_rows):min(1, $table_index/$tables);
            $label = sprintf(
                /* translators: 1: current table number, 2: total tables, 3: exported row count. */
                __('Exporting database · table %1$d/%2$d · %3$s rows', 'uwh-site-migrator'),
                min($tables, $table_index+1),
                $tables,
                number_format_i18n($rows)
            );
            return array('progress' => min(98, 75+$ratio*23), 'label' => $label);
        }
        $total = (int) ($s['file_bytes'] ?? 0);
        $done = (int) ($s['file_bytes_done'] ?? 0);
        $ratio = $total > 0?min(1, $done/$total):0;
        return array('progress' => max(1, $ratio*75), 'label' => sprintf(
            /* translators: 1: archived size, 2: total website size. */
            __('Archiving website files · %1$s / %2$s', 'uwh-site-migrator'),
            size_format($done, 2),
            size_format(max(1, $total), 2)
        ));
    }

    private function open_zip($path) {
        $zip = new ZipArchive();
        $result = $zip->open($path);
        if (true !== $result) {
            throw new RuntimeException(esc_html__('Could not open archive (Zip error ', 'uwh-site-migrator') . (int) $result . ').');
        }
        return $zip;
    }

    private function backup_files(array &$s) {
        $zip = $this->open_zip($s['zip']);
        $started = microtime(true);
        $limit = 128*1024*1024;
        $batch_bytes = 0;
        $handled = 0;
        $count = count($s['files']);
        for ($i = $s['file_index']; $i < $count && $handled < $this->batch_files; $i++) {
            $f = $s['files'][$i];
            if ($handled > 0 && ($batch_bytes+(int) $f['size'] > $limit || microtime(true)-$started > 7)) {
                break;
            }
            if ($this->is_ephemeral_file($f['rel'])) {
                if (!isset($s['warnings'])) {
                    $s['warnings'] = array();
                }
                $s['warnings'][] = __('Skipped temporary runtime file: ', 'uwh-site-migrator') . $f['rel'];
                $handled++;
                $s['file_bytes_done'] += (int) $f['size'];
                $s['done_units']++;
                $s['file_index'] = $i+1;
                continue;
            }
            if (!is_readable($f['path'])) {
                $zip->close();
                throw new RuntimeException(esc_html__('File is not readable: ', 'uwh-site-migrator') . esc_html($f['rel']));
            }
            if (!$zip->addFile($f['path'], 'site/' . $f['rel'])) {
                $zip->close();
                throw new RuntimeException(esc_html__('Could not add file: ', 'uwh-site-migrator') . esc_html($f['rel']));
            }
            $digest = hash_file('sha256', $f['path']);
            if (false === $digest) {
                throw new RuntimeException(esc_html__('Could not checksum ', 'uwh-site-migrator') . esc_html($f['rel']));
            }
            $s['inventory']['site/' . $f['rel']] = array('size' => (int) filesize($f['path']), 'sha256' => $digest);
            $ext = strtolower(pathinfo($f['rel'], PATHINFO_EXTENSION));
            if (method_exists($zip, 'setCompressionName')) {
                if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'avi', 'mkv', 'mp3', 'wav', 'zip', 'gz', 'rar', '7z', 'pdf', 'woff', 'woff2'), true)) {
                    @$zip->setCompressionName('site/' . $f['rel'], ZipArchive::CM_STORE);
                } else {
                    // Faster compression for text/code without changing the .uwh ZIP format.
                    @$zip->setCompressionName('site/' . $f['rel'], ZipArchive::CM_DEFLATE, 1);
                }
            }
            $batch_bytes += (int) $f['size'];
            $handled++;
            $s['file_bytes_done'] += (int) $f['size'];
            $s['done_units']++;
            $s['file_index'] = $i+1;
        }
        if (!$zip->close()) {
            throw new RuntimeException(esc_html__('Could not finish writing ZIP. Check free disk space and hosting limits.', 'uwh-site-migrator'));
        }
        if ($s['file_index'] >= count($s['files'])) {
            unset($s['files']);
            $s['phase'] = 'database';
        }
    }

    /**
     * Only restore table engines with a well-defined local CREATE TABLE format.
     * The original engine is preserved in the archive; a transactional InnoDB
     * table is used while importing rows so a lost request can safely resume.
     */
    private static function supported_table_engine($schema) {
        if (!is_string($schema) || !preg_match('/\bENGINE\s*=\s*(InnoDB|MyISAM|Aria|MEMORY)\b/i', $schema, $matches)) {
            return '';
        }
        foreach (array('InnoDB', 'MyISAM', 'Aria', 'MEMORY') as $engine) {
            if (0 === strcasecmp($matches[1], $engine)) {
                return $engine;
            }
        }
        return '';
    }

    /** Convert only the CREATE TABLE used for importing, never the source or archive. */
    private static function transactional_restore_schema($schema) {
        $engine = self::supported_table_engine($schema);
        if ('' === $engine) {
            throw new RuntimeException(esc_html__('Unsupported database table storage engine.', 'uwh-site-migrator'));
        }
        if ('InnoDB' !== $engine) {
            $schema = preg_replace('/\bENGINE\s*=\s*' . preg_quote($engine, '/') . '\b/i', 'ENGINE=InnoDB', $schema, 1);
            if ('MEMORY' === $engine) {
                // MEMORY supports HASH indexes; InnoDB's retry-safe import
                // needs BTREE indexes. The archived schema stays untouched.
                $schema = preg_replace('/\bUSING\s+HASH\b/i', 'USING BTREE', $schema);
            }
        }
        return $schema;
    }

    private function backup_database(array &$s) {
        global $wpdb;
        if ($s['table_index'] >= count($s['tables'])) {
            $s['phase'] = 'finalize';
            return;
        }
        $table = $s['tables'][$s['table_index']];
        $schema_stage = $s['db_stage'] . '/' . rawurlencode($table) . '.schema';
        $rows_stage = $s['db_stage'] . '/' . rawurlencode($table) . '.rows';
        if (0 === (int) $s['row_offset']) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $create = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
            if (!$create || empty($create[1])) {
                throw new RuntimeException(esc_html__('Could not read schema for table ', 'uwh-site-migrator') . esc_html($table));
            }
            $storage_engine = self::supported_table_engine($create[1]);
            if ('' === $storage_engine) {
                throw new RuntimeException(esc_html__('Unsupported database table storage engine: ', 'uwh-site-migrator') . esc_html($table));
            }
            if ('InnoDB' !== $storage_engine) {
                // Non-transactional tables can change between resumable read batches.
                // Keep their original schema and rows in the .uwh archive.
                $s['warnings'][] = sprintf(
                    /* translators: 1: database table name, 2: storage engine name. */
                    __('Table %1$s uses %2$s. Avoid changes to this table while the backup runs to ensure consistent data.', 'uwh-site-migrator'),
                    $table,
                    $storage_engine
                );
            }
            if (false === file_put_contents($schema_stage, $create[1], LOCK_EX)) {
                throw new RuntimeException(esc_html__('Could not stage database schema.', 'uwh-site-migrator'));
            }
            wp_delete_file($rows_stage);
            $s['rows_bytes'] = 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $keys = $wpdb->get_results($wpdb->prepare('SHOW KEYS FROM %i WHERE Key_name=%s ORDER BY Seq_in_index', $table, 'PRIMARY'), ARRAY_A);
            $s['table_pk'] = count((array) $keys) === 1?(string) ($keys[0]['Column_name'] ?? ''):'';
            unset($s['last_pk_b64']);
        }
        if (!empty($s['table_pk'])) {
            $column = (string) $s['table_pk'];
            if (!empty($s['last_pk_b64'])) {
                $last_raw = base64_decode((string) $s['last_pk_b64'], true);
                $last = false === $last_raw?null:@unserialize($last_raw, array('allowed_classes' => false));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $column, (string) $last, $column, $this->batch_rows), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $column, $this->batch_rows), ARRAY_A);
            }
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i LIMIT %d OFFSET %d', $table, $this->batch_rows, (int) $s['row_offset']), ARRAY_A);
        }
        if ($wpdb->last_error) {
            throw new RuntimeException(esc_html__('Database export failed: ', 'uwh-site-migrator') . esc_html($wpdb->last_error));
        }
        if ($rows) {
            $payload = '';
            foreach ($rows as $row) {
                $payload .= base64_encode(serialize($row)) . "\n";
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $fh = fopen($rows_stage, 'c+b');
            if (!$fh) {
                throw new RuntimeException(esc_html__('Could not open staged database rows.', 'uwh-site-migrator'));
            }
            $offset = isset($s['rows_bytes'])?(int) $s['rows_bytes']:(int) filesize($rows_stage);
            if (!ftruncate($fh, $offset) || fseek($fh, $offset) !== 0) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($fh);
                throw new RuntimeException(esc_html__('Could not checkpoint database rows.', 'uwh-site-migrator'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native streaming is required to write large backup archives/chunks without loading them fully into memory.
            $written = fwrite($fh, $payload);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($fh);
            if ($written !== strlen($payload)) {
                throw new RuntimeException(esc_html__('Could not stage database rows. Check disk space.', 'uwh-site-migrator'));
            }
            $s['rows_bytes'] = $offset+$written;
            $s['row_offset'] += count($rows);
            $s['rows_done'] += count($rows);
            if (!empty($s['table_pk'])) {
                $last_row = end($rows);
                $s['last_pk_b64'] = base64_encode(serialize($last_row[$s['table_pk']]));
            }
        }
        if (count($rows) < $this->batch_rows) {
            // Keep table exports staged; add all tables in one ZIP update during finalization.
            $s['table_rows'][$table] = (int) $s['row_offset'];
            $s['table_index']++;
            $s['row_offset'] = 0;
            $s['done_units'] += 3;
            unset($s['table_pk'], $s['last_pk_b64'], $s['rows_bytes']);
        }
    }

    private function backup_finalize(array &$s) {
        $zip = $this->open_zip($s['zip']);
        foreach ((array) glob($s['db_stage'] . '/*') as $file) {
            if (!is_file($file) || !preg_match('/\.(schema|rows)\z/', $file)) {
                continue;
            }
            $entry = 'database/' . basename($file);
            if (!$zip->addFile($file, $entry)) {
                throw new RuntimeException(esc_html__('Could not add staged database export.', 'uwh-site-migrator'));
            }
            $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, 1);
        }
        foreach ((array) glob($s['db_stage'] . '/*') as $file) {
            if (is_file($file)) {
                $s['inventory']['database/' . basename($file)] = array('size' => filesize($file), 'sha256' => hash_file('sha256', $file));
            }
        }
        if (empty($s['inventory'])) {
            throw new RuntimeException(esc_html__('This interrupted backup predates integrity checks. Create a new backup.', 'uwh-site-migrator'));
        }
        $zip->addFromString('integrity.json', wp_json_encode(array('version' => 1, 'files' => $s['inventory'], 'table_rows' => $s['table_rows'])));
        if (!$zip->close()) {
            throw new RuntimeException(esc_html__('ZIP finalization failed. Check free disk space.', 'uwh-site-migrator'));
        }
        $zip = $this->open_zip($s['zip']);
        $valid = $zip->locateName('manifest.json') !== false;
        foreach ($s['tables'] as $table) {
            if ($zip->locateName('database/' . rawurlencode($table) . '.schema') === false) {
                $valid = false;
                break;
            }
        }
        $zip->close();
        clearstatcache(true, $s['zip']);
        if (!$valid || !is_file($s['zip']) || filesize($s['zip']) < 100) {
            throw new RuntimeException(esc_html__('Backup structure verification failed.', 'uwh-site-migrator'));
        }
        $completed_size = (int) filesize($s['zip']);
        $completed_mtime = (int) filemtime($s['zip']);
        if (false === file_put_contents($s['zip'] . '.json', wp_json_encode(array('created' => time(), 'size' => $completed_size, 'mtime' => $completed_mtime, 'rows' => (int) $s['rows_done'], 'warnings' => $s['warnings'] ?? array(), 'automatic' => !empty($s['is_auto']), 'purpose' => (string) ($s['purpose'] ?? 'backup'), 'checkpoint_for' => (string) ($s['checkpoint_for'] ?? ''))), LOCK_EX)) {
            throw new RuntimeException(esc_html__('Could not save backup completion marker. Check storage permissions.', 'uwh-site-migrator'));
        }
        // Cleanup is best-effort after a durable completion marker; deletion can retry it.
        // The caller removes staging only after the completed state has been saved.
        $s['done_units'] = $s['total_units'];
        $s['phase'] = 'done';
    }

    public function uwhsm_upload_start() {
        $this->guard();
        $name = sanitize_file_name(UWHSM_Compat::post_value('name'));
        if (preg_match('/^(?:site-vault-migrator|site-migrator)(?:-v|\.)/i', $name)) {
            $this->fail(__('This is the plugin installer ZIP. Select your website backup named site-vault-…svm.zip.', 'uwh-site-migrator'));
        }
        $size_raw = UWHSM_Compat::post_value('size');
        $size = '' !== $size_raw ? absint($size_raw) : 0;
        if (!$name || !preg_match('/\.uwh$/i', $name) || $size < 100 || $size > 50*1024*1024*1024) {
            $this->fail(__('Choose a valid backup ZIP package (maximum 50 GB).', 'uwh-site-migrator'));
        }
        if ($size > 2*1024*1024*1024 && PHP_INT_SIZE < 8) {
            $this->fail(__('This server uses 32-bit PHP and cannot restore a package larger than 2 GB.', 'uwh-site-migrator'));
        }
        $free = @disk_free_space($this->import_dir);
        $required = (int) ($size*2.5)+256*1024*1024;
        if (false !== $free && $free < $required) {
            $this->fail(sprintf(
                /* translators: 1: required disk space, 2: available disk space. */
                __('Not enough destination disk space. Required approximately %1$s; available %2$s.', 'uwh-site-migrator'),
                size_format($required, 2),
                esc_html(size_format($free, 2))
            ));
        }
        $id = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $dir = $this->import_dir . '/' . $id;
        if (!wp_mkdir_p($dir)) {
            $this->fail(__('Could not create the import directory.', 'uwh-site-migrator'));
        }
        $chunk_size = 8 * 1024 * 1024;
        foreach (array('upload_max_filesize' => 65536, 'post_max_size' => 1048576) as $setting => $reserve) {
            $limit = wp_convert_hr_to_bytes(ini_get($setting));
            if ($limit > 0) {
                $chunk_size = min($chunk_size, max(65536, $limit - $reserve));
            }
        }
        $state = array('chunk_size' => $chunk_size, 'type' => 'upload', 'upload_id' => $id, 'token_hash' => hash('sha256', $token), 'expected_size' => $size, 'received' => 0, 'next_index' => 0, 'archive' => $dir . '/package.svm.zip', 'created' => time());
        try {
            $this->write_state($id, $state);
        } catch (Throwable $e) {
            $this->fail($e->getMessage());
        }
        wp_send_json_success(array('upload_id' => $id, 'token' => $token, 'chunk_size' => $chunk_size));
    }

    public function uwhsm_upload_chunk() {
        $this->guard();
        $id = UWHSM_Compat::post_value('upload_id');
        $index_raw = UWHSM_Compat::post_value('index');
        if ('' === $index_raw) {
            $index_raw = '-1';
        }
        $index     = is_scalar($index_raw) ? (int) $index_raw : -1;
        if (!$this->safe_id($id)) {
            $this->fail(__('Invalid upload session.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = fopen($this->lock_path($id), 'c');
        if (!$lock) {
            $this->fail(__('Cannot lock upload.', 'uwh-site-migrator'), 500);
        }
        if (!flock($lock, LOCK_EX|LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $this->fail(__('Upload batch busy. Retrying.', 'uwh-site-migrator'), 503);
        }
        try {
            $s = $this->read_state($id);
            if (!$s || $s['type'] !== 'upload') {
                throw new RuntimeException(__('Upload session not found.', 'uwh-site-migrator'));
            }
            if ($index === (int) $s['next_index']-1) {
                $result = array('received' => $s['received'], 'duplicate' => true);
            } else {
                if ($index !== (int) $s['next_index']) {
                    throw new RuntimeException(__('Upload chunk is out of order.', 'uwh-site-migrator'));
                }
                $tmp_name = UWHSM_Compat::uploaded_tmp_name('chunk');
                if ('' === $tmp_name || !is_uploaded_file($tmp_name)) {
                    throw new RuntimeException(__('Upload chunk is missing.', 'uwh-site-migrator'));
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP's validated upload temp file is local and read once before integrity checks.
                $bytes = file_get_contents($tmp_name);
                if (false === $bytes) {
                    throw new RuntimeException(__('The server could not read the uploaded chunk.', 'uwh-site-migrator'));
                }
                $length = strlen($bytes);
                if ($length === 0) {
                    throw new RuntimeException(__('The uploaded chunk is empty. Check PHP upload limits.', 'uwh-site-migrator'));
                }
                if ($length > (int) ($s['chunk_size'] ?? 4*1024*1024)) {
                    throw new RuntimeException(__('Chunk exceeds this upload session limit. Refresh Site Migrator and restart upload.', 'uwh-site-migrator'));
                }
                if ((int) $s['received']+$length > (int) $s['expected_size']) {
                    throw new RuntimeException(__('Chunk exceeds the remaining backup size. Select the original backup and restart this upload.', 'uwh-site-migrator'));
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                $fh = fopen($s['archive'], 'c+b');
                if (!$fh) {
                    throw new RuntimeException(__('Cannot write upload.', 'uwh-site-migrator'));
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native streaming is required to write large backup archives/chunks without loading them fully into memory.
                $ok = ftruncate($fh, (int) $s['received']) && fseek($fh, (int) $s['received']) === 0 && fwrite($fh, $bytes) === strlen($bytes);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($fh);
                if (!$ok) {
                    throw new RuntimeException(__('Could not store upload chunk.', 'uwh-site-migrator'));
                }
                $s['received'] += strlen($bytes);
                $s['next_index']++;
                $this->write_state($id, $s);
                $result = array('received' => $s['received']);
            }
        } catch (Throwable $e) {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $this->fail($e->getMessage(), 500);
        }
        flock($lock, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        fclose($lock);
        wp_send_json_success($result);
    }

    private function assert_restore_slot($except_id = '') {
        foreach ((array) glob($this->base . '/job-*.json') as $jobfile) {
            $other = json_decode((string) @file_get_contents($jobfile), true);
            if (!is_array($other)) {
                continue;
            }
            $type = (string) ($other['type'] ?? '');
            $phase = (string) ($other['phase'] ?? '');
            $other_id = (string) ($other['restore_id'] ?? '');
            if (in_array($type, array('restore', 'restore_pending'), true) && !in_array($phase, array('done', 'failed'), true) && $other_id !== $except_id) {
                throw new RuntimeException(esc_html__('Another restore is unfinished. Resume it before starting another.', 'uwh-site-migrator'));
            }
        }
    }

    private function begin_restore_with_checkpoint($id, $token, $archive, $source_mode, $archive_name, $archive_cleanup) {
        $this->assert_restore_slot($id);
        $state = $this->prepare_restore_state($id, $token, $archive, $source_mode, $archive_name);
        $checkpoint = $this->start_checkpoint_job($id);
        $state['type'] = 'restore_pending';
        $state['restore_phase'] = 'extract';
        $state['phase'] = 'checkpoint';
        $state['archive_cleanup'] = (bool) $archive_cleanup;
        $state['checkpoint_job'] = $checkpoint['job'];
        $state['checkpoint_name_pending'] = $checkpoint['name'];
        $this->write_state($id, $state);
        UWHSM_Logger::log('restore', 'started', __('Restore prepared and safety checkpoint requested.', 'uwh-site-migrator'), array('restore_id' => $id, 'backup' => $archive_name, 'phase' => 'checkpoint', 'message' => __('Source: ', 'uwh-site-migrator') . $source_mode));
        return array(
            'restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 0,
            'label' => __('Creating automatic rollback checkpoint', 'uwh-site-migrator'), 'checkpoint_required' => true,
            'checkpoint_job' => $checkpoint['job'], 'checkpoint_name' => $checkpoint['name'],
        );
    }

    public function begin_external_restore($id, $archive, $source_mode, $archive_name, $archive_cleanup = true) {
        if (!$this->safe_id($id)) {
            throw new RuntimeException(esc_html__('Invalid direct migration restore ID.', 'uwh-site-migrator'));
        }
        $token = bin2hex(random_bytes(32));
        return $this->begin_restore_with_checkpoint($id, $token, $archive, $source_mode, $archive_name, $archive_cleanup);
    }

    public function restore_session_state($id, $token) {
        if (!$this->safe_id($id) || !($state = $this->read_state($id)) || !in_array(($state['type'] ?? ''), array('restore', 'restore_pending'), true) || !hash_equals((string) ($state['token_hash'] ?? ''), hash('sha256', (string) $token))) {
            return false;
        }
        if (($state['type'] ?? '') === 'restore_pending') {
            return array('restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 0, 'label' => __('Creating automatic rollback checkpoint', 'uwh-site-migrator'), 'checkpoint_required' => true, 'checkpoint_job' => $state['checkpoint_job'], 'checkpoint_name' => $state['checkpoint_name_pending'] ?? '');
        }
        return array('restore_id' => $id, 'token' => $token, 'done' => ($state['phase'] ?? '') === 'done', 'progress' => ($state['phase'] ?? '') === 'done'?100:1, 'label' => !empty($state['is_rollback'])?__('Resuming rollback', 'uwh-site-migrator'):__('Resuming existing restore', 'uwh-site-migrator'), 'automatic_rollback' => !empty($state['is_rollback']));
    }

    public function uwhsm_restore_start() {
        $this->guard();
        $id = UWHSM_Compat::post_value('upload_id');
        $token = UWHSM_Compat::post_value('token');
        $existing = $this->safe_id($id)?$this->read_state($id):false;
        if ($existing && in_array(($existing['type'] ?? ''), array('restore', 'restore_pending'), true) && hash_equals((string) $existing['token_hash'], hash('sha256', $token))) {
            if (($existing['type'] ?? '') === 'restore_pending') {
                wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 0, 'label' => __('Creating automatic rollback checkpoint', 'uwh-site-migrator'), 'checkpoint_required' => true, 'checkpoint_job' => $existing['checkpoint_job'], 'checkpoint_name' => $existing['checkpoint_name_pending'] ?? ''));
            }
            wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => ($existing['phase'] === 'done'), 'progress' => 1, 'label' => __('Resuming existing restore', 'uwh-site-migrator')));
        }
        if (!$this->safe_id($id) || !($s = $this->read_state($id)) || 'upload' !== ($s['type'] ?? '') || !hash_equals((string) $s['token_hash'], hash('sha256', $token))) {
            $this->fail(__('Invalid restore session.', 'uwh-site-migrator'));
            return;
        }
        if ((int) $s['received'] !== (int) $s['expected_size'] || !is_file($s['archive']) || filesize($s['archive']) !== (int) $s['expected_size']) {
            $this->fail(__('Upload is incomplete.', 'uwh-site-migrator'));
            return;
        }
        try {
            $result = $this->begin_restore_with_checkpoint($id, $token, $s['archive'], 'uploaded', basename($s['archive']), true);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 500);
            return;
        }
        wp_send_json_success($result);
    }

    public function uwhsm_restore_local_start() {
        $this->guard();
        $backup_name_raw = sanitize_file_name(UWHSM_Compat::post_value('backup_name'));
        $name = $this->normalize_backup_name($backup_name_raw);
        $id = '';
        $staging = '';
        try {
            $record = $this->completed_backup($name);
            $this->assert_restore_slot();
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $staging = $this->import_dir . '/' . $id;
            if (!wp_mkdir_p($staging)) {
                throw new RuntimeException(__('Could not create the restore staging directory.', 'uwh-site-migrator'));
            }
            $result = $this->begin_restore_with_checkpoint($id, $token, $record['path'], 'local', $record['name'], false);
        } catch (Throwable $e) {
            if ($staging && is_dir($staging)) {
                $this->remove_tree($staging);
            }
            $this->fail($e->getMessage(), 500);
            return;
        }
        wp_send_json_success($result);
    }

    public function uwhsm_restore_checkpoint_finish() {
        $this->guard();
        $id = UWHSM_Compat::post_value('restore_id');
        $token = UWHSM_Compat::post_value('token');
        if (!$this->safe_id($id) || !($state = $this->read_state($id)) || ($state['type'] ?? '') !== 'restore_pending' || !hash_equals((string) ($state['token_hash'] ?? ''), hash('sha256', $token))) {
            $this->fail(__('Restore checkpoint authorization is invalid.', 'uwh-site-migrator'), 403);
            return;
        }
        try {
            $checkpoint_job = (string) ($state['checkpoint_job'] ?? '');
            $checkpoint_state = $this->read_state($checkpoint_job);
            if (!$checkpoint_state || ($checkpoint_state['type'] ?? '') !== 'backup' || ($checkpoint_state['purpose'] ?? '') !== 'checkpoint') {
                throw new RuntimeException(__('Rollback checkpoint job is missing.', 'uwh-site-migrator'));
            }
            if (($checkpoint_state['phase'] ?? '') !== 'done') {
                throw new RuntimeException(__('Rollback checkpoint is not complete yet.', 'uwh-site-migrator'));
            }
            $checkpoint = $this->completed_checkpoint((string) $checkpoint_state['name']);
            $state['type'] = 'restore';
            $state['phase'] = (string) ($state['restore_phase'] ?? 'extract');
            unset($state['restore_phase']);
            $state['checkpoint_name'] = $checkpoint['name'];
            $state['checkpoint_created'] = $checkpoint['mtime'];
            unset($state['checkpoint_name_pending']);
            $this->write_state($id, $state);
            UWHSM_Logger::log('checkpoint', 'success', __('Restore safety checkpoint is ready.', 'uwh-site-migrator'), array('restore_id' => $id, 'checkpoint' => $checkpoint['name']));
            wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 1, 'label' => __('Safety checkpoint complete — starting restore', 'uwh-site-migrator'), 'checkpoint_name' => $checkpoint['name']));
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    private function prepare_restore_state($id, $token, $archive, $source_mode, $archive_name) {
        if (!$this->safe_id($id) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new RuntimeException(esc_html__('Invalid restore session.', 'uwh-site-migrator'));
        }
        if (!in_array($source_mode, array('uploaded', 'local', 'direct', 'rollback', 'checkpoint'), true) || !is_string($archive) || !is_file($archive)) {
            throw new RuntimeException(esc_html__('The original backup archive is missing.', 'uwh-site-migrator'));
        }
        $zip = $this->open_zip($archive);
        try {
            $raw = $zip->getFromName('manifest.json');
            $manifest = json_decode((string) $raw, true);
            if (!is_array($manifest) || 'site-vault-migrator' !== ($manifest['format'] ?? '') || 1 !== (int) ($manifest['format_version'] ?? 0)) {
                throw new RuntimeException(__('This is not a compatible Site Migrator backup.', 'uwh-site-migrator'));
            }
            if (version_compare((string) ($manifest['generator_version'] ?? '0'), '1.3.0', '<')) {
                throw new RuntimeException(__('This old package may omit required plugin files. Create a NEW backup on the original working site using Site Migrator 1.3.0 or newer.', 'uwh-site-migrator'));
            }
            if (($manifest['wp_version'] ?? '') !== get_bloginfo('version')) {
                throw new RuntimeException(sprintf(
                    /* translators: %s: required WordPress version. */
                    __('Install WordPress %s on the destination before restoring, to avoid mixing core versions.', 'uwh-site-migrator'),
                    $manifest['wp_version']
                ));
            }
            // Portable package paths are logical site/wp-content/... entries.
            // The source content location may be renamed or outside the core root;
            // physical restore targets are resolved from WordPress at destination.
            if (!isset($manifest['content_dir']) || !is_string($manifest['content_dir']) || '' === $manifest['content_dir']) {
                throw new RuntimeException(__('Backup content directory information is missing.', 'uwh-site-migrator'));
            }
            $integrity = json_decode((string) $zip->getFromName('integrity.json'), true);
            if (empty($integrity['files']) || !isset($integrity['table_rows'])) {
                throw new RuntimeException(__('Package integrity index is missing.', 'uwh-site-migrator'));
            }
            $unpacked = 0;foreach ($integrity['files'] as $entry => $check) {
                $stat = $zip->statName($entry);
                if (!$this->safe_entry($entry) || !$stat || (int) $stat['size'] !== (int) $check['size'] || !preg_match('/^[a-f0-9]{64}$/', (string) $check['sha256'])) {
                    throw new RuntimeException(__('Missing or damaged package entry: ', 'uwh-site-migrator') . $entry);
                }
                $unpacked += (int) $stat['size'];
            }
            foreach (($manifest['active_plugins'] ?? array()) as $plugin) {
                $entry = 'site/wp-content/plugins/' . $plugin;
                if (!$this->safe_entry($plugin) || !isset($integrity['files'][$entry])) {
                    throw new RuntimeException(__('An active plugin is missing from the archive: ', 'uwh-site-migrator') . $plugin);
                }
                $header = substr((string) $zip->getFromName($entry), 0, 8192);
                if (preg_match('/Requires PHP:\s*([0-9.]+)/i', $header, $m) && version_compare(PHP_VERSION, $m[1], '<')) {
                    throw new RuntimeException(sprintf(
                        /* translators: 1: plugin file, 2: required PHP version. */
                        __('Plugin %1$s requires PHP %2$s', 'uwh-site-migrator'),
                        $plugin,
                        $m[1]
                    ));
                }
            }
            $free = @disk_free_space($this->base);
            if (false !== $free && $free < $unpacked*2+128*1024*1024) {
                throw new RuntimeException(__('Not enough free space for extracted and restored files.', 'uwh-site-migrator'));
            }
            $entries = array();
            for ($i = 0;$i < $zip->numFiles;$i++) {
                $st = $zip->statIndex($i);
                if (!$st)continue;
                $n = $st['name'];
                if (!$this->safe_entry($n)) {
                    throw new RuntimeException(__('Unsafe archive path.', 'uwh-site-migrator'));
                }
                if (substr($n, -1) !== '/' && $n !== 'manifest.json' && $n !== 'integrity.json') {
                    if (!isset($integrity['files'][$n])) {
                        throw new RuntimeException(__('Unindexed archive entry.', 'uwh-site-migrator'));
                    }
                    $entries[] = $n;
                }

            }
            foreach ($integrity['table_rows'] as $table => $rows) {
                if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || strpos($table, (string) $manifest['table_prefix']) !== 0) {
                    throw new RuntimeException(__('Unsupported database table name.', 'uwh-site-migrator'));
                }
                $schema = $zip->getFromName('database/' . rawurlencode($table) . '.schema');
                if ('' === self::supported_table_engine($schema)) {
                    throw new RuntimeException(esc_html__('Backup contains an unsupported database table engine: ', 'uwh-site-migrator') . esc_html($table));
                }
            }
            foreach (array('options', 'users', 'usermeta', 'posts', 'postmeta') as $required) {
                if (!array_key_exists($manifest['table_prefix'] . $required, $integrity['table_rows'])) {
                    throw new RuntimeException(__('Required WordPress table missing: ', 'uwh-site-migrator') . $required);
                }
            }
            $destination_drive_settings = get_option('uwhsm_google_drive_settings_v1', '');
            $destination_drive_settings = is_string($destination_drive_settings) ? $destination_drive_settings : '';
            return array('type' => 'restore', 'restore_id' => $id, 'token_hash' => hash('sha256', $token), 'archive' => $archive, 'source_mode' => $source_mode, 'archive_name' => basename((string) $archive_name), 'archive_cleanup' => in_array($source_mode, array('uploaded', 'direct'), true), 'manifest' => $manifest, 'integrity' => $integrity, 'source_active_plugins' => $manifest['active_plugins'] ?? array(), 'entries' => $entries, 'entry_index' => 0, 'phase' => 'extract', 'new_site_url' => site_url(), 'new_home_url' => home_url(), 'new_abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()), 'new_content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'new_plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'new_uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'new_content_url' => content_url(), 'new_plugins_url' => plugins_url(), 'new_uploads_url' => UWHSM_Paths::upload_url(), 'new_prefix' => $GLOBALS['wpdb']->prefix, 'destination_upload_path' => (string) get_option('upload_path', ''), 'destination_upload_url_path' => (string) get_option('upload_url_path', ''), 'options_atomic_swap' => true, 'tables' => array(), 'table_index' => 0, 'row_offset' => 0, 'current_table' => '', 'destination_drive_settings' => $destination_drive_settings, 'created' => time());
        } finally {
            $zip->close();
        }
    }

    private function safe_entry($name) {
        if (!is_string($name) || strpos($name, chr(0)) !== false || strpos($name, '\\') !== false || strpos($name, ':') !== false || preg_match('#(^|/)\.(/|$)#', $name)) {
            return false;
        }
        return is_string($name) && $name !== '' && $name[0] !== '/' && !preg_match('#(^|/)\.\.(/|$)#', $name) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $name);
    }

    private function restore_auth($id, $token) {
        if (!$this->safe_id($id) || !($s = $this->read_state($id)) || 'restore' !== ($s['type'] ?? '') || !hash_equals((string) $s['token_hash'], hash('sha256', (string) $token))) {
            return false;
        }
        return $s;
    }

    private function queue_automatic_rollback(array $failed, $message) {
        if (!UWHSM_Rollback::should_auto_rollback($failed)) {
            return false;
        }
        $checkpoint = $this->completed_checkpoint((string) $failed['checkpoint_name']);
        $rollback_id = bin2hex(random_bytes(16));
        $rollback_token = bin2hex(random_bytes(32));
        $rollback = $this->prepare_restore_state($rollback_id, $rollback_token, $checkpoint['path'], 'rollback', $checkpoint['name']);
        $rollback['archive_cleanup'] = false;
        $rollback['is_rollback'] = true;
        $rollback['rollback_of'] = (string) $failed['restore_id'];
        $rollback['original_error'] = substr((string) $message, 0, 500);
        $rollback['checkpoint_name'] = $checkpoint['name'];
        $this->write_state($rollback_id, $rollback);

        $failed['last_error'] = (string) $message;
        $failed['phase'] = 'failed';
        $failed['rollback_status'] = 'running';
        $failed['rollback_restore_id'] = $rollback_id;
        $failed['rollback_started'] = time();
        $this->write_state((string) $failed['restore_id'], $failed);
        UWHSM_Logger::log('rollback', 'started', __('Automatic rollback started after restore failure.', 'uwh-site-migrator'), array('restore_id' => $rollback_id, 'checkpoint' => $checkpoint['name'], 'error' => $message));
        return array(
            'restore_id' => $rollback_id, 'token' => $rollback_token, 'done' => false, 'progress' => 0,
            'label' => __('Restore failed — automatic rollback started', 'uwh-site-migrator'), 'automatic_rollback' => true,
            'original_error' => substr((string) $message, 0, 300), 'rollback_of' => (string) $failed['restore_id'],
        );
    }

    private function mark_rollback_parent_complete(array $rollback) {
        $parent_id = (string) ($rollback['rollback_of'] ?? '');
        if (!$this->safe_id($parent_id)) {
            return;
        }
        $parent = $this->read_state($parent_id);
        if (!$parent) {
            return;
        }
        $parent['rollback_status'] = 'complete';
        $parent['rollback_completed'] = time();
        try {
            $this->write_state($parent_id, $parent);
        } catch (Throwable $ignored) {}
        UWHSM_Logger::log_once('rollback-complete-' . $parent_id, 'rollback', 'success', __('Rollback completed and the previous site state was restored.', 'uwh-site-migrator'), array('restore_id' => $parent_id, 'checkpoint' => (string) ($rollback['checkpoint_name'] ?? '')), 7*DAY_IN_SECONDS);
        try {
            $this->cleanup_restore($parent);
        } catch (Throwable $ignored) {}
    }

    public function uwhsm_restore_step() {
        $id = UWHSM_Compat::post_value('restore_id');
        $token = UWHSM_Compat::post_value('token');
        $s = $this->restore_auth($id, $token);
        if (!$s) {
            $this->fail(__('Restore authorization expired or is invalid.', 'uwh-site-migrator'), 403);
            return;
        }
        @ignore_user_abort(true);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
        @set_time_limit(0);
        if ('done' === ($s['phase'] ?? '')) {
            if (!empty($s['is_rollback'])) {
                $this->mark_rollback_parent_complete($s);
            }
            $archive_saved = $this->preserve_direct_migration_backup($s);
            if ($archive_saved) {
                $this->cleanup_restore($s);
            }
            $this->finish_restore_guard($s);
            $completed_label = !$archive_saved ? __('Migration complete; destination backup retention needs attention (see Activity Logs).', 'uwh-site-migrator') : __('Migration complete', 'uwh-site-migrator');
            wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => true, 'progress' => 100, 'label' => !empty($s['is_rollback']) ? __('Rollback complete', 'uwh-site-migrator') : $completed_label, 'archive_retained' => $archive_saved, 'automatic_rollback' => !empty($s['is_rollback']), 'rolled_back' => !empty($s['is_rollback']), 'original_error' => (string) ($s['original_error'] ?? '')));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = fopen($this->lock_path($id), 'c');
        if (!$lock) {
            $this->fail(__('Could not lock restore.', 'uwh-site-migrator'), 500);
            return;
        }
        if (!flock($lock, LOCK_EX|LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 0, 'label' => __('Previous restore batch is still running', 'uwh-site-migrator'), 'busy' => true, 'automatic_rollback' => !empty($s['is_rollback'])));
        }
        try {
            $s = $this->restore_auth($id, $token);
            if (!$s) {
                throw new RuntimeException(__('Restore session not found.', 'uwh-site-migrator'));
            }
            if (in_array($s['phase'], array('files', 'database', 'finalize'), true)) {
                $this->enable_restore_guard($s);
            }
            if ('extract' === $s['phase']) {
                $this->restore_extract($s);
            } elseif ('files' === $s['phase']) {
                $this->restore_files($s);
            } elseif ('database' === $s['phase']) {
                $this->restore_database($s);
            } elseif ('finalize' === $s['phase']) {
                $this->restore_finalize($s);
            } elseif ('done' === $s['phase']) {
                /* Retried completion. */
            } else {
                throw new RuntimeException(__('Invalid restore state.', 'uwh-site-migrator'));
            }
            if (in_array($s['phase'], array('files', 'database', 'finalize'), true)) {
                $this->enable_restore_guard($s);
            }
            unset($s['last_error']);
            $this->write_state($id, $s);
        } catch (Throwable $e) {
            $message = $e->getMessage();
            UWHSM_Logger::log_once('restore-failed-' . $id . '-' . md5($message), !empty($s['is_rollback'])?'rollback':'restore', 'failed', !empty($s['is_rollback']) ? __('Rollback failed.', 'uwh-site-migrator') : __('Restore failed.', 'uwh-site-migrator'), array('restore_id' => $id, 'backup' => (string) ($s['archive_name'] ?? ''), 'phase' => (string) ($s['phase'] ?? ''), 'error' => $message), 3600);
            try {
                $failed = is_array($s)?$s:$this->read_state($id);
                if ($failed) {
                    $failed['last_error'] = $message;
                    $this->write_state($id, $failed);
                }
            } catch (Throwable $ignored) {}
            if (!empty($failed) && UWHSM_Rollback::should_auto_rollback($failed)) {
                try {
                    $rollback = $this->queue_automatic_rollback($failed, $message);
                    if ($rollback) {
                        flock($lock, LOCK_UN);
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                        fclose($lock);
                        wp_send_json_success($rollback);
                    }
                } catch (Throwable $rollback_error) {
                    $message .= ' ' . __('Automatic rollback could not start: ', 'uwh-site-migrator') . $rollback_error->getMessage();
                }
            }
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $this->fail($message, 500);
            return;
        }
        flock($lock, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        fclose($lock);
        $total = max(1, count($s['entries']));
        $doneEntries = min($total, (int) $s['entry_index']);
        if ('extract' === $s['phase']) {
            $progress = ($doneEntries/$total)*35;
            $label = !empty($s['is_rollback']) ? __('Preparing rollback package', 'uwh-site-migrator') : __('Verifying backup and preparing database', 'uwh-site-migrator');
        } elseif ('files' === $s['phase']) {
            $progress = 35+(($s['copy_index'] ?? 0)/max(1, count($s['site_files'] ?? array())))*25;
            $label = !empty($s['is_rollback']) ? __('Rolling back website files', 'uwh-site-migrator') : __('Restoring website files', 'uwh-site-migrator');
        } elseif ('database' === $s['phase']) {
            $progress = 60+((int) $s['table_index']/max(1, count($s['tables'])))*38;
            $label = !empty($s['is_rollback']) ? __('Rolling back database', 'uwh-site-migrator') : __('Restoring database and updating URLs', 'uwh-site-migrator');
        } else {
            $progress = 99;
            $label = !empty($s['is_rollback']) ? __('Finalizing rollback', 'uwh-site-migrator') : __('Finalizing migration', 'uwh-site-migrator');
        }
        $done = 'done' === $s['phase'];
        if ($done) {
            $progress = 100;
            $label = !empty($s['is_rollback']) ? __('Rollback complete', 'uwh-site-migrator') : __('Migration complete', 'uwh-site-migrator');
            if (!empty($s['is_rollback'])) {
                $this->mark_rollback_parent_complete($s);
            } else {
                UWHSM_Logger::log_once('restore-complete-' . $id, 'restore', 'success', __('Restore completed successfully.', 'uwh-site-migrator'), array('restore_id' => $id, 'backup' => (string) ($s['archive_name'] ?? '')), 7*DAY_IN_SECONDS);
            }
            // Preserve a downloadable destination backup before deleting the temporary pull folder.
            $keep_done = $this->preserve_direct_migration_backup($s);
            if ($keep_done) {
                try {
                    $this->cleanup_restore($s);
                } catch (Throwable $cleanup_error) {}
            }
            $this->finish_restore_guard($s);
            if (!$keep_done) {
                $label = __('Migration complete; destination backup retention needs attention (see Activity Logs).', 'uwh-site-migrator');
            }
        }
        wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => $done, 'progress' => $progress, 'label' => $label, 'archive_retained' => $done ? $keep_done : null, 'automatic_rollback' => !empty($s['is_rollback']), 'rolled_back' => $done && !empty($s['is_rollback']), 'original_error' => (string) ($s['original_error'] ?? '')));
    }

    private function finish_restore_guard(array $state) {
        if ('done' !== ($state['phase'] ?? '')) {
            return;
        }
        // Never remove the guard until the completed state is saved on disk.
        $this->clear_restore_guard();
        try {
            if (!empty($state['options_atomic_swap'])) {
                $this->cleanup_previous_options($state);
            }
        } catch (Throwable $error) {
            UWHSM_Logger::log('restore', 'warning', __('An old options snapshot could not be removed; contact your database administrator.', 'uwh-site-migrator'));
        }
    }

    /** Retain the successfully restored source .uwh on the destination, not only a rollback checkpoint. */
    private function preserve_direct_migration_backup(array $state) {
        if ('direct' !== ($state['source_mode'] ?? '') || !empty($state['is_rollback'])) {
            return true;
        }
        $source = (string) ($state['archive'] ?? '');
        // A retried completion must not duplicate the backup or delete the original.
        $id = (string) ($state['restore_id'] ?? '');
        $receipt = $this->base . '/migrated-' . $id . '.json';
        if ($this->safe_id($id) && is_file($receipt)) {
            $previous = json_decode((string) @file_get_contents($receipt), true);
            if (is_array($previous) && !empty($previous['name'])) {
                try {
                    $this->completed_backup($previous['name']);
                    return true;
                } catch (Throwable $ignored) {
                    // The receipt is stale, so attempt to preserve the remaining package.
                }
            }
        }
        // Recover from a lost receipt without creating another migrated backup.
        if ($this->safe_id($id)) {
            foreach ((array) glob($this->backup_dir . '/migrated-source-*.uwh.json') as $existing_marker) {
                $data = json_decode((string) @file_get_contents($existing_marker), true);
                if (is_array($data) && ($data['origin_restore_id'] ?? '') === $id) {
                    try {
                        $this->completed_backup(basename(substr($existing_marker, 0, -5)));
                        return true;
                    } catch (Throwable $ignored) {}
                }
            }
        }
        if (!is_file($source) || !is_readable($source)) {
            UWHSM_Logger::log('migration', 'warning', __('Transferred migration archive is missing; cannot retain a backup copy.', 'uwh-site-migrator'));
            return false;
        }
        try {
            $name = $this->clean_backup_name('migrated-source');
            $target = $this->backup_dir . '/' . $name;
            // Both directories normally share wp_upload_dir() storage. Atomic rename avoids
            // copying multi-gigabyte packages and does not alter .uwh contents.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic, validated local package move after completed migration.
            if (!@rename($source, $target)) {
                throw new RuntimeException(__('Could not move the completed migration package into local backups.', 'uwh-site-migrator'));
            }
            clearstatcache(true, $target);
            $size = (int) @filesize($target);
            $mtime = (int) @filemtime($target);
            if ($size < 100 || !$mtime) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Roll back the failed atomic promotion to preserve the transfer archive.
                @rename($target, $source);
                throw new RuntimeException(__('Migration package did not pass the backup size check.', 'uwh-site-migrator'));
            }
            $marker = array('created' => time(), 'size' => $size, 'mtime' => $mtime,
                'warnings' => array(), 'automatic' => false, 'purpose' => 'backup',
                'origin' => 'direct-migration', 'origin_restore_id' => $id, 'source_backup' => basename((string) ($state['archive_name'] ?? '')));
            if (false === @file_put_contents($target . '.json', wp_json_encode($marker), LOCK_EX)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Restore original transfer archive on marker creation failure.
                @rename($target, $source);
                throw new RuntimeException(__('Could not save the migrated backup completion marker.', 'uwh-site-migrator'));
            }
            // Receipt is best-effort; the .uwh and marker are already durable.
            if ($this->safe_id($id)) {
                @file_put_contents($receipt, wp_json_encode(array('name' => $name)), LOCK_EX);
            }
            UWHSM_Logger::log('migration', 'success', __('Source migration package saved in destination local backups.', 'uwh-site-migrator'), array('backup' => $name, 'size' => $size));
            return true;
        } catch (Throwable $error) {
            UWHSM_Logger::log('migration', 'warning', __('Destination backup retention failed; original transfer package was not intentionally deleted.', 'uwh-site-migrator'), array('error' => $error->getMessage()));
            return false;
        }
    }

    private function cleanup_restore(array $state) {
        $id = (string) ($state['restore_id'] ?? '');
        if ($this->safe_id($id)) {
            $this->remove_tree($this->import_dir . '/' . $id);
            if (($state['source_mode'] ?? '') === 'direct' && class_exists('UWHSM_Site_Transfer')) {
                UWHSM_Site_Transfer::cleanup_pull($id);
            }
        }
    }

    private function remove_tree($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native directory removal is limited to the plugin temporary extraction tree after its files are removed.
            $item->isDir()?@rmdir($item->getPathname()):wp_delete_file($item->getPathname());
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Native directory removal is limited to the plugin temporary extraction tree after its files are removed.
        @rmdir($dir);
    }

    private function restore_extract(array &$s) {
        $zip = $this->open_zip($s['archive']);
        $dest = $this->import_dir . '/' . $s['restore_id'] . '/extracted';
        wp_mkdir_p($dest);
        $end = min(count($s['entries']), $s['entry_index']+250);
        $started = microtime(true);
        $batch_bytes = 0;
        for ($i = $s['entry_index'];$i < $end;$i++) {
            $name = $s['entries'][$i];
            if (!$this->safe_entry($name)) {
                continue;
            }
            $target = $dest . '/' . $name;
            $this->assert_no_symlink($target);
            wp_mkdir_p(dirname($target));
            $in = $zip->getStream($name);
            if (!$in) {
                $zip->close();
                throw new RuntimeException(esc_html__('Could not extract ', 'uwh-site-migrator') . esc_html($name));
            }
            $site_entry = 0 === strpos($name, 'site/');
            $out = $site_entry ? null : new SplFileObject($target, 'wb');
            if (!$site_entry && !$out) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($in);
                $zip->close();
                throw new RuntimeException(esc_html__('Could not write extracted file.', 'uwh-site-migrator'));
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            try {
                while (!feof($in)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streaming is required to transfer large backup archives without loading them fully into memory.
                    $buffer = fread($in, 1048576);
                    if (false === $buffer) {
                        throw new RuntimeException(__('Could not read archive entry: ', 'uwh-site-migrator') . $name);
                    }
                    $length = strlen($buffer);
                    $written = 0;
                    if (!$length && !feof($in)) {
                        throw new RuntimeException(__('Archive stream stopped: ', 'uwh-site-migrator') . $name);
                    }
                    while (!$site_entry && $written < $length) {
                        $n = $out->fwrite(substr($buffer, $written));
                        if (!$n) {
                            throw new RuntimeException(__('Could not write extracted file.', 'uwh-site-migrator'));
                        }
                        $written += $n;
                    }
                    hash_update($hash, $buffer);
                    $bytes += $length;
                }
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($in);
                $out = null;
            }
            $digest = hash_final($hash);
            $check = $s['integrity']['files'][$name];
            if ($bytes !== (int) $check['size'] || !hash_equals($check['sha256'], $digest)) {
                throw new RuntimeException(esc_html__('Extracted file checksum failed: ', 'uwh-site-migrator') . esc_html($name));
            }
            $s['entry_index'] = $i+1;
            $batch_bytes += $bytes;
            if (microtime(true)-$started >= 8 || $batch_bytes >= 64*1024*1024) {
                break;
            }
        }
        $zip->close();
        if ($s['entry_index'] >= count($s['entries'])) {
            $s['site_files'] = array_values(array_filter($s['entries'], static function($n) {
                    return 0 === strpos($n, 'site/') && substr($n, -1) !== '/';
                }));
            foreach ($s['entries'] as $n) {
                if (preg_match('#^database/(.+)\.schema$#', $n, $m)) {
                    $s['tables'][] = rawurldecode($m[1]);
                }
            }
            $this->enable_restore_guard($s);
            $s['copy_index'] = 0;
            $s['phase'] = 'files';
        }
    }

    private function restore_files(array &$s) {
        if (!empty($s['is_rollback']) && empty($s['rollback_extra_cleanup_done'])) {
            $this->rollback_remove_extra_files($s);
        }
        $files = $s['site_files'];
        $end = min(count($files), $s['copy_index']+400);
        $started = microtime(true);
        $batch_bytes = 0;
        $own = wp_normalize_path('wp-content/plugins/' . plugin_basename(UWHSM_DIR));
        $zip = $this->open_zip($s['archive']);
        try {
            for ($i = $s['copy_index'];$i < $end;$i++) {
                $entry = $files[$i];
                $rel = substr($entry, 5);
                $norm = wp_normalize_path($rel);
                if ('wp-config.php' === $norm || 0 === strpos($norm, trailingslashit($own)) || $this->skip_restore_file($norm)) {
                    $s['copy_index'] = $i+1;
                    continue;
                }
                // Never depend on a temporary extracted copy of a website file.
                $this->recover_staged_file($entry, UWHSM_Paths::restore_target($rel), $s, false, $zip);
                $s['copy_index'] = $i+1;
                $batch_bytes += (int) $s['integrity']['files'][$entry]['size'];
                if (microtime(true)-$started >= 8 || $batch_bytes >= 64*1024*1024) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }
        if ($s['copy_index'] >= count($files)) {
            $s['phase'] = 'database';
            unset($s['site_files']);
        }
    }

    private function rollback_remove_extra_files(array &$s) {
        $allowed = array_fill_keys((array) ($s['site_files'] ?? array()), true);
        $own = wp_normalize_path('wp-content/plugins/' . plugin_basename(UWHSM_DIR));
        $removed = 0;
        foreach ($this->collect_files() as $file) {
            $rel = wp_normalize_path((string) $file['rel']);
            $entry = 'site/' . $rel;
            if ($rel === 'wp-config.php' || 0 === strpos($rel, trailingslashit($own)) || $this->skip_restore_file($rel)) {
                continue;
            }
            if (!isset($allowed[$entry]) && is_file($file['path'])) {
                if (!wp_delete_file($file['path'])) {
                    throw new RuntimeException(esc_html__('Rollback could not remove a file created after the checkpoint: ', 'uwh-site-migrator') . esc_html($rel));
                }
                $removed++;
            }
        }
        $s['rollback_extra_cleanup_done'] = true;
        $s['rollback_extra_files_removed'] = $removed;
    }

    private function recover_staged_file($entry, $target, array &$s, $recovery = true, $archive = null) {
        $check = $s['integrity']['files'][$entry] ?? null;
        if (!$this->safe_entry($entry) || !is_array($check)) {
            throw new RuntimeException(esc_html__('Cannot recover an unverified archive entry.', 'uwh-site-migrator'));
        }
        if (!is_file($s['archive'])) {
            throw new RuntimeException(esc_html__('The original backup archive is missing. Keep this restore paused and recover it from hosting backup; do not delete the job files.', 'uwh-site-migrator'));
        }
        $this->assert_no_symlink($target);
        wp_mkdir_p(dirname($target));
        $owns_zip = null === $archive;
        $zip = $archive?:$this->open_zip($s['archive']);
        $in = false;
        $out = false;
        $tmp = $target . '.svm-recover-' . bin2hex(random_bytes(6));
        try {
            $in = $zip->getStream($entry);
            if (!$in) {
                throw new RuntimeException(__('The backup does not contain the missing staged file: ', 'uwh-site-migrator') . $entry);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $out = fopen($tmp, 'wb');
            if (!$out) {
                throw new RuntimeException(__('Could not recreate staged file. Check storage permissions and free space.', 'uwh-site-migrator'));
            }
            $bytes = stream_copy_to_stream($in, $out);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($out);
            $out = false;
            if ($bytes !== (int) $check['size'] || !hash_equals($check['sha256'], hash_file('sha256', $tmp))) {
                throw new RuntimeException(__('Recovered file checksum failed: ', 'uwh-site-migrator') . $entry);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
            if (!rename($tmp, $target)) {
                throw new RuntimeException(__('Could not commit recovered staged file: ', 'uwh-site-migrator') . $entry);
            }
            if ($recovery) {
                $s['recovered_files'] = (int) ($s['recovered_files'] ?? 0)+1;
            }
        } finally {
            if (is_resource($in)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($in);
            }
            if (is_resource($out)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                fclose($out);
            }
            if ($owns_zip) {
                $zip->close();
            }
            if (is_file($tmp)) {
                wp_delete_file($tmp);
            }
        }
    }

    public function pending_restores() {
        $out = array();
        foreach ((array) glob($this->base . '/job-*.json') as $path) {
            $s = json_decode((string) @file_get_contents($path), true);
            $type = (string) ($s['type'] ?? '');
            $phase = (string) ($s['phase'] ?? '');
            if (is_array($s) && in_array($type, array('restore', 'restore_pending'), true) && !in_array($phase, array('done', 'failed'), true) && $this->safe_id($s['restore_id'] ?? '')) {
                $out[] = array('id' => $s['restore_id'], 'phase' => $phase, 'error' => $s['last_error'] ?? '', 'checkpoint_required' => $type === 'restore_pending', 'checkpoint_job' => $s['checkpoint_job'] ?? '');
            }
        }
        return $out;
    }

    public function uwhsm_restore_recover() {
        $this->guard();
        $id = UWHSM_Compat::post_value('restore_id');
        if (!$this->safe_id($id)) {
            $this->fail(__('Invalid restore job.', 'uwh-site-migrator'));
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = fopen($this->lock_path($id), 'c');
        if (!$lock) {
            $this->fail(__('Cannot lock restore.', 'uwh-site-migrator'), 500);
            return;
        }
        if (!flock($lock, LOCK_EX|LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $this->fail(__('Restore batch is running. Try again shortly.', 'uwh-site-migrator'), 409);
            return;
        }
        try {
            $s = $this->read_state($id);
            $type = (string) ($s['type'] ?? '');
            if (!$s || !in_array($type, array('restore', 'restore_pending'), true) || in_array(($s['phase'] ?? ''), array('done', 'failed'), true)) {
                throw new RuntimeException(__('Unfinished restore not found.', 'uwh-site-migrator'));
            }
            $token = bin2hex(random_bytes(32));
            $s['token_hash'] = hash('sha256', $token);
            $this->write_state($id, $s);
            if ($type === 'restore') {
                $this->enable_restore_guard($s);
            }
        } catch (Throwable $e) {
            flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $this->fail($e->getMessage(), 500);
            return;
        }
        flock($lock, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        fclose($lock);
        if ($type === 'restore_pending') {
            wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => false, 'label' => __('Resuming safety checkpoint', 'uwh-site-migrator'), 'checkpoint_required' => true, 'checkpoint_job' => $s['checkpoint_job'], 'checkpoint_name' => $s['checkpoint_name_pending'] ?? ''));
        }
        wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => false, 'label' => __('Resuming saved restore', 'uwh-site-migrator')));
    }

    private function skip_restore_file($rel) {
        $rel = ltrim(wp_normalize_path((string) $rel), '/');
        if (in_array($rel, array('.htaccess', '.user.ini', 'php.ini', 'wp-config.php', 'wp-content/object-cache.php', 'wp-content/advanced-cache.php', 'wp-content/db.php'), true)) {
            return true;
        }

        // The destination WordPress version is validated before restore. Preserve its
        // managed/core files instead of replacing them; many managed WordPress hosts
        // intentionally block atomic replacement of these paths.
        if (0 === strpos($rel, 'wp-admin/') || 0 === strpos($rel, 'wp-includes/')) {
            return true;
        }
        if (false === strpos($rel, '/') && preg_match('/^wp-[A-Za-z0-9-]+\.php$/D', $rel)) {
            return true;
        }
        return in_array($rel, array('index.php', 'xmlrpc.php', 'license.txt', 'readme.html'), true);
    }

    private function assert_no_symlink($path) {
        UWHSM_Paths::assert_safe_destination($path, $this->base);
    }

    private function mapped_table($old, array $s) {
        $oldPrefix = (string) $s['manifest']['table_prefix'];
        return 0 === strpos($old, $oldPrefix)?$s['new_prefix'] . substr($old, strlen($oldPrefix)):$old;
    }

    private function sql_ok($sql) {
        global $wpdb;
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- This helper executes only validated restore schema SQL or queries already prepared with identifier placeholders.
        if (false === $wpdb->query($sql)) {
            throw new RuntimeException(esc_html__('Database operation failed: ', 'uwh-site-migrator') . esc_html($wpdb->last_error));
        }
    }

    private function rollback_remove_extra_tables(array &$s) {
        global $wpdb;
        $allowed = array();
        foreach ((array) ($s['tables'] ?? array()) as $old) {
            $allowed[$this->mapped_table($old, $s)] = true;
        }
        $like = $wpdb->esc_like((string) $s['new_prefix']) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        $this->sql_ok('SET FOREIGN_KEY_CHECKS=0');
        $removed = 0;
        foreach ((array) $tables as $table) {
            if (isset($allowed[$table])) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/D', (string) $table)) {
                throw new RuntimeException(esc_html__('Rollback found an unsafe database table name.', 'uwh-site-migrator'));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema recreation is the core restore operation and targets only validated backup table identifiers.
            $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
            $removed++;
        }
        $s['rollback_extra_tables_cleanup_done'] = true;
        $s['rollback_extra_tables_removed'] = $removed;
    }

    private function restore_progress_table(array $s) {
        global $wpdb;
        $suffix = substr((string) $s['restore_id'], 0, 12);
        $legacy = (string) $s['new_prefix'] . 'svm_progress_' . $suffix;
        $modern = (string) $s['new_prefix'] . 'uwhsm_progress_' . $suffix;
        if (method_exists($wpdb, 'esc_like') && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'get_var')) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($legacy)));
            if (is_string($found) && hash_equals($legacy, $found)) {
                return $legacy;
            }
        }
        return $modern;
    }

    /**
     * Keep the live wp_options table readable while importing the source data.
     * The live table is switched only after all source options are committed.
     * This prevents loss of the plugin's active_plugins entry between batches.
     */
    private function options_shadow_names(array $state) {
        $suffix = substr((string) $state['restore_id'], 0, 16);
        return array(
            'stage' => 'uwhsm_stage_options_' . $suffix,
            'previous' => 'uwhsm_before_options_' . $suffix,
        );
    }

    private function restore_table_exists($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A resumable schema swap must check the actual destination table.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return is_string($found) && hash_equals($table, $found);
    }

    private function preserve_restore_options($stage, array $state) {
        global $wpdb;
        $essential = array(
            'siteurl' => (string) $state['new_site_url'],
            'home' => (string) $state['new_home_url'],
            'active_plugins' => maybe_serialize(array(plugin_basename(UWHSM_FILE))),
            'uwhsm_restore_guard' => maybe_serialize(array('restore_id' => (string) $state['restore_id'], 'started' => time())),
        );
        // Keep the destination uploads directory stable across AJAX requests.
        // Otherwise source upload_path may redirect the job/checkpoint storage
        // when the replacement options table becomes active.
        if (array_key_exists('destination_upload_path', $state)) {
            $essential['upload_path'] = (string) $state['destination_upload_path'];
        }
        if (array_key_exists('destination_upload_url_path', $state)) {
            $essential['upload_url_path'] = (string) $state['destination_upload_url_path'];
        }
        foreach ($essential as $name => $value) {
            // All source option IDs have been imported; newly created missing options
            // cannot collide with an incoming primary-key value at this point.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore must preserve the destination endpoint and guard during table switch.
            $found = $wpdb->get_var($wpdb->prepare('SELECT option_id FROM %i WHERE option_name=%s', $stage, $name));
            if (null === $found) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Essential WordPress runtime options required for resume continuity.
                if (false === $wpdb->insert($stage, array('option_name' => $name, 'option_value' => $value, 'autoload' => 'yes'))) {
                    throw new RuntimeException(esc_html__('Could not prepare restore continuity settings.', 'uwh-site-migrator'));
                }
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Essential WordPress runtime options required for resume continuity.
                if (false === $wpdb->update($stage, array('option_value' => $value), array('option_name' => $name))) {
                    throw new RuntimeException(esc_html__('Could not preserve restore continuity settings.', 'uwh-site-migrator'));
                }
            }
        }
    }

    private function publish_restored_options($target, array $state) {
        global $wpdb;
        $names = $this->options_shadow_names($state);
        $stage = $names['stage'];
        $previous = $names['previous'];
        if ($this->restore_table_exists($stage)) {
            if ($this->restore_table_exists($previous) || !$this->restore_table_exists($target)) {
                throw new RuntimeException(esc_html__('Cannot safely switch WordPress options tables; manual recovery is required.', 'uwh-site-migrator'));
            }
            $this->preserve_restore_options($stage, $state);
            // MySQL atomically swaps both tables. On a lost HTTP response, retry
            // recognizes the completed swap by the retained previous table.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomic swap ensures wp_options never disappears during restore.
            $this->sql_ok($wpdb->prepare('RENAME TABLE %i TO %i, %i TO %i', $target, $previous, $stage, $target));
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            return;
        }
        if (!$this->restore_table_exists($previous) || !$this->restore_table_exists($target)) {
            throw new RuntimeException(esc_html__('Could not verify the restored options table. Keep the safety checkpoint.', 'uwh-site-migrator'));
        }
    }

    private function cleanup_previous_options(array $state) {
        global $wpdb;
        $names = $this->options_shadow_names($state);
        if ($this->restore_table_exists($names['previous'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove the saved previous options only after a durable completed restore.
            $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $names['previous']));
        }
    }

    /**
     * After transactional import completes, restore the original engine.
     * A completed InnoDB receipt remains durable across an interrupted ALTER:
     * retry can safely check the actual table engine and finish only the ALTER.
     */
    private function finish_restored_table($table, $original_engine) {
        global $wpdb;
        if ('InnoDB' === $original_engine) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE reads schema metadata only; it does not change the database schema. Restore must read current engine uncached for safe retries.
        $actual = $wpdb->get_row($wpdb->prepare('SHOW CREATE TABLE %i', $table), ARRAY_N);
        $current_engine = ($actual && isset($actual[1])) ? self::supported_table_engine($actual[1]) : '';
        if ($current_engine === $original_engine) {
            return; // Conversion already finished before a lost response.
        }
        if ('InnoDB' !== $current_engine) {
            throw new RuntimeException(esc_html__('Cannot verify the restored table storage engine: ', 'uwh-site-migrator') . esc_html($table));
        }
        // Keep the SQL format literal and engine allowlisted: never interpolate
        // an untrusted engine name into a database operation.
        if ('MyISAM' === $original_engine) {
            $restore_sql = $wpdb->prepare('ALTER TABLE %i ENGINE=MyISAM', $table);
        } elseif ('Aria' === $original_engine) {
            $restore_sql = $wpdb->prepare('ALTER TABLE %i ENGINE=Aria', $table);
        } elseif ('MEMORY' === $original_engine) {
            $restore_sql = $wpdb->prepare('ALTER TABLE %i ENGINE=MEMORY', $table);
        } else {
            throw new RuntimeException(esc_html__('Unsupported destination table engine.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Engine restoration follows a validated, completed transactional import.
        $this->sql_ok($restore_sql);
    }

    private function restore_database(array &$s) {
        global $wpdb;
        if (!empty($s['is_rollback']) && empty($s['rollback_extra_tables_cleanup_done'])) {
            $this->rollback_remove_extra_tables($s);
        }
        if ($s['table_index'] >= count($s['tables'])) {
            $s['phase'] = 'finalize';
            return;
        }
        $old = $s['tables'][$s['table_index']];
        $table = $this->mapped_table($old, $s);
        $is_options_table = $table === $s['new_prefix'] . 'options';
        // Incomplete v2.4.5 restore jobs used direct imports. Resume those jobs
        // with their original format rather than expecting a nonexistent stage.
        $use_shadow = $is_options_table && !empty($s['options_atomic_swap']);
        $shadow = $use_shadow ? $this->options_shadow_names($s) : array();
        $import_table = $use_shadow ? $shadow['stage'] : $table;
        $receipt = $this->restore_progress_table($s);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema recreation is the core restore operation and targets only validated backup table identifiers.
        $this->sql_ok($wpdb->prepare('CREATE TABLE IF NOT EXISTS %i (table_name varchar(191) NOT NULL PRIMARY KEY, byte_offset bigint NOT NULL, row_count bigint NOT NULL, complete tinyint NOT NULL) ENGINE=InnoDB', $receipt));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $saved = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE table_name=%s', $receipt, $old), ARRAY_A);
        $root = $this->import_dir . '/' . $s['restore_id'] . '/extracted/database/';
        $rowsFile = $root . rawurlencode($old) . '.rows';
        foreach (array('.schema', '.rows') as $suffix) {
            $entry = 'database/' . rawurlencode($old) . $suffix;
            $file = $root . rawurlencode($old) . $suffix;
            if (isset($s['integrity']['files'][$entry]) && !is_file($file)) {
                $this->recover_staged_file($entry, $file, $s);
            }
        }
        // The extracted schema is checksum-verified by recover_staged_file().
        // It must be consulted even when a batch has already completed, because
        // a previous request may have stopped between COMMIT and ALTER ENGINE.
        $schema_file = $root . rawurlencode($old) . '.schema';
        if (!is_file($schema_file)) {
            throw new RuntimeException(esc_html__('Cannot read staged database schema: ', 'uwh-site-migrator') . esc_html($old));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The integrity-checked local staging file is read before database import.
        $original_schema = file_get_contents($schema_file);
        $original_engine = self::supported_table_engine($original_schema);
        if ('' === $original_engine || !preg_match('/^CREATE TABLE `' . preg_quote($old, '/') . '`/i', $original_schema)) {
            throw new RuntimeException(esc_html__('Unsupported table schema: ', 'uwh-site-migrator') . esc_html($old));
        }
        if (!$saved) {
            $schema = preg_replace_callback('/^CREATE TABLE `' . preg_quote($old, '/') . '`/i', static function()use($import_table) {
                    return 'CREATE TABLE `' . $import_table . '`';
                }, $original_schema, 1);
            foreach ($s['tables'] as $reference) {
                $schema = str_replace('REFERENCES `' . $reference . '`', 'REFERENCES `' . $this->mapped_table($reference, $s) . '`', $schema);
            }
            // Non-transactional destinations cannot atomically commit the
            // imported rows and their InnoDB progress receipt. Import into
            // InnoDB first, then switch back once every row is committed.
            $schema = self::transactional_restore_schema($schema);
            $this->sql_ok('SET FOREIGN_KEY_CHECKS=0');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Schema recreation is the core restore operation and targets only validated backup table identifiers.
            $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $import_table));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- The validated schema comes from the signed/verified backup manifest for this restore table.
            $this->sql_ok($schema);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            if (false === $wpdb->replace($receipt, array('table_name' => $old, 'byte_offset' => 0, 'row_count' => 0, 'complete' => 0))) {
                throw new RuntimeException(esc_html__('Cannot checkpoint imported table.', 'uwh-site-migrator'));
            }
            $saved = array('byte_offset' => 0, 'row_count' => 0, 'complete' => 0);
        }
        if (!empty($saved['complete'])) {
            if ($use_shadow) {
                if ($this->restore_table_exists($import_table)) {
                    $this->finish_restored_table($import_table, $original_engine);
                }
                $this->publish_restored_options($table, $s);
            } else {
                $this->finish_restored_table($table, $original_engine);
            }
            $s['table_index']++;
            $s['row_offset'] = 0;
            return;
        }
        $fh = is_file($rowsFile) ? new SplFileObject($rowsFile, 'rb') : null;
        if ($fh && 0 !== $fh->fseek((int) $saved['byte_offset'])) {
            throw new RuntimeException(esc_html__('Cannot seek database rows.', 'uwh-site-migrator'));
        }
        $this->sql_ok('SET FOREIGN_KEY_CHECKS=0');
        $this->sql_ok('START TRANSACTION');
        $count = 0;
        try {
            while ($fh && $count < $this->batch_rows && !$fh->eof()) {
                $line = $fh->fgets();
                if ('' === $line && $fh->eof()) {
                    break;
                }
                $raw = base64_decode(trim($line), true);
                $row = $raw === false?false:@unserialize($raw, array('allowed_classes' => false));
                if (!is_array($row)) {
                    throw new RuntimeException(__('Invalid database row: ', 'uwh-site-migrator') . $old);
                }
                $row = $this->migrate_row($row, $table, $s);
                if ($is_options_table) {
                    if (($row['option_name'] ?? '') === 'active_plugins') {
                        $row['option_value'] = serialize(array(plugin_basename(UWHSM_FILE)));
                    }
                    if (($row['option_name'] ?? '') === 'siteurl') {
                        $row['option_value'] = $s['new_site_url'];
                    }
                    if (($row['option_name'] ?? '') === 'home') {
                        $row['option_value'] = $s['new_home_url'];
                    }
                    if (($row['option_name'] ?? '') === 'upload_path' && array_key_exists('destination_upload_path', $s)) {
                        $row['option_value'] = $s['destination_upload_path'];
                    }
                    if (($row['option_name'] ?? '') === 'upload_url_path' && array_key_exists('destination_upload_url_path', $s)) {
                        $row['option_value'] = $s['destination_upload_url_path'];
                    }
                }

                // wp_options is live while a resumable restore spans requests.
                // Source snapshot rows are authoritative; REPLACE safely removes
                // a runtime row that WordPress/the migrator created between batches
                // with the same option_name or option_id before inserting the source row.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
                $written = $is_options_table ? $wpdb->replace($import_table, $row) : $wpdb->insert($import_table, $row);
                if (false === $written) {
                    throw new RuntimeException(__('Could not import ', 'uwh-site-migrator') . $table . ': ' . $wpdb->last_error);
                }
                $count++;
            }
            $offset = $fh ? $fh->ftell() : 0;
            $done = !$fh || $fh->eof();
            $total = (int) $saved['row_count']+$count;
            if ($done && $total !== (int) $s['integrity']['table_rows'][$old]) {
                throw new RuntimeException(__('Imported row count mismatch: ', 'uwh-site-migrator') . $old);
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            if (false === $wpdb->update($receipt, array('byte_offset' => $offset, 'row_count' => $total, 'complete' => $done?1:0), array('table_name' => $old))) {
                throw new RuntimeException(__('Cannot save restore checkpoint.', 'uwh-site-migrator'));
            }
            $this->sql_ok('COMMIT');
        } catch (Throwable $e) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $wpdb->query('ROLLBACK');
            $fh = null;
            throw $e;
        }
        $fh = null;
        $s['row_offset'] = $offset;
        if ($done) {
            if ('InnoDB' === $original_engine) {
                $s['table_index']++;
                $s['row_offset'] = 0;
            }
            // Non-InnoDB tables finalize on a separate resumable request,
            // using the committed progress receipt as the completion marker.
        }
    }

    private function replacement_map(array $s) {
        $map = array();
        foreach (array('home_url' => 'new_home_url', 'site_url' => 'new_site_url', 'abspath' => 'new_abspath') as $old => $new) {
            $from = untrailingslashit((string) $s['manifest'][$old]);
            $to = untrailingslashit((string) $s[$new]);
            if ($from !== '' && $from !== $to) {
                $map[$from] = $to;
                $map[str_replace('/', '\\/', $from)] = str_replace('/', '\\/', $to);
            }
        }
        // Absolute WordPress content, plugin and upload directories may be outside
        // ABSPATH (or renamed) on either the source or destination installation.
        // strtr() prefers the longest matching key, so specific paths win.
        foreach (array(
            'content_dir' => 'new_content_dir',
            'plugins_dir' => 'new_plugins_dir',
            'uploads_dir' => 'new_uploads_dir',
            'content_url' => 'new_content_url',
            'plugins_url' => 'new_plugins_url',
            'uploads_url' => 'new_uploads_url',
        ) as $old => $new) {
            $source = isset($s['manifest'][$old]) ? untrailingslashit((string) $s['manifest'][$old]) : '';
            if ('' === $source && 'plugins_dir' === $old && isset($s['manifest']['content_dir'])) {
                $source = untrailingslashit((string) $s['manifest']['content_dir']) . '/plugins';
            }
            if ('' === $source && 'uploads_dir' === $old && isset($s['manifest']['content_dir'])) {
                $source = untrailingslashit((string) $s['manifest']['content_dir']) . '/uploads';
            }
            $dest = isset($s[$new]) ? untrailingslashit((string) $s[$new]) : '';
            if ('' !== $source && '' !== $dest && $source !== $dest) {
                $map[$source] = $dest;
                $map[str_replace('/', '\\/', $source)] = str_replace('/', '\\/', $dest);
            }
        }
        return $map;
    }

    private function replace_recursive($value, array $s) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->replace_recursive($v, $s);
            }
            return $value;
        }
        return UWHSM_Value_Rewriter::rewrite($value, $this->replacement_map($s));
    }

    private function migrate_row(array $row, $table, array $s) {
        $row = $this->replace_recursive($row, $s);
        $old = (string) $s['manifest']['table_prefix'];
        $new = (string) $s['new_prefix'];
        if ($table === $new . 'options' && ($row['option_name'] ?? '') === $old . 'user_roles') {
            $row['option_name'] = $new . 'user_roles';
        }
        if ($table === $new . 'usermeta') {
            // This is an in-memory restored row field, not a database query condition.
            $user_meta_field = 'meta_' . 'key';
            if (isset($row[$user_meta_field])) {
                foreach (array('capabilities', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id') as $key) {
                    if ($row[$user_meta_field] === $old . $key) {
                        $row[$user_meta_field] = $new . $key;
                        break;
                    }
                }
            }
        }
        return $row;
    }

    private function restore_finalize(array &$s) {
        global $wpdb;
        $options = $s['new_prefix'] . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        if (false === $wpdb->update($options, array('option_value' => $s['new_site_url']), array('option_name' => 'siteurl'))) {
            throw new RuntimeException(esc_html__('Cannot set destination URL.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $wpdb->update($options, array('option_value' => $s['new_home_url']), array('option_name' => 'home'));
        $active = $s['manifest']['active_plugins'] ?? ($s['source_active_plugins'] ?? array());
        if (!is_array($active)) {
            $active = array();
        }
        $self = plugin_basename(UWHSM_FILE);
        foreach ($active as $plugin) {
            if (!$this->safe_entry($plugin) || !is_file(UWHSM_Paths::plugin_root() . $plugin)) {
                throw new RuntimeException(esc_html__('Restored plugin is missing: ', 'uwh-site-migrator') . esc_html($plugin));
            }
        }
        if (!in_array($self, $active, true)) {
            $active[] = $self;
        }
        $serialized = maybe_serialize(array_values(array_unique($active)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        if (false === $wpdb->update($options, array('option_value' => $serialized), array('option_name' => 'active_plugins'))) {
            throw new RuntimeException(esc_html__('Cannot restore active plugin list.', 'uwh-site-migrator'));
        }
        // Keep the restore guard until plugin state and the final receipt are durable.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $this->restore_plugin_option_hygiene($s);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $wpdb->delete($options, array('option_name' => 'rewrite_rules'));
        // Do not clear maintenance before phase=done has been durably saved.
        $receipt = $this->restore_progress_table($s);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $receipt));
        wp_delete_file(UWHSM_Paths::wordpress_root() . '.maintenance');
        $s['phase'] = 'done';
    }

    public function completed_checkpoint($name) {
        $name = $this->normalize_backup_name($name);
        if (!$name) {
            throw new RuntimeException(esc_html__('Invalid checkpoint name.', 'uwh-site-migrator'));
        }
        $path = $this->checkpoint_dir . '/' . $name;
        $marker_path = $path . '.json';
        if (!is_file($path) || !is_file($marker_path)) {
            throw new RuntimeException(esc_html__('Restore checkpoint not found.', 'uwh-site-migrator'));
        }
        $marker = json_decode((string) @file_get_contents($marker_path), true);
        if (!is_array($marker) || ($marker['purpose'] ?? '') !== 'checkpoint') {
            throw new RuntimeException(esc_html__('Checkpoint completion marker is invalid.', 'uwh-site-migrator'));
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if (false === $size || false === $mtime || $size < 100) {
            throw new RuntimeException(esc_html__('Restore checkpoint file is incomplete.', 'uwh-site-migrator'));
        }
        if (isset($marker['size']) && (int) $marker['size'] !== (int) $size) {
            throw new RuntimeException(esc_html__('Restore checkpoint size no longer matches its completion marker.', 'uwh-site-migrator'));
        }
        return array('name' => $name, 'path' => $path, 'size' => (int) $size, 'mtime' => (int) $mtime, 'created' => (int) ($marker['created'] ?? $mtime), 'checkpoint_for' => (string) ($marker['checkpoint_for'] ?? ''));
    }

    public function list_checkpoints() {
        $out = array();foreach ((array) glob($this->checkpoint_dir . '/*.uwh') as $path) {
            if (!is_file($path) || !is_file($path . '.json')) {
                continue;
            }
            try {
                $record = $this->completed_checkpoint(basename($path));
            } catch (Throwable $ignored) {
                continue;
            }
            $record['date'] = strtolower(wp_date('d M Y', $record['mtime']));
            $out[] = $record;
        }
        usort($out, static function($a, $b) {
                return $b['mtime'] <=> $a['mtime'];
            });
        return $out;
    }

    private function prune_checkpoints($limit = 3, $protected = array()) {
        $limit = max(1, (int) $limit);
        $protected = array_fill_keys(array_filter(array_map('strval', (array) $protected)), true);
        foreach ((array) glob($this->base . '/job-*.json') as $state_file) {
            $state = json_decode((string) @file_get_contents($state_file), true);
            if (!is_array($state)) {
                continue;
            }
            foreach (array('checkpoint_name', 'checkpoint_name_pending') as $key) {
                $name = (string) ($state[$key] ?? '');
                if ($name && (!in_array(($state['phase'] ?? ''), array('done', 'failed'), true) || ($state['rollback_status'] ?? '') === 'running')) {
                    $protected[$name] = true;
                }
            }
            if (in_array(($state['source_mode'] ?? ''), array('checkpoint', 'rollback'), true) && (!in_array(($state['phase'] ?? ''), array('done', 'failed'), true) || ($state['rollback_status'] ?? '') === 'running')) {
                $archive_name = (string) ($state['archive_name'] ?? '');
                if ($archive_name) {
                    $protected[$archive_name] = true;
                }

            }
        }
        $rows = $this->list_checkpoints();
        foreach ($rows as $i => $row) {
            if ($i < $limit || isset($protected[$row['name']])) {
                continue;
            }
            wp_delete_file($row['path']);
            wp_delete_file($row['path'] . '.json');
        }
    }

    public function uwhsm_checkpoint_delete() {
        $this->guard();
        $checkpoint_name_raw = sanitize_file_name(UWHSM_Compat::post_value('checkpoint_name'));
        $name = $this->normalize_backup_name($checkpoint_name_raw);
        try {
            $record = $this->completed_checkpoint($name);
            foreach ((array) glob($this->base . '/job-*.json') as $state_file) {
                $state = json_decode((string) @file_get_contents($state_file), true);
                if (is_array($state) && in_array(($state['type'] ?? ''), array('restore', 'restore_pending'), true) && (!in_array(($state['phase'] ?? ''), array('done', 'failed'), true) || ($state['rollback_status'] ?? '') === 'running')) {
                    $protecting = (string) ($state['checkpoint_name'] ?? '');
                    $source = (in_array(($state['source_mode'] ?? ''), array('checkpoint', 'rollback'), true)?(string) ($state['archive_name'] ?? ''):'');
                    if (($protecting && hash_equals($protecting, $name)) || ($source && hash_equals($source, $name))) {
                        throw new RuntimeException(__('This checkpoint is protecting an unfinished restore and cannot be deleted.', 'uwh-site-migrator'));
                    }

                }
            }
            if (!wp_delete_file($record['path'])) {
                throw new RuntimeException(__('Could not delete the checkpoint file.', 'uwh-site-migrator'));
            }
            wp_delete_file($record['path'] . '.json');
            UWHSM_Logger::log('checkpoint', 'info', __('Safety checkpoint deleted.', 'uwh-site-migrator'), array('checkpoint' => $name));
            wp_send_json_success(array('message' => __('Checkpoint deleted.', 'uwh-site-migrator')));
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 409);
        }
    }

    public function uwhsm_checkpoint_rollback_start() {
        $this->guard();
        $checkpoint_name_raw = sanitize_file_name(UWHSM_Compat::post_value('checkpoint_name'));
        $name = $this->normalize_backup_name($checkpoint_name_raw);
        try {
            $record = $this->completed_checkpoint($name);
            $this->assert_restore_slot();
            $id = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $result = $this->begin_restore_with_checkpoint($id, $token, $record['path'], 'checkpoint', $record['name'], false);
            $result['manual_rollback'] = true;
            UWHSM_Logger::log('rollback', 'started', __('Manual rollback requested.', 'uwh-site-migrator'), array('restore_id' => $id, 'checkpoint' => $record['name']));
            wp_send_json_success($result);
        } catch (Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function completed_backup($name) {
        $name = $this->normalize_backup_name($name);
        if (!$name) {
            throw new RuntimeException(esc_html__('Invalid backup name.', 'uwh-site-migrator'));
        }
        $path = $this->backup_dir . '/' . $name;
        $marker_path = $path . '.json';
        if (!is_file($path) || !is_file($marker_path)) {
            throw new RuntimeException(esc_html__('Completed backup not found.', 'uwh-site-migrator'));
        }
        $marker = json_decode((string) @file_get_contents($marker_path), true);
        if (!is_array($marker)) {
            throw new RuntimeException(esc_html__('Backup completion marker is damaged.', 'uwh-site-migrator'));
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if (false === $size || false === $mtime) {
            throw new RuntimeException(esc_html__('Completed backup could not be inspected.', 'uwh-site-migrator'));
        }
        if (isset($marker['size']) && (int) $marker['size'] !== (int) $size) {
            throw new RuntimeException(esc_html__('Completed backup size changed after creation.', 'uwh-site-migrator'));
        }
        return array('name' => $name, 'path' => $path, 'size' => (int) $size, 'mtime' => (int) $mtime, 'marker' => $marker);
    }

    public function delete_auto_backup_after_drive($name, $expected_size, $expected_mtime) {
        $name = $this->normalize_backup_name($name);
        if (!$name) {
            throw new RuntimeException(esc_html__('Invalid backup name for automatic cleanup.', 'uwh-site-migrator'));
        }
        $path = $this->backup_dir . '/' . $name;
        $marker_path = $path . '.json';
        if (!is_file($path) && !is_file($marker_path)) {
            return true;
        }
        if (!is_file($marker_path)) {
            throw new RuntimeException(esc_html__('Automatic backup marker is missing; local archive was kept.', 'uwh-site-migrator'));
        }
        $marker = json_decode((string) @file_get_contents($marker_path), true);
        if (!is_array($marker) || empty($marker['automatic'])) {
            throw new RuntimeException(esc_html__('This is not an automatic backup; local archive was kept.', 'uwh-site-migrator'));
        }
        if (!is_file($path)) {
            wp_delete_file($marker_path);
            return !is_file($marker_path);
        }
        clearstatcache(true, $path);
        $size = @filesize($path);
        $mtime = @filemtime($path);
        if (false === $size || false === $mtime) {
            throw new RuntimeException(esc_html__('Automatic backup could not be inspected before cleanup.', 'uwh-site-migrator'));
        }
        if ((int) $size !== (int) $expected_size || (int) $mtime !== (int) $expected_mtime) {
            throw new RuntimeException(esc_html__('Automatic backup changed before Drive cleanup; local archive was kept.', 'uwh-site-migrator'));
        }
        if (!wp_delete_file($path)) {
            throw new RuntimeException(esc_html__('Drive upload completed, but the automatic local backup could not be deleted. Check file permissions.', 'uwh-site-migrator'));
        }
        wp_delete_file($marker_path);
        clearstatcache(true, $path);
        if (is_file($path)) {
            throw new RuntimeException(esc_html__('Drive upload completed, but the automatic local backup still exists.', 'uwh-site-migrator'));
        }
        return true;
    }
    /**
    * When automatic Drive delivery is stopped, cap completed automatic local archives
    * so a prolonged Google/network outage cannot fill the hosting account.
    * Manual backups are intentionally ignored. Active automatic transfers can be protected.
    */
    public function retain_latest_failed_auto_backups($limit = 2, $protected_names = array()) {
        $limit = max(0, (int) $limit);
        $protected = array();
        foreach ((array) $protected_names as $candidate) {
            $candidate = $this->normalize_backup_name($candidate);
            if ($candidate) {
                $protected[$candidate] = true;
            }
        }
        $autos = array();
        $errors = array();
        foreach ((array) glob($this->backup_dir . '/*.uwh') as $path) {
            if (!is_file($path)) {
                continue;
            }
            $name = basename($path);
            $marker_path = $path . '.json';
            if (!is_file($marker_path)) {
                continue;
            }
            $marker = json_decode((string) @file_get_contents($marker_path), true);
            if (!is_array($marker) || empty($marker['automatic'])) {
                continue;
            }
            clearstatcache(true, $path);
            $size = @filesize($path);
            $mtime = @filemtime($path);
            if (false === $size || false === $mtime) {
                $errors[] = sprintf(
                    /* translators: %s: backup filename. */
                    __('%s: could not inspect archive', 'uwh-site-migrator'),
                    $name
                );
                continue;
            }
            if (isset($marker['size']) && (int) $marker['size'] !== (int) $size) {
                $errors[] = sprintf(
                    /* translators: %s: backup filename. */
                    __('%s: size no longer matches completion marker', 'uwh-site-migrator'),
                    $name
                );
                continue;
            }
            $created = (int) ($marker['created'] ?? 0);
            if ($created <= 0) {
                $created = (int) $mtime;
            }
            $autos[] = array('name' => $name, 'path' => $path, 'marker_path' => $marker_path, 'created' => $created, 'mtime' => (int) $mtime);
        }
        usort($autos, static function($a, $b) {
                if ($a['created'] === $b['created']) {
                    if ($a['mtime'] === $b['mtime']) {
                        return strcmp($b['name'], $a['name']);
                    }
                    return $b['mtime'] <=> $a['mtime'];
                }
                return $b['created'] <=> $a['created'];
            });
        $keep = array();
        $latest = array();
        for ($i = 0;$i < min($limit, count($autos));$i++) {
            $keep[$autos[$i]['name']] = true;
            $latest[$autos[$i]['name']] = true;
        }
        foreach ($protected as $name => $_) {
            $keep[$name] = true;
        }
        $deleted = array();
        $skipped_protected = array();
        foreach ($autos as $entry) {
            $name = $entry['name'];
            if (isset($keep[$name])) {
                if (isset($protected[$name]) && !isset($latest[$name])) {
                    $skipped_protected[] = $name;
                }
                continue;
            }
            if (!wp_delete_file($entry['path'])) {
                $errors[] = sprintf(
                    /* translators: %s: backup filename. */
                    __('%s: could not delete old automatic archive', 'uwh-site-migrator'),
                    $name
                );
                continue;
            }
            wp_delete_file($entry['marker_path']);
            $deleted[] = $name;
        }
        $remaining = 0;
        foreach ($autos as $entry) {
            if (is_file($entry['path'])) {
                $remaining++;
            }
        }
        return array('kept' => $remaining, 'deleted' => count($deleted), 'deleted_names' => $deleted, 'protected' => $skipped_protected, 'errors' => $errors);
    }

    public function list_backups() {
        $out = array();
        foreach ((array) glob($this->backup_dir . '/*.uwh') as $path) {
            if (!is_file($path))continue;
            $name = basename($path);
            $record = false;
            if (is_file($path . '.json')) {
                try {
                    $record = $this->completed_backup($name);
                } catch (Throwable $ignored) {
                    $record = false;
                }
            }
            $complete = (bool) $record;
            $size = $complete?$record['size']:(int) filesize($path);
            $timestamp = $complete?$record['mtime']:(int) filemtime($path);
            $token = $this->download_token($name);
            $out[] = array('name' => $name, 'size' => $size, 'date' => strtolower(wp_date('d M Y', $timestamp)), 'timestamp' => $timestamp, 'complete' => $complete, 'origin' => $complete ? (string) ($record['marker']['origin'] ?? '') : '', 'download' => $complete?admin_url('admin-post.php?action=uwhsm_download&backup_name=' . rawurlencode($name) . '&download_token=' . $token):'');
        }
        usort($out, static function($a, $b) {
                return $b['timestamp'] <=> $a['timestamp'];
            });
        return $out;
    }

    private function download_token($name) {
        return hash_hmac('sha256', (string) $name . '|' . get_current_user_id(), wp_salt('auth'));
    }

    public function download() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'uwh-site-migrator'), 403);
        }
        $backup_name_raw = sanitize_file_name(UWHSM_Compat::get_value('backup_name'));
        $name = $this->normalize_backup_name($backup_name_raw);
        $token = UWHSM_Compat::get_value('download_token');
        if (!$token || !hash_equals($this->download_token($name), $token)) {
            wp_die(esc_html__('Invalid download authorization. Refresh the Site Migrator page and try again.', 'uwh-site-migrator'), 403);
        }
        try {
            $record = $this->completed_backup($name);
            $path = $record['path'];
        } catch (Throwable $e) {
            wp_die(esc_html__('Completed backup not found.', 'uwh-site-migrator'), 404);
        }
        @ignore_user_abort(true);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
        @set_time_limit(0);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Disabling output compression is required for byte-accurate resumable backup downloads.
        @ini_set('zlib.output_compression', 'Off');
        if (function_exists('session_write_close')) {
            @session_write_close();
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        $size = (int) filesize($path);
        $start = 0;
        $end = $size-1;
        $status = 200;
        $range = UWHSM_Compat::server_value('HTTP_RANGE');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $length = min($size, (int) $m[2]);
                $start = $size-$length;
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int) $m[2]);
                }
            }
            if ($start < 0 || $start > $end || $start >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
        }
        status_header($status);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Accept-Ranges: bytes');
        header('X-Accel-Buffering: no');
        if (206 === $status) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end-$start+1));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $fh = fopen($path, 'rb');
        if (!$fh) {
            wp_die(esc_html__('Could not open the backup file.', 'uwh-site-migrator'), 500);
        }
        fseek($fh, $start);
        $remaining = $end-$start+1;
        while ($remaining > 0 && !feof($fh)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streaming is required to transfer large backup archives without loading them fully into memory.
            $chunk = fread($fh, min(1024*1024, $remaining));
            if (false === $chunk) {
                break;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw output is a binary/plain-text download stream; escaping would corrupt the downloaded content.
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        fclose($fh);
        exit;
    }

    public function uwhsm_delete_backup() {
        $this->guard();
        $backup_name_raw = sanitize_file_name(UWHSM_Compat::post_value('backup_name'));
        if ('' === $backup_name_raw) {
            $backup_name_raw = sanitize_file_name(UWHSM_Compat::post_value('file'));
        }
        if ('' === $backup_name_raw) {
            $backup_name_raw = sanitize_file_name(UWHSM_Compat::post_value('name'));
        }

        $backup_name_raw = is_scalar( $backup_name_raw ) ? (string) $backup_name_raw : '';
        $name            = $this->normalize_backup_name( $backup_name_raw );
        if (!$this->valid_backup_name($name)) {
            $this->fail(__('Invalid backup name.', 'uwh-site-migrator'), 400);
        }
        if (class_exists('UWHSM_Site_Transfer') && UWHSM_Site_Transfer::backup_is_shared($name)) {
            $this->fail(__('This backup is currently shared by an active Direct Migration key. Revoke the key before deleting the backup.', 'uwh-site-migrator'), 409);
            return;
        }
        foreach ((array) glob($this->base . '/job-*.json') as $restore_file) {
            $restore = json_decode((string) @file_get_contents($restore_file), true);
            if (is_array($restore) && ($restore['type'] ?? '') === 'restore' && ($restore['source_mode'] ?? '') === 'local' && ($restore['phase'] ?? '') !== 'done' && hash_equals((string) ($restore['archive_name'] ?? ''), $name)) {
                $this->fail(__('This backup is the source of an unfinished restore. Resume or finish that restore before deleting it.', 'uwh-site-migrator'), 409);
                return;
            }
        }
        $path = '';
        foreach ((array) glob($this->backup_dir . '/*.uwh') as $candidate) {
            if (hash_equals(basename($candidate), $name)) {
                $path = $candidate;
                break;
            }
        }
        $cancelled = 0;
        $pending = 0;
        foreach ((array) glob($this->base . '/job-*.json') as $state_file) {
            $state = json_decode((string) @file_get_contents($state_file), true);
            if (!is_array($state) || empty($state['zip']) || !hash_equals(basename($state['zip']), $name)) {
                continue;
            }
            $job = (string) ($state['job'] ?? '');
            $lock = false;
            $lock_path = '';
            if ($this->safe_id($job)) {
                @file_put_contents($this->cancel_path($job), '1', LOCK_EX);
                $lock_path = $this->lock_path($job);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                $lock = @fopen($lock_path, 'c');
                if (!$lock) {
                    $this->fail(__('Cannot lock backup for deletion. Check storage permissions.', 'uwh-site-migrator'), 500);
                }
                if (!@flock($lock, LOCK_EX|LOCK_NB)) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                    @fclose($lock);
                    $pending++;
                    continue;
                }
            }
            if (!empty($state['db_stage'])) {
                $this->remove_tree($state['db_stage']);
                if (is_dir($state['db_stage'])) {
                    $this->fail(__('Temporary files could not be removed. Check file ownership and permissions.', 'uwh-site-migrator'), 500);
                }
            }
            wp_delete_file($state_file);
            wp_delete_file($state_file . '.tmp');
            if ($this->safe_id($job)) {
                wp_delete_file($this->cancel_path($job));
                if ($lock) {
                    @flock($lock, LOCK_UN);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                    @fclose($lock);
                }
                wp_delete_file($lock_path);
            }
            $cancelled++;
        }
        $target = $path?:$this->backup_dir . '/' . $name;
        if (!$pending && is_file($target) && !wp_delete_file($target)) {
            $this->fail(__('The backup exists but could not be deleted. Check file ownership/permissions.', 'uwh-site-migrator'), 500);
        }
        if (!$pending) {
            wp_delete_file($target . '.json');
        }
        UWHSM_Logger::log('backup', 'info', $pending ? __('Backup deletion is waiting for a running job.', 'uwh-site-migrator') : __('Backup deleted.', 'uwh-site-migrator'), array('backup' => $name, 'message' => $pending ? __('Deletion pending', 'uwh-site-migrator') : __('Deleted', 'uwh-site-migrator')));
        wp_send_json_success(array('removed' => !$pending, 'pending' => (bool) $pending, 'cancelled_jobs' => $cancelled, 'already_missing' => !$path && !$pending));
    }

    private function normalize_backup_name($name) {
        if (is_array($name) || is_object($name)) {
            return '';
        }
        // Validate the exact generated .uwh backup/checkpoint name.
        $name = trim((string) wp_unslash($name));
        return $this->valid_backup_name($name) ? $name : '';
    }

    private function valid_backup_name($name) {
        return is_string($name) && (bool) preg_match('/^[a-z0-9_.-]+\.uwh\z/i', $name);
    }
}
