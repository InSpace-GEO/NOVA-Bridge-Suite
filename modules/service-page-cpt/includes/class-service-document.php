<?php
/** Native, versioned service document and explicit legacy migration. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceDocument {
	public const META_DOCUMENT = '_nova_service_document';
	public const META_MODE = '_nova_service_experience';
	private const CPT = 'service_page';
	private static $bootstrapped = false;
	private static $new_request_legacy = false;
	private static $native_request_creating = false;
	private const CONTENT_SLOTS = [ 'rich_text_1', 'rich_text_2', 'rich_text_3' ];
	private const CONTENT_ALIASES = [ 'offer' => 'rich_text_1', 'how' => 'rich_text_2', 'why' => 'rich_text_3' ];
	private const LAYOUTS = [ 'default', 'split', 'stacked', 'compact', 'cards', 'list', 'badges', 'logos', 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature', 'logo_rail', 'featured_membership', 'credential_tiles', 'trust_band', 'evidence_accordion' ];

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		\add_action( 'init', [ self::class, 'register_meta' ], 20 );
		\add_action( 'rest_api_init', [ self::class, 'register_rest' ] );
		\add_action( 'save_post_service_page', [ self::class, 'initialize_new_post' ], 1, 3 );
		\add_filter( 'rest_pre_insert_service_page', [ self::class, 'validate_rest_insert' ], 1, 2 );
		\add_filter( 'rest_pre_dispatch', [ self::class, 'normalize_rest_request' ], 10, 3 );
		\add_filter( 'rest_prepare_service_page', [ self::class, 'prepare_rest_response' ], 30, 3 );
		\add_action( 'rest_after_insert_service_page', [ self::class, 'reset_rest_flags' ], PHP_INT_MAX, 3 );
	}

	public static function register_meta(): void {
		foreach ( [ self::META_DOCUMENT => 'object', self::META_MODE => 'string' ] as $key => $type ) {
			$args = [
				'type' => $type, 'single' => true, 'show_in_rest' => false,
				'auth_callback' => static function ( $allowed, $meta_key, $post_id ) { return \current_user_can( 'edit_post', (int) $post_id ); },
				'sanitize_callback' => self::META_DOCUMENT === $key ? [ self::class, 'sanitize_document' ] : static function ( $value ) { return 'v2' === $value ? 'v2' : 'legacy'; },
			];
			if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.4', '>=' ) ) { $args['revisions_enabled'] = true; }
			\register_post_meta( self::CPT, $key, $args );
		}
	}

	public static function initialize_new_post( int $post_id, $post, bool $update ): void {
		if ( $update || \wp_is_post_revision( $post_id ) || \wp_is_post_autosave( $post_id ) || self::CPT !== $post->post_type ) {
			return;
		}
		// Existing pages are never switched by an upgrade or an ordinary save.
		\update_post_meta( $post_id, self::META_MODE, self::$new_request_legacy ? 'legacy' : 'v2' );
		if ( ! self::$new_request_legacy ) { \update_post_meta( $post_id, self::META_DOCUMENT, \wp_slash( self::default_document( $post_id ) ) ); }
	}

	public static function reset_rest_flags(): void {
		self::$new_request_legacy = false;
		self::$native_request_creating = false;
	}

	public static function is_v2( int $post_id ): bool {
		return 'v2' === \get_post_meta( $post_id, self::META_MODE, true );
	}

	public static function is_preview( int $post_id ): bool {
		return isset( $_GET['nova_service_preview'], $_GET['_wpnonce'] )
			&& is_scalar( $_GET['nova_service_preview'] ) && is_scalar( $_GET['_wpnonce'] )
			&& 'v2' === (string) $_GET['nova_service_preview']
			&& \current_user_can( 'edit_post', $post_id )
			&& (bool) \wp_verify_nonce( \sanitize_text_field( \wp_unslash( (string) $_GET['_wpnonce'] ) ), 'nova_service_preview_' . $post_id );
	}

	public static function get_document( int $post_id ): array {
		$saved = \get_post_meta( $post_id, self::META_DOCUMENT, true );
		if ( is_array( $saved ) && isset( $saved['sections'] ) ) {
			return self::sanitize_document( $saved );
		}
		return self::is_v2( $post_id ) ? self::default_document( $post_id ) : self::legacy_document( $post_id );
	}

	public static function resolve_document( int $post_id ): array {
		$document = self::get_document( $post_id );
		if ( class_exists( __NAMESPACE__ . '\\ServiceShared' ) ) {
			$document['sections'] = array_map( [ ServiceShared::class, 'resolve_section' ], $document['sections'] );
		}
		return $document;
	}

	public static function section_registry(): array {
		return [
			'hero' => [ 'label' => 'Hero and first CTA', 'description' => 'Clear service offer, supporting image and first conversion action high on the page.' ],
			'rich_text' => [ 'label' => 'Rich text', 'description' => 'A generic rich-text section with an optional heading. Preserve the source content and reading order.' ],
			'people' => [ 'label' => 'People', 'description' => 'Real staff or customer images, with useful alt text and captions.' ],
			'location' => [ 'label' => 'Location images', 'description' => 'One or more genuine images of the business location.' ],
			'map' => [ 'label' => 'Google Maps and address', 'description' => 'Verified address and a Google Maps embed URL; no invented location.' ],
			'reviews' => [ 'label' => 'Reviews and social proof', 'description' => 'Verified customer reviews with attribution and optional source link/rating.' ],
			'credentials' => [ 'label' => 'Awards and certifications', 'description' => 'Verified awards, certifications and qualifications, optionally linking to evidence.' ],
			'cta' => [ 'label' => 'Call to action', 'description' => 'Repeat a relevant conversion action at useful points in the page.' ],
			'custom_cta' => [ 'label' => 'Form or calendar', 'description' => 'Safe custom HTML, approved HTTPS iframe embed, or a supported installed form/calendar shortcode.' ],
			'related' => [ 'label' => 'Related services', 'description' => 'Link cards to published, relevant service_page posts, or manually supplied links.' ],
			'faq' => [ 'label' => 'Frequently asked questions', 'description' => 'Question/answer pairs. Answers support rich text; avoid duplicating them in body sections.' ],
			'video' => [ 'label' => 'Video', 'description' => 'Uploaded site video or a supported YouTube/Vimeo embed, with optional poster image.' ],
		];
	}

	public static function default_document( int $post_id = 0 ): array {
		if ( class_exists( __NAMESPACE__ . '\\ServiceShared' ) ) {
			$template = ServiceShared::get_template();
			if ( isset( $template['sections'] ) ) { return $template; }
		}
		// An empty override follows the WordPress title, including later title edits.
		$sections = [ self::section( 'hero', 'hero', [ 'title' => '', 'body' => '', 'buttons' => [], 'images' => [] ] ) ];
		$presets = class_exists( __NAMESPACE__ . '\\ServiceShared' ) ? array_column( ServiceShared::get_presets()['sections'], null, 'id' ) : [];
		if ( isset( $presets['default_hero'] ) && 'hero' === $presets['default_hero']['type'] ) { $sections[0]['shared_key'] = 'default_hero'; $sections[0]['data'] = [ 'title' => '', 'body' => '' ]; }
		foreach ( self::CONTENT_SLOTS as $slot ) {
			$section = self::section( $slot, 'rich_text', [ 'title' => '', 'body' => '' ] );
			$section['content_slot'] = $slot;
			$sections[] = $section;
		}
		foreach ( [ 'people', 'location', 'map', 'reviews', 'credentials', 'cta', 'custom_cta', 'video', 'related', 'faq' ] as $type ) {
			$section = self::section( $type, $type, [ 'body' => '' ], 'faq' !== $type );
			if ( isset( $presets[ 'default_' . $type ] ) && $type === $presets[ 'default_' . $type ]['type'] ) { $section['shared_key'] = 'default_' . $type; $section['data'] = (object) []; }
			$sections[] = $section;
		}
		return [ 'version' => 2, 'sections' => $sections ];
	}

	private static function section( string $id, string $type, $data, bool $enabled = true ): array {
		return [ 'id' => $id, 'type' => $type, 'enabled' => $enabled, 'data' => $data ];
	}

	public static function document_schema(): array {
		$text = [ 'type' => 'string' ];
		$url = [ 'type' => 'string', 'description' => 'Absolute HTTP(S) URL; button URLs also permit tel:, mailto:, /relative paths and #anchors. Empty clears the value.' ];
		$image = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 0 ], 'url' => $url, 'alt' => $text, 'caption' => $text ] ];
		$item = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [
			'title' => $text, 'body' => $text, 'url' => array_merge( $url, [ 'description' => 'Optional review, evidence or related-card URL. Manually supplied reviews do not require a URL.' ] ), 'image' => $url,
			'rating' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 5 ],
			'author' => $text, 'source' => $text, 'issuer' => [ 'type' => 'string', 'description' => 'Optional credential issuing organization. source remains the separate evidence-link label.' ], 'question' => $text, 'answer' => $text,
			'date' => $text, 'author_url' => array_merge( $url, [ 'description' => 'Optional author profile URL for manually supplied reviews; required live provider attribution is retained when available.' ] ), 'author_image' => $url, 'source_url' => array_merge( $url, [ 'description' => 'Optional publisher/profile URL for manually supplied reviews. A supplied matching provider URL can identify its branding.' ] ),
			'source_logo' => [ 'type' => 'string', 'description' => 'Optional publisher brand logo: HTTPS absolute or safe root-relative image URL. Branding identifies the source; it is not a certification or review verification claim.' ],
			'original_rating' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 10 ], 'original_scale' => [ 'type' => 'integer', 'enum' => [ 5, 10 ] ],
		] ];
		$data = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [
			'title' => $text, 'eyebrow' => $text, 'body' => [ 'type' => 'string', 'description' => 'Safe rich HTML. Preserve original text, headings and complete structural boundaries.' ],
			'heading_level' => [ 'type' => 'string', 'enum' => [ 'h2', 'h3' ] ],
			'layout' => [ 'type' => 'string', 'enum' => self::LAYOUTS, 'description' => 'Optional presentation. Reviews support editorial, conversation, spotlight, publisher_cards, compact_rail and signature. Credentials support logo_rail, featured_membership, editorial, credential_tiles, trust_band and evidence_accordion. Existing layouts remain available.' ],
			'link_sources' => [ 'type' => 'boolean', 'description' => 'Optional clickable source links for manually supplied reviews/provider summaries and evidence image/link anchors for credentials; default true. false keeps branding and credential content. Stored URLs still identify branding; live provider attribution is retained where required.' ],
			'width' => [ 'type' => 'string', 'enum' => [ 'full', 'half' ], 'description' => 'Full row by default. Place two half-row sections next to one another in the section order for a two-column row; mobile uses one column. This presentation field can inherit from a shared section.' ],
			'images' => [ 'type' => 'array', 'maxItems' => 20, 'items' => $image ],
			'buttons' => [ 'type' => 'array', 'maxItems' => 10, 'items' => [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'label' => $text, 'url' => $url ] ] ],
			'items' => [ 'type' => 'array', 'maxItems' => 80, 'items' => $item ],
			'address' => $text, 'map_url' => $url, 'html' => [ 'type' => 'string', 'description' => 'Safe HTML and native form controls. GET/POST forms require HTTPS or site-relative actions. Scripts/events are stripped; iframes require an approved HTTPS provider.' ],
			'shortcode' => [ 'type' => 'string', 'description' => 'A single installed shortcode in the approved form/calendar allowlist.' ],
			'video_url' => [ 'type' => 'string', 'description' => 'HTTPS video uploaded to this site, or an approved YouTube/Vimeo URL. Autoplay is disabled.' ], 'poster' => $url,
			'source_logo' => [ 'type' => 'string', 'description' => 'Optional default publisher logo for a review section: HTTPS absolute or safe root-relative image URL. Shared sections can configure it once; individual cards may override it.' ],
			'review_source' => self::review_source_schema(),
			'post_ids' => [ 'type' => 'array', 'maxItems' => 100, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ],
		] ];
		return [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'version', 'sections' ], 'properties' => [
			'version' => [ 'type' => 'integer', 'enum' => [ 2 ] ],
			'sections' => [ 'type' => 'array', 'maxItems' => 60, 'description' => 'Complete ordered replacement. An empty array intentionally removes every section.', 'items' => [
				'type' => 'object', 'additionalProperties' => false, 'required' => [ 'id', 'type', 'enabled', 'data' ], 'properties' => [
					'id' => [ 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,80}$' ],
					'type' => [ 'type' => 'string', 'enum' => array_keys( self::section_registry() ) ],
					'enabled' => [ 'type' => 'boolean' ], 'data' => $data,
					'shared_key' => [ 'type' => 'string', 'pattern' => '^[a-zA-Z0-9_-]{1,80}$', 'description' => 'Optional reusable site section ID. Missing local data keys inherit; explicit empty values override.' ],
					'content_slot' => [ 'type' => 'string', 'enum' => self::CONTENT_SLOTS, 'description' => 'Stable generic rich-text posting slot, independent of headings and section order.' ],
				],
			] ],
		] ];
	}

	public static function review_source_schema(): array {
		return [ 'type' => 'object', 'additionalProperties' => false, 'description' => 'Verified provider aggregate, independent of selected cards. Empty object explicitly clears an inherited source. Google keeps only live source references and defaults to a compact profile link; an optional map widget requires display widget.', 'properties' => [
			'provider' => [ 'type' => 'string', 'enum' => [ 'trustpilot', 'google', 'webwinkelkeur', 'thuiswinkel', 'kiyoh' ] ],
			'url' => [ 'type' => 'string', 'maxLength' => 4096, 'description' => 'Matching public provider profile URL for an imported source. Manual review cards may omit review_source entirely.' ], 'business_name' => [ 'type' => 'string' ],
			'average_rating' => [ 'type' => 'number', 'minimum' => 0, 'maximum' => 10 ], 'rating_scale' => [ 'type' => 'integer', 'enum' => [ 5, 10 ] ],
			'aggregate_scope' => [ 'type' => 'string', 'enum' => [ 'provider', 'combined' ], 'description' => 'Whether the vendor publishes only its own ratings or a combined score across listed sources.' ],
			'review_count' => [ 'type' => 'integer', 'minimum' => 0 ], 'imported_at' => [ 'type' => 'string', 'format' => 'date-time' ],
			'google_place_id' => [ 'type' => 'string', 'maxLength' => 255 ], 'selected_review_ids' => [ 'type' => 'array', 'maxItems' => 30, 'items' => [ 'type' => 'string', 'maxLength' => 500 ] ],
			'embed_url' => [ 'type' => 'string', 'maxLength' => 4096 ],
			'display' => [ 'type' => 'string', 'enum' => [ 'profile', 'widget' ], 'description' => 'Google display choice. profile is the default compact source link, including older sources with an embed URL. widget explicitly opts into its official map embed.' ],
		] ];
	}

	/** Validate before sanitizing, so malformed requests cannot erase a good document. */
	public static function validate_document( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['version'], $value['sections'] ) || 2 !== $value['version'] || ! is_array( $value['sections'] ) ) {
			return self::error( 'The service document requires version 2 and an ordered sections array.' );
		}
		$value = self::normalize_document_slots( $value );
		$schema = self::document_schema();
		$schema['properties']['sections']['items']['properties']['data']['properties']['intent'] = [ 'type' => 'string', 'enum' => [ 'offer', 'how', 'why', 'general' ] ];
		$result = \rest_validate_value_from_schema( $value, $schema, 'nova_service_document' );
		if ( \is_wp_error( $result ) ) {
			return self::schema_error( $result );
		}
		$ids = [];
		foreach ( $value['sections'] as $section ) {
			if ( in_array( $section['id'], $ids, true ) ) {
				return self::error( 'Section IDs must be unique.' );
			}
			$ids[] = $section['id'];
			$data = (array) $section['data'];
			if ( array_key_exists( 'link_sources', $data ) && ! is_bool( $data['link_sources'] ) ) { return self::error( 'link_sources must be a JSON boolean.' ); }
			foreach ( [ 'images' => [ 'url' ], 'buttons' => [ 'url' ], 'items' => [ 'url', 'image', 'author_url', 'author_image', 'source_url' ] ] as $collection => $fields ) {
				foreach ( $data[ $collection ] ?? [] as $row ) {
					foreach ( $fields as $field ) {
						if ( ! empty( $row[ $field ] ) && ! self::valid_url( $row[ $field ], 'buttons' === $collection ) ) {
							return self::error( 'Invalid URL in ' . $section['id'] . '.' . $collection . '.' . $field . '.' );
						}
					}
				}
			}
			foreach ( $data['items'] ?? [] as $row ) { if ( ! empty( $row['source_logo'] ) && ! self::valid_source_logo_url( $row['source_logo'] ) ) { return self::error( 'Publisher logos require an HTTPS or safe root-relative image URL.' ); } }
			if ( ! empty( $data['source_logo'] ) && ! self::valid_source_logo_url( $data['source_logo'] ) ) { return self::error( 'The default publisher logo requires an HTTPS or safe root-relative image URL.' ); }
			if ( ! empty( $data['map_url'] ) && ! self::valid_map_url( $data['map_url'] ) ) {
				return self::error( 'Use an HTTPS Google Maps embed URL for map_url.' );
			}
			if ( ! empty( $data['video_url'] ) && '' === self::safe_video_url( $data['video_url'] ) ) { return self::error( 'Use an uploaded site HTTPS MP4/WebM or a supported YouTube/Vimeo URL.' ); }
			if ( ! empty( $data['poster'] ) && ! self::valid_url( $data['poster'] ) ) { return self::error( 'Invalid video poster URL.' ); }
			if ( isset( $data['review_source'] ) ) {
				$source = (array) $data['review_source'];
				if ( $source ) {
					if ( empty( $source['provider'] ) || empty( $source['url'] ) || ! class_exists( __NAMESPACE__ . '\\ServiceReviewImporter' ) || ServiceReviewImporter::provider_for_url( $source['url'] ) !== $source['provider'] ) { return self::error( 'Review sources require a matching HTTPS public provider profile URL.' ); }
					if ( isset( $source['average_rating'] ) && $source['average_rating'] > ( $source['rating_scale'] ?? 5 ) ) { return self::error( 'The provider average cannot exceed its published rating scale.' ); }
					if ( 'google' === $source['provider'] && array_intersect( array_keys( $source ), [ 'business_name', 'average_rating', 'rating_scale', 'aggregate_scope', 'review_count', 'imported_at' ] ) ) { return self::error( 'Google review content is live. Store only its profile/widget and selected review identifier references.' ); }
					if ( ! empty( $source['embed_url'] ) && ( 'google' !== $source['provider'] || ! self::valid_map_url( $source['embed_url'] ) ) ) { return self::error( 'Review widgets require a supported HTTPS Google Maps embed URL.' ); }
				}
			}
			if ( ! empty( $data['shortcode'] ) && '' === self::safe_shortcode( $data['shortcode'] ) ) {
				return self::error( 'The shortcode is not an approved, installed form or calendar shortcode.' );
			}
			if ( ! empty( $data['html'] ) && preg_match_all( '/<iframe\b[^>]*>/i', $data['html'], $frames ) ) {
				foreach ( $frames[0] as $frame ) {
					$src = self::html_attribute( $frame, 'src' );
					if ( null === $src || ! self::allowed_embed_url( $src ) ) {
						return self::error( 'The custom CTA iframe must use an approved HTTPS embed provider.' );
					}
				}
			}
			if ( ! empty( $data['html'] ) && preg_match_all( '/<form\b[^>]*>/i', $data['html'], $forms ) ) {
				foreach ( $forms[0] as $form ) {
					if ( ! self::safe_form_tag( $form ) ) { return self::error( 'Custom forms require method GET or POST and an HTTPS or site-relative action URL.' ); }
				}
			}
		}
		return true;
	}

	private static function error( string $message ) {
		return new \WP_Error( 'nova_service_invalid_document', $message, [ 'status' => 400 ] );
	}

	/** Core schema errors lack an HTTP status when returned before dispatch. */
	private static function schema_error( $error ) {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : ( null === $data ? [] : [ 'details' => $data ] );
		$data['status'] = 400;
		$error->add_data( $data );
		return $error;
	}

	private static function valid_url( string $url, bool $action = false ): bool {
		if ( '' === $url ) { return true; }
		if ( preg_match( '/[\x00-\x20\x7f<>\x22\x27`]/', $url ) || false !== strpos( $url, '\\' ) ) { return false; }
		if ( $action && ( preg_match( '/^#[a-zA-Z0-9_-]+$/', $url ) || ( '/' === substr( $url, 0, 1 ) && '//' !== substr( $url, 0, 2 ) ) || preg_match( '/^(mailto:[^<>]+@[^<>]+|tel:\+?[0-9().-]+)$/i', $url ) ) ) { return true; }
		$parts = \wp_parse_url( $url );
		return is_array( $parts ) && ! empty( $parts['host'] ) && in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) && empty( $parts['user'] ) && empty( $parts['pass'] );
	}

	private static function valid_source_logo_url( string $url ): bool {
		return '' === $url || ( self::valid_url( $url, true ) && ( ( '/' === substr( $url, 0, 1 ) && '//' !== substr( $url, 0, 2 ) ) || 'https' === strtolower( \wp_parse_url( $url, PHP_URL_SCHEME ) ?? '' ) ) );
	}

	public static function valid_map_url( string $url ): bool {
		$parts = \wp_parse_url( $url );
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		return self::valid_url( $url ) && 'https' === strtolower( $parts['scheme'] ?? '' )
			&& in_array( $host, [ 'www.google.com', 'google.com', 'maps.google.com' ], true )
			&& ( '/maps/embed' === $path || 0 === strpos( $path, '/maps/embed/' ) || ( '/maps' === $path && false !== strpos( $parts['query'] ?? '', 'output=embed' ) ) );
	}

	public static function safe_video_url( string $url ): string {
		if ( '' === $url || ! self::valid_url( $url ) ) { return ''; }
		$parts = \wp_parse_url( $url );
		if ( 'https' !== strtolower( $parts['scheme'] ?? '' ) ) { return ''; }
		$host = strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		$query = [];
		parse_str( $parts['query'] ?? '', $query );
		if ( in_array( $host, [ 'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be', 'www.youtu.be' ], true ) ) {
			$id = '';
			if ( in_array( $host, [ 'youtu.be', 'www.youtu.be' ], true ) ) { $id = trim( $path, '/' ); }
			elseif ( '/watch' === $path && is_string( $query['v'] ?? null ) ) { $id = $query['v']; }
			elseif ( preg_match( '#^/(?:embed|shorts)/([a-zA-Z0-9_-]{11})/?$#', $path, $match ) ) { $id = $match[1]; }
			return preg_match( '/^[a-zA-Z0-9_-]{11}$/', $id ) ? 'https://www.youtube-nocookie.com/embed/' . $id . '?autoplay=0' : '';
		}
		if ( in_array( $host, [ 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com' ], true ) && preg_match( '#^/(?:video/)?([0-9]{1,12})/?$#', $path, $match ) ) {
			$private = isset( $query['h'] ) && is_string( $query['h'] ) && preg_match( '/^[a-zA-Z0-9]+$/', $query['h'] ) ? '&h=' . rawurlencode( $query['h'] ) : '';
			return 'https://player.vimeo.com/video/' . $match[1] . '?autoplay=0&background=0' . $private;
		}
		$uploads = \wp_upload_dir();
		$base = \wp_parse_url( $uploads['baseurl'] ?? '' );
		$prefix = rtrim( $base['path'] ?? '/wp-content/uploads', '/' ) . '/';
		if ( $host !== strtolower( $base['host'] ?? '' ) || ( $parts['port'] ?? null ) !== ( $base['port'] ?? null ) || 0 !== strpos( $path, $prefix ) || preg_match( '#(?:^|/)\.\.(?:/|$)#', rawurldecode( $path ) ) || ! preg_match( '/\.(mp4|webm)$/i', $path ) ) { return ''; }
		return \esc_url_raw( $url );
	}

	private static function allowed_embed_url( string $url ): bool {
		if ( self::valid_map_url( $url ) ) { return true; }
		$parts = \wp_parse_url( $url );
		$providers = \apply_filters( 'nova_service_embed_providers', [
			'calendly.com' => '/', 'www.calendly.com' => '/', 'cal.com' => '/', 'app.cal.com' => '/',
			'forms.office.com' => '/', 'docs.google.com' => '/forms/', 'form.jotform.com' => '/',
			'airtable.com' => '/embed/', 'share.hsforms.com' => '/', 'forms.hsforms.com' => '/',
			'embed.acuityscheduling.com' => '/', 'app.acuityscheduling.com' => '/',
		] );
		$host = strtolower( $parts['host'] ?? '' );
		return self::valid_url( $url ) && 'https' === strtolower( $parts['scheme'] ?? '' ) && isset( $providers[ $host ] ) && 0 === strpos( $parts['path'] ?? '/', $providers[ $host ] );
	}

	/** Read only a real, whitespace-delimited attribute, including unquoted URLs. */
	private static function html_attribute( string $tag, string $name ): ?string {
		if ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
			$processor = new \WP_HTML_Tag_Processor( $tag );
			if ( ! $processor->next_tag() ) { return null; }
			$value = $processor->get_attribute( $name );
			return null === $value ? null : ( is_string( $value ) ? $value : '' );
		}
		if ( class_exists( '\DOMDocument' ) ) {
			$prior = libxml_use_internal_errors( true );
			$document = new \DOMDocument();
			$document->loadHTML( '<!doctype html><html><body>' . $tag . '</body></html>', LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $prior );
			$body = $document->getElementsByTagName( 'body' )->item( 0 );
			foreach ( $body ? $body->childNodes : [] as $node ) {
				if ( $node instanceof \DOMElement ) { return $node->hasAttribute( $name ) ? $node->getAttribute( $name ) : null; }
			}
			return null;
		}
		if ( ! preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(?:\x22([^\x22]*)\x22|\x27([^\x27]*)\x27|([^\s\x22\x27=<>`]+))/is', $tag, $match ) ) { return null; }
		$value = isset( $match[3] ) && '' !== $match[3] ? $match[3] : ( isset( $match[2] ) && '' !== $match[2] ? $match[2] : ( $match[1] ?? '' ) );
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private static function safe_form_tag( string $tag ): bool {
		$method = self::html_attribute( $tag, 'method' );
		$action = self::html_attribute( $tag, 'action' );
		if ( null !== $method && ! in_array( strtolower( $method ), [ 'get', 'post' ], true ) ) { return false; }
		if ( null === $action || '' === $action ) { return true; }
		if ( ! self::valid_url( $action, true ) ) { return false; }
		return ( '/' === substr( $action, 0, 1 ) && '//' !== substr( $action, 0, 2 ) ) || 'https' === strtolower( \wp_parse_url( $action, PHP_URL_SCHEME ) ?? '' );
	}

	/** Scripts, event handlers and non-provider iframe URLs never survive storage. */
	public static function safe_custom_html( string $html ): string {
		$html = preg_replace( '/<(script|style|object|embed)\b[^>]*>.*?<\/\1\s*>/is', '', $html );
		$html = preg_replace_callback( '/<iframe\b([^>]*)>(?:.*?)<\/iframe\s*>/is', static function ( $matches ) {
			$src = self::html_attribute( $matches[0], 'src' );
			return null !== $src && self::allowed_embed_url( $src ) ? $matches[0] : '';
		}, $html );
		$html = preg_replace_callback( '/<form\b[^>]*>.*?<\/form\s*>/is', static function ( $matches ) {
			return self::safe_form_tag( strstr( $matches[0], '>', true ) . '>' ) ? $matches[0] : '';
		}, $html );
		$allowed = \wp_kses_allowed_html( 'post' );
		$allowed['iframe'] = [ 'src' => true, 'title' => true, 'width' => true, 'height' => true, 'loading' => true, 'allowfullscreen' => true, 'referrerpolicy' => true ];
		$allowed['form'] = [ 'action' => true, 'method' => true, 'id' => true, 'class' => true, 'accept-charset' => true, 'enctype' => true ];
		$allowed['input'] = array_fill_keys( [ 'type', 'name', 'value', 'id', 'class', 'placeholder', 'required', 'disabled', 'checked', 'autocomplete', 'maxlength', 'min', 'max', 'step', 'multiple', 'pattern', 'aria-label', 'aria-describedby' ], true );
		$allowed['select'] = array_fill_keys( [ 'name', 'id', 'class', 'required', 'disabled', 'multiple', 'aria-label', 'aria-describedby' ], true );
		$allowed['option'] = [ 'value' => true, 'selected' => true, 'disabled' => true ];
		$allowed['textarea'] = array_fill_keys( [ 'name', 'id', 'class', 'rows', 'cols', 'required', 'placeholder', 'maxlength', 'aria-label', 'aria-describedby' ], true );
		$allowed['button'] = array_fill_keys( [ 'type', 'name', 'value', 'id', 'class', 'disabled', 'aria-label' ], true );
		$allowed['label'] = [ 'for' => true, 'id' => true, 'class' => true ];
		$html = \wp_kses( $html, $allowed, [ 'http', 'https', 'mailto', 'tel' ] );
		// Catch malformed/self-closing frames as well as paired tags after KSES normalization.
		$html = preg_replace_callback( '/<iframe\b[^>]*>/is', static function ( $matches ) {
			$src = self::html_attribute( $matches[0], 'src' );
			return null !== $src && self::allowed_embed_url( $src ) ? $matches[0] : '';
		}, $html );
		return preg_replace_callback( '/<form\b[^>]*>/is', static function ( $matches ) { return self::safe_form_tag( $matches[0] ) ? $matches[0] : ''; }, $html );
	}

	public static function shortcode_allowlist(): array {
		return (array) \apply_filters( 'nova_service_allowed_shortcodes', [ 'contact-form-7', 'wpforms', 'gravityform', 'fluentform', 'formidable', 'ninja_form', 'forminator_form', 'bookingpress_form', 'ameliabooking', 'ameliacatalog', 'bookly-form' ] );
	}

	public static function safe_shortcode( string $shortcode, bool $require_installed = true ): string {
		$shortcode = trim( $shortcode );
		if ( ! preg_match( '/^\[([a-zA-Z0-9_-]+)(?:\s[^\[\]<>]*)?\s*\/?\]$/', $shortcode, $match ) || ! in_array( $match[1], self::shortcode_allowlist(), true ) || ( $require_installed && ! \shortcode_exists( $match[1] ) ) ) { return ''; }
		return $shortcode;
	}

	public static function sanitize_document( $value ): array {
		$document = [ 'version' => 2, 'sections' => [] ];
		if ( ! is_array( $value ) || ! is_array( $value['sections'] ?? null ) ) { return $document; }
		foreach ( $value['sections'] as $section ) {
			if ( ! is_array( $section ) || ! is_string( $section['type'] ?? null ) || ! isset( self::section_registry()[ $section['type'] ] ) ) { continue; }
			$data = (array) ( $section['data'] ?? [] );
			$shared_key = is_string( $section['shared_key'] ?? null ) ? $section['shared_key'] : '';
			$clean = '' !== $shared_key ? [] : [ 'body' => '' ];
			foreach ( [ 'title', 'eyebrow', 'address' ] as $key ) { if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) { $clean[ $key ] = 'address' === $key ? \sanitize_textarea_field( (string) $data[ $key ] ) : \sanitize_text_field( (string) $data[ $key ] ); } }
			if ( isset( $data['body'] ) && is_string( $data['body'] ) ) { $clean['body'] = \wp_kses_post( $data['body'] ); }
			if ( isset( $data['intent'] ) ) { $clean['intent'] = in_array( $data['intent'], [ 'offer', 'how', 'why' ], true ) ? $data['intent'] : 'general'; }
			if ( isset( $data['heading_level'] ) ) { $clean['heading_level'] = 'h3' === $data['heading_level'] ? 'h3' : 'h2'; }
			if ( isset( $data['layout'] ) ) { $clean['layout'] = in_array( $data['layout'], self::LAYOUTS, true ) ? $data['layout'] : 'default'; }
			if ( array_key_exists( 'link_sources', $data ) && is_bool( $data['link_sources'] ) ) { $clean['link_sources'] = $data['link_sources']; }
			if ( isset( $data['width'] ) ) { $clean['width'] = 'half' === $data['width'] ? 'half' : 'full'; }
			foreach ( [ 'images' => 20, 'buttons' => 10, 'items' => 80 ] as $collection => $limit ) {
				if ( ! isset( $data[ $collection ] ) || ! is_array( $data[ $collection ] ) ) { continue; }
				$clean[ $collection ] = [];
				foreach ( $data[ $collection ] as $row ) {
					if ( ! is_array( $row ) && ! is_object( $row ) ) { continue; }
					$row = (array) $row;
					$allowed = 'images' === $collection ? [ 'id', 'url', 'alt', 'caption' ] : ( 'buttons' === $collection ? [ 'label', 'url' ] : [ 'title', 'body', 'url', 'image', 'rating', 'author', 'source', 'issuer', 'source_logo', 'question', 'answer', 'date', 'author_url', 'author_image', 'source_url', 'original_rating', 'original_scale' ] );
					$item = 'images' === $collection ? [ 'id' => 0, 'url' => '', 'alt' => '', 'caption' => '' ] : ( 'buttons' === $collection ? [ 'label' => '', 'url' => '' ] : [ 'body' => '' ] );
					foreach ( $allowed as $key ) {
						if ( ! array_key_exists( $key, $row ) || ! is_scalar( $row[ $key ] ) ) { continue; }
						if ( 'id' === $key ) { $item[ $key ] = \absint( $row[ $key ] ); }
						elseif ( 'rating' === $key ) { $item[ $key ] = max( 0, min( 5, (float) $row[ $key ] ) ); }
						elseif ( 'original_rating' === $key ) { $item[ $key ] = max( 0, min( 10, (float) $row[ $key ] ) ); }
						elseif ( 'original_scale' === $key ) { $item[ $key ] = 10 === (int) $row[ $key ] ? 10 : 5; }
						elseif ( in_array( $key, [ 'body', 'answer' ], true ) ) { $item[ $key ] = \wp_kses_post( (string) $row[ $key ] ); }
						elseif ( 'source_logo' === $key ) { $item[ $key ] = self::valid_source_logo_url( (string) $row[ $key ] ) ? \esc_url_raw( (string) $row[ $key ], [ 'https' ] ) : ''; }
						elseif ( in_array( $key, [ 'url', 'image', 'author_url', 'author_image', 'source_url' ], true ) ) { $item[ $key ] = self::valid_url( (string) $row[ $key ], 'buttons' === $collection ) ? \esc_url_raw( (string) $row[ $key ], [ 'http', 'https', 'mailto', 'tel' ] ) : ''; }
						else { $item[ $key ] = \sanitize_text_field( (string) $row[ $key ] ); }
					}
					$clean[ $collection ][] = $item;
				}
			}
			if ( isset( $data['map_url'] ) && is_string( $data['map_url'] ) ) { $clean['map_url'] = self::valid_map_url( $data['map_url'] ) ? \esc_url_raw( $data['map_url'] ) : ''; }
			if ( isset( $data['html'] ) && is_string( $data['html'] ) ) { $clean['html'] = self::safe_custom_html( $data['html'] ); }
			// Provider deactivation must not erase a saved CTA on the next unrelated edit.
			if ( isset( $data['shortcode'] ) && is_string( $data['shortcode'] ) ) { $clean['shortcode'] = self::safe_shortcode( $data['shortcode'], false ); }
			if ( isset( $data['video_url'] ) && is_string( $data['video_url'] ) ) { $clean['video_url'] = self::safe_video_url( $data['video_url'] ); }
			if ( isset( $data['poster'] ) && is_string( $data['poster'] ) ) { $clean['poster'] = self::valid_url( $data['poster'] ) ? \esc_url_raw( $data['poster'] ) : ''; }
			if ( isset( $data['source_logo'] ) && is_string( $data['source_logo'] ) ) { $clean['source_logo'] = self::valid_source_logo_url( $data['source_logo'] ) ? \esc_url_raw( $data['source_logo'], [ 'https' ] ) : ''; }
			if ( isset( $data['review_source'] ) ) { $clean['review_source'] = self::sanitize_review_source( $data['review_source'] ); }
			if ( isset( $data['post_ids'] ) && is_array( $data['post_ids'] ) ) { $clean['post_ids'] = array_values( array_unique( array_filter( array_map( 'absint', $data['post_ids'] ) ) ) ); }
			$id = is_scalar( $section['id'] ?? null ) ? (string) $section['id'] : '';
			$entry = self::section( substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $id ), 0, 80 ), $section['type'], $clean ?: (object) [], ! isset( $section['enabled'] ) || (bool) $section['enabled'] );
			if ( '' !== $shared_key ) { $entry['shared_key'] = substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $shared_key ), 0, 80 ); }
			$slot = self::canonical_slot( $section['content_slot'] ?? '' );
			if ( $slot ) { $entry['content_slot'] = $slot; }
			$document['sections'][] = $entry;
		}
		return $document;
	}

	public static function sanitize_review_source( $value ) {
		$value = (array) $value; $source = [];
		if ( ! in_array( $value['provider'] ?? '', [ 'trustpilot', 'google', 'webwinkelkeur', 'thuiswinkel', 'kiyoh' ], true ) ) { return (object) []; }
		$source['provider'] = $value['provider'];
		$source['url'] = isset( $value['url'] ) && is_string( $value['url'] ) && class_exists( __NAMESPACE__ . '\\ServiceReviewImporter' ) ? ServiceReviewImporter::canonical_profile_url( $value['url'] ) : '';
		if ( 'google' === $source['provider'] ) {
			if ( isset( $value['display'] ) ) { $source['display'] = 'widget' === $value['display'] ? 'widget' : 'profile'; }
			if ( isset( $value['google_place_id'] ) && is_string( $value['google_place_id'] ) ) { $source['google_place_id'] = substr( \sanitize_text_field( $value['google_place_id'] ), 0, 255 ); }
			if ( isset( $value['selected_review_ids'] ) && is_array( $value['selected_review_ids'] ) ) { $source['selected_review_ids'] = array_values( array_unique( array_map( static function ( $id ) { return is_scalar( $id ) ? substr( \sanitize_text_field( (string) $id ), 0, 500 ) : ''; }, $value['selected_review_ids'] ) ) ); }
			if ( isset( $value['embed_url'] ) && is_string( $value['embed_url'] ) && self::valid_map_url( $value['embed_url'] ) ) { $source['embed_url'] = \esc_url_raw( $value['embed_url'] ); }
			return $source;
		}
		foreach ( [ 'business_name', 'imported_at' ] as $key ) { if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) ) { $source[ $key ] = \sanitize_text_field( $value[ $key ] ); } }
		$source['rating_scale'] = 10 === ( $value['rating_scale'] ?? 5 ) ? 10 : 5;
		if ( isset( $value['aggregate_scope'] ) && in_array( $value['aggregate_scope'], [ 'provider', 'combined' ], true ) ) { $source['aggregate_scope'] = $value['aggregate_scope']; }
		if ( isset( $value['average_rating'] ) && is_numeric( $value['average_rating'] ) ) { $source['average_rating'] = max( 0, min( $source['rating_scale'], (float) $value['average_rating'] ) ); }
		if ( isset( $value['review_count'] ) && is_numeric( $value['review_count'] ) ) { $source['review_count'] = \absint( $value['review_count'] ); }
		return $source;
	}

	public static function save_document( int $post_id, $document ) {
		if ( ! \current_user_can( 'edit_post', $post_id ) ) { return new \WP_Error( 'rest_forbidden', 'You cannot edit this service page.', [ 'status' => 403 ] ); }
		$valid = self::validate_document( $document );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		\update_post_meta( $post_id, self::META_DOCUMENT, \wp_slash( self::sanitize_document( $document ) ) );
		\update_post_meta( $post_id, self::META_MODE, 'v2' );
		return true;
	}

	public static function register_rest(): void {
		\register_rest_field( self::CPT, 'nova_service_document', [
			'get_callback' => static function ( $object ) { return self::public_document( self::get_document( (int) $object['id'] ) ); },
			'update_callback' => static function ( $value, $post ) { return self::save_document( (int) $post->ID, $value ); },
			// WP treats the top-level field schema's required as a request-parameter
			// flag. Keep this optional; validate_document enforces inner requirements.
			'schema' => array_merge( self::document_schema(), [ 'required' => false, 'context' => [ 'view', 'edit' ], 'description' => 'Native service page. Writing this field explicitly opts this page into v2; omission preserves its document and mode.' ] ),
		] );
		\register_rest_field( self::CPT, 'nova_service_content', [
			'get_callback' => static function ( $object ) { return self::get_content( (int) $object['id'] ); },
			'update_callback' => static function ( $value, $post ) { return self::save_content( (int) $post->ID, $value ); },
			'schema' => array_merge( self::content_schema(), [ 'required' => false, 'context' => [ 'view', 'edit' ], 'description' => 'Generic service rich text and FAQ, with optional per-page overrides. Omitted keys retain client defaults and existing local values. See meta_note and meta_descriptions in the regular response.' ] ),
		] );
		\register_rest_route( 'nova-service/v1', '/pages/(?P<id>\d+)/migration', [
			[ 'methods' => 'GET', 'permission_callback' => [ self::class, 'migration_permission' ], 'callback' => static function ( $request ) { return \rest_ensure_response( self::migration_preview( (int) $request['id'] ) ); } ],
			[ 'methods' => 'POST', 'permission_callback' => [ self::class, 'migration_permission' ], 'callback' => [ self::class, 'apply_migration' ], 'args' => [ 'action' => [ 'type' => 'string', 'enum' => [ 'migrate', 'v2', 'legacy' ], 'default' => 'migrate' ] ] ],
		] );
	}

	public static function validate_rest_insert( $prepared_post, $request ) {
		self::$new_request_legacy = ! $request->get_param( 'id' ) && self::request_uses_legacy_payload( $request );
		self::$native_request_creating = ! $request->get_param( 'id' ) && $request->has_param( 'nova_service_content' );
		if ( $request->has_param( 'nova_service_document' ) ) {
			$valid = self::validate_document( $request->get_param( 'nova_service_document' ) );
			if ( \is_wp_error( $valid ) ) { return $valid; }
		}
		if ( $request->has_param( 'nova_service_content' ) ) {
			$valid = self::validate_content( $request->get_param( 'nova_service_content' ) );
			if ( \is_wp_error( $valid ) ) { return $valid; }
			$id = (int) $request->get_param( 'id' );
			$document = $request->has_param( 'nova_service_document' ) ? $request->get_param( 'nova_service_document' ) : ( $id ? self::get_document( $id ) : self::default_document() );
			$valid = self::validate_override_targets( $document, $request->get_param( 'nova_service_content' ) );
			if ( \is_wp_error( $valid ) ) { return $valid; }
			$valid = self::validate_document( self::patch_content( $document, $request->get_param( 'nova_service_content' ), ! $id ) );
			if ( \is_wp_error( $valid ) ) { return $valid; }
		}
		return $prepared_post;
	}

	public static function content_schema(): array {
		$text = [ 'type' => 'string' ];
		$properties = [ 'hero' => [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'title' => $text, 'eyebrow' => $text, 'body' => $text ] ] ];
		foreach ( self::CONTENT_SLOTS as $slot ) {
			$properties[ $slot ] = [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'title' => $text, 'body' => $text ] ];
		}
		$properties['faq'] = [ 'type' => 'array', 'maxItems' => 80, 'items' => [ 'type' => 'object', 'additionalProperties' => false, 'required' => [ 'question', 'answer' ], 'properties' => [ 'question' => $text, 'answer' => $text ] ] ];
		$data_schema = self::document_schema()['properties']['sections']['items']['properties']['data'];
		$properties['section_overrides'] = [ 'type' => 'object', 'description' => 'Optional patches keyed by an existing nova_service_document section ID. Omission keeps client defaults. data overrides whole fields; empty values clear fields. inherit removes selected local overrides. shared_key changes a same-type client variant, or empty detaches a copy. enabled explicitly changes local visibility.', 'additionalProperties' => [ 'type' => 'object', 'additionalProperties' => false, 'properties' => [ 'data' => $data_schema, 'enabled' => [ 'type' => 'boolean' ], 'shared_key' => [ 'type' => 'string', 'pattern' => '^(?:[a-zA-Z0-9_-]{1,80})?$' ], 'inherit' => [ 'type' => 'array', 'maxItems' => 30, 'items' => [ 'type' => 'string', 'enum' => array_keys( $data_schema['properties'] ) ] ] ] ] ];
		return [ 'type' => 'object', 'additionalProperties' => false, 'properties' => $properties ];
	}

	public static function validate_content( $value ) {
		if ( ! is_array( $value ) && ! is_object( $value ) ) { return self::error( 'nova_service_content must be an object.' ); }
		$value = self::normalize_content_payload( $value );
		if ( \is_wp_error( $value ) ) { return $value; }
		foreach ( (array) ( $value['section_overrides'] ?? [] ) as $override ) {
			$data = (array) ( ( (array) $override )['data'] ?? [] );
			if ( array_key_exists( 'link_sources', $data ) && ! is_bool( $data['link_sources'] ) ) { return self::error( 'link_sources must be a JSON boolean.' ); }
		}
		$schema = self::content_schema();
		// Old requests are accepted internally, without advertising old field names.
		$schema['properties']['related_service_ids'] = [ 'type' => 'array', 'maxItems' => 100, 'items' => [ 'type' => 'integer', 'minimum' => 1 ] ];
		$result = \rest_validate_value_from_schema( $value, $schema, 'nova_service_content' );
		return \is_wp_error( $result ) ? self::schema_error( $result ) : $result;
	}

	/** Content-only posting leaves site-wide selections and omitted fields intact. */
	private static function canonical_slot( $slot ): string { return is_string( $slot ) ? ( self::CONTENT_ALIASES[ $slot ] ?? ( in_array( $slot, self::CONTENT_SLOTS, true ) ? $slot : '' ) ) : ''; }

	private static function normalize_document_slots( array $document ): array {
		if ( ! is_array( $document['sections'] ?? null ) ) { return $document; }
		foreach ( $document['sections'] as &$section ) { if ( is_array( $section ) && isset( $section['content_slot'] ) ) { $slot = self::canonical_slot( $section['content_slot'] ); if ( $slot ) { $section['content_slot'] = $slot; } } }
		unset( $section );
		return $document;
	}

	/** The public document has no obsolete content classification metadata. */
	public static function public_document( array $document ): array {
		$document = self::normalize_document_slots( $document );
		if ( ! is_array( $document['sections'] ?? null ) ) { return $document; }
		foreach ( $document['sections'] as &$section ) {
			if ( ! is_array( $section ) || ! isset( $section['type'] ) || ! is_array( $section['data'] ?? null ) ) { continue; }
			if ( 'rich_text' === $section['type'] && ! isset( $section['content_slot'] ) ) { $slot = self::content_slot( $section ); if ( $slot ) { $section['content_slot'] = $slot; } }
			unset( $section['data']['intent'] );
		}
		unset( $section );
		return $document;
	}

	private static function normalize_content_payload( $value ) {
		if ( ! is_array( $value ) && ! is_object( $value ) ) { return self::error( 'nova_service_content must be an object.' ); }
		$value = (array) $value;
		foreach ( self::CONTENT_ALIASES as $old => $slot ) {
			if ( ! array_key_exists( $old, $value ) ) { continue; }
			$legacy = $value[ $old ];
			if ( is_array( $legacy ) || is_object( $legacy ) ) {
				$legacy = (array) $legacy;
				if ( isset( $legacy['intent'] ) && ! in_array( $legacy['intent'], [ $old, 'general' ], true ) ) { return self::error( 'Invalid legacy content classification.' ); }
				unset( $legacy['intent'] );
			}
			if ( ! array_key_exists( $slot, $value ) ) { $value[ $slot ] = $legacy; }
			unset( $value[ $old ] );
		}
		return $value;
	}

	/** Normalize earlier clients before core argument validation; advertise only the new schema. */
	public static function normalize_rest_request( $result, $server, $request ) {
		if ( null !== $result || ! in_array( $request->get_method(), [ 'POST', 'PUT', 'PATCH' ], true ) ) { return $result; }
		$type = function_exists( 'get_post_type_object' ) ? \get_post_type_object( self::CPT ) : null;
		$base = $type && ! empty( $type->rest_base ) ? $type->rest_base : \get_option( 'service_cpt_slug', 'services' );
		if ( ! preg_match( '#^/wp/v2/' . preg_quote( $base, '#' ) . '(?:/([0-9]+))?/?$#', $request->get_route(), $route_match ) ) { return $result; }
		$id = (int) ( $route_match[1] ?? 0 );
		if ( $request->has_param( 'nova_service_document' ) ) {
			$valid = self::validate_document( $request->get_param( 'nova_service_document' ) );
			if ( \is_wp_error( $valid ) ) { return $valid; }
			$request->set_param( 'nova_service_document', self::public_document( $request->get_param( 'nova_service_document' ) ) );
		}
		if ( ! $request->has_param( 'nova_service_content' ) ) { return $result; }
		$value = self::normalize_content_payload( $request->get_param( 'nova_service_content' ) );
		if ( \is_wp_error( $value ) ) { return $value; }
		$valid = self::validate_content( $value );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		if ( array_key_exists( 'related_service_ids', $value ) ) {
			$document = $request->has_param( 'nova_service_document' ) ? $request->get_param( 'nova_service_document' ) : ( $id ? self::get_document( $id ) : self::default_document() );
			$valid = self::validate_override_targets( $document, $value );
			if ( \is_wp_error( $valid ) ) { return $valid; }
			$request->set_param( 'nova_service_document', self::public_document( self::patch_content( $document, $value, ! $id ) ) );
			unset( $value['related_service_ids'] );
		}
		$request->set_param( 'nova_service_content', $value ?: (object) [] );
		return $result;
	}

	private static function validate_override_targets( array $document, $value ) {
		$value = self::normalize_content_payload( $value );
		if ( \is_wp_error( $value ) ) { return $value; }
		$by_id = array_column( $document['sections'], null, 'id' );
		$presets = class_exists( __NAMESPACE__ . '\\ServiceShared' ) ? array_column( ServiceShared::get_presets()['sections'], null, 'id' ) : [];
		foreach ( (array) ( $value['section_overrides'] ?? [] ) as $id => $override ) {
			if ( ! isset( $by_id[ $id ] ) ) { return self::error( 'Unknown section override ID: ' . $id . '. Use an existing nova_service_document section ID.' ); }
			$override = (array) $override;
			if ( ! empty( $override['shared_key'] ) && ( ! isset( $presets[ $override['shared_key'] ] ) || $presets[ $override['shared_key'] ]['type'] !== $by_id[ $id ]['type'] ) ) { return self::error( 'The shared override must reference an existing client section of the same type: ' . $id . '.' ); }
		}
		return true;
	}

	private static function content_slot( array $section ): string {
		$slot = self::canonical_slot( $section['content_slot'] ?? '' );
		if ( $slot ) { return $slot; }
		if ( preg_match( '/^(?:content_)*(rich_text_[123]|offer|how|why)$/', $section['id'], $match ) ) { return self::canonical_slot( $match[1] ); }
		$data = (array) $section['data'];
		if ( isset( self::CONTENT_ALIASES[ $data['intent'] ?? '' ] ) ) { return self::CONTENT_ALIASES[ $data['intent'] ]; }
		if ( ! empty( $section['shared_key'] ) && class_exists( __NAMESPACE__ . '\\ServiceShared' ) ) {
			// Local intent=general changes the content suggestion, not the preset's
			// original provider slot. It must not disconnect later API refreshes.
			$source = $section;
			$source['data'] = (object) [];
			$source = ServiceShared::resolve_section( $source );
			$intent = ( (array) $source['data'] )['intent'] ?? '';
			if ( isset( self::CONTENT_ALIASES[ $intent ] ) ) { return self::CONTENT_ALIASES[ $intent ]; }
		}
		return '';
	}

	private static function content_slots( array $document ): array {
		$slots = []; $used = [];
		foreach ( $document['sections'] as $index => $section ) { if ( 'rich_text' !== $section['type'] ) { continue; } $slot = self::content_slot( $section ); if ( $slot && ! isset( $used[ $slot ] ) ) { $slots[ $index ] = $slot; $used[ $slot ] = true; } }
		foreach ( $document['sections'] as $index => $section ) { if ( 'rich_text' !== $section['type'] || isset( $slots[ $index ] ) || self::content_slot( $section ) ) { continue; } foreach ( self::CONTENT_SLOTS as $slot ) { if ( ! isset( $used[ $slot ] ) ) { $slots[ $index ] = $slot; $used[ $slot ] = true; break; } } }
		return $slots;
	}

	public static function patch_content( array $document, $value, bool $enable_existing = false ): array {
		$value = self::normalize_content_payload( $value );
		if ( \is_wp_error( $value ) ) { return $document; }
		$document = self::normalize_document_slots( $document );
		$slots = self::content_slots( $document );
		foreach ( array_merge( [ 'hero' ], self::CONTENT_SLOTS, [ 'faq', 'related_service_ids' ] ) as $key ) {
			if ( ! array_key_exists( $key, $value ) ) { continue; }
			$type = in_array( $key, self::CONTENT_SLOTS, true ) ? 'rich_text' : ( 'related_service_ids' === $key ? 'related' : $key );
			$index = null;
			$added = false;
			foreach ( $document['sections'] as $position => $section ) {
				if ( $section['type'] !== $type ) { continue; }
				if ( 'rich_text' !== $type || $key === ( $slots[ $position ] ?? '' ) ) { $index = $position; break; }
			}
			if ( null === $index ) {
				$added = true;
				$ids = array_column( $document['sections'], 'id' );
				$id = $key;
				while ( in_array( $id, $ids, true ) ) { $id = 'content_' . $id; }
				$document['sections'][] = self::section( $id, $type, [], true );
				$index = count( $document['sections'] ) - 1;
			}
			$data = (array) $document['sections'][ $index ]['data'];
			if ( 'faq' === $key ) { $data['items'] = $value[ $key ]; }
			elseif ( 'related_service_ids' === $key ) { $data['post_ids'] = $value[ $key ]; }
			else { $data = array_replace( $data, (array) $value[ $key ] ); }
			$document['sections'][ $index ]['data'] = $data ?: (object) [];
			if ( 'rich_text' === $type ) { $document['sections'][ $index ]['content_slot'] = $key; }
			// Content refreshes preserve intentional hiding on existing pages.
			if ( $added || $enable_existing ) { $document['sections'][ $index ]['enabled'] = true; }
		}
		foreach ( (array) ( $value['section_overrides'] ?? [] ) as $id => $override ) {
			$id = (string) $id;
			foreach ( $document['sections'] as &$section ) {
				if ( $id !== $section['id'] ) { continue; }
				$override = (array) $override;
				if ( array_key_exists( 'shared_key', $override ) ) {
					if ( '' === $override['shared_key'] ) { $resolved = class_exists( __NAMESPACE__ . '\\ServiceShared' ) ? ServiceShared::resolve_section( $section ) : $section; $section['data'] = $resolved['data']; unset( $section['shared_key'] ); }
					else { $section['shared_key'] = $override['shared_key']; }
				}
				if ( ! empty( $override['inherit'] ) ) { $data = (array) $section['data']; foreach ( $override['inherit'] as $key ) { unset( $data[ $key ] ); } $section['data'] = $data ?: (object) []; }
				if ( array_key_exists( 'data', $override ) ) { $section['data'] = array_replace( (array) $section['data'], (array) $override['data'] ) ?: (object) []; }
				if ( array_key_exists( 'enabled', $override ) ) { $section['enabled'] = $override['enabled']; }
				break;
			}
			unset( $section );
		}
		return $document;
	}

	public static function save_content( int $post_id, $value ) {
		$valid = self::validate_content( $value );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		$document = self::get_document( $post_id );
		$valid = self::validate_override_targets( $document, $value );
		if ( \is_wp_error( $valid ) ) { return $valid; }
		return self::save_document( $post_id, self::patch_content( $document, $value, self::$native_request_creating ) );
	}

	public static function get_content( int $post_id ): array {
		return self::content_from_document( $post_id ? self::get_document( $post_id ) : self::default_document() );
	}

	private static function content_from_document( array $document ): array {
		$result = [];
		$slots = self::content_slots( $document );
		foreach ( $document['sections'] as $index => $raw ) {
			$section = class_exists( __NAMESPACE__ . '\\ServiceShared' ) ? ServiceShared::resolve_section( $raw ) : $raw;
			if ( empty( $section['enabled'] ) ) { continue; }
			$data = (array) $section['data'];
			if ( 'hero' === $section['type'] && ! isset( $result['hero'] ) ) { $result['hero'] = array_intersect_key( $data, array_flip( [ 'title', 'eyebrow', 'body' ] ) ) ?: (object) []; }
			elseif ( 'rich_text' === $section['type'] ) {
				$slot = $slots[ $index ] ?? '';
				if ( $slot && ! isset( $result[ $slot ] ) ) { $result[ $slot ] = array_intersect_key( $data, array_flip( [ 'title', 'body' ] ) ) ?: (object) []; }
			}
			elseif ( 'faq' === $section['type'] && ! isset( $result['faq'] ) ) { $result['faq'] = array_map( static function ( $item ) { return [ 'question' => (string) ( $item['question'] ?? '' ), 'answer' => (string) ( $item['answer'] ?? '' ) ]; }, $data['items'] ?? [] ); }
		}
		return $result;
	}

	public static function request_uses_legacy_payload( $request ): bool {
		if ( $request->has_param( 'nova_service_document' ) || $request->has_param( 'nova_service_content' ) ) { return false; }
		foreach ( [ 'meta', 'meta_all', 'meta_all_flat' ] as $field ) {
			foreach ( (array) $request->get_param( $field ) as $key => $value ) {
				if ( 0 === strpos( (string) $key, 'sp_' ) ) { return true; }
			}
		}
		$content = $request->get_param( 'content' );
		if ( is_array( $content ) ) { $content = $content['raw'] ?? ''; }
		if ( is_string( $content ) && '' !== trim( $content ) ) { return true; }
		return false;
	}

	public static function prepare_rest_response( $response, $post, $request ) {
		if ( ! self::is_v2( (int) $post->ID ) ) { return $response; }
		$data = $response->get_data();
		$data['nova_service_experience'] = 'v2';
		$data['nova_service_document'] = self::public_document( self::get_document( (int) $post->ID ) );
		$data['nova_service_content'] = self::get_content( (int) $post->ID );
		$data['meta_note'] = self::meta_note( (int) $post->ID );
		$data['meta_descriptions'] = self::meta_descriptions( (int) $post->ID );
		unset( $data['nova_service_context'] );
		foreach ( [ 'meta', 'meta_all', 'meta_all_flat' ] as $field ) { if ( ! is_array( $data[ $field ] ?? null ) ) { continue; } foreach ( array_keys( $data[ $field ] ) as $key ) { if ( 0 === strpos( (string) $key, 'sp_' ) ) { unset( $data[ $field ][ $key ] ); } } }
		$response->set_data( $data );
		return $response;
	}

	private static function visible_rich_slots( int $post_id = 0 ): array {
		$content = self::get_content( $post_id );
		return array_values( array_filter( array_keys( $content ), static function ( $slot ) { return in_array( $slot, self::CONTENT_SLOTS, true ); } ) );
	}

	public static function meta_descriptions( int $post_id = 0 ): array {
		$content = self::get_content( $post_id ); $descriptions = [];
		if ( array_key_exists( 'hero', $content ) ) { $descriptions['hero'] = 'Optional service introduction: title override, eyebrow and safe rich-text body. Keep an introduction concise, up to 100 words; do not create or summarize new text when redistributing existing content.'; }
		$slots = self::visible_rich_slots( $post_id );
		$percentages = 3 === count( $slots ) ? [ 30, 30, 40 ] : ( 2 === count( $slots ) ? [ 40, 60 ] : [ 100 ] );
		foreach ( $slots as $index => $slot ) { $descriptions[ $slot ] = 'Generic rich text with optional title and safe HTML body. For substantial content, use roughly ' . $percentages[ $index ] . '% of the remaining body here in reading order. Split only at intact heading, paragraph, list or table boundaries; short content may stay in one slot.'; }
		if ( array_key_exists( 'faq', $content ) ) { $descriptions['faq'] = 'Existing or source-supported {question,answer} pairs; answers accept safe rich HTML. Do not duplicate these FAQs in the rich-text sections or invent FAQs while redistributing existing content.'; }
		$descriptions['section_overrides'] = 'All overrides are optional, keyed by an existing document section ID: data, enabled, shared_key and inherit. Omit to follow client defaults. data may contain buttons [{label,url}] for CTAs/hero; html or shortcode for custom CTAs; images [{id,url,alt,caption}] for people/location; address and map_url for maps; items for credentials/reviews, including optional publisher source_logo and credential issuer organization, image, evidence url and separate source link label; section source_logo configures a shared review publisher logo once; review_source for imported proof; video_url and poster for video; title, body, layout, width or heading_level for presentation. Credential layouts support logo_rail, featured_membership, editorial, credential_tiles, trust_band and evidence_accordion, plus existing default/cards/list/badges/logos/compact layouts. Reviews support editorial, conversation, spotlight, publisher_cards, compact_rail and signature, plus existing default/cards/list/compact layouts. Both presentation controls are optional and can inherit from client defaults. Manual reviews may use source text/logo without any item, source or profile URL or review_source. Optional link_sources is a JSON boolean, default true: false hides clickable review source/profile/author links while retaining source branding, and hides credential evidence image/link anchors while retaining credential content. Stored URLs still identify branding and live provider attribution is retained where required. Supplied data keys override whole fields; empty strings/arrays clear fields. inherit removes named local overrides so shared values apply again. Variant keys must match the section type.';
		$descriptions['nova_service_document'] = 'Advanced complete replacement of the ordered version-2 document; include every section to retain. Routine posting should use nova_service_content instead.';
		return $descriptions;
	}

	public static function meta_note( int $post_id = 0 ): string {
		$slots = self::visible_rich_slots( $post_id );
		$segments = [ 'Post generic copy through the top-level nova_service_content object. Every rich-text slot accepts title and body; hero accepts title, eyebrow and body. meta_descriptions documents the fields in this regular GET response.' ];
		if ( array_key_exists( 'hero', self::get_content( $post_id ) ) ) { $segments[] = 'A source-provided introduction in hero.body may be up to 100 words. When redistributing existing text, keep its introduction and wording; do not generate a new introduction.'; }
		if ( 3 === count( $slots ) ) { $segments[] = 'After the introduction, distribute substantial remaining body content roughly 30% in ' . $slots[0] . ', 30% in ' . $slots[1] . ' and 40% in ' . $slots[2] . '.'; }
		elseif ( 2 === count( $slots ) ) { $segments[] = 'After the introduction, distribute substantial remaining body content roughly 40% in ' . $slots[0] . ' and 60% in ' . $slots[1] . '; the second slot should be longer.'; }
		elseif ( 1 === count( $slots ) ) { $segments[] = 'Only ' . $slots[0] . ' is enabled: place 100% of the remaining body content in that slot.'; }
		else { $segments[] = 'No generic rich-text slot is currently enabled; do not place source text into another component. Enable a rich-text section explicitly when needed.'; }
		$segments[] = 'Preserve all existing text and reading order exactly. Split only at complete heading, paragraph, list and table boundaries; keep a heading with its following content. Do not rewrite, summarize, duplicate, omit or invent content merely to reach percentages. Short content may use one slot and leave the others empty; exact percentages are guidance, not a forced split.';
		if ( array_key_exists( 'faq', self::get_content( $post_id ) ) ) { $segments[] = 'Put source-supported FAQ pairs in faq so they render as accordions; do not duplicate them in rich text or create new FAQs while only redistributing existing content.'; }
		$segments[] = 'Disabled content fields are omitted from nova_service_content and meta_descriptions. Client-configured CTAs, forms, media, team, locations, maps, videos and proof apply by default. Related service cards are automatic; routine posting supplies no related IDs. Optional section_overrides can change an existing section’s data, visibility or same-type shared variant for this page, or remove local overrides with inherit. Omitting these overrides retains client defaults.';
		$segments[] = 'Content writes patch only supplied keys. Omitted values and existing visibility stay unchanged; empty strings or arrays intentionally clear supplied fields. New pages inherit the client layout. Write nova_service_document only for a complete structural replacement. Stored legacy fields remain available for rollback but do not drive native rendering.';
		return implode( ' ', $segments );
	}

	public static function context(): array {
		$context = [
			'version' => 2, 'post_type' => self::CPT, 'field' => 'nova_service_document', 'content_field' => 'nova_service_content', 'content_schema' => self::content_schema(), 'sections' => self::section_registry(), 'schema' => self::document_schema(),
			'meta_note' => self::meta_note(), 'meta_descriptions' => self::meta_descriptions(),
			'guidance' => [ self::meta_note(), 'People and location sections accept multiple images. Prefer attachment IDs plus descriptive alt text. Only provide actual source-supported proof and genuine forms or calendars; never create placeholders or unsupported claims.' ],
			'write_semantics' => [
				'omit_document' => 'Omitting both nova_service_document and nova_service_content preserves the current document and experience. Existing legacy pages remain legacy.',
				'provide_document' => 'Providing a valid document replaces the complete section list and explicitly switches this page to v2. Include all sections you want to keep. No implicit deep merge.',
				'provide_content' => 'Prefer nova_service_content for generic rich_text_1/2/3 title/body, hero text and FAQ. Supplied keys patch the current document, or the client layout on new pages. Omitted keys preserve values, visibility and shared references; empty values clear supplied fields. Explicit native writes opt legacy pages into v2 while preserving old content/meta.',
				'optional_overrides' => 'section_overrides is optional and keyed by an existing section ID. data patches local values; inherit removes selected local overrides. shared_key selects a configured same-type variant, while empty shared_key detaches a local copy. enabled explicitly changes local visibility. Unknown IDs or mismatched presets reject the request before saving.',
				'empty_values' => 'Empty strings clear fields, empty arrays clear collections, sections:[] intentionally clears every section; enabled:false hides a section while retaining its data.',
				'new_posts' => 'New service pages inherit the configured site layout and shared sections. Requests supplying legacy sp_* meta or nonempty post_content without either native field remain legacy for older posting integrations. Existing pages require an explicit native write or migration. Legacy post_content and sp_* meta are preserved.',
			],
			'migration' => [ 'preview' => '/nova-service/v1/pages/{id}/migration', 'apply' => 'POST to the same route with action:migrate. Requires edit_post permission and normal WordPress REST authentication/nonce.', 'rollback' => 'POST action:legacy restores legacy rendering while keeping both documents. POST action:v2 restores the saved v2 version. Repeated migration never overwrites an edited v2 document.' ],
			'shortcodes' => self::shortcode_allowlist(),
			'content_example' => [ 'rich_text_1' => [ 'title' => 'First heading', 'body' => '<p>Original first portion of the source.</p>' ], 'rich_text_2' => [ 'title' => 'Second heading', 'body' => '<p>Original next portion of the source.</p>' ], 'rich_text_3' => [ 'title' => 'Final heading', 'body' => '<p>Original remaining portion of the source.</p>' ] ],
			'example' => [ 'version' => 2, 'sections' => [ self::section( 'hero', 'hero', [ 'title' => '', 'body' => '' ] ), self::section( 'rich_text_1', 'rich_text', [ 'title' => 'First heading', 'body' => '<p>Original source content.</p>' ] ) ] ],
		];
		if ( class_exists( __NAMESPACE__ . '\\ServiceShared' ) ) { $context['shared'] = ServiceShared::context(); }
		if ( class_exists( __NAMESPACE__ . '\\ServiceReviewImporter' ) ) { $context['review_import'] = ServiceReviewImporter::context(); }
		return $context;
	}

	public static function migration_permission( $request ) {
		$id = (int) $request['id'];
		$post = \get_post( $id );
		if ( ! $post || self::CPT !== $post->post_type ) { return new \WP_Error( 'rest_post_invalid_id', 'Service page not found.', [ 'status' => 404 ] ); }
		return \current_user_can( 'edit_post', $id ) ? true : new \WP_Error( 'rest_forbidden', 'You cannot edit this service page.', [ 'status' => 403 ] );
	}

	public static function migration_preview( int $post_id ): array {
		$warnings = [];
		$saved = \get_post_meta( $post_id, self::META_DOCUMENT, true );
		$document = is_array( $saved ) && isset( $saved['sections'] ) ? self::sanitize_document( $saved ) : self::build_legacy_document( $post_id, $warnings );
		return [
			'post_id' => $post_id, 'mode' => self::is_v2( $post_id ) ? 'v2' : 'legacy', 'document' => $document,
			'can_migrate' => ! \is_wp_error( self::validate_document( $document ) ),
			'warnings' => array_values( array_unique( $warnings ) ), 'has_saved_document' => is_array( $saved ) && isset( $saved['sections'] ),
			'preview_url' => \add_query_arg( [ 'nova_service_preview' => 'v2', '_wpnonce' => \wp_create_nonce( 'nova_service_preview_' . $post_id ) ], \get_permalink( $post_id ) ),
		];
	}

	public static function apply_migration( $request ) {
		$id = (int) $request['id'];
		$action = (string) ( $request->get_param( 'action' ) ?: 'migrate' );
		if ( 'legacy' === $action ) {
			\update_post_meta( $id, self::META_MODE, 'legacy' );
		} else {
			$saved = \get_post_meta( $id, self::META_DOCUMENT, true );
			if ( ! is_array( $saved ) || ! isset( $saved['sections'] ) ) {
				// Store once; reactivation and repeated migration preserve later native edits.
				$document = self::legacy_document( $id );
				$valid = self::validate_document( $document );
				if ( \is_wp_error( $valid ) ) { return self::error( 'The converted page requires manual adjustment before migration: ' . $valid->get_error_message() ); }
				\update_post_meta( $id, self::META_DOCUMENT, \wp_slash( $document ) );
			}
			\update_post_meta( $id, self::META_MODE, 'v2' );
		}
		return \rest_ensure_response( self::migration_preview( $id ) );
	}

	public static function legacy_document( int $post_id ): array {
		$warnings = [];
		return self::build_legacy_document( $post_id, $warnings );
	}

	private static function legacy_image( int $id ): array {
		return [ 'id' => $id, 'url' => (string) ( \wp_get_attachment_image_url( $id, 'full' ) ?: '' ), 'alt' => (string) \get_post_meta( $id, '_wp_attachment_image_alt', true ), 'caption' => (string) \wp_get_attachment_caption( $id ) ];
	}

	private static function legacy_buttons( int $post_id, string $prefix, string $global_prefix ): array {
		$buttons = [];
		$global = \get_option( $global_prefix . '_primary_label', '' ) || \get_option( $global_prefix . '_primary_url', '' ) || \get_option( $global_prefix . '_secondary_label', '' ) || \get_option( $global_prefix . '_secondary_url', '' );
		foreach ( [ 'primary', 'secondary' ] as $kind ) {
			$label = $global ? \get_option( $global_prefix . '_' . $kind . '_label', '' ) : \get_post_meta( $post_id, $prefix . '_' . $kind . '_label', true );
			$url = $global ? \get_option( $global_prefix . '_' . $kind . '_url', '' ) : \get_post_meta( $post_id, $prefix . '_' . $kind . '_url', true );
			if ( $label || $url ) { $buttons[] = [ 'label' => (string) $label, 'url' => (string) $url ]; }
		}
		return $buttons;
	}

	private static function build_legacy_document( int $post_id, array &$warnings ): array {
		$get = static function ( $key ) use ( $post_id ) { return \get_post_meta( $post_id, $key, true ); };
		$sections = [];
		$hero = [ 'title' => (string) $get( 'sp_hero_title' ), 'eyebrow' => (string) $get( 'sp_hero_eyebrow' ), 'body' => (string) $get( 'sp_hero_copy' ), 'buttons' => self::legacy_buttons( $post_id, 'sp_hero', 'service_cpt_global_hero' ), 'images' => [] ];
		$hero_image = (int) ( \get_option( 'service_cpt_global_hero_background_image', 0 ) ?: \get_post_thumbnail_id( $post_id ) );
		if ( $hero_image ) { $hero['images'][] = self::legacy_image( $hero_image ); }
		$sections[] = self::section( 'hero', 'hero', $hero );
		foreach ( [ 'sp_intro', 'sp_main_1', 'sp_main_2', 'sp_main_3', 'sp_extra_copy' ] as $key ) {
			$body = (string) $get( $key );
			if ( trim( $body ) ) { $sections[] = self::section( str_replace( 'sp_', 'legacy_', $key ), 'rich_text', [ 'body' => $body, 'intent' => 'general' ] ); }
			if ( in_array( $key, [ 'sp_main_1', 'sp_main_2' ], true ) ) {
				$id = (int) $get( 'sp_image_' . substr( $key, -1 ) );
				if ( $id ) { $sections[] = self::section( 'legacy_image_' . substr( $key, -1 ), 'people', [ 'images' => [ self::legacy_image( $id ) ] ] ); $warnings[] = 'Existing content images are retained in image sections. Check whether each belongs under People or Location.'; }
			}
		}
		$table = $get( 'sp_table' );
		if ( is_array( $table ) && $table ) {
			$body = '<table>';
			foreach ( array_values( $table ) as $row_index => $row ) {
				$header = 0 === $row_index;
				$body .= $header ? '<thead><tr>' : ( 1 === $row_index ? '<tbody><tr>' : '<tr>' );
				foreach ( (array) $row as $cell ) { $body .= ( $header ? '<th scope="col">' : '<td>' ) . \esc_html( (string) $cell ) . ( $header ? '</th>' : '</td>' ); }
				$body .= $header ? '</tr></thead>' : '</tr>';
			}
			$sections[] = self::section( 'legacy_table', 'rich_text', [ 'body' => $body . ( count( $table ) > 1 ? '</tbody>' : '' ) . '</table>', 'intent' => 'general' ] );
		}
		for ( $i = 1; $i <= 3; $i++ ) {
			if ( $get( 'sp_tab_' . $i . '_title' ) || $get( 'sp_tab_' . $i . '_content' ) ) { $sections[] = self::section( 'legacy_tab_' . $i, 'rich_text', [ 'title' => (string) $get( 'sp_tab_' . $i . '_title' ), 'body' => (string) $get( 'sp_tab_' . $i . '_content' ), 'intent' => 'general' ] ); }
		}
		$sidebar_global = \get_option( 'service_cpt_global_sidebar_title', '' ) || \get_option( 'service_cpt_global_sidebar_copy', '' ) || \get_option( 'service_cpt_global_sidebar_primary_url', '' );
		$sidebar = [ 'title' => (string) ( $sidebar_global ? \get_option( 'service_cpt_global_sidebar_title', '' ) : $get( 'sp_sidebar_title' ) ), 'body' => (string) ( $sidebar_global ? \get_option( 'service_cpt_global_sidebar_copy', '' ) : $get( 'sp_sidebar_copy' ) ), 'buttons' => self::legacy_buttons( $post_id, 'sp_sidebar', 'service_cpt_global_sidebar' ) ];
		$sidebar_image = (int) ( $sidebar_global ? \get_option( 'service_cpt_global_sidebar_image', 0 ) : $get( 'sp_sidebar_image' ) );
		if ( $sidebar_image ) { $sidebar['images'] = [ self::legacy_image( $sidebar_image ) ]; }
		if ( $sidebar['title'] || $sidebar['body'] || $sidebar['buttons'] || $sidebar_image ) { $sections[] = self::section( 'legacy_sidebar_cta', 'cta', $sidebar ); }
		$wide_global = \get_option( 'service_cpt_global_cta_title', '' ) || \get_option( 'service_cpt_global_cta_button_url', '' );
		$wide = [ 'title' => (string) ( $wide_global ? \get_option( 'service_cpt_global_cta_title', '' ) : $get( 'sp_cta_title' ) ), 'body' => '', 'buttons' => [] ];
		$bullets = $wide_global ? [ \get_option( 'service_cpt_global_cta_bullet_1', '' ), \get_option( 'service_cpt_global_cta_bullet_2', '' ), \get_option( 'service_cpt_global_cta_bullet_3', '' ) ] : (array) $get( 'sp_cta_bullets' );
		if ( array_filter( $bullets ) ) { $wide['body'] = '<ul>'; foreach ( array_filter( $bullets ) as $bullet ) { $wide['body'] .= '<li>' . \esc_html( (string) $bullet ) . '</li>'; } $wide['body'] .= '</ul>'; }
		foreach ( [ 'button' => [ 'button_label', 'button_url' ], 'more' => [ 'more_text', 'more_url' ] ] as $keys ) {
			$label = (string) ( $wide_global ? \get_option( 'service_cpt_global_cta_' . $keys[0], '' ) : $get( 'sp_cta_' . $keys[0] ) );
			$url = (string) ( $wide_global ? \get_option( 'service_cpt_global_cta_' . $keys[1], '' ) : $get( 'sp_cta_' . $keys[1] ) );
			if ( $label || $url ) { $wide['buttons'][] = [ 'label' => $label, 'url' => $url ]; }
		}
		if ( $wide['title'] || $wide['body'] || $wide['buttons'] ) { $sections[] = self::section( 'legacy_wide_cta', 'cta', $wide ); }
		if ( is_array( $get( 'sp_faq' ) ) && $get( 'sp_faq' ) ) { $sections[] = self::section( 'legacy_faq', 'faq', [ 'title' => (string) \get_option( 'service_cpt_label_faq', 'Frequently asked questions' ), 'items' => $get( 'sp_faq' ) ] ); }
		if ( is_array( $get( 'sp_related_posts' ) ) && $get( 'sp_related_posts' ) ) {
			$related_data = [ 'title' => (string) \get_option( 'service_cpt_label_related', 'Related services' ), 'post_ids' => [], 'items' => [] ];
			foreach ( $get( 'sp_related_posts' ) as $related_id ) {
				$related = \get_post( (int) $related_id );
				if ( ! $related || 'publish' !== $related->post_status ) { $warnings[] = 'A missing or unpublished legacy related article could not become a public link card. Its ID remains in the preserved legacy meta.'; continue; }
				if ( self::CPT === $related->post_type ) { $related_data['post_ids'][] = (int) $related_id; }
				else {
					$related_data['items'][] = [ 'title' => (string) \get_the_title( $related_id ), 'url' => (string) \get_permalink( $related_id ), 'body' => (string) ( $related->post_excerpt ?? '' ), 'image' => (string) ( \wp_get_attachment_image_url( \get_post_thumbnail_id( $related_id ), 'large' ) ?: '' ) ];
					$warnings[] = 'Legacy links to blog posts are preserved as manual link cards; native post_ids are reserved for service pages.';
				}
			}
			$sections[] = self::section( 'legacy_related', 'related', $related_data );
		}
		$post = \get_post( $post_id );
		if ( $post && trim( (string) $post->post_content ) ) { self::import_blocks( (string) $post->post_content, $sections, $warnings ); }
		$document = [ 'version' => 2, 'sections' => $sections ];
		$sanitized = self::sanitize_document( $document );
		if ( $sanitized !== $document ) { $warnings[] = 'Legacy content is sanitized for native rendering. Unsafe HTML, unsupported embeds/shortcodes or invalid links may need manual adjustment; all original content and meta remain preserved.'; }
		$valid = self::validate_document( $sanitized );
		if ( \is_wp_error( $valid ) ) { $warnings[] = 'This conversion needs manual adjustment before migration: ' . $valid->get_error_message() . ' No converted sections or items have been truncated.'; }
		$warnings[] = 'Preview every section before switching. Existing legacy templates, component visibility, sidebars and tabs become editable native sections; brand settings are retained by the renderer.';
		return $sanitized;
	}

	/** Flatten static block content without executing Gutenberg or external plugins. */
	private static function import_blocks( string $content, array &$sections, array &$warnings ): void {
		$placeholders = [];
		foreach ( glob( dirname( __DIR__ ) . '/templates/layouts/*.html' ) ?: [] as $template ) {
			$text = (string) file_get_contents( $template );
			if ( trim( $text ) === trim( $content ) ) { return; }
			$collect = static function ( $blocks ) use ( &$collect, &$placeholders ) { foreach ( $blocks as $block ) { if ( ! empty( $block['innerBlocks'] ) ) { $collect( $block['innerBlocks'] ); } elseif ( trim( $block['innerHTML'] ?? '' ) ) { $placeholders[ sha1( trim( $block['innerHTML'] ) ) ] = true; } } };
			$collect( \parse_blocks( $text ) );
		}
		$index = 0;
		$walk = static function ( $blocks ) use ( &$walk, &$sections, &$warnings, &$index, $placeholders ) {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['innerBlocks'] ) ) { $walk( $block['innerBlocks'] ); continue; }
				$html = trim( $block['innerHTML'] ?? '' );
				$type = $block['blockName'] ?? '';
				if ( '' === $html ) { if ( $type && ! in_array( $type, [ 'core/spacer', 'core/separator', 'core/latest-posts' ], true ) ) { $warnings[] = 'Dynamic block ' . $type . ' requires manual replacement. Its original data remains in legacy post_content.'; } continue; }
				if ( isset( $placeholders[ sha1( $html ) ] ) ) { continue; }
				$id = 'legacy_block_' . ++$index;
				if ( 'core/shortcode' === $type ) {
					$shortcode = self::safe_shortcode( trim( strip_tags( $html ) ) );
					if ( $shortcode ) { $sections[] = self::section( $id, 'custom_cta', [ 'shortcode' => $shortcode ] ); }
					else { $warnings[] = 'A legacy shortcode requires manual review; its original value remains preserved.'; }
					continue;
				}
				if ( false !== stripos( $html, '<iframe' ) ) { $sections[] = self::section( $id, 'custom_cta', [ 'html' => self::safe_custom_html( $html ) ] ); $warnings[] = 'An embedded legacy block was converted to a custom CTA. Confirm the provider and content in preview.'; continue; }
				if ( 'core/image' === $type && preg_match( '/<img[^>]+src=[\x22\x27]([^\x22\x27]+)[\x22\x27]/i', $html, $match ) ) {
					$image_id = (int) ( $block['attrs']['id'] ?? 0 );
					$image = $image_id ? self::legacy_image( $image_id ) : [ 'id' => 0, 'url' => html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' ), 'alt' => '', 'caption' => '' ];
					if ( preg_match( '/\balt=[\x22\x27]([^\x22\x27]*)[\x22\x27]/i', $html, $alt ) ) { $image['alt'] = html_entity_decode( $alt[1], ENT_QUOTES, 'UTF-8' ); }
					if ( preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/is', $html, $caption ) ) { $image['caption'] = strip_tags( $caption[1] ); }
					$sections[] = self::section( $id, 'people', [ 'images' => [ $image ] ] ); $warnings[] = 'Imported images need a People/Location role check.'; continue;
				}
				if ( 'core/button' === $type && preg_match( '/<a[^>]+href=[\x22\x27]([^\x22\x27]+)[\x22\x27][^>]*>(.*?)<\/a>/is', $html, $button ) ) { $sections[] = self::section( $id, 'cta', [ 'buttons' => [ [ 'url' => html_entity_decode( $button[1], ENT_QUOTES, 'UTF-8' ), 'label' => strip_tags( $button[2] ) ] ] ] ); continue; }
				if ( 'faq-block-for-gutenberg/faq' === $type && preg_match( '/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $question ) ) { $answer = preg_replace( '/^.*?<\/h[1-6]>/is', '', $html ); $sections[] = self::section( $id, 'faq', [ 'items' => [ [ 'question' => strip_tags( $question[1] ), 'answer' => \wp_kses_post( $answer ) ] ] ] ); continue; }
				if ( $type && 0 !== strpos( $type, 'core/' ) ) { $warnings[] = 'Block ' . $type . ' was imported as static rich text. Confirm its appearance; plugin interactions require manual replacement.'; }
				$safe = \wp_kses_post( $html );
				if ( trim( strip_tags( $safe ) ) || false !== stripos( $safe, '<img' ) ) { $sections[] = self::section( $id, 'rich_text', [ 'body' => $safe, 'intent' => 'general' ] ); }
				if ( $safe !== $html ) { $warnings[] = 'Some custom HTML was sanitized during conversion. The original remains preserved for rollback.'; }
			}
		};
		$walk( \parse_blocks( $content ) );
	}
}
