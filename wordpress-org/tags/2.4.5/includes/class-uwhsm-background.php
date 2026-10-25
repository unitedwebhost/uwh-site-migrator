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
    }

    public static function intervals($schedules) {
        $schedules['uwhsm_minute'] = array('interval' => 60, 'display' => __('Site Migrator background minute', 'uwh-site-migrator'));
        return $schedules;
    }

    private static function binding($job) {
        return hash_hmac('sha256', $job . '|' . home_url('/') . '|' . ABSPATH, wp_salt('auth'));
    }

    public static function deactivate() {
        wp_unschedule_hook(self::HOOK);
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
            return;
        }
        if (!wp_next_scheduled(self::HOOK, array($job))) {
            $ok = wp_schedule_event(time()+10, 'uwhsm_minute', self::HOOK, array($job), true);
            if (is_wp_error($ok) || !$ok) {
                $b['enabled'] = false;
                $b['error'] = __('Could not schedule background processing.', 'uwh-site-migrator');
                $engine->background_update($job, $b);
                throw new RuntimeException(esc_html($b['error']) . esc_html__(' Manual backup remains available.', 'uwh-site-migrator'));
            }
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
        do {
            $s = $engine->background_state($job);
            $b = $s['background'] ?? array();
            if (!$s || empty($b['enabled']) || !hash_equals(self::binding($job), (string) ($b['binding'] ?? ''))) {
                wp_clear_scheduled_hook(self::HOOK, array($job));
                return;
            }
            if (is_multisite() || time() < ($b['retry_at'] ?? 0)) {
                return;
            }
            $next = $b;
            $next['last_tick'] = time();
            try {
                if ('done' === ($s['phase'] ?? '')) {
                    self::apply_drive_result($next, self::continue_auto_drive($s));
                } else {
                    $result = $engine->run_backup_batch($job);
                    if (!empty($result['busy'])) {
                        return;
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
                if ($next['failures'] >= 3) {
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
                return;
            }
            if ($next['failures'] || time() < ($next['retry_at'] ?? 0)) {
                return;
            }
        } while (microtime(true) - $started < 20);
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
