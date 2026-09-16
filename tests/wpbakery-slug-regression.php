<?php
/** Staging-only REST regression: wp eval-file tests/wpbakery-slug-regression.php */
if ( ! defined( 'ABSPATH' ) || 'inspace-seo.com' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new Exception( 'This fixture is restricted to the authorized staging site.' );
}
if ( ! function_exists( 'nova_wpb_resolve_page' ) ) {
	require_once WP_PLUGIN_DIR . '/nova-bridge-suite/modules/wpbakery/wpbakery-bridge.php';
}
$slug_admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$slug_previous_user = get_current_user_id();
wp_set_current_user( $slug_admins[0]->ID );
$slug_ids = array();
$slug_checks = 0;
$slug_upload = null;
$slug_assert = function ( $ok, $message ) use ( &$slug_checks ) {
	if ( ! $ok ) {
		throw new Exception( $message );
	}
	++$slug_checks;
};
$slug_request = function ( $method, $route, $params = array() ) {
	$request = new WP_REST_Request( $method, '/nova-wpbakery/v1/pages' . $route );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
	}
	return rest_do_request( $request );
};
$slug_insert = function ( $args ) use ( &$slug_ids ) {
	$id = wp_insert_post( $args, true );
	if ( is_wp_error( $id ) ) {
		throw new Exception( $id->get_error_message() );
	}
	$slug_ids[] = $id;
	return $id;
};
try {
	$slug = 'nb5025-slug-' . strtolower( wp_generate_password( 10, false, false ) );
	$old = $slug_insert( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Old slug fixture', 'post_name' => $slug ) );
	wp_trash_post( $old );
	$slug_assert( 'trash' === get_post_status( $old ), 'Original fixture was not trashed' );
	$slug_upload = wp_upload_bits( $slug . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=' ) );
	$slug_assert( empty( $slug_upload['error'] ), 'Image upload failed' );
	$image = wp_insert_attachment( array( 'post_title' => $slug, 'post_name' => $slug, 'post_status' => 'inherit', 'post_mime_type' => 'image/png' ), $slug_upload['file'], 0, true );
	$slug_assert( ! is_wp_error( $image ), 'Attachment insert failed' );
	$slug_ids[] = $image;
	$legacy = get_page_by_path( $slug, OBJECT, 'page' );
	$slug_assert( $legacy && (int) $legacy->ID === $image, 'Fixture did not reproduce the original WordPress attachment collision' );
	foreach ( array( array(), array( '_fields' => 'id,link' ) ) as $projection ) {
		$response = $slug_request( 'GET', '', array_merge( array( 'post_type' => 'page', 'slug' => $slug ), $projection ) );
		$slug_assert( 200 === $response->get_status() && array() === $response->get_data(), 'Attachment-only lookup must return an empty list' );
	}
	$slug_assert( 404 === $slug_request( 'GET', '/' . $image )->get_status(), 'Attachment ID must not resolve as a page' );
	$slug_assert( 404 === $slug_request( 'GET', '/' . $old )->get_status(), 'Trashed page must not resolve' );
	$parent = $slug_insert( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_name' => $slug . '-parent', 'post_title' => 'Packages fixture' ) );
	$source = $slug_insert( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Source fixture', 'post_content' => '[vc_row][vc_column][vc_column_text]<p>Template body</p>[/vc_column_text][/vc_column][/vc_row]' ) );
	$created = $slug_request( 'POST', '', array( 'title' => 'Collision regression', 'slug' => $slug, 'post_type' => 'page', 'status' => 'draft', 'parent' => $parent, 'source_page_id' => $source, 'template' => (string) $source, 'append_sections' => array( array( 'title' => 'Article', 'body' => '<p>Collision article preserved.</p>', 'type' => 'text' ) ) ) );
	$slug_assert( 201 === $created->get_status(), 'Create failed: ' . wp_json_encode( $created->get_data() ) );
	$page = $created->get_data()['id'];
	$slug_ids[] = $page;
	$slug_assert( (int) get_post_field( 'post_parent', $page ) === $parent, 'Create lost the resolved parent' );
	$slug_assert( get_post_field( 'post_name', $page ) === $slug, 'Create changed the requested slug' );
	$slug_assert( false !== strpos( get_post_field( 'post_content', $page ), 'Collision article preserved.' ), 'Create lost article content' );
	foreach ( array( $slug, $slug . '-parent/' . $slug ) as $path ) {
		$found = $slug_request( 'GET', '', array( 'post_type' => 'page', 'slug' => $path ) );
		$items = $found->get_data();
		$slug_assert( 200 === $found->get_status() && 1 === count( $items ) && (int) $items[0]['id'] === $page && 'page' === $items[0]['post_type'], 'Page lookup returned the wrong object' );
	}
	$slug_assert( 200 === $slug_request( 'GET', '/' . $page )->get_status(), 'Existing page outline failed' );
	$post = $slug_insert( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_name' => $slug, 'post_title' => 'Same slug post fixture' ) );
	$posts = $slug_request( 'GET', '', array( 'post_type' => 'post', 'slug' => $slug ) )->get_data();
	$slug_assert( 1 === count( $posts ) && (int) $posts[0]['id'] === $post, 'Post lookup selected the wrong type' );
	register_post_type( 'nb5025_fixture', array( 'public' => false, 'show_in_rest' => true ) );
	$cpt = $slug_insert( array( 'post_type' => 'nb5025_fixture', 'post_status' => 'draft', 'post_name' => $slug, 'post_title' => 'Same slug CPT fixture' ) );
	$cpts = $slug_request( 'GET', '', array( 'post_type' => 'nb5025_fixture', 'slug' => $slug ) )->get_data();
	$slug_assert( 1 === count( $cpts ) && (int) $cpts[0]['id'] === $cpt, 'CPT lookup selected the wrong type' );
	wp_trash_post( $page );
	$empty = $slug_request( 'GET', '', array( 'post_type' => 'page', 'slug' => $slug ) )->get_data();
	$slug_assert( array() === $empty, 'Trashed page lookup fell back to attachment or another type' );
	echo 'SLUG_COLLISION_PASS ' . wp_json_encode( array( 'checks' => $slug_checks, 'fixtures' => count( $slug_ids ) ) ) . PHP_EOL;
} finally {
	foreach ( array_reverse( $slug_ids ) as $id ) {
		if ( 'attachment' === get_post_type( $id ) ) {
			wp_delete_attachment( $id, true );
		} else {
			wp_delete_post( $id, true );
		}
	}
	if ( $slug_upload && empty( $slug_upload['error'] ) && file_exists( $slug_upload['file'] ) ) {
		wp_delete_file( $slug_upload['file'] );
	}
	unregister_post_type( 'nb5025_fixture' );
	wp_set_current_user( $slug_previous_user );
}
