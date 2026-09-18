<?php
/** Standalone connection boundary checks; no WordPress or network mutations. */
if ( defined( 'ABSPATH' ) ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ );
class WP_Error {}
class Nova_Bridge_Suite_Posting_Jobs {
    public static $unfinished = false;
    public static function has_unfinished_for_site( $site ) { return self::$unfinished; }
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
echo "PASS {$checks} posting connection checks\n";
