<?php
/** Real recipe compilation and writer journal, with in-memory WordPress persistence only. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/blocks.php' ) ) { echo "SKIP: set NOVA_WP_CORE_FIXTURE to the local WordPress block-parser fixture.\n"; exit( 0 ); }
define( 'ABSPATH', __DIR__ . '/' ); define( 'NOVA_BRIDGE_SUITE_VERSION', '3.0.0' ); $wp_version = '6.8';
class WP_Error { private $code; private $message; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
class WP_Post { public $ID; public $post_type; public $post_status; public $post_title = ''; public $post_excerpt = ''; public $post_name; public $post_parent = 0; public $post_author = 1; public $post_content = ''; public $post_content_filtered = ''; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function wp_slash( $v ) { return is_array( $v ) ? array_map( 'wp_slash', $v ) : ( is_string( $v ) ? addslashes( $v ) : $v ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
function wp_kses_post( $v ) { return preg_replace( '#<script\\b[^>]*>.*?</script>#is', '', $v ); }
function esc_html( $v ) { return htmlspecialchars( $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( $v ) { return esc_html( $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
function apply_filters( $hook, $v ) { return $v; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, ...$rest ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, ...$rest ) { $GLOBALS['options'][$key] = $value; return true; }
function wp_cache_delete( $key, $group ) {}
function clean_post_cache( $id ) {}
function current_user_can( $cap, $id = 0 ) { return ! in_array( $cap, $GLOBALS['denied'] ?? [], true ); }
function get_current_user_id() { return $GLOBALS['actor'] ?? 1; }
function wp_set_current_user( $id ) { $GLOBALS['actor'] = $id; }
function get_post_type_object( $type ) { return in_array( $type, [ 'page', 'post', 'portfolio' ], true ) ? (object) [ 'name' => $type, 'public' => true, 'hierarchical' => $type === 'page', 'cap' => (object) [ 'create_posts' => 'create_' . $type, 'edit_posts' => 'edit_' . $type, 'publish_posts' => 'publish_' . $type ] ] : null; }
function post_type_supports( $type, $feature ) { return $feature === 'editor' && get_post_type_object( $type ) && empty( $GLOBALS['editor_unavailable'] ); }
function home_url( $path = '/' ) { return 'https://fixture.test' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_status( $id ) { return get_post( $id )->post_status ?? false; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][$id] ?? [] ); }
function update_post_meta( $id, $key, $v ) { if ( $key === ( $GLOBALS['drop_meta'] ?? null ) ) { return false; } $v = wp_unslash( $v ); $GLOBALS['meta'][$id][$key] = is_scalar( $v ) || null === $v ? (string) $v : $v; if ( $key === ( $GLOBALS['crash_meta'] ?? null ) ) { $GLOBALS['crash_meta'] = null; throw new RuntimeException( 'Lost completed metadata save response' ); } return true; }
function get_page_template_slug( $id ) { $template = get_post_meta( $id, '_wp_page_template', true ); return $template === 'default' ? '' : $template; }
function get_permalink( $id ) { $p = get_post( $id ); return 'https://fixture.test/' . ( $p->post_type === 'portfolio' ? 'library/' : '' ) . $p->post_name . '/'; }
function get_sample_permalink( $id ) { $p = get_post( $id ); return [ 'https://fixture.test/' . ( $p->post_type === 'portfolio' ? 'library/' : '' ) . '%' . ( $p->post_type === 'page' ? 'pagename' : ( $p->post_type === 'post' ? 'postname' : 'portfolio' ) ) . '%/', $p->post_name ]; }
function wp_unique_post_slug( $slug, $id, $status, $type, $parent ) { foreach ( $GLOBALS['posts'] as $p ) { if ( $p->ID !== $id && $p->post_name === $slug && $p->post_type === $type ) { return $slug . '-2'; } } return $slug; }
function add_filter( $key, $fn, ...$rest ) { $GLOBALS['filters'][$key][] = $fn; }
function remove_filter( $key, $fn, ...$rest ) { $GLOBALS['filters'][$key] = array_values( array_filter( $GLOBALS['filters'][$key] ?? [], static function ( $v ) use ( $fn ) { return $v !== $fn; } ) ); }
function wp_insert_post( $data, $error = false ) { foreach ( $GLOBALS['filters']['wp_insert_post_data'] ?? [] as $fn ) { $data = $fn( $data, $data, $data ); } $p = new WP_Post(); $p->ID = 10 + count( $GLOBALS['posts'] ); foreach ( wp_unslash( $data ) as $k => $v ) { $p->$k = $v; } $GLOBALS['posts'][$p->ID] = $p; ++$GLOBALS['inserts']; if ( $GLOBALS['crash_insert'] ) { $GLOBALS['crash_insert'] = false; throw new RuntimeException( 'Lost insert response' ); } return $p->ID; }
function wp_update_post( $data, $error = false ) { $p = get_post( $data['ID'] ); if ( ! $p ) { return new WP_Error( 'missing', 'Missing native ID' ); } foreach ( wp_unslash( $data ) as $k => $v ) { if ( $k !== 'ID' ) { $p->$k = $v; } } if ( isset( $data['post_content'] ) ) { ++$GLOBALS['saves']; } else { ++$GLOBALS['publishes']; } return $p->ID; }
class Layout_DB { public $posts = 'wp_posts'; public function prepare( $q, ...$args ) { return [ $q, $args ]; } public function get_var( $q ) { return '1'; } public function get_col( $q ) { $ids = []; foreach ( $GLOBALS['posts'] as $p ) { if ( $p->post_content_filtered === $q[1][0] && $p->post_type === $q[1][1] ) { $ids[] = $p->ID; } } return $ids; } }
$wpdb = new Layout_DB();
class Nova_Bridge_Suite_Mapped_Content_Target {
    public static function normalize_url( $url ) { if ( ! is_string( $url ) || ! preg_match( '#^https://fixture\.test/(?:[a-z-]+/?)?$|^https://fixture\.test/library/[a-z-]+/?$#D', $url ) ) { return new WP_Error( 'foreign', 'Invalid canonical fixture URL' ); } return [ 'origin' => 'https://fixture.test', 'path' => rtrim( parse_url( $url, PHP_URL_PATH ), '/' ) ?: '/', 'identity' => rtrim( $url, '/' ) ]; }
    public static function resolve( $url, $resource = null ) { $n = self::normalize_url( $url ); if ( is_wp_error( $n ) ) { return $n; } foreach ( $GLOBALS['posts'] as $p ) { if ( rtrim( get_permalink( $p->ID ), '/' ) === $n['identity'] ) { return $resource === 'post_type:' . $p->post_type ? [ 'reference_type' => 'post', 'reference_id' => $p->ID, 'resource_id' => $resource ] : new WP_Error( 'resource', 'Wrong post type' ); } } return new WP_Error( 'nova_mapped_target_not_found', 'Not found' ); }
    public static function creation( $resource, $url, $language ) { $n = self::normalize_url( $url ); if ( is_wp_error( $n ) ) { return $n; } return [ 'slug' => basename( $n['path'] ), 'parent' => 0, 'target_url' => $n['identity'], 'language' => $language, 'native_language' => [ 'provider' => 'core', 'code' => 'en-us' ] ]; }
    public static function with_creation_language( $target, $fn ) { return $fn(); }
    public static function verify_created_language( $resource, $kind, $id, $target ) { return true; }
    public static function native_url( $resource, $kind, $id ) { return get_permalink( $id ); }
}
require $core . '/class-wp-block-parser.php'; require $core . '/blocks.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-language.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout-writer.php';
function check( $condition, $why ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $why ); } }
function ok( $result ) { if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() . ': ' . $result->get_error_message() ); } return $result; }
function op( $n ) { return '11111111-1111-4111-8111-' . sprintf( '%012d', $n ); }
function reset_native() { $GLOBALS['options'] = $GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['filters'] = $GLOBALS['denied'] = []; $GLOBALS['inserts'] = $GLOBALS['saves'] = $GLOBALS['publishes'] = 0; $GLOBALS['crash_insert'] = $GLOBALS['editor_unavailable'] = false; $GLOBALS['crash_meta'] = $GLOBALS['drop_meta'] = null; }
function fixture( $builder, $type = 'post', $civ = '501', $status = 'publish' ) {
    if ( in_array( $builder, [ 'core', 'custom' ], true ) ) { $blueprint = [ 'document' => [ 'title' => 'Example', 'sections' => [ [ 'body' => '<p>Old body.</p>' ] ], 'faqs' => [] ], 'type' => 'post_type', 'post_type' => $type, 'resource_id' => 'post_type:' . $type, 'template' => 'templates/full.php' ]; $target = '/document/sections/0/body'; }
    else { $blueprint = [ 'document' => parse_blocks( '<!-- wp:paragraph --><p>Old body.</p><!-- /wp:paragraph -->' ), 'template' => 'templates/full.php', 'meta' => [ 'design_mode' => 'wide', 'editor_toggle' => true, 'layout_data' => [ 'color' => '#445566', 'active' => true ] ] ]; $target = '/document/0/innerHTML'; }
    $record = [ 'id' => op( 90 ), 'revision' => 1, 'resource_id' => 'post_type:' . $type, 'builder' => $builder, 'label' => 'Native fixture', 'recipe' => [ 'builder' => $builder, 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => [ [ 'target' => $target, 'source' => '/body', 'format' => 'html', 'required' => true ] ], 'regions' => [] ] ];
    $local = ok( Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ) ); $local['routing']['publication'] = $status;
    $template = [ 'id' => op( 99 ), 'revision' => 1, 'definition' => [ 'version' => 3 ], 'mapping' => [ 'version' => 3, 'fields' => [] ] ];
    $config = [ 'mode' => 'stored_layout_v3', 'site_id' => op( 88 ), 'local' => $local, 'template' => $template, 'profile_digest' => hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $local ) ) ];
    $content = [ 'id' => op( 87 ), 'kind' => 'adaptation.delivery', 'schema_version' => 2, 'site_id' => op( 88 ), 'url_id' => '42', 'content_item_id' => '101', 'content_item_version_id' => $civ, 'version_number' => (int) $civ - 500, 'source_sha256' => str_repeat( 'a', 64 ), 'configuration' => $template, 'url' => 'https://fixture.test/' . ( $type === 'portfolio' ? 'library/' : '' ) . 'article/', 'language' => 'en-US', 'destination_values' => [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => "Creator's café 😀 " . $civ, Nova_Bridge_Suite_Writing_Adapter::field_id( $target ) => '<p>Mapped ' . $civ . '</p>' ] ];
    return [ $content, $config ];
}
function planned( $builder, $type = 'post', $civ = '501', $n = 1, $status = 'publish' ) { [ $content, $config ] = fixture( $builder, $type, $civ, $status ); return ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( $n ) ] ) ); }
$checks = 0;
foreach ( [ 'core', 'gutenberg' ] as $builder ) {
    foreach ( [ 'post', 'page', 'portfolio' ] as $type ) {
        foreach ( [ 'draft', 'publish' ] as $status ) {
            reset_native(); $plan = planned( $builder, $type, '501', 1, $status ); $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
            check( $plan['builder'] === $builder && $saved['post_id'] === 10 && $posts[10]->post_type === $type && $posts[10]->post_status === $status && $inserts === 1 && $saves === 1, "$builder creates one native $type with the chosen status." );
            check( strpos( $posts[10]->post_content, '<p>Mapped 501</p>' ) !== false && get_page_template_slug( 10 ) === 'templates/full.php' && $posts[10]->post_title === "Creator's café 😀 501", 'Compiled content, title, escaping and saved native template survive the actual known-ID writer.' );
            if ( $builder === 'gutenberg' ) { check( count( parse_blocks( $posts[10]->post_content ) ) === 1 && get_post_meta( 10, 'editor_toggle' ) === '1' && get_post_meta( 10, 'layout_data' )['active'] === true, 'Block serialization and native scalar/structured metadata retain WordPress semantics.' ); }
            ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
            check( $inserts === 1 && $saves === 1, 'A completed operation is verified without duplicate insertion or saving.' );
            $new = planned( $builder, $type, '502', 2, $status ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $new, op( 2 ) ) );
            check( $new['target_post_id'] === 10 && $saves === 2 && $inserts === 1 && strpos( $posts[10]->post_content, 'Mapped 502' ) !== false, 'A higher CIV updates the same retained native identity and scope.' );
            $posts[10]->post_content .= '<p>Human edit.</p>'; [ $content, $config ] = fixture( $builder, $type, '503', $status );
            $replacement = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 3 ) ] ) );
            check( strpos( $replacement['native_before']['content'], 'Human edit.' ) !== false, 'A newer delivery uses the current native content as its baseline for every supported builder and destination.' );
            ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $replacement, op( 3 ) ) );
            check( $saves === 3 && $inserts === 1 && strpos( $posts[10]->post_content, 'Mapped 503' ) !== false && strpos( $posts[10]->post_content, 'Human edit.' ) === false, 'The newer authorized version replaces changed content on the same native item.' );
        }
    }
    foreach ( [ 'crash_insert', 'crash_meta' ] as $interruption ) {
        reset_native(); $plan = planned( $builder ); $GLOBALS[$interruption] = $interruption === 'crash_meta' ? '_wp_page_template' : true;
        check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ), 'An interrupted native operation remains recoverable.' );
        $proof = ok( Nova_Bridge_Suite_Stored_Layout_Writer::recover( $plan, op( 1 ) ) );
        $saved = ( $proof['state'] ?? '' ) === 'not_committed' ? ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) : $proof;
        check( $saved['post_id'] === 10 && $inserts === 1 && $saves === 1, 'Lost insertion/save responses recover one ID without duplicate writes.' );
    }
}
reset_native(); $plan = planned( 'gutenberg' ); $drop_meta = 'design_mode';
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) ) && $publishes === 0, 'A dropped native metadata write is detected before publication.' );
reset_native(); $p = new WP_Post(); $p->ID = 30; $p->post_type = 'post'; $p->post_name = 'article'; $p->post_status = 'publish'; $p->post_author = 8; $p->post_content = '<p>Original</p>'; $posts[30] = $p;
$plan = planned( 'core' ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
check( $plan['target_post_id'] === 30 && $inserts === 0 && $posts[30]->post_author === 8 && $posts[30]->post_name === 'article', 'An existing native Post keeps its ID, author and URL while adopting the saved layout.' );
reset_native(); $posts[30] = $p; $meta[30]['_elementor_edit_mode'] = 'builder'; [ $content, $config ] = fixture( 'core' );
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 1 ) ] ) ) && $saves === 0, 'A content-field provider cannot report success while another builder still owns rendering.' );
reset_native(); $denied = [ 'create_post' ]; check( ! Nova_Bridge_Suite_Stored_Layout_Provider::support( 'core', 'post' )['supported'], 'Native creation capabilities are enforced independently of destination names.' );
$denied = []; $editor_unavailable = true; check( ! Nova_Bridge_Suite_Stored_Layout_Provider::support( 'gutenberg', 'portfolio' )['supported'], 'A registered destination without content support gives an explicit unavailable reason.' );
$editor_unavailable = false; $unsupported = Nova_Bridge_Suite_Stored_Layout_Provider::support( 'divi5', 'post' ); check( ! $unsupported['supported'] && strpos( $unsupported['reason'], 'known-ID' ) !== false, 'Compile/draft support alone does not falsely advertise recoverable publication.' );
// An extension registers one complete native lifecycle, without editing destination or builder allowlists.
class Fixture_Publication_Adapter {
    private $inner;
    public function __construct() { $this->inner = new Nova_Bridge_Suite_Recipe_Core(); }
    public function validate_blueprint( $v ) { return $this->inner->validate_blueprint( $v ); }
    public function validate_binding( $v, $b ) { return $this->inner->validate_binding( $v, $b ); }
    public function validate_region( $v, $r ) { return $this->inner->validate_region( $v, $r ); }
    public function describe( $v ) { return $this->inner->describe( $v ); }
    public function instantiate( $v, $seed, $context = [] ) { return $this->inner->instantiate( $v, $seed, $context ); }
    public function bind( $v, $b, $value, $context = [] ) { return $this->inner->bind( $v, $b, $value, $context ); }
    public function replace_region( $v, $r, $children, $context = [] ) { return $this->inner->replace_region( $v, $r, $children, $context ); }
    public function finalize( $v, $seed, $context = [] ) { $r = $this->inner->finalize( $v, $seed, $context ); $r['writer_payload']['carrier_key'] = '_vendor_document'; return $r; }
    public function publication_support( $type ) { return [ 'supported' => true, 'reason' => '' ]; }
    public function publication_probe( $type, $payload, $status ) { return [ 'provider_version' => 'fixture-native-1' ]; }
    public function publication_snapshot( $id, $payload ) { return [ 'content' => get_post( $id )->post_content, 'document' => get_post_meta( $id, $payload['carrier_key'] ) ]; }
    public function publication_apply( $id, $payload, $fields ) { $r = Nova_Bridge_Suite_Stored_Layout_Provider::apply( 'core', $id, $payload, $fields ); if ( is_wp_error( $r ) ) { return $r; } update_post_meta( $id, $payload['carrier_key'], wp_slash( $payload['content'] ) ); return true; }
    public function publication_verify( $id, $payload ) { if ( ! empty( $GLOBALS['extension_bad_confirmation'] ) ) { return [ 'verified' => false ]; } $r = Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'core', $id, $payload ); return is_wp_error( $r ) ? $r : ( get_post_meta( $id, $payload['carrier_key'] ) === $payload['content'] ? true : new WP_Error( 'vendor_mismatch', 'Vendor carrier mismatch' ) ); }
}
Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'custom', new Fixture_Publication_Adapter() );
reset_native(); $plan = planned( 'custom', 'portfolio' ); $saved = ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $plan, op( 1 ) ) );
$new = planned( 'custom', 'portfolio', '502', 2 ); ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $new, op( 2 ) ) );
check( $saved['post_id'] === 10 && $new['target_post_id'] === 10 && $inserts === 1 && $saves === 2 && get_post_meta( 10, '_vendor_document' ) === '<p>Mapped 502</p>', 'A registered third-party adapter reuses its scoped readback and known ID on an arbitrary native destination.' );
$meta[10]['_vendor_document'] .= 'Manual edit'; [ $content, $config ] = fixture( 'custom', 'portfolio', '503' );
$replacement = ok( Nova_Bridge_Suite_Stored_Layout_Writer::plan( $content, $config, [ 'operation_id' => op( 3 ) ] ) );
check( strpos( $replacement['native_before']['native_document']['document'], 'Manual edit' ) !== false, 'The extension captures current carrier data as the newer delivery baseline.' );
ok( Nova_Bridge_Suite_Stored_Layout_Writer::apply( $replacement, op( 3 ) ) );
check( $inserts === 1 && $saves === 3 && get_post_meta( 10, '_vendor_document' ) === '<p>Mapped 503</p>', 'A newer authorized delivery replaces changed extension content on the retained native item.' );
$extension_bad_confirmation = true;
check( is_wp_error( Nova_Bridge_Suite_Stored_Layout_Provider::verify( 'custom', 10, $new['payload'] ) ), 'Extension verification requires an explicit true confirmation rather than a truthy result object.' );
echo "Generic stored-layout writer checks: $checks passed.\n";
