<?php
/** Native Elementor prototypes and protected complete-frame compilation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Elementor {
    public const VERSION = 'nova-elementor-prototype-adapter-v1';
    private function error( string $message ): WP_Error { return Nova_Bridge_Suite_Recipe_Engine::error( 'elementor', $message ); }
    private function safe( callable $work ) { try { return $work(); } catch ( InvalidArgumentException $error ) { return $this->error( $error->getMessage() ); } catch ( Throwable $error ) { return $this->error( 'The complete native Elementor blueprint is unavailable.' ); } }
    private function walk( array $nodes, callable $visitor, array $path = [], int $depth = 0 ): void {
        if ( $depth > 32 || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $nodes ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Elementor child arrays must remain ordered and bounded to 32 levels.' ); }
        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) || ! is_string( $node['id'] ?? null ) || '' === $node['id'] || strlen( $node['id'] ) > 128 || ! in_array( $node['elType'] ?? '', [ 'container', 'section', 'column', 'widget' ], true ) || ! is_array( $node['settings'] ?? null ) || ( isset( $node['elements'] ) && ! is_array( $node['elements'] ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A complete native Elementor node has an identity, registered kind, settings and ordered children.' ); }
            $pointer = array_merge( $path, [ $index ] ); $visitor( $node, $pointer ); $this->walk( $node['elements'] ?? [], $visitor, array_merge( $pointer, [ 'elements' ] ), $depth + 1 );
        }
    }
    private function pointer( array $parts ): string { return '/' . implode( '/', array_map( static function ( $part ) { return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $part ); }, $parts ) ); }
    private function document( array $native ): array { return array_key_exists( 'document', $native ) ? $native['document'] : $native; }
    public function validate_blueprint( array $blueprint ) {
        return $this->safe( function () use ( $blueprint ) {
            if ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $blueprint['document'] ?? null ) || ( isset( $blueprint['meta'] ) && ! is_array( $blueprint['meta'] ) ) || ( isset( $blueprint['template'] ) && ! is_string( $blueprint['template'] ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Elementor needs a complete document list and optional native page settings/template.' ); }
            Nova_Bridge_Suite_Recipe_Engine::json( $blueprint ); $ids = []; $anchors = []; $count = 0;
            $this->walk( $blueprint['document'], function ( $node ) use ( &$ids, &$anchors, &$count ) {
                if ( ++$count > 2000 || isset( $ids[ $node['id'] ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'The complete Elementor tree exceeds 2000 nodes or contains duplicate native identities.' ); } $ids[ $node['id'] ] = true;
                $anchor = $node['settings']['_element_id'] ?? ''; if ( '' !== $anchor ) { if ( ! is_string( $anchor ) || preg_match( '/[\x00-\x20]/', $anchor ) || isset( $anchors[ $anchor ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Explicit native HTML anchors must be unique within the saved blueprint.' ); } $anchors[ $anchor ] = true; }
                $scan = static function ( $value, string $key = '' ) use ( &$scan, &$anchors ) { if ( is_array( $value ) ) { foreach ( $value as $name => $child ) { $scan( $child, (string) $name ); } } elseif ( is_string( $value ) && in_array( $key, [ 'title', 'editor', 'html', 'tab_title', 'tab_content' ], true ) ) { foreach ( self::html_anchors( $value ) as $anchor ) { if ( isset( $anchors[ $anchor ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Inline and native HTML anchor targets must be unambiguous within the complete document.' ); } $anchors[ $anchor ] = true; } } }; $scan( $node['settings'] );
                if ( 'nested-tabs' === ( $node['widgetType'] ?? '' ) && ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $node['settings']['tabs'] ?? null ) || count( $node['settings']['tabs'] ) !== count( $node['elements'] ?? [] ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A complete native nested-tabs document must retain one navigation record per panel.' ); }
            } );
            return $blueprint;
        } );
    }
    private function node_for_target( array $native, string $target ): array {
        $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $target ); $position = array_search( 'settings', $parts, true );
        if ( false === $position || $position < 1 ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Only declared editorial Elementor settings can be bound.' ); }
        $node = Nova_Bridge_Suite_Recipe_Engine::get( $native, $this->pointer( array_slice( $parts, 0, $position ) ), $found );
        if ( ! $found || ! is_array( $node ) || 'widget' !== ( $node['elType'] ?? '' ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'The binding does not resolve to an existing native widget.' ); }
        return [ $node, array_slice( $parts, $position + 1 ) ];
    }
    public function validate_binding( array $native, array $binding ) {
        return $this->safe( function () use ( $native, $binding ) {
            list( $node, $path ) = $this->node_for_target( $native, $binding['target'] ); $widget = $node['widgetType'] ?? ''; $control = $path[0] ?? ''; $settings = $node['settings'];
            $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $binding['target'], $found );
            if ( ! $found || is_array( $value ) || isset( $settings['__dynamic__'][ $control ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Missing, structured and dynamic Elementor content remains protected.' ); }
            $formats = [];
            if ( 'heading' === $widget && [ 'title' ] === $path ) { $formats = [ 'text', 'html' ]; }
            elseif ( 'text-editor' === $widget && [ 'editor' ] === $path ) { $formats = [ 'text', 'html' ]; }
            elseif ( 'button' === $widget && [ 'text' ] === $path ) { $formats = [ 'text', 'html' ]; }
            elseif ( in_array( $widget, [ 'heading', 'button', 'image' ], true ) && [ 'link', 'url' ] === $path ) { $formats = [ 'url' ]; }
            elseif ( 'image' === $widget && in_array( $path, [ [ 'image', 'url' ], [ 'image', 'alt' ] ], true ) && empty( $settings['image']['id'] ) ) { $formats = [ 'url' === end( $path ) ? 'url' : 'text' ]; }
            elseif ( 'image' === $widget && [ 'caption' ] === $path ) { $formats = [ 'text', 'html' ]; }
            elseif ( in_array( $widget, [ 'accordion', 'toggle', 'nested-tabs' ], true ) && count( $path ) === 3 && 'tabs' === $path[0] && ctype_digit( $path[1] ) && in_array( $path[2], [ 'tab_title', 'tab_content' ], true ) ) { $formats = [ 'text', 'html' ]; }
            elseif ( 'table-of-contents' === $widget && [ 'title' ] === $path ) { $formats = [ 'text', 'html' ]; }
            if ( ! in_array( $binding['format'], $formats, true ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'This native control is an identity, style, dynamic binding, opaque setting or incompatible content format.' ); }
            return true;
        } );
    }
    public function validate_region( array $native, array $region ) {
        return $this->safe( function () use ( $native, $region ) {
            $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['target'] ); $last = end( $parts );
            $list = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'], $found );
            if ( ! $found || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $list ) || ( 'elements' !== $last && 'document' !== $last && '' !== $region['target'] && ! ( count( $parts ) === 1 && ctype_digit( $last ) ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Elementor regions replace explicit native child arrays, not arbitrary style/repeater objects.' ); }
            $parent = 'elements' === $last ? Nova_Bridge_Suite_Recipe_Engine::get( $native, $this->pointer( array_slice( $parts, 0, -1 ) ), $has_parent ) : null;
            $prototypes = [ $region['prototype'] ?? [] ]; foreach ( $region['variants'] ?? [] as $prototype ) { $prototypes[] = $prototype; }
            foreach ( $prototypes as $prototype ) {
                if ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $prototype['native'] ?? null ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'An Elementor region needs complete ordered native prototype children.' ); }
                $this->walk( $prototype['native'], static function () {} );
                if ( is_array( $parent ) && 'nested-tabs' === ( $parent['widgetType'] ?? '' ) && ( count( $prototype['native'] ) !== 1 || 'container' !== ( $prototype['native'][0]['elType'] ?? '' ) ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Each nested-tab iteration must generate one complete native panel container.' ); }
            }
            return true;
        } );
    }
    public function validate_plan_scope( array $native, array $bindings, array $regions ) {
        return $this->safe( function () use ( $native, $bindings, $regions ) {
            foreach ( $regions as $region ) { $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['target'] ); if ( 'elements' !== end( $parts ) ) { continue; } $parent_pointer = $this->pointer( array_slice( $parts, 0, -1 ) ); $parent = Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent_pointer, $found ); if ( ! $found || 'nested-tabs' !== ( $parent['widgetType'] ?? '' ) ) { continue; }
                $prefix = $parent_pointer . '/settings/tabs/'; foreach ( $bindings as $binding ) { if ( 0 !== strpos( $binding['target'], $prefix ) ) { continue; } $index = explode( '/', substr( $binding['target'], strlen( $prefix ) ) )[0]; if ( ctype_digit( $index ) && (int) $index >= $region['start'] && (int) $index < $region['start'] + $region['count'] ) { return $this->error( 'A fixed tab-label binding overlaps a generated panel range. Use the section title or projected mobile title for that region, or keep the fixed panel outside the replaced range.' ); } }
            } return true;
        } );
    }
    public function instantiate( array $prototype_native, string $seed, array $context = [] ) {
        return $this->safe( function () use ( $prototype_native, $seed, $context ) {
            $native = $this->rebase( $prototype_native, $seed, true, $identity ); $context['identity_map'] = $identity['nodes']; $context['anchor_map'] = array_merge( $context['anchor_map'] ?? [], $context['canonical_anchor_map'] ?? [], $identity['anchors'] );
            $scan = static function ( $value ) use ( &$scan, &$context, $seed ) { if ( is_array( $value ) ) { foreach ( $value as $child ) { $scan( $child ); } } elseif ( is_string( $value ) ) { foreach ( self::html_anchors( $value ) as $anchor ) { if ( ! isset( $context['anchor_map'][ $anchor ] ) ) { $context['anchor_map'][ $anchor ] = 'nova-content-' . substr( hash( 'sha256', $seed . '/content-anchor/' . $anchor ), 0, 12 ); } } } };
            $scan( $context['item'] ?? [] ); return [ 'native' => $native, 'context' => $context ];
        } );
    }
    /** Resolve canonical cross-section links once per view, including nested sections. */
    public function region_context( array $items, string $seed, array $region, array $context = [] ) {
        return $this->safe( function () use ( $items, $seed, $context ) {
            $map = $context['canonical_anchor_map'] ?? []; $owners = [];
            foreach ( $items as $index => $item ) { $found = []; $scan = static function ( $value ) use ( &$scan, &$found ) { if ( is_array( $value ) ) { foreach ( $value as $child ) { $scan( $child ); } } elseif ( is_string( $value ) ) { foreach ( self::html_anchors( $value ) as $anchor ) { $found[ $anchor ] = true; } } }; $scan( $item );
                foreach ( array_keys( $found ) as $anchor ) { if ( isset( $owners[ $anchor ] ) && $owners[ $anchor ] !== $index ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Canonical section HTML contains an ambiguous repeated anchor. Use a unique anchor for each source section.' ); } $owners[ $anchor ] = $index; if ( ! isset( $map[ $anchor ] ) ) { $map[ $anchor ] = 'nova-content-' . substr( hash( 'sha256', $seed . '/canonical-anchor/' . $anchor ), 0, 12 ); } }
            }
            return [ 'canonical_anchor_map' => $map ];
        } );
    }
    public function bind( array $native, array $binding, $value, array $context = [] ) {
        return $this->safe( function () use ( $native, $binding, $value, $context ) {
            $check = $this->validate_binding( $native, $binding ); if ( is_wp_error( $check ) ) { return $check; }
            list( $node, $path ) = $this->node_for_target( $native, $binding['target'] );
            if ( 'html' === $binding['format'] && ( 'heading' === ( $node['widgetType'] ?? '' ) || [ 'text' ] === $path || 'tab_title' === end( $path ) ) && preg_match( '/<(?:h[1-6]|p|div|section|article|main|ul|ol|li|table|details|blockquote|figure)\b/i', $value ) ) { return $this->error( 'Native heading/button/navigation titles accept inline HTML; put complete paragraphs in a rich-text body slot.' ); }
            if ( 'text' === $binding['format'] && [ 'image', 'alt' ] !== $path ) { $value = htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
            if ( 'html' === $binding['format'] ) { $value = $this->html_anchor_references( $value, $context['anchor_map'] ?? [] ); }
            if ( 'url' === $binding['format'] && 0 === strpos( $value, '#' ) && isset( $context['anchor_map'][ substr( $value, 1 ) ] ) ) { $value = '#' . $context['anchor_map'][ substr( $value, 1 ) ]; }
            Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); return $native;
        } );
    }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) {
        return $this->safe( function () use ( $native, $region, $children, $context ) {
            $parts = Nova_Bridge_Suite_Recipe_Engine::parts( $region['target'] ); $parent_pointer = $this->pointer( array_slice( $parts, 0, -1 ) );
            $parent = 'elements' === end( $parts ) ? Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent_pointer, $found ) : null;
            if ( is_array( $parent ) && 'nested-tabs' === ( $parent['widgetType'] ?? '' ) ) {
                $tabs = $parent['settings']['tabs'] ?? []; if ( ! Nova_Bridge_Suite_Recipe_Engine::list_array( $tabs ) || count( $tabs ) !== count( $parent['elements'] ?? [] ) || count( $children ) !== count( $context['items'] ?? [] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Nested-tab label/panel structure or generated panel cardinality is inconsistent.' ); }
                $tab_prototype = $tabs[ $region['prototype_start'] ?? $region['start'] ] ?? [ 'tab_title' => '' ]; $new_tabs = [];
                foreach ( $children as $index => $child ) {
                    $item = $context['items'][ $index ]; $title = is_array( $item ) ? ( $item['title'] ?? $item['title_html'] ?? $item['question'] ?? '' ) : '';
                    if ( '' === $title ) { $title = $this->first_title( $child ); } if ( ! is_string( $title ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'Generated tab navigation titles must be text.' ); }
                    $tab = $tab_prototype; $tab['tab_title'] = trim( html_entity_decode( strip_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); $tab['_id'] = substr( hash( 'sha256', ( $context['seed'] ?? '' ) . '/tab/' . $index ), 0, 7 ); if ( ! empty( $tab['element_id'] ) ) { $tab['element_id'] = 'nova-tab-' . $tab['_id']; } $new_tabs[] = $tab;
                }
                array_splice( $tabs, $region['start'], $region['count'], $new_tabs ); $parent['settings']['tabs'] = $tabs; Nova_Bridge_Suite_Recipe_Engine::put( $native, $parent_pointer, $parent );
            }
            Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native;
        } );
    }
    private function first_title( array $node ): string { if ( 'heading' === ( $node['widgetType'] ?? '' ) ) { return (string) ( $node['settings']['title'] ?? '' ); } foreach ( $node['elements'] ?? [] as $child ) { $title = $this->first_title( $child ); if ( '' !== $title ) { return $title; } } return ''; }
    private static function html_anchors( string $value ): array { preg_match_all( '/<[a-zA-Z][^>]*>/s', $value, $tags ); $out = []; foreach ( $tags[0] as $tag ) { preg_match_all( '/(?<![a-zA-Z0-9_:\-])id\s*=\s*(?:([\x22\x27])(.*?)\1|([^\s<>\x22\x27=]+))/is', $tag, $matches, PREG_SET_ORDER ); foreach ( $matches as $match ) { $anchor = $match[3] ?? $match[2]; if ( '' !== $anchor ) { $out[] = html_entity_decode( $anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); } } } return $out; }
    private function html_anchor_references( string $value, array $anchors ): string {
        if ( ! $anchors ) { return $value; }
        return preg_replace_callback( '/<[a-zA-Z][^>]*>/s', static function ( $tag ) use ( $anchors ) { return preg_replace_callback( '/(?<![a-zA-Z0-9_:\-])(id|href)\s*=\s*(?:([\x22\x27])(.*?)\2|([^\s<>\x22\x27=]+))/is', static function ( $match ) use ( $anchors ) { $value = html_entity_decode( $match[4] ?? $match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ); $href = 'href' === strtolower( $match[1] ); $key = $href ? ( 0 === strpos( $value, '#' ) ? substr( $value, 1 ) : '' ) : $value; $quote = $match[2] ?: '"'; return isset( $anchors[ $key ] ) ? $match[1] . '=' . $quote . ( $href ? '#' : '' ) . htmlspecialchars( $anchors[ $key ], ENT_QUOTES, 'UTF-8' ) . $quote : $match[0]; }, $tag[0] ); }, $value );
    }
    private function native_selectors( string $value, array $identities ): string { return preg_replace_callback( '/(?<=elementor-element-)[a-zA-Z0-9_-]+/', static function ( $match ) use ( $identities ) { return $identities[ $match[0] ] ?? $match[0]; }, $value ); }
    private function anchor_selectors( string $css, array $anchors ): string { return preg_replace_callback( '/(^|[{}])([^{}]*)(?=\{)/s', static function ( $match ) use ( $anchors ) { $selector = $match[2]; foreach ( $anchors as $old => $new ) { $selector = preg_replace( '/#' . preg_quote( $old, '/' ) . '(?![\pL\pN_-])/u', '#' . $new, $selector ); } return $match[1] . $selector; }, $css ); }
    private function anchors_and_links( array $nodes ): array {
        $anchors = []; $links = [];
        $this->walk( $nodes, function ( $node ) use ( &$anchors, &$links ) { if ( ! empty( $node['settings']['_element_id'] ) ) { $anchors[ $node['settings']['_element_id'] ] = true; }
            $scan = static function ( $value, string $key = '' ) use ( &$scan, &$anchors, &$links ) { if ( is_array( $value ) ) { foreach ( $value as $name => $child ) { $scan( $child, (string) $name ); } } elseif ( is_string( $value ) ) { if ( 'url' === $key && 0 === strpos( $value, '#' ) ) { $links[] = substr( $value, 1 ); } if ( in_array( $key, [ 'title', 'editor', 'html', 'tab_title', 'tab_content' ], true ) ) { foreach ( self::html_anchors( $value ) as $anchor ) { $anchors[ $anchor ] = true; } preg_match_all( '/(?<![a-zA-Z0-9_:\-])href\s*=\s*([\x22\x27])#([^\x22\x27]+)\1/i', $value, $matches ); foreach ( $matches[2] as $anchor ) { $links[] = html_entity_decode( $anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); } } } }; $scan( $node['settings'] );
        } ); return [ 'anchors' => $anchors, 'links' => $links ];
    }
    private function rebase( array $nodes, string $seed, bool $anchors, &$identity = null ): array {
        $ids = []; $anchor_map = []; $allocated = [];
        $allocate = static function ( string $identity ) use ( $seed, &$allocated ) { $salt = 0; do { $id = substr( hash( 'sha256', $seed . '/' . $identity . '/' . $salt++ ), 0, 7 ); } while ( isset( $allocated[ $id ] ) ); $allocated[ $id ] = true; return $id; };
        $this->walk( $nodes, function ( $node, $path ) use ( &$ids, &$anchor_map, $allocate, $anchors ) { if ( isset( $ids[ $node['id'] ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A prototype contains duplicate node identities.' ); } $ids[ $node['id'] ] = $allocate( 'node/' . $this->pointer( $path ) . '/' . $node['id'] ); if ( $anchors && ! empty( $node['settings']['_element_id'] ) ) { $old = $node['settings']['_element_id']; if ( isset( $anchor_map[ $old ] ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A prototype has ambiguous duplicate anchor targets.' ); } $anchor_map[ $old ] = 'nova-' . $allocate( 'anchor/' . $old ); } } );
        if ( $anchors ) { $scan = static function ( $value, string $key = '' ) use ( &$scan, &$anchor_map, $allocate ) { if ( is_array( $value ) ) { foreach ( $value as $name => $child ) { $scan( $child, (string) $name ); } } elseif ( is_string( $value ) && in_array( $key, [ 'title', 'editor', 'html', 'tab_title', 'tab_content' ], true ) ) { foreach ( self::html_anchors( $value ) as $anchor ) { if ( ! isset( $anchor_map[ $anchor ] ) ) { $anchor_map[ $anchor ] = 'nova-' . $allocate( 'inline-anchor/' . $anchor ); } } } }; $scan( $nodes ); }
        $rewrite = function ( $value, string $key = '', bool $list_item = false ) use ( &$rewrite, $ids, $anchor_map, $allocate ) {
            if ( is_array( $value ) ) {
                if ( isset( $value['elType'], $value['id'] ) && isset( $ids[ $value['id'] ] ) ) { $value['id'] = $ids[ $value['id'] ]; }
                if ( $list_item && isset( $value['_id'] ) && is_string( $value['_id'] ) ) { $value['_id'] = $allocate( 'repeater/' . $value['_id'] . '/' . $key ); }
                $list = Nova_Bridge_Suite_Recipe_Engine::list_array( $value ); foreach ( $value as $name => $child ) { $value[ $name ] = $rewrite( $child, (string) $name, $list ); } return $value;
            }
            if ( ! is_string( $value ) ) { return $value; }
            if ( '_element_id' === $key && isset( $anchor_map[ $value ] ) ) { return $anchor_map[ $value ]; }
            if ( 'url' === $key && 0 === strpos( $value, '#' ) && isset( $anchor_map[ substr( $value, 1 ) ] ) ) { return '#' . $anchor_map[ substr( $value, 1 ) ]; }
            if ( in_array( $key, [ 'title', 'html', 'editor', 'tab_title', 'tab_content' ], true ) ) { $value = $this->html_anchor_references( $value, $anchor_map ); }
            if ( in_array( $key, [ 'custom_css', 'html', 'editor' ], true ) ) {
                $value = $this->native_selectors( $value, $ids );
                if ( 'custom_css' === $key ) { $value = $this->anchor_selectors( $value, $anchor_map ); }
            }
            return $value;
        };
        $identity = [ 'nodes' => $ids, 'anchors' => $anchor_map ]; return $rewrite( $nodes );
    }
    /** Match Element_Base/Widget_Base get_raw_data() before freezing the native payload. */
    private function provider_flags( array $nodes ): array { foreach ( $nodes as &$node ) { if ( 'widget' === $node['elType'] ) { unset( $node['isInner'] ); } elseif ( ! array_key_exists( 'isInner', $node ) ) { $node['isInner'] = false; } $node['elements'] = $this->provider_flags( $node['elements'] ?? [] ); } unset( $node ); return $nodes; }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        return $this->safe( function () use ( $blueprint, $seed, $context ) {
            $check = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $check ) ) { return $check; }
            if ( isset( $context['recipe']['blueprint']['document'] ) ) { $old = $this->anchors_and_links( $context['recipe']['blueprint']['document'] ); $current = $this->anchors_and_links( $blueprint['document'] ); foreach ( $current['links'] as $anchor ) { if ( isset( $old['anchors'][ $anchor ] ) && ! isset( $current['anchors'][ $anchor ] ) ) { return $this->error( 'A fixed native link still targets the removed example anchor #' . $anchor . '. Bind that navigation link to canonical content or remove the stale sample link before creating a draft.' ); } } }
            $blueprint['document'] = $this->provider_flags( $this->rebase( $blueprint['document'], $seed . '/document', false, $identity ) );
            if ( isset( $blueprint['meta']['custom_css'] ) && is_string( $blueprint['meta']['custom_css'] ) ) { $blueprint['meta']['custom_css'] = $this->native_selectors( $blueprint['meta']['custom_css'], $identity['nodes'] ); }
            $check = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $check ) ) { return $check; }
            $payload = [ 'elementor_data' => $blueprint['document'] ]; if ( array_key_exists( 'meta', $blueprint ) ) { $payload['elementor_page_settings'] = $blueprint['meta']; } if ( isset( $blueprint['template'] ) ) { $payload['template'] = $blueprint['template']; }
            $count = 0; $this->walk( $blueprint['document'], static function () use ( &$count ) { ++$count; } );
            return [ 'native_document' => $blueprint, 'writer_payload' => $payload, 'preview_html' => $this->preview( $blueprint['document'] ), 'warnings' => [ 'Structure preview preserves content grouping; native global styles, responsive widgets and dynamic site bindings are verified in the native draft.' ], 'summary' => [ 'nodes' => $count ], 'adapter_version' => self::VERSION ];
        } );
    }
    private function preview( array $nodes ): string {
        $html = '';
        foreach ( $nodes as $node ) {
            $part = '';
            $settings = $node['settings']; $widget = $node['widgetType'] ?? ''; $anchor = isset( $settings['_element_id'] ) ? ' id="' . htmlspecialchars( $settings['_element_id'], ENT_QUOTES, 'UTF-8' ) . '"' : '';
            if ( 'heading' === $widget ) { $tag = in_array( $settings['header_size'] ?? 'h2', [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ], true ) ? ( $settings['header_size'] ?? 'h2' ) : 'h2'; $part .= '<' . $tag . $anchor . '>' . wp_kses_post( $settings['title'] ?? '' ) . '</' . $tag . '>'; }
            elseif ( 'text-editor' === $widget ) { $part .= '<div' . $anchor . '>' . wp_kses_post( $settings['editor'] ?? '' ) . '</div>'; }
            elseif ( 'button' === $widget ) { $part .= '<span class="nova-recipe-button">' . wp_kses_post( $settings['text'] ?? '' ) . '</span>'; }
            elseif ( 'image' === $widget ) { if ( isset( $settings['__dynamic__']['image'] ) ) { $part .= '<span class="nova-recipe-native-placeholder">Dynamic native image</span>'; } elseif ( ! empty( $settings['image']['url'] ) ) { $part .= '<img src="' . htmlspecialchars( $settings['image']['url'], ENT_QUOTES, 'UTF-8' ) . '" alt="' . htmlspecialchars( $settings['image']['alt'] ?? '', ENT_QUOTES, 'UTF-8' ) . '">'; } }
            elseif ( in_array( $widget, [ 'accordion', 'toggle' ], true ) ) { foreach ( $settings['tabs'] ?? [] as $tab ) { $part .= '<details><summary>' . wp_kses_post( $tab['tab_title'] ?? '' ) . '</summary>' . wp_kses_post( $tab['tab_content'] ?? '' ) . '</details>'; } }
            elseif ( 'table-of-contents' === $widget ) { $part .= '<aside class="nova-recipe-native-placeholder">Native table of contents</aside>'; }
            elseif ( 'nested-tabs' === $widget ) { foreach ( $node['elements'] ?? [] as $index => $panel ) { $part .= '<section class="nova-recipe-tab"><h4>' . htmlspecialchars( $settings['tabs'][ $index ]['tab_title'] ?? '', ENT_QUOTES, 'UTF-8' ) . '</h4>' . $this->preview( [ $panel ] ) . '</section>'; } }
            elseif ( 'widget' === $node['elType'] ) { $part .= '<span class="nova-recipe-native-placeholder">Protected native ' . htmlspecialchars( $widget, ENT_QUOTES, 'UTF-8' ) . ' widget</span>'; }
            if ( 'nested-tabs' !== $widget ) { $children = $this->preview( $node['elements'] ?? [] ); if ( 'widget' !== $node['elType'] ) { $part .= '<div' . $anchor . ' data-native-role="' . htmlspecialchars( $settings['_title'] ?? 'container', ENT_QUOTES, 'UTF-8' ) . '">' . $children . '</div>'; } else { $part .= $children; } }
            $classes = []; foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) { if ( ! empty( $settings[ 'hide_' . $device ] ) ) { $classes[] = 'elementor-hidden-' . $device; } } $html .= $classes ? '<div class="' . implode( ' ', $classes ) . '">' . $part . '</div>' : $part;
        }
        return $html;
    }

    public function capabilities( string $post_type = 'page' ): array {
        $provider = defined( 'ELEMENTOR_VERSION' ) && class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) && class_exists( '\Elementor\Plugin' );
        $type = function_exists( 'get_post_type_object' ) ? get_post_type_object( $post_type ) : null;
        $create = $provider && $type && current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) && current_user_can( $type->cap->edit_posts );
        return [ 'id' => 'elementor', 'builder' => 'elementor', 'provider' => 'elementor', 'version' => defined( 'ELEMENTOR_VERSION' ) ? (string) ELEMENTOR_VERSION : '', 'available' => $provider, 'source_free' => true, 'modes' => [ 'static', 'mixed', 'rules' ], 'capture' => $provider, 'starter' => $provider, 'draft' => $create, 'create' => $create, 'preview' => true, 'native_prototypes' => true, 'responsive_projection' => true, 'reason' => $provider ? '' : 'Enable Elementor and its NOVA bridge before creating a native test draft.', 'warnings' => [] ];
    }
    public function capture( int $id ) {
        return $this->safe( function () use ( $id ) {
            if ( ! current_user_can( 'edit_post', $id ) || ! class_exists( '\SEOR_Elementor_Bridge\Elementor_Service' ) ) { return $this->error( 'An editable native Elementor example and the installed bridge reader are required.' ); }
            $post = get_post( $id ); if ( ! $post || 'builder' !== get_post_meta( $id, '_elementor_edit_mode', true ) ) { return $this->error( 'The example does not have an unambiguous active Elementor document.' ); }
            $service = new \SEOR_Elementor_Bridge\Elementor_Service(); $payload = $service->get_page_payload( $id, [ 'include_document' => true, 'include_fields' => false, 'include_element_map' => false ] ); if ( is_wp_error( $payload ) ) { return $payload; }
            $raw = get_post_meta( $id, '_elementor_data', true ); $stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
            if ( ! is_array( $stored ) || Nova_Bridge_Suite_Recipe_Engine::digest( $stored ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $payload['document'] ?? null ) ) { return $this->error( 'The provider reader did not return the complete stored native tree.' ); }
            $blueprint = [ 'document' => $stored ]; if ( metadata_exists( 'post', $id, '_elementor_page_settings' ) ) { $blueprint['meta'] = get_post_meta( $id, '_elementor_page_settings', true ); } $blueprint['template'] = (string) get_page_template_slug( $id );
            return $this->describe( $blueprint );
        } );
    }
    public function describe( array $blueprint ) {
        return $this->safe( function () use ( $blueprint ) {
            $check = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $check ) ) { return $check; }
            $fields = []; $containers = []; $ordinals = [];
            $candidate = static function ( string $target, string $label, array $children ): array { $items = []; foreach ( $children as $index => $child ) { $name = $child['settings']['_title'] ?? ''; $name = is_string( $name ) && '' !== trim( $name ) && strlen( $name ) <= 200 ? $name : ucfirst( str_replace( '-', ' ', $child['widgetType'] ?? $child['elType'] ) ); $items[] = [ 'index' => $index, 'label' => 'Item ' . ( $index + 1 ) . ' · ' . $name, 'kind' => $child['widgetType'] ?? $child['elType'] ]; } return [ 'target' => $target, 'label' => $label, 'start' => 0, 'count' => count( $children ), 'items' => $items, 'prototype' => $children ? [ $children[0] ] : [] ]; };
            if ( $blueprint['document'] ) { $containers[] = $candidate( '/document', 'Document sections', $blueprint['document'] ); }
            $this->walk( $blueprint['document'], function ( $node, $path ) use ( $blueprint, $candidate, &$fields, &$containers, &$ordinals ) {
                $base = $this->pointer( array_merge( [ 'document' ], $path ) ); $widget = $node['widgetType'] ?? $node['elType']; $ordinals[ $widget ] = ( $ordinals[ $widget ] ?? 0 ) + 1; $label = ucfirst( str_replace( '-', ' ', $widget ) ) . ' ' . $ordinals[ $widget ];
                if ( isset( $node['elements'] ) && $node['elements'] ) { $containers[] = $candidate( $base . '/elements', ( $node['settings']['_title'] ?? '' ) ?: $label, $node['elements'] ); }
                $visit = function ( $value, array $parts ) use ( &$visit, $blueprint, $base, $label, &$fields ) {
                    if ( is_array( $value ) ) { foreach ( $value as $key => $child ) { $visit( $child, array_merge( $parts, [ $key ] ) ); } return; }
                    $target = $base . $this->pointer( array_merge( [ 'settings' ], $parts ) ); $key = (string) end( $parts ); if ( ! in_array( $key, [ 'title', 'editor', 'text', 'url', 'alt', 'caption', 'tab_title', 'tab_content' ], true ) || ! is_string( $value ) ) { return; }
                    $format = 'url' === $key ? 'url' : ( in_array( $key, [ 'editor', 'tab_content', 'title', 'caption' ], true ) ? 'html' : 'text' ); $valid = $this->validate_binding( $blueprint, [ 'target' => $target, 'format' => $format ] );
                    $fields[] = [ 'target' => $target, 'label' => $label . ' · ' . ucwords( str_replace( '_', ' ', $key ) ), 'format' => $format, 'value' => $value, 'writable' => ! is_wp_error( $valid ), 'protected' => is_wp_error( $valid ), 'reason' => is_wp_error( $valid ) ? $valid->get_error_message() : '' ];
                }; $visit( $node['settings'], [] );
            }, [], 0 );
            return [ 'blueprint' => $blueprint, 'fields' => $fields, 'containers' => $containers, 'prototypes' => [], 'capabilities' => $this->capabilities(), 'warnings' => [ 'A captured native example does not declare repetition or semantic grouping. Select and review those regions explicitly.' ] ];
        } );
    }
    public function starter( string $mode = 'mixed' ): array {
        $widget = static function ( $id, $type, $settings ) { return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ]; };
        $container = static function ( $id, $children, $title ) { return [ 'id' => $id, 'elType' => 'container', 'settings' => [ '_title' => $title, 'flex_direction' => 'column', 'flex_gap' => [ 'unit' => 'px', 'column' => 20, 'row' => 20, 'isLinked' => true ] ], 'elements' => $children ]; };
        $section = $container( 'c000003', [ $widget( 'a000004', 'heading', [ 'title' => '', 'header_size' => 'h2' ] ), $widget( 'a000005', 'text-editor', [ 'editor' => '' ] ) ], 'Article section' );
        $faq = $container( 'c000006', [ $widget( 'a000007', 'heading', [ 'title' => 'Questions', 'header_size' => 'h2' ] ), $container( 'c000008', [ $widget( 'a000009', 'heading', [ 'title' => '', 'header_size' => 'h3' ] ), $widget( 'a000010', 'text-editor', [ 'editor' => '' ] ) ], 'Question and answer' ) ], 'FAQ region' );
        $blueprint = [ 'document' => [ $container( 'c000000', [ $widget( 'a000001', 'heading', [ 'title' => '', 'header_size' => 'h1' ] ), $widget( 'a000002', 'text-editor', [ 'editor' => '' ] ), $section, $faq ], 'Article' ) ] ];
        $bindings = [ [ 'target' => '/document/0/elements/0/settings/title', 'source' => '/title_html', 'format' => 'html', 'required' => true, 'label' => 'Article title' ], [ 'target' => '/document/0/elements/1/settings/editor', 'source' => '/intro_html', 'format' => 'html', 'required' => false, 'label' => 'Introduction' ] ];
        $section_binding = [ [ 'target' => '/0/elements/0/settings/title', 'source' => '/title_html', 'format' => 'html', 'required' => true, 'label' => 'Section heading' ], [ 'target' => '/0/elements/1/settings/editor', 'source' => '/body_with_children_html', 'format' => 'html', 'required' => true, 'label' => 'Section content' ] ];
        $faq_binding = [ [ 'target' => '/0/elements/0/settings/title', 'source' => '/question', 'format' => 'html', 'required' => true, 'label' => 'FAQ question' ], [ 'target' => '/0/elements/1/settings/editor', 'source' => '/answer_html', 'format' => 'html', 'required' => true, 'label' => 'FAQ answer' ] ];
        $regions = [ [ 'id' => 'article_sections', 'label' => 'Article section cards', 'target' => '/document/0/elements', 'start' => 2, 'count' => 1, 'source' => '/sections', 'kind' => 'repeat', 'empty' => 'remove', 'max_items' => 500, 'prototype' => [ 'native' => [ $section ], 'bindings' => $section_binding, 'regions' => [] ] ], [ 'id' => 'faq_frame', 'label' => 'Optional questions', 'target' => '/document/0/elements', 'start' => 3, 'count' => 1, 'source' => '/faqs', 'kind' => 'optional', 'context' => 'parent', 'empty' => 'remove', 'max_items' => 500, 'prototype' => [ 'native' => [ $faq ], 'bindings' => [], 'regions' => [ [ 'id' => 'faq_rows', 'label' => 'Question rows', 'target' => '/0/elements', 'start' => 1, 'count' => 1, 'source' => '/faqs', 'kind' => 'repeat', 'empty' => 'remove', 'max_items' => 500, 'prototype' => [ 'native' => [ $faq['elements'][1] ], 'bindings' => $faq_binding, 'regions' => [] ] ] ] ] ] ];
        if ( 'static' === $mode ) { $blueprint['document'][0]['elements'] = array_slice( $blueprint['document'][0]['elements'], 0, 2 ); $blueprint['document'][0]['elements'][] = $widget( 'a000011', 'text-editor', [ 'editor' => '' ] ); $bindings[] = [ 'target' => '/document/0/elements/2/settings/editor', 'source' => '/body', 'format' => 'html', 'required' => true, 'label' => 'Fixed article body' ]; $regions = []; }
        $artifact = $this->describe( $blueprint ); if ( is_wp_error( $artifact ) ) { return []; } $artifact['prototypes'] = [ 'section' => [ $section ], 'faq' => [ $faq['elements'][1] ] ]; $artifact['suggested_bindings'] = $bindings; $artifact['suggested_regions'] = $regions; return $artifact;
    }
    public function write_draft( array $writer_payload, array $post_fields = [] ) {
        return $this->safe( function () use ( $writer_payload, $post_fields ) {
            $type = $post_fields['post_type'] ?? 'page'; $caps = $this->capabilities( $type ); if ( ! $caps['create'] ) { return $this->error( 'Enable the native Elementor bridge and target creation capability before draft review.' ); }
            $plugin = \Elementor\Plugin::instance(); if ( ! isset( $plugin->elements_manager ) || ! method_exists( $plugin->elements_manager, 'get_element' ) ) { return $this->error( 'The installed native element registry is unavailable.' ); }
            $this->walk( $writer_payload['elementor_data'] ?? [], function ( $node ) use ( $plugin ) { if ( ! $plugin->elements_manager->get_element( $node['elType'], $node['widgetType'] ?? null ) ) { Nova_Bridge_Suite_Recipe_Engine::fail( 'A required native Elementor element is unavailable; no draft was inserted.' ); } } );
            $service = new \SEOR_Elementor_Bridge\Elementor_Service(); $id = $service->create_page( array_merge( $post_fields, $writer_payload, [ 'post_type' => $type, 'status' => 'draft' ] ) ); if ( is_wp_error( $id ) ) { return $id; }
            $verified = $this->verify_saved( (int) $id, $writer_payload ); if ( is_wp_error( $verified ) ) { return $verified; }
            return [ 'id' => (int) $id, 'post_id' => (int) $id, 'status' => 'draft', 'url' => get_permalink( $id ), 'edit_url' => get_edit_post_link( $id, 'raw' ), 'native_digest' => $verified['native_digest'] ];
        } );
    }
    public function verify_saved( int $id, array $writer_payload ) {
        return $this->safe( function () use ( $id, $writer_payload ) {
            if ( ! current_user_can( 'edit_post', $id ) ) { return $this->error( 'The saved native draft cannot be inspected with this permission.' ); }
            $raw = get_post_meta( $id, '_elementor_data', true ); $stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
            if ( ! is_array( $stored ) || Nova_Bridge_Suite_Recipe_Engine::digest( $stored ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $writer_payload['elementor_data'] ?? null ) || 'on' === get_post_meta( $id, '_et_pb_use_builder', true ) || 'builder' !== get_post_meta( $id, '_elementor_edit_mode', true ) ) { return $this->error( 'The complete native Elementor draft was not retained; reconcile this existing draft before retrying.' ); }
            if ( array_key_exists( 'elementor_page_settings', $writer_payload ) && Nova_Bridge_Suite_Recipe_Engine::digest( get_post_meta( $id, '_elementor_page_settings', true ) ) !== Nova_Bridge_Suite_Recipe_Engine::digest( $writer_payload['elementor_page_settings'] ) ) { return $this->error( 'Native Elementor page settings changed during persistence.' ); }
            if ( isset( $writer_payload['template'] ) && (string) get_page_template_slug( $id ) !== $writer_payload['template'] ) { return $this->error( 'The native WordPress page template changed during persistence.' ); }
            return [ 'post_id' => $id, 'native_digest' => Nova_Bridge_Suite_Recipe_Engine::digest( [ $stored, get_post_meta( $id, '_elementor_page_settings', true ), get_page_template_slug( $id ) ] ) ];
        } );
    }
}
