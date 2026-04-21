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
	 * Option key used to store component render-order priorities.
	 */
	private const OPTION_COMPONENT_ORDER = 'quarantined_cpt_bodyclean_component_order';

	/**
	 * Option key storing component visibility overrides per CPT.
	 */
	private const OPTION_COMPONENT_VISIBILITY_BY_CPT = 'quarantined_cpt_bodyclean_components_by_cpt';

	/**
	 * Option key storing component order overrides per CPT.
	 */
	private const OPTION_COMPONENT_ORDER_BY_CPT = 'quarantined_cpt_bodyclean_component_order_by_cpt';

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
	private const DEFAULT_HEADER_OFFSET = '2rem';

	/**
	 * Option key storing the global blog style preset.
	 */
	private const OPTION_BLOG_STYLE_PRESET = 'quarantined_cpt_bodyclean_blog_style_preset';

	/**
	 * Option key storing blog content max width.
	 */
	private const OPTION_BLOG_CONTENT_MAX_WIDTH = 'quarantined_cpt_bodyclean_blog_content_max_width';

	/**
	 * Option key storing blog body text color.
	 */
	private const OPTION_BLOG_TEXT_COLOR = 'quarantined_cpt_bodyclean_blog_text_color';

	/**
	 * Option key storing blog link color.
	 */
	private const OPTION_BLOG_LINK_COLOR = 'quarantined_cpt_bodyclean_blog_link_color';

	/**
	 * Option key storing blog link hover color.
	 */
	private const OPTION_BLOG_LINK_HOVER_COLOR = 'quarantined_cpt_bodyclean_blog_link_hover_color';

	/**
	 * Option key storing key takeaway/TOC panel background color.
	 */
	private const OPTION_BLOG_PANEL_BG = 'quarantined_cpt_bodyclean_blog_panel_bg';

	/**
	 * Option key storing panel border color.
	 */
	private const OPTION_BLOG_PANEL_BORDER = 'quarantined_cpt_bodyclean_blog_panel_border';

	/**
	 * Option key storing meta row divider color.
	 */
	private const OPTION_BLOG_META_BORDER = 'quarantined_cpt_bodyclean_blog_meta_border';

	/**
	 * Option key storing share chip background color.
	 */
	private const OPTION_BLOG_SHARE_BG = 'quarantined_cpt_bodyclean_blog_share_bg';

	/**
	 * Option key storing share chip border color.
	 */
	private const OPTION_BLOG_SHARE_BORDER = 'quarantined_cpt_bodyclean_blog_share_border';

	/**
	 * Option key storing wide CTA background color.
	 */
	private const OPTION_BLOG_CTA_BG = 'quarantined_cpt_bodyclean_blog_cta_bg';

	/**
	 * Option key storing wide CTA button background color.
	 */
	private const OPTION_BLOG_CTA_BUTTON_BG = 'quarantined_cpt_bodyclean_blog_cta_button_bg';

	/**
	 * Option key storing wide CTA button text color.
	 */
	private const OPTION_BLOG_CTA_BUTTON_TEXT = 'quarantined_cpt_bodyclean_blog_cta_button_text';

	/**
	 * Option key storing wide CTA button hover background color.
	 */
	private const OPTION_BLOG_CTA_BUTTON_HOVER = 'quarantined_cpt_bodyclean_blog_cta_button_hover';

	/**
	 * Option key storing default primary CTA title.
	 */
	private const OPTION_BLOG_CTA_PRIMARY_TITLE = 'quarantined_cpt_bodyclean_blog_cta_primary_title';

	/**
	 * Option key storing default primary CTA rich copy.
	 */
	private const OPTION_BLOG_CTA_PRIMARY_COPY = 'quarantined_cpt_bodyclean_blog_cta_primary_copy';

	/**
	 * Option key storing default primary CTA button label.
	 */
	private const OPTION_BLOG_CTA_PRIMARY_BUTTON_LABEL = 'quarantined_cpt_bodyclean_blog_cta_primary_button_label';

	/**
	 * Option key storing default primary CTA button URL.
	 */
	private const OPTION_BLOG_CTA_PRIMARY_BUTTON_URL = 'quarantined_cpt_bodyclean_blog_cta_primary_button_url';

	/**
	 * Option key storing default after-related CTA title.
	 */
	private const OPTION_BLOG_CTA_AFTER_RELATED_TITLE = 'quarantined_cpt_bodyclean_blog_cta_after_related_title';

	/**
	 * Option key storing default after-related CTA rich copy.
	 */
	private const OPTION_BLOG_CTA_AFTER_RELATED_COPY = 'quarantined_cpt_bodyclean_blog_cta_after_related_copy';

	/**
	 * Option key storing default after-related CTA button label.
	 */
	private const OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL = 'quarantined_cpt_bodyclean_blog_cta_after_related_button_label';

	/**
	 * Option key storing default after-related CTA button URL.
	 */
	private const OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_URL = 'quarantined_cpt_bodyclean_blog_cta_after_related_button_url';

	/**
	 * Option key storing optional CTA defaults per CPT.
	 */
	private const OPTION_BLOG_CTA_BY_CPT = 'quarantined_cpt_bodyclean_blog_cta_by_cpt';

	/**
	 * Option key storing optional archive layout overrides per CPT.
	 */
	private const OPTION_ARCHIVE_BY_CPT = 'quarantined_cpt_bodyclean_archive_by_cpt';

	/**
	 * Option key storing optional design overrides per CPT.
	 */
	private const OPTION_BLOG_STYLE_BY_CPT = 'quarantined_cpt_bodyclean_blog_style_by_cpt';

	/**
	 * Option key storing author box background color.
	 */
	private const OPTION_BLOG_AUTHOR_BOX_BG = 'quarantined_cpt_bodyclean_blog_author_box_bg';

	/**
	 * Option key storing author box border color.
	 */
	private const OPTION_BLOG_AUTHOR_BOX_BORDER = 'quarantined_cpt_bodyclean_blog_author_box_border';

	/**
	 * Option key storing related card corner radius.
	 */
	private const OPTION_BLOG_CARD_RADIUS = 'quarantined_cpt_bodyclean_blog_card_radius';

	/**
	 * Default blog style preset.
	 */
	private const DEFAULT_BLOG_STYLE_PRESET = 'theme';

	/**
	 * Default blog content max-width.
	 */
	private const DEFAULT_BLOG_CONTENT_MAX_WIDTH = '1600px';

	/**
	 * Default archive posts-per-page when not overridden per CPT.
	 */
	private const DEFAULT_ARCHIVE_POSTS_PER_PAGE = 15;

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
	 * Option key storing selected language used for default labels.
	 */
	private const OPTION_LABEL_LANGUAGE = 'quarantined_cpt_bodyclean_label_language';

	/**
	 * Option key storing the key takeaways heading label.
	 */
	private const OPTION_LABEL_KEY_TAKEAWAYS = 'quarantined_cpt_bodyclean_label_key_takeaways';

	/**
	 * Option key storing the table of contents heading label.
	 */
	private const OPTION_LABEL_TOC = 'quarantined_cpt_bodyclean_label_toc';

	/**
	 * Option key storing the table of contents expand-button label.
	 */
	private const OPTION_LABEL_TOC_READ_MORE = 'quarantined_cpt_bodyclean_label_toc_read_more';

	/**
	 * Option key storing the table of contents collapse/jump-back label.
	 */
	private const OPTION_LABEL_TOC_READ_LESS = 'quarantined_cpt_bodyclean_label_toc_read_less';

	/**
	 * Option key storing the related articles section heading label.
	 */
	private const OPTION_LABEL_RELATED_ARTICLES = 'quarantined_cpt_bodyclean_label_related_articles';

	/**
	 * Option key storing the frequently asked questions section heading label.
	 */
	private const OPTION_LABEL_FAQ_TITLE = 'quarantined_cpt_bodyclean_label_faq_title';

	/**
	 * Option key storing the publications label.
	 */
	private const OPTION_LABEL_PUBLICATIONS = 'quarantined_cpt_bodyclean_label_publications';

	/**
	 * Settings that should save per language when WPML or Polylang is active.
	 */
	private const LOCALIZED_SETTINGS_OPTIONS = [
		self::OPTION_AUTHOR_ARCHIVE_TITLE,
		self::OPTION_CPT_SINGULAR,
		self::OPTION_CPT_PLURAL,
		self::OPTION_BLOG_CTA_PRIMARY_TITLE,
		self::OPTION_BLOG_CTA_PRIMARY_COPY,
		self::OPTION_BLOG_CTA_PRIMARY_BUTTON_LABEL,
		self::OPTION_BLOG_CTA_PRIMARY_BUTTON_URL,
		self::OPTION_BLOG_CTA_AFTER_RELATED_TITLE,
		self::OPTION_BLOG_CTA_AFTER_RELATED_COPY,
		self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL,
		self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_URL,
		self::OPTION_BLOG_CTA_BY_CPT,
		self::OPTION_ARCHIVE_BY_CPT,
		self::OPTION_LABEL_AUTHOR,
		self::OPTION_LABEL_KEY_TAKEAWAYS,
		self::OPTION_LABEL_TOC,
		self::OPTION_LABEL_TOC_READ_MORE,
		self::OPTION_LABEL_TOC_READ_LESS,
		self::OPTION_LABEL_RELATED_ARTICLES,
		self::OPTION_LABEL_FAQ_TITLE,
		self::OPTION_LABEL_PUBLICATIONS,
	];

	/**
	 * Default author label text.
	 */
	private const DEFAULT_LABEL_AUTHOR = 'Door';

	/**
	 * Default language selection.
	 */
	private const DEFAULT_LABEL_LANGUAGE = 'auto';

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
	 * Post meta key storing the intro copy above the blog body.
	 */
	private const META_BLOG_INTRO = 'blog_intro';

	/**
	 * Post meta key storing optional key takeaway bullet items.
	 */
	private const META_BLOG_KEY_TAKEAWAYS = 'blog_key_takeaways';

	/**
	 * Legacy post meta key for manual table-of-contents entries (cleanup only).
	 */
	private const META_BLOG_TOC = 'blog_toc';

	/**
	 * Post meta key storing whether the auto-generated table of contents is disabled per post.
	 */
	private const META_BLOG_TOC_DISABLE = 'blog_toc_disable';

	/**
	 * Post meta key storing the first rich-text body section.
	 */
	private const META_BLOG_PART_1 = 'blog_part_1';

	/**
	 * Post meta key storing optional wide CTA title text.
	 */
	private const META_BLOG_CTA_TITLE = 'blog_cta_title';

	/**
	 * Post meta key storing optional wide CTA rich text.
	 */
	private const META_BLOG_CTA_COPY = 'blog_cta_copy';

	/**
	 * Post meta key storing optional wide CTA button label.
	 */
	private const META_BLOG_CTA_BUTTON_LABEL = 'blog_cta_button_label';

	/**
	 * Post meta key storing optional wide CTA button URL.
	 */
	private const META_BLOG_CTA_BUTTON_URL = 'blog_cta_button_url';

	/**
	 * Post meta key storing whether global/default primary CTA is disabled for the post.
	 */
	private const META_BLOG_CTA_DISABLE = 'blog_cta_disable';

	/**
	 * Post meta key storing the second rich-text body section.
	 */
	private const META_BLOG_PART_2 = 'blog_part_2';

	/**
	 * Post meta key storing optional related post IDs.
	 */
	private const META_BLOG_RELATED_POSTS = 'blog_related_posts';

	/**
	 * Post meta key storing optional FAQ entries.
	 */
	private const META_BLOG_FAQS = 'blog_faqs';

	/**
	 * Post meta key storing optional after-related wide CTA title text.
	 */
	private const META_BLOG_CTA_AFTER_RELATED_TITLE = 'blog_cta_after_related_title';

	/**
	 * Post meta key storing optional after-related wide CTA rich text.
	 */
	private const META_BLOG_CTA_AFTER_RELATED_COPY = 'blog_cta_after_related_copy';

	/**
	 * Post meta key storing optional after-related wide CTA button label.
	 */
	private const META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL = 'blog_cta_after_related_button_label';

	/**
	 * Post meta key storing optional after-related wide CTA button URL.
	 */
	private const META_BLOG_CTA_AFTER_RELATED_BUTTON_URL = 'blog_cta_after_related_button_url';

	/**
	 * Post meta key storing whether global/default after-related CTA is disabled for the post.
	 */
	private const META_BLOG_CTA_AFTER_RELATED_DISABLE = 'blog_cta_after_related_disable';

	/**
	 * Legacy post meta key for manual read-time overrides (cleanup only).
	 */
	private const META_BLOG_LEGACY_READ_TIME = 'blog_read_time_minutes';

	/**
	 * Words-per-minute baseline used for read-time estimation.
	 */
	private const BLOG_READ_WPM = 220;

	/**
	 * Character length used for generated blog excerpts.
	 */
	private const BLOG_EXCERPT_LENGTH = 30;

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
	 * Tracks slug renames during a settings save request.
	 *
	 * @var array<string,string>
	 */
	private $pending_cpt_slug_renames = [];

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
		add_action( 'init', [ $this, 'register_blog_meta_fields' ], 11 );
		add_action( 'init', [ $this, 'maybe_normalize_managed_draft_slugs' ], 98 );
		add_filter( 'pre_wp_unique_post_slug', [ $this, 'preserve_managed_cpt_slug' ], 10, 6 );
		add_filter( 'template_include', [ $this, 'force_isolated_template' ] );
		add_filter( 'body_class', [ $this, 'append_body_class' ] );
		add_filter( 'enter_title_here', [ $this, 'update_title_placeholder' ], 10, 2 );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'force_classic_editor' ], 100, 2 );
		add_filter( 'gutenberg_can_edit_post_type', [ $this, 'force_classic_editor' ], 100, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 100 );
		add_action( 'wp_head', [ $this, 'output_author_schema' ], 5 );
		add_action( 'wp_head', [ $this, 'output_article_schema' ], 6 );
		add_action( 'wp_head', [ $this, 'output_author_breadcrumb_schema' ], 7 );
		add_filter( 'wpseo_schema_person', [ $this, 'filter_wpseo_person_schema' ], 10, 2 );
		add_filter( 'wpseo_schema_graph_pieces', [ $this, 'filter_wpseo_graph_pieces' ], 5 );
		add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
		add_action( 'pre_get_posts', [ $this, 'include_in_author_archives' ], 20 );
		add_action( 'pre_get_posts', [ $this, 'apply_archive_posts_per_page' ], 25 );
		add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_icon_styles' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_blog_meta_box' ] );
		add_filter( 'hidden_meta_boxes', [ $this, 'filter_hidden_meta_boxes' ], 10, 3 );
		add_action( 'save_post', [ $this, 'save_blog_meta_box' ], 10, 3 );
		add_action( 'rest_api_init', [ $this, 'register_blog_rest_fields' ] );
		add_action( 'rest_api_init', [ $this, 'register_blog_rest_prepare_filters' ] );
		add_action( 'rest_api_init', [ $this, 'register_blog_rest_routes' ] );
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
	 * Preserves requested slugs for managed CPT items when the only collision is an attachment.
	 *
	 * WordPress treats hierarchical post types like pages during slug generation and checks
	 * sibling attachments too. That causes uploaded hero images such as `example.jpg` to force
	 * a managed article slug like `example` to become `example-2`, even though the public URLs
	 * live under distinct CPT bases like `/kennisbank/` and `/how-to/`.
	 *
	 * @param string|null $override_slug Short-circuit return value from earlier filters.
	 * @param string      $slug          Requested slug.
	 * @param int         $post_id       Post ID.
	 * @param string      $_post_status  Post status.
	 * @param string      $post_type     Post type.
	 * @param int         $post_parent   Parent ID.
	 * @return string|null
	 */
	public function preserve_managed_cpt_slug( $override_slug, $slug, $post_id, $_post_status, $post_type, $post_parent ) {
		if ( null !== $override_slug ) {
			return $override_slug;
		}

		$post_type = sanitize_key( (string) $post_type );
		$slug      = sanitize_title( (string) $slug );

		if ( $this->should_preserve_managed_cpt_slug( $slug, $post_type, (int) $post_id, (int) $post_parent ) ) {
			return $slug;
		}

		return null;
	}

	/**
	 * Determines whether a managed CPT slug can safely ignore attachment collisions.
	 *
	 * @param string $slug Requested slug.
	 * @param string $post_type Post type.
	 * @param int    $post_id Post ID.
	 * @param int    $post_parent Parent ID.
	 * @return bool
	 */
	private function should_preserve_managed_cpt_slug( string $slug, string $post_type, int $post_id, int $post_parent ): bool {
		if ( ! $this->is_managed_post_type( $post_type ) || '' === $slug ) {
			return false;
		}

		global $wpdb, $wp_rewrite;

		$feeds = $wp_rewrite->feeds;
		if ( ! is_array( $feeds ) ) {
			$feeds = [];
		}

		$pagination_base = is_string( $wp_rewrite->pagination_base ) ? preg_quote( $wp_rewrite->pagination_base, '@' ) : '';

		if ( in_array( $slug, $feeds, true )
			|| 'embed' === $slug
			|| preg_match( '@^(' . $pagination_base . ')?\d+$@', $slug )
		) {
			return false;
		}

		$post_name_check = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_name
				FROM $wpdb->posts
				WHERE post_name = %s
					AND post_type = %s
					AND ID != %d
					AND post_parent = %d
				LIMIT 1",
				$slug,
				$post_type,
				$post_id,
				$post_parent
			)
		);

		if ( $post_name_check ) {
			return false;
		}

		return true;
	}

	/**
	 * Normalizes existing managed draft slugs that were suffixed only because of attachment collisions.
	 *
	 * Runs once after upgrading to a new plugin version, before the main plugin version option is
	 * updated. Published entries are intentionally excluded to avoid changing live URLs implicitly.
	 *
	 * @return void
	 */
	public function maybe_normalize_managed_draft_slugs(): void {
		if ( ! defined( 'NOVA_BRIDGE_SUITE_VERSION' ) || ! defined( 'NOVA_BRIDGE_SUITE_VERSION_OPTION' ) ) {
			return;
		}

		$installed_version = (string) get_option( NOVA_BRIDGE_SUITE_VERSION_OPTION, '' );

		if ( NOVA_BRIDGE_SUITE_VERSION === $installed_version ) {
			return;
		}

		$post_types = $this->get_cpt_types( false );

		if ( empty( $post_types ) ) {
			return;
		}

		global $wpdb;

		$type_placeholders = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$args              = array_merge( $post_types, [ 'draft', 'pending', '-[0-9]+$' ] );
		$candidates        = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_name, post_parent, post_type
				FROM $wpdb->posts
				WHERE post_type IN ( $type_placeholders )
					AND post_status IN ( %s, %s )
					AND post_name REGEXP %s",
				...$args
			)
		);

		if ( empty( $candidates ) ) {
			return;
		}

		$normalized = 0;

		foreach ( $candidates as $candidate ) {
			$post_id     = isset( $candidate->ID ) ? (int) $candidate->ID : 0;
			$post_parent = isset( $candidate->post_parent ) ? (int) $candidate->post_parent : 0;
			$post_type   = isset( $candidate->post_type ) ? sanitize_key( (string) $candidate->post_type ) : '';
			$current     = isset( $candidate->post_name ) ? sanitize_title( (string) $candidate->post_name ) : '';
			$desired     = preg_replace( '/-\d+$/', '', $current );

			if ( ! is_string( $desired ) || '' === $desired || $desired === $current ) {
				continue;
			}

			if ( ! $this->should_preserve_managed_cpt_slug( $desired, $post_type, $post_id, $post_parent ) ) {
				continue;
			}

			$attachment_conflict = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID
					FROM $wpdb->posts
					WHERE post_name = %s
						AND post_type = 'attachment'
						AND ID != %d
						AND post_parent = %d
					LIMIT 1",
					$desired,
					$post_id,
					$post_parent
				)
			);

			if ( ! $attachment_conflict ) {
				continue;
			}

			$result = wp_update_post(
				[
					'ID'        => $post_id,
					'post_name' => $desired,
				],
				true
			);

			if ( ! is_wp_error( $result ) ) {
				++$normalized;
			}
		}

		if ( $normalized > 0 ) {
			self::log( 'Normalized ' . $normalized . ' managed draft slugs after attachment-only collisions.' );
		}
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
				'hierarchical'       => true,
				'publicly_queryable' => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_rest'       => true,
				'rest_base'          => $rest_base,
				'menu_position'      => 22,
				'menu_icon'          => plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt-rocket.svg',
				'supports'           => [ 'title', 'author', 'thumbnail', 'revisions', 'page-attributes' ],
				'rewrite'            => [
					'slug'         => $base_slug,
					'with_front'   => false,
					'hierarchical' => true,
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
	 * Returns available blog style preset labels.
	 *
	 * @return array<string,string>
	 */
	private function get_blog_style_preset_options(): array {
		$options = [
			'theme'    => __( 'Theme Native (recommended)', 'nova-bridge-suite' ),
			'balanced' => __( 'Balanced', 'nova-bridge-suite' ),
			'enhanced' => __( 'Enhanced', 'nova-bridge-suite' ),
		];

		return apply_filters( 'quarantined_cpt_bodyclean/blog_style_presets', $options, $this );
	}

	/**
	 * Returns the active blog style preset.
	 *
	 * @return string
	 */
	private function get_blog_style_preset(): string {
		$stored = get_option( self::OPTION_BLOG_STYLE_PRESET, self::DEFAULT_BLOG_STYLE_PRESET );

		return $this->sanitize_blog_style_preset_option( $stored );
	}

	/**
	 * Returns the configured blog content width.
	 *
	 * @return string
	 */
	private function get_blog_content_max_width_setting(): string {
		$stored = get_option( self::OPTION_BLOG_CONTENT_MAX_WIDTH, self::DEFAULT_BLOG_CONTENT_MAX_WIDTH );

		return $this->sanitize_blog_content_max_width_option( $stored );
	}

	/**
	 * Returns the configured blog card radius.
	 *
	 * @return string
	 */
	private function get_blog_card_radius_setting(): string {
		$stored = get_option( self::OPTION_BLOG_CARD_RADIUS, '' );

		return $this->sanitize_blog_card_radius_option( $stored );
	}

	/**
	 * Returns a sanitized color override option.
	 *
	 * @param string $option_name Option key.
	 * @return string
	 */
	private function get_blog_color_option_value( string $option_name ): string {
		$stored = get_option( $option_name, '' );

		return $this->sanitize_blog_color_option( $stored );
	}

	/**
	 * Returns CTA slot option map.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function get_blog_cta_slot_option_map(): array {
		return [
			'primary'       => [
				'title'        => self::OPTION_BLOG_CTA_PRIMARY_TITLE,
				'copy'         => self::OPTION_BLOG_CTA_PRIMARY_COPY,
				'button_label' => self::OPTION_BLOG_CTA_PRIMARY_BUTTON_LABEL,
				'button_url'   => self::OPTION_BLOG_CTA_PRIMARY_BUTTON_URL,
			],
			'after_related' => [
				'title'        => self::OPTION_BLOG_CTA_AFTER_RELATED_TITLE,
				'copy'         => self::OPTION_BLOG_CTA_AFTER_RELATED_COPY,
				'button_label' => self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL,
				'button_url'   => self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_URL,
			],
		];
	}

	/**
	 * Returns an empty CTA payload shape.
	 *
	 * @return array<string,string>
	 */
	private function get_empty_blog_cta_payload(): array {
		return [
			'title'        => '',
			'copy'         => '',
			'button_label' => '',
			'button_url'   => '',
		];
	}

	/**
	 * Returns per-CPT CTA override map.
	 *
	 * @return array<string,array<string,array<string,string>>>
	 */
	private function get_blog_cta_overrides_by_cpt(): array {
		$saved = get_option( self::OPTION_BLOG_CTA_BY_CPT, [] );

		return $this->sanitize_blog_cta_defaults_by_cpt( $saved );
	}

	/**
	 * Returns per-CPT archive layout override map.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_archive_settings_overrides_by_cpt(): array {
		$saved = get_option( self::OPTION_ARCHIVE_BY_CPT, [] );

		return $this->sanitize_archive_settings_by_cpt( $saved );
	}

	/**
	 * Returns the default archive posts-per-page value.
	 *
	 * @return int
	 */
	private function get_archive_default_posts_per_page(): int {
		$default = (int) apply_filters(
			'quarantined_cpt_bodyclean/archive_posts_per_page_default',
			self::DEFAULT_ARCHIVE_POSTS_PER_PAGE,
			$this
		);

		return max( 1, min( 200, $default ) );
	}

	/**
	 * Returns effective archive layout settings for a specific CPT.
	 *
	 * @param string $post_type CPT key.
	 * @return array<string,mixed>
	 */
	private function get_archive_settings_for_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$defaults  = [
			'intro'             => '',
			'posts_per_page'    => $this->get_archive_default_posts_per_page(),
			'cta_before'        => $this->build_effective_blog_cta_payload(
				'',
				'',
				'',
				'',
				true,
				$this->get_blog_global_cta_defaults( 'primary', $post_type )
			),
			'cta_after'         => $this->build_effective_blog_cta_payload(
				'',
				'',
				'',
				'',
				false,
				$this->get_blog_global_cta_defaults( 'after_related', $post_type )
			),
			'content_after_cta' => '',
		];
		$defaults['cta'] = $defaults['cta_after']; // Legacy alias.

		if ( '' === $post_type ) {
			return $defaults;
		}

		$overrides = $this->get_archive_settings_overrides_by_cpt();

		if ( ! isset( $overrides[ $post_type ] ) || ! is_array( $overrides[ $post_type ] ) ) {
			return $defaults;
		}

		$payload = $overrides[ $post_type ];
		$intro   = isset( $payload['intro'] ) ? (string) $payload['intro'] : '';
		$bottom  = isset( $payload['content_after_cta'] ) ? (string) $payload['content_after_cta'] : '';

		if ( $this->blog_html_has_text( $intro ) ) {
			$defaults['intro'] = $intro;
		}

		if ( $this->blog_html_has_text( $bottom ) ) {
			$defaults['content_after_cta'] = $bottom;
		}

		if ( isset( $payload['posts_per_page'] ) ) {
			$posts_per_page = max( 1, min( 200, absint( $payload['posts_per_page'] ) ) );

			if ( $posts_per_page > 0 ) {
				$defaults['posts_per_page'] = $posts_per_page;
			}
		}

		$cta_before_payload = isset( $payload['cta_before'] ) && is_array( $payload['cta_before'] ) ? $payload['cta_before'] : [];
		$cta_after_payload  = isset( $payload['cta_after'] ) && is_array( $payload['cta_after'] ) ? $payload['cta_after'] : [];

		// Backward compatibility for older archive CTA payload key.
		if ( empty( $cta_after_payload ) && isset( $payload['cta'] ) && is_array( $payload['cta'] ) ) {
			$cta_after_payload = $payload['cta'];
		}

		$cta_before_disabled = array_key_exists( 'cta_before_disabled', $payload )
			? self::sanitize_blog_bool_flag( $payload['cta_before_disabled'] )
			: true;
		$cta_after_disabled  = array_key_exists( 'cta_after_disabled', $payload )
			? self::sanitize_blog_bool_flag( $payload['cta_after_disabled'] )
			: false;

		$defaults['cta_before'] = $this->build_effective_blog_cta_payload(
			$this->sanitize_text_option( $cta_before_payload['title'] ?? '' ),
			$this->sanitize_blog_rich_text( is_string( $cta_before_payload['copy'] ?? '' ) ? $cta_before_payload['copy'] : '' ),
			$this->sanitize_text_option( $cta_before_payload['button_label'] ?? '' ),
			self::sanitize_blog_cta_url( $cta_before_payload['button_url'] ?? '' ),
			$cta_before_disabled,
			$this->get_blog_global_cta_defaults( 'primary', $post_type )
		);

		$defaults['cta_after'] = $this->build_effective_blog_cta_payload(
			$this->sanitize_text_option( $cta_after_payload['title'] ?? '' ),
			$this->sanitize_blog_rich_text( is_string( $cta_after_payload['copy'] ?? '' ) ? $cta_after_payload['copy'] : '' ),
			$this->sanitize_text_option( $cta_after_payload['button_label'] ?? '' ),
			self::sanitize_blog_cta_url( $cta_after_payload['button_url'] ?? '' ),
			$cta_after_disabled,
			$this->get_blog_global_cta_defaults( 'after_related', $post_type )
		);
		$defaults['cta'] = $defaults['cta_after'];

		return $defaults;
	}

	/**
	 * Returns global fallback CTA defaults for a layout slot.
	 *
	 * @param string $slot      Slot key: primary|after_related.
	 * @param string $post_type Optional CPT for per-CPT overrides.
	 * @return array<string,string>
	 */
	private function get_blog_global_cta_defaults( string $slot, string $post_type = '' ): array {
		$map = $this->get_blog_cta_slot_option_map();

		if ( ! isset( $map[ $slot ] ) ) {
			return $this->get_empty_blog_cta_payload();
		}

		$config = $map[ $slot ];
		$title_raw        = get_option( $config['title'], '' );
		$copy_raw         = get_option( $config['copy'], '' );
		$button_label_raw = get_option( $config['button_label'], '' );
		$button_url_raw   = get_option( $config['button_url'], '' );

		$global = [
			'title'        => $this->sanitize_text_option( $title_raw ),
			'copy'         => $this->sanitize_blog_rich_text( is_string( $copy_raw ) ? $copy_raw : '' ),
			'button_label' => $this->sanitize_text_option( $button_label_raw ),
			'button_url'   => self::sanitize_blog_cta_url( is_string( $button_url_raw ) ? $button_url_raw : '' ),
		];

		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			return $global;
		}

		$overrides = $this->get_blog_cta_overrides_by_cpt();

		if ( ! isset( $overrides[ $post_type ][ $slot ] ) || ! is_array( $overrides[ $post_type ][ $slot ] ) ) {
			return $global;
		}

		$override = $overrides[ $post_type ][ $slot ];

		$override_title = isset( $override['title'] ) ? trim( (string) $override['title'] ) : '';
		$override_copy  = isset( $override['copy'] ) ? (string) $override['copy'] : '';
		$override_label = isset( $override['button_label'] ) ? trim( (string) $override['button_label'] ) : '';
		$override_url   = isset( $override['button_url'] ) ? trim( (string) $override['button_url'] ) : '';

		if ( '' !== $override_title ) {
			$global['title'] = $override_title;
		}

		if ( $this->blog_html_has_text( $override_copy ) ) {
			$global['copy'] = $override_copy;
		}

		if ( '' !== $override_label ) {
			$global['button_label'] = $override_label;
		}

		if ( '' !== $override_url ) {
			$global['button_url'] = $override_url;
		}

		return $global;
	}

	/**
	 * Builds the effective CTA payload by combining global defaults with per-post overrides.
	 *
	 * @param string $title           Post-level title value.
	 * @param string $copy            Post-level rich copy value.
	 * @param string $button_label    Post-level button label value.
	 * @param string $button_url      Post-level button URL value.
	 * @param bool   $disabled        Whether CTA is disabled on this post.
	 * @param array<string,string> $global_defaults Global defaults for this CTA slot.
	 * @return array<string,mixed>
	 */
	private function build_effective_blog_cta_payload(
		string $title,
		string $copy,
		string $button_label,
		string $button_url,
		bool $disabled,
		array $global_defaults
	): array {
		$title = trim( $title );
		$copy  = (string) $copy;
		$button_label = trim( $button_label );
		$button_url   = trim( $button_url );

		$global_title        = isset( $global_defaults['title'] ) ? trim( (string) $global_defaults['title'] ) : '';
		$global_copy         = isset( $global_defaults['copy'] ) ? (string) $global_defaults['copy'] : '';
		$global_button_label = isset( $global_defaults['button_label'] ) ? trim( (string) $global_defaults['button_label'] ) : '';
		$global_button_url   = isset( $global_defaults['button_url'] ) ? trim( (string) $global_defaults['button_url'] ) : '';

		$effective = [
			'title'        => '' !== $title ? $title : $global_title,
			'copy'         => $this->blog_html_has_text( $copy ) ? $copy : $global_copy,
			'button_label' => '' !== $button_label ? $button_label : $global_button_label,
			'button_url'   => '' !== $button_url ? $button_url : $global_button_url,
		];

		$active = ! $disabled && $this->blog_cta_has_content(
			$effective['title'],
			$effective['copy'],
			$effective['button_label'],
			$effective['button_url']
		);

		return [
			'active'         => $active,
			'disabled'       => $disabled,
			'title'          => $effective['title'],
			'copy'           => $effective['copy'],
			'button_label'   => $effective['button_label'],
			'button_url'     => $effective['button_url'],
			'global_fallback'=> [
				'title'        => $global_title,
				'copy'         => $global_copy,
				'button_label' => $global_button_label,
				'button_url'   => $global_button_url,
			],
		];
	}

	/**
	 * Determines whether a CTA payload has enough content to render.
	 *
	 * @param string $title CTA title.
	 * @param string $copy CTA rich copy.
	 * @param string $button_label CTA button label.
	 * @param string $button_url CTA button URL.
	 * @return bool
	 */
	private function blog_cta_has_content( string $title, string $copy, string $button_label, string $button_url ): bool {
		return '' !== trim( $title ) || $this->blog_html_has_text( $copy ) || ( '' !== trim( $button_label ) && '' !== trim( $button_url ) );
	}

	/**
	 * Returns preset defaults for blog style variables.
	 *
	 * @param string $preset Preset slug.
	 * @return array<string,string>
	 */
	private function get_blog_style_defaults_for_preset( string $preset ): array {
		$defaults = [
			'theme'    => [
				'text_color'           => '',
				'link_color'           => '',
				'link_hover_color'     => '',
				'panel_background'     => 'transparent',
				'panel_border'         => 'rgba(15,23,42,0.16)',
				'meta_border'          => 'rgba(15,23,42,0.16)',
				'share_background'     => 'transparent',
				'share_background_hover'=> 'rgba(15,23,42,0.06)',
				'share_border'         => 'rgba(15,23,42,0.22)',
				'cta_background'       => '#e9efff',
				'cta_button_background'=> '',
				'cta_button_text'      => '',
				'cta_button_hover'     => '',
				'author_box_background'=> 'transparent',
				'author_box_border'    => 'rgba(15,23,42,0.16)',
				'card_radius'          => '0.55rem',
				'card_shadow'          => 'none',
				'card_shadow_hover'    => 'none',
				'card_border'          => '1px solid rgba(15,23,42,0.16)',
			],
			'balanced' => [
				'text_color'           => '',
				'link_color'           => '',
				'link_hover_color'     => '',
				'panel_background'     => '#f6f7f9',
				'panel_border'         => 'rgba(15,23,42,0.12)',
				'meta_border'          => 'rgba(15,23,42,0.12)',
				'share_background'     => '#ffffff',
				'share_background_hover'=> 'rgba(15,23,42,0.06)',
				'share_border'         => 'rgba(15,23,42,0.16)',
				'cta_background'       => '#f1f3f6',
				'cta_button_background'=> '#1d4ed8',
				'cta_button_text'      => '#ffffff',
				'cta_button_hover'     => '#1b44bb',
				'author_box_background'=> '#ffffff',
				'author_box_border'    => 'rgba(15,23,42,0.12)',
				'card_radius'          => '0.85rem',
				'card_shadow'          => '0 18px 35px -20px rgba(17,17,17,0.35)',
				'card_shadow_hover'    => '0 22px 45px -20px rgba(17,17,17,0.4)',
				'card_border'          => '1px solid transparent',
			],
			'enhanced' => [
				'text_color'           => '',
				'link_color'           => '',
				'link_hover_color'     => '',
				'panel_background'     => '#f8fafc',
				'panel_border'         => 'rgba(15,23,42,0.14)',
				'meta_border'          => 'rgba(15,23,42,0.14)',
				'share_background'     => '#ffffff',
				'share_background_hover'=> 'rgba(15,23,42,0.08)',
				'share_border'         => 'rgba(15,23,42,0.2)',
				'cta_background'       => '#e9efff',
				'cta_button_background'=> '#1d4ed8',
				'cta_button_text'      => '#ffffff',
				'cta_button_hover'     => '#1b44bb',
				'author_box_background'=> '#ffffff',
				'author_box_border'    => 'rgba(15,23,42,0.14)',
				'card_radius'          => '1rem',
				'card_shadow'          => '0 22px 42px -24px rgba(17,17,17,0.44)',
				'card_shadow_hover'    => '0 26px 50px -24px rgba(17,17,17,0.52)',
				'card_border'          => '1px solid transparent',
			],
		];

		if ( ! array_key_exists( $preset, $defaults ) ) {
			$preset = self::DEFAULT_BLOG_STYLE_PRESET;
		}

		return apply_filters(
			'quarantined_cpt_bodyclean/blog_style_preset_defaults',
			$defaults[ $preset ],
			$preset,
			$defaults,
			$this
		);
	}

	/**
	 * Returns merged style settings (preset defaults + custom overrides).
	 *
	 * @return array<string,string>
	 */
	private function get_blog_style_settings(): array {
		$preset   = $this->get_blog_style_preset();
		$defaults = $this->get_blog_style_defaults_for_preset( $preset );
		$settings = $defaults;

		$settings['preset']            = $preset;
		$settings['content_max_width'] = $this->get_blog_content_max_width_setting();

		$color_map = [
			self::OPTION_BLOG_TEXT_COLOR        => 'text_color',
			self::OPTION_BLOG_LINK_COLOR        => 'link_color',
			self::OPTION_BLOG_LINK_HOVER_COLOR  => 'link_hover_color',
			self::OPTION_BLOG_PANEL_BG         => 'panel_background',
			self::OPTION_BLOG_PANEL_BORDER     => 'panel_border',
			self::OPTION_BLOG_META_BORDER      => 'meta_border',
			self::OPTION_BLOG_SHARE_BG         => 'share_background',
			self::OPTION_BLOG_SHARE_BORDER     => 'share_border',
			self::OPTION_BLOG_CTA_BG           => 'cta_background',
			self::OPTION_BLOG_CTA_BUTTON_BG    => 'cta_button_background',
			self::OPTION_BLOG_CTA_BUTTON_TEXT  => 'cta_button_text',
			self::OPTION_BLOG_CTA_BUTTON_HOVER => 'cta_button_hover',
			self::OPTION_BLOG_AUTHOR_BOX_BG    => 'author_box_background',
			self::OPTION_BLOG_AUTHOR_BOX_BORDER=> 'author_box_border',
		];

		foreach ( $color_map as $option_name => $setting_key ) {
			$custom = $this->get_blog_color_option_value( $option_name );

			if ( '' !== $custom ) {
				$settings[ $setting_key ] = $custom;
			}
		}

		$custom_card_radius = $this->get_blog_card_radius_setting();

		if ( '' !== $custom_card_radius ) {
			$settings['card_radius'] = $custom_card_radius;
		}

		if ( '' === (string) ( $settings['link_hover_color'] ?? '' ) && '' !== (string) ( $settings['link_color'] ?? '' ) ) {
			$settings['link_hover_color'] = (string) $settings['link_color'];
		}

		if ( '' === (string) ( $settings['cta_button_hover'] ?? '' ) && '' !== (string) ( $settings['cta_button_background'] ?? '' ) ) {
			$settings['cta_button_hover'] = (string) $settings['cta_button_background'];
		}

		return apply_filters( 'quarantined_cpt_bodyclean/blog_style_settings', $settings, $preset, $defaults, $this );
	}

	/**
	 * Returns an empty per-CPT blog style override payload.
	 *
	 * @return array<string,string>
	 */
	private function get_empty_blog_style_override_payload(): array {
		return [
			'text_color'            => '',
			'link_color'            => '',
			'link_hover_color'      => '',
			'cta_button_background' => '',
			'cta_button_text'       => '',
			'cta_button_hover'      => '',
		];
	}

	/**
	 * Returns per-CPT blog style override map.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function get_blog_style_overrides_by_cpt(): array {
		$saved = get_option( self::OPTION_BLOG_STYLE_BY_CPT, [] );

		return $this->sanitize_blog_style_overrides_by_cpt( $saved );
	}

	/**
	 * Returns effective blog style settings for a specific CPT.
	 *
	 * @param string $post_type CPT key.
	 * @return array<string,string>
	 */
	private function get_blog_style_settings_for_type( string $post_type ): array {
		$settings  = $this->get_blog_style_settings();
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			return $settings;
		}

		$overrides = $this->get_blog_style_overrides_by_cpt();

		if ( ! isset( $overrides[ $post_type ] ) || ! is_array( $overrides[ $post_type ] ) ) {
			return $settings;
		}

		foreach ( $this->get_empty_blog_style_override_payload() as $key => $default ) {
			$override = isset( $overrides[ $post_type ][ $key ] ) ? (string) $overrides[ $post_type ][ $key ] : '';

			if ( '' !== $override ) {
				$settings[ $key ] = $override;
			}
		}

		if ( '' === (string) ( $settings['link_hover_color'] ?? '' ) && '' !== (string) ( $settings['link_color'] ?? '' ) ) {
			$settings['link_hover_color'] = (string) $settings['link_color'];
		}

		if ( '' === (string) ( $settings['cta_button_hover'] ?? '' ) && '' !== (string) ( $settings['cta_button_background'] ?? '' ) ) {
			$settings['cta_button_hover'] = (string) $settings['cta_button_background'];
		}

		return $settings;
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

	private function get_label_language_options(): array {
		return [
			'auto' => __( 'Auto (site locale)', 'nova-bridge-suite' ),
			'en'   => __( 'English', 'nova-bridge-suite' ),
			'nl'   => __( 'Dutch', 'nova-bridge-suite' ),
			'de'   => __( 'German', 'nova-bridge-suite' ),
			'fr'   => __( 'French', 'nova-bridge-suite' ),
			'es'   => __( 'Spanish', 'nova-bridge-suite' ),
			'pt'   => __( 'Portuguese', 'nova-bridge-suite' ),
			'it'   => __( 'Italian', 'nova-bridge-suite' ),
			'pl'   => __( 'Polish', 'nova-bridge-suite' ),
			'sv'   => __( 'Swedish', 'nova-bridge-suite' ),
			'da'   => __( 'Danish', 'nova-bridge-suite' ),
			'no'   => __( 'Norwegian', 'nova-bridge-suite' ),
			'fi'   => __( 'Finnish', 'nova-bridge-suite' ),
			'cs'   => __( 'Czech', 'nova-bridge-suite' ),
			'ro'   => __( 'Romanian', 'nova-bridge-suite' ),
			'tr'   => __( 'Turkish', 'nova-bridge-suite' ),
		];
	}

	private function get_selected_label_language(): string {
		$stored = get_option( self::OPTION_LABEL_LANGUAGE, self::DEFAULT_LABEL_LANGUAGE );

		return $this->sanitize_label_language_option( $stored );
	}

	private function get_settings_option_name( string $option ): string {
		return \Nova_Bridge_Suite_WPML_Support::get_localized_option_name( $option );
	}

	private function get_effective_label_language(): string {
		$selected = $this->get_selected_label_language();

		if ( 'auto' !== $selected ) {
			return $selected;
		}

		$multilingual_language = \Nova_Bridge_Suite_WPML_Support::get_current_language_code();

		if ( '' !== $multilingual_language ) {
			$prefix = strtolower( substr( sanitize_key( $multilingual_language ), 0, 2 ) );

			if ( in_array( $prefix, [ 'en', 'nl', 'de', 'fr', 'es', 'pt', 'it', 'pl', 'sv', 'da', 'no', 'fi', 'cs', 'ro', 'tr' ], true ) ) {
				return $prefix;
			}
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$prefix = strtolower( substr( sanitize_key( (string) $locale ), 0, 2 ) );
		$aliases = [
			'nb' => 'no',
			'nn' => 'no',
		];

		if ( isset( $aliases[ $prefix ] ) ) {
			$prefix = $aliases[ $prefix ];
		}

		return in_array( $prefix, [ 'en', 'nl', 'de', 'fr', 'es', 'pt', 'it', 'pl', 'sv', 'da', 'no', 'fi', 'cs', 'ro', 'tr' ], true ) ? $prefix : 'en';
	}

	/**
	 * Returns language defaults for common labels.
	 *
	 * @return array<string,string>
	 */
	private function get_language_term_defaults(): array {
		$map = [
			'en' => [
				'author'             => 'By',
				'key_takeaways'      => 'Key takeaways',
				'toc'                => 'Table of contents',
				'toc_read_more'      => 'Show more...',
				'toc_read_less'      => 'Show less...',
				'publications'       => '%s publications',
				'related_articles'   => 'Related articles',
				'faq_title'          => 'Frequently asked questions',
				'read_time_singular' => '%d min read',
				'read_time_plural'   => '%d mins read',
			],
			'nl' => [
				'author'             => 'Door',
				'key_takeaways'      => 'Belangrijkste punten',
				'toc'                => 'Inhoudsopgave',
				'toc_read_more'      => 'Meer tonen...',
				'toc_read_less'      => 'Minder tonen...',
				'publications'       => '%s publicaties',
				'related_articles'   => 'Gerelateerde artikelen',
				'faq_title'          => 'Veelgestelde vragen',
				'read_time_singular' => '%d min leestijd',
				'read_time_plural'   => '%d min leestijd',
			],
			'de' => [
				'author'             => 'Von',
				'key_takeaways'      => 'Wichtigste Punkte',
				'toc'                => 'Inhaltsverzeichnis',
				'toc_read_more'      => 'Mehr anzeigen...',
				'toc_read_less'      => 'Weniger anzeigen...',
				'publications'       => '%s Veröffentlichungen',
				'related_articles'   => 'Ähnliche Artikel',
				'faq_title'          => 'Häufig gestellte Fragen',
				'read_time_singular' => '%d Min. Lesezeit',
				'read_time_plural'   => '%d Min. Lesezeit',
			],
			'fr' => [
				'author'             => 'Par',
				'key_takeaways'      => 'Points clés',
				'toc'                => 'Table des matières',
				'toc_read_more'      => 'Afficher plus...',
				'toc_read_less'      => 'Afficher moins...',
				'publications'       => '%s publications',
				'related_articles'   => 'Articles associés',
				'faq_title'          => 'Questions fréquentes',
				'read_time_singular' => '%d min de lecture',
				'read_time_plural'   => '%d min de lecture',
			],
			'es' => [
				'author'             => 'Por',
				'key_takeaways'      => 'Puntos clave',
				'toc'                => 'Tabla de contenidos',
				'toc_read_more'      => 'Mostrar más...',
				'toc_read_less'      => 'Mostrar menos...',
				'publications'       => '%s publicaciones',
				'related_articles'   => 'Artículos relacionados',
				'faq_title'          => 'Preguntas frecuentes',
				'read_time_singular' => '%d min de lectura',
				'read_time_plural'   => '%d min de lectura',
			],
			'pt' => [
				'author'             => 'Por',
				'key_takeaways'      => 'Principais pontos',
				'toc'                => 'Índice',
				'toc_read_more'      => 'Mostrar mais...',
				'toc_read_less'      => 'Mostrar menos...',
				'publications'       => '%s publicações',
				'related_articles'   => 'Artigos relacionados',
				'faq_title'          => 'Perguntas frequentes',
				'read_time_singular' => '%d min de leitura',
				'read_time_plural'   => '%d min de leitura',
			],
			'it' => [
				'author'             => 'Di',
				'key_takeaways'      => 'Punti chiave',
				'toc'                => 'Indice dei contenuti',
				'toc_read_more'      => 'Mostra altro...',
				'toc_read_less'      => 'Mostra meno...',
				'publications'       => '%s pubblicazioni',
				'related_articles'   => 'Articoli correlati',
				'faq_title'          => 'Domande frequenti',
				'read_time_singular' => '%d min di lettura',
				'read_time_plural'   => '%d min di lettura',
			],
			'pl' => [
				'author'             => 'Autor',
				'key_takeaways'      => 'Kluczowe wnioski',
				'toc'                => 'Spis treści',
				'toc_read_more'      => 'Pokaż więcej...',
				'toc_read_less'      => 'Pokaż mniej...',
				'publications'       => '%s publikacji',
				'related_articles'   => 'Powiązane artykuły',
				'faq_title'          => 'Najczęściej zadawane pytania',
				'read_time_singular' => '%d min czytania',
				'read_time_plural'   => '%d min czytania',
			],
			'sv' => [
				'author'             => 'Av',
				'key_takeaways'      => 'Viktiga punkter',
				'toc'                => 'Innehållsförteckning',
				'toc_read_more'      => 'Visa mer...',
				'toc_read_less'      => 'Visa mindre...',
				'publications'       => '%s publikationer',
				'related_articles'   => 'Relaterade artiklar',
				'faq_title'          => 'Vanliga frågor',
				'read_time_singular' => '%d min lästid',
				'read_time_plural'   => '%d min lästid',
			],
			'da' => [
				'author'             => 'Af',
				'key_takeaways'      => 'Vigtigste pointer',
				'toc'                => 'Indholdsfortegnelse',
				'toc_read_more'      => 'Vis mere...',
				'toc_read_less'      => 'Vis mindre...',
				'publications'       => '%s publikationer',
				'related_articles'   => 'Relaterede artikler',
				'faq_title'          => 'Ofte stillede spørgsmål',
				'read_time_singular' => '%d min læsetid',
				'read_time_plural'   => '%d min læsetid',
			],
			'no' => [
				'author'             => 'Av',
				'key_takeaways'      => 'Viktige punkter',
				'toc'                => 'Innholdsfortegnelse',
				'toc_read_more'      => 'Vis mer...',
				'toc_read_less'      => 'Vis mindre...',
				'publications'       => '%s publikasjoner',
				'related_articles'   => 'Relaterte artikler',
				'faq_title'          => 'Ofte stilte spørsmål',
				'read_time_singular' => '%d min lesetid',
				'read_time_plural'   => '%d min lesetid',
			],
			'fi' => [
				'author'             => 'Tekijä',
				'key_takeaways'      => 'Tärkeimmät kohdat',
				'toc'                => 'Sisällysluettelo',
				'toc_read_more'      => 'Näytä lisää...',
				'toc_read_less'      => 'Näytä vähemmän...',
				'publications'       => '%s julkaisua',
				'related_articles'   => 'Aiheeseen liittyvät artikkelit',
				'faq_title'          => 'Usein kysytyt kysymykset',
				'read_time_singular' => '%d min lukuaika',
				'read_time_plural'   => '%d min lukuaika',
			],
			'cs' => [
				'author'             => 'Autor',
				'key_takeaways'      => 'Klíčové body',
				'toc'                => 'Obsah',
				'toc_read_more'      => 'Zobrazit více...',
				'toc_read_less'      => 'Zobrazit méně...',
				'publications'       => '%s publikací',
				'related_articles'   => 'Související články',
				'faq_title'          => 'Často kladené otázky',
				'read_time_singular' => '%d min čtení',
				'read_time_plural'   => '%d min čtení',
			],
			'ro' => [
				'author'             => 'De',
				'key_takeaways'      => 'Idei principale',
				'toc'                => 'Cuprins',
				'toc_read_more'      => 'Arată mai mult...',
				'toc_read_less'      => 'Arată mai puțin...',
				'publications'       => '%s publicații',
				'related_articles'   => 'Articole similare',
				'faq_title'          => 'Întrebări frecvente',
				'read_time_singular' => '%d min de citit',
				'read_time_plural'   => '%d min de citit',
			],
			'tr' => [
				'author'             => 'Yazan',
				'key_takeaways'      => 'Öne çıkanlar',
				'toc'                => 'İçindekiler',
				'toc_read_more'      => 'Daha fazla göster...',
				'toc_read_less'      => 'Daha az göster...',
				'publications'       => '%s yayın',
				'related_articles'   => 'İlgili yazılar',
				'faq_title'          => 'Sık sorulan sorular',
				'read_time_singular' => '%d dk okuma',
				'read_time_plural'   => '%d dk okuma',
			],
		];

		$language = $this->get_effective_label_language();
		$defaults = $map['en'];

		if ( isset( $map[ $language ] ) ) {
			$defaults = $map[ $language ];
		}

		return $defaults;
	}

	private function get_author_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['author'] ) ? (string) $defaults['author'] : self::DEFAULT_LABEL_AUTHOR;
		$label    = get_option( self::OPTION_LABEL_AUTHOR, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_key_takeaways_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['key_takeaways'] ) ? (string) $defaults['key_takeaways'] : __( 'Key takeaways', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_KEY_TAKEAWAYS, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_toc_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['toc'] ) ? (string) $defaults['toc'] : __( 'Table of contents', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_TOC, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_toc_read_more_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['toc_read_more'] ) ? (string) $defaults['toc_read_more'] : __( 'Show more...', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_TOC_READ_MORE, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_toc_read_less_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['toc_read_less'] ) ? (string) $defaults['toc_read_less'] : __( 'Show less...', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_TOC_READ_LESS, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_publications_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['publications'] ) ? (string) $defaults['publications'] : self::DEFAULT_LABEL_PUBLICATIONS;
		$label    = get_option( self::OPTION_LABEL_PUBLICATIONS, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		if ( '' === $label ) {
			$label = $default;
		}

		if ( false === strpos( $label, '%s' ) ) {
			$label .= ' %s';
		}

		return $label;
	}

	private function get_related_articles_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['related_articles'] ) ? (string) $defaults['related_articles'] : __( 'Related articles', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_RELATED_ARTICLES, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_faq_title_label(): string {
		$defaults = $this->get_language_term_defaults();
		$default  = isset( $defaults['faq_title'] ) ? (string) $defaults['faq_title'] : __( 'Frequently asked questions', 'nova-bridge-suite' );
		$label    = get_option( self::OPTION_LABEL_FAQ_TITLE, $default );
		$label    = is_string( $label ) ? wp_strip_all_tags( $label ) : $default;
		$label    = trim( $label );

		return '' === $label ? $default : $label;
	}

	private function get_read_time_label( int $minutes ): string {
		$minutes  = max( 1, $minutes );
		$defaults = $this->get_language_term_defaults();
		$pattern  = 1 === $minutes
			? (string) ( $defaults['read_time_singular'] ?? '%d min read' )
			: (string) ( $defaults['read_time_plural'] ?? '%d mins read' );

		if ( false === strpos( $pattern, '%d' ) ) {
			$pattern = '%d ' . $pattern;
		}

		return sprintf( $pattern, $minutes );
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
		$style_path = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt.css';
		$style_ver  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : '1.0.0';

		wp_register_style(
			$handle,
			plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt.css',
			[],
			$style_ver
		);

		wp_enqueue_style( $handle );

		$this->output_author_inline_styles( $handle );

		$enable_dom_cleaner = $bodyclean_active && apply_filters( 'quarantined_cpt_bodyclean/enable_dom_cleaner', true, $context, $this );

		if ( $enable_dom_cleaner ) {
			$script_handle = 'quarantined-cpt-bodyclean-cleaner';
			$script_path   = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-clean.js';
			$script_ver    = file_exists( $script_path ) ? (string) filemtime( $script_path ) : '1.0.0';

			wp_register_script(
				$script_handle,
				plugin_dir_url( __FILE__ ) . 'assets/quarantined-cpt-clean.js',
				[ 'wp-dom-ready' ],
				$script_ver,
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

		$style_post_type = $this->detect_component_post_type_context();
		$style_settings  = $this->get_blog_style_settings_for_type( $style_post_type );
		$component_order = $this->get_component_order_settings_for_type( $style_post_type );
		$social_color    = '' !== trim( (string) ( $style_settings['link_color'] ?? '' ) )
			? (string) $style_settings['link_color']
			: (string) ( $style_settings['text_color'] ?? '' );
		$style_rules    = [
			'--quarantined-cpt-entry-max-width'      => (string) ( $style_settings['content_max_width'] ?? self::DEFAULT_BLOG_CONTENT_MAX_WIDTH ),
			'--quarantined-cpt-text-color'           => (string) ( $style_settings['text_color'] ?? '' ),
			'--quarantined-cpt-link-color'           => (string) ( $style_settings['link_color'] ?? '' ),
			'--quarantined-cpt-link-hover-color'     => (string) ( $style_settings['link_hover_color'] ?? '' ),
			'--quarantined-cpt-panel-background'     => (string) ( $style_settings['panel_background'] ?? '' ),
			'--quarantined-cpt-panel-border-color'   => (string) ( $style_settings['panel_border'] ?? '' ),
			'--quarantined-cpt-meta-divider-color'   => (string) ( $style_settings['meta_border'] ?? '' ),
			'--quarantined-cpt-share-bg'             => (string) ( $style_settings['share_background'] ?? '' ),
			'--quarantined-cpt-share-bg-hover'       => (string) ( $style_settings['share_background_hover'] ?? '' ),
			'--quarantined-cpt-share-border'         => (string) ( $style_settings['share_border'] ?? '' ),
			'--quarantined-cpt-social-color'         => $social_color,
			'--quarantined-cpt-social-background'    => (string) ( $style_settings['share_background'] ?? '' ),
			'--quarantined-cpt-social-background-hover' => (string) ( $style_settings['share_background_hover'] ?? '' ),
			'--quarantined-cpt-cta-bg'               => (string) ( $style_settings['cta_background'] ?? '' ),
			'--quarantined-cpt-cta-button-bg'        => (string) ( $style_settings['cta_button_background'] ?? '' ),
			'--quarantined-cpt-cta-button-text'      => (string) ( $style_settings['cta_button_text'] ?? '' ),
			'--quarantined-cpt-cta-button-hover-bg'  => (string) ( $style_settings['cta_button_hover'] ?? '' ),
			'--quarantined-cpt-author-box-bg'        => (string) ( $style_settings['author_box_background'] ?? '' ),
			'--quarantined-cpt-author-box-border'    => (string) ( $style_settings['author_box_border'] ?? '' ),
			'--quarantined-cpt-card-radius'          => (string) ( $style_settings['card_radius'] ?? '' ),
			'--quarantined-cpt-card-shadow'          => (string) ( $style_settings['card_shadow'] ?? '' ),
			'--quarantined-cpt-card-shadow-hover'    => (string) ( $style_settings['card_shadow_hover'] ?? '' ),
			'--quarantined-cpt-card-border'          => (string) ( $style_settings['card_border'] ?? '' ),
			'--quarantined-cpt-order-breadcrumbs'    => (string) ( $component_order['breadcrumbs'] ?? 10 ),
			'--quarantined-cpt-order-featured'       => (string) ( $component_order['featured'] ?? 20 ),
			'--quarantined-cpt-order-title'          => (string) ( $component_order['title'] ?? 30 ),
			'--quarantined-cpt-order-meta'           => (string) ( $component_order['meta'] ?? 40 ),
			'--quarantined-cpt-order-intro'          => (string) ( $component_order['intro'] ?? 50 ),
			'--quarantined-cpt-order-content-1'      => (string) ( $component_order['content_1'] ?? 60 ),
			'--quarantined-cpt-order-wide-cta'       => (string) ( $component_order['wide_cta'] ?? 70 ),
			'--quarantined-cpt-order-content-2'      => (string) ( $component_order['content_2'] ?? 80 ),
			'--quarantined-cpt-order-faq'            => (string) ( $component_order['faq'] ?? 90 ),
			'--quarantined-cpt-order-related'        => (string) ( $component_order['related'] ?? 100 ),
			'--quarantined-cpt-order-wide-cta-after' => (string) ( $component_order['wide_cta_after_related'] ?? 110 ),
			'--quarantined-cpt-order-author-box'     => (string) ( $component_order['author_box'] ?? 120 ),
		];

		$style_declarations = [];

		foreach ( $style_rules as $variable => $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';

			if ( '' === $value ) {
				continue;
			}

			$style_declarations[] = $variable . ':' . $value;
		}

		if ( ! empty( $style_declarations ) ) {
			$inline_css .= "\n" . 'body.quarantined-cpt-view,body.quarantined-cpt-archive,body.quarantined-cpt-author,.quarantined-cpt{'
				. implode( ';', $style_declarations )
				. ';}';
		}

		$style_override_rules = $this->build_blog_style_override_rules( $style_settings );

		if ( '' !== $style_override_rules ) {
			$inline_css .= "\n" . $style_override_rules;
		}

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
				'\tcolor: var(--quarantined-cpt-social-color, var(--quarantined-cpt-link-color, var(--quarantined-cpt-text-color, inherit))) !important;',
				'\tbackground-color: var(--quarantined-cpt-social-background, var(--quarantined-cpt-share-bg, rgba(15, 23, 42, 0.08))) !important;',
				'\tline-height: 0 !important;',
				'\ttransition: background-color 0.2s ease, transform 0.2s ease !important;',
				'}',
				'',
				'main.quarantined-cpt .quarantined-cpt__author-social a:hover,',
				'main.quarantined-cpt .quarantined-cpt__author-social a:focus {',
				'\tbackground-color: var(--quarantined-cpt-social-background-hover, var(--quarantined-cpt-share-bg-hover, rgba(15, 23, 42, 0.16))) !important;',
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
	 * Returns AI-editable blog meta keys and their component dependencies.
	 *
	 * @return array<string,array<int,string>>
	 */
	private function get_blog_ai_editable_meta_map(): array {
		return [
			self::META_BLOG_INTRO         => [ 'intro' ],
			self::META_BLOG_KEY_TAKEAWAYS => [ 'content_1', 'key_takeaways' ],
			self::META_BLOG_PART_1        => [ 'content_1' ],
			self::META_BLOG_PART_2        => [ 'content_2' ],
			self::META_BLOG_FAQS          => [ 'faq' ],
		];
	}

	/**
	 * Resolves AI-editable blog meta keys for a post type.
	 *
	 * @param string $post_type Optional CPT key.
	 * @return string[]
	 */
	private function get_blog_ai_editable_meta_keys( string $post_type = '' ): array {
		$map       = $this->get_blog_ai_editable_meta_map();
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type || ! $this->is_managed_post_type( $post_type ) ) {
			return array_keys( $map );
		}

		$components = $this->get_component_settings_for_type( $post_type );
		$allowed    = [];

		foreach ( $map as $meta_key => $required_components ) {
			$is_enabled = true;

			foreach ( $required_components as $component_key ) {
				if ( empty( $components[ $component_key ] ) ) {
					$is_enabled = false;
					break;
				}
			}

			if ( $is_enabled ) {
				$allowed[] = $meta_key;
			}
		}

		return $allowed;
	}

	/**
	 * Returns AI-editable meta values for a specific blog post.
	 *
	 * @param \WP_Post $post Blog post.
	 * @return array<string,mixed>
	 */
	private function get_blog_ai_meta_values( \WP_Post $post ): array {
		$meta         = $this->get_blog_meta_values( (int) $post->ID );
		$allowed_keys = $this->get_blog_ai_editable_meta_keys( (string) $post->post_type );
		$filtered     = [];

		foreach ( $allowed_keys as $meta_key ) {
			if ( array_key_exists( $meta_key, $meta ) ) {
				$filtered[ $meta_key ] = $meta[ $meta_key ];
			}
		}

		return $filtered;
	}

	/**
	 * Returns AI-focused field descriptions for editable blog meta fields.
	 *
	 * @param string[] $allowed_keys Optional allowed meta key list.
	 * @return array<string,string>
	 */
	private function get_blog_ai_meta_descriptions( array $allowed_keys = [] ): array {
		$descriptions = [
			self::META_BLOG_INTRO         => __( 'Intro paragraph shown directly beneath the author/meta row. Keep this concise (maximum 100 words).', 'nova-bridge-suite' ),
			self::META_BLOG_KEY_TAKEAWAYS => __( 'Key takeaways/TLDR bullet list (array of strings). Populate this with concise, scannable points (recommended 3-5 bullets).', 'nova-bridge-suite' ),
			self::META_BLOG_PART_1        => __( 'First rich-text section after the intro. Keep this to roughly 40% of the remaining article content. Do not place the majority of body content here.', 'nova-bridge-suite' ),
			self::META_BLOG_PART_2        => __( 'Second rich-text section after the intro. Put the remaining roughly 60% of post-intro content here. This must contain the majority of the article body and should be longer than blog_part_1.', 'nova-bridge-suite' ),
			self::META_BLOG_FAQS          => __( 'FAQ items shown in the FAQ section ({question,answer}[]). Add FAQs here so they render as proper accordion items. Do not repeat FAQ content in blog_part_1 or blog_part_2.', 'nova-bridge-suite' ),
		];

		if ( empty( $allowed_keys ) ) {
			return $descriptions;
		}

		$filtered = [];

		foreach ( $allowed_keys as $meta_key ) {
			if ( isset( $descriptions[ $meta_key ] ) ) {
				$filtered[ $meta_key ] = $descriptions[ $meta_key ];
			}
		}

		return $filtered;
	}

	/**
	 * Returns AI guidance note for editable blog fields.
	 *
	 * @param string[] $allowed_keys Optional allowed meta key list.
	 * @return string
	 */
	private function get_blog_ai_meta_note( array $allowed_keys = [] ): string {
		$has_intro         = in_array( self::META_BLOG_INTRO, $allowed_keys, true );
		$has_part_1        = in_array( self::META_BLOG_PART_1, $allowed_keys, true );
		$has_part_2        = in_array( self::META_BLOG_PART_2, $allowed_keys, true );
		$has_takeaways     = in_array( self::META_BLOG_KEY_TAKEAWAYS, $allowed_keys, true );
		$has_faqs          = in_array( self::META_BLOG_FAQS, $allowed_keys, true );
		$guidance_segments = [];

		if ( $has_intro ) {
			$guidance_segments[] = __( 'Write the intro in up to 100 words.', 'nova-bridge-suite' );
		}

		if ( $has_part_1 && $has_part_2 ) {
			$guidance_segments[] = __( 'After the intro, split the remaining body content as roughly 40% in blog_part_1 and 60% in blog_part_2. Keep blog_part_1 shorter; blog_part_2 must be the longer section.', 'nova-bridge-suite' );
		} elseif ( $has_part_1 ) {
			$guidance_segments[] = __( 'Use blog_part_1 for the main body content that remains after the intro.', 'nova-bridge-suite' );
		} elseif ( $has_part_2 ) {
			$guidance_segments[] = __( 'Use blog_part_2 for the main body content that remains after the intro.', 'nova-bridge-suite' );
		}

		if ( $has_takeaways ) {
			$guidance_segments[] = __( 'Populate blog_key_takeaways with concise key points (recommended 3-5 bullets).', 'nova-bridge-suite' );
		}

		if ( $has_faqs ) {
			$guidance_segments[] = __( 'Place FAQ items in blog_faqs so they render as proper FAQ accordions; do not duplicate those FAQs in blog_part_1 or blog_part_2.', 'nova-bridge-suite' );
		}

		$guidance_segments[] = __( 'Auto-generated sections (table of contents and default related articles) and CTA fields are intentionally excluded from this payload.', 'nova-bridge-suite' );
		$guidance_segments[] = __( 'Fields for disabled components are omitted from meta and meta_descriptions.', 'nova-bridge-suite' );

		return implode( ' ', $guidance_segments );
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
	 * Forces managed blog post types to use the classic editor in wp-admin.
	 *
	 * @param bool   $can_edit  Whether the block editor is enabled.
	 * @param string $post_type Current post type.
	 * @return bool
	 */
	public function force_classic_editor( bool $can_edit, string $post_type ): bool {
		if ( $this->is_managed_post_type( $post_type ) ) {
			return false;
		}

		return $can_edit;
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
		$preset_class    = 'quarantined-cpt-style-' . sanitize_html_class( $this->get_blog_style_preset() );

		if ( $is_cpt_singular ) {
			$classes[] = 'quarantined-cpt-view';
			$classes[] = $preset_class;
			if ( $this->should_enable_bodyclean( 'single' ) ) {
				$classes[] = 'quarantined-cpt-bodyclean';
			}
		} elseif ( $is_cpt_archive ) {
			$classes[] = 'quarantined-cpt-archive';
			$classes[] = $preset_class;
			if ( $this->should_enable_bodyclean( 'archive' ) ) {
				$classes[] = 'quarantined-cpt-bodyclean';
			}
		} elseif ( $this->is_author_context() ) {
			$classes[] = 'quarantined-cpt-author';
			$classes[] = $preset_class;
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
	 * Returns managed post types or a stable default when no definitions are active.
	 *
	 * @return string[]
	 */
	private function get_managed_or_default_post_types(): array {
		$types = $this->get_cpt_types( false );

		if ( empty( $types ) ) {
			$types = [ self::CPT ];
		}

		$types = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $type ) {
							return sanitize_key( (string) $type );
						},
						$types
					)
				)
			)
		);

		return empty( $types ) ? [ self::CPT ] : $types;
	}

	/**
	 * Registers post meta fields used by the rich single-blog layout.
	 */
	public function register_blog_meta_fields(): void {
		$definitions  = $this->get_blog_meta_definitions();
		$descriptions = $this->get_blog_meta_descriptions();
		$post_types   = $this->get_managed_or_default_post_types();

		foreach ( $post_types as $post_type ) {
			foreach ( $definitions as $key => $definition ) {
				$show_in_rest = $definition['show_in_rest'];

				if ( true === $show_in_rest ) {
					$show_in_rest = [
						'schema' => [
							'type' => $definition['type'],
						],
					];
				}

				if ( is_array( $show_in_rest ) && isset( $show_in_rest['schema'] ) && isset( $descriptions[ $key ] ) ) {
					$show_in_rest['schema']['description'] = (string) $descriptions[ $key ];
				}

				register_post_meta(
					$post_type,
					$key,
					[
						'type'              => $definition['type'],
						'single'            => true,
						'auth_callback'     => '__return_true',
						'sanitize_callback' => $definition['sanitize_callback'],
						'show_in_rest'      => $show_in_rest,
						'description'       => (string) ( $descriptions[ $key ] ?? '' ),
					]
				);
			}
		}
	}

	/**
	 * Returns the blog layout post-meta schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_blog_meta_definitions(): array {
		return [
			self::META_BLOG_INTRO                    => $this->blog_rich_text_meta_definition(),
				self::META_BLOG_KEY_TAKEAWAYS            => [
					'type'              => 'array',
					'sanitize_callback' => [ self::class, 'sanitize_blog_string_array' ],
					'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'string' ],
						],
					],
				],
				self::META_BLOG_TOC_DISABLE             => $this->blog_bool_meta_definition(),
				self::META_BLOG_PART_1                   => $this->blog_rich_text_meta_definition(),
			self::META_BLOG_CTA_TITLE                => $this->blog_string_meta_definition(),
			self::META_BLOG_CTA_COPY                 => $this->blog_rich_text_meta_definition(),
			self::META_BLOG_CTA_BUTTON_LABEL         => $this->blog_string_meta_definition(),
			self::META_BLOG_CTA_BUTTON_URL           => $this->blog_url_meta_definition(),
			self::META_BLOG_CTA_DISABLE              => $this->blog_bool_meta_definition(),
			self::META_BLOG_PART_2                   => $this->blog_rich_text_meta_definition(),
			self::META_BLOG_FAQS                     => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_blog_faqs' ],
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
			self::META_BLOG_RELATED_POSTS            => [
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize_blog_related_posts' ],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
				],
			],
			self::META_BLOG_CTA_AFTER_RELATED_TITLE        => $this->blog_string_meta_definition(),
			self::META_BLOG_CTA_AFTER_RELATED_COPY         => $this->blog_rich_text_meta_definition(),
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL => $this->blog_string_meta_definition(),
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL   => $this->blog_url_meta_definition(),
			self::META_BLOG_CTA_AFTER_RELATED_DISABLE      => $this->blog_bool_meta_definition(),
		];
	}

	/**
	 * Returns human-readable descriptions for each blog layout field.
	 *
	 * @return array<string,string>
	 */
	private function get_blog_meta_descriptions(): array {
		return [
				self::META_BLOG_INTRO                          => __( 'Intro paragraph shown directly beneath the author/meta row.', 'nova-bridge-suite' ),
				self::META_BLOG_KEY_TAKEAWAYS                  => __( 'Optional key takeaways/TLDR bullet list (array of strings).', 'nova-bridge-suite' ),
				self::META_BLOG_TOC_DISABLE                    => __( 'Disable the auto-generated table of contents for this post. When disabled, key takeaways can use full width.', 'nova-bridge-suite' ),
				self::META_BLOG_PART_1                         => __( 'First rich-text body section before the primary wide CTA.', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_TITLE                      => __( 'Primary wide CTA title (post-level override; falls back to global CTA defaults when empty).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_COPY                       => __( 'Primary wide CTA supporting rich text (post-level override; falls back to global CTA defaults when empty).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_BUTTON_LABEL               => __( 'Primary wide CTA button label (post-level override).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_BUTTON_URL                 => __( 'Primary wide CTA button URL (post-level override). Supports absolute links and internal links that start with "/".', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_DISABLE                    => __( 'Disable the primary wide CTA on this post, including global CTA fallback content.', 'nova-bridge-suite' ),
			self::META_BLOG_PART_2                         => __( 'Second rich-text body section after the primary wide CTA.', 'nova-bridge-suite' ),
			self::META_BLOG_FAQS                           => __( 'Optional FAQ items shown above related articles ({question,answer}[]).', 'nova-bridge-suite' ),
			self::META_BLOG_RELATED_POSTS                  => __( 'Optional related article IDs shown beneath the FAQ section. When empty, the latest three articles are used.', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_AFTER_RELATED_TITLE        => __( 'After-related wide CTA title (post-level override; falls back to global CTA defaults when empty).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_AFTER_RELATED_COPY         => __( 'After-related wide CTA supporting rich text (post-level override; falls back to global CTA defaults when empty).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL => __( 'After-related wide CTA button label (post-level override).', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL   => __( 'After-related wide CTA button URL (post-level override). Supports absolute links and internal links that start with "/".', 'nova-bridge-suite' ),
			self::META_BLOG_CTA_AFTER_RELATED_DISABLE      => __( 'Disable the after-related wide CTA on this post, including global CTA fallback content.', 'nova-bridge-suite' ),
		];
	}

	/**
	 * Returns a basic string meta definition.
	 *
	 * @return array<string,mixed>
	 */
	private function blog_string_meta_definition(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
		];
	}

	/**
	 * Returns a rich-text meta definition.
	 *
	 * @return array<string,mixed>
	 */
	private function blog_rich_text_meta_definition(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_blog_rich_text' ],
			'show_in_rest'      => true,
		];
	}

	/**
	 * Returns a boolean meta definition.
	 *
	 * @return array<string,mixed>
	 */
	private function blog_bool_meta_definition(): array {
		return [
			'type'              => 'boolean',
			'sanitize_callback' => [ self::class, 'sanitize_blog_bool_flag' ],
			'show_in_rest'      => true,
		];
	}

	/**
	 * Returns a URL meta definition.
	 *
	 * @return array<string,mixed>
	 */
	private function blog_url_meta_definition(): array {
		return [
			'type'              => 'string',
			'sanitize_callback' => [ self::class, 'sanitize_blog_cta_url' ],
			'show_in_rest'      => true,
		];
	}

	/**
	 * Sanitizes rich text for blog layout fields.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_blog_rich_text( $value ): string {
		return wp_kses_post( (string) $value );
	}

	/**
	 * Sanitizes string arrays used by key takeaways.
	 *
	 * @param mixed $value Raw array.
	 * @return string[]
	 */
	public static function sanitize_blog_string_array( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$clean[] = $item;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitizes related post IDs.
	 *
	 * @param mixed $value Raw array.
	 * @return int[]
	 */
	public static function sanitize_blog_related_posts( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$ids = [];

		foreach ( $value as $item ) {
			$id = absint( $item );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Sanitizes FAQ entries.
	 *
	 * @param mixed $value Raw FAQ rows.
	 * @return array<int,array<string,string>>
	 */
	public static function sanitize_blog_faqs( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];

		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = isset( $row['question'] ) ? sanitize_text_field( (string) $row['question'] ) : '';
			$answer   = isset( $row['answer'] ) ? wp_kses_post( (string) $row['answer'] ) : '';

			if ( '' === $question && '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}

			$clean[] = [
				'question' => $question,
				'answer'   => $answer,
			];

			if ( count( $clean ) >= 12 ) {
				break;
			}
		}

		return $clean;
	}

	/**
	 * Sanitizes boolean flags stored in blog meta.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_blog_bool_flag( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
		}

		return ! empty( $value );
	}

	/**
	 * Sanitizes a CTA URL while allowing internal root-relative paths.
	 *
	 * @param mixed $value Raw URL.
	 * @return string
	 */
	public static function sanitize_blog_cta_url( $value ): string {
		$url = trim( (string) $value );

		if ( '' === $url ) {
			return '';
		}

		if ( 0 === strpos( $url, '#' ) ) {
			$anchor = sanitize_title( substr( $url, 1 ) );

			return '' === $anchor ? '' : '#' . $anchor;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			// Block protocol-relative URLs while allowing internal paths.
			if ( 0 === strpos( $url, '//' ) ) {
				return '';
			}

			return $url;
		}

		return esc_url_raw( $url );
	}

	/**
	 * Returns default values for blog layout fields.
	 *
	 * @return array<string,mixed>
	 */
	private function get_blog_meta_defaults(): array {
			return [
				self::META_BLOG_INTRO                          => '',
				self::META_BLOG_KEY_TAKEAWAYS                  => [],
				self::META_BLOG_TOC_DISABLE                    => false,
				self::META_BLOG_PART_1                         => '',
			self::META_BLOG_CTA_TITLE                      => '',
			self::META_BLOG_CTA_COPY                       => '',
			self::META_BLOG_CTA_BUTTON_LABEL               => '',
			self::META_BLOG_CTA_BUTTON_URL                 => '',
			self::META_BLOG_CTA_DISABLE                    => false,
			self::META_BLOG_PART_2                         => '',
			self::META_BLOG_FAQS                           => [],
			self::META_BLOG_RELATED_POSTS                  => [],
			self::META_BLOG_CTA_AFTER_RELATED_TITLE        => '',
			self::META_BLOG_CTA_AFTER_RELATED_COPY         => '',
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL => '',
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL   => '',
			self::META_BLOG_CTA_AFTER_RELATED_DISABLE      => false,
		];
	}

	/**
	 * Retrieves sanitized blog layout meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function get_blog_meta_values( int $post_id ): array {
		$post = get_post( $post_id );

		if ( $post instanceof \WP_Post ) {
			$this->maybe_auto_upgrade_legacy_blog_post( $post );
		}

		$values      = $this->get_blog_meta_defaults();
		$definitions = $this->get_blog_meta_definitions();

		foreach ( $definitions as $key => $definition ) {
			$raw = get_post_meta( $post_id, $key, true );
			$type = (string) $definition['type'];

			if ( 'array' === $type ) {
				$raw = is_array( $raw ) ? $raw : [];
			}

			if ( isset( $definition['sanitize_callback'] ) && is_callable( $definition['sanitize_callback'] ) ) {
				$raw = call_user_func( $definition['sanitize_callback'], $raw );
			}

				if ( 'integer' === $type ) {
					$raw = absint( $raw );
				}

				if ( 'boolean' === $type ) {
					$raw = (bool) $raw;
				}

					$values[ $key ] = $raw;
				}

		if ( $post instanceof \WP_Post && $this->is_legacy_blog_post_internal( $post ) ) {
			if ( ! $this->blog_html_has_text( (string) $values[ self::META_BLOG_PART_1 ] ) ) {
				$legacy_content = $this->enforce_blog_body_heading_policy( $this->prepare_content( $post ), (string) $post->post_type );

				if ( $this->blog_html_has_text( $legacy_content ) ) {
					$values[ self::META_BLOG_PART_1 ] = $legacy_content;
				}
			}

			if ( ! $this->blog_html_has_text( (string) $values[ self::META_BLOG_INTRO ] ) ) {
				$intro_source = has_excerpt( $post ) ? (string) $post->post_excerpt : '';

				if ( '' !== trim( $intro_source ) ) {
					$values[ self::META_BLOG_INTRO ] = wpautop( esc_html( $intro_source ) );
				}
			}
		}

		if ( $post instanceof \WP_Post ) {
			$post_type = (string) $post->post_type;
			$values[ self::META_BLOG_PART_1 ] = $this->enforce_blog_body_heading_policy( (string) $values[ self::META_BLOG_PART_1 ], $post_type );
			$values[ self::META_BLOG_PART_2 ] = $this->enforce_blog_body_heading_policy( (string) $values[ self::META_BLOG_PART_2 ], $post_type );
		}

		return $values;
	}

	/**
	 * Registers the blog layout meta box.
	 */
	public function register_blog_meta_box(): void {
		$post_types = $this->get_managed_or_default_post_types();

		foreach ( $post_types as $post_type ) {
			add_meta_box(
				'quarantined-cpt-blog-layout',
				__( 'Blog Layout Fields', 'nova-bridge-suite' ),
				[ $this, 'render_blog_meta_box' ],
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Keeps the blog layout meta box visible for managed post types.
	 *
	 * @param array       $hidden       Hidden metabox IDs.
	 * @param \WP_Screen  $screen       Current screen object.
	 * @param bool        $use_defaults Whether defaults are in use.
	 * @return array
	 */
	public function filter_hidden_meta_boxes( array $hidden, \WP_Screen $screen, bool $use_defaults ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! isset( $screen->post_type ) || ! $this->is_managed_post_type( (string) $screen->post_type ) ) {
			return $hidden;
		}

		return array_values(
			array_filter(
				$hidden,
				static function ( $id ) {
					return 'quarantined-cpt-blog-layout' !== (string) $id;
				}
			)
		);
	}

	/**
	 * Renders the blog layout meta box fields.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_blog_meta_box( \WP_Post $post ): void {
		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return;
		}

		wp_nonce_field( 'quarantined_cpt_blog_layout_save', 'quarantined_cpt_blog_layout_nonce' );

		$meta                    = $this->get_blog_meta_values( (int) $post->ID );
		$takeaways_text          = implode( "\n", $meta[ self::META_BLOG_KEY_TAKEAWAYS ] );
		$toc_disabled            = ! empty( $meta[ self::META_BLOG_TOC_DISABLE ] );
		$primary_cta_global       = $this->get_blog_global_cta_defaults( 'primary', (string) $post->post_type );
		$after_related_cta_global = $this->get_blog_global_cta_defaults( 'after_related', (string) $post->post_type );

		$related_types = array_values(
			array_unique(
				array_filter(
					array_merge(
						[ (string) $post->post_type ],
						[ 'post' ]
					)
				)
			)
		);

		$related_candidates = get_posts(
			[
				'post_type'        => $related_types,
				'post_status'      => 'publish',
				'posts_per_page'   => 80,
				'post__not_in'     => [ (int) $post->ID ],
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);

		$selected_related = array_values(
			array_unique(
				array_filter(
					array_map(
						'absint',
						is_array( $meta[ self::META_BLOG_RELATED_POSTS ] ) ? $meta[ self::META_BLOG_RELATED_POSTS ] : []
					)
				)
			)
		);

		$author_user = get_userdata( (int) $post->post_author );
		$author_name = $author_user instanceof \WP_User ? self::get_author_display_name( $author_user ) : '';
		$author_avatar = $author_user instanceof \WP_User ? self::get_author_avatar_url( $author_user, 96 ) : '';
		$author_title = $author_user instanceof \WP_User
			? sanitize_text_field( (string) get_user_meta( $author_user->ID, self::META_AUTHOR_TITLE, true ) )
			: '';
		$author_org = $author_user instanceof \WP_User
			? sanitize_text_field( (string) get_user_meta( $author_user->ID, self::META_AUTHOR_ORG, true ) )
			: '';

		$has_content = static function ( array $values ) use ( &$has_content ): bool {
			foreach ( $values as $value ) {
				if ( is_array( $value ) ) {
					if ( $has_content( $value ) ) {
						return true;
					}
					continue;
				}
				if ( '' !== trim( (string) $value ) ) {
					return true;
				}
			}

			return false;
		};

		$has_takeaways              = $has_content( [ $meta[ self::META_BLOG_KEY_TAKEAWAYS ] ] );
		$has_toc_settings           = $toc_disabled;
		$has_part_1                 = $has_content( [ $meta[ self::META_BLOG_PART_1 ] ] );
		$cta_disabled               = ! empty( $meta[ self::META_BLOG_CTA_DISABLE ] );
		$has_primary_cta_local      = $this->blog_cta_has_content(
			(string) $meta[ self::META_BLOG_CTA_TITLE ],
			(string) $meta[ self::META_BLOG_CTA_COPY ],
			(string) $meta[ self::META_BLOG_CTA_BUTTON_LABEL ],
			(string) $meta[ self::META_BLOG_CTA_BUTTON_URL ]
		);
		$has_primary_cta_global     = $this->blog_cta_has_content(
			(string) $primary_cta_global['title'],
			(string) $primary_cta_global['copy'],
			(string) $primary_cta_global['button_label'],
			(string) $primary_cta_global['button_url']
		);
		$has_cta                    = $cta_disabled || $has_primary_cta_local || $has_primary_cta_global;
		$has_part_2                 = $has_content( [ $meta[ self::META_BLOG_PART_2 ] ] );
		$has_faq                    = $has_content( [ $meta[ self::META_BLOG_FAQS ] ] );
		$has_related                = ! empty( $selected_related );
		$cta_after_related_disabled = ! empty( $meta[ self::META_BLOG_CTA_AFTER_RELATED_DISABLE ] );
		$has_after_cta_local        = $this->blog_cta_has_content(
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_TITLE ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_COPY ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL ]
		);
		$has_after_cta_global       = $this->blog_cta_has_content(
			(string) $after_related_cta_global['title'],
			(string) $after_related_cta_global['copy'],
			(string) $after_related_cta_global['button_label'],
			(string) $after_related_cta_global['button_url']
		);
		$has_after_related_cta      = $cta_after_related_disabled || $has_after_cta_local || $has_after_cta_global;
		$faqs                       = isset( $meta[ self::META_BLOG_FAQS ] ) && is_array( $meta[ self::META_BLOG_FAQS ] ) ? self::sanitize_blog_faqs( $meta[ self::META_BLOG_FAQS ] ) : [];
		$faq_row_count              = max( 4, count( $faqs ) + 1 );
		?>
		<div class="quarantined-cpt-blog-editor">
			<p class="quarantined-cpt-blog-editor-intro">
				<?php esc_html_e( 'Configure the blog article layout sections below.', 'nova-bridge-suite' ); ?>
			</p>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" open>
				<summary>
					<?php esc_html_e( 'Header & Intro', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Above article body', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-field">
						<label><?php esc_html_e( 'Intro paragraph', 'nova-bridge-suite' ); ?></label>
						<?php
						wp_editor(
							(string) $meta[ self::META_BLOG_INTRO ],
							'quarantined_cpt_blog_intro_' . (int) $post->ID,
							[
								'textarea_name' => self::META_BLOG_INTRO,
								'textarea_rows' => 5,
								'editor_class'  => 'quarantined-cpt-blog-editor-field',
								'media_buttons' => false,
								'teeny'         => true,
								'quicktags'     => true,
								'tinymce'       => [
									'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
									'toolbar2'      => '',
									'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
									'resize'        => false,
								],
							]
						);
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Estimated read time is generated automatically from intro + content blocks.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_takeaways ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Key Takeaways (optional)', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Summary panel', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-field">
						<label for="quarantined-cpt-key-takeaways"><?php esc_html_e( 'One bullet per line', 'nova-bridge-suite' ); ?></label>
						<textarea
							id="quarantined-cpt-key-takeaways"
							rows="5"
							class="quarantined-cpt-blog-editor-field"
							name="quarantined_cpt_blog_key_takeaways_lines"
						><?php echo esc_textarea( $takeaways_text ); ?></textarea>
					</div>
				</div>
				</details>

				<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_toc_settings ? 'open' : ''; ?>>
					<summary>
						<?php esc_html_e( 'Table of Contents (auto, optional)', 'nova-bridge-suite' ); ?>
						<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Generated from headings', 'nova-bridge-suite' ); ?></span>
					</summary>
					<div class="quarantined-cpt-blog-section-body">
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-toc-disable">
								<input
									id="quarantined-cpt-toc-disable"
									type="checkbox"
									class="quarantined-cpt-blog-editor-field"
									name="<?php echo esc_attr( self::META_BLOG_TOC_DISABLE ); ?>"
									value="1"
									<?php checked( $toc_disabled ); ?>
								/>
								<?php esc_html_e( 'Disable table of contents for this post', 'nova-bridge-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, the table of contents is hidden on this post and key takeaways can stretch full width.', 'nova-bridge-suite' ); ?></p>
							<p class="description"><?php esc_html_e( 'When enabled globally, TOC is built automatically from H2/H3/H4 in content blocks.', 'nova-bridge-suite' ); ?></p>
						</div>
					</div>
				</details>

				<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_part_1 ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Rich Text Block 1', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Main body', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-field">
						<?php
						wp_editor(
							(string) $meta[ self::META_BLOG_PART_1 ],
							'quarantined_cpt_blog_part_1_' . (int) $post->ID,
							[
								'textarea_name' => self::META_BLOG_PART_1,
								'textarea_rows' => 10,
								'editor_class'  => 'quarantined-cpt-blog-editor-field',
								'media_buttons' => false,
								'teeny'         => true,
								'quicktags'     => true,
								'tinymce'       => [
									'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
									'toolbar2'      => '',
									'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
									'resize'        => false,
								],
							]
						);
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Use Heading 2/3/4 in content blocks to generate the table of contents automatically.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_cta ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Primary Wide CTA (optional)', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Between body blocks', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<p class="description"><?php esc_html_e( 'Optional per-post overrides. Leave fields empty to use global CTA defaults configured for this CPT in NOVA Blog Settings.', 'nova-bridge-suite' ); ?></p>
					<div class="quarantined-cpt-blog-field">
						<label for="quarantined-cpt-cta-disable">
							<input
								id="quarantined-cpt-cta-disable"
								type="checkbox"
								class="quarantined-cpt-blog-editor-field"
								name="<?php echo esc_attr( self::META_BLOG_CTA_DISABLE ); ?>"
								value="1"
								<?php checked( $cta_disabled ); ?>
							/>
							<?php esc_html_e( 'Disable this CTA for this post', 'nova-bridge-suite' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When checked, this CTA is hidden even if global defaults are configured.', 'nova-bridge-suite' ); ?></p>
					</div>
					<div class="quarantined-cpt-blog-grid">
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-cta-title"><?php esc_html_e( 'CTA title (optional override)', 'nova-bridge-suite' ); ?></label>
							<input
								id="quarantined-cpt-cta-title"
								type="text"
								class="quarantined-cpt-blog-editor-field"
								name="<?php echo esc_attr( self::META_BLOG_CTA_TITLE ); ?>"
								value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_TITLE ] ); ?>"
								placeholder="<?php echo esc_attr( (string) $primary_cta_global['title'] ); ?>"
							/>
						</div>
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-cta-label"><?php esc_html_e( 'CTA button label (optional override)', 'nova-bridge-suite' ); ?></label>
							<input
								id="quarantined-cpt-cta-label"
								type="text"
								class="quarantined-cpt-blog-editor-field"
								name="<?php echo esc_attr( self::META_BLOG_CTA_BUTTON_LABEL ); ?>"
								value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_BUTTON_LABEL ] ); ?>"
								placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_label'] ); ?>"
							/>
						</div>
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-cta-url"><?php esc_html_e( 'CTA button URL (optional override)', 'nova-bridge-suite' ); ?></label>
							<input
								id="quarantined-cpt-cta-url"
								type="text"
								class="quarantined-cpt-blog-editor-field"
								name="<?php echo esc_attr( self::META_BLOG_CTA_BUTTON_URL ); ?>"
								value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_BUTTON_URL ] ); ?>"
								placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_url'] ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
						</div>
					</div>
					<div class="quarantined-cpt-blog-field">
						<label><?php esc_html_e( 'CTA copy (optional override)', 'nova-bridge-suite' ); ?></label>
						<?php
						wp_editor(
							(string) $meta[ self::META_BLOG_CTA_COPY ],
							'quarantined_cpt_blog_cta_copy_' . (int) $post->ID,
							[
								'textarea_name' => self::META_BLOG_CTA_COPY,
								'textarea_rows' => 5,
								'editor_class'  => 'quarantined-cpt-blog-editor-field',
								'media_buttons' => false,
								'teeny'         => true,
								'quicktags'     => true,
								'tinymce'       => [
									'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
									'toolbar2'      => '',
									'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
									'resize'        => false,
								],
							]
						);
						?>
					</div>
					<p class="description"><?php esc_html_e( 'Leave any of these fields empty to keep the global CTA defaults for this CPT.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_part_2 ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Rich Text Block 2', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Main body', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-field">
						<?php
						wp_editor(
							(string) $meta[ self::META_BLOG_PART_2 ],
							'quarantined_cpt_blog_part_2_' . (int) $post->ID,
							[
								'textarea_name' => self::META_BLOG_PART_2,
								'textarea_rows' => 10,
								'editor_class'  => 'quarantined-cpt-blog-editor-field',
								'media_buttons' => false,
								'teeny'         => true,
								'quicktags'     => true,
								'tinymce'       => [
									'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
									'toolbar2'      => '',
									'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
									'resize'        => false,
								],
							]
						);
						?>
					</div>
				</div>
			</details>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_faq ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'FAQs (optional)', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Above related articles', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<?php for ( $i = 0; $i < $faq_row_count; ++$i ) : ?>
						<?php
						$faq_question = isset( $faqs[ $i ]['question'] ) ? (string) $faqs[ $i ]['question'] : '';
						$faq_answer   = isset( $faqs[ $i ]['answer'] ) ? (string) $faqs[ $i ]['answer'] : '';
						?>
						<div class="quarantined-cpt-blog-grid">
							<div class="quarantined-cpt-blog-field">
								<label for="quarantined-cpt-faq-question-<?php echo esc_attr( (string) $i ); ?>">
									<?php
									/* translators: %d: faq row number. */
									echo esc_html( sprintf( __( 'Question %d', 'nova-bridge-suite' ), $i + 1 ) );
									?>
								</label>
								<input
									id="quarantined-cpt-faq-question-<?php echo esc_attr( (string) $i ); ?>"
									type="text"
									class="quarantined-cpt-blog-editor-field"
									name="quarantined_cpt_blog_faq_question[]"
									value="<?php echo esc_attr( $faq_question ); ?>"
								/>
							</div>
						</div>
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-faq-answer-<?php echo esc_attr( (string) $i ); ?>">
								<?php
								/* translators: %d: faq row number. */
								echo esc_html( sprintf( __( 'Answer %d', 'nova-bridge-suite' ), $i + 1 ) );
								?>
							</label>
							<textarea
								id="quarantined-cpt-faq-answer-<?php echo esc_attr( (string) $i ); ?>"
								rows="4"
								class="quarantined-cpt-blog-editor-field"
								name="quarantined_cpt_blog_faq_answer[]"
							><?php echo esc_textarea( $faq_answer ); ?></textarea>
						</div>
					<?php endfor; ?>
					<p class="description"><?php esc_html_e( 'Leave questions/answers empty to hide this section.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>

			<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_related ? 'open' : ''; ?>>
				<summary>
					<?php esc_html_e( 'Related Articles (optional)', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Below article body', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-field">
						<label for="quarantined-cpt-related-search"><?php esc_html_e( 'Select related posts', 'nova-bridge-suite' ); ?></label>
						<input
							type="search"
							id="quarantined-cpt-related-search"
							class="quarantined-cpt-blog-related-search"
							placeholder="<?php esc_attr_e( 'Filter posts...', 'nova-bridge-suite' ); ?>"
						/>
						<div class="quarantined-cpt-blog-related-list">
							<?php foreach ( $related_candidates as $candidate ) : ?>
								<?php
								$candidate_id = (int) $candidate->ID;
								$title        = get_the_title( $candidate_id );
								$title        = '' !== $title ? $title : __( '(no title)', 'nova-bridge-suite' );
								?>
								<label
									class="quarantined-cpt-blog-related-item"
									data-blog-related-item
									data-blog-related-title="<?php echo esc_attr( strtolower( $title ) ); ?>"
								>
									<input
										type="checkbox"
										class="quarantined-cpt-blog-editor-field"
										name="<?php echo esc_attr( self::META_BLOG_RELATED_POSTS ); ?>[]"
										value="<?php echo esc_attr( $candidate_id ); ?>"
										<?php checked( in_array( $candidate_id, $selected_related, true ) ); ?>
									/>
									<span><?php echo esc_html( $title ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Leave empty to automatically show the latest 3 articles. Selecting posts here overrides the default list.', 'nova-bridge-suite' ); ?></p>
					</div>
					</div>
				</details>

				<details class="quarantined-cpt-blog-section" data-autotoggle="1" <?php echo $has_after_related_cta ? 'open' : ''; ?>>
					<summary>
						<?php esc_html_e( 'Second Wide CTA (optional)', 'nova-bridge-suite' ); ?>
						<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'After related articles', 'nova-bridge-suite' ); ?></span>
					</summary>
					<div class="quarantined-cpt-blog-section-body">
						<p class="description"><?php esc_html_e( 'Optional per-post overrides. Leave fields empty to use global CTA defaults configured for this CPT in NOVA Blog Settings.', 'nova-bridge-suite' ); ?></p>
						<div class="quarantined-cpt-blog-field">
							<label for="quarantined-cpt-cta-after-related-disable">
								<input
									id="quarantined-cpt-cta-after-related-disable"
									type="checkbox"
									class="quarantined-cpt-blog-editor-field"
									name="<?php echo esc_attr( self::META_BLOG_CTA_AFTER_RELATED_DISABLE ); ?>"
									value="1"
									<?php checked( $cta_after_related_disabled ); ?>
								/>
								<?php esc_html_e( 'Disable this CTA for this post', 'nova-bridge-suite' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When checked, this after-related CTA is hidden even if global defaults are configured.', 'nova-bridge-suite' ); ?></p>
						</div>
						<div class="quarantined-cpt-blog-grid">
							<div class="quarantined-cpt-blog-field">
								<label for="quarantined-cpt-cta-after-related-title"><?php esc_html_e( 'CTA title (optional override)', 'nova-bridge-suite' ); ?></label>
								<input
									id="quarantined-cpt-cta-after-related-title"
									type="text"
									class="quarantined-cpt-blog-editor-field"
									name="<?php echo esc_attr( self::META_BLOG_CTA_AFTER_RELATED_TITLE ); ?>"
									value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_TITLE ] ); ?>"
									placeholder="<?php echo esc_attr( (string) $after_related_cta_global['title'] ); ?>"
								/>
							</div>
							<div class="quarantined-cpt-blog-field">
								<label for="quarantined-cpt-cta-after-related-label"><?php esc_html_e( 'CTA button label (optional override)', 'nova-bridge-suite' ); ?></label>
								<input
									id="quarantined-cpt-cta-after-related-label"
									type="text"
									class="quarantined-cpt-blog-editor-field"
									name="<?php echo esc_attr( self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL ); ?>"
									value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL ] ); ?>"
									placeholder="<?php echo esc_attr( (string) $after_related_cta_global['button_label'] ); ?>"
								/>
							</div>
							<div class="quarantined-cpt-blog-field">
								<label for="quarantined-cpt-cta-after-related-url"><?php esc_html_e( 'CTA button URL (optional override)', 'nova-bridge-suite' ); ?></label>
								<input
									id="quarantined-cpt-cta-after-related-url"
									type="text"
									class="quarantined-cpt-blog-editor-field"
									name="<?php echo esc_attr( self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL ); ?>"
									value="<?php echo esc_attr( (string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL ] ); ?>"
									placeholder="<?php echo esc_attr( (string) $after_related_cta_global['button_url'] ); ?>"
								/>
								<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
							</div>
						</div>
						<div class="quarantined-cpt-blog-field">
							<label><?php esc_html_e( 'CTA copy (optional override)', 'nova-bridge-suite' ); ?></label>
							<?php
							wp_editor(
								(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_COPY ],
								'quarantined_cpt_blog_cta_after_related_copy_' . (int) $post->ID,
								[
									'textarea_name' => self::META_BLOG_CTA_AFTER_RELATED_COPY,
									'textarea_rows' => 5,
									'editor_class'  => 'quarantined-cpt-blog-editor-field',
									'media_buttons' => false,
									'teeny'         => true,
									'quicktags'     => true,
									'tinymce'       => [
										'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
										'toolbar2'      => '',
										'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
										'resize'        => false,
									],
								]
							);
							?>
						</div>
						<p class="description"><?php esc_html_e( 'Leave any of these fields empty to keep the global CTA defaults for this CPT.', 'nova-bridge-suite' ); ?></p>
					</div>
				</details>

				<details class="quarantined-cpt-blog-section" open>
					<summary>
						<?php esc_html_e( 'Author Mention & Share Row', 'nova-bridge-suite' ); ?>
					<span class="quarantined-cpt-blog-section-location"><?php esc_html_e( 'Auto-generated in frontend', 'nova-bridge-suite' ); ?></span>
				</summary>
				<div class="quarantined-cpt-blog-section-body">
					<div class="quarantined-cpt-blog-author-preview">
						<?php if ( '' !== $author_avatar ) : ?>
							<img src="<?php echo esc_url( $author_avatar ); ?>" alt="" loading="lazy" />
						<?php endif; ?>
						<div>
							<strong><?php echo esc_html( '' !== $author_name ? $author_name : __( '(no author name)', 'nova-bridge-suite' ) ); ?></strong>
							<?php if ( '' !== $author_title || '' !== $author_org ) : ?>
								<p>
									<?php echo esc_html( trim( $author_title . ( '' !== $author_title && '' !== $author_org ? ' @ ' : '' ) . $author_org ) ); ?>
								</p>
							<?php endif; ?>
						</div>
					</div>
					<p class="description"><?php esc_html_e( 'Author avatar/name are pulled from the selected post author profile. Read time and social share links are rendered automatically from this post.', 'nova-bridge-suite' ); ?></p>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Parses a simple newline-delimited string list.
	 *
	 * @param string $raw Raw text.
	 * @return string[]
	 */
	private function parse_blog_string_lines( string $raw ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $raw );

		if ( ! is_array( $lines ) ) {
			return [];
		}

		$clean = [];

		foreach ( $lines as $line ) {
			$line = sanitize_text_field( trim( (string) $line ) );

			if ( '' !== $line ) {
				$clean[] = $line;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Persists blog layout fields when a managed post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update operation.
	 */
	public function save_blog_meta_box( int $post_id, \WP_Post $post, bool $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$nonce = isset( $_POST['quarantined_cpt_blog_layout_nonce'] )
			? wp_unslash( (string) $_POST['quarantined_cpt_blog_layout_nonce'] )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'quarantined_cpt_blog_layout_save' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$string_fields = [
			self::META_BLOG_CTA_TITLE,
			self::META_BLOG_CTA_BUTTON_LABEL,
			self::META_BLOG_CTA_AFTER_RELATED_TITLE,
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL,
		];

		foreach ( $string_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) ) : '';
			$this->persist_blog_meta_value( $post_id, $field, $value );
		}

		$url_fields = [
			self::META_BLOG_CTA_BUTTON_URL,
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL,
		];

		foreach ( $url_fields as $url_field ) {
			$url = isset( $_POST[ $url_field ] )
				? self::sanitize_blog_cta_url( wp_unslash( (string) $_POST[ $url_field ] ) )
				: '';
			$this->persist_blog_meta_value( $post_id, $url_field, $url );
		}

		$rich_fields = [
			self::META_BLOG_INTRO,
			self::META_BLOG_PART_1,
			self::META_BLOG_CTA_COPY,
			self::META_BLOG_PART_2,
			self::META_BLOG_CTA_AFTER_RELATED_COPY,
		];

		foreach ( $rich_fields as $field ) {
			$value = isset( $_POST[ $field ] ) ? $this->sanitize_blog_rich_text( wp_unslash( (string) $_POST[ $field ] ) ) : '';

			if ( self::META_BLOG_PART_1 === $field || self::META_BLOG_PART_2 === $field ) {
				$value = $this->enforce_blog_body_heading_policy( $value, (string) $post->post_type );
			}

			$this->persist_blog_meta_value( $post_id, $field, $value );
		}

		$cta_disable = isset( $_POST[ self::META_BLOG_CTA_DISABLE ] ) ? 1 : 0;
		$this->persist_blog_meta_value( $post_id, self::META_BLOG_CTA_DISABLE, $cta_disable );

		$cta_after_related_disable = isset( $_POST[ self::META_BLOG_CTA_AFTER_RELATED_DISABLE ] ) ? 1 : 0;
		$this->persist_blog_meta_value( $post_id, self::META_BLOG_CTA_AFTER_RELATED_DISABLE, $cta_after_related_disable );

		$toc_disable = isset( $_POST[ self::META_BLOG_TOC_DISABLE ] ) ? 1 : 0;
		$this->persist_blog_meta_value( $post_id, self::META_BLOG_TOC_DISABLE, $toc_disable );

		$takeaways_raw = isset( $_POST['quarantined_cpt_blog_key_takeaways_lines'] )
			? sanitize_textarea_field( wp_unslash( (string) $_POST['quarantined_cpt_blog_key_takeaways_lines'] ) )
			: '';
		$takeaways = self::sanitize_blog_string_array( $this->parse_blog_string_lines( $takeaways_raw ) );
		$this->persist_blog_meta_value( $post_id, self::META_BLOG_KEY_TAKEAWAYS, $takeaways );

		$faq_questions = isset( $_POST['quarantined_cpt_blog_faq_question'] ) && is_array( $_POST['quarantined_cpt_blog_faq_question'] )
			? wp_unslash( $_POST['quarantined_cpt_blog_faq_question'] )
			: [];
		$faq_answers   = isset( $_POST['quarantined_cpt_blog_faq_answer'] ) && is_array( $_POST['quarantined_cpt_blog_faq_answer'] )
			? wp_unslash( $_POST['quarantined_cpt_blog_faq_answer'] )
			: [];
		$faq_count     = max( count( $faq_questions ), count( $faq_answers ) );
		$faq_rows      = [];

		for ( $i = 0; $i < $faq_count; ++$i ) {
			$question = isset( $faq_questions[ $i ] ) ? sanitize_text_field( (string) $faq_questions[ $i ] ) : '';
			$answer   = isset( $faq_answers[ $i ] ) ? $this->sanitize_blog_rich_text( (string) $faq_answers[ $i ] ) : '';

			if ( '' === $question && '' === trim( wp_strip_all_tags( $answer ) ) ) {
				continue;
			}

			$faq_rows[] = [
				'question' => $question,
				'answer'   => $answer,
			];
		}

		$this->persist_blog_meta_value( $post_id, self::META_BLOG_FAQS, self::sanitize_blog_faqs( $faq_rows ) );

		$related = isset( $_POST[ self::META_BLOG_RELATED_POSTS ] ) && is_array( $_POST[ self::META_BLOG_RELATED_POSTS ] )
			? self::sanitize_blog_related_posts( wp_unslash( $_POST[ self::META_BLOG_RELATED_POSTS ] ) )
			: [];
		$related = array_values(
			array_filter(
				$related,
				static function ( $id ) use ( $post_id ) {
					return (int) $id !== (int) $post_id;
				}
			)
		);
		$this->persist_blog_meta_value( $post_id, self::META_BLOG_RELATED_POSTS, $related );

		// Deprecated manual fields are cleaned up to enforce auto-generated behavior.
		delete_post_meta( $post_id, self::META_BLOG_TOC );
		delete_post_meta( $post_id, self::META_BLOG_LEGACY_READ_TIME );

		$this->sync_post_excerpt_from_intro( $post_id );
	}

	/**
	 * Stores a post meta value and removes empty payloads.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $key     Meta key.
	 * @param string|int|array $value   Sanitized value.
	 */
	private function persist_blog_meta_value( int $post_id, string $key, $value ): void {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}

			return;
		}

		if ( is_int( $value ) ) {
			if ( $value > 0 ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}

			return;
		}

		$value = trim( (string) $value );

		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Synchronizes post excerpt from the intro field (plain text only).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function sync_post_excerpt_from_intro( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return;
		}

		$excerpt = $this->get_intro_based_blog_excerpt( $post, self::BLOG_EXCERPT_LENGTH );

		$current_excerpt = (string) $post->post_excerpt;

		if ( $current_excerpt === $excerpt ) {
			return;
		}

		remove_action( 'save_post', [ $this, 'save_blog_meta_box' ], 10 );

		wp_update_post(
			[
				'ID'           => $post_id,
				'post_excerpt' => $excerpt,
			]
		);

		add_action( 'save_post', [ $this, 'save_blog_meta_box' ], 10, 3 );
	}

	/**
	 * Registers REST helper fields for blog layout prompts and payloads.
	 */
	public function register_blog_rest_fields(): void {
		$post_types = $this->get_managed_or_default_post_types();

		foreach ( $post_types as $post_type ) {
			$allowed_meta_keys  = $this->get_blog_ai_editable_meta_keys( $post_type );
			$meta_schema        = $this->get_blog_rest_meta_schema( $allowed_meta_keys );
			$description_schema = [];

			foreach ( $allowed_meta_keys as $meta_key ) {
				$description_schema[ $meta_key ] = [ 'type' => 'string' ];
			}

			register_rest_field(
				$post_type,
				'meta',
				[
					'get_callback' => [ $this, 'get_blog_rest_meta_field' ],
					'schema'       => [
						'description' => __( 'Blog layout meta fields for this post type.', 'nova-bridge-suite' ),
						'type'        => 'object',
						'context'     => [ 'view', 'edit' ],
						'properties'  => $meta_schema,
					],
				]
			);

			register_rest_field(
				$post_type,
				'meta_descriptions',
				[
					'get_callback' => [ $this, 'get_blog_meta_descriptions_field' ],
					'schema'       => [
						'description' => __( 'Descriptions for NOVA Blog layout fields.', 'nova-bridge-suite' ),
						'type'        => 'object',
						'context'     => [ 'view', 'edit' ],
						'properties'  => $description_schema,
					],
				]
			);

			register_rest_field(
				$post_type,
				'meta_note',
				[
					'get_callback' => [ $this, 'get_blog_meta_note_field' ],
					'schema'       => [
						'description' => __( 'Usage notes for NOVA Blog layout fields.', 'nova-bridge-suite' ),
						'type'        => 'string',
						'context'     => [ 'view', 'edit' ],
					],
				]
			);
		}
	}

	/**
	 * Returns REST schema for blog layout fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_blog_rest_meta_schema( array $allowed_keys = [] ): array {
		if ( empty( $allowed_keys ) ) {
			return [];
		}

		$definitions  = $this->get_blog_meta_definitions();
		$descriptions = $this->get_blog_ai_meta_descriptions( $allowed_keys );
		$schema       = [];

		foreach ( $definitions as $key => $definition ) {
			if ( ! empty( $allowed_keys ) && ! in_array( $key, $allowed_keys, true ) ) {
				continue;
			}

			$show_in_rest = $definition['show_in_rest'];
			$field_schema = null;

			if ( true === $show_in_rest ) {
				$field_schema = [
					'type' => $definition['type'],
				];
			} elseif ( is_array( $show_in_rest ) && isset( $show_in_rest['schema'] ) ) {
				$field_schema = $show_in_rest['schema'];
			}

			if ( ! is_array( $field_schema ) ) {
				continue;
			}

			if ( isset( $descriptions[ $key ] ) ) {
				$field_schema['description'] = (string) $descriptions[ $key ];
			}

			$schema[ $key ] = $field_schema;
		}

		return $schema;
	}

	/**
	 * REST field callback returning curated blog meta values.
	 *
	 * @param array            $object     Rest object.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request    Current request.
	 * @return array<string,mixed>
	 */
	public function get_blog_rest_meta_field( array $object, string $field_name, \WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) ) {
			return [];
		}

		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return [];
		}

		return $this->get_blog_ai_meta_values( $post );
	}

	/**
	 * Returns REST schema for the assembled blog layout payload.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_blog_layout_rest_schema(): array {
		$cta_schema = [
			'type'       => 'object',
			'properties' => [
				'active'          => [ 'type' => 'boolean' ],
				'disabled'        => [ 'type' => 'boolean' ],
				'title'           => [ 'type' => 'string' ],
				'copy'            => [ 'type' => 'string' ],
				'button_label'    => [ 'type' => 'string' ],
				'button_url'      => [ 'type' => 'string' ],
				'global_fallback' => [
					'type'       => 'object',
					'properties' => [
						'title'        => [ 'type' => 'string' ],
						'copy'         => [ 'type' => 'string' ],
						'button_label' => [ 'type' => 'string' ],
						'button_url'   => [ 'type' => 'string' ],
					],
				],
			],
		];

		return [
			'intro'             => [ 'type' => 'string' ],
			'key_takeaways'     => [
				'type'  => 'array',
				'items' => [ 'type' => 'string' ],
			],
			'toc'               => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'label' => [ 'type' => 'string' ],
						'url'   => [ 'type' => 'string' ],
						'level' => [ 'type' => 'integer' ],
					],
				],
			],
			'toc_disabled'      => [ 'type' => 'boolean' ],
			'part_1'            => [ 'type' => 'string' ],
			'part_2'            => [ 'type' => 'string' ],
			'faqs'              => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'question' => [ 'type' => 'string' ],
						'answer'   => [ 'type' => 'string' ],
					],
				],
			],
			'cta'               => $cta_schema,
			'cta_after_related' => $cta_schema,
			'read_time_minutes' => [ 'type' => 'integer' ],
			'share_links'       => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'key'   => [ 'type' => 'string' ],
						'label' => [ 'type' => 'string' ],
						'url'   => [ 'type' => 'string' ],
						'copy'  => [ 'type' => 'boolean' ],
					],
				],
			],
			'related_posts'     => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'id'        => [ 'type' => 'integer' ],
						'title'     => [ 'type' => 'string' ],
						'url'       => [ 'type' => 'string' ],
						'excerpt'   => [ 'type' => 'string' ],
						'thumbnail' => [ 'type' => 'string' ],
					],
				],
			],
		];
	}

	/**
	 * Hooks rest_prepare filters for managed blog post types.
	 */
	public function register_blog_rest_prepare_filters(): void {
		$post_types = $this->get_managed_or_default_post_types();

		foreach ( $post_types as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, [ $this, 'filter_rest_blog_response' ], 999, 3 );
		}
	}

	/**
	 * Ensures blog REST responses expose a deterministic API-first payload.
	 *
	 * @param mixed            $response REST response.
	 * @param mixed            $post     Post object.
	 * @param \WP_REST_Request $request  Request instance.
	 * @return mixed
	 */
	public function filter_rest_blog_response( $response, $post, \WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof \WP_Post ) {
			return $response;
		}

		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		$editable_meta       = $this->get_blog_ai_meta_values( $post );
		$allowed_blog_fields = array_keys( $editable_meta );
		$component_settings  = $this->get_component_settings_for_type( (string) $post->post_type );

		$data['meta']              = $editable_meta;
		$data['meta_descriptions'] = $this->get_blog_ai_meta_descriptions( $allowed_blog_fields );
		$data['meta_note']         = $this->get_blog_ai_meta_note( $allowed_blog_fields );

		if ( isset( $data['blog_layout'] ) ) {
			unset( $data['blog_layout'] );
		}

		if ( isset( $component_settings['title'] ) && ! $component_settings['title'] && isset( $data['title'] ) ) {
			unset( $data['title'] );
		}

		// Keep bridge-compatible meta_all/meta_all_flat for SEO tooling, but remove duplicate blog_* content keys.
		if ( isset( $data['meta_all'] ) && is_array( $data['meta_all'] ) ) {
			foreach ( $data['meta_all'] as $meta_key => $value ) {
				$meta_key = (string) $meta_key;
				if ( 0 === strpos( $meta_key, 'blog_' ) ) {
					unset( $data['meta_all'][ $meta_key ] );
				}
			}
		}

		if ( isset( $data['meta_all_flat'] ) && is_array( $data['meta_all_flat'] ) ) {
			foreach ( $data['meta_all_flat'] as $meta_key => $value ) {
				$meta_key = (string) $meta_key;
				$base_key = explode( '.', $meta_key, 2 )[0];
				if ( 0 === strpos( $base_key, 'blog_' ) ) {
					unset( $data['meta_all_flat'][ $meta_key ] );
				}
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * REST field callback for layout-field descriptions.
	 *
	 * @param array            $object     Rest object.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request    Current request.
	 * @return array<string,string>
	 */
	public function get_blog_meta_descriptions_field( array $object, string $field_name, \WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return $this->get_blog_ai_meta_descriptions();
		}

		$allowed_keys = $this->get_blog_ai_editable_meta_keys( (string) $post->post_type );

		return $this->get_blog_ai_meta_descriptions( $allowed_keys );
	}

	/**
	 * REST field callback for a short layout usage note.
	 *
	 * @param array            $object     Rest object.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request    Current request.
	 * @return string
	 */
	public function get_blog_meta_note_field( array $object, string $field_name, \WP_REST_Request $request ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return $this->get_blog_ai_meta_note();
		}

		$allowed_keys = $this->get_blog_ai_editable_meta_keys( (string) $post->post_type );

		return $this->get_blog_ai_meta_note( $allowed_keys );
	}

	/**
	 * REST field callback exposing assembled layout data.
	 *
	 * @param array            $object     Rest object.
	 * @param string           $field_name Field name.
	 * @param \WP_REST_Request $request    Current request.
	 * @return array<string,mixed>
	 */
	public function get_blog_layout_rest_field( array $object, string $field_name, \WP_REST_Request $request ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$post_id = isset( $object['id'] ) ? absint( $object['id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! ( $post instanceof \WP_Post ) ) {
			return [];
		}

		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return [];
		}

		return $this->build_blog_layout_data( $post );
	}

	/**
	 * Provides a concise usage note for blog layout fields.
	 *
	 * @return string
	 */
	private function get_blog_meta_note(): string {
		return $this->get_blog_ai_meta_note( $this->get_blog_ai_editable_meta_keys() );
	}

	/**
	 * Registers a focused API endpoint for blog payload retrieval.
	 */
	public function register_blog_rest_routes(): void {
		register_rest_route(
			'nova-blog/v1',
			'/post/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_blog_post' ],
				'permission_callback' => [ $this, 'rest_can_read_blog_post' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			'nova-blog/v1',
			'/post/by-slug/(?P<slug>[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'rest_get_blog_post_by_slug' ],
				'permission_callback' => [ $this, 'rest_can_read_blog_post' ],
				'args'                => [
					'slug'      => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => [ $this, 'sanitize_rest_slug_path' ],
					],
					'post_type' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					],
				],
			]
		);
	}

	/**
	 * Sanitizes a slash-delimited slug path for REST requests.
	 *
	 * @param mixed $value Raw request value.
	 * @return string
	 */
	public function sanitize_rest_slug_path( $value ): string {
		return $this->sanitize_slug_path( (string) $value );
	}

	/**
	 * Guards the dedicated blog endpoint with publish/capability checks.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return bool
	 */
	public function rest_can_read_blog_post( \WP_REST_Request $request ): bool {
		$post = $this->resolve_rest_blog_post( $request );

		if ( ! ( $post instanceof \WP_Post ) ) {
			// Let callbacks return a proper 404 payload for unresolved IDs/slugs.
			return true;
		}

		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return current_user_can( 'edit_post', (int) $post->ID );
	}

	/**
	 * Returns an API-first payload for a single blog post.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_get_blog_post( \WP_REST_Request $request ) {
		$post = $this->resolve_rest_blog_post( $request );

		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return new \WP_Error( 'not_found', __( 'Blog post not found.', 'nova-bridge-suite' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $this->build_blog_rest_payload( $post ) );
	}

	/**
	 * Returns an API-first payload for a single blog post resolved by slug.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_get_blog_post_by_slug( \WP_REST_Request $request ) {
		$post = $this->resolve_rest_blog_post( $request );

		if ( ! ( $post instanceof \WP_Post ) || ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return new \WP_Error( 'not_found', __( 'Blog post not found.', 'nova-bridge-suite' ), [ 'status' => 404 ] );
		}

		return rest_ensure_response( $this->build_blog_rest_payload( $post ) );
	}

	/**
	 * Resolves a managed blog post from REST request arguments.
	 *
	 * @param \WP_REST_Request $request Request instance.
	 * @return \WP_Post|null
	 */
	private function resolve_rest_blog_post( \WP_REST_Request $request ): ?\WP_Post {
		$post_id = absint( $request->get_param( 'id' ) );

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );

			if ( $post instanceof \WP_Post && $this->is_managed_post_type( (string) $post->post_type ) ) {
				return $post;
			}

			return null;
		}

		$slug = $this->sanitize_slug_path( (string) $request->get_param( 'slug' ) );

		if ( '' === $slug ) {
			return null;
		}

		$post_type       = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$candidate_types = [];

		if ( '' !== $post_type ) {
			if ( ! $this->is_managed_post_type( $post_type ) ) {
				return null;
			}

			$candidate_types[] = $post_type;
		} else {
			$candidate_types = $this->get_managed_or_default_post_types();
		}

		if ( empty( $candidate_types ) ) {
			return null;
		}

		$post = get_page_by_path( $slug, OBJECT, $candidate_types );

		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}

		return $this->is_managed_post_type( (string) $post->post_type ) ? $post : null;
	}

	/**
	 * Builds the dedicated REST payload used by automation systems.
	 *
	 * @param \WP_Post $post Blog post.
	 * @return array<string,mixed>
	 */
	private function build_blog_rest_payload( \WP_Post $post ): array {
		$post_type          = (string) $post->post_type;
		$component_settings = $this->get_component_settings_for_type( $post_type );
		$meta               = $this->get_blog_ai_meta_values( $post );
		$allowed_meta_keys  = array_keys( $meta );
		$editable_fields    = $allowed_meta_keys;

		$payload = [
			'id'                => (int) $post->ID,
			'post_type'         => $post_type,
			'slug'              => (string) $post->post_name,
			'path'              => $this->get_post_slug_path( $post ),
			'parent_id'         => (int) $post->post_parent,
			'meta'              => $meta,
			'meta_descriptions' => $this->get_blog_ai_meta_descriptions( $allowed_meta_keys ),
			'meta_note'         => $this->get_blog_ai_meta_note( $allowed_meta_keys ),
		];

		if ( ! isset( $component_settings['title'] ) || $component_settings['title'] ) {
			$payload['title'] = get_the_title( $post );
			array_unshift( $editable_fields, 'title' );
		}

		$payload['editable_fields'] = array_values( $editable_fields );

		return $payload;
	}

	/**
	 * Sanitizes a slash-delimited slug path while preserving hierarchy.
	 *
	 * @param string $path Raw slug or slug path.
	 * @return string
	 */
	private function sanitize_slug_path( string $path ): string {
		$path = trim( $path, '/' );

		if ( '' === $path ) {
			return '';
		}

		$segments = array_filter(
			array_map( 'sanitize_title', explode( '/', $path ) ),
			static function ( $segment ): bool {
				return '' !== $segment;
			}
		);

		return implode( '/', $segments );
	}

	/**
	 * Returns a slash-delimited slug path for hierarchical post types.
	 *
	 * @param \WP_Post $post Post instance.
	 * @return string
	 */
	private function get_post_slug_path( \WP_Post $post ): string {
		$type_object = get_post_type_object( $post->post_type );

		if ( $type_object && ! empty( $type_object->hierarchical ) ) {
			$segments  = [];
			$ancestors = array_reverse( get_post_ancestors( $post ) );

			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );

				if ( ! ( $ancestor instanceof \WP_Post ) || $ancestor->post_type !== $post->post_type || '' === $ancestor->post_name ) {
					continue;
				}

				$segments[] = (string) $ancestor->post_name;
			}

			if ( '' !== $post->post_name ) {
				$segments[] = (string) $post->post_name;
			}

			$path = implode( '/', $segments );
			if ( '' !== $path ) {
				return $path;
			}
		}

		return (string) $post->post_name;
	}

	/**
	 * Builds a normalized author payload for blog API responses.
	 *
	 * @param \WP_Post $post Blog post.
	 * @return array<string,mixed>
	 */
	private function build_blog_author_payload( \WP_Post $post ): array {
		$author_id   = (int) $post->post_author;
		$author_user = $author_id ? get_userdata( $author_id ) : null;

		if ( ! ( $author_user instanceof \WP_User ) ) {
			return [];
		}

		$profile = $this->build_author_profile_data( $author_user );
		$url     = self::author_archive_enabled() ? self::get_author_permalink( $author_user ) : get_author_posts_url( $author_user->ID, $author_user->user_nicename );

		return [
			'id'          => (int) $author_user->ID,
			'name'        => self::get_author_display_name( $author_user ),
			'url'         => $url,
			'avatar_url'  => self::get_author_avatar_url( $author_user, 128 ),
			'description' => get_the_author_meta( 'description', $author_user->ID ),
			'profile'     => $profile,
		];
	}

	/**
	 * Builds the rendered blog-layout payload used by templates and API.
	 *
	 * @param \WP_Post $post Blog post.
	 * @return array<string,mixed>
	 */
	private function build_blog_layout_data( \WP_Post $post ): array {
		$meta      = $this->get_blog_meta_values( (int) $post->ID );
		$content   = $this->prepare_content( $post );
		$post_type = (string) $post->post_type;

		$intro = (string) $meta[ self::META_BLOG_INTRO ];
		if ( ! $this->blog_html_has_text( $intro ) ) {
			$intro_source = has_excerpt( $post ) ? (string) $post->post_excerpt : '';
			$intro        = '' !== trim( $intro_source ) ? wpautop( esc_html( $intro_source ) ) : '';
		}

		$part_1 = (string) $meta[ self::META_BLOG_PART_1 ];
		if ( ! $this->blog_html_has_text( $part_1 ) ) {
			$part_1 = $content;
		}
		$part_1 = $this->enforce_blog_body_heading_policy( $part_1, $post_type );

		$part_2 = (string) $meta[ self::META_BLOG_PART_2 ];
		$part_2 = $this->enforce_blog_body_heading_policy( $part_2, $post_type );

		$toc_payload = $this->build_blog_toc_payload( $part_1, $part_2 );
		$part_1      = (string) $toc_payload['part_1'];
		$part_2      = (string) $toc_payload['part_2'];
		$toc_items   = isset( $toc_payload['toc'] ) && is_array( $toc_payload['toc'] ) ? $toc_payload['toc'] : [];
		$toc_disabled = ! empty( $meta[ self::META_BLOG_TOC_DISABLE ] );

		if ( $toc_disabled ) {
			$toc_items = [];
		}

		$primary_cta_defaults = $this->get_blog_global_cta_defaults( 'primary', (string) $post->post_type );
		$cta                  = $this->build_effective_blog_cta_payload(
			(string) $meta[ self::META_BLOG_CTA_TITLE ],
			(string) $meta[ self::META_BLOG_CTA_COPY ],
			(string) $meta[ self::META_BLOG_CTA_BUTTON_LABEL ],
			(string) $meta[ self::META_BLOG_CTA_BUTTON_URL ],
			! empty( $meta[ self::META_BLOG_CTA_DISABLE ] ),
			$primary_cta_defaults
		);

		$after_related_cta_defaults = $this->get_blog_global_cta_defaults( 'after_related', (string) $post->post_type );
		$cta_after_related          = $this->build_effective_blog_cta_payload(
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_TITLE ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_COPY ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL ],
			(string) $meta[ self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL ],
			! empty( $meta[ self::META_BLOG_CTA_AFTER_RELATED_DISABLE ] ),
			$after_related_cta_defaults
		);

		return [
			'intro'             => $intro,
			'key_takeaways'     => is_array( $meta[ self::META_BLOG_KEY_TAKEAWAYS ] ) ? $meta[ self::META_BLOG_KEY_TAKEAWAYS ] : [],
			'toc'               => $toc_items,
			'toc_disabled'      => $toc_disabled,
			'part_1'            => $part_1,
			'part_2'            => $part_2,
			'faqs'              => is_array( $meta[ self::META_BLOG_FAQS ] ) ? self::sanitize_blog_faqs( $meta[ self::META_BLOG_FAQS ] ) : [],
			'cta'               => $cta,
			'cta_after_related' => $cta_after_related,
			'read_time_minutes' => $this->calculate_blog_read_time( $post, $meta ),
			'share_links'       => $this->get_blog_share_links_for_post( $post ),
			'related_posts'     => $this->get_blog_related_posts_payload( $post, $meta ),
		];
	}

	/**
	 * Builds an automatic TOC from H2/H3 headings and injects stable anchors.
	 *
	 * @param string $part_1 First body section HTML.
	 * @param string $part_2 Second body section HTML.
	 * @return array<string,mixed>
	 */
	private function build_blog_toc_payload( string $part_1, string $part_2 ): array {
		$toc          = [];
		$used_anchors = [];
		$part_1       = $this->annotate_blog_headings( $part_1, $toc, $used_anchors );
		$part_2       = $this->annotate_blog_headings( $part_2, $toc, $used_anchors );

		return [
			'part_1' => $part_1,
			'part_2' => $part_2,
			'toc'    => $toc,
		];
	}

	/**
	 * Prepares UTF-8 HTML for DOMDocument without using deprecated HTML-ENTITIES conversion.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function encode_html_for_dom_document( string $html ): string {
		if ( '' === $html || ! function_exists( 'mb_encode_numericentity' ) ) {
			return $html;
		}

		return mb_encode_numericentity( $html, [ 0x80, 0x10FFFF, 0, 0x1FFFFF ], 'UTF-8' );
	}

	/**
	 * Injects heading IDs for H2/H3 and appends TOC entries.
	 *
	 * @param string              $html         HTML content.
	 * @param array<int,array<string,mixed>> $toc          TOC accumulator.
	 * @param array<string,bool>  $used_anchors Used anchors map.
	 * @return string
	 */
	private function annotate_blog_headings( string $html, array &$toc, array &$used_anchors ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( class_exists( '\DOMDocument' ) && class_exists( '\DOMXPath' ) ) {
			$previous_errors = libxml_use_internal_errors( true );
			$dom             = new \DOMDocument( '1.0', 'UTF-8' );
			$encoded_html    = $this->encode_html_for_dom_document( $html );
			$options         = 0;

			if ( defined( 'LIBXML_HTML_NOIMPLIED' ) ) {
				$options |= LIBXML_HTML_NOIMPLIED;
			}

			if ( defined( 'LIBXML_HTML_NODEFDTD' ) ) {
				$options |= LIBXML_HTML_NODEFDTD;
			}

			$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $encoded_html . '</div>', $options );

			if ( $loaded ) {
				$xpath    = new \DOMXPath( $dom );
				$headings = $xpath->query( '//h2|//h3|//h4' );

				if ( $headings instanceof \DOMNodeList ) {
					foreach ( $headings as $heading ) {
						if ( ! ( $heading instanceof \DOMElement ) ) {
							continue;
						}

						$label = sanitize_text_field( preg_replace( '/\s+/u', ' ', trim( (string) $heading->textContent ) ) );

						if ( '' === $label ) {
							continue;
						}

						$existing = sanitize_title( (string) $heading->getAttribute( 'id' ) );
						$anchor   = $this->get_unique_blog_anchor( '' !== $existing ? $existing : $label, $used_anchors );
						$level    = absint( substr( strtolower( $heading->tagName ), 1 ) );
						$level    = max( 2, min( 4, $level ) );

						$heading->setAttribute( 'id', $anchor );
						$toc[] = [
							'label' => $label,
							'url'   => '#' . $anchor,
							'level' => $level,
						];
					}
				}

				$container = $dom->getElementsByTagName( 'div' )->item( 0 );

				if ( $container instanceof \DOMElement ) {
					$rebuilt = '';

					foreach ( $container->childNodes as $node ) {
						$rebuilt .= $dom->saveHTML( $node );
					}

					if ( '' !== $rebuilt ) {
						libxml_clear_errors();
						libxml_use_internal_errors( $previous_errors );

						return $rebuilt;
					}
				}
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}

		return (string) preg_replace_callback(
			'/<h([234])([^>]*)>(.*?)<\/h\1>/is',
			function ( array $matches ) use ( &$toc, &$used_anchors ) {
				$level      = isset( $matches[1] ) ? (string) $matches[1] : '2';
				$attributes = isset( $matches[2] ) ? (string) $matches[2] : '';
				$inner_html = isset( $matches[3] ) ? (string) $matches[3] : '';
				$label      = sanitize_text_field( wp_strip_all_tags( $inner_html ) );

				if ( '' === $label ) {
					return $matches[0];
				}

				$existing = '';
				if ( preg_match( '/\bid\s*=\s*([\'"])(.*?)\1/i', $attributes, $id_match ) ) {
					$existing = sanitize_title( (string) $id_match[2] );
				}

				$anchor = $this->get_unique_blog_anchor( '' !== $existing ? $existing : $label, $used_anchors );

				if ( '' !== $existing ) {
					$attributes = (string) preg_replace( '/\bid\s*=\s*([\'"]).*?\1/i', 'id="' . esc_attr( $anchor ) . '"', $attributes, 1 );
				} else {
					$attributes .= ' id="' . esc_attr( $anchor ) . '"';
				}

				$toc[] = [
					'label' => $label,
					'url'   => '#' . $anchor,
					'level' => max( 2, min( 4, absint( $level ) ) ),
				];

				return '<h' . $level . $attributes . '>' . $inner_html . '</h' . $level . '>';
			},
			$html
		);
	}

	/**
	 * Produces a unique anchor slug for heading linking.
	 *
	 * @param string            $candidate Candidate anchor text.
	 * @param array<string,bool> $used_anchors Used anchors map.
	 * @return string
	 */
	private function get_unique_blog_anchor( string $candidate, array &$used_anchors ): string {
		$slug = sanitize_title( $candidate );

		if ( '' === $slug ) {
			$slug = 'section';
		}

		$unique = $slug;
		$index  = 2;

		while ( isset( $used_anchors[ $unique ] ) ) {
			$unique = $slug . '-' . $index;
			++$index;
		}

		$used_anchors[ $unique ] = true;

		return $unique;
	}

	/**
	 * Builds related article cards from selected IDs.
	 *
	 * @param \WP_Post             $post Blog post.
	 * @param array<string,mixed> $meta Blog meta values.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_blog_related_posts_payload( \WP_Post $post, array $meta ): array {
		$related_ids = isset( $meta[ self::META_BLOG_RELATED_POSTS ] ) && is_array( $meta[ self::META_BLOG_RELATED_POSTS ] )
			? self::sanitize_blog_related_posts( $meta[ self::META_BLOG_RELATED_POSTS ] )
			: [];

		$related_ids = \Nova_Bridge_Suite_WPML_Support::maybe_translate_post_ids( $related_ids );
		$related_ids = array_values(
			array_filter(
				$related_ids,
				static function ( $id ) use ( $post ) {
					return (int) $id !== (int) $post->ID;
				}
			)
		);

		if ( ! empty( $related_ids ) ) {
			$related_posts = get_posts(
				[
					'post_type'      => 'any',
					'post_status'    => 'publish',
					'post__in'       => $related_ids,
					'orderby'        => 'post__in',
					'posts_per_page' => count( $related_ids ),
					'no_found_rows'  => true,
				]
			);
		} else {
			$default_post_types = $this->get_cpt_types();

			if ( empty( $default_post_types ) ) {
				$default_post_types = [ (string) $post->post_type ];
			}

			$related_posts = get_posts(
				[
					'post_type'           => $default_post_types,
					'post_status'         => 'publish',
					'posts_per_page'      => 3,
					'post__not_in'        => [ (int) $post->ID ],
					'orderby'             => 'date',
					'order'               => 'DESC',
					'no_found_rows'       => true,
					'suppress_filters'    => false,
					'ignore_sticky_posts' => true,
				]
			);
		}

		$payload = [];

		foreach ( $related_posts as $related_post ) {
			if ( ! ( $related_post instanceof \WP_Post ) ) {
				continue;
			}

			$excerpt = $this->get_intro_based_blog_excerpt( $related_post, self::BLOG_EXCERPT_LENGTH );

			$payload[] = [
				'id'        => (int) $related_post->ID,
				'title'     => get_the_title( $related_post ),
				'url'       => get_permalink( $related_post ),
				'excerpt'   => $excerpt,
				'thumbnail' => get_the_post_thumbnail_url( $related_post, 'quarantined-cpt-card' ),
			];
		}

		return $payload;
	}

	/**
	 * Builds default social/share links for a blog post.
	 *
	 * @param \WP_Post $post Blog post.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_blog_share_links_for_post( \WP_Post $post ): array {
		$permalink = get_permalink( $post );

		if ( ! $permalink ) {
			return [];
		}

		$encoded_url   = rawurlencode( $permalink );
		$encoded_title = rawurlencode( get_the_title( $post ) );

		return [
			[
				'key'   => 'linkedin',
				'label' => __( 'LinkedIn', 'nova-bridge-suite' ),
				'url'   => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $encoded_url,
				'copy'  => false,
			],
			[
				'key'   => 'meta',
				'label' => __( 'Meta', 'nova-bridge-suite' ),
				'url'   => 'https://www.facebook.com/sharer/sharer.php?u=' . $encoded_url,
				'copy'  => false,
			],
			[
				'key'   => 'x',
				'label' => __( 'X', 'nova-bridge-suite' ),
				'url'   => 'https://x.com/intent/tweet?url=' . $encoded_url . '&text=' . $encoded_title,
				'copy'  => false,
			],
			[
				'key'   => 'copy',
				'label' => __( 'Kopieer link', 'nova-bridge-suite' ),
				'url'   => $permalink,
				'copy'  => true,
			],
		];
	}

	/**
	 * Calculates read-time in minutes.
	 *
	 * @param \WP_Post             $post Blog post.
	 * @param array<string,mixed> $meta Blog meta.
	 * @return int
	 */
	private function calculate_blog_read_time( \WP_Post $post, array $meta ): int {
		$text_chunks = [];

		foreach ( [ self::META_BLOG_INTRO, self::META_BLOG_PART_1, self::META_BLOG_PART_2 ] as $key ) {
			$value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';

			if ( $this->blog_html_has_text( $value ) ) {
				$text_chunks[] = wp_strip_all_tags( $value );
			}
		}

		if ( empty( $text_chunks ) ) {
			$text_chunks[] = wp_strip_all_tags( (string) get_post_field( 'post_content', $post, 'raw' ) );
		}

		$word_count = $this->count_words( implode( ' ', $text_chunks ) );

		if ( $word_count <= 0 ) {
			return 1;
		}

		return max( 1, (int) ceil( $word_count / self::BLOG_READ_WPM ) );
	}

	/**
	 * Converts blog HTML to plain text for excerpt/read-time workflows.
	 *
	 * @param string $value HTML content.
	 * @return string
	 */
	private function get_blog_plain_text( string $value ): string {
		$text = wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, get_option( 'blog_charset' ) ) );
		$text = preg_replace( '/\s+/u', ' ', $text );

		return trim( (string) $text );
	}

	/**
	 * Builds a sanitized excerpt based on intro text with fixed character length.
	 *
	 * @param \WP_Post $post Blog post.
	 * @param int      $length Character limit.
	 * @return string
	 */
	private function get_intro_based_blog_excerpt( \WP_Post $post, int $length ): string {
		$length = max( 1, $length );
		$intro  = (string) get_post_meta( (int) $post->ID, self::META_BLOG_INTRO, true );
		$source = $this->get_blog_plain_text( $intro );

		if ( '' === $source ) {
			$fallback = has_excerpt( $post )
				? (string) $post->post_excerpt
				: (string) get_post_field( 'post_content', $post );
			$source   = $this->get_blog_plain_text( $fallback );
		}

		return wp_html_excerpt( $source, $length, '…' );
	}

	/**
	 * Migrates legacy blog posts to enhanced meta fields on first read.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	private function maybe_auto_upgrade_legacy_blog_post( \WP_Post $post ): void {
		if ( ! $this->is_managed_post_type( (string) $post->post_type ) || ! $this->is_legacy_blog_post_internal( $post ) ) {
			return;
		}

		$post_id   = (int) $post->ID;
		$post_type = (string) $post->post_type;
		$changed   = false;

		$current_part_1 = (string) get_post_meta( $post_id, self::META_BLOG_PART_1, true );

		if ( ! $this->blog_html_has_text( $current_part_1 ) ) {
			$legacy_content = $this->enforce_blog_body_heading_policy( $this->prepare_content( $post ), $post_type );

			if ( $this->blog_html_has_text( $legacy_content ) ) {
				$this->persist_blog_meta_value( $post_id, self::META_BLOG_PART_1, $legacy_content );
				$changed = true;
			}
		}

		$current_intro = (string) get_post_meta( $post_id, self::META_BLOG_INTRO, true );

		if ( ! $this->blog_html_has_text( $current_intro ) ) {
			$intro_source = has_excerpt( $post ) ? (string) $post->post_excerpt : '';

			if ( '' !== trim( $intro_source ) ) {
				$this->persist_blog_meta_value( $post_id, self::META_BLOG_INTRO, wpautop( esc_html( $intro_source ) ) );
				$changed = true;
			}
		}

		if ( $changed ) {
			clean_post_cache( $post_id );
		}
	}

	/**
	 * Enforces heading policy for body content fields.
	 *
	 * @param string $html      Rich text HTML.
	 * @param string $post_type Post type context.
	 * @return string
	 */
	private function enforce_blog_body_heading_policy( string $html, string $post_type ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( ! $this->is_component_enabled( 'title', $post_type ) ) {
			return $html;
		}

		return $this->strip_h1_elements_from_blog_html( $html );
	}

	/**
	 * Removes H1 headings from blog body HTML to prevent duplicate page H1 output.
	 *
	 * @param string $html Rich text HTML.
	 * @return string
	 */
	private function strip_h1_elements_from_blog_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( class_exists( '\DOMDocument' ) && class_exists( '\DOMXPath' ) ) {
			$previous_errors = libxml_use_internal_errors( true );
			$dom             = new \DOMDocument( '1.0', 'UTF-8' );
			$encoded_html    = $this->encode_html_for_dom_document( $html );
			$options         = 0;

			if ( defined( 'LIBXML_HTML_NOIMPLIED' ) ) {
				$options |= LIBXML_HTML_NOIMPLIED;
			}

			if ( defined( 'LIBXML_HTML_NODEFDTD' ) ) {
				$options |= LIBXML_HTML_NODEFDTD;
			}

			$loaded = $dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $encoded_html . '</div>', $options );

			if ( $loaded ) {
				$xpath   = new \DOMXPath( $dom );
				$h1_nodes = $xpath->query( '//h1' );

					if ( $h1_nodes instanceof \DOMNodeList ) {
						foreach ( $h1_nodes as $heading ) {
							if ( ! ( $heading instanceof \DOMElement ) || ! ( $heading->parentNode instanceof \DOMNode ) ) {
								continue;
							}

							$heading->parentNode->removeChild( $heading );
						}
					}

				$container = $dom->getElementsByTagName( 'div' )->item( 0 );

				if ( $container instanceof \DOMElement ) {
					$rebuilt = '';

					foreach ( $container->childNodes as $node ) {
						$rebuilt .= $dom->saveHTML( $node );
					}

					libxml_clear_errors();
					libxml_use_internal_errors( $previous_errors );

					return wp_kses_post( $rebuilt );
				}
			}

			libxml_clear_errors();
			libxml_use_internal_errors( $previous_errors );
		}

		$cleaned = preg_replace( '/<h1\b[^>]*>.*?<\/h1>/is', '', $html );

		return wp_kses_post( is_string( $cleaned ) ? $cleaned : $html );
	}

	/**
	 * Checks whether a managed post still uses the legacy blog layout.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_legacy_blog_post_internal( \WP_Post $post ): bool {
		if ( ! $this->is_managed_post_type( (string) $post->post_type ) ) {
			return false;
		}

		if ( $this->post_uses_enhanced_blog_layout( (int) $post->ID ) ) {
			return false;
		}

		return '' !== trim( wp_strip_all_tags( (string) $post->post_content ) );
	}

	/**
	 * Checks whether enhanced blog-layout meta is configured on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function post_uses_enhanced_blog_layout( int $post_id ): bool {
		$meta_keys = [
			self::META_BLOG_INTRO,
			self::META_BLOG_KEY_TAKEAWAYS,
			self::META_BLOG_TOC_DISABLE,
			self::META_BLOG_PART_1,
			self::META_BLOG_PART_2,
			self::META_BLOG_FAQS,
			self::META_BLOG_RELATED_POSTS,
			self::META_BLOG_CTA_TITLE,
			self::META_BLOG_CTA_COPY,
			self::META_BLOG_CTA_BUTTON_LABEL,
			self::META_BLOG_CTA_BUTTON_URL,
			self::META_BLOG_CTA_DISABLE,
			self::META_BLOG_CTA_AFTER_RELATED_TITLE,
			self::META_BLOG_CTA_AFTER_RELATED_COPY,
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL,
			self::META_BLOG_CTA_AFTER_RELATED_BUTTON_URL,
			self::META_BLOG_CTA_AFTER_RELATED_DISABLE,
		];

		foreach ( $meta_keys as $meta_key ) {
			if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
				continue;
			}

			$meta_value = get_post_meta( $post_id, $meta_key, true );

			if ( $this->blog_meta_value_has_content( $meta_value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Determines whether a meta value contains meaningful content.
	 *
	 * @param mixed $value Meta value.
	 * @return bool
	 */
	private function blog_meta_value_has_content( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 !== (int) $value;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( wp_strip_all_tags( $value ) );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->blog_meta_value_has_content( $item ) ) {
					return true;
				}
			}

			return false;
		}

		return ! empty( $value );
	}

	/**
	 * Determines whether an HTML fragment has visible text.
	 *
	 * @param string $value HTML content.
	 * @return bool
	 */
	private function blog_html_has_text( string $value ): bool {
		return '' !== $this->get_blog_plain_text( $value );
	}

	/**
	 * Counts words in a unicode-safe way.
	 *
	 * @param string $text Raw text.
	 * @return int
	 */
	private function count_words( string $text ): int {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return 0;
		}

		$matches = [];

		if ( preg_match_all( '/[\p{L}\p{N}\']+/u', $text, $matches ) ) {
			return count( $matches[0] );
		}

		return 0;
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
	 * Applies per-CPT archive posts-per-page overrides to the main archive query.
	 *
	 * @param \WP_Query $query Current query instance.
	 */
	public function apply_archive_posts_per_page( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );

		if ( is_array( $post_type ) ) {
			$post_type = reset( $post_type );
		}

		$post_type = sanitize_key( (string) $post_type );

		if ( '' === $post_type ) {
			$queried = get_queried_object();

			if ( $queried instanceof \WP_Post_Type ) {
				$post_type = sanitize_key( (string) $queried->name );
			}
		}

		if ( '' === $post_type || ! $this->is_managed_post_type( $post_type ) ) {
			return;
		}

		$settings = $this->get_archive_settings_for_type( $post_type );
		$per_page = isset( $settings['posts_per_page'] ) ? max( 1, absint( $settings['posts_per_page'] ) ) : $this->get_archive_default_posts_per_page();

		$query->set( 'posts_per_page', $per_page );
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
					'parents-on',
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

			$relative_path = $this->sanitize_slug_path( implode( '/', array_slice( $segments, 1 ) ) );

			if ( '' === $relative_path ) {
				continue;
			}

			$matched_post = get_page_by_path( $relative_path, OBJECT, $post_type );

			if ( ! ( $matched_post instanceof \WP_Post ) || $post_type !== (string) $matched_post->post_type ) {
				continue;
			}

			unset( $query_vars['pagename'] );
			$query_vars['post_type'] = $post_type;
			$query_vars['p']         = (int) $matched_post->ID;
			$query_vars['name']      = (string) $matched_post->post_name;

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
	 * Returns effective archive layout settings for a CPT archive.
	 *
	 * @param string $post_type Optional CPT key.
	 * @return array<string,mixed>
	 */
	public static function get_archive_layout_data( string $post_type = '' ): array {
		if ( null === self::$instance ) {
			$empty_cta = [
				'active'          => false,
				'disabled'        => false,
				'title'           => '',
				'copy'            => '',
				'button_label'    => '',
				'button_url'      => '',
				'global_fallback' => [
					'title'        => '',
					'copy'         => '',
					'button_label' => '',
					'button_url'   => '',
				],
			];

			return [
				'intro'             => '',
				'posts_per_page'    => self::DEFAULT_ARCHIVE_POSTS_PER_PAGE,
				'cta_before'        => $empty_cta,
				'cta_after'         => $empty_cta,
				'cta'               => $empty_cta,
				'content_after_cta' => '',
			];
		}

		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			$query_var = get_query_var( 'post_type', '' );

			if ( is_array( $query_var ) ) {
				$query_var = reset( $query_var );
			}

			if ( is_string( $query_var ) && '' !== $query_var ) {
				$post_type = sanitize_key( $query_var );
			}
		}

		if ( '' === $post_type ) {
			$queried = get_queried_object();

			if ( $queried instanceof \WP_Post_Type ) {
				$post_type = sanitize_key( (string) $queried->name );
			}
		}

		if ( '' !== $post_type && ! self::$instance->is_managed_post_type( $post_type ) ) {
			$post_type = '';
		}

		return self::$instance->get_archive_settings_for_type( $post_type );
	}

	/**
	 * Renders archive pagination links with rel prev/next attributes.
	 *
	 * @param array<string,mixed> $args Optional pagination arguments.
	 * @return string
	 */
	public static function archive_pagination( array $args = [] ): string {
		if ( null === self::$instance ) {
			return '';
		}

		return self::$instance->render_archive_pagination_markup( $args );
	}

	/**
	 * Returns the assembled blog layout payload for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_blog_layout_data( int $post_id ): array {
		if ( null === self::$instance ) {
			return [];
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof \WP_Post ) || ! self::$instance->is_managed_post_type( (string) $post->post_type ) ) {
			return [];
		}

		return self::$instance->build_blog_layout_data( $post );
	}

	/**
	 * Determines whether a post should render using the legacy single-blog layout.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_legacy_blog_post( int $post_id ): bool {
		if ( null === self::$instance || $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! ( $post instanceof \WP_Post ) ) {
			return false;
		}

		self::$instance->maybe_auto_upgrade_legacy_blog_post( $post );

		return self::$instance->is_legacy_blog_post_internal( $post );
	}

	/**
	 * Returns the estimated read-time for a blog post.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return int
	 */
	public static function get_estimated_read_time( $post = null ): int {
		if ( null === self::$instance ) {
			return 1;
		}

		$post = get_post( $post );

		if ( ! ( $post instanceof \WP_Post ) || ! self::$instance->is_managed_post_type( (string) $post->post_type ) ) {
			return 1;
		}

		$layout = self::$instance->build_blog_layout_data( $post );

		return max( 1, absint( $layout['read_time_minutes'] ?? 1 ) );
	}

	/**
	 * Returns default social-share links for a blog post.
	 *
	 * @param int|\WP_Post|null $post Optional post object or ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_blog_share_links( $post = null ): array {
		if ( null === self::$instance ) {
			return [];
		}

		$post = get_post( $post );

		if ( ! ( $post instanceof \WP_Post ) || ! self::$instance->is_managed_post_type( (string) $post->post_type ) ) {
			return [];
		}

		return self::$instance->get_blog_share_links_for_post( $post );
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

		$intro  = (string) get_post_meta( (int) $post->ID, self::META_BLOG_INTRO, true );
		$source = $this->get_blog_plain_text( $intro );

		if ( '' === $source ) {
			$fallback = has_excerpt( $post )
				? (string) $post->post_excerpt
				: (string) get_post_field( 'post_content', $post );
			$source   = $this->get_blog_plain_text( $fallback );
		}

		$excerpt = wp_html_excerpt( $source, $length, '…' );

		$excerpt = apply_filters( 'quarantined_cpt/summary_text', $excerpt, $post, $length );

		return $excerpt;
	}

	/**
	 * Builds archive pagination with rel prev/next attributes.
	 *
	 * @param array<string,mixed> $args Optional pagination arguments.
	 * @return string
	 */
	private function render_archive_pagination_markup( array $args = [] ): string {
		global $wp_query;

		$query = isset( $args['query'] ) && $args['query'] instanceof \WP_Query ? $args['query'] : $wp_query;

		if ( ! ( $query instanceof \WP_Query ) ) {
			return '';
		}

		$total_pages = max( 1, absint( $query->max_num_pages ) );

		if ( $total_pages < 2 ) {
			return '';
		}

		$current_page = max(
			1,
			absint( get_query_var( 'paged' ) ),
			absint( get_query_var( 'page' ) )
		);

		$base = str_replace(
			'999999999',
			'%#%',
			esc_url( get_pagenum_link( 999999999 ) )
		);

		$links = paginate_links(
			[
				'base'      => $base,
				'format'    => '?paged=%#%',
				'current'   => $current_page,
				'total'     => $total_pages,
				'mid_size'  => isset( $args['mid_size'] ) ? max( 0, absint( $args['mid_size'] ) ) : 2,
				'prev_text' => isset( $args['prev_text'] ) ? wp_kses_post( (string) $args['prev_text'] ) : esc_html__( 'Vorige', 'nova-bridge-suite' ),
				'next_text' => isset( $args['next_text'] ) ? wp_kses_post( (string) $args['next_text'] ) : esc_html__( 'Volgende', 'nova-bridge-suite' ),
				'type'      => 'array',
			]
		);

		if ( ! is_array( $links ) || empty( $links ) ) {
			return '';
		}

		$rendered_links = [];

		foreach ( $links as $link_html ) {
			$link_html = trim( (string) $link_html );

			if ( '' === $link_html ) {
				continue;
			}

			if ( false !== strpos( $link_html, 'prev page-numbers' ) ) {
				$link_html = $this->inject_rel_attribute_into_link( $link_html, 'prev' );
			} elseif ( false !== strpos( $link_html, 'next page-numbers' ) ) {
				$link_html = $this->inject_rel_attribute_into_link( $link_html, 'next' );
			}

			$rendered_links[] = wp_kses(
				$link_html,
				[
					'a'    => [
						'class'        => [],
						'href'         => [],
						'aria-current' => [],
						'aria-label'   => [],
						'title'        => [],
						'rel'          => [],
					],
					'span' => [
						'class'        => [],
						'aria-current' => [],
					],
				]
			);
		}

		if ( empty( $rendered_links ) ) {
			return '';
		}

		return sprintf(
			'<nav class="navigation pagination quarantined-cpt__pagination" aria-label="%1$s"><div class="nav-links">%2$s</div></nav>',
			esc_attr__( 'Archive pagination', 'nova-bridge-suite' ),
			implode( "\n", $rendered_links )
		);
	}

	/**
	 * Injects rel attributes into a pagination anchor if missing.
	 *
	 * @param string $link_html Link HTML.
	 * @param string $rel       Link relation.
	 * @return string
	 */
	private function inject_rel_attribute_into_link( string $link_html, string $rel ): string {
		if ( false === stripos( $link_html, '<a ' ) ) {
			return $link_html;
		}

		if ( 1 === preg_match( '/\srel\s*=/i', $link_html ) ) {
			return $link_html;
		}

		$rel = in_array( $rel, [ 'prev', 'next' ], true ) ? $rel : '';

		if ( '' === $rel ) {
			return $link_html;
		}

		$updated = preg_replace( '/<a\s+/i', '<a rel="' . $rel . '" ', $link_html, 1 );

		return is_string( $updated ) && '' !== $updated ? $updated : $link_html;
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

		if ( $post_type && ! empty( $post_type->hierarchical ) ) {
			$ancestor_ids = array_reverse( get_post_ancestors( $post ) );

			foreach ( $ancestor_ids as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );

				if ( ! ( $ancestor instanceof \WP_Post ) || $ancestor->post_type !== $post->post_type ) {
					continue;
				}

				$ancestor_title = get_the_title( $ancestor );

				if ( '' === trim( $ancestor_title ) ) {
					continue;
				}

				$crumbs[] = [
					'label' => $ancestor_title,
					'url'   => get_permalink( $ancestor ) ?: '',
				];
			}
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
			self::OPTION_COMPONENT_ORDER,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_component_order_settings' ],
				'default'           => $this->get_component_order_defaults(),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_COMPONENT_VISIBILITY_BY_CPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_component_settings_by_cpt' ],
				'default'           => [],
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_COMPONENT_ORDER_BY_CPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_component_order_settings_by_cpt' ],
				'default'           => [],
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
			self::OPTION_BLOG_STYLE_PRESET,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_style_preset_option' ],
				'default'           => self::DEFAULT_BLOG_STYLE_PRESET,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CONTENT_MAX_WIDTH,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_content_max_width_option' ],
				'default'           => self::DEFAULT_BLOG_CONTENT_MAX_WIDTH,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_TEXT_COLOR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_LINK_COLOR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_LINK_HOVER_COLOR,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_PANEL_BG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_PANEL_BORDER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_META_BORDER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_SHARE_BG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_SHARE_BORDER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_BG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_BUTTON_BG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_BUTTON_TEXT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_BUTTON_HOVER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_PRIMARY_TITLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_PRIMARY_COPY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_rich_text' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_PRIMARY_BUTTON_LABEL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_PRIMARY_BUTTON_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_blog_cta_url' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_AFTER_RELATED_TITLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_AFTER_RELATED_COPY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_rich_text' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_URL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_blog_cta_url' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CTA_BY_CPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_blog_cta_defaults_by_cpt' ],
				'default'           => [],
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_ARCHIVE_BY_CPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_archive_settings_by_cpt' ],
				'default'           => [],
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_STYLE_BY_CPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_blog_style_overrides_by_cpt' ],
				'default'           => [],
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_AUTHOR_BOX_BG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_AUTHOR_BOX_BORDER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_color_option' ],
				'default'           => '',
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_BLOG_CARD_RADIUS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_blog_card_radius_option' ],
				'default'           => '',
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
			self::OPTION_LABEL_LANGUAGE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_label_language_option' ],
				'default'           => self::DEFAULT_LABEL_LANGUAGE,
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_KEY_TAKEAWAYS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Key takeaways', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_TOC,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Table of contents', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_TOC_READ_MORE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Show more...', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_TOC_READ_LESS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Show less...', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_RELATED_ARTICLES,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Related articles', 'nova-bridge-suite' ),
			]
		);

		register_setting(
			'quarantined_cpt_bodyclean',
			self::OPTION_LABEL_FAQ_TITLE,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_text_option' ],
				'default'           => __( 'Frequently asked questions', 'nova-bridge-suite' ),
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

		\Nova_Bridge_Suite_WPML_Support::register_localized_settings(
			'quarantined_cpt_bodyclean',
			self::LOCALIZED_SETTINGS_OPTIONS
		);
	}

	/**
	 * Enqueues admin assets for author profile fields and blog post editor UI.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$is_settings_page = false !== strpos( $hook, 'quarantined-cpt-bodyclean' );

		if ( $is_settings_page ) {
			$settings_style_path = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-settings.css';
			$settings_style_url  = plugins_url( 'assets/quarantined-cpt-settings.css', __FILE__ );
			$settings_style_ver  = file_exists( $settings_style_path ) ? (string) filemtime( $settings_style_path ) : null;
			$script_path         = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-admin.js';
			$script_url          = plugins_url( 'assets/quarantined-cpt-admin.js', __FILE__ );
			$version             = file_exists( $script_path ) ? (string) filemtime( $script_path ) : null;

			wp_enqueue_style(
				'quarantined-cpt-settings',
				$settings_style_url,
				[],
				$settings_style_ver
			);

			wp_enqueue_script(
				'quarantined-cpt-admin',
				$script_url,
				[ 'jquery', 'jquery-ui-sortable' ],
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
					'settings'    => [
						'removeNoPosts'   => __( 'Remove CPT "%1$s"? This only removes the CPT registration from this plugin.', 'nova-bridge-suite' ),
						'removeWithPosts' => __( 'Remove CPT "%1$s"? This CPT currently has %2$d active posts. They will not be deleted, but they can become inaccessible until the CPT is registered again.', 'nova-bridge-suite' ),
						'removeFallback'  => __( 'this CPT', 'nova-bridge-suite' ),
						'pickColor'       => __( 'Pick color', 'nova-bridge-suite' ),
						'pickFromScreen'  => __( 'Pick from screen', 'nova-bridge-suite' ),
					],
				]
			);

			return;
		}

		if ( 'profile.php' === $hook || 'user-edit.php' === $hook ) {
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

			return;
		}

		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! $this->is_managed_post_type( (string) $screen->post_type ) ) {
			return;
		}

		$style_path = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-blog-editor.css';
		$style_url  = plugins_url( 'assets/quarantined-cpt-blog-editor.css', __FILE__ );
		$style_ver  = file_exists( $style_path ) ? (string) filemtime( $style_path ) : null;

		$script_path = plugin_dir_path( __FILE__ ) . 'assets/quarantined-cpt-blog-editor.js';
		$script_url  = plugins_url( 'assets/quarantined-cpt-blog-editor.js', __FILE__ );
		$script_ver  = file_exists( $script_path ) ? (string) filemtime( $script_path ) : null;

		wp_enqueue_style(
			'quarantined-cpt-blog-editor',
			$style_url,
			[],
			$style_ver
		);

		wp_enqueue_script(
			'quarantined-cpt-blog-editor',
			$script_url,
			[ 'jquery' ],
			$script_ver,
			true
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

		$settings_language_context = \Nova_Bridge_Suite_WPML_Support::get_settings_language_context();
		$settings_language_links   = \Nova_Bridge_Suite_WPML_Support::get_settings_language_links(
			admin_url( 'options-general.php?page=quarantined-cpt-bodyclean' )
		);
		$settings_form_action      = \Nova_Bridge_Suite_WPML_Support::get_settings_form_action_url();
		$settings_form_language    = (string) ( $settings_language_context['current'] ?? '' );
		$settings_form_default_language = (string) ( $settings_language_context['default'] ?? '' );

		$raw = get_option( self::OPTION_EXCLUDE_SELECTORS, '' );
		$value = is_array( $raw ) ? implode( "\n", $raw ) : (string) $raw;
		$value = trim( $value );

		$component_order_labels = $this->get_component_order_labels();
		$cta_defaults_by_cpt    = $this->get_blog_cta_overrides_by_cpt();
		$archive_settings_by_cpt = $this->get_archive_settings_overrides_by_cpt();
		$author_pages                = $this->author_archive_enabled();
		$enable_cpts         = $this->cpt_registration_enabled();
		$cpt_definitions     = $this->get_cpt_definitions( false );
		$cpt_post_counts      = [];

		foreach ( $cpt_definitions as $definition ) {
			$slug = sanitize_key( (string) ( $definition['slug'] ?? $definition['type'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$counts = wp_count_posts( $slug );
			$total  = 0;

			if ( $counts instanceof \stdClass ) {
				foreach ( get_object_vars( $counts ) as $status => $count ) {
					if ( in_array( (string) $status, [ 'trash', 'auto-draft', 'inherit' ], true ) ) {
						continue;
					}

					$total += max( 0, (int) $count );
				}
			}

			$cpt_post_counts[ $slug ] = $total;
		}
		$author_base         = $this->get_author_base();
		$author_archive_name = $this->get_author_archive_title();
		$separator_choice    = $this->get_breadcrumb_separator_choice();
		$separator_options   = $this->get_breadcrumb_separator_options();
		$label_author_override        = $this->sanitize_text_option( get_option( self::OPTION_LABEL_AUTHOR, '' ) );
		$label_takeaways_override     = $this->sanitize_text_option( get_option( self::OPTION_LABEL_KEY_TAKEAWAYS, '' ) );
		$label_toc_override           = $this->sanitize_text_option( get_option( self::OPTION_LABEL_TOC, '' ) );
		$label_toc_read_more_override = $this->sanitize_text_option( get_option( self::OPTION_LABEL_TOC_READ_MORE, '' ) );
		$label_toc_read_less_override = $this->sanitize_text_option( get_option( self::OPTION_LABEL_TOC_READ_LESS, '' ) );
		$label_related_override       = $this->sanitize_text_option( get_option( self::OPTION_LABEL_RELATED_ARTICLES, '' ) );
		$label_faq_override           = $this->sanitize_text_option( get_option( self::OPTION_LABEL_FAQ_TITLE, '' ) );
		$label_publications_override  = $this->sanitize_text_option( get_option( self::OPTION_LABEL_PUBLICATIONS, '' ) );
		$label_language               = $this->get_selected_label_language();
		$label_languages              = $this->get_label_language_options();
		$header_offset       = $this->get_header_offset_setting();
		$blog_style_preset   = $this->get_blog_style_preset();
		$blog_style_presets  = $this->get_blog_style_preset_options();
		$blog_content_width  = $this->get_blog_content_max_width_setting();
		$blog_text_color     = $this->get_blog_color_option_value( self::OPTION_BLOG_TEXT_COLOR );
		$blog_link_color     = $this->get_blog_color_option_value( self::OPTION_BLOG_LINK_COLOR );
		$blog_link_hover     = $this->get_blog_color_option_value( self::OPTION_BLOG_LINK_HOVER_COLOR );
		$blog_panel_bg       = $this->get_blog_color_option_value( self::OPTION_BLOG_PANEL_BG );
		$blog_panel_border   = $this->get_blog_color_option_value( self::OPTION_BLOG_PANEL_BORDER );
		$blog_meta_border    = $this->get_blog_color_option_value( self::OPTION_BLOG_META_BORDER );
		$blog_share_bg       = $this->get_blog_color_option_value( self::OPTION_BLOG_SHARE_BG );
		$blog_share_border   = $this->get_blog_color_option_value( self::OPTION_BLOG_SHARE_BORDER );
		$blog_cta_bg         = $this->get_blog_color_option_value( self::OPTION_BLOG_CTA_BG );
		$blog_cta_button_bg  = $this->get_blog_color_option_value( self::OPTION_BLOG_CTA_BUTTON_BG );
		$blog_cta_button_txt = $this->get_blog_color_option_value( self::OPTION_BLOG_CTA_BUTTON_TEXT );
		$blog_cta_button_hover = $this->get_blog_color_option_value( self::OPTION_BLOG_CTA_BUTTON_HOVER );
		$primary_cta_global  = $this->get_blog_global_cta_defaults( 'primary' );
		$after_cta_global    = $this->get_blog_global_cta_defaults( 'after_related' );
		$archive_default_posts_per_page = $this->get_archive_default_posts_per_page();
		$blog_author_bg      = $this->get_blog_color_option_value( self::OPTION_BLOG_AUTHOR_BOX_BG );
		$blog_author_border  = $this->get_blog_color_option_value( self::OPTION_BLOG_AUTHOR_BOX_BORDER );
		$blog_card_radius    = $this->get_blog_card_radius_setting();
		$style_overrides_by_cpt = $this->get_blog_style_overrides_by_cpt();
		$schema_choices      = $this->get_article_schema_choices();

		?>
		<div class="wrap">
		<h1><?php esc_html_e( 'NOVA Blog Settings', 'nova-bridge-suite' ); ?></h1>
		<?php if ( ! empty( $settings_language_context ) ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: 1: multilingual plugin name, 2: current language code, 3: default language code. */
						esc_html__( '%1$s is active. Label, CTA, and archive-content settings on this screen save for the current language (%2$s) and fall back to the default language (%3$s) until overridden. CPT slugs, registration toggles, definitions, styling, and layout controls stay global and only save on the default language tab.', 'nova-bridge-suite' ),
						esc_html( (string) ( $settings_language_context['provider'] ?? 'Multilingual' ) ),
						esc_html( strtoupper( (string) ( $settings_language_context['current'] ?? '' ) ) ),
						esc_html( strtoupper( (string) ( $settings_language_context['default'] ?? '' ) ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>
		<?php if ( ! empty( $settings_language_links ) ) : ?>
			<h2 class="nav-tab-wrapper" style="margin-bottom:12px;">
				<?php foreach ( $settings_language_links as $language_link ) : ?>
					<?php
					$classes = [ 'nav-tab' ];

					if ( ! empty( $language_link['is_current'] ) ) {
						$classes[] = 'nav-tab-active';
					}
					?>
					<a class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" href="<?php echo esc_url( (string) $language_link['url'] ); ?>">
						<?php
						echo esc_html( (string) $language_link['label'] );

						if ( ! empty( $language_link['is_default'] ) ) {
							echo ' ';
							esc_html_e( '(Default)', 'nova-bridge-suite' );
						}
						?>
					</a>
				<?php endforeach; ?>
			</h2>
			<p class="description" style="margin-bottom:16px;">
				<?php esc_html_e( 'Choose the language you want to edit before saving translatable settings on this screen.', 'nova-bridge-suite' ); ?>
			</p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Configure the NOVA Blog CPT layouts, author archives, and isolation rules for this site.', 'nova-bridge-suite' ); ?></p>
			<form action="<?php echo esc_url( $settings_form_action ); ?>" method="post">
				<?php
				settings_fields( 'quarantined_cpt_bodyclean' );
				?>
				<?php if ( '' !== $settings_form_language ) : ?>
					<input type="hidden" name="lang" value="<?php echo esc_attr( $settings_form_language ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $settings_form_default_language ) : ?>
					<input type="hidden" name="nova_bridge_suite_default_lang" value="<?php echo esc_attr( $settings_form_default_language ); ?>" />
				<?php endif; ?>
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
								<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-author-archive-title" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_AUTHOR_ARCHIVE_TITLE ) ); ?>" value="<?php echo esc_attr( $author_archive_name ); ?>" />
								<p class="description"><?php esc_html_e( 'Sets the heading and breadcrumb label for the author archive page.', 'nova-bridge-suite' ); ?></p>
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
				</table>

					<?php if ( $enable_cpts ) : ?>
					<div class="quarantined-cpt-settings">

					<details id="quarantined-cpt-definitions-dropdown" class="quarantined-cpt-settings__dropdown" open>
						<summary><?php esc_html_e( 'Post type definitions', 'nova-bridge-suite' ); ?></summary>
						<p class="description"><?php esc_html_e( 'Use Add CPT to create rows. Removing a row only removes registration from this plugin; posts are not deleted.', 'nova-bridge-suite' ); ?></p>
						<table class="widefat striped quarantined-cpt-definitions-table" data-cpt-definitions-table data-next-index="<?php echo esc_attr( (string) count( $cpt_definitions ) ); ?>">
							<thead>
								<tr>
									<th class="quarantined-cpt-definitions-table__heading"><?php esc_html_e( 'CPT slug', 'nova-bridge-suite' ); ?></th>
									<th><?php esc_html_e( 'Singular label', 'nova-bridge-suite' ); ?></th>
									<th><?php esc_html_e( 'Plural label', 'nova-bridge-suite' ); ?></th>
									<th><?php esc_html_e( 'Schema type', 'nova-bridge-suite' ); ?></th>
									<th><?php esc_html_e( 'Active posts', 'nova-bridge-suite' ); ?></th>
									<th><?php esc_html_e( 'Actions', 'nova-bridge-suite' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cpt_definitions as $index => $definition ) : ?>
									<?php
									$definition_slug       = sanitize_key( (string) ( $definition['slug'] ?? $definition['type'] ?? '' ) );
									$definition_post_count = (int) ( $cpt_post_counts[ $definition_slug ] ?? 0 );
									?>
									<tr data-cpt-row data-post-count="<?php echo esc_attr( (string) $definition_post_count ); ?>">
										<td>
											<input
												type="text"
												name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][slug]"
												value="<?php echo esc_attr( $definition_slug ); ?>"
												placeholder="blog"
											/>
										</td>
										<td>
											<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][singular]" value="<?php echo esc_attr( $definition['singular'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Blog Page', 'nova-bridge-suite' ); ?>" />
										</td>
										<td>
											<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][plural]" value="<?php echo esc_attr( $definition['plural'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Blog Pages', 'nova-bridge-suite' ); ?>" />
										</td>
										<td>
											<select name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[<?php echo esc_attr( $index ); ?>][schema_type]">
												<?php foreach ( $schema_choices as $schema_slug => $schema_label ) : ?>
													<option value="<?php echo esc_attr( $schema_slug ); ?>" <?php selected( $definition['schema_type'] ?? '', $schema_slug ); ?>>
														<?php echo esc_html( $schema_label ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td class="quarantined-cpt-definitions-table__count">
											<?php echo esc_html( number_format_i18n( $definition_post_count ) ); ?>
										</td>
										<td>
											<button
												type="button"
												class="button button-secondary"
												data-remove-cpt-row
											>
												<?php esc_html_e( 'Remove', 'nova-bridge-suite' ); ?>
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p class="quarantined-cpt-settings__add-row">
							<button type="button" class="button button-secondary" id="quarantined-cpt-add-row"><?php esc_html_e( 'Add CPT', 'nova-bridge-suite' ); ?></button>
						</p>
						<template id="quarantined-cpt-row-template">
							<tr data-cpt-row data-post-count="0">
								<td>
									<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[__index__][slug]" value="" placeholder="blog" />
								</td>
								<td>
									<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[__index__][singular]" value="" placeholder="<?php esc_attr_e( 'Blog Page', 'nova-bridge-suite' ); ?>" />
								</td>
								<td>
									<input type="text" name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[__index__][plural]" value="" placeholder="<?php esc_attr_e( 'Blog Pages', 'nova-bridge-suite' ); ?>" />
								</td>
								<td>
									<select name="<?php echo esc_attr( self::OPTION_CPTS ); ?>[__index__][schema_type]">
										<?php foreach ( $schema_choices as $schema_slug => $schema_label ) : ?>
											<option value="<?php echo esc_attr( $schema_slug ); ?>" <?php selected( self::DEFAULT_ARTICLE_SCHEMA_TYPE, $schema_slug ); ?>>
												<?php echo esc_html( $schema_label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td class="quarantined-cpt-definitions-table__count"><?php echo esc_html( number_format_i18n( 0 ) ); ?></td>
								<td>
									<button type="button" class="button button-secondary" data-remove-cpt-row><?php esc_html_e( 'Remove', 'nova-bridge-suite' ); ?></button>
								</td>
							</tr>
						</template>
					</details>

					<details id="quarantined-cpt-language-dropdown" class="quarantined-cpt-settings__dropdown">
						<summary><?php esc_html_e( 'Language', 'nova-bridge-suite' ); ?></summary>
						<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
							<tr>
								<th scope="row"><label for="quarantined-cpt-language"><?php esc_html_e( 'Base language', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<select id="quarantined-cpt-language" name="<?php echo esc_attr( self::OPTION_LABEL_LANGUAGE ); ?>">
										<?php foreach ( $label_languages as $language_key => $language_label ) : ?>
											<option value="<?php echo esc_attr( $language_key ); ?>" <?php selected( $label_language, $language_key ); ?>><?php echo esc_html( $language_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Select a language for common labels (author, key takeaways, table of contents, show more/less, related articles, frequently asked questions).', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
						</table>

						<details class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
							<summary><?php esc_html_e( 'Language Overrides (optional)', 'nova-bridge-suite' ); ?></summary>
							<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-author"><?php esc_html_e( 'Author label', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-author" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_AUTHOR ) ); ?>" value="<?php echo esc_attr( $label_author_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Text shown before the author name on single CPT pages.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-publications"><?php esc_html_e( 'Publications label', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-publications" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_PUBLICATIONS ) ); ?>" value="<?php echo esc_attr( $label_publications_override ); ?>" />
										<p class="description">
											<?php
											/* translators: 1: publication count placeholder, 2: example localized string. */
											esc_html_e( 'Use %1$s where the publication count should appear (for example: "%2$s Veröffentlichungen").', 'nova-bridge-suite' );
											?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-key-takeaways"><?php esc_html_e( 'Key takeaways heading', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-key-takeaways" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_KEY_TAKEAWAYS ) ); ?>" value="<?php echo esc_attr( $label_takeaways_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Heading label used for the key takeaways panel on single blog pages.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-toc"><?php esc_html_e( 'Table of contents heading', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-toc" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_TOC ) ); ?>" value="<?php echo esc_attr( $label_toc_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Heading label used for the table of contents panel on single blog pages.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-toc-read-more"><?php esc_html_e( 'TOC read-more label', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-toc-read-more" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_TOC_READ_MORE ) ); ?>" value="<?php echo esc_attr( $label_toc_read_more_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Label for the text link shown when the table of contents has more than 5 items. Clicking it expands the list in place.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-toc-read-less"><?php esc_html_e( 'TOC show-less label', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-toc-read-less" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_TOC_READ_LESS ) ); ?>" value="<?php echo esc_attr( $label_toc_read_less_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Label shown after expansion so visitors can collapse the table of contents back to 5 items.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-related"><?php esc_html_e( 'Related articles heading', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-related" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_RELATED_ARTICLES ) ); ?>" value="<?php echo esc_attr( $label_related_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Heading label used for the related articles section on single blog pages.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="quarantined-cpt-bodyclean-label-faq"><?php esc_html_e( 'FAQ heading', 'nova-bridge-suite' ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-label-faq" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_LABEL_FAQ_TITLE ) ); ?>" value="<?php echo esc_attr( $label_faq_override ); ?>" />
										<p class="description"><?php esc_html_e( 'Heading label used for the frequently asked questions section on single blog pages.', 'nova-bridge-suite' ); ?></p>
									</td>
								</tr>
							</table>
						</details>
					</details>

					<details id="quarantined-cpt-blog-design-dropdown" class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
						<summary><?php esc_html_e( 'Blog Design Controls (optional overrides)', 'nova-bridge-suite' ); ?></summary>
						<p><?php esc_html_e( 'All fields in this section are optional overrides. Leave any field empty to keep the selected style preset defaults. Theme Native attempts to follow your active theme styling, while Balanced/Enhanced use plugin-provided defaults.', 'nova-bridge-suite' ); ?></p>
						<p class="description"><?php esc_html_e( 'Use the color swatch to pick a color quickly, or the pencil button to sample from screen when your browser supports it. Manual values (hex, rgb, rgba) are still supported.', 'nova-bridge-suite' ); ?></p>
						<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
							<tr>
								<th scope="row"><label for="quarantined-cpt-style-preset"><?php esc_html_e( 'Style preset', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<select id="quarantined-cpt-style-preset" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_PRESET ); ?>">
										<?php foreach ( $blog_style_presets as $preset_slug => $preset_label ) : ?>
											<option value="<?php echo esc_attr( $preset_slug ); ?>" <?php selected( $blog_style_preset, $preset_slug ); ?>><?php echo esc_html( $preset_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Theme Native keeps styling close to your active website design, but keeps the enhanced wide-CTA background by default for stronger CTA emphasis. Balanced/Enhanced apply broader plugin styling.', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-content-width"><?php esc_html_e( 'Article max width', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="quarantined-cpt-content-width" name="<?php echo esc_attr( self::OPTION_BLOG_CONTENT_MAX_WIDTH ); ?>" value="<?php echo esc_attr( $blog_content_width ); ?>" />
									<p class="description"><?php esc_html_e( 'Examples: 880px, 72rem, min(92vw, 980px).', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-text-color"><?php esc_html_e( 'Body text color', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-text-color" name="<?php echo esc_attr( self::OPTION_BLOG_TEXT_COLOR ); ?>" value="<?php echo esc_attr( $blog_text_color ); ?>" placeholder="<?php esc_attr_e( 'Theme default', 'nova-bridge-suite' ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-link-color"><?php esc_html_e( 'Internal link color', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-link-color" name="<?php echo esc_attr( self::OPTION_BLOG_LINK_COLOR ); ?>" value="<?php echo esc_attr( $blog_link_color ); ?>" placeholder="<?php esc_attr_e( 'Theme default', 'nova-bridge-suite' ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-link-hover"><?php esc_html_e( 'Internal link hover color', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-link-hover" name="<?php echo esc_attr( self::OPTION_BLOG_LINK_HOVER_COLOR ); ?>" value="<?php echo esc_attr( $blog_link_hover ); ?>" placeholder="<?php esc_attr_e( 'Same as link color', 'nova-bridge-suite' ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-panel-bg"><?php esc_html_e( 'Panel background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-panel-bg" name="<?php echo esc_attr( self::OPTION_BLOG_PANEL_BG ); ?>" value="<?php echo esc_attr( $blog_panel_bg ); ?>" placeholder="#f6f7f9" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-panel-border"><?php esc_html_e( 'Panel border color', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-panel-border" name="<?php echo esc_attr( self::OPTION_BLOG_PANEL_BORDER ); ?>" value="<?php echo esc_attr( $blog_panel_border ); ?>" placeholder="rgba(15,23,42,0.12)" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-meta-border"><?php esc_html_e( 'Meta divider color', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-meta-border" name="<?php echo esc_attr( self::OPTION_BLOG_META_BORDER ); ?>" value="<?php echo esc_attr( $blog_meta_border ); ?>" placeholder="rgba(15,23,42,0.12)" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-share-bg"><?php esc_html_e( 'Share chip background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-share-bg" name="<?php echo esc_attr( self::OPTION_BLOG_SHARE_BG ); ?>" value="<?php echo esc_attr( $blog_share_bg ); ?>" placeholder="#ffffff" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-share-border"><?php esc_html_e( 'Share chip border', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-share-border" name="<?php echo esc_attr( self::OPTION_BLOG_SHARE_BORDER ); ?>" value="<?php echo esc_attr( $blog_share_border ); ?>" placeholder="rgba(15,23,42,0.16)" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-cta-bg"><?php esc_html_e( 'Wide CTA background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-cta-bg" name="<?php echo esc_attr( self::OPTION_BLOG_CTA_BG ); ?>" value="<?php echo esc_attr( $blog_cta_bg ); ?>" placeholder="#e9efff" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-cta-button-bg"><?php esc_html_e( 'CTA button background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-cta-button-bg" name="<?php echo esc_attr( self::OPTION_BLOG_CTA_BUTTON_BG ); ?>" value="<?php echo esc_attr( $blog_cta_button_bg ); ?>" placeholder="#1d4ed8" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-cta-button-text"><?php esc_html_e( 'CTA button text', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-cta-button-text" name="<?php echo esc_attr( self::OPTION_BLOG_CTA_BUTTON_TEXT ); ?>" value="<?php echo esc_attr( $blog_cta_button_txt ); ?>" placeholder="#ffffff" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-cta-button-hover"><?php esc_html_e( 'CTA button hover background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-cta-button-hover" name="<?php echo esc_attr( self::OPTION_BLOG_CTA_BUTTON_HOVER ); ?>" value="<?php echo esc_attr( $blog_cta_button_hover ); ?>" placeholder="<?php esc_attr_e( 'Same as CTA button background', 'nova-bridge-suite' ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-author-bg"><?php esc_html_e( 'Author box background', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-author-bg" name="<?php echo esc_attr( self::OPTION_BLOG_AUTHOR_BOX_BG ); ?>" value="<?php echo esc_attr( $blog_author_bg ); ?>" placeholder="#ffffff" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-author-border"><?php esc_html_e( 'Author box border', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-author-border" name="<?php echo esc_attr( self::OPTION_BLOG_AUTHOR_BOX_BORDER ); ?>" value="<?php echo esc_attr( $blog_author_border ); ?>" placeholder="rgba(15,23,42,0.12)" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-card-radius"><?php esc_html_e( 'Related card radius', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text" id="quarantined-cpt-card-radius" name="<?php echo esc_attr( self::OPTION_BLOG_CARD_RADIUS ); ?>" value="<?php echo esc_attr( $blog_card_radius ); ?>" placeholder="1rem" /></td>
							</tr>
						</table>
					</details>

					<details id="quarantined-cpt-global-cta-dropdown" class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional" open>
						<summary><?php esc_html_e( 'Global CTA Defaults (Primary + Second CTA)', 'nova-bridge-suite' ); ?></summary>
						<p><?php esc_html_e( 'These defaults are used when CTA fields are left empty on a post. Post-level fields override these values, and CPT-specific optional overrides are available in the CPT Components tabs.', 'nova-bridge-suite' ); ?></p>
						<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-primary-title"><?php esc_html_e( 'Primary CTA title', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="quarantined-cpt-global-cta-primary-title" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_PRIMARY_TITLE ) ); ?>" value="<?php echo esc_attr( $primary_cta_global['title'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Default title for the CTA between rich text block 1 and rich text block 2.', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-primary-copy"><?php esc_html_e( 'Primary CTA copy', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="4" id="quarantined-cpt-global-cta-primary-copy" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_PRIMARY_COPY ) ); ?>"><?php echo esc_textarea( $primary_cta_global['copy'] ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-primary-label"><?php esc_html_e( 'Primary CTA button label', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text" id="quarantined-cpt-global-cta-primary-label" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_PRIMARY_BUTTON_LABEL ) ); ?>" value="<?php echo esc_attr( $primary_cta_global['button_label'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-primary-url"><?php esc_html_e( 'Primary CTA button URL', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="quarantined-cpt-global-cta-primary-url" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_PRIMARY_BUTTON_URL ) ); ?>" value="<?php echo esc_attr( $primary_cta_global['button_url'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-after-title"><?php esc_html_e( 'Second CTA title (Optional)', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="quarantined-cpt-global-cta-after-title" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_AFTER_RELATED_TITLE ) ); ?>" value="<?php echo esc_attr( $after_cta_global['title'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Default title for the second CTA shown after the related articles section.', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-after-copy"><?php esc_html_e( 'Second CTA copy (Optional)', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<textarea class="large-text" rows="4" id="quarantined-cpt-global-cta-after-copy" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_AFTER_RELATED_COPY ) ); ?>"><?php echo esc_textarea( $after_cta_global['copy'] ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-after-label"><?php esc_html_e( 'Second CTA button label (Optional)', 'nova-bridge-suite' ); ?></label></th>
								<td><input type="text" class="regular-text" id="quarantined-cpt-global-cta-after-label" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_LABEL ) ); ?>" value="<?php echo esc_attr( $after_cta_global['button_label'] ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="quarantined-cpt-global-cta-after-url"><?php esc_html_e( 'Second CTA button URL (Optional)', 'nova-bridge-suite' ); ?></label></th>
								<td>
									<input type="text" class="regular-text" id="quarantined-cpt-global-cta-after-url" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_AFTER_RELATED_BUTTON_URL ) ); ?>" value="<?php echo esc_attr( $after_cta_global['button_url'] ); ?>" />
									<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
								</td>
							</tr>
						</table>
					</details>

					<details id="quarantined-cpt-components-dropdown" class="quarantined-cpt-settings__dropdown" open>
						<summary><?php esc_html_e( 'CPT Components', 'nova-bridge-suite' ); ?></summary>
						<p class="description"><?php esc_html_e( 'Configure visibility, order, optional CTA overrides, and archive content controls per CPT.', 'nova-bridge-suite' ); ?></p>

						<?php if ( empty( $cpt_definitions ) ) : ?>
							<p class="description"><?php esc_html_e( 'Add at least one CPT in Post type definitions to configure components.', 'nova-bridge-suite' ); ?></p>
						<?php else : ?>
							<div class="quarantined-cpt-tabs" data-cpt-tabs>
								<div class="quarantined-cpt-tabs__nav" role="tablist" aria-label="<?php esc_attr_e( 'CPT tabs', 'nova-bridge-suite' ); ?>">
									<?php $tab_index = 0; ?>
									<?php foreach ( $cpt_definitions as $definition ) : ?>
										<?php
										$tab_slug  = sanitize_key( (string) ( $definition['slug'] ?? $definition['type'] ?? '' ) );
										$tab_label = trim( (string) ( $definition['plural'] ?? '' ) );

										if ( '' === $tab_slug ) {
											continue;
										}

										if ( '' === $tab_label ) {
											$tab_label = strtoupper( $tab_slug );
										}
										?>
										<button
											type="button"
											class="button<?php echo 0 === $tab_index ? ' button-primary' : ''; ?>"
											data-cpt-tab-button
											data-tab-target="<?php echo esc_attr( $tab_slug ); ?>"
											role="tab"
											aria-selected="<?php echo 0 === $tab_index ? 'true' : 'false'; ?>"
										>
											<?php echo esc_html( $tab_label ); ?>
										</button>
										<?php $tab_index++; ?>
									<?php endforeach; ?>
								</div>

								<?php $panel_index = 0; ?>
								<?php foreach ( $cpt_definitions as $definition ) : ?>
									<?php
									$tab_slug  = sanitize_key( (string) ( $definition['slug'] ?? $definition['type'] ?? '' ) );
									$tab_label = trim( (string) ( $definition['plural'] ?? '' ) );

									if ( '' === $tab_slug ) {
										continue;
									}

									if ( '' === $tab_label ) {
										$tab_label = strtoupper( $tab_slug );
									}

									$tab_components = $this->get_component_settings_for_type( $tab_slug );
									$tab_order      = $this->get_component_order_settings_for_type( $tab_slug );
									$tab_order_keys = array_keys( $component_order_labels );
									$tab_cta        = isset( $cta_defaults_by_cpt[ $tab_slug ] ) && is_array( $cta_defaults_by_cpt[ $tab_slug ] ) ? $cta_defaults_by_cpt[ $tab_slug ] : [];
									$tab_primary_cta = isset( $tab_cta['primary'] ) && is_array( $tab_cta['primary'] ) ? $tab_cta['primary'] : $this->get_empty_blog_cta_payload();
									$tab_after_cta   = isset( $tab_cta['after_related'] ) && is_array( $tab_cta['after_related'] ) ? $tab_cta['after_related'] : $this->get_empty_blog_cta_payload();
									$tab_archive     = isset( $archive_settings_by_cpt[ $tab_slug ] ) && is_array( $archive_settings_by_cpt[ $tab_slug ] ) ? $archive_settings_by_cpt[ $tab_slug ] : [];
									$tab_archive_intro = isset( $tab_archive['intro'] ) ? (string) $tab_archive['intro'] : '';
									$tab_archive_before_cta = isset( $tab_archive['cta_before'] ) && is_array( $tab_archive['cta_before'] ) ? $tab_archive['cta_before'] : $this->get_empty_blog_cta_payload();
									$tab_archive_after_cta  = isset( $tab_archive['cta_after'] ) && is_array( $tab_archive['cta_after'] ) ? $tab_archive['cta_after'] : ( isset( $tab_archive['cta'] ) && is_array( $tab_archive['cta'] ) ? $tab_archive['cta'] : $this->get_empty_blog_cta_payload() );
									$tab_archive_before_disabled = array_key_exists( 'cta_before_disabled', $tab_archive )
										? ! empty( $tab_archive['cta_before_disabled'] )
										: true;
									$tab_archive_after_disabled  = array_key_exists( 'cta_after_disabled', $tab_archive )
										? ! empty( $tab_archive['cta_after_disabled'] )
										: false;
									$tab_archive_bottom = isset( $tab_archive['content_after_cta'] ) ? (string) $tab_archive['content_after_cta'] : '';
									$tab_archive_posts_per_page = isset( $tab_archive['posts_per_page'] ) ? (string) absint( $tab_archive['posts_per_page'] ) : '';
									$tab_style       = isset( $style_overrides_by_cpt[ $tab_slug ] ) && is_array( $style_overrides_by_cpt[ $tab_slug ] ) ? $style_overrides_by_cpt[ $tab_slug ] : $this->get_empty_blog_style_override_payload();

									usort(
										$tab_order_keys,
										static function ( string $left_key, string $right_key ) use ( $tab_order, $component_order_labels ): int {
											$left  = (int) ( $tab_order[ $left_key ] ?? 0 );
											$right = (int) ( $tab_order[ $right_key ] ?? 0 );

											if ( $left === $right ) {
												return strcmp(
													(string) ( $component_order_labels[ $left_key ] ?? $left_key ),
													(string) ( $component_order_labels[ $right_key ] ?? $right_key )
												);
											}

											return $left <=> $right;
										}
									);
									?>
									<section
										class="quarantined-cpt-tabs__panel"
										data-cpt-tab-panel="<?php echo esc_attr( $tab_slug ); ?>"
										<?php echo 0 === $panel_index ? '' : 'hidden'; ?>
									>
										<input type="hidden" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][__present]" value="1" />
										<h3><?php echo esc_html( sprintf( __( 'Visibility: %s', 'nova-bridge-suite' ), $tab_label ) ); ?></h3>
										<fieldset class="quarantined-cpt-components-visibility">
											<p><strong><?php esc_html_e( 'Header', 'nova-bridge-suite' ); ?></strong></p>
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][title]" value="1" <?php checked( ! empty( $tab_components['title'] ) ); ?> /> <?php esc_html_e( 'Show title (H1)', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][breadcrumbs]" value="1" <?php checked( ! empty( $tab_components['breadcrumbs'] ) ); ?> /> <?php esc_html_e( 'Show breadcrumbs', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][featured]" value="1" <?php checked( ! empty( $tab_components['featured'] ) ); ?> /> <?php esc_html_e( 'Show featured image', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][author]" value="1" <?php checked( ! empty( $tab_components['author'] ) ); ?> /> <?php esc_html_e( 'Show author mention', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][date]" value="1" <?php checked( ! empty( $tab_components['date'] ) ); ?> /> <?php esc_html_e( 'Show publication date', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][read_time]" value="1" <?php checked( ! empty( $tab_components['read_time'] ) ); ?> /> <?php esc_html_e( 'Show estimated read time', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][share_links]" value="1" <?php checked( ! empty( $tab_components['share_links'] ) ); ?> /> <?php esc_html_e( 'Show social share links', 'nova-bridge-suite' ); ?></label>

											<p class="quarantined-cpt-components-visibility__body"><strong><?php esc_html_e( 'Body Layout', 'nova-bridge-suite' ); ?></strong></p>
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][intro]" value="1" <?php checked( ! empty( $tab_components['intro'] ) ); ?> /> <?php esc_html_e( 'Show intro paragraph', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][key_takeaways]" value="1" <?php checked( ! empty( $tab_components['key_takeaways'] ) ); ?> /> <?php esc_html_e( 'Show key takeaways panel', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][toc]" value="1" <?php checked( ! empty( $tab_components['toc'] ) ); ?> /> <?php esc_html_e( 'Show table of contents panel', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][content_1]" value="1" <?php checked( ! empty( $tab_components['content_1'] ) ); ?> /> <?php esc_html_e( 'Show rich text block 1', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][wide_cta]" value="1" <?php checked( ! empty( $tab_components['wide_cta'] ) ); ?> /> <?php esc_html_e( 'Show primary wide CTA section', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][content_2]" value="1" <?php checked( ! empty( $tab_components['content_2'] ) ); ?> /> <?php esc_html_e( 'Show rich text block 2', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][faq]" value="1" <?php checked( ! empty( $tab_components['faq'] ) ); ?> /> <?php esc_html_e( 'Show FAQ section', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][related]" value="1" <?php checked( ! empty( $tab_components['related'] ) ); ?> /> <?php esc_html_e( 'Show related articles', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][wide_cta_after_related]" value="1" <?php checked( ! empty( $tab_components['wide_cta_after_related'] ) ); ?> /> <?php esc_html_e( 'Show second wide CTA section (after related articles)', 'nova-bridge-suite' ); ?></label><br />
											<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_COMPONENT_VISIBILITY_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][author_box]" value="1" <?php checked( ! empty( $tab_components['author_box'] ) ); ?> /> <?php esc_html_e( 'Show author biography box', 'nova-bridge-suite' ); ?></label>
										</fieldset>

										<h3><?php esc_html_e( 'Component Order', 'nova-bridge-suite' ); ?></h3>
										<p class="description"><?php esc_html_e( 'Drag and drop to reorder components. Top item appears first on the page.', 'nova-bridge-suite' ); ?></p>
										<ol class="quarantined-cpt-component-order" data-component-order-list>
												<?php foreach ( $tab_order_keys as $position => $order_key ) : ?>
													<?php $order_label = (string) ( $component_order_labels[ $order_key ] ?? $order_key ); ?>
													<li class="quarantined-cpt-component-order__item" data-order-key="<?php echo esc_attr( $order_key ); ?>">
														<span class="quarantined-cpt-component-order__handle" aria-hidden="true">
															<span class="dashicons dashicons-menu"></span>
														</span>
														<span class="quarantined-cpt-component-order__label"><?php echo esc_html( $order_label ); ?></span>
													<span class="quarantined-cpt-component-order__position" data-order-position><?php echo esc_html( (string) ( $position + 1 ) ); ?></span>
													<input
														type="hidden"
														name="<?php echo esc_attr( self::OPTION_COMPONENT_ORDER_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][<?php echo esc_attr( $order_key ); ?>]"
														value="<?php echo esc_attr( (string) ( $tab_order[ $order_key ] ?? ( ( $position + 1 ) * 10 ) ) ); ?>"
														data-order-input
													/>
												</li>
											<?php endforeach; ?>
										</ol>

										<details class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
											<summary><?php esc_html_e( 'Color overrides for this specific CPT (Optional)', 'nova-bridge-suite' ); ?></summary>
											<p class="description"><?php esc_html_e( 'Leave empty to inherit the global design controls or your site theme. Use this when one CPT should have a different text, link, or button color treatment than the others.', 'nova-bridge-suite' ); ?></p>
											<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-text-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Body text color', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-text-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][text_color]" value="<?php echo esc_attr( (string) ( $tab_style['text_color'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_text_color ? $blog_text_color : __( 'Theme default', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-link-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Internal link color', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-link-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][link_color]" value="<?php echo esc_attr( (string) ( $tab_style['link_color'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_link_color ? $blog_link_color : __( 'Theme default', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-link-hover-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Internal link hover color', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-link-hover-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][link_hover_color]" value="<?php echo esc_attr( (string) ( $tab_style['link_hover_color'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_link_hover ? $blog_link_hover : __( 'Same as link color', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-button-bg-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA button background', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-button-bg-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_button_background]" value="<?php echo esc_attr( (string) ( $tab_style['cta_button_background'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_cta_button_bg ? $blog_cta_button_bg : __( 'Theme default', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-button-text-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA button text', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-button-text-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_button_text]" value="<?php echo esc_attr( (string) ( $tab_style['cta_button_text'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_cta_button_txt ? $blog_cta_button_txt : __( 'Theme default', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-style-button-hover-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA button hover background', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text quarantined-cpt-color-control__value" id="quarantined-cpt-style-button-hover-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( self::OPTION_BLOG_STYLE_BY_CPT ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_button_hover]" value="<?php echo esc_attr( (string) ( $tab_style['cta_button_hover'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( '' !== $blog_cta_button_hover ? $blog_cta_button_hover : __( 'Same as CTA button background', 'nova-bridge-suite' ) ); ?>" /></td>
												</tr>
											</table>
										</details>

										<details class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
											<summary><?php esc_html_e( 'CTA defaults overwrites for this specific CPT (Optional)', 'nova-bridge-suite' ); ?></summary>
											<p class="description"><?php esc_html_e( 'Leave empty to inherit the global CTA defaults.', 'nova-bridge-suite' ); ?></p>
											<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
											<tr>
												<th scope="row"><label for="quarantined-cpt-primary-title-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Primary CTA title', 'nova-bridge-suite' ); ?></label></th>
												<td><input type="text" class="regular-text" id="quarantined-cpt-primary-title-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][primary][title]" value="<?php echo esc_attr( (string) ( $tab_primary_cta['title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['title'] ); ?>" /></td>
											</tr>
											<tr>
												<th scope="row"><label for="quarantined-cpt-primary-copy-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Primary CTA copy', 'nova-bridge-suite' ); ?></label></th>
												<td><textarea class="large-text" rows="3" id="quarantined-cpt-primary-copy-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][primary][copy]"><?php echo esc_textarea( (string) ( $tab_primary_cta['copy'] ?? '' ) ); ?></textarea></td>
											</tr>
											<tr>
												<th scope="row"><label for="quarantined-cpt-primary-label-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Primary CTA button label', 'nova-bridge-suite' ); ?></label></th>
												<td><input type="text" class="regular-text" id="quarantined-cpt-primary-label-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][primary][button_label]" value="<?php echo esc_attr( (string) ( $tab_primary_cta['button_label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_label'] ); ?>" /></td>
											</tr>
											<tr>
												<th scope="row"><label for="quarantined-cpt-primary-url-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Primary CTA button URL', 'nova-bridge-suite' ); ?></label></th>
												<td><input type="text" class="regular-text" id="quarantined-cpt-primary-url-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][primary][button_url]" value="<?php echo esc_attr( (string) ( $tab_primary_cta['button_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_url'] ); ?>" /></td>
											</tr>
											<tr>
													<th scope="row"><label for="quarantined-cpt-after-title-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Second CTA title (Optional)', 'nova-bridge-suite' ); ?></label></th>
												<td><input type="text" class="regular-text" id="quarantined-cpt-after-title-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][after_related][title]" value="<?php echo esc_attr( (string) ( $tab_after_cta['title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['title'] ); ?>" /></td>
											</tr>
											<tr>
													<th scope="row"><label for="quarantined-cpt-after-copy-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Second CTA copy (Optional)', 'nova-bridge-suite' ); ?></label></th>
												<td><textarea class="large-text" rows="3" id="quarantined-cpt-after-copy-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][after_related][copy]"><?php echo esc_textarea( (string) ( $tab_after_cta['copy'] ?? '' ) ); ?></textarea></td>
											</tr>
											<tr>
													<th scope="row"><label for="quarantined-cpt-after-label-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Second CTA button label (Optional)', 'nova-bridge-suite' ); ?></label></th>
												<td><input type="text" class="regular-text" id="quarantined-cpt-after-label-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][after_related][button_label]" value="<?php echo esc_attr( (string) ( $tab_after_cta['button_label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['button_label'] ); ?>" /></td>
											</tr>
											<tr>
													<th scope="row"><label for="quarantined-cpt-after-url-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Second CTA button URL (Optional)', 'nova-bridge-suite' ); ?></label></th>
												<td>
													<input type="text" class="regular-text" id="quarantined-cpt-after-url-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_BLOG_CTA_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][after_related][button_url]" value="<?php echo esc_attr( (string) ( $tab_after_cta['button_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['button_url'] ); ?>" />
													<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
												</td>
											</tr>
											</table>
										</details>

										<details class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
											<summary><?php esc_html_e( 'Archive Page Content (Optional)', 'nova-bridge-suite' ); ?></summary>
											<p class="description"><?php esc_html_e( 'Optional per-CPT archive elements. Leave CTA override fields empty to inherit global defaults (primary CTA for above-post placement, second CTA for below-post placement).', 'nova-bridge-suite' ); ?></p>
											<table class="form-table quarantined-cpt-settings__nested-table" role="presentation">
												<tr>
													<th scope="row"><label for="quarantined_cpt_archive_intro_<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Archive intro (under H1)', 'nova-bridge-suite' ); ?></label></th>
													<td>
														<?php
														wp_editor(
															$tab_archive_intro,
															'quarantined_cpt_archive_intro_' . $tab_slug,
															[
																'textarea_name' => $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) . '[' . $tab_slug . '][intro]',
																'textarea_rows' => 5,
																'media_buttons' => false,
																'teeny'         => true,
																'quicktags'     => true,
																'tinymce'       => [
																	'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
																	'toolbar2'      => '',
																	'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
																	'resize'        => false,
																],
															]
														);
														?>
													</td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-posts-per-page-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Archive posts per page (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td>
														<input
															type="number"
															class="small-text"
															id="quarantined-cpt-archive-posts-per-page-<?php echo esc_attr( $tab_slug ); ?>"
															name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][posts_per_page]"
															value="<?php echo esc_attr( $tab_archive_posts_per_page ); ?>"
															min="1"
															max="200"
															placeholder="<?php echo esc_attr( (string) $archive_default_posts_per_page ); ?>"
														/>
														<p class="description">
															<?php
															printf(
																/* translators: %d: default archive posts per page. */
																esc_html__( 'Leave empty to use the default %d posts per page.', 'nova-bridge-suite' ),
																$archive_default_posts_per_page
															);
															?>
														</p>
													</td>
												</tr>
												<tr>
													<th scope="row"><?php esc_html_e( 'CTA Above Posts (Optional)', 'nova-bridge-suite' ); ?></th>
													<td>
														<label for="quarantined-cpt-archive-cta-before-disabled-<?php echo esc_attr( $tab_slug ); ?>">
															<input type="hidden" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before_disabled]" value="0" />
															<input type="checkbox" id="quarantined-cpt-archive-cta-before-disabled-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before_disabled]" value="1" <?php checked( $tab_archive_before_disabled ); ?> />
															<?php esc_html_e( 'Disable CTA above posts for this CPT', 'nova-bridge-suite' ); ?>
														</label>
														<p class="description"><?php esc_html_e( 'When checked, this CTA is hidden even if global defaults are configured. Default: disabled.', 'nova-bridge-suite' ); ?></p>
													</td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-before-title-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA above posts title (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text" id="quarantined-cpt-archive-cta-before-title-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before][title]" value="<?php echo esc_attr( (string) ( $tab_archive_before_cta['title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['title'] ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-before-copy-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA above posts copy (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><textarea class="large-text" rows="3" id="quarantined-cpt-archive-cta-before-copy-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before][copy]"><?php echo esc_textarea( (string) ( $tab_archive_before_cta['copy'] ?? '' ) ); ?></textarea></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-before-label-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA above posts button label (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text" id="quarantined-cpt-archive-cta-before-label-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before][button_label]" value="<?php echo esc_attr( (string) ( $tab_archive_before_cta['button_label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_label'] ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-before-url-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA above posts button URL (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td>
														<input type="text" class="regular-text" id="quarantined-cpt-archive-cta-before-url-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_before][button_url]" value="<?php echo esc_attr( (string) ( $tab_archive_before_cta['button_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $primary_cta_global['button_url'] ); ?>" />
														<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
													</td>
												</tr>
												<tr>
													<th scope="row"><?php esc_html_e( 'CTA Below Posts (Optional)', 'nova-bridge-suite' ); ?></th>
													<td>
														<label for="quarantined-cpt-archive-cta-after-disabled-<?php echo esc_attr( $tab_slug ); ?>">
															<input type="hidden" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after_disabled]" value="0" />
															<input type="checkbox" id="quarantined-cpt-archive-cta-after-disabled-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after_disabled]" value="1" <?php checked( $tab_archive_after_disabled ); ?> />
															<?php esc_html_e( 'Disable CTA below posts for this CPT', 'nova-bridge-suite' ); ?>
														</label>
														<p class="description"><?php esc_html_e( 'When checked, this CTA is hidden even if global defaults are configured.', 'nova-bridge-suite' ); ?></p>
													</td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-after-title-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA below posts title (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text" id="quarantined-cpt-archive-cta-after-title-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after][title]" value="<?php echo esc_attr( (string) ( $tab_archive_after_cta['title'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['title'] ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-after-copy-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA below posts copy (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><textarea class="large-text" rows="3" id="quarantined-cpt-archive-cta-after-copy-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after][copy]"><?php echo esc_textarea( (string) ( $tab_archive_after_cta['copy'] ?? '' ) ); ?></textarea></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-after-label-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA below posts button label (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td><input type="text" class="regular-text" id="quarantined-cpt-archive-cta-after-label-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after][button_label]" value="<?php echo esc_attr( (string) ( $tab_archive_after_cta['button_label'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['button_label'] ); ?>" /></td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined-cpt-archive-cta-after-url-<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'CTA below posts button URL (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td>
														<input type="text" class="regular-text" id="quarantined-cpt-archive-cta-after-url-<?php echo esc_attr( $tab_slug ); ?>" name="<?php echo esc_attr( $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) ); ?>[<?php echo esc_attr( $tab_slug ); ?>][cta_after][button_url]" value="<?php echo esc_attr( (string) ( $tab_archive_after_cta['button_url'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) $after_cta_global['button_url'] ); ?>" />
														<p class="description"><?php esc_html_e( 'Supports internal links like /contact and absolute URLs like https://example.com/contact.', 'nova-bridge-suite' ); ?></p>
													</td>
												</tr>
												<tr>
													<th scope="row"><label for="quarantined_cpt_archive_bottom_<?php echo esc_attr( $tab_slug ); ?>"><?php esc_html_e( 'Archive content below CTA (Optional)', 'nova-bridge-suite' ); ?></label></th>
													<td>
														<?php
														wp_editor(
															$tab_archive_bottom,
															'quarantined_cpt_archive_bottom_' . $tab_slug,
															[
																'textarea_name' => $this->get_settings_option_name( self::OPTION_ARCHIVE_BY_CPT ) . '[' . $tab_slug . '][content_after_cta]',
																'textarea_rows' => 5,
																'media_buttons' => false,
																'teeny'         => true,
																'quicktags'     => true,
																'tinymce'       => [
																	'toolbar1'      => 'formatselect,bold,italic,link,bullist,numlist,undo,redo,removeformat',
																	'toolbar2'      => '',
																	'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3;Heading 4=h4',
																	'resize'        => false,
																],
															]
														);
														?>
													</td>
												</tr>
											</table>
										</details>
									</section>
									<?php $panel_index++; ?>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</details>
					</div>
					<h2><?php esc_html_e( 'Layout & Spacing', 'nova-bridge-suite' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="quarantined-cpt-bodyclean-header-offset"><?php esc_html_e( 'Top content offset', 'nova-bridge-suite' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="quarantined-cpt-bodyclean-header-offset" name="<?php echo esc_attr( self::OPTION_HEADER_OFFSET ); ?>" value="<?php echo esc_attr( $header_offset ); ?>" />
								<p class="description"><?php esc_html_e( 'Controls the whitespace above the CPT content. Accepts CSS units such as 2rem, 120px, 10vh, or calc(2rem + 20px).', 'nova-bridge-suite' ); ?></p>
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
						<details class="quarantined-cpt-settings__dropdown quarantined-cpt-settings__dropdown--optional">
							<summary><?php esc_html_e( 'Element Exclusions (optional)', 'nova-bridge-suite' ); ?></summary>
							<p class="description"><?php esc_html_e( 'Use CSS selectors (class-based, id, or other valid selectors) to hide matching elements on CPT pages only. One selector per line.', 'nova-bridge-suite' ); ?></p>
							<textarea
								name="<?php echo esc_attr( self::OPTION_EXCLUDE_SELECTORS ); ?>"
								id="quarantined-cpt-bodyclean-selectors"
								rows="10"
								cols="70"
								class="large-text code"
								placeholder=".site-breadcrumbs&#10;.banner-wrapper"
							><?php echo esc_textarea( $value ); ?></textarea>
						</details>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'CPT registration is currently disabled. Enable CPT registration above and save to unlock all CPT-specific settings.', 'nova-bridge-suite' ); ?></p>
					<?php endif; ?>
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
		$this->pending_cpt_slug_renames = [];

		if ( ! is_array( $value ) ) {
			return [];
		}

		$rename_map = $this->capture_cpt_slug_rename_map( $value );
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
				$singular = ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
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

		$clean_values = array_values( $clean );

		if ( ! empty( $rename_map ) ) {
			$valid_targets = [];

			foreach ( $clean_values as $definition ) {
				if ( is_array( $definition ) && ! empty( $definition['type'] ) ) {
					$valid_targets[] = sanitize_key( (string) $definition['type'] );
				}
			}

			$rename_map = array_filter(
				$rename_map,
				static function ( string $new_slug ) use ( $valid_targets ): bool {
					return in_array( sanitize_key( $new_slug ), $valid_targets, true );
				}
			);
		}

		if ( ! empty( $rename_map ) ) {
			$this->pending_cpt_slug_renames = $rename_map;
			$this->migrate_posts_for_cpt_slug_renames( $rename_map );
		}

		return $clean_values;
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
			$sanitized[ $key ] = array_key_exists( $key, $value ) ? ! empty( $value[ $key ] ) : (bool) $default;
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes component order settings.
	 *
	 * @param array|string $value Raw value from the form.
	 * @return array<string,int>
	 */
	public function sanitize_component_order_settings( $value ): array {
		$defaults = $this->get_component_order_defaults();

		if ( ! is_array( $value ) ) {
			$value = [];
		}

		$sanitized = [];

		foreach ( $defaults as $key => $default ) {
			$raw = array_key_exists( $key, $value ) ? absint( $value[ $key ] ) : (int) $default;
			$sanitized[ $key ] = max( 0, min( 9999, $raw ) );
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes component visibility overrides keyed by CPT.
	 *
	 * @param array|string $value Raw value from the form.
	 * @return array<string,array<string,bool>>
	 */
	public function sanitize_component_settings_by_cpt( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$defaults  = $this->get_component_defaults();
		$sanitized = [];

		foreach ( $value as $post_type => $settings ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $settings ) ) {
				continue;
			}

			$normalized = [];

			foreach ( $defaults as $key => $default ) {
				$normalized[ $key ] = array_key_exists( $key, $settings ) ? ! empty( $settings[ $key ] ) : false;
			}

			$sanitized[ $post_type ] = $normalized;
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes component order overrides keyed by CPT.
	 *
	 * @param array|string $value Raw value from the form.
	 * @return array<string,array<string,int>>
	 */
	public function sanitize_component_order_settings_by_cpt( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$defaults  = $this->get_component_order_defaults();
		$sanitized = [];

		foreach ( $value as $post_type => $order ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $order ) ) {
				continue;
			}

			$normalized = [];

			foreach ( $defaults as $key => $default ) {
				$raw = array_key_exists( $key, $order ) ? absint( $order[ $key ] ) : (int) $default;
				$normalized[ $key ] = max( 0, min( 9999, $raw ) );
			}

			$sanitized[ $post_type ] = $normalized;
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes per-CPT CTA default overrides.
	 *
	 * @param array|string $value Raw option value.
	 * @return array<string,array<string,array<string,string>>>
	 */
	public function sanitize_blog_cta_defaults_by_cpt( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$slots = [
			'primary',
			'after_related',
		];
		$sanitized = [];

		foreach ( $value as $post_type => $slot_payloads ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $slot_payloads ) ) {
				continue;
			}

			$type_payload = [];

			foreach ( $slots as $slot ) {
				$payload = isset( $slot_payloads[ $slot ] ) && is_array( $slot_payloads[ $slot ] ) ? $slot_payloads[ $slot ] : [];
				$title   = $this->sanitize_text_option( $payload['title'] ?? '' );
				$copy_raw = $payload['copy'] ?? '';
				$copy     = $this->sanitize_blog_rich_text( is_string( $copy_raw ) ? $copy_raw : '' );
				$label   = $this->sanitize_text_option( $payload['button_label'] ?? '' );
				$url     = self::sanitize_blog_cta_url( $payload['button_url'] ?? '' );

				if ( '' === $title && ! $this->blog_html_has_text( $copy ) && '' === $label && '' === $url ) {
					continue;
				}

				$type_payload[ $slot ] = [
					'title'        => $title,
					'copy'         => $copy,
					'button_label' => $label,
					'button_url'   => $url,
				];
			}

			if ( ! empty( $type_payload ) ) {
				$sanitized[ $post_type ] = $type_payload;
			}
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes per-CPT archive layout overrides.
	 *
	 * @param array|string $value Raw option value.
	 * @return array<string,array<string,mixed>>
	 */
	public function sanitize_archive_settings_by_cpt( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$sanitized = [];
		$sanitize_cta_payload = function ( array $payload ): array {
			$title    = $this->sanitize_text_option( $payload['title'] ?? '' );
			$copy_raw = $payload['copy'] ?? '';
			$copy     = $this->sanitize_blog_rich_text( is_string( $copy_raw ) ? $copy_raw : '' );
			$label    = $this->sanitize_text_option( $payload['button_label'] ?? '' );
			$url      = self::sanitize_blog_cta_url( $payload['button_url'] ?? '' );

			return [
				'title'        => $title,
				'copy'         => $copy,
				'button_label' => $label,
				'button_url'   => $url,
			];
		};
		$cta_has_content = function ( array $payload ): bool {
			$title = isset( $payload['title'] ) ? (string) $payload['title'] : '';
			$copy  = isset( $payload['copy'] ) ? (string) $payload['copy'] : '';
			$label = isset( $payload['button_label'] ) ? (string) $payload['button_label'] : '';
			$url   = isset( $payload['button_url'] ) ? (string) $payload['button_url'] : '';

			return '' !== $title
				|| $this->blog_html_has_text( $copy )
				|| '' !== $label
				|| '' !== $url;
		};

		foreach ( $value as $post_type => $payload ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $payload ) ) {
				continue;
			}

			$intro_raw  = $payload['intro'] ?? '';
			$intro      = $this->sanitize_blog_rich_text( is_string( $intro_raw ) ? $intro_raw : '' );
			$bottom_raw = $payload['content_after_cta'] ?? '';
			$bottom     = $this->sanitize_blog_rich_text( is_string( $bottom_raw ) ? $bottom_raw : '' );

			$posts_per_page = 0;
			if ( array_key_exists( 'posts_per_page', $payload ) && '' !== trim( (string) $payload['posts_per_page'] ) ) {
				$posts_per_page = max( 1, min( 200, absint( $payload['posts_per_page'] ) ) );
			}

			$cta_before_payload = isset( $payload['cta_before'] ) && is_array( $payload['cta_before'] ) ? $payload['cta_before'] : [];
			$cta_after_payload  = isset( $payload['cta_after'] ) && is_array( $payload['cta_after'] ) ? $payload['cta_after'] : [];

			// Backward compatibility for old key.
			if ( empty( $cta_after_payload ) && isset( $payload['cta'] ) && is_array( $payload['cta'] ) ) {
				$cta_after_payload = $payload['cta'];
			}

			$cta_before          = $sanitize_cta_payload( $cta_before_payload );
			$cta_after           = $sanitize_cta_payload( $cta_after_payload );
			$has_before_flag     = array_key_exists( 'cta_before_disabled', $payload );
			$has_after_flag      = array_key_exists( 'cta_after_disabled', $payload );
			$cta_before_disabled = $has_before_flag ? self::sanitize_blog_bool_flag( $payload['cta_before_disabled'] ) : false;
			$cta_after_disabled  = $has_after_flag ? self::sanitize_blog_bool_flag( $payload['cta_after_disabled'] ) : false;

			$type_payload = [];

			if ( $this->blog_html_has_text( $intro ) ) {
				$type_payload['intro'] = $intro;
			}

			if ( $posts_per_page > 0 ) {
				$type_payload['posts_per_page'] = $posts_per_page;
			}

			if ( $cta_before_disabled || $cta_has_content( $cta_before ) ) {
				$type_payload['cta_before'] = $cta_before;
			}

			if ( $cta_after_disabled || $cta_has_content( $cta_after ) ) {
				$type_payload['cta_after'] = $cta_after;
			}

			if ( $has_before_flag ) {
				$type_payload['cta_before_disabled'] = $cta_before_disabled;
			}

			if ( $has_after_flag ) {
				$type_payload['cta_after_disabled'] = $cta_after_disabled;
			}

			if ( $this->blog_html_has_text( $bottom ) ) {
				$type_payload['content_after_cta'] = $bottom;
			}

			if ( ! empty( $type_payload ) ) {
				$sanitized[ $post_type ] = $type_payload;
			}
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Sanitizes per-CPT design overrides.
	 *
	 * @param array|string $value Raw option value.
	 * @return array<string,array<string,string>>
	 */
	public function sanitize_blog_style_overrides_by_cpt( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$defaults  = $this->get_empty_blog_style_override_payload();
		$sanitized = [];

		foreach ( $value as $post_type => $payload ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $payload ) ) {
				continue;
			}

			$type_payload = [];

			foreach ( $defaults as $key => $default ) {
				$clean = $this->sanitize_blog_color_option( $payload[ $key ] ?? '' );

				if ( '' !== $clean ) {
					$type_payload[ $key ] = $clean;
				}
			}

			if ( ! empty( $type_payload ) ) {
				$sanitized[ $post_type ] = $type_payload;
			}
		}

		return $this->remap_cpt_keyed_option_payload( $sanitized );
	}

	/**
	 * Re-keys per-CPT option payloads when a slug is renamed during save.
	 *
	 * @param array<string,mixed> $payload Existing payload keyed by CPT slug.
	 * @return array<string,mixed>
	 */
	private function remap_cpt_keyed_option_payload( array $payload ): array {
		if ( empty( $payload ) || empty( $this->pending_cpt_slug_renames ) ) {
			return $payload;
		}

		$remapped = [];

		foreach ( $payload as $post_type => $value ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type ) {
				continue;
			}

			$target = $this->pending_cpt_slug_renames[ $post_type ] ?? $post_type;
			$target = sanitize_key( (string) $target );

			if ( '' === $target ) {
				continue;
			}

			$remapped[ $target ] = $value;
		}

		return $remapped;
	}

	/**
	 * Builds a slug rename map keyed by existing row index.
	 *
	 * @param array<string|int,mixed> $submitted_definitions Raw submitted definitions.
	 * @return array<string,string>
	 */
	private function capture_cpt_slug_rename_map( array $submitted_definitions ): array {
		$current_definitions = get_option( self::OPTION_CPTS, [] );

		if ( ! is_array( $current_definitions ) ) {
			return [];
		}

		$current_by_index = [];
		foreach ( $current_definitions as $index => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$slug = $this->extract_definition_slug( $definition );

			if ( '' !== $slug ) {
				$current_by_index[ (string) $index ] = $slug;
			}
		}

		$submitted_by_index = [];
		foreach ( $submitted_definitions as $index => $definition ) {
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$slug = $this->extract_definition_slug( $definition );

			if ( '' !== $slug ) {
				$submitted_by_index[ (string) $index ] = $slug;
			}
		}

		$rename_map = [];

		foreach ( $current_by_index as $index => $old_slug ) {
			if ( ! isset( $submitted_by_index[ $index ] ) ) {
				continue;
			}

			$new_slug = $submitted_by_index[ $index ];

			if ( '' === $old_slug || '' === $new_slug || $old_slug === $new_slug ) {
				continue;
			}

			$rename_map[ $old_slug ] = $new_slug;
		}

		return $rename_map;
	}

	/**
	 * Extracts the normalized slug from a CPT definition payload.
	 *
	 * @param array<string,mixed> $definition Raw definition payload.
	 * @return string
	 */
	private function extract_definition_slug( array $definition ): string {
		$slug = isset( $definition['slug'] ) ? sanitize_title_with_dashes( (string) $definition['slug'] ) : '';

		if ( '' === $slug && isset( $definition['type'] ) ) {
			$slug = sanitize_title_with_dashes( (string) $definition['type'] );
		}

		if ( '' === $slug ) {
			return '';
		}

		if ( strlen( $slug ) > 20 ) {
			$slug = substr( $slug, 0, 20 );
		}

		return sanitize_key( $slug );
	}

	/**
	 * Migrates existing posts to new CPT slugs when a row is renamed.
	 *
	 * @param array<string,string> $rename_map Old slug => new slug.
	 * @return void
	 */
	private function migrate_posts_for_cpt_slug_renames( array $rename_map ): void {
		global $wpdb;

		foreach ( $rename_map as $old_slug => $new_slug ) {
			$old_slug = sanitize_key( (string) $old_slug );
			$new_slug = sanitize_key( (string) $new_slug );

			if ( '' === $old_slug || '' === $new_slug || $old_slug === $new_slug ) {
				continue;
			}

			$post_ids = get_posts(
				[
					'post_type'        => $old_slug,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				]
			);

			if ( empty( $post_ids ) ) {
				continue;
			}

			foreach ( $post_ids as $post_id ) {
				$wpdb->update(
					$wpdb->posts,
					[ 'post_type' => $new_slug ],
					[ 'ID' => (int) $post_id ],
					[ '%s' ],
					[ '%d' ]
				);
				clean_post_cache( (int) $post_id );
			}

			self::log( 'Migrated ' . count( $post_ids ) . ' posts from ' . $old_slug . ' to ' . $new_slug );
		}
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

	/**
	 * Sanitizes selected settings language key.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_label_language_option( $value ): string {
		$key     = sanitize_key( (string) $value );
		$choices = $this->get_label_language_options();

		return array_key_exists( $key, $choices ) ? $key : self::DEFAULT_LABEL_LANGUAGE;
	}

	public function sanitize_header_offset_option( $value ): string {
		$value = is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		$normalized = $this->normalize_header_offset( $value );

		return '' === $normalized ? self::DEFAULT_HEADER_OFFSET : $normalized;
	}

	public function sanitize_blog_style_preset_option( $value ): string {
		if ( ! is_string( $value ) ) {
			return self::DEFAULT_BLOG_STYLE_PRESET;
		}

		$value   = sanitize_key( $value );
		$choices = $this->get_blog_style_preset_options();

		return array_key_exists( $value, $choices ) ? $value : self::DEFAULT_BLOG_STYLE_PRESET;
	}

	public function sanitize_blog_content_max_width_option( $value ): string {
		$value      = is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		$normalized = $this->normalize_header_offset( $value );

		return '' === $normalized ? self::DEFAULT_BLOG_CONTENT_MAX_WIDTH : $normalized;
	}

	public function sanitize_blog_card_radius_option( $value ): string {
		$value      = is_string( $value ) ? wp_strip_all_tags( $value ) : '';
		$normalized = $this->normalize_header_offset( $value );

		return '' === $normalized ? '' : $normalized;
	}

	public function sanitize_blog_color_option( $value ): string {
		return $this->sanitize_css_color_token( $value );
	}

	/**
	 * Sanitizes CSS color tokens for inline style usage.
	 *
	 * @param mixed $value Raw color value.
	 * @return string
	 */
	private function sanitize_css_color_token( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( wp_strip_all_tags( $value ) );

		if ( '' === $value ) {
			return '';
		}

		$lower = strtolower( $value );

		if ( in_array( $lower, [ 'transparent', 'currentcolor', 'inherit' ], true ) ) {
			return $lower;
		}

		if ( preg_match( '/^#[0-9a-fA-F]{3}$/', $value ) || preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '/^rgba?\(\s*(\d{1,3}\s*,\s*){2}\d{1,3}(?:\s*,\s*(?:0|0?\.\d+|1(?:\.0+)?)\s*)?\)$/', $value ) ) {
			return $value;
		}

		return '';
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
			'meta' => '<svg class="quarantined-cpt__author-social-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M17.8 6.4c-2.2 0-3.9 1.2-5.8 3.4-1.8-2.2-3.5-3.4-5.8-3.4-2.4 0-4.2 1.9-4.2 4.3 0 2.4 1.8 4.3 4.2 4.3 1.9 0 3.4-1 5.8-3.6 2.3 2.6 3.9 3.6 5.8 3.6 2.4 0 4.2-1.9 4.2-4.3 0-2.4-1.8-4.3-4.2-4.3Zm-11.6 6c-1.2 0-2.1-.8-2.1-1.7S5 9 6.2 9c1.2 0 2.4.9 4.2 2.9-1.8 1.8-3 2.5-4.2 2.5Zm11.6 0c-1.2 0-2.4-.7-4.2-2.5 1.8-2 3-2.9 4.2-2.9 1.2 0 2.1.8 2.1 1.7s-.9 1.7-2.1 1.7Z"/></svg>',
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
	 * Builds explicit frontend override rules for filled blog design settings.
	 *
	 * These rules intentionally only render when a setting has an effective value,
	 * so Theme Native can continue inheriting theme defaults for untouched fields.
	 *
	 * @param array<string,string> $style_settings Effective blog style settings.
	 * @return string
	 */
	private function build_blog_style_override_rules( array $style_settings ): string {
		$rules = [];

		$text_color = trim( (string) ( $style_settings['text_color'] ?? '' ) );

		if ( '' !== $text_color ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt,',
					'.quarantined-cpt .quarantined-cpt__title,',
					'.quarantined-cpt .quarantined-cpt__description,',
					'.quarantined-cpt .quarantined-cpt__archive-intro,',
					'.quarantined-cpt .quarantined-cpt__meta,',
					'.quarantined-cpt .quarantined-cpt__meta-row,',
					'.quarantined-cpt .quarantined-cpt__author-chip-text,',
					'.quarantined-cpt .quarantined-cpt__intro,',
					'.quarantined-cpt .quarantined-cpt__content,',
					'.quarantined-cpt .quarantined-cpt__content h1,',
					'.quarantined-cpt .quarantined-cpt__content h2,',
					'.quarantined-cpt .quarantined-cpt__content h3,',
					'.quarantined-cpt .quarantined-cpt__content h4,',
					'.quarantined-cpt .quarantined-cpt__content h5,',
					'.quarantined-cpt .quarantined-cpt__content h6,',
					'.quarantined-cpt .quarantined-cpt__content p,',
					'.quarantined-cpt .quarantined-cpt__content li,',
					'.quarantined-cpt .quarantined-cpt__content blockquote,',
					'.quarantined-cpt .quarantined-cpt__content figcaption,',
					'.quarantined-cpt .quarantined-cpt__panel,',
					'.quarantined-cpt .quarantined-cpt__panel-title,',
					'.quarantined-cpt .quarantined-cpt__wide-cta,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-title,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-copy,',
					'.quarantined-cpt .quarantined-cpt__faq-title,',
					'.quarantined-cpt .quarantined-cpt__faq-item,',
					'.quarantined-cpt .quarantined-cpt__faq-item summary,',
					'.quarantined-cpt .quarantined-cpt__faq-answer,',
					'.quarantined-cpt .quarantined-cpt__related-title,',
					'.quarantined-cpt .quarantined-cpt__card,',
					'.quarantined-cpt .quarantined-cpt__card-title,',
					'.quarantined-cpt .quarantined-cpt__card-summary,',
					'.quarantined-cpt .quarantined-cpt__card-meta,',
					'.quarantined-cpt .quarantined-cpt__author-box,',
					'.quarantined-cpt .quarantined-cpt__author-box-name,',
					'.quarantined-cpt .quarantined-cpt__author-box-role,',
					'.quarantined-cpt .quarantined-cpt__author-box-description,',
					'.quarantined-cpt .quarantined-cpt__author-role,',
					'.quarantined-cpt .quarantined-cpt__author-location,',
					'.quarantined-cpt .quarantined-cpt__author-description,',
					'.quarantined-cpt .quarantined-cpt__empty {',
					"\tcolor: var(--quarantined-cpt-text-color) !important;",
					'}',
				]
			);
		}

		$link_color = trim( (string) ( $style_settings['link_color'] ?? '' ) );

		if ( '' !== $link_color ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__description a,',
					'.quarantined-cpt .quarantined-cpt__archive-intro a,',
					'.quarantined-cpt .quarantined-cpt__meta a,',
					'.quarantined-cpt .quarantined-cpt__crumb a,',
					'.quarantined-cpt .quarantined-cpt__author-chip-text a,',
					'.quarantined-cpt .quarantined-cpt__content a,',
					'.quarantined-cpt .quarantined-cpt__panel a,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-copy a,',
					'.quarantined-cpt .quarantined-cpt__author-box-description a,',
					'.quarantined-cpt .quarantined-cpt__card-title a,',
					'.quarantined-cpt .quarantined-cpt__author-role a,',
					'.quarantined-cpt .quarantined-cpt__author-location a,',
					'.quarantined-cpt .quarantined-cpt__author-box-name a {',
					"\tcolor: var(--quarantined-cpt-link-color) !important;",
					'}',
				]
			);
		}

		$link_hover_color = trim( (string) ( $style_settings['link_hover_color'] ?? '' ) );

		if ( '' !== $link_hover_color ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__description a:hover,',
					'.quarantined-cpt .quarantined-cpt__description a:focus,',
					'.quarantined-cpt .quarantined-cpt__archive-intro a:hover,',
					'.quarantined-cpt .quarantined-cpt__archive-intro a:focus,',
					'.quarantined-cpt .quarantined-cpt__meta a:hover,',
					'.quarantined-cpt .quarantined-cpt__meta a:focus,',
					'.quarantined-cpt .quarantined-cpt__crumb a:hover,',
					'.quarantined-cpt .quarantined-cpt__crumb a:focus,',
					'.quarantined-cpt .quarantined-cpt__author-chip-text a:hover,',
					'.quarantined-cpt .quarantined-cpt__author-chip-text a:focus,',
					'.quarantined-cpt .quarantined-cpt__content a:hover,',
					'.quarantined-cpt .quarantined-cpt__content a:focus,',
					'.quarantined-cpt .quarantined-cpt__panel a:hover,',
					'.quarantined-cpt .quarantined-cpt__panel a:focus,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-copy a:hover,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-copy a:focus,',
					'.quarantined-cpt .quarantined-cpt__author-box-description a:hover,',
					'.quarantined-cpt .quarantined-cpt__author-box-description a:focus,',
					'.quarantined-cpt .quarantined-cpt__card-title a:hover,',
					'.quarantined-cpt .quarantined-cpt__card-title a:focus,',
					'.quarantined-cpt .quarantined-cpt__author-role a:hover,',
					'.quarantined-cpt .quarantined-cpt__author-role a:focus,',
					'.quarantined-cpt .quarantined-cpt__author-location a:hover,',
					'.quarantined-cpt .quarantined-cpt__author-location a:focus,',
					'.quarantined-cpt .quarantined-cpt__author-box-name a:hover,',
					'.quarantined-cpt .quarantined-cpt__author-box-name a:focus {',
					"\tcolor: var(--quarantined-cpt-link-hover-color) !important;",
					'}',
				]
			);
		}

		$content_max_width = trim( (string) ( $style_settings['content_max_width'] ?? '' ) );

		if ( '' !== $content_max_width ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__entry--article{\n\tmax-width: var(--quarantined-cpt-entry-max-width) !important;\n}";
		}

		$panel_background = trim( (string) ( $style_settings['panel_background'] ?? '' ) );

		if ( '' !== $panel_background ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__panel,',
					'.quarantined-cpt .quarantined-cpt__faq-item {',
					"\tbackground-color: var(--quarantined-cpt-panel-background) !important;",
					'}',
				]
			);
		}

		$panel_border = trim( (string) ( $style_settings['panel_border'] ?? '' ) );

		if ( '' !== $panel_border ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__panel,',
					'.quarantined-cpt .quarantined-cpt__faq-item {',
					"\tborder-color: var(--quarantined-cpt-panel-border-color) !important;",
					'}',
				]
			);
		}

		$meta_border = trim( (string) ( $style_settings['meta_border'] ?? '' ) );

		if ( '' !== $meta_border ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__meta-row{\n\tborder-bottom-color: var(--quarantined-cpt-meta-divider-color) !important;\n}";
		}

		$share_background = trim( (string) ( $style_settings['share_background'] ?? '' ) );

		if ( '' !== $share_background ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__share-link,',
					'.quarantined-cpt .quarantined-cpt__author-social a {',
					"\tbackground-color: var(--quarantined-cpt-social-background, var(--quarantined-cpt-share-bg)) !important;",
					'}',
				]
			);
		}

		$share_border = trim( (string) ( $style_settings['share_border'] ?? '' ) );

		if ( '' !== $share_border ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__share-link{\n\tborder-color: var(--quarantined-cpt-share-border) !important;\n}";
		}

		$cta_background = trim( (string) ( $style_settings['cta_background'] ?? '' ) );

		if ( '' !== $cta_background ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__wide-cta{\n\tbackground-color: var(--quarantined-cpt-cta-bg) !important;\n}";
		}

		$cta_button_background = trim( (string) ( $style_settings['cta_button_background'] ?? '' ) );

		if ( '' !== $cta_button_background ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__wide-cta-button{\n\tbackground-color: var(--quarantined-cpt-cta-button-bg) !important;\n}";
		}

		$cta_button_text = trim( (string) ( $style_settings['cta_button_text'] ?? '' ) );

		if ( '' !== $cta_button_text ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__wide-cta-button,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-button:hover,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-button:focus {',
					"\tcolor: var(--quarantined-cpt-cta-button-text) !important;",
					'}',
				]
			);
		}

		$cta_button_hover = trim( (string) ( $style_settings['cta_button_hover'] ?? '' ) );

		if ( '' !== $cta_button_hover ) {
			$rules[] = implode(
				"\n",
				[
					'.quarantined-cpt .quarantined-cpt__wide-cta-button:hover,',
					'.quarantined-cpt .quarantined-cpt__wide-cta-button:focus {',
					"\tbackground-color: var(--quarantined-cpt-cta-button-hover-bg) !important;",
					'}',
				]
			);
		}

		$author_box_background = trim( (string) ( $style_settings['author_box_background'] ?? '' ) );

		if ( '' !== $author_box_background ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__author-box{\n\tbackground-color: var(--quarantined-cpt-author-box-bg) !important;\n}";
		}

		$author_box_border = trim( (string) ( $style_settings['author_box_border'] ?? '' ) );

		if ( '' !== $author_box_border ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__author-box{\n\tborder-color: var(--quarantined-cpt-author-box-border) !important;\n}";
		}

		$card_radius = trim( (string) ( $style_settings['card_radius'] ?? '' ) );

		if ( '' !== $card_radius ) {
			$rules[] = ".quarantined-cpt .quarantined-cpt__card{\n\tborder-radius: var(--quarantined-cpt-card-radius) !important;\n}";
		}

		return implode( "\n", $rules );
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
			'title'         => true,
			'breadcrumbs'   => true,
			'date'          => true,
			'author'        => true,
			'featured'      => true,
			'read_time'     => true,
			'share_links'   => true,
			'intro'         => true,
			'key_takeaways' => true,
			'toc'           => true,
			'content_1'     => true,
			'wide_cta'      => true,
			'content_2'     => true,
			'faq'           => true,
			'related'       => true,
			'wide_cta_after_related' => true,
			'author_box'    => true,
		];
	}

	/**
	 * Returns the default component order map.
	 *
	 * Lower numbers render earlier/higher on the page.
	 *
	 * @return array<string,int>
	 */
	private function get_component_order_defaults(): array {
		return [
			'breadcrumbs'            => 10,
			'featured'               => 20,
			'title'                  => 30,
			'meta'                   => 40,
			'intro'                  => 50,
			'content_1'              => 60,
			'wide_cta'               => 70,
			'content_2'              => 80,
			'faq'                    => 90,
			'related'                => 100,
			'wide_cta_after_related' => 110,
			'author_box'             => 120,
		];
	}

	/**
	 * Returns labels for component-order controls in settings.
	 *
	 * @return array<string,string>
	 */
	private function get_component_order_labels(): array {
		return [
			'breadcrumbs'            => __( 'Breadcrumbs', 'nova-bridge-suite' ),
			'featured'               => __( 'Featured image', 'nova-bridge-suite' ),
			'title'                  => __( 'Title (H1)', 'nova-bridge-suite' ),
			'meta'                   => __( 'Meta row (author/read time/date/share)', 'nova-bridge-suite' ),
			'intro'                  => __( 'Intro paragraph', 'nova-bridge-suite' ),
			'content_1'              => __( 'Content block 1 + TOC + Key takeaways', 'nova-bridge-suite' ),
			'wide_cta'               => __( 'Primary wide CTA', 'nova-bridge-suite' ),
			'content_2'              => __( 'Content block 2', 'nova-bridge-suite' ),
			'faq'                    => __( 'FAQs', 'nova-bridge-suite' ),
			'related'                => __( 'Related articles', 'nova-bridge-suite' ),
			'wide_cta_after_related' => __( 'Second wide CTA (after related)', 'nova-bridge-suite' ),
			'author_box'             => __( 'Author biography box', 'nova-bridge-suite' ),
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
			$normalized[ $key ] = array_key_exists( $key, $saved ) ? ! empty( $saved[ $key ] ) : (bool) $default;
		}

		return apply_filters( 'quarantined_cpt_bodyclean/component_settings', $normalized, $defaults, $saved );
	}

	/**
	 * Retrieves component visibility overrides keyed by CPT.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private function get_component_settings_by_cpt(): array {
		$saved = get_option( self::OPTION_COMPONENT_VISIBILITY_BY_CPT, [] );

		return $this->sanitize_component_settings_by_cpt( $saved );
	}

	/**
	 * Retrieves merged component render-order settings.
	 *
	 * @return array<string,int>
	 */
	private function get_component_order_settings(): array {
		$defaults = $this->get_component_order_defaults();
		$saved    = get_option( self::OPTION_COMPONENT_ORDER, null );

		if ( ! is_array( $saved ) ) {
			return apply_filters( 'quarantined_cpt_bodyclean/component_order', $defaults, $defaults, [] );
		}

		$normalized = $defaults;

		foreach ( $defaults as $key => $default ) {
			$raw = array_key_exists( $key, $saved ) ? absint( $saved[ $key ] ) : (int) $default;
			$normalized[ $key ] = max( 0, min( 9999, $raw ) );
		}

		return apply_filters( 'quarantined_cpt_bodyclean/component_order', $normalized, $defaults, $saved );
	}

	/**
	 * Retrieves component order overrides keyed by CPT.
	 *
	 * @return array<string,array<string,int>>
	 */
	private function get_component_order_settings_by_cpt(): array {
		$saved = get_option( self::OPTION_COMPONENT_ORDER_BY_CPT, [] );

		return $this->sanitize_component_order_settings_by_cpt( $saved );
	}

	/**
	 * Returns effective component visibility for a specific CPT.
	 *
	 * @param string $post_type CPT key.
	 * @return array<string,bool>
	 */
	private function get_component_settings_for_type( string $post_type ): array {
		$settings  = $this->get_component_settings();
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			return $settings;
		}

		$by_cpt = $this->get_component_settings_by_cpt();

		if ( ! isset( $by_cpt[ $post_type ] ) || ! is_array( $by_cpt[ $post_type ] ) ) {
			return $settings;
		}

		foreach ( $settings as $key => $default ) {
			if ( array_key_exists( $key, $by_cpt[ $post_type ] ) ) {
				$settings[ $key ] = (bool) $by_cpt[ $post_type ][ $key ];
			}
		}

		return $settings;
	}

	/**
	 * Returns effective component order for a specific CPT.
	 *
	 * @param string $post_type CPT key.
	 * @return array<string,int>
	 */
	private function get_component_order_settings_for_type( string $post_type ): array {
		$order     = $this->get_component_order_settings();
		$post_type = sanitize_key( $post_type );

		if ( '' === $post_type ) {
			return $order;
		}

		$by_cpt = $this->get_component_order_settings_by_cpt();

		if ( ! isset( $by_cpt[ $post_type ] ) || ! is_array( $by_cpt[ $post_type ] ) ) {
			return $order;
		}

		foreach ( $order as $key => $default ) {
			if ( array_key_exists( $key, $by_cpt[ $post_type ] ) ) {
				$order[ $key ] = max( 0, min( 9999, (int) $by_cpt[ $post_type ][ $key ] ) );
			}
		}

		return $order;
	}

	/**
	 * Detects post type context for component rendering.
	 *
	 * @param string $post_type Explicit post type override.
	 * @return string
	 */
	private function detect_component_post_type_context( string $post_type = '' ): string {
		$post_type = sanitize_key( $post_type );

		if ( '' !== $post_type ) {
			return $post_type;
		}

		$detected = get_post_type();

		if ( is_string( $detected ) && '' !== $detected ) {
			return sanitize_key( $detected );
		}

		$query_var = get_query_var( 'post_type', '' );

		if ( is_array( $query_var ) ) {
			$query_var = reset( $query_var );
		}

		if ( is_string( $query_var ) && '' !== $query_var ) {
			return sanitize_key( $query_var );
		}

		return '';
	}

	/**
	 * Determines whether a component should be displayed.
	 *
	 * @param string $component Component key.
	 * @param string $post_type Optional post type context.
	 * @return bool
	 */
	public static function component_enabled( string $component, string $post_type = '' ): bool {
		if ( null === self::$instance ) {
			return true;
		}

		return self::$instance->is_component_enabled( $component, $post_type );
	}

	/**
	 * Instance helper for component visibility lookup.
	 *
	 * @param string $component Component key.
	 * @param string $post_type Optional post type context.
	 * @return bool
	 */
	private function is_component_enabled( string $component, string $post_type = '' ): bool {
		$post_type = $this->detect_component_post_type_context( $post_type );
		$settings  = $this->get_component_settings_for_type( $post_type );

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
			return __( 'By', 'nova-bridge-suite' );
		}

		return self::$instance->get_author_label();
	}

	public static function key_takeaways_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Key takeaways', 'nova-bridge-suite' );
		}

		return self::$instance->get_key_takeaways_label();
	}

	public static function toc_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Table of contents', 'nova-bridge-suite' );
		}

		return self::$instance->get_toc_label();
	}

	public static function toc_read_more_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Show more...', 'nova-bridge-suite' );
		}

		return self::$instance->get_toc_read_more_label();
	}

	public static function toc_read_less_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Show less...', 'nova-bridge-suite' );
		}

		return self::$instance->get_toc_read_less_label();
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

	public static function related_articles_label_text(): string {
		if ( null === self::$instance ) {
			return __( 'Related articles', 'nova-bridge-suite' );
		}

		return self::$instance->get_related_articles_label();
	}

	public static function faq_title_text(): string {
		if ( null === self::$instance ) {
			return __( 'Frequently asked questions', 'nova-bridge-suite' );
		}

		return self::$instance->get_faq_title_label();
	}

	public static function read_time_label_text( int $minutes ): string {
		$minutes = max( 1, $minutes );

		if ( null === self::$instance ) {
			return sprintf(
				/* translators: %d: estimated read time in minutes */
				_n( '%d min read', '%d mins read', $minutes, 'nova-bridge-suite' ),
				$minutes
			);
		}

		return self::$instance->get_read_time_label( $minutes );
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
