<?php
define( 'ABSPATH', '/' );
class WP_Error { private $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
class WP_REST_Request { public function __construct( $method ) {} }
function post_type_exists( $type ) { return in_array( $type, [ 'service_page', 'blog', 'blog_two' ], true ); }
$blog_types = [ 'blog' ];
function nova_bridge_suite_get_managed_blog_post_types() { global $blog_types; return $blog_types; }
function rest_get_server() {}
class Nova_Bridge_Suite_Posting_Settings { public static $connection = []; public static function connection() { return self::$connection; } }
eval( 'namespace SEORAI\\ServicePageCPT; class Plugin {}' );
eval( 'namespace SEORAI\\BodycleanCPT; class Plugin {}' );
$options = [];
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[$key] : $default; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { global $options; if ( array_key_exists( $key, $options ) ) { return false; } $options[$key] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { global $options; $options[$key] = $value; return true; }
class Nova_Bridge_Suite_Posting_Protocol { public static function uuid( $id ) { return is_string( $id ) && (bool) preg_match( '/^[a-f0-9-]{36}$/', $id ); } public static function validate( $v, $type ) { return true; } }
function sanitize_text_field( $text ) { return trim( $text ); }
require __DIR__ . '/../../api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require __DIR__ . '/../includes/class-nova-bridge-suite-writing-adapter.php';
class Nova_Bridge_Suite_Posting_Client {
    public static $seed_defaults = []; public static $instances = [];
    public $defaults = []; public $revision = 1; public $writes = []; public $site = '11111111-1111-4111-8111-111111111111';
    public function __construct( $connection = [] ) { $this->defaults = self::$seed_defaults; self::$instances[] = $this; }
    public function connection_error() { return true; } public function site_id() { return $this->site; }
    public function site_request( $method, $path, $body = [], $headers = [] ) {
        if ( $method === 'PUT' ) { $this->writes[] = [$body, $headers]; if ( ($headers['If-Match'] ?? '') !== '"' . $this->revision . '"' ) { return new WP_Error( 'conflict', 'Stale ETag' ); } $this->defaults = $body['defaults']; ++$this->revision; }
        return [ 'status' => 200, 'body' => [ 'defaults' => $this->defaults, 'revision' => $this->revision ], 'etag' => '"' . $this->revision . '"' ];
    }
}
class Nova_Bridge_Suite_Mapping_Sync {
    public static $states = []; public static $syncs = 0; private $client; private $scope;
    public function __construct( $client, $scope ) { $this->client = $client; $this->scope = $scope; }
    public static function scope_state( $site, $scope ) { return self::$states[$site . ':' . $scope] ?? null; }
    public static function destination_enabled( $site ) { return true === get_option( 'nova_posting_v3_' . hash( 'sha256', $site ), false ); }
    public function synchronize( $draft ) {
        ++self::$syncs; $key = $this->client->site_id() . ':' . $this->scope; $old = self::$states[$key] ?? null;
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ); if ( is_wp_error( $input ) ) { return $input; }
        $h = md5( $this->scope ); $id = substr($h,0,8) . '-' . substr($h,8,4) . '-4' . substr($h,13,3) . '-8' . substr($h,17,3) . '-' . substr($h,20,12);
        $record = $input + [ 'id' => $id, 'revision' => $old ? $old['template']['revision'] + (int) ($old['local_revision'] !== $draft['revision']) : 1, 'retired_at' => null ];
        return self::$states[$key] = [ 'template' => $record, 'local_revision' => $draft['revision'] ];
    }
}
require __DIR__ . '/../includes/class-nova-bridge-suite-owned-cpt-sync.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
function descriptions() { return [ 'sp_intro' => 'Introduction for this service.', 'sp_main_1' => 'First main section.', 'sp_main_2' => 'Second main section.', 'sp_faq' => 'FAQ question and answer pairs.', 'blog_intro' => 'Introduction for this article.', 'blog_part_1' => 'First body part.', 'blog_part_2' => 'Second body part.', 'blog_faqs' => 'FAQ question and answer pairs.', 'blog_key_takeaways' => 'Key takeaway bullets.' ]; }
function note() { return 'Keep the intro short. Put FAQ content in sp_faq. Use a neutral style.'; }
$GLOBALS['wp_rest_additional_fields'] = [
    'service_page' => [ 'meta_descriptions' => [ 'schema' => [ 'properties' => [ 'sp_intro' => [], 'sp_main_1' => [] ] ], 'get_callback' => 'descriptions' ], 'meta_note' => [ 'get_callback' => 'note' ] ],
    'blog' => [ 'meta_descriptions' => [ 'schema' => [ 'properties' => [ 'blog_intro' => [], 'blog_part_2' => [] ] ], 'get_callback' => 'descriptions' ] ],
];
$built = Nova_Bridge_Suite_Owned_CPT_Sync::build( 'service_page', 'service' );
check( ! is_wp_error( $built ) && isset( $built['draft']['fields']['/meta/sp_intro'] ) && ! isset( $built['draft']['fields']['/meta/sp_main_2'] ), 'Only active service fields are exported.' );
check( strpos( $built['draft']['guidance'], 'Put FAQ content in sp_faq' ) === false && strpos( $built['draft']['guidance'], 'Keep the intro short' ) !== false, 'Unavailable structure guidance is filtered while site context remains.' );
$wire = Nova_Bridge_Suite_Writing_Adapter::template_input( $built['draft'] );
check( ! is_wp_error( $wire ) && $wire['definition']['version'] === 3 && count( $wire['definition']['fields'] ) === 3, 'Actual adapter emits a V3 destination for active scalar fields.' );
$blog = Nova_Bridge_Suite_Owned_CPT_Sync::build( 'blog', 'informative' );
check( isset( $blog['draft']['fields']['/meta/blog_part_2'] ) && ! isset( $blog['draft']['fields']['/meta/blog_part_1'] ), 'Blog callback is filtered by its own active schema.' );
$GLOBALS['wp_rest_additional_fields']['blog']['meta_descriptions']['schema']['properties']['blog_faqs'] = [];
$GLOBALS['wp_rest_additional_fields']['blog']['meta_descriptions']['schema']['properties']['blog_key_takeaways'] = [];
$blog = Nova_Bridge_Suite_Owned_CPT_Sync::build( 'blog', 'informative' );
$blog_wire = Nova_Bridge_Suite_Writing_Adapter::template_input( $blog['draft'] );
check( ! is_wp_error( $blog_wire ) && count( $blog_wire['definition']['groups'] ) === 2 && count( $blog_wire['definition']['groups'][0]['slots'] ) === 8 && count( $blog_wire['definition']['groups'][1]['slots'] ) === 5, 'Active FAQ and key takeaways use bounded existing V3 scalar groups.' );
check( isset( $blog['local']['fields'][Nova_Bridge_Suite_Writing_Adapter::field_id('/meta/blog_faqs/0/answer')] ), 'FAQ fields have a stable native scalar target.' );
check( strpos( $blog['draft']['guidance'], 'overflow in rich text' ) !== false, 'FAQ content beyond the slot limit is directed into rich text.' );
$client = new Nova_Bridge_Suite_Posting_Client();
$client->defaults['category'] = '33333333-3333-4333-8333-333333333333';
$result = Nova_Bridge_Suite_Owned_CPT_Sync::synchronize( [ 'enabled' => true ], 'service_page', 'service', $client );
check( ! is_wp_error( $result ) && $client->defaults['category'] === '33333333-3333-4333-8333-333333333333' && $client->writes[0][1]['If-Match'] === '"1"', 'Default update preserves unrelated entries and uses the observed ETag.' );
$record = Nova_Bridge_Suite_Mapping_Sync::$states[$client->site . ':owned-cpt:service_page:service']['template'];
$frozen = array_intersect_key( $record, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) );
$snapshot = [ 'site_id' => $client->site, 'configuration' => $frozen ];
$profile = Nova_Bridge_Suite_Owned_CPT_Sync::configuration_for_snapshot( $snapshot, $client->site );
check( ! is_wp_error( $profile ) && $profile['mode'] === 'owned_cpt_v3', 'Exact frozen revision resolves its native profile.' );
check( null === Nova_Bridge_Suite_Owned_CPT_Sync::configuration_for_snapshot( $snapshot, '55555555-5555-4555-8555-555555555555' ), 'A different site cannot use this profile.' );
$same = Nova_Bridge_Suite_Owned_CPT_Sync::synchronize( [ 'enabled' => true ], 'service_page', 'service', $client );
check( ! is_wp_error( $same ) && count( $client->writes ) === 1, 'Unchanged synchronization retains the already selected default.' );
$GLOBALS['wp_rest_additional_fields']['service_page']['meta_descriptions']['schema']['properties']['sp_main_2'] = [];
$changed = Nova_Bridge_Suite_Owned_CPT_Sync::synchronize( [ 'enabled' => true ], 'service_page', 'service', $client );
check( ! is_wp_error( $changed ) && $changed['template_revision'] === 2 && ! is_wp_error( Nova_Bridge_Suite_Owned_CPT_Sync::configuration_for_snapshot( $snapshot, $client->site ) ), 'A changed layout receives a new revision while the original profile remains usable.' );
$snapshot['configuration']['name'] = 'Altered';
check( is_wp_error( Nova_Bridge_Suite_Owned_CPT_Sync::configuration_for_snapshot( $snapshot, $client->site ) ), 'A changed frozen template is rejected.' );
$client->defaults['service'] = '44444444-4444-4444-8444-444444444444'; $calls = Nova_Bridge_Suite_Mapping_Sync::$syncs;
check( is_wp_error( Nova_Bridge_Suite_Owned_CPT_Sync::synchronize( [ 'enabled' => true ], 'service_page', 'service', $client ) ) && $calls === Nova_Bridge_Suite_Mapping_Sync::$syncs, 'Unrelated default is never overwritten or mutated.' );
$before_defaults = $client->defaults; $before_writes = count( $client->writes );
$explicit = Nova_Bridge_Suite_Owned_CPT_Sync::synchronize( [ 'enabled' => true ], 'service_page', 'service', $client, false );
check( ! is_wp_error( $explicit ) && $client->defaults === $before_defaults && count( $client->writes ) === $before_writes, 'An explicitly selected URL profile can synchronize without reading or changing the unrelated default.' );
$options = []; Nova_Bridge_Suite_Mapping_Sync::$states = []; Nova_Bridge_Suite_Mapping_Sync::$syncs = 0; Nova_Bridge_Suite_Posting_Client::$instances = [];
Nova_Bridge_Suite_Posting_Settings::$connection = [ 'enabled' => false ];
Nova_Bridge_Suite_Owned_CPT_Sync::refresh();
check( 0 === Nova_Bridge_Suite_Mapping_Sync::$syncs, 'A disabled connection never synchronizes.' );
$blog_types = [ 'blog', 'blog_two' ];
$GLOBALS['wp_rest_additional_fields']['blog_two'] = $GLOBALS['wp_rest_additional_fields']['blog'];
Nova_Bridge_Suite_Posting_Client::$seed_defaults = [ 'service' => '44444444-4444-4444-8444-444444444444' ];
Nova_Bridge_Suite_Posting_Settings::$connection = [ 'enabled' => true, 'site_id' => '11111111-1111-4111-8111-111111111111', 'base_url' => 'https://posting.example' ];
Nova_Bridge_Suite_Owned_CPT_Sync::refresh();
check( 3 === Nova_Bridge_Suite_Mapping_Sync::$syncs, 'Both ambiguous Blog profiles and a Service profile are synchronized unselected.' );
foreach ( Nova_Bridge_Suite_Posting_Client::$instances as $used ) { check( [] === $used->writes && $used->defaults === Nova_Bridge_Suite_Posting_Client::$seed_defaults, 'Automatic provisioning does not change an existing Service default.' ); }
$markers = array_filter( $options, static function ( $v, $k ) { return strpos( $k, 'nova_owned_cpt_refresh_' ) === 0; }, ARRAY_FILTER_USE_BOTH );
check( count( $markers ) === 3 && count( array_filter( $markers, static function ( $v ) { return ($v['status'] ?? '') === 'unselected'; } ) ) === 3, 'Provisioned but unselected profiles are recorded accurately.' );
echo "owned CPT sync unit checks passed\n";
