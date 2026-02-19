<?php
/**
 * NOVA Bridge Suite module: Breakdance bridge.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-nova-bd-utils.php';
require_once __DIR__ . '/includes/class-nova-bd-rest-controller.php';
require_once __DIR__ . '/includes/class-nova-bd-bridge.php';

// Bootstrap the REST bridge once WordPress and Breakdance are loaded.
add_action( 'plugins_loaded', array( 'Nova_BD_Bridge', 'init' ) );
