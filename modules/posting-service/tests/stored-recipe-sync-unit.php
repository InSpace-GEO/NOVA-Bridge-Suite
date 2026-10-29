<?php
/** Saved layout -> real V3 projection -> conditional template transport -> retained profile. */
require __DIR__ . '/mapping-sync-unit.php';
function current_user_can( ...$args ) { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_kses_post( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function get_post( $id ) { throw new RuntimeException( 'The example page is deleted; synchronization must use the stored layout.' ); }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-recipe-sync.php';
class Nova_Bridge_Suite_Layout_Recipes {
    public static $record;
    public static function delivery_record( $id, $revision ) { return self::$record['id'] === $id && self::$record['revision'] === $revision ? self::$record : new WP_Error( 'missing', 'Unknown revision.' ); }
    public static function defaults() { return [ self::$record['resource_id'] => self::$record ]; }
}
class Nova_Bridge_Suite_Stored_Layout_Writer {
    public static $failure = null;
    public static function probe_mapping( $template, $mapping, $draft, $context ) { check( $context['actor_user_id'] === 7, 'Configured delivery actor is supplied for approval.' ); return self::$failure ?: Nova_Bridge_Suite_Stored_Layout::validate( $draft ); }
}
class Stored_Sync_Lock { public function lock( $name ) { return true; } public function unlock( $name ) {} }
function stored_ok( $value ) { check( ! is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_code() . ': ' . $value->get_error_message() : 'Success' ); return $value; }
function stored_sync( $client, $connection, $recover = '', ?array $replace_default = null ) { return Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () { return Nova_Bridge_Suite_Layout_Recipes::$record; }, false, Nova_Bridge_Suite_Layout_Recipes::$record['id'], $client, new Stored_Sync_Lock(), $recover, $replace_default ); }
$connection += [ 'enabled' => true, 'actor_user_id' => 7 ];
$recipe_id = '33333333-3333-4333-8333-333333333333'; $template_id = '44444444-4444-4444-8444-444444444444';
$record = [ 'id' => $recipe_id, 'revision' => 1, 'resource_id' => 'post_type:page', 'builder' => 'elementor', 'mode' => 'static', 'posting_page_type' => 'service', 'label' => 'Service creation', 'recipe' => [ 'builder' => 'elementor', 'mode' => 'static', 'instructions' => 'Put the introductory content in the hero.', 'regions' => [], 'blueprint' => [ 'document' => [ [ 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Example heading', 'header_size' => 'h1', 'opacity' => 0.75, 'title_color' => '#123abc' ], 'elements' => [] ] ], 'meta' => [ 'background_color' => '#ddeeff' ], 'template' => 'elementor_canvas' ], 'bindings' => [ [ 'id' => 'hero', 'target' => '/document/0/settings/title', 'source' => '/hero_title', 'format' => 'text', 'label' => 'Hero heading', 'required' => true, 'instructions' => 'Choose the main service heading.' ] ] ] ];
Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$local = stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
$input = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( $local ) );
check( $input['definition']['version'] === 3 && $input['mapping']['version'] === 3, 'Visual field purposes project to V3.' );
check( strpos( json_encode( $input ), '#123abc' ) === false && strpos( json_encode( $input ), 'abc1234' ) === false, 'Native styling and widget IDs stay in WordPress.' );
$remote = array_merge( $input, [ 'id' => $template_id, 'revision' => 1, 'retired_at' => null, 'created_at' => '2026-10-26T12:00:00Z', 'updated_at' => '2026-10-26T12:00:00Z' ] );
$other_id = '66666666-6666-4666-8666-666666666666';
$defaults = [ 'revision' => 1, 'defaults' => [ 'informative' => $other_id ] ];
$selected_defaults = [ 'revision' => 2, 'defaults' => [ 'informative' => $other_id, 'service' => $template_id ] ];
$client = new QueueClient( $connection );
$client->queue = [ [ 'GET', '/template-defaults', reply( $defaults ) ], [ 'POST', '/templates', reply( $remote, 201 ) ], [ 'GET', '/templates/' . $template_id, reply( $remote ) ], [ 'PUT', '/template-defaults', reply( $selected_defaults ) ], [ 'GET', '/template-defaults', reply( $selected_defaults ) ] ];
$result = stored_ok( stored_sync( $client, $connection ) );
check( $result['posting_sync']['verified'] && $result['posting_sync']['default_selected'] && $result['posting_sync']['defaults_revision'] === 2 && $result['posting_sync']['page_type'] === 'service' && !$result['local_only'] && $result['posting_sync']['recipe_revision'] === 1, 'Editor receives matching verified saved revision only after reading back its Service default.' );
check( $client->calls[3][3] === [ 'If-Match' => '"1"' ], 'Default selection is conditional.' );
check( $client->calls[3][2]['defaults']['informative'] === $other_id, 'Selecting Service preserves the Informative default.' );
check( Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $connection, $recipe_id, 1 ) === $result['posting_sync'], 'The exact successful result survives reloading without another remote read.' );
check( Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $connection, $recipe_id, 2 ) === null, 'A new recipe revision cannot inherit earlier confirmation.' );
check( Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( array_replace( $connection, [ 'site_id' => $other_id ] ), $recipe_id, 1 ) === null && Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( array_replace( $connection, [ 'base_url' => 'https://another-environment.invalid' ] ), $recipe_id, 1 ) === null, 'Another site or service origin cannot inherit confirmation.' );
$frozen = array_intersect_key( $remote, array_flip( [ 'id', 'revision', 'name', 'page_type', 'definition', 'mapping' ] ) );
$profile = stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) );
check( $profile['mode'] === 'stored_layout_v3' && $profile['local']['stored_layout']['blueprint']['document'][0]['settings']['opacity'] === 0.75, 'Approved profile retains creation mode and fractional styling.' );
check( Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'V3 discovery enabled after exact local approval.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( $selected_defaults ) ], [ 'GET', '/templates/' . $template_id, reply( $remote ) ], [ 'GET', '/template-defaults', reply( $selected_defaults ) ] ];
stored_ok( stored_sync( $client, $connection ) );
check( count( $client->calls ) === 8, 'Same saved revision rechecks its default without a duplicate create or default write.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( $selected_defaults ) ], [ 'GET', '/templates/' . $template_id, reply( $remote ) ], [ 'GET', '/template-defaults', reply( [ 'revision' => 3, 'defaults' => [ 'informative' => $other_id ] ] ) ] ];
$changed = stored_ok( stored_sync( $client, $connection ) );
check( $changed['posting_sync']['status'] === 'failed' && $changed['posting_sync']['code'] === 'nova_stored_recipe_default_conflict' && $changed['local_only'], 'A removed default during activation never reports synchronization success.' );
check( Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $connection, $recipe_id, 1 )['status'] === 'failed', 'The latest failed verification replaces an old success for the same revision.' );
$record['revision'] = 2; $record['recipe']['bindings'][0]['instructions'] = 'Use the first heading from the supplied service text.';
Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$input2 = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ) ) );
$remote2 = array_merge( $remote, $input2, [ 'revision' => 2 ] );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ], [ 'PUT', '/templates/' . $template_id, new WP_Error( 'transport_timeout', 'Lost update response.', [ 'status' => 503 ] ) ] ];
$failed = stored_ok( stored_sync( $client, $connection ) ); check( $failed['local_only'] && $failed['posting_sync']['status'] === 'failed', 'Uncertain remote update is reported and retained.' );
$called = false;
$blocked = Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () use ( &$called ) { $called = true; return []; }, true, $recipe_id, $client, new Stored_Sync_Lock() );
check( is_wp_error( $blocked ) && !$called, 'A pending update prevents another local revision before reconciliation.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ], [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ] ];
$recovered = stored_ok( stored_sync( $client, $connection ) ); check( $recovered['posting_sync']['recipe_revision'] === 2 && !$recovered['local_only'], 'Lost PUT acknowledgement recovers exact revision without another mutation.' );
check( stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) ) === $profile, 'Later instructions do not rewrite the retained historical layout.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 3, 'defaults' => [ 'service' => '55555555-5555-4555-8555-555555555555' ] ] ) ] ];
$conflict = stored_ok( stored_sync( $client, $connection ) ); check( $conflict['posting_sync']['code'] === 'nova_stored_recipe_default_conflict', 'Unrelated remote defaults are not overwritten.' );
$replacement = [ 'page_type' => 'service', 'template_id' => '55555555-5555-4555-8555-555555555555', 'defaults_revision' => 3 ];
check( $conflict['posting_sync']['default_replacement'] === $replacement, 'A conflict identifies the exact default and revision the administrator must review.' );
$before_calls = count( $client->calls );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 4, 'defaults' => [ 'service' => $replacement['template_id'] ] ] ) ] ];
$stale = stored_ok( stored_sync( $client, $connection, '', $replacement ) );
check( count( $client->calls ) === $before_calls + 1 && $stale['posting_sync']['code'] === 'nova_stored_recipe_default_conflict' && $stale['posting_sync']['default_replacement']['defaults_revision'] === 4, 'A stale reviewed default cannot mutate a template or silently overwrite a changed remote revision.' );
$before_defaults = [ 'revision' => 3, 'defaults' => [ 'service' => $replacement['template_id'], 'informative' => $other_id ] ];
$after_defaults = [ 'revision' => 4, 'defaults' => [ 'service' => $template_id, 'informative' => $other_id ] ];
$client->queue = [ [ 'GET', '/template-defaults', reply( $before_defaults ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ], [ 'PUT', '/template-defaults', reply( $after_defaults ) ], [ 'GET', '/template-defaults', reply( $after_defaults ) ] ];
$before_calls = count( $client->calls ); $switched = stored_ok( stored_sync( $client, $connection, '', $replacement ) );
check( $switched['posting_sync']['verified'] && $switched['posting_sync']['default_selected'] && $client->calls[$before_calls + 2][3] === [ 'If-Match' => '"3"' ] && $client->calls[$before_calls + 2][2]['defaults']['informative'] === $other_id, 'Explicitly reviewed replacement uses the existing conditional defaults API and preserves other page types.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( $before_defaults ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ], [ 'PUT', '/template-defaults', new WP_Error( 'precondition_failed', 'Another administrator changed the defaults.', [ 'status' => 412 ] ) ] ];
$raced = stored_ok( stored_sync( $client, $connection, '', $replacement ) );
check( $raced['local_only'] && $raced['posting_sync']['code'] === 'precondition_failed' && empty( $raced['posting_sync']['default_selected'] ), 'A concurrent default change after review never reports success or retries an unconditional overwrite.' );
$called = false; $malformed = Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () use ( &$called ) { $called = true; return []; }, true, $recipe_id, $client, new Stored_Sync_Lock(), '', $replacement );
check( is_wp_error( $malformed ) && ! $called, 'Creating a new local revision cannot implicitly authorize replacement of the remote default.' );
$record['posting_page_type'] = 'informative'; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ] ];
$type_error = stored_ok( stored_sync( $client, $connection ) ); check( $type_error['posting_sync']['code'] === 'nova_stored_recipe_page_type_changed', 'A synchronized template cannot strand its old page-type default.' );
foreach ( [ 'informative', 'category' ] as $index => $page_type ) {
    $record['id'] = $index === 0 ? '77777777-7777-4777-8777-777777777777' : '88888888-8888-4888-8888-888888888888';
    $record['revision'] = 1; $record['posting_page_type'] = $page_type; $record['resource_id'] = $index === 0 ? 'post_type:post' : 'post_type:page'; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
    $projection = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ) ) );
    check( $projection['page_type'] === $page_type, 'The selected ' . $page_type . ' type reaches the remote template definition.' );
    $type_template_id = $index === 0 ? 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb' : 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $remote_for_type = array_merge( $remote, $projection, [ 'id' => $type_template_id, 'revision' => 1 ] );
    $before_defaults = [ 'revision' => 3, 'defaults' => [ 'service' => $other_id ] ];
    $after_defaults = [ 'revision' => 4, 'defaults' => [ 'service' => $other_id, $page_type => $type_template_id ] ];
    $client->queue = [ [ 'GET', '/template-defaults', reply( $before_defaults ) ], [ 'POST', '/templates', reply( $remote_for_type, 201 ) ], [ 'GET', '/templates/' . $type_template_id, reply( $remote_for_type ) ], [ 'PUT', '/template-defaults', reply( $after_defaults ) ], [ 'GET', '/template-defaults', reply( $after_defaults ) ] ];
    $chosen = stored_ok( stored_sync( $client, $connection ) );
    check( $chosen['posting_sync']['page_type'] === $page_type && $chosen['posting_sync']['default_selected'], 'The ' . $page_type . ' default is selected and independently confirmed for its native destination.' );
    $native = stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
    check( $native['routing']['post_type'] === ( $index === 0 ? 'post' : 'page' ) && $native['stored_layout']['resource_id'] === $record['resource_id'], 'The native destination remains frozen in the layout independently of its NOVA page type.' );
}
$record['id'] = '99999999-9999-4999-8999-999999999999'; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$projection = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ) ) );
$remote_failure = array_merge( $remote, $projection, [ 'id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'revision' => 1 ] );
Nova_Bridge_Suite_Stored_Layout_Writer::$failure = new WP_Error( 'native_widget_unavailable', 'Enable the template widget before synchronizing.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 1, 'defaults' => [] ] ) ], [ 'POST', '/templates', reply( $remote_failure, 201 ) ], [ 'GET', '/templates/' . $remote_failure['id'], reply( $remote_failure ) ] ];
$start = count( $client->calls ); $native_failure = stored_ok( stored_sync( $client, $connection ) );
check( count( $client->calls ) === $start + 3 && $native_failure['posting_sync']['code'] === 'native_widget_unavailable' && $native_failure['local_only'], 'Template creation followed by failed native approval cannot select a default or report success.' );
check( Nova_Bridge_Suite_Stored_Recipe_Sync::saved_status( $connection, $record['id'], 1 )['message'] === 'Enable the template widget before synchronizing.', 'The actionable activation failure survives reopening the layout.' );
echo 'PASS ' . $checks . " stored recipe synchronization assertions\n";
