<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function nova_bridge_suite_module_definitions(): array {
    return [
        'pagebuilder_wpbakery'   => [
            'path'                 => 'modules/wpbakery/wpbakery-bridge.php',
            'standalone_filenames' => [ 'wpbakery-bridge.php' ],
        ],
        'pagebuilder_elementor'  => [
            'path'                 => 'modules/elementor/seor-elementor-bridge.php',
            'standalone_filenames' => [ 'seor-elementor-bridge.php' ],
        ],
        'pagebuilder_breakdance' => [
            'path'                 => 'modules/breakdance/breakdance-bridge.php',
            'standalone_filenames' => [ 'breakdance-bridge.php' ],
        ],
        'pagebuilder_avada'      => [
            'path'                 => 'modules/avada/nova-avada-bridge.php',
            'standalone_filenames' => [ 'nova-avada-bridge.php' ],
        ],
        'multilingual_wpml'      => [
            'path'                 => 'modules/wpml/wpml-translation-api.php',
            'standalone_filenames' => [ 'wpml-translation-api.php' ],
        ],
        'woocommerce_rich_text'  => [
            'path'                 => 'modules/woocommerce/wc-content-below-products.php',
            'standalone_filenames' => [ 'wc-content-below-products.php' ],
        ],
        'custom_post_types'      => [
            'path'                 => 'modules/cpt/quarantined-cpt.php',
            'standalone_filenames' => [ 'quarantined-cpt.php' ],
        ],
        'service_page_cpt'       => [
            'path'                 => 'modules/service-page-cpt/service-page-cpt.php',
            'standalone_filenames' => [ 'service-page-cpt.php' ],
        ],
    ];
}

function nova_bridge_suite_default_settings(): array {
    $defaults = [];

    foreach ( nova_bridge_suite_module_definitions() as $key => $module ) {
        $defaults[ $key ] = 0;
    }

    return $defaults;
}


function nova_bridge_suite_get_active_plugins(): array {
    $plugins = (array) get_option( 'active_plugins', [] );

    if ( is_multisite() ) {
        $network_active = (array) get_site_option( 'active_sitewide_plugins', [] );
        $plugins        = array_merge( $plugins, array_keys( $network_active ) );
    }

    $normalized = [];
    foreach ( $plugins as $plugin ) {
        $plugin = str_replace( '\\', '/', (string) $plugin );
        if ( '' !== $plugin ) {
            $normalized[] = $plugin;
        }
    }

    return array_values( array_unique( $normalized ) );
}

function nova_bridge_suite_has_active_plugin( array $filenames ): bool {
    if ( empty( $filenames ) ) {
        return false;
    }

    $active_plugins = nova_bridge_suite_get_active_plugins();
    if ( empty( $active_plugins ) ) {
        return false;
    }

    $active_basenames = array_map( 'basename', $active_plugins );

    foreach ( $filenames as $filename ) {
        $filename = str_replace( '\\', '/', (string) $filename );
        $filename = ltrim( $filename, '/' );
        if ( '' === $filename ) {
            continue;
        }

        if ( in_array( $filename, $active_plugins, true ) ) {
            return true;
        }

        if ( in_array( basename( $filename ), $active_basenames, true ) ) {
            return true;
        }
    }

    return false;
}

function nova_bridge_suite_is_acf_active(): bool {
    if ( class_exists( 'ACF' ) || defined( 'ACF_VERSION' ) ) {
        return true;
    }

    return nova_bridge_suite_has_active_plugin(
        [
            'advanced-custom-fields/acf.php',
            'advanced-custom-fields-pro/acf.php',
            'acf-pro/acf.php',
        ]
    );
}

function nova_bridge_suite_is_woocommerce_active(): bool {
    if ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ) {
        return true;
    }

    return nova_bridge_suite_has_active_plugin( [ 'woocommerce/woocommerce.php' ] );
}

function nova_bridge_suite_find_active_plugin_by_filename( string $filename ): string {
    $filename = ltrim( $filename, '/' );

    if ( '' === $filename ) {
        return '';
    }

    foreach ( nova_bridge_suite_get_active_plugins() as $plugin ) {
        if ( basename( $plugin ) === $filename ) {
            return $plugin;
        }
    }

    return '';
}

