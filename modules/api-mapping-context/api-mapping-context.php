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

foreach ( [ 'content-transport', 'strategy', 'strategy-admin', 'mapping-preview' ] as $nova_context_component ) {
	$nova_context_file = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'includes/class-nova-bridge-suite-' . $nova_context_component . '.php';
	if ( file_exists( $nova_context_file ) ) {
		require_once $nova_context_file;
	}
}
foreach ( [ 'Nova_Bridge_Suite_Content_Transport', 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Strategy_Admin', 'Nova_Bridge_Suite_Mapping_Preview' ] as $nova_context_class ) {
	if ( class_exists( $nova_context_class ) ) {
		$nova_context_class::bootstrap();
	}
}
