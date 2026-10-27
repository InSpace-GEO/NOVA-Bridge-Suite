<?php
/** SEO metadata for NOVA-owned CPT deliveries. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Owned_CPT_SEO {
    private const TITLE = '_nova_posting_seo_title';
    private const DESCRIPTION = '_nova_posting_seo_description';
    private const PROVIDERS = [
        'wp-seopress/seopress.php' => [ 'title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc' ],
        'wordpress-seo/wp-seo.php' => [ 'title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc' ],
        'seo-by-rank-math/rank-math.php' => [ 'title' => 'rank_math_title', 'description' => 'rank_math_description' ],
    ];
    // Avoid emitting a second description if another recognized SEO plugin owns the head.
    private const OTHER_PROVIDERS = [ 'all-in-one-seo-pack/all_in_one_seo_pack.php', 'autodescription/autodescription.php', 'smartcrawl-seo/wpmu-dev-seo.php', 'wpmu-dev-seo/wpmu-dev-seo.php', 'squirrly-seo/squirrly.php', 'slim-seo/slim-seo.php' ];

    public static function bootstrap(): void {
        add_filter( 'pre_get_document_title', [ __CLASS__, 'native_title' ], PHP_INT_MAX );
        add_action( 'wp_head', [ __CLASS__, 'native_description' ], 1 );
        add_filter( 'wpseo_metadesc', [ __CLASS__, 'yoast_description' ], PHP_INT_MAX );
        add_filter( 'wpseo_opengraph_desc', [ __CLASS__, 'yoast_description' ], PHP_INT_MAX );
    }

    public static function available(): bool { return true; }

    /** Look at active plugins, including network-activated plugins, not files on disk. */
    public static function active_providers(): array {
        $active = get_option( 'active_plugins', [] );
        $active = is_array( $active ) ? $active : [];
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $network = get_site_option( 'active_sitewide_plugins', [] );
            if ( is_array( $network ) ) { $active = array_merge( $active, array_keys( $network ) ); }
        }
        return array_values( array_unique( array_filter( $active, 'is_string' ) ) );
    }

    /** Freeze the exact provider keys and supplied values in the writer's plan. */
    public static function plan( $title, $description ): array {
        $values = [];
        if ( is_string( $title ) && '' !== trim( $title ) ) { $values['title'] = sanitize_text_field( $title ); }
        if ( is_string( $description ) && '' !== trim( $description ) ) { $values['description'] = sanitize_text_field( $description ); }
        $values = array_filter( $values, static function ( $value ) { return '' !== $value; } );
        $writes = [];
        if ( isset( $values['title'] ) ) { $writes[self::TITLE] = $values['title']; }
        if ( isset( $values['description'] ) ) { $writes[self::DESCRIPTION] = $values['description']; }
        $active = self::active_providers();
        if ( $values && array_intersect( $active, self::OTHER_PROVIDERS ) ) {
            error_log( 'NOVA CPT SEO: an active SEO plugin is not supported for automatic metadata updates.' );
        }
        foreach ( $active as $plugin ) {
            if ( ! isset( self::PROVIDERS[$plugin] ) ) { continue; }
            foreach ( $values as $field => $value ) { $writes[self::PROVIDERS[$plugin][$field]] = $value; }
        }
        return $writes;
    }

    /** Include existence to distinguish absent metadata from an explicitly empty value. */
    public static function read( int $post_id, array $keys ): array {
        $result = [];
        foreach ( $keys as $key ) {
            $result[$key] = [ 'exists' => metadata_exists( 'post', $post_id, $key ), 'value' => (string) get_post_meta( $post_id, $key, true ) ];
        }
        return $result;
    }

    public static function matches( int $post_id, array $writes ): bool {
        foreach ( $writes as $key => $value ) {
            if ( ! metadata_exists( 'post', $post_id, $key ) || (string) get_post_meta( $post_id, $key, true ) !== $value ) { return false; }
        }
        return true;
    }

    /** Idempotent apply; the writer is responsible for its saved plan and concurrency checks. */
    public static function apply( int $post_id, array $writes ) {
        foreach ( $writes as $key => $value ) {
            if ( ! is_string( $key ) || ! is_string( $value ) ) { return new WP_Error( 'nova_owned_cpt_seo_invalid', 'Invalid SEO values.' ); }
            update_post_meta( $post_id, $key, wp_slash( $value ) );
        }
        if ( ! self::matches( $post_id, $writes ) ) { return new WP_Error( 'nova_owned_cpt_seo_save', 'The SEO metadata could not be verified.' ); }
        return true;
    }

    private static function current_id(): int {
        return function_exists( 'is_singular' ) && is_singular() ? (int) get_queried_object_id() : 0;
    }

    private static function has_seo_plugin(): bool {
        return (bool) array_intersect( self::active_providers(), array_merge( array_keys( self::PROVIDERS ), self::OTHER_PROVIDERS ) );
    }

    /** WordPress itself has no meta-description field; provide one only without an SEO plugin. */
    public static function native_title( $title ) {
        $id = self::current_id();
        if ( ! $id || self::has_seo_plugin() ) { return $title; }
        $custom = get_post_meta( $id, self::TITLE, true );
        return is_string( $custom ) && '' !== $custom ? $custom : $title;
    }
    public static function native_description(): void {
        $id = self::current_id();
        if ( ! $id || self::has_seo_plugin() ) { return; }
        $description = get_post_meta( $id, self::DESCRIPTION, true );
        if ( is_string( $description ) && '' !== $description ) { echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n"; }
    }

    /** The Service module otherwise replaces Yoast's explicit description with an intro excerpt. */
    public static function yoast_description( $description ) {
        $id = self::current_id();
        if ( ! $id || 'service_page' !== get_post_type( $id ) || ! metadata_exists( 'post', $id, self::DESCRIPTION ) ) { return $description; }
        $current = get_post_meta( $id, '_yoast_wpseo_metadesc', true );
        return is_string( $current ) && '' !== $current ? $current : $description;
    }
}
