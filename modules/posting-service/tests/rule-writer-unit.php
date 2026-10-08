<?php
/** Native lifecycle, response-loss and owned-URL tests. WordPress/Elementor/database are mocked. */
namespace Elementor {
    class Plugin {
        public $documents; public $elements_manager;
        public static function instance() { static $instance; if ( ! $instance ) { $instance = new self(); $instance->documents = new \Rule_Documents(); $instance->elements_manager = new \Rule_Elements(); } return $instance; }
    }
}
namespace SEOR_Elementor_Bridge {
    class Elementor_Service {
        public function update_page( $id, $payload ) {
            ++$GLOBALS['native_saves'];
            $data = json_decode( $payload['elementor_data'], true );
            // The real bridge captures Elementor's canonical save result. Native-added safe
            // settings are permitted, but planned order/identity/content must survive.
            $data[0]['settings']['_title'] = 'Native editor label';
            if ( $GLOBALS['native_link'] ) { $data[0]['settings']['e_link'] = [ 'url' => 'https://changed.test/' ]; }
            if ( $GLOBALS['drop_node'] ) { array_pop( $data[0]['elements'] ); }
            $GLOBALS['posts'][ $id ]->post_title = stripslashes( $payload['title'] );
            $GLOBALS['meta'][ $id ]['_elementor_data'] = json_encode( $data, JSON_UNESCAPED_UNICODE );
            if ( $GLOBALS['crash_save'] ) { $GLOBALS['crash_save'] = false; throw new \RuntimeException( 'Response lost after native save' ); }
            return [ 'post_id' => $id ];
        }
    }
}
namespace {
define( 'ABSPATH', __DIR__ . '/' ); define( 'OBJECT', 'OBJECT' ); define( 'ELEMENTOR_VERSION', '3.35.1' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' );
$wp_version = '6.8';
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; }
}
class WP_Post { public $ID; public $post_type; public $post_status; public $post_title; public $post_name; public $post_parent = 0; public $post_author = 1; public $post_content = ''; public $post_content_filtered = ''; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_generate_uuid4() { static $n = 0; return 'd3353c1e-9702-438c-85d4-' . sprintf( '%012d', ++$n ); }
function wp_cache_delete( $key, $group ) { return true; }
function get_option( $key, $default = false ) { if ( $key === 'permalink_structure' ) { return $GLOBALS['permalink_structure'] ?? '/%postname%/'; } return $GLOBALS['options'][ $key ] ?? $default; }
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['options'][ $key ] ) ) { return false; } $GLOBALS['options'][ $key ] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; if ( $GLOBALS['crash_update_checkpoint'] && ( $value['state'] ?? '' ) === 'saving' && ( $value['plan']['target_post_id'] ?? 0 ) > 0 ) { $GLOBALS['crash_update_checkpoint'] = false; throw new RuntimeException( 'Process lost before update save' ); } return true; }
function get_post_type_object( $type ) { return in_array( $type, [ 'page', 'post' ], true ) ? (object) [ 'cap' => (object) [ 'create_posts' => 'edit_pages', 'edit_posts' => 'edit_pages' ] ] : null; }
function post_type_exists( $type ) { return in_array( $type, [ 'page', 'post' ], true ); }
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['permission']; }
function get_current_user_id() { return 1; }
function wp_set_current_user( $id ) {}
function get_locale() { return 'en_US'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_kses_post( $value ) { return strip_tags( $value, '<p><h1><h2><h3><a><strong><em><ul><ol><li><img><br>' ); }
function wp_slash( $value ) { return addslashes( $value ); }
function clean_post_cache( $id ) {}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; return true; }
function get_edit_post_link( $id, $context = '' ) { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_permalink( $id ) { return str_replace( [ '%pagename%', '%postname%' ], get_post( $id )->post_name, get_sample_permalink( $id )[0] ); }
function get_sample_permalink( $id ) { return [ 'https://example.test' . ( get_post( $id )->post_type === 'post' ? get_option( 'permalink_structure' ) : '/%pagename%/' ), get_post( $id )->post_name ]; }
function get_page_by_path( $path, $output = OBJECT, $type = 'page' ) { foreach ( $GLOBALS['posts'] as $post ) { if ( $post->post_name === $path && $post->post_type === $type ) { return $post; } } return null; }
function add_filter( $name, $callback, $priority = 10, $args = 1 ) { $GLOBALS['filters'][ $name ][] = $callback; }
function remove_filter( $name, $callback, $priority = 10 ) { $GLOBALS['filters'][ $name ] = array_values( array_filter( $GLOBALS['filters'][ $name ] ?? [], static function ( $fn ) use ( $callback ) { return $fn !== $callback; } ) ); }
function wp_insert_post( $data, $error = false ) {
    foreach ( $GLOBALS['filters']['wp_insert_post_data'] ?? [] as $callback ) { $data = $callback( $data, $data, $data ); }
    $post = new WP_Post(); $post->ID = count( $GLOBALS['posts'] ) + 10;
    foreach ( $data as $key => $value ) { $post->$key = $key === 'post_title' ? stripslashes( $value ) : $value; }
    $GLOBALS['posts'][ $post->ID ] = $post; ++$GLOBALS['inserts'];
    if ( $GLOBALS['crash_insert'] ) { $GLOBALS['crash_insert'] = false; throw new RuntimeException( 'Response lost after reservation insert' ); }
    return $post->ID;
}
class Rule_Document { public function save( $data ) {} public function set_is_built_with_elementor( $flag ) {} public function is_editable_by_current_user() { return true; } }
class Rule_Documents { public function get( $id, $cache = false ) { return new Rule_Document(); } }
class Rule_Elements { public function get_element( $type, $widget = null ) { return $GLOBALS['missing_element'] ? null : (object) []; } }
class Rule_DB {
    public $posts = 'wp_posts'; public $last_error = '';
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) { return '1'; }
    public function get_col( $query ) { list( $sql, $args ) = $query; $ids = []; foreach ( $GLOBALS['posts'] as $post ) { if ( $post->post_content_filtered === $args[0] && $post->post_type === $args[1] ) { $ids[] = $post->ID; } } return $ids; }
}
$wpdb = new Rule_DB();
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-content-rules.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-rule-writer.php';
foreach ( [ 'posting-protocol', 'receipt-json', 'writing-adapter', 'mapping-sync', 'posting-worker' ] as $file ) { require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-' . $file . '.php'; }
require_once __DIR__ . '/contract-fixture.php';
$checks = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function result( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function reset_native() { $GLOBALS['options'] = $GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['filters'] = []; $GLOBALS['inserts'] = $GLOBALS['native_saves'] = 0; $GLOBALS['permission'] = true; $GLOBALS['missing_element'] = $GLOBALS['crash_insert'] = $GLOBALS['crash_save'] = $GLOBALS['drop_node'] = $GLOBALS['crash_update_checkpoint'] = $GLOBALS['native_link'] = false; $GLOBALS['permalink_structure'] = '/%postname%/'; }
function op( $number ) { return '11111111-1111-4111-8111-' . sprintf( '%012d', $number ); }
function profile() { $profile = Nova_Bridge_Suite_Content_Rules::preset(); $profile['revision'] = 1; return $profile; }
function input() { return [ 'title' => "A creator's café \\path 😀", 'html' => '<h2>One</h2><p>First <strong>body</strong>.</p><h3>Two</h3><p>Second body.</p>' ]; }
function snapshot( $version = '501' ) { return [ 'site_id' => 'fb67968c-8fc7-4189-88b7-6851c2f9241b', 'url_id' => '42', 'content_item_id' => '101', 'content_item_version_id' => $version, 'version_number' => (int) $version - 500, 'source_sha256' => str_repeat( 'a', 64 ), 'configuration' => null, 'url' => 'https://example.test/article/', 'language' => 'en-GB', 'content' => [ 'title' => 'Article', 'content' => '<h2>Heading</h2><p>Body.</p>', 'top_content' => '<p>Other source</p>' ] ]; }
function config( $profile ) { return [ 'mode' => 'local_rules', 'site_id' => snapshot()['site_id'], 'profile' => $profile, 'profile_digest' => Nova_Bridge_Suite_Content_Rules::digest( $profile ) ]; }

reset_native(); $p = profile(); $i = input(); $created = result( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 1 ) ) );
check( $inserts === 1 && $native_saves === 1 && $created['status'] === 'draft', 'Source-free creation uses one native draft and one save.' );
check( get_post( $created['post_id'] )->post_title === $i['title'] && count( json_decode( get_post_meta( $created['post_id'], '_elementor_data' ), true )[0]['elements'] ) === 4, 'Unicode title and variable widget count survive native read-back.' );
$again = result( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 1 ) ) );
check( $again['post_id'] === $created['post_id'] && $inserts === 1 && $native_saves === 1, 'Repeated operation does not create or save again.' );
$changed = $i; $changed['title'] = 'Different';
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $changed, op( 1 ) ) ) && $inserts === 1, 'An operation UUID cannot be reused for a different input.' );

