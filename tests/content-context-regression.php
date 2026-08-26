<?php
/**
 * Backward-compatible entry point for the API Mapping Context regression suite.
 *
 * Usage:
 * wp eval-file wp-content/plugins/nova-bridge-suite/tests/content-context-regression.php
 *
 * @package NOVA_Bridge_Suite
 */

$nova_bridge_suite_api_mapping_context_test = dirname( __DIR__ ) . '/modules/api-mapping-context/tests/content-context-regression.php';

if ( ! file_exists( $nova_bridge_suite_api_mapping_context_test ) ) {
	throw new RuntimeException( 'The API Mapping Context regression suite could not be found.' );
}

require $nova_bridge_suite_api_mapping_context_test;
