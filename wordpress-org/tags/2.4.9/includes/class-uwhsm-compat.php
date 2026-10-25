<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
* One-release compatibility and persistent-state migration layer for v2.2.0.
* Legacy names live here so production business logic can use the UWHSM/uwhsm prefix exclusively.
*/
final class UWHSM_Compat {
    const DATA_SCHEMA_VERSION = '2.2.0';
    const DATA_SCHEMA_OPTION = 'uwhsm_data_schema_version';
    private static $booted = false;
    private static $routes_registered = false;

    private static function option_map() {
        return array(
            'svm_activity_logs_v1'          => 'uwhsm_activity_logs_v1',
            'svm_activity_log_dedupe_v1'    => 'uwhsm_activity_log_dedupe_v1',
            'svm_auto_backup_frequency'     => 'uwhsm_auto_backup_frequency',
            'svm_auto_backup_time'          => 'uwhsm_auto_backup_time',
            'svm_auto_drive_retention'      => 'uwhsm_auto_drive_retention',
            'svm_direct_migration_sessions' => 'uwhsm_direct_migration_sessions',
        );
    }

    private static function cron_map() {
        return array(
            'svm_auto_backup_event'   => 'uwhsm_auto_backup_event',
            'svm_background_tick'     => 'uwhsm_background_tick',
            'svm_drive_transfer_tick' => 'uwhsm_drive_transfer_tick',
        );
    }

    private static function legacy_nonce_map() {
        return array(
            'uwhsm_admin'             => 'svm_admin',
            'uwhsm_activity_logs'     => 'svm_activity_logs',
            'uwhsm_diagnostic_report' => 'svm_diagnostic_report',
            'uwhsm_drive'             => 'svm_drive',
        );
    }

    /**
     * Return the callback URL expected by the deployed Google Drive gateway.
     *
     * The gateway protocol predates the public uwhsm_ prefix migration and
     * treats the legacy callback action as part of its compatibility contract.
     * Keep that external protocol detail isolated in this compatibility layer.
     */
    public static function drive_gateway_callback_url() {
        return admin_url('admin-post.php?action=svm_drive_callback');
    }

    public static function legacy_route_map() {
        return array(
            // AJAX routes.
            'svm_backup_start'              => 'ajax',
            'svm_backup_step'               => 'ajax',
            'svm_upload_start'              => 'ajax',
            'svm_upload_chunk'              => 'ajax',
            'svm_restore_start'             => 'ajax',
            'svm_restore_local_start'       => 'ajax',
            'svm_restore_checkpoint_finish' => 'ajax',
            'svm_restore_recover'           => 'ajax',
            'svm_restore_step'              => 'ajax',
            'svm_checkpoint_rollback_start' => 'ajax',
            'svm_checkpoint_delete'         => 'ajax',
            'svm_delete_backup'             => 'ajax',
            'svm_save_auto_backup'          => 'ajax',
            'svm_background_configure'      => 'ajax',
            'svm_drive_upload_start'        => 'ajax',
            'svm_drive_upload_step'         => 'ajax',
            'svm_drive_upload_status'       => 'ajax',
            'svm_drive_list'                => 'ajax',
            'svm_drive_delete'              => 'ajax',
            'svm_drive_fetch'               => 'ajax',
            'svm_drive_import_start'        => 'ajax',
            'svm_drive_import_step'         => 'ajax',
            'svm_drive_import_status'       => 'ajax',
            'svm_site_transfer_key_create'  => 'ajax',
            'svm_site_transfer_key_revoke'  => 'ajax',
            'svm_site_pull_start'           => 'ajax',
            'svm_site_pull_step'            => 'ajax',
            'svm_site_pull_status'          => 'ajax',
            'svm_site_pull_restore'         => 'ajax',
            // Admin-post/public routes.
            'svm_download'                  => 'admin_post',
            'svm_activity_logs_action'      => 'admin_post',
            'svm_diagnostic_report'         => 'admin_post',
            'svm_drive_action'              => 'admin_post',
            'svm_drive_callback'            => 'admin_post',
            'svm_drive_download'            => 'admin_post',
            'svm_site_transfer_meta'        => 'public_admin_post',
            'svm_site_transfer_package'     => 'public_admin_post',
        );
    }

    public static function boot() {
        self::bridge_legacy_constants();
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        if (function_exists('add_filter')) {
            add_filter('cron_schedules', array(__CLASS__, 'cron_schedules'));
        }
        // A restore can rebuild wp_options across multiple AJAX requests. Do not
        // run compatibility migrations between restore batches, because writing
        // options/cron state into the partially restored table can collide with
        // authoritative rows that are still waiting to be imported.
        if (!self::is_restore_step_request()) {
            self::migrate_once();
        }
        self::register_legacy_routes();
    }

