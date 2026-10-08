<?php
/** Actual WordPress credential design/inheritance checks on a disposable draft. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;
use SEORAI\ServicePageCPT\ServiceShared as Shared;
$checks = []; $id = 0;
$virtual_presets = get_option( Shared::OPTION_PRESETS, false );
$read_presets = static function ( $pre ) use ( &$virtual_presets ) { return $virtual_presets; };
$write_presets = static function ( $value, $old_value ) use ( &$virtual_presets ) { $virtual_presets = $value; return $old_value; };
$retain_template = static function ( $value, $old_value ) { return $old_value; };
$assert = static function ( $condition, $label ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] ); wp_set_current_user( $admins[0]->ID );
$type = get_post_type_object( 'service_page' ); $route = '/wp/v2/' . ( $type->rest_base ?: $type->name );
$request = static function ( $method, $path, $payload = [] ) { $r = new WP_REST_Request( $method, $path ); $r->set_body_params( $payload ); return rest_do_request( $r ); };
$styles = [ 'logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion' ];
$canonical = static function ( $value ) use ( &$canonical ) {
    if ( ! is_array( $value ) ) { return $value; }
    if ( ! array_is_list( $value ) ) { ksort( $value ); }
    foreach ( $value as &$part ) { $part = $canonical( $part ); } unset( $part );
    return $value;
};
$items = [
    [ 'title' => 'Fixture affiliation', 'issuer' => 'Fixture issuer', 'body' => '<p>Fixture affiliation description.</p>', 'image' => 'https://example.org/issuer.png', 'url' => 'https://example.org/credential', 'source' => 'Read published evidence' ],
    [ 'title' => 'Fixture qualification', 'issuer' => 'Other issuer', 'body' => '<p>Fixture qualification without an image or source URL.</p>' ],
    [ 'body' => '<p>Complete body-only credential fixture text.</p>' ],
];
$section = [ 'id' => 'proof', 'type' => 'credentials', 'enabled' => true, 'data' => [ 'title' => 'Disposable credential fixture', 'items' => $items ] ];
$doc = [ 'version' => 2, 'sections' => [ $section ] ];
try {
    $response = $request( 'POST', $route, [ 'title' => 'NOVA credential styles disposable fixture', 'status' => 'draft', 'nova_service_document' => $doc ] );
    $assert( 201 === $response->get_status(), 'Disposable native credential draft created through normal REST' ); $id = (int) $response->get_data()['id'];
    foreach ( $styles as $style ) {
        $doc['sections'][0]['data']['layout'] = $style; $doc['sections'][0]['data']['link_sources'] = false;
        $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
        $assert( 200 === $response->get_status(), $style . ': actual REST accepts credential appearance and optional links' );
        $stored = Document::get_document( $id )['sections'][0]['data'];
        $assert( $style === $stored['layout'] && false === $stored['link_sources'] && 3 === count( $stored['items'] ) && $canonical( $items ) === $canonical( $stored['items'] ), $style . ': complete issuer/image/evidence/copy data preserved' );
        $html = Renderer::render( $id );
        $assert( false !== strpos( $html, 'nova-service__layout--' . $style ) && false !== strpos( $html, 'Fixture affiliation description.' ) && false !== strpos( $html, 'Fixture qualification without an image or source URL.' ) && false !== strpos( $html, 'Complete body-only credential fixture text.' ), $style . ': all three credential items remain rendered' );
        $assert( false !== strpos( $html, 'https://example.org/issuer.png' ) && false === strpos( $html, 'href="https://example.org/credential"' ) && false !== strpos( $html, 'Fixture issuer' ), $style . ': authentic supplied image and issuer remain without evidence links' );
        $assert( false === strpos( $html, 'FAQPage' ), $style . ': credential evidence does not become FAQ structured data' );
        if ( 'evidence_accordion' === $style ) {
            $assert( 3 === substr_count( $html, '<details' ) && 3 === substr_count( $html, '<summary' ) && 1 === preg_match_all( '/<details\b[^>]*\sopen(?:\s|=|>)/', $html ), 'Evidence accordion uses native disclosures with one initially open item' );
        }
    }
    unset( $doc['sections'][0]['data']['link_sources'] );
    $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $assert( false !== strpos( Renderer::render( $id ), 'href="https://example.org/credential"' ), 'Omitted link_sources keeps existing credential links enabled' );
    $before = wp_json_encode( Document::get_document( $id ) ); $title = get_the_title( $id );
    foreach ( [ [ 'layout' => 'unknown_credential_style' ], [ 'link_sources' => 'no' ] ] as $invalid ) {
        $bad = $doc; $bad['sections'][0]['data'] = array_replace( $bad['sections'][0]['data'], $invalid );
        $response = $request( 'POST', $route . '/' . $id, [ 'title' => 'MUST_NOT_SAVE', 'nova_service_document' => $bad ] );
        $assert( 400 === $response->get_status() && $title === get_the_title( $id ) && $before === wp_json_encode( Document::get_document( $id ) ), 'Invalid ' . key( $invalid ) . ' rejected before any post mutation' );
    }
    $shared = $section; $shared['id'] = 'credential_design_fixture'; $shared['data']['layout'] = 'logo_rail'; $shared['data']['link_sources'] = false;
    // Exercise the normal shared validation/sanitization path in this request,
    // without temporarily replacing a client's live options or concurrent edits.
    add_filter( 'pre_option_' . Shared::OPTION_PRESETS, $read_presets );
    add_filter( 'pre_update_option_' . Shared::OPTION_PRESETS, $write_presets, 10, 2 );
    add_filter( 'pre_update_option_' . Shared::OPTION_TEMPLATE, $retain_template, 10, 2 );
    $presets = Shared::get_presets(); $presets['sections'][] = $shared;
    $assert( true === Shared::save_presets( $presets ), 'Client defaults accept new credential appearance and source choice' );
    $doc['sections'][0] = [ 'id' => 'proof', 'type' => 'credentials', 'enabled' => true, 'shared_key' => 'credential_design_fixture', 'data' => (object) [] ];
    $request( 'POST', $route . '/' . $id, [ 'nova_service_document' => $doc ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 'logo_rail' === $resolved['layout'] && false === $resolved['link_sources'], 'Page inherits shared credential style and optional links without local copies' );
    $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_content' => [ 'section_overrides' => [ 'proof' => [ 'data' => [ 'layout' => 'evidence_accordion', 'link_sources' => true ] ] ] ] ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 200 === $response->get_status() && 'evidence_accordion' === $resolved['layout'] && true === $resolved['link_sources'] && $canonical( $items ) === $canonical( $resolved['items'] ), 'Routine API overrides appearance/links while retaining shared credential content' );
    $response = $request( 'POST', $route . '/' . $id, [ 'nova_service_content' => [ 'section_overrides' => [ 'proof' => [ 'inherit' => [ 'layout', 'link_sources' ] ] ] ] ] );
    $resolved = Document::resolve_document( $id )['sections'][0]['data'];
    $assert( 200 === $response->get_status() && 'logo_rail' === $resolved['layout'] && false === $resolved['link_sources'], 'Removing presentation overrides restores shared credential choices' );
    $get = $request( 'GET', $route . '/' . $id ); $context = wp_json_encode( $get->get_data()['meta_descriptions'] ?? [] );
    $assert( 200 === $get->get_status() && false !== strpos( $context, 'logo_rail' ) && false !== strpos( $context, 'evidence_accordion' ) && false !== strpos( $context, 'link_sources' ), 'Ordinary GET documents credential choices as optional controls' );
    echo wp_json_encode( [ 'result' => 'PASS', 'checks' => $checks ], JSON_PRETTY_PRINT ) . "\n";
} finally {
    if ( $id ) { wp_update_post( [ 'ID' => $id, 'post_status' => 'trash' ] ); }
    remove_filter( 'pre_option_' . Shared::OPTION_PRESETS, $read_presets );
    remove_filter( 'pre_update_option_' . Shared::OPTION_PRESETS, $write_presets, 10 );
    remove_filter( 'pre_update_option_' . Shared::OPTION_TEMPLATE, $retain_template, 10 );
}
