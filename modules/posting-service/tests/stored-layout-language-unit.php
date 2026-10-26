<?php
/** Scoped Polylang routing uses real exact-URL ownership; provider and native inventory are mocked. */
define( 'ABSPATH', __DIR__ ); $checks = 0;
class WP_Error { private $code; private $message; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function current_user_can( $cap, ...$args ) { return ! $GLOBALS['denied']; }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function get_option( $key, $default = false ) { return $key === 'home' ? 'https://example.test' : $default; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_locale() { return 'de_DE'; }
function update_option( ...$args ) { throw new RuntimeException( 'Persistent language settings must not change' ); }
function pll_save_post_translations( ...$args ) { throw new RuntimeException( 'Independent page must not create translation links' ); }
function pll_languages_list( $args ) { return array_values( $args['fields'] === 'slug' ? array_keys( $GLOBALS['languages'] ) : $GLOBALS['languages'] ); }
function pll_default_language( $field = 'slug' ) { return $field === 'locale' ? ( $GLOBALS['languages'][$GLOBALS['default_language']] ?? false ) : $GLOBALS['default_language']; }
function pll_home_url( $code ) { return $GLOBALS['homes'][$code]; }
function pll_is_translated_post_type( $type ) { return $type === 'page'; }
function pll_get_post_language( $id, $field = 'slug' ) { $code = $GLOBALS['post_languages'][$id] ?? false; return $field === 'locale' && $code ? $GLOBALS['languages'][$code] : $code; }
function pll_set_post_language( $id, $code ) { ++$GLOBALS['assignments']; $GLOBALS['post_languages'][$id] = $code; }
function get_posts( $args ) { return array_slice( array_keys( $GLOBALS['links'] ), $args['offset'], $args['posts_per_page'] ); }
function get_permalink( $id ) { return $GLOBALS['links'][$id] ?? ''; }
class Language_Rewrite { public $structure = '/%pagename%/'; public function get_page_permastruct() { return $this->structure; } }
class Nova_Bridge_Suite_Document_Mapping { public static function resources() { return [ [ 'id' => 'post_type:page', 'type' => 'post_type', 'post_type' => 'page', 'availability' => 'available', 'can_inspect_existing' => true ] ]; } }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
use Nova_Bridge_Suite_Stored_Layout_Language as Language;
function check( $truth, $message ) { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function good( $value, $message ) { check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function bad( $value, $message ) { check( is_wp_error( $value ), $message ); }
$languages = [ 'de-de' => 'de_DE', 'en' => 'en_US', 'nl-nl' => 'nl_NL', 'nl-be' => 'nl_BE' ];
$homes = [ 'de-de' => 'https://example.test/', 'en' => 'https://example.test/en/', 'nl-nl' => 'https://example.test/nl-nl/', 'nl-be' => 'https://example.test/nl-be/' ];
$post_languages = []; $links = []; $assignments = 0; $denied = false; $default_language = 'de-de'; $wp_rewrite = new Language_Rewrite();
$german = good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'de-DE' ), 'Default language page uses root without a synthetic parent' );
check( $german['parent'] === 0 && $german['slug'] === 'new-service' && $german['native_language']['code'] === 'de-de', 'Configured German default resolves its nonstandard slug' );
$dutch_content = good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'nl-NL' ), 'Dutch content metadata does not select a Dutch native destination' );
check( $dutch_content === $german && $dutch_content['language'] === 'de_DE', 'Payload language does not change the default-language route or native assignment' );
check( good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'nl' ), 'Generic content metadata does not require multilingual inference' ) === $german, 'Generic payload language also leaves native default unchanged' );
foreach ( [ [ 'https://example.test/nl-nl/new/', 'nl-NL' ], [ 'https://example.test/en/new/', 'de-DE' ], [ 'https://example.test/nl-be/new/', 'nl-NL' ], [ 'https://evil.test/new/', 'nl-NL' ], [ 'https://example.test/new/?lang=nl-nl', 'nl-NL' ], [ 'https://example.test/', 'nl-NL' ] ] as $case ) { bad( Language::creation( 'post_type:page', $case[0], $case[1] ), 'Nondefault/foreign/noncanonical target fails without rewriting its URL' ); }
$links[10] = 'https://example.test/services/'; $post_languages[10] = 'de-de';
$nested = good( Language::creation( 'post_type:page', 'https://example.test/services/new/', 'nl-NL' ), 'An existing default-language native parent is supported' ); check( $nested['parent'] === 10, 'Parent resolves by exact native URL rather than guessed slug' );
$post_languages[10] = 'nl-be'; bad( Language::creation( 'post_type:page', 'https://example.test/services/new/', 'nl-NL' ), 'A nondefault native parent is rejected despite its URL' ); $post_languages[10] = 'de-de';
bad( Language::creation( 'post_type:page', $links[10], 'nl-NL' ), 'Existing native URL ownership cannot be taken over' );
$post_languages[20] = 'nl-nl'; good( Language::assign( 20, $german ), 'The newly reserved page receives only the site default language' ); check( $post_languages[20] === 'de-de' && $assignments === 1, 'Default assignment does not follow the Dutch content metadata' );
good( Language::assign( 20, $german ), 'Language assignment is idempotent on retry' ); check( $assignments === 1, 'Repeated assignment does not repeat a native mutation' );
good( Language::verify( 'post_type:page', 20, $german ), 'Default locale and slug are verified on the native page' );
$post_languages[20] = 'nl-be'; bad( Language::verify( 'post_type:page', 20, $german ), 'A later language change cannot be reported as successful delivery' ); $post_languages[20] = 'de-de';
$homes['de-de'] = 'https://example.test/changed/'; bad( Language::with_context( $german, static function () { return true; } ), 'Default language URL drift invalidates the frozen target before writing' ); $homes['de-de'] = 'https://example.test/';
$default_language = 'nl-nl'; bad( Language::with_context( $german, static function () { return true; } ), 'Changing site default cannot silently move a retained page' );
$new_default = good( Language::creation( 'post_type:page', 'https://example.test/nl-nl/new/', 'de-DE' ), 'New work uses the actual configured default even when it has a prefix' ); check( $new_default['parent'] === 0 && $new_default['native_language']['code'] === 'nl-nl', 'Default language prefix is not mistaken for a native parent page' );
$default_language = 'de-de'; bad( Language::with_context( $new_default, static function () { return true; } ), 'Previously retained nondefault-language associations fail context verification' ); bad( Language::verify( 'post_type:page', 20, $new_default ), 'Nondefault associations fail final verification instead of retargeting' );
$denied = true; bad( Language::assign( 21, $german ), 'Publishing actor permissions apply to default assignment' ); $denied = false;
$homes['de-de'] = 'https://foreign.test/'; bad( Language::creation( 'post_type:page', 'https://foreign.test/new/', 'nl-NL' ), 'Separate default-language domains remain outside this scoped adapter' ); $homes['de-de'] = 'https://example.test/';
$default_language = 'missing'; bad( Language::creation( 'post_type:page', 'https://example.test/new/', 'nl-NL' ), 'Unavailable site default fails closed' ); $default_language = 'de-de';
$wp_rewrite->structure = '/%year%/%pagename%/'; bad( Language::creation( 'post_type:page', 'https://example.test/new/', 'nl-NL' ), 'Unknown native page routing tokens fail closed' );
echo "Stored-layout language checks: $checks passed.\n";
