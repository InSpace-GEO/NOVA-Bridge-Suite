<?php
/**
 * Plugin Name: NOVA WPBakery Bridge
 * Description: Minimal REST bridge for WPBakery Page Builder (list pages, get outline, apply text_updates).
 * Version:     1.0.0
 * Author:      Hypernova Technologies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOVA_WPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_WPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core includes.
require_once NOVA_WPB_PLUGIN_DIR . 'includes/helpers.php';
require_once NOVA_WPB_PLUGIN_DIR . 'includes/layout.php';
require_once NOVA_WPB_PLUGIN_DIR . 'includes/transformations.php';
require_once NOVA_WPB_PLUGIN_DIR . 'includes/pages.php';
require_once NOVA_WPB_PLUGIN_DIR . 'includes/rest-api.php';
