<?php
/** Core category content and schema-owned nested ACF values share one reviewed mapping. */
if ( in_array( '--provider-unavailable', $argv ?? [], true ) ) {
    define( 'ABSPATH', __DIR__ );
    class WP_Error {
        private $data;
        function __construct( $code, $message, $data = [] ) { $this->data = $data; }
        function get_error_data() { return $this->data; }
    }
    class Nova_Bridge_Suite_Content_Transport {}
    function is_wp_error( $value ) { return $value instanceof WP_Error; }
    require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-acf.php';
    $adapter = new Nova_Bridge_Suite_Recipe_ACF();
    $result = $adapter->validate_blueprint( [ 'document' => [], 'schema' => [], 'post_type' => '', 'taxonomy' => 'product_cat' ] );
    if ( ! is_wp_error( $result ) || ( $result->get_error_data()['status'] ?? 0 ) !== 503 ) { throw new RuntimeException( 'Removing the ACF provider bypassed taxonomy template validation.' ); }
    echo "PASS 1 unavailable ACF category provider check.\n";
    exit;
}

require __DIR__ . '/layout-recipes-api-unit.php';
$baseline_checks = $checks;
$before_native = $writes;
$before_field_writes = $acf_writes;
$baseline_schema = $acf_schema;
$baseline_values = $acf_values;
$acf_schema = [
    'extra_content' => af( 'extra_content', 'wysiwyg', [ 'label' => 'Additional category content' ] ),
    'hero' => af( 'hero', 'group', [ 'sub_fields' => [ af( 'heading' ), af( 'enabled', 'true_false' ) ] ] ),
    'faqs' => af( 'faqs', 'repeater', [ 'max' => 4, 'sub_fields' => [ af( 'question' ), af( 'answer', 'wysiwyg' ) ] ] ),
    'matrix' => af( 'matrix', 'flexible_content', [ 'layouts' => [
        [ 'key' => 'layout_category_copy', 'name' => 'copy', 'label' => 'Copy', 'sub_fields' => [ af( 'heading' ), af( 'body', 'wysiwyg' ) ] ],
        [ 'key' => 'layout_category_cta', 'name' => 'cta', 'label' => 'CTA', 'sub_fields' => [ af( 'label' ), af( 'link', 'link' ) ] ],
    ] ] ),
];
$acf_values['product_cat_31'] = [
    $acf_schema['extra_content']['key'] => '<p>Actual separate category content.</p>',
    $acf_schema['hero']['key'] => [ 'heading' => 'Actual category banner', 'enabled' => true ],
    $acf_schema['faqs']['key'] => [ [ 'question' => 'Existing FAQ?', 'answer' => '<p>Existing answer.</p>' ] ],
    $acf_schema['matrix']['key'] => [ [ 'acf_fc_layout' => 'copy', 'heading' => 'Existing nested heading', 'body' => '<p>Existing nested content.</p>' ] ],
];
$category = design( 'core', 'static', 'taxonomy:product_cat', 31 );
check( isset( $category['custom_fields_design'] ), 'Core category capture omitted its installed ACF companion.' );
$custom = $category['custom_fields_design'];
check( $custom['builder'] === 'acf' && $custom['blueprint']['taxonomy'] === 'product_cat' && $custom['blueprint']['post_type'] === '', 'The ACF companion belongs to a post rather than the category screen.' );
check( $custom['blueprint']['document']['extra_content'] === '<p>Actual separate category content.</p>' && $custom['blueprint']['document']['hero']['enabled'] === true, 'Category ACF values used the wrong object identity or lost native types.' );
check( in_array( '/document/faqs/0/answer', array_column( $custom['fields'], 'target' ), true ) && in_array( '/document/matrix/0/body', array_column( $custom['fields'], 'target' ), true ), 'Declared nested category fields were absent from the selectable inventory.' );
$blank_category = design( 'core', 'mixed', 'taxonomy:product_cat' );
check( isset( $blank_category['custom_fields_design']['blueprint']['taxonomy'] ) && $blank_category['custom_fields_design']['blueprint']['document']['extra_content'] === '', 'New categories omitted their declared blank ACF fields.' );
$manual = data( route( 'design', 'POST', [], [ 'builder' => 'core', 'mode' => 'mixed', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => 31, 'manual_selection' => true ] ) );
check( $manual['recipe_preset']['bindings'] === [] && $manual['custom_fields_design']['recipe_preset']['bindings'] === [] && $manual['custom_fields_design']['recipe_preset']['regions'] === [], 'Manual category setup preselected schema fields before the administrator chose them.' );
check( $manual['custom_fields_design']['sample'] instanceof stdClass && count( $manual['custom_fields_design']['fields'] ) >= 7, 'Manual category setup discarded available fields or emitted an array in place of empty content.' );

