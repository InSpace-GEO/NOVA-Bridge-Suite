<?php
/** Standalone only: php modules/api-mapping-context/tests/mapping-drafts-unit.php. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run this mocked test with standalone PHP, never inside WordPress.' ); }
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
}
class Draft_Test_Response {
    public $data; public $headers = [];
    public function __construct( $data ) { $this->data = $data; }
    public function header( $name, $value ) { $this->headers[ $name ] = $value; }
    public function get_data() { return $this->data; }
}
class Draft_Test_Request {
    private $input; public $body;
    public function __construct( array $input ) { $this->input = $input; $this->body = json_encode( $input ); }
    public function get_param( $name ) { return $this->input[ $name ] ?? null; }
    public function get_json_params() { return $this->input; }
    public function get_body() { return $this->body; }
}
class Draft_Test_DB {
    public $options = 'test_options'; public $rows = []; public $before_query; public $fail = false; public $read_fail = false; public $last_error = ''; public $queries = [];
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $prepared ) { $this->last_error = $this->read_fail ? 'test database unavailable' : ''; return $this->read_fail ? null : ( $this->rows[ $prepared[1][0] ]['value'] ?? null ); }
    public function query( $prepared ) {
        $this->queries[] = $prepared;
        if ( $this->before_query ) { $callback = $this->before_query; $this->before_query = null; $callback( $this, $prepared ); }
        if ( $this->fail ) { return false; }
        list( $sql, $args ) = $prepared;
        if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
            if ( isset( $this->rows[ $args[0] ] ) ) { return 0; }
            $this->rows[ $args[0] ] = [ 'value' => $args[1], 'autoload' => 'no' ]; return 1;
        }
        if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, 'BINARY option_value = BINARY' ) ) {
            if ( ( $this->rows[ $args[1] ]['value'] ?? null ) !== $args[2] ) { return 0; }
            $this->rows[ $args[1] ] = [ 'value' => $args[0], 'autoload' => 'no' ]; return 1;
        }
        throw new RuntimeException( 'Unexpected database mutation.' );
    }
}
$wpdb = new Draft_Test_DB();
$admin = true; $logged_in = true; $adapter_catalog = [ 'templates' => [] ]; $routes = []; $cms_calls = 0; $remote_calls = 0;
function current_user_can( $capability ) { return $GLOBALS['admin']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $data ) { return new Draft_Test_Response( $data ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function maybe_serialize( $value ) { return serialize( $value ); }
function maybe_unserialize( $value ) { return unserialize( $value, [ 'allowed_classes' => false ] ); }
function wp_generate_uuid4() { static $sequence = 0; return 'test-revision-' . ++$sequence; }
function wp_cache_delete( $name, $group ) {}
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function apply_filters( $name, $value ) { if ( 'nova_bridge_mapping_catalog' !== $name ) { throw new RuntimeException( 'Unexpected filter.' ); } return $GLOBALS['adapter_catalog']; }
function add_action( $name, $callback, $priority = 10 ) {}
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['routes'][ $namespace . $route ] = $args; }
function wp_insert_post() { ++$GLOBALS['cms_calls']; throw new RuntimeException( 'Draft endpoint must never write CMS content.' ); }
function wp_update_post() { return wp_insert_post(); }
function update_post_meta() { return wp_insert_post(); }
function wp_remote_request() { ++$GLOBALS['remote_calls']; throw new RuntimeException( 'Draft endpoint must never call a backend.' ); }

final class Nova_Bridge_Suite_Strategy {
    public static $signature = 'layout-one'; public static $inventory = [];
    public static function entity( $type, $id ) { return in_array( $id, [ 1, 2, 3 ], true ) ? [ 'reference_type' => $type, 'reference_id' => $id, 'post_type' => 'post' === $type ? 'page' : 'product_cat' ] : null; }
    public static function fingerprint( $entity ) { return [ 'signature' => self::$signature, 'template' => 'native-page.php' ]; }
    public static function field_inventory( $entity ) { return self::$inventory; }
    public static function valid_pointer( $path ) { return is_string( $path ) && 1 === preg_match( '#^/(?:[^~\x00-\x1F]|~[01])*$#D', $path ) && false === strpos( $path, '__proto__' ); }
}
$native = static function ( $path, array $extra = [] ) { return array_merge( [ 'path' => $path, 'writable' => true, 'route' => '/wp/v2/pages/{id}', 'transport' => 'wordpress', 'request_path' => $path, 'binding' => 'binding:' . $path ], $extra ); };
$inventory = [ $native( '/title' ), $native( '/content' ), $native( '/excerpt', [ 'writable' => false ] ), $native( '/meta_all/acf/group/intro', [ 'write_mode' => 'complete_parent', 'request_path' => '/meta_all/acf/group' ] ), $native( '/meta_all/acf/group/faq', [ 'write_mode' => 'complete_parent', 'request_path' => '/meta_all/acf/group' ] ) ];
foreach ( [ 1, 2, 3, 4, 5, 6, 7 ] as $index ) { foreach ( [ 'heading', 'body' ] as $member ) { $inventory[] = $native( '/slots/' . $index . '/' . $member ); } }
$inventory[] = $native( '/@builders/elementor/a', [ 'builder' => 'elementor', 'request_path' => '/fields/*/value', 'selector_data' => [ 'field_key' => 'widgetA|title' ] ] );
$inventory[] = $native( '/@builders/elementor/b', [ 'builder' => 'elementor', 'request_path' => '/fields/*/value', 'selector_data' => [ 'field_key' => 'widgetB|title' ] ] );
$inventory[] = $native( '/@builders/elementor/alias', [ 'builder' => 'elementor', 'request_path' => '/fields/*/value', 'selector_data' => [ 'field_key' => 'widgetA|title' ] ] );
Nova_Bridge_Suite_Strategy::$inventory = $inventory;
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-drafts.php';

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; };
$error = static function ( $value, $code, $status = null ) use ( $assert ) { $assert( $value instanceof WP_Error && $value->code === 'nova_mapping_draft_' . $code && ( null === $status || $value->data['status'] === $status ), 'Expected ' . $code . ', got ' . ( $value instanceof WP_Error ? $value->code . ': ' . $value->message : 'success' ) ); };
$save = static function ( array $input ) { return Nova_Bridge_Suite_Mapping_Drafts::save_response( new Draft_Test_Request( $input ) ); };
$get = static function ( $id = 1, $signature = 'layout-one' ) { return Nova_Bridge_Suite_Mapping_Drafts::get_response( new Draft_Test_Request( [ 'reference_type' => 'post', 'reference_id' => $id, 'signature' => $signature ] ) ); };
$input = [ 'expected_revision' => '', 'reference_type' => 'post', 'reference_id' => 1, 'signature' => 'layout-one', 'catalog_mode' => 'preview', 'template' => [ 'id' => 'preview-service', 'revision' => 'preview-1' ], 'label' => 'Services', 'guidance' => 'Keep the FAQ.', 'fields' => [ '/title' => [ 'mode' => 'mapped', 'source_path' => 'page_heading', 'instructions' => 'Use one clear heading.', 'binding' => 'client-invented', 'route' => 'https://evil.invalid/' ] ], 'skipped_sources' => [ [ 'source_path' => 'summary', 'reason' => 'The layout has no separate summary.' ] ], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => 'nl-NL', 'route' => 'https://evil.invalid/' ] ];

