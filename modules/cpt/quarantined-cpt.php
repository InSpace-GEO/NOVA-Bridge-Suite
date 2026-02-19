<?php
/**
 * NOVA Bridge Suite module: Blog CPT.
 */

namespace SEORAI\BodycleanCPT;

use WP_User;

defined( 'ABSPATH' ) || exit;

$nova_bridge_suite_plugin_class = __NAMESPACE__ . '\\Plugin';

if ( \class_exists( $nova_bridge_suite_plugin_class, false ) ) {
	$nova_bridge_suite_plugin_class::bootstrap();
	return;
}

/**
 * Bootstraps the SEOR CPT plugin.
 */
final class Plugin {

	/**
	 * Name of the quarantined post type.
	 */
	private const CPT = 'blog';

	const OLD_CPT = 'quarantined_page';
	/**
	 * Base slug used for rewrite rules.
	 */
	private const BASE_SLUG = 'quarantined';

	/**
	 * Option that tracks whether rewrite rules are ready.
	 */
	private const REWRITE_READY_OPTION = 'quarantined_cpt_rewrite_ready';

	/**
	 * Option key used to store custom exclusion selectors.
	 */
	private const OPTION_EXCLUDE_SELECTORS = 'quarantined_cpt_bodyclean_exclude_selectors';

	/**
	 * Option key used to store component visibility toggles.
	 */
	private const OPTION_COMPONENT_VISIBILITY = 'quarantined_cpt_bodyclean_components';

	/**
	 * Option key that toggles the plugin-managed author archive template.
	 */
	private const OPTION_AUTHOR_ARCHIVE = 'quarantined_cpt_bodyclean_author_archive';

	/**
	 * Option key storing custom CPT slug.
	 */
	private const OPTION_CPT_SLUG = 'quarantined_cpt_bodyclean_cpt_slug';

	/**
	 * Option key storing custom CPT singular label.
	 */
	private const OPTION_CPT_SINGULAR = 'quarantined_cpt_bodyclean_cpt_singular';

	/**
	 * Option key storing custom CPT plural label.
	 */
	private const OPTION_CPT_PLURAL = 'quarantined_cpt_bodyclean_cpt_plural';

	/**
	 * Option key storing multiple CPT definitions.
	 */
	private const OPTION_CPTS = 'quarantined_cpt_bodyclean_cpts';

	/**
	 * Option key toggling CPT registration.
	 */
	private const OPTION_ENABLE_CPTS = 'quarantined_cpt_bodyclean_enable_cpts';

	/**
	 * Option key storing custom author archive base.
	 */
	private const OPTION_AUTHOR_BASE = 'quarantined_cpt_bodyclean_author_base';

	/**
	 * Option key storing current rewrite signature.
	 */
	private const OPTION_REWRITE_SIGNATURE = 'quarantined_cpt_bodyclean_rewrite_signature';

	/**
	 * Option key storing author archive title.
	 */
	private const OPTION_AUTHOR_ARCHIVE_TITLE = 'quarantined_cpt_bodyclean_author_archive_title';

	/**
	 * Option key storing breadcrumb separator selection.
	 */
	private const OPTION_BREADCRUMB_SEPARATOR = 'quarantined_cpt_bodyclean_breadcrumb_separator';

	/**
	 * Option key storing the header offset used for top spacing.
	 */
	private const OPTION_HEADER_OFFSET = 'quarantined_cpt_bodyclean_header_offset';

	/**
	 * Default header offset value.
	 */
	private const DEFAULT_HEADER_OFFSET = '6rem';

	/**
	 * Option key storing the structured data type for CPT items.
	 */
	private const OPTION_ARTICLE_SCHEMA_TYPE = 'quarantined_cpt_bodyclean_schema_type';

	/**
	 * Default structured data type identifier.
	 */
	private const DEFAULT_ARTICLE_SCHEMA_TYPE = 'blogPosting';

	/**
	 * Allowed structured data type identifiers.
	 *
	 * @var array<string,string>
	 */
	private const ARTICLE_SCHEMA_TYPES = [
		'blogPosting' => 'BlogPosting',
		'article'     => 'Article',
		'techArticle' => 'TechArticle',
		'howTo'       => 'HowTo',
	];

	/**
	 * Option key controlling whether body clean isolation is active.
	 */
	private const OPTION_BODYCLEAN_ENABLED = 'quarantined_cpt_bodyclean_enable';

	/**
	 * Option key storing the author label prefix.
	 */
	private const OPTION_LABEL_AUTHOR = 'quarantined_cpt_bodyclean_label_author';

	/**
	 * Option key storing the publications label.
	 */
	private const OPTION_LABEL_PUBLICATIONS = 'quarantined_cpt_bodyclean_label_publications';

	/**
	 * Default author label text.
	 */
	private const DEFAULT_LABEL_AUTHOR = 'Door';

	/**
	 * Default publications label pattern.
	 */
	private const DEFAULT_LABEL_PUBLICATIONS = '%s publications';

	/**
	 * User meta key storing custom author display name.
	 */
	private const META_AUTHOR_DISPLAY = 'quarantined_cpt_bodyclean_author_display';

	/**
	 * User meta key storing custom author slug.
	 */
	private const META_AUTHOR_SLUG = 'quarantined_cpt_bodyclean_author_slug';

	/**
	 * User meta key storing structured author social links.
	 */
	private const META_AUTHOR_SOCIAL = 'quarantined_cpt_bodyclean_author_social';

	/**
	 * User meta key storing author job title.
	 */
	private const META_AUTHOR_TITLE = 'quarantined_cpt_bodyclean_author_title';

	/**
	 * User meta key storing author organisation name.
	 */
	private const META_AUTHOR_ORG = 'quarantined_cpt_bodyclean_author_org';

	/**
	 * User meta key storing author organisation URL.
	 */
	private const META_AUTHOR_ORG_URL = 'quarantined_cpt_bodyclean_author_org_url';

	/**
	 * User meta key storing author location label.
	 */
	private const META_AUTHOR_LOCATION = 'quarantined_cpt_bodyclean_author_location';

	/**
	 * User meta key storing author website URL.
	 */
	private const META_AUTHOR_WEBSITE = 'quarantined_cpt_bodyclean_author_website';

	/**
	 * User meta key storing the custom author avatar attachment ID.
	 */
	private const META_AUTHOR_AVATAR = 'quarantined_cpt_bodyclean_author_avatar';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Tracks whether external schema providers were enhanced.
	 *
	 * @var bool
	 */
	private $person_schema_integrated = false;

	/**
	 * Ensures theme-provided Person schema is suppressed once.
	 *
	 * @var bool
	 */
	private $author_schema_suppressed = false;

	/**
	 * Ensures inline author styles print only once.
	 *
	 * @var bool
	 */
	private $author_inline_styles_printed = false;

	/**
	 * Stores selectors used for buffered cleanup in the current request.
	 *
	 * @var array|null
	 */
	private $buffer_selectors = null;

	/**
	 * Tracks whether the current buffer pass should drop external Person schema.
	 *
	 * @var bool
	 */
	private $buffer_remove_person_schema = false;

	/**
	 * Output buffer level for the DOM cleanup pass.
	 *
	 * @var int|null
	 */
	private $buffer_level = null;

	/**
	 * Output buffer handler name for the cleanup pass.
	 *
	 * @var string
	 */
	private $buffer_handler = '';

	/**
	 * Tracks whether the shutdown flush hook was added.
	 *
	 * @var bool
	 */
	private $buffer_flush_registered = false;

	/**
	 * Cached CPT definitions for the current request.
	 *
	 * @var array|null
	 */
	private $cpt_definitions = null;

	/**
	 * Ensures the plugin is bootstrapped once.
	 */
	public static function bootstrap(): void {
		self::log( 'bootstrap invoked' );

		if ( null === self::$instance ) {
			self::$instance = new self();
			self::log( 'instance created' );
		}
	}

	/**
	 * Runs tasks on plugin activation.
	 */
	
	/**
	 * One-time migration: rename existing posts from OLD_CPT to CPT.
	 */
	private function migrate_old_cpt_to_new(): void {
		if ( ! $this->cpt_registration_enabled() ) {
			return;
		}

		$target = $this->get_primary_cpt_definition();

		if ( ! $target || empty( $target['type'] ) ) {
			return;
		}

		if ( ! post_type_exists( self::OLD_CPT ) ) {
			return; // nothing to migrate
		}
		$ids = get_posts([
			'post_type'      => self::OLD_CPT,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'post_status'    => 'any',
		]);
		if ( empty( $ids ) ) {
			return;
		}
		foreach ( $ids as $pid ) {
			// set_post_type is lighter than wp_update_post for just type changes
			set_post_type( $pid, (string) $target['type'] );
		}
		self::log( 'Migrated ' . count( $ids ) . ' posts from ' . self::OLD_CPT . ' to ' . (string) $target['type'] );
	}

	public static function activate(): void {
		self::bootstrap();
		if ( null === self::$instance ) {
			return;
		}

		self::$instance->migrate_old_cpt_to_new();
		self::$instance->register_post_type();
		self::$instance->register_author_rewrites();
		flush_rewrite_rules();
		if ( self::$instance->rewrite_rules_are_ready() ) {
			update_option( self::REWRITE_READY_OPTION, 1 );
		} else {
			delete_option( self::REWRITE_READY_OPTION );
		}
	}

