<?php
/** Standalone core checks. WordPress storage/HTML policy are explicitly mocked. */
define( 'ABSPATH', __DIR__ );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_generate_uuid4() { return 'd3353c1e-9702-438c-85d4-370e9f8f0241'; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function post_type_exists( $type ) { return in_array( $type, [ 'post', 'page', 'service_page' ], true ); }
function wp_kses_post( $value ) {
    $value = strip_tags( $value, '<h1><h2><h3><h4><h5><h6><p><a><abbr><b><br><cite><code><em><i><mark><small><span><strong><sub><sup><u><s><div><section><article><main><ul><ol><li><img><figure><figcaption><table><tbody><tr><td><blockquote><hr>' );
    $value = preg_replace( '/\s+on[a-z]+\s*=\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s>]+)/i', '', $value );
    return preg_replace( '/(href|src)\s*=\s*(["\x27])\s*javascript:[^"\x27]*\2/i', '$1=""', $value );
}
$store = [];
function get_option( $name, $default = false ) { return $GLOBALS['store'][ $name ] ?? $default; }
function add_option( $name, $value, $deprecated = '', $autoload = false ) { if ( array_key_exists( $name, $GLOBALS['store'] ) ) { return false; } $GLOBALS['store'][ $name ] = $value; return true; }
function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
function wp_cache_delete( $key, $group ) { return true; }
class Content_Rules_DB {
    public $options = 'wp_options'; public $on_query; public $updates = 0;
    public function prepare( $sql, ...$arguments ) { return [ $sql, $arguments ]; }
    public function query( $prepared ) {
        ++$this->updates;
        if ( $this->on_query ) { $hook = $this->on_query; $this->on_query = null; $hook(); }
        list( $sql, $arguments ) = $prepared; list( $next, $name, $expected ) = $arguments;
        if ( maybe_serialize( get_option( $name ) ) !== $expected ) { return 0; }
        $GLOBALS['store'][ $name ] = unserialize( $next, [ 'allowed_classes' => false ] ); return 1;
    }
}
$wpdb = new Content_Rules_DB();
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-content-rules.php';
$checks = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function result( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function blocked( $value, $code = '' ) { check( is_wp_error( $value ) && ( '' === $code || $value->get_error_code() === 'nova_content_rules_' . $code ), 'Expected rejection ' . $code ); }
function nodes( array $elements ): array { $all = []; foreach ( $elements as $element ) { $all[] = $element; $all = array_merge( $all, nodes( $element['elements'] ?? [] ) ); } return $all; }
$profile = Nova_Bridge_Suite_Content_Rules::preset();
check( result( Nova_Bridge_Suite_Content_Rules::validate( $profile ) ) === $profile, 'Preset validates unchanged.' );
foreach ( [ [ 'schema_version', 2 ], [ 'post_type', 'missing' ], [ 'hide_empty', 'true' ], [ 'revision', -1 ], [ 'id', 'source-page-123' ] ] as $bad ) { $copy = $profile; $copy[ $bad[0] ] = $bad[1]; blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ) ); }
$copy = $profile; $copy['rules']['paragraph']['settings']['script'] = '<script>'; blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ), 'settings' );
$copy = $profile; $copy['rules']['image']['settings']['color'] = '#fff'; blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ), 'settings' );
$copy = $profile; $copy['rules']['group']['settings']['typography_font_size'] = 20; blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ), 'settings' );
$copy = $profile; $copy['layout']['gap'] = 121; blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ), 'layout' );
$copy = $profile; unset( $copy['rules']['rich_text'] ); blocked( Nova_Bridge_Suite_Content_Rules::validate( $copy ), 'rules' );
$copy = $profile; $copy['rules']['heading']['settings'] = [ 'color' => '#abcdef', 'typography_font_size' => 30, 'line_height' => 1.25, 'align' => 'center' ]; result( Nova_Bridge_Suite_Content_Rules::validate( $copy ) );
$styled = result( Nova_Bridge_Suite_Content_Rules::render( $copy, [ 'blocks' => [ [ 'type' => 'heading', 'level' => 3, 'text' => 'Native style' ] ] ], 'style' ) );
$style = $styled['elements'][0]['elements'][0]['settings'];
check( $style['title_color'] === '#abcdef' && $style['typography_font_size']['size'] === 30 && $style['typography_line_height']['size'] === 1.25 && $style['align'] === 'center' && $style['header_size'] === 'h3', 'Only supported native styles are emitted and source heading level survives.' );
$html = '<h2>A <em>formatted</em> <a href="https://example.org/link">heading</a></h2><p>First <strong>paragraph</strong>.</p><h3>Next</h3><p>Second &amp; third.</p><ul><li>One</li><li>Two <b>bold</b></li></ul><img src="https://example.org/image.png" alt="Accessible image">';
$render = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => $html, 'title' => 'Native draft' ], 'operation-1' ) );
check( array_column( $render['blocks'], 'type' ) === [ 'heading', 'paragraph', 'heading', 'paragraph', 'list', 'image' ], 'HTML order and separate headings/paragraphs/list/image are retained.' );
$children = $render['elements'][0]['elements'];
check( array_column( $children, 'widgetType' ) === [ 'heading', 'text-editor', 'heading', 'text-editor', 'text-editor', 'image' ], 'Every heading and paragraph becomes its own editable native widget.' );
check( strpos( $children[0]['settings']['title'], '<em>formatted</em>' ) !== false && strpos( $children[0]['settings']['title'], 'href="https://example.org/link"' ) !== false, 'Inline heading formatting and links survive.' );
check( $children[5]['settings']['image'] === [ 'url' => 'https://example.org/image.png', 'id' => 0, 'alt' => 'Accessible image' ], 'External native images retain source URL and explicit alt.' );
check( $render === result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => $html, 'title' => 'Native draft' ], 'operation-1' ) ), 'A retry has byte-identical document IDs and semantic result.' );
$changed_seed = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => $html ], 'operation-2' ) );
check( $changed_seed['elements'][0]['id'] !== $render['elements'][0]['id'], 'Independent creation identities do not reuse native IDs.' );
$unsupported = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => '<figure><a href="https://example.org"><img src="https://example.org/a.png" alt="Kept"></a><figcaption>Caption kept</figcaption></figure><table><tr><td>Cell kept</td></tr></table>' ], 'unsupported' ) );
check( count( $unsupported['blocks'] ) === 2 && strpos( $unsupported['elements'][0]['elements'][0]['settings']['editor'], 'Caption kept' ) !== false && strpos( $unsupported['elements'][0]['elements'][1]['settings']['editor'], 'Cell kept' ) !== false && count( $unsupported['warnings'] ) >= 2, 'Unsupported complex HTML is retained as rich text with visible warnings.' );
$anchors = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => '<h2 id="topic">Linked heading</h2><p><a href="#topic">Jump to heading</a></p><p id="empty-anchor"></p><p><a id="inline-target"></a><a href="https://example.org/full"><img src="https://example.org/linked.png" alt="Linked image"></a></p><h3 lang="nl">Taal bewaren</h3>' ], 'anchors' ) );
check( $anchors['elements'][0]['elements'][0]['settings']['_element_id'] === 'topic' && strpos( $anchors['preview_html'], 'id="topic"' ) !== false && strpos( $anchors['elements'][0]['elements'][1]['settings']['editor'], 'href="#topic"' ) !== false, 'Source anchors and internal links survive native widgets and preview.' );
check( $anchors['elements'][0]['elements'][2]['settings']['_element_id'] === 'empty-anchor', 'An empty anchor target survives hide-empty policy.' );
check( strpos( $anchors['elements'][0]['elements'][3]['settings']['editor'], 'id="inline-target"' ) !== false && strpos( $anchors['elements'][0]['elements'][3]['settings']['editor'], 'alt="Linked image"' ) !== false && strpos( $anchors['elements'][0]['elements'][3]['settings']['editor'], 'href="https://example.org/full"' ) !== false, 'Inline empty anchors and linked image semantics remain intact.' );
check( $anchors['blocks'][4]['type'] === 'rich_text' && strpos( $anchors['elements'][0]['elements'][4]['settings']['editor'], 'lang="nl"' ) !== false && $anchors['warnings'], 'Unsupported semantic attributes preserve the whole HTML block with a warning.' );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => '<h2 id="same">One</h2><p id="same">Two</p>' ], 'duplicated-anchor' ) );
$unsafe = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => '<p onclick="bad()">Visible</p>' ], 'unsafe' ) );
check( strpos( $unsafe['preview_html'], 'onclick' ) === false && $unsafe['warnings'], 'Sanitization is reflected in preview and reported.' );
$nested = [ [ 'type' => 'repeat', 'items' => [ [ 'children' => [ [ 'type' => 'heading', 'text' => 'Row one' ], [ 'type' => 'paragraph', 'html' => 'Body <em>one</em>' ] ] ], [ [ 'type' => 'group', 'children' => [ [ 'type' => 'heading', 'text' => 'Row two' ] ] ] ] ] ] ];
$groups = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'blocks' => $nested, 'faqs' => [ [ 'question' => 'Question 1?', 'answer' => '<p>Answer 1.</p>' ], [ 'question' => 'Question 2?', 'answer' => '<p>Answer 2.</p>' ] ] ], 'nested' ) );
$all = nodes( $groups['elements'] ); $ids = array_column( $all, 'id' );
check( count( array_filter( $all, static function ( $node ) { return ( $node['elType'] ?? '' ) === 'container'; } ) ) >= 4 && count( $ids ) === count( array_unique( $ids ) ), 'Repeated nested groups create ordered containers with unique deterministic IDs.' );
$accordion = end( $groups['elements'][0]['elements'] );
check( $accordion['widgetType'] === 'accordion' && count( $accordion['settings']['tabs'] ) === 2, 'Explicit FAQs create repeated native accordion items.' );
$copy = $profile; $copy['rules']['faq']['element'] = 'container';
$faq_layout = result( Nova_Bridge_Suite_Content_Rules::render( $copy, [ 'faqs' => [ [ 'question' => 'Editable question', 'answer' => '<p>Editable answer.</p>' ] ] ], 'faq-container' ) );
check( count( nodes( $faq_layout['elements'] ) ) === 5, 'FAQ containers create individual heading and rich-text widgets.' );
check( strpos( $faq_layout['preview_html'], '<details>' ) === false && strpos( $faq_layout['preview_html'], '<h3>Editable question</h3>' ) !== false && strpos( $faq_layout['preview_html'], '<p>Editable answer.</p>' ) !== false && strpos( $faq_layout['preview_html'], 'nova-content-rule-faq-item' ) !== false, 'Container FAQ preview mirrors separate question/answer groups rather than accordion controls.' );
$copy['rules']['heading']['settings']['html_tag'] = 'h2';
$faq_tag = result( Nova_Bridge_Suite_Content_Rules::render( $copy, [ 'faqs' => [ [ 'question' => 'Explicit heading tag', 'answer' => '<p>Answer.</p>' ] ] ], 'faq-heading-tag' ) );
check( strpos( $faq_tag['preview_html'], '<h2>Explicit heading tag</h2>' ) !== false && $faq_tag['elements'][0]['elements'][0]['elements'][0]['elements'][0]['settings']['header_size'] === 'h2', 'Container FAQ preview and native question widgets respect the selected heading tag.' );
check( strpos( $groups['preview_html'], '<details><summary>Question 1?</summary>' ) !== false, 'Accordion FAQ preview retains details and summary grouping.' );
$many = str_repeat( '<h2>Heading</h2><p>Paragraph</p>', 11 );
check( count( result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => $many ], 'more-than-fixed-cap' ) )['elements'][0]['elements'] ) === 22, 'Rendering repeats beyond fixed legacy section capacities.' );
$empty = result( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'blocks' => [ [ 'type' => 'paragraph', 'text' => '' ], [ 'type' => 'group', 'children' => [] ] ] ], 'empty' ) );
check( $empty['elements'][0]['elements'] === [], 'Empty text and containers are hidden by explicit profile policy.' );
$copy = $profile; $copy['hide_empty'] = false;
check( count( result( Nova_Bridge_Suite_Content_Rules::render( $copy, [ 'blocks' => [ [ 'type' => 'paragraph', 'text' => '' ] ] ], 'empty-kept' ) )['elements'][0]['elements'] ) === 1, 'Empty-block policy can explicitly retain widgets.' );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'blocks' => [ [ 'type' => 'mystery', 'html' => 'Never silently discard' ] ] ], 'bad' ) );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => 'x', 'blocks' => [] ], 'bad' ), 'input' );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'blocks' => [ [ 'type' => 'image', 'url' => 'javascript:bad' ] ] ], 'bad' ) );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'faqs' => [ [ 'question' => '<b>Question</b>', 'answer' => 'Answer' ] ] ], 'bad' ) );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => str_repeat( '<p>x</p>', 501 ) ], 'too-many' ) );
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'html' => str_repeat( 'x', 1048577 ) ], 'too-large' ), 'input_size' );
$deep = [ [ 'type' => 'paragraph', 'text' => 'Too deep' ] ]; for ( $i = 0; $i < 10; ++$i ) { $deep = [ [ 'type' => 'group', 'children' => $deep ] ]; }
blocked( Nova_Bridge_Suite_Content_Rules::render( $profile, [ 'blocks' => $deep ], 'too-deep' ) );

