<?php
/** Standalone native settings persistence with WordPress's actual unslashing semantics. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run this isolated test outside WordPress.' ); }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { public function __construct( ...$args ) {} }
function __( $value, $domain = '' ) { return $value; }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function update_post_meta( $id, $key, $value ) { $GLOBALS['stored_meta'][$id][$key] = wp_unslash( $value ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['stored_meta'][$id][$key] ); return true; }
require dirname( __DIR__ ) . '/includes/class-elementor-service.php';
$service = new \SEOR_Elementor_Bridge\Elementor_Service();
$resolve = new ReflectionMethod( $service, 'resolve_page_settings_payload' );
$persist = new ReflectionMethod( $service, 'persist_elementor_page_settings' );
$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; };
$settings = [ 'custom_css' => 'selector::before { content: "\\f00c"; } selector::after { content: "\\\"quoted\\\""; }', 'nested' => [ 'css' => 'selector[data-key="a\\:b"] { display:block; }', 'opacity' => 0.75 ], 'empty' => '' ];
$assert( wp_unslash( $settings ) !== $settings, 'The fixture must expose the original metadata escaping loss.' );
foreach ( [ $settings, json_encode( $settings ) ] as $input ) {
    $payload = $resolve->invoke( $service, [ 'elementor_page_settings' => $input ] );
    $assert( $payload['value'] === $settings, 'Array and JSON settings must resolve to the same raw native values.' );
    $before = $payload;
    $assert( true === $persist->invoke( $service, 42, $payload ), 'Supported native settings must be persisted.' );
    $assert( $GLOBALS['stored_meta'][42]['_elementor_page_settings'] === $settings, 'CSS unicode escapes, escaped quotes, nested values and fractional styling must survive WordPress metadata storage.' );
    $assert( $payload === $before, 'Persistence must leave the raw settings unchanged for subsequent read-back verification.' );
}
$assert( true === $persist->invoke( $service, 42, [ 'present' => false ] ) && $GLOBALS['stored_meta'][42]['_elementor_page_settings'] === $settings, 'Omitted settings must preserve existing native styling.' );
$assert( true === $persist->invoke( $service, 42, [ 'present' => true, 'value' => null ] ) && ! isset( $GLOBALS['stored_meta'][42]['_elementor_page_settings'] ), 'An explicit null must still remove page settings.' );
echo 'PASS ' . $checks . " Elementor page-settings persistence checks.\n";
