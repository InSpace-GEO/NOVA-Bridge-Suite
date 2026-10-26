<?php
/** Frozen native layouts for V3 creation. Only capture() reads an example page. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Layout {
    public const FORMAT = 'nova-stored-layout/v1';

    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_stored_layout', $message, [ 'status' => 422 ] ); }
    private static function checked( $value ) { if ( is_wp_error( $value ) ) { throw new InvalidArgumentException( $value->get_error_message() ); } return $value; }
    private static function guard( callable $work ) {
        try {
            require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-engine.php';
            require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-elementor.php';
            return $work();
        } catch ( InvalidArgumentException $error ) { return self::error( $error->getMessage() ); }
        catch ( Throwable $error ) { return self::error( 'The complete saved Elementor layout could not be prepared. No page was created.' ); }
    }
    private static function fail( string $message ): void { throw new InvalidArgumentException( $message ); }
    private static function pointer( array $parts ): string { return '/' . implode( '/', array_map( static function ( $part ) { return str_replace( [ '~', '/' ], [ '~0', '~1' ], (string) $part ); }, $parts ) ); }

    /** Save a complete example once; later source changes/deletion cannot affect this snapshot. */
    public static function capture( array $draft ) {
        return self::guard( static function () use ( $draft ) {
            if ( ( $draft['reference_type'] ?? '' ) !== 'post' || empty( $draft['reference_id'] ) ) { self::fail( 'Choose an editable Elementor example page before saving a creation mapping.' ); }
            $id = (int) $draft['reference_id']; $post = get_post( $id );
            if ( ! $post || 'page' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) { self::fail( 'Stored-layout creation currently supports editable Elementor pages.' ); }
            $adapter = new Nova_Bridge_Suite_Recipe_Elementor();
            $captured = self::checked( $adapter->capture( $id ) );
            return self::seal( $draft, $captured['blueprint'], [ 'title' => (string) $post->post_title, 'excerpt' => (string) $post->post_excerpt ] );
        } );
    }

    private static function independent( array $blueprint ): void {
        $scan = static function ( $value, string $key = '' ) use ( &$scan ) {
            if ( '__dynamic__' === $key && ! empty( $value ) ) { self::fail( 'Stored-layout creation cannot retain dynamic tags. Resolve those controls to static values before saving.' ); }
            if ( is_array( $value ) ) {
                if ( in_array( $value['widgetType'] ?? '', [ 'global', 'template', 'shortcode' ], true ) || ! empty( $value['templateID'] ) ) { self::fail( 'Inline global/template widgets and shortcodes before saving; a creation mapping must contain its complete native layout.' ); }
                foreach ( $value as $name => $child ) { $scan( $child, (string) $name ); }
            } elseif ( is_string( $value ) && 'custom_css' === $key && preg_match( '/(?:\.elementor-(?:page-)?[0-9]+\b|\[data-elementor-id\s*=)/', $value ) ) { self::fail( 'Custom CSS targets a specific page ID. Use Elementor selector or widget selectors before saving an independent creation layout.' ); }
            elseif ( is_string( $value ) && ( preg_match( '/\[(?:elementor-template|elementor-tag)\b/i', $value ) || ( in_array( $key, [ 'template_id', 'loop_template_id' ], true ) && '' !== $value && '0' !== $value ) ) ) { self::fail( 'The layout still references an external native template or dynamic tag. Inline it before saving.' ); }
            elseif ( in_array( $key, [ 'template_id', 'loop_template_id' ], true ) && ! empty( $value ) ) { self::fail( 'The layout still references an external native template. Inline it before saving.' ); }
        };
        $scan( $blueprint );
    }

    private static function nodes( array $nodes, array $prefix, array &$out ): void {
        foreach ( $nodes as $index => $node ) {
            $parts = array_merge( $prefix, [ $index ] );
            $out[ $node['id'] ] = [ 'node' => $node, 'parts' => $parts ];
            self::nodes( $node['elements'] ?? [], array_merge( $parts, [ 'elements' ] ), $out );
        }
    }

    private static function bindings( array $draft, array $blueprint ): array {
        $draft = Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $draft );
        $nodes = []; self::nodes( $blueprint['document'], [ 'document' ], $nodes );
        $adapter = new Nova_Bridge_Suite_Recipe_Elementor(); $result = []; $occupied = [];
        foreach ( $draft['fields'] ?? [] as $path => $field ) {
            $descriptor = $draft['target_descriptors'][ $path ] ?? [];
            $mode = $field['mode'] ?? '';
            if ( ( $descriptor['path'] ?? null ) !== $path || ! in_array( $mode, [ 'adapt', 'protected', 'leave_empty' ], true ) ) { self::fail( 'Every creation field needs its retained descriptor and a supported scalar policy.' ); }
            if ( in_array( $path, [ '/title', '/excerpt' ], true ) ) {
                $target = [ 'kind' => 'post', 'column' => substr( $path, 1 ), 'mode' => $mode ];
            } elseif ( '/slug' === $path && 'adapt' !== $mode ) {
                // New-page URL authority always comes from the authenticated delivery.
                $target = [ 'kind' => 'routing', 'mode' => $mode ];
            } else {
                if ( 'elementor' !== ( $descriptor['builder'] ?? '' ) || ! empty( $descriptor['acf_key'] ) ) { self::fail( 'Stored-layout creation supports Elementor fields and native title/excerpt. ACF, post-content and other metadata need a dedicated creation adapter.' ); }
                $selector = $descriptor['selector_data'] ?? []; $id = $selector['element_id'] ?? ''; $setting = $selector['path'] ?? null;
                if ( is_string( $selector['field_key'] ?? null ) && 1 === substr_count( $selector['field_key'], '|' ) ) { list( $id, $setting_path ) = explode( '|', $selector['field_key'], 2 ); $setting = explode( '.', $setting_path ); }
                if ( ! is_string( $id ) || ! isset( $nodes[ $id ] ) || ! is_array( $setting ) || ! $setting ) { self::fail( 'A saved Elementor field must resolve to one exact widget and setting path.' ); }
                foreach ( $setting as $part ) { if ( ! is_string( $part ) && ! is_int( $part ) ) { self::fail( 'A saved Elementor setting path is invalid.' ); } }
                $pointer = self::pointer( array_merge( $nodes[ $id ]['parts'], [ 'settings' ], $setting ) );
                $current = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $pointer, $found );
                if ( ! $found || ! is_string( $current ) ) { self::fail( 'Only existing scalar Elementor fields can receive fitted creation content.' ); }
                $type = $field['description']['value_type'] ?? ( in_array( $descriptor['format'] ?? '', [ 'html', 'rich_text' ], true ) ? 'rich_text' : 'text' );
                $format = 'rich_text' === $type ? 'html' : ( 'url' === $type ? 'url' : 'text' );
                if ( 'protected' !== $mode ) { self::checked( $adapter->validate_binding( $blueprint, [ 'target' => $pointer, 'format' => $format ] ) ); }
                $target = [ 'kind' => 'elementor', 'target' => $pointer, 'format' => $format, 'mode' => $mode ];
            }
            $identity = $target['target'] ?? ( $target['column'] ?? 'routing' );
            if ( isset( $occupied[ $identity ] ) ) { self::fail( 'Two creation fields address the same native target. Keep one mapping for each field.' ); }
            $occupied[ $identity ] = true; $result[ $path ] = $target;
        }
        return $result;
    }

    private static function seal( array $draft, array $blueprint, array $post_fields ): array {
        $adapter = new Nova_Bridge_Suite_Recipe_Elementor();
        self::checked( $adapter->validate_blueprint( $blueprint ) ); self::independent( $blueprint );
        $layout = [ 'format' => self::FORMAT, 'builder' => 'elementor', 'post_type' => 'page', 'blueprint' => $blueprint, 'post_fields' => $post_fields, 'bindings' => self::bindings( $draft, $blueprint ) ];
        $layout['digest'] = Nova_Bridge_Suite_Recipe_Engine::digest( $layout );
        $bounded = $draft; $bounded['stored_layout'] = $layout;
        if ( strlen( (string) wp_json_encode( $bounded ) ) > 524288 ) { self::fail( 'The saved creation mapping exceeds 512 KiB. Reduce the captured layout before synchronizing; delivery journals must retain both the layout and fitted page.' ); }
        return $layout;
    }

    /** Pure validation: no current example, native reader or source fingerprint is consulted. */
    public static function validate( array $draft ) {
        return self::guard( static function () use ( $draft ) {
            $layout = $draft['stored_layout'] ?? null;
            if ( ! is_array( $layout ) || ( $layout['format'] ?? '' ) !== self::FORMAT || ( $layout['builder'] ?? '' ) !== 'elementor' || ( $layout['post_type'] ?? '' ) !== 'page' || ! is_array( $layout['blueprint'] ?? null ) || ! is_array( $layout['post_fields'] ?? null ) || ! is_array( $layout['bindings'] ?? null ) || ! is_string( $layout['digest'] ?? null ) ) { self::fail( 'Save this mapping again to retain its complete Elementor creation layout.' ); }
            $unsigned = $layout; unset( $unsigned['digest'] );
            if ( ! hash_equals( Nova_Bridge_Suite_Recipe_Engine::digest( $unsigned ), $layout['digest'] ) ) { self::fail( 'The frozen creation layout changed after it was saved. Re-save and synchronize a new mapping revision.' ); }
            $sealed = self::seal( $draft, $layout['blueprint'], $layout['post_fields'] );
            if ( ! hash_equals( $sealed['digest'], $layout['digest'] ) ) { self::fail( 'The creation targets or policies differ from the frozen layout revision.' ); }
            return $layout;
        } );
    }

    /** Fill frozen destinations and rebase native identities, without reading the source page. */
    public static function compile( array $draft, array $destination_values, string $seed ) {
        return self::guard( static function () use ( $draft, $destination_values, $seed ) {
            if ( '' === $seed ) { self::fail( 'A retained operation identity is required for fresh native element IDs.' ); }
            $layout = self::checked( self::validate( $draft ) ); $effective = Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $draft );
            $fitted = self::checked( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $effective, $destination_values ) );
            $blueprint = $layout['blueprint']; $post_fields = $layout['post_fields']; $adapter = new Nova_Bridge_Suite_Recipe_Elementor();
            foreach ( $layout['bindings'] as $path => $binding ) {
                if ( 'protected' === $binding['mode'] || 'routing' === $binding['kind'] ) { continue; }
                // New pages must not leak example copy into generated but unfilled slots.
                $value = 'leave_empty' === $binding['mode'] ? '' : ( $fitted[ $path ] ?? '' );
                $rich = 'rich_text' === ( $effective['fields'][ $path ]['description']['value_type'] ?? '' );
                if ( $rich ? ( ! function_exists( 'wp_kses_post' ) || wp_kses_post( $value ) !== $value ) : preg_match( '/<[^>]*>/', $value ) ) { self::fail( 'Fitted content does not satisfy the saved text field or WordPress HTML policy.' ); }
                if ( 'post' === $binding['kind'] ) { $post_fields[ $binding['column'] ] = $value; }
                else { $blueprint = self::checked( $adapter->bind( $blueprint, $binding, $value ) ); }
            }
            $compiled = self::checked( $adapter->finalize( $blueprint, 'stored-layout:' . $seed, [ 'recipe' => [ 'blueprint' => $layout['blueprint'] ] ] ) );
            return [ 'builder' => 'elementor', 'post_type' => 'page', 'writer_payload' => $compiled['writer_payload'], 'post_fields' => $post_fields, 'layout_digest' => $layout['digest'] ];
        } );
    }

    /** Project the saved visual recipe into V3 destinations; native structure stays local. */
    public static function from_recipe( array $record ) {
        return self::guard( static function () use ( $record ) {
            $recipe = $record['recipe'] ?? [];
            if ( ( $record['builder'] ?? '' ) !== 'elementor' || ( $record['resource_id'] ?? '' ) !== 'post_type:page' || ! is_array( $recipe['blueprint'] ?? null ) || ! is_array( $recipe['bindings'] ?? null ) || ! empty( $recipe['regions'] ) || ! empty( $recipe['custom_fields'] ) ) { self::fail( 'V3 stored-layout creation supports Elementor pages with fixed scalar fields. Generated regions and ACF fields need a dedicated creation adapter.' ); }
            $adapter = new Nova_Bridge_Suite_Recipe_Elementor(); $design = self::checked( $adapter->describe( $recipe['blueprint'] ) );
            $inventory = array_column( $design['fields'], null, 'target' );
            $draft = [ 'schema_version' => 1, 'revision' => (string) ( $record['id'] ?? '' ) . ':' . (string) ( $record['revision'] ?? '' ), 'reference_type' => 'post', 'reference_id' => 0, 'signature' => Nova_Bridge_Suite_Recipe_Engine::digest( $recipe['blueprint'] ), 'catalog_mode' => 'destination', 'template' => [ 'id' => 'local-destination', 'revision' => '1' ], 'profile_page_type' => $record['posting_page_type'] ?? 'service', 'label' => $record['label'] ?? 'Elementor page', 'guidance_mode' => empty( $recipe['instructions'] ) ? 'clear' : 'set', 'guidance' => $recipe['instructions'] ?? '', 'routing' => [ 'operation' => 'create', 'publication' => 'publish', 'post_type' => 'page', 'locale' => '' ], 'fields' => [], 'target_descriptors' => [], 'repeat_slots' => [], 'destination_groups' => [] ];
            $draft['fields']['/title'] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => true, 'instructions' => '', 'description' => [ 'label' => 'Page title', 'purpose' => 'The native page title matching the supplied content heading.', 'value_type' => 'text', 'constraints' => [] ] ];
            $draft['target_descriptors']['/title'] = [ 'path' => '/title' ];
            foreach ( $recipe['bindings'] as $binding ) {
                $path = $binding['target'] ?? ''; $field = $inventory[ $path ] ?? null;
                if ( ! is_array( $field ) || empty( $field['writable'] ) || ! in_array( $binding['format'] ?? '', [ 'text', 'html', 'url' ], true ) || isset( $draft['fields'][ $path ] ) ) { self::fail( 'Every visual creation binding must identify one distinct supported scalar Elementor field.' ); }
                self::checked( $adapter->validate_binding( $recipe['blueprint'], $binding ) );
                $type = 'html' === $binding['format'] ? 'rich_text' : ( 'url' === $binding['format'] ? 'url' : 'text' );
                $label = isset( $binding['label'] ) && '' !== trim( $binding['label'] ) ? $binding['label'] : $field['label'];
                $draft['fields'][ $path ] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => ! empty( $binding['required'] ), 'instructions' => $binding['instructions'] ?? '', 'description' => [ 'label' => $label, 'purpose' => 'Content for ' . $label . ( empty( $binding['source'] ) ? '.' : ', described by ' . $binding['source'] . '.' ), 'value_type' => $type, 'constraints' => [] ] ];
                $draft['target_descriptors'][ $path ] = [ 'path' => $path, 'builder' => 'elementor', 'selector_data' => [ 'element_id' => $field['native_node_id'], 'path' => array_slice( $field['control_path'], 1 ) ] ];
            }
            $draft['stored_layout'] = self::seal( $draft, $recipe['blueprint'], [ 'title' => '', 'excerpt' => '' ] );
            return $draft;
        } );
    }
}
