<?php
/** Read-only actual WordPress checks for horizontal selection and logo links. */
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;
$checks = [];
$assert = static function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$xpath = static function ( $html ) {
    $dom = new DOMDocument();
    $prior = libxml_use_internal_errors( true );
    $dom->loadHTML( '<!doctype html><html><body>' . $html . '</body></html>' );
    libxml_clear_errors(); libxml_use_internal_errors( $prior );
    return new DOMXPath( $dom );
};
foreach ( [ 5461 => 3, 5618 => 2 ] as $id => $quotes ) {
    $html = Renderer::render( $id );
    $assert( false !== strpos( $html, 'nova-service__layout--publisher_cards' ) && $quotes === substr_count( $html, '<blockquote' ), $id . ': selected horizontal design preserves every quote' );
    $request = new WP_REST_Request( 'GET', '/wp/v2/services/' . $id );
    $get = rest_do_request( $request ); $data = $get->get_data();
    $assert( 200 === $get->get_status() && ! empty( $data['meta_note'] ) && ! empty( $data['meta_descriptions'] ), $id . ': ordinary GET retains posting context' );
    if ( 5461 === $id ) {
        $x = $xpath( $html );
        $links = $x->query( '//div[contains(@class,"nova-service__review-summary")]//a[img or .//img]' );
        $assert( 1 === $links->length && false !== strpos( $links->item( 0 )->getAttribute( 'href' ), 'google.com/maps' ), 'Google profile logo is the single linked source' );
        $assert( '' === trim( $links->item( 0 )->textContent ), 'Google logo link has no duplicate visible provider text' );
        $assert( $links->item( 0 )->getAttribute( 'aria-label' ) || $links->item( 0 )->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'alt' ), 'Logo link has an accessible source name' );
    }
}
$review_method = new ReflectionMethod( Renderer::class, 'reviews' );
$source = [ 'review_source' => [ 'provider' => 'google', 'url' => 'https://www.google.com/maps?cid=17049893816316505686' ], 'link_sources' => false ];
$disabled = $xpath( $review_method->invoke( null, $source ) );
$assert( 0 === $disabled->query( '//a' )->length && 1 === $disabled->query( '//img[@alt="Google Maps"]' )->length, 'Optional source-link setting still leaves an unlinked Google logo' );
echo wp_json_encode( [ 'result' => 'PASS', 'read_only' => true, 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
