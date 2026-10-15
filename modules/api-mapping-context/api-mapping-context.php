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

foreach ( [ 'content-transport', 'strategy', 'mapping-drafts', 'posting-settings', 'strategy-admin', 'mapping-preview', 'content-rules', 'content-rules-admin', 'document-mapping', 'endpoint-mappings', 'endpoint-mappings-admin', 'recipe-engine', 'recipe-elementor', 'recipe-builders', 'recipe-acf', 'recipe-term-fields', 'recipe-core', 'recipe-visual', 'layout-recipes', 'layout-recipes-admin', 'term-content' ] as $nova_context_component ) {
	$nova_context_file = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'includes/class-nova-bridge-suite-' . $nova_context_component . '.php';
	if ( file_exists( $nova_context_file ) ) {
		require_once $nova_context_file;
	}
}
Nova_Bridge_Suite_Recipe_Builders::register();
Nova_Bridge_Suite_Recipe_ACF::register();
Nova_Bridge_Suite_Recipe_Core::register();
Nova_Bridge_Suite_Layout_Recipes::bootstrap();
Nova_Bridge_Suite_Layout_Recipes_Admin::bootstrap();
Nova_Bridge_Suite_Term_Content::bootstrap();
// Publishing is part of mapping: load all collaborators before registering hooks.
foreach ( [ 'posting-client', 'posting-protocol', 'receipt-json', 'writing-adapter', 'mapping-sync', 'taxonomy-counts', 'elementor-derived', 'mapped-writer', 'rule-writer', 'posting-jobs', 'posting-discovery', 'posting-worker', 'posting-delivery', 'mapped-content-term-writer', 'mapped-content-post-writer', 'mapped-content-writer', 'mapped-content-target', 'mapped-content-connector' ] as $nova_posting_component ) {
    require_once dirname( NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR ) . '/posting-service/includes/class-nova-bridge-suite-' . $nova_posting_component . '.php';
}
foreach ( [ 'Nova_Bridge_Suite_Content_Transport', 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Mapping_Drafts', 'Nova_Bridge_Suite_Posting_Settings', 'Nova_Bridge_Suite_Mapping_Sync', 'Nova_Bridge_Suite_Posting_Delivery', 'Nova_Bridge_Suite_Strategy_Admin', 'Nova_Bridge_Suite_Mapping_Preview', 'Nova_Bridge_Suite_Content_Rules_Admin', 'Nova_Bridge_Suite_Endpoint_Mappings', 'Nova_Bridge_Suite_Endpoint_Mappings_Admin', 'Nova_Bridge_Suite_Mapped_Content_Connector' ] as $nova_context_class ) {
	if ( class_exists( $nova_context_class ) ) {
		$nova_context_class::bootstrap();
	}
}