Nova_Bridge_Suite_Mapping_Drafts::register_routes();
$assert( 2 === count( $routes ) && $routes['nova-bridge/v1/mapping/draft'][1]['methods'] === 'POST', 'Only private catalog and draft routes are registered.' );
$admin = false; $logged_in = false;
$error( $save( $input ), 'forbidden', 401 ); $error( $get(), 'forbidden', 401 );
$logged_in = true; $error( $save( $input ), 'forbidden', 403 ); $admin = true;
$catalog = Nova_Bridge_Suite_Mapping_Drafts::catalog_response( new Draft_Test_Request( [ 'mode' => 'preview' ] ) );
$assert( $catalog->headers['Cache-Control'] === 'private, no-store' && $catalog->data['origin'] === 'preview' && 3 === count( $catalog->data['templates'] ), 'Preview catalog is explicit and non-cached.' );
$assert( Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' )['origin'] === 'unavailable', 'No adapter means no invented NOVA catalog.' );
$error( Nova_Bridge_Suite_Mapping_Drafts::catalog_response( new Draft_Test_Request( [ 'mode' => 'arbitrary' ] ) ), 'mode' );
$assert( null === $get()->data['draft'], 'New reference has no draft.' );
$first = $save( $input );
$assert( $first instanceof Draft_Test_Response, 'Valid incomplete preview saves.' );
$draft = $first->data['draft']; $revision = $draft['revision'];
$assert( 'local_draft' === $draft['status'] && false === $first->data['handoff']['verified'] && true === $first->data['handoff']['local_only'], 'Local save never claims verified, synchronized or active status.' );
$assert( 'binding:/title' === $draft['fields']['/title']['binding'] && false === strpos( json_encode( $first->data ), 'evil.invalid' ), 'Native descriptors are server-owned; injected endpoints are discarded.' );
$assert( $first->data['handoff']['reference_id'] === 1 && $draft['routing']['post_type'] === 'page' && $draft['routing']['native_template'] === 'native-page.php', 'Handoff retains concrete reference and native routing context.' );
$assert( $draft['skipped_sources'][0]['reason'] === $input['skipped_sources'][0]['reason'] && false !== strpos( implode( ' ', $draft['warnings'] ), 'backend may refuse sealing' ), 'Explicit skipped fields retain reasons and warn about backend coverage.' );
$assert( false !== strpos( implode( ' ', $draft['warnings'] ), 'Unmapped NOVA fields' ), 'Incomplete coverage is a warning, not a blocked local save.' );
$assert( $get()->data['draft']['revision'] === $revision && null === $get( 2 )->data['draft'], 'Draft retrieval is isolated by concrete reference, not shared structural layout.' );
$assert( current( $wpdb->rows )['autoload'] === 'no', 'Draft options are not autoloaded.' );
$error( $save( $input ), 'conflict', 409 );
$edited = $input; $edited['expected_revision'] = $revision; $edited['guidance'] = 'New instructions';
$second = $save( $edited );
$assert( $second instanceof Draft_Test_Response && $second->data['draft']['revision'] !== $revision, 'Matching revision updates atomically and returns a new token.' );
$error( $save( $edited ), 'conflict', 409 );
$edited['expected_revision'] = $second->data['draft']['revision'];
$wpdb->before_query = static function ( $db, $prepared ) { $name = $prepared[1][1]; $winner = unserialize( $db->rows[ $name ]['value'] ); $winner['revision'] = 'concurrent-winner'; $winner['guidance'] = 'Other editor wins'; $db->rows[ $name ]['value'] = serialize( $winner ); };
$error( $save( $edited ), 'conflict', 409 );
$assert( $get()->data['draft']['guidance'] === 'Other editor wins', 'A write arriving between read and update is not overwritten.' );
$edited['expected_revision'] = 'concurrent-winner';
$wpdb->fail = true; $error( $save( $edited ), 'storage_unavailable', 500 ); $wpdb->fail = false;
$wpdb->read_fail = true; $error( $get(), 'storage_unavailable', 500 ); $error( $save( $edited ), 'storage_unavailable', 500 ); $wpdb->read_fail = false;

$bad = $edited; unset( $bad['expected_revision'] ); $error( $save( $bad ), 'revision' );
$assert( $draft['routing']['publication'] === 'preserve', 'Existing page updates preserve publication state by default.' );
$bad = $edited; $bad['routing']['publication'] = 'trash'; $error( $save( $bad ), 'routing' );
$bad = $edited; $bad['fields']['/title']['protected_slot'] = 'faq_block'; $error( $save( $bad ), 'protected_slot' );
$bad = $edited; $bad['fields']['/content'] = [ 'mode' => 'protected', 'protected_slot' => 'unknown_region' ]; $error( $save( $bad ), 'protected_slot' );
$bad = $edited; $bad['fields'] = [ '/title' => [ 'mode' => 'protected', 'protected_slot' => 'faq_block' ], '/content' => [ 'mode' => 'protected', 'protected_slot' => 'faq_block' ] ]; $error( $save( $bad ), 'protected_slot' );
$bad = $edited; $bad['fields']['/title']['source_path'] = 'made_up_source'; $error( $save( $bad ), 'source' );
$bad = $edited; $bad['fields']['/title']['source_path'] = 'steps[].step_heading'; $error( $save( $bad ), 'repeat_source' );
$bad = $edited; $bad['fields']['/unknown'] = $bad['fields']['/title']; $error( $save( $bad ), 'stale_target', 409 );
$bad = $edited; $bad['fields']['/excerpt'] = $bad['fields']['/title']; $error( $save( $bad ), 'unwritable' );
$bad = $edited; $bad['skipped_sources'][0]['reason'] = ''; $error( $save( $bad ), 'skip' );
$bad = $edited; $bad['skipped_sources'][0]['source_path'] = 'page_heading'; $error( $save( $bad ), 'skip' );
$bad = $edited; $bad['fields']['/title']['instructions'] = str_repeat( 'x', 8001 ); $error( $save( $bad ), 'field' );
$request = new Draft_Test_Request( $edited ); $request->body = str_repeat( 'x', 262145 ); $error( Nova_Bridge_Suite_Mapping_Drafts::save_response( $request ), 'size' );
$bad = $edited; $bad['reference_id'] = 999; $error( $save( $bad ), 'reference', 404 );
$bad = $edited; $bad['fields'] = [ '/meta_all/acf/group/intro' => [ 'mode' => 'mapped', 'source_path' => 'body', 'instructions' => '' ], '/meta_all/acf/group/faq' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ] ];
$error( $save( $bad ), 'protected_overlap' );
$clone_omission = $bad; $clone_omission['routing']['operation'] = 'clone'; $clone_omission['fields']['/meta_all/acf/group/intro']['mode'] = 'leave_empty'; $clone_omission['fields']['/meta_all/acf/group/intro']['source_path'] = '';
$error( $save( $clone_omission ), 'protected_overlap' );
$bad['fields'] = [ '/@builders/elementor/a' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ], '/@builders/elementor/alias' => [ 'mode' => 'mapped', 'source_path' => 'body', 'instructions' => '' ] ];
$error( $save( $bad ), 'protected_overlap' );
$bad['fields'] = [ '/@builders/elementor/a' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ], '/@builders/elementor/b' => [ 'mode' => 'mapped', 'source_path' => 'body', 'instructions' => '' ] ];
$distinct = $save( $bad ); $assert( $distinct instanceof Draft_Test_Response, 'Distinct builder selectors sharing a request envelope do not falsely overlap.' );
$edited['expected_revision'] = $distinct->data['draft']['revision'];
$nested = $edited; $nested['fields'] = [ '/meta_all/acf/group/intro' => [ 'mode' => 'mapped', 'source_path' => 'body', 'instructions' => '' ] ];
$nested_result = $save( $nested );
$assert( $nested_result instanceof Draft_Test_Response && false !== strpos( implode( ' ', $nested_result->data['warnings'] ), 'complete-parent write' ), 'Nested existing targets remain editable with explicit uncertified warning.' );
$edited['expected_revision'] = $nested_result->data['draft']['revision'];
$repeat = $edited; $repeat['repeat_slots'] = [ 'steps' => [ [ 'id' => 'slot-first', 'targets' => [ 'step_heading' => '/slots/1/heading', 'step_body' => '/slots/1/body' ] ] ] ];
$repeat_result = $save( $repeat );
$assert( $repeat_result instanceof Draft_Test_Response && $repeat_result->data['draft']['repeat_slots']['steps'][0]['id'] === 'slot-first', 'Fixed existing repeat slots retain stable identity.' );
$assert( false !== strpos( implode( ' ', $repeat_result->data['warnings'] ), 'fewer existing slots' ), 'Repeat minimum is an incomplete-draft warning.' );
$repeat['expected_revision'] = $repeat_result->data['draft']['revision'];
$placeholder = $repeat; $placeholder['repeat_slots']['steps'][] = [ 'id' => 'unfinished-slot', 'targets' => [] ];
$placeholder_result = $save( $placeholder );
$assert( $placeholder_result instanceof Draft_Test_Response && false !== strpos( implode( ' ', $placeholder_result->data['warnings'] ), 'unfinished-slot is incomplete' ), 'An unfinished local slot can be saved without implying native row creation or readiness.' );
$repeat['expected_revision'] = $placeholder_result->data['draft']['revision'];
$bad = $repeat; $bad['repeat_slots']['steps'][] = $bad['repeat_slots']['steps'][0]; $error( $save( $bad ), 'repeat_slot' );
$bad = $repeat; $bad['repeat_slots']['steps'][] = [ 'id' => 'second', 'targets' => [ 'step_heading' => '/slots/1/heading' ] ]; $error( $save( $bad ), 'repeat_target' );
$bad = $repeat; $bad['repeat_slots']['steps'][0]['targets']['wrong_member'] = '/slots/2/body'; $error( $save( $bad ), 'repeat_target' );
$bad = $repeat; $bad['repeat_slots']['steps'] = []; for ( $index = 1; $index <= 7; ++$index ) { $bad['repeat_slots']['steps'][] = [ 'id' => 'slot-' . $index, 'targets' => [ 'step_heading' => '/slots/' . $index . '/heading' ] ]; } $error( $save( $bad ), 'repeat_limit' );

