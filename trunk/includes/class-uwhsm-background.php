<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Optional cron adapter; it never creates backups or runs restores on its own. */
final class UWHSM_Background {
    const HOOK = 'uwhsm_background_tick';
    public static function boot() {
        add_filter('cron_schedules', array(__CLASS__, 'intervals'));
        add_action(self::HOOK, array(__CLASS__, 'tick'));
        add_action('wp_ajax_uwhsm_background_configure', array(__CLASS__, 'configure'));
        add_action('admin_init', array(__CLASS__, 'upgrade_intervals'));
        // Repair missing worker events even when the browser tab is closed.
        // Runs only for an indexed, enabled backup and at most once per minute.
        add_action('init', array(__CLASS__, 'watchdog'), 100);
    }

    public static function intervals($schedules) {
        $schedules['uwhsm_minute'] = array('interval' => 30, 'display' => __('Site Migrator background cycle', 'uwh-site-migrator'));
        return $schedules;
    }

    /**
     * Keep a small index so ordinary site visits do not scan the entire backup
     * directory. Only administrator-authorized, enabled background jobs enter it.
     */
    private static function remember_job($job, $enabled) {
        $jobs = get_option('uwhsm_background_watch_jobs', array());
        if (!is_array($jobs)) {
            $jobs = array();
        }
        if ($enabled) {
            $jobs[$job] = time();
        } else {
            unset($jobs[$job]);
        }
        if (count($jobs) > 20) {
            asort($jobs, SORT_NUMERIC);
            $jobs = array_slice($jobs, -20, null, true);
        }
        // No backup secrets or authorization keys are stored in this index.
        update_option('uwhsm_background_watch_jobs', $jobs, false);
    }

    /** Keep the existing per-job event; do not start a second ZIP writer. */
    private static function ensure_event($job) {
        $args = array($job);
        $event = wp_get_scheduled_event(self::HOOK, $args);
        if ($event && (int) ($event->interval ?? 0) === 30) {
            return true;
        }
        if ($event) {
            wp_clear_scheduled_hook(self::HOOK, $args);
        }
        $ok = wp_schedule_event(time() + 1, 'uwhsm_minute', self::HOOK, $args, true);
        return !is_wp_error($ok) && (bool) $ok;
    }

    /**
     * WP-Cron depends on HTTP traffic unless hosting configures a real cron.
     * This repairs lost schedules after a visit, without running backup batches
     * in frontend requests or bypassing the engine's exclusive backup lock.
     */
    public static function watchdog() {
        if (is_multisite() || wp_doing_ajax()) {
            return;
        }
        // A real server cron / WP-CLI process is often the only request on
        // low-traffic sites. Permit the lightweight watchdog there too so lost
        // backup events are repaired without waiting for a frontend visit.
        // Avoid modifying wp_options while a destructive restore is active.
        if (get_option('uwhsm_restore_guard', false)) {
            return;
        }
        $jobs = get_option('uwhsm_background_watch_jobs', array());
        if (!is_array($jobs) || !$jobs || false !== get_transient('uwhsm_background_watchdog')) {
            return;
        }
        set_transient('uwhsm_background_watchdog', 1, 60);
        $engine = UWHSM_Engine::instance();
        foreach ($jobs as $job => $saved) {
            $state = $engine->background_state((string) $job);
            $b = $state['background'] ?? array();
            if ($engine->backup_is_paused((string) $job)) {
                wp_clear_scheduled_hook(self::HOOK, array((string) $job));
                continue;
            }
            if (!$state || empty($b['enabled']) || !hash_equals(self::binding((string) $job), (string) ($b['binding'] ?? ''))) {
                wp_clear_scheduled_hook(self::HOOK, array((string) $job));
                unset($jobs[$job]);
                continue;
            }
            if (!self::ensure_event((string) $job)) {
                UWHSM_Logger::log_once('bg-event-missing-' . $job, 'schedule', 'warning', __('Could not recover the background backup cron schedule. Check server cron configuration.', 'uwh-site-migrator'), array('job' => $job), 3600);
            }
        }
        update_option('uwhsm_background_watch_jobs', $jobs, false);
    }