	/**
	 * Runs tasks on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
		delete_option( self::REWRITE_READY_OPTION );
	}

	/**
	 * Hooks plugin internals into WordPress.
	 */
	private function __construct() {
		self::log( 'constructor start' );
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_author_rewrites' ], 9 );
		add_filter( 'template_include', [ $this, 'force_isolated_template' ] );
		add_filter( 'body_class', [ $this, 'append_body_class' ] );
		add_filter( 'enter_title_here', [ $this, 'update_title_placeholder' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_head', [ $this, 'output_author_schema' ], 5 );
		add_action( 'wp_head', [ $this, 'output_article_schema' ], 6 );
		add_action( 'wp_head', [ $this, 'output_author_breadcrumb_schema' ], 7 );
		add_filter( 'wpseo_schema_person', [ $this, 'filter_wpseo_person_schema' ], 10, 2 );
		add_filter( 'wpseo_schema_graph_pieces', [ $this, 'filter_wpseo_graph_pieces' ], 5 );
		add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
		add_action( 'pre_get_posts', [ $this, 'include_in_author_archives' ], 20 );
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_icon_styles' ] );
		add_filter( 'author_link', [ $this, 'filter_author_link' ], 10, 3 );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'init', [ $this, 'maybe_flush_rewrite_rules' ], 20 );
		add_filter( 'request', [ $this, 'maybe_force_cpt_routing' ] );
		add_action( 'template_redirect', [ $this, 'maybe_buffer_output' ], 0 );
		add_action( 'show_user_profile', [ $this, 'render_user_fields' ] );
		add_action( 'edit_user_profile', [ $this, 'render_user_fields' ] );
		add_action( 'personal_options_update', [ $this, 'save_user_fields' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_user_fields' ] );
		self::log( 'constructor hooks registered' );
	}

	/**
	 * Registers the quarantined custom post type(s).
	 */
	public function register_post_type(): void {
		self::log( 'register_post_type start' );

		$definitions = $this->get_cpt_definitions();

		if ( empty( $definitions ) ) {
			self::log( 'register_post_type skipped: no CPT definitions enabled' );
			return;
		}

		foreach ( $definitions as $definition ) {
			$post_type    = $definition['type'];
			$cpt_singular = $definition['singular'];
			$cpt_plural   = $definition['plural'];
			$base_slug    = $this->get_base_slug( $post_type );
			$rest_base    = $base_slug;

			$labels = [
				'name'                  => $cpt_plural,
				'singular_name'         => $cpt_singular,
				'add_new'               => __( 'Add New', 'nova-bridge-suite' ),
				/* translators: %s: CPT singular label. */
				'add_new_item'          => sprintf( __( 'Add New %s', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT singular label. */
				'edit_item'             => sprintf( __( 'Edit %s', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT singular label. */
				'new_item'              => sprintf( __( 'New %s', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT singular label. */
				'view_item'             => sprintf( __( 'View %s', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT plural label. */
				'search_items'          => sprintf( __( 'Search %s', 'nova-bridge-suite' ), $cpt_plural ),
				/* translators: %s: CPT plural label. */
				'not_found'             => sprintf( __( 'No %s found.', 'nova-bridge-suite' ), strtolower( $cpt_plural ) ),
				/* translators: %s: CPT plural label. */
				'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'nova-bridge-suite' ), strtolower( $cpt_plural ) ),
				/* translators: %s: CPT plural label. */
				'all_items'             => sprintf( __( 'All %s', 'nova-bridge-suite' ), $cpt_plural ),
				/* translators: %s: CPT singular label. */
				'archives'              => sprintf( __( '%s Archives', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT singular label. */
				'attributes'            => sprintf( __( '%s Attributes', 'nova-bridge-suite' ), $cpt_singular ),
				/* translators: %s: CPT singular label. */
				'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'nova-bridge-suite' ), strtolower( $cpt_singular ) ),
				/* translators: %s: CPT plural label. */
				'filter_items_list'     => sprintf( __( 'Filter %s list', 'nova-bridge-suite' ), strtolower( $cpt_plural ) ),
				/* translators: %s: CPT plural label. */
				'items_list'            => sprintf( __( '%s list', 'nova-bridge-suite' ), strtolower( $cpt_plural ) ),
				'menu_name'             => $cpt_plural,
			];

			$args = [
				'labels'             => $labels,
				'public'             => true,
				'has_archive'        => $base_slug,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'rest_base'          => $rest_base,
				'menu_position'      => 22,
				'menu_icon'          => plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt-rocket.svg',
				'supports'           => [ 'title', 'editor', 'author', 'thumbnail', 'excerpt', 'revisions' ],
				'rewrite'            => [
					'slug'       => $base_slug,
					'with_front' => false,
				],
			];

			register_post_type( $post_type, $args );
		}

		self::log( 'register_post_type completed' );
	}

	/**
	 * Returns the rewrite base slug, allowing customization via filter.
	 *
	 * @param string|null $post_type Optional CPT key.
	 * @return string
	 */
	private function get_base_slug( string $post_type = null ): string {
		$definition = $post_type ? $this->get_cpt_definition_by_type( $post_type ) : $this->get_primary_cpt_definition();

		if ( ! $definition ) {
			return self::BASE_SLUG;
		}

		$slug = apply_filters( 'quarantined_cpt/base_slug', $definition['slug'], $post_type, $definition, $this );
		$slug = sanitize_title_with_dashes( (string) $slug );

		return '' === $slug ? self::BASE_SLUG : $slug;
	}

	private function get_cpt_singular_name( string $post_type = null ): string {
		$definition = $post_type ? $this->get_cpt_definition_by_type( $post_type, false ) : $this->get_primary_cpt_definition( false );
		$default    = $this->get_legacy_cpt_definition()['singular'];

		if ( $definition && ! empty( $definition['singular'] ) ) {
			return (string) $definition['singular'];
		}

		return $default;
	}

	private function get_cpt_plural_name( string $post_type = null ): string {
		$definition = $post_type ? $this->get_cpt_definition_by_type( $post_type, false ) : $this->get_primary_cpt_definition( false );
		$default    = $this->get_legacy_cpt_definition()['plural'];

		if ( $definition && ! empty( $definition['plural'] ) ) {
			return (string) $definition['plural'];
		}

		return $default;
	}

	/**
	 * Returns active CPT definitions with sanitization applied.
	 *
	 * @param bool $respect_toggle Whether to respect the enable toggle.
	 * @return array
	 */
	private function get_cpt_definitions( bool $respect_toggle = true ): array {
		if ( $respect_toggle && null !== $this->cpt_definitions ) {
			return $this->cpt_definitions;
		}

		$raw          = get_option( self::OPTION_CPTS, null );
		$definitions  = $this->sanitize_cpt_definitions_option( null === $raw ? [] : $raw );
		$has_explicit = null !== $raw;

		if ( empty( $definitions ) && ! $has_explicit ) {
			$definitions = [ $this->get_legacy_cpt_definition() ];
		}

		if ( $respect_toggle && ! $this->cpt_registration_enabled() ) {
			$definitions = [];
		}

		if ( $respect_toggle ) {
			$this->cpt_definitions = $definitions;
		}

		return $definitions;
	}

	private function get_cpt_definition_by_type( string $post_type, bool $respect_toggle = true ): ?array {
		$post_type   = sanitize_key( $post_type );
		$definitions = $this->get_cpt_definitions( $respect_toggle );

		foreach ( $definitions as $definition ) {
			if ( isset( $definition['type'] ) && $definition['type'] === $post_type ) {
				return $definition;
			}
		}

		return null;
	}

	private function get_primary_cpt_definition( bool $respect_toggle = true ): ?array {
		$definitions = $this->get_cpt_definitions( $respect_toggle );

		if ( empty( $definitions ) ) {
			return null;
		}

		return $definitions[0];
	}

	private function get_cpt_types( bool $respect_toggle = true ): array {
		$definitions = $this->get_cpt_definitions( $respect_toggle );

		if ( empty( $definitions ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $definition ) {
						return isset( $definition['type'] ) ? (string) $definition['type'] : '';
					},
					$definitions
				)
			)
		);
	}

	private function get_rest_base_for_type( string $post_type ): string {
		return $this->get_base_slug( $post_type );
	}

	private function get_legacy_cpt_definition(): array {
		$default_singular = __( 'Quarantined Page', 'nova-bridge-suite' );
		$default_plural   = __( 'Quarantined Pages', 'nova-bridge-suite' );
		$singular         = $this->sanitize_text_option( get_option( self::OPTION_CPT_SINGULAR, $default_singular ) );
		$plural           = $this->sanitize_text_option( get_option( self::OPTION_CPT_PLURAL, $default_plural ) );
		$slug             = $this->sanitize_slug_option( get_option( self::OPTION_CPT_SLUG, self::BASE_SLUG ) );

		if ( '' === $singular ) {
			$singular = $default_singular;
		}

		if ( '' === $plural ) {
			$plural = $default_plural;
		}

		$rest_base = sanitize_title( $slug );

		if ( '' === $rest_base ) {
			$rest_base = self::BASE_SLUG;
		}

		return [
			'type'      => $rest_base,
			'slug'      => $slug,
			'rest_base' => $rest_base,
			'singular'  => $singular,
			'plural'    => $plural,
			'schema_type' => self::DEFAULT_ARTICLE_SCHEMA_TYPE,
		];
	}

	private function cpt_registration_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLE_CPTS, true );
	}

	private function is_managed_post_type( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );

		return in_array( $post_type, $this->get_cpt_types(), true );
	}

	private function get_author_base(): string {
		$default = 'authors';
		$base    = get_option( self::OPTION_AUTHOR_BASE, $default );
		$base    = apply_filters( 'quarantined_cpt_bodyclean/author_base', $base );
		$base    = sanitize_title_with_dashes( (string) $base );

		return '' === $base ? $default : $base;
	}

	private function get_author_archive_title(): string {
		$default = __( 'Authors', 'nova-bridge-suite' );
		$title   = get_option( self::OPTION_AUTHOR_ARCHIVE_TITLE, $default );
		$title   = sanitize_text_field( (string) $title );

		return '' === $title ? $default : $title;
	}

	private function get_breadcrumb_separator_choice(): string {
		$default = 'chevron';
		$choice  = get_option( self::OPTION_BREADCRUMB_SEPARATOR, $default );

		if ( ! is_string( $choice ) ) {
			$choice = $default;
		}

		$choices = $this->get_breadcrumb_separator_options();

		if ( ! array_key_exists( $choice, $choices ) ) {
			return $default;
		}

		return $choice;
	}

	private function get_breadcrumb_separator_options(): array {
		return apply_filters(
			'quarantined_cpt_bodyclean/breadcrumb_separator_options',
			[
				'chevron' => '›',
				'slash'   => '/',
				'dash'    => '-',
				'arrow'   => '→',
				'pipe'    => '|',
				'less'    => '<',
			]
		);
	}

	private function resolve_breadcrumb_separator(): string {
		$choices = $this->get_breadcrumb_separator_options();
		$choice  = $this->get_breadcrumb_separator_choice();

		return $choices[ $choice ] ?? '›';
	}

	/**
	 * Retrieves the configured header offset for viewport spacing.
	 *
	 * @return string
	 */
	private function get_header_offset_setting(): string {
		$stored = get_option( self::OPTION_HEADER_OFFSET, self::DEFAULT_HEADER_OFFSET );

		if ( ! is_string( $stored ) ) {
			$stored = self::DEFAULT_HEADER_OFFSET;
		}

		$normalized = $this->normalize_header_offset( wp_strip_all_tags( $stored ) );

		return '' === $normalized ? self::DEFAULT_HEADER_OFFSET : $normalized;
	}

	/**
	 * Normalizes a CSS length/clamp expression for safe inline usage.
	 *
	 * @param string $value Raw value from settings.
	 * @return string
	 */
	
	private function normalize_header_offset( string $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		// Keep only CSS-safe characters.
		$clean = preg_replace( '/[^0-9a-zA-Z.%\-\s\(\)+,\/\*_]/', '', $value );
		if ( ! is_string( $clean ) ) {
			return '';
		}
		$clean = trim( $clean );
		if ( '' === $clean ) {
			return '';
		}
		// Forgive spaces between numbers and units: e.g., "120 px" => "120px".
		$clean = preg_replace( '/(\d)\s+(?=[a-zA-Z%])/', '$1', $clean );
		if ( ! is_string( $clean ) ) {
			return '';
		}
		$lower = strtolower( $clean );
		// Allow plain zero with or without units.
		if ( preg_match( '/^0(?:\s*(?:px|rem|em|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|lh|rlh|q|pc|pt|mm|cm|in|%))?$/', $lower ) ) {
			return trim( $clean );
		}
		// <number><unit> with a wide unit allow-list.
		if ( preg_match( '/^-?\d+(?:\.\d+)?(?:px|rem|em|vh|vw|vmin|vmax|svh|lvh|dvh|svw|lvw|dvw|ch|ex|lh|rlh|q|pc|pt|mm|cm|in|%)$/', $lower ) ) {
			return $clean;
		}
		// Allow common CSS functions: calc(), clamp(), min(), max(), and env()/var() wrappers.
		foreach ( [ 'calc', 'clamp', 'min', 'max', 'env', 'var' ] as $fn ) {
			if ( 0 === strpos( $lower, $fn . '(' ) && ')' === substr( $lower, -1 ) ) {
				return $clean;
			}
		}
		return '';
	}

	private function is_bodyclean_enabled(): bool {
		return false;
	}

	/**
	 * Returns the stored article schema type identifier.
	 *
	 * @param string|null $post_type Optional CPT key.
	 * @return string
	 */
	private function get_article_schema_type( string $post_type = null ): string {
		if ( $post_type ) {
			$definition = $this->get_cpt_definition_by_type( $post_type, false );

			if ( $definition && ! empty( $definition['schema_type'] ) ) {
				return $this->sanitize_article_schema_type_option( $definition['schema_type'] );
			}
		}

		return self::DEFAULT_ARTICLE_SCHEMA_TYPE;
	}

	/**
	 * Returns schema type choices for the settings dropdown.
	 *
	 * @return array<string,string>
	 */
	private function get_article_schema_choices(): array {
		$choices = [
			'blogPosting' => __( 'BlogPosting (default)', 'nova-bridge-suite' ),
			'article'     => __( 'Article', 'nova-bridge-suite' ),
			'techArticle' => __( 'TechArticle', 'nova-bridge-suite' ),
			'howTo'       => __( 'HowTo', 'nova-bridge-suite' ),
		];

		return apply_filters( 'quarantined_cpt_bodyclean/article_schema_choices', $choices, $this );
	}

	private function should_enable_bodyclean( string $context ): bool {
		$enabled = $this->is_bodyclean_enabled();
		$enabled = apply_filters( 'quarantined_cpt_bodyclean/enable_bodyclean', $enabled, $context, $this );

		return (bool) $enabled;
	}

	private function get_author_label(): string {
		$default = __( 'Door', 'nova-bridge-suite' );
		$label   = get_option( self::OPTION_LABEL_AUTHOR, $default );
		$label   = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label   = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_publications_label(): string {
		/* translators: %s: number of publications. */
		$default = __( '%s publications', 'nova-bridge-suite' );
		$label   = get_option( self::OPTION_LABEL_PUBLICATIONS, $default );
		$label   = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label   = trim( $label );

		if ( '' === $label ) {
			$label = $default;
		}

		if ( false === strpos( $label, '%s' ) ) {
			$label .= ' %s';
		}

		return $label;
	}

	/**
	 * Forces single CPT views through the plugin-controlled template.
	 *
	 * @param string $template The template WordPress planned to use.
	 * @return string
	 */
	public function force_isolated_template( string $template ): string {
		$post_types = $this->get_cpt_types();

		if ( ! empty( $post_types ) && is_singular( $post_types ) ) {
			return plugin_dir_path( __FILE__ ) . 'templates/single-quarantined-page.php';
		} elseif ( ! empty( $post_types ) && is_post_type_archive( $post_types ) ) {
			return plugin_dir_path( __FILE__ ) . 'templates/archive-quarantined-page.php';
		} elseif ( self::author_archive_enabled() && get_query_var( 'quarantined_cpt_authors' ) ) {
			return plugin_dir_path( __FILE__ ) . 'templates/author-quarantined-index.php';
		} elseif ( self::author_archive_enabled() && ( get_query_var( 'quarantined_cpt_author' ) || is_author() ) ) {
			return plugin_dir_path( __FILE__ ) . 'templates/author-quarantined-page.php';
		}

		return $template;
	}

	public function register_author_rewrites(): void {
		if ( ! self::author_archive_enabled() ) {
			return;
		}

		$base = trim( $this->get_author_base(), '/' );

		if ( '' === $base ) {
			$base = 'authors';
		}

		add_rewrite_rule( '^' . $base . '/?$', 'index.php?quarantined_cpt_authors=1', 'top' );
		add_rewrite_rule(
			'^' . $base . '/([^/]+)/?$',
			'index.php?author_name=$matches[1]&quarantined_cpt_author=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '/([^/]+)/page/([0-9]+)/?$',
			'index.php?author_name=$matches[1]&paged=$matches[2]&quarantined_cpt_author=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '/([^/]+)/(feed|rdf|rss|rss2|atom)/?$',
			'index.php?author_name=$matches[1]&feed=$matches[2]&quarantined_cpt_author=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . $base . '/([^/]+)/feed/(feed|rdf|rss|rss2|atom)/?$',
			'index.php?author_name=$matches[1]&feed=$matches[2]&quarantined_cpt_author=$matches[1]',
			'top'
		);
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'quarantined_cpt_authors';
		$vars[] = 'quarantined_cpt_author';

		return array_unique( $vars );
	}

	/**
	 * Enqueues isolated styling for quarantined views.
	 */
	public function enqueue_assets(): void {
		if ( is_admin() ) {
			return;
		}

		$post_types       = $this->get_cpt_types();
		$is_author_context = $this->is_author_context();

		$is_cpt_context = ! empty( $post_types ) && ( is_singular( $post_types ) || is_post_type_archive( $post_types ) );

		if ( ! ( $is_cpt_context || $is_author_context ) ) {
			return;
		}

		$context = $is_cpt_context ? 'single' : 'author';

		if ( $is_cpt_context && is_post_type_archive( $post_types ) ) {
			$context = 'archive';
		}

		$bodyclean_active = $this->should_enable_bodyclean( $context );

		$handle = 'quarantined-cpt-bodyclean';

		wp_register_style(
			$handle,
			plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt.css',
			[],
			'1.0.0'
		);

		wp_enqueue_style( $handle );

		$this->output_author_inline_styles( $handle );

		$enable_dom_cleaner = $bodyclean_active && apply_filters( 'quarantined_cpt_bodyclean/enable_dom_cleaner', true, $context, $this );

		if ( $enable_dom_cleaner ) {
			$script_handle = 'quarantined-cpt-bodyclean-cleaner';

			wp_register_script(
				$script_handle,
				plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt-clean.js',
				[ 'wp-dom-ready' ],
				'1.0.0',
				true
			);

			wp_localize_script(
				$script_handle,
				'QuarantinedCPTBodyClean',
				[
					'mainSelector'      => 'main.quarantined-cpt',
					'keepAttribute'     => 'data-quarantined-keep',
					'allowedSelectors'  => $this->get_allowed_dom_selectors(),
					'boundarySelectors' => $this->get_boundary_selectors(),
					'pruneSelectors'    => $this->get_prune_selectors(),
					'customSelectors'   => $this->get_custom_exclude_selectors(),
				]
			);

			wp_enqueue_script( $script_handle );
		}

		$offset = $this->get_header_offset_setting();
		$offset = apply_filters( 'quarantined_cpt_bodyclean/header_offset', $offset, $this );

		if ( ! is_string( $offset ) ) {
			$offset = self::DEFAULT_HEADER_OFFSET;
		}

		$offset = $this->normalize_header_offset( wp_strip_all_tags( $offset ) );

		if ( '' === $offset ) {
			$offset = self::DEFAULT_HEADER_OFFSET;
		}

		$inline_css = sprintf(
			':root{--quarantined-cpt-header-offset:%1$s;} body.quarantined-cpt-view,body.quarantined-cpt-archive,body.quarantined-cpt-author,.quarantined-cpt{--quarantined-cpt-header-offset:%1$s;} .quarantined-cpt__inner{padding-top:calc(var(--quarantined-cpt-header-offset) + (var(--quarantined-cpt-base-padding,0) * 0.5)) !important;}',
			esc_attr( $offset )
		);

		$custom_rules = $this->build_custom_exclusion_rules();

		if ( $custom_rules ) {
			$inline_css .= "\n" . $custom_rules;
		}

		wp_add_inline_style( $handle, $inline_css );
	}

	/**
	 * Outputs Person schema for plugin-managed author pages.
	 */
	public function output_author_schema(): void {
		if ( is_admin() ) {
			return;
		}

		if ( false === apply_filters( 'quarantined_cpt_bodyclean/enable_author_schema_output', true, $this ) ) {
			return;
		}

		if ( ! self::author_archive_enabled() ) {
			return;
		}

		if ( $this->person_schema_integrated ) {
			return;
		}

		if ( ! $this->is_author_detail_request() ) {
			return;
		}

		$author_slug = get_query_var( 'quarantined_cpt_author' );

		if ( '' === (string) $author_slug ) {
			return;
		}

		$author = get_queried_object();

		if ( ! ( $author instanceof WP_User ) ) {
			return;
		}

		$profile    = $this->build_author_profile_data( $author );
		$name       = self::get_author_display_name( $author );
		$permalink  = self::get_author_permalink( $author );
		$permalink  = $permalink ? $permalink : get_author_posts_url( $author->ID, $author->user_nicename );
		$description = get_the_author_meta( 'description', $author->ID );
		$avatar     = self::get_author_avatar_url( $author, 256, false );

		$schema = [
			'@context'         => 'https://schema.org',
			'@type'            => 'Person',
			'@id'              => trailingslashit( $permalink ) . '#seor-cpt-person',
			'name'             => $name,
			'url'              => $permalink,
			'mainEntityOfPage' => [
				'@type' => 'WebPage',
				'@id'   => $permalink,
			],
		];

		if ( $avatar ) {
			$schema['image'] = $avatar;
		}

		if ( is_string( $description ) && '' !== trim( $description ) ) {
			$schema['description'] = wp_strip_all_tags( $description );
		}

		if ( ! empty( $profile['job_title'] ) ) {
			$schema['jobTitle'] = $profile['job_title'];
		}

		if ( ! empty( $profile['organisation']['name'] ) ) {
			$organization = [
				'@type' => 'Organization',
				'name'  => $profile['organisation']['name'],
			];

			if ( ! empty( $profile['organisation']['url'] ) ) {
				$organization['url'] = $profile['organisation']['url'];
			}

			$schema['worksFor'] = $organization;
		}

		if ( ! empty( $profile['location'] ) ) {
			$schema['homeLocation'] = [
				'@type' => 'Place',
				'name'  => $profile['location'],
			];
		}

		$same_as = [];

		if ( ! empty( $profile['website'] ) ) {
			$same_as[] = $profile['website'];
		}

		if ( ! empty( $profile['social'] ) && is_array( $profile['social'] ) ) {
			foreach ( $profile['social'] as $item ) {
				if ( empty( $item['url'] ) ) {
					continue;
				}

				$same_as[] = $item['url'];
			}
		}

		$same_as = array_values( array_unique( array_filter( $same_as ) ) );

		if ( ! empty( $same_as ) ) {
			$schema['sameAs'] = $same_as;
		}

		$schema = apply_filters( 'quarantined_cpt_bodyclean/author_schema', $schema, $author, $profile );

		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}

		$this->person_schema_integrated = true;

		printf(
			'<script type="application/ld+json">%s</script>',
			wp_kses( wp_json_encode( $schema ), [] )
		);
	}

	/**
	 * Outputs breadcrumb structured data for author archive contexts.
	 */
	public function output_author_breadcrumb_schema(): void {
		if ( is_admin() ) {
			return;
		}

		if ( ! self::author_archive_enabled() ) {
			return;
		}

		if ( ! self::component_enabled( 'breadcrumbs' ) ) {
			return;
		}

		if ( ! $this->is_author_context() ) {
			return;
		}

		$crumbs = $this->get_author_breadcrumb_trail();

		if ( empty( $crumbs ) ) {
			return;
		}

		$items = [];

		foreach ( $crumbs as $index => $crumb ) {
			$name = isset( $crumb['label'] ) ? trim( (string) $crumb['label'] ) : '';

			if ( '' === $name ) {
				continue;
			}

			$item = [
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $name,
			];

			$url = isset( $crumb['url'] ) ? (string) $crumb['url'] : '';

			if ( '' !== $url ) {
				$item['item'] = $url;
			}

			$items[] = $item;
		}

		if ( count( $items ) < 2 ) {
			return;
		}

		$schema = [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		];

		$schema = apply_filters( 'quarantined_cpt_bodyclean/author_breadcrumb_schema', $schema, $crumbs, $this );

		if ( ! is_array( $schema ) || empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema );

		if ( ! $json ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>',
			wp_kses( $json, [] )
		);
	}

	/**
	 * Builds the breadcrumb trail definition for author listings and detail views.
	 *
	 * @return array[]
	 */
	private function get_author_breadcrumb_trail(): array {
		if ( ! self::author_archive_enabled() ) {
			return [];
		}

		$crumbs = [];

		$crumbs[] = [
			'label'   => __( 'Home', 'nova-bridge-suite' ),
			'url'     => home_url( '/' ),
			'current' => false,
		];

		$base_slug = trim( self::get_author_base_slug(), '/' );

		if ( '' === $base_slug ) {
			$base_slug = 'authors';
		}

		$archive_title = self::get_author_archive_title_text();

		if ( '' === trim( $archive_title ) ) {
			$archive_title = __( 'Authors', 'nova-bridge-suite' );
		}

		$archive_url = trailingslashit( home_url( '/' . $base_slug ) );
		$is_detail   = $this->is_author_detail_request();
		$context     = $is_detail ? 'detail' : 'index';

		if ( '' !== $archive_title ) {
			$crumbs[] = [
				'label'   => $archive_title,
				'url'     => $archive_url,
				'current' => false,
			];
		}

		if ( $is_detail ) {
			$author = get_queried_object();

			if ( $author instanceof WP_User ) {
				$crumbs[] = [
					'label'   => self::get_author_display_name( $author ),
					'url'     => self::get_author_permalink( $author ),
					'current' => true,
				];
			}
		}

		$crumbs = apply_filters( 'quarantined_cpt_bodyclean/author_breadcrumb_trail', $crumbs, $context, $this );

		if ( ! is_array( $crumbs ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $crumbs as $crumb ) {
			$label = isset( $crumb['label'] ) ? wp_strip_all_tags( (string) $crumb['label'] ) : '';
			$label = trim( $label );

			if ( '' === $label ) {
				continue;
			}

			$url = isset( $crumb['url'] ) ? esc_url_raw( (string) $crumb['url'] ) : '';

			$normalized[] = [
				'label'   => $label,
				'url'     => $url,
				'current' => ! empty( $crumb['current'] ),
			];
		}

		if ( empty( $normalized ) ) {
			return [];
		}

		$current_index = count( $normalized ) - 1;

		foreach ( $normalized as $index => &$crumb ) {
			$crumb['current'] = ( $index === $current_index );
		}

		unset( $crumb );

		return $normalized;
	}

	/**
	 * Outputs Article/BlogPosting schema for quarantined CPT single views.
	 */
	public function output_article_schema(): void {
		$post_types = $this->get_cpt_types();

		if ( is_admin() || empty( $post_types ) || ! is_singular( $post_types ) ) {
			return;
		}

		if ( false === apply_filters( 'quarantined_cpt_bodyclean/enable_article_schema_output', true, $this ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof \WP_Post ) ) {
			return;
		}

		$schema = $this->build_article_schema_data( $post );
		$schema = apply_filters( 'quarantined_cpt_bodyclean/article_schema', $schema, $post, $this );

		if ( empty( $schema ) || ! is_array( $schema ) ) {
			return;
		}

		printf(
			'<script type="application/ld+json">%s</script>',
			wp_json_encode( $schema )
		);
	}

	/**
	 * Prints inline CSS overrides for author meta to guard against theme resets.
	 */
	public function output_author_inline_styles( string $handle = 'quarantined-cpt-bodyclean' ): void {
		if ( $this->author_inline_styles_printed ) {
			return;
		}

		if ( ! $this->is_author_context() ) {
			return;
		}

		$this->author_inline_styles_printed = true;

		$css = implode(
			"\n",
			[
				'main.quarantined-cpt .quarantined-cpt__author-social {',
				'\tdisplay: flex !important;',
				'\talign-items: center !important;',
				'\tflex-wrap: wrap !important;',
				'\tgap: 0.5rem !important;',
				'\tmargin: 1rem 0 0 !important;',
				'\tpadding: 0 !important;',
				'\tlist-style: none !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social-item {',
				'\tdisplay: inline-flex !important;',
				'\tmargin: 0 !important;',
				'\tlist-style: none !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social-item::marker {',
				'\tdisplay: none !important;',
				"\tcontent: '' !important;",
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social a {',
				'\tdisplay: inline-flex !important;',
				'\talign-items: center !important;',
				'\tjustify-content: center !important;',
				'\twidth: 2.4rem !important;',
				'\theight: 2.4rem !important;',
				'\tmin-width: 2.4rem !important;',
				'\tmin-height: 2.4rem !important;',
				'\tborder-radius: 999px !important;',
				'\ttext-decoration: none !important;',
				'\tcolor: rgba(15, 23, 42, 0.85) !important;',
				'\tbackground-color: rgba(15, 23, 42, 0.08) !important;',
				'\tline-height: 0 !important;',
				'\ttransition: background-color 0.2s ease, transform 0.2s ease !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social a:hover,',
				'main.quarantined-cpt .quarantined-cpt__author-social a:focus {',
				'\tbackground-color: rgba(15, 23, 42, 0.16) !important;',
				'\ttransform: translateY(-1px) !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social-icon {',
				'\tdisplay: inline-block !important;',
				'\twidth: 1.1rem !important;',
				'\theight: 1.1rem !important;',
				'\tmax-width: 1.1rem !important;',
				'\tmax-height: 1.1rem !important;',
				'\tflex: 0 0 1.1rem !important;',
				'\tfill: currentColor !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__sr-only {',
				'\tposition: absolute !important;',
				'\twidth: 1px !important;',
				'\theight: 1px !important;',
				'\tpadding: 0 !important;',
				'\tmargin: -1px !important;',
				'\toverflow: hidden !important;',
				'\tclip: rect(0, 0, 0, 0) !important;',
				'\twhite-space: nowrap !important;',
				'\tborder: 0 !important;',
				'}',
			]
		);

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, [], null );
			wp_enqueue_style( $handle );
		}

		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Builds schema data for quarantined CPT single posts.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<string,mixed>
	 */
	private function build_article_schema_data( \WP_Post $post ): array {
		$permalink = get_permalink( $post );
		$permalink = $permalink ? $permalink : '';
		$headline  = wp_strip_all_tags( get_the_title( $post ) );
		$type_slug = $this->get_article_schema_type( $post->post_type );
		$type_name = self::ARTICLE_SCHEMA_TYPES[ $type_slug ] ?? self::ARTICLE_SCHEMA_TYPES[ self::DEFAULT_ARTICLE_SCHEMA_TYPE ];

		$description = get_the_excerpt( $post );
		$description = is_string( $description ) ? wp_strip_all_tags( $description ) : '';

		if ( '' === trim( $description ) ) {
			$content = wp_strip_all_tags( get_post_field( 'post_content', $post ) );
			$description = wp_trim_words( $content, 40, '…' );
		}

		$description = trim( $description );

		$date_published = get_post_time( DATE_W3C, true, $post );
		$date_modified  = get_post_modified_time( DATE_W3C, true, $post );

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => $type_name,
			'headline' => $headline,
			'name'     => $headline,
		];

		if ( $permalink ) {
			$schema['@id'] = trailingslashit( $permalink ) . '#seor-cpt-article';
			$schema['url'] = $permalink;
			$schema['mainEntityOfPage'] = [
				'@type' => 'WebPage',
				'@id'   => $permalink,
			];
		}

		if ( $description ) {
			$schema['description'] = $description;
		}

		if ( $date_published ) {
			$schema['datePublished'] = $date_published;
		}

		if ( $date_modified ) {
			$schema['dateModified'] = $date_modified;
		}

		$author = get_userdata( (int) $post->post_author );

		if ( $author instanceof WP_User ) {
			$author_name = self::get_author_display_name( $author );
			$author_url  = self::author_archive_enabled() ? self::get_author_permalink( $author ) : get_author_posts_url( $author->ID, $author->user_nicename );

			$author_schema = [
				'@type' => 'Person',
				'name'  => $author_name,
			];

			if ( $author_url ) {
				$author_schema['url'] = $author_url;
			}

			$schema['author'] = $author_schema;
		}

		$publisher_name = get_bloginfo( 'name' );

		if ( $publisher_name ) {
			$publisher = [
				'@type' => 'Organization',
				'name'  => $publisher_name,
			];

			$logo = get_site_icon_url();

			if ( $logo ) {
				$publisher['logo'] = [
					'@type' => 'ImageObject',
					'url'   => $logo,
				];
			}

			$schema['publisher'] = $publisher;
		}

		$site_url   = home_url( '/' );
		$site_id    = trailingslashit( $site_url ) . '#website';
		$site_label = $publisher_name ? $publisher_name : $site_url;

		$schema['isPartOf'] = [
			'@type' => 'WebSite',
			'@id'   => $site_id,
			'name'  => $site_label,
			'url'   => $site_url,
		];

		$featured_id = get_post_thumbnail_id( $post );

		if ( $featured_id ) {
			$image = wp_get_attachment_image_src( $featured_id, 'full' );

			if ( is_array( $image ) && ! empty( $image[0] ) ) {
				$image_object = [
					'@type'  => 'ImageObject',
					'url'    => $image[0],
				];

				if ( isset( $image[1] ) && $image[1] ) {
					$image_object['width'] = (int) $image[1];
				}

				if ( isset( $image[2] ) && $image[2] ) {
					$image_object['height'] = (int) $image[2];
				}

				$schema['image'] = $image_object;
			}
		}

		$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );

		if ( $word_count > 0 ) {
			$schema['wordCount'] = (int) $word_count;
		}

		$categories = get_the_terms( $post, 'category' );

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$sections = array_values(
				array_filter(
					array_map(
						static function ( $term ) {
							return isset( $term->name ) ? trim( $term->name ) : '';
						},
						$categories
					)
				)
			);

			if ( ! empty( $sections ) ) {
				$schema['articleSection'] = $sections;
			}
		}

		$language = get_bloginfo( 'language' );

		if ( $language ) {
			$schema['inLanguage'] = $language;
		}

		if ( 'HowTo' === $type_name ) {
			$steps = $this->build_howto_steps( $post );

			if ( ! empty( $steps ) ) {
				$schema['step'] = $steps;
			}
		}

		return $schema;
	}

	/**
	 * Removes third-party Person schema entries so the plugin can output its enriched version.
	 *
	 * @param array<int,\Yoast\WP\SEO\Generated\Schema\Abstract_Schema_Piece|array> $pieces Schema graph pieces.
	 * @return array
	 */
	public function filter_wpseo_graph_pieces( $pieces ) {
		if ( $this->author_schema_suppressed || ! $this->is_author_detail_request() ) {
			return $pieces;
		}

		$filtered = [];

		foreach ( (array) $pieces as $piece ) {
			$type = null;

			if ( is_object( $piece ) && isset( $piece->context['@type'] ) ) {
				$type = $piece->context['@type'];
			} elseif ( is_array( $piece ) && isset( $piece['@type'] ) ) {
				$type = $piece['@type'];
			}

			if ( $type && ( 'Person' === $type || ( is_array( $type ) && in_array( 'Person', $type, true ) ) ) ) {
				$this->author_schema_suppressed = true;
				continue;
			}

			$filtered[] = $piece;
		}

		return $filtered;
	}

	/**
	 * Attempts to derive HowTo steps from the post content.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_howto_steps( \WP_Post $post ): array {
		$content = get_post_field( 'post_content', $post );
		$content = is_string( $content ) ? $content : '';
		$content = apply_filters( 'the_content', $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$content = wp_strip_all_tags( $content );
		$content = trim( preg_replace( '/\s+/', ' ', $content ) );

		if ( '' === $content ) {
			return [];
		}

		$segments = preg_split( '/(?<=\.)\s+/', $content );
		$steps    = [];
		$seen     = [];
		$position = 1;

		foreach ( (array) $segments as $segment ) {
			$segment = trim( $segment );

			if ( '' === $segment ) {
				continue;
			}

			$key = strtolower( $segment );

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$name        = wp_trim_words( $segment, 8, '…' );
			$text        = wp_trim_words( $segment, 40, '…' );

			$steps[] = [
				'@type'    => 'HowToStep',
				'position' => $position,
				'name'     => $name,
				'text'     => $text,
			];

			$position++;

			if ( $position > 8 ) {
				break;
			}
		}

		return $steps;
	}

	/**
	 * Integrates custom author profile data into Yoast SEO Person schema.
	 *
	 * @param array      $data    Existing Person schema.
	 * @param \stdClass  $context Schema context.
	 * @return array
	 */
	public function filter_wpseo_person_schema( $data, $context ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		if ( is_admin() || ! self::author_archive_enabled() ) {
			return $data;
		}

		if ( ! $this->is_author_detail_request() ) {
			return $data;
		}

		$author = get_queried_object();

		if ( ! ( $author instanceof WP_User ) ) {
			return $data;
		}

		$this->person_schema_integrated = true;

		$profile    = $this->build_author_profile_data( $author );
		$name       = self::get_author_display_name( $author );
		$permalink  = self::get_author_permalink( $author );
		$permalink  = $permalink ? $permalink : get_author_posts_url( $author->ID, $author->user_nicename );
		$description = get_the_author_meta( 'description', $author->ID );
		$avatar     = self::get_author_avatar_url( $author, 256, false );

		if ( $name ) {
			$data['name'] = $name;
		}

		if ( $permalink ) {
			$data['url'] = $permalink;
			$data['mainEntityOfPage'] = [
				'@type' => 'WebPage',
				'@id'   => $permalink,
			];
		}

		if ( is_string( $description ) && '' !== trim( $description ) ) {
			$data['description'] = wp_strip_all_tags( $description );
		}

		if ( $avatar ) {
			$data['image'] = $avatar;
		} elseif ( isset( $data['image'] ) ) {
			unset( $data['image'] );
		}

		if ( ! empty( $profile['job_title'] ) ) {
			$data['jobTitle'] = $profile['job_title'];
		}

		if ( ! empty( $profile['organisation']['name'] ) ) {
			$organization = [
				'@type' => 'Organization',
				'name'  => $profile['organisation']['name'],
			];

			if ( ! empty( $profile['organisation']['url'] ) ) {
				$organization['url'] = $profile['organisation']['url'];
			}

			$data['worksFor'] = $organization;
		}

		if ( ! empty( $profile['location'] ) ) {
			$data['homeLocation'] = [
				'@type' => 'Place',
				'name'  => $profile['location'],
			];
		}

		$same_as = [];

		if ( ! empty( $data['sameAs'] ) && is_array( $data['sameAs'] ) ) {
			$same_as = $data['sameAs'];
		}

		if ( ! empty( $profile['website'] ) ) {
			$same_as[] = $profile['website'];
		}

		if ( ! empty( $profile['social'] ) && is_array( $profile['social'] ) ) {
			foreach ( $profile['social'] as $item ) {
				if ( empty( $item['url'] ) ) {
					continue;
				}

				$same_as[] = $item['url'];
			}
		}

		$same_as = array_values( array_unique( array_filter( $same_as ) ) );

		if ( ! empty( $same_as ) ) {
			$data['sameAs'] = $same_as;
		}

		return $data;
	}

	/**
	 * Adjusts the title placeholder for the CPT editor to provide context.
	 *
	 * @param string   $placeholder Default placeholder text.
	 * @param \WP_Post $post        Current post.
	 * @return string
	 */
	public function update_title_placeholder( string $placeholder, $post ): string {
		if ( isset( $post->post_type ) && $this->is_managed_post_type( (string) $post->post_type ) ) {
			/* translators: %s: CPT singular label. */
			return sprintf( __( '%s title', 'nova-bridge-suite' ), $this->get_cpt_singular_name( (string) $post->post_type ) );
		}

		return $placeholder;
	}

	/**
	 * Adds an identifying body class to allow scoped styling.
	 *
	 * @param string[] $classes Body class list.
	 * @return string[]
	 */
	public function append_body_class( array $classes ): array {
		$post_types      = $this->get_cpt_types();
		$is_cpt_singular = ! empty( $post_types ) && is_singular( $post_types );
		$is_cpt_archive  = ! empty( $post_types ) && is_post_type_archive( $post_types );

		if ( $is_cpt_singular ) {
			$classes[] = 'quarantined-cpt-view';
			if ( $this->should_enable_bodyclean( 'single' ) ) {
				$classes[] = 'quarantined-cpt-bodyclean';
			}
		} elseif ( $is_cpt_archive ) {
			$classes[] = 'quarantined-cpt-archive';
			if ( $this->should_enable_bodyclean( 'archive' ) ) {
				$classes[] = 'quarantined-cpt-bodyclean';
			}
		} elseif ( $this->is_author_context() ) {
			$classes[] = 'quarantined-cpt-author';
			if ( $this->should_enable_bodyclean( 'author' ) ) {
				$classes[] = 'quarantined-cpt-bodyclean';
			}
		}

		return $classes;
	}

	/**
	 * Returns selectors that should be preserved when pruning body markup.
	 *
	 * @return string[]
	 */
	private function get_allowed_dom_selectors(): array {
		$defaults = [
			'header',
			'.site-header',
			'.main-header',
			'.header',
			'.top-bar',
			'.topbar',
			'.navbar',
			'.navigation',
			'.site-navigation',
			'.main-navigation',
			'.menu',
			'.menu-wrapper',
			'.elementor-location-header',
			'.elementor-location-top-bar',
			'nav',
			'.quarantined-keep',
			'a.skip-link',
			'#wpadminbar',
			'.wpadminbar',
			'[data-quarantined-keep]',
		];

		$selectors = apply_filters( 'quarantined_cpt_bodyclean/allowed_dom_selectors', $defaults );

		if ( ! is_array( $selectors ) ) {
			return $defaults;
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $selector ) {
						return is_string( $selector ) ? trim( $selector ) : '';
					},
					$selectors
				),
				static function ( $selector ) {
					return '' !== $selector;
				}
			)
		);
	}

	/**
	 * Returns selectors that are safe to prune if they do not contain quarantined content.
	 *
	 * @return string[]
	 */
	private function get_prune_selectors(): array {
		$defaults = [
			'main',
			'.site-main',
			'.site-content',
			'.content-area',
			'#primary',
			'article',
			'.hentry',
			'.entry-content',
			'.entry-header',
			'.entry-title',
			'.page-title',
			'.page-header',
			'.breadcrumbs',
			'.breadcrumb',
			'.rank-math-breadcrumb',
			'.yoast-breadcrumbs',
			'.elementor',
			'.elementor-section',
			'.elementor-container',
			'.elementor-widget',
			'.page-content',
			'.ast-article-single',
			'.ast-breadcrumbs',
			'.woocommerce-breadcrumb',
			'.wp-block-post-content',
			'.wp-block-group',
			'.wp-block-template-part',
			'.fl-page-content',
			'.fl-page-header',
			'.fl-breadcrumbs',
			'.generate-section',
			'.generate-main-page',
			'.generate-content-container',
			'.genesis-title',
			'.genesis-breadcrumbs',
			'.et-l--before-content',
			'.et-l--content',
			'.et_builder_inner_content',
			'.fusion-page-title-bar',
			'.fusion-breadcrumbs',
			'.kadence-breadcrumb-container',
			'.kadence-sticky-header',
			'.oceanwp-breadcrumbs',
			'.oceanwp-mobile-menu-icon',
			'.wp-block-query',
			'.wp-block-post-template',
		];

		$selectors = apply_filters( 'quarantined_cpt_bodyclean/prune_selectors', $defaults );

		if ( ! is_array( $selectors ) ) {
			return $defaults;
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $selector ) {
						return is_string( $selector ) ? trim( $selector ) : '';
					},
					$selectors
				),
				static function ( $selector ) {
					return '' !== $selector;
				}
			)
		);
	}

