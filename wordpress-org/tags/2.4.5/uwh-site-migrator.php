<?php
/**
 * Plugin Name: UWH Site Migrator
 * Plugin URI: https://site-migrator.unitedwebhost.com/
 * Description: Backup, restore, and migrate WordPress sites with resumable processing, Direct Site-to-Site Migration, automatic rollback checkpoints, Google Drive sync, activity logs, diagnostics, and live progress.
 * Version: 2.4.5
 * Author: United Web Host
 * Author URI: https://unitedwebhost.com/
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: uwh-site-migrator
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

/*
 * Site Migrator is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Site Migrator is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See LICENSE.txt.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UWHSM_VERSION', '2.4.5' );
define( 'UWHSM_FILE', __FILE__ );
define( 'UWHSM_DIR', plugin_dir_path( __FILE__ ) );
define( 'UWHSM_URL', plugin_dir_url( __FILE__ ) );

require_once UWHSM_DIR . 'includes/class-uwhsm-compat.php';
UWHSM_Compat::bridge_legacy_constants();

// Register the Settings link for this exact plugin basename.
// Keeping a stable uwh-site-migrator/uwh-site-migrator.php basename prevents duplicate
// versioned folders and makes the action link reliable across upgrades.
add_filter(
    'plugin_action_links_' . plugin_basename( UWHSM_FILE ),
    static function ( $links ) {
        $settings = '<a href="' . esc_url( admin_url( 'admin.php?page=site-vault-migrator' ) ) . '">' . esc_html__( 'Settings', 'uwh-site-migrator' ) . '</a>';
        array_unshift( $links, $settings );
        return $links;
    }
);

require_once UWHSM_DIR . 'includes/class-uwhsm-paths.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-value-rewriter.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-logger.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-rollback.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-engine.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-site-transfer.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-admin.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-background.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-drive.php';
require_once UWHSM_DIR . 'includes/class-uwhsm-drive-transfer.php';

register_deactivation_hook(
    __FILE__,
    static function () {
        UWHSM_Background::deactivate();
        UWHSM_Drive_Transfer::deactivate();
    }
);

register_activation_hook( __FILE__, array( 'UWHSM_Engine', 'activate' ) );

add_action(
    'plugins_loaded',
    static function () {
        UWHSM_Compat::boot();
        UWHSM_Engine::instance();
        UWHSM_Background::boot();
        UWHSM_Drive::boot();
        UWHSM_Drive_Transfer::boot();
        UWHSM_Site_Transfer::boot();

        if ( is_admin() ) {
            UWHSM_Admin::instance();
        }
    }
);