function nova_bridge_suite_get_module_conflicts(): array {
    static $conflicts = null;

    if ( null !== $conflicts ) {
        return $conflicts;
    }

    $conflicts = [];

    foreach ( nova_bridge_suite_module_definitions() as $key => $module ) {
        $filenames = $module['standalone_filenames'] ?? [];

        if ( empty( $filenames ) && ! empty( $module['path'] ) ) {
            $filenames = [ basename( $module['path'] ) ];
        }

        foreach ( $filenames as $filename ) {
            $active_plugin = nova_bridge_suite_find_active_plugin_by_filename( $filename );
            if ( '' !== $active_plugin ) {
                $conflicts[ $key ] = $active_plugin;
                break;
            }
        }
    }

    return $conflicts;
}

function nova_bridge_suite_get_module_conflict( string $setting_key ): string {
    $conflicts = nova_bridge_suite_get_module_conflicts();

    return $conflicts[ $setting_key ] ?? '';
}

function nova_bridge_suite_is_module_blocked( string $setting_key ): bool {
    return '' !== nova_bridge_suite_get_module_conflict( $setting_key );
}

function nova_bridge_suite_get_dismissible_suggestions(): array {
    return [
        'woocommerce_rich_text',
    ];
}

function nova_bridge_suite_get_dismissed_suggestions(): array {
    $dismissed = get_option( 'nova_bridge_suite_dismissed_suggestions', [] );

    if ( ! is_array( $dismissed ) ) {
        $dismissed = [];
    }

    $normalized = [];
    foreach ( $dismissed as $key ) {
        $key = sanitize_key( (string) $key );
        if ( '' !== $key ) {
            $normalized[ $key ] = true;
        }
    }

    return array_keys( $normalized );
}

function nova_bridge_suite_is_suggestion_dismissed( string $key ): bool {
    $key = sanitize_key( $key );
    if ( '' === $key ) {
        return false;
    }

    return in_array( $key, nova_bridge_suite_get_dismissed_suggestions(), true );
}

function nova_bridge_suite_dismiss_suggestion( string $key ): void {
    $key = sanitize_key( $key );
    if ( '' === $key ) {
        return;
    }

    if ( ! in_array( $key, nova_bridge_suite_get_dismissible_suggestions(), true ) ) {
        return;
    }

    $dismissed = nova_bridge_suite_get_dismissed_suggestions();
    if ( in_array( $key, $dismissed, true ) ) {
        return;
    }

    $dismissed[] = $key;
    update_option( 'nova_bridge_suite_dismissed_suggestions', $dismissed, false );
}

function nova_bridge_suite_handle_dismiss_suggestion(): void {
    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( empty( $_GET['nova_bridge_dismiss_suggestion'] ) || empty( $_GET['_nova_bridge_dismiss_nonce'] ) ) {
        return;
    }

    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'nova-settings' !== $page ) {
        return;
    }

    $key   = sanitize_key( wp_unslash( $_GET['nova_bridge_dismiss_suggestion'] ) );
    $nonce = sanitize_text_field( wp_unslash( $_GET['_nova_bridge_dismiss_nonce'] ) );

    if ( '' === $key || ! wp_verify_nonce( $nonce, 'nova_bridge_suite_dismiss_suggestion_' . $key ) ) {
        return;
    }

    nova_bridge_suite_dismiss_suggestion( $key );

    $fallback_redirect = admin_url( 'options-general.php?page=nova-settings' );
    $request_uri       = '';

    if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
    }

    if ( '' === $request_uri ) {
        $request_uri = $fallback_redirect;
    }

    $redirect = remove_query_arg(
        [ 'nova_bridge_dismiss_suggestion', '_nova_bridge_dismiss_nonce' ],
        $request_uri
    );

    if ( ! is_string( $redirect ) || '' === $redirect ) {
        $redirect = $fallback_redirect;
    }

    wp_safe_redirect( $redirect );
    exit;
}

add_action( 'admin_init', 'nova_bridge_suite_handle_dismiss_suggestion' );

