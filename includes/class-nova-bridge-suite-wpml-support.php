<?php
/**
 * Multilingual support helpers for NOVA Bridge Suite.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nova_Bridge_Suite_WPML_Support {

	/**
	 * Request key used to preserve the default language across settings saves.
	 */
	private const SETTINGS_DEFAULT_LANGUAGE_REQUEST_KEY = 'nova_bridge_suite_default_lang';

	/**
	 * Option suffix used for localized shadow settings.
	 */
	private const LOCALIZED_OPTION_SUFFIX = '__nova_lang_';

	/**
	 * Blog CPT option that stores one or more custom post type definitions.
	 */
	private const BLOG_CPTS_OPTION = 'quarantined_cpt_bodyclean_cpts';

	/**
	 * Blog CPT post type slug used when no custom definitions were saved.
	 */
	private const DEFAULT_BLOG_POST_TYPE = 'blog';

	/**
	 * Service CPT post type slug.
	 */
	private const SERVICE_POST_TYPE = 'service_page';

	/**
	 * Blog related-posts meta key.
	 */
	private const BLOG_RELATED_POSTS_META = 'blog_related_posts';

	/**
	 * Service related-posts meta key.
	 */
	private const SERVICE_RELATED_POSTS_META = 'sp_related_posts';

	/**
	 * Service attachment meta keys.
	 */
	private const SERVICE_IMAGE_META_KEYS = [
		'sp_image_1',
		'sp_image_2',
	];

	/**
	 * Blog settings that should vary per language when a multilingual plugin is active.
	 */
	private const BLOG_LOCALIZED_OPTIONS = [
		'quarantined_cpt_bodyclean_author_archive_title',
		'quarantined_cpt_bodyclean_cpt_singular',
		'quarantined_cpt_bodyclean_cpt_plural',
		'quarantined_cpt_bodyclean_blog_cta_primary_title',
		'quarantined_cpt_bodyclean_blog_cta_primary_copy',
		'quarantined_cpt_bodyclean_blog_cta_primary_button_label',
		'quarantined_cpt_bodyclean_blog_cta_primary_button_url',
		'quarantined_cpt_bodyclean_blog_cta_after_related_title',
		'quarantined_cpt_bodyclean_blog_cta_after_related_copy',
		'quarantined_cpt_bodyclean_blog_cta_after_related_button_label',
		'quarantined_cpt_bodyclean_blog_cta_after_related_button_url',
		'quarantined_cpt_bodyclean_blog_cta_by_cpt',
		'quarantined_cpt_bodyclean_archive_by_cpt',
		'quarantined_cpt_bodyclean_label_author',
		'quarantined_cpt_bodyclean_label_key_takeaways',
		'quarantined_cpt_bodyclean_label_toc',
		'quarantined_cpt_bodyclean_label_toc_read_more',
		'quarantined_cpt_bodyclean_label_toc_read_less',
		'quarantined_cpt_bodyclean_label_related_articles',
		'quarantined_cpt_bodyclean_label_faq_title',
		'quarantined_cpt_bodyclean_label_publications',
	];

	/**
	 * Service settings that should vary per language when a multilingual plugin is active.
	 */
	private const SERVICE_LOCALIZED_OPTIONS = [
		'service_cpt_singular',
		'service_cpt_plural',
		'service_cpt_label_faq',
		'service_cpt_label_related',
		'service_cpt_global_hero_primary_label',
		'service_cpt_global_hero_primary_url',
		'service_cpt_global_hero_secondary_label',
		'service_cpt_global_hero_secondary_url',
		'service_cpt_global_sidebar_title',
		'service_cpt_global_sidebar_copy',
		'service_cpt_global_sidebar_primary_label',
		'service_cpt_global_sidebar_primary_url',
		'service_cpt_global_sidebar_secondary_label',
		'service_cpt_global_sidebar_secondary_url',
		'service_cpt_global_cta_title',
		'service_cpt_global_cta_bullet_1',
		'service_cpt_global_cta_bullet_2',
		'service_cpt_global_cta_bullet_3',
		'service_cpt_global_cta_button_label',
		'service_cpt_global_cta_button_url',
		'service_cpt_global_cta_more_text',
		'service_cpt_global_cta_more_url',
		'service_cpt_archive_hero_eyebrow',
		'service_cpt_archive_hero_title',
		'service_cpt_archive_hero_copy',
		'service_cpt_archive_hero_cta_label',
		'service_cpt_archive_hero_cta_url',
		'service_cpt_archive_intro_heading',
		'service_cpt_archive_intro_copy',
		'service_cpt_archive_card_cta_label',
		'service_cpt_archive_card_placeholder',
		'service_cpt_archive_services_mode',
		'service_cpt_archive_services_limit',
		'service_cpt_archive_services_ids',
		'service_cpt_archive_highlights_heading',
		'service_cpt_archive_highlight_one_image',
		'service_cpt_archive_highlight_one_copy',
		'service_cpt_archive_highlight_two_image',
		'service_cpt_archive_highlight_two_copy',
		'service_cpt_archive_cta_title',
		'service_cpt_archive_cta_bullet_1',
		'service_cpt_archive_cta_bullet_2',
		'service_cpt_archive_cta_bullet_3',
		'service_cpt_archive_cta_button_label',
		'service_cpt_archive_cta_button_url',
		'service_cpt_archive_cta_more_text',
		'service_cpt_archive_cta_more_url',
		'service_cpt_archive_faq',
		'service_cpt_archive_related_posts',
		'service_cpt_archive_seo_title',
		'service_cpt_archive_seo_description',
	];

	/**
	 * Settings groups whose translated saves must never overwrite global/base options.
	 */
	private const LOCALIZED_SETTINGS_GROUPS = [
		'quarantined_cpt_bodyclean',
		'service-cpt',
	];

	/**
	 * Track bootstrap status so the filter is only registered once.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Cached localized option lookups.
	 *
	 * @var array<string,array{exists:bool,value:mixed}>
	 */
	private static $localized_option_cache = [];

	/**
	 * Language code currently loaded into the localized option cache.
	 *
	 * @var string
	 */
	private static $localized_option_cache_language = '';

	/**
	 * Settings groups that have localized shadow options.
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $localized_settings_groups = [];

	/**
	 * Prevent recursive localized option resolution.
	 *
	 * @var bool
	 */
	private static $localized_option_resolution_guard = false;

	/**
	 * Prevent recursive current-language resolution.
	 *
	 * @var bool
	 */
	private static $current_language_resolution_guard = false;

	/**
	 * Prevent recursive default-language resolution.
	 *
	 * @var bool
	 */
	private static $default_language_resolution_guard = false;

	/**
	 * Prevent recursive available-language resolution.
	 *
	 * @var bool
	 */
	private static $available_languages_resolution_guard = false;

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}

		self::$bootstrapped = true;

		// The blog CPT slug is configurable, so extend multilingual configs at runtime.
		add_filter( 'wpml_config_array', [ self::class, 'filter_wpml_config_array' ] );
		add_filter( 'pll_get_post_types', [ self::class, 'filter_polylang_post_types' ], 10, 2 );
		add_filter( 'pll_translate_post_meta', [ self::class, 'filter_polylang_post_meta' ], 10, 5 );

		if ( self::should_register_option_localization_hooks() ) {
			add_filter( 'allowed_options', [ self::class, 'filter_allowed_options' ], 1000 );
			add_filter( 'whitelist_options', [ self::class, 'filter_allowed_options' ], 1000 );
			add_filter( 'pre_update_option', [ self::class, 'filter_nondefault_option_updates' ], 10, 3 );
			add_action( 'added_option', [ self::class, 'handle_option_cache_change' ], 10, 2 );
			add_action( 'updated_option', [ self::class, 'handle_option_cache_change' ], 10, 3 );
			add_action( 'deleted_option', [ self::class, 'handle_option_cache_change' ], 10, 1 );

			foreach ( self::get_localized_base_options() as $option ) {
				add_filter( 'pre_option_' . $option, [ self::class, 'filter_localized_option_value' ], 10, 3 );
			}
		}
	}

	/**
	 * Skip localized option shadowing during REST mutations.
	 *
	 * Post and page mutations can trigger plugin and multilingual lookups while
	 * WordPress is still inside the write flow. Keep localized option shadowing
	 * for read requests, but avoid it on mutating REST requests so writes cannot
	 * recurse back through language resolution.
	 */
	private static function should_register_option_localization_hooks(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return true;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		return in_array( $method, [ 'GET', 'HEAD', 'OPTIONS' ], true );
	}

	/**
	 * Returns the current settings scope for admin UI hints.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_settings_language_context(): ?array {
		if ( ! self::is_multilingual_active() ) {
			return null;
		}

		$languages        = self::get_available_languages();
		$current_language = self::get_current_language_code();
		$default_language = self::get_default_language_code();

		if ( '' === $current_language && '' === $default_language && empty( $languages ) ) {
			return null;
		}

		return [
			'provider'      => self::get_multilingual_provider_name(),
			'current'       => $current_language,
			'default'       => $default_language,
			'is_localized'  => '' !== $current_language && '' !== $default_language && $current_language !== $default_language,
			'languages'     => $languages,
		];
	}

	/**
	 * Returns language-switcher data for a settings page.
	 *
	 * @param string $base_url Settings page URL without a lang query arg.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_settings_language_links( string $base_url ): array {
		$base_url = remove_query_arg( 'lang', $base_url );

		if ( '' === $base_url ) {
			return [];
		}

		$current_language = self::get_current_language_code();
		$default_language = self::get_default_language_code();
		$links            = [];

		foreach ( self::get_available_languages() as $language ) {
			$code = (string) ( $language['code'] ?? '' );

			if ( '' === $code ) {
				continue;
			}

			$links[] = [
				'code'       => $code,
				'label'      => (string) ( $language['label'] ?? strtoupper( $code ) ),
				'url'        => add_query_arg( 'lang', $code, $base_url ),
				'is_current' => $code === $current_language,
				'is_default' => $code === $default_language,
			];
		}

		return $links;
	}

	/**
	 * Returns the settings form action URL with the current language preserved.
	 */
	public static function get_settings_form_action_url(): string {
		$url      = admin_url( 'options.php' );
		$language = self::get_current_language_code();

		if ( '' !== $language ) {
			$url = add_query_arg( 'lang', $language, $url );
		}

		return $url;
	}

	/**
	 * Returns the option name that should be used for the current language scope.
	 */
	public static function get_localized_option_name( string $option ): string {
		$option = (string) $option;

		if ( ! self::should_use_localized_option( $option ) ) {
			return $option;
		}

		return self::build_localized_option_name( $option, self::get_current_language_code() );
	}

	/**
	 * Registers current-language shadow settings so options.php accepts them.
	 *
	 * @param string            $group   Settings group.
	 * @param array<int,string> $options Base option names that may be localized.
	 * @return void
	 */
	public static function register_localized_settings( string $group, array $options ): void {
		if ( ! self::is_multilingual_active() ) {
			return;
		}

		global $wp_registered_settings;

		self::$localized_settings_groups[ $group ] = array_values( array_unique( array_map( 'strval', $options ) ) );

		foreach ( self::$localized_settings_groups[ $group ] as $option ) {
			$shadow_option = self::get_localized_option_name( $option );

			if ( $shadow_option === $option ) {
				continue;
			}

			if ( ! isset( $wp_registered_settings[ $option ] ) || isset( $wp_registered_settings[ $shadow_option ] ) ) {
				continue;
			}

			register_setting( $group, $shadow_option, $wp_registered_settings[ $option ] );
		}
	}

	/**
	 * Replaces base localized options with the current-language shadow options during settings saves.
	 *
	 * @param mixed $allowed_options Allowed options grouped by settings page.
	 * @return mixed
	 */
	public static function filter_allowed_options( $allowed_options ) {
		if ( ! is_array( $allowed_options ) ) {
			return $allowed_options;
		}

		$current_language = self::get_current_language_code();
		$default_language = self::get_default_language_code();

		if ( '' === $current_language || '' === $default_language || $current_language === $default_language ) {
			return $allowed_options;
		}

		foreach ( self::$localized_settings_groups as $group => $options ) {
			if ( empty( $allowed_options[ $group ] ) || ! is_array( $allowed_options[ $group ] ) ) {
				continue;
			}

			$shadow_options = [];

			foreach ( $options as $option ) {
				$shadow_option = self::build_localized_option_name( $option, $current_language );
				$shadow_options[] = $shadow_option;
			}

			// Non-default languages must only save localized shadow options.
			// Global settings remain editable on the default language only.
			$allowed_options[ $group ] = array_values( array_unique( $shadow_options ) );
		}

		return $allowed_options;
	}

	/**
	 * Prevents non-default-language settings saves from overwriting global/base plugin options.
	 *
	 * @param mixed $value     Proposed option value.
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Existing option value.
	 * @return mixed
	 */
	public static function filter_nondefault_option_updates( $value, $option, $old_value ) {
		if ( ! is_string( $option ) || '' === $option ) {
			return $value;
		}

		$current_language = self::get_current_language_code();
		$default_language = self::get_default_language_code();

		if ( '' === $current_language || '' === $default_language || $current_language === $default_language ) {
			return $value;
		}

		if ( ! self::is_localized_settings_save_request() ) {
			return $value;
		}

		if ( self::is_shadow_option_for_language( $option, $current_language ) ) {
			return $value;
		}

		if ( self::is_protected_settings_option( $option ) ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * Adds runtime CPT definitions to WPML config.
	 *
	 * @param mixed $config Parsed WPML config array.
	 * @return array<string,mixed>
	 */
	public static function filter_wpml_config_array( $config ): array {
		if ( ! is_array( $config ) ) {
			$config = [];
		}

		$types = array_merge(
			[ self::SERVICE_POST_TYPE ],
			self::get_blog_post_types()
		);

		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $types ) ) ) as $post_type ) {
			self::merge_custom_type_config( $config, $post_type, '1' );
		}

		return $config;
	}

	public static function maybe_translate_post_id( int $post_id, string $post_type = '', ?string $language_code = null ): int {
		if ( $post_id <= 0 ) {
			return $post_id;
		}

		if ( '' === $post_type ) {
			$detected_post_type = get_post_type( $post_id );
			$post_type          = is_string( $detected_post_type ) ? $detected_post_type : 'post';
		}

		if ( self::is_wpml_available() ) {
			$translated = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $language_code );

			return is_numeric( $translated ) ? (int) $translated : $post_id;
		}

		if ( self::is_polylang_available() && function_exists( 'pll_get_post' ) ) {
			$translated = ( null === $language_code || '' === $language_code )
				? pll_get_post( $post_id )
				: pll_get_post( $post_id, (string) $language_code );

			return is_numeric( $translated ) && (int) $translated > 0 ? (int) $translated : $post_id;
		}

		return $post_id;
	}

	public static function maybe_translate_post_ids( array $post_ids, string $post_type = '', ?string $language_code = null ): array {
		$translated_ids = [];

		foreach ( $post_ids as $post_id ) {
			$post_id = absint( $post_id );

			if ( $post_id <= 0 ) {
				continue;
			}

			$translated_id = self::maybe_translate_post_id( $post_id, $post_type, $language_code );

			if ( $translated_id > 0 && ! in_array( $translated_id, $translated_ids, true ) ) {
				$translated_ids[] = $translated_id;
			}
		}

		return $translated_ids;
	}

	public static function maybe_translate_attachment_id( int $attachment_id, ?string $language_code = null ): int {
		return self::maybe_translate_post_id( $attachment_id, 'attachment', $language_code );
	}

	public static function get_current_language_code(): string {
		if ( self::$current_language_resolution_guard ) {
			return '';
		}

		self::$current_language_resolution_guard = true;

		try {
			$requested_language = self::get_requested_language_code();

			if ( '' !== $requested_language ) {
				return $requested_language;
			}

			if ( self::is_wpml_available() ) {
				$language = apply_filters( 'wpml_current_language', null );

				if ( is_string( $language ) ) {
					return self::normalize_language_key( $language );
				}
			}

			if ( self::is_polylang_available() && function_exists( 'pll_current_language' ) ) {
				$language = pll_current_language( 'slug' );

				if ( is_string( $language ) ) {
					return self::normalize_language_key( $language );
				}
			}

			return '';
		} finally {
			self::$current_language_resolution_guard = false;
		}
	}

	public static function get_default_language_code(): string {
		if ( self::$default_language_resolution_guard ) {
			return '';
		}

		self::$default_language_resolution_guard = true;

		try {
			$requested_default_language = self::get_requested_default_language_code();

			if ( '' !== $requested_default_language ) {
				return $requested_default_language;
			}

			if ( self::is_wpml_available() ) {
				$language = apply_filters( 'wpml_default_language', null );

				if ( is_string( $language ) ) {
					return self::normalize_language_key( $language );
				}
			}

			if ( self::is_polylang_available() && function_exists( 'pll_default_language' ) ) {
				$language = pll_default_language( 'slug' );

				if ( is_string( $language ) ) {
					return self::normalize_language_key( $language );
				}
			}

			return '';
		} finally {
			self::$default_language_resolution_guard = false;
		}
	}

	private static function is_wpml_available(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' );
	}

	private static function is_polylang_available(): bool {
		return defined( 'POLYLANG_VERSION' ) || function_exists( 'pll_current_language' );
	}

	public static function is_multilingual_active(): bool {
		return self::is_wpml_available() || self::is_polylang_available();
	}

	/**
	 * Returns the language explicitly requested in the current admin request.
	 */
	public static function get_requested_language_code(): string {
		if ( empty( $_REQUEST['lang'] ) || ! is_scalar( $_REQUEST['lang'] ) ) {
			return '';
		}

		$language = self::normalize_language_key( wp_unslash( (string) $_REQUEST['lang'] ) );

		if ( '' === $language ) {
			return '';
		}

		if ( self::is_available_language_code( $language ) ) {
			return $language;
		}

		if ( self::is_localized_settings_save_request() && '' !== self::get_requested_default_language_code() ) {
			return $language;
		}

		return '';
	}

	/**
	 * Programmatically enables multilingual support for NOVA CPTs in Polylang.
	 *
	 * @param mixed $post_types Existing Polylang post types.
	 * @param mixed $hide       Whether Polylang is building the settings UI list.
	 * @return array<int|string,string>
	 */
	public static function filter_polylang_post_types( $post_types, $hide ): array {
		if ( ! is_array( $post_types ) ) {
			$post_types = [];
		}

		foreach ( array_unique( array_merge( [ self::SERVICE_POST_TYPE ], self::get_blog_post_types() ) ) as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type ) {
				continue;
			}

			if ( $hide ) {
				$key = array_search( $post_type, $post_types, true );
				if ( false !== $key ) {
					unset( $post_types[ $key ] );
				}

				unset( $post_types[ $post_type ] );
				continue;
			}

			$post_types[ $post_type ] = $post_type;
		}

		return $post_types;
	}

	/**
	 * Translates NOVA post-meta IDs when Polylang copies or syncs translations.
	 *
	 * @param mixed  $value Meta value.
	 * @param mixed  $key   Meta key.
	 * @param mixed  $lang  Target language slug.
	 * @param mixed  $from  Source post ID.
	 * @param mixed  $to    Target post ID.
	 * @return mixed
	 */
	public static function filter_polylang_post_meta( $value, $key, $lang, $from = 0, $to = 0 ) {
		unset( $from, $to );

		$key  = (string) $key;
		$lang = is_string( $lang ) ? $lang : '';

		if ( self::BLOG_RELATED_POSTS_META === $key || self::SERVICE_RELATED_POSTS_META === $key ) {
			$post_ids = is_array( $value ) ? $value : maybe_unserialize( $value );

			if ( ! is_array( $post_ids ) ) {
				return $value;
			}

			return self::maybe_translate_post_ids( $post_ids, '', $lang );
		}

		if ( in_array( $key, self::SERVICE_IMAGE_META_KEYS, true ) ) {
			return self::maybe_translate_attachment_id( absint( $value ), $lang );
		}

		return $value;
	}

	/**
	 * Returns localized shadow settings when a non-default language override exists.
	 *
	 * @param mixed  $pre_option    Short-circuit value from earlier filters.
	 * @param string $option        Base option name.
	 * @param mixed  $default_value Requested default value.
	 * @return mixed
	 */
	public static function filter_localized_option_value( $pre_option, string $option, $default_value ) {
		unset( $default_value );

		if ( false !== $pre_option ) {
			return $pre_option;
		}

		if ( self::$localized_option_resolution_guard ) {
			return $pre_option;
		}

		self::$localized_option_resolution_guard = true;

		try {
			if ( ! self::should_use_localized_option( $option ) ) {
				return false;
			}

			$localized_option = self::build_localized_option_name( $option, self::get_current_language_code() );
			$localized_value  = self::get_raw_option_value( $localized_option );

			return $localized_value['exists'] ? $localized_value['value'] : false;
		} finally {
			self::$localized_option_resolution_guard = false;
		}
	}

	/**
	 * Clears the localized option cache when a relevant option changes.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function handle_option_cache_change( string $option ): void {
		foreach ( self::get_localized_base_options() as $base_option ) {
			if ( $option === $base_option || 0 === strpos( $option, $base_option . self::LOCALIZED_OPTION_SUFFIX ) ) {
				self::reset_localized_option_cache();
				return;
			}
		}
	}

	/**
	 * Returns all configured blog CPT post type slugs.
	 *
	 * @return array<int,string>
	 */
	private static function get_blog_post_types(): array {
		if ( class_exists( '\\SEORAI\\BodycleanCPT\\Plugin' ) ) {
			$types = \SEORAI\BodycleanCPT\Plugin::get_post_types();

			if ( ! empty( $types ) ) {
				return array_values(
					array_unique(
						array_filter(
							array_map( 'sanitize_key', $types )
						)
					)
				);
			}
		}

		$raw = get_option( self::BLOG_CPTS_OPTION, null );

		if ( null === $raw ) {
			return [ self::DEFAULT_BLOG_POST_TYPE ];
		}

		if ( ! is_array( $raw ) ) {
			return [];
		}

		$types = [];

		foreach ( $raw as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$type = isset( $definition['type'] ) ? sanitize_key( (string) $definition['type'] ) : '';

			if ( '' === $type && isset( $definition['slug'] ) ) {
				$type = sanitize_key( sanitize_title( (string) $definition['slug'] ) );
			}

			if ( '' !== $type ) {
				$types[] = substr( $type, 0, 20 );
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Ensures a CPT exists in WPML's parsed config array.
	 *
	 * @param array<string,mixed> $config Config array passed by reference.
	 * @param string              $post_type Post type slug.
	 * @param string              $translate Translation mode.
	 * @return void
	 */
	private static function merge_custom_type_config( array &$config, string $post_type, string $translate ): void {
		if ( ! isset( $config['wpml-config'] ) || ! is_array( $config['wpml-config'] ) ) {
			$config['wpml-config'] = [];
		}

		if ( ! isset( $config['wpml-config']['custom-types'] ) || ! is_array( $config['wpml-config']['custom-types'] ) ) {
			$config['wpml-config']['custom-types'] = [];
		}

		if ( ! isset( $config['wpml-config']['custom-types']['custom-type'] ) || ! is_array( $config['wpml-config']['custom-types']['custom-type'] ) ) {
			$config['wpml-config']['custom-types']['custom-type'] = [];
		}

		foreach ( $config['wpml-config']['custom-types']['custom-type'] as &$entry ) {
			if ( ! is_array( $entry ) || ( $entry['value'] ?? '' ) !== $post_type ) {
				continue;
			}

			if ( ! isset( $entry['attr'] ) || ! is_array( $entry['attr'] ) ) {
				$entry['attr'] = [];
			}

			$entry['attr']['translate'] = $translate;
			return;
		}
		unset( $entry );

		$config['wpml-config']['custom-types']['custom-type'][] = [
			'value' => $post_type,
			'attr'  => [
				'translate' => $translate,
			],
		];
	}

	/**
	 * Returns all base settings that can use localized shadow values.
	 *
	 * @return array<int,string>
	 */
	private static function get_localized_base_options(): array {
		return array_values(
			array_unique(
				array_merge(
					self::BLOG_LOCALIZED_OPTIONS,
					self::SERVICE_LOCALIZED_OPTIONS
				)
			)
		);
	}

	/**
	 * Returns all active language definitions keyed by language code.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_available_languages(): array {
		if ( self::$available_languages_resolution_guard ) {
			return [];
		}

		self::$available_languages_resolution_guard = true;

		try {
			$languages        = [];
			$default_language = self::get_default_language_code();

			if ( self::is_wpml_available() ) {
				$active_languages = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );

				if ( is_array( $active_languages ) ) {
					foreach ( $active_languages as $key => $language ) {
						if ( is_array( $language ) ) {
							$code = self::normalize_language_key(
								(string) ( $language['language_code'] ?? $language['code'] ?? $key )
							);
							$label = (string) ( $language['translated_name'] ?? $language['native_name'] ?? $language['display_name'] ?? $language['english_name'] ?? $language['name'] ?? strtoupper( $code ) );
						} else {
							$code  = self::normalize_language_key( (string) $key );
							$label = strtoupper( $code );
						}

						if ( '' === $code ) {
							continue;
						}

						$languages[ $code ] = [
							'code'       => $code,
							'label'      => '' !== $label ? $label : strtoupper( $code ),
							'is_default' => $code === $default_language,
						];
					}
				}
			}

			if ( empty( $languages ) && self::is_polylang_available() && function_exists( 'pll_languages_list' ) ) {
				$codes = pll_languages_list( [ 'fields' => 'slug' ] );

				if ( is_array( $codes ) ) {
					foreach ( $codes as $code ) {
						$code = self::normalize_language_key( (string) $code );

						if ( '' === $code ) {
							continue;
						}

						$label = strtoupper( $code );

						if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->model ) && method_exists( PLL()->model, 'get_language' ) ) {
							$language_object = PLL()->model->get_language( $code );

							if ( is_object( $language_object ) && ! empty( $language_object->name ) ) {
								$label = (string) $language_object->name;
							}
						}

						$languages[ $code ] = [
							'code'       => $code,
							'label'      => $label,
							'is_default' => $code === $default_language,
						];
					}
				}
			}

			return $languages;
		} finally {
			self::$available_languages_resolution_guard = false;
		}
	}

	private static function is_available_language_code( string $language_code ): bool {
		return isset( self::get_available_languages()[ $language_code ] );
	}

	private static function is_localized_settings_save_request(): bool {
		if ( empty( $_REQUEST['option_page'] ) || ! is_scalar( $_REQUEST['option_page'] ) ) {
			return false;
		}

		$group = wp_unslash( (string) $_REQUEST['option_page'] );

		return in_array( $group, self::LOCALIZED_SETTINGS_GROUPS, true );
	}

	private static function get_requested_default_language_code(): string {
		if ( empty( $_REQUEST[ self::SETTINGS_DEFAULT_LANGUAGE_REQUEST_KEY ] ) || ! is_scalar( $_REQUEST[ self::SETTINGS_DEFAULT_LANGUAGE_REQUEST_KEY ] ) ) {
			return '';
		}

		return self::normalize_language_key( wp_unslash( (string) $_REQUEST[ self::SETTINGS_DEFAULT_LANGUAGE_REQUEST_KEY ] ) );
	}

	private static function is_shadow_option_for_language( string $option, string $language_code ): bool {
		return $option === self::build_localized_option_name( self::strip_shadow_suffix( $option ), $language_code )
			&& self::strip_shadow_suffix( $option ) !== $option;
	}

	private static function strip_shadow_suffix( string $option ): string {
		$pattern = '/' . preg_quote( self::LOCALIZED_OPTION_SUFFIX, '/' ) . '[a-z0-9_]+$/';

		return (string) preg_replace( $pattern, '', $option );
	}

	private static function is_protected_settings_option( string $option ): bool {
		return 0 === strpos( $option, 'quarantined_cpt_bodyclean_' ) || 0 === strpos( $option, 'service_cpt_' );
	}

	private static function get_multilingual_provider_name(): string {
		if ( defined( 'POLYLANG_VERSION' ) ) {
			return 'Polylang';
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'WPML';
		}

		if ( self::is_polylang_available() ) {
			return 'Polylang';
		}

		if ( self::is_wpml_available() ) {
			return 'WPML';
		}

		return 'Multilingual';
	}

	private static function should_use_localized_option( string $option ): bool {
		if ( ! self::is_multilingual_active() || ! in_array( $option, self::get_localized_base_options(), true ) ) {
			return false;
		}

		$current_language = self::get_current_language_code();
		$default_language = self::get_default_language_code();

		return '' !== $current_language && '' !== $default_language && $current_language !== $default_language;
	}

	private static function build_localized_option_name( string $option, string $language_code ): string {
		$language_code = self::normalize_language_key( $language_code );

		if ( '' === $language_code ) {
			return $option;
		}

		return $option . self::LOCALIZED_OPTION_SUFFIX . $language_code;
	}

	private static function normalize_language_key( string $language_code ): string {
		$language_code = strtolower( trim( $language_code ) );
		$language_code = preg_replace( '/[^a-z0-9]+/', '_', $language_code );

		return is_string( $language_code ) ? trim( $language_code, '_' ) : '';
	}

	/**
	 * Reads a raw option row without applying option defaults or filters.
	 *
	 * @param string $option Option name.
	 * @return array{exists:bool,value:mixed}
	 */
	private static function get_raw_option_value( string $option ): array {
		self::prime_localized_option_cache();

		if ( isset( self::$localized_option_cache[ $option ] ) ) {
			return self::$localized_option_cache[ $option ];
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option
			)
		);

		if ( null === $row ) {
			self::$localized_option_cache[ $option ] = [
				'exists' => false,
				'value'  => null,
			];

			return self::$localized_option_cache[ $option ];
		}

		self::$localized_option_cache[ $option ] = [
			'exists' => true,
			'value'  => maybe_unserialize( $row->option_value ),
		];

		return self::$localized_option_cache[ $option ];
	}

	private static function reset_localized_option_cache(): void {
		self::$localized_option_cache_language = '';
		self::$localized_option_cache          = [];
	}

	private static function prime_localized_option_cache(): void {
		$current_language = self::get_current_language_code();

		if ( self::$localized_option_cache_language === $current_language ) {
			return;
		}

		self::$localized_option_cache_language = $current_language;
		self::$localized_option_cache          = [];

		if ( '' === $current_language ) {
			return;
		}

		$shadow_options = array_map(
			static function ( string $option ) use ( $current_language ): string {
				return self::build_localized_option_name( $option, $current_language );
			},
			self::get_localized_base_options()
		);

		foreach ( $shadow_options as $shadow_option ) {
			self::$localized_option_cache[ $shadow_option ] = [
				'exists' => false,
				'value'  => null,
			];
		}

		if ( empty( $shadow_options ) ) {
			return;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $shadow_options ), '%s' ) );
		$sql          = "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $shadow_options ) );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row->option_name ) ) {
				continue;
			}

			self::$localized_option_cache[ (string) $row->option_name ] = [
				'exists' => true,
				'value'  => maybe_unserialize( $row->option_value ),
			];
		}
	}
}
