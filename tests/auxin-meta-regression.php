<?php
/**
 * Run with: wp eval-file tests/auxin-meta-regression.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

function nova_auxin_meta_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);

nova_auxin_meta_test_assert( ! empty( $admins ), 'No administrator is available for the regression check.' );
wp_set_current_user( (int) $admins[0] );

$marker  = 'nova-auxin-meta-regression-' . wp_generate_uuid4();
$post_id = 0;
$meta    = array(
	'auxin-autop'                            => 'no',
	'aux_page_template_content_location'     => 'above-in-frame',
	'aux_show_topheader'                     => 'no',
	'aux_topheader_layout'                   => 'default',
	'aux_show_topheader_message'             => 'default',
	'aux_show_topheader_secondary_message'   => 'default',
	'aux_use_custom_logo'                    => '1',
	'aux_title_bar_show'                     => 'default',
	'aux_title_bar_preset'                   => 'default',
	'aux_title_bar_enable_customize'         => '0',
	'aux_title_bar_content_width_type'       => 'boxed',
	'aux_title_bar_content_section_height'   => 'auto',
	'aux_title_bar_title_show'               => '1',
	'aux_title_bar_heading_bordered'          => '0',
	'aux_title_bar_heading_boxed'             => '0',
	'aux_title_bar_meta_enabled'              => '0',
	'aux_title_bar_bread_enabled'             => '1',
	'aux_title_bar_bread_bordered'            => '0',
	'aux_title_bar_text_align'                => 'left',
	'aux_title_bar_overlay_pattern'           => 'none',
	'aux_title_bar_overlay_pattern_opacity'   => '0.15',
	'aux_title_bar_color_style'               => 'dark',
	'aux_title_bar_bg_show'                   => '0',
	'aux_title_bar_bg_parallax'               => '0',
	'aux_title_bar_bg_size'                   => 'cover',
	'aux_custom_bg_show'                      => '0',
	'aux_custom_bg_repeat'                    => 'repeat',
	'aux_custom_bg_attach'                    => 'scroll',
	'aux_custom_bg_position'                  => 'left top',
	'aux_custom_bg_size'                      => 'auto',
	'aux_metafields_custom_styles'            => '.site-header-section .aux-header-elements:not(.aux-vertical-menu-elements), .site-header-section .aux-fill .aux-menu-depth-0 > .aux-item-content { height:90px; }',
);

try {
	nova_auxin_meta_test_assert( defined( 'NOVA_BRIDGE_SUITE_VERSION' ), 'NOVA Bridge Suite is not loaded.' );
	nova_auxin_meta_test_assert( function_exists( 'cf_tmrb_update_post_meta_all_payload' ), 'The core meta_all handler is not loaded.' );

	$invalid_slug = sanitize_title( $marker . '-invalid' );
	$invalid      = new WP_REST_Request( 'POST', '/seor-bridge/v1/pages' );
	$invalid->set_header( 'Content-Type', 'application/json' );
	$invalid->set_body(
		wp_json_encode(
			array(
				'title'     => $marker . '-invalid',
				'slug'      => $invalid_slug,
				'status'    => 'draft',
				'post_type' => 'page',
				'meta_all'  => 'not-an-object',
			)
		)
	);
	$invalid_response = rest_do_request( $invalid );
	nova_auxin_meta_test_assert( 400 === $invalid_response->get_status(), 'A non-object meta_all payload was not rejected with HTTP 400.' );
	nova_auxin_meta_test_assert( null === get_page_by_path( $invalid_slug, OBJECT, 'page' ), 'The rejected meta_all request created an orphaned page.' );

	$request = new WP_REST_Request( 'POST', '/seor-bridge/v1/pages' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'title'     => $marker,
				'slug'      => sanitize_title( $marker ),
				'status'    => 'draft',
				'post_type' => 'page',
				'meta_all'  => $meta,
			)
		)
	);
	$response = rest_do_request( $request );

	nova_auxin_meta_test_assert( ! is_wp_error( $response ), 'The Elementor REST create request returned an error.' );
	nova_auxin_meta_test_assert( 201 === $response->get_status(), 'The Elementor REST create request did not return HTTP 201.' );
	$data    = $response->get_data();
	$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
	nova_auxin_meta_test_assert( $post_id > 0, 'The Elementor REST create response did not contain a post ID.' );

	foreach ( $meta as $key => $value ) {
		$stored_value = get_post_meta( $post_id, $key, true );
		nova_auxin_meta_test_assert(
			$value === $stored_value,
			sprintf(
				'Auxin setting %s was not persisted as the theme reads it. Expected %s, got %s.',
				$key,
				wp_json_encode( $value ),
				wp_json_encode( $stored_value )
			)
		);
	}

	$read = new WP_REST_Request( 'GET', '/wp/v2/pages/' . $post_id );
	$read->set_param( 'context', 'edit' );
	$read->set_param( 'include_meta_all', true );
	$read_response = rest_do_request( $read );
	nova_auxin_meta_test_assert( ! is_wp_error( $read_response ), 'The core REST readback request returned an error.' );
	$read_data = $read_response->get_data();
	nova_auxin_meta_test_assert( isset( $read_data['meta_all'] ) && is_array( $read_data['meta_all'] ), 'The core REST response did not expose meta_all.' );
	nova_auxin_meta_test_assert( isset( $read_data['meta_all_flat'] ) && is_array( $read_data['meta_all_flat'] ), 'The core REST response did not expose meta_all_flat.' );
	foreach ( $meta as $key => $value ) {
		nova_auxin_meta_test_assert(
			array_key_exists( $key, $read_data['meta_all'] ) && $value === $read_data['meta_all'][ $key ],
			sprintf( 'Auxin setting %s did not round-trip through core REST.', $key )
		);
		nova_auxin_meta_test_assert(
			array_key_exists( $key, $read_data['meta_all_flat'] ) && $value === $read_data['meta_all_flat'][ $key ],
			sprintf( 'Auxin setting %s did not round-trip through meta_all_flat.', $key )
		);
	}

	$core_update = new WP_REST_Request( 'PATCH', '/wp/v2/pages/' . $post_id );
	$core_update->set_header( 'Content-Type', 'application/json' );
	$core_update->set_body(
		wp_json_encode(
			array(
				'meta_all' => array(
					'aux_title_bar_show'             => 'no',
					'aux_title_bar_enable_customize' => '1',
				),
			)
		)
	);
	$core_update_response = rest_do_request( $core_update );
	nova_auxin_meta_test_assert( ! is_wp_error( $core_update_response ), 'The core REST update request returned an error.' );
	nova_auxin_meta_test_assert( 200 === $core_update_response->get_status(), 'The core REST update request did not return HTTP 200.' );
	nova_auxin_meta_test_assert( 'no' === get_post_meta( $post_id, 'aux_title_bar_show', true ), 'The core REST update did not apply the title-bar setting.' );
	nova_auxin_meta_test_assert( '1' === get_post_meta( $post_id, 'aux_title_bar_enable_customize', true ), 'The core REST update did not apply the title-bar customization setting.' );

	$update = new WP_REST_Request( 'PATCH', '/seor-bridge/v1/pages/' . $post_id );
	$update->set_header( 'Content-Type', 'application/json' );
	$update->set_body(
		wp_json_encode(
			array(
				'meta_all' => array(
					'aux_title_bar_text_align'      => 'center',
					'aux_custom_bg_size'            => 'contain',
					'aux_metafields_custom_styles' => '',
				),
			)
		)
	);
	$update_response = rest_do_request( $update );
	nova_auxin_meta_test_assert( ! is_wp_error( $update_response ), 'The Elementor REST update request returned an error.' );
	nova_auxin_meta_test_assert( 200 === $update_response->get_status(), 'The Elementor REST update request did not return HTTP 200.' );
	nova_auxin_meta_test_assert( 'center' === get_post_meta( $post_id, 'aux_title_bar_text_align', true ), 'The Elementor REST update did not apply the text-alignment setting.' );
	nova_auxin_meta_test_assert( 'contain' === get_post_meta( $post_id, 'aux_custom_bg_size', true ), 'The plain update value was not preserved.' );
	nova_auxin_meta_test_assert( metadata_exists( 'post', $post_id, 'aux_metafields_custom_styles' ), 'The empty Auxin custom-styles setting was not stored.' );
	nova_auxin_meta_test_assert( '' === get_post_meta( $post_id, 'aux_metafields_custom_styles', true ), 'The empty Auxin custom-styles setting was not preserved.' );
	foreach ( array_keys( $meta ) as $key ) {
		nova_auxin_meta_test_assert( 1 === count( get_post_meta( $post_id, $key, false ) ), sprintf( 'Auxin setting %s was stored more than once.', $key ) );
	}

	WP_CLI::success( 'Auxin meta_all REST regression checks passed.' );
} finally {
	if ( $post_id > 0 ) {
		wp_delete_post( $post_id, true );
	}
}
