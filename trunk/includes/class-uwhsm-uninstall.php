<?php
/**
 * Site Migrator uninstall cleanup policy.
 *
 * Runtime-only options, schedules, transients, and temporary filesystem
 * artifacts are removed. User configuration, completed backups, restore
 * checkpoints, and recovery database state are intentionally preserved.
 *
 * @package Site_Migrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Safe uninstall cleanup routines.
 */
final class UWHSM_Uninstall {
    /**
     * Runtime-only options that are safe to discard on uninstall.
     *
     * @return array<int,string>
     */
    public static function runtime_options() {
        return array(
            'uwhsm_activity_logs_v1',
            'uwhsm_activity_log_dedupe_v1',
            'uwhsm_direct_migration_sessions',
            'uwhsm_drive_transfer_state_ids',
            'uwhsm_drive_import_state_ids',
            'uwhsm_restore_guard',
            'svm_activity_logs_v1',
            'svm_activity_log_dedupe_v1',
            'svm_direct_migration_sessions',
        );
    }

    /**
     * Scheduled runtime hooks that must not survive plugin deletion.
     *
     * @return array<int,string>
     */
    public static function cron_hooks() {
        return array(
            'uwhsm_auto_backup_event',
            'uwhsm_background_tick',
            'uwhsm_drive_transfer_tick',
            'svm_auto_backup_event',
            'svm_background_tick',
            'svm_drive_transfer_tick',
        );
    }

    /**
     * Run the safe uninstall cleanup.
     *
     * @return void
     */
    public static function run() {
        self::delete_drive_transfer_state_options();
        self::delete_runtime_options();
        self::clear_cron_hooks();
        self::delete_runtime_transients();
        self::delete_runtime_files();
    }

    /**
     * Return candidate Site Migrator storage roots without creating them.
     *
     * @return array<int,string>
     */
    public static function storage_roots() {
        $roots = array();

        if ( defined( 'UWHSM_STORAGE_PATH' ) && is_string( UWHSM_STORAGE_PATH ) && '' !== UWHSM_STORAGE_PATH ) {
            $roots[] = UWHSM_STORAGE_PATH;
        }

        if ( function_exists( 'wp_upload_dir' ) ) {
            $uploads = wp_upload_dir();

            if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
                $basedir = rtrim( (string) $uploads['basedir'], '/\\' );
                $roots[] = $basedir . '/uwh-site-migrator';
                $roots[] = $basedir . '/site-migrator';
                $roots[] = $basedir . '/site-vault-migrator';
            }
        }

        $unique = array();

        foreach ( $roots as $root ) {
            $normalized = self::normalize_path( $root );

            if ( '' !== $normalized && ! in_array( $normalized, $unique, true ) ) {
                $unique[] = $normalized;
            }
        }