    /** Existing v2.4.6 recurring events remember their original 60-second interval. */
    public static function upgrade_intervals() {
        // Avoid scanning saved backup job files and querying cron events on
        // unrelated wp-admin screens (WooCommerce, Elementor, WPvivid, etc.).
        // Legacy intervals are upgraded when an administrator opens any Site
        // Migrator screen; new jobs already schedule at the current interval.
        $page = sanitize_key(UWHSM_Compat::get_value('page'));
        if (!in_array($page, array('site-vault-migrator', 'site-migrator-import-export', 'site-migrator-direct', 'site-migrator-drive', 'site-migrator-logs', 'site-migrator-troubleshooting'), true) || !current_user_can('manage_options')) {
            return;
        }
        foreach (UWHSM_Engine::instance()->background_jobs() as $j) {
            $b = $j['background'];
            if (empty($b['enabled']) || !hash_equals(self::binding($j['job']), (string) ($b['binding'] ?? ''))) {
                continue;
            }
            // Also recover enabled jobs created before the watchdog index existed.
            self::remember_job($j['job'], true);
            if (!self::ensure_event($j['job'])) {
                UWHSM_Logger::log_once('bg-schedule-failed-' . $j['job'], 'schedule', 'warning', __('Unable to schedule unattended backup. Configure hosting WP-Cron.', 'uwh-site-migrator'), array('job' => $j['job']), 3600);
            }
        }
    }

    private static function binding($job) {
        return hash_hmac('sha256', $job . '|' . home_url('/') . '|' . ABSPATH, wp_salt('auth'));
    }

    public static function deactivate() {
        wp_unschedule_hook(self::HOOK);
        delete_option('uwhsm_background_watch_jobs');
        foreach (UWHSM_Engine::instance()->background_jobs() as $j) {
            $b = $j['background'];
            $b['enabled'] = false;
            try {
                UWHSM_Engine::instance()->background_update($j['job'], $b);
            } catch (Throwable $e) {
                /* No scheduled worker remains. */
            }
        }
    }

    public static function configure() {
        UWHSM_Compat::verify_ajax_nonce('uwhsm_admin');
        if (!current_user_can('manage_options') || is_multisite()) {
            wp_send_json_error(array('message' => __('Administrator permission on a single site is required.', 'uwh-site-migrator')), 403);
            return;
        }
        $job         = UWHSM_Compat::post_value('job');
        $enabled_raw = UWHSM_Compat::post_value('enabled');
        $enabled     = is_scalar($enabled_raw) && '1' === (string) $enabled_raw;
        try {
            self::set_enabled($job, $enabled);
        } catch (Throwable $e) {
            UWHSM_Logger::log('schedule', 'failed', __('Background backup setting could not be changed.', 'uwh-site-migrator'), array('job' => $job, 'error' => $e->getMessage()));
            wp_send_json_error(array('message' => $e->getMessage()), 409);
            return;
        }
        UWHSM_Logger::log('schedule', 'info', $enabled ? __('Background backup processing enabled.', 'uwh-site-migrator') : __('Background backup processing paused.', 'uwh-site-migrator'), array('job' => $job));
        wp_send_json_success(array('message' => $enabled ? __('Background processing enabled. Cron must run for this backup to continue after closing the tab.', 'uwh-site-migrator') : __('Background processing paused. An open backup tab can still process batches.', 'uwh-site-migrator')));
    }

