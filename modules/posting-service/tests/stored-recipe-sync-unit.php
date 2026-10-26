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
    public static function defaults() { return [ 'post_type:page' => self::$record ]; }
}
class Nova_Bridge_Suite_Stored_Layout_Writer {
    public static function probe_mapping( $template, $mapping, $draft, $context ) { check( $context['actor_user_id'] === 7, 'Configured delivery actor is supplied for approval.' ); return Nova_Bridge_Suite_Stored_Layout::validate( $draft ); }
}
class Stored_Sync_Lock { public function lock( $name ) { return true; } public function unlock( $name ) {} }
function stored_ok( $value ) { check( ! is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_code() . ': ' . $value->get_error_message() : 'Success' ); return $value; }
function stored_sync( $client, $connection, $recover = '' ) { return Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () { return Nova_Bridge_Suite_Layout_Recipes::$record; }, false, Nova_Bridge_Suite_Layout_Recipes::$record['id'], $client, new Stored_Sync_Lock(), $recover ); }
$connection += [ 'enabled' => true, 'actor_user_id' => 7 ];
$recipe_id = '33333333-3333-4333-8333-333333333333'; $template_id = '44444444-4444-4444-8444-444444444444';
$record = [ 'id' => $recipe_id, 'revision' => 1, 'resource_id' => 'post_type:page', 'builder' => 'elementor', 'mode' => 'static', 'posting_page_type' => 'service', 'label' => 'Service creation', 'recipe' => [ 'builder' => 'elementor', 'mode' => 'static', 'instructions' => 'Put the introductory content in the hero.', 'regions' => [], 'blueprint' => [ 'document' => [ [ 'id' => 'abc1234', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Example heading', 'header_size' => 'h1', 'opacity' => 0.75, 'title_color' => '#123abc' ], 'elements' => [] ] ], 'meta' => [ 'background_color' => '#ddeeff' ], 'template' => 'elementor_canvas' ], 'bindings' => [ [ 'id' => 'hero', 'target' => '/document/0/settings/title', 'source' => '/hero_title', 'format' => 'text', 'label' => 'Hero heading', 'required' => true, 'instructions' => 'Choose the main service heading.' ] ] ] ];
Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$local = stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) );
$input = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( $local ) );
check( $input['definition']['version'] === 3 && $input['mapping']['version'] === 3, 'Visual field purposes project to V3.' );
check( strpos( json_encode( $input ), '#123abc' ) === false && strpos( json_encode( $input ), 'abc1234' ) === false, 'Native styling and widget IDs stay in WordPress.' );
$remote = array_merge( $input, [ 'id' => $template_id, 'revision' => 1, 'retired_at' => null, 'created_at' => '2026-10-26T12:00:00Z', 'updated_at' => '2026-10-26T12:00:00Z' ] );
$defaults = [ 'revision' => 1, 'defaults' => [] ];
$client = new QueueClient( $connection );
$client->queue = [ [ 'GET', '/template-defaults', reply( $defaults ) ], [ 'POST', '/templates', reply( $remote, 201 ) ], [ 'GET', '/templates/' . $template_id, reply( $remote ) ], [ 'PUT', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ] ];
$result = stored_ok( stored_sync( $client, $connection ) );
check( $result['posting_sync']['verified'] && !$result['local_only'] && $result['posting_sync']['recipe_revision'] === 1, 'Editor receives matching verified saved revision.' );
check( $client->calls[3][3] === [ 'If-Match' => '"1"' ], 'Default selection is conditional.' );
$frozen = array_intersect_key( $remote, array_flip( [ 'id', 'revision', 'name', 'page_type', 'definition', 'mapping' ] ) );
$profile = stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) );
check( $profile['mode'] === 'stored_layout_v3' && $profile['local']['stored_layout']['blueprint']['document'][0]['settings']['opacity'] === 0.75, 'Approved profile retains creation mode and fractional styling.' );
check( Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'V3 discovery enabled after exact local approval.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ], [ 'GET', '/templates/' . $template_id, reply( $remote ) ] ];
stored_ok( stored_sync( $client, $connection ) );
check( count( $client->calls ) === 6, 'Same saved revision retries without a duplicate create or default write.' );
$record['revision'] = 2; $record['recipe']['bindings'][0]['instructions'] = 'Use the first heading from the supplied service text.';
Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$input2 = stored_ok( Nova_Bridge_Suite_Writing_Adapter::template_input( stored_ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ) ) );
$remote2 = array_merge( $remote, $input2, [ 'revision' => 2 ] );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ], [ 'PUT', '/templates/' . $template_id, new WP_Error( 'transport_timeout', 'Lost update response.', [ 'status' => 503 ] ) ] ];
$failed = stored_ok( stored_sync( $client, $connection ) ); check( $failed['local_only'] && $failed['posting_sync']['status'] === 'failed', 'Uncertain remote update is reported and retained.' );
$called = false;
$blocked = Nova_Bridge_Suite_Stored_Recipe_Sync::sync_editor( $connection, static function () use ( &$called ) { $called = true; return []; }, true, $recipe_id, $client, new Stored_Sync_Lock() );
check( is_wp_error( $blocked ) && !$called, 'A pending update prevents another local revision before reconciliation.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ], [ 'GET', '/templates/' . $template_id, reply( $remote2 ) ] ];
$recovered = stored_ok( stored_sync( $client, $connection ) ); check( $recovered['posting_sync']['recipe_revision'] === 2 && !$recovered['local_only'], 'Lost PUT acknowledgement recovers exact revision without another mutation.' );
check( stored_ok( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) ) === $profile, 'Later instructions do not rewrite the retained historical layout.' );
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 3, 'defaults' => [ 'service' => '55555555-5555-4555-8555-555555555555' ] ] ) ] ];
$conflict = stored_ok( stored_sync( $client, $connection ) ); check( $conflict['posting_sync']['code'] === 'nova_stored_recipe_default_conflict', 'Unrelated remote defaults are not overwritten.' );
$record['posting_page_type'] = 'informative'; Nova_Bridge_Suite_Layout_Recipes::$record = $record;
$client->queue = [ [ 'GET', '/template-defaults', reply( [ 'revision' => 2, 'defaults' => [ 'service' => $template_id ] ] ) ] ];
$type_error = stored_ok( stored_sync( $client, $connection ) ); check( $type_error['posting_sync']['code'] === 'nova_stored_recipe_page_type_changed', 'A synchronized template cannot strand its old page-type default.' );
echo 'PASS ' . $checks . " stored recipe synchronization assertions\n";