$before_reads = $acf_reads;
$denied_caps = [ 'edit_term_meta' ];
rejected( route( 'design', 'POST', [], [ 'builder' => 'core', 'mode' => 'static', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => 31 ] ), 403, 'Denied ACF category metadata was silently discarded instead of rejecting incomplete capture.' );
check( $acf_reads === $before_reads, 'Category ACF values were read before term metadata permissions.' );
$denied_caps = [];
$acf_example_only = true;
rejected( route( 'design', 'POST', [], [ 'builder' => 'core', 'mode' => 'mixed', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => 31 ] ), 409, 'Category-only example location rules silently removed the ACF companion.' );
$acf_example_only = false;

// One category mapping contains an independent native description plus variable custom rows.
$custom_dynamic = design( 'acf', 'mixed', 'taxonomy:product_cat', 31 );
$recipe = $category['recipe_preset'];
$recipe['custom_fields'] = $custom_dynamic['recipe_preset'];
$content = array_replace_recursive( $category['sample'], $custom_dynamic['sample'] );
foreach ( $recipe['bindings'] as $binding ) {
    if ( '/document/title' === $binding['target'] ) { Nova_Bridge_Suite_Recipe_Engine::put( $content, $binding['source'], 'New category title' ); }
    if ( '/document/sections/0/body' === $binding['target'] ) { Nova_Bridge_Suite_Recipe_Engine::put( $content, $binding['source'], '<p>New native top description.</p>' ); }
}
$content['acf']['extra_content'] = '<p>New custom category content.</p>';
$content['acf']['hero']['heading'] = 'New category banner';
$content['acf']['faqs'] = [ [ 'question' => 'First new FAQ?', 'answer' => '<p>First answer.</p>' ], [ 'question' => 'Second new FAQ?', 'answer' => '<p>Second answer.</p>' ] ];
$content['acf']['matrix'] = [
    [ 'layout' => 'cta', 'label' => 'See options', 'link' => [ 'url' => 'https://example.com/options', 'title' => 'View options' ] ],
    [ 'layout' => 'copy', 'heading' => 'Additional copy', 'body' => '<p>Additional body.</p>' ],
];
$save = save_body( $category, $recipe, $content, 'taxonomy:product_cat', null, 0, $custom_dynamic['seal'] );
$saved = data( route( 'save', 'POST', [], $save ) );
$translated = data( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1, 'content' => $content ] ) );
check( $translated['writer_payload']['name'] === 'New category title' && $translated['writer_payload']['description'] === '<p>New native top description.</p>', 'Category ACF composition replaced or combined the independent native top description.' );
check( $translated['writer_payload']['meta_all']['acf']['extra_content'] === '<p>New custom category content.</p>' && $translated['writer_payload']['meta_all']['acf']['hero']['enabled'] === true, 'Category custom values lost their dedicated payload or a typed group sibling.' );
check( count( $translated['custom_native_document']['faqs'] ) === 2 && $translated['custom_native_document']['faqs'][1]['question'] === 'Second new FAQ?', 'Category nested row quantities were flattened into a fixed template.' );
check( array_column( $translated['custom_native_document']['matrix'], 'acf_fc_layout' ) === [ 'cta', 'copy' ] && $translated['custom_native_document']['matrix'][0]['link']['url'] === 'https://example.com/options', 'Category flexible variants or nested links lost their exact schema.' );
check( isset( $translated['content_schema']['properties']['acf']['properties']['matrix'], $translated['content_schema']['properties']['title'] ), 'Category composition lost the primary or custom content schema.' );
$profile = data( route( 'profile', 'GET', [ 'id' => $saved['id'] ] ) );
check( $profile['custom_fields_design']['blueprint'] === $custom_dynamic['blueprint'] && $profile['recipe']['custom_fields'] === $recipe['custom_fields'], 'Reloading the category mapping dropped its schema-owned companion.' );
$empty_rows = $content; $empty_rows['acf']['faqs'] = [];
$empty_result = data( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1, 'content' => $empty_rows ] ) );
check( $empty_result['custom_native_document']['faqs'] === [] && $empty_result['writer_payload']['description'] === '<p>New native top description.</p>', 'An empty optional category repeater erased the independent top content.' );
$unknown_variant = $content; $unknown_variant['acf']['matrix'][0]['layout'] = 'made_up';
rejected( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1, 'content' => $unknown_variant ] ), 422, 'An undeclared category layout was accepted.' );
$page_custom = design( 'acf', 'mixed', 'post_type:page' );
$wrong_destination = $recipe; $wrong_destination['custom_fields'] = $page_custom['recipe_preset'];
$wrong_save = save_body( $category, $wrong_destination, $content, 'taxonomy:product_cat', null, 0, $page_custom['seal'] );
rejected( route( 'save', 'POST', [], $wrong_save ), 409, 'A separately sealed page schema was saved as a category companion.' );
$acf_schema['extra_content']['label'] = 'Changed installed label';
rejected( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1, 'content' => $content ] ), 409, 'A changed installed category schema was bypassed.' );
$acf_schema = [];
rejected( route( 'translate', 'POST', [], [ 'id' => $saved['id'], 'revision' => 1, 'content' => $content ] ), 409, 'Removing the category schema silently discarded custom values.' );
$no_custom = design( 'core', 'static', 'taxonomy:product_cat', 31 );
check( ! isset( $no_custom['custom_fields_design'] ), 'A destination without a schema acquired a false custom-fields requirement.' );
rejected( route( 'design', 'POST', [], [ 'builder' => 'core', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => 31, 'declared_term_fields' => [ [ 'type' => 'string' ] ] ] ), 422, 'Local custom declarations accepted a list without field identities.' );
rejected( route( 'design', 'POST', [], [ 'builder' => 'core', 'resource_id' => 'post_type:page', 'reference_id' => 11, 'declared_term_fields' => [ 'body' => [ 'type' => 'string' ] ] ] ), 422, 'Category-local field declarations changed a post design.' );
rejected( route( 'design', 'POST', [], [ 'builder' => 'core', 'resource_id' => 'taxonomy:product_cat', 'reference_id' => 31, 'declared_term_fields' => [], 'existing_blueprint' => $category['blueprint'], 'existing_seal' => $category['seal'] ] ), 409, 'Additional fields were confirmed without the original verified example identity.' );

