<?php
/** Shared sections and a default site layout, using the native section editor. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceSharedAdmin {
	private const PAGE = 'nova-service-shared';

	public static function bootstrap(): void {
		\add_action( 'admin_menu', [ self::class, 'register_menu' ] );
		\add_action( 'admin_init', [ self::class, 'redirect_legacy_settings' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
		\add_action( 'admin_post_nova_service_save_shared', [ self::class, 'save' ] );
		\add_action( 'admin_post_nova_service_save_template', [ self::class, 'save_page_template' ] );
	}

	public static function register_menu(): void {
		\add_submenu_page( 'edit.php?post_type=service_page', __( 'Service page settings', 'nova-bridge-suite' ), __( 'Service page settings', 'nova-bridge-suite' ), 'manage_options', self::PAGE, [ self::class, 'render' ] );
	}

	private static function mode(): string {
		if ( \class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) && ServiceOnboarding::is_required() ) { return 'setup'; }
		$tab = isset( $_GET['tab'] ) && \is_scalar( $_GET['tab'] ) ? (string) $_GET['tab'] : 'defaults';
		if ( 'legacy' === $tab && self::has_legacy_pages() ) { return 'legacy'; }
		// Existing content-library bookmarks now open the live setup settings.
		return \in_array( $tab, [ 'library', 'setup' ], true ) ? 'setup' : 'defaults';
	}

	/** Saved old pages, including recoverable trash, still need their settings. */
	public static function has_legacy_pages(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status <> %s AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s) LIMIT 1", 'service_page', 'auto-draft', ServiceDocument::META_MODE, 'v2' ) );
	}

	private static function preview_post_id( array $request ): int {
		return isset( $request['preview_post_id'] ) && \is_scalar( $request['preview_post_id'] ) ? \absint( $request['preview_post_id'] ) : 0;
	}

	private static function advanced_setup(): bool {
		return 'setup' === self::mode() && ! ServiceOnboarding::is_required() && isset( $_GET['view'] ) && \is_scalar( $_GET['view'] ) && 'advanced' === (string) $_GET['view'];
	}

	public static function settings_url( string $mode = 'defaults', int $preview_post_id = 0 ): string {
		$mode = 'library' === $mode ? 'setup' : ( 'template' === $mode ? 'defaults' : $mode );
		$args = [ 'post_type' => 'service_page', 'page' => self::PAGE, 'tab' => \in_array( $mode, [ 'defaults', 'legacy', 'setup' ], true ) ? $mode : 'defaults' ];
		if ( isset( $_GET['lang'] ) && \is_scalar( $_GET['lang'] ) ) { $args['lang'] = \sanitize_key( \wp_unslash( (string) $_GET['lang'] ) ); }
		$preview_post_id = $preview_post_id ?: self::preview_post_id( $_GET );
		if ( $preview_post_id ) { $args['preview_post_id'] = $preview_post_id; }
		return \add_query_arg( $args, \admin_url( 'edit.php' ) );
	}

	/** Keep old bookmarks and options.php return URLs while exposing one settings entry. */
	public static function redirect_legacy_settings(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && \is_scalar( $_SERVER['REQUEST_METHOD'] ) ? \strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method || \wp_doing_ajax() || ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! isset( $_GET['page'] ) || ! \is_scalar( $_GET['page'] ) || 'service-cpt' !== (string) $_GET['page'] || ! \current_user_can( 'manage_options' ) ) { return; }
		$target = self::settings_url( self::has_legacy_pages() ? 'legacy' : 'defaults' );
		foreach ( [ 'lang', 'settings-updated' ] as $key ) {
			if ( isset( $_GET[ $key ] ) && \is_scalar( $_GET[ $key ] ) ) { $target = \add_query_arg( $key, \sanitize_text_field( \wp_unslash( (string) $_GET[ $key ] ) ), $target ); }
		}
		\wp_safe_redirect( $target );
		exit;
	}

	/** Read raw native documents so preview usage reflects local overrides and saved order. */
	private static function preview_context( array $presets, array $template ): array {
		$targets = [];
		$usage = [];
		$meta = [];
		$reserved = \array_map( static function ( string $type ): string { return 'default_' . $type; }, [ 'hero', 'cta', 'custom_cta', 'credentials', 'reviews', 'location', 'map', 'people', 'video' ] );
		$template_keys = [];
		$preset_types = \array_column( $presets['sections'], 'type', 'id' );
		foreach ( $template['sections'] ?? [] as $section ) {
			$key = (string) ( $section['shared_key'] ?? '' );
			if ( '' !== $key && isset( $preset_types[ $key ] ) && $preset_types[ $key ] === $section['type'] ) { $template_keys[] = $key; }
		}
		$template_keys = \array_values( \array_unique( $template_keys ) );
		foreach ( $presets['sections'] as $preset ) {
			$key = $preset['id'];
			$usage[ $key ] = [];
			$has_content = false;
			foreach ( [ 'title', 'eyebrow', 'body', 'images', 'buttons', 'items', 'html', 'shortcode', 'map_url', 'video_url', 'address', 'post_ids', 'review_source' ] as $field ) {
				if ( ! empty( ( (array) $preset['data'] )[ $field ] ) ) { $has_content = true; break; }
			}
			$meta[ $key ] = [ 'reserved' => \in_array( $key, $reserved, true ), 'empty' => ! $has_content, 'unused' => true, 'usedByTemplate' => \in_array( $key, $template_keys, true ) ];
		}
		$posts = \get_posts( [
			'post_type' => 'service_page', 'post_status' => [ 'publish', 'future', 'draft', 'pending', 'private' ],
			'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true,
			'meta_key' => ServiceDocument::META_MODE, 'meta_value' => 'v2',
		] );
		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;
			if ( ! \current_user_can( 'edit_post', $post_id ) ) { continue; }
			if ( 'setup' === self::mode() && ServiceLanguages::current() !== ServiceLanguages::current( $post_id ) ) { continue; }
			$title = (string) \get_the_title( $post_id );
			$title = '' !== $title ? $title : __( '(no title)', 'nova-bridge-suite' );
			$url = (string) \get_permalink( $post_id );
			$edit_url = (string) \get_edit_post_link( $post_id, 'raw' );
			$document = ServiceDocument::get_document( $post_id );
			$order = [];
			$ref_keys = [];
			foreach ( $document['sections'] as $position => $section ) {
				$data = (array) $section['data'];
				$key = (string) ( $section['shared_key'] ?? '' );
				$override_keys = \array_keys( $data );
				$order[] = [ 'id' => $section['id'], 'type' => $section['type'], 'shared_key' => $key, 'enabled' => (bool) $section['enabled'], 'title' => (string) ( $data['title'] ?? '' ), 'overrideFields' => $override_keys ];
				if ( '' === $key || ! isset( $preset_types[ $key ] ) || $preset_types[ $key ] !== $section['type'] ) { continue; }
				$ref_keys[] = $key;
				$usage[ $key ][] = [ 'postId' => $post_id, 'title' => $title, 'url' => $url, 'editUrl' => $edit_url, 'sectionId' => $section['id'], 'position' => $position, 'enabled' => (bool) $section['enabled'], 'overrideKeys' => $override_keys ];
				if ( isset( $meta[ $key ] ) ) { $meta[ $key ]['unused'] = false; }
			}
			$targets[] = [ 'id' => $post_id, 'title' => $title, 'url' => $url, 'editUrl' => $edit_url, 'document' => $document, 'sectionOrder' => $order, 'refKeys' => \array_values( \array_unique( $ref_keys ) ) ];
		}
		$requested = self::preview_post_id( $_GET );
		$selected = 0;
		$best_score = -1;
		foreach ( $targets as $target ) {
			if ( $requested === $target['id'] ) { $selected = $requested; break; }
			// Prefer a page using the configured site layout's actual variants.
			$score = \count( \array_intersect( $template_keys, $target['refKeys'] ) ) * 100 + \count( $target['refKeys'] );
			if ( $score > $best_score ) { $best_score = $score; $selected = $target['id']; }
		}
		return [ 'previewTargets' => $targets, 'previewPostId' => $selected, 'presetUsage' => $usage, 'presetMeta' => $meta, 'reservedPresetKeys' => $reserved ];
	}

	public static function enqueue(): void {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] || ! \current_user_can( 'manage_options' ) ) { return; }
		$mode = self::mode();
		if ( 'legacy' === $mode ) { return; }
		self::enqueue_setup_assets();
		if ( 'setup' === $mode && ! self::advanced_setup() ) { return; }
		$presets = ServiceShared::get_presets();
		$template = ServiceShared::get_template();
		$layout = $template ?: ServiceDocument::default_document();
		ServiceEditor::enqueue_document_assets( \array_merge( [
			'postId' => 0, 'isV2' => true, 'isNew' => true, 'mode' => 'setup' === $mode ? 'library' : 'template',
			'layoutOnly' => 'defaults' === $mode, 'setupUrl' => self::settings_url( 'setup' ), 'advancedSetupUrl' => \add_query_arg( 'view', 'advanced', self::settings_url( 'setup' ) ),
			'globalControlsReadOnly' => 'setup' === $mode && ServiceLanguages::is_translated(),
			'document' => 'setup' === $mode ? $presets : $layout,
			'sections' => ServiceDocument::section_registry(), 'presets' => $presets,
			'restNonce' => \wp_create_nonce( 'wp_rest' ), 'canManage' => true,
			'canUseHtml' => \current_user_can( 'unfiltered_html' ),
			'servicesUrl' => \rest_url( 'wp/v2/' . ( \get_post_type_object( 'service_page' )->rest_base ?: 'service_page' ) ),
			'importerUrl' => \rest_url( 'nova-service/v1/reviews/import' ),
			'editorPreviewUrl' => \rest_url( 'nova-service/v1/editor-preview' ),
			'language' => ServiceLanguages::current(),
		], self::preview_context( $presets, $layout ) ) );
	}

	private static function enqueue_setup_assets(): void {
		\wp_enqueue_media();
		$directory = \dirname( __DIR__ ) . '/assets/';
		$url = \plugins_url( 'assets/', \dirname( __DIR__ ) . '/service-page-cpt.php' );
		\wp_enqueue_style( 'nova-service-onboarding', $url . 'service-onboarding.css', [], (string) \filemtime( $directory . 'service-onboarding.css' ) );
		\wp_enqueue_script( 'nova-service-onboarding', $url . 'service-onboarding.js', [ 'media-views' ], (string) \filemtime( $directory . 'service-onboarding.js' ), true );
		$config = ServiceOnboarding::config();
		$config['demo'] = false;
		$config['mode'] = ServiceOnboarding::is_required() ? 'setup' : 'settings';
		$config['previewUrl'] = \rest_url( 'nova-service/v1/editor-preview' );
		$config['settingsUrl'] = self::settings_url( 'defaults' );
		$config['advancedUrl'] = \add_query_arg( 'view', 'advanced', self::settings_url( 'setup' ) );
		$config['previewTemplate'] = ServiceShared::get_template() ?: ServiceDocument::default_layout( ServiceShared::get_presets() );
		$config['language'] = ServiceLanguages::current();
		$config['isTranslated'] = ServiceLanguages::is_translated();
		$config['initialStep'] = isset( $_GET['step'] ) && \is_scalar( $_GET['step'] ) && \in_array( (string) $_GET['step'], [ 'business', 'cta', 'content', 'style', 'review' ], true ) ? (string) $_GET['step'] : ( ServiceOnboarding::is_required() ? ( $config['state']['step'] ?? 'business' ) : 'business' );
		$config['createUrl'] = \admin_url( 'post-new.php?post_type=service_page' );
		if ( \is_file( $directory . 'branding/inspace-wordmark.svg' ) ) { $config['logoUrl'] = $url . 'branding/inspace-wordmark.svg'; }
		\wp_localize_script( 'nova-service-onboarding', 'novaServiceOnboarding', $config );
	}

	public static function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) { return; }
		$mode = self::mode();
		$setup = \class_exists( __NAMESPACE__ . '\\ServiceOnboarding' );
		$required = $setup && ServiceOnboarding::is_required();
		$tabs = $required ? [ 'setup' => __( 'Setup', 'nova-bridge-suite' ) ] : [ 'defaults' => __( 'Page defaults', 'nova-bridge-suite' ), 'setup' => __( 'Setup settings', 'nova-bridge-suite' ) ];
		if ( ! $required && self::has_legacy_pages() ) { $tabs['legacy'] = __( 'Legacy settings', 'nova-bridge-suite' ); }
		?>
		<div class="wrap nova-service-settings <?php echo 'legacy' === $mode ? '' : 'nova-service-settings--native'; ?>">
			<?php if ( 'legacy' !== $mode ) : ?>
			<header class="nova-service-settings-header"><img src="<?php echo \esc_url( \plugins_url( 'assets/branding/inspace-wordmark.svg', \dirname( __DIR__ ) . '/service-page-cpt.php' ) ); ?>" alt="InSpace"><div><h1><?php \esc_html_e( 'NOVA Services', 'nova-bridge-suite' ); ?></h1><p><?php \esc_html_e( 'Shared content in setup. One starting layout for your service pages.', 'nova-bridge-suite' ); ?></p></div></header>
			<?php else : ?><h1><?php \esc_html_e( 'Service page settings', 'nova-bridge-suite' ); ?></h1><?php endif; ?>
			<nav class="<?php echo 'legacy' === $mode ? 'nav-tab-wrapper' : 'nova-service-settings-nav'; ?>" aria-label="<?php \esc_attr_e( 'Service settings', 'nova-bridge-suite' ); ?>"><?php foreach ( $tabs as $tab => $label ) : ?><a class="<?php echo 'legacy' === $mode ? 'nav-tab ' . ( $tab === $mode ? 'nav-tab-active' : '' ) : ( $tab === $mode ? 'is-active' : '' ); ?>" <?php if ( $tab === $mode ) { echo 'aria-current="page"'; } ?> href="<?php echo \esc_url( self::settings_url( $tab ) ); ?>"><?php echo \esc_html( $label ); ?></a><?php endforeach; ?></nav>
			<?php if ( 'setup' === $mode && $setup && ! self::advanced_setup() ) : ?>
				<?php self::render_languages( $mode ); ?>
				<div id="nova-service-onboarding"><p><?php \esc_html_e( 'Preparing your service page setup…', 'nova-bridge-suite' ); ?></p></div>
				<noscript><p><?php \esc_html_e( 'Enable JavaScript to complete the service page setup.', 'nova-bridge-suite' ); ?></p></noscript>
			</div>
			<?php return; endif; ?>
			<?php if ( 'legacy' === $mode ) : ?>
				<?php $plugin = Plugin::instance(); if ( $plugin ) { $plugin->render_settings_page( true ); } ?>
			</div>
			<?php return; endif; ?>
			<div class="nova-service-settings-content">
			<?php self::render_languages( $mode ); ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php \esc_html_e( 'Service settings saved.', 'nova-bridge-suite' ); ?></p></div><?php endif; ?>
			<?php $error = \get_transient( 'nova_service_shared_error_' . \get_current_user_id() ); if ( $error ) { \delete_transient( 'nova_service_shared_error_' . \get_current_user_id() ); echo '<div class="notice notice-error"><p>' . \esc_html( $error ) . '</p></div>'; } ?>
			<?php if ( 'setup' === $mode ) : ?>
				<h2><?php \esc_html_e( 'Advanced shared content', 'nova-bridge-suite' ); ?></h2>
				<p><a class="nova-setup-text-button" href="<?php echo \esc_url( self::settings_url( 'setup' ) ); ?>"><?php \esc_html_e( 'Back to the five setup screens', 'nova-bridge-suite' ); ?></a></p>
				<div id="nova-service-setup-business"></div>
				<p><?php \esc_html_e( 'Manage calls to action, people, locations, reviews, qualifications, forms and videos here. Linked pages follow these settings; local page overrides remain in place. You can keep separate reusable versions for different services.', 'nova-bridge-suite' ); ?></p>
			<?php else : ?>
				<h2><?php \esc_html_e( 'The starting layout for new pages', 'nova-bridge-suite' ); ?></h2>
				<p><?php \esc_html_e( 'Arrange, hide or remove sections and select the shared versions they use. Existing pages keep their saved layout. Rich text and FAQs are filled per service; related services are selected automatically.', 'nova-bridge-suite' ); ?></p>
				<p><a class="nova-setup-text-button" href="<?php echo \esc_url( self::settings_url( 'setup' ) ); ?>"><?php \esc_html_e( 'Edit shared content and its appearance in Setup settings', 'nova-bridge-suite' ); ?></a></p>
			<?php endif; ?>
			<form id="nova-service-settings-form" method="post" action="<?php echo \esc_url( \add_query_arg( 'lang', ServiceLanguages::current(), \admin_url( 'admin-post.php' ) ) ); ?>">
				<input type="hidden" name="action" value="nova_service_save_shared"><input type="hidden" name="mode" value="<?php echo 'setup' === $mode ? 'library' : 'template'; ?>">
				<input type="hidden" name="lang" value="<?php echo \esc_attr( ServiceLanguages::current() ); ?>">
				<input type="hidden" id="nova-service-settings-fingerprint" name="expected_fingerprint" value="<?php echo \esc_attr( ServiceOnboarding::settings_fingerprint() ); ?>">
				<input type="hidden" name="preview_post_id" id="nova-service-preview-post-id" value="<?php echo \esc_attr( self::preview_post_id( $_GET ) ); ?>">
				<?php \wp_nonce_field( 'nova_service_save_shared', '_nova_service_shared_nonce' ); ?>
				<div id="nova-service-editor" class="nova-service-editor" data-mode="v2"><div class="nova-service-message" role="status" aria-live="polite"></div><?php ServiceEditor::render_canvas(); ?></div>
				<?php \submit_button( 'setup' === $mode ? __( 'Save shared content', 'nova-bridge-suite' ) : __( 'Save page defaults', 'nova-bridge-suite' ) ); ?>
				<?php if ( 'defaults' === $mode ) : ?><button type="submit" class="button" name="reset_template" value="1"><?php \esc_html_e( 'Restore the built-in starting layout', 'nova-bridge-suite' ); ?></button><?php endif; ?>
			</form>
			</div>
		</div>
		<?php
	}

	private static function render_languages( string $mode ): void {
		$context = ServiceLanguages::context();
		if ( ! $context['active'] ) { return; }
		echo '<div class="nova-service-settings-language">';
		if ( 'defaults' === $mode ) {
			echo '<p>' . \esc_html__( 'The starting layout applies to every language. Translate shared content in Setup settings.', 'nova-bridge-suite' ) . '</p>';
		} else {
			echo '<p>' . \esc_html__( 'Choose a language for shared text, links and media. Unchanged fields follow the default language. Section order, visibility and appearance apply to every language.', 'nova-bridge-suite' ) . '</p>';
			foreach ( $context['languages'] as $link ) {
				$url = \add_query_arg( 'lang', $link['code'], self::settings_url( 'setup' ) );
				if ( self::advanced_setup() ) { $url = \add_query_arg( 'view', 'advanced', $url ); }
				echo '<a class="button ' . ( ! empty( $link['is_current'] ) ? 'button-primary' : '' ) . '" href="' . \esc_url( $url ) . '">' . \esc_html( $link['label'] . ( ! empty( $link['is_default'] ) ? ' (' . __( 'default', 'nova-bridge-suite' ) . ')' : '' ) ) . '</a> ';
			}
		}
		echo '</div>';
	}

	public static function save(): void {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'You cannot manage service settings.', 'nova-bridge-suite' ), '', [ 'response' => 403 ] ); }
		\check_admin_referer( 'nova_service_save_shared', '_nova_service_shared_nonce' );
		$mode = isset( $_POST['mode'] ) && 'template' === $_POST['mode'] ? 'template' : 'library';
		$lang = isset( $_POST['lang'] ) && \is_scalar( $_POST['lang'] ) ? \sanitize_key( \wp_unslash( (string) $_POST['lang'] ) ) : null;
		$language = ServiceLanguages::validate( $lang );
		$fingerprint = isset( $_POST['expected_fingerprint'] ) && \is_string( $_POST['expected_fingerprint'] ) ? \wp_unslash( $_POST['expected_fingerprint'] ) : '';
		$raw = isset( $_POST['nova_service_document'] ) && \is_string( $_POST['nova_service_document'] ) ? \wp_unslash( $_POST['nova_service_document'] ) : '';
		$document = \json_decode( $raw, true );
		if ( \is_wp_error( $language ) ) { $result = $language; }
		elseif ( '' === $fingerprint || ! \hash_equals( ServiceOnboarding::settings_fingerprint(), $fingerprint ) ) { $result = new \WP_Error( 'nova_service_settings_conflict', __( 'Service settings changed in another session. Reload this screen before saving; the current settings were preserved.', 'nova-bridge-suite' ) ); }
		elseif ( 'template' === $mode && ! empty( $_POST['reset_template'] ) ) { $result = ServiceShared::save_template( [] ); }
		elseif ( ! \is_array( $document ) ) { $result = new \WP_Error( 'nova_service_invalid_editor', __( 'The section editor did not supply valid data. Your saved settings were kept.', 'nova-bridge-suite' ) ); }
		else { $result = 'library' === $mode ? ServiceShared::save_presets( $document, $language ) : ServiceShared::save_template( $document ); }
		if ( \is_wp_error( $result ) ) { \set_transient( 'nova_service_shared_error_' . \get_current_user_id(), $result->get_error_message(), 120 ); }
		$url = self::settings_url( $mode, self::preview_post_id( $_POST ) );
		if ( 'library' === $mode ) { $url = \add_query_arg( 'view', 'advanced', $url ); }
		if ( ! \is_wp_error( $language ) && '' !== $language ) { $url = \add_query_arg( 'lang', $language, $url ); }
		\wp_safe_redirect( \is_wp_error( $result ) ? $url : \add_query_arg( 'saved', '1', $url ) );
		exit;
	}

	/** Explicit save from a page; retain reusable defaults without cloning service copy. */
	public static function save_page_template(): void {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_send_json_error( [ 'message' => 'You cannot manage service settings.' ], 403 ); }
		if ( ! \check_ajax_referer( 'nova_service_save_template', '_nonce', false ) ) { \wp_send_json_error( [ 'message' => 'Your session expired. Reload the editor and try again.' ], 403 ); }
		$raw = isset( $_POST['document'] ) && \is_string( $_POST['document'] ) ? \wp_unslash( $_POST['document'] ) : '';
		$document = \json_decode( $raw, true );
		if ( ! \is_array( $document ) ) { \wp_send_json_error( [ 'message' => 'The service layout data is invalid.' ], 400 ); }
		$valid = ServiceDocument::validate_document( $document );
		if ( \is_wp_error( $valid ) ) { \wp_send_json_error( [ 'message' => $valid->get_error_message() ], 400 ); }
		foreach ( $document['sections'] as &$section ) {
			$data = &$section['data'];
			if ( 'hero' === $section['type'] ) { unset( $data['title'], $data['body'] ); }
			if ( 'rich_text' === $section['type'] ) { unset( $data['body'] ); }
			if ( \in_array( $section['type'], [ 'faq', 'related' ], true ) ) { unset( $data['items'], $data['post_ids'], $data['body'] ); }
			// Keep an object-shaped empty value through JSON/storage round trips.
			if ( ! $data && empty( $section['shared_key'] ) ) { $data['body'] = ''; }
		}
		unset( $data, $section );
		$result = ServiceShared::save_template( $document );
		if ( \is_wp_error( $result ) ) { \wp_send_json_error( [ 'message' => $result->get_error_message() ], 400 ); }
		\wp_send_json_success( [ 'message' => 'Site default saved. New service pages will start with this layout. Your current page was not changed.' ] );
	}
}
