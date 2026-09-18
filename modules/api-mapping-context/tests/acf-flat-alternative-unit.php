<?php
define( 'ABSPATH', __DIR__ );
$wp_rest_additional_fields = [ 'page' => [ 'meta_all' => [ 'update_callback' => 'cf_tmrb_update_post_meta_all_payload' ] ] ];
function cf_tmrb_update_post_meta_all_payload() {}
function get_post_type_object( $type ) { return (object) [ 'show_in_rest' => true, 'rest_namespace' => 'wp/v2', 'rest_base' => 'pages' ]; }
function current_user_can( ...$args ) { return true; }
function get_post_meta( $id, $name = null, $single = false ) { $meta = [ 'matrix_0_heading' => [ 'Existing' ], '_matrix_0_heading' => [ 'field_heading' ], 'matrix_1_heading' => [ 'Duplicate', 'Duplicate' ], '_matrix_1_heading' => [ 'field_heading' ], 'unknown' => [ 'No reference' ] ]; return null === $name ? $meta : ( $single ? ( $meta[ $name ][0] ?? '' ) : ( $meta[ $name ] ?? [] ) ); }
function acf_get_field( $key ) { return 'field_heading' === $key ? [ 'key' => $key, 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ] : false; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
final class Nova_Bridge_Suite_Content_Transport { public static function safe_key( $key ) { return 0 !== strpos( $key, '_' ); } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-strategy.php';
$fields = [ '/meta_all/acf/matrix/0/heading' => [ 'transport' => 'nova_content_bridge', 'acf_key' => 'field_heading', 'write_mode' => 'complete_parent' ] ];
$method = new ReflectionMethod( Nova_Bridge_Suite_Strategy::class, 'flat_acf_fields' );
$method->invokeArgs( null, [ &$fields, [ 'reference_id' => 7, 'post_type' => 'page' ] ] );
if ( 'existing_leaf' !== ( $fields['/meta_all/matrix_0_heading']['write_mode'] ?? '' ) || 'complete_parent' !== $fields['/meta_all/acf/matrix/0/heading']['write_mode'] || isset( $fields['/meta_all/matrix_1_heading'] ) || isset( $fields['/meta_all/unknown'] ) ) { throw new RuntimeException( 'Exact existing scalar alternative regression.' ); }
echo "PASS ACF scalar alternative remains discoverable beside complete-parent legacy mapping\n";
