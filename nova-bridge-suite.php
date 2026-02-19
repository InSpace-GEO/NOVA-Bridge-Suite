<?php
/**
 * Plugin Name: NOVA Bridge Suite
 * Description: Connects NOVA to WordPress so your SEO automation can update pages and layouts the standard API cannot reach.
 * Version: 1.1.3
 * Author: Hypernova Technologies
 * Requires PHP: 7.4
 * License: Proprietary
 * Text Domain: nova-bridge-suite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'NOVA_BRIDGE_SUITE_VERSION', '1.1.3' );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_FILE', __FILE__ );
define( 'NOVA_BRIDGE_SUITE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NOVA_BRIDGE_SUITE_OPTION', 'nova_bridge_settings' );
function nova_bridge_suite_normalize_server_globals(): void {
    if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) || '' === $_SERVER['REQUEST_URI'] ) {
        $_SERVER['REQUEST_URI'] = '/';
    }

    if ( ! isset( $_SERVER['HTTP_HOST'] ) || ! is_string( $_SERVER['HTTP_HOST'] ) || '' === $_SERVER['HTTP_HOST'] ) {
        $home_url = get_option( 'home' );
        if ( is_string( $home_url ) && '' !== $home_url ) {
            $host = wp_parse_url( $home_url, PHP_URL_HOST );
            if ( is_string( $host ) && '' !== $host ) {
                $_SERVER['HTTP_HOST'] = $host;
            }
        }
    }
}

nova_bridge_suite_normalize_server_globals();

require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'includes/settings.php';

if ( file_exists( NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    $nova_bridge_suite_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/jg-inspace/LUNA/',
        __FILE__,
        'nova-bridge-suite'
    );
    $nova_bridge_suite_update_checker->setBranch( 'main' );
    $nova_bridge_suite_update_checker->getVcsApi()->enableReleaseAssets( '/NOVA-Bridge-Suite-.*\\.zip$/i' );
}

$nova_bridge_suite_settings = nova_bridge_suite_get_settings();

// Core bridge is always on.
require_once NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/bridge/nova-bridge.php';

// Post resolver is always on.
$nova_bridge_suite_post_resolver_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/post-resolver/nova-post-resolver.php';
if ( file_exists( $nova_bridge_suite_post_resolver_file ) ) {
    require_once $nova_bridge_suite_post_resolver_file;
}

$nova_bridge_suite_modules   = nova_bridge_suite_module_definitions();
$nova_bridge_suite_conflicts = nova_bridge_suite_get_module_conflicts();

foreach ( $nova_bridge_suite_modules as $nova_bridge_suite_setting_key => $nova_bridge_suite_module ) {
    if ( empty( $nova_bridge_suite_settings[ $nova_bridge_suite_setting_key ] ) ) {
        continue;
    }

    if ( ! empty( $nova_bridge_suite_conflicts[ $nova_bridge_suite_setting_key ] ) ) {
        continue;
    }

    $nova_bridge_suite_relative_path = $nova_bridge_suite_module['path'] ?? '';
    if ( '' !== $nova_bridge_suite_relative_path ) {
        $nova_bridge_suite_module_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . $nova_bridge_suite_relative_path;
        if ( file_exists( $nova_bridge_suite_module_file ) ) {
            require_once $nova_bridge_suite_module_file;
        }
    }
}

register_activation_hook( __FILE__, 'nova_bridge_suite_activate' );
register_deactivation_hook( __FILE__, 'nova_bridge_suite_deactivate' );

function nova_bridge_suite_activate(): void {
    $settings = nova_bridge_suite_get_settings();
    if ( false === get_option( NOVA_BRIDGE_SUITE_OPTION, false ) ) {
        add_option( NOVA_BRIDGE_SUITE_OPTION, $settings );
    }

    nova_bridge_suite_handle_cpt_toggle( false, ! empty( $settings['custom_post_types'] ) );
    nova_bridge_suite_handle_service_cpt_toggle( false, ! empty( $settings['service_page_cpt'] ) );
}

function nova_bridge_suite_deactivate(): void {
    $settings = nova_bridge_suite_get_settings();
    if ( ! empty( $settings['custom_post_types'] ) ) {
        nova_bridge_suite_handle_cpt_toggle( true, false );
    }

    if ( ! empty( $settings['service_page_cpt'] ) ) {
        nova_bridge_suite_handle_service_cpt_toggle( true, false );
    }
}

add_action( 'update_option_' . NOVA_BRIDGE_SUITE_OPTION, 'nova_bridge_suite_settings_updated', 10, 3 );

function nova_bridge_suite_settings_updated( $old_value, $new_value, string $option ): void {
    $defaults      = nova_bridge_suite_default_settings();
    $old_settings  = wp_parse_args( is_array( $old_value ) ? $old_value : [], $defaults );
    $new_settings  = wp_parse_args( is_array( $new_value ) ? $new_value : [], $defaults );
    $old_enabled   = ! empty( $old_settings['custom_post_types'] );
    $new_enabled   = ! empty( $new_settings['custom_post_types'] );
    $old_service   = ! empty( $old_settings['service_page_cpt'] );
    $new_service   = ! empty( $new_settings['service_page_cpt'] );

    if ( $old_enabled !== $new_enabled ) {
        nova_bridge_suite_handle_cpt_toggle( $old_enabled, $new_enabled );
    }

    if ( $old_service !== $new_service ) {
        nova_bridge_suite_handle_service_cpt_toggle( $old_service, $new_service );
    }
}

function nova_bridge_suite_handle_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'custom_post_types' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/cpt/quarantined-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\BodycleanCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\BodycleanCPT\Plugin::deactivate();
    }
}

function nova_bridge_suite_handle_service_cpt_toggle( bool $was_enabled, bool $is_enabled ): void {
    if ( nova_bridge_suite_is_module_blocked( 'service_page_cpt' ) ) {
        return;
    }

    $cpt_file = NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/service-page-cpt/service-page-cpt.php';
    if ( ! file_exists( $cpt_file ) ) {
        return;
    }

    require_once $cpt_file;

    if ( ! class_exists( '\\SEORAI\\ServicePageCPT\\Plugin' ) ) {
        return;
    }

    if ( ! $was_enabled && $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::activate();
        return;
    }

    if ( $was_enabled && ! $is_enabled ) {
        \SEORAI\ServicePageCPT\Plugin::deactivate();
    }
}
