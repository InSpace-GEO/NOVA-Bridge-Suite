<?php
/** V1 worker/projection/recovery checks with in-memory CMS and HTTP; no live service. */
require __DIR__ . '/delivery-unit.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-static-recipe-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-static-term-writer.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-engine.php';
$checks = 0; $options = []; $terms = []; $term_meta = []; $hooks = []; $insertions = 0; $crash_insert = false; $crash_write = false; $partial_write = false; $permissions = true;
function current_user_can( ...$args ) { return $GLOBALS['permissions']; }
function home_url( $path = '' ) { return 'https://native.fixture.invalid' . $path; }
function get_option( $key, $fallback = false ) { return $GLOBALS['options'][$key] ?? $fallback; }
function add_option( $key, $value, ...$args ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); return true; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function wp_kses_post( $value ) { return str_replace( '<script>', '', $value ); }
function sanitize_term_field( $field, $value, ...$args ) { return $value; }
function wp_slash( $value ) { return addslashes( $value ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function get_taxonomy( $name ) { return (object) [ 'cap' => (object) [ 'manage_terms' => 'manage_categories', 'edit_terms' => 'edit_categories' ] ]; }
function get_term( $id, ...$args ) { return $GLOBALS['terms'][$id] ?? null; }
function get_term_link( $id, ...$args ) { return home_url( '/product-category/' . get_term( $id )->slug . '/' ); }
function get_term_meta( $id, $key, ...$args ) { return $GLOBALS['term_meta'][$id][$key] ?? ''; }
function update_term_meta( $id, $key, $value ) { $GLOBALS['term_meta'][$id][$key] = $value; return true; }
function add_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][$name][] = $callback; }
function remove_action( $name, $callback, ...$args ) { $GLOBALS['hooks'][$name] = array_filter( $GLOBALS['hooks'][$name] ?? [], static function ( $item ) use ( $callback ) { return $item !== $callback; } ); }
function wp_insert_term( $name, $taxonomy, $args ) {
    ++$GLOBALS['insertions']; $id = 100 + $GLOBALS['insertions']; $GLOBALS['terms'][$id] = (object) ( $args + [ 'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name, 'description' => '', 'bottom' => '' ] );
    if ( $GLOBALS['crash_insert'] ) { throw new RuntimeException( 'Process lost between insert and witness' ); }
    foreach ( $GLOBALS['hooks']['created_term'] ?? [] as $callback ) { $callback( $id, $id, $taxonomy ); } return [ 'term_id' => $id ];
}
class Nova_Bridge_Suite_Layout_Recipes {
    static $record;
    static function defaults() { return [ 'taxonomy:product_cat' => array_intersect_key( self::$record, array_flip( [ 'id', 'revision' ] ) ) ]; }
    static function delivery_record( $id, $revision ) { return self::$record['id'] === $id && self::$record['revision'] === $revision ? self::$record : new WP_Error( 'fixture_recipe_missing' ); }
    static function delivery_compile( $id, $revision, $content, $operation ) {
        $payload = []; foreach ( self::$record['recipe']['bindings'] as $binding ) { $role = explode( '_', str_replace( 'nova_role_', '', $binding['id'] ) )[0]; $field = 'title' === $role ? 'name' : ( 'top' === $role ? 'description' : 'content_below_products' ); $value = Nova_Bridge_Suite_Recipe_Engine::get( $content, $binding['source'] ); if ( null !== $value ) { $payload[$field] = $value; } }
        if ( ! empty( $GLOBALS['fixture_missing_bottom'] ) ) { unset( $payload['content_below_products'] ); }
        return [ 'writer_payload' => $payload ];
    }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    static function creation( $resource, $url, $language = null ) {
        if ( 0 !== strpos( $url, home_url( '/product-category/' ) ) || false !== strpos( $url, '?' ) || 'en' !== $language ) { return new WP_Error( 'fixture_route_rejected' ); }
        $slug = basename( rtrim( $url, '/' ) ); foreach ( $GLOBALS['terms'] as $term ) { if ( $term->slug === $slug ) { return new WP_Error( 'fixture_target_exists' ); } }
        return [ 'slug' => $slug, 'parent' => 0, 'target_url' => $url, 'language' => $language, 'native_language' => [ 'provider' => 'core', 'code' => 'en-us' ] ];
    }
    static function normalize_url( $url ) { return [ 'identity' => $url ]; }
    static function native_url( $resource, $kind, $id ) { return get_term_link( $id ); }
    static function verify_created_language( ...$args ) { return true; }
    static function with_creation_language( $target, $callback ) { return $callback(); }
}
class Nova_Bridge_Suite_Term_Content {
    static function apply( $taxonomy, $id, $payload, $context ) {
        foreach ( $payload as $field => $value ) { if ( $GLOBALS['partial_write'] && 'content_below_products' === $field ) { return new WP_Error( 'fixture_partial_save' ); } $GLOBALS['terms'][$id]->{ 'content_below_products' === $field ? 'bottom' : $field } = $value; }
        if ( $GLOBALS['crash_write'] ) { throw new RuntimeException( 'Process lost after native save' ); } return true;
    }
    static function read( $taxonomy, $id, $context ) { $term = get_term( $id ); return [ 'name' => $term->name, 'description' => $term->description, 'content_below_products' => $term->bottom ]; }
}
class Static_Setup_Client extends Nova_Bridge_Suite_Posting_Client {
    public $remote; public $calls = []; public $lose = false;
    public function site_request( string $method, string $suffix, $body = null, array $headers = [] ) { $this->calls[] = $method; if ( 'POST' === $method ) { $this->remote = $body + [ 'id' => '00000000-0000-4000-8000-000000000111', 'revision' => 1, 'retired_at' => null ]; if ( $this->lose ) { return new WP_Error( 'fixture_response_lost' ); } } return [ 'status' => 200, 'body' => $this->remote, 'etag' => '"1"' ]; }
}
function static_record() {
    $bindings = []; foreach ( [ 'title' => '/document/title', 'top_content' => '/document/sections/0/body', 'bottom_content' => '/term_fields/values/t_bottom' ] as $role => $target ) { $bindings[] = [ 'id' => 'nova_role_' . $role . '_0123456789abcdef', 'target' => $target, 'source' => '/custom/' . $role, 'format' => 'title' === $role ? 'text' : 'html', 'required' => true, 'label' => $role ]; }
    return [ 'id' => '00000000-0000-4000-8000-000000000222', 'revision' => 2, 'resource_id' => 'taxonomy:product_cat', 'builder' => 'core', 'mode' => 'static', 'label' => 'Static category', 'content_schema' => [ 'type' => 'object', 'properties' => [ 'custom' => [ 'type' => 'object', 'properties' => [ 'title' => [ 'type' => 'string' ], 'top_content' => [ 'type' => 'string' ], 'bottom_content' => [ 'type' => 'string' ] ] ] ] ], 'recipe' => [ 'builder' => 'core', 'mode' => 'static', 'bindings' => $bindings, 'regions' => [], 'blueprint' => [ 'term_fields' => [ 'declarations' => [ 't_bottom' => [ 'key' => 'content_below_products', 'rest_key' => 'content_below_products', 'transport' => 'rest', 'schema' => [ 'type' => 'string' ] ] ] ] ] ] ];
}
function static_fixture( bool $optional_bottom = false, bool $missing_schema_leaf = false ) {
    $GLOBALS['options'] = $GLOBALS['terms'] = $GLOBALS['term_meta'] = $GLOBALS['hooks'] = []; $GLOBALS['insertions'] = 0; $GLOBALS['crash_insert'] = $GLOBALS['crash_write'] = $GLOBALS['partial_write'] = false; $GLOBALS['permissions'] = true;
    Nova_Bridge_Suite_Layout_Recipes::$record = static_record(); if ( $optional_bottom ) { Nova_Bridge_Suite_Layout_Recipes::$record['recipe']['bindings'][2]['required'] = false; } if ( $missing_schema_leaf ) { unset( Nova_Bridge_Suite_Layout_Recipes::$record['content_schema']['properties']['custom']['properties']['bottom_content'] ); } $f = nova_contract_fixture(); $client = new Static_Setup_Client( $f['connection'] );
    $approval = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( $f['connection'], static_record()['id'], 2, '5', '6', 'category', null, $client, new Contract_Test_Store() );
    delivery_check( ! is_wp_error( $approval ), 'Explicit setup approves an exact stock template without a native write.' );
    $snapshot = $f['snapshot']; $snapshot['configuration'] = array_intersect_key( $client->remote, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); $snapshot['page_type_frontend'] = 'category'; $snapshot['url'] = home_url( '/product-category/static-test/' ); $snapshot['content']['top_content'] = '<p>Separate top.</p>'; $snapshot['content']['bottom_content'] = '<p>Separate bottom.</p>';
    $f = nova_contract_fixture( [ 'content' => $snapshot ] ); $GLOBALS['server'] = $server = new Contract_Test_Server( $f ); $store = new Contract_Test_Store(); $worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ) ); return [ $f, $store, $server, $worker ];
}

