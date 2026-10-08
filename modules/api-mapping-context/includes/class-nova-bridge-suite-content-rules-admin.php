<?php
/** Local content-to-layout editor and administrator-only endpoints. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Content_Rules_Admin {
    private const ROUTE = '/mapping/content-rules';

    public static function bootstrap(): void {
        add_filter( 'nova_bridge_suite_settings_tabs', [ __CLASS__, 'tab' ], 101 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ], 1003 );
    }

    public static function tab( array $tabs ): array {
        $entry = [ 'label' => __( 'Build from content', 'nova-bridge-suite' ), 'render_callback' => [ __CLASS__, 'render' ] ];
        $result = [];
        foreach ( $tabs as $key => $value ) {
            $result[ $key ] = $value;
            if ( 'strategy-mapping' === $key ) { $result['content-rules'] = $entry; }
        }
        if ( ! isset( $result['content-rules'] ) ) { $result = [ 'content-rules' => $entry ] + $result; }
        return $result;
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'nova-settings' ) || 'content-rules' !== ( $_GET['tab'] ?? '' ) || ! current_user_can( 'manage_options' ) ) { return; }
        $dir = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_DIR . 'assets/';
        $url = NOVA_BRIDGE_SUITE_API_MAPPING_CONTEXT_URL . 'assets/';
        wp_enqueue_style( 'nova-content-rules', $url . 'content-rules.css', [], (string) filemtime( $dir . 'content-rules.css' ) );
        wp_enqueue_script( 'nova-content-rules', $url . 'content-rules.js', [], (string) filemtime( $dir . 'content-rules.js' ), true );
        wp_localize_script( 'nova-content-rules', 'NovaContentRules', [
            'url' => esc_url_raw( rest_url( 'nova-bridge/v1' . self::ROUTE ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div id="nova-content-rules-app" class="ncr"><p role="status">Loading local content profiles…</p></div>';
    }

    public static function routes(): void {
        foreach ( [
            '/profiles' => [ [ 'GET', 'list_profiles' ], [ 'POST', 'save_profile' ] ],
            '/profiles/(?P<id>[0-9a-fA-F-]{36})' => [ [ 'GET', 'get_profile' ] ],
            '/preview' => [ [ 'POST', 'preview' ] ],
            '/create-draft' => [ [ 'POST', 'create_draft' ] ],
            '/binding' => [ [ 'GET', 'get_binding' ], [ 'POST', 'save_binding' ] ],
        ] as $path => $operations ) {
            $handlers = [];
            foreach ( $operations as $operation ) {
                $handlers[] = [ 'methods' => $operation[0], 'callback' => [ __CLASS__, $operation[1] ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ];
            }
            register_rest_route( 'nova-bridge/v1', self::ROUTE . $path, $handlers );
        }
    }

    public static function can_admin( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'forbidden', 'Administrator access is required.', 403 ); }
        $nonce = $request->get_header( 'x-wp-nonce' );
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) { return self::error( 'nonce', 'Reload this page to renew the WordPress session.', 403 ); }
        return true;
    }

    private static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_content_rules_admin_' . $code, $message, [ 'status' => $status ] );
    }

    private static function response( $data ) {
        if ( is_wp_error( $data ) ) { return $data; }
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }

    private static function body( $request ) {
        if ( strlen( (string) $request->get_body() ) > 1048576 ) { return self::error( 'size', 'The editor request exceeds 1 MiB.', 413 ); }
        $body = $request->get_json_params();
        return is_array( $body ) ? $body : self::error( 'request', 'Supply a JSON object.' );
    }

    private static function site_id(): string {
        if ( ! class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) { return ''; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        return (string) ( $connection['site_id'] ?? '' );
    }

    public static function list_profiles( $request ) {
        $types = [];
        foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $type ) {
            if ( ! in_array( $type->name, [ 'attachment', 'revision', 'nav_menu_item', 'elementor_library' ], true ) && post_type_supports( $type->name, 'editor' ) ) {
                $types[] = [ 'value' => $type->name, 'label' => $type->labels->singular_name ];
            }
        }
        return self::response( [ 'profiles' => Nova_Bridge_Suite_Content_Rules::profiles(), 'preset' => Nova_Bridge_Suite_Content_Rules::preset(), 'post_types' => $types, 'connected_site_id' => self::site_id() ] );
    }

    public static function get_profile( $request ) {
        return self::response( Nova_Bridge_Suite_Content_Rules::get( (string) $request->get_param( 'id' ) ) );
    }

    public static function save_profile( $request ) {
        $body = self::body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        if ( ! isset( $body['profile'] ) || ! is_array( $body['profile'] ) || ! isset( $body['expected_revision'] ) || ! is_int( $body['expected_revision'] ) ) { return self::error( 'request', 'Supply the profile and its saved revision.' ); }
        return self::response( Nova_Bridge_Suite_Content_Rules::save( $body['profile'], $body['expected_revision'] ) );
    }

    private static function saved_profile( array $body ) {
        if ( ! is_string( $body['profile_id'] ?? null ) || ! is_int( $body['profile_revision'] ?? null ) ) { return self::error( 'profile', 'Save and select an exact local profile revision.' ); }
        $profile = Nova_Bridge_Suite_Content_Rules::get( $body['profile_id'] );
        if ( is_wp_error( $profile ) ) { return $profile; }
        if ( ! is_array( $profile ) ) { return self::error( 'profile', 'The local profile was not found.', 404 ); }
        if ( $profile['revision'] !== $body['profile_revision'] ) { return self::error( 'revision', 'This profile changed elsewhere. Reload it before continuing.', 409 ); }
        return $profile;
    }

    public static function preview( $request ) {
        $body = self::body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        if ( ! is_array( $body['profile'] ?? null ) || ! is_array( $body['input'] ?? null ) ) { return self::error( 'preview', 'Supply a profile and sample content.' ); }
        $result = Nova_Bridge_Suite_Content_Rules::render( $body['profile'], $body['input'], 'admin-preview' );
        if ( is_wp_error( $result ) ) { return $result; }
        $fragment = (string) ( $result['preview_html'] ?? '' );
        // HTML comes only from the server renderer. The iframe has no script or same-origin privilege.
        $document = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src https:; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'"><style>body{font:16px/1.65 system-ui,sans-serif;color:#202b3c;background:#fff;margin:0;padding:28px}main{max-width:760px;margin:auto}h1,h2,h3,h4,h5,h6{line-height:1.3;margin:1.4em 0 .6em}p{margin:0 0 1em}img{max-width:100%;height:auto}ul,ol{padding-left:1.5em}details{border:1px solid #dbe2ea;border-radius:8px;padding:12px;margin:8px 0}summary{font-weight:600;cursor:pointer}section{margin-bottom:1.5em}a{color:#315ab0}</style></head><body><main>' . wp_kses_post( $fragment ) . '</main></body></html>';
        return self::response( [ 'preview_document' => $document, 'blocks' => $result['blocks'] ?? [], 'warnings' => $result['warnings'] ?? [], 'renderer_version' => $result['renderer_version'] ?? '' ] );
    }

    public static function create_draft( $request ) {
        $body = self::body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        $profile = self::saved_profile( $body );
        if ( is_wp_error( $profile ) ) { return $profile; }
        if ( ! is_array( $body['input'] ?? null ) || ! is_string( $body['operation_uuid'] ?? null ) || ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $body['operation_uuid'] ) ) { return self::error( 'draft', 'Supply content and a draft operation ID.' ); }
        if ( ! class_exists( 'Nova_Bridge_Suite_Rule_Writer' ) ) { return self::error( 'writer', 'The Elementor draft writer is unavailable.', 503 ); }
        return self::response( Nova_Bridge_Suite_Rule_Writer::create_draft( $profile, $body['input'], $body['operation_uuid'] ) );
    }

    public static function get_binding( $request ) {
        $site = self::site_id();
        return self::response( [ 'site_id' => $site, 'binding' => '' !== $site ? Nova_Bridge_Suite_Content_Rules::binding( $site ) : null ] );
    }

    public static function save_binding( $request ) {
        $body = self::body( $request );
        if ( is_wp_error( $body ) ) { return $body; }
        $site = self::site_id();
        if ( '' === $site || $site !== ( $body['site_id'] ?? '' ) || ! is_bool( $body['enabled'] ?? null ) ) { return self::error( 'site', 'Select the currently configured posting site and an explicit delivery preference.' ); }
        if ( ! $body['enabled'] ) { return self::response( Nova_Bridge_Suite_Content_Rules::unbind( $site ) ); }
        $profile = self::saved_profile( $body );
        if ( is_wp_error( $profile ) ) { return $profile; }
        if ( ! in_array( $profile['post_type'], [ 'page', 'post' ], true ) ) { return self::error( 'delivery_post_type', 'Fetched deliveries support pages or posts with simple post-name URLs. Custom post types remain available for local draft creation.' ); }
        return self::response( Nova_Bridge_Suite_Content_Rules::bind( $site, $profile['id'], $profile['revision'] ) );
    }
}
