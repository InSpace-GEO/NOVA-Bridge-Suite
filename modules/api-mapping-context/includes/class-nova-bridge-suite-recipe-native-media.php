<?php
/** Pure authoring contract for explicitly selected native image destinations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Native_Media {
    public const FEATURED = '/native_post/featured_image';

    public static function featured_field(): array {
        return [ 'target' => self::FEATURED, 'label' => 'Featured image', 'kind' => 'image_url', 'format' => 'url', 'allowed_formats' => [ 'url' ], 'writable' => true, 'required' => false,
            'value' => '', 'sample' => '', 'instructions' => 'Choose the primary article image from image_url or image_urls. Return its exact image URL. If no suitable image is supplied, leave this optional field empty.', 'native_media' => [ 'kind' => 'featured' ] ];
    }

    public static function describe( array $description, string $resource ): array {
        if ( preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) && function_exists( 'post_type_supports' ) && post_type_supports( $match[1], 'thumbnail' ) ) {
            $description['fields'][] = self::featured_field();
        }
        return $description;
    }

    /** Derive native pointers from the adapter, never from client-supplied metadata. */
    public static function spec( array $native, array $binding, $adapter ): ?array {
        $featured = self::FEATURED === ( $binding['target'] ?? '' );
        if ( ! array_key_exists( 'native_media', $binding ) && ! $featured ) { return null; }
        if ( true !== ( $binding['native_media'] ?? null ) || 'url' !== ( $binding['format'] ?? '' ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Native image mappings require an explicit URL binding.' ); }
        if ( $featured ) { return [ 'kind' => 'featured' ]; }
        if ( ! is_callable( [ $adapter, 'describe' ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'This image field has no native media descriptor.' ); }
        $description = $adapter->describe( $native );
        if ( is_wp_error( $description ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( $description->get_error_message() ); }
        foreach ( $description['fields'] ?? [] as $field ) {
            if ( ( $field['target'] ?? '' ) !== $binding['target'] ) { continue; }
            $spec = $field['native_media'] ?? null;
            if ( empty( $field['writable'] ) || ! is_array( $spec ) || ! is_string( $spec['id_target'] ?? null ) ) { break; }
            Nova_Bridge_Suite_Recipe_Engine::parts( $spec['id_target'] );
            if ( ( $spec['kind'] ?? '' ) === 'elementor' && ( $spec['url_target'] ?? '' ) === $binding['target'] ) {
                return [ 'kind' => 'elementor', 'url_target' => $spec['url_target'], 'id_target' => $spec['id_target'] ];
            }
            if ( ( $spec['kind'] ?? '' ) === 'flatsome' && $spec['id_target'] === $binding['target'] && $adapter instanceof Nova_Bridge_Suite_Recipe_Flatsome ) {
                return [ 'kind' => 'flatsome', 'id_target' => $spec['id_target'] ];
            }
            break;
        }
        Nova_Bridge_Suite_Recipe_Engine::fail( 'Only a captured native image control can use image import.' );
    }

    public static function entry( array $spec, string $url, bool $clear = false ): ?array {
        if ( '' === $url && ! $clear ) { return null; }
        if ( '' !== $url && ( ! filter_var( $url, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $url ) || preg_match( '/[\x00-\x20]/', $url ) || null !== parse_url( $url, PHP_URL_USER ) || null !== parse_url( $url, PHP_URL_PASS ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A mapped image must be an absolute HTTP(S) image URL.' ); }
        $entry = $spec + [ 'url' => $url ];
        if ( $clear ) { $entry['clear'] = true; }
        return $entry;
    }
}
