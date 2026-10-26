<?php
/** Core/WPML default selection delegates native routing without allowing payload-language retargeting. */
define( 'ABSPATH', __DIR__ ); $checks = 0; $wpml = false; $options = [ 'WPLANG' => 'de_DE' ]; $current_locale = 'nl_NL'; $wpml_default = 'de'; $calls = [];
class WP_Error { public $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; } }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function get_locale() { return $GLOBALS['current_locale']; }
function has_filter( $name ) { return $GLOBALS['wpml'] && $name === 'wpml_element_language_details'; }
function apply_filters( $name, $value ) { return $name === 'wpml_default_language' ? $GLOBALS['wpml_default'] : $value; }
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function creation( $resource, $url, $language ) { $GLOBALS['calls'][] = [ 'creation', $language, $url ]; return [ 'target_url' => $url, 'language' => $language, 'native_language' => [ 'provider' => $GLOBALS['wpml'] ? 'wpml' : 'core', 'code' => strtolower( str_replace( '_', '-', $language ) ) ] ]; }
    public static function with_creation_language( $target, $work ) { $GLOBALS['calls'][] = [ 'context', $target['language'] ]; return $work(); }
    public static function verify_created_language( $resource, $kind, $id, $target ) { $GLOBALS['calls'][] = [ 'verify', $target['language'] ]; return true; }
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
use Nova_Bridge_Suite_Stored_Layout_Language as Language;
function check( $truth, $message ) { ++$GLOBALS['checks']; if ( ! $truth ) { throw new RuntimeException( $message ); } }
$target = Language::creation( 'post_type:page', 'https://example.test/new/', 'nl-NL' );
check( $target['language'] === 'de-DE' && $calls[0] === [ 'creation', 'de-DE', 'https://example.test/new/' ], 'Core uses configured WPLANG, ignoring payload and current request locale' );
check( Language::with_context( $target, static function () { return 'done'; } ) === 'done', 'Core default target uses unchanged native context delegate' );
check( Language::verify( 'post_type:page', 10, $target ) === true, 'Core default target uses unchanged native language readback' );
$old = $target; $old['native_language']['code'] = 'nl-nl'; $old['language'] = 'nl-NL'; $before = count( $calls );
check( is_wp_error( Language::with_context( $old, static function () { throw new RuntimeException( 'Should not mutate' ); } ) ) && count( $calls ) === $before, 'Retained nondefault core target is rejected before invoking the native context' );
$options['WPLANG'] = ''; $target = Language::creation( 'post_type:page', 'https://example.test/new/', 'de-DE' ); check( $target['language'] === 'en-US', 'Empty WordPress language option means native en-US default' );
$wpml = true; $target = Language::creation( 'post_type:page', 'https://example.test/new/', 'nl-NL' );
check( $target['language'] === 'de' && $target['native_language']['provider'] === 'wpml', 'WPML chooses its configured default, independent of content locale or core option' );
check( Language::with_context( $target, static function () { return true; } ) === true && Language::verify( 'post_type:page', 10, $target ) === true, 'WPML default context and verification continue to delegate' );
$target['language'] = 'de-DE'; check( Language::with_context( $target, static function () { return true; } ) === true, 'Previously retained default locale spelling remains compatible with its native default code' );
$wpml_default = 'nl'; check( is_wp_error( Language::with_context( $target, static function () { return true; } ) ), 'A changed WPML default does not silently move a retained association' );
$wpml_default = ''; check( is_wp_error( Language::creation( 'post_type:page', 'https://example.test/new/', 'nl-NL' ) ), 'Unavailable WPML default fails closed' );
echo "Stored-layout core/default language checks: $checks passed.\n";
