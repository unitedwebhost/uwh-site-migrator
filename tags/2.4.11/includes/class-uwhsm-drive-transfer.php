<?php
if (!defined('ABSPATH')) {
    exit;
}

final class UWHSM_Drive_Transfer {
    const HOOK = 'uwhsm_drive_transfer_tick';
    const CHUNK_SIZE = 8388608;
    const STATE_INDEX_OPTION = 'uwhsm_drive_transfer_state_ids';
    const IMPORT_STATE_INDEX_OPTION = 'uwhsm_drive_import_state_ids';
    const STATE_OPTION_PREFIX = 'uwhsm_drive_transfer_state_';
    const IMPORT_STATE_OPTION_PREFIX = 'uwhsm_drive_import_state_';
    private static $booted = false;
    private static $test_transport = null;
    private static $test_clock = null;

    public static function boot() {
        self::ensure_directory();
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        add_action('wp_ajax_uwhsm_drive_upload_start', array(__CLASS__, 'ajax_start'));
        add_action('wp_ajax_uwhsm_drive_upload_step', array(__CLASS__, 'ajax_step'));
        add_action('wp_ajax_uwhsm_drive_upload_status', array(__CLASS__, 'ajax_status'));
        add_action('wp_ajax_uwhsm_drive_list', array(__CLASS__, 'ajax_list'));
        add_action('wp_ajax_uwhsm_drive_delete', array(__CLASS__, 'ajax_delete'));
        add_action('wp_ajax_uwhsm_drive_fetch', array(__CLASS__, 'ajax_fetch'));
        add_action('wp_ajax_uwhsm_drive_import_start', array(__CLASS__, 'ajax_import_start'));
        add_action('wp_ajax_uwhsm_drive_import_step', array(__CLASS__, 'ajax_import_step'));
        add_action('wp_ajax_uwhsm_drive_import_status', array(__CLASS__, 'ajax_import_status'));
        add_action('admin_post_uwhsm_drive_download', array(__CLASS__, 'proxy_download'));
        add_action(self::HOOK, array(__CLASS__, 'cron_tick'));
    }

    private static function directory() {
        return rtrim(UWHSM_Engine::instance()->integration_directory(), '/\\') . '/drive-transfers';
    }