$record = static_record(); $projection = Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $record ); delivery_check( ! is_wp_error( $projection ) && array_column( $projection['input']['mapping']['fields'], 'source_field' ) === [ 'h1', 'top_content', 'bottom_content' ], 'Semantic IDs project separately; visible category name uses h1, not the SEO title.' );
$uninitialized = []; $strict_put_rejected = false; try { Nova_Bridge_Suite_Recipe_Engine::put( $uninitialized, '/custom/title', 'Heading' ); } catch ( InvalidArgumentException $error ) { $strict_put_rejected = true; } delivery_check( $strict_put_rejected, 'The actual recipe engine requires declared pointers; the test does not substitute an auto-creating setter.' );
[ $f, $store, $server, $worker ] = static_fixture( false, true ); $result = $worker->process( $f['job'] ); delivery_check( $result['state'] === 'complete' && $insertions === 0 && count( $server->posts ) === 2 && json_decode( $server->posts[1], true )['kind'] === 'publication_failed', 'A missing declared pointer returns a controlled pre-write failure instead of throwing or creating a category.' );
foreach ( [ 'dynamic', 'repeat', 'custom', 'duplicate', 'wrong_bottom' ] as $case ) { $bad = $record; if ( 'dynamic' === $case ) { $bad['mode'] = 'mixed'; } elseif ( 'repeat' === $case ) { $bad['recipe']['regions'] = [ [ 'source' => '/x' ] ]; } elseif ( 'custom' === $case ) { $bad['recipe']['bindings'][0]['id'] = 'nova_role_custom_0123456789abcdef'; } elseif ( 'duplicate' === $case ) { $bad['recipe']['bindings'][] = $bad['recipe']['bindings'][0]; } else { $bad['recipe']['blueprint']['term_fields']['declarations']['t_bottom']['key'] = 'other_field'; } delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::project_record( $bad ) ), 'Unsupported projection rejected: ' . $case ); }
[ $f, $store, $server, $worker ] = static_fixture(); $result = $worker->process( $f['job'] ); delivery_check( $result['state'] === 'complete' && $insertions === 1 && isset( $result['payload']['result']['term_id'] ) && ! isset( $result['payload']['result']['post_id'] ), 'Standard worker publishes one native term with a term-specific receipt.' ); $id = $result['target_id']; delivery_check( get_term( $id )->description === '<p>Separate top.</p>' && get_term( $id )->bottom === '<p>Separate bottom.</p>', 'Top and bottom survive independently.' ); $events = array_map( static function ( $bytes ) { return json_decode( $bytes, true ); }, $server->posts ); delivery_check( strpos( $events[1]['publication_ref'], 'wordpress:term:product_cat:' ) === 0, 'Publication evidence identifies the taxonomy term.' );
delivery_check( get_term( $id )->name === $f['snapshot']['content']['h1'] && get_term( $id )->name !== $f['snapshot']['content']['title'], 'The visible category name uses H1, keeping the separate SEO title out of the taxonomy name.' );
$plan = $result['payload']['plan']; $again = Nova_Bridge_Suite_Static_Term_Writer::apply( $plan, $f['job']['operation_id'] ); delivery_check( ! is_wp_error( $again ) && $again['term_id'] === $id && $insertions === 1, 'Duplicate application returns the existing verified category.' ); get_term( $id )->bottom = 'Human edit'; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Term_Writer::verify( $plan, $again ) ), 'Human content drift prevents a false publication receipt.' );
foreach ( [ 'client_id', 'domain_id', 'site_id', 'page_type_frontend' ] as $field ) { [ $f, $store, $server, $worker ] = static_fixture(); $snapshot = $f['snapshot']; $snapshot[$field] = '999'; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $f['job']['site_id'] ) ), 'Frozen scope rejects changed ' . $field ); }
foreach ( [ 'crash_insert', 'partial_write', 'crash_write' ] as $failure ) { [ $f, $store, $server, $worker ] = static_fixture(); $GLOBALS[$failure] = true; $result = $worker->process( $f['job'] ); $GLOBALS[$failure] = false; $before = $insertions; $result = $worker->process( delivery_resume( $result ) ); delivery_check( $insertions === $before && $insertions === 1, 'Recovery never inserts again after ' . $failure ); if ( 'crash_write' === $failure ) { delivery_check( $result['state'] === 'complete', 'Read-only recovery verifies a fully saved category after process loss.' ); } else { delivery_check( $result['state'] === 'blocked' && count( $server->posts ) === 1, 'An uncertain or partial native save emits no false failure/success event.' ); } }
[ $f, $store, $server, $worker ] = static_fixture(); $server->lose = 'publication_succeeded'; $result = $worker->process( $f['job'] ); $bytes = $result['payload']['event_bytes']; $result = $worker->process( delivery_resume( $result ) ); delivery_check( $result['state'] === 'complete' && $insertions === 1 && count( array_filter( $server->posts, static function ( $entry ) use ( $bytes ) { return $entry === $bytes; } ) ) === 2, 'Lost success acknowledgments replay exact durable bytes without another native mutation.' );
[ $f, $store, $server, $worker ] = static_fixture(); $permissions = false; $result = $worker->process( $f['job'] ); delivery_check( $insertions === 0 && $result['state'] === 'complete', 'Revoked actor permissions reject before a native write.' );
[ $f, $store, $server, $worker ] = static_fixture(); $store->fail_phase = 'applying'; $result = $worker->process( $f['job'] ); delivery_check( is_wp_error( $result ) && $store->saved['phase'] === 'planned' && $insertions === 0, 'A prepared plan is retained before the native boundary.' ); $store->fail_phase = ''; $permissions = false; $result = $worker->process( delivery_resume( $store->saved ) ); delivery_check( $insertions === 0 && $result['state'] === 'complete', 'Revoking permissions after planning still prevents native insertion on resume.' );
[ $f, $store, $server, $worker ] = static_fixture(); $snapshot = $f['snapshot']; $snapshot['content']['bottom_content'] = null; $profile = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $f['job']['site_id'] ); $context = $f['context'] + [ 'operation' => 'create', 'desired_status' => 'publish' ]; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Term_Writer::plan( $snapshot, $profile, $context ) ) && $insertions === 0, 'Missing required bottom content rejects before insertion.' );
[ $f, $store, $server, $worker ] = static_fixture( true ); $snapshot = $f['snapshot']; $snapshot['content']['bottom_content'] = null; $profile = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $snapshot, $f['job']['site_id'] ); $context = $f['context'] + [ 'operation' => 'create', 'desired_status' => 'publish' ]; $optional = Nova_Bridge_Suite_Static_Term_Writer::plan( $snapshot, $profile, $context ); delivery_check( ! is_wp_error( $optional ) && $optional['payload']['content_below_products'] === '', 'Missing optional bottom content is explicitly empty and cannot inherit example content.' );
[ $f, $store, $server, $worker ] = static_fixture(); Nova_Bridge_Suite_Layout_Recipes::$record['label'] = 'Changed outside immutable history'; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $f['snapshot'], $f['job']['site_id'] ) ) && $insertions === 0, 'Changing an approved recipe revision invalidates the exact frozen authority.' );
[ $f, $store, $server, $worker ] = static_fixture(); $profile = Nova_Bridge_Suite_Static_Recipe_Adapter::configuration_for_snapshot( $f['snapshot'], $f['job']['site_id'] ); $snapshot = $f['snapshot']; $snapshot['url'] = 'https://foreign.invalid/product-category/x/'; delivery_check( is_wp_error( Nova_Bridge_Suite_Static_Recipe_Adapter::policy( $snapshot, $profile ) ) && $insertions === 0, 'Foreign URL never reaches native insertion.' );
$options = []; $client = new Static_Setup_Client( $f['connection'] ); $client->lose = true; $locks = new Contract_Test_Store(); $args = [ $f['connection'], $record['id'], 2, '5', '6', 'category' ]; $lost = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( ...array_merge( $args, [ null, $client, $locks ] ) ); $client->lose = false; $retry = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( ...array_merge( $args, [ null, $client, $locks ] ) ); delivery_check( is_wp_error( $lost ) && is_wp_error( $retry ) && $client->calls === [ 'POST' ], 'An unknown template-create outcome never causes an automatic duplicate POST.' ); $recovered = Nova_Bridge_Suite_Static_Recipe_Adapter::sync_and_approve( ...array_merge( $args, [ $client->remote['id'], $client, $locks ] ) ); delivery_check( ! is_wp_error( $recovered ) && $client->calls === [ 'POST', 'GET' ], 'The exact created template UUID supports read-only reconciliation and approval.' );
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-jobs.php';
class Static_Query_DB { public $prefix = 'wp_'; public $last_error = ''; public $query; function prepare( $sql, ...$args ) { return [ $sql, $args ]; } function get_var( $query ) { $this->query = $query; return 19; } }
$db = new Static_Query_DB(); $journal = new Nova_Bridge_Suite_Posting_Jobs( $db ); $j = $f['job']; $j['payload']['native_writer'] = 'static_recipe_v1'; $j['payload']['plan']['target']['target_url'] = $f['snapshot']['url']; delivery_check( $journal->uncertain_target( $j ) && strpos( $db->query[0], '$.plan.target.target_url' ) !== false && in_array( $f['snapshot']['url'], $db->query[1], true ), 'Different content IDs cannot bypass an uncertain native attempt for the same category URL.' );
// The real compiler's omission behavior is covered by delivery-missing-fields-unit.php.
// At the worker boundary, a reduced payload must still complete the success receipt.
[ $f, $store, $server, $worker ] = static_fixture(); $GLOBALS['fixture_missing_bottom'] = true;
$result = $worker->process( $f['job'] ); $GLOBALS['fixture_missing_bottom'] = false;
delivery_check( $result['state'] === 'complete' && $insertions === 1 && ! isset( $result['payload']['plan']['payload']['content_below_products'] ), 'A compiled payload with an omitted missing field creates exactly one category.' );
delivery_check( json_decode( $server->posts[1], true )['kind'] === 'publication_succeeded' && get_term( $result['target_id'] )->description === '<p>Separate top.</p>', 'Available fields are verified and publication succeeds despite the omitted field.' );
echo 'PASS ' . $checks . " static category compatibility checks.\n";
