<?php
/** Run with php, or wp eval-file. Tests import semantics and layout identity without modifying the site. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
	function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_json_encode( $value ) { return json_encode( $value ); }
	function home_url( $path = '' ) { return 'https://staging.example.test' . $path; }
	function esc_url_raw( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-strategy.php';
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; };
$csv = "\xEF\xBB\xBFurl,page_type,locale,content_html\r\nhttps://example.test/services/a/,service,nl,\"<h1>A, B</h1>\n<p>quoted \"\"word\"\"</p>\"\r\nhttps://example.test/services/b/,service,nl,\"<p>Second</p>\"\r\n";
$parsed = Nova_Bridge_Suite_Strategy::parse_import( [ 'csv' => $csv ] );
$assert( ! is_wp_error( $parsed ) && 2 === count( $parsed['rows'] ), 'Multiline quoted CSV must produce exactly two URLs.' );
$assert( ! isset( $parsed['rows'][0]['content_html'] ) && false === strpos( wp_json_encode( $parsed ), 'quoted' ), 'Imported article bodies must never be stored.' );
$assert( '/services/a/' === $parsed['rows'][0]['path'] && 'nl' === $parsed['rows'][0]['locale'], 'Minimal URL hierarchy and locale must survive import.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'csv' => "url,x\nhttps://example.test/a/,a,b" ] ) ), 'Malformed column counts must reject the whole import.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'csv' => "url,url\n/a/,/b/" ] ) ), 'Duplicate URL headers must reject the import.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'csv' => str_repeat( 'x', Nova_Bridge_Suite_Strategy::MAX_BYTES + 1 ) ] ) ), 'The byte limit must apply before parsing.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'urls' => [ 'https://a.test/a/', 'https://b.test/b/' ] ] ) ), 'Different hosts must not be combined silently.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'urls' => [ 'https://user:secret@example.test/a/' ] ] ) ), 'URLs containing credentials must be rejected.' );
$assert( is_wp_error( Nova_Bridge_Suite_Strategy::parse_import( [ 'urls' => [ 'https://example.test/a/?p=9' ] ] ) ), 'Query-based resource ambiguity must be rejected.' );
$assert( false === Nova_Bridge_Suite_Strategy::path( 'https://example.test/a/%2e%2e/b/' ), 'Encoded traversal must be rejected.' );
$assert( false === Nova_Bridge_Suite_Strategy::path( 'https://example.test/a%2fb/' ), 'Encoded separators must not merge distinct paths.' );
$assert( '/' === Nova_Bridge_Suite_Strategy::path( 'https://example.test' ), 'The root URL canonicalizes correctly.' );
$parsed = Nova_Bridge_Suite_Strategy::parse_import( [ 'urls' => [ 'https://www.example.test/a', 'https://example.test/a/' ] ] );
$assert( ! is_wp_error( $parsed ) && 1 === count( $parsed['rows'] ), 'Canonical duplicate URLs should be mapped only once.' );
$left = [ [ 'id' => 'abc', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Before', 'html_tag' => 'h2' ], 'elements' => [] ] ];
$right = [ [ 'id' => 'def', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'After', 'html_tag' => 'h2' ], 'elements' => [] ] ];
$assert( Nova_Bridge_Suite_Strategy::shape( $left ) === Nova_Bridge_Suite_Strategy::shape( $right ), 'Text changes and document-specific element IDs must not change layout identity.' );
$right[0]['widgetType'] = 'text-editor';
$assert( Nova_Bridge_Suite_Strategy::shape( $left ) !== Nova_Bridge_Suite_Strategy::shape( $right ), 'Different builder widget types must not share a layout.' );
$right = $left;
$right[0]['elements'][] = [ 'elType' => 'widget', 'widgetType' => 'image' ];
$assert( Nova_Bridge_Suite_Strategy::shape( $left ) !== Nova_Bridge_Suite_Strategy::shape( $right ), 'Different nesting must not share a layout.' );
$assert( Nova_Bridge_Suite_Strategy::shape( [ 'parent_index' => 0 ] ) !== Nova_Bridge_Suite_Strategy::shape( [ 'parent_index' => 1 ] ), 'Beaver parent topology must survive ID normalization.' );
$assert( Nova_Bridge_Suite_Strategy::valid_pointer( '/meta_all/acf/a~1b' ), 'Valid escaped JSON pointers must be accepted.' );
$assert( ! Nova_Bridge_Suite_Strategy::valid_pointer( '/title~2bad' ) && ! Nova_Bridge_Suite_Strategy::valid_pointer( 'title' ), 'Malformed JSON pointers must be rejected.' );
$flex = new ReflectionMethod( Nova_Bridge_Suite_Strategy::class, 'acf_value_layout' );
$flex->setAccessible( true );
$definition = [ 'type' => 'flexible_content', 'layouts' => [ [ 'name' => 'hero', 'sub_fields' => [] ], [ 'name' => 'faq', 'sub_fields' => [] ] ] ];
$a = $flex->invoke( null, $definition, [ [ 'acf_fc_layout' => 'hero', 'text' => 'Before' ] ] );
$b = $flex->invoke( null, $definition, [ [ 'acf_fc_layout' => 'faq', 'text' => 'Before' ] ] );
$c = $flex->invoke( null, $definition, [ [ 'acf_fc_layout' => 'hero', 'text' => 'After' ] ] );
$assert( $a !== $b && $a === $c, 'Actual ACF flexible layout choices must differ while editorial values do not.' );
echo 'PASS ' . $checks . " strategy import, pointer, and layout identity checks.\n";

$base = ['rows'=>[], 'imports'=>[], 'assignments'=>[], 'profiles'=>['keep'=>['label'=>'Saved mapping']]];
$files = ['files'=>[['name'=>'a.csv','csv'=>"url,page_type\nhttps://example.test/a/,first\nhttps://example.test/shared/,first"],['name'=>'b.csv','csv'=>"url,page_type\nhttps://example.test/shared/,second\nhttps://example.test/b/,second"]]];
$merged = Nova_Bridge_Suite_Strategy::update_imports($base,$files);
$assert(!is_wp_error($merged) && count($merged['imports'])===2 && count($merged['rows'])===3,'Two files must produce a deduplicated union.');
$assert($merged['rows'][1]['page_type']==='second','Latest file metadata wins for shared URLs.');
$merged['assignments']=array_fill_keys(array_column($merged['rows'],'id'),['reference_id'=>1]);
$removed=Nova_Bridge_Suite_Strategy::combine_imports($merged,[$merged['imports'][0]]);
$assert(count($removed['rows'])===2 && $removed['rows'][1]['page_type']==='first' && count($removed['assignments'])===2,'Removing a file restores shared URL metadata and retains shared assignments.');
$empty=Nova_Bridge_Suite_Strategy::combine_imports($removed,[]);
$assert($empty['rows']===[] && $empty['assignments']===[] && $empty['profiles']===$base['profiles'],'Removing all files keeps mappings and clears strategy scope.');
$assert(is_wp_error(Nova_Bridge_Suite_Strategy::update_imports($merged,['files'=>[['name'=>'other.csv','csv'=>"url\nhttps://other.test/a/"]]])),'Different-client files must be rejected.');
$assert(is_wp_error(Nova_Bridge_Suite_Strategy::update_imports($base,['files'=>[$files['files'][0],['name'=>'bad.csv','csv'=>'invalid']]])) && $base['imports']===[],'One invalid file rejects the batch without mutating its input.');
$assert(is_wp_error(Nova_Bridge_Suite_Strategy::update_imports($base,['files'=>array_fill(0,51,$files['files'][0])])),'File count is bounded.');
$legacy=Nova_Bridge_Suite_Strategy::update_imports($merged,['urls'=>['https://example.test/replacement/']]);
$assert(count($legacy['imports'])===1 && count($legacy['rows'])===1 && $legacy['profiles']===$base['profiles'],'Legacy API replacement remains compatible.');
echo "PASS 8 multiple-file import and removal checks.\n";