foreach ( [ 'crash_insert', 'crash_save' ] as $crash ) {
    reset_native(); $GLOBALS[ $crash ] = true;
    check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 2 ) ) ), 'Uncertain native response remains visible.' );
    $recovered = result( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 2 ) ) );
    check( $inserts === 1 && $native_saves === 1 && $recovered['post_id'] === 10, 'Lost reservation/save response reconciles the same draft without a duplicate native save.' );
}
reset_native(); $missing_element = true;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 3 ) ) ) && $inserts === 0 && $options === [], 'Unsupported native element fails before any mutation.' );
reset_native(); $permission = false;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 3 ) ) ) && $inserts === 0, 'Create permissions are checked before draft insertion.' );
reset_native(); $drop_node = true;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 4 ) ) ) && $inserts === 1, 'Silent native element loss is not accepted as successful creation.' );
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 4 ) ) ) && $inserts === 1 && $native_saves === 1, 'A partially saved document is retained for review rather than overwritten or duplicated.' );
reset_native(); $native_link = true;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::create_draft( $p, $i, op( 17 ) ) ) && $inserts === 1, 'Native-added effective links are rejected rather than mistaken for harmless canonical defaults.' );

reset_native(); $content = snapshot(); $configuration = config( $p );
$policy = result( Nova_Bridge_Suite_Rule_Writer::policy( $content, $configuration ) );
check( $policy['routing'] === [ 'operation' => 'create', 'publication' => 'draft' ] && $policy['reference']['reference_id'] === 0, 'Unmapped delivery can choose source-free creation.' );
$plan = result( Nova_Bridge_Suite_Rule_Writer::plan( $content, $configuration, [ 'operation_id' => op( 5 ) ] ) );
$first = result( Nova_Bridge_Suite_Rule_Writer::apply( $plan, op( 5 ) ) );
check( $first['warnings'] && $plan['source_selection'] === 'content.content', 'Main-body selection and excluded top/bottom sources are explicit.' );
$next = snapshot( '502' ); $next['content']['content'] .= '<p>New version.</p>';
$plan2 = result( Nova_Bridge_Suite_Rule_Writer::plan( $next, $configuration, [ 'operation_id' => op( 6 ) ] ) );
$updated = result( Nova_Bridge_Suite_Rule_Writer::apply( $plan2, op( 6 ) ) );
check( $updated['post_id'] === $first['post_id'] && $inserts === 1 && $native_saves === 2, 'A newer source version retains its URL/page association.' );
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::plan( $content, $configuration, [ 'operation_id' => op( 7 ) ] ) ), 'An older source cannot replace the current generated page.' );
$post_id = $updated['post_id']; $data = json_decode( get_post_meta( $post_id, '_elementor_data' ), true ); $data[0]['elements'][0]['settings']['title'] = 'Human edit'; update_post_meta( $post_id, '_elementor_data', json_encode( $data ) );
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::plan( snapshot( '503' ), $configuration, [ 'operation_id' => op( 8 ) ] ) ) && $native_saves === 2, 'Human native edits block replacement without another save.' );

