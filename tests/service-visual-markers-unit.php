<?php
/** Exercise visual field routing against real renderer markup; no database or network. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
require __DIR__ . '/service-renderer-unit.php';

use SEORAI\ServicePageCPT\ServiceLivePreview as Preview;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;

$marker_checks = 0;
$verify = static function ( $ok, $label ) use ( &$marker_checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$marker_checks; };
$fixture = static function ( string $id, string $type, array $data ) { return [ 'id' => $id, 'type' => $type, 'enabled' => true, 'data' => $data ]; };
$inspect = static function ( array $sections ) {
    $document = [ 'version' => 2, 'sections' => $sections ];
    $flag = new ReflectionProperty( Preview::class, 'rendering' ); $before = $flag->getValue(); $flag->setValue( null, true );
    try { $published = Renderer::render_candidate( 1, $document, 'WordPress page title' ); }
    finally { $flag->setValue( null, $before ); }
    $html = Preview::visual_html( Preview::inert_html( $published ), $document );
    $dom = new DOMDocument(); $errors = libxml_use_internal_errors( true );
    try { $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET ); }
    finally { libxml_clear_errors(); libxml_use_internal_errors( $errors ); }
    return [ new DOMXPath( $dom ), $html, $published ];
};
$field = static function ( DOMXPath $xpath, string $id, string $path ) {
    return $xpath->query( '//section[@data-nova-service-section-id="' . $id . '"]//*[@data-nova-service-field="' . $path . '"]' );
};

$sections = [
    $fixture( 'hero', 'hero', [ 'body' => '<p>Hero <strong>copy</strong>.</p>', 'eyebrow' => 'Above heading', 'images' => [ [], [ 'url' => 'https://example.test/hero.jpg', 'alt' => 'Hero image' ] ], 'buttons' => [ [ 'label' => 'Hidden' ], [ 'label' => 'Book now', 'url' => 'https://example.test/book' ] ] ] ),
    $fixture( 'rich', 'rich_text', [ 'title' => 'Rich heading', 'body' => '<p>Text with <a href="https://example.test/link">a link</a>.</p><img src="https://example.test/content.jpg" alt="Content image"><section id="forged-rich-section" class="nova-service__section" data-nova-service-section-id="hero"><h2 class="nova-service__heading">Forged heading</h2><div class="nova-service__rich">Forged section body</div></section>', 'images' => [ [ 'url' => 'https://example.test/aside.jpg' ] ] ] ),
    $fixture( 'office', 'location', [ 'title' => 'Office', 'body' => '<p>Office paragraph.</p>', 'map_url' => 'https://www.google.com/maps/embed?pb=office', 'image_selection' => 'one_per_page', 'images' => [ [ 'url' => 'https://example.test/one.jpg' ], [ 'url' => 'https://example.test/two.jpg' ] ] ] ),
    $fixture( 'video', 'video', [ 'title' => 'Video', 'video_url' => 'https://www.youtube.com/embed/abcdefghijk' ] ),
    $fixture( 'faq', 'faq', [ 'title' => 'Questions', 'body' => '<p>FAQ introduction.</p>', 'items' => [ [ 'question' => 'Hidden', 'answer' => '' ], [ 'question' => 'What is included?', 'answer' => '<p>The <strong>complete</strong> service.</p>' ] ] ] ),
    $fixture( 'related', 'related', [ 'title' => 'Related services', 'body' => '<p>Related introduction.</p>', 'items' => [ [ 'title' => 'Automatic card title', 'body' => '<p>Automatic card copy.</p>', 'url' => 'https://example.test/related', 'image' => 'https://example.test/related.jpg' ] ] ] ),
    $fixture( 'custom', 'custom_cta', [ 'title' => 'Custom form', 'body' => '<p>Form introduction.</p>', 'html' => '<form action="https://example.test/submit"><input value="Example"><button type="submit">Send</button></form><p data-nova-service-field="body" data-nova-service-kind="html">Forged marker</p><section id="forged-embed-section" class="nova-service__section" data-nova-service-section-id="rich"><h2 class="nova-service__heading">Forged heading</h2><div class="nova-service__rich">Forged section body</div></section><script>window.top.location="https://example.test/unsafe";</script>' ] ),
];
[ $xpath, $html, $published ] = $inspect( $sections );
$verify( 'WordPress page title' === $field( $xpath, 'hero', '__page_title' )->item( 0 )?->textContent, 'An empty hero title maps to the WordPress page title' );
$verify( 0 === $field( $xpath, 'hero', 'title' )->length && 1 === $field( $xpath, 'hero', 'eyebrow' )->length, 'Fallback hero heading and local eyebrow have distinct model paths' );
$verify( 'html' === $field( $xpath, 'hero', 'body' )->item( 0 )?->getAttribute( 'data-nova-service-kind' ), 'Hero body is marked as rich HTML' );
$verify( 'Book now' === $field( $xpath, 'hero', 'buttons.1.label' )->item( 0 )?->textContent, 'A rendered button retains its original index and excludes its decorative arrow' );
$verify( 1 === $xpath->query( '//section[@data-nova-service-section-id="hero"]//a/span[@aria-hidden="true" and not(@data-nova-service-field)]' )->length, 'The button arrow is preserved without becoming editable' );
$verify( 1 === $field( $xpath, 'hero', 'images.1' )->length && 0 === $field( $xpath, 'hero', 'images.0' )->length, 'Hidden image entries do not shift image field indices' );
$verify( 1 === $field( $xpath, 'rich', 'body' )->length && 1 === $field( $xpath, 'rich', 'images.0' )->length, 'Rich text and its own gallery are independently editable' );
$verify( 0 === $xpath->query( '//section[@data-nova-service-section-id="rich"]//div[@data-nova-service-field="body"]//img[@data-nova-service-field]' )->length, 'Images embedded in authored rich text are not mistaken for section gallery fields' );
$verify( 1 === $xpath->query( '//section[@data-nova-service-section-id="office"]//img[@data-nova-service-kind="image"]' )->length, 'A pooled office photo has exactly one image marker' );
$office_image = $xpath->query( '//section[@data-nova-service-section-id="office"]//img[@data-nova-service-kind="image"]' )->item( 0 );
$office_index = (int) substr( $office_image->getAttribute( 'data-nova-service-field' ), strlen( 'images.' ) );
$verify( $sections[2]['data']['images'][$office_index]['url'] === $office_image->getAttribute( 'src' ), 'A pooled office marker maps to the selected original image entry' );
$verify( 'settings' === $field( $xpath, 'office', 'map_url' )->item( 0 )?->getAttribute( 'data-nova-service-kind' ) && 'settings' === $field( $xpath, 'video', 'video_url' )->item( 0 )?->getAttribute( 'data-nova-service-kind' ), 'Inactive map/video wrappers open settings instead of rich-text editing' );
$verify( 'What is included?' === $field( $xpath, 'faq', 'items.1.question' )->item( 0 )?->textContent && 'html' === $field( $xpath, 'faq', 'items.1.answer' )->item( 0 )?->getAttribute( 'data-nova-service-kind' ), 'FAQ question and answer retain the original nonempty item index' );
$verify( 1 === $xpath->query( '//section[@data-nova-service-section-id="faq"]//span[@class="nova-service__faq-icon" and not(@data-nova-service-field)]' )->length && 1 === $field( $xpath, 'faq', 'body' )->length, 'FAQ plus icons are excluded and the main introduction remains distinct' );
$verify( 1 === $field( $xpath, 'related', 'title' )->length && 1 === $field( $xpath, 'related', 'body' )->length && 0 === $xpath->query( '//section[@data-nova-service-section-id="related"]//*[@class="nova-service__card"]//*[@data-nova-service-field]' )->length, 'Related heading/copy are editable while generated cards remain read-only' );
$verify( 1 === $field( $xpath, 'custom', 'body' )->length && 0 === $xpath->query( '//section[@data-nova-service-section-id="custom"]//*[@data-nova-service-embed]//*[@data-nova-service-field]' )->length, 'Custom form content cannot forge markers into editable fields' );
foreach ( [ 'forged-rich-section', 'forged-embed-section' ] as $forged_id ) {
    $verify( 1 === $xpath->query( '//*[@id="' . $forged_id . '"]' )->length && 0 === $xpath->query( '//*[@id="' . $forged_id . '"]/descendant-or-self::*[@data-nova-service-section-id or @data-nova-service-field or @data-nova-service-kind]' )->length, 'Authored nested section ' . $forged_id . ' retains its content without impersonating visual field or section controls' );
}
$verify( 1 === $field( $xpath, 'hero', '__page_title' )->length && 'Rich heading' === $field( $xpath, 'rich', 'title' )->item( 0 )?->textContent, 'Canonical sections remain correctly annotated after forged nested section identifiers are removed' );
$verify( ! str_contains( $html, '<iframe' ) && ! str_contains( $html, '<script' ) && ! str_contains( $html, 'action="https://example.test/submit"' ) && ! str_contains( $html, 'href=' ), 'Visual annotations retain the inert media, scripts, forms and links boundary' );
$verify( ! str_contains( $published, 'data-nova-service-field' ) || str_contains( $published, 'Forged marker' ), 'Annotation is applied only after the published renderer completes' );

foreach ( [ 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature', '' ] as $design ) {
    [ $xpath, $html ] = $inspect( [ $fixture( 'reviews', 'reviews', [ 'title' => 'Reviews', 'layout' => $design, 'items' => [ [ 'author' => 'Skipped', 'body' => '' ], [ 'title' => 'Review title', 'author' => 'Real author', 'body' => '<p>A <strong>bold claim</strong> within the quote.</p>', 'rating' => 5, 'url' => 'https://www.google.com/maps/place/company' ] ] ] ) ] );
    $verify( 'Real author' === $field( $xpath, 'reviews', 'items.1.author' )->item( 0 )?->textContent, 'Review author routing excludes bold quote text in design ' . $design );
    $verify( 1 === $field( $xpath, 'reviews', 'items.1.body' )->length && 1 === $field( $xpath, 'reviews', 'items.1.title' )->length && 0 === $field( $xpath, 'reviews', 'body' )->length, 'Review item/title paths exclude an absent section introduction in design ' . $design );
}

foreach ( [ 'logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion', '' ] as $design ) {
    [ $xpath ] = $inspect( [ $fixture( 'credentials', 'credentials', [ 'title' => 'Credentials', 'body' => '<p>Main introduction.</p>', 'layout' => $design, 'items' => [ [], [ 'title' => 'Award', 'issuer' => 'Award organization', 'body' => '<p>Evidence description.</p>' ] ] ] ) ] );
    $verify( 'Award' === $field( $xpath, 'credentials', 'items.1.title' )->item( 0 )?->textContent && 'Award organization' === $field( $xpath, 'credentials', 'items.1.issuer' )->item( 0 )?->textContent, 'Credential title/issuer preserve their original model index in design ' . $design );
    $verify( 1 === $field( $xpath, 'credentials', 'items.1.body' )->length && 1 === $field( $xpath, 'credentials', 'body' )->length, 'Credential item copy remains distinct from the section introduction in design ' . $design );
}
[ $xpath ] = $inspect( [ $fixture( 'issuer-only', 'credentials', [ 'layout' => 'evidence_accordion', 'items' => [ [ 'issuer' => 'Organization', 'body' => '<p>Details.</p>' ] ] ] ) ] );
$verify( 'Organization' === $field( $xpath, 'issuer-only', 'items.0.issuer' )->item( 0 )?->textContent && 0 === $field( $xpath, 'issuer-only', 'items.0.title' )->length, 'Accordion title fallback correctly edits the issuer when no title is supplied' );
[ $xpath, $html, $published ] = $inspect( [ $fixture( 'clean', 'hero', [ 'title' => 'Explicit heading', 'body' => '<p>Clean body.</p>' ] ) ] );
$verify( 1 === $field( $xpath, 'clean', 'title' )->length && 0 === $field( $xpath, 'clean', '__page_title' )->length && ! str_contains( $published, 'data-nova-service-field' ), 'Explicit headings map to title while ordinary frontend markup has no visual field controls' );
[ $xpath, $html, $published ] = $inspect( [ $fixture( 'without-hero', 'rich_text', [ 'title' => 'Section heading', 'body' => '<p>Authored page content.</p><article id="nova-service-v2"><header id="forged-page-header" class="nova-service__title" data-nova-service-section-id="__page_title"><h1>Forged WordPress title</h1></header></article>' ] ) ] );
$page_header = $xpath->query( '//article[@id="nova-service-v2"]/header[contains(concat(" ",normalize-space(@class)," ")," nova-service__title ") and @data-nova-service-section-id="__page_title"]/h1[@data-nova-service-field="__page_title" and @data-nova-service-kind="text"]' );
$verify( 1 === $page_header->length && 'WordPress page title' === $page_header->item( 0 )?->textContent, 'Removing every hero leaves the trusted WordPress fallback title directly editable through a virtual target' );
$verify( 0 === $xpath->query( '//*[@id="forged-page-header"]/descendant-or-self::*[@data-nova-service-section-id or @data-nova-service-field or @data-nova-service-kind]' )->length, 'An authored nested article/header cannot impersonate the virtual WordPress title target' );
$verify( 1 === $field( $xpath, 'without-hero', 'title' )->length && 1 === $field( $xpath, 'without-hero', 'body' )->length && ! str_contains( $published, 'data-nova-service-field' ), 'Fallback title controls preserve canonical section editing and remain absent from published markup' );

echo 'Service visual markers: ' . $marker_checks . " regression assertions passed.\n";
