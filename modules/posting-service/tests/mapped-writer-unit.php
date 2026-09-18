<?php
define( 'ABSPATH', __DIR__ );
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function is_serialized( $value ) { return is_string( $value ) && preg_match( '/^(?:a|O|s|i|b|d):/', $value ); }
function wp_kses( $value, $tags, $protocols ) { return strip_tags( $value, '<' . implode( '><', array_keys( $tags ) ) . '>' ); }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-mapped-writer.php';
require __DIR__ . '/mapped-writer-fixture.php';
$checks = 0;
function check( $condition, string $message ): void { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function plan_fixture( array $fixture ): array { return Nova_Bridge_Suite_Mapped_Writer::build_plan( $fixture['content'], $fixture['configuration'], $fixture['context'], $fixture['snapshot'], $fixture['inventory'] ); }
function rejects( string $reason, callable $operation ): void { try { $operation(); } catch ( Nova_Bridge_Suite_Writer_Failure $error ) { check( $error->reason === $reason, 'Expected ' . $reason . ', got ' . $error->reason ); return; } throw new RuntimeException( 'Expected rejection: ' . $reason ); }
function add_meta( array &$fixture, string $key, string $value ): void { $fixture['snapshot']['meta'][] = [ 'meta_id' => (string) ( 100 + count( $fixture['snapshot']['meta'] ) ), 'post_id' => '7', 'meta_key' => $key, 'meta_value' => $value ]; }
function bind_heading( array &$fixture, string $path, array $live ): void {
    $local = &$fixture['configuration']['local']; unset( $local['fields']['/title'] );
    $local['fields'][ $path ] = [ 'mode' => 'mapped', 'source_path' => 'heading' ];
    $descriptor = array_intersect_key( $live, array_flip( [ 'path', 'transport', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'selector_data' ] ) );
    $local['target_descriptors'][ $path ] = $descriptor;
    $fixture['inventory'][ $path ] = $live;
    $fixture['configuration']['mapping']['bindings'][0]['target_descriptor']['target'] = $descriptor;
    nova_writer_fixture_policy( $fixture['configuration'] );
}
$fixture = nova_writer_fixture(); $plan = plan_fixture( $fixture );
check( $plan['changes']['post']['post_title'] === 'Generated fixture heading', 'Native title mapped.' );
check( ! isset( $plan['changes']['post']['post_content'] ), 'Leave empty skips updates.' );
check( ! isset( $plan['changes']['post']['post_excerpt'] ), 'Protected native excerpt is absent from writes.' );
check( count( $plan['protected'] ) === 1, 'Canonical protected slot resolved.' );
$clone = nova_writer_fixture( 7, 'fixture-layout', 'clone' ); $clone_plan = plan_fixture( $clone );
check( $clone_plan['changes']['post']['post_content'] === '' && $clone_plan['desired_status'] === 'draft', 'Clone explicitly blanks only Leave empty.' );
$bad = $fixture; $bad['content']['digest'] = str_repeat( 'b', 64 ); rejects( 'pin_mismatch', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['template_version'] = '999'; rejects( 'template_mismatch', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['configuration']['local']['signature'] = 'drift'; rejects( 'layout_drift', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['configuration']['local']['routing']['publication'] = 'publish'; rejects( 'policy', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['fields']['retained_note'] = 'override'; rejects( 'unknown_value', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['fields']['heading'] = '<script>bad</script>'; rejects( 'html_policy', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['fields']['heading'] = ['wrong']; rejects( 'value_type', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['fields']['heading'] = str_repeat( 'é', 201 ); rejects( 'value_length', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; $bad['content']['fields']['slug'] = 'bad/slug'; rejects( 'slug', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; unset( $bad['content']['fields']['heading'] ); rejects( 'missing_value', function () use ( $bad ) { plan_fixture( $bad ); } );
$acf = $fixture;
add_meta( $acf, 'matrix', 'a:1:{i:0;s:4:"copy";}' ); add_meta( $acf, '_matrix', 'field_matrix' );
add_meta( $acf, 'matrix_0_heading', 'Existing heading' ); add_meta( $acf, '_matrix_0_heading', 'field_heading' );
add_meta( $acf, 'matrix_0_protected', 'Keep these exact bytes' ); add_meta( $acf, '_matrix_0_protected', 'field_protected' );
bind_heading( $acf, '/meta_all/matrix_0_heading', [ 'path' => '/meta_all/matrix_0_heading', 'writable' => true, 'type' => 'text', 'acf_key' => 'field_heading', 'write_mode' => 'existing_leaf', 'transport' => 'wordpress_meta_all' ] );
$acf_plan = plan_fixture( $acf );
check( $acf_plan['changes']['meta'] === [ 102 => 'Generated fixture heading' ], 'Nested ACF uses one exact existing physical row.' );
check( $acf_plan['uses_acf'] && count( $acf_plan['snapshot']['meta'] ) === 6, 'Complete native snapshot includes parent counters, references and siblings.' );
$bad = $acf; $bad['snapshot']['meta'][3]['meta_value'] = 'field_other'; rejects( 'acf_reference', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $acf; add_meta( $bad, 'matrix_0_heading', 'duplicate' ); rejects( 'physical_identity', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $acf; $bad['inventory']['/meta_all/matrix_0_heading']['write_mode'] = 'complete_parent'; $bad['configuration']['local']['target_descriptors']['/meta_all/matrix_0_heading']['write_mode'] = 'complete_parent'; $bad['configuration']['mapping']['bindings'][0]['target_descriptor']['target']['write_mode'] = 'complete_parent'; nova_writer_fixture_policy( $bad['configuration'] ); rejects( 'complete_parent', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $acf;
$bad['configuration']['local']['fields']['/meta_all/matrix'] = [ 'mode' => 'protected' ];
$bad['configuration']['local']['target_descriptors']['/meta_all/matrix'] = [ 'path' => '/meta_all/matrix', 'acf_key' => 'field_matrix' ];
$bad['inventory']['/meta_all/matrix'] = [ 'path' => '/meta_all/matrix', 'acf_key' => 'field_matrix', 'type' => 'flexible_content' ]; nova_writer_fixture_policy( $bad['configuration'] );
rejects( 'protected_overlap', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $fixture; add_meta( $bad, '_elementor_data', '[]' ); bind_heading( $bad, '/meta_all/_elementor_data', [ 'path' => '/meta_all/_elementor_data', 'writable' => true, 'type' => 'text' ] ); rejects( 'writer_scope', function () use ( $bad ) { plan_fixture( $bad ); } );
$json = '[ {"id":"a", "settings": {"title":"old", "html":"<b>Keep \\/ \\u00e9</b>"}, "count": 2, "ok":true, "none":null} ]';
$changed = Nova_Bridge_Suite_Mapped_Writer::replace_json_scalars( $json, [ '/0/settings/title' => 'New "quoted" title' ] );
check( $changed === str_replace( '"title":"old"', '"title":"New \\"quoted\\" title"', $json ), 'Only selected JSON token bytes change.' );
rejects( 'elementor_json', function () { Nova_Bridge_Suite_Mapped_Writer::replace_json_scalars( '{"x":1,"x":2}', [ '/x' => 'new' ] ); } );
rejects( 'elementor_json', function () { Nova_Bridge_Suite_Mapped_Writer::replace_json_scalars( '{"x":{}}', [ '/x' => 'new' ] ); } );
$elementor = $fixture; add_meta( $elementor, '_elementor_data', '[{"id":"a","elType":"widget","widgetType":"heading","settings":{"title":"old"},"elements":[]}]' ); add_meta( $elementor, '_elementor_page_settings', 'a:0:{}' );
bind_heading( $elementor, '/builder/heading', [ 'path' => '/builder/heading', 'writable' => true, 'builder' => 'elementor', 'selector_data' => [ 'element_id' => 'a', 'path' => [ 'title' ] ] ] );
$elementor_plan = plan_fixture( $elementor ); check( $elementor_plan['uses_elementor'] && strpos( $elementor_plan['changes']['meta'][100], 'Generated fixture heading' ) !== false, 'Elementor resolves semantic native identity.' );
$bad = $elementor; $bad['snapshot']['meta'][0]['meta_value'] = '[{"id":"a","settings":{"title":"old"}},{"id":"a","settings":{"title":"old"}}]'; rejects( 'elementor_identity', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $elementor; $bad['snapshot']['meta'][0]['meta_value'] = '[{"id":"a","widgetType":"heading","settings":{"title":"old","__dynamic__":{"title":"tag"}}}]'; rejects( 'elementor_setting', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $elementor; $bad['configuration']['local']['fields']['/builder/other'] = [ 'mode' => 'protected' ]; $bad['configuration']['local']['target_descriptors']['/builder/other'] = [ 'path' => '/builder/other', 'builder' => 'elementor' ]; $bad['inventory']['/builder/other'] = [ 'path' => '/builder/other', 'builder' => 'elementor', 'selector_data' => [ 'element_id' => 'a', 'path' => [ 'subtitle' ] ] ]; nova_writer_fixture_policy( $bad['configuration'] ); rejects( 'protected_overlap', function () use ( $bad ) { plan_fixture( $bad ); } );
$repeat = $acf; $source = 'sections[].heading';
$repeat['configuration']['template']['fields'] = [ $repeat['configuration']['template']['fields'][1] ];
$repeat['configuration']['template']['groups'] = [ [ 'group_key' => 'sections', 'required' => true, 'minItems' => 1, 'maxItems' => 1, 'fields' => [ [ 'field_key' => 'heading', 'field_type' => 'heading', 'required' => true ] ] ] ];
unset( $repeat['configuration']['local']['fields']['/meta_all/matrix_0_heading'] );
$repeat['configuration']['local']['repeat_slots'] = [ 'sections' => [ [ 'id' => 'existing-a', 'targets' => [ 'heading' => '/meta_all/matrix_0_heading' ] ] ] ];
$binding = &$repeat['configuration']['mapping']['bindings'][0]; $binding['source_path'] = $source; $binding['target_descriptor'] = [ 'format' => 'nova_bridge_target_v1', 'slots' => [ [ 'slot_id' => 'existing-a', 'target' => $repeat['configuration']['local']['target_descriptors']['/meta_all/matrix_0_heading'] ] ] ]; unset( $binding );
unset( $repeat['content']['fields']['heading'] ); $repeat['content']['fields']['sections'] = [ [ 'heading' => 'Existing row replacement' ] ];
$repeat['context']['repeat_instances'] = [ 'sections' => [ [ 'slot_id' => 'existing-a', 'instance_id' => 'generated-instance-1' ] ] ]; nova_writer_fixture_policy( $repeat['configuration'] );
check( plan_fixture( $repeat )['changes']['meta'][102] === 'Existing row replacement', 'Fixed repeat replaces existing leaf without creating or reordering rows.' );
$bad = $repeat; unset( $bad['context']['repeat_instances'] ); rejects( 'repeat_identity', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $repeat; $bad['context']['repeat_instances']['sections'][0]['slot_id'] = 'changed'; rejects( 'repeat_identity', function () use ( $bad ) { plan_fixture( $bad ); } );
$bad = $repeat; $bad['content']['fields']['sections'][] = [ 'heading' => 'Extra row' ]; rejects( 'repeat_bounds', function () use ( $bad ) { plan_fixture( $bad ); } );
$two = $repeat;
$two['configuration']['template']['groups'][0]['minItems'] = 2; $two['configuration']['template']['groups'][0]['maxItems'] = 2;
add_meta( $two, 'matrix_1_heading', 'Second row' ); add_meta( $two, '_matrix_1_heading', 'field_heading' );
$two['inventory']['/meta_all/matrix_1_heading'] = array_merge( $two['inventory']['/meta_all/matrix_0_heading'], [ 'path' => '/meta_all/matrix_1_heading' ] );
$two['configuration']['local']['target_descriptors']['/meta_all/matrix_1_heading'] = array_merge( $two['configuration']['local']['target_descriptors']['/meta_all/matrix_0_heading'], [ 'path' => '/meta_all/matrix_1_heading' ] );
$two['configuration']['local']['repeat_slots']['sections'][] = [ 'id' => 'existing-b', 'targets' => [ 'heading' => '/meta_all/matrix_1_heading' ] ];
$two['configuration']['mapping']['bindings'][0]['target_descriptor']['slots'][] = [ 'slot_id' => 'existing-b', 'target' => $two['configuration']['local']['target_descriptors']['/meta_all/matrix_1_heading'] ];
$two['content']['fields']['sections'][] = [ 'heading' => 'Second replacement' ];
$two['context']['repeat_instances']['sections'][] = [ 'slot_id' => 'existing-b', 'instance_id' => 'generated-instance-1' ]; nova_writer_fixture_policy( $two['configuration'] );
rejects( 'repeat_identity', function () use ( $two ) { plan_fixture( $two ); } );
$two['context']['repeat_instances']['sections'][1]['instance_id'] = 'generated-instance-2';
check( count( plan_fixture( $two )['changes']['meta'] ) === 2, 'Two unique instances bind exactly two existing scalar rows.' );
echo 'PASS ' . $checks . " mapped writer assertions\n";
