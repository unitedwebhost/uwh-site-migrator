<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
* Secure server-to-server transfer adapter.
* The source exposes only a selected completed .uwh archive behind an expiring key.
* The destination pulls fixed-size chunks and hands the verified package to UWHSM_Engine.
*/
final class UWHSM_Site_Transfer {
    const OPTION = 'uwhsm_direct_migration_sessions';
    const CHUNK_SIZE = 4 * 1024 * 1024;
    const KEY_TTL = 7200;

    public static function boot() {
        add_action('wp_ajax_uwhsm_site_transfer_key_create', array(__CLASS__, 'ajax_create_key'));
        add_action('wp_ajax_uwhsm_site_transfer_key_revoke', array(__CLASS__, 'ajax_revoke_key'));
        add_action('wp_ajax_uwhsm_site_connection_test', array(__CLASS__, 'ajax_connection_test'));
        add_action('wp_ajax_uwhsm_site_pull_start', array(__CLASS__, 'ajax_pull_start'));
        add_action('wp_ajax_uwhsm_site_pull_step', array(__CLASS__, 'ajax_pull_step'));
        add_action('wp_ajax_uwhsm_site_pull_status', array(__CLASS__, 'ajax_pull_status'));
        add_action('wp_ajax_uwhsm_site_pull_restore', array(__CLASS__, 'ajax_pull_restore'));

        add_action('admin_post_nopriv_uwhsm_site_transfer_meta', array(__CLASS__, 'public_meta'));
        add_action('admin_post_uwhsm_site_transfer_meta', array(__CLASS__, 'public_meta'));
        add_action('admin_post_nopriv_uwhsm_site_transfer_package', array(__CLASS__, 'public_package'));
        add_action('admin_post_uwhsm_site_transfer_package', array(__CLASS__, 'public_package'));
    }

    public static function parse_migration_key($key) {
        $key = trim((string) $key);
        if (!preg_match('/^svm1_([a-f0-9]{24})_([a-f0-9]{64})$/D', $key, $m)) {
            return false;
        }
        return array('session' => $m[1], 'secret' => $m[2]);
    }

    public static function build_source_endpoint($source_url, $kind) {
        $source_url = trim((string) $source_url);
        $parts = wp_parse_url($source_url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ('https' !== $scheme) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        $action = 'package' === $kind ? 'uwhsm_site_transfer_package' : 'uwhsm_site_transfer_meta';
        return $scheme . '://' . $parts['host'] . $port . $path . '/wp-admin/admin-post.php?action=' . $action;
    }

    private static function guard() {
        UWHSM_Compat::verify_ajax_nonce('uwhsm_admin');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Administrator permission required.', 'uwh-site-migrator')), 403);
        }
        if (is_multisite()) {
            wp_send_json_error(array('message' => __('WordPress Multisite is not supported.', 'uwh-site-migrator')), 400);
        }
    }

    private static function clean_sessions($save = true) {
        $sessions = get_option(self::OPTION, array());
        if (!is_array($sessions)) {
            $sessions = array();
        }
        $now = time();
        $changed = false;
        foreach ($sessions as $id => $session) {
            if (!is_array($session) || empty($session['expires']) || (int) $session['expires'] < $now) {
                unset($sessions[$id]);
                $changed = true;
            }
        }
        if ($changed && $save) {
            update_option(self::OPTION, $sessions, false);
        }
        return $sessions;
    }

    private static function save_sessions(array $sessions) {
        update_option(self::OPTION, $sessions, false);
    }

    public static function ajax_create_key() {
        self::guard();
        $name = sanitize_file_name(UWHSM_Compat::post_value('backup_name'));
        try {
            $record = UWHSM_Engine::instance()->completed_backup($name);
            $session_id = bin2hex(random_bytes(12));
            $secret = bin2hex(random_bytes(32));
            $sessions = self::clean_sessions(false);
            $sessions[$session_id] = array(
                'secret_hash' => hash('sha256', $secret),
                'backup_name' => $record['name'],
                'created' => time(),
                'expires' => time() + self::KEY_TTL,
                'last_access' => 0,
                'download_complete_at' => 0,
            );
            self::save_sessions($sessions);
            UWHSM_Logger::log('migration', 'started', __('Direct Migration key created.', 'uwh-site-migrator'), array('backup' => $record['name'], 'size' => (int) $record['size']));
            wp_send_json_success(array(
                'migration_key' => 'svm1_' . $session_id . '_' . $secret,
                'source_url' => site_url('/'),
                'backup_name' => $record['name'],
                'size' => (int) $record['size'],
                'expires' => (int) $sessions[$session_id]['expires'],
            ));
        } catch (Throwable $e) {
            UWHSM_Logger::log('migration', 'failed', __('Direct Migration key could not be created.', 'uwh-site-migrator'), array('error' => $e->getMessage()));
            wp_send_json_error(array('message' => $e->getMessage()), 400);
        }
    }

