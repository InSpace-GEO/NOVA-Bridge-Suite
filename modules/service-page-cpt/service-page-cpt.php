<?php
/**
 * NOVA Bridge Suite module: Service Page CPT.
 */

namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private const CPT                       = 'service_page';
	private const BASE_SLUG                 = 'services';
	private const OPTION_SLUG               = 'service_cpt_slug';
	private const OPTION_SINGULAR           = 'service_cpt_singular';
	private const OPTION_PLURAL             = 'service_cpt_plural';
	private const OPTION_COMPONENTS         = 'service_cpt_components';
	private const OPTION_EXCLUDE_SELECTORS  = 'service_cpt_exclude_selectors';
	private const OPTION_HEADER_OFFSET      = 'service_cpt_header_offset';
	private const OPTION_TEMPLATE           = 'service_cpt_template';
	private const OPTION_LABEL_FAQ          = 'service_cpt_label_faq';
	private const OPTION_LABEL_RELATED      = 'service_cpt_label_related';
	private const OPTION_GLOBAL_HERO_PRIMARY_LABEL = 'service_cpt_global_hero_primary_label';
	private const OPTION_GLOBAL_HERO_PRIMARY_URL = 'service_cpt_global_hero_primary_url';
	private const OPTION_GLOBAL_HERO_SECONDARY_LABEL = 'service_cpt_global_hero_secondary_label';
	private const OPTION_GLOBAL_HERO_SECONDARY_URL = 'service_cpt_global_hero_secondary_url';
	private const OPTION_GLOBAL_SIDEBAR_TITLE = 'service_cpt_global_sidebar_title';
	private const OPTION_GLOBAL_SIDEBAR_COPY = 'service_cpt_global_sidebar_copy';
	private const OPTION_GLOBAL_SIDEBAR_PRIMARY_LABEL = 'service_cpt_global_sidebar_primary_label';
	private const OPTION_GLOBAL_SIDEBAR_PRIMARY_URL = 'service_cpt_global_sidebar_primary_url';
	private const OPTION_GLOBAL_SIDEBAR_SECONDARY_LABEL = 'service_cpt_global_sidebar_secondary_label';
	private const OPTION_GLOBAL_SIDEBAR_SECONDARY_URL = 'service_cpt_global_sidebar_secondary_url';
	private const OPTION_GLOBAL_CTA_TITLE   = 'service_cpt_global_cta_title';
	private const OPTION_GLOBAL_CTA_BULLET_1 = 'service_cpt_global_cta_bullet_1';
	private const OPTION_GLOBAL_CTA_BULLET_2 = 'service_cpt_global_cta_bullet_2';
	private const OPTION_GLOBAL_CTA_BULLET_3 = 'service_cpt_global_cta_bullet_3';
	private const OPTION_GLOBAL_CTA_BUTTON_LABEL = 'service_cpt_global_cta_button_label';
	private const OPTION_GLOBAL_CTA_BUTTON_URL = 'service_cpt_global_cta_button_url';
	private const OPTION_GLOBAL_CTA_MORE_TEXT = 'service_cpt_global_cta_more_text';
	private const OPTION_GLOBAL_CTA_MORE_URL = 'service_cpt_global_cta_more_url';
	private const OPTION_COLOR_PRIMARY      = 'service_cpt_color_primary';
	private const OPTION_COLOR_CONTRAST     = 'service_cpt_color_contrast';
	private const OPTION_COLOR_SURFACE      = 'service_cpt_color_surface';
	private const OPTION_COLOR_TEXT         = 'service_cpt_color_text';
	private const OPTION_COLOR_ACCENT       = 'service_cpt_color_accent';
	private const OPTION_COLOR_BORDER       = 'service_cpt_color_border';
	private const OPTION_COLOR_HERO_BG      = 'service_cpt_color_hero_bg';
	private const OPTION_COLOR_HERO_TEXT    = 'service_cpt_color_hero_text';
	private const OPTION_COLOR_CTA_BG       = 'service_cpt_color_cta_bg';
	private const OPTION_COLOR_CTA_TEXT     = 'service_cpt_color_cta_text';
	private const OPTION_COLOR_BUTTON_BG    = 'service_cpt_color_button_bg';
	private const OPTION_COLOR_BUTTON_TEXT  = 'service_cpt_color_button_text';
	private const OPTION_COLOR_BUTTON_OUTLINE = 'service_cpt_color_button_outline';
	private const OPTION_COLOR_FAQ_BG       = 'service_cpt_color_faq_bg';
	private const OPTION_COLOR_FAQ_QUESTION = 'service_cpt_color_faq_question';
	private const OPTION_COLOR_FAQ_ANSWER   = 'service_cpt_color_faq_answer';
	private const OPTION_COLOR_TABS_ACTIVE_BG = 'service_cpt_color_tabs_active_bg';
	private const OPTION_COLOR_TABS_ACTIVE_TEXT = 'service_cpt_color_tabs_active_text';
	private const OPTION_COLOR_TABS_INACTIVE_BG = 'service_cpt_color_tabs_inactive_bg';
	private const OPTION_COLOR_TABS_INACTIVE_TEXT = 'service_cpt_color_tabs_inactive_text';
	private const OPTION_COLOR_TABS_BORDER  = 'service_cpt_color_tabs_border';
	private const OPTION_SPACE_SCALE        = 'service_cpt_space_scale';
	private const OPTION_SPACE_SECTION_PADDING = 'service_cpt_space_section_padding';
	private const OPTION_SPACE_SECTION_GAP  = 'service_cpt_space_section_gap';
	private const OPTION_SPACE_CARD_PADDING = 'service_cpt_space_card_padding';
	private const OPTION_CONTENT_WIDTH      = 'service_cpt_content_width';
	private const OPTION_WIDE_WIDTH         = 'service_cpt_wide_width';
	private const OPTION_TEMPLATE_COMPONENTS = 'service_cpt_template_components';
	private const OPTION_ARCHIVE_HERO_EYEBROW = 'service_cpt_archive_hero_eyebrow';
	private const OPTION_ARCHIVE_HERO_TITLE = 'service_cpt_archive_hero_title';
	private const OPTION_ARCHIVE_HERO_COPY = 'service_cpt_archive_hero_copy';
	private const OPTION_ARCHIVE_HERO_CTA_LABEL = 'service_cpt_archive_hero_cta_label';
	private const OPTION_ARCHIVE_HERO_CTA_URL = 'service_cpt_archive_hero_cta_url';
	private const OPTION_ARCHIVE_INTRO_HEADING = 'service_cpt_archive_intro_heading';
	private const OPTION_ARCHIVE_INTRO_COPY = 'service_cpt_archive_intro_copy';
	private const OPTION_ARCHIVE_CARD_CTA_LABEL = 'service_cpt_archive_card_cta_label';
	private const OPTION_ARCHIVE_CARD_PLACEHOLDER = 'service_cpt_archive_card_placeholder';
	private const OPTION_ARCHIVE_SERVICES_MODE = 'service_cpt_archive_services_mode';
	private const OPTION_ARCHIVE_SERVICES_LIMIT = 'service_cpt_archive_services_limit';
	private const OPTION_ARCHIVE_SERVICES_IDS = 'service_cpt_archive_services_ids';
	private const OPTION_ARCHIVE_HIGHLIGHTS_HEADING = 'service_cpt_archive_highlights_heading';
	private const OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE = 'service_cpt_archive_highlight_one_image';
	private const OPTION_ARCHIVE_HIGHLIGHT_ONE_COPY = 'service_cpt_archive_highlight_one_copy';
	private const OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE = 'service_cpt_archive_highlight_two_image';
	private const OPTION_ARCHIVE_HIGHLIGHT_TWO_COPY = 'service_cpt_archive_highlight_two_copy';
	private const OPTION_ARCHIVE_CTA_TITLE = 'service_cpt_archive_cta_title';
	private const OPTION_ARCHIVE_CTA_BULLET_1 = 'service_cpt_archive_cta_bullet_1';
	private const OPTION_ARCHIVE_CTA_BULLET_2 = 'service_cpt_archive_cta_bullet_2';
	private const OPTION_ARCHIVE_CTA_BULLET_3 = 'service_cpt_archive_cta_bullet_3';
	private const OPTION_ARCHIVE_CTA_BUTTON_LABEL = 'service_cpt_archive_cta_button_label';
	private const OPTION_ARCHIVE_CTA_BUTTON_URL = 'service_cpt_archive_cta_button_url';
	private const OPTION_ARCHIVE_CTA_MORE_TEXT = 'service_cpt_archive_cta_more_text';
	private const OPTION_ARCHIVE_CTA_MORE_URL = 'service_cpt_archive_cta_more_url';
	private const OPTION_ARCHIVE_FAQ = 'service_cpt_archive_faq';
	private const OPTION_ARCHIVE_RELATED_POSTS = 'service_cpt_archive_related_posts';
	private const OPTION_ARCHIVE_SEO_TITLE = 'service_cpt_archive_seo_title';
	private const OPTION_ARCHIVE_SEO_DESCRIPTION = 'service_cpt_archive_seo_description';
	private const DEFAULT_HEADER_OFFSET     = '6rem';
	private const DEFAULT_SPACE_SCALE       = '1';
	private const DEFAULT_CONTENT_WIDTH     = '1600px';
	private const DEFAULT_WIDE_WIDTH        = '1800px';
	private const DEFAULT_TEMPLATE          = 'service-page-1-column';
	private const DEFAULT_COMPONENTS        = [
		'hero'        => true,
		'intro'       => true,
		'main'        => true,
		'sidebar_cta' => true,
		'wide_cta'    => true,
		'faq'         => true,
	];
	private const REQUIRED_PLUGINS          = [
		'gutenberg' => [
			'name' => 'Gutenberg',
			'file' => 'gutenberg/gutenberg.php',
		],
		'essential-blocks' => [
			'name' => 'Essential Blocks',
			'file' => 'essential-blocks/essential-blocks.php',
		],
		'faq-block-for-gutenberg' => [
			'name' => 'FAQ Block for Gutenberg',
			'file' => 'faq-block-for-gutenberg/faq-block-for-gutenberg.php',
		],
	];
	private const TEMPLATE_COMPONENTS       = [
		'service-page-1-column' => [ 'hero', 'intro', 'spacer', 'content', 'cta_cover', 'cta_wide', 'faq', 'related' ],
		'service-page-2'        => [ 'hero', 'spacer', 'intro', 'image_text', 'text_image', 'cta_wide', 'faq', 'content', 'related' ],
		'service-page-3'        => [ 'hero', 'intro', 'content', 'cta_cover', 'cta_wide', 'tabs', 'faq' ],
	];
	private const TEMPLATE_COMPONENT_LABELS = [
		'hero'       => 'Hero section',
		'intro'      => 'Intro paragraph',
		'spacer'     => 'Hero spacer',
		'content'    => 'Main content',
		'image_text' => 'Image + text (left)',
		'text_image' => 'Text + image (right)',
		'cta_wide'   => 'CTA wide',
		'cta_cover'  => 'CTA cover',
		'tabs'       => 'Tabs',
		'faq'        => 'FAQ',
		'related'    => 'Related articles',
	];

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;
	private $rendering_service_page = false;
	private $current_service_post_id = 0;
	private $current_service_meta = [];
	private $current_service_title = '';
	private $current_hero_cta = [];
	private $current_sidebar_cta = [];
	private $current_wide_cta = [];
	private $hero_paragraph_index = 0;
	private $hero_button_index = 0;
	private $hero_heading_used = false;
	private $cta_paragraph_index = 0;
	private $cta_button_index = 0;
	private $sidebar_button_index = 0;
	private $sidebar_heading_used = false;
	private $sidebar_paragraph_used = false;
	private $content_heading_index = 0;
	private $content_paragraph_index = 0;
	private $content_image_index = 0;
	private $faq_index = 0;
	private $table_used = false;
	private $inline_eb_tab_styles = [];
	private $inline_style_queue = [];
	private $late_inline_style_queue = [];
	private $late_inline_styles_hooked = false;

	/**
	 * Bootstraps the plugin.
	 */
	public static function bootstrap(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Static helper to render a service page by ID.
	 */
	public static function render_page( int $post_id ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->render_service_page( $post_id );
	}

	private function __construct() {
		\register_activation_hook( __FILE__, [ self::class, 'activate' ] );
		\register_deactivation_hook( __FILE__, [ self::class, 'deactivate' ] );

		\add_action( 'admin_notices', [ $this, 'render_dependency_notice' ] );
		\add_action( 'admin_post_service_cpt_install_plugin', [ $this, 'handle_dependency_install' ] );
		\add_action( 'current_screen', [ $this, 'maybe_block_admin_screen' ] );

		\add_action( 'init', [ $this, 'register_post_type' ] );
		\add_action( 'init', [ $this, 'register_meta_fields' ] );
		\add_action( 'init', [ $this, 'register_block' ] );
		\add_action( 'init', [ $this, 'register_block_patterns' ] );
		\add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );
		\add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		\add_action( 'wp', [ $this, 'maybe_override_faq_schema' ] );
		\add_action( 'wp_head', [ $this, 'render_service_faq_schema' ], 9 );
		\add_filter( 'template_include', [ $this, 'maybe_use_templates' ] );
		\add_filter( 'body_class', [ $this, 'add_body_class' ] );
		\add_filter( 'enter_title_here', [ $this, 'title_placeholder' ], 10, 2 );
		\add_filter( 'use_block_editor_for_post_type', [ $this, 'force_block_editor' ], 100, 2 );
		\add_filter( 'gutenberg_can_edit_post_type', [ $this, 'force_block_editor' ], 100, 2 );
		\add_filter( 'allowed_block_types_all', [ $this, 'ensure_block_allowed' ], 100, 2 );
		\add_filter( 'block_editor_settings_all', [ $this, 'lock_block_editor' ], 10, 2 );
		\add_filter( 'render_block_data', [ $this, 'normalize_block_data' ], 10, 3 );
		\add_filter( 'render_block', [ $this, 'filter_rendered_block' ], 10, 2 );
		\add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		\add_action( 'save_post_' . self::CPT, [ $this, 'ensure_template_content_on_save' ], 5, 3 );
		\add_action( 'save_post_' . self::CPT, [ $this, 'save_meta_box' ], 10, 2 );
		\add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

		\add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		\add_action( 'admin_init', [ $this, 'register_settings' ] );
		\add_action( 'admin_menu', [ $this, 'register_cpt_settings_submenu' ] );
		\add_action( 'update_option_' . self::OPTION_TEMPLATE, [ $this, 'handle_template_option_update' ], 10, 3 );

		\add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		\add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
		\add_filter( 'rest_pre_insert_' . self::CPT, [ $this, 'rest_pre_insert_service_page' ], 10, 2 );
		\add_action( 'rest_after_insert_' . self::CPT, [ $this, 'rest_after_insert_service_page' ], 10, 3 );
		\add_filter( 'rest_prepare_' . self::CPT, [ $this, 'filter_rest_service_page_response' ], 10, 3 );
		\add_filter( 'rest_post_dispatch', [ $this, 'maybe_return_schema_on_empty_collection' ], 10, 3 );
		\add_filter( 'pre_get_document_title', [ $this, 'filter_archive_document_title' ] );
		\add_filter( 'wpseo_title', [ $this, 'filter_wpseo_title' ] );
		\add_filter( 'wpseo_opengraph_desc', [ $this, 'filter_wpseo_description' ] );
		\add_filter( 'wpseo_metadesc', [ $this, 'filter_wpseo_description' ] );
		\add_action( 'wp_head', [ $this, 'render_archive_meta_description' ], 1 );
	}

	/**
	 * Activation tasks: register the CPT and flush rewrites.
	 */
	public static function activate(): void {
		self::bootstrap();

		if ( null !== self::$instance ) {
			self::$instance->register_post_type();
		}

		\add_option( self::OPTION_CONTENT_WIDTH, self::DEFAULT_CONTENT_WIDTH );
		\add_option( self::OPTION_WIDE_WIDTH, self::DEFAULT_WIDE_WIDTH );
		if ( null !== self::$instance ) {
			$presets = self::$instance->get_color_presets();
			$defaults = $presets['modern-slate']['values'] ?? [];
			foreach ( $defaults as $option => $value ) {
				if ( false === \get_option( $option, false ) ) {
					\add_option( $option, $value );
				}
			}
			$archive_defaults = self::$instance->get_archive_defaults();
			foreach ( $archive_defaults as $option => $value ) {
				if ( false === \get_option( $option, false ) ) {
					\add_option( $option, $value );
				}
			}
		}

		\flush_rewrite_rules();
	}

	/**
	 * Deactivation tasks: flush rewrites.
	 */
	public static function deactivate(): void {
		\flush_rewrite_rules();
	}

	/**
	 * Registers the service page CPT.
	 */
	public function register_post_type(): void {
		$labels   = $this->get_labels();
		$base     = $this->get_base_slug();
		$args = [
			'labels'             => $labels,
			'public'             => true,
			'has_archive'        => $base,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_rest'       => true,
			'rest_base'          => $base,
			'menu_position'      => 22,
			'menu_icon'          => 'dashicons-screenoptions',
			'supports'           => [ 'title', 'thumbnail', 'revisions', 'author' ],
			'rewrite'            => [
				'slug'       => $base,
				'with_front' => false,
			],
		];

		\register_post_type( self::CPT, $args );
	}

	/**
	 * Returns localized labels based on settings.
	 */
	private function get_labels(): array {
		$singular = $this->get_singular_name();
		$plural   = $this->get_plural_name();

		return [
			'name'                  => $plural,
			'singular_name'         => $singular,
			'add_new'               => __( 'Add New', 'nova-bridge-suite' ),
			/* translators: %s: Service page singular label. */
			'add_new_item'          => sprintf( __( 'Add New %s', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page singular label. */
			'edit_item'             => sprintf( __( 'Edit %s', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page singular label. */
			'new_item'              => sprintf( __( 'New %s', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page singular label. */
			'view_item'             => sprintf( __( 'View %s', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page plural label. */
			'search_items'          => sprintf( __( 'Search %s', 'nova-bridge-suite' ), $plural ),
			/* translators: %s: Service page plural label. */
			'not_found'             => sprintf( __( 'No %s found.', 'nova-bridge-suite' ), strtolower( $plural ) ),
			/* translators: %s: Service page plural label. */
			'not_found_in_trash'    => sprintf( __( 'No %s found in Trash.', 'nova-bridge-suite' ), strtolower( $plural ) ),
			/* translators: %s: Service page plural label. */
			'all_items'             => sprintf( __( 'All %s', 'nova-bridge-suite' ), $plural ),
			/* translators: %s: Service page singular label. */
			'archives'              => sprintf( __( '%s Archives', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page singular label. */
			'attributes'            => sprintf( __( '%s Attributes', 'nova-bridge-suite' ), $singular ),
			/* translators: %s: Service page singular label. */
			'uploaded_to_this_item' => sprintf( __( 'Uploaded to this %s', 'nova-bridge-suite' ), strtolower( $singular ) ),
			'menu_name'             => $plural,
		];
	}

	private function get_base_slug(): string {
		$slug = \get_option( self::OPTION_SLUG, self::BASE_SLUG );
		$slug = \sanitize_title_with_dashes( (string) $slug );

		return '' === $slug ? self::BASE_SLUG : $slug;
	}

	private function get_singular_name(): string {
		$default = __( 'Service Page', 'nova-bridge-suite' );
		$value   = \sanitize_text_field( (string) \get_option( self::OPTION_SINGULAR, $default ) );

		return '' === $value ? $default : $value;
	}

	private function get_plural_name(): string {
		$default = __( 'Service Pages', 'nova-bridge-suite' );
		$value   = \sanitize_text_field( (string) \get_option( self::OPTION_PLURAL, $default ) );

		return '' === $value ? $default : $value;
	}

	/**
	 * Returns available block templates.
	 */
	private function get_templates(): array {
		return [
			'service-page-1-column' => [
				'label' => __( 'Service Page - 1 Column', 'nova-bridge-suite' ),
				'file'  => __DIR__ . '/templates/layouts/service-page-1-column.html',
			],
			'service-page-2' => [
				'label' => __( 'Service Page - 2 Column', 'nova-bridge-suite' ),
				'file'  => __DIR__ . '/templates/layouts/service-page-2.html',
			],
			'service-page-3' => [
				'label' => __( 'Service Page - 3 Column + Tabs', 'nova-bridge-suite' ),
				'file'  => __DIR__ . '/templates/layouts/service-page-3.html',
			],
		];
	}

	private function get_template_label( string $slug ): string {
		$templates = $this->get_templates();

		return isset( $templates[ $slug ] ) ? (string) $templates[ $slug ]['label'] : $slug;
	}

	private function get_selected_template_slug(): string {
		$value = (string) \get_option( self::OPTION_TEMPLATE, self::DEFAULT_TEMPLATE );

		return $this->sanitize_template_option( $value );
	}

	private function get_archive_defaults(): array {
		return [
			self::OPTION_ARCHIVE_HERO_EYEBROW    => __( 'Our amazing clients', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_HERO_TITLE      => "The Perfect Theme For\nStunning Websites!",
			self::OPTION_ARCHIVE_HERO_COPY       => __( 'Lorem ipsum dolor sit amet consectetur adipiscing eiusmod tempor incididunt.', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_HERO_CTA_LABEL  => __( 'See our services', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_HERO_CTA_URL    => '#service-cpt-archive-services',
			self::OPTION_ARCHIVE_INTRO_HEADING   => __( 'Service overview', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_INTRO_COPY      => __( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent rutrum aliquet nunc, non porta quam luctus non. Phasellus ut tristique velit, in porta tortor. Curabitur efficitur finibus leo, id laoreet mi posuere ut.', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_CARD_CTA_LABEL  => __( 'Learn more', 'nova-bridge-suite' ),
			self::OPTION_ARCHIVE_SERVICES_MODE   => 'auto',
			self::OPTION_ARCHIVE_SERVICES_LIMIT  => '0',
			self::OPTION_ARCHIVE_SEO_TITLE       => '',
			self::OPTION_ARCHIVE_SEO_DESCRIPTION => '',
		];
	}

	private function get_archive_default( string $option ): string {
		$defaults = $this->get_archive_defaults();

		return isset( $defaults[ $option ] ) ? (string) $defaults[ $option ] : '';
	}

	private function get_archive_text_option( string $option ): string {
		$default = $this->get_archive_default( $option );
		$value = \sanitize_text_field( (string) \get_option( $option, $default ) );

		return $value;
	}

	private function get_archive_multiline_option( string $option ): string {
		$default = $this->get_archive_default( $option );
		$value = \sanitize_textarea_field( (string) \get_option( $option, $default ) );

		return $value;
	}

	private function get_archive_rich_text_option( string $option ): string {
		$default = $this->get_archive_default( $option );
		$value = \wp_kses_post( (string) \get_option( $option, $default ) );

		return $value;
	}

	private function get_archive_url_option( string $option ): string {
		$default = $this->get_archive_default( $option );
		$value = \esc_url_raw( (string) \get_option( $option, $default ) );

		return $value;
	}

	private function get_archive_int_option( string $option ): int {
		return \absint( \get_option( $option, 0 ) );
	}

	private function get_archive_service_mode(): string {
		$value = (string) \get_option( self::OPTION_ARCHIVE_SERVICES_MODE, $this->get_archive_default( self::OPTION_ARCHIVE_SERVICES_MODE ) );

		return $this->sanitize_archive_service_mode( $value );
	}

	private function get_archive_service_ids(): array {
		$value = \get_option( self::OPTION_ARCHIVE_SERVICES_IDS, [] );

		return self::sanitize_post_ids( $value );
	}

	private function get_archive_related_post_ids(): array {
		$value = \get_option( self::OPTION_ARCHIVE_RELATED_POSTS, [] );

		return self::sanitize_post_ids( $value );
	}

	private function get_archive_faq_items(): array {
		$value = \get_option( self::OPTION_ARCHIVE_FAQ, [] );

		return self::sanitize_faq( $value );
	}

	private function get_archive_services(): array {
		$mode = $this->get_archive_service_mode();
		$limit = $this->get_archive_int_option( self::OPTION_ARCHIVE_SERVICES_LIMIT );
		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'posts_per_page' => $limit > 0 ? $limit : -1,
		];

		if ( 'manual' === $mode ) {
			$ids = $this->get_archive_service_ids();
			if ( empty( $ids ) ) {
				return [];
			}
			$args['post__in'] = $ids;
			$args['orderby'] = 'post__in';
		}

		return \get_posts( $args );
	}

	private function get_section_label( string $option, string $fallback ): string {
		$value = \get_option( $option, '' );
		$value = \sanitize_text_field( (string) $value );

		return '' !== $value ? $value : $fallback;
	}

	private function get_faq_heading_label(): string {
		return $this->get_section_label( self::OPTION_LABEL_FAQ, __( 'Faq', 'nova-bridge-suite' ) );
	}

	private function get_related_heading_label(): string {
		return $this->get_section_label( self::OPTION_LABEL_RELATED, __( 'Related articles', 'nova-bridge-suite' ) );
	}

	private function get_template_content( string $slug ): string {
		$templates = $this->get_templates();

		if ( empty( $templates[ $slug ]['file'] ) ) {
			return '';
		}

		$path = $templates[ $slug ]['file'];

		if ( ! \is_readable( $path ) ) {
			return '';
		}

		$content = \file_get_contents( $path );

		return false === $content ? '' : (string) $content;
	}

	private function get_selected_template_content(): string {
		return $this->get_template_content( $this->get_selected_template_slug() );
	}

	private function get_templates_payload(): array {
		$payload = [];

		foreach ( $this->get_templates() as $slug => $template ) {
			$payload[ $slug ] = [
				'label'   => (string) $template['label'],
				'content' => $this->get_template_content( $slug ),
			];
		}

		return $payload;
	}

	private function get_effective_template_slug( int $post_id ): string {
		return $this->get_selected_template_slug();
	}

	private function get_template_source( int $post_id ): string {
		return 'global';
	}

	private function build_template_from_blocks( array $blocks ): array {
		$template = [];

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			if ( ! $block_name ) {
				continue;
			}

			$inner_blocks = $block['innerBlocks'] ?? [];
			$template[]   = [
				$block_name,
				$block['attrs'] ?? [],
				$this->build_template_from_blocks( $inner_blocks ),
			];
		}

		return $template;
	}

	public function sanitize_template_option( $value ): string {
		$slug      = \sanitize_key( (string) $value );
		$templates = $this->get_templates();

		if ( isset( $templates[ $slug ] ) ) {
			return $slug;
		}

		return self::DEFAULT_TEMPLATE;
	}

	public function sanitize_archive_service_mode( $value ): string {
		$value = \strtolower( \trim( (string) $value ) );

		return in_array( $value, [ 'auto', 'manual' ], true ) ? $value : 'auto';
	}

	/**
	 * Registers all meta fields for the CPT.
	 */
	public function register_meta_fields(): void {
		$global_hero_cta = $this->cta_has_content( $this->get_global_hero_cta() );
		$global_sidebar_cta = $this->cta_has_content( $this->get_global_sidebar_cta() );
		$global_wide_cta = $this->cta_has_content( $this->get_global_wide_cta() );
		$hero_cta_keys = [
			'sp_hero_primary_label',
			'sp_hero_primary_url',
			'sp_hero_secondary_label',
			'sp_hero_secondary_url',
		];
		$sidebar_cta_keys = [
			'sp_sidebar_title',
			'sp_sidebar_copy',
			'sp_sidebar_primary_label',
			'sp_sidebar_primary_url',
			'sp_sidebar_secondary_label',
			'sp_sidebar_secondary_url',
		];
		$wide_cta_keys = [
			'sp_cta_title',
			'sp_cta_bullets',
			'sp_cta_button_label',
			'sp_cta_button_url',
			'sp_cta_more_text',
			'sp_cta_more_url',
		];
		$descriptions = $this->get_meta_descriptions();

		foreach ( $this->get_meta_definitions() as $key => $definition ) {
			$description = isset( $descriptions[ $key ] ) ? (string) $descriptions[ $key ] : '';
			$show_in_rest = $definition['show_in_rest'];

			if ( $global_hero_cta && \in_array( $key, $hero_cta_keys, true ) ) {
				$show_in_rest = false;
			}
			if ( $global_sidebar_cta && \in_array( $key, $sidebar_cta_keys, true ) ) {
				$show_in_rest = false;
			}
			if ( $global_wide_cta && \in_array( $key, $wide_cta_keys, true ) ) {
				$show_in_rest = false;
			}

			if ( true === $show_in_rest ) {
				$show_in_rest = [
					'schema' => [
						'type' => $definition['type'],
					],
				];
			}

			if ( \is_array( $show_in_rest ) && isset( $show_in_rest['schema'] ) && '' !== $description ) {
				$show_in_rest['schema']['description'] = $description;
			}

			$args = [
				'type'              => $definition['type'],
				'single'            => true,
				'show_in_rest'      => $show_in_rest,
				'auth_callback'     => '__return_true',
				'sanitize_callback' => $definition['sanitize_callback'],
			];

			if ( '' !== $description ) {
				$args['description'] = $description;
			}

			\register_post_meta( self::CPT, $key, $args );
		}
	}

	/**
	 * Meta field schema.
	 */
	private function get_meta_definitions(): array {
		return [
			'sp_hero_eyebrow'         => $this->string_meta(),
			'sp_hero_title'           => $this->string_meta(),
			'sp_hero_copy'            => $this->rich_text_meta(),
			'sp_hero_primary_label'   => $this->string_meta(),
			'sp_hero_primary_url'     => $this->url_meta(),
			'sp_hero_secondary_label' => $this->string_meta(),
			'sp_hero_secondary_url'   => $this->url_meta(),
			'sp_intro'                => $this->rich_text_meta(),
			'sp_main_1'               => $this->rich_text_meta(),
			'sp_main_2'               => $this->rich_text_meta(),
			'sp_main_3'               => $this->rich_text_meta(),
			'sp_table'                => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_table' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
					],
				],
			],
			'sp_image_1'              => $this->media_meta(),
			'sp_image_2'              => $this->media_meta(),
			'sp_sidebar_title'        => $this->string_meta(),
			'sp_sidebar_copy'         => $this->rich_text_meta(),
			'sp_sidebar_primary_label'=> $this->string_meta(),
			'sp_sidebar_primary_url'  => $this->url_meta(),
			'sp_sidebar_secondary_label' => $this->string_meta(),
			'sp_sidebar_secondary_url'=> $this->url_meta(),
			'sp_cta_title'            => $this->string_meta(),
			'sp_cta_bullets'          => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_string_array' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
					],
				],
			],
			'sp_cta_button_label'     => $this->string_meta(),
			'sp_cta_button_url'       => $this->url_meta(),
			'sp_cta_more_text'        => $this->string_meta(),
			'sp_cta_more_url'         => $this->url_meta(),
			'sp_extra_copy'           => $this->rich_text_meta(),
			'sp_tab_1_title'          => $this->string_meta(),
			'sp_tab_1_content'        => $this->rich_text_meta(),
			'sp_tab_2_title'          => $this->string_meta(),
			'sp_tab_2_content'        => $this->rich_text_meta(),
			'sp_tab_3_title'          => $this->string_meta(),
			'sp_tab_3_content'        => $this->rich_text_meta(),
			'sp_faq'                  => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_faq' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'question' => [ 'type' => 'string' ],
								'answer'   => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
			'sp_related_posts'        => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_post_ids' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
				],
			],
		];
	}

	private function get_meta_descriptions( string $template_slug = '' ): array {
		$descriptions = [
			'sp_hero_eyebrow'            => 'Optional small eyebrow text above the hero title.',
			'sp_hero_title'              => 'Hero H1 title. If empty, the post title is used.',
			'sp_hero_copy'               => 'Short hero intro rich text under the title.',
			'sp_hero_primary_label'      => 'Hero primary CTA label.',
			'sp_hero_primary_url'        => 'Hero primary CTA URL.',
			'sp_hero_secondary_label'    => 'Hero secondary CTA label.',
			'sp_hero_secondary_url'      => 'Hero secondary CTA URL.',
			'sp_intro'                   => 'Full intro rich text shown below the hero section. Can hold multiple paragraphs and headings.',
			'sp_main_1'                  => 'Section 1 rich text.',
			'sp_main_2'                  => 'Section 2 rich text.',
			'sp_main_3'                  => 'Section 3 rich text.',
			'sp_table'                   => 'Table rows. First row is headers; each row is an array of cell strings. If you Example: [["Column 1","Column 2"],["Value 1","Value 2"]].',
			'sp_image_1'                 => 'Attachment ID for the first content image.',
			'sp_image_2'                 => 'Attachment ID for the second content image.',
			'sp_sidebar_title'           => 'Sidebar CTA title.',
			'sp_sidebar_copy'            => 'Sidebar CTA rich text.',
			'sp_sidebar_primary_label'   => 'Sidebar CTA primary button label.',
			'sp_sidebar_primary_url'     => 'Sidebar CTA primary button URL.',
			'sp_sidebar_secondary_label' => 'Sidebar CTA secondary link label.',
			'sp_sidebar_secondary_url'   => 'Sidebar CTA secondary link URL.',
			'sp_cta_title'               => 'Wide CTA title.',
			'sp_cta_bullets'             => 'Wide CTA bullet list (array of strings).',
			'sp_cta_button_label'        => 'Wide CTA primary button label.',
			'sp_cta_button_url'          => 'Wide CTA primary button URL.',
			'sp_cta_more_text'           => 'Wide CTA secondary link label.',
			'sp_cta_more_url'            => 'Wide CTA secondary link URL.',
			'sp_extra_copy'              => 'Extra section rich text after the full width CTA heading. Shown above the sp_table. This rich text can hold multiple headers and paragraphs - no limit.',
			'sp_tab_1_title'             => 'Optional tab 1 label.',
			'sp_tab_1_content'           => 'Optional tab 1 rich text content.',
			'sp_tab_2_title'             => 'Optional tab 2 label.',
			'sp_tab_2_content'           => 'Optional tab 2 rich text content.',
			'sp_tab_3_title'             => 'Optional tab 3 label.',
			'sp_tab_3_content'           => 'Optional tab 3 rich text content.',
			'sp_faq'                     => 'FAQ items. Array of objects with question and answer (answer supports rich text).',
			'sp_related_posts'           => 'Optional related post IDs. If empty, the related section is hidden.',
		];

		if ( 'service-page-3' === $template_slug ) {
			$descriptions['sp_main_1'] = 'Section 1 rich text. One full row, next to a sidebar CTA. Max 300 words.';
			$descriptions['sp_main_2'] = 'Section 2 rich text. Half row, below section 1. Max 100 words, can be used to expand on section 1 content too. Tables and lists take more space, so even less words allowed.';
			$descriptions['sp_main_3'] = 'Section 3 rich text. Half row, next to section 2. Max 100 words, can be used to expand on section 1 content too. Tables and lists take more space, so even less words allowed.';
		}

		return $descriptions;
	}

	private function string_meta(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
		];
	}

	private function long_text_meta(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'show_in_rest'      => true,
		];
	}

	private function rich_text_meta(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_rich_text' ],
			'show_in_rest'      => true,
		];
	}

	private function sanitize_rich_text( $value ): string {
		return \wp_kses_post( (string) $value );
	}

	private function url_meta(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'show_in_rest'      => true,
		];
	}

	private function media_meta(): array {
		return [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'show_in_rest'      => true,
		];
	}

	public static function sanitize_string_array( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $item ) {
			$item = \sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return $clean;
	}

	public static function sanitize_table( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$table = [];

		foreach ( $value as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			$table[] = array_map(
				static function ( $cell ) {
					return \sanitize_text_field( (string) $cell );
				},
				$row
			);
		}

		return $table;
	}

	public static function sanitize_post_ids( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $item ) {
			$item = \absint( $item );
			if ( $item > 0 ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	public static function sanitize_faq( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $item ) {
			if ( ! \is_array( $item ) ) {
				continue;
			}

			$question = \sanitize_text_field( (string) ( $item['question'] ?? '' ) );
			$answer   = \wp_kses_post( (string) ( $item['answer'] ?? '' ) );

			if ( '' === $question && '' === $answer ) {
				continue;
			}

			$clean[] = [
				'question' => $question,
				'answer'   => $answer,
			];
		}

		return $clean;
	}

	private function table_has_content( array $table ): bool {
		foreach ( $table as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			foreach ( $row as $cell ) {
				if ( '' !== \trim( (string) $cell ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function format_table_for_editor( array $table ): string {
		$lines = [];

		foreach ( $table as $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			$cells = array_map(
				static function ( $cell ) {
					return \trim( (string) $cell );
				},
				$row
			);

			$lines[] = implode( ' | ', $cells );
		}

		return \trim( implode( "\n", $lines ) );
	}

	private function parse_table_input( string $raw ): array {
		$rows = preg_split( '/\r\n|\r|\n/', $raw );

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$table = [];

		foreach ( $rows as $row ) {
			$row = trim( (string) $row );

			if ( '' === $row ) {
				continue;
			}

			$cells   = array_map( 'trim', explode( '|', $row ) );
			$table[] = $cells;
		}

		return $table;
	}

	/**
	 * Registers the dynamic Gutenberg block used for this CPT.
	 */
	public function register_block(): void {
		$this->register_assets();
		$metadata = __DIR__ . '/block.json';

		if ( \function_exists( 'register_block_type_from_metadata' ) && \file_exists( $metadata ) ) {
			\register_block_type_from_metadata(
				$metadata,
				[
					'render_callback' => [ $this, 'render_block' ],
				]
			);

			return;
		}

		\register_block_type(
			'service-cpt/layout',
			[
				'render_callback' => [ $this, 'render_block' ],
				'api_version'     => 2,
				'supports'        => [
					'html'     => false,
					'inserter' => false,
				],
				'style'         => 'service-cpt-frontend',
				'editor_style'  => 'service-cpt-editor',
				'editor_script' => 'service-cpt-block',
			]
		);
	}

	/**
	 * Registers the service page block patterns.
	 */
	public function register_block_patterns(): void {
		if ( ! \function_exists( 'register_block_pattern' ) ) {
			return;
		}

		if ( \function_exists( 'register_block_pattern_category' ) ) {
			\register_block_pattern_category(
				'service-pages',
				[
					'label' => __( 'Service Pages', 'nova-bridge-suite' ),
				]
			);
		}

		foreach ( $this->get_templates() as $slug => $template ) {
			$content = $this->get_template_content( $slug );

			if ( '' === $content ) {
				continue;
			}

			\register_block_pattern(
				'service-cpt/' . $slug,
				[
					'title'      => $template['label'],
					'content'    => $content,
					'categories' => [ 'service-pages' ],
				]
			);
		}
	}

	/**
	 * Render callback for the dynamic block.
	 */
	public function render_block(): string {
		$post = \get_post();

		if ( ! $post || self::CPT !== $post->post_type ) {
			return '';
		}

		return $this->render_legacy_layout( $post->ID );
	}

	/**
	 * Enqueues front-end assets.
	 */
	public function enqueue_front_assets(): void {
		if ( ! $this->is_service_context() ) {
			return;
		}

		\wp_enqueue_style( 'service-cpt-frontend' );
		$this->enqueue_exclusion_styles( 'service-cpt-frontend' );
	}

	/**
	 * Enqueues editor assets.
	 */
	public function enqueue_editor_assets(): void {
		$screen = \get_current_screen();

		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}

		$post_id = 0;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post'] ) ) {
			$post_id = \absint( wp_unslash( $_GET['post'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			$post = \get_post();
			if ( $post && self::CPT === $post->post_type ) {
				$post_id = (int) $post->ID;
			}
		}
		$template_slug = $post_id ? $this->get_effective_template_slug( $post_id ) : $this->get_selected_template_slug();
		$component_flags = [
			'hero'       => $this->template_component_active( $template_slug, 'hero' ),
			'intro'      => $this->template_component_active( $template_slug, 'intro' ),
			'content'    => $this->template_component_active( $template_slug, 'content' )
				|| $this->template_component_active( $template_slug, 'image_text' )
				|| $this->template_component_active( $template_slug, 'text_image' ),
			'table'      => 'service-page-3' === $template_slug
				&& $this->template_component_active( $template_slug, 'content' ),
			'images'     => $this->template_component_active( $template_slug, 'image_text' )
				|| $this->template_component_active( $template_slug, 'text_image' ),
			'sidebarCta' => $this->template_component_active( $template_slug, 'cta_cover' ),
			'wideCta'    => $this->template_component_active( $template_slug, 'cta_wide' ),
			'faq'        => $this->template_component_active( $template_slug, 'faq' ),
		];
		$editor_payload = [
			'components'          => $component_flags,
			'showHeroCtaFields'   => ! $this->cta_has_content( $this->get_global_hero_cta() ),
			'showSidebarCtaFields'=> ! $this->cta_has_content( $this->get_global_sidebar_cta() ),
			'showWideCtaFields'   => ! $this->cta_has_content( $this->get_global_wide_cta() ),
		];

		\wp_enqueue_style( 'service-cpt-editor' );
		\wp_enqueue_script( 'service-cpt-block' );
		\wp_enqueue_script( 'service-cpt-sidebar' );
		\wp_localize_script( 'service-cpt-sidebar', 'serviceCptSidebar', $editor_payload );
		\wp_localize_script( 'service-cpt-block', 'serviceCptBlock', $editor_payload );
	}

	/**
	 * Admin-only assets for the meta box (media picker).
	 */
	public function enqueue_admin_assets( string $hook ): void {
		if ( 'post.php' === $hook || 'post-new.php' === $hook ) {
			$screen = \get_current_screen();

			if ( $screen && self::CPT === $screen->post_type ) {
				\wp_enqueue_media();
				\wp_enqueue_script( 'service-cpt-admin' );
				\wp_enqueue_style( 'service-cpt-admin-meta' );
			}

			return;
		}

		if ( 'settings_page_service-cpt' === $hook || 'service_page_page_service-cpt' === $hook ) {
			\wp_enqueue_media();
			\wp_enqueue_script( 'service-cpt-admin' );
			\wp_enqueue_script( 'service-cpt-settings' );
			\wp_enqueue_style( 'service-cpt-admin-settings' );
			\wp_localize_script( 'service-cpt-settings', 'serviceCptSettings', $this->get_settings_payload() );
		}
	}

	/**
	 * Registers shared block assets.
	 */
	private function register_assets(): void {
		$version = function ( string $relative ): string {
			$path = __DIR__ . '/' . ltrim( $relative, '/' );

			return \is_file( $path ) ? (string) \filemtime( $path ) : '0.1.0';
		};

		\wp_register_style(
			'service-cpt-frontend',
			\plugins_url( 'assets/frontend.css', __FILE__ ),
			[],
			$version( 'assets/frontend.css' )
		);

		\wp_register_style(
			'service-cpt-editor',
			\plugins_url( 'assets/block.css', __FILE__ ),
			[ 'wp-edit-blocks' ],
			$version( 'assets/block.css' )
		);

		\wp_register_style(
			'service-cpt-admin-meta',
			\plugins_url( 'assets/admin-meta.css', __FILE__ ),
			[],
			$version( 'assets/admin-meta.css' )
		);

		\wp_register_style(
			'service-cpt-admin-settings',
			\plugins_url( 'assets/admin-settings.css', __FILE__ ),
			[],
			$version( 'assets/admin-settings.css' )
		);

		\wp_register_script(
			'service-cpt-block',
			\plugins_url( 'assets/block.js', __FILE__ ),
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-data', 'wp-i18n' ],
			$version( 'assets/block.js' ),
			true
		);

		\wp_register_script(
			'service-cpt-sidebar',
			\plugins_url( 'assets/sidebar.js', __FILE__ ),
			[ 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-block-editor' ],
			$version( 'assets/sidebar.js' ),
			true
		);

		\wp_register_script(
			'service-cpt-admin',
			\plugins_url( 'assets/admin.js', __FILE__ ),
			[ 'jquery', 'media-editor' ],
			$version( 'assets/admin.js' ),
			true
		);

		\wp_register_script(
			'service-cpt-settings',
			\plugins_url( 'assets/settings.js', __FILE__ ),
			[],
			$version( 'assets/settings.js' ),
			true
		);
	}

	/**
	 * Forces plugin templates for this CPT.
	 */
	public function maybe_use_templates( string $template ): string {
		if ( \is_singular( self::CPT ) ) {
			$path = __DIR__ . '/templates/single-' . self::CPT . '.php';
			if ( \file_exists( $path ) ) {
				return $path;
			}
		}

		if ( \is_post_type_archive( self::CPT ) ) {
			$path = __DIR__ . '/templates/archive-' . self::CPT . '.php';
			if ( \file_exists( $path ) ) {
				return $path;
			}
		}

		return $template;
	}

	public function add_body_class( array $classes ): array {
		if ( $this->is_service_context() ) {
			$classes[] = 'service-cpt';
		}

		return $classes;
	}

	private function is_service_context(): bool {
		return \is_singular( self::CPT ) || \is_post_type_archive( self::CPT );
	}

	public function title_placeholder( string $placeholder, \WP_Post $post ): string {
		if ( self::CPT === $post->post_type ) {
			return __( 'Service name', 'nova-bridge-suite' );
		}

		return $placeholder;
	}

	/**
	 * Disables the block editor for this CPT to keep edits in the classic admin screen.
	 */
	public function force_block_editor( bool $can_edit, string $post_type ): bool {
		if ( self::CPT === $post_type ) {
			return false;
		}

		return $can_edit;
	}

	/**
	 * Ensures the service block stays allowed even when block lists are restricted.
	 */
	public function ensure_block_allowed( $allowed, $editor_context ) {
		if ( empty( $editor_context->post ) || self::CPT !== $editor_context->post->post_type ) {
			return $allowed;
		}

		// If everything is allowed, keep it that way.
		if ( true === $allowed ) {
			return $allowed;
		}

		$required_blocks = [
			'service-cpt/layout',
			'essential-blocks/advanced-tabs',
			'essential-blocks/tab',
			'faq-block-for-gutenberg/faq',
		];

		if ( ! is_array( $allowed ) ) {
			$allowed = [];
		}

		foreach ( $required_blocks as $block ) {
			if ( ! in_array( $block, $allowed, true ) ) {
				$allowed[] = $block;
			}
		}

		return $allowed;
	}

	public function lock_block_editor( array $settings, $context ): array {
		if ( empty( $context->post ) || self::CPT !== $context->post->post_type ) {
			return $settings;
		}

		if ( ! $this->dependencies_ready() ) {
			return $settings;
		}

		$template_slug = $this->get_effective_template_slug( (int) $context->post->ID );
		$template_content = $this->get_template_content( $template_slug );

		if ( '' !== $template_content ) {
			$settings['template'] = $this->build_template_from_blocks( \parse_blocks( $template_content ) );
			$settings['templateLock'] = 'all';
		}

		return $settings;
	}

	public function normalize_block_data( array $parsed_block, array $source_block, $parent_block = null ): array {
		if ( ! $this->rendering_service_page ) {
			return $parsed_block;
		}

		$component = $this->get_component_slug_for_block( $parsed_block, $parent_block );
		$attrs = $parsed_block['attrs'] ?? [];

		if ( ! $component && $parent_block instanceof \WP_Block ) {
			$parent_attrs = $parent_block->parsed_block['attrs'] ?? [];
			$parent_component = $parent_attrs['serviceCptComponent'] ?? '';

			if ( ! \is_string( $parent_component ) || '' === $parent_component ) {
				$parent_classes = isset( $parent_attrs['className'] ) ? (string) $parent_attrs['className'] : '';
				if ( '' !== $parent_classes && preg_match( '/service-cpt-component--([a-z0-9_-]+)/', $parent_classes, $match ) ) {
					$parent_component = $match[1];
				}
			}

			if ( ( ! \is_string( $parent_component ) || '' === $parent_component ) && isset( $parent_attrs['metadata']['name'] ) ) {
				$parent_component = $this->get_component_slug_for_block( $parent_block->parsed_block ?? [] );
			}

			if ( \is_string( $parent_component ) && '' !== $parent_component ) {
				$component = $parent_component;
			}
		}

		if ( ! $component ) {
			return $parsed_block;
		}

		$attrs['serviceCptComponent'] = $component;
		$class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';
		$classes = preg_split( '/\s+/', $class_name ) ?: [];
		$classes[] = 'service-cpt-component';
		$classes[] = 'service-cpt-component--' . $component;
		$classes = array_values( array_unique( array_filter( $classes ) ) );
		$attrs['className'] = implode( ' ', $classes );
		$parsed_block['attrs'] = $attrs;

		return $parsed_block;
	}

	private function add_component_classes_to_blocks( array $blocks ): array {
		$updated = [];

		foreach ( $blocks as $block ) {
			if ( ! \is_array( $block ) ) {
				$updated[] = $block;
				continue;
			}

			$updated[] = $this->add_component_classes_to_block( $block, null );
		}

		return $updated;
	}

	private function add_component_classes_to_block( array $block, ?array $parent_block ): array {
		$component = $this->get_component_slug_for_block( $block, $parent_block );

		if ( ! $component && \is_array( $parent_block ) ) {
			$parent_attrs = $parent_block['attrs'] ?? [];
			$parent_component = $parent_attrs['serviceCptComponent'] ?? '';

			if ( ! \is_string( $parent_component ) || '' === $parent_component ) {
				$parent_classes = isset( $parent_attrs['className'] ) ? (string) $parent_attrs['className'] : '';
				if ( '' !== $parent_classes && preg_match( '/service-cpt-component--([a-z0-9_-]+)/', $parent_classes, $match ) ) {
					$parent_component = $match[1];
				}
			}

			if ( ( ! \is_string( $parent_component ) || '' === $parent_component ) && isset( $parent_attrs['metadata']['name'] ) ) {
				$parent_component = $this->get_component_slug_for_block( $parent_block );
			}

			if ( \is_string( $parent_component ) && '' !== $parent_component ) {
				$component = $parent_component;
			}
		}

		if ( $component ) {
			$attrs = $block['attrs'] ?? [];
			$attrs['serviceCptComponent'] = $component;
			$class_name = isset( $attrs['className'] ) ? (string) $attrs['className'] : '';
			$classes = preg_split( '/\s+/', $class_name ) ?: [];
			$classes[] = 'service-cpt-component';
			$classes[] = 'service-cpt-component--' . $component;
			$classes = array_values( array_unique( array_filter( $classes ) ) );
			$attrs['className'] = implode( ' ', $classes );
			$block['attrs'] = $attrs;
		}

		if ( isset( $block['innerBlocks'] ) && \is_array( $block['innerBlocks'] ) ) {
			$inner_blocks = [];

			foreach ( $block['innerBlocks'] as $inner_block ) {
				if ( ! \is_array( $inner_block ) ) {
					$inner_blocks[] = $inner_block;
					continue;
				}

				$inner_blocks[] = $this->add_component_classes_to_block( $inner_block, $block );
			}

			$block['innerBlocks'] = $inner_blocks;
		}

		return $block;
	}

	public function filter_rendered_block( string $block_content, array $block ): string {
		if ( ! $this->rendering_service_page || 0 === $this->current_service_post_id ) {
			return $block_content;
		}

		$block_content = $this->apply_meta_overrides( $block_content, $block );

		$component = $this->get_component_slug_for_block( $block );
		$block_name = $block['blockName'] ?? '';

		if ( ! $component ) {
			return $block_content;
		}

		$template_slug = $this->get_effective_template_slug( $this->current_service_post_id );

		if ( ! $this->component_enabled_for_template( $component, $template_slug ) ) {
			return '';
		}

		$block_content = $this->inject_component_class_into_html( $block_content, $component );

		if ( 'related' === $component ) {
			$related_posts = $this->get_related_post_ids();
			if ( empty( $related_posts ) ) {
				return '';
			}

			if ( 'core/latest-posts' === $block_name ) {
				return $this->render_related_posts_block( $related_posts, $block['attrs'] ?? [] );
			}
		}

		if ( 'essential-blocks/advanced-tabs' === $block_name ) {
			$block_content = $this->replace_advanced_tab_titles( $block_content );
			if ( '' === $block_content ) {
				return '';
			}
			$block_content = $this->prepend_eb_tab_styles( $block_content, $block );
		}

		return $block_content;
	}

	private function inject_component_class_into_html( string $block_content, string $component ): string {
		if ( '' === $block_content ) {
			return $block_content;
		}

		$needle = 'service-cpt-component--' . $component;
		if ( false !== strpos( $block_content, $needle ) ) {
			return $block_content;
		}

		if ( ! preg_match( '/<([a-zA-Z0-9:-]+)([^>]*)>/', $block_content, $match, PREG_OFFSET_CAPTURE ) ) {
			return $block_content;
		}

		$full_tag = $match[0][0];
		$offset   = $match[0][1];

		if ( preg_match( '/^<\\s*!/i', $full_tag ) ) {
			return $block_content;
		}

		if ( preg_match( '/\\bclass\\s*=\\s*([\\\"\\\'])([^\\\"\\\']*)\\1/i', $full_tag, $class_match ) ) {
			$existing = preg_split( '/\\s+/', $class_match[2] ) ?: [];
			$existing[] = 'service-cpt-component';
			$existing[] = 'service-cpt-component--' . $component;
			$existing = array_values( array_unique( array_filter( $existing ) ) );
			$replacement = 'class=' . $class_match[1] . implode( ' ', $existing ) . $class_match[1];
			$new_tag = preg_replace( '/\\bclass\\s*=\\s*([\\\"\\\'])([^\\\"\\\']*)\\1/i', $replacement, $full_tag, 1 );
		} else {
			$insert = ' class="service-cpt-component service-cpt-component--' . $component . '"';
			if ( '/>' === substr( $full_tag, -2 ) ) {
				$new_tag = substr( $full_tag, 0, -2 ) . $insert . ' />';
			} else {
				$new_tag = substr( $full_tag, 0, -1 ) . $insert . '>';
			}
		}

		return substr( $block_content, 0, $offset ) . $new_tag . substr( $block_content, $offset + strlen( $full_tag ) );
	}

	private function prepend_eb_tab_styles( string $block_content, array $block ): string {
		if ( '' === $block_content ) {
			return $block_content;
		}

		$styles = $this->build_eb_tab_styles( $block );

		if ( '' === $styles ) {
			return $block_content;
		}

		$this->queue_inline_style( $styles );

		return $block_content;
	}

	private function replace_advanced_tab_titles( string $block_content ): string {
		if ( '' === $block_content ) {
			return $block_content;
		}

		$block_content = (string) preg_replace(
			'/<h6([^>]*)class=[\"\']([^\"\']*tab-title-text[^\"\']*)[\"\']([^>]*)>(.*?)<\\/h6>/is',
			'<span$1class="$2"$3>$4</span>',
			$block_content
		);

		$filled_tabs = $this->get_filled_tab_ids();
		if ( empty( $filled_tabs ) ) {
			return '';
		}

		$tab_values = $this->get_tab_values();

		$doc = new \DOMDocument( '1.0', 'UTF-8' );
		$previous = \libxml_use_internal_errors( true );
		$doc->loadHTML( '<div id="service-cpt-tabs-root">' . $block_content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		\libxml_clear_errors();
		if ( false !== $previous ) {
			\libxml_use_internal_errors( $previous );
		}

		$xpath = new \DOMXPath( $doc );

		foreach ( $tab_values as $tab_id => $values ) {
			$is_filled = \in_array( $tab_id, $filled_tabs, true );

			if ( ! $is_filled ) {
				foreach ( $xpath->query( "//li[@data-title-tab-id='{$tab_id}']" ) as $node ) {
					$node->parentNode->removeChild( $node );
				}
				foreach ( $xpath->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' eb-tab-wrapper ') and @data-tab-id='{$tab_id}']" ) as $node ) {
					$node->parentNode->removeChild( $node );
				}
				continue;
			}

			$title = $values['title'] ?? '';
			if ( '' === \trim( (string) $title ) ) {
				continue;
			}

			foreach ( $xpath->query( "//li[@data-title-tab-id='{$tab_id}']//*[contains(concat(' ', normalize-space(@class), ' '), ' tab-title-text ')]" ) as $node ) {
				$node->nodeValue = (string) $title;
			}
		}

		$tab_nodes = $xpath->query( "//li[@data-title-tab-id]" );
		if ( 0 === $tab_nodes->length ) {
			return '';
		}

		$active_nodes = $xpath->query( "//li[contains(concat(' ', normalize-space(@class), ' '), ' active ')]" );
		if ( 0 === $active_nodes->length ) {
			$first = $tab_nodes->item( 0 );
			if ( $first ) {
				$classes = trim( $first->getAttribute( 'class' ) . ' active' );
				$classes = preg_replace( '/\\binactive\\b/', '', $classes );
				$classes = preg_replace( '/\\s+/', ' ', trim( (string) $classes ) );
				$first->setAttribute( 'class', $classes );
			}
		}

		$container = $doc->getElementById( 'service-cpt-tabs-root' );
		if ( ! $container ) {
			return $block_content;
		}

		$updated = '';
		foreach ( $container->childNodes as $child ) {
			$updated .= $doc->saveHTML( $child );
		}

		return $updated;
	}

	private function tabs_have_content(): bool {
		return ! empty( $this->get_filled_tab_ids() );
	}

	private function get_tab_values(): array {
		return [
			1 => [
				'title'   => $this->current_service_meta['sp_tab_1_title'] ?? '',
				'content' => $this->current_service_meta['sp_tab_1_content'] ?? '',
			],
			2 => [
				'title'   => $this->current_service_meta['sp_tab_2_title'] ?? '',
				'content' => $this->current_service_meta['sp_tab_2_content'] ?? '',
			],
			3 => [
				'title'   => $this->current_service_meta['sp_tab_3_title'] ?? '',
				'content' => $this->current_service_meta['sp_tab_3_content'] ?? '',
			],
		];
	}

	private function get_filled_tab_ids(): array {
		$filled = [];

		foreach ( $this->get_tab_values() as $tab_id => $values ) {
			$has_title = $this->value_has_text( $values['title'] ?? '' );
			$has_content = $this->value_has_text( $values['content'] ?? '' );
			if ( $has_title && $has_content ) {
				$filled[] = $tab_id;
			}
		}

		return $filled;
	}

	private function value_has_text( $value ): bool {
		$text = (string) $value;
		if ( '' === \trim( $text ) ) {
			return false;
		}

		$text = \html_entity_decode( $text, ENT_QUOTES, \get_option( 'blog_charset' ) );
		$text = \wp_strip_all_tags( $text );

		return '' !== \trim( $text );
	}

	private function get_related_post_ids(): array {
		$ids = $this->current_service_meta['sp_related_posts'] ?? [];

		if ( ! \is_array( $ids ) ) {
			return [];
		}

		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}

	private function render_related_posts_block( array $post_ids, array $attributes ): string {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );

		if ( empty( $post_ids ) ) {
			return '';
		}

		$args = [
			'posts_per_page'      => count( $post_ids ),
			'post_status'         => 'publish',
			'post_type'           => 'post',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'post__in'            => $post_ids,
			'orderby'             => 'post__in',
		];

		$query        = new \WP_Query();
		$recent_posts = $query->query( $args );

		if ( isset( $attributes['displayFeaturedImage'] ) && $attributes['displayFeaturedImage'] ) {
			\update_post_thumbnail_cache( $query );
		}

		$added_excerpt_filter = false;
		if ( isset( $attributes['excerptLength'] ) ) {
			$GLOBALS['block_core_latest_posts_excerpt_length'] = (int) $attributes['excerptLength']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
			if ( \function_exists( 'block_core_latest_posts_get_excerpt_length' ) ) {
				\add_filter( 'excerpt_length', 'block_core_latest_posts_get_excerpt_length', 20 );
				$added_excerpt_filter = true;
			}
		}

		$list_items_markup = '';

		foreach ( $recent_posts as $post ) {
			$post_link = \esc_url( \get_permalink( $post ) );
			$title     = \get_the_title( $post );

			if ( ! $title ) {
				$title = \__( '(no title)', 'nova-bridge-suite' );
			}

			$list_items_markup .= '<li>';

			if ( ! empty( $attributes['displayFeaturedImage'] ) && \has_post_thumbnail( $post ) ) {
				$image_style = '';
				if ( isset( $attributes['featuredImageSizeWidth'] ) ) {
					$image_style .= sprintf( 'max-width:%spx;', $attributes['featuredImageSizeWidth'] );
				}
				if ( isset( $attributes['featuredImageSizeHeight'] ) ) {
					$image_style .= sprintf( 'max-height:%spx;', $attributes['featuredImageSizeHeight'] );
				}

				$image_classes = 'wp-block-latest-posts__featured-image';
				if ( isset( $attributes['featuredImageAlign'] ) ) {
					$image_classes .= ' align' . $attributes['featuredImageAlign'];
				}

				$featured_image = \get_the_post_thumbnail(
					$post,
					$attributes['featuredImageSizeSlug'] ?? 'thumbnail',
					[ 'style' => \esc_attr( $image_style ) ]
				);

				$list_items_markup .= sprintf(
					'<a class="%1$s" href="%2$s">%3$s</a>',
					\esc_attr( $image_classes ),
					$post_link,
					$featured_image
				);
			}

			$list_items_markup .= sprintf(
				'<a class="wp-block-latest-posts__post-title" href="%1$s">%2$s</a>',
				$post_link,
				\esc_html( $title )
			);

			if ( isset( $attributes['displayAuthor'] ) && $attributes['displayAuthor'] ) {
				$author = \get_the_author_meta( 'display_name', $post->post_author );
				$list_items_markup .= sprintf(
					'<div class="wp-block-latest-posts__post-author">%1$s</div>',
					\esc_html( $author )
				);
			}

			if ( isset( $attributes['displayPostDate'] ) && $attributes['displayPostDate'] ) {
				$list_items_markup .= sprintf(
					'<time datetime="%1$s" class="wp-block-latest-posts__post-date">%2$s</time>',
					\esc_attr( \get_the_date( 'c', $post ) ),
					\esc_html( \get_the_date( '', $post ) )
				);
			}

			if ( isset( $attributes['displayPostContent'] ) && $attributes['displayPostContent'] ) {
				if ( isset( $attributes['displayPostContentRadio'] ) && 'excerpt' === $attributes['displayPostContentRadio'] ) {
					$trimmed_excerpt = \get_the_excerpt( $post );
					if ( \post_password_required( $post ) ) {
						$trimmed_excerpt = \__( 'This content is password protected.', 'nova-bridge-suite' );
					}
					$list_items_markup .= sprintf(
						'<div class="wp-block-latest-posts__post-excerpt">%1$s</div>',
						\wp_kses_post( $trimmed_excerpt )
					);
				}

				if ( isset( $attributes['displayPostContentRadio'] ) && 'full_post' === $attributes['displayPostContentRadio'] ) {
					$post_content = \html_entity_decode( $post->post_content, ENT_QUOTES, \get_option( 'blog_charset' ) );
					if ( \post_password_required( $post ) ) {
						$post_content = \__( 'This content is password protected.', 'nova-bridge-suite' );
					}
					$list_items_markup .= sprintf(
						'<div class="wp-block-latest-posts__post-full-content">%1$s</div>',
						\wp_kses_post( $post_content )
					);
				}
			}

			$list_items_markup .= "</li>\n";
		}

		if ( $added_excerpt_filter && \function_exists( 'block_core_latest_posts_get_excerpt_length' ) ) {
			\remove_filter( 'excerpt_length', 'block_core_latest_posts_get_excerpt_length', 20 );
		}

		if ( '' === $list_items_markup ) {
			return '';
		}

		$classes = [ 'wp-block-latest-posts__list' ];
		if ( isset( $attributes['postLayout'] ) && 'grid' === $attributes['postLayout'] ) {
			$classes[] = 'is-grid';
		}
		if ( isset( $attributes['columns'] ) && 'grid' === ( $attributes['postLayout'] ?? '' ) ) {
			$classes[] = 'columns-' . (int) $attributes['columns'];
		}
		if ( ! empty( $attributes['displayPostDate'] ) ) {
			$classes[] = 'has-dates';
		}
		if ( ! empty( $attributes['displayAuthor'] ) ) {
			$classes[] = 'has-author';
		}
		if ( isset( $attributes['style']['elements']['link']['color']['text'] ) ) {
			$classes[] = 'has-link-color';
		}

		$wrapper_attributes = \function_exists( 'get_block_wrapper_attributes' )
			? \get_block_wrapper_attributes( [ 'class' => implode( ' ', $classes ) ] )
			: 'class="' . \esc_attr( implode( ' ', $classes ) ) . '"';

		return sprintf(
			'<ul %1$s>%2$s</ul>',
			$wrapper_attributes,
			$list_items_markup
		);
	}

	private function build_eb_tab_styles( array $block ): string {
		$attrs = $block['attrs'] ?? [];

		if ( ! \is_array( $attrs ) ) {
			return '';
		}

		$meta = $this->normalize_eb_style_payload( $attrs['blockMeta'] ?? [] );
		$common = $this->normalize_eb_style_payload( $attrs['commonStyles'] ?? [] );

		$styles = '';
		$styles .= $this->collect_eb_style_chunk( $common, 'desktop' );
		$styles .= $this->collect_eb_style_chunk( $meta, 'desktop' );
		$styles .= $this->collect_eb_style_media( $common, 'tab', 1024 );
		$styles .= $this->collect_eb_style_media( $meta, 'tab', 1024 );
		$styles .= $this->collect_eb_style_media( $common, 'mobile', 767 );
		$styles .= $this->collect_eb_style_media( $meta, 'mobile', 767 );

		$styles = \trim( $styles );

		if ( '' === $styles ) {
			return '';
		}

		$styles = \str_replace( '</style>', '', $styles );
		$styles = \wp_kses( $styles, [] );
		$hash = \md5( $styles );

		if ( isset( $this->inline_eb_tab_styles[ $hash ] ) ) {
			return '';
		}

		$this->inline_eb_tab_styles[ $hash ] = true;

		return $styles;
	}

	private function sanitize_inline_css( string $css ): string {
		$css = \wp_kses( $css, [] );
		return \trim( $css );
	}

	private function queue_inline_style( string $css ): void {
		$css = $this->sanitize_inline_css( $css );

		if ( '' === $css ) {
			return;
		}

		$hash = \md5( $css );

		if ( isset( $this->inline_style_queue[ $hash ] ) || isset( $this->late_inline_style_queue[ $hash ] ) ) {
			return;
		}

		if ( \did_action( 'wp_print_styles' ) || \did_action( 'admin_print_styles' ) ) {
			$this->late_inline_style_queue[ $hash ] = $css;

			if ( ! $this->late_inline_styles_hooked ) {
				$this->late_inline_styles_hooked = true;
				\add_action( 'wp_footer', [ $this, 'print_late_inline_styles' ], 1 );
				\add_action( 'admin_footer', [ $this, 'print_late_inline_styles' ], 1 );
			}

			return;
		}

		$this->inline_style_queue[ $hash ] = $css;
		$handle = 'service-cpt-inline';

		if ( ! \wp_style_is( $handle, 'registered' ) ) {
			\wp_register_style( $handle, false, [], null );
		}

		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, $css );
	}

	public function print_late_inline_styles(): void {
		if ( empty( $this->late_inline_style_queue ) ) {
			return;
		}

		$css = implode( "\n", $this->late_inline_style_queue );
		$this->late_inline_style_queue = [];

		$handle = 'service-cpt-inline-late';

		if ( ! \wp_style_is( $handle, 'registered' ) ) {
			\wp_register_style( $handle, false, [], null );
		}

		\wp_enqueue_style( $handle );
		\wp_add_inline_style( $handle, $css );
		\wp_print_styles( $handle );
	}

	private function normalize_eb_style_payload( $payload ): array {
		if ( \is_array( $payload ) ) {
			return $payload;
		}

		if ( \is_string( $payload ) && '' !== \trim( $payload ) ) {
			$decoded = \json_decode( $payload, true );
			if ( \is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return [];
	}

	private function collect_eb_style_chunk( array $payload, string $key ): string {
		$value = $payload[ $key ] ?? '';

		return \is_string( $value ) ? $value : '';
	}

	private function collect_eb_style_media( array $payload, string $key, int $max_width ): string {
		$value = $payload[ $key ] ?? '';

		if ( ! \is_string( $value ) || '' === \trim( $value ) ) {
			return '';
		}

		return sprintf( '@media (max-width: %dpx){%s}', $max_width, $value );
	}

	private function apply_meta_overrides( string $block_content, array $block ): string {
		$metadata = $block['attrs']['metadata']['name'] ?? '';
		$hero_cta = $this->current_hero_cta;
		$wide_cta = $this->current_wide_cta;
		$sidebar_cta = $this->current_sidebar_cta;
		$block_name = $block['blockName'] ?? '';

		if ( ! \is_string( $metadata ) || '' === $metadata ) {
			$metadata = '';
		}

		if ( 'core/heading' === $block_name ) {
			$text = \trim( \html_entity_decode( \wp_strip_all_tags( $block_content ), ENT_QUOTES, 'UTF-8' ) );
			if ( in_array( $text, [ 'H2 - wide + 2 columns', 'Mooooore content!!!', 'More content section', 'Final content section' ], true ) ) {
				return '';
			}
		}

		if ( '' !== $metadata ) {
			switch ( $metadata ) {
				case 'Hero eyebrow':
					return $this->replace_block_text( $block_content, $this->current_service_meta['sp_hero_eyebrow'] ?? '', [ 'p' ], true );
				case 'Hero title':
					$title = $this->current_service_meta['sp_hero_title'] ?? '';
					if ( '' === \trim( (string) $title ) ) {
						$title = $this->current_service_title;
					}
					return $this->replace_block_text( $block_content, $title, [ 'h1', 'h2', 'h3', 'h4' ], false );
				case 'Hero copy':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_hero_copy'] ?? '', [ 'p' ], true );
				case 'Hero primary button':
					return $this->replace_block_link(
						$block_content,
						$hero_cta['primary_label'] ?? '',
						$hero_cta['primary_url'] ?? '',
						true
					);
				case 'Hero secondary button':
					return $this->replace_block_link(
						$block_content,
						$hero_cta['secondary_label'] ?? '',
						$hero_cta['secondary_url'] ?? '',
						true
					);
				case 'Intro paragraph':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_intro'] ?? '', [ 'p' ], true );
				case 'CTA title':
					$this->cta_paragraph_index = max( $this->cta_paragraph_index, 1 );
					return $this->replace_block_text( $block_content, $wide_cta['title'] ?? '', [ 'p', 'h2', 'h3', 'h4' ], true );
				case 'CTA bullet 1':
					$this->cta_paragraph_index = max( $this->cta_paragraph_index, 2 );
					return $this->replace_block_text( $block_content, $this->get_cta_bullet_by_index( 0 ), [ 'p' ], true );
				case 'CTA bullet 2':
					$this->cta_paragraph_index = max( $this->cta_paragraph_index, 3 );
					return $this->replace_block_text( $block_content, $this->get_cta_bullet_by_index( 1 ), [ 'p' ], true );
				case 'CTA bullet 3':
					$this->cta_paragraph_index = max( $this->cta_paragraph_index, 4 );
					return $this->replace_block_text( $block_content, $this->get_cta_bullet_by_index( 2 ), [ 'p' ], true );
				case 'CTA button':
					$this->cta_button_index = max( $this->cta_button_index, 1 );
					return $this->replace_block_link(
						$block_content,
						$wide_cta['button_label'] ?? '',
						$wide_cta['button_url'] ?? '',
						true
					);
				case 'CTA more link':
					return $this->replace_block_link(
						$block_content,
						$wide_cta['more_text'] ?? '',
						$wide_cta['more_url'] ?? '',
						true
					);
				case 'Extra heading':
					return '';
				case 'Extra copy':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_extra_copy'] ?? '', [ 'p' ], true );
				case 'Tab 1 content':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_tab_1_content'] ?? '', [ 'p' ], true );
				case 'Tab 2 content':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_tab_2_content'] ?? '', [ 'p' ], true );
				case 'Tab 3 heading':
					return '';
				case 'Tab 3 content':
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_tab_3_content'] ?? '', [ 'p' ], true );
				case 'FAQ heading':
					return $this->replace_block_text( $block_content, $this->get_faq_heading_label(), [ 'h2', 'h3', 'h4', 'h5', 'h6' ], true );
				case 'Related heading':
					return $this->replace_block_heading( $block_content, $this->get_related_heading_label(), 3, true );
				case 'Sidebar title':
					$this->sidebar_heading_used = true;
					return $this->replace_block_text( $block_content, $sidebar_cta['title'] ?? '', [ 'h1', 'h2', 'h3', 'h4' ], true );
				case 'Sidebar copy':
					$this->sidebar_paragraph_used = true;
					return $this->replace_block_rich_text( $block_content, $sidebar_cta['copy'] ?? '', [ 'p' ], true );
				case 'Sidebar primary button':
					$this->sidebar_button_index = max( $this->sidebar_button_index, 1 );
					return $this->replace_block_link(
						$block_content,
						$sidebar_cta['primary_label'] ?? '',
						$sidebar_cta['primary_url'] ?? '',
						true
					);
				case 'Sidebar secondary button':
					$this->sidebar_button_index = max( $this->sidebar_button_index, 2 );
					return $this->replace_block_link(
						$block_content,
						$sidebar_cta['secondary_label'] ?? '',
						$sidebar_cta['secondary_url'] ?? '',
						true
					);
				case 'Main content 1':
					$this->content_paragraph_index = max( $this->content_paragraph_index, 1 );
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_main_1'] ?? '', [ 'p' ], true );
				case 'Main content 2':
					$this->content_paragraph_index = max( $this->content_paragraph_index, 2 );
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_main_2'] ?? '', [ 'p' ], true );
				case 'Main content 3':
					$this->content_paragraph_index = max( $this->content_paragraph_index, 3 );
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_main_3'] ?? '', [ 'p' ], true );
				case 'Main image 1':
					$this->content_image_index = max( $this->content_image_index, 1 );
					return $this->replace_block_image( $block_content, $this->format_media( (int) ( $this->current_service_meta['sp_image_1'] ?? 0 ) ), true );
				case 'Main image 2':
					$this->content_image_index = max( $this->content_image_index, 2 );
					return $this->replace_block_image( $block_content, $this->format_media( (int) ( $this->current_service_meta['sp_image_2'] ?? 0 ) ), true );
				case 'Main table':
					$this->table_used = true;
					return $this->replace_block_table( $block_content, $this->current_service_meta['sp_table'] ?? [] );
			}
		}

		$component = $block['attrs']['serviceCptComponent'] ?? '';
		$block_name = $block['blockName'] ?? '';

		if ( 'hero' === $component ) {
			if ( 'core/paragraph' === $block_name ) {
				$index = $this->hero_paragraph_index;
				$this->hero_paragraph_index++;

				if ( 0 === $index ) {
					return $this->replace_block_text( $block_content, $this->current_service_meta['sp_hero_eyebrow'] ?? '', [ 'p' ], true );
				}

				if ( 1 === $index ) {
					return $this->replace_block_rich_text( $block_content, $this->current_service_meta['sp_hero_copy'] ?? '', [ 'p' ], true );
				}
			}

			if ( 'core/heading' === $block_name && ! $this->hero_heading_used ) {
				$this->hero_heading_used = true;
				$title = $this->current_service_meta['sp_hero_title'] ?? '';
				if ( '' === \trim( (string) $title ) ) {
					$title = $this->current_service_title;
				}
				return $this->replace_block_text( $block_content, $title, [ 'h1', 'h2', 'h3', 'h4' ], false );
			}

			if ( 'core/button' === $block_name ) {
				$index = $this->hero_button_index;
				$this->hero_button_index++;

				if ( 0 === $index ) {
					return $this->replace_block_link(
						$block_content,
						$hero_cta['primary_label'] ?? '',
						$hero_cta['primary_url'] ?? '',
						true
					);
				}

				if ( 1 === $index ) {
					return $this->replace_block_link(
						$block_content,
						$hero_cta['secondary_label'] ?? '',
						$hero_cta['secondary_url'] ?? '',
						true
					);
				}
			}
		}

		if ( in_array( $component, [ 'cta_wide', 'cta_card' ], true ) ) {
			if ( 'core/paragraph' === $block_name ) {
				if ( false !== stripos( $block_content, '<a' ) ) {
					return $this->replace_block_link(
						$block_content,
						$wide_cta['more_text'] ?? '',
						$wide_cta['more_url'] ?? '',
						true
					);
				}

				$index = $this->cta_paragraph_index;
				$this->cta_paragraph_index++;

				if ( 0 === $index ) {
					return $this->replace_block_text( $block_content, $wide_cta['title'] ?? '', [ 'p', 'h2', 'h3', 'h4' ], true );
				}

				$bullet_index = $index - 1;
				if ( $bullet_index >= 0 && $bullet_index <= 2 ) {
					return $this->replace_block_text( $block_content, $this->get_cta_bullet_by_index( $bullet_index ), [ 'p' ], true );
				}

				return '';
			}

			if ( 'core/button' === $block_name ) {
				$index = $this->cta_button_index;
				$this->cta_button_index++;

				if ( 0 === $index ) {
					return $this->replace_block_link(
						$block_content,
						$wide_cta['button_label'] ?? '',
						$wide_cta['button_url'] ?? '',
						true
					);
				}

				return '';
			}
		}

		if ( 'cta_cover' === $component ) {
			if ( 'core/heading' === $block_name && ! $this->sidebar_heading_used ) {
				$this->sidebar_heading_used = true;
				return $this->replace_block_text( $block_content, $sidebar_cta['title'] ?? '', [ 'h1', 'h2', 'h3', 'h4' ], true );
			}

			if ( 'core/paragraph' === $block_name ) {
				if ( $this->sidebar_paragraph_used ) {
					return '';
				}
				$this->sidebar_paragraph_used = true;
				return $this->replace_block_text( $block_content, $sidebar_cta['copy'] ?? '', [ 'p' ], true );
			}

			if ( 'core/button' === $block_name ) {
				$index = $this->sidebar_button_index;
				$this->sidebar_button_index++;

				if ( 0 === $index ) {
					return $this->replace_block_link(
						$block_content,
						$sidebar_cta['primary_label'] ?? '',
						$sidebar_cta['primary_url'] ?? '',
						true
					);
				}

				if ( 1 === $index ) {
					return $this->replace_block_link(
						$block_content,
						$sidebar_cta['secondary_label'] ?? '',
						$sidebar_cta['secondary_url'] ?? '',
						true
					);
				}

				return '';
			}
		}

		if ( in_array( $component, [ 'content', 'image_text', 'text_image' ], true ) ) {
			if ( 'core/heading' === $block_name ) {
				$index = $this->content_heading_index;
				$this->content_heading_index++;
				return $this->replace_block_text( $block_content, $this->get_main_heading_by_index( $index ), [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true );
			}

			if ( 'core/paragraph' === $block_name ) {
				$index = $this->content_paragraph_index;
				$this->content_paragraph_index++;
				return $this->replace_block_rich_text( $block_content, $this->get_main_content_by_index( $index ), [ 'p' ], true );
			}

			if ( 'core/image' === $block_name ) {
				$media = $this->get_main_image_by_index( $this->content_image_index );
				$this->content_image_index++;
				return $this->replace_block_image( $block_content, $media, true );
			}

			if ( 'core/table' === $block_name && ! $this->table_used ) {
				$this->table_used = true;
				return $this->replace_block_table( $block_content, $this->current_service_meta['sp_table'] ?? [] );
			}
		}

		if ( 'faq' === $component && 'faq-block-for-gutenberg/faq' === $block_name ) {
			$faq_items = $this->current_service_meta['sp_faq'] ?? [];
			$item = isset( $faq_items[ $this->faq_index ] ) && \is_array( $faq_items[ $this->faq_index ] )
				? $faq_items[ $this->faq_index ]
				: [];
			$this->faq_index++;
			return $this->replace_faq_block( $block_content, $item );
		}

		if ( 'core/table' === $block_name && ! $this->table_used ) {
			$this->table_used = true;
			return $this->replace_block_table( $block_content, $this->current_service_meta['sp_table'] ?? [] );
		}

		if ( 'core/heading' === $block_name && 'faq' === $component ) {
			return $this->replace_block_text( $block_content, $this->get_faq_heading_label(), [ 'h2', 'h3', 'h4', 'h5', 'h6' ], true );
		}

		if ( 'core/heading' === $block_name && 'related' === $component ) {
			return $this->replace_block_heading( $block_content, $this->get_related_heading_label(), 3, true );
		}

		return $block_content;
	}

	private function contains_block_level_html( string $value ): bool {
		return 1 === preg_match( '/<(p|ul|ol|h[1-6]|div|blockquote|table|pre|figure|section|article)[\\s>]/i', $value );
	}

	private function format_rich_text( string $value ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$sanitized = \wp_kses_post( $value );

		if ( '' === \trim( $sanitized ) ) {
			return '';
		}

		if ( $this->contains_block_level_html( $sanitized ) ) {
			return $sanitized;
		}

		return \wpautop( $sanitized );
	}

	private function format_heading_text( string $value ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return \nl2br( \esc_html( $value ) );
	}

	private function replace_block_rich_text( string $block_content, string $value, array $tags, bool $remove_if_empty ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return $remove_if_empty ? '' : $block_content;
		}

		$sanitized = \wp_kses_post( $value );

		if ( '' === \trim( $sanitized ) ) {
			return $remove_if_empty ? '' : $block_content;
		}

		$has_blocks = $this->contains_block_level_html( $sanitized );

		foreach ( $tags as $tag ) {
			$pattern = sprintf( '/<%1$s([^>]*)>.*?<\\/%1$s>/is', preg_quote( $tag, '/' ) );

			if ( 1 === preg_match( $pattern, $block_content, $matches ) ) {
				if ( $has_blocks ) {
					$attrs = $matches[1] ?? '';
					return '<div' . $attrs . '>' . $sanitized . '</div>';
				}

				$inline = \nl2br( $sanitized );

				return (string) preg_replace( $pattern, '<' . $tag . '$1>' . $inline . '</' . $tag . '>', $block_content, 1 );
			}
		}

		return $block_content;
	}

	private function force_heading_level( string $block_content, int $level ): string {
		$level = max( 1, min( 6, $level ) );
		$pattern = '/<h[1-6]([^>]*)>(.*?)<\\/h[1-6]>/is';

		if ( 1 === preg_match( $pattern, $block_content ) ) {
			return (string) preg_replace( $pattern, '<h' . $level . '$1>$2</h' . $level . '>', $block_content, 1 );
		}

		return $block_content;
	}

	private function replace_block_heading( string $block_content, string $value, int $level, bool $remove_if_empty ): string {
		$updated = $this->replace_block_text( $block_content, $value, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], $remove_if_empty );

		return $this->force_heading_level( $updated, $level );
	}

	private function replace_block_text( string $block_content, string $value, array $tags, bool $remove_if_empty ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return $remove_if_empty ? '' : $block_content;
		}

		$escaped = \esc_html( $value );

		foreach ( $tags as $tag ) {
			$pattern = sprintf( '/(<%1$s[^>]*>).*?(<\\/%1$s>)/is', preg_quote( $tag, '/' ) );

			if ( 1 === preg_match( $pattern, $block_content ) ) {
				return (string) preg_replace( $pattern, '$1' . $escaped . '$2', $block_content, 1 );
			}
		}

		return $block_content;
	}

	private function replace_block_link( string $block_content, string $label, string $url, bool $remove_if_empty ): string {
		$label = \trim( (string) $label );
		$url   = \trim( (string) $url );

		if ( '' === $label && $remove_if_empty ) {
			return '';
		}

		$result = $block_content;

		if ( '' !== $label ) {
			$escaped = \esc_html( $label );
			$result = (string) preg_replace( '/(<a[^>]*>).*?(<\\/a>)/is', '$1' . $escaped . '$2', $result, 1 );
		}

		if ( '' !== $url ) {
			$escaped_url = \esc_url( $url );
			$result = (string) preg_replace( '/<a([^>]*?)href="[^"]*"([^>]*)>/i', '<a$1href="' . $escaped_url . '"$2>', $result, 1, $count );

			if ( 0 === $count ) {
				$result = (string) preg_replace( '/<a(?![^>]*href=)([^>]*)>/i', '<a href="' . $escaped_url . '"$1>', $result, 1 );
			}
		}

		return $result;
	}

	private function get_main_content_by_index( int $index ): string {
		switch ( $index ) {
			case 0:
				return (string) ( $this->current_service_meta['sp_main_1'] ?? '' );
			case 1:
				return (string) ( $this->current_service_meta['sp_main_2'] ?? '' );
			case 2:
				return (string) ( $this->current_service_meta['sp_main_3'] ?? '' );
			default:
				return '';
		}
	}

	private function get_main_heading_by_index( int $index ): string {
		return '';
	}

	private function get_cta_bullet_by_index( int $index ): string {
		$bullets = $this->current_wide_cta['bullets'] ?? [];
		if ( \is_array( $bullets ) && isset( $bullets[ $index ] ) ) {
			return (string) $bullets[ $index ];
		}

		return '';
	}

	private function get_main_image_by_index( int $index ): array {
		$key = 0 === $index ? 'sp_image_1' : ( 1 === $index ? 'sp_image_2' : '' );

		if ( '' === $key ) {
			return [
				'id'  => 0,
				'url' => '',
				'alt' => '',
			];
		}

		$attachment_id = (int) ( $this->current_service_meta[ $key ] ?? 0 );

		return $this->format_media( $attachment_id );
	}

	private function replace_block_image( string $block_content, array $media, bool $remove_if_empty ): string {
		$url = isset( $media['url'] ) ? (string) $media['url'] : '';

		if ( '' === \trim( $url ) ) {
			return $remove_if_empty ? '' : $block_content;
		}

		$escaped_url = \esc_url( $url );
		$result = (string) preg_replace( '/<img([^>]*?)src="[^"]*"([^>]*)>/i', '<img$1src="' . $escaped_url . '"$2>', $block_content, 1, $count );

		if ( 0 === $count ) {
			return $block_content;
		}

		$alt = isset( $media['alt'] ) ? (string) $media['alt'] : '';
		if ( '' !== $alt ) {
			$escaped_alt = \esc_attr( $alt );
			if ( 1 === preg_match( '/alt="[^"]*"/i', $result ) ) {
				$result = (string) preg_replace( '/alt="[^"]*"/i', 'alt="' . $escaped_alt . '"', $result, 1 );
			} else {
				$result = (string) preg_replace( '/<img/i', '<img alt="' . $escaped_alt . '"', $result, 1 );
			}
		}

		if ( ! empty( $media['id'] ) ) {
			$result = (string) preg_replace( '/(class="[^"]*)wp-image-\\d+([^"]*")/i', '$1wp-image-' . (int) $media['id'] . '$2', $result, 1 );
		}

		return $result;
	}

	private function replace_block_table( string $block_content, array $table ): string {
		if ( ! $this->table_has_content( $table ) ) {
			return '';
		}

		$table_markup = $this->build_table_markup( $table );
		if ( '' === $table_markup ) {
			return '';
		}

		$result = (string) preg_replace( '/<table[^>]*>.*?<\\/table>/is', $table_markup, $block_content, 1, $count );

		if ( 0 === $count ) {
			return $table_markup;
		}

		return $result;
	}

	private function build_table_markup( array $table ): string {
		if ( ! $this->table_has_content( $table ) ) {
			return '';
		}

		$rows = '';

		foreach ( $table as $row_index => $row ) {
			if ( ! \is_array( $row ) ) {
				continue;
			}

			$rows .= '<tr>';

			foreach ( $row as $cell ) {
				$tag = 0 === $row_index ? 'th' : 'td';
				$rows .= sprintf( '<%1$s>%2$s</%1$s>', $tag, \esc_html( (string) $cell ) );
			}

			$rows .= '</tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<table class="service-cpt-table"><tbody>' . $rows . '</tbody></table>';
	}

	private function replace_faq_block( string $block_content, array $item ): string {
		$question = \trim( (string) ( $item['question'] ?? '' ) );
		$answer   = \trim( (string) ( $item['answer'] ?? '' ) );

		if ( '' === $question && '' === $answer ) {
			return '';
		}

		if ( '' === $question ) {
			return '';
		}

		$background = $this->get_color_option( self::OPTION_COLOR_FAQ_BG );
		if ( '' === $background ) {
			$background = $this->get_color_option( self::OPTION_COLOR_SURFACE );
		}
		if ( '' === $background ) {
			$background = '#f6f7f7';
		}

		$question_color = $this->get_color_option( self::OPTION_COLOR_FAQ_QUESTION );
		if ( '' === $question_color ) {
			$question_color = '#000';
		}

		$answer_color = $this->get_color_option( self::OPTION_COLOR_FAQ_ANSWER );
		if ( '' === $answer_color ) {
			$answer_color = $question_color;
		}

		$answer_markup = $this->format_rich_text( $answer );

		return sprintf(
			'<div class="wp-block-faq-block-for-gutenberg-faq service-cpt-component service-cpt-component--faq" style="background:%1$s"><div class="question" style="background:none;color:%2$s"><h4 style="color:%2$s">%3$s</h4></div><div class="answer" style="background:none;color:%4$s">%5$s</div></div>',
			\esc_attr( $background ),
			\esc_attr( $question_color ),
			\esc_html( $question ),
			\esc_attr( $answer_color ),
			$answer_markup
		);
	}

	private function get_component_slug_for_block( array $block, $parent_block = null ): ?string {
		if ( $parent_block instanceof \WP_Block ) {
			$parent_block = $parent_block->parsed_block ?? null;
		}

		if ( null !== $parent_block && ! \is_array( $parent_block ) ) {
			$parent_block = null;
		}

		$block_name = $block['blockName'] ?? '';
		$metadata   = $block['attrs']['metadata']['name'] ?? '';

		if ( \is_string( $metadata ) && '' !== $metadata ) {
			$map = [
				'Hero section + CTA' => 'hero',
				'Header + intro'     => 'hero',
				'Intro paragraph'    => 'intro',
				'Spacer 50px'        => 'spacer',
				'Basic - 1 column'   => 'content',
				'Basic Content'      => 'content',
				'Basic + Sidebar'    => 'content',
				'Image-text'         => 'image_text',
				'text-image'         => 'text_image',
				'CTA wide'           => 'cta_wide',
				'Blocksy - Call to Action' => 'cta_cover',
				'Faq'                => 'faq',
				'Related Articles'   => 'related',
			];

			if ( isset( $map[ $metadata ] ) ) {
				return $map[ $metadata ];
			}
		}

		if ( 'essential-blocks/advanced-tabs' === $block_name ) {
			return 'tabs';
		}

		if ( 'faq-block-for-gutenberg/faq' === $block_name ) {
			return 'faq';
		}

		if ( $parent_block && isset( $parent_block['attrs']['metadata']['name'] ) ) {
			$parent_name = (string) $parent_block['attrs']['metadata']['name'];

			if ( 'CTA wide' === $parent_name && 'core/columns' === $block_name ) {
				return 'cta_card';
			}
		}

		return null;
	}

	private function get_template_component_settings( string $template_slug ): array {
		$definitions = $this->get_template_component_definitions( $template_slug );
		$saved = \get_option( self::OPTION_TEMPLATE_COMPONENTS, [] );

		if ( ! \is_array( $saved ) ) {
			$saved = [];
		}

		$template_saved = $saved;

		if ( isset( $saved[ $template_slug ] ) && \is_array( $saved[ $template_slug ] ) ) {
			$template_saved = $saved[ $template_slug ];
		}
		$normalized     = [];

		foreach ( $definitions as $component => $label ) {
			$normalized[ $component ] = isset( $template_saved[ $component ] ) ? (bool) $template_saved[ $component ] : true;
		}

		return $normalized;
	}

	private function component_enabled_for_template( string $component_slug, string $template_slug ): bool {
		$definitions = $this->get_template_component_definitions( $template_slug );

		if ( ! isset( $definitions[ $component_slug ] ) ) {
			return true;
		}

		$settings = $this->get_template_component_settings( $template_slug );

		return isset( $settings[ $component_slug ] ) ? (bool) $settings[ $component_slug ] : true;
	}

	private function get_template_component_definitions( string $template_slug ): array {
		$components = self::TEMPLATE_COMPONENTS[ $template_slug ] ?? [];
		$definitions = [];

		foreach ( $components as $component ) {
			if ( isset( self::TEMPLATE_COMPONENT_LABELS[ $component ] ) ) {
				$definitions[ $component ] = self::TEMPLATE_COMPONENT_LABELS[ $component ];
			}
		}

		return $definitions;
	}

	private function template_supports_component( string $template_slug, string $component_slug ): bool {
		$definitions = $this->get_template_component_definitions( $template_slug );

		return isset( $definitions[ $component_slug ] );
	}

	private function template_component_active( string $template_slug, string $component_slug ): bool {
		if ( ! $this->template_supports_component( $template_slug, $component_slug ) ) {
			return false;
		}

		return $this->component_enabled_for_template( $component_slug, $template_slug );
	}

	private function get_color_option_keys(): array {
		return [
			self::OPTION_COLOR_PRIMARY,
			self::OPTION_COLOR_CONTRAST,
			self::OPTION_COLOR_SURFACE,
			self::OPTION_COLOR_TEXT,
			self::OPTION_COLOR_ACCENT,
			self::OPTION_COLOR_BORDER,
			self::OPTION_COLOR_HERO_BG,
			self::OPTION_COLOR_HERO_TEXT,
			self::OPTION_COLOR_CTA_BG,
			self::OPTION_COLOR_CTA_TEXT,
			self::OPTION_COLOR_BUTTON_BG,
			self::OPTION_COLOR_BUTTON_TEXT,
			self::OPTION_COLOR_BUTTON_OUTLINE,
			self::OPTION_COLOR_FAQ_BG,
			self::OPTION_COLOR_FAQ_QUESTION,
			self::OPTION_COLOR_FAQ_ANSWER,
			self::OPTION_COLOR_TABS_ACTIVE_BG,
			self::OPTION_COLOR_TABS_ACTIVE_TEXT,
			self::OPTION_COLOR_TABS_INACTIVE_BG,
			self::OPTION_COLOR_TABS_INACTIVE_TEXT,
			self::OPTION_COLOR_TABS_BORDER,
		];
	}

	public static function sanitize_template_components_option( $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}

		$clean = [];
		$templates = self::TEMPLATE_COMPONENTS;
		$has_template_keys = false;

		foreach ( array_keys( $templates ) as $template_slug ) {
			if ( isset( $value[ $template_slug ] ) && \is_array( $value[ $template_slug ] ) ) {
				$has_template_keys = true;
				break;
			}
		}

		if ( ! $has_template_keys ) {
			foreach ( self::TEMPLATE_COMPONENT_LABELS as $component => $label ) {
				if ( array_key_exists( $component, $value ) ) {
					$clean[ $component ] = ! empty( $value[ $component ] );
				}
			}

			return $clean;
		}

		$stored = \get_option( self::OPTION_TEMPLATE_COMPONENTS, [] );

		if ( ! \is_array( $stored ) ) {
			$stored = [];
		}

		foreach ( $templates as $template_slug => $components ) {
			if ( isset( $value[ $template_slug ] ) && \is_array( $value[ $template_slug ] ) ) {
				$template_values = $value[ $template_slug ];
				$clean[ $template_slug ] = [];

				foreach ( $components as $component ) {
					$clean[ $template_slug ][ $component ] = ! empty( $template_values[ $component ] );
				}
			} else {
				$existing = isset( $stored[ $template_slug ] ) && \is_array( $stored[ $template_slug ] )
					? $stored[ $template_slug ]
					: [];
				$clean[ $template_slug ] = [];

				foreach ( $components as $component ) {
					if ( array_key_exists( $component, $existing ) ) {
						$clean[ $template_slug ][ $component ] = (bool) $existing[ $component ];
					} else {
						$clean[ $template_slug ][ $component ] = true;
					}
				}
			}
		}

		return $clean;
	}

	/**
	 * Registers a simple meta box as a fallback editor for all fields.
	 */
	public function register_meta_box(): void {
		\add_meta_box(
			'service-cpt-fields',
			__( 'Service Page Fields', 'nova-bridge-suite' ),
			[ $this, 'render_meta_box' ],
			self::CPT,
			'normal',
			'high'
		);
	}

	public function render_meta_box( \WP_Post $post ): void {
		\wp_nonce_field( 'service_cpt_save_meta', 'service_cpt_nonce' );
		$meta = $this->get_meta_values( $post->ID );
		$media_1 = $this->format_media( (int) $meta['sp_image_1'] );
		$media_2 = $this->format_media( (int) $meta['sp_image_2'] );
		$table_text = $this->format_table_for_editor( $meta['sp_table'] );
		$template_slug = $this->get_effective_template_slug( $post->ID );
		$show_hero_section = $this->template_component_active( $template_slug, 'hero' );
		$show_intro_section = $this->template_component_active( $template_slug, 'intro' );
		$show_content_section = $this->template_component_active( $template_slug, 'content' )
			|| $this->template_component_active( $template_slug, 'image_text' )
			|| $this->template_component_active( $template_slug, 'text_image' );
		$show_table_section = 'service-page-3' === $template_slug && $show_content_section;
		$show_images_section = $this->template_component_active( $template_slug, 'image_text' )
			|| $this->template_component_active( $template_slug, 'text_image' );
		$show_sidebar_cta_section = $this->template_component_active( $template_slug, 'cta_cover' );
		$show_wide_cta_section = $this->template_component_active( $template_slug, 'cta_wide' );
		$show_extra_section = 'service-page-3' === $template_slug && $show_content_section;
		$show_tabs_section = $this->template_component_active( $template_slug, 'tabs' );
		$show_faq_section = $this->template_component_active( $template_slug, 'faq' );
		$show_related_section = $this->template_component_active( $template_slug, 'related' );

		$field = function ( $label, $name, $value, $type = 'text', $classes = '' ) {
			$classes = trim( 'service-cpt-section-field ' . $classes );
			?>
			<div class="service-cpt-meta-field">
				<label><?php echo esc_html( $label ); ?></label>
				<?php if ( 'richtext' === $type ) : ?>
					<?php
					$editor_id = 'service_cpt_' . preg_replace( '/[^a-z0-9_]/i', '_', $name );
					\wp_editor(
						$value,
						$editor_id,
						[
							'textarea_name' => $name,
							'textarea_rows' => 4,
							'editor_class'  => $classes,
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => true,
							'tinymce'       => [
								'toolbar1' => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
								'toolbar2' => '',
								'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4;Heading 5=h5;Heading 6=h6',
								'resize'   => false,
								'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
							],
						]
					);
					?>
				<?php elseif ( 'textarea' === $type ) : ?>
					<textarea name="<?php echo esc_attr( $name ); ?>" class="large-text <?php echo esc_attr( $classes ); ?>" rows="3"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text <?php echo esc_attr( $classes ); ?>" value="<?php echo esc_attr( $value ); ?>" />
				<?php endif; ?>
			</div>
			<?php
		};

		$section_has_content = function ( array $values ) use ( &$section_has_content ): bool {
			foreach ( $values as $value ) {
				if ( \is_array( $value ) ) {
					if ( $section_has_content( $value ) ) {
						return true;
					}
					continue;
				}
				if ( '' !== \trim( (string) $value ) ) {
					return true;
				}
			}
			return false;
		};

		$has_hero = $section_has_content( [
			$meta['sp_hero_eyebrow'],
			$meta['sp_hero_title'],
			$meta['sp_hero_copy'],
			$meta['sp_hero_primary_label'],
			$meta['sp_hero_primary_url'],
			$meta['sp_hero_secondary_label'],
			$meta['sp_hero_secondary_url'],
		] );
		$has_intro = $section_has_content( [ $meta['sp_intro'] ] );
		$has_main = $section_has_content( [
			$meta['sp_main_1'],
			$meta['sp_main_2'],
			$meta['sp_main_3'],
		] );
		$has_table = '' !== \trim( (string) $table_text );
		$global_sidebar = $this->get_global_sidebar_cta();
		$global_wide = $this->get_global_wide_cta();
		$global_hero = $this->get_global_hero_cta();
		$has_global_hero = $this->cta_has_content( $global_hero );
		$has_global_sidebar = $this->cta_has_content( $global_sidebar );
		$has_global_wide = $this->cta_has_content( $global_wide );
		$show_hero_cta_editor = ! $has_global_hero;
		$show_sidebar_cta_editor = ! $has_global_sidebar;
		$show_wide_cta_editor = ! $has_global_wide;
		$has_sidebar = $show_sidebar_cta_editor ? $section_has_content( [
			$meta['sp_sidebar_title'],
			$meta['sp_sidebar_copy'],
			$meta['sp_sidebar_primary_label'],
			$meta['sp_sidebar_primary_url'],
			$meta['sp_sidebar_secondary_label'],
			$meta['sp_sidebar_secondary_url'],
		] ) : false;
		$has_cta = $show_wide_cta_editor ? $section_has_content( [
			$meta['sp_cta_title'],
			$meta['sp_cta_bullets'],
			$meta['sp_cta_button_label'],
			$meta['sp_cta_button_url'],
			$meta['sp_cta_more_text'],
			$meta['sp_cta_more_url'],
		] ) : false;
		$has_extra = $section_has_content( [
			$meta['sp_extra_copy'],
		] );
		$has_tabs = $section_has_content( [
			$meta['sp_tab_1_title'],
			$meta['sp_tab_1_content'],
			$meta['sp_tab_2_title'],
			$meta['sp_tab_2_content'],
			$meta['sp_tab_3_title'],
			$meta['sp_tab_3_content'],
		] );
		$has_faq = $section_has_content( $meta['sp_faq'] );
		$has_related = ! empty( $meta['sp_related_posts'] );
		$has_images = ! empty( $media_1['url'] ) || ! empty( $media_2['url'] );
		$related_posts = \get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'suppress_filters' => false,
		] );
		$selected_related = isset( $meta['sp_related_posts'] ) && \is_array( $meta['sp_related_posts'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $meta['sp_related_posts'] ) ) ) )
			: [];

		?>
		<?php if ( $show_hero_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_hero ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Hero (top of page)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Top section', 'nova-bridge-suite' ); ?></span></summary>
			<div class="service-cpt-section-body">
				<div class="service-cpt-meta-grid">
					<?php
					$field( __( 'Hero eyebrow', 'nova-bridge-suite' ), 'sp_hero_eyebrow', $meta['sp_hero_eyebrow'] );
					$field( __( 'Hero title (H1)', 'nova-bridge-suite' ), 'sp_hero_title', $meta['sp_hero_title'] );
					$field( __( 'Hero copy', 'nova-bridge-suite' ), 'sp_hero_copy', $meta['sp_hero_copy'], 'richtext' );
					if ( $show_hero_cta_editor ) {
						$field( __( 'Hero primary CTA label', 'nova-bridge-suite' ), 'sp_hero_primary_label', $meta['sp_hero_primary_label'] );
						$field( __( 'Hero primary CTA URL', 'nova-bridge-suite' ), 'sp_hero_primary_url', $meta['sp_hero_primary_url'] );
						$field( __( 'Hero secondary CTA label', 'nova-bridge-suite' ), 'sp_hero_secondary_label', $meta['sp_hero_secondary_label'] );
						$field( __( 'Hero secondary CTA URL', 'nova-bridge-suite' ), 'sp_hero_secondary_url', $meta['sp_hero_secondary_url'] );
					}
					?>
				</div>
				<?php if ( ! $show_hero_cta_editor ) : ?>
					<p class="description"><?php esc_html_e( 'Hero CTAs are defined globally in Settings → NOVA Services Settings.', 'nova-bridge-suite' ); ?></p>
				<?php endif; ?>
			</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_intro_section ) : ?>
			<details class="service-cpt-section service-cpt-section--single" data-autotoggle="1" <?php echo $has_intro ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Intro (below hero)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Upper content', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php $field( __( 'Intro paragraph', 'nova-bridge-suite' ), 'sp_intro', $meta['sp_intro'], 'richtext' ); ?>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_content_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_main ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Main content (middle)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Main body', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'Main text 1', 'nova-bridge-suite' ), 'sp_main_1', $meta['sp_main_1'], 'richtext' );
						$field( __( 'Main text 2', 'nova-bridge-suite' ), 'sp_main_2', $meta['sp_main_2'], 'richtext' );
						$field( __( 'Main text 3', 'nova-bridge-suite' ), 'sp_main_3', $meta['sp_main_3'], 'richtext' );
						?>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_table_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_table ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Table (main content)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Main body', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<div class="service-cpt-meta-field">
							<label><?php esc_html_e( 'Table rows', 'nova-bridge-suite' ); ?></label>
							<textarea name="sp_table_text" class="large-text service-cpt-section-field service-cpt-table-textarea" rows="4"><?php echo esc_textarea( $table_text ); ?></textarea>
							<div class="service-cpt-table-builder" data-target="sp_table_text" data-min-columns="2">
								<div class="service-cpt-table-controls">
									<button type="button" class="button service-cpt-table-add-row"><?php esc_html_e( 'Add row', 'nova-bridge-suite' ); ?></button>
									<button type="button" class="button service-cpt-table-add-col"><?php esc_html_e( 'Add column', 'nova-bridge-suite' ); ?></button>
									<button type="button" class="button service-cpt-table-remove-col"><?php esc_html_e( 'Remove column', 'nova-bridge-suite' ); ?></button>
								</div>
								<div class="service-cpt-table-rows"></div>
								<p class="description"><?php esc_html_e( 'First row renders as table headers. Use Add column for richer tables.', 'nova-bridge-suite' ); ?></p>
							</div>
						</div>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_sidebar_cta_section && $show_sidebar_cta_editor ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_sidebar ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Sidebar CTA (right column)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Side column', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'Sidebar title (H3)', 'nova-bridge-suite' ), 'sp_sidebar_title', $meta['sp_sidebar_title'] );
						$field( __( 'Sidebar copy', 'nova-bridge-suite' ), 'sp_sidebar_copy', $meta['sp_sidebar_copy'], 'richtext' );
						$field( __( 'Sidebar primary CTA label', 'nova-bridge-suite' ), 'sp_sidebar_primary_label', $meta['sp_sidebar_primary_label'] );
						$field( __( 'Sidebar primary CTA URL', 'nova-bridge-suite' ), 'sp_sidebar_primary_url', $meta['sp_sidebar_primary_url'] );
						$field( __( 'Sidebar secondary CTA label', 'nova-bridge-suite' ), 'sp_sidebar_secondary_label', $meta['sp_sidebar_secondary_label'] );
						$field( __( 'Sidebar secondary CTA URL', 'nova-bridge-suite' ), 'sp_sidebar_secondary_url', $meta['sp_sidebar_secondary_url'] );
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Visible only when Global CTAs are empty.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_wide_cta_section && $show_wide_cta_editor ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_cta ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Wide CTA (full width)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Full width', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'CTA title (H2)', 'nova-bridge-suite' ), 'sp_cta_title', $meta['sp_cta_title'] );
						$field( __( 'CTA bullet 1', 'nova-bridge-suite' ), 'sp_cta_bullet_1', $meta['sp_cta_bullets'][0] ?? '' );
						$field( __( 'CTA bullet 2', 'nova-bridge-suite' ), 'sp_cta_bullet_2', $meta['sp_cta_bullets'][1] ?? '' );
						$field( __( 'CTA bullet 3', 'nova-bridge-suite' ), 'sp_cta_bullet_3', $meta['sp_cta_bullets'][2] ?? '' );
						$field( __( 'CTA button label', 'nova-bridge-suite' ), 'sp_cta_button_label', $meta['sp_cta_button_label'] );
						$field( __( 'CTA button URL', 'nova-bridge-suite' ), 'sp_cta_button_url', $meta['sp_cta_button_url'] );
						$field( __( 'CTA more text', 'nova-bridge-suite' ), 'sp_cta_more_text', $meta['sp_cta_more_text'] );
						$field( __( 'CTA more URL', 'nova-bridge-suite' ), 'sp_cta_more_url', $meta['sp_cta_more_url'] );
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Visible only when Global CTAs are empty.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_extra_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_extra ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Extra section (template 3)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'After CTA wide', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'Extra section text', 'nova-bridge-suite' ), 'sp_extra_copy', $meta['sp_extra_copy'], 'richtext' );
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Controls the rich text section after the CTA in template 3.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_tabs_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_tabs ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Tabs (template 3)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Tabbed content', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'Tab 1 label', 'nova-bridge-suite' ), 'sp_tab_1_title', $meta['sp_tab_1_title'] );
						$field( __( 'Tab 1 content', 'nova-bridge-suite' ), 'sp_tab_1_content', $meta['sp_tab_1_content'], 'richtext' );
						$field( __( 'Tab 2 label', 'nova-bridge-suite' ), 'sp_tab_2_title', $meta['sp_tab_2_title'] );
						$field( __( 'Tab 2 content', 'nova-bridge-suite' ), 'sp_tab_2_content', $meta['sp_tab_2_content'], 'richtext' );
						$field( __( 'Tab 3 label', 'nova-bridge-suite' ), 'sp_tab_3_title', $meta['sp_tab_3_title'] );
						$field( __( 'Tab 3 content', 'nova-bridge-suite' ), 'sp_tab_3_content', $meta['sp_tab_3_content'], 'richtext' );
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Tab labels are plain text. Tab content supports rich text (headings, lists).', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_faq_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_faq ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'FAQ (near bottom)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Lower section', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<?php
						$field( __( 'FAQ 1 question', 'nova-bridge-suite' ), 'sp_faq_q1', $meta['sp_faq'][0]['question'] ?? '' );
						$field( __( 'FAQ 1 answer', 'nova-bridge-suite' ), 'sp_faq_a1', $meta['sp_faq'][0]['answer'] ?? '', 'richtext' );
						$field( __( 'FAQ 2 question', 'nova-bridge-suite' ), 'sp_faq_q2', $meta['sp_faq'][1]['question'] ?? '' );
						$field( __( 'FAQ 2 answer', 'nova-bridge-suite' ), 'sp_faq_a2', $meta['sp_faq'][1]['answer'] ?? '', 'richtext' );
						$field( __( 'FAQ 3 question', 'nova-bridge-suite' ), 'sp_faq_q3', $meta['sp_faq'][2]['question'] ?? '' );
						$field( __( 'FAQ 3 answer', 'nova-bridge-suite' ), 'sp_faq_a3', $meta['sp_faq'][2]['answer'] ?? '', 'richtext' );
						$field( __( 'FAQ 4 question', 'nova-bridge-suite' ), 'sp_faq_q4', $meta['sp_faq'][3]['question'] ?? '' );
						$field( __( 'FAQ 4 answer', 'nova-bridge-suite' ), 'sp_faq_a4', $meta['sp_faq'][3]['answer'] ?? '', 'richtext' );
						?>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_related_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_related ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Related articles (optional)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Below FAQ', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<div class="service-cpt-meta-field">
							<label><?php esc_html_e( 'Select related posts', 'nova-bridge-suite' ); ?></label>
							<input type="search" class="service-cpt-related-search" placeholder="<?php esc_attr_e( 'Filter posts...', 'nova-bridge-suite' ); ?>" />
							<?php if ( empty( $related_posts ) ) : ?>
								<p class="description"><?php esc_html_e( 'No posts found.', 'nova-bridge-suite' ); ?></p>
							<?php else : ?>
								<div class="service-cpt-related-list">
									<?php foreach ( $related_posts as $related_post ) : ?>
										<?php
										$post_id = (int) $related_post->ID;
										$title = \get_the_title( $related_post );
										$is_checked = in_array( $post_id, $selected_related, true );
										?>
										<label class="service-cpt-related-item" data-related-item data-related-title="<?php echo esc_attr( strtolower( (string) $title ) ); ?>">
											<input
												type="checkbox"
												name="sp_related_posts[]"
												value="<?php echo esc_attr( $post_id ); ?>"
												class="service-cpt-section-field"
												<?php checked( $is_checked ); ?>
											/>
											<span><?php echo esc_html( $title ? $title : __( '(no title)', 'nova-bridge-suite' ) ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description"><?php esc_html_e( 'If nothing is selected, the related articles section stays hidden.', 'nova-bridge-suite' ); ?></p>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( $show_images_section ) : ?>
			<details class="service-cpt-section" data-autotoggle="1" <?php echo $has_images ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Images (inline content)', 'nova-bridge-suite' ); ?><span class="service-cpt-section-location"><?php esc_html_e( 'Main body', 'nova-bridge-suite' ); ?></span></summary>
				<div class="service-cpt-section-body">
					<div class="service-cpt-meta-grid">
						<div class="service-cpt-meta-field">
							<label><?php esc_html_e( 'Image 1', 'nova-bridge-suite' ); ?></label>
							<input type="hidden" name="sp_image_1" value="<?php echo esc_attr( (int) $media_1['id'] ); ?>" />
							<div id="sp_image_1_preview" class="service-cpt-image-preview <?php echo $media_1['url'] ? 'has-image' : 'is-empty'; ?>">
								<img src="<?php echo esc_url( $media_1['url'] ); ?>" alt="" />
								<span class="service-cpt-image-placeholder"><?php esc_html_e( 'No image selected', 'nova-bridge-suite' ); ?></span>
								<button
									type="button"
									class="service-cpt-media-remove service-cpt-image-remove"
									data-target="sp_image_1"
									data-preview="#sp_image_1_preview"
									data-button="button.service-cpt-media-button[data-target='sp_image_1']"
									aria-label="<?php esc_attr_e( 'Remove image 1', 'nova-bridge-suite' ); ?>"
									<?php disabled( ! $media_1['id'] ); ?>
								>
									X
								</button>
							</div>
							<div class="service-cpt-image-actions">
								<button
									type="button"
									class="button service-cpt-media-button"
									data-target="sp_image_1"
									data-preview="#sp_image_1_preview"
									data-select-label="<?php echo esc_attr__( 'Select image 1', 'nova-bridge-suite' ); ?>"
									data-change-label="<?php echo esc_attr__( 'Change image 1', 'nova-bridge-suite' ); ?>"
								>
									<?php echo $media_1['id'] ? esc_html__( 'Change image 1', 'nova-bridge-suite' ) : esc_html__( 'Select image 1', 'nova-bridge-suite' ); ?>
								</button>
							</div>
						</div>

						<div class="service-cpt-meta-field">
							<label><?php esc_html_e( 'Image 2', 'nova-bridge-suite' ); ?></label>
							<input type="hidden" name="sp_image_2" value="<?php echo esc_attr( (int) $media_2['id'] ); ?>" />
							<div id="sp_image_2_preview" class="service-cpt-image-preview <?php echo $media_2['url'] ? 'has-image' : 'is-empty'; ?>">
								<img src="<?php echo esc_url( $media_2['url'] ); ?>" alt="" />
								<span class="service-cpt-image-placeholder"><?php esc_html_e( 'No image selected', 'nova-bridge-suite' ); ?></span>
								<button
									type="button"
									class="service-cpt-media-remove service-cpt-image-remove"
									data-target="sp_image_2"
									data-preview="#sp_image_2_preview"
									data-button="button.service-cpt-media-button[data-target='sp_image_2']"
									aria-label="<?php esc_attr_e( 'Remove image 2', 'nova-bridge-suite' ); ?>"
									<?php disabled( ! $media_2['id'] ); ?>
								>
									X
								</button>
							</div>
							<div class="service-cpt-image-actions">
								<button
									type="button"
									class="button service-cpt-media-button"
									data-target="sp_image_2"
									data-preview="#sp_image_2_preview"
									data-select-label="<?php echo esc_attr__( 'Select image 2', 'nova-bridge-suite' ); ?>"
									data-change-label="<?php echo esc_attr__( 'Change image 2', 'nova-bridge-suite' ); ?>"
								>
									<?php echo $media_2['id'] ? esc_html__( 'Change image 2', 'nova-bridge-suite' ) : esc_html__( 'Select image 2', 'nova-bridge-suite' ); ?>
								</button>
							</div>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'Images: set Featured Image for the main hero image, or set media IDs via API for sp_image_1 / sp_image_2.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		<?php endif; ?>
		<?php
	}

	public function save_meta_box( int $post_id, \WP_Post $post ): void {
		$nonce = isset( $_POST['service_cpt_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['service_cpt_nonce'] ) ) : '';
		if ( '' === $nonce || ! \wp_verify_nonce( $nonce, 'service_cpt_save_meta' ) ) {
			return;
		}

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$meta = $this->get_meta_values( $post_id );

		$string_fields = [
			'sp_hero_eyebrow',
			'sp_hero_title',
			'sp_hero_primary_label',
			'sp_hero_primary_url',
			'sp_hero_secondary_label',
			'sp_hero_secondary_url',
			'sp_sidebar_title',
			'sp_sidebar_primary_label',
			'sp_sidebar_primary_url',
			'sp_sidebar_secondary_label',
			'sp_sidebar_secondary_url',
			'sp_cta_title',
			'sp_cta_button_label',
			'sp_cta_button_url',
			'sp_cta_more_text',
			'sp_cta_more_url',
			'sp_tab_1_title',
			'sp_tab_2_title',
			'sp_tab_3_title',
		];

		$rich_text_fields = [
			'sp_hero_copy',
			'sp_intro',
			'sp_main_1',
			'sp_main_2',
			'sp_main_3',
			'sp_sidebar_copy',
			'sp_extra_copy',
			'sp_tab_1_content',
			'sp_tab_2_content',
			'sp_tab_3_content',
		];

		foreach ( $string_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$meta[ $field ] = \sanitize_text_field( (string) wp_unslash( $_POST[ $field ] ) );
				\update_post_meta( $post_id, $field, $meta[ $field ] );
			}
		}

		foreach ( $rich_text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$meta[ $field ] = $this->sanitize_rich_text( (string) wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				\update_post_meta( $post_id, $field, $meta[ $field ] );
			}
		}

		if ( isset( $_POST['sp_table_text'] ) ) {
			$table_raw        = \sanitize_textarea_field( (string) wp_unslash( $_POST['sp_table_text'] ) );
			$meta['sp_table'] = self::sanitize_table( $this->parse_table_input( $table_raw ) );
			\update_post_meta( $post_id, 'sp_table', $meta['sp_table'] );
		}

		// CTA bullets.
		$bullets = [];
		$has_cta_bullets = false;

		foreach ( [ 1, 2, 3 ] as $index ) {
			$key = 'sp_cta_bullet_' . $index;
			if ( isset( $_POST[ $key ] ) ) {
				$bullets[] = \sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) );
				$has_cta_bullets = true;
				continue;
			}
			$bullets[] = '';
		}

		if ( $has_cta_bullets ) {
			$meta['sp_cta_bullets'] = $this->sanitize_string_array( $bullets );
			\update_post_meta( $post_id, 'sp_cta_bullets', $meta['sp_cta_bullets'] );
		}

		// FAQ.
		$faq = [];

		foreach ( [ 1, 2, 3, 4 ] as $index ) {
			$q = isset( $_POST[ 'sp_faq_q' . $index ] ) ? \sanitize_text_field( (string) wp_unslash( $_POST[ 'sp_faq_q' . $index ] ) ) : '';
			$a = isset( $_POST[ 'sp_faq_a' . $index ] ) ? $this->sanitize_rich_text( (string) wp_unslash( $_POST[ 'sp_faq_a' . $index ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( '' === $q && '' === $a ) {
				continue;
			}

			$faq[] = [
				'question' => $q,
				'answer'   => $a,
			];
		}

		$meta['sp_faq'] = $this->sanitize_faq( $faq );
		\update_post_meta( $post_id, 'sp_faq', $meta['sp_faq'] );

		// Related posts.
		$related_posts = [];
		if ( isset( $_POST['sp_related_posts'] ) && \is_array( $_POST['sp_related_posts'] ) ) {
			$related_posts = array_map( 'absint', wp_unslash( $_POST['sp_related_posts'] ) );
		}
		$meta['sp_related_posts'] = self::sanitize_post_ids( $related_posts );
		\update_post_meta( $post_id, 'sp_related_posts', $meta['sp_related_posts'] );

		// Images.
		foreach ( [ 'sp_image_1', 'sp_image_2' ] as $image_field ) {
			if ( isset( $_POST[ $image_field ] ) ) {
				$meta[ $image_field ] = \absint( wp_unslash( $_POST[ $image_field ] ) );
				\update_post_meta( $post_id, $image_field, $meta[ $image_field ] );
			}
		}

		$this->maybe_apply_template_content( $post_id );
	}

	/**
	 * Component visibility toggles with defaults.
	 */
	private function get_component_settings(): array {
		$saved = \get_option( self::OPTION_COMPONENTS, [] );

		if ( ! \is_array( $saved ) ) {
			$saved = [];
		}

		$normalized = self::DEFAULT_COMPONENTS;

		foreach ( self::DEFAULT_COMPONENTS as $component => $default ) {
			$normalized[ $component ] = isset( $saved[ $component ] ) ? (bool) $saved[ $component ] : $default;
		}

		return $normalized;
	}

	private function component_enabled( string $component ): bool {
		$components = $this->get_component_settings();

		return isset( $components[ $component ] ) ? (bool) $components[ $component ] : true;
	}

	/**
	 * Renders the service page content, falling back to the legacy layout.
	 */
	public function render_service_page( int $post_id ): string {
		$post = \get_post( $post_id );
		$template_slug = $this->get_effective_template_slug( $post_id );
		$wrap_style = $this->get_wrap_style_attribute( $template_slug );

		if ( $post && '' !== \trim( (string) $post->post_content ) ) {
			$previous_post = $GLOBALS['post'] ?? null;
			$GLOBALS['post'] = $post;
			\setup_postdata( $post );

			$this->rendering_service_page = true;
			$this->current_service_post_id = $post_id;
			$this->current_service_meta = $this->get_meta_values( $post_id );
			$this->current_service_title = (string) \get_the_title( $post_id );
			$this->current_hero_cta = $this->get_effective_hero_cta( $this->current_service_meta );
			$this->current_sidebar_cta = $this->get_effective_sidebar_cta( $this->current_service_meta );
			$this->current_wide_cta = $this->get_effective_wide_cta( $this->current_service_meta );
			$this->hero_paragraph_index = 0;
			$this->hero_button_index = 0;
			$this->hero_heading_used = false;
			$this->cta_paragraph_index = 0;
			$this->cta_button_index = 0;
			$this->sidebar_button_index = 0;
			$this->sidebar_heading_used = false;
			$this->sidebar_paragraph_used = false;
			$this->content_heading_index = 0;
			$this->content_paragraph_index = 0;
			$this->content_image_index = 0;
			$this->faq_index = 0;
			$this->table_used = false;

			try {
				$content_source = (string) $post->post_content;
				$blocks = \parse_blocks( $content_source );

				if ( ! empty( $blocks ) ) {
					$blocks = $this->add_component_classes_to_blocks( $blocks );
					$content_source = (string) \serialize_blocks( $blocks );
				}

				$content = (string) \apply_filters( 'the_content', $content_source ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			} finally {
				$this->rendering_service_page = false;
				$this->current_service_post_id = 0;
				$this->current_service_meta = [];
				$this->current_service_title = '';
				$this->current_hero_cta = [];
				$this->current_sidebar_cta = [];
				$this->current_wide_cta = [];
				$this->hero_paragraph_index = 0;
				$this->hero_button_index = 0;
				$this->hero_heading_used = false;
				$this->cta_paragraph_index = 0;
				$this->cta_button_index = 0;
				$this->sidebar_button_index = 0;
				$this->sidebar_heading_used = false;
				$this->sidebar_paragraph_used = false;
				$this->content_heading_index = 0;
				$this->content_paragraph_index = 0;
				$this->content_image_index = 0;
				$this->faq_index = 0;
				$this->table_used = false;
			}

			$content = $this->normalize_rendered_content( $content );

			if ( $previous_post instanceof \WP_Post ) {
				$GLOBALS['post'] = $previous_post;
				\setup_postdata( $previous_post );
			} else {
				\wp_reset_postdata();
			}

			return '<div class="service-cpt__wrap"' . $wrap_style . '>' . $content . '</div>';
		}

		return $this->render_legacy_layout( $post_id );
	}

	/**
	 * Renders the archive page layout.
	 */
	public function render_archive_page(): string {
		$wrap_style = $this->get_wrap_style_attribute( $this->get_selected_template_slug() );
		$type_object = \get_post_type_object( self::CPT );
		$default_title = $type_object ? (string) $type_object->labels->name : __( 'Services', 'nova-bridge-suite' );

		$hero_eyebrow = $this->get_archive_text_option( self::OPTION_ARCHIVE_HERO_EYEBROW );
		$hero_title = $this->get_archive_multiline_option( self::OPTION_ARCHIVE_HERO_TITLE );
		if ( '' === $hero_title ) {
			$hero_title = $default_title;
		}
		$hero_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HERO_COPY );
		$hero_cta = $this->get_archive_hero_cta();

		$intro_heading = $this->get_archive_text_option( self::OPTION_ARCHIVE_INTRO_HEADING );
		$intro_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_INTRO_COPY );

		$services = $this->get_archive_services();
		$card_cta_label = $this->get_archive_text_option( self::OPTION_ARCHIVE_CARD_CTA_LABEL );
		$card_placeholder = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_CARD_PLACEHOLDER, 0 ) );

		$highlights_heading = $this->get_archive_text_option( self::OPTION_ARCHIVE_HIGHLIGHTS_HEADING );
		$highlight_one_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_COPY );
		$highlight_two_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_COPY );
		$highlight_one_media = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE, 0 ) );
		$highlight_two_media = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE, 0 ) );

		$cta = $this->get_archive_wide_cta();
		$faq_items = $this->get_archive_faq_items();

		$related_ids = $this->get_archive_related_post_ids();
		$related_posts = [];
		if ( ! empty( $related_ids ) ) {
			$related_posts = \get_posts( [
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post__in'       => $related_ids,
				'orderby'        => 'post__in',
				'posts_per_page' => count( $related_ids ),
				'no_found_rows'  => true,
			] );
		}

		$has_intro = '' !== \trim( $intro_heading ) || '' !== \trim( $intro_copy );
		$highlight_one_has = '' !== \trim( $highlight_one_copy ) || '' !== \trim( (string) $highlight_one_media['url'] );
		$highlight_two_has = '' !== \trim( $highlight_two_copy ) || '' !== \trim( (string) $highlight_two_media['url'] );
		$has_highlights = '' !== \trim( $highlights_heading ) || $highlight_one_has || $highlight_two_has;
		$has_cta = $this->cta_has_content( $cta );
		$has_faq = ! empty( $faq_items );
		$has_related = ! empty( $related_posts );

		$render_highlight = function ( array $media, string $copy, bool $reverse ): string {
			$copy = $this->format_rich_text( $copy );
			$has_copy = '' !== \trim( $copy );
			$has_media = '' !== \trim( (string) ( $media['url'] ?? '' ) );

			if ( ! $has_copy && ! $has_media ) {
				return '';
			}

			$classes = 'service-cpt-archive__highlight';
			if ( $reverse ) {
				$classes .= ' service-cpt-archive__highlight--reverse';
			}
			if ( ! $has_copy || ! $has_media ) {
				$classes .= ' service-cpt-archive__highlight--single';
			}

			ob_start();
			?>
			<div class="<?php echo esc_attr( $classes ); ?>">
				<?php if ( $has_media ) : ?>
					<figure class="service-cpt-archive__media">
						<img src="<?php echo esc_url( $media['url'] ); ?>" alt="<?php echo esc_attr( $media['alt'] ?? '' ); ?>" />
					</figure>
				<?php endif; ?>
				<?php if ( $has_copy ) : ?>
					<div class="service-cpt-archive__highlight-copy">
						<?php echo $copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
		};

		ob_start();
		?>
		<div class="service-cpt__wrap service-cpt-archive"<?php echo $wrap_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<section class="service-cpt-archive__section service-cpt-archive__section--hero">
				<div class="service-cpt-archive__inner">
					<?php if ( '' !== \trim( $hero_eyebrow ) ) : ?>
						<p class="service-cpt-archive__eyebrow"><?php echo esc_html( $hero_eyebrow ); ?></p>
					<?php endif; ?>
					<h1 class="wp-block-heading service-cpt-archive__title"><?php echo $this->format_heading_text( $hero_title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h1>
					<?php if ( '' !== \trim( $hero_copy ) ) : ?>
						<div class="service-cpt-archive__lead">
							<?php echo $this->format_rich_text( $hero_copy ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
					<?php endif; ?>
					<?php if ( '' !== \trim( $hero_cta['label'] ?? '' ) && '' !== \trim( $hero_cta['url'] ?? '' ) ) : ?>
						<div class="service-cpt-archive__actions">
							<a class="service-cpt__btn service-cpt__btn--primary service-cpt-archive__hero-btn" href="<?php echo esc_url( $hero_cta['url'] ); ?>">
								<?php echo esc_html( $hero_cta['label'] ); ?>
							</a>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( $has_intro ) : ?>
				<section class="service-cpt-archive__section service-cpt-archive__section--intro">
					<div class="service-cpt-archive__inner">
						<?php if ( '' !== \trim( $intro_heading ) ) : ?>
							<h2 class="wp-block-heading service-cpt-archive__section-heading"><?php echo esc_html( $intro_heading ); ?></h2>
						<?php endif; ?>
						<?php if ( '' !== \trim( $intro_copy ) ) : ?>
							<div class="service-cpt-archive__intro-copy">
								<?php echo $this->format_rich_text( $intro_copy ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<section id="service-cpt-archive-services" class="service-cpt-archive__section service-cpt-archive__section--services">
				<div class="service-cpt-archive__inner">
					<?php if ( empty( $services ) ) : ?>
						<p><?php esc_html_e( 'No services available yet.', 'nova-bridge-suite' ); ?></p>
					<?php else : ?>
						<div class="service-cpt-archive__grid">
							<?php
							$previous_post = $GLOBALS['post'] ?? null;
							foreach ( $services as $service_post ) :
								$GLOBALS['post'] = $service_post;
								\setup_postdata( $service_post );
								$thumbnail = \get_the_post_thumbnail( $service_post->ID, 'large', [
									'class' => 'service-cpt-archive__card-image',
								] );
								$has_media = false;
								if ( $thumbnail ) {
									$has_media = true;
								} elseif ( ! empty( $card_placeholder['url'] ) ) {
									$has_media = true;
									$thumbnail = sprintf(
										'<img class="service-cpt-archive__card-image" src="%s" alt="%s" />',
										esc_url( $card_placeholder['url'] ),
										esc_attr( $card_placeholder['alt'] ?? '' )
									);
								}
								?>
								<article class="service-cpt-archive__card<?php echo $has_media ? '' : ' service-cpt-archive__card--no-media'; ?>">
									<a class="service-cpt-archive__card-linkwrap" href="<?php the_permalink(); ?>">
										<div class="service-cpt-archive__card-inner">
											<?php if ( $has_media ) : ?>
												<div class="service-cpt-archive__card-media">
													<?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</div>
											<?php endif; ?>
											<div class="service-cpt-archive__card-body">
												<h3 class="wp-block-heading service-cpt-archive__card-title"><?php the_title(); ?></h3>
												<p class="service-cpt-archive__card-excerpt"><?php echo esc_html( \wp_trim_words( \get_the_excerpt( $service_post ), 18 ) ); ?></p>
												<?php if ( '' !== \trim( $card_cta_label ) ) : ?>
													<span class="service-cpt-archive__card-cta"><?php echo esc_html( $card_cta_label ); ?></span>
												<?php endif; ?>
											</div>
										</div>
									</a>
								</article>
								<?php
							endforeach;
							if ( $previous_post instanceof \WP_Post ) {
								$GLOBALS['post'] = $previous_post;
								\setup_postdata( $previous_post );
							} else {
								\wp_reset_postdata();
							}
							?>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<?php if ( $has_highlights ) : ?>
				<section class="service-cpt-archive__section service-cpt-archive__section--highlights">
					<div class="service-cpt-archive__inner">
						<?php if ( '' !== \trim( $highlights_heading ) ) : ?>
							<h2 class="wp-block-heading service-cpt-archive__section-heading"><?php echo esc_html( $highlights_heading ); ?></h2>
						<?php endif; ?>
						<?php echo $render_highlight( $highlight_one_media, $highlight_one_copy, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $render_highlight( $highlight_two_media, $highlight_two_copy, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $has_cta ) : ?>
				<section class="service-cpt-archive__section service-cpt-archive__section--cta">
					<div class="service-cpt-archive__inner">
						<div class="service-cpt-archive__cta">
							<div class="service-cpt-archive__cta-grid">
								<?php if ( '' !== \trim( $cta['title'] ?? '' ) ) : ?>
									<h2 class="wp-block-heading service-cpt-archive__cta-title"><?php echo esc_html( $cta['title'] ); ?></h2>
								<?php endif; ?>
								<?php if ( ! empty( $cta['bullets'] ) ) : ?>
									<ul class="service-cpt-archive__cta-bullets">
										<?php foreach ( $cta['bullets'] as $bullet ) : ?>
											<li><?php echo esc_html( $bullet ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<div class="service-cpt-archive__cta-actions">
									<?php if ( '' !== \trim( $cta['button_label'] ?? '' ) && '' !== \trim( $cta['button_url'] ?? '' ) ) : ?>
										<a class="service-cpt__btn service-cpt__btn--primary" href="<?php echo esc_url( $cta['button_url'] ); ?>">
											<?php echo esc_html( $cta['button_label'] ); ?>
										</a>
									<?php endif; ?>
									<?php if ( '' !== \trim( $cta['more_text'] ?? '' ) && '' !== \trim( $cta['more_url'] ?? '' ) ) : ?>
										<a class="service-cpt-archive__cta-link" href="<?php echo esc_url( $cta['more_url'] ); ?>">
											<?php echo esc_html( $cta['more_text'] ); ?>
										</a>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $has_faq ) : ?>
				<section class="service-cpt-archive__section service-cpt-archive__section--faq">
					<div class="service-cpt-archive__inner">
						<h3 class="wp-block-heading service-cpt-archive__section-heading"><?php echo esc_html( $this->get_faq_heading_label() ); ?></h3>
						<div class="service-cpt-archive__faq-list">
							<?php
							foreach ( $faq_items as $item ) {
								echo $this->replace_faq_block( '', $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $has_related ) : ?>
				<section class="service-cpt-archive__section service-cpt-archive__section--related">
					<div class="service-cpt-archive__inner">
						<h3 class="wp-block-heading service-cpt-archive__section-heading"><?php echo esc_html( $this->get_related_heading_label() ); ?></h3>
						<div class="service-cpt-archive__related-grid">
							<?php
							$previous_post = $GLOBALS['post'] ?? null;
							foreach ( $related_posts as $related_post ) :
								$GLOBALS['post'] = $related_post;
								\setup_postdata( $related_post );
								$thumb = \get_the_post_thumbnail( $related_post->ID, 'medium', [
									'class' => 'service-cpt-archive__related-image',
								] );
								?>
								<article class="service-cpt-archive__related-card">
									<a class="service-cpt-archive__related-media" href="<?php the_permalink(); ?>">
										<?php if ( $thumb ) : ?>
											<?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php else : ?>
											<span class="service-cpt-archive__related-placeholder"></span>
										<?php endif; ?>
									</a>
									<div class="service-cpt-archive__related-body">
										<p class="service-cpt-archive__related-date"><?php echo esc_html( \get_the_date( '', $related_post ) ); ?></p>
										<h4 class="wp-block-heading service-cpt-archive__related-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h4>
									</div>
								</article>
								<?php
							endforeach;
							if ( $previous_post instanceof \WP_Post ) {
								$GLOBALS['post'] = $previous_post;
								\setup_postdata( $previous_post );
							} else {
								\wp_reset_postdata();
							}
							?>
						</div>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Renders the legacy meta-driven layout.
	 */
	private function render_legacy_layout( int $post_id ): string {
		$meta       = $this->get_meta_values( $post_id );
		$components = $this->get_component_settings();
		$hero_cta = $this->get_effective_hero_cta( $meta );
		$sidebar_cta = $this->get_effective_sidebar_cta( $meta );
		$wide_cta = $this->get_effective_wide_cta( $meta );
		$images     = [
			'image_1' => $this->format_media( (int) $meta['sp_image_1'] ),
			'image_2' => $this->format_media( (int) $meta['sp_image_2'] ),
		];

		$template_slug = $this->get_effective_template_slug( $post_id );
		$wrap_style = $this->get_wrap_style_attribute( $template_slug );

		ob_start();
		?>
		<div class="service-cpt__wrap"<?php echo $wrap_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $this->component_enabled( 'hero' ) ) : ?>
				<section class="service-cpt__hero">
					<div class="service-cpt__hero-inner">
						<?php if ( $meta['sp_hero_eyebrow'] ) : ?>
							<p class="service-cpt__eyebrow"><?php echo esc_html( $meta['sp_hero_eyebrow'] ); ?></p>
						<?php endif; ?>
						<?php if ( $meta['sp_hero_title'] ) : ?>
							<h1 class="service-cpt__title"><?php echo esc_html( $meta['sp_hero_title'] ); ?></h1>
						<?php else : ?>
							<h1 class="service-cpt__title"><?php echo esc_html( \get_the_title( $post_id ) ); ?></h1>
						<?php endif; ?>
						<?php if ( $meta['sp_hero_copy'] ) : ?>
							<div class="service-cpt__lead"><?php echo $this->format_rich_text( $meta['sp_hero_copy'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<?php endif; ?>
						<div class="service-cpt__buttons">
							<?php if ( $hero_cta['primary_label'] && $hero_cta['primary_url'] ) : ?>
								<a class="service-cpt__btn service-cpt__btn--primary" href="<?php echo esc_url( $hero_cta['primary_url'] ); ?>">
									<?php echo esc_html( $hero_cta['primary_label'] ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $hero_cta['secondary_label'] && $hero_cta['secondary_url'] ) : ?>
								<a class="service-cpt__btn service-cpt__btn--ghost" href="<?php echo esc_url( $hero_cta['secondary_url'] ); ?>">
									<?php echo esc_html( $hero_cta['secondary_label'] ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $this->component_enabled( 'intro' ) && $meta['sp_intro'] ) : ?>
				<section class="service-cpt__section service-cpt__intro">
					<?php echo $this->format_rich_text( $meta['sp_intro'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
			<?php endif; ?>

			<?php if ( $this->component_enabled( 'main' ) ) : ?>
				<section class="service-cpt__section service-cpt__content">
					<div class="service-cpt__content-main">
						<?php foreach ( [ 'sp_main_1', 'sp_main_2', 'sp_main_3' ] as $index => $key ) : ?>
							<?php if ( ! empty( $meta[ $key ] ) ) : ?>
								<div class="service-cpt__block">
									<?php
									$heading_key = 'sp_main_' . ( $index + 1 ) . '_heading';
									$heading = $meta[ $heading_key ] ?? '';
									if ( '' === \trim( (string) $heading ) ) {
										/* translators: %d: section number. */
										$heading = sprintf( __( 'Section %d', 'nova-bridge-suite' ), $index + 1 );
									}
									?>
									<h2><?php echo esc_html( $heading ); ?></h2>
									<?php echo $this->format_rich_text( $meta[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>

						<?php if ( $this->table_has_content( $meta['sp_table'] ) ) : ?>
							<div class="service-cpt__table-wrap">
								<table class="service-cpt__table">
									<tbody>
										<?php foreach ( $meta['sp_table'] as $row_index => $row ) : ?>
											<?php if ( ! is_array( $row ) ) : ?>
												<?php continue; ?>
											<?php endif; ?>
											<tr>
												<?php foreach ( $row as $cell ) : ?>
													<?php
													$cell_tag = 0 === $row_index ? 'th' : 'td';
													printf(
														'<%1$s>%2$s</%1$s>',
														esc_html( $cell_tag ),
														esc_html( (string) $cell )
													);
													?>
												<?php endforeach; ?>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $images['image_1']['url'] ) ) : ?>
							<figure class="service-cpt__figure">
								<img src="<?php echo esc_url( $images['image_1']['url'] ); ?>" alt="<?php echo esc_attr( $images['image_1']['alt'] ); ?>" />
							</figure>
						<?php endif; ?>

						<?php if ( ! empty( $images['image_2']['url'] ) ) : ?>
							<figure class="service-cpt__figure service-cpt__figure--alt">
								<img src="<?php echo esc_url( $images['image_2']['url'] ); ?>" alt="<?php echo esc_attr( $images['image_2']['alt'] ); ?>" />
							</figure>
						<?php endif; ?>
					</div>

					<?php if ( $this->component_enabled( 'sidebar_cta' ) && ( $sidebar_cta['title'] || $sidebar_cta['copy'] ) ) : ?>
						<aside class="service-cpt__sidebar">
							<div class="service-cpt__card">
								<?php if ( $sidebar_cta['title'] ) : ?>
									<h3><?php echo esc_html( $sidebar_cta['title'] ); ?></h3>
								<?php endif; ?>
								<?php if ( $sidebar_cta['copy'] ) : ?>
									<?php echo $this->format_rich_text( $sidebar_cta['copy'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
								<div class="service-cpt__buttons">
									<?php if ( $sidebar_cta['primary_label'] && $sidebar_cta['primary_url'] ) : ?>
										<a class="service-cpt__btn service-cpt__btn--primary" href="<?php echo esc_url( $sidebar_cta['primary_url'] ); ?>">
											<?php echo esc_html( $sidebar_cta['primary_label'] ); ?>
										</a>
									<?php endif; ?>
									<?php if ( $sidebar_cta['secondary_label'] && $sidebar_cta['secondary_url'] ) : ?>
										<a class="service-cpt__btn service-cpt__btn--ghost" href="<?php echo esc_url( $sidebar_cta['secondary_url'] ); ?>">
											<?php echo esc_html( $sidebar_cta['secondary_label'] ); ?>
										</a>
									<?php endif; ?>
								</div>
							</div>
						</aside>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php if ( $this->component_enabled( 'wide_cta' ) && ( $wide_cta['title'] || ! empty( $wide_cta['bullets'] ) ) ) : ?>
				<section class="service-cpt__section service-cpt__cta-wide">
					<div class="service-cpt__cta-inner">
						<?php if ( $wide_cta['title'] ) : ?>
							<h2><?php echo esc_html( $wide_cta['title'] ); ?></h2>
						<?php endif; ?>
						<?php if ( ! empty( $wide_cta['bullets'] ) ) : ?>
							<ul class="service-cpt__bullets">
								<?php foreach ( $wide_cta['bullets'] as $bullet ) : ?>
									<li><?php echo esc_html( $bullet ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="service-cpt__buttons">
							<?php if ( $wide_cta['button_label'] && $wide_cta['button_url'] ) : ?>
								<a class="service-cpt__btn service-cpt__btn--primary" href="<?php echo esc_url( $wide_cta['button_url'] ); ?>">
									<?php echo esc_html( $wide_cta['button_label'] ); ?>
								</a>
							<?php endif; ?>
							<?php if ( $wide_cta['more_text'] && $wide_cta['more_url'] ) : ?>
								<a class="service-cpt__btn service-cpt__btn--link" href="<?php echo esc_url( $wide_cta['more_url'] ); ?>">
									<?php echo esc_html( $wide_cta['more_text'] ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $this->component_enabled( 'faq' ) && ! empty( $meta['sp_faq'] ) ) : ?>
				<section class="service-cpt__section service-cpt__faq">
					<h3><?php esc_html_e( 'Frequently asked questions', 'nova-bridge-suite' ); ?></h3>
					<div class="service-cpt__faq-list">
						<?php foreach ( $meta['sp_faq'] as $item ) : ?>
							<div class="service-cpt__faq-item">
								<?php if ( ! empty( $item['question'] ) ) : ?>
									<h4><?php echo esc_html( $item['question'] ); ?></h4>
								<?php endif; ?>
								<?php if ( ! empty( $item['answer'] ) ) : ?>
									<?php echo $this->format_rich_text( $item['answer'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private function normalize_rendered_content( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		if ( false !== strpos( $content, 'style=' ) ) {
			$content = (string) preg_replace_callback(
				'/style="([^"]*)"/',
				function ( array $matches ): string {
					$style = $this->normalize_style_declarations( (string) $matches[1] );

					if ( '' === $style ) {
						return '';
					}

					return 'style="' . $style . '"';
				},
				$content
			);

			$content = (string) preg_replace_callback(
				'/(<[^>]*class="[^"]*wp-block-spacer[^"]*"[^>]*style=")([^"]*)(")/i',
				function ( array $matches ): string {
					$style = $this->replace_style_height( (string) $matches[2] );

					return $matches[1] . $style . $matches[3];
				},
				$content
			);
		}

		if ( false !== strpos( $content, 'has-' ) ) {
			$content = (string) preg_replace_callback(
				'/class="([^"]*)"/',
				function ( array $matches ): string {
					$classes = preg_split( '/\s+/', (string) $matches[1] );

					if ( ! \is_array( $classes ) ) {
						return $matches[0];
					}

					$filtered = array_values(
						array_filter(
							$classes,
							static function ( string $class ): bool {
								if ( '' === $class ) {
									return false;
								}

								if ( 'has-text-color' === $class || 'has-background' === $class || 'has-link-color' === $class ) {
									return false;
								}

								if ( 1 === preg_match( '/^has-.*-color$/', $class ) ) {
									return false;
								}

								if ( 1 === preg_match( '/^has-.*-background-color$/', $class ) ) {
									return false;
								}

								if ( 1 === preg_match( '/^has-.*-border-color$/', $class ) ) {
									return false;
								}

								return true;
							}
						)
					);

					if ( empty( $filtered ) ) {
						return '';
					}

					return 'class="' . implode( ' ', $filtered ) . '"';
				},
				$content
			);
		}

		return $content;
	}

	private function normalize_style_declarations( string $style ): string {
		$declarations = array_filter( array_map( 'trim', explode( ';', $style ) ) );
		$normalized   = [];
		$spacing_props = [
			'margin',
			'margin-top',
			'margin-right',
			'margin-bottom',
			'margin-left',
			'padding',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'gap',
			'row-gap',
			'column-gap',
		];
		$color_props = [
			'color',
			'background-color',
			'border-color',
			'border-top-color',
			'border-right-color',
			'border-bottom-color',
			'border-left-color',
		];

		foreach ( $declarations as $declaration ) {
			$parts = explode( ':', $declaration, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$property = strtolower( trim( $parts[0] ) );
			$value    = trim( $parts[1] );

			if ( '' === $property || '' === $value ) {
				continue;
			}

			if ( in_array( $property, $color_props, true ) ) {
				continue;
			}

			if ( 'background' === $property && false === stripos( $value, 'url(' ) ) {
				continue;
			}

			if ( in_array( $property, $spacing_props, true ) ) {
				$value = $this->normalize_spacing_value( $value );
			}

			$normalized[] = $property . ':' . $value;
		}

		return implode( ';', $normalized );
	}

	private function replace_style_height( string $style ): string {
		$declarations = array_filter( array_map( 'trim', explode( ';', $style ) ) );
		$normalized   = [];

		foreach ( $declarations as $declaration ) {
			$parts = explode( ':', $declaration, 2 );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$property = strtolower( trim( $parts[0] ) );
			$value    = trim( $parts[1] );

			if ( 'height' === $property ) {
				$value = $this->normalize_spacing_value( $value );
			}

			$normalized[] = $property . ':' . $value;
		}

		return implode( ';', $normalized );
	}

	private function normalize_spacing_value( string $value ): string {
		if ( '' === $value || false !== strpos( $value, '--service-cpt-space-scale' ) ) {
			return $value;
		}

		$important = '';

		if ( false !== stripos( $value, '!important' ) ) {
			$value     = trim( str_ireplace( '!important', '', $value ) );
			$important = ' !important';
		}

		$tokens = $this->split_css_value_tokens( $value );
		$scaled = [];

		foreach ( $tokens as $token ) {
			$scaled[] = $this->scale_spacing_token( $token );
		}

		return trim( implode( ' ', $scaled ) ) . $important;
	}

	private function split_css_value_tokens( string $value ): array {
		$tokens = [];
		$buffer = '';
		$depth  = 0;
		$length = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];

			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char && $depth > 0 ) {
				$depth--;
			}

			if ( 0 === $depth && ctype_space( $char ) ) {
				if ( '' !== $buffer ) {
					$tokens[] = $buffer;
					$buffer   = '';
				}
				continue;
			}

			$buffer .= $char;
		}

		if ( '' !== $buffer ) {
			$tokens[] = $buffer;
		}

		return $tokens;
	}

	private function scale_spacing_token( string $token ): string {
		$trimmed = trim( $token );

		if ( '' === $trimmed ) {
			return $trimmed;
		}

		$lower = strtolower( $trimmed );

		if ( in_array( $lower, [ '0', '0px', '0rem', '0em', '0vh', '0vw', '0%' ], true ) ) {
			return $trimmed;
		}

		if ( in_array( $lower, [ 'auto', 'inherit', 'initial', 'unset', 'normal' ], true ) ) {
			return $trimmed;
		}

		if ( preg_match( '/^(calc|clamp|min|max)\(/', $lower ) ) {
			return $trimmed;
		}

		if ( '' !== $lower && '%' === substr( $lower, -1 ) ) {
			return $trimmed;
		}

		if ( 0 === preg_match( '/^var\\(.*\\)$|^-?\\d*\\.?\\d+[a-zA-Z]+$/', $trimmed ) ) {
			return $trimmed;
		}

		return sprintf( 'calc(%s * var(--service-cpt-space-scale, 1))', $trimmed );
	}

	/**
	 * Collects meta values with defaults.
	 */
	private function get_meta_values( int $post_id ): array {
		$meta = [];

		foreach ( $this->get_meta_definitions() as $key => $definition ) {
			$value = \get_post_meta( $post_id, $key, true );

			switch ( $definition['type'] ) {
				case 'integer':
					$value = (int) $value;
					break;
				case 'array':
					$value = \is_array( $value ) ? $value : [];
					break;
				default:
					$value = (string) $value;
					break;
			}

			$meta[ $key ] = $value;
		}

		return $meta;
	}

	private function get_global_text_option( string $option ): string {
		return \sanitize_text_field( (string) \get_option( $option, '' ) );
	}

	private function get_global_textarea_option( string $option ): string {
		return \wp_kses_post( (string) \get_option( $option, '' ) );
	}

	private function get_global_url_option( string $option ): string {
		return \esc_url_raw( (string) \get_option( $option, '' ) );
	}

	private function get_global_hero_cta(): array {
		return [
			'primary_label'   => $this->get_global_text_option( self::OPTION_GLOBAL_HERO_PRIMARY_LABEL ),
			'primary_url'     => $this->get_global_url_option( self::OPTION_GLOBAL_HERO_PRIMARY_URL ),
			'secondary_label' => $this->get_global_text_option( self::OPTION_GLOBAL_HERO_SECONDARY_LABEL ),
			'secondary_url'   => $this->get_global_url_option( self::OPTION_GLOBAL_HERO_SECONDARY_URL ),
		];
	}

	private function get_global_sidebar_cta(): array {
		return [
			'title'           => $this->get_global_text_option( self::OPTION_GLOBAL_SIDEBAR_TITLE ),
			'copy'            => $this->get_global_textarea_option( self::OPTION_GLOBAL_SIDEBAR_COPY ),
			'primary_label'   => $this->get_global_text_option( self::OPTION_GLOBAL_SIDEBAR_PRIMARY_LABEL ),
			'primary_url'     => $this->get_global_url_option( self::OPTION_GLOBAL_SIDEBAR_PRIMARY_URL ),
			'secondary_label' => $this->get_global_text_option( self::OPTION_GLOBAL_SIDEBAR_SECONDARY_LABEL ),
			'secondary_url'   => $this->get_global_url_option( self::OPTION_GLOBAL_SIDEBAR_SECONDARY_URL ),
		];
	}

	private function get_global_wide_cta(): array {
		$bullets = [
			$this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_1 ),
			$this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_2 ),
			$this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_3 ),
		];
		$bullets = self::sanitize_string_array( $bullets );

		return [
			'title'        => $this->get_global_text_option( self::OPTION_GLOBAL_CTA_TITLE ),
			'bullets'      => $bullets,
			'button_label' => $this->get_global_text_option( self::OPTION_GLOBAL_CTA_BUTTON_LABEL ),
			'button_url'   => $this->get_global_url_option( self::OPTION_GLOBAL_CTA_BUTTON_URL ),
			'more_text'    => $this->get_global_text_option( self::OPTION_GLOBAL_CTA_MORE_TEXT ),
			'more_url'     => $this->get_global_url_option( self::OPTION_GLOBAL_CTA_MORE_URL ),
		];
	}

	private function get_archive_hero_cta(): array {
		$global = $this->get_global_hero_cta();
		if ( $this->cta_has_content( $global ) ) {
			$label = (string) ( $global['primary_label'] ?? '' );
			$url = (string) ( $global['primary_url'] ?? '' );

			if ( '' === \trim( $label ) && '' === \trim( $url ) ) {
				$label = (string) ( $global['secondary_label'] ?? '' );
				$url = (string) ( $global['secondary_url'] ?? '' );
			}

			return [
				'label' => $label,
				'url'   => $url,
			];
		}

		$label = $this->get_archive_text_option( self::OPTION_ARCHIVE_HERO_CTA_LABEL );
		$url = $this->get_archive_url_option( self::OPTION_ARCHIVE_HERO_CTA_URL );

		return [
			'label' => $label,
			'url'   => $url,
		];
	}

	private function get_archive_wide_cta(): array {
		$global = $this->get_global_wide_cta();
		if ( $this->cta_has_content( $global ) ) {
			return $global;
		}

		$bullets = [
			$this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_1 ),
			$this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_2 ),
			$this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_3 ),
		];

		$bullets = self::sanitize_string_array( $bullets );

		$title = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_TITLE );
		$button_label = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BUTTON_LABEL );
		$button_url = $this->get_archive_url_option( self::OPTION_ARCHIVE_CTA_BUTTON_URL );
		$more_text = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_MORE_TEXT );
		$more_url = $this->get_archive_url_option( self::OPTION_ARCHIVE_CTA_MORE_URL );

		$archive_cta = [
			'title'        => $title,
			'bullets'      => $bullets,
			'button_label' => $button_label,
			'button_url'   => $button_url,
			'more_text'    => $more_text,
			'more_url'     => $more_url,
		];

		return $archive_cta;
	}

	private function cta_has_content( array $cta ): bool {
		foreach ( $cta as $value ) {
			if ( \is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					return true;
				}
				continue;
			}

			if ( '' !== \trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	private function build_sidebar_cta_from_meta( array $meta ): array {
		return [
			'title'           => (string) ( $meta['sp_sidebar_title'] ?? '' ),
			'copy'            => (string) ( $meta['sp_sidebar_copy'] ?? '' ),
			'primary_label'   => (string) ( $meta['sp_sidebar_primary_label'] ?? '' ),
			'primary_url'     => (string) ( $meta['sp_sidebar_primary_url'] ?? '' ),
			'secondary_label' => (string) ( $meta['sp_sidebar_secondary_label'] ?? '' ),
			'secondary_url'   => (string) ( $meta['sp_sidebar_secondary_url'] ?? '' ),
		];
	}

	private function build_wide_cta_from_meta( array $meta ): array {
		$bullets = $meta['sp_cta_bullets'] ?? [];
		if ( ! \is_array( $bullets ) ) {
			$bullets = [];
		}
		$bullets = self::sanitize_string_array( $bullets );

		return [
			'title'        => (string) ( $meta['sp_cta_title'] ?? '' ),
			'bullets'      => $bullets,
			'button_label' => (string) ( $meta['sp_cta_button_label'] ?? '' ),
			'button_url'   => (string) ( $meta['sp_cta_button_url'] ?? '' ),
			'more_text'    => (string) ( $meta['sp_cta_more_text'] ?? '' ),
			'more_url'     => (string) ( $meta['sp_cta_more_url'] ?? '' ),
		];
	}

	private function get_effective_hero_cta( array $meta ): array {
		$global = $this->get_global_hero_cta();

		if ( $this->cta_has_content( $global ) ) {
			return $global;
		}

		return [
			'primary_label'   => (string) ( $meta['sp_hero_primary_label'] ?? '' ),
			'primary_url'     => (string) ( $meta['sp_hero_primary_url'] ?? '' ),
			'secondary_label' => (string) ( $meta['sp_hero_secondary_label'] ?? '' ),
			'secondary_url'   => (string) ( $meta['sp_hero_secondary_url'] ?? '' ),
		];
	}

	private function get_effective_sidebar_cta( array $meta ): array {
		$global = $this->get_global_sidebar_cta();

		if ( $this->cta_has_content( $global ) ) {
			return $global;
		}

		return $this->build_sidebar_cta_from_meta( $meta );
	}

	private function get_effective_wide_cta( array $meta ): array {
		$global = $this->get_global_wide_cta();

		if ( $this->cta_has_content( $global ) ) {
			return $global;
		}

		return $this->build_wide_cta_from_meta( $meta );
	}

	/**
	 * Formats media for API and rendering.
	 */
	private function format_media( int $attachment_id ): array {
		if ( $attachment_id <= 0 ) {
			return [
				'id'  => 0,
				'url' => '',
				'alt' => '',
			];
		}

		$src = \wp_get_attachment_image_src( $attachment_id, 'large' );
		$alt = \get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );

		return [
			'id'  => $attachment_id,
			'url' => $src ? $src[0] : '',
			'alt' => $alt ? (string) $alt : '',
		];
	}

	/**
	 * Registers a simple settings page for defaults and toggles.
	 */
	public function register_settings_page(): void {
		\add_options_page(
			__( 'NOVA Services Settings', 'nova-bridge-suite' ),
			__( 'NOVA Services Settings', 'nova-bridge-suite' ),
			'manage_options',
			'service-cpt',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Adds a CPT submenu entry pointing to the settings page.
	 */
	public function register_cpt_settings_submenu(): void {
		\add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			__( 'NOVA Services Settings', 'nova-bridge-suite' ),
			__( 'NOVA Services Settings', 'nova-bridge-suite' ),
			'manage_options',
			'service-cpt',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings(): void {
		\register_setting( 'service-cpt', self::OPTION_SLUG, [
			'sanitize_callback' => 'sanitize_title_with_dashes',
		] );
		\register_setting( 'service-cpt', self::OPTION_SINGULAR, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_PLURAL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_HEADER_OFFSET, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_TEMPLATE, [
			'sanitize_callback' => [ $this, 'sanitize_template_option' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_PRIMARY, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_CONTRAST, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_SURFACE, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_ACCENT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_BORDER, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_HERO_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_HERO_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_CTA_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_CTA_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_BUTTON_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_BUTTON_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_BUTTON_OUTLINE, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_FAQ_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_FAQ_QUESTION, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_FAQ_ANSWER, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TABS_ACTIVE_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TABS_ACTIVE_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TABS_INACTIVE_BG, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TABS_INACTIVE_TEXT, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COLOR_TABS_BORDER, [
			'sanitize_callback' => [ $this, 'sanitize_color_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_SPACE_SCALE, [
			'sanitize_callback' => [ $this, 'sanitize_spacing_scale' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_SPACE_SECTION_PADDING, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_SPACE_SECTION_GAP, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_SPACE_CARD_PADDING, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_CONTENT_WIDTH, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
			'default' => self::DEFAULT_CONTENT_WIDTH,
		] );
		\register_setting( 'service-cpt', self::OPTION_WIDE_WIDTH, [
			'sanitize_callback' => [ $this, 'sanitize_length_value' ],
			'default' => self::DEFAULT_WIDE_WIDTH,
		] );
		\register_setting( 'service-cpt', self::OPTION_EXCLUDE_SELECTORS, [
			'sanitize_callback' => [ self::class, 'sanitize_exclude_selectors' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_COMPONENTS, [
			'sanitize_callback' => [ self::class, 'sanitize_components_option' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_TEMPLATE_COMPONENTS, [
			'sanitize_callback' => [ self::class, 'sanitize_template_components_option' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_LABEL_FAQ, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_LABEL_RELATED, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_HERO_PRIMARY_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_HERO_PRIMARY_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_HERO_SECONDARY_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_HERO_SECONDARY_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_TITLE, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_COPY, [
			'sanitize_callback' => 'wp_kses_post',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_PRIMARY_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_PRIMARY_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_SECONDARY_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_SIDEBAR_SECONDARY_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_TITLE, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_BULLET_1, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_BULLET_2, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_BULLET_3, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_BUTTON_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_BUTTON_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_MORE_TEXT, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_GLOBAL_CTA_MORE_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HERO_EYEBROW, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HERO_TITLE, [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HERO_COPY, [
			'sanitize_callback' => 'wp_kses_post',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HERO_CTA_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HERO_CTA_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_INTRO_HEADING, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_INTRO_COPY, [
			'sanitize_callback' => 'wp_kses_post',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CARD_CTA_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CARD_PLACEHOLDER, [
			'sanitize_callback' => 'absint',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_SERVICES_MODE, [
			'sanitize_callback' => [ $this, 'sanitize_archive_service_mode' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_SERVICES_LIMIT, [
			'sanitize_callback' => 'absint',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_SERVICES_IDS, [
			'sanitize_callback' => [ self::class, 'sanitize_post_ids' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HIGHLIGHTS_HEADING, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE, [
			'sanitize_callback' => 'absint',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HIGHLIGHT_ONE_COPY, [
			'sanitize_callback' => 'wp_kses_post',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE, [
			'sanitize_callback' => 'absint',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_HIGHLIGHT_TWO_COPY, [
			'sanitize_callback' => 'wp_kses_post',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_TITLE, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_BULLET_1, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_BULLET_2, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_BULLET_3, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_BUTTON_LABEL, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_BUTTON_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_MORE_TEXT, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_CTA_MORE_URL, [
			'sanitize_callback' => 'esc_url_raw',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_FAQ, [
			'sanitize_callback' => [ self::class, 'sanitize_faq' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_RELATED_POSTS, [
			'sanitize_callback' => [ self::class, 'sanitize_post_ids' ],
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_SEO_TITLE, [
			'sanitize_callback' => 'sanitize_text_field',
		] );
		\register_setting( 'service-cpt', self::OPTION_ARCHIVE_SEO_DESCRIPTION, [
			'sanitize_callback' => 'sanitize_textarea_field',
		] );
	}

	private function get_color_presets(): array {
		return [
			'modern-slate' => [
				'label'  => __( 'Modern Slate', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_COLOR_PRIMARY           => '#0F172A',
					self::OPTION_COLOR_CONTRAST          => '#F8FAFC',
					self::OPTION_COLOR_SURFACE           => '#F8FAFC',
					self::OPTION_COLOR_TEXT              => '#0F172A',
					self::OPTION_COLOR_ACCENT            => '#2563EB',
					self::OPTION_COLOR_BORDER            => '#E2E8F0',
					self::OPTION_COLOR_HERO_BG           => '#0F172A',
					self::OPTION_COLOR_HERO_TEXT         => '#F8FAFC',
					self::OPTION_COLOR_CTA_BG            => '#111827',
					self::OPTION_COLOR_CTA_TEXT          => '#F8FAFC',
					self::OPTION_COLOR_BUTTON_BG         => '#2563EB',
					self::OPTION_COLOR_BUTTON_TEXT       => '#FFFFFF',
					self::OPTION_COLOR_BUTTON_OUTLINE    => '#2563EB',
					self::OPTION_COLOR_FAQ_BG            => '#F1F5F9',
					self::OPTION_COLOR_FAQ_QUESTION      => '#0F172A',
					self::OPTION_COLOR_FAQ_ANSWER        => '#475569',
					self::OPTION_COLOR_TABS_ACTIVE_BG    => '#2563EB',
					self::OPTION_COLOR_TABS_ACTIVE_TEXT  => '#FFFFFF',
					self::OPTION_COLOR_TABS_INACTIVE_BG  => '#F1F5F9',
					self::OPTION_COLOR_TABS_INACTIVE_TEXT => '#0F172A',
					self::OPTION_COLOR_TABS_BORDER       => '#CBD5E1',
				],
			],
			'dark-studio' => [
				'label'  => __( 'Dark Studio', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_COLOR_PRIMARY           => '#0F172A',
					self::OPTION_COLOR_CONTRAST          => '#F8FAFC',
					self::OPTION_COLOR_SURFACE           => '#111827',
					self::OPTION_COLOR_TEXT              => '#E5E7EB',
					self::OPTION_COLOR_ACCENT            => '#F59E0B',
					self::OPTION_COLOR_BORDER            => '#334155',
					self::OPTION_COLOR_HERO_BG           => '#0B0F1A',
					self::OPTION_COLOR_HERO_TEXT         => '#F8FAFC',
					self::OPTION_COLOR_CTA_BG            => '#1F2937',
					self::OPTION_COLOR_CTA_TEXT          => '#F8FAFC',
					self::OPTION_COLOR_BUTTON_BG         => '#F59E0B',
					self::OPTION_COLOR_BUTTON_TEXT       => '#0F172A',
					self::OPTION_COLOR_BUTTON_OUTLINE    => '#F59E0B',
					self::OPTION_COLOR_FAQ_BG            => '#F59E0B',
					self::OPTION_COLOR_FAQ_QUESTION      => '#FFFFFF',
					self::OPTION_COLOR_FAQ_ANSWER        => '#FFFFFF',
					self::OPTION_COLOR_TABS_ACTIVE_BG    => '#F59E0B',
					self::OPTION_COLOR_TABS_ACTIVE_TEXT  => '#0F172A',
					self::OPTION_COLOR_TABS_INACTIVE_BG  => '#111827',
					self::OPTION_COLOR_TABS_INACTIVE_TEXT => '#E5E7EB',
					self::OPTION_COLOR_TABS_BORDER       => '#334155',
				],
			],
			'light-minimal' => [
				'label'  => __( 'Light Minimal', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_COLOR_PRIMARY           => '#1E293B',
					self::OPTION_COLOR_CONTRAST          => '#F8FAFC',
					self::OPTION_COLOR_SURFACE           => '#FFFFFF',
					self::OPTION_COLOR_TEXT              => '#111827',
					self::OPTION_COLOR_ACCENT            => '#2563EB',
					self::OPTION_COLOR_BORDER            => '#E5E7EB',
					self::OPTION_COLOR_HERO_BG           => '#F8FAFC',
					self::OPTION_COLOR_HERO_TEXT         => '#0F172A',
					self::OPTION_COLOR_CTA_BG            => '#EAF2FF',
					self::OPTION_COLOR_CTA_TEXT          => '#0F172A',
					self::OPTION_COLOR_BUTTON_BG         => '#FFFFFF',
					self::OPTION_COLOR_BUTTON_TEXT       => '#111827',
					self::OPTION_COLOR_BUTTON_OUTLINE    => '#111827',
					self::OPTION_COLOR_FAQ_BG            => '#F3F4F6',
					self::OPTION_COLOR_FAQ_QUESTION      => '#111827',
					self::OPTION_COLOR_FAQ_ANSWER        => '#6B7280',
					self::OPTION_COLOR_TABS_ACTIVE_BG    => '#2563EB',
					self::OPTION_COLOR_TABS_ACTIVE_TEXT  => '#FFFFFF',
					self::OPTION_COLOR_TABS_INACTIVE_BG  => '#F3F4F6',
					self::OPTION_COLOR_TABS_INACTIVE_TEXT => '#111827',
					self::OPTION_COLOR_TABS_BORDER       => '#E5E7EB',
				],
			],
			'tech-cyan' => [
				'label'  => __( 'Tech Cyan', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_COLOR_PRIMARY           => '#0B1220',
					self::OPTION_COLOR_CONTRAST          => '#E2E8F0',
					self::OPTION_COLOR_SURFACE           => '#05080F',
					self::OPTION_COLOR_TEXT              => '#E2E8F0',
					self::OPTION_COLOR_ACCENT            => '#22D3EE',
					self::OPTION_COLOR_BORDER            => '#1E293B',
					self::OPTION_COLOR_HERO_BG           => '#0B1220',
					self::OPTION_COLOR_HERO_TEXT         => '#E2E8F0',
					self::OPTION_COLOR_CTA_BG            => '#111827',
					self::OPTION_COLOR_CTA_TEXT          => '#E2E8F0',
					self::OPTION_COLOR_BUTTON_BG         => '#22D3EE',
					self::OPTION_COLOR_BUTTON_TEXT       => '#0B1220',
					self::OPTION_COLOR_BUTTON_OUTLINE    => '#22D3EE',
					self::OPTION_COLOR_FAQ_BG            => '#0F172A',
					self::OPTION_COLOR_FAQ_QUESTION      => '#E2E8F0',
					self::OPTION_COLOR_FAQ_ANSWER        => '#94A3B8',
					self::OPTION_COLOR_TABS_ACTIVE_BG    => '#22D3EE',
					self::OPTION_COLOR_TABS_ACTIVE_TEXT  => '#0B1220',
					self::OPTION_COLOR_TABS_INACTIVE_BG  => '#1E293B',
					self::OPTION_COLOR_TABS_INACTIVE_TEXT => '#E2E8F0',
					self::OPTION_COLOR_TABS_BORDER       => '#334155',
				],
			],
			'cozy-sand' => [
				'label'  => __( 'Cozy Sand', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_COLOR_PRIMARY           => '#7A4E2D',
					self::OPTION_COLOR_CONTRAST          => '#FFF7ED',
					self::OPTION_COLOR_SURFACE           => '#FFF7ED',
					self::OPTION_COLOR_TEXT              => '#3F2A1D',
					self::OPTION_COLOR_ACCENT            => '#D97706',
					self::OPTION_COLOR_BORDER            => '#E7D8C9',
					self::OPTION_COLOR_HERO_BG           => '#7A4E2D',
					self::OPTION_COLOR_HERO_TEXT         => '#FFF7ED',
					self::OPTION_COLOR_CTA_BG            => '#FDEAD7',
					self::OPTION_COLOR_CTA_TEXT          => '#3F2A1D',
					self::OPTION_COLOR_BUTTON_BG         => '#D97706',
					self::OPTION_COLOR_BUTTON_TEXT       => '#FFFFFF',
					self::OPTION_COLOR_BUTTON_OUTLINE    => '#D97706',
					self::OPTION_COLOR_FAQ_BG            => '#FDEAD7',
					self::OPTION_COLOR_FAQ_QUESTION      => '#3F2A1D',
					self::OPTION_COLOR_FAQ_ANSWER        => '#7A5C3E',
					self::OPTION_COLOR_TABS_ACTIVE_BG    => '#D97706',
					self::OPTION_COLOR_TABS_ACTIVE_TEXT  => '#FFFFFF',
					self::OPTION_COLOR_TABS_INACTIVE_BG  => '#F3E7D7',
					self::OPTION_COLOR_TABS_INACTIVE_TEXT => '#3F2A1D',
					self::OPTION_COLOR_TABS_BORDER       => '#DFCAB6',
				],
			],
		];
	}

	private function get_spacing_presets(): array {
		return [
			'compact' => [
				'label'  => __( 'Compact', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_SPACE_SCALE           => '0.9',
					self::OPTION_SPACE_SECTION_PADDING => '3rem 1.25rem',
					self::OPTION_SPACE_SECTION_GAP     => '18px',
					self::OPTION_SPACE_CARD_PADDING    => '18px',
				],
			],
			'balanced' => [
				'label'  => __( 'Balanced', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_SPACE_SCALE           => '1',
					self::OPTION_SPACE_SECTION_PADDING => '3.5rem 1.5rem',
					self::OPTION_SPACE_SECTION_GAP     => '24px',
					self::OPTION_SPACE_CARD_PADDING    => '22px',
				],
			],
			'spacious' => [
				'label'  => __( 'Spacious', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_SPACE_SCALE           => '1.15',
					self::OPTION_SPACE_SECTION_PADDING => '5rem 2rem',
					self::OPTION_SPACE_SECTION_GAP     => '30px',
					self::OPTION_SPACE_CARD_PADDING    => '28px',
				],
			],
			'airy' => [
				'label'  => __( 'Airy', 'nova-bridge-suite' ),
				'values' => [
					self::OPTION_SPACE_SCALE           => '1.25',
					self::OPTION_SPACE_SECTION_PADDING => '6rem 2.5rem',
					self::OPTION_SPACE_SECTION_GAP     => '36px',
					self::OPTION_SPACE_CARD_PADDING    => '32px',
				],
			],
		];
	}

	private function get_settings_payload(): array {
		return [
			'colorPresets'    => $this->get_color_presets(),
			'spacingPresets'  => $this->get_spacing_presets(),
		];
	}

	public function render_settings_page(): void {
		$templates  = $this->get_templates();
		$selected_template = $this->get_selected_template_slug();
		$missing_required = $this->collect_missing_plugins( self::REQUIRED_PLUGINS );
		$settings_payload = $this->get_settings_payload();
		$color_presets = $settings_payload['colorPresets'] ?? [];
		$spacing_presets = $settings_payload['spacingPresets'] ?? [];
		$faq_label = $this->get_faq_heading_label();
		$related_label = $this->get_related_heading_label();
		$global_hero = $this->get_global_hero_cta();
		$global_sidebar = $this->get_global_sidebar_cta();
		$global_wide = $this->get_global_wide_cta();
		$global_wide_bullet_1 = $this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_1 );
		$global_wide_bullet_2 = $this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_2 );
		$global_wide_bullet_3 = $this->get_global_text_option( self::OPTION_GLOBAL_CTA_BULLET_3 );
		$archive_hero_eyebrow = $this->get_archive_text_option( self::OPTION_ARCHIVE_HERO_EYEBROW );
		$archive_hero_title = $this->get_archive_multiline_option( self::OPTION_ARCHIVE_HERO_TITLE );
		$archive_hero_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HERO_COPY );
		$archive_hero_cta_label = $this->get_archive_text_option( self::OPTION_ARCHIVE_HERO_CTA_LABEL );
		$archive_hero_cta_url = $this->get_archive_url_option( self::OPTION_ARCHIVE_HERO_CTA_URL );
		$archive_intro_heading = $this->get_archive_text_option( self::OPTION_ARCHIVE_INTRO_HEADING );
		$archive_intro_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_INTRO_COPY );
		$archive_card_cta_label = $this->get_archive_text_option( self::OPTION_ARCHIVE_CARD_CTA_LABEL );
		$archive_card_placeholder = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_CARD_PLACEHOLDER, 0 ) );
		$archive_service_mode = $this->get_archive_service_mode();
		$archive_service_limit = $this->get_archive_int_option( self::OPTION_ARCHIVE_SERVICES_LIMIT );
		$archive_service_ids = $this->get_archive_service_ids();
		$archive_highlights_heading = $this->get_archive_text_option( self::OPTION_ARCHIVE_HIGHLIGHTS_HEADING );
		$archive_highlight_one_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_COPY );
		$archive_highlight_two_copy = $this->get_archive_rich_text_option( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_COPY );
		$archive_highlight_one_media = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE, 0 ) );
		$archive_highlight_two_media = $this->format_media( (int) \get_option( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE, 0 ) );
		$archive_cta_title = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_TITLE );
		$archive_cta_bullet_1 = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_1 );
		$archive_cta_bullet_2 = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_2 );
		$archive_cta_bullet_3 = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BULLET_3 );
		$archive_cta_button_label = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_BUTTON_LABEL );
		$archive_cta_button_url = $this->get_archive_url_option( self::OPTION_ARCHIVE_CTA_BUTTON_URL );
		$archive_cta_more_text = $this->get_archive_text_option( self::OPTION_ARCHIVE_CTA_MORE_TEXT );
		$archive_cta_more_url = $this->get_archive_url_option( self::OPTION_ARCHIVE_CTA_MORE_URL );
		$archive_seo_title = $this->get_archive_text_option( self::OPTION_ARCHIVE_SEO_TITLE );
		$archive_seo_description = $this->get_archive_multiline_option( self::OPTION_ARCHIVE_SEO_DESCRIPTION );
		$archive_faq_items = $this->get_archive_faq_items();
		$archive_related_ids = $this->get_archive_related_post_ids();
		$archive_service_posts = \get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );
		$archive_service_titles = [];
		foreach ( $archive_service_posts as $service_post ) {
			$archive_service_titles[ (int) $service_post->ID ] = \get_the_title( $service_post );
		}
		$archive_selected_services = [];
		foreach ( $archive_service_ids as $service_id ) {
			$title = $archive_service_titles[ $service_id ] ?? \get_the_title( $service_id );
			$archive_selected_services[] = [
				'id'    => $service_id,
				'title' => $title ? (string) $title : __( '(no title)', 'nova-bridge-suite' ),
			];
		}
		$archive_related_posts = \get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		] );
		$color_groups = [
			__( 'Base palette', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_PRIMARY  => __( 'Primary', 'nova-bridge-suite' ),
				self::OPTION_COLOR_CONTRAST => __( 'Primary contrast', 'nova-bridge-suite' ),
				self::OPTION_COLOR_SURFACE  => __( 'Surface', 'nova-bridge-suite' ),
				self::OPTION_COLOR_TEXT     => __( 'Text', 'nova-bridge-suite' ),
				self::OPTION_COLOR_ACCENT   => __( 'Accent', 'nova-bridge-suite' ),
				self::OPTION_COLOR_BORDER   => __( 'Border', 'nova-bridge-suite' ),
			],
			__( 'Hero', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_HERO_BG   => __( 'Hero background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_HERO_TEXT => __( 'Hero text', 'nova-bridge-suite' ),
			],
			__( 'CTA', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_CTA_BG   => __( 'CTA background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_CTA_TEXT => __( 'CTA text', 'nova-bridge-suite' ),
			],
			__( 'Buttons', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_BUTTON_BG      => __( 'Button background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_BUTTON_TEXT    => __( 'Button text', 'nova-bridge-suite' ),
				self::OPTION_COLOR_BUTTON_OUTLINE => __( 'Button outline', 'nova-bridge-suite' ),
			],
			__( 'FAQ', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_FAQ_BG       => __( 'Background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_FAQ_QUESTION => __( 'Question text', 'nova-bridge-suite' ),
				self::OPTION_COLOR_FAQ_ANSWER   => __( 'Answer text', 'nova-bridge-suite' ),
			],
			__( 'Tabs', 'nova-bridge-suite' ) => [
				self::OPTION_COLOR_TABS_ACTIVE_BG     => __( 'Active background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_TABS_ACTIVE_TEXT   => __( 'Active text', 'nova-bridge-suite' ),
				self::OPTION_COLOR_TABS_INACTIVE_BG   => __( 'Inactive background', 'nova-bridge-suite' ),
				self::OPTION_COLOR_TABS_INACTIVE_TEXT => __( 'Inactive text', 'nova-bridge-suite' ),
				self::OPTION_COLOR_TABS_BORDER        => __( 'Border', 'nova-bridge-suite' ),
			],
		];
		$spacing_fields = [
			self::OPTION_SPACE_SECTION_PADDING => [
				'label'       => __( 'Section padding', 'nova-bridge-suite' ),
				'placeholder' => __( 'e.g. 6rem 1.5rem', 'nova-bridge-suite' ),
				'description' => __( 'Overrides hero/CTA section padding. Leave empty to keep template spacing.', 'nova-bridge-suite' ),
			],
			self::OPTION_SPACE_SECTION_GAP => [
				'label'       => __( 'Section gap', 'nova-bridge-suite' ),
				'placeholder' => __( 'e.g. 24px', 'nova-bridge-suite' ),
				'description' => __( 'Overrides gaps between stacked elements in a section.', 'nova-bridge-suite' ),
			],
			self::OPTION_SPACE_CARD_PADDING => [
				'label'       => __( 'Card padding', 'nova-bridge-suite' ),
				'placeholder' => __( 'e.g. 20px', 'nova-bridge-suite' ),
				'description' => __( 'Overrides padding on card-like blocks.', 'nova-bridge-suite' ),
			],
		];
		$layout_fields = [
			self::OPTION_CONTENT_WIDTH => [
				'label'       => __( 'Content width', 'nova-bridge-suite' ),
				'placeholder' => __( 'e.g. 1200px or var(--wp--style--global--content-size)', 'nova-bridge-suite' ),
				'description' => __( 'Caps full-width blocks to your theme content width.', 'nova-bridge-suite' ),
			],
			self::OPTION_WIDE_WIDTH => [
				'label'       => __( 'Wide width', 'nova-bridge-suite' ),
				'placeholder' => __( 'e.g. 1400px or var(--wp--style--global--wide-size)', 'nova-bridge-suite' ),
				'description' => __( 'Optional width for wide-aligned blocks when used.', 'nova-bridge-suite' ),
			],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NOVA Services Settings', 'nova-bridge-suite' ); ?></h1>
			<?php if ( ! empty( $missing_required ) ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'This screen is locked until the required plugins are installed and active.', 'nova-bridge-suite' ); ?></p>
					<p><strong><?php esc_html_e( 'Required:', 'nova-bridge-suite' ); ?></strong></p>
					<ul>
						<?php foreach ( $missing_required as $slug => $plugin ) : ?>
							<li>
								<?php echo esc_html( $plugin['name'] ); ?>
								<a class="button button-primary" href="<?php echo esc_url( $this->get_dependency_action_url( $slug ) ); ?>">
									<?php echo esc_html( $plugin['installed'] ? __( 'Activate', 'nova-bridge-suite' ) : __( 'Install & Activate', 'nova-bridge-suite' ) ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
				</div>
				<?php return; ?>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php \settings_fields( 'service-cpt' ); ?>

				<details class="service-cpt-panel" open>
					<summary><?php esc_html_e( 'General', 'nova-bridge-suite' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Base slug', 'nova-bridge-suite' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_SLUG ); ?>" value="<?php echo esc_attr( $this->get_base_slug() ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Used for rewrites and REST base. Flush permalinks after changing.', 'nova-bridge-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Singular label', 'nova-bridge-suite' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( self::OPTION_SINGULAR ); ?>" value="<?php echo esc_attr( $this->get_singular_name() ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Plural label', 'nova-bridge-suite' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( self::OPTION_PLURAL ); ?>" value="<?php echo esc_attr( $this->get_plural_name() ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Default template', 'nova-bridge-suite' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( self::OPTION_TEMPLATE ); ?>" id="service-cpt-default-template">
									<?php foreach ( $templates as $slug => $template ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected_template, $slug ); ?>>
											<?php echo esc_html( $template['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Applied to new Service Page posts and API inserts when no content is provided.', 'nova-bridge-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'FAQ heading label (H3)', 'nova-bridge-suite' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_LABEL_FAQ ); ?>" value="<?php echo esc_attr( $faq_label ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Shown as the FAQ section heading in the templates.', 'nova-bridge-suite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Related articles label (H3)', 'nova-bridge-suite' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( self::OPTION_LABEL_RELATED ); ?>" value="<?php echo esc_attr( $related_label ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Shown as the Related Articles heading in the templates.', 'nova-bridge-suite' ); ?></p>
							</td>
						</tr>
					</table>
				</details>

				<details class="service-cpt-panel">
					<summary><?php esc_html_e( 'Template styling', 'nova-bridge-suite' ); ?></summary>
					<p class="description"><?php esc_html_e( 'These settings apply globally to the selected template.', 'nova-bridge-suite' ); ?></p>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Colors', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-inline-row">
							<label for="service-cpt-color-preset"><?php esc_html_e( 'Color preset', 'nova-bridge-suite' ); ?></label>
							<select id="service-cpt-color-preset">
								<option value=""><?php esc_html_e( 'Custom', 'nova-bridge-suite' ); ?></option>
								<?php foreach ( $color_presets as $preset_key => $preset ) : ?>
									<option value="<?php echo esc_attr( $preset_key ); ?>">
										<?php echo esc_html( $preset['label'] ?? $preset_key ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<p class="description"><?php esc_html_e( 'Applying a preset fills the color fields for the selected scope.', 'nova-bridge-suite' ); ?></p>
						<?php foreach ( $color_groups as $group_label => $group_options ) : ?>
							<div class="service-cpt-color-group">
								<div class="service-cpt-color-group-title"><?php echo esc_html( $group_label ); ?></div>
								<?php foreach ( $group_options as $option => $label ) : ?>
									<?php
									$color_value = $this->get_color_option( $option );
									$color_hex   = \sanitize_hex_color( $color_value ) ?: '#000000';
									?>
									<div class="service-cpt-field">
										<span class="service-cpt-field-label"><?php echo esc_html( $label ); ?></span>
										<input
											type="color"
											class="service-cpt-color-picker"
											value="<?php echo esc_attr( $color_hex ); ?>"
											data-target="<?php echo esc_attr( $option ); ?>"
										/>
										<input
											type="text"
											name="<?php echo esc_attr( $option ); ?>"
											id="<?php echo esc_attr( $option ); ?>"
											value="<?php echo esc_attr( $color_value ); ?>"
											placeholder="<?php esc_attr_e( 'Leave empty to inherit', 'nova-bridge-suite' ); ?>"
											class="regular-text service-cpt-setting"
											data-option="<?php echo esc_attr( $option ); ?>"
										/>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'Accepts hex colors or CSS variables (e.g. var(--wp--preset--color--primary)).', 'nova-bridge-suite' ); ?>
						</p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Spacing', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-inline-row">
							<label for="service-cpt-spacing-preset"><?php esc_html_e( 'Spacing preset', 'nova-bridge-suite' ); ?></label>
							<select id="service-cpt-spacing-preset">
								<option value=""><?php esc_html_e( 'Custom', 'nova-bridge-suite' ); ?></option>
								<?php foreach ( $spacing_presets as $preset_key => $preset ) : ?>
									<option value="<?php echo esc_attr( $preset_key ); ?>">
										<?php echo esc_html( $preset['label'] ?? $preset_key ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
						<p class="description"><?php esc_html_e( 'Applying a preset fills spacing values for the selected scope.', 'nova-bridge-suite' ); ?></p>
						<?php
						$header_offset_value = $this->get_header_offset_setting();
						?>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Header offset', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_HEADER_OFFSET ); ?>"
								id="<?php echo esc_attr( self::OPTION_HEADER_OFFSET ); ?>"
								value="<?php echo esc_attr( $header_offset_value ); ?>"
								class="regular-text service-cpt-setting"
								data-option="<?php echo esc_attr( self::OPTION_HEADER_OFFSET ); ?>"
							/>
						</div>
						<?php
						$scale_value = $this->get_spacing_scale_setting();
						?>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Spacing scale', 'nova-bridge-suite' ); ?></span>
							<input
								type="number"
								name="<?php echo esc_attr( self::OPTION_SPACE_SCALE ); ?>"
								id="<?php echo esc_attr( self::OPTION_SPACE_SCALE ); ?>"
								value="<?php echo esc_attr( $scale_value ); ?>"
								class="small-text service-cpt-setting"
								step="0.05"
								min="0.5"
								max="2"
								data-option="<?php echo esc_attr( self::OPTION_SPACE_SCALE ); ?>"
							/>
						</div>
						<?php foreach ( $spacing_fields as $option => $field ) : ?>
							<?php
							$value = $this->get_length_option( $option );
							?>
							<div class="service-cpt-field">
								<span class="service-cpt-field-label"><?php echo esc_html( $field['label'] ); ?></span>
								<input
									type="text"
									name="<?php echo esc_attr( $option ); ?>"
									id="<?php echo esc_attr( $option ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
									class="regular-text service-cpt-setting"
									data-option="<?php echo esc_attr( $option ); ?>"
								/>
								<?php if ( ! empty( $field['description'] ) ) : ?>
									<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Accepts CSS lengths or shorthand (e.g. 24px, 2rem 1rem, var(--wp--preset--spacing--80)).', 'nova-bridge-suite' ); ?></p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Layout', 'nova-bridge-suite' ); ?></summary>
						<?php foreach ( $layout_fields as $option => $field ) : ?>
							<?php
							$value = $this->get_length_option( $option );
							?>
							<div class="service-cpt-field">
								<span class="service-cpt-field-label"><?php echo esc_html( $field['label'] ); ?></span>
								<input
									type="text"
									name="<?php echo esc_attr( $option ); ?>"
									id="<?php echo esc_attr( $option ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"
									class="regular-text service-cpt-setting"
									data-option="<?php echo esc_attr( $option ); ?>"
								/>
								<?php if ( ! empty( $field['description'] ) ) : ?>
									<p class="description"><?php echo esc_html( $field['description'] ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Defaults to 1600px / 1800px on install. Leave blank to inherit your theme content/wide widths.', 'nova-bridge-suite' ); ?></p>
					</details>
				</details>

				<details class="service-cpt-panel">
					<summary><?php esc_html_e( 'Global CTAs', 'nova-bridge-suite' ); ?></summary>
					<p class="description"><?php esc_html_e( 'If any global CTA field is filled, per-page CTA fields are hidden and ignored. Leave this section empty to edit CTAs per page or hide the section.', 'nova-bridge-suite' ); ?></p>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Hero CTAs', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Primary CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_HERO_PRIMARY_LABEL ); ?>"
								value="<?php echo esc_attr( $global_hero['primary_label'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Primary CTA URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_HERO_PRIMARY_URL ); ?>"
								value="<?php echo esc_attr( $global_hero['primary_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Secondary CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_HERO_SECONDARY_LABEL ); ?>"
								value="<?php echo esc_attr( $global_hero['secondary_label'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Secondary CTA URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_HERO_SECONDARY_URL ); ?>"
								value="<?php echo esc_attr( $global_hero['secondary_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Sidebar CTA', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Title', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_TITLE ); ?>"
								value="<?php echo esc_attr( $global_sidebar['title'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Copy', 'nova-bridge-suite' ); ?></span>
							<textarea
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_COPY ); ?>"
								class="large-text"
								rows="3"
							><?php echo esc_textarea( $global_sidebar['copy'] ?? '' ); ?></textarea>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Primary CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_PRIMARY_LABEL ); ?>"
								value="<?php echo esc_attr( $global_sidebar['primary_label'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Primary CTA URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_PRIMARY_URL ); ?>"
								value="<?php echo esc_attr( $global_sidebar['primary_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Secondary CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_SECONDARY_LABEL ); ?>"
								value="<?php echo esc_attr( $global_sidebar['secondary_label'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Secondary CTA URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_SIDEBAR_SECONDARY_URL ); ?>"
								value="<?php echo esc_attr( $global_sidebar['secondary_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Wide CTA', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Title', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_TITLE ); ?>"
								value="<?php echo esc_attr( $global_wide['title'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 1', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_BULLET_1 ); ?>"
								value="<?php echo esc_attr( $global_wide_bullet_1 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 2', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_BULLET_2 ); ?>"
								value="<?php echo esc_attr( $global_wide_bullet_2 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 3', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_BULLET_3 ); ?>"
								value="<?php echo esc_attr( $global_wide_bullet_3 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Button label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_BUTTON_LABEL ); ?>"
								value="<?php echo esc_attr( $global_wide['button_label'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Button URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_BUTTON_URL ); ?>"
								value="<?php echo esc_attr( $global_wide['button_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'More text', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_MORE_TEXT ); ?>"
								value="<?php echo esc_attr( $global_wide['more_text'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'More URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_GLOBAL_CTA_MORE_URL ); ?>"
								value="<?php echo esc_attr( $global_wide['more_url'] ?? '' ); ?>"
								class="regular-text"
							/>
						</div>
					</details>
				</details>

				<details class="service-cpt-panel">
					<summary><?php esc_html_e( 'Archive page', 'nova-bridge-suite' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Controls the content and service listing on the /services archive page.', 'nova-bridge-suite' ); ?></p>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Hero', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Eyebrow', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_HERO_EYEBROW ); ?>"
								value="<?php echo esc_attr( $archive_hero_eyebrow ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Heading (H1)', 'nova-bridge-suite' ); ?></span>
							<textarea
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_HERO_TITLE ); ?>"
								class="large-text"
								rows="2"
							><?php echo esc_textarea( $archive_hero_title ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Line breaks are supported.', 'nova-bridge-suite' ); ?></p>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Intro copy', 'nova-bridge-suite' ); ?></span>
							<?php
							\wp_editor(
								$archive_hero_copy,
								'service_cpt_archive_hero_copy',
								[
									'textarea_name' => self::OPTION_ARCHIVE_HERO_COPY,
									'textarea_rows' => 4,
									'editor_class'  => 'service-cpt-archive-editor',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1' => 'bold,italic,link,bullist,numlist,undo,redo,removeformat',
										'toolbar2' => '',
										'resize'   => false,
										'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
									],
								]
							);
							?>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_HERO_CTA_LABEL ); ?>"
								value="<?php echo esc_attr( $archive_hero_cta_label ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'CTA URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_HERO_CTA_URL ); ?>"
								value="<?php echo esc_attr( $archive_hero_cta_url ); ?>"
								class="regular-text"
							/>
						</div>
						<p class="description"><?php esc_html_e( 'If the archive CTA is empty, the global hero CTA is used instead.', 'nova-bridge-suite' ); ?></p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Intro', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Heading (H2)', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_INTRO_HEADING ); ?>"
								value="<?php echo esc_attr( $archive_intro_heading ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Intro copy', 'nova-bridge-suite' ); ?></span>
							<?php
							\wp_editor(
								$archive_intro_copy,
								'service_cpt_archive_intro_copy',
								[
									'textarea_name' => self::OPTION_ARCHIVE_INTRO_COPY,
									'textarea_rows' => 5,
									'editor_class'  => 'service-cpt-archive-editor',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1' => 'bold,italic,link,bullist,numlist,undo,redo,removeformat',
										'toolbar2' => '',
										'resize'   => false,
										'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
									],
								]
							);
							?>
						</div>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'SEO', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Meta title', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_SEO_TITLE ); ?>"
								value="<?php echo esc_attr( $archive_seo_title ); ?>"
								class="large-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Meta description', 'nova-bridge-suite' ); ?></span>
							<textarea
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_SEO_DESCRIPTION ); ?>"
								class="large-text"
								rows="3"
							><?php echo esc_textarea( $archive_seo_description ); ?></textarea>
						</div>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Service grid', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Card CTA label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CARD_CTA_LABEL ); ?>"
								value="<?php echo esc_attr( $archive_card_cta_label ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Card image placeholder', 'nova-bridge-suite' ); ?></span>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_ARCHIVE_CARD_PLACEHOLDER ); ?>" value="<?php echo esc_attr( (int) $archive_card_placeholder['id'] ); ?>" />
							<div id="service_cpt_archive_card_placeholder_preview" class="service-cpt-image-preview <?php echo $archive_card_placeholder['url'] ? 'has-image' : 'is-empty'; ?>">
								<img src="<?php echo esc_url( $archive_card_placeholder['url'] ); ?>" alt="" />
								<span class="service-cpt-image-placeholder"><?php esc_html_e( 'No image selected', 'nova-bridge-suite' ); ?></span>
								<button
									type="button"
									class="service-cpt-media-remove service-cpt-image-remove"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_CARD_PLACEHOLDER ); ?>"
									data-preview="#service_cpt_archive_card_placeholder_preview"
									data-button="button.service-cpt-media-button[data-target='<?php echo esc_attr( self::OPTION_ARCHIVE_CARD_PLACEHOLDER ); ?>']"
									aria-label="<?php esc_attr_e( 'Remove placeholder image', 'nova-bridge-suite' ); ?>"
									<?php disabled( ! $archive_card_placeholder['id'] ); ?>
								>
									X
								</button>
							</div>
							<div class="service-cpt-image-actions">
								<button
									type="button"
									class="button service-cpt-media-button"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_CARD_PLACEHOLDER ); ?>"
									data-preview="#service_cpt_archive_card_placeholder_preview"
									data-select-label="<?php echo esc_attr__( 'Select placeholder', 'nova-bridge-suite' ); ?>"
									data-change-label="<?php echo esc_attr__( 'Change placeholder', 'nova-bridge-suite' ); ?>"
								>
									<?php echo $archive_card_placeholder['id'] ? esc_html__( 'Change placeholder', 'nova-bridge-suite' ) : esc_html__( 'Select placeholder', 'nova-bridge-suite' ); ?>
								</button>
							</div>
							<p class="description"><?php esc_html_e( 'Used when a service has no featured image. If empty, the card shows text only.', 'nova-bridge-suite' ); ?></p>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Service selection', 'nova-bridge-suite' ); ?></span>
							<select name="<?php echo esc_attr( self::OPTION_ARCHIVE_SERVICES_MODE ); ?>" id="service-cpt-archive-service-mode">
								<option value="auto" <?php selected( $archive_service_mode, 'auto' ); ?>><?php esc_html_e( 'All services (automatic)', 'nova-bridge-suite' ); ?></option>
								<option value="manual" <?php selected( $archive_service_mode, 'manual' ); ?>><?php esc_html_e( 'Manual selection', 'nova-bridge-suite' ); ?></option>
							</select>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Max services', 'nova-bridge-suite' ); ?></span>
							<input
								type="number"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_SERVICES_LIMIT ); ?>"
								value="<?php echo esc_attr( $archive_service_limit ); ?>"
								class="small-text"
								min="0"
							/>
							<p class="description"><?php esc_html_e( 'Leave empty or 0 to show all available services.', 'nova-bridge-suite' ); ?></p>
						</div>
						<div id="service-cpt-archive-service-manual" style="<?php echo 'manual' === $archive_service_mode ? '' : 'display:none;'; ?>">
							<div class="service-cpt-archive-picker">
								<div>
									<label class="service-cpt-field-label"><?php esc_html_e( 'Selected services (order)', 'nova-bridge-suite' ); ?></label>
									<ul
										class="service-cpt-archive-list service-cpt-archive-selected"
										data-service-cpt-selected
										data-input-name="<?php echo esc_attr( self::OPTION_ARCHIVE_SERVICES_IDS ); ?>[]"
									>
										<li class="service-cpt-empty" data-service-cpt-empty><?php esc_html_e( 'No services selected yet.', 'nova-bridge-suite' ); ?></li>
										<?php foreach ( $archive_selected_services as $service ) : ?>
											<li
												class="service-cpt-selected-item"
												data-service-id="<?php echo esc_attr( (string) $service['id'] ); ?>"
												data-service-title="<?php echo esc_attr( (string) $service['title'] ); ?>"
												data-related-item
												data-related-title="<?php echo esc_attr( strtolower( (string) $service['title'] ) ); ?>"
											>
												<span class="service-cpt-item-title"><?php echo esc_html( $service['title'] ); ?></span>
												<div class="service-cpt-selected-actions">
													<button type="button" class="button button-small service-cpt-move-up"><?php esc_html_e( 'Up', 'nova-bridge-suite' ); ?></button>
													<button type="button" class="button button-small service-cpt-move-down"><?php esc_html_e( 'Down', 'nova-bridge-suite' ); ?></button>
													<button type="button" class="button button-small service-cpt-remove-service"><?php esc_html_e( 'Remove', 'nova-bridge-suite' ); ?></button>
												</div>
												<input type="hidden" name="<?php echo esc_attr( self::OPTION_ARCHIVE_SERVICES_IDS ); ?>[]" value="<?php echo esc_attr( (string) $service['id'] ); ?>" />
											</li>
										<?php endforeach; ?>
									</ul>
									<p class="description"><?php esc_html_e( 'Use the up/down buttons to set the order shown on the archive.', 'nova-bridge-suite' ); ?></p>
								</div>
								<div>
									<label class="service-cpt-field-label"><?php esc_html_e( 'Available services', 'nova-bridge-suite' ); ?></label>
									<input type="search" class="service-cpt-filter-search" placeholder="<?php esc_attr_e( 'Filter services...', 'nova-bridge-suite' ); ?>" data-service-cpt-filter="archive-services" />
									<?php if ( empty( $archive_service_posts ) ) : ?>
										<p class="description"><?php esc_html_e( 'No services found yet.', 'nova-bridge-suite' ); ?></p>
									<?php else : ?>
										<ul class="service-cpt-archive-list service-cpt-archive-available" data-service-cpt-list="archive-services">
											<?php foreach ( $archive_service_posts as $service_post ) : ?>
												<?php
												$post_id = (int) $service_post->ID;
												$title = \get_the_title( $service_post );
												$is_selected = in_array( $post_id, $archive_service_ids, true );
												$label = $title ? (string) $title : __( '(no title)', 'nova-bridge-suite' );
												?>
												<li
													class="service-cpt-available-item <?php echo $is_selected ? 'is-selected' : ''; ?>"
													data-service-id="<?php echo esc_attr( (string) $post_id ); ?>"
													data-service-title="<?php echo esc_attr( $label ); ?>"
													data-related-item
													data-related-title="<?php echo esc_attr( strtolower( $label ) ); ?>"
												>
													<span class="service-cpt-item-title"><?php echo esc_html( $label ); ?></span>
													<button type="button" class="button button-small service-cpt-add-service" <?php disabled( $is_selected ); ?>>
														<?php echo esc_html( $is_selected ? __( 'Added', 'nova-bridge-suite' ) : __( 'Add', 'nova-bridge-suite' ) ); ?>
													</button>
												</li>
											<?php endforeach; ?>
										</ul>
										<p class="description"><?php esc_html_e( 'Add services to build the archive grid. Use the selected list to reorder.', 'nova-bridge-suite' ); ?></p>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Highlights (two columns)', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Heading (H2)', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHTS_HEADING ); ?>"
								value="<?php echo esc_attr( $archive_highlights_heading ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Highlight 1 copy', 'nova-bridge-suite' ); ?></span>
							<?php
							\wp_editor(
								$archive_highlight_one_copy,
								'service_cpt_archive_highlight_one_copy',
								[
									'textarea_name' => self::OPTION_ARCHIVE_HIGHLIGHT_ONE_COPY,
									'textarea_rows' => 4,
									'editor_class'  => 'service-cpt-archive-editor',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1' => 'bold,italic,link,bullist,numlist,undo,redo,removeformat',
										'toolbar2' => '',
										'resize'   => false,
										'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
									],
								]
							);
							?>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Highlight 1 image', 'nova-bridge-suite' ); ?></span>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE ); ?>" value="<?php echo esc_attr( (int) $archive_highlight_one_media['id'] ); ?>" />
							<div id="service_cpt_archive_highlight_one_preview" class="service-cpt-image-preview <?php echo $archive_highlight_one_media['url'] ? 'has-image' : 'is-empty'; ?>">
								<img src="<?php echo esc_url( $archive_highlight_one_media['url'] ); ?>" alt="" />
								<span class="service-cpt-image-placeholder"><?php esc_html_e( 'No image selected', 'nova-bridge-suite' ); ?></span>
								<button
									type="button"
									class="service-cpt-media-remove service-cpt-image-remove"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE ); ?>"
									data-preview="#service_cpt_archive_highlight_one_preview"
									data-button="button.service-cpt-media-button[data-target='<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE ); ?>']"
									aria-label="<?php esc_attr_e( 'Remove highlight image 1', 'nova-bridge-suite' ); ?>"
									<?php disabled( ! $archive_highlight_one_media['id'] ); ?>
								>
									X
								</button>
							</div>
							<div class="service-cpt-image-actions">
								<button
									type="button"
									class="button service-cpt-media-button"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_ONE_IMAGE ); ?>"
									data-preview="#service_cpt_archive_highlight_one_preview"
									data-select-label="<?php echo esc_attr__( 'Select image 1', 'nova-bridge-suite' ); ?>"
									data-change-label="<?php echo esc_attr__( 'Change image 1', 'nova-bridge-suite' ); ?>"
								>
									<?php echo $archive_highlight_one_media['id'] ? esc_html__( 'Change image 1', 'nova-bridge-suite' ) : esc_html__( 'Select image 1', 'nova-bridge-suite' ); ?>
								</button>
							</div>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Highlight 2 copy', 'nova-bridge-suite' ); ?></span>
							<?php
							\wp_editor(
								$archive_highlight_two_copy,
								'service_cpt_archive_highlight_two_copy',
								[
									'textarea_name' => self::OPTION_ARCHIVE_HIGHLIGHT_TWO_COPY,
									'textarea_rows' => 4,
									'editor_class'  => 'service-cpt-archive-editor',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1' => 'bold,italic,link,bullist,numlist,undo,redo,removeformat',
										'toolbar2' => '',
										'resize'   => false,
										'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
									],
								]
							);
							?>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Highlight 2 image', 'nova-bridge-suite' ); ?></span>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE ); ?>" value="<?php echo esc_attr( (int) $archive_highlight_two_media['id'] ); ?>" />
							<div id="service_cpt_archive_highlight_two_preview" class="service-cpt-image-preview <?php echo $archive_highlight_two_media['url'] ? 'has-image' : 'is-empty'; ?>">
								<img src="<?php echo esc_url( $archive_highlight_two_media['url'] ); ?>" alt="" />
								<span class="service-cpt-image-placeholder"><?php esc_html_e( 'No image selected', 'nova-bridge-suite' ); ?></span>
								<button
									type="button"
									class="service-cpt-media-remove service-cpt-image-remove"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE ); ?>"
									data-preview="#service_cpt_archive_highlight_two_preview"
									data-button="button.service-cpt-media-button[data-target='<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE ); ?>']"
									aria-label="<?php esc_attr_e( 'Remove highlight image 2', 'nova-bridge-suite' ); ?>"
									<?php disabled( ! $archive_highlight_two_media['id'] ); ?>
								>
									X
								</button>
							</div>
							<div class="service-cpt-image-actions">
								<button
									type="button"
									class="button service-cpt-media-button"
									data-target="<?php echo esc_attr( self::OPTION_ARCHIVE_HIGHLIGHT_TWO_IMAGE ); ?>"
									data-preview="#service_cpt_archive_highlight_two_preview"
									data-select-label="<?php echo esc_attr__( 'Select image 2', 'nova-bridge-suite' ); ?>"
									data-change-label="<?php echo esc_attr__( 'Change image 2', 'nova-bridge-suite' ); ?>"
								>
									<?php echo $archive_highlight_two_media['id'] ? esc_html__( 'Change image 2', 'nova-bridge-suite' ) : esc_html__( 'Select image 2', 'nova-bridge-suite' ); ?>
								</button>
							</div>
						</div>
						<p class="description"><?php esc_html_e( 'Leave all highlight fields empty to hide this section.', 'nova-bridge-suite' ); ?></p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Wide CTA', 'nova-bridge-suite' ); ?></summary>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Title', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_TITLE ); ?>"
								value="<?php echo esc_attr( $archive_cta_title ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 1', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_BULLET_1 ); ?>"
								value="<?php echo esc_attr( $archive_cta_bullet_1 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 2', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_BULLET_2 ); ?>"
								value="<?php echo esc_attr( $archive_cta_bullet_2 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Bullet 3', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_BULLET_3 ); ?>"
								value="<?php echo esc_attr( $archive_cta_bullet_3 ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Button label', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_BUTTON_LABEL ); ?>"
								value="<?php echo esc_attr( $archive_cta_button_label ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'Button URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_BUTTON_URL ); ?>"
								value="<?php echo esc_attr( $archive_cta_button_url ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'More text', 'nova-bridge-suite' ); ?></span>
							<input
								type="text"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_MORE_TEXT ); ?>"
								value="<?php echo esc_attr( $archive_cta_more_text ); ?>"
								class="regular-text"
							/>
						</div>
						<div class="service-cpt-field">
							<span class="service-cpt-field-label"><?php esc_html_e( 'More URL', 'nova-bridge-suite' ); ?></span>
							<input
								type="url"
								name="<?php echo esc_attr( self::OPTION_ARCHIVE_CTA_MORE_URL ); ?>"
								value="<?php echo esc_attr( $archive_cta_more_url ); ?>"
								class="regular-text"
							/>
						</div>
						<p class="description"><?php esc_html_e( 'Leave empty to fall back to the global wide CTA settings.', 'nova-bridge-suite' ); ?></p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'FAQ', 'nova-bridge-suite' ); ?></summary>
						<?php
						$archive_faq_items = array_values( $archive_faq_items );
						for ( $i = 0; $i < 4; $i++ ) {
							if ( ! isset( $archive_faq_items[ $i ] ) || ! is_array( $archive_faq_items[ $i ] ) ) {
								$archive_faq_items[ $i ] = [ 'question' => '', 'answer' => '' ];
							}
						}
						?>
						<?php for ( $i = 0; $i < 4; $i++ ) : ?>
							<?php
							$faq_item = $archive_faq_items[ $i ];
							$question = isset( $faq_item['question'] ) ? (string) $faq_item['question'] : '';
							$answer = isset( $faq_item['answer'] ) ? (string) $faq_item['answer'] : '';
							$editor_id = 'service_cpt_archive_faq_answer_' . ( $i + 1 );
							?>
							<div class="service-cpt-field">
								<?php /* translators: %d: FAQ item number. */ ?>
								<span class="service-cpt-field-label"><?php echo esc_html( sprintf( __( 'FAQ %d question', 'nova-bridge-suite' ), $i + 1 ) ); ?></span>
								<input
									type="text"
									name="<?php echo esc_attr( self::OPTION_ARCHIVE_FAQ ); ?>[<?php echo esc_attr( $i ); ?>][question]"
									value="<?php echo esc_attr( $question ); ?>"
									class="regular-text"
								/>
							</div>
							<div class="service-cpt-field">
								<?php /* translators: %d: FAQ item number. */ ?>
								<span class="service-cpt-field-label"><?php echo esc_html( sprintf( __( 'FAQ %d answer', 'nova-bridge-suite' ), $i + 1 ) ); ?></span>
								<?php
								\wp_editor(
									$answer,
									$editor_id,
									[
										'textarea_name' => self::OPTION_ARCHIVE_FAQ . '[' . $i . '][answer]',
										'textarea_rows' => 3,
										'editor_class'  => 'service-cpt-archive-editor',
										'media_buttons' => false,
										'teeny'         => true,
										'quicktags'     => true,
										'tinymce'       => [
											'toolbar1' => 'bold,italic,link,bullist,numlist,undo,redo,removeformat',
											'toolbar2' => '',
											'resize'   => false,
											'content_style' => 'body { padding: 4px; margin: 0; min-height: 0; }',
										],
									]
								);
								?>
							</div>
						<?php endfor; ?>
						<p class="description"><?php esc_html_e( 'Leave all FAQ fields empty to hide this section.', 'nova-bridge-suite' ); ?></p>
					</details>

					<details class="service-cpt-subpanel">
						<summary><?php esc_html_e( 'Related articles', 'nova-bridge-suite' ); ?></summary>
						<p class="description"><?php esc_html_e( 'Uses the global Related articles label (H3). Leave unselected to hide this section.', 'nova-bridge-suite' ); ?></p>
						<label class="service-cpt-field-label"><?php esc_html_e( 'Select posts', 'nova-bridge-suite' ); ?></label>
						<input type="search" class="service-cpt-filter-search" placeholder="<?php esc_attr_e( 'Filter posts...', 'nova-bridge-suite' ); ?>" data-service-cpt-filter="archive-related" />
						<?php if ( empty( $archive_related_posts ) ) : ?>
							<p class="description"><?php esc_html_e( 'No posts found yet.', 'nova-bridge-suite' ); ?></p>
						<?php else : ?>
							<div class="service-cpt-related-list" data-service-cpt-list="archive-related">
								<?php foreach ( $archive_related_posts as $related_post ) : ?>
									<?php
									$post_id = (int) $related_post->ID;
									$title = \get_the_title( $related_post );
									$is_checked = in_array( $post_id, $archive_related_ids, true );
									?>
									<label class="service-cpt-related-item" data-related-item data-related-title="<?php echo esc_attr( strtolower( (string) $title ) ); ?>">
										<input
											type="checkbox"
											name="<?php echo esc_attr( self::OPTION_ARCHIVE_RELATED_POSTS ); ?>[]"
											value="<?php echo esc_attr( $post_id ); ?>"
											<?php checked( $is_checked ); ?>
										/>
										<span><?php echo esc_html( $title ? $title : __( '(no title)', 'nova-bridge-suite' ) ); ?></span>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'If nothing is selected, the related articles section stays hidden.', 'nova-bridge-suite' ); ?></p>
						<?php endif; ?>
					</details>
				</details>

				<details class="service-cpt-panel">
					<summary><?php esc_html_e( 'Template sections (block layout)', 'nova-bridge-suite' ); ?></summary>
					<p class="description"><?php esc_html_e( 'Controls which sections render for the selected template.', 'nova-bridge-suite' ); ?></p>
					<?php
					$definitions = $this->get_template_component_definitions( $selected_template );
					$settings = $this->get_template_component_settings( $selected_template );
					$template_label = $templates[ $selected_template ]['label'] ?? $selected_template;
					?>
					<div class="service-cpt-template-components">
						<strong><?php echo esc_html( $template_label ); ?></strong>
						<?php if ( empty( $definitions ) ) : ?>
							<p class="description"><?php esc_html_e( 'No configurable components for this template.', 'nova-bridge-suite' ); ?></p>
						<?php else : ?>
							<?php foreach ( $definitions as $component => $label ) : ?>
								<label style="display:block;margin-bottom:6px;">
									<input type="hidden" name="<?php echo esc_attr( self::OPTION_TEMPLATE_COMPONENTS ); ?>[<?php echo esc_attr( $component ); ?>]" value="0" />
									<input
										type="checkbox"
										name="<?php echo esc_attr( self::OPTION_TEMPLATE_COMPONENTS ); ?>[<?php echo esc_attr( $component ); ?>]"
										value="1"
										<?php checked( $settings[ $component ] ?? true ); ?>
									/>
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
				</details>

				<details class="service-cpt-panel">
					<summary><?php esc_html_e( 'Advanced', 'nova-bridge-suite' ); ?></summary>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Hide selectors (one per line)', 'nova-bridge-suite' ); ?></th>
							<td>
								<textarea name="<?php echo esc_attr( self::OPTION_EXCLUDE_SELECTORS ); ?>" class="large-text code" rows="4"><?php echo esc_textarea( implode( "\n", $this->get_exclude_selectors() ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'CSS selectors to strip from the page output for isolation.', 'nova-bridge-suite' ); ?></p>
							</td>
						</tr>
					</table>
				</details>
				<?php \submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public static function sanitize_exclude_selectors( $value ): array {
		if ( ! \is_array( $value ) ) {
			$value = explode( "\n", (string) $value );
		}

		$clean = [];

		foreach ( $value as $line ) {
			$line = \trim( (string) $line );

			if ( '' === $line ) {
				continue;
			}

			$clean[] = \wp_strip_all_tags( $line );
		}

		return $clean;
	}

	public function sanitize_color_value( $value ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		$lower = strtolower( $value );
		if ( in_array( $lower, [ 'currentcolor', 'inherit', 'transparent' ], true ) ) {
			return $lower;
		}

		$hex = \sanitize_hex_color( $value );

		if ( $hex ) {
			return $hex;
		}

		if ( 1 === preg_match( '/^var\\(--[A-Za-z0-9_-]+\\)$/', $value ) ) {
			return $value;
		}

		return '';
	}

	public function sanitize_length_value( $value ): string {
		$value = \trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( 1 === preg_match( '/^var\\(--[A-Za-z0-9_-]+\\)$/', $value ) ) {
			return $value;
		}

		if ( 1 === preg_match( '/^(calc|min|max|clamp)\\([^\\)]+\\)$/', $value ) ) {
			return $value;
		}

		$parts = preg_split( '/\s+/', $value );

		if ( ! \is_array( $parts ) || empty( $parts ) ) {
			return '';
		}

		$normalized = [];

		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}

			$lower = strtolower( $part );

			if ( '0' === $lower ) {
				$normalized[] = '0';
				continue;
			}

			if ( in_array( $lower, [ 'auto', 'inherit', 'initial', 'unset', 'normal' ], true ) ) {
				$normalized[] = $lower;
				continue;
			}

			if ( 1 === preg_match( '/^var\\(--[A-Za-z0-9_-]+\\)$/', $part ) ) {
				$normalized[] = $part;
				continue;
			}

			if ( 1 === preg_match( '/^(calc|min|max|clamp)\\([^\\)]+\\)$/', $part ) ) {
				$normalized[] = $part;
				continue;
			}

			if ( 1 === preg_match( '/^-?\\d*\\.?\\d+$/', $part ) ) {
				$normalized[] = $part . 'px';
				continue;
			}

			if ( 1 === preg_match( '/^-?\\d*\\.?\\d+(px|rem|em|%|vh|vw|vmin|vmax)$/', $part ) ) {
				$normalized[] = $part;
				continue;
			}

			return '';
		}

		return implode( ' ', $normalized );
	}

	public function sanitize_spacing_scale( $value ): string {
		if ( \is_string( $value ) ) {
			$value = \trim( $value );
		}

		if ( '' === $value || null === $value || ! \is_numeric( $value ) ) {
			return self::DEFAULT_SPACE_SCALE;
		}

		$numeric = (float) $value;

		if ( $numeric <= 0 ) {
			return self::DEFAULT_SPACE_SCALE;
		}

		$numeric = max( 0.5, min( 2.0, $numeric ) );
		$formatted = rtrim( rtrim( sprintf( '%.2f', $numeric ), '0' ), '.' );

		return '' === $formatted ? self::DEFAULT_SPACE_SCALE : $formatted;
	}

	private function get_color_option( string $option, string $template_slug = '' ): string {
		$value = \get_option( $option, '' );

		return $this->sanitize_color_value( $value );
	}

	private function get_length_option( string $option, string $template_slug = '' ): string {
		$default = '';

		if ( self::OPTION_CONTENT_WIDTH === $option ) {
			$default = self::DEFAULT_CONTENT_WIDTH;
		} elseif ( self::OPTION_WIDE_WIDTH === $option ) {
			$default = self::DEFAULT_WIDE_WIDTH;
		}

		$value = \get_option( $option, $default );

		return $this->sanitize_length_value( $value );
	}

	private function get_wrap_style_attribute( string $template_slug = '' ): string {
		$styles = [];

		$styles[] = sprintf( '--service-cpt-header-offset: %s', $this->get_header_offset_setting() );
		$styles[] = sprintf( '--service-cpt-space-scale: %s', $this->get_spacing_scale_setting() );

		$color_map = [
			self::OPTION_COLOR_PRIMARY  => '--service-cpt-primary',
			self::OPTION_COLOR_CONTRAST => '--service-cpt-primary-contrast',
			self::OPTION_COLOR_SURFACE  => '--service-cpt-surface',
			self::OPTION_COLOR_TEXT     => '--service-cpt-text',
			self::OPTION_COLOR_ACCENT   => '--service-cpt-accent',
			self::OPTION_COLOR_BORDER   => '--service-cpt-border',
			self::OPTION_COLOR_HERO_BG  => '--service-cpt-hero-bg',
			self::OPTION_COLOR_HERO_TEXT => '--service-cpt-hero-text',
			self::OPTION_COLOR_CTA_BG   => '--service-cpt-cta-bg',
			self::OPTION_COLOR_CTA_TEXT => '--service-cpt-cta-text',
			self::OPTION_COLOR_BUTTON_BG => '--service-cpt-button-bg',
			self::OPTION_COLOR_BUTTON_TEXT => '--service-cpt-button-text',
			self::OPTION_COLOR_BUTTON_OUTLINE => '--service-cpt-button-outline',
			self::OPTION_COLOR_FAQ_BG => '--service-cpt-faq-bg',
			self::OPTION_COLOR_FAQ_QUESTION => '--service-cpt-faq-question',
			self::OPTION_COLOR_FAQ_ANSWER => '--service-cpt-faq-answer',
			self::OPTION_COLOR_TABS_ACTIVE_BG => '--service-cpt-tabs-active-bg',
			self::OPTION_COLOR_TABS_ACTIVE_TEXT => '--service-cpt-tabs-active-text',
			self::OPTION_COLOR_TABS_INACTIVE_BG => '--service-cpt-tabs-inactive-bg',
			self::OPTION_COLOR_TABS_INACTIVE_TEXT => '--service-cpt-tabs-inactive-text',
			self::OPTION_COLOR_TABS_BORDER => '--service-cpt-tabs-border',
		];

		foreach ( $color_map as $option => $css_var ) {
			$value = $this->get_color_option( $option, $template_slug );

			if ( '' === $value ) {
				continue;
			}

			$styles[] = sprintf( '%s: %s', $css_var, $value );
		}

		$section_padding = $this->get_length_option( self::OPTION_SPACE_SECTION_PADDING, $template_slug );
		if ( '' !== $section_padding ) {
			$styles[] = sprintf( '--service-cpt-section-padding: %s', $section_padding );

			$padding_parts = preg_split( '/\s+/', trim( $section_padding ) ) ?: [];
			$padding_parts = array_values( array_filter( $padding_parts, 'strlen' ) );
			$vertical = $padding_parts[0] ?? '';
			$horizontal = $padding_parts[0] ?? '';

			if ( isset( $padding_parts[1] ) ) {
				$horizontal = $padding_parts[1];
			}

			if ( '' !== $vertical ) {
				$styles[] = sprintf( '--service-cpt-section-padding-y: %s', $vertical );
			}

			if ( '' !== $horizontal ) {
				$styles[] = sprintf( '--service-cpt-section-padding-x: %s', $horizontal );
			}
		}

		$spacing_map = [
			self::OPTION_SPACE_SECTION_GAP  => '--service-cpt-section-gap',
			self::OPTION_SPACE_CARD_PADDING => '--service-cpt-card-padding',
		];

		foreach ( $spacing_map as $option => $css_var ) {
			$value = $this->get_length_option( $option, $template_slug );

			if ( '' === $value ) {
				continue;
			}

			$styles[] = sprintf( '%s: %s', $css_var, $value );
		}

		$gap_value = $this->get_length_option( self::OPTION_SPACE_SECTION_GAP, $template_slug );

		if ( '' !== $gap_value ) {
			$styles[] = sprintf( '--wp--style--block-gap: %s', $gap_value );
		}

		$layout_map = [
			self::OPTION_CONTENT_WIDTH => '--service-cpt-content-width',
			self::OPTION_WIDE_WIDTH    => '--service-cpt-wide-width',
		];

		foreach ( $layout_map as $option => $css_var ) {
			$value = $this->get_length_option( $option, $template_slug );

			if ( '' === $value ) {
				continue;
			}

			$styles[] = sprintf( '%s: %s', $css_var, $value );

			if ( self::OPTION_CONTENT_WIDTH === $option ) {
				$styles[] = sprintf( '--wp--style--global--content-size: %s', $value );
			}

			if ( self::OPTION_WIDE_WIDTH === $option ) {
				$styles[] = sprintf( '--wp--style--global--wide-size: %s', $value );
			}
		}

		$style = \trim( implode( '; ', $styles ) );

		if ( '' === $style ) {
			return '';
		}

		return ' style="' . esc_attr( $style ) . '"';
	}

	private function get_exclude_selectors(): array {
		$value = \get_option( self::OPTION_EXCLUDE_SELECTORS, [] );

		if ( ! \is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					static function ( $selector ) {
						return \wp_strip_all_tags( (string) $selector );
					},
					$value
				)
			)
		);
	}

	/**
	 * Sanitizes component toggles.
	 */
	public static function sanitize_components_option( $value ): array {
		if ( ! \is_array( $value ) ) {
			$value = [];
		}

		$clean = [];

		foreach ( self::DEFAULT_COMPONENTS as $key => $default ) {
			$clean[ $key ] = ! empty( $value[ $key ] );
		}

		return $clean;
	}

	private function dependencies_ready(): bool {
		foreach ( self::REQUIRED_PLUGINS as $plugin ) {
			if ( empty( $plugin['file'] ) ) {
				continue;
			}

			if ( ! $this->is_plugin_enabled( $plugin['file'] ) ) {
				return false;
			}
		}

		return true;
	}

	private function has_dependency_notice_context(): bool {
		if ( ! \function_exists( 'get_current_screen' ) ) {
			return true;
		}

		$screen = \get_current_screen();

		if ( ! $screen ) {
			return true;
		}

		$allowed_ids = [
			'edit-' . self::CPT,
			self::CPT,
			'settings_page_service-cpt',
			'plugins',
			'dashboard',
		];

		return in_array( $screen->id, $allowed_ids, true );
	}

	private function is_dependency_blocked_screen( \WP_Screen $screen ): bool {
		if ( self::CPT === $screen->post_type && in_array( $screen->base, [ 'edit', 'post' ], true ) ) {
			return true;
		}

		if ( in_array( $screen->id, [ 'settings_page_service-cpt', self::CPT . '_page_service-cpt' ], true ) ) {
			return true;
		}

		return false;
	}

	private function collect_missing_plugins( array $plugins ): array {
		$missing = [];

		foreach ( $plugins as $slug => $plugin ) {
			$plugin_file = $plugin['file'] ?? '';

			if ( '' === $plugin_file ) {
				continue;
			}

			if ( $this->is_plugin_enabled( $plugin_file ) ) {
				continue;
			}

			$missing[ $slug ] = [
				'name'      => $plugin['name'],
				'installed' => $this->is_plugin_installed( $plugin_file ),
			];
		}

		return $missing;
	}

	public function maybe_block_admin_screen(): void {
		if ( ! \is_admin() || \wp_doing_ajax() ) {
			return;
		}

		if ( $this->dependencies_ready() ) {
			return;
		}

		if ( ! \function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = \get_current_screen();

		if ( ! $screen || ! $this->is_dependency_blocked_screen( $screen ) ) {
			return;
		}

		$this->render_dependency_screen();
		exit;
	}

	private function is_plugin_enabled( string $plugin_file ): bool {
		if ( ! \function_exists( 'is_plugin_active' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( \is_plugin_active( $plugin_file ) ) {
			return true;
		}

		if ( \is_multisite() && \function_exists( 'is_plugin_active_for_network' ) ) {
			return \is_plugin_active_for_network( $plugin_file );
		}

		return false;
	}

	private function is_plugin_installed( string $plugin_file ): bool {
		return \file_exists( \WP_PLUGIN_DIR . '/' . $plugin_file );
	}

	private function get_dependency_action_url( string $slug ): string {
		$url = \admin_url( 'admin-post.php?action=service_cpt_install_plugin&slug=' . $slug );

		return \wp_nonce_url( $url, 'service_cpt_install_plugin_' . $slug );
	}

	public function render_dependency_notice(): void {
		if ( ! \current_user_can( 'install_plugins' ) && ! \current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! $this->has_dependency_notice_context() ) {
			return;
		}

		if ( \function_exists( 'get_current_screen' ) ) {
			$screen = \get_current_screen();
			if ( $screen && $this->is_dependency_blocked_screen( $screen ) ) {
				return;
			}
		}

		$missing_required = $this->collect_missing_plugins( self::REQUIRED_PLUGINS );

		if ( empty( $missing_required ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		esc_html_e( 'Service Page CPT requires the following plugins to be installed and active:', 'nova-bridge-suite' );
		echo '</p><ul>';

		foreach ( $missing_required as $slug => $plugin ) {
			$action_label = $plugin['installed']
				? __( 'Activate', 'nova-bridge-suite' )
				: __( 'Install & Activate', 'nova-bridge-suite' );
			$action_url = $this->get_dependency_action_url( $slug );

			printf(
				'<li>%1$s <a class="button button-primary" href="%2$s">%3$s</a></li>',
				esc_html( $plugin['name'] ),
				esc_url( $action_url ),
				esc_html( $action_label )
			);
		}

		echo '</ul></div>';
	}

	public function handle_dependency_install(): void {
		$slug = isset( $_GET['slug'] ) ? \sanitize_key( (string) wp_unslash( $_GET['slug'] ) ) : '';

		if ( '' === $slug || ! isset( self::REQUIRED_PLUGINS[ $slug ] ) ) {
			\wp_die( esc_html__( 'Unknown plugin.', 'nova-bridge-suite' ) );
		}

		\check_admin_referer( 'service_cpt_install_plugin_' . $slug );

		if ( ! \current_user_can( 'install_plugins' ) && ! \current_user_can( 'activate_plugins' ) ) {
			\wp_die( esc_html__( 'You do not have permission to install plugins.', 'nova-bridge-suite' ) );
		}

		$plugin      = self::REQUIRED_PLUGINS[ $slug ];
		$plugin_file = $plugin['file'] ?? '';

		if ( '' === $plugin_file ) {
			\wp_die( esc_html__( 'Invalid plugin configuration.', 'nova-bridge-suite' ) );
		}

		require_once \ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! $this->is_plugin_installed( $plugin_file ) ) {
			if ( ! \current_user_can( 'install_plugins' ) ) {
				\wp_die( esc_html__( 'You do not have permission to install plugins.', 'nova-bridge-suite' ) );
			}

			require_once \ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once \ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once \ABSPATH . 'wp-admin/includes/file.php';

			$api = \plugins_api(
				'plugin_information',
				[
					'slug'   => $slug,
					'fields' => [
						'sections' => false,
					],
				]
			);

			if ( \is_wp_error( $api ) ) {
				\wp_die( esc_html( $api->get_error_message() ) );
			}

			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->install( $api->download_link );

			if ( \is_wp_error( $result ) ) {
				\wp_die( esc_html( $result->get_error_message() ) );
			}
		}

		if ( ! $this->is_plugin_enabled( $plugin_file ) ) {
			if ( ! \current_user_can( 'activate_plugins' ) ) {
				\wp_die( esc_html__( 'You do not have permission to activate plugins.', 'nova-bridge-suite' ) );
			}

		$result = \activate_plugin( $plugin_file );

		if ( \is_wp_error( $result ) ) {
			\wp_die( esc_html( $result->get_error_message() ) );
		}
		}

		\wp_safe_redirect( \admin_url( 'plugins.php?service_cpt_dependencies=1' ) );
		exit;
	}

	private function render_dependency_screen(): void {
		$missing = $this->collect_missing_plugins( self::REQUIRED_PLUGINS );

		if ( empty( $missing ) ) {
			return;
		}

		require_once \ABSPATH . 'wp-admin/admin-header.php';
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'NOVA Services Settings', 'nova-bridge-suite' ) . '</h1>';
		echo '<div class="notice notice-warning inline"><p>';
		esc_html_e( 'Before using Service Page CPT, please install and activate the required plugins:', 'nova-bridge-suite' );
		echo '</p><ul>';

		foreach ( $missing as $slug => $plugin ) {
			$action_label = $plugin['installed']
				? __( 'Activate', 'nova-bridge-suite' )
				: __( 'Install & Activate', 'nova-bridge-suite' );
			$action_url = $this->get_dependency_action_url( $slug );

			printf(
				'<li>%1$s <a class="button button-primary" href="%2$s">%3$s</a></li>',
				esc_html( $plugin['name'] ),
				esc_url( $action_url ),
				esc_html( $action_label )
			);
		}

		echo '</ul></div></div>';
		require_once \ABSPATH . 'wp-admin/admin-footer.php';
	}

	/**
	 * Prints inline CSS that hides excluded selectors for isolation.
	 */
	public function enqueue_exclusion_styles( string $handle = 'service-cpt-frontend' ): void {
		if ( ! $this->is_service_context() ) {
			return;
		}

		$selectors = $this->get_exclude_selectors();

		if ( empty( $selectors ) ) {
			return;
		}

		$rules = array_map(
			static function ( $selector ) {
				$selector = \wp_strip_all_tags( (string) $selector );
				$selector = trim( $selector );

				if ( '' === $selector ) {
					return '';
				}

				return sprintf( '%s { display: none !important; }', $selector );
			},
			$selectors
		);

		$rules = array_filter( $rules );

		if ( empty( $rules ) ) {
			return;
		}

		if ( ! \wp_style_is( $handle, 'registered' ) ) {
			\wp_register_style( $handle, false, [], null );
			\wp_enqueue_style( $handle );
		}

		\wp_add_inline_style( $handle, implode( "\n", $rules ) );
	}

	public function maybe_override_faq_schema(): void {
		if ( ! \is_singular( self::CPT ) ) {
			return;
		}

		$this->remove_action_by_method( 'wp_head', 'gutenberg_faq_block_json_ld' );
		$this->remove_action_by_method( 'amp_post_template_head', 'gutenberg_faq_block_json_ld' );
	}

	public function render_service_faq_schema(): void {
		if ( ! \is_singular( self::CPT ) ) {
			return;
		}

		$post_id = (int) \get_the_ID();
		if ( $post_id <= 0 ) {
			return;
		}

		$schema = $this->build_faq_schema_data( $post_id );
		if ( empty( $schema ) ) {
			return;
		}

		echo "\n<!-- Service Page FAQ schema -->\n";
		echo '<script type="application/ld+json">' . \wp_json_encode( $schema ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function filter_wpseo_description( string $description ): string {
		if ( \is_post_type_archive( self::CPT ) ) {
			$custom = $this->get_archive_seo_description();

			return '' !== $custom ? $custom : $description;
		}

		if ( ! \is_singular( self::CPT ) ) {
			return $description;
		}

		$post_id = (int) \get_the_ID();
		if ( $post_id <= 0 ) {
			return $description;
		}

		$custom = $this->build_meta_description( $post_id );

		return '' !== $custom ? $custom : $description;
	}

	public function filter_wpseo_title( string $title ): string {
		if ( ! \is_post_type_archive( self::CPT ) ) {
			return $title;
		}

		$custom = $this->get_archive_seo_title();

		return '' !== $custom ? $custom : $title;
	}

	public function filter_archive_document_title( string $title ): string {
		if ( ! \is_post_type_archive( self::CPT ) ) {
			return $title;
		}

		$custom = $this->get_archive_seo_title();

		return '' !== $custom ? $custom : $title;
	}

	public function render_archive_meta_description(): void {
		if ( ! \is_post_type_archive( self::CPT ) ) {
			return;
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			return;
		}

		$description = $this->get_archive_seo_description();
		if ( '' === $description ) {
			return;
		}

		echo "\n" . '<meta name="description" content="' . esc_attr( $description ) . "\" />\n";
	}

	private function get_archive_seo_title(): string {
		$value = $this->get_archive_text_option( self::OPTION_ARCHIVE_SEO_TITLE );

		return $this->normalize_seo_text( $value );
	}

	private function get_archive_seo_description(): string {
		$value = $this->get_archive_multiline_option( self::OPTION_ARCHIVE_SEO_DESCRIPTION );

		return $this->normalize_seo_text( $value );
	}

	private function normalize_seo_text( string $value ): string {
		$value = \trim( \wp_strip_all_tags( $value ) );
		$value = (string) preg_replace( '/\s+/', ' ', $value );

		return $value;
	}

	private function build_faq_schema_data( int $post_id ): array {
		$template_slug = $this->get_effective_template_slug( $post_id );
		if ( ! $this->component_enabled_for_template( 'faq', $template_slug ) ) {
			return [];
		}

		$meta = $this->get_meta_values( $post_id );
		$faq_items = $meta['sp_faq'] ?? [];

		if ( ! \is_array( $faq_items ) || empty( $faq_items ) ) {
			return [];
		}

		$permalink = \get_permalink( $post_id );
		$main_entity = [];

		foreach ( $faq_items as $index => $item ) {
			if ( ! \is_array( $item ) ) {
				continue;
			}

			$question = \trim( \wp_strip_all_tags( (string) ( $item['question'] ?? '' ) ) );
			$answer   = \trim( \wp_strip_all_tags( (string) ( $item['answer'] ?? '' ) ) );

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$anchor = $permalink . '#service-faq-' . ( $index + 1 );

			$main_entity[] = [
				'@type'          => 'Question',
				'@id'            => $anchor,
				'name'           => $question,
				'answerCount'    => 1,
				'position'       => (int) $index,
				'url'            => $anchor,
				'acceptedAnswer' => [
					'@type' => 'Answer',
					'text'  => $answer,
				],
			];
		}

		if ( empty( $main_entity ) ) {
			return [];
		}

		return [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		];
	}

	private function build_meta_description( int $post_id ): string {
		$meta = $this->get_meta_values( $post_id );

		$parts = array_filter(
			[
				$meta['sp_hero_copy'] ?? '',
				$meta['sp_intro'] ?? '',
				$meta['sp_main_1'] ?? '',
				$meta['sp_main_2'] ?? '',
				$meta['sp_main_3'] ?? '',
			],
			static function ( string $value ): bool {
				return '' !== \trim( $value );
			}
		);

		if ( empty( $parts ) ) {
			$parts[] = \get_the_title( $post_id );
		}

		$summary = \trim( \wp_strip_all_tags( implode( ' ', $parts ) ) );
		$summary = (string) preg_replace( '/\s+/', ' ', $summary );

		if ( '' === $summary ) {
			return '';
		}

		return \wp_html_excerpt( $summary, 160, '' );
	}

	private function remove_action_by_method( string $hook, string $method ): void {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
			return;
		}

		$hook_obj = $wp_filter[ $hook ];

		foreach ( $hook_obj->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( \is_array( $function ) && isset( $function[1] ) && $method === $function[1] ) {
					\remove_action( $hook, $function, (int) $priority );
				}
			}
		}
	}

	private function get_header_offset_setting( string $template_slug = '' ): string {
		$stored = \get_option( self::OPTION_HEADER_OFFSET, self::DEFAULT_HEADER_OFFSET );

		if ( ! \is_string( $stored ) ) {
			return self::DEFAULT_HEADER_OFFSET;
		}

		$value = \trim( $stored );

		return '' === $value ? self::DEFAULT_HEADER_OFFSET : $value;
	}

	private function get_spacing_scale_setting( string $template_slug = '' ): string {
		$stored = \get_option( self::OPTION_SPACE_SCALE, self::DEFAULT_SPACE_SCALE );

		return $this->sanitize_spacing_scale( $stored );
	}

	private function maybe_apply_template_content( int $post_id, bool $force = false, string $template_slug = '' ): void {
		static $updating = false;

		if ( $updating ) {
			return;
		}

		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post = \get_post( $post_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return;
		}

		if ( ! $force && '' !== \trim( (string) $post->post_content ) ) {
			return;
		}

		if ( '' === $template_slug ) {
			$template_slug = $this->get_effective_template_slug( $post_id );
		}
		$template      = $this->get_template_content( $template_slug );

		if ( '' === $template ) {
			return;
		}

		if ( \trim( (string) $post->post_content ) === \trim( $template ) ) {
			return;
		}

		$updating = true;
		\wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => \wp_slash( $template ),
			]
		);
		$updating = false;
	}

	public function handle_template_option_update( $old_value, $value, $option = '' ): void {
		$new_slug = $this->sanitize_template_option( $value );
		$old_slug = $this->sanitize_template_option( $old_value );

		if ( $new_slug === $old_slug ) {
			return;
		}

		$posts = \get_posts(
			[
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $posts ) ) {
			return;
		}

		foreach ( $posts as $post_id ) {
			$this->maybe_apply_template_content( (int) $post_id, true, $new_slug );
		}
	}

	private function get_template_slug_from_request( \WP_REST_Request $request ): string {
		return $this->get_selected_template_slug();
	}

	public function ensure_template_content_on_save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( self::CPT !== $post->post_type ) {
			return;
		}

		$this->maybe_apply_template_content( $post_id );
	}

	public function rest_pre_insert_service_page( $prepared_post, \WP_REST_Request $request ) {
		$post_content = '';
		if ( $prepared_post instanceof \WP_Post ) {
			$post_content = (string) $prepared_post->post_content;
		} elseif ( \is_array( $prepared_post ) ) {
			$post_content = (string) ( $prepared_post['post_content'] ?? '' );
		} elseif ( \is_object( $prepared_post ) ) {
			$post_content = (string) ( $prepared_post->post_content ?? '' );
		} else {
			return $prepared_post;
		}

		if ( '' !== \trim( $post_content ) ) {
			return $prepared_post;
		}

		$template_slug = $this->get_template_slug_from_request( $request );
		$template      = $this->get_template_content( $template_slug );

		if ( '' === $template ) {
			return $prepared_post;
		}

		if ( $prepared_post instanceof \WP_Post ) {
			$prepared_post->post_content = $template;
		} elseif ( \is_array( $prepared_post ) ) {
			$prepared_post['post_content'] = $template;
		} else {
			$prepared_post->post_content = $template;
		}

		return $prepared_post;
	}

	public function rest_after_insert_service_page( $post, \WP_REST_Request $request, bool $creating ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$this->maybe_apply_template_content( $post->ID );
	}

	public function maybe_return_schema_on_empty_collection( $response, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}

		if ( 'GET' !== $request->get_method() ) {
			return $response;
		}

		if ( $response->get_status() >= 400 ) {
			return $response;
		}

		$collection_route = '/wp/v2/' . $this->get_base_slug();
		if ( $request->get_route() !== $collection_route ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! \is_array( $data ) || ! empty( $data ) ) {
			return $response;
		}

		$headers = $response->get_headers();
		$total = 0;
		if ( isset( $headers['X-WP-Total'] ) ) {
			$total = (int) $headers['X-WP-Total'];
		}

		$offset = (int) $request->get_param( 'offset' );
		if ( $offset < 0 ) {
			$offset = 0;
		}

		if ( $total > 0 && ( 0 === $offset || $offset < $total ) ) {
			return $response;
		}

		$options_request = new \WP_REST_Request( 'OPTIONS', $collection_route );
		$options_response = \rest_do_request( $options_request );
		if ( $options_response instanceof \WP_REST_Response ) {
			return $options_response;
		}

		return $response;
	}

	public function filter_rest_service_page_response( $response, $post, \WP_REST_Request $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}

		if ( self::CPT !== $post->post_type ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! \is_array( $data ) ) {
			return $response;
		}

		$template = $this->get_effective_template_slug( $post->ID );
		$allowed  = $this->get_active_meta_keys_for_template( $template );
		$meta_values = $this->get_meta_values( $post->ID );

		$filtered_meta = [];
		foreach ( $allowed as $meta_key ) {
			if ( array_key_exists( $meta_key, $meta_values ) ) {
				$filtered_meta[ $meta_key ] = $meta_values[ $meta_key ];
			}
		}

		$data['meta'] = $filtered_meta;

		$meta_descriptions = $this->get_rest_meta_descriptions_field( [ 'id' => $post->ID ], 'meta_descriptions', $request );
		if ( ! empty( $meta_descriptions ) ) {
			$data['meta_descriptions'] = $meta_descriptions;
		}

		$meta_note = $this->get_meta_context_note( '', $template );
		if ( '' !== $meta_note ) {
			$data['meta_note'] = $meta_note;
		}

		if ( isset( $data['meta_all'] ) && \is_array( $data['meta_all'] ) ) {
			foreach ( $data['meta_all'] as $meta_key => $value ) {
				if ( 0 === \strpos( (string) $meta_key, 'sp_' ) && ! \in_array( $meta_key, $allowed, true ) ) {
					unset( $data['meta_all'][ $meta_key ] );
				}
			}
		}

		if ( isset( $data['meta_all_flat'] ) && \is_array( $data['meta_all_flat'] ) ) {
			foreach ( $data['meta_all_flat'] as $meta_key => $value ) {
				$meta_key = (string) $meta_key;
				if ( 0 !== \strpos( $meta_key, 'sp_' ) ) {
					continue;
				}

				$base_key = \explode( '.', $meta_key, 2 )[0];
				if ( ! \in_array( $base_key, $allowed, true ) ) {
					unset( $data['meta_all_flat'][ $meta_key ] );
				}
			}
		}

		$response->set_data( $data );

		return $response;
	}

	public function register_rest_fields(): void {
		$meta_schema = $this->get_rest_meta_schema();
		if ( empty( $meta_schema ) ) {
			return;
		}

		$meta_description = $this->get_meta_context_note( 'Service page fields for the active layout.', $this->get_selected_template_slug() );

		\register_rest_field(
			self::CPT,
			'meta',
			[
				'get_callback' => [ $this, 'get_rest_meta_field' ],
				'schema'       => [
					'description' => $meta_description,
					'type'        => 'object',
					'context'     => [ 'view', 'edit' ],
					'properties'  => $meta_schema,
				],
			]
		);

		$description_schema = $this->get_rest_meta_description_schema();
		if ( ! empty( $description_schema ) ) {
			\register_rest_field(
				self::CPT,
				'meta_descriptions',
				[
					'get_callback' => [ $this, 'get_rest_meta_descriptions_field' ],
					'schema'       => [
						'description' => __( 'Descriptions for the active service page meta fields.', 'nova-bridge-suite' ),
						'type'        => 'object',
						'context'     => [ 'view', 'edit' ],
						'properties'  => $description_schema,
					],
				]
			);
		}

		$meta_note = $this->get_meta_context_note( '', $this->get_selected_template_slug() );
		if ( '' !== $meta_note ) {
			\register_rest_field(
				self::CPT,
				'meta_note',
				[
					'get_callback' => [ $this, 'get_rest_meta_note_field' ],
					'schema'       => [
						'description' => __( 'Usage notes for the active service page meta fields.', 'nova-bridge-suite' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
					],
				]
			);
		}
	}

	public function get_rest_meta_field( array $object, string $field_name, \WP_REST_Request $request ) {
		$post_id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		if ( 0 === $post_id ) {
			return [];
		}

		$template = $this->get_effective_template_slug( $post_id );
		$allowed  = $this->get_active_meta_keys_for_template( $template );
		$meta_values = $this->get_meta_values( $post_id );

		$filtered_meta = [];
		foreach ( $allowed as $meta_key ) {
			if ( array_key_exists( $meta_key, $meta_values ) ) {
				$filtered_meta[ $meta_key ] = $meta_values[ $meta_key ];
			}
		}

		return $filtered_meta;
	}

	public function get_rest_meta_descriptions_field( array $object, string $field_name, \WP_REST_Request $request ): array {
		$post_id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		$template = $post_id ? $this->get_effective_template_slug( $post_id ) : $this->get_selected_template_slug();
		$allowed  = $this->get_active_meta_keys_for_template( $template );
		$descriptions = $this->get_meta_descriptions( $template );

		$filtered = [];
		foreach ( $allowed as $meta_key ) {
			if ( isset( $descriptions[ $meta_key ] ) && '' !== $descriptions[ $meta_key ] ) {
				$filtered[ $meta_key ] = (string) $descriptions[ $meta_key ];
			}
		}

		return $filtered;
	}

	public function get_rest_meta_note_field( array $object, string $field_name, \WP_REST_Request $request ): string {
		$post_id = isset( $object['id'] ) ? (int) $object['id'] : 0;
		$template = $post_id ? $this->get_effective_template_slug( $post_id ) : $this->get_selected_template_slug();

		return $this->get_meta_context_note( '', $template );
	}

	private function get_rest_meta_schema(): array {
		return $this->get_rest_meta_schema_for_template( $this->get_selected_template_slug() );
	}

	private function get_rest_meta_schema_for_template( string $template_slug ): array {
		$allowed = $this->get_active_meta_keys_for_template( $template_slug );
		$definitions = $this->get_meta_definitions();
		$descriptions = $this->get_meta_descriptions( $template_slug );
		$schema = [];

		foreach ( $definitions as $key => $definition ) {
			if ( ! \in_array( $key, $allowed, true ) ) {
				continue;
			}

			$show_in_rest = $definition['show_in_rest'];
			$field_schema = null;

			if ( true === $show_in_rest ) {
				$field_schema = [
					'type' => $definition['type'],
				];
			} elseif ( \is_array( $show_in_rest ) && isset( $show_in_rest['schema'] ) ) {
				$field_schema = $show_in_rest['schema'];
			}

			if ( ! \is_array( $field_schema ) ) {
				continue;
			}

			if ( isset( $descriptions[ $key ] ) && '' !== $descriptions[ $key ] ) {
				$field_schema['description'] = (string) $descriptions[ $key ];
			}

			$schema[ $key ] = $field_schema;
		}

		return $schema;
	}

	private function get_rest_meta_description_schema(): array {
		$template_slug = $this->get_selected_template_slug();
		$allowed = $this->get_active_meta_keys_for_template( $template_slug );
		$schema = [];

		foreach ( $allowed as $key ) {
			$schema[ $key ] = [
				'type' => 'string',
			];
		}

		return $schema;
	}

	private function get_meta_context_note( string $base = '', string $template_slug = '' ): string {
		$note = $base;
		$template_slug = '' !== $template_slug ? $template_slug : $this->get_selected_template_slug();
		if (
			$this->cta_has_content( $this->get_global_hero_cta() )
			|| $this->cta_has_content( $this->get_global_sidebar_cta() )
			|| $this->cta_has_content( $this->get_global_wide_cta() )
		) {
			$cta_note = __( 'Global CTAs are enabled; do not add CTA copy inside content fields.', 'nova-bridge-suite' );
			$note = '' === $note ? $cta_note : $note . ' ' . $cta_note;
		}

		if ( $this->template_component_active( $template_slug, 'tabs' ) ) {
			$tabs_note = __( 'Tabs: unfilled tabs are hidden. We recommend filling at least two tabs; if you only have one, consider placing that content in the main rich text instead.', 'nova-bridge-suite' );
			$note = '' === $note ? $tabs_note : $note . ' ' . $tabs_note;
		}

		return $note;
	}

	private function get_active_meta_keys_for_template( string $template_slug ): array {
		$hero_keys = [
			'sp_hero_eyebrow',
			'sp_hero_title',
			'sp_hero_copy',
			'sp_hero_primary_label',
			'sp_hero_primary_url',
			'sp_hero_secondary_label',
			'sp_hero_secondary_url',
		];
		$hero_cta_keys = [
			'sp_hero_primary_label',
			'sp_hero_primary_url',
			'sp_hero_secondary_label',
			'sp_hero_secondary_url',
		];
		$content_keys = [
			'sp_main_1',
			'sp_main_2',
			'sp_main_3',
		];
		$image_keys = [ 'sp_image_1', 'sp_image_2' ];
		$sidebar_keys = [
			'sp_sidebar_title',
			'sp_sidebar_copy',
			'sp_sidebar_primary_label',
			'sp_sidebar_primary_url',
			'sp_sidebar_secondary_label',
			'sp_sidebar_secondary_url',
		];
		$wide_cta_keys = [
			'sp_cta_title',
			'sp_cta_bullets',
			'sp_cta_button_label',
			'sp_cta_button_url',
			'sp_cta_more_text',
			'sp_cta_more_url',
		];
		$tab_keys = [
			'sp_tab_1_title',
			'sp_tab_1_content',
			'sp_tab_2_title',
			'sp_tab_2_content',
			'sp_tab_3_title',
			'sp_tab_3_content',
		];
		$extra_keys = [ 'sp_extra_copy' ];

		$keys = [];

		$has_hero = $this->template_component_active( $template_slug, 'hero' );
		if ( $has_hero ) {
			$keys = array_merge( $keys, $hero_keys );
		}

		$has_intro = $this->template_component_active( $template_slug, 'intro' );
		if ( $has_intro ) {
			$keys[] = 'sp_intro';
		}

		$has_content = $this->template_component_active( $template_slug, 'content' )
			|| $this->template_component_active( $template_slug, 'image_text' )
			|| $this->template_component_active( $template_slug, 'text_image' );
		if ( $has_content ) {
			$keys = array_merge( $keys, $content_keys );
		}

		if ( 'service-page-3' === $template_slug && $has_content ) {
			$keys[] = 'sp_table';
			$keys   = array_merge( $keys, $extra_keys );
		}

		$has_images = $this->template_component_active( $template_slug, 'image_text' )
			|| $this->template_component_active( $template_slug, 'text_image' );
		if ( $has_images ) {
			$keys = array_merge( $keys, $image_keys );
		}

		$has_sidebar_cta = $this->template_component_active( $template_slug, 'cta_cover' );
		if ( $has_sidebar_cta && ! $this->cta_has_content( $this->get_global_sidebar_cta() ) ) {
			$keys = array_merge( $keys, $sidebar_keys );
		}

		$has_wide_cta = $this->template_component_active( $template_slug, 'cta_wide' );
		if ( $has_wide_cta && ! $this->cta_has_content( $this->get_global_wide_cta() ) ) {
			$keys = array_merge( $keys, $wide_cta_keys );
		}

		$has_tabs = $this->template_component_active( $template_slug, 'tabs' );
		if ( $has_tabs ) {
			$keys = array_merge( $keys, $tab_keys );
		}

		$has_faq = $this->template_component_active( $template_slug, 'faq' );
		if ( $has_faq ) {
			$keys[] = 'sp_faq';
		}

		$has_related = $this->template_component_active( $template_slug, 'related' );
		if ( $has_related ) {
			$keys[] = 'sp_related_posts';
		}

		if ( $has_hero && $this->cta_has_content( $this->get_global_hero_cta() ) ) {
			$keys = array_diff( $keys, $hero_cta_keys );
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Registers REST endpoints for API consumption.
	 */
	public function register_rest_routes(): void {
		\register_rest_route(
			'service-pages/v1',
			'/page/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_page' ],
				'permission_callback' => [ $this, 'rest_can_read_page' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'required'          => true,
					],
				],
			]
		);
	}

	public function rest_can_read_page( \WP_REST_Request $request ): bool {
		$post_id = (int) $request['id'];

		if ( $post_id <= 0 ) {
			return false;
		}

		$post = \get_post( $post_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return false;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return \current_user_can( 'edit_post', $post_id );
	}

	public function rest_get_page( \WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$post    = \get_post( $post_id );

		if ( ! $post || self::CPT !== $post->post_type ) {
			return new \WP_Error( 'not_found', __( 'Service page not found.', 'nova-bridge-suite' ), [ 'status' => 404 ] );
		}

		$meta       = $this->get_meta_values( $post_id );
		$hero_cta = $this->get_effective_hero_cta( $meta );
		$effective_template = $this->get_effective_template_slug( $post_id );
		$global_template    = $this->get_selected_template_slug();
		$components = $this->get_template_component_settings( $effective_template );
		$sidebar_cta = $this->get_effective_sidebar_cta( $meta );
		$wide_cta = $this->get_effective_wide_cta( $meta );
		$has_hero = $this->template_component_active( $effective_template, 'hero' );
		$has_intro = $this->template_component_active( $effective_template, 'intro' );
		$has_content = $this->template_component_active( $effective_template, 'content' )
			|| $this->template_component_active( $effective_template, 'image_text' )
			|| $this->template_component_active( $effective_template, 'text_image' );
		$has_table = 'service-page-3' === $effective_template
			&& $this->template_component_active( $effective_template, 'content' );
		$has_images = $this->template_component_active( $effective_template, 'image_text' )
			|| $this->template_component_active( $effective_template, 'text_image' );
		$has_sidebar_cta = $this->template_component_active( $effective_template, 'cta_cover' );
		$has_wide_cta = $this->template_component_active( $effective_template, 'cta_wide' );
		$has_faq = $this->template_component_active( $effective_template, 'faq' );
		$has_tabs = $this->template_component_active( $effective_template, 'tabs' );
		$has_related = $this->template_component_active( $effective_template, 'related' );
		$has_extra = 'service-page-3' === $effective_template && $has_content;

		$data = [
			'id'         => $post_id,
			'slug'       => $post->post_name,
			'title'      => \get_the_title( $post ),
			'permalink'  => \get_permalink( $post ),
			'content_raw' => (string) $post->post_content,
			'content_rendered' => $this->render_service_page( $post_id ),
			'template'   => [
				'slug'   => $effective_template,
				'label'  => $this->get_template_label( $effective_template ),
				'source' => $this->get_template_source( $post_id ),
				'global' => [
					'slug'  => $global_template,
					'label' => $this->get_template_label( $global_template ),
				],
			],
			'components' => $components,
			'hero'       => [
				'eyebrow'        => $meta['sp_hero_eyebrow'],
				'title'          => $meta['sp_hero_title'],
				'copy'           => $meta['sp_hero_copy'],
				'primary_label'  => $hero_cta['primary_label'],
				'primary_url'    => $hero_cta['primary_url'],
				'secondary_label'=> $hero_cta['secondary_label'],
				'secondary_url'  => $hero_cta['secondary_url'],
			],
			'intro'      => $meta['sp_intro'],
			'content'    => [
				'sections' => [
					$meta['sp_main_1'],
					$meta['sp_main_2'],
					$meta['sp_main_3'],
				],
				'table'    => $meta['sp_table'],
			],
			'images'     => [
				'image_1' => $this->format_media( (int) $meta['sp_image_1'] ),
				'image_2' => $this->format_media( (int) $meta['sp_image_2'] ),
			],
			'sidebar_cta'=> [
				'title'          => $sidebar_cta['title'],
				'copy'           => $sidebar_cta['copy'],
				'primary_label'  => $sidebar_cta['primary_label'],
				'primary_url'    => $sidebar_cta['primary_url'],
				'secondary_label'=> $sidebar_cta['secondary_label'],
				'secondary_url'  => $sidebar_cta['secondary_url'],
			],
			'wide_cta'   => [
				'title'         => $wide_cta['title'],
				'bullets'       => $wide_cta['bullets'],
				'button_label'  => $wide_cta['button_label'],
				'button_url'    => $wide_cta['button_url'],
				'more_text'     => $wide_cta['more_text'],
				'more_url'      => $wide_cta['more_url'],
			],
			'extra'      => [
				'copy'    => $meta['sp_extra_copy'],
			],
			'tabs'       => [
				[
					'label'   => $meta['sp_tab_1_title'],
					'content' => $meta['sp_tab_1_content'],
				],
				[
					'label'   => $meta['sp_tab_2_title'],
					'content' => $meta['sp_tab_2_content'],
				],
				[
					'label'   => $meta['sp_tab_3_title'],
					'content' => $meta['sp_tab_3_content'],
				],
			],
			'faq'        => $meta['sp_faq'],
			'related_posts' => $meta['sp_related_posts'],
		];

		if ( ! $has_hero ) {
			unset( $data['hero'] );
		}

		if ( ! $has_intro ) {
			unset( $data['intro'] );
		}

		if ( ! $has_content ) {
			unset( $data['content'] );
		} elseif ( ! $has_table && isset( $data['content']['table'] ) ) {
			unset( $data['content']['table'] );
		}

		if ( ! $has_images ) {
			unset( $data['images'] );
		}

		if ( ! $has_sidebar_cta ) {
			unset( $data['sidebar_cta'] );
		}

		if ( ! $has_wide_cta ) {
			unset( $data['wide_cta'] );
		}

		if ( ! $has_faq ) {
			unset( $data['faq'] );
		}

		$global_hero_cta = $this->cta_has_content( $this->get_global_hero_cta() );
		if ( $global_hero_cta && isset( $data['hero'] ) && \is_array( $data['hero'] ) ) {
			unset(
				$data['hero']['primary_label'],
				$data['hero']['primary_url'],
				$data['hero']['secondary_label'],
				$data['hero']['secondary_url']
			);
		}

		$global_sidebar_cta = $this->cta_has_content( $this->get_global_sidebar_cta() );
		if ( $global_sidebar_cta ) {
			unset( $data['sidebar_cta'] );
		}

		$global_wide_cta = $this->cta_has_content( $this->get_global_wide_cta() );
		if ( $global_wide_cta ) {
			unset( $data['wide_cta'] );
		}

		if ( ! $has_tabs ) {
			unset( $data['tabs'] );
		}

		if ( ! $has_extra ) {
			unset( $data['extra'] );
		}

		if ( ! $has_related ) {
			unset( $data['related_posts'] );
		}

		return \rest_ensure_response( $data );
	}
}

Plugin::bootstrap();
