<?php
/** Public v0.8 values -> existing native writers; synthetic local fixtures only. */
require __DIR__ . '/protocol-unit.php';
$checks = 0;
$f = nova_contract_fixture(); $template = $f['configuration']['template'];
$template['definition']['fields'] = [
    [ 'id' => 'heading', 'label' => 'Heading', 'kind' => 'text', 'required' => true ],
    [ 'id' => 'copy_heading', 'label' => 'Second heading', 'kind' => 'text', 'required' => false ],
    [ 'id' => 'pictures', 'label' => 'Pictures', 'kind' => 'list', 'required' => false ],
    [ 'id' => 'page_url', 'label' => 'URL', 'kind' => 'url', 'required' => false ],
    [ 'id' => 'excerpt', 'label' => 'Excerpt', 'kind' => 'rich_text', 'required' => false ],
];
$template['mapping']['fields'] = [
    [ 'field_id' => 'heading', 'source_field' => 'title' ],
    [ 'field_id' => 'copy_heading', 'source_field' => 'title' ],
    [ 'field_id' => 'pictures', 'source_field' => 'image_urls' ],
    [ 'field_id' => 'page_url', 'source_field' => 'url' ],
    [ 'field_id' => 'excerpt', 'source_field' => 'top_content' ],
];
$profile = $f['configuration']; $profile['template'] = $template;
$wire = $f['snapshot']; unset( $wire['attempt_id'] );
$wire['configuration'] = [ 'id' => $template['id'], 'name' => $template['name'], 'page_type' => $template['page_type'], 'revision' => 1,
    'fields' => [
        [ 'id' => 'heading', 'label' => 'Heading', 'value_type' => 'text', 'required' => true ],
        [ 'id' => 'copy_heading', 'label' => 'Second heading', 'value_type' => 'text', 'required' => false ],
        [ 'id' => 'pictures', 'label' => 'Pictures', 'value_type' => 'list', 'required' => false ],
        [ 'id' => 'page_url', 'label' => 'URL', 'value_type' => 'url', 'required' => false ],
        [ 'id' => 'excerpt', 'label' => 'Excerpt', 'value_type' => 'rich_text', 'required' => false ],
    ], 'groups' => [] ];