function nova_bridge_suite_get_recommended_modules(): array {
    $settings = nova_bridge_suite_get_settings();
    $recommendations = [];
    $plugin_candidates = [
        'pagebuilder_wpbakery'   => [
            'label'       => 'Enable WPBakery bridge',
            'description' => 'WPBakery detected. Enable the bridge to update WPBakery pages.',
            'plugins'     => [ 'js_composer/js_composer.php', 'wpbakery/js_composer.php' ],
        ],
        'pagebuilder_elementor'  => [
            'label'       => 'Enable Elementor bridge',
            'description' => 'Elementor detected. Enable the bridge to update Elementor pages.',
            'plugins'     => [ 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ],
        ],
        'pagebuilder_breakdance' => [
            'label'       => 'Enable Breakdance bridge',
            'description' => 'Breakdance detected. Enable the bridge to update Breakdance pages.',
            'plugins'     => [ 'breakdance/plugin.php', 'breakdance/breakdance.php' ],
        ],
        'pagebuilder_avada'      => [
            'label'       => 'Enable Avada bridge',
            'description' => 'Avada detected. Enable the bridge to update Avada Builder pages.',
            'plugins'     => [ 'fusion-builder/fusion-builder.php', 'avada-builder/avada-builder.php' ],
        ],
        'multilingual_wpml'      => [
            'label'       => 'Enable WPML bridge',
            'description' => 'WPML detected. Enable the bridge to manage translations.',
            'plugins'     => [ 'sitepress-multilingual-cms/sitepress.php' ],
        ],
    ];

    foreach ( $plugin_candidates as $key => $candidate ) {
        if ( ! nova_bridge_suite_has_active_plugin( $candidate['plugins'] ?? [] ) ) {
            continue;
        }

        if ( ! empty( $settings[ $key ] ) ) {
            continue;
        }

        if ( nova_bridge_suite_is_module_blocked( $key ) ) {
            continue;
        }

        $recommendations[] = [
            'key'         => $key,
            'label'       => $candidate['label'] ?? $key,
            'description' => $candidate['description'] ?? '',
        ];
    }

    if ( nova_bridge_suite_is_woocommerce_active() && ! nova_bridge_suite_is_acf_active() ) {
        $key = 'woocommerce_rich_text';

        if ( nova_bridge_suite_is_suggestion_dismissed( $key ) ) {
            return $recommendations;
        }

        if ( empty( $settings[ $key ] ) && ! nova_bridge_suite_is_module_blocked( $key ) ) {
            $recommendations[] = [
                'key'         => $key,
                'label'       => 'Enable WooCommerce rich text field',
                'description' => 'ACF not detected. If your category pages need content below products, enable this rich text field.',
            ];
        }
    }

    return $recommendations;
}

function nova_bridge_suite_get_recommendation_for_key( string $key ): ?array {
    if ( '' === $key ) {
        return null;
    }

    foreach ( nova_bridge_suite_get_recommended_modules() as $item ) {
        if ( ( $item['key'] ?? '' ) === $key ) {
            return $item;
        }
    }

    return null;
}

function nova_bridge_suite_render_recommendation_notice(): void {
    $recommendations = nova_bridge_suite_get_recommended_modules();

    if ( empty( $recommendations ) ) {
        return;
    }
    ?>
    <div class="notice notice-info nova-bridge-suite-recommendations">
        <p><strong><?php echo esc_html__( 'Compatible plugins detected.', 'nova-bridge-suite' ); ?></strong> <?php echo esc_html__( 'Consider enabling the matching NOVA modules below:', 'nova-bridge-suite' ); ?></p>
        <ul>
            <?php foreach ( $recommendations as $recommendation ) : ?>
                <?php
                $anchor      = 'nova-bridge-suite-' . $recommendation['key'];
                $label       = $recommendation['label'] ?? '';
                $description = $recommendation['description'] ?? '';
                ?>
                <li>
                    <a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a>
                    <?php if ( '' !== $description ) : ?>
                        <?php echo esc_html( ' - ' . $description ); ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php
}

function nova_bridge_suite_get_settings(): array {
    $defaults = nova_bridge_suite_default_settings();
    $options  = get_option( NOVA_BRIDGE_SUITE_OPTION, [] );

    if ( ! is_array( $options ) ) {
        $options = [];
    }

    $settings  = wp_parse_args( $options, $defaults );
    $conflicts = nova_bridge_suite_get_module_conflicts();

    foreach ( $conflicts as $key => $plugin ) {
        if ( array_key_exists( $key, $settings ) ) {
            $settings[ $key ] = 0;
        }
    }

    return $settings;
}


function nova_bridge_suite_get_inline_setting_errors( string $key ): array {
    if ( '' === $key ) {
        return [];
    }

    if ( ! function_exists( 'get_settings_errors' ) ) {
        return [];
    }

    $target_code = 'nova_bridge_conflict_' . $key;
    $errors      = get_settings_errors( 'nova_bridge_settings' );
    $messages    = [];

    foreach ( $errors as $error ) {
        if ( ( $error['code'] ?? '' ) !== $target_code ) {
            continue;
        }

        $message = $error['message'] ?? '';
        if ( '' !== $message ) {
            $messages[] = $message;
        }
    }

    return $messages;
}

