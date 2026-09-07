<?php
/** Run with php, or wp eval-file. Tests import semantics and layout identity without modifying the site. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
	class WP_Error { public $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
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