    private static function is_restore_step_request() {
        $action = sanitize_key(self::request_value('action'));
        return in_array($action, array('uwhsm_restore_step', 'svm_restore_step'), true);
    }

    public static function cron_schedules($schedules) {
        if (!isset($schedules['uwhsm_minute'])) {
            $schedules['uwhsm_minute'] = array('interval' => 60, 'display' => __('Site Migrator background minute', 'uwh-site-migrator'));
        }
        return $schedules;
    }

    public static function bridge_legacy_constants() {
        if (defined('SVM_STORAGE_PATH') && !defined('UWHSM_STORAGE_PATH')) {
            define('UWHSM_STORAGE_PATH', SVM_STORAGE_PATH);
        }
    }

    public static function migrate_once() {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        if ((string) get_option(self::DATA_SCHEMA_OPTION, '') === self::DATA_SCHEMA_VERSION) {
            return true;
        }
        $options_ok = self::migrate_options();
        $cron_ok = self::migrate_cron();
        if ($options_ok && $cron_ok) {
            update_option(self::DATA_SCHEMA_OPTION, self::DATA_SCHEMA_VERSION, false);
            return (string) get_option(self::DATA_SCHEMA_OPTION, '') === self::DATA_SCHEMA_VERSION;
        }
        return false;
    }

