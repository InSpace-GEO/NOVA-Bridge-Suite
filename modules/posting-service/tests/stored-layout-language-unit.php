<?php
/** Scoped Polylang routing uses real exact-URL ownership; provider and native inventory are mocked. */
define( 'ABSPATH', __DIR__ ); $checks = 0;
class WP_Error { private $code; private $message; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function current_user_can( $cap, ...$args ) { return ! $GLOBALS['denied']; }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function get_option( $key, $default = false ) { return $key === 'home' ? ( $GLOBALS['site_home'] ?? 'https://example.test' ) : ( $key === 'permalink_structure' ? '/%postname%/' : $default ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function get_locale() { return 'de_DE'; }
function update_option( ...$args ) { throw new RuntimeException( 'Persistent language settings must not change' ); }
function pll_save_post_translations( ...$args ) { throw new RuntimeException( 'Independent page must not create translation links' ); }
function pll_languages_list( $args ) { return array_values( $args['fields'] === 'slug' ? array_keys( $GLOBALS['languages'] ) : $GLOBALS['languages'] ); }
function pll_default_language( $field = 'slug' ) { return $field === 'locale' ? ( $GLOBALS['languages'][$GLOBALS['default_language']] ?? false ) : $GLOBALS['default_language']; }
function pll_home_url( $code ) { return $GLOBALS['homes'][$code]; }
function pll_is_translated_post_type( $type ) { return in_array( $type, [ 'page', 'post', 'case_study' ], true ); }
function get_post_type_object( $type ) { return (object) [ 'hierarchical' => $type === 'page' ]; }
function pll_get_post_language( $id, $field = 'slug' ) { $code = $GLOBALS['post_languages'][$id] ?? false; return $field === 'locale' && $code ? $GLOBALS['languages'][$code] : $code; }
function pll_set_post_language( $id, $code ) { ++$GLOBALS['assignments']; $GLOBALS['post_languages'][$id] = $code; }
function get_posts( $args ) { $ids = array_filter( array_keys( $GLOBALS['links'] ), static function ( $id ) use ( $args ) { return ( $GLOBALS['native_posts'][$id]->post_type ?? 'page' ) === $args['post_type']; } ); return array_slice( $ids, $args['offset'], $args['posts_per_page'] ); }
function get_permalink( $id ) { return $GLOBALS['links'][$id] ?? ''; }
function get_post( $id ) { return $GLOBALS['native_posts'][$id] ?? null; }
class Language_Rewrite { public $structure = '/%pagename%/'; public function get_page_permastruct() { return $this->structure; } public function get_extra_permastruct( $type ) { return '/cases/%case_study%/'; } }
class Nova_Bridge_Suite_Document_Mapping { public static function resources() { return array_map( static function ( $type ) { return [ 'id' => 'post_type:' . $type, 'type' => 'post_type', 'post_type' => $type, 'availability' => 'available', 'can_inspect_existing' => true ]; }, [ 'page', 'post', 'case_study' ] ); } }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-writer.php';
use Nova_Bridge_Suite_Stored_Layout_Language as Language;
function check( $truth, $message ) { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
function good( $value, $message ) { check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function bad( $value, $message ) { check( is_wp_error( $value ), $message ); }
$languages = [ 'de-de' => 'de_DE', 'en' => 'en_US', 'nl-nl' => 'nl_NL', 'nl-be' => 'nl_BE' ];
$homes = [ 'de-de' => 'https://example.test/', 'en' => 'https://example.test/en/', 'nl-nl' => 'https://example.test/nl-nl/', 'nl-be' => 'https://example.test/nl-be/' ];
$post_languages = []; $links = []; $assignments = 0; $denied = false; $default_language = 'de-de'; $wp_rewrite = new Language_Rewrite();
$german = good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'de-DE' ), 'Default language page uses root without a synthetic parent' );
check( $german['parent'] === 0 && $german['slug'] === 'new-service' && $german['native_language']['code'] === 'de-de', 'Configured German default resolves its nonstandard slug' );
$target_url = new ReflectionMethod( Nova_Bridge_Suite_Stored_Layout_Writer::class, 'target_url' );
foreach ( [ '/new-service/', 'new-service/', 'https://example.test/new-service/' ] as $url ) {
    check( good( Language::creation( 'post_type:page', $target_url->invoke( null, $url ), 'nl-NL' ), 'Stored-writer URL resolution reaches native routing' ) === $german, 'Relative and full URLs retain exact native default-language routing' );
}
foreach ( [ '//example.test/new-service/', '//evil.test/new-service/', 'https://evil.test/new-service/', 'http://example.test/new-service/', '/new//service/', '../new-service/', '/%2e%2e/new-service/', '/new%2fservice/', "/new\tservice/", '/new-service/?x=1', '/new-service/#fragment', '?x=1', '#fragment' ] as $url ) {
    bad( Language::creation( 'post_type:page', $target_url->invoke( null, $url ), 'nl-NL' ), 'Relative URL support does not bypass real canonical URL validation' );
}
$site_home = 'https://example.test/site/'; $homes['de-de'] = $site_home;
$subdirectory = good( Language::creation( 'post_type:page', 'https://example.test/site/new-service/', 'nl-NL' ), 'Native default language can use a subdirectory home' );
foreach ( [ 'new-service/', '/site/new-service/', 'https://example.test/site/new-service/' ] as $url ) {
    check( good( Language::creation( 'post_type:page', $target_url->invoke( null, $url ), 'nl-NL' ), 'Resolve relative URL against its home or origin' ) === $subdirectory, 'Bare paths use the home prefix and root paths retain their exact origin path' );
}
bad( Language::creation( 'post_type:page', $target_url->invoke( null, '/new-service/' ), 'nl-NL' ), 'A root-relative path cannot escape the connected WordPress home prefix' );
unset( $site_home ); $homes['de-de'] = 'https://example.test/';
$dutch_content = good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'nl-NL' ), 'Dutch content metadata does not select a Dutch native destination' );
check( $dutch_content === $german && $dutch_content['language'] === 'de_DE', 'Payload language does not change the default-language route or native assignment' );
check( good( Language::creation( 'post_type:page', 'https://example.test/new-service/', 'nl' ), 'Generic content metadata does not require multilingual inference' ) === $german, 'Generic payload language also leaves native default unchanged' );
foreach ( [ [ 'https://example.test/nl-nl/new/', 'nl-NL' ], [ 'https://example.test/en/new/', 'de-DE' ], [ 'https://example.test/nl-be/new/', 'nl-NL' ], [ 'https://evil.test/new/', 'nl-NL' ], [ 'https://example.test/new/?lang=nl-nl', 'nl-NL' ], [ 'https://example.test/', 'nl-NL' ] ] as $case ) { bad( Language::creation( 'post_type:page', $case[0], $case[1] ), 'Nondefault/foreign/noncanonical target fails without rewriting its URL' ); }
$links[10] = 'https://example.test/services/'; $post_languages[10] = 'de-de';
$native_posts[10] = (object) [ 'post_type' => 'page', 'post_name' => 'services', 'post_parent' => 42 ];
$existing = good( Language::existing( 'post_type:page', 10, $links[10] ), 'An existing default-language page retains its exact canonical identity' );
check( $existing['slug'] === 'services' && $existing['parent'] === 42 && $existing['native_language']['code'] === 'de-de', 'Existing native parent and default language are preserved without assigning a language' );
bad( Language::existing( 'post_type:page', 10, 'https://example.test/another/' ), 'Existing replacement rejects aliases and different canonical paths' );
$post_languages[10] = 'nl-be'; bad( Language::existing( 'post_type:page', 10, $links[10] ), 'A nondefault-language existing page cannot be adopted or relabeled' ); $post_languages[10] = 'de-de';
$native_posts[10]->post_type = 'post'; bad( Language::existing( 'post_type:page', 10, $links[10] ), 'Saved Elementor page templates cannot adopt another native post type' ); $native_posts[10]->post_type = 'page';
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
$wp_rewrite->structure = '/%pagename%/'; $links = [];
$post_target = good( Language::creation( 'post_type:post', 'https://example.test/new-article/', 'nl-NL' ), 'Regular Posts use their native root permalink and site default language' );
check( $post_target['slug'] === 'new-article' && $post_target['parent'] === 0 && $post_target['native_language']['post_type'] === 'post', 'Posts retain their own destination in the language context' );
good( Language::assign( 31, $post_target ), 'A reserved regular Post receives the default language' );
good( Language::verify( 'post_type:post', 31, $post_target ), 'Regular Post language is verified without a Page-only restriction' );
bad( Language::verify( 'post_type:page', 31, $post_target ), 'Language context cannot be reused for another destination' );
bad( Language::creation( 'post_type:post', 'https://example.test/parent/new-article/', 'nl-NL' ), 'Flat Posts do not invent parent hierarchies' );
$cpt_target = good( Language::creation( 'post_type:case_study', 'https://example.test/cases/new-case/', 'nl-NL' ), 'An arbitrary registered custom post type uses its own native rewrite base' );
check( $cpt_target['slug'] === 'new-case' && $cpt_target['native_language']['post_type'] === 'case_study', 'Custom post type and slug survive routing' );
good( Language::assign( 32, $cpt_target ), 'Custom native post type uses the same language assignment path' );
bad( Language::creation( 'post_type:case_study', 'https://example.test/new-case/', 'nl-NL' ), 'A custom post type cannot publish outside its native permalink structure' );
echo "Stored-layout language checks: $checks passed.\n";
