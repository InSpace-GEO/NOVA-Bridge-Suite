<?php
/** Standalone connection boundary checks; no WordPress or network mutations. */
if ( defined( 'ABSPATH' ) ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
class WP_Error { public function __construct( $code = '', $message = '' ) { $this->message = $message; } public $message; public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class Nova_Bridge_Suite_Posting_Jobs {
    public static $unfinished = false;
    public static function has_unfinished_for_site( $site ) { return self::$unfinished; }
}
class Nova_Bridge_Suite_Posting_Delivery {
    public static $initializations = 0; public static $storage = true;
    public static function initialize() { ++self::$initializations; }
    public static function ensure_schema() { return self::$storage; }
}
$saved = []; $admin = true; $errors = [];
function get_option( $key, $default ) { return $GLOBALS['saved']; }
function current_user_can( $cap ) { return $GLOBALS['admin']; }
function get_current_user_id() { return 17; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_settings_error( $option, $code, $message ) { $GLOBALS['errors'][] = $code; }
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-posting-settings.php';
$checks = 0;
function check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; }
$input = [ 'base_url' => 'https://posting.example', 'site_id' => '12345678-1234-4234-8234-123456789abc', 'token' => 'nova_pk_fixture1234567890', 'webhook_secret' => 'fixture-signing-secret', 'enabled' => 1 ];
$first = Nova_Bridge_Suite_Posting_Settings::sanitize( $input );
check( $first['enabled'] && $first['paused'] && 17 === $first['actor_user_id'], 'New origin pauses until resumed and retains authorized actor.' );
$saved = $first;
$blank = array_merge( $input, [ 'token' => '', 'webhook_secret' => '' ] );
$next = Nova_Bridge_Suite_Posting_Settings::sanitize( $blank );
check( $next['token'] === $saved['token'] && $next['webhook_secret'] === $saved['webhook_secret'] && ! $next['paused'], 'Blank secret controls retain stored values while allowing resume.' );
$admin = false;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( $input ) === $saved, 'Nonadministrator cannot replace connection.' );
$admin = true;
foreach ( [ 'http://posting.example', 'https://user:password@posting.example', 'https://posting.example/path', 'https://posting.example?token=value', 'https://posting.example/#fragment' ] as $url ) {
    check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => $url ] ) ) === $saved, 'Only a credential-free HTTPS origin is accepted.' );
}
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'site_id' => 'another-site' ] ) ) === $saved, 'Site is a UUID.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'token' => "secret\r\nheader" ] ) ) === $saved, 'Credential cannot inject headers.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = true;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => 'https://other.example' ] ) ) === $saved, 'Unfinished work cannot be abandoned through origin replacement.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = new WP_Error();
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'site_id' => '12345678-1234-4234-8234-123456789abd' ] ) ) === $saved, 'Database failures preserve current site binding.' );
Nova_Bridge_Suite_Posting_Jobs::$unfinished = false;
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $input, [ 'base_url' => 'https://other.example' ] ) )['paused'], 'Safe origin replacement requires resume.' );
Nova_Bridge_Suite_Posting_Settings::updated( $first, $next, Nova_Bridge_Suite_Posting_Settings::OPTION );
check( 1 === Nova_Bridge_Suite_Posting_Delivery::$initializations, 'Saving connection settings invokes webhook catch-up initialization.' );
Nova_Bridge_Suite_Posting_Settings::updated( $next, [ 'enabled' => false ], Nova_Bridge_Suite_Posting_Settings::OPTION );
check( 2 === Nova_Bridge_Suite_Posting_Delivery::$initializations, 'Disabling settings also initializes cleanup of the old delivery cron and catch-up marker.' );
Nova_Bridge_Suite_Posting_Delivery::$storage = false;
Nova_Bridge_Suite_Posting_Settings::updated( $first, $next, Nova_Bridge_Suite_Posting_Settings::OPTION );
check( end( $errors ) === 'nova_posting_storage', 'A storage failure remains visible to the administrator saving settings.' );
check( ! $first['url_scope_enabled'] && [] === $first['url_scope_excluded_paths'], 'Existing connections have routing disabled by default.' );
$routed = Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $blank, [ 'url_scope_enabled' => 1, 'url_scope_excluded_paths' => "/eindhoven/\n/schiphol\n/eindhoven" ] ) );
check( $routed['url_scope_enabled'] && [ '/eindhoven', '/schiphol' ] === $routed['url_scope_excluded_paths'], 'An administrator can opt into local ownership and excluded path prefixes.' );
check( $routed['site_id'] === $saved['site_id'] && $routed['token'] === $saved['token'] && $routed['webhook_secret'] === $saved['webhook_secret'], 'Routing settings do not change the shared site identity or credentials.' );
check( Nova_Bridge_Suite_Posting_Settings::sanitize( array_merge( $blank, [ 'url_scope_enabled' => 1, 'url_scope_excluded_paths' => 'https://wrong.invalid/path' ] ) ) === $saved, 'Invalid routing settings retain the previous connection.' );
echo "PASS {$checks} posting connection checks\n";