    public static function ajax_revoke_key() {
        self::guard();
        $key = UWHSM_Compat::post_value('migration_key');
        $parsed = self::parse_migration_key($key);
        if (!$parsed) {
            wp_send_json_error(array('message' => __('Invalid migration key.', 'uwh-site-migrator')), 400);
        }
        $sessions = self::clean_sessions(false);
        unset($sessions[$parsed['session']]);
        self::save_sessions($sessions);
        UWHSM_Logger::log('migration', 'info', __('Direct Migration key revoked.', 'uwh-site-migrator'));
        wp_send_json_success(array('message' => __('Migration key revoked.', 'uwh-site-migrator')));
    }

    private static function request_key() {
        return UWHSM_Compat::server_value('HTTP_X_SVM_MIGRATION_KEY');
    }

    private static function authorize_public() {
        $parsed = self::parse_migration_key(self::request_key());
        if (!$parsed) {
            return new WP_Error('svm_key', __('Invalid migration key.', 'uwh-site-migrator'), array('status' => 403));
        }
        $sessions = self::clean_sessions(false);
        $session = isset($sessions[$parsed['session']]) && is_array($sessions[$parsed['session']]) ? $sessions[$parsed['session']] : null;
        if (!$session || empty($session['secret_hash']) || !hash_equals((string) $session['secret_hash'], hash('sha256', $parsed['secret']))) {
            return new WP_Error('svm_key', __('Migration key is invalid or expired.', 'uwh-site-migrator'), array('status' => 403));
        }
        if ((int) $session['expires'] < time()) {
            return new WP_Error('svm_key', __('Migration key has expired.', 'uwh-site-migrator'), array('status' => 403));
        }
        try {
            $record = UWHSM_Engine::instance()->completed_backup($session['backup_name']);
        } catch (Throwable $e) {
            return new WP_Error('svm_package', __('The shared migration package is no longer available.', 'uwh-site-migrator'), array('status' => 404));
        }

        // Keep active large transfers alive without writing the options table for every 4 MB chunk.
        $now = time();
        $cap = (int) $session['created'] + 12 * HOUR_IN_SECONDS;
        if ($now - (int) ($session['last_access'] ?? 0) >= 60 || (int) $session['expires'] < $now + 15 * MINUTE_IN_SECONDS) {
            $session['last_access'] = $now;
            $session['expires'] = min($cap, max((int) $session['expires'], $now + 30 * MINUTE_IN_SECONDS));
            $sessions[$parsed['session']] = $session;
            self::save_sessions($sessions);
        }
        return array('id' => $parsed['session'], 'session' => $session, 'record' => $record);
    }

    private static function public_error($error) {
        nocache_headers();
        $status = 403;
        if (is_wp_error($error)) {
            $data = $error->get_error_data();
            if (is_array($data) && !empty($data['status'])) {
                $status = (int) $data['status'];
            }
            wp_send_json_error(array('message' => $error->get_error_message()), $status);
        }
        wp_send_json_error(array('message' => __('Migration authorization failed.', 'uwh-site-migrator')), $status);
    }

    public static function public_meta() {
        nocache_headers();
        $auth = self::authorize_public();
        if (is_wp_error($auth)) {
            self::public_error($auth);
        }
        $record = $auth['record'];
        wp_send_json_success(array(
            'backup_name' => $record['name'],
            'size' => (int) $record['size'],
            'mtime' => (int) $record['mtime'],
            'source_home' => home_url('/'),
            'source_site' => site_url('/'),
            'source_domain' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => UWHSM_VERSION,
            'chunk_size' => self::CHUNK_SIZE,
            'expires' => (int) $auth['session']['expires'],
        ));
    }