    public static function migrate_options() {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return false;
        }
        $all_ok = true;
        $missing = '__uwhsm_missing_' . md5(__FILE__) . '__';
        foreach (self::option_map() as $old => $new) {
            $new_value = get_option($new, $missing);
            if ($new_value !== $missing) {
                continue;
            }
            $old_value = get_option($old, $missing);
            if ($old_value === $missing) {
                continue;
            }
            $written = update_option($new, $old_value, false);
            $verified = get_option($new, $missing);
            if ((!$written && $verified === $missing) || serialize($verified) !== serialize($old_value)) {
                $all_ok = false;
                continue;
            }
            // Preserve active source authorizations under the old option for this release window.
            if ($old !== 'svm_direct_migration_sessions' && function_exists('delete_option')) {
                delete_option($old);
            }
        }
        return $all_ok;
    }

    private static function legacy_cron_events($hook) {
        $events = array();
        if (!function_exists('_get_cron_array')) {
            return $events;
        }
        $cron = _get_cron_array();
        if (!is_array($cron)) {
            return $events;
        }
        foreach ($cron as $timestamp => $hooks) {
            if (empty($hooks[$hook]) || !is_array($hooks[$hook])) {
                continue;
            }
            foreach ($hooks[$hook] as $event) {
                $events[] = array(
                    'timestamp' => (int) $timestamp,
                    'schedule'  => isset($event['schedule']) ? (string) $event['schedule'] : '',
                    'args'      => isset($event['args']) && is_array($event['args']) ? array_values($event['args']) : array(),
                );
            }
        }
        return $events;
    }

    public static function migrate_cron() {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event') || !function_exists('wp_clear_scheduled_hook')) {
            return true;
        }
        $all_ok = true;
        foreach (self::cron_map() as $old_hook => $new_hook) {
            foreach (self::legacy_cron_events($old_hook) as $event) {
                $args = $event['args'];
                $new_schedule = $event['schedule'] === 'svm_minute' ? 'uwhsm_minute' : $event['schedule'];
                $existing = wp_next_scheduled($new_hook, $args);
                if (!$existing) {
                    if ($new_schedule === '') {
                        $all_ok = false;
                        continue;
                    }
                    $scheduled = wp_schedule_event($event['timestamp'], $new_schedule, $new_hook, $args);
                    if (!$scheduled) {
                        $all_ok = false;
                        continue;
                    }
                    $existing = wp_next_scheduled($new_hook, $args);
                }
                if ((int) $existing !== (int) $event['timestamp']) {
                    $all_ok = false;
                    continue;
                }
                wp_clear_scheduled_hook($old_hook, $args);
            }
        }
        return $all_ok;
    }

    public static function is_legacy_request($action = null) {
        if (null === $action) {
            $action = sanitize_key(self::request_value('action'));
        }

        return isset(self::legacy_route_map()[$action]);
    }

    /**
     * Read a scalar request value through one audited boundary.
     *
     * Callers still enforce the appropriate WordPress nonce or protocol-specific
     * authorization before acting on the returned value.
     */
    public static function request_value($field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized request reader; authorization is enforced by each request handler or nonce verifier before the value is acted on.
        if (!isset($_REQUEST[$field])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized request reader; the scalar value is sanitized here and authenticated by the caller.
        return is_scalar($_REQUEST[$field]) ? sanitize_text_field(wp_unslash((string) $_REQUEST[$field])) : '';
    }

    /** Read and sanitize a scalar POST value after the handler's authorization gate. */
    public static function post_value($field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized POST reader; state-changing handlers verify their nonce/capability before consuming this value.
        if (!isset($_POST[$field]) || !is_scalar($_POST[$field])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized POST reader; state-changing handlers verify their nonce/capability before consuming this value.
        return sanitize_text_field(wp_unslash((string) $_POST[$field]));
    }

    /** Read and sanitize a scalar GET value used by authenticated/read-only routes. */
    public static function get_value($field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized GET reader; callers use read-only routing tokens, OAuth state, or protocol-specific authorization.
        if (!isset($_GET[$field]) || !is_scalar($_GET[$field])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized GET reader; callers validate the returned token/value before acting on it.
        return sanitize_text_field(wp_unslash((string) $_GET[$field]));
    }

    /** Read and sanitize a scalar server header value. */
    public static function server_value($field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- HTTP headers are authenticated/validated by the consuming download or migration protocol.
        if (!isset($_SERVER[$field]) || !is_scalar($_SERVER[$field])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Centralized header reader; the returned scalar is sanitized before protocol validation.
        return sanitize_text_field(wp_unslash((string) $_SERVER[$field]));
    }

    /** Return PHP's sanitized upload temporary path for an already nonce-protected handler. */
    public static function uploaded_tmp_name($field) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload handlers verify a nonce first; PHP supplies tmp_name and the caller also validates it with is_uploaded_file().
        if (!isset($_FILES[$field]['tmp_name']) || !is_scalar($_FILES[$field]['tmp_name'])) {
            return '';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP-generated temporary path is normalized before is_uploaded_file() validation.
        return sanitize_text_field(wp_unslash((string) $_FILES[$field]['tmp_name']));
    }

    private static function request_nonce($field) {
        return self::request_value($field);
    }

    public static function verify_ajax_nonce($new_action = 'uwhsm_admin') {
        $action = sanitize_key(self::request_value('action'));
        if (self::is_legacy_request($action)) {
            $nonce = self::request_nonce('_ajax_nonce');
            $legacy = isset(self::legacy_nonce_map()[$new_action]) ? self::legacy_nonce_map()[$new_action] : '';
            if (function_exists('wp_verify_nonce') && (wp_verify_nonce($nonce, $new_action) || ($legacy && wp_verify_nonce($nonce, $legacy)))) {
                return true;
            }
            if (function_exists('wp_send_json_error')) {
                wp_send_json_error(array('message' => __('Security check failed. Refresh the page and try again.', 'uwh-site-migrator')), 403);
            }
            return false;
        }
        if (function_exists('check_ajax_referer')) {
            return (bool) check_ajax_referer($new_action);
        }
        return false;
    }

    public static function verify_admin_post_nonce($new_action) {
        $action = sanitize_key(self::request_value('action'));
        if (self::is_legacy_request($action)) {
            $nonce = self::request_nonce('_wpnonce');
            $legacy = isset(self::legacy_nonce_map()[$new_action]) ? self::legacy_nonce_map()[$new_action] : '';
            if (function_exists('wp_verify_nonce') && (wp_verify_nonce($nonce, $new_action) || ($legacy && wp_verify_nonce($nonce, $legacy)))) {
                return true;
            }
            if (function_exists('wp_die')) {
                wp_die(esc_html__('Security check failed. Refresh the page and try again.', 'uwh-site-migrator'), 403);
            }
            return false;
        }
        if (function_exists('check_admin_referer')) {
            return (bool) check_admin_referer($new_action);
        }
        return false;
    }

    public static function register_legacy_routes() {
        if (self::$routes_registered || !function_exists('add_action')) {
            return;
        }
        self::$routes_registered = true;

        $engine = class_exists('UWHSM_Engine') ? UWHSM_Engine::instance() : null;
        $admin = class_exists('UWHSM_Admin') && function_exists('is_admin') && is_admin() ? UWHSM_Admin::instance() : null;

        $ajax = array(
            'svm_backup_start'              => $engine ? array($engine, 'uwhsm_backup_start') : null,
            'svm_backup_step'               => $engine ? array($engine, 'uwhsm_backup_step') : null,
            'svm_upload_start'              => $engine ? array($engine, 'uwhsm_upload_start') : null,
            'svm_upload_chunk'              => $engine ? array($engine, 'uwhsm_upload_chunk') : null,
            'svm_restore_start'             => $engine ? array($engine, 'uwhsm_restore_start') : null,
            'svm_restore_local_start'       => $engine ? array($engine, 'uwhsm_restore_local_start') : null,
            'svm_restore_checkpoint_finish' => $engine ? array($engine, 'uwhsm_restore_checkpoint_finish') : null,
            'svm_restore_recover'           => $engine ? array($engine, 'uwhsm_restore_recover') : null,
            'svm_restore_step'              => $engine ? array($engine, 'uwhsm_restore_step') : null,
            'svm_checkpoint_rollback_start' => $engine ? array($engine, 'uwhsm_checkpoint_rollback_start') : null,
            'svm_checkpoint_delete'         => $engine ? array($engine, 'uwhsm_checkpoint_delete') : null,
            'svm_delete_backup'             => $engine ? array($engine, 'uwhsm_delete_backup') : null,
            'svm_save_auto_backup'          => $engine ? array($engine, 'uwhsm_save_auto_backup') : null,
            'svm_background_configure'      => class_exists('UWHSM_Background') ? array('UWHSM_Background', 'configure') : null,
            'svm_drive_upload_start'        => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_start') : null,
            'svm_drive_upload_step'         => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_step') : null,
            'svm_drive_upload_status'       => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_status') : null,
            'svm_drive_list'                => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_list') : null,
            'svm_drive_delete'              => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_delete') : null,
            'svm_drive_fetch'               => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_fetch') : null,
            'svm_drive_import_start'        => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_import_start') : null,
            'svm_drive_import_step'         => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_import_step') : null,
            'svm_drive_import_status'       => class_exists('UWHSM_Drive_Transfer') ? array('UWHSM_Drive_Transfer', 'ajax_import_status') : null,
            'svm_site_transfer_key_create'  => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_create_key') : null,
            'svm_site_transfer_key_revoke'  => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_revoke_key') : null,
            'svm_site_pull_start'           => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_pull_start') : null,
            'svm_site_pull_step'            => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_pull_step') : null,
            'svm_site_pull_status'          => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_pull_status') : null,
            'svm_site_pull_restore'         => class_exists('UWHSM_Site_Transfer') ? array('UWHSM_Site_Transfer', 'ajax_pull_restore') : null,
        );
        foreach ($ajax as $action => $callback) {
            if ($callback) {
                add_action('wp_ajax_' . $action, $callback);
            }

        }
        if ($engine) {
            add_action('wp_ajax_nopriv_svm_restore_step', array($engine, 'uwhsm_restore_step'));
        }

        if ($engine) {
            add_action('admin_post_svm_download', array($engine, 'download'));
        }
        if ($admin) {
            add_action('admin_post_svm_activity_logs_action', array($admin, 'activity_logs_action'));
            add_action('admin_post_svm_diagnostic_report', array($admin, 'download_diagnostic_report'));
        }
        if (class_exists('UWHSM_Drive')) {
            add_action('admin_post_svm_drive_action', array('UWHSM_Drive', 'admin_action'));
            add_action('admin_post_svm_drive_callback', array('UWHSM_Drive', 'admin_callback'));
            add_action('admin_post_nopriv_svm_drive_callback', array('UWHSM_Drive', 'admin_callback_login'));
        }
        if (class_exists('UWHSM_Drive_Transfer')) {
            add_action('admin_post_svm_drive_download', array('UWHSM_Drive_Transfer', 'proxy_download'));
        }
        if (class_exists('UWHSM_Site_Transfer')) {
            add_action('admin_post_svm_site_transfer_meta', array('UWHSM_Site_Transfer', 'public_meta'));
            add_action('admin_post_nopriv_svm_site_transfer_meta', array('UWHSM_Site_Transfer', 'public_meta'));
            add_action('admin_post_svm_site_transfer_package', array('UWHSM_Site_Transfer', 'public_package'));
            add_action('admin_post_nopriv_svm_site_transfer_package', array('UWHSM_Site_Transfer', 'public_package'));
        }

        // Old cron hooks are callback aliases during the v2.2.0 migration window.
        if ($engine) {
            add_action('svm_auto_backup_event', array($engine, 'run_auto_backup'));
        }
        if (class_exists('UWHSM_Background')) {
            add_action('svm_background_tick', array('UWHSM_Background', 'tick'));
        }
        if (class_exists('UWHSM_Drive_Transfer')) {
            add_action('svm_drive_transfer_tick', array('UWHSM_Drive_Transfer', 'cron_tick'));
        }
    }
}
