<?php
/** Real native plan construction against deterministic storage snapshots; no CMS or network. */
require __DIR__ . '/mapped-writer-contract-unit.php';
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
$v3 = nova_writer_fixture();
$local = &$v3['configuration']['local']; $local['catalog_mode'] = 'destination';
$local['fields']['/slug'] = [ 'mode' => 'protected', 'source_path' => '', 'instructions' => '' ];
$local['fields']['/title'] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => true, 'instructions' => 'Use the main page heading.',
    'description' => [ 'label' => 'Heading', 'purpose' => 'Introduce this category of services.', 'value_type' => 'text', 'constraints' => [ 'max_length' => 80 ] ] ];
// Existing nested ACF scalar: update only its leaf row, preserving the group and siblings.
$path = '/meta_all/hero_heading'; $local['fields'][ $path ] = $local['fields']['/title']; $local['fields'][ $path ]['required'] = false;
$local['target_descriptors'][ $path ] = [ 'path' => $path, 'reference_type' => 'post', 'reference_id' => 7, 'signature' => 'fixture-layout', 'acf_key' => 'field_hero_heading' ];
$v3['inventory'][ $path ] = [ 'path' => $path, 'writable' => true, 'type' => 'text', 'acf_key' => 'field_hero_heading' ];
$v3['snapshot']['meta'] = [ [ 'meta_id' => '11', 'meta_key' => 'hero_heading', 'meta_value' => 'Original heading' ], [ 'meta_id' => '12', 'meta_key' => '_hero_heading', 'meta_value' => 'field_hero_heading' ], [ 'meta_id' => '13', 'meta_key' => 'hero_subheading', 'meta_value' => 'Protected sibling' ] ];
$local['destination_groups'] = [ [ 'id' => '11111111-1111-4111-8111-111111111111', 'label' => 'Existing hero', 'slots' => [ [ 'id' => '22222222-2222-4222-8222-222222222222', 'ordinal' => 0, 'fields' => [ '/title', $path ] ] ] ] ];
$v3['content']['kind'] = 'adaptation.delivery'; $v3['content']['schema_version'] = 2;
$v3['content']['job_id'] = nova_writer_fixture_uuid( 'v3-job' ); $v3['content']['run_id'] = nova_writer_fixture_uuid( 'v3-run' ); $v3['content']['source_event_id'] = 'posting-source-v1-' . str_repeat( 'a', 64 );
unset( $v3['content']['content'] );
$v3['content']['destination_values'] = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Selected original heading' ];
nova_writer_fixture_refresh( $v3 );
$plan = plan_fixture( $v3 );
check( [ 'post_title' => 'Selected original heading' ] === $plan['changes']['post'] && [] === $plan['changes']['meta'], 'V3 writes the selected heading and preserves omitted optional nested fields.' );
// Native resolution is independently constrained: V3 does not authorize unsupported paths.
$nested = $v3; $nested['content']['destination_values'][ Nova_Bridge_Suite_Writing_Adapter::field_id( $path ) ] = 'Selected nested heading'; nova_writer_fixture_refresh( $nested );
try { $nested_plan = plan_fixture( $nested ); check( [ 11 => 'Selected nested heading' ] === $nested_plan['changes']['meta'], 'Nested field changes only its existing scalar row.' ); }
catch ( Nova_Bridge_Suite_Writer_Failure $error ) { throw new RuntimeException( 'Nested fixture must use a supported native descriptor: ' . $error->reason . ': ' . $error->getMessage() ); }
$bad = $v3; $bad['content']['destination_values']['unknown_target'] = 'No'; nova_writer_fixture_refresh( $bad ); rejects( 'fitted_values', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $v3; $bad['content']['destination_values'] = []; nova_writer_fixture_refresh( $bad ); rejects( 'fitted_values', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $v3; $bad['content']['destination_values'][ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) ] = str_repeat( 'x', 81 ); nova_writer_fixture_refresh( $bad ); rejects( 'fitted_values', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $v3; $bad['configuration']['local']['fields']['/title']['instructions'] = 'Changed without reapproval'; rejects( 'binding_identity', function () use ( $bad ) { plan_fixture( $bad ); } );
// A direct mapping with instructions selects V3 without changing its local stored modes.
$promoted = nova_writer_fixture();
$promoted['configuration']['local']['fields']['/slug']['mode'] = 'protected';
$promoted['configuration']['local']['fields']['/slug']['source_path'] = '';
$promoted['configuration']['local']['fields']['/title']['instructions'] = 'Use the introductory heading.';
$promoted['content']['kind'] = 'adaptation.delivery'; $promoted['content']['schema_version'] = 2;
$promoted['content']['job_id'] = $v3['content']['job_id']; $promoted['content']['run_id'] = $v3['content']['run_id']; $promoted['content']['source_event_id'] = $v3['content']['source_event_id'];
unset( $promoted['content']['content'] );
$promoted['content']['destination_values'] = [ Nova_Bridge_Suite_Writing_Adapter::field_id( '/title' ) => 'Fitted original heading' ];
nova_writer_fixture_refresh( $promoted );
check( 3 === $promoted['configuration']['template']['definition']['version'] && 'mapped' === $promoted['configuration']['local']['fields']['/title']['mode'], 'Instructions promote the wire version while retaining the saved local policy.' );
check( [ 'post_title' => 'Fitted original heading' ] === plan_fixture( $promoted )['changes']['post'], 'Promoted mappings consume fitted results through the real native plan builder.' );

$historical = nova_writer_fixture();
$historical['configuration']['local']['guidance_mode'] = 'set'; $historical['configuration']['local']['guidance'] = 'Earlier local-only notes.';
$historical['configuration']['profile_digest'] = hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $historical['configuration']['local'] ) );
check( 'Generated fixture heading' === plan_fixture( $historical )['changes']['post']['post_title'], 'An approved historical V1 delivery retains direct behavior despite old local notes.' );
echo "PASS V3 native plans, fixed nested fields, omissions, version promotion and historical V1\n";
