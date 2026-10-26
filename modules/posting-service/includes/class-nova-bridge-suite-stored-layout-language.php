<?php
/** Language routing scoped to independent stored-layout pages; no translation links or site settings. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Layout_Language {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_stored_layout_language', $message, [ 'status' => 409 ] ); }
    private static function locale( string $value ): string { return strtolower( str_replace( '_', '-', $value ) ); }

    /** Use documented Polylang APIs, with exact configured locale matching (never first-language fallback). */
    private static function polylang( string $language ) {
        foreach ( [ 'pll_languages_list', 'pll_home_url', 'pll_get_post_language', 'pll_set_post_language', 'pll_is_translated_post_type' ] as $function ) { if ( ! function_exists( $function ) ) { return self::error( 'The installed Polylang language API is incomplete.' ); } }
        $requested = self::locale( $language );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $requested ) || ! pll_is_translated_post_type( 'page' ) ) { return self::error( 'Choose an exact configured page locale before stored-layout delivery.' ); }
        $codes = pll_languages_list( [ 'fields' => 'slug', 'hide_empty' => 0 ] ); $locales = pll_languages_list( [ 'fields' => 'locale', 'hide_empty' => 0 ] );
        if ( ! is_array( $codes ) || ! is_array( $locales ) || count( $codes ) !== count( $locales ) || count( array_unique( $codes ) ) !== count( $codes ) ) { return self::error( 'Polylang did not return an unambiguous language catalog.' ); }
        $matches = [];
        foreach ( array_values( $codes ) as $index => $code ) { $locale = array_values( $locales )[$index]; if ( ! is_string( $code ) || '' === $code || ! is_string( $locale ) ) { return self::error( 'The configured language catalog is invalid.' ); } if ( $requested === self::locale( $locale ) ) { $matches[] = [ 'code' => $code, 'locale' => $locale ]; } }
        if ( count( $matches ) !== 1 ) { return self::error( 'The delivery locale must match exactly one Polylang locale, such as nl-NL or nl-BE. Generic or ambiguous languages are not inferred.' ); }
        $context = $matches[0]; $home = pll_home_url( $context['code'] );
        $normalized = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $home );
        if ( is_wp_error( $normalized ) || isset( wp_parse_url( $home )['query'] ) ) { return self::error( 'The requested Polylang language has no canonical pretty-permalink home URL.' ); }
        $site = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( get_option( 'home', home_url( '/' ) ) );
        if ( is_wp_error( $site ) || $normalized['origin'] !== $site['origin'] || ( $site['path'] !== '/' && $normalized['path'] !== $site['path'] && strpos( $normalized['path'], $site['path'] . '/' ) !== 0 ) ) { return self::error( 'This stored-layout adapter supports language paths on the connected WordPress origin only.' ); }
        return [ 'provider' => 'polylang', 'code' => $context['code'], 'locale' => $context['locale'], 'home' => $normalized['identity'] ];
    }
    public static function creation( string $resource, string $url, string $language ) {
        if ( ! function_exists( 'pll_get_post_language' ) ) { return Nova_Bridge_Suite_Mapped_Content_Target::creation( $resource, $url, $language ); }
        if ( $resource !== 'post_type:page' ) { return self::error( 'Polylang stored-layout creation currently supports native pages only.' ); }
        $context = self::polylang( $language ); if ( is_wp_error( $context ) ) { return $context; }
        $wanted = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( $url ); if ( is_wp_error( $wanted ) || isset( wp_parse_url( $url )['query'] ) ) { return self::error( 'The planned page needs a canonical native URL without query parameters.' ); }
        global $wp_rewrite;
        $structure = is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'get_page_permastruct' ) ? $wp_rewrite->get_page_permastruct() : null;
        if ( ! is_string( $structure ) || substr_count( $structure, '%pagename%' ) !== 1 || preg_match( '/%[a-zA-Z0-9_]+%/', str_replace( '%pagename%', '', $structure ) ) ) { return self::error( 'This native page permalink structure needs an explicit routing adapter.' ); }
        $marker = 'nova-stored-layout-route-slot'; $route = Nova_Bridge_Suite_Mapped_Content_Target::normalize_url( rtrim( $context['home'], '/' ) . '/' . ltrim( str_replace( '%pagename%', $marker, $structure ), '/' ) );
        if ( is_wp_error( $route ) || $wanted['origin'] !== $route['origin'] ) { return self::error( 'The planned URL belongs to a different language origin.' ); }
        $sides = explode( $marker, $route['path'] );
        if ( count( $sides ) !== 2 || strpos( $wanted['path'], $sides[0] ) !== 0 || ( $sides[1] !== '' && substr( $wanted['path'], -strlen( $sides[1] ) ) !== $sides[1] ) ) { return self::error( 'The planned URL does not use the requested Polylang language path.' ); }
        $tail = substr( $wanted['path'], strlen( $sides[0] ) ); if ( $sides[1] !== '' ) { $tail = substr( $tail, 0, -strlen( $sides[1] ) ); }
        if ( '' === $tail ) { return self::error( 'The delivery must identify a new page below its language home.' ); }
        $segments = explode( '/', $tail );
        foreach ( $segments as $segment ) { $native = rawurldecode( $segment ); if ( '' === $native || strlen( $native ) > 180 || sanitize_title( $native ) !== $native ) { return self::error( 'The planned page path must use canonical native slugs.' ); } }
        $slug = rawurldecode( array_pop( $segments ) ); $parent = 0;
        if ( $segments ) {
            $parent_url = $wanted['origin'] . $sides[0] . implode( '/', $segments ) . $sides[1]; $resolved = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $parent_url, $resource );
            if ( is_wp_error( $resolved ) || pll_get_post_language( $resolved['reference_id'], 'slug' ) !== $context['code'] ) { return self::error( 'The requested native parent page must already exist in the same Polylang language.' ); } $parent = $resolved['reference_id'];
        }
        $occupied = Nova_Bridge_Suite_Mapped_Content_Target::resolve( $url );
        if ( ! is_wp_error( $occupied ) ) { return self::error( 'An existing native item already owns the planned URL.' ); }
        if ( $occupied->get_error_code() !== 'nova_mapped_target_not_found' ) { return $occupied; }
        return [ 'slug' => $slug, 'parent' => $parent, 'target_url' => $wanted['identity'], 'language' => $language, 'native_language' => $context ];
    }
    public static function with_context( array $target, callable $work ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) { return Nova_Bridge_Suite_Mapped_Content_Target::with_creation_language( $target, $work ); }
        $current = self::polylang( $target['language'] );
        if ( is_wp_error( $current ) || $current !== $target['native_language'] ) { return self::error( 'The requested language or native language URL changed after planning.' ); }
        return $work();
    }
    /** Only the newly reserved owned page may have its language assigned. No translation relationship is created. */
    public static function assign( int $id, array $target ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) { return true; }
        return self::with_context( $target, static function () use ( $id, $target ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The configured actor cannot assign this reserved page language.' ); }
            $code = $target['native_language']['code']; if ( pll_get_post_language( $id, 'slug' ) !== $code ) { pll_set_post_language( $id, $code ); }
            return self::verify( 'post_type:page', $id, $target );
        } );
    }
    public static function verify( string $resource, int $id, array $target ) {
        if ( ( $target['native_language']['provider'] ?? '' ) !== 'polylang' ) { return Nova_Bridge_Suite_Mapped_Content_Target::verify_created_language( $resource, 'post', $id, $target ); }
        $current = self::polylang( $target['language'] );
        if ( is_wp_error( $current ) || $current !== $target['native_language'] || pll_get_post_language( $id, 'slug' ) !== $current['code'] || self::locale( (string) pll_get_post_language( $id, 'locale' ) ) !== self::locale( $current['locale'] ) ) { return self::error( 'The created page does not have its exact planned Polylang language.' ); }
        return true;
    }
}
