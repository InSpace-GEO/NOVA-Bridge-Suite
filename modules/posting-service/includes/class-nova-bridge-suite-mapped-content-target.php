<?php
/** Local exact URL ownership and editorial object resolution; never fetches a URL. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Mapped_Content_Target {
    private const BATCH = 250;
    private const MAX_OBJECTS = 10000;
    private static function error( string $code, string $message, int $status = 422 ): WP_Error { return new WP_Error( 'nova_mapped_' . $code, $message, [ 'status' => $status, 'error_code' => $code ] ); }
    /** Same identity rules as the mapped service; no redirects or basename aliases. */
    public static function normalize_url( $url ) {
        if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) ) { return self::error( 'target_url', 'Supply a complete native HTTPS URL.' ); }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || strtolower( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return self::error( 'target_url', 'The target must be an HTTPS URL without credentials or fragments.' ); }
        $port = $parts['port'] ?? 443; if ( ! is_int( $port ) || $port < 1 || $port > 65535 ) { return self::error( 'target_url', 'The target URL port is invalid.' ); }
        $origin = 'https://' . strtolower( $parts['host'] ) . ( 443 === $port ? '' : ':' . $port );
        $path = $parts['path'] ?? '/'; if ( '' === $path ) { $path = '/'; }
        if ( '/' !== $path[0] || false !== strpos( $path, '//' ) || preg_match( '/%(?![a-f0-9]{2})|%2f|%5c/i', $path ) ) { return self::error( 'target_url', 'The target path is ambiguous.' ); }
        $segments = explode( '/', $path ); $encoded = [];
        foreach ( $segments as $segment ) { $decoded = rawurldecode( $segment ); if ( in_array( $decoded, [ '.', '..' ], true ) || 1 !== preg_match( '//u', $decoded ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $decoded ) ) { return self::error( 'target_url', 'The target path is invalid.' ); } $encoded[] = rawurlencode( $decoded ); }
        $path = rtrim( implode( '/', $encoded ), '/' ); if ( '' === $path ) { $path = '/'; }
        $query = []; $seen = [];
        foreach ( isset( $parts['query'] ) && '' !== $parts['query'] ? explode( '&', $parts['query'] ) : [] as $pair ) {
            $pieces = explode( '=', $pair, 2 ); $key = rawurldecode( str_replace( '+', ' ', $pieces[0] ) ); $value = rawurldecode( str_replace( '+', ' ', $pieces[1] ?? '' ) );
            if ( '' === $key || isset( $seen[$key] ) || preg_match( '/[\x00-\x1f\x7f\[\]]/', $key . $value ) || preg_match( '/%(?![a-f0-9]{2})/i', $pair ) || 1 !== preg_match( '//u', $key . $value ) ) { return self::error( 'target_url', 'The target query is ambiguous.' ); }
            $seen[$key] = true; $query[] = rawurlencode( $key ) . '=' . rawurlencode( $value );
        }
        sort( $query, SORT_STRING ); $identity = $origin . $path . ( $query ? '?' . implode( '&', $query ) : '' );
        return [ 'origin' => $origin, 'path' => $path, 'identity' => $identity ];
    }
    private static function owned_url( $url ) {
        $target = self::normalize_url( $url ); if ( is_wp_error( $target ) ) { return $target; }
        // The stored home option avoids a request-language filter narrowing a shared
        // installation to just its current language's subdirectory.
        $home = get_option( 'home', home_url( '/' ) ); $site = self::normalize_url( is_string( $home ) && '' !== $home ? $home : home_url( '/' ) );
        if ( is_wp_error( $site ) || $target['origin'] !== $site['origin'] || ( '/' !== $site['path'] && $target['path'] !== $site['path'] && 0 !== strpos( $target['path'], $site['path'] . '/' ) ) ) { return self::error( 'foreign_target_url', 'This URL does not belong to the connected WordPress site.', 403 ); }
        return $target;
    }
    /** WPML's plain get_permalink can reflect the current request language. */
    public static function native_url( string $resource, string $kind, int $id ) {
        $term = 'term' === $kind; $name = substr( $resource, strpos( $resource, ':' ) + 1 ); $url = $term ? get_term_link( $id, $name ) : get_permalink( $id );
        if ( is_wp_error( $url ) || ! is_string( $url ) ) { return self::error( 'native_url', 'The native item has no canonical URL.' ); }
        if ( function_exists( 'has_filter' ) && ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) ) {
            $object = $term ? get_term( $id, $name ) : null; $element = $term ? (int) ( $object->term_taxonomy_id ?? 0 ) : $id; $details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $element, 'element_type' => ( $term ? 'tax_' : 'post_' ) . $name ] );
            $language = is_object( $details ) ? ( $details->language_code ?? '' ) : ( $details['language_code'] ?? '' );
            if ( ! is_string( $language ) || '' === $language ) { return false === apply_filters( $term ? 'wpml_is_translated_taxonomy' : 'wpml_is_translated_post_type', null, $name ) ? $url : self::error( 'native_language', 'The native item language could not be verified.' ); }
            $url = apply_filters( 'wpml_permalink', $url, $language, true ); if ( ! is_string( $url ) ) { return self::error( 'native_url', 'The native language URL could not be verified.' ); }
        }
        return $url;
    }
    private static function creation_language( string $language ) {
        $requested = strtolower( str_replace( '_', '-', $language ) );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $requested ) ) { return self::error( 'create_language_unsupported', 'Supply a verified native default language.' ); }
        if ( function_exists( 'pll_get_post_language' ) ) { return self::error( 'create_language_unsupported', 'The installed Polylang provider has no certified default-language creation context adapter yet.' ); }
        if ( function_exists( 'has_filter' ) && ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) ) {
            $code = apply_filters( 'wpml_default_language', null ); $languages = (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ); $locale = is_string( $code ) ? ( $languages[$code]['default_locale'] ?? '' ) : '';
            if ( ! is_string( $code ) || '' === $code || ! in_array( $requested, [ strtolower( $code ), strtolower( str_replace( '_', '-', (string) $locale ) ) ], true ) ) { return self::error( 'create_language_unsupported', 'Only the verified native default language can be created by this adapter. No item was inserted.' ); }
            return [ 'provider' => 'wpml', 'code' => $code ];
        }
        $locale = function_exists( 'get_locale' ) ? strtolower( str_replace( '_', '-', get_locale() ) ) : '';
        return in_array( $requested, [ $locale, explode( '-', $locale )[0] ], true ) ? [ 'provider' => 'core', 'code' => $locale ] : self::error( 'create_language_unsupported', 'This site cannot create content in the requested language.' );
    }
    /** Request-local native language context; no persistent settings are changed. */
    public static function with_creation_language( array $target, callable $work ) {
        if ( ! isset( $target['language'] ) ) { return $work(); }
        $context = self::creation_language( $target['language'] ); if ( is_wp_error( $context ) ) { return $context; }
        if ( $context !== ( $target['native_language'] ?? null ) ) { return self::error( 'create_language_changed', 'The verified native default language changed before creation.' ); }
        if ( 'core' === $context['provider'] ) { return $work(); }
        $previous = apply_filters( 'wpml_current_language', null );
        if ( ! is_string( $previous ) || '' === $previous ) { return self::error( 'create_language_context', 'The native language context cannot be safely restored. No item was inserted.' ); }
        try { do_action( 'wpml_switch_language', $context['code'] ); if ( apply_filters( 'wpml_current_language', null ) !== $context['code'] ) { return self::error( 'create_language_context', 'The native default language context could not be activated.' ); } return $work(); }
        finally { do_action( 'wpml_switch_language', $previous ); }
    }
    public static function verify_created_language( string $resource, string $kind, int $id, array $target ) {
        if ( ! isset( $target['language'] ) ) { return true; } $context = self::creation_language( $target['language'] ); if ( is_wp_error( $context ) || $context !== ( $target['native_language'] ?? null ) ) { return self::error( 'create_language_readback', 'The created item language authority changed.' ); }
        if ( 'core' === $context['provider'] ) { return true; }
        $term = 'term' === $kind; $name = substr( $resource, strpos( $resource, ':' ) + 1 ); $object = $term ? get_term( $id, $name ) : null; $element = $term ? (int) ( $object->term_taxonomy_id ?? 0 ) : $id;
        $details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $element, 'element_type' => ( $term ? 'tax_' : 'post_' ) . $name ] ); $language = is_object( $details ) ? ( $details->language_code ?? null ) : ( $details['language_code'] ?? null );
        return $language === $context['code'] ? true : self::error( 'create_language_readback', 'The witnessed native item was not saved in its requested default language. No duplicate will be inserted.' );
    }
    /** Derive native routing from the site's registered permalink structure only. */
    public static function creation( string $resource, string $url, ?string $language = null ) {
        $wanted = self::owned_url( $url ); if ( is_wp_error( $wanted ) ) { return $wanted; }
        if ( isset( wp_parse_url( $wanted['identity'] )['query'] ) ) { return self::error( 'create_route', 'Creation requires a native pretty permalink without query parameters.' ); }
        $native_language = null; if ( null !== $language ) { $native_language = self::creation_language( $language ); if ( is_wp_error( $native_language ) ) { return $native_language; } }
        global $wp_rewrite;
        $term = 0 === strpos( $resource, 'taxonomy:' ); $name = substr( $resource, strpos( $resource, ':' ) + 1 ); $object = $term ? get_taxonomy( $name ) : get_post_type_object( $name );
        if ( ! is_object( $object ) || ! is_object( $wp_rewrite ) ) { return self::error( 'create_route', 'The native endpoint permalink structure is unavailable.' ); }
        $token = $term ? '%' . $name . '%' : ( 'page' === $name ? '%pagename%' : '%postname%' );
        if ( $term || ! in_array( $name, [ 'post', 'page' ], true ) ) { $structure = $wp_rewrite->get_extra_permastruct( $name ); if ( ! $term ) { $token = '%' . $name . '%'; } }
        else { $structure = 'page' === $name ? $wp_rewrite->get_page_permastruct() : get_option( 'permalink_structure', '' ); }
        if ( ! is_string( $structure ) || substr_count( $structure, $token ) !== 1 || preg_match( '/%[a-zA-Z0-9_]+%/', str_replace( $token, '', $structure ) ) ) { return self::error( 'create_route', 'This native permalink structure needs an explicit certified creation adapter.' ); }
        $route_home = get_option( 'home', home_url( '/' ) );
        if ( ( $native_language['provider'] ?? '' ) === 'wpml' ) { $route_home = apply_filters( 'wpml_permalink', rtrim( $route_home, '/' ) . '/', $native_language['code'], true ); }
        if ( ! is_string( $route_home ) ) { return self::error( 'create_route', 'The native default-language home URL is unavailable.' ); }
        $marker = 'nova-mapped-route-slot'; $route = self::owned_url( rtrim( $route_home, '/' ) . '/' . ltrim( str_replace( $token, $marker, $structure ), '/' ) ); if ( is_wp_error( $route ) ) { return $route; }
        $sides = explode( $marker, $route['path'] ); if ( count( $sides ) !== 2 || 0 !== strpos( $wanted['path'], $sides[0] ) || ( '' !== $sides[1] && substr( $wanted['path'], -strlen( $sides[1] ) ) !== $sides[1] ) ) { return self::error( 'create_route', 'The planned URL does not match this endpoint’s native permalink route.' ); }
        $tail = substr( $wanted['path'], strlen( $sides[0] ) ); if ( '' !== $sides[1] ) { $tail = substr( $tail, 0, -strlen( $sides[1] ) ); } $segments = explode( '/', $tail );
        $hierarchical = $term ? ! empty( $object->rewrite['hierarchical'] ) : ! empty( $object->hierarchical );
        if ( ! $tail || ( ! $hierarchical && count( $segments ) !== 1 ) ) { return self::error( 'create_route', 'The planned URL hierarchy is unsupported by this endpoint.' ); }
        foreach ( $segments as $segment ) { $slug = rawurldecode( $segment ); if ( '' === $slug || strlen( $slug ) > 180 || sanitize_title( $slug ) !== $slug ) { return self::error( 'create_slug', 'The planned URL must use canonical native slugs.' ); } }
        $slug = rawurldecode( array_pop( $segments ) ); $parent = 0;
        if ( $segments ) { $parentUrl = $wanted['origin'] . $sides[0] . implode( '/', $segments ) . $sides[1]; $resolved = self::resolve( $parentUrl, $resource ); if ( is_wp_error( $resolved ) ) { return $resolved; } $parent = $resolved['reference_id']; }
        $occupied = self::resolve( $url ); if ( ! is_wp_error( $occupied ) ) { return self::error( 'target_exists', 'The planned URL already belongs to an existing native item.' ); } if ( 'nova_mapped_target_not_found' !== $occupied->get_error_code() ) { return $occupied; }
        return [ 'slug' => $slug, 'parent' => $parent, 'target_url' => $wanted['identity'] ] + ( null !== $language ? [ 'language' => $language, 'native_language' => $native_language ] : [] );
    }
    /** Enumerate actual native links, preserving hierarchy, taxonomy and language. */
    public static function resolve( string $url, ?string $resource_hint = null, ?string $language = null ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::error( 'permission', 'The configured mapping actor cannot inspect site URLs.', 403 ); }
        $wanted = self::owned_url( $url ); if ( is_wp_error( $wanted ) ) { return $wanted; }
        $matches = []; $inspected = 0; $resources = Nova_Bridge_Suite_Document_Mapping::resources();
        foreach ( $resources as $resource ) {
            if ( empty( $resource['can_inspect_existing'] ) || ! empty( $resource['coming_soon'] ) || ( $resource['availability'] ?? '' ) !== 'available' ) { continue; }
            $term = 'taxonomy' === $resource['type']; $name = $resource[$term ? 'taxonomy' : 'post_type'];
            for ( $offset = 0; ; $offset += self::BATCH ) {
                $ids = $term ? get_terms( [ 'taxonomy' => $name, 'hide_empty' => false, 'fields' => 'ids', 'number' => self::BATCH, 'offset' => $offset, 'orderby' => 'term_id', 'order' => 'ASC', 'suppress_filter' => true, 'lang' => '' ] ) : get_posts( [ 'post_type' => $name, 'post_status' => [ 'publish', 'draft', 'private', 'pending', 'future' ], 'fields' => 'ids', 'numberposts' => self::BATCH, 'posts_per_page' => self::BATCH, 'offset' => $offset, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'no_found_rows' => true ] );
                if ( is_wp_error( $ids ) || ! is_array( $ids ) ) { return self::error( 'target_scan', 'Native URL candidates could not be inspected.', 503 ); }
                foreach ( $ids as $id ) {
                    if ( ++$inspected > self::MAX_OBJECTS ) { return self::error( 'target_scan_limit', 'Exact URL ownership could not be proven within the bounded native inventory.', 503 ); }
                    $id = is_object( $id ) ? (int) ( $term ? $id->term_id : $id->ID ) : (int) $id;
                    $native = self::native_url( $resource['id'], $term ? 'term' : 'post', $id ); if ( is_wp_error( $native ) ) { continue; } $identity = self::owned_url( $native );
                    if ( is_wp_error( $identity ) || $identity['identity'] !== $wanted['identity'] ) { continue; }
                    $key = ( $term ? 'term:' : 'post:' ) . $id; $matches[$key] = [ 'resource_id' => $resource['id'], 'reference_type' => $term ? 'term' : 'post', 'reference_id' => $id, 'url' => $native ];
                }
                if ( count( $ids ) < self::BATCH ) { break; }
            }
        }
        if ( ! $matches ) { return self::error( 'target_not_found', 'No editable native item has this exact site URL.', 404 ); }
        if ( count( $matches ) !== 1 ) { return self::error( 'target_ambiguous', 'Several native items claim this URL. Resolve their canonical links before rewriting.', 409 ); }
        $target = reset( $matches ); $target['requested_url'] = $wanted['identity'];
        if ( null !== $resource_hint && $resource_hint !== $target['resource_id'] ) { return self::error( 'target_resource_mismatch', 'The URL belongs to a different native destination.', 409 ); }
        if ( ! current_user_can( 'term' === $target['reference_type'] ? 'edit_term' : 'edit_post', $target['reference_id'] ) ) { return self::error( 'target_permission', 'The configured mapping actor cannot edit the resolved native item.', 403 ); }
        if ( null !== $language && '' !== $language ) { return self::language_target( $target, $language ); }
        return $target;
    }
    private static function language_target( array $target, string $language ) {
        $requested = strtolower( str_replace( '_', '-', $language ) );
        if ( ! preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $requested ) ) { return self::error( 'target_language', 'Supply a recognized BCP47 target language.' ); }
        $term = 'term' === $target['reference_type']; $name = substr( $target['resource_id'], strpos( $target['resource_id'], ':' ) + 1 ); $id = $target['reference_id'];
        if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_get_term_language' ) ) {
            $source = $term ? pll_get_term_language( $id, 'slug' ) : pll_get_post_language( $id, 'slug' ); $codes = (array) pll_languages_list( [ 'fields' => 'slug' ] ); $locales = (array) pll_languages_list( [ 'fields' => 'locale' ] ); $code = null;
            foreach ( $codes as $index => $candidate ) { if ( in_array( $requested, [ strtolower( (string) $candidate ), strtolower( str_replace( '_', '-', (string) ( $locales[$index] ?? '' ) ) ) ], true ) ) { $code = (string) $candidate; } }
            if ( ! is_string( $source ) || '' === $source || null === $code ) { return self::error( 'target_language_unavailable', 'The requested language cannot be verified by the installed multilingual provider.', 409 ); }
            $translated = $source === $code ? $id : (int) ( $term ? pll_get_term( $id, $code ) : pll_get_post( $id, $code ) );
            if ( $translated < 1 || ( $term ? pll_get_term_language( $translated, 'slug' ) : pll_get_post_language( $translated, 'slug' ) ) !== $code || (int) ( $term ? pll_get_term( $translated, $source ) : pll_get_post( $translated, $source ) ) !== $id ) { return self::error( 'target_translation_missing', 'No verified native translation exists for this URL and language.', 409 ); }
        } elseif ( function_exists( 'has_filter' ) && ( has_filter( 'wpml_element_language_details' ) || defined( 'ICL_SITEPRESS_VERSION' ) ) ) {
            $element = $term ? get_term( $id, $name ) : null; $elementId = $term ? (int) ( $element->term_taxonomy_id ?? 0 ) : $id; $type = ( $term ? 'tax_' : 'post_' ) . $name;
            $details = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $elementId, 'element_type' => $type ] ); $source = is_object( $details ) ? ( $details->language_code ?? '' ) : ( $details['language_code'] ?? '' ); $code = null;
            foreach ( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) as $candidate => $data ) { if ( in_array( $requested, [ strtolower( (string) $candidate ), strtolower( str_replace( '_', '-', (string) ( $data['default_locale'] ?? '' ) ) ) ], true ) ) { $code = (string) $candidate; } }
            if ( ! is_string( $source ) || '' === $source || null === $code ) { return self::error( 'target_language_unavailable', 'The requested language cannot be verified by the installed multilingual provider.', 409 ); }
            $translated = $source === $code ? $id : (int) apply_filters( 'wpml_object_id', $id, $name, false, $code );
            $translatedObject = $term && $translated ? get_term( $translated, $name ) : null; $translatedElement = $term ? (int) ( $translatedObject->term_taxonomy_id ?? 0 ) : $translated;
            $translatedDetails = apply_filters( 'wpml_element_language_details', null, [ 'element_id' => $translatedElement, 'element_type' => $type ] ); $actualCode = is_object( $translatedDetails ) ? ( $translatedDetails->language_code ?? '' ) : ( $translatedDetails['language_code'] ?? '' );
            if ( $translated < 1 || $actualCode !== $code || (int) apply_filters( 'wpml_object_id', $translated, $name, false, $source ) !== $id ) { return self::error( 'target_translation_missing', 'No verified native translation exists for this URL and language.', 409 ); }
        } else {
            $locale = function_exists( 'get_locale' ) ? strtolower( str_replace( '_', '-', get_locale() ) ) : '';
            if ( $requested !== $locale && $requested !== explode( '-', $locale )[0] ) { return self::error( 'target_language_unsupported', 'This site cannot verify a separate native item for the requested language.', 409 ); }
            return $target + [ 'language' => $language ];
        }
        if ( ! current_user_can( $term ? 'edit_term' : 'edit_post', $translated ) ) { return self::error( 'target_permission', 'The configured actor cannot edit the translated item.', 403 ); }
        $url = self::native_url( $target['resource_id'], $target['reference_type'], $translated ); if ( is_wp_error( $url ) ) { return $url; } $owned = self::owned_url( $url ); if ( is_wp_error( $owned ) ) { return $owned; }
        $target['reference_id'] = $translated; $target['url'] = $url; $target['language'] = $language; return $target;
    }
}
