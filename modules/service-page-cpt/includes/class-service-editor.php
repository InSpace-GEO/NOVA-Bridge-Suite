<?php
/** Native, structured editor for the opt-in service page experience. */

namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceEditor {
	private const NONCE_ACTION = 'nova_service_editor_save';
	private const NONCE_FIELD  = '_nova_service_editor_nonce';
	private const FORM_FIELD   = 'nova_service_document';

	public static function bootstrap(): void {
		\add_action( 'add_meta_boxes_service_page', [ self::class, 'register_metabox' ] );
		\add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
		\add_action( 'save_post_service_page', [ self::class, 'save' ], 30, 3 );
		\add_action( 'admin_notices', [ self::class, 'save_notice' ] );
		\add_filter( 'use_block_editor_for_post_type', [ self::class, 'use_native_editor' ], 200, 2 );
		\add_filter( 'gutenberg_can_edit_post_type', [ self::class, 'use_native_editor' ], 200, 2 );
		\add_filter( 'use_block_editor_for_post', [ self::class, 'use_native_editor_for_post' ], 200, 2 );
	}

	public static function use_native_editor( bool $use, string $post_type ): bool {
		if ( 'service_page' !== $post_type ) {
			return $use;
		}
		global $post;
		$current = $post instanceof \WP_Post ? $post : null;
		if ( ! $current && isset( $_GET['post'] ) ) {
			$current = \get_post( \absint( $_GET['post'] ) );
		}
		if ( $current instanceof \WP_Post ) {
			return self::use_native_editor_for_post( $use, $current );
		}
		// No saved page on post-new.php: new service pages start with the native editor.
		return isset( $_GET['post_type'] ) && 'service_page' === $_GET['post_type'] ? false : $use;
	}

	public static function use_native_editor_for_post( bool $use, \WP_Post $post ): bool {
		return 'service_page' === $post->post_type && ( 'auto-draft' === $post->post_status || ServiceDocument::is_v2( $post->ID ) ) ? false : $use;
	}

	public static function register_metabox( \WP_Post $post ): void {
		\add_meta_box( 'nova-service-editor-v2', __( 'NOVA service page', 'nova-bridge-suite' ), [ self::class, 'render' ], 'service_page', 'normal', 'high' );
	}

	public static function enqueue_assets(): void {
		$screen = \get_current_screen();
		if ( ! $screen || 'service_page' !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$is_new = 'auto-draft' === $post->post_status;
		$is_v2  = ServiceDocument::is_v2( $post->ID ) || $is_new;
		$setup_required = $is_v2 && ServiceOnboarding::is_required();
		$document = $is_new ? ServiceDocument::default_document( $post->ID ) : ServiceDocument::get_document( $post->ID );
		self::enqueue_document_assets( [
			'postId'       => $post->ID,
			'mode'         => 'page',
			'isV2'         => $is_v2,
			'isNew'        => $is_new,
			'visualEditor' => $is_v2,
			'saveLabel'    => $setup_required ? __( 'Save draft', 'nova-bridge-suite' ) : ( in_array( $post->post_status, [ 'publish', 'private', 'future' ], true ) ? __( 'Update page', 'nova-bridge-suite' ) : ( \current_user_can( \get_post_type_object( 'service_page' )->cap->publish_posts ) ? __( 'Publish page', 'nova-bridge-suite' ) : __( 'Submit for review', 'nova-bridge-suite' ) ) ),
			'saveAction'   => $setup_required ? 'save-post' : 'publish',
			'onboardingRequired' => $setup_required,
			'onboardingUrl' => ServiceOnboarding::setup_url(),
			'document'     => $document,
			'sections'     => ServiceDocument::section_registry(),
			'restUrl'      => \rest_url( 'nova-service/v1/pages/' . $post->ID . '/migration' ),
			'restNonce'    => \wp_create_nonce( 'wp_rest' ),
			'canUseHtml'   => \current_user_can( 'unfiltered_html' ),
			'editUrl'      => \get_edit_post_link( $post->ID, 'raw' ),
			'previewUrl'   => self::preview_url( $post->ID ),
			'servicesUrl'  => \rest_url( 'wp/v2/' . ( \get_post_type_object( 'service_page' )->rest_base ?: 'service_page' ) ),
			'presets'      => ServiceShared::get_presets( $post->ID ),
			'language'     => ServiceLanguages::current( $post->ID ),
			'canManage'    => \current_user_can( 'manage_options' ),
			'sharedUrl'    => ServiceSharedAdmin::settings_url( 'setup', $post->ID ),
			'templateActionUrl' => \admin_url( 'admin-post.php' ),
			'templateNonce' => \wp_create_nonce( 'nova_service_save_template' ),
			'importerUrl'  => \rest_url( 'nova-service/v1/reviews/import' ),
			'editorPreviewUrl' => \rest_url( 'nova-service/v1/editor-preview' ),
			'previewPostId' => $post->ID,
			'previewTargets' => [ [ 'id' => $post->ID, 'title' => \get_the_title( $post ) ?: __( 'New service page', 'nova-bridge-suite' ), 'url' => \get_permalink( $post ) ] ],
		] );
	}

	/** Reuse the same structured editor for the shared library and site layout. */
	public static function enqueue_document_assets( array $config ): void {
		\wp_enqueue_media();
		\wp_enqueue_editor();
		$directory = \dirname( __DIR__ ) . '/assets/';
		$url       = \plugins_url( 'assets/', \dirname( __DIR__ ) . '/service-page-cpt.php' );
		\wp_enqueue_style( 'nova-service-editor-v2', $url . 'service-editor-v2.css', [], (string) \filemtime( $directory . 'service-editor-v2.css' ) );
		\wp_enqueue_script( 'nova-service-editor-v2', $url . 'service-editor-v2.js', [ 'jquery', 'jquery-ui-sortable', 'wp-api-fetch', 'editor', 'wp-util' ], (string) \filemtime( $directory . 'service-editor-v2.js' ), true );
		\wp_localize_script( 'nova-service-editor-v2', 'novaServiceEditor', $config );
		if ( ! empty( $config['visualEditor'] ) && 'page' === ( $config['mode'] ?? '' ) ) {
			\wp_enqueue_style( 'nova-service-visual-editor-v2', $url . 'service-visual-editor-v2.css', [ 'nova-service-editor-v2' ], (string) \filemtime( $directory . 'service-visual-editor-v2.css' ) );
			\wp_enqueue_script( 'nova-service-visual-editor-v2', $url . 'service-visual-editor-v2.js', [ 'nova-service-editor-v2' ], (string) \filemtime( $directory . 'service-visual-editor-v2.js' ), true );
		}
	}

	public static function render_canvas(): void {
		?>
		<input type="hidden" id="nova-service-document" name="nova_service_document" value="">
		<div class="nova-service-workspace">
		<div class="nova-service-edit-pane">
		<div class="nova-service-toolbar">
			<label for="nova-service-add-type"><?php \esc_html_e( 'Add section', 'nova-bridge-suite' ); ?></label>
			<select id="nova-service-add-type"></select>
			<button type="button" class="button button-primary" id="nova-service-add-section"><?php \esc_html_e( 'Add', 'nova-bridge-suite' ); ?></button>
			<button type="button" class="button" id="nova-service-collapse"><?php \esc_html_e( 'Collapse all', 'nova-bridge-suite' ); ?></button>
		</div>
		<div class="nova-service-sections"></div>
		<noscript><p><?php \esc_html_e( 'Enable JavaScript to edit service page sections. Existing sections are preserved when you save without this editor.', 'nova-bridge-suite' ); ?></p></noscript>
		</div>
		<aside class="nova-service-preview-pane" aria-label="<?php \esc_attr_e( 'Live service page preview', 'nova-bridge-suite' ); ?>" hidden></aside>
		</div>
		<?php
	}

	private static function preview_url( int $post_id ): string {
		return \add_query_arg( [ 'nova_service_preview' => 'v2', '_wpnonce' => \wp_create_nonce( 'nova_service_preview_' . $post_id ) ], \get_permalink( $post_id ) );
	}

	public static function render( \WP_Post $post ): void {
		$is_new = 'auto-draft' === $post->post_status;
		$is_v2  = ServiceDocument::is_v2( $post->ID ) || $is_new;
		?>
		<div id="nova-service-editor" class="nova-service-editor" data-mode="<?php echo $is_v2 ? 'v2' : 'legacy'; ?>">
			<div class="nova-service-message" role="status" aria-live="polite"></div>
			<?php if ( ! $is_v2 ) : ?>
				<div class="nova-service-migration">
					<h3><?php \esc_html_e( 'Try the new service page editor', 'nova-bridge-suite' ); ?></h3>
					<p><?php \esc_html_e( 'This page still uses its original template. Preview the new layout and explicitly migrate when you are ready. The original content and settings are kept, so you can return to the legacy layout.', 'nova-bridge-suite' ); ?></p>
					<p><a class="button" data-service-preview href="<?php echo \esc_url( self::preview_url( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php \esc_html_e( 'Preview new layout', 'nova-bridge-suite' ); ?></a> <button type="button" class="button button-primary" data-service-migrate="migrate"><?php \esc_html_e( 'Use new editor for this page', 'nova-bridge-suite' ); ?></button></p>
					<p class="description"><?php \esc_html_e( 'Migration copies supported legacy fields into editable sections. Review the preview, especially embedded forms and custom blocks, before switching.', 'nova-bridge-suite' ); ?></p>
					<ul class="nova-service-migration-warnings"></ul>
				</div>
			<?php else : ?>
				<?php \wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<div class="nova-service-intro">
					<p><?php \esc_html_e( 'One flexible service page: add sections, reorder them, and hide or remove anything you do not need. Put a clear call to action near the top. Empty sections are omitted on the page.', 'nova-bridge-suite' ); ?></p>
					<p class="description"><?php \esc_html_e( 'The WordPress page title is the page heading (H1). Use section headings (H2) for the main topics and H3 for supporting topics. Rich text 1, 2 and 3 are flexible content areas: use meaningful headings and the content this service needs.', 'nova-bridge-suite' ); ?></p>
					<p class="description"><?php \esc_html_e( 'Client defaults supply shared calls to action, forms, reviews, qualifications and media. Leave their Override boxes unchecked to follow the site settings. Related services are selected automatically unless you choose specific links.', 'nova-bridge-suite' ); ?></p>
				</div>
				<?php self::render_canvas(); ?>
				<div class="nova-service-footer">
					<p class="description"><?php \esc_html_e( 'The live preview shows your unsaved changes. Save or update the page to publish them.', 'nova-bridge-suite' ); ?></p>
					<?php if ( \current_user_can( 'manage_options' ) ) : ?><p><a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=service_page&page=nova-service-shared' ) ); ?>"><?php \esc_html_e( 'Manage client defaults and page layout', 'nova-bridge-suite' ); ?></a> <button type="button" class="button" id="nova-service-save-template"><?php \esc_html_e( 'Save this layout as site default', 'nova-bridge-suite' ); ?></button></p><p class="description"><?php \esc_html_e( 'A site default supplies the starting layout for new service pages. Page-specific rich text, FAQs and selected related links are cleared; client defaults remain linked.', 'nova-bridge-suite' ); ?></p><?php endif; ?>
					<?php if ( ! $is_new ) : ?><button type="button" class="button-link" data-service-migrate="legacy"><?php \esc_html_e( 'Return this page to the legacy layout', 'nova-bridge-suite' ); ?></button><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post, bool $update ): void {
		if ( \wp_is_post_autosave( $post_id ) || \wp_is_post_revision( $post_id ) || ( \defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::FORM_FIELD ] ) || ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		$raw = \wp_unslash( $_POST[ self::FORM_FIELD ] );
		// An empty field means JavaScript did not initialize. Never replace saved content.
		if ( ! \is_string( $raw ) || '' === \trim( $raw ) ) {
			return;
		}
		$document = \json_decode( $raw, true );
		if ( ! \is_array( $document ) ) {
			self::save_error( __( 'The service page sections could not be saved because their data was invalid. Your previously saved sections were kept.', 'nova-bridge-suite' ) );
			return;
		}
		$result = ServiceDocument::save_document( $post_id, $document );
		if ( \is_wp_error( $result ) ) {
			self::save_error( $result->get_error_message() );
		}
	}

	private static function save_error( string $message ): void {
		\set_transient( 'nova_service_editor_error_' . \get_current_user_id(), $message, 120 );
	}

	public static function save_notice(): void {
		$message = \get_transient( 'nova_service_editor_error_' . \get_current_user_id() );
		if ( ! $message ) {
			return;
		}
		\delete_transient( 'nova_service_editor_error_' . \get_current_user_id() );
		echo '<div class="notice notice-error"><p>' . \esc_html( $message ) . '</p></div>';
	}
}