Nova_Bridge_Suite_Strategy::$inventory = array_values( array_filter( $inventory, static function ( $field ) { return '/title' !== $field['path'] && '/slots/1/body' !== $field['path']; } ) );
Nova_Bridge_Suite_Strategy::$signature = 'layout-two';
$stale = $get( 1, 'layout-two' );
$assert( $stale->data['draft']['signature'] === 'layout-one' && isset( $stale->data['draft']['fields']['/title'] ), 'Changed layout still retrieves previous draft and missing targets.' );
$error( $save( $repeat ), 'reference_changed', 409 );
$repeat['signature'] = 'layout-two'; $error( $save( $repeat ), 'reference_changed', 409 );
$repeat['confirm_reference_change'] = true;
$preserved = $save( $repeat );
$assert( $preserved instanceof Draft_Test_Response && isset( $preserved->data['draft']['fields']['/title'] ) && false !== strpos( implode( ' ', $preserved->data['warnings'] ), 'no longer discovered' ), 'Confirmed structural change retains missing field and repeat selections with warnings.' );
$repeat['expected_revision'] = $preserved->data['draft']['revision'];
$bad = $repeat; unset( $bad['fields']['/title'] ); $error( $save( $bad ), 'stale_drop', 409 );
$bad = $repeat; unset( $bad['repeat_slots']['steps'][0]['targets']['step_body'] ); $error( $save( $bad ), 'stale_drop', 409 );
$bad = $repeat; $bad['discard_stale_targets'] = [ '/content' ]; $error( $save( $bad ), 'stale_discard' );
$discard = $repeat; unset( $discard['fields']['/title'], $discard['repeat_slots']['steps'][0]['targets']['step_body'] ); $discard['discard_stale_targets'] = [ '/title', '/slots/1/body' ];
$discarded = $save( $discard );
$assert( $discarded instanceof Draft_Test_Response && ! isset( $discarded->data['draft']['target_descriptors']['/title'] ), 'Only explicit stale-target discard removes missing saved fields.' );

