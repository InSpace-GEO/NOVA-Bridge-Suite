<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
/**
 * NOVA Bridge Suite module: WPML translation bridge.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WTAI_VERSION', '1.0.0');
define('WTAI_PLUGIN_FILE', __FILE__);
define('WTAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WTAI_PLUGIN_DIR . 'includes/class-wtai-translation-service.php';
require_once WTAI_PLUGIN_DIR . 'includes/class-wtai-rest-controller.php';

function wtai_is_wpml_active(): bool
{
    return defined('ICL_SITEPRESS_VERSION') || function_exists('wpml_init_language_switcher');
}

function wtai_bootstrap(): void
{
    if (! wtai_is_wpml_active()) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>WPML Translation API requires WPML to be active.</p></div>';
        });

        return;
    }

    $translation_service = new WTAI_Translation_Service();
    $rest_controller     = new WTAI_REST_Controller($translation_service);

    add_action('rest_api_init', [$rest_controller, 'register_routes']);
}

add_action('plugins_loaded', 'wtai_bootstrap');
