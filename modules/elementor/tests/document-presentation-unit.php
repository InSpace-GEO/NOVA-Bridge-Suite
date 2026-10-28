<?php
/** Isolated fake Elementor provider: no WordPress bootstrap or database. */
namespace Elementor {
    class Base_Data_Control {}
    class Plugin {
        public $documents; public $controls_manager; public $elements_manager; public $widgets_manager;
        public static function instance() { static $instance; if ( ! $instance ) { $instance = new self(); $instance->documents = new \Fake_Documents(); $instance->controls_manager = new \Fake_Controls(); $instance->elements_manager = new \Fake_Elements(); $instance->widgets_manager = new \Fake_Widgets(); } return $instance; }
    }
}
namespace {
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; private $code; public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = '' ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_post( $id ) { return isset( $GLOBALS['pages'][$id] ) ? (object) [ 'ID' => $id ] : null; }
function current_user_can( $cap, $id = 0 ) { return ! empty( $GLOBALS['can_edit'] ); }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $kind, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][$id] ?? [] ); }
function update_post_meta( $id, $key, $value ) { if ( $GLOBALS['reject_meta_write'] ?? false ) { return false; } $GLOBALS['meta'][$id][$key] = $value; return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); return true; }
function wp_slash( $value ) { return $value; }
function clean_post_cache( $id ) {}
function add_filter( $name, $callback, ...$rest ) { $GLOBALS['filters'][$name][] = $callback; }
function remove_filter( $name, $callback, ...$rest ) { $GLOBALS['filters'][$name] = array_values( array_filter( $GLOBALS['filters'][$name] ?? [], static function ( $item ) use ( $callback ) { return $item !== $callback; } ) ); }
function add_action( $name, $callback, ...$rest ) { add_filter( $name, $callback ); }
function remove_action( $name, $callback, ...$rest ) { remove_filter( $name, $callback ); }
class Fake_Controls { public function get_control( $type ) { return $type === 'data' ? new \Elementor\Base_Data_Control() : new \stdClass(); } public function delete_stack( $document ) { ++$GLOBALS['stack_resets']; } }
class Fake_Elements { public function get_element_types( $type = null ) { return (object) []; } }
class Fake_Widgets { public function get_widget_types( $type = null ) { return (object) []; } }
class Fake_Documents { public function get( $id, $fallback = true ) { return new Fake_Document( $id ); } }
class Fake_Document {
    private $id; public function __construct( $id ) { $this->id = $id; }
    public function get_controls() { if ( $GLOBALS['throw_controls'] ?? false ) { throw new \RuntimeException( 'Control failure' ); } if ( ( $GLOBALS['post']->ID ?? 0 ) !== $this->id ) { throw new \RuntimeException( 'Wrong global post context' ); } return $GLOBALS['controls'][$this->id]; }
    public function get_settings() { return $GLOBALS['pages'][$this->id]; }
    public function is_editable_by_current_user() { return true; }
    public function set_is_built_with_elementor( $value ) {}
    public function save( $data ) {
        ++$GLOBALS['saves']; $GLOBALS['save_input'] = $data;
        foreach ( $GLOBALS['filters']['elementor/document/save/data'] ?? [] as $callback ) { $data = $callback( $data, $this ); }
        if ( isset( $data['settings'] ) ) {
            // Simulate a provider which removes its settings from raw Elementor meta after saving.
            $GLOBALS['pages'][$this->id] = $data['settings'];
            $GLOBALS['meta'][$this->id]['_elementor_page_settings'] = [ 'layout_width' => $data['settings']['layout_width'] ];
            $GLOBALS['meta'][$this->id]['vendor_header'] = $data['settings']['header_visibility'];
        }
        $GLOBALS['meta'][$this->id]['_elementor_data'] = $data['elements'];
        foreach ( $GLOBALS['filters']['elementor/editor/after_save'] ?? [] as $callback ) { $callback( $this->id, $data['elements'] ); }
        return true;
    }
}
require_once dirname( __DIR__ ) . '/includes/class-element-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-document-presentation.php';
require_once dirname( __DIR__ ) . '/includes/class-elementor-service.php';
use SEOR_Elementor_Bridge\Document_Presentation;
$checks = 0;
function check( $value, $message ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new \RuntimeException( $message ); } }
function ok( $value ) { if ( is_wp_error( $value ) ) { throw new \RuntimeException( $value->get_error_message() ); } return $value; }
$can_edit = true; $throw_controls = false; $saves = 0; $stack_resets = 0; $filters = []; $meta = []; $post = (object) [ 'ID' => 777 ];
$controls = [ 99 => [], 10 => [] ];
foreach ( [ 'layout_width', 'header_visibility', 'post_title', 'post_status', 'template', 'post_featured_image', 'vendor_user_id', 'background_image', 'background_color', 'dynamic_control', 'section_label' ] as $key ) { $controls[99][$key] = $controls[10][$key] = [ 'type' => 'section_label' === $key ? 'heading' : 'data' ]; }
$pages = [ 99 => [ 'layout_width' => 'full-width', 'header_visibility' => 'hidden', 'post_title' => 'Source title', 'post_status' => 'publish', 'template' => 'source-template', 'post_featured_image' => [ 'id' => 42 ], 'vendor_user_id' => 12, 'background_image' => [ 'id' => 42, 'url' => 'https://example.test/image.jpg' ], 'background_color' => '', '__globals__' => [ 'background_color' => 'globals/colors?id=primary' ], 'dynamic_control' => 'dynamic', '__dynamic__' => [ 'dynamic_control' => '[elementor-tag]' ] ], 10 => [ 'layout_width' => 'default', 'header_visibility' => 'visible' ] ];
$captured = ok( Document_Presentation::capture( 99 ) );
check( $captured === [ 'layout_width' => 'full-width', 'header_visibility' => 'hidden', 'background_image' => [ 'id' => 42, 'url' => 'https://example.test/image.jpg' ], 'background_color' => '', '__globals__' => [ 'background_color' => 'globals/colors?id=primary' ] ] && $post->ID === 777, 'Capture retains registered media and global style values while excluding identity and restoring source context.' );
check( $stack_resets === 1, 'Capture invalidates the document control stack before reading provider defaults.' );
check( is_wp_error( Document_Presentation::validate( [ 'post_title' => 'danger' ] ) ) && is_wp_error( Document_Presentation::validate( [ 'background_color' => '', '__globals__' => [ 'post_title' => 'bad' ] ] ) ), 'Identity fields and invalid global references are rejected.' );
check( is_wp_error( Document_Presentation::assert_supported( 10, [ 'missing_control' => 'x' ] ) ), 'Missing provider controls fail before save.' );
$throw_controls = true; check( is_wp_error( Document_Presentation::capture( 99 ) ) && $post->ID === 777, 'An exception restores global post context.' ); $throw_controls = false;
$service = new \SEOR_Elementor_Bridge\Elementor_Service();
$persist = new \ReflectionMethod( $service, 'persist_elementor_document' );
$elements = [ [ 'id' => 'a', 'elType' => 'container', 'settings' => [], 'elements' => [] ] ];
$result = $persist->invoke( $service, 10, $elements, [ 'page_settings' => [ 'present' => true, 'value' => [ 'old' => 'original' ] ], 'document_settings' => $captured ] );
check( ! is_wp_error( $result ) && $saves === 1 && $save_input['settings'] === $captured && $post->ID === 777, 'The same native document save receives source-free presentation settings in the target context.' );
check( $meta[10]['_elementor_page_settings'] === [ 'layout_width' => 'full-width' ] && $meta[10]['vendor_header'] === 'hidden' && ! is_wp_error( Document_Presentation::verify( 10, $captured ) ), 'Provider after-save metadata changes remain in place while effective values verify.' );
$retained = [ 'astra_sites_main_color' => '#123456', 'legacy_font' => [ 'font_family' => 'Source Sans', 'font_size' => [ 'unit' => 'px', 'size' => 18 ] ], 'legacy_background' => '', '__globals__' => [ 'legacy_background' => 'globals/colors?id=secondary' ] ];
$meta[99]['_elementor_page_settings'] = $retained + [ '__dynamic__' => [], 'post_title' => 'Never copy identity' ];
$with_retained = ok( Document_Presentation::capture( 99 ) );
check( $with_retained[Document_Presentation::RETAINED] === $retained && ! isset( $with_retained['post_title'] ), 'Safe unregistered color, typography and global references retain their exact values without copying identity.' );
check( $captured === array_diff_key( $with_retained, [ Document_Presentation::RETAINED => true ] ), 'Retained settings remain separate from registered effective controls.' );
$before_retained = ok( Document_Presentation::read_state( 10, $with_retained ) );
check( ! array_key_exists( 'astra_sites_main_color', $before_retained[Document_Presentation::RETAINED] ) && is_wp_error( Document_Presentation::read_selected( 10, $with_retained ) ), 'A pre-save baseline records missing retained settings without weakening strict readback.' );
$meta[10]['_elementor_page_settings']['__dynamic__']['astra_sites_main_color'] = '[elementor-tag]';
check( is_wp_error( Document_Presentation::read_state( 10, $with_retained ) ), 'A missing literal with a dynamic binding is not treated as an absent setting.' );
unset( $meta[10]['_elementor_page_settings']['__dynamic__'] );
$meta[10]['_elementor_page_settings']['__globals__']['astra_sites_main_color'] = 'globals/colors?id=other';
check( is_wp_error( Document_Presentation::read_state( 10, $with_retained ) ), 'A missing literal with an active global binding is not treated as an absent setting.' );
unset( $meta[10]['_elementor_page_settings']['__globals__'] );
$native = Document_Presentation::native_settings( $with_retained );
check( ! isset( $native[Document_Presentation::RETAINED] ) && $native['astra_sites_main_color'] === '#123456' && $native['__globals__'] === [ 'background_color' => 'globals/colors?id=primary', 'legacy_background' => 'globals/colors?id=secondary' ], 'Native settings flatten both global-reference namespaces and remove the snapshot marker.' );
unset( $pages[99], $meta[99] );
$result = $persist->invoke( $service, 10, $elements, [ 'document_settings' => $with_retained ] );
check( ! is_wp_error( $result ) && $save_input['settings'] === $native, 'Delivery remains source-free and provides retained literals to native provider hooks.' );
check( $meta[10]['_elementor_page_settings']['astra_sites_main_color'] === '#123456' && $meta[10]['_elementor_page_settings']['legacy_font'] === $retained['legacy_font'] && $meta[10]['vendor_header'] === 'hidden' && ! isset( $meta[10]['_elementor_page_settings']['header_visibility'] ), 'Only retained settings are restored after hooks; provider-owned registered metadata is not overwritten.' );
check( ! is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Readback verifies the retained literals and references after native hooks remove their raw input.' );
check( ok( Document_Presentation::read_state( 10, $with_retained ) ) === ok( Document_Presentation::read_selected( 10, $with_retained ) ), 'Complete presentation baselines retain their previous shape for existing frozen journals.' );
unset( $meta[10]['_elementor_page_settings']['astra_sites_main_color'] );
check( is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Readback detects a missing retained setting.' );
ok( Document_Presentation::persist_retained( 10, $with_retained ) );
$meta[10]['_elementor_page_settings']['__dynamic__'] = [ 'astra_sites_main_color' => '[elementor-tag]' ];
check( is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Readback detects a dynamic binding attached to a retained literal.' );
unset( $meta[10]['_elementor_page_settings']['__dynamic__'] );
$pages[10]['__globals__']['layout_width'] = 'globals/custom?id=changed';
check( is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Readback detects a new global reference overriding a registered literal.' );
unset( $pages[10]['__globals__']['layout_width'] );
$meta[10]['_elementor_page_settings']['unrelated_later_value'] = 'keep';
check( ! is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Later unrelated raw settings do not affect the selected presentation digest.' );
$meta[10]['_elementor_page_settings']['astra_sites_main_color'] = '#abcdef';
check( is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'A retained literal change is detected by readback.' );
$reject_meta_write = true;
check( is_wp_error( Document_Presentation::persist_retained( 10, $with_retained ) ), 'A failed retained write is detected by actual metadata readback.' );
$reject_meta_write = false;
ok( Document_Presentation::persist_retained( 10, $with_retained ) );
check( $meta[10]['_elementor_page_settings']['unrelated_later_value'] === 'keep', 'Restoring selected retained settings preserves unrelated provider-owned raw values.' );
$meta[10]['_elementor_page_settings']['__globals__']['astra_sites_main_color'] = 'globals/colors?id=danger';
check( is_wp_error( Document_Presentation::verify( 10, $with_retained ) ), 'Readback detects a newly attached global reference overriding a retained literal.' );
ok( Document_Presentation::persist_retained( 10, $with_retained ) );
check( ! isset( $meta[10]['_elementor_page_settings']['__globals__']['astra_sites_main_color'] ), 'Restoring retained literals removes only conflicting references for those literals.' );
$controls[10]['astra_sites_main_color'] = [ 'type' => 'data' ]; $before_saves = $saves;
check( is_wp_error( $persist->invoke( $service, 10, $elements, [ 'document_settings' => $with_retained ] ) ) && $saves === $before_saves, 'A retained setting becoming registered requires recapture before native save.' );
unset( $controls[10]['astra_sites_main_color'], $controls[10]['header_visibility'] );
check( is_wp_error( $persist->invoke( $service, 10, $elements, [ 'document_settings' => $with_retained ] ) ) && $saves === $before_saves, 'A removed registered control still fails before saving and is never demoted to a literal.' );
$controls[10]['header_visibility'] = [ 'type' => 'data' ];
foreach ( [ [ 'post_title' => 'identity' ], [ 'api_token' => 'credential' ], [ 'legacy_value' => '[elementor-tag id="x"]' ], [ 'legacy_value' => [ '__dynamic__' => 'x' ] ], [ 'legacy_value' => str_repeat( 'x', 8193 ) ] ] as $bad ) {
    check( is_wp_error( Document_Presentation::validate( [ Document_Presentation::RETAINED => $bad ] ) ), 'Retained values preserve identity, dynamic and size restrictions.' );
}
check( is_wp_error( Document_Presentation::validate( [ 'layout_width' => 'full', Document_Presentation::RETAINED => [ 'layout_width' => 'narrow' ] ] ) ), 'Registered and retained namespaces cannot collide.' );
$pages[99] = $pages[10]; $meta[99]['_elementor_page_settings'] = [ '__dynamic__' => [ 'legacy_font' => '[elementor-tag]' ] ];
check( is_wp_error( Document_Presentation::capture( 99 ) ), 'Nonempty stored dynamic bindings still require review.' );
$meta[99]['_elementor_page_settings'] = [ 'legacy_font' => '[elementor-tag]' ];
check( is_wp_error( Document_Presentation::capture( 99 ) ), 'Unsafe unknown stored values cannot be retained.' );
unset( $meta[99]['_elementor_page_settings'] );
$pages[10]['layout_width'] = 'human-change';
check( is_wp_error( Document_Presentation::verify( 10, $captured ) ) && ok( Document_Presentation::read_selected( 10, $captured ) )['layout_width'] === 'human-change', 'Readback detects a later presentation edit.' );
$can_edit = false; check( is_wp_error( Document_Presentation::capture( 99 ) ) && $post->ID === 777, 'Insufficient permission fails without changing context.' );
echo "PASS $checks Elementor document presentation checks.\n";
}
