<?php
/** Real read-only expansion API, source receipts, saved revisions and frozen UX Block compilation. */
function get_page_by_path( $path, $output = 'OBJECT', $types = 'page' ) {
    foreach ( $GLOBALS['posts'] ?? [] as $post ) { if ( $post->post_name === $path && in_array( $post->post_type, (array) $types, true ) ) { return $post; } }
    return null;
}
// Reuse the real adapter/compiler/API registration and memory-only WordPress seams.
require __DIR__ . '/flatsome-media-integration-unit.php';
$checks_before = $checks;
$posts[701] = new WP_Post( [ 'ID' => 701, 'post_type' => 'page', 'post_title' => 'Linked block example', 'post_content' => '[section][title text="Protected page heading"][block id="801"][block id="802"][/section]', 'post_status' => 'draft', 'post_author' => 7 ] );
$posts[801] = new WP_Post( [ 'ID' => 801, 'post_type' => 'blocks', 'post_title' => 'Shared body', 'post_content' => '[ux_text]<p>Captured first block body.</p>[/ux_text]', 'post_status' => 'publish', 'post_author' => 7, 'post_name' => 'shared-body' ] );
$posts[802] = new WP_Post( [ 'ID' => 802, 'post_type' => 'blocks', 'post_title' => 'Shared footer', 'post_content' => '[ux_text]<p>Captured second block body.</p>[/ux_text]', 'post_status' => 'publish', 'post_author' => 7, 'post_name' => 'shared-footer' ] );

function block_request( array $design, string $target ): array {
    return [ 'resource_id' => 'post_type:page', 'mode' => 'static', 'target' => $target, 'design' => [ 'builder' => 'flatsome', 'blueprint' => $design['blueprint'], 'seal' => $design['seal'] ], 'source_reference' => $design['source_reference'], 'source_receipt' => $design['source_receipt'] ];
}
function block_rejected( $result, string $why ): void { check( is_wp_error( $result ), $why ); }
function block_preview( array $design, array $recipe, array $content ): array {
    return data( route( 'preview', 'POST', [], [ 'resource_id' => 'post_type:page', 'recipe' => $recipe, 'seal' => $design['seal'], 'input' => [ 'content' => $content ], 'source_reference' => $design['source_reference'], 'source_receipt' => $design['source_receipt'] ] ) );
}

$design = data( route( 'design', 'POST', [], [ 'resource_id' => 'post_type:page', 'builder' => 'flatsome', 'reference_id' => 701, 'mode' => 'static', 'manual_selection' => true ] ) );
check( count( $design['reusable_blocks'] ) === 2 && array_column( $design['reusable_blocks'], 'mode' ) === [ 'linked', 'linked' ], 'Capture keeps both reusable blocks linked by default' );
$first = $design['reusable_blocks'][0]['target']; $second = $design['reusable_blocks'][1]['target'];
check( ! array_filter( $design['fields'], static function ( $field ) use ( $first, $second ) { return $field['writable'] && ( str_starts_with( $field['target'], $first . '/' ) || str_starts_with( $field['target'], $second . '/' ) ); } ), 'Linked content exposes no writable children' );
$before = [ $options, $post_meta, $writes ];
$expanded = data( route( 'reusable-block', 'POST', [], block_request( $design, $first ) ) );
check( $before === [ $options, $post_meta, $writes ], 'Expanding one selected UX Block performs no persistence or native writes' );
check( array_column( $expanded['reusable_blocks'], 'mode' ) === [ 'captured', 'linked' ], 'Only the selected occurrence expands' );
check( $expanded['seal'] !== $design['seal'] && $expanded['source_receipt'] !== $design['source_receipt'], 'Expanded content receives a fresh sealed blueprint and full source receipt' );
check( $expanded['recipe_preset']['bindings'] === [] && $expanded['recipe_preset']['regions'] === [], 'Expansion never silently selects child fields for mapping' );
check( $expanded['visual_preview']['status'] === 'unavailable', 'Live source canvas does not misrepresent a frozen block snapshot' );
$child = null;
foreach ( $expanded['fields'] as $field ) { if ( $field['writable'] && $field['format'] === 'html' && str_starts_with( $field['target'], $first . '/children/' ) ) { $child = $field; break; } }
check( is_array( $child ), 'Captured body is available as an independent rich-text destination' );
$recipe = $expanded['recipe_preset'];
$recipe['bindings'] = [ [ 'target' => $child['target'], 'source' => '/body', 'format' => 'html', 'required' => true, 'label' => 'Mapped captured body', 'instructions' => '' ] ];
$content = [ 'body' => '<p>Mapped first paragraph.</p><h2>Mapped subheading</h2><p>Mapped final paragraph.</p>' ];
$preview = block_preview( $expanded, $recipe, $content );
check( $before === [ $options, $post_meta, $writes ], 'Preview of the expanded block remains read-only' );

