<?php
/**
 * Breakdance Bridge – Main plugin bootstrap
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Ensure the REST controller is available before we try to instantiate it.
require_once __DIR__ . '/class-nova-bd-rest-controller.php';

if ( ! class_exists( 'Nova_BD_Plugin' ) ) {

    class Nova_BD_Plugin {

        /** @var Nova_BD_REST_Controller|null */
        protected static $rest_controller = null;

        /**
         * Init hook, called from main plugin file on plugins_loaded.
         */
        public static function init() {
            add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
        }

        /**
         * Register REST routes via our REST controller.
         */
        public static function register_rest_routes() {
            if ( null === self::$rest_controller ) {
                self::$rest_controller = new Nova_BD_REST_Controller();
            }

            self::$rest_controller->register_routes();
        }

        /**
         * Shared permission callback: require user who can edit pages.
         * Change to 'edit_posts' if you want Authors to use it.
         */
        public static function permission_check( $request ) {
            return current_user_can( 'edit_pages' );
        }
    }
}