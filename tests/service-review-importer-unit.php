<?php
/** Offline public-profile import behavior/security fixtures. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
$GLOBALS['import_calls'] = []; $GLOBALS['import_responses'] = []; $GLOBALS['import_cache'] = [];
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $text ) { return strip_tags( preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text ) ); }
function esc_url_raw( $url ) { return $url; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_transient( $key ) { return $GLOBALS['import_cache'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['import_cache'][ $key ] = $value; }
function wp_safe_remote_get( $url, $args ) { $GLOBALS['import_calls'][] = [ $url, $args ]; return $GLOBALS['import_responses'][ $url ] ?? new WP_Error( 'network', 'Not available' ); }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
function wp_remote_retrieve_body( $response ) { return $response['body'] ?? ''; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function rest_validate_value_from_schema( $value, $schema, $field ) { return true; }

require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-document.php';
require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-review-importer.php';
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceReviewImporter as Importer;
$checks = 0;
function check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }
function html( $data ) { return '<!doctype html><script type="application/ld+json">' . json_encode( $data, JSON_UNESCAPED_SLASHES ) . '</script>'; }
function source_doc( $source ) { return [ 'version' => 2, 'sections' => [ [ 'id' => 'reviews', 'type' => 'reviews', 'enabled' => true, 'data' => [ 'review_source' => $source ] ] ] ]; }

$profile = 'https://www.trustpilot.com/review/example.test';
$facts = [ '@context' => 'https://schema.org', '@type' => 'Organization', 'name' => 'Example business', 'url' => $profile, 'aggregateRating' => [ '@type' => 'AggregateRating', 'ratingValue' => '4.8', 'bestRating' => '5', 'reviewCount' => '253' ], 'review' => [ [ '@type' => 'Review', '@id' => 'review-a', 'author' => [ '@type' => 'Person', 'name' => 'Reviewer A', 'url' => 'https://www.trustpilot.com/users/user-a' ], 'reviewRating' => [ 'ratingValue' => 5 ], 'reviewBody' => 'Excellent personal support.', 'datePublished' => '2026-10-01', 'url' => 'https://www.trustpilot.com/reviews/review-a' ], [ '@type' => 'Review', '@id' => 'review-b', 'author' => 'Reviewer B', 'reviewRating' => [ 'ratingValue' => 2 ], 'reviewBody' => 'A verified second experience.' ] ] ];
$parsed = Importer::extract_html( html( $facts ), 'trustpilot', $profile );
check( 4.8 === $parsed['average_rating'] && 253 === $parsed['review_count'] && 5 === $parsed['rating_scale'], 'provider aggregate stays independent of the two selected review scores' );
check( 2 === count( $parsed['reviews'] ) && 'Reviewer A' === $parsed['reviews'][0]['author'] && 5.0 === $parsed['reviews'][0]['rating'], 'review text, author and score import from actual structured data' );
check( 'https://www.trustpilot.com/users/user-a' === $parsed['reviews'][0]['author_url'] && '2026-10-01' === $parsed['reviews'][0]['date'], 'review attribution and original publication date are retained' );
$only = [ '@context' => 'https://schema.org', '@type' => 'Review', 'author' => 'Only reviewer', 'reviewBody' => 'Only one public review.', 'reviewRating' => [ 'ratingValue' => 5 ] ];
$without_average = Importer::extract_html( html( $only ), 'trustpilot', $profile );
check( null === $without_average['average_rating'] && null === $without_average['review_count'] && count( $without_average['reviews'] ) === 1 && ! empty( $without_average['warnings'] ), 'no aggregate or count is fabricated from sample reviews' );
$ten = $facts; $ten['aggregateRating'] = [ 'ratingValue' => 9.5, 'bestRating' => 10, 'reviewCount' => 112 ]; $ten['review'][0]['reviewRating'] = [ 'ratingValue' => 8, 'bestRating' => 10 ];
$ten_result = Importer::extract_html( html( $ten ), 'kiyoh', 'https://www.kiyoh.com/reviews/12345/example' );
check( 9.5 === $ten_result['average_rating'] && 10 === $ten_result['rating_scale'], 'ten-point vendor aggregate remains published as 9.5 out of 10' );
check( 4.0 === $ten_result['reviews'][0]['rating'] && 8.0 === $ten_result['reviews'][0]['original_rating'] && 10 === $ten_result['reviews'][0]['original_scale'], 'individual stars normalize while original ten-point source score remains available' );
$unsafe = $only; $unsafe['reviewBody'] = '&lt;script&gt;alert(1)&lt;/script&gt;<b>Safe content</b>'; $unsafe['author'] = [ 'name' => 'Person', 'url' => 'javascript:alert(1)' ];
$clean = Importer::extract_html( html( $unsafe ), 'trustpilot', $profile );
check( 'Safe content' === $clean['reviews'][0]['body'] && '' === $clean['reviews'][0]['author_url'], 'encoded HTML scripts and unsafe attribution links never become imported markup' );
$bad = $facts; $bad['aggregateRating']['ratingValue'] = 99; $bad['review'][0]['reviewRating']['ratingValue'] = -1;
$invalid = Importer::extract_html( html( $bad ), 'trustpilot', $profile );
check( null === $invalid['average_rating'] && 1 === count( $invalid['reviews'] ), 'out-of-scale provider values are rejected rather than clamped or invented' );
$facts['url'] = [ 'malformed' => 'shape' ];
check( is_array( Importer::extract_html( html( $facts ), 'trustpilot', $profile ) ), 'malformed optional structured URL shape cannot crash the parser' );

$wwk_url = 'https://www.webwinkelkeur.nl/webshops/reviews/1';
$wwk_props = [ 'webshop' => [ 'Webshop' => [ 'id' => 1, 'bedrijfsnaam' => 'Fixture company', 'ratings_average' => 4.8 ] ] ];
$wwk_context = [ 'hybridAsyncComputed' => [
	'hybridFetch:reviews' => [ 'value' => [ 'data' => [ [ 'type' => 'review', 'public_id' => 'fixture-a', 'name' => 'Published reviewer', 'rating_value' => 4, 'review_text' => 'A public review with braces { } and "quoted" text.', 'review_date' => '2026-10-03' ], [ 'type' => 'review', 'public_id' => 'quarantined', 'name' => 'Under investigation', 'rating_value' => 5, 'review_text' => 'Not eligible to select.', 'quarantine' => true ] ], 'pagination' => [ 'page' => 1 ] ] ],
	'hybridFetch:overall' => [ 'value' => [ 'rating' => 9.421339, 'count' => 2371, 'ratings_count12m' => 87 ] ],
] ];
$wwk_script = '<script type="module">mod.init({"vue-component-1":{component:"MemberPage",wrapper:"Tailwind",props:' . json_encode( $wwk_props ) . ',context:' . json_encode( $wwk_context ) . '}});</script>';
$wwk_parsed = Importer::extract_html( html( $ten ) . $wwk_script, 'webwinkelkeur', $wwk_url );
check( 9.4 === $wwk_parsed['average_rating'] && 2371 === $wwk_parsed['review_count'] && 'combined' === $wwk_parsed['aggregate_scope'], 'public WebwinkelKeur hydration uses published combined score instead of its own-source JSON-LD subset' );
check( 'Fixture company' === $wwk_parsed['business_name'] && 'Published reviewer' === $wwk_parsed['reviews'][2]['author'] && 'A public review with braces { } and "quoted" text.' === $wwk_parsed['reviews'][2]['body'], 'public MemberPage review cards import without executing script and preserve quoted/bracketed content' );
check( 3 === count( $wwk_parsed['reviews'] ) && ! empty( $wwk_parsed['warnings'] ), 'quarantined review is omitted and combined-source summary is explained' );
$wwk_wrong_profile = Importer::extract_html( $wwk_script, 'webwinkelkeur', 'https://www.webwinkelkeur.nl/webshops/reviews/2' );
check( null === $wwk_wrong_profile['average_rating'] && [] === $wwk_wrong_profile['reviews'], 'hydration for a different numeric company identity cannot override this profile' );
$wwk_own = Importer::extract_html( html( $ten ), 'webwinkelkeur', $wwk_url );
check( 'provider' === $wwk_own['aggregate_scope'] && ! empty( $wwk_own['warnings'] ), 'WebwinkelKeur source-only fallback explicitly warns that its score differs from combined profile data' );
$wwk_executable = str_replace( json_encode( $wwk_props ), '{"webshop":function(){throw new Error("Never execute");}}', $wwk_script );
check( null === Importer::extract_html( $wwk_executable, 'webwinkelkeur', $wwk_url )['average_rating'], 'non-JSON executable hydration syntax is rejected without evaluating JavaScript' );

foreach ( [ 'http://www.trustpilot.com/review/example.test', 'https://www.trustpilot.com.evil.test/review/example', 'https://127.0.0.1/review/example', 'https://www.trustpilot.com@127.0.0.1/review/example', 'https://www.trustpilot.com:444/review/example', 'https://www.trustpilot.com/', 'https://evil.test/profile' ] as $url ) { check( '' === Importer::provider_for_url( $url ), 'unsafe/non-profile URL rejected: ' . $url ); }
check( 'kiyoh' === Importer::provider_for_url( 'https://www.kiyoh.com/reviews/1068046/thuiswinkel_waarborg' ), 'official Kiyoh partner profile URL is supported directly' );
check( 'webwinkelkeur' === Importer::provider_for_url( 'https://www.webwinkelkeur.nl/webshops/reviews/1' ) && '' === Importer::provider_for_url( 'https://www.webwinkelkeur.nl/webshops/reviews/not-a-company-id' ), 'verified current WebwinkelKeur company review path accepts numeric profiles only' );
check( 'webwinkelkeur' === Importer::provider_for_url( 'https://dashboard.webwinkelkeur.nl/webshops/reviews/1' ) && '' === Importer::provider_for_url( 'https://dashboard.webwinkelkeur.nl/app/reviews?id=1' ), 'current provider-linked dashboard host permits only its verified public company profile path' );
$wwk_legacy = 'https://www.webwinkelkeur.nl/webshop/WebwinkelKeur-nl_1/reviews'; $wwk_current = 'https://www.webwinkelkeur.nl/webshops/reviews/1';
$GLOBALS['import_responses'][ $wwk_legacy ] = [ 'status' => 302, 'headers' => [ 'location' => '/webshops/reviews/1' ] ];
$GLOBALS['import_responses'][ $wwk_current ] = [ 'status' => 200, 'body' => html( $facts ) ];
check( ! is_wp_error( Importer::import_url( $wwk_legacy ) ), 'legacy WebwinkelKeur profile follows its verified current company review redirect' );
$GLOBALS['import_calls'] = [];
$GLOBALS['import_responses'][ $profile ] = [ 'status' => 200, 'body' => html( $facts ) ];
$fetched = Importer::import_url( $profile );
check( ! is_wp_error( $fetched ) && count( $GLOBALS['import_calls'] ) === 1, 'normal public profile imports without credentials' );
Importer::import_url( $profile );
check( 1 === count( $GLOBALS['import_calls'] ), 'non-Google imports use a short five-minute response cache' );
check( 0 === $GLOBALS['import_calls'][0][1]['redirection'] && true === $GLOBALS['import_calls'][0][1]['reject_unsafe_urls'] && 2097152 === $GLOBALS['import_calls'][0][1]['limit_response_size'], 'HTTP requests disable automatic redirects and impose safe URL/body/time constraints' );
$redirect_profile = 'https://www.trustpilot.com/review/redirect.test';
$GLOBALS['import_responses'][ $redirect_profile ] = [ 'status' => 302, 'headers' => [ 'location' => 'https://127.0.0.1/admin' ] ];
$before_calls = count( $GLOBALS['import_calls'] );
check( is_wp_error( Importer::import_url( $redirect_profile ) ) && 1 === count( $GLOBALS['import_calls'] ) - $before_calls, 'unsafe redirect target is rejected before a second request' );
$thuis = 'https://www.thuiswinkel.org/leden/example/certificaat/'; $kiyoh = 'https://www.kiyoh.com/reviews/12345/example';
$GLOBALS['import_responses'][ $thuis ] = [ 'status' => 200, 'body' => '<a href="' . $kiyoh . '">Kiyoh reviews</a>' ];
$GLOBALS['import_responses'][ $kiyoh ] = [ 'status' => 200, 'body' => html( $ten ) ];
$partner = Importer::import_url( $thuis );
check( ! is_wp_error( $partner ) && 9.5 === $partner['average_rating'] && $kiyoh === $partner['reviews'][0]['source_url'] && ! empty( $partner['warnings'] ), 'Thuiswinkel certificate follows only its explicit approved Kiyoh partner review link' );
$cert = 'https://www.thuiswinkel.org/leden/certificate-only/certificaat/';
$GLOBALS['import_responses'][ $cert ] = [ 'status' => 200, 'body' => '<p>Membership certificate only.</p>' ];
check( is_wp_error( Importer::import_url( $cert ) ), 'certificate-only page does not fabricate reviews or score' );

$google = 'https://www.google.com/maps/place/InSpace/data=!1s0x47c6db9885509139:0xec9d64177bacae56!8m2';
$GLOBALS['import_responses'][ $google ] = [ 'status' => 200, 'body' => '<html><p>Public map, no review data.</p></html>' ];
$before_cache = $GLOBALS['import_cache'];
$fallback = Importer::import_url( $google );
check( is_wp_error( $fallback ) && $fallback->get_error_data()['fallback']['embed_url'] === 'https://www.google.com/maps?cid=17049893816316505686&output=embed', 'Google no-public-review response offers exact verified business map widget using unsigned CID conversion' );
check( $GLOBALS['import_cache'] === $before_cache, 'Google public content is never persisted in transient cache' );
$before_calls = count( $GLOBALS['import_calls'] );
$profile_only = Importer::resolve_source( $fallback->get_error_data()['fallback'] );
check( $before_calls === count( $GLOBALS['import_calls'] ) && 'profile' === $profile_only['display'] && ! isset( $profile_only['embed_url'] ) && [] === $profile_only['warnings'] && null === $profile_only['average_rating'], 'Google profile fallback resolves to a compact source without extra fetch, map or invented score' );
$widget_source = $fallback->get_error_data()['fallback']; $widget_source['display'] = 'widget';
$widget = Importer::resolve_source( $widget_source );
check( $before_calls === count( $GLOBALS['import_calls'] ) && 'widget' === $widget['display'] && ! empty( $widget['embed_url'] ) && [] === $widget['reviews'] && null === $widget['average_rating'], 'explicit Google widget display preserves optional live frame without copying provider score or cards' );
$legacy_widget = $widget_source; unset( $legacy_widget['display'] );
check( ! isset( Importer::resolve_source( $legacy_widget )['embed_url'] ), 'older sources with saved embed URLs default to compact profile display' );
$plain_profile = Importer::resolve_source( [ 'provider' => 'google', 'url' => $google ] );
check( $before_calls === count( $GLOBALS['import_calls'] ) && 'profile' === $plain_profile['display'] && $google === $plain_profile['url'], 'plain Google profile-only reference avoids runtime retrieval and retains the original source link' );
check( '' === Importer::google_embed_url( 'https://evil.test/maps/data=!1s0x1:0xffffffffffffffff' ) && 'https://www.google.com/maps?cid=18446744073709551615&output=embed' === Importer::google_embed_url( 'https://www.google.com/maps/place/Test/data=!1s0x1:0xffffffffffffffff' ), 'widget CID conversion is exact across full unsigned 64-bit range and only approved hosts' );
$google_blocked = 'https://www.google.com/maps/place/Blocked/data=!1s0x1:0x1234!8m2';
$GLOBALS['import_responses'][ $google_blocked ] = [ 'status' => 302, 'headers' => [ 'location' => 'https://consent.google.com/private' ] ];
$blocked_fallback = Importer::import_url( $google_blocked );
check( is_wp_error( $blocked_fallback ) && 'https://www.google.com/maps?cid=4660&output=embed' === $blocked_fallback->get_error_data()['fallback']['embed_url'] && $google_blocked === $blocked_fallback->get_error_data()['fallback']['url'], 'Google consent or blocked redirect retains exact widget fallback from the validated original profile' );
$google_json = 'https://www.google.com/maps?cid=123';
$GLOBALS['import_responses'][ $google_json ] = [ 'status' => 200, 'body' => html( $facts ) ];
$selected = Importer::resolve_source( [ 'provider' => 'google', 'url' => $google_json, 'selected_review_ids' => [ 'review-b', 'missing-id' ] ] );
check( ! is_wp_error( $selected ) && count( $selected['reviews'] ) === 1 && 'review-b' === $selected['reviews'][0]['id'], 'live Google references select only explicitly chosen IDs and never replace missing selections' );
$before_empty = count( $GLOBALS['import_calls'] );
$empty_selection = Importer::resolve_source( [ 'provider' => 'google', 'url' => $google_json, 'selected_review_ids' => [] ] );
check( $before_empty === count( $GLOBALS['import_calls'] ) && [] === $empty_selection['reviews'] && null === $empty_selection['average_rating'] && 'profile' === $empty_selection['display'], 'empty Google selection defaults to a compact profile without retrieval or implicit review selection' );

$source = [ 'provider' => 'kiyoh', 'url' => $kiyoh, 'average_rating' => 9.5, 'rating_scale' => 10, 'aggregate_scope' => 'provider', 'review_count' => 112, 'imported_at' => '2026-10-05T12:00:00+00:00' ];
check( true === Document::validate_document( source_doc( $source ) ) && 9.5 === Document::sanitize_review_source( $source )['average_rating'], 'document source preserves verified published ten-point aggregate' );
check( 'provider' === Document::sanitize_review_source( $source )['aggregate_scope'], 'source sanitization preserves provider versus combined summary scope' );
$source['rating_scale'] = 5;
check( is_wp_error( Document::validate_document( source_doc( $source ) ) ), 'aggregate exceeding stated scale is rejected before document mutation' );
check( true === Document::validate_document( source_doc( (object) [] ) ), 'explicit empty source object can clear inherited aggregate metadata' );
$google_copy = [ 'provider' => 'google', 'url' => $google_json, 'average_rating' => 5, 'review_count' => 18, 'business_name' => 'Copied name', 'selected_review_ids' => [] ];
check( is_wp_error( Document::validate_document( source_doc( $google_copy ) ) ), 'persistent Google aggregate/name snapshots are rejected' );
$google_clean = Document::sanitize_review_source( $google_copy );
check( ! isset( $google_clean['average_rating'], $google_clean['review_count'], $google_clean['business_name'] ), 'defensive Google source sanitization retains references only' );
check( true === Document::validate_document( source_doc( $widget_source ) ) && 'widget' === Document::sanitize_review_source( $widget_source )['display'], 'explicit supported Google map display can be saved as a reference only' );
check( 'profile' === Document::sanitize_review_source( $fallback->get_error_data()['fallback'] )['display'], 'compact Google display survives source sanitization' );
echo 'PASS: ' . $checks . ' review importer checks.' . PHP_EOL;
