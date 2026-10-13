<?php
/** Focused ACF presets through registered design/review routes and real schema transport. */
require __DIR__ . '/layout-recipes-api-unit.php';
$baseline_checks = $checks;
$baseline_schema = $acf_schema;
$baseline_values = $acf_values;
$before_native = $writes;
$before_field_writes = $acf_writes;

function acf_schema_node( array $schema, string $pointer ): array {
    foreach ( Nova_Bridge_Suite_Recipe_Engine::parts( $pointer ) as $part ) { $schema = $schema['properties'][$part] ?? []; }
    return $schema;
}
function acf_checked_compile( array $design ): array {
    $result = Nova_Bridge_Suite_Recipe_Engine::compile( $design['recipe_preset'], $design['sample'], 'acf-focused-preset' );
    check( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'The ACF preset could not compile.' );
    preview( $design, $design['recipe_preset'], $design['sample'] );
    return $result;
}
function acf_twin_rows( string $name, int $minimum, int $maximum, string $choice ): array {
    return af( $name, 'repeater', [ 'required' => true, 'min' => 2, 'max' => 3, 'sub_fields' => [
        af( 'title', 'text', [ 'required' => true, 'max_length' => 30 ] ),
        af( 'score', 'number', [ 'min' => $minimum, 'max' => $maximum ] ),
        af( 'choice', 'select', [ 'choices' => [ 'same' => 'Shared default', $choice => 'Own allowed choice' ] ] ),
    ] ] );
}
$acf_schema = [
    'contact' => af( 'contact', 'group', [ 'sub_fields' => [ af( 'email', 'email', [ 'required' => true ] ), af( 'url', 'url' ), af( 'date', 'date_picker' ), af( 'enabled', 'true_false' ), af( 'short', 'text', [ 'label' => 'Één', 'max_length' => 1 ] ) ] ] ),
    'low' => acf_twin_rows( 'low', 1, 4, 'low-choice' ),
    'high' => acf_twin_rows( 'high', 10, 20, 'high-choice' ),
    'siblings' => af( 'siblings', 'repeater', [ 'sub_fields' => [ af( 'heading' ), acf_twin_rows( 'left', 2, 5, 'left-choice' ), acf_twin_rows( 'right', 30, 40, 'right-choice' ) ] ] ),
    'matrix' => af( 'matrix', 'flexible_content', [ 'layouts' => [
        [ 'key' => 'layout_first', 'name' => 'copy', 'label' => 'Copy', 'sub_fields' => [ af( 'title' ), af( 'body', 'wysiwyg' ) ] ],
        [ 'key' => 'layout_second', 'name' => '2faq', 'label' => 'FAQ', 'sub_fields' => [ af( 'question' ), af( 'answer', 'wysiwyg' ), acf_twin_rows( 'items', 50, 60, 'faq-choice' ) ] ],
    ] ] ),
];

foreach ( [ 'static', 'mixed', 'rules' ] as $mode ) {
    $typed = design( 'acf', $mode );
    $compiled = acf_checked_compile( $typed );
    check( $compiled['native_document']['contact']['email'] === 'example@example.com' && $compiled['native_document']['contact']['date'] === '20260101', 'The typed ' . $mode . ' sample omitted valid email/date values.' );
    check( $compiled['native_document']['contact']['short'] === 'É', 'A bounded Unicode sample was truncated by bytes.' );
    check( $compiled['native_document']['low'][0]['score'] === 1.0 && $compiled['native_document']['high'][0]['score'] === 10.0, 'Identical-shape collections used the wrong numerical schema in ' . $mode . '.' );
    if ( 'static' === $mode ) {
        check( ! $typed['recipe_preset']['regions'], 'A static ACF preset acquired repeat rules.' );
        foreach ( $typed['recipe_preset']['bindings'] as $binding ) {
            if ( $binding['target'] === '/document/high/0/score' ) { $node = acf_schema_node( $compiled['content_schema'], $binding['source'] ); check( $node['minimum'] === 10.0 && $node['maximum'] === 20.0, 'A static native field exported another collection\'s bounds.' ); }
        }
    } else {
        $low_node = acf_schema_node( $compiled['content_schema'], '/acf/low' );
        $high_node = acf_schema_node( $compiled['content_schema'], '/acf/high' );
        check( $low_node['minItems'] === 2 && $low_node['maxItems'] === 3 && $high_node['items']['properties']['score']['minimum'] === 10.0 && $low_node['items']['properties']['score']['maximum'] === 4.0, 'Collection cardinality or exact numerical constraints were lost.' );
        check( $low_node['items']['properties']['choice']['enum'] === [ 'same', 'low-choice' ] && $high_node['items']['properties']['choice']['enum'] === [ 'same', 'high-choice' ], 'Identical-shaped repeaters shared one choices schema.' );
        $edited = $typed['sample'];
        $edited['acf']['low'][0]['choice'] = 'low-choice'; $edited['acf']['high'][0]['choice'] = 'high-choice';
        $edited['acf']['low'][0]['score'] = 4; $edited['acf']['high'][0]['score'] = 20;
        $edited['acf']['siblings'][0]['left'][0]['choice'] = 'left-choice'; $edited['acf']['siblings'][0]['right'][0]['choice'] = 'right-choice';
        $edited['acf']['siblings'][0]['left'][0]['score'] = 5; $edited['acf']['siblings'][0]['right'][0]['score'] = 40;
        $translated = Nova_Bridge_Suite_Recipe_Engine::compile( $typed['recipe_preset'], $edited, 'acf-twin-scopes' );
        check( ! is_wp_error( $translated ), is_wp_error( $translated ) ? $translated->get_error_message() : 'Legal values from separate ACF schemas did not compile.' );
        check( $translated['native_document']['siblings'][0]['right'][0]['score'] === 40 && $translated['native_document']['siblings'][0]['left'][0]['score'] === 5, 'Nested sibling collections lost their exact schema context.' );
        $invalid = $edited; $invalid['acf']['high'][0]['score'] = 4;
        check( is_wp_error( Nova_Bridge_Suite_Recipe_Engine::compile( $typed['recipe_preset'], $invalid, 'acf-invalid-number' ) ), 'A value legal only in another identical-shaped collection was accepted.' );
        $invalid = $edited; $invalid['acf']['high'] = [ $edited['acf']['high'][0] ];
        rejected( Nova_Bridge_Suite_Recipe_Engine::compile( $typed['recipe_preset'], $invalid, 'acf-too-few-rows' ), 422, 'An installed collection minimum was ignored.' );
        $invalid = $edited; $invalid['acf']['high'][0]['title'] = str_repeat( 'é', 31 );
        rejected( Nova_Bridge_Suite_Recipe_Engine::compile( $typed['recipe_preset'], $invalid, 'acf-text-too-long' ), 422, 'Native text character bounds were ignored.' );
    }
}

