<?php
/** Actual WordPress review design/optional-link behavior on a disposable draft. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;
use SEORAI\ServicePageCPT\ServiceShared as Shared;
$checks = []; $id = 0;
$presets_before = get_option( Shared::OPTION_PRESETS, null );
$assert = static function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] ); wp_set_current_user( $admins[0]->ID );
$type = get_post_type_object( 'service_page' ); $route = '/wp/v2/' . ( $type->rest_base ?: $type->name );
$request = static function ( $method, $path, $payload = [] ) { $r = new WP_REST_Request( $method, $path ); $r->set_body_params( $payload ); return rest_do_request( $r ); };
$styles = [ 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature' ];
$section = [ 'id' => 'proof', 'type' => 'reviews', 'enabled' => true, 'data' => [ 'title' => 'Disposable review fixture', 'source_logo' => 'https://example.org/publisher.png', 'items' => [ [ 'author' => 'Fixture customer', 'author_url' => 'https://example.org/customer', 'body' => '<p>A fictional review used only for automated checks.</p>', 'source' => 'Fixture publisher', 'url' => 'https://example.org/source' ] ] ] ];
$doc = [ 'version' => 2, 'sections' => [ $section ] ];
try {
    $response = $request( 'POST', $route, [ 'title' => 'NOVA review styles disposable fixture', 'status' => 'draft', 'nova_service_document' => $doc ] );
    $assert( 201 === $response->get_status(), 'Disposable native draft created through normal REST route' ); $id = (int) $response->get_data()['id'];
    foreach ( $styles as $style ) {
        $doc['sections'][0]['data']['layout'] = $style; $doc['sections'][0]['data']['link_sources'] = false;
        $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
        $assert( 200 === $response->get_status(), $style . ': real REST accepts appearance and false link flag' );
        $stored = Document::get_document( $id );
        $assert( $style === $stored['sections'][0]['data']['layout'] && false === $stored['sections'][0]['data']['link_sources'], $style . ': appearance and boolean survive storage' );
        $html = Renderer::render( $id );
        $assert( false !== strpos( $html, 'nova-service__layout--' . $style ) && 1 === substr_count( $html, '<blockquote' ), $style . ': saved selection renders its quote' );
        $assert( ! preg_match( '/<a\b[^>]*class="[^"]*(?:review-source-link|review-author)/', $html ) && false !== strpos( $html, 'Fixture publisher' ) && false !== strpos( $html, 'https://example.org/publisher.png' ), $style . ': no source/author anchors while publisher and logo remain' );
    }
    unset( $doc['sections'][0]['data']['link_sources'] );
    $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $html = Renderer::render( $id );
    $assert( false !== strpos( $html, 'href="https://example.org/source"' ) && false !== strpos( $html, 'href="https://example.org/customer"' ), 'Omitted link flag preserves existing clickable attribution' );
    $doc['sections'][0]['data']['link_sources'] = false;
    unset( $doc['sections'][0]['data']['items'][0]['url'], $doc['sections'][0]['data']['items'][0]['author_url'] );
    $doc['sections'][0]['data']['items'][0]['source'] = 'Google';
    $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $assert( 200 === $response->get_status() && false !== strpos( Renderer::render( $id ), '>Google</span>' ), 'Manual source name works without any original source URL' );
    $doc['sections'][0]['data']['review_source'] = [ 'provider' => 'google', 'url' => 'https://www.google.com/maps?cid=17049893816316505686' ];
    $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $html = Renderer::render( $id );
    $assert( false === strpos( $html, 'href="https://www.google.com/maps' ) && false === strpos( $html, 'View reviews on' ) && false !== strpos( $html, 'Google Maps' ) && false === strpos( $html, '<iframe' ), 'Keyless Google profile can keep source branding without link, map or invented score' );
    $before = wp_json_encode( Document::get_document( $id ) ); $title = get_the_title( $id );
    foreach ( [ [ 'layout' => 'unknown_review_style' ], [ 'link_sources' => [ false ] ] ] as $invalid ) {
        $bad = $doc; $bad['sections'][0]['data'] = array_replace( $bad['sections'][0]['data'], $invalid );
        $response = $request( 'POST', $route . '/' . $id, [ 'title' => 'MUST_NOT_SAVE', 'nova_service_document' => $bad ] );
        $assert( 400 === $response->get_status() && $title === get_the_title( $id ) && $before === wp_json_encode( Document::get_document( $id ) ), 'Invalid ' . key( $invalid ) . ' rejects before title/document mutation' );
    }
    $shared = $section; $shared['id'] = 'review_design_fixture'; $shared['data']['layout'] = 'signature'; $shared['data']['link_sources'] = false;
    $presets = Shared::get_presets(); $presets['sections'][] = $shared;
    $assert( true === Shared::save_presets( $presets ), 'Shared library accepts all review design controls' );
    $doc['sections'][0] = [ 'id' => 'proof', 'type' => 'reviews', 'enabled' => true, 'shared_key' => 'review_design_fixture', 'data' => (object) [] ];
    $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 'signature' === $resolved['layout'] && false === $resolved['link_sources'], 'Pages inherit shared design and optional linking together' );
    $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_content' => [ 'section_overrides' => [ 'proof' => [ 'data' => [ 'layout' => 'compact_rail', 'link_sources' => true ] ] ] ] ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 200 === $response->get_status() && 'compact_rail' === $resolved['layout'] && true === $resolved['link_sources'], 'Routine content API can override review design and linking without replacing shared content' );
    $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_content' => [ 'section_overrides' => [ 'proof' => [ 'inherit' => [ 'layout', 'link_sources' ] ] ] ] ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 200 === $response->get_status() && 'signature' === $resolved['layout'] && false === $resolved['link_sources'], 'Removing only presentation overrides restores shared inheritance' );
    $get = $request( 'GET', $route . '/' . $id );
    $assert( 200 === $get->get_status() && false !== strpos( wp_json_encode( $get->get_data()['meta_descriptions'] ?? [] ), 'link_sources' ), 'Ordinary GET explains optional review links in existing posting context' );
    echo wp_json_encode( [ 'result' => 'PASS', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
} finally {
    if ( $id ) { wp_update_post( [ 'ID' => $id, 'post_status' => 'trash' ] ); }
    if ( null === $presets_before ) { delete_option( Shared::OPTION_PRESETS ); } else { update_option( Shared::OPTION_PRESETS, $presets_before, false ); }
}
