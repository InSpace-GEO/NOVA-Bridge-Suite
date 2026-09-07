<?php
/** Authenticated, schema-gated editorial transport, independent of show_in_rest. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Content_Transport {
	private static $bootstrapped = false;

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) { return; }
		self::$bootstrapped = true;
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ], 998 );
	}

	public static function register_routes(): void {
		foreach ( get_post_types( [], 'objects' ) as $type ) {
			if ( self::supports_post_type( $type ) ) {
				( new Nova_Bridge_Suite_Content_Controller( $type->name ) )->register_routes();
			}
		}
	}

	/** Never expose internal/system post types, even if another module opts them into discovery. */
	public static function supports_post_type( $type ): bool {
		$type = is_string( $type ) ? get_post_type_object( $type ) : $type;
		if ( ! $type instanceof WP_Post_Type ) { return false; }
		if ( ( 'service_page' === $type->name && class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) ) || ( function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) && in_array( $type->name, (array) nova_bridge_suite_get_managed_blog_post_types(), true ) ) ) { return false; }
		if ( in_array( $type->name, [ 'post', 'page' ], true ) ) { return true; }
		if ( $type->_builtin || ! $type->show_ui ) { return false; }
		if ( in_array( $type->name, [ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'shop_subscription' ], true )
			|| preg_match( '/^(?:acf|acfe|elementor|woocommerce|wc|wp|shop)[_-]|^e-|(?:^|[_-])(?:nav|navigation|menus?|orders?|payments?|logs?|transactions?|tickets?|fonts?|icons?|snippets?)$/i', $type->name ) ) { return false; }
		return ( $type->public || $type->publicly_queryable ) && ( post_type_supports( $type->name, 'editor' ) || post_type_supports( $type->name, 'excerpt' ) || post_type_supports( $type->name, 'custom-fields' ) || ! empty( self::acf_fields( $type->name, 0, '' ) ) );
	}

	public static function route_for( string $post_type ): string {
		return self::supports_post_type( $post_type ) ? '/nova-bridge/v1/content/' . $post_type : '';
	}

	/** The registry is the allowlist. Existing unregistered database keys are never a schema. */
	public static function field_catalog( string $post_type, int $post_id = 0, string $template = '' ): array {
		$catalog = [ 'acf' => [], 'meta' => [] ];
		if ( ! self::supports_post_type( $post_type ) ) { return $catalog; }
		$catalog['acf'] = self::acf_fields( $post_type, $post_id, $template );
		$registrations = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) );
		foreach ( $registrations as $key => $registration ) {
			if ( self::safe_key( $key ) && is_array( $registration ) && ! empty( $registration['single'] ) && ! isset( $catalog['acf'][ $key ] )
				&& ( ! empty( $registration['show_in_rest'] ) || ! empty( $registration['description'] ) ) ) {
				$catalog['meta'][ $key ] = $registration;
			}
		}
		return $catalog;
	}

	public static function safe_key( $key ): bool {
		return is_string( $key ) && '' !== $key && strlen( $key ) <= 191 && '_' !== $key[0]
			&& ! preg_match( '/[.\[\]\/\\\\\x00-\x20]|(?:password|passwd|secret|token|credential|api[_-]?key|private[_-]?key|session|nonce)/i', $key )
			&& ! in_array( $key, [ 'acf', 'wpbakery' ], true );
	}

	/** ACF/SCF's location engine chooses the actual screen, including template conditions. */
	private static function acf_fields( string $post_type, int $post_id, string $template ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) || ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) { return []; }
		$screen = [ 'post_type' => $post_type, 'post_id' => $post_id ];
		if ( '' !== $template ) { $screen['page_template'] = $template; $screen['post_template'] = $template; }
		$fields = [];
		try {
			foreach ( (array) acf_get_field_groups( $screen ) as $group ) {
				if ( isset( $group['active'] ) && ! $group['active'] ) { continue; }
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( ! is_array( $field ) || ! self::safe_acf_field( $field ) ) { continue; }
					$name = $field['name'];
					// Conflicting definitions are ambiguous; never choose one by registration order.
					if ( isset( $fields[ $name ] ) && $fields[ $name ]['key'] !== $field['key'] ) { $fields[ $name ] = false; continue; }
					if ( ! array_key_exists( $name, $fields ) ) { $fields[ $name ] = $field; }
				}
			}
		} catch ( Throwable $exception ) { return []; }
		return array_filter( $fields, 'is_array' );
	}

	/** Unsupported field providers and fields containing secrets are never advertised as writable. */
	private static function safe_acf_field( array $field, int $depth = 0 ): bool {
		if ( $depth > 12 || ! self::safe_key( $field['name'] ?? '' ) || empty( $field['key'] ) || ! empty( $field['readonly'] ) || ! empty( $field['disabled'] ) ) { return false; }
		$type = $field['type'] ?? '';
		if ( function_exists( 'acf_get_field_type' ) && ! acf_get_field_type( $type ) ) { return false; }
		if ( in_array( $type, [ 'group', 'repeater', 'flexible_content' ], true ) ) {
			$sets = 'flexible_content' === $type ? (array) ( $field['layouts'] ?? [] ) : [ $field ];
			if ( empty( $sets ) ) { return false; }
			foreach ( $sets as $set ) {
				foreach ( (array) ( $set['sub_fields'] ?? [] ) as $child ) {
					if ( in_array( $child['type'] ?? '', [ 'tab', 'accordion', 'message' ], true ) ) { continue; }
					if ( ! self::safe_acf_field( $child, $depth + 1 ) ) { return false; }
				}
			}
			return true;
		}
		return in_array( $type, [ 'text', 'textarea', 'wysiwyg', 'email', 'url', 'oembed', 'number', 'range', 'true_false', 'select', 'radio', 'checkbox', 'button_group', 'image', 'file', 'gallery', 'post_object', 'relationship', 'taxonomy', 'color_picker', 'date_picker', 'date_time_picker', 'time_picker', 'link', 'google_map' ], true );
	}

	public static function read_fields( WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) { return []; }
		$catalog = self::field_catalog( $post->post_type, $post->ID );
		$result = [];
		foreach ( $catalog['meta'] as $key => $registration ) {
			if ( current_user_can( 'edit_post_meta', $post->ID, $key ) ) {
				$value = get_post_meta( $post->ID, $key, true );
				$schema = is_array( $registration['show_in_rest'] ?? false ) ? ( $registration['show_in_rest']['schema'] ?? [] ) : [];
				$schema['type'] = $schema['type'] ?? ( $registration['type'] ?? 'string' );
				$schema = self::closed_meta_schema( $schema );
				if ( ! is_wp_error( rest_validate_value_from_schema( $value, $schema, $key ) ) ) { $result[ $key ] = $value; }
			}
		}
		$acf = [];
		foreach ( $catalog['acf'] as $name => $field ) {
			if ( self::can_write_acf( $post->post_type, $post->ID, $name ) ) { $acf[ $name ] = self::acf_response_value( get_field( $field['key'], $post->ID, false ), $field ); }
		}
		if ( $acf ) { $result['acf'] = $acf; }
		return $result;
	}

	/** Preserve raw IDs/storage formats but expose stable names instead of ACF's internal field keys. */
	private static function acf_response_value( $value, array $field ) {
		$type = $field['type'] ?? '';
		if ( 'group' === $type ) {
			$result = [];
			foreach ( (array) ( $field['sub_fields'] ?? [] ) as $child ) {
				if ( ! self::safe_acf_field( $child ) ) { continue; }
				$child_value = is_array( $value ) ? ( $value[ $child['name'] ] ?? ( $value[ $child['key'] ] ?? null ) ) : null;
				$result[ $child['name'] ] = self::acf_response_value( $child_value, $child );
			}
			return $result;
		}
		if ( in_array( $type, [ 'repeater', 'flexible_content' ], true ) ) {
			$result = [];
			foreach ( is_array( $value ) ? $value : [] as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$children = $field['sub_fields'] ?? [];
				$layout_name = null;
				if ( 'flexible_content' === $type ) {
					$layout_name = $row['acf_fc_layout'] ?? '';
					foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) { if ( ( $layout['name'] ?? '' ) === $layout_name ) { $children = $layout['sub_fields'] ?? []; break; } }
				}
				$next = self::acf_response_value( $row, [ 'type' => 'group', 'sub_fields' => $children ] );
				if ( null !== $layout_name ) { $next['acf_fc_layout'] = $layout_name; }
				$result[] = $next;
			}
			return $result;
		}
		if ( 'true_false' === $type ) { return (bool) $value; }
		if ( in_array( $type, [ 'image', 'file', 'post_object', 'taxonomy', 'gallery', 'relationship' ], true ) ) {
			$multiple = in_array( $type, [ 'gallery', 'relationship' ], true ) || ! empty( $field['multiple'] ) || ( 'taxonomy' === $type && in_array( $field['field_type'] ?? '', [ 'checkbox', 'multi_select' ], true ) );
			return $multiple ? array_map( 'intval', is_array( $value ) ? array_values( $value ) : [] ) : (int) $value;
		}
		if ( in_array( $type, [ 'number', 'range' ], true ) ) { return is_numeric( $value ) ? 0 + $value : ''; }
		if ( 'checkbox' === $type || ( 'select' === $type && ! empty( $field['multiple'] ) ) ) { return is_array( $value ) ? array_values( $value ) : []; }
		if ( in_array( $type, [ 'link', 'google_map' ], true ) ) { return is_array( $value ) ? $value : []; }
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function can_write_acf( string $post_type, int $post_id, string $name ): bool {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) );
		if ( $post_id && ! current_user_can( 'edit_post_meta', $post_id, $name ) ) { return false; }
		if ( ! $post_id && isset( $registered[ $name ] ) ) { return self::can_create_meta( $post_type, $name, $registered[ $name ] ); }
		return true;
	}

	/** On a create, object-specific authorization cannot be assumed from edit_posts. */
	private static function can_create_meta( string $post_type, string $key, array $registration ): bool {
		$callback = $registration['auth_callback'] ?? null;
		return is_callable( $callback ) && (bool) call_user_func( $callback, false, $key, 0, get_current_user_id(), 'edit_post_meta', [ get_post_type_object( $post_type )->cap->create_posts ] );
	}

	/** Validate every key and nested value before native content is written. */
	public static function validate_payload( $value, string $post_type, int $post_id = 0, string $template = '' ) {
		if ( ! is_array( $value ) || ( $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) ) { return self::error( 'meta_all must be an object.' ); }
		if ( strlen( wp_json_encode( $value ) ) > 2097152 || count( $value ) > 500 ) { return self::error( 'meta_all exceeds the field or size limit.' ); }
		$catalog = self::field_catalog( $post_type, $post_id, $template );
		$result = [ 'acf' => [], 'meta' => [] ];
		$acf_input = isset( $value['acf'] ) ? $value['acf'] : [];
		if ( array_key_exists( 'acf', $value ) && ( ! is_array( $acf_input ) || ( $acf_input && array_keys( $acf_input ) === range( 0, count( $acf_input ) - 1 ) ) ) ) { return self::error( 'meta_all.acf must be an object of top-level field names.' ); }
		foreach ( $value as $key => $item ) {
			if ( 'acf' === $key ) { continue; }
			if ( isset( $catalog['acf'][ $key ] ) ) {
				if ( array_key_exists( $key, $acf_input ) ) { return self::error( 'An ACF field was supplied twice: ' . $key ); }
				$acf_input[ $key ] = $item; continue;
			}
			if ( ! isset( $catalog['meta'][ $key ] ) ) { return self::error( 'Unknown or protected meta_all field: ' . $key ); }
			$registration = $catalog['meta'][ $key ];
			$allowed = $post_id ? current_user_can( 'edit_post_meta', $post_id, $key ) : self::can_create_meta( $post_type, $key, $registration );
			if ( ! $allowed ) { return self::error( 'Meta authorization denied: ' . $key . ( $post_id ? '' : '. Create a draft first, then update this field using its ID.' ), 403 ); }
			$schema = is_array( $registration['show_in_rest'] ?? false ) ? ( $registration['show_in_rest']['schema'] ?? [] ) : [];
			$schema['type'] = $schema['type'] ?? ( $registration['type'] ?? 'string' );
			if ( in_array( $schema['type'], [ 'array', 'object' ], true ) && empty( $schema['items'] ) && empty( $schema['properties'] ) ) { return self::error( 'Structured registered meta requires an explicit schema: ' . $key ); }
			$schema = self::closed_meta_schema( $schema );
			$valid = rest_validate_value_from_schema( $item, $schema, 'meta_all.' . $key );
			if ( is_wp_error( $valid ) ) { return $valid; }
			$result['meta'][ $key ] = rest_sanitize_value_from_schema( $item, $schema, 'meta_all.' . $key );
		}
		foreach ( $acf_input as $name => $item ) {
			if ( ! isset( $catalog['acf'][ $name ] ) ) { return self::error( 'Unknown, protected, or inapplicable ACF field: ' . $name ); }
			if ( ! self::can_write_acf( $post_type, $post_id, $name ) ) { return self::error( 'ACF meta authorization denied: ' . $name, 403 ); }
			$field = $catalog['acf'][ $name ];
			$normalized = self::validate_acf_value( $item, $field, 'meta_all.acf.' . $name );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
			if ( function_exists( 'acf_validate_value' ) && ! acf_validate_value( $normalized, $field, 'meta_all.acf.' . $name ) ) { return self::error( 'ACF validation failed for ' . $name . '.' ); }
			$result['acf'][ $name ] = [ 'field' => $field, 'value' => $normalized ];
		}
		return $result;
	}

	private static function closed_meta_schema( array $schema ): array {
		if ( 'object' === ( $schema['type'] ?? '' ) || isset( $schema['properties'] ) ) { $schema['additionalProperties'] = false; }
		foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $property ) {
			if ( ! self::safe_key( $key ) ) { unset( $schema['properties'][ $key ] ); continue; }
			$schema['properties'][ $key ] = self::closed_meta_schema( $property );
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) { $schema['items'] = self::closed_meta_schema( $schema['items'] ); }
		return $schema;
	}

	private static function validate_acf_value( $value, array $field, string $path, int $depth = 0 ) {
		if ( $depth > 12 ) { return self::error( 'ACF nesting limit exceeded: ' . $path ); }
		$type = $field['type'];
		if ( in_array( $type, [ 'group', 'repeater', 'flexible_content' ], true ) ) {
			if ( ! is_array( $value ) ) { return self::error( $path . ' requires a structured ' . $type . ' value.' ); }
			if ( 'group' === $type ) { return self::validate_acf_children( $value, $field['sub_fields'] ?? [], $path, $depth ); }
			if ( count( $value ) > 500 || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) { return self::error( $path . ' requires a list of at most 500 rows.' ); }
			$out = [];
			foreach ( $value as $index => $row ) {
				if ( ! is_array( $row ) ) { return self::error( $path . ' contains a non-object row.' ); }
				$children = $field['sub_fields'] ?? [];
				$layout_name = null;
				if ( 'flexible_content' === $type ) {
					$layout_name = $row['acf_fc_layout'] ?? null;
					$layout = null;
					foreach ( (array) $field['layouts'] as $candidate ) { if ( ( $candidate['name'] ?? '' ) === $layout_name ) { $layout = $candidate; break; } }
					if ( ! $layout ) { return self::error( $path . ' has an unknown acf_fc_layout.' ); }
					$children = $layout['sub_fields'] ?? [];
					unset( $row['acf_fc_layout'] );
				}
				$normalized = self::validate_acf_children( $row, $children, $path . '.' . $index, $depth );
				if ( is_wp_error( $normalized ) ) { return $normalized; }
				if ( null !== $layout_name ) { $normalized['acf_fc_layout'] = $layout_name; }
				$out[] = $normalized;
			}
			return $out;
		}
		if ( 'true_false' === $type ) { return is_bool( $value ) || in_array( $value, [ 0, 1 ], true ) ? (int) $value : self::error( $path . ' must be boolean.' ); }
		if ( in_array( $type, [ 'number', 'range' ], true ) ) {
			if ( '' === $value && empty( $field['required'] ) ) { return ''; }
			if ( ! is_int( $value ) && ! is_float( $value ) ) { return self::error( $path . ' must be a number.' ); }
			if ( ( isset( $field['min'] ) && '' !== $field['min'] && $value < $field['min'] ) || ( isset( $field['max'] ) && '' !== $field['max'] && $value > $field['max'] ) ) { return self::error( $path . ' is outside the allowed range.' ); }
			return $value;
		}
		if ( in_array( $type, [ 'image', 'file', 'gallery', 'post_object', 'relationship', 'taxonomy' ], true ) ) {
			$multiple = in_array( $type, [ 'gallery', 'relationship' ], true ) || ! empty( $field['multiple'] ) || ( 'taxonomy' === $type && in_array( $field['field_type'] ?? '', [ 'checkbox', 'multi_select' ], true ) );
			$ids = $multiple ? $value : [ $value ];
			if ( ! is_array( $ids ) || count( $ids ) > 500 ) { return self::error( $path . ' requires IDs.' ); }
			foreach ( $ids as $id ) { if ( ! is_int( $id ) || $id < 0 ) { return self::error( $path . ' requires non-negative integer IDs.' ); } }
			foreach ( $ids as $id ) {
				if ( 0 === $id ) { continue; }
				if ( 'taxonomy' === $type ) {
					$taxonomy = get_taxonomy( $field['taxonomy'] ?? '' );
					if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->assign_terms ) || ! term_exists( $id, $taxonomy->name ) ) { return self::error( $path . ' includes a term that cannot be assigned.', 403 ); }
				} else {
					$related = get_post( $id );
					if ( ! $related || ! current_user_can( 'read_post', $id ) || ( in_array( $type, [ 'image', 'file', 'gallery' ], true ) && 'attachment' !== $related->post_type ) ) { return self::error( $path . ' includes an invalid or unreadable content ID.', 403 ); }
				}
			}
			return $value;
		}
		if ( in_array( $type, [ 'select', 'radio', 'checkbox', 'button_group' ], true ) ) {
			$multiple = 'checkbox' === $type || ! empty( $field['multiple'] );
			$choices = $multiple ? $value : [ $value ];
			if ( ! is_array( $choices ) ) { return self::error( $path . ' requires a list of choices.' ); }
			foreach ( $choices as $choice ) { if ( ! is_scalar( $choice ) || ( ! array_key_exists( $choice, (array) ( $field['choices'] ?? [] ) ) && ! ( '' === $choice && ! empty( $field['allow_null'] ) ) ) ) { return self::error( $path . ' contains an unknown choice.' ); } }
			return $value;
		}
		if ( in_array( $type, [ 'link', 'google_map' ], true ) ) {
			$allowed = 'link' === $type ? [ 'url', 'title', 'target' ] : [ 'address', 'lat', 'lng', 'zoom', 'place_id', 'name', 'street_number', 'street_name', 'city', 'state', 'post_code', 'country', 'country_short', 'state_short', 'street_name_short' ];
			if ( ! is_array( $value ) || array_diff( array_keys( $value ), $allowed ) ) { return self::error( $path . ' has an invalid object shape.' ); }
			foreach ( $value as $key => &$part ) { if ( ! is_scalar( $part ) ) { return self::error( $path . ' has a non-scalar property.' ); } $part = is_string( $part ) ? sanitize_text_field( $part ) : $part; }
			unset( $part );
			if ( isset( $value['url'] ) && ! preg_match( '~^(?:https?://|/|#)~i', $value['url'] ) ) { return self::error( $path . ' has an invalid link URL.' ); }
			if ( isset( $value['target'] ) && ! in_array( $value['target'], [ '', '_self', '_blank' ], true ) ) { return self::error( $path . ' has an invalid link target.' ); }
			return $value;
		}
		if ( ! is_string( $value ) ) { return self::error( $path . ' must be text.' ); }
		$date_formats = [ 'date_picker' => 'Ymd', 'date_time_picker' => 'Y-m-d H:i:s', 'time_picker' => 'H:i:s' ];
		if ( isset( $date_formats[ $type ] ) && '' !== $value ) {
			$date = DateTime::createFromFormat( '!' . $date_formats[ $type ], $value );
			if ( ! $date || $date->format( $date_formats[ $type ] ) !== $value ) { return self::error( $path . ' requires the raw storage format ' . $date_formats[ $type ] . '.' ); }
		}
		if ( 'email' === $type && '' !== $value && ! is_email( $value ) ) { return self::error( $path . ' must be an email address.' ); }
		if ( in_array( $type, [ 'url', 'oembed' ], true ) && '' !== $value && ! preg_match( '~^https?://~i', $value ) ) { return self::error( $path . ' must be an HTTP(S) URL.' ); }
		return in_array( $type, [ 'wysiwyg', 'textarea' ], true ) ? wp_kses_post( $value ) : sanitize_text_field( $value );
	}

	private static function validate_acf_children( array $value, array $children, string $path, int $depth ) {
		$known = [];
		foreach ( $children as $child ) { if ( self::safe_acf_field( $child ) ) { $known[ $child['name'] ] = $child; } }
		$missing = array_diff( array_keys( $known ), array_keys( $value ) );
		if ( $missing ) { return self::error( $path . ' requires the complete parent value. Missing fields: ' . implode( ', ', $missing ) . '. Read the current item and retain existing sibling values.' ); }
		$out = [];
		foreach ( $value as $name => $item ) {
			if ( ! isset( $known[ $name ] ) ) { return self::error( 'Unknown nested ACF field: ' . $path . '.' . $name ); }
			$out[ $known[ $name ]['key'] ] = self::validate_acf_value( $item, $known[ $name ], $path . '.' . $name, $depth + 1 );
			if ( is_wp_error( $out[ $known[ $name ]['key'] ] ) ) { return $out[ $known[ $name ]['key'] ]; }
		}
		return $out;
	}

	public static function write_fields( WP_Post $post, array $payload ) {
		// Re-check object-scoped permissions after a create; an ID now exists.
		foreach ( $payload['meta'] as $key => $value ) { if ( ! current_user_can( 'edit_post_meta', $post->ID, $key ) ) { return self::error( 'Meta authorization denied: ' . $key, 403 ); } }
		foreach ( $payload['acf'] as $name => $entry ) { if ( ! self::can_write_acf( $post->post_type, $post->ID, $name ) ) { return self::error( 'ACF authorization denied: ' . $name, 403 ); } }
		foreach ( $payload['meta'] as $key => $value ) {
			$updated = update_post_meta( $post->ID, $key, wp_slash( $value ) );
			if ( ! $updated && get_post_meta( $post->ID, $key, true ) != sanitize_meta( $key, $value, 'post', $post->post_type ) ) { return self::error( 'The database did not save ' . $key . '.', 500 ); }
		}
		foreach ( $payload['acf'] as $name => $entry ) {
			// Field keys initialize references correctly even for new group/repeater/flexible values.
			update_field( $entry['field']['key'], $entry['value'], $post->ID );
			if ( function_exists( 'acf_flush_value_cache' ) ) { acf_flush_value_cache( $post->ID, $name ); }
			$stored = get_field( $entry['field']['key'], $post->ID, false );
			if ( ! self::acf_value_matches( $entry['value'], $stored, $entry['field'] ) ) { return self::error( 'ACF did not retain the supplied value for ' . $name . '. A field save filter or database error may have rejected it.', 500 ); }
		}
		return true;
	}

	/** ACF returns named children and string IDs, while updates use field keys. */
	private static function acf_value_matches( $expected, $actual, array $field ): bool {
		$type = $field['type'] ?? '';
		if ( in_array( $type, [ 'group', 'repeater', 'flexible_content' ], true ) ) {
			if ( [] === $expected && in_array( $actual, [ false, null, '', [] ], true ) ) { return true; }
			if ( ! is_array( $expected ) || ! is_array( $actual ) ) { return false; }
			if ( 'group' === $type ) {
				foreach ( (array) ( $field['sub_fields'] ?? [] ) as $child ) {
					if ( ! self::safe_acf_field( $child ) ) { continue; }
					$name = $child['name']; $key = $child['key'];
					if ( ! array_key_exists( $name, $actual ) && ! array_key_exists( $key, $actual ) ) { return false; }
					if ( ! self::acf_value_matches( $expected[ $key ] ?? ( $expected[ $name ] ?? null ), $actual[ $name ] ?? ( $actual[ $key ] ?? null ), $child ) ) { return false; }
				}
				return true;
			}
			if ( count( $expected ) !== count( $actual ) ) { return false; }
			foreach ( $expected as $index => $row ) {
				$children = $field['sub_fields'] ?? [];
				if ( 'flexible_content' === $type ) {
					if ( ( $actual[ $index ]['acf_fc_layout'] ?? null ) !== ( $row['acf_fc_layout'] ?? null ) ) { return false; }
					foreach ( (array) ( $field['layouts'] ?? [] ) as $layout ) { if ( ( $layout['name'] ?? '' ) === $row['acf_fc_layout'] ) { $children = $layout['sub_fields'] ?? []; break; } }
				}
				if ( ! self::acf_value_matches( $row, $actual[ $index ] ?? null, [ 'type' => 'group', 'sub_fields' => $children ] ) ) { return false; }
			}
			return true;
		}
		if ( 'true_false' === $type ) { return (int) $expected === (int) $actual; }
		if ( in_array( $actual, [ null, false, '' ], true ) && in_array( $expected, [ '', [], 0 ], true ) ) { return true; }
		if ( is_array( $expected ) && is_array( $actual ) ) {
			if ( ! in_array( $type, [ 'google_map', 'link' ], true ) && count( $expected ) !== count( $actual ) ) { return false; }
			foreach ( $expected as $key => $value ) { if ( ! array_key_exists( $key, $actual ) || ! is_scalar( $value ) || ! is_scalar( $actual[ $key ] ) || (string) $value !== (string) $actual[ $key ] ) { return false; } }
			return true;
		}
		return is_scalar( $expected ) && is_scalar( $actual ) && (string) $expected === (string) $actual;
	}

	public static function error( string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'nova_content_invalid_field', $message, [ 'status' => $status ] );
	}
}

