<?php
/** Frozen UX Builder shortcode layouts and their known-ID WordPress publication lifecycle. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-nova-bridge-suite-flatsome-document.php';
require_once __DIR__ . '/class-nova-bridge-suite-flatsome-registry.php';

final class Nova_Bridge_Suite_Recipe_Flatsome {
    private static function error( string $message, int $status = 409 ): WP_Error { return new WP_Error( 'nova_recipe_flatsome', $message, [ 'status' => $status ] ); }
    private static function guard( callable $callback ) { try { return $callback(); } catch ( Throwable $e ) { return self::error( $e->getMessage() ); } }
    private static function registry() { return Nova_Bridge_Suite_Flatsome_Registry::get(); }
    // Sealed layouts use the frontend native carrier; publishing does not initialize the UX editor registry.
    private static function available(): bool { return defined( 'UX_BUILDER_VERSION' ) && function_exists( 'ux_row' ) && function_exists( 'ux_col' ); }
    public static function has_document( string $content ): bool {
        if ( preg_match( '~<!--\s*wp:flatsome/uxbuilder\b|\[ux_[a-z_]+(?=[\s/\]])~i', $content ) ) { return true; }
        return self::available() && 1 === preg_match( '~\[(?:row|col|section)(?:_inner(?:_[0-9]+)?)?(?=[\s/\]])~i', $content );
    }
    public function metadata( string $post_type = 'page' ): array {
        $support = $this->publication_support( $post_type );
        return [ 'id' => 'flatsome', 'label' => 'Flatsome / UX Builder', 'provider' => 'flatsome', 'version' => defined( 'UX_BUILDER_VERSION' ) ? (string) UX_BUILDER_VERSION : '', 'available' => self::available(), 'source_free' => true, 'capture' => true, 'starter' => self::available(), 'draft' => $support['supported'], 'modes' => [ 'static', 'mixed', 'rules' ], 'reason' => $support['reason'] ];
    }
    public function capabilities( string $post_type = 'page' ): array { return $this->metadata( $post_type ); }

    /** Registry option groups are presentation structure, not editable destinations themselves. */
    private static function options( array $options ): array {
        $result = [];
        foreach ( $options as $key => $option ) {
            if ( ! is_array( $option ) ) { continue; }
            if ( 'group' === ( $option['type'] ?? '' ) ) { $result += self::options( $option['options'] ?? [] ); }
            else { $result[$option['param_name'] ?? $key] = $option; }
        }
        return $result;
    }
    private static function external( string $tag ): bool { return in_array( preg_replace( '/_inner(?:_[0-9]+)?$/', '', $tag ), [ 'block', 'ux_block', 'ux_template' ], true ); }
    private static function protected_tag( string $tag ): bool { return 1 === preg_match( '/^(?:ux_html|ux_gutenberg|ux_products|ux_pages|ux_portfolio|blog_posts|ux_gallery|contact|gravityform|wpforms|fluentform|woocommerce|ux_sidebar|ux_nav|ux_instagram)/i', $tag ); }
    private static function semantic( string $key, array $option ): ?array {
        $type = $option['type'] ?? ''; $label = strip_tags( (string) ( $option['heading'] ?? ucwords( str_replace( '_', ' ', $key ) ) ) );
        if ( 'image' === $type ) { return [ 'format' => 'url', 'kind' => 'image_url', 'label' => $label, 'image' => true ]; }
        if ( ! in_array( $type, [ 'textfield', 'textarea', 'text-editor' ], true ) || ! preg_match( '/^(?:text|title|heading|sub_?title|description|caption|alt|name|label|button_text|link_text|content|link|url|href)$/D', $key ) ) { return null; }
        $url = in_array( $key, [ 'link', 'url', 'href' ], true );
        return [ 'format' => $url ? 'url' : ( 'text-editor' === $type ? 'html' : 'text' ), 'kind' => $url ? 'link_url' : ( in_array( $key, [ 'title', 'heading' ], true ) ? 'heading' : $key ), 'label' => $label, 'image' => false ];
    }
    /** Capture definitions as data: compilation never needs the example post again. */
    public function capture_document( string $content ) {
        return self::guard( function () use ( $content ) {
            if ( ! self::available() ) { return self::error( 'Load Flatsome and its UX Builder registry before capturing a layout.' ); }
            $nodes = Nova_Bridge_Suite_Flatsome_Document::parse( $content ); $registry = self::registry();
            if ( ! is_object( $registry ) || ! is_callable( [ $registry, 'get' ] ) ) { return self::error( 'The native UX Builder control registry could not be loaded.' ); }
            $walk = static function ( array &$items ) use ( &$walk, $registry ) {
                foreach ( $items as &$node ) {
                    if ( 'shortcode' !== $node['kind'] ) { continue; }
                    if ( self::external( $node['tag'] ) ) { throw new RuntimeException( 'This layout references a reusable UX Block. Insert its layout into the example page before capturing; publishing must not depend on another source document.' ); }
                    $definition = $registry->get( $node['tag'] ); $fields = []; $body = false;
                    if ( is_array( $definition ) ) { foreach ( self::options( $definition['options'] ?? [] ) as $key => $option ) { if ( '$content' === $key && in_array( $option['type'] ?? '', [ 'text-editor', 'textarea' ], true ) ) { $body = true; } $field = self::semantic( (string) $key, $option ); if ( $field ) { $fields[$key] = $field; } } }
                    $node['definition'] = [ 'name' => is_array( $definition ) ? strip_tags( (string) ( $definition['name'] ?? $node['tag'] ) ) : $node['tag'], 'container' => is_array( $definition ) && 'container' === ( $definition['type'] ?? '' ), 'protected' => ! is_array( $definition ) || self::protected_tag( $node['tag'] ), 'fields' => $fields, 'body' => $body ];
                    $walk( $node['children'] );
                    if ( $body && '' !== $node['raw_close'] ) { $node['content'] = Nova_Bridge_Suite_Flatsome_Document::serialize( $node['children'] ); $node['children'] = []; }
                }
                unset( $node );
            };
            $walk( $nodes );
            if ( Nova_Bridge_Suite_Flatsome_Document::serialize( $nodes ) !== $content ) { return self::error( 'The complete UX Builder source could not be captured without changing its formatting.' ); }
            return $nodes;
        } );
    }
    private static function safe_meta_key( string $key ): bool {
        return 1 === preg_match( '/^[_a-zA-Z][_a-zA-Z0-9-]{0,127}$/D', $key ) && ! preg_match( '/(?:password|secret|token|credential|api[_-]?key|_nova_|_edit_|_uxb_|_ux_builder_data|_thumbnail_id|_wp_page_template|post_content|post_title|post_status|post_author|post_parent|post_name)/i', $key );
    }
    private static function meta_capture( $post ): array {
        $options = self::options( (array) apply_filters( 'ux_builder_meta_options', [], $post ) ); $meta = [];
        foreach ( $options as $key => $option ) {
            if ( ! self::safe_meta_key( (string) $key ) || in_array( $option['type'] ?? '', [ 'image', 'gallery', 'file', 'text-editor', 'textarea' ], true ) ) { continue; }
            $present = metadata_exists( 'post', $post->ID, $key ); $value = $present ? get_post_meta( $post->ID, $key, true ) : '';
            if ( ! is_scalar( $value ) && null !== $value ) { throw new RuntimeException( 'A UX Builder page setting is not a supported scalar presentation value: ' . $key ); }
            $meta[$key] = [ 'present' => $present, 'value' => (string) $value ];
        }
        return $meta;
    }
    public function capture( int $post_id ) {
        return self::guard( function () use ( $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) { return self::error( 'An editable native example is required to capture its UX Builder layout.', 403 ); }
            $target = $this->check_target( $post ); if ( is_wp_error( $target ) ) { return $target; }
            $document = $this->capture_document( (string) $post->post_content ); if ( is_wp_error( $document ) ) { return $document; }
            $blueprint = [ 'document' => $document, 'template' => (string) get_page_template_slug( $post_id ), 'meta' => self::meta_capture( $post ) ];
            return $this->envelope( $blueprint );
        } );
    }
    public function starter( string $mode = 'mixed' ) {
        $document = $this->capture_document( '[section][row][col span="12"]<h1>Example heading</h1><p>Example service content.</p>[/col][/row][/section]' );
        return is_wp_error( $document ) ? $document : $this->envelope( [ 'document' => $document, 'template' => '', 'meta' => [] ] );
    }
    private function envelope( array $blueprint ): array { return [ 'builder' => 'flatsome', 'provider' => 'flatsome', 'version' => defined( 'UX_BUILDER_VERSION' ) ? (string) UX_BUILDER_VERSION : '', 'blueprint' => $blueprint, 'capabilities' => $this->metadata(), 'warnings' => [] ] + $this->inventory( $blueprint ); }
    public function describe( array $blueprint ) { $valid = $this->validate_blueprint( $blueprint ); return is_wp_error( $valid ) ? $valid : $this->inventory( $blueprint ); }
    public function fields( array $native ): array { return $this->inventory( $native )['fields']; }
    public function containers( array $native ): array { return $this->inventory( $native )['containers']; }
    public function validate_blueprint( array $blueprint ) {
        return self::guard( static function () use ( $blueprint ) {
            if ( array_diff( array_keys( $blueprint ), [ 'document', 'template', 'meta' ] ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $blueprint['document'] ?? null ) || isset( $blueprint['template'] ) && ! is_string( $blueprint['template'] ) || ! is_array( $blueprint['meta'] ?? [] ) ) { return self::error( 'Use a complete frozen Flatsome document and page presentation.' ); }
            foreach ( $blueprint['meta'] ?? [] as $key => $value ) { if ( ! self::safe_meta_key( (string) $key ) || ! is_array( $value ) || ! is_bool( $value['present'] ?? null ) || ! is_string( $value['value'] ?? null ) || strlen( $value['value'] ) > 8192 ) { return self::error( 'The frozen UX Builder page setting is invalid.' ); } }
            $count = 0; $walk = static function ( array $nodes, int $depth ) use ( &$walk, &$count ) {
                if ( $depth > 32 || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $nodes ) ) { throw new RuntimeException( 'The UX Builder child structure is invalid or too deep.' ); }
                foreach ( $nodes as $node ) {
                    if ( ! is_array( $node ) || ++$count > 4000 ) { throw new RuntimeException( 'The UX Builder document exceeds its native node limit.' ); }
                    $kind = $node['kind'] ?? '';
                    if ( 'shortcode' === $kind ) {
                        if ( ! is_string( $node['tag'] ?? null ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $node['tag'] ) || self::external( $node['tag'] ) || ! is_array( $node['attributes'] ?? null ) || ! is_array( $node['original_attributes'] ?? null ) || ! is_array( $node['definition'] ?? null ) || ! is_array( $node['children'] ?? null ) || ! is_string( $node['raw_open'] ?? null ) || ! is_string( $node['raw_close'] ?? null ) ) { throw new RuntimeException( 'The frozen UX Builder shortcode is incomplete or retains a reusable block reference.' ); }
                        if ( ! is_string( $node['definition']['name'] ?? null ) || ! is_bool( $node['definition']['container'] ?? null ) || ! is_bool( $node['definition']['protected'] ?? null ) || ! is_array( $node['definition']['fields'] ?? null ) || ( isset( $node['content'] ) && ( ! is_string( $node['content'] ) || $node['children'] ) ) ) { throw new RuntimeException( 'The frozen UX Builder control definitions are incomplete.' ); }
                        foreach ( $node['definition']['fields'] as $name => $field ) { if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name ) || ! is_array( $field ) || ! in_array( $field['format'] ?? '', [ 'text', 'html', 'url' ], true ) || ! is_string( $field['kind'] ?? null ) || ! is_string( $field['label'] ?? null ) || ! is_bool( $field['image'] ?? null ) ) { throw new RuntimeException( 'The frozen UX Builder field definition is invalid.' ); } }
                        foreach ( $node['attributes'] as $key => $value ) { if ( ! is_string( $value ) && ! is_numeric( $value ) ) { throw new RuntimeException( 'UX Builder attributes must remain native scalar strings.' ); } if ( is_string( $key ) && preg_match( '/(?:password|secret|token|credential|api[_-]?key)/i', $key ) && '' !== (string) $value ) { throw new RuntimeException( 'The UX Builder document contains a credential-like setting and cannot be exported.' ); } }
                        $walk( $node['children'], $depth + 1 );
                    } elseif ( in_array( $kind, [ 'raw', 'html' ], true ) ) {
                        if ( ! is_string( $node['content'] ?? null ) || 'html' === $kind && ( ! is_string( $node['raw_open'] ?? null ) || ! is_string( $node['raw_close'] ?? null ) || ! is_string( $node['tag'] ?? null ) ) ) { throw new RuntimeException( 'The frozen UX Builder text fragment is incomplete.' ); }
                    } else { throw new RuntimeException( 'The frozen UX Builder node type is unknown.' ); }
                }
            };
            $walk( $blueprint['document'], 0 );
            if ( strlen( Nova_Bridge_Suite_Recipe_Engine::json( $blueprint ) ) > 2097152 ) { return self::error( 'The complete UX Builder blueprint exceeds two megabytes.' ); }
            return $blueprint;
        } );
    }
    private function inventory( array $native ): array {
        $result = [ 'fields' => [], 'containers' => [] ]; $nodes = $native['document'] ?? $native; $prefix = isset( $native['document'] ) ? '/document' : '';
        $walk = static function ( array $items, string $path, bool $protected, string $context ) use ( &$walk, &$result ) {
            $entries = [];
            foreach ( $items as $i => $node ) {
                $p = $path . '/' . $i; $tag = $node['tag'] ?? 'text'; $label = $node['definition']['name'] ?? ( preg_match( '/^h[1-6]$/', $tag ) ? 'Heading' : 'Text' );
                $blocked = $protected || ! empty( $node['definition']['protected'] );
                $native_context = trim( $context . ' > ' . $label . ' ' . ( $i + 1 ), ' >' );
                $add = static function ( string $target, string $format, string $kind, string $label, string $value, bool $writable, array $extra = [] ) use ( &$result, $p, $tag, $native_context ) {
                    $result['fields'][] = [ 'target' => $target, 'label' => $label, 'format' => $format, 'kind' => $kind, 'value' => $value, 'sample' => $value, 'writable' => $writable, 'protected' => ! $writable, 'reason' => $writable ? '' : 'This is a retained native interactive or dynamic fragment.', 'context' => $native_context, 'node_target' => $p, 'element_type' => $tag, 'native_node_id' => '', 'control_path' => Nova_Bridge_Suite_Recipe_Engine::parts( substr( $target, strlen( $p ) ) ), 'locator' => [ 'strategy' => 'none', 'native_node_id' => '', 'anchor' => '', 'precision' => 'element', 'status' => 'unavailable', 'reason' => 'Use the named native field in the list.' ] ] + $extra;
                };
                if ( 'shortcode' === $node['kind'] ) {
                    foreach ( $node['definition']['fields'] ?? [] as $key => $field ) {
                        $image = ! empty( $field['image'] );
                        if ( ! $image && ! array_key_exists( $key, $node['attributes'] ) ) { continue; }
                        $value = (string) ( $node['attributes'][$key] ?? '' ); $extra = [];
                        if ( $image ) {
                            $extra['native_media'] = [ 'kind' => 'flatsome', 'id_target' => $p . '/attributes/' . $key ];
                            $value = ctype_digit( $value ) && function_exists( 'wp_get_attachment_url' ) ? (string) ( wp_get_attachment_url( (int) $value ) ?: '' ) : ( preg_match( '#^https?://#i', $value ) ? $value : '' );
                        } else { $value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); }
                        $add( $p . '/attributes/' . $key, $field['format'], $field['kind'], $label . ' · ' . $field['label'], $value, ! $blocked, $extra );
                    }
                    if ( isset( $node['content'] ) && ! empty( $node['definition']['body'] ) ) {
                        $value = $node['content']; $heading = self::heading( $value ); $safe = ! $blocked && ! preg_match( '/<(?:script|style|iframe|form)\b|\[(?:[a-zA-Z_]|\[)/i', $value );
                        $add( $p . '/content', $heading ? 'text' : 'html', $heading ? 'heading' : 'body', $label . ' · Content', $heading ? html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value, $safe );
                    }
                    $walk( $node['children'], $p . '/children', $blocked, $native_context );
                } else {
                    $value = $node['content']; $plain = 'raw' === $node['kind'];
                    if ( '' !== trim( strip_tags( $value ) ) && ( ! $plain || ! preg_match( '/[<>\[\]]/', $value ) ) ) {
                        $heading = 'html' === $node['kind'] && preg_match( '/^h[1-6]$/', $tag );
                        $safe = ! $blocked && ! preg_match( '/<(?:script|style|iframe|form)\b|\[(?:[a-zA-Z_]|\[)/i', $value );
                        $add( $p . '/content', $heading ? 'text' : 'html', $heading ? 'heading' : 'body', $label, $heading ? html_entity_decode( strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $value, $safe );
                    }
                }
                $entries[] = [ 'index' => $i, 'label' => $label . ' ' . ( $i + 1 ), 'native_type' => $tag ];
            }
            if ( $entries && ! $protected ) { $result['containers'][] = [ 'target' => $path, 'label' => $context ?: 'UX Builder layout', 'items' => $entries ]; }
        };
        $walk( $nodes, $prefix, false, '' ); return $result;
    }
    public function validate_binding( array $native, array $binding ) {
        foreach ( $this->fields( $native ) as $field ) { if ( $field['target'] === ( $binding['target'] ?? '' ) && $field['writable'] && $field['format'] === ( $binding['format'] ?? '' ) ) { return true; } }
        return self::error( 'Choose an exposed UX Builder editorial field with its declared format.' );
    }
    public function validate_region( array $native, array $region ) { foreach ( $this->containers( $native ) as $container ) { if ( $container['target'] === ( $region['target'] ?? null ) ) { return true; } } return self::error( 'Choose an exposed UX Builder child collection.' ); }
    public function instantiate( array $prototype_native, string $seed, array $context = [] ) { return [ 'native' => $prototype_native, 'context' => $context ]; }
    private static function heading( string $value ): bool { return 1 === preg_match( '~^\s*<h([1-6])\b[^>]*>[\s\S]*?</h\1>\s*$~i', $value ) && 1 === preg_match_all( '~<h[1-6]\b~i', $value ); }
    /** Retain design attributes on corresponding text blocks without wrapping new block-level content. */
    private static function body_styles( string $original, string $value ): string {
        preg_match_all( '~<(p|h[1-6]|ul|ol|table|blockquote|pre|figure)\b([^>]*)>~i', $original, $matches, PREG_SET_ORDER ); $styles = [];
        foreach ( $matches as $match ) { $styles[strtolower( $match[1] )][] = $match[2]; }
        $indices = [];
        return preg_replace_callback( '~<(p|h[1-6]|ul|ol|table|blockquote|pre|figure)\b[^>]*>~i', static function ( $match ) use ( $styles, &$indices ) { $tag = strtolower( $match[1] ); $index = $indices[$tag] ?? 0; $indices[$tag] = $index + 1; return array_key_exists( $index, $styles[$tag] ?? [] ) ? '<' . $match[1] . $styles[$tag][$index] . '>' : $match[0]; }, $value );
    }
    public function bind( array $native, array $binding, $value, array $context = [] ) {
        $checked = $this->validate_binding( $native, $binding ); if ( is_wp_error( $checked ) ) { return $checked; }
        if ( str_ends_with( $binding['target'], '/content' ) ) {
            $node = Nova_Bridge_Suite_Recipe_Engine::get( $native, substr( $binding['target'], 0, -8 ) );
            if ( 'text' === $binding['format'] ) {
                $value = esc_html( (string) $value );
                if ( 'shortcode' === $node['kind'] && self::heading( $node['content'] ) && preg_match( '~^(\s*<h[1-6]\b[^>]*>)[\s\S]*?(</h[1-6]>\s*)$~i', $node['content'], $match ) ) { $value = $match[1] . $value . $match[2]; }
            } else { $value = self::body_styles( $node['content'], (string) $value ); }
        }
        Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); return $native;
    }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) { $valid = $this->validate_region( $native, $region ); if ( is_wp_error( $valid ) ) { return $valid; } Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native; }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $valid = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $valid ) ) { return $valid; }
        $preview = ''; foreach ( $this->fields( $blueprint ) as $field ) { if ( ! $field['writable'] || 'url' === $field['format'] ) { continue; } $preview .= 'html' === $field['format'] ? wp_kses_post( $field['value'] ) : '<p>' . esc_html( $field['value'] ) . '</p>'; }
        return [ 'native_document' => Nova_Bridge_Suite_Flatsome_Document::serialize( $blueprint['document'] ), 'writer_payload' => [ 'flatsome_document' => $blueprint['document'], 'template' => $blueprint['template'] ?? '', 'meta' => $blueprint['meta'] ?? [] ], 'preview_html' => $preview, 'warnings' => [], 'summary' => [ 'builder' => 'flatsome', 'fields' => count( $this->fields( $blueprint ) ) ] ];
    }
    public function publication_support( string $type ): array {
        $object = get_post_type_object( $type ); $available = self::available() && $object && current_user_can( $object->cap->edit_posts ) && current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts );
        return [ 'supported' => (bool) $available, 'reason' => $available ? '' : 'Load Flatsome / UX Builder and allow the configured actor to create and edit this native post type.' ];
    }
    private function payload( array $payload ) { return $this->validate_blueprint( [ 'document' => $payload['flatsome_document'] ?? null, 'template' => $payload['template'] ?? '', 'meta' => $payload['meta'] ?? [] ] ); }
    public function publication_probe( string $type, array $payload, string $status ) {
        $support = $this->publication_support( $type ); if ( ! $support['supported'] ) { return self::error( $support['reason'] ); }
        $valid = $this->payload( $payload ); if ( is_wp_error( $valid ) ) { return $valid; }
        return [ 'wordpress' => (string) ( $GLOBALS['wp_version'] ?? '' ), 'provider_version' => 'flatsome-shortcodes-v1', 'flatsome' => (string) UX_BUILDER_VERSION ];
    }
    private function check_target( $post ) {
        if ( is_callable( [ 'Nova_Bridge_Suite_Recipe_Builders', 'native_carriers' ] ) ) {
            $carriers = Nova_Bridge_Suite_Recipe_Builders::native_carriers( $post );
            if ( array_diff( $carriers, [ 'flatsome', 'gutenberg' ] ) ) { return self::error( 'This item uses another native builder. Select its matching layout instead.' ); }
        }
        return true;
    }
    public function publication_snapshot( int $id, array $payload ) {
        $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained UX Builder page cannot be inspected.', 403 ); }
        $target = $this->check_target( $post ); if ( is_wp_error( $target ) ) { return $target; }
        $meta = []; foreach ( $payload['meta'] ?? [] as $key => $_ ) { $meta[$key] = [ 'present' => metadata_exists( 'post', $id, $key ), 'value' => (string) get_post_meta( $id, $key, true ) ]; }
        return [ 'content' => (string) $post->post_content, 'template' => (string) get_page_template_slug( $id ), 'meta' => $meta ];
    }
    private function post_data( int $id, array $payload, array $fields ): array {
        $meta = []; foreach ( $payload['meta'] ?? [] as $key => $snapshot ) { if ( $snapshot['present'] ) { $meta[$key] = $snapshot['value']; } }
        return [ 'ID' => $id, 'post_content' => Nova_Bridge_Suite_Flatsome_Document::serialize( $payload['flatsome_document'] ), 'post_title' => (string) ( $fields['title'] ?? '' ), 'post_excerpt' => (string) ( $fields['excerpt'] ?? '' ), 'page_template' => '' === ( $payload['template'] ?? '' ) ? 'default' : $payload['template'], 'meta_input' => $meta ];
    }
    public function publication_apply( int $id, array $payload, array $fields ) {
        $post = get_post( $id ); if ( ! $post || ! current_user_can( 'edit_post', $id ) ) { return self::error( 'The retained UX Builder item is not editable.', 403 ); }
        $checked = $this->publication_probe( $post->post_type, $payload, $post->post_status ); if ( is_wp_error( $checked ) ) { return $checked; }
        $target = $this->check_target( $post ); if ( is_wp_error( $target ) ) { return $target; }
        $data = $this->post_data( $id, $payload, $fields ); $data = apply_filters( 'ux_builder_save_post', $data );
        if ( ! is_array( $data ) || ( $data['ID'] ?? null ) !== $id ) { return self::error( 'The native UX Builder save filter changed the retained target.' ); }
        $saved = wp_update_post( wp_slash( $data ), true ); if ( is_wp_error( $saved ) ) { return $saved; }
        if ( (int) $saved !== $id ) { return self::error( 'WordPress did not return the retained UX Builder item ID.' ); }
        foreach ( $payload['meta'] ?? [] as $key => $snapshot ) { if ( ! $snapshot['present'] ) { delete_post_meta( $id, $key ); } }
        clean_post_cache( $id ); return $this->publication_verify( $id, $payload );
    }
    public function publication_verify( int $id, array $payload ) {
        $valid = $this->payload( $payload ); if ( is_wp_error( $valid ) ) { return $valid; }
        $state = $this->publication_snapshot( $id, $payload ); if ( is_wp_error( $state ) ) { return $state; }
        $expected = [ 'content' => Nova_Bridge_Suite_Flatsome_Document::serialize( $payload['flatsome_document'] ), 'template' => $payload['template'] ?? '', 'meta' => $payload['meta'] ?? [] ];
        return $state === $expected ? true : self::error( 'The saved UX Builder document, page template or native presentation differs from the compiled layout. Retain this same item for recovery.' );
    }
    public function verify_saved( int $id, array $payload ) { return $this->publication_verify( $id, $payload ); }
    public function write_draft( array $payload, array $fields ) {
        $type = $fields['post_type'] ?? 'page'; $probe = $this->publication_probe( $type, $payload, 'draft' ); if ( is_wp_error( $probe ) ) { return $probe; }
        $data = $this->post_data( 0, $payload, $fields ); unset( $data['ID'] ); $data['post_type'] = $type; $data['post_status'] = 'draft'; $data['post_author'] = get_current_user_id();
        if ( isset( $fields['slug'] ) ) { $data['post_name'] = $fields['slug']; } if ( isset( $fields['parent_id'] ) ) { $data['post_parent'] = (int) $fields['parent_id']; }
        $id = wp_insert_post( wp_slash( $data ), true ); if ( is_wp_error( $id ) ) { return $id; }
        $verified = $this->verify_saved( (int) $id, $payload ); if ( is_wp_error( $verified ) ) { return new WP_Error( 'nova_recipe_flatsome', $verified->get_error_message(), [ 'status' => 409, 'post_id' => (int) $id ] ); }
        return [ 'post_id' => (int) $id, 'status' => 'draft', 'builder' => 'flatsome', 'edit_url' => get_edit_post_link( $id, 'raw' ), 'preview_url' => get_preview_post_link( $id ) ];
    }
}