check( $store === [], 'Preset, validation and rendering never write storage.' );
$site = 'fb67968c-8fc7-4189-88b7-6851c2f9241b'; $other_site = 'cf7fbf48-bef6-4f56-975b-7c06f2255a87';
blocked( Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( [ 'configuration' => null, 'site_id' => $site ], $site ), 'no_match' );
$saved = result( Nova_Bridge_Suite_Content_Rules::save( $profile, 0 ) );
check( $saved['revision'] === 1 && count( Nova_Bridge_Suite_Content_Rules::profiles() ) === 1, 'Initial save atomically creates one revision.' );
blocked( Nova_Bridge_Suite_Content_Rules::save( $profile, 0 ), 'conflict' );
result( Nova_Bridge_Suite_Content_Rules::bind( $site, $saved['id'], 1 ) );
$frozen = result( Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( [ 'configuration' => null, 'site_id' => $site ], $site ) );
$updated = $saved; $updated['label'] = 'New revision'; $updated = result( Nova_Bridge_Suite_Content_Rules::save( $updated, 1 ) );
check( $updated['revision'] === 2 && Nova_Bridge_Suite_Content_Rules::get( $saved['id'] )['revision'] === 2, 'Editing creates another immutable revision.' );
check( $frozen === result( Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( [ 'configuration' => null, 'site_id' => $site ], $site ) ), 'Saved edits do not silently rebind opted-in delivery or its frozen revision/digest.' );
blocked( Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( [ 'configuration' => [ 'id' => 'existing-mapping' ], 'site_id' => $site ], $site ), 'no_match' );
blocked( Nova_Bridge_Suite_Content_Rules::configuration_for_snapshot( [ 'configuration' => null, 'site_id' => $other_site ], $site ), 'no_match' );
blocked( Nova_Bridge_Suite_Content_Rules::bind( $site, $saved['id'], 99 ), 'not_found' );
result( Nova_Bridge_Suite_Content_Rules::unbind( $site ) );
check( Nova_Bridge_Suite_Content_Rules::binding( $site ) === [], 'Site opt-in can explicitly be removed.' );
$wpdb->on_query = static function () use ( $updated ) { $race = $updated; $race['revision'] = 3; $race['label'] = 'Concurrent edit'; $GLOBALS['store']['nova_bridge_content_rules_v1']['profiles'][ $race['id'] ][3] = $race; };
$attempt = $updated; $attempt['label'] = 'Stale losing edit'; blocked( Nova_Bridge_Suite_Content_Rules::save( $attempt, 2 ), 'conflict' );
check( Nova_Bridge_Suite_Content_Rules::get( $updated['id'] )['label'] === 'Concurrent edit', 'Byte-CAS refuses a same-profile concurrent overwrite.' );
$race_before = $wpdb->updates;
$wpdb->on_query = static function () use ( $site, $other_site ) { $GLOBALS['store']['nova_bridge_content_rules_v1']['bindings'][ $other_site ] = [ 'site_id' => $other_site, 'profile_id' => 'unrelated', 'profile_revision' => 1 ]; };
result( Nova_Bridge_Suite_Content_Rules::bind( $site, $saved['id'], 1 ) );
check( $wpdb->updates === $race_before + 2 && isset( $store['nova_bridge_content_rules_v1']['bindings'][ $other_site ] ), 'Atomic CAS retry preserves an unrelated concurrent binding.' );
echo 'content-rules-unit: ' . $checks . " checks passed\n";