/** Core handles native fields and permission rules; this controller owns only its safe field surface. */
class Nova_Bridge_Suite_Content_Controller extends WP_REST_Posts_Controller {
	public function __construct( $post_type ) { parent::__construct( $post_type ); $this->namespace = 'nova-bridge/v1'; $this->rest_base = 'content/' . $post_type; }

	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_items' ], 'permission_callback' => [ $this, 'get_items_permissions_check' ], 'args' => $this->get_collection_params() ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_item' ], 'permission_callback' => [ $this, 'create_item_permissions_check' ], 'args' => $this->get_endpoint_args_for_item_schema( 'POST' ) ],
			'schema' => [ $this, 'get_public_item_schema' ],
		] );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\\d]+)', [
			'args' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_item' ], 'permission_callback' => [ $this, 'get_item_permissions_check' ], 'args' => [ 'context' => [ 'type' => 'string', 'default' => 'edit', 'enum' => [ 'edit' ] ] ] ],
			[ 'methods' => 'POST, PUT, PATCH', 'callback' => [ $this, 'update_item' ], 'permission_callback' => [ $this, 'update_item_permissions_check' ], 'args' => $this->get_endpoint_args_for_item_schema( 'PUT' ) ],
			'schema' => [ $this, 'get_public_item_schema' ],
		] );
	}

	protected function get_additional_fields( $object_type = null ) { return []; }
	protected function check_is_post_type_allowed( $post_type ) { return Nova_Bridge_Suite_Content_Transport::supports_post_type( $post_type ); }
	public function check_read_permission( $post ) { return $post instanceof WP_Post && $this->check_is_post_type_allowed( $post->post_type ) && current_user_can( 'edit_post', $post->ID ); }
	public function get_items_permissions_check( $request ) {
		$type = get_post_type_object( $this->post_type );
		return is_user_logged_in() && $type && current_user_can( $type->cap->edit_posts ) ? true : new WP_Error( 'rest_forbidden', 'An authenticated editor is required.', [ 'status' => rest_authorization_required_code() ] );
	}
	public function get_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) { return $post; }
		return $this->check_read_permission( $post ) ? true : new WP_Error( 'rest_forbidden', 'You cannot edit this content item.', [ 'status' => rest_authorization_required_code() ] );
	}
	public function get_collection_params() {
		$params = parent::get_collection_params();
		$params['context'] = [ 'type' => 'string', 'default' => 'edit', 'enum' => [ 'edit' ] ];
		return $params;
	}
	public function get_item_schema() {
		$schema = parent::get_item_schema();
		unset( $schema['properties']['meta'], $schema['properties']['password'] );
		$schema['properties']['meta_all'] = [ 'type' => 'object', 'context' => [ 'edit' ], 'description' => 'Only registered editorial fields. ACF/SCF uses meta_all.acf.FIELD_NAME with complete structured parent values. Unknown and protected keys are rejected before writing.', 'additionalProperties' => true ];
		return $schema;
	}

	private function preflight( WP_REST_Request $request, int $post_id ) {
		$body = $request->get_params();
		$allowed = $this->get_endpoint_args_for_item_schema( $post_id ? 'PUT' : 'POST' );
		foreach ( $body as $key => $value ) { if ( ! isset( $allowed[ $key ] ) && ! in_array( $key, [ 'id', 'context', '_fields', '_locale' ], true ) ) { return Nova_Bridge_Suite_Content_Transport::error( 'Unknown or read-only content field: ' . $key ); } }
		if ( $post_id && $request->has_param( 'template' ) && (string) $request['template'] !== (string) get_page_template_slug( $post_id ) && $request->has_param( 'meta_all' ) ) {
			return Nova_Bridge_Suite_Content_Transport::error( 'Change the template first, then send fields for that template in a separate request.' );
		}
		return Nova_Bridge_Suite_Content_Transport::validate_payload( $request->has_param( 'meta_all' ) ? $request['meta_all'] : [], $this->post_type, $post_id, (string) ( $request['template'] ?? '' ) );
	}
	private function native_request( WP_REST_Request $request ): WP_REST_Request {
		$native = clone $request;
		unset( $native['meta_all'] );
		$native['context'] = 'edit';
		return $native;
	}
	public function create_item( $request ) {
		$payload = $this->preflight( $request, 0 );
		if ( is_wp_error( $payload ) ) { return $payload; }
		// Hold publication until the custom fields have been saved successfully.
		$native = $this->native_request( $request );
		$prepared = $this->prepare_item_for_database( $native );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$desired_status = $native['status'] ?? 'draft';
		$native['status'] = 'draft';
		$response = parent::create_item( $native );
		if ( is_wp_error( $response ) ) { return $response; }
		$id = (int) $response->get_data()['id'];
		$post = get_post( $id );
		$result = Nova_Bridge_Suite_Content_Transport::write_fields( $post, $payload );
		if ( is_wp_error( $result ) ) { $result->add_data( [ 'status' => 500, 'post_id' => $id, 'post_status' => 'draft', 'message' => 'A draft was retained for recovery; publication did not run.' ] ); return $result; }
		if ( 'draft' !== $desired_status ) {
			$publish = new WP_REST_Request( 'PATCH', $request->get_route() . '/' . $id );
			$publish->set_param( 'id', $id ); $publish->set_param( 'status', $desired_status ); $publish->set_param( 'context', 'edit' );
			$check = parent::update_item_permissions_check( $publish );
			if ( is_wp_error( $check ) ) { return $check; }
			$result = parent::update_item( $publish );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		$response = $this->prepare_item_for_response( get_post( $id ), $this->native_request( $request ) );
		$response->set_status( 201 );
		$response->header( 'Location', rest_url( $this->namespace . '/' . $this->rest_base . '/' . $id ) );
		return $response;
	}
	public function update_item( $request ) {
		$payload = $this->preflight( $request, (int) $request['id'] );
		if ( is_wp_error( $payload ) ) { return $payload; }
		$response = parent::update_item( $this->native_request( $request ) );
		if ( is_wp_error( $response ) ) { return $response; }
		$result = Nova_Bridge_Suite_Content_Transport::write_fields( get_post( $request['id'] ), $payload );
		if ( is_wp_error( $result ) ) { $result->add_data( [ 'status' => 500, 'post_id' => (int) $request['id'], 'partial_write' => true ] ); return $result; }
		return $this->prepare_item_for_response( get_post( $request['id'] ), $this->native_request( $request ) );
	}
	public function prepare_item_for_response( $item, $request ) {
		$request['context'] = 'edit';
		$response = parent::prepare_item_for_response( $item, $request );
		$data = $response->get_data();
		unset( $data['meta'], $data['acf'], $data['password'] );
		$data['meta_all'] = (object) Nova_Bridge_Suite_Content_Transport::read_fields( $item );
		$data['nova_transport'] = [ 'id' => 'nova_content_bridge', 'route' => '/' . $this->namespace . '/' . $this->rest_base . '/' . $item->ID, 'methods' => [ 'POST', 'PUT', 'PATCH' ], 'native_show_in_rest' => (bool) get_post_type_object( $this->post_type )->show_in_rest ];
		if ( class_exists( 'Nova_Bridge_Suite_Content_Context' ) ) {
			$data['meta_descriptions'] = Nova_Bridge_Suite_Content_Context::get_generic_meta_descriptions( $this->post_type, [ 'id' => $item->ID ], $request );
			$data['nova_content_mappings'] = Nova_Bridge_Suite_Content_Context::get_generic_content_mappings( 'post_type', $this->post_type, [ 'id' => $item->ID ], $request );
			$data['nova_template_contexts'] = Nova_Bridge_Suite_Content_Context::get_generic_template_contexts( $this->post_type, [ 'id' => $item->ID ], $request );
		}
		$data = apply_filters( 'nova_bridge_strategy_decorate_record', $data, 'post', (int) $item->ID );
		$response->set_data( $data );
		return $response;
	}
}
