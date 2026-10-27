<?php
/** Exercise CPT URL preparation against the actual native target ownership rules. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return 'home' === $key ? $GLOBALS['test_home'] : $default; }
function home_url( $path = '/' ) { return rtrim( $GLOBALS['test_home'], '/' ) . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-content-target.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-owned-cpt-writer.php';
$target = new ReflectionMethod( 'Nova_Bridge_Suite_Owned_CPT_Writer', 'target_url' ); $target->setAccessible( true );
$owned = new ReflectionMethod( 'Nova_Bridge_Suite_Mapped_Content_Target', 'owned_url' ); $owned->setAccessible( true );
$checks = 0;
function url_check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function owned_target( $target, $owned, $url ) { return $owned->invoke( null, $target->invoke( null, $url ) ); }

$GLOBALS['test_home'] = 'https://example.test';
$absolute = owned_target( $target, $owned, 'https://example.test/services/cleaning/' );
foreach ( [ '/services/cleaning/', 'services/cleaning/' ] as $url ) {
    $value = owned_target( $target, $owned, $url );
    url_check( ! is_wp_error( $value ) && $value['identity'] === $absolute['identity'], 'Absolute and relative URLs have the same owned identity.' );
}
$with_query = owned_target( $target, $owned, '/services/cleaning/?b=2&a=1' );
url_check( ! is_wp_error( $with_query ) && $with_query['identity'] === 'https://example.test/services/cleaning?a=1&b=2', 'A valid query is retained and canonicalized by existing rules.' );
url_check( is_wp_error( Nova_Bridge_Suite_Mapped_Content_Target::creation( 'post_type:service_page', $target->invoke( null, '/services/cleaning/?a=1' ) ) ), 'Creation still rejects query-bearing URLs rather than changing native route rules.' );
foreach ( [ '//foreign.test/services/cleaning/', 'https://foreign.test/services/cleaning/', 'http://example.test/services/cleaning/', '/services/../other', '/services/%2e%2e/other', '/services/%2Fother', '/services/cleaning/#fragment', '/services/cleaning/?a=1&a=2', '/services//cleaning/', '?query=1', '#fragment' ] as $url ) {
    url_check( is_wp_error( owned_target( $target, $owned, $url ) ), 'Unsafe or ambiguous target rejected: ' . $url );
}
$GLOBALS['test_home'] = 'https://example.test/subsite/';
$relative = owned_target( $target, $owned, 'services/cleaning/' );
$rooted = owned_target( $target, $owned, '/subsite/services/cleaning/' );
$absolute = owned_target( $target, $owned, 'https://example.test/subsite/services/cleaning/' );
url_check( ! is_wp_error( $relative ) && ! is_wp_error( $rooted ) && ! is_wp_error( $absolute ) && $relative['identity'] === $absolute['identity'] && $rooted['identity'] === $absolute['identity'], 'Relative paths retain a configured site subdirectory.' );
url_check( is_wp_error( owned_target( $target, $owned, '/services/cleaning/' ) ), 'Root-relative paths outside the WordPress home directory are rejected.' );
url_check( is_wp_error( owned_target( $target, $owned, '../elsewhere' ) ), 'Path-relative traversal cannot escape the configured home directory.' );
echo "Owned CPT relative URL: $checks checks passed\n";