reset_native(); foreach ( [ 'foreign_origin', 'wrong_locale', 'configured_remote', 'missing_main_body', 'different_profile' ] as $case ) {
    $bad = snapshot(); $cfg = config( $p );
    if ( $case === 'foreign_origin' ) { $bad['url'] = 'https://other.test/article/'; }
    if ( $case === 'wrong_locale' ) { $bad['language'] = 'nl'; }
    if ( $case === 'configured_remote' ) { $bad['configuration'] = [ 'id' => op( 9 ) ]; }
    if ( $case === 'missing_main_body' ) { $bad['content']['content'] = null; }
    if ( $case === 'different_profile' ) { $cfg['profile_digest'] = str_repeat( 'b', 64 ); }
    check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::plan( $bad, $cfg, [ 'operation_id' => op( 10 ) ] ) ) && $inserts === 0, 'Invalid route/profile/source is rejected before native creation: ' . $case );
}
reset_native(); $plan = result( Nova_Bridge_Suite_Rule_Writer::plan( snapshot(), config( $p ), [ 'operation_id' => op( 11 ) ] ) ); $crash_save = true;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::apply( $plan, op( 11 ) ) ), 'Delivery native response can be uncertain.' );
$recovered = result( Nova_Bridge_Suite_Rule_Writer::recover( $plan, op( 11 ) ) );
check( $recovered['post_id'] === 10 && $inserts === 1 && $native_saves === 1, 'Worker recovery confirms a saved native document without saving again.' );
$posts[10]->post_status = 'publish';
check( result( Nova_Bridge_Suite_Rule_Writer::verify( $plan, $recovered ) )['cms_post_status'] === 'publish', 'A human publication is permitted without treating it as a document drift.' );
unset( $options['nova_rule_operation_' . hash( 'sha256', op( 11 ) )] );
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::recover( $plan, op( 11 ) ) ) && $inserts === 1, 'A lost journal never proves absence while an atomic native reservation still exists.' );
reset_native(); $permalink_structure = '/blog/%postname%/'; $post_profile = $p; $post_profile['post_type'] = 'post'; $post_source = snapshot(); $post_source['url'] = 'https://example.test/blog/article/';
$post_plan = result( Nova_Bridge_Suite_Rule_Writer::plan( $post_source, config( $post_profile ), [ 'operation_id' => op( 12 ) ] ) );
check( result( Nova_Bridge_Suite_Rule_Writer::apply( $post_plan, op( 12 ) ) )['url'] === $post_source['url'], 'Simple fixed-prefix post permalinks can create a source-free native draft.' );
reset_native(); $permalink_structure = '/%year%/%monthnum%/%postname%/';
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::plan( $post_source, config( $post_profile ), [ 'operation_id' => op( 13 ) ] ) ) && $inserts === 0, 'Date-based post routes fail before mutation rather than guessing routing fields.' );
reset_native(); $first_plan = result( Nova_Bridge_Suite_Rule_Writer::plan( snapshot(), config( $p ), [ 'operation_id' => op( 14 ) ] ) ); result( Nova_Bridge_Suite_Rule_Writer::apply( $first_plan, op( 14 ) ) );
$next_source = snapshot( '502' ); $next_source['content']['content'] .= '<p>New pending update.</p>'; $pending_plan = result( Nova_Bridge_Suite_Rule_Writer::plan( $next_source, config( $p ), [ 'operation_id' => op( 15 ) ] ) ); $crash_update_checkpoint = true;
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::apply( $pending_plan, op( 15 ) ) ) && $native_saves === 1, 'An interruption at update save intent retains the pending URL owner before native save.' );
check( is_wp_error( Nova_Bridge_Suite_Rule_Writer::policy( snapshot( '503' ), config( $p ) ) ) && is_wp_error( Nova_Bridge_Suite_Rule_Writer::recover( $pending_plan, op( 15 ) ) ) && $native_saves === 1, 'A higher source version cannot bypass an uncertain update or authorize a second save.' );

