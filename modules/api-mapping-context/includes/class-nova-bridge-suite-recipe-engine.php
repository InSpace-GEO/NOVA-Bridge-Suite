<?php
/** Pure, bounded compilation of sealed native layout recipes. No CMS writes occur here. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Failure extends RuntimeException {
    public $error;
    public function __construct( WP_Error $error ) { $this->error = $error; parent::__construct( $error->get_error_message() ); }
}

final class Nova_Bridge_Suite_Recipe_Engine {
    public const FORMAT = 'nova-layout-recipe/v1';
    public const VERSION = 'nova-native-recipe-engine-v1';
    private const MAX_BYTES = 8388608;
    private const MAX_DEPTH = 8;
    private static $adapters = [];

    public static function error( string $code, string $message, int $status = 422 ): WP_Error { return new WP_Error( 'nova_recipe_' . $code, $message, [ 'status' => $status ] ); }
    public static function fail( string $message ): void { throw new InvalidArgumentException( $message ); }
    private static function checked( $result ) { if ( is_wp_error( $result ) ) { throw new Nova_Bridge_Suite_Recipe_Failure( $result ); } return $result; }
    private static function guard( callable $work ) {
        try { return $work(); }
        catch ( Nova_Bridge_Suite_Recipe_Failure $error ) { return $error->error; }
        catch ( InvalidArgumentException $error ) { return self::error( 'invalid', $error->getMessage() ); }
        catch ( Throwable $error ) { return self::error( 'unavailable', 'The native recipe could not be compiled. No content or settings were changed.', 503 ); }
    }
    public static function register_adapter( string $builder, object $adapter ): void {
        if ( ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $builder ) ) { self::fail( 'Use a canonical native builder identifier.' ); }
        foreach ( [ 'validate_blueprint', 'validate_binding', 'validate_region', 'instantiate', 'bind', 'replace_region', 'finalize' ] as $method ) { if ( ! method_exists( $adapter, $method ) ) { self::fail( 'A native recipe adapter is missing ' . $method . '.' ); } }
        self::$adapters[ $builder ] = $adapter;
    }
    public static function adapter( string $builder ) {
        if ( 'elementor' === $builder && ! isset( self::$adapters[ $builder ] ) ) {
            require_once __DIR__ . '/class-nova-bridge-suite-recipe-elementor.php';
            self::register_adapter( 'elementor', new Nova_Bridge_Suite_Recipe_Elementor() );
        }
        return self::$adapters[ $builder ] ?? self::error( 'builder', 'No complete native recipe adapter is registered for this builder.' );
    }
    public static function list_array( $value ): bool { return is_array( $value ) && ( [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    public static function json( $value ): string {
        $entries = 0;
        $sort = static function ( $item, int $depth = 0 ) use ( &$sort, &$entries ) {
            if ( $depth > 64 || ++$entries > 100000 || is_object( $item ) || is_resource( $item ) || ( is_float( $item ) && ! is_finite( $item ) ) ) { self::fail( 'A recipe or content value exceeds its bounded native JSON shape.' ); }
            if ( is_array( $item ) ) { if ( ! self::list_array( $item ) ) { ksort( $item, SORT_STRING ); } foreach ( $item as $key => $child ) { $item[ $key ] = $sort( $child, $depth + 1 ); } }
            return $item;
        };
        $json = wp_json_encode( $sort( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( ! is_string( $json ) || strlen( $json ) > self::MAX_BYTES ) { self::fail( 'The full native recipe/content exceeds eight megabytes or is not valid UTF-8.' ); }
        return $json;
    }
    public static function digest( $value ): string { return hash( 'sha256', self::json( $value ) ); }
    public static function parts( string $pointer ): array {
        if ( '' === $pointer ) { return []; }
        if ( '/' !== $pointer[0] || strlen( $pointer ) > 1200 || preg_match( '/~(?![01])|[\x00-\x1f]/', $pointer ) ) { self::fail( 'Use a bounded RFC6901 native/content pointer.' ); }
        $parts = array_map( static function ( $part ) { return str_replace( [ '~1', '~0' ], [ '/', '~' ], $part ); }, explode( '/', substr( $pointer, 1 ) ) );
        if ( count( $parts ) > 48 || array_intersect( $parts, [ '__proto__', 'prototype', 'constructor' ] ) ) { self::fail( 'The native/content pointer contains an unsupported path component.' ); }
        return $parts;
    }
    public static function get( array $native, string $pointer, &$found = null ) {
        $value = $native; $found = true;
        foreach ( self::parts( $pointer ) as $part ) { if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) { $found = false; return null; } $value = $value[ $part ]; }
        return $value;
    }
    public static function put( array &$native, string $pointer, $value ): void {
        $parts = self::parts( $pointer ); if ( ! $parts ) { if ( ! is_array( $value ) ) { self::fail( 'The full native blueprint must remain an array/object.' ); } $native = $value; return; }
        $cursor = &$native;
        foreach ( $parts as $index => $part ) { if ( ! is_array( $cursor ) || ! array_key_exists( $part, $cursor ) ) { self::fail( 'A declared native binding no longer exists in the frozen blueprint.' ); } if ( $index === count( $parts ) - 1 ) { $cursor[ $part ] = $value; } else { $cursor = &$cursor[ $part ]; } }
    }
    public static function splice( array &$native, string $pointer, int $start, int $count, array $children ): void {
        $list = self::get( $native, $pointer, $found );
        if ( ! $found || ! self::list_array( $list ) || ! self::list_array( $children ) || $start < 0 || $count < 0 || $start + $count > count( $list ) ) { self::fail( 'A native region must replace an existing contiguous child-array range.' ); }
        array_splice( $list, $start, $count, $children ); self::put( $native, $pointer, $list );
    }
    private static function plain( $value, int $max = 8000 ): bool { return is_string( $value ) && strlen( $value ) <= $max && false === strpos( $value, "\0" ) && 1 === preg_match( '//u', $value ); }
    private static function metadata( array $value ): void { foreach ( [ 'label', 'instructions' ] as $key ) { if ( isset( $value[ $key ] ) && ! self::plain( $value[ $key ], 'label' === $key ? 200 : 8000 ) ) { self::fail( 'Recipe labels/instructions must be bounded UTF-8 text.' ); } } }

    public static function validate( array $recipe ) {
        return self::guard( static function () use ( $recipe ) {
            self::json( $recipe );
            if ( array_diff( array_keys( $recipe ), [ 'format', 'mode', 'builder', 'blueprint', 'bindings', 'regions', 'label', 'instructions', 'custom_fields' ] ) || ( $recipe['format'] ?? '' ) !== self::FORMAT || ! in_array( $recipe['mode'] ?? '', [ 'static', 'mixed', 'rules' ], true ) || ! is_string( $recipe['builder'] ?? null ) || ! is_array( $recipe['blueprint'] ?? null ) ) { self::fail( 'Supply a complete versioned static, mixed or rules native recipe.' ); }
            self::metadata( $recipe ); $adapter = self::checked( self::adapter( $recipe['builder'] ) );
            $checked = self::checked( $adapter->validate_blueprint( $recipe['blueprint'] ) );
            if ( ! is_array( $checked ) || self::digest( $checked ) !== self::digest( $recipe['blueprint'] ) ) { self::fail( 'Native validation must not flatten or silently replace the sealed blueprint.' ); }
            $count = 0; self::validate_scope( $recipe['blueprint'], $recipe['bindings'] ?? [], $recipe['regions'] ?? [], $adapter, $recipe['mode'], $count, 0 );
            self::scope_schema( $recipe['bindings'] ?? [], $recipe['regions'] ?? [] );
            if ( isset( $recipe['custom_fields'] ) && ! is_array( $recipe['custom_fields'] ) ) { self::fail( 'The optional custom-fields companion must remain its complete separately sealed recipe.' ); }
            return $recipe;
        } );
    }
    private static function validate_scope( array $native, $bindings, $regions, $adapter, string $mode, int &$count, int $depth ): void {
        if ( $depth > self::MAX_DEPTH || ! self::list_array( $bindings ) || ! self::list_array( $regions ) || ( 'static' === $mode && $regions ) ) { self::fail( 'Use ordered bindings/regions nested at most eight levels; static mode has no variable regions.' ); }
        $targets = []; $identities = [];
        foreach ( $bindings as $binding ) {
            if ( ++$count > 2000 || ! is_array( $binding ) || array_diff( array_keys( $binding ), [ 'id', 'label', 'target', 'source', 'format', 'required', 'instructions' ] ) || ! is_string( $binding['target'] ?? null ) || '' === $binding['target'] || ! is_string( $binding['source'] ?? null ) || ! in_array( $binding['format'] ?? '', [ 'text', 'html', 'url', 'number', 'boolean' ], true ) || ! is_bool( $binding['required'] ?? null ) ) { self::fail( 'Each native binding declares a target, relative content source, format and required preference.' ); }
            self::parts( $binding['target'] ); self::parts( $binding['source'] ); self::metadata( $binding );
            if ( isset( $targets[ $binding['target'] ] ) ) { self::fail( 'A native field cannot have two bindings in the same scope.' ); } $targets[ $binding['target'] ] = true;
            self::checked( $adapter->validate_binding( $native, $binding ) );
        }
        foreach ( $regions as $region ) {
            if ( ++$count > 2000 || ! is_array( $region ) || array_diff( array_keys( $region ), [ 'id', 'label', 'instructions', 'target', 'start', 'count', 'prototype_start', 'prototype_count', 'source', 'kind', 'context', 'prototype', 'empty', 'max_items', 'projection', 'variant_by', 'variants', 'default_variant' ] ) || ! is_string( $region['id'] ?? null ) || ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $region['id'] ) || isset( $identities[ $region['id'] ] ) || ! is_string( $region['target'] ?? null ) || ! is_string( $region['source'] ?? null ) || ! is_int( $region['start'] ?? null ) || ! is_int( $region['count'] ?? null ) || $region['start'] < 0 || $region['count'] < 0 || ! in_array( $region['kind'] ?? '', [ 'repeat', 'optional' ], true ) || ! in_array( $region['context'] ?? 'item', [ 'item', 'parent' ], true ) || ( 'repeat' === ( $region['kind'] ?? '' ) && 'parent' === ( $region['context'] ?? '' ) ) || ! in_array( $region['empty'] ?? '', [ 'remove', 'keep' ], true ) || ! is_int( $region['max_items'] ?? null ) || $region['max_items'] < 1 || $region['max_items'] > 500 ) { self::fail( 'Each native region declares a unique ID, contiguous range, source, bounded kind and empty preference.' ); }
            $identities[ $region['id'] ] = true; self::parts( $region['target'] ); self::parts( $region['source'] ); self::metadata( $region );
            $list = self::get( $native, $region['target'], $found ); if ( ! $found || ! self::list_array( $list ) || $region['start'] + $region['count'] > count( $list ) ) { self::fail( 'A native region is outside its sealed child collection.' ); }
            if ( isset( $region['prototype_start'] ) || isset( $region['prototype_count'] ) ) { $sample_start = $region['prototype_start'] ?? $region['start']; $sample_count = $region['prototype_count'] ?? $region['count']; if ( ! is_int( $sample_start ) || ! is_int( $sample_count ) || $sample_count < 1 || $sample_start < $region['start'] || $sample_start + $sample_count > $region['start'] + $region['count'] || count( $region['prototype']['native'] ?? [] ) !== $sample_count ) { self::fail( 'A sampled native prototype must be a nonempty slice inside the separate replacement range.' ); } }
            if ( isset( $region['projection'] ) ) { $projection = $region['projection']; if ( ! is_array( $projection ) || array_diff( array_keys( $projection ), [ 'group_by', 'title_source' ] ) || ! is_string( $projection['group_by'] ?? null ) ) { self::fail( 'Device grouping must explicitly declare a relative group_by pointer.' ); } self::parts( $projection['group_by'] ); if ( isset( $projection['title_source'] ) ) { if ( ! is_string( $projection['title_source'] ) ) { self::fail( 'A projected title source must be a pointer.' ); } self::parts( $projection['title_source'] ); } }
            self::checked( $adapter->validate_region( $native, $region ) );
            self::validate_prototype( $region['prototype'] ?? null, $adapter, $mode, $count, $depth );
            if ( isset( $region['variant_by'] ) || isset( $region['variants'] ) || isset( $region['default_variant'] ) ) {
                if ( ! is_string( $region['variant_by'] ?? null ) || ! is_array( $region['variants'] ?? null ) || ! $region['variants'] || count( $region['variants'] ) > 64 ) { self::fail( 'Variant regions require a discriminator pointer and a finite declared prototype map.' ); } self::parts( $region['variant_by'] );
                foreach ( $region['variants'] as $name => $prototype ) { if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/D', $name ) ) { self::fail( 'Native variants use declared layout names.' ); } self::validate_prototype( $prototype, $adapter, $mode, $count, $depth ); }
                if ( isset( $region['default_variant'] ) && ( ! is_string( $region['default_variant'] ) || ! isset( $region['variants'][ $region['default_variant'] ] ) ) ) { self::fail( 'The explicit default variant must name a declared layout.' ); }
            }
        }
        foreach ( $regions as $i => $region ) {
            foreach ( array_keys( $targets ) as $target ) { if ( self::inside_range( $target, $region ) ) { self::fail( 'A fixed binding cannot target content replaced by a region; move it into that prototype.' ); } }
            foreach ( $regions as $j => $other ) { if ( $j <= $i ) { continue; } if ( $region['target'] === $other['target'] ) { $a = $region['start']; $b = $other['start']; if ( ( $a === $b ) || ( $a < $b + $other['count'] && $b < $a + $region['count'] ) ) { self::fail( 'Native region replacement ranges must not overlap.' ); } } elseif ( self::inside_range( $other['target'], $region ) || self::inside_range( $region['target'], $other ) ) { self::fail( 'Nested regions belong inside their owning prototype, not overlapping root ranges.' ); } }
        }
        if ( method_exists( $adapter, 'validate_plan_scope' ) ) { self::checked( $adapter->validate_plan_scope( $native, $bindings, $regions ) ); }
    }
    private static function inside_range( string $pointer, array $region ): bool { $prefix = $region['target'] . '/'; if ( 0 !== strpos( $pointer, $prefix ) ) { return false; } $tail = substr( $pointer, strlen( $prefix ) ); $index = explode( '/', $tail )[0]; return ctype_digit( $index ) && (int) $index >= $region['start'] && (int) $index < $region['start'] + $region['count']; }
    private static function validate_prototype( $prototype, $adapter, string $mode, int &$count, int $depth ): void {
        if ( ! is_array( $prototype ) || array_diff( array_keys( $prototype ), [ 'native', 'bindings', 'regions' ] ) || ! self::list_array( $prototype['native'] ?? null ) || ! $prototype['native'] ) { self::fail( 'A region prototype contains a nonempty ordered list of complete native children.' ); }
        self::validate_scope( $prototype['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $adapter, $mode, $count, $depth + 1 );
    }

    /** Canonical fields are aliases, not a lossy universal native-node representation. */
    public static function normalize( array $content ) {
        return self::guard( static function () use ( $content ) {
            self::json( $content ); if ( self::list_array( $content ) && $content ) { self::fail( 'Supply a canonical content object, not a bare list.' ); }
            if ( isset( $content['html'] ) ) { if ( ! is_string( $content['html'] ) ) { self::fail( 'Article HTML must be text.' ); } $parsed = self::html_article( $content['html'] ); foreach ( $parsed as $key => $value ) { if ( ! array_key_exists( $key, $content ) ) { $content[ $key ] = $value; } } }
            if ( isset( $content['title'] ) && is_string( $content['title'] ) && ! isset( $content['title_html'] ) ) { $content['title_html'] = htmlspecialchars( $content['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
            if ( isset( $content['intro'] ) && is_string( $content['intro'] ) && ! isset( $content['intro_html'] ) ) { $content['intro_html'] = $content['intro']; }
            if ( ! isset( $content['sections'] ) && isset( $content['body'] ) && is_string( $content['body'] ) && '' !== $content['body'] ) { $content['sections'] = [ [ 'title' => '', 'body' => $content['body'] ] ]; }
            if ( isset( $content['sections'] ) ) { $content['sections'] = self::sections( $content['sections'], 0 ); }
            if ( isset( $content['faqs'] ) ) { $content['faqs'] = self::faqs( $content['faqs'] ); }
            if ( ! isset( $content['body'] ) && isset( $content['sections'] ) ) { $content['body'] = self::sections_html( $content['sections'] ); }
            if ( isset( $content['body'] ) && is_string( $content['body'] ) && ! isset( $content['body_html'] ) ) { $content['body_html'] = $content['body']; }
            self::json( $content ); return $content;
        } );
    }
    private static function sections( $sections, int $depth ): array {
        if ( $depth > self::MAX_DEPTH || ! self::list_array( $sections ) || count( $sections ) > 500 ) { self::fail( 'Canonical sections are ordered collections nested at most eight levels.' ); }
        foreach ( $sections as $index => &$section ) {
            if ( ! is_array( $section ) ) { self::fail( 'Each canonical section is an object.' ); }
            if ( ! isset( $section['key'] ) ) { $section['key'] = 'section-' . $index; }
            if ( isset( $section['title'] ) && is_string( $section['title'] ) && ! isset( $section['title_html'] ) ) { $section['title_html'] = htmlspecialchars( $section['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
            if ( isset( $section['body'] ) && is_string( $section['body'] ) && ! isset( $section['body_html'] ) ) { $section['body_html'] = $section['body']; }
            if ( isset( $section['body_html'] ) && ! isset( $section['body'] ) ) { $section['body'] = $section['body_html']; }
            if ( isset( $section['children'] ) ) { $section['children'] = self::sections( $section['children'], $depth + 1 ); }
            if ( isset( $section['faqs'] ) ) { $section['faqs'] = self::faqs( $section['faqs'] ); }
            if ( ! isset( $section['body_with_children_html'] ) && isset( $section['body_html'] ) ) { $section['body_with_children_html'] = $section['body_html'] . ( isset( $section['children'] ) ? self::sections_html( $section['children'] ) : '' ); }
        } unset( $section ); return $sections;
    }
    private static function faqs( $faqs ): array { if ( ! self::list_array( $faqs ) || count( $faqs ) > 500 ) { self::fail( 'FAQs require an explicit ordered collection.' ); } foreach ( $faqs as &$faq ) { if ( ! is_array( $faq ) ) { self::fail( 'Each FAQ is an object.' ); } if ( ! isset( $faq['question'] ) && isset( $faq['q'] ) ) { $faq['question'] = $faq['q']; } if ( ! isset( $faq['answer'] ) && isset( $faq['a'] ) ) { $faq['answer'] = $faq['a']; } if ( ! isset( $faq['answer_html'] ) && isset( $faq['answer'] ) ) { $faq['answer_html'] = $faq['answer']; } } unset( $faq ); return $faqs; }
    private static function sections_html( array $sections ): string { $html = ''; foreach ( $sections as $section ) { if ( ! empty( $section['title_html'] ) ) { $level = max( 2, min( 6, (int) ( $section['level'] ?? 2 ) ) ); $html .= '<h' . $level . '>' . $section['title_html'] . '</h' . $level . '>'; } $html .= $section['body_html'] ?? ''; if ( isset( $section['children'] ) ) { $html .= self::sections_html( $section['children'] ); } } return $html; }
    private static function html_article( string $html ): array {
        if ( ! class_exists( 'DOMDocument' ) || ! function_exists( 'wp_kses_post' ) ) { self::fail( 'Native HTML normalization requires WordPress sanitization and the DOM parser.' ); }
        $safe = wp_kses_post( $html ); $document = new DOMDocument(); $old = libxml_use_internal_errors( true );
        try { $loaded = $document->loadHTML( '<!doctype html><html><head><meta charset="UTF-8"></head><body>' . $safe . '</body></html>', LIBXML_NONET ); } finally { libxml_clear_errors(); libxml_use_internal_errors( $old ); }
        if ( ! $loaded ) { self::fail( 'Article HTML could not be parsed.' ); }
        $result = [ 'title' => '', 'title_html' => '', 'intro' => '', 'intro_html' => '', 'sections' => [], 'body' => '' ]; $stack = [];
        foreach ( $document->getElementsByTagName( 'body' )->item( 0 )->childNodes as $node ) {
            $tag = $node instanceof DOMElement ? strtolower( $node->tagName ) : ''; $outer = $document->saveHTML( $node );
            $inside = ''; if ( preg_match( '/^h[1-6]$/D', $tag ) ) { foreach ( $node->childNodes as $child ) { $inside .= $document->saveHTML( $child ); } if ( $node->hasAttribute( 'id' ) && '' !== $node->getAttribute( 'id' ) ) { $inside = '<span id="' . htmlspecialchars( $node->getAttribute( 'id' ), ENT_QUOTES, 'UTF-8' ) . '">' . $inside . '</span>'; } }
            if ( 'h1' === $tag && ! $result['title_html'] && ! $stack && ! trim( $result['intro'] ) ) { $result['title'] = trim( $node->textContent ); $result['title_html'] = $inside; continue; }
            if ( preg_match( '/^h[1-6]$/D', $tag ) ) {
                $level = (int) substr( $tag, 1 ); while ( $stack && $stack[ count( $stack ) - 1 ]['level'] >= $level ) { array_pop( $stack ); }
                $pointer = $stack ? $stack[ count( $stack ) - 1 ]['pointer'] . '/children' : '/sections'; $list = self::get( $result, $pointer, $exists );
                if ( ! $exists ) { $parent = &$result; foreach ( self::parts( $stack[ count( $stack ) - 1 ]['pointer'] ) as $part ) { $parent = &$parent[ $part ]; } $parent['children'] = []; unset( $parent ); $list = []; }
                $index = count( $list ); $list[] = [ 'key' => 'section-' . str_replace( '/', '-', $pointer ) . '-' . $index, 'title' => trim( $node->textContent ), 'title_html' => $inside, 'level' => $level, 'body' => '', 'body_html' => '' ]; self::put( $result, $pointer, $list ); $stack[] = [ 'level' => $level, 'pointer' => $pointer . '/' . $index ];
            } elseif ( ! $stack ) { $result['intro'] .= $outer; $result['intro_html'] .= $outer; }
            else { $pointer = $stack[ count( $stack ) - 1 ]['pointer']; $section = self::get( $result, $pointer, $exists ); $section['body'] .= $outer; $section['body_html'] .= $outer; self::put( $result, $pointer, $section ); }
        }
        $result['body'] = self::sections_html( $result['sections'] );
        if ( $safe !== $html ) { $result['_normalization_warnings'] = [ 'WordPress removed HTML outside the allowed post-content policy.' ]; }
        return $result;
    }

    public static function compile( array $recipe, array $content, string $seed ) {
        return self::guard( static function () use ( $recipe, $content, $seed ) {
            if ( ! self::plain( $seed, 256 ) || '' === $seed ) { self::fail( 'Compilation needs a bounded stable operation identity.' ); }
            $recipe = self::checked( self::validate( $recipe ) ); $normalized = self::checked( self::normalize( $content ) ); $adapter = self::checked( self::adapter( $recipe['builder'] ) );
            $state = [ 'warnings' => $normalized['_normalization_warnings'] ?? [], 'regions' => [], 'bindings' => 0, 'instances' => 0, 'generated_bytes' => 0 ];
            $native = self::compile_scope( $recipe['blueprint'], $recipe['bindings'] ?? [], $recipe['regions'] ?? [], $normalized, $adapter, $seed, [], $state, 0 );
            $result = self::checked( $adapter->finalize( $native, $seed, [ 'recipe' => $recipe, 'content' => $normalized, 'regions' => $state['regions'] ] ) );
            if ( ! is_array( $result ) || ! isset( $result['native_document'], $result['writer_payload'], $result['preview_html'] ) || ! is_array( $result['writer_payload'] ) || ! is_string( $result['preview_html'] ) ) { self::fail( 'The adapter did not return a complete native document, writer payload and structure preview.' ); }
            $result['warnings'] = array_values( array_unique( array_merge( $state['warnings'], (array) ( $result['warnings'] ?? [] ) ) ) );
            $result['summary'] = array_merge( (array) ( $result['summary'] ?? [] ), [ 'builder' => $recipe['builder'], 'mode' => $recipe['mode'], 'bindings' => $state['bindings'], 'instances' => $state['instances'], 'regions' => $state['regions'] ] );
            if ( ( $result['summary']['nodes'] ?? 0 ) > 2000 ) { self::fail( 'The compiled native document exceeds 2000 nodes.' ); }
            $result['content_schema'] = self::augmented_schema( $recipe, $adapter );
            $result['normalized_content'] = $normalized; $result['engine_version'] = self::VERSION; $result['recipe_digest'] = self::digest( $recipe ); $result['content_digest'] = self::digest( $normalized );
            if ( isset( $recipe['custom_fields'] ) ) { $result['custom_fields'] = $recipe['custom_fields']; }
            $result['plan_digest'] = self::digest( $result ); self::json( $result ); return $result;
        } );
    }
    private static function compile_scope( array $native, array $bindings, array $regions, array $content, $adapter, string $seed, array $context, array &$state, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { self::fail( 'Native region recursion exceeded eight levels.' ); }
        foreach ( $bindings as $binding ) {
            $value = self::get( $content, $binding['source'], $found );
            if ( ! $found ) { if ( $binding['required'] ) { self::fail( 'Required content is missing at ' . $binding['source'] . '.' ); } if ( method_exists( $adapter, 'preserve_missing_binding' ) && $adapter->preserve_missing_binding( $native, $binding, $context ) ) { continue; } $value = 'boolean' === $binding['format'] ? false : ( 'number' === $binding['format'] ? 0 : '' ); }
            $value = self::format_value( $value, $binding['format'], $state['warnings'] ); $native = self::checked( $adapter->bind( $native, $binding, $value, $context ) ); ++$state['bindings'];
        }
        usort( $regions, static function ( $a, $b ) { $depth = count( self::parts( $b['target'] ) ) <=> count( self::parts( $a['target'] ) ); return $depth ?: ( $a['target'] === $b['target'] ? $b['start'] <=> $a['start'] : strcmp( $a['target'], $b['target'] ) ); } );
        foreach ( $regions as $region ) {
            $value = self::get( $content, $region['source'], $found ); $items = [];
            if ( $found && null !== $value && false !== $value && '' !== $value && [] !== $value ) {
                if ( 'repeat' === $region['kind'] ) { if ( ! self::list_array( $value ) ) { self::fail( 'Repeat content must be an ordered list at ' . $region['source'] . '.' ); } $items = $value; }
                elseif ( 'parent' === ( $region['context'] ?? 'item' ) ) { $items = is_array( $value ) && isset( $value['enabled'] ) && false === $value['enabled'] ? [] : [ $content ]; }
                elseif ( is_array( $value ) && self::list_array( $value ) ) { if ( count( $value ) > 1 ) { self::fail( 'Optional regions accept at most one content item.' ); } $items = $value; }
                elseif ( is_array( $value ) && isset( $value['enabled'] ) && false === $value['enabled'] ) { $items = []; }
                else { $items = [ $value ]; }
            }
            if ( count( $items ) > $region['max_items'] ) { self::fail( 'A region exceeds its declared content-item limit.' ); }
            $input_count = 'parent' === ( $region['context'] ?? 'item' ) && self::list_array( $value ) ? count( $value ) : count( $items ); if ( isset( $region['projection'] ) ) { $items = self::project( $items, $region['projection'] ); }
            if ( ! $items && 'keep' === $region['empty'] ) { $state['regions'][] = [ 'id' => $region['id'], 'source_items' => 0, 'output_items' => 0, 'kept' => true ]; continue; }
            $region_context = method_exists( $adapter, 'region_context' ) ? self::checked( $adapter->region_context( $items, $seed . '/' . $region['id'], $region, $context ) ) : [];
            if ( ! is_array( $region_context ) ) { self::fail( 'The native adapter returned an invalid collection context.' ); }
            $children = []; $identities = [];
            foreach ( $items as $index => $item ) {
                if ( ! is_array( $item ) ) { $item = [ 'value' => $item ]; }
                $key = isset( $item['_group_key'] ) ? $item['_group_key'] : ( $item['key'] ?? (string) $index ); if ( ! is_scalar( $key ) ) { self::fail( 'Repeated content uses scalar stable identities.' ); } $key = (string) $key;
                if ( isset( $identities[ $key ] ) ) { self::fail( 'A repeated collection contains duplicate stable item keys.' ); } $identities[ $key ] = true;
                if ( ++$state['instances'] > 2000 ) { self::fail( 'A recipe may generate at most 2000 native region instances.' ); }
                $prototype = $region['prototype'];
                if ( isset( $region['variants'] ) ) { $choice = self::get( $item, $region['variant_by'], $has_choice ); if ( ! $has_choice && isset( $region['default_variant'] ) ) { $choice = $region['default_variant']; } if ( ! is_string( $choice ) || ! isset( $region['variants'][ $choice ] ) ) { self::fail( 'The content row names an unknown or missing declared native variant.' ); } $prototype = $region['variants'][ $choice ]; }
                $instance_seed = $seed . '/' . $region['id'] . '/' . $key; $instance = self::checked( $adapter->instantiate( $prototype['native'], $instance_seed, array_merge( $context, $region_context, [ 'item' => $item, 'region' => $region ] ) ) );
                if ( ! is_array( $instance ) || ! self::list_array( $instance['native'] ?? null ) || ! is_array( $instance['context'] ?? [] ) ) { self::fail( 'The native adapter returned an incomplete region instance.' ); }
                $part = self::compile_scope( $instance['native'], $prototype['bindings'] ?? [], $prototype['regions'] ?? [], $item, $adapter, $instance_seed, $instance['context'] ?? [], $state, $depth + 1 );
                $state['generated_bytes'] += strlen( self::json( $part ) ); if ( $state['generated_bytes'] > self::MAX_BYTES ) { self::fail( 'Generated native prototypes exceed the bounded eight-megabyte compilation budget.' ); }
                $children = array_merge( $children, $part );
            }
            $native = self::checked( $adapter->replace_region( $native, $region, $children, array_merge( $context, [ 'items' => $items, 'seed' => $seed . '/' . $region['id'] ] ) ) );
            $state['regions'][] = [ 'id' => $region['id'], 'source_items' => $input_count, 'output_items' => count( $items ), 'children' => count( $children ), 'kept' => false ];
        }
        return $native;
    }
    private static function format_value( $value, string $format, array &$warnings ) {
        if ( 'number' === $format ) { if ( ! ( is_int( $value ) || is_float( $value ) ) || ! is_finite( (float) $value ) ) { self::fail( 'Number bindings need a finite native number.' ); } return $value; }
        if ( 'boolean' === $format ) { if ( ! is_bool( $value ) ) { self::fail( 'Boolean bindings need an explicit true/false value.' ); } return $value; }
        if ( ! self::plain( $value, 2097152 ) ) { self::fail( 'Text/HTML/URL bindings require bounded UTF-8 strings.' ); }
        if ( 'url' === $format ) { $parts = parse_url( $value ); if ( '' !== $value && ( ! is_array( $parts ) || preg_match( '/[\x00-\x20<>"\x27\\\\]/', $value ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), [ 'http', 'https', 'mailto', 'tel' ], true ) ) || ( in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) && empty( $parts['host'] ) ) ) ) { self::fail( 'A URL binding uses an unsafe or malformed native link.' ); } return $value; }
        if ( 'html' === $format ) { if ( ! function_exists( 'wp_kses_post' ) ) { self::fail( 'WordPress HTML sanitization is unavailable.' ); } $safe = wp_kses_post( $value ); if ( $safe !== $value ) { $warnings[] = 'WordPress removed HTML outside its post-content policy from a content binding.'; } return $safe; }
        return $value;
    }
    private static function project( array $items, array $projection ): array {
        $groups = []; $keys = []; $previous = null;
        foreach ( $items as $index => $item ) {
            if ( ! is_array( $item ) ) { self::fail( 'Grouped article sections must be canonical objects.' ); }
            $value = self::get( $item, $projection['group_by'], $found ); if ( $found && null !== $value && '' !== $value && ! is_scalar( $value ) ) { self::fail( 'Device group keys must be scalar.' ); }
            $key = ! $found || null === $value || '' === $value ? 'item:' . $index : 'group:' . (string) $value;
            if ( isset( $keys[ $key ] ) && $previous !== $key ) { self::fail( 'Device groups must be contiguous so article content is not silently reordered.' ); }
            if ( ! isset( $keys[ $key ] ) ) { $keys[ $key ] = count( $groups ); $item['_group_key'] = $key; $groups[] = $item; }
            else { $position = $keys[ $key ]; foreach ( [ 'body', 'body_html', 'body_with_children_html', 'html' ] as $field ) { if ( isset( $item[ $field ] ) ) { if ( ! is_string( $item[ $field ] ) || ( isset( $groups[ $position ][ $field ] ) && ! is_string( $groups[ $position ][ $field ] ) ) ) { self::fail( 'Projected rich bodies must remain HTML strings.' ); } $groups[ $position ][ $field ] = ( $groups[ $position ][ $field ] ?? '' ) . $item[ $field ]; } } foreach ( [ 'children', 'faqs', 'blocks' ] as $field ) { if ( isset( $item[ $field ] ) ) { if ( ! self::list_array( $item[ $field ] ) || ( isset( $groups[ $position ][ $field ] ) && ! self::list_array( $groups[ $position ][ $field ] ) ) ) { self::fail( 'Projected nested content must remain an ordered list.' ); } $groups[ $position ][ $field ] = array_merge( $groups[ $position ][ $field ] ?? [], $item[ $field ] ); } } }
            $previous = $key;
        }
        foreach ( $groups as &$group ) { if ( isset( $projection['title_source'] ) ) { $title = self::get( $group, $projection['title_source'], $found ); if ( $found && '' !== $title ) { if ( ! is_string( $title ) ) { self::fail( 'A projected native title must be text.' ); } $group['title'] = $title; $group['title_html'] = htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); } } } unset( $group ); return $groups;
    }
    private static function scope_schema( array $bindings, array $regions ): array {
        $schema = [ 'type' => 'object', 'properties' => [], 'additionalProperties' => true ];
        foreach ( $bindings as $binding ) { $type = in_array( $binding['format'], [ 'number', 'boolean' ], true ) ? $binding['format'] : 'string'; self::schema_at( $schema, self::parts( $binding['source'] ), [ 'type' => $type, 'description' => $binding['instructions'] ?? $binding['label'] ?? '', 'x-native-formats' => [ $binding['format'] ] ], $binding['required'] ); }
        foreach ( $regions as $region ) {
            $item = self::scope_schema( $region['prototype']['bindings'] ?? [], $region['prototype']['regions'] ?? [] );
            if ( isset( $region['variants'] ) ) { $choices = []; foreach ( $region['variants'] as $name => $prototype ) { $variant = self::scope_schema( $prototype['bindings'] ?? [], $prototype['regions'] ?? [] ); self::schema_at( $variant, self::parts( $region['variant_by'] ), [ 'type' => 'string', 'enum' => [ $name ] ], true ); $choices[] = $variant; } $item = [ 'oneOf' => $choices ]; }
            if ( isset( $region['projection'] ) ) { self::schema_at( $item, self::parts( $region['projection']['group_by'] ), [ 'type' => 'string', 'description' => 'Explicit contiguous device grouping key; omit for one section per group.' ], false ); if ( isset( $region['projection']['title_source'] ) ) { self::schema_at( $item, self::parts( $region['projection']['title_source'] ), [ 'type' => 'string', 'description' => 'Optional short projected navigation/section title.' ], false ); } }
            if ( 'optional' === $region['kind'] && 'parent' === ( $region['context'] ?? 'item' ) ) { $schema = self::merge_schema( $schema, $item ); $parts = self::parts( $region['source'] ); if ( ! self::schema_get( $schema, $parts ) ) { self::schema_at( $schema, $parts, [ 'anyOf' => [ [ 'type' => 'boolean' ], [ 'type' => 'array' ], [ 'type' => 'object' ], [ 'type' => 'null' ] ], 'description' => $region['instructions'] ?? $region['label'] ?? '' ], false ); } else { self::schema_at( $schema, $parts, [ 'description' => $region['instructions'] ?? $region['label'] ?? '' ], false ); } }
            else { $value = 'repeat' === $region['kind'] ? [ 'type' => 'array', 'items' => $item, 'maxItems' => $region['max_items'] ] : [ 'anyOf' => [ $item, [ 'type' => 'null' ], [ 'type' => 'boolean' ] ] ]; $value['description'] = $region['instructions'] ?? $region['label'] ?? ''; self::schema_at( $schema, self::parts( $region['source'] ), $value, false ); }
        }
        return $schema;
    }
    private static function schema_at( array &$schema, array $parts, array $leaf, bool $required ): void {
        foreach ( [ 'oneOf', 'anyOf' ] as $union ) { if ( isset( $schema[ $union ] ) ) { foreach ( $schema[ $union ] as &$choice ) { self::schema_at( $choice, $parts, $leaf, $required ); } unset( $choice ); return; } }
        if ( ! $parts ) { $schema = self::merge_schema( $schema, $leaf ); return; }
        $part = array_shift( $parts ); if ( ! isset( $schema['properties'] ) ) { $schema['properties'] = []; }
        if ( ! isset( $schema['properties'][ $part ] ) ) { $schema['properties'][ $part ] = $parts ? [ 'type' => 'object', 'properties' => [] ] : []; }
        self::schema_at( $schema['properties'][ $part ], $parts, $leaf, $required );
        if ( $required ) { $schema['required'] = array_values( array_unique( array_merge( $schema['required'] ?? [], [ $part ] ) ) ); }
    }
    private static function schema_get( array $schema, array $parts ): array { foreach ( $parts as $part ) { if ( ! isset( $schema['properties'][ $part ] ) ) { return []; } $schema = $schema['properties'][ $part ]; } return $schema; }
    private static function merge_schema( array $left, array $right ): array {
        if ( ! $left ) { return $right; } if ( ! $right ) { return $left; }
        foreach ( [ 'oneOf', 'anyOf' ] as $union ) {
            if ( isset( $left[ $union ] ) || isset( $right[ $union ] ) ) {
                $options = static function ( array $schema ) use ( $union ): array { if ( ! isset( $schema[ $union ] ) ) { return [ $schema ]; } $choices = $schema[ $union ]; unset( $schema[ $union ] ); $out = []; foreach ( $choices as $choice ) { try { $out[] = self::merge_schema( $schema, $choice ); } catch ( InvalidArgumentException $error ) { /* A common constraint excludes this branch. */ } } return $out; };
                $a = $options( $left ); $b = $options( $right ); $choices = [];
                foreach ( $a as $one ) { foreach ( $b as $two ) { try { $choice = self::merge_schema( $one, $two ); $choices[ self::digest( $choice ) ] = $choice; } catch ( InvalidArgumentException $error ) { /* Disjoint variants are not valid in both views. */ } if ( count( $choices ) > 128 ) { self::fail( 'Combined native variants exceed the bounded canonical schema. Share one declared discriminator across the views.' ); } } }
                if ( ! $choices ) { self::fail( 'Canonical source schemas declare incompatible types or native variants across views.' ); }
                return count( $choices ) === 1 ? array_values( $choices )[0] : [ $union => array_values( $choices ) ];
            }
        }
        if ( isset( $left['type'], $right['type'] ) && $left['type'] !== $right['type'] ) { self::fail( 'Canonical source schemas declare conflicting value types.' ); }
        $merged = array_merge( $left, $right );
        foreach ( [ 'properties' ] as $key ) { if ( isset( $left[ $key ] ) || isset( $right[ $key ] ) ) { $merged[ $key ] = $left[ $key ] ?? []; foreach ( $right[ $key ] ?? [] as $name => $child ) { $merged[ $key ][ $name ] = self::merge_schema( $merged[ $key ][ $name ] ?? [], $child ); } } }
        if ( isset( $left['items'] ) || isset( $right['items'] ) ) { $merged['items'] = self::merge_schema( $left['items'] ?? [], $right['items'] ?? [] ); }
        foreach ( [ 'required', 'x-native-formats' ] as $key ) { if ( isset( $left[ $key ] ) || isset( $right[ $key ] ) ) { $merged[ $key ] = array_values( array_unique( array_merge( $left[ $key ] ?? [], $right[ $key ] ?? [] ) ) ); } }
        if ( isset( $left['enum'], $right['enum'] ) ) { $merged['enum'] = array_values( array_intersect( $left['enum'], $right['enum'] ) ); if ( ! $merged['enum'] ) { self::fail( 'Canonical source variants have no compatible discriminator.' ); } }
        if ( isset( $left['maxItems'], $right['maxItems'] ) ) { $merged['maxItems'] = min( $left['maxItems'], $right['maxItems'] ); }
        if ( isset( $left['description'] ) || isset( $right['description'] ) ) { $merged['description'] = implode( "\n", array_values( array_unique( array_filter( [ $left['description'] ?? '', $right['description'] ?? '' ] ) ) ) ); }
        return $merged;
    }
    private static function augmented_schema( array $recipe, $adapter ): array {
        $schema = self::scope_schema( $recipe['bindings'] ?? [], $recipe['regions'] ?? [] );
        if ( method_exists( $adapter, 'augment_content_schema' ) ) { $schema = self::checked( $adapter->augment_content_schema( $recipe, $schema ) ); if ( ! is_array( $schema ) || ( $schema && self::list_array( $schema ) ) ) { self::fail( 'A native schema adapter must return the complete content-schema object.' ); } }
        self::json( $schema ); return $schema;
    }
    public static function content_schema( array $recipe ) { return self::guard( static function () use ( $recipe ) { self::checked( self::validate( $recipe ) ); return self::augmented_schema( $recipe, self::checked( self::adapter( $recipe['builder'] ) ) ); } ); }
    public static function merge_content_schemas( array $left, array $right ) { return self::guard( static function () use ( $left, $right ) { self::json( $left ); self::json( $right ); $merged = self::merge_schema( $left, $right ); self::json( $merged ); return $merged; } ); }
    public static function empty_content( array $schema ) {
        if ( isset( $schema['anyOf'] ) ) { foreach ( $schema['anyOf'] as $option ) { if ( 'null' === ( $option['type'] ?? '' ) ) { return null; } } return self::empty_content( $schema['anyOf'][0] ); }
        if ( isset( $schema['oneOf'] ) ) { return self::empty_content( $schema['oneOf'][0] ); }
        if ( 'object' === ( $schema['type'] ?? '' ) ) { $value = []; foreach ( $schema['properties'] ?? [] as $key => $property ) { $value[ $key ] = self::empty_content( $property ); } return $value; }
        if ( 'array' === ( $schema['type'] ?? '' ) ) { return []; } if ( 'boolean' === ( $schema['type'] ?? '' ) ) { return false; } if ( in_array( $schema['type'] ?? '', [ 'number', 'integer' ], true ) ) { return 0; } if ( 'null' === ( $schema['type'] ?? '' ) ) { return null; } return isset( $schema['enum'][0] ) ? $schema['enum'][0] : '';
    }
}
