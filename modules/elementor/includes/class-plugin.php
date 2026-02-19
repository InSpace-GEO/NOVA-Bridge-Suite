<?php
/**
 * Core plugin bootstrap.
 *
 * @package SEOR_Elementor_Bridge
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
namespace SEOR_Elementor_Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin singleton.
 */
class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_filter( 'seor_eb_allowed_setting_keys', array( $this, 'default_allowed_setting_keys' ) );
	}

	/**
	 * Register REST controller routes.
	 */
	public function register_rest_routes() {
		$controller = new Rest_Controller();
		$controller->register_routes();
	}

	/**
	 * Provide baseline allowlist of Elementor setting keys that are safe to expose.
	 *
	 * @param array $keys Existing keys from filters.
	 * @return array
	 */
	public function default_allowed_setting_keys( $keys ) {
		$defaults = array(
			'title',
			'subtitle',
			'description',
			'content',
			'text',
			'editor',
			'button_text',
			'badge_text',
			'link',
			'button_secondary_text',
			'heading',
			'html',
			'caption',
			'name',
			'position',
			'price',
			'before_text',
			'after_text',
			'tab_title',
			'tab_content',
			'label',
			'value',
		);

		return array_unique( array_merge( $keys, $defaults ) );
	}
}
