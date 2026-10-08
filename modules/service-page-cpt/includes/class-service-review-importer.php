<?php
/** Public-profile review imports. No credentials, paid API, or challenge bypass. */
namespace SEORAI\ServicePageCPT;

defined( 'ABSPATH' ) || exit;

final class ServiceReviewImporter {
	private static $bootstrapped = false;
	private static $live = [];
	private const MAX_BODY = 2097152;
	private const MAX_REVIEWS = 30;

	public static function bootstrap(): void {
		if ( self::$bootstrapped ) { return; }
		self::$bootstrapped = true;
		\add_action( 'rest_api_init', static function () {
			\register_rest_route( 'nova-service/v1', '/reviews/import', [
				'methods' => 'POST', 'permission_callback' => static function () { return \current_user_can( 'edit_posts' ); },
				'args' => [ 'url' => [ 'type' => 'string', 'required' => true, 'maxLength' => 4096 ] ],
				'callback' => static function ( $request ) { $result = self::import_url( $request->get_param( 'url' ) ); return \is_wp_error( $result ) ? $result : \rest_ensure_response( $result ); },
			] );
		} );
	}

	private static function error( string $message, string $code = 'nova_service_review_import', int $status = 400, array $extra = [] ) {
		return new \WP_Error( $code, $message, array_merge( [ 'status' => $status ], $extra ) );
	}

