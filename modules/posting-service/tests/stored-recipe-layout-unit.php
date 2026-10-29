<?php
/** Pure saved-layout projection/compilation with real WordPress block serialization. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run standalone only.' ); }
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/blocks.php' ) || ! is_file( $core . '/class-wp-block-parser.php' ) ) {
    echo "SKIP: set NOVA_WP_CORE_FIXTURE to a disposable copy of WordPress blocks.php and its parser.\n";
    exit( 0 );
}
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_kses_post( $value ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function current_user_can( ...$args ) { return true; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'edit_posts' => 'edit_posts' ] ]; }
function no_live_io(): void { ++$GLOBALS['live_io_attempts']; throw new RuntimeException( 'Saved recipe compilation must not read an example, write data, or call HTTP.' ); }
function get_post( ...$args ) { no_live_io(); }
function get_post_meta( ...$args ) { no_live_io(); }
function metadata_exists( ...$args ) { no_live_io(); }
function get_page_template_slug( ...$args ) { no_live_io(); }
function get_option( ...$args ) { no_live_io(); }
function wp_insert_post( ...$args ) { no_live_io(); }
function wp_update_post( ...$args ) { no_live_io(); }
function update_post_meta( ...$args ) { no_live_io(); }
function update_option( ...$args ) { no_live_io(); }
function wp_remote_request( ...$args ) { no_live_io(); }
function wp_remote_get( ...$args ) { no_live_io(); }
function wp_remote_post( ...$args ) { no_live_io(); }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { return implode( '', array_slice( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ), $start, $length ) ); } }
require $core . '/class-wp-block-parser.php';
require $core . '/blocks.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
use Nova_Bridge_Suite_Stored_Layout as Layout;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
$checks = 0; $live_io_attempts = 0;
function check( $truth, string $message ): void { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function good( $result, string $message ) { check( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) ); return $result; }
function binding( string $target, string $format, string $instructions ): array {
    return [ 'target' => $target, 'source' => '/body', 'format' => $format, 'instructions' => $instructions, 'label' => 'Editorial field ' . $target, 'required' => true ];
}
function record( string $builder, string $type, array $blueprint, array $bindings ): array {
    return [ 'id' => '11111111-1111-4111-8111-111111111111', 'revision' => 9, 'builder' => $builder,
        'resource_id' => 'post_type:' . $type, 'posting_page_type' => 'informative', 'label' => 'Saved ' . $builder . ' design',
        'reference_id' => 987654321, // This source is deliberately absent; compilation has only the saved blueprint.
        'recipe' => [ 'builder' => $builder, 'mode' => 'static', 'blueprint' => $blueprint,
            'instructions' => 'Keep every paragraph in its original order. Do not repeat the heading in the body.',
            'bindings' => $bindings, 'regions' => [], 'custom_fields' => [] ] ];
}
function fitted( array $by_path ): array { $values = []; foreach ( $by_path as $path => $value ) { $values[Writing::field_id( $path )] = $value; } return $values; }
function check_projection( array $draft, array $record ): void {
    check( $draft['reference_id'] === 0 && $draft['revision'] === $record['id'] . ':9', 'Saved recipe identity does not retain a live example dependency' );
    check( $draft['routing']['post_type'] === substr( $record['resource_id'], 10 ) && $draft['routing']['operation'] === 'create', 'Selected native post type survives projection' );
    check( $draft['profile_page_type'] === 'informative' && $draft['guidance'] === $record['recipe']['instructions'], 'NOVA page type and template instructions survive exactly' );
    check( $draft['stored_layout']['blueprint'] === $record['recipe']['blueprint'], 'Projection retains the complete saved native blueprint' );
    foreach ( $record['recipe']['bindings'] as $binding ) {
        $field = $draft['fields'][$binding['target']];
        check( $field['instructions'] === $binding['instructions'] && $field['description']['label'] === $binding['label'], 'Field instructions and human label survive projection' );
        check( $field['description']['value_type'] === ( 'html' === $binding['format'] ? 'rich_text' : 'text' ), 'The editorial field retains its intended format' );
    }
}

$elementor = [ 'template' => 'elementor_canvas', 'meta' => [ 'background_color' => '#eef2f8', '__globals__' => [ 'background_color' => 'globals/colors?id=primary' ] ],
    'document' => [ [ 'id' => 'aaaaaaa', 'elType' => 'container', 'settings' => [ 'content_width' => 'full', 'padding_mobile' => [ 'unit' => 'px', 'top' => 24 ] ], 'elements' => [
        [ 'id' => 'bbbbbbb', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Example title', 'header_size' => 'h2', 'typography_font_size' => [ 'size' => 38.5, 'unit' => 'px' ] ], 'elements' => [] ],
        [ 'id' => 'ccccccc', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Example body</p>' ], 'elements' => [] ],
    ] ] ] ];
$headline = '/document/0/elements/0/settings/title'; $body = '/document/0/elements/1/settings/editor';
foreach ( [ 'post', 'case_study' ] as $type ) {
    $saved = record( 'elementor', $type, $elementor, [ binding( $headline, 'text', 'Use the section heading once.' ), binding( $body, 'html', 'Place the article body here without duplicating the heading.' ) ] );
    $draft = good( Layout::from_recipe( $saved ), 'Elementor projects for ' . $type );
    check_projection( $draft, $saved );
    $values = fitted( [ '/title' => 'New native title', $headline => 'New section heading', $body => '<p>First paragraph.</p><p>Second paragraph.</p>' ] );
    $compiled = good( Layout::compile( $draft, $values, 'elementor-' . $type ), 'Elementor compiles for ' . $type );
    check( $compiled['builder'] === 'elementor' && $compiled['post_type'] === $type, 'Elementor does not coerce Posts or arbitrary CPTs into Pages' );
    check( $compiled['post_fields']['title'] === 'New native title', 'Elementor retains the fitted native title' );
    $out = $compiled['writer_payload'];
    check( $out['elementor_data'][0]['elements'][0]['settings']['title'] === 'New section heading' && $out['elementor_data'][0]['elements'][1]['settings']['editor'] === '<p>First paragraph.</p><p>Second paragraph.</p>', 'Both fitted editorial values replace the saved example values' );
    check( $out['elementor_data'][0]['settings'] === $elementor['document'][0]['settings'] && $out['elementor_data'][0]['elements'][0]['settings']['typography_font_size']['size'] === 38.5, 'Elementor responsive settings and fractional typography survive' );
    check( $out['elementor_page_settings'] === $elementor['meta'] && $out['template'] === 'elementor_canvas', 'Elementor native document settings and template survive' );
    check( $out['elementor_data'][0]['id'] !== 'aaaaaaa', 'Each compiled Elementor item gets independent element identities' );
    check( $compiled === good( Layout::compile( $draft, $values, 'elementor-' . $type ), 'Retained Elementor operation recompiles' ), 'Retained operation compilation is deterministic' );
    $wrong = $draft; $wrong['routing']['post_type'] = 'page';
    check( is_wp_error( Layout::compile( $wrong, $values, 'wrong-destination' ) ), 'A changed destination cannot silently replace the selected native post type' );
}

$core_blueprint = [ 'resource_id' => 'post_type:post', 'type' => 'post_type', 'post_type' => 'post', 'template' => 'templates/full-width.php',
    'document' => [ 'title' => 'Example title', 'excerpt' => 'Example excerpt', 'intro' => '<p>Example introduction</p>', 'sections' => [ [ 'heading' => 'Example section', 'body' => '<p>Example paragraph</p>' ] ], 'faqs' => [] ] ];
$saved = record( 'core', 'post', $core_blueprint, [ binding( '/document/title', 'text', 'Use the document title with the requested product name.' ), binding( '/document/intro', 'html', 'Use the introduction first.' ), binding( '/document/sections/0/heading', 'text', 'Use the next section heading.' ), binding( '/document/sections/0/body', 'html', 'Keep all remaining paragraphs in order.' ) ] );
$draft = good( Layout::from_recipe( $saved ), 'Core native content projects without an example' );
check_projection( $draft, $saved );
check( count( array_intersect( [ '/title', '/document/title' ], array_keys( $draft['fields'] ) ) ) === 1 && isset( $draft['fields']['/document/title'] ), 'Core exposes only the configured native title, without a competing synthetic title' );
check( ! isset( $draft['target_descriptors']['/title'] ) && $draft['fields']['/document/title']['instructions'] === 'Use the document title with the requested product name.', 'Core title instructions stay attached to the sole authoritative title destination' );
check( ! isset( $draft['fields']['/excerpt'] ) && ! isset( $draft['fields']['/document/excerpt'] ), 'The adapter does not expose a synthetic editable excerpt when none is configured' );
$values = fitted( [ '/document/title' => 'Requested product article title', '/document/intro' => '<p>Introduction.</p>', '/document/sections/0/heading' => 'Article section', '/document/sections/0/body' => '<p>First body paragraph.</p><p>Second body paragraph.</p>' ] );
$compiled = good( Layout::compile( $draft, $values, 'core-post' ), 'Core native content compiles from the stored recipe' );
check( $compiled['builder'] === 'core' && $compiled['post_type'] === 'post', 'Core compiler preserves the standard Posts destination' );
check( $compiled['writer_payload']['content'] === '<p>Introduction.</p><h2>Article section</h2><p>First body paragraph.</p><p>Second body paragraph.</p>', 'Core output keeps fitted content in the saved native field order' );
check( $compiled['writer_payload']['template'] === 'templates/full-width.php', 'Core native template survives compilation' );
check( $compiled['post_fields']['title'] === 'Requested product article title', 'Core configured title becomes the authoritative native post value used by the writer' );
check( $compiled['post_fields']['excerpt'] === 'Example excerpt', 'An unmapped static Core excerpt remains preserved from the saved blueprint' );
$extra_title = $values; $extra_title[Writing::field_id( '/title' )] = 'Unconfigured competing title';
check( is_wp_error( Layout::compile( $draft, $extra_title, 'core-extra-title' ) ), 'An unconfigured synthetic title cannot silently override the instructed native title' );
$wrong = $saved; $wrong['recipe']['blueprint']['post_type'] = 'page';
check( is_wp_error( Layout::from_recipe( $wrong ) ), 'A core blueprint for another post type is rejected before synchronization' );

$block_markup = '<!-- wp:group {"layout":{"type":"constrained","contentSize":"1180px"},"className":"retained-layout"} --><div class="wp-block-group retained-layout"><!-- wp:heading {"level":2,"className":"retained-heading"} --><h2 class="wp-block-heading retained-heading">Example heading</h2><!-- /wp:heading --><!-- wp:html --><p>Example paragraph</p><!-- /wp:html --></div><!-- /wp:group -->';
$gutenberg = [ 'document' => parse_blocks( $block_markup ), 'template' => 'templates/full-width.php', 'meta' => [ 'retained_design' => [ 'width' => 'wide', 'spacing' => 24 ] ] ];
$headline = '/document/0/innerBlocks/0/innerHTML'; $body = '/document/0/innerBlocks/1/innerHTML';
$saved = record( 'gutenberg', 'case_study', $gutenberg, [ binding( $headline, 'text', 'Place the section heading here exactly once.' ), binding( $body, 'html', 'Keep the body paragraphs in source order.' ) ] );
$draft = good( Layout::from_recipe( $saved ), 'Gutenberg nested blocks project from the saved native document' );
check_projection( $draft, $saved );
$compiled = good( Layout::compile( $draft, fitted( [ '/title' => 'Block article', $headline => 'New block heading', $body => '<p>New first paragraph.</p><p>New second paragraph.</p>' ] ), 'gutenberg-cpt' ), 'Gutenberg compiles with the real WordPress serializer' );
check( $compiled['builder'] === 'gutenberg' && $compiled['post_type'] === 'case_study', 'Gutenberg retains its arbitrary native CPT destination' );
$blocks = parse_blocks( $compiled['writer_payload']['native_document'] );
check( $blocks[0]['attrs'] === $gutenberg['document'][0]['attrs'] && $blocks[0]['innerBlocks'][0]['attrs'] === $gutenberg['document'][0]['innerBlocks'][0]['attrs'], 'Nested Gutenberg layout and heading attributes survive serialization' );
check( $blocks[0]['innerBlocks'][0]['innerHTML'] === '<h2 class="wp-block-heading retained-heading">New block heading</h2>', 'Gutenberg binds a plain heading while retaining its native heading wrapper' );
check( $blocks[0]['innerBlocks'][1]['innerHTML'] === '<p>New first paragraph.</p><p>New second paragraph.</p>', 'Gutenberg rich content replaces the target body in source order' );
check( $compiled['writer_payload']['meta']['retained_design'] === $gutenberg['meta']['retained_design'] && $compiled['writer_payload']['meta']['_wp_page_template'] === 'templates/full-width.php', 'Gutenberg retains native design metadata and page template' );
check( $live_io_attempts === 0, 'Every projection and compilation stayed source independent and performed no database or HTTP operation' );
echo 'PASS ' . $checks . " generic stored-recipe layout assertions\n";