// Captured first variant is not the first declared layout, and has empty nested rows.
$acf_values[11] = [];
$acf_values[11][$acf_schema['matrix']['key']] = [ [ 'acf_fc_layout' => '2faq', 'question' => 'Captured question?', 'answer' => '<p>Captured answer.</p>', 'items' => [] ], [ 'acf_fc_layout' => 'copy', 'title' => 'Second native row', 'body' => '<p>Second body.</p>' ] ];
$captured = design( 'acf', 'mixed', 'post_type:page', 11 );
$matrix_region = null; foreach ( $captured['recipe_preset']['regions'] as $region ) { if ( $region['target'] === '/document/matrix' ) { $matrix_region = $region; } }
check( $matrix_region && $matrix_region['count'] === 2 && $matrix_region['prototype_start'] === 0 && $matrix_region['prototype_count'] === 1 && count( $matrix_region['prototype']['native'] ) === 1, 'Replacing multiple captured rows repeated the complete collection instead of one prototype.' );
check( $matrix_region['prototype']['native'][0]['acf_fc_layout'] === '2faq' && $matrix_region['prototype']['native'][0] === $captured['blueprint']['document']['matrix'][0], 'The captured first row was replaced by the first declared layout.' );
check( $captured['sample']['acf']['matrix'][0]['layout'] === '2faq' && count( $matrix_region['variants']['2faq']['native'][0]['items'] ) === 2, 'Nested empty captured collections lacked a valid local prototype or exact native variant.' );
$captured_result = acf_checked_compile( $captured );
check( $captured_result['native_document']['matrix'][0]['acf_fc_layout'] === '2faq' && $captured_result['native_document']['matrix'][0]['items'][0]['score'] === 50.0, 'The reviewed captured flex preset lost typed nested content.' );

$acf_schema['matrix']['layouts'][0]['min'] = 1; $acf_schema['matrix']['layouts'][0]['max'] = 1;
$acf_schema['matrix']['layouts'][1]['min'] = 1; $acf_schema['matrix']['layouts'][1]['max'] = 2;
foreach ( [ 'static', 'mixed', 'rules' ] as $mode ) {
    $bounded_flex = design( 'acf', $mode ); $bounded_result = acf_checked_compile( $bounded_flex );
    check( array_column( $bounded_result['native_document']['matrix'], 'acf_fc_layout' ) === [ 'copy', '2faq' ], 'The ' . $mode . ' preset omitted a required declared flexible layout.' );
    if ( 'static' !== $mode ) { $invalid = $bounded_flex['sample']; $invalid['acf']['matrix'] = [ $invalid['acf']['matrix'][0] ]; rejected( Nova_Bridge_Suite_Recipe_Engine::compile( $bounded_flex['recipe_preset'], $invalid, 'acf-missing-layout' ), 422, 'A required declared flexible layout was omitted.' ); }
}

$before_reads = $acf_reads; $denied_caps = [ 'edit_post_meta' ];
rejected( Nova_Bridge_Suite_Recipe_ACF::design( 'page', 11 ), 403, 'Metadata-denied source values were inspected.' );
check( $acf_reads === $before_reads, 'ACF values were read before post metadata permission checks.' );
$denied_caps = [ 'edit_term_meta' ];
rejected( Nova_Bridge_Suite_Recipe_ACF::design( '', 31, '', 'product_cat' ), 403, 'Metadata-denied term values were inspected.' );
check( $acf_reads === $before_reads, 'ACF values were read before term metadata permission checks.' ); $denied_caps = [];
$term_design = design( 'acf', 'rules', 'taxonomy:product_cat' );
$term_result = acf_checked_compile( $term_design );
check( $term_design['blueprint']['taxonomy'] === 'product_cat' && $term_result['native_document']['high'][0]['score'] === 10.0, 'Source-free taxonomy fields did not use their installed typed schema.' );
$acf_schema = [];
rejected( Nova_Bridge_Suite_Recipe_Engine::compile( $term_design['recipe_preset'], $term_design['sample'], 'acf-schema-absent' ), 409, 'Removing the installed schema bypassed native validation.' );
$acf_schema = $baseline_schema; $acf_values = $baseline_values;
check( $writes === $before_native && $acf_writes === $before_field_writes, 'ACF design, review or translation wrote native posts/fields.' );
echo 'PASS ' . ( $checks - $baseline_checks ) . " focused ACF recipe checks (plus the registered API baseline).\n";
