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
    /**
     * A custom storage override must never target the web root, a WordPress
     * runtime directory, or one of their parent directories. Otherwise our
     * private-backup .htaccess could deny access to the entire website.
     * Existing valid custom storage locations remain supported.
     */
    private static function physical_storage_path($path) {
        $path = untrailingslashit(wp_normalize_path((string) $path));
        $missing = array();
        $probe = $path;
        while ('' !== $probe && !file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            array_unshift($missing, basename($probe));
            $probe = $parent;
        }
        $real = realpath($probe);
        return false !== $real ? untrailingslashit(wp_normalize_path($real . ($missing ? '/' . implode('/', $missing) : ''))) : $path;
    }

    private static function safe_storage_base($candidate, $uploads_base) {
        if (!is_string($candidate) || '' === trim($candidate)) {
            return false;
        }
        $candidate = wp_normalize_path(trim($candidate));
        if (!preg_match('#^(?:/|[A-Za-z]:/)#', $candidate) || preg_match('#(?:^|/)\.\.?(?:/|$)#', $candidate)) {
            return false;
        }
        $candidate = self::physical_storage_path($candidate);
        $core = self::physical_storage_path(UWHSM_Paths::wordpress_root());
        $content = self::physical_storage_path(UWHSM_Paths::content_root());
        $uploads = self::physical_storage_path($uploads_base);
        $plugins = self::physical_storage_path(UWHSM_Paths::plugin_root());
        // Reject roots and ancestors: a deny-all file there could block WordPress.
        foreach (array($core, $content, $uploads, $plugins) as $protected) {
            if ($candidate === $protected || 0 === strpos($protected, trailingslashit($candidate))) {
                return false;
            }
        }
        // Even a nested directory in WordPress core / installed plugins must not
        // be used as a repository for private backup data.
        foreach (array($core . '/wp-admin', $core . '/wp-includes', $plugins, $content . '/themes') as $code_root) {
            if ($candidate === $code_root || 0 === strpos($candidate, trailingslashit($code_root))) {
                return false;
            }
        }
        return true;
    }

    public static function notify_unsafe_storage_override() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('Site Migrator ignored an unsafe custom backup-storage location and is using its private directory in WordPress uploads. Please update UWHSM_STORAGE_PATH (or the legacy SVM_STORAGE_PATH) to a dedicated directory; old files were not deleted.', 'uwh-site-migrator') . '</p></div>';
    }

    private $batch_files = 1000;
    private $batch_rows = 250; // Bound DB batch memory/time on shared hosting; resumable cursor/offset stays compatible.

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
        if (!is_array($uploads) || !empty($uploads['error']) || empty($uploads['basedir'])) {
            throw new RuntimeException(esc_html__('WordPress uploads directory is unavailable; Site Migrator cannot initialize protected backup storage.', 'uwh-site-migrator'));
        }
        $default_base = trailingslashit($uploads['basedir']) . 'uwh-site-migrator';
        $legacy_bases = array(
            trailingslashit($uploads['basedir']) . 'site-migrator',
            trailingslashit($uploads['basedir']) . 'site-vault-migrator',
        );
        $configured_base = defined('UWHSM_STORAGE_PATH') ? UWHSM_STORAGE_PATH : $default_base;
        $override_is_safe = self::safe_storage_base($configured_base, $uploads['basedir']);
        // Never create .htaccess in the WordPress root, wp-content root, or
        // uploads root, even if a legacy override accidentally points there.
        if (!$override_is_safe && !self::safe_storage_base($default_base, $uploads['basedir'])) {
            throw new RuntimeException(esc_html__('Site Migrator backup storage resolves to a protected WordPress directory. Fix the uploads directory or symbolic link before enabling backups.', 'uwh-site-migrator'));
        }
        $this->base = $override_is_safe ? $configured_base : $default_base;
        if (!$override_is_safe) {
            add_action('admin_notices', array(__CLASS__, 'notify_unsafe_storage_override'));
        }
        if (!defined('UWHSM_STORAGE_PATH') && !is_dir($default_base)) {
            foreach ($legacy_bases as $legacy_base) {
                if (!is_dir($legacy_base) || !self::safe_storage_base($legacy_base, $uploads['basedir'])) {
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
        foreach (array('uwhsm_backup_start', 'uwhsm_backup_step', 'uwhsm_backup_status', 'uwhsm_upload_start', 'uwhsm_upload_chunk', 'uwhsm_restore_start', 'uwhsm_restore_local_start', 'uwhsm_restore_checkpoint_finish', 'uwhsm_restore_recover', 'uwhsm_restore_cancel', 'uwhsm_restore_step', 'uwhsm_restore_status', 'uwhsm_checkpoint_rollback_start', 'uwhsm_checkpoint_delete', 'uwhsm_delete_backup') as $action) {
            add_action('wp_ajax_' . $action, array($this, $action));
        }



        // Auto Backup er AJAX ebong Cron hooks autobackup
        add_action('wp_ajax_uwhsm_save_auto_backup', array($this, 'uwhsm_save_auto_backup'));
        add_filter('cron_schedules', array($this, 'uwhsm_custom_cron_schedules'));
        add_action('uwhsm_auto_backup_event', array($this, 'run_auto_backup'));
        // WordPress can lose the cron array after a restore, migration or a host
        // cleanup. Re-register only a configured, missing event (not on each page).
        add_action('init', array($this, 'maybe_repair_auto_backup_schedule'), 20);



        // Restore may replace users and authentication cookies; the unprivileged route remains protected by a 256-bit per-job token.
        add_action('wp_ajax_nopriv_uwhsm_restore_step', array($this, 'uwhsm_restore_step'));
        add_action('wp_ajax_nopriv_uwhsm_restore_status', array($this, 'uwhsm_restore_status'));
        add_action('wp_ajax_nopriv_uwhsm_restore_recovery_page', array($this, 'restore_recovery_page'));
        add_action('wp_ajax_uwhsm_restore_recovery_page', array($this, 'restore_recovery_page'));
        add_action('admin_post_uwhsm_download', array($this, 'download'));
        add_action('template_redirect', array($this, 'maybe_block_frontend_during_restore'), 0);
        add_filter('login_message', array($this, 'restore_login_message'));
        add_action('init', array($this, 'maybe_run_post_restore_repairs'), 100);
        // Elementor may finish initializing after init on some plugin stacks. Retry
        // the pending one-time cleanup at wp_loaded without doing duplicate work.
        add_action('wp_loaded', array($this, 'maybe_run_post_restore_repairs'), 20);
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

    /** Return actionable information for the Troubleshooting screen. */
    public function maintenance_status() {
        $guard = get_option('uwhsm_restore_guard', array());
        $id = is_array($guard) ? (string) ($guard['restore_id'] ?? '') : '';
        $core = trailingslashit(UWHSM_Paths::wordpress_root()) . '.maintenance';
        $core_present = is_file($core) && !is_link($core);
        $core_stale = false;
        if ($core_present) {
            // Only WordPress's simple timestamp lock is eligible for stale cleanup.
            $body = @file_get_contents($core, false, null, 0, 512);
            $core_stale = is_string($body) && preg_match('/\$upgrading\s*=\s*([0-9]{9,12})\s*;/', $body, $matches)
                && time() - (int) $matches[1] > 600
                && time() - (int) @filemtime($core) > 600;
        }
        return array(
            'manual' => (bool) get_option('uwhsm_manual_maintenance', false),
            'restore' => $this->safe_id($id),
            'restore_id' => $this->safe_id($id) ? $id : '',
            'core' => $core_present,
            'core_stale' => (bool) $core_stale,
        );
    }

    /** Explicit admin maintenance override; never touch a currently locked restore batch. */
    public function set_maintenance_mode($enabled) {
        if ($enabled) {
            update_option('uwhsm_manual_maintenance', '1', false);
            return true;
        }
        $status = $this->maintenance_status();
        if ($status['core'] && !$status['core_stale']) {
            return new WP_Error('core_maintenance_active', __('WordPress core maintenance is recent or unrecognized. It was not removed; check hosting updates.', 'uwh-site-migrator'));
        }
        if ($status['restore']) {
            $id = $status['restore_id'];
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A real stream handle is required for flock() so a running restore cannot be interrupted.
            $handle = @fopen($this->lock_path($id), 'c');
            if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
                if ($handle) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the flock() stream acquired above.
                    fclose($handle);
                }
                return new WP_Error('restore_busy', __('Restore is currently running. Stop the active transfer before turning maintenance off.', 'uwh-site-migrator'));
            }
            try {
                $state = $this->read_state($id);
                if (is_array($state) && 'done' !== ($state['phase'] ?? '')) {
                    if ('failed' !== ($state['phase'] ?? '')) {
                        $state['failed_phase'] = (string) ($state['phase'] ?? '');
                        $state['phase'] = 'failed';
                        $state['last_error'] = __('Restore stopped by an administrator from Troubleshooting.', 'uwh-site-migrator');
                    }
                    $state['maintenance_manually_released'] = true;
                    $state['recovery_available'] = false;
                    $this->write_state($id, $state);
                    // Restore old plugin activation only if the guard still has
                    // the singleton lockdown and a destination snapshot exists.
                    $self = plugin_basename(UWHSM_FILE);
                    $previous = $state['destination_active_plugins_before_restore'] ?? null;
                    if (get_option('active_plugins', array()) === array($self) && is_array($previous) && in_array($self, $previous, true)) {
                        $valid = array_filter($previous, static function($plugin) {
                            return is_string($plugin) && false === strpos($plugin, '..') && is_file(WP_PLUGIN_DIR . '/' . $plugin);
                        });
                        if ($valid) { update_option('active_plugins', array_values($valid)); }
                    }
                }
                $this->clear_restore_guard();
            } catch (Throwable $error) {
                flock($handle, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the PHP stream after releasing the restore lock.
                fclose($handle);
                return new WP_Error('maintenance_release_failed', $error->getMessage());
            }
            flock($handle, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the PHP stream after releasing the restore lock.
            fclose($handle);
        }
        // WordPress core maintenance can be removed only after its ordinary
        // upgrade lock has expired. Never interfere with a running core update.
        if ($status['core_stale']) {
            $core = trailingslashit(UWHSM_Paths::wordpress_root()) . '.maintenance';
            // WordPress' deletion API is filterable and intentionally has no return value.
            // Check that the actual stale maintenance file is gone, not the return value.
            wp_delete_file($core);
            clearstatcache(true, $core);
            if (file_exists($core) || is_link($core)) {
                return new WP_Error('core_maintenance_delete', __('The old WordPress maintenance file could not be removed. Check filesystem permissions.', 'uwh-site-migrator'));
            }
        }
        delete_option('uwhsm_manual_maintenance');
        UWHSM_Logger::log('restore', 'info', __('Site Migrator maintenance was disabled by an administrator.', 'uwh-site-migrator'));
        return true;
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
        if (get_option('uwhsm_manual_maintenance', false)) {
            status_header(503);
            nocache_headers();
            if (!headers_sent()) { header('Retry-After: 60'); }
            wp_die(esc_html__('This website is temporarily in maintenance mode. Please check back shortly.', 'uwh-site-migrator'), esc_html__('Maintenance mode', 'uwh-site-migrator'), array('response' => 503));
        }
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
        $show_recovery = is_array($state) && 'failed' !== ($state['phase'] ?? '')
            && !(isset($state['checkpoint_enabled']) && !$state['checkpoint_enabled'] && !empty($state['last_error']) && empty($state['checkpoint_name']));
        $recovery_url = admin_url('admin-ajax.php?action=uwhsm_restore_recovery_page');
        $message = '<p>' . esc_html__('Website migration is paused or in progress. Your data is not automatically deleted.', 'uwh-site-migrator') . '</p>';
        if ($show_recovery) {
            $message .= '<p><a href="' . esc_url($recovery_url) . '">' . esc_html__('Resume a restore saved in this browser', 'uwh-site-migrator') . '</a></p>';
        }
        $message .= '<p>' . esc_html__('Contact the site administrator if maintenance remains active. A disabled safety checkpoint cannot be rolled back automatically.', 'uwh-site-migrator') . '</p>';
        wp_die($message, esc_html__('Site Migrator maintenance', 'uwh-site-migrator'), array('response' => 503)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is composed solely of escaped strings and URLs.
    }

    /** Give a signed-out administrator the same browser-local recovery path. */
    public function restore_login_message($message) {
        $guard = get_option('uwhsm_restore_guard', array());
        if (!is_array($guard) || !$this->safe_id((string) ($guard['restore_id'] ?? ''))) {
            return $message;
        }
        $state = $this->read_state((string) $guard['restore_id']);
        if (is_array($state) && empty($state['checkpoint_enabled'])
            && ('failed' === ($state['phase'] ?? '') || !empty($state['last_error']))) {
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
        // Standalone recovery page still registers its script through WordPress.
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

    /**
     * Re-create a missing automatic Drive backup event without changing the
     * user's saved Daily/Weekly/Monthly choice or starting another backup.
     *
     * Frontend checks are throttled to avoid reading the cron option on every
     * request. The Drive Settings screen can force a fresh check so users see
     * the current next-run timestamp after opening the page.
     *
     * @param bool $force Bypass the short throttle for the settings screen.
     * @return bool True when a matching event exists or was repaired.
     */
    public function maybe_repair_auto_backup_schedule($force = false) {
        if (is_multisite() || wp_doing_ajax() || (function_exists('wp_doing_cron') && wp_doing_cron())) {
            return false;
        }

        $frequency = (string) get_option('uwhsm_auto_backup_frequency', 'none');
        if (!in_array($frequency, array('daily', 'weekly', 'monthly'), true)) {
            return false;
        }

        // Do not write to wp_options/cron while a resumable database restore
        // is replacing the options table. Resume/completion will clear the guard.
        $guard = get_option('uwhsm_restore_guard', array());
        if (is_array($guard) && !empty($guard['restore_id'])) {
            return false;
        }

        if (!$force && false !== get_transient('uwhsm_auto_cron_recheck')) {
            return false;
        }

        $hook = 'uwhsm_auto_backup_event';
        $existing = wp_get_scheduled_event($hook);
        if ($existing && $existing->schedule === $frequency) {
            set_transient('uwhsm_auto_cron_recheck', '1', 300);
            return true;
        }
        if ($existing) {
            // Preserve an existing event even if its recurrence differs: do not
            // discard scheduled work without the user explicitly saving settings.
            UWHSM_Logger::log_once(
                'auto-cron-frequency-mismatch', 'schedule', 'warning',
                __('Automatic backup schedule differs from saved settings. Save Auto Backup Settings to update the recurrence.', 'uwh-site-migrator'),
                array('frequency' => $frequency), 3600
            );
            set_transient('uwhsm_auto_cron_recheck', '1', 300);
            return false;
        }

        $backup_time = (string) get_option('uwhsm_auto_backup_time', '03:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $backup_time)) {
            $backup_time = '03:00';
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
            $next_run = self::calculate_auto_backup_first_run($backup_time, $timezone);
            $result = wp_schedule_event($next_run, $frequency, $hook, array(), true);
            if (is_wp_error($result) || !$result || !wp_next_scheduled($hook)) {
                $reason = is_wp_error($result) ? $result->get_error_message() : __('WordPress did not persist the scheduled event.', 'uwh-site-migrator');
                UWHSM_Logger::log_once(
                    'auto-cron-repair-failed', 'schedule', 'failed',
                    __('Automatic backup event could not be restored.', 'uwh-site-migrator'),
                    array('frequency' => $frequency, 'error' => $reason), 900
                );
                set_transient('uwhsm_auto_cron_recheck', '1', 300);
                return false;
            }
            UWHSM_Logger::log('schedule', 'success', __('Missing automatic backup event was restored.', 'uwh-site-migrator'), array('frequency' => $frequency, 'next_run' => (int) wp_next_scheduled($hook)));
            set_transient('uwhsm_auto_cron_recheck', '1', 300);
            return true;
        } catch (Throwable $error) {
            UWHSM_Logger::log_once(
                'auto-cron-repair-exception', 'schedule', 'failed',
                __('Automatic backup event recovery failed.', 'uwh-site-migrator'),
                array('frequency' => $frequency, 'error' => $error->getMessage()), 900
            );
            set_transient('uwhsm_auto_cron_recheck', '1', 300);
            return false;
        }
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
        delete_transient('uwhsm_auto_cron_recheck');

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

        $scheduled = wp_schedule_event($first_run, $frequency, 'uwhsm_auto_backup_event', array(), true);
        $next_run = wp_next_scheduled('uwhsm_auto_backup_event');

        if (is_wp_error($scheduled) || !$scheduled || !$next_run) {
            UWHSM_Logger::log('schedule', 'failed', __('Automatic backup schedule could not be registered.', 'uwh-site-migrator'), array('frequency' => $frequency, 'error' => is_wp_error($scheduled) ? $scheduled->get_error_message() : __('WP-Cron event registration failed', 'uwh-site-migrator')));
            // Keep the saved preference visible in the UI, but report that cron registration failed.
            wp_send_json_error(array(
                'message' => sprintf(
                /* translators: %s: automatic backup frequency. */
                __('The setting was saved as %s, but WordPress could not register the WP-Cron event. Check cron storage and server error logs. DISABLE_WP_CRON does not prevent registration; it only prevents visitor-triggered execution.', 'uwh-site-migrator'),
                array('daily' => __('Daily', 'uwh-site-migrator'), 'weekly' => __('Weekly', 'uwh-site-migrator'), 'monthly' => __('Monthly', 'uwh-site-migrator'))[$frequency] ?? $frequency
                ),
                'frequency' => $frequency,
                'backup_time' => $backup_time,
                'drive_retention' => $drive_retention,
                ), 500);
            return;
        }

        set_transient('uwhsm_auto_cron_recheck', '1', 300);
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
            if (!class_exists('UWHSM_Drive')) {
                throw new RuntimeException(__('Google Drive integration is unavailable.', 'uwh-site-migrator'));
            }
            $drive = UWHSM_Drive::status();
            if (empty($drive['connected'])) {
                throw new RuntimeException(__('Google Drive is disconnected; scheduled backup did not start.', 'uwh-site-migrator'));
            }
            UWHSM_Drive::site_identifier();
        } catch (Throwable $e) {
            UWHSM_Logger::log_once('auto-drive-not-ready-' . md5($e->getMessage()), 'schedule', 'warning', __('Scheduled backup skipped: Google Drive is not ready.', 'uwh-site-migrator'), array('error' => $e->getMessage()), 3600);
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
                throw new RuntimeException(__('Could not open the scheduled backup archive for writing.', 'uwh-site-migrator'));
            }

            $tables = $this->collect_tables();
            $source_theme_settings = $this->source_active_theme_settings();
            $manifest = array(
                'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
                'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
                'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix, 'database_scope' => 'wordpress_prefix_tables', 'database_tables' => $tables,
                'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION, 'active_plugins' => get_option('active_plugins', array()), 'active_theme_stylesheet' => $source_theme_settings['stylesheet'], 'active_theme_template' => $source_theme_settings['template'], 'front_page_settings' => $this->source_front_page_settings(), 'site_identity_settings' => $this->source_site_identity(), 'branding_media' => $this->source_branding_media($source_theme_settings['stylesheet']), 'builder_template_ids' => $this->source_builder_templates(),
            );

            $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();

            $files = $this->collect_files();
        $this->verify_branding_inventory((array) ($manifest['branding_media'] ?? array()), $files);
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
        // Match the same WordPress-prefix scope as the actual backup inventory.
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Estimate only the WordPress site's own database tables.
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $like), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not estimate the WordPress database backup size.', 'uwh-site-migrator'));
        }
        $size = 0;
        $count = 0;
        foreach ((array) $rows as $row) {
            $size += (int) ($row['Data_length'] ?? 0)+(int) ($row['Index_length'] ?? 0);
            $count += max(0, (int) ($row['Rows'] ?? 0));
        }
        return array('size' => $size, 'rows' => $count);
    }

    /**
     * Read the authoritative source Reading Settings directly from the database.
     * These values become a cross-check against the rows actually imported,
     * instead of trusting an apparent 100% progress bar alone.
     *
     * @return array<string,string>
     */
    /**
     * Snapshot the actual active theme settings from wp_options, not from a
     * potentially stale persistent object cache. Backups use these values to
     * prevent a new destination from falling back to its previously active theme.
     *
     * @return array<string,string>
     */
    private function source_active_theme_settings() {
        global $wpdb;
        $options = $wpdb->prefix . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Backup records the physical source theme options for post-import integrity checks.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s)', $options, 'stylesheet', 'template'),
            ARRAY_A
        );
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not read the source active theme for backup.', 'uwh-site-migrator'));
        }
        $settings = array('stylesheet' => '', 'template' => '');
        foreach ($rows as $row) {
            $key = (string) ($row['option_name'] ?? '');
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) ($row['option_value'] ?? '');
            }
        }
        foreach ($settings as $theme) {
            if (!$this->safe_theme_slug($theme)) {
                throw new RuntimeException(esc_html__('Source WordPress active theme settings are missing or unsafe.', 'uwh-site-migrator'));
            }
        }
        return $settings;
    }

    /**
     * Snapshot the source site's visible identity and Elementor active kit from
     * the physical options table. These are checked against the archived rows
     * before modifying any destination database table.
     */
    private function source_site_identity() {
        global $wpdb;
        $options = $wpdb->prefix . 'options';
        $wanted = array('blogname', 'blogdescription', 'site_icon', 'site_logo', 'elementor_active_kit', 'permalink_structure', 'category_base', 'tag_base');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A migration snapshot must bypass potentially stale option caches.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s, %s, %s, %s, %s, %s)', $options, $wanted[0], $wanted[1], $wanted[2], $wanted[3], $wanted[4], $wanted[5], $wanted[6], $wanted[7]),
            ARRAY_A
        );
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not read source site identity settings.', 'uwh-site-migrator'));
        }
        $result = array();
        foreach ($rows as $row) {
            $key = (string) ($row['option_name'] ?? '');
            if (in_array($key, $wanted, true)) {
                $result[$key] = (string) ($row['option_value'] ?? '');
            }
        }
        if (!array_key_exists('blogname', $result)) {
            throw new RuntimeException(esc_html__('Source site title is missing from WordPress options.', 'uwh-site-migrator'));
        }
        return $result;
    }

    /**
     * Snapshot media references behind the WordPress custom logo, site icon,
     * and Elementor Site Identity logo. A restored attachment record without
     * its real uploads file produces the blank logo reported on migrated sites.
     * Read the authoritative source rows rather than a cached theme-mod value.
     *
     * @return array<string,array{id:int,file:string}>
     */
    private function source_branding_media($stylesheet) {
        global $wpdb;
        $options = $wpdb->prefix . 'options';
        $posts = $wpdb->prefix . 'posts';
        $postmeta = $wpdb->prefix . 'postmeta';
        $references = array();
        $stylesheet = (string) $stylesheet;
        if ($this->safe_theme_slug($stylesheet)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Source branding must be read from the physical source database.
            $mods = $wpdb->get_row($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $options, 'theme_mods_' . $stylesheet), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Could not read source custom logo settings.', 'uwh-site-migrator'));
            }
            $value = is_array($mods) ? maybe_unserialize($mods['option_value']) : array();
            if (is_array($value) && !empty($value['custom_logo'])) {
                $references['custom_logo'] = absint($value['custom_logo']);
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Source icon and kit IDs are migration identifiers, not cached display values.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT option_name, option_value FROM %i WHERE option_name IN (%s,%s,%s)', $options, 'site_icon', 'site_logo', 'elementor_active_kit'), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not read source branding references.', 'uwh-site-migrator'));
        }
        $kit = 0;
        foreach ($rows as $row) {
            if ('site_icon' === $row['option_name'] && (int) $row['option_value'] > 0) {
                $references['site_icon'] = absint($row['option_value']);
            } elseif ('site_logo' === $row['option_name'] && (int) $row['option_value'] > 0) {
                // WordPress core site_logo can override the theme's custom_logo.
                $references['core_site_logo'] = absint($row['option_value']);
            } elseif ('elementor_active_kit' === $row['option_name']) {
                $kit = absint($row['option_value']);
            }
        }
        if ($kit > 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect the source Elementor kit's independently stored Site Identity image IDs.
            $settings = $wpdb->get_row($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $postmeta, $kit, '_elementor_page_settings'), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Could not inspect Elementor Site Identity settings.', 'uwh-site-migrator'));
            }
            $kit_settings = is_array($settings) ? maybe_unserialize($settings['meta_value']) : array();
            if (is_array($kit_settings)) {
                foreach (array('site_logo' => 'elementor_site_logo', 'site_favicon' => 'elementor_site_favicon') as $field => $key) {
                    $image = $kit_settings[$field] ?? null;
                    if (is_array($image) && !empty($image['id']) && (int) $image['id'] > 0) {
                        $references[$key] = absint($image['id']);
                    }
                }
            }
        }
        $result = array();
        foreach ($references as $kind => $id) {
            if ($id < 1) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Source logo must resolve to a real media-library attachment.
            $attachment = $wpdb->get_row($wpdb->prepare('SELECT post_type FROM %i WHERE ID = %d LIMIT 1', $posts, $id), ARRAY_A);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The file path is part of the source attachment record.
            $media = $wpdb->get_row($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $postmeta, $id, '_wp_attached_file'), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Could not inspect source Media Library attachments.', 'uwh-site-migrator'));
            }
            if (!is_array($attachment) || 'attachment' !== $attachment['post_type'] || !is_array($media)) {
                continue; // Optional branding reference was removed from Media Library.
            }
            $relative = wp_normalize_path((string) $media['meta_value']);
            if ('' === $relative || !$this->safe_entry($relative) || '/' === $relative[0] || strpos($relative, ':') !== false) {
                continue; // Optional logo path is missing or unsupported.
            }
            $file = UWHSM_Paths::upload_root() . $relative;
            if (!is_file($file) || !is_readable($file)) {
                continue; // Do not abort a whole backup for an absent optional logo.
            }
            $result[$kind] = array('id' => $id, 'file' => $relative);
        }
        return $result;
    }

    /** Require branded media to be part of the *real* backup file inventory. */
    private function verify_branding_inventory(array $branding, array $files) {
        if (!$branding) {
            return;
        }
        $names = array();
        foreach ($files as $file) {
            $names[(string) $file['rel']] = true;
        }
        foreach ($branding as $kind => $ref) {
            $entry = 'wp-content/uploads/' . $ref['file'];
            if (empty($names[$entry])) {
                throw new RuntimeException(esc_html__('Logo/icon is outside the backup file inventory: ', 'uwh-site-migrator') . esc_html($entry));
            }
        }
    }

    /**
     * Verify the destination can render the same image after migration. Do not
     * silently replace a missing brand image with the destination's default.
     * Older archives simply retain their established restore behavior.
     */
    private function verify_restored_branding_media(array $state) {
        global $wpdb;
        $branding = $state['manifest']['branding_media'] ?? null;
        if (!is_array($branding)) {
            return;
        }
        $posts = (string) $state['new_prefix'] . 'posts';
        $meta = (string) $state['new_prefix'] . 'postmeta';
        foreach ($branding as $kind => $ref) {
            if (!is_array($ref) || empty($ref['id']) || empty($ref['file'])) {
                continue;
            }
            $id = absint($ref['id']);
            $relative = wp_normalize_path((string) $ref['file']);
            if ($id < 1 || '' === $relative || !$this->safe_entry($relative) || '/' === $relative[0] || strpos($relative, ':') !== false) {
                continue;
            }
            $entry = 'site/wp-content/uploads/' . $relative;
            $check = $state['integrity']['files'][$entry] ?? null;
            $file = UWHSM_Paths::upload_root() . $relative;
            if (!is_array($check) || !is_file($file)) {
                continue; // A logo omitted from the package is optional.
            }
            if (empty($check['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $check['sha256'])
                || !hash_equals($check['sha256'], (string) hash_file('sha256', $file))) {
                throw new RuntimeException(esc_html__('Migrated site logo/icon file is missing or differs from the backup: ', 'uwh-site-migrator') . esc_html($relative));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm source attachment ID survived the restored media tables.
            $post = $wpdb->get_row($wpdb->prepare('SELECT post_type FROM %i WHERE ID = %d LIMIT 1', $posts, $id), ARRAY_A);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the attachment still refers to the same image file.
            $attached = $wpdb->get_row($wpdb->prepare('SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $meta, $id, '_wp_attached_file'), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Could not verify restored media tables.', 'uwh-site-migrator'));
            }
            if (!is_array($post) || 'attachment' !== $post['post_type'] || !is_array($attached)
                || wp_normalize_path((string) $attached['meta_value']) !== $relative) {
                continue; // Optional logo metadata is absent; do not fail migration.
            }
        }
    }

    /**
     * Prevent temporary theme initialization from clearing the restored logo.
     * Only a custom_logo ID independently captured during source backup can
     * be reapplied. Never fabricate media library references.
     */
    private function reconcile_restored_custom_logo(array $state) {
        global $wpdb;
        $branding = (array) ($state['manifest']['branding_media'] ?? array());
        if (empty($branding['custom_logo']['id']) || empty($branding['custom_logo']['file'])
            || empty($state['integrity']['files']['site/wp-content/uploads/' . $branding['custom_logo']['file']])
            || !is_file(UWHSM_Paths::upload_root() . $branding['custom_logo']['file'])) {
            return;
        }
        $slug = (string) ($state['manifest']['active_theme_stylesheet'] ?? '');
        if (!$this->safe_theme_slug($slug)) {
            throw new RuntimeException(esc_html__('Cannot verify restored custom logo theme.', 'uwh-site-migrator'));
        }
        $table = (string) $state['new_prefix'] . 'options';
        $option = 'theme_mods_' . $slug;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore the source logo ID after database migration, bypassing a stale option cache.
        $row = $wpdb->get_row($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $option), ARRAY_A);
        if ($wpdb->last_error) {
            throw new RuntimeException(esc_html__('Could not query restored theme settings.', 'uwh-site-migrator'));
        }
        // Some themes do not create theme_mods until first customization.
        // A verified archive logo can populate the missing option safely.
        if (!is_array($row)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert only an archive-verified optional custom logo into the restored options table, not the current-site options cache.
            $inserted = $wpdb->insert($table, array('option_name' => $option, 'option_value' => maybe_serialize(array('custom_logo' => absint($branding['custom_logo']['id']))), 'autoload' => 'no'));
            if (false !== $inserted) {
                wp_cache_delete($option, 'options');
                wp_cache_delete('notoptions', 'options');
                return;
            }
            // Logo configuration is optional; never fail the whole import.
            return;
        }
        $mods = maybe_unserialize($row['option_value']);
        if (!is_array($mods)) {
            return; // Missing/invalid optional theme mods must not block restore.
        }
        $expected = (int) $branding['custom_logo']['id'];
        if ((int) ($mods['custom_logo'] ?? 0) !== $expected) {
            $mods['custom_logo'] = $expected;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reapply only the archive-verified custom logo attachment ID.
            if (false === $wpdb->update($table, array('option_value' => maybe_serialize($mods)), array('option_name' => $option))) {
                return; // Preserve imported site even if its optional logo setting cannot be written.
            }
            wp_cache_delete($option, 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }

    /**
     * Elementor Site Identity stores both an attachment ID and an image URL.
     * The original media file and ID can migrate correctly while a stale kit
     * URL makes the Site Logo widget appear blank on the new domain.
     * Repair only the independently source-verified attachment reference.
     */
    /**
     * Avoid changing a healthy source logo reference just because the image
     * has a custom CDN/media-offload URL. Only blank or unchanged old-domain
     * references need a destination-local URL after a cross-domain import.
     * The ordinary serialized/JSON URL rewriter has already run at this point.
     */
    private static function logo_url_requires_repair($current_url, array $state) {
        $current_url = trim((string) $current_url);
        if ('' === $current_url) {
            return true;
        }
        foreach (array('home_url', 'site_url') as $key) {
            $old = untrailingslashit((string) ($state['manifest'][$key] ?? ''));
            $new = untrailingslashit((string) ($state['new_' . $key] ?? ''));
            if ('' !== $old && $old !== $new && ($current_url === $old || 0 === strpos($current_url, $old . '/'))) {
                return true;
            }
        }
        return false;
    }

    private function reconcile_restored_elementor_logo(array $state) {
        global $wpdb;
        $branding = (array) ($state['manifest']['branding_media'] ?? array());
        $ref = $branding['elementor_site_logo'] ?? null;
        if (!is_array($ref) || empty($ref['id']) || empty($ref['file'])
            || empty($state['integrity']['files']['site/wp-content/uploads/' . $ref['file']])
            || !is_file(UWHSM_Paths::upload_root() . $ref['file'])) {
            return; // Missing optional Elementor logo; leave imported settings unchanged.
        }
        $expected_id = absint($ref['id']);
        $identity = (array) ($state['manifest']['site_identity_settings'] ?? array());
        $kit = absint($identity['elementor_active_kit'] ?? 0);
        $uploads_url = untrailingslashit((string) ($state['new_uploads_url'] ?? ''));
        if ($expected_id < 1 || $kit < 1 || !preg_match('#^https?://[^/]+(?:/|$)#i', $uploads_url)) {
            return; // No resolvable Elementor logo configuration to migrate.
        }
        $table = (string) $state['new_prefix'] . 'postmeta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the imported kit settings directly after a cross-domain restore.
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the one indexed kit settings row by post_id/meta_key; meta_value is SELECTed, never filtered.
        $row = $wpdb->get_row($wpdb->prepare('SELECT meta_id, meta_value FROM %i WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1', $table, $kit, '_elementor_page_settings'), ARRAY_A);
        if ($wpdb->last_error || !is_array($row)) {
            return; // No imported Elementor kit settings: the logo is optional.
        }
        $settings = maybe_unserialize($row['meta_value']);
        if (!is_array($settings)) {
            return;
        }
        if (!isset($settings['site_logo']) || !is_array($settings['site_logo'])) {
            $settings['site_logo'] = array();
        }
        // The attachment was independently verified against the backup.
        $id_changed = absint($settings['site_logo']['id'] ?? 0) !== $expected_id;
        $settings['site_logo']['id'] = $expected_id;
        $expected_url = $uploads_url . '/' . ltrim((string) $ref['file'], '/');
        $current_logo_url = (string) ($settings['site_logo']['url'] ?? '');
        $needs_url_repair = !empty($state['source_first_restore'])
            ? self::logo_url_requires_repair($current_logo_url, $state)
            : $current_logo_url !== $expected_url;
        if ($id_changed || ($needs_url_repair && $current_logo_url !== $expected_url)) {
            if ($needs_url_repair) {
                $settings['site_logo']['url'] = $expected_url;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Correct only the source-verified Elementor logo URL for the destination uploads location.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Update a single verified Elementor kit logo metadata row by indexed primary key, never filter by meta_value.
            if (false === $wpdb->update($table, array('meta_value' => maybe_serialize($settings)), array('meta_id' => (int) $row['meta_id']))) {
                return; // Optional branding failure is not a restore failure.
            }
            wp_cache_delete($kit, 'post_meta');
        }
    }

    /** Check new-format branding image entries before destructive import. */
    private function verify_archived_branding_entries(array $manifest, array $integrity) {
        $branding = $manifest['branding_media'] ?? null;
        if (!is_array($branding)) {
            return;
        }
        foreach ($branding as $reference) {
            if (!is_array($reference) || empty($reference['file']) || empty($reference['id'])) {
                continue; // Older archives can have missing optional branding fields.
            }
            $file = wp_normalize_path((string) $reference['file']);
            $entry = 'site/wp-content/uploads/' . $file;
            if (!$this->safe_entry($file) || '/' === substr($file, 0, 1) || strpos($file, ':') !== false) {
                continue; // Invalid optional logo references are not imported.
            }
            // If absent from the archive, skip this optional image. Files that
            // really are in the integrity inventory remain hash-verified.
            if (!isset($integrity['files'][$entry])) {
                continue;
            }
        }
    }

    /** Source page-builder and block-theme templates must survive migration. */
    private function source_builder_templates() {
        global $wpdb;
        $posts = $wpdb->prefix . 'posts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Snapshot source template IDs from the actual WordPress database.
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT ID, post_type FROM %i WHERE post_type IN (%s, %s, %s, %s, %s, %s)',
                $posts, 'elementor_library', 'elementor-hf', 'hfe_templates', 'wp_template', 'wp_template_part', 'wp_global_styles'), ARRAY_A
        );
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not inventory Elementor and theme-builder templates.', 'uwh-site-migrator'));
        }
        $ids = array();
        foreach ($rows as $row) {
            $id = (int) ($row['ID'] ?? 0);
            $type = (string) ($row['post_type'] ?? '');
            if ($id < 1 || isset($ids[$id]) || !in_array($type, array('elementor_library', 'elementor-hf', 'hfe_templates', 'wp_template', 'wp_template_part', 'wp_global_styles'), true)) {
                throw new RuntimeException(esc_html__('Invalid source builder template inventory.', 'uwh-site-migrator'));
            }
            $ids[$id] = $type;
        }
        ksort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function source_front_page_settings() {
        global $wpdb;
        $options = $wpdb->prefix . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A backup must snapshot the database rather than a potentially stale object cache.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s)',
                $options, 'show_on_front', 'page_on_front', 'page_for_posts'
            ),
            ARRAY_A
        );
        if ($wpdb->last_error) {
            throw new RuntimeException(esc_html__('Cannot read source Reading Settings for the backup.', 'uwh-site-migrator'));
        }
        $settings = array('show_on_front' => null, 'page_on_front' => null, 'page_for_posts' => null);
        foreach ((array) $rows as $row) {
            $key = (string) ($row['option_name'] ?? '');
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) $row['option_value'];
            }
        }
        return $settings;
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
        $tables = $this->collect_tables();
        $source_theme_settings = $this->source_active_theme_settings();
        $manifest = array(
            'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
            'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
            'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix, 'database_scope' => 'wordpress_prefix_tables', 'database_tables' => $tables,
            'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION,
            'active_plugins' => get_option('active_plugins', array()), 'active_theme_stylesheet' => $source_theme_settings['stylesheet'], 'active_theme_template' => $source_theme_settings['template'], 'front_page_settings' => $this->source_front_page_settings(), 'site_identity_settings' => $this->source_site_identity(), 'branding_media' => $this->source_branding_media($source_theme_settings['stylesheet']), 'builder_template_ids' => $this->source_builder_templates(), 'checkpoint_for' => $restore_id,
        );
        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();
        $files = $this->collect_files();
        $this->verify_branding_inventory((array) ($manifest['branding_media'] ?? array()), $files);
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
            $tables = $this->collect_tables();
            $source_theme_settings = $this->source_active_theme_settings();
            $manifest = array(
                'format' => 'site-vault-migrator', 'format_version' => 1, 'created_utc' => gmdate('c'),
                'site_url' => site_url(), 'home_url' => home_url(), 'abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()),
                'content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'content_url' => content_url(), 'plugins_url' => plugins_url(), 'uploads_url' => UWHSM_Paths::upload_url(), 'table_prefix' => $GLOBALS['wpdb']->prefix, 'database_scope' => 'wordpress_prefix_tables', 'database_tables' => $tables,
                'wp_version' => get_bloginfo('version'), 'php_version' => PHP_VERSION, 'generator_version' => UWHSM_VERSION, 'active_plugins' => get_option('active_plugins', array()), 'active_theme_stylesheet' => $source_theme_settings['stylesheet'], 'active_theme_template' => $source_theme_settings['template'], 'front_page_settings' => $this->source_front_page_settings(), 'site_identity_settings' => $this->source_site_identity(), 'branding_media' => $this->source_branding_media($source_theme_settings['stylesheet']), 'builder_template_ids' => $this->source_builder_templates(),
            );
            $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $zip->close();
            $files = $this->collect_files();
        $this->verify_branding_inventory((array) ($manifest['branding_media'] ?? array()), $files);
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

    /**
     * Enumerate the same WordPress-prefix table scope used by v2.4.5.
     * This avoids copying unrelated sites sharing a database. Site builder,
     * theme, Elementor and postmeta tables with the site's prefix are included.
     */
    private function collect_tables() {
        global $wpdb;
        // v2.4.5-compatible database scope: this WordPress installation's tables.
        // Shared hosting often has other applications/sites in the same DB.
        // Non-prefixed tables cannot safely be attributed to this WordPress site.
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Database table inventory is required for site migration.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($wpdb->last_error || !is_array($tables) || !$tables) {
            throw new RuntimeException(esc_html__('Could not read WordPress database tables.', 'uwh-site-migrator'));
        }
        $tables = array_values(array_filter(array_map('strval', $tables), static function($name)use($wpdb) {
            return preg_match('/^[A-Za-z0-9_]+$/D', $name) && 0 === strpos($name, $wpdb->prefix);
        }));
        if (!in_array($wpdb->prefix . 'options', $tables, true)
            || !in_array($wpdb->prefix . 'posts', $tables, true)
            || !in_array($wpdb->prefix . 'postmeta', $tables, true)) {
            throw new RuntimeException(esc_html__('Critical WordPress database tables are missing from the backup scope.', 'uwh-site-migrator'));
        }
        sort($tables, SORT_STRING);
        return $tables;
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
                $zip->close();
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
        $batch_rows = ($s['purpose'] ?? '') === 'checkpoint' ? min($this->batch_rows, 100) : $this->batch_rows;
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
            if (!$create || empty($create[1]) || '' === self::supported_table_engine($create[1])) {
                throw new RuntimeException(esc_html__('Could not read a supported table schema: ', 'uwh-site-migrator') . esc_html($table));
            }
            if (false === file_put_contents($schema_stage, $create[1], LOCK_EX)) {
                throw new RuntimeException(esc_html__('Could not stage database schema.', 'uwh-site-migrator'));
            }
            wp_delete_file($rows_stage);
            $s['rows_bytes'] = 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $keys = $wpdb->get_results($wpdb->prepare('SHOW KEYS FROM %i WHERE Key_name=%s ORDER BY Seq_in_index', $table, 'PRIMARY'), ARRAY_A);
            $s['table_pk'] = count((array) $keys) === 1?(string) ($keys[0]['Column_name'] ?? ''):'';
            unset($s['last_pk_b64'], $s['table_expected_rows'], $s['table_snapshot_max']);
        }
        if (!empty($s['table_pk'])) {
            $column = (string) $s['table_pk'];
            if (!empty($s['last_pk_b64'])) {
                $last_raw = base64_decode((string) $s['last_pk_b64'], true);
                $last = false === $last_raw?null:@unserialize($last_raw, array('allowed_classes' => false));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE %i > %s ORDER BY %i ASC LIMIT %d', $table, $column, (string) $last, $column, $batch_rows), ARRAY_A);
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
                $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY %i ASC LIMIT %d', $table, $column, $batch_rows), ARRAY_A);
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
        if (count($rows) < $batch_rows) {
            // Keep table exports staged; add all tables in one ZIP update during finalization.
            $s['table_rows'][$table] = (int) $s['row_offset'];
            $s['table_index']++;
            $s['row_offset'] = 0;
            $s['done_units'] += 3;
            unset($s['table_pk'], $s['last_pk_b64'], $s['rows_bytes'], $s['table_expected_rows'], $s['table_snapshot_max']);
        }
    }

    private function backup_finalize(array &$s) {
        // Detect tables created or deleted while a multi-request backup ran.
        // Missing a late-created table must never produce a silently complete backup.
        $now = $this->collect_tables();
        $expected = (array) ($s['tables'] ?? array());
        $exported = array_keys((array) ($s['table_rows'] ?? array()));
        sort($expected, SORT_STRING);
        sort($exported, SORT_STRING);
        if ($now !== $expected || $exported !== $expected) {
            throw new RuntimeException(esc_html__('Database table list changed during export or not every table was exported. Retry the backup when database schema is stable.', 'uwh-site-migrator'));
        }
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
        if (preg_match('/\.zip$/i', $name) && preg_match('/^(?:uwh-site-migrator|site-vault-migrator|site-migrator)(?:-v|\.)/i', $name)) {
            $this->fail(__('This is the UWH Site Migrator plugin installer ZIP. Please select a website backup file (.uwh) instead.', 'uwh-site-migrator'));
        }
        $size_raw = UWHSM_Compat::post_value('size');
        $size = '' !== $size_raw ? absint($size_raw) : 0;
        if (!$name || !preg_match('/\.uwh$/i', $name) || $size < 100 || $size > 50*1024*1024*1024) {
            $this->fail(__('Choose a valid UWH Site Migrator website backup file (.uwh, maximum 50 GB).', 'uwh-site-migrator'));
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
        $guard = get_option('uwhsm_restore_guard', array());
        if (is_array($guard) && $this->safe_id((string) ($guard['restore_id'] ?? '')) && $guard['restore_id'] !== $except_id) {
            throw new RuntimeException(esc_html__('An earlier restore still holds the maintenance guard. Resolve it in Troubleshooting before starting another migration.', 'uwh-site-migrator'));
        }
        foreach ((array) glob($this->base . '/job-*.json') as $jobfile) {
            $other = json_decode((string) @file_get_contents($jobfile), true);
            if (!is_array($other)) {
                continue;
            }
            $type = (string) ($other['type'] ?? '');
            $phase = (string) ($other['phase'] ?? '');
            $other_id = (string) ($other['restore_id'] ?? '');
            if (in_array($type, array('restore', 'restore_pending'), true) && !in_array($phase, array('done', 'failed', 'cancelled'), true) && $other_id !== $except_id
                && !(isset($other['checkpoint_enabled']) && !$other['checkpoint_enabled'] && !empty($other['last_error']) && empty($other['checkpoint_name']))) {
                throw new RuntimeException(esc_html__('Another restore is unfinished. Resume it before starting another.', 'uwh-site-migrator'));
            }
        }
    }

    private function begin_restore_with_checkpoint($id, $token, $archive, $source_mode, $archive_name, $archive_cleanup, $create_checkpoint = true, $skipped_missing_plugins = array()) {
        $this->assert_restore_slot($id);
        $state = $this->prepare_restore_state($id, $token, $archive, $source_mode, $archive_name, $skipped_missing_plugins);
        // Persist the choice with this restore, not as a global setting. An
        // interrupted job must resume with the protection level it started with.
        $state['checkpoint_enabled'] = (bool) $create_checkpoint;
        $state['archive_cleanup'] = (bool) $archive_cleanup;
        if (!empty($state['skipped_missing_plugins'])) {
            UWHSM_Logger::log('migration', 'warning', sprintf(
                /* translators: %s: comma-separated plugin filenames. */
                __('Administrator approved skipping these archive-missing active plugin entries (they will remain inactive): %s', 'uwh-site-migrator'),
                implode(', ', $state['skipped_missing_plugins'])
            ), array('restore_id' => $id));
        }
        if ($create_checkpoint) {
            $checkpoint = $this->start_checkpoint_job($id);
            $state['type'] = 'restore_pending';
            $state['restore_phase'] = 'extract';
            $state['phase'] = 'checkpoint';
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
        // Direct restore: same archive validation, import, progress, and resume
        // path, but no checkpoint archive or automatic rollback on failure.
        $this->write_state($id, $state);
        UWHSM_Logger::log('restore', 'started', __('Restore started without a safety checkpoint.', 'uwh-site-migrator'), array('restore_id' => $id, 'backup' => $archive_name, 'phase' => 'extract', 'message' => __('Source: ', 'uwh-site-migrator') . $source_mode));
        return array(
            'restore_id' => $id, 'token' => $token, 'done' => false, 'progress' => 0,
            'label' => __('Restoring without safety checkpoint', 'uwh-site-migrator'), 'checkpoint_required' => false,
        );
    }

    public function begin_external_restore($id, $archive, $source_mode, $archive_name, $archive_cleanup = true, $create_checkpoint = true, $skipped_missing_plugins = array()) {
        if (!$this->safe_id($id)) {
            throw new RuntimeException(esc_html__('Invalid direct migration restore ID.', 'uwh-site-migrator'));
        }
        $token = bin2hex(random_bytes(32));
        return $this->begin_restore_with_checkpoint($id, $token, $archive, $source_mode, $archive_name, $archive_cleanup, $create_checkpoint, $skipped_missing_plugins);
    }

    public function restore_session_state($id, $token) {
        if (!$this->safe_id($id) || !($state = $this->read_state($id)) || !in_array(($state['type'] ?? ''), array('restore', 'restore_pending'), true) || !hash_equals((string) ($state['token_hash'] ?? ''), hash('sha256', (string) $token))) {
            return false;
        }
        if (in_array(($state['phase'] ?? ''), array('failed', 'cancelled'), true)
            || (isset($state['checkpoint_enabled']) && !$state['checkpoint_enabled'] && !empty($state['last_error']) && empty($state['checkpoint_name']))) {
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
        if ($existing && in_array(($existing['type'] ?? ''), array('restore', 'restore_pending'), true) && !in_array(($existing['phase'] ?? ''), array('failed', 'cancelled'), true) && hash_equals((string) $existing['token_hash'], hash('sha256', $token))) {
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
            $create_checkpoint = '1' === UWHSM_Compat::post_value('create_checkpoint');
            $result = $this->begin_restore_with_checkpoint($id, $token, $s['archive'], 'uploaded', basename($s['archive']), true, $create_checkpoint);
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
            $create_checkpoint = '1' === UWHSM_Compat::post_value('create_checkpoint');
            $result = $this->begin_restore_with_checkpoint($id, $token, $record['path'], 'local', $record['name'], false, $create_checkpoint);
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

    /**
     * Detect orphaned active_plugins entries from a completed archive.
     * This is only a diagnostic; the full integrity and database checks still
     * run in prepare_restore_state() before any destination data is modified.
     * An orphaned entry is not evidence that a plugin was malicious.
     *
     * @return string[] Relative plugin basenames absent from the archive index.
     */
    public function missing_archive_active_plugins($archive) {
        if (!is_string($archive) || !is_file($archive)) {
            throw new RuntimeException(esc_html__('The original backup archive is missing.', 'uwh-site-migrator'));
        }
        $zip = $this->open_zip($archive);
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $integrity = json_decode((string) $zip->getFromName('integrity.json'), true);
            if (!is_array($manifest) || 'site-vault-migrator' !== ($manifest['format'] ?? '')
                || 1 !== (int) ($manifest['format_version'] ?? 0)
                || !isset($integrity['files']) || !is_array($integrity['files'])) {
                throw new RuntimeException(esc_html__('The migration package has invalid manifest or integrity metadata.', 'uwh-site-migrator'));
            }
            $plugins = $manifest['active_plugins'] ?? array();
            if (!is_array($plugins) || count($plugins) > 500) {
                throw new RuntimeException(esc_html__('The archived active plugin list is invalid.', 'uwh-site-migrator'));
            }
            $missing = array();
            foreach ($plugins as $plugin) {
                if (!is_string($plugin) || strlen($plugin) > 260 || !$this->safe_entry($plugin)
                    || !preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)?\.php$#D', $plugin)) {
                    throw new RuntimeException(esc_html__('The archived active plugin path is unsafe.', 'uwh-site-migrator'));
                }
                $entry = 'site/wp-content/plugins/' . $plugin;
                if (!isset($integrity['files'][$entry])) {
                    $missing[$plugin] = $plugin;
                }
            }
            return array_values($missing);
        } finally {
            $zip->close();
        }
    }

    private function prepare_restore_state($id, $token, $archive, $source_mode, $archive_name, $skipped_missing_plugins = array()) {
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
            // Bypass is strictly limited to explicitly confirmed orphaned
            // active_plugins entries on direct migrations. Never bypass a
            // corrupted/incomplete integrity index, theme, or database table.
            $allowed_skips = array();
            if (!empty($skipped_missing_plugins)) {
                if ('direct' !== $source_mode || !is_array($skipped_missing_plugins)) {
                    throw new RuntimeException(esc_html__('Skipping missing plugins is only supported for administrator-approved direct migrations.', 'uwh-site-migrator'));
                }
                $actual_missing = $this->missing_archive_active_plugins($archive);
                foreach ($skipped_missing_plugins as $plugin) {
                    if (!is_string($plugin) || !in_array($plugin, $actual_missing, true)) {
                        throw new RuntimeException(esc_html__('A plugin skip request is not an archive-confirmed missing active plugin.', 'uwh-site-migrator'));
                    }
                    $allowed_skips[$plugin] = true;
                }
            }
            $retained_active_plugins = array();
            foreach (($manifest['active_plugins'] ?? array()) as $plugin) {
                $entry = 'site/wp-content/plugins/' . $plugin;
                if (!$this->safe_entry($plugin)) {
                    throw new RuntimeException(esc_html__('An active plugin path is unsafe.', 'uwh-site-migrator'));
                }
                if (!isset($integrity['files'][$entry])) {
                    if (isset($allowed_skips[$plugin])) {
                        continue;
                    }
                    throw new RuntimeException(__('An active plugin is missing from the archive: ', 'uwh-site-migrator') . $plugin);
                }
                $retained_active_plugins[] = $plugin;
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
            // Fail before a destructive restore if the source active theme is
            // missing. A site's theme, child theme, header and footer templates
            // must not silently fall back to the destination's previous theme.
            foreach (array('active_theme_stylesheet', 'active_theme_template') as $theme_key) {
                if (!array_key_exists($theme_key, $manifest)) {
                    continue; // Packages created before the theme manifest fields.
                }
                $theme = (string) $manifest[$theme_key];
                if (!$this->safe_theme_slug($theme)) {
                    throw new RuntimeException(esc_html__('The source theme identifier is invalid.', 'uwh-site-migrator'));
                }
                $style = 'site/wp-content/themes/' . $theme . '/style.css';
                if (!isset($integrity['files'][$style])) {
                    throw new RuntimeException(esc_html__('The active source theme is missing from the backup: ', 'uwh-site-migrator') . esc_html($theme));
                }
                if ('active_theme_template' === $theme_key) {
                    $theme_root = 'site/wp-content/themes/' . $theme . '/';
                    if (!isset($integrity['files'][$theme_root . 'index.php'])
                        && !isset($integrity['files'][$theme_root . 'templates/index.html'])
                        && !isset($integrity['files'][$theme_root . 'block-templates/index.html'])) {
                        throw new RuntimeException(esc_html__('The source parent theme has no index template in the backup: ', 'uwh-site-migrator') . esc_html($theme));
                    }
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
            if (isset($manifest['database_scope']) && !in_array($manifest['database_scope'], array('full_source_database', 'wordpress_prefix_tables'), true)) {
                throw new RuntimeException(esc_html__('Unrecognized database backup scope; cannot verify complete table transfer.', 'uwh-site-migrator'));
            }
            $is_full_database = in_array((string) ($manifest['database_scope'] ?? ''), array('full_source_database', 'wordpress_prefix_tables'), true);
            if ($is_full_database) {
                $listed = $manifest['database_tables'] ?? null;
                if (!is_array($listed) || !$listed || count($listed) !== count(array_unique($listed))
                    || array_values(array_unique($listed)) !== array_values($listed)) {
                    throw new RuntimeException(esc_html__('The backup database table manifest is invalid or incomplete.', 'uwh-site-migrator'));
                }
                $listed_sorted = $listed;
                $archived_sorted = array_keys($integrity['table_rows']);
                sort($listed_sorted, SORT_STRING);
                sort($archived_sorted, SORT_STRING);
                if ($listed_sorted !== $archived_sorted) {
                    throw new RuntimeException(esc_html__('Database table manifest and exported tables do not match. Create a fresh full backup.', 'uwh-site-migrator'));
                }
            }
            // Distinct source tables must not collapse onto one destination
            // table when old/new WordPress prefixes differ. Refuse the entire
            // package BEFORE deleting any destination tables.
            $mapped_names = array();
            foreach ($integrity['table_rows'] as $source_name => $row_total) {
                $source_prefix = (string) ($manifest['table_prefix'] ?? '');
                $dest_prefix = (string) $GLOBALS['wpdb']->prefix;
                $mapped = 0 === strpos($source_name, $source_prefix)
                    ? $dest_prefix . substr($source_name, strlen($source_prefix)) : $source_name;
                if (isset($mapped_names[$mapped])) {
                    throw new RuntimeException(esc_html__('Two source database tables would map to the same destination table: ', 'uwh-site-migrator') . esc_html($mapped));
                }
                $mapped_names[$mapped] = true;
            }
            foreach ($integrity['table_rows'] as $table => $rows) {
                if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || (!$is_full_database && strpos($table, (string) $manifest['table_prefix']) !== 0) || !is_numeric($rows) || (int) $rows < 0) {
                    throw new RuntimeException(__('Unsupported database table name.', 'uwh-site-migrator'));
                }
                $schema_entry = 'database/' . rawurlencode($table) . '.schema';
                $rows_entry = 'database/' . rawurlencode($table) . '.rows';
                if (!isset($integrity['files'][$schema_entry]) || ((int) $rows > 0 && !isset($integrity['files'][$rows_entry]))) {
                    throw new RuntimeException(esc_html__('A database table schema or data file is absent from the backup: ', 'uwh-site-migrator') . esc_html($table));
                }
                // Never overwrite unrelated tables on a destination sharing this database.
                // Checkpoint/rollback restores use their own saved table names and are exempt.
                if ($is_full_database && 0 !== strpos($table, (string) $manifest['table_prefix'])
                    && !in_array($source_mode, array('checkpoint', 'rollback'), true)
                    && $this->restore_table_exists($table)) {
                    throw new RuntimeException(esc_html__('Destination already contains an unrelated non-prefixed table. Use a fresh isolated database or restore manually: ', 'uwh-site-migrator') . esc_html($table));
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
            $this->verify_archive_front_page_settings($zip, $manifest);
            $this->verify_archive_active_theme_settings($zip, $manifest);
            $this->verify_archive_site_identity($zip, $manifest);
            $this->verify_archived_branding_entries($manifest, $integrity);
            $this->verify_archive_builder_templates($zip, $manifest);
            $destination_drive_settings = get_option('uwhsm_google_drive_settings_v1', '');
            $destination_drive_settings = is_string($destination_drive_settings) ? $destination_drive_settings : '';
            return array('type' => 'restore', 'restore_id' => $id, 'token_hash' => hash('sha256', $token), 'archive' => $archive, 'source_mode' => $source_mode, 'archive_name' => basename((string) $archive_name), 'archive_cleanup' => in_array($source_mode, array('uploaded', 'direct'), true), 'manifest' => $manifest, 'integrity' => $integrity, 'source_active_plugins' => $retained_active_plugins, 'skipped_missing_plugins' => array_values(array_keys($allowed_skips)), 'entries' => $entries, 'entry_index' => 0, 'phase' => 'extract', 'new_site_url' => site_url(), 'new_home_url' => home_url(), 'new_abspath' => untrailingslashit(UWHSM_Paths::wordpress_root()), 'new_content_dir' => untrailingslashit(UWHSM_Paths::content_root()), 'new_plugins_dir' => untrailingslashit(UWHSM_Paths::plugin_root()), 'new_uploads_dir' => untrailingslashit(UWHSM_Paths::upload_root()), 'new_content_url' => content_url(), 'new_plugins_url' => plugins_url(), 'new_uploads_url' => UWHSM_Paths::upload_url(), 'new_prefix' => $GLOBALS['wpdb']->prefix, 'destination_upload_path' => (string) get_option('upload_path', ''), 'destination_upload_url_path' => (string) get_option('upload_url_path', ''), 'source_first_restore' => true, 'options_atomic_swap' => true, 'auth_tables_atomic_swap' => true, 'incremental_template_verify' => true, 'verify_restored_files' => true, 'verify_files_index' => 0, 'verify_kind_index' => 0, 'verify_byte_offset' => 0, 'tables' => array(), 'table_index' => 0, 'row_offset' => 0, 'current_table' => '', 'destination_drive_settings' => $destination_drive_settings, 'created' => time());
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

    /**
     * A read-only, token-authenticated receipt for a restore batch whose AJAX
     * response was lost by the browser, proxy or hosting PHP worker. Unlike a
     * restore step it never applies files or changes the database.
     */
    public function uwhsm_restore_status() {
        $id = UWHSM_Compat::post_value('restore_id');
        $token = UWHSM_Compat::post_value('token');
        $state = $this->restore_auth($id, $token);
        if (!$state) {
            $this->fail(__('Restore session was not found or its token has expired.', 'uwh-site-migrator'), 403);
            return;
        }
        $phase = (string) ($state['phase'] ?? '');
        $index = (int) ($state['copy_index'] ?? 0);
        $files = (array) ($state['site_files'] ?? array());
        $progress = 0;
        if ('extract' === $phase) {
            $progress = 35 * min(1, (int) ($state['entry_index'] ?? 0) / max(1, count((array) ($state['entries'] ?? array()))));
        } elseif ('stage_files' === $phase) {
            // Legacy jobs from the staged-file release restart the file phase
            // directly from their original validated backup on next resume.
            $progress = 35;
        } elseif ('files' === $phase) {
            $progress = 35 + 25 * min(1, $index / max(1, count($files)));
        } elseif ('database' === $phase) {
            $progress = 60 + 38 * min(1, (int) ($state['table_index'] ?? 0) / max(1, count((array) ($state['tables'] ?? array()))));
        } elseif ('verify' === $phase || 'verify_files' === $phase) {
            $progress = 98;
        } elseif (in_array($phase, array('finalize', 'failed', 'cancelled'), true)) {
            $progress = 99;
        } elseif ('done' === $phase) {
            $progress = 100;
        }
        $current = isset($files[$index]) ? (string) $files[$index] : '';
        $partial = is_array($state['file_partial'] ?? null) ? $state['file_partial'] : array();
        $total_bytes = (int) ($state['integrity']['files'][$current]['size'] ?? 0);
        $file_percent = $total_bytes > 0 && ($partial['entry'] ?? '') === $current
            ? (int) min(100, floor(100 * (int) ($partial['offset'] ?? 0) / $total_bytes)) : 0;
        wp_send_json_success(array(
            'restore_id' => $id,
            'token' => $token,
            'done' => 'done' === $phase,
            'phase' => $phase,
            'progress' => $progress,
            'label' => ('stage_files' === $phase) ? __('Restarting direct website file restore', 'uwh-site-migrator') : (('files' === $phase) ? __('Restoring website files', 'uwh-site-migrator') : (('verify_files' === $phase) ? __('Verifying restored website files', 'uwh-site-migrator') : __('Checking saved restore progress', 'uwh-site-migrator'))),
            'file_progress' => $file_percent,
            // The signed restore token is required before exposing file names.
            'current_file' => $current,
            'file_index' => $index,
            'file_total' => count($files),
            'last_error' => (string) ($state['last_error'] ?? ''),
            'automatic_rollback' => !empty($state['is_rollback']),
            'rolled_back' => 'done' === $phase && !empty($state['is_rollback']),
            'recovery_available' => !isset($state['recovery_available']) || false !== $state['recovery_available'],
        ));
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
            // Compatibility: a restore paused by the former verified file-
            // staging release can continue without private staging. Files in
            // the old staging folder have never been published, so restart
            // directly from the signed backup ZIP rather than trusting them.
            if ('stage_files' === ($s['phase'] ?? '')) {
                $s['phase'] = 'files';
                $s['copy_index'] = 0;
                unset($s['file_partial']);
                $s['verify_restored_files'] = true;
                unset($s['strict_file_staging']);
                $this->write_state($id, $s);
            } elseif (array_key_exists('strict_file_staging', $s)) {
                // Publishing may have already written some live files. Keep
                // its saved cursor and any destination-local partial file.
                $s['verify_restored_files'] = true;
                unset($s['strict_file_staging']);
                $this->write_state($id, $s);
            }
            if (in_array($s['phase'], array('files', 'database', 'verify', 'verify_files', 'finalize'), true)) {
                $this->enable_restore_guard($s);
            }
            if ('extract' === $s['phase']) {
                $this->restore_extract($s);
            } elseif ('files' === $s['phase']) {
                $this->restore_files($s);
            } elseif ('verify_files' === $s['phase']) {
                $this->verify_restored_files_batch($s);
            } elseif ('database' === $s['phase']) {
                $this->restore_database($s);
            } elseif ('verify' === $s['phase']) {
                $this->run_restore_integrity_batch($s);
            } elseif ('finalize' === $s['phase']) {
                // Recover jobs paused at 99% by v2.4.17/v2.4.18: the old
                // database phase bypassed verification entirely. Re-enter the
                // durable verifier instead of reporting the same error forever.
                // Do not bypass any source-data integrity checks.
                if (self::restore_needs_incremental_verify($s)) {
                    $s['phase'] = 'verify';
                    $this->run_restore_integrity_batch($s);
                } else {
                    $this->restore_finalize($s);
                }
            } elseif ('done' === $s['phase']) {
                /* Retried completion. */
            } else {
                throw new RuntimeException(__('Invalid restore state.', 'uwh-site-migrator'));
            }
            if (in_array($s['phase'], array('files', 'database', 'verify', 'verify_files', 'finalize'), true)) {
                $this->enable_restore_guard($s);
            }
            // The archive occasionally contains legacy malformed serialized
            // option/meta values. These are copied byte-for-byte instead of
            // being corrupted by naive URL replacement. Surface this limitation
            // once in Activity Logs, after the current database batch commits.
            if (UWHSM_Value_Rewriter::consume_preserved_invalid_count() > 0) {
                UWHSM_Logger::log_once(
                    'source-invalid-serialized-' . $id,
                    'restore',
                    'warning',
                    __('Some source database values contain invalid PHP serialized lengths. Their original bytes were preserved without URL replacement to avoid further corruption. Review related source plugin/theme settings if links remain outdated.', 'uwh-site-migrator'),
                    array('restore_id' => $id),
                    7 * DAY_IN_SECONDS
                );
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
                    if (array_key_exists('checkpoint_enabled', $failed) && empty($failed['checkpoint_enabled']) && empty($failed['checkpoint_name'])) {
                        $failed['failed_phase'] = (string) ($failed['phase'] ?? '');
                        $failed['phase'] = 'failed';
                        $failed['recovery_available'] = false;
                    }
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
            if (!empty($failed) && isset($failed['recovery_available']) && false === $failed['recovery_available']) {
                wp_send_json_error(array('message' => $message, 'recovery_available' => false), 409);
            }
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
            $partial = is_array($s['file_partial'] ?? null) ? $s['file_partial'] : array();
            if ($partial) {
                $entry = (string) ($partial['entry'] ?? '');
                $file_size = (int) ($s['integrity']['files'][$entry]['size'] ?? 0);
                if ($file_size > 0) {
                    $label .= sprintf(' (%d%% ' . __('of large file', 'uwh-site-migrator') . ')',
                        min(100, (int) floor(100 * (int) $partial['offset'] / $file_size)));
                }
            }
        } elseif ('database' === $s['phase']) {
            $progress = 60+((int) $s['table_index']/max(1, count($s['tables'])))*38;
            $label = !empty($s['is_rollback']) ? __('Rolling back database', 'uwh-site-migrator') : __('Restoring database and updating URLs', 'uwh-site-migrator');
        } elseif ('verify_files' === $s['phase']) {
            $progress = 99;
            $label = __('Verifying every restored website file', 'uwh-site-migrator');
        } elseif ('verify' === $s['phase']) {
            $progress = 98;
            $verification_step = min(3, max(1, (int) ($s['verify_kind_index'] ?? 0) + 1));
            $label = sprintf(
                /* translators: %1$d: current verification stage, %2$d: total verification stages. */
                __('Verifying restored slugs and Elementor templates (%1$d/%2$d)', 'uwh-site-migrator'),
                $verification_step,
                3
            );
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
        wp_send_json_success(array('restore_id' => $id, 'token' => $token, 'done' => $done, 'progress' => $progress, 'phase' => (string) $s['phase'], 'file_index' => (int) ($s['copy_index'] ?? 0), 'file_progress' => !empty($s['file_partial']) && !empty($s['integrity']['files'][$s['file_partial']['entry']]['size']) ? (int) floor(100 * (int) $s['file_partial']['offset'] / (int) $s['integrity']['files'][$s['file_partial']['entry']]['size']) : 0, 'label' => $label, 'archive_retained' => $done ? $keep_done : null, 'automatic_rollback' => !empty($s['is_rollback']), 'rolled_back' => $done && !empty($s['is_rollback']), 'original_error' => (string) ($s['original_error'] ?? '')));
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
            $this->cleanup_previous_auth_tables($state);
            global $wpdb;
            $receipt = $this->restore_progress_table($state);
            if ($this->restore_table_exists($receipt)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- The restore is already durably completed; progress receipts are no longer needed.
                $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $receipt));
            }
        } catch (Throwable $error) {
            UWHSM_Logger::log('restore', 'warning', __('An old restore snapshot or progress receipt could not be removed; contact your database administrator.', 'uwh-site-migrator'), array('error' => $error->getMessage()));
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
            // Keep the destination's own wp_options live until all other
            // tables have been imported. Publishing the source wp_options
            // early lets WordPress/plugins run against a half-restored wp_posts
            // table on the next AJAX request and reset page_on_front to 0.
            // The archive order stays untouched; only new restore jobs are
            // re-ordered. Existing resumable job state is not modified.
            $s['tables'] = self::options_table_last($s['tables'], (string) $s['manifest']['table_prefix']);
            // Like v2.4.5, begin direct file restore immediately. Guard the
            // live destination before the first file is published.
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
        // Optimize the common case (many small WordPress files) by reducing
        // HTTP/AJAX round trips, NOT by lengthening a risky PHP request.
        // Keep both wall-time and uncompressed-byte caps bounded so earlier
        // timeout/retry failures do not return on slower shared hosting.
        // The cursor still commits only after this complete batch succeeds.
        $end = min(count($files), (int) $s['copy_index'] + 112);
        $started = microtime(true);
        $batch_bytes = 0;
        $own = wp_normalize_path('wp-content/plugins/' . plugin_basename(UWHSM_DIR));
        $zip = $this->open_zip($s['archive']);
        try {
            for ($i = (int) $s['copy_index']; $i < $end; $i++) {
                $entry = $files[$i];
                $rel = substr($entry, 5);
                $norm = wp_normalize_path($rel);
                if ('wp-config.php' === $norm || 0 === strpos($norm, trailingslashit($own)) || $this->skip_restore_file($norm)) {
                    $s['copy_index'] = $i + 1;
                    continue;
                }
                $size = (int) ($s['integrity']['files'][$entry]['size'] ?? 0);
                $target = UWHSM_Paths::restore_target($rel);
                if ($size >= 48 * 1024 * 1024 || !empty($s['file_partial'])) {
                    // Write large files to a destination-local temporary file;
                    // only atomically publish it after SHA-256 verification.
                    $complete = $this->restore_large_file_chunk($entry, $target, $s, $zip);
                    if (!$complete) {
                        break;
                    }
                } else {
                    $this->recover_staged_file($entry, $target, $s, false, $zip);
                }
                $s['copy_index'] = $i + 1;
                $batch_bytes += $size;
                // An explicit time budget matters more than the file-count
                // ceiling; even a directory of tiny files remains resumable.
                if (microtime(true) - $started >= 2.0 || $batch_bytes >= 12 * 1024 * 1024) {
                    break;
                }
            }
        } finally {
            $zip->close();
        }
        if ($s['copy_index'] >= count($files)) {
            $s['phase'] = 'database';
            // Keep inventory for final post-restore checksum verification.
            unset($s['file_partial']);
        }
    }

    /**
     * Resume very large file restoration without loading the file in memory.
     * The partial output is never published to its destination until the full
     * SHA-256 matches the backup manifest. The offset is committed atomically
     * with the job state by the caller after every batch. If PHP terminates
     * before that commit, the next batch truncates to the last saved offset.
     *
     * ZIP streams are not seekable on every PHP version. For compatibility,
     * skip already-verified bytes when reopening the archive stream.
     */
    private function restore_large_file_chunk($entry, $target, array &$s, ZipArchive $zip) {
        $check = $s['integrity']['files'][$entry] ?? null;
        if (!$this->safe_entry($entry) || !is_array($check)) {
            throw new RuntimeException(esc_html__('Unverified large file in restore package.', 'uwh-site-migrator'));
        }
        $size = (int) $check['size'];
        $id = (string) ($s['restore_id'] ?? '');
        if (!$this->safe_id($id) || $size < 0 || !preg_match('/^[a-f0-9]{64}$/D', (string) ($check['sha256'] ?? ''))) {
            throw new RuntimeException(esc_html__('Invalid large-file restore state.', 'uwh-site-migrator'));
        }
        $tmp = $target . '.uwhsm-' . $id . '.part';
        $partial = is_array($s['file_partial'] ?? null) ? $s['file_partial'] : array();
        if ($partial && ($partial['entry'] ?? '') !== $entry) {
            throw new RuntimeException(esc_html__('Large-file restore entry changed unexpectedly.', 'uwh-site-migrator'));
        }
        $offset = (int) ($partial['offset'] ?? 0);
        if ($offset < 0 || $offset > $size) {
            throw new RuntimeException(esc_html__('Invalid large-file restore offset.', 'uwh-site-migrator'));
        }
        $this->assert_no_symlink($target);
        $this->assert_no_symlink($tmp);
        // A timeout can happen between atomic rename and writing the job
        // receipt. The source manifest is authoritative: if the committed
        // destination is already correct, advancing is safe and idempotent.
        if ($offset > 0 && !is_file($tmp) && is_file($target)
            && (int) filesize($target) === $size
            && hash_equals((string) $check['sha256'], (string) hash_file('sha256', $target))) {
            unset($s['file_partial']);
            return true;
        }
        if (!wp_mkdir_p(dirname($target))) {
            throw new RuntimeException(esc_html__('Could not create destination folder for a large file.', 'uwh-site-migrator'));
        }
        $in = $zip->getStream($entry);
        if (!is_resource($in)) {
            throw new RuntimeException(esc_html__('Could not read large file from migration archive: ', 'uwh-site-migrator') . esc_html($entry));
        }
        $out = false;
        try {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- c+b stream is necessary for bounded, resumable writes, ftruncate and flock; WP_Filesystem does not expose stream handles.
            $out = @fopen($tmp, 'c+b');
            if (!$out || !flock($out, LOCK_EX)) {
                throw new RuntimeException(esc_html__('Could not lock large-file staging area.', 'uwh-site-migrator'));
            }
            $actual = @fstat($out);
            if (!is_array($actual) || (int) $actual['size'] < $offset || !ftruncate($out, $offset) || fseek($out, $offset) !== 0) {
                throw new RuntimeException(esc_html__('Large-file staging area is incomplete or unwritable.', 'uwh-site-migrator'));
            }
            // ZIP entry streams differ between PHP/libzip builds: attempt a
            // native forward seek only where supported, otherwise fall back
            // to the proven sequential discard logic. This avoids thousands
            // of 256 KiB PHP reads on runtimes that support seeking.
            $seeked = false;
            if ($offset > 0) {
                $meta = stream_get_meta_data($in);
                if (!empty($meta['seekable'])) {
                    $seeked = @fseek($in, $offset, SEEK_SET) === 0 && @ftell($in) === $offset;
                    if (!$seeked) {
                        // Any attempted seek could leave a stream at an
                        // undefined offset. Reopen before sequential fallback.
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Reopen a ZIP entry stream after an unsupported seek to guarantee correct byte offsets.
                        fclose($in);
                        $in = $zip->getStream($entry);
                        if (!is_resource($in)) {
                            throw new RuntimeException(esc_html__('Could not reopen the large-file archive stream.', 'uwh-site-migrator'));
                        }
                    }
                }
            }
            $remaining = $seeked ? 0 : $offset;
            while ($remaining > 0) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Discard ZIP stream bytes in bounded memory while resuming a verified archive entry.
                $discard = fread($in, min(1048576, $remaining));
                if ($discard === false || $discard === '') {
                    throw new RuntimeException(esc_html__('Could not resume large-file archive stream.', 'uwh-site-migrator'));
                }
                $remaining -= strlen($discard);
            }
            $written = 0;
            // Larger payload per HTTP round trip, but exit on the original
            // short time budget when compression or I/O is slow.
            $limit = min(12 * 1024 * 1024, $size - $offset);
            $started = microtime(true);
            while ($written < $limit) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded reads from ZipArchive::getStream are required for resumable large-file restore.
                $bytes = fread($in, min(262144, $limit - $written));
                if ($bytes === false || $bytes === '') {
                    throw new RuntimeException(esc_html__('Large-file archive stream ended early.', 'uwh-site-migrator'));
                }
                $length = strlen($bytes);
                $pos = 0;
                while ($pos < $length) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Buffered, locked stream writes require handling partial writes before saving a durable offset.
                    $n = fwrite($out, substr($bytes, $pos));
                    if ($n === false || $n <= 0) {
                        throw new RuntimeException(esc_html__('Could not write restored large file. Check storage permissions.', 'uwh-site-migrator'));
                    }
                    $pos += $n;
                }
                $written += $length;
                if ($written >= 1024 * 1024 && microtime(true) - $started >= 1.6) {
                    break;
                }
            }
            if (!fflush($out)) {
                throw new RuntimeException(esc_html__('Could not flush large-file restore data.', 'uwh-site-migrator'));
            }
            $offset += $written;
            // This receipt is committed by uwhsm_restore_step before replying.
            $s['file_partial'] = array('entry' => $entry, 'offset' => $offset);
        } finally {
            if (is_resource($in)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the ZIP input stream after each bounded restore batch.
                fclose($in);
            }
            if (is_resource($out)) {
                flock($out, LOCK_UN);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release local flock-backed partial output stream.
                fclose($out);
            }
        }
        if ($offset < $size) {
            return false;
        }
        if (!hash_equals((string) $check['sha256'], (string) hash_file('sha256', $tmp))) {
            throw new RuntimeException(esc_html__('Restored large file checksum failed: ', 'uwh-site-migrator') . esc_html($entry));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory rename after SHA-256 validation must not fall back to a copy that can expose partial files.
        if (!rename($tmp, $target)) {
            throw new RuntimeException(esc_html__('Could not publish restored large file: ', 'uwh-site-migrator') . esc_html($entry));
        }
        unset($s['file_partial']);
        return true;
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
                throw new RuntimeException(esc_html__('The backup does not contain the missing staged file: ', 'uwh-site-migrator') . esc_html($entry));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $out = fopen($tmp, 'wb');
            if (!$out) {
                throw new RuntimeException(esc_html__('Could not recreate staged file. Check storage permissions and free space.', 'uwh-site-migrator'));
            }
            $bytes = stream_copy_to_stream($in, $out);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($out);
            $out = false;
            if ($bytes !== (int) $check['size'] || !hash_equals($check['sha256'], hash_file('sha256', $tmp))) {
                throw new RuntimeException(esc_html__('Recovered file checksum failed: ', 'uwh-site-migrator') . esc_html($entry));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
            if (!rename($tmp, $target)) {
                throw new RuntimeException(esc_html__('Could not commit recovered staged file: ', 'uwh-site-migrator') . esc_html($entry));
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
            if (is_array($s) && in_array($type, array('restore', 'restore_pending'), true) && !in_array($phase, array('done', 'failed', 'cancelled'), true) && $this->safe_id($s['restore_id'] ?? '')
                && !(isset($s['checkpoint_enabled']) && !$s['checkpoint_enabled'] && !empty($s['last_error']) && empty($s['checkpoint_name']))) {
                $out[] = array('id' => $s['restore_id'], 'phase' => $phase, 'error' => $s['last_error'] ?? '', 'checkpoint_enabled' => !empty($s['checkpoint_enabled']), 'checkpoint_required' => $type === 'restore_pending', 'checkpoint_job' => $s['checkpoint_job'] ?? '');
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
            if (!$s || !in_array($type, array('restore', 'restore_pending'), true) || in_array(($s['phase'] ?? ''), array('done', 'failed', 'cancelled'), true)
                || (isset($s['checkpoint_enabled']) && !$s['checkpoint_enabled'] && !empty($s['last_error']) && empty($s['checkpoint_name']))) {
                throw new RuntimeException(__('Unfinished restore not found.', 'uwh-site-migrator'));
            }
            $token = bin2hex(random_bytes(32));
            $s['token_hash'] = hash('sha256', $token);
            $this->write_state($id, $s);
            if ($type === 'restore' && !in_array((string) ($s['phase'] ?? ''), array('extract', 'stage_files'), true)) {
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

    /**
     * Cancel an unfinished restore using the current administrator session. A
     * browser-local resume token is NOT required to clear a lost/abandoned job.
     * Never interrupt running restore/checkpoint batches or delete a completed
     * checkpoint. This operation does NOT revert partially written site data.
     */
    public function uwhsm_restore_cancel() {
        $this->guard();
        $id = UWHSM_Compat::post_value('restore_id');
        if (!$this->safe_id($id)) {
            $this->fail(__('Invalid restore job ID.', 'uwh-site-migrator'), 400);
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock() must protect in-progress restore state from concurrent cancellation.
        $lock = @fopen($this->lock_path($id), 'c');
        if (!$lock) {
            $this->fail(__('Cannot open restore lock. Check storage permissions.', 'uwh-site-migrator'), 500);
            return;
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            $this->fail(__('A restore batch is currently running. Wait for it to finish, then retry Cancel.', 'uwh-site-migrator'), 409);
            return;
        }
        $checkpoint_lock = null;
        try {
            $state = $this->read_state($id);
            if (!is_array($state) || !in_array(($state['type'] ?? ''), array('restore', 'restore_pending'), true)
                || !hash_equals((string) ($state['restore_id'] ?? ''), $id)
                || in_array(($state['phase'] ?? ''), array('done', 'failed', 'cancelled'), true)) {
                throw new DomainException(__('No active unfinished restore was found. Refresh the page.', 'uwh-site-migrator'));
            }
            $checkpoint_id = (string) ($state['checkpoint_job'] ?? '');
            $checkpoint = false;
            if ($checkpoint_id && $this->safe_id($checkpoint_id)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Checkpoint archive writing uses the same mandatory flock() guard.
                $checkpoint_lock = @fopen($this->lock_path($checkpoint_id), 'c');
                if (!$checkpoint_lock || !@flock($checkpoint_lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException(__('Safety checkpoint export is still running. Retry Cancel after the current batch.', 'uwh-site-migrator'));
                }
                $checkpoint = $this->read_state($checkpoint_id);
            }
            $old_phase = (string) ($state['phase'] ?? '');
            $state['phase'] = 'cancelled';
            $state['cancelled_at'] = time();
            $state['token_hash'] = ''; // Prevent any already-open browser from resuming after cancellation.
            $state['recovery_available'] = false;
            $state['last_error'] = in_array($old_phase, array('extract', 'stage_files'), true)
                ? __('Restore preparation was cancelled before the live website was changed.', 'uwh-site-migrator')
                : __('Restore was cancelled by an administrator. Previously applied file/database changes were not rolled back.', 'uwh-site-migrator');
            $this->write_state($id, $state);
            $guard = get_option('uwhsm_restore_guard', array());
            if (is_array($guard) && ($guard['restore_id'] ?? '') === $id) {
                $this->clear_restore_guard();
            }
            if (is_array($checkpoint) && ($checkpoint['type'] ?? '') === 'backup'
                && ($checkpoint['purpose'] ?? '') === 'checkpoint' && ($checkpoint['checkpoint_for'] ?? '') === $id
                && ($checkpoint['phase'] ?? '') !== 'done') {
                // No complete checkpoint exists, so its incomplete private
                // archive/staging data can be removed without touching backups.
                $path = (string) ($checkpoint['zip'] ?? '');
                $stage = (string) ($checkpoint['db_stage'] ?? '');
                if ($path && dirname(wp_normalize_path($path)) === wp_normalize_path($this->checkpoint_dir)
                    && !is_link($path) && is_file($path)) {
                    wp_delete_file($path);
                    if (is_file($path)) {
                        UWHSM_Logger::log('checkpoint', 'warning', __('An incomplete checkpoint file could not be removed.', 'uwh-site-migrator'));
                    }
                }
                if ($stage === $this->base . '/db-' . $checkpoint_id && is_dir($stage) && !is_link($stage)) {
                    $this->remove_tree($stage);
                }
                wp_delete_file($this->state_path($checkpoint_id));
            }
            // Remove only a partial large file belonging to this restore. Never
            // delete a destination file or an archive on cancellation.
            $partial = is_array($state['file_partial'] ?? null) ? $state['file_partial'] : array();
            $entry = (string) ($partial['entry'] ?? '');
            if ($entry && $this->safe_entry($entry) && 0 === strpos($entry, 'site/')) {
                // The legacy stage_files partial was confined to the owned
                // extraction workspace, removed below. Never delete a live
                // destination partial unless the restore actually wrote it.
                if ('stage_files' !== $old_phase) {
                    $target = UWHSM_Paths::restore_target(substr($entry, 5));
                    $part_path = $target . '.uwhsm-' . $id . '.part';
                    if (is_file($part_path) && !is_link($part_path)) {
                        wp_delete_file($part_path);
                    }
                }
            }
            // Preserve original transfer packages and completed checkpoints.
            // Only remove the extraction workspace owned by this exact restore.
            $temp = $this->import_dir . '/' . $id;
            if (is_dir($temp) && !is_link($temp)) {
                $this->remove_tree($temp);
            }
            UWHSM_Logger::log('restore', 'warning', __('Unfinished restore cancelled by administrator.', 'uwh-site-migrator'), array('restore_id' => $id, 'phase' => $old_phase));
            $result = array('cancelled' => true, 'restore_id' => $id,
                'message' => __('Restore job cleared. This does not undo files or database changes already applied. Review the website before restarting migration.', 'uwh-site-migrator'));
        } catch (Throwable $error) {
            $result = new WP_Error('cancel_failed', $error->getMessage());
        }
        if (is_resource($checkpoint_lock)) {
            flock($checkpoint_lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the advisory checkpoint lock after cancel state is committed.
            fclose($checkpoint_lock);
        }
        flock($lock, LOCK_UN);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Release the advisory restore job lock after cancellation.
        fclose($lock);
        if (is_wp_error($result)) {
            $this->fail($result->get_error_message(), 409);
            return;
        }
        wp_send_json_success($result);
    }

    private function skip_restore_file($rel) {
        $rel = ltrim(wp_normalize_path((string) $rel), '/');
        // Another backup plugin owns these repositories. A Site Migrator
        // restore/rollback must not replace, corrupt or prune its live archives,
        // including files contained in backups created by older UWHSM versions.
        if (UWHSM_Paths::is_external_backup_archive_path($rel)) {
            return true;
        }
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

    /** Restore the source options table only after all other source tables. */
    private static function options_table_last(array $tables, $source_prefix) {
        $options = (string) $source_prefix . 'options';
        $index = array_search($options, $tables, true);
        if (false !== $index) {
            unset($tables[$index]);
            $tables = array_values($tables);
            $tables[] = $options;
        }
        return $tables;
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
    /**
     * Keep destination credentials usable until every table and site setting
     * has been validated. New restores stage users/usermeta separately and
     * publish both at the very end in one atomic RENAME TABLE operation.
     * Existing interrupted jobs without this flag retain their old layout.
     */
    private function auth_shadow_names(array $state, $type) {
        if (!in_array($type, array('users', 'usermeta'), true)) {
            throw new RuntimeException(esc_html__('Invalid authentication table requested.', 'uwh-site-migrator'));
        }
        $suffix = substr((string) $state['restore_id'], 0, 16);
        return array(
            'stage' => 'uwhsm_stage_' . $type . '_' . $suffix,
            'previous' => 'uwhsm_before_' . $type . '_' . $suffix,
        );
    }

    private function auth_shadow_table($source_table, array $state) {
        if (empty($state['auth_tables_atomic_swap'])) {
            return '';
        }
        $source_prefix = (string) ($state['manifest']['table_prefix'] ?? '');
        foreach (array('users', 'usermeta') as $type) {
            if ($source_table === $source_prefix . $type) {
                return $this->auth_shadow_names($state, $type)['stage'];
            }
        }
        return '';
    }

    /**
     * Make both source account tables live together only after all restore
     * validation has succeeded. An interrupted HTTP response can be retried:
     * either both staging tables exist, or both saved previous tables exist.
     */
    private function publish_restored_auth_tables(array $state) {
        if (empty($state['auth_tables_atomic_swap'])) {
            return;
        }
        global $wpdb;
        $pending = array();
        $published = array();
        foreach (array('users', 'usermeta') as $type) {
            $names = $this->auth_shadow_names($state, $type);
            $target = (string) $state['new_prefix'] . $type;
            $stage_exists = $this->restore_table_exists($names['stage']);
            $old_exists = $this->restore_table_exists($names['previous']);
            if (!$this->restore_table_exists($target) || ($stage_exists && $old_exists)) {
                throw new RuntimeException(esc_html__('Cannot safely publish restored WordPress accounts. Keep the restore state.', 'uwh-site-migrator'));
            }
            if ($stage_exists) {
                $pending[] = array('target' => $target, 'names' => $names, 'type' => $type);
            } elseif ($old_exists) {
                $published[] = $type;
            } else {
                throw new RuntimeException(esc_html__('Staged account data is missing. Resume or recover the previous restore.', 'uwh-site-migrator'));
            }
        }
        if (count($published) === 2) {
            return; // Already atomically published on an earlier request.
        }
        if (count($pending) !== 2 || $published) {
            throw new RuntimeException(esc_html__('Account-table publish state is inconsistent; manual recovery is required.', 'uwh-site-migrator'));
        }
        $sql = $wpdb->prepare(
            'RENAME TABLE %i TO %i, %i TO %i, %i TO %i, %i TO %i',
            $pending[0]['target'], $pending[0]['names']['previous'], $pending[0]['names']['stage'], $pending[0]['target'],
            $pending[1]['target'], $pending[1]['names']['previous'], $pending[1]['names']['stage'], $pending[1]['target']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomic account-table publish occurs only after the entire site import has been verified.
        $this->sql_ok($sql);
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }

    private function cleanup_previous_auth_tables(array $state) {
        if (empty($state['auth_tables_atomic_swap'])) {
            return;
        }
        global $wpdb;
        foreach (array('users', 'usermeta') as $type) {
            $names = $this->auth_shadow_names($state, $type);
            if ($this->restore_table_exists($names['previous'])) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Old account tables are removed only after the finished restore is durably saved.
                $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $names['previous']));
            }
        }
    }

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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE reads metadata only, never changes the schema; current engine is essential for safe restore retries.
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
            // The database has finished, but the source post/term slugs and
            // Elementor template metadata still need their resumable integrity
            // pass. Previously this jumped straight to finalize, which then
            // always rejected new (v2.4.17+) jobs at 99%.
            $s['phase'] = self::restore_needs_incremental_verify($s) ? 'verify' : 'finalize';
            return;
        }
        $old = $s['tables'][$s['table_index']];
        $table = $this->mapped_table($old, $s);
        $is_options_table = $table === $s['new_prefix'] . 'options';
        // Incomplete v2.4.5 restore jobs used direct imports. Resume those jobs
        // with their original format rather than expecting a nonexistent stage.
        $use_shadow = $is_options_table && !empty($s['options_atomic_swap']);
        $shadow = $use_shadow ? $this->options_shadow_names($s) : array();
        $auth_stage = $this->auth_shadow_table($old, $s);
        $import_table = $use_shadow ? $shadow['stage'] : ('' !== $auth_stage ? $auth_stage : $table);
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
            // If a response was lost after COMMIT, check the physical row count
            // again before deciding this table is finished. The options table
            // may already have been atomically published on a prior request.
            if (!$use_shadow || $this->restore_table_exists($import_table)) {
                $this->verify_imported_table_rows($import_table, (int) $s['integrity']['table_rows'][$old], $s, $old);
            }
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
            // A receipt tracks input rows, but duplicate unique keys or other
            // database behavior can leave fewer rows in the physical table.
            // Fail before claiming a complete migration.
            $this->verify_imported_table_rows($import_table, (int) $s['integrity']['table_rows'][$old], $s, $old);
            if ('InnoDB' === $original_engine) {
                // A previous release advanced table_index before publishing the
                // newly imported options shadow table. That left the old site's
                // homepage/theme/Elementor/plugin options LIVE after migration.
                // The receipt has committed; safely publish before advancing.
                if ($use_shadow) {
                    $this->publish_restored_options($table, $s);
                }
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
        // Page-builder JSON, CSS metadata and old widgets sometimes retain an
        // HTTP variant even when the source currently uses HTTPS (or vice versa).
        // Match the entire source URL with scheme, never a bare domain fragment.
        foreach (array('home_url' => 'new_home_url', 'site_url' => 'new_site_url') as $old => $new) {
            $source = untrailingslashit((string) ($s['manifest'][$old] ?? ''));
            $target = untrailingslashit((string) ($s[$new] ?? ''));
            if (preg_match('#^https?://#i', $source) && '' !== $target) {
                $alternate = (stripos($source, 'https://') === 0 ? 'http://' : 'https://') . substr($source, strpos($source, '://') + 3);
                if ($alternate !== $target) {
                    $map[$alternate] = $target;
                    $map[str_replace('/', '\\/', $alternate)] = str_replace('/', '\\/', $target);
                }
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
        // Slugs are site-local identifiers, not URLs. An overbroad URL replacement
        // in post_name or a taxonomy slug would silently change public routes.
        $source_prefix = (string) ($s['manifest']['table_prefix'] ?? '');
        $dest_prefix = (string) ($s['new_prefix'] ?? '');
        $slug_field = '';
        if ($table === $dest_prefix . 'posts') {
            $slug_field = 'post_name';
        } elseif ($table === $dest_prefix . 'terms') {
            $slug_field = 'slug';
        }
        $source_slug = '' !== $slug_field && array_key_exists($slug_field, $row) ? $row[$slug_field] : null;
        $row = $this->replace_recursive($row, $s);
        if ('' !== $slug_field && null !== $source_slug) {
            $row[$slug_field] = $source_slug;
        }
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

    /** Theme slugs are relative paths below the portable themes directory. */
    private function safe_theme_slug($theme) {
        return is_string($theme) && '' !== $theme && strlen($theme) < 200
            && $this->safe_entry($theme) && !preg_match('#(^|/)[.][.]?(/|$)#', $theme);
    }

    /**
     * Check the archived wp_options rows against the Reading Settings recorded
     * before export. This runs before any destination files/tables are replaced,
     * especially important when the operator opts out of a safety checkpoint.
     * Older archives have no snapshot and retain their previous restore path.
     */
    /** Verify source Elementor and theme-builder template posts survived export. */
    private function verify_archive_builder_templates(ZipArchive $zip, array $manifest) {
        $expected = $manifest['builder_template_ids'] ?? null;
        if (!is_array($expected)) {
            return; // Older backup archives do not include template inventories.
        }
        $types = array('elementor_library', 'elementor-hf', 'hfe_templates', 'wp_template', 'wp_template_part', 'wp_global_styles');
        foreach ($expected as $id => $type) {
            if (!ctype_digit((string) $id) || (int) $id < 1 || !in_array($type, $types, true)) {
                throw new RuntimeException(esc_html__('Invalid archived builder template inventory.', 'uwh-site-migrator'));
            }
        }
        if (!$expected) {
            return;
        }
        $entry = 'database/' . rawurlencode((string) $manifest['table_prefix'] . 'posts') . '.rows';
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new RuntimeException(esc_html__('The WordPress posts database export is missing.', 'uwh-site-migrator'));
        }
        $found = array();
        try {
            while (!feof($stream) && count($found) < count($expected)) {
                $line = fgets($stream);
                if (false === $line) {
                    break;
                }
                $decoded = base64_decode(trim($line), true);
                $row = false !== $decoded ? @unserialize($decoded, array('allowed_classes' => false)) : false;
                if (!is_array($row)) {
                    throw new RuntimeException(esc_html__('Invalid archived WordPress template data.', 'uwh-site-migrator'));
                }
                $id = (string) ($row['ID'] ?? '');
                if (array_key_exists($id, $expected) && (string) $row['post_type'] === (string) $expected[$id]) {
                    $found[$id] = true;
                }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after bounded builder metadata verification.
            fclose($stream);
        }
        if (count($found) !== count($expected)) {
            throw new RuntimeException(esc_html__('Elementor or theme-builder templates changed during backup. Create a fresh source backup.', 'uwh-site-migrator'));
        }
    }

    /**
     * Ensure every archived post/page/attachment and taxonomy slug survives a
     * cross-domain restore. This uses the archived rows as the source of truth,
     * not a fresh source-site query or a potentially stale WordPress cache.
     * Any changed slug is repaired ONLY when the original row ID exists, and is
     * checked again. Missing source rows are never hidden by this repair.
     */
    private function reconcile_restored_archive_slugs(array $state) {
        global $wpdb;
        $prefix = (string) ($state['manifest']['table_prefix'] ?? '');
        foreach (array(
            'posts' => array('id' => 'ID', 'slug' => 'post_name'),
            'terms' => array('id' => 'term_id', 'slug' => 'slug'),
        ) as $kind => $fields) {
            $source_table = $prefix . $kind;
            if (!array_key_exists($source_table, (array) ($state['integrity']['table_rows'] ?? array()))) {
                continue; // Backward-compatible for old, partial archives.
            }
            $target = $this->mapped_table($source_table, $state);
            if (!$this->restore_table_exists($target)) {
                throw new RuntimeException(esc_html__('Restored slug table is missing: ', 'uwh-site-migrator') . esc_html($kind));
            }
            $zip = $this->open_zip($state['archive']);
            try {
                $stream = $zip->getStream('database/' . rawurlencode($source_table) . '.rows');
                if (!is_resource($stream)) {
                    if (0 === (int) $state['integrity']['table_rows'][$source_table]) {
                        continue;
                    }
                    throw new RuntimeException(esc_html__('Archived slug rows are missing: ', 'uwh-site-migrator') . esc_html($kind));
                }
                try {
                    $batch = array();
                    while (!feof($stream)) {
                        $line = fgets($stream);
                        if (false === $line || '' === trim($line)) {
                            continue;
                        }
                        $raw = base64_decode(trim($line), true);
                        $row = false !== $raw ? @unserialize($raw, array('allowed_classes' => false)) : false;
                        if (!is_array($row) || !isset($row[$fields['id']]) || !array_key_exists($fields['slug'], $row)) {
                            throw new RuntimeException(esc_html__('Corrupt archived post or term slug record.', 'uwh-site-migrator'));
                        }
                        $id = (int) $row[$fields['id']];
                        if ($id < 1) {
                            throw new RuntimeException(esc_html__('Invalid source slug record ID.', 'uwh-site-migrator'));
                        }
                        $batch[$id] = (string) $row[$fields['slug']];
                        if (count($batch) >= 100) {
                            $this->reconcile_slug_batch($target, $fields, $batch);
                            $batch = array();
                        }
                    }
                    if ($batch) {
                        $this->reconcile_slug_batch($target, $fields, $batch);
                    }
                } finally {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after slug reconciliation.
                    fclose($stream);
                }
            } finally {
                $zip->close();
            }
        }
    }

    /** Compare IDs in bounded queries; never synthesize missing posts or terms. */
    private function reconcile_slug_batch($target, array $fields, array $batch) {
        global $wpdb;
        $ids = array_keys($batch);
        $query = 'SELECT %i, %i FROM %i WHERE %i IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify exact restored source slugs by primary key.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifiers use %i, bounded row IDs use %d, all bound through wpdb::prepare().
        $rows = $wpdb->get_results($wpdb->prepare($query, array_merge(array($fields['id'], $fields['slug'], $target, $fields['id']), $ids)), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows) || count($rows) !== count($batch)) {
            throw new RuntimeException(esc_html__('Restored page or taxonomy slug records are incomplete.', 'uwh-site-migrator'));
        }
        foreach ($rows as $row) {
            $id = (int) $row[$fields['id']];
            if (!array_key_exists($id, $batch)) {
                throw new RuntimeException(esc_html__('Unexpected slug record after restore.', 'uwh-site-migrator'));
            }
            if ((string) $row[$fields['slug']] !== $batch[$id]) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Correct only a verified source slug when WordPress changed it during import.
                if (false === $wpdb->update($target, array($fields['slug'] => $batch[$id]), array($fields['id'] => $id))) {
                    throw new RuntimeException(esc_html__('Cannot restore an original page or category slug.', 'uwh-site-migrator'));
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the migrated slug was actually saved.
                $check = $wpdb->get_row($wpdb->prepare('SELECT %i FROM %i WHERE %i = %d', $fields['slug'], $target, $fields['id'], $id), ARRAY_A);
                if (!is_array($check) || (string) $check[$fields['slug']] !== $batch[$id]) {
                    throw new RuntimeException(esc_html__('Restored slug differs from the source backup.', 'uwh-site-migrator'));
                }
            }
        }
    }

    /**
     * A template post can exist even while its Elementor layout/display rules
     * are missing. Check each source template's archived postmeta by meta_id,
     * including _elementor_data and header/footer assignment conditions.
     */
    private function verify_restored_builder_metadata(array $state) {
        global $wpdb;
        $templates = (array) ($state['manifest']['builder_template_ids'] ?? array());
        if (!$templates) {
            return;
        }
        $source_table = (string) $state['manifest']['table_prefix'] . 'postmeta';
        if (!array_key_exists($source_table, (array) ($state['integrity']['table_rows'] ?? array()))) {
            throw new RuntimeException(esc_html__('Source builder metadata table is missing.', 'uwh-site-migrator'));
        }
        $target = $this->mapped_table($source_table, $state);
        $zip = $this->open_zip($state['archive']);
        try {
            $stream = $zip->getStream('database/' . rawurlencode($source_table) . '.rows');
            if (!is_resource($stream)) {
                if (0 === (int) $state['integrity']['table_rows'][$source_table]) {
                    return;
                }
                throw new RuntimeException(esc_html__('Source builder metadata is missing from the backup.', 'uwh-site-migrator'));
            }
            try {
                $batch = array();
                while (!feof($stream)) {
                    $line = fgets($stream);
                    if (false === $line || '' === trim($line)) {
                        continue;
                    }
                    $raw = base64_decode(trim($line), true);
                    $row = false !== $raw ? @unserialize($raw, array('allowed_classes' => false)) : false;
                    if (!is_array($row)) {
                        throw new RuntimeException(esc_html__('Invalid source builder metadata row.', 'uwh-site-migrator'));
                    }
                    $id = (string) ($row['post_id'] ?? '');
                    if (!isset($templates[$id])) {
                        continue;
                    }
                    $key = (string) ($row['meta_key'] ?? '');
                    if ('_elementor_css' === $key || '_elementor_element_cache' === $key || '_edit_lock' === $key) {
                        continue; // Regenerable or runtime-only values.
                    }
                    $meta_id = (int) ($row['meta_id'] ?? 0);
                    if ($meta_id < 1) {
                        throw new RuntimeException(esc_html__('Invalid Elementor template metadata ID.', 'uwh-site-migrator'));
                    }
                    $row = $this->migrate_row($row, $target, $state);
                    $batch[$meta_id] = $row;
                    if (count($batch) >= 100) {
                        $this->verify_builder_meta_batch($target, $batch);
                        $batch = array();
                    }
                }
                if ($batch) {
                    $this->verify_builder_meta_batch($target, $batch);
                }
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after Elementor metadata verification.
                fclose($stream);
            }
        } finally {
            $zip->close();
        }
    }

    private function verify_builder_meta_batch($table, array $batch) {
        global $wpdb;
        $ids = array_keys($batch);
        $query = 'SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE meta_id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare verified source template metadata against the imported records.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier uses %i and every bounded meta_id uses a prepared %d placeholder.
        $rows = $wpdb->get_results($wpdb->prepare($query, array_merge(array($table), $ids)), ARRAY_A);
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Cannot inspect restored Elementor template metadata.', 'uwh-site-migrator'));
        }
        $found = array();
        foreach ($rows as $row) {
            $found[(int) $row['meta_id']] = $row;
        }
        foreach ($batch as $id => $expected) {
            $actual = $found[$id] ?? null;
            // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- These are result-array keys for a bounded primary-key lookup, not SQL meta filters.
            $expected_meta = array(
                'post_id' => $expected['post_id'],
                'meta_key' => $expected['meta_key'],
                'meta_value' => $expected['meta_value'],
            );
            // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            if (!is_array($actual)) {
                // Recreate only a row whose exact contents were checked in the
                // source archive and imported with a durable table receipt.
                $insert = $expected_meta;
                $insert['meta_id'] = $id;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore an archive-verified missing builder metadata row.
                if (false === $wpdb->insert($table, $insert)) {
                    throw new RuntimeException(esc_html__('Cannot restore missing Elementor template metadata.', 'uwh-site-migrator'));
                }
            } elseif ((string) $actual['post_id'] !== (string) $expected['post_id']
                || (string) $actual['meta_key'] !== (string) $expected['meta_key']
                || (string) $actual['meta_value'] !== (string) $expected['meta_value']) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore verified source header/footer content and display conditions.
                if (false === $wpdb->update($table, $expected_meta, array('meta_id' => $id))) {
                    throw new RuntimeException(esc_html__('Cannot reconcile Elementor template metadata.', 'uwh-site-migrator'));
                }
            } else {
                continue;
            }
            // Re-read after writing: never claim success on a failed or ignored update.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirm the saved Elementor values directly in MySQL.
            $confirmed = $wpdb->get_row($wpdb->prepare('SELECT post_id, meta_key, meta_value FROM %i WHERE meta_id = %d', $table, $id), ARRAY_A);
            if ($wpdb->last_error || !is_array($confirmed)
                || (string) $confirmed['post_id'] !== (string) $expected['post_id']
                || (string) $confirmed['meta_key'] !== (string) $expected['meta_key']
                || (string) $confirmed['meta_value'] !== (string) $expected['meta_value']) {
                throw new RuntimeException(esc_html__('Elementor template layout or display conditions did not transfer completely.', 'uwh-site-migrator'));
            }
        }
    }

    /**
     * New restore jobs must verify every archived slug/template batch before
     * finalizing. Legacy restore jobs did not have this flag, so their saved
     * state remains backward compatible with the original finalization path.
     */
    /**
     * Assert physical destination parity with every restorable archived file.
     * Cursors make the final comparison resumable on shared hosting; a
     * missing/modified file is a hard failure, never a silent success.
     */
    private function verify_restored_files_batch(array &$state) {
        $files = (array) ($state['site_files'] ?? array());
        $index = (int) ($state['verify_files_index'] ?? 0);
        $end = min(count($files), $index + 112);
        $started = microtime(true);
        $own = wp_normalize_path('wp-content/plugins/' . plugin_basename(UWHSM_DIR));
        for ($i = $index; $i < $end; $i++) {
            $entry = (string) $files[$i];
            $rel = substr($entry, 5);
            $norm = wp_normalize_path($rel);
            if ('wp-config.php' === $norm || 0 === strpos($norm, trailingslashit($own)) || $this->skip_restore_file($norm)) {
                $state['verify_files_index'] = $i + 1;
                continue;
            }
            $check = (array) ($state['integrity']['files'][$entry] ?? array());
            $target = UWHSM_Paths::restore_target($rel);
            if (!is_file($target) || !is_readable($target)
                || (int) filesize($target) !== (int) ($check['size'] ?? -1)
                || !hash_equals((string) ($check['sha256'] ?? ''), (string) hash_file('sha256', $target))) {
                throw new RuntimeException(esc_html__('Migration cannot finish: missing or modified website file: ', 'uwh-site-migrator') . esc_html($rel));
            }
            $state['verify_files_index'] = $i + 1;
            if (microtime(true) - $started >= 2.0) {
                break;
            }
        }
        if ((int) $state['verify_files_index'] >= count($files)) {
            $state['files_verified'] = true;
            $state['phase'] = 'finalize';
        }
    }

    private static function restore_needs_incremental_verify(array $state) {
        return !empty($state['incremental_template_verify']) && empty($state['builder_and_slugs_verified']);
    }

    /**
     * Read at most a few seconds of integrity data per AJAX request. The state
     * holds a byte offset into already checksum-verified extracted rows, so a
     * dropped HTTP response can safely repeat the same checks without losing
     * the migration or forcing a long 99% request.
     */
    private function run_restore_integrity_batch(array &$state) {
        $kinds = array('posts', 'terms', 'postmeta');
        $index = (int) ($state['verify_kind_index'] ?? 0);
        if ($index >= count($kinds)) {
            $state['builder_and_slugs_verified'] = true;
            $state['phase'] = !empty($state['verify_restored_files']) ? 'verify_files' : 'finalize';
            unset($state['verify_byte_offset'], $state['verify_kind_index']);
            return;
        }
        $kind = $kinds[$index];
        $source_table = (string) ($state['manifest']['table_prefix'] ?? '') . $kind;
        $counts = (array) ($state['integrity']['table_rows'] ?? array());
        if (!array_key_exists($source_table, $counts) || 0 === (int) $counts[$source_table]
            || ('postmeta' === $kind && empty($state['manifest']['builder_template_ids']))) {
            $state['verify_kind_index'] = $index + 1;
            $state['verify_byte_offset'] = 0;
            return;
        }
        $entry = 'database/' . rawurlencode($source_table) . '.rows';
        $file = $this->import_dir . '/' . $state['restore_id'] . '/extracted/' . $entry;
        if (!is_file($file)) {
            $this->recover_staged_file($entry, $file, $state);
        }
        $fh = new SplFileObject($file, 'rb');
        $offset = (int) ($state['verify_byte_offset'] ?? 0);
        if (0 !== $fh->fseek($offset)) {
            throw new RuntimeException(esc_html__('Cannot resume database identity verification.', 'uwh-site-migrator'));
        }
        $fields = 'posts' === $kind ? array('id' => 'ID', 'slug' => 'post_name') : array('id' => 'term_id', 'slug' => 'slug');
        $table = $this->mapped_table($source_table, $state);
        $batch = array();
        $read = 0;
        $bytes_read = 0;
        $start = microtime(true);
        // Read more lightweight slug rows per request, but limit CPU time and
        // total decoded input so large Elementor JSON never exhausts PHP memory.
        $limit = 'postmeta' === $kind ? 5000 : 12000;
        $byte_limit = 10 * 1024 * 1024;
        while (!$fh->eof() && $read < $limit && $bytes_read < $byte_limit && microtime(true) - $start < 5) {
            $line = $fh->fgets();
            $bytes_read += is_string($line) ? strlen($line) : 0;
            if (false === $line || '' === trim($line)) {
                continue;
            }
            $raw = base64_decode(trim($line), true);
            $row = false !== $raw ? @unserialize($raw, array('allowed_classes' => false)) : false;
            if (!is_array($row)) {
                throw new RuntimeException(esc_html__('Corrupt archived slug or template metadata row.', 'uwh-site-migrator'));
            }
            ++$read;
            if ('postmeta' === $kind) {
                $post_id = (string) ($row['post_id'] ?? '');
                if (!isset($state['manifest']['builder_template_ids'][$post_id])) {
                    continue;
                }
                $key = (string) ($row['meta_key'] ?? '');
                if (in_array($key, array('_elementor_css', '_elementor_element_cache', '_edit_lock'), true)) {
                    continue;
                }
                $id = (int) ($row['meta_id'] ?? 0);
                if ($id < 1) {
                    throw new RuntimeException(esc_html__('Invalid source template metadata identifier.', 'uwh-site-migrator'));
                }
                $batch[$id] = $this->migrate_row($row, $table, $state);
            } else {
                $id = (int) ($row[$fields['id']] ?? 0);
                if ($id < 1 || !array_key_exists($fields['slug'], $row)) {
                    throw new RuntimeException(esc_html__('Invalid archived slug record.', 'uwh-site-migrator'));
                }
                $batch[$id] = (string) $row[$fields['slug']];
            }
            if (count($batch) >= ('postmeta' === $kind ? 100 : 250)) {
                if ('postmeta' === $kind) {
                    $this->verify_builder_meta_batch($table, $batch);
                } else {
                    $this->reconcile_slug_batch($table, $fields, $batch);
                }
                $batch = array();
            }
        }
        if ($batch) {
            if ('postmeta' === $kind) {
                $this->verify_builder_meta_batch($table, $batch);
            } else {
                $this->reconcile_slug_batch($table, $fields, $batch);
            }
        }
        $state['verify_byte_offset'] = $fh->ftell();
        if ($fh->eof()) {
            $state['verify_kind_index'] = $index + 1;
            $state['verify_byte_offset'] = 0;
        }
    }

    /** Check source template IDs still exist with their exact post types. */
    private function verify_restored_builder_templates(array $state) {
        global $wpdb;
        $expected = $state['manifest']['builder_template_ids'] ?? null;
        if (!is_array($expected) || !$expected) {
            return;
        }
        $posts = (string) $state['new_prefix'] . 'posts';
        foreach (array_chunk($expected, 100, true) as $chunk) {
            $ids = array_keys($chunk);
            $query = 'SELECT ID, post_type FROM %i WHERE ID IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify every restored Elementor/header/footer template directly in the destination database.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier uses %i and every archived template ID uses a prepared %d placeholder.
            $rows = $wpdb->get_results($wpdb->prepare($query, array_merge(array($posts), $ids)), ARRAY_A);
            if ($wpdb->last_error || !is_array($rows)) {
                throw new RuntimeException(esc_html__('Cannot verify restored Elementor and theme-builder templates.', 'uwh-site-migrator'));
            }
            $found = array();
            foreach ($rows as $row) {
                $id = (string) ($row['ID'] ?? '');
                if (isset($chunk[$id]) && $chunk[$id] === (string) ($row['post_type'] ?? '')) {
                    $found[$id] = true;
                }
            }
            if (count($found) !== count($chunk)) {
                throw new RuntimeException(esc_html__('A source Elementor header/footer or theme-builder template was not restored.', 'uwh-site-migrator'));
            }
        }
    }

    /** Check the original identity fields inside the .uwh options rows. */
    private function verify_archive_site_identity(ZipArchive $zip, array $manifest) {
        $expected = $manifest['site_identity_settings'] ?? null;
        if (!is_array($expected)) {
            return; // Older archive versions remain valid.
        }
        $allowed = array('blogname', 'blogdescription', 'site_icon', 'site_logo', 'elementor_active_kit', 'permalink_structure', 'category_base', 'tag_base');
        if (!array_key_exists('blogname', $expected)) {
            throw new RuntimeException(esc_html__('The source site title snapshot is missing.', 'uwh-site-migrator'));
        }
        foreach ($expected as $key => $value) {
            if (!in_array($key, $allowed, true) || !is_string($value)) {
                throw new RuntimeException(esc_html__('Invalid source site identity snapshot.', 'uwh-site-migrator'));
            }
        }
        $entry = 'database/' . rawurlencode((string) ($manifest['table_prefix'] ?? '') . 'options') . '.rows';
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new RuntimeException(esc_html__('The backup contains no site settings rows.', 'uwh-site-migrator'));
        }
        $actual = array();
        try {
            while (!feof($stream) && count($actual) < count($expected)) {
                $line = fgets($stream);
                if (false === $line) {
                    break;
                }
                $raw = base64_decode(trim($line), true);
                $row = false !== $raw ? @unserialize($raw, array('allowed_classes' => false)) : false;
                if (!is_array($row)) {
                    throw new RuntimeException(esc_html__('Invalid archived WordPress settings row.', 'uwh-site-migrator'));
                }
                $key = (string) ($row['option_name'] ?? '');
                if (array_key_exists($key, $expected)) {
                    $actual[$key] = (string) ($row['option_value'] ?? '');
                }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after source identity validation.
            fclose($stream);
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual) || !hash_equals($value, $actual[$key])) {
                throw new RuntimeException(esc_html__('Site title or Elementor site settings changed during backup. Create a fresh source backup.', 'uwh-site-migrator'));
            }
        }
    }

    /**
     * WordPress or page builders may rewrite options during a long AJAX restore.
     * Restore only fields independently verified inside the source archive.
     */
    private function reconcile_restored_site_identity(array $state) {
        global $wpdb;
        $expected = $state['manifest']['site_identity_settings'] ?? null;
        if (!is_array($expected)) {
            return;
        }
        $zip = $this->open_zip($state['archive']);
        try {
            $this->verify_archive_site_identity($zip, (array) $state['manifest']);
        } finally {
            $zip->close();
        }
        $options = (string) $state['new_prefix'] . 'options';
        $posts = (string) $state['new_prefix'] . 'posts';
        foreach ($expected as $key => $value) {
            $value = UWHSM_Value_Rewriter::rewrite($value, $this->replacement_map($state));
            // Verify references used by theme builders and site identity. A
            // missing kit/page is a real incomplete migration, not a setting
            // that should be silently removed or replaced with the default.
            if (in_array($key, array('site_icon', 'site_logo', 'elementor_active_kit'), true) && ctype_digit((string) $value) && (int) $value > 0) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify actual restored site identity references.
                $found = $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE ID = %d LIMIT 1', $posts, (int) $value));
                if (null === $found) {
                    if (in_array($key, array('site_icon', 'site_logo'), true)) {
                        continue; // Missing optional source logo/icon is not fatal.
                    }
                    throw new RuntimeException(esc_html__('The migrated Elementor kit is missing from the restored database.', 'uwh-site-migrator'));
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct reconciliation of verified source configuration.
            // get_var() maps an existing empty option_value to null. Site
            // taglines are legitimately empty; read the row to distinguish
            // empty values from genuinely missing options.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the exact durable restored option instead of potentially cached WordPress options.
            $current_row = $wpdb->get_row($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $options, $key), ARRAY_A);
            if ($wpdb->last_error) {
                throw new RuntimeException(esc_html__('Could not read restored source website setting: ', 'uwh-site-migrator') . esc_html($key));
            }
            $current = is_array($current_row) ? (string) $current_row['option_value'] : null;
            if (null === $current) {
                // During long imports WordPress may drop or recreate an option.
                // Its value was independently verified against the archived
                // source rows, so restoring this *specific* missing key is safe.
                // This is not permission to ignore missing database rows.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore an archive-verified WordPress setting if its row was removed by concurrent runtime code.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Restore one pre-verified missing option row; this is not a database schema change.
                if (false === $wpdb->insert($options, array('option_name' => $key, 'option_value' => $value, 'autoload' => 'yes'))) {
                    throw new RuntimeException(esc_html__('Could not re-create verified source website setting: ', 'uwh-site-migrator') . esc_html($key));
                }
            } elseif ((string) $current !== (string) $value) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only source-verified settings can be reapplied.
                if (false === $wpdb->update($options, array('option_value' => $value), array('option_name' => $key))) {
                    throw new RuntimeException(esc_html__('Failed to restore source website setting: ', 'uwh-site-migrator') . esc_html($key));
                }
            }
            // Always check the stored value, not an in-memory options cache.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies the source-verified setting survived the write.
            $confirmed_row = $wpdb->get_row($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $options, $key), ARRAY_A);
            if ($wpdb->last_error || !is_array($confirmed_row) || !hash_equals((string) $value, (string) $confirmed_row['option_value'])) {
                throw new RuntimeException(esc_html__('The source website setting could not be verified after restoration: ', 'uwh-site-migrator') . esc_html($key));
            }
        }
    }

    /**
     * A full-site migration replaces old site tables with the archived table set.
     * Drop only the destination WordPress prefix's extra physical tables after
     * ALL source tables passed verification. Never touch other prefixes or
     * unprefixed tables on a shared database.
     *
     * The operation is idempotent if finalization has to be resumed.
     */
    private function prune_replaced_destination_tables(array $state) {
        global $wpdb;
        if (!in_array((string) ($state['manifest']['database_scope'] ?? ''), array('full_source_database', 'wordpress_prefix_tables'), true)) {
            return; // Never prune for legacy prefix-only backups.
        }
        if (!empty($state['is_rollback']) || in_array((string) ($state['source_mode'] ?? ''), array('rollback', 'checkpoint'), true)) {
            return; // Preserve the rollback job's separate restoration semantics.
        }
        $prefix = (string) ($state['new_prefix'] ?? '');
        if ('' === $prefix || !preg_match('/^[A-Za-z0-9_]+$/D', $prefix)) {
            throw new RuntimeException(esc_html__('Destination database prefix is invalid.', 'uwh-site-migrator'));
        }
        $keep = array();
        foreach ((array) ($state['integrity']['table_rows'] ?? array()) as $source_table => $count) {
            $target = $this->mapped_table($source_table, $state);
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $target) || isset($keep[$target])) {
                throw new RuntimeException(esc_html__('Conflicting destination database table names after prefix mapping.', 'uwh-site-migrator'));
            }
            $keep[$target] = true;
        }
        $like = $wpdb->esc_like($prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Discover leftover tables belonging to the replaced destination site only.
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
        if ($wpdb->last_error || !is_array($tables)) {
            throw new RuntimeException(esc_html__('Could not check destination tables before finalizing migration.', 'uwh-site-migrator'));
        }
        $receipt = $this->restore_progress_table($state);
        $this->sql_ok('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if (isset($keep[$table]) || $table === $receipt
                || preg_match('/^' . preg_quote($prefix, '/') . '(?:uwhsm_progress_|svm_progress_)[a-f0-9]{12}$/D', $table)) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table) || 0 !== strpos($table, $prefix)) {
                throw new RuntimeException(esc_html__('Unsafe destination table name during site replacement.', 'uwh-site-migrator'));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Required by exact site replacement: drop only validated WordPress-prefix tables absent from verified source inventory (never other sites' tables).
            $this->sql_ok($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
        }
    }

    private function verify_archive_front_page_settings(ZipArchive $zip, array $manifest) {
        $expected = $manifest['front_page_settings'] ?? null;
        if (!is_array($expected)) {
            return;
        }
        $needed = array('show_on_front', 'page_on_front', 'page_for_posts');
        foreach ($needed as $key) {
            if (!array_key_exists($key, $expected) || (null !== $expected[$key] && !is_scalar($expected[$key]))) {
                throw new RuntimeException(esc_html__('Backup contains invalid source Reading Settings.', 'uwh-site-migrator'));
            }
        }
        $table = (string) ($manifest['table_prefix'] ?? '') . 'options';
        $entry = 'database/' . rawurlencode($table) . '.rows';
        $stream = $zip->getStream($entry);
        if (!is_resource($stream)) {
            throw new RuntimeException(esc_html__('Backup is missing the WordPress Reading Settings table rows.', 'uwh-site-migrator'));
        }
        $actual = array();
        try {
            while (!feof($stream) && count($actual) < count($needed)) {
                $line = fgets($stream);
                if (false === $line) {
                    break;
                }
                $raw = base64_decode(trim($line), true);
                $row = false === $raw ? false : @unserialize($raw, array('allowed_classes' => false));
                if (!is_array($row)) {
                    throw new RuntimeException(esc_html__('Backup contains invalid database option data.', 'uwh-site-migrator'));
                }
                $name = isset($row['option_name']) ? (string) $row['option_name'] : '';
                if (in_array($name, $needed, true)) {
                    $actual[$name] = (string) ($row['option_value'] ?? '');
                }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after source reading-settings validation.
            fclose($stream);
        }
        foreach ($needed as $key) {
            $mismatch = null === $expected[$key] ? array_key_exists($key, $actual)
                : (!array_key_exists($key, $actual) || (string) $expected[$key] !== $actual[$key]);
            if ($mismatch) {
                throw new RuntimeException(
                    sprintf(
                        /* translators: %s: WordPress Reading Setting key. */
                        esc_html__('Backup contains incomplete or changed Reading Settings (%s). Create a fresh backup on the source site.', 'uwh-site-migrator'),
                        esc_html($key)
                    )
                );
            }
        }
    }

    /**
     * Confirm that the source theme reported in the manifest is also present
     * in the source database rows inside the archive. This detects backups
     * taken during a theme switch and avoids applying an invented theme.
     * It runs before destructive changes and again for older paused jobs.
     */
    private function verify_archive_active_theme_settings(ZipArchive $zip, array $manifest) {
        if (!array_key_exists('active_theme_stylesheet', $manifest) || !array_key_exists('active_theme_template', $manifest)) {
            return; // Legacy backups have no authoritative theme snapshot.
        }
        $expected = array(
            'stylesheet' => $manifest['active_theme_stylesheet'],
            'template' => $manifest['active_theme_template'],
        );
        foreach ($expected as $theme) {
            if (!$this->safe_theme_slug($theme)) {
                throw new RuntimeException(esc_html__('Backup contains an invalid active theme snapshot.', 'uwh-site-migrator'));
            }
        }
        $table = (string) ($manifest['table_prefix'] ?? '') . 'options';
        $stream = $zip->getStream('database/' . rawurlencode($table) . '.rows');
        if (!is_resource($stream)) {
            throw new RuntimeException(esc_html__('Backup is missing active theme database rows.', 'uwh-site-migrator'));
        }
        $actual = array();
        try {
            while (!feof($stream) && count($actual) < 2) {
                $line = fgets($stream);
                if (false === $line) {
                    break;
                }
                $raw = base64_decode(trim($line), true);
                $row = false === $raw ? false : @unserialize($raw, array('allowed_classes' => false));
                if (!is_array($row)) {
                    throw new RuntimeException(esc_html__('Backup contains invalid theme option rows.', 'uwh-site-migrator'));
                }
                $name = (string) ($row['option_name'] ?? '');
                if (array_key_exists($name, $expected)) {
                    $actual[$name] = (string) ($row['option_value'] ?? '');
                }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close streamed ZIP rows after source theme-settings validation.
            fclose($stream);
        }
        foreach ($expected as $name => $theme) {
            if (!array_key_exists($name, $actual) || !hash_equals((string) $theme, $actual[$name])) {
                throw new RuntimeException(
                    sprintf(
                        /* translators: %s: WordPress theme option (template or stylesheet). */
                        esc_html__('The active theme changed while the backup was created (%s). Create a new source backup.', 'uwh-site-migrator'),
                        esc_html($name)
                    )
                );
            }
        }
    }

    /**
     * Verify source rows survive a restore. WordPress can append its own post
     * metadata between AJAX batches, so an increased destination row count
     * does not by itself mean the backup was imported incompletely. In that
     * narrow case, compare every original postmeta row with the backup rather
     * than blindly accepting a greater-than-expected COUNT(*).
     */
    private function verify_imported_table_rows($table, $expected, array $state = array(), $source_table = '') {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Durable count verification cannot rely on cached objects.
        $actual = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        if (null !== $actual && (int) $actual === (int) $expected) {
            return;
        }
        $source_prefix = (string) ($state['manifest']['table_prefix'] ?? '');
        $destination_prefix = (string) ($state['new_prefix'] ?? '');
        if (null !== $actual && (int) $actual > (int) $expected
            && '' !== $source_prefix && '' !== $destination_prefix
            && $source_table === $source_prefix . 'postmeta'
            && $table === $destination_prefix . 'postmeta') {
            // A completed import receipt proves the source file was consumed,
            // but only archive-to-destination comparison proves no source row
            // was lost or overwritten by a concurrent plugin/cron request.
            $this->verify_restored_postmeta_source_rows($table, $source_table, $state, (int) $expected);
            UWHSM_Logger::log_once(
                'restore-postmeta-extra-' . sanitize_key((string) ($state['restore_id'] ?? '')),
                'restore', 'warning',
                __('WordPress added post metadata during migration; all source backup rows were individually verified.', 'uwh-site-migrator'),
                array('table' => $table, 'backup_rows' => (int) $expected, 'destination_rows' => (int) $actual),
                DAY_IN_SECONDS
            );
            return;
        }
        throw new RuntimeException(
            sprintf(
                /* translators: 1: table name, 2: expected row count, 3: imported row count. */
                esc_html__('Database restore incomplete for %1$s: expected %2$d rows, found %3$d.', 'uwh-site-migrator'),
                esc_html($table), (int) $expected, (int) $actual
            )
        );
    }

    /**
     * Compare every source postmeta ID and its restored values in bounded
     * batches. Accept extra rows only when all original metadata is intact.
     * No rows are removed or modified here; a corrupt import fails closed.
     */
    private function verify_restored_postmeta_source_rows($table, $source_table, array $state, $expected) {
        global $wpdb;
        $entry = 'database/' . rawurlencode($source_table) . '.rows';
        $file = $this->import_dir . '/' . $state['restore_id'] . '/extracted/' . $entry;
        if (!is_file($file)) {
            if (empty($state['integrity']['files'][$entry])) {
                throw new RuntimeException(esc_html__('Post metadata verification data is missing from the backup.', 'uwh-site-migrator'));
            }
            $this->recover_staged_file($entry, $file, $state);
        }
        try {
            $stream = new SplFileObject($file, 'rb');
        } catch (Throwable $error) {
            throw new RuntimeException(esc_html__('Could not open the original post metadata for verification.', 'uwh-site-migrator'));
        }
        $batch = array();
        $verified = 0;
        while (!$stream->eof()) {
            $line = trim($stream->fgets());
            if ('' === $line) {
                continue;
            }
            $decoded = base64_decode($line, true);
            $row = false !== $decoded ? @unserialize($decoded, array('allowed_classes' => false)) : false;
            if (!is_array($row) || !isset($row['meta_id']) || !ctype_digit((string) $row['meta_id'])
                || '0' === (string) $row['meta_id']) {
                throw new RuntimeException(esc_html__('The archived post metadata contains an invalid row identifier.', 'uwh-site-migrator'));
            }
            $row = $this->migrate_row($row, $table, $state);
            $batch[(string) $row['meta_id']] = $row;
            if (count($batch) >= 50) {
                $this->verify_postmeta_batch($table, $batch);
                $verified += count($batch);
                $batch = array();
            }
        }
        if ($batch) {
            $this->verify_postmeta_batch($table, $batch);
            $verified += count($batch);
        }
        if ($verified !== $expected) {
            throw new RuntimeException(esc_html__('Source post metadata row count changed during verification.', 'uwh-site-migrator'));
        }
    }

    /** Compare the actual restored values, not only the primary key IDs. */
    private function verify_postmeta_batch($table, array $expected_rows) {
        global $wpdb;
        $ids = array_keys($expected_rows);
        if (!$ids) {
            return;
        }
        $sql = 'SELECT meta_id, post_id, meta_key, meta_value FROM %i WHERE meta_id IN ('
            . implode(', ', array_fill(0, count($ids), '%s')) . ')';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Archive row validation must read the actual restored values, bypassing caches.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier uses %i and all archived metadata IDs have bound %s placeholders.
        $found = $wpdb->get_results($wpdb->prepare($sql, array_merge(array($table), $ids)), ARRAY_A);
        if ($wpdb->last_error || !is_array($found) || count($found) !== count($expected_rows)) {
            throw new RuntimeException(esc_html__('Restored post metadata is missing source rows; resume or recover the restore.', 'uwh-site-migrator'));
        }
        foreach ($found as $row) {
            $id = (string) ($row['meta_id'] ?? '');
            if (!isset($expected_rows[$id])) {
                throw new RuntimeException(esc_html__('Restored post metadata contains an unexpected row identifier.', 'uwh-site-migrator'));
            }
            foreach (array('post_id', 'meta_key', 'meta_value') as $field) {
                $expected = $expected_rows[$id][$field] ?? null;
                $actual = $row[$field] ?? null;
                if ((null === $expected) !== (null === $actual)
                    || (null !== $expected && (string) $expected !== (string) $actual)) {
                    throw new RuntimeException(esc_html__('Restored post metadata differs from the source backup for meta_id ', 'uwh-site-migrator') . esc_html($id));
                }
            }
        }
    }

    /**
     * Reapply the archive-verified Reading Settings only after every table
     * and its import receipt have passed verification. WordPress (or another
     * plugin) may update page_on_front during a long restore while wp_posts is
     * temporarily incomplete. The manifest and archive rows were compared
     * before destructive import; never infer or invent a page ID here.
     *
     * This also repairs an interrupted v2.4.11 restore paused at finalize:
     * its existing job can safely retry without restarting database import.
     */
    private function reconcile_restored_front_page(array $state) {
        global $wpdb;
        $source = $state['manifest']['front_page_settings'] ?? null;
        if (!is_array($source)) {
            return; // Legacy archives: no authoritative snapshot to restore.
        }
        $names = array('show_on_front', 'page_on_front', 'page_for_posts');
        foreach ($names as $name) {
            if (!array_key_exists($name, $source) || (null !== $source[$name] && !is_scalar($source[$name]))) {
                throw new RuntimeException(esc_html__('Invalid backup Reading Settings snapshot.', 'uwh-site-migrator'));
            }
        }
        $mode = (string) ($source['show_on_front'] ?? 'posts');
        if (!in_array($mode, array('page', 'posts'), true)) {
            throw new RuntimeException(esc_html__('Invalid source homepage mode.', 'uwh-site-migrator'));
        }
        $front = (int) ($source['page_on_front'] ?? 0);
        if ('page' === $mode && $front < 1) {
            throw new RuntimeException(esc_html__('Source homepage page ID is invalid.', 'uwh-site-migrator'));
        }
        $posts = (string) $state['new_prefix'] . 'posts';
        foreach (array('page_on_front', 'page_for_posts') as $name) {
            $id = (int) ($source[$name] ?? 0);
            if ($id < 1 || ('page_on_front' === $name && 'page' !== $mode)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check that the source page ID was actually restored before resetting Reading Settings.
            $found = $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE ID = %d AND post_type = %s LIMIT 1', $posts, $id, 'page'));
            if (null === $found) {
                throw new RuntimeException(
                    sprintf(
                        /* translators: %s: WordPress Reading Setting name. */
                        esc_html__('Cannot restore Reading Settings because the selected %s page is missing from the imported database.', 'uwh-site-migrator'),
                        esc_html($name)
                    )
                );
            }
        }
        $options = (string) $state['new_prefix'] . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read real DB values; object cache can be stale during a cross-domain restore.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s)',
                $options, 'show_on_front', 'page_on_front', 'page_for_posts'
            ),
            ARRAY_A
        );
        if ($wpdb->last_error || !is_array($rows)) {
            throw new RuntimeException(esc_html__('Could not inspect restored Reading Settings.', 'uwh-site-migrator'));
        }
        $actual = array();
        foreach ($rows as $row) {
            $actual[(string) $row['option_name']] = (string) $row['option_value'];
        }
        $repaired = array();
        foreach ($names as $name) {
            if (null === $source[$name]) {
                if (array_key_exists($name, $actual)) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Remove an option that was absent in the verified source snapshot, not unrelated destination settings.
                    if (false === $wpdb->delete($options, array('option_name' => $name))) {
                        throw new RuntimeException(esc_html__('Could not reconcile restored Reading Settings.', 'uwh-site-migrator'));
                    }
                    $repaired[] = $name;
                }
                continue;
            }
            $expected = (string) $source[$name];
            if (array_key_exists($name, $actual) && $actual[$name] === $expected) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore only the three verified Reading Settings; never change unrelated option values.
            $result = array_key_exists($name, $actual)
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore only archive-verified Reading Settings in the new prefix during migration.
                ? $wpdb->update($options, array('option_value' => $expected), array('option_name' => $name))
                : $wpdb->insert($options, array('option_name' => $name, 'option_value' => $expected, 'autoload' => 'yes'));
            if (false === $result) {
                throw new RuntimeException(esc_html__('Could not reconcile restored Reading Settings.', 'uwh-site-migrator'));
            }
            $repaired[] = $name;
        }
        if ($repaired) {
            if (function_exists('wp_cache_delete')) {
                foreach ($repaired as $name) {
                    wp_cache_delete($name, 'options');
                }
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
            }
            UWHSM_Logger::log('restore', 'warning', __('Verified Reading Settings were reapplied after a runtime change during migration.', 'uwh-site-migrator'), array('options' => $repaired, 'restore_id' => (string) ($state['restore_id'] ?? '')));
        }
    }

    /**
     * Validate static homepage and blog-page selection from the restored DB.
     * New archives also carry authoritative source Reading Settings so missing
     * or altered options are detected rather than silently resetting to posts.
     */
    private function verify_restored_front_page(array $state) {
        global $wpdb;
        $options = (string) $state['new_prefix'] . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypass stale persistent caches during a database migration.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s)',
                $options, 'show_on_front', 'page_on_front', 'page_for_posts'
            ),
            ARRAY_A
        );
        if ($wpdb->last_error) {
            throw new RuntimeException(esc_html__('Could not verify restored Reading Settings.', 'uwh-site-migrator'));
        }
        $actual = array();
        foreach ((array) $rows as $row) {
            $actual[(string) $row['option_name']] = (string) $row['option_value'];
        }
        $source = $state['manifest']['front_page_settings'] ?? null;
        if (is_array($source)) {
            foreach (array('show_on_front', 'page_on_front', 'page_for_posts') as $key) {
                $mismatch = !array_key_exists($key, $source)
                    || (null === $source[$key] ? array_key_exists($key, $actual)
                    : (!array_key_exists($key, $actual) || (string) $source[$key] !== $actual[$key]));
                if ($mismatch) {
                    throw new RuntimeException(
                        sprintf(
                            /* translators: %s: WordPress Reading Setting key. */
                            esc_html__('Homepage/Reading Setting was not transferred completely: %s.', 'uwh-site-migrator'),
                            esc_html($key)
                        )
                    );
                }
            }
        }
        $mode = $actual['show_on_front'] ?? 'posts';
        if (!in_array($mode, array('posts', 'page'), true)) {
            throw new RuntimeException(esc_html__('Restored Reading Settings contain an invalid homepage mode.', 'uwh-site-migrator'));
        }
        if ('page' !== $mode) {
            return;
        }
        $front = isset($actual['page_on_front']) ? (int) $actual['page_on_front'] : 0;
        if ($front < 1) {
            throw new RuntimeException(esc_html__('The source uses a static homepage, but the restored homepage page ID is missing.', 'uwh-site-migrator'));
        }
        $posts = (string) $state['new_prefix'] . 'posts';
        foreach (array('page_on_front' => $front, 'page_for_posts' => isset($actual['page_for_posts']) ? (int) $actual['page_for_posts'] : 0) as $option => $id) {
            if ($id < 1) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify actual imported posts instead of cached page objects.
            $found = $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE ID = %d AND post_type = %s LIMIT 1', $posts, $id, 'page'));
            if (null === $found) {
                throw new RuntimeException(
                    sprintf(
                        /* translators: %s: WordPress Reading Setting key. */
                        esc_html__('The selected %s page is missing from the restored database.', 'uwh-site-migrator'),
                        esc_html($option)
                    )
                );
            }
        }
    }

    /**
     * Restore the verified source site's active theme, regardless of which
     * default theme the destination had before import. WordPress can reset
     * template/stylesheet when a restore spans multiple AJAX requests. Only
     * reconcile options from an archive whose original database values match
     * the source manifest and whose theme files passed SHA-256 verification.
     * The operation is safe to repeat when resuming a finalize step.
     */
    private function reconcile_restored_active_theme(array $state) {
        global $wpdb;
        $manifest = (array) ($state['manifest'] ?? array());
        if (!array_key_exists('active_theme_stylesheet', $manifest) || !array_key_exists('active_theme_template', $manifest)) {
            return; // Legacy archives: keep earlier behavior without guessing.
        }
        $zip = $this->open_zip($state['archive']);
        try {
            $this->verify_archive_active_theme_settings($zip, $manifest);
        } finally {
            $zip->close();
        }
        $expected = array(
            'stylesheet' => (string) $manifest['active_theme_stylesheet'],
            'template' => (string) $manifest['active_theme_template'],
        );
        foreach (array_unique(array_values($expected)) as $theme) {
            if (!$this->safe_theme_slug($theme)) {
                throw new RuntimeException(esc_html__('Source active theme path is unsafe.', 'uwh-site-migrator'));
            }
            $rel = 'wp-content/themes/' . $theme . '/style.css';
            $check = $state['integrity']['files']['site/' . $rel] ?? null;
            $target = UWHSM_Paths::restore_target($rel);
            if (!is_array($check) || !isset($check['sha256']) || !is_string($check['sha256'])
                || !preg_match('/^[a-f0-9]{64}$/D', $check['sha256'])
                || !is_file($target) || !hash_equals($check['sha256'], (string) hash_file('sha256', $target))) {
                throw new RuntimeException(esc_html__('Source theme files were not fully restored; cannot activate the theme safely: ', 'uwh-site-migrator') . esc_html($theme));
            }
        }
        $table = (string) $state['new_prefix'] . 'options';
        $changed = array();
        foreach ($expected as $name => $theme) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read durable database state rather than cached theme settings during migration finalization.
            $actual = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $table, $name));
            if (null !== $actual && hash_equals($theme, (string) $actual)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reconcile source-verified theme settings in the restored options table.
            $ok = null === $actual
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Write the verified active theme option in the restored site database.
                ? $wpdb->insert($table, array('option_name' => $name, 'option_value' => $theme, 'autoload' => 'yes'))
                : $wpdb->update($table, array('option_value' => $theme), array('option_name' => $name));
            if (false === $ok) {
                throw new RuntimeException(esc_html__('Could not activate the restored source theme.', 'uwh-site-migrator'));
            }
            $changed[] = $name;
        }
        if ($changed) {
            if (function_exists('wp_cache_delete')) {
                foreach ($changed as $name) {
                    wp_cache_delete($name, 'options');
                }
                wp_cache_delete('alloptions', 'options');
                wp_cache_delete('notoptions', 'options');
            }
            UWHSM_Logger::log('restore', 'warning', __('Destination theme options changed during migration and were restored from the verified source backup.', 'uwh-site-migrator'), array('options' => $changed, 'restore_id' => (string) ($state['restore_id'] ?? '')));
        }
    }

    /**
     * Verify restored WordPress theme options AND the actual theme files.
     * This check runs before the migration is declared complete, allowing the
     * existing checkpoint/rollback flow to handle incomplete theme restores.
     */
    private function verify_restored_theme(array $state) {
        global $wpdb;
        $table = (string) $state['new_prefix'] . 'options';
        foreach (array('stylesheet' => 'active_theme_stylesheet', 'template' => 'active_theme_template') as $option => $manifest_key) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Migration finalization needs authoritative restored options, not stale object-cache values.
            $theme = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name=%s', $table, $option));
            if (!$this->safe_theme_slug($theme)) {
                throw new RuntimeException(esc_html__('The restored active theme setting is missing or invalid: ', 'uwh-site-migrator') . esc_html($option));
            }
            if (isset($state['manifest'][$manifest_key]) && (string) $state['manifest'][$manifest_key] !== (string) $theme) {
                throw new RuntimeException(esc_html__('Restored theme setting differs from the verified source backup: ', 'uwh-site-migrator') . esc_html($option));
            }
            $style = 'wp-content/themes/' . $theme . '/style.css';
            $target = UWHSM_Paths::restore_target($style);
            if (!is_file($target)) {
                throw new RuntimeException(esc_html__('Restored active theme files are missing: ', 'uwh-site-migrator') . esc_html($theme));
            }
            // Avoid a false success when an older incomplete archive omitted
            // the theme but an identically named destination theme already exists.
            $check = $state['integrity']['files']['site/' . $style] ?? null;
            if (!is_array($check) || empty($check['sha256']) || !is_string($check['sha256'])) {
                throw new RuntimeException(esc_html__('The active theme stylesheet is not included in the verified backup.', 'uwh-site-migrator'));
            }
            $actual_hash = hash_file('sha256', $target);
            if (false === $actual_hash || !hash_equals((string) $check['sha256'], $actual_hash)) {
                throw new RuntimeException(esc_html__('The restored active theme stylesheet checksum does not match the backup: ', 'uwh-site-migrator') . esc_html($theme));
            }
        }
    }

    /**
     * Repair caches only on a new request, after restored plugins can load.
     * Elementor's official file manager regenerates CSS lazily on page view.
     * Never delete unrelated cache folders or third-party site content.
     */
    public function maybe_run_post_restore_repairs() {
        $tasks = get_option('uwhsm_post_restore_tasks_v1', array());
        if (!is_array($tasks) || empty($tasks['pending']) || get_option('uwhsm_restore_guard', false)) {
            return;
        }
        try {
            if (function_exists('wp_clean_themes_cache')) {
                wp_clean_themes_cache(true);
            }
            $active = (array) get_option('active_plugins', array());
            if (in_array('elementor/elementor.php', $active, true)) {
                $elementor = class_exists('Elementor\Plugin', false) ? \Elementor\Plugin::$instance : null;
                if (!is_object($elementor) || !isset($elementor->files_manager) || !method_exists($elementor->files_manager, 'clear_cache')) {
                    UWHSM_Logger::log_once('post-restore-elementor-not-ready', 'restore', 'warning', __('Elementor files could not yet be refreshed. Check that Elementor loads correctly on the destination.', 'uwh-site-migrator'), array(), 3600);
                    return; // Preserve pending repair until Elementor loads.
                }
                $elementor->files_manager->clear_cache();
            }
            // Source-first migrations must not mutate imported theme mods, logo
            // settings or Elementor metadata after the database was verified.
            // Cache regeneration is safe, but a wholesale Theme Builder conditions
            // rebuild can replace the valid cache copied from the source site.
            // Only reconstruct conditions when the imported cache has no usable
            // header/footer assignments. Manual repair remains available.
            if (!empty($tasks['source_first'])) {
                $builder_repair = UWHSM_Elementor_Repair::rebuild_only_if_missing();
            } else {
                // Interrupted restores from older releases retain their original
                // finalization semantics; do not reinterpret an existing job.
                $logo_repair = UWHSM_Elementor_Repair::repair_logo();
                if ('warning' === $logo_repair['status']) {
                    UWHSM_Logger::log_once('post-restore-logo-warning', 'restore', 'warning', $logo_repair['message'], array(), 3600);
                }
                $builder_repair = UWHSM_Elementor_Repair::rebuild();
            }
            if ('warning' === $builder_repair['status']) {
                update_option('uwhsm_elementor_repair_warning_v1', $builder_repair['message'], false);
                UWHSM_Logger::log_once('post-restore-elementor-conditions', 'restore', 'warning', $builder_repair['message'], array(), 3600);
            } else {
                delete_option('uwhsm_elementor_repair_warning_v1');
            }
            if (function_exists('flush_rewrite_rules')) {
                flush_rewrite_rules(false); // Soft flush; never rewrite server .htaccess.
            }
            delete_option('uwhsm_post_restore_tasks_v1');
            UWHSM_Logger::log_once('post-restore-repaired-' . sanitize_key((string) ($tasks['restore_id'] ?? '')), 'restore', 'success', __('Post-migration WordPress and page-builder caches were refreshed without replacing verified source theme-builder settings.', 'uwh-site-migrator'), array('restore_id' => (string) ($tasks['restore_id'] ?? '')), 7 * DAY_IN_SECONDS);
        } catch (Throwable $error) {
            UWHSM_Logger::log_once('post-restore-repair-error', 'restore', 'warning', __('Post-migration cache refresh is pending. You can clear Elementor files and server cache manually.', 'uwh-site-migrator'), array('error' => $error->getMessage()), 3600);
        }
    }

    private function restore_finalize(array &$s) {
        global $wpdb;
        if (!empty($s['verify_restored_files']) && empty($s['files_verified'])) {
            throw new RuntimeException(esc_html__('Migration file integrity verification is incomplete. Resume the restore.', 'uwh-site-migrator'));
        }
        // Recover a previous v2.4.10 job whose options import completed but
        // table_index advanced without making imported site options live.
        // A fresh restore performs the publish before advancing its cursor.
        if (!empty($s['options_atomic_swap'])) {
            $source_options = (string) ($s['manifest']['table_prefix'] ?? '') . 'options';
            $shadow_names = $this->options_shadow_names($s);
            if ($this->restore_table_exists($shadow_names['stage'])) {
                $receipt_table = $this->restore_progress_table($s);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify imported table completion before switching options.
                $completed = $wpdb->get_var($wpdb->prepare('SELECT complete FROM %i WHERE table_name = %s', $receipt_table, $source_options));
                if (1 !== (int) $completed) {
                    throw new RuntimeException(esc_html__('WordPress options import has not completed.', 'uwh-site-migrator'));
                }
                $this->verify_imported_table_rows($shadow_names['stage'], (int) ($s['integrity']['table_rows'][$source_options] ?? -1), $s, $source_options);
                $this->publish_restored_options((string) $s['new_prefix'] . 'options', $s);
            }
            if (!$this->restore_table_exists($shadow_names['previous'])) {
                throw new RuntimeException(esc_html__('The imported WordPress options were not published. Resume this restore before continuing.', 'uwh-site-migrator'));
            }
        }
        // Recheck every table from the archive at the end, not only while its
        // individual batches run. Never mark a partially imported DB successful.
        $receipt = $this->restore_progress_table($s);
        foreach ((array) $s['tables'] as $source_table) {
            $target_table = $this->mapped_table($source_table, $s);
            if (!$this->restore_table_exists($target_table)) {
                throw new RuntimeException(esc_html__('Restored database table is missing: ', 'uwh-site-migrator') . esc_html($source_table));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Check durable import receipts before success.
            $completed = $wpdb->get_var($wpdb->prepare('SELECT complete FROM %i WHERE table_name = %s', $receipt, $source_table));
            if (1 !== (int) $completed) {
                throw new RuntimeException(esc_html__('Database table import did not finish: ', 'uwh-site-migrator') . esc_html($source_table));
            }
            if ($target_table !== $s['new_prefix'] . 'options') {
                // New restores have not switched live account tables yet.
                // Check their imported staging copy, never the old destination
                // account table. On a lost final response, use published tables.
                $auth_stage = $this->auth_shadow_table($source_table, $s);
                if ('' !== $auth_stage) {
                    $type = substr($source_table, strlen((string) $s['manifest']['table_prefix']));
                    $auth_names = $this->auth_shadow_names($s, $type);
                    if ($this->restore_table_exists($auth_stage)) {
                        $target_table = $auth_stage;
                    } elseif (!$this->restore_table_exists($auth_names['previous'])) {
                        throw new RuntimeException(esc_html__('The imported account staging table is missing: ', 'uwh-site-migrator') . esc_html($type));
                    }
                }
                $this->verify_imported_table_rows($target_table, (int) $s['integrity']['table_rows'][$source_table], $s, $source_table);
            }
        }
        $options = $s['new_prefix'] . 'options';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        if (false === $wpdb->update($options, array('option_value' => $s['new_site_url']), array('option_name' => 'siteurl'))) {
            throw new RuntimeException(esc_html__('Cannot set destination URL.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $wpdb->update($options, array('option_value' => $s['new_home_url']), array('option_name' => 'home'));
        $active = $s['source_active_plugins'] ?? ($s['manifest']['active_plugins'] ?? array());
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
        // Reconcile only source-verified Reading Settings after database import.
        // A long restore may let core/plugin code reset page_on_front while
        // its target post is temporarily absent. Never hide a missing page.
        $this->reconcile_restored_front_page($s);
        $this->verify_restored_front_page($s);
        $this->reconcile_restored_active_theme($s);
        // Verify actual theme files after the copy, rather than silently using
        // the destination theme when a package was incomplete.
        $this->verify_restored_theme($s);
        $this->reconcile_restored_site_identity($s);
        $this->reconcile_restored_custom_logo($s);
        $this->verify_restored_branding_media($s);
        $this->reconcile_restored_elementor_logo($s);
        $this->verify_restored_builder_templates($s);
        if (self::restore_needs_incremental_verify($s)) {
            throw new RuntimeException(esc_html__('The restored template and permalink verification has not finished.', 'uwh-site-migrator'));
        }
        // Defer Elementor CSS regeneration until its plugin can load on the next
        // request. This marker lives in restored wp_options, survives logout,
        // and requires no user credentials or browser-local storage.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Restore finalization must durably queue the post-swap cache repair.
        if (false === $wpdb->replace($options, array('option_name' => 'uwhsm_post_restore_tasks_v1', 'option_value' => maybe_serialize(array('pending' => true, 'restore_id' => (string) $s['restore_id'], 'source_first' => !empty($s['source_first_restore']))), 'autoload' => 'no'))) {
            throw new RuntimeException(esc_html__('Could not queue post-migration style refresh.', 'uwh-site-migrator'));
        }
        // Direct table writes bypass WordPress option-cache invalidation. Clear
        // both the key and a cached 'missing option' result, especially for
        // persistent Redis/Memcached object caches across the next request.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('uwhsm_post_restore_tasks_v1', 'options');
            wp_cache_delete('notoptions', 'options');
        }
        // Keep the restore guard until plugin state and the final receipt are durable.
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        $this->restore_plugin_option_hygiene($s);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
        $wpdb->delete($options, array('option_name' => 'rewrite_rules'));
        // Delete old destination-prefixed tables only after all source data,
        // active theme and source identity verification have succeeded.
        $this->prune_replaced_destination_tables($s);
        // UWH Site Migrator uses its own restore guard; it never creates the
        // WordPress core .maintenance file. Do not delete it: a concurrent core
        // or plugin upgrade may own that file and must manage its own lock.
        // Publish the restored WordPress accounts only after all other database
        // rows, theme, homepage, site identity, and template checks succeeded.
        // Until this atomic switch, administrators retain destination login.
        // A failed verification leaves the old credentials available to resume.
        $this->publish_restored_auth_tables($s);
        // Leave the import receipt intact until the completed job state has
        // been durably written, allowing safe retry after a lost HTTP response.
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
                if ($name && (!in_array(($state['phase'] ?? ''), array('done', 'failed', 'cancelled'), true) || ($state['rollback_status'] ?? '') === 'running')) {
                    $protected[$name] = true;
                }
            }
            if (in_array(($state['source_mode'] ?? ''), array('checkpoint', 'rollback'), true) && (!in_array(($state['phase'] ?? ''), array('done', 'failed', 'cancelled'), true) || ($state['rollback_status'] ?? '') === 'running')) {
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
                if (is_array($state) && in_array(($state['type'] ?? ''), array('restore', 'restore_pending'), true) && (!in_array(($state['phase'] ?? ''), array('done', 'failed', 'cancelled'), true) || ($state['rollback_status'] ?? '') === 'running')) {
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
            $create_checkpoint = '1' === UWHSM_Compat::post_value('create_checkpoint');
            $result = $this->begin_restore_with_checkpoint($id, $token, $record['path'], 'checkpoint', $record['name'], false, $create_checkpoint);
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
        // A .uwh backup is ZIP-based internally, but application/zip can cause
        // Android download managers to append an unwanted .zip extension.
        // Serve it as a generic binary attachment with an explicit .uwh name.
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
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
