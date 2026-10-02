<?php
/** Run only with wp eval-file and NOVA_WRITER_STAGING_CANARY=1 on an isolated candidate. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'NOVA_WRITER_STAGING_CANARY' ) ) { throw new RuntimeException( 'Explicit staging canary opt-in is required.' ); }
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-writer.php';
require_once __DIR__ . '/mapped-writer-fixture.php';

/** Test-only fault wrapper; real SQL still uses the candidate WordPress connection. */
final class Nova_Writer_Canary_DB {
    private $db; public $fault;
    public function __construct( $db, string $fault ) { $this->db = $db; $this->fault = $fault; }
    public function __get( $key ) { return $this->db->$key; }
    public function __isset( $key ) { return isset( $this->db->$key ); }
    public function __call( $name, $args ) { return call_user_func_array( [ $this->db, $name ], $args ); }
    public function insert( $table, $data, $format = null ) {
        if ( 'marker_insert' === $this->fault && 0 === strpos( $data['meta_key'] ?? '', '_nova_writer_op_' ) ) { $this->fault = ''; return false; }
        return $this->db->insert( $table, $data, $format );
    }
    public function query( $query ) {
        $result = $this->db->query( $query );
        if ( 'commit_ack' === $this->fault && 'COMMIT' === $query ) { $this->fault = ''; return false; }
        return $result;
    }
}
function nova_canary_assert( $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function nova_canary_result( $result, string $message ): array { if ( is_wp_error( $result ) ) { throw new RuntimeException( $message . ': ' . $result->get_error_code() . ' ' . $result->get_error_message() ); } nova_canary_assert( is_array( $result ), $message ); return $result; }
function nova_canary_fixture( int $post_id, string $operation, string $suffix, int $actor ): array {
    $entity = Nova_Bridge_Suite_Strategy::entity( 'post', $post_id );
    $fixture = nova_writer_fixture( $post_id, Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'], $operation, $suffix );
    $fixture['context']['actor_user_id'] = $actor;
    return $fixture;
}
function nova_canary_plan( array $fixture ): array { $fixture['context']['snapshot_sha256'] = hash( 'sha256', wp_json_encode( $fixture['content'] ) ); return nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::plan( $fixture['content'], $fixture['configuration'], $fixture['context'] ), 'Plan' ); }

global $wpdb; $real_db = $wpdb; $created = []; $report = []; $old_actor = get_current_user_id(); $actor = $old_actor;
if ( ! $actor ) { $admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] ); $actor = (int) ( $admins[0] ?? 0 ); }
nova_canary_assert( $actor > 0, 'A local publishing actor is required.' ); wp_set_current_user( $actor );
$suffix = strtolower( wp_generate_password( 10, false, false ) );
try {
    $source = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA isolated writer canary ' . $suffix, 'post_name' => 'nova-writer-source-' . $suffix, 'post_content' => 'Leave empty must retain this on update.', 'post_excerpt' => 'Protected byte string <b>retain</b>.', 'post_author' => $actor ], true );
    if ( is_wp_error( $source ) ) { throw new RuntimeException( $source->get_error_message() ); } $source = (int) $source; $created[] = $source;
    add_post_meta( $source, 'nova_canary_unmapped', 'Unmapped bytes ' . $suffix );
    $fixture = nova_canary_fixture( $source, 'update', $suffix . '-update', $actor ); $plan = nova_canary_plan( $fixture );
    $result = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::apply( $plan, $fixture['context']['operation_id'] ), 'Native apply' );
    $recovered = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::recover( $plan, $fixture['context']['operation_id'] ), 'Native recovery' );
    nova_canary_assert( $recovered['state'] === 'cms_committed' && $recovered['post_id'] === $source, 'Lost apply response must recover exact target.' );
    $finished = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::finish( $plan, $recovered ), 'Native finish' );
    nova_canary_assert( 'complete' === $finished['state'] && 'draft' === $finished['cms_post_status'], 'Temporary target must remain unpublished.' );
    nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::verify( $plan, $finished ), 'Native final verify' );
    $post = get_post( $source ); nova_canary_assert( $post->post_content === 'Leave empty must retain this on update.' && $post->post_excerpt === 'Protected byte string <b>retain</b>.', 'Update preserve semantics failed.' ); $report[] = 'native update + protected + leave_empty + lost-response recovery';

    $drift = nova_canary_fixture( $source, 'update', $suffix . '-drift', $actor );
    $drift['content']['url_id'] = $fixture['content']['url_id']; $drift['content']['content_item_id'] = $fixture['content']['content_item_id']; $drift['content']['content_item_version_id'] = '92'; $drift['content']['version_number'] = 2; nova_writer_fixture_refresh( $drift );
    $drift_plan = nova_canary_plan( $drift );
    $wpdb->update( $wpdb->posts, [ 'post_title' => 'Concurrent editor ' . $suffix ], [ 'ID' => $source ] ); clean_post_cache( $source );
    $blocked = Nova_Bridge_Suite_Mapped_Writer::apply( $drift_plan, $drift['context']['operation_id'] );
    nova_canary_assert( is_wp_error( $blocked ) && 'nova_writer_native_drift' === $blocked->get_error_code(), 'Editor change must block before writer mutation.' ); $report[] = 'native editor drift blocks with zero planned writes';

    $clone = nova_canary_fixture( $source, 'clone', $suffix . '-clone', $actor ); $clone_plan = nova_canary_plan( $clone );
    $wpdb = new Nova_Writer_Canary_DB( $real_db, 'marker_insert' );
    $failed = Nova_Bridge_Suite_Mapped_Writer::apply( $clone_plan, $clone['context']['operation_id'] ); $wpdb = $real_db;
    nova_canary_assert( is_wp_error( $failed ) && 'nova_writer_marker_storage' === $failed->get_error_code(), 'Injected final marker failure must roll back clone.' );
    $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s", basename( rtrim( parse_url( $clone['content']['url'], PHP_URL_PATH ), '/' ) ) ) ); nova_canary_assert( '0' === (string) $count, 'Failed transaction left an orphan clone.' );
    $absent = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::recover( $clone_plan, $clone['context']['operation_id'] ), 'Rollback recovery' ); nova_canary_assert( 'not_committed' === $absent['state'] && $absent['safe_to_apply'] === true, 'Rollback must prove absence under locks.' ); $report[] = 'mid-clone failure rolls back post + all metadata + marker';

    $wpdb = new Nova_Writer_Canary_DB( $real_db, 'commit_ack' );
    $lost = Nova_Bridge_Suite_Mapped_Writer::apply( $clone_plan, $clone['context']['operation_id'] ); $wpdb = $real_db;
    nova_canary_assert( is_wp_error( $lost ) && 'nova_writer_ambiguous_commit' === $lost->get_error_code(), 'Lost commit acknowledgment must be uncertain.' );
    $committed = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::recover( $clone_plan, $clone['context']['operation_id'] ), 'Clone commit recovery' ); $created[] = (int) $committed['post_id'];
    $complete = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::finish( $clone_plan, $committed ), 'Clone finish' );
    $replayed = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::apply( $clone_plan, $clone['context']['operation_id'] ), 'Idempotent replay' );
    nova_canary_assert( $replayed['post_id'] === $complete['post_id'], 'Replay created a second clone.' );
    $cloned_post = get_post( $complete['post_id'] ); nova_canary_assert( 'draft' === $cloned_post->post_status && '' === $cloned_post->post_content && $cloned_post->post_excerpt === 'Protected byte string <b>retain</b>.', 'Clone preserve/empty semantics failed.' );
    nova_canary_assert( get_post_meta( $complete['post_id'], 'nova_canary_unmapped', true ) === 'Unmapped bytes ' . $suffix, 'Complete clone lost unmapped metadata.' ); $report[] = 'lost commit acknowledgment discovers single clone; replay preserves exact identity';
    wp_delete_post( $source, true );
    $without_source = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::recover( $clone_plan, $clone['context']['operation_id'] ), 'Deleted source clone recovery' );
    nova_canary_assert( $without_source['post_id'] === $complete['post_id'], 'Recovery incorrectly depends on a deleted clone source.' ); $report[] = 'committed clone remains recoverable after its temporary source is deleted';
    // A new operation cannot follow a clone after the frozen source was removed.
    $wpdb->update( $wpdb->posts, [ 'post_content' => 'Editor restored a Leave empty field' ], [ 'ID' => $complete['post_id'] ] ); clean_post_cache( $complete['post_id'] );
    $next = $clone; $next['content']['version_number'] = 2; $next['content']['content_item_version_id'] = '92'; $next['context']['operation_id'] = nova_writer_fixture_uuid( $suffix . '-v2' ); $next['content']['content']['h1'] = 'Second generated version';
    nova_writer_fixture_refresh( $next ); $next_plan = Nova_Bridge_Suite_Mapped_Writer::plan( $next['content'], $next['configuration'], $next['context'] );
    nova_canary_assert( is_wp_error( $next_plan ) && get_post( $complete['post_id'] )->post_content === 'Editor restored a Leave empty field', 'New work silently followed a previous clone after the frozen source disappeared.' ); $report[] = 'new operation fails closed instead of following an earlier clone';

    if ( function_exists( 'acf_add_local_field_group' ) ) {
        $root_name = 'nova_canary_matrix_' . $suffix; $root_key = 'field_nova_canary_root_' . $suffix; $leaf_key = 'field_nova_canary_leaf_' . $suffix;
        acf_add_local_field_group( [ 'key' => 'group_nova_canary_' . $suffix, 'title' => 'Request-local canary only', 'fields' => [ [ 'key' => $root_key, 'name' => $root_name, 'label' => 'Matrix', 'type' => 'repeater', 'sub_fields' => [ [ 'key' => $leaf_key, 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ] ] ] ], 'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ] ] );
        $acf_source = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA nested canary ' . $suffix, 'post_excerpt' => 'ACF protected note', 'post_content' => 'ACF original', 'post_author' => $actor ], true ); if ( is_wp_error( $acf_source ) ) { throw new RuntimeException( $acf_source->get_error_message() ); } $acf_source = (int) $acf_source; $created[] = $acf_source;
        foreach ( [ $root_name => '1', '_' . $root_name => $root_key, $root_name . '_0_heading' => 'Original nested heading', '_' . $root_name . '_0_heading' => $leaf_key, 'nova_canary_sibling' => 'Protected sibling bytes' ] as $key => $value ) { add_post_meta( $acf_source, $key, $value ); }
        $acf = nova_canary_fixture( $acf_source, 'update', $suffix . '-acf', $actor ); $path = '/meta_all/' . $root_name . '_0_heading';
        $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( Nova_Bridge_Suite_Strategy::entity( 'post', $acf_source ) ), null, 'path' ); nova_canary_assert( isset( $inventory[ $path ] ), 'The installed provider did not expose its existing nested scalar writer.' );
        $descriptor = array_intersect_key( $inventory[ $path ], array_flip( [ 'path', 'transport', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'selector_data' ] ) );
        unset( $acf['configuration']['local']['fields']['/title'] ); $acf['configuration']['local']['fields'][ $path ] = [ 'mode' => 'mapped', 'source_path' => 'h1' ]; $acf['configuration']['local']['target_descriptors'][ $path ] = $descriptor; nova_writer_fixture_refresh( $acf );
        $acf_plan = nova_canary_plan( $acf ); $acf_applied = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::apply( $acf_plan, $acf['context']['operation_id'] ), 'ACF apply' ); $acf_complete = nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::finish( $acf_plan, $acf_applied ), 'ACF finish' ); nova_canary_result( Nova_Bridge_Suite_Mapped_Writer::verify( $acf_plan, $acf_complete ), 'ACF verify' );
        nova_canary_assert( get_post_meta( $acf_source, $root_name . '_0_heading', true ) === 'Generated fixture heading' && get_post_meta( $acf_source, $root_name, true ) === '1' && get_post_meta( $acf_source, '_' . $root_name . '_0_heading', true ) === $leaf_key && get_post_meta( $acf_source, 'nova_canary_sibling', true ) === 'Protected sibling bytes', 'Nested scalar update changed parent, hidden reference or sibling.' ); $report[] = 'installed ACF existing nested scalar preserves parent count/reference/siblings';
    } else { $report[] = 'ACF provider absent: native checks only; no ACF certification claimed'; }
    echo wp_json_encode( [ 'ok' => true, 'checks' => $report, 'temporary_post_ids' => $created, 'publication' => 'draft_only', 'runtime_probe' => Nova_Bridge_Suite_Mapped_Writer::probe() ], JSON_PRETTY_PRINT ) . "\n";
} finally {
    $wpdb = $real_db;
    // Resolve a committed clone even if a test failed before its response could be captured.
    $orphans = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'draft' AND post_name = %s", 'nova-writer-fixture-' . $suffix . '-clone' ) );
    foreach ( array_unique( array_merge( $created, array_map( 'intval', $orphans ) ) ) as $post_id ) { wp_delete_post( $post_id, true ); }
    wp_set_current_user( $old_actor );
}
