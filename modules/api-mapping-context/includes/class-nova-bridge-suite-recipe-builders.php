<?php
/** Native document adapters for the shared layout recipe compiler. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Recipe_Builders {
    const BUILDERS = [ 'elementor', 'gutenberg', 'wpbakery', 'avada', 'divi4', 'divi5', 'beaver', 'breakdance' ];
    private static $adapters = [];

    public static function register(): void {
        foreach ( self::BUILDERS as $builder ) {
            $adapter = self::adapter( $builder );
            if ( ! is_wp_error( $adapter ) && class_exists( 'Nova_Bridge_Suite_Recipe_Engine' ) ) { Nova_Bridge_Suite_Recipe_Engine::register_adapter( $builder, $adapter ); }
        }
    }

    public static function adapter( string $builder ) {
        if ( ! in_array( $builder, self::BUILDERS, true ) ) { return self::error( 'builder', 'This native recipe adapter is unavailable.' ); }
        if ( 'elementor' === $builder && ! class_exists( 'Nova_Bridge_Suite_Recipe_Elementor' ) ) { return self::error( 'builder', 'The Elementor recipe adapter is unavailable.' ); }
        if ( ! isset( self::$adapters[ $builder ] ) ) { self::$adapters[ $builder ] = 'elementor' === $builder ? new Nova_Bridge_Suite_Recipe_Elementor() : new Nova_Bridge_Suite_Recipe_Native_Adapter( $builder ); }
        return self::$adapters[ $builder ];
    }

    public static function catalog( string $post_type = 'page' ): array {
        $result = [];
        foreach ( self::BUILDERS as $builder ) {
            $adapter = self::adapter( $builder );
            if ( is_wp_error( $adapter ) ) { $result[] = [ 'id' => $builder, 'label' => 'Elementor', 'available' => false, 'reason' => $adapter->get_error_message() ]; continue; }
            $result[] = 'elementor' === $builder ? array_merge( [ 'id' => 'elementor', 'label' => 'Elementor' ], $adapter->capabilities( $post_type ) ) : $adapter->metadata( $post_type );
        }
        return $result;
    }

    public static function capture( string $builder, int $post_id, bool $include_document_settings = true ) {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        return 'elementor' === $builder ? $adapter->capture( $post_id, $include_document_settings ) : $adapter->capture( $post_id );
    }

    /** Report real native carriers, including disabled providers; never guess from rendered text. */
    public static function detect_reference( int $post_id ) {
        if ( $post_id < 1 ) { return self::error( 'reference', 'Choose an actual native reference before inspecting its builder.', 404 ); }
        $post = get_post( $post_id );
        if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) { return self::error( 'reference', 'The native reference cannot be inspected by this account.', 403 ); }
        return self::native_carriers( $post );
    }

    /** Shared read-only carrier inspection; callers enforce their own editor permissions. */
    public static function native_carriers( $post ): array {
        $post_id = (int) $post->ID;
        $content = (string) $post->post_content; $builders = []; $elementor = get_post_meta( $post_id, '_elementor_data', true );
        if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) || ! empty( $elementor ) && ! in_array( $elementor, [ '[]', [] ], true ) ) { $builders[] = 'elementor'; }
        $divi5 = 1 === preg_match( '/<!--\s+wp:divi\//i', $content );
        if ( $divi5 ) { $builders[] = 'divi5'; }
        if ( preg_match( '/\[et_pb_/i', $content ) || ! $divi5 && 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ) ) { $builders[] = 'divi4'; }
        if ( preg_match( '/\[vc_/i', $content ) ) { $builders[] = 'wpbakery'; }
        if ( preg_match( '/\[fusion_/i', $content ) || ! empty( get_post_meta( $post_id, '_fusion_builder_shortcodes', true ) ) ) { $builders[] = 'avada'; }
        if ( in_array( get_post_meta( $post_id, '_fl_builder_enabled', true ), [ true, 1, '1', 'true', 'yes', 'on' ], true ) || ! empty( get_post_meta( $post_id, '_fl_builder_data', true ) ) ) { $builders[] = 'beaver'; }
        if ( ! empty( get_post_meta( $post_id, '_breakdance_data', true ) ) ) { $builders[] = 'breakdance'; }
        if ( ! $divi5 && preg_match( '/<!--\s+wp:/i', $content ) ) { $builders[] = 'gutenberg'; }
        return array_values( array_unique( $builders ) );
    }

    public static function starter( string $builder, string $mode = 'mixed' ) {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        $capabilities = $adapter->capabilities();
        if ( empty( $capabilities['available'] ) ) { return self::error( 'provider', 'Enable the native builder and its NOVA bridge before starting this layout.', 503 ); }
        return $adapter->starter( $mode );
    }

    public static function describe( string $builder, array $blueprint ) {
        $adapter = self::adapter( $builder );
        return is_wp_error( $adapter ) ? $adapter : $adapter->describe( $blueprint );
    }

    public static function fields( string $builder, array $blueprint ): array {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return []; }
        if ( 'elementor' !== $builder ) { return $adapter->fields( $blueprint ); }
        $description = $adapter->describe( $blueprint );
        return is_wp_error( $description ) ? [] : ( $description['fields'] ?? [] );
    }

    public static function containers( string $builder, array $blueprint ): array {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return []; }
        if ( 'elementor' !== $builder ) { return $adapter->containers( $blueprint ); }
        $description = $adapter->describe( $blueprint );
        return is_wp_error( $description ) ? [] : ( $description['containers'] ?? [] );
    }

    public static function write_draft( string $builder, array $payload, array $post_fields ) {
        $adapter = self::adapter( $builder );
        return is_wp_error( $adapter ) ? $adapter : $adapter->write_draft( $payload, $post_fields );
    }

    public static function verify_saved( string $builder, int $post_id, array $payload ) {
        $adapter = self::adapter( $builder );
        if ( is_wp_error( $adapter ) ) { return $adapter; }
        if ( 'elementor' === $builder ) {
            if ( method_exists( $adapter, 'verify_saved' ) ) { return $adapter->verify_saved( $post_id, $payload ); }
            return self::error( 'provider', 'The Elementor native readback adapter is unavailable.', 503 );
        }
        return $adapter->verify_saved( $post_id, $payload );
    }

    public static function error( string $code, string $message, int $status = 422 ): WP_Error {
        return new WP_Error( 'nova_recipe_' . $code, $message, [ 'status' => $status ] );
    }
}

/** Each adapter preserves its provider's native child and setting vocabulary. */
final class Nova_Bridge_Suite_Recipe_Native_Adapter {
    private $builder;
    private $labels = [ 'gutenberg' => 'WordPress blocks', 'wpbakery' => 'WPBakery', 'avada' => 'Avada', 'divi4' => 'Divi shortcode layout', 'divi5' => 'Divi 5 native layout', 'beaver' => 'Beaver Builder', 'breakdance' => 'Breakdance' ];

    public function __construct( string $builder ) { $this->builder = $builder; }

    public function metadata( string $post_type = 'page' ): array {
        $available = $this->available();
        $type = get_post_type_object( $post_type );
        $draft = $available && $type && current_user_can( $type->cap->create_posts ?? $type->cap->edit_posts ) && current_user_can( $type->cap->edit_posts );
        if ( 'divi5' === $this->builder ) { $draft = $draft && current_user_can( 'unfiltered_html' ); }
        return [ 'id' => $this->builder, 'label' => $this->labels[ $this->builder ], 'available' => $available, 'provider' => $this->builder,
            'version' => $this->version(), 'source_free' => true, 'capture' => true, 'starter' => $available, 'draft' => $draft, 'modes' => [ 'static', 'mixed', 'rules' ],
            'reason' => $available ? '' : 'Enable this site’s native builder and its NOVA bridge before creating a test draft.' ];
    }

    public function capabilities( string $post_type = 'page' ): array { return $this->metadata( $post_type ); }
    public function describe( array $blueprint ) {
        $checked = $this->validate_blueprint( $blueprint );
        return is_wp_error( $checked ) ? $checked : $this->envelope( $checked );
    }

