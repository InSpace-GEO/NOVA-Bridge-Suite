<?php
/** A deleted example cannot affect a retained V3 creation layout. */
namespace Elementor {
    class Base_Data_Control {}
    class Plugin { public $documents; public $controls_manager; public static function instance() { static $p; if ( ! $p ) { $p = new self(); $p->documents = new \Stored_Test_Documents(); $p->controls_manager = new \Stored_Test_Controls(); } return $p; } }
}
namespace SEOR_Elementor_Bridge {
    class Elementor_Service {
        public function get_page_payload( $id, $args ) { return [ 'document' => json_decode( $GLOBALS['meta'][ $id ]['_elementor_data'], true ) ]; }
    }
}
namespace {
if ( defined( 'ABSPATH' ) ) { throw new \RuntimeException( 'Run standalone only.' ); }
define( 'ABSPATH', __DIR__ );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new \ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error { private $code; private $message; private $data; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } public function get_error_data() { return $this->data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_kses_post( $value ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function current_user_can( ...$args ) { return true; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'edit_pages', 'edit_posts' => 'edit_pages' ] ]; }
function source_read() { if ( $GLOBALS['source_deleted'] ) { throw new \RuntimeException( 'The source must never be read during compilation.' ); } ++$GLOBALS['source_reads']; }
function get_post( $id ) { source_read(); return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = false ) { source_read(); return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function metadata_exists( $type, $id, $key ) { source_read(); return isset( $GLOBALS['meta'][ $id ][ $key ] ); }
function get_page_template_slug( $id ) { source_read(); return 'elementor_canvas'; }
function wp_insert_post( ...$args ) { throw new \RuntimeException( 'Pure compilation cannot insert a page.' ); }
function update_option( ...$args ) { throw new \RuntimeException( 'Pure compilation cannot save settings.' ); }
function update_post_meta( ...$args ) { throw new \RuntimeException( 'Pure compilation cannot write native data.' ); }
class Stored_Test_Controls { public function get_control( $type ) { return new \Elementor\Base_Data_Control(); } public function delete_stack( $document ) {} }
class Stored_Test_Documents { public function get( $id, $cache = false ) { return new Stored_Test_Document( $id ); } }
class Stored_Test_Document { private $id; public function __construct( $id ) { $this->id = $id; } public function get_controls() { return [ 'custom_css' => [ 'type' => 'text' ], 'background_color' => [ 'type' => 'color' ] ]; } public function get_settings() { return array_merge( [ 'background_color' => '' ], get_post_meta( $this->id, '_elementor_page_settings', true ) ); } }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
use Nova_Bridge_Suite_Stored_Layout as Layout;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
$checks = 0; $source_reads = 0; $source_deleted = false;
function check( $truth, string $message ): void { ++$GLOBALS['checks']; if ( ! $truth ) { throw new \RuntimeException( $message ); } }
function good( $result, string $message ) { check( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) ); return $result; }
function bad( $result, string $message ): void { check( is_wp_error( $result ), $message ); }
function widget( $id, $kind, $settings ) { return [ 'id' => $id, 'elType' => 'widget', 'widgetType' => $kind, 'settings' => $settings, 'elements' => [] ]; }
function field( string $type = 'text' ): array { return [ 'mode' => 'adapt', 'source_path' => '', 'instructions' => 'Use the supplied page content.', 'required' => false, 'description' => [ 'label' => 'New copy', 'purpose' => 'Content for the relevant page section.', 'value_type' => $type, 'constraints' => [] ] ]; }
function descriptor( $path, $id, $setting ): array { return [ 'path' => $path, 'builder' => 'elementor', 'selector_data' => [ 'element_id' => $id, 'path' => $setting ] ]; }
$document = [ [ 'id' => 'aaaaaaa', 'elType' => 'container', 'settings' => [ 'background_color' => '#ddeeff', 'opacity' => 0.75, 'padding_mobile' => [ 'unit' => 'px', 'top' => 16 ] ], 'elements' => [
    widget( 'bbbbbbb', 'heading', [ 'title' => 'Example headline', 'header_size' => 'h1', 'typography_font_size' => [ 'size' => 48.5, 'unit' => 'px' ] ] ),
    widget( 'ccccccc', 'text-editor', [ 'editor' => '<p>Example paragraph</p>', 'custom_css' => '.elementor-element-bbbbbbb { color: red; }' ] ),
    widget( 'ddddddd', 'button', [ 'text' => 'Static CTA', 'link' => [ 'url' => 'https://example.test/contact/' ], '__dynamic__' => [ 'link' => '[elementor-tag name="internal-url" settings="%7B%22post_id%22%3A9%7D"]' ] ] ),
    widget( 'eeeeeee', 'heading', [ 'title' => 'Remove example', 'header_size' => 'h2' ] ),
    widget( 'fffffff', 'accordion', [ 'tabs' => [ [ '_id' => 'tab-1', 'tab_title' => 'First question', 'tab_content' => '<p>Example answer</p>' ], [ '_id' => 'tab-2', 'tab_title' => 'Second question', 'tab_content' => '<p>Second answer</p>' ] ] ] ),
] ] ];
$posts = [ 10 => (object) [ 'ID' => 10, 'post_type' => 'page', 'post_title' => 'Example page', 'post_excerpt' => 'Static excerpt' ] ];
$meta = [ 10 => [ '_elementor_edit_mode' => 'builder', '_elementor_data' => json_encode( $document ), '_elementor_page_settings' => [ 'custom_css' => '.elementor-element-bbbbbbb{margin:0}', '__globals__' => [ 'background_color' => 'globals/colors?id=primary' ] ] ] ];
$draft = [ 'schema_version' => 1, 'revision' => 'saved-1', 'reference_type' => 'post', 'reference_id' => 10, 'catalog_mode' => 'destination', 'guidance' => 'Distribute content into the fixed layout.', 'guidance_mode' => 'set', 'profile_page_type' => 'service', 'label' => 'Stored service design', 'routing' => [ 'operation' => 'create', 'publication' => 'publish' ], 'repeat_slots' => [], 'destination_groups' => [], 'fields' => [ '/title' => field(), '/headline' => field(), '/body' => field( 'rich_text' ), '/cta' => [ 'mode' => 'protected' ], '/empty' => [ 'mode' => 'leave_empty' ], '/answer' => field( 'rich_text' ) ], 'target_descriptors' => [ '/title' => [ 'path' => '/title' ], '/headline' => descriptor( '/headline', 'bbbbbbb', [ 'title' ] ), '/body' => descriptor( '/body', 'ccccccc', [ 'editor' ] ), '/cta' => descriptor( '/cta', 'ddddddd', [ 'text' ] ), '/empty' => descriptor( '/empty', 'eeeeeee', [ 'title' ] ), '/answer' => descriptor( '/answer', 'fffffff', [ 'tabs', 0, 'tab_content' ] ) ] ];
$draft['stored_layout'] = good( Layout::capture( $draft ), 'Example capture retains a complete native layout' );
check( $source_reads > 0 && $draft['stored_layout']['blueprint']['document'] === $document, 'Only capture reads the source and retains every native setting' );
$direct = $draft; $direct['catalog_mode'] = 'nova'; $direct['guidance'] = ''; $direct['guidance_mode'] = 'clear';
foreach ( [ '/title' => 'title', '/headline' => 'h1', '/body' => 'content', '/answer' => 'bottom_content' ] as $path => $source ) { $direct['fields'][ $path ] = [ 'mode' => 'mapped', 'source_path' => $source, 'required' => false, 'instructions' => '' ]; }
$direct['stored_layout'] = good( Layout::capture( $direct ), 'Direct V1 fields retain the same native creation snapshot' );
$source_deleted = true; $posts = []; $meta = []; $read_count = $source_reads;
$values = [ Writing::field_id( '/title' ) => 'Fresh service', Writing::field_id( '/headline' ) => 'New heading', Writing::field_id( '/body' ) => '<p>Entirely new body.</p>' ];
$compiled = good( Layout::compile( $draft, $values, 'operation-1' ), 'Compilation succeeds after the example page has been deleted' );
check( $source_reads === $read_count, 'Validation and compilation make no source reads' );
$out = $compiled['writer_payload']['elementor_data'];
check( $out[0]['settings'] === $document[0]['settings'] && $out[0]['elements'][0]['settings']['typography_font_size'] === $document[0]['elements'][0]['settings']['typography_font_size'], 'Responsive layout and styling survive creation' );
check( $out[0]['elements'][0]['settings']['title'] === 'New heading' && $out[0]['elements'][1]['settings']['editor'] === '<p>Entirely new body.</p>', 'Fitted values reach exact saved native destinations' );
check( $out[0]['elements'][2]['settings'] === $document[0]['elements'][2]['settings'], 'Legacy saved layouts retain protected CTA native bindings unchanged' );
check( $out[0]['elements'][3]['settings']['title'] === '' && $out[0]['elements'][4]['settings']['tabs'][0]['tab_content'] === '', 'Leave-empty and missing optional generated destinations do not leak example content' );
check( $out[0]['elements'][4]['settings']['tabs'][1]['tab_content'] === '<p>Second answer</p>' && count( $out[0]['elements'][4]['settings']['tabs'] ) === 2, 'Unmapped nested fields and fixed cardinality are preserved' );
check( $out[0]['id'] !== 'aaaaaaa' && $out[0]['elements'][0]['id'] !== 'bbbbbbb', 'New page elements receive new identities' );
check( strpos( $out[0]['elements'][1]['settings']['custom_css'], 'elementor-element-' . $out[0]['elements'][0]['id'] ) !== false, 'Widget CSS selectors follow rebased IDs' );
check( $compiled['writer_payload']['template'] === 'elementor_canvas' && $compiled['writer_payload']['elementor_page_settings']['__globals__'] === [ 'background_color' => 'globals/colors?id=primary' ], 'Page template and site global style bindings are retained' );
check( $compiled['writer_payload']['elementor_document_settings']['custom_css'] === $compiled['writer_payload']['elementor_page_settings']['custom_css'] && strpos( $compiled['writer_payload']['elementor_document_settings']['custom_css'], 'elementor-element-' . $out[0]['elements'][0]['id'] ) !== false, 'Effective document settings use the same rebased native CSS selectors as the page settings snapshot.' );
check( $compiled['post_fields']['title'] === 'Fresh service' && $compiled['post_fields']['excerpt'] === 'Static excerpt', 'Mapped native title and protected static excerpt survive' );
check( $compiled['writer_payload']['elementor_data'][0]['settings']['opacity'] === 0.75 && $compiled['writer_payload']['elementor_data'][0]['elements'][0]['settings']['typography_font_size']['size'] === 48.5, 'Fractional native styling remains exact' );
$direct_out = good( Layout::compile( $direct, $values, 'direct-operation' ), 'Direct-source values converted by the caller use the same frozen builder' );
check( $direct_out['post_fields']['title'] === 'Fresh service' && $direct_out['writer_payload']['elementor_data'][0]['elements'][0]['settings']['title'] === 'New heading', 'V1 direct values populate saved layout without V3 runtime or source reads' );
check( $compiled === good( Layout::compile( $draft, $values, 'operation-1' ), 'Same retained operation can be rebuilt' ), 'Same saved mapping, values and operation produce identical payloads' );
check( $compiled['writer_payload']['elementor_data'][0]['id'] !== good( Layout::compile( $draft, $values, 'operation-2' ), 'Next page compiles' )['writer_payload']['elementor_data'][0]['id'], 'Different operations create independent element identities' );
$changed = $draft; $changed['stored_layout']['blueprint']['document'][0]['settings']['background_color'] = 'pink'; bad( Layout::compile( $changed, $values, 'op' ), 'Tampering with frozen native styling fails its digest' );
$changed = $draft; $changed['fields']['/cta']['mode'] = 'leave_empty'; bad( Layout::validate( $changed ), 'Changing retained protection without resaving fails' );
$bad_values = $values; $bad_values['field_unknown'] = 'wrong'; bad( Layout::compile( $draft, $bad_values, 'op' ), 'Unexpected fitted destinations fail closed' );
$bad_values = $values; $bad_values[ Writing::field_id( '/body' ) ] = '<script>alert(1)</script>'; bad( Layout::compile( $draft, $bad_values, 'op' ), 'Generated unsafe HTML does not enter native storage' );
$record = [ 'id' => '11111111-1111-4111-8111-111111111111', 'revision' => 7, 'posting_page_type' => 'informative', 'builder' => 'elementor', 'resource_id' => 'post_type:page', 'label' => 'Saved visual design', 'recipe' => [ 'builder' => 'elementor', 'blueprint' => $draft['stored_layout']['blueprint'], 'instructions' => 'Keep intro concise.', 'bindings' => [ [ 'target' => '/document/0/elements/0/settings/title', 'source' => '/title', 'format' => 'text', 'instructions' => 'Use the page heading here.', 'required' => true ], [ 'target' => '/document/0/elements/4/settings/tabs/0/tab_content', 'source' => '/answer', 'format' => 'html', 'instructions' => 'Answer the first question.', 'required' => false ] ], 'regions' => [] ] ];
$record['recipe']['bindings'][0]['label'] = 'Service hero headline';
$projected = good( Layout::from_recipe( $record ), 'Visual recipe projects without the example page' );
check( $projected['revision'] === $record['id'] . ':7' && $projected['profile_page_type'] === 'informative' && $projected['routing']['operation'] === 'create', 'Recipe identity and page type survive projection' );
check( $projected['guidance'] === 'Keep intro concise.' && $projected['fields']['/document/0/elements/0/settings/title']['instructions'] === 'Use the page heading here.', 'Recipe and field instructions are preserved exactly' );
check( $projected['fields']['/document/0/elements/0/settings/title']['description']['label'] === 'Service hero headline', 'Human field purpose labels survive the V3 projection' );
$recipe_values = [ Writing::field_id( '/title' ) => 'Created title', Writing::field_id( '/document/0/elements/0/settings/title' ) => 'Created heading', Writing::field_id( '/document/0/elements/4/settings/tabs/0/tab_content' ) => '<p>New nested answer.</p>' ];
$recipe_out = good( Layout::compile( $projected, $recipe_values, 'recipe-create' ), 'Projected visual recipe uses the same creation compiler' );
check( $recipe_out['post_fields']['title'] === 'Created title' && $recipe_out['writer_payload']['elementor_data'][0]['elements'][4]['settings']['tabs'][0]['tab_content'] === '<p>New nested answer.</p>', 'Native page title and nested values are filled from the V3 result' );
$bad = $record; $bad['recipe']['regions'] = [ [ 'id' => 'generated' ] ]; bad( Layout::from_recipe( $bad ), 'Generated repeat regions are explicitly rejected, not dropped' );
$bad = $record; $bad['recipe']['custom_fields'] = [ 'builder' => 'acf' ]; bad( Layout::from_recipe( $bad ), 'ACF sidecars are explicitly rejected, not dropped' );
$dynamic = $record; $dynamic['recipe']['blueprint']['document'][0]['elements'][2]['settings']['__dynamic__']['text'] = '[elementor-tag name="post-title" settings="%7B%7D"]';
$dynamic_draft = good( Layout::from_recipe( $dynamic ), 'Protected native dynamic controls do not require the source page' );
$dynamic_out = good( Layout::compile( $dynamic_draft, $recipe_values, 'recipe-dynamic' ), 'Native dynamic configuration compiles without evaluating the deleted example' );
check( $dynamic_out['writer_payload']['elementor_data'][0]['elements'][2]['settings'] === $dynamic['recipe']['blueprint']['document'][0]['elements'][2]['settings'], 'Native dynamic expressions stay intact for destination rendering' );
$bad = $record; $bad['recipe']['blueprint']['document'][0]['elements'][2]['widgetType'] = 'template'; bad( Layout::from_recipe( $bad ), 'Embedded external template widgets must be inlined before saving' );
$bad = $record; $bad['recipe']['blueprint']['meta']['custom_css'] = '.elementor-page-10 .special{color:red}'; bad( Layout::from_recipe( $bad ), 'Source-specific page CSS must not silently stop working in a new page' );
check( $source_reads === $read_count, 'All recipe projection and compilation checks stayed independent of the deleted example' );
$oversized = $record; $oversized['recipe']['blueprint']['meta']['custom_css'] = str_repeat( ' ', 524288 );
bad( Layout::from_recipe( $oversized ), 'A captured mapping too large for recoverable delivery is rejected before synchronization' );
echo 'PASS ' . $checks . " stored-layout creation assertions\n";
}
