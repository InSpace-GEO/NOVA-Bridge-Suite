<?php
/**
 * NOVA Bridge Suite module: Elementor bridge.
 *
 * @package SEOR_Elementor_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEOR_EB_PLUGIN_FILE', __FILE__ );
define( 'SEOR_EB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEOR_EB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'SEOR_Elementor_Bridge\\' ) ) {
			return;
		}

		$relative = strtolower( str_replace( 'SEOR_Elementor_Bridge\\', '', $class ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$relative = str_replace( '_', '-', $relative );
		$file     = SEOR_EB_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'SEOR Elementor Bridge requires Elementor to be active.', 'nova-bridge-suite' ) . '</p></div>';
				}
			);
			return;
		}

		SEOR_Elementor_Bridge\Plugin::instance();
	}
);
