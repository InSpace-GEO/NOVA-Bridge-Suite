<?php
/**
 * Block-independent rendering for the opt-in Service experience.
 *
 * @package NOVABridgeSuite
 */

namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceRenderer {
	private const REVIEW_DESIGNS = [ 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature' ];
	private const CREDENTIAL_DESIGNS = [ 'logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion' ];

	private static $bootstrapped = false;
	private static $rendering = [];
	private static $candidate_title = null;

	private static function live_preview_context(): bool { return \class_exists( ServiceLivePreview::class ) && ServiceLivePreview::is_rendering(); }

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 40 );
		\add_action( 'wp_print_styles', [ __CLASS__, 'remove_legacy_style' ], PHP_INT_MAX );
		\add_action( 'wp_footer', [ __CLASS__, 'remove_legacy_style' ], 19 );
		\add_action( 'template_redirect', [ __CLASS__, 'protect_preview' ], 0 );
		\add_filter( 'body_class', [ __CLASS__, 'body_classes' ] );
	}

	public static function protect_preview(): void {
		if ( \is_singular( 'service_page' ) && ServiceDocument::is_preview( (int) \get_queried_object_id() ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
			\nocache_headers();
			\header( 'X-Robots-Tag: noindex, nofollow', true );
		}
	}

	public static function is_context(): bool {
		if ( ! \is_singular( 'service_page' ) ) {
			return false;
		}
		$post_id = (int) \get_queried_object_id();
		return self::live_preview_context() || ServiceDocument::is_v2( $post_id ) || ServiceDocument::is_preview( $post_id );
	}

	public static function body_classes( array $classes ): array {
		if ( self::is_context() ) {
			$classes[] = 'nova-service-v2-page';
		}
		return $classes;
	}

	public static function enqueue_assets(): void {
		if ( ! self::is_context() ) {
			return;
		}
		self::remove_legacy_style();
		$base    = \plugins_url( 'assets/', \dirname( __DIR__ ) . '/service-page-cpt.php' );
		$version = \defined( 'NOVA_BRIDGE_SUITE_VERSION' ) ? NOVA_BRIDGE_SUITE_VERSION : 'service-v2';
		$assets  = \dirname( __DIR__ ) . '/assets/';
		$css_version = $version . '.' . (string) \filemtime( $assets . 'service-frontend-v2.css' );
		$js_version  = $version . '.' . (string) \filemtime( $assets . 'service-frontend-v2.js' );
		\wp_enqueue_style( 'nova-service-frontend-v2', $base . 'service-frontend-v2.css', [], $css_version );
		\wp_enqueue_script( 'nova-service-frontend-v2', $base . 'service-frontend-v2.js', [], $js_version, true );
	}

	public static function remove_legacy_style(): void {
		if ( self::is_context() ) {
			// Core may auto-enqueue this registered block stylesheet independently
			// of the old module's enqueue callback. Keep the theme/brand assets.
			\wp_dequeue_style( 'service-cpt-frontend' );
		}
	}

	public static function render( int $post_id ): string {
		if ( 'service_page' !== \get_post_type( $post_id ) ) {
			return '';
		}
		if ( \post_password_required( $post_id ) ) {
			return \get_the_password_form( $post_id );
		}
		if ( ! empty( self::$rendering[ $post_id ] ) ) {
			return '';
		}
		self::$rendering[ $post_id ] = true;
		try {
			return self::render_document( $post_id );
		} finally {
			unset( self::$rendering[ $post_id ] );
		}
	}

	/** Render an already validated/resolved request-local candidate, without saving it. */
	public static function render_candidate( int $post_id, array $document, ?string $title = null ): string {
		$old_title = self::$candidate_title;
		self::$candidate_title = $title;
		try { return self::render_document( $post_id, $document ); }
		finally { self::$candidate_title = $old_title; }
	}

	private static function page_title( int $post_id ): string { return null !== self::$candidate_title ? self::$candidate_title : \get_the_title( $post_id ); }

	private static function render_document( int $post_id, ?array $candidate = null ): string {
		$document = $candidate ?? ServiceDocument::resolve_document( $post_id );
		$sections = \is_array( $document['sections'] ?? null ) ? $document['sections'] : [];
		foreach ( $sections as &$section ) {
			if ( \is_array( $section ) && \is_object( $section['data'] ?? null ) ) {
				$section['data'] = (array) $section['data'];
			}
		}
		unset( $section );
		$has_hero = false;
		foreach ( $sections as $section ) {
			if ( ! empty( $section['enabled'] ) && \is_array( $section['data'] ?? null ) && 'hero' === ( $section['type'] ?? '' ) ) {
				$has_hero = true;
				break;
			}
		}

		$html = '<article id="nova-service-v2" class="nova-service" style="' . \esc_attr( self::brand_tokens() ) . '">';
		if ( ServiceDocument::is_preview( $post_id ) ) {
			$html .= '<aside class="nova-service__preview" aria-label="' . \esc_attr__( 'Service preview', 'nova-bridge-suite' ) . '"><div>';
			$html .= '<strong>' . \esc_html__( 'New Service experience preview', 'nova-bridge-suite' ) . '</strong> ';
			$html .= \esc_html__( 'This preview does not change the published page. Review it before choosing to switch in the editor.', 'nova-bridge-suite' );
			$html .= '</div><a href="' . \esc_url( \get_permalink( $post_id ) ) . '">' . \esc_html__( 'View current published page', 'nova-bridge-suite' ) . '</a></aside>';
		}
		if ( ! $has_hero ) {
			$html .= '<header class="nova-service__title nova-service__container"><h1>' . \esc_html( self::page_title( $post_id ) ) . '</h1></header>';
		}

		$hero_count = 0;
		$faq_schema = [];
		$used_ids   = [];
		$half_count = 0;
		$row_count  = 0;
		foreach ( $sections as $index => $section ) {
			if ( empty( $section['enabled'] ) || ! \is_array( $section['data'] ?? null ) ) {
				continue;
			}
			$type = (string) ( $section['type'] ?? '' );
			$data = $section['data'];
			$id   = 'nova-service-' . \sanitize_html_class( (string) ( $section['id'] ?? ( $type . '-' . $index ) ) );
			if ( isset( $used_ids[ $id ] ) ) {
				$id .= '-' . $index;
			}
			$used_ids[ $id ] = true;
			$content = '';
			switch ( $type ) {
				case 'hero':
					$content = self::hero( $data, $post_id, 0 === $hero_count++ );
					break;
				case 'rich_text':
					$content = self::text_media( $data );
					break;
				case 'people':
				case 'location':
					$address = '';
					if ( 'location' === $type && ! empty( $data['address'] ) ) {
						$address = '<address class="nova-service__address">' . \nl2br( \esc_html( $data['address'] ) ) . '</address>';
					}
					$content = self::text_media( $data, $address );
					break;
				case 'video':
					$content = self::video( $data );
					break;
				case 'map':
					$content = self::map( $data );
					break;
				case 'reviews':
					$content = self::reviews( $data );
					break;
				case 'credentials':
					$content = self::credentials( $data );
					break;
				case 'cta':
					$body    = self::rich_body( $data['body'] ?? '' ) . self::gallery( $data['images'] ?? [] );
					$buttons = self::buttons( $data['buttons'] ?? [] );
					$content = '' !== $body || '' !== $buttons ? '<div class="nova-service__cta-copy">' . self::heading( $data ) . $body . '</div>' . $buttons : '';
					break;
				case 'custom_cta':
					$content = self::custom_cta( $data );
					break;
				case 'related':
					$content = self::related( $data, $post_id );
					break;
				case 'faq':
					$content = self::faq( $data, $faq_schema );
					break;
			}
			if ( '' === $content ) {
				continue;
			}
			$layouts = [ 'split', 'stacked', 'compact', 'cards', 'list' ];
			if ( 'credentials' === $type ) { $layouts = \array_merge( $layouts, [ 'badges', 'logos' ], self::CREDENTIAL_DESIGNS ); }
			if ( 'reviews' === $type ) { $layouts = \array_merge( $layouts, self::REVIEW_DESIGNS ); }
			$layout = \in_array( $data['layout'] ?? '', $layouts, true ) ? $data['layout'] : 'default';
			$half = 'half' === ( $data['width'] ?? 'full' );
			if ( ! $half && $half_count ) {
				$html .= '</div></div>';
				$half_count = 0;
			}
			if ( $half && 0 === $half_count ) {
				$band = 0 === $row_count++ % 2 ? 'soft' : 'plain';
				$html .= '<div class="nova-service__band nova-service__band--' . $band . '"><div class="nova-service__row nova-service__container">';
			}
			$html .= '<section id="' . \esc_attr( $id ) . '" data-nova-service-section-id="' . \esc_attr( (string) ( $section['id'] ?? '' ) ) . '" class="nova-service__section nova-service__section--' . \esc_attr( $type ) . ' nova-service__layout--' . \esc_attr( $layout ) . ' nova-service__width--' . ( $half ? 'half' : 'full' ) . '"><div class="nova-service__container">' . $content . '</div></section>';
			if ( $half && 2 === ++$half_count ) {
				$html .= '</div></div>';
				$half_count = 0;
			}
		}
		if ( $half_count ) {
			$html .= '</div></div>';
		}
		$html .= '</article>';
		if ( ! empty( $faq_schema ) && \apply_filters( 'nova_service_v2/enable_faq_schema', true, $post_id ) ) {
			$schema = [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faq_schema ];
			$html  .= '<script type="application/ld+json" class="nova-service-faq-schema">' . \wp_json_encode( $schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script>';
		}
		return $html;
	}

	private static function hero( array $data, int $post_id, bool $first ): string {
		$title = (string) ( $data['title'] ?? '' );
		if ( '' === \trim( $title ) ) {
			$title = self::page_title( $post_id );
		}
		$tag   = $first ? 'h1' : 'h2';
		$html  = '<div class="nova-service__hero-grid"><div class="nova-service__hero-copy">';
		$html .= self::eyebrow( $data );
		$html .= '<' . $tag . ' class="nova-service__hero-title">' . \esc_html( $title ) . '</' . $tag . '>';
		$html .= self::rich_body( $data['body'] ?? '' ) . self::buttons( $data['buttons'] ?? [] ) . '</div>';
		$images = \is_array( $data['images'] ?? null ) ? $data['images'] : [];
		$media  = self::gallery( $images, true, $first );
		if ( '' !== $media ) {
			$html .= '<div class="nova-service__hero-media">' . $media . '</div>';
		}
		return $html . '</div>';
	}

	private static function eyebrow( array $data ): string {
		return ! empty( $data['eyebrow'] ) ? '<p class="nova-service__eyebrow">' . \esc_html( $data['eyebrow'] ) . '</p>' : '';
	}

	private static function heading( array $data ): string {
		$html = self::eyebrow( $data );
		if ( ! empty( $data['title'] ) ) {
			$tag   = 'h3' === ( $data['heading_level'] ?? '' ) ? 'h3' : 'h2';
			$html .= '<' . $tag . ' class="nova-service__heading">' . \esc_html( $data['title'] ) . '</' . $tag . '>';
		}
		return $html;
	}

	private static function rich_body( $body ): string {
		$body = self::content_headings( \wp_kses_post( (string) $body ) );
		$plain = \html_entity_decode( \wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' );
		if ( '' === \trim( \str_replace( "\xc2\xa0", ' ', $plain ) ) && ! \preg_match( '/<(?:img|video|audio|table|hr)\b/i', $body ) ) {
			return '';
		}
		return '<div class="nova-service__rich">' . \wpautop( $body ) . '</div>';
	}

	private static function content_headings( string $html ): string {
		// Imported HTML and plugin CTAs share the page's one native H1 hierarchy.
		return (string) \preg_replace( '/<(\/?)h1(?=\s|>)/i', '<$1h2', $html );
	}

	private static function text_media( array $data, string $extra_copy = '' ): string {
		$copy  = self::rich_body( $data['body'] ?? '' ) . $extra_copy;
		$media = self::gallery( $data['images'] ?? [] );
		if ( '' === $copy && '' === $media ) {
			return '';
		}
		$text = self::heading( $data ) . $copy;
		$html = '<div class="nova-service__content-grid">';
		if ( '' !== $text ) {
			$html .= '<div class="nova-service__content-copy">' . $text . '</div>';
		}
		if ( '' !== $media ) {
			$html .= '<div class="nova-service__content-media">' . $media . '</div>';
		}
		return $html . '</div>';
	}

	private static function video( array $data ): string {
		$url = ServiceDocument::safe_video_url( (string) ( $data['video_url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}
		$title = ! empty( $data['title'] ) ? $data['title'] : \__( 'Service video', 'nova-bridge-suite' );
		$path  = (string) \wp_parse_url( $url, PHP_URL_PATH );
		$media = '';
		if ( \preg_match( '/\.(mp4|webm)$/i', $path, $extension ) ) {
			$poster = (string) ( $data['poster'] ?? '' );
			$first_image = $data['images'][0] ?? [];
			if ( '' === $poster && ! empty( $first_image['id'] ) ) {
				$poster = (string) \wp_get_attachment_image_url( (int) $first_image['id'], 'large' );
			}
			if ( '' === $poster ) {
				$poster = (string) ( $first_image['url'] ?? '' );
			}
			$media = '<video controls playsinline preload="metadata" aria-label="' . \esc_attr( $title ) . '"' . ( '' !== \esc_url( $poster ) ? ' poster="' . \esc_url( $poster ) . '"' : '' ) . '><source src="' . \esc_url( $url ) . '" type="video/' . \esc_attr( \strtolower( $extension[1] ) ) . '" />';
			$media .= '<a href="' . \esc_url( $url ) . '">' . \esc_html__( 'Watch the service video', 'nova-bridge-suite' ) . '</a></video>';
		} else {
			// Approved provider URLs are normalized by the document model; no autoplay.
			$media = '<iframe src="' . \esc_url( $url ) . '" title="' . \esc_attr( $title ) . '" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; picture-in-picture" allowfullscreen></iframe>';
		}
		return self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . '<div class="nova-service__video">' . $media . '</div>';
	}

	private static function buttons( $buttons ): string {
		$html = '';
		foreach ( \is_array( $buttons ) ? $buttons : [] as $button ) {
			$url   = \esc_url( (string) ( $button['url'] ?? '' ) );
			$label = \trim( (string) ( $button['label'] ?? '' ) );
			if ( '' === $url || '' === $label ) {
				continue;
			}
			$class = '' === $html ? 'nova-service__button' : 'nova-service__button nova-service__button--secondary';
			$html .= '<a class="' . $class . '" href="' . $url . '">' . \esc_html( $label ) . '<span aria-hidden="true">&#8599;</span></a>';
		}
		return '' !== $html ? '<div class="nova-service__actions">' . $html . '</div>' : '';
	}

	private static function gallery( $images, bool $hero = false, bool $eager = false ): string {
		$html  = '';
		$count = 0;
		foreach ( \is_array( $images ) ? $images : [] as $image ) {
			if ( ! \is_array( $image ) ) {
				continue;
			}
			$markup = self::image( $image, $eager && 0 === $count );
			if ( '' === $markup ) {
				continue;
			}
			$html .= '<figure class="nova-service__figure">' . $markup;
			if ( ! empty( $image['caption'] ) ) {
				$html .= '<figcaption>' . \esc_html( $image['caption'] ) . '</figcaption>';
			}
			$html .= '</figure>';
			$count++;
		}
		return '' !== $html ? '<div class="nova-service__gallery nova-service__gallery--' . ( 1 === $count ? 'single' : 'multiple' ) . ( $hero ? ' nova-service__gallery--hero' : '' ) . '">' . $html . '</div>' : '';
	}

	private static function image( array $image, bool $eager = false ): string {
		$attributes = [ 'class' => 'nova-service__image', 'loading' => $eager ? 'eager' : 'lazy', 'decoding' => 'async' ];
		if ( $eager ) {
			$attributes['fetchpriority'] = 'high';
		}
		// An explicit blank alt text is useful for decorative images.
		if ( \array_key_exists( 'alt', $image ) ) {
			$attributes['alt'] = (string) $image['alt'];
		}
		if ( ! empty( $image['id'] ) ) {
			$markup = \wp_get_attachment_image( (int) $image['id'], 'large', false, $attributes );
			if ( $markup ) {
				return $markup;
			}
		}
		$url = \esc_url( (string) ( $image['url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}
		return '<img class="nova-service__image" src="' . $url . '" alt="' . \esc_attr( (string) ( $image['alt'] ?? '' ) ) . '" loading="' . ( $eager ? 'eager' : 'lazy' ) . '" decoding="async"' . ( $eager ? ' fetchpriority="high"' : '' ) . ' />';
	}

	private static function map( array $data ): string {
		$url     = self::google_maps_url( (string) ( $data['map_url'] ?? '' ) );
		$address = \trim( (string) ( $data['address'] ?? '' ) );
		$embed   = '';
		if ( '' !== $url && ServiceDocument::valid_map_url( $url ) ) {
			$embed = $url;
		}
		if ( '' === $embed && '' !== $address ) {
			$embed = 'https://maps.google.com/maps?q=' . \rawurlencode( $address ) . '&output=embed';
			if ( '' === $url ) {
				$url = 'https://www.google.com/maps/search/?api=1&query=' . \rawurlencode( $address );
			}
		}
		if ( '' === $embed && '' === $url && '' === $address ) {
			return '';
		}
		$html = self::heading( $data ) . self::rich_body( $data['body'] ?? '' );
		if ( '' !== $embed ) {
			$title = ! empty( $data['title'] ) ? $data['title'] : \__( 'Our location on Google Maps', 'nova-bridge-suite' );
			$html .= '<div class="nova-service__map"><iframe src="' . \esc_url( $embed ) . '" title="' . \esc_attr( $title ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
		}
		if ( '' !== $address ) {
			$html .= '<address class="nova-service__address">' . \nl2br( \esc_html( $address ) ) . '</address>';
		}
		if ( '' !== $url ) {
			$html .= '<a class="nova-service__text-link" href="' . \esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . \esc_html__( 'Open in Google Maps', 'nova-bridge-suite' ) . '<span aria-hidden="true"> &#8599;</span></a>';
		}
		return $html;
	}

	private static function google_maps_url( string $url ): string {
		$parts = \wp_parse_url( $url );
		if ( ! \is_array( $parts ) || 'https' !== \strtolower( $parts['scheme'] ?? '' ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return '';
		}
		$host = \strtolower( $parts['host'] ?? '' );
		$path = $parts['path'] ?? '';
		$valid = ( \in_array( $host, [ 'www.google.com', 'google.com' ], true ) && 0 === \strpos( $path, '/maps' ) ) || 'maps.google.com' === $host || 'maps.app.goo.gl' === $host || ( 'goo.gl' === $host && 0 === \strpos( $path, '/maps/' ) );
		return $valid ? \esc_url_raw( $url, [ 'https' ] ) : '';
	}

	private static function reviews( array $data ): string {
		$source        = (array) ( $data['review_source'] ?? [] );
		$profile       = self::review_profile( $source );
		$summary       = '';
		$google_widget = '';
		$google_cards  = '';
		$design = \in_array( $data['layout'] ?? '', self::REVIEW_DESIGNS, true ) ? $data['layout'] : '';
		$link_sources = false !== ( $data['link_sources'] ?? true );
		$manual_cards  = self::review_cards( $data['items'] ?? [], false, (string) ( $data['source_logo'] ?? '' ), (string) ( $profile['provider'] ?? '' ), $design, $link_sources );
		$is_google     = 'google' === ( $profile['provider'] ?? '' );
		if ( $profile ) {
			$aggregate = $source;
			if ( $is_google ) {
				// Google content is fetched for this request. Stored metadata contains
				// selection identifiers only; never display a stale stored aggregate.
				$aggregate = [];
				$wants_widget = 'widget' === ( $source['display'] ?? 'profile' );
				// A profile link remains compact and does not perform an unnecessary
				// public-page request. Google Maps is an explicit display choice.
				$live = ! self::live_preview_context() && ( $wants_widget || ! empty( $source['selected_review_ids'] ) ) ? ServiceReviewImporter::resolve_source( $source ) : [];
				if ( \is_array( $live ) && 'google' === ( $live['provider'] ?? '' ) ) {
					$aggregate    = $live;
					// Live imported Google cards retain their supplied attribution links.
					$google_cards = self::review_cards( $live['reviews'] ?? [], true, '', 'google', $design, true );
					$google_widget = $wants_widget ? self::google_review_widget( $live ) : '';
				} elseif ( \is_wp_error( $live ) ) {
					$error_data = $live->get_error_data();
					$fallback = \is_array( $error_data ) ? (array) ( $error_data['fallback'] ?? [] ) : [];
					$google_widget = $wants_widget ? self::google_review_widget( $fallback ) : '';
				}
			}
			$summary = self::review_summary( $aggregate, $profile, (string) ( $data['source_logo'] ?? '' ), $link_sources || '' !== $google_cards );
		}
		if ( '' === $summary && '' === $google_widget && '' === $google_cards && '' === $manual_cards ) {
			return '';
		}
		$html = self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . $summary . $google_widget;
		if ( '' !== $google_cards ) {
			$html .= '<p class="nova-service__review-selection">' . \esc_html__( 'Selected reviews from Google; ordered by relevance.', 'nova-bridge-suite' ) . '</p>';
		}
		if ( '' !== $google_cards || '' !== $manual_cards ) {
			$html .= '<div class="nova-service__cards nova-service__reviews">' . $google_cards . $manual_cards . '</div>';
		}
		return $html;
	}

	private static function google_review_widget( array $source ): string {
		$url = self::google_maps_url( (string) ( $source['embed_url'] ?? '' ) );
		if ( 'google' !== ( $source['provider'] ?? '' ) || '' === $url || ! ServiceDocument::valid_map_url( $url ) ) {
			return '';
		}
		return '<div class="nova-service__map nova-service__review-widget"><iframe src="' . \esc_url( $url ) . '" title="' . \esc_attr__( 'Business profile on Google Maps', 'nova-bridge-suite' ) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>';
	}

	private static function review_profile( array $source ): array {
		if ( ! \class_exists( ServiceReviewImporter::class ) ) {
			return [];
		}
		$url = ServiceReviewImporter::canonical_profile_url( (string) ( $source['url'] ?? '' ) );
		$provider = (string) ( $source['provider'] ?? '' );
		$labels = [ 'trustpilot' => 'Trustpilot', 'google' => 'Google Maps', 'webwinkelkeur' => 'WebwinkelKeur', 'thuiswinkel' => 'Thuiswinkel', 'kiyoh' => 'Kiyoh' ];
		if ( '' === $url || ! isset( $labels[ $provider ] ) || $provider !== ServiceReviewImporter::provider_for_url( $url ) ) {
			return [];
		}
		return [ 'url' => $url, 'provider' => $provider, 'label' => $labels[ $provider ] ];
	}

	private static function review_summary( array $source, array $profile, string $custom_logo = '', bool $link_sources = true ): string {
		$html = '<div class="nova-service__review-summary">';
		$scale = \in_array( (int) ( $source['rating_scale'] ?? 5 ), [ 5, 10 ], true ) ? (int) ( $source['rating_scale'] ?? 5 ) : 5;
		$rating = isset( $source['average_rating'] ) && \is_numeric( $source['average_rating'] ) ? (float) $source['average_rating'] : null;
		$count  = isset( $source['review_count'] ) && \is_numeric( $source['review_count'] ) && (float) $source['review_count'] >= 0 && (float) $source['review_count'] === (float) (int) $source['review_count'] ? (int) $source['review_count'] : null;
		if ( null !== $rating && ( ! \is_finite( $rating ) || $rating < 0 || $rating > $scale || 0 === $count ) ) {
			$rating = null;
		}
		if ( null !== $rating ) {
			$score = \number_format_i18n( $rating, 1 );
			$html .= '<div class="nova-service__overall-rating" aria-label="' . \esc_attr( \sprintf( \__( 'Overall rating: %1$s out of %2$s on %3$s', 'nova-bridge-suite' ), $score, $scale, $profile['label'] ) ) . '"><strong>' . \esc_html( $score ) . '<span>/' . \esc_html( (string) $scale ) . '</span></strong><span class="nova-service__review-stars" aria-hidden="true"><span>&#9733;&#9733;&#9733;&#9733;&#9733;</span><span style="width:' . \esc_attr( (string) ( $rating / $scale * 100 ) ) . '%">&#9733;&#9733;&#9733;&#9733;&#9733;</span></span></div>';
		}
		$html .= '<div class="nova-service__review-summary-copy">';
		if ( ! empty( $source['business_name'] ) ) {
			$html .= '<strong>' . \esc_html( (string) $source['business_name'] ) . '</strong>';
		}
		if ( null !== $count ) {
			$html .= '<span class="nova-service__review-count">' . \esc_html( \sprintf( \_n( '%s review', '%s reviews', $count, 'nova-bridge-suite' ), \number_format_i18n( $count ) ) ) . '</span>';
		}
		if ( 'combined' === ( $source['aggregate_scope'] ?? '' ) ) {
			$html .= '<span class="nova-service__review-scope">' . \esc_html( \sprintf( \__( 'Combined score published by %s', 'nova-bridge-suite' ), $profile['label'] ) ) . '</span>';
		}
		$google = 'google' === $profile['provider'];
		if ( $google && null === $rating && null === $count ) {
			$html .= '<span class="nova-service__review-profile-label">' . \esc_html( $link_sources ? \__( 'View reviews on', 'nova-bridge-suite' ) : \__( 'Source', 'nova-bridge-suite' ) ) . '</span>';
		}
		$html .= '<span class="nova-service__provider-brand nova-service__provider-brand--' . \esc_attr( $profile['provider'] ) . '">' . self::review_source_attribution( $profile['provider'], $profile['label'], $profile['url'], $custom_logo, $link_sources, 'nova-service__text-link' ) . '</span></div></div>';
		return $html;
	}

	private static function review_cards( $items, bool $google = false, string $default_logo = '', string $default_provider = '', string $design = '', bool $link_sources = true ): string {
		$cards = '';
		$link_sources = $google || $link_sources;
		foreach ( \is_array( $items ) ? $items : [] as $item ) {
			if ( ! \is_array( $item ) || empty( $item['body'] ) ) {
				continue;
			}
			$body = $google ? self::rich_body( \esc_html( (string) $item['body'] ) ) : self::rich_body( (string) $item['body'] );
			if ( '' === $body ) {
				continue;
			}
			$item_url = (string) ( ! empty( $item['url'] ) ? $item['url'] : ( $item['source_url'] ?? '' ) );
			$url = $google ? self::review_https_url( $item_url ) : \esc_url( $item_url );
			$provider = $google ? 'google' : self::review_provider_for_url( $item_url );
			$labels = [ 'google' => 'Google Maps', 'trustpilot' => 'Trustpilot', 'webwinkelkeur' => 'WebwinkelKeur', 'kiyoh' => 'Kiyoh', 'thuiswinkel' => 'Thuiswinkel' ];
			$source = $labels[ $provider ] ?? \trim( (string) ( $item['source'] ?? '' ) );
			// A platform name alone never makes an own-site testimonial a platform review.
			if ( '' === $provider && '' !== $url && \preg_match( '/^(?:google(?: maps| reviews)?|trustpilot|webwinkelkeur|kiyoh|thuiswinkel(?: waarborg)?)$/i', $source ) ) { $source = ''; }
			if ( '' === $source && '' !== $url ) { $source = (string) \preg_replace( '/^www\./', '', \strtolower( (string) \wp_parse_url( $item_url, PHP_URL_HOST ) ) ); }
			$source = '' !== $source ? $source : \__( 'Customer testimonial', 'nova-bridge-suite' );
			$custom_logo = \array_key_exists( 'source_logo', $item ) ? (string) $item['source_logo'] : ( '' === $provider || $provider === $default_provider ? $default_logo : '' );
			$source_class = 'nova-service__review-source' . ( '' !== $provider ? ' nova-service__review-source--' . $provider : '' );
			$informative_label = '' === $provider ? $source : \trim( (string) ( $item['source'] ?? '' ) );
			if ( '' !== $provider && \preg_match( '/^(?:google(?: maps| reviews)?|trustpilot|webwinkelkeur|kiyoh|thuiswinkel(?: waarborg)?)$/i', $informative_label ) ) { $informative_label = ''; }
			$source_markup = '<span class="' . \esc_attr( $source_class ) . '">' . self::review_source_attribution( $provider, $source, $url, $custom_logo, $link_sources, 'nova-service__review-source-link', $informative_label ) . '</span>';
			$testimonial = '' === $provider;
			$rating_markup = '';
			$rating = \min( 5, \max( 0, (float) ( $item['rating'] ?? 0 ) ) );
			if ( $rating > 0 ) {
				$original = $item['original_rating'] ?? null;
				$scale = (int) ( $item['original_scale'] ?? 5 );
				$has_original = \is_numeric( $original ) && \in_array( $scale, [ 5, 10 ], true ) && (float) $original >= 0 && (float) $original <= $scale;
				$display_rating = $has_original ? (float) $original : $rating;
				if ( ! $has_original ) { $scale = 5; }
				$score = \number_format_i18n( $display_rating, 1 );
				$rating_markup = '<div class="nova-service__rating" aria-label="' . \esc_attr( \sprintf( \__( 'Rated %1$s out of %2$s', 'nova-bridge-suite' ), $score, $scale ) ) . '"><span aria-hidden="true">' . \str_repeat( '&#9733;', (int) \round( $rating ) ) . '</span><span>' . \esc_html( $score ) . '/' . \esc_html( (string) $scale ) . '</span></div>';
			}
			$vendor_head = ! $testimonial ? '<div class="nova-service__review-head">' . $source_markup . $rating_markup . '</div>' : '';
			$title = ! empty( $item['title'] ) ? '<h3 class="nova-service__card-title">' . \esc_html( $item['title'] ) . '</h3>' : '';
			$quote = '<blockquote class="nova-service__review-quote">' . ( $testimonial || '' !== $design ? '<span class="nova-service__quote-mark" aria-hidden="true">&#8220;</span>' : '' ) . $body . '</blockquote>';
			$avatar = '';
			$author_image = self::review_https_url( (string) ( $item['author_image'] ?? '' ) );
			if ( '' !== $author_image ) {
				$avatar = '<img class="nova-service__review-avatar" src="' . \esc_url( $author_image ) . '" alt="" width="40" height="40" loading="lazy" decoding="async" />';
			} elseif ( ( $testimonial || '' !== $design ) && \preg_match( '/[\p{L}\p{N}]/u', \wp_strip_all_tags( (string) ( $item['author'] ?? '' ) ), $initial ) ) {
				$letter = \function_exists( 'mb_strtoupper' ) ? \mb_strtoupper( $initial[0], 'UTF-8' ) : \strtoupper( $initial[0] );
				$avatar = '<span class="nova-service__testimonial-initial" aria-hidden="true">' . \esc_html( $letter ) . '</span>';
			}
			$attribution = '';
			if ( ! empty( $item['author'] ) ) {
				$author_url = self::review_https_url( (string) ( $item['author_url'] ?? '' ) );
				$author = '<strong>' . \esc_html( $item['author'] ) . '</strong>';
				$attribution .= '' !== $author_url && $link_sources ? '<a class="nova-service__review-author" href="' . \esc_url( $author_url ) . '" target="_blank" rel="noopener noreferrer">' . $author . '</a>' : $author;
			}
			if ( ! empty( $item['date'] ) ) {
				$attribution .= '<span class="nova-service__review-date">' . \esc_html( (string) $item['date'] ) . '</span>';
			}
			$person = '' !== $avatar || '' !== $attribution ? '<div class="nova-service__testimonial-person">' . $avatar . '<div class="nova-service__testimonial-attribution">' . $attribution . '</div></div>' : '';
			$publication = $testimonial ? '<div class="nova-service__testimonial-publication">' . $source_markup . $rating_markup . '</div>' : '';
			$cards .= '<figure class="nova-service__review nova-service__card' . ( $testimonial ? ' nova-service__testimonial' : '' ) . ( '' !== $design ? ' nova-service__review--designed nova-service__review--' . \esc_attr( $design ) . ( '' === $person ? ' nova-service__review--anonymous' : '' ) : '' ) . '">';
			if ( 'signature' === $design ) {
				$cards .= ( '' !== $person ? '<figcaption class="nova-service__signature-identity">' . $person . '</figcaption>' : '' ) . '<div class="nova-service__signature-content">' . $vendor_head . $title . $quote . $publication . '</div>';
			} elseif ( \in_array( $design, [ 'publisher_cards', 'compact_rail' ], true ) ) {
				$cards .= ( '' !== $person ? '<figcaption class="nova-service__review-identity">' . $person . '</figcaption>' : '' ) . $vendor_head . $title . $quote . $publication;
			} elseif ( '' !== $design ) {
				$cards .= $vendor_head . ( 'conversation' === $design ? '<div class="nova-service__review-bubble">' . $title . $quote . '</div>' : $title . $quote ) . ( '' !== $person || '' !== $publication ? '<figcaption class="nova-service__testimonial-footer">' . $person . $publication . '</figcaption>' : '' );
			} elseif ( $testimonial ) {
				$cards .= $title . $quote . '<figcaption class="nova-service__testimonial-footer">' . $person . $publication . '</figcaption>';
			} else {
				$cards .= $vendor_head . $title . $quote . '<figcaption>' . $avatar . $attribution . '</figcaption>';
			}
			$cards .= '</figure>';
		}
		return $cards;
	}

	/** A logo carries the source identity/link; only genuinely additional labels remain visible. */
	private static function review_source_attribution( string $provider, string $label, string $url, string $custom_logo, bool $link_sources, string $link_class, string $informative_label = '' ): string {
		$logo = self::review_brand_logo( $provider, $custom_logo );
		$linked = $link_sources && '' !== $url;
		$google = 'google' === $provider;
		if ( '' !== $logo ) {
			$identity = $linked ? '<a class="' . \esc_attr( $link_class ) . '" href="' . \esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . \esc_attr( $label ) . '"' . ( $google ? ' translate="no"' : '' ) . '>' . $logo . '</a>' : '<span role="img" aria-label="' . \esc_attr( $label ) . '"' . ( $google ? ' translate="no"' : '' ) . '>' . $logo . '</span>';
			return $identity . ( '' !== $informative_label ? '<span class="nova-service__source-text">' . \esc_html( $informative_label ) . '</span>' : '' );
		}
		if ( $linked ) {
			$identity = '<a class="' . \esc_attr( $link_class ) . ( $google ? ' nova-service__google-attribution' : '' ) . '" href="' . \esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"' . ( $google ? ' translate="no"' : '' ) . '>' . \esc_html( $label ) . ' <span aria-hidden="true">&#8599;</span></a>';
		} else {
			$identity = \esc_html( $label );
		}
		return $identity . ( '' !== $informative_label && $informative_label !== $label ? '<span class="nova-service__source-text">' . \esc_html( $informative_label ) . '</span>' : '' );
	}

	private static function review_provider_for_url( string $url ): string {
		$url = self::review_https_url( $url );
		if ( '' === $url ) { return ''; }
		$host = \strtolower( (string) \wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) \wp_parse_url( $url, PHP_URL_PATH );
		if ( \in_array( $host, [ 'google.com', 'www.google.com', 'maps.google.com', 'google.nl', 'www.google.nl', 'maps.google.nl' ], true ) && ( '/maps' === $path || 0 === \strpos( $path, '/maps/' ) || 0 === \strpos( $host, 'maps.' ) ) ) { return 'google'; }
		if ( \preg_match( '/^(?:(?:www|[a-z]{2}(?:-[a-z]{2})?)\.)?trustpilot\.com$/', $host ) && \preg_match( '#^/reviews?/#', $path ) ) { return 'trustpilot'; }
		if ( \in_array( $host, [ 'webwinkelkeur.nl', 'www.webwinkelkeur.nl' ], true ) && 0 === \strpos( $path, '/webshop/' ) ) { return 'webwinkelkeur'; }
		if ( \in_array( $host, [ 'kiyoh.com', 'www.kiyoh.com', 'kiyoh.nl', 'www.kiyoh.nl' ], true ) && 0 === \strpos( $path, '/reviews/' ) ) { return 'kiyoh'; }
		return \class_exists( ServiceReviewImporter::class ) ? ServiceReviewImporter::provider_for_url( $url ) : '';
	}

	/** Publisher logos are opt-in; a verified Google source uses the packaged official mark. */
	private static function review_brand_logo( string $provider, string $custom_logo = '' ): string {
		$url = '';
		if ( 'google' === $provider ) {
			foreach ( [ 'google-maps.svg', 'google-maps.png' ] as $filename ) {
				if ( \is_file( \dirname( __DIR__ ) . '/assets/review-providers/' . $filename ) ) {
					$url = \plugins_url( 'assets/review-providers/' . $filename, \dirname( __DIR__ ) . '/service-page-cpt.php' );
					break;
				}
			}
		}
		if ( '' === $url ) {
			$custom_logo = \trim( $custom_logo );
			$local = '/' === \substr( $custom_logo, 0, 1 ) && '/' !== \substr( $custom_logo, 1, 1 ) && ! \preg_match( '/[\\\\\x00-\x20]|%5c/i', $custom_logo );
			$url = $local ? \esc_url( $custom_logo ) : self::review_https_url( $custom_logo );
		}
		if ( '' === $url ) { return ''; }
		$google = 'google' === $provider;
		return '<span class="nova-service__provider-mark' . ( $google ? ' nova-service__provider-mark--google' : '' ) . '"><img class="nova-service__provider-logo" src="' . \esc_url( $url ) . '" alt="' . ( $google ? 'Google Maps' : '' ) . '" loading="lazy" decoding="async" /></span>';
	}

	private static function review_https_url( string $url ): string {
		$parts = \wp_parse_url( $url );
		return \is_array( $parts ) && 'https' === \strtolower( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && ( ! isset( $parts['port'] ) || 443 === (int) $parts['port'] ) ? \esc_url_raw( $url, [ 'https' ] ) : '';
	}

	private static function credentials( array $data ): string {
		$parts = [];
		$design = \in_array( $data['layout'] ?? '', self::CREDENTIAL_DESIGNS, true ) ? $data['layout'] : '';
		$link_sources = false !== ( $data['link_sources'] ?? true );
		foreach ( \is_array( $data['items'] ?? null ) ? $data['items'] : [] as $item ) {
			if ( ! \is_array( $item ) ) { continue; }
			$title_text = \trim( (string) ( $item['title'] ?? '' ) );
			$issuer_text = (string) ( $item['issuer'] ?? '' );
			$body = self::rich_body( (string) ( $item['body'] ?? '' ) );
			$image = ! empty( $item['image'] ) ? self::image( [ 'url' => $item['image'], 'alt' => $item['issuer'] ?? $item['title'] ?? '' ] ) : '';
			if ( '' === $title_text && '' === $image && '' === $body ) { continue; }
			$evidence = \esc_url( (string) ( $item['url'] ?? '' ) );
			$mark = '';
			if ( '' !== $image ) {
				$mark_label = $title_text ?: $issuer_text;
				$mark_label = '' !== $mark_label ? \sprintf( \__( 'View evidence for %s', 'nova-bridge-suite' ), $mark_label ) : \__( 'View credential', 'nova-bridge-suite' );
				$mark = '' !== $evidence && $link_sources && 'evidence_accordion' !== $design ? '<a class="nova-service__credential-mark" href="' . $evidence . '" target="_blank" rel="noopener noreferrer" aria-label="' . \esc_attr( $mark_label ) . '">' . $image . '</a>' : '<span class="nova-service__credential-mark">' . $image . '</span>';
			}
			$issuer = '' !== $issuer_text ? '<p class="nova-service__credential-issuer">' . \esc_html( $issuer_text ) . '</p>' : '';
			$title = '' !== $title_text ? '<h3 class="nova-service__card-title">' . \esc_html( $title_text ) . '</h3>' : '';
			$action = '';
			if ( '' !== $evidence && $link_sources ) {
				$label = ! empty( $item['source'] ) ? (string) $item['source'] : \__( 'View credential', 'nova-bridge-suite' );
				$action = '<a class="nova-service__text-link" href="' . $evidence . '" target="_blank" rel="noopener noreferrer">' . \esc_html( $label ) . ' <span aria-hidden="true">&#8599;</span></a>';
			}
			$copy = '<div class="nova-service__credential-copy">' . $issuer . $title . $body . $action . '</div>';
			if ( '' === $design ) {
				$parts[] = '<article class="nova-service__credential nova-service__card">' . $mark . $copy . '</article>';
				continue;
			}
			$visual = '' !== $mark ? $mark : '<span class="nova-service__credential-symbol" aria-hidden="true"></span>';
			if ( 'editorial' === $design && '' === $mark && '' !== $issuer_text ) {
				$visual = '<span class="nova-service__credential-word">' . \esc_html( $issuer_text ) . '</span>';
				$copy = '<div class="nova-service__credential-copy">' . $title . $body . $action . '</div>';
			}
			if ( 'evidence_accordion' === $design ) {
				$label = $title_text ?: ( $issuer_text ?: \__( 'Details', 'nova-bridge-suite' ) );
				$summary_issuer = '' !== $issuer_text && $issuer_text !== $label ? '<span class="nova-service__credential-issuer">' . \esc_html( $issuer_text ) . '</span>' : '';
				$parts[] = '<details class="nova-service__credential-disclosure"' . ( empty( $parts ) ? ' open' : '' ) . '><summary><h3 class="nova-service__credential-summary"><span class="nova-service__credential-visual">' . $visual . '</span><span class="nova-service__credential-summary-label">' . $summary_issuer . '<span>' . \esc_html( $label ) . '</span></span><span class="nova-service__credential-chevron" aria-hidden="true">+</span></h3></summary><div class="nova-service__credential-content">' . $body . $action . '</div></details>';
			} else {
				$parts[] = '<article class="nova-service__credential nova-service__card nova-service__credential--designed' . ( 'featured_membership' === $design && empty( $parts ) ? ' nova-service__credential--featured' : '' ) . '"><div class="nova-service__credential-visual">' . $visual . '</div>' . $copy . '</article>';
			}
		}
		if ( empty( $parts ) ) { return ''; }
		if ( 'featured_membership' === $design ) {
			$feature = \array_shift( $parts );
			$collection = '<div class="nova-service__credential-composition">' . $feature . ( $parts ? '<div class="nova-service__credential-facts">' . \implode( '', $parts ) . '</div>' : '' ) . '</div>';
		} else {
			$collection = '<div class="nova-service__cards' . ( '' !== $design ? ' nova-service__credential-collection' : '' ) . '"' . ( '' !== $design ? ' style="--nova-service-credential-columns:' . \min( 3, \count( $parts ) ) . '"' : '' ) . '>' . \implode( '', $parts ) . '</div>';
		}
		return self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . $collection;
	}

	private static function custom_cta( array $data ): string {
		$embed = ServiceDocument::safe_custom_html( (string) ( $data['html'] ?? '' ) );
		$shortcode = ServiceDocument::safe_shortcode( (string) ( $data['shortcode'] ?? '' ) );
		if ( '' !== $shortcode ) {
			// Trusted registered plugins control their generated HTML and enqueue their own assets.
			$embed .= self::live_preview_context() ? '<p class="nova-service__preview-placeholder">' . \esc_html__( 'Form or calendar shortcode: read-only preview placeholder.', 'nova-bridge-suite' ) . '</p>' : \do_shortcode( $shortcode );
		}
		if ( '' === \trim( $embed ) ) {
			return '';
		}
		return self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . '<div class="nova-service__embed" data-nova-service-embed>' . self::content_headings( $embed ) . '</div>';
	}

	private static function related( array $data, int $post_id ): string {
		$cards = '';
		$seen  = [];
		$ids   = \is_array( $data['post_ids'] ?? null ) ? $data['post_ids'] : [];
		$items = \is_array( $data['items'] ?? null ) ? $data['items'] : [];
		// Existing handpicked services and custom link cards remain explicit
		// overrides. Empty selections let the CPT supply useful cards itself.
		if ( empty( $ids ) && empty( $items ) ) {
			$ids = self::automatic_related_ids( $post_id );
		}
		foreach ( $ids as $related_id ) {
			$related_id = (int) $related_id;
			if ( $post_id === $related_id || isset( $seen[ $related_id ] ) || 'service_page' !== \get_post_type( $related_id ) || 'publish' !== \get_post_status( $related_id ) || \post_password_required( $related_id ) ) {
				continue;
			}
			$seen[ $related_id ] = true;
			$cards .= self::related_card( [ 'title' => \get_the_title( $related_id ), 'url' => \get_permalink( $related_id ), 'body' => self::card_excerpt( $related_id ), 'image_id' => \get_post_thumbnail_id( $related_id ) ] );
		}
		foreach ( $items as $item ) {
			$cards .= self::related_card( $item );
		}
		return '' !== $cards ? self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . '<div class="nova-service__cards">' . $cards . '</div>' : '';
	}

	/** Prefer shared subject/location terms, then use the Blog CPT's recent-post fallback. */
	private static function automatic_related_ids( int $post_id ): array {
		$language = self::related_language( $post_id );
		$args = [
			'post_type' => 'service_page', 'post_status' => 'publish', 'has_password' => false,
			'posts_per_page' => 12, 'fields' => 'ids', 'orderby' => [ 'date' => 'DESC', 'ID' => 'DESC' ],
			'no_found_rows' => true, 'ignore_sticky_posts' => true, 'suppress_filters' => false,
		];
		if ( 'polylang' === ( $language['provider'] ?? '' ) && '' !== $language['code'] ) {
			$args['lang'] = $language['code'];
		}
		$tax_query = [ 'relation' => 'OR' ];
		foreach ( \get_object_taxonomies( 'service_page', 'objects' ) as $name => $taxonomy ) {
			if ( empty( $taxonomy->public ) || \in_array( $name, [ 'language', 'post_translations' ], true ) ) {
				continue;
			}
			$terms = \wp_get_object_terms( $post_id, $name, [ 'fields' => 'ids' ] );
			if ( \is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$tax_query[] = [ 'taxonomy' => $name, 'field' => 'term_id', 'terms' => \array_map( 'intval', $terms ), 'include_children' => false ];
		}
		$phases = \count( $tax_query ) > 1 ? [ [ 'tax_query' => $tax_query ], [] ] : [ [] ];
		$seen = [ $post_id => true ];
		$selected = [];
		foreach ( $phases as $phase ) {
			$query = \array_merge( $args, $phase, [ 'post__not_in' => \array_keys( $seen ) ] );
			foreach ( \get_posts( $query ) as $candidate ) {
				$id = (int) ( \is_object( $candidate ) ? ( $candidate->ID ?? 0 ) : $candidate );
				if ( $id <= 0 || isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$post = \get_post( $id );
				if ( ! $post || ! empty( $post->post_password ) || 'service_page' !== \get_post_type( $id ) || 'publish' !== \get_post_status( $id ) || \post_password_required( $id ) ) {
					continue;
				}
				if ( $language && self::related_language( $id ) !== $language ) {
					continue;
				}
				$selected[] = $id;
				if ( 3 === \count( $selected ) ) {
					return $selected;
				}
			}
		}
		return $selected;
	}

	/** Use the source page's assigned language, never a guessed translation. */
	private static function related_language( int $post_id ): array {
		if ( \function_exists( 'pll_get_post_language' ) ) {
			return [ 'provider' => 'polylang', 'code' => (string) \pll_get_post_language( $post_id, 'slug' ) ];
		}
		$details = \apply_filters( 'wpml_post_language_details', null, $post_id );
		return \is_array( $details ) && isset( $details['language_code'] ) ? [ 'provider' => 'wpml', 'code' => (string) $details['language_code'] ] : [];
	}

	private static function card_excerpt( int $post_id ): string {
		$post  = \get_post( $post_id );
		$parts = [ $post ? (string) $post->post_excerpt : '' ];
		if ( '' === \trim( \wp_strip_all_tags( $parts[0] ) ) ) {
			$parts = [];
			if ( ServiceDocument::is_v2( $post_id ) ) {
				$document = ServiceDocument::resolve_document( $post_id );
				foreach ( $document['sections'] ?? [] as $section ) {
					if ( ! empty( $section['enabled'] ) && \in_array( $section['type'] ?? '', [ 'hero', 'rich_text' ], true ) ) {
						$data = (array) ( $section['data'] ?? [] );
						$parts[] = (string) ( $data['body'] ?? '' );
					}
				}
			} else {
				foreach ( [ 'sp_hero_copy', 'sp_intro', 'sp_main_1', 'sp_main_2', 'sp_main_3' ] as $key ) {
					$parts[] = (string) \get_post_meta( $post_id, $key, true );
				}
			}
		}
		// Never call get_the_excerpt/the_content: a singular service's content
		// filters can re-enter this renderer while generating related cards.
		$summary = \trim( \wp_strip_all_tags( \implode( ' ', $parts ) ) );
		$summary = (string) \preg_replace( '/\s+/', ' ', $summary );
		return \wp_trim_words( $summary, 18, '' );
	}

	private static function related_card( array $item ): string {
		$url = \esc_url( (string) ( $item['url'] ?? '' ) );
		if ( '' === $url || empty( $item['title'] ) ) {
			return '';
		}
		$html  = '<article class="nova-service__related-card nova-service__card">';
		$html .= self::image( [ 'id' => $item['image_id'] ?? 0, 'url' => $item['image'] ?? '', 'alt' => '' ] );
		$html .= '<div class="nova-service__related-copy"><h3 class="nova-service__card-title"><a href="' . $url . '">' . \esc_html( $item['title'] ) . ' <span aria-hidden="true">&#8594;</span></a></h3>';
		$summary = \wp_trim_words( \trim( \wp_strip_all_tags( (string) ( $item['body'] ?? '' ) ) ), 18, '' );
		$html .= '' !== $summary ? '<p class="nova-service__related-summary">' . \esc_html( $summary ) . '</p>' : '';
		return $html . '</div></article>';
	}

	private static function faq( array $data, array &$schema ): string {
		$html = '';
		foreach ( $data['items'] ?? [] as $item ) {
			$question = \trim( (string) ( $item['question'] ?? '' ) );
			$answer   = \wp_kses_post( (string) ( $item['answer'] ?? '' ) );
			if ( '' === $question || '' === \trim( \wp_strip_all_tags( $answer ) ) ) {
				continue;
			}
			$html .= '<details class="nova-service__faq"><summary><span>' . \esc_html( $question ) . '</span><span class="nova-service__faq-icon" aria-hidden="true">+</span></summary>' . self::rich_body( $answer ) . '</details>';
			$schema[] = [ '@type' => 'Question', 'name' => $question, 'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $answer ] ];
		}
		return '' !== $html ? self::heading( $data ) . self::rich_body( $data['body'] ?? '' ) . '<div class="nova-service__faqs">' . $html . '</div>' : '';
	}

	private static function brand_tokens(): string {
		$tokens = [];
		$colors = [];
		$factory = [];
		if ( \class_exists( ServiceOnboarding::class ) ) {
			$active = ServiceOnboarding::active_settings();
			$inherit = $active ? 'inherit_site' === ( $active['brand']['mode'] ?? '' ) : ServiceOnboarding::is_required();
			if ( $inherit ) { $factory = ServiceOnboarding::factory_settings(); }
		}
		$keys   = [ 'primary', 'contrast', 'surface', 'text', 'accent', 'border', 'hero_bg', 'hero_text', 'cta_bg', 'cta_text', 'button_bg', 'button_text', 'button_outline', 'faq_bg', 'faq_question', 'faq_answer' ];
		foreach ( $keys as $key ) {
			$option = 'service_cpt_color_' . $key;
			$value = \sanitize_hex_color( (string) \get_option( $option, '' ) );
			// Activation's old palette is not a deliberate client override. Fresh
			// setup follows theme tokens while retaining later custom color values.
			if ( $value && isset( $factory[ $option ] ) && 0 === \strcasecmp( $value, $factory[ $option ] ) ) { continue; }
			if ( $value ) {
				$colors[ $key ] = $value;
			}
		}
		// Old templates sometimes used white hero copy over an image. A native
		// hero has its own image column, so make its actual solid color readable.
		$known_paper = $colors['surface'] ?? null;
		$paper = $known_paper ?? '#ffffff';
		if ( null !== $known_paper && isset( $colors['text'] ) && self::contrast_ratio( $colors['text'], $paper ) < 4.5 ) {
			$colors['text'] = self::readable_text( $paper );
		}
		// Unknown theme colors stay in CSS, where Elementor/theme tokens resolve.
		// Only calculate a fixed tint when both deliberate override colors are known.
		$soft = null;
		if ( isset( $colors['accent'] ) && null !== $known_paper ) {
			$soft = self::tint( $colors['accent'], $known_paper, 0.06 );
			$colors['soft'] = $soft;
			if ( isset( $colors['text'] ) ) {
				$colors['soft_text'] = self::contrast_ratio( $colors['text'], $soft ) >= 4.5 ? $colors['text'] : self::readable_text( $soft );
			}
		}
		$pairs = [
			'hero_text' => [ $colors['hero_bg'] ?? $soft, $colors['hero_text'] ?? $colors['text'] ?? '#202b29' ],
			'cta_text' => [ $colors['cta_bg'] ?? $colors['primary'] ?? null, $colors['cta_text'] ?? $colors['contrast'] ?? '#ffffff' ],
			'button_text' => [ $colors['button_bg'] ?? $colors['primary'] ?? null, $colors['button_text'] ?? $colors['contrast'] ?? '#ffffff' ],
			'faq_question' => [ $colors['faq_bg'] ?? $known_paper, $colors['faq_question'] ?? $colors['text'] ?? '#202b29' ],
			'faq_answer' => [ $colors['faq_bg'] ?? $known_paper, $colors['faq_answer'] ?? $colors['text'] ?? '#202b29' ],
		];
		foreach ( $pairs as $text_key => $pair ) {
			if ( null !== $pair[0] && self::contrast_ratio( $pair[1], $pair[0] ) < 4.5 ) {
				$colors[ $text_key ] = self::readable_text( $pair[0] );
			}
		}
		foreach ( $colors as $key => $value ) {
			$tokens[] = '--nova-service-' . \str_replace( '_', '-', $key ) . ':' . $value;
		}
		$lengths = [ 'content_width' => 'content-width', 'wide_width' => 'wide-width', 'header_offset' => 'header-offset', 'space_section_gap' => 'section-gap', 'space_card_padding' => 'card-padding' ];
		foreach ( $lengths as $option => $property ) {
			$value = \trim( (string) \get_option( 'service_cpt_' . $option, '' ) );
			if ( \preg_match( '/^(?:0|(?:\d+(?:\.\d+)?|\.\d+)(?:px|rem|em|vw|vh|%))$/i', $value ) ) {
				$tokens[] = '--nova-service-' . $property . ':' . $value;
			}
		}
		return \implode( ';', $tokens );
	}

	private static function color_channels( string $color ): array {
		$hex = \ltrim( $color, '#' );
		if ( 3 === \strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return [ \hexdec( \substr( $hex, 0, 2 ) ), \hexdec( \substr( $hex, 2, 2 ) ), \hexdec( \substr( $hex, 4, 2 ) ) ];
	}

	private static function luminance( string $color ): float {
		$channels = \array_map( static function ( $channel ) {
			$value = $channel / 255;
			return $value <= 0.04045 ? $value / 12.92 : \pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}, self::color_channels( $color ) );
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	private static function contrast_ratio( string $first, string $second ): float {
		$a = self::luminance( $first );
		$b = self::luminance( $second );
		return ( \max( $a, $b ) + 0.05 ) / ( \min( $a, $b ) + 0.05 );
	}

	private static function readable_text( string $background ): string {
		$dark  = self::contrast_ratio( '#111827', $background );
		$light = self::contrast_ratio( '#ffffff', $background );
		if ( $light >= 4.5 && $light > $dark ) {
			return '#ffffff';
		}
		return $dark >= 4.5 ? '#111827' : '#000000';
	}

	private static function tint( string $color, string $surface, float $amount ): string {
		$color_channels = self::color_channels( $color );
		$surface_channels = self::color_channels( $surface );
		$channels = [];
		foreach ( $color_channels as $index => $channel ) {
			$channels[] = (int) \round( $channel * $amount + $surface_channels[ $index ] * ( 1 - $amount ) );
		}
		return \sprintf( '#%02x%02x%02x', $channels[0], $channels[1], $channels[2] );
	}
}
