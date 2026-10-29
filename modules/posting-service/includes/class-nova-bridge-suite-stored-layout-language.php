<?php
/** Stored-layout pages use the site's native default language, independent of delivery metadata. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Layout_Language {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_stored_layout_language', $message, [ 'status' => 409 ] ); }
    private static function locale( string $value ): string { return strtolower( str_replace( '_', '-', $value ) ); }

    private static function native_default() {
        if ( function_exists( 'has_filter' ) && ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) ) {
            $code = apply_filters( 'wpml_default_language', null );
            return is_string( $code ) && '' !== $code ? $code : self::error( 'The native default WPML language is unavailable.' );
        }
        $locale = get_option( 'WPLANG', null );
        if ( null === $locale ) { $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US'; }
        return is_string( $locale ) ? str_replace( '_', '-', '' === $locale ? 'en_US' : $locale ) : self::error( 'The native default WordPress locale is unavailable.' );
    }
    /** The configured Polylang default owns native routing; payload language never selects a destination. */
    private static function polylang( string $post_type = 'page' ) {
        foreach ( [ 'pll_default_language', 'pll_languages_list', 'pll_home_url', 'pll_get_post_language', 'pll_set_post_language', 'pll_is_translated_post_type' ] as $function ) { if ( ! function_exists( $function ) ) { return self::error( 'The installed Polylang language API is incomplete.' ); } }
        $default = pll_default_language( 'slug' ); $default_locale = pll_default_language( 'locale' );
        if ( ! is_string( $default ) || '' === $default || ! is_string( $default_locale ) || ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', self::locale( $default_locale ) ) || ! pll_is_translated_post_type( $post_type ) ) { return self::error( 'The selected post type needs a verified site-default language before stored-layout delivery.' ); }
        $codes = pll_languages_list( [ 'fields' => 'slug', 'hide_empty' => 0 ] ); $locales = pll_languages_list( [ 'fields' => 'locale', 'hide_empty' => 0 ] );
        if ( ! is_array( $codes ) || ! is_array( $locales ) || count( $codes ) !== count( $locales ) || count( array_unique( $codes ) ) !== count( $codes ) ) { return self::error( 'Polylang did not return an unambiguous language catalog.' ); }
        $matches = [];
        foreach ( array_values( $codes ) as $index => $code ) { $locale = array_values( $locales )[$index]; if ( ! is_string( $code ) || '' === $code || ! is_string( $locale ) ) { return self::error( 'The configured language catalog is invalid.' ); } if ( $code === $default && self::locale( $locale ) === self::locale( $default_locale ) ) { $matches[] = [ 'code' => $code, 'locale' => $locale ]; } }
        if ( count( $matches ) !== 1 ) { return self::error( 'The native Polylang default does not match its configured language catalog.' ); }
        $context = $matches[0]; $home = pll_home_url( $context['code'] );
        $normalized = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $home );
        if ( is_wp_error( $normalized ) || isset( wp_parse_url( $home )['query'] ) ) { return self::error( 'The default Polylang language has no canonical pretty-permalink home URL.' ); }
        $site = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( get_option( 'home', home_url( '/' ) ) );
        if ( is_wp_error( $site ) || $normalized['origin'] !== $site['origin'] || ( $site['path'] !== '/' && $normalized['path'] !== $site['path'] && strpos( $normalized['path'], $site['path'] . '/' ) !== 0 ) ) { return self::error( 'This stored-layout adapter supports language paths on the connected WordPress origin only.' ); }
        $result = [ 'provider' => 'polylang', 'code' => $context['code'], 'locale' => $context['locale'], 'home' => $normalized['identity'] ];
        if ( 'page' !== $post_type ) { $result['post_type'] = $post_type; }
        return $result;
    }
    /** Existing pages keep their native identity and must already belong to the site default. */
    public static function existing( string $resource, int $id, string $url ) {
        $post = get_post( $id );
        if ( ! preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) || ! $post || $post->post_type !== $match[1] ) { return self::error( 'The saved layout must match the existing native post type.' ); }
        $wanted = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ); $native = Nova_Bridge_Suite_Mapped_Content_Target::native_url( $resource, 'post', $id );
        $actual = is_wp_error( $native ) ? $native : Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $native );
        if ( is_wp_error( $wanted ) || is_wp_error( $actual ) || $wanted['identity'] !== $actual['identity'] || isset( wp_parse_url( $url )['query'] ) ) { return self::error( 'The existing page must own the exact canonical delivery URL.' ); }
        if ( function_exists( 'pll_get_post_language' ) ) { $context = self::polylang( $post->post_type ); if ( is_wp_error( $context ) ) { return $context; } $language = $context['locale']; }
        else {
            $language = self::native_default(); if ( is_wp_error( $language ) ) { return $language; }
            $wpml = function_exists( 'has_filter' ) && ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) );
            $context = [ 'provider' => $wpml ? 'wpml' : 'core', 'code' => $wpml ? $language : self::locale( $language ) ];
        }
        $target = [ 'slug' => $post->post_name, 'parent' => (int) $post->post_parent, 'target_url' => $actual['identity'], 'language' => $language, 'native_language' => $context ];
        $verified = self::verify( $resource, $id, $target ); return is_wp_error( $verified ) ? $verified : $target;
    }
    public static function creation( string $resource, string $url, string $language ) {
        // The original delivery language remains in its content identity; native routing always uses the site default.
        if ( ! function_exists( 'pll_get_post_language' ) ) { $default = self::native_default(); return is_wp_error( $default ) ? $default : Nova_Bridge_Suite_Mapped_Content_Target::creation( $resource, $url, $default ); }
        if ( ! preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) ) { return self::error( 'Choose a registered native post destination.' ); }
        $type = $match[1]; $context = self::polylang( $type ); if ( is_wp_error( $context ) ) { return $context; }
        $wanted = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ); if ( is_wp_error( $wanted ) || isset( wp_parse_url( $url )['query'] ) ) { return self::error( 'The planned page needs a canonical native URL without query parameters.' ); }
        foreach ( pll_languages_list( [ 'fields' => 'slug', 'hide_empty' => 0 ] ) as $code ) {
            if ( $code === $context['code'] ) { continue; }
            $other = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( pll_home_url( $code ) );
            if ( ! is_wp_error( $other ) && $other['identity'] !== $context['home'] && $other['origin'] === $wanted['origin'] && $other['path'] !== '/' && ( $wanted['path'] === $other['path'] || strpos( $wanted['path'], $other['path'] . '/' ) === 0 ) ) { return self::error( 'The planned URL belongs to a nondefault language. Supply a URL in the site default language; this adapter does not rewrite delivery URLs.' ); }
        }
        global $wp_rewrite;
        $token = 'page' === $type ? '%pagename%' : ( 'post' === $type ? '%postname%' : '%' . $type . '%' );
        $structure = null;
        if ( is_object( $wp_rewrite ) ) {
            if ( 'page' === $type && method_exists( $wp_rewrite, 'get_page_permastruct' ) ) { $structure = $wp_rewrite->get_page_permastruct(); }
            elseif ( 'post' === $type ) { $structure = get_option( 'permalink_structure', '' ); }
            elseif ( method_exists( $wp_rewrite, 'get_extra_permastruct' ) ) { $structure = $wp_rewrite->get_extra_permastruct( $type ); }
        }
        if ( ! is_string( $structure ) || substr_count( $structure, $token ) !== 1 || preg_match( '/%[a-zA-Z0-9_]+%/', str_replace( $token, '', $structure ) ) ) { return self::error( 'This native permalink structure needs an explicit routing adapter.' ); }
        $marker = 'nova-stored-layout-route-slot'; $route = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( rtrim( $context['home'], '/' ) . '/' . ltrim( str_replace( $token, $marker, $structure ), '/' ) );
        if ( is_wp_error( $route ) || $wanted['origin'] !== $route['origin'] ) { return self::error( 'The planned URL belongs to a different language origin.' ); }
        $sides = explode( $marker, $route['path'] );
        if ( count( $sides ) !== 2 || strpos( $wanted['path'], $sides[0] ) !== 0 || ( $sides[1] !== '' && substr( $wanted['path'], -strlen( $sides[1] ) ) !== $sides[1] ) ) { return self::error( 'The planned URL does not use the site default Polylang language path.' ); }
        $tail = substr( $wanted['path'], strlen( $sides[0] ) ); if ( $sides[1] !== '' ) { $tail = substr( $tail, 0, -strlen( $sides[1] ) ); }
        if ( '' === $tail ) { return self::error( 'The delivery must identify a new page below its language home.' ); }
        $segments = explode( '/', $tail );
        if ( count( $segments ) > 1 && 'page' !== $type ) { $object = get_post_type_object( $type ); if ( ! $object || empty( $object->hierarchical ) ) { return self::error( 'The selected native post type does not support a parent hierarchy.' ); } }
        foreach ( $segments as $segment ) { $native = rawurldecode( $segment ); if ( '' === $native || strlen( $native ) > 180 || sanitize_title( $native ) !== $native ) { return self::error( 'The planned page path must use canonical native slugs.' ); } }
        $slug = rawurldecode( array_pop( $segments ) ); $parent = 0;
        if ( $segments ) {
            $parent_url = $wanted['origin'] . $sides[0] . implode( '/', $segments ) . $sides[1]; $resolved = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $parent_url, $resource );
            if ( is_wp_error( $resolved ) || pll_get_post_language( $resolved['reference_id'], 'slug' ) !== $context['code'] ) { return self::error( 'The requested native parent page must already exist in the same Polylang language.' ); } $parent = $resolved['reference_id'];
        }
        $occupied = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $url );
        if ( ! is_wp_error( $occupied ) ) { return self::error( 'An existing native item already owns the planned URL.' ); }
        if ( $occupied->get_error_code() !== 'nova_mapped_target_not_found' ) { return $occupied; }
        return [ 'slug' => $slug, 'parent' => $parent, 'target_url' => $wanted['identity'], 'language' => $context['locale'], 'native_language' => $context ];
    }
    public static function with_context( array $target, callable $work ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) {
            $default = self::native_default();
            if ( is_wp_error( $default ) || self::locale( (string) ( $target['native_language']['code'] ?? '' ) ) !== self::locale( $default ) ) { return self::error( 'The retained native page target does not belong to the current site default language.' ); }
            return Nova_Bridge_Suite_Mapped_Content_Target::with_creation_language( $target, $work );
        }
        $current = self::polylang( $target['native_language']['post_type'] ?? 'page' );
        if ( is_wp_error( $current ) || $current !== $target['native_language'] ) { return self::error( 'The site default language or its native URL changed after planning. The retained page will not be moved.' ); }
        return $work();
    }
    /** Only the newly reserved owned page may have its language assigned. No translation relationship is created. */
    public static function assign( int $id, array $target ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) { return true; }
        return self::with_context( $target, static function () use ( $id, $target ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The configured actor cannot assign this reserved page language.' ); }
            $code = $target['native_language']['code']; if ( pll_get_post_language( $id, 'slug' ) !== $code ) { pll_set_post_language( $id, $code ); }
            return self::verify( 'post_type:' . ( $target['native_language']['post_type'] ?? 'page' ), $id, $target );
        } );
    }
    public static function verify( string $resource, int $id, array $target ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) { return self::with_context( $target, static function () use ( $resource, $id, $target ) { return Nova_Bridge_Suite_Mapped_Content_Target::verify_created_language( $resource, 'post', $id, $target ); } ); }
        $type = substr( $resource, strlen( 'post_type:' ) );
        if ( $resource !== 'post_type:' . ( $target['native_language']['post_type'] ?? 'page' ) ) { return self::error( 'The retained native language belongs to a different post type.' ); }
        $current = self::polylang( $type );
        if ( is_wp_error( $current ) || $current !== $target['native_language'] || pll_get_post_language( $id, 'slug' ) !== $current['code'] || self::locale( (string) pll_get_post_language( $id, 'locale' ) ) !== self::locale( $current['locale'] ) ) { return self::error( 'The created page does not have its exact planned Polylang language.' ); }
        return true;
    }
}
