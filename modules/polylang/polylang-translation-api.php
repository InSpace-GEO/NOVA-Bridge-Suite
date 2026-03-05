<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
/**
 * NOVA Bridge Suite module: Polylang translation bridge.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PTAI_VERSION', '1.0.0');
define('PTAI_PLUGIN_FILE', __FILE__);
define('PTAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once PTAI_PLUGIN_DIR . 'includes/class-ptai-translation-service.php';
require_once PTAI_PLUGIN_DIR . 'includes/class-ptai-rest-controller.php';

function ptai_is_polylang_active(): bool
{
    return defined('POLYLANG_VERSION') || function_exists('pll_current_language');
}

function ptai_bootstrap(): void
{
    if (! ptai_is_polylang_active()) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>NOVA Polylang Translation API requires Polylang to be active.</p></div>';
        });

        return;
    }

    $translation_service = new PTAI_Translation_Service();
    $rest_controller     = new PTAI_REST_Controller($translation_service);

    add_action('rest_api_init', [$rest_controller, 'register_routes']);
}

add_action('plugins_loaded', 'ptai_bootstrap');
