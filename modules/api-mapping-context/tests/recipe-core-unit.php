<?php
/** Real Core capture/preset/compile checks through the registered recipe API fixture. */
require __DIR__ . '/layout-recipes-api-unit.php';
$core_baseline = $checks; $core_writes = $writes; $core_options = $options;
$original_post = clone $posts[11];
$posts[11]->post_content = "<!-- retained source -->\n<div class=\"original-frame\" data-native=\"keep\"><h2>Existing title</h2><p>Existing body — 🛰️</p><script>sourceOwned()</script></div>";
$source_bytes = $posts[11]->post_content;
$source_entity = serialize( $posts[11] );
$core_resource = Nova_Bridge_Suite_Document_Mapping::resources()[0];
$adapter = new Nova_Bridge_Suite_Recipe_Core(); $captured_hash = null;
foreach ( [ 'static', 'mixed', 'rules' ] as $mode ) {
    $captured = Nova_Bridge_Suite_Recipe_Core::design( $core_resource, 11, $mode );
    check( ! is_wp_error( $captured ), 'Core capture failed in ' . $mode . '.' );
    $hash = Nova_Bridge_Suite_Recipe_Engine::digest( $captured['blueprint'] );
    check( null === $captured_hash || $hash === $captured_hash, 'Source capture changed its native digest with the template mode.' ); $captured_hash = $hash;
    check( array_column( $captured['fields'], 'target' ) === [ '/document/title', '/document/sections/0/body' ], 'Existing Core inventory invented an introduction, section heading or FAQ field.' );
    check( ! isset( $captured['blueprint']['document']['intro'] ) && ! isset( $captured['blueprint']['document']['sections'][0]['heading'] ) && [] === $captured['blueprint']['document']['faqs'], 'Source-only Core capture added structural placeholders.' );
    check( array_column( $captured['containers'], 'target' ) === [ '/document/sections' ], 'An empty source FAQ collection was offered as a real design region.' );
    $design = data( route( 'design', 'POST', [], [ 'resource_id' => 'post_type:page', 'builder' => 'core', 'reference_id' => 11, 'mode' => $mode, 'manual_selection' => true ] ) );
    check( [] === $design['recipe_preset']['bindings'] && [] === $design['recipe_preset']['regions'], 'Manual capture preconfigured fields or regions.' );
    $kept = Nova_Bridge_Suite_Recipe_Engine::compile( $design['recipe_preset'], [], 'core-source-keep' );
    check( ! is_wp_error( $kept ) && $kept['writer_payload']['content'] === $source_bytes, 'Keeping an existing Core body changed source bytes or inserted an empty FAQ.' );
    check( $kept['summary']['faq_items'] === 0 && $kept['native_document'] === $captured['blueprint']['document'], 'Keep-all capture introduced native content elements.' );
    preview( $design, $design['recipe_preset'], [] );
    $rewritten = $design['recipe_preset']; $rewritten['bindings'][] = [ 'target' => '/document/sections/0/body', 'source' => '/replacement', 'format' => 'html', 'required' => false, 'label' => 'Complete body', 'instructions' => '' ];
    $changed = Nova_Bridge_Suite_Recipe_Engine::compile( $rewritten, [ 'replacement' => '<p>Changed body</p><script>generatedUnsafe()</script>' ], 'core-source-replaced' );
    check( ! is_wp_error( $changed ) && '<p>Changed body</p>' === $changed['writer_payload']['content'], 'Generated content bypassed HTML sanitation or retained a fictitious FAQ.' );
}
$dynamic = data( route( 'design', 'POST', [], [ 'resource_id' => 'post_type:page', 'builder' => 'core', 'reference_id' => 11, 'mode' => 'mixed', 'manual_selection' => true ] ) );
$selected = data( route( 'region', 'POST', [], [ 'design' => [ 'builder' => 'core', 'blueprint' => $dynamic['blueprint'], 'seal' => $dynamic['seal'] ], 'target' => '/document/sections', 'start' => 0, 'count' => 1, 'prototype_start' => 0, 'prototype_count' => 1, 'source' => '/sections', 'kind' => 'repeat', 'label' => 'Complete content fields' ] ) );
check( array_column( $selected['fields'], 'target' ) === [ '/0/body' ] && array_column( $selected['region']['prototype']['bindings'], 'target' ) === [ '/0/body' ], 'Dynamic source regions invented a heading instead of using their real body field.' );
$dynamic_recipe = $dynamic['recipe_preset']; $dynamic_recipe['regions'] = [ $selected['region'] ];
$region_source = $selected['region']['prototype']['bindings'][0]['source']; check( '/body' === $region_source, 'The real Core body region lost its declared content source.' ); $items = [ [ 'body' => '<p>First real body</p>' ], [ 'body' => '<p>Second real body</p>' ] ];
$repeated = Nova_Bridge_Suite_Recipe_Engine::compile( $dynamic_recipe, [ 'sections' => $items ], 'core-real-body-region' );
check( ! is_wp_error( $repeated ) && '<p>First real body</p><p>Second real body</p>' === $repeated['writer_payload']['content'] && $repeated['summary']['faq_items'] === 0, 'Repeating real Core content added an unrequested section heading or FAQ.' );
$term_resource = Nova_Bridge_Suite_Document_Mapping::resources()[2];
$term = Nova_Bridge_Suite_Recipe_Core::design( $term_resource, 31, 'mixed' );
check( array_column( $term['fields'], 'target' ) === [ '/document/title', '/document/sections/0/body' ], 'Term capture invented page-template fields.' );
$term_recipe = [ 'format' => 'nova-layout-recipe/v1', 'builder' => 'core', 'mode' => 'mixed', 'blueprint' => $term['blueprint'], 'bindings' => [], 'regions' => [] ];
$term_kept = Nova_Bridge_Suite_Recipe_Engine::compile( $term_recipe, [], 'core-term-keep' );
check( ! is_wp_error( $term_kept ) && '<p>Category body.</p>' === $term_kept['writer_payload']['description'] && 'Category source' === $term_kept['writer_payload']['name'], 'Existing term fields gained an empty FAQ or lost their exact native content.' );
$starter = Nova_Bridge_Suite_Recipe_Core::design( $core_resource, 0, 'mixed' );
// Editing one mapped native field must not copy the example's other field to an unrelated term.
$term_patch = $term_recipe; $term_patch['bindings'] = [ [ 'target' => '/document/title', 'source' => '/title', 'format' => 'text', 'required' => false ], [ 'target' => '/document/sections/0/body', 'source' => '/top', 'format' => 'html', 'required' => false ] ];
$omitted = Nova_Bridge_Suite_Recipe_Engine::compile( $term_patch, [], 'term-optional-omission' );
check( ! is_wp_error( $omitted ) && [] === $omitted['writer_payload'] && $omitted['native_document'] === $term['blueprint']['document'], 'Omitted optional taxonomy input overwrote native content or leaked example values into its patch.' );
$cleared = Nova_Bridge_Suite_Recipe_Engine::compile( $term_patch, [ 'top' => '' ], 'term-explicit-top-clear' );
check( ! is_wp_error( $cleared ) && [ 'description' => '' ] === $cleared['writer_payload'] && 'Category source' === $cleared['native_document']['title'], 'Clearing the top content also changed the title, or omitted input could not remain distinct from empty input.' );
check( isset( $starter['blueprint']['document']['intro'], $starter['blueprint']['document']['sections'][0]['heading'], $starter['blueprint']['document']['faqs'][0]['question'] ), 'Source-free Core starters lost their existing explicit content examples.' );
check( is_wp_error( Nova_Bridge_Suite_Recipe_Core::design( $core_resource, 99999, 'mixed' ) ), 'A missing source silently became a starter.' );
check( $source_entity === serialize( $posts[11] ) && $writes === $core_writes && $options === $core_options, 'Core capture/review/translation changed the source or persisted a mapping.' );
$posts[11] = $original_post;
echo 'PASS ' . ( $checks - $core_baseline ) . " Core source-capture checks (plus registered API baseline).\n";
