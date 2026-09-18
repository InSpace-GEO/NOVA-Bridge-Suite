<?php
/** Private connection settings for the site's posting-service plugin credential. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Settings {
    public const OPTION = 'nova_bridge_posting_connection';
    private const GROUP = 'nova_bridge_posting_connection_settings';

    public static function bootstrap(): void {
        add_action( 'admin_init', [ __CLASS__, 'register' ] );
        add_action( 'update_option_' . self::OPTION, [ __CLASS__, 'updated' ], 10, 3 );
    }

    public static function register(): void {
        register_setting( self::GROUP, self::OPTION, [ 'type' => 'array', 'show_in_rest' => false, 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
        add_option( self::OPTION, [], '', 'no' );
    }

    public static function connection(): array {
        $value = get_option( self::OPTION, [] );
        $value = is_array( $value ) ? $value : [];
        $value = array_merge( [ 'base_url' => '', 'site_id' => '', 'token' => '', 'webhook_secret' => '', 'enabled' => false, 'paused' => true, 'actor_user_id' => 0 ], $value );
        foreach ( [ 'base_url' => 'NOVA_POSTING_SERVICE_URL', 'site_id' => 'NOVA_POSTING_SITE_ID', 'token' => 'NOVA_POSTING_TOKEN', 'webhook_secret' => 'NOVA_POSTING_WEBHOOK_SECRET' ] as $key => $constant ) {
            if ( defined( $constant ) ) { $value[ $key ] = (string) constant( $constant ); }
        }
        $value['enabled'] = ! empty( $value['enabled'] );
        $value['site_id'] = strtolower( $value['site_id'] );
        $value['paused'] = ! empty( $value['paused'] );
        $value['actor_user_id'] = (int) $value['actor_user_id'];
        return $value;
    }

    public static function updated( $old, $next, $option ): void {
        if ( ! empty( $next['enabled'] ) && class_exists( 'Nova_Bridge_Suite_Posting_Jobs' ) ) {
            $installed = ( new Nova_Bridge_Suite_Posting_Jobs() )->install();
            if ( true !== $installed ) { add_settings_error( self::OPTION, 'nova_posting_storage', 'Delivery storage could not be initialized. New deliveries remain unavailable.' ); }
        }
    }

    public static function sanitize( $input ): array {
        $old = get_option( self::OPTION, [] );
        $old = is_array( $old ) ? $old : [];
        if ( ! is_array( $input ) || ! current_user_can( 'manage_options' ) ) { return $old; }
        $next = $old;
        foreach ( [ 'base_url', 'site_id' ] as $key ) {
            if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) { $next[ $key ] = trim( $input[ $key ] ); }
        }
        $next['base_url'] = rtrim( $next['base_url'] ?? '', '/' );
        $next['site_id'] = strtolower( $next['site_id'] ?? '' );
        $parts = wp_parse_url( $next['base_url'] );
        if ( $next['base_url'] && ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || ! in_array( $parts['path'] ?? '', [ '', '/' ], true ) ) ) {
            add_settings_error( self::OPTION, 'nova_posting_url', 'Enter the HTTPS service origin without a path, credentials, query parameters or a fragment.' );
            return $old;
        }
        if ( ! empty( $next['site_id'] ) && ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $next['site_id'] ) ) {
            add_settings_error( self::OPTION, 'nova_posting_site', 'Enter the publishing site UUID issued by NOVA.' );
            return $old;
        }
        // Blank password controls retain the saved secret; secrets are never echoed.
        foreach ( [ 'token', 'webhook_secret' ] as $key ) {
            if ( isset( $input[ $key ] ) && is_string( $input[ $key ] ) && '' !== trim( $input[ $key ] ) ) {
                $secret = trim( $input[ $key ] );
                if ( strlen( $secret ) > 4096 || preg_match( '/[\x00-\x20\x7f]/', $secret ) ) {
                    add_settings_error( self::OPTION, 'nova_posting_secret', 'Credentials must be a single token without whitespace.' );
                    return $old;
                }
                $next[ $key ] = $secret;
            }
        }
        $next['enabled'] = ! empty( $input['enabled'] );
        $next['paused'] = ! empty( $input['paused'] );
        $next['actor_user_id'] = get_current_user_id();
        // A different service/site must not accidentally inherit permission to publish.
        if ( ( $old['base_url'] ?? '' ) !== ( $next['base_url'] ?? '' ) || ( $old['site_id'] ?? '' ) !== ( $next['site_id'] ?? '' ) ) {
            if ( ! empty( $old['site_id'] ) && class_exists( 'Nova_Bridge_Suite_Posting_Jobs' ) ) {
                $unfinished = Nova_Bridge_Suite_Posting_Jobs::has_unfinished_for_site( $old['site_id'] );
                if ( false !== $unfinished ) {
                    add_settings_error( self::OPTION, 'nova_posting_unfinished', 'Finish or reconcile retained deliveries before changing the posting service or site. The existing connection has been retained.' );
                    return $old;
                }
            }
            $next['paused'] = true;
        }
        return $next;
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = self::connection();
        echo '<details class="nova-mapping-advanced"><summary>NOVA posting service</summary><p>Connect this WordPress site using its NOVA plugin token. The token and webhook signing secret are issued through NOVA site administration.</p>';
        settings_errors( self::OPTION );
        echo '<form method="post" action="options.php">';
        settings_fields( self::GROUP );
        foreach ( [ 'base_url' => 'Service base URL', 'site_id' => 'Publishing site UUID', 'token' => 'Plugin token', 'webhook_secret' => 'Webhook signing secret' ] as $key => $label ) {
            $secret = in_array( $key, [ 'token', 'webhook_secret' ], true );
            echo '<p><label for="nova-posting-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><br><input class="regular-text" id="nova-posting-' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION . '[' . $key . ']' ) . '" type="' . ( $secret ? 'password' : 'text' ) . '" value="' . ( $secret ? '' : esc_attr( (string) $settings[ $key ] ) ) . '" autocomplete="' . ( $secret ? 'new-password' : 'off' ) . '">';
            if ( $secret ) { echo '<br><span class="description">' . ( ! empty( $settings[ $key ] ) ? 'A credential is configured. Leave blank to keep it.' : 'No credential configured.' ) . '</span>'; }
            echo '</p>';
        }
        echo '<p><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[enabled]" value="1" ' . checked( $settings['enabled'], true, false ) . '> Enable this service connection</label></p>';
        echo '<p><label><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[paused]" value="1" ' . checked( $settings['paused'], true, false ) . '> Pause new content mutations; continue recovery and result delivery</label></p>';
        echo '<p>Confirm service compatibility with NOVA before unpausing, then verify a controlled test delivery.</p>';
        echo '<p>Publishing uses the WordPress permissions of the administrator saving these settings. Changing the service or site pauses new mutations until you resume them.</p>';
        submit_button( 'Save NOVA connection' );
        echo '</form><p>Ready notification URL: <code>' . esc_html( rest_url( 'nova-bridge/v1/posting/ready' ) ) . '</code></p><div id="nova-posting-status"></div></details>';
    }
}
