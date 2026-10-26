<?php
/** Intent selection, actual sync upgrade and immutable pre-upgrade delivery profiles. */
require __DIR__ . '/v3-sync-unit.php';
$direct = $original; $direct['reference_id'] = 92; $direct['revision'] = 'version-selection';
$nested = '/acf/hero/rows/0/heading';
$direct['fields'][ $nested ] = $direct['fields']['/title'];
$direct['target_descriptors'][ $nested ] = $target( $nested );
check( 1 === Nova_Bridge_Suite_Writing_Adapter::template_input( $direct )['definition']['version'], 'Nested native paths and protection notes do not require fitting.' );
$with_notes = $direct; $with_notes['fields'][ $nested ]['instructions'] = 'Select the shortest relevant original heading.';
$promoted = Nova_Bridge_Suite_Writing_Adapter::template_input( $with_notes );
check( ! is_wp_error( $promoted ) && 3 === $promoted['definition']['version'] && 3 === $promoted['mapping']['version'], 'Written field instructions automatically select V3.' );
$fields = array_column( $promoted['definition']['fields'], null, 'id' );
check( $fields[ Nova_Bridge_Suite_Writing_Adapter::field_id( $nested ) ]['instructions'] === $with_notes['fields'][ $nested ]['instructions'], 'Nested field instructions survive serialization exactly.' );
check( 2 === count( $promoted['mapping']['source_hints'] ) && ! str_contains( json_encode( $promoted ), '/acf/' ), 'Existing source choices become hints and native paths stay local.' );
check( 'mapped' === $with_notes['fields'][ $nested ]['mode'], 'Projection never mutates the retained draft.' );
$inherited = $direct; $inherited['catalog_snapshot']['authoring_notes'] = 'Use existing source sections for this layout.';
check( 3 === Nova_Bridge_Suite_Writing_Adapter::template_input( $inherited )['definition']['version'], 'Inherited instructions select V3.' );
$inherited['guidance_mode'] = 'clear';
check( 1 === Nova_Bridge_Suite_Writing_Adapter::template_input( $inherited )['definition']['version'], 'Explicit clearing restores direct V1 when no field needs fitting.' );
$with_template = $direct; $with_template['guidance_mode'] = 'set'; $with_template['guidance'] = 'Prefer introductory passages.';
check( Nova_Bridge_Suite_Writing_Adapter::template_input( $with_template )['definition']['instructions']['text'] === $with_template['guidance'], 'Template instructions are sent, not silently dropped.' );
$malformed = $direct; $malformed['fields']['/title']['instructions'] = [];
error_is( Nova_Bridge_Suite_Writing_Adapter::template_input( $malformed ), 'notes_size', 400 );

// Seed a previously approved V1 profile with notes that the old serializer kept local.
$legacy_input = Nova_Bridge_Suite_Writing_Adapter::template_input( $with_notes, 1 );
$legacy_record = array_merge( $record, $legacy_input, [ 'id' => '77777777-7777-4777-8777-777777777777', 'revision' => 1 ] );
$frozen_legacy = array_intersect_key( $legacy_record, array_flip( [ 'id', 'name', 'page_type', 'revision', 'definition', 'mapping' ] ) );
$legacy_profile = [ 'contract' => Nova_Bridge_Suite_Mapping_Sync::CONTRACT, 'site_id' => $site, 'template' => $frozen_legacy, 'remote' => $frozen_legacy, 'local' => $with_notes, 'profile_digest' => hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $with_notes ) ) ];
add_option( 'nova_publishing_profile_' . hash( 'sha256', $site . ':' . $legacy_record['id'] . ':1' ), $legacy_profile );
$legacy_snapshot = [ 'site_id' => $site, 'configuration' => $frozen_legacy ];
check( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $legacy_snapshot, $site ) === $legacy_profile, 'Historical approved V1 with notes remains executable without reinterpretation.' );
$upgrade_api = new QueueClient( $connection ); $upgrade = new Nova_Bridge_Suite_Mapping_Sync( $upgrade_api );
$acquire = new ReflectionMethod( $upgrade, 'acquire' ); $checkpoint = new ReflectionMethod( $upgrade, 'checkpoint' ); $release = new ReflectionMethod( $upgrade, 'release' );
check( true === $acquire->invoke( $upgrade, $with_notes ), 'Acquire upgrade state fixture.' );
$state_property = new ReflectionProperty( $upgrade, 'state' );
$state_property->setValue( $upgrade, [ 'contract' => Nova_Bridge_Suite_Mapping_Sync::CONTRACT, 'site_id' => $site, 'status' => 'active', 'local_revision' => $with_notes['revision'], 'local' => $with_notes, 'template' => $legacy_record ] );
$checkpoint->invoke( $upgrade ); $release->invoke( $upgrade );
$upgrade_api->queue[] = [ 'GET', '/templates/' . $legacy_record['id'], reply( $legacy_record ) ];
error_is( $upgrade->activate( $with_notes, [ 'actor_user_id' => 7 ] ), 'not_synced' );
$upgraded_record = array_merge( $legacy_record, $promoted, [ 'revision' => 2 ] );
$upgrade_api->queue[] = [ 'PUT', '/templates/' . $legacy_record['id'], reply( $upgraded_record ) ];
$upgrade_result = $upgrade->synchronize( $with_notes );
check( ! is_wp_error( $upgrade_result ) && 'synced_draft' === $upgrade_result['status'] && 3 === $upgrade_result['template']['definition']['version'], 'Explicit sync upgrades the same local revision instead of returning cached V1.' );
check( $upgrade_api->calls[1][2] === $promoted && $upgrade_api->calls[1][3] === [ 'If-Match' => '"1"' ], 'Upgrade uses exact V3 wire payload and existing ETag.' );
check( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( $legacy_snapshot, $site ) === $legacy_profile, 'Upgrade leaves historical V1 approval untouched.' );
check( is_wp_error( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => array_intersect_key( $upgraded_record, $frozen_legacy ) ], $site ) ), 'New V3 revision requires its own approval.' );
echo "PASS automatic V1/V3 selection, conditional upgrade and historical V1 retention\n";
