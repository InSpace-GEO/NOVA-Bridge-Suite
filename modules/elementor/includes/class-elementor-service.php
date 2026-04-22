<?php
/**
 * Service helpers for interacting with Elementor data structures.
 *
 * @package SEOR_Elementor_Bridge
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
namespace SEOR_Elementor_Bridge;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides helper methods for fetching and mutating Elementor pages.
 */
class Elementor_Service {
	/**
	 * Last document source identifier.
	 *
	 * @var string
	 */
	private $last_document_source = 'none';
	/**
	 * Build payload describing a page and its exposed fields.
	 *
	 * @param int   $post_id Target page ID.
	 * @param array $args    Optional arguments.
	 * @return array|WP_Error
	 */
	public function get_page_payload( $post_id, array $args = array() ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'seor_eb_invalid_post',
				__( 'Target post does not exist.', 'nova-bridge-suite' ),
				array( 'post_id' => $post_id )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'seor_eb_forbidden',
				__( 'You are not allowed to inspect this page.', 'nova-bridge-suite' ),
				array( 'post_id' => $post_id )
			);
		}

		$meta_keys           = isset( $args['meta_keys'] ) ? array_filter( (array) $args['meta_keys'] ) : array();
		$include_fields      = isset( $args['include_fields'] ) ? (bool) $args['include_fields'] : true;
		$include_element_map = isset( $args['include_element_map'] ) ? (bool) $args['include_element_map'] : false;
		$include_document    = isset( $args['include_document'] ) ? (bool) $args['include_document'] : false;
		$data                = $this->get_elementor_document_data( $post_id );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$payload = array(
			'id'       => (int) $post_id,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'status'   => $post->post_status,
			'author'   => (int) $post->post_author,
			'post_type'=> $post->post_type,
			'template' => get_page_template_slug( $post_id ),
			'meta'     => $this->collect_meta( $post_id, $meta_keys ),
		);

		$payload['fields']      = $include_fields ? $this->extract_fields_from_document( $data ) : array();
		$payload['element_map'] = $include_element_map ? $this->summarize_elements( $data ) : array();

		if ( $include_document ) {
			$payload['document']        = $data;
			$payload['document_source'] = $this->last_document_source;
		}

		return $payload;
	}

	/**
	 * Create a new Elementor page.
	 *
	 * @param array $payload Payload describing the page.
	 * @return int|WP_Error
	 */
	public function create_page( array $payload ) {
		$title = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'seor_eb_empty_title', __( 'A title is required to create a page.', 'nova-bridge-suite' ) );
		}

		$post_type = isset( $payload['post_type'] ) ? sanitize_key( $payload['post_type'] ) : 'page';

		if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
			return new WP_Error( 'seor_eb_invalid_post_type', __( 'Invalid post type provided.', 'nova-bridge-suite' ) );
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object ) {
			return new WP_Error( 'seor_eb_invalid_post_type', __( 'Post type definition could not be loaded.', 'nova-bridge-suite' ) );
		}

		$status            = isset( $payload['status'] ) ? sanitize_key( $payload['status'] ) : 'draft';
		$publish_like      = in_array( $status, array( 'publish', 'private', 'future' ), true );
		$required_capability = $publish_like ? $post_type_object->cap->publish_posts : $post_type_object->cap->edit_posts;

		if ( ! current_user_can( $required_capability ) ) {
			return new WP_Error( 'seor_eb_forbidden', __( 'You are not allowed to create content for this post type.', 'nova-bridge-suite' ) );
		}

		$postarr = array(
			'post_title'   => $title,
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_author'  => isset( $payload['author'] ) ? (int) $payload['author'] : get_current_user_id(),
			'post_name'    => isset( $payload['slug'] ) ? sanitize_title( $payload['slug'] ) : '',
			'post_parent'  => isset( $payload['parent'] ) ? (int) $payload['parent'] : 0,
			'post_excerpt' => isset( $payload['excerpt'] ) ? wp_kses_post( $payload['excerpt'] ) : '',
		);

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( ! empty( $payload['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $payload['template'] ) );
		}

		$page_settings = $this->resolve_page_settings_payload( $payload );
		if ( is_wp_error( $page_settings ) ) {
			return $page_settings;
		}

		$full_document = $this->maybe_prepare_direct_document_replacement( $payload );
		if ( is_wp_error( $full_document ) ) {
			return $full_document;
		}

		if ( false !== $full_document ) {
			$persist = $this->persist_elementor_document(
				$post_id,
				$full_document['document'],
				array(
					'raw_json'      => $full_document['raw_json'],
					'page_settings' => $page_settings,
				)
			);
		} else {
			$document_data = $this->resolve_document_data( $payload );

			if ( is_wp_error( $document_data ) ) {
				return $document_data;
			}

			$fields_payload = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
			$elementor_data = $this->apply_field_mutations( $document_data, $fields_payload );

			if ( is_wp_error( $elementor_data ) ) {
				return $elementor_data;
			}

			$append_html   = isset( $payload['append_html'] ) ? (string) $payload['append_html'] : '';
			$append_faqs   = isset( $payload['append_faqs'] ) && is_array( $payload['append_faqs'] ) ? $payload['append_faqs'] : array();
			$elementor_data = $this->append_html_block( $elementor_data, $append_html, $append_faqs );

			$persist = $this->persist_elementor_document(
				$post_id,
				$elementor_data,
				array(
					'page_settings' => $page_settings,
				)
			);
		}

		if ( is_wp_error( $persist ) ) {
			return $persist;
		}

		if ( ! empty( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			$this->persist_meta( $post_id, $payload['meta'] );
		}

		$finalize = $this->finalize_elementor_document( $post_id, $payload );
		if ( is_wp_error( $finalize ) ) {
			return $finalize;
		}

		return (int) $post_id;
	}

	/**
	 * Update an existing Elementor page.
	 *
	 * @param int   $post_id Target page.
	 * @param array $payload Update payload.
	 * @return array|WP_Error
	 */
	public function update_page( $post_id, array $payload ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seor_eb_invalid_post', __( 'Target post does not exist.', 'nova-bridge-suite' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'seor_eb_forbidden', __( 'You are not allowed to edit this page.', 'nova-bridge-suite' ) );
		}

		$post_updates = array(
			'ID' => $post_id,
		);
		$needs_update = false;

		if ( isset( $payload['title'] ) ) {
			$post_updates['post_title'] = sanitize_text_field( $payload['title'] );
			$needs_update               = true;
		}

		if ( isset( $payload['slug'] ) ) {
			$post_updates['post_name'] = sanitize_title( $payload['slug'] );
			$needs_update              = true;
		}

		if ( isset( $payload['status'] ) ) {
			$post_updates['post_status'] = sanitize_key( $payload['status'] );
			$needs_update                = true;
		}

		if ( isset( $payload['author'] ) ) {
			$post_updates['post_author'] = (int) $payload['author'];
			$needs_update                = true;
		}

		if ( isset( $payload['excerpt'] ) ) {
			$post_updates['post_excerpt'] = wp_kses_post( $payload['excerpt'] );
			$needs_update                 = true;
		}

		if ( $needs_update ) {
			$result = wp_update_post( $post_updates, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $payload['template'] ) ) {
			update_post_meta( $post_id, '_wp_page_template', sanitize_text_field( $payload['template'] ) );
		}

		if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			$this->persist_meta( $post_id, $payload['meta'] );
		}

		$page_settings = $this->resolve_page_settings_payload( $payload );
		if ( is_wp_error( $page_settings ) ) {
			return $page_settings;
		}

		$full_document = $this->maybe_prepare_direct_document_replacement( $payload );
		if ( is_wp_error( $full_document ) ) {
			return $full_document;
		}

		if ( false !== $full_document ) {
			$persist = $this->persist_elementor_document(
				$post_id,
				$full_document['document'],
				array(
					'raw_json'      => $full_document['raw_json'],
					'page_settings' => $page_settings,
				)
			);
		} else {
			$current_data = $this->get_elementor_document_data( $post_id );
			if ( is_wp_error( $current_data ) ) {
				return $current_data;
			}

			$document_data = $this->resolve_document_data( $payload, $current_data, true );
			if ( is_wp_error( $document_data ) ) {
				return $document_data;
			}

			$fields  = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
			$mutated = $this->apply_field_mutations( $document_data, $fields );

			if ( is_wp_error( $mutated ) ) {
				return $mutated;
			}

			$append_html = isset( $payload['append_html'] ) ? (string) $payload['append_html'] : '';
			$append_faqs = isset( $payload['append_faqs'] ) && is_array( $payload['append_faqs'] ) ? $payload['append_faqs'] : array();
			$mutated     = $this->append_html_block( $mutated, $append_html, $append_faqs );

			$persist = $this->persist_elementor_document(
				$post_id,
				$mutated,
				array(
					'page_settings' => $page_settings,
				)
			);
		}

		if ( is_wp_error( $persist ) ) {
			return $persist;
		}

		$finalize = $this->finalize_elementor_document( $post_id, $payload );
		if ( is_wp_error( $finalize ) ) {
			return $finalize;
		}

		return array(
			'post_id' => (int) $post_id,
			'status'  => get_post_status( $post_id ),
			'post_type' => get_post_type( $post_id ),
		);
	}

	/**
	 * Apply the provided change set onto the Elementor document data.
	 *
	 * @param array $document  Elementor document array.
	 * @param array $changeset Fields to update.
	 * @return array|WP_Error
	 */
	public function apply_field_mutations( array $document, array $changeset ) {
		if ( empty( $changeset ) ) {
			return $document;
		}

		$errors = array();

		foreach ( $changeset as $change ) {
			$field_key = isset( $change['field_key'] ) ? sanitize_text_field( $change['field_key'] ) : '';

			if ( empty( $field_key ) && isset( $change['element_id'], $change['path'] ) ) {
				$field_key = $this->build_field_key( $change['element_id'], $change['path'] );
			}

			if ( empty( $field_key ) ) {
				$errors[] = array(
					'code'    => 'seor_eb_missing_field_key',
					'message' => __( 'Each field mutation must include a field_key.', 'nova-bridge-suite' ),
					'context' => $change,
				);
				continue;
			}

			$parsed = $this->parse_field_key( $field_key );

			if ( is_wp_error( $parsed ) ) {
				$errors[] = array(
					'code'    => $parsed->get_error_code(),
					'message' => $parsed->get_error_message(),
					'context' => array( 'field_key' => $field_key ),
				);
				continue;
			}

			$value = isset( $change['value'] ) ? $change['value'] : '';

			if ( ! $this->apply_value_to_document( $document, $parsed['element_id'], $parsed['path'], $value ) ) {
				$errors[] = array(
					'code'    => 'seor_eb_field_not_found',
					'message' => __( 'Field not found in Elementor document.', 'nova-bridge-suite' ),
					'context' => array(
						'field_key'  => $field_key,
						'element_id' => $parsed['element_id'],
						'field_path' => $parsed['path'],
					),
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'seor_eb_mutation_errors', __( 'Some field updates could not be applied.', 'nova-bridge-suite' ), $errors );
		}

		return $document;
	}

	/**
	 * Build a summary of document elements for reference.
	 *
	 * @param array $document Elementor document.
	 * @return array
	 */
	public function summarize_elements( array $document ) {
		$summary = array();

		foreach ( $document as $element ) {
			$this->walk_elements_for_summary( $element, $summary );
		}

		return $summary;
	}

	/**
	 * Decode Elementor `_elementor_data`.
	 *
	 * @param int $post_id Target page.
	 * @return array|WP_Error
	 */
	public function get_elementor_document_data( $post_id ) {
		$this->last_document_source = 'none';

		$document = $this->get_document_data_from_meta( $post_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		if ( null === $document ) {
			$document = $this->get_document_data_via_elementor( $post_id );
		}

		return $this->normalize_document_structure( $document );
	}

	/**
	 * Persist Elementor document back to post meta.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $document Document data.
	 * @param array $options  Persistence options.
	 * @return true|WP_Error
	 */
	private function persist_elementor_document( $post_id, array $document, array $options = array() ) {
		$raw_json = isset( $options['raw_json'] ) ? $options['raw_json'] : null;

		if ( ! is_string( $raw_json ) || '' === trim( $raw_json ) ) {
			$raw_json = $this->encode_json_string(
				$document,
				'seor_eb_encode_failed',
				__( 'Elementor document could not be encoded for persistence.', 'nova-bridge-suite' )
			);
			if ( is_wp_error( $raw_json ) ) {
				return $raw_json;
			}
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $raw_json ) );

		$page_settings = isset( $options['page_settings'] ) && is_array( $options['page_settings'] )
			? $options['page_settings']
			: array( 'present' => false );

		$page_settings_result = $this->persist_elementor_page_settings( $post_id, $page_settings );
		if ( is_wp_error( $page_settings_result ) ) {
			return $page_settings_result;
		}

		$this->sync_elementor_runtime_meta( $post_id );
		clean_post_cache( $post_id );

		return $this->verify_persisted_elementor_document( $post_id, $document, $page_settings );
	}

	/**
	 * Persist arbitrary meta values.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta key => value map.
	 */
	private function persist_meta( $post_id, array $meta ) {
		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( null === $value ) {
				delete_post_meta( $post_id, $key );
				continue;
			}

			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Read Elementor document data directly from post meta without fallback.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error|null
	 */
	private function get_document_data_from_meta( $post_id ) {
		$has_meta = metadata_exists( 'post', $post_id, '_elementor_data' );

		if ( ! $has_meta ) {
			return null;
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_array( $raw ) ) {
			$this->last_document_source = 'meta:array';
			return $raw;
		}

		if ( ! is_string( $raw ) ) {
			return new WP_Error(
				'seor_eb_invalid_elementor_meta',
				__( 'Stored Elementor document is unreadable.', 'nova-bridge-suite' ),
				array(
					'post_id'  => (int) $post_id,
					'raw_type' => gettype( $raw ),
				)
			);
		}

		$raw_json = trim( $raw );

		if ( '' === $raw_json ) {
			return new WP_Error(
				'seor_eb_invalid_elementor_meta',
				__( 'Stored Elementor document is empty.', 'nova-bridge-suite' ),
				array( 'post_id' => (int) $post_id )
			);
		}

		$maybe_unserialized = maybe_unserialize( $raw_json );

		if ( is_array( $maybe_unserialized ) ) {
			$this->last_document_source = 'meta:serialized';
			return $maybe_unserialized;
		}

		$decoded = $this->decode_json_array_string( $raw_json, $used_unslashed_fallback );

		if ( is_array( $decoded ) ) {
			$this->last_document_source = $used_unslashed_fallback ? 'meta:json-unslashed' : 'meta:json';
			return $decoded;
		}

		return new WP_Error(
			'seor_eb_invalid_elementor_meta',
			__( 'Stored Elementor document is unreadable.', 'nova-bridge-suite' ),
			array(
				'post_id'    => (int) $post_id,
				'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
			)
		);
	}

	/**
	 * Resolve top-level Elementor page settings payload.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error
	 */
	private function resolve_page_settings_payload( array $payload ) {
		if ( ! array_key_exists( 'elementor_page_settings', $payload ) ) {
			return array(
				'present' => false,
			);
		}

		$value = $payload['elementor_page_settings'];

		if ( null === $value ) {
			return array(
				'present' => true,
				'value'   => null,
			);
		}

		if ( is_string( $value ) ) {
			$decoded = $this->decode_json_array_string( trim( $value ) );

			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'seor_eb_invalid_page_settings',
					__( 'elementor_page_settings must be a JSON object/array or a native array.', 'nova-bridge-suite' ),
					array(
						'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
					)
				);
			}

			$value = $decoded;
		}

		if ( ! is_array( $value ) ) {
			return new WP_Error(
				'seor_eb_invalid_page_settings',
				__( 'elementor_page_settings must be a JSON object/array or a native array.', 'nova-bridge-suite' )
			);
		}

		return array(
			'present' => true,
			'value'   => $value,
		);
	}

	/**
	 * Determine whether the payload requires document mutation before save.
	 *
	 * @param array $payload Request payload.
	 * @return bool
	 */
	private function payload_requires_document_mutation( array $payload ) {
		$fields = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		if ( ! empty( $fields ) ) {
			return true;
		}

		$append_html = isset( $payload['append_html'] ) ? trim( (string) $payload['append_html'] ) : '';
		if ( '' !== $append_html ) {
			return true;
		}

		$append_faqs = isset( $payload['append_faqs'] ) && is_array( $payload['append_faqs'] ) ? $payload['append_faqs'] : array();

		return ! empty( $this->normalize_faq_items( $append_faqs ) );
	}

	/**
	 * Prepare a direct full-document replacement when no mutations are requested.
	 *
	 * @param array $payload Request payload.
	 * @return array|WP_Error|false
	 */
	private function maybe_prepare_direct_document_replacement( array $payload ) {
		if ( ! array_key_exists( 'elementor_data', $payload ) ) {
			return false;
		}

		if ( $this->payload_requires_document_mutation( $payload ) ) {
			return false;
		}

		return $this->prepare_document_payload( $payload['elementor_data'] );
	}

	/**
	 * Prepare Elementor document payload for persistence.
	 *
	 * @param mixed $data Incoming document payload.
	 * @return array|WP_Error
	 */
	private function prepare_document_payload( $data ) {
		$raw_json = null;

		if ( is_string( $data ) ) {
			$raw_json = trim( $data );

			if ( '' === $raw_json ) {
				return new WP_Error(
					'seor_eb_invalid_elementor_data',
					__( 'elementor_data cannot be an empty string.', 'nova-bridge-suite' )
				);
			}

			$data = $this->decode_json_array_string( $raw_json );

			if ( ! is_array( $data ) ) {
				return new WP_Error(
					'seor_eb_invalid_elementor_data',
					__( 'elementor_data must be valid JSON or an array.', 'nova-bridge-suite' ),
					array(
						'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
					)
				);
			}
		} elseif ( ! is_array( $data ) ) {
			return new WP_Error(
				'seor_eb_invalid_elementor_data',
				__( 'elementor_data must be valid JSON or an array.', 'nova-bridge-suite' )
			);
		}

		$document = $this->normalize_document_structure( $data );

		if ( ! $this->is_valid_document_payload( $data, $document ) ) {
			return new WP_Error(
				'seor_eb_invalid_elementor_data',
				__( 'elementor_data does not contain a valid Elementor document.', 'nova-bridge-suite' )
			);
		}

		if ( null === $raw_json || $data !== $document ) {
			$raw_json = $this->encode_json_string(
				$document,
				'seor_eb_encode_failed',
				__( 'Elementor document could not be encoded for persistence.', 'nova-bridge-suite' )
			);

			if ( is_wp_error( $raw_json ) ) {
				return $raw_json;
			}
		}

		return array(
			'document' => $document,
			'raw_json' => $raw_json,
		);
	}

	/**
	 * Decode a JSON string using the raw value first, then an unslashed fallback for legacy payloads.
	 *
	 * @param string    $raw                    Raw JSON string.
	 * @param bool|null $used_unslashed_fallback Whether unslashed fallback was required.
	 * @return array|null
	 */
	private function decode_json_array_string( $raw, &$used_unslashed_fallback = null ) {
		$used_unslashed_fallback = false;

		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		$unslashed = wp_unslash( $raw );

		if ( $unslashed === $raw ) {
			return null;
		}

		$decoded = json_decode( $unslashed, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			$used_unslashed_fallback = true;
			return $decoded;
		}

		return null;
	}

	/**
	 * Determine if the provided payload can represent an Elementor document.
	 *
	 * @param array $raw_document Raw input document.
	 * @param array $document     Normalized Elementor document.
	 * @return bool
	 */
	private function is_valid_document_payload( array $raw_document, array $document ) {
		if ( empty( $raw_document ) ) {
			return true;
		}

		if ( isset( $raw_document[0] ) && is_array( $raw_document[0] ) ) {
			return true;
		}

		foreach ( array( 'elements', 'content', 'data', 'widgets' ) as $key ) {
			if ( array_key_exists( $key, $raw_document ) && is_array( $raw_document[ $key ] ) ) {
				return true;
			}
		}

		foreach ( $raw_document as $maybe_element ) {
			if ( is_array( $maybe_element ) && isset( $maybe_element['elType'] ) ) {
				return true;
			}
		}

		return ! empty( $document );
	}

	/**
	 * Encode data with strict error handling.
	 *
	 * @param mixed  $value         Value to encode.
	 * @param string $error_code    Error code.
	 * @param string $error_message Error message.
	 * @return string|WP_Error
	 */
	private function encode_json_string( $value, $error_code, $error_message ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new WP_Error(
				$error_code,
				$error_message,
				array(
					'json_error' => function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : '',
				)
			);
		}

		return $json;
	}

	/**
	 * Persist Elementor page settings when explicitly supplied.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $page_settings  Page settings payload state.
	 * @return true|WP_Error
	 */
	private function persist_elementor_page_settings( $post_id, array $page_settings ) {
		if ( empty( $page_settings['present'] ) ) {
			return true;
		}

		if ( ! array_key_exists( 'value', $page_settings ) || null === $page_settings['value'] ) {
			delete_post_meta( $post_id, '_elementor_page_settings' );
			return true;
		}

		if ( ! is_array( $page_settings['value'] ) ) {
			return new WP_Error(
				'seor_eb_invalid_page_settings',
				__( 'Elementor page settings must be an array when persisted.', 'nova-bridge-suite' )
			);
		}

		update_post_meta( $post_id, '_elementor_page_settings', $page_settings['value'] );

		return true;
	}

	/**
	 * Keep Elementor runtime meta aligned after saving document data.
	 *
	 * @param int $post_id Post ID.
	 */
	private function sync_elementor_runtime_meta( $post_id ) {
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'page' );

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * Verify persisted Elementor data immediately after write.
	 *
	 * @param int   $post_id        Post ID.
	 * @param array $expected       Expected document data.
	 * @param array $page_settings  Page settings payload state.
	 * @return true|WP_Error
	 */
	private function verify_persisted_elementor_document( $post_id, array $expected, array $page_settings = array() ) {
		$stored = $this->get_document_data_from_meta( $post_id );

		if ( is_wp_error( $stored ) ) {
			return new WP_Error(
				'seor_eb_persist_mismatch',
				__( 'Persisted Elementor document could not be reloaded after save.', 'nova-bridge-suite' ),
				array(
					'post_id' => (int) $post_id,
					'error'   => $stored->get_error_code(),
				)
			);
		}

		if ( null === $stored || ! $this->documents_match( $expected, $stored ) ) {
			return new WP_Error(
				'seor_eb_persist_mismatch',
				__( 'Persisted Elementor document does not match the requested payload.', 'nova-bridge-suite' ),
				array( 'post_id' => (int) $post_id )
			);
		}

		if ( ! empty( $page_settings['present'] ) ) {
			$has_stored_page_settings = metadata_exists( 'post', $post_id, '_elementor_page_settings' );

			if ( ! array_key_exists( 'value', $page_settings ) || null === $page_settings['value'] ) {
				if ( $has_stored_page_settings ) {
					return new WP_Error(
						'seor_eb_persist_mismatch',
						__( 'Elementor page settings were not removed as requested.', 'nova-bridge-suite' ),
						array( 'post_id' => (int) $post_id )
					);
				}
			} else {
				$stored_page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );

				if ( $stored_page_settings !== $page_settings['value'] ) {
					return new WP_Error(
						'seor_eb_persist_mismatch',
						__( 'Elementor page settings do not match the requested payload.', 'nova-bridge-suite' ),
						array( 'post_id' => (int) $post_id )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Compare two Elementor documents after normalization.
	 *
	 * @param array $expected Expected document.
	 * @param array $actual   Actual document.
	 * @return bool
	 */
	private function documents_match( array $expected, array $actual ) {
		$expected_json = $this->encode_json_string(
			$this->normalize_document_structure( $expected ),
			'seor_eb_encode_failed',
			__( 'Elementor document comparison failed.', 'nova-bridge-suite' )
		);
		$actual_json   = $this->encode_json_string(
			$this->normalize_document_structure( $actual ),
			'seor_eb_encode_failed',
			__( 'Elementor document comparison failed.', 'nova-bridge-suite' )
		);

		if ( is_wp_error( $expected_json ) || is_wp_error( $actual_json ) ) {
			return false;
		}

		return hash_equals( $expected_json, $actual_json );
	}

	/**
	 * Build a map of desired meta key values.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $meta_keys Specific keys to fetch.
	 * @return array
	 */
	private function collect_meta( $post_id, array $meta_keys ) {
		if ( empty( $meta_keys ) ) {
			return array();
		}

		$meta = array();

		foreach ( $meta_keys as $key ) {
			$key          = sanitize_key( $key );
			$meta[ $key ]  = get_post_meta( $post_id, $key, true );
		}

		return $meta;
	}

	/**
	 * Extract a flattened collection of fields from Elementor document.
	 *
	 * @param array $document Elementor document.
	 * @return array
	 */
	private function extract_fields_from_document( array $document ) {
		$fields = array();

		foreach ( $document as $element ) {
			$this->walk_element_for_fields( $element, $fields );
		}

		return $fields;
	}

	/**
	 * Traverse a single element and collect widget fields.
	 *
	 * @param array $element Elementor element.
	 * @param array $fields  Accumulator.
	 */
	private function walk_element_for_fields( array $element, array &$fields ) {
		if ( isset( $element['elType'] ) && 'widget' === $element['elType'] ) {
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$this->walk_settings_for_fields(
				$element['id'],
				isset( $element['widgetType'] ) ? $element['widgetType'] : '',
				$settings,
				array(),
				$fields
			);
		}

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $child ) {
				if ( is_array( $child ) ) {
					$this->walk_element_for_fields( $child, $fields );
				}
			}
		}
	}

	/**
	 * Traverse widget settings and collect scalar values.
	 *
	 * @param string $element_id  Elementor element ID.
	 * @param string $widget_type Widget type.
	 * @param mixed  $value       Current value.
	 * @param array  $path        Path to current value.
	 * @param array  $fields      Accumulator.
	 */
	private function walk_settings_for_fields( $element_id, $widget_type, $value, array $path, array &$fields ) {
		if ( is_string( $value ) || is_numeric( $value ) ) {
			$last_key = $this->resolve_setting_key_from_path( $path );
			if ( $this->is_setting_key_allowed( $last_key ) ) {
				$fields[] = array(
					'field_key'   => $this->build_field_key( $element_id, $path ),
					'element_id'  => $element_id,
					'widget_type' => $widget_type,
					'path'        => $path,
					'key'         => $last_key,
					'value'       => is_string( $value ) ? $value : (string) $value,
				);
			}
			return;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$next_path   = $path;
				$next_path[] = is_int( $key ) ? (string) $key : $key;

				$this->walk_settings_for_fields( $element_id, $widget_type, $child, $next_path, $fields );
			}
		}
	}

	/**
	 * Apply a value to document path for specific element.
	 *
	 * @param array  $document   Document reference.
	 * @param string $element_id Target element ID.
	 * @param array  $path       Path into settings.
	 * @param mixed  $value      New value.
	 * @return bool
	 */
	private function apply_value_to_document( array &$document, $element_id, array $path, $value ) {
		foreach ( $document as &$element ) {
			if ( isset( $element['id'] ) && $element['id'] === $element_id ) {
				if ( ! isset( $element['settings'] ) || ! is_array( $element['settings'] ) ) {
					$element['settings'] = array();
				}
				return $this->assign_by_path( $element['settings'], $path, $value );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				if ( $this->apply_value_to_document( $element['elements'], $element_id, $path, $value ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Assign a value at the provided path within settings array.
	 *
	 * @param array $settings Settings reference.
	 * @param array $path     Path within array.
	 * @param mixed $value    Value to assign.
	 * @return bool
	 */
	private function assign_by_path( array &$settings, array $path, $value ) {
		if ( empty( $path ) ) {
			return false;
		}

		$current = &$settings;

		foreach ( $path as $index => $segment ) {
			$is_last = ( $index === count( $path ) - 1 );
			$key     = is_numeric( $segment ) ? (int) $segment : $segment;

			if ( $is_last ) {
				$current[ $key ] = $value;
				return true;
			}

			if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) ) {
				$current[ $key ] = array();
			}

			$current = &$current[ $key ];
		}

		return false;
	}

	/**
	 * Resolve the most specific non-numeric key from a path.
	 *
	 * @param array $path Path segments.
	 * @return string
	 */
	private function resolve_setting_key_from_path( array $path ) {
		$reversed = array_reverse( $path );

		foreach ( $reversed as $segment ) {
			if ( ! is_numeric( $segment ) ) {
				return (string) $segment;
			}
		}

		return isset( $path[0] ) ? (string) $path[0] : '';
	}

	/**
	 * Determine if a setting key should be exposed.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	private function is_setting_key_allowed( $key ) {
		$allowlist = apply_filters( 'seor_eb_allowed_setting_keys', array() );

		if ( empty( $allowlist ) ) {
			return true;
		}

		return in_array( $key, $allowlist, true );
	}

	/**
	 * Parse a field key back to element ID and path.
	 *
	 * @param string $field_key Field key string.
	 * @return array|WP_Error
	 */
	public function parse_field_key( $field_key ) {
		$parts = explode( '|', $field_key );

		if ( count( $parts ) !== 2 ) {
			return new WP_Error( 'seor_eb_invalid_field_key', __( 'Field key must include an element ID and path.', 'nova-bridge-suite' ) );
		}

		$element_id = sanitize_text_field( $parts[0] );
		$path       = array_map(
			function ( $segment ) {
				return sanitize_text_field( $segment );
			},
			array_filter( explode( '.', $parts[1] ), 'strlen' )
		);

		if ( empty( $element_id ) || empty( $path ) ) {
			return new WP_Error( 'seor_eb_invalid_field_key', __( 'Field key is missing required parts.', 'nova-bridge-suite' ) );
		}

		return array(
			'element_id' => $element_id,
			'path'       => $path,
		);
	}

	/**
	 * Build a field key string out of element ID and path.
	 *
	 * @param string       $element_id Element ID.
	 * @param array|string $path       Path (array or dot notation).
	 * @return string
	 */
	public function build_field_key( $element_id, $path ) {
		$element_id = sanitize_text_field( $element_id );

		if ( is_string( $path ) ) {
			$path = array_filter( explode( '.', $path ), 'strlen' );
		}

		$path = array_map(
			function ( $segment ) {
				return is_numeric( $segment ) ? (string) (int) $segment : (string) $segment;
			},
			(array) $path
		);

		return $element_id . '|' . implode( '.', $path );
	}

	/**
	 * Produce a simple summary of an element tree.
	 *
	 * @param array $element Element to summarise.
	 * @param array $summary Accumulator.
	 */
	private function walk_elements_for_summary( array $element, array &$summary ) {
		if ( ! isset( $element['id'] ) ) {
			return;
		}

		$summary[] = array(
			'element_id'  => $element['id'],
			'el_type'     => isset( $element['elType'] ) ? $element['elType'] : '',
			'widget_type' => isset( $element['widgetType'] ) ? $element['widgetType'] : '',
			'parent'      => isset( $element['parent'] ) ? $element['parent'] : '',
		);

		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			foreach ( $element['elements'] as $child ) {
				if ( is_array( $child ) ) {
					$this->walk_elements_for_summary( $child, $summary );
				}
			}
		}
	}

	/**
	 * Resolve document data from payload or fallback.
	 *
	 * @param array $payload Payload data.
	 * @param array $fallback Existing document as fallback.
	 * @param bool  $allow_source Whether to allow cloning from source page.
	 * @return array|WP_Error
	 */
	private function resolve_document_data( array $payload, array $fallback = array(), $allow_source = true ) {
		if ( array_key_exists( 'elementor_data', $payload ) ) {
			$prepared = $this->prepare_document_payload( $payload['elementor_data'] );

			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			return $prepared['document'];
		}

		if ( $allow_source && ! empty( $payload['source_page_id'] ) ) {
			$source_id = (int) $payload['source_page_id'];
			if ( $source_id > 0 ) {
				$source_data = $this->get_elementor_document_data( $source_id );
				if ( is_wp_error( $source_data ) ) {
					return $source_data;
				}

				if ( ! empty( $source_data ) ) {
					return $source_data;
				}
			}
		}

		if ( ! empty( $fallback ) ) {
			return $fallback;
		}

		$section_id = $this->generate_element_id();
		$column_id  = $this->generate_element_id();
		$widget_id  = $this->generate_element_id();

		return array(
			array(
				'id'       => $section_id,
				'elType'   => 'section',
				'layout'   => 'full_width',
				'elements' => array(
					array(
						'id'       => $column_id,
						'elType'   => 'column',
						'elements' => array(
							array(
								'id'         => $widget_id,
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array(
									'editor' => __( 'New Elementor Bridge Page', 'nova-bridge-suite' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Extract a field-friendly unique ID.
	 *
	 * @return string
	 */
	private function generate_element_id() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
	}

	/**
	 * Try to load document data through Elementor's document API.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function get_document_data_via_elementor( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return array();
		}

		try {
			$plugin            = \Elementor\Plugin::instance();
			$documents_manager = isset( $plugin->documents ) ? $plugin->documents : null;

			if ( $documents_manager ) {
				$document = $documents_manager->get( $post_id );

				if ( $document && method_exists( $document, 'get_elements_data' ) ) {
					$data = $document->get_elements_data();
					if ( is_array( $data ) && ! empty( $data ) ) {
						$this->last_document_source = 'document_manager';
						return $data;
					}
				}
			}

			$data = $this->get_document_data_via_db( $plugin, $post_id );
			if ( ! empty( $data ) ) {
				$this->last_document_source = 'db:plain-editor';
			}
			return $data;
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Fallback to Elementor DB helper methods.
	 *
	 * @param \Elementor\Plugin $plugin  Elementor plugin instance.
	 * @param int               $post_id Post ID.
	 * @return array
	 */
	private function get_document_data_via_db( $plugin, $post_id ) {
		if ( ! isset( $plugin->db ) ) {
			return array();
		}

		try {
			if ( method_exists( $plugin->db, 'get_plain_editor' ) ) {
				$data = $plugin->db->get_plain_editor( $post_id );
				if ( is_array( $data ) && ! empty( $data ) ) {
					return $data;
				}
			}

			if ( method_exists( $plugin->db, 'get_builder' ) ) {
				$data = $plugin->db->get_builder( $post_id );
				if ( is_array( $data ) && ! empty( $data ) ) {
					return $data;
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}

		return array();
	}

	/**
	 * Normalize Elementor document data to a consistent array of elements.
	 *
	 * @param mixed $document Raw document data.
	 * @return array
	 */
	private function normalize_document_structure( $document ) {
		if ( empty( $document ) || ! is_array( $document ) ) {
			return array();
		}

		if ( isset( $document[0] ) && is_array( $document[0] ) ) {
			return $document;
		}

		foreach ( array( 'elements', 'content', 'data', 'widgets' ) as $key ) {
			if ( isset( $document[ $key ] ) && is_array( $document[ $key ] ) ) {
				return $this->normalize_document_structure( $document[ $key ] );
			}
		}

		$elements = array();

		foreach ( $document as $maybe_element ) {
			if ( is_array( $maybe_element ) && isset( $maybe_element['elType'] ) ) {
				$elements[] = $maybe_element;
			}
		}

		return $elements;
	}

	/**
	 * Append rich HTML and/or FAQs to the bottom of the document in a single section.
	 *
	 * @param array  $document    Elementor document.
	 * @param string $html        Raw HTML to append.
	 * @param array  $append_faqs FAQ rows to append as accordion items.
	 * @return array
	 */
	private function append_html_block( array $document, $html, array $append_faqs = array() ) {
		$sanitized_html = is_string( $html ) ? trim( wp_kses_post( $html ) ) : '';
		$normalized_faqs = $this->normalize_faq_items( $append_faqs );

		if ( '' === $sanitized_html && empty( $normalized_faqs ) ) {
			return $document;
		}

		$section_id = $this->generate_element_id();
		$column_id  = $this->generate_element_id();
		$widgets    = array();

		if ( '' !== $sanitized_html ) {
			$widgets[] = $this->build_text_editor_widget( $sanitized_html );
		}

		if ( ! empty( $normalized_faqs ) ) {
			$widgets[] = $this->build_accordion_widget( $normalized_faqs );
		}

		$document[] = array(
			'id'       => $section_id,
			'elType'   => 'section',
			'layout'   => 'full_width',
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'elements' => $widgets,
				),
			),
		);

		return $document;
	}

	/**
	 * Build a text-editor widget payload.
	 *
	 * @param string $html Sanitized HTML.
	 * @return array
	 */
	private function build_text_editor_widget( $html ) {
		return array(
			'id'         => $this->generate_element_id(),
			'elType'     => 'widget',
			'widgetType' => 'text-editor',
			'settings'   => array(
				'editor' => $html,
			),
		);
	}

	/**
	 * Build an accordion widget from FAQ rows.
	 *
	 * @param array $faqs Normalized FAQ items.
	 * @return array
	 */
	private function build_accordion_widget( array $faqs ) {
		$tabs = array();

		foreach ( $faqs as $faq ) {
			$tabs[] = array(
				'_id'         => $this->generate_element_id(),
				'tab_title'   => $faq['question'],
				'tab_content' => $faq['answer'],
			);
		}

		return array(
			'id'         => $this->generate_element_id(),
			'elType'     => 'widget',
			'widgetType' => 'accordion',
			'settings'   => array(
				'tabs'           => $tabs,
				'active_item_no' => '1',
			),
		);
	}

	/**
	 * Normalize FAQ rows from payload.
	 *
	 * @param array $append_faqs Raw FAQ rows.
	 * @return array
	 */
	private function normalize_faq_items( array $append_faqs ) {
		$normalized = array();

		foreach ( $append_faqs as $faq ) {
			if ( ! is_array( $faq ) ) {
				continue;
			}

			$question_raw = '';
			if ( isset( $faq['question'] ) ) {
				$question_raw = $faq['question'];
			} elseif ( isset( $faq['title'] ) ) {
				$question_raw = $faq['title'];
			} elseif ( isset( $faq['q'] ) ) {
				$question_raw = $faq['q'];
			}

			$answer_raw = '';
			if ( isset( $faq['answer'] ) ) {
				$answer_raw = $faq['answer'];
			} elseif ( isset( $faq['content'] ) ) {
				$answer_raw = $faq['content'];
			} elseif ( isset( $faq['a'] ) ) {
				$answer_raw = $faq['a'];
			}

			$question = sanitize_text_field( (string) $question_raw );
			$answer   = trim( wp_kses_post( (string) $answer_raw ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$normalized[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $normalized;
	}

	/**
	 * Trigger Elementor and WordPress publishing side-effects so the frontend reflects new content.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $payload Request payload.
	 * @return true|WP_Error
	 */
	private function finalize_elementor_document( $post_id, array $payload ) {
		if ( $this->should_publish_document( $payload, $post_id ) ) {
			$result = $this->bump_post_revision( $post_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$this->clear_elementor_cache( $post_id );

		return true;
	}

	/**
	 * Decide whether the Elementor document should be published.
	 *
	 * @param array $payload Request payload.
	 * @param int   $post_id Post ID.
	 * @return bool
	 */
	private function should_publish_document( array $payload, $post_id ) {
		if ( array_key_exists( 'publish_elementor', $payload ) ) {
			return $this->value_to_bool( $payload['publish_elementor'] );
		}

		return 'publish' === get_post_status( $post_id );
	}

	/**
	 * Fire Elementor cache clearing routines when available.
	 *
	 * @param int $post_id Post ID.
	 */
	private function clear_elementor_cache( $post_id ) {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			clean_post_cache( $post_id );
			return;
		}

		try {
			$plugin = \Elementor\Plugin::instance();
			$this->invalidate_elementor_document_cache( $post_id, $plugin );

			if ( isset( $plugin->posts_css_manager ) && method_exists( $plugin->posts_css_manager, 'clear_cache' ) ) {
				$plugin->posts_css_manager->clear_cache( $post_id );
			}

			$clear_global_files_cache = (bool) apply_filters(
				'seor_eb_clear_global_elementor_files_cache',
				false,
				$post_id,
				$plugin
			);

			if (
				$clear_global_files_cache
				&& isset( $plugin->files_manager )
				&& method_exists( $plugin->files_manager, 'clear_cache' )
			) {
				$plugin->files_manager->clear_cache();
			}
		} catch ( \Throwable $e ) {
			// Swallow silently; cache refresh failure should not break content updates.
		}

		clean_post_cache( $post_id );
	}

	/**
	 * Bump the post revision so Elementor's frontend picks up the latest document.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	private function bump_post_revision( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'seor_eb_invalid_post',
				__( 'Target post does not exist.', 'nova-bridge-suite' ),
				array( 'post_id' => $post_id )
			);
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$this->invalidate_elementor_document_cache( $post_id, \Elementor\Plugin::instance() );
			} catch ( \Throwable $e ) {
				// Best-effort only.
			}
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $post->post_status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( class_exists( '\Elementor\Plugin' ) ) {
			try {
				$this->invalidate_elementor_document_cache( $post_id, \Elementor\Plugin::instance() );
			} catch ( \Throwable $e ) {
				// Best-effort only.
			}
		}

		clean_post_cache( $post_id );

		return true;
	}

	/**
	 * Invalidate Elementor document caches when the installed version exposes it.
	 *
	 * @param int               $post_id Post ID.
	 * @param \Elementor\Plugin $plugin  Elementor plugin instance.
	 */
	private function invalidate_elementor_document_cache( $post_id, $plugin ) {
		if ( ! isset( $plugin->documents ) ) {
			return;
		}

		$documents_manager = $plugin->documents;
		$document          = null;

		if ( method_exists( $documents_manager, 'get' ) ) {
			$document = $documents_manager->get( $post_id );
		}

		if ( $document && is_object( $document ) ) {
			foreach ( array( 'delete_cache', 'clear_cache' ) as $method ) {
				if ( method_exists( $document, $method ) ) {
					$document->{$method}();
				}
			}
		}
	}

	/**
	 * Normalize different truthy representations into a strict boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function value_to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (bool) (int) $value;
		}

		if ( is_string( $value ) ) {
			return in_array(
				strtolower( trim( $value ) ),
				array( '1', 'true', 'yes', 'on' ),
				true
			);
		}

		return ! empty( $value );
	}

	/**
	 * Expose the last document source used.
	 *
	 * @return string
	 */
	public function get_last_document_source() {
		return $this->last_document_source;
	}
}
