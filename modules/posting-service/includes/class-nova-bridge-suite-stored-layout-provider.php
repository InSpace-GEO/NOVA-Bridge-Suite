<?php
/** Known-ID native saves for the stored-layout journal. These providers never create posts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Layout_Provider {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_layout_provider', $message, [ 'status' => 409 ] ); }
    private static function adapter( string $builder ) {
        if ( ! class_exists( 'Nova_Bridge_Suite_Recipe_Engine' ) ) { require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-engine.php'; }
        return Nova_Bridge_Suite_Recipe_Engine::adapter( $builder );
    }
    /** A registered recipe adapter may implement this complete contract for another native builder. */
    private static function extension( string $builder ) {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        foreach ( [ 'publication_support', 'publication_probe', 'publication_snapshot', 'publication_apply', 'publication_verify' ] as $method ) {
            if ( ! is_callable( [ $adapter, $method ] ) ) { return self::error( 'This builder can prepare layouts, but has no complete known-ID publishing and recovery provider yet.' ); }
        }
        return $adapter;
    }
    public static function support( string $builder, string $type ): array {
        $object = get_post_type_object( $type );
        if ( ! $object || empty( $object->cap->edit_posts ) || ! current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) || ! current_user_can( $object->cap->edit_posts ) ) { return [ 'supported' => false, 'reason' => 'The publishing actor cannot create and edit this native post type.' ]; }
        if ( 'elementor' === $builder ) {
            if ( ! class_exists( '\Elementor\Plugin' ) || ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) ) { return [ 'supported' => false, 'reason' => 'Enable Elementor and the NOVA Elementor bridge before publishing this layout.' ]; }
            $support = get_option( 'elementor_cpt_support', [ 'post', 'page' ] );
            if ( ! in_array( $type, is_array( $support ) ? $support : [], true ) && ( ! function_exists( 'post_type_supports' ) || ! post_type_supports( $type, 'elementor' ) ) ) { return [ 'supported' => false, 'reason' => 'Enable Elementor for this native post type.' ]; }
            $plugin = \Elementor\Plugin::instance();
            if ( ! isset( $plugin->documents ) || ! is_callable( [ $plugin->documents, 'get' ] ) || ! class_exists( '\SEOR_Elementor_Bridge\Element_Registry' ) || ! \SEOR_Elementor_Bridge\Element_Registry::available( $plugin ) ) { return [ 'supported' => false, 'reason' => 'The native Elementor document lifecycle is unavailable.' ]; }
        } elseif ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) {
            if ( ! function_exists( 'wp_update_post' ) || ( function_exists( 'post_type_supports' ) && ! post_type_supports( $type, 'editor' ) ) ) { return [ 'supported' => false, 'reason' => 'This native destination does not expose a writable WordPress content field.' ]; }
            if ( 'gutenberg' === $builder && ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) ) { return [ 'supported' => false, 'reason' => 'The WordPress block document lifecycle is unavailable.' ]; }
        } else {
            $adapter = self::extension( $builder );
            if ( is_wp_error( $adapter ) ) { return [ 'supported' => false, 'reason' => $adapter->get_error_message() ]; }
            $result = $adapter->publication_support( $type );
            return is_array( $result ) && true === ( $result['supported'] ?? false ) ? [ 'supported' => true, 'reason' => '' ] : [ 'supported' => false, 'reason' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['reason'] ?? 'The native publishing provider is unavailable for this destination.' ) ];
        }
        return [ 'supported' => true, 'reason' => '' ];
    }
    public static function probe( string $builder, string $type, array $payload, string $status ) {
        $support = self::support( $builder, $type ); if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
        $object = get_post_type_object( $type );
        if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ?? 'publish_posts' ) ) { return self::error( 'The publishing actor cannot publish this native post type.' ); }
        if ( isset( $payload['theme_presentation'] ) ) { $result = Nova_Bridge_Suite_Theme_Presentation::assert_current( $payload['theme_presentation'] ); if ( is_wp_error( $result ) ) { return $result; } }
        global $wp_version;
        if ( 'elementor' === $builder ) {
            $plugin = \Elementor\Plugin::instance();
            $walk = static function ( array $nodes ) use ( &$walk, $plugin ) {
                foreach ( $nodes as $node ) {
                    if ( ! \SEOR_Elementor_Bridge\Element_Registry::contains( $plugin, $node['elType'] ?? '', $node['widgetType'] ?? null ) ) { return self::error( 'A widget used by the stored layout is unavailable. No native item was created.' ); }
                    $result = $walk( $node['elements'] ?? [] ); if ( is_wp_error( $result ) ) { return $result; }
                }
                return true;
            };
            $result = $walk( $payload['elementor_data'] ?? [] ); if ( is_wp_error( $result ) ) { return $result; }
            // Do not change the provider tuple frozen into earlier Elementor operation journals.
            return [ 'wordpress' => (string) $wp_version, 'elementor' => (string) ELEMENTOR_VERSION, 'bridge' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '' ];
        }
        if ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) {
            $checked = self::content_payload( $builder, $payload ); if ( is_wp_error( $checked ) ) { return $checked; }
            return [ 'wordpress' => (string) $wp_version, 'builder' => $builder, 'provider_version' => 'known-post-v1', 'bridge' => defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '' ];
        }
        $adapter = self::extension( $builder ); return is_wp_error( $adapter ) ? $adapter : $adapter->publication_probe( $type, $payload, $status );
    }
    private static function content_payload( string $builder, array $payload ) {
        $field = 'gutenberg' === $builder ? 'native_document' : 'content';
        if ( ! is_string( $payload[$field] ?? null ) || ( isset( $payload['template'] ) && ! is_string( $payload['template'] ) ) || ( isset( $payload['meta'] ) && ! is_array( $payload['meta'] ) ) ) { return self::error( 'The compiled native document or template is invalid.' ); }
        if ( ! empty( $payload['meta_all'] ) ) { return self::error( 'Additional field providers need their own complete publication contract.' ); }
        foreach ( $payload['meta'] ?? [] as $key => $value ) {
            if ( ! is_string( $key ) || '' === $key || in_array( $key, [ '_nova_stored_layout_owner', '_edit_lock', '_edit_last' ], true ) ) { return self::error( 'The stored layout contains protected native identity metadata.' ); }
        }
        return [ 'content' => $payload[$field], 'template' => $payload['template'] ?? null, 'meta' => $payload['meta'] ?? [] ];
    }
    /** Retain exactly the provider's readback scope with the owned native association. */
    public static function snapshot_scope( string $builder, array $payload ): array {
        if ( 'elementor' === $builder ) { return []; }
        if ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) { return [ 'meta' => array_fill_keys( array_keys( $payload['meta'] ?? [] ), null ) ]; }
        return $payload;
    }
    private static function content_target( $post ) {
        if ( is_callable( [ 'Nova_Bridge_Suite_Recipe_Builders', 'native_carriers' ] ) ) {
            $carriers = Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post );
            if ( array_diff( $carriers, [ 'gutenberg' ] ) ) { return self::error( 'The existing item uses another native builder. Choose its matching saved layout before replacing its content.' ); }
        }
        return true;
    }
    public static function snapshot( string $builder, int $id, array $payload ) {
        if ( 'elementor' === $builder ) {
            $raw = get_post_meta( $id, '_elementor_data', true ); $data = '' === $raw ? [] : ( is_array( $raw ) ? $raw : json_decode( (string) $raw, true ) );
            if ( ! is_array( $data ) ) { return self::error( 'The retained Elementor document is unreadable.' ); }
            return [ 'elements' => $data, 'page_settings' => get_post_meta( $id, '_elementor_page_settings', true ) ];
        }
        if ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) {
            $post = get_post( $id ); if ( ! $post ) { return self::error( 'The native item no longer exists.' ); }
            $checked = self::content_target( $post ); if ( is_wp_error( $checked ) ) { return $checked; }
            $meta = [];
            foreach ( $payload['meta'] ?? [] as $key => $unused ) { $meta[$key] = [ 'exists' => metadata_exists( 'post', $id, $key ), 'value' => get_post_meta( $id, $key, true ) ]; }
            return [ 'content' => (string) $post->post_content, 'template' => get_page_template_slug( $id ), 'meta' => $meta ];
        }
        $adapter = self::extension( $builder ); return is_wp_error( $adapter ) ? $adapter : $adapter->publication_snapshot( $id, $payload );
    }
    /** The caller has retained the ID and saving checkpoint before entering this method. */
    public static function apply( string $builder, int $id, array $payload, array $post_fields ) {
        $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained native item cannot be edited.' ); }
        if ( 'elementor' === $builder ) {
            $body = $payload; $body['elementor_data'] = wp_json_encode( $payload['elementor_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            $body['title'] = wp_slash( $post_fields['title'] ); $body['excerpt'] = wp_slash( $post_fields['excerpt'] ?? '' );
            $result = ( new \SEOR_Elementor_Bridge\Elementor_Service() )->update_page( $id, $body ); if ( is_wp_error( $result ) ) { return $result; }
        } elseif ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) {
            $native = self::content_payload( $builder, $payload ); if ( is_wp_error( $native ) ) { return $native; }
            $checked = self::content_target( $post ); if ( is_wp_error( $checked ) ) { return $checked; }
            // Core handles both complete HTML and serialized block documents. Never call a create-only bridge route.
            $data = [ 'ID' => $id, 'post_content' => $native['content'], 'post_title' => $post_fields['title'], 'post_excerpt' => $post_fields['excerpt'] ?? '' ];
            $result = wp_update_post( wp_slash( $data ), true ); if ( is_wp_error( $result ) ) { return $result; }
            if ( (int) $result !== $id ) { return self::error( 'The native save did not return its retained item ID.' ); }
            foreach ( $native['meta'] as $key => $value ) { update_post_meta( $id, $key, wp_slash( $value ) ); }
            if ( null !== $native['template'] ) { update_post_meta( $id, '_wp_page_template', $native['template'] ); }
        } else {
            $adapter = self::extension( $builder ); if ( is_wp_error( $adapter ) ) { return $adapter; }
            $result = $adapter->publication_apply( $id, $payload, $post_fields ); if ( is_wp_error( $result ) ) { return $result; }
            if ( true !== $result ) { return self::error( 'The native adapter did not confirm its known-ID save.' ); }
        }
        if ( isset( $payload['theme_presentation'] ) ) { $result = Nova_Bridge_Suite_Theme_Presentation::apply( $id, $payload['theme_presentation'] ); if ( is_wp_error( $result ) ) { return $result; } }
        return self::verify( $builder, $id, $payload );
    }
    public static function verify( string $builder, int $id, array $payload ) {
        if ( 'elementor' === $builder ) { $result = Nova_Bridge_Suite_Recipe_Builders::verify_saved( $builder, $id, $payload ); }
        elseif ( in_array( $builder, [ 'core', 'gutenberg' ], true ) ) {
            $native = self::content_payload( $builder, $payload ); if ( is_wp_error( $native ) ) { return $native; }
            $post = get_post( $id );
            if ( ! $post || (string) $post->post_content !== $native['content'] || ( null !== $native['template'] && get_page_template_slug( $id ) !== $native['template'] ) ) { return self::error( 'The saved document or native template differs from the compiled layout.' ); }
            foreach ( $native['meta'] as $key => $value ) { $expected = is_scalar( $value ) || null === $value ? (string) $value : $value; if ( ! metadata_exists( 'post', $id, $key ) || Nova_Bridge_Suite_Recipe_Engine::digest( get_post_meta( $id, $key, true ) ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $expected ) ) { return self::error( 'The saved native settings differ from the compiled layout.' ); } }
            $result = true;
        } else {
            $adapter = self::extension( $builder ); $result = is_wp_error( $adapter ) ? $adapter : $adapter->publication_verify( $id, $payload );
            if ( ! is_wp_error( $result ) && true !== $result ) { return self::error( 'The native adapter did not confirm its saved document.' ); }
        }
        if ( is_wp_error( $result ) ) { return $result; }
        if ( ! $result ) { return self::error( 'The native provider could not verify the saved layout.' ); }
        if ( isset( $payload['theme_presentation'] ) ) { $result = Nova_Bridge_Suite_Theme_Presentation::verify( $id, $payload['theme_presentation'] ); if ( is_wp_error( $result ) ) { return $result; } }
        return true;
    }
}
