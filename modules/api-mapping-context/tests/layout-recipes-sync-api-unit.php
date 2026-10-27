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
// Exercise real endpoint dispatch/storage with a transport seam; native Elementor compilation has its own tests.
class Nova_Bridge_Suite_Stored_Recipe_Sync {
    public static $calls = [];
    public static $statuses = [];
    public static function saved_status( array $connection, string $id, int $revision ): ?array { return self::$statuses[$id][$revision] ?? null; }
    public static function sync_editor( array $connection, callable $load, bool $saving = false, string $recipe_id = '', $client = null, $locks = null, string $recover_template_id = '' ) {
        self::$calls[] = [ 'saving' => $saving, 'recipe_id' => $recipe_id ];
        $record = $load(); if ( is_wp_error( $record ) ) { return $record; }
        $record['posting_sync'] = [ 'status' => 'synced', 'verified' => true, 'default_selected' => true, 'page_type' => $record['posting_page_type'], 'recipe_revision' => $record['revision'], 'template_revision' => 1, 'template_id' => '00000000-0000-4000-8000-000000000999' ];
        self::$statuses[$record['id']][$record['revision']] = $record['posting_sync'];
        return $record;
    }
}
Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'elementor', new Nova_Bridge_Suite_Recipe_Core() );
$elementor = design( 'elementor', 'static' );
$body = save_body( $elementor, $elementor['recipe_preset'], $elementor['sample'] ); $body['posting_page_type'] = 'informative';
$elementor_saved = data( route( 'save', 'POST', [], $body ) );
check( count( Nova_Bridge_Suite_Stored_Recipe_Sync::$calls ) === 1 && Nova_Bridge_Suite_Stored_Recipe_Sync::$calls[0]['saving'], 'A static Elementor creation save must enter the stored-layout synchronization wrapper.' );
check( $elementor_saved['posting_page_type'] === 'informative' && $elementor_saved['posting_sync']['recipe_revision'] === $elementor_saved['revision'], 'The real save response must retain the selected NOVA content type and exact synchronized revision.' );
$profile = data( route( 'profile', 'GET', [ 'id' => $elementor_saved['id'] ] ) );
check( $profile['posting_page_type'] === 'informative', 'Reopening a saved layout must retain its NOVA content type.' );
check( $profile['posting_sync'] === $elementor_saved['posting_sync'], 'Reopening a Page layout returns its last exact-revision synchronization result.' );
$card = Nova_Bridge_Suite_Layout_Recipes::default_metadata( 'post_type:page' );
check( $card['posting_page_type'] === 'informative' && $card['posting_sync'] === $profile['posting_sync'], 'The layout card identifies the chosen page type and its last default confirmation.' );
$before_calls = count( Nova_Bridge_Suite_Stored_Recipe_Sync::$calls ); $catalog = data( route( 'catalog', 'GET' ) );
check( $catalog['defaults']['post_type:page'] === $card && count( Nova_Bridge_Suite_Stored_Recipe_Sync::$calls ) === $before_calls, 'Reading cards and catalog never starts another synchronization.' );
$before = $options[RECIPE_OPTION]; $retry = data( route( 'sync', 'POST', [], [ 'id' => $elementor_saved['id'], 'revision' => $elementor_saved['revision'] ] ) );
check( count( Nova_Bridge_Suite_Stored_Recipe_Sync::$calls ) === 2 && ! Nova_Bridge_Suite_Stored_Recipe_Sync::$calls[1]['saving'] && $options[RECIPE_OPTION] === $before, 'Elementor retry must route to its stored-layout adapter without another local save.' );
$dynamic = design( 'elementor', 'mixed' );
$dynamic_saved = data( route( 'save', 'POST', [], save_body( $dynamic, $dynamic['recipe_preset'], $dynamic['sample'] ) ) );
check( count( Nova_Bridge_Suite_Stored_Recipe_Sync::$calls ) === 2 && ! isset( $dynamic_saved['posting_sync'] ), 'Dynamic Elementor templates remain local and do not trigger unsupported automatic posting synchronization.' );
$local_page = design( 'core', 'static' ); $page_body = save_body( $local_page, $local_page['recipe_preset'], $local_page['sample'] ); $page_body['posting_page_type'] = 'category';
$page_category = data( route( 'save', 'POST', [], $page_body ) );
check( $page_category['posting_page_type'] === 'category' && ! isset( $page_category['posting_sync'] ), 'A category using ordinary Pages retains its explicit type without claiming unsupported builder synchronization.' );
$older_client = save_body( $local_page, $local_page['recipe_preset'], $local_page['sample'], 'post_type:page', $page_category['id'], $page_category['revision'] );
$preserved = data( route( 'save', 'POST', [], $older_client ) );
check( $preserved['posting_page_type'] === 'category', 'An older client omitting the field preserves a saved page-type choice.' );
$local_post = design( 'core', 'static', 'post_type:post' );
$post_saved = data( route( 'save', 'POST', [], save_body( $local_post, $local_post['recipe_preset'], $local_post['sample'], 'post_type:post' ) ) );
check( $post_saved['posting_page_type'] === 'informative' && Nova_Bridge_Suite_Layout_Recipes::default_metadata( 'post_type:post' )['posting_page_type'] === 'informative', 'New ordinary Posts default to Informative and show it on their card.' );
$category = design( 'core', 'static', 'taxonomy:product_cat' ); $wrong_category = save_body( $category, $category['recipe_preset'], $category['sample'], 'taxonomy:product_cat' ); $wrong_category['posting_page_type'] = 'service';
rejected( route( 'save', 'POST', [], $wrong_category ), 400, 'A WooCommerce taxonomy cannot falsely claim a Service default.' );
echo 'PASS ' . $checks . " layout recipe save/sync integration checks.\n";
