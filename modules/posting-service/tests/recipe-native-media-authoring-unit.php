<?php
/** Pure image authoring/projection tests. No HTTP, WordPress database or real persistence. */
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; private $code; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_kses_post( $value ) { return $value; }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function post_type_supports( $type, $feature ) { return 'thumbnail' === $feature && in_array( $type, [ 'post', 'page', 'custom_article' ], true ); }
function no_io() { throw new RuntimeException( 'Pure authoring must not touch storage or HTTP.' ); }
function get_post( ...$args ) { no_io(); }
function get_post_meta( ...$args ) { no_io(); }
function get_option( ...$args ) { no_io(); }
function update_option( ...$args ) { no_io(); }
function update_post_meta( ...$args ) { no_io(); }
function wp_remote_get( ...$args ) { no_io(); }
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-mapping-drafts.php';
require dirname( __DIR__, 2 ) . '/api-mapping-context/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-writing-adapter.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-layout.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-stored-recipe-layout.php';
use Nova_Bridge_Suite_Recipe_Engine as Engine;
use Nova_Bridge_Suite_Recipe_Native_Media as Media;
use Nova_Bridge_Suite_Stored_Recipe_Layout as Stored;
use Nova_Bridge_Suite_Writing_Adapter as Writing;
$checks = 0;
function check( $ok, $message ) { ++$GLOBALS['checks']; if ( ! $ok ) { throw new RuntimeException( $message ); } }
function good( $result ) { check( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'result' ); return $result; }
function recipe( $builder, $blueprint, $bindings ) { return [ 'format' => Engine::FORMAT, 'builder' => $builder, 'mode' => 'static', 'blueprint' => $blueprint, 'bindings' => $bindings, 'regions' => [], 'instructions' => '' ]; }
function record( $recipe, $type = 'post' ) { return [ 'id' => 'test', 'revision' => 1, 'builder' => $recipe['builder'], 'resource_id' => 'post_type:' . $type, 'recipe' => $recipe ]; }
function binding( $target, $native = true ) { $binding = [ 'target' => $target, 'source' => '/image_url', 'format' => 'url', 'required' => false, 'label' => 'Article image', 'instructions' => 'Use an article image.' ]; if ( $native ) { $binding['native_media'] = true; } return $binding; }
function values( array $by_path ) { $result = []; foreach ( $by_path as $path => $value ) { $result[Writing::field_id( $path )] = $value; } return $result; }