        return $unique;
    }

    /**
     * Delete only explicitly allowlisted runtime options.
     *
     * @return void
     */
    private static function delete_runtime_options() {
        if ( ! function_exists( 'delete_option' ) ) {
            return;
        }

        foreach ( self::runtime_options() as $option_name ) {
            delete_option( $option_name );
        }
    }

    /**
     * Delete encrypted, in-progress Drive transfer state stored in options.
     *
     * Completed backups and persistent Drive configuration are intentionally
     * preserved; only ephemeral transfer/import progress is removed.
     *
     * @return void
     */
    private static function delete_drive_transfer_state_options() {
        if ( ! function_exists( 'get_option' ) || ! function_exists( 'delete_option' ) ) {
            return;
        }

        $indexes = array(
            'uwhsm_drive_transfer_state_ids' => 'uwhsm_drive_transfer_state_',
            'uwhsm_drive_import_state_ids'   => 'uwhsm_drive_import_state_',
        );

        foreach ( $indexes as $index_option => $state_prefix ) {
            $ids = get_option( $index_option, array() );
            $ids = is_array( $ids ) ? $ids : array();

            foreach ( $ids as $id ) {
                $id = is_scalar( $id ) ? (string) $id : '';
                if ( 1 === preg_match( '/^[a-f0-9]{32,64}$/D', $id ) ) {
                    delete_option( $state_prefix . $id );
                }
            }

            delete_option( $index_option );
        }
    }

    /**
     * Unschedule Site Migrator workers and automatic backup hooks.
     *
     * @return void
     */
    private static function clear_cron_hooks() {
        if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
            return;
        }

        foreach ( self::cron_hooks() as $hook_name ) {
            wp_clear_scheduled_hook( $hook_name );
        }
    }

    /**
     * Remove only transient option rows owned by Site Migrator notices.
     *
     * @return void
     */
    private static function delete_runtime_transients() {
        global $wpdb;

        if (
            ! isset( $wpdb )
            || ! is_object( $wpdb )
            || empty( $wpdb->options )
            || ! method_exists( $wpdb, 'esc_like' )
            || ! method_exists( $wpdb, 'prepare' )
            || ! method_exists( $wpdb, 'get_col' )
            || ! function_exists( 'delete_option' )
        ) {
            return;
        }

        $prefixes = array(
            '_transient_uwhsm_drive_notice_',
            '_transient_timeout_uwhsm_drive_notice_',
            '_transient_svm_drive_notice_',
            '_transient_timeout_svm_drive_notice_',
        );

        foreach ( $prefixes as $prefix ) {
            $like = $wpdb->esc_like( $prefix ) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct table access is required for exact backup/restore/uninstall state; object caching would be stale or inappropriate for this operation.
            $rows = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $like
                )
            );

            if ( ! is_array( $rows ) ) {
                continue;
            }

            foreach ( $rows as $option_name ) {
                if ( is_string( $option_name ) && 0 === strpos( $option_name, $prefix ) ) {
                    delete_option( $option_name );
                }
            }
        }
    }

    /**
     * Delete runtime-only files and directories from known storage roots.
     *
     * Uses WordPress' direct filesystem implementation rather than embedding
     * recursive delete primitives in the plugin's uninstall source.
     *
     * @return void
     */
    private static function delete_runtime_files() {
        $filesystem = self::filesystem();

        if ( ! $filesystem ) {
            return;
        }

        foreach ( self::storage_roots() as $root ) {
            // A stale/custom override could point to WordPress itself or a
            // symlink. Never recursively delete runtime-looking directories
            // unless the candidate is a dedicated, safe storage directory.
            if ( ! self::safe_cleanup_root( $root ) ) {
                continue;
            }

            try {
                $iterator = new DirectoryIterator( $root );
            } catch ( Exception $error ) {
                continue;
            }

            foreach ( $iterator as $item ) {
                if ( $item->isDot() ) {
                    continue;
                }

                $name = $item->getFilename();
                $path = self::normalize_path( $item->getPathname() );

                if ( ! self::is_runtime_path_name( $name ) ) {
                    continue;
                }

                if ( ! self::path_is_inside_root( $path, $root ) ) {
                    continue;
                }

                $filesystem->delete( $path, true );
            }
        }
    }

    /**
     * Ensure uninstall never deletes folders from WordPress core, content,
     * uploads, plugin/theme code, or their ancestors. Resolve symlinks first.
     * Uninstall does not create or relocate any storage directory.
     *
     * @param string $root Configured or legacy storage directory.
     * @return bool
     */
    private static function safe_cleanup_root( $root ) {
        $root = self::normalize_path( $root );
        if ( '' === $root || ! is_dir( $root ) || is_link( $root ) ) {
            return false;
        }
        $resolved = realpath( $root );
        if ( false === $resolved ) {
            return false;
        }
        $resolved = self::normalize_path( $resolved );
        $uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
        $plugin_file = dirname( __DIR__ ) . '/uwh-site-migrator.php';
        $plugin_root = function_exists( 'plugin_dir_path' ) ? dirname( self::normalize_path( plugin_dir_path( $plugin_file ) ) ) : '';
        $protected_roots = array(
            // ABSPATH / WP_CONTENT_DIR are canonical physical WordPress locations,
            // necessary here to reject dangerous legacy storage overrides.
            // phpcs:ignore PluginCheck.CodeAnalysis.DirectConstants -- Uninstall must protect WordPress filesystem roots, including custom WP_CONTENT_DIR installs.
            defined( 'ABSPATH' ) ? ABSPATH : '',
            // phpcs:ignore PluginCheck.CodeAnalysis.DirectConstants -- Guard configured custom content root rather than assuming wp-content.
            defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
            is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '',
            $plugin_root,
        );
        foreach ( $protected_roots as $candidate ) {
            if ( '' === $candidate ) {
                continue;
            }
            $real = realpath( $candidate );
            $protected = self::normalize_path( false !== $real ? $real : $candidate );
            if ( $resolved === $protected || 0 === strpos( $protected, rtrim( $resolved, '/' ) . '/' ) ) {
                return false;
            }
        }
        $code_directories = array(
            defined( 'ABSPATH' ) ? trailingslashit( ABSPATH ) . 'wp-admin' : '',
            defined( 'ABSPATH' ) ? trailingslashit( ABSPATH ) . 'wp-includes' : '',
            $plugin_root,
            defined( 'WP_CONTENT_DIR' ) ? trailingslashit( WP_CONTENT_DIR ) . 'themes' : '',
        );
        foreach ( $code_directories as $candidate ) {
            if ( '' === $candidate ) {
                continue;
            }
            $real = realpath( $candidate );
            $protected = self::normalize_path( false !== $real ? $real : $candidate );
            if ( $resolved === $protected || 0 === strpos( $resolved, rtrim( $protected, '/' ) . '/' ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determine whether a top-level storage item is runtime-only.
     *
     * @param string $name Item name.
     * @return bool
     */
    private static function is_runtime_path_name( $name ) {
        if ( in_array( $name, array( 'imports', 'drive-transfers', 'direct' ), true ) ) {
            return true;
        }

        $patterns = array(
            '/^job-.+\\.json(?:\\.tmp)?$/D',
            '/^job-.+\\.lock$/D',
            '/^cancel-.+\\.flag$/D',
            '/^db-.+$/D',
        );

        foreach ( $patterns as $pattern ) {
            if ( 1 === preg_match( $pattern, $name ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a local WordPress filesystem object for uninstall cleanup.
     *
     * @return WP_Filesystem_Direct|null
     */
    private static function filesystem() {
        if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
            $base_file   = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php' : '';
            $direct_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php' : '';

            if ( $base_file && is_file( $base_file ) ) {
                require_once $base_file;
            }
            if ( $direct_file && is_file( $direct_file ) ) {
                require_once $direct_file;
            }
        }

        if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
            return null;
        }

        return new WP_Filesystem_Direct( null );
    }

    /**
     * Check that a normalized path remains inside a known storage root.
     *
     * @param string $path Candidate path.
     * @param string $root Storage root.
     * @return bool
     */
    private static function path_is_inside_root( $path, $root ) {
        $path = self::normalize_path( $path );
        $root = rtrim( self::normalize_path( $root ), '/' );

        return '' !== $path && '' !== $root && $path !== $root && 0 === strpos( $path, $root . '/' );
    }

    /**
     * Normalize a filesystem path without requiring normal plugin bootstrap.
     *
     * @param string $path Path to normalize.
     * @return string
     */
    private static function normalize_path( $path ) {
        $path = (string) $path;

        if ( function_exists( 'wp_normalize_path' ) ) {
            return rtrim( wp_normalize_path( $path ), '/' );
        }

        return rtrim( str_replace( '\\', '/', $path ), '/' );
    }
}