// The captured UX Block may disappear; its original page/link stays unchanged.
unset( $posts[801] );
$save_body = [ 'resource_id' => 'post_type:page', 'recipe' => $recipe, 'seal' => $expanded['seal'], 'expected_revision' => 0, 'expected_default' => default_identity( 'post_type:page' ), 'label' => 'Selective block snapshot', 'content' => $content, 'preview_receipt' => $preview['preview_receipt'], 'source_reference' => $expanded['source_reference'], 'source_receipt' => $expanded['source_receipt'] ];
$saved = data( route( 'save', 'POST', [], $save_body ) );
check( $saved['revision'] === 1, 'Saving the captured snapshot does not reread its deleted UX Block' );
$reloaded = data( route( 'profile', 'GET', [ 'id' => $saved['id'] ] ) );
check( $reloaded['design']['blueprint'] === $expanded['blueprint'] && $reloaded['recipe']['bindings'] === $recipe['bindings'], 'Saved reload retains exact captured content and selected binding' );
$before_second = [ $options, $post_meta, $writes ];
$both = data( route( 'reusable-block', 'POST', [], block_request( $reloaded['design'], $second ) ) );
check( array_column( $both['reusable_blocks'], 'mode' ) === [ 'captured', 'captured' ] && $before_second === [ $options, $post_meta, $writes ], 'Reloaded template can capture another linked block without rereading the deleted captured one or saving' );

$forged = block_request( $expanded, $second );
Nova_Bridge_Suite_Recipe_Engine::put( $forged['design']['blueprint'], $child['target'], 'Forged body' );
block_rejected( route( 'reusable-block', 'POST', [], $forged ), 'Snapshot forgery with its previous seal is rejected' );
$forged = block_request( $expanded, $second ); $forged['source_receipt'] = str_repeat( 'x', 64 );
block_rejected( route( 'reusable-block', 'POST', [], $forged ), 'Forged source receipt is rejected' );
$missing_source = block_request( $expanded, $second ); unset( $missing_source['source_reference'] );
block_rejected( route( 'reusable-block', 'POST', [], $missing_source ), 'Omitting source provenance cannot bypass freshness checks' );
$wrong_destination = block_request( $expanded, $second ); $wrong_destination['resource_id'] = 'post_type:post';
block_rejected( route( 'reusable-block', 'POST', [], $wrong_destination ), 'Expansion cannot cross native destination boundaries' );
$invalid = block_request( $expanded, '/document/1/attributes/id' );
block_rejected( route( 'reusable-block', 'POST', [], $invalid ), 'Only a native block node target can be selected' );
block_rejected( route( 'reusable-block', 'POST', [], block_request( $expanded, $first ) ), 'An already captured node cannot be recaptured implicitly' );
$administrator = false;
block_rejected( route( 'reusable-block', 'POST', [], block_request( $expanded, $second ) ), 'Read-only capture still requires administrator permission' );
$administrator = true;
$nonce_valid = false;
block_rejected( route( 'reusable-block', 'POST', [], block_request( $expanded, $second ) ), 'Invalid UI nonce is rejected' );
$nonce_valid = true;

$original_source = $posts[701]->post_content;
$posts[701]->post_content = '[section][title text="Changed source page"][block id="801"][block id="802"][/section]';
block_rejected( route( 'reusable-block', 'POST', [], block_request( $expanded, $second ) ), 'Changed original page still invalidates expansion provenance' );
$save_body['id'] = $saved['id']; $save_body['expected_revision'] = 1; $save_body['expected_default'] = default_identity( 'post_type:page' );
block_rejected( route( 'save', 'POST', [], $save_body ), 'Changed original page still prevents saving stale mapping choices' );
$posts[701]->post_content = $original_source;
$store = good( Nova_Bridge_Suite_Stored_Recipe_Layout::from_recipe( $saved ) );
unset( $posts[701], $posts[802] );
$fitted = [ Nova_Bridge_Suite_Writing_Adapter::field_id( $child['target'] ) => $content['body'], Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Source-free block page' ];
$compiled = good( Nova_Bridge_Suite_Stored_Recipe_Layout::compile( $store, $fitted, 'selective-block-independent' ) );
$native = Nova_Bridge_Suite_Flatsome_Document::serialize( $compiled['writer_payload']['flatsome_document'] );
check( str_contains( $native, 'Mapped first paragraph.' ) && str_contains( $native, '[block id="802"]' ) && ! str_contains( $native, '[block id="801"]' ), 'Publication uses captured child content independently while retaining an unselected block link' );

echo 'PASS ' . ( $checks - $checks_before ) . " selective reusable-block API/provenance checks (memory only, no DB/HTTP).\n";
