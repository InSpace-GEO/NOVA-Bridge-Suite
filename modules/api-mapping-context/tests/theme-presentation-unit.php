<?php
/** Generic snapshots; optional arguments execute official Kadence 1.2.16 and Astra 4.11.7 registration. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run standalone.' ); }
define( 'ABSPATH', __DIR__ );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error { private $message; public function __construct( $code, $message ) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_template() { if ( $GLOBALS['pure'] ) { throw new RuntimeException( 'Pure validation read theme.' ); } return $GLOBALS['template']; }
function get_post_type( $id ) { read_guard( $id ); return $GLOBALS['types'][$id] ?? 'page'; }
function get_registered_meta_keys( $kind, $subtype = '' ) { if ( $GLOBALS['pure'] ) { throw new RuntimeException( 'Pure validation read registry.' ); } return $GLOBALS['registrations'][$subtype] ?? []; }
function current_user_can( $cap, $id = 0, $key = '' ) { return $GLOBALS['can_edit'] && ! in_array( $key, $GLOBALS['denied'], true ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function read_guard( $id ) { if ( $GLOBALS['pure'] || ( $GLOBALS['source_deleted'] && 10 === $id ) ) { throw new RuntimeException( 'Unexpected source read.' ); } }
function metadata_exists( $kind, $id, $key ) { read_guard( $id ); return array_key_exists( $key, $GLOBALS['meta'][$id] ?? [] ); }
function get_post_meta( $id, $key, $single = false ) { read_guard( $id ); return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $value = wp_unslash( $value ); if ( $GLOBALS['failed_key'] !== $key ) { $GLOBALS['meta'][$id][$key] = is_scalar( $value ) ? (string) $value : $value; } return $GLOBALS['failed_key'] !== $key; }
function delete_post_meta( $id, $key ) { ++$GLOBALS['writes']; if ( $GLOBALS['failed_key'] !== $key ) { unset( $GLOBALS['meta'][$id][$key] ); } return $GLOBALS['failed_key'] !== $key; }
function sanitize_meta( $key, $value, $type, $subtype ) { return $GLOBALS['sanitize_key'] === $key ? 'changed-by-provider' : $value; }
// Optional provider source runs against this in-memory registry, never a WordPress/database bootstrap.
function register_post_meta( $subtype, $key, $args ) { $GLOBALS['registrations'][$subtype][$key] = $args; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function is_admin() { return false; }
function apply_filters( $hook, $value ) { return $value; }
function is_customize_preview() { return false; }
function astra_button_default_padding_updated() { return false; }
class Astra_Dynamic_CSS { public static function astra_4_8_9_compatibility() { return false; } }
class Astra_Global_Palette { public static function get_css_variable_prefix() { return '--ast-global-color-'; } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-theme-presentation.php';
use Nova_Bridge_Suite_Theme_Presentation as Theme;
$checks = 0; $writes = 0; $pure = false; $source_deleted = false; $template = 'astra'; $can_edit = true; $failed_key = null; $sanitize_key = null; $denied = []; $types = [];
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $message ); } }
function good( $result, $message ) { check( ! is_wp_error( $result ), $message . ( is_wp_error( $result ) ? ': ' . $result->get_error_message() : '' ) ); return $result; }
function registration( $type = 'string' ) { return [ 'show_in_rest' => true, 'single' => true, 'type' => $type, 'auth_callback' => '__return_true' ]; }

// Official Astra 4.11.7 inc/metabox/class-astra-meta-boxes.php:1089-1139,1210-1219,1321-1331.
// This test covers registered theme metadata, not every native Elementor hook. Astra's
// class-astra-elementor-editor-settings.php:793 re-saves remaining page settings without
// wp_slash, so a custom_css value containing literal backslashes can change during that
// provider hook. The document adapter must report its readback mismatch; do not conceal
// it by bypassing the provider lifecycle or blindly restoring all raw source metadata.
$astra_keys = [ 'site-content-layout', 'ast-site-content-layout', 'astra-migrate-meta-layouts', 'site-content-style', 'site-sidebar-layout', 'site-post-title', 'theme-transparent-header-meta', 'ast-banner-title-visibility' ];
$registrations = [ '' => array_fill_keys( $astra_keys, registration() ), 'page' => [] ];
if ( isset( $argv[2] ) ) {
    // Supply inc/metabox/class-astra-meta-boxes.php from https://downloads.wordpress.org/theme/astra.4.11.7.zip.
    // Execute the original class and method, with only theme-environment helpers stubbed;
    // unlike importing WordPress this cannot connect to a database or run registered hooks.
    $provider = realpath( $argv[2] );
    if ( ! $provider || hash_file( 'sha256', $provider ) !== '92c91d6cc57c592b4a363f9553059f9f217ffc44b8baa293f19ba05b071b7a7a' ) { throw new RuntimeException( 'Expected the unmodified official Astra 4.11.7 inc/metabox/class-astra-meta-boxes.php.' ); }
    $registrations = [ '' => [], 'page' => [] ]; $pagenow = 'post.php';
    require $provider;
    Astra_Meta_Boxes::get_instance()->register_meta_settings();
    check( 25 === count( $registrations[''] ), 'Official Astra registration executes and declares twenty-five global settings.' );
    check( isset( $registrations['']['ast-page-background-meta']['show_in_rest']['schema']['properties']['desktop'] ), 'Actual Astra structured background schema is registered.' );
}
foreach ( [ '_edit_lock', '_elementor_css', 'private_api_key', 'seo_title', 'source_id', 'business_reference' ] as $key ) { $registrations[''][$key] = registration(); }
$nonpresentation_keys = [ '_seopress_titles_title', '_seopress_titles_desc', '_seopress_redirections_enabled', '_seopress_redirections_value', '_seopress_pro_rich_snippets_type', '_acf_changed', '_genesis_title', '_genesis_description', 'footnotes', 'schema_type', 'redirections_enabled', 'og_title', 'twitter_description' ];
foreach ( $nonpresentation_keys as $key ) { $registrations[''][$key] = registration(); }
$registrations['']['_genesis_layout'] = registration();
$meta = [ 10 => [
    'ast-site-content-layout' => 'full-width-container', 'astra-migrate-meta-layouts' => 'set', 'site-content-style' => 'unboxed',
    'site-sidebar-layout' => 'no-sidebar', 'site-post-title' => 'disabled', 'theme-transparent-header-meta' => 'enabled', 'ast-banner-title-visibility' => '',
    '_elementor_css' => [ 'generated' => true ], '_edit_lock' => 'not-presentation', 'private_api_key' => 'never-copy', 'business_reference' => 'keep-only-if-selected',
    'not_registered_layout' => 'no-schema',
] ];
$inventory = good( Theme::candidates( 10 ), 'Discover globally registered Astra fields.' );
check( 0 === $writes && null === Theme::capture( 10 ) && null === Theme::select( $inventory, [] ), 'Discovery never selects or writes settings automatically.' );
check( isset( $inventory['fields']['ast-site-content-layout'], $inventory['fields']['site-post-title'] ), 'Global registration captures Astra legacy and modern controls.' );
check( ! isset( $inventory['fields']['private_api_key'], $inventory['fields']['_elementor_css'] ) && ! isset( $inventory['fields']['_edit_lock'] ) && ! isset( $inventory['fields']['seo_title'] ) && ! isset( $inventory['fields']['source_id'] ) && ! isset( $inventory['fields']['not_registered_layout'] ), 'Secrets, SEO, identity, cache and undeclared fields never enter candidates.' );
check( ! array_intersect( $nonpresentation_keys, array_keys( $inventory['fields'] ) ), 'SEOpress, redirections, structured SEO, editorial footnotes and ACF bookkeeping are not design candidates.' );
check( isset( $inventory['fields']['_genesis_layout'] ), 'Excluding Genesis SEO fields retains its independently registered layout setting.' );
$snapshot = good( Theme::select( $inventory, $astra_keys ), 'Explicit selection freezes captured values and schemas.' );
check( ! isset( $snapshot['meta']['business_reference'] ), 'A registered but unselected field is never copied.' );
check( $snapshot['meta']['site-content-layout']['present'] === false && $snapshot['meta']['ast-banner-title-visibility']['present'] === true && $snapshot['meta']['ast-banner-title-visibility']['value'] === '', 'Absent and explicitly empty remain distinct.' );
$pure = true; good( Theme::validate( $snapshot ), 'Stored validation is source-free and registry-free.' ); $pure = false; $source_deleted = true;
$meta[20] = [ 'site-content-layout' => 'boxed-container', 'ast-site-content-layout' => 'normal-width-container', 'site-content-style' => 'boxed', 'theme-transparent-header-meta' => 'disabled', '_elementor_data' => 'new filled document', 'business_reference' => 'target-only' ];
good( Theme::apply( 20, $snapshot ), 'Apply retained presentation after deleting the example.' );
check( $meta[20]['ast-site-content-layout'] === 'full-width-container' && $meta[20]['theme-transparent-header-meta'] === 'enabled' && $meta[20]['site-post-title'] === 'disabled', 'Full-width, overlay header and hidden native title survive.' );
check( ! isset( $meta[20]['site-content-layout'] ) && $meta[20]['astra-migrate-meta-layouts'] === 'set', 'Explicit absence removes a stale target legacy override.' );
check( $meta[20]['_elementor_data'] === 'new filled document' && $meta[20]['business_reference'] === 'target-only', 'Content and unselected metadata stay untouched.' );
good( Theme::verify( 20, $snapshot ), 'Readback verifies selected values.' );
$writes_before = $writes; good( Theme::apply( 20, $snapshot ), 'Repeated apply succeeds.' ); check( $writes_before === $writes, 'Repeated apply performs no writes.' );
$before = good( Theme::native_state( 20, $snapshot ), 'Read ownership state.' ); $meta[20]['site-content-style'] = 'boxed';
check( $before !== Theme::native_state( 20, $snapshot ) && is_wp_error( Theme::verify( 20, $snapshot ) ), 'Human layout changes are detectable.' );
$failed_key = 'site-content-style'; check( is_wp_error( Theme::apply( 20, $snapshot ) ), 'Failed writes fail readback.' ); $failed_key = null;
$can_edit = false; $writes_before = $writes; check( is_wp_error( Theme::apply( 20, $snapshot ) ) && $writes_before === $writes, 'No edit capability prevents every write.' ); $can_edit = true;
$denied = [ 'site-content-style' ]; check( is_wp_error( Theme::apply( 20, $snapshot ) ) && $writes_before === $writes, 'Metadata authorization is checked before all writes.' ); $denied = [];
$sanitize_key = 'site-content-style'; check( is_wp_error( Theme::apply( 20, $snapshot ) ) && $writes_before === $writes, 'Changed provider sanitization is detected before writes.' ); $sanitize_key = null;
$registrations['']['site-content-style']['type'] = 'boolean'; check( is_wp_error( Theme::assert_current( $snapshot ) ) && is_wp_error( Theme::apply( 20, $snapshot ) ) && $writes_before === $writes, 'A live schema change is rejected before mutation.' ); $registrations['']['site-content-style']['type'] = 'string';
$template = 'hello-elementor'; check( is_wp_error( Theme::assert_current( $snapshot ) ) && $writes_before === $writes, 'A different theme requires recapture.' );
$pure = true; good( Theme::validate( $snapshot ), 'Compilation remains independent of active theme.' ); $pure = false;
check( is_wp_error( Theme::select( $inventory, [ 'private_api_key' ] ) ) && is_wp_error( Theme::select( $inventory, [ 'site-post-title', 'site-post-title' ] ) ), 'Unknown and duplicate selections are rejected.' );

// Official Kadence 1.2.16 inc/meta/class-theme-meta.php:456-555 registers protected globals.
$template = 'kadence'; $registrations = [ '' => [], 'page' => [] ];
$kadence_selected = [ '_kad_post_layout', '_kad_post_content_style', '_kad_post_transparent', '_kad_post_header' ];
if ( isset( $argv[1] ) ) {
    // Supply inc/meta/class-theme-meta.php from https://downloads.wordpress.org/theme/kadence.1.2.16.zip.
    $provider = realpath( $argv[1] );
    if ( ! $provider || hash_file( 'sha256', $provider ) !== '21225a0edfc17246f54ecd93debf5282e69d1cede15d67b1a2fffff355f436aa' ) { throw new RuntimeException( 'Expected the unmodified official Kadence 1.2.16 inc/meta/class-theme-meta.php.' ); }
    require $provider;
    \Kadence\Theme_Meta::get_instance()->register_meta();
    check( 10 === count( $registrations[''] ), 'Official Kadence registration executes and declares ten global settings.' );
} else {
    foreach ( array_slice( $kadence_selected, 0, 3 ) as $key ) { $registrations[''][$key] = registration(); }
    $registrations['']['_kad_post_header'] = registration( 'boolean' );
}
$meta[30] = [ '_kad_post_layout' => 'fullwidth', '_kad_post_content_style' => 'unboxed', '_kad_post_transparent' => 'enable', '_kad_post_header' => '' ];
$kadence = good( Theme::capture( 30, $kadence_selected ), 'Capture another common theme without theme-specific code.' );
check( $kadence['meta']['_kad_post_header']['value'] === false, 'Registered boolean metadata normalizes WordPress empty-string storage.' );
$meta[31] = [ '_kad_post_header' => '1' ];
good( Theme::apply( 31, $kadence ), 'Apply protected registered Kadence layout fields.' );
check( $meta[31]['_kad_post_layout'] === 'fullwidth' && $meta[31]['_kad_post_header'] === '', 'Kadence width and header choice are retained.' );
good( Theme::verify( 31, $kadence ), 'Kadence readback respects boolean serialization.' );
$types[31] = 'post'; $writes_before = $writes; check( is_wp_error( Theme::apply( 31, $kadence ) ) && $writes_before === $writes, 'A selected page schema cannot write another post type.' ); unset( $types[31] );

// A custom theme can register structured design metadata; no theme-name list exists.
$template = 'client-child-parent'; $registrations = [ '' => [ 'layout' => registration() ], 'page' => [ 'layout' => [ 'single' => true, 'show_in_rest' => [ 'schema' => [ 'type' => 'object', 'properties' => [ 'width' => [ 'type' => 'integer' ], 'spacing' => [ 'type' => 'array', 'items' => [ 'type' => 'number' ] ], 'enabled' => [ 'type' => 'boolean' ] ], 'required' => [ 'width' ], 'additionalProperties' => false ] ] ] ] ];
$meta[40] = [ 'layout' => [ 'width' => 1200, 'spacing' => [ 10, 20.5 ], 'enabled' => true ] ];
$custom = good( Theme::capture( 40, [ 'layout' ] ), 'Subtype schema overrides global registration and captures declared structured values.' );
good( Theme::apply( 41, $custom ), 'Structured fixed design settings are applied source-free.' );
check( $meta[41]['layout'] === $meta[40]['layout'], 'Nested typed arrays survive persistence.' );
$meta[40]['layout']['private_api_key'] = 'excluded';
check( ! isset( good( Theme::candidates( 40 ), 'Discover bounded structured candidates.' )['fields']['layout'] ), 'Sensitive undeclared nested data is excluded.' );
$bad = $custom; $bad['meta']['layout']['value']['width'] = 'not-an-integer';
check( is_wp_error( Theme::validate( $bad ) ), 'Stored values must retain their schema shape.' );

// GeneratePress 3.5.1 uses these fields but does not register metadata schemas.
$template = 'generatepress'; $registrations = [ '' => [], 'page' => [] ]; $meta[50] = [ '_generate-full-width-content' => 'true', '_generate-sidebar-layout-meta' => 'no-sidebar' ];
check( good( Theme::candidates( 50 ), 'Undeclared provider settings can be inspected only via a provider adapter.' )['fields'] === [], 'Unregistered GeneratePress fields are not silently trusted or copied.' );
echo "Generic theme presentation: {$checks} assertions passed.\n";
