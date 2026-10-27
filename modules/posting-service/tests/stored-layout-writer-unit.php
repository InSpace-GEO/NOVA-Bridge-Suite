<?php
/** Real capture/compiler plus native lifecycle/response-loss tests; WordPress and Elementor are mocked. */
namespace Elementor {
    class Plugin { public $documents; public $elements_manager; public $widgets_manager; public static function instance() { static $p; if ( ! $p ) { $p = new self(); $p->documents = new \Layout_Documents(); $p->elements_manager = new \Layout_Elements(); $p->widgets_manager = new \Layout_Widgets(); } return $p; } }
}
namespace SEOR_Elementor_Bridge {
    class Elementor_Service {
        public function get_page_payload( $id, $args ) { return [ 'document' => $GLOBALS['example_document'] ]; }
        public function update_page( $id, $payload ) {
            if ( $GLOBALS['crash_before_save'] ) { $GLOBALS['crash_before_save'] = false; throw new \RuntimeException( 'Interrupted before native save' ); }
            ++$GLOBALS['saves']; $post = $GLOBALS['posts'][$id]; $post->post_title = stripslashes( $payload['title'] ); $post->post_excerpt = stripslashes( $payload['excerpt'] ?? '' );
            $data = json_decode( $payload['elementor_data'], true ); if ( $GLOBALS['partial'] ) { array_pop( $data[0]['elements'] ); }
            $GLOBALS['meta'][$id]['_elementor_data'] = json_encode( $data ); $GLOBALS['meta'][$id]['_elementor_page_settings'] = $payload['elementor_page_settings'] ?? []; $GLOBALS['meta'][$id]['_wp_page_template'] = $payload['template'] ?? ''; $GLOBALS['meta'][$id]['_elementor_edit_mode'] = 'builder';
            if ( $GLOBALS['crash_save'] ) { $GLOBALS['crash_save'] = false; throw new \RuntimeException( 'Lost native save response' ); } return [ 'post_id' => $id ];
        }
    }
}
namespace {
define( 'ABSPATH', __DIR__ . '/' ); define( 'ELEMENTOR_VERSION', '3.30.3' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' ); $wp_version = '6.8';
class WP_Error { private $code; private $message; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
class WP_Post { public $ID; public $post_type; public $post_status; public $post_title = ''; public $post_excerpt = ''; public $post_name; public $post_parent = 0; public $post_author = 1; public $post_content = ''; public $post_content_filtered = ''; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_cache_delete( $key, $group ) {}
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function add_option( $key, $value, ...$rest ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, ...$rest ) { $GLOBALS['options'][$key] = $value; return true; }
function current_user_can( $cap, $id = 0 ) { return $GLOBALS['permission'] && get_current_user_id() !== 3; }
function get_current_user_id() { return $GLOBALS['current_user'] ?? 1; }
function wp_set_current_user( $id ) { $GLOBALS['current_user'] = $id; }
function get_post_type_object( $type ) { return $type === 'page' ? (object) [ 'cap' => (object) [ 'create_posts' => 'edit_pages', 'edit_posts' => 'edit_pages', 'publish_posts' => 'publish_pages' ] ] : null; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_kses_post( $value ) { return preg_replace( '#<script\\b[^>]*>.*?</script>#is', '', $value ); }
function wp_slash( $value ) { if ( is_array( $value ) ) { return array_map( 'wp_slash', $value ); } return is_string( $value ) ? addslashes( $value ) : $value; }
function clean_post_cache( $id ) {}
function get_post( $id ) { if ( $id === 99 ) { ++$GLOBALS['source_reads']; if ( empty( $GLOBALS['capturing'] ) ) { throw new RuntimeException( 'Example page no longer exists' ); } return (object) [ 'ID' => 99, 'post_type' => 'page', 'post_title' => 'Example page', 'post_excerpt' => '' ]; } return $GLOBALS['posts'][$id] ?? null; }
function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
function get_post_meta( $id, $key, $single = true ) { if ( $id === 99 ) { get_post( 99 ); return [ '_elementor_edit_mode' => 'builder', '_elementor_data' => json_encode( $GLOBALS['example_document'] ), '_elementor_page_settings' => [ 'background_color' => '#445566' ], '_wp_page_template' => 'elementor_canvas' ][$key] ?? ''; } return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return get_post_meta( $id, $key, true ) !== ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][$id][$key] = $value; if ( $key === '_nova_stored_layout_owner' && $GLOBALS['crash_owner'] ) { $GLOBALS['crash_owner'] = false; throw new RuntimeException( 'Lost ownership marker response' ); } return true; }
function get_page_template_slug( $id ) { return get_post_meta( $id, '_wp_page_template', true ); }
function get_permalink( $id ) { $post = get_post( $id ); return ( $post->post_parent ? get_permalink( $post->post_parent ) : 'https://example.test/' ) . $post->post_name . '/'; }
function get_sample_permalink( $id ) { $post = get_post( $id ); return [ ( $post->post_parent ? get_permalink( $post->post_parent ) : 'https://example.test/' ) . '%pagename%/', $post->post_name ]; }
function wp_unique_post_slug( $slug, $id, $status, $type, $parent ) { foreach ( $GLOBALS['posts'] as $post ) { if ( $post->ID !== $id && $post->post_name === $slug ) { return $slug . '-2'; } } return $slug; }
function add_filter( $key, $fn, ...$rest ) { $GLOBALS['filters'][$key][] = $fn; }
function remove_filter( $key, $fn, ...$rest ) { $GLOBALS['filters'][$key] = array_values( array_filter( $GLOBALS['filters'][$key] ?? [], static function ( $v ) use ( $fn ) { return $v !== $fn; } ) ); }
function wp_insert_post( $data, $error = false ) { foreach ( $GLOBALS['filters']['wp_insert_post_data'] ?? [] as $fn ) { $data = $fn( $data, $data, $data ); } $post = new WP_Post(); $post->ID = 10 + count( $GLOBALS['posts'] ); foreach ( $data as $k => $v ) { $post->$k = is_string( $v ) ? stripslashes( $v ) : $v; } $GLOBALS['posts'][$post->ID] = $post; ++$GLOBALS['inserts']; if ( $GLOBALS['crash_insert'] ) { $GLOBALS['crash_insert'] = false; throw new RuntimeException( 'Lost insert response' ); } return $post->ID; }
function wp_update_post( $data, $error = false ) { $post = get_post( $data['ID'] ); foreach ( $data as $k => $v ) { if ( $k !== 'ID' ) { $post->$k = is_string( $v ) ? stripslashes( $v ) : $v; } } ++$GLOBALS['publication_changes']; if ( $GLOBALS['crash_publish'] ) { $GLOBALS['crash_publish'] = false; throw new RuntimeException( 'Lost publication response' ); } return $post->ID; }
class Layout_Documents { public function get( $id, $cache = false ) { return (object) []; } }
// Elementor before 3.34 exposes separate public registries, without get_element().
class Layout_Elements { public function get_element_types( $type = null ) { $types = array_fill_keys( [ 'section', 'column', 'container' ], (object) [] ); return $type === null ? $types : ( $types[$type] ?? null ); } }
class Layout_Widgets { public function get_widget_types( $type = null ) { $types = $GLOBALS['missing_widget'] ? [] : array_fill_keys( [ 'heading', 'text-editor' ], (object) [] ); return $type === null ? $types : ( $types[$type] ?? null ); } }
class Layout_DB { public $posts = 'wp_posts'; public $last_error = ''; public function prepare( $q, ...$args ) { return [ $q, $args ]; } public function get_var( $q ) { return '1'; } public function get_col( $q ) { $ids = []; foreach ( $GLOBALS['posts'] as $post ) { if ( $post->post_content_filtered === $q[1][0] && $post->post_type === $q[1][1] ) { $ids[] = $post->ID; } } return $ids; } }
$wpdb = new Layout_DB();
require_once dirname( __DIR__, 2 ) . '/elementor/includes/class-element-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
class Nova_Bridge_Suite_Recipe_Builders {
    public static function verify_saved( $builder, $id, $payload ) { return json_decode( get_post_meta( $id, '_elementor_data', true ), true ) === $payload['elementor_data'] && get_post_meta( $id, '_elementor_page_settings', true ) === $payload['elementor_page_settings'] && get_page_template_slug( $id ) === $payload['template'] ? [ 'post_id' => $id ] : new WP_Error( 'readback', 'Native styling/content changed' ); }
}
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function normalize_url( $url ) { if ( ! is_string( $url ) || ! preg_match( '#^https://example\.test/(?:[a-z-]+(?:/[a-z-]+)*/?)?$#D', $url ) ) { return new WP_Error( 'foreign_url', 'Wrong site' ); } return [ 'origin' => 'https://example.test', 'path' => rtrim( parse_url( $url, PHP_URL_PATH ), '/' ) ?: '/', 'identity' => rtrim( $url, '/' ) ]; }
    public static function resolve( $url, $resource = null ) {
        $wanted = self::normalize_url( $url ); if ( is_wp_error( $wanted ) ) { return $wanted; } $matches = [];
        foreach ( $GLOBALS['posts'] as $post ) { if ( rtrim( get_permalink( $post->ID ), '/' ) === $wanted['identity'] ) { $matches[] = $post; } }
        if ( ! $matches ) { return new WP_Error( 'nova_mapped_target_not_found', 'No exact native URL' ); }
        if ( count( $matches ) !== 1 ) { return new WP_Error( 'nova_mapped_target_ambiguous', 'Several native URL owners' ); }
        $post = $matches[0]; if ( $resource !== 'post_type:' . $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) { return new WP_Error( 'nova_mapped_target_resource_mismatch', 'Wrong type or permission' ); }
        return [ 'reference_type' => 'post', 'reference_id' => $post->ID, 'resource_id' => $resource, 'requested_url' => $wanted['identity'] ];
    }
    public static function creation( $resource, $url, $language ) { $normalized = self::normalize_url( $url ); if ( is_wp_error( $normalized ) ) { return $normalized; } if ( $language !== 'en-US' ) { return new WP_Error( 'language', 'Wrong language' ); } $slug = trim( parse_url( $url, PHP_URL_PATH ), '/' ); foreach ( $GLOBALS['posts'] as $post ) { if ( $post->post_name === $slug ) { return new WP_Error( 'occupied', 'Already occupied' ); } } return [ 'slug' => $slug, 'parent' => 0, 'target_url' => $normalized['identity'], 'language' => $language, 'native_language' => [ 'provider' => 'core', 'code' => 'en-us' ] ]; }
    public static function with_creation_language( $target, $fn ) { return $fn(); }
    public static function verify_created_language( $resource, $kind, $id, $target ) { return true; }
    public static function native_url( $resource, $kind, $id ) { return get_permalink( $id ); }
}
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-writer.php';
function check( $value, $why ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $why ); } }
function ok( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function op( $n ) { return '11111111-1111-4111-8111-' . sprintf( '%012d', $n ); }
function reset_native() { $GLOBALS['options'] = $GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['filters'] = []; $GLOBALS['inserts'] = $GLOBALS['saves'] = $GLOBALS['publication_changes'] = $GLOBALS['source_reads'] = 0; $GLOBALS['current_user'] = 1; $GLOBALS['permission'] = true; $GLOBALS['crash_insert'] = $GLOBALS['crash_save'] = $GLOBALS['crash_publish'] = $GLOBALS['crash_owner'] = $GLOBALS['crash_before_save'] = $GLOBALS['partial'] = $GLOBALS['missing_widget'] = false; }
function existing_page( $id = 10, $slug = 'article', $status = 'publish', $parent = 0, $type = 'page' ) {
    $post = new WP_Post(); $post->ID = $id; $post->post_type = $type; $post->post_name = $slug; $post->post_status = $status; $post->post_parent = $parent; $post->post_author = 7; $post->post_title = 'Previous page'; $post->post_excerpt = 'Previous excerpt';
    $GLOBALS['posts'][$id] = $post; $GLOBALS['meta'][$id] = [ '_elementor_data' => json_encode( [ [ 'id' => 'old', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ] ), '_elementor_page_settings' => [ 'background_color' => '#old' ], '_wp_page_template' => 'default' ];
}
function fixture( $civ = '501', $version = 3, $status = 'publish' ) {
    $payload = [ 'elementor_data' => [ [ 'id' => 'c000001', 'elType' => 'container', 'settings' => [ 'background_color' => '#112233', 'opacity' => 0.75 ], 'elements' => [ [ 'id' => 'h000001', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Example title', 'header_size' => 'h1', 'color' => '#abcdef' ], 'elements' => [] ], [ 'id' => 'b000001', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => 'Example content' ], 'elements' => [] ] ] ] ], 'elementor_page_settings' => [ 'background_color' => '#445566' ], 'template' => 'elementor_canvas' ];
    $local = [ 'schema_version' => 1, 'reference_type' => 'post', 'reference_id' => 99, 'revision' => 'saved-1', 'profile_page_type' => 'service', 'label' => 'Stored creation', 'catalog_mode' => 1 === $version ? 'nova' : 'destination', 'guidance_mode' => 'clear', 'guidance' => '', 'repeat_slots' => [], 'destination_groups' => [], 'routing' => [ 'operation' => 'create', 'publication' => $status, 'locale' => 'en-US' ], 'fields' => [] ];
    foreach ( [ '/title' => 'title', '/body' => 'content' ] as $path => $source ) { $local['fields'][$path] = [ 'mode' => 1 === $version ? 'mapped' : 'adapt', 'source_path' => 1 === $version ? $source : '', 'instructions' => '', 'required' => true, 'description' => [ 'label' => $source, 'purpose' => 'Place the supplied ' . $source . ' here.', 'value_type' => $source === 'title' ? 'text' : 'rich_text', 'constraints' => [] ] ]; }
    $local['target_descriptors'] = [ '/title' => [ 'path' => '/title' ], '/body' => [ 'path' => '/body', 'builder' => 'elementor', 'selector_data' => [ 'element_id' => 'b000001', 'path' => [ 'editor' ] ] ] ];
    $GLOBALS['example_document'] = $payload['elementor_data']; $GLOBALS['capturing'] = true; try { $local['stored_layout'] = ok( Nova_Bridge_Suite_Stored_Layout::capture( $local ) ); } finally { $GLOBALS['capturing'] = false; $GLOBALS['source_reads'] = 0; }
    $template = [ 'id' => op( 99 ), 'revision' => 1, 'definition' => [ 'version' => $version ], 'mapping' => [ 'version' => $version, 'fields' => [ [ 'field_id' => Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ), 'source_field' => 'title' ], [ 'field_id' => Nova_Bridge_Suite_Writing_Adapter::field_id( '/body' ), 'source_field' => 'content' ] ] ] ];
    $configuration = [ 'mode' => 'stored_layout_v3', 'site_id' => op( 88 ), 'local' => $local, 'template' => $template, 'profile_digest' => hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $local ) ) ];
    $title = "New creator's café 😀 " . $civ; $body = '<p>Fresh body ' . $civ . '</p>';
    $content = [ 'id' => op( 87 ), 'kind' => 'adaptation.delivery', 'schema_version' => 2, 'site_id' => op( 88 ), 'url_id' => '42', 'content_item_id' => '101', 'content_item_version_id' => $civ, 'version_number' => (int) $civ - 500, 'source_sha256' => str_repeat( 'a', 64 ), 'configuration' => $template, 'url' => 'https://example.test/article/', 'language' => 'en-US', 'destination_values' => [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => $title, Nova_Bridge_Suite_Writing_Adapter::field_id( '/body' ) => $body ], 'content' => [ 'title' => $title, 'content' => $body ] ];
    if ( 1 === $version ) { unset( $content['kind'], $content['schema_version'], $content['destination_values'] ); } return [ $content, $configuration ];
}
function planned( $n = 1, $civ = '501', $version = 3, $status = 'publish' ) { list( $content, $configuration ) = fixture( $civ, $version, $status ); return ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( $n ) ] ) ); }
$checks = 0;
check( ! method_exists( \Elementor\Plugin::instance()->elements_manager, 'get_element' ), 'Fixture exercises the Elementor 3.30.3 public registries without the newer shortcut.' );
foreach ( [ [ 'publish', 'preserve', 'publish' ], [ 'draft', 'preserve', 'draft' ], [ 'draft', 'publish', 'publish' ], [ 'publish', 'draft', 'draft' ] ] as [ $initial, $publication, $desired ] ) {
    reset_native(); existing_page( 20, 'services' ); existing_page( 10, 'article', $initial, 20 );
    list( $content, $configuration ) = fixture( '501', 3, $publication ); $content['url'] = '/services/article/';
    $policy = ok( Nova_Bridge_Suite_Stored_Layout_Writer::policy( $content, $configuration ) );
    $plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 1 ), 'target_post_id' => $policy['reference']['reference_id'] ] ) );
    check( $policy['routing']['operation'] === 'update' && $policy['reference']['reference_id'] === 10 && $plan['target']['parent'] === 20 && $plan['desired_status'] === $desired, 'Exact existing pages select update with their native parent and chosen status policy.' );
    check( ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) )['safe_to_apply'], 'An unchanged existing page without an operation journal is safe to apply.' );
    $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
    check( $inserts === 0 && $saves === 1 && $saved['post_id'] === 10 && $saved['url'] === 'https://example.test/services/article/' && get_post( 10 )->post_parent === 20 && get_post( 10 )->post_author === 7 && get_post_status( 10 ) === $desired, 'Applying the saved layout preserves existing ID, URL, parent and author without inserting a duplicate.' );
    check( get_post( 10 )->post_title === $plan['post_fields']['title'] && get_post( 10 )->post_excerpt === $plan['post_fields']['excerpt'] && get_page_template_slug( 10 ) === 'elementor_canvas' && json_decode( get_post_meta( 10, '_elementor_data' ), true )[0]['settings']['background_color'] === '#112233', 'Existing title/excerpt and complete Elementor layout are replaced by the saved mapped template.' );
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
    check( $saves === 1 && $inserts === 0, 'Replaying an adopted page operation does not save or insert again.' );
    list( $newer, $configuration ) = fixture( '502', 3, $publication ); $newer['url'] = $content['url'];
    ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $newer, $configuration, [ 'operation_id' => op( 2 ) ] ) ), op( 2 ) ) );
    check( $saves === 2 && $inserts === 0 && is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 3 ) ] ) ), 'Adoption binds the content association and rejects a stale version after its owned update.' );
}
foreach ( [ 'crash_owner', 'crash_before_save', 'crash_save', 'crash_publish' ] as $crash ) {
    reset_native(); existing_page( 10, 'article', 'draft' ); $plan = planned(); $GLOBALS[$crash] = true;
    check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ), 'Interrupted existing-page replacement retains its operation.' );
    $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
    $saved = ( $proof['state'] ?? '' ) === 'not_committed' ? ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) : $proof;
    check( $saved['post_id'] === 10 && $inserts === 0 && $saves === 1 && $publication_changes === 1, 'Ownership/save/publication interruptions recover one existing page without replaying a completed save.' );
}
foreach ( [ 'human_edit', 'owner_changed', 'wrong_type', 'ambiguous', 'other_owner', 'private' ] as $case ) {
    reset_native(); existing_page();
    if ( $case === 'wrong_type' ) { $posts[10]->post_type = 'post'; }
    if ( $case === 'ambiguous' ) { existing_page( 11 ); }
    if ( $case === 'other_owner' ) { $meta[10]['_nova_stored_layout_owner'] = [ 'site_id' => op( 77 ), 'url_id' => 'different' ]; }
    if ( $case === 'private' ) { $posts[10]->post_status = 'private'; }
    list( $content, $configuration ) = fixture(); $plan = Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 1 ) ] );
    if ( in_array( $case, [ 'human_edit', 'owner_changed' ], true ) ) {
        ok( $plan ); if ( $case === 'human_edit' ) { $posts[10]->post_title = 'Human edit after planning'; } else { $meta[10]['_nova_stored_layout_owner'] = [ 'site_id' => op( 77 ) ]; }
        check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ), 'Changed existing pages cannot be declared safe without a journal.' );
        $plan = Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) );
    }
    check( is_wp_error( $plan ) && $inserts === 0 && $saves === 0, 'Changed, ambiguously owned, wrong-type or unsupported existing pages fail before replacement.' );
}
reset_native(); list( $content, $configuration ) = fixture(); existing_page();
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 1 ), 'target_post_id' => 0 ] ) ), 'A target appearing between policy selection and planning cannot silently change create into update.' );
reset_native(); list( $content, $configuration ) = fixture();
$absolute_plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $configuration, [ 'operation_id' => op( 1 ) ] ) );
foreach ( [ '/article/', 'article/' ] as $url ) {
    $relative = $content; $relative['url'] = $url; $snapshot = $relative;
    $relative_plan = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $relative, $configuration, [ 'operation_id' => op( 1 ) ] ) );
    check( $relative_plan === $absolute_plan && $relative === $snapshot, 'Relative and full URLs produce the same creation plan without changing the authenticated snapshot.' );
}
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $relative_plan, op( 1 ) ) );
list( $newer, $configuration ) = fixture( '502' );
$absolute_update = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $newer, $configuration, [ 'operation_id' => op( 2 ) ] ) );
foreach ( [ '/article/', 'article/' ] as $url ) {
    $relative = $newer; $relative['url'] = $url; $snapshot = $relative;
    $relative_update = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $relative, $configuration, [ 'operation_id' => op( 2 ) ] ) );
    check( $relative_update === $absolute_update && $relative === $snapshot && $relative_update['target_post_id'] === 10, 'Relative and full URLs identify the same owned update without changing delivery identity.' );
}
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $relative_update, op( 2 ) ) );
check( $inserts === 1 && $saves === 2, 'Relative creation and its newer owned update use one native page.' );
foreach ( [ 'https://other.test/article/', '//example.test/article/', '../article/', '/%2e%2e/article/', '/article//', '/article/?x=1', '/article/#fragment' ] as $url ) {
    foreach ( [ false, true ] as $owned ) {
        reset_native(); if ( $owned ) { ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( planned(), op( 1 ) ) ); }
        list( $invalid, $configuration ) = fixture( '502' ); $invalid['url'] = $url;
        $result = Nova_Bridge_Suite_Stored_Layout_Writer::plan( $invalid, $configuration, [ 'operation_id' => op( 2 ) ] );
        check( is_wp_error( $result ) && $result->get_error_code() === 'nova_layout_writer_native' && $inserts === ( $owned ? 1 : 0 ), 'Foreign or ambiguous URLs fail before new or owned native writes.' );
    }
}
reset_native(); $plan = planned(); $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
check( $inserts === 1 && $saves === 1 && $publication_changes === 1 && $source_reads === 0 && $saved['cms_post_status'] === 'publish', 'Fresh stored-layout publication uses no example page and one native save.' );
check( get_page_template_slug( 10 ) === 'elementor_canvas' && get_post_meta( 10, '_elementor_page_settings' ) === [ 'background_color' => '#445566' ] && json_decode( get_post_meta( 10, '_elementor_data' ), true )[0]['settings']['background_color'] === '#112233', 'Captured styling, native page settings and page template survive.' );
check( get_post( 10 )->post_title === "New creator's café 😀 501" && $saved['url'] === 'https://example.test/article/', 'Fitted title, Unicode and exact planned URL survive.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::verify( $plan, $saved ) );
check( $inserts === 1 && $saves === 1 && $publication_changes === 1, 'Repeated operation does not insert, save or publish again.' );
foreach ( [ 'crash_insert', 'crash_save', 'crash_publish' ] as $crash ) { reset_native(); $plan = planned(); $GLOBALS[$crash] = true; check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ), 'Native response loss remains visible.' ); $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ); $saved = ( $proof['state'] ?? '' ) === 'not_committed' ? ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) : $proof; check( $saved['post_id'] === 10 && $inserts === 1 && $saves === 1 && $publication_changes === 1, 'Response loss recovers same native page without duplicate insert/save/publication.' ); }
reset_native(); $plan = planned(); $partial = true; check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ), 'Partial native save is not reported as success.' ); check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ) && is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) && $inserts === 1 && $saves === 1, 'Partial native save blocks replay and duplicate pages.' );
reset_native(); $first = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( planned(), op( 1 ) ) ); $second = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( planned( 2, '502' ), op( 2 ) ) ); check( $first['post_id'] === $second['post_id'] && $inserts === 1 && $saves === 2 && get_post( 10 )->post_title === "New creator's café 😀 502", 'A newer owned source version safely updates the same generated page.' );
list( $old, $config ) = fixture(); check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $old, $config, [ 'operation_id' => op( 3 ) ] ) ), 'An older version cannot overwrite its generated page.' );
$meta[10]['_elementor_page_settings']['background_color'] = '#human'; list( $next, $config ) = fixture( '503' ); check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $next, $config, [ 'operation_id' => op( 3 ) ] ) ), 'Manual styling changes are protected from replacement.' );
reset_native(); $plan = planned( 1, '501', 1, 'draft' ); $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ); check( $saved['cms_post_status'] === 'draft' && $publication_changes === 0 && $saves === 1, 'V1 direct content creates the same stored layout without requiring an LLM or publication.' );
reset_native(); list( $content, $config ) = fixture(); $policy = ok( Nova_Bridge_Suite_Stored_Layout_Writer::policy( $content, $config ) ); check( $policy['reference']['reference_id'] === 0 && $policy['routing']['operation'] === 'create', 'New URL policy contains no source native page dependency.' );
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::probe_mapping( $config['template'], $config['template']['mapping'], $config['local'] ) ), 'Activation requires the configured publishing actor.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::probe_mapping( $config['template'], $config['template']['mapping'], $config['local'], [ 'actor_user_id' => 2 ] ) ); check( get_current_user_id() === 1 && $source_reads === 0, 'Mapping approval works on the older registries, checks the configured actor using only the stored layout, then restores the administrator.' );
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::probe_mapping( $config['template'], $config['template']['mapping'], $config['local'], [ 'actor_user_id' => 3 ] ) ) && get_current_user_id() === 1, 'An unprivileged publishing actor cannot borrow the approving administrator permissions.' );
foreach ( [ 'url', 'language', 'permission', 'widget' ] as $case ) { reset_native(); list( $content, $config ) = fixture(); if ( $case === 'url' ) { $content['url'] = 'https://other.test/article/'; } if ( $case === 'language' ) { $content['language'] = 'nl-NL'; } if ( $case === 'permission' ) { $permission = false; } if ( $case === 'widget' ) { $missing_widget = true; } check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 1 ) ] ) ) && $inserts === 0, 'Invalid site/language/permission/provider fails before insertion.' ); }
reset_native(); $plan = planned(); ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ); list( $content, $config ) = fixture( '502' ); $content['url_id'] = '43'; check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 2 ) ] ) ) && $inserts === 1, 'An unrelated URL identity cannot take over an occupied native URL.' ); unset( $posts[10] ); check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) ) && $inserts === 1, 'Deletion does not silently recreate the generated page.' );
echo "Stored-layout writer checks: $checks passed.\n";
}
