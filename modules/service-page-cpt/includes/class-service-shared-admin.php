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
		return isset( $_GET['tab'] ) && \is_scalar( $_GET['tab'] ) && \in_array( (string) $_GET['tab'], [ 'template', 'legacy', 'setup' ], true ) ? (string) $_GET['tab'] : 'library';
	}

	private static function preview_post_id( array $request ): int {
		return isset( $request['preview_post_id'] ) && \is_scalar( $request['preview_post_id'] ) ? \absint( $request['preview_post_id'] ) : 0;
	}

	public static function settings_url( string $mode = 'library', int $preview_post_id = 0 ): string {
		$args = [ 'post_type' => 'service_page', 'page' => self::PAGE, 'tab' => \in_array( $mode, [ 'library', 'template', 'legacy', 'setup' ], true ) ? $mode : 'library' ];
		$preview_post_id = $preview_post_id ?: self::preview_post_id( $_GET );
		if ( $preview_post_id ) { $args['preview_post_id'] = $preview_post_id; }
		return \add_query_arg( $args, \admin_url( 'edit.php' ) );
	}

	/** Keep old bookmarks and options.php return URLs while exposing one settings entry. */
	public static function redirect_legacy_settings(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && \is_scalar( $_SERVER['REQUEST_METHOD'] ) ? \strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method || \wp_doing_ajax() || ( \defined( 'REST_REQUEST' ) && REST_REQUEST ) ) { return; }
		if ( ! isset( $_GET['page'] ) || ! \is_scalar( $_GET['page'] ) || 'service-cpt' !== (string) $_GET['page'] || ! \current_user_can( 'manage_options' ) ) { return; }
		$target = self::settings_url( 'legacy' );
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
		if ( 'setup' === $mode && \class_exists( __NAMESPACE__ . '\\ServiceOnboarding' ) ) { self::enqueue_setup_assets(); return; }
		if ( 'legacy' === $mode ) { return; }
		$presets = ServiceShared::get_presets();
		$template = ServiceShared::get_template();
		$layout = $template ?: ServiceDocument::default_document();
		ServiceEditor::enqueue_document_assets( \array_merge( [
			'postId' => 0, 'isV2' => true, 'isNew' => true, 'mode' => $mode,
			'document' => 'library' === $mode ? $presets : $layout,
			'sections' => ServiceDocument::section_registry(), 'presets' => $presets,
			'restNonce' => \wp_create_nonce( 'wp_rest' ), 'canManage' => true,
			'canUseHtml' => \current_user_can( 'unfiltered_html' ),
			'servicesUrl' => \rest_url( 'wp/v2/' . ( \get_post_type_object( 'service_page' )->rest_base ?: 'service_page' ) ),
			'importerUrl' => \rest_url( 'nova-service/v1/reviews/import' ),
			'editorPreviewUrl' => \rest_url( 'nova-service/v1/editor-preview' ),
		], self::preview_context( $presets, $layout ) ) );
	}

	private static function enqueue_setup_assets(): void {
		\wp_enqueue_media();
		$directory = \dirname( __DIR__ ) . '/assets/';
		$url = \plugins_url( 'assets/', \dirname( __DIR__ ) . '/service-page-cpt.php' );
		\wp_enqueue_style( 'nova-service-onboarding', $url . 'service-onboarding.css', [], (string) \filemtime( $directory . 'service-onboarding.css' ) );
		\wp_enqueue_script( 'nova-service-onboarding', $url . 'service-onboarding.js', [ 'media-views' ], (string) \filemtime( $directory . 'service-onboarding.js' ), true );
		$config = ServiceOnboarding::config();
		$config['demo'] = 'upgrade' === ( $config['state']['classification'] ?? '' ) || ( isset( $_GET['demo'] ) && \is_scalar( $_GET['demo'] ) && '1' === (string) $_GET['demo'] );
		$config['previewUrl'] = \rest_url( 'nova-service/v1/editor-preview' );
		$config['settingsUrl'] = self::settings_url( 'library' );
		$config['createUrl'] = \admin_url( 'post-new.php?post_type=service_page' );
		if ( \is_file( $directory . 'branding/inspace-wordmark.svg' ) ) { $config['logoUrl'] = $url . 'branding/inspace-wordmark.svg'; }
		\wp_localize_script( 'nova-service-onboarding', 'novaServiceOnboarding', $config );
	}

	public static function render(): void {
		if ( ! \current_user_can( 'manage_options' ) ) { return; }
		$mode = self::mode();
		$setup = \class_exists( __NAMESPACE__ . '\\ServiceOnboarding' );
		$required = $setup && ServiceOnboarding::is_required();
		$tabs = $required ? [ 'setup' => __( 'Setup', 'nova-bridge-suite' ) ] : [ 'library' => __( 'Customer defaults', 'nova-bridge-suite' ), 'template' => __( 'Default page layout', 'nova-bridge-suite' ), 'legacy' => __( 'Legacy settings', 'nova-bridge-suite' ) ];
		if ( $setup && ! $required ) { $tabs['setup'] = __( 'Setup preview', 'nova-bridge-suite' ); }
		?>
		<div class="wrap nova-service-settings">
			<h1><?php \esc_html_e( 'Service page settings', 'nova-bridge-suite' ); ?></h1>
			<nav class="nav-tab-wrapper"><?php foreach ( $tabs as $tab => $label ) : ?><a class="nav-tab <?php echo $tab === $mode ? 'nav-tab-active' : ''; ?>" href="<?php echo \esc_url( self::settings_url( $tab ) ); ?>"><?php echo \esc_html( $label ); ?></a><?php endforeach; ?></nav>
			<?php if ( 'setup' === $mode && $setup ) : ?>
				<div id="nova-service-onboarding"><p><?php \esc_html_e( 'Preparing your service page setup…', 'nova-bridge-suite' ); ?></p></div>
				<noscript><p><?php \esc_html_e( 'Enable JavaScript to complete the service page setup.', 'nova-bridge-suite' ); ?></p></noscript>
			</div>
			<?php return; endif; ?>
			<?php if ( 'legacy' === $mode ) : ?>
				<?php $plugin = Plugin::instance(); if ( $plugin ) { $plugin->render_settings_page( true ); } ?>
			</div>
			<?php return; endif; ?>
			<?php if ( isset( $_GET['saved'] ) ) : ?><div class="notice notice-success"><p><?php \esc_html_e( 'Service settings saved.', 'nova-bridge-suite' ); ?></p></div><?php endif; ?>
			<?php $error = \get_transient( 'nova_service_shared_error_' . \get_current_user_id() ); if ( $error ) { \delete_transient( 'nova_service_shared_error_' . \get_current_user_id() ); echo '<div class="notice notice-error"><p>' . \esc_html( $error ) . '</p></div>'; } ?>
			<?php if ( 'library' === $mode ) : ?>
				<p><?php \esc_html_e( 'Configure the customer content once: the first CTA, repeat CTAs, forms or calendars, awards and certifications, reviews, people, locations, maps and videos. The built-in page layout already links these defaults, so new service pages inherit them without selecting them again. Empty defaults add no content to the page.', 'nova-bridge-suite' ); ?></p>
				<p><?php \esc_html_e( 'Use the Default page layout tab to choose where these sections appear. You can also add reusable variations with unique shared keys. Individual service pages can override a field, select a different shared version, or hide a section when needed.', 'nova-bridge-suite' ); ?></p>
				<p><strong><?php \esc_html_e( 'Changes to a shared section affect every native service page that references it. Local overrides stay unchanged. Removing a shared section hides references without local content until you restore it or choose another section.', 'nova-bridge-suite' ); ?></strong></p>
			<?php else : ?>
				<p><?php \esc_html_e( 'Arrange the starting layout for new service pages. Keep Rich text 1, 2 and 3 as flexible page content, and link calls to action, proof and media to Customer defaults. Related services appear automatically when no specific links are selected. Move, hide or remove anything you do not need. Existing pages retain their saved content and order; linked customer defaults continue to update.', 'nova-bridge-suite' ); ?></p>
			<?php endif; ?>
			<form id="nova-service-settings-form" method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nova_service_save_shared"><input type="hidden" name="mode" value="<?php echo \esc_attr( $mode ); ?>">
				<input type="hidden" name="preview_post_id" id="nova-service-preview-post-id" value="<?php echo \esc_attr( self::preview_post_id( $_GET ) ); ?>">
				<?php \wp_nonce_field( 'nova_service_save_shared', '_nova_service_shared_nonce' ); ?>
				<div id="nova-service-editor" class="nova-service-editor" data-mode="v2"><div class="nova-service-message" role="status" aria-live="polite"></div><?php ServiceEditor::render_canvas(); ?></div>
				<?php \submit_button( 'library' === $mode ? __( 'Save customer defaults', 'nova-bridge-suite' ) : __( 'Save default page layout', 'nova-bridge-suite' ) ); ?>
				<?php if ( 'template' === $mode ) : ?><button type="submit" class="button" name="reset_template" value="1"><?php \esc_html_e( 'Restore the built-in starting layout', 'nova-bridge-suite' ); ?></button><?php endif; ?>
			</form>
		</div>
		<?php
	}

	public static function save(): void {
		if ( ! \current_user_can( 'manage_options' ) ) { \wp_die( \esc_html__( 'You cannot manage service settings.', 'nova-bridge-suite' ), '', [ 'response' => 403 ] ); }
		\check_admin_referer( 'nova_service_save_shared', '_nova_service_shared_nonce' );
		$mode = isset( $_POST['mode'] ) && 'template' === $_POST['mode'] ? 'template' : 'library';
		$raw = isset( $_POST['nova_service_document'] ) && \is_string( $_POST['nova_service_document'] ) ? \wp_unslash( $_POST['nova_service_document'] ) : '';
		$document = \json_decode( $raw, true );
		if ( 'template' === $mode && ! empty( $_POST['reset_template'] ) ) { $result = ServiceShared::save_template( [] ); }
		elseif ( ! \is_array( $document ) ) { $result = new \WP_Error( 'nova_service_invalid_editor', __( 'The section editor did not supply valid data. Your saved settings were kept.', 'nova-bridge-suite' ) ); }
		else { $result = 'library' === $mode ? ServiceShared::save_presets( $document ) : ServiceShared::save_template( $document ); }
		if ( \is_wp_error( $result ) ) { \set_transient( 'nova_service_shared_error_' . \get_current_user_id(), $result->get_error_message(), 120 ); }
		$url = self::settings_url( $mode, self::preview_post_id( $_POST ) );
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
