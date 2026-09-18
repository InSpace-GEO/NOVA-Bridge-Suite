<?php
/** Real core count checks; every simulated publication is rolled back before commit. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
global $wpdb;
$post_id = 0; $term_id = 0; $in_transaction = false;
$taxonomy_name = 'novact_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$checks = 0;
$assert = static function ( $ok, $message ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$checks; };
try {
    register_taxonomy( $taxonomy_name, [ 'page' ], [ 'public' => false ] );
    $term = wp_insert_term( 'NOVA private count fixture', $taxonomy_name );
    $assert( ! is_wp_error( $term ), 'Temporary core taxonomy term created.' ); $term_id = (int) $term['term_id']; $tt_id = (int) $term['term_taxonomy_id'];
    $post_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA private taxonomy count fixture' ], true );
    $assert( ! is_wp_error( $post_id ), 'Temporary unpublished page created.' ); $post_id = (int) $post_id;
    wp_set_post_terms( $post_id, [ $term_id ], $taxonomy_name );
    $snapshot = [ 'post' => [ 'ID' => (string) $post_id, 'post_type' => 'page', 'post_status' => 'draft' ], 'terms' => [ [ 'object_id' => (string) $post_id, 'term_taxonomy_id' => (string) $tt_id, 'term_order' => '0' ] ] ];
    $count = static function () use ( $wpdb, $tt_id ) { return (int) $wpdb->get_var( $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d", $tt_id ) ); };
    $plan = Nova_Bridge_Suite_Taxonomy_Counts::plan( $snapshot, 'update', 'publish' );
    $assert( ! is_wp_error( $plan ) && $plan['terms'][0]['callback'] === '_update_post_term_count', 'Core post count policy verified from actual registered taxonomy.' );
    $wpdb->query( 'START TRANSACTION' ); $in_transaction = true;
    $published = $snapshot; $published['post']['post_status'] = 'publish';
    $wpdb->update( $wpdb->posts, [ 'post_status' => 'publish' ], [ 'ID' => $post_id ] );
    $assert( true === Nova_Bridge_Suite_Taxonomy_Counts::apply( $plan, $snapshot, $published ) && 1 === $count(), 'Publication adds one core count inside the native transaction.' );
    $draft_plan = Nova_Bridge_Suite_Taxonomy_Counts::plan( $published, 'update', 'draft' );
    $wpdb->update( $wpdb->posts, [ 'post_status' => 'draft' ], [ 'ID' => $post_id ] );
    $assert( true === Nova_Bridge_Suite_Taxonomy_Counts::apply( $draft_plan, $published, $snapshot ) && 0 === $count(), 'Draft transition removes one count in the same transaction.' );
    $wpdb->query( 'ROLLBACK' ); $in_transaction = false;
    $assert( 'draft' === $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID=%d", $post_id ) ) && 0 === $count(), 'No published state or count change was committed.' );
    $clone = Nova_Bridge_Suite_Taxonomy_Counts::plan( $snapshot, 'clone', 'draft' );
    $wpdb->query( 'START TRANSACTION' ); $in_transaction = true;
    $assert( true === Nova_Bridge_Suite_Taxonomy_Counts::apply( $clone, null, $snapshot ) && 0 === $count(), 'Unpublished clone does not increment a publish-only count.' );
    $wpdb->query( 'ROLLBACK' ); $in_transaction = false;
    $taxonomy = get_taxonomy( $taxonomy_name ); $taxonomy->update_count_callback = '_update_generic_term_count';
    $generic = Nova_Bridge_Suite_Taxonomy_Counts::plan( $snapshot, 'clone', 'draft' );
    $wpdb->query( 'START TRANSACTION' ); $in_transaction = true;
    $assert( true === Nova_Bridge_Suite_Taxonomy_Counts::apply( $generic, null, $snapshot ) && 1 === $count(), 'Generic core counts include an unpublished clone exactly once per writer transaction.' );
    $wpdb->query( 'ROLLBACK' ); $in_transaction = false;
    $taxonomy->update_count_callback = static function () {};
    $assert( is_wp_error( Nova_Bridge_Suite_Taxonomy_Counts::plan( $snapshot, 'clone', 'draft' ) ), 'Unknown custom taxonomy callback blocks before mutation.' );
    $taxonomy->update_count_callback = '';
    echo "PASS {$checks} core taxonomy checks; simulated publications rolled back.\n";
} finally {
    if ( $in_transaction ) { $wpdb->query( 'ROLLBACK' ); }
    if ( is_int( $post_id ) && $post_id > 0 ) { wp_delete_post( $post_id, true ); }
    if ( $term_id ) { wp_delete_term( $term_id, $taxonomy_name ); }
    unregister_taxonomy( $taxonomy_name );
}