    public static function set_enabled($job, $enabled) {
        $engine = UWHSM_Engine::instance();
        $s = $engine->background_state($job);
        if (!$s || 'done' === $s['phase']) {
            throw new RuntimeException(esc_html__('Choose an unfinished backup.', 'uwh-site-migrator'));
        }
        $b = array('enabled' => (bool) $enabled, 'binding' => self::binding($job), 'failures' => 0, 'last_tick' => 0, 'error' => '', 'retry_at' => 0);
        $engine->background_update($job, $b);
        if (!$enabled) {
            wp_clear_scheduled_hook(self::HOOK, array($job));
            self::remember_job($job, false);
            return;
        }
        if (!self::ensure_event($job)) {
            $b['enabled'] = false;
            $b['error'] = __('Could not schedule background processing.', 'uwh-site-migrator');
            $engine->background_update($job, $b);
            self::remember_job($job, false);
            throw new RuntimeException(esc_html($b['error']) . esc_html__(' Manual backup remains available.', 'uwh-site-migrator'));
        }
        self::remember_job($job, true);
        // Kick off the first cycle while the admin session is still available.
        // When WP-Cron is disabled, the configured real server cron remains responsible.
        if (function_exists('spawn_cron') && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
            spawn_cron(time() + 2);
        }
    }

    private static function continue_auto_drive(array $state) {
        $transfer_id = (string) ($state['drive_transfer_id'] ?? '');
        if (empty($state['is_auto']) || !$transfer_id || !class_exists('UWHSM_Drive_Transfer')) {
            return null;
        }
        return UWHSM_Drive_Transfer::background_step($transfer_id, true);
    }

    private static function apply_drive_result(array &$next, $drive) {
        if (!is_array($drive)) {
            $next['enabled'] = false;
            return;
        }
        $next['failures'] = 0;
        $next['error'] = '';
        $next['retry_at'] = 0;
        if (($drive['status'] ?? '') === 'failed' || !empty($drive['auto_paused']) || !empty($drive['reconnect_required'])) {
            $next['enabled'] = false;
            $next['error'] = (string) ($drive['error'] ?? __('Automatic Drive upload stopped.', 'uwh-site-migrator'));
            return;
        }
        if (!empty($drive['done'])) {
            if (!empty($drive['delete_local_after_upload']) && empty($drive['local_cleanup_done'])) {
                $next['enabled'] = true;
                $next['error'] = (string) ($drive['local_cleanup_error'] ?? __('Drive upload completed; waiting to remove the automatic local copy.', 'uwh-site-migrator'));
                $next['retry_at'] = time() + 60;
            } else {
                $next['enabled'] = false;
            }
            return;
        }
        $next['enabled'] = true;
        $retry_after = max(0, (int) ($drive['retry_after'] ?? 0));
        if ($retry_after) {
            $next['retry_at'] = time() + $retry_after;
        }
    }