Nova_Bridge_Suite_Strategy::$inventory = $inventory; Nova_Bridge_Suite_Strategy::$signature = 'layout-one';
$canonical = Nova_Bridge_Suite_Mapping_Drafts::catalog( 'preview' )['templates'][2]; $canonical['id'] = 'remote-service'; $canonical['revision'] = 'remote-revision-1'; $canonical['private_token'] = 'must-not-leak';
$adapter_catalog = [ 'templates' => [ $canonical ], 'secret' => 'must-not-leak' ];
$normalized = Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' );
$assert( $normalized['origin'] === 'nova' && false === strpos( json_encode( $normalized ), 'must-not-leak' ), 'Catalog adapter normalizes and excludes unrelated properties.' );
$remote = $input; $remote['reference_id'] = 2; $remote['catalog_mode'] = 'nova'; $remote['template'] = [ 'id' => 'remote-service', 'revision' => 'remote-revision-1' ];
$remote_result = $save( $remote );
$assert( $remote_result instanceof Draft_Test_Response && $remote_result->data['draft']['status'] === 'awaiting_backend', 'Real catalog still creates only an awaiting-backend local draft.' );
$remote['expected_revision'] = $remote_result->data['draft']['revision']; $adapter_catalog = [ 'templates' => [] ]; $remote['guidance'] = 'Notes edited offline';
$offline = $save( $remote );
$assert( $offline instanceof Draft_Test_Response && $offline->data['draft']['template'] === $remote['template'] && false !== strpos( implode( ' ', $offline->data['warnings'] ), 'exact NOVA template revision is unavailable' ), 'Offline edits retain exact remote selection and warn about unverified catalog.' );
$remote['expected_revision'] = $offline->data['draft']['revision']; $bad = $remote; $bad['fields']['/title']['source_path'] = 'invented_offline_key'; $error( $save( $bad ), 'source' );
$bad = $remote; $bad['template']['revision'] = 'invented-revision'; $error( $save( $bad ), 'catalog_unavailable', 409 );
$bad = $remote; $bad['reference_id'] = 3; $bad['expected_revision'] = ''; $error( $save( $bad ), 'catalog_unavailable', 409 );
$adapter_catalog = [ 'templates' => [ $canonical, $canonical ] ]; $assert( Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' )['origin'] === 'unavailable', 'Duplicate template identities reject malformed adapter catalogs.' );
$canonical['fields'][0]['type'] = 'arbitrary'; $adapter_catalog = [ 'templates' => [ $canonical ] ]; $assert( Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' )['origin'] === 'unavailable', 'Unknown field types fail closed at normalized adapter boundary.' );

$new = $input; $new['reference_id'] = 3;
$wpdb->before_query = static function ( $db, $prepared ) { $db->rows[ $prepared[1][0] ] = [ 'value' => serialize( [ 'revision' => 'first-insert-winner' ] ), 'autoload' => 'no' ]; };
$error( $save( $new ), 'conflict', 409 );
$assert( 0 === $cms_calls && 0 === $remote_calls, 'All catalog/save/read/conflict scenarios perform zero CMS mutations and zero remote calls.' );
echo 'PASS ' . $checks . " local mapping draft, catalog, conflict, preservation and no-publication checks.\n";
