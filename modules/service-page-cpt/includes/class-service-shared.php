<?php
/** Site-wide reusable sections and the default native service layout. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceShared {
	public const OPTION_PRESETS = 'nova_service_shared_sections';
	public const OPTION_TEMPLATE = 'nova_service_template';
	public const OPTION_TRANSLATIONS = 'nova_service_shared_translations';

	public static function bootstrap(): void {
		// The content posting context is registered by ServiceDocument and includes
		// this library. Settings writes are managed by the nonce-protected admin UI.
	}

	public static function get_base_presets(): array {
		$active = class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) ? ServiceOnboarding::active_settings() : null;
		$value = $active ? $active['presets'] : \get_option( self::OPTION_PRESETS, [] );
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

	/** Missing language fields follow the default language; explicit empty fields clear. */
	public static function get_presets( int $post_id = 0, ?string $language = null ): array {
		$document = self::get_base_presets();
		if ( ! class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ) { return $document; }
		$language = ServiceLanguages::current( $post_id, $language );
		if ( ! ServiceLanguages::is_localized( $language ) ) { return $document; }
		$translations = \get_option( self::OPTION_TRANSLATIONS, [] );
		$value = \is_array( $translations ) ? ( $translations[ $language ] ?? [] ) : [];
		if ( ! \is_array( $value ) || ! isset( $value['sections'] ) ) { return $document; }
		$overlays = \array_column( ServiceDocument::sanitize_document( $value )['sections'], null, 'id' );
		$raw_overlays = \array_column( (array) $value['sections'], null, 'id' );
		$fields = \array_flip( self::content_fields() );
		foreach ( $document['sections'] as &$section ) {
			$overlay = $overlays[ $section['id'] ] ?? null;
			if ( ! $overlay || $overlay['type'] !== $section['type'] ) { continue; }
			$present = \array_intersect_key( (array) ( $raw_overlays[ $section['id'] ]['data'] ?? [] ), $fields );
			$section['data'] = \array_replace( (array) $section['data'], \array_intersect_key( (array) $overlay['data'], $present ) );
		} unset( $section );
		return $document;
	}

	public static function content_fields(): array {
		return \array_values( \array_diff( \array_keys( ServiceDocument::document_schema()['properties']['sections']['items']['properties']['data']['properties'] ), [ 'heading_level', 'layout', 'width', 'image_selection', 'link_sources' ] ) );
	}

	public static function presets(): array { return self::get_presets(); }

	public static function get_template(): array {
		$active = class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) ? ServiceOnboarding::active_settings() : null;
		$value = $active ? $active['template'] : \get_option( self::OPTION_TEMPLATE, [] );
		return is_array( $value ) && isset( $value['sections'] ) ? ServiceDocument::sanitize_document( $value ) : [];
	}

	public static function template(): array { return self::get_template(); }

	/** Validate both settings first so a bad template cannot partially save a library. */
	public static function validate_settings( $presets, $template ) {
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
		return true;
	}

	public static function save_settings( $presets, $template, ?string $language = null ) {
		if ( ! \current_user_can( 'manage_options' ) ) { return new \WP_Error( 'nova_service_settings_forbidden', 'You cannot manage the shared service library.', [ 'status' => 403 ] ); }
		if ( class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) && ServiceOnboarding::is_required() ) { return ServiceOnboarding::pending_error(); }
		if ( class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ) { $language = ServiceLanguages::validate( $language ); if ( \is_wp_error( $language ) ) { return $language; } }
		$valid = self::validate_settings( $presets, $template );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		$provided = $presets;
		$presets = ServiceDocument::sanitize_document( $presets );
		$template = [] === $template ? [] : ServiceDocument::sanitize_document( $template );
		if ( class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) && ServiceLanguages::is_localized( $language ) ) { return self::save_translation( $presets, $template, $language, $provided ); }
		if ( class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) && ServiceOnboarding::active_settings() ) { return ServiceOnboarding::save_active_settings( $presets, $template ); }
		\update_option( self::OPTION_PRESETS, $presets, false );
		\update_option( self::OPTION_TEMPLATE, $template, false );
		return true;
	}

	/** Translation editors cannot change shared ordering, visibility or presentation. */
	private static function save_translation( array $presets, array $template, string $language, array $provided ) {
		$base = self::get_base_presets();
		if ( \serialize( $template ) !== \serialize( self::get_template() ) || \array_column( $presets['sections'], 'id' ) !== \array_column( $base['sections'], 'id' ) ) { return self::translation_structure_error(); }
		$translations = \get_option( self::OPTION_TRANSLATIONS, [] ); $translations = \is_array( $translations ) ? $translations : [];
		$existing = \array_column( (array) ( $translations[ $language ]['sections'] ?? [] ), null, 'id' );
		$fields = \array_flip( self::content_fields() ); $translated = [ 'version' => 2, 'sections' => [] ];
		foreach ( $base['sections'] as $index => $section ) {
			$incoming = $presets['sections'][ $index ];
			if ( $incoming['type'] !== $section['type'] || $incoming['enabled'] !== $section['enabled'] || ( $incoming['content_slot'] ?? '' ) !== ( $section['content_slot'] ?? '' ) ) { return self::translation_structure_error(); }
			$before = (array) $section['data']; $after = (array) $incoming['data'];
			if ( \array_diff_key( $before, $fields ) !== \array_diff_key( $after, $fields ) ) { return self::translation_structure_error(); }
			$data = [];
			$present = \array_intersect_key( (array) $provided['sections'][ $index ]['data'], $fields );
			foreach ( \array_intersect_key( $after, $present ) as $key => $value ) {
				$old_data = (array) ( $existing[ $section['id'] ]['data'] ?? [] );
				$empty = '' === $value || ( ( \is_array( $value ) || \is_object( $value ) ) && ! (array) $value );
				// An intentional empty remains intentional even if the base is now
				// empty too. Omitting the field still explicitly restores fallback.
				$keep_empty = $empty && \array_key_exists( $key, $old_data ) && \serialize( $old_data[ $key ] ) === \serialize( $value );
				if ( $keep_empty || ! \array_key_exists( $key, $before ) || \serialize( $before[ $key ] ) !== \serialize( $value ) ) { $data[ $key ] = $value; }
			}
			if ( $data ) { $translated['sections'][] = [ 'id' => $section['id'], 'type' => $section['type'], 'enabled' => true, 'data' => $data ]; }
		}
		if ( $translated['sections'] ) { $translations[ $language ] = $translated; } else { unset( $translations[ $language ] ); }
		if ( \serialize( $translations ) === \serialize( \get_option( self::OPTION_TRANSLATIONS, [] ) ) ) { return true; }
		return \update_option( self::OPTION_TRANSLATIONS, $translations, false ) ? true : new \WP_Error( 'nova_service_translation_save_failed', 'Translated service defaults could not be saved.', [ 'status' => 500 ] );
	}

	private static function translation_structure_error() { return new \WP_Error( 'nova_service_translation_structure', 'Page layout, shared section variants, visibility and styling are global. Change them in the default language; translate the existing content in this language.', [ 'status' => 400 ] ); }

	public static function save_presets( $presets, ?string $language = null ) { return self::save_settings( $presets, self::get_template(), $language ); }
	public static function save_template( $template ) { return self::save_settings( self::get_base_presets(), $template, class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ? ServiceLanguages::default_language() : null ); }

	public static function resolve_section( array $section, int $post_id = 0, ?string $language = null ): array {
		if ( empty( $section['shared_key'] ) ) { return $section; }
		foreach ( self::get_presets( $post_id, $language )['sections'] as $preset ) {
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

	public static function warnings( array $document, int $post_id = 0, ?string $language = null ): array {
		$library = array_column( self::get_presets( $post_id, $language )['sections'], null, 'id' );
		$warnings = [];
		foreach ( $document['sections'] ?? [] as $section ) {
			$key = $section['shared_key'] ?? '';
			if ( ! $key ) { continue; }
			if ( ! isset( $library[ $key ] ) ) { $warnings[] = 'Shared section ' . $key . ' is missing. Only local overrides are available until it is restored or replaced.'; }
			elseif ( $library[ $key ]['type'] !== $section['type'] ) { $warnings[] = 'Shared section ' . $key . ' has a different type. It cannot be inherited by ' . $section['id'] . '.'; }
		}
		return array_values( array_unique( $warnings ) );
	}

	public static function context( int $post_id = 0, ?string $language = null ): array {
		$presets = ServiceDocument::public_document( self::get_presets( $post_id, $language ) );
		$summaries = [];
		foreach ( $presets['sections'] as $preset ) {
			$data = (array) $preset['data'];
			$summaries[] = [ 'key' => $preset['id'], 'type' => $preset['type'], 'title' => $data['title'] ?? '', 'fields' => array_keys( $data ), 'image_count' => count( $data['images'] ?? [] ), 'item_count' => count( $data['items'] ?? [] ) ];
		}
		$template = ServiceDocument::public_document( self::get_template() );
		return [
			'presets' => $summaries, 'template' => $template,
			'warnings' => self::warnings( $template, $post_id, $language ),
			'languages' => class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ? ServiceLanguages::context( $language, $post_id ) : [ 'active' => false ],
			'language_guidance' => 'Shared content follows the assigned language of the target service page. Missing translated fields fall back to default-language content; explicit empty translated values clear fields. Shared variants, section order, visibility, layout and styling are global. Providers do not need to resupply or translate client defaults when posting service-specific copy.',
			'site_owned_defaults' => [ 'cta', 'custom_cta', 'credentials', 'reviews', 'people', 'location', 'map', 'video' ],
			'automatic_related_services' => [ 'default' => 'Up to three published, unprotected services in the page language, excluding the current page. Shared public taxonomy terms are preferred; recent service pages fill remaining places.', 'overrides' => 'Nonempty related post_ids or custom items are optional manual overrides for existing pages. Both empty or missing resumes automatic selection. Disable the section to hide related services.' ],
			'guidance' => 'The site layout selects reusable CTA, team, location, map, proof, form and video variants once. New service pages inherit it. Providers should submit nova_service_content for service-specific copy and FAQ; shared media, CTAs, forms and proof do not need to be reselected. Related service cards are selected automatically and their IDs do not belong in routine posting payloads. Nonempty document post_ids or custom link items remain optional manual overrides; with both empty or missing, automatic selection resumes. Disable the related section to hide it. A section shared_key follows the matching preset ID and type. Local data keys override entire shared fields, including explicit empty values; missing keys continue to inherit. Disabling a preset hides all its linked sections; local disabled sections also remain hidden. Presets cannot reference other presets. Existing pages follow shared content when linked, but their section order only changes through an explicit page edit.',
		];
	}
}
