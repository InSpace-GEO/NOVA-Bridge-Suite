<?php
/** V3 create/update/profile retention over the existing conditional setup transport. */
require __DIR__ . '/mapping-sync-unit.php';
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
$v3 = $original; $v3['reference_id'] = 91; $v3['revision'] = 'destination-1'; $v3['catalog_mode'] = 'destination';
$v3['fields']['/title'] = [ 'mode' => 'adapt', 'source_path' => '', 'required' => false, 'instructions' => 'Choose the introductory heading.',
    'description' => [ 'label' => 'Hero heading', 'purpose' => 'Main heading above the product grid.', 'value_type' => 'text', 'constraints' => [ 'max_length' => 80 ] ] ];
$v3input = Nova_Bridge_Suite_Writing_Adapter::template_input( $v3 );
check( ! is_wp_error( $v3input ), 'V3 request serializes.' );
$v3record = array_merge( $v3input, [ 'id' => '99999999-9999-4999-8999-999999999999', 'revision' => 1, 'retired_at' => null, 'created_at' => '2026-10-26T12:00:00Z', 'updated_at' => '2026-10-26T12:00:00Z' ] );
$v3client = new QueueClient( $connection ); $v3client->queue[] = [ 'POST', '/templates', reply( $v3record, 201 ) ];
$v3sync = new Nova_Bridge_Suite_Mapping_Sync( $v3client ); $v3result = $v3sync->synchronize( $v3 );
check( ! is_wp_error( $v3result ) && 'v3_destination_fitting' === $v3result['instructions_sync'], 'V3 sync explicitly reports remote instruction fitting.' );
check( $v3client->calls[0][2] === $v3input, 'POST carries exact V3 instructions and purposes without native addresses.' );
check( ! Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'Saving a template alone does not enable native V3 discovery.' );
Nova_Bridge_Suite_Mapped_Writer::$blocked = false;
$v3client->queue[] = [ 'GET', '/templates/' . $v3record['id'], reply( $v3record ) ];
$approved = $v3sync->activate( $v3, [ 'actor_user_id' => 7 ] );
check( ! is_wp_error( $approved ) && Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'Exact native profile approval enables V3 discovery for this site.' );
$enabled_key = 'nova_posting_v3_' . hash( 'sha256', $site );
// A later WordPress request reads a persisted boolean option as the string "1".
$wpdb->rows[$enabled_key] = maybe_serialize( '1' );
check( get_option( $enabled_key ) === '1' && Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'V3 discovery stays enabled after a persisted WordPress option reload.' );
foreach ( [ true, 1, '1' ] as $enabled ) { update_option( $enabled_key, $enabled ); check( Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'Only supported WordPress true values enable discovery.' ); }
foreach ( [ false, 0, '0', '', 'false', 'true', 'yes', 2, [], [ 1 ] ] as $disabled ) { update_option( $enabled_key, $disabled ); check( ! Nova_Bridge_Suite_Mapping_Sync::destination_enabled( $site ), 'Unrecognized or disabled option values cannot enable discovery.' ); }
update_option( $enabled_key, '1' );
$frozen = array_intersect_key( $v3record, array_flip( [ 'id', 'name', 'page_type', 'revision', 'definition', 'mapping' ] ) );
check( ! is_wp_error( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) ), 'A frozen V3 template resolves to its immutable local profile.' );
$v3['revision'] = 'destination-2'; $v3['guidance'] = 'Prefer category introductory content.';
$v3record2 = array_merge( $v3record, Nova_Bridge_Suite_Writing_Adapter::template_input( $v3 ), [ 'revision' => 2 ] );
$v3client->queue[] = [ 'PUT', '/templates/' . $v3record['id'], reply( $v3record2 ) ];
$updated = $v3sync->synchronize( $v3 );
check( ! is_wp_error( $updated ) && [ 'If-Match' => '"1"' ] === $v3client->calls[2][3], 'Instruction changes use a conditional template revision update.' );
check( ! is_wp_error( Nova_Bridge_Suite_Mapping_Sync::configuration_for_snapshot( [ 'site_id' => $site, 'configuration' => $frozen ], $site ) ), 'A later instruction revision does not rewrite an approved historical profile.' );
echo "PASS V3 synchronization and exact revision retention\n";
