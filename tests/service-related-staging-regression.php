<?php
/** Real WordPress SQL checks; published/noindex fixtures are trashed afterwards. */
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceRenderer as Renderer;

$checks = [];
$fixtures = [];
$queries = [];
$term_id = 0;
$taxonomy = 'nova_srv_test_' . substr( md5( wp_generate_uuid4() ), 0, 8 );
$previous_user = get_current_user_id();
$failure = null;
$assert = static function ( $condition, $label ) use ( &$checks ) {
    if ( ! $condition ) { throw new RuntimeException( $label ); }
    $checks[] = $label;
};
$admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
wp_set_current_user( $admins[0]->ID );
$driver = '';
$language = '';
$other_language = '';
if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_default_language' ) && function_exists( 'pll_languages_list' ) && ( ! function_exists( 'pll_is_translated_post_type' ) || pll_is_translated_post_type( 'service_page' ) ) ) {
    $driver = 'polylang'; $language = (string) pll_default_language( 'slug' );
    foreach ( pll_languages_list( [ 'fields' => 'slug' ] ) as $code ) { if ( $code !== $language ) { $other_language = $code; break; } }
} elseif ( has_action( 'wpml_set_element_language_details' ) ) {
    $driver = 'wpml'; $language = (string) apply_filters( 'wpml_default_language', null );
    foreach ( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) as $code => $details ) { if ( $code !== $language ) { $other_language = $code; break; } }
}
$assign_language = static function ( int $id, string $code ) use ( $driver ) {
    if ( '' === $code ) { return; }
    if ( 'polylang' === $driver ) { pll_set_post_language( $id, $code ); }
    elseif ( 'wpml' === $driver ) { do_action( 'wpml_set_element_language_details', [ 'element_id' => $id, 'element_type' => 'post_service_page', 'trid' => false, 'language_code' => $code, 'source_language_code' => null ] ); }
};
$create = static function ( string $label, string $status = 'publish', string $password = '', string $type = 'service_page' ) use ( &$fixtures, $assert, $assign_language, $language ) {
    $id = wp_insert_post( [ 'post_type' => $type, 'post_status' => $status, 'post_title' => 'NOVA related regression ' . $label, 'post_excerpt' => 'Temporary regression fixture only.', 'post_password' => $password, 'meta_input' => [ '_seopress_robots_index' => 'yes', '_yoast_wpseo_meta-robots-noindex' => '1' ] ], true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $fixtures[] = (int) $id;
    if ( 'service_page' === $type ) {
        $saved = Document::save_document( $id, [ 'version' => 2, 'sections' => [ [ 'id' => 'hero', 'type' => 'hero', 'enabled' => true, 'data' => [ 'title' => 'Temporary regression fixture' ] ] ] ] );
        if ( true !== $saved ) { throw new RuntimeException( 'Native fixture document could not be saved.' ); }
        $assign_language( $id, $language );
    }
    return (int) $id;
};
$related_doc = static function ( array $data = [], bool $enabled = true ) {
    return [ 'version' => 2, 'sections' => [ [ 'id' => 'related', 'type' => 'related', 'enabled' => $enabled, 'data' => $data ] ] ];
};
$links = static function ( string $html ): array {
    $dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
    try { $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET ); }
    finally { libxml_clear_errors(); libxml_use_internal_errors( $previous ); }
    $xpath = new DOMXPath( $dom ); $result = [];
    foreach ( $xpath->query( '//article[contains(concat(" ", normalize-space(@class), " "), " nova-service__related-card ")]//h3/a' ) as $link ) { $result[] = $link->getAttribute( 'href' ); }
    return $result;
};
$source = 0;
// Scope only this disposable source's discovery to fixtures. WordPress still
// performs the real publication, password, taxonomy, language and ordering SQL.
$scope = static function ( string $where, WP_Query $query ) use ( &$queries, &$fixtures, &$source ) {
    if ( 'service_page' === $query->get( 'post_type' ) && 12 === (int) $query->get( 'posts_per_page' ) && in_array( $source, (array) $query->get( 'post__not_in' ), true ) ) {
        $queries[] = $query->query_vars;
        global $wpdb;
        $where .= ' AND ' . $wpdb->posts . '.ID IN (' . implode( ',', array_map( 'intval', $fixtures ) ) . ')';
    }
    return $where;
};
try {
    $assert( post_type_exists( 'service_page' ), 'Service CPT exists for the real SQL query' );
    $source = $create( 'SOURCE' );
    $a = $create( 'OLDER SHARED TOPIC' ); $b = $create( 'RECENT B' ); $c = $create( 'RECENT C' ); $d = $create( 'RECENT D' );
    $draft = $create( 'DRAFT EXCLUDED', 'draft' );
    $protected = $create( 'PASSWORD EXCLUDED', 'publish', 'temporary-fixture-password' );
    $other_type = $create( 'BLOG EXCLUDED', 'publish', '', 'post' );
    add_filter( 'posts_where', $scope, 1000, 2 );
    $doc = $related_doc(); $assert( true === Document::save_document( $source, $doc ), 'Native source accepts an automatic related section without IDs' );
    $saved_before = Document::get_document( $source );
    $html = Renderer::render( $source ); $actual = $links( $html );
    $assert( [ get_permalink( $d ), get_permalink( $c ), get_permalink( $b ) ] === $actual, 'Automatic related SQL returns the three latest eligible services in stable order' );
    $assert( ! in_array( get_permalink( $source ), $actual, true ) && ! in_array( get_permalink( $draft ), $actual, true ) && ! in_array( get_permalink( $protected ), $actual, true ) && ! in_array( get_permalink( $other_type ), $actual, true ), 'Current page, drafts, protected pages and ordinary blog posts are excluded' );
    $assert( 12 === (int) $queries[0]['posts_per_page'] && false === $queries[0]['suppress_filters'] && false === $queries[0]['has_password'] && 'publish' === $queries[0]['post_status'], 'Automatic SQL query is bounded and retains language filters and public-only constraints' );
    $assert( $saved_before === Document::get_document( $source ), 'Automatic discovery never writes selected IDs into the service document' );
    Document::save_document( $source, $related_doc( [ 'post_ids' => [], 'items' => [] ] ) );
    $assert( $actual === $links( Renderer::render( $source ) ), 'Explicit empty selections resume automatic related cards' );
    $before_queries = count( $queries );
    Document::save_document( $source, $related_doc( [ 'post_ids' => [ $a ] ] ) );
    $assert( [ get_permalink( $a ) ] === $links( Renderer::render( $source ) ) && $before_queries === count( $queries ), 'Handpicked legacy IDs remain a manual override without discovery queries' );
    Document::save_document( $source, $related_doc( [ 'items' => [ [ 'title' => 'Manual resource', 'url' => 'https://example.com/resource/' ] ] ] ) );
    $assert( [ 'https://example.com/resource/' ] === $links( Renderer::render( $source ) ) && $before_queries === count( $queries ), 'Custom link cards remain a manual override' );
    Document::save_document( $source, $related_doc( [], false ) );
    $assert( [] === $links( Renderer::render( $source ) ) && $before_queries === count( $queries ), 'Disabled related section performs no automatic query' );
    register_taxonomy( $taxonomy, [ 'service_page' ], [ 'public' => true, 'publicly_queryable' => false, 'show_ui' => false, 'show_in_rest' => false ] );
    $term = wp_insert_term( 'Disposable related fixture topic', $taxonomy );
    if ( is_wp_error( $term ) ) { throw new RuntimeException( $term->get_error_message() ); }
    $term_id = (int) $term['term_id'];
    foreach ( [ $source, $a, $draft, $protected ] as $id ) { wp_set_object_terms( $id, [ $term_id ], $taxonomy ); }
    Document::save_document( $source, $related_doc() );
    $before_queries = count( $queries );
    $assert( [ get_permalink( $a ), get_permalink( $d ), get_permalink( $c ) ] === $links( Renderer::render( $source ) ), 'Shared public taxonomy gives a genuinely related older service priority, then fills with recent eligible services' );
    $phases = array_slice( $queries, $before_queries );
    $has_fixture_taxonomy = static function ( array $query ) use ( $taxonomy ): bool {
        foreach ( new RecursiveIteratorIterator( new RecursiveArrayIterator( (array) ( $query['tax_query'] ?? [] ) ) ) as $key => $value ) { if ( 'taxonomy' === $key && $taxonomy === $value ) { return true; } }
        return false;
    };
    $assert( 2 === count( $phases ) && $has_fixture_taxonomy( $phases[0] ) && ! $has_fixture_taxonomy( $phases[1] ) && in_array( $a, $phases[1]['post__not_in'], true ), 'Real taxonomy and fallback queries deduplicate the selected service' );
    if ( '' !== $other_language ) {
        $foreign = $create( 'OTHER LANGUAGE EXCLUDED' ); $assign_language( $foreign, $other_language );
        $assert( ! in_array( get_permalink( $foreign ), $links( Renderer::render( $source ) ), true ), 'Available multilingual plugin excludes a newer service assigned another language' );
    }
} catch ( Throwable $error ) {
    $failure = $error;
} finally {
    remove_filter( 'posts_where', $scope, 1000 );
    foreach ( $fixtures as $fixture ) { wp_trash_post( $fixture ); }
    if ( $term_id ) { wp_delete_term( $term_id, $taxonomy ); }
    if ( taxonomy_exists( $taxonomy ) ) { unregister_taxonomy( $taxonomy ); }
    wp_set_current_user( $previous_user );
}
if ( $failure ) { throw $failure; }
$assert( [] === array_filter( $fixtures, static function ( $id ) { return 'trash' !== get_post_status( $id ); } ), 'Every disposable published/draft fixture is safely moved to Trash' );
echo wp_json_encode( [ 'result' => 'PASS', 'checks' => $checks, 'fixtures' => $fixtures, 'language_driver' => $driver ?: 'none', 'other_language_tested' => '' !== $other_language ], JSON_PRETTY_PRINT ) . "\n";