    private function available(): bool {
        switch ( $this->builder ) {
            case 'gutenberg': return function_exists( 'nova_gut_create_page' ) && function_exists( 'parse_blocks' );
            case 'wpbakery': return function_exists( 'nova_wpb_create_page' ) && ( defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ) || function_exists( 'vc_manager' ) );
            case 'avada': return class_exists( 'NovaAvadaBridge\\REST_Controller' ) && ( defined( 'FUSION_BUILDER_VERSION' ) || class_exists( 'FusionBuilder' ) );
            case 'divi4': return function_exists( 'nova_divi_create_page' ) && defined( 'ET_BUILDER_VERSION' );
            case 'divi5': return function_exists( 'nova_divi_create_page' ) && function_exists( 'nova_divi5_native_create_available' ) && nova_divi5_native_create_available();
            case 'beaver': return function_exists( 'nova_bb_create_page' ) && class_exists( 'FLBuilderModel' );
            case 'breakdance': return class_exists( 'Nova_BD_REST_Controller' ) && ( defined( 'BREAKDANCE_VERSION' ) || class_exists( 'Breakdance\\Plugin' ) );
        }
        return false;
    }

    private function version(): string {
        $constants = [ 'wpbakery' => 'WPB_VC_VERSION', 'avada' => 'FUSION_BUILDER_VERSION', 'divi4' => 'ET_BUILDER_VERSION', 'divi5' => 'ET_BUILDER_VERSION', 'beaver' => 'FL_BUILDER_VERSION', 'breakdance' => 'BREAKDANCE_VERSION' ];
        $constant = $constants[ $this->builder ] ?? '';
        return $constant && defined( $constant ) ? (string) constant( $constant ) : ( 'gutenberg' === $this->builder ? (string) ( $GLOBALS['wp_version'] ?? '' ) : '' );
    }

    private function shortcode_helpers(): void {
        if ( ! function_exists( 'nova_wpb_parse_shortcodes_to_compact' ) ) { require_once dirname( __DIR__, 2 ) . '/wpbakery/includes/layout.php'; }
    }

    private function helpers(): void {
        if ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) { $this->shortcode_helpers(); }
        if ( in_array( $this->builder, [ 'divi4', 'divi5' ], true ) ) {
            require_once dirname( __DIR__, 2 ) . '/divi/includes/helpers.php';
            require_once dirname( __DIR__, 2 ) . '/divi/includes/layout.php';
        } elseif ( 'beaver' === $this->builder ) {
            require_once dirname( __DIR__, 2 ) . '/beaver/includes/helpers.php';
            require_once dirname( __DIR__, 2 ) . '/beaver/includes/layout.php';
        } elseif ( 'breakdance' === $this->builder ) {
            require_once dirname( __DIR__, 2 ) . '/breakdance/includes/class-nova-bd-utils.php';
        }
    }

    private function envelope( array $blueprint, array $warnings = [] ): array {
        return [ 'builder' => $this->builder, 'blueprint' => $blueprint, 'fields' => $this->fields( $blueprint ), 'containers' => $this->containers( $blueprint ),
            'provider' => $this->builder, 'version' => $this->version(), 'capabilities' => $this->metadata(), 'warnings' => $warnings ];
    }

    public function capture( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'permission', 'Administrator access and permission to inspect this content item are required.', 403 ); }
        $blueprint = [ 'document' => [], 'template' => (string) get_page_template_slug( $post_id ), 'meta' => $this->page_meta( $post_id ) ];
        try {
            $this->helpers();
            if ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
                $this->shortcode_helpers();
                $content = (string) $post->post_content;
                if ( 'avada' === $this->builder && '' === trim( $content ) ) { $content = (string) get_post_meta( $post_id, '_fusion_builder_shortcodes', true ); }
                $marker = [ 'wpbakery' => '[vc_', 'avada' => '[fusion_', 'divi4' => '[et_pb_' ][ $this->builder ];
                if ( false === strpos( $content, $marker ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'reference', 'The selected item does not contain this builder’s native layout.' ); }
                if ( 'divi4' === $this->builder && 'divi4-shortcodes' !== nova_divi_content_format( $content ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'reference', 'Choose the adapter matching the actual Divi document format.' ); }
                $blueprint['document'] = nova_wpb_parse_shortcodes_to_compact( $content );
                if ( nova_wpb_compact_to_shortcodes( $blueprint['document'] ) !== $content ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'capture', 'This shortcode document cannot be captured without changing native source fragments. No partial layout was returned.' ); }
            } elseif ( 'gutenberg' === $this->builder || 'divi5' === $this->builder ) {
                if ( ! function_exists( 'parse_blocks' ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The native WordPress block parser is unavailable.' ); }
                if ( 'divi5' === $this->builder ) {
                    $checked = nova_divi5_validate_create_document( (string) $post->post_content );
                    if ( is_wp_error( $checked ) ) { return $checked; }
                } elseif ( preg_match( '/\[(?:vc_|fusion_|et_pb_)|<!--\s+wp:divi\//i', $post->post_content ) || get_post_meta( $post_id, '_fl_builder_enabled', true ) || get_post_meta( $post_id, '_breakdance_data', true ) || 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
                    return Nova_Bridge_Suite_Recipe_Builders::error( 'reference', 'Choose the native builder adapter for this content item.' );
                }
                $blueprint['document'] = parse_blocks( (string) $post->post_content );
            } elseif ( 'beaver' === $this->builder ) {
                if ( ! function_exists( 'nova_bb_get_layout_nodes' ) || ! get_post_meta( $post_id, '_fl_builder_enabled', true ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'reference', 'The selected item does not contain an active Beaver layout.' ); }
                $blueprint['document'] = self::arrays( nova_bb_flat_to_tree( nova_bb_get_layout_nodes( $post_id ) ) );
            } elseif ( 'breakdance' === $this->builder ) {
                if ( ! class_exists( 'Nova_BD_Utils' ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The Breakdance bridge is unavailable.' ); }
                $wrapper = Nova_BD_Utils::decode_breakdance_tree( Nova_BD_Utils::get_raw_breakdance_data( $post_id ) );
                if ( ! $wrapper ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'reference', 'The selected item has no complete Breakdance document.' ); }
                $blueprint['document'] = [ $wrapper['root'] ];
                $decoded = $wrapper['decoded'] ?? []; $inner = $wrapper['inner'] ?? [];
                unset( $decoded['root'], $decoded['tree_json_string'], $inner['root'] );
                $blueprint['envelope'] = [ 'decoded' => $decoded, 'inner' => $inner, 'root_source' => $wrapper['root_source'], 'raw_array' => is_array( $wrapper['raw'] ?? null ) ];
            }
            $checked = $this->validate_blueprint( $blueprint );
            return is_wp_error( $checked ) ? $checked : $this->envelope( $checked );
        } catch ( Throwable $error ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'capture', 'The native provider could not capture this complete layout.' ); }
    }

    /** Known native one-column foundations, independent of any source page ID. */
    public function starter( string $mode = 'mixed' ) {
        $this->helpers();
        $heading = 'Example heading'; $body = '<p>Example paragraph. Replace this through a content binding or repeated region.</p>';
        if ( 'gutenberg' === $this->builder ) {
            $document = [ self::block( 'core/group', [], '<div class="wp-block-group">', '</div>', [ self::block( 'core/heading', [ 'level' => 2 ], '<h2 class="wp-block-heading">' . $heading . '</h2>' ), self::block( 'core/paragraph', [], $body ) ] ) ];
        } elseif ( 'divi5' === $this->builder ) {
            // These module names and innerContent envelopes are inspected native
            // fixtures; no unverified section/row/column defaults are invented.
            $document = [ self::block( 'divi/placeholder', [], '', '', [ self::block( 'divi/heading', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<h2>' . $heading . '</h2>' ] ] ] ], '' ), self::block( 'divi/text', [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => $body ] ] ] ], '' ) ] ) ];
        } elseif ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
            $this->shortcode_helpers();
            if ( 'wpbakery' === $this->builder ) { $raw = '[vc_row][vc_column][vc_custom_heading text="' . $heading . '"][vc_column_text]' . $body . '[/vc_column_text][/vc_column][/vc_row]'; }
            elseif ( 'avada' === $this->builder ) { $raw = '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_title size="2"]' . $heading . '[/fusion_title][fusion_text]' . $body . '[/fusion_text][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]'; }
            else { $raw = '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text]<h2>' . $heading . '</h2>[/et_pb_text][et_pb_text]' . $body . '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]'; }
            $document = nova_wpb_parse_shortcodes_to_compact( $raw );
        } elseif ( 'beaver' === $this->builder ) {
            $document = [ self::bb_node( 'row', [], [ self::bb_node( 'column-group', '', [ self::bb_node( 'column', [ 'size' => 100 ], [ self::bb_node( 'module', [ 'type' => 'heading', 'heading' => $heading, 'tag' => 'h2' ] ), self::bb_node( 'module', [ 'type' => 'rich-text', 'text' => $body ] ) ] ) ] ) ] ) ];
        } else {
            $document = [ [ 'id' => 1, 'data' => [ 'type' => 'root', 'properties' => [] ], 'children' => [ self::bd_node( 'Section', [], [ self::bd_node( 'Div', [], [ self::bd_node( 'Heading', [ 'content' => [ 'content' => [ 'text' => $heading, 'tags' => 'h2' ] ] ] ), self::bd_node( 'Text', [ 'content' => [ 'content' => [ 'text' => $body ] ] ] ) ] ) ] ) ] ] ];
        }
        $blueprint = [ 'document' => $document, 'template' => '', 'meta' => [] ];
        if ( 'breakdance' === $this->builder ) { $blueprint['envelope'] = [ 'decoded' => [], 'inner' => [ '_nextNodeId' => 2, 'status' => 'exported' ], 'root_source' => 'tree_json_string', 'raw_array' => false ]; }
        $warnings = 'divi5' === $this->builder ? [ 'The simple native Divi 5 starter uses inspected heading/text modules. Richer native layout prototypes can be captured; frontend certification depends on the installed provider.' ] : [];
        if ( 'static' === $mode ) {
            if ( 'gutenberg' === $this->builder ) { $blueprint['document'][0]['innerBlocks'][1] = self::block( 'core/html', [], $body ); }
            $artifact = $this->envelope( $blueprint, $warnings ); $artifact['suggested_bindings'] = [];
            foreach ( array_slice( $artifact['fields'], 0, 2 ) as $index => $field ) { $artifact['suggested_bindings'][] = [ 'target' => $field['target'], 'source' => $index ? '/body' : '/title', 'format' => $field['format'], 'label' => $index ? 'Article content' : 'Page heading', 'instructions' => '', 'required' => false ]; }
            $artifact['suggested_regions'] = []; $artifact['prototypes'] = [];
            return $artifact;
        }
        if ( 'gutenberg' === $this->builder ) { $blueprint['document'][0]['innerBlocks'][1] = self::block( 'core/html', [], $body ); }
        return $this->starter_regions( $blueprint, $warnings );
    }

    private function starter_regions( array $blueprint, array $warnings ): array {
        // Build real native section and FAQ group prototypes, retaining the
        // title/intro as fixed siblings. No source page or copied page ID exists.
        if ( 'gutenberg' === $this->builder ) {
            $target = '/document/0/innerBlocks';
            $section = self::block( 'core/group', [], '<div class="wp-block-group">', '</div>', $blueprint['document'][0]['innerBlocks'] );
            $section['innerBlocks'][0]['innerHTML'] = '<h2 class="wp-block-heading">Section title</h2>'; $section['innerBlocks'][0]['innerContent'] = [ $section['innerBlocks'][0]['innerHTML'] ];
            $section['innerBlocks'][1] = self::block( 'core/html', [], '<p>Section content.</p>' );
            $details = self::block( 'core/details', [], '<details class="wp-block-details"><summary>Question?</summary>', '</details>', [ self::block( 'core/html', [], '<p>Answer.</p>' ) ] );
            $faq = self::block( 'core/group', [], '<div class="wp-block-group">', '</div>', [ $details ] );
            $faq_target = '/0/innerBlocks';
        } elseif ( 'divi5' === $this->builder ) {
            $target = '/document/0/innerBlocks';
            $section = self::block( 'divi/group', [], '', '', $blueprint['document'][0]['innerBlocks'] );
            $item = self::block( 'divi/accordion-item', [ 'title' => [ 'innerContent' => [ 'desktop' => [ 'value' => 'Question?' ] ] ], 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<p>Answer.</p>' ] ] ] ], '' );
            $faq = self::block( 'divi/accordion', [], '', '', [ $item ] ); $faq_target = '/0/innerBlocks';
        } elseif ( 'wpbakery' === $this->builder ) {
            $target = '/document/0/children/0/children';
            $section = nova_wpb_parse_shortcodes_to_compact( '[vc_row_inner][vc_column_inner][vc_custom_heading text="Section title"][vc_column_text]<p>Section content.</p>[/vc_column_text][/vc_column_inner][/vc_row_inner]' )[0];
            $faq = nova_wpb_parse_shortcodes_to_compact( '[vc_tta_accordion active_section="1"][vc_tta_section title="Question?"][vc_column_text]<p>Answer.</p>[/vc_column_text][/vc_tta_section][/vc_tta_accordion]' )[0]; $faq_target = '/0/children';
        } elseif ( 'avada' === $this->builder || 'divi4' === $this->builder || 'beaver' === $this->builder ) {
            $target = '/document'; $section = $blueprint['document'][0];
            if ( 'avada' === $this->builder ) {
                $faq = nova_wpb_parse_shortcodes_to_compact( '[fusion_builder_container][fusion_builder_row][fusion_builder_column type="1_1"][fusion_accordion][fusion_toggle title="Question?"]<p>Answer.</p>[/fusion_toggle][/fusion_accordion][/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' )[0];
                $faq_target = '/0/children/0/children/0/children/0/children';
            } elseif ( 'divi4' === $this->builder ) {
                $faq = nova_wpb_parse_shortcodes_to_compact( '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_accordion][et_pb_accordion_item title="Question?"]<p>Answer.</p>[/et_pb_accordion_item][/et_pb_accordion][/et_pb_column][/et_pb_row][/et_pb_section]' )[0];
                $faq_target = '/0/children/0/children/0/children/0/children';
            } else {
                // A plain native FAQ group works without requiring a source
                // accordion prototype. Captured accordions retain their widgets.
                $faq = self::bb_node( 'row', [], [ self::bb_node( 'column-group', '', [ self::bb_node( 'column', [ 'size' => 100 ], [ self::bb_node( 'module', [ 'type' => 'heading', 'heading' => 'Question?', 'tag' => 'h3' ] ), self::bb_node( 'module', [ 'type' => 'rich-text', 'text' => '<p>Answer.</p>' ] ) ] ) ] ) ] );
                $faq_target = '/0/children/0/children/0/children';
                // The repeated native list holds the heading and answer as one
                // logical Q/A unit within its ordinary column, never a nested row.
            }
        } else {
            $target = '/document/0/children'; $section = $blueprint['document'][0]['children'][0];
            $item = self::bd_node( 'Div', [], [ self::bd_node( 'Heading', [ 'content' => [ 'content' => [ 'text' => 'Question?', 'tags' => 'h3' ] ] ] ), self::bd_node( 'Text', [ 'content' => [ 'content' => [ 'text' => '<p>Answer.</p>' ] ] ] ) ] );
            $faq = self::bd_node( 'Section', [], [ self::bd_node( 'Div', [], [ $item ] ) ] ); $faq_target = '/0/children/0/children';
        }
        $current = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $target ); $start = count( $current );
        Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $target, array_merge( $current, [ $section, $faq ] ) );
        if ( in_array( $this->builder, [ 'gutenberg', 'divi5' ], true ) ) {
            $parent = substr( $target, 0, -12 ); $node = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $parent );
            $node['innerContent'] = array_merge( [ $node['innerContent'][0] ?? '' ], array_fill( 0, count( $node['innerBlocks'] ), null ), [ end( $node['innerContent'] ) ?: '' ] );
            Nova_Bridge_Suite_Recipe_Engine::put( $blueprint, $parent, $node );
        }
        $artifact = $this->envelope( $blueprint, $warnings );
        $fixed = $this->fields( [ 'document' => [ $blueprint['document'][0] ] ] );
        $artifact['suggested_bindings'] = [];
        foreach ( array_slice( $fixed, 0, 2 ) as $index => $field ) { $artifact['suggested_bindings'][] = [ 'target' => $field['target'], 'source' => $index ? '/intro' : '/title', 'format' => $field['format'], 'label' => $index ? 'Introduction' : 'Page heading', 'instructions' => '', 'required' => false ]; }
        $section_fields = $this->fields( [ $section ] ); $section_bindings = [];
        foreach ( array_slice( $section_fields, 0, 2 ) as $index => $field ) { $section_bindings[] = [ 'target' => $field['target'], 'source' => $index ? '/body_with_children_html' : '/title', 'format' => $field['format'], 'label' => $index ? 'Section content and nested content' : 'Section heading', 'instructions' => '', 'required' => false ]; }
        $faq_native = Nova_Bridge_Suite_Recipe_Engine::get( [ $faq ], $faq_target ); $faq_fields = $this->fields( $faq_native ); $faq_bindings = [];
        foreach ( array_slice( $faq_fields, 0, 2 ) as $field ) { $question = in_array( $field['kind'], [ 'title', 'question', 'heading', 'label' ], true ); $faq_bindings[] = [ 'target' => $field['target'], 'source' => $question ? '/question' : '/answer', 'format' => $field['format'], 'label' => $question ? 'Question' : 'Answer', 'instructions' => '', 'required' => false ]; }
        $artifact['suggested_regions'] = [
            [ 'id' => 'sections', 'label' => 'Content sections', 'target' => $target, 'start' => $start, 'count' => 1, 'source' => '/sections', 'kind' => 'repeat', 'empty' => 'remove', 'max_items' => 100, 'prototype' => [ 'native' => [ $section ], 'bindings' => $section_bindings, 'regions' => [] ] ],
            [ 'id' => 'faq', 'label' => 'Optional FAQ', 'target' => $target, 'start' => $start + 1, 'count' => 1, 'source' => '/faqs', 'kind' => 'optional', 'context' => 'parent', 'empty' => 'remove', 'max_items' => 1, 'prototype' => [ 'native' => [ $faq ], 'bindings' => [], 'regions' => [ [ 'id' => 'questions', 'label' => 'FAQ items', 'target' => $faq_target, 'start' => 0, 'count' => count( $faq_native ), 'source' => '/faqs', 'kind' => 'repeat', 'empty' => 'remove', 'max_items' => 100, 'prototype' => [ 'native' => $faq_native, 'bindings' => $faq_bindings, 'regions' => [] ] ] ] ] ],
        ];
        $artifact['prototypes'] = [ 'section' => [ $section ], 'faq' => [ $faq ] ];
        return $artifact;
    }

    private static function block( string $name, array $attrs, string $html, string $closing = '', array $children = [] ): array {
        return [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $html . $closing,
            'innerContent' => $children ? array_merge( [ $html ], array_fill( 0, count( $children ), null ), [ $closing ] ) : [ $html ] ];
    }
    private static function bb_node( string $type, $settings, array $children = [] ): array { return [ 'node_id' => null, 'type' => $type, 'settings' => $settings, 'orig' => null, 'children' => $children ]; }
    private static function bd_node( string $type, array $properties, array $children = [] ): array { return [ 'id' => 0, 'data' => [ 'type' => 'EssentialElements\\' . $type, 'properties' => $properties ], 'children' => $children ]; }

    private function page_meta( int $post_id ): array {
        $result = [];
        foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
            $keep = 'wpbakery' === $this->builder ? '_wpb_post_custom_css' === $key
                : ( 'avada' === $this->builder ? 0 === strpos( $key, 'pyre_' )
                : ( in_array( $this->builder, [ 'divi4', 'divi5' ], true ) ? in_array( $key, [ '_et_pb_page_layout', '_et_pb_show_title', '_et_pb_custom_css', '_et_pb_post_hide_nav' ], true )
                : ( 'beaver' === $this->builder ? '_fl_builder_data_settings' === $key
                : ( 'breakdance' === $this->builder ? in_array( $key, [ '_breakdance_page_settings', 'breakdance_page_settings', '_breakdance_template', 'breakdance_template' ], true ) : false ) ) ) );
            if ( $keep && is_array( $values ) && 1 === count( $values ) ) { $result[ $key ] = self::arrays( maybe_unserialize( $values[0] ) ); }
        }
        return $result;
    }

    private static function arrays( $value ) {
        if ( is_object( $value ) ) { $value = get_object_vars( $value ); }
        if ( is_array( $value ) ) { foreach ( $value as $key => $child ) { $value[ $key ] = self::arrays( $child ); } }
        return $value;
    }

    private static function no_secrets( $value, int $depth = 0 ): bool {
        if ( $depth > 64 || is_object( $value ) ) { return false; }
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $child ) {
                if ( is_string( $key ) && preg_match( '/(?:password|passwd|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|credentials?)/i', $key ) && ! empty( $child ) ) { return false; }
                if ( ! self::no_secrets( $child, $depth + 1 ) ) { return false; }
            }
        }
        return true;
    }

    private function structural( string $tag ): bool {
        if ( 'gutenberg' === $this->builder ) { return in_array( $tag, [ 'core/group', 'core/columns', 'core/column', 'core/cover', 'core/details', 'core/list' ], true ); }
        if ( 'divi5' === $this->builder ) { return in_array( $tag, [ 'divi/placeholder', 'divi/section', 'divi/row', 'divi/row-inner', 'divi/column', 'divi/column-inner', 'divi/group', 'divi/accordion' ], true ); }
        if ( 'wpbakery' === $this->builder ) { return in_array( $tag, [ 'vc_row', 'vc_column', 'vc_row_inner', 'vc_column_inner', 'vc_tta_accordion', 'vc_tta_section', 'vc_tta_tabs', 'vc_tta_tour' ], true ); }
        if ( 'avada' === $this->builder ) { return in_array( $tag, [ 'fusion_builder_container', 'fusion_builder_row', 'fusion_builder_column', 'fusion_accordion', 'fusion_tabs' ], true ); }
        if ( 'divi4' === $this->builder ) { return nova_divi_is_structural_container_tag( $tag ); }
        if ( 'beaver' === $this->builder ) { return in_array( $tag, [ 'row', 'column-group', 'column', 'box' ], true ); }
        return in_array( $tag, [ 'root', 'EssentialElements\\Section', 'EssentialElements\\Div', 'EssentialElements\\Container', 'EssentialElements\\Columns', 'EssentialElements\\Column', 'EssentialElements\\Row', 'EssentialElements\\Grid', 'EssentialElements\\GridItem' ], true );
    }

    private function human_label( string $tag ): string {
        $tags = [ 'vc_custom_heading' => 'Heading', 'fusion_title' => 'Heading', 'divi/heading' => 'Heading', 'core/heading' => 'Heading', 'heading' => 'Heading', 'EssentialElements\\Heading' => 'Heading',
            'vc_column_text' => 'Text', 'fusion_text' => 'Text', 'et_pb_text' => 'Text', 'divi/text' => 'Text', 'core/paragraph' => 'Paragraph', 'core/html' => 'Rich text', 'rich-text' => 'Text', 'EssentialElements\\Text' => 'Text',
            'vc_row' => 'Row', 'vc_row_inner' => 'Row', 'fusion_builder_row' => 'Row', 'et_pb_row' => 'Row', 'divi/row' => 'Row', 'row' => 'Row', 'column-group' => 'Columns', 'core/columns' => 'Columns',
            'vc_column' => 'Column', 'vc_column_inner' => 'Column', 'fusion_builder_column' => 'Column', 'et_pb_column' => 'Column', 'divi/column' => 'Column', 'column' => 'Column', 'core/column' => 'Column',
            'fusion_builder_container' => 'Section', 'et_pb_section' => 'Section', 'divi/section' => 'Section', 'EssentialElements\\Section' => 'Section',
            'core/group' => 'Content group', 'divi/group' => 'Content group', 'EssentialElements\\Div' => 'Content group', 'divi/placeholder' => 'Layout', 'root' => 'Layout',
            'vc_tta_accordion' => 'FAQ', 'fusion_accordion' => 'FAQ', 'et_pb_accordion' => 'FAQ', 'divi/accordion' => 'FAQ', 'core/details' => 'FAQ item', 'vc_tta_section' => 'FAQ item', 'fusion_toggle' => 'FAQ item', 'et_pb_accordion_item' => 'FAQ item', 'divi/accordion-item' => 'FAQ item' ];
        return $tags[ $tag ] ?? ucwords( str_replace( [ '_', '-', '\\', '/' ], ' ', $tag ) );
    }

    private function node_example( array $node ): string {
        $value = $node['attributes']['admin_label'] ?? $node['attrs']['module']['meta']['adminLabel']['desktop']['value'] ?? $node['attributes']['title'] ?? $node['attributes']['text'] ?? $node['settings']['heading'] ?? $node['text'] ?? $node['innerHTML'] ?? $node['data']['properties']['content']['content']['text'] ?? '';
        if ( ! is_string( $value ) ) { return ''; }
        if ( 'divi4' === $this->builder ) { $value = nova_divi_decode_attr( $value ); }
        $value = trim( preg_replace( '/\s+/u', ' ', strip_tags( $value ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 70 ) : substr( $value, 0, 70 );
    }

    private static function heading_html( string $html ): bool { return 1 === preg_match( '/\A\s*<h([1-6])(?:\s[^>]*)?>[\s\S]*<\/h\1>\s*\z/i', $html ); }
    private static function inside_wrapper( string $original, string $value, bool $plain = false ): string {
        if ( ! preg_match( '/\A(\s*<([a-z][a-z0-9]*)\b[^>]*>)([\s\S]*)(<\/\2>\s*)\z/i', $original, $match ) ) { return $plain ? esc_html( $value ) : $value; }
        if ( preg_match( '/\A\s*<' . preg_quote( $match[2], '/' ) . '\b[^>]*>([\s\S]*)<\/' . preg_quote( $match[2], '/' ) . '>\s*\z/i', $value, $supplied ) ) { $value = $supplied[1]; }
        return $match[1] . ( $plain ? esc_html( $value ) : $value ) . $match[4];
    }

    public function validate_blueprint( array $blueprint ) {
        $this->helpers();
        if ( array_diff( array_keys( $blueprint ), [ 'document', 'meta', 'template', 'envelope' ] ) || ! isset( $blueprint['document'] ) || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $blueprint['document'] ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'Use a complete native document blueprint.' ); }
        if ( isset( $blueprint['meta'] ) && ! is_array( $blueprint['meta'] ) || isset( $blueprint['template'] ) && ! is_string( $blueprint['template'] ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'Native page context has an invalid shape.' ); }
        try { if ( strlen( Nova_Bridge_Suite_Recipe_Engine::json( $blueprint ) ) > 2097152 ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'limit', 'Native layout blueprints are limited to two megabytes.' ); } }
        catch ( Throwable $error ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'The complete native layout is not valid JSON.' ); }
        if ( ! self::no_secrets( $blueprint ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'sensitive', 'This native layout contains credential-like provider settings. No partial blueprint was returned.' ); }
        $count = 0; $error = $this->validate_nodes( $blueprint['document'], 0, $count );
        return is_wp_error( $error ) ? $error : $blueprint;
    }

    private function validate_nodes( array $nodes, int $depth, int &$count ) {
        if ( $depth > 32 || ! Nova_Bridge_Suite_Recipe_Engine::list_array( $nodes ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'limit', 'Native layouts require ordered children within 32 levels.' ); }
        foreach ( $nodes as $node ) {
            if ( ! is_array( $node ) || ++$count > ( 'divi5' === $this->builder ? 1000 : 2000 ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'limit', 'The complete native layout exceeds this provider’s node limit.' ); }
            if ( in_array( $this->builder, [ 'gutenberg', 'divi5' ], true ) ) {
                if ( ! array_key_exists( 'blockName', $node ) || ! is_array( $node['attrs'] ?? null ) || ! is_array( $node['innerBlocks'] ?? null ) || ! is_array( $node['innerContent'] ?? null ) || ! is_string( $node['innerHTML'] ?? null ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'A native WordPress block is incomplete.' ); }
                $children = $node['innerBlocks'];
            } elseif ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
                if ( ! is_string( $node['tag'] ?? null ) || ! is_array( $node['attributes'] ?? null ) || ! is_array( $node['children'] ?? null ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'A native shortcode node is incomplete.' ); }
                $children = $node['children'];
            } elseif ( 'beaver' === $this->builder ) {
                if ( ! is_string( $node['type'] ?? null ) || ! array_key_exists( 'settings', $node ) || ! is_array( $node['children'] ?? null ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'A native Beaver node is incomplete.' ); }
                $children = $node['children'];
            } else {
                if ( ! is_array( $node['data'] ?? null ) || ! is_string( $node['data']['type'] ?? null ) || ! is_array( $node['children'] ?? null ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'blueprint', 'A native Breakdance node is incomplete.' ); }
                $children = $node['children'];
            }
            $error = $this->validate_nodes( $children, $depth + 1, $count );
            if ( is_wp_error( $error ) ) { return $error; }
        }
        return true;
    }

    public function fields( array $native ): array { return $this->inventory( $native )['fields']; }
    public function containers( array $native ): array { return $this->inventory( $native )['containers']; }

    private function inventory( array $native ): array {
        $this->helpers();
        $result = [ 'fields' => [], 'containers' => [] ];
        $nodes = $native['document'] ?? $native; $prefix = isset( $native['document'] ) ? '/document' : '';
        $this->walk_inventory( $nodes, $prefix, $result );
        return $result;
    }

    private function provenance( array $node, string $path, string $target ): array {
        $type = (string) ( $node['tag'] ?? $node['blockName'] ?? $node['data']['type'] ?? $node['settings']['type'] ?? $node['type'] ?? '' );
        $id = 'beaver' === $this->builder ? (string) ( $node['node_id'] ?? '' ) : ( 'breakdance' === $this->builder ? (string) ( $node['id'] ?? '' ) : '' );
        $anchor = '';
        if ( 'gutenberg' === $this->builder ) { $anchor = $node['attrs']['anchor'] ?? ''; }
        elseif ( 'wpbakery' === $this->builder ) { $anchor = $node['attributes']['el_id'] ?? ''; }
        elseif ( 'avada' === $this->builder ) { $anchor = $node['attributes']['id'] ?? ''; }
        elseif ( 'divi4' === $this->builder ) { $anchor = nova_divi_decode_attr( (string) ( $node['attributes']['module_id'] ?? '' ) ); }
        elseif ( 'divi5' === $this->builder ) {
            $attributes = $node['attrs']['module']['decoration']['attributes']['desktop']['value']['attributes'] ?? [];
            $matches = []; foreach ( (array) $attributes as $attribute ) { if ( 'id' === strtolower( (string) ( $attribute['name'] ?? '' ) ) && '' === ( $attribute['targetElement'] ?? null ) && is_string( $attribute['value'] ?? null ) ) { $matches[] = $attribute['value']; } }
            if ( 1 === count( $matches ) ) { $anchor = $matches[0]; }
        }
        $safe_anchor = is_string( $anchor ) && 1 === preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]{0,127}$/D', $anchor );
        $beaver_id = 'beaver' === $this->builder && 1 === preg_match( '/^[a-zA-Z0-9_-]{1,128}$/D', $id ) && 0 !== strpos( $id, 'nova-tmp-' );
        $strategy = $beaver_id ? 'beaver' : ( $safe_anchor ? 'native_anchor' : 'none' );
        return [ 'native_node_id' => $id, 'node_target' => $path, 'element_type' => $type,
            'control_path' => Nova_Bridge_Suite_Recipe_Engine::parts( substr( $target, strlen( $path ) ) ),
            'locator' => [ 'strategy' => $strategy, 'native_node_id' => $id, 'anchor' => $safe_anchor ? $anchor : '', 'precision' => 'element', 'status' => 'none' === $strategy ? 'unavailable' : 'candidate', 'reason' => 'none' === $strategy ? 'This provider does not expose a verified native page locator for this element. Use its named field in the list.' : '' ] ];
    }

    private function walk_inventory( array $nodes, string $prefix, array &$result, bool $protected = false, string $context = '', array $owner = [] ): void {
        $items = [];
        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) ) { continue; }
            $path = $prefix . '/' . $index;
            if ( 'beaver' === $this->builder && ! isset( $node['type'] ) && isset( $node['label'], $node['content'] ) ) {
                foreach ( [ 'label' => [ 'text', 'question' ], 'content' => [ 'html', 'answer' ] ] as $key => $spec ) {
                    if ( is_string( $node[ $key ] ) ) { $result['fields'][] = [ 'target' => $path . '/' . $key, 'label' => ucfirst( $spec[1] ), 'format' => $spec[0], 'kind' => $spec[1], 'value' => $node[ $key ], 'context' => $context, 'writable' => ! $protected, 'protected' => $protected, 'reason' => $protected ? 'The owning native region is protected.' : '' ] + ( $owner ? $this->provenance( $owner['node'], $owner['path'], $path . '/' . $key ) : $this->provenance( $node, $path, $path . '/' . $key ) ); }
                }
                $items[] = [ 'index' => $index, 'label' => 'FAQ item — ' . substr( strip_tags( (string) $node['label'] ), 0, 70 ) ];
                continue;
            }
            if ( 'breakdance' === $this->builder && ! isset( $node['data'] ) && isset( $node['question'], $node['answer'] ) ) {
                foreach ( [ 'question' => 'text', 'answer' => 'html' ] as $key => $format ) {
                    if ( is_string( $node[ $key ] ) ) { $result['fields'][] = [ 'target' => $path . '/' . $key, 'label' => ucfirst( $key ), 'format' => $format, 'kind' => $key, 'value' => $node[ $key ], 'context' => $context, 'writable' => ! $protected, 'protected' => $protected, 'reason' => $protected ? 'The owning native region is protected.' : '' ] + ( $owner ? $this->provenance( $owner['node'], $owner['path'], $path . '/' . $key ) : $this->provenance( $node, $path, $path . '/' . $key ) ); }
                }
                $items[] = [ 'index' => $index, 'label' => 'FAQ item — ' . substr( strip_tags( (string) $node['question'] ), 0, 70 ) ];
                continue;
            }
            $native_tag = (string) ( $node['tag'] ?? $node['blockName'] ?? $node['data']['type'] ?? $node['settings']['type'] ?? $node['type'] ?? 'Native item' );
            $label = $this->human_label( $native_tag );
            $node_protected = $protected;
            if ( 'divi5' === $this->builder ) { $node_protected = $protected || 'divi/global-layout' === $native_tag || nova_divi5_block_is_locked( $node['attrs'] ?? [] ); }
            elseif ( 'gutenberg' === $this->builder ) { $node_protected = $protected || in_array( $native_tag, [ 'core/block', 'core/template-part', 'core/query', 'core/post-content' ], true ) || ! empty( $node['attrs']['lock'] ) || ! empty( $node['attrs']['templateLock'] ); }
            $display_value = $this->node_example( $node );
            $items[] = [ 'index' => $index, 'label' => $label . ( $display_value ? ' — ' . $display_value : '' ), 'native_type' => $native_tag ];
            $node_context = $context . ( $context ? ' > ' : '' ) . $label . ' ' . ( $index + 1 );
            $add = function ( string $target, string $format, string $kind = 'text', $semantic_value = null ) use ( &$result, $label, $node_protected, $node_context, $nodes, $prefix, $node, $path ) {
                $value = Nova_Bridge_Suite_Recipe_Engine::get( $nodes, substr( $target, strlen( $prefix ) ) );
                $native_value = $value;
                if ( null !== $semantic_value ) { $value = $semantic_value; }
                $sensitive_markup = is_string( $native_value ) && preg_match( '/\[(?:gravityform|wpforms|contact-form|fluentform)|\$variable\(|<script\b/i', $native_value );
                $control = basename( $target );
                $connected = 'beaver' === $this->builder && isset( $node['settings']['connections'][$control] );
                $writable = ! $node_protected && ! $sensitive_markup && ! $connected && is_string( $value );
                $result['fields'][] = [ 'target' => $target, 'label' => $label . ' · ' . ucwords( str_replace( '_', ' ', $kind ) ), 'format' => $format, 'kind' => $kind, 'value' => $value,
                    'context' => $node_context, 'writable' => $writable, 'protected' => ! $writable, 'reason' => $writable ? '' : 'This native value belongs to a protected, dynamic or interactive region.' ] + $this->provenance( $node, $path, $target );
                if ( null !== $semantic_value ) { $result['fields'][count( $result['fields'] ) - 1]['sample'] = $semantic_value; }
            };
            if ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
                $tag = $node['tag'] ?? ''; $attrs = $node['attributes'] ?? [];
                $body_tags = [ 'vc_column_text', 'fusion_text', 'fusion_title', 'fusion_toggle', 'fusion_button', 'et_pb_text', 'et_pb_accordion_item', 'et_pb_toggle' ];
                if ( in_array( $tag, $body_tags, true ) && empty( $node['children'] ) ) { $heading = 'divi4' === $this->builder && self::heading_html( (string) ( $node['text'] ?? '' ) ); $button = 'fusion_button' === $tag; $add( $path . '/text', $heading || $button ? 'text' : 'html', $button ? 'button_text' : ( $heading || false !== strpos( $tag, 'title' ) ? 'heading' : 'body' ) ); }
                $keys = 'wpbakery' === $this->builder ? [ 'text', 'title', 'button_text', 'link_text', 'alt', 'caption', 'url', 'href', 'link_url', 'button_url', 'image_link', 'image_url', 'src' ] : ( 'avada' === $this->builder ? [ 'title', 'text', 'link', 'alt', 'image_url', 'src' ] : [ 'title', 'button_text', 'heading', 'name', 'alt', 'src', 'url', 'button_url' ] );
                foreach ( $keys as $key ) {
                    if ( ! isset( $attrs[ $key ] ) || ! is_string( $attrs[ $key ] ) ) { continue; }
                    $url = in_array( $key, [ 'url', 'src', 'href', 'image_url', 'image_link', 'link_url', 'button_url', 'link' ], true );
                    if ( in_array( $key, [ 'src', 'image_url' ], true ) && '' !== $attrs[$key] && ! preg_match( '~^(?:https?://|/)~i', $attrs[$key] ) ) { continue; }
                    $add( $path . '/attributes/' . $key, $url ? 'url' : 'text', $key, 'divi4' === $this->builder ? nova_divi_decode_attr( $attrs[$key] ) : null );
                }
                if ( 'wpbakery' === $this->builder && isset( $attrs['link'] ) && is_string( $attrs['link'] ) ) { $packed = nova_wpb_packed_link_url( $attrs['link'], $found_url ); if ( $found_url ) { $add( $path . '/attributes/link', 'url', 'link_url', $packed ); } }
                $children = $node['children'] ?? []; $child_path = $path . '/children';
            } elseif ( in_array( $this->builder, [ 'gutenberg', 'divi5' ], true ) ) {
                $name = $node['blockName'] ?? '';
                if ( 'divi5' === $this->builder ) {
                    $definition = function_exists( 'nova_divi5_field_definitions' ) ? nova_divi5_field_definitions( $name ) : null;
                    foreach ( (array) ( $definition['fields'] ?? [] ) as $key => $field ) {
                        if ( empty( $field['path'] ) ) { continue; }
                        $resolved = nova_divi5_resolve_field( [ 'name' => $name, 'attributes' => $node['attrs'], 'self_closing' => empty( $node['innerBlocks'] ) && '' === $node['innerHTML'] ], $key );
                        foreach ( $resolved['variants'] ?? [] as $breakpoint => $variant ) { $target = $path . '/attrs/' . implode( '/', $variant['path'] ); $add( $target, 'html' === $field['kind'] ? 'html' : ( 'url' === $field['kind'] ? 'url' : 'text' ), $key ); }
                    }
                } elseif ( in_array( $name, [ 'core/heading', 'core/paragraph', 'core/list-item', 'core/html', 'core/quote', 'core/preformatted' ], true ) && empty( $node['innerBlocks'] ) ) {
                    $add( $path . '/innerHTML', 'core/heading' === $name ? 'text' : 'html', 'core/heading' === $name ? 'heading' : 'body' );
                } elseif ( 'core/image' === $name && empty( $node['innerBlocks'] ) ) {
                    $image_url = self::html_attribute( $node['innerHTML'], 'img', 'src', $found_image );
                    if ( $found_image ) { $add( $path . '/innerHTML', 'url', 'image_url', $image_url ); }
                } elseif ( 'core/button' === $name && empty( $node['innerBlocks'] ) ) {
                    if ( preg_match( '/<a\b[^>]*>([\s\S]*?)<\/a>/i', $node['innerHTML'], $button ) ) { $add( $path . '/innerHTML', 'text', 'button_text', html_entity_decode( strip_tags( $button[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); }
                    if ( isset( $node['attrs']['url'] ) && is_string( $node['attrs']['url'] ) ) { $add( $path . '/attrs/url', 'url', 'link_url' ); }
                } elseif ( 'core/details' === $name && isset( $node['innerContent'][0] ) && preg_match( '/<summary\b/i', $node['innerContent'][0] ) ) {
                    $add( $path . '/innerContent/0', 'text', 'question' );
                }
                $children = $node['innerBlocks'] ?? []; $child_path = $path . '/innerBlocks';
            } elseif ( 'beaver' === $this->builder ) {
                $settings = $node['settings'] ?? [];
                if ( 'module' === ( $node['type'] ?? '' ) && is_array( $settings ) ) {
                    foreach ( [ 'heading', 'text', 'label', 'link', 'caption', 'alt' ] as $key ) { if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) { $add( $path . '/settings/' . $key, 'text' === $key ? 'html' : ( 'link' === $key ? 'url' : 'text' ), $key ); } }
                    if ( 'photo' === ( $settings['type'] ?? '' ) && 'url' === ( $settings['photo_source'] ?? '' ) && isset( $settings['photo_url'] ) && is_string( $settings['photo_url'] ) ) { $add( $path . '/settings/photo_url', 'url', 'image_url' ); }
                    if ( in_array( $settings['type'] ?? '', [ 'accordion', 'tabs' ], true ) && is_array( $settings['items'] ?? null ) ) { $this->walk_inventory( $settings['items'], $path . '/settings/items', $result, $node_protected, $node_context . ' > FAQ items', [ 'node' => $node, 'path' => $path ] ); }
                }
                $children = $node['children'] ?? []; $child_path = $path . '/children';
            } else {
                $props = $node['data']['properties'] ?? []; $type = $node['data']['type'] ?? '';
                if ( in_array( $type, [ 'EssentialElements\\Heading', 'EssentialElements\\Text', 'EssentialElements\\Button' ], true ) && isset( $props['content']['content']['text'] ) ) { $add( $path . '/data/properties/content/content/text', 'EssentialElements\\Text' === $type ? 'html' : 'text', 'EssentialElements\\Heading' === $type ? 'heading' : ( 'EssentialElements\\Button' === $type ? 'button_text' : 'body' ) ); }
                if ( in_array( $type, [ 'EssentialElements\\Button', 'EssentialElements\\Heading', 'EssentialElements\\Image' ], true ) ) {
                    $scan_urls = function ( array $values, string $pointer, bool $media = false ) use ( &$scan_urls, $add ) {
                        if ( ! empty( $values['dynamic'] ) || ! empty( $values['global'] ) ) { return; }
                        foreach ( $values as $key => $value ) {
                            if ( in_array( $key, [ 'dynamic', 'global', 'conditions', 'attributes' ], true ) ) { continue; }
                            if ( is_array( $value ) ) { if ( in_array( $key, [ 'link', 'image' ], true ) ) { if ( 'image' === $key && ! empty( $value['id'] ) ) { continue; } $scan_urls( $value, $pointer . '/' . $key, true ); } elseif ( ! $media && 'content' === $key ) { $scan_urls( $value, $pointer . '/' . $key ); } }
                            elseif ( $media && 'url' === $key && is_string( $value ) ) { $add( $pointer . '/url', 'url', false !== strpos( $pointer, '/image' ) ? 'image_url' : 'link_url' ); }
                        }
                    };
                    $scan_urls( $props['content'] ?? [], $path . '/data/properties/content' );
                }
                if ( 'EssentialElements\\FrequentlyAskedQuestions' === $type ) {
                    $collection = isset( $props['content']['settings']['items'] ) ? 'items' : 'questions';
                    if ( is_array( $props['content']['settings'][ $collection ] ?? null ) ) { $this->walk_inventory( $props['content']['settings'][ $collection ], $path . '/data/properties/content/settings/' . $collection, $result, $node_protected, $node_context . ' > FAQ items', [ 'node' => $node, 'path' => $path ] ); }
                }
                $children = $node['children'] ?? []; $child_path = $path . '/children';
            }
            if ( is_array( $children ) && $this->structural( $native_tag ) ) { $this->walk_inventory( $children, $child_path, $result, $node_protected, $node_context ); }
        }
        if ( ! $protected ) { $result['containers'][] = [ 'target' => $prefix, 'label' => '' === $prefix || '/document' === $prefix ? 'Complete layout' : $context . ' · content region', 'items' => $items ]; }
    }

    public function validate_binding( array $native, array $binding ) {
        foreach ( $this->fields( $native ) as $field ) {
            $format = $binding['format'] ?? 'text';
            $heading = in_array( $field['kind'], [ 'heading', 'question' ], true ) || ( 'divi5' === $this->builder && self::heading_html( (string) $field['value'] ) );
            if ( ! empty( $field['writable'] ) && ( $binding['target'] ?? '' ) === $field['target'] && ( $format === $field['format'] || $heading && 'html' === $format ) ) { return true; }
        }
        return Nova_Bridge_Suite_Recipe_Builders::error( 'binding', 'Choose an exposed native editorial value with its declared format.' );
    }

    public function validate_region( array $native, array $region ) {
        foreach ( $this->containers( $native ) as $container ) { if ( ( $region['target'] ?? null ) === $container['target'] ) { return true; } }
        return Nova_Bridge_Suite_Recipe_Builders::error( 'region', 'Choose an exposed ordered native child region.' );
    }

    public function instantiate( array $prototype_native, string $seed, array $context = [] ) { return [ 'native' => $prototype_native, 'context' => $context ]; }

    public function bind( array $native, array $binding, $value, array $context = [] ) {
        $checked = $this->validate_binding( $native, $binding ); if ( is_wp_error( $checked ) ) { return $checked; }
        $target = $binding['target'];
        $original = Nova_Bridge_Suite_Recipe_Engine::get( $native, $target );
        $heading = ( 'divi4' === $this->builder || 'divi5' === $this->builder ) && self::heading_html( (string) $original );
        if ( $heading ) {
            $value = self::inline_value( (string) $value, true ); if ( is_wp_error( $value ) ) { return $value; }
            $value = self::inside_wrapper( $original, $value, 'text' === $binding['format'] );
        }
        if ( 'gutenberg' === $this->builder && '/innerHTML' === substr( $target, -10 ) ) {
            $parent = Nova_Bridge_Suite_Recipe_Engine::get( $native, substr( $target, 0, -10 ) );
            if ( 'core/image' === $parent['blockName'] ) {
                $current_url = self::html_attribute( $original, 'img', 'src', $found );
                $value = $current_url === $value ? $original : self::replace_html_attribute( $original, 'img', 'src', (string) $value );
                if ( $current_url !== self::html_attribute( $value, 'img', 'src' ) ) {
                    $old_id = $parent['attrs']['id'] ?? null; unset( $parent['attrs']['id'] );
                    if ( isset( $parent['attrs']['url'] ) ) { $parent['attrs']['url'] = self::html_attribute( $value, 'img', 'src' ); }
                    if ( $old_id ) { $value = preg_replace_callback( '/(<img\b[^>]*\sclass\s*=\s*)([\x22\x27])(.*?)\2/is', static function ( $match ) use ( $old_id ) { $classes = preg_split( '/\s+/', $match[3] ); $classes = array_filter( $classes, static function ( $class ) use ( $old_id ) { return '' !== $class && 'wp-image-' . $old_id !== $class; } ); return $match[1] . $match[2] . implode( ' ', $classes ) . $match[2]; }, $value ); }
                    Nova_Bridge_Suite_Recipe_Engine::put( $native, substr( $target, 0, -10 ), $parent );
                }
            } elseif ( 'core/button' === $parent['blockName'] ) {
                preg_match( '/<a\b[^>]*>([\s\S]*?)<\/a>/i', $original, $button );
                $current_text = html_entity_decode( strip_tags( $button[1] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                $value = $current_text === $value ? $original : preg_replace_callback( '/(<a\b[^>]*>)[\s\S]*?(<\/a>)/i', static function ( $match ) use ( $value ) { return $match[1] . esc_html( (string) $value ) . $match[2]; }, $original, 1 );
            } elseif ( 'core/html' !== $parent['blockName'] ) {
                $value = self::inline_value( (string) $value, 'core/heading' === $parent['blockName'] ); if ( is_wp_error( $value ) ) { return $value; }
                $value = self::inside_wrapper( $original, $value, 'text' === $binding['format'] );
            }
        } elseif ( 'gutenberg' === $this->builder && '/innerContent/0' === substr( $target, -15 ) ) {
            $value = preg_replace_callback( '/(<summary\b[^>]*>)[\s\S]*?(<\/summary>)/i', static function ( $match ) use ( $value ) { return $match[1] . esc_html( (string) $value ) . $match[2]; }, (string) $original );
        }
        if ( 'wpbakery' === $this->builder && '/attributes/link' === substr( $target, -16 ) ) {
            $value = nova_wpb_packed_link_url( $original ) === $value ? $original : preg_replace_callback( '/(^|\|)url:[^|]*/', static function ( $match ) use ( $value ) { return $match[1] . 'url:' . rawurlencode( (string) $value ); }, (string) $original, 1 );
        }
        if ( 'avada' === $this->builder && '/text' === substr( $target, -5 ) ) { $parent = Nova_Bridge_Suite_Recipe_Engine::get( $native, substr( $target, 0, -5 ) ); if ( 'fusion_button' === $parent['tag'] ) { $value = esc_html( (string) $value ); } }
        if ( 'divi4' === $this->builder && false !== strpos( $target, '/attributes/' ) ) { $value = nova_divi_encode_attr( (string) $value ); }
        Nova_Bridge_Suite_Recipe_Engine::put( $native, $target, $value );
        if ( 'breakdance' === $this->builder && false !== strpos( $target, '/content/settings/' ) ) {
            $other = preg_replace_callback( '#/content/settings/(items|questions)/#', static function ( $match ) { return '/content/settings/' . ( 'items' === $match[1] ? 'questions' : 'items' ) . '/'; }, $target );
            Nova_Bridge_Suite_Recipe_Engine::get( $native, $other, $found ); if ( $other !== $target && $found ) { Nova_Bridge_Suite_Recipe_Engine::put( $native, $other, $value ); }
        }
        if ( 'gutenberg' === $this->builder && '/innerHTML' === substr( $target, -10 ) ) { Nova_Bridge_Suite_Recipe_Engine::put( $native, substr( $target, 0, -10 ) . '/innerContent', [ $value ] ); }
        elseif ( 'gutenberg' === $this->builder && '/attrs/url' === substr( $target, -10 ) ) {
            $parent_path = substr( $target, 0, -10 ); $node = Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent_path );
            if ( 'core/button' === $node['blockName'] ) { $node['innerHTML'] = self::replace_html_attribute( $node['innerHTML'], 'a', 'href', (string) $value ); $node['innerContent'] = [ $node['innerHTML'] ]; Nova_Bridge_Suite_Recipe_Engine::put( $native, $parent_path, $node ); }
        }
        elseif ( 'gutenberg' === $this->builder && '/innerContent/0' === substr( $target, -15 ) ) {
            $parent_path = substr( $target, 0, -15 ); $node = Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent_path );
            $node['innerHTML'] = implode( '', array_filter( $node['innerContent'], 'is_string' ) ); Nova_Bridge_Suite_Recipe_Engine::put( $native, $parent_path, $node );
        }
        return $native;
    }

    /** Parse one quoted native HTML carrier; ambiguous images/links remain opaque. */
    private static function html_attribute( string $html, string $tag, string $attribute, &$found = null ): string {
        $found = false;
        if ( 1 !== preg_match_all( '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', $html, $tags ) ) { return ''; }
        if ( 1 !== preg_match_all( '/\s' . preg_quote( $attribute, '/' ) . '\s*=\s*([\x22\x27])(.*?)\1/is', $tags[0][0], $matches ) ) { return ''; }
        $found = true; return html_entity_decode( $matches[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }
    private static function replace_html_attribute( string $html, string $tag, string $attribute, string $value ): string {
        return preg_replace_callback( '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/i', static function ( $match ) use ( $attribute, $value ) {
            return preg_replace_callback( '/(\s' . preg_quote( $attribute, '/' ) . '\s*=\s*)([\x22\x27])(.*?)\2/is', static function ( $part ) use ( $value ) { return $part[1] . $part[2] . htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . $part[2]; }, $match[0], 1 );
        }, $html, 1 );
    }

    private static function inline_value( string $value, bool $heading = false ) {
        $tag = $heading ? 'h[1-6]' : 'p|li|blockquote|pre';
        if ( preg_match( '/\A\s*<(' . $tag . ')\b[^>]*>([\s\S]*?)<\/\1>\s*\z/i', $value, $outer ) ) { $value = $outer[2]; }
        if ( preg_match( '/<\/?(?:h[1-6]|p|div|section|article|main|table|ul|ol|li|blockquote|pre|details)\b/i', $value ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'value', 'This native text slot accepts one heading or paragraph with inline content. Use a rich-text block or repeat a paragraph prototype for multiple blocks.' ); }
        return $value;
    }

    public function replace_region( array $native, array $region, array $children, array $context = [] ) {
        $checked = $this->validate_region( $native, $region ); if ( is_wp_error( $checked ) ) { return $checked; }
        Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children );
        if ( 'breakdance' === $this->builder && preg_match( '#/content/settings/(items|questions)$#D', $region['target'] ) ) {
            $other = preg_replace_callback( '#/content/settings/(items|questions)$#D', static function ( $match ) { return '/content/settings/' . ( 'items' === $match[1] ? 'questions' : 'items' ); }, $region['target'] );
            Nova_Bridge_Suite_Recipe_Engine::get( $native, $other, $found ); if ( $found ) { Nova_Bridge_Suite_Recipe_Engine::put( $native, $other, Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'] ) ); }
        }
        if ( in_array( $this->builder, [ 'gutenberg', 'divi5' ], true ) && '/innerBlocks' === substr( $region['target'], -12 ) ) {
            $parent = substr( $region['target'], 0, -12 );
            $content = Nova_Bridge_Suite_Recipe_Engine::get( $native, $parent . '/innerContent' );
            $children_now = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'] );
            $updated = []; $ordinal = 0; $inserted = false;
            foreach ( $content as $chunk ) {
                if ( null !== $chunk ) { $updated[] = $chunk; continue; }
                if ( $ordinal === $region['start'] ) { $updated = array_merge( $updated, array_fill( 0, count( $children ), null ) ); $inserted = true; }
                if ( $ordinal < $region['start'] || $ordinal >= $region['start'] + $region['count'] ) { $updated[] = null; }
                $ordinal++;
            }
            if ( ! $inserted && $region['start'] === $ordinal ) { array_splice( $updated, max( 0, count( $updated ) - 1 ), 0, array_fill( 0, count( $children ), null ) ); }
            Nova_Bridge_Suite_Recipe_Engine::put( $native, $parent . '/innerContent', $updated );
        }
        return $native;
    }

    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $checked = $this->validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; }
        $document = $blueprint['document']; $payload = [ 'meta' => $blueprint['meta'] ?? [] ];
        if ( ! empty( $blueprint['template'] ) ) { $payload['meta']['_wp_page_template'] = $blueprint['template']; }
        if ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
            $this->shortcode_helpers(); $document = nova_wpb_compact_to_shortcodes( $document ); $payload['layout'] = [ 'raw_shortcodes' => $document ];
        } elseif ( in_array( $this->builder, [ 'gutenberg', 'divi5' ], true ) ) {
            if ( ! function_exists( 'serialize_blocks' ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The native WordPress block serializer is unavailable.' ); }
            $document = serialize_blocks( $document );
            if ( 'divi5' === $this->builder ) { $checked = nova_divi5_validate_create_document( $document ); if ( is_wp_error( $checked ) ) { return $checked; } $payload['layout'] = [ 'native_document' => $document ]; }
            else { $payload['native_document'] = $document; }
        } elseif ( 'beaver' === $this->builder ) {
            if ( ! function_exists( 'nova_bb_tree_to_flat' ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The native Beaver bridge is unavailable.' ); }
            $tree = $this->beaver_objects( $document, $seed ); $document = self::arrays( nova_bb_tree_to_flat( $tree, false ) ); $payload['layout'] = [ 'nodes' => $document ];
        } else {
            if ( ! class_exists( 'Nova_BD_Utils' ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The native Breakdance bridge is unavailable.' ); }
            $next_id = 1; $root = $this->breakdance_ids( $document[0], $next_id ); $envelope = $blueprint['envelope'] ?? [];
            $wrapper = [ 'decoded' => $envelope['decoded'] ?? [], 'inner' => array_merge( $envelope['inner'] ?? [], [ '_nextNodeId' => $next_id ] ), 'root_source' => $envelope['root_source'] ?? 'tree_json_string', 'raw' => ! empty( $envelope['raw_array'] ) ? [] : '' ];
            $document = Nova_BD_Utils::encode_breakdance_tree( $wrapper, $root ); $payload['layout'] = [ 'raw_data' => $document ];
        }
        $count = 0; $this->validate_nodes( $blueprint['document'], 0, $count );
        return [ 'native_document' => $document, 'writer_payload' => $payload, 'preview_html' => $this->preview( $blueprint ), 'warnings' => [], 'summary' => [ 'nodes' => $count, 'builder' => $this->builder ] ];
    }

    private function beaver_objects( array $nodes, string $seed, string $prefix = '' ): array {
        foreach ( $nodes as $index => &$node ) {
            $path = $prefix . '/' . $index;
            $node['node_id'] = substr( hash( 'sha256', $seed . $path ), 0, 13 );
            if ( is_array( $node['orig'] ?? null ) ) { $node['orig'] = (object) $node['orig']; }
            $node['children'] = $this->beaver_objects( $node['children'], $seed, $path );
        }
        unset( $node ); return $nodes;
    }
    private function breakdance_ids( array $node, int &$next_id ): array {
        $node['id'] = $next_id++;
        foreach ( $node['children'] as &$child ) { $child = $this->breakdance_ids( $child, $next_id ); }
        unset( $child ); return $node;
    }
    private function preview( array $blueprint ): string {
        $html = '';
        foreach ( $this->fields( $blueprint ) as $field ) {
            $value = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $field['target'] );
            if ( ! is_string( $value ) || '' === trim( $value ) ) { continue; }
            if ( 'divi4' === $this->builder && false !== strpos( $field['target'], '/attributes/' ) ) { $value = nova_divi_decode_attr( $value ); }
            $html .= 'html' === $field['format'] ? wp_kses_post( $value ) : '<p>' . esc_html( $value ) . '</p>';
        }
        return $html;
    }

    public function write_draft( array $payload, array $post_fields ) {
        if ( ! $this->available() ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'provider', 'The selected native builder and bridge must be loaded before writing a draft.', 503 ); }
        $type = $post_fields['post_type'] ?? 'page'; $object = get_post_type_object( $type );
        if ( ! current_user_can( 'manage_options' ) || ! $object || ! current_user_can( $object->cap->create_posts ?? $object->cap->edit_posts ) || ! current_user_can( $object->cap->edit_posts ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'permission', 'You do not have permission to create this draft.', 403 ); }
        $allowed = array_intersect_key( $post_fields, array_flip( [ 'title', 'slug', 'post_type', 'excerpt', 'parent_id', 'author' ] ) );
        $body = array_merge( $payload, $allowed, [ 'status' => 'draft', 'post_type' => $type ] );
        // Legacy bridge metadata setters expect slashed values; the public
        // compiled payload remains unslashed for stable identity and readback.
        if ( isset( $body['meta'] ) ) { $body['meta'] = wp_slash( $body['meta'] ); }
        $routes = [ 'gutenberg' => '/nova-gutenberg/v1/pages', 'wpbakery' => '/nova-wpbakery/v1/pages', 'avada' => '/nova-avada/v1/pages', 'divi4' => '/nova-divi/v1/pages', 'divi5' => '/nova-divi/v1/pages', 'beaver' => '/nova-beaver/v1/pages', 'breakdance' => '/nova-breakdance/v1/pages' ];
        $request = new WP_REST_Request( 'POST', $routes[ $this->builder ] ); $request->set_header( 'Content-Type', 'application/json' ); $request->set_body( wp_json_encode( $body ) );
        try {
            if ( 'avada' === $this->builder ) { $transformer = new NovaAvadaBridge\Layout_Transformer(); $writer = new NovaAvadaBridge\REST_Controller( new NovaAvadaBridge\Page_Service( $transformer ), $transformer ); $result = $writer->create_item( $request ); }
            elseif ( 'breakdance' === $this->builder ) { $result = Nova_BD_REST_Controller::create_page( $request ); }
            else { $callbacks = [ 'gutenberg' => 'nova_gut_create_page', 'wpbakery' => 'nova_wpb_create_page', 'divi4' => 'nova_divi_create_page', 'divi5' => 'nova_divi_create_page', 'beaver' => 'nova_bb_create_page' ]; $result = call_user_func( $callbacks[ $this->builder ], $request ); }
        } catch ( Throwable $error ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'write', 'The native builder failed to create the draft.', 500 ); }
        if ( is_wp_error( $result ) ) { return $result; }
        $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        $id = is_array( $data ) ? (int) ( $data['id'] ?? $data['post_id'] ?? 0 ) : 0; $post = $id ? get_post( $id ) : null;
        if ( ! $post || 'draft' !== $post->post_status || $type !== $post->post_type ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'write', 'The native writer did not return a verified draft.', 500 ); }
        $checked = $this->verify_saved( $id, $payload );
        if ( is_wp_error( $checked ) ) { return $checked; }
        return [ 'post_id' => $id, 'status' => 'draft', 'builder' => $this->builder, 'edit_url' => get_edit_post_link( $id, 'raw' ), 'preview_url' => get_preview_post_link( $id ), 'native_result' => $data ];
    }

    public function verify_saved( int $post_id, array $payload ) {
        $post = get_post( $post_id );
        if ( ! $post || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post_id ) ) { return Nova_Bridge_Suite_Recipe_Builders::error( 'permission', 'You cannot inspect this generated native draft.', 403 ); }
        $matches = false;
        if ( 'gutenberg' === $this->builder ) { $matches = (string) $post->post_content === ( $payload['native_document'] ?? null ); }
        elseif ( in_array( $this->builder, [ 'wpbakery', 'avada', 'divi4' ], true ) ) {
            $expected = $payload['layout']['raw_shortcodes'] ?? null;
            $matches = is_string( $expected ) && (string) $post->post_content === $expected;
            if ( $matches && 'avada' === $this->builder ) { $matches = (string) get_post_meta( $post_id, '_fusion_builder_shortcodes', true ) === $expected && (string) get_post_meta( $post_id, '_fusion_builder_content', true ) === $expected; }
        } elseif ( 'divi5' === $this->builder ) { $matches = (string) $post->post_content === ( $payload['layout']['native_document'] ?? null ) && 'on' === get_post_meta( $post_id, '_et_pb_use_builder', true ); }
        elseif ( 'beaver' === $this->builder ) { $matches = Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( get_post_meta( $post_id, '_fl_builder_data', true ) ) ) === Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( $payload['layout']['nodes'] ?? null ) ) && (bool) get_post_meta( $post_id, '_fl_builder_enabled', true ); }
        else {
            $expected = Nova_BD_Utils::decode_breakdance_tree( $payload['layout']['raw_data'] ?? '' );
            $stored = Nova_BD_Utils::decode_breakdance_tree( Nova_BD_Utils::get_raw_breakdance_data( $post_id ) );
            if ( $expected && $stored ) { $matches = Nova_Bridge_Suite_Recipe_Engine::digest( $expected['root'] ) === Nova_Bridge_Suite_Recipe_Engine::digest( $stored['root'] ) && Nova_Bridge_Suite_Recipe_Engine::digest( $expected['decoded'] ) === Nova_Bridge_Suite_Recipe_Engine::digest( $stored['decoded'] ); }
        }
        if ( $matches ) {
            foreach ( (array) ( $payload['meta'] ?? [] ) as $key => $value ) {
                if ( Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( get_post_meta( $post_id, $key, true ) ) ) !== Nova_Bridge_Suite_Recipe_Engine::digest( self::arrays( $value ) ) ) { $matches = false; break; }
            }
        }
        return $matches ? true : new WP_Error( 'nova_recipe_native_mismatch', 'The saved native layout does not match the compiled document. Retain this item for review; do not create another draft automatically.', [ 'status' => 409, 'post_id' => $post_id ] );
    }
}
