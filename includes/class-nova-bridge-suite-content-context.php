<?php
/**
 * Backward-compatible class loader for API Mapping Context.
 *
 * The implementation belongs to the standalone module. This file remains only
 * for integrations that required the pre-3.0 class path directly.
 *
 * @package NOVA_Bridge_Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nova_bridge_suite_api_mapping_context_legacy_class = dirname( __DIR__ ) . '/modules/api-mapping-context/includes/class-nova-bridge-suite-content-context.php';

if ( file_exists( $nova_bridge_suite_api_mapping_context_legacy_class ) ) {
	require_once $nova_bridge_suite_api_mapping_context_legacy_class;
}
