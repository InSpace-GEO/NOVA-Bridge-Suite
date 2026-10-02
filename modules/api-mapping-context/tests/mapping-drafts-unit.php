<?php
/** Standalone only: php modules/api-mapping-context/tests/mapping-drafts-unit.php. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run this mocked test with standalone PHP, never inside WordPress.' ); }
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );

class WP_Error {
    public $code; public $message; public $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
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
require_once dirname( __DIR__, 2 ) . '/posting-service/includes/class-nova-bridge-suite-writing-adapter.php';

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
$assert( $draft['skipped_sources'][0]['reason'] === $input['skipped_sources'][0]['reason'] && false !== strpos( implode( ' ', $draft['warnings'] ), 'generation and the delivered source content are unchanged' ), 'Explicit skips retain reasons and do not claim to change generation.' );
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
$repeat = $edited; $repeat['repeat_slots'] = [ 'steps' => [ [ 'id' => '1668a4b6-85c4-4e53-a3a6-6879d695098e', 'ordinal' => 0, 'targets' => [ 'step_heading' => '/slots/1/heading', 'step_body' => '/slots/1/body' ] ] ] ];
$repeat_result = $save( $repeat );
$assert( $repeat_result instanceof Draft_Test_Response && $repeat_result->data['draft']['repeat_slots']['steps'][0]['id'] === '1668a4b6-85c4-4e53-a3a6-6879d695098e', 'Fixed existing repeat slots retain stable identity.' );
$assert( false !== strpos( implode( ' ', $repeat_result->data['warnings'] ), 'fewer existing slots' ), 'Repeat minimum is an incomplete-draft warning.' );
$repeat['expected_revision'] = $repeat_result->data['draft']['revision'];
$placeholder = $repeat; $placeholder['repeat_slots']['steps'][] = [ 'id' => '70499276-ec04-40b0-9f57-8cf5a00e243d', 'ordinal' => 1, 'targets' => [] ];
$placeholder_result = $save( $placeholder );
$assert( $placeholder_result instanceof Draft_Test_Response && false !== strpos( implode( ' ', $placeholder_result->data['warnings'] ), '70499276-ec04-40b0-9f57-8cf5a00e243d is incomplete' ), 'An unfinished local slot can be saved without implying native row creation or readiness.' );
$repeat['expected_revision'] = $placeholder_result->data['draft']['revision'];
$bad = $repeat; $bad['repeat_slots']['steps'][0]['id'] = 'new-invalid-legacy-token'; $error( $save( $bad ), 'repeat_slot_identity' );
$bad = $repeat; $bad['repeat_slots']['steps'][0]['ordinal'] = 4; $error( $save( $bad ), 'repeat_slot_identity' );
$bad = $repeat; $bad['repeat_slots']['steps'][0]['id'] = strtoupper( $bad['repeat_slots']['steps'][0]['id'] ); $error( $save( $bad ), 'repeat_slot_identity' );
$bad = $repeat; $bad['guidance'] = str_repeat( 'é', 4001 ); $error( $save( $bad ), 'text' );
$bad = $repeat; $bad['guidance_mode'] = 'set'; $bad['guidance'] = ''; $error( $save( $bad ), 'guidance_mode' );
$bad = $repeat; $bad['repeat_slots']['steps'][] = $bad['repeat_slots']['steps'][0]; $error( $save( $bad ), 'repeat_slot' );
$bad = $repeat; $bad['repeat_slots']['steps'][] = [ 'id' => '22222222-2222-4222-8222-222222222222', 'ordinal' => 1, 'targets' => [ 'step_heading' => '/slots/1/heading' ] ]; $error( $save( $bad ), 'repeat_target' );
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
$inherit = $remote; $inherit['guidance_mode'] = 'inherit'; $inherit['guidance'] = ''; $inherited = $save( $inherit );
$assert( $inherited instanceof Draft_Test_Response && $inherited->data['draft']['guidance_mode'] === 'inherit', 'Inherited global instructions are an explicit saved intent.' );
$remote['expected_revision'] = $inherited->data['draft']['revision'];
$legacy_name = 'nova_mapping_draft_' . hash( 'sha256', 'post:2' );
$legacy_stored = unserialize( $wpdb->rows[ $legacy_name ]['value'] );
$legacy_stored['repeat_slots'] = [ 'steps' => [ [ 'id' => 'saved-before-slot-catalog', 'targets' => [ 'step_heading' => '/slots/1/heading' ] ] ] ];
$wpdb->rows[ $legacy_name ]['value'] = serialize( $legacy_stored );
$legacy_read = $get( 2 );
$assert( $legacy_read->data['draft']['repeat_slots'] === $legacy_stored['repeat_slots'] && false !== strpos( implode( ' ', $legacy_read->data['warnings'] ), 'Legacy repeat slot' ), 'Reading an older saved slot never fabricates structural UUIDs or ordinals.' );
$legacy_input = $legacy_stored; $legacy_input['expected_revision'] = $legacy_stored['revision'];
$legacy_saved = $save( $legacy_input );
$assert( $legacy_saved instanceof Draft_Test_Response && $legacy_saved->data['draft']['repeat_slots'] === $legacy_stored['repeat_slots'], 'Editing an old local draft retains its unsupported slot identity until explicit replacement.' );
$remote['expected_revision'] = $legacy_saved->data['draft']['revision'];
$bad = $remote; $bad['template']['revision'] = 'invented-revision'; $error( $save( $bad ), 'catalog_unavailable', 409 );
$bad = $remote; $bad['reference_id'] = 3; $bad['expected_revision'] = ''; $error( $save( $bad ), 'catalog_unavailable', 409 );
$adapter_catalog = [ 'templates' => [ $canonical, $canonical ] ]; $assert( Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' )['origin'] === 'unavailable', 'Duplicate template identities reject malformed adapter catalogs.' );
$canonical['fields'][0]['type'] = 'arbitrary'; $adapter_catalog = [ 'templates' => [ $canonical ] ]; $assert( Nova_Bridge_Suite_Mapping_Drafts::catalog( 'nova' )['origin'] === 'unavailable', 'Unknown field types fail closed at normalized adapter boundary.' );

$new = $input; $new['reference_id'] = 3;
$wpdb->before_query = static function ( $db, $prepared ) { $db->rows[ $prepared[1][0] ] = [ 'value' => serialize( [ 'revision' => 'first-insert-winner' ] ), 'autoload' => 'no' ]; };
$error( $save( $new ), 'conflict', 409 );

// Destination descriptions are useful without a NOVA source catalog or a native write.
$legacy_rows = $wpdb->rows;
$destination_name = 'nova_mapping_draft_' . hash( 'sha256', 'post:3' ); unset( $wpdb->rows[ $destination_name ] );
foreach ( $inventory as &$field ) {
    if ( '/title' === $field['path'] ) { $field += [ 'label' => 'Native title', 'type' => 'string', 'format' => 'plain_text', 'native_description' => 'Native title guidance' ]; }
}
unset( $field ); Nova_Bridge_Suite_Strategy::$inventory = $inventory;
$catalog = Nova_Bridge_Suite_Mapping_Drafts::catalog_response( new Draft_Test_Request( [] ) );
$assert( 'destination' === $catalog->data['origin'] && false === $catalog->data['api_available'] && [] === $catalog->data['templates'][0]['fields'], 'Default destination catalog describes local preparation without invented remote sources.' );
$assert( 'destination' === $get( 3 )->data['catalog']['origin'], 'New references default to destination preparation.' );
$describe = static function ( string $label, string $type = 'text', array $constraints = [] ): array {
    return [ 'mode' => 'adapt', 'source_path' => '', 'required' => false, 'instructions' => '', 'description' => [ 'label' => $label, 'purpose' => 'Existing template destination', 'value_type' => $type, 'constraints' => $constraints ] ];
};
$destination = [ 'expected_revision' => '', 'reference_type' => 'post', 'reference_id' => 3, 'signature' => 'layout-one', 'catalog_mode' => 'destination', 'template' => [ 'id' => 'local-destination', 'revision' => '1' ], 'label' => 'Source-free service description', 'guidance_mode' => 'set', 'guidance' => 'Fit the content to the existing template. Add exactly one relevant badge label.', 'fields' => [ '/title' => $describe( 'Hero heading', 'text', [ 'max_length' => 70 ] ), '/content' => $describe( 'Introduction', 'rich_text' ), '/excerpt' => $describe( 'Read-only example', 'rich_text' ), '/meta_all/acf/group/intro' => $describe( 'Compound destination', 'link' ) ], 'destination_groups' => [], 'skipped_sources' => [], 'repeat_slots' => [], 'routing' => [ 'operation' => 'update', 'locale' => '' ] ];
$literal_rule = 'Use <strong>...</strong>; preserve <br> and /path%20example';
$destination['guidance'] .= "\n" . $literal_rule;
$destination['fields']['/title']['required'] = true;
$destination['fields']['/title']['instructions'] = $literal_rule;
$destination['fields']['/title']['description']['purpose'] = $literal_rule;
$destination['fields']['/@builders/elementor/a'] = [ 'mode' => 'protected', 'source_path' => '', 'instructions' => 'Private local protection instructions.' ];
$destination['target_descriptors'] = [ '/title' => [ 'label' => 'forged native label', 'route' => 'https://forged.invalid/' ] ];
$group = [ 'id' => '60000000-0000-4000-8000-000000000001', 'label' => 'Three existing steps', 'slots' => [] ];
for ( $index = 1; $index <= 3; ++$index ) {
    $paths = [];
    foreach ( [ 'heading', 'body' ] as $member ) { $path = '/slots/' . $index . '/' . $member; $paths[] = $path; $destination['fields'][ $path ] = $describe( 'Step ' . $index . ' ' . $member, 'body' === $member ? 'rich_text' : 'text', 'body' === $member ? [] : [ 'max_length' => 40 ] ); }
    $group['slots'][] = [ 'id' => sprintf( '60000000-0000-4000-8000-%012d', $index + 1 ), 'ordinal' => $index - 1, 'fields' => $paths ];
}
$destination['destination_groups'][] = $group;
$admin = false; $logged_in = false; $error( $save( $destination ), 'forbidden', 401 );
$logged_in = true; $error( $save( $destination ), 'forbidden', 403 ); $admin = true;
$destination_saved = $save( $destination );
$assert( $destination_saved instanceof Draft_Test_Response, 'Adapted destination description saves without a stock source or backend connection.' );
$destination_draft = $destination_saved->data['draft'];
$assert( 'local_draft' === $destination_draft['status'] && $destination_draft['fields']['/title']['description'] === $destination['fields']['/title']['description'] && '' === $destination_draft['fields']['/title']['source_path'], 'Description, rules, required intent and source-free mode survive local storage.' );
$assert( $destination_draft['target_descriptors']['/title']['label'] === 'Native title' && $destination_draft['target_descriptors']['/title']['type'] === 'string' && $destination_draft['target_descriptors']['/title']['format'] === 'plain_text' && $destination_draft['target_descriptors']['/title']['native_description'] === 'Native title guidance' && false === strpos( json_encode( $destination_saved->data ), 'forged.invalid' ), 'Trusted discovered destination metadata is retained and caller addresses discarded.' );
$assert( $destination_draft['destination_groups'] === $destination['destination_groups'] && $destination_saved->data['handoff']['destination_groups'] === $destination['destination_groups'] && count( $destination_draft['destination_groups'][0]['slots'] ) === 3, 'Three identified existing slots round-trip with their exact capacity and field selections.' );
$assert( $destination_saved->data['handoff']['bindings'][0]['description'] === $destination['fields']['/title']['description'] && true === $destination_saved->data['handoff']['bindings'][0]['required'], 'Local export retains description, optional rules and required intent.' );
$assert( false === strpos( implode( ' ', $destination_saved->data['warnings'] ), 'Unmapped NOVA fields' ) && false !== strpos( implode( ' ', $destination_saved->data['warnings'] ), 'requires a verified native writer' ), 'Destination preparation omits irrelevant stock coverage and warns about unsupported native destinations.' );
$backend = $destination_saved->data['backend_description'];
$assert( 'prepared_local' === $backend['status'] && $backend['local_revision'] === $destination_draft['revision'] && $backend['sha256'] === hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $backend['description'] ) ), 'Backend-neutral export is tied to the local revision and exact description digest.' );
$backend_json = json_encode( $backend['description'] );
$assert( false === strpos( $backend_json, 'reference_id' ) && false === strpos( $backend_json, 'native-page.php' ) && false === strpos( $backend_json, 'request_path' ) && false === strpos( $backend_json, 'source_field' ) && false === strpos( $backend_json, 'source_path' ) && false === strpos( $backend_json, '/slots/' ) && false === strpos( $backend_json, '/meta_all/' ) && false === strpos( $backend_json, 'Private local protection instructions.' ), 'Backend description excludes native addresses, WordPress IDs, stock source bindings and private protection details.' );
$backend_fields = array_column( $backend['description']['fields'], null, 'id' );
$title_id = Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' );
$assert( isset( $backend_fields[ $title_id ] ) && $backend_fields[ $title_id ]['label'] === 'Hero heading' && true === $backend_fields[ $title_id ]['required'] && false === $backend_fields[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/excerpt' ) ]['required'], 'Destination field IDs are stable and required/optional intent is explicit.' );
$assert( $destination_draft['guidance'] === $destination['guidance'] && $destination_draft['fields']['/title']['instructions'] === $literal_rule && $destination_draft['fields']['/title']['description']['purpose'] === $literal_rule && $backend['description']['instructions']['text'] === $destination['guidance'] && $backend_fields[ $title_id ]['instructions'] === $literal_rule && $backend_fields[ $title_id ]['purpose'] === $literal_rule && 0 === $cms_calls && 0 === $remote_calls, 'Literal HTML and URL escapes survive purpose and human rule storage/export as nonexecuted metadata.' );
$assert( 3 === $backend['description']['groups'][0]['capacity'] && $backend['description']['groups'][0]['slots'][0]['field_ids'] === [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/slots/1/heading' ), Nova_Bridge_Suite_Writing_Adapter::field_id( '/slots/1/body' ) ], 'Backend group capacity and slot members refer to exact stable destination IDs.' );
$assert( $get( 3 )->data['backend_description']['sha256'] === $backend['sha256'], 'Repeated reads export the identical description digest without remote calls.' );
$stale_export = $get( 3, 'stale-request-signature' )->data['backend_description'];
$assert( 'needs_review' === $stale_export['status'] && 'nova_mapping_draft_reference_changed' === $stale_export['error']['code'] && ! isset( $stale_export['description'] ), 'A stale requested signature blocks backend description export while retaining the local draft.' );
$error( $save( $destination ), 'conflict', 409 );
$destination['expected_revision'] = $destination_draft['revision'];
$builder_description = $destination; $builder_description['fields'] = [ '/@builders/elementor/a' => $describe( 'First widget heading' ), '/@builders/elementor/alias' => $describe( 'Aliased widget heading' ) ]; $builder_description['destination_groups'] = [];
$error( $save( $builder_description ), 'destination_overlap' );
unset( $builder_description['fields']['/@builders/elementor/alias'] ); $builder_description['fields']['/@builders/elementor/b'] = $describe( 'Second widget heading' );
$distinct_destinations = $save( $builder_description );
$assert( $distinct_destinations instanceof Draft_Test_Response && 'prepared_local' === $distinct_destinations->data['backend_description']['status'], 'Distinct existing widget selectors can be described separately despite sharing a request envelope.' );
$destination['expected_revision'] = $distinct_destinations->data['draft']['revision']; $restored_destinations = $save( $destination );
$assert( $restored_destinations instanceof Draft_Test_Response && $backend['sha256'] === $restored_destinations->data['backend_description']['sha256'], 'Restoring the reviewed template description retains stable identities and its exact exported digest.' );
$destination['expected_revision'] = $restored_destinations->data['draft']['revision'];
$bad = $destination; $bad['fields']['/title']['source_path'] = 'h1'; $error( $save( $bad ), 'source' );
$bad = $destination; $bad['fields']['/title']['required'] = 1; $error( $save( $bad ), 'required' );
$bad = $destination; $bad['fields']['/title']['required'] = null; $error( $save( $bad ), 'required' );
$bad = $destination; $bad['fields']['/title']['description']['field_path'] = '/forged'; $error( $save( $bad ), 'description' );
$bad = $destination; $bad['fields']['/title']['description']['purpose'] = str_repeat( 'é', 1001 ); $error( $save( $bad ), 'description' );
$bad = $destination; $bad['fields']['/title']['description']['label'] = "invalid\xff"; $error( $save( $bad ), 'description' );
$bad = $destination; $bad['fields']['/title']['description']['value_type'] = 'button'; $error( $save( $bad ), 'description' );
foreach ( [ [ 'max_length' => 0 ], [ 'max_length' => 100001 ], [ 'max_length' => '70' ], [ 'max_length' => null ], [ 'min_items' => -1 ], [ 'max_items' => 501 ], [ 'min_items' => 3, 'max_items' => 2 ], [ 'pattern' => '.*' ] ] as $constraints ) { $bad = $destination; $bad['fields']['/title']['description']['constraints'] = $constraints; $error( $save( $bad ), 'constraints' ); }
$accepted = Nova_Bridge_Suite_Mapping_Drafts::normalize_description( [ 'label' => 'Optional list', 'value_type' => 'list', 'constraints' => [ 'min_items' => 0, 'max_items' => 500 ] ] );
$assert( is_array( $accepted ) && '' === $accepted['purpose'] && 500 === $accepted['constraints']['max_items'], 'Description bounds are inclusive and omitted purpose is explicitly empty.' );
$assert( is_array( Nova_Bridge_Suite_Mapping_Drafts::normalize_description( [ 'label' => 'Long text', 'value_type' => 'text', 'constraints' => [ 'max_length' => 100000 ] ] ) ), 'Scalar maximum length allows the documented upper bound.' );
foreach ( [ [ 'text', [ 'min_items' => 1 ] ], [ 'rich_text', [ 'max_items' => 2 ] ], [ 'list', [ 'max_length' => 20 ] ], [ 'link', [ 'max_length' => 20 ] ] ] as $case ) { $error( Nova_Bridge_Suite_Mapping_Drafts::normalize_description( [ 'label' => 'Invalid typed constraint', 'value_type' => $case[0], 'constraints' => $case[1] ] ), 'constraints' ); }
$bad = $destination; $bad['destination_groups'][0]['route'] = '/forged'; $error( $save( $bad ), 'destination_group' );
$bad = $destination; $bad['destination_groups'][] = $group; $error( $save( $bad ), 'destination_group' );
$bad = $destination; $bad['destination_groups'][0]['slots'][1]['id'] = $group['slots'][0]['id']; $error( $save( $bad ), 'destination_slot' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['id'] = $group['id']; $error( $save( $bad ), 'destination_slot' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['id'] = 'AAAAAAAA-0000-4000-8000-000000000002'; $error( $save( $bad ), 'destination_slot' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['ordinal'] = 1; $error( $save( $bad ), 'destination_slot' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['fields'][] = '/slots/1/heading'; $error( $save( $bad ), 'destination_target' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['fields'][] = '/unknown'; $error( $save( $bad ), 'destination_target' );
$bad = $destination; $bad['destination_groups'][0]['slots'][0]['fields'][] = '/meta_all/acf/group/intro'; $error( $save( $bad ), 'destination_target' );
$bad = $destination; $bad['fields']['/slots/1/heading']['mode'] = 'protected'; $error( $save( $bad ), 'destination_target' );
$bad = $destination; $bad['fields']['/meta_all/acf/group/faq'] = [ 'mode' => 'protected', 'source_path' => '' ]; $error( $save( $bad ), 'protected_overlap' );
$wpdb->before_query = static function ( $db, $prepared ) { $name = $prepared[1][1]; $winner = unserialize( $db->rows[ $name ]['value'] ); $winner['revision'] = 'destination-concurrent-winner'; $db->rows[ $name ]['value'] = serialize( $winner ); };
$error( $save( $destination ), 'conflict', 409 );
$assert( 'destination-concurrent-winner' === $get( 3 )->data['draft']['revision'], 'Source-free drafts retain compare-and-swap protection.' );
$destination['expected_revision'] = 'destination-concurrent-winner';
Nova_Bridge_Suite_Strategy::$signature = 'destination-layout-two';
Nova_Bridge_Suite_Strategy::$inventory = array_values( array_filter( $inventory, static function ( $field ) { return '/slots/1/heading' !== $field['path']; } ) );
$destination['signature'] = 'destination-layout-two'; $destination['confirm_reference_change'] = true;
$retained = $save( $destination ); $assert( $retained instanceof Draft_Test_Response && isset( $retained->data['draft']['fields']['/slots/1/heading'] ), 'Confirmed layout changes retain missing adapted destinations and group identities.' );
$assert( 'needs_review' === $retained->data['backend_description']['status'] && ! isset( $retained->data['backend_description']['description'] ), 'A retained disappeared destination remains local but cannot be exported as current template capacity.' );
$destination['expected_revision'] = $retained->data['draft']['revision'];
$bad = $destination; unset( $bad['fields']['/slots/1/heading'] ); $error( $save( $bad ), 'stale_drop', 409 );
$bad = $destination; $bad['destination_groups'] = []; $error( $save( $bad ), 'stale_drop', 409 );
$discard = $destination; unset( $discard['fields']['/slots/1/heading'] ); $discard['destination_groups'][0]['slots'][0]['fields'] = [ '/slots/1/body' ]; $discard['discard_stale_targets'] = [ '/slots/1/heading' ];
$discarded_destination = $save( $discard );
$assert( $discarded_destination instanceof Draft_Test_Response && ! isset( $discarded_destination->data['draft']['target_descriptors']['/slots/1/heading'] ), 'Explicit stale discard removes only the reviewed missing adapted destination.' );
$assert( $wpdb->rows[ 'nova_mapping_draft_' . hash( 'sha256', 'post:1' ) ] === $legacy_rows[ 'nova_mapping_draft_' . hash( 'sha256', 'post:1' ) ] && $wpdb->rows[ $legacy_name ] === $legacy_rows[ $legacy_name ], 'Creating destination descriptions never rewrites historical source or repeat drafts.' );
Nova_Bridge_Suite_Strategy::$inventory = $inventory; Nova_Bridge_Suite_Strategy::$signature = 'layout-one';
$switch = $legacy_saved->data['draft']; $switch['expected_revision'] = $switch['revision']; $switch['catalog_mode'] = 'destination'; $switch['template'] = [ 'id' => 'local-destination', 'revision' => '1' ];
$switched = $save( $switch );
$assert( $switched instanceof Draft_Test_Response && $switched->data['draft']['fields'] === $legacy_saved->data['draft']['fields'] && $switched->data['draft']['repeat_slots'] === $legacy_saved->data['draft']['repeat_slots'], 'Explicit destination-mode switch preserves historical source bindings and unsupported repeat identities for review.' );
$assert( 'needs_review' === $switched->data['backend_description']['status'] && 'nova_writing_mixed_mapping' === $switched->data['backend_description']['error']['code'] && ! isset( $switched->data['backend_description']['description'] ), 'Mixed historical source/repeat selections stay readable while backend description export requires explicit review.' );
$assert( 0 === $cms_calls && 0 === $remote_calls, 'All catalog/save/read/conflict scenarios perform zero CMS mutations and zero remote calls.' );
echo 'PASS ' . $checks . " local mapping draft, catalog, conflict, preservation and no-publication checks.\n";