function nova_bridge_suite_sanitize_settings( $input ): array {
    $defaults  = nova_bridge_suite_default_settings();
    $sanitized = [];
    $conflicts = nova_bridge_suite_get_module_conflicts();

    if ( ! is_array( $input ) ) {
        $input = [];
    }

    foreach ( $defaults as $key => $default ) {
        if ( isset( $conflicts[ $key ] ) ) {
            $sanitized[ $key ] = 0;
            if ( ! empty( $input[ $key ] ) && function_exists( 'add_settings_error' ) ) {
                $message = sprintf(
                    'Standalone plugin active (%s). Deactivate it to enable this module.',
                    $conflicts[ $key ]
                );
                add_settings_error( 'nova_bridge_settings', 'nova_bridge_conflict_' . $key, esc_html( $message ), 'warning' );
            }
            continue;
        }

        $sanitized[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
    }

    return $sanitized;
}


function nova_bridge_suite_register_settings_page(): void {
    add_options_page(
        'NOVA Settings',
        'NOVA Settings',
        'manage_options',
        'nova-settings',
        'nova_bridge_suite_render_settings_page'
    );
}

add_action( 'admin_menu', 'nova_bridge_suite_register_settings_page' );

function nova_bridge_suite_register_settings(): void {
    register_setting(
        'nova_bridge_settings',
        NOVA_BRIDGE_SUITE_OPTION,
        [
            'sanitize_callback' => 'nova_bridge_suite_sanitize_settings',
        ]
    );

    add_settings_section(
        'nova_bridge_core',
        'Core Bridge',
        'nova_bridge_suite_render_core_section',
        'nova-settings'
    );

    add_settings_field(
        'nova_bridge_core_status',
        'NOVA Bridge',
        'nova_bridge_suite_render_core_field',
        'nova-settings',
        'nova_bridge_core'
    );

    add_settings_section(
        'nova_bridge_pagebuilders',
        'Pagebuilder Bridges',
        '__return_false',
        'nova-settings'
    );

    add_settings_field(
        'nova_bridge_wpbakery',
        'WPBakery',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_pagebuilders',
        [
            'key'         => 'pagebuilder_wpbakery',
            'label'       => 'Enable WPBakery bridge',
            'description' => 'REST bridge for WPBakery pages.',
        ]
    );

    add_settings_field(
        'nova_bridge_elementor',
        'Elementor',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_pagebuilders',
        [
            'key'         => 'pagebuilder_elementor',
            'label'       => 'Enable Elementor bridge',
            'description' => 'REST bridge for Elementor pages.',
        ]
    );

    add_settings_field(
        'nova_bridge_breakdance',
        'Breakdance',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_pagebuilders',
        [
            'key'         => 'pagebuilder_breakdance',
            'label'       => 'Enable Breakdance bridge',
            'description' => 'REST bridge for Breakdance pages.',
        ]
    );

    add_settings_field(
        'nova_bridge_avada',
        'Avada',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_pagebuilders',
        [
            'key'         => 'pagebuilder_avada',
            'label'       => 'Enable Avada bridge',
            'description' => 'REST bridge for Avada Builder pages.',
        ]
    );

    add_settings_section(
        'nova_bridge_multilingual',
        'Multilingual',
        '__return_false',
        'nova-settings'
    );

    add_settings_field(
        'nova_bridge_wpml',
        'WPML',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_multilingual',
        [
            'key'         => 'multilingual_wpml',
            'label'       => 'Enable WPML bridge',
            'description' => 'REST bridge for WPML translations.',
        ]
    );

    add_settings_section(
        'nova_bridge_woocommerce',
        'WooCommerce',
        '__return_false',
        'nova-settings'
    );

    add_settings_field(
        'nova_bridge_woocommerce_rich_text',
        'Rich Text Field',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_woocommerce',
        [
            'key'         => 'woocommerce_rich_text',
            'label'       => 'Enable WooCommerce rich text field',
            'description' => 'Rich text field for below products on category pages.',
        ]
    );

    add_settings_section(
        'nova_bridge_cpt',
        'Custom Post Types',
        '__return_false',
        'nova-settings'
    );

    add_settings_field(
        'nova_bridge_cpt_toggle',
        'NOVA Blog CPT',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_cpt',
        [
            'key'         => 'custom_post_types',
            'label'       => 'Enable NOVA Blog CPT',
            'description' => 'Custom Post Type for SEO-friendly blog posts.',
        ]
    );

    add_settings_field(
        'nova_bridge_service_cpt_toggle',
        'NOVA Service CPT',
        'nova_bridge_suite_render_checkbox_field',
        'nova-settings',
        'nova_bridge_cpt',
        [
            'key'         => 'service_page_cpt',
            'label'       => 'Enable NOVA Service CPT',
            'description' => 'Custom Post Type for SEO-friendly service pages using Gutenberg.',
        ]
    );

}

add_action( 'admin_init', 'nova_bridge_suite_register_settings' );

function nova_bridge_suite_render_settings_page(): void {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'NOVA Settings', 'nova-bridge-suite' ); ?></h1>
        <p><?php echo esc_html__( 'Choose which NOVA modules are active. The core bridge and post resolver always remain on.', 'nova-bridge-suite' ); ?></p>
        <?php nova_bridge_suite_render_recommendation_notice(); ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'nova_bridge_settings' );
            do_settings_sections( 'nova-settings' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function nova_bridge_suite_render_core_section(): void {
    echo '<p>' . esc_html__( 'The core NOVA Bridge and Post Resolver are always enabled.', 'nova-bridge-suite' ) . '</p>';
}

function nova_bridge_suite_render_core_field(): void {
    echo '<label><input type="checkbox" checked disabled> ' . esc_html__( 'Enabled', 'nova-bridge-suite' ) . '</label>';
}

function nova_bridge_suite_render_checkbox_field( array $args ): void {
    $settings    = nova_bridge_suite_get_settings();
    $key         = $args['key'] ?? '';
    $label       = $args['label'] ?? '';
    $description = $args['description'] ?? '';

    if ( '' === $key ) {
        return;
    }

    $conflict = nova_bridge_suite_get_module_conflict( $key );
    $disabled = '' !== $conflict;
    $checked  = ! empty( $settings[ $key ] ) && ! $disabled;
    $name     = NOVA_BRIDGE_SUITE_OPTION . '[' . $key . ']';
    $input_id = 'nova-bridge-suite-' . $key;

    echo '<label for="' . esc_attr( $input_id ) . '"><input type="checkbox" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . ( $disabled ? ' disabled' : '' ) . '> ' . esc_html( $label ) . '</label>';

    if ( $disabled ) {
        $message = sprintf(
            'Standalone plugin active (%s). Deactivate it to enable this module.',
            $conflict
        );
        echo '<p class="description">' . esc_html( $message ) . '</p>';
        return;
    }

    if ( '' !== $description ) {
        echo '<p class="description">' . esc_html( $description ) . '</p>';
    }

    if ( ! $disabled ) {
        $inline_errors = nova_bridge_suite_get_inline_setting_errors( $key );
        foreach ( $inline_errors as $message ) {
            echo '<p class="description" style="color:#b32d2e;">' . esc_html( $message ) . '</p>';
        }
    }

    $recommendation = nova_bridge_suite_get_recommendation_for_key( $key );

    if ( $recommendation && 'woocommerce_rich_text' !== $key ) {
        $suggestion = $recommendation['description'] ?? '';
        if ( '' !== $suggestion ) {
            echo '<p class="description" style="color:#135e96;">' . esc_html( $suggestion ) . '</p>';
        }
    }

    if ( 'woocommerce_rich_text' === $key && $recommendation ) {
        $suggestion  = $recommendation['description'] ?? '';
        $dismiss_url = add_query_arg(
            [
                'page'                          => 'nova-settings',
                'nova_bridge_dismiss_suggestion'=> $key,
                '_nova_bridge_dismiss_nonce'    => wp_create_nonce( 'nova_bridge_suite_dismiss_suggestion_' . $key ),
            ],
            admin_url( 'options-general.php' )
        );

        if ( '' !== $suggestion ) {
            echo '<p class="description" style="color:#135e96;">' . esc_html( $suggestion ) . ' <a class="button-link" href="' . esc_url( $dismiss_url ) . '">' . esc_html__( 'Dismiss', 'nova-bridge-suite' ) . '</a></p>';
        }
    }
}
