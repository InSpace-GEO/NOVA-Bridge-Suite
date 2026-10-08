<?php
/** Site-wide reusable sections and the default native service layout. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceShared {
	public const OPTION_PRESETS = 'nova_service_shared_sections';
	public const OPTION_TEMPLATE = 'nova_service_template';

	public static function bootstrap(): void {
		// The content posting context is registered by ServiceDocument and includes
		// this library. Settings writes are managed by the nonce-protected admin UI.
	}

	public static function get_presets(): array {
		$value = \get_option( self::OPTION_PRESETS, [] );
		$document = is_array( $value ) && isset( $value['sections'] ) ? ServiceDocument::sanitize_document( $value ) : [ 'version' => 2, 'sections' => [] ];
		$ids = array_column( $document['sections'], 'id' );
		// Lazy empty client defaults are visible in settings without writing options
		// during an upgrade. Existing configured variants and templates stay intact.
		foreach ( [ 'hero', 'cta', 'custom_cta', 'credentials', 'reviews', 'location', 'map', 'people', 'video' ] as $type ) {
			$id = 'default_' . $type;
			if ( count( $document['sections'] ) >= 60 ) { break; }
			if ( ! in_array( $id, $ids, true ) ) { $document['sections'][] = [ 'id' => $id, 'type' => $type, 'enabled' => true, 'data' => [ 'body' => '' ] ]; }
		}
		return $document;
	}

	public static function presets(): array { return self::get_presets(); }

	public static function get_template(): array {
		$value = \get_option( self::OPTION_TEMPLATE, [] );
		return is_array( $value ) && isset( $value['sections'] ) ? ServiceDocument::sanitize_document( $value ) : [];
	}

	public static function template(): array { return self::get_template(); }

	/** Validate both settings first so a bad template cannot partially save a library. */
	public static function save_settings( $presets, $template ) {
		if ( ! \current_user_can( 'manage_options' ) ) { return new \WP_Error( 'nova_service_settings_forbidden', 'You cannot manage the shared service library.', [ 'status' => 403 ] ); }
		$valid = ServiceDocument::validate_document( $presets );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		$by_id = [];
		foreach ( $presets['sections'] as $preset ) {
			if ( array_key_exists( 'shared_key', $preset ) ) { return new \WP_Error( 'nova_service_recursive_preset', 'Shared library sections cannot reference other shared sections.', [ 'status' => 400 ] ); }
			$by_id[ $preset['id'] ] = $preset;
		}
		if ( [] !== $template ) {
			$valid = ServiceDocument::validate_document( $template );
			if ( \is_wp_error( $valid ) ) { return $valid; }
			foreach ( $template['sections'] as $section ) {
				if ( empty( $section['shared_key'] ) ) { continue; }
				$preset = $by_id[ $section['shared_key'] ] ?? null;
				if ( ! $preset || $preset['type'] !== $section['type'] ) { return new \WP_Error( 'nova_service_missing_preset', 'The site layout references a missing shared section or a different section type: ' . $section['shared_key'], [ 'status' => 400 ] ); }
			}
		}
		\update_option( self::OPTION_PRESETS, ServiceDocument::sanitize_document( $presets ), false );
		\update_option( self::OPTION_TEMPLATE, [] === $template ? [] : ServiceDocument::sanitize_document( $template ), false );
		return true;
	}

	public static function save_presets( $presets ) { return self::save_settings( $presets, self::get_template() ); }
	public static function save_template( $template ) { return self::save_settings( self::get_presets(), $template ); }

	public static function resolve_section( array $section ): array {
		if ( empty( $section['shared_key'] ) ) { return $section; }
		foreach ( self::get_presets()['sections'] as $preset ) {
			if ( $preset['id'] === $section['shared_key'] && $preset['type'] === $section['type'] ) {
				// Merge whole fields, not array members. An explicit empty array clears
				// the collection; an omitted key continues to follow the shared value.
				$section['data'] = array_replace( (array) $preset['data'], (array) $section['data'] );
				$section['enabled'] = (bool) $section['enabled'] && (bool) $preset['enabled'];
				return $section;
			}
		}
		return $section;
	}

	public static function warnings( array $document ): array {
		$library = array_column( self::get_presets()['sections'], null, 'id' );
		$warnings = [];
		foreach ( $document['sections'] ?? [] as $section ) {
			$key = $section['shared_key'] ?? '';
			if ( ! $key ) { continue; }
			if ( ! isset( $library[ $key ] ) ) { $warnings[] = 'Shared section ' . $key . ' is missing. Only local overrides are available until it is restored or replaced.'; }
			elseif ( $library[ $key ]['type'] !== $section['type'] ) { $warnings[] = 'Shared section ' . $key . ' has a different type. It cannot be inherited by ' . $section['id'] . '.'; }
		}
		return array_values( array_unique( $warnings ) );
	}

	public static function context(): array {
		$presets = ServiceDocument::public_document( self::get_presets() );
		$summaries = [];
		foreach ( $presets['sections'] as $preset ) {
			$data = (array) $preset['data'];
			$summaries[] = [ 'key' => $preset['id'], 'type' => $preset['type'], 'title' => $data['title'] ?? '', 'fields' => array_keys( $data ), 'image_count' => count( $data['images'] ?? [] ), 'item_count' => count( $data['items'] ?? [] ) ];
		}
		$template = ServiceDocument::public_document( self::get_template() );
		return [
			'presets' => $summaries, 'template' => $template,
			'warnings' => self::warnings( $template ),
			'site_owned_defaults' => [ 'cta', 'custom_cta', 'credentials', 'reviews', 'people', 'location', 'map', 'video' ],
			'automatic_related_services' => [ 'default' => 'Up to three published, unprotected services in the page language, excluding the current page. Shared public taxonomy terms are preferred; recent service pages fill remaining places.', 'overrides' => 'Nonempty related post_ids or custom items are optional manual overrides for existing pages. Both empty or missing resumes automatic selection. Disable the section to hide related services.' ],
			'guidance' => 'The site layout selects reusable CTA, team, location, map, proof, form and video variants once. New service pages inherit it. Providers should submit nova_service_content for service-specific copy and FAQ; shared media, CTAs, forms and proof do not need to be reselected. Related service cards are selected automatically and their IDs do not belong in routine posting payloads. Nonempty document post_ids or custom link items remain optional manual overrides; with both empty or missing, automatic selection resumes. Disable the related section to hide it. A section shared_key follows the matching preset ID and type. Local data keys override entire shared fields, including explicit empty values; missing keys continue to inherit. Disabling a preset hides all its linked sections; local disabled sections also remain hidden. Presets cannot reference other presets. Existing pages follow shared content when linked, but their section order only changes through an explicit page edit.',
		];
	}
}
