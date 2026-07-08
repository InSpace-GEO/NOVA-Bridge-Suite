<?php
/**
 * NOVA Bridge Suite module: Beaver Builder Bridge.
 *
 * REST bridge for Beaver Builder. Unlike WPBakery/Divi, Beaver Builder keeps
 * the layout in post meta (`_fl_builder_data`: a serialized flat array of
 * node objects linked by parent/position), not as shortcodes in post_content.
 * post_content only holds a rendered plain-HTML fallback.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOVA_BB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_BB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once NOVA_BB_PLUGIN_DIR . 'includes/helpers.php';
require_once NOVA_BB_PLUGIN_DIR . 'includes/layout.php';
require_once NOVA_BB_PLUGIN_DIR . 'includes/transformations.php';
require_once NOVA_BB_PLUGIN_DIR . 'includes/pages.php';
require_once NOVA_BB_PLUGIN_DIR . 'includes/rest-api.php';