$wire['content'] = [ 'heading' => '', 'copy_heading' => '', 'pictures' => [], 'page_url' => $wire['url'] ];
protocol_check( protocol_valid( json_decode( json_encode( $wire ) ), 'Delivery' ), 'Unified fields permit original empty strings and arrays.' );
$raw = json_encode( $wire );
$view = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $wire, $profile );
protocol_check( ! is_wp_error( $view ) && $view['content'] === [ 'title' => '', 'image_urls' => [], 'url' => $wire['url'] ], 'V1 values reverse only approved bindings and retain empty values.' );
protocol_check( ! array_key_exists( 'top_content', $view['content'] ) && json_encode( $wire ) === $raw && $view['configuration'] === $template, 'Missing replacement remains absent and wire bytes/configuration remain untouched.' );
foreach ( [ 'duplicate_conflict', 'duplicate_omission', 'unknown_field', 'url_conflict', 'url_omission', 'profile_revision', 'profile_label', 'site', 'page_type' ] as $case ) {
    $bad = $wire;
    if ( 'duplicate_conflict' === $case ) { $bad['content']['copy_heading'] = 'Different'; }
    if ( 'duplicate_omission' === $case ) { unset( $bad['content']['copy_heading'] ); }
    if ( 'unknown_field' === $case ) { $bad['content']['unknown'] = 'Extra'; }
    if ( 'url_conflict' === $case ) { $bad['content']['page_url'] .= 'changed/'; }
    if ( 'url_omission' === $case ) { unset( $bad['content']['page_url'] ); }
    if ( 'profile_revision' === $case ) { ++$bad['configuration']['revision']; }
    if ( 'profile_label' === $case ) { $bad['configuration']['fields'][0]['label'] .= 'changed'; }
    if ( 'site' === $case ) { $bad['site_id'] = '00000000-0000-4000-8000-000000000099'; }
    if ( 'page_type' === $case ) { $bad['page_type_frontend'] = 'informative'; }
    protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $bad, $profile ) ), 'Unsafe V1 reconstruction rejected: ' . $case );
}
$missing = $wire; unset( $missing['content']['heading'], $missing['content']['copy_heading'], $missing['content']['pictures'] );
$view = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $missing, $profile );
protocol_check( ! is_wp_error( $view ) && ! isset( $view['content']['title'], $view['content']['image_urls'] ), 'All omitted bindings for one source stay absent.' );
$list = $wire; $list['content']['pictures'] = [ 'https://images.example/one.jpg', 'https://images.example/two.jpg' ];
$view = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $list, $profile );
protocol_check( $view['content']['image_urls'] === $list['content']['pictures'], 'Original V1 array order and members survive.' );
$unmapped = $wire; $unmapped['configuration'] = null; $unmapped['content'] = [ 'title' => 'Unmapped', 'content' => '<p>Body</p>', 'image_urls' => [] ];
protocol_check( $unmapped === Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $unmapped, [] ), 'Unmapped canonical content passes through unchanged.' );
protocol_check( $f['snapshot'] === Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $f['snapshot'], $f['configuration'] ), 'Retained legacy writer snapshot stays unchanged.' );
$legacy_unmapped = $f['snapshot']; $legacy_unmapped['configuration'] = null; unset( $legacy_unmapped['attempt_id'] );
$legacy_raw = json_encode( $legacy_unmapped );
protocol_check( $legacy_unmapped === Nova_Bridge_Suite_Posting_Protocol::snapshot( [ 'status' => 200, 'raw_body' => $legacy_raw, 'etag' => '"' . hash( 'sha256', $legacy_raw ) . '"', 'attempt_id' => $f['attempt_id'] ], $f['job'] ), 'Legacy null configuration and nullable source values still accept a current header without a body attempt.' );

$v3 = $template; $v3['definition'] = [ 'version' => 3, 'fields' => [ [ 'id' => 'heading', 'label' => 'Heading', 'value_type' => 'text', 'required' => true, 'constraints' => [ 'max_length' => 120 ], 'instructions' => 'Private human guidance.' ] ], 'groups' => [] ]; $v3['mapping'] = [ 'version' => 3 ];
$profile['template'] = $v3; $v3wire = $wire; $v3wire['configuration'] = [ 'id' => $v3['id'], 'name' => $v3['name'], 'page_type' => $v3['page_type'], 'revision' => 1, 'fields' => [ [ 'id' => 'heading', 'label' => 'Heading', 'value_type' => 'text', 'required' => true, 'constraints' => [ 'max_length' => 120 ] ] ], 'groups' => [] ]; $v3wire['content'] = [ 'heading' => 'Fitted title' ];
$view = Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $v3wire, $profile );
protocol_check( ! is_wp_error( $view ) && $view['kind'] === 'adaptation.delivery' && $view['schema_version'] === 2 && $view['destination_values'] === $v3wire['content'], 'V3 uses fitted destination values without source remapping.' );
protocol_check( ! isset( $view['job_id'], $view['run_id'], $view['attempt_id'] ) && ! isset( $v3wire['configuration']['fields'][0]['instructions'] ), 'Local writer view does not manufacture remote attempt/job authority or modify wire metadata.' );
$bad = $v3wire; $bad['content']['unknown'] = 'extra'; protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $bad, $profile ) ), 'V3 unknown destination IDs fail before writing.' );
$bad = $v3wire; $bad['configuration']['fields'][0]['constraints']['max_length'] = 121; protocol_check( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::writer_snapshot( $bad, $profile ) ), 'V3 public constraints must match approved revision.' );
echo "PASS $checks unified public projection checks\n";
