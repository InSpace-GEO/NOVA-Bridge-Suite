<?php
/** WordPress integration fixtures; no real business reviews are published. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;
use SEORAI\ServicePageCPT\ServiceShared as Shared;
$checks = []; $id = 0;
$presets_before = get_option( Shared::OPTION_PRESETS, null );
$assert = static function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$api = static function ( $url ) { $request = new WP_REST_Request( 'POST', '/nova-service/v1/reviews/import' ); $request->set_body_params( [ 'url' => $url ] ); return rest_do_request( $request ); };
$fixture_url = 'https://www.kiyoh.com/reviews/9999999/nova_fixture';
$fixture = '<script type="application/ld+json">' . wp_json_encode( [ '@type' => 'Organization', 'url' => $fixture_url, 'name' => 'Fictional fixture', 'aggregateRating' => [ 'ratingValue' => 8.5, 'bestRating' => 10, 'reviewCount' => 100 ], 'review' => [ [ '@type' => 'Review', 'author' => [ 'name' => 'Fixture author' ], 'reviewBody' => 'Fictional fixture text.', 'reviewRating' => [ 'ratingValue' => 10, 'bestRating' => 10 ] ] ] ] ) . '</script>';
$http = static function ( $preempt, $args, $url ) use ( $fixture_url, $fixture ) { if ( $url === $fixture_url ) { return [ 'headers' => [], 'body' => $fixture, 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ]; } return $preempt; };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admins[0]->ID );
add_filter( 'pre_http_request', $http, 10, 3 );
try {
    delete_transient( 'nova_service_reviews_' . hash( 'sha256', $fixture_url ) );
    $response = $api( $fixture_url ); $result = $response->get_data();
    $assert( 200 === $response->get_status(), 'Authenticated review import route registered' );
    $assert( 8.5 === $result['average_rating'] && 10 === $result['rating_scale'] && 100 === $result['review_count'], 'Published ten-point aggregate retained independently' );
    $assert( 1 === count( $result['reviews'] ) && 5.0 === $result['reviews'][0]['rating'] && 10.0 === $result['reviews'][0]['original_rating'], 'Selectable cards retain original rating and normalized stars' );
    $assert( 400 === $api( 'https://127.0.0.1/review/private' )->get_status(), 'Private import target rejected' );
    $assert( 400 === $api( 'https://www.kiyoh.com.evil.example/reviews/1/example' )->get_status(), 'Spoofed provider host rejected' );
    wp_set_current_user( 0 );
    $assert( $api( $fixture_url )->get_status() >= 400, 'Anonymous review imports denied' );
    wp_set_current_user( $admins[0]->ID );
    $id = wp_insert_post( [ 'post_type' => 'service_page', 'post_status' => 'draft', 'post_title' => 'NOVA reviews integration fixture' ], true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $source = [ 'provider' => 'kiyoh', 'url' => $fixture_url, 'business_name' => 'Fictional fixture', 'average_rating' => 8.5, 'rating_scale' => 10, 'review_count' => 100, 'imported_at' => $result['fetched_at'] ];
    $section = [ 'id' => 'review_fixture', 'type' => 'reviews', 'enabled' => true, 'data' => [ 'title' => 'Fixture reviews', 'layout' => 'list', 'review_source' => $source, 'items' => [ [ 'author' => 'Fixture author', 'body' => '<p>Fictional fixture text.</p>', 'rating' => 5 ] ] ] ];
    $doc = [ 'version' => 2, 'sections' => [ $section ] ];
    $assert( true === Document::save_document( $id, $doc ), 'Review source and selected cards save together' );
    $html = Renderer::render( $id );
    $assert( false !== strpos( $html, '/10' ) && false !== strpos( $html, '100 reviews' ) && false !== strpos( $html, 'nova-service__layout--list' ), 'Provider total and list appearance render' );
    $doc['sections'][0]['data']['items'] = [];
    Document::save_document( $id, $doc );
    $assert( 8.5 === Document::get_document( $id )['sections'][0]['data']['review_source']['average_rating'] && false !== strpos( Renderer::render( $id ), '100 reviews' ), 'Removing selected cards does not change source average' );
    $bad = $doc; $bad['sections'][0]['data']['review_source']['average_rating'] = 11;
    $assert( is_wp_error( Document::save_document( $id, $bad ) ) && 8.5 === Document::get_document( $id )['sections'][0]['data']['review_source']['average_rating'], 'Invalid aggregate rejected without changing saved source' );
    $fixture_presets = Shared::get_presets(); $fixture_presets['sections'][] = $section;
    $assert( true === Shared::save_presets( $fixture_presets ), 'Review source can be configured once in shared library' );
    $doc['sections'][0]['shared_key'] = 'review_fixture'; $doc['sections'][0]['data'] = (object) [];
    Document::save_document( $id, $doc );
    $assert( 100 === Document::resolve_document( $id )['sections'][0]['data']['review_source']['review_count'], 'Page inherits shared review source' );
    $doc['sections'][0]['data'] = [ 'review_source' => (object) [] ]; Document::save_document( $id, $doc );
    $assert( [] === (array) Document::resolve_document( $id )['sections'][0]['data']['review_source'], 'Empty local source explicitly clears inherited source' );
    $google = [ 'provider' => 'google', 'url' => 'https://www.google.com/maps?cid=17049893816316505686', 'embed_url' => 'https://www.google.com/maps?cid=17049893816316505686&output=embed' ];
    unset( $doc['sections'][0]['shared_key'] ); $doc['sections'][0]['data'] = [ 'review_source' => $google, 'items' => [] ];
    $assert( true === Document::save_document( $id, $doc ), 'Older keyless Google source reference remains saveable' );
    $html = Renderer::render( $id );
    $assert( false !== strpos( $html, '17049893816316505686' ) && false !== strpos( $html, 'Google Maps' ) && false === strpos( $html, '<iframe' ) && false === strpos( $html, 'nova-service__overall-rating' ), 'Google sources default to a compact attributed profile link without map or invented score' );
    $doc['sections'][0]['data']['review_source']['display'] = 'widget';
    $assert( true === Document::save_document( $id, $doc ), 'Official Google map display requires an explicit widget choice' );
    $html = Renderer::render( $id );
    $assert( false !== strpos( $html, '<iframe' ) && false !== strpos( $html, '17049893816316505686' ) && false === strpos( $html, 'nova-service__overall-rating' ), 'Explicit Google widget renders its safe live map reference without copied aggregate content' );
    $before_display = Document::get_document( $id ); $title_before_display = get_post( $id )->post_title;
    $bad_display = $doc; $bad_display['sections'][0]['data']['review_source']['display'] = 'invented-display';
    $display_request = new WP_REST_Request( 'POST', '/wp/v2/' . get_option( 'service_cpt_slug', 'services' ) . '/' . $id );
    $display_request->set_body_params( [ 'title' => 'INVALID_REVIEW_DISPLAY_MUST_NOT_SAVE', 'nova_service_document' => $bad_display ] );
    $display_response = rest_do_request( $display_request );
    $assert( 400 === $display_response->get_status() && $title_before_display === get_post( $id )->post_title && wp_json_encode( $before_display ) === wp_json_encode( Document::get_document( $id ) ), 'Real WordPress REST schema rejects an invalid review display before any title or document mutation' );
    $bad = $doc; $bad['sections'][0]['data']['review_source']['average_rating'] = 5;
    $assert( is_wp_error( Document::save_document( $id, $bad ) ), 'Google aggregate content cannot be persisted as a widget snapshot' );
    $inherit_filter = static function () { return ''; };
    $color_keys = [ 'primary', 'contrast', 'surface', 'text', 'accent', 'border', 'hero_bg', 'hero_text', 'cta_bg', 'cta_text', 'button_bg', 'button_text', 'button_outline', 'faq_bg', 'faq_question', 'faq_answer' ];
    foreach ( $color_keys as $key ) { add_filter( 'pre_option_service_cpt_color_' . $key, $inherit_filter ); }
    try {
        $html = Renderer::render( $id );
        $assert( ! preg_match( '/--nova-service-(?:primary|text|surface|soft|soft-text|hero-text):/', $html ), 'Absent Service color overrides leave the global brand colors in control' );
    } finally { foreach ( $color_keys as $key ) { remove_filter( 'pre_option_service_cpt_color_' . $key, $inherit_filter ); } }
    echo wp_json_encode( [ 'result' => 'PASS', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
} finally {
    remove_filter( 'pre_http_request', $http, 10 );
    delete_transient( 'nova_service_reviews_' . hash( 'sha256', $fixture_url ) );
    if ( $id && ! is_wp_error( $id ) ) { wp_trash_post( $id ); }
    if ( null === $presets_before ) { delete_option( Shared::OPTION_PRESETS ); } else { update_option( Shared::OPTION_PRESETS, $presets_before, false ); }
}