class Rule_Worker_Store {
    public $saved; public $locks = []; public $fail_phase = '';
    public function save( $job, $changes ) { $next = array_merge( $job, $changes ); if ( $next['phase'] === $this->fail_phase ) { return new WP_Error( 'fixture_journal_failure' ); } return $this->saved = $next; }
    public function lock( $key ) { $this->locks[ $key ] = true; return true; } public function unlock( $key ) { unset( $this->locks[ $key ] ); } public function uncertain_target( $job ) { return false; }
}
class Rule_Worker_Client {
    public $fixture; public $events = []; public $lose_failure = false;
    public function __construct( $fixture ) { $this->fixture = $fixture; }
    public function site_request( $method, $path ) { return [ 'status' => 200, 'raw_body' => $this->fixture['raw'], 'etag' => $this->fixture['etag'], 'attempt_id' => $this->fixture['attempt_id'] ]; }
    public function site_request_bytes( $method, $path, $bytes ) { $event = json_decode( $bytes, true ); $this->events[] = $event; if ( $this->lose_failure && $event['kind'] === 'publication_failed' ) { $this->lose_failure = false; return new WP_Error( 'fixture_ack_lost', '', [ 'status' => 503 ] ); } return [ 'status' => 202, 'raw_body' => json_encode( nova_contract_fixture_event_accept( $bytes ) ) ]; }
}
function worker_fixture( $p, $bound = true, $remote = false ) {
    $fixture = nova_contract_fixture(); $s = $fixture['snapshot']; $s['url'] = 'https://example.test/service/'; if ( ! $remote ) { $s['configuration'] = null; }
    $fixture = nova_contract_fixture( [ 'content' => $s ] );
    if ( $bound ) { $GLOBALS['options']['nova_bridge_content_rules_v1'] = [ 'profiles' => [ $p['id'] => [ $p['revision'] => $p ] ], 'bindings' => [ $s['site_id'] => [ 'site_id' => $s['site_id'], 'profile_id' => $p['id'], 'profile_revision' => $p['revision'], 'profile_digest' => Nova_Bridge_Suite_Content_Rules::digest( $p ) ] ] ]; }
    return [ $fixture, new Rule_Worker_Store(), new Rule_Worker_Client( $fixture ) ];
}
reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p ); $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client );
$created_job = result( $worker->process( $fixture['job'] ) );
check( $created_job['phase'] === 'committed' && $created_job['last_error'] === 'awaiting_publication' && $inserts === 1 && $native_saves === 1 && array_column( $client->events, 'kind' ) === [ 'received_complete' ], 'Explicit local binding creates one draft without reporting publication.' );
check( $created_job['payload']['native_writer'] === 'local_rules' && $created_job['target_id'] === 10 && $store->locks === [], 'Default dispatch retains writer identity and releases URL-scoped locks.' );
$posts[10]->post_status = 'publish'; $created_job['state'] = 'running';
$published = result( $worker->process( $created_job ) );
check( $published['state'] === 'complete' && $native_saves === 1 && array_column( $client->events, 'kind' ) === [ 'received_complete', 'publication_succeeded' ], 'Genuine native publication completes the original event workflow without another save.' );

reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p, false ); $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client ); result( $worker->process( $fixture['job'] ) );
check( $inserts === 0 && end( $client->events )['kind'] === 'publication_failed', 'An unmapped site without explicit local opt-in cannot create a page.' );
reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p, true, true ); $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client ); result( $worker->process( $fixture['job'] ) );
check( $inserts === 0 && end( $client->events )['kind'] === 'publication_failed', 'A failed configured remote mapping never falls back to bound local rendering rules.' );

reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p ); $store->fail_phase = 'planned'; $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client );
check( is_wp_error( $worker->process( $fixture['job'] ) ) && $inserts === 0, 'The selected local profile is journaled before planning can authorize a native write.' );
$frozen = $store->saved; $p2 = $p; $p2['revision'] = 2; $p2['rules']['heading']['element'] = 'text-editor'; $options['nova_bridge_content_rules_v1']['profiles'][ $p['id'] ][2] = $p2; $options['nova_bridge_content_rules_v1']['bindings'][ $fixture['snapshot']['site_id'] ]['profile_revision'] = 2; $options['nova_bridge_content_rules_v1']['bindings'][ $fixture['snapshot']['site_id'] ]['profile_digest'] = Nova_Bridge_Suite_Content_Rules::digest( $p2 );
$store->fail_phase = ''; $frozen['state'] = 'running'; $recovered_job = result( $worker->process( $frozen ) );
check( $recovered_job['payload']['plan']['profile']['revision'] === 1 && $inserts === 1, 'A later site binding edit cannot reinterpret a retained local job after interruption.' );

reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p ); $crash_insert = true; $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client );
$uncertain = result( $worker->process( $fixture['job'] ) ); check( $uncertain['phase'] === 'applying' && $inserts === 1 && $native_saves === 0, 'Lost create response retains applying phase and a uniquely findable draft reservation.' );
$connection = $fixture['connection']; $connection['paused'] = true; $worker = new Nova_Bridge_Suite_Posting_Worker( $connection, $store, $client ); $uncertain['state'] = 'running'; $paused_job = result( $worker->process( $uncertain ) );
check( $paused_job['last_error'] === 'paused' && $inserts === 1 && $native_saves === 0, 'Paused recovery can inspect a reservation but does not perform its unfinished native save.' );
$worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client ); $paused_job['state'] = 'running'; $resumed = result( $worker->process( $paused_job ) );
check( $resumed['phase'] === 'committed' && $resumed['last_error'] === 'awaiting_publication' && $inserts === 1 && $native_saves === 1, 'Unpaused recovery saves the original reserved page once.' );

reset_native(); [ $fixture, $store, $client ] = worker_fixture( $p ); $s = $fixture['snapshot']; $s['configuration'] = null; $s['content']['content'] = null; $client->fixture = $fixture = nova_contract_fixture( [ 'content' => $s ] ); $worker = new Nova_Bridge_Suite_Posting_Worker( $fixture['connection'], $store, $client );
$failed_job = result( $worker->process( $fixture['job'] ) );
check( $failed_job['state'] === 'complete' && $failed_job['payload']['native_writer'] === 'local_rules' && ! isset( $failed_job['payload']['plan'] ) && $inserts === 0, 'A local plan rejection records positive pre-mutation absence with its selected writer.' );
$client->fixture['attempt_id'] = op( 16 ); $client->lose_failure = true; $failed_job['state'] = 'running'; $failed_job['phase'] = 'attempt_check'; unset( $options['nova_bridge_content_rules_v1']['bindings'][ $fixture['snapshot']['site_id'] ] );
$new_attempt = result( $worker->process( $failed_job ) );
check( $new_attempt['phase'] === 'event_pending' && ! isset( $new_attempt['payload']['native_writer'], $new_attempt['payload']['configuration'] ) && $inserts === 0, 'A new authorized attempt clears the former local writer/configuration before re-selection.' );
$new_attempt['state'] = 'running'; $complete_failure = result( $worker->process( $new_attempt ) );
check( $complete_failure['state'] === 'complete' && $complete_failure['last_error'] !== 'native_writer_identity' && $inserts === 0, 'A failed re-selection can drain its exact retained event after response loss without stale-dispatch blockage.' );
echo 'PASS ' . $checks . " rule-writer native lifecycle checks.\n";
}
