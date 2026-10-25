<?php
if (!defined('ABSPATH')) {
     exit;
}

/** Google Drive authorization adapter. Backup and restore code never call this class. */
final class UWHSM_Drive {
    const GATEWAY = 'https://migrator.unitedwebhost.com/';
    const OPTION = 'uwhsm_google_drive_settings_v1';
    const LEGACY_HEADER = "<?php exit; ?>\n";

    public static function boot() {
        add_action('admin_post_uwhsm_drive_action', array(__CLASS__, 'admin_action'));
        add_action('admin_post_uwhsm_drive_callback', array(__CLASS__, 'admin_callback'));
        add_action('admin_post_nopriv_uwhsm_drive_callback', array(__CLASS__, 'admin_callback_login'));
    }

    private static function legacy_file() {
        return rtrim(UWHSM_Engine::instance()->integration_directory(), '/') . '/google-drive.php';
    }

    private static function binding() {
        return home_url('/') . '|' . ABSPATH;
    }

    private static function key() {
        return hash('sha256', 'site-vault-drive-1|' . wp_salt('auth'), true);
    }

    private static function decode_payload($payload) {
        if (!is_string($payload) || '' === $payload) {
            return array();
        }
        $raw = base64_decode($payload, true);
        if (!is_string($raw) || strlen($raw) < 29) {
            throw new RuntimeException(esc_html__('Drive settings are damaged.', 'uwh-site-migrator'));
        }
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            self::binding()
        );
        if (false === $plain) {
            throw new RuntimeException(esc_html__('This Drive connection belongs to another site or its WordPress security keys changed.', 'uwh-site-migrator'));
        }
        $data = json_decode($plain, true);
        if (!is_array($data)) {
            throw new RuntimeException(esc_html__('Drive settings are damaged.', 'uwh-site-migrator'));
        }
        return $data;
    }

    private static function encode_payload(array $data) {
        $iv = random_bytes(12);
        $json = wp_json_encode($data);
        $cipher = openssl_encrypt($json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, self::binding());
        if (false === $cipher) {
            throw new RuntimeException(esc_html__('Could not encrypt Drive settings.', 'uwh-site-migrator'));
        }
        return base64_encode($iv . $tag . $cipher);
    }

    private static function filesystem() {
        if (!class_exists('WP_Filesystem_Direct')) {
            $base_file = ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            $direct_file = ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            if (is_file($base_file)) {
                require_once $base_file;
            }
            if (is_file($direct_file)) {
                require_once $direct_file;
            }
        }
        return class_exists('WP_Filesystem_Direct') ? new WP_Filesystem_Direct(null) : null;
    }

    private static function read_legacy() {
        $file = self::legacy_file();
        if (!is_file($file)) {
            return array();
        }
        $filesystem = self::filesystem();
        $contents = $filesystem ? $filesystem->get_contents($file) : false;
        if (!is_string($contents) || 0 !== strpos($contents, self::LEGACY_HEADER)) {
            throw new RuntimeException(esc_html__('Drive settings are damaged.', 'uwh-site-migrator'));
        }
        return self::decode_payload(substr($contents, strlen(self::LEGACY_HEADER)));
    }

    private static function remove_legacy_file() {
        $file = self::legacy_file();
        if (!is_file($file)) {
            return;
        }
        if (function_exists('wp_delete_file')) {
            wp_delete_file($file);
            return;
        }
        wp_delete_file($file);
    }

    private static function read() {
        $payload = get_option(self::OPTION, '');
        if (is_string($payload) && '' !== $payload) {
            return self::decode_payload($payload);
        }

        $legacy = self::read_legacy();
        if ($legacy) {
            self::save($legacy);
            self::remove_legacy_file();
        }
        return $legacy;
    }

    private static function save(array $data) {
        $payload = self::encode_payload($data);
        $saved = update_option(self::OPTION, $payload, false);
        if (!$saved && get_option(self::OPTION, '') !== $payload) {
            throw new RuntimeException(esc_html__('Cannot save Drive settings in WordPress.', 'uwh-site-migrator'));
        }
    }

    /** Read connection state for a new OAuth attempt, recovering stale/corrupt local state. */
    private static function read_for_connect() {
        try {
            return self::read();
        } catch (Throwable $error) {
            delete_option(self::OPTION);
            self::remove_legacy_file();
            return array();
        }
    }

    /** Return a config with a fresh gateway credential pair while preserving unrelated fields. */
    private static function fresh_credentials(array $config) {
        $config['site_id'] = bin2hex(random_bytes(16));
        $config['site_key'] = bin2hex(random_bytes(32));
        unset($config['connected'], $config['email'], $config['connected_at'], $config['pending']);
        return $config;
    }

    /** Attach a fresh one-time OAuth state to the local config and persist it. */
    private static function prepare_pending(array &$config) {
        $state = bin2hex(random_bytes(32));
        $config['pending'] = array(
            'state_hash' => hash('sha256', hex2bin($state)),
            'user'       => (int) get_current_user_id(),
            'created'    => time(),
        );
        self::save($config);
        return $state;
    }

    private static function api($action, array $body, array $config) {
        if (empty($config['site_id']) || empty($config['site_key'])) {
             throw new RuntimeException(esc_html__('Drive site credential is missing.', 'uwh-site-migrator'));
        }
        $result = wp_safe_remote_post(self::GATEWAY . '?action=' . rawurlencode($action), array(
            'timeout' => 24, 'redirection' => 0, 'sslverify' => true, 'reject_unsafe_urls' => true,
            'limit_response_size' => 16384,
            'headers' => array(
                'Content-Type' => 'application/json', 'Accept' => 'application/json',
                'X-SVM-Site' => $config['site_id'], 'X-SVM-Key' => $config['site_key']),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($result)) {
            $detail = method_exists($result, 'get_error_message') ? sanitize_text_field((string) $result->get_error_message()) : '';
            $message = esc_html__('Cannot reach the Google connection gateway over HTTPS.', 'uwh-site-migrator');
            if ('' !== $detail) {
                $message .= ' ' . substr($detail, 0, 180);
            }
            throw new RuntimeException(esc_html($message), 0);
        }
        $status = (int) wp_remote_retrieve_response_code($result);
        $data = json_decode((string) wp_remote_retrieve_body($result), true);
        if (!is_array($data)) {
             throw new RuntimeException(esc_html__('Google connection gateway returned an invalid response.', 'uwh-site-migrator'));
        }
        if ($status !== 200) {
            $message = substr((string) ($data['error'] ?? __('Google connection gateway rejected this request.', 'uwh-site-migrator')), 0, 250);
            if ($action === 'start' && $message === 'Gateway request failed. Check gateway server logs.') {
                $message = __('Gateway start failed. Update the gateway package, then check its health page and PHP error log.', 'uwh-site-migrator');
            }
            $id = $data['request_id'] ?? '';
            if (is_string($id) && preg_match('/^[a-f0-9]{16}$/D', $id)) {
                $message .= __(' Request ID: ', 'uwh-site-migrator') . $id;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $status is an integer exception code used internally for gateway retry handling; it is never rendered.
            throw new RuntimeException(esc_html($message), $status);
        }
        return $data;
    }

    public static function begin() {
        if (!extension_loaded('openssl')) {
            throw new RuntimeException(esc_html__('Google Drive connection requires the PHP OpenSSL extension.', 'uwh-site-migrator'));
        }
        if (!is_ssl() || strpos(home_url('/'), 'https://') !== 0 || is_multisite()) {
            throw new RuntimeException(esc_html__('Google Drive connection requires HTTPS and a standard single-site WordPress installation.', 'uwh-site-migrator'));
        }

        $config = self::read_for_connect();
        if (
            empty($config['site_id']) || !is_string($config['site_id']) || !preg_match('/^[a-f0-9]{32}$/D', $config['site_id']) ||
            empty($config['site_key']) || !is_string($config['site_key']) || !preg_match('/^[a-f0-9]{64}$/D', $config['site_key'])
        ) {
            $config = self::fresh_credentials($config);
        }

        $state = self::prepare_pending($config);
        try {
            $answer = self::api('start', array(
                'callback'     => UWHSM_Compat::drive_gateway_callback_url(),
                'return_state' => $state,
            ), $config);
        } catch (RuntimeException $error) {
            /*
             * A copied/restored database can contain a credential pair that no
             * longer matches the gateway record. Rotate once and retry instead
             * of trapping the administrator in a permanent reconnect loop.
             */
            if (in_array((int) $error->getCode(), array(400, 401, 403, 404), true)) {
                $config = self::fresh_credentials($config);
                $state = self::prepare_pending($config);
                try {
                    $answer = self::api('start', array(
                        'callback'     => UWHSM_Compat::drive_gateway_callback_url(),
                        'return_state' => $state,
                    ), $config);
                } catch (Throwable $retry_error) {
                    unset($config['pending']);
                    self::save($config);
                    throw $retry_error;
                }
            } else {
                unset($config['pending']);
                self::save($config);
                throw $error;
            }
        }

        $url = $answer['url'] ?? '';
        if (!is_string($url) || strpos($url, self::GATEWAY . '?action=authorize&id=') !== 0 ||
            !preg_match('/^https:\/\/migrator\.unitedwebhost\.com\/\?action=authorize&id=[a-f0-9]{64}$/D', $url)) {
            unset($config['pending']);
            self::save($config);
            throw new RuntimeException(esc_html__('Gateway returned an unexpected sign-in URL.', 'uwh-site-migrator'));
        }
        return $url;
    }

    private static function pending(array $config, $state) {
        $pending = $config['pending'] ?? array();
        if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/D', $state) ||
            empty($pending['state_hash']) ||
            !hash_equals((string) $pending['state_hash'], hash('sha256', hex2bin($state))) ||
            (int) ($pending['user'] ?? 0) !== (int) get_current_user_id() ||
            (int) ($pending['created'] ?? 0) + 1200 < time()) {
            throw new RuntimeException(esc_html__('Google sign-in expired or belongs to another administrator session. Start again.', 'uwh-site-migrator'));
        }
    }

    public static function complete($state, $ticket) {
        $config = self::read();
        self::pending($config, $state);
        if (!is_string($ticket) || !preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
             throw new RuntimeException(esc_html__('Invalid Google connection ticket.', 'uwh-site-migrator'));
        }
        $answer = self::api('exchange', array('ticket' => $ticket), $config);
        if (empty($answer['connected']) || empty($answer['email'])) {
             throw new RuntimeException(esc_html__('Google account verification was incomplete.', 'uwh-site-migrator'));
        }
        unset($config['pending']);
        $config['connected'] = true;
        $config['email'] = substr((string) $answer['email'], 0, 255);
        $config['connected_at'] = time();
        self::save($config);
        return array('connected' => true, 'email' => $config['email'], 'warning' => substr((string) ($answer['warning'] ?? ''), 0, 250));
    }

    public static function denied($state) {
        $config = self::read();
        self::pending($config, $state);
        unset($config['pending']);
        self::save($config);
    }

    public static function status() {
        $config = self::read();
        return array('connected' => !empty($config['connected']), 'email' => !empty($config['connected'])?(string) ($config['email'] ?? ''):'');
    }

    public static function site_identifier() {
        $config = self::read();
        if (empty($config['connected']) || empty($config['site_id']) || !is_string($config['site_id']) || !preg_match('/^[a-f0-9]{32}$/D', $config['site_id'])) {
            throw new RuntimeException(esc_html__('Connect Google Drive first.', 'uwh-site-migrator'));
        }
        return $config['site_id'];
    }

    public static function access_token() {
        $config = self::read();
        if (empty($config['connected'])) {
            throw new RuntimeException(esc_html__('Connect Google Drive first.', 'uwh-site-migrator'));
        }
        $answer = self::api('token', array(), $config);
        $token = $answer['access_token'] ?? '';
        if (!is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/\s/', $token)) {
            throw new RuntimeException(esc_html__('Google Drive needs to be reconnected.', 'uwh-site-migrator'));
        }
        $ttl = (int) ($answer['expires_in'] ?? 300);
        $ttl = max(1, min(3600, $ttl));
        return array('access_token' => $token, 'expires_in' => $ttl, 'site_id' => self::site_identifier());
    }

    public static function check() {
        $config = self::read();
        if (empty($config['connected'])) {
             throw new RuntimeException(esc_html__('Connect Google Drive first.', 'uwh-site-migrator'));
        }
        $remote = self::api('check', array(), $config);
        if (empty($remote['connected']) || empty($remote['email'])) {
             throw new RuntimeException(esc_html__('Google Drive needs to be reconnected.', 'uwh-site-migrator'));
        }
        $config['email'] = (string) $remote['email'];
        self::save($config);
        return $remote;
    }

    public static function disconnect() {
        $config = self::read();
        $answer = self::api('disconnect', array(), $config);
        unset($config['connected'], $config['email'], $config['connected_at'], $config['pending']);
        self::save($config);
        return $answer;
    }

    public static function reset() {
        delete_option(self::OPTION);
        self::remove_legacy_file();
    }

    /**
     * Return results to the settings panel that started the operation.
     * The destination is selected from known plugin pages, never an arbitrary URL.
     */
    private static function notice($message, $type = 'success', $page = 'site-migrator-drive') {
        $page = 'site-migrator-troubleshooting' === $page ? $page : 'site-migrator-drive';
        set_transient(
            'uwhsm_drive_notice_' . get_current_user_id(),
            array('text' => $message, 'type' => $type, 'page' => $page),
            60
        );
        $anchor = 'site-migrator-troubleshooting' === $page ? '#svm-drive-reset' : '#svm-drive';
        wp_safe_redirect(admin_url('admin.php?page=' . $page . $anchor));
        exit;
    }

    /** Show a one-time result beside its action, not on the Overview page. */
    public static function render_notice($page = 'site-migrator-drive') {
        $notice_key = 'uwhsm_drive_notice_' . get_current_user_id();
        $notice = get_transient($notice_key);
        if (!is_array($notice) || empty($notice['text'])) {
            return;
        }
        // Older saved notices did not include a target; they belong on Drive Settings.
        $target = isset($notice['page']) ? (string) $notice['page'] : 'site-migrator-drive';
        if ($target !== $page) {
            return;
        }
        delete_transient($notice_key);
        $error = 'error' === ($notice['type'] ?? 'success');
        echo '<div class="notice ' . ($error ? 'notice-error' : 'notice-success') . ' inline" role="' . ($error ? 'alert' : 'status') . '"><p>' . esc_html((string) $notice['text']) . '</p></div>';
    }

    public static function allow_gateway_redirect_host($hosts) {
        if (!is_array($hosts)) {
            $hosts = array();
        }
        $hosts[] = 'migrator.unitedwebhost.com';
        return array_values(array_unique($hosts));
    }

    private static function redirect_to_gateway($url) {
        add_filter('allowed_redirect_hosts', array(__CLASS__, 'allow_gateway_redirect_host'));
        try {
            return wp_safe_redirect($url);
        } finally {
            remove_filter('allowed_redirect_hosts', array(__CLASS__, 'allow_gateway_redirect_host'));
        }
    }

    private static function guard() {
        if (!current_user_can('manage_options') || is_multisite()) {
             wp_die(esc_html__('Administrator permission on a single-site WordPress installation is required.', 'uwh-site-migrator'), 403);
        }
    }

    public static function admin_action() {
        self::guard();
        UWHSM_Compat::verify_admin_post_nonce('uwhsm_drive');
        // The local reset button also exists in Troubleshooting. Its status
        // must be shown there; all other Drive actions return to Drive Settings.
        $return_page = 'site-migrator-drive';
        try {
            $operation = sanitize_key(UWHSM_Compat::post_value('operation'));
            if ('reset' === $operation && 'site-migrator-troubleshooting' === sanitize_key(UWHSM_Compat::post_value('return_page'))) {
                $return_page = 'site-migrator-troubleshooting';
            }
            if ($operation === 'connect') {
                if (!self::redirect_to_gateway(self::begin())) {
                    throw new RuntimeException(esc_html__('Could not redirect to the Google Drive authorization gateway.', 'uwh-site-migrator'));
                }
                exit;
            }
            if ($operation === 'check') {
                 $result = self::check();
             UWHSM_Logger::log('drive', 'success', __('Google Drive connection verified.', 'uwh-site-migrator'));
             self::notice(__('Google Drive connection verified: ', 'uwh-site-migrator') . $result['email']);
            }
            if ($operation === 'disconnect') {
                $result = self::disconnect();
                UWHSM_Logger::log('drive', 'info', __('Google Drive disconnected.', 'uwh-site-migrator'));
                self::notice(__('Google Drive disconnected. ', 'uwh-site-migrator') . ($result['warning'] ?? ''));
            }
            if ($operation === 'reset') {
                self::reset();
                UWHSM_Logger::log('drive', 'warning', __('Local Google Drive connection settings cleared.', 'uwh-site-migrator'));
                self::notice(__('Local Drive settings cleared. If an account was connected, revoke Site Migrator access in Google Account settings.', 'uwh-site-migrator'), 'success', $return_page);
            }
            throw new RuntimeException(__('Unknown Google Drive action.', 'uwh-site-migrator'));
        } catch (Throwable $e) {
             UWHSM_Logger::log('drive', 'failed', __('Google Drive account action failed.', 'uwh-site-migrator'), array('error' => $e->getMessage()));
         self::notice($e->getMessage(), 'error', $return_page);
        }
    }

    /**
     * Recover OAuth callbacks that return without a WordPress auth cookie.
     * The callback parameters are strictly validated, then preserved through
     * WordPress login; the authenticated callback still enforces user/state.
     */
    public static function admin_callback_login() {
        $state = UWHSM_Compat::get_value('return_state');
        $ticket = UWHSM_Compat::get_value('ticket');
        $error = UWHSM_Compat::get_value('error');

        if (!preg_match('/^[a-f0-9]{64}$/D', $state)) {
            wp_die(esc_html__('Invalid Google Drive callback state.', 'uwh-site-migrator'), 400);
        }
        if ('' !== $ticket && !preg_match('/^[a-f0-9]{64}$/D', $ticket)) {
            wp_die(esc_html__('Invalid Google connection ticket.', 'uwh-site-migrator'), 400);
        }

        $args = array(
            'action'       => 'uwhsm_drive_callback',
            'return_state' => $state,
        );
        if ('' !== $ticket) {
            $args['ticket'] = $ticket;
        } elseif ('' !== $error) {
            $args['error'] = substr($error, 0, 80);
        }

        $callback = add_query_arg($args, admin_url('admin-post.php'));
        wp_safe_redirect(wp_login_url($callback));
        exit;
    }

    public static function admin_callback() {
        self::guard();
        try {
            $state = UWHSM_Compat::get_value('return_state');
            if ('' !== UWHSM_Compat::get_value('error')) {
                 self::denied($state);
             self::notice(__('Google Drive permission was not granted or Google could not verify it. Please try again.', 'uwh-site-migrator'), 'error');
            }
            $ticket = UWHSM_Compat::get_value('ticket');
            $result = self::complete($state, $ticket);
            UWHSM_Logger::log('drive', 'success', __('Google Drive connected successfully.', 'uwh-site-migrator'));
            self::notice(__('Google Drive connected: ', 'uwh-site-migrator') . $result['email'] . '. ' . $result['warning']);
        } catch (Throwable $e) {
             UWHSM_Logger::log('drive', 'failed', __('Google Drive connection callback failed.', 'uwh-site-migrator'), array('error' => $e->getMessage()));
         self::notice($e->getMessage(), 'error');
        }
    }

    public static function render_auto_backup_settings() {
        $auto_frequency = (string) get_option('uwhsm_auto_backup_frequency', 'none');
        if (!in_array($auto_frequency, array('none', 'daily', 'weekly', 'monthly'), true)) {
            $auto_frequency = 'none';
        }
        $auto_backup_time = (string) get_option('uwhsm_auto_backup_time', '03:00');
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $auto_backup_time)) {
            $auto_backup_time = '03:00';
        }
        $auto_drive_retention = max(1, min(100, (int) get_option('uwhsm_auto_drive_retention', 7)));
        $auto_next_run = wp_next_scheduled('uwhsm_auto_backup_event');
        $auto_timezone = function_exists('wp_timezone') ? wp_timezone()->getName() : 'UTC';
        try {
             $drive_status = self::status();
        } catch (Throwable $ignored) {
             $drive_status = array('connected' => false);
        }
        ?>
        <section class="svm-card svm-auto-settings">
            <div class="svm-card-head"><span class="dashicons dashicons-calendar-alt"></span><div><h2><?php esc_html_e('Automatic Scheduled Backup', 'uwh-site-migrator');
             ?></h2><p><?php esc_html_e('Schedule unattended backups and send them to Google Drive.', 'uwh-site-migrator'); ?></p></div></div>
            <?php if (empty($drive_status['connected'])): ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('Google Drive is not connected. You can save the schedule now, but automatic delivery will wait until Drive is connected.', 'uwh-site-migrator'); ?></p></div>
            <?php endif; ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="auto-freq"><?php esc_html_e('Frequency', 'uwh-site-migrator'); ?></label></th>
                    <td>
                        <select id="auto-freq" name="auto_freq">
                            <option value="none" <?php selected($auto_frequency, 'none');
                             ?>><?php esc_html_e('Manual Only (Disabled)', 'uwh-site-migrator'); ?></option>
                            <option value="daily" <?php selected($auto_frequency, 'daily');
                             ?>><?php esc_html_e('Daily', 'uwh-site-migrator'); ?></option>
                            <option value="weekly" <?php selected($auto_frequency, 'weekly');
                             ?>><?php esc_html_e('Weekly', 'uwh-site-migrator'); ?></option>
                            <option value="monthly" <?php selected($auto_frequency, 'monthly');
                             ?>><?php esc_html_e('Monthly', 'uwh-site-migrator'); ?></option>
                        </select>
                        <?php if ('none' !== $auto_frequency): ?>
                            <p class="description"><?php esc_html_e('Saved:', 'uwh-site-migrator');
                             ?> <strong><?php echo esc_html(array('daily' => __('Daily', 'uwh-site-migrator'), 'weekly' => __('Weekly', 'uwh-site-migrator'), 'monthly' => __('Monthly', 'uwh-site-migrator'))[$auto_frequency] ?? $auto_frequency);
                             ?></strong>. <?php if ($auto_next_run): ?><?php esc_html_e('Next WP-Cron run:', 'uwh-site-migrator');
                             ?> <?php echo esc_html(function_exists('wp_date') ? wp_date('Y-m-d H:i:s', (int) $auto_next_run) : date_i18n('Y-m-d H:i:s', (int) $auto_next_run));
                             ?>.<?php else: ?><?php esc_html_e('No WP-Cron event is currently registered; save the settings again and check hosting cron.', 'uwh-site-migrator');
                             ?><?php endif; ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auto-backup-time"><?php esc_html_e('Backup Time', 'uwh-site-migrator'); ?></label></th>
                    <td><input type="time" id="auto-backup-time" name="auto_backup_time" value="<?php echo esc_attr($auto_backup_time);
                     ?>" step="60"><p class="description"><?php esc_html_e('WordPress site timezone:', 'uwh-site-migrator');
                     ?> <strong><?php echo esc_html($auto_timezone); ?></strong>.</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="auto-drive-retention"><?php esc_html_e('Drive backups to keep', 'uwh-site-migrator'); ?></label></th>
                    <td>
                        <input type="number" id="auto-drive-retention" name="auto_drive_retention" value="<?php echo esc_attr($auto_drive_retention); ?>" min="1" max="100" step="1" class="small-text">
                        <p class="description"><?php esc_html_e('Keep the latest', 'uwh-site-migrator');
                         ?> <strong><?php echo esc_html($auto_drive_retention);
                         ?></strong> <?php esc_html_e('automatic backups in Google Drive. Default is 7. After a newer automatic backup uploads successfully, the oldest automatic Drive backup is removed when this limit is exceeded. Manual Drive backups are never removed by this rule.', 'uwh-site-migrator'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Storage behavior', 'uwh-site-migrator'); ?></th>
                    <td><p class="description"><strong><?php esc_html_e('Automatic backups:', 'uwh-site-migrator');
                     ?></strong> <?php esc_html_e('removed locally after a successful Drive upload. If Drive fails or needs reconnection, only the latest 2 completed automatic backups are retained locally.', 'uwh-site-migrator');
                     ?> <strong><?php esc_html_e('Google Drive:', 'uwh-site-migrator');
                     ?></strong> <?php esc_html_e('keeps the number of automatic backups configured above.', 'uwh-site-migrator');
                     ?> <strong><?php esc_html_e('Manual backups:', 'uwh-site-migrator');
                     ?></strong> <?php esc_html_e('remain stored locally and are never auto-pruned from Drive.', 'uwh-site-migrator'); ?></p></td>
                </tr>
            </table>
            <p class="submit"><button type="button" id="save-auto-backup" class="button button-primary"><?php esc_html_e('Save Auto Backup Settings', 'uwh-site-migrator'); ?></button></p>
        </section>
        <?php
    }

    public static function render() {
        try {
             $status = self::status();
         $error = '';
        } catch (Throwable $e) {
             $status = array('connected' => false);
         $error = $e->getMessage();
        }
        $active = !empty($status['connected'])?UWHSM_Drive_Transfer::active_transfer():false;
        ?>
        <section class="svm-card svm-list" id="svm-drive" data-connected="<?php echo !empty($status['connected'])?'1':'0';
         ?>" data-active-transfer="<?php echo esc_attr($active['transfer_id'] ?? ''); ?>">
            <div class="svm-card-head"><span class="dashicons dashicons-cloud"></span><div><h2><?php esc_html_e('Google Drive', 'uwh-site-migrator');
             ?></h2><p><?php esc_html_e('Connect your account, review Site Migrator cloud backups and manage resumable Drive transfers.', 'uwh-site-migrator'); ?></p></div></div>
            <?php self::render_notice('site-migrator-drive'); ?>
            <?php if ($error): ?><div class="notice notice-error inline"><p><?php echo esc_html($error);
             ?></p></div><?php endif; ?>
            <p><strong><?php
            if (!empty($status['connected'])) {
                /* translators: %s: connected Google account email address. */
                echo esc_html(sprintf(__('Connected to %s', 'uwh-site-migrator'), (string) $status['email']));
            } else {
                esc_html_e('Not connected', 'uwh-site-migrator');
            }
            ?></strong></p>
            <div class="svm-drive-actions">
                <?php foreach ((!empty($status['connected']) ? array('connect' => __('Reconnect Google Drive', 'uwh-site-migrator'), 'check' => __('Check connection', 'uwh-site-migrator'), 'disconnect' => __('Disconnect', 'uwh-site-migrator')) : array('connect' => __('Connect Google Drive', 'uwh-site-migrator'))) as $operation => $label): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="uwhsm_drive_action">
                    <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
                    <?php wp_nonce_field('uwhsm_drive'); ?>
                    <button class="button <?php echo $operation === 'connect'?'button-primary':'';
                     ?>" type="submit"><?php echo esc_html($label); ?></button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($status['connected'])): ?>
                <div class="svm-drive-summary"><div><small><?php esc_html_e('Accessible backups', 'uwh-site-migrator');
                 ?></small><strong id="svm-drive-count">0</strong></div><div><small><?php esc_html_e('Total size', 'uwh-site-migrator');
                 ?></small><strong id="svm-drive-total">0 B</strong></div><button type="button" class="button" id="svm-drive-refresh"><?php esc_html_e('Refresh', 'uwh-site-migrator'); ?></button></div>
                <div class="svm-progress-wrap" id="svm-drive-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator');
                 ?></strong><span>0%</span></div><p id="svm-drive-transfer-meta"></p><button type="button" class="button" id="svm-drive-resume" hidden><?php esc_html_e('Resume Drive upload', 'uwh-site-migrator'); ?></button></div>
                <p id="svm-drive-loading"><?php esc_html_e('Wait loading Site Migrator backups from Google Drive…', 'uwh-site-migrator'); ?></p>
                <p id="svm-drive-empty" hidden><?php esc_html_e('No Site Migrator backups uploaded by this app are visible in the connected Drive.', 'uwh-site-migrator'); ?></p>
                <p class="svm-log is-error" id="svm-drive-error" aria-live="polite" hidden></p>
                <div class="svm-drive-table-wrap"><table><thead><tr><th><?php esc_html_e('Backup', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Source', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Created', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Size', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Actions', 'uwh-site-migrator'); ?></th></tr></thead><tbody id="svm-drive-table"></tbody></table></div>
            <?php endif; ?>
        </section>
        <?php
    }
}
