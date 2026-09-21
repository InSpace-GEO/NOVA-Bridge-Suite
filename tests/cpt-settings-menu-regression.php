<?php
/**
 * Run on staging with an administrator: wp --user=ADMIN eval-file tests/cpt-settings-menu-regression.php
 * Exercises WordPress's real menu/access functions without saving options or creating content.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$assert( current_user_can( 'manage_options' ), 'Run this test as an administrator.' );
$assert( class_exists( 'SEORAI\\BodycleanCPT\\Plugin' ), 'Enable the NOVA Blog CPT module before running this test.' );

// Menu globals and request data are restored before returning to WP-CLI.
$global_names = [ 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_menu_nopriv', '_wp_submenu_nopriv', 'pagenow', 'typenow', 'plugin_page', 'parent_file', 'wp_filter' ];
$saved = [];
foreach ( $global_names as $name ) {
	$saved[ $name ] = [ array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null ];
}
foreach ( $saved['wp_filter'][1] as $name => $hook ) {
	$saved['wp_filter'][1][ $name ] = clone $hook;
}
$saved_get = $_GET;
$saved_server = $_SERVER;
$saved_user = get_current_user_id();
$reflection = new ReflectionClass( 'SEORAI\\BodycleanCPT\\Plugin' );
$definitions_property = $reflection->getProperty( 'cpt_definitions' );
$definitions_property->setAccessible( true );
$page = 'quarantined-cpt-bodyclean';
$destination = 'options-general.php?page=' . $page;
$checks = 0;

// Catch the real WordPress redirect before headers/exit so assertions can inspect it.
$redirect = null;
$capture_redirect = static function ( $location, $status ) use ( &$redirect ) {
	$redirect = [ $location, $status ];
	throw new RuntimeException( 'nova_settings_test_redirect' );
};

try {
	foreach ( [ 'default' => [ 'quarantined' ], 'renamed' => [ 'news' ], 'multiple' => [ 'news', 'quarantined' ], 'disabled' => [] ] as $scenario => $types ) {
		$enabled = static function () use ( $types ) { return ! empty( $types ); };
		add_filter( 'pre_option_quarantined_cpt_bodyclean_enable_cpts', $enabled );
		$instance = $reflection->newInstanceWithoutConstructor();
		$definitions_property->setValue( $instance, array_map( static function ( $type ) { return [ 'type' => $type ]; }, $types ) );

		foreach ( [ 'canonical', 'legacy', 'language', 'unrelated', 'post', 'array-page', 'array-language', 'unauthorized' ] as $request ) {
			wp_set_current_user( 'unauthorized' === $request ? 0 : $saved_user );
			foreach ( [ 'menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages', '_wp_real_parent_file', '_wp_menu_nopriv', '_wp_submenu_nopriv' ] as $name ) {
				$GLOBALS[ $name ] = [];
			}
			$GLOBALS['parent_file'] = '';
			$GLOBALS['pagenow'] = 'canonical' === $request || 'unauthorized' === $request ? 'options-general.php' : 'edit.php';
			$GLOBALS['typenow'] = 'edit.php' === $GLOBALS['pagenow'] && in_array( 'quarantined', $types, true ) ? 'quarantined' : '';
			$GLOBALS['plugin_page'] = $page;
			$parents = [ 'edit.php' => 'posts' ];
			foreach ( $types as $type ) { $parents[ 'edit.php?post_type=' . $type ] = $type; }
			$parents['options-general.php'] = 'settings';
			foreach ( $parents as $parent => $hook ) {
				$GLOBALS['menu'][] = [ $hook, 'manage_options', $parent, $hook ];
				$GLOBALS['submenu'][ $parent ] = [ [ $hook, 'manage_options', $parent, $hook ] ];
				$GLOBALS['admin_page_hooks'][ $parent ] = $hook;
			}
			$_SERVER['REQUEST_METHOD'] = 'post' === $request ? 'POST' : 'GET';
			$_GET = [ 'page' => $page, 'post_type' => 'quarantined' ];
			if ( 'language' === $request ) { $_GET['lang'] = 'nl'; $_GET['redirect_to'] = 'https://example.invalid/'; }
			if ( 'unrelated' === $request ) { $_GET['page'] = 'another-plugin'; }
			if ( 'array-page' === $request ) { $_GET['page'] = [ $page ]; }
			if ( 'array-language' === $request ) { $_GET['lang'] = [ 'nl' ]; }
			$redirect = null;
			add_filter( 'wp_redirect', $capture_redirect, -999, 2 );
			try {
				$instance->register_settings_page();
			} catch ( RuntimeException $error ) {
				if ( 'nova_settings_test_redirect' !== $error->getMessage() ) { throw $error; }
			} finally {
				remove_filter( 'wp_redirect', $capture_redirect, -999 );
			}
			$expects_redirect = in_array( $request, [ 'legacy', 'language', 'array-language' ], true );
			$assert( $expects_redirect === ( null !== $redirect ), "$scenario/$request: unexpected redirect behavior" );
			if ( $expects_redirect ) {
				$expected_url = admin_url( $destination );
				if ( 'language' === $request ) { $expected_url = add_query_arg( 'lang', 'nl', $expected_url ); }
				$assert( [ $expected_url, 302 ] === $redirect, "$scenario/$request: wrong redirect destination or status" );
			} elseif ( 'canonical' === $request ) {
				$assert( user_can_access_admin_page(), "$scenario: canonical page is inaccessible" );
				$assert( 'settings_page_' . $page === get_plugin_page_hook( $page, 'options-general.php' ), "$scenario: canonical callback is missing" );
				if ( $types ) {
					$links = array_column( $GLOBALS['submenu'][ 'edit.php?post_type=' . $types[0] ], 2 );
					$assert( in_array( $destination, $links, true ), "$scenario: CPT shortcut does not point to the canonical page" );
					$assert( ! in_array( $page, $links, true ), "$scenario: duplicate settings parent remains" );
				}
			} elseif ( 'unauthorized' === $request ) {
				$assert( ! user_can_access_admin_page(), "$scenario: unauthorized user can access settings" );
				$GLOBALS['pagenow'] = 'edit.php';
				$instance->register_settings_page();
				$assert( ! user_can_access_admin_page(), "$scenario: unauthorized user can access the legacy page" );
			}
			++$checks;
		}
		remove_filter( 'pre_option_quarantined_cpt_bodyclean_enable_cpts', $enabled );
	}
} finally {
	if ( isset( $enabled ) ) { remove_filter( 'pre_option_quarantined_cpt_bodyclean_enable_cpts', $enabled ); }
	wp_set_current_user( $saved_user );
	$_GET = $saved_get;
	$_SERVER = $saved_server;
	foreach ( $saved as $name => $value ) {
		if ( $value[0] ) { $GLOBALS[ $name ] = $value[1]; } else { unset( $GLOBALS[ $name ] ); }
	}
}

WP_CLI::success( "NOVA Blog settings menu: $checks regression cases passed." );
