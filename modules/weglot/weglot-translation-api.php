<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
/**
 * NOVA Bridge Suite module: Weglot translation bridge.
 */

if (! defined('ABSPATH')) {
    exit;
}

define('WGTAI_VERSION', '1.0.0');
define('WGTAI_PLUGIN_FILE', __FILE__);
define('WGTAI_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once WGTAI_PLUGIN_DIR . 'includes/class-wgtai-language-service.php';
require_once WGTAI_PLUGIN_DIR . 'includes/class-wgtai-storage-entity.php';
require_once WGTAI_PLUGIN_DIR . 'includes/class-wgtai-storage-service.php';
require_once WGTAI_PLUGIN_DIR . 'includes/class-wgtai-render-service.php';
require_once WGTAI_PLUGIN_DIR . 'includes/class-wgtai-rest-controller.php';

function wgtai_is_weglot_active(): bool
{
    return WGTAI_Language_Service::is_weglot_active();
}

function wgtai_bootstrap(): void
{
    if (! wgtai_is_weglot_active()) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>NOVA Weglot Translation API requires Weglot to be active.</p></div>';
        });

        return;
    }

    $language_service = new WGTAI_Language_Service();
    $storage_service  = new WGTAI_Storage_Service($language_service);
    $rest_controller  = new WGTAI_REST_Controller($storage_service, $language_service);
    $render_service   = new WGTAI_Render_Service($language_service, $storage_service);

    add_action('rest_api_init', [$rest_controller, 'register_routes']);

    $render_service->hooks();
}

// Priority 20: Weglot builds its service container on plugins_loaded, and this
// module must not resolve Weglot services before that container exists.
add_action('plugins_loaded', 'wgtai_bootstrap', 20);
