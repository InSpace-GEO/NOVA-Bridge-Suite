<?php
/**
 * NOVA Bridge Suite module: Gutenberg bridge.
 *
 * REST bridge for Gutenberg posts & pages — create, update,
 * HTML-to-block conversion, SEO meta, featured images.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent double-loading if the standalone plugin already defined this.
if ( defined( 'NOVA_GUT_PLUGIN_DIR' ) ) {
	return;
}

define( 'NOVA_GUT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once NOVA_GUT_PLUGIN_DIR . 'includes/helpers.php';
require_once NOVA_GUT_PLUGIN_DIR . 'includes/pages.php';
require_once NOVA_GUT_PLUGIN_DIR . 'includes/rest-api.php';
