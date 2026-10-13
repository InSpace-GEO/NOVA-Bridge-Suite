<?php
/** Required fresh setup and editable identity for existing Service CPT sites. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceOnboarding {
	public const OPTION_STATE = 'nova_service_onboarding';
	public const OPTION_CONFIGURATION = 'nova_service_client_configuration';
	private const OPTION_LOCK = 'nova_service_onboarding_lock';
	private const STEPS = [ 'business', 'cta', 'content', 'style', 'review' ];
	private static $bootstrapped = false;

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) { return; }
		self::$bootstrapped = true;
		// Run before the old activation callback seeds its factory options.
		self::initialize();
		\add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
		\add_filter( 'wp_insert_post_data', [ self::class, 'guard_publish' ], PHP_INT_MAX, 2 );
		\add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	private static function error( string $message, int $status = 400, string $code = 'nova_service_setup_error' ) {
		return new \WP_Error( $code, $message, [ 'status' => $status, 'setup_url' => self::setup_url() ] );
	}

	public static function setup_url(): string { return \add_query_arg( [ 'post_type' => 'service_page', 'page' => 'nova-service-shared', 'tab' => 'setup' ], \admin_url( 'edit.php' ) ); }

	/** Existing services, even drafts/trash, are an upgrade and are never enrolled. */
	private static function has_services(): bool {
		global $wpdb;
		// Bootstrap can precede other plugins' custom-status registration. Look at
		// stored rows without a status predicate, including orphaned statuses.
		if ( isset( $wpdb ) && \method_exists( $wpdb, 'get_var' ) ) { return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", 'service_page' ) ); }
		$statuses = \function_exists( 'get_post_stati' ) ? \array_values( \get_post_stati() ) : [ 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft' ];
		return (bool) \get_posts( [ 'post_type' => 'service_page', 'post_status' => $statuses, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true ] );
	}

	/** Only exact values seeded by the old activation routine are disregarded. */
	public static function factory_settings(): array {
		$defaults = [
			'content_width' => '1600px', 'wide_width' => '1800px', 'label_language' => 'auto', 'space_scale' => '0.9',
			'space_section_padding' => '3rem 1.25rem', 'space_section_gap' => '18px', 'space_card_padding' => '18px',
			'color_primary' => '#0F172A', 'color_contrast' => '#F8FAFC', 'color_surface' => '#F8FAFC', 'color_text' => '#0F172A',
			'color_accent' => '#2563EB', 'color_border' => '#E2E8F0', 'color_hero_bg' => '#0F172A', 'color_hero_text' => '#F8FAFC',
			'color_cta_bg' => '#111827', 'color_cta_text' => '#F8FAFC', 'color_sidebar_cta_bg' => '#111827', 'color_sidebar_cta_text' => '#F8FAFC',
			'color_wide_cta_bg' => '#111827', 'color_wide_cta_text' => '#F8FAFC', 'color_button_bg' => '#2563EB', 'color_button_text' => '#FFFFFF',
			'color_button_outline' => '#2563EB', 'color_faq_bg' => '#F1F5F9', 'color_faq_question' => '#0F172A', 'color_faq_answer' => '#475569',
			'color_tabs_active_bg' => '#2563EB', 'color_tabs_active_text' => '#FFFFFF', 'color_tabs_inactive_bg' => '#F1F5F9',
			'color_tabs_inactive_text' => '#0F172A', 'color_tabs_border' => '#CBD5E1',
			'archive_hero_eyebrow' => '', 'archive_hero_title' => '', 'archive_hero_copy' => '', 'archive_hero_cta_url' => '#service-cpt-archive-services',
			'archive_intro_heading' => '', 'archive_intro_copy' => '', 'archive_services_mode' => 'auto', 'archive_services_limit' => '0', 'archive_seo_title' => '', 'archive_seo_description' => '',
			'archive_hero_cta_label' => [ 'See our services', 'Bekijk onze diensten', 'Unsere Leistungen ansehen', 'Voir nos services', 'Ver nuestros servicios', 'Ver nossos serviços', 'Vedi i nostri servizi', 'Zobacz nasze usługi', 'Se våra tjänster' ],
			'archive_card_cta_label' => [ 'Read more', 'Lees meer', 'Mehr lesen', 'Lire la suite', 'Leer más', 'Ler mais', 'Leggi di più', 'Czytaj więcej', 'Läs mer' ],
		];
		$result = []; foreach ( $defaults as $key => $value ) { $result[ 'service_cpt_' . $key ] = $value; } return $result;
	}

	private static function has_configuration(): bool {
		$missing = new \stdClass();
		foreach ( [ self::OPTION_CONFIGURATION, ServiceShared::OPTION_PRESETS, ServiceShared::OPTION_TEMPLATE ] as $key ) { if ( $missing !== \get_option( $key, $missing ) ) { return true; } }
		global $wpdb;
		if ( ! isset( $wpdb ) || ! \method_exists( $wpdb, 'get_col' ) ) { return false; }
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'service_cpt_' ) . '%' ) );
		$factory = self::factory_settings();
		foreach ( $names as $key ) {
			// Internal migration timestamps are not customer configuration.
			if ( 'service_cpt_attachment_slug_migration_20260513' === $key ) { continue; }
			$value = \get_option( $key );
			if ( isset( $factory[ $key ] ) || \array_key_exists( $key, $factory ) ) {
				$expected = $factory[ $key ];
				if ( \is_array( $expected ) ? \in_array( $value, $expected, true ) : $value === $expected ) { continue; }
			}
			return true;
		}
		return false;
	}

	/** Shared editors and setup identity use one snapshot to reject stale saves. */
	public static function settings_fingerprint(): string {
		$values = [];
		foreach ( [ self::OPTION_CONFIGURATION, ServiceShared::OPTION_PRESETS, ServiceShared::OPTION_TEMPLATE, 'nova_service_shared_translations' ] as $key ) { $missing = new \stdClass(); $value = \get_option( $key, $missing ); $values[ $key ] = $missing === $value ? [ 'exists' => false ] : [ 'exists' => true, 'value' => $value ]; }
		// Upgrade sites keep their established preset/template storage. Their new
		// identity lives in the existing lifecycle option rather than a second set
		// of active defaults. Draft progress is deliberately not part of this hash.
		$state = \get_option( self::OPTION_STATE, [] );
		$values['business'] = \is_array( $state ) ? ( $state['business'] ?? ( 'upgrade' === ( $state['classification'] ?? '' ) ? ( $state['draft']['business'] ?? null ) : null ) ) : null;
		return \hash( 'sha256', \serialize( $values ) );
	}

	public static function initialize(): void {
		$missing = new \stdClass();
		if ( $missing !== \get_option( self::OPTION_STATE, $missing ) ) { return; }
		$reason = self::has_services() ? 'existing_services' : ( self::has_configuration() ? 'existing_configuration' : 'fresh_install' );
		\add_option( self::OPTION_STATE, [ 'version' => 1, 'classification' => 'fresh_install' === $reason ? 'fresh' : 'upgrade', 'reason' => $reason, 'revision' => 0, 'step' => 'business', 'baseline' => self::settings_fingerprint(), 'draft' => [] ], '', false );
	}

	/** A single bundle is the activation boundary: no partially active defaults. */
	public static function active_settings(): ?array {
		$value = \get_option( self::OPTION_CONFIGURATION, null );
		return \is_array( $value ) && 1 === ( $value['version'] ?? null ) && \is_array( $value['presets'] ?? null ) && \is_array( $value['template'] ?? null ) && \is_array( $value['business'] ?? null ) ? $value : null;
	}

	public static function is_required(): bool {
		self::initialize();
		$state = \get_option( self::OPTION_STATE, [] );
		return \is_array( $state ) && 'fresh' === ( $state['classification'] ?? '' ) && null === self::active_settings();
	}

	private static function initial_draft(): array {
		$presets = [ 'version' => 2, 'sections' => [] ];
		foreach ( [ 'hero', 'cta', 'custom_cta', 'credentials', 'reviews', 'location', 'map', 'people', 'video' ] as $type ) { $presets['sections'][] = [ 'id' => 'default_' . $type, 'type' => $type, 'enabled' => true, 'data' => [ 'body' => '' ] ]; }
		return [ 'business' => [ 'name' => \sanitize_text_field( \get_bloginfo( 'name' ) ), 'website' => \home_url( '/' ), 'phone' => '' ], 'presets' => $presets, 'template' => ServiceDocument::default_layout( $presets ), 'ui' => [ 'cta_type' => 'url', 'cta_label' => '', 'cta_target' => '', 'style' => 'default', 'optional_panels' => [] ] ];
	}

	public static function state( ?string $language = null ): array {
		self::initialize(); $saved = \get_option( self::OPTION_STATE, [] ); $saved = \is_array( $saved ) ? $saved : []; $active = self::active_settings();
		$required = self::is_required();
		$language = \class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ? ServiceLanguages::current( 0, $language ) : '';
		if ( $required ) {
			$draft = ! empty( $saved['draft'] ) && \is_array( $saved['draft'] ) ? $saved['draft'] : self::initial_draft();
		} else {
			$initial = self::initial_draft();
			$business = $active['business'] ?? ( $saved['business'] ?? $initial['business'] );
			// Early upgrade setup screens saved identity inside a draft. Retain that
			// configured identity, while always taking proof and layout from the live
			// shared settings rather than the obsolete walkthrough document.
			if ( ! $active && ! isset( $saved['business'] ) && isset( $saved['draft']['business'] ) ) {
				$prior = self::sanitize_business( $saved['draft']['business'], $initial['business'] );
				if ( ! \is_wp_error( $prior ) ) { $business = $prior; }
			}
			$draft = [ 'business' => $business, 'presets' => ServiceShared::get_presets( 0, $language ), 'template' => ServiceShared::get_template(), 'ui' => $active['ui'] ?? $initial['ui'] ];
		}
		return [ 'required' => $required, 'complete' => ! $required, 'editable' => true, 'classification' => $saved['classification'] ?? 'upgrade', 'reason' => $saved['reason'] ?? 'existing_configuration', 'revision' => (int) ( $active['onboarding_revision'] ?? ( $saved['revision'] ?? 0 ) ), 'fingerprint' => self::settings_fingerprint(), 'language' => $language, 'step' => $active ? 'review' : ( $saved['step'] ?? 'business' ), 'steps' => self::STEPS, 'draft' => $draft, 'brand' => [ 'mode' => 'inherit_site', 'description' => 'Service pages use the site fonts and brand colors.' ], 'setup_url' => self::setup_url() ];
	}

	public static function config( ?string $language = null ): array { return [ 'endpoint' => \rest_url( 'nova-service/v1/onboarding' ), 'nonce' => \wp_create_nonce( 'wp_rest' ), 'setup_url' => self::setup_url(), 'state' => self::state( $language ) ]; }

	private static function response( array $state ) {
		$response = \rest_ensure_response( $state );
		if ( \is_object( $response ) && \method_exists( $response, 'header' ) ) { $response->header( 'Cache-Control', 'private, no-store, max-age=0' ); }
		return $response;
	}

	public static function permission( $request = null ) {
		if ( ! \current_user_can( 'manage_options' ) ) { return self::error( 'An administrator must configure service page defaults.', 403, 'nova_service_setup_forbidden' ); }
		// Cookie-authenticated REST requests also use WordPress's standard nonce
		// validation; other standard REST authentication methods remain supported.
		$nonce = $request && \method_exists( $request, 'get_header' ) ? $request->get_header( 'X-WP-Nonce' ) : '';
		if ( $nonce && ! \wp_verify_nonce( $nonce, 'wp_rest' ) ) { return self::error( 'The setup session expired. Reload this screen.', 403, 'rest_cookie_invalid_nonce' ); }
		return true;
	}

	public static function register_routes(): void {
		\register_rest_route( 'nova-service/v1', '/onboarding', [
			[ 'methods' => 'GET', 'permission_callback' => [ self::class, 'permission' ], 'callback' => static function ( $request ) { $language = self::request_language( $request ); return \is_wp_error( $language ) ? $language : self::response( self::state( $language ) ); }, 'args' => [ 'language' => [ 'type' => 'string' ] ] ],
			[ 'methods' => 'POST', 'permission_callback' => [ self::class, 'permission' ], 'callback' => [ self::class, 'save' ], 'args' => [ 'action' => [ 'type' => 'string', 'enum' => [ 'save', 'finish', 'save_business' ], 'required' => true ], 'expected_revision' => [ 'type' => 'integer', 'minimum' => 0, 'required' => true ], 'expected_fingerprint' => [ 'type' => 'string' ], 'language' => [ 'type' => 'string' ], 'step' => [ 'type' => 'string', 'enum' => self::STEPS ], 'draft' => [ 'type' => 'object' ] ] ],
		] );
	}

	private static function request_language( $request ) {
		$value = $request->has_param( 'language' ) ? $request->get_param( 'language' ) : null;
		if ( null !== $value && ! \is_string( $value ) ) { return self::error( 'Choose a valid service language.' ); }
		if ( \class_exists( __NAMESPACE__ . '\\ServiceLanguages' ) ) { return ServiceLanguages::validate( $value ); }
		return null === $value || '' === $value ? '' : self::error( 'A service language is unavailable on this site.' );
	}

	/** add_option is atomic, preventing two administrators from finishing together. */
	private static function delete_lock_if_matches( array $expected ): bool {
		global $wpdb;
		// A stale reader must not delete a token acquired by another request.
		if ( ! isset( $wpdb ) || ! \method_exists( $wpdb, 'delete' ) ) { return false; }
		$value = \function_exists( 'maybe_serialize' ) ? \maybe_serialize( $expected ) : \serialize( $expected );
		$deleted = $wpdb->delete( $wpdb->options, [ 'option_name' => self::OPTION_LOCK, 'option_value' => $value ], [ '%s', '%s' ] );
		self::refresh_lock_cache();
		return 1 === $deleted;
	}

	private static function refresh_lock_cache(): void {
		if ( \function_exists( 'wp_cache_delete' ) ) { \wp_cache_delete( self::OPTION_LOCK, 'options' ); \wp_cache_delete( 'notoptions', 'options' ); }
	}

	private static function locked( callable $callback ) {
		$token = \wp_generate_uuid4(); $lock = [ 'token' => $token, 'expires' => \time() + 60 ];
		self::refresh_lock_cache();
		if ( ! \add_option( self::OPTION_LOCK, $lock, '', false ) ) {
			$old = \get_option( self::OPTION_LOCK, [] );
			if ( \is_array( $old ) && ( $old['expires'] ?? PHP_INT_MAX ) < \time() ) { self::delete_lock_if_matches( $old ); }
			if ( ! \add_option( self::OPTION_LOCK, $lock, '', false ) ) { return self::error( 'Service setup is being saved in another session. Retry shortly.', 409, 'nova_service_setup_conflict' ); }
		}
		// A request may have cached state before another administrator finished.
		// Refresh after acquiring the lock, so revision checks use the saved row.
		if ( \function_exists( 'wp_cache_delete' ) ) {
			foreach ( [ self::OPTION_STATE, self::OPTION_CONFIGURATION, ServiceShared::OPTION_PRESETS, ServiceShared::OPTION_TEMPLATE, 'nova_service_shared_translations', 'alloptions', 'notoptions' ] as $key ) { \wp_cache_delete( $key, 'options' ); }
		}
		try { return $callback(); }
		finally { self::delete_lock_if_matches( $lock ); }
	}

	private static function sanitize_business( $value, array $current ) {
		if ( ! \is_array( $value ) && ! \is_object( $value ) ) { return self::error( 'Business identity must be an object.' ); }
		$business = (array) $value;
		if ( \array_diff( \array_keys( $business ), [ 'name', 'website', 'phone' ] ) ) { return self::error( 'Unknown business identity fields.' ); }
		foreach ( $business as $key => $text ) { if ( ! \is_string( $text ) || \strlen( $text ) > 2000 ) { return self::error( 'Business identity fields require short text values.' ); } $current[ $key ] = \sanitize_text_field( $text ); }
		$website = $current['website'] ?? '';
		if ( '' !== $website && ( ! \filter_var( $website, FILTER_VALIDATE_URL ) || ! \in_array( \strtolower( (string) \wp_parse_url( $website, PHP_URL_SCHEME ) ), [ 'http', 'https' ], true ) || \wp_parse_url( $website, PHP_URL_USER ) || \wp_parse_url( $website, PHP_URL_PASS ) ) ) { return self::error( 'Use a valid HTTP(S) business website URL.' ); }
		return $current;
	}

	private static function sanitize_draft( $value, array $current ) {
		if ( ! \is_array( $value ) && ! \is_object( $value ) ) { return self::error( 'The setup draft must be an object.' ); }
		$value = (array) $value;
		if ( \array_diff( \array_keys( $value ), [ 'business', 'presets', 'template', 'ui' ] ) ) { return self::error( 'Unknown setup draft fields.' ); }
		foreach ( [ 'presets', 'template' ] as $key ) { if ( \array_key_exists( $key, $value ) ) { $current[ $key ] = $value[ $key ]; } }
		if ( \array_key_exists( 'business', $value ) ) {
			$business = self::sanitize_business( $value['business'], $current['business'] );
			if ( \is_wp_error( $business ) ) { return $business; }
			$current['business'] = $business;
		}
		if ( \array_key_exists( 'ui', $value ) ) {
			if ( ! \is_array( $value['ui'] ) && ! \is_object( $value['ui'] ) ) { return self::error( 'Setup display choices must be an object.' ); }
			$ui = (array) $value['ui'];
			if ( \array_diff( \array_keys( $ui ), [ 'cta_type', 'cta_label', 'cta_target', 'style', 'optional_panels' ] ) ) { return self::error( 'Unknown setup display choices.' ); }
			foreach ( [ 'cta_type', 'cta_label', 'cta_target', 'style' ] as $key ) { if ( \array_key_exists( $key, $ui ) ) { if ( ! \is_string( $ui[ $key ] ) || \strlen( $ui[ $key ] ) > 100000 ) { return self::error( 'Setup display choices must be text values.' ); } $current['ui'][ $key ] = $ui[ $key ]; } }
			if ( ! \in_array( $current['ui']['cta_type'], [ 'booking', 'url', 'phone', 'form' ], true ) ) { return self::error( 'Choose a button, booking link, phone action or form.' ); }
			if ( isset( $ui['optional_panels'] ) ) {
				if ( ! \is_array( $ui['optional_panels'] ) || \count( $ui['optional_panels'] ) > 20 ) { return self::error( 'Optional setup panels require an array.' ); }
				foreach ( $ui['optional_panels'] as $panel ) { if ( ! \is_string( $panel ) || ! \preg_match( '/^[a-zA-Z0-9_-]{1,40}$/', $panel ) ) { return self::error( 'Invalid optional setup panel.' ); } }
				$current['ui']['optional_panels'] = \array_values( \array_unique( $ui['optional_panels'] ) );
			}
			$current['ui']['cta_label'] = \sanitize_text_field( $current['ui']['cta_label'] );
			$current['ui']['cta_target'] = 'form' === $current['ui']['cta_type'] ? ServiceDocument::safe_custom_html( $current['ui']['cta_target'] ) : \sanitize_text_field( $current['ui']['cta_target'] );
			$current['ui']['style'] = \sanitize_text_field( $current['ui']['style'] );
		}
		$valid = ServiceShared::validate_settings( $current['presets'], $current['template'] );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		$current['presets'] = ServiceDocument::sanitize_document( $current['presets'] );
		$current['template'] = [] === $current['template'] ? [] : ServiceDocument::sanitize_document( $current['template'] );
		if ( \strlen( \serialize( $current ) ) > 2000000 ) { return self::error( 'The setup draft is too large.' ); }
		return $current;
	}

	private static function validate_finish( array $draft ) {
		if ( '' === \trim( $draft['business']['name'] ) ) { return self::error( 'Enter the real business name before finishing setup.' ); }
		if ( empty( $draft['template']['sections'] ) ) { return self::error( 'Select a default service layout before finishing setup.' ); }
		$presets = \array_column( $draft['presets']['sections'], null, 'id' ); $resolved = [];
		foreach ( $draft['template']['sections'] as $section ) {
			if ( ! empty( $section['shared_key'] ) ) { $preset = $presets[ $section['shared_key'] ]; $section['data'] = \array_replace( (array) $preset['data'], (array) $section['data'] ); $section['enabled'] = $section['enabled'] && $preset['enabled']; }
			$resolved[] = $section;
		}
		foreach ( $resolved as $section ) {
			if ( empty( $section['enabled'] ) ) { continue; }
			if ( 'hero' !== $section['type'] ) { return self::error( 'Keep an enabled hero with the primary action first in the service layout.' ); }
			foreach ( (array) ( ( (array) $section['data'] )['buttons'] ?? [] ) as $button ) {
				if ( '' === \trim( $button['label'] ?? '' ) || '' === \trim( $button['url'] ?? '' ) ) { continue; }
				if ( '#' === \substr( $button['url'], 0, 1 ) ) {
					$target = \substr( $button['url'], 1 ); $found = false;
					foreach ( $resolved as $candidate ) {
						if ( $target !== 'nova-service-' . $candidate['id'] || empty( $candidate['enabled'] ) || 'custom_cta' !== $candidate['type'] ) { continue; }
						$data = (array) $candidate['data'];
						$found = ! empty( $data['shortcode'] ) || ( ! empty( $data['html'] ) && \preg_match( '/<(?:form|iframe)\b/i', $data['html'] ) );
					}
					if ( ! $found ) { return self::error( 'The primary booking/form link must target an enabled working form or calendar section.' ); }
				}
				return true;
			}
			return self::error( 'Add a valid primary button or an enabled working form/calendar target before finishing setup.' );
		}
		return self::error( 'Add an enabled hero and primary action before finishing setup.' );
	}

	public static function save( $request ) {
		$allowed = self::permission( $request ); if ( \is_wp_error( $allowed ) ) { return $allowed; }
		return self::locked( static function () use ( $request ) {
			$language = self::request_language( $request ); if ( \is_wp_error( $language ) ) { return $language; }
			$state = self::state( $language );
			if ( 'save_business' === $request->get_param( 'action' ) ) { return self::save_business( $request, $state, $language ); }
			if ( ! $state['required'] ) { return self::error( 'This site is already configured or is an existing installation. Its defaults were not changed.', 409, 'nova_service_setup_not_required' ); }
			$revision = $request->get_param( 'expected_revision' );
			if ( ! \is_int( $revision ) || $revision !== $state['revision'] ) { return self::error( 'Setup changed in another session. Reload to continue with the saved progress.', 409, 'nova_service_setup_conflict' ); }
			$action = $request->get_param( 'action' ); $step = $request->get_param( 'step' ) ?: $state['step'];
			if ( ! \in_array( $action, [ 'save', 'finish' ], true ) || ! \in_array( $step, self::STEPS, true ) ) { return self::error( 'Unknown setup action or step.' ); }
			$draft = self::sanitize_draft( $request->has_param( 'draft' ) ? $request->get_param( 'draft' ) : [], $state['draft'] );
			if ( \is_wp_error( $draft ) ) { return $draft; }
			$saved = \get_option( self::OPTION_STATE );
			if ( 'finish' === $action ) {
				$valid = self::validate_finish( $draft ); if ( \is_wp_error( $valid ) ) { return $valid; }
				if ( $saved['baseline'] !== self::settings_fingerprint() ) { return self::error( 'Active service defaults changed while setup was open. They were preserved. Review the current settings before continuing.', 409, 'nova_service_setup_conflict' ); }
				$configuration = [ 'version' => 1, 'business' => $draft['business'], 'presets' => $draft['presets'], 'template' => $draft['template'], 'ui' => $draft['ui'], 'brand' => [ 'mode' => 'inherit_site' ], 'onboarding_revision' => $revision + 1, 'finished_at' => \gmdate( 'c' ) ];
				if ( ! \add_option( self::OPTION_CONFIGURATION, $configuration, '', false ) ) { return self::error( 'Service defaults were installed by another session. Reload to see them.', 409, 'nova_service_setup_conflict' ); }
			} else {
				$saved['draft'] = $draft; $saved['step'] = $step; $saved['revision'] = $revision + 1;
				if ( ! \update_option( self::OPTION_STATE, $saved, false ) ) { return self::error( 'Setup progress could not be saved. Your active defaults were not changed.', 500 ); }
			}
			return self::response( self::state() );
		} );
	}

	/** Explicit identity saves cannot activate, replace or sanitize shared content. */
	private static function save_business( $request, array $state, ?string $language ) {
		if ( $state['required'] ) { return self::pending_error(); }
		$revision = $request->get_param( 'expected_revision' ); $fingerprint = $request->get_param( 'expected_fingerprint' );
		if ( ! \is_int( $revision ) || $revision !== $state['revision'] || ! \is_string( $fingerprint ) || ! \hash_equals( $state['fingerprint'], $fingerprint ) ) { return self::error( 'Service settings changed in another session. Reload before saving business settings.', 409, 'nova_service_setup_conflict' ); }
		$draft = $request->get_param( 'draft' );
		if ( ( ! \is_array( $draft ) && ! \is_object( $draft ) ) || [ 'business' ] !== \array_keys( (array) $draft ) ) { return self::error( 'Business settings can only update the business identity.' ); }
		$draft = (array) $draft;
		$business = self::sanitize_business( $draft['business'], $state['draft']['business'] );
		if ( \is_wp_error( $business ) ) { return $business; }
		if ( $business === $state['draft']['business'] ) { return self::response( $state ); }
		$active = self::active_settings();
		if ( $active ) {
			$active['business'] = $business; $active['onboarding_revision'] = $revision + 1;
			$saved = \update_option( self::OPTION_CONFIGURATION, $active, false );
		} else {
			$lifecycle = \get_option( self::OPTION_STATE, [] );
			$lifecycle['business'] = $business; $lifecycle['revision'] = $revision + 1;
			$saved = \update_option( self::OPTION_STATE, $lifecycle, false );
		}
		if ( ! $saved ) { return self::error( 'Business settings could not be saved. Shared service settings were not changed.', 500 ); }
		return self::response( self::state( $language ) );
	}

	/** Subsequent client-default edits keep the fresh configuration as one bundle. */
	public static function save_active_settings( array $presets, array $template ) {
		$allowed = self::permission(); if ( \is_wp_error( $allowed ) ) { return $allowed; }
		$valid = ServiceShared::validate_settings( $presets, $template ); if ( \is_wp_error( $valid ) ) { return $valid; }
		$presets = ServiceDocument::sanitize_document( $presets ); $template = [] === $template ? [] : ServiceDocument::sanitize_document( $template );
		return self::locked( static function () use ( $presets, $template ) {
			$active = self::active_settings(); if ( ! $active ) { return self::error( 'The active service configuration is unavailable.', 409 ); }
			if ( \serialize( $active['presets'] ) === \serialize( $presets ) && \serialize( $active['template'] ) === \serialize( $template ) ) { return true; }
			$active['presets'] = $presets; $active['template'] = $template; $active['onboarding_revision'] = (int) ( $active['onboarding_revision'] ?? 0 ) + 1;
			return \update_option( self::OPTION_CONFIGURATION, $active, false ) ? true : self::error( 'Service defaults could not be saved.', 500 );
		} );
	}

	public static function pending_error() { return self::error( 'Finish Service CPT setup before publishing or posting native service pages. An administrator can resume setup at ' . self::setup_url(), 409, 'nova_service_setup_required' ); }

	public static function guard_rest_request( $request, int $post_id = 0 ) {
		if ( ! self::is_required() ) { return true; }
		if ( ! $post_id || $request->has_param( 'nova_service_content' ) || $request->has_param( 'nova_service_document' ) || ServiceDocument::is_v2( $post_id ) || \in_array( $request->get_param( 'status' ), [ 'publish', 'future', 'private' ], true ) ) { return self::pending_error(); }
		return true;
	}

	/** Manual setup drafts remain available, but cannot become public prematurely. */
	public static function guard_publish( array $data, array $postarr = [] ): array {
		if ( 'service_page' === ( $data['post_type'] ?? '' ) && \in_array( $data['post_status'] ?? '', [ 'publish', 'future', 'private' ], true ) && self::is_required() ) { $data['post_status'] = 'draft'; }
		return $data;
	}

	public static function notice(): void {
		if ( ! self::is_required() || ! \current_user_can( 'manage_options' ) ) { return; }
		$screen = \function_exists( 'get_current_screen' ) ? \get_current_screen() : null;
		if ( ! $screen || 'service_page' !== ( $screen->post_type ?? '' ) || ( isset( $_GET['tab'] ) && 'setup' === $_GET['tab'] ) ) { return; }
		echo '<div class="notice notice-warning"><p>' . \esc_html__( 'Finish service page setup before publishing. Saved setup drafts can be resumed at any time.', 'nova-bridge-suite' ) . ' <a href="' . \esc_url( self::setup_url() ) . '">' . \esc_html__( 'Continue setup', 'nova-bridge-suite' ) . '</a></p></div>';
	}
}