    private static function ensure_directory() {
        $dir = self::directory();
        if (!wp_mkdir_p($dir)) {
            throw new RuntimeException(esc_html__('Could not create protected Drive transfer storage.', 'uwh-site-migrator'));
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n", LOCK_EX);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n", LOCK_EX);
        }
        return $dir;
    }

    private static function valid_id($id) {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{32,64}$/D', $id);
    }

    private static function lock_path($id) {
        if (!self::valid_id($id)) {
            throw new RuntimeException(esc_html__('Invalid Drive transfer ID.', 'uwh-site-migrator'));
        }
        return self::directory() . '/transfer-' . $id . '.lock';
    }

    private static function import_lock_path($id) {
        if (!self::valid_id($id)) {
            throw new RuntimeException(esc_html__('Invalid Drive import ID.', 'uwh-site-migrator'));
        }
        return self::directory() . '/import-' . $id . '.lock';
    }

    private static function key() {
        return hash('sha256', 'site-vault-drive-transfer-1|' . wp_salt('auth'), true);
    }

    private static function aad($id) {
        return home_url('/') . '|' . ABSPATH . '|' . $id;
    }

    private static function import_key() {
        return hash('sha256', 'site-migrator-drive-import-1|' . wp_salt('auth'), true);
    }

    private static function import_aad($id) {
        return home_url('/') . '|' . ABSPATH . '|drive-import|' . $id;
    }

    private static function state_option_name($id, $import = false) {
        if (!self::valid_id($id)) {
            throw new RuntimeException($import ? esc_html__('Invalid Drive import ID.', 'uwh-site-migrator') : esc_html__('Invalid Drive transfer ID.', 'uwh-site-migrator'));
        }
        return ($import ? self::IMPORT_STATE_OPTION_PREFIX : self::STATE_OPTION_PREFIX) . $id;
    }

    private static function state_ids($import = false) {
        $option = $import ? self::IMPORT_STATE_INDEX_OPTION : self::STATE_INDEX_OPTION;
        $stored = get_option($option, array());
        $stored = is_array($stored) ? $stored : array();
        $clean = array();
        foreach ($stored as $id) {
            $id = is_scalar($id) ? (string) $id : '';
            if (self::valid_id($id) && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }
        if ($clean !== array_values($stored)) {
            update_option($option, $clean, false);
        }
        return $clean;
    }

    private static function register_state_id($id, $import = false) {
        $ids = self::state_ids($import);
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            update_option($import ? self::IMPORT_STATE_INDEX_OPTION : self::STATE_INDEX_OPTION, $ids, false);
        }
    }

    private static function unregister_state_id($id, $import = false) {
        $ids = array_values(array_diff(self::state_ids($import), array($id)));
        update_option($import ? self::IMPORT_STATE_INDEX_OPTION : self::STATE_INDEX_OPTION, $ids, false);
    }

    private static function encode_state(array $state, $id, $key, $aad) {
        $iv = random_bytes(12);
        $json = wp_json_encode($state);
        $cipher = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if (false === $cipher) {
            throw new RuntimeException(esc_html__('Could not encrypt Drive transfer progress.', 'uwh-site-migrator'));
        }
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decode_state($payload, $id, $key, $aad, $expected_type) {
        if (!is_string($payload) || '' === $payload) {
            throw new RuntimeException(esc_html__('Drive transfer progress is missing or damaged.', 'uwh-site-migrator'));
        }
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            throw new RuntimeException(esc_html__('Drive transfer progress is damaged.', 'uwh-site-migrator'));
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        if (false === $plain) {
            throw new RuntimeException(esc_html__('Drive transfer belongs to another site, its security keys changed, or its state was modified.', 'uwh-site-migrator'));
        }
        $state = json_decode($plain, true);
        $id_field = 'drive_import' === $expected_type ? 'import_id' : 'transfer_id';
        if (!is_array($state) || ($state[$id_field] ?? '') !== $id || ($state['type'] ?? '') !== $expected_type) {
            throw new RuntimeException(esc_html__('Drive transfer progress is damaged.', 'uwh-site-migrator'));
        }
        return $state;
    }

    private static function write_state(array $state) {
        $id = (string) ($state['transfer_id'] ?? '');
        $option = self::state_option_name($id, false);
        $payload = self::encode_state($state, $id, self::key(), self::aad($id));
        $saved = update_option($option, $payload, false);
        if (!$saved && get_option($option, '') !== $payload) {
            throw new RuntimeException(esc_html__('Could not save Drive transfer progress.', 'uwh-site-migrator'));
        }
        self::register_state_id($id, false);
    }

    private static function read_state($id) {
        $option = self::state_option_name($id, false);
        $payload = get_option($option, '');
        if (!is_string($payload) || '' === $payload) {
            self::unregister_state_id($id, false);
            throw new RuntimeException(esc_html__('Drive transfer progress is missing or damaged.', 'uwh-site-migrator'));
        }
        self::register_state_id($id, false);
        return self::decode_state($payload, $id, self::key(), self::aad($id), 'drive_upload');
    }

    private static function write_import_state(array $state) {
        $id = (string) ($state['import_id'] ?? '');
        $option = self::state_option_name($id, true);
        $payload = self::encode_state($state, $id, self::import_key(), self::import_aad($id));
        $saved = update_option($option, $payload, false);
        if (!$saved && get_option($option, '') !== $payload) {
            throw new RuntimeException(esc_html__('Could not save Drive import progress.', 'uwh-site-migrator'));
        }
        self::register_state_id($id, true);
    }

    private static function read_import_state($id) {
        $option = self::state_option_name($id, true);
        $payload = get_option($option, '');
        if (!is_string($payload) || '' === $payload) {
            self::unregister_state_id($id, true);
            throw new RuntimeException(esc_html__('Drive import progress is missing or damaged.', 'uwh-site-migrator'));
        }
        self::register_state_id($id, true);
        return self::decode_state($payload, $id, self::import_key(), self::import_aad($id), 'drive_import');
    }

    private static function idempotency_key($site_id, $name, $size) {
        return hash('sha256', $site_id . '|' . $name . '|' . (string) (int) $size);
    }

    private static function now() {
        return (defined('UWHSM_DRIVE_TEST') && UWHSM_DRIVE_TEST && is_callable(self::$test_clock))?(int) call_user_func(self::$test_clock):time();
    }

    public static function set_test_transport($transport) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test seam is disabled.', 'uwh-site-migrator'));
        }
        self::$test_transport = $transport;
    }

    public static function set_test_clock($clock) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test seam is disabled.', 'uwh-site-migrator'));
        }
        self::$test_clock = $clock;
    }

    public static function queue_backup($backup_name, $delete_local_after_upload = null) {
        self::ensure_directory();
        $record = UWHSM_Engine::instance()->completed_backup($backup_name);
        $site_id = UWHSM_Drive::site_identifier();
        $auto_cleanup = null === $delete_local_after_upload ? !empty($record['marker']['automatic']) : (bool) $delete_local_after_upload;
        $key = self::idempotency_key($site_id, $record['name'], $record['size']);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $queue = @fopen(self::directory() . '/queue.lock', 'c');
        if (!$queue) {
            throw new RuntimeException(esc_html__('Could not lock the Drive queue.', 'uwh-site-migrator'));
        }
        if (!@flock($queue, LOCK_EX|LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($queue);
            throw new RuntimeException(esc_html__('Drive queue is busy. Try again shortly.', 'uwh-site-migrator'));
        }
        try {
            foreach (self::state_ids(false) as $state_id) {
                try {
                    $state = self::read_state($state_id);
                } catch (Throwable $ignored) {
                    continue;
                }
                if (hash_equals((string) ($state['idempotency_key'] ?? ''), $key) && in_array($state['status'] ?? '', array('queued', 'uploading', 'waiting', 'complete'), true)) {
                    if ($auto_cleanup && empty($state['delete_local_after_upload'])) {
                        $state['delete_local_after_upload'] = true;
                        $state['updated_at'] = self::now();
                        self::write_state($state);
                    }
                    if (($state['status'] ?? '') === 'complete' && !self::remote_complete_exists($state)) {
                        $state['status'] = 'failed';
                        $state['last_error'] = __('The previously uploaded Drive file was removed. A new transfer has been queued from the retained local backup.', 'uwh-site-migrator');
                        $state['updated_at'] = self::now();
                        self::write_state($state);
                        continue;
                    }
                    if (($state['status'] ?? '') !== 'complete' && empty($state['auto_paused']) && empty($state['reconnect_required'])) {
                        self::schedule($state['transfer_id'], self::now()+10);
                    }
                    UWHSM_Logger::log_once('drive-queued-' . $state['transfer_id'], 'drive', 'started', __('Google Drive upload queued.', 'uwh-site-migrator'), array('transfer_id' => $state['transfer_id'], 'backup' => (string) ($state['backup_name'] ?? ''), 'size' => (int) ($state['expected_size'] ?? 0)), 7*DAY_IN_SECONDS);
                    return self::safe_status($state);
                }
            }
            $id = bin2hex(random_bytes(16));
            $now = self::now();
            $state = array(
                'type' => 'drive_upload', 'transfer_id' => $id, 'idempotency_key' => $key, 'site_id' => $site_id,
                'backup_name' => $record['name'], 'archive_path' => $record['path'], 'expected_size' => $record['size'], 'expected_mtime' => $record['mtime'], 'backup_created' => (int) ($record['marker']['created'] ?? $record['mtime']),
                'delete_local_after_upload' => $auto_cleanup, 'local_cleanup_done' => false, 'local_cleanup_error' => '', 'local_cleanup_attempts' => 0,
                'remote_retention_done' => !$auto_cleanup, 'remote_retention_deleted' => 0, 'remote_retention_error' => '', 'remote_retention_checked_at' => 0,
                'status' => 'queued', 'confirmed_offset' => 0, 'attempts' => 0, 'worker_failures' => 0, 'next_retry_at' => 0,
                'session_url' => '', 'drive_file_id' => '', 'created_at' => $now, 'updated_at' => $now, 'last_activity' => 0, 'last_error' => '',
            );
            self::write_state($state);
            self::schedule($id, $now+10);
            UWHSM_Logger::log('drive', 'started', __('Google Drive upload queued.', 'uwh-site-migrator'), array('transfer_id' => $id, 'backup' => $record['name'], 'size' => (int) $record['size']));
            return self::safe_status($state);
        } finally {
            @flock($queue, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($queue);
        }
    }

    private static function verify_archive(array &$state) {
        if (in_array($state['status'], array('complete', 'failed'), true)) {
            return;
        }
        try {
            $record = UWHSM_Engine::instance()->completed_backup($state['backup_name']);
        } catch (Throwable $error) {
            $state['status'] = 'failed';
            $state['last_error'] = __('The completed local backup is missing. Restore the local archive before retrying.', 'uwh-site-migrator');
            $state['updated_at'] = self::now();
            self::write_state($state);
            return;
        }
        if ((int) $record['size'] !== (int) $state['expected_size'] || (int) $record['mtime'] !== (int) $state['expected_mtime'] || !hash_equals((string) $record['path'], (string) $state['archive_path'])) {
            $state['status'] = 'failed';
            $state['last_error'] = __('The local backup changed after this Drive transfer was queued. Queue the current completed backup again.', 'uwh-site-migrator');
            $state['updated_at'] = self::now();
            self::write_state($state);
        }
    }

    public static function status($transfer_id) {
        $state = self::read_state($transfer_id);
        if (($state['status'] ?? '') === 'complete') {
            $changed = false;
            if (self::cleanup_local_after_complete($state)) {
                $changed = true;
            }
            if (self::enforce_remote_auto_retention($state)) {
                $changed = true;
            }
            if ($changed) {
                self::write_state($state);
            }
            return self::safe_status($state);
        }
        if (($state['status'] ?? '') === 'failed' && ($state['last_error'] ?? '') === 'Google Drive returned an unsafe upload URL.' && empty($state['legacy_header_retry_done'])) {
            $state['legacy_header_retry_done'] = true;
            $state['status'] = 'queued';
            $state['session_url'] = '';
            $state['confirmed_offset'] = 0;
            $state['attempts'] = 0;
            $state['worker_failures'] = 0;
            $state['next_retry_at'] = 0;
            $state['last_error'] = '';
            $state['auto_paused'] = false;
            $state['reconnect_required'] = false;
            $state['updated_at'] = self::now();
            self::verify_archive($state);
            self::write_state($state);
            if (($state['status'] ?? '') === 'queued') {
                self::schedule($state['transfer_id'], self::now()+10);
            }
        } else {
            self::verify_archive($state);
        }
        return self::safe_status($state);
    }

    public static function status_for_backup($backup_name) {
        if (!is_string($backup_name) || !preg_match('/^[A-Za-z0-9_.-]+\.uwh$/Di', $backup_name)) {
            return false;
        }
        $found = false;
        $updated = -1;
        foreach (self::state_ids(false) as $state_id) {
            try {
                $state = self::read_state($state_id);
            } catch (Throwable $ignored) {
                continue;
            }
            if (($state['backup_name'] ?? '') === $backup_name && (int) ($state['updated_at'] ?? 0) > $updated) {
                $found = $state;
                $updated = (int) ($state['updated_at'] ?? 0);
            }
        }
        return $found?self::safe_status($found):false;
    }

    public static function active_transfer() {
        $found = false;
        $updated = -1;
        foreach (self::state_ids(false) as $state_id) {
            try {
                $state = self::read_state($state_id);
            } catch (Throwable $ignored) {
                continue;
            }
            if (in_array($state['status'] ?? '', array('queued', 'uploading', 'waiting'), true) && (int) ($state['updated_at'] ?? 0) > $updated) {
                $found = $state;
                $updated = (int) ($state['updated_at'] ?? 0);
            }
        }
        return $found?self::safe_status($found):false;
    }

    private static function should_delete_local(array $state) {
        return !empty($state['delete_local_after_upload'])
        && ($state['status'] ?? '') === 'complete'
        && self::valid_google_id($state['drive_file_id'] ?? '')
        && empty($state['local_cleanup_done']);
    }

    public static function test_should_delete_local(array $state) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::should_delete_local($state);
    }

    private static function cleanup_local_after_complete(array &$state) {
        if (!self::should_delete_local($state)) {
            return false;
        }
        $state['local_cleanup_attempts'] = (int) ($state['local_cleanup_attempts'] ?? 0)+1;
        try {
            UWHSM_Engine::instance()->delete_auto_backup_after_drive(
                (string) ($state['backup_name'] ?? ''),
                (int) ($state['expected_size'] ?? 0),
                (int) ($state['expected_mtime'] ?? 0)
            );
            $state['local_cleanup_done'] = true;
            $state['local_cleanup_error'] = '';
            $state['archive_path'] = '';
            $state['updated_at'] = self::now();
        } catch (Throwable $error) {
            $state['local_cleanup_error'] = substr($error->getMessage(), 0, 300);
            $state['updated_at'] = self::now();
        }
        return true;
    }

    private static function failed_auto_retention_needed(array $state) {
        return !empty($state['delete_local_after_upload'])
        && empty($state['local_cleanup_done'])
        && (($state['status'] ?? '') === 'failed' || !empty($state['auto_paused']) || !empty($state['reconnect_required']));
    }

    private static function protected_active_auto_backups() {
        $protected = array();
        foreach (self::state_ids(false) as $state_id) {
            try {
                $other = self::read_state($state_id);
            } catch (Throwable $ignored) {
                continue;
            }
            if (empty($other['delete_local_after_upload'])) {
                continue;
            }
            $status = (string) ($other['status'] ?? '');
            if (in_array($status, array('queued', 'uploading', 'waiting'), true) && empty($other['auto_paused']) && empty($other['reconnect_required'])) {
                $name = (string) ($other['backup_name'] ?? '');
                if ($name !== '') {
                    $protected[] = $name;
                }
            }
        }
        return array_values(array_unique($protected));
    }

    private static function enforce_failed_auto_retention(array &$state) {
        if (!self::failed_auto_retention_needed($state)) {
            return false;
        }
        $last_check = (int) ($state['local_retention_checked_at'] ?? 0);
        if ($last_check > 0 && self::now()-$last_check < 30) {
            return false;
        }
        try {
            $result = UWHSM_Engine::instance()->retain_latest_failed_auto_backups(2, self::protected_active_auto_backups());
            $state['local_retention_kept'] = (int) ($result['kept'] ?? 0);
            $state['local_retention_deleted'] = (int) ($result['deleted'] ?? 0);
            $state['local_retention_error'] = empty($result['errors'])?'':substr(implode('; ', (array) $result['errors']), 0, 300);
        } catch (Throwable $error) {
            $state['local_retention_error'] = substr($error->getMessage(), 0, 300);
        }
        $state['local_retention_checked_at'] = self::now();
        $state['updated_at'] = self::now();
        return true;
    }

    private static function retention_deletions(array $files, $limit, $source_site) {
        $limit = max(1, min(100, (int) $limit));
        $source_site = (string) $source_site;
        $automatic = array();
        foreach ($files as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $name = (string) ($row['name'] ?? '');
            $properties = is_array($row['appProperties'] ?? null)?$row['appProperties']:array();
            if ($source_site === '' || !hash_equals($source_site, (string) ($properties['source_site'] ?? ''))) {
                continue;
            }
            $automatic_flag = array_key_exists('automatic', $properties)?(string) $properties['automatic']:'';
            $is_automatic = $automatic_flag === '1' || ($automatic_flag === '' && strpos($name, '-auto-') !== false);
            if (!$is_automatic || !self::valid_google_id($id)) {
                continue;
            }
            $created = (int) ($row['created'] ?? 0);
            if ($created <= 0 && is_string($row['createdTime'] ?? null)) {
                $parsed = strtotime($row['createdTime']);
                $created = false === $parsed?0:(int) $parsed;
            }
            if ($created <= 0) {
                continue;
            }
            $automatic[] = array('id' => $id, 'created' => $created, 'name' => $name);
        }
        usort($automatic, static function($a, $b) {
                if ($a['created'] === $b['created']) {
                    return strcmp($b['id'], $a['id']);
                }
                return $b['created'] <=> $a['created'];
            });
        $delete = array();
        for ($i = $limit, $count = count($automatic);$i < $count;$i++) {
            $delete[] = $automatic[$i]['id'];
        }
        return $delete;
    }

    public static function test_retention_deletions(array $files, $limit, $source_site) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::retention_deletions($files, $limit, $source_site);
    }

    private static function remote_auto_retention_needed(array $state) {
        return !empty($state['delete_local_after_upload'])
        && ($state['status'] ?? '') === 'complete'
        && self::valid_google_id($state['drive_file_id'] ?? '')
        && empty($state['remote_retention_done']);
    }

    private static function load_remote_retention_files($token, $source_site) {
        $files = array();
        $page_token = '';
        $pages = 0;
        do {
            $query = "trashed = false and appProperties has { key='site_vault_product' and value='site-vault-migrator' }";
            $params = array('q' => $query, 'pageSize' => 100, 'orderBy' => 'createdTime desc', 'spaces' => 'drive', 'fields' => 'nextPageToken,files(id,name,createdTime,appProperties,trashed)');
            if ($page_token !== '') {
                $params['pageToken'] = $page_token;
            }
            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $token);
            if (in_array($response['status'], array(401, 403), true)) {
                throw new RuntimeException(esc_html__('Reconnect Google Drive so automatic retention can finish.', 'uwh-site-migrator'), 401);
            }
            if ($response['status'] !== 200) {
                throw new RuntimeException(esc_html__('Google Drive retention list is temporarily unavailable.', 'uwh-site-migrator'));
            }
            $data = json_decode($response['body'], true);
            if (!is_array($data) || !is_array($data['files'] ?? array())) {
                throw new RuntimeException(esc_html__('Google Drive returned an invalid retention list.', 'uwh-site-migrator'));
            }
            foreach ($data['files'] as $row) {
                if (!is_array($row) || !empty($row['trashed'])) {
                    continue;
                }
                $properties = is_array($row['appProperties'] ?? null)?$row['appProperties']:array();
                if (($properties['site_vault_product'] ?? '') !== 'site-vault-migrator' || !hash_equals((string) $source_site, (string) ($properties['source_site'] ?? ''))) {
                    continue;
                }
                $files[] = $row;
            }
            $page_token = is_string($data['nextPageToken'] ?? null)?$data['nextPageToken']:'';
            $pages++;
        } while ($page_token !== '' && $pages < 50);
        if ($page_token !== '') {
            throw new RuntimeException(esc_html__('Google Drive has too many backup pages to safely apply retention in one run.', 'uwh-site-migrator'));
        }
        return $files;
    }

    private static function enforce_remote_auto_retention(array &$state) {
        if (!self::remote_auto_retention_needed($state)) {
            return false;
        }
        $last_check = (int) ($state['remote_retention_checked_at'] ?? 0);
        if ($last_check > 0 && self::now()-$last_check < 30) {
            return false;
        }
        $state['remote_retention_checked_at'] = self::now();
        $state['updated_at'] = self::now();
        try {
            $limit = max(1, min(100, (int) get_option('uwhsm_auto_drive_retention', 7)));
            $site_id = (string) ($state['site_id'] ?? '');
            if ($site_id === '') {
                throw new RuntimeException(__('Automatic Drive retention could not identify this site.', 'uwh-site-migrator'));
            }
            $source_site = hash('sha256', $site_id);
            $context = UWHSM_Drive::access_token();
            $files = self::load_remote_retention_files($context['access_token'], $source_site);
            $delete_ids = self::retention_deletions($files, $limit, $source_site);
            $deleted = 0;
            foreach ($delete_ids as $file_id) {
                $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id);
                $response = self::authorized_request('DELETE', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $context['access_token']);
                if (!in_array($response['status'], array(200, 204, 404), true)) {
                    throw new RuntimeException(__('Google Drive could not delete an old automatic backup. Retention will retry.', 'uwh-site-migrator'));
                }
                $deleted++;
            }
            $state['remote_retention_deleted'] = $deleted;
            $state['remote_retention_error'] = '';
            $state['remote_retention_done'] = true;
        } catch (Throwable $error) {
            $state['remote_retention_error'] = substr($error->getMessage(), 0, 300);
            $state['remote_retention_done'] = false;
        }
        $state['updated_at'] = self::now();
        return true;
    }

    private static function safe_status(array $state) {
        $total = max(0, (int) ($state['expected_size'] ?? 0));
        $sent = max(0, min($total, (int) ($state['confirmed_offset'] ?? 0)));
        $status = (string) ($state['status'] ?? 'failed');
        if ($status === 'complete') {
            UWHSM_Logger::log_once('drive-complete-' . (string) ($state['transfer_id'] ?? ''), 'drive', 'success', __('Google Drive upload completed.', 'uwh-site-migrator'), array('transfer_id' => (string) ($state['transfer_id'] ?? ''), 'backup' => (string) ($state['backup_name'] ?? ''), 'size' => $total), 7*DAY_IN_SECONDS);
        } elseif ($status === 'failed') {
            UWHSM_Logger::log_once('drive-failed-' . (string) ($state['transfer_id'] ?? '') . '-' . md5((string) ($state['last_error'] ?? '')), 'drive', 'failed', __('Google Drive upload stopped.', 'uwh-site-migrator'), array('transfer_id' => (string) ($state['transfer_id'] ?? ''), 'backup' => (string) ($state['backup_name'] ?? ''), 'error' => (string) ($state['last_error'] ?? '')), 3600);
        }
        $labels = array('queued' => __('Queued for Google Drive', 'uwh-site-migrator'), 'uploading' => __('Uploading to Google Drive', 'uwh-site-migrator'), 'waiting' => __('Waiting to retry Google Drive', 'uwh-site-migrator'), 'complete' => __('Google Drive upload complete', 'uwh-site-migrator'), 'failed' => __('Google Drive upload stopped', 'uwh-site-migrator'));
        return array(
            'transfer_id' => (string) $state['transfer_id'], 'backup_name' => (string) ($state['backup_name'] ?? ''), 'status' => $status,
            'done' => $status === 'complete', 'bytes_sent' => $sent, 'bytes_total' => $total, 'progress' => $total > 0?(int) floor(($sent/$total)*100):0,
            'label' => $labels[$status] ?? __('Google Drive transfer', 'uwh-site-migrator'), 'retry_after' => max(0, (int) ($state['next_retry_at'] ?? 0)-self::now()),
            'error' => (string) ($state['last_error'] ?? ''), 'drive_file_id' => $status === 'complete'?(string) ($state['drive_file_id'] ?? ''):'',
            'last_activity' => (int) ($state['last_activity'] ?? 0), 'auto_paused' => !empty($state['auto_paused']), 'reconnect_required' => !empty($state['reconnect_required']),
            'delete_local_after_upload' => !empty($state['delete_local_after_upload']), 'local_cleanup_done' => !empty($state['local_cleanup_done']), 'local_cleanup_error' => (string) ($state['local_cleanup_error'] ?? ''), 'local_cleanup_attempts' => (int) ($state['local_cleanup_attempts'] ?? 0),
            'local_retention_kept' => (int) ($state['local_retention_kept'] ?? 0), 'local_retention_deleted' => (int) ($state['local_retention_deleted'] ?? 0), 'local_retention_error' => (string) ($state['local_retention_error'] ?? ''),
            'remote_retention_done' => !empty($state['remote_retention_done']), 'remote_retention_pending' => self::remote_auto_retention_needed($state), 'remote_retention_deleted' => (int) ($state['remote_retention_deleted'] ?? 0), 'remote_retention_error' => (string) ($state['remote_retention_error'] ?? ''),
        );
    }

    public static function test_state($transfer_id) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::read_state($transfer_id);
    }

    private static function range_for($offset, $total) {
        $offset = (int) $offset;
        $total = (int) $total;
        if ($offset < 0 || $total <= 0 || $offset >= $total) {
            throw new RuntimeException(esc_html__('Invalid Drive upload range.', 'uwh-site-migrator'));
        }
        $end = min($total-1, $offset+self::CHUNK_SIZE-1);
        $length = $end-$offset+1;
        return array('start' => $offset, 'end' => $end, 'length' => $length, 'header' => 'bytes ' . $offset . '-' . $end . '/' . $total);
    }

    public static function test_range($offset, $total) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::range_for($offset, $total);
    }

    private static function validate_google_url($url, $session = false) {
        if (!is_string($url) || strlen($url) > 4096) {
            throw new RuntimeException(esc_html__('Google Drive returned an unsafe upload URL.', 'uwh-site-migrator'));
        }
        $parts = wp_parse_url($url);if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'www.googleapis.com' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new RuntimeException(esc_html__('Google Drive returned an unsafe upload URL.', 'uwh-site-migrator'));
        }
        $path = (string) ($parts['path'] ?? '');
        if ($session) {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if ($path !== '/upload/drive/v3/files' || empty($query['upload_id'])) {
                throw new RuntimeException(esc_html__('Google Drive returned an unsafe resumable upload URL.', 'uwh-site-migrator'));
            }
        } elseif (strpos($path, '/drive/v3/') !== 0 && $path !== '/upload/drive/v3/files') {
            throw new RuntimeException(esc_html__('Unexpected Google Drive API URL.', 'uwh-site-migrator'));
        }
        return true;
    }

    public static function test_validate_session_url($url) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::validate_google_url($url, true);
    }

    private static function normalize_headers($headers) {
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            $headers = $headers->getAll();
        } elseif ($headers instanceof Traversable) {
            $headers = iterator_to_array($headers);
        } elseif (!is_array($headers)) {
            $headers = (array) $headers;
        }
        $out = array();
        foreach ((array) $headers as $name => $value) {
            $out[strtolower((string) $name)] = is_array($value)?implode(',', $value):(string) $value;
        }
        return $out;
    }

    private static function request($method, $url, array $args) {
        self::validate_google_url($url, false);
        $args['method'] = $method;
        $args['timeout'] = $args['timeout'] ?? 28;
        $args['redirection'] = 0;
        $args['sslverify'] = true;
        $args['reject_unsafe_urls'] = true;
        $args['limit_response_size'] = $args['limit_response_size'] ?? 65536;
        if (defined('UWHSM_DRIVE_TEST') && UWHSM_DRIVE_TEST && is_callable(self::$test_transport)) {
            try {
                $response = call_user_func(self::$test_transport, $method, $url, $args);
            } catch (Throwable $error) {
                return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'network');
            }
            return array('status' => (int) ($response['status'] ?? 0), 'headers' => self::normalize_headers($response['headers'] ?? array()), 'body' => (string) ($response['body'] ?? ''), 'error' => (string) ($response['error'] ?? ''));
        }
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            return array('status' => 0, 'headers' => array(), 'body' => '', 'error' => 'network');
        }
        return array('status' => (int) wp_remote_retrieve_response_code($response), 'headers' => self::normalize_headers(wp_remote_retrieve_headers($response)), 'body' => (string) wp_remote_retrieve_body($response), 'error' => '');
    }

    private static function authorized_request($method, $url, array $args, $token, $retry_unauthorized = true) {
        $args['headers'] = $args['headers'] ?? array();
        $args['headers']['Authorization'] = 'Bearer ' . $token;
        $response = self::request($method, $url, $args);
        if ($response['status'] === 401 && $retry_unauthorized) {
            try {
                $context = UWHSM_Drive::access_token();
            } catch (Throwable $error) {
                return array('status' => 401, 'headers' => array(), 'body' => '', 'error' => 'authorization');
            }
            $args['headers']['Authorization'] = 'Bearer ' . $context['access_token'];
            return self::request($method, $url, $args);
        }
        return $response;
    }

    private static function valid_google_id($id) {
        return is_string($id) && (bool) preg_match('/^[A-Za-z0-9_-]{3,200}$/D', $id);
    }

    private static function remote_complete_exists(array $state) {
        $file_id = $state['drive_file_id'] ?? '';
        if (!self::valid_google_id($file_id)) {
            return false;
        }
        try {
            $context = UWHSM_Drive::access_token();
        } catch (Throwable $error) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive before checking this completed upload.', 'uwh-site-migrator'), 401);
        }
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?' . http_build_query(array('fields' => 'id,size,trashed,appProperties'), '', '&', PHP_QUERY_RFC3986);
        $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $context['access_token']);
        if ($response['status'] === 404) {
            return false;
        }
        if (in_array($response['status'], array(401, 403), true)) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive before checking this completed upload.', 'uwh-site-migrator'), 401);
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(esc_html__('Google Drive could not verify the previous upload. No duplicate transfer was started. Try again shortly.', 'uwh-site-migrator'));
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !self::valid_google_id($data['id'] ?? '')) {
            throw new RuntimeException(esc_html__('Google Drive returned an invalid file check. No duplicate transfer was started.', 'uwh-site-migrator'));
        }
        $properties = is_array($data['appProperties'] ?? null)?$data['appProperties']:array();
        return empty($data['trashed']) && hash_equals((string) $file_id, (string) $data['id']) && (int) ($data['size'] ?? -1) === (int) $state['expected_size'] && hash_equals((string) $state['idempotency_key'], (string) ($properties['idempotency_key'] ?? ''));
    }

    private static function ensure_folder(array &$state, $token) {
        if (self::valid_google_id($state['folder_id'] ?? '')) {
            return $state['folder_id'];
        }
        $query = "mimeType = 'application/vnd.google-apps.folder' and trashed = false and appProperties has { key='site_vault_product' and value='site-vault-migrator' }";
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array('q' => $query, 'pageSize' => 10, 'spaces' => 'drive', 'fields' => 'files(id,name,trashed,appProperties)'), '', '&', PHP_QUERY_RFC3986);
        $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $token);
        if (in_array($response['status'], array(401, 403), true)) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive before starting this upload.', 'uwh-site-migrator'), 401);
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(esc_html__('Google Drive folder lookup failed. Try again.', 'uwh-site-migrator'));
        }
        $data = json_decode($response['body'], true);
        $folder = '';
        foreach ((array) ($data['files'] ?? array()) as $row) {
            $properties = is_array($row['appProperties'] ?? null)?$row['appProperties']:array();
            if (empty($row['trashed']) && in_array(($row['name'] ?? ''), array('Site Vault Backups', 'Site Migrator Backups'), true) && ($properties['site_vault_product'] ?? '') === 'site-vault-migrator' && self::valid_google_id($row['id'] ?? '')) {
                $folder = $row['id'];
                break;
            }
        }
        if (!$folder) {
            $body = wp_json_encode(array('name' => 'Site Migrator Backups', 'mimeType' => 'application/vnd.google-apps.folder', 'appProperties' => array('site_vault_product' => 'site-vault-migrator')));
            $response = self::authorized_request('POST', 'https://www.googleapis.com/drive/v3/files?fields=id', array('headers' => array('Content-Type' => 'application/json; charset=UTF-8', 'Accept' => 'application/json'), 'body' => $body), $token);
            if (in_array($response['status'], array(401, 403), true)) {
                throw new RuntimeException(esc_html__('Reconnect Google Drive before starting this upload.', 'uwh-site-migrator'), 401);
            }
            $created = json_decode($response['body'], true);
            $folder = $created['id'] ?? '';
            if (!in_array($response['status'], array(200, 201), true) || !self::valid_google_id($folder)) {
                throw new RuntimeException(esc_html__('Google Drive backup folder could not be created.', 'uwh-site-migrator'));
            }
        }
        $state['folder_id'] = $folder;
        return $folder;
    }

    private static function initiate_session(array &$state, $token) {
        $folder = self::ensure_folder($state, $token);
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $metadata = array(
            'name' => $state['backup_name'], 'mimeType' => 'application/zip', 'parents' => array($folder),
            'appProperties' => array(
            'site_vault_product' => 'site-vault-migrator', 'source_host' => substr($host, 0, 200),
            'source_site' => hash('sha256', $state['site_id']), 'format_version' => '1',
            'idempotency_key' => $state['idempotency_key'], 'backup_created' => (string) $state['backup_created'],
            'automatic' => !empty($state['delete_local_after_upload'])?'1':'0',
            ),
        );
        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=false';
        $response = self::authorized_request('POST', $url, array('headers' => array(
            'Content-Type' => 'application/json; charset=UTF-8', 'Accept' => 'application/json', 'X-Upload-Content-Type' => 'application/zip', 'X-Upload-Content-Length' => (string) $state['expected_size'],
            ), 'body' => wp_json_encode($metadata)), $token);
        if (in_array($response['status'], array(401, 403), true)) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive before starting this upload.', 'uwh-site-migrator'), 401);
        }
        if (!in_array($response['status'], array(200, 201), true)) {
            throw new RuntimeException(esc_html__('Google Drive could not start a resumable upload.', 'uwh-site-migrator'));
        }
        $location = $response['headers']['location'] ?? '';
        self::validate_google_url($location, true);
        $state['session_url'] = $location;
        $state['confirmed_offset'] = 0;
        $state['status'] = 'uploading';
        $state['attempts'] = 0;
        $state['next_retry_at'] = 0;
        $state['last_error'] = '';
        $state['updated_at'] = self::now();
        $state['last_activity'] = self::now();
    }

    private static function confirmed_offset(array $response, $total) {
        $range = $response['headers']['range'] ?? '';
        if ($range === '') {
            return 0;
        }
        if (!preg_match('/^bytes=0-([0-9]+)$/D', $range, $match)) {
            throw new RuntimeException(esc_html__('Google Drive returned an invalid confirmed range.', 'uwh-site-migrator'));
        }
        $offset = (int) $match[1]+1;
        if ($offset < 0 || $offset > (int) $total) {
            throw new RuntimeException(esc_html__('Google Drive confirmed bytes beyond the archive.', 'uwh-site-migrator'));
        }
        return $offset;
    }

    private static function wait_after(array &$state, array $response) {
        $state['attempts'] = (int) ($state['attempts'] ?? 0)+1;
        $base = min(900, 5*(2**min(7, $state['attempts'])));
        $jitter = abs(crc32($state['transfer_id'] . '|' . $state['attempts']))%6;
        $header = (int) ($response['headers']['retry-after'] ?? 0);
        $delay = min(900, max($base+$jitter, $header));
        $state['status'] = 'waiting';
        $state['next_retry_at'] = self::now()+$delay;
        $state['last_error'] = __('Google Drive is temporarily unavailable. Site Migrator will retry from the confirmed offset.', 'uwh-site-migrator');
        $state['updated_at'] = self::now();
    }

    private static function apply_upload_response(array &$state, array $response) {
        $code = (int) $response['status'];
        $total = (int) $state['expected_size'];
        if ($code === 308) {
            $offset = self::confirmed_offset($response, $total);
            if ($offset < (int) $state['confirmed_offset']) {
                throw new RuntimeException(esc_html__('Google Drive confirmed an older upload offset.', 'uwh-site-migrator'));
            }
            $state['confirmed_offset'] = $offset;
            $state['status'] = 'uploading';
            $state['attempts'] = 0;
            $state['next_retry_at'] = 0;
            $state['last_error'] = '';
            $state['updated_at'] = self::now();
            $state['last_activity'] = self::now();
            return;
        }
        if (in_array($code, array(200, 201), true)) {
            $data = json_decode($response['body'], true);
            $id = $data['id'] ?? '';
            $size = isset($data['size'])?(int) $data['size']:$total;
            if (!self::valid_google_id($id) || $size !== $total) {
                throw new RuntimeException(esc_html__('Google Drive completion response did not match the local backup.', 'uwh-site-migrator'));
            }
            $state['drive_file_id'] = $id;
            $state['confirmed_offset'] = $total;
            $state['status'] = 'complete';
            $state['attempts'] = 0;
            $state['next_retry_at'] = 0;
            $state['last_error'] = '';
            $state['updated_at'] = self::now();
            $state['last_activity'] = self::now();
            return;
        }
        if ($code === 404) {
            $state['session_url'] = '';
            $state['confirmed_offset'] = 0;
            $state['status'] = 'queued';
            $state['attempts'] = 0;
            $state['next_retry_at'] = 0;
            $state['last_error'] = __('The previous Google upload session expired; a fresh session will start.', 'uwh-site-migrator');
            $state['updated_at'] = self::now();
            return;
        }
        if ($code === 429 || $code >= 500) {
            self::wait_after($state, $response);
            return;
        }
        if (in_array($code, array(401, 403), true)) {
            $state['status'] = 'waiting';
            $state['next_retry_at'] = 0;
            $state['last_error'] = __('Google Drive authorization expired. Reconnect Google Drive, then resume this transfer.', 'uwh-site-migrator');
            $state['reconnect_required'] = true;
            $state['auto_paused'] = true;
            $state['updated_at'] = self::now();
            return;
        }
        $state['status'] = 'failed';
        $state['last_error'] = __('Google Drive rejected this upload. The local backup is unchanged.', 'uwh-site-migrator');
        $state['updated_at'] = self::now();
    }

    private static function probe_session(array &$state, $token) {
        self::validate_google_url($state['session_url'], true);
        $response = self::authorized_request('PUT', $state['session_url'], array('headers' => array('Content-Length' => '0', 'Content-Range' => 'bytes */' . $state['expected_size']), 'body' => ''), $token);
        self::apply_upload_response($state, $response);
    }

    public static function advance($transfer_id, $manual = true) {
        if (!self::valid_id($transfer_id)) {
            throw new RuntimeException(esc_html__('Invalid Drive transfer ID.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen(self::lock_path($transfer_id), 'c');
        if (!$lock) {
            throw new RuntimeException(esc_html__('Could not lock Drive transfer.', 'uwh-site-migrator'));
        }
        if (!@flock($lock, LOCK_EX|LOCK_NB)) {
            $busy = self::status($transfer_id);
            $busy['busy'] = true;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
            return $busy;
        }
        try {
            $state = self::read_state($transfer_id);
            self::verify_archive($state);
            if (($state['status'] ?? '') === 'complete') {
                $changed = false;
                if (self::cleanup_local_after_complete($state)) {
                    $changed = true;
                }
                if (self::enforce_remote_auto_retention($state)) {
                    $changed = true;
                }
                if ($changed) {
                    self::write_state($state);
                }
                return self::safe_status($state);
            }
            if (($state['status'] ?? '') === 'failed') {
                if (self::enforce_failed_auto_retention($state)) {
                    self::write_state($state);
                }
                return self::safe_status($state);
            }
            if (!$manual && !empty($state['auto_paused'])) {
                if (self::enforce_failed_auto_retention($state)) {
                    self::write_state($state);
                }
                return self::safe_status($state);
            }
            if ($manual && (!empty($state['auto_paused']) || !empty($state['reconnect_required']))) {
                $state['auto_paused'] = false;
                $state['reconnect_required'] = false;
                $state['worker_failures'] = 0;
                $state['next_retry_at'] = 0;
            }
            if ((int) ($state['next_retry_at'] ?? 0) > self::now()) {
                return self::safe_status($state);
            }
            if (($state['status'] ?? '') === 'waiting') {
                $state['status'] = empty($state['session_url'])?'queued':'uploading';
                $state['next_retry_at'] = 0;
            }
            try {
                $context = UWHSM_Drive::access_token();
            } catch (Throwable $token_error) {
                $state['status'] = 'waiting';
                $state['next_retry_at'] = 0;
                $state['reconnect_required'] = true;
                $state['auto_paused'] = true;
                $state['last_error'] = __('Reconnect Google Drive, then resume this transfer. The local backup is unchanged.', 'uwh-site-migrator');
                $state['updated_at'] = self::now();
                self::enforce_failed_auto_retention($state);
                self::write_state($state);
                return self::safe_status($state);
            }
            $token = $context['access_token'];
            $state['reconnect_required'] = false;
            if (($state['status'] ?? '') === 'queued' || empty($state['session_url'])) {
                self::initiate_session($state, $token);
                self::write_state($state);
                return self::safe_status($state);
            }
            self::validate_google_url($state['session_url'], true);
            $range = self::range_for($state['confirmed_offset'], $state['expected_size']);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $fh = @fopen($state['archive_path'], 'rb');
            if (!$fh || fseek($fh, $range['start']) !== 0) {
                if ($fh) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                    fclose($fh);
                }
                throw new RuntimeException(__('Could not read the queued local backup range.', 'uwh-site-migrator'));
            }
            $body = '';
            while (strlen($body) < $range['length'] && !feof($fh)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streaming is required to transfer large backup archives without loading them fully into memory.
                $piece = fread($fh, $range['length']-strlen($body));
                if (false === $piece) {
                    break;
                }
                $body .= $piece;
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($fh);
            if (strlen($body) !== $range['length']) {
                throw new RuntimeException(__('The local backup ended before the queued byte range.', 'uwh-site-migrator'));
            }
            $response = self::authorized_request('PUT', $state['session_url'], array('headers' => array('Content-Type' => 'application/zip', 'Content-Length' => (string) $range['length'], 'Content-Range' => $range['header']), 'body' => $body), $token);
            unset($body);
            if ((int) $response['status'] === 0) {
                self::probe_session($state, $token);
            } else {
                self::apply_upload_response($state, $response);
            }
            // Persist successful Drive completion before touching the local archive. Cleanup is automatic-backup-only and idempotent.
            self::write_state($state);
            if (($state['status'] ?? '') === 'complete') {
                $changed = false;
                if (self::cleanup_local_after_complete($state)) {
                    $changed = true;
                }
                if (self::enforce_remote_auto_retention($state)) {
                    $changed = true;
                }
                if ($changed) {
                    self::write_state($state);
                }
            } elseif (self::enforce_failed_auto_retention($state)) {
                self::write_state($state);
            }
            return self::safe_status($state);
        } catch (Throwable $error) {
            try {
                if (isset($state) && is_array($state)) {
                    if ((int) $error->getCode() === 401) {
                        $state['status'] = 'waiting';
                        $state['next_retry_at'] = 0;
                        $state['reconnect_required'] = true;
                        $state['auto_paused'] = true;
                        $state['last_error'] = __('Reconnect Google Drive, then resume this transfer. The local backup is unchanged.', 'uwh-site-migrator');
                    } else {
                        $state['status'] = 'failed';
                        $state['last_error'] = substr($error->getMessage(), 0, 300);
                    }
                    $state['updated_at'] = self::now();
                    self::enforce_failed_auto_retention($state);
                    self::write_state($state);
                    return self::safe_status($state);
                }
            } catch (Throwable $ignored) {}
            throw $error;
        } finally {
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
        }
    }

    private static function ajax_guard() {
        UWHSM_Compat::verify_ajax_nonce('uwhsm_admin');
        if (!current_user_can('manage_options') || is_multisite()) {
            wp_send_json_error(array('message' => __('Administrator permission on a single-site WordPress installation is required.', 'uwh-site-migrator')), 403);
        }
        try {
            $connected = UWHSM_Drive::status();
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => __('Reconnect Google Drive before using Drive backups.', 'uwh-site-migrator')), 403);
            return;
        }
        if (empty($connected['connected'])) {
            wp_send_json_error(array('message' => __('Connect Google Drive before using Drive backups.', 'uwh-site-migrator')), 403);
        }
    }

    public static function ajax_start() {
        self::ajax_guard();
        try {
            $name = UWHSM_Compat::post_value('backup_name');
            $result = self::queue_backup($name);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_step() {
        self::ajax_guard();
        try {
            $id = UWHSM_Compat::post_value('transfer_id');
            $result = self::advance($id, true);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_status() {
        self::ajax_guard();
        try {
            $id = UWHSM_Compat::post_value('transfer_id');
            $result = self::status($id);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    private static function schedule($transfer_id, $timestamp) {
        if (!self::valid_id($transfer_id) || !function_exists('wp_schedule_single_event')) {
            return;
        }
        $args = array($transfer_id);
        if (!wp_next_scheduled(self::HOOK, $args)) {
            wp_schedule_single_event(max(self::now()+1, (int) $timestamp), self::HOOK, $args);
        }
    }

    private static function record_worker_result($transfer_id) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen(self::lock_path($transfer_id), 'c');
        if (!$lock || !@flock($lock, LOCK_EX|LOCK_NB)) {
            if ($lock) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                @fclose($lock);
            }
            return self::status($transfer_id);
        }
        try {
            $state = self::read_state($transfer_id);
            if (($state['status'] ?? '') === 'waiting' && (int) ($state['next_retry_at'] ?? 0) > self::now() && empty($state['reconnect_required'])) {
                $state['worker_failures'] = (int) ($state['worker_failures'] ?? 0)+1;
                if ($state['worker_failures'] >= 3) {
                    $state['auto_paused'] = true;
                    $state['last_error'] = __('Automatic Drive retries paused after three failures. Use Resume Drive upload when Google or the network is ready.', 'uwh-site-migrator');
                }
            } elseif (in_array($state['status'] ?? '', array('queued', 'uploading', 'complete'), true)) {
                $state['worker_failures'] = 0;
                $state['auto_paused'] = false;
            }
            self::enforce_failed_auto_retention($state);
            self::enforce_remote_auto_retention($state);
            self::write_state($state);
            return self::safe_status($state);
        } finally {
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
        }
    }

    private static function force_auto_cleanup_flag($transfer_id) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen(self::lock_path($transfer_id), 'c');
        if (!$lock || !@flock($lock, LOCK_EX|LOCK_NB)) {
            if ($lock) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                @fclose($lock);
            }
            return;
        }
        try {
            $state = self::read_state($transfer_id);
            if (empty($state['delete_local_after_upload'])) {
                $state['delete_local_after_upload'] = true;
                $state['updated_at'] = self::now();
                self::write_state($state);
            }
        } finally {
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
        }
    }

    public static function background_step($transfer_id, $force_auto_cleanup = false) {
        if (!self::valid_id($transfer_id)) {
            throw new RuntimeException(esc_html__('Invalid Drive transfer ID.', 'uwh-site-migrator'));
        }
        if ($force_auto_cleanup) {
            self::force_auto_cleanup_flag($transfer_id);
        }
        $result = self::advance($transfer_id, false);
        return self::record_worker_result($transfer_id);
    }

    public static function cron_tick($transfer_id) {
        if (!self::valid_id($transfer_id)) {
            return;
        }
        try {
            $result = self::background_step($transfer_id, false);
        } catch (Throwable $error) {
            return;
        }
        $cleanup_pending = !empty($result['done']) && !empty($result['delete_local_after_upload']) && empty($result['local_cleanup_done']);
        $remote_retention_pending = !empty($result['done']) && !empty($result['delete_local_after_upload']) && !empty($result['remote_retention_pending']);
        if ((!empty($result['done']) && !$cleanup_pending && !$remote_retention_pending) || $result['status'] === 'failed' || !empty($result['auto_paused']) || !empty($result['reconnect_required'])) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::HOOK, array($transfer_id));
            }
            return;
        }
        $delay = $cleanup_pending?60:($remote_retention_pending?300:max(10, (int) ($result['retry_after'] ?? 0)));
        self::schedule($transfer_id, self::now()+$delay);
    }

    private static function cursor_key() {
        return hash('sha256', 'site-vault-drive-cursor-1|' . wp_salt('auth'), true);
    }

    private static function base64url_encode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64url_decode($value) {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            return false;
        }
        $padding = (4-strlen($value)%4)%4;
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    }

    private static function sign_cursor($page_token, $site_id) {
        if (!is_string($page_token) || $page_token === '' || strlen($page_token) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $page_token)) {
            throw new RuntimeException(esc_html__('Google Drive returned an invalid page cursor.', 'uwh-site-migrator'));
        }
        $payload = self::base64url_encode(wp_json_encode(array('token' => $page_token, 'site' => $site_id, 'issued' => self::now())));
        return $payload . '.' . self::base64url_encode(hash_hmac('sha256', $payload, self::cursor_key(), true));
    }

    private static function open_cursor($cursor, $site_id) {
        if ($cursor === '') {
            return '';
        }
        if (!is_string($cursor) || strlen($cursor) > 4096 || substr_count($cursor, '.') !== 1) {
            throw new RuntimeException(esc_html__('Drive inventory cursor is invalid or expired. Refresh the list.', 'uwh-site-migrator'));
        }
        list($payload, $signature) = explode('.', $cursor, 2);
        $given = self::base64url_decode($signature);
        $json = self::base64url_decode($payload);
        if (false === $given || false === $json || !hash_equals(hash_hmac('sha256', $payload, self::cursor_key(), true), $given)) {
            throw new RuntimeException(esc_html__('Drive inventory cursor is invalid or expired. Refresh the list.', 'uwh-site-migrator'));
        }
        $data = json_decode($json, true);
        $issued = (int) ($data['issued'] ?? 0);
        $token = $data['token'] ?? '';
        if (!is_array($data) || !isset($data['site']) || !hash_equals($site_id, (string) $data['site']) || $issued > self::now()+60 || $issued+600 < self::now() || !is_string($token) || $token === '' || strlen($token) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $token)) {
            throw new RuntimeException(esc_html__('Drive inventory cursor is invalid or expired. Refresh the list.', 'uwh-site-migrator'));
        }
        return $token;
    }

    private static function display_text($value, $limit) {
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', wp_strip_all_tags((string) $value)));
        return substr($value, 0, $limit);
    }

    private static function inventory_row($row) {
        if (!is_array($row) || !empty($row['trashed']) || !self::valid_google_id($row['id'] ?? '')) {
            return false;
        }
        $properties = is_array($row['appProperties'] ?? null)?$row['appProperties']:array();
        if (($properties['site_vault_product'] ?? '') !== 'site-vault-migrator') {
            return false;
        }
        $name = self::display_text($row['name'] ?? '', 255);
        if (!preg_match('/^[A-Za-z0-9_.-]+\.uwh$/Di', $name)) {
            return false;
        }
        $size = $row['size'] ?? '';
        if (!is_string($size) && !is_int($size)) {
            return false;
        }
        $size = (string) $size;
        if (!preg_match('/^[0-9]{1,20}$/D', $size)) {
            return false;
        }
        $size_int = (int) $size;
        if ($size_int < 0) {
            return false;
        }
        $created = is_string($row['createdTime'] ?? null)?strtotime($row['createdTime']):false;
        if (false === $created || $created <= 0) {
            return false;
        }
        $domain = strtolower(self::display_text($properties['source_host'] ?? '', 253));
        if ($domain !== '' && !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $domain = '';
        }
        return array('file_id' => $row['id'], 'name' => $name, 'source_domain' => $domain, 'created' => (int) $created, 'size' => $size_int);
    }

    public static function list_files($signed_cursor = '') {
        try {
            $site_id = UWHSM_Drive::site_identifier();
        } catch (Throwable $error) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive to load Site Migrator backups.', 'uwh-site-migrator'));
        }
        $page_token = self::open_cursor($signed_cursor, $site_id);
        try {
            $context = UWHSM_Drive::access_token();
        } catch (Throwable $error) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive to load Site Migrator backups.', 'uwh-site-migrator'));
        }
        $query = array(
            'q' => "trashed = false and appProperties has { key='site_vault_product' and value='site-vault-migrator' }",
            'pageSize' => 100, 'orderBy' => 'createdTime desc', 'spaces' => 'drive',
            'fields' => 'nextPageToken,files(id,name,size,createdTime,appProperties,trashed,mimeType)',
        );
        if ($page_token !== '') {
            $query['pageToken'] = $page_token;
        }
        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $context['access_token']);
        if (in_array($response['status'], array(401, 403), true)) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive to load Site Migrator backups.', 'uwh-site-migrator'));
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(esc_html__('Google Drive backup list is temporarily unavailable. Refresh and try again.', 'uwh-site-migrator'));
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_array($data['files'] ?? array())) {
            throw new RuntimeException(esc_html__('Google Drive returned an invalid backup list.', 'uwh-site-migrator'));
        }
        $files = array();
        foreach ($data['files'] as $row) {
            $safe = self::inventory_row($row);
            if ($safe) {
                $files[] = $safe;
            }
        }
        $next = $data['nextPageToken'] ?? '';
        $cursor = $next !== ''?self::sign_cursor($next, $site_id):'';
        return array('files' => $files, 'next_cursor' => $cursor, 'page_count' => count($files));
    }

    public static function ajax_list() {
        self::ajax_guard();
        $cursor = UWHSM_Compat::post_value('cursor');
        try {
            $result = self::list_files($cursor);
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_delete() {
        self::ajax_guard();
        $file_id = UWHSM_Compat::post_value('file_id');
        if (!$file_id) {
            wp_send_json_error(array('message' => __('Invalid Google Drive file ID.', 'uwh-site-migrator')));
        }
        try {
            $context = UWHSM_Drive::access_token();
            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id);
            $response = self::authorized_request('DELETE', $url, array(), $context['access_token']);
            if (!in_array($response['status'], array(200, 204, 404), true)) {
                throw new RuntimeException(__('Failed to delete file from Google Drive.', 'uwh-site-migrator'));
            }
        } catch (Throwable $e) {
            UWHSM_Logger::log('drive', 'failed', __('Google Drive backup could not be deleted.', 'uwh-site-migrator'), array('error' => $e->getMessage()));
            wp_send_json_error(array('message' => $e->getMessage()), 400);
            return;
        }
        UWHSM_Logger::log('drive', 'info', __('Google Drive backup deleted.', 'uwh-site-migrator'));
        wp_send_json_success(array('message' => __('Deleted successfully from Google Drive.', 'uwh-site-migrator')));
    }

    private static function import_range($offset, $total) {
        $offset = (int) $offset;
        $total = (int) $total;
        if ($offset < 0 || $total <= 0 || $offset >= $total) {
            throw new RuntimeException(esc_html__('Invalid Drive import range.', 'uwh-site-migrator'));
        }
        $end = min($total-1, $offset+self::CHUNK_SIZE-1);
        $length = $end-$offset+1;
        return array('start' => $offset, 'end' => $end, 'length' => $length, 'header' => 'bytes=' . $offset . '-' . $end);
    }

    public static function test_import_range($offset, $total) {
        if (!defined('UWHSM_DRIVE_TEST') || !UWHSM_DRIVE_TEST) {
            throw new RuntimeException(esc_html__('Transfer test access is disabled.', 'uwh-site-migrator'));
        }
        return self::import_range($offset, $total);
    }

    private static function safe_import_status(array $state) {
        $total = max(0, (int) ($state['expected_size'] ?? 0));
        $received = max(0, min($total, (int) ($state['confirmed_offset'] ?? 0)));
        $status = (string) ($state['status'] ?? 'failed');
        if ($status === 'complete') {
            UWHSM_Logger::log_once('drive-import-complete-' . (string) ($state['import_id'] ?? ''), 'drive', 'success', __('Google Drive backup imported to local storage.', 'uwh-site-migrator'), array('import_id' => (string) ($state['import_id'] ?? ''), 'backup' => (string) ($state['name'] ?? ''), 'size' => $total), 7*DAY_IN_SECONDS);
        } elseif ($status === 'failed') {
            UWHSM_Logger::log_once('drive-import-failed-' . (string) ($state['import_id'] ?? '') . '-' . md5((string) ($state['last_error'] ?? '')), 'drive', 'failed', __('Google Drive import stopped.', 'uwh-site-migrator'), array('import_id' => (string) ($state['import_id'] ?? ''), 'backup' => (string) ($state['name'] ?? ''), 'error' => (string) ($state['last_error'] ?? '')), 3600);
        }
        $labels = array('downloading' => __('Importing from Google Drive', 'uwh-site-migrator'), 'complete' => __('Imported to local backups', 'uwh-site-migrator'), 'failed' => __('Drive import stopped', 'uwh-site-migrator'));
        return array(
            'import_id' => (string) ($state['import_id'] ?? ''), 'file_id' => (string) ($state['file_id'] ?? ''), 'name' => (string) ($state['name'] ?? ''), 'status' => $status,
            'done' => $status === 'complete', 'bytes_received' => $received, 'bytes_total' => $total, 'progress' => $total > 0?(int) floor(($received/$total)*100):0,
            'label' => $labels[$status] ?? __('Drive import', 'uwh-site-migrator'), 'error' => (string) ($state['last_error'] ?? ''),
        );
    }

    private static function import_destination($name) {
        return rtrim(UWHSM_Engine::instance()->integration_directory(), '/\\') . '/backups/' . $name;
    }

    private static function finalize_import(array &$state) {
        $tmp = (string) $state['temp_path'];
        $dest = (string) $state['destination_path'];
        $expected = (int) $state['expected_size'];
        clearstatcache(true, $tmp);
        $size = @filesize($tmp);
        if (false === $size || (int) $size !== $expected) {
            throw new RuntimeException(esc_html__('Drive import size did not match the selected backup.', 'uwh-site-migrator'));
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(esc_html__('ZipArchive extension is missing.', 'uwh-site-migrator'));
        }
        $zip = new ZipArchive();
        $opened = $zip->open($tmp);
        if (true !== $opened || false === $zip->locateName('manifest.json')) {
            if (true === $opened) {
                $zip->close();
            }
            throw new RuntimeException(esc_html__('Downloaded file is not a valid Site Migrator backup.', 'uwh-site-migrator'));
        }
        $zip->close();
        if (is_file($dest) || is_file($dest . '.json')) {
            throw new RuntimeException(esc_html__('This backup already exists locally.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic local rename is required when committing migration state or moving an existing backup directory safely.
        if (!@rename($tmp, $dest)) {
            throw new RuntimeException(esc_html__('Drive import finished downloading, but the local backup could not be saved. Check storage permissions.', 'uwh-site-migrator'));
        }
        clearstatcache(true, $dest);
        $mtime = @filemtime($dest);
        $size = @filesize($dest);
        if (false === $mtime || false === $size || (int) $size !== $expected) {
            wp_delete_file($dest);
            throw new RuntimeException(esc_html__('Imported backup could not be verified after saving.', 'uwh-site-migrator'));
        }
        $marker = array('created' => (int) ($state['backup_created'] ?? self::now()), 'size' => (int) $size, 'mtime' => (int) $mtime, 'warnings' => array());
        if (false === @file_put_contents($dest . '.json', wp_json_encode($marker), LOCK_EX)) {
            wp_delete_file($dest);
            throw new RuntimeException(esc_html__('Imported backup marker could not be saved. Check storage permissions.', 'uwh-site-migrator'));
        }
        $state['status'] = 'complete';
        $state['confirmed_offset'] = $expected;
        $state['last_error'] = '';
        $state['updated_at'] = self::now();
        self::write_import_state($state);
    }

    public static function start_import($file_id, $name) {
        self::ensure_directory();
        if (!self::valid_google_id($file_id) || !is_string($name) || !preg_match('/^[A-Za-z0-9_.-]+\.uwh$/Di', $name)) {
            throw new RuntimeException(esc_html__('Invalid file selection.', 'uwh-site-migrator'));
        }
        $dest = self::import_destination($name);
        if (is_file($dest) || is_file($dest . '.json')) {
            throw new RuntimeException(esc_html__('This backup already exists locally.', 'uwh-site-migrator'));
        }
        foreach (self::state_ids(true) as $state_id) {
            try {
                $existing = self::read_import_state($state_id);
            } catch (Throwable $ignored) {
                continue;
            }
            if (hash_equals((string) ($existing['file_id'] ?? ''), $file_id) && hash_equals((string) ($existing['name'] ?? ''), $name) && in_array($existing['status'] ?? '', array('downloading', 'complete'), true)) {
                if (($existing['status'] ?? '') === 'complete' && !is_file($dest)) {
                    continue;
                }
                return self::safe_import_status($existing);
            }
        }
        $context = UWHSM_Drive::access_token();
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?' . http_build_query(array('fields' => 'id,name,size,createdTime,trashed,appProperties'), '', '&', PHP_QUERY_RFC3986);
        $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/json'), 'body' => ''), $context['access_token']);
        if (in_array($response['status'], array(401, 403), true)) {
            throw new RuntimeException(esc_html__('Reconnect Google Drive before importing this backup.', 'uwh-site-migrator'));
        }
        if ($response['status'] !== 200) {
            throw new RuntimeException(esc_html__('Google Drive could not inspect the selected backup.', 'uwh-site-migrator'));
        }
        $row = json_decode($response['body'], true);
        $properties = is_array($row['appProperties'] ?? null)?$row['appProperties']:array();
        if (!is_array($row) || !empty($row['trashed']) || !self::valid_google_id($row['id'] ?? '') || !hash_equals($file_id, (string) $row['id']) || !hash_equals($name, (string) ($row['name'] ?? '')) || ($properties['site_vault_product'] ?? '') !== 'site-vault-migrator') {
            throw new RuntimeException(esc_html__('The selected Drive file is not a Site Migrator backup created by this app.', 'uwh-site-migrator'));
        }
        $size = (string) ($row['size'] ?? '');
        if (!preg_match('/^[0-9]{1,20}$/D', $size) || (int) $size <= 0) {
            throw new RuntimeException(esc_html__('Google Drive returned an invalid backup size.', 'uwh-site-migrator'));
        }
        $id = bin2hex(random_bytes(16));
        $created = is_string($row['createdTime'] ?? null)?strtotime($row['createdTime']):false;
        $state = array(
            'type' => 'drive_import', 'import_id' => $id, 'file_id' => $file_id, 'name' => $name, 'expected_size' => (int) $size, 'confirmed_offset' => 0,
            'temp_path' => self::directory() . '/import-' . $id . '.part', 'destination_path' => $dest, 'backup_created' => $created?:(int) self::now(),
            'status' => 'downloading', 'last_error' => '', 'created_at' => self::now(), 'updated_at' => self::now(),
        );
        wp_delete_file($state['temp_path']);
        self::write_import_state($state);
        UWHSM_Logger::log('drive', 'started', __('Google Drive backup import started.', 'uwh-site-migrator'), array('import_id' => $id, 'backup' => $name, 'size' => (int) $size));
        return self::safe_import_status($state);
    }

    public static function import_status($import_id) {
        return self::safe_import_status(self::read_import_state($import_id));
    }

    public static function advance_import($import_id) {
        if (!self::valid_id($import_id)) {
            throw new RuntimeException(esc_html__('Invalid Drive import ID.', 'uwh-site-migrator'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
        $lock = @fopen(self::import_lock_path($import_id), 'c');
        if (!$lock) {
            throw new RuntimeException(esc_html__('Could not lock Drive import progress.', 'uwh-site-migrator'));
        }
        if (!@flock($lock, LOCK_EX|LOCK_NB)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
            $state = self::read_import_state($import_id);
            return self::safe_import_status($state);
        }
        try {
            $state = self::read_import_state($import_id);
            if (($state['status'] ?? '') === 'complete') {
                return self::safe_import_status($state);
            }
            if (($state['status'] ?? '') === 'failed') {
                return self::safe_import_status($state);
            }
            $offset = (int) ($state['confirmed_offset'] ?? 0);
            $total = (int) ($state['expected_size'] ?? 0);
            if ($offset >= $total) {
                self::finalize_import($state);
                return self::safe_import_status($state);
            }
            $range = self::import_range($offset, $total);
            $context = UWHSM_Drive::access_token();
            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($state['file_id']) . '?alt=media';
            $response = self::authorized_request('GET', $url, array('headers' => array('Accept' => 'application/octet-stream', 'Range' => $range['header']), 'body' => '', 'timeout' => 60, 'limit_response_size' => $range['length']+1), $context['access_token']);
            if (in_array($response['status'], array(401, 403), true)) {
                throw new RuntimeException(__('Reconnect Google Drive, then resume this import.', 'uwh-site-migrator'));
            }
            if (!in_array($response['status'], array(200, 206), true)) {
                throw new RuntimeException(__('Google Drive could not download the next backup chunk. Try again.', 'uwh-site-migrator'));
            }
            $body = (string) $response['body'];
            $length = strlen($body);
            if ($response['status'] === 206) {
                if ($length !== $range['length']) {
                    throw new RuntimeException(__('Google Drive returned an incomplete backup chunk. Try again.', 'uwh-site-migrator'));
                }
                $content_range = (string) ($response['headers']['content-range'] ?? '');
                if ($content_range !== '' && !preg_match('/^bytes\s+' . preg_quote((string) $range['start'], '/') . '-' . preg_quote((string) $range['end'], '/') . '\/' . preg_quote((string) $total, '/') . '$/D', $content_range)) {
                    throw new RuntimeException(__('Google Drive returned an unexpected backup range. Try again.', 'uwh-site-migrator'));
                }
            } else {
                if ($offset !== 0 || $length !== $total) {
                    throw new RuntimeException(__('Google Drive ignored the requested backup range. Try again.', 'uwh-site-migrator'));
                }
            }
            $tmp = (string) $state['temp_path'];
            clearstatcache(true, $tmp);
            $existing = is_file($tmp)?(int) filesize($tmp):0;
            if ($existing !== $offset) {
                throw new RuntimeException(__('Local Drive import progress no longer matches the downloaded file. Restart the import.', 'uwh-site-migrator'));
            }
            if (false === @file_put_contents($tmp, $body, FILE_APPEND|LOCK_EX)) {
                throw new RuntimeException(__('Could not write the downloaded backup chunk. Check storage permissions.', 'uwh-site-migrator'));
            }
            clearstatcache(true, $tmp);
            $after = @filesize($tmp);
            if (false === $after || (int) $after !== $offset+$length) {
                throw new RuntimeException(__('Downloaded backup chunk could not be verified on disk.', 'uwh-site-migrator'));
            }
            $state['confirmed_offset'] = $offset+$length;
            $state['last_error'] = '';
            $state['updated_at'] = self::now();
            self::write_import_state($state);
            if ((int) $state['confirmed_offset'] >= $total) {
                self::finalize_import($state);
            }
            return self::safe_import_status($state);
        } catch (Throwable $error) {
            try {
                $state = self::read_import_state($import_id);
                $state['last_error'] = substr($error->getMessage(), 0, 300);
                $state['updated_at'] = self::now();
                self::write_import_state($state);
            } catch (Throwable $ignored) {}
            UWHSM_Logger::log_once('drive-import-error-' . $import_id . '-' . md5($error->getMessage()), 'drive', 'failed', __('Google Drive import encountered an error.', 'uwh-site-migrator'), array('import_id' => $import_id, 'backup' => (string) ($state['name'] ?? ''), 'error' => $error->getMessage()), 3600);
            throw $error;
        } finally {
            @flock($lock, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            @fclose($lock);
        }
    }

    public static function ajax_import_start() {
        self::ajax_guard();
        try {
            $file_id = UWHSM_Compat::post_value('file_id');
            $name = UWHSM_Compat::post_value('name');
            $result = self::start_import($file_id, $name);
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_import_status() {
        self::ajax_guard();
        try {
            $id = UWHSM_Compat::post_value('import_id');
            $result = self::import_status($id);
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_import_step() {
        self::ajax_guard();
        try {
            $id = UWHSM_Compat::post_value('import_id');
            $result = self::advance_import($id);
        } catch (Throwable $error) {
            wp_send_json_error(array('message' => $error->getMessage()), 400);
            return;
        }
        wp_send_json_success($result);
    }

    public static function ajax_fetch() {
        self::ajax_guard();
        $file_id = UWHSM_Compat::post_value('file_id');
        // Keep the exact remote .uwh filename instead of altering it with sanitize_file_name().
        $name = UWHSM_Compat::post_value('name');

        // Domain based backup support korar jonno regex update
        if (!$file_id || !$name || !preg_match('/^[A-Za-z0-9_.-]+\.uwh$/i', $name)) {
            wp_send_json_error(array('message' => __('Invalid file selection.', 'uwh-site-migrator')));
        }
        try {
            @ignore_user_abort(true);
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
            @set_time_limit(0);

            $context = UWHSM_Drive::access_token();
            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media';
            // Exact original nam bebohar korchi
            $dest = UWHSM_Engine::instance()->integration_directory() . '/backups/' . $name;

            $response = wp_safe_remote_get($url, array(
                'headers' => array('Authorization' => 'Bearer ' . $context['access_token']),
                'timeout' => 1200,
                'stream' => true,
                'filename' => $dest
            ));

            if (is_wp_error($response)) {
                throw new RuntimeException($response->get_error_message());
            }
            if (wp_remote_retrieve_response_code($response) !== 200) {
                wp_delete_file($dest);
                throw new RuntimeException(__('Failed to download from Google Drive.', 'uwh-site-migrator'));
            }

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                // Check korchi file ti valid Site Migrator zip kina
                if ($zip->open($dest) === true && $zip->locateName('manifest.json') !== false) {
                    $zip->close();
                    clearstatcache(true, $dest); // File size jeno thik ashe tai cache clear kora holo
                    file_put_contents($dest . '.json', wp_json_encode(array(
                        'created' => time(),
                        'size' => filesize($dest),
                        'mtime' => filemtime($dest),
                        'warnings' => array()
                    )));
                } else {
                    wp_delete_file($dest);
                    throw new RuntimeException(__('Downloaded file is not a valid Site Migrator backup.', 'uwh-site-migrator'));
                }
            } else {
                wp_delete_file($dest);
                throw new RuntimeException(__('ZipArchive extension is missing.', 'uwh-site-migrator'));
            }
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 400);
            return;
        }
        wp_send_json_success(array('message' => __('Backup imported successfully.', 'uwh-site-migrator')));
    }

    public static function proxy_download() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'uwh-site-migrator'), '', array('response' => 403));
        }
        UWHSM_Compat::verify_admin_post_nonce('uwhsm_drive_download');
        $file_id = UWHSM_Compat::get_value('file_id');
        $name = sanitize_file_name(UWHSM_Compat::get_value('name'));
        if (!$file_id || !$name) {
            wp_die(esc_html__('Invalid file selection.', 'uwh-site-migrator'), '', array('response' => 400));
        }

        $tmp = '';
        try {
            $context = UWHSM_Drive::access_token();
            $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media';
            self::ensure_directory();
            $tmp = trailingslashit(self::directory()) . 'proxy-' . wp_generate_uuid4() . '.tmp';

            @ignore_user_abort(true);
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Large archive streaming is resumable, but this request may legitimately exceed the host default execution limit.
            @set_time_limit(0);
            if (function_exists('session_write_close')) {
                @session_write_close();
            }

            $response = wp_safe_remote_get($url, array(
                'headers' => array('Authorization' => 'Bearer ' . $context['access_token']),
                'timeout' => 3600,
                'redirection' => 5,
                'stream' => true,
                'filename' => $tmp,
            ));

            if (is_wp_error($response)) {
                throw new RuntimeException(__('Google Drive download request failed. Please try again.', 'uwh-site-migrator'));
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                if (401 === $code || 403 === $code) {
                    throw new RuntimeException(__('Google Drive authorization expired or was denied. Reconnect Google Drive and try again.', 'uwh-site-migrator'));
                }
                if (404 === $code) {
                    throw new RuntimeException(__('The selected Google Drive backup was not found.', 'uwh-site-migrator'));
                }
                if (429 === $code) {
                    throw new RuntimeException(__('Google Drive is rate-limiting this download. Please wait and try again.', 'uwh-site-migrator'));
                }
                if ($code >= 500) {
                    throw new RuntimeException(__('Google Drive is temporarily unavailable. Please try again later.', 'uwh-site-migrator'));
                }
                /* translators: %d: HTTP response status code returned by Google Drive. */
                throw new RuntimeException(sprintf(esc_html__('Google Drive download failed with HTTP status %d.', 'uwh-site-migrator'), $code));
            }

            if (!is_file($tmp) || filesize($tmp) <= 0) {
                throw new RuntimeException(__('Google Drive returned an empty download.', 'uwh-site-migrator'));
            }

            while (ob_get_level()) {
                ob_end_clean();
            }
            nocache_headers();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . (string) filesize($tmp));
            header('X-Accel-Buffering: no');

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            $fh = @fopen($tmp, 'rb');
            if (!$fh) {
                throw new RuntimeException(__('Could not open the downloaded backup for streaming.', 'uwh-site-migrator'));
            }
            while (!feof($fh)) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Native streaming is required to transfer large backup archives without loading them fully into memory.
                $chunk = fread($fh, 1024 * 1024);
                if (false === $chunk) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
                    fclose($fh);
                    throw new RuntimeException(__('Could not read the downloaded backup.', 'uwh-site-migrator'));
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary file stream.
                flush();
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($fh);
            wp_delete_file($tmp);
            exit;
        } catch (Throwable $e) {
            if ($tmp && is_file($tmp)) {
                wp_delete_file($tmp);
            }
            wp_die(esc_html($e->getMessage()), '', array('response' => 500));
        }
    }


    public static function deactivate() {
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(self::HOOK);
        }
    }
}