    public static function public_package() {
        $auth = self::authorize_public();
        if (is_wp_error($auth)) {
            self::public_error($auth);
        }
        $record = $auth['record'];
        $path = $record['path'];
        $size = (int) $record['size'];
        $offset = (UWHSM_Compat::get_value('offset') !== '' ? absint(UWHSM_Compat::get_value('offset')) : 0);
        $length = (UWHSM_Compat::get_value('length') !== '' ? absint(UWHSM_Compat::get_value('length')) : self::CHUNK_SIZE);
        $length     = max(1, min(self::CHUNK_SIZE, $length));
        if ($offset >= $size) {
            status_header(416);
            exit;
        }
        $length = min($length, $size - $offset);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $fh = @fopen($path, 'rb');
        if (!$fh || 0 !== fseek($fh, $offset)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            if ($fh) fclose($fh);
            status_header(500);
            exit;
        }
        while (ob_get_level()) {
            @ob_end_clean();
        }
        @ignore_user_abort(true);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
        @set_time_limit(0);
        nocache_headers();
        status_header(206);
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $size);
        header('X-Accel-Buffering: no');
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streaming is required to transfer large backup archives without loading them fully into memory.
            $chunk = fread($fh, min(1024 * 1024, $remaining));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw output is a binary/plain-text download stream; escaping would corrupt the downloaded content.
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        fclose($fh);
        if (0 === $remaining && ($offset + $length) >= $size) {
            $sessions = self::clean_sessions(false);
            if (isset($sessions[$auth['id']])) {
                $sessions[$auth['id']]['download_complete_at'] = time();
                self::save_sessions($sessions);
            }
        }
        exit;
    }

    private static function direct_dir() {
        $dir = UWHSM_Engine::instance()->integration_directory() . '/direct';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $dir;
    }

    private static function state_path($id) {
        return self::direct_dir() . '/pull-' . preg_replace('/[^a-f0-9]/', '', (string) $id) . '.json';
    }

    private static function lock_path($id) {
        return self::direct_dir() . '/pull-' . preg_replace('/[^a-f0-9]/', '', (string) $id) . '.lock';
    }

    private static function read_state($id) {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $id)) {
            return false;
        }
        $path = self::state_path($id);
        if (!is_file($path)) {
            return false;
        }
        $state = json_decode((string) @file_get_contents($path), true);
        return is_array($state) ? $state : false;
    }

    private static function write_state($id, array $state) {
        $path = self::state_path($id);
        $tmp = $path . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
        if (false === file_put_contents($tmp, wp_json_encode($state), LOCK_EX) || !@rename($tmp, $path)) {
            throw new RuntimeException(esc_html__('Could not save direct migration progress.', 'uwh-site-migrator'));
        }
    }

    public static function cleanup_pull($id) {
        if (!preg_match('/^[a-f0-9]{32}$/D', (string) $id)) {
            return;
        }
        wp_delete_file(self::state_path($id));
        wp_delete_file(self::state_path($id) . '.tmp');
        wp_delete_file(self::lock_path($id));
    }

    public static function backup_is_shared($name) {
        $name = basename((string) $name);
        foreach (self::clean_sessions(false) as $session) {
            if (is_array($session) && !empty($session['backup_name']) && hash_equals((string) $session['backup_name'], $name) && (int) ($session['expires'] ?? 0) >= time()) {
                return true;
            }
        }
        return false;
    }

    private static function crypto_key() {
        return hash('sha256', wp_salt('auth') . '|site-migrator-direct', true);
    }

    private static function protect_secret($secret) {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException(esc_html__('OpenSSL is required for Direct Site-to-Site Migration.', 'uwh-site-migrator'));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string) $secret, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $cipher || strlen($tag) < 12) {
            throw new RuntimeException(esc_html__('Could not secure the migration key on this server.', 'uwh-site-migrator'));
        }
        return base64_encode($iv . $tag . $cipher);
    }

    private static function reveal_secret($payload) {
        $raw = base64_decode((string) $payload, true);
        if (false === $raw || strlen($raw) < 29) {
            throw new RuntimeException(esc_html__('Stored migration authorization is damaged. Restart the direct migration.', 'uwh-site-migrator'));
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $plain) {
            throw new RuntimeException(esc_html__('Could not unlock the stored migration authorization. Restart the direct migration.', 'uwh-site-migrator'));
        }
        return $plain;
    }

    private static function remote_json($url, $key) {
        $response = wp_safe_remote_get($url, array(
            'timeout' => 25, 'redirection' => 0, 'reject_unsafe_urls' => true,
            'headers' => array('X-SVM-Migration-Key' => $key, 'Accept' => 'application/json'),
        ));
        if (is_wp_error($response)) {
            throw new RuntimeException(esc_html__('Could not reach the source site: ', 'uwh-site-migrator') . esc_html($response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['success']) || !is_array($json['data'])) {
            $message = is_array($json) && isset($json['data']['message']) ? $json['data']['message'] : __('Source site rejected the migration connection.', 'uwh-site-migrator');
            throw new RuntimeException(esc_html((string) $message));
        }
        return $json['data'];
    }

    public static function ajax_connection_test() {
        self::guard();
        $source = esc_url_raw(UWHSM_Compat::post_value('source_url'));
        $key = UWHSM_Compat::post_value('migration_key');
        $checks = array();
        $ready = true;
        $meta = array();

        $add_check = static function($id, $label, $status, $message = '') use (&$checks, &$ready) {
            $status = in_array($status, array('pass', 'warning', 'fail'), true) ? $status : 'fail';
            $checks[] = array(
                'id' => sanitize_key((string) $id),
                'label' => (string) $label,
                'status' => $status,
                'message' => (string) $message,
            );
            if ('fail' === $status) {
                $ready = false;
            }
        };

        $parsed = self::parse_migration_key($key);
        if (!$parsed) {
            $add_check('key', __('Migration key', 'uwh-site-migrator'), 'fail', __('Enter a valid Site Migrator migration key.', 'uwh-site-migrator'));
            wp_send_json_success(array(
                'ready' => false,
                'connected' => false,
                'checks' => $checks,
                'message' => __('Migration key is invalid.', 'uwh-site-migrator'),
            ));
        }

        $endpoint = self::build_source_endpoint($source, 'meta');
        if (!$endpoint) {
            $add_check('source', __('Source connection', 'uwh-site-migrator'), 'fail', __('Enter the source WordPress URL, including https://.', 'uwh-site-migrator'));
            wp_send_json_success(array(
                'ready' => false,
                'connected' => false,
                'checks' => $checks,
                'message' => __('Source WordPress URL is invalid.', 'uwh-site-migrator'),
            ));
        }

        try {
            $meta = self::remote_json($endpoint, $key);
            $add_check('source', __('Source connection', 'uwh-site-migrator'), 'pass', __('Source site reached and migration key accepted.', 'uwh-site-migrator'));
        } catch (Throwable $e) {
            $add_check('source', __('Source connection', 'uwh-site-migrator'), 'fail', $e->getMessage());
            wp_send_json_success(array(
                'ready' => false,
                'connected' => false,
                'checks' => $checks,
                'message' => $e->getMessage(),
            ));
        }

        $expires = (int) ($meta['expires'] ?? 0);
        if ($expires > time()) {
            $add_check('key', __('Migration key', 'uwh-site-migrator'), 'pass', __('Valid and not expired.', 'uwh-site-migrator'));
        } else {
            $add_check('key', __('Migration key', 'uwh-site-migrator'), 'fail', __('Migration key has expired. Generate a new key on the source site.', 'uwh-site-migrator'));
        }

        $plugin_version = (string) ($meta['plugin_version'] ?? '0');
        if (version_compare($plugin_version, '2.1.0', '>=')) {
            $add_check('plugin', __('Source Site Migrator', 'uwh-site-migrator'), 'pass', sprintf(
                /* translators: %s: Site Migrator plugin version. */
                __('Version %s is compatible.', 'uwh-site-migrator'),
                $plugin_version
            ));
        } else {
            $add_check('plugin', __('Source Site Migrator', 'uwh-site-migrator'), 'fail', __('The source site must run Site Migrator 2.1.0 or newer.', 'uwh-site-migrator'));
        }

        $source_wp = (string) ($meta['wp_version'] ?? '');
        $destination_wp = (string) get_bloginfo('version');
        if ($source_wp && $source_wp === $destination_wp) {
            $add_check('wordpress', __('WordPress version', 'uwh-site-migrator'), 'pass', sprintf(
                /* translators: %s: WordPress version. */
                __('Both sites use WordPress %s.', 'uwh-site-migrator'),
                $destination_wp
            ));
        } else {
            $add_check('wordpress', __('WordPress version', 'uwh-site-migrator'), 'fail', sprintf(
                /* translators: 1: destination WordPress version, 2: source WordPress version. */
                __('Destination %1$s; source %2$s. Versions must match.', 'uwh-site-migrator'),
                $destination_wp,
                $source_wp ?: __('unknown', 'uwh-site-migrator')
            ));
        }

        $size = (int) ($meta['size'] ?? 0);
        if ($size < 100 || $size > 50 * 1024 * 1024 * 1024) {
            $add_check('package', __('Migration package', 'uwh-site-migrator'), 'fail', __('Source package size is outside the supported range.', 'uwh-site-migrator'));
        } elseif ($size > 2 * 1024 * 1024 * 1024 && PHP_INT_SIZE < 8) {
            $add_check('package', __('Migration package', 'uwh-site-migrator'), 'fail', __('This destination uses 32-bit PHP and cannot safely migrate a package larger than 2 GB.', 'uwh-site-migrator'));
        } else {
            $add_check('package', __('Migration package', 'uwh-site-migrator'), 'pass', sprintf(
                /* translators: %s: backup package size. */
                __('Package size: %s.', 'uwh-site-migrator'),
                size_format($size, 2)
            ));
        }

        $zip_ok = class_exists('ZipArchive');
        $add_check('zip', __('ZipArchive', 'uwh-site-migrator'), $zip_ok ? 'pass' : 'fail', $zip_ok ? __('Available.', 'uwh-site-migrator') : __('PHP ZipArchive is required.', 'uwh-site-migrator'));

        $openssl_ok = function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
        $add_check('openssl', __('OpenSSL', 'uwh-site-migrator'), $openssl_ok ? 'pass' : 'fail', $openssl_ok ? __('Available.', 'uwh-site-migrator') : __('PHP OpenSSL is required to protect the migration authorization.', 'uwh-site-migrator'));

        $base = UWHSM_Engine::instance()->integration_directory();
        $imports = rtrim($base, '/\\') . '/imports';
        if (!is_dir($imports)) {
            wp_mkdir_p($imports);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Pre-flight diagnostics must verify the real destination storage permissions.
        $storage_ok = is_dir($imports) && is_writable($imports);
        $add_check('storage', __('Destination storage', 'uwh-site-migrator'), $storage_ok ? 'pass' : 'fail', $storage_ok ? __('Migration storage is writable.', 'uwh-site-migrator') : __('Migration storage is not writable.', 'uwh-site-migrator'));

        $free = @disk_free_space($base);
        $required = $size > 0 ? (int) ($size * 2.5) + 256 * 1024 * 1024 : 0;
        if (false === $free) {
            $add_check('disk', __('Free disk space', 'uwh-site-migrator'), 'warning', __('Could not measure free disk space. The migration can continue, but verify available storage first.', 'uwh-site-migrator'));
        } elseif ($required > 0 && $free < $required) {
            $add_check('disk', __('Free disk space', 'uwh-site-migrator'), 'fail', sprintf(
                /* translators: 1: required disk space, 2: available disk space. */
                __('Required approximately %1$s; available %2$s.', 'uwh-site-migrator'),
                size_format($required, 2),
                size_format($free, 2)
            ));
        } else {
            $add_check('disk', __('Free disk space', 'uwh-site-migrator'), 'pass', sprintf(
                /* translators: %s: available disk space. */
                __('Available: %s.', 'uwh-site-migrator'),
                false === $free ? __('unknown', 'uwh-site-migrator') : size_format($free, 2)
            ));
        }

        global $wpdb;
        $database_ok = false;
        try {
            $database_ok = is_object($wpdb) && method_exists($wpdb, 'check_connection') && (bool) $wpdb->check_connection(false);
        } catch (Throwable $ignored) {
            $database_ok = false;
        }
        $add_check('database', __('Database connection', 'uwh-site-migrator'), $database_ok ? 'pass' : 'fail', $database_ok ? __('Destination database connection is healthy.', 'uwh-site-migrator') : __('Destination database connection check failed.', 'uwh-site-migrator'));

        $pending = UWHSM_Engine::instance()->pending_restores();
        $restore_slot_ok = empty($pending);
        $add_check('restore_slot', __('Restore slot', 'uwh-site-migrator'), $restore_slot_ok ? 'pass' : 'fail', $restore_slot_ok ? __('No unfinished restore is blocking migration.', 'uwh-site-migrator') : __('An unfinished restore exists. Resume or finish it before starting another migration.', 'uwh-site-migrator'));

        $admin_https = is_ssl() || 0 === strpos(strtolower(admin_url()), 'https://');
        $add_check('admin_https', __('Admin HTTPS', 'uwh-site-migrator'), $admin_https ? 'pass' : 'warning', $admin_https ? __('WordPress admin is using HTTPS.', 'uwh-site-migrator') : __('WordPress admin is not detected as HTTPS. Use HTTPS before sending migration credentials.', 'uwh-site-migrator'));

        $add_check('ajax', __('WordPress AJAX', 'uwh-site-migrator'), 'pass', __('AJAX endpoint is responding.', 'uwh-site-migrator'));

        wp_send_json_success(array(
            'ready' => (bool) $ready,
            'connected' => true,
            'checks' => $checks,
            'message' => $ready ? __('Connection and pre-flight checks passed.', 'uwh-site-migrator') : __('Connection succeeded, but one or more pre-flight checks need attention.', 'uwh-site-migrator'),
            'source_domain' => sanitize_text_field((string) ($meta['source_domain'] ?? '')),
            'backup_name' => sanitize_file_name((string) ($meta['backup_name'] ?? '')),
            'package_size' => $size,
            'expires' => $expires,
            'plugin_version' => $plugin_version,
            'source_wp_version' => $source_wp,
            'destination_wp_version' => $destination_wp,
            'destination_domain' => sanitize_text_field((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
        ));
    }

    public static function ajax_pull_start() {
        self::guard();
        $source = esc_url_raw(UWHSM_Compat::post_value('source_url'));
        $key = UWHSM_Compat::post_value('migration_key');
        $parsed = self::parse_migration_key($key);
        if (!$parsed) {
            wp_send_json_error(array('message' => __('Enter a valid Site Migrator migration key.', 'uwh-site-migrator')), 400);
        }
        $endpoint = self::build_source_endpoint($source, 'meta');
        if (!$endpoint) {
            wp_send_json_error(array('message' => __('Enter the source WordPress URL, including https://.', 'uwh-site-migrator')), 400);
        }
        if (0 !== strpos(strtolower($endpoint), 'https://')) {
            wp_send_json_error(array('message' => __('Direct migration requires HTTPS so the migration key is never sent over an unencrypted connection.', 'uwh-site-migrator')), 400);
        }
        try {
            $meta = self::remote_json($endpoint, $key);
            $size = (int) ($meta['size'] ?? 0);
            if (version_compare((string) ($meta['plugin_version'] ?? '0'), '2.1.0', '<')) {
                throw new RuntimeException(__('The source site must run Site Migrator 2.1.0 or newer for Direct Migration.', 'uwh-site-migrator'));
            }
            if ($size < 100 || $size > 50 * 1024 * 1024 * 1024) {
                throw new RuntimeException(__('Source package size is outside the supported range.', 'uwh-site-migrator'));
            }
            if (($meta['wp_version'] ?? '') !== get_bloginfo('version')) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: destination WordPress version, 2: source WordPress version. */
                    __('WordPress versions must match before direct migration. Destination: %1$s; source: %2$s.', 'uwh-site-migrator'),
                    get_bloginfo('version'),
                    ($meta['wp_version'] ?? __('unknown', 'uwh-site-migrator'))
                ));
            }
            if ($size > 2 * 1024 * 1024 * 1024 && PHP_INT_SIZE < 8) {
                throw new RuntimeException(__('This destination uses 32-bit PHP and cannot safely migrate a package larger than 2 GB.', 'uwh-site-migrator'));
            }
            $base = UWHSM_Engine::instance()->integration_directory();
            $free = @disk_free_space($base);
            $required = (int) ($size * 2.5) + 256 * 1024 * 1024;
            if (false !== $free && $free < $required) {
                throw new RuntimeException(sprintf(
                    /* translators: 1: required disk space, 2: available disk space. */
                    __('Not enough free space on the destination. Required approximately %1$s; available %2$s.', 'uwh-site-migrator'),
                    size_format($required, 2),
                    size_format($free, 2)
                ));
            }
            $id = bin2hex(random_bytes(16));
            $dir = $base . '/imports/' . $id;
            if (!wp_mkdir_p($dir)) {
                throw new RuntimeException(__('Could not create the direct migration import directory.', 'uwh-site-migrator'));
            }
            $archive = $dir . '/package.svm.zip';
            if (false === file_put_contents($archive, '')) {
                throw new RuntimeException(__('Could not create the destination package file.', 'uwh-site-migrator'));
            }
            $state = array(
                'type' => 'site_pull', 'pull_id' => $id, 'source_url' => rtrim($source, '/'),
                'protected_key' => self::protect_secret($key), 'backup_name' => sanitize_file_name((string) ($meta['backup_name'] ?? 'source.uwh')),
                'source_domain' => sanitize_text_field((string) ($meta['source_domain'] ?? '')), 'size' => $size, 'received' => 0,
                'chunk_size' => min(self::CHUNK_SIZE, max(256 * 1024, (int) ($meta['chunk_size'] ?? self::CHUNK_SIZE))),
                'archive' => $archive, 'status' => 'pulling', 'created' => time(), 'updated' => time(), 'restore_started' => false,
            );
            self::write_state($id, $state);
            UWHSM_Logger::log('migration', 'started', __('Direct site-to-site package transfer started.', 'uwh-site-migrator'), array('pull_id' => $id, 'backup' => $state['backup_name'], 'source_domain' => $state['source_domain'], 'size' => $size));
            wp_send_json_success(self::public_pull_state($state));
        } catch (Throwable $e) {
            UWHSM_Logger::log('migration', 'failed', __('Direct Migration transfer could not start.', 'uwh-site-migrator'), array('error' => $e->getMessage()));
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    private static function public_pull_state(array $state) {
        $size = max(1, (int) $state['size']);
        $received = min($size, (int) $state['received']);
        return array(
            'pull_id' => $state['pull_id'], 'done' => $received >= $size,
            'progress' => min(100, ($received / $size) * 100), 'bytes_received' => $received, 'bytes_total' => $size,
            'backup_name' => $state['backup_name'], 'source_domain' => $state['source_domain'],
            'status' => $state['status'], 'label' => $received >= $size ? __('Source package downloaded', 'uwh-site-migrator') : __('Pulling website package from source', 'uwh-site-migrator'),
        );
    }

    public static function ajax_pull_status() {
        self::guard();
        $id = UWHSM_Compat::post_value('pull_id');
        $state = self::read_state($id);
        if (!$state) {
            wp_send_json_error(array('message' => __('Direct migration session not found.', 'uwh-site-migrator')), 404);
        }
        wp_send_json_success(self::public_pull_state($state));
    }

    public static function ajax_pull_step() {
        self::guard();
        $id = UWHSM_Compat::post_value('pull_id');
        $state = self::read_state($id);
        if (!$state) {
            wp_send_json_error(array('message' => __('Direct migration session not found.', 'uwh-site-migrator')), 404);
        }
        if ((int) $state['received'] >= (int) $state['size']) {
            $state['status'] = 'downloaded';
            wp_send_json_success(self::public_pull_state($state));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen(self::lock_path($id), 'c');
        if (!$lock) {
            wp_send_json_error(array('message' => __('Could not lock the direct migration session.', 'uwh-site-migrator')), 500);
        }
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            $out = self::public_pull_state($state);
            $out['busy'] = true;
            wp_send_json_success($out);
        }
        try {
            $state = self::read_state($id);
            if (!$state) {
                throw new RuntimeException(__('Direct migration session disappeared.', 'uwh-site-migrator'));
            }
            $offset = (int) $state['received'];
            $length = min((int) $state['chunk_size'], (int) $state['size'] - $offset);
            $key = self::reveal_secret($state['protected_key']);
            $url = add_query_arg(array('offset' => $offset, 'length' => $length), self::build_source_endpoint($state['source_url'], 'package'));
            $response = wp_safe_remote_get($url, array(
                'timeout' => 40, 'redirection' => 0, 'reject_unsafe_urls' => true,
                'headers' => array('X-SVM-Migration-Key' => $key, 'Accept' => 'application/octet-stream'),
            ));
            if (is_wp_error($response)) {
                throw new RuntimeException(__('Source transfer failed: ', 'uwh-site-migrator') . $response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if (206 !== $code && 200 !== $code) {
                throw new RuntimeException(__('Source transfer returned HTTP ', 'uwh-site-migrator') . $code . '.');
            }
            if (strlen($body) !== $length) {
                throw new RuntimeException(__('Source chunk length mismatch. The transfer can be resumed safely.', 'uwh-site-migrator'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $fh = @fopen($state['archive'], 'c+b');
            if (!$fh) {
                throw new RuntimeException(__('Could not write the incoming migration package.', 'uwh-site-migrator'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Native streaming is required to write large backup archives/chunks without loading them fully into memory.
            $ok = ftruncate($fh, $offset) && 0 === fseek($fh, $offset) && strlen($body) === fwrite($fh, $body);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($fh);
            if (!$ok) {
                throw new RuntimeException(__('Could not commit the incoming migration chunk.', 'uwh-site-migrator'));
            }
            $state['received'] = $offset + $length;
            $state['updated'] = time();
            if ((int) $state['received'] >= (int) $state['size']) {
                $state['status'] = 'downloaded';
                UWHSM_Logger::log_once('direct-pull-complete-' . $id, 'migration', 'success', __('Direct Migration package download completed.', 'uwh-site-migrator'), array('pull_id' => $id, 'backup' => $state['backup_name'], 'source_domain' => $state['source_domain'], 'size' => (int) $state['size']), 7*DAY_IN_SECONDS);
                // The source authorization is no longer required once the complete package is durable locally.
                unset($state['protected_key']);
            }
            self::write_state($id, $state);
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            wp_send_json_success(self::public_pull_state($state));
        } catch (Throwable $e) {
            UWHSM_Logger::log_once('direct-pull-error-' . $id . '-' . md5($e->getMessage()), 'migration', 'failed', __('Direct Migration package transfer encountered an error.', 'uwh-site-migrator'), array('pull_id' => $id, 'backup' => (string) ($state['backup_name'] ?? ''), 'source_domain' => (string) ($state['source_domain'] ?? ''), 'error' => $e->getMessage()), 3600);
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($lock);
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }

    public static function ajax_pull_restore() {
        self::guard();
        $id = UWHSM_Compat::post_value('pull_id');
        $state = self::read_state($id);
        if (!$state) {
            wp_send_json_error(array('message' => __('Direct migration session not found.', 'uwh-site-migrator')), 404);
        }
        if ((int) $state['received'] !== (int) $state['size'] || !is_file($state['archive']) || (int) filesize($state['archive']) !== (int) $state['size']) {
            wp_send_json_error(array('message' => __('The source package has not finished downloading.', 'uwh-site-migrator')), 409);
        }
        try {
            if (!empty($state['restore_started']) && !empty($state['protected_restore_token'])) {
                $restore_token = self::reveal_secret($state['protected_restore_token']);
                $existing = UWHSM_Engine::instance()->restore_session_state($id, $restore_token);
                if ($existing) {
                    wp_send_json_success($existing);
                }
            }
            $create_checkpoint = '1' === UWHSM_Compat::post_value('create_checkpoint');
            $result = UWHSM_Engine::instance()->begin_external_restore($id, $state['archive'], 'direct', $state['backup_name'], true, $create_checkpoint);
            $state['restore_started'] = true;
            $state['protected_restore_token'] = self::protect_secret($result['token']);
            $state['updated'] = time();
            self::write_state($id, $state);
            UWHSM_Logger::log('migration', 'success', __('Direct Migration package handed to restore engine.', 'uwh-site-migrator'), array('pull_id' => $id, 'backup' => $state['backup_name'], 'source_domain' => $state['source_domain']));
            wp_send_json_success($result);
        } catch (Throwable $e) {
            UWHSM_Logger::log('migration', 'failed', __('Direct Migration restore could not start.', 'uwh-site-migrator'), array('pull_id' => $id, 'backup' => (string) ($state['backup_name'] ?? ''), 'source_domain' => (string) ($state['source_domain'] ?? ''), 'error' => $e->getMessage()));
            wp_send_json_error(array('message' => $e->getMessage()), 500);
        }
    }
}
