<?php
/** Local descriptions and explicit simulated fitted values; no backend or CMS mutations. */
if ( defined( 'ABSPATH' ) ) { throw new RuntimeException( 'Run standalone only.' ); }
define( 'ABSPATH', __DIR__ . '/' );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message = '', $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function wp_safe_remote_request( ...$args ) { throw new RuntimeException( 'Descriptions must not make backend requests.' ); }
function wp_remote_request( ...$args ) { return wp_safe_remote_request( ...$args ); }
function wp_insert_post( ...$args ) { throw new RuntimeException( 'Description simulation must not mutate native content.' ); }
function wp_update_post( ...$args ) { return wp_insert_post( ...$args ); }
function update_post_meta( ...$args ) { return wp_insert_post( ...$args ); }
require_once dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-protocol.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-posting-client.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapping-sync.php';
$checks = 0;
function destination_check( $condition, string $message ): void { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function destination_error( $value, string $message ): void { destination_check( is_wp_error( $value ), $message ); }
function destination_field( string $label, string $type = 'text', array $constraints = [], bool $required = false ): array { return [ 'mode' => 'adapt', 'source_path' => '', 'required' => $required, 'instructions' => 'Fit this value to the existing layout.', 'description' => [ 'label' => $label, 'purpose' => 'A relevant destination value for this page.', 'value_type' => $type, 'constraints' => $constraints ] ]; }
$draft = [ 'schema_version' => 1, 'revision' => 'destination-revision-1', 'reference_type' => 'post', 'reference_id' => 10, 'signature' => 'layout-1', 'catalog_mode' => 'destination', 'template' => [ 'id' => 'local-destination', 'revision' => '1' ], 'profile_page_type' => 'service', 'label' => 'Existing service layout', 'guidance_mode' => 'set', 'guidance' => 'Exactly one relevant label per page. Keep all existing repeat rows.', 'routing' => [ 'operation' => 'update', 'publication' => 'draft' ], 'skipped_sources' => [], 'repeat_slots' => [], 'destination_groups' => [], 'fields' => [ '/badge' => destination_field( 'Relevant page label', 'text', [ 'max_length' => 4 ], true ), '/url' => destination_field( 'Relevant URL', 'url' ), '/email' => destination_field( 'Contact email', 'email' ), '/protected' => [ 'mode' => 'protected', 'source_path' => '', 'instructions' => 'Retain exact source bytes.' ], '/empty' => [ 'mode' => 'leave_empty', 'source_path' => '', 'instructions' => '' ] ], 'target_descriptors' => [] ];
$group_id = '11111111-1111-4111-8111-111111111111'; $slots = []; $values = [];
for ( $index = 0; $index < 3; ++$index ) {
    $title = '/meta_all/steps_' . $index . '_title'; $body = '/meta_all/steps_' . $index . '_body';
    $draft['fields'][ $title ] = destination_field( 'Step title ' . ( $index + 1 ), 'text', [ 'max_length' => 30 ], true );
    $draft['fields'][ $body ] = destination_field( 'Step description ' . ( $index + 1 ), 'rich_text', [ 'max_length' => 10 ] );
    $slots[] = [ 'id' => '22222222-2222-4222-8222-22222222222' . $index, 'ordinal' => $index, 'fields' => [ $title, $body ] ];
    $values[ Nova_Bridge_Suite_Writing_Adapter::field_id( $title ) ] = 'Distinct step ' . ( $index + 1 );
}
$draft['destination_groups'][] = [ 'id' => $group_id, 'label' => 'Existing steps', 'slots' => $slots ];
foreach ( $draft['fields'] as $path => $field ) { $draft['target_descriptors'][ $path ] = [ 'path' => $path, 'reference_id' => 10, 'signature' => 'layout-1' ]; }
$draft['target_descriptors']['/badge']['element'] = 'Existing badge widget'; $draft['target_descriptors']['/badge']['logical_field'] = 'label';
$original = $draft;
$export = Nova_Bridge_Suite_Writing_Adapter::destination_description( $draft );
destination_check( ! is_wp_error( $export ) && $export['format'] === 'nova-template-description/v1', 'Descriptions export a local generation description, not a posting API payload.' );
destination_check( Nova_Bridge_Suite_Writing_Adapter::is_destination_draft( $draft ) && $draft === $original, 'Destination detection/export do not rewrite local descriptions or native bindings.' );
$fields = array_column( $export['fields'], null, 'id' ); $badge_id = Nova_Bridge_Suite_Writing_Adapter::field_id( '/badge' );
destination_check( count( $fields ) === 9 && ! isset( $fields[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/protected' ) ] ) && ! isset( $fields[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/empty' ) ] ), 'Protected and Leave empty native targets are absent from generated destination fields.' );
destination_check( $fields[ $badge_id ]['label'] === 'Relevant page label' && $fields[ $badge_id ]['purpose'] === $draft['fields']['/badge']['description']['purpose'] && $fields[ $badge_id ]['instructions'] === $draft['fields']['/badge']['instructions'] && $fields[ $badge_id ]['constraints'] === [ 'max_length' => 4 ], 'Label, purpose, fallback instructions and structured limits remain separate.' );
destination_check( $export['instructions'] === [ 'mode' => 'set', 'text' => $draft['guidance'] ], 'Template fallback rules remain explicit in the description.' );
destination_check( false === strpos( json_encode( $export ), 'reference_id' ) && false === strpos( json_encode( $export ), '/meta_all/' ) && false === strpos( json_encode( $export ), 'source_field' ), 'Backend-neutral descriptions contain neither native addressing nor guessed stock source mappings.' );
destination_check( $export['groups'][0]['id'] === $group_id && $export['groups'][0]['capacity'] === 3 && count( $export['groups'][0]['slots'] ) === 3, 'Group identity and capacity come from exactly three existing slots.' );
foreach ( $slots as $index => $slot ) { destination_check( $export['groups'][0]['slots'][ $index ] === [ 'id' => $slot['id'], 'ordinal' => $index, 'field_ids' => array_map( [ 'Nova_Bridge_Suite_Writing_Adapter', 'field_id' ], $slot['fields'] ) ], 'Existing slot identity/order points to stable destination IDs.' ); }
$values[ $badge_id ] = 'éééé'; $values[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/url' ) ] = 'https://example.test/relevant/'; $values[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/email' ) ] = 'test@example.test';
$body_id = Nova_Bridge_Suite_Writing_Adapter::field_id( '/meta_all/steps_0_body' ); $values[ $body_id ] = '<p>A &amp; B</p>';
$simulation = Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $values );
destination_check( ! is_wp_error( $simulation ) && $simulation['/badge'] === 'éééé' && $simulation['/meta_all/steps_0_body'] === '<p>A &amp; B</p>' && $simulation['/meta_all/steps_2_title'] === 'Distinct step 3', 'Explicit simulated fitted values validate and resolve to native paths: ' . ( is_wp_error( $simulation ) ? $simulation->get_error_message() : 'unexpected result' ) );
destination_check( $draft === $original && count( $simulation ) === 7, 'Simulation preserves native profiles, optional unfilled destinations and protected targets.' );
$bad = $values; $bad[ $badge_id ] = 'ééééé'; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'Length rules count Unicode characters.' );
$bad = $values; $bad[ $body_id ] = '<p>12345678901</p>'; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'Rich-text limits count visible text after markup/entities.' );
$bad = $values; $bad[ 'field_unknown' ] = 'Extra fourth row'; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'Unknown destination IDs cannot create extra native rows.' );
$bad = $values; unset( $bad[ $badge_id ] ); destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'Missing required destinations fail validation.' );
$bad = $values; $bad[ $badge_id ] = null; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'Required null destinations fail validation.' );
$optional = $values; $optional[ $body_id ] = null; destination_check( ! isset( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $optional )['/meta_all/steps_0_body'] ), 'Optional null skips native replacement.' );
$empty = $values; $empty[ $badge_id ] = ''; destination_check( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $empty )['/badge'] === '', 'An explicit empty scalar remains distinct from omission.' );
foreach ( [ '/url' => 'javascript:alert(1)', '/email' => 'invalid email' ] as $path => $bad_value ) { $bad = $values; $bad[ Nova_Bridge_Suite_Writing_Adapter::field_id( $path ) ] = $bad_value; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $draft, $bad ), 'URL/email destination types reject malformed values.' ); }
foreach ( [ 'image', 'list', 'link' ] as $type ) {
    $compound = $draft; $compound['fields']['/compound'] = destination_field( 'Described compound destination', $type, 'list' === $type ? [ 'min_items' => 1, 'max_items' => 3 ] : [] ); $compound['target_descriptors']['/compound'] = [ 'path' => '/compound' ];
    $compound_export = Nova_Bridge_Suite_Writing_Adapter::destination_description( $compound ); destination_check( ! is_wp_error( $compound_export ), 'Compound destination descriptions can be prepared without asserting native write support.' );
    $bad = $values; $bad[ Nova_Bridge_Suite_Writing_Adapter::field_id( '/compound' ) ] = [ 'unverified' ]; destination_error( Nova_Bridge_Suite_Writing_Adapter::validate_fitted_values( $compound, $bad ), 'Supplied compound values require a verified native adapter.' );
}
$bad = $draft; $bad['fields']['/badge']['description']['constraints']['max_length'] = '4'; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Malformed structured limits fail before export.' );
$bad = $draft; $bad['fields']['/stock'] = [ 'mode' => 'mapped', 'source_path' => 'h1', 'instructions' => '' ]; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Destination exports do not silently combine fitted and stock-source mappings.' );
$bad = $draft; $bad['repeat_slots']['legacy'] = [ [ 'id' => 'retained-old-slot' ] ]; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Historical repeat mappings require explicit review before a destination export.' );
$bad = $draft; $bad['destination_groups'][0]['slots'][1]['id'] = $bad['destination_groups'][0]['slots'][0]['id']; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Duplicate existing slot identities fail description export.' );
$bad = $draft; $bad['destination_groups'][0]['slots'][1]['ordinal'] = 0; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Reordered slot ordinals fail description export.' );
$bad = $draft; $bad['destination_groups'][0]['slots'][0]['fields'][] = '/protected'; destination_error( Nova_Bridge_Suite_Writing_Adapter::destination_description( $bad ), 'Protected fields cannot enter a generated repeat slot.' );
$stock = $draft; $stock['catalog_mode'] = 'nova'; $stock['template'] = [ 'id' => 'nova-delivery-fields-v1', 'revision' => '1' ]; $stock['fields']['/stock'] = [ 'mode' => 'mapped', 'source_path' => 'h1', 'instructions' => '' ]; $stock['target_descriptors']['/stock'] = [ 'path' => '/stock' ];
destination_error( Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ), 'Destination-only profiles cannot become stock publishing templates.' );
destination_error( Nova_Bridge_Suite_Writing_Adapter::template_input( $stock ), 'Mixed fitted/stock profiles cannot silently sync a stock subset.' );
$client = new Nova_Bridge_Suite_Posting_Client( [ 'base_url' => 'https://posting.example', 'site_id' => '33333333-3333-4333-8333-333333333333', 'token' => 'test-token-not-a-credential' ] );
$service = new Nova_Bridge_Suite_Mapping_Sync( $client );
destination_error( $service->synchronize( $draft ), 'Destination synchronization is blocked before HTTP or SQL state changes.' );
destination_error( $service->activate( $draft ), 'Destination activation is blocked before HTTP or SQL state changes.' );
echo 'PASS ' . $checks . " destination-description and local simulation assertions\n";