	public static function provider_for_url( string $url ): string {
		if ( strlen( $url ) > 4096 || preg_match( '/[\x00-\x20\x7f<>\x22\x27`\\\\]/', $url ) ) { return ''; }
		$parts = \wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ) { return ''; }
		$host = strtolower( $parts['host'] ); $path = $parts['path'] ?? '/';
		$trustpilot = [ 'trustpilot.com', 'www.trustpilot.com', 'nl.trustpilot.com', 'de.trustpilot.com', 'uk.trustpilot.com', 'fr.trustpilot.com', 'es.trustpilot.com', 'it.trustpilot.com', 'dk.trustpilot.com', 'no.trustpilot.com', 'se.trustpilot.com', 'fi.trustpilot.com', 'au.trustpilot.com', 'ca.trustpilot.com', 'nz.trustpilot.com', 'ie.trustpilot.com', 'ch.trustpilot.com', 'at.trustpilot.com', 'be.trustpilot.com', 'pt.trustpilot.com', 'pl.trustpilot.com' ];
		if ( in_array( $host, $trustpilot, true ) && preg_match( '#^/review/[^/]+/?$#i', $path ) ) { return 'trustpilot'; }
		if ( in_array( $host, [ 'webwinkelkeur.nl', 'www.webwinkelkeur.nl', 'valuedshops.com', 'www.valuedshops.com' ], true ) && preg_match( '#^(?:(?:/webshop|/store)/[^/]+(?:/reviews)?|/webshops/reviews/[0-9]+)/?$#i', $path ) ) { return 'webwinkelkeur'; }
		// This public profile path is linked by the current WebwinkelKeur member page.
		if ( 'dashboard.webwinkelkeur.nl' === $host && preg_match( '#^/webshops/reviews/[0-9]+/?$#', $path ) ) { return 'webwinkelkeur'; }
		if ( in_array( $host, [ 'thuiswinkel.org', 'www.thuiswinkel.org' ], true ) && preg_match( '#^/leden/[^/]+(?:/certificaat)?/?$#i', $path ) ) { return 'thuiswinkel'; }
		if ( self::kiyoh_url( $url ) ) { return 'kiyoh'; }
		if ( in_array( $host, [ 'google.com', 'www.google.com', 'maps.google.com', 'google.nl', 'www.google.nl', 'maps.google.nl' ], true ) && ( '/maps' === $path || 0 === strpos( $path, '/maps/' ) || ( '/' === $path && ! empty( $parts['query'] ) ) ) ) { return 'google'; }
		if ( in_array( $host, [ 'maps.app.goo.gl', 'goo.gl', 'g.co' ], true ) && preg_match( '#^/(?:maps/)?[a-zA-Z0-9_-]+/?$#', $path ) ) { return 'google'; }
		return '';
	}

	public static function canonical_profile_url( string $url ): string {
		$url = trim( $url );
		if ( '' === self::provider_for_url( $url ) ) { return ''; }
		$parts = \wp_parse_url( $url );
		return 'https://' . strtolower( $parts['host'] ) . ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	/** Each redirect is validated before a network request, including DNS/IP safety. */
	private static function fetch_profile( string $url, string $provider ) {
		for ( $redirect = 0; $redirect <= 3; $redirect++ ) {
			if ( self::provider_for_url( $url ) !== $provider && ! ( 'thuiswinkel' === $provider && self::kiyoh_url( $url ) ) ) { return self::error( 'The review profile redirected to an unsupported website.' ); }
			$result = \wp_safe_remote_get( $url, [ 'timeout' => 12, 'redirection' => 0, 'limit_response_size' => self::MAX_BODY, 'headers' => [ 'Accept' => 'text/html, application/ld+json, application/json;q=0.9', 'Cache-Control' => 'no-cache' ], 'reject_unsafe_urls' => true ] );
			if ( \is_wp_error( $result ) ) { return self::error( 'The review provider could not be reached. Try the public company profile link again later.', 'nova_service_review_unavailable', 502 ); }
			$status = (int) \wp_remote_retrieve_response_code( $result );
			if ( in_array( $status, [ 301, 302, 303, 307, 308 ], true ) ) {
				$location = \wp_remote_retrieve_header( $result, 'location' );
				if ( ! is_string( $location ) || '' === $location ) { return self::error( 'The review provider returned an invalid redirect.' ); }
				if ( 0 === strpos( $location, '/' ) && 0 !== strpos( $location, '//' ) ) { $location = 'https://' . \wp_parse_url( $url, PHP_URL_HOST ) . $location; }
				$url = $location;
				continue;
			}
			if ( 200 !== $status ) { return self::error( 'The provider does not expose this review profile to a normal public request (HTTP ' . $status . '). Use its official embed or enter verified reviews manually.', 'nova_service_review_unavailable', 422 ); }
			$body = \wp_remote_retrieve_body( $result );
			if ( ! is_string( $body ) || '' === trim( $body ) ) { return self::error( 'The review provider returned an empty profile.' ); }
			return [ 'url' => $url, 'body' => $body ];
		}
		return self::error( 'The review profile redirected too many times.' );
	}

	private static function kiyoh_url( string $url ): bool {
		$parts = \wp_parse_url( $url );
		return is_array( $parts ) && 'https' === strtolower( $parts['scheme'] ?? '' ) && in_array( strtolower( $parts['host'] ?? '' ), [ 'kiyoh.com', 'www.kiyoh.com', 'kiyoh.nl', 'www.kiyoh.nl' ], true ) && empty( $parts['user'] ) && empty( $parts['pass'] ) && ( ! isset( $parts['port'] ) || 443 === $parts['port'] ) && preg_match( '#^/(?:reviews|beoordelingen)/[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)?/?$#i', $parts['path'] ?? '' );
	}

	public static function import_url( string $url ) {
		$url = self::canonical_profile_url( $url );
		if ( ! $url ) { return self::error( 'Paste an HTTPS company profile link from Trustpilot, Google Maps, WebwinkelKeur or Thuiswinkel.' ); }
		$provider = self::provider_for_url( $url );
		$cache_key = 'nova_service_reviews_v2_' . hash( 'sha256', $url );
		// Google source content is live and never persisted in a transient.
		if ( 'google' !== $provider ) { $cached = \get_transient( $cache_key ); if ( is_array( $cached ) ) { return $cached; } }
		elseif ( isset( self::$live[ $url ] ) ) { return self::$live[ $url ]; }
		$fetched = self::fetch_profile( $url, $provider );
		if ( \is_wp_error( $fetched ) ) {
			// A consent/blocked redirect cannot invalidate the trusted original map profile.
			if ( 'google' === $provider ) { return self::google_fallback( $url, self::google_embed_url( $url ) ); }
			return $fetched;
		}
		$result = self::extract_html( $fetched['body'], $provider, $url );
		if ( 'thuiswinkel' === $provider && ! $result['reviews'] && null === $result['average_rating'] ) {
			$partner = self::find_kiyoh_link( $fetched['body'] );
			if ( $partner ) {
				$partner_page = self::fetch_profile( $partner, 'thuiswinkel' );
				if ( ! \is_wp_error( $partner_page ) ) { $result = self::extract_html( $partner_page['body'], $provider, $url ); $result['warnings'][] = 'Reviews come from the linked Kiyoh profile, the Thuiswinkel review partner.'; foreach ( $result['reviews'] as &$review ) { $review['source_url'] = $partner; } unset( $review ); }
			}
		}
		if ( ! $result['reviews'] && null === $result['average_rating'] ) {
			$message = 'google' === $provider ? 'Google Maps does not expose verified reviews or an average through this public link. Keep the Google profile link or use its supported map embed; no reviews were invented or copied.' : ( 'thuiswinkel' === $provider ? 'This Thuiswinkel page is a membership certificate, without public review data. It can be displayed as a certification link. If it links a Kiyoh review profile, use that linked profile or an official review widget.' : 'The profile does not expose public structured reviews or an average. Use the provider’s official review widget or enter verified reviews manually.' );
			if ( 'google' === $provider ) { return self::google_fallback( $url, self::google_embed_url( $fetched['url'] ) ?: self::google_embed_url( $url ) ); }
			return self::error( $message, 'nova_service_review_no_public_data', 422 );
		}
		if ( ! $result['reviews'] ) { $result['warnings'][] = 'The provider exposes an aggregate score but no individual reviews to select. The aggregate can still be displayed with its source link.'; }
		if ( 'google' === $provider ) { self::$live[ $url ] = $result; }
		else { \set_transient( $cache_key, $result, 300 ); }
		return $result;
	}

	private static function google_fallback( string $url, string $embed_url ) {
		return self::error( 'Google Maps does not expose selectable reviews or an average through this public link. Add the Google profile link and enter verified reviews manually; individual review import is unavailable.', 'nova_service_review_no_public_data', 422, [ 'fallback' => [ 'provider' => 'google', 'url' => $url, 'embed_url' => $embed_url, 'display' => 'profile' ] ] );
	}

	private static function find_kiyoh_link( string $html ): string {
		if ( preg_match_all( '/\bhref\s*=\s*([\x22\x27])(.*?)\1/is', $html, $links ) ) {
			foreach ( $links[2] as $link ) { $link = html_entity_decode( $link, ENT_QUOTES | ENT_HTML5, 'UTF-8' ); if ( self::kiyoh_url( $link ) ) { return $link; } }
		}
		return '';
	}

	/** Only structured source facts are used; selected reviews never define an average. */
	public static function extract_html( string $html, string $provider, string $url ): array {
		$result = [ 'provider' => $provider, 'url' => $url, 'business_name' => '', 'average_rating' => null, 'rating_scale' => 5, 'aggregate_scope' => 'provider', 'review_count' => null, 'reviews' => [], 'fetched_at' => gmdate( 'c' ), 'warnings' => [] ];
		$documents = [];
		$direct = json_decode( trim( $html ), true, 64 );
		if ( is_array( $direct ) && ( isset( $direct['@context'] ) || isset( $direct['@type'] ) ) ) { $documents[] = $direct; }
		if ( preg_match_all( '/<script\b[^>]*\btype\s*=\s*([\x22\x27])application\/ld\+json\1[^>]*>(.*?)<\/script\s*>/is', $html, $scripts ) ) {
			foreach ( $scripts[2] as $script ) {
				$json = json_decode( trim( $script ), true, 64 );
				if ( ! is_array( $json ) ) { $json = json_decode( html_entity_decode( trim( $script ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true, 64 ); }
				if ( is_array( $json ) ) { $documents[] = $json; }
			}
		}
		$nodes = [];
		$walk = static function ( $node, int $depth = 0 ) use ( &$walk, &$nodes ) {
			if ( ! is_array( $node ) || $depth > 32 || count( $nodes ) > 1500 ) { return; }
			if ( isset( $node['@type'] ) || isset( $node['aggregateRating'] ) || isset( $node['reviewRating'] ) ) { $nodes[] = $node; }
			foreach ( $node as $child ) { if ( is_array( $child ) ) { $walk( $child, $depth + 1 ); } }
		};
		foreach ( $documents as $document ) { $walk( $document ); }
		$aggregates = [];
		foreach ( $nodes as $node ) {
			$types = (array) ( $node['@type'] ?? [] );
			if ( isset( $node['aggregateRating'] ) && is_array( $node['aggregateRating'] ) && ! in_array( 'Product', $types, true ) ) {
				$fact = self::rating_fact( $node['aggregateRating'] );
				$count = self::count_value( $node['aggregateRating']['reviewCount'] ?? ( $node['aggregateRating']['ratingCount'] ?? null ) );
				if ( null !== $fact ) {
					$score = count( array_intersect( $types, [ 'Organization', 'LocalBusiness', 'Store', 'ProfessionalService' ] ) ) ? 10 : 0;
					if ( rtrim( self::public_url( $node['url'] ?? '' ), '/' ) === rtrim( $url, '/' ) || rtrim( self::public_url( $node['@id'] ?? '' ), '/' ) === rtrim( $url, '/' ) ) { $score += 20; }
					$aggregates[] = [ 'score' => $score, 'rating' => $fact['value'], 'scale' => $fact['scale'], 'count' => $count, 'name' => self::text( $node['name'] ?? '' ) ];
				}
			}
			if ( in_array( 'Review', $types, true ) || isset( $node['reviewRating'] ) ) {
				$fact = self::rating_fact( (array) ( $node['reviewRating'] ?? [] ) );
				$rating = null === $fact ? null : round( $fact['value'] / $fact['scale'] * 5, 2 );
				$body = self::text( $node['reviewBody'] ?? ( $node['description'] ?? '' ) );
				$author = $node['author'] ?? '';
				$author_name = self::text( is_array( $author ) ? ( $author['name'] ?? '' ) : $author );
				if ( null === $rating || '' === $body || '' === $author_name ) { continue; }
				$review_url = self::public_url( $node['url'] ?? '' );
				$id = self::text( $node['@id'] ?? ( $node['identifier'] ?? '' ) );
				$date = self::text( $node['datePublished'] ?? '' );
				if ( ! $id ) { $id = hash( 'sha256', $provider . '|' . $review_url . '|' . $author_name . '|' . $date . '|' . $body ); }
				$result['reviews'][] = [ 'id' => substr( $id, 0, 500 ), 'author' => $author_name, 'author_url' => is_array( $author ) ? self::public_url( $author['url'] ?? '' ) : '', 'author_image' => is_array( $author ) ? self::public_url( $author['image'] ?? '' ) : '', 'rating' => $rating, 'original_rating' => $fact['value'], 'original_scale' => $fact['scale'], 'body' => substr( $body, 0, 10000 ), 'url' => $review_url ?: $url, 'date' => $date, 'source_url' => $url ];
				if ( ! $result['business_name'] && is_array( $node['itemReviewed'] ?? null ) ) { $result['business_name'] = self::text( $node['itemReviewed']['name'] ?? '' ); }
			}
		}
		if ( $aggregates ) { usort( $aggregates, static function ( $a, $b ) { return $b['score'] <=> $a['score']; } ); $result['average_rating'] = $aggregates[0]['rating']; $result['rating_scale'] = $aggregates[0]['scale']; $result['review_count'] = $aggregates[0]['count']; if ( $aggregates[0]['name'] ) { $result['business_name'] = $aggregates[0]['name']; } }
		if ( 'webwinkelkeur' === $provider ) {
			$result = self::extract_webwinkelkeur( $html, $url, $result );
			if ( null !== $result['average_rating'] && 'combined' !== $result['aggregate_scope'] ) { $result['warnings'][] = 'Only WebwinkelKeur’s own source score and count were available. These may differ from the combined score across other sources on its profile.'; }
		}
		$unique = [];
		foreach ( $result['reviews'] as $review ) { $unique[ $review['id'] ] = $review; }
		$result['reviews'] = array_slice( array_values( $unique ), 0, self::MAX_REVIEWS );
		if ( null === $result['average_rating'] && $result['reviews'] ) { $result['warnings'][] = 'Individual reviews were found but the provider did not expose an aggregate. No average was calculated from this selected sample.'; }
		return $result;
	}

	/** Read the public MemberPage hydration JSON; never execute its script. */
	private static function extract_webwinkelkeur( string $html, string $url, array $result ): array {
		if ( ! preg_match_all( '/<script\b[^>]*>(.*?)<\/script\s*>/is', $html, $scripts ) ) { return $result; }
		foreach ( $scripts[1] as $script ) {
			if ( ! preg_match( '/[\x22\x27]?component[\x22\x27]?\s*:\s*[\x22\x27]MemberPage[\x22\x27]/', $script, $component, PREG_OFFSET_CAPTURE ) ) { continue; }
			$offset = $component[0][1] + strlen( $component[0][0] );
			if ( ! preg_match( '/(?:\bprops|[\x22\x27]props[\x22\x27])\s*:\s*/', $script, $property, PREG_OFFSET_CAPTURE, $offset ) ) { continue; }
			$end = 0;
			$props = self::json_object_at( $script, $property[0][1] + strlen( $property[0][0] ), $end );
			$shop = $props['webshop']['Webshop'] ?? null;
			if ( ! is_array( $shop ) || empty( $shop['id'] ) || ! is_numeric( $shop['id'] ) ) { continue; }
			if ( preg_match( '#/(?:webshops/reviews/([0-9]+)|(?:webshop|store)/[^/]*_([0-9]+))(?:/reviews)?/?$#', \wp_parse_url( $url, PHP_URL_PATH ) ?? '', $identity ) && (int) ( $identity[1] ?: $identity[2] ) !== (int) $shop['id'] ) { continue; }
			if ( ! preg_match( '/^\s*,\s*(?:context|[\x22\x27]context[\x22\x27])\s*:\s*/', substr( $script, $end ), $property ) ) { continue; }
			$context = self::json_object_at( $script, $end + strlen( $property[0] ), $end );
			$cache = $context['hybridAsyncComputed'] ?? null;
			if ( ! is_array( $cache ) ) { continue; }
			$name = self::text( $shop['bedrijfsnaam'] ?? '' );
			if ( $name ) { $result['business_name'] = $name; }
			foreach ( $cache as $entry ) {
				$value = is_array( $entry ) ? ( $entry['value'] ?? null ) : null;
				if ( ! is_array( $value ) ) { continue; }
				if ( array_key_exists( 'rating', $value ) && array_key_exists( 'count', $value ) ) {
					$fact = self::rating_fact( [ 'ratingValue' => $value['rating'], 'bestRating' => 10 ] );
					$count = self::count_value( $value['count'] );
					if ( null !== $fact && null !== $count ) {
						// The public profile displays its combined score to one decimal.
						$result['average_rating'] = round( $fact['value'], 1 ); $result['rating_scale'] = 10;
						$result['review_count'] = $count; $result['aggregate_scope'] = 'combined';
					}
				}
				if ( ! isset( $value['pagination'] ) || ! is_array( $value['data'] ?? null ) ) { continue; }
				foreach ( $value['data'] as $review ) {
					if ( ! is_array( $review ) || 'review' !== ( $review['type'] ?? '' ) || ! empty( $review['quarantine'] ) ) { continue; }
					$fact = self::rating_fact( [ 'ratingValue' => $review['rating_value'] ?? null, 'bestRating' => 5 ] );
					$body = self::text( $review['review_text'] ?? '' ); $author = self::text( $review['name'] ?? '' );
					if ( null === $fact || '' === $body || '' === $author ) { continue; }
					$id = self::text( $review['public_id'] ?? ( $review['id'] ?? '' ) );
					if ( ! $id ) { $id = hash( 'sha256', $author . '|' . (string) ( $review['review_date'] ?? '' ) . '|' . $body ); }
					$result['reviews'][] = [ 'id' => substr( 'webwinkelkeur:' . $id, 0, 500 ), 'author' => $author, 'author_url' => '', 'author_image' => '', 'rating' => $fact['value'], 'original_rating' => $fact['value'], 'original_scale' => 5, 'body' => substr( $body, 0, 10000 ), 'url' => $url, 'date' => self::text( $review['review_date'] ?? '' ), 'source_url' => $url ];
				}
			}
			if ( 'combined' === $result['aggregate_scope'] ) { $result['warnings'][] = 'The overall score is the combined score published by WebwinkelKeur across its listed sources. The selectable cards are reviews published directly on this profile.'; }
			break;
		}
		return $result;
	}

	private static function json_object_at( string $source, int $offset, int &$end ): ?array {
		$length = strlen( $source );
		while ( $offset < $length && ctype_space( $source[ $offset ] ) ) { $offset++; }
		if ( $offset >= $length || '{' !== $source[ $offset ] ) { return null; }
		$depth = 0; $quoted = false; $escaped = false;
		for ( $index = $offset; $index < $length; $index++ ) {
			$character = $source[ $index ];
			if ( $quoted ) { if ( $escaped ) { $escaped = false; } elseif ( '\\' === $character ) { $escaped = true; } elseif ( '"' === $character ) { $quoted = false; } continue; }
			if ( '"' === $character ) { $quoted = true; }
			elseif ( '{' === $character || '[' === $character ) { $depth++; }
			elseif ( '}' === $character || ']' === $character ) {
				$depth--;
				if ( 0 === $depth ) { $end = $index + 1; $value = json_decode( substr( $source, $offset, $end - $offset ), true, 64 ); return is_array( $value ) ? $value : null; }
			}
		}
		return null;
	}

	private static function text( $value ): string { return is_scalar( $value ) ? trim( \wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) : ''; }
	private static function count_value( $value ): ?int { return ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^[0-9]+$/', $value ) ) ) && (float) $value <= PHP_INT_MAX ? max( 0, (int) $value ) : null; }
	private static function rating_fact( array $rating ): ?array {
		$value = $rating['ratingValue'] ?? null; $best = $rating['bestRating'] ?? 5; $worst = $rating['worstRating'] ?? 0;
		if ( ! is_numeric( $value ) || ! is_numeric( $best ) || ! is_numeric( $worst ) || ! in_array( (float) $best, [ 5.0, 10.0 ], true ) || (float) $best <= (float) $worst || (float) $value < (float) $worst || (float) $value > (float) $best ) { return null; }
		return [ 'value' => (float) $value, 'scale' => (int) $best ];
	}
	private static function public_url( $value ): string {
		if ( ! is_string( $value ) || preg_match( '/[\x00-\x20\x7f<>\x22\x27`\\\\]/', $value ) ) { return ''; }
		$parts = \wp_parse_url( $value );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] ) ? \esc_url_raw( $value ) : '';
	}

	/** Google content exists only in the current response, never in post meta/cache. */
	public static function resolve_source( array $source ) {
		if ( 'google' !== ( $source['provider'] ?? '' ) ) { return $source; }
		$embed = ! empty( $source['embed_url'] ) && ServiceDocument::valid_map_url( $source['embed_url'] ) ? $source['embed_url'] : '';
		if ( $embed && 'widget' === ( $source['display'] ?? 'profile' ) ) { return [ 'provider' => 'google', 'url' => $source['url'] ?? '', 'display' => 'widget', 'embed_url' => $embed, 'business_name' => '', 'average_rating' => null, 'rating_scale' => 5, 'review_count' => null, 'reviews' => [], 'warnings' => [ 'Google displays its current business information inside this official map widget. Individual review selection is unavailable for this public profile.' ] ]; }
		// A compact profile link needs no remote fetch. Older widget references
		// also follow this default until the editor explicitly opts into a map.
		if ( empty( $source['selected_review_ids'] ) ) { return [ 'provider' => 'google', 'url' => $source['url'] ?? '', 'display' => 'profile', 'business_name' => '', 'average_rating' => null, 'rating_scale' => 5, 'review_count' => null, 'reviews' => [], 'warnings' => [] ]; }
		$result = self::import_url( (string) ( $source['url'] ?? '' ) );
		if ( \is_wp_error( $result ) ) { return $result; }
		$selected = array_fill_keys( (array) ( $source['selected_review_ids'] ?? [] ), true );
		$result['reviews'] = array_values( array_filter( $result['reviews'], static function ( $review ) use ( $selected ) { return isset( $selected[ $review['id'] ] ); } ) );
		return $result;
	}

	public static function google_embed_url( string $url ): string {
		if ( 'google' !== self::provider_for_url( $url ) ) { return ''; }
		$parts = \wp_parse_url( $url ); $query = []; parse_str( $parts['query'] ?? '', $query );
		$cid = isset( $query['cid'] ) && is_string( $query['cid'] ) && preg_match( '/^[0-9]{1,20}$/', $query['cid'] ) ? $query['cid'] : '';
		if ( ! $cid && preg_match( '/!1s0x[0-9a-f]+:0x([0-9a-f]{1,16})(?:!|$)/i', rawurldecode( $parts['path'] ?? '' ), $match ) ) { $cid = self::hex_decimal( $match[1] ); }
		return $cid ? 'https://www.google.com/maps?cid=' . $cid . '&output=embed' : '';
	}

	private static function hex_decimal( string $hex ): string {
		$decimal = '0';
		foreach ( str_split( strtolower( $hex ) ) as $digit ) {
			$carry = hexdec( $digit ); $next = '';
			for ( $index = strlen( $decimal ) - 1; $index >= 0; $index-- ) { $value = (int) $decimal[ $index ] * 16 + $carry; $next = ( $value % 10 ) . $next; $carry = intdiv( $value, 10 ); }
			while ( $carry ) { $next = ( $carry % 10 ) . $next; $carry = intdiv( $carry, 10 ); }
			$decimal = ltrim( $next, '0' ) ?: '0';
		}
		return $decimal;
	}

	public static function context(): array {
		return [ 'route' => '/nova-service/v1/reviews/import', 'method' => 'POST', 'body' => [ 'url' => 'HTTPS public company review profile' ], 'providers' => [ 'trustpilot', 'google', 'webwinkelkeur', 'thuiswinkel', 'kiyoh' ], 'guidance' => 'Import verified public structured reviews by profile link, inspect the result, select the reviews to display, and retain source attribution. The provider aggregate and its published 5- or 10-point scale are independent of selected cards. Unknown aggregate values are omitted, never calculated from a sample. Provider protection or missing public data produces an honest error, without placeholders. Google profiles without public structured reviews offer a compact profile link; enter verified manual cards with their platform and original source URL. Maps are an explicit optional display choice rather than the reviews default. Do not claim Google reviews can be individually selected when unavailable. Google selections store only profile/identifier references and are resolved live without a paid API or persistent Google content cache. Thuiswinkel certificate pages are credentials; reviews may come from their linked Kiyoh partner profile.' ];
	}
}