    public static function tick($job) {
        $engine = UWHSM_Engine::instance();
        $started = microtime(true);
        // The CLI process is initiated by a real hosting cron, typically once
        // per minute. Use more of that minute for backup batches so unattended
        // throughput approaches the open-tab browser runner. HTTP cron stays
        // conservative to avoid a shared hosting reverse-proxy timeout.
        $max_work_seconds = (defined('WP_CLI') && WP_CLI) ? 42 : 21;
        do {
            $s = $engine->background_state($job);
            $b = $s['background'] ?? array();
            if ($engine->backup_is_paused($job)) {
                wp_clear_scheduled_hook(self::HOOK, array($job));
                return;
            }
            if (!$s || empty($b['enabled']) || !hash_equals(self::binding($job), (string) ($b['binding'] ?? ''))) {
                wp_clear_scheduled_hook(self::HOOK, array($job));
                self::remember_job($job, false);
                return;
            }
            if (is_multisite() || time() < ($b['retry_at'] ?? 0)) {
                return;
            }
            // Prefer a healthy browser runner when the admin tab is open.
            // If it stops, the heartbeat expires and cron resumes unattended
            // backups without losing any saved archive/database progress.
            if ('done' !== ($s['phase'] ?? '') && time() - (int) ($b['last_foreground_batch'] ?? 0) < 15) {
                return;
            }
            $next = $b;
            $next['last_tick'] = time();
            try {
                if ('done' === ($s['phase'] ?? '')) {
                    self::apply_drive_result($next, self::continue_auto_drive($s));
                } else {
                    $result = $engine->run_backup_batch($job, 'cron');
                    if (!empty($result['busy'])) {
                        return;
                    }
                    if (!empty($result['blocked'])) {
                        // Three automatic per-file attempts have finished.
                        // Suspend unattended processing until the admin explicitly
                        // confirms a partial backup, rather than looping forever.
                        $next['enabled'] = false;
                        $next['error'] = '';
                        $next['retry_at'] = 0;
                        $next['failures'] = 0;
                        UWHSM_Logger::log_once('backup-await-skip-' . $job, 'backup', 'warning', __('Automatic file retries failed. An administrator may skip the identified Site Migrator PHP file and resume a partial backup.', 'uwh-site-migrator'), array('job' => $job, 'file' => (string) ($result['skip_candidate'] ?? '')), 3600);
                    }
                    $next['failures'] = 0;
                    $next['error'] = '';
                    $next['retry_at'] = 0;
                    if (!empty($result['done'])) {
                        // Reload the durable backup state so the Drive transfer ID queued during finalization is visible.
                        $s = $engine->background_state($job);
                        self::apply_drive_result($next, is_array($s) ? self::continue_auto_drive($s) : null);
                    }
                }
            } catch (Throwable $e) {
                $next['failures'] = ($b['failures'] ?? 0) + 1;
                $next['error'] = $e->getMessage();
                UWHSM_Logger::log_once('background-error-' . $job . '-' . md5($e->getMessage()), 'backup', 'failed', __('Background backup worker encountered an error.', 'uwh-site-migrator'), array('job' => $job, 'error' => $e->getMessage()), 3600);
                $next['retry_at'] = time() + min(900, 60 * (2 ** min(4, $next['failures'])));
                // Keep unattended jobs retryable through transient PHP/IO errors.
                // Pause only after sustained repeated failures, not three hiccups.
                if ($next['failures'] >= 8) {
                    $next['enabled'] = false;
                }
            }
            try {
                $saved = $engine->background_update($job, $next, $b);
            } catch (Throwable $e) {
                return;
            }
            if (!$saved) {
                return;
            }
            // An administrator changed settings during the batch.
            if (!$next['enabled']) {
                wp_clear_scheduled_hook(self::HOOK, array($job));
                self::remember_job($job, false);
                return;
            }
            if ($next['failures'] || time() < ($next['retry_at'] ?? 0)) {
                return;
            }
        } while (microtime(true) - $started < $max_work_seconds);
    }

    public static function describe(array $j) {
        $b = $j['background'];
        if ('done' === $j['phase']) {
            return __('Complete — 100%', 'uwh-site-migrator');
        }
        if (!hash_equals(self::binding($j['job']), (string) ($b['binding'] ?? ''))) {
            return __('Paused: this job belongs to another site or its authorization changed. Re-enable to authorize here.', 'uwh-site-migrator');
        }
        $active = !empty($b['enabled']) && wp_next_scheduled(self::HOOK, array($j['job']));
        $label = $active ? __('Background enabled', 'uwh-site-migrator') : __('Background paused', 'uwh-site-migrator');
        $label .= ' · ' . floor($j['progress']) . '% · ' . $j['label'];
        $label .= empty($b['last_tick']) ? __(' · Waiting for first cron run', 'uwh-site-migrator') : __(' · Last cron run: ', 'uwh-site-migrator') . gmdate('Y-m-d H:i:s', $b['last_tick']) . __(' UTC', 'uwh-site-migrator');
        if (!empty($b['error'])) {
            $label .= ' · ' . $b['error'];
        }
        if ($active && time() - ($b['last_tick'] ?: 0) > 300) {
            $label .= __(' · Check hosting cron if progress does not advance.', 'uwh-site-migrator');
        }
        return $label;
    }
}
