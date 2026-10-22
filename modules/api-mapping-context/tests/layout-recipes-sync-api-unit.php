<?php
/** Real save route and adapter: a supported local save must not claim unsupported remote sync. */
require __DIR__ . '/layout-recipes-api-unit.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-settings.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-posting-client.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-posting-protocol.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-static-recipe-adapter.php';
function wp_parse_url( $url ) { return parse_url( $url ); }
class Nova_Bridge_Suite_Posting_Jobs {
    static $held = false;
    function lock( $id ) { self::$held = true; return true; }
    function unlock( $id ) { self::$held = false; }
}
$checks = 0;
$options['nova_bridge_posting_connection'] = [ 'enabled' => true, 'site_id' => '00000000-0000-4000-8000-000000000111', 'base_url' => 'https://posting.fixture.invalid', 'token' => 'fixture-token-not-a-real-secret' ];
$design = design( 'core', 'static', 'taxonomy:product_cat' );
$body = save_body( $design, $design['recipe_preset'], $design['sample'], 'taxonomy:product_cat' );
$saved = data( route( 'save', 'POST', [], $body ) );
check( $saved['revision'] === 1 && $saved['default_saved'] && $saved['local_only'] && $saved['posting_sync']['status'] === 'failed', 'The real save route must retain the local revision and report unsupported remote projection separately.' );
check( str_starts_with( $saved['posting_sync']['code'], 'nova_static_' ) && ! Nova_Bridge_Suite_Posting_Jobs::$held, 'The real adapter must run and always release the editor lock.' );
$before = $options[RECIPE_OPTION]; $retry = data( route( 'sync', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1 ] ) );
check( $retry['posting_sync']['status'] === 'failed' && $options[RECIPE_OPTION] === $before, 'Retrying remote sync never creates another local revision.' );
rejected( route( 'sync', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1 ], [ 'x-wp-nonce' => 'invalid' ] ), 403, 'Sync requires the valid administrator session nonce.' );
echo 'PASS ' . $checks . " layout recipe save/sync integration checks.\n";
