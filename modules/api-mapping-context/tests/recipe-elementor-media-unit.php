<?php
/** Registered media fixtures; all WordPress reads/writes remain in the in-memory unit harness. */
require_once __DIR__ . '/recipe-engine-unit.php';
$baseline_checks = $checks;

class Recipe_Media_Test_Element {
    private $controls;
    public function __construct( array $controls ) { $this->controls = $controls; }
    public function get_controls() { return $this->controls; }
}
class Recipe_Media_Test_Plugin extends \Elementor\Plugin { public $breakpoints; }
$media_plugin = new Recipe_Media_Test_Plugin();
foreach ( [ 'elements_manager', 'widgets_manager', 'documents', 'controls_manager' ] as $property ) { $media_plugin->$property = \Elementor\Plugin::$instance->$property; }
$native_media = static function ( string $label ) { return [ 'type' => 'media', 'label' => $label ]; };
$background_controls = [ 'background_image' => $native_media( 'Background image' ) + [ 'is_responsive' => true ], 'background_image_tablet' => $native_media( 'Tablet background' ) ];
$custom_controls = [
    'hero_media' => $native_media( 'Hero photograph' ), 'secondary_media' => $native_media( 'Decoration' ),
    'photos' => [ 'type' => 'repeater', 'fields' => [
        [ 'name' => 'photo', 'type' => 'media', 'label' => 'Photograph' ],
        [ 'name' => 'nested', 'type' => 'repeater', 'fields' => [ 'portrait' => $native_media( 'Portrait' ) ] ],
    ] ],
    'video_asset' => [ 'type' => 'media', 'media_types' => [ 'video' ], 'label' => 'Video' ],
    'broken_media' => $native_media( 'Malformed' ),
];
$media_plugin->elements_manager = new class( $background_controls ) {
    private $controls;
    public function __construct( array $controls ) { $this->controls = $controls; }
    public function get_element_types( $type = null ) { return in_array( $type, [ 'container', 'section', 'column' ], true ) ? new Recipe_Media_Test_Element( $this->controls ) : null; }
};
$media_plugin->widgets_manager = new class( $custom_controls ) {
    private $controls;
    public function __construct( array $controls ) { $this->controls = $controls; }
    public function get_widget_types( $type = null ) { return 'customer-hero' === $type ? new Recipe_Media_Test_Element( $this->controls ) : null; }
};
$media_plugin->breakpoints = new class { public function get_active_devices_list() { return [ 'desktop', 'tablet', 'mobile', 'laptop' ]; } };
\Elementor\Plugin::$instance = $media_plugin;
$asset = static function ( int $id, string $name ) { return [ 'id' => $id, 'url' => 'https://example.test/' . $name . '.jpg', 'opaque' => 'unchanged' ]; };
$custom_node = widget( 'customer-hero-1', 'customer-hero', [
    'hero_media' => $asset( 61, 'hero' ), 'secondary_media' => $asset( 62, 'decoration' ),
    'photos' => [
        [ '_id' => 'row-1', 'photo' => $asset( 63, 'row-1' ), 'nested' => [ [ 'portrait' => $asset( 64, 'portrait' ) ] ] ],
        [ '_id' => 'row-2', 'photo' => $asset( 65, 'row-2' ), '__dynamic__' => [ 'photo' => 'provider-tag' ] ],
    ],
    'video_asset' => $asset( 66, 'video' ), 'unknown_media' => $asset( 67, 'unknown' ), 'broken_media' => 'provider-owned',
] );
$media_document = [ 'document' => [ container( 'background-owner', [ $custom_node ], [
    'background_image' => $asset( 41, 'desktop' ), 'background_image_mobile' => $asset( 42, 'mobile' ),
    'background_image_tablet' => $asset( 43, 'tablet' ), 'background_image_laptop' => $asset( 44, 'laptop' ),
    'background_image_invented' => $asset( 45, 'invented' ), 'padding' => [ 'top' => 40, 'unit' => 'px' ],
] ) ] ];
$media_digest = \Nova_Bridge_Suite_Recipe_Engine::digest( $media_document ); $media_writes = $writes;
$image_fields = array_column( good( $adapter->describe( $media_document ), 'Registered image discovery' )['fields'], null, 'target' );
foreach ( [ 'background_image', 'background_image_mobile', 'background_image_tablet', 'background_image_laptop' ] as $control ) {
    $target = '/document/0/settings/' . $control . '/url'; $field = $image_fields[$target];
    check( $field['writable'] && $field['kind'] === 'image_url' && $field['format'] === 'url' && ! $field['required'], 'Registered native background image supports ' . $control );
    check( $field['native_media'] === [ 'kind' => 'elementor', 'url_target' => $target, 'id_target' => '/document/0/settings/' . $control . '/id' ], 'Native image manifest identifies only its exact pair: ' . $control );
}
check( ! $image_fields['/document/0/settings/background_image_invented/url']['writable'], 'Unregistered responsive suffix is not guessed from a matching object' );
$hero_target = '/document/0/elements/0/settings/hero_media/url';
check( $image_fields[$hero_target]['writable'] && $image_fields[$hero_target]['label'] === 'Customer hero 1 · Hero photograph', 'Third-party widget MEDIA is discovered by registered schema rather than widget name' );
check( $image_fields['/document/0/elements/0/settings/photos/0/photo/url']['writable'], 'Registered fixed repeater media remains addressable' );
check( $image_fields['/document/0/elements/0/settings/photos/0/nested/0/portrait/url']['writable'], 'Nested fixed repeater media follows the complete registered schema' );
check( ! $image_fields['/document/0/elements/0/settings/photos/1/photo/url']['writable'], 'A dynamic media tag inside a repeater remains protected' );
check( ! $image_fields['/document/0/elements/0/settings/video_asset/url']['writable'] && ! $image_fields['/document/0/elements/0/settings/unknown_media/url']['writable'], 'Video controls and unregistered image-looking objects are not image destinations' );
check( ! isset( $image_fields['/document/0/elements/0/settings/broken_media/url'] ), 'A malformed media value is never invented as a new native field' );
$hero_binding = binding( $hero_target, '/hero', 'url' );
$hero_changed = good( $adapter->bind( $media_document, $hero_binding, 'https://source.test/generated.jpg' ), 'Generic media URL binding' );
check( $hero_changed['document'][0]['elements'][0]['settings']['hero_media'] === [ 'id' => 0, 'url' => 'https://source.test/generated.jpg', 'opaque' => 'unchanged' ], 'New media URL clears the old attachment ID and preserves opaque native properties' );
check( $hero_changed['document'][0]['elements'][0]['settings']['secondary_media'] === $custom_node['settings']['secondary_media'] && $hero_changed['document'][0]['settings'] === $media_document['document'][0]['settings'], 'Unmapped decorative images and native background design are unchanged' );
$local_media = [ 'id' => 777, 'url' => 'https://example.test/wp-content/uploads/generated.jpg' ];
$resolved_document = good( $adapter->bind( $media_document, $hero_binding, 'https://source.test/generated.jpg', [ 'resolved_media' => [ $hero_target => $local_media ] ] ), 'Retained attachment context binding' );
check( $resolved_document['document'][0]['elements'][0]['settings']['hero_media'] === $local_media + [ 'opaque' => 'unchanged' ], 'A resolved attachment uses its paired native ID and local URL' );
$same_resolved = good( $adapter->bind( $media_document, $hero_binding, $custom_node['settings']['hero_media']['url'], [ 'resolved_media' => [ $hero_target => $local_media ] ] ), 'Same source URL with explicit retained attachment' );
check( $same_resolved['document'][0]['elements'][0]['settings']['hero_media']['id'] === 777, 'A resolver result is not lost to the unchanged URL fast path' );
bad( $adapter->bind( $media_document, $hero_binding, 'https://source.test/generated.jpg', [ 'resolved_media' => [ $hero_target => [ 'id' => '777', 'url' => $local_media['url'] ] ] ] ), 'Untrusted or malformed attachment identity is rejected' );
$background_target = '/document/0/settings/background_image/url';
foreach ( [ 'container', 'section', 'column' ] as $kind ) {
    $native_background = $media_document; $native_background['document'][0]['elType'] = $kind;
    $bound_background = good( $adapter->bind( $native_background, binding( $background_target, '/photo', 'url' ), 'https://source.test/background.jpg' ), 'Registered ' . $kind . ' background binding' );
    check( $bound_background['document'][0]['settings']['background_image']['id'] === 0 && $bound_background['document'][0]['settings']['padding'] === $native_background['document'][0]['settings']['padding'], 'Native ' . $kind . ' design survives media substitution' );
}
$dynamic_background = $media_document; $dynamic_background['document'][0]['settings']['__dynamic__']['background_image'] = 'native-dynamic-tag';
bad( $adapter->validate_binding( $dynamic_background, binding( $background_target, '/photo', 'url' ) ), 'Dynamic background remains protected' );
bad( $adapter->validate_binding( $dynamic_background, binding( '/document/0/settings/background_image_mobile/url', '/photo', 'url' ) ), 'A dynamic responsive base cannot silently become static on mobile' );
$global_background = $media_document; $global_background['document'][0]['settings']['__globals__']['background_image'] = 'native-global-setting';
bad( $adapter->validate_binding( $global_background, binding( $background_target, '/photo', 'url' ) ), 'A globally owned media control remains protected' );
$dynamic_repeater = $media_document; $dynamic_repeater['document'][0]['elements'][0]['settings']['__dynamic__']['photos'] = 'provider-owned-list';
bad( $adapter->validate_binding( $dynamic_repeater, binding( '/document/0/elements/0/settings/photos/0/photo/url', '/photo', 'url' ) ), 'Dynamic repeater ownership protects its image descendants' );
$no_id = $media_document; unset( $no_id['document'][0]['elements'][0]['settings']['hero_media']['id'] );
$added_id = good( $adapter->bind( $no_id, $hero_binding, 'https://source.test/no-id.jpg' ), 'Registered URL-only media object' );
check( $added_id['document'][0]['elements'][0]['settings']['hero_media']['id'] === 0, 'Registered media schema can supply the missing paired ID without guessing an arbitrary object' );
$compiled_media = good( $adapter->finalize( $resolved_document, 'media-test' ), 'Finalize preserves native image locations' );
check( $compiled_media['writer_payload']['elementor_data'][0]['elements'][0]['settings']['hero_media']['id'] === 777 && $compiled_media['writer_payload']['elementor_data'][0]['settings']['background_image']['id'] === 41, 'Native element ID rebasing preserves media destination indexes and unmapped attachment IDs' );
check( \Nova_Bridge_Suite_Recipe_Engine::digest( $media_document ) === $media_digest && $writes === $media_writes, 'Registered media discovery and binding preserve the source and perform no native writes' );
echo 'Registered Elementor media: ' . ( $checks - $baseline_checks ) . " additional assertions passed\n";
