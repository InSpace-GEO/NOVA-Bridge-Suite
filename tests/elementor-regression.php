<?php
/**
 * Run with: wp eval-file tests/elementor-regression.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

function nova_elementor_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$marker        = 'nova-elementor-regression-' . wp_generate_uuid4();
$created_ids   = array();
$filter_active = false;
$publish_before_elementor = false;
$native_before_saves      = 0;
$native_editor_saves      = 0;
$native_after_saves       = 0;
$last_saving_document     = null;
$canonical_target_id      = 0;
$canonical_filter_active  = false;
$admins        = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

nova_elementor_test_assert( ! empty( $admins ), 'No administrator is available for the regression check.' );
wp_set_current_user( (int) $admins[0] );

$block_elementor_meta = static function ( $check, $object_id, $meta_key ) {
	if ( '_elementor_data' === $meta_key ) {
		return false;
	}

	return $check;
};

$observe_publish = static function ( $new_status, $old_status, $post ) use ( $marker, &$publish_before_elementor ) {
	if ( 'publish' === $new_status && 0 === strpos( $post->post_title, $marker ) && ! metadata_exists( 'post', $post->ID, '_elementor_data' ) ) {
		$publish_before_elementor = true;
	}
};

$is_marker_document = static function ( $document ) use ( $marker ) {
	if ( ! is_object( $document ) || ! method_exists( $document, 'get_post' ) ) {
		return false;
	}

	$post = $document->get_post();
	return $post instanceof WP_Post && 0 === strpos( $post->post_title, $marker );
};

$observe_document_before_save = static function ( $document ) use ( $is_marker_document, &$native_before_saves, &$last_saving_document ) {
	if ( $is_marker_document( $document ) ) {
		$native_before_saves++;
		$last_saving_document = $document;
	}
};

$observe_editor_after_save = static function ( $post_id ) use ( $marker, &$native_editor_saves ) {
	$post = get_post( $post_id );
	if ( $post instanceof WP_Post && 0 === strpos( $post->post_title, $marker ) ) {
		$native_editor_saves++;
	}
};

$observe_document_after_save = static function ( $document ) use ( $is_marker_document, &$native_after_saves ) {
	if ( $is_marker_document( $document ) ) {
		$native_after_saves++;
	}
};

$canonicalize_heading = static function ( $data, $document ) use ( &$canonical_target_id ) {
	if (
		(int) $canonical_target_id === (int) $document->get_post()->ID
		&& isset( $data['elements'][0]['settings']['title'] )
	) {
		if ( 'nova-legacy-widget-fixture' === $data['elements'][0]['widgetType'] ) {
			$data['elements'][0]['widgetType'] = 'heading';
		}
		$data['elements'][0]['settings']['title'] .= ' [canonical]';
	}

	return $data;
};

add_action( 'transition_post_status', $observe_publish, PHP_INT_MAX, 3 );
add_action( 'elementor/document/before_save', $observe_document_before_save, 10, 1 );
add_action( 'elementor/editor/after_save', $observe_editor_after_save, 10, 1 );
add_action( 'elementor/document/after_save', $observe_document_after_save, 10, 1 );

try {
	nova_elementor_test_assert( defined( 'NOVA_BRIDGE_SUITE_VERSION' ), 'NOVA Bridge Suite is not loaded.' );
	nova_elementor_test_assert( class_exists( '\\SEOR_Elementor_Bridge\\Elementor_Service' ), 'Enable Elementor and the Elementor bridge before running this check.' );

	$service         = new \SEOR_Elementor_Bridge\Elementor_Service();
	$emoji           = json_decode( '"\\ud83d\\udcb0"' );
	$large_body      = str_repeat( '<p>Elementor regression body 0123456789.</p>', 5000 ) . '<p>' . $emoji . '</p>';
	$source_document = array(
		array(
			'id'         => 'a1b2c3d4',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array(
				'title'       => 'Original heading',
				'header_size' => 'h2',
			),
			'elements'   => array(),
		),
		array(
			'id'         => 'e5f6a7b8',
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array( 'editor' => $large_body ),
			'elements'   => array(),
		),
	);
	nova_elementor_test_assert( strlen( wp_json_encode( $source_document ) ) > 180000, 'The large-document regression fixture is too small.' );
	$source_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $marker . '-source',
		),
		true
	);

	nova_elementor_test_assert( ! is_wp_error( $source_id ), 'Could not create the Elementor source page.' );
	$created_ids[] = (int) $source_id;
	update_post_meta( $source_id, '_elementor_data', wp_slash( wp_json_encode( $source_document ) ) );
	update_post_meta( $source_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $source_id, '_elementor_template_type', 'page' );

	$success_slug = sanitize_title( $marker . '-success' );
	$success_id   = $service->create_page(
		array(
			'title'          => $marker . '-success',
			'slug'           => $success_slug,
			'status'         => 'publish',
			'post_type'      => 'page',
			'template'       => 'elementor_header_footer',
			'source_page_id' => (int) $source_id,
			'fields'         => array(
				array(
					'field_key' => 'a1b2c3d4|title',
					'value'     => 'Updated heading',
				),
			),
		)
	);

	nova_elementor_test_assert( is_int( $success_id ) && $success_id > 0, 'A valid Elementor create request failed.' );
	$created_ids[] = $success_id;
	nova_elementor_test_assert( 'publish' === get_post_status( $success_id ), 'The requested publish status was not applied.' );
	nova_elementor_test_assert( ! $publish_before_elementor, 'The page was published before its Elementor document existed.' );
	nova_elementor_test_assert( 'elementor_header_footer' === get_post_meta( $success_id, '_wp_page_template', true ), 'The Elementor page template was not preserved.' );

	$saved_document = $service->get_elementor_document_data( $success_id );
	nova_elementor_test_assert( is_array( $saved_document ), 'The saved Elementor document could not be reloaded.' );
	nova_elementor_test_assert( 'Updated heading' === $saved_document[0]['settings']['title'], 'The requested Elementor field mutation was not persisted.' );
	nova_elementor_test_assert( $large_body === $saved_document[1]['settings']['editor'], 'The large Elementor body was not preserved exactly.' );
	nova_elementor_test_assert( $native_before_saves > 0, 'The Elementor before-save lifecycle did not run.' );
	nova_elementor_test_assert( $native_editor_saves > 0, 'The Elementor editor save lifecycle did not run.' );
	nova_elementor_test_assert( $native_after_saves > 0, 'The Elementor after-save lifecycle did not run.' );
	nova_elementor_test_assert( 'builder' === get_post_meta( $success_id, '_elementor_edit_mode', true ), 'The page was not marked as built with Elementor.' );
	nova_elementor_test_assert( 'wp-page' === get_post_meta( $success_id, '_elementor_template_type', true ), 'Elementor did not persist its canonical page document type.' );
	nova_elementor_test_assert( ELEMENTOR_VERSION === get_post_meta( $success_id, '_elementor_version', true ), 'Elementor did not persist its current version.' );
	$saved_json = get_post_meta( $success_id, '_elementor_data', true );
	nova_elementor_test_assert( false !== strpos( $saved_json, '\\ud83d\\udcb0' ), 'Supplementary Unicode was not persisted as a database-safe JSON escape.' );
	nova_elementor_test_assert( false === strpos( $saved_json, $emoji ), 'Raw four-byte Unicode was written to Elementor metadata.' );

	$canonical_target_id     = $success_id;
	$canonical_filter_active = true;
	add_filter( 'elementor/document/save/data', $canonicalize_heading, PHP_INT_MAX, 2 );
	$legacy_document                              = $saved_document;
	$legacy_document[0]['widgetType']              = 'nova-legacy-widget-fixture';
	$legacy_document[0]['settings']['title']       = 'Canonical heading';
	$canonical_update = $service->update_page( $success_id, array( 'elementor_data' => $legacy_document ) );
	remove_filter( 'elementor/document/save/data', $canonicalize_heading, PHP_INT_MAX );
	$canonical_filter_active = false;
	nova_elementor_test_assert( ! is_wp_error( $canonical_update ), 'A save-data widget migration was rejected before Elementor could canonicalize it.' );
	$canonical_document = $service->get_elementor_document_data( $success_id );
	nova_elementor_test_assert( 'Canonical heading [canonical]' === $canonical_document[0]['settings']['title'], 'Elementor canonical save output was not persisted.' );

	$unsupported_document   = $canonical_document;
	$unsupported_document[] = array(
		'id'         => 'deadbeef',
		'elType'     => 'widget',
		'widgetType' => 'nova-unregistered-widget-fixture',
		'settings'   => array( 'title' => 'Must not be dropped silently' ),
		'elements'   => array(),
	);
	$numeric_locale_before  = setlocale( LC_NUMERIC, 0 );
	$last_saving_document   = null;
	$unsupported_update     = $service->update_page( $success_id, array( 'elementor_data' => $unsupported_document ) );
	nova_elementor_test_assert( is_wp_error( $unsupported_update ), 'An unregistered Elementor widget was silently dropped.' );
	nova_elementor_test_assert( 'seor_eb_elementor_meta_write_failed' === $unsupported_update->get_error_code(), 'The unregistered widget returned the wrong error.' );
	nova_elementor_test_assert( 'elementor_element_unavailable' === $unsupported_update->get_error_data()['reason'], 'The unregistered widget returned the wrong failure reason.' );
	$after_unsupported_update = $service->get_elementor_document_data( $success_id );
	nova_elementor_test_assert( 'Canonical heading [canonical]' === $after_unsupported_update[0]['settings']['title'], 'The rejected widget update changed the existing document.' );
	nova_elementor_test_assert( $numeric_locale_before === setlocale( LC_NUMERIC, 0 ), 'The rejected widget update changed the process numeric locale.' );
	nova_elementor_test_assert( is_object( $last_saving_document ), 'The rejected widget update did not complete Elementor\'s save lifecycle.' );
	nova_elementor_test_assert( false === has_filter( 'wp_save_post_revision_post_has_changed', array( $last_saving_document, 'handle_revisions_changed' ) ), 'The rejected widget update left Elementor\'s revision filter installed.' );
	if ( is_callable( array( $last_saving_document, 'is_saving' ) ) ) {
		nova_elementor_test_assert( ! $last_saving_document->is_saving(), 'The rejected widget update left Elementor in saving state.' );
	}

	$plain_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'draft',
			'post_title'  => $marker . '-plain-page',
		),
		true
	);
	nova_elementor_test_assert( ! is_wp_error( $plain_id ), 'Could not create the plain-page runtime-meta fixture.' );
	$created_ids[] = (int) $plain_id;

	add_filter( 'update_post_metadata', $block_elementor_meta, PHP_INT_MAX, 3 );
	$filter_active = true;
	$plain_failure = $service->update_page( $plain_id, array( 'elementor_data' => $source_document ) );
	nova_elementor_test_assert( is_wp_error( $plain_failure ), 'A rejected plain-page Elementor update reported success.' );
	nova_elementor_test_assert( 'missing_meta' === $plain_failure->get_error_data()['reason'], 'The rejected plain-page update returned the wrong failure reason.' );
	foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version' ) as $runtime_meta_key ) {
		nova_elementor_test_assert( ! metadata_exists( 'post', $plain_id, $runtime_meta_key ), 'A rejected plain-page update left Elementor runtime metadata behind.' );
	}

	$update_failure = $service->update_page(
		$success_id,
		array(
			'fields' => array(
				array(
					'field_key' => 'a1b2c3d4|title',
					'value'     => 'Rejected heading',
				),
			),
		)
	);
	nova_elementor_test_assert( is_wp_error( $update_failure ), 'A rejected Elementor update reported success.' );
	nova_elementor_test_assert( 'seor_eb_elementor_meta_write_failed' === $update_failure->get_error_code(), 'The rejected update returned the wrong error.' );
	nova_elementor_test_assert( 'write_mismatch' === $update_failure->get_error_data()['reason'], 'The rejected update did not identify the write mismatch.' );

	$failure_slug  = sanitize_title( $marker . '-failure' );
	$failure       = $service->create_page(
		array(
			'title'          => $marker . '-failure',
			'slug'           => $failure_slug,
			'status'         => 'publish',
			'post_type'      => 'page',
			'elementor_data' => $source_document,
		)
	);
	remove_filter( 'update_post_metadata', $block_elementor_meta, PHP_INT_MAX );
	$filter_active = false;

	nova_elementor_test_assert( is_wp_error( $failure ), 'A rejected Elementor metadata write reported success.' );
	nova_elementor_test_assert( 'seor_eb_elementor_meta_write_failed' === $failure->get_error_code(), 'The rejected write returned the wrong error.' );
	nova_elementor_test_assert( 500 === (int) $failure->get_error_data()['status'], 'The rejected write did not return HTTP 500 error data.' );
	nova_elementor_test_assert( 'missing_meta' === $failure->get_error_data()['reason'], 'The rejected create did not identify the missing metadata.' );
	nova_elementor_test_assert( null === get_page_by_path( $failure_slug, OBJECT, 'page' ), 'The failed create left an orphaned page.' );
	nova_elementor_test_assert( ! $publish_before_elementor, 'A failed create reached publish without Elementor data.' );

	$unchanged_document = $service->get_elementor_document_data( $success_id );
	nova_elementor_test_assert( 'Canonical heading [canonical]' === $unchanged_document[0]['settings']['title'], 'The rejected update changed the existing Elementor page.' );

	$raw_unicode_json = wp_json_encode( $source_document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	nova_elementor_test_assert( false !== strpos( $raw_unicode_json, $emoji ), 'The direct replacement fixture does not contain raw four-byte Unicode.' );
	$direct_update = $service->update_page( $success_id, array( 'elementor_data' => $raw_unicode_json ) );
	nova_elementor_test_assert( ! is_wp_error( $direct_update ), 'A direct Elementor document replacement with supplementary Unicode failed.' );
	$direct_saved_json = get_post_meta( $success_id, '_elementor_data', true );
	nova_elementor_test_assert( false !== strpos( $direct_saved_json, '\\ud83d\\udcb0' ), 'Direct replacement did not persist supplementary Unicode as a database-safe JSON escape.' );
	nova_elementor_test_assert( false === strpos( $direct_saved_json, $emoji ), 'Direct replacement wrote raw four-byte Unicode to Elementor metadata.' );
	$direct_saved_document = $service->get_elementor_document_data( $success_id );
	nova_elementor_test_assert( $large_body === $direct_saved_document[1]['settings']['editor'], 'Direct replacement changed the decoded Elementor content.' );

	WP_CLI::success( 'Elementor persistence regression checks passed.' );
} finally {
	remove_action( 'transition_post_status', $observe_publish, PHP_INT_MAX );
	remove_action( 'elementor/document/before_save', $observe_document_before_save, 10 );
	remove_action( 'elementor/editor/after_save', $observe_editor_after_save, 10 );
	remove_action( 'elementor/document/after_save', $observe_document_after_save, 10 );

	if ( $canonical_filter_active ) {
		remove_filter( 'elementor/document/save/data', $canonicalize_heading, PHP_INT_MAX );
	}

	if ( $filter_active ) {
		remove_filter( 'update_post_metadata', $block_elementor_meta, PHP_INT_MAX );
	}

	foreach ( array_reverse( $created_ids ) as $created_id ) {
		wp_delete_post( $created_id, true );
	}
}
