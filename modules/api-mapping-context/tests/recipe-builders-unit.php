<?php
/** Native adapter integration with the real WP parser/serializers and bridge helpers. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/blocks.php' ) || ! is_file( $core . '/shortcodes.php' ) ) { echo "SKIP: set NOVA_WP_CORE_FIXTURE to a disposable copy of the WordPress parser, blocks.php and shortcodes.php.\n"; exit( 0 ); }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response { private $data; public function __construct( $data, $status = 200 ) { $this->data = $data; } public function get_data() { return $this->data; } }
class WP_REST_Request { private $route; private $body = ''; private $headers = []; public function __construct( $method, $route ) { $this->route = $route; } public function set_header( $key, $value ) { $this->headers[$key] = $value; } public function set_body( $value ) { $this->body = $value; } public function get_body() { return $this->body; } public function get_json_params() { return json_decode( $this->body, true ); } public function get_route() { return $this->route; } public function get_param( $key ) { return $this->get_json_params()[$key] ?? null; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function apply_filters( $hook, $value ) { return $value; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); }
function wp_kses_post( $value ) { return preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function maybe_unserialize( $value ) { return $value; }
function current_user_can() { return true; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'edit_posts' ] ]; }
function get_post( $id ) { return $GLOBALS['recipe_posts'][ $id ] ?? null; }
function get_post_meta( $id, $key = '', $single = false ) { $meta = $GLOBALS['recipe_meta'][ $id ] ?? []; return '' === $key ? array_map( static function ( $value ) { return [ $value ]; }, $meta ) : ( $meta[ $key ] ?? '' ); }
function get_page_template_slug( $id ) { return 'templates/layout.php'; }
function wp_salt( $scheme ) { return 'native-integration-fixture-' . $scheme; }
function wp_generate_uuid4() { static $counter = 0; ++$counter; return sprintf( '%08x-1111-4111-8111-%012d', $counter, $counter ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_insert_post( $data, $return_error = false ) { static $id = 11000; $data = wp_unslash( $data ); $data['ID'] = ++$id; $GLOBALS['recipe_posts'][$id] = (object) $data; $GLOBALS['native_fixture_writes'] = ( $GLOBALS['native_fixture_writes'] ?? 0 ) + 1; return $id; }
function wp_delete_post( $id, $force = false ) { unset( $GLOBALS['recipe_posts'][$id], $GLOBALS['recipe_meta'][$id] ); return true; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['recipe_meta'][$id][$key] = wp_unslash( $value ); return true; }
function get_permalink( $id ) { return 'https://fixture.example/post-' . $id; }
function admin_url( $path ) { return 'https://fixture.example/wp-admin/' . $path; }
function get_edit_post_link( $id, $context = '' ) { return admin_url( 'post.php?post=' . $id . '&action=edit' ); }
function get_preview_post_link( $id ) { return get_permalink( $id ) . '?preview=true'; }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $value, $start, $length = null ) { return implode( '', array_slice( preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY ), $start, $length ) ); } }
require $core . '/class-wp-block-parser.php';
require $core . '/blocks.php';
require $core . '/shortcodes.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-builders.php';
Nova_Bridge_Suite_Recipe_Builders::register();
$assertions = 0;
function recipe_assert( $condition, $message ) { global $assertions; $assertions++; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function recipe_ok( $result, $message ) { recipe_assert( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) ); return $result; }
function recipe_for( $builder, $artifact, $mode = 'mixed' ) { return [ 'format' => 'nova-layout-recipe/v1', 'builder' => $builder, 'mode' => $mode, 'label' => 'Native fixture', 'instructions' => '', 'blueprint' => $artifact['blueprint'], 'bindings' => $artifact['suggested_bindings'], 'regions' => 'static' === $mode ? [] : $artifact['suggested_regions'] ]; }
$input = [ 'title' => 'A real title', 'intro' => '<p>A real introduction.</p>', 'body' => '<p>Whole article.</p><p>Second paragraph.</p>',
    'sections' => [ [ 'title' => 'First section', 'body' => '<p>First paragraph.</p><p>Another paragraph.</p>' ], [ 'title' => 'Second section', 'body' => '<table><tbody><tr><td>Kept table</td></tr></tbody></table>' ] ],
    'faqs' => [ [ 'question' => 'First question?', 'answer' => '<p>First answer.</p>' ], [ 'question' => 'Second question?', 'answer' => '<p>Second answer.</p>' ] ] ];
foreach ( [ 'gutenberg', 'wpbakery', 'avada', 'divi4', 'divi5', 'beaver', 'breakdance' ] as $builder ) {
    $adapter = Nova_Bridge_Suite_Recipe_Builders::adapter( $builder );
    $artifact = recipe_ok( $adapter->starter(), $builder . ' starter' );
    $recipe = recipe_for( $builder, $artifact );
    recipe_ok( Nova_Bridge_Suite_Recipe_Engine::validate( $recipe ), $builder . ' validates full hinted recipe' );
    $compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $input, 'native-fixture' ), $builder . ' compiles repeated sections and FAQs' );
    $native = wp_json_encode( $compiled['native_document'] );
    foreach ( [ 'A real title', 'First section', 'Second section', 'First question?', 'Second question?', 'Kept table' ] as $expected ) { recipe_assert( false !== strpos( $native, $expected ), $builder . ' native output contains ' . $expected ); }
    recipe_assert( false === strpos( $native, 'Example heading' ), $builder . ' removes replaced starter headings' );
    recipe_assert( ! empty( $compiled['summary']['nodes'] ), $builder . ' reports complete node count' );
    recipe_assert( Nova_Bridge_Suite_Recipe_Engine::digest( $compiled['writer_payload'] ) === Nova_Bridge_Suite_Recipe_Engine::digest( recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $input, 'native-fixture' ), $builder . ' repeat compile' )['writer_payload'] ), $builder . ' stable native output for a stable identity' );
    $empty = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, [ 'title' => 'Only heading', 'intro' => '<p>Only intro.</p>', 'sections' => [], 'faqs' => [] ], 'empty-fixture' ), $builder . ' optional empty collections' );
    recipe_assert( false === strpos( wp_json_encode( $empty['native_document'] ), 'Question?' ), $builder . ' removes whole empty FAQ frame' );
    $static = $adapter->starter( 'static' );
    $static_result = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( recipe_for( $builder, $static, 'static' ), $input, 'static-fixture' ), $builder . ' whole body static template' );
    recipe_assert( false !== strpos( wp_json_encode( $static_result['native_document'] ), 'Second paragraph.' ), $builder . ' retains multi-paragraph static body' );
}

$gutenberg = Nova_Bridge_Suite_Recipe_Builders::adapter( 'gutenberg' );
$fixture = $gutenberg->starter( 'static' )['blueprint'];
$fixture['document'][0]['innerBlocks'][0]['innerHTML'] = '<h2 class="wp-block-heading custom-heading" style="color:#123456">Old heading</h2>';
$fixture['document'][0]['innerBlocks'][0]['innerContent'] = [ $fixture['document'][0]['innerBlocks'][0]['innerHTML'] ];
$field = $gutenberg->fields( $fixture )[0];
$bound = recipe_ok( $gutenberg->bind( $fixture, [ 'target' => $field['target'], 'format' => 'text' ], 'New heading' ), 'native heading binding' );
recipe_assert( '<h2 class="wp-block-heading custom-heading" style="color:#123456">New heading</h2>' === $bound['document'][0]['innerBlocks'][0]['innerHTML'], 'Heading binding retains native tag, level, classes and style' );
$inline = recipe_ok( $gutenberg->bind( $fixture, [ 'target' => $field['target'], 'format' => 'html' ], '<em>Inline heading</em>' ), 'formatted native heading' );
recipe_assert( false !== strpos( $inline['document'][0]['innerBlocks'][0]['innerHTML'], '<em>Inline heading</em>' ), 'Native heading permits inline formatting without replacing its outer tag' );
recipe_assert( is_wp_error( $gutenberg->bind( $fixture, [ 'target' => $field['target'], 'format' => 'html' ], '<p>Invalid block heading</p>' ) ), 'Native heading refuses a paragraph wrapper' );
$fixture['document'][0]['attrs']['lock'] = [ 'remove' => true ];
recipe_assert( is_wp_error( $gutenberg->validate_binding( $fixture, [ 'target' => $field['target'], 'format' => 'text' ] ) ), 'Locked native ancestors protect descendant fields' );
$chunks = $gutenberg->starter( 'static' )['blueprint'];
$chunks['document'][0]['innerContent'] = [ '<div class="wp-block-group"><b>Protected chrome</b>', null, '<hr class="keep-middle"/>', null, '</div>' ];
$replacement = $chunks['document'][0]['innerBlocks'];
$chunks = recipe_ok( $gutenberg->replace_region( $chunks, [ 'target' => '/document/0/innerBlocks', 'start' => 0, 'count' => 1 ], $replacement ), 'native range replacement' );
recipe_assert( false !== strpos( implode( '', array_filter( $chunks['document'][0]['innerContent'], 'is_string' ) ), '<hr class="keep-middle"/>' ), 'Partial child replacement preserves native interstitial markup' );
recipe_assert( 3 === count( array_filter( $chunks['document'][0]['innerContent'], static function ( $value ) { return null === $value; } ) ), 'Native WordPress child placeholders match the new child collection' );

$beaver = Nova_Bridge_Suite_Recipe_Builders::adapter( 'beaver' );
$bb = $beaver->starter( 'static' )['blueprint'];
$bb['document'][0]['orig'] = [ 'future_vendor_property' => [ 'keep' => 'opaque value' ] ];
$bb_final = recipe_ok( $beaver->finalize( $bb, 'retained-vendor' ), 'native Beaver unknown properties' );
recipe_assert( 'opaque value' === reset( $bb_final['native_document'] )['future_vendor_property']['keep'], 'Beaver serialization preserves opaque source node properties' );
$bb['document'][0]['children'][0]['children'][0]['children'][] = [ 'node_id' => null, 'type' => 'module', 'orig' => null, 'settings' => [ 'type' => 'accordion', 'items' => [ [ 'label' => 'Captured question?', 'content' => '<p>Captured answer.</p>', 'opaque_style' => 'kept' ] ] ], 'children' => [] ];
$items_target = '/document/0/children/0/children/0/children/2/settings/items';
recipe_assert( true === $beaver->validate_region( $bb, [ 'target' => $items_target ] ), 'Captured native accordion rows expose their actual collection' );
recipe_assert( true === $beaver->validate_binding( Nova_Bridge_Suite_Recipe_Engine::get( $bb, $items_target ), [ 'target' => '/0/label', 'format' => 'text' ] ), 'Native accordion item prototypes expose questions without translating their schema' );

$breakdance = Nova_Bridge_Suite_Recipe_Builders::adapter( 'breakdance' );
$bd = $breakdance->starter( 'static' )['blueprint'];
$row = [ 'question' => 'Captured question?', 'answer' => '<p>Captured answer.</p>', 'opaque_style' => 'kept' ];
$bd['document'][0]['children'][] = [ 'id' => 99, 'data' => [ 'type' => 'EssentialElements\\FrequentlyAskedQuestions', 'properties' => [ 'content' => [ 'settings' => [ 'items' => [ $row ], 'questions' => [ $row ] ] ], 'design' => [ 'future_breakpoint' => [ 'color' => '#123456' ] ] ] ], 'children' => [] ];
$bd_items = '/document/0/children/1/data/properties/content/settings/items';
$bd_bound = recipe_ok( $breakdance->bind( $bd, [ 'target' => $bd_items . '/0/question', 'format' => 'text' ], 'Replaced question?' ), 'native Breakdance FAQ question' );
recipe_assert( 'Replaced question?' === Nova_Bridge_Suite_Recipe_Engine::get( $bd_bound, str_replace( '/items', '/questions', $bd_items ) . '/0/question' ), 'Breakdance FAQ writes keep native aliases synchronized' );
$bd_repeated = recipe_ok( $breakdance->replace_region( $bd_bound, [ 'target' => $bd_items, 'start' => 0, 'count' => 1 ], [ $row, $row ] ), 'native Breakdance repeated FAQ rows' );
recipe_assert( 2 === count( Nova_Bridge_Suite_Recipe_Engine::get( $bd_repeated, str_replace( '/items', '/questions', $bd_items ) ) ), 'Breakdance FAQ repeats update the complete mirrored native collection' );
$bd_final = recipe_ok( $breakdance->finalize( $bd_repeated, 'retained-style' ), 'native Breakdance styled frame' );
$bd_decoded = Nova_BD_Utils::decode_breakdance_tree( $bd_final['native_document'] );
recipe_assert( '#123456' === $bd_decoded['root']['children'][1]['data']['properties']['design']['future_breakpoint']['color'], 'Breakdance preserves unknown responsive style envelopes' );

$divi5 = Nova_Bridge_Suite_Recipe_Builders::adapter( 'divi5' );
$d5 = $divi5->starter( 'static' )['blueprint'];
$d5['document'][0]['attrs']['locked'] = [ 'desktop' => [ 'value' => 'on' ] ];
recipe_assert( is_wp_error( $divi5->validate_binding( $d5, [ 'target' => '/document/0/innerBlocks/0/attrs/title/innerContent/desktop/value', 'format' => 'html' ] ) ), 'Native Divi 5 protected ancestors cannot be filled through descendant pointers' );

$wpb = Nova_Bridge_Suite_Recipe_Builders::adapter( 'wpbakery' );
$raw = 'prefix [vc_row css=".vc_custom_123{color:#123456}" unknown="preserved"][vc_column][vc_column_text]<p>Original.</p>[/vc_column_text][/vc_column][/vc_row] suffix';
$GLOBALS['recipe_posts'][1] = (object) [ 'ID' => 1, 'post_content' => $raw ];
$GLOBALS['recipe_meta'][1] = [ '_wpb_post_custom_css' => '.custom{padding:19px}' ];
$captured = recipe_ok( $wpb->capture( 1 ), 'lossless complete shortcode capture' );
$result = recipe_ok( $wpb->finalize( $captured['blueprint'], 'static-capture' ), 'captured native serialization' );
recipe_assert( $raw === $result['native_document'], 'Native source fragments, unknown attributes and style frame stay byte-exact' );
recipe_assert( '.custom{padding:19px}' === $result['writer_payload']['meta']['_wpb_post_custom_css'], 'Provider custom CSS stays in complete writer context' );
$bad = $captured['blueprint']; $bad['document'][0]['attributes']['api_key'] = 'must-never-be-exported';
recipe_assert( is_wp_error( $wpb->validate_blueprint( $bad ) ), 'Complete native capture fails closed on embedded credentials instead of exporting a partial frame' );
recipe_assert( is_wp_error( Nova_Bridge_Suite_Recipe_Builders::starter( 'beaver' ) ), 'Inactive native provider cannot start a runtime setup' );

require dirname( __DIR__, 2 ) . '/gutenberg/includes/pages.php';
$faq_html = '<h2>FAQs</h2><h3>A question?</h3><p>An answer.</p>';
$opaque_native = serialize_blocks( [ [ 'blockName' => 'core/html', 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $faq_html, 'innerContent' => [ $faq_html ] ] ] );
recipe_assert( $opaque_native === nova_gut_validate_native_document( $opaque_native ), 'Explicit complete Gutenberg content retains opaque FAQ HTML without converting it into details blocks' );
recipe_assert( is_wp_error( nova_gut_validate_native_document( '<!-- wp:paragraph --><p>Broken</p>' ) ), 'Explicit native block creation refuses malformed delimiters before writes' );

// Exercise the same sealing/preset/strict review that the actual onboarding API uses.
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-layout-recipes.php';
$decorate = new ReflectionMethod( Nova_Bridge_Suite_Layout_Recipes::class, 'decorate_design' ); if ( PHP_VERSION_ID < 80100 ) { $decorate->setAccessible( true ); }
$reviewed = new ReflectionMethod( Nova_Bridge_Suite_Layout_Recipes::class, 'reviewed' ); if ( PHP_VERSION_ID < 80100 ) { $reviewed->setAccessible( true ); }
foreach ( [ 'gutenberg', 'wpbakery', 'avada', 'divi4', 'divi5', 'beaver', 'breakdance' ] as $builder ) {
    foreach ( [ 'static', 'mixed', 'rules' ] as $mode ) {
        $artifact = recipe_ok( Nova_Bridge_Suite_Recipe_Builders::adapter( $builder )->starter( $mode ), $builder . ' ' . $mode . ' controller starter' );
        $design = $decorate->invoke( null, $builder, $mode, $artifact );
        recipe_assert( $design['recipe_preset']['blueprint'] === $artifact['blueprint'] && strlen( $design['seal'] ) === 64, $builder . ' ' . $mode . ' API preserves and seals its full native blueprint' );
        $recipe = recipe_ok( $reviewed->invoke( null, [ 'recipe' => $design['recipe_preset'], 'seal' => $design['seal'] ] ), $builder . ' ' . $mode . ' passes controller field/prototype checks' );
        $sample = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $design['sample'], 'controller-' . $builder . '-' . $mode ), $builder . ' ' . $mode . ' compiles its actual onboarding sample' );
        recipe_assert( ! empty( $sample['summary']['nodes'] ), $builder . ' ' . $mode . ' onboarding sample has complete native nodes' );
        $content = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( $recipe, $input, 'controller-content-' . $builder . '-' . $mode ), $builder . ' ' . $mode . ' compiles filled canonical content after review' );
        recipe_assert( strpos( wp_json_encode( $content['native_document'] ), 'static' === $mode ? 'Second paragraph.' : 'Second section' ) !== false, $builder . ' ' . $mode . ' strict controller preset actually fills supplied content' );
    }
}
// Call the genuine bridge: its public response uses post_id, not id.
require dirname( __DIR__, 2 ) . '/gutenberg/includes/helpers.php';
$gutenberg_artifact = $gutenberg->starter( 'static' ); $gutenberg_compiled = recipe_ok( Nova_Bridge_Suite_Recipe_Engine::compile( recipe_for( 'gutenberg', $gutenberg_artifact, 'static' ), $input, 'actual-writer-shape' ), 'Complete Gutenberg payload for native write' );
$native_title = "NOVA's native \\ title 🛰️"; $source_before = serialize( $GLOBALS['recipe_posts'][1] );
$written = recipe_ok( $gutenberg->write_draft( $gutenberg_compiled['writer_payload'], [ 'post_type' => 'post', 'title' => $native_title ] ), 'Actual Gutenberg bridge post_id response normalization' );
recipe_assert( $written['post_id'] > 11000 && isset( $written['native_result']['post_id'] ) && ! isset( $written['native_result']['id'] ), 'The real bridge post_id envelope was not accepted' );
recipe_assert( 1 === $GLOBALS['native_fixture_writes'] && 'draft' === get_post( $written['post_id'] )->post_status && $native_title === get_post( $written['post_id'] )->post_title, 'Native create did not retain draft status/title escaping' );
recipe_assert( $gutenberg_compiled['writer_payload']['native_document'] === get_post( $written['post_id'] )->post_content && serialize( $GLOBALS['recipe_posts'][1] ) === $source_before, 'Source-free write changed source identity or native document bytes' );
echo 'PASS: ' . $assertions . " native adapter assertions (real WordPress parser/serializers; no provider rendering simulated).\n";
