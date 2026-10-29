<?php
if (!defined('ABSPATH')) {
     exit;
}

final class UWHSM_Admin {
    private static $instance;

    public static function instance() {
        return self::$instance ?: (self::$instance = new self());
    }

    private function __construct() {
        add_filter('wp_handle_upload_prefilter', array($this, 'check_plugin_upload'));
        add_filter('plugin_row_meta', array($this, 'plugin_row_meta'), 10, 2);
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_notices', array($this, 'requirements_notice'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_uwhsm_activity_logs_action', array($this, 'activity_logs_action'));
        add_action('admin_post_uwhsm_diagnostic_report', array($this, 'download_diagnostic_report'));
        add_action('admin_post_uwhsm_maintenance_control', array($this, 'maintenance_control'));
        add_action('admin_post_uwhsm_repair_elementor', array($this, 'repair_elementor_action'));
    }

    public function check_plugin_upload($file) {
        $action = sanitize_key(UWHSM_Compat::request_value('action'));

        if ('upload-plugin' === $action && is_string($file['name'] ?? null) && preg_match('/\.uwh$/i', $file['name'])) {
            $file['error'] = __('This is a website backup, not a plugin. Open Site Migrator → Import / Export and select this backup there.', 'uwh-site-migrator');
        }

        return $file;
    }

    public function plugin_row_meta($links, $file) {
        if ($file !== plugin_basename(UWHSM_FILE)) {
             return $links;
        }
        $links[] = '<a href="https://site-migrator.unitedwebhost.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('Plugin Website', 'uwh-site-migrator') . '</a>';
        return $links;
    }

    private function menu_icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
             . '<defs><linearGradient id="svmGradient" x1="2" y1="3" x2="22" y2="21" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#4F46E5"/><stop offset="0.52" stop-color="#06B6D4"/><stop offset="1" stop-color="#22C55E"/></linearGradient></defs>'
             . '<path fill="url(#svmGradient)" d="M4 3.5A2.5 2.5 0 0 0 1.5 6v3A2.5 2.5 0 0 0 4 11.5h16A2.5 2.5 0 0 0 22.5 9V6A2.5 2.5 0 0 0 20 3.5H4Zm0 2h16a.5.5 0 0 1 .5.5v3a.5.5 0 0 1-.5.5H4a.5.5 0 0 1-.5-.5V6a.5.5 0 0 1 .5-.5Zm2 1.25a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm-2 6A2.5 2.5 0 0 0 1.5 15v3A2.5 2.5 0 0 0 4 20.5h7.2a6.1 6.1 0 0 1-1.08-2H4a.5.5 0 0 1-.5-.5v-3a.5.5 0 0 1 .5-.5h7.1a6.1 6.1 0 0 1 1.45-1.75H4ZM6 16a1 1 0 1 0 0 2 1 1 0 0 0 0-2Zm11.25-3.25a4.25 4.25 0 0 0-4.13 3.25H11l2.9 2.9 2.9-2.9h-1.55a2.25 2.25 0 1 1 .53 2.3l-1.42 1.42a4.25 4.25 0 1 0 2.89-6.97Z"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public function requirements_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $issues = array();
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $issues[] = __('PHP 7.4 or newer is required.', 'uwh-site-migrator');
        }
        if (!class_exists('ZipArchive')) {
            $issues[] = __('PHP ZipArchive is missing. Backup and restore features stay unavailable until your host enables php-zip.', 'uwh-site-migrator');
        }
        if (!$issues) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__('United Web Host Site Migrator:', 'uwh-site-migrator') . '</strong> ' . esc_html(implode(' ', $issues)) . '</p></div>';
    }

    public function menu() {
        add_menu_page(__('Site Migrator', 'uwh-site-migrator'), __('Site Migrator', 'uwh-site-migrator'), 'manage_options', 'site-vault-migrator', array($this, 'page_backups'), $this->menu_icon(), 58);
        add_submenu_page('site-vault-migrator', __('Overview', 'uwh-site-migrator'), __('Overview', 'uwh-site-migrator'), 'manage_options', 'site-vault-migrator', array($this, 'page_backups'));
        add_submenu_page('site-vault-migrator', __('Import / Export', 'uwh-site-migrator'), __('Import / Export', 'uwh-site-migrator'), 'manage_options', 'site-migrator-import-export', array($this, 'page_import_export'));
        add_submenu_page('site-vault-migrator', __('Direct Migration', 'uwh-site-migrator'), __('Direct Migration', 'uwh-site-migrator'), 'manage_options', 'site-migrator-direct', array($this, 'page_direct_migration'));
        add_submenu_page('site-vault-migrator', __('Drive Settings', 'uwh-site-migrator'), __('Drive Settings', 'uwh-site-migrator'), 'manage_options', 'site-migrator-drive', array($this, 'page_drive'));
        add_submenu_page(null, __('Activity Logs', 'uwh-site-migrator'), __('Activity Logs', 'uwh-site-migrator'), 'manage_options', 'site-migrator-logs', array($this, 'page_activity_logs'));
        add_submenu_page('site-vault-migrator', __('Troubleshooting', 'uwh-site-migrator'), __('Troubleshooting', 'uwh-site-migrator'), 'manage_options', 'site-migrator-troubleshooting', array($this, 'page_troubleshooting'));
    }

    private function allowed_page_slugs() {
        return array('site-vault-migrator', 'site-migrator-import-export', 'site-migrator-direct', 'site-migrator-drive', 'site-migrator-logs', 'site-migrator-troubleshooting');
    }

    public function assets($hook) {
        if ('plugin-install.php' === $hook && current_user_can('manage_options')) {
            wp_enqueue_script('svm-installer-guard', UWHSM_URL . 'assets/installer-guard.js', array('wp-i18n'), UWHSM_VERSION, true);
            wp_set_script_translations('svm-installer-guard', 'uwh-site-migrator', UWHSM_DIR . 'languages');
            wp_localize_script('svm-installer-guard', 'UWHSM_INSTALL', array('restoreUrl' => admin_url('admin.php?page=site-migrator-import-export')));
        }

        $page = sanitize_key(UWHSM_Compat::get_value('page'));
        if (!in_array($page, $this->allowed_page_slugs(), true)) {
             return;
        }

        wp_enqueue_style('uwhsm-menu-icon', UWHSM_URL . 'assets/menu-icon.css', array(), UWHSM_VERSION);
        wp_enqueue_script('svm-dashboard-metrics', UWHSM_URL . 'assets/dashboard-metrics.js', array('wp-i18n'), UWHSM_VERSION, true);
        wp_enqueue_script('svm-admin', UWHSM_URL . 'assets/admin.js', array('svm-dashboard-metrics', 'wp-i18n'), (string) filemtime(UWHSM_DIR . 'assets/admin.js'), true);
        wp_enqueue_script('svm-background', UWHSM_URL . 'assets/background.js', array('svm-admin', 'wp-i18n'), UWHSM_VERSION, true);
        wp_enqueue_script('svm-backup-monitor', UWHSM_URL . 'assets/backup-monitor.js', array('svm-admin', 'wp-i18n'), (string) filemtime(UWHSM_DIR . 'assets/backup-monitor.js'), true);
        wp_enqueue_style('svm-admin', UWHSM_URL . 'assets/admin.css', array(), (string) filemtime(UWHSM_DIR . 'assets/admin.css'));
        wp_set_script_translations('svm-dashboard-metrics', 'uwh-site-migrator', UWHSM_DIR . 'languages');
        wp_set_script_translations('svm-admin', 'uwh-site-migrator', UWHSM_DIR . 'languages');
        wp_set_script_translations('svm-background', 'uwh-site-migrator', UWHSM_DIR . 'languages');

        $local_backups = array();
        $engine = UWHSM_Engine::instance();
        foreach ($engine->list_backups() as $backup) {
            if (!empty($backup['name'])) {
                 $local_backups[] = $backup['name'];
            }
        }

        wp_localize_script('svm-admin', 'UWHSM_DATA', array(
            'ajax' => admin_url('admin-ajax.php'),
            'home' => home_url('/'),
            'admin' => admin_url(),
            'destinationDomain' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'nonce' => wp_create_nonce('uwhsm_admin'),
            'driveDownloadNonce' => wp_create_nonce('uwhsm_drive_download'),
            'chunkSize' => 4 * 1024 * 1024,
            'multisite' => is_multisite(),
            'drive_connected' => $this->drive_connected(),
            'local_backups' => $local_backups,
            'pending_restore_ids' => array_values(array_column($engine->pending_restores(), 'id')),
        ));
    }

    private function frequency_label($frequency) {
        $labels = array(
            'none' => __('Manual only', 'uwh-site-migrator'),
            'daily' => __('Daily', 'uwh-site-migrator'),
            'weekly' => __('Weekly', 'uwh-site-migrator'),
            'monthly' => __('Monthly', 'uwh-site-migrator'),
        );
        return $labels[$frequency] ?? (string) $frequency;
    }

    private function log_category_label($category) {
        $labels = array(
            'backup' => __('Backup', 'uwh-site-migrator'),
            'restore' => __('Restore', 'uwh-site-migrator'),
            'rollback' => __('Rollback', 'uwh-site-migrator'),
            'checkpoint' => __('Checkpoint', 'uwh-site-migrator'),
            'drive' => __('Drive', 'uwh-site-migrator'),
            'migration' => __('Migration', 'uwh-site-migrator'),
            'schedule' => __('Schedule', 'uwh-site-migrator'),
            'system' => __('System', 'uwh-site-migrator'),
        );
        return $labels[$category] ?? (string) $category;
    }

    private function log_status_label($status) {
        $labels = array(
            'started' => __('Started', 'uwh-site-migrator'),
            'success' => __('Success', 'uwh-site-migrator'),
            'failed' => __('Failed', 'uwh-site-migrator'),
            'warning' => __('Warning', 'uwh-site-migrator'),
            'info' => __('Info', 'uwh-site-migrator'),
            'stopped' => __('Stopped', 'uwh-site-migrator'),
        );
        return $labels[$status] ?? (string) $status;
    }

    private function guard_page() {
        if (!current_user_can('manage_options')) {
             return false;
        }
        return true;
    }

    private function section_nav($active) {
        $items = array(
            'backups' => array(__('Overview', 'uwh-site-migrator'), 'site-vault-migrator', 'dashicons-dashboard'),
            'import-export' => array(__('Import / Export', 'uwh-site-migrator'), 'site-migrator-import-export', 'dashicons-migrate'),
            'direct' => array(__('Direct Migration', 'uwh-site-migrator'), 'site-migrator-direct', 'dashicons-randomize'),
            'drive' => array(__('Drive Settings', 'uwh-site-migrator'), 'site-migrator-drive', 'dashicons-cloud'),
            'troubleshooting' => array(__('Troubleshooting', 'uwh-site-migrator'), 'site-migrator-troubleshooting', 'dashicons-admin-tools'),
        );
        echo '<nav class="svm-section-nav" aria-label="' . esc_attr__('Site Migrator sections', 'uwh-site-migrator') . '">';
        foreach ($items as $key => $item) {
            $class = $key === $active ? ' is-active' : '';
            echo '<a class="svm-section-nav-item' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $item[1])) . '"><span class="dashicons ' . esc_attr($item[2]) . '"></span><span>' . esc_html($item[0]) . '</span></a>';
        }
        echo '</nav>';
    }

    private function page_start($title, $description, $active) {
        ?>
        <div class="wrap svm-wrap">
            <h1 style="display:none;"></h1><hr class="wp-header-end">
            <div class="svm-hero">
                <div>
                    <span class="svm-kicker"><?php esc_html_e('WORDPRESS MIGRATION SUITE', 'uwh-site-migrator'); ?></span>
                    <h2 class="svm-main-title"><?php echo esc_html($title); ?></h2>
                    <p><?php echo esc_html($description); ?></p>
                </div>
                <span class="svm-version">v<?php echo esc_html(UWHSM_VERSION); ?></span>
            </div>
            <?php $this->section_nav($active); ?>
            <section id="svm-backup-monitor" class="svm-backup-monitor" hidden aria-live="polite">
                <div class="svm-backup-monitor-top">
                    <div><span class="dashicons dashicons-update"></span> <strong id="svm-backup-monitor-title"><?php esc_html_e('Background backup', 'uwh-site-migrator'); ?></strong></div>
                    <div class="svm-backup-monitor-actions">
                        <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=site-migrator-import-export')); ?>"><?php esc_html_e('View backup', 'uwh-site-migrator'); ?></a>
                        <button type="button" class="button button-small svm-backup-resume" id="svm-resume-monitor" hidden><?php esc_html_e('Resume now', 'uwh-site-migrator'); ?></button>
                        <button type="button" class="button button-small svm-backup-skip-file" id="svm-skip-blocked-file" hidden><?php esc_html_e('Skip this file & resume', 'uwh-site-migrator'); ?></button>
                        <button type="button" class="button button-small svm-backup-stop" id="svm-stop-backup"><?php esc_html_e('Stop backup', 'uwh-site-migrator'); ?></button>
                    </div>
                </div>
                <div class="svm-progress"><i id="svm-backup-monitor-fill" style="width:0%"></i></div>
                <div class="svm-backup-monitor-meta"><span id="svm-backup-monitor-label"><?php esc_html_e('Loading status…', 'uwh-site-migrator'); ?></span><strong id="svm-backup-monitor-percent">0%</strong></div>
                <p id="svm-backup-monitor-note" class="svm-backup-monitor-note"></p>
            </section>
            <?php if (is_multisite()): ?><div class="notice notice-error inline"><p><?php esc_html_e('WordPress Multisite is not supported. Please use this plugin only on a standard single-site installation.', 'uwh-site-migrator');
             ?></p></div><?php endif; ?>
        <?php
    }

    private function page_end() {
        echo '</div>';
    }

    private function drive_connected() {
        try {
             $status = UWHSM_Drive::status();
         return !empty($status['connected']);
        }
        catch (Throwable $ignored) {
             return false;
        }
    }

    private function dashboard_metrics(array $backups, $drive_connected, $auto_frequency, $next_timestamp) {
        $complete_count = 0;
        $temporary_count = 0;
        $local_bytes = 0;
        $latest_timestamp = 0;

        foreach ($backups as $backup) {
            $size = max(0, (int) ($backup['size'] ?? 0));
            $timestamp = max(0, (int) ($backup['timestamp'] ?? 0));
            $local_bytes += $size;
            if (!empty($backup['complete'])) {
                $complete_count++;
                if ($timestamp > $latest_timestamp) {
                     $latest_timestamp = $timestamp;
                }
            } else {
                $temporary_count++;
            }
        }

        $auto_frequency = in_array($auto_frequency, array('daily', 'weekly', 'monthly'), true) ? $auto_frequency : 'none';
        return array(
            'complete_count' => $complete_count,
            'temporary_count' => $temporary_count,
            'local_bytes' => $local_bytes,
            'latest_timestamp' => $latest_timestamp,
            'drive_connected' => (bool) $drive_connected,
            'auto_frequency' => $auto_frequency,
            'next_timestamp' => max(0, (int) $next_timestamp),
        );
    }

    /**
     * One completion panel for direct migration, uploaded archives, local restores,
     * saved restore resumes, and the overview screen. Keep links and copy in sync.
     * The panel only becomes visible after the browser verifies the homepage.
     */
    private function render_restore_success($direct = false) {
        $panel_id = $direct ? 'svm-direct-success' : 'svm-restore-success';
        ?>
        <div class="svm-direct-success svm-restore-success" id="<?php echo esc_attr($panel_id); ?>" role="status" aria-live="polite" hidden>
            <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
            <div class="svm-direct-success-copy">
                <strong><?php echo esc_html($direct ? __('Migration completed', 'uwh-site-migrator') : __('Restore completed', 'uwh-site-migrator')); ?></strong>
                <p><?php esc_html_e('The destination homepage responded successfully. Review your website and log in with the source website account if the restored database replaced the existing users.', 'uwh-site-migrator'); ?></p>
                <div class="svm-direct-success-actions">
                    <a class="button button-primary" href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View site', 'uwh-site-migrator'); ?></a>
                    <a class="button" href="<?php echo esc_url(wp_login_url(admin_url())); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Log in to WordPress', 'uwh-site-migrator'); ?></a>
                    <button type="button" class="button svm-restore-log-download"><?php esc_html_e('Download log', 'uwh-site-migrator'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_restore_runtime() {
        ?>
        <div class="svm-restore-runtime">
            <button class="button button-secondary" id="svm-resume-restore" hidden><?php esc_html_e('Resume interrupted restore', 'uwh-site-migrator'); ?></button>
            <div class="svm-progress-wrap" id="svm-restore-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator'); ?></strong><span>0%</span></div></div>
            <div class="svm-log" id="svm-restore-log" aria-live="polite"></div>
            <?php $this->render_restore_success(); ?>
        </div>
        <?php
    }

    private function render_local_restore_modal() {
        ?>
        <div class="svm-modal" id="svm-local-restore-modal" role="dialog" aria-modal="true" aria-labelledby="svm-local-restore-title" hidden>
            <div class="svm-modal-panel">
                <h2 id="svm-local-restore-title" tabindex="-1"><?php esc_html_e('Restore this local backup?', 'uwh-site-migrator'); ?></h2>
                <p><?php esc_html_e('Website files and the database on this site will be replaced using', 'uwh-site-migrator');
                 ?> <code id="svm-local-backup-name"></code><?php esc_html_e('. The stored backup itself will be retained.', 'uwh-site-migrator'); ?></p>
                <label class="svm-checkpoint-option"><input type="checkbox" id="svm-local-checkpoint" checked><span><strong><?php esc_html_e('Create restore safety checkpoint', 'uwh-site-migrator'); ?></strong><small><?php esc_html_e('Enabled by default: back up the current destination first. Uncheck to restore without an automatic rollback checkpoint.', 'uwh-site-migrator'); ?></small></span></label>
                <label class="svm-confirm"><input type="checkbox" id="svm-local-confirm"> <?php esc_html_e('I understand this destructive restore will replace this website.', 'uwh-site-migrator'); ?></label>
                <div class="svm-modal-actions"><button type="button" class="button" id="svm-local-cancel"><?php esc_html_e('Cancel', 'uwh-site-migrator');
                 ?></button><button type="button" class="button button-primary" id="svm-local-start" disabled><?php esc_html_e('Start restore', 'uwh-site-migrator'); ?></button></div>
            </div>
        </div>
        <?php
    }

    public function page_backups() {
        if (!$this->guard_page()) {
             return;
        }
        $engine = UWHSM_Engine::instance();
        $backups = $engine->list_backups();
        $drive_connected = $this->drive_connected();
        $auto_frequency = (string) get_option('uwhsm_auto_backup_frequency', 'none');
        $auto_next = wp_next_scheduled('uwhsm_auto_backup_event');
        $metrics = $this->dashboard_metrics($backups, $drive_connected, $auto_frequency, $auto_next);
        $last_backup = $metrics['latest_timestamp'] ? strtolower(wp_date('d M Y · H:i', $metrics['latest_timestamp'])) : __('No backup yet', 'uwh-site-migrator');
        $next_backup = $metrics['next_timestamp'] ? strtolower(wp_date('d M Y · H:i', $metrics['next_timestamp'])) : ('none' === $metrics['auto_frequency'] ? __('Manual only', 'uwh-site-migrator') : __('Not scheduled', 'uwh-site-migrator'));
        $this->page_start(__('Overview', 'uwh-site-migrator'), __('Monitor backup health, storage, automation and recovery from one place.', 'uwh-site-migrator'), 'backups');
        ?>
        <section class="svm-dashboard-overview" aria-label="<?php echo esc_attr__('Backup overview', 'uwh-site-migrator'); ?>">
            <article class="svm-dashboard-stat">
                <span class="svm-dashboard-stat-icon dashicons dashicons-database"></span>
                <div><small><?php esc_html_e('Local Backups', 'uwh-site-migrator');
                 ?></small><strong><?php echo esc_html((string) $metrics['complete_count']);
                 ?></strong><span><?php echo esc_html((string) $metrics['temporary_count']);
                 ?> <?php esc_html_e('temporary', 'uwh-site-migrator'); ?></span></div>
            </article>
            <article class="svm-dashboard-stat">
                <span class="svm-dashboard-stat-icon dashicons dashicons-cloud"></span>
                <div><small><?php esc_html_e('Drive Backups', 'uwh-site-migrator');
                 ?></small><strong id="svm-dashboard-drive-count"><?php echo $drive_connected ? '…' : '—';
                 ?></strong><span id="svm-dashboard-drive-size"><?php echo esc_html($drive_connected ? __('Loading…', 'uwh-site-migrator') : __('Drive not connected', 'uwh-site-migrator')); ?></span></div>
            </article>
            <article class="svm-dashboard-stat">
                <span class="svm-dashboard-stat-icon dashicons dashicons-media-archive"></span>
                <div><small><?php esc_html_e('Local Storage Used', 'uwh-site-migrator');
                 ?></small><strong><?php echo esc_html(size_format($metrics['local_bytes'], 2));
                 ?></strong><span><?php esc_html_e('Backup packages on this server', 'uwh-site-migrator'); ?></span></div>
            </article>
            <article class="svm-dashboard-stat">
                <span class="svm-dashboard-stat-icon dashicons dashicons-yes-alt"></span>
                <div><small><?php esc_html_e('Last Backup', 'uwh-site-migrator');
                 ?></small><strong class="svm-dashboard-date" id="svm-dashboard-last-backup" data-local-ts="<?php echo esc_attr((string) $metrics['latest_timestamp']);
                 ?>"><?php echo esc_html($last_backup);
                 ?></strong><span id="svm-dashboard-last-source"><?php echo esc_html($metrics['latest_timestamp'] ? __('Latest completed local backup', 'uwh-site-migrator') : __('Create your first backup', 'uwh-site-migrator')); ?></span></div>
            </article>
            <article class="svm-dashboard-stat">
                <span class="svm-dashboard-stat-icon dashicons dashicons-calendar-alt"></span>
                <div><small><?php esc_html_e('Next Automatic Backup', 'uwh-site-migrator');
                 ?></small><strong class="svm-dashboard-date"><?php echo esc_html($next_backup);
                 ?></strong><span><?php
                 if ('none' === $metrics['auto_frequency']) {
                     esc_html_e('Automatic backups are disabled', 'uwh-site-migrator');
                 } else {
                     /* translators: %s: automatic backup frequency label, for example Daily or Weekly. */
                     echo esc_html(sprintf(__('%s schedule', 'uwh-site-migrator'), $this->frequency_label($metrics['auto_frequency'])));
                 }
                 ?></span></div>
            </article>
            <article class="svm-dashboard-stat <?php echo $drive_connected ? 'is-connected' : 'is-warning'; ?>">
                <span class="svm-dashboard-stat-icon dashicons dashicons-cloud"></span>
                <div><small><?php esc_html_e('Google Drive', 'uwh-site-migrator');
                 ?></small><strong><?php echo esc_html($drive_connected ? __('Connected', 'uwh-site-migrator') : __('Not connected', 'uwh-site-migrator'));
                 ?></strong><span><?php echo esc_html($drive_connected ? __('Cloud delivery is ready', 'uwh-site-migrator') : __('Connect Drive for cloud backups', 'uwh-site-migrator')); ?></span></div>
            </article>
        </section>

        <section class="svm-card svm-list svm-backup-list">
            <div class="svm-card-head svm-card-head-split"><span class="dashicons dashicons-archive"></span><div><h2><?php esc_html_e('Available backups', 'uwh-site-migrator');
             ?></h2><p><?php esc_html_e('Restore, download, send to Drive or remove packages stored on this server.', 'uwh-site-migrator');
             ?></p></div><button type="button" class="button svm-stop-all-unfinished" title="<?php echo esc_attr__('Stop and remove all unfinished ordinary backups. Completed backups and safety checkpoints are preserved.', 'uwh-site-migrator'); ?>"><?php esc_html_e('Stop all unfinished', 'uwh-site-migrator'); ?></button><div class="svm-list-summary"><strong><?php echo esc_html((string) $metrics['complete_count']);
             ?></strong><span><?php esc_html_e('completed', 'uwh-site-migrator');
             ?></span><strong><?php echo esc_html(size_format($metrics['local_bytes'], 2));
             ?></strong><span><?php esc_html_e('local usage', 'uwh-site-migrator'); ?></span></div></div>
            <table><thead><tr><th><?php esc_html_e('Backup', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Status', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Created', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Size', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Actions', 'uwh-site-migrator'); ?></th></tr></thead><tbody id="svm-backups">
            <?php if (!$backups): ?><tr class="svm-empty"><td colspan="5"><?php esc_html_e('No backup packages found.', 'uwh-site-migrator');
             ?></td></tr><?php endif; ?>
            <?php foreach ($backups as $backup): $transfer = $backup['complete'] ? UWHSM_Drive_Transfer::status_for_backup($backup['name']) : false; ?>
                <tr data-backup="<?php echo esc_attr($backup['name']);
                 ?>" data-transfer-id="<?php echo esc_attr($transfer['transfer_id'] ?? ''); ?>">
                    <td><strong><?php echo esc_html($backup['name']); ?></strong><?php if (($backup['origin'] ?? '') === 'direct-migration'): ?><span class="svm-status is-complete svm-migrated-label"><?php esc_html_e('Migrated source backup', 'uwh-site-migrator'); ?></span><?php endif; ?></td>
                    <td><div class="svm-status-stack"><span class="svm-status <?php echo $backup['complete'] ? 'is-complete' : 'is-partial';
                     ?>"><?php echo esc_html($backup['complete'] ? __('Complete', 'uwh-site-migrator') : __('Temporary', 'uwh-site-migrator'));
                     ?></span><?php if ($backup['complete'] && $transfer && in_array($transfer['status'] ?? '', array('queued', 'uploading', 'waiting'), true)): ?><span class="svm-status is-working"><?php esc_html_e('Uploading', 'uwh-site-migrator');
                     ?></span><?php endif; ?></div></td>
                    <td><?php echo esc_html($backup['date']); ?></td>
                    <td><?php echo esc_html(size_format($backup['size'], 2)); ?></td>
                    <td>
                        <div class="svm-row-actions">
                        <?php if ($backup['complete']): ?>
                            <button type="button" class="button svm-restore-local" data-backup="<?php echo esc_attr($backup['name']);
                             ?>"><?php esc_html_e('Restore', 'uwh-site-migrator'); ?></button>
                            <a class="button" href="<?php echo esc_url($backup['download']);
                             ?>"><?php esc_html_e('Download', 'uwh-site-migrator'); ?></a>
                            <button type="button" class="button svm-send-drive" data-backup="<?php echo esc_attr($backup['name']);
                             ?>" <?php disabled(!$drive_connected);
                             ?>><?php echo esc_html($transfer && in_array($transfer['status'], array('queued', 'uploading', 'waiting'), true) ? __('Resume Drive upload', 'uwh-site-migrator') : __('Send to Drive', 'uwh-site-migrator')); ?></button>
                        <?php endif; ?>
                            <?php if (!$backup['complete'] && !empty($backup['resumable'])): ?>
                                <?php $backup_running_selected = !empty($backup['selected']) && empty($backup['paused']); ?>
                                <button type="button" class="button <?php echo $backup_running_selected ? 'svm-pause-temporary' : 'button-primary svm-resume-temporary'; ?>" data-job="<?php echo esc_attr($backup['job']); ?>" data-backup="<?php echo esc_attr($backup['name']); ?>" data-resume-url="<?php echo esc_url(admin_url('admin.php?page=site-migrator-import-export')); ?>"><?php echo esc_html($backup_running_selected ? __('Pause backup', 'uwh-site-migrator') : __('Resume backup', 'uwh-site-migrator')); ?></button>
                            <?php elseif (!$backup['complete']): ?>
                                <span class="svm-backup-no-resume" title="<?php echo esc_attr__('No valid saved job-state is available for this archive.', 'uwh-site-migrator'); ?>"><?php esc_html_e('Not resumable', 'uwh-site-migrator'); ?></span>
                            <?php endif; ?>
                            <button type="button" class="button svm-delete" name="backup_name" value="<?php echo esc_attr($backup['name']);
                             ?>" data-backup="<?php echo esc_attr($backup['name']);
                             ?>"><?php echo esc_html($backup['complete'] ? __('Delete', 'uwh-site-migrator') : __('Delete temporary', 'uwh-site-migrator')); ?></button>
                        </div>
                        <?php if ($backup['complete']): ?>
                        <div class="svm-progress-wrap svm-row-progress svm-drive-row-progress" hidden>
                            <div class="svm-progress"><i></i></div>
                            <div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator'); ?></strong><span>0%</span></div>
                            <p class="svm-row-transfer-meta"></p>
                            <p class="svm-log svm-drive-row-error" aria-live="polite"></p>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php $this->render_restore_runtime(); ?>
        </section>
        <?php
        $this->render_local_restore_modal();
        $this->page_end();
    }

    public function page_import_export() {
        if (!$this->guard_page()) {
             return;
        }
        $engine = UWHSM_Engine::instance();
        $backups = $engine->list_backups();
        $checkpoints = $engine->list_checkpoints();
        $drive_connected = $this->drive_connected();
        $this->page_start(__('Import / Export', 'uwh-site-migrator'), __('Create a backup, restore a .uwh website package, or export an existing local backup.', 'uwh-site-migrator'), 'import-export');
        ?>
        <div class="svm-grid">
            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-database-export"></span><div><h2><?php esc_html_e('Create backup', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Database, themes, plugins, uploads and WordPress files.', 'uwh-site-migrator'); ?></p></div></div>
                <div class="svm-backup-options" role="group" aria-label="<?php esc_attr_e('Backup options', 'uwh-site-migrator'); ?>">
                    <label class="svm-confirm"><input type="checkbox" id="svm-background-opt" checked> <?php esc_html_e('Background backup', 'uwh-site-migrator'); ?></label>
                    <label class="svm-confirm"><input type="checkbox" id="svm-fast-zip-opt"> <?php esc_html_e('Fast ZIP (experimental)', 'uwh-site-migrator'); ?></label>
                </div>
                <button class="button button-primary button-hero" id="svm-start" <?php disabled(is_multisite());
                 ?>><?php esc_html_e('Create full backup', 'uwh-site-migrator'); ?></button>
                <button class="button button-secondary button-hero" id="svm-start-drive" <?php disabled(is_multisite() || !$drive_connected);
                 ?>><?php esc_html_e('Create backup & send to Drive', 'uwh-site-migrator'); ?></button>
                <button class="button button-secondary button-hero" id="svm-resume-backup" hidden><?php esc_html_e('Resume interrupted backup', 'uwh-site-migrator'); ?></button>
                <div class="svm-progress-wrap" id="svm-backup-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Preparing…', 'uwh-site-migrator'); ?></strong><span>0%</span></div></div>
                <div class="svm-log" id="svm-backup-log" aria-live="polite"></div>
            </section>

            <section class="svm-card" id="svm-restore">
                <div class="svm-card-head"><span class="dashicons dashicons-migrate"></span><div><h2><?php esc_html_e('Import / restore', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Upload a UWH Site Migrator backup (.uwh) and restore it on this WordPress installation.', 'uwh-site-migrator'); ?></p></div></div>
                <label class="svm-drop" id="svm-file-drop"><input type="file" id="svm-file" accept=".uwh"><span class="dashicons dashicons-upload"></span><b><?php esc_html_e('Choose website backup (.uwh)', 'uwh-site-migrator');
                 ?></b><small><?php esc_html_e('Select a .uwh backup created by UWH Site Migrator.', 'uwh-site-migrator'); ?></small></label>
                <div class="svm-selected-file" id="svm-selected-file" hidden aria-live="polite">
                    <div class="svm-selected-file-badge"><span class="dashicons dashicons-yes-alt"></span><?php esc_html_e('Backup selected', 'uwh-site-migrator'); ?></div>
                    <div class="svm-selected-file-main">
                        <strong id="svm-selected-file-name">—</strong>
                        <span id="svm-selected-file-meta"><?php esc_html_e('Ready for restore', 'uwh-site-migrator'); ?></span>
                    </div>
                    <button type="button" class="button button-secondary" id="svm-selected-file-clear"><?php esc_html_e('Remove', 'uwh-site-migrator'); ?></button>
                </div>
                <div class="svm-progress-wrap" id="svm-restore-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator'); ?></strong><span>0%</span></div></div>
                <label class="svm-checkpoint-option"><input type="checkbox" id="svm-restore-checkpoint" checked><span><strong><?php esc_html_e('Create restore safety checkpoint', 'uwh-site-migrator'); ?></strong><small><?php esc_html_e('Optional: save the existing website first. If unchecked, failed restores have no automatic rollback or recovery checkpoint.', 'uwh-site-migrator'); ?></small></span></label>
                <label class="svm-confirm"><input type="checkbox" id="svm-confirm"> <?php esc_html_e('I understand that restore will replace this site\'s database and website files.', 'uwh-site-migrator'); ?></label>
                <button class="button button-primary button-hero" id="svm-upload" disabled><?php esc_html_e('Upload and restore', 'uwh-site-migrator'); ?></button>
                <button type="button" class="button button-secondary button-hero svm-cancel-restore" id="svm-local-cancel-current" hidden><?php esc_html_e('Cancel / clear restore', 'uwh-site-migrator'); ?></button>
                <button class="button button-secondary button-hero" id="svm-resume-restore" hidden><?php esc_html_e('Resume interrupted restore', 'uwh-site-migrator'); ?></button>
                <?php $this->render_pending_restore_controls($engine->pending_restores()); ?>
                <?php foreach ($engine->pending_restores() as $pending): ?><p><button type="button" class="button svm-recover-restore" data-restore="<?php echo esc_attr($pending['id']);
                 ?>"><?php esc_html_e('Resume saved restore', 'uwh-site-migrator');
                 ?></button> <small><?php echo esc_html($pending['phase'] . (!empty($pending['error']) ? ' — ' . $pending['error'] : ''));
                 ?></small></p><?php endforeach; ?>
                <div class="svm-log" id="svm-restore-log" aria-live="polite"></div>
            <?php $this->render_restore_success(); ?>
            </section>

            <section class="svm-card svm-list svm-grid-wide svm-local-backups svm-backup-list">
                <div class="svm-card-head"><span class="dashicons dashicons-download"></span><div><h2><?php esc_html_e('Export local backup', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Restore, download, send to Drive or delete completed local backups.', 'uwh-site-migrator'); ?></p></div></div>
                <table><thead><tr><th><?php esc_html_e('Backup', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Status', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Created', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Size', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Actions', 'uwh-site-migrator'); ?></th></tr></thead><tbody>
                <?php $complete_count = 0;
                 foreach ($backups as $backup): if (!$backup['complete']) {
             continue;
        }
         $complete_count++;
                 $transfer = UWHSM_Drive_Transfer::status_for_backup($backup['name']); ?>
                    <tr data-backup="<?php echo esc_attr($backup['name']);
                     ?>" data-transfer-id="<?php echo esc_attr($transfer['transfer_id'] ?? ''); ?>">
                        <td><strong><?php echo esc_html($backup['name']); ?></strong><?php if (($backup['origin'] ?? '') === 'direct-migration'): ?><span class="svm-status is-complete svm-migrated-label"><?php esc_html_e('Migrated source backup', 'uwh-site-migrator'); ?></span><?php endif; ?></td>
                        <td><div class="svm-status-stack"><span class="svm-status is-complete"><?php esc_html_e('Complete', 'uwh-site-migrator'); ?></span><?php if ($transfer && in_array($transfer['status'] ?? '', array('queued', 'uploading', 'waiting'), true)): ?><span class="svm-status is-working"><?php esc_html_e('Uploading', 'uwh-site-migrator'); ?></span><?php endif; ?></div></td>
                        <td><?php echo esc_html($backup['date']); ?></td>
                        <td><?php echo esc_html(size_format($backup['size'], 2)); ?></td>
                        <td>
                            <div class="svm-row-actions">
                                <button type="button" class="button svm-restore-local" data-backup="<?php echo esc_attr($backup['name']);
                                 ?>"><?php esc_html_e('Restore', 'uwh-site-migrator'); ?></button>
                                <a class="button" href="<?php echo esc_url($backup['download']);
                                 ?>"><?php esc_html_e('Download', 'uwh-site-migrator'); ?></a>
                                <button type="button" class="button svm-send-drive" data-backup="<?php echo esc_attr($backup['name']);
                                 ?>" <?php disabled(!$drive_connected);
                                 ?>><?php echo esc_html($transfer && in_array($transfer['status'], array('queued', 'uploading', 'waiting'), true) ? __('Resume Drive upload', 'uwh-site-migrator') : __('Send to Drive', 'uwh-site-migrator')); ?></button>
                            <button type="button" class="button svm-delete" name="backup_name" value="<?php echo esc_attr($backup['name']);
                                 ?>" data-backup="<?php echo esc_attr($backup['name']);
                                 ?>"><?php esc_html_e('Delete', 'uwh-site-migrator'); ?></button>
                            </div>
                            <div class="svm-progress-wrap svm-row-progress svm-drive-row-progress" hidden>
                                <div class="svm-progress"><i></i></div>
                                <div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator'); ?></strong><span>0%</span></div>
                                <p class="svm-row-transfer-meta"></p>
                                <p class="svm-log svm-drive-row-error" aria-live="polite"></p>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$complete_count): ?><tr class="svm-empty"><td colspan="5"><?php esc_html_e('No completed local backup is available.', 'uwh-site-migrator');
                 ?></td></tr><?php endif; ?>
                </tbody></table>
            </section>

            <section class="svm-card svm-list svm-grid-wide" id="svm-checkpoints">
                <div class="svm-card-head"><span class="dashicons dashicons-backup"></span><div><h2><?php esc_html_e('Restore safety checkpoints', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Optional full-site safety backups created before selected restores. The newest three are retained.', 'uwh-site-migrator'); ?></p></div></div>
                <table><thead><tr><th><?php esc_html_e('Checkpoint', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Created', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Size', 'uwh-site-migrator');
                 ?></th><th><?php esc_html_e('Actions', 'uwh-site-migrator'); ?></th></tr></thead><tbody>
                <?php if (!$checkpoints): ?><tr class="svm-empty"><td colspan="4"><?php esc_html_e('No restore checkpoint has been created yet.', 'uwh-site-migrator');
                 ?></td></tr><?php endif; ?>
                <?php foreach ($checkpoints as $checkpoint): ?>
                    <tr data-checkpoint="<?php echo esc_attr($checkpoint['name']); ?>">
                        <td><strong><?php echo esc_html($checkpoint['name']); ?></strong></td>
                        <td><?php echo esc_html($checkpoint['date']); ?></td>
                        <td><?php echo esc_html(size_format($checkpoint['size'], 2)); ?></td>
                        <td><div class="svm-row-actions">
                            <button type="button" class="button button-primary svm-checkpoint-rollback" data-checkpoint="<?php echo esc_attr($checkpoint['name']);
                             ?>"><?php esc_html_e('Rollback', 'uwh-site-migrator'); ?></button>
                            <button type="button" class="button svm-checkpoint-delete" data-checkpoint="<?php echo esc_attr($checkpoint['name']);
                             ?>"><?php esc_html_e('Delete', 'uwh-site-migrator'); ?></button>
                        </div></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </section>
        </div>
        <?php
        $this->render_local_restore_modal();
        $this->page_end();
    }

    public function page_direct_migration() {
        if (!$this->guard_page()) {
             return;
        }
        $engine = UWHSM_Engine::instance();
         $backups = array_values(array_filter($engine->list_backups(), static function($row) {
             return !empty($row['complete']);
        }));
        $this->page_start(__('Direct Migration', 'uwh-site-migrator'), __('Move a WordPress site server-to-server without downloading and re-uploading the backup manually.', 'uwh-site-migrator'), 'direct');
        ?>
        <div class="svm-grid">
            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-upload"></span><div><h2><?php esc_html_e('1. Source site', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Prepare a full package, then generate an expiring secure migration key.', 'uwh-site-migrator'); ?></p></div></div>
                <button type="button" class="button button-primary button-hero" id="svm-direct-source-prepare" <?php disabled(is_multisite());
                 ?>><?php esc_html_e('Prepare new migration package', 'uwh-site-migrator'); ?></button>
                <div class="svm-progress-wrap" id="svm-direct-source-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Preparing…', 'uwh-site-migrator'); ?></strong><span>0%</span></div></div>
                <div class="svm-log" id="svm-direct-source-log" aria-live="polite"></div>
                <div class="svm-direct-existing">
                    <label for="svm-direct-backup"><strong><?php esc_html_e('Or use an existing completed backup', 'uwh-site-migrator'); ?></strong></label>
                    <select id="svm-direct-backup"><option value=""><?php esc_html_e('Choose backup', 'uwh-site-migrator');
                     ?></option><?php foreach ($backups as $backup): ?><option value="<?php echo esc_attr($backup['name']);
                     ?>"><?php echo esc_html($backup['name'] . ' · ' . size_format($backup['size'], 2));
                     ?></option><?php endforeach; ?></select>
                    <button type="button" class="button" id="svm-direct-key-create"><?php esc_html_e('Generate secure key', 'uwh-site-migrator'); ?></button>
                </div>
                <div class="svm-direct-key-box" id="svm-direct-key-box" hidden>
                    <label><strong><?php esc_html_e('Source WordPress URL', 'uwh-site-migrator'); ?></strong><input type="text" id="svm-direct-source-url-out" readonly></label>
                    <label><strong><?php esc_html_e('Migration key', 'uwh-site-migrator'); ?></strong><textarea id="svm-direct-key-out" rows="3" readonly></textarea></label>
                    <div class="svm-direct-key-meta"><span class="svm-direct-status-chip is-neutral" id="svm-direct-key-status"><?php esc_html_e('Not generated', 'uwh-site-migrator'); ?></span><span id="svm-direct-key-expiry"></span></div>
                    <div class="svm-row-actions"><button type="button" class="button" id="svm-direct-copy"><?php esc_html_e('Copy connection details', 'uwh-site-migrator');
                     ?></button><button type="button" class="button" id="svm-direct-revoke"><?php esc_html_e('Revoke key', 'uwh-site-migrator'); ?></button></div>
                </div>
            </section>

            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-download"></span><div><h2><?php esc_html_e('2. Destination site', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Enter the source URL and migration key. This server pulls the package directly in resumable chunks.', 'uwh-site-migrator'); ?></p></div></div>
                <div class="svm-direct-destination-form">
                    <label class="svm-field"><strong><?php esc_html_e('Source WordPress URL', 'uwh-site-migrator'); ?></strong><input type="url" id="svm-direct-source-url" placeholder="https://example.com" autocomplete="off" inputmode="url"></label>
                    <label class="svm-field"><strong><?php esc_html_e('Migration key', 'uwh-site-migrator'); ?></strong><textarea id="svm-direct-key" rows="3" placeholder="svm1_…" autocomplete="off" autocapitalize="off" spellcheck="false"></textarea></label>
                    <div class="svm-direct-paste-row">
                        <button type="button" class="button" id="svm-direct-paste"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><?php esc_html_e('Paste connection details', 'uwh-site-migrator'); ?></button>
                        <textarea id="svm-direct-paste-catcher" class="svm-direct-paste-catcher" rows="2" autocomplete="off" autocapitalize="off" spellcheck="false" aria-label="<?php esc_attr_e('Paste connection details', 'uwh-site-migrator'); ?>" hidden></textarea>
                    </div>
                    <div class="svm-direct-test-row">
                        <button type="button" class="button" id="svm-direct-test"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php esc_html_e('Test connection', 'uwh-site-migrator'); ?></button>
                        <span class="svm-direct-status-chip is-neutral" id="svm-direct-readiness"><?php esc_html_e('Not checked', 'uwh-site-migrator'); ?></span>
                        <span class="svm-direct-status-chip is-neutral" id="svm-direct-destination-key-status"><?php esc_html_e('Key not checked', 'uwh-site-migrator'); ?></span>
                    </div>
                    <div class="svm-direct-preflight" id="svm-direct-preflight" hidden>
                        <div class="svm-direct-preflight-head"><strong><?php esc_html_e('Pre-flight check', 'uwh-site-migrator'); ?></strong><span id="svm-direct-preflight-summary"></span></div>
                        <div class="svm-direct-preflight-list" id="svm-direct-preflight-list"></div>
                    </div>
                    <div class="svm-direct-target-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><?php esc_html_e('Destination website:', 'uwh-site-migrator'); ?> <strong id="svm-direct-target-domain"><?php echo esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST)); ?></strong></span></div>
                    <?php $this->render_pending_restore_controls($engine->pending_restores()); ?>
                    <label class="svm-checkpoint-option"><input type="checkbox" id="svm-direct-checkpoint" checked><span><strong><?php esc_html_e('Create restore safety checkpoint', 'uwh-site-migrator'); ?></strong><small><?php esc_html_e('Enabled by default: save this destination before migration. Uncheck to skip the safety checkpoint and automatic rollback.', 'uwh-site-migrator'); ?></small></span></label>
                    <label class="svm-confirm svm-direct-confirm"><input type="checkbox" id="svm-direct-confirm"><span id="svm-direct-confirm-text"><?php esc_html_e('I understand this destination website will be replaced.', 'uwh-site-migrator'); ?></span></label>
                </div>
                <button type="button" class="button button-primary button-hero" id="svm-direct-start" disabled <?php disabled(is_multisite());
                 ?>><?php esc_html_e('Connect & migrate', 'uwh-site-migrator'); ?></button>
                <button type="button" class="button button-secondary button-hero" id="svm-direct-resume" hidden><?php esc_html_e('Resume interrupted direct migration', 'uwh-site-migrator'); ?></button>
                <button type="button" class="button button-secondary button-hero svm-cancel-restore" id="svm-direct-cancel-current" hidden><?php esc_html_e('Cancel / clear this restore', 'uwh-site-migrator'); ?></button>
                <button type="button" class="button button-secondary button-hero" id="svm-resume-restore" hidden><?php esc_html_e('Resume interrupted restore / rollback', 'uwh-site-migrator'); ?></button>
                <div class="svm-direct-missing-plugin-panel notice notice-warning inline" id="svm-direct-missing-plugin-panel" role="alert" hidden>
                    <p><strong><?php esc_html_e('Active plugins missing from the source archive', 'uwh-site-migrator'); ?></strong></p>
                    <p><?php esc_html_e('These plugins are listed as active in the source database, but their main files are absent. They may be obsolete, broken, or suspicious; absence alone does not prove malware. Skipping means they will not be activated on the destination. Other archive, theme, file, and database integrity checks remain required.', 'uwh-site-migrator'); ?></p>
                    <ul id="svm-direct-missing-plugin-list"></ul>
                    <p><button type="button" class="button button-secondary" id="svm-direct-skip-missing-plugins"><?php esc_html_e('Skip missing plugins & resume', 'uwh-site-migrator'); ?></button></p>
                </div>
                <div class="svm-progress-wrap" id="svm-direct-destination-progress" hidden><div class="svm-progress"><i></i></div><div class="svm-progress-meta"><strong><?php esc_html_e('Waiting…', 'uwh-site-migrator'); ?></strong><span>0%</span></div></div>
                <div class="svm-log" id="svm-direct-destination-log" aria-live="polite"></div>
                <?php $this->render_restore_success(true); ?>
            </section>

            <section class="svm-card svm-grid-wide">
                <div class="svm-card-head"><span class="dashicons dashicons-shield-alt"></span><div><h2><?php esc_html_e('Built-in safety', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('HTTPS-only key transfer, expiring source authorization, resumable chunks, package integrity validation, and optional pre-restore safety checkpoints with automatic rollback.', 'uwh-site-migrator'); ?></p></div></div>
            </section>
        </div>
        <?php
        $this->page_end();
    }

    /** Administrator actions for server-side restores, even without localStorage. */
    private function render_pending_restore_controls($pending) {
        if (!$pending) {
            return;
        }
        ?>
        <div class="svm-pending-restores" aria-label="<?php esc_attr_e('Unfinished restores', 'uwh-site-migrator'); ?>">
            <strong><?php esc_html_e('Unfinished restore jobs', 'uwh-site-migrator'); ?></strong>
            <p><?php esc_html_e('The saved restore slot can be cancelled here even if the original browser or resume token is missing. Cancelling cannot undo changes already applied to the website.', 'uwh-site-migrator'); ?></p>
            <?php foreach ($pending as $item): ?>
                <div class="svm-pending-restore-item">
                    <span><?php echo esc_html(__('Phase:', 'uwh-site-migrator') . ' ' . $item['phase'] . ' · ' . substr($item['id'], 0, 10)); ?></span>
                    <button type="button" class="button svm-cancel-restore" data-restore="<?php echo esc_attr($item['id']); ?>"><?php esc_html_e('Cancel / clear restore', 'uwh-site-migrator'); ?></button>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function page_drive() {
        if (!$this->guard_page()) {
             return;
        }
        $this->page_start(__('Drive Settings', 'uwh-site-migrator'), __('Connect Google Drive, review cloud backups and manage Drive transfer recovery.', 'uwh-site-migrator'), 'drive');
        UWHSM_Drive::render();
        UWHSM_Drive::render_auto_backup_settings();
        $this->page_end();
    }

    private function diagnostic_snapshot() {
        $engine = UWHSM_Engine::instance();
        $base = $engine->integration_directory();
        $free = @disk_free_space($base);
        $total = @disk_total_space($base);
        $auto_frequency = (string) get_option('uwhsm_auto_backup_frequency', 'none');
        $auto_next = wp_next_scheduled('uwhsm_auto_backup_event');
        $background_jobs = $engine->background_jobs();
        $active_background = 0;
         $last_tick = 0;
         $background_errors = 0;
        foreach ($background_jobs as $job) {
            $background = is_array($job['background'] ?? null) ? $job['background'] : array();
            if (!empty($background['enabled']) && 'done' !== ($job['phase'] ?? '')) {
                 $active_background++;
            }
            $last_tick = max($last_tick, (int) ($background['last_tick'] ?? 0));
            if (!empty($background['error'])) {
                 $background_errors++;
            }
        }
        try {
             $drive = UWHSM_Drive::status();
         $drive_error = '';
        }
        catch (Throwable $error) {
             $drive = array('connected' => false);
         $drive_error = $error->getMessage();
        }
        $checkpoints = $engine->list_checkpoints();
        $pending_restores = $engine->pending_restores();
        $logs = UWHSM_Logger::summary();
        $memory_limit = ini_get('memory_limit');
        $max_execution = ini_get('max_execution_time');
        $upload_max = ini_get('upload_max_filesize');
        $post_max = ini_get('post_max_size');
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only diagnostics must check the actual local filesystem permissions without changing them.
        $storage_writable = is_dir($base) && is_writable($base);
        $checks = array(
            array('label' => __('PHP 7.4+', 'uwh-site-migrator'), 'ok' => version_compare(PHP_VERSION, '7.4', '>='), 'value' => PHP_VERSION),
            array('label' => __('64-bit PHP', 'uwh-site-migrator'), 'ok' => PHP_INT_SIZE >= 8, 'value' => PHP_INT_SIZE >= 8 ? __('Available', 'uwh-site-migrator') : __('32-bit', 'uwh-site-migrator')),
            array('label' => __('ZipArchive', 'uwh-site-migrator'), 'ok' => class_exists('ZipArchive'), 'value' => class_exists('ZipArchive') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator')),
            array('label' => __('OpenSSL', 'uwh-site-migrator'), 'ok' => extension_loaded('openssl'), 'value' => extension_loaded('openssl') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator')),
            array('label' => __('cURL', 'uwh-site-migrator'), 'ok' => extension_loaded('curl'), 'value' => extension_loaded('curl') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator'), 'optional' => true),
            array('label' => __('Backup storage', 'uwh-site-migrator'), 'ok' => $storage_writable, 'value' => $storage_writable ? __('Writable', 'uwh-site-migrator') : __('Not writable', 'uwh-site-migrator')),
            array('label' => __('Free disk', 'uwh-site-migrator'), 'ok' => false === $free || $free >= 512*1024*1024, 'value' => false === $free ? __('Host did not report', 'uwh-site-migrator') : size_format($free, 2)),
            array('label' => __('WP-Cron', 'uwh-site-migrator'), 'ok' => !$cron_disabled || (bool) $last_tick, 'value' => $cron_disabled ? ($last_tick ? __('Host cron detected', 'uwh-site-migrator') : __('Page cron disabled', 'uwh-site-migrator')) : __('Enabled', 'uwh-site-migrator')),
            array('label' => __('Google Drive', 'uwh-site-migrator'), 'ok' => !empty($drive['connected']), 'value' => !empty($drive['connected']) ? __('Connected', 'uwh-site-migrator') : __('Not connected', 'uwh-site-migrator'), 'optional' => true),
        );
        $required_failures = 0;
        foreach ($checks as $check) {
             if (empty($check['optional']) && empty($check['ok'])) {
                 $required_failures++;
            }

        }
        return array(
            'engine' => $engine, 'base' => $base, 'free' => $free, 'total' => $total, 'auto_frequency' => $auto_frequency,
            'auto_next' => $auto_next, 'background_jobs' => $background_jobs, 'active_background' => $active_background,
            'background_errors' => $background_errors, 'last_tick' => $last_tick, 'drive' => $drive, 'drive_error' => $drive_error,
            'checkpoints' => $checkpoints, 'pending_restores' => $pending_restores, 'logs' => $logs, 'memory_limit' => $memory_limit,
            'max_execution' => $max_execution, 'upload_max' => $upload_max, 'post_max' => $post_max, 'cron_disabled' => $cron_disabled,
            'checks' => $checks, 'required_failures' => $required_failures,
        );
    }

    public function activity_logs_action() {
        if (!current_user_can('manage_options')) {
             wp_die(esc_html__('Administrator permission required.', 'uwh-site-migrator'));
        }
        UWHSM_Compat::verify_admin_post_nonce('uwhsm_activity_logs');
        $operation = sanitize_key(UWHSM_Compat::post_value('operation'));
        if ('clear' === $operation) {
            UWHSM_Logger::clear();
            UWHSM_Logger::log('system', 'info', __('Activity log was cleared by an administrator.', 'uwh-site-migrator'));
            wp_safe_redirect(admin_url('admin.php?page=site-migrator-logs&cleared=1'));
             exit;
        }
        if ('export' === $operation) {
            $rows = UWHSM_Logger::recent(array('limit' => 500));
            $filename = 'site-migrator-activity-' . gmdate('Y-m-d-His') . '.csv';
            nocache_headers();
             header('Content-Type: text/csv; charset=utf-8');
             header('Content-Disposition: attachment; filename="' . $filename . '"');
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Native output stream is required for memory-safe CSV download streaming.
            $out = fopen('php://output', 'w');
             fputcsv($out, array(__('Time UTC', 'uwh-site-migrator'), __('Category', 'uwh-site-migrator'), __('Status', 'uwh-site-migrator'), __('Message', 'uwh-site-migrator'), __('User', 'uwh-site-migrator'), __('Context', 'uwh-site-migrator')));
            foreach ($rows as $row) {
                fputcsv($out, array(gmdate('Y-m-d H:i:s', (int) ($row['timestamp'] ?? 0)), $row['category'] ?? '', $row['status'] ?? '', $row['message'] ?? '', $row['user'] ?? '', wp_json_encode($row['context'] ?? array())));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native stream handles are required for file locking and memory-safe chunked archive I/O.
            fclose($out);
             exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=site-migrator-logs'));
         exit;
    }

    public function download_diagnostic_report() {
        if (!current_user_can('manage_options')) {
             wp_die(esc_html__('Administrator permission required.', 'uwh-site-migrator'));
        }
        UWHSM_Compat::verify_admin_post_nonce('uwhsm_diagnostic_report');
        $d = $this->diagnostic_snapshot();
        $lines = array(
            __('SITE MIGRATOR DIAGNOSTIC REPORT', 'uwh-site-migrator'),
            __('Generated UTC: ', 'uwh-site-migrator') . gmdate('Y-m-d H:i:s'),
            __('Plugin version: ', 'uwh-site-migrator') . UWHSM_VERSION,
            __('WordPress version: ', 'uwh-site-migrator') . get_bloginfo('version'),
            __('PHP: ', 'uwh-site-migrator') . PHP_VERSION . (PHP_INT_SIZE >= 8 ? __(' 64-bit', 'uwh-site-migrator') : __(' 32-bit', 'uwh-site-migrator')),
            __('Site URL: ', 'uwh-site-migrator') . site_url('/'),
            __('Home URL: ', 'uwh-site-migrator') . home_url('/'),
            __('Multisite: ', 'uwh-site-migrator') . (is_multisite() ? __('Yes', 'uwh-site-migrator') : __('No', 'uwh-site-migrator')),
            __('Memory limit: ', 'uwh-site-migrator') . $d['memory_limit'],
            __('Max execution time: ', 'uwh-site-migrator') . $d['max_execution'],
            __('Upload max: ', 'uwh-site-migrator') . $d['upload_max'],
            __('POST max: ', 'uwh-site-migrator') . $d['post_max'],
            __('ZipArchive: ', 'uwh-site-migrator') . (class_exists('ZipArchive') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator')),
            __('OpenSSL: ', 'uwh-site-migrator') . (extension_loaded('openssl') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator')),
            __('cURL: ', 'uwh-site-migrator') . (extension_loaded('curl') ? __('Available', 'uwh-site-migrator') : __('Missing', 'uwh-site-migrator')),
            __('Storage path: ', 'uwh-site-migrator') . $d['base'],
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only diagnostics must check the actual local filesystem permissions without changing them.
            __('Storage writable: ', 'uwh-site-migrator') . (is_writable($d['base']) ? __('Yes', 'uwh-site-migrator') : __('No', 'uwh-site-migrator')),
            __('Free disk: ', 'uwh-site-migrator') . (false === $d['free'] ? __('Unknown', 'uwh-site-migrator') : size_format($d['free'], 2)),
            __('Automatic backup: ', 'uwh-site-migrator') . $d['auto_frequency'],
            __('Next automatic backup: ', 'uwh-site-migrator') . ($d['auto_next'] ? gmdate('Y-m-d H:i:s', (int) $d['auto_next']) . ' ' . __('UTC', 'uwh-site-migrator') : __('Not scheduled', 'uwh-site-migrator')),
            __('WP-Cron disabled: ', 'uwh-site-migrator') . ($d['cron_disabled'] ? __('Yes', 'uwh-site-migrator') : __('No', 'uwh-site-migrator')),
            __('Last Site Migrator background tick: ', 'uwh-site-migrator') . ($d['last_tick'] ? gmdate('Y-m-d H:i:s', $d['last_tick']) . ' ' . __('UTC', 'uwh-site-migrator') : __('None recorded', 'uwh-site-migrator')),
            __('Active background jobs: ', 'uwh-site-migrator') . $d['active_background'],
            __('Background errors: ', 'uwh-site-migrator') . $d['background_errors'],
            __('Pending restores: ', 'uwh-site-migrator') . count($d['pending_restores']),
            __('Safety checkpoints: ', 'uwh-site-migrator') . count($d['checkpoints']),
            __('Google Drive: ', 'uwh-site-migrator') . (!empty($d['drive']['connected']) ? __('Connected', 'uwh-site-migrator') : __('Not connected', 'uwh-site-migrator')),
            __('Activity log rows: ', 'uwh-site-migrator') . (int) $d['logs']['total'],
            __('Logged failures: ', 'uwh-site-migrator') . (int) $d['logs']['failed'],
            '', __('RECENT FAILURES', 'uwh-site-migrator')
        );
        foreach (UWHSM_Logger::recent(array('status' => 'failed', 'limit' => 20)) as $row) {
            $lines[] = gmdate('Y-m-d H:i:s', (int) $row['timestamp']) . ' ' . __('UTC', 'uwh-site-migrator') . ' | ' . strtoupper($row['category']) . ' | ' . $row['message'];
        }
        $perf = UWHSM_Engine::instance()->backup_archive_diagnostics();
        $lines[] = '';
        $lines[] = __('BACKUP ARCHIVE PERFORMANCE (LAST 8 BATCHES)', 'uwh-site-migrator');
        $lines[] = __('Backup: ', 'uwh-site-migrator') . (string) $perf['name'];
        foreach ((array) $perf['samples'] as $sample) {
            $lines[] = gmdate('Y-m-d H:i:s', (int) ($sample['at'] ?? 0)) . ' UTC | files=' . (int) ($sample['files'] ?? 0)
                . ' bytes=' . (int) ($sample['bytes'] ?? 0)
                . ' open_s=' . (float) ($sample['open_s'] ?? 0)
                . ' checksum_s=' . (float) ($sample['checksum_s'] ?? 0)
                . ' queue_s=' . (float) ($sample['enqueue_s'] ?? 0)
                . ' close_s=' . (float) ($sample['close_s'] ?? 0)
                . ' total_s=' . (float) ($sample['total_s'] ?? 0)
                . ' MiB_per_s=' . (float) ($sample['rate_mib_s'] ?? 0)
                . ' limit_bytes=' . (int) ($sample['limit_bytes'] ?? 0)
                . ' limit_files=' . (int) ($sample['limit_files'] ?? 0);
        }
        $lines[] = '';
         $lines[] = __('Security note: migration keys, restore tokens, OAuth access tokens and passwords are intentionally excluded.', 'uwh-site-migrator');
        $filename = 'site-migrator-diagnostics-' . gmdate('Y-m-d-His') . '.txt';
        nocache_headers();
         header('Content-Type: text/plain; charset=utf-8');
         header('Content-Disposition: attachment; filename="' . $filename . '"');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw output is a binary/plain-text download stream; escaping would corrupt the downloaded content.
        echo implode("\n", $lines);
         exit;
    }

    public function page_activity_logs() {
        if (!$this->guard_page()) {
             return;
        }
        $category = sanitize_key(UWHSM_Compat::get_value('category'));
        $status = sanitize_key(UWHSM_Compat::get_value('status'));
        $search = UWHSM_Compat::get_value('s');
        $rows = UWHSM_Logger::recent(array('category' => $category, 'status' => $status, 'search' => $search, 'limit' => 250));
        $summary = UWHSM_Logger::summary();
        $this->page_start(__('Activity / Backup Logs', 'uwh-site-migrator'), __('Review backup, restore, Drive, migration, rollback and automation activity from one place.', 'uwh-site-migrator'), 'logs');
        ?>
        <section class="svm-dashboard-overview svm-log-overview" aria-label="<?php echo esc_attr__('Activity log overview', 'uwh-site-migrator'); ?>">
            <article class="svm-dashboard-stat"><span class="svm-dashboard-stat-icon dashicons dashicons-list-view"></span><div><small><?php esc_html_e('Stored Events', 'uwh-site-migrator');
             ?></small><strong><?php echo esc_html((string) $summary['total']);
             ?></strong><span><?php esc_html_e('Latest 500 retained', 'uwh-site-migrator'); ?></span></div></article>
            <article class="svm-dashboard-stat"><span class="svm-dashboard-stat-icon dashicons dashicons-yes-alt"></span><div><small><?php esc_html_e('Successful', 'uwh-site-migrator');
             ?></small><strong><?php echo esc_html((string) $summary['success']);
             ?></strong><span><?php esc_html_e('Completed operations', 'uwh-site-migrator'); ?></span></div></article>
            <article class="svm-dashboard-stat <?php echo $summary['failed'] ? 'is-warning' : 'is-connected';
             ?>"><span class="svm-dashboard-stat-icon dashicons dashicons-warning"></span><div><small><?php esc_html_e('Failures', 'uwh-site-migrator');
             ?></small><strong><?php echo esc_html((string) $summary['failed']);
             ?></strong><span><?php echo esc_html($summary['failed'] ? __('Review failed events below', 'uwh-site-migrator') : __('No logged failures', 'uwh-site-migrator')); ?></span></div></article>
            <article class="svm-dashboard-stat"><span class="svm-dashboard-stat-icon dashicons dashicons-clock"></span><div><small><?php esc_html_e('Last Activity', 'uwh-site-migrator');
             ?></small><strong class="svm-dashboard-date"><?php echo $summary['last_timestamp'] ? esc_html(wp_date('d M Y · H:i', (int) $summary['last_timestamp'])) : esc_html__('No activity', 'uwh-site-migrator');
             ?></strong><span><?php esc_html_e('Site Migrator operations', 'uwh-site-migrator'); ?></span></div></article>
        </section>

        <section class="svm-card svm-log-toolbar">
            <form method="get" class="svm-log-filters">
                <input type="hidden" name="page" value="site-migrator-logs">
                <select name="category"><option value=""><?php esc_html_e('All categories', 'uwh-site-migrator');
                 ?></option><?php foreach (array('backup', 'restore', 'rollback', 'checkpoint', 'drive', 'migration', 'schedule', 'system') as $item): ?><option value="<?php echo esc_attr($item);
                 ?>" <?php selected($category, $item);
                 ?>><?php echo esc_html(ucfirst($item));
                 ?></option><?php endforeach; ?></select>
                <select name="status"><option value=""><?php esc_html_e('All statuses', 'uwh-site-migrator');
                 ?></option><?php foreach (array('started', 'success', 'failed', 'warning', 'info', 'stopped') as $item): ?><option value="<?php echo esc_attr($item);
                 ?>" <?php selected($status, $item);
                 ?>><?php echo esc_html(ucfirst($item));
                 ?></option><?php endforeach; ?></select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search logs…">
                <button class="button"><?php esc_html_e('Filter', 'uwh-site-migrator');
                 ?></button><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=site-migrator-logs'));
                 ?>"><?php esc_html_e('Reset', 'uwh-site-migrator'); ?></a>
            </form>
            <div class="svm-log-tools">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));
                 ?>"><input type="hidden" name="action" value="uwhsm_activity_logs_action"><input type="hidden" name="operation" value="export"><?php wp_nonce_field('uwhsm_activity_logs');
                 ?><button class="button"><?php esc_html_e('Export CSV', 'uwh-site-migrator'); ?></button></form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));
                 ?>" onsubmit="return confirm('Clear the stored Site Migrator activity log?');"><input type="hidden" name="action" value="uwhsm_activity_logs_action"><input type="hidden" name="operation" value="clear"><?php wp_nonce_field('uwhsm_activity_logs');
                 ?><button class="button"><?php esc_html_e('Clear logs', 'uwh-site-migrator'); ?></button></form>
            </div>
        </section>

        <section class="svm-card svm-list svm-activity-list">
            <div class="svm-card-head"><span class="dashicons dashicons-clipboard"></span><div><h2><?php esc_html_e('Recent activity', 'uwh-site-migrator');
             ?></h2><p><?php esc_html_e('Keys, OAuth tokens and restore authorization secrets are never written to this log.', 'uwh-site-migrator'); ?></p></div></div>
            <table><thead><tr><th><?php esc_html_e('Time', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Activity', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Status', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('Details', 'uwh-site-migrator');
             ?></th><th><?php esc_html_e('User', 'uwh-site-migrator'); ?></th></tr></thead><tbody>
            <?php if (!$rows): ?><tr class="svm-empty"><td colspan="5"><?php esc_html_e('No activity matches the selected filters.', 'uwh-site-migrator');
             ?></td></tr><?php endif; ?>
            <?php foreach ($rows as $row): $context = is_array($row['context'] ?? null) ? $row['context'] : array(); ?>
                <tr><td><span class="svm-log-time"><?php echo esc_html(wp_date('d M Y · H:i', (int) ($row['timestamp'] ?? 0))); ?></span></td>
                <td><strong><?php echo esc_html($this->log_category_label((string) ($row['category'] ?? 'system')));
                 ?></strong><span class="svm-log-message"><?php echo esc_html((string) ($row['message'] ?? '')); ?></span></td>
                <td><span class="svm-log-status is-<?php echo esc_attr((string) ($row['status'] ?? 'info'));
                 ?>"><?php echo esc_html($this->log_status_label((string) ($row['status'] ?? 'info'))); ?></span></td>
                <td><?php if ($context): ?><div class="svm-log-context"><?php foreach ($context as $key => $value): ?><span><b><?php echo esc_html(str_replace('_', ' ', (string) $key));
                 ?>:</b> <?php echo esc_html(is_bool($value) ? ($value ? __('Yes', 'uwh-site-migrator') : __('No', 'uwh-site-migrator')) : (string) $value);
                 ?></span><?php endforeach;
                 ?></div><?php else: ?>—<?php endif; ?></td>
                <td><?php echo !empty($row['user']) ? esc_html((string) $row['user']) : '<span class="svm-muted">' . esc_html__('System', 'uwh-site-migrator') . '</span>'; ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
        <?php $this->page_end();
    }

    /** Admin-only, explicitly requested Elementor cache recovery. */
    public function repair_elementor_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Administrator permission required.', 'uwh-site-migrator'));
        }
        UWHSM_Compat::verify_admin_post_nonce('uwhsm_repair_elementor');
        if (get_option('uwhsm_restore_guard', false)) {
            $result = array('status' => 'warning', 'message' => __('An unfinished restore is still active. Resume it before rebuilding Elementor caches.', 'uwh-site-migrator'));
        } else {
            $logo_result = UWHSM_Elementor_Repair::repair_logo();
            $result = UWHSM_Elementor_Repair::rebuild();
            if ('warning' === $logo_result['status']) {
                $result = $logo_result;
            } elseif ('warning' !== $result['status']) {
                $result['message'] = $logo_result['message'] . ' ' . $result['message'];
            }
            if ('warning' !== $result['status']) {
                $elementor = class_exists('Elementor\Plugin', false) ? \Elementor\Plugin::$instance : null;
                if (is_object($elementor) && isset($elementor->files_manager) && is_callable(array($elementor->files_manager, 'clear_cache'))) {
                    $elementor->files_manager->clear_cache();
                }
                delete_option('uwhsm_elementor_repair_warning_v1');
            } else {
                update_option('uwhsm_elementor_repair_warning_v1', $result['message'], false);
            }
        }
        set_transient('uwhsm_elementor_repair_result_' . get_current_user_id(), $result, 180);
        UWHSM_Logger::log('restore', $result['status'], $result['message']);
        wp_safe_redirect(admin_url('admin.php?page=site-migrator-troubleshooting#uwhsm-elementor-diagnostics'));
        exit;
    }

    /** Non-AJAX control remains accessible even when a restore signs the admin out. */
    public function maintenance_control() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('Administrator permission required.', 'uwh-site-migrator'), '', array('response' => 403)); }
        check_admin_referer('uwhsm_maintenance_control');
        $operation = sanitize_key(UWHSM_Compat::post_value('operation'));
        if (!in_array($operation, array('on', 'off'), true)) { wp_die(esc_html__('Invalid maintenance operation.', 'uwh-site-migrator')); }
        if ('off' === $operation && '1' !== UWHSM_Compat::post_value('acknowledge')) {
            $result = new WP_Error('confirmation_required', __('Please confirm that unfinished restores may remain incomplete.', 'uwh-site-migrator'));
        } else {
            $result = UWHSM_Engine::instance()->set_maintenance_mode('on' === $operation);
        }
        $query = is_wp_error($result)
            ? array('uwhsm_maintenance_result' => 'error', 'uwhsm_maintenance_detail' => $result->get_error_message())
            : array('uwhsm_maintenance_result' => 'success', 'uwhsm_maintenance_detail' => 'on' === $operation ? 'on' : 'off');
        wp_safe_redirect(add_query_arg($query, admin_url('admin.php?page=site-migrator-troubleshooting')) . '#uwhsm-maintenance');
        exit;
    }

    public function page_troubleshooting() {
        if (!$this->guard_page()) {
             return;
        }
        $d = $this->diagnostic_snapshot();
        $maintenance = UWHSM_Engine::instance()->maintenance_status();
        $failed_logs = UWHSM_Logger::recent(array('status' => 'failed', 'limit' => 8));
        $archive_perf = UWHSM_Engine::instance()->backup_archive_diagnostics();
        $this->page_start(__('Troubleshooting & Diagnostics', 'uwh-site-migrator'), __('Review migration readiness, maintenance access, server limits and recent failures.', 'uwh-site-migrator'), 'troubleshooting');
        ?>
        <section class="svm-diagnostic-hero <?php echo $d['required_failures'] ? 'has-issues' : 'is-ready'; ?>">
            <div><span class="dashicons <?php echo $d['required_failures'] ? 'dashicons-warning' : 'dashicons-yes-alt';
             ?>"></span><div><small><?php esc_html_e('Overall readiness', 'uwh-site-migrator');
             ?></small><strong><?php
             if ($d['required_failures']) {
                 /* translators: %d: number of required diagnostic checks that need attention. */
                 echo esc_html(sprintf(_n('%d required check needs attention', '%d required checks need attention', (int) $d['required_failures'], 'uwh-site-migrator'), (int) $d['required_failures']));
             } else {
                 esc_html_e('Core migration requirements look ready', 'uwh-site-migrator');
             }
             ?></strong><p><?php esc_html_e('Checks are read-only. Optional services such as Google Drive do not block local backup or restore.', 'uwh-site-migrator'); ?></p></div></div>
            <div class="svm-diagnostic-actions"><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=site-migrator-troubleshooting'));
             ?>"><?php esc_html_e('Refresh checks', 'uwh-site-migrator');
             ?></a><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));
             ?>"><input type="hidden" name="action" value="uwhsm_diagnostic_report"><?php wp_nonce_field('uwhsm_diagnostic_report');
             ?><button class="button button-primary"><?php esc_html_e('Download diagnostic report', 'uwh-site-migrator'); ?></button></form></div>
        </section>

        <section class="svm-health svm-health-extended">
            <?php foreach ($d['checks'] as $item): ?>
                <?php // Keep ZipArchive in required-readiness calculations and diagnostic exports, without a redundant status tile on this page. ?>
                <?php if ('ZipArchive' === $item['label']) { continue; } ?>
                <div class="svm-health-item <?php echo $item['ok'] ? 'is-good' : (!empty($item['optional']) ? 'is-neutral' : 'is-bad'); ?>">
                    <span class="dashicons <?php echo $item['ok'] ? 'dashicons-yes-alt' : (!empty($item['optional']) ? 'dashicons-minus' : 'dashicons-warning'); ?>"></span>
                    <div><small><?php echo esc_html($item['label']); ?><?php echo !empty($item['optional']) ? esc_html__(' · optional', 'uwh-site-migrator') : ''; ?></small>
                    <strong><?php echo esc_html($item['value']); ?></strong></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="svm-card svm-maintenance-card" id="uwhsm-maintenance" aria-labelledby="uwhsm-maintenance-title">
            <div class="svm-maintenance-heading">
                <div class="svm-card-head">
                    <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                    <div>
                        <span class="svm-maintenance-eyebrow"><?php esc_html_e('SITE ACCESS & RECOVERY', 'uwh-site-migrator'); ?></span>
                        <h2 id="uwhsm-maintenance-title"><?php esc_html_e('Maintenance mode control', 'uwh-site-migrator'); ?></h2>
                        <p><?php esc_html_e('Manage visitor access and safely release a stalled migration guard.', 'uwh-site-migrator'); ?></p>
                    </div>
                </div>
                <span class="svm-maintenance-overall <?php echo ($maintenance['manual'] || $maintenance['restore'] || $maintenance['core']) ? 'is-active' : 'is-clear'; ?>">
                    <span class="svm-maintenance-dot" aria-hidden="true"></span>
                    <?php echo ($maintenance['manual'] || $maintenance['restore'] || $maintenance['core'])
                        ? esc_html__('Maintenance detected', 'uwh-site-migrator')
                        : esc_html__('Maintenance not active', 'uwh-site-migrator'); ?>
                </span>
            </div>

            <?php if ('success' === UWHSM_Compat::get_value('uwhsm_maintenance_result')): ?>
                <div class="svm-maintenance-feedback is-success" role="status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e('Maintenance setting saved.', 'uwh-site-migrator'); ?></div>
            <?php elseif ('error' === UWHSM_Compat::get_value('uwhsm_maintenance_result')): ?>
                <div class="svm-maintenance-feedback is-error" role="alert"><span class="dashicons dashicons-warning" aria-hidden="true"></span><?php echo esc_html(UWHSM_Compat::get_value('uwhsm_maintenance_detail')); ?></div>
            <?php endif; ?>

            <div class="svm-maintenance-actions">
                <form class="svm-maintenance-action-panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="uwhsm_maintenance_control">
                    <input type="hidden" name="operation" value="on">
                    <?php wp_nonce_field('uwhsm_maintenance_control'); ?>
                    <div class="svm-maintenance-action-heading">
                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                        <strong><?php esc_html_e('Enable maintenance', 'uwh-site-migrator'); ?></strong>
                    </div>
                    <p><?php esc_html_e('Show visitors the maintenance page. Signed-in administrators can still view the live site unless a restore guard is active.', 'uwh-site-migrator'); ?></p>
                    <div class="svm-maintenance-action-bottom">
                        <button type="submit" class="button svm-maintenance-button" <?php disabled($maintenance['manual']); ?>><?php esc_html_e('Turn maintenance ON', 'uwh-site-migrator'); ?></button>
                    </div>
                </form>
                <form class="svm-maintenance-action-panel svm-maintenance-release" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="uwhsm_maintenance_control">
                    <input type="hidden" name="operation" value="off">
                    <?php wp_nonce_field('uwhsm_maintenance_control'); ?>
                    <div class="svm-maintenance-action-heading">
                        <span class="dashicons dashicons-unlock" aria-hidden="true"></span>
                        <strong><?php esc_html_e('Disable maintenance', 'uwh-site-migrator'); ?></strong>
                    </div>
                    <p><?php esc_html_e('Allow everyone to access the website. A running restore or WordPress core maintenance lock still takes priority.', 'uwh-site-migrator'); ?></p>
                    <label class="svm-maintenance-ack">
                        <input type="checkbox" name="acknowledge" value="1" required>
                        <span><?php esc_html_e('I understand the website may be partially migrated.', 'uwh-site-migrator'); ?></span>
                    </label>
                    <div class="svm-maintenance-action-bottom">
                        <button type="submit" class="button button-primary svm-maintenance-button"><?php esc_html_e('Turn maintenance OFF', 'uwh-site-migrator'); ?></button>
                    </div>
                </form>
            </div>
        </section>

        <div class="svm-grid svm-diagnostics-grid svm-diagnostics-grid-wide">
            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-performance"></span><div><h2><?php esc_html_e('PHP & transfer limits', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Runtime values that commonly affect large backups and imports.', 'uwh-site-migrator'); ?></p></div></div>
                <dl class="svm-diagnostic-list">
                    <div><dt><?php esc_html_e('Memory limit', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['memory_limit']); ?></dd></div>
                    <div><dt><?php esc_html_e('Max execution time', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['max_execution']);
                     ?> <?php esc_html_e('sec', 'uwh-site-migrator'); ?></dd></div>
                    <div><dt><?php esc_html_e('Upload max filesize', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['upload_max']); ?></dd></div>
                    <div><dt><?php esc_html_e('POST max size', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['post_max']); ?></dd></div>
                    <div><dt><?php esc_html_e('WordPress', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html(get_bloginfo('version')); ?></dd></div>
                    <div><dt><?php esc_html_e('Site Migrator', 'uwh-site-migrator');
                     ?></dt><dd>v<?php echo esc_html(UWHSM_VERSION); ?></dd></div>
                </dl>
                <p class="svm-note"><?php esc_html_e('Direct Migration and Drive transfers are chunked, so PHP upload limits mainly affect manual package uploads.', 'uwh-site-migrator'); ?></p>
            </section>

            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-database"></span><div><h2><?php esc_html_e('Storage & recovery', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Local storage used for packages, checkpoints and resumable job state.', 'uwh-site-migrator'); ?></p></div></div>
                <dl class="svm-diagnostic-list">
                    <div><dt><?php esc_html_e('Storage access', 'uwh-site-migrator');
                     // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Read-only diagnostics must check the actual local filesystem permissions without changing them.
                     ?></dt><dd><?php echo esc_html(is_writable($d['base']) ? __('Writable', 'uwh-site-migrator') : __('Not writable', 'uwh-site-migrator')); ?></dd></div>
                    <div><dt><?php esc_html_e('Free disk', 'uwh-site-migrator');
                     ?></dt><dd><?php echo false === $d['free'] ? esc_html__('Host did not report', 'uwh-site-migrator') : esc_html(size_format($d['free'], 2)); ?></dd></div>
                    <div><dt><?php esc_html_e('Total disk', 'uwh-site-migrator');
                     ?></dt><dd><?php echo false === $d['total'] ? esc_html__('Host did not report', 'uwh-site-migrator') : esc_html(size_format($d['total'], 2)); ?></dd></div>
                    <div><dt><?php esc_html_e('Safety checkpoints', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) count($d['checkpoints'])); ?></dd></div>
                    <div><dt><?php esc_html_e('Pending restores', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) count($d['pending_restores'])); ?></dd></div>
                </dl>
                <?php $this->render_pending_restore_controls($d['pending_restores']); ?>
                <p class="svm-note svm-path-note"><strong><?php esc_html_e('Storage path:', 'uwh-site-migrator');
                 ?></strong> <code><?php echo esc_html($d['base']); ?></code></p>
                <div class="svm-storage-drive-reset" role="group" aria-labelledby="svm-drive-reset">
                <h3 id="svm-drive-reset"><?php esc_html_e('Reset Drive connection', 'uwh-site-migrator'); ?></h3>
                <?php UWHSM_Drive::render_notice('site-migrator-troubleshooting'); ?>
                <p class="svm-note"><?php esc_html_e('Clears only the local Google Drive connection. It does not delete backups from Drive.', 'uwh-site-migrator'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));
                 ?>"><input type="hidden" name="action" value="uwhsm_drive_action"><input type="hidden" name="operation" value="reset"><input type="hidden" name="return_page" value="site-migrator-troubleshooting"><?php wp_nonce_field('uwhsm_drive');
                 ?><button class="button" type="submit"><?php esc_html_e('Clear local connection', 'uwh-site-migrator'); ?></button></form>
                </div>
            </section>

            <section class="svm-card svm-diagnostics-automation">
                <div class="svm-card-head"><span class="dashicons dashicons-clock"></span><div><h2><?php esc_html_e('Automation & WP-Cron', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Automatic backups and background processing depend on reliable cron execution.', 'uwh-site-migrator'); ?></p></div></div>
                <dl class="svm-diagnostic-list">
                    <div><dt><?php esc_html_e('Page-triggered WP-Cron', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html($d['cron_disabled'] ? __('Disabled', 'uwh-site-migrator') : __('Enabled', 'uwh-site-migrator')); ?></dd></div>
                    <div><dt><?php esc_html_e('Automatic backup', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html($this->frequency_label((string) $d['auto_frequency'])); ?></dd></div>
                    <div><dt><?php esc_html_e('Next backup event', 'uwh-site-migrator');
                     ?></dt><dd><?php echo $d['auto_next'] ? esc_html(wp_date('d M Y · H:i', (int) $d['auto_next'])) : esc_html__('Not scheduled', 'uwh-site-migrator'); ?></dd></div>
                    <div><dt><?php esc_html_e('Active background jobs', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['active_background']); ?></dd></div>
                    <div><dt><?php esc_html_e('Background job errors', 'uwh-site-migrator');
                     ?></dt><dd><?php echo esc_html((string) $d['background_errors']); ?></dd></div>
                    <div><dt><?php esc_html_e('Last worker tick', 'uwh-site-migrator');
                     ?></dt><dd><?php echo $d['last_tick'] ? esc_html(wp_date('d M Y · H:i', (int) $d['last_tick'])) : esc_html__('No tick recorded', 'uwh-site-migrator'); ?></dd></div>
                </dl>
                <p class="svm-note"><?php esc_html_e('For uninterrupted manual or scheduled backups after closing the tab, run a real hosting cron every minute. Prefer WP-CLI: wp cron event run --due-now --path=/path/to/wordpress --quiet. Alternatively schedule a request to wp-cron.php. Without server cron, backups can wait when the website receives no visits.', 'uwh-site-migrator'); ?></p>
            </section>

            <section class="svm-card">
                <div class="svm-card-head"><span class="dashicons dashicons-warning"></span><div><h2><?php esc_html_e('Recent failures', 'uwh-site-migrator');
                 ?></h2><p><?php esc_html_e('Latest failed operations captured by Activity Logs.', 'uwh-site-migrator'); ?></p></div></div>
                <div class="svm-failure-list">
                    <?php if (!$failed_logs): ?><p class="svm-empty-state"><?php esc_html_e('No failures are currently recorded.', 'uwh-site-migrator');
                     ?></p><?php endif; ?>
                    <?php foreach ($failed_logs as $row): ?><div><span class="svm-log-status is-failed"><?php esc_html_e('Failed', 'uwh-site-migrator');
                     ?></span><p><strong><?php echo esc_html($this->log_category_label((string) $row['category']));
                     ?></strong> — <?php echo esc_html((string) $row['message']);
                     ?><small><?php echo esc_html(wp_date('d M Y · H:i', (int) $row['timestamp']));
                     ?></small></p></div><?php endforeach; ?>
                </div>
                <p><a class="button" href="<?php echo esc_url(admin_url('admin.php?page=site-migrator-logs&status=failed'));
                 ?>"><?php esc_html_e('View failure logs', 'uwh-site-migrator'); ?></a></p>
            </section>

        </div>

        <section class="svm-card" id="uwhsm-archive-performance" style="margin-top:18px">
            <div class="svm-card-head"><span class="dashicons dashicons-chart-line" aria-hidden="true"></span><div>
                <h2><?php esc_html_e('Backup archiving performance', 'uwh-site-migrator'); ?></h2>
                <p><?php esc_html_e('Recent measured file-batch times. These help diagnose slow ZIP close, checksums and disk throughput without changing backup data.', 'uwh-site-migrator'); ?></p>
            </div></div>
            <?php if (empty($archive_perf['samples'])) : ?>
                <p class="svm-note"><?php esc_html_e('No file-batch performance data yet. Start or resume a backup to collect measurements.', 'uwh-site-migrator'); ?></p>
            <?php else : ?>
                <p class="svm-note"><strong><?php esc_html_e('Backup:', 'uwh-site-migrator'); ?></strong> <?php echo esc_html((string) $archive_perf['name']); ?> <span style="margin-left:12px"><strong><?php esc_html_e('ZIP writer:', 'uwh-site-migrator'); ?></strong> <?php echo 'stream-v1' === ($archive_perf['writer'] ?? '') ? esc_html__('Fast append-only (experimental)', 'uwh-site-migrator') : esc_html__('Standard ZipArchive', 'uwh-site-migrator'); ?></span></p>
                <div style="overflow-x:auto;max-width:100%">
                    <table class="widefat striped" style="min-width:860px">
                        <thead><tr>
                            <th><?php esc_html_e('Time', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('Files / data', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('ZIP open', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('Checksum', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('ZIP queue', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('ZIP close', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('Total', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('Input MiB/s', 'uwh-site-migrator'); ?></th>
                            <th><?php esc_html_e('Batch cap', 'uwh-site-migrator'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($archive_perf['samples']) as $sample) : ?>
                            <tr>
                                <td><?php echo esc_html(wp_date('d M H:i:s', (int) ($sample['at'] ?? 0))); ?></td>
                                <td><?php echo esc_html((string) (int) ($sample['files'] ?? 0) . ' / ' . size_format((int) ($sample['bytes'] ?? 0), 2)); ?></td>
                                <?php foreach (array('open_s', 'checksum_s', 'enqueue_s', 'close_s', 'total_s', 'rate_mib_s') as $key) : ?>
                                    <td><?php echo esc_html(number_format_i18n((float) ($sample[$key] ?? 0), 2)); ?></td>
                                <?php endforeach; ?>
                                <td><?php echo esc_html(size_format((int) ($sample['limit_bytes'] ?? 0), 0) . ' / ' . (int) ($sample['limit_files'] ?? 0) . ' files'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="svm-note"><?php esc_html_e('Input MiB/s includes ZIP open/close overhead and is based on uncompressed source bytes. The last 8 samples are shown; the latest 16 remain in the saved backup job. Real server performance varies.', 'uwh-site-migrator'); ?></p>
            <?php endif; ?>
        </section>

        <?php $this->page_end();
    }

}