// Empty registered collections use their declared prototype, never an invented row shape.
$empty_native = [ 'document' => [ 'title' => 'Category', 'sections' => [], 'faqs' => [], 'custom_rows' => [] ], 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'resource_id' => 'taxonomy:product_cat' ];
$declared_container = [ 'target' => '/document/custom_rows', 'label' => 'Custom category rows', 'items' => [], 'required' => true, 'min_items' => 1, 'max_items' => 4, 'schema_prototype' => [ [ 'title' => '', 'url' => '' ] ], 'schema_fields' => [
    [ 'target' => '/0/title', 'label' => 'Row heading', 'kind' => 'heading', 'format' => 'text', 'writable' => true ],
    [ 'target' => '/0/url', 'label' => 'Row URL', 'kind' => 'url', 'format' => 'url', 'writable' => true ],
], 'schema_containers' => [] ];
$empty_description = [ 'fields' => [], 'containers' => [ $declared_container ] ];
$empty_request = [ 'target' => '/document/custom_rows', 'start' => 0, 'count' => 0, 'source' => '/sections' ];
$region_method = new ReflectionMethod( Nova_Bridge_Suite_Layout_Recipes::class, 'region_value' );
$scope_method = new ReflectionMethod( Nova_Bridge_Suite_Layout_Recipes::class, 'check_scope' );
if ( PHP_VERSION_ID < 80100 ) { $region_method->setAccessible( true ); $scope_method->setAccessible( true ); }
$empty_region = $region_method->invoke( null, $empty_native, $empty_description, $empty_request, 'core' );
check( is_array( $empty_region ) && $empty_region['region']['count'] === 0 && $empty_region['region']['prototype']['native'] === $declared_container['schema_prototype'], 'An empty declared collection could not prepare its schema-owned native row.' );
check( ! isset( $empty_region['region']['prototype_start'], $empty_region['region']['prototype_count'] ) && count( $empty_region['fields'] ) === 2 && count( $empty_region['region']['prototype']['bindings'] ) === 2, 'Empty collection rules claimed a sampled row or discarded its declared controls.' );
check( $empty_region['collection_limits'] === [ 'required' => true, 'min_items' => 1, 'max_items' => 4 ], 'The region UI did not receive declared collection bounds.' );
check( true === $scope_method->invoke( null, $empty_native, $empty_description, [], [ $empty_region['region'] ], 'core' ), 'The reviewed API rejected its own exact empty schema prototype.' );
$changed_prototype = $empty_region['region']; $changed_prototype['prototype']['native'][0]['url'] = 'https://untrusted.example/';
rejected( $scope_method->invoke( null, $empty_native, $empty_description, [], [ $changed_prototype ], 'core' ), 409, 'A changed empty-list prototype bypassed declared schema authority.' );
$missing_schema = $empty_description; unset( $missing_schema['containers'][0]['schema_prototype'] );
rejected( $region_method->invoke( null, $empty_native, $missing_schema, $empty_request, 'core' ), 409, 'An undeclared empty collection invented a native prototype.' );
rejected( $region_method->invoke( null, $empty_native, $empty_description, $empty_request, 'gutenberg' ), 409, 'A native builder accepted an empty source range without actual example nodes.' );
$sampled_empty = $empty_request; $sampled_empty['prototype_start'] = 0; $sampled_empty['prototype_count'] = 1;
rejected( $region_method->invoke( null, $empty_native, $empty_description, $sampled_empty, 'core' ), 409, 'An empty collection asserted a nonexistent sampled row.' );
$nonempty_native = $empty_native; $nonempty_native['document']['custom_rows'] = [ [ 'title' => 'Existing', 'url' => 'https://example.com/' ] ];
rejected( $region_method->invoke( null, $nonempty_native, $empty_description, $empty_request, 'core' ), 409, 'An empty schema rule was used to bypass actual existing native rows.' );
$empty_recipe = [ 'format' => Nova_Bridge_Suite_Recipe_Engine::FORMAT, 'builder' => 'core', 'mode' => 'rules', 'blueprint' => $empty_native, 'bindings' => [], 'regions' => [ $empty_region['region'] ], 'instructions' => '' ];
$empty_compile = Nova_Bridge_Suite_Recipe_Engine::compile( $empty_recipe, [ 'sections' => [ [ 'title' => 'First row', 'url' => 'https://example.com/first' ], [ 'title' => 'Second row', 'url' => 'https://example.com/second' ] ] ], 'empty-declared-category-rows' );
check( ! is_wp_error( $empty_compile ) && count( $empty_compile['native_document']['custom_rows'] ) === 2 && $empty_compile['native_document']['custom_rows'][1]['title'] === 'Second row', 'The real engine could not instantiate variable rows into an empty declared collection.' );
check( $writes === $before_native && $acf_writes === $before_field_writes, 'Capturing, saving or translating category mappings published native category/custom-field changes.' );
$acf_schema = $baseline_schema; $acf_values = $baseline_values;
echo 'PASS ' . ( $checks - $baseline_checks ) . " category ACF integration checks (plus the registered API baseline).\n";
