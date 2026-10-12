<?php
/** Authenticated, request-local service editor preview. No candidate is persisted. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceLivePreview {
	private static $rendering = false;
	public static function is_rendering(): bool { return self::$rendering; }
	public static function bootstrap(): void { \add_action( 'rest_api_init', [ self::class, 'register_routes' ] ); }
	public static function register_routes(): void {
		\register_rest_route( 'nova-service/v1', '/editor-preview', [ 'methods' => 'POST', 'permission_callback' => [ self::class, 'permission' ], 'callback' => [ self::class, 'preview' ], 'args' => [
			'mode' => [ 'type' => 'string', 'enum' => [ 'page', 'library', 'template' ], 'default' => 'page' ],
			'post_id' => [ 'type' => 'integer', 'minimum' => 0, 'required' => true ],
			'document' => [ 'type' => 'object', 'required' => true ],
			'title' => [ 'type' => 'string', 'maxLength' => 1000 ],
		] ] );
	}
	private static function error( string $message, int $status = 400 ) { return new \WP_Error( 'nova_service_preview_error', $message, [ 'status' => $status ] ); }
	public static function permission( $request ) {
		$mode = (string) ( $request->get_param( 'mode' ) ?: 'page' );
		$id = (int) $request->get_param( 'post_id' );
		if ( ! \get_current_user_id() ) { return self::error( 'Sign in to preview service changes.', 403 ); }
		if ( ! \in_array( $mode, [ 'page', 'library', 'template' ], true ) ) { return self::error( 'Unknown preview mode.' ); }
		if ( 'page' !== $mode && ! \current_user_can( 'manage_options' ) ) { return self::error( 'You cannot preview shared service settings.', 403 ); }
		if ( $id > 0 && ! \current_user_can( 'edit_post', $id ) ) { return self::error( 'You cannot preview this service page.', 403 ); }
		if ( 'page' === $mode && $id <= 0 ) { return self::error( 'A saved service editor target is required.' ); }
		if ( $id > 0 && ( 'service_page' !== \get_post_type( $id ) || 'trash' === \get_post_status( $id ) ) ) { return self::error( 'Select an existing service page.', 404 ); }
		return true;
	}

	/** Resolve draft settings directly in arrays; never install temporary option/meta values. */
	public static function candidate( string $mode, int $post_id, $document ) {
		$valid = ServiceDocument::validate_document( $document );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		$document = ServiceDocument::sanitize_document( $document );
		$presets = ServiceShared::get_presets();
		if ( 'library' === $mode ) {
			foreach ( $document['sections'] as $preset ) { if ( \array_key_exists( 'shared_key', $preset ) ) { return self::error( 'Shared library entries cannot reference other entries.' ); } }
			$presets = $document;
			if ( $post_id ) { $document = ServiceDocument::get_document( $post_id ); }
			else {
				$document = [ 'version' => 2, 'sections' => [] ];
				foreach ( $presets['sections'] as $preset ) { $document['sections'][] = [ 'id' => $preset['id'], 'type' => $preset['type'], 'enabled' => true, 'shared_key' => $preset['id'], 'data' => (object) [] ]; }
			}
		}
		$by_id = \array_column( $presets['sections'], null, 'id' );
		$warnings = [];
		foreach ( $document['sections'] as &$section ) {
			$key = $section['shared_key'] ?? '';
			if ( ! $key ) { continue; }
			$preset = $by_id[ $key ] ?? null;
			if ( ! $preset || $preset['type'] !== $section['type'] ) { $warnings[] = 'Shared section ' . $key . ' is missing or has a different type; only local overrides are shown.'; continue; }
			$section['data'] = \array_replace( (array) $preset['data'], (array) $section['data'] );
			$section['enabled'] = (bool) $section['enabled'] && (bool) $preset['enabled'];
		}
		unset( $section );
		if ( 'template' === $mode && $post_id ) {
			$patched = ServiceDocument::patch_content( $document, ServiceDocument::get_content( $post_id ) );
			$copies = \array_column( $patched['sections'], null, 'id' );
			foreach ( $document['sections'] as &$section ) {
				$fields = 'hero' === $section['type'] ? [ 'title', 'eyebrow', 'body' ] : ( 'rich_text' === $section['type'] ? [ 'title', 'body' ] : ( 'faq' === $section['type'] ? [ 'items' ] : [] ) );
				$data = (array) $section['data'];
				$page_copy = (array) ( $copies[ $section['id'] ]['data'] ?? [] );
				foreach ( $fields as $field ) {
					$current = $data[ $field ] ?? '';
					$empty = \is_array( $current ) ? ! $current : '' === \trim( \str_replace( "\xc2\xa0", ' ', \html_entity_decode( \wp_strip_all_tags( (string) $current ), ENT_QUOTES, 'UTF-8' ) ) );
					if ( $empty && isset( $page_copy[ $field ] ) ) { $data[ $field ] = $page_copy[ $field ]; }
				}
				$section['data'] = $data ?: (object) [];
			}
			unset( $section );
		}
		return [ 'document' => $document, 'warnings' => \array_values( \array_unique( $warnings ) ) ];
	}

	public static function preview( $request ) {
		$allowed = self::permission( $request );
		if ( \is_wp_error( $allowed ) ) { return $allowed; }
		$mode = (string) ( $request->get_param( 'mode' ) ?: 'page' );
		$id = (int) $request->get_param( 'post_id' );
		$candidate = self::candidate( $mode, $id, $request->get_param( 'document' ) );
		if ( \is_wp_error( $candidate ) ) { return $candidate; }
		$title = $request->has_param( 'title' ) ? \sanitize_text_field( $request->get_param( 'title' ) ) : ( $id ? \get_the_title( $id ) : \__( 'Service preview', 'nova-bridge-suite' ) );
		$html = self::frame( $id, $candidate['document'], $title );
		if ( \is_wp_error( $html ) ) { return $html; }
		\preg_match_all( '/data-nova-service-section-id="([^"]*)"/', $html, $ids );
		$warnings = \array_merge( $candidate['warnings'], [ 'Read-only service preview with site CSS. Theme header/footer and active form/calendar/media widgets are omitted; provider shortcodes and review refreshes are not executed.' ] );
		$response = \rest_ensure_response( [ 'html' => $html, 'sections' => \array_map( static function ( $value ) { return \html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ); }, $ids[1] ), 'warnings' => $warnings ] );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}

	/** Queue the active Elementor kit's font assets without CSS regeneration or downloads. */
	private static function enqueue_site_fonts( int $kit_id ): void {
		if ( ! $kit_id || ! \class_exists( '\\Elementor\\Plugin' ) ) { return; }
		$elementor = \Elementor\Plugin::$instance;
		if ( ! $elementor || ! isset( $elementor->kits_manager, $elementor->frontend )
			|| ! \is_callable( [ $elementor->kits_manager, 'get_active_kit_for_frontend' ] )
			|| ! \is_callable( [ $elementor->frontend, 'enqueue_font' ] )
			|| ! \is_callable( [ $elementor->frontend, 'print_fonts_links' ] ) ) { return; }
		$kit = $elementor->kits_manager->get_active_kit_for_frontend();
		if ( ! $kit || ! \is_callable( [ $kit, 'get_settings' ] ) ) { return; }
		// Raw settings include global typography, custom fonts and theme heading/body
		// families. get_settings_for_display would execute dynamic tags unnecessarily.
		$settings = $kit->get_settings();
		if ( ! \is_array( $settings ) ) { return; }
		$families = [];
		$collect = static function ( array $values ) use ( &$collect, &$families ): void {
			foreach ( $values as $key => $value ) {
				if ( \is_array( $value ) ) { $collect( $value ); }
				elseif ( \is_string( $key ) && \preg_match( '/(?:^|_)font_family$/', $key ) && \is_string( $value ) && '' !== \trim( $value ) ) { $families[ \trim( $value ) ] = true; }
			}
		};
		$collect( $settings );
		if ( ! $families ) { return; }
		// Elementor's public font API handles Google and registered custom providers.
		// Its queues are per-frontend-instance, so do not consume or change the caller's.
		$frontend = clone $elementor->frontend;
		foreach ( \array_keys( $families ) as $family ) { $frontend->enqueue_font( $family ); }
		// Elementor checks an existing local font cache before this option. Disabling
		// cache creation for this call preserves cached assets and uses its CDN fallback
		// for missing Google fonts, avoiding option updates and filesystem downloads.
		$read_only_fonts = static function () { return '0'; };
		\add_filter( 'pre_option_elementor_local_google_fonts', $read_only_fonts );
		try { $frontend->print_fonts_links(); }
		finally { \remove_filter( 'pre_option_elementor_local_google_fonts', $read_only_fonts ); }
	}

	/** wp_head callbacks can also consume the original Elementor frontend's queues. */
	private static function snapshot_elementor_fonts(): array {
		if ( ! \class_exists( '\\Elementor\\Plugin' ) || ! \Elementor\Plugin::$instance || ! isset( \Elementor\Plugin::$instance->frontend ) ) { return []; }
		$frontend = \Elementor\Plugin::$instance->frontend;
		if ( ! \is_object( $frontend ) ) { return []; }
		$reflection = new \ReflectionObject( $frontend );
		$properties = [];
		// Elementor 4.1.4 has no public queue snapshot/restore API. Limit reflection
		// to its four font queues, keeping the instance and registered callbacks intact.
		foreach ( [ 'fonts_to_enqueue', 'registered_fonts', 'icon_fonts_to_enqueue', 'enqueued_icon_fonts' ] as $name ) {
			if ( ! $reflection->hasProperty( $name ) ) { continue; }
			$property = $reflection->getProperty( $name );
			if ( PHP_VERSION_ID < 80100 ) { $property->setAccessible( true ); }
			$properties[] = [ $property, $property->getValue( $frontend ) ];
		}
		return [ 'frontend' => $frontend, 'properties' => $properties ];
	}

	private static function restore_elementor_fonts( array $snapshot ): void {
		if ( ! $snapshot ) { return; }
		foreach ( $snapshot['properties'] as $saved ) { $saved[0]->setValue( $snapshot['frontend'], $saved[1] ); }
	}

	/** Use a real target's frontend query/classes, restoring every substituted global in finally. */
	private static function frame( int $post_id, array $document, string $title ) {
		$names = [ 'post', 'wp_query', 'wp_the_query', 'wp_styles', 'wp_scripts' ];
		$before = [];
		foreach ( $names as $name ) { $before[ $name ] = [ \array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null ]; }
		$old_rendering = self::$rendering;
		$elementor_fonts = self::snapshot_elementor_fonts();
		$no_admin_bar = static function () { return false; };
		$read_only_font_cache = static function () { return '0'; };
		self::$rendering = true;
		\add_filter( 'show_admin_bar', $no_admin_bar );
		if ( $elementor_fonts ) { \add_filter( 'pre_option_elementor_local_google_fonts', $read_only_font_cache, PHP_INT_MAX ); }
		try {
			$post = $post_id ? clone \get_post( $post_id ) : new \WP_Post( (object) [ 'ID' => 0, 'post_type' => 'service_page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '', 'post_excerpt' => '', 'post_author' => 0, 'post_password' => '' ] );
			$post->post_title = $title;
			$query = new \WP_Query();
			$query->posts = [ $post ]; $query->post = $post; $query->post_count = 1; $query->current_post = -1;
			$query->queried_object = $post; $query->queried_object_id = $post_id;
			$query->is_singular = true; $query->is_single = true; $query->is_home = false; $query->is_page = false;
			$query->query_vars = [ 'p' => $post_id, 'post_type' => 'service_page' ];
			$GLOBALS['post'] = $post; $GLOBALS['wp_query'] = $query; $GLOBALS['wp_the_query'] = $query;
			foreach ( [ 'wp_styles', 'wp_scripts' ] as $name ) {
				if ( \is_object( $GLOBALS[ $name ] ?? null ) ) {
					$GLOBALS[ $name ] = clone $GLOBALS[ $name ];
					foreach ( $GLOBALS[ $name ]->registered ?? [] as $handle => $entry ) { $GLOBALS[ $name ]->registered[ $handle ] = clone $entry; }
				}
			}
			$content = ServiceRenderer::render_candidate( $post_id, $document, $title );
			$kit = (int) \get_option( 'elementor_active_kit', 0 );
			if ( $kit && \function_exists( 'wp_upload_dir' ) ) {
				$uploads = \wp_upload_dir( null, false );
				$file = '/elementor/css/post-' . $kit . '.css';
				if ( \is_file( $uploads['basedir'] . $file ) ) { \wp_enqueue_style( 'nova-service-preview-site-kit', $uploads['baseurl'] . $file, [], (string) \filemtime( $uploads['basedir'] . $file ) ); }
			}
			\ob_start();
			try { self::enqueue_site_fonts( $kit ); \wp_head(); $head = \ob_get_contents(); }
			finally { \ob_end_clean(); }
			$classes = \get_body_class( [ 'nova-service-v2-page', 'nova-service-editor-live-preview' ] );
			if ( $kit ) { $classes[] = 'elementor-kit-' . $kit; }
			$policy = "default-src 'none'; style-src 'self' https: 'unsafe-inline'; img-src 'self' https: data:; font-src 'self' https: data:; script-src 'none'; connect-src 'none'; frame-src 'none'; object-src 'none'; form-action 'none'; base-uri 'self'";
			$frame = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta http-equiv="Content-Security-Policy" content="' . \esc_attr( $policy ) . '"><base href="' . \esc_url( \home_url( '/' ) ) . '"><title>' . \esc_html( $title ) . '</title>' . $head . '</head><body class="' . \esc_attr( \implode( ' ', \array_unique( $classes ) ) ) . '"><main id="nova-service-primary">' . $content . '</main></body></html>';
			return self::visual_html( self::inert_html( $frame ), $document );
		} catch ( \Throwable $error ) {
			return self::error( 'The preview could not be rendered. Your saved page and settings were not changed.', 500 );
		} finally {
			\remove_filter( 'show_admin_bar', $no_admin_bar );
			if ( $elementor_fonts ) { \remove_filter( 'pre_option_elementor_local_google_fonts', $read_only_font_cache, PHP_INT_MAX ); }
			self::restore_elementor_fonts( $elementor_fonts );
			self::$rendering = $old_rendering;
			foreach ( $before as $name => $state ) { if ( $state[0] ) { $GLOBALS[ $name ] = $state[1]; } else { unset( $GLOBALS[ $name ] ); } }
		}
	}

	/** Mark model fields on the inert editor canvas without changing public rendering. */
	public static function visual_html( string $html, array $document ): string {
		$dom = new \DOMDocument(); $previous = \libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET ); }
		finally { \libxml_clear_errors(); \libxml_use_internal_errors( $previous ); }
		$xpath = new \DOMXPath( $dom );
		// User HTML cannot opt itself into an editor control. Only fields below
		// are annotated, after the safe preview has removed executable content.
		foreach ( $xpath->query( '//*[@data-nova-service-field or @data-nova-service-kind]' ) as $node ) {
			$node->removeAttribute( 'data-nova-service-field' ); $node->removeAttribute( 'data-nova-service-kind' );
		}
		$authored = self::visual_class( 'nova-service__rich' ) . ' or ' . self::visual_class( 'nova-service__embed' );
		// Rich text and custom embeds are authored content, including any nested
		// elements that imitate a renderer section. They cannot select another
		// document section through the parent canvas controller.
		foreach ( $xpath->query( '//*[@data-nova-service-section-id and ancestor::*[' . $authored . ']]' ) as $node ) { $node->removeAttribute( 'data-nova-service-section-id' ); }
		$by_id = \array_column( $document['sections'] ?? [], null, 'id' );
		foreach ( $xpath->query( '//section[@data-nova-service-section-id and not(ancestor::*[' . $authored . '])]' ) as $section ) {
			$model = $by_id[ $section->getAttribute( 'data-nova-service-section-id' ) ] ?? null;
			if ( ! $model || ! self::visual_has_class( $section, 'nova-service__section' ) ) { continue; }
			$data = (array) ( $model['data'] ?? [] ); $type = $model['type'] ?? '';
			$heading = self::visual_first( $xpath, './/*[' . self::visual_class( 'nova-service__heading' ) . ' or ' . self::visual_class( 'nova-service__hero-title' ) . ']', $section );
			if ( $heading && ( ! empty( $data['title'] ) || 'hero' === $type ) ) {
				self::visual_mark( $heading, 'hero' === $type && '' === \trim( (string) ( $data['title'] ?? '' ) ) ? '__page_title' : 'title', 'text' );
			}
			if ( ! empty( $data['eyebrow'] ) ) { self::visual_mark( self::visual_first( $xpath, './/*[' . self::visual_class( 'nova-service__eyebrow' ) . ']', $section ), 'eyebrow', 'text' ); }
			// A section's main copy is outside its nested cards, FAQs and embeds.
			$nested = \implode( ' or ', \array_map( [ self::class, 'visual_class' ], [ 'nova-service__card', 'nova-service__credential-disclosure', 'nova-service__faq', 'nova-service__embed', 'nova-service__gallery' ] ) );
			if ( self::visual_rich_has_content( $data['body'] ?? '' ) ) {
				self::visual_mark( self::visual_first( $xpath, './/div[' . self::visual_class( 'nova-service__rich' ) . ' and not(ancestor::*[' . $nested . '])]', $section ), 'body', 'html' );
			}
			if ( \in_array( $type, [ 'hero', 'rich_text', 'people', 'location', 'cta' ], true ) ) { self::visual_images( $xpath, $section, (array) ( $data['images'] ?? [] ) ); }
			if ( \in_array( $type, [ 'hero', 'cta' ], true ) ) {
				$indices = [];
				foreach ( $data['buttons'] ?? [] as $index => $item ) { if ( \is_array( $item ) && '' !== \trim( (string) ( $item['label'] ?? '' ) ) && ! empty( $item['url'] ) ) { $indices[] = (int) $index; } }
				foreach ( $xpath->query( './/div[' . self::visual_class( 'nova-service__actions' ) . ' and not(ancestor::*[' . $authored . '])]/a', $section ) as $index => $node ) {
					if ( isset( $indices[ $index ] ) ) { self::visual_mark( self::visual_label( $dom, $node ), 'buttons.' . $indices[ $index ] . '.label', 'text' ); }
				}
			}
			if ( 'faq' === $type ) { self::visual_faqs( $xpath, $section, (array) ( $data['items'] ?? [] ) ); }
			if ( 'reviews' === $type || 'credentials' === $type ) { self::visual_items( $xpath, $section, (array) ( $data['items'] ?? [] ), $type ); }
			if ( 'location' === $type || 'map' === $type ) {
				self::visual_mark( self::visual_first( $xpath, './/div[' . self::visual_class( 'nova-service__map' ) . ']', $section ), 'map_url', 'settings' );
			}
			if ( 'video' === $type ) { self::visual_mark( self::visual_first( $xpath, './/div[' . self::visual_class( 'nova-service__video' ) . ']', $section ), 'video_url', 'settings' ); }
		}
		// Without a hero, the renderer owns a direct article header for the
		// WordPress title. It is a virtual canvas target, never a document section.
		foreach ( $xpath->query( '//article[@id="nova-service-v2" and not(ancestor::*[' . $authored . '])]/header[' . self::visual_class( 'nova-service__title' ) . ']/h1' ) as $heading ) {
			$heading->parentNode->setAttribute( 'data-nova-service-section-id', '__page_title' );
			self::visual_mark( $heading, '__page_title', 'text' );
		}
		foreach ( \iterator_to_array( $dom->childNodes ) as $node ) { if ( XML_PI_NODE === $node->nodeType ) { $dom->removeChild( $node ); } }
		return $dom->saveHTML();
	}

	private static function visual_class( string $name ): string { return 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")'; }
	private static function visual_has_class( \DOMElement $node, string $name ): bool { return false !== \strpos( ' ' . \preg_replace( '/\s+/', ' ', $node->getAttribute( 'class' ) ) . ' ', ' ' . $name . ' ' ); }
	private static function visual_first( \DOMXPath $xpath, string $query, \DOMNode $context ): ?\DOMElement {
		$node = $xpath->query( $query, $context )->item( 0 ); return $node instanceof \DOMElement ? $node : null;
	}
	private static function visual_mark( ?\DOMElement $node, string $path, string $kind ): void {
		if ( $node ) { $node->setAttribute( 'data-nova-service-field', $path ); $node->setAttribute( 'data-nova-service-kind', $kind ); }
	}
	private static function visual_rich_has_content( $value ): bool {
		$value = (string) $value;
		$plain = \html_entity_decode( \strip_tags( $value ), ENT_QUOTES, 'UTF-8' );
		return '' !== \trim( \str_replace( "\xc2\xa0", ' ', $plain ) ) || (bool) \preg_match( '/<(?:img|video|audio|table|hr)\b/i', $value );
	}

	/** Isolate button label text from its decorative arrow in the preview only. */
	private static function visual_label( \DOMDocument $dom, \DOMElement $node ): ?\DOMElement {
		$label = null;
		foreach ( \iterator_to_array( $node->childNodes ) as $child ) {
			if ( XML_TEXT_NODE !== $child->nodeType ) { continue; }
			if ( ! $label ) { $label = $dom->createElement( 'span' ); $node->insertBefore( $label, $child ); }
			$label->appendChild( $child );
		}
		return $label;
	}

	private static function visual_images( \DOMXPath $xpath, \DOMElement $section, array $images ): void {
		$used = [];
		foreach ( $xpath->query( './/div[' . self::visual_class( 'nova-service__gallery' ) . ' and not(ancestor::*[' . self::visual_class( 'nova-service__rich' ) . ' or ' . self::visual_class( 'nova-service__embed' ) . '])]/figure/img', $section ) as $node ) {
			foreach ( $images as $index => $image ) {
				if ( ! \is_array( $image ) || isset( $used[ $index ] ) ) { continue; }
				$url = (string) ( $image['url'] ?? '' );
				$attachment = ! empty( $image['id'] ) && \function_exists( 'wp_get_attachment_image_url' ) ? (string) \wp_get_attachment_image_url( (int) $image['id'], 'large' ) : '';
				if ( $node->getAttribute( 'src' ) !== $url && ( '' === $attachment || $node->getAttribute( 'src' ) !== $attachment ) ) { continue; }
				self::visual_mark( $node, 'images.' . (int) $index, 'image' ); $used[ $index ] = true; break;
			}
		}
	}

	private static function visual_faqs( \DOMXPath $xpath, \DOMElement $section, array $items ): void {
		$indices = [];
		foreach ( $items as $index => $item ) {
			if ( \is_array( $item ) && '' !== \trim( (string) ( $item['question'] ?? '' ) ) && '' !== \trim( \strip_tags( (string) ( $item['answer'] ?? '' ) ) ) ) { $indices[] = (int) $index; }
		}
		foreach ( $xpath->query( './/details[' . self::visual_class( 'nova-service__faq' ) . ' and not(ancestor::*[' . self::visual_class( 'nova-service__rich' ) . ' or ' . self::visual_class( 'nova-service__embed' ) . '])]', $section ) as $index => $node ) {
			if ( ! isset( $indices[ $index ] ) ) { continue; }
			$prefix = 'items.' . $indices[ $index ] . '.';
			self::visual_mark( self::visual_first( $xpath, './summary/span[not(' . self::visual_class( 'nova-service__faq-icon' ) . ')]', $node ), $prefix . 'question', 'text' );
			self::visual_mark( self::visual_first( $xpath, './div[' . self::visual_class( 'nova-service__rich' ) . ']', $node ), $prefix . 'answer', 'html' );
		}
	}

	private static function visual_items( \DOMXPath $xpath, \DOMElement $section, array $items, string $type ): void {
		$indices = [];
		foreach ( $items as $index => $item ) {
			if ( ! \is_array( $item ) ) { continue; }
			$visible = self::visual_rich_has_content( $item['body'] ?? '' );
			if ( 'credentials' === $type ) { $visible = $visible || '' !== \trim( (string) ( $item['title'] ?? '' ) ) || ! empty( $item['image'] ); }
			if ( $visible ) { $indices[] = (int) $index; }
		}
		$authored = self::visual_class( 'nova-service__rich' ) . ' or ' . self::visual_class( 'nova-service__embed' );
		$query = 'reviews' === $type ? './/figure[' . self::visual_class( 'nova-service__review' ) . ' and not(ancestor::*[' . $authored . '])]' : './/*[(' . self::visual_class( 'nova-service__credential' ) . ' or ' . self::visual_class( 'nova-service__credential-disclosure' ) . ') and not(ancestor::*[' . $authored . '])]';
		foreach ( $xpath->query( $query, $section ) as $index => $node ) {
			if ( ! isset( $indices[ $index ] ) ) { continue; }
			$item_index = $indices[ $index ]; $item = $items[ $item_index ]; $prefix = 'items.' . $item_index . '.';
			self::visual_mark( self::visual_first( $xpath, './/div[' . self::visual_class( 'nova-service__rich' ) . ']', $node ), $prefix . 'body', 'html' );
			if ( ! empty( $item['title'] ) ) { self::visual_mark( self::visual_first( $xpath, './/*[' . self::visual_class( 'nova-service__card-title' ) . ' and not(ancestor::blockquote)]', $node ), $prefix . 'title', 'text' ); }
			if ( 'reviews' === $type ) {
				if ( ! empty( $item['author'] ) ) { self::visual_mark( self::visual_first( $xpath, './/*[' . self::visual_class( 'nova-service__testimonial-attribution' ) . ' and not(ancestor::blockquote)]//strong | ./figcaption/strong | ./figcaption/a/strong', $node ), $prefix . 'author', 'text' ); }
			} else {
				if ( ! empty( $item['issuer'] ) ) { self::visual_mark( self::visual_first( $xpath, './/*[' . self::visual_class( 'nova-service__credential-issuer' ) . ' or ' . self::visual_class( 'nova-service__credential-word' ) . ']', $node ), $prefix . 'issuer', 'text' ); }
				$summary = self::visual_first( $xpath, './/span[' . self::visual_class( 'nova-service__credential-summary-label' ) . ']/span[not(' . self::visual_class( 'nova-service__credential-issuer' ) . ')]', $node );
				if ( $summary && ( ! empty( $item['title'] ) || ! empty( $item['issuer'] ) ) ) { self::visual_mark( $summary, $prefix . ( ! empty( $item['title'] ) ? 'title' : 'issuer' ), 'text' ); }
			}
		}
	}

	/** A srcdoc preview is inert even if a theme printed scripts or a plugin CTA contains controls. */
	public static function inert_html( string $html ): string {
		if ( ! \class_exists( '\DOMDocument' ) ) { throw new \RuntimeException( 'The safe preview requires the PHP DOM extension.' ); }
		$dom = new \DOMDocument(); $previous = \libxml_use_internal_errors( true );
		try { $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET ); }
		finally { \libxml_clear_errors(); \libxml_use_internal_errors( $previous ); }
		$xpath = new \DOMXPath( $dom );
		foreach ( \iterator_to_array( $xpath->query( '//script|//object|//embed|//meta[translate(@http-equiv,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="refresh"]' ) ) as $node ) { $node->parentNode->removeChild( $node ); }
		foreach ( \iterator_to_array( $xpath->query( '//iframe|//video|//audio' ) ) as $node ) {
			$placeholder = $dom->createElement( 'p', 'Embedded media: read-only preview placeholder.' );
			$placeholder->setAttribute( 'class', 'nova-service__preview-placeholder' );
			$node->parentNode->replaceChild( $placeholder, $node );
		}
		foreach ( $xpath->query( '//*' ) as $node ) {
			foreach ( \iterator_to_array( $node->attributes ) as $attribute ) { if ( 0 === \stripos( $attribute->name, 'on' ) ) { $node->removeAttribute( $attribute->name ); } }
			if ( 'a' === $node->tagName ) { foreach ( [ 'href', 'target', 'download', 'ping' ] as $name ) { $node->removeAttribute( $name ); } }
			if ( 'form' === $node->tagName ) { $node->removeAttribute( 'action' ); $node->setAttribute( 'inert', '' ); }
			if ( \in_array( $node->tagName, [ 'input', 'button', 'select', 'textarea' ], true ) ) { $node->setAttribute( 'disabled', 'disabled' ); $node->removeAttribute( 'formaction' ); }
		}
		foreach ( \iterator_to_array( $dom->childNodes ) as $node ) { if ( XML_PI_NODE === $node->nodeType ) { $dom->removeChild( $node ); } }
		return $dom->saveHTML();
	}
}