	/**
	 * Returns selectors that mark a boundary where pruning should stop.
	 *
	 * @return string[]
	 */
	private function get_boundary_selectors(): array {
		$defaults = [
			'body',
			'.wp-site-blocks',
			'#wp-site-blocks',
			'#page',
			'.site',
			'.site-container',
			'.elementor-location-header',
			'.elementor-location-top-bar',
			'[data-quarantined-boundary]',
		];

		$selectors = apply_filters( 'quarantined_cpt_bodyclean/boundary_selectors', $defaults );

		if ( ! is_array( $selectors ) ) {
			return $defaults;
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $selector ) {
						return is_string( $selector ) ? trim( $selector ) : '';
					},
					$selectors
				),
				static function ( $selector ) {
					return '' !== $selector;
				}
			)
		);
	}

	/**
	 * Registers image sizes used within the quarantined templates.
	 */
	public function register_image_sizes(): void {
		add_image_size( 'quarantined-cpt-hero', 1920, 360, true );
		add_image_size( 'quarantined-cpt-card', 800, 450, true );
	}

	/**
	 * Ensures quarantined posts appear within author archives.
	 *
	 * @param \WP_Query $query Current query instance.
	 */
	public function include_in_author_archives( \WP_Query $query ): void {
		self::log( 'include_in_author_archives running' );
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( ! self::author_archive_enabled() ) {
			return;
		}

		$has_author_context = $query->is_author() || '' !== (string) $query->get( 'quarantined_cpt_author', '' );

		if ( ! $has_author_context ) {
			return;
		}

		$custom_slug = (string) $query->get( 'quarantined_cpt_author', '' );

		if ( '' === $custom_slug ) {
			$custom_slug = (string) $query->get( 'author_name', '' );
		}

		if ( '' !== $custom_slug ) {
			$user = $this->find_author_by_slug( $custom_slug );

			if ( $user instanceof \WP_User ) {
				$query->set( 'author', $user->ID );
				$query->set( 'author_name', $user->user_nicename );
				$query->set( 'quarantined_cpt_author', $this->get_author_slug_for_user( $user ) );
				$query->is_author = true;
			}
		}

		$managed_types = $this->get_cpt_types();

		if ( empty( $managed_types ) ) {
			return;
		}

		$post_types = $query->get( 'post_type' );

		if ( empty( $post_types ) || 'post' === $post_types || 'any' === $post_types ) {
			$post_types = array_merge( [ 'post' ], $managed_types );
		} elseif ( is_string( $post_types ) ) {
			$post_types = array_merge( [ $post_types ], $managed_types );
		} elseif ( is_array( $post_types ) ) {
			$post_types = array_merge( $post_types, $managed_types );
		} else {
			$post_types = array_merge( [ 'post' ], $managed_types );
		}

		$post_types = array_values(
			array_unique(
				array_filter(
					$post_types,
					static function ( $type ) {
						return is_string( $type ) && '' !== $type;
					}
				)
			)
		);

		$query->set( 'post_type', $post_types );
	}

	/**
	 * Flushes rewrite rules if the quarantined CPT rules are missing.
	 */
	public function maybe_flush_rewrite_rules(): void {
		self::log( 'maybe_flush_rewrite_rules check' );
		$needs_flush      = false;
		$stored_signature = get_option( self::OPTION_REWRITE_SIGNATURE );
		$current_signature = $this->compute_rewrite_signature();

		if ( ! get_option( self::REWRITE_READY_OPTION ) && ! $this->rewrite_rules_are_ready() ) {
			$needs_flush = true;
		}

		if ( $stored_signature !== $current_signature ) {
			$needs_flush = true;
		}

		if ( $needs_flush ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_READY_OPTION, 1 );
			update_option( self::OPTION_REWRITE_SIGNATURE, $current_signature );
		}
	}

	/**
	 * Determines whether the rewrite rules include entries for the quarantined CPT.
	 *
	 * @return bool
	 */
	private function rewrite_rules_are_ready(): bool {
		$rules = get_option( 'rewrite_rules' );

		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return false;
		}

		$post_types = $this->get_cpt_types();

		if ( ! empty( $post_types ) ) {
			$found = array_fill_keys( $post_types, false );

			foreach ( $rules as $query ) {
				foreach ( $post_types as $type ) {
					if ( false !== strpos( (string) $query, $type ) ) {
						$found[ $type ] = true;
					}
				}
			}

			if ( in_array( false, $found, true ) ) {
				return false;
			}
		}

		if ( self::author_archive_enabled() ) {
			foreach ( $rules as $query ) {
				if ( false !== strpos( (string) $query, 'quarantined_cpt_author' ) ) {
					return true;
				}
			}

			return false;
		}

		return true;
	}

	private function compute_rewrite_signature(): string {
		$definitions = $this->get_cpt_definitions( false );
		$parts       = [];

		foreach ( $definitions as $definition ) {
			$type      = isset( $definition['type'] ) ? (string) $definition['type'] : '';
			$slug      = isset( $definition['slug'] ) ? (string) $definition['slug'] : '';
			$slug      = apply_filters( 'quarantined_cpt/base_slug', $slug, $type, $definition, $this );
			$slug      = sanitize_title_with_dashes( (string) $slug );
			$parts[] = implode(
				':',
				[
					$type,
					$slug,
					$slug,
				]
			);
		}

		$parts[] = $this->cpt_registration_enabled() ? 'cpts-on' : 'cpts-off';
		$parts[] = $this->get_author_base();
		$parts[] = self::author_archive_enabled() ? 'authors-on' : 'authors-off';

		return md5( implode( '|', $parts ) );
	}

	/**
	 * Forces quarantined CPT routing when a conflicting page slug is present.
	 *
	 * @param array $query_vars Parsed query variables.
	 * @return array
	 */
	public function maybe_force_cpt_routing( array $query_vars ): array {
		self::log( 'maybe_force_cpt_routing invoked' );
		$definitions = $this->get_cpt_definitions();
		$post_types  = $this->get_cpt_types();

		if ( isset( $query_vars['post_type'] ) ) {
			$post_type = $query_vars['post_type'];

			if ( ( is_string( $post_type ) && in_array( $post_type, $post_types, true ) )
				|| ( is_array( $post_type ) && array_intersect( $post_types, $post_type ) )
			) {
				return $query_vars;
			}
		}

		if ( isset( $query_vars['quarantined_cpt_authors'] ) || isset( $query_vars['quarantined_cpt_author'] ) ) {
			return $query_vars;
		}

		if ( ! isset( $query_vars['pagename'] ) ) {
			return $query_vars;
		}

		$author_base = self::author_archive_enabled() ? $this->get_author_base() : '';
		$path        = trim( (string) $query_vars['pagename'], '/' );
		$segments = explode( '/', $path );

		if ( '' !== $author_base && isset( $segments[0] ) && $segments[0] === $author_base ) {
			$segment_count = count( $segments );

			if ( 1 === $segment_count ) {
				unset( $query_vars['pagename'] );
				$query_vars['quarantined_cpt_authors'] = 1;

				return $query_vars;
			}

			$second = (string) $segments[1];

			if ( 'page' === $second && $segment_count >= 3 ) {
				unset( $query_vars['pagename'] );
				$query_vars['quarantined_cpt_authors'] = 1;
				$query_vars['paged'] = (int) $segments[2];

				return $query_vars;
			}

			if ( 'feed' === $second ) {
				unset( $query_vars['pagename'] );
				$query_vars['quarantined_cpt_authors'] = 1;
				$query_vars['feed'] = $segment_count >= 3 ? (string) $segments[2] : 'feed';

				return $query_vars;
			}

			if ( in_array( $second, [ 'rdf', 'rss', 'rss2', 'atom' ], true ) ) {
				unset( $query_vars['pagename'] );
				$query_vars['quarantined_cpt_authors'] = 1;
				$query_vars['feed'] = $second;

				return $query_vars;
			}

			$slug = $this->sanitize_author_slug( $second );

			if ( '' === $slug ) {
				return $query_vars;
			}

			$user = $this->find_author_by_slug( $slug );

			unset( $query_vars['pagename'] );
			$query_vars['quarantined_cpt_author'] = $user instanceof \WP_User ? $this->get_author_slug_for_user( $user ) : $slug;

			if ( $user instanceof \WP_User ) {
				$query_vars['author']      = $user->ID;
				$query_vars['author_name'] = $user->user_nicename;
			} else {
				$query_vars['author_name'] = $slug;
			}

			if ( $segment_count >= 3 ) {
				$third = (string) $segments[2];

				if ( 'page' === $third && $segment_count >= 4 ) {
					$query_vars['paged'] = (int) $segments[3];
				} elseif ( 'feed' === $third ) {
					$query_vars['feed'] = $segment_count >= 4 ? (string) $segments[3] : 'feed';
				} elseif ( in_array( $third, [ 'rdf', 'rss', 'rss2', 'atom' ], true ) ) {
					$query_vars['feed'] = $third;
				}
			}

			return $query_vars;
		}

		if ( empty( $definitions ) || empty( $segments ) ) {
			return $query_vars;
		}

		foreach ( $definitions as $definition ) {
			$base = isset( $definition['slug'] ) ? trim( (string) $definition['slug'], '/' ) : '';

			if ( '' === $base || $segments[0] !== $base ) {
				continue;
			}

			$segment_count = count( $segments );
			$post_type     = isset( $definition['type'] ) ? (string) $definition['type'] : '';

			if ( '' === $post_type ) {
				continue;
			}

			if ( 1 === $segment_count ) {
				unset( $query_vars['pagename'] );
				$query_vars['post_type'] = $post_type;

				return $query_vars;
			}

			$second = (string) $segments[1];

			if ( 'page' === $second && $segment_count >= 3 ) {
				unset( $query_vars['pagename'] );
				$query_vars['post_type'] = $post_type;
				$query_vars['paged']     = (int) $segments[2];

				return $query_vars;
			}

			if ( 'feed' === $second || 0 === strpos( $second, 'feed' ) ) {
				unset( $query_vars['pagename'] );
				$query_vars['post_type'] = $post_type;
				$query_vars['feed']      = $segment_count >= 3 ? $segments[2] : 'feed';

				return $query_vars;
			}

			$slug = sanitize_title( $second );

			if ( '' === $slug ) {
				continue;
			}

			unset( $query_vars['pagename'] );
			$query_vars['post_type'] = $post_type;
			$query_vars['name']      = $slug;

			return $query_vars;
		}

		return $query_vars;
	}

	/**
	 * Returns sanitized, theme-independent HTML for the quarantined post content.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	public static function content( $post = null ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_content( $post );
	}

	/**
	 * Returns a sanitized excerpt for the quarantined post.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	public static function excerpt( $post = null ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_excerpt( $post );
	}

	/**
	 * Returns isolated featured image markup for the quarantined post.
	 *
	 * @param string|int[]      $size Optional image size.
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	public static function thumbnail( $size = 'large', $post = null ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_thumbnail( $size, $post );
	}

	/**
	 * Returns a shortened summary limited to a given character length.
	 *
	 * @param int                $length Optional maximum length.
	 * @param int|\WP_Post|null  $post   Optional post object or ID.
	 * @return string
	 */
	public static function summary( int $length = 30, $post = null ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_summary( $length, $post );
	}

	/**
	 * Returns sanitized breadcrumb markup for the quarantined post.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	public static function breadcrumbs( $post = null ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_breadcrumbs( $post );
	}

	/**
	 * Provides a structural placeholder when assets like images are missing.
	 *
	 * @param string $context Display context, e.g. 'card'.
	 * @return string
	 */
	public static function placeholder( string $context = 'general' ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->prepare_placeholder( $context );
	}

	/**
	 * Builds sanitized post content without running theme/plugin filters.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	private function prepare_content( $post = null ): string {
		$post = get_post( $post );

		if ( ! $post || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return '';
		}

		$raw = (string) get_post_field( 'post_content', $post, 'raw' );

		if ( function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			$processed = do_blocks( $raw );
		} else {
			$processed = wpautop( $raw );
		}

		$processed = do_shortcode( $processed );

		$processed = apply_filters( 'quarantined_cpt/raw_content', $processed, $post );

		$allowed_html = apply_filters(
			'quarantined_cpt/allowed_html',
			wp_kses_allowed_html( 'post' ),
			$post
		);

		$sanitized = wp_kses( $processed, $allowed_html );

		return shortcode_unautop( $sanitized );
	}

	/**
	 * Builds sanitized excerpt text independent of theme filters.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	private function prepare_excerpt( $post = null ): string {
		$post = get_post( $post );

		if ( ! $post || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return '';
		}

		$raw_excerpt = has_excerpt( $post )
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $post ) ), 40 );

		$filtered_excerpt = apply_filters( 'quarantined_cpt/raw_excerpt', $raw_excerpt, $post );

		return esc_html( $filtered_excerpt );
	}

	/**
	 * Builds featured image markup without theme filters.
	 *
	 * @param string|int[]      $size Optional image size.
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	private function prepare_thumbnail( $size, $post = null ): string {
		$post = get_post( $post );

		if ( ! $post || ! $this->is_managed_post_type( (string) $post->post_type ) || ! has_post_thumbnail( $post ) ) {
			return '';
		}

		$image_id = (int) get_post_thumbnail_id( $post );

		$html = wp_get_attachment_image(
			$image_id,
			$size,
			false,
			[ 'class' => 'quarantined-cpt__thumbnail' ]
		);

		return apply_filters( 'quarantined_cpt/thumbnail_html', $html, $image_id, $post, $size );
	}

	/**
	 * Builds a truncated summary string for listings.
	 *
	 * @param int                $length Desired length.
	 * @param int|\WP_Post|null  $post   Post context.
	 * @return string
	 */
	private function prepare_summary( int $length, $post = null ): string {
		$post = get_post( $post );

		if ( ! $post || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return '';
		}

		$length = max( 1, $length );

		$source = has_excerpt( $post )
			? $post->post_excerpt
			: get_post_field( 'post_content', $post );

		$source = wp_strip_all_tags( (string) $source );

		$excerpt = wp_html_excerpt( $source, $length, '…' );

		$excerpt = apply_filters( 'quarantined_cpt/summary_text', $excerpt, $post, $length );

		return $excerpt;
	}

	/**
	 * Builds breadcrumb markup either from filters or the plugin fallback.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return string
	 */
	private function prepare_breadcrumbs( $post = null ): string {
		$post = get_post( $post );

		if ( ! $post || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return '';
		}

		$filtered = apply_filters( 'quarantined_cpt/breadcrumbs_html', '', $post );

		if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
			return $this->sanitize_breadcrumbs_html( $filtered );
		}

		return $this->default_breadcrumbs( $post );
	}

	/**
	 * Provides a minimal fallback breadcrumb trail.
	 *
	 * @param \WP_Post $post Quarantined post instance.
	 * @return string
	 */
	private function default_breadcrumbs( \WP_Post $post ): string {
		$crumbs = [];

		$crumbs[] = [
			'label' => __( 'Home', 'nova-bridge-suite' ),
			'url'   => home_url( '/' ),
		];

		$post_type = get_post_type_object( $post->post_type );

		if ( $post_type && ! empty( $post_type->labels->name ) ) {
			$link = get_post_type_archive_link( $post->post_type );

			$crumbs[] = [
				'label' => $post_type->labels->name,
				'url'   => $link ?: '',
			];
		}

		$crumbs[] = [
			'label'   => get_the_title( $post ),
			'url'     => '',
			'current' => true,
		];

		return $this->build_breadcrumb_markup( $crumbs );
	}

	/**
	 * Runs a restricted sanitization pass over breadcrumb markup.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	private function sanitize_breadcrumbs_html( string $html ): string {
		$allowed = [
			'nav' => [
				'class'      => [],
				'aria-label' => [],
			],
			'ol'  => [
				'class' => [],
			],
			'ul'  => [
				'class' => [],
			],
			'li'  => [
				'class'        => [],
				'aria-current' => [],
			],
			'a'   => [
				'href'   => [],
				'class'  => [],
				'target' => [],
				'rel'    => [],
				'title'  => [],
			],
			'span' => [
				'class' => [],
			],
		];

		$allowed = apply_filters( 'quarantined_cpt/allowed_breadcrumb_tags', $allowed );

		return wp_kses( $html, $allowed );
	}

	/**
	 * Builds markup for breadcrumbs using the configured separator.
	 *
	 * @param array $crumbs Breadcrumb items.
	 * @return string
	 */
	private function build_breadcrumb_markup( array $crumbs ): string {
		if ( empty( $crumbs ) ) {
			return '';
		}

		$items     = [];
		$count     = count( $crumbs );
		$separator = $this->resolve_breadcrumb_separator();

		foreach ( $crumbs as $index => $crumb ) {
			$label   = isset( $crumb['label'] ) ? (string) $crumb['label'] : '';
			$url     = isset( $crumb['url'] ) ? (string) $crumb['url'] : '';
			$current = ! empty( $crumb['current'] );

			if ( '' === $label ) {
				continue;
			}

			if ( $url && ! $current ) {
				$items[] = sprintf(
					'<li class="quarantined-cpt__crumb"><a href="%1$s">%2$s</a></li>',
					esc_url( $url ),
					esc_html( $label )
				);
			} else {
				$items[] = sprintf(
					'<li class="quarantined-cpt__crumb"%2$s><span>%1$s</span></li>',
					esc_html( $label ),
					$current ? ' aria-current="page"' : ''
				);
			}

			if ( $index < ( $count - 1 ) ) {
				$items[] = sprintf(
					'<li class="quarantined-cpt__crumb-separator" aria-hidden="true"><span>%1$s</span></li>',
					esc_html( $separator )
				);
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		$html = sprintf(
			'<nav class="quarantined-cpt__breadcrumbs" aria-label="%1$s"><ol class="quarantined-cpt__crumbs">%2$s</ol></nav>',
			esc_attr__( 'Breadcrumbs', 'nova-bridge-suite' ),
			implode( '', $items )
		);

		return $this->sanitize_breadcrumbs_html( $html );
	}

	/**
	 * Public helper to render breadcrumb markup from templates.
	 *
	 * @param array $crumbs Breadcrumb definition.
	 * @return string
	 */
	public static function render_breadcrumbs( array $crumbs ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->build_breadcrumb_markup( $crumbs );
	}

	/**
	 * Returns placeholder markup for contexts where assets are absent.
	 *
	 * @param string $context Display context.
	 * @return string
	 */
	private function prepare_placeholder( string $context ): string {
		$context_class = 'quarantined-cpt__placeholder--' . sanitize_html_class( $context );

		$html = sprintf(
			'<div class="quarantined-cpt__placeholder %1$s" aria-hidden="true"></div>',
			esc_attr( $context_class )
		);

		return apply_filters( 'quarantined_cpt/placeholder_html', $html, $context );
	}

	/**
	 * Registers the Body Clean settings page in the CPT menu.
	 */
	public function register_settings_page(): void {
		$definition  = $this->get_primary_cpt_definition();
		$settings_parent = 'options-general.php';

		// Always expose the settings under Settings > NOVA Blog Settings so it remains reachable when CPTs are disabled.
		add_submenu_page(
			$settings_parent,
			__( 'NOVA Blog Settings', 'nova-bridge-suite' ),
			__( 'NOVA Blog Settings', 'nova-bridge-suite' ),
			'manage_options',
			'quarantined-cpt-bodyclean',
			[ $this, 'render_settings_page' ]
		);

		// Also surface inside the CPT menu when a CPT is active.
		if ( $this->cpt_registration_enabled() && $definition && ! empty( $definition['type'] ) ) {
			$parent_slug = 'edit.php?post_type=' . sanitize_key( (string) $definition['type'] );

			add_submenu_page(
				$parent_slug,
				__( 'NOVA Blog Settings', 'nova-bridge-suite' ),
				__( 'NOVA Blog Settings', 'nova-bridge-suite' ),
				'manage_options',
				'quarantined-cpt-bodyclean',
				[ $this, 'render_settings_page' ]
			);
		}
	}

	/**
	 * Registers plugin settings used for body clean controls.
	 */
	public function register_settings(): void {
		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_ENABLE_CPTS,
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
				'default'           => true,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_CPTS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_cpt_definitions_option' ],
				'default'           => [],
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_EXCLUDE_SELECTORS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_selector_input' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_COMPONENT_VISIBILITY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_component_settings' ],
				'default'           => $this->get_component_defaults(),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_AUTHOR_ARCHIVE,
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
				'default'           => false,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_CPT_SINGULAR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Quarantined Page', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_CPT_PLURAL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Quarantined Pages', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_CPT_SLUG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_slug_option' ],
				'default'           => self::BASE_SLUG,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_AUTHOR_BASE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_author_base_option' ],
				'default'           => 'authors',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_AUTHOR_ARCHIVE_TITLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Authors', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BREADCRUMB_SEPARATOR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_breadcrumb_separator_option' ],
				'default'           => 'chevron',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_HEADER_OFFSET,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_header_offset_option' ],
				'default'           => self::DEFAULT_HEADER_OFFSET,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_AUTHOR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Door', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_PUBLICATIONS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				/* translators: %s: number of publications. */
				'default'           => __( '%s publications', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BODYCLEAN_ENABLED,
			[
				'type'              => 'boolean',
				'sanitize_callback' => static function ( $value ) {
					return (bool) $value;
				},
				'default'           => false,
			]
		);
	}

	/**
	 * Enqueues admin assets used for the custom author fields.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'profile.php' !== $hook && 'user-edit.php' !== $hook ) {
			return;
		}

		wp_enqueue_media();

		$script_path = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-admin.js';
		$script_url  = plugins_url( 'assets/quarantined-cpt-admin.js', __FILE__ );
		$version     = file_exists( $script_path ) ? (string) filemtime( $script_path ) : null;

		wp_enqueue_script(
			'quarantined-cpt-admin',
			$script_url,
			[ 'jquery' ],
			$version,
			true
		);

		wp_localize_script(
			'quarantined-cpt-admin',
			'quarantinedCptAdmin',
			[
				'placeholder' => $this->get_placeholder_avatar_url(),
				'l10n'        => [
					'select' => __( 'Select author image', 'nova-bridge-suite' ),
					'use'    => __( 'Use this image', 'nova-bridge-suite' ),
					'remove' => __( 'Remove image', 'nova-bridge-suite' ),
				],
			]
		);
	}

	/**
	 * Ensures the custom menu icon keeps its original colors.
	 */
	public function enqueue_menu_icon_styles(): void {
		$types = $this->get_cpt_types();

		if ( empty( $types ) ) {
			return;
		}

		$selectors = [];

		foreach ( $types as $type ) {
			$slug        = sanitize_html_class( $type );
			$selectors[] = '#adminmenu .menu-icon-' . $slug . ' div.wp-menu-image img';
			$selectors[] = '#adminmenu .menu-icon-' . $slug . '.current div.wp-menu-image img';
			$selectors[] = '#adminmenu .menu-icon-' . $slug . ':hover div.wp-menu-image img';
			$selectors[] = '#adminmenu .menu-icon-' . $slug . '.wp-has-current-submenu div.wp-menu-image img';
		}

		if ( empty( $selectors ) ) {
			return;
		}

		$handle = 'quarantined-cpt-admin-menu';
		$css = implode( ',', $selectors ) . '{filter:none!important;opacity:1!important;}';

		wp_register_style( $handle, false, [], null );
		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, $css );
	}

	/**
	 * Outputs the settings page content.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$raw = get_option( self::OPTION_EXCLUDE_SELECTORS, '' );
		$value = is_array( $raw ) ? implode( "\n", $raw ) : (string) $raw;
		$value = trim( $value );

		$components          = $this->get_component_settings();
		$author_pages        = $this->author_archive_enabled();
		$enable_cpts         = $this->cpt_registration_enabled();
		$cpt_definitions     = $this->get_cpt_definitions( false );
		$cpt_definitions[]   = [
			'slug'      => '',
			'singular'  => '',
			'plural'    => '',
			'schema_type' => self::DEFAULT_ARTICLE_SCHEMA_TYPE,
		];
		$author_base         = $this->get_author_base();
		$author_archive_name = $this->get_author_archive_title();
		$separator_choice    = $this->get_breadcrumb_separator_choice();
		$separator_options   = $this->get_breadcrumb_separator_options();
		$label_author        = $this->get_author_label();
		$label_publications  = $this->get_publications_label();
		$header_offset       = $this->get_header_offset_setting();
		$schema_choices      = $this->get_article_schema_choices();

		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'NOVA Blog Settings', 'nova-bridge-suite' ); ?></h1>
		<p><?php esc_html_e( 'Configure the NOVA Blog CPT layouts, author archives, and isolation rules for this site.', 'nova-bridge-suite' ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'quarantined_cpt_bodyclean' );
				?>
				<h2><?php esc_html_e( 'Author Archive', 'nova-bridge-suite' ); ?></h2>
				<p><?php esc_html_e( 'Enable the plugin-provided author archive template if the theme does not supply one.', 'nova-bridge-suite' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">&nbsp;</th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_AUTHOR_ARCHIVE ); ?>" value="1" <?php checked( $author_pages ); ?> />
								<?php esc_html_e( 'Enable plugin author archive', 'nova-bridge-suite' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, author pages will use the plugin template and list all posts by that author, including the CPT.', 'nova-bridge-suite' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Custom Post Types', 'nova-bridge-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Register CPTs', 'nova-bridge-suite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLE_CPTS ); ?>" value="1" <?php checked( $enable_cpts ); ?> />
								<?php esc_html_e( 'Enable CPT registration', 'nova-bridge-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Uncheck to skip registering any CPTs while still using the author templates and isolation tooling.', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post type definitions', 'nova-bridge-suite' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Add one row per CPT. Leave a row blank to remove it. The CPT slug is also used as the post type and REST API base.', 'nova-bridge-suite' ); ?></p>
							<table class="widefat striped" style="max-width: 100%; margin-top: 0.5rem;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'CPT slug', 'nova-bridge-suite' ); ?></th>
										<th><?php esc_html_e( 'Singular label', 'nova-bridge-suite' ); ?></th>
										<th><?php esc_html_e( 'Plural label', 'nova-bridge-suite' ); ?></th>
										<th><?php esc_html_e( 'Schema type', 'nova-bridge-suite' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $cpt_definitions as $index => $definition ) : ?>
										<tr>
											<td>
												<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][slug]" value="<?php echo esc_attr( $definition['slug'] ?? '' ); ?>" placeholder="quarantined" />
											</td>
											<td>
												<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][singular]" value="<?php echo esc_attr( $definition['singular'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Quarantined Page', 'nova-bridge-suite' ); ?>" />
											</td>
											<td>
												<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][plural]" value="<?php echo esc_attr( $definition['plural'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Quarantined Pages', 'nova-bridge-suite' ); ?>" />
											</td>
											<td>
												<select name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][schema_type]">
													<?php foreach ( $schema_choices as $slug => $label ) : ?>
														<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $definition['schema_type'] ?? '', $slug ); ?>>
															<?php echo esc_html( $label ); ?>
														</option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-author-base"><?php esc_html_e( 'Author Base Slug', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-author-base" name="<?php echo esc_attr( self::OPTION_AUTHOR_BASE ); ?>" value="<?php echo esc_attr( $author_base ); ?>" />
							<p class="description"><?php esc_html_e( 'Used for author URLs (e.g. /authors/username/). Applies when the plugin author archive is enabled.', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-author-archive-title"><?php esc_html_e( 'Author Archive Title', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-author-archive-title" name="<?php echo esc_attr( self::OPTION_AUTHOR_ARCHIVE_TITLE ); ?>" value="<?php echo esc_attr( $author_archive_name ); ?>" />
							<p class="description"><?php esc_html_e( 'Sets the heading and breadcrumb label for the author archive page.', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Element Exclusions', 'nova-bridge-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="quarantined-cpt-bodyclean-selectors"><?php esc_html_e( 'Selectors to hide', 'nova-bridge-suite' ); ?></label>
						</th>
						<td>
							<textarea
								name="<?php echo esc_attr( self::OPTION_EXCLUDE_SELECTORS ); ?>"
								id="quarantined-cpt-bodyclean-selectors"
								rows="10"
								cols="70"
								class="large-text code"
								placeholder=".site-breadcrumbs&#10;.banner-wrapper"
							><?php echo esc_textarea( $value ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One selector per line. Rules are only applied on CPT views and are appended to the default exclusions.', 'nova-bridge-suite' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Plugin Components', 'nova-bridge-suite' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY ); ?>[title]" value="1" <?php checked( ! empty( $components['title'] ) ); ?> />
									<?php esc_html_e( 'Show title (H1)', 'nova-bridge-suite' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY ); ?>[breadcrumbs]" value="1" <?php checked( ! empty( $components['breadcrumbs'] ) ); ?> />
									<?php esc_html_e( 'Show breadcrumbs', 'nova-bridge-suite' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY ); ?>[date]" value="1" <?php checked( ! empty( $components['date'] ) ); ?> />
									<?php esc_html_e( 'Show publication date', 'nova-bridge-suite' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY ); ?>[author]" value="1" <?php checked( ! empty( $components['author'] ) ); ?> />
									<?php esc_html_e( 'Show author', 'nova-bridge-suite' ); ?>
								</label>
								<br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY ); ?>[featured]" value="1" <?php checked( ! empty( $components['featured'] ) ); ?> />
									<?php esc_html_e( 'Show featured image', 'nova-bridge-suite' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Language Overrides', 'nova-bridge-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-label-author"><?php esc_html_e( 'Author label', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-author" name="<?php echo esc_attr( self::OPTION_LABEL_AUTHOR ); ?>" value="<?php echo esc_attr( $label_author ); ?>" />
							<p class="description"><?php esc_html_e( 'Text shown before the author name on single CPT pages.', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-label-publications"><?php esc_html_e( 'Publications label', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-publications" name="<?php echo esc_attr( self::OPTION_LABEL_PUBLICATIONS ); ?>" value="<?php echo esc_attr( $label_publications ); ?>" />
							<p class="description">
								<?php
								/* translators: 1: publication count placeholder, 2: example localized string. */
								esc_html_e( 'Use %1$s where the publication count should appear (for example: "%2$s Veröffentlichungen").', 'nova-bridge-suite' );
								?>
							</p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Layout & Spacing', 'nova-bridge-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-header-offset"><?php esc_html_e( 'Top content offset', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-header-offset" name="<?php echo esc_attr( self::OPTION_HEADER_OFFSET ); ?>" value="<?php echo esc_attr( $header_offset ); ?>" />
							<p class="description"><?php esc_html_e( 'Controls the whitespace above the CPT content. Accepts CSS units such as 6rem, 120px, 10vh, or calc(4rem + 20px).', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Breadcrumbs', 'nova-bridge-suite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="quarantined-cpt-bodyclean-separator"><?php esc_html_e( 'Breadcrumb separator', 'nova-bridge-suite' ); ?></label></th>
						<td>
							<select id="quarantined-cpt-bodyclean-separator" name="<?php echo esc_attr( self::OPTION_BREADCRUMB_SEPARATOR ); ?>">
								<?php foreach ( $separator_options as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $separator_choice, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Choose how breadcrumb items are separated.', 'nova-bridge-suite' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Outputs custom author controls on the user profile screen.
	 *
	 * @param \WP_User $user User object.
	 */
	public function render_user_fields( $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClass
		if ( ! ( $user instanceof \WP_User ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$display = get_user_meta( $user->ID, self::META_AUTHOR_DISPLAY, true );
		$display = is_string( $display ) ? $display : '';

		$custom_slug = get_user_meta( $user->ID, self::META_AUTHOR_SLUG, true );
		$custom_slug = is_string( $custom_slug ) ? $custom_slug : '';

		$current_slug = $this->get_author_slug_for_user( $user );
		$author_base  = $this->get_author_base();
		$example_url  = trailingslashit( home_url( '/' . $author_base . '/' . $current_slug ) );

		$job_title = get_user_meta( $user->ID, self::META_AUTHOR_TITLE, true );
		$job_title = is_string( $job_title ) ? $job_title : '';

		$organization = get_user_meta( $user->ID, self::META_AUTHOR_ORG, true );
		$organization = is_string( $organization ) ? $organization : '';

		$organization_url = get_user_meta( $user->ID, self::META_AUTHOR_ORG_URL, true );
		$organization_url = is_string( $organization_url ) ? $organization_url : '';

		$location = get_user_meta( $user->ID, self::META_AUTHOR_LOCATION, true );
		$location = is_string( $location ) ? $location : '';

		$website = get_user_meta( $user->ID, self::META_AUTHOR_WEBSITE, true );
		$website = is_string( $website ) ? $website : '';

		$social_values = get_user_meta( $user->ID, self::META_AUTHOR_SOCIAL, true );
		$social_values = is_array( $social_values ) ? array_map( 'strval', $social_values ) : [];

		$social_fields = $this->get_author_social_field_map();

		$avatar_id   = absint( get_user_meta( $user->ID, self::META_AUTHOR_AVATAR, true ) );
		$avatar_url  = $avatar_id ? wp_get_attachment_image_url( $avatar_id, 'medium' ) : '';
		$avatar_url  = $avatar_url ? $avatar_url : get_avatar_url( $user->ID, [ 'size' => 192 ] );
		$placeholder = $this->get_placeholder_avatar_url();
		$avatar_url  = $avatar_url ? $avatar_url : $placeholder;

		wp_nonce_field( 'quarantined_cpt_user_fields', 'quarantined_cpt_user_fields_nonce' );
		?>
		<h2><?php esc_html_e( 'NOVA Blog Author Settings', 'nova-bridge-suite' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-display"><?php esc_html_e( 'Author Display Name', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="quarantined-cpt-author-display" name="quarantined_cpt_display_name" value="<?php echo esc_attr( $display ); ?>" />
					<p class="description"><?php esc_html_e( 'Overrides the author name shown on NOVA Blog CPT templates. Leave empty to use the default profile display name.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Author Avatar', 'nova-bridge-suite' ); ?></th>
				<td>
					<div
						class="quarantined-cpt-avatar-control<?php echo $avatar_id ? ' has-image' : ''; ?>"
						data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
					>
						<div class="quarantined-cpt-avatar-preview" style="width:96px;height:96px;border-radius:50%;overflow:hidden;border:1px solid #dcdcde;margin-bottom:12px;">
							<img
								src="<?php echo esc_url( $avatar_url ); ?>"
								alt="<?php echo esc_attr( $display ? $display : $user->display_name ); ?>"
								style="width:100%;height:100%;object-fit:cover;"
								width="96"
								height="96"
							/>
						</div>
						<input type="hidden" id="quarantined-cpt-author-avatar-id" name="quarantined_cpt_author_avatar_id" value="<?php echo esc_attr( $avatar_id ); ?>" />
						<button type="button" class="button quarantined-cpt-avatar-select"><?php esc_html_e( 'Select image', 'nova-bridge-suite' ); ?></button>
						<button type="button" class="button-link quarantined-cpt-avatar-remove"<?php echo $avatar_id ? '' : ' style="display:none;"'; ?>>
							<?php esc_html_e( 'Remove image', 'nova-bridge-suite' ); ?>
						</button>
					</div>
					<p class="description"><?php esc_html_e( 'Displayed on CPT author pages and included in schema markup. Falls back to the default WordPress avatar when empty.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-slug"><?php esc_html_e( 'Author URL Slug', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="quarantined-cpt-author-slug" name="quarantined_cpt_author_slug" value="<?php echo esc_attr( $custom_slug ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %s - example author URL */
							esc_html__( 'Controls the author permalink under the NOVA Blog CPT archive. Current URL: %s', 'nova-bridge-suite' ),
							'<code>' . esc_html( $example_url ) . '</code>'
						);
						?>
						<br />
						<?php esc_html_e( 'Leave empty to match the default WordPress author slug.', 'nova-bridge-suite' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<h3><?php esc_html_e( 'Author Schema Details', 'nova-bridge-suite' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-title"><?php esc_html_e( 'Job Title', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="quarantined-cpt-author-title" name="quarantined_cpt_author_title" value="<?php echo esc_attr( $job_title ); ?>" />
					<p class="description"><?php esc_html_e( 'Shown on author pages and used in Person schema markup.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-org"><?php esc_html_e( 'Organisation', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="quarantined-cpt-author-org" name="quarantined_cpt_author_org" value="<?php echo esc_attr( $organization ); ?>" />
					<p class="description"><?php esc_html_e( 'Company or brand the author represents.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-org-url"><?php esc_html_e( 'Organisation URL', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="quarantined-cpt-author-org-url" name="quarantined_cpt_author_org_url" value="<?php echo esc_attr( $organization_url ); ?>" placeholder="https://example.com" />
					<p class="description"><?php esc_html_e( 'Optional link to the organisation website.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-location"><?php esc_html_e( 'Location', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="quarantined-cpt-author-location" name="quarantined_cpt_author_location" value="<?php echo esc_attr( $location ); ?>" />
					<p class="description"><?php esc_html_e( 'City or region used for Person schema (optional).', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="quarantined-cpt-author-website"><?php esc_html_e( 'Author Website', 'nova-bridge-suite' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="quarantined-cpt-author-website" name="quarantined_cpt_author_website" value="<?php echo esc_attr( $website ); ?>" placeholder="https://author-site.com" />
					<p class="description"><?php esc_html_e( 'Canonical site for the author. Included in schema and can be shown publicly.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Social Profiles', 'nova-bridge-suite' ); ?></th>
				<td>
					<div class="quarantined-cpt-author-social-fields">
						<?php foreach ( $social_fields as $key => $field ) : ?>
							<p>
								<label for="quarantined-cpt-author-social-<?php echo esc_attr( $key ); ?>">
									<?php echo esc_html( $field['label'] ); ?>
								</label><br />
								<input
									type="url"
									class="regular-text"
									id="quarantined-cpt-author-social-<?php echo esc_attr( $key ); ?>"
									name="quarantined_cpt_author_social[<?php echo esc_attr( $key ); ?>]"
									value="<?php echo isset( $social_values[ $key ] ) ? esc_attr( $social_values[ $key ] ) : ''; ?>"
									<?php
									if ( ! empty( $field['placeholder'] ) ) {
										echo ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
									}
									?>
								/>
							</p>
						<?php endforeach; ?>
					</div>
					<p class="description"><?php esc_html_e( 'Full URLs to social profiles. Added to Person schema and (optionally) displayed on author pages.', 'nova-bridge-suite' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persists custom author profile fields.
	 *
	 * @param int $user_id User being saved.
	 */
	public function save_user_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['quarantined_cpt_user_fields_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_user_fields_nonce'] ) ) : '';

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'quarantined_cpt_user_fields' ) ) {
			return;
		}

		$display = isset( $_POST['quarantined_cpt_display_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_display_name'] ) ) : '';

		if ( '' === $display ) {
			delete_user_meta( $user_id, self::META_AUTHOR_DISPLAY );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_DISPLAY, $display );
		}

		$slug_raw = isset( $_POST['quarantined_cpt_author_slug'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_author_slug'] ) )
			: '';
		$slug     = $this->sanitize_author_slug( $slug_raw );

		if ( '' === $slug ) {
			delete_user_meta( $user_id, self::META_AUTHOR_SLUG );
		} else {
			$unique_slug = $this->ensure_unique_author_slug( $slug, $user_id );
			update_user_meta( $user_id, self::META_AUTHOR_SLUG, $unique_slug );
		}

		$avatar_id = isset( $_POST['quarantined_cpt_author_avatar_id'] ) ? absint( wp_unslash( (string) $_POST['quarantined_cpt_author_avatar_id'] ) ) : 0;

		if ( $avatar_id && wp_attachment_is_image( $avatar_id ) ) {
			update_user_meta( $user_id, self::META_AUTHOR_AVATAR, $avatar_id );
		} else {
			delete_user_meta( $user_id, self::META_AUTHOR_AVATAR );
		}

		$job_title = isset( $_POST['quarantined_cpt_author_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_author_title'] ) ) : '';

		if ( '' === $job_title ) {
			delete_user_meta( $user_id, self::META_AUTHOR_TITLE );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_TITLE, $job_title );
		}

		$organization = isset( $_POST['quarantined_cpt_author_org'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_author_org'] ) ) : '';

		if ( '' === $organization ) {
			delete_user_meta( $user_id, self::META_AUTHOR_ORG );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_ORG, $organization );
		}

		$organization_url_raw = isset( $_POST['quarantined_cpt_author_org_url'] )
			? esc_url_raw( wp_unslash( (string) $_POST['quarantined_cpt_author_org_url'] ) )
			: '';
		$organization_url     = $this->sanitize_profile_url( $organization_url_raw );

		if ( '' === $organization_url ) {
			delete_user_meta( $user_id, self::META_AUTHOR_ORG_URL );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_ORG_URL, $organization_url );
		}

		$location = isset( $_POST['quarantined_cpt_author_location'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['quarantined_cpt_author_location'] ) ) : '';

		if ( '' === $location ) {
			delete_user_meta( $user_id, self::META_AUTHOR_LOCATION );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_LOCATION, $location );
		}

		$website_raw = isset( $_POST['quarantined_cpt_author_website'] )
			? esc_url_raw( wp_unslash( (string) $_POST['quarantined_cpt_author_website'] ) )
			: '';
		$website     = $this->sanitize_profile_url( $website_raw );

		if ( '' === $website ) {
			delete_user_meta( $user_id, self::META_AUTHOR_WEBSITE );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_WEBSITE, $website );
		}

		$social_input = [];
		if ( isset( $_POST['quarantined_cpt_author_social'] ) && \is_array( $_POST['quarantined_cpt_author_social'] ) ) {
			$social_input = array_map(
				'sanitize_text_field',
				wp_unslash( $_POST['quarantined_cpt_author_social'] )
			);
		}
		$social_clean = $this->sanitize_author_social_input( $social_input );

		if ( empty( $social_clean ) ) {
			delete_user_meta( $user_id, self::META_AUTHOR_SOCIAL );
		} else {
			update_user_meta( $user_id, self::META_AUTHOR_SOCIAL, $social_clean );
		}
	}

	/**
	 * Sanitizes selectors submitted via the settings page.
	 *
	 * @param string|array $value Raw user input.
	 * @return string Sanitized value stored in the database.
	 */
	public function sanitize_cpt_definitions_option( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$slug = isset( $definition['slug'] ) ? sanitize_title_with_dashes( (string) $definition['slug'] ) : '';
			if ( '' === $slug && isset( $definition['type'] ) ) {
				$slug = sanitize_title_with_dashes( (string) $definition['type'] );
			}

			if ( '' === $slug ) {
				continue;
			}

			if ( strlen( $slug ) > 20 ) {
				$slug = substr( $slug, 0, 20 );
			}

			if ( isset( $clean[ $slug ] ) ) {
				continue;
			}

			$singular = $this->sanitize_text_option( $definition['singular'] ?? '' );
			$plural   = $this->sanitize_text_option( $definition['plural'] ?? '' );

			if ( '' === $singular ) {
				$singular = ucwords( str_replace( [ '-', '_' ], ' ', $type ) );
			}

			if ( '' === $plural ) {
				$plural = $singular . 's';
			}

			$schema_type = $this->sanitize_article_schema_type_option( $definition['schema_type'] ?? self::DEFAULT_ARTICLE_SCHEMA_TYPE );

			$clean[ $slug ] = [
				'type'      => $slug,
				'slug'      => $slug,
				'rest_base' => $slug,
				'singular'  => $singular,
				'plural'    => $plural,
				'schema_type' => $schema_type,
			];
		}

		return array_values( $clean );
	}

	public function sanitize_selector_input( $value ): string {
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		$value = (string) $value;
		$value = str_replace( "\r\n", "\n", $value );
		$lines = explode( "\n", $value );

		$clean = array_filter(
			array_map(
				static function ( $line ) {
					$line = trim( (string) $line );

					if ( '' === $line ) {
						return '';
					}

					$line = wp_strip_all_tags( $line );
					$line = preg_replace( '/[<>]/', '', $line );

					return $line;
				},
				$lines
			)
		);

		return implode( "\n", $clean );
	}

	/**
	 * Sanitizes component visibility settings.
	 *
	 * @param array|string $value Raw value from the form.
	 * @return array
	 */
	public function sanitize_component_settings( $value ): array {
		$defaults = $this->get_component_defaults();

		if ( ! is_array( $value ) ) {
			$value = [];
		}

		$sanitized = [];

		foreach ( $defaults as $key => $default ) {
			$sanitized[ $key ] = ! empty( $value[ $key ] );
		}

		return $sanitized;
	}

	public function sanitize_text_option( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = '';
		}

		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/', ' ', $value );

		if ( ! is_string( $value ) ) {
			$value = '';
		}

		return trim( $value );
	}

	public function sanitize_header_offset_option( $value ): string {
		$value = is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		$normalized = $this->normalize_header_offset( $value );

		return '' === $normalized ? self::DEFAULT_HEADER_OFFSET : $normalized;
	}

	public function sanitize_article_schema_type_option( $value ): string {
		if ( ! is_string( $value ) ) {
			return self::DEFAULT_ARTICLE_SCHEMA_TYPE;
		}

		$slug = sanitize_key( $value );

		return array_key_exists( $slug, self::ARTICLE_SCHEMA_TYPES ) ? $slug : self::DEFAULT_ARTICLE_SCHEMA_TYPE;
	}

	/**
	 * Detects whether Yoast SEO is active.
	 *
	 * @return bool
	 */
	private function is_yoast_active(): bool {
		if ( null !== $this->yoast_active ) {
			return $this->yoast_active;
		}

		$this->yoast_active = defined( 'WPSEO_VERSION' )
			|| class_exists( '\\\Yoast\WP\SEO\Main' )
			|| class_exists( '\\\Yoast\WP\SEO\\\YoastSEO' )
			|| class_exists( 'WPSEO_Frontend' );

		return $this->yoast_active;
	}

	public function sanitize_slug_option( $value ): string {
		$value = sanitize_title_with_dashes( (string) $value );

		return '' === $value ? self::BASE_SLUG : $value;
	}

	public function sanitize_author_base_option( $value ): string {
		$value = sanitize_title_with_dashes( (string) $value );

		return '' === $value ? 'authors' : $value;
	}

	public function sanitize_breadcrumb_separator_option( $value ): string {
		if ( ! is_string( $value ) ) {
			$value = 'chevron';
		}

		$value   = sanitize_key( $value );
		$choices = $this->get_breadcrumb_separator_options();

		return array_key_exists( $value, $choices ) ? $value : 'chevron';
	}

	/**
	 * Sanitizes author slugs extracted from pretty permalinks.
	 *
	 * Allows dots and underscores so user nicenames such as "john.doe" keep working.
	 *
	 * @param string $value Raw slug fragment.
	 * @return string
	 */
	private function sanitize_author_slug( string $value ): string {
		$value = rawurldecode( $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[^A-Za-z0-9._-]/', '', $value );

		if ( ! is_string( $value ) ) {
			return '';
		}

		return strtolower( $value );
	}

	private function get_author_slug_for_user( \WP_User $user ): string {
		$stored = get_user_meta( $user->ID, self::META_AUTHOR_SLUG, true );
		$stored = is_string( $stored ) ? $this->sanitize_author_slug( $stored ) : '';

		if ( '' !== $stored ) {
			return $stored;
		}

		return $this->sanitize_author_slug( (string) $user->user_nicename );
	}

	private function get_author_display_for_user( \WP_User $user ): string {
		$stored = get_user_meta( $user->ID, self::META_AUTHOR_DISPLAY, true );
		$stored = is_string( $stored ) ? sanitize_text_field( $stored ) : '';

		if ( '' !== $stored ) {
			return $stored;
		}

		$display = (string) $user->display_name;

		return '' === trim( $display ) ? (string) $user->user_nicename : $display;
	}

	/**
	 * Retrieves a structured profile data array for an author.
	 *
	 * @param WP_User $user User object.
	 * @return array
	 */
	public static function get_author_profile( WP_User $user ): array {
		if ( null === self::$instance ) {
			return [
				'job_title'    => '',
				'organisation' => [
					'name' => '',
					'url'  => '',
				],
				'location'     => '',
				'website'      => '',
				'social'       => [],
				'avatar'       => [
					'id'  => 0,
					'url' => self::get_author_avatar_url( $user ),
				],
			];
		}

		return self::$instance->build_author_profile_data( $user );
	}

	/**
	 * Determines whether the current query is for a plugin-managed author route.
	 *
	 * @return bool
	 */
	private function is_author_detail_request(): bool {
		if ( ! self::author_archive_enabled() ) {
			return false;
		}

		return ( is_author() || '' !== (string) get_query_var( 'quarantined_cpt_author' ) );
	}

	/**
	 * Determines whether any plugin-managed author context is active.
	 *
	 * @return bool
	 */
	private function is_author_context(): bool {
		if ( ! self::author_archive_enabled() ) {
			return false;
		}

		return ( is_author() || '' !== (string) get_query_var( 'quarantined_cpt_author' ) || (bool) get_query_var( 'quarantined_cpt_authors' ) );
	}

	/**
	 * Returns the plugin placeholder avatar URL.
	 *
	 * @return string
	 */
	private function get_placeholder_avatar_url(): string {
		return plugins_url( 'assets/quarantined-cpt-avatar.svg', __FILE__ );
	}

	/**
	 * Resolves the avatar URL for an author with plugin fallbacks.
	 *
	 * @param WP_User $user Author object.
	 * @param int     $size Requested image size.
	 * @param bool    $allow_placeholder Whether placeholder assets are allowed.
	 * @return string
	 */
	private function resolve_author_avatar_url( WP_User $user, int $size = 256, bool $allow_placeholder = true ): string {
		$attachment_id = absint( get_user_meta( $user->ID, self::META_AUTHOR_AVATAR, true ) );

		if ( $attachment_id ) {
			$cropped = wp_get_attachment_image_src( $attachment_id, [ $size, $size ] );

			if ( is_array( $cropped ) && ! empty( $cropped[0] ) ) {
				return (string) $cropped[0];
			}

			$fallback = wp_get_attachment_image_url( $attachment_id, 'full' );

			if ( $fallback ) {
				return $fallback;
			}
		}

		$avatar = get_avatar_url( $user->ID, [ 'size' => $size ] );

		if ( $avatar ) {
			return $avatar;
		}

		if ( $allow_placeholder ) {
			return apply_filters(
				'quarantined_cpt_bodyclean/author_placeholder',
				$this->get_placeholder_avatar_url(),
				$user
			);
		}

		return '';
	}

	/**
	 * Builds an author profile array with sanitised meta.
	 *
	 * @param WP_User $user User object.
	 * @return array
	 */
	private function build_author_profile_data( WP_User $user ): array {
		$job_title        = sanitize_text_field( (string) get_user_meta( $user->ID, self::META_AUTHOR_TITLE, true ) );
		$organization     = sanitize_text_field( (string) get_user_meta( $user->ID, self::META_AUTHOR_ORG, true ) );
		$organization_url = $this->sanitize_profile_url( get_user_meta( $user->ID, self::META_AUTHOR_ORG_URL, true ) );
		$location         = sanitize_text_field( (string) get_user_meta( $user->ID, self::META_AUTHOR_LOCATION, true ) );
		$website          = $this->sanitize_profile_url( get_user_meta( $user->ID, self::META_AUTHOR_WEBSITE, true ) );
		$avatar_id        = absint( get_user_meta( $user->ID, self::META_AUTHOR_AVATAR, true ) );
		$avatar_url       = $this->resolve_author_avatar_url( $user, 256 );

		$social_raw = get_user_meta( $user->ID, self::META_AUTHOR_SOCIAL, true );
		$social_raw = is_array( $social_raw ) ? $social_raw : [];

		$fields       = $this->get_author_social_field_map();
		$social_links = [];

		foreach ( $fields as $key => $field ) {
			$value = isset( $social_raw[ $key ] ) ? $this->sanitize_profile_url( $social_raw[ $key ] ) : '';

			if ( '' === $value ) {
				continue;
			}

			$label = isset( $field['label'] ) ? (string) $field['label'] : ucfirst( (string) $key );

			$social_links[] = [
				'key'   => (string) $key,
				'label' => $label,
				'url'   => $value,
			];
		}

		$data = [
			'job_title'    => $job_title,
			'organisation' => [
				'name' => $organization,
				'url'  => $organization_url,
			],
			'location'     => $location,
			'website'      => $website,
			'social'       => $social_links,
			'avatar'       => [
				'id'  => $avatar_id,
				'url' => $avatar_url,
			],
		];

		return apply_filters( 'quarantined_cpt_bodyclean/author_profile_data', $data, $user, $fields );
	}

	/**
	 * Provides SVG markup for a given social profile key.
	 *
	 * @param string $key Social identifier.
	 * @return string
	 */
	public static function get_social_icon_markup( string $key ): string {
		$key = sanitize_key( $key );

		$icons = [
			'website' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.25c-5.385 0-9.75 4.365-9.75 9.75s4.365 9.75 9.75 9.75 9.75-4.365 9.75-9.75S17.385 2.25 12 2.25Zm6.615 5.25h-2.646a17.91 17.91 0 0 0-1.246-3.516A7.507 7.507 0 0 1 18.615 7.5ZM15.75 12a16.45 16.45 0 0 1-.143 2.25H8.393A16.45 16.45 0 0 1 8.25 12c0-.768.05-1.517.143-2.25h7.214c.093.733.143 1.482.143 2.25Zm-7.084 3.75h6.668a17.912 17.912 0 0 1-1.246 3.516A7.507 7.507 0 0 1 8.666 15.75Zm6.668-7.5H8.666A17.91 17.91 0 0 1 9.912 4.734 7.507 7.507 0 0 1 15.334 8.25Zm-7.494-3.016A17.912 17.912 0 0 0 6.594 7.5H3.385a7.46 7.46 0 0 1 4.455-2.266ZM3.375 9H6.3a18.83 18.83 0 0 0-.18 3c0 1.035.066 2.048.18 3H3.375a7.455 7.455 0 0 1 0-6ZM3.385 16.5h3.209a17.91 17.91 0 0 0 1.246 3.516A7.46 7.46 0 0 1 3.385 16.5Zm10.673 3.516A17.91 17.91 0 0 0 15.348 16.5h3.267a7.46 7.46 0 0 1-4.557 3.516Zm4.838-5.016H17.7c.114-.952.18-1.965.18-3 0-1.035-.066-2.048-.18-3h2.925a7.455 7.455 0 0 1 0 6Z"/></svg>',
			'linkedin' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4.98 3.5a2.5 2.5 0 1 1-4.98 0 2.5 2.5 0 0 1 4.98 0ZM.43 8.28h4.91V22H.43V8.28Zm7.53 0h4.7v1.87h.07c.65-1.23 2.23-2.52 4.58-2.52 4.9 0 5.8 3.23 5.8 7.42V22h-4.92v-6.25c0-1.49-.03-3.41-2.08-3.41-2.08 0-2.4 1.62-2.4 3.3V22h-4.85V8.28Z"/></svg>',
			'facebook' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M13 22V12.8h3.1l.46-3.6H13V6.6c0-1.04.29-1.75 1.78-1.75h1.9V1.6a25.6 25.6 0 0 0-2.78-.14c-2.75 0-4.64 1.68-4.64 4.76v2.67H6.1v3.6h3.16V22H13Z"/></svg>',
			'instagram' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.163c3.204 0 3.584.012 4.85.07 1.366.062 2.633.35 3.608 1.325.975.975 1.263 2.242 1.325 3.608.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.062 1.366-.35 2.633-1.325 3.608-.975.975-2.242 1.263-3.608 1.325-1.266.058-1.646.07-4.85.07s-3.584-.012-4.85-.07c-1.366-.062-2.633-.35-3.608-1.325-.975-.975-1.263-2.242-1.325-3.608C2.175 15.746 2.163 15.366 2.163 12s.012-3.584.07-4.85c.062-1.366.35-2.633 1.325-3.608C4.533 2.567 5.8 2.279 7.166 2.217 8.432 2.159 8.812 2.163 12 2.163Zm0 1.807c-3.17 0-3.548.012-4.795.069-1.03.047-1.588.216-1.957.362-.492.19-.843.418-1.213.788a2.788 2.788 0 0 0-.788 1.213c-.146.369-.315.927-.362 1.957-.057 1.247-.069 1.624-.069 4.795s.012 3.548.069 4.795c.047 1.03.216 1.588.362 1.957.19.492.418.843.788 1.213.37.37.721.598 1.213.788.369.146.927.315 1.957.362 1.247.057 1.624.069 4.795.069s3.548-.012 4.795-.069c1.03-.047 1.588-.216 1.957-.362.492-.19.843-.418 1.213-.788.37-.37.598-.721.788-1.213.146-.369.315-.927.362-1.957.057-1.247.069-1.624.069-4.795s-.012-3.548-.069-4.795c-.047-1.03-.216-1.588-.362-1.957-.19-.492-.418-.843-.788-1.213a2.788 2.788 0 0 0-1.213-.788c-.369-.146-.927-.315-1.957-.362-1.247-.057-1.624-.069-4.795-.069Zm0 3.905a5.025 5.025 0 1 1 0 10.05 5.025 5.025 0 0 1 0-10.05Zm0 1.807a3.218 3.218 0 1 0 0 6.436 3.218 3.218 0 0 0 0-6.436Zm6.406-2.18a1.17 1.17 0 1 1-2.34 0 1.17 1.17 0 0 1 2.34 0Z"/></svg>',
			'x' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M3.4 2h3.75l6.22 8.66L19.6 2H22l-7.64 9.86L21.2 22h-3.75l-6.58-9.1L7.6 22H2l8.14-10.53L3.4 2Z"/></svg>',
			'youtube' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M21.8 8s-.2-1.43-.82-2.06c-.78-.82-1.66-.87-2.06-.9C15.6 5 12 5 12 5h-.01s-3.6 0-6.91.07c-.4.03-1.28.08-2.06.9C2.2 6.57 2 8 2 8s-.2 1.66-.2 3.33v1.34C1.8 14 2 15.67 2 15.67s.2 1.43.82 2.06c.78.82 1.8.8 2.26.88C6.57 18.77 12 18.8 12 18.8s3.6 0 6.91-.07c.4-.08 1.28-.06 2.06-.88.62-.63.82-2.06.82-2.06s.2-1.66.2-3.34V11.33C22 9.66 21.8 8 21.8 8ZM10 14.73V9.27l5.2 2.73L10 14.73Z"/></svg>',
			'tiktok' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M16.5 3.5c.7.52 1.5.94 2.37 1.13v3.03a6.04 6.04 0 0 1-2.37-.5v5.96c0 3.04-2.46 5.51-5.5 5.51S5.5 16.16 5.5 13.12 7.96 7.61 11 7.61c.25 0 .5.02.74.05v3.24a2.47 2.47 0 0 0-.74-.11 2.38 2.38 0 1 0 2.37 2.38V2h3.13v1.5Z"/></svg>',
			'pinterest' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" fill-rule="evenodd" clip-rule="evenodd" d="M9 3h5a5 5 0 1 1 0 10h-2v8H9V3Zm3 3v4h2a2 2 0 1 0 0-4h-2Z"/></svg>',
			'generic' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M10.59 13.41a1 1 0 0 0 1.41 1.41l4.24-4.24a3 3 0 0 0-4.24-4.24l-1.18 1.18a1 1 0 0 0 1.41 1.41l1.18-1.18a1 1 0 0 1 1.41 1.41l-4.24 4.24Zm-1.18-6.59L5.17 11.06a3 3 0 0 0 4.24 4.24l1.18-1.18a1 1 0 1 0-1.41-1.41l-1.18 1.18a1 1 0 0 1-1.41-1.41l4.24-4.24a1 1 0 1 0-1.41-1.41Z"/></svg>',
		];

		$icon = $icons['generic'];

		if ( array_key_exists( $key, $icons ) ) {
			$icon = $icons[ $key ];
		}

		return apply_filters( 'quarantined_cpt_bodyclean/social_icon_markup', $icon, $key, $icons );
	}

	/**
	 * Returns the supported social profile fields.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_author_social_field_map(): array {
		$fields = [
			'linkedin'  => [
				'label'       => __( 'LinkedIn', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.linkedin.com/in/username/',
			],
			'facebook'  => [
				'label'       => __( 'Facebook', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.facebook.com/username',
			],
			'instagram' => [
				'label'       => __( 'Instagram', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.instagram.com/username/',
			],
			'x'         => [
				'label'       => __( 'X (Twitter)', 'nova-bridge-suite' ),
				'placeholder' => 'https://twitter.com/username',
			],
			'youtube'   => [
				'label'       => __( 'YouTube', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.youtube.com/@channel',
			],
			'tiktok'    => [
				'label'       => __( 'TikTok', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.tiktok.com/@username',
			],
			'pinterest' => [
				'label'       => __( 'Pinterest', 'nova-bridge-suite' ),
				'placeholder' => 'https://www.pinterest.com/username/',
			],
		];

		return apply_filters( 'quarantined_cpt_bodyclean/author_social_fields', $fields );
	}

	/**
	 * Sanitizes a profile URL allowing only valid external links.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	private function sanitize_profile_url( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$sanitized = esc_url_raw( $value );

		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Sanitizes social profile submissions from the user profile form.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, string>
	 */
	private function sanitize_author_social_input( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}

		$fields = $this->get_author_social_field_map();
		$clean  = [];

		foreach ( $fields as $key => $field ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = $input[ $key ];

			if ( is_array( $value ) ) {
				$value = reset( $value );
			}

			$url = $this->sanitize_profile_url( $value );

			if ( '' === $url ) {
				continue;
			}

			$clean[ $key ] = $url;
		}

		return apply_filters( 'quarantined_cpt_bodyclean/author_social_sanitized', $clean, $input, $fields );
	}

	private function ensure_unique_author_slug( string $slug, int $user_id ): string {
		if ( '' === $slug ) {
			return '';
		}

		$unique = $slug;
		$index  = 2;

		while ( $this->slug_in_use_by_other( $unique, $user_id ) ) {
			$unique = $slug . '-' . $index;
			$index++;

			if ( $index > 50 ) {
				$unique = $slug . '-' . strtolower( wp_generate_password( 4, false ) );
				break;
			}
		}

		return $unique;
	}

	private function slug_in_use_by_other( string $slug, int $user_id ): bool {
		$user = $this->find_author_by_slug( $slug );

		return $user instanceof WP_User && (int) $user->ID !== $user_id;
	}

	private function find_author_by_slug( string $slug ): ?WP_User {
		$slug = $this->sanitize_author_slug( $slug );

		if ( '' === $slug ) {
			return null;
		}

		$user = get_user_by( 'slug', $slug );

		if ( $user instanceof \WP_User ) {
			return $user;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$users = get_users(
			[
				'meta_key'     => self::META_AUTHOR_SLUG, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'       => 1,
				'count_total'  => false,
				'fields'       => 'all',
				'no_found_rows'=> true,
			]
		);

		if ( ! empty( $users ) && $users[0] instanceof WP_User ) {
			return $users[0];
		}

		return null;
	}

	/**
	 * Retrieves custom exclusion selectors as an array.
	 *
	 * @return string[]
	 */
	private function get_custom_exclude_selectors(): array {
		$value = get_option( self::OPTION_EXCLUDE_SELECTORS, '' );

		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		$value = (string) $value;

		$lines = array_filter(
			array_map(
				static function ( $line ) {
					$line = trim( (string) $line );

					if ( '' === $line ) {
						return '';
					}

					return wp_strip_all_tags( $line );
				},
				preg_split( '/\r\n|\r|\n/', $value )
			)
		);

		return apply_filters( 'quarantined_cpt_bodyclean/custom_exclude_selectors', array_values( $lines ) );
	}

	/**
	 * Builds CSS rules for the custom exclusion selectors.
	 *
	 * @return string
	 */
	private function build_custom_exclusion_rules(): string {
		$selectors = $this->get_custom_exclude_selectors();

		if ( empty( $selectors ) ) {
			return '';
		}

		$rules = array_map(
			static function ( $selector ) {
				return sprintf(
					'body.quarantined-cpt-bodyclean %1$s { display: none !important; }',
					$selector
				);
			},
			$selectors
		);

		return implode( "\n", $rules );
	}

	/**
	 * Returns the default component visibility map.
	 *
	 * @return array
	 */
	private function get_component_defaults(): array {
		return [
			'title'       => true,
			'breadcrumbs' => true,
			'date'        => true,
			'author'      => true,
			'featured'    => true,
		];
	}

	/**
	 * Retrieves merged component visibility settings.
	 *
	 * @return array
	 */
	private function get_component_settings(): array {
		$defaults = $this->get_component_defaults();
		$saved    = get_option( self::OPTION_COMPONENT_VISIBILITY, null );

		if ( ! is_array( $saved ) ) {
			return apply_filters( 'quarantined_cpt_bodyclean/component_settings', $defaults, $defaults, [] );
		}

		if ( empty( $saved ) ) {
			return apply_filters( 'quarantined_cpt_bodyclean/component_settings', $defaults, $defaults, $saved );
		}

		$normalized = $defaults;

		foreach ( $defaults as $key => $default ) {
			$normalized[ $key ] = ! empty( $saved[ $key ] );
		}

		return apply_filters( 'quarantined_cpt_bodyclean/component_settings', $normalized, $defaults, $saved );
	}

	/**
	 * Determines whether a component should be displayed.
	 *
	 * @param string $component Component key.
	 * @return bool
	 */
	public static function component_enabled( string $component ): bool {
		if ( null === self::$instance ) {
			return true;
		}

		return self::$instance->is_component_enabled( $component );
	}

	/**
	 * Instance helper for component visibility lookup.
	 *
	 * @param string $component Component key.
	 * @return bool
	 */
	private function is_component_enabled( string $component ): bool {
		$settings = $this->get_component_settings();

		return isset( $settings[ $component ] ) ? (bool) $settings[ $component ] : true;
	}

	public static function get_post_type(): string {
		if ( null === self::$instance ) {
			return self::CPT;
		}

		$types = self::$instance->get_cpt_types();

		if ( empty( $types ) ) {
			return self::CPT;
		}

		return (string) $types[0];
	}

	/**
	 * Returns all active CPT slugs registered by the plugin.
	 *
	 * @return array
	 */
	public static function get_post_types(): array {
		if ( null === self::$instance ) {
			return [ self::CPT ];
		}

		return self::$instance->get_cpt_types();
	}

	/**
	 * Returns the configured author archive title.
	 *
	 * @return string
	 */
	public static function get_author_archive_title_text(): string {
		if ( null === self::$instance ) {
			return __( 'Authors', 'nova-bridge-suite' );
		}

		return self::$instance->get_author_archive_title();
	}

	/**
	 * Returns the active breadcrumb separator.
	 *
	 * @return string
	 */
	public static function get_breadcrumb_separator(): string {
		if ( null === self::$instance ) {
			return '›';
		}

		return self::$instance->resolve_breadcrumb_separator();
	}

	/**
	 * Returns the public author base slug.
	 *
	 * @return string
	 */
	public static function get_author_base_slug(): string {
		if ( null === self::$instance ) {
			return 'authors';
		}

		return self::$instance->get_author_base();
	}

	/**
	 * Returns the slug used for a given author within SEOR CPT routes.
	 *
	 * @param \WP_User $user Author object.
	 * @return string
	 */
	public static function get_author_slug( \WP_User $user ): string {
		if ( null === self::$instance ) {
			return (string) $user->user_nicename;
		}

		return self::$instance->get_author_slug_for_user( $user );
	}

	/**
	 * Returns the display name used for a given author on SEOR CPT templates.
	 *
	 * @param \WP_User $user Author object.
	 * @return string
	 */
	public static function get_author_display_name( \WP_User $user ): string {
		if ( null === self::$instance ) {
			$name = (string) $user->display_name;

			return '' === trim( $name ) ? (string) $user->user_nicename : $name;
		}

		return self::$instance->get_author_display_for_user( $user );
	}

	/**
	 * Returns the preferred avatar URL for an author.
	 *
	 * @param \WP_User $user Author object.
	 * @param int      $size Requested square size.
	 * @param bool     $allow_placeholder Whether to fall back to the plugin placeholder.
	 * @return string
	 */
	public static function get_author_avatar_url( \WP_User $user, int $size = 256, bool $allow_placeholder = true ): string {
		if ( null === self::$instance ) {
			$url = get_avatar_url( $user->ID, [ 'size' => $size ] );

			if ( $url || ! $allow_placeholder ) {
				return (string) $url;
			}

			$placeholder = plugins_url( 'assets/quarantined-cpt-avatar.svg', __FILE__ );

			return apply_filters( 'quarantined_cpt_bodyclean/author_placeholder', $placeholder, $user );
		}

		return self::$instance->resolve_author_avatar_url( $user, $size, $allow_placeholder );
	}

	public static function author_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Door', 'nova-bridge-suite' );
		}

		return self::$instance->get_author_label();
	}

	public static function publications_label( int $count, \WP_User $author = null ): string {
		/* translators: %s: number of publications. */
		$pattern = __( '%s publications', 'nova-bridge-suite' );

		if ( null !== self::$instance ) {
			$pattern = self::$instance->get_publications_label();
		}

		$pattern = apply_filters( 'quarantined_cpt_bodyclean/publications_label_pattern', $pattern, $count, $author );

		return sprintf( $pattern, number_format_i18n( $count ) );
	}

	/**
	 * Builds the SEOR CPT-managed author permalink.
	 *
	 * @param \WP_User $user Author object.
	 * @return string
	 */
	public static function get_author_permalink( \WP_User $user ): string {
		$base = trim( self::get_author_base_slug(), '/' );
		$slug = self::get_author_slug( $user );

		if ( '' === $base || '' === $slug ) {
			return home_url( '/' );
		}

		return trailingslashit( home_url( '/' . $base . '/' . $slug ) );
	}

	/**
	 * Sets up output buffering so custom exclusion selectors can remove elements entirely.
	 */
	public function maybe_buffer_output(): void {
		if ( is_admin() ) {
			return;
		}

		$post_types       = $this->get_cpt_types();
		$is_author_context = $this->is_author_context();

		$is_cpt_context = ! empty( $post_types ) && ( is_singular( $post_types ) || is_post_type_archive( $post_types ) );

		if ( ! ( $is_cpt_context || $is_author_context ) ) {
			return;
		}

		$context = $is_cpt_context ? 'single' : 'author';

		if ( $is_cpt_context && is_post_type_archive( $post_types ) ) {
			$context = 'archive';
		}

		$selectors = $this->get_custom_exclude_selectors();
		$remove_person_schema = $this->is_author_detail_request();

		if ( ! empty( $selectors ) ) {
			$should_process = apply_filters(
				'quarantined_cpt_bodyclean/enable_selector_cleanup',
				true,
				$context,
				$selectors,
				$this
			);

			if ( false === $should_process ) {
				$selectors = [];
			}
		}

		if ( empty( $selectors ) && ! $remove_person_schema ) {
			return;
		}

		// Skip buffering for non-HTML or risky contexts to avoid parser fatals.
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';

		if ( 'GET' !== $request_method ) {
			return;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return;
		}

		if ( is_feed() || is_embed() ) {
			return;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) : '';

		if ( $accept && false === stripos( $accept, 'text/html' ) && false === stripos( $accept, 'application/xhtml+xml' ) ) {
			return;
		}

		$handlers = array_map( 'strval', ob_list_handlers() );
		$compression_active = (bool) ini_get( 'zlib.output_compression' ) || in_array( 'ob_gzhandler', $handlers, true );

		if ( $compression_active ) {
			$skip_for_compression = apply_filters(
				'quarantined_cpt_bodyclean/skip_buffer_for_compression',
				false,
				$context,
				$handlers,
				$this
			);

			if ( $skip_for_compression ) {
				return;
			}
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$is_bot     = (bool) preg_match( '~bot|crawl|spider|slurp|baiduspider|yandex|duckduckgo|ahrefs|semrush~i', $user_agent );

		if ( apply_filters( 'quarantined_cpt_bodyclean/disable_for_bots', $is_bot, $user_agent, $context ) ) {
			return;
		}

		$this->buffer_selectors = $selectors;
		$this->buffer_remove_person_schema = $remove_person_schema;

		if ( $remove_person_schema ) {
			$this->author_schema_suppressed = false;
		}

		ob_start( [ $this, 'filter_buffered_output' ] );
		$this->buffer_level = ob_get_level();
		$handlers = ob_list_handlers();
		$this->buffer_handler = $handlers ? (string) end( $handlers ) : '';

		if ( ! $this->buffer_flush_registered ) {
			add_action( 'shutdown', [ $this, 'flush_buffered_output' ], 0 );
			$this->buffer_flush_registered = true;
		}
	}

	/**
	 * Removes unwanted nodes from the final HTML output.
	 *
	 * @param string $html Buffered HTML.
	 * @return string
	 */
		public function filter_buffered_output( string $html ): string {
			$selectors = $this->buffer_selectors ?? $this->get_custom_exclude_selectors();
		$remove_person_schema = $this->buffer_remove_person_schema;
		$this->buffer_selectors = null;
		$this->buffer_remove_person_schema = false;

		$size_limit = (int) apply_filters(
			'quarantined_cpt_bodyclean/dom_processing_size_limit',
			2 * 1024 * 1024,
			$selectors,
			$remove_person_schema,
			$this
		);

		$html_length = strlen( $html );

		if ( $size_limit > 0 && $html_length > $size_limit ) {
			$should_process_large = apply_filters(
				'quarantined_cpt_bodyclean/process_large_buffer',
				! empty( $selectors ),
				$html_length,
				$size_limit,
				$selectors,
				$remove_person_schema,
				$this
			);

			if ( ! $should_process_large ) {
				if ( $remove_person_schema ) {
					return $this->strip_person_schema_from_html( $html );
				}

				return $html;
			}
		}

		if ( empty( $selectors ) ) {
			if ( $remove_person_schema ) {
				return $this->strip_person_schema_from_html( $html );
			}

			return $html;
		}

		$can_use_dom = class_exists( '\DOMDocument' );

		if ( ! $can_use_dom ) {
			if ( $remove_person_schema ) {
				$html = $this->strip_person_schema_from_html( $html );
			}

			return $html;
		}

		libxml_use_internal_errors( true );

		$dom = new \DOMDocument();
		$encoding_prefix = '';

		if ( false === strpos( $html, '<?xml' ) ) {
			$encoding_prefix = '<?xml encoding="utf-8"?>';
		}

		$html_to_load = $encoding_prefix . $html;

		if ( ! $dom->loadHTML( $html_to_load, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD ) ) {
			libxml_clear_errors();

			if ( $remove_person_schema ) {
				return $this->strip_person_schema_from_html( $html );
			}

			return $html;
		}

		$xpath = new \DOMXPath( $dom );

		if ( ! empty( $selectors ) ) {
			foreach ( $selectors as $selector ) {
				$paths = $this->selector_to_xpath( $selector );

				foreach ( $paths as $path ) {
					if ( '' === $path ) {
						continue;
					}

					$nodes = $xpath->query( $path );

					if ( ! $nodes ) {
						continue;
					}

					for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
						$node = $nodes->item( $i );

						if ( $node && $node->parentNode ) {
							$node->parentNode->removeChild( $node );
						}
					}
				}
			}
		}

		if ( $remove_person_schema ) {
			$this->strip_person_schema_nodes( $dom );
		}

		$output = $dom->saveHTML();
		libxml_clear_errors();

		return is_string( $output ) && '' !== $output ? $output : $html;
	}

	/**
	 * Flushes the output buffer started for selector cleanup.
	 */
	public function flush_buffered_output(): void {
		if ( null === $this->buffer_level ) {
			return;
		}

		if ( ob_get_level() < $this->buffer_level ) {
			$this->buffer_level = null;
			$this->buffer_handler = '';
			return;
		}

		$handlers = ob_list_handlers();
		$top_handler = $handlers ? (string) end( $handlers ) : '';

		if ( '' !== $this->buffer_handler && $top_handler !== $this->buffer_handler ) {
			return;
		}

		$buffered = ob_get_clean();

		if ( false !== $buffered && '' !== $buffered ) {
			echo $buffered;
		}

		$this->buffer_level = null;
		$this->buffer_handler = '';
	}


	/**
	 * Removes JSON-LD Person schema scripts that were not generated by this plugin.
	 *
	 * @param \DOMDocument $dom Parsed document.
	 */
	private function strip_person_schema_nodes( \DOMDocument $dom ): void {
		$xpath = new \DOMXPath( $dom );
		$scripts = $xpath->query( '//script[@type="application/ld+json"]' );

		if ( ! $scripts ) {
			return;
		}

		foreach ( $scripts as $script ) {
			if ( ! ( $script instanceof \DOMElement ) ) {
				continue;
			}

			$content = trim( $script->textContent );

			if ( false !== strpos( $content, '#seor-cpt-person' ) ) {
				continue;
			}

			$data = json_decode( $content, true );

			if ( null === $data || JSON_ERROR_NONE !== json_last_error() ) {
				continue;
			}

			if ( $this->data_contains_person_type( $data ) ) {
				if ( $script->parentNode ) {
					$script->parentNode->removeChild( $script );
				}
				$this->author_schema_suppressed = true;
			}
		}
	}

	/**
	 * Removes Person schema scripts from raw HTML when DOMDocument is unavailable.
	 *
	 * @param string $html Raw HTML markup.
	 * @return string
	 */
	private function strip_person_schema_from_html( string $html ): string {
		$pattern = "#<script[^>]*type=(?:\"|')application/ld\+json(?:\"|')[^>]*>(.*?)</script>#is";

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$content = trim( html_entity_decode( $matches[1] ) );

				if ( false !== strpos( $content, '#seor-cpt-person' ) ) {
					return $matches[0];
				}

				$data = json_decode( $content, true );

				if ( null === $data || JSON_ERROR_NONE !== json_last_error() ) {
					return $matches[0];
				}

				if ( $this->data_contains_person_type( $data ) ) {
					$this->author_schema_suppressed = true;

					return '';
				}

				return $matches[0];
			},
			$html
		);
	}

	/**
	 * Determines whether decoded JSON-LD contains a Person type.
	 *
	 * @param mixed $data Decoded JSON-LD fragment.
	 * @return bool
	 */
	private function data_contains_person_type( $data ): bool {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( '@type' === $key ) {
					if ( is_string( $value ) && 'Person' === $value ) {
						return true;
					}

					if ( is_array( $value ) && in_array( 'Person', $value, true ) ) {
						return true;
					}
				}

				if ( $this->data_contains_person_type( $value ) ) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Converts a CSS-like selector into one or more XPath expressions.
	 * Supports simple selectors (optional tag, classes, id, attributes) and descendant combinators.
	 *
	 * @param string $selector Raw selector.
	 * @return string[]
	 */
	private function selector_to_xpath( string $selector ): array {
		$selector = trim( $selector );

		if ( '' === $selector ) {
			return [];
		}

		$segments = preg_split( '/\s+/', $selector );
		$segments = array_filter( array_map( 'trim', $segments ) );

		if ( empty( $segments ) ) {
			return [];
		}

		$xpath = '';

		foreach ( $segments as $index => $segment ) {
			$segment_xpath = $this->build_xpath_for_segment( $segment );

			if ( '' === $segment_xpath ) {
				return [];
			}

			if ( 0 === $index ) {
				$xpath .= '//' . $segment_xpath;
			} else {
				$xpath .= '//' . $segment_xpath;
			}
		}

		return [ $xpath ];
	}

	/**
\t * Builds an XPath fragment for a single selector segment.
	 *
	 * @param string $segment Selector segment without spaces.
	 * @return string
	 */
	private function build_xpath_for_segment( string $segment ): string {
		$segment = trim( $segment );

		if ( '' === $segment ) {
			return '';
		}

		$tag      = '*';
		$id       = '';
		$classes  = [];
		$attrs    = [];
		$pattern  = '/^([a-zA-Z][a-zA-Z0-9_-]*)/';

		if ( preg_match( $pattern, $segment, $matches ) ) {
			$tag     = $matches[0];
			$segment = substr( $segment, strlen( $matches[0] ) );
		}

		while ( preg_match( '/^#([a-zA-Z0-9_-]+)/', $segment, $matches ) ) {
			$id      = $matches[1];
			$segment = substr( $segment, strlen( $matches[0] ) );
		}

		while ( preg_match( '/^\.([a-zA-Z0-9_-]+)/', $segment, $matches ) ) {
			$classes[] = $matches[1];
			$segment   = substr( $segment, strlen( $matches[0] ) );
		}

		while ( preg_match( '/^\[([a-zA-Z0-9_-]+)(?:=([^\]]+))?\]/', $segment, $matches ) ) {
			$attr_name  = $matches[1];
			$attr_value = isset( $matches[2] ) ? trim( $matches[2], '\"\'"' ) : null;
			$attrs[]    = [ $attr_name, $attr_value ];
			$segment    = substr( $segment, strlen( $matches[0] ) );
		}

		if ( '' !== trim( $segment ) ) {
			return '';
		}

		$xpath = $tag;

		$conditions = [];

		if ( '' !== $id ) {
			$conditions[] = '@id=' . $this->xpath_literal( $id );
		}

		foreach ( $classes as $class ) {
			$conditions[] = 'contains(concat(" ", normalize-space(@class), " "), ' . $this->xpath_literal( ' ' . $class . ' ' ) . ')';
		}

		foreach ( $attrs as $attr ) {
			list( $name, $value ) = $attr;

			if ( null === $value || '' === $value ) {
				$conditions[] = '@' . $name;
			} else {
				$conditions[] = '@' . $name . '=' . $this->xpath_literal( $value );
			}
		}

		if ( ! empty( $conditions ) ) {
			$xpath .= '[' . implode( ' and ', $conditions ) . ']';
		}

		return $xpath;
	}

	/**
	 * Determines whether the plugin should take over author archives.
	 *
	 * @return bool
	 */
	public static function author_archive_enabled(): bool {
		if ( null === self::$instance ) {
			return false;
		}

		return (bool) get_option( self::OPTION_AUTHOR_ARCHIVE, false );
	}

	/**
	 * Filters author permalinks to avoid linking to 404s when archives are disabled.
	 *
	 * @param string $link    Author URL.
	 * @param int    $author_id Author ID.
	 * @param string $nick    Author nickname.
	 * @return string
	 */
	public function filter_author_link( string $link, int $author_id, string $nick ): string {
		if ( ! self::author_archive_enabled() ) {
			return $link;
		}

		$user = get_user_by( 'id', $author_id );

		if ( ! ( $user instanceof \WP_User ) ) {
			return $link;
		}

		$base = trim( self::get_author_base_slug(), '/' );
		$slug = $this->get_author_slug_for_user( $user );

		if ( '' === $base || '' === $slug ) {
			return $link;
		}

		$url = trailingslashit( home_url( '/' . $base . '/' . $slug ) );

		return apply_filters( 'quarantined_cpt_bodyclean/author_link', $url, $author_id, $nick );
	}

	/**
	 * Escapes a value for safe use inside an XPath literal.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function xpath_literal( string $value ): string {
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}

		$parts = explode( "'", $value );

		return "concat('" . implode( "',\'", $parts ) . "')";
	}

	/**
	 * Helper for debug logging when WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 */
	private static function log( string $message ): void {
		if ( ! \defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		\do_action( 'nova_bridge_suite/log', '[SEOR CPT] ' . $message );
	}

}

Plugin::bootstrap();

\register_activation_hook( __FILE__, [ Plugin::class, 'activate' ] );
\register_deactivation_hook( __FILE__, [ Plugin::class, 'deactivate' ] );
