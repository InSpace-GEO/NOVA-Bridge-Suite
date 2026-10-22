<?php
/** Real sealed recipe compile and term transport; isolated native storage, no network. */
require __DIR__ . '/term-content-unit.php';
use Nova_Bridge_Suite_Recipe_Term_Fields as Fields;
use Nova_Bridge_Suite_Recipe_Engine as Engine;
use Nova_Bridge_Suite_Term_Content as Content;
$checks = 0; $registered = []; $denied = []; $admin = true; $term_sanitizers = []; $term_write_mutator = null;
$GLOBALS['wp_rest_additional_fields'] = []; $term_values[31] = [];
WCCBP_Content_Below_Products::register_term_meta(); WCCBP_Content_Below_Products::register_rest_field();
register_rest_field( 'product_cat', 'client_editorial', [
    'schema' => [ 'type' => 'string', 'context' => [ 'edit' ] ],
    'get_callback' => static function ( $object ) { return get_term_meta( $object['id'], 'client_editorial', true ); },
    'update_callback' => static function ( $value, $term ) { return update_term_meta( $term->term_id, 'client_editorial', wp_slash( $value ) ); },
] );
register_term_meta( 'product_cat', 'client_summary', [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ] );
$design = good( Nova_Bridge_Suite_Recipe_Core::design( $resource, 31, 'static' ), 'Capture actual WooCommerce and third-party registrations' );
$bindings = [];
foreach ( $design['suggested_bindings'] as $index => $binding ) { $bindings[] = $binding + [ 'id' => 'field_' . $index, 'format' => false !== strpos( $binding['source'], 'content' ) ? 'html' : 'text', 'required' => true ]; }
$recipe = [ 'format' => Engine::FORMAT, 'builder' => 'core', 'mode' => 'static', 'blueprint' => $design['blueprint'], 'bindings' => $bindings, 'regions' => [], 'instructions' => '' ];
$content = good( Engine::empty_content( good( Engine::content_schema( $recipe ), 'Real content schema' ) ), 'Typed content' );
foreach ( $bindings as $binding ) { Engine::put( $content, $binding['source'], 'html' === $binding['format'] ? '<p>Fresh delivery content.</p>' : 'Fresh delivery text' ); }
$record = [ 'id' => $profile_id, 'revision' => 1, 'resource_id' => $resource['id'], 'builder' => 'core', 'recipe' => $recipe, 'seal' => hash_hmac( 'sha256', Engine::json( [ 'core', $recipe['blueprint'] ] ), wp_salt( 'auth' ) ) ];
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1] = $record;
$full = good( Fields::for_delivery( static function () use ( $recipe, $content ) { return Engine::compile( $recipe, $content, 'delivery-test' ); } ), 'All providers compile normally' );
$written = good( Fields::for_delivery( static function () use ( $full, $context ) { return Content::apply( 'product_cat', 31, $full['writer_payload'], $context ); } ), 'Write through third-party and WooCommerce callbacks' );
check( isset( $written['content']['client_editorial'], $written['content']['content_below_products'], $written['content']['meta']['client_summary'] ), 'All registered provider fields survive readback' );
$before = $term_values[31]; $registrations = $GLOBALS['wp_rest_additional_fields']; $meta = $registered;
unset( $GLOBALS['wp_rest_additional_fields']['product_cat']['content_below_products'], $registered['term:product_cat']['content_below_products'], $registered['term:product_cat']['client_summary'] );
check( is_wp_error( Engine::validate( $recipe ) ), 'Editor validation remains strict outside delivery' );
$skips = [];
$partial = Fields::for_delivery( static function () use ( $recipe, $content, $context, $full ) {
    $compiled = good( Engine::compile( $recipe, $content, 'delivery-test' ), 'Missing fields no longer reject compilation' );
    check( ! isset( $compiled['writer_payload']['content_below_products'], $compiled['writer_payload']['meta']['client_summary'] ) && isset( $compiled['writer_payload']['client_editorial'], $compiled['writer_payload']['name'], $compiled['writer_payload']['description'] ), 'Only missing REST and meta fields omitted; available fields retained' );
    // A retained pre-loss plan also omits the missing fields before invoking setters.
    return Content::apply( 'product_cat', 31, $full['writer_payload'], $context );
}, static function ( $entry ) use ( &$skips ) { $skips[] = $entry; } );
good( $partial, 'Available fields publish successfully even from a retained full plan' );
check( count( $skips ) === 2 && array_column( $skips, 'reason' ) === [ 'field_not_registered', 'field_not_registered' ], 'Each omitted field produces one bounded diagnostic' );
check( $term_values[31]['content_below_products'] === $before['content_below_products'] && $term_values[31]['client_summary'] === $before['client_summary'], 'Missing provider fields are not written or cleared' );
check( is_wp_error( Engine::validate( $recipe ) ), 'Delivery scope restored after success' );
try { Fields::for_delivery( static function () { throw new RuntimeException( 'fixture' ); } ); } catch ( RuntimeException $expected ) {}
check( is_wp_error( Engine::validate( $recipe ) ), 'Delivery scope restored after exceptions' );
$GLOBALS['wp_rest_additional_fields'] = $registrations; $registered = $meta;
$GLOBALS['wp_rest_additional_fields']['product_cat']['client_editorial']['schema']['type'] = 'integer';
check( is_wp_error( Fields::for_delivery( static function () use ( $recipe ) { return Engine::validate( $recipe ); } ) ), 'Changed registered schemas still reject' );
$GLOBALS['wp_rest_additional_fields'] = $registrations;
$GLOBALS['wp_rest_additional_fields']['product_cat']['client_editorial']['update_callback'] = null;
check( is_wp_error( Fields::for_delivery( static function () use ( $recipe ) { return Engine::validate( $recipe ); } ) ), 'Registered field missing its setter is not silently skipped' );
$GLOBALS['wp_rest_additional_fields'] = $registrations; $denied = [ 'edit_term_meta:31:client_summary' ];
check( is_wp_error( Fields::for_delivery( static function () use ( $recipe ) { return Engine::validate( $recipe ); } ) ), 'Denied field permissions still reject' ); $denied = [];
$options['nova_bridge_layout_recipes_v1']['profiles'][$profile_id][1]['seal'] = 'forged';
check( is_wp_error( Fields::for_delivery( static function () use ( $full, $context ) { return Content::apply( 'product_cat', 31, $full['writer_payload'], $context ); } ) ), 'Tampered saved mapping still rejects' );
echo 'PASS ' . $checks . " delivery missing-field checks.\n";
