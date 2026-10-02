<?php
/** Local deterministic transaction model. No WordPress site or network is contacted. */
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ELEMENTOR_VERSION', 'writer-unit-only' );
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_userdata( $id ) { return $id === 1; }
function get_current_user_id() { return 1; }
function wp_set_current_user( $id ) {}
function current_user_can( ...$args ) { return true; }
function get_post_type_object( $type ) { return (object) [ 'cap' => (object) [ 'create_posts' => 'create', 'publish_posts' => 'publish' ] ]; }
function current_time( ...$args ) { return '2026-09-22 12:00:00'; }
function home_url( $path ) { return 'https://writer-fixture.invalid' . $path; }
function wp_cache_delete( ...$args ) {}
function wp_cache_set( ...$args ) {}
function acf_get_field( ...$args ) { return []; }
function acf_get_setting( ...$args ) { return 'writer-unit-only'; }
class Writer_Elementor_Stub {
    public static function instance() { return (object) [ 'widgets_manager' => new class { public function get_widget_types( $type ) { return in_array( $type, [ 'heading', 'text-editor', 'button' ], true ); } } ]; }
}
class_alias( 'Writer_Elementor_Stub', 'Elementor\\Plugin' );
require __DIR__ . '/mapped-writer-unit.php';

$historical = $fixture;
check( plan_fixture( $historical )['changes'] === plan_fixture( $fixture )['changes'], 'A retained frozen template revision preserves its native writes.' );
$bad = $historical; $bad['configuration']['template']['mapping']['fields'][0]['source_field'] = 'title'; $bad['content']['configuration'] = $bad['configuration']['template'];
rejects( 'binding_identity', function () use ( $bad ) { plan_fixture( $bad ); } );

$elclone = nova_writer_fixture( 7, 'fixture-layout', 'clone', 'elclone' );
$document = '[ {"id":"abc1234","elType":"widget","widgetType":"heading","settings":{"title":"Original","custom_css":".elementor-element-def5678 { color: red; }"},"elements":[]}, {"id":"def5678","elType":"widget","widgetType":"heading","settings":{"title":"Protected \\u00e9"},"elements":[]} ]';
add_meta( $elclone, '_elementor_data', $document ); add_meta( $elclone, '_elementor_page_settings', serialize( [ 'custom_css' => '[data-id="abc1234"] { display: block; }' ] ) );
bind_heading( $elclone, '/builder/heading', [ 'path' => '/builder/heading', 'writable' => true, 'builder' => 'elementor', 'selector_data' => [ 'element_id' => 'abc1234', 'path' => [ 'title' ] ] ] );
$elclone['configuration']['local']['fields']['/builder/protected'] = [ 'mode' => 'protected' ];
$elclone['configuration']['local']['target_descriptors']['/builder/protected'] = [ 'path' => '/builder/protected', 'builder' => 'elementor' ];
$elclone['inventory']['/builder/protected'] = [ 'path' => '/builder/protected', 'builder' => 'elementor', 'selector_data' => [ 'element_id' => 'def5678', 'path' => [ 'title' ] ] ];
nova_writer_fixture_refresh( $elclone );
$ep = plan_fixture( $elclone ); $tree = json_decode( $ep['changes']['meta'][100], true );
check( $tree[0]['id'] !== 'abc1234' && $tree[1]['id'] !== 'def5678' && $tree[0]['id'] !== $tree[1]['id'], 'Explicit clone regenerates all native node identities.' );
check( $tree[1]['settings'] === json_decode( $document, true )[1]['settings'], 'Protected widget values are retained while clone identity changes.' );
check( strpos( $ep['changes']['meta'][100], 'Protected \\u00e9' ) !== false, 'Untouched protected string token bytes are preserved.' );
check( strpos( $tree[0]['settings']['custom_css'], 'elementor-element-' . $tree[1]['id'] ) !== false, 'Known document CSS references follow regenerated nodes.' );
check( strpos( unserialize( $ep['changes']['meta'][101] )['custom_css'], $tree[0]['id'] ) !== false, 'Known page-setting DOM references follow regenerated nodes.' );
check( plan_fixture( $elclone )['elementor_id_map'] === $ep['elementor_id_map'], 'A retried identical operation has the same deterministic clone ID map.' );
$other = $elclone; $other['context']['operation_id'] = nova_writer_fixture_uuid( 'another-operation' ); check( plan_fixture( $other )['elementor_id_map'] !== $ep['elementor_id_map'], 'A distinct explicit clone receives different node identities.' );
$update = $elclone; $update['configuration']['local']['routing']['operation'] = 'update'; $update['context']['operation'] = 'update'; nova_writer_fixture_refresh( $update );
check( json_decode( plan_fixture( $update )['changes']['meta'][100], true )[0]['id'] === 'abc1234', 'Updates retain existing Elementor node identities.' );
$bad = $elclone; $bad['snapshot']['meta'][0]['meta_value'] = str_replace( 'Protected \\u00e9', '.elementor-element-abc1234', $document ); rejects( 'clone_protected_reference', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $elclone; $bad['snapshot']['meta'][0]['meta_value'] = str_replace( '.elementor-element-def5678 { color: red; }', 'unreviewed target def5678', $document ); rejects( 'clone_reference', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $elclone; $bad['snapshot']['meta'][0]['meta_value'] = str_replace( '.elementor-element-def5678 { color: red; }', 'https://another-page.invalid/#elementor-element-def5678', $document ); rejects( 'clone_reference', function () use ( $bad ) { plan_fixture( $bad ); } );

/** SQL-aware in-memory model: validates transaction boundaries, CAS, markers and recovery. */
final class Writer_Contract_DB {
    public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $term_relationships = 'wp_term_relationships';
    public $last_error = ''; public $insert_id = 0; public $data; public $fail_fence = false; public $lose_commit = false;
    private $transaction = null; private $next_post = 1000; private $next_meta = 1000;
    public function __construct( array $snapshot ) { $this->data = [ $this->posts => [ $snapshot['post']['ID'] => $snapshot['post'] ], $this->postmeta => [], $this->term_relationships => [] ]; foreach ( $snapshot['meta'] as $row ) { $this->data[$this->postmeta][$row['meta_id']] = $row; } }
    public function prepare( $sql, ...$args ) { return json_encode( [ $sql, $args ] ); }
    private function statement( $value ) { $decoded = json_decode( $value, true ); return is_array( $decoded ) ? $decoded : [ $value, [] ]; }
    public function get_results( $statement, $format ) {
        list( $sql, $args ) = $this->statement( $statement );
        if ( strpos( $sql, 'SHOW TABLE STATUS' ) === 0 ) { return array_map( function ( $name ) { return [ 'Name' => $name, 'Engine' => 'InnoDB' ]; }, $args ); }
        if ( strpos( $sql, 'information_schema.TRIGGERS' ) !== false ) { return []; }
        if ( strpos( $sql, 'SELECT * FROM `wp_posts`' ) === 0 ) { return isset( $this->data[$this->posts][$args[0]] ) ? [ $this->data[$this->posts][$args[0]] ] : []; }
        if ( strpos( $sql, 'SELECT ID FROM' ) === 0 ) { return array_values( array_filter( $this->data[$this->posts], function ( $row ) use ( $args ) { return $row['post_name'] === $args[0] && in_array( $row['post_type'], [ $args[1], $args[2] ], true ) && (int) $row['ID'] !== $args[3]; } ) ); }
        if ( strpos( $sql, '`wp_term_relationships`' ) !== false ) { return []; }
        if ( strpos( $sql, '`wp_postmeta`' ) !== false ) {
            $rows = array_filter( $this->data[$this->postmeta], function ( $row ) use ( $sql, $args ) {
                if ( strpos( $sql, 'WHERE meta_key = ' ) !== false ) { return $row['meta_key'] === $args[0]; }
                return (int) $row['post_id'] === (int) $args[0] && ( count( $args ) < 2 || $row['meta_key'] === $args[1] );
            } ); ksort( $rows, SORT_NUMERIC ); return array_values( $rows );
        }
        throw new RuntimeException( 'Unexpected fixture SELECT: ' . $sql );
    }
    public function query( $statement ) {
        list( $sql, $args ) = $this->statement( $statement );
        if ( strpos( $sql, 'SET TRANSACTION' ) === 0 ) { return 0; }
        if ( 'START TRANSACTION' === $sql ) { check( $this->transaction === null, 'Transactions cannot nest.' ); $this->transaction = $this->data; return 0; }
        if ( 'ROLLBACK' === $sql ) { if ( $this->transaction !== null ) { $this->data = $this->transaction; $this->transaction = null; } return 0; }
        if ( 'COMMIT' === $sql ) { $this->transaction = null; if ( $this->lose_commit ) { $this->lose_commit = false; return false; } return 0; }
        if ( strpos( $sql, 'UPDATE `wp_postmeta` SET post_id' ) === 0 ) {
            list( $post, $value, $id, $old ) = $args;
            if ( ! isset( $this->data[$this->postmeta][$id] ) || $this->data[$this->postmeta][$id]['meta_value'] !== $old ) { return 0; }
            $this->data[$this->postmeta][$id]['post_id'] = (string) $post; $this->data[$this->postmeta][$id]['meta_value'] = $value; return 1;
        }
        if ( strpos( $sql, 'UPDATE `wp_postmeta` SET meta_value' ) === 0 ) {
            list( $value, $id, $post, $old ) = $args;
            if ( ! isset( $this->data[$this->postmeta][$id] ) || $this->data[$this->postmeta][$id]['meta_value'] !== $old || (int) $this->data[$this->postmeta][$id]['post_id'] !== $post ) { return 0; }
            $this->data[$this->postmeta][$id]['meta_value'] = $value; return 1;
        }
        throw new RuntimeException( 'Unexpected fixture query: ' . $sql );
    }
    public function insert( $table, $row, $formats = null ) {
        if ( $table === $this->postmeta && $this->fail_fence && $row['meta_key'] === '_nova_writer_target_fence_v1' ) { return false; }
        $column = $table === $this->posts ? 'ID' : 'meta_id'; $id = $table === $this->posts ? $this->next_post++ : $this->next_meta++;
        $row[$column] = $id; $this->data[$table][$id] = array_map( 'strval', $row ); $this->insert_id = $id; return 1;
    }
    public function update( $table, $values, $where ) { $id = $where['ID']; if ( ! isset( $this->data[$table][$id] ) ) { return false; } $this->data[$table][$id] = array_merge( $this->data[$table][$id], array_map( 'strval', $values ) ); return 1; }
    public function snapshot( int $id ): array { return [ 'post' => $this->data[$this->posts][$id], 'meta' => $this->get_results( $this->prepare( 'SELECT meta_id, post_id, meta_key, meta_value FROM `wp_postmeta` WHERE post_id = %d ORDER BY meta_id', $id ), ARRAY_A ), 'terms' => [] ]; }
}
function contract_result( $result ): array { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; }
function contract_error( $result, string $code ): void { check( is_wp_error( $result ) && $result->get_error_code() === 'nova_writer_' . $code, 'Expected writer error ' . $code . ', received ' . ( is_wp_error( $result ) ? $result->get_error_code() : 'success' ) ); }
function contract_plan( array $fixture ): array { global $wpdb; $fixture['snapshot'] = $wpdb->snapshot( $fixture['context']['target_post_id'] ); $fixture['context']['snapshot_sha256'] = hash( 'sha256', json_encode( $fixture['content'] ) ); return plan_fixture( $fixture ); }
function contract_complete( array $plan ): array { $result = contract_result( Nova_Bridge_Suite_Mapped_Writer::apply( $plan, $plan['context']['operation_id'] ) ); return contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $plan, $result ) ); }

$base = nova_writer_fixture(); $wpdb = new Writer_Contract_DB( $base['snapshot'] );
$p = contract_plan( $base ); $done = contract_complete( $p );
check( $done['state'] === 'complete' && $wpdb->snapshot( 7 )['post']['post_title'] === $base['content']['content']['h1'], 'Native effects and completed version fence persist together.' );
$late = $base; $late['content']['content_item_id'] = '999'; $late['content']['version_number'] = 12; $late['context']['operation_id'] = nova_writer_fixture_uuid( 'other-content' ); $late['content']['content']['h1'] = 'Must not overwrite'; nova_writer_fixture_refresh( $late );
$before = $wpdb->data; $late_plan = contract_plan( $late ); contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $late_plan, $late_plan['context']['operation_id'] ), 'target_conflict' ); check( $wpdb->data === $before, 'Different content identity changes zero native rows.' );
$absent_late = contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $late_plan, $late_plan['context']['operation_id'] ) ); check( $absent_late['state'] === 'not_committed' && $absent_late['no_native_commit'] && ! $absent_late['safe_to_apply'], 'A rejected apply proves absence without declaring replay safe.' );
$equal = $base; $equal['context']['operation_id'] = nova_writer_fixture_uuid( 'equal-impostor' ); $equal['context']['attempt_id'] = nova_writer_fixture_uuid( 'different-attempt' ); $equal_plan = contract_plan( $equal ); contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $equal_plan, $equal_plan['context']['operation_id'] ), 'older_version' );
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $p, $p['context']['operation_id'] ) )['post_id'] === 7, 'Identical delivery operation recovers without a new write.' );
$saved_rows = $wpdb->data[$wpdb->postmeta]; foreach ( $wpdb->data[$wpdb->postmeta] as $id => $row ) { if ( strpos( $row['meta_key'], '_nova_writer_op_' ) === 0 ) { unset( $wpdb->data[$wpdb->postmeta][$id] ); } }
contract_error( Nova_Bridge_Suite_Mapped_Writer::recover( $p, $p['context']['operation_id'] ), 'ambiguous_marker' ); $wpdb->data[$wpdb->postmeta] = $saved_rows;
$new = $base; $new['content']['version_number'] = 2; $new['content']['content_item_version_id'] = '92'; $new['content']['id'] = nova_writer_fixture_uuid( 'delivery-version-2' ); $new['context']['operation_id'] = nova_writer_fixture_uuid( 'version-2' ); $new['context']['attempt_id'] = nova_writer_fixture_uuid( 'attempt-version-2' ); $new['content']['content']['h1'] = 'Newer same content'; nova_writer_fixture_refresh( $new ); contract_complete( contract_plan( $new ) );
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $p, $p['context']['operation_id'] ) )['superseded'] === true, 'Completed earlier delivery reconciles after a newer version.' );
$before = $wpdb->data; $old = $base; $old['context']['operation_id'] = nova_writer_fixture_uuid( 'late-old-version' ); contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( contract_plan( $old ), $old['context']['operation_id'] ), 'older_version' ); check( $wpdb->data === $before, 'Older content version cannot overwrite newer native content.' );

$wpdb = new Writer_Contract_DB( $acf['snapshot'] ); $rp = contract_plan( $acf ); contract_complete( $rp );
$next = $acf; $next['content']['version_number'] = 2; $next['content']['content_item_version_id'] = '92'; $next['content']['id'] = nova_writer_fixture_uuid( 'acf-generation-2' ); $next['context']['attempt_id'] = nova_writer_fixture_uuid( 'acf-attempt-2' ); $next['context']['operation_id'] = nova_writer_fixture_uuid( 'acf-result-2' ); $next['content']['content']['h1'] = 'Regenerated existing row'; nova_writer_fixture_refresh( $next );
contract_complete( contract_plan( $next ) ); check( $wpdb->snapshot( 7 )['meta'][2]['meta_value'] === 'Regenerated existing row', 'A new content version updates the same existing ACF leaf and retains native structure.' );

$native_clone = nova_writer_fixture( 7, 'fixture-layout', 'clone', 'native-clone' ); $wpdb = new Writer_Contract_DB( $native_clone['snapshot'] ); $cp = contract_plan( $native_clone ); $before = $wpdb->data;
$wpdb->fail_fence = true; contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $cp, $cp['context']['operation_id'] ), 'marker_storage' ); check( $wpdb->data === $before, 'Failure persisting the target fence rolls back clone, metadata and all markers atomically.' );
$wpdb->fail_fence = false; $wpdb->lose_commit = true; contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $cp, $cp['context']['operation_id'] ), 'ambiguous_commit' );
$recovered = contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $cp, $cp['context']['operation_id'] ) ); $complete = contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $cp, $recovered ) );
check( count( $wpdb->data[$wpdb->posts] ) === 2 && $complete['post_id'] !== 7, 'Lost commit acknowledgement recovers one existing clone.' );
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::apply( $cp, $cp['context']['operation_id'] ) )['post_id'] === $complete['post_id'], 'Duplicate operation reuses the committed clone.' );
$new_clone = $native_clone; $new_clone['context']['operation_id'] = nova_writer_fixture_uuid( 'distinct-explicit-clone' ); $new_clone['content']['version_number'] = 2; $new_clone['content']['content_item_version_id'] = '92'; $new_clone['content']['id'] = nova_writer_fixture_uuid( 'distinct-clone-delivery' ); $new_clone['context']['attempt_id'] = nova_writer_fixture_uuid( 'distinct-clone-attempt' ); $new_clone['content']['url'] = rtrim( $new_clone['content']['url'], '/' ) . '-another/'; nova_writer_fixture_refresh( $new_clone );
$ncp = contract_plan( $new_clone ); check( $ncp['source_post_id'] === 7 && $ncp['operation'] === 'clone', 'A new explicit clone operation does not follow the prior clone target.' ); $new_complete = contract_complete( $ncp ); check( $new_complete['post_id'] !== $complete['post_id'], 'Only explicit new clone work creates a distinct target.' );
unset( $wpdb->data[$wpdb->posts][7] ); foreach ( $wpdb->data[$wpdb->postmeta] as $id => $row ) { if ( (int) $row['post_id'] === 7 ) { unset( $wpdb->data[$wpdb->postmeta][$id] ); } }
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $cp, $cp['context']['operation_id'] ) )['post_id'] === $complete['post_id'], 'Completed clone recovery does not require its deleted source.' );
$wpdb = new Writer_Contract_DB( $native_clone['snapshot'] ); $missing_source_plan = contract_plan( $native_clone ); $pending = contract_result( Nova_Bridge_Suite_Mapped_Writer::apply( $missing_source_plan, $missing_source_plan['context']['operation_id'] ) );
unset( $wpdb->data[$wpdb->posts][7] ); foreach ( $wpdb->data[$wpdb->postmeta] as $id => $row ) { if ( (int) $row['post_id'] === 7 ) { unset( $wpdb->data[$wpdb->postmeta][$id] ); } }
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $missing_source_plan, $pending ) )['state'] === 'complete', 'A committed clone can finish after source deletion when its own complete copy and fence remain verified.' );

$wpdb = new Writer_Contract_DB( $native_clone['snapshot'] ); $witness_plan = contract_plan( $native_clone ); $witness_result = contract_complete( $witness_plan );
$another_page = nova_writer_fixture( 7, 'fixture-layout', 'clone', 'another-template-page' ); $second_result = contract_complete( contract_plan( $another_page ) );
check( count( $wpdb->data[$wpdb->posts] ) === 3 && $second_result['post_id'] !== $witness_result['post_id'], 'The same unchanged source can safely clone a different NOVA URL/content item.' );
unset( $wpdb->data[$wpdb->posts][$witness_result['post_id']] ); foreach ( $wpdb->data[$wpdb->postmeta] as $id => $row ) { if ( (int) $row['post_id'] === $witness_result['post_id'] ) { unset( $wpdb->data[$wpdb->postmeta][$id] ); } }
$before = $wpdb->data; contract_error( Nova_Bridge_Suite_Mapped_Writer::recover( $witness_plan, $witness_plan['context']['operation_id'] ), 'ambiguous_marker' ); contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $witness_plan, $witness_plan['context']['operation_id'] ), 'ambiguous_marker' );
check( $wpdb->data === $before, 'A retained source witness blocks false absence and duplicate creation after clone-local markers disappear.' );

$publication = nova_writer_fixture( 7, 'fixture-layout', 'update', 'human-publication' ); $wpdb = new Writer_Contract_DB( $publication['snapshot'] ); $publication_plan = contract_plan( $publication ); $draft_result = contract_complete( $publication_plan );
$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish', 'post_date' => '2026-10-02 14:00:00', 'post_date_gmt' => '2026-10-02 12:00:00', 'post_modified' => '2026-10-02 14:00:00', 'post_modified_gmt' => '2026-10-02 12:00:00' ], [ 'ID' => 7 ] );
$published_result = contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $publication_plan, $draft_result ) );
check( $published_result['cms_post_status'] === 'publish' && $published_result['publication_transition']['from'] === 'draft', 'Verified status/date-only human publication updates durable outcome evidence without replaying content.' );
check( contract_result( Nova_Bridge_Suite_Mapped_Writer::verify( $publication_plan, $published_result ) )['cms_post_status'] === 'publish', 'Publication transition evidence remains recoverable.' );
$scheduled = $publication; $wpdb = new Writer_Contract_DB( $scheduled['snapshot'] ); $scheduled_plan = contract_plan( $scheduled ); $scheduled_result = contract_complete( $scheduled_plan );
foreach ( [ 'future', 'private', 'pending', 'draft', 'future' ] as $waiting_status ) {
    $wpdb->update( $wpdb->posts, [ 'post_status' => $waiting_status, 'post_date' => '2026-12-02 14:00:00', 'post_date_gmt' => '2026-12-02 13:00:00', 'post_modified' => '2026-10-02 14:05:00', 'post_modified_gmt' => '2026-10-02 12:05:00' ], [ 'ID' => 7 ] );
    $scheduled_result = contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $scheduled_plan, $scheduled_result ) );
    check( $scheduled_result['cms_post_status'] === $waiting_status && $scheduled_result['cms_post_status'] !== 'publish', 'Unpublished status/date transitions remain verified without being labelled published: ' . $waiting_status );
}
$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish', 'post_modified' => '2026-12-02 14:00:00', 'post_modified_gmt' => '2026-12-02 13:00:00' ], [ 'ID' => 7 ] );
$scheduled_result = contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $scheduled_plan, $scheduled_result ) );
check( $scheduled_result['cms_post_status'] === 'publish' && $scheduled_result['publication_transition']['from'] === 'future', 'Scheduled content becomes published only after WordPress actually changes its native status to publish.' );
$existing_future = nova_writer_fixture( 7, 'fixture-layout', 'update', 'existing-future' ); $existing_future['snapshot']['post']['post_status'] = 'future'; $existing_future['snapshot']['post']['post_date'] = '2026-12-02 14:00:00'; $existing_future['snapshot']['post']['post_date_gmt'] = '2026-12-02 13:00:00'; $existing_future['configuration']['local']['routing']['publication'] = 'preserve'; nova_writer_fixture_refresh( $existing_future );
$wpdb = new Writer_Contract_DB( $existing_future['snapshot'] ); $future_plan = contract_plan( $existing_future ); $future_result = contract_complete( $future_plan );
check( $future_result['cms_post_status'] === 'future' && $wpdb->snapshot( 7 )['post']['post_date_gmt'] === '2026-12-02 13:00:00', 'Updating an existing scheduled post preserves its native schedule and remains unpublished.' );
$wpdb->update( $wpdb->posts, [ 'post_status' => 'draft', 'post_content' => 'Unexpected scheduling edit' ], [ 'ID' => 7 ] ); $before = $wpdb->data;
contract_error( Nova_Bridge_Suite_Mapped_Writer::finish( $future_plan, $future_result ), 'recovery_drift' ); check( $wpdb->data === $before, 'Scheduling with document changes cannot update outcome evidence or replay content.' );
$wpdb = new Writer_Contract_DB( $publication['snapshot'] ); $publication_plan = contract_plan( $publication ); $draft_result = contract_complete( $publication_plan );
$wpdb->update( $wpdb->posts, [ 'post_status' => 'publish', 'post_title' => 'Changed after verification' ], [ 'ID' => 7 ] ); $before = $wpdb->data;
contract_error( Nova_Bridge_Suite_Mapped_Writer::finish( $publication_plan, $draft_result ), 'recovery_drift' ); check( $wpdb->data === $before, 'Publication with unexpected content change produces no acceptance markers or content rewrites.' );

Nova_Bridge_Suite_Mapped_Writer::register_elementor_derived( ELEMENTOR_VERSION, function () { return [ 'completed' => true ]; }, 'local-mock-only' );
$wpdb = new Writer_Contract_DB( $elclone['snapshot'] ); $ecp = contract_plan( $elclone ); $wpdb->lose_commit = true; contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $ecp, $ecp['context']['operation_id'] ), 'ambiguous_commit' );
$ec = contract_result( Nova_Bridge_Suite_Mapped_Writer::recover( $ecp, $ecp['context']['operation_id'] ) ); $ec = contract_result( Nova_Bridge_Suite_Mapped_Writer::finish( $ecp, $ec ) );
check( $ec['elementor_id_map'] === $ecp['elementor_id_map'] && count( $wpdb->data[$wpdb->posts] ) === 2, 'Elementor clone recovery retains the durable ID map and creates no additional copy.' );
$wpdb = new Writer_Contract_DB( $base['snapshot'] ); $deleted_target_plan = contract_plan( $base ); $wpdb->lose_commit = true;
contract_error( Nova_Bridge_Suite_Mapped_Writer::apply( $deleted_target_plan, $deleted_target_plan['context']['operation_id'] ), 'ambiguous_commit' );
check( $wpdb->snapshot( 7 )['post']['post_title'] === $base['content']['content']['h1'], 'Deletion regression starts with a committed native update whose acknowledgment was lost.' );
unset( $wpdb->data[$wpdb->posts][7] ); foreach ( $wpdb->data[$wpdb->postmeta] as $id => $row ) { if ( (int) $row['post_id'] === 7 ) { unset( $wpdb->data[$wpdb->postmeta][$id] ); } }
contract_error( Nova_Bridge_Suite_Mapped_Writer::recover( $deleted_target_plan, $deleted_target_plan['context']['operation_id'] ), 'ambiguous_target' );
echo 'PASS ' . $checks . " total writer and contracted transaction assertions (local model only)\n";
