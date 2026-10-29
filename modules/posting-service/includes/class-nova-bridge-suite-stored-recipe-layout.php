<?php
/** Builder-independent projection and compilation of saved scalar layout recipes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Recipe_Layout {
    public const FORMAT = 'nova.stored-recipe/v1';
    private static function fail( string $message ): void { throw new InvalidArgumentException( $message ); }
    private static function checked( $value ) { if ( is_wp_error( $value ) ) { self::fail( $value->get_error_message() ); } return $value; }
    private static function guard( callable $work ) {
        try { self::load(); return $work(); }
        catch ( InvalidArgumentException $error ) { return new WP_Error( 'nova_stored_layout', $error->getMessage(), [ 'status' => 422 ] ); }
        catch ( Throwable $error ) { return new WP_Error( 'nova_stored_layout', 'The saved native layout could not be prepared. No content was created.', [ 'status' => 422 ] ); }
    }
    private static function load(): void {
        $directory = dirname( __DIR__, 2 ) . '/api-mapping-context/includes/';
        require_once $directory . 'class-nova-bridge-suite-recipe-engine.php';
        require_once $directory . 'class-nova-bridge-suite-recipe-elementor.php';
        require_once $directory . 'class-nova-bridge-suite-recipe-builders.php';
        require_once $directory . 'class-nova-bridge-suite-recipe-core.php';
        // Registered extension adapters remain authoritative. Fill only missing built-ins.
        foreach ( array_merge( [ 'core' ], Nova_Bridge_Suite_Recipe_Builders::BUILDERS ) as $builder ) {
            if ( ! is_wp_error( Nova_Bridge_Suite_Recipe_Engine::adapter( $builder ) ) ) { continue; }
            $adapter = 'core' === $builder ? new Nova_Bridge_Suite_Recipe_Core() : Nova_Bridge_Suite_Recipe_Builders::adapter( $builder );
            if ( ! is_wp_error( $adapter ) ) { Nova_Bridge_Suite_Recipe_Engine::register_adapter( $builder, $adapter ); }
        }
    }
    private static function post_type( string $resource ): string {
        if ( ! preg_match( '/^post_type:([a-z0-9_-]{1,20})$/D', $resource, $match ) ) { self::fail( 'Choose a registered native post destination. Taxonomy content uses its taxonomy writer.' ); }
        return $match[1];
    }
    public static function publishing_support( string $resource, string $builder, string $mode ): array {
        try {
            self::load(); $type = self::post_type( $resource );
            if ( 'static' !== $mode ) { return [ 'supported' => false, 'reason' => 'Automatic posting currently needs fixed mapped fields. Repeated content regions remain local.' ]; }
            self::checked( Nova_Bridge_Suite_Recipe_Engine::adapter( $builder ) );
            require_once __DIR__ . '/class-nova-bridge-suite-stored-layout-provider.php';
            return Nova_Bridge_Suite_Stored_Layout_Provider::support( $builder, $type );
        } catch ( Throwable $error ) { return [ 'supported' => false, 'reason' => $error->getMessage() ]; }
    }
    private static function inventory( string $builder, array $blueprint ): array {
        $adapter = self::checked( Nova_Bridge_Suite_Recipe_Engine::adapter( $builder ) );
        self::checked( $adapter->validate_blueprint( $blueprint ) );
        Nova_Bridge_Suite_Stored_Layout::assert_independent( $builder, $blueprint );
        if ( ! is_callable( [ $adapter, 'describe' ] ) ) { self::fail( 'This builder needs an editorial-field description before a saved layout can be synchronized.' ); }
        $description = self::checked( $adapter->describe( $blueprint ) );
        return array_column( $description['fields'] ?? [], null, 'target' );
    }
    private static function seal( array $draft, string $builder, string $resource, array $blueprint, array $post_fields ): array {
        $type = self::post_type( $resource );
        if ( ( $draft['routing']['post_type'] ?? '' ) !== $type ) { self::fail( 'The saved destination differs from its approved native routing.' ); }
        if ( 'core' === $builder && ( ( $blueprint['type'] ?? '' ) !== 'post_type' || ( $blueprint['post_type'] ?? '' ) !== $type || ( $blueprint['resource_id'] ?? '' ) !== $resource ) ) { self::fail( 'The WordPress content blueprint belongs to a different native destination.' ); }
        $adapter = self::checked( Nova_Bridge_Suite_Recipe_Engine::adapter( $builder ) );
        $inventory = self::inventory( $builder, $blueprint ); $bindings = [];
        $effective = Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $draft );
        foreach ( $effective['fields'] ?? [] as $path => $field ) {
            $descriptor = $effective['target_descriptors'][$path] ?? []; $mode = $field['mode'] ?? '';
            if ( ( $descriptor['path'] ?? null ) !== $path || ! in_array( $mode, [ 'adapt', 'protected', 'leave_empty' ], true ) ) { self::fail( 'Each saved field needs its exact native descriptor and supported scalar policy.' ); }
            if ( in_array( $path, [ '/title', '/excerpt' ], true ) ) { $bindings[$path] = [ 'kind' => 'post', 'column' => substr( $path, 1 ), 'mode' => $mode ]; continue; }
            $native = $inventory[$path] ?? null;
            if ( ! is_array( $native ) || empty( $native['writable'] ) || ( $descriptor['builder'] ?? '' ) !== $builder ) { self::fail( 'A mapped value is not an exposed editorial field in the retained native layout.' ); }
            $value_type = $field['description']['value_type'] ?? 'text';
            $format = 'rich_text' === $value_type ? 'html' : ( 'url' === $value_type ? 'url' : 'text' );
            $binding = [ 'kind' => 'recipe', 'target' => $path, 'format' => $format, 'mode' => $mode ];
            self::checked( $adapter->validate_binding( $blueprint, $binding ) );
            if ( 'core' === $builder && in_array( $path, [ '/document/title', '/document/excerpt' ], true ) ) {
                $binding['kind'] = 'post'; $binding['column'] = substr( $path, strlen( '/document/' ) );
            }
            $bindings[$path] = $binding;
        }
        $layout = [ 'format' => self::FORMAT, 'builder' => $builder, 'resource_id' => $resource, 'post_type' => $type, 'blueprint' => $blueprint, 'post_fields' => $post_fields, 'bindings' => $bindings ];
        $layout['digest'] = Nova_Bridge_Suite_Recipe_Engine::digest( $layout );
        $bounded = $draft; $bounded['stored_layout'] = $layout;
        if ( strlen( (string) wp_json_encode( $bounded ) ) > 524288 ) { self::fail( 'The saved creation mapping exceeds 512 KiB. Reduce the captured layout before synchronizing.' ); }
        return $layout;
    }
    public static function from_recipe( array $record ) {
        return self::guard( static function () use ( $record ) {
            $recipe = $record['recipe'] ?? []; $builder = $record['builder'] ?? $recipe['builder'] ?? '';
            $resource = $record['resource_id'] ?? ''; $type = self::post_type( $resource );
            if ( $builder !== ( $recipe['builder'] ?? '' ) || ( $recipe['mode'] ?? 'static' ) !== 'static' || ! is_array( $recipe['blueprint'] ?? null ) || ! is_array( $recipe['bindings'] ?? null ) || ! empty( $recipe['regions'] ) || ! empty( $recipe['custom_fields'] ) ) { self::fail( 'Automatic posting needs a complete saved layout with fixed scalar fields. Repeated regions and custom-field companions require their own publishing capability.' ); }
            $inventory = self::inventory( $builder, $recipe['blueprint'] );
            $draft = [ 'schema_version' => 1, 'revision' => (string) ( $record['id'] ?? '' ) . ':' . (string) ( $record['revision'] ?? '' ), 'reference_type' => 'post', 'reference_id' => 0, 'signature' => Nova_Bridge_Suite_Recipe_Engine::digest( $recipe['blueprint'] ), 'catalog_mode' => 'destination', 'template' => [ 'id' => 'local-destination', 'revision' => '1' ], 'profile_page_type' => $record['posting_page_type'] ?? ( 'post' === $type ? 'informative' : 'service' ), 'label' => $record['label'] ?? 'Saved content layout', 'guidance_mode' => empty( $recipe['instructions'] ) ? 'clear' : 'set', 'guidance' => $recipe['instructions'] ?? '', 'routing' => [ 'operation' => 'create', 'publication' => 'publish', 'post_type' => $type, 'locale' => '' ], 'fields' => [], 'target_descriptors' => [], 'repeat_slots' => [], 'destination_groups' => [] ];
            $draft['fields']['/title'] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => true, 'instructions' => '', 'description' => [ 'label' => 'Content title', 'purpose' => 'The native content title matching the supplied heading.', 'value_type' => 'text', 'constraints' => [] ] ];
            $draft['target_descriptors']['/title'] = [ 'path' => '/title' ];
            // Core already exposes the native title. Preserve its instructions instead of asking for a second title.
            if ( 'core' === $builder && in_array( '/document/title', array_column( $recipe['bindings'], 'target' ), true ) ) {
                unset( $draft['fields']['/title'], $draft['target_descriptors']['/title'] );
            }
            foreach ( $recipe['bindings'] as $binding ) {
                $path = $binding['target'] ?? ''; $field = $inventory[$path] ?? null;
                if ( ! is_array( $field ) || empty( $field['writable'] ) || ! in_array( $binding['format'] ?? '', [ 'text', 'html', 'url' ], true ) || isset( $draft['fields'][$path] ) ) { self::fail( 'Every creation binding must address one distinct supported native scalar field.' ); }
                $value_type = 'html' === $binding['format'] ? 'rich_text' : ( 'url' === $binding['format'] ? 'url' : 'text' );
                $label = isset( $binding['label'] ) && '' !== trim( $binding['label'] ) ? $binding['label'] : $field['label'];
                $draft['fields'][$path] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => ! empty( $binding['required'] ), 'instructions' => $binding['instructions'] ?? '', 'description' => [ 'label' => $label, 'purpose' => 'Content for ' . $label . ( empty( $binding['source'] ) ? '.' : ', described by ' . $binding['source'] . '.' ), 'value_type' => $value_type, 'constraints' => [] ] ];
                $draft['target_descriptors'][$path] = [ 'path' => $path, 'builder' => $builder ];
            }
            $draft['stored_layout'] = self::seal( $draft, $builder, $resource, $recipe['blueprint'], [ 'title' => '', 'excerpt' => 'core' === $builder ? ( $recipe['blueprint']['document']['excerpt'] ?? '' ) : '' ] );
            return $draft;
        } );
    }
    public static function validate( array $draft ) {
        return self::guard( static function () use ( $draft ) {
            $layout = $draft['stored_layout'] ?? [];
            if ( ( $layout['format'] ?? '' ) !== self::FORMAT || ! is_string( $layout['builder'] ?? null ) || ! is_string( $layout['resource_id'] ?? null ) || ! is_array( $layout['blueprint'] ?? null ) || ! is_array( $layout['post_fields'] ?? null ) || ! is_array( $layout['bindings'] ?? null ) || ! is_string( $layout['digest'] ?? null ) ) { self::fail( 'Re-save the complete native creation layout before synchronizing.' ); }
            $unsigned = $layout; unset( $unsigned['digest'] );
            if ( ! hash_equals( Nova_Bridge_Suite_Recipe_Engine::digest( $unsigned ), $layout['digest'] ) ) { self::fail( 'The retained native layout changed after it was saved.' ); }
            $sealed = self::seal( $draft, $layout['builder'], $layout['resource_id'], $layout['blueprint'], $layout['post_fields'] );
            if ( ! hash_equals( $sealed['digest'], $layout['digest'] ) ) { self::fail( 'The mapping fields or native destination differ from the retained layout.' ); }
            return $layout;
        } );
    }
    public static function compile( array $draft, array $destination_values, string $seed ) {
        return self::guard( static function () use ( $draft, $destination_values, $seed ) {
            if ( '' === $seed ) { self::fail( 'A retained operation identity is required for native layout compilation.' ); }
            $layout = self::checked( self::validate( $draft ) ); $effective = Nova_Bridge_Suite_Writing_Adapter::adaptation_draft( $draft );
            $values = self::checked( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $effective, $destination_values ) );
            $adapter = self::checked( Nova_Bridge_Suite_Recipe_Engine::adapter( $layout['builder'] ) );
            $blueprint = $layout['blueprint']; $post_fields = $layout['post_fields'];
            foreach ( $layout['bindings'] as $path => $binding ) {
                if ( 'protected' === $binding['mode'] ) { continue; }
                $value = 'leave_empty' === $binding['mode'] ? '' : ( $values[$path] ?? '' );
                $rich = 'rich_text' === ( $effective['fields'][$path]['description']['value_type'] ?? '' );
                if ( $rich ? ( ! function_exists( 'wp_kses_post' ) || wp_kses_post( $value ) !== $value ) : preg_match( '/<[^>]*>/', $value ) ) { self::fail( 'Fitted content does not satisfy the saved field or WordPress HTML policy.' ); }
                if ( 'post' === $binding['kind'] ) { $post_fields[$binding['column']] = $value; }
                else { $blueprint = self::checked( $adapter->bind( $blueprint, $binding, $value ) ); }
            }
            $compiled = self::checked( $adapter->finalize( $blueprint, 'stored-layout:' . $seed, [ 'recipe' => [ 'blueprint' => $layout['blueprint'] ] ] ) );
            if ( ! is_array( $compiled['writer_payload'] ?? null ) ) { self::fail( 'The builder did not produce a complete native payload.' ); }
            return [ 'builder' => $layout['builder'], 'post_type' => $layout['post_type'], 'writer_payload' => $compiled['writer_payload'], 'post_fields' => $post_fields, 'layout_digest' => $layout['digest'] ];
        } );
    }
}
