<?php
/**
 * NOVA Bridge Suite module: Divi Bridge.
 *
 * REST bridge for the Divi Builder. Writes Divi 4 `et_pb_*` shortcode layouts
 * into post_content (+ `_et_pb_use_builder` meta). Divi 5 renders this content
 * through its backwards-compatibility layer and can migrate it per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOVA_DIVI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_DIVI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once NOVA_DIVI_PLUGIN_DIR . 'includes/helpers.php';
require_once NOVA_DIVI_PLUGIN_DIR . 'includes/layout.php';
require_once NOVA_DIVI_PLUGIN_DIR . 'includes/transformations.php';
require_once NOVA_DIVI_PLUGIN_DIR . 'includes/pages.php';
require_once NOVA_DIVI_PLUGIN_DIR . 'includes/rest-api.php';
