<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Nova_BD_Bridge {

    /**
     * Plugin bootstrap.
     */
    public static function init() {
        add_action(
            'rest_api_init',
            array( 'Nova_BD_REST_Controller', 'register_routes' )
        );
    }
}