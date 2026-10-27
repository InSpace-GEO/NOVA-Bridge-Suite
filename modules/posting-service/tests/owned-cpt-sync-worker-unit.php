<?php
/** Exercise real worker dispatch and resume using its existing in-memory connector harness. */
require __DIR__ . '/delivery-unit.php';

class Nova_Bridge_Suite_Owned_CPT_Sync {
    public static function configuration_for_snapshot( $snapshot, $site ) { return [ 'mode' => 'owned_cpt_v3', 'site_id' => $site ]; }
}
class Nova_Bridge_Suite_Mapped_Writer extends Contract_Test_Writer {}
class Nova_Bridge_Suite_Owned_CPT_Writer extends Contract_Test_Writer {
    public const FORMAT = 'nova.owned-cpt/v1';
    public static function policy( $snapshot, $configuration ) { return [ 'reference' => [ 'reference_type' => 'post', 'reference_id' => 0 ], 'routing' => [ 'operation' => 'create', 'publication' => 'publish' ] ]; }
    public static function plan( $snapshot, $configuration, $context ) { self::$context = $context; return [ 'format' => self::FORMAT, 'context' => $context ]; }
    public static function result( $plan ) { return [ 'post_id' => 77, 'cms_post_status' => self::$status ]; }
    public static function apply( $plan, $operation ) { ++self::$apply; if ( self::$crash ) { throw new RuntimeException( 'Process lost after commit' ); } return self::result( $plan ); }
    public static function recover( $plan, $operation ) { ++self::$recover; return self::result( $plan ); }
    public static function verify( $plan, $result ) { return self::result( $plan ); }
}
function owned_worker_fixture() {
    Contract_Test_Writer::reset(); $f = nova_contract_fixture(); $store = new Contract_Test_Store();
    $server = new Contract_Test_Server( $f ); $GLOBALS['server'] = $server;
    $worker = new Nova_Bridge_Suite_Posting_Worker( $f['connection'], $store, new Nova_Bridge_Suite_Posting_Client( $f['connection'] ) );
    return [ $f['job'], $store, $server, $worker ];
}
[ $job, $store, $server, $worker ] = owned_worker_fixture();
$done = $worker->process( $job );
delivery_check( 'complete' === $done['state'] && 'owned_cpt_v3' === $done['payload']['native_writer'] && 1 === Contract_Test_Writer::$apply, 'The real worker selects the owned CPT writer for a source-free creation profile.' );
[ $job, $store, $server, $worker ] = owned_worker_fixture();
Contract_Test_Writer::$crash = true;
$pending = $worker->process( $job );
delivery_check( 'applying' === $pending['phase'] && 'owned_cpt_v3' === $pending['payload']['native_writer'], 'An interrupted operation retains the native writer identity and plan.' );
Contract_Test_Writer::$crash = false;
$resumed = ( new Nova_Bridge_Suite_Posting_Worker( nova_contract_fixture()['connection'], $store, new Nova_Bridge_Suite_Posting_Client( nova_contract_fixture()['connection'] ) ) )->process( delivery_resume( $pending ) );
delivery_check( 'complete' === $resumed['state'] && 1 === Contract_Test_Writer::$apply && 1 === Contract_Test_Writer::$recover, 'A fresh worker resumes through the owned CPT writer without repeating the native apply.' );
echo "owned CPT worker dispatch checks passed\n";
