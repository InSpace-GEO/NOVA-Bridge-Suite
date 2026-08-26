<?php
/**
 * API Mapping Context module bootstrap.
 *
 * @package NOVA_Bridge_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR' ) ) {
	define( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL' ) ) {
	define( 'NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL', plugin_dir_url( __FILE__ ) );
}

$nova_bridge_suite_api_mapping_context_class = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'includes/class-nova-bridge-suite-content-context.php';

if ( file_exists( $nova_bridge_suite_api_mapping_context_class ) ) {
	require_once $nova_bridge_suite_api_mapping_context_class;
}

if ( class_exists( 'Nova_Bridge_Suite_Content_Context' ) ) {
	Nova_Bridge_Suite_Content_Context::bootstrap();
}
