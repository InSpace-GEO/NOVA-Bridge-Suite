<?php
/** Shared entry point for guided layouts. Navigation does not activate publishing. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Layout_Setup_Admin {
    private static $bootstrapped = false;

    public static function bootstrap(): void {
        if ( self::$bootstrapped ) { return; }
        self::$bootstrapped = true;
        add_filter( 'nova_bridge_suite_settings_tabs', [ __CLASS__, 'tabs' ], 102 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ], 110 );
        add_action( 'in_admin_header', [ __CLASS__, 'quiet_external_notices' ], PHP_INT_MAX );
    }

    /** Keep theme/plugin promotions out of this screen, retaining core and NOVA notices. */
    public static function quiet_external_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'settings_page_nova-settings' !== $screen->id || ! current_user_can( 'manage_options' ) || ! defined( 'WP_CONTENT_DIR' ) ) { return; }
        global $wp_filter;
        $content = rtrim( strtolower( wp_normalize_path( realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR ) ), '/' ) . '/';
        $suite = rtrim( strtolower( wp_normalize_path( realpath( NOVA_BRIDGE_SUITE_PLUGIN_DIR ) ?: NOVA_BRIDGE_SUITE_PLUGIN_DIR ) ), '/' ) . '/';
        foreach ( [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ] as $name ) {
            if ( ! isset( $wp_filter[ $name ]->callbacks ) ) { continue; }
            foreach ( $wp_filter[ $name ]->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $entry ) {
                    $callback = $entry['function'];
                    try {
                        if ( is_array( $callback ) && 2 === count( $callback ) ) { $reflection = new ReflectionMethod( $callback[0], $callback[1] ); }
                        elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) { $parts = explode( '::', $callback, 2 ); $reflection = new ReflectionMethod( $parts[0], $parts[1] ); }
                        elseif ( is_object( $callback ) && ! $callback instanceof Closure ) { $reflection = new ReflectionMethod( $callback, '__invoke' ); }
                        else { $reflection = new ReflectionFunction( $callback ); }
                        $file = $reflection->getFileName();
                    } catch ( Throwable $exception ) { continue; }
                    if ( ! is_string( $file ) ) { continue; }
                    $file = strtolower( wp_normalize_path( $file ) );
                    if ( 0 === strpos( $file, $content ) && 0 !== strpos( $file, $suite ) ) { remove_action( $name, $callback, $priority ); }
                }
            }
        }
    }

    public static function url( string $tab, array $args = [] ): string {
        if ( isset( $_GET['lang'] ) && is_scalar( $_GET['lang'] ) && ! isset( $args['lang'] ) ) { $args['lang'] = sanitize_text_field( (string) $_GET['lang'] ); }
        return add_query_arg( array_merge( $args, [ 'page' => 'nova-settings', 'tab' => $tab ] ), admin_url( 'options-general.php' ) );
    }

    public static function tabs( array $tabs ): array {
        $layout_views = isset( $tabs['strategy-mapping'] ) || isset( $tabs['content-rules'] );
        if ( ! $layout_views ) { return $tabs; }
        foreach ( [ 'strategy-mapping', 'content-rules' ] as $view ) {
            if ( isset( $tabs[ $view ] ) ) { $tabs[ $view ]['nav_parent'] = 'layouts'; }
        }
        $first = [ 'layouts' => [ 'label' => __( 'Layouts', 'nova-bridge-suite' ), 'render_callback' => [ __CLASS__, 'render' ] ] ];
        if ( class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ) {
            $first['nova-connection'] = [ 'label' => __( 'NOVA connection', 'nova-bridge-suite' ), 'render_callback' => [ __CLASS__, 'connection' ] ];
        }
        return $first + $tabs;
    }

    private static function asset_base(): string {
        return plugin_dir_url( NOVA_BRIDGE_SUITE_PLUGIN_FILE ) . 'modules/api-mapping-context/assets/';
    }

    public static function assets( string $hook ): void {
        if ( false === strpos( $hook, 'nova-settings' ) || ! current_user_can( 'manage_options' ) ) { return; }
        $directory = __DIR__ . '/../assets/';
        $base = self::asset_base();
        wp_enqueue_style( 'nova-layout-fonts', $base . 'brand/fonts.css', [], (string) filemtime( $directory . 'brand/fonts.css' ) );
        $dependencies = [ 'nova-layout-fonts' ];
        foreach ( [ 'nova-content-rules', 'nova-strategy-mapping', 'nova-mapping-drafts', 'nova-endpoint-mappings' ] as $style ) {
            if ( wp_style_is( $style, 'enqueued' ) ) { $dependencies[] = $style; }
        }
        wp_enqueue_style( 'nova-layout-brand', $base . 'mapping-brand.css', $dependencies, (string) filemtime( $directory . 'mapping-brand.css' ) );
        if ( 'nova-connection' === ( $_GET['tab'] ?? '' ) ) {
            wp_enqueue_script( 'nova-posting-admin', $base . 'posting-admin.js', [], (string) filemtime( $directory . 'posting-admin.js' ), true );
            wp_localize_script( 'nova-posting-admin', 'NovaStrategyAdmin', [ 'postingUrl' => esc_url_raw( rest_url( 'nova-bridge/v1/posting' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
        }
    }

    public static function screen_title( string $current_tab = '' ): string {
        if ( 'nova-connection' === $current_tab ) { return __( 'NOVA connection', 'nova-bridge-suite' ); }
        if ( 'modules' === $current_tab ) { return __( 'Modules', 'nova-bridge-suite' ); }
        return __( 'Layouts', 'nova-bridge-suite' );
    }

    public static function header( string $current_tab = '' ): void {
        echo '<header class="nova-brand-header"><img class="nova-brand-wordmark" src="' . esc_url( self::asset_base() . 'brand/inspace-wordmark.svg' ) . '" alt="InSpace" width="160" height="27">';
        echo '<div class="nova-brand-product"><p class="nova-brand-kicker">NOVA / WORDPRESS</p><p class="nova-brand-title">' . esc_html( self::screen_title( $current_tab ) ) . '</p></div>';
        echo '<span class="nova-brand-version">v' . esc_html( defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : '' ) . '</span></header>';
    }

    /** Existing mapping records remain owned by their original store and validation API. */
    public static function saved_existing(): array {
        global $wpdb;
        if ( ! isset( $wpdb->options ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->options ) ) { return []; }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM `' . $wpdb->options . '` WHERE option_name LIKE %s AND LENGTH(option_value) <= 1048576 ORDER BY option_name LIMIT 30', $wpdb->esc_like( 'nova_mapping_draft_' ) . '%' ), ARRAY_A );
        if ( ! is_array( $rows ) ) { return []; }
        $saved = [];
        foreach ( $rows as $row ) {
            if ( ! preg_match( '/^nova_mapping_draft_[0-9a-f]{64}$/D', $row['option_name'] ) || ! is_serialized( $row['option_value'] ) ) { continue; }
            $draft = @unserialize( $row['option_value'], [ 'allowed_classes' => false ] );
            if ( ! is_array( $draft ) || ( $draft['schema_version'] ?? null ) !== 1 || ! in_array( $draft['reference_type'] ?? '', [ 'post', 'term' ], true ) || ! is_int( $draft['reference_id'] ?? null ) || $draft['reference_id'] < 1 ) { continue; }
            $type = $draft['reference_type']; $id = $draft['reference_id'];
            if ( ! current_user_can( 'post' === $type ? 'edit_post' : 'edit_term', $id ) ) { continue; }
            $entity = 'post' === $type ? get_post( $id ) : get_term( $id );
            if ( ! $entity || is_wp_error( $entity ) ) { continue; }
            $label = is_string( $draft['label'] ?? null ) && '' !== trim( $draft['label'] ) ? $draft['label'] : ( 'post' === $type ? $entity->post_title : $entity->name );
            $saved[] = [ 'label' => sanitize_text_field( $label ), 'reference_type' => $type, 'reference_id' => $id ];
        }
        return $saved;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( class_exists( 'Nova_Bridge_Suite_Endpoint_Mappings_Admin' ) ) {
            Nova_Bridge_Suite_Endpoint_Mappings_Admin::render();
            echo '<details class="nova-layout-saved nova-layout-legacy-tools"><summary>Other layout tools</summary><p>Existing reference drafts and Elementor content rules remain available separately from endpoint creation defaults.</p><p><a href="' . esc_url( self::url( 'strategy-mapping', [ 'view' => 'workspace' ] ) ) . '">Reference field editor</a> · <a href="' . esc_url( self::url( 'content-rules', [ 'view' => 'workspace' ] ) ) . '">Elementor content rules</a></p>';
            self::saved();
            echo '</details>';
            return;
        }
        echo '<section class="nova-layout-start"><p class="nova-brand-kicker">GUIDED SETUP</p><h2>How should your articles fit your website?</h2>';
        echo '<p>Choose an approach for this layout. We will walk you through it. You can save other setups and edit them in a workspace afterward.</p>';
        echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '"><input type="hidden" name="page" value="nova-settings"><input type="hidden" name="view" value="setup"><input type="hidden" name="new" value="1">';
        if ( isset( $_GET['lang'] ) && is_scalar( $_GET['lang'] ) ) { echo '<input type="hidden" name="lang" value="' . esc_attr( sanitize_text_field( (string) $_GET['lang'] ) ) . '">'; }
        echo '<div class="nova-layout-choices">';
        echo '<label class="nova-layout-choice"><input type="radio" name="tab" value="strategy-mapping" aria-labelledby="nova-choice-existing" aria-describedby="nova-choice-existing-help" required><span class="nova-layout-choice-content"><span class="nova-brand-kicker">KEEP AN EXISTING DESIGN</span><strong id="nova-choice-existing">Fill an existing layout</strong><span id="nova-choice-existing-help">Choose an existing page or supported content item as your layout reference. Describe what belongs in its fields and what stays unchanged.</span><span class="nova-layout-example">Article title → the existing title field<br>Article body → the existing text area</span><span>Best for a fixed page design with known content slots.</span></span></label>';
        echo '<label class="nova-layout-choice"><input type="radio" name="tab" value="content-rules" aria-labelledby="nova-choice-build" aria-describedby="nova-choice-build-help" required><span class="nova-layout-choice-content"><span class="nova-brand-kicker">LET CONTENT SHAPE THE PAGE</span><strong id="nova-choice-build">Build new Elementor content</strong><span id="nova-choice-build-help">Choose which Elementor widget each content type becomes. New widgets are created in the article’s order.</span><span class="nova-layout-example">3 paragraphs → 3 Elementor Text Editor widgets<br>2 headings → 2 Elementor Heading widgets</span><span>No source page or separate field mapping is needed.</span></span></label>';
        echo '</div><button type="submit" class="button button-primary">Start guided setup</button></form>';
        echo '<div class="nova-layout-outcome"><h3>What does a mapping do?</h3><p>It decides how content belongs in your website. An existing-layout mapping describes fixed fields. Elementor layout rules turn headings, paragraphs and other blocks into widgets.</p><p>Saving a setup keeps it on this website. It does not publish an article, upload your rules to NOVA or change how NOVA writes.</p></div></section>';
        self::saved();
        echo '<aside class="nova-layout-connection-note"><h3>Connect NOVA when you need it</h3><p>Local setup, examples and Elementor test drafts work without a service connection. Connection details are shared setup, not another mapping.</p><a href="' . esc_url( self::url( 'nova-connection' ) ) . '">Open NOVA connection</a></aside>';
    }

    private static function saved(): void {
        $rules = class_exists( 'Nova_Bridge_Suite_Content_Rules' ) ? Nova_Bridge_Suite_Content_Rules::profiles() : [];
        $existing = self::saved_existing();
        if ( ! $rules && ! $existing ) { return; }
        echo '<details class="nova-layout-saved"><summary>' . esc_html( sprintf( __( 'Saved layouts (%d)', 'nova-bridge-suite' ), count( $rules ) + count( $existing ) ) ) . '</summary><p class="nova-brand-kicker">SAVED ON THIS WEBSITE</p><h2>Continue with a saved setup</h2><p>Open its workspace for changes, or restart its guided setup.</p><div class="nova-layout-saved-grid">';
        foreach ( $rules as $profile ) {
            echo '<article class="nova-layout-saved-card"><span class="nova-brand-kicker">ELEMENTOR LAYOUT RULES</span><h3>' . esc_html( $profile['label'] ) . '</h3><p>Every matching block creates its own widget. Saved revision ' . esc_html( (string) $profile['revision'] ) . '.</p>';
            echo '<a class="button" href="' . esc_url( self::url( 'content-rules', [ 'view' => 'workspace', 'profile_id' => $profile['id'] ] ) ) . '">Open workspace</a> <a href="' . esc_url( self::url( 'content-rules', [ 'view' => 'setup', 'profile_id' => $profile['id'] ] ) ) . '">Guided setup</a></article>';
        }
        foreach ( $existing as $draft ) {
            $args = [ 'reference_type' => $draft['reference_type'], 'reference_id' => $draft['reference_id'] ];
            echo '<article class="nova-layout-saved-card"><span class="nova-brand-kicker">EXISTING LAYOUT</span><h3>' . esc_html( $draft['label'] ) . '</h3><p>Existing fields and their descriptions. Saving alone does not enable content delivery.</p>';
            echo '<a class="button" href="' . esc_url( self::url( 'strategy-mapping', $args + [ 'view' => 'workspace' ] ) ) . '">Open workspace</a> <a href="' . esc_url( self::url( 'strategy-mapping', $args + [ 'view' => 'setup' ] ) ) . '">Guided setup</a></article>';
        }
        echo '</div></details>';
    }

    public static function connection(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $connection = Nova_Bridge_Suite_Posting_Settings::connection();
        echo '<section class="nova-connection-intro"><p class="nova-brand-kicker">OPTIONAL / SHARED SETUP</p><h2>NOVA connection</h2><p>A connection lets this website retrieve compatible content from NOVA. It is separate from choosing how that content becomes a page.</p>';
        echo '<p class="nova-layout-connection-state">' . esc_html( ! $connection['enabled'] ? 'Service connection is off. Local layout setup and test drafts are available.' : ( $connection['paused'] ? 'New content changes are paused. Recovery and result delivery can continue.' : 'Service connection is enabled. Each delivery still needs an approved compatible setup.' ) ) . '</p>';
        echo '<p>Saving layout rules does not switch this connection on. Review service compatibility and your layout setup before enabling automatic delivery.</p><a href="' . esc_url( self::url( 'layouts' ) ) . '">Back to layouts</a></section>';
        Nova_Bridge_Suite_Posting_Settings::render();
    }
}
