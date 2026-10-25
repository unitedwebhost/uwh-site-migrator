<?php
/**
 * Site Migrator uninstall handler.
 *
 * @package Site_Migrator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-uwhsm-uninstall.php';

UWHSM_Uninstall::run();