class Other_Native_Builder {
    public function validate_blueprint( $native ) { return $native; }
    public function describe( $native ) { return [ 'fields' => [], 'containers' => [] ]; }
    public function validate_binding( ...$args ) { throw new RuntimeException( 'Virtual featured image must bypass native binding.' ); }
    public function validate_region( ...$args ) { return true; }
    public function instantiate( $native, ...$args ) { return $native; }
    public function bind( ...$args ) { throw new RuntimeException( 'Featured image must not mutate builder content.' ); }
    public function replace_region( $native, ...$args ) { return $native; }
    public function finalize( $native, ...$args ) { return [ 'native_document' => $native, 'writer_payload' => [ 'content' => 'Unchanged body' ], 'preview_html' => 'Unchanged body' ]; }
}
Engine::register_adapter( 'other', new Other_Native_Builder() );
$featured = binding( Media::FEATURED ); $url = 'https://images.example.test/article.jpg';
$other = recipe( 'other', [ 'native_style' => 'kept' ], [ $featured ] );
$compiled = good( Engine::compile( $other, [ 'image_url' => $url ], 'stable' ) );
check( $compiled['writer_payload']['nova_media'][Media::FEATURED] === [ 'kind' => 'featured', 'url' => $url ], 'Every builder can emit the shared featured manifest' );
check( $compiled['native_document'] === $other['blueprint'], 'Featured image never changes native builder structure' );
$empty = good( Engine::compile( $other, [], 'stable' ) ); check( ! isset( $empty['writer_payload']['nova_media'] ), 'Absent optional image keeps native payload unchanged' );
$empty = good( Engine::compile( $other, [ 'image_url' => '' ], 'stable' ) ); check( ! isset( $empty['writer_payload']['nova_media'] ), 'Empty optional image is not an implicit clear' );
foreach ( [ 'post', 'page', 'custom_article' ] as $type ) {
    $draft = good( Stored::from_recipe( record( $other, $type ) ) );
    check( $draft['source_hints'][Media::FEATURED] === [ 'image_url', 'image_urls' ], 'Both image sources are advisory candidates' );
    check( $draft['fields'][Media::FEATURED]['description']['value_type'] === 'url' && false === $draft['fields'][Media::FEATURED]['required'], 'Featured destination is optional scalar URL' );
    $stored = good( Stored::compile( $draft, values( [ '/title' => 'Test', Media::FEATURED => $url ] ), 'test' ) );
    check( $stored['writer_payload']['nova_media'][Media::FEATURED]['url'] === $url, 'Stored publication and local draft use the same manifest' );
}
check( is_wp_error( Stored::from_recipe( record( $other, 'no_thumbnail' ) ) ), 'Unsupported destinations do not offer featured import' );
check( empty( Media::describe( [ 'fields' => [] ], 'taxonomy:product_cat' )['fields'] ), 'Taxonomies do not acquire a post thumbnail field' );
$bad = $other; $bad['bindings'][0]['native_media'] = [ 'kind' => 'featured' ]; check( is_wp_error( Engine::validate( $bad ) ), 'Clients cannot inject native pointer descriptors' );
$bad = $other; $bad['bindings'][0]['format'] = 'text'; check( is_wp_error( Engine::validate( $bad ) ), 'Native media must use the URL scalar contract' );
$bad = $other; $bad['bindings'][0]['required'] = true; check( is_wp_error( Engine::compile( $bad, [], 'x' ) ), 'Explicit required image is respected' );
check( is_wp_error( Engine::compile( $other, [ 'image_url' => 'file:///tmp/image.jpg' ], 'x' ) ), 'Local paths are not import sources' );

$blueprint = [ 'document' => [ [ 'id' => 'aaaaaaa', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'id' => 7, 'url' => 'https://example.test/decorative.jpg' ] ], 'elements' => [] ] ], 'meta' => [], 'template' => '' ];
$path = '/document/0/settings/image/url'; $marked = recipe( 'elementor', $blueprint, [ binding( $path ) ] );
$compiled = good( Engine::compile( $marked, [ 'image_url' => $url ], 'image' ) );
check( $compiled['writer_payload']['elementor_data'][0]['settings']['image'] === $blueprint['document'][0]['settings']['image'], 'Native media stays paired and unchanged until import succeeds' );
check( $compiled['writer_payload']['nova_media'][$path]['id_target'] === '/document/0/settings/image/id', 'Manifest retains the exact paired native ID position' );
$draft = good( Stored::from_recipe( record( $marked, 'page' ) ) ); $compiled = good( Stored::compile( $draft, values( [ '/title' => 'Test', $path => '' ] ), 'image' ) );
check( ! isset( $compiled['writer_payload']['nova_media'] ) && $compiled['writer_payload']['elementor_data'][0]['settings']['image']['id'] === 7, 'Missing optional fitted image preserves saved template asset' );
$legacy = recipe( 'elementor', $blueprint, [ binding( $path, false ) ] ); $legacy_draft = good( Stored::from_recipe( record( $legacy, 'page' ) ) );
check( ! isset( $legacy_draft['source_hints'] ) && $legacy_draft['stored_layout']['bindings'][$path] === [ 'kind' => 'recipe', 'target' => $path, 'format' => 'url', 'mode' => 'adapt' ], 'Old unmarked bindings retain their exact serialized contract and digest input' );
$legacy_compiled = good( Stored::compile( $legacy_draft, values( [ '/title' => 'Test', $path => $url ] ), 'image' ) );
check( ! isset( $legacy_compiled['writer_payload']['nova_media'] ) && $legacy_compiled['writer_payload']['elementor_data'][0]['settings']['image']['url'] === $url, 'Old external image binding behavior remains unchanged' );
$tampered = $draft; $tampered['target_descriptors'][$path]['native_media'] = false; check( is_wp_error( Stored::validate( $tampered ) ), 'Changing selected media policy invalidates the sealed layout' );
echo "PASS $checks native media authoring/projection checks (pure, no storage or HTTP).\n";
