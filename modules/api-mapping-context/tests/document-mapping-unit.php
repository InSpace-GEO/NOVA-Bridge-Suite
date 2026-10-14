<?php
/** Complete native capture/blank checks. WP storage and Gutenberg parser seams are isolated. */
define( 'ABSPATH', __DIR__ );
define( 'ELEMENTOR_VERSION', '3.35.0' );
$wp_version = '6.9'; $checks = 0; $administrator = true; $denied = []; $native_writes = 0;
$posts = []; $terms = []; $metadata = []; $term_metadata = []; $registered = []; $acf_values = []; $acf_definitions = []; $block_fixtures = []; $options = [];
class WP_Error {
    private $code; private $message; private $data;
    public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; }
}
class WP_Post {
    public $ID; public $post_type; public $post_title; public $post_content; public $post_excerpt; public $post_name; public $post_status = 'draft'; public $post_parent = 0; public $post_author = 1; public $menu_order = 0; public $post_modified = '2026-10-12 10:00:00'; public $post_modified_gmt = '2026-10-12 08:00:00';
    public function __construct( $id, $type, $content = '' ) { $this->ID = $id; $this->post_type = $type; $this->post_title = 'Title ' . $id; $this->post_content = $content; $this->post_excerpt = 'Excerpt ' . $id; $this->post_name = 'article-' . $id; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap, ...$args ) { return $GLOBALS['administrator'] && ! in_array( $cap . ':' . implode( ':', $args ), $GLOBALS['denied'], true ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function __( $value, $domain = '' ) { return $value; }
function apply_filters( $name, $value, ...$args ) { return $value; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function maybe_unserialize( $value ) { return $value; }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_term( $id, $taxonomy ) { $term = $GLOBALS['terms'][$id] ?? null; return $term && $term->taxonomy === $taxonomy ? $term : null; }
function get_posts( $args ) { return array_values( array_filter( $GLOBALS['posts'], static function ( $post ) use ( $args ) { return $post->post_type === $args['post_type'] && in_array( $post->post_status, $args['post_status'], true ); } ) ); }
function get_terms( $args ) { return array_values( array_filter( $GLOBALS['terms'], static function ( $term ) use ( $args ) { return $term->taxonomy === $args['taxonomy']; } ) ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['metadata'][$id][$key] ?? ''; }
function get_term_meta( $id, $key = '', $single = false ) { if ( '' === $key ) { return array_map( static function ( $value ) { return [ $value ]; }, $GLOBALS['term_metadata'][$id] ?? [] ); } return $GLOBALS['term_metadata'][$id][$key] ?? ''; }
function metadata_exists( $kind, $id, $key ) { return array_key_exists( $key, $GLOBALS['metadata'][$id] ?? [] ); }
function get_registered_meta_keys( $kind, $subtype = '' ) { return $GLOBALS['registered'][$kind . ':' . $subtype] ?? []; }
function get_field_object( $key, $id, $format = false, $load = false ) { return $GLOBALS['acf_definitions'][$key] ?? false; }
function get_field( $key, $id, $format = false ) { return $GLOBALS['acf_values'][$id][$key] ?? false; }
function get_page_template_slug( $id ) { return 'templates/landing.php'; }
function get_post_thumbnail_id( $id ) { return 91; }
function home_url( $path = '/' ) { return 'https://site.example' . $path; }
function get_permalink( $id ) { return 'https://site.example/article-' . $id; }
function get_term_link( $term, $taxonomy = null ) { return 'https://site.example/category-' . ( is_object( $term ) ? $term->term_id : $term ); }
function get_taxonomies( $args, $output ) { return $GLOBALS['taxonomies']; }
function get_post_types( $args, $output ) { return $GLOBALS['types']; }
function get_post_type_object( $name ) { return $GLOBALS['types'][$name] ?? null; }
function wp_update_post( ...$args ) { ++$GLOBALS['native_writes']; throw new RuntimeException( 'No native writes are allowed.' ); }
function wp_insert_post( ...$args ) { ++$GLOBALS['native_writes']; throw new RuntimeException( 'No native writes are allowed.' ); }
function update_post_meta( ...$args ) { ++$GLOBALS['native_writes']; throw new RuntimeException( 'No native writes are allowed.' ); }
function wp_remote_request( ...$args ) { throw new RuntimeException( 'No backend request is allowed.' ); }
function rest_validate_value_from_schema( $value, $schema, $path ) {
    if ( ( $schema['type'] ?? '' ) === 'integer' && ! is_int( $value ) ) { return new WP_Error( 'schema', 'Expected integer' ); }
    if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { return new WP_Error( 'schema', 'Expected schema choice' ); }
    return true;
}
/** Fixtures have the actual parse_blocks tree shape; serialization preserves nesting/attrs/order. */
function parse_blocks( $content ) { return $GLOBALS['block_fixtures'][$content] ?? []; }
function serialize_blocks( $blocks ) {
    $out = '';
    foreach ( $blocks as $block ) {
        $inner = ''; $index = 0;
        foreach ( $block['innerContent'] as $part ) { $inner .= null === $part ? serialize_blocks( [ $block['innerBlocks'][$index++] ] ) : $part; }
        $name = $block['blockName']; if ( null === $name ) { $out .= $inner; continue; }
        $name = strpos( $name, 'core/' ) === 0 ? substr( $name, 5 ) : $name;
        $attrs = $block['attrs'] ? ' ' . json_encode( $block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
        $out .= '' === $inner ? '<!-- wp:' . $name . $attrs . ' /-->' : '<!-- wp:' . $name . $attrs . ' -->' . $inner . '<!-- /wp:' . $name . ' -->';
    }
    return $out;
}
function block( $name, $html, $attrs = [], $children = [], $chunks = null ) { return [ 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => $children, 'innerHTML' => $html, 'innerContent' => null === $chunks ? [ $html ] : $chunks ]; }
function set_blocks( $id, $blocks ) { $raw = serialize_blocks( $blocks ); $GLOBALS['block_fixtures'][$raw] = $blocks; $GLOBALS['posts'][$id]->post_content = $raw; }
class DocumentRoutes { public function get_routes() { return $GLOBALS['registered_routes']; } }
$registered_routes = [ '/wp/v2/categories' => [ [ 'methods' => [ 'POST' => true ], 'args' => [ 'name' => [], 'description' => [], 'meta' => [ 'properties' => [ 'category_copy' => [ 'type' => 'string' ] ] ] ] ] ], '/wp/v2/tags' => [ [ 'methods' => [ 'POST' ], 'args' => [ 'name' => [], 'description' => [] ] ] ] ];
$wp_rest_server = new DocumentRoutes();
$taxonomies = [ (object) [ 'name' => 'category', 'public' => true, 'object_type' => [ 'post' ], 'show_ui' => true, 'show_in_rest' => true, 'rest_base' => 'categories', 'cap' => (object) [ 'edit_terms' => 'manage_categories' ], 'labels' => (object) [ 'name' => 'Categories' ] ], (object) [ 'name' => 'post_tag', 'public' => true, 'object_type' => [ 'post' ], 'show_ui' => true, 'show_in_rest' => true, 'rest_base' => 'tags', 'cap' => (object) [ 'edit_terms' => 'manage_categories' ], 'labels' => (object) [ 'name' => 'Tags' ] ] ];
function type( $name, $base = null, $public = true ) { return (object) [ 'name' => $name, 'public' => $public, 'show_ui' => true, 'show_in_rest' => true, 'rest_base' => $base ?: $name, 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'edit_posts' ], 'labels' => (object) [ 'name' => ucfirst( $name ) ] ]; }
$types = []; foreach ( [ 'page', 'post', 'service_page', 'product', 'locked', 'testing', 'nesting', 'besting', 'resting', 'no_cap' ] as $name ) { $types[$name] = type( $name ); } $types['testing']->rest_base = 'test-articles'; $types['no_cap']->cap->create_posts = 'create_denied'; $types['wp_template'] = type( 'wp_template', 'templates', false ); $types['private_model'] = type( 'private_model', 'private', false );
$registered_routes['/wp/v2/test-articles'] = [ [ 'methods' => 'POST', 'args' => [ 'title' => [], 'content' => [ 'readonly' => true ], 'excerpt' => [], 'meta' => [ 'properties' => [ 'testing_copy' => [ 'type' => 'string' ], 'api_token' => [ 'type' => 'string' ] ] ] ] ] ];
$registered_routes['/wp/v2/nesting'] = [ [ 'methods' => 'GET', 'args' => [ 'title' => [] ] ] ]; $registered_routes['/wp/v2/besting'] = [ [ 'methods' => [ 'POST' => true ], 'args' => [ 'unrelated' => [] ] ] ];
foreach ( [ 'resting', 'product', 'no_cap' ] as $name ) { $registered_routes['/wp/v2/' . $name] = [ [ 'methods' => [ 'POST' ], 'args' => [ 'title' => [], 'content' => [], 'excerpt' => [] ] ] ]; }
foreach ( [ 'wp_pattern_category', 'translation_priority', 'internal_topic', 'public_topic', 'missing_route_topic' ] as $name ) { $taxonomies[] = (object) [ 'name' => $name, 'public' => $name !== 'wp_pattern_category' && $name !== 'translation_priority', 'show_ui' => true, 'show_in_rest' => true, 'object_type' => [ $name === 'internal_topic' ? 'wp_template' : 'resting' ], 'cap' => (object) [ 'edit_terms' => 'manage_categories' ], 'labels' => (object) [ 'name' => 'Topics' ] ]; }
$registered_routes['/wp/v2/public_topic'] = [ [ 'methods' => 'POST', 'args' => [ 'name' => [], 'description' => [] ] ] ];
class Nova_Bridge_Suite_Content_Context {
    public static $resources = [];
    public static function discover_resources() { return [ 'resources' => self::$resources ]; }
}
function resource( $type, $available = true ) {
    $fields = []; foreach ( [ 'title', 'content', 'excerpt' ] as $key ) { $fields['/' . $key] = [ 'path' => '/' . $key, 'writable' => $available ]; }
    return [ 'id' => 'post_type:' . $type, 'label' => $type, 'type' => 'post_type', 'post_type' => $type, 'status' => $available ? 'available' : 'unavailable', 'usable' => $available, 'route' => '/wp/v2/' . $type, 'methods' => [ 'POST' ], 'fields' => $fields ];
}
Nova_Bridge_Suite_Content_Context::$resources = [ resource( 'page' ), resource( 'post' ), resource( 'service_page' ), resource( 'locked', false ) ];
$posts[1] = new WP_Post( 1, 'page', '<div class="article" style="color:#8511D9">' . str_repeat( 'FULL source &amp; paragraph. ', 80 ) . '</div>' );
$posts[1]->post_status = 'publish'; $posts[1]->post_parent = 18; $posts[1]->post_author = 22; $posts[1]->menu_order = 4;
$posts[2] = new WP_Post( 2, 'page', 'DERIVED selected accordion source' );
$posts[3] = new WP_Post( 3, 'post' );
$posts[4] = new WP_Post( 4, 'locked', 'Protected body' );
$posts[5] = new WP_Post( 5, 'page', '[fusion_builder_container]Native Avada[/fusion_builder_container]' );
$posts[6] = new WP_Post( 6, 'product', 'Incomplete generic product' );
$terms[7] = (object) [ 'term_id' => 7, 'taxonomy' => 'category', 'name' => 'Category sample', 'description' => str_repeat( 'Full category description ', 80 ), 'slug' => 'category-sample', 'parent' => 0 ];
$registered['post:page'] = [ 'editorial' => [ 'single' => true, 'type' => 'string' ], 'rating' => [ 'single' => true, 'type' => 'integer' ], 'structured' => [ 'single' => true, 'type' => 'object' ], 'api_token' => [ 'single' => true, 'type' => 'string' ], 'locked' => [ 'single' => true, 'type' => 'string' ] ];
$registered['term:category'] = [ 'category_copy' => [ 'single' => true, 'type' => 'string' ] ];
$registered['term:post_tag'] = [ 'tag_content' => [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ] ]; $registered_routes['/wp/v2/tags'][0]['args']['meta'] = [ 'properties' => [ 'tag_content' => [ 'type' => 'string' ] ] ];
$terms[12] = (object) [ 'term_id' => 12, 'taxonomy' => 'public_topic', 'name' => 'Editorial topic', 'description' => '<p>Filled editorial archive</p>', 'slug' => 'editorial-topic', 'parent' => 0 ];
$terms[13] = (object) [ 'term_id' => 13, 'taxonomy' => 'missing_route_topic', 'name' => 'Editorial topic without REST', 'description' => '<p>Filled editorial archive</p>', 'slug' => 'missing-topic', 'parent' => 0 ];
$metadata[1] = [ 'editorial' => str_repeat( 'Editorial metadata ', 90 ), 'rating' => 3, 'structured' => [ 'copy' => 'Nested editorial copy', 'style' => '#8511D9' ], 'api_token' => 'PRIVATE_TOKEN_VALUE', 'locked' => 'PRIVATE_DENIED_META', 'unregistered' => 'PRIVATE_UNREGISTERED_META' ];
$term_metadata[7] = [ 'category_copy' => 'Approved term metadata' ];
foreach ( [ 'editorial', 'rating', 'api_token', 'locked', 'unregistered' ] as $key ) { Nova_Bridge_Suite_Content_Context::$resources[0]['fields']['/meta/' . $key] = [ 'path' => '/meta/' . $key, 'label' => $key, 'type' => 'rating' === $key ? 'integer' : 'string', 'writable' => true ]; }
Nova_Bridge_Suite_Content_Context::$resources[0]['fields']['/meta/structured/copy'] = [ 'path' => '/meta/structured/copy', 'type' => 'string', 'writable' => true, 'label' => 'Nested copy' ];
$denied = [ 'edit_post_meta:1:locked', 'create_denied:' ];
$posts[9] = new WP_Post( 9, 'testing', 'Protected unsupported native body' ); $registered['post:testing'] = [ 'testing_copy' => [ 'single' => true, 'type' => 'string' ] ]; $metadata[9] = [ 'testing_copy' => str_repeat( 'CPT complete copy ', 80 ), 'api_token' => 'PRIVATE_CPT_TOKEN' ];
$acf_definitions['field_faq'] = [ 'key' => 'field_faq', 'name' => 'faq', 'type' => 'repeater', 'sub_fields' => [ [ 'name' => 'question', 'type' => 'text' ], [ 'name' => 'answer', 'type' => 'wysiwyg' ] ] ];
$acf_values[1]['field_faq'] = [ [ 'question' => 'ACF question source', 'answer' => 'ACF answer source' ], [ 'question' => 'Kept question', 'answer' => 'Kept answer' ] ];
foreach ( [ '/acf/faq' => [ 'type' => 'array', 'writable' => false, 'acf_field_key' => 'field_faq' ], '/acf/faq/*/question' => [ 'type' => 'string', 'writable' => true ], '/acf/faq/*/answer' => [ 'type' => 'string', 'writable' => true ] ] as $path => $field ) { Nova_Bridge_Suite_Content_Context::$resources[0]['fields'][$path] = array_merge( [ 'path' => $path, 'label' => $path ], $field ); }
$elementor = [ [ 'id' => 'container', 'elType' => 'container', 'settings' => [ 'content_width' => 'boxed', 'background_color' => '#8511D9' ], 'elements' => [
    [ 'id' => 'heading', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => str_repeat( 'Full heading ', 90 ), 'title_color' => '#8511D9' ], 'elements' => [] ],
    [ 'id' => 'accordion', 'elType' => 'widget', 'widgetType' => 'accordion', 'settings' => [ 'tabs' => [ [ '_id' => 'faq-row', 'tab_title' => 'DERIVED selected accordion source', 'tab_content' => '<p>Accordion answer retained</p>' ] ], 'title_color' => '#0B0B0C' ], 'elements' => [] ],
    [ 'id' => 'image', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => [ 'image' => [ 'id' => 0, 'url' => 'https://site.example/image.png', 'alt' => 'Image description' ] ], 'elements' => [] ],
    [ 'id' => 'dynamic', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Dynamic source', '__dynamic__' => [ 'title' => '[elementor-tag]' ] ], 'elements' => [] ],
    [ 'id' => 'custom', 'elType' => 'widget', 'widgetType' => 'vendor-complex', 'settings' => [ 'opaque' => [ 'complete' => 'Unknown widget source', 'nested' => [ 3, 1, 2 ] ] ], 'elements' => [] ],
] ] ];
$metadata[2] = [ '_elementor_data' => json_encode( $elementor ), '_elementor_edit_mode' => 'builder', '_elementor_page_settings' => [ 'background_color' => '#B9EFA3', 'padding' => [ 'top' => '20', 'unit' => 'px' ] ] ];
$posts[8] = new WP_Post( 8, 'page', '<!-- wp:paragraph --><p>Another editor is active</p><!-- /wp:paragraph -->' ); $metadata[8] = [ '_elementor_data' => json_encode( $elementor ), '_elementor_edit_mode' => '' ];
$gutenberg = [ block( 'core/group', '<section class="wp-block-group custom" style="color:#8511D9"></section>', [ 'className' => 'custom', 'style' => [ 'color' => [ 'text' => '#8511D9' ] ] ], [
    block( 'core/heading', '<h3 class="wp-block-heading" id="topic">' . str_repeat( 'Long heading ', 80 ) . '</h3>', [ 'level' => 3 ] ),
    block( 'core/paragraph', '<p style="font-size:18px">Unique paragraph source <em>inline format</em> &amp; Unicode é</p>', [ 'fontSize' => 'large' ] ),
    block( 'core/list', '<ul class="wp-block-list"><li>Protected nested <ul><li>Nested child</li></ul></li><li class="second">Unique second list item</li></ul>' ),
    block( 'core/list', '<ol class="wp-block-list"></ol>', [ 'ordered' => true ], [ block( 'core/list-item', '<li>Modern first item</li>' ), block( 'core/list-item', '<li class="last">Modern second item</li>' ) ], [ '<ol class="wp-block-list">', null, null, '</ol>' ] ),
    block( 'vendor/dynamic', '', [ 'provider_id' => 91 ], [], [] ),
], [ '<section class="wp-block-group custom" style="color:#8511D9">', null, null, null, null, null, '</section>' ] ) ];
set_blocks( 3, $gutenberg );
require_once dirname( __DIR__, 2 ) . '/elementor/includes/class-elementor-service.php';
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-document-mapping.php';
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function result( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; }
function rejected( $value, $suffix = '' ) { check( is_wp_error( $value ) && ( '' === $suffix || $value->get_error_code() === 'nova_document_mapping_' . $suffix ), 'Expected document rejection ' . $suffix ); }
function read_doc( $resource, $id, $type = 'post' ) { return result( Nova_Bridge_Suite_Document_Mapping::read( $resource, $type, $id ) ); }
function field_value_path( $mapping, $needle ) { foreach ( $mapping['fields'] as $path => $field ) { if ( is_string( $field['current_value'] ) && strpos( $field['current_value'], $needle ) !== false && $field['writable'] ) { return $path; } } throw new RuntimeException( 'Writable source not found: ' . $needle ); }

$core = read_doc( 'post_type:page', 1 );
check( strlen( $core['fields']['/core/content']['value'] ) > 600 && $core['structure']['core']['content'] === $posts[1]->post_content, 'Core export truncated or normalized original raw HTML.' );
check( strlen( $core['fields']['/meta/editorial']['value'] ) > 600 && $core['fields']['/meta/rating']['value'] === 3, 'Approved full metadata values/types were lost.' );
check( strpos( json_encode( $core ), 'PRIVATE_' ) === false && !isset( $core['structure']['meta']['unregistered'] ), 'Denied, sensitive or unregistered metadata leaked.' );
check( $core['fields']['/meta/structured']['protected'] && $core['fields']['/meta/structured/copy']['writable'], 'Structured metadata parent was not protected while a verified leaf was retained.' );
check( count( array_filter( array_keys( $core['fields'] ), static function ( $path ) { return strpos( $path, '/acf/faq/' ) === 0; } ) ) === 4 && $core['structure']['acf']['faq'] === $acf_values[1]['field_faq'], 'ACF full structured rows/leaf inventory were flattened or truncated.' );
check( result( Nova_Bridge_Suite_Document_Mapping::validate( $core ) ) === $core, 'An unchanged full core mapping did not validate.' );
$reordered = $core; $reordered['fields'] = array_reverse( $reordered['fields'], true ); result( Nova_Bridge_Suite_Document_Mapping::validate( $reordered ) ); check( true, 'JSON object field-key order must not alter native array/tree authority.' );
$edited = $core; $edited['fields']['/core/content']['value'] = '<p>Generated full article</p>'; result( Nova_Bridge_Suite_Document_Mapping::validate( $edited ) );
foreach ( [ 'instructions', 'label', 'current_value', 'writable', 'protected', 'structure_path' ] as $property ) { $copy = $core; $copy['fields']['/core/content'][$property] = 'Changed readonly metadata'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' ); }
$copy = $core; $copy['instructions'] = 'Changed after export'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'identity' );
$copy = $core; $copy['structure']['core']['content'] = 'Changed readonly source'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'identity' );
$copy = $core; $copy['fields']['/core/slug']['value'] = 'different-slug'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' );
$copy = $core; $copy['fields']['/meta/rating']['value'] = '3'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' );
$copy = $core; $copy['fields']['/core/content']['value'] = [ 'nested' => 'wrong' ]; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' );
$blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $core, [ '/core/content', '/meta/structured/copy', '/acf/faq/0/question' ] ) );
check( $blank['mode'] === 'creation' && $blank['target']['reference_id'] === 0 && $blank['source_reference'] === $core['target'], 'Creation mapping still targets its sample.' );
check( $blank['structure']['core']['status'] === 'draft' && $blank['structure']['core']['slug'] === '' && $blank['structure']['core']['author'] === 0 && $blank['structure']['core']['parent'] === 0 && $blank['structure']['core']['menu_order'] === 0 && $blank['fields']['/core/status']['current_value'] === 'draft', 'Creation mapping inherited publication/URL/ownership identity from its source.' );
check( $blank['structure']['core']['content'] === '' && $blank['fields']['/core/content']['current_value'] === '' && $blank['fields']['/core/content']['value'] === '', 'Selected core source leaked in the blank.' );
check( $blank['fields']['/meta/structured']['value']['copy'] === '' && $blank['fields']['/acf/faq']['current_value'][0]['question'] === '' && strpos( json_encode( $blank ), 'ACF question source' ) === false, 'Selected nested source leaked through a protected parent alias.' );
check( $blank['structure']['meta']['structured']['style'] === '#8511D9' && $blank['structure']['acf']['faq'][1] === $acf_values[1]['field_faq'][1] && $blank['structure']['core']['template'] === 'templates/landing.php', 'Blanking changed nonselected content/layout.' );
result( Nova_Bridge_Suite_Document_Mapping::validate( $blank ) );
$copy = $blank; $copy['fields']['/core/content']['value'] = '<p>New article</p>'; result( Nova_Bridge_Suite_Document_Mapping::validate( $copy ) );
$copy = $blank; $copy['fields']['/core/title']['value'] = 'Unselected replacement'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' );
rejected( Nova_Bridge_Suite_Document_Mapping::blank( $core, [ '/core/slug' ] ) );
rejected( Nova_Bridge_Suite_Document_Mapping::blank( $core, [ '/core/content', '/core/content' ] ) );
rejected( Nova_Bridge_Suite_Document_Mapping::blank( $edited, [ '/core/content' ] ), 'selection' );
$source_content = $posts[1]->post_content; $posts[1]->post_content .= ' Human edit'; $fresh = read_doc( 'post_type:page', 1 );
check( $fresh['baseline']['structure_sha256'] === $core['baseline']['structure_sha256'] && $fresh['baseline']['content_sha256'] !== $core['baseline']['content_sha256'], 'Plain editorial text changed the layout hash or missed the content hash.' );
rejected( Nova_Bridge_Suite_Document_Mapping::validate( $blank ), 'drift' ); $posts[1]->post_content = $source_content;

$elementor_map = read_doc( 'post_type:page', 2 );
check( $elementor_map['provider']['id'] === 'elementor' && $elementor_map['structure']['elementor_document'] === $elementor && $elementor_map['structure']['elementor_page_settings'] === $metadata[2]['_elementor_page_settings'], 'Complete real-bridge Elementor tree/settings were changed.' );
check( !isset( $elementor_map['fields']['/elementor_document/0/elements/0/settings/title_color'] ) && $elementor_map['fields']['/elementor_document/0/elements/3/settings/title']['protected'] && $elementor_map['warnings'], 'Styles, dynamic tags or unknown widgets became writable.' );
check( $elementor_map['fields']['/elementor_document/0/elements/0/settings/title']['label'] === 'Heading 1' && $elementor_map['fields']['/elementor_document/0/elements/3/settings/title']['label'] === 'Heading 2' && $elementor_map['fields']['/elementor_document/0/elements/1/settings/tabs/0/tab_title']['label'] === 'Accordion 1 · item 1 · Question', 'Source-free widget ordinals and repeated field labels were not stable/disambiguated.' );
$title_path = '/elementor_document/0/elements/1/settings/tabs/0/tab_title';
$elementor_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $elementor_map, [ $title_path ] ) );
check( strpos( json_encode( $elementor_blank ), 'DERIVED selected accordion source' ) === false && $elementor_blank['structure']['core']['content'] === '' && $elementor_blank['fields']['/core/content']['current_value'] === '', 'Selected Elementor source leaked through parent values or post_content cache.' );
check( $elementor_blank['structure']['elementor_document'][0]['elements'][1]['settings']['tabs'][0]['_id'] === 'faq-row' && $elementor_blank['structure']['elementor_document'][0]['settings'] === $elementor[0]['settings'] && $elementor_blank['structure']['elementor_document'][0]['elements'][4] === $elementor[0]['elements'][4], 'Elementor identity/styles/opaque widgets were changed by blanking.' );
result( Nova_Bridge_Suite_Document_Mapping::validate( $elementor_blank ) );
$url_path = '/elementor_document/0/elements/2/settings/image/url';
foreach ( [ 'javascript:alert(1)', 'data:text/html,unsafe', 'https://user:password@site.example/private', 'https://site.example/image with spaces.png' ] as $unsafe_url ) { $copy = $elementor_map; $copy['fields'][$url_path]['value'] = $unsafe_url; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' ); }
foreach ( [ 'https://site.example/new.png', '/images/new.png', '#image-anchor' ] as $safe_url ) { $copy = $elementor_map; $copy['fields'][$url_path]['value'] = $safe_url; result( Nova_Bridge_Suite_Document_Mapping::validate( $copy ) ); }
$changed_tree = $elementor; $changed_tree[0]['elements'][0]['settings']['title'] .= ' Human'; $metadata[2]['_elementor_data'] = json_encode( $changed_tree ); $fresh = read_doc( 'post_type:page', 2 );
check( $fresh['baseline']['structure_sha256'] === $elementor_map['baseline']['structure_sha256'] && $fresh['baseline']['content_sha256'] !== $elementor_map['baseline']['content_sha256'], 'Elementor text is not independently versioned from its native layout.' );
rejected( Nova_Bridge_Suite_Document_Mapping::validate( $elementor_blank ), 'drift' );
$changed_tree[0]['settings']['background_color'] = '#fff'; $metadata[2]['_elementor_data'] = json_encode( $changed_tree ); $fresh = read_doc( 'post_type:page', 2 ); check( $fresh['baseline']['structure_sha256'] !== $elementor_map['baseline']['structure_sha256'], 'Elementor style changes were missed by layout authority.' );
$changed_tree = $elementor; $changed_tree[0]['elements'][0]['settings']['api_token'] = 'SECRET'; $metadata[2]['_elementor_data'] = json_encode( $changed_tree ); rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 2 ), 'sensitive' );
$changed_tree = $elementor; $changed_tree[0]['elements'][1]['id'] = 'heading'; $metadata[2]['_elementor_data'] = json_encode( $changed_tree ); rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 2 ) ); $metadata[2]['_elementor_data'] = json_encode( $elementor );

$gut = read_doc( 'post_type:post', 3 );
check( $gut['provider']['id'] === 'gutenberg' && $gut['structure']['gutenberg_blocks'] === $gutenberg && $gut['structure']['core']['content'] === $posts[3]->post_content && $gut['fields']['/core/content']['protected'], 'Gutenberg full raw markup/tree or protected raw-body authority was lost.' );
$paragraph_path = field_value_path( $gut, 'Unique paragraph source' ); $list_path = field_value_path( $gut, 'Unique second list item' ); $modern_path = field_value_path( $gut, 'Modern second item' );
$gut_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $gut, [ $paragraph_path, $list_path, $modern_path ] ) );
foreach ( [ 'Unique paragraph source', 'Unique second list item', 'Modern second item' ] as $source ) { check( strpos( json_encode( $gut_blank ), $source ) === false, 'Selected Gutenberg source leaked from slots/tree/core aliases: ' . $source ); }
check( strpos( $gut_blank['structure']['core']['content'], '<p style="font-size:18px"></p>' ) !== false && strpos( $gut_blank['structure']['core']['content'], '<li class="second"></li>' ) !== false && strpos( $gut_blank['structure']['core']['content'], '<li class="last"></li>' ) !== false && strpos( $gut_blank['structure']['core']['content'], 'Protected nested' ) !== false, 'Gutenberg blanking changed wrappers/styles or targeted the wrong legacy list ordinal.' );
check( $gut_blank['structure']['gutenberg_blocks'][0]['attrs'] === $gutenberg[0]['attrs'] && $gut_blank['structure']['gutenberg_blocks'][0]['innerBlocks'][4] === $gutenberg[0]['innerBlocks'][4], 'Gutenberg container attrs/dynamic blocks were altered.' );
result( Nova_Bridge_Suite_Document_Mapping::validate( $gut_blank ) );
$changed_blocks = $gutenberg; $changed_blocks[0]['innerBlocks'][1]['innerHTML'] = '<p style="font-size:18px">Changed human text</p>'; $changed_blocks[0]['innerBlocks'][1]['innerContent'] = [ $changed_blocks[0]['innerBlocks'][1]['innerHTML'] ]; set_blocks( 3, $changed_blocks ); $fresh = read_doc( 'post_type:post', 3 );
check( $fresh['baseline']['structure_sha256'] === $gut['baseline']['structure_sha256'] && $fresh['baseline']['content_sha256'] !== $gut['baseline']['content_sha256'], 'Gutenberg text edits incorrectly changed layout authority or missed content drift.' ); rejected( Nova_Bridge_Suite_Document_Mapping::validate( $gut_blank ), 'drift' ); set_blocks( 3, $gutenberg );

$empty = result( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:service_page' ) );
check( $empty['mode'] === 'creation' && $empty['source_reference'] === null && $empty['target']['reference_id'] === 0 && $empty['structure']['core']['status'] === 'draft' && $empty['structure']['core']['content'] === '' && $empty['structure']['meta'] === [], 'No-sample document invented source, metadata or publication state.' );
$empty_selected = result( Nova_Bridge_Suite_Document_Mapping::blank( $empty, [ '/core/title', '/core/content' ] ) ); $empty_selected['fields']['/core/content']['value'] = '<p>Generated new document</p>'; result( Nova_Bridge_Suite_Document_Mapping::validate( $empty_selected ) );
$term = read_doc( 'taxonomy:category', 7, 'term' ); check( strlen( $term['fields']['/core/description']['value'] ) > 600 && $term['fields']['/meta/category_copy']['value'] === 'Approved term metadata', 'Ordinary taxonomy full editorial/schema metadata was omitted.' );
$term_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $term, [ '/core/description' ] ) ); result( Nova_Bridge_Suite_Document_Mapping::validate( $term_blank ) );
$term_empty = result( Nova_Bridge_Suite_Document_Mapping::empty( 'taxonomy:post_tag' ) ); check( $term_empty['fields']['/core/name']['writable'] && $term_empty['fields']['/core/description']['writable'], 'REST handler method list did not verify ordinary tags.' );
$locked = read_doc( 'post_type:locked', 4 ); check( !$locked['fields']['/core/content']['writable'] && $locked['warnings'], 'Unavailable native resource claimed a writable body.' );
rejected( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:locked' ), 'resource' ); rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 5 ), 'provider' ); rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:product', 'post', 6 ), 'provider' ); rejected( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:product' ), 'provider' );
rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 8 ), 'provider' );
$catalog = Nova_Bridge_Suite_Document_Mapping::resources(); check( count( $catalog ) === 14 && strpos( json_encode( $catalog ), 'Editorial metadata' ) === false && strpos( json_encode( $catalog ), 'DERIVED selected' ) === false, 'Metadata-only catalog omitted resources or leaked native content.' );
// The older Context seam omits these public editorial CPTs; the native registry must still cover them.
$by_id = []; foreach ( $catalog as $resource ) { $by_id[$resource['id']] = $resource; }
check( isset( $by_id['post_type:testing'], $by_id['post_type:nesting'], $by_id['post_type:besting'], $by_id['post_type:resting'], $by_id['post_type:product'] ) && $by_id['post_type:testing']['route'] === '/wp/v2/test-articles' && $by_id['post_type:testing']['creation_supported'], 'Public custom types or custom native REST base were silently omitted.' );
check( !$by_id['post_type:nesting']['creation_supported'] && !$by_id['post_type:besting']['creation_supported'] && strpos( implode( ' ', $by_id['post_type:nesting']['reasons'] ), 'No native REST create route' ) !== false && !$by_id['post_type:no_cap']['creation_supported'], 'Missing collection schema/capability was mistaken for creation support.' );
check( !isset( $by_id['post_type:wp_template'], $by_id['post_type:private_model'], $by_id['taxonomy:wp_pattern_category'], $by_id['taxonomy:translation_priority'], $by_id['taxonomy:internal_topic'] ) && isset( $by_id['taxonomy:public_topic'] ), 'Internal models/taxonomies or solely-internal associations were exposed.' );
check( $by_id['taxonomy:public_topic']['label'] !== $by_id['taxonomy:missing_route_topic']['label'] && strpos( implode( ' ', $by_id['taxonomy:missing_route_topic']['reasons'] ), '/wp/v2/missing_route_topic' ) !== false, 'Duplicate display labels or missing taxonomy route reason stayed ambiguous.' );
$custom = read_doc( 'post_type:testing', 9 ); check( strlen( $custom['fields']['/meta/testing_copy']['value'] ) > 600 && $custom['fields']['/core/content']['protected'] && strpos( json_encode( $custom ), 'PRIVATE_CPT_TOKEN' ) === false, 'Fallback CPT registration did not capture full approved editorial metadata or respected field schema.' );
foreach ( $catalog as $resource ) { if ( $resource['id'] === 'post_type:product' ) { check( !$resource['creation_supported'] && !$resource['can_inspect_existing'] && $resource['provider_status'] === 'coming_soon' && !$resource['client_configurable'] && $resource['targets'] === [] && $resource['reasons'] === [ 'Coming soon.' ], 'Product endpoint was not a plain disabled Coming soon state.' ); } }
$administrator = false; rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 1 ), 'permission' ); check( Nova_Bridge_Suite_Document_Mapping::resources() === [], 'Unauthorized catalog was visible.' ); $administrator = true;
$denied[] = 'edit_post:1'; rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 1 ), 'permission' ); array_pop( $denied );
rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'term', 7 ), 'target' ); rejected( Nova_Bridge_Suite_Document_Mapping::read( 'post_type:page', 'post', 3 ), 'target' ); rejected( Nova_Bridge_Suite_Document_Mapping::empty( 'arbitrary' ), 'resource' );
check( $native_writes === 0, 'Document inspection/preparation wrote CMS content.' );

/** Real controller + real core adapter integration: approved instructions stay immutable. */
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( isset( $GLOBALS['options'][$key] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function maybe_serialize( $value ) { return serialize( $value ); }
function wp_cache_delete( $key, $group ) {}
function wp_generate_uuid4() { static $counter = 0; return sprintf( '1aac1779-5b7f-4af6-a49e-%012d', ++$counter ); }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid'; }
class DocumentResponse { private $data; public $headers = []; public function __construct( $data ) { $this->data = $data; } public function header( $name, $value ) { $this->headers[$name] = $value; } public function get_data() { return $this->data; } }
function rest_ensure_response( $value ) { return new DocumentResponse( $value ); }
class DocumentRequest {
    private $params; private $body; public function __construct( $params = [], $body = null ) { $this->params = $params; $this->body = $body; }
    public function get_param( $key ) { return $this->params[$key] ?? null; } public function get_header( $key ) { return null; } public function get_json_params() { return $this->body; } public function get_body() { return json_encode( $this->body ); }
}
class DocumentDB { public $options = 'wp_options'; public function prepare( $sql, ...$args ) { return $args; } public function query( $args ) { list( $value, $key, $expected ) = $args; if ( serialize( get_option( $key ) ) !== $expected ) { return 0; } $GLOBALS['options'][$key] = unserialize( $value ); return 1; } }
$wpdb = new DocumentDB();
require_once dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-endpoint-mappings.php';
function response_data( $response ) { $response = result( $response ); check( $response instanceof DocumentResponse && $response->headers['Cache-Control'] === 'private, no-store', 'Expected a private controller response.' ); return $response->get_data(); }
$existing = response_data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new DocumentRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 1 ] ) ) );
$body = [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 1, 'selected_fields' => [ '/core/content' ], 'field_descriptions' => [ '/core/content' => [ 'purpose' => 'Main article', 'instructions' => 'Keep readable paragraphs' ] ], 'instructions' => 'Retain site structure', 'label' => 'Article preparation', 'expected_revision' => 0, 'source_document_version' => $existing['source_document_version'] ];
$preview = response_data( Nova_Bridge_Suite_Endpoint_Mappings::preview_creation( new DocumentRequest( [], $body ) ) );
check( $preview['mapping']['structure']['core']['content'] === '' && !isset( $preview['source_evidence'] ) && !$options, 'Controller blank preview leaked evidence or wrote local settings.' );
$saved = response_data( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new DocumentRequest( [], $body ) ) );
$generated = $saved['mapping']; $generated['fields']['/core/content']['value'] = '<p>Created by generation</p>';
$validated = response_data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new DocumentRequest( [], [ 'mapping' => $generated, 'mapping_id' => $saved['mapping_id'], 'revision' => $saved['revision'] ] ) ) );
check( $validated['valid'] && $validated['mapping']['instructions'] === 'Retain site structure' && $validated['mapping']['fields']['/core/content']['instructions'] === "Main article\nKeep readable paragraphs", 'Creation roundtrip lost approved notes or failed readonly core note validation.' );
$existing = response_data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new DocumentRequest( [ 'resource_id' => 'post_type:page', 'reference_type' => 'post', 'reference_id' => 1 ] ) ) );
$existing['mapping']['fields']['/core/content']['value'] = '<p>Existing generation result</p>'; $validated = response_data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new DocumentRequest( [], [ 'mapping' => $existing['mapping'] ] ) ) ); check( $validated['valid'], 'Existing filled generation does not work independently of native writes.' );
$empty_response = response_data( Nova_Bridge_Suite_Endpoint_Mappings::existing( new DocumentRequest( [ 'resource_id' => 'post_type:service_page', 'reference_type' => 'post', 'reference_id' => 0 ] ) ) );
$empty_body = [ 'resource_id' => 'post_type:service_page', 'reference_type' => 'post', 'reference_id' => 0, 'selected_fields' => [ '/core/title', '/core/content' ], 'instructions' => 'Create a new standard item', 'field_descriptions' => [], 'label' => 'No sample', 'expected_revision' => 0, 'source_document_version' => $empty_response['source_document_version'] ];
$saved = response_data( Nova_Bridge_Suite_Endpoint_Mappings::save_creation( new DocumentRequest( [], $empty_body ) ) ); $saved['mapping']['fields']['/core/title']['value'] = 'New item';
$validated = response_data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new DocumentRequest( [], [ 'mapping' => $saved['mapping'], 'mapping_id' => $saved['mapping_id'], 'revision' => $saved['revision'] ] ) ) ); check( $validated['valid'] && $validated['mapping']['source_reference'] === null && $validated['mapping']['target']['reference_id'] === 0, 'Standard source-free controller roundtrip failed.' );
check( $native_writes === 0, 'Controller validation/preparation wrote native content.' );

// Active dedicated modules: their public layout getters would migrate content and must never run.
eval( 'namespace SEORAI\\BodycleanCPT; class Plugin { public static function get_blog_layout_data($id) { throw new \\RuntimeException("Migrating reader called"); } }' );
eval( 'namespace SEORAI\\ServicePageCPT; class Plugin { public static function get_instance() { throw new \\RuntimeException("Renderer called"); } }' );
function nova_bridge_suite_get_managed_blog_post_types() { return [ 'testing', 'resting' ]; }
$blog_keys = [ 'blog_intro', 'blog_key_takeaways', 'blog_toc_disable', 'blog_part_1', 'blog_cta_title', 'blog_cta_copy', 'blog_cta_button_label', 'blog_cta_button_url', 'blog_cta_disable', 'blog_part_2', 'blog_faqs', 'blog_related_posts', 'blog_cta_after_related_title', 'blog_cta_after_related_copy', 'blog_cta_after_related_button_label', 'blog_cta_after_related_button_url', 'blog_cta_after_related_disable' ];
foreach ( $blog_keys as $key ) { $schema = [ 'type' => in_array( $key, [ 'blog_key_takeaways', 'blog_faqs', 'blog_related_posts' ], true ) ? 'array' : ( substr( $key, -8 ) === '_disable' ? 'boolean' : 'string' ) ]; if ( 'array' === $schema['type'] ) { $schema['items'] = [ 'type' => 'blog_related_posts' === $key ? 'integer' : 'string' ]; } if ( 'blog_faqs' === $key ) { $schema['items'] = [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ] ]; } $registered['post:testing'][$key] = [ 'single' => true, 'type' => $schema['type'], 'auth_callback' => '__return_true', 'show_in_rest' => [ 'schema' => $schema ] ]; }
$metadata[9]['blog_intro'] = 'Blog unique intro source ' . str_repeat( 'Complete source ', 80 ); $posts[9]->post_content = '<!-- wp:paragraph --><p>' . $metadata[9]['blog_intro'] . '</p><!-- /wp:paragraph -->'; $metadata[9]['blog_part_1'] = '<p>Native blog body</p>'; $metadata[9]['blog_key_takeaways'] = [ 'Takeaway 1', 'Takeaway 2' ]; $metadata[9]['blog_faqs'] = [ [ 'question' => 'Blog unique question', 'answer' => '<p>Blog native answer</p>' ] ];
$options['quarantined_cpt_bodyclean_blog_text_color'] = '#8511D9'; $options['quarantined_cpt_bodyclean_components'] = [ 'intro' => true, 'content_1' => true, 'key_takeaways' => true, 'faq' => true ];
$blog = read_doc( 'post_type:testing', 9 );
check( $blog['provider']['id'] === 'nova_blog' && $blog['provider']['preview_read_only'] === false && $blog['preview_url'] === '' && $blog['fields']['/core/content']['protected'], 'Blog template body/unsafe frontend preview were mistaken for native editable fields.' );
check( $blog['structure']['core']['content'] === $posts[9]->post_content && !isset( $blog['structure']['gutenberg_blocks'], $blog['structure']['gutenberg_content'] ), 'Dedicated template scaffold raw markup was normalized or duplicated into shadow builder aliases.' );
check( $blog['structure']['suite_template']['language_scope'] === 'site_default' && isset( $blog['structure']['suite_template']['artifacts_sha256']['cpt/templates/single-quarantined-page.php'] ) && $blog['structure']['suite_template']['settings']['quarantined_cpt_bodyclean_blog_text_color'] === '#8511D9', 'Blog native template/code/style identity was not captured.' );
check( count( array_intersect( $blog_keys, array_keys( $blog['structure']['meta'] ) ) ) === 17 && $blog['fields']['/meta/blog_faqs/0/question']['writable'] && $blog['fields']['/meta/blog_cta_title']['protected'], 'Full native Blog metadata or textual repeater leaf/AI-slot protection was lost.' );
$blog_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $blog, [ '/meta/blog_intro', '/meta/blog_faqs/0/question' ] ) ); check( strpos( json_encode( $blog_blank ), 'Blog unique intro source' ) === false && strpos( json_encode( $blog_blank ), 'Blog unique question' ) === false && $blog_blank['fields']['/meta/blog_faqs']['current_value'][0]['question'] === '', 'Blog creation retained selected source in body cache/parent aliases.' ); result( Nova_Bridge_Suite_Document_Mapping::validate( $blog_blank ) );
$blog_empty = result( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:testing' ) ); check( $blog_empty['fields']['/meta/blog_intro']['writable'] && $blog_empty['fields']['/core/content']['protected'] && $blog_empty['structure']['meta']['blog_faqs'] === [] && !isset( $blog_empty['fields']['/meta/blog_faqs/0/question'] ), 'No-sample Blog fabricated rows or exposed ignored core body.' );
$options['quarantined_cpt_bodyclean_components']['faq'] = false; $inactive = read_doc( 'post_type:testing', 9 ); check( $inactive['fields']['/meta/blog_faqs/0/question']['protected'], 'Disabled Blog component stayed writable.' ); rejected( Nova_Bridge_Suite_Document_Mapping::validate( $blog_blank ), 'drift' ); $options['quarantined_cpt_bodyclean_components']['faq'] = true;
$service_keys = [ 'sp_hero_eyebrow', 'sp_hero_title', 'sp_hero_copy', 'sp_hero_primary_label', 'sp_hero_primary_url', 'sp_hero_secondary_label', 'sp_hero_secondary_url', 'sp_intro', 'sp_main_1', 'sp_main_2', 'sp_main_3', 'sp_table', 'sp_image_1', 'sp_image_2', 'sp_sidebar_image', 'sp_sidebar_title', 'sp_sidebar_copy', 'sp_sidebar_primary_label', 'sp_sidebar_primary_url', 'sp_sidebar_secondary_label', 'sp_sidebar_secondary_url', 'sp_cta_title', 'sp_cta_bullets', 'sp_cta_button_label', 'sp_cta_button_url', 'sp_cta_more_text', 'sp_cta_more_url', 'sp_extra_copy', 'sp_tab_1_title', 'sp_tab_1_content', 'sp_tab_2_title', 'sp_tab_2_content', 'sp_tab_3_title', 'sp_tab_3_content', 'sp_faq', 'sp_related_posts' ];
foreach ( $service_keys as $key ) { $schema = [ 'type' => in_array( $key, [ 'sp_table', 'sp_cta_bullets', 'sp_faq', 'sp_related_posts' ], true ) ? 'array' : ( in_array( $key, [ 'sp_image_1', 'sp_image_2', 'sp_sidebar_image' ], true ) ? 'integer' : 'string' ) ]; if ( 'array' === $schema['type'] ) { $schema['items'] = [ 'type' => 'sp_related_posts' === $key ? 'integer' : 'string' ]; } if ( 'sp_faq' === $key ) { $schema['items'] = [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ] ]; } $registered['post:service_page'][$key] = [ 'single' => true, 'type' => $schema['type'], 'auth_callback' => '__return_true', 'show_in_rest' => [ 'schema' => $schema ] ]; }
$posts[10] = new WP_Post( 10, 'service_page', 'Ignored service scaffold' ); $metadata[10] = [ 'sp_intro' => str_repeat( 'Service native intro ', 80 ), 'sp_main_1' => '<p>Native service main section</p>', 'sp_faq' => [ [ 'question' => 'Service question source', 'answer' => 'Service retained answer' ] ], 'sp_tab_1_content' => 'Inactive tab retained', 'sp_hero_primary_label' => 'Per-item overridden CTA' ];
$options['service_cpt_template'] = 'service-page-1-column'; $options['service_cpt_global_hero_primary_label'] = 'Global protected CTA'; $options['service_cpt_color_primary'] = '#8511D9';
$service = read_doc( 'post_type:service_page', 10 ); check( $service['provider']['id'] === 'nova_service' && count( $service['structure']['suite_template']['artifacts_sha256'] ) === 4 && strlen( $service['fields']['/meta/sp_intro']['value'] ) > 600 && $service['fields']['/core/content']['protected'], 'Service complete native template/metadata was flattened into scaffold body.' );
check( $service['fields']['/meta/sp_hero_primary_label']['protected'] && $service['fields']['/meta/sp_tab_1_content']['protected'] && $service['fields']['/meta/sp_main_3']['protected'] && $service['fields']['/meta/sp_main_1']['writable'], 'Global overrides/inactive Service slots were offered as writable.' );
$service_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $service, [ '/meta/sp_intro', '/meta/sp_faq/0/question' ] ) ); check( strpos( json_encode( $service_blank ), 'Service native intro' ) === false && strpos( json_encode( $service_blank ), 'Service question source' ) === false && $service_blank['structure']['suite_template'] === $service['structure']['suite_template'], 'Service selected source leaked or blanking changed native design.' ); result( Nova_Bridge_Suite_Document_Mapping::validate( $service_blank ) );
$service_empty = result( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:service_page' ) ); check( $service_empty['provider']['id'] === 'nova_service' && $service_empty['fields']['/meta/sp_intro']['writable'] && $service_empty['fields']['/core/content']['protected'] && $service_empty['structure']['meta']['sp_faq'] === [], 'Source-free Service lacks its native scalar slots or invented repeated rows.' );
$options['service_cpt_template_components'] = [ 'service-page-1-column' => [ 'intro' => false ] ]; $inactive = result( Nova_Bridge_Suite_Document_Mapping::empty( 'post_type:service_page' ) ); check( $inactive['fields']['/meta/sp_intro']['protected'], 'Disabled Service component remained writable.' ); unset( $options['service_cpt_template_components'] );
Nova_Bridge_Suite_Content_Context::$resources[] = [ 'id' => 'taxonomy:product_cat', 'type' => 'taxonomy', 'taxonomy' => 'product_cat', 'label' => 'Product categories', 'usable' => true, 'fields' => [ [ 'path' => '/name', 'writable' => true ], [ 'path' => '/description', 'writable' => true ], [ 'path' => '/content_below_products', 'writable' => true ] ] ];
$taxonomies[] = (object) [ 'name' => 'product_cat', 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'object_type' => [ 'product' ], 'cap' => (object) [ 'edit_terms' => 'manage_categories' ] ]; $terms[11] = (object) [ 'term_id' => 11, 'taxonomy' => 'product_cat', 'name' => 'Product category', 'description' => 'Main category description', 'slug' => 'product-category', 'parent' => 0 ];
$registered['term:product_cat'] = [ 'content_below_products' => [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ] ]; $term_metadata[11] = [ 'content_below_products' => str_repeat( 'Unique below-product body ', 80 ), 'display_type' => 'products', 'thumbnail_id' => '123', 'order' => '4' ];
$category = read_doc( 'taxonomy:product_cat', 11, 'term' ); check( $category['provider']['id'] === 'woocommerce_category' && strlen( $category['fields']['/meta/content_below_products']['value'] ) > 600 && $category['structure']['woocommerce_category'] === [ 'display_type' => 'products', 'thumbnail_id' => '123', 'order' => '4' ] && $category['fields']['/woocommerce_category/thumbnail_id']['protected'], 'Woo category native secondary body or protected image/display/order state was omitted.' );
$category_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $category, [ '/meta/content_below_products' ] ) ); check( strpos( json_encode( $category_blank ), 'Unique below-product body' ) === false, 'Woo secondary body source leaked in creation blueprint.' ); result( Nova_Bridge_Suite_Document_Mapping::validate( $category_blank ) );
$category_empty = result( Nova_Bridge_Suite_Document_Mapping::empty( 'taxonomy:product_cat' ) ); check( $category_empty['fields']['/meta/content_below_products']['writable'] && $category_empty['structure']['meta']['content_below_products'] === '', 'Source-free Woo category lacks its approved below-product content slot.' );

// Native NOVA mappings are supplied without a client-created option record.
$native_catalog = Nova_Bridge_Suite_Document_Mapping::resources(); $native_by_id = []; foreach ( $native_catalog as $item ) { $native_by_id[$item['id']] = $item; }
check( $native_by_id['post_type:testing']['system_managed'] && $native_by_id['post_type:testing']['native_mapping_available'] && $native_by_id['post_type:testing']['provider_status'] === 'mapped_by_nova' && !$native_by_id['post_type:testing']['client_configurable'], 'NOVA-owned provider still requires client mapping setup.' );
check( $native_by_id['post_type:service_page']['system_managed'] && $native_by_id['post_type:service_page']['native_mapping_available'] && $native_by_id['post_type:service_page']['native_provider'] === 'nova_service', 'Native Service mapping ownership was not declared.' );
check( $native_by_id['taxonomy:product_cat']['label'] === 'WooCommerce categories', 'WooCommerce category card could be mistaken for native WordPress categories.' );
check( strpos( $blog_empty['instructions'], 'NOVA supplies' ) !== false && $blog_empty['fields']['/meta/blog_intro']['instructions'] !== '' && $blog_empty['fields']['/meta/blog_faqs']['writable'] && $blog_empty['fields']['/meta/blog_key_takeaways']['writable'] && $blog_empty['fields']['/meta/blog_related_posts']['protected'], 'Native factory instructions/editorial arrays or protected reference arrays were not supplied.' );
$native_paths = []; foreach ( $blog_empty['fields'] as $path => $field ) { if ( $field['writable'] && !$field['protected'] ) { $native_paths[] = $path; } }
$native_blank = result( Nova_Bridge_Suite_Document_Mapping::blank( $blog_empty, $native_paths ) ); $native_blank['fields']['/meta/blog_faqs']['value'] = [ [ 'question' => 'New question?', 'answer' => '<p>New answer.</p>' ] ]; $native_blank['fields']['/meta/blog_key_takeaways']['value'] = [ 'First takeaway', 'Second takeaway' ]; result( Nova_Bridge_Suite_Document_Mapping::validate( $native_blank ) );
foreach ( [ [ [ 'question' => 'Q', 'answer' => 'A', 'native_id' => 91 ] ], [ [ 'question' => 'Q' ] ], [ [ 'question' => 12, 'answer' => 'A' ] ], [ 'wrong-row-type' ], [ 'not-a-list' => [ 'question' => 'Q', 'answer' => 'A' ] ] ] as $bad_rows ) { $copy = $native_blank; $copy['fields']['/meta/blog_faqs']['value'] = $bad_rows; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'field' ); }
$copy = $native_blank; $copy['instructions'] = 'Change factory notes'; rejected( Nova_Bridge_Suite_Document_Mapping::validate( $copy ), 'identity' );
check( $blog['fields']['/meta/blog_faqs']['protected'] && $blog['fields']['/meta/blog_faqs/0/question']['writable'], 'Existing native FAQ row structure became freely replaceable.' );
check( $service_empty['fields']['/meta/sp_faq']['writable'] && $service_empty['fields']['/meta/sp_cta_bullets']['writable'] && $service_empty['fields']['/meta/sp_related_posts']['protected'], 'Source-free Service editorial arrays/references have incorrect protection.' );

function catalog_by_id() { $result = []; foreach ( Nova_Bridge_Suite_Document_Mapping::resources() as $resource ) { $result[$resource['id']] = $resource; } return $result; }
foreach ( [ 'product_brand', 'product_tag', 'large_archive' ] as $name ) { $taxonomies[] = (object) [ 'name' => $name, 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'object_type' => [ 'product' ], 'cap' => (object) [ 'edit_terms' => 'manage_categories' ] ]; $registered_routes['/wp/v2/' . $name] = [ [ 'methods' => 'POST', 'args' => [ 'name' => [], 'description' => [] ] ] ]; }
$aux = catalog_by_id(); check( !isset( $aux['taxonomy:product_brand'], $aux['taxonomy:product_tag'], $aux['taxonomy:large_archive'] ) && isset( $aux['taxonomy:product_cat'] ), 'Unused auxiliary taxonomies remained visible, or default product categories were hidden.' );
$terms[14] = (object) [ 'term_id' => 14, 'taxonomy' => 'product_brand', 'name' => 'Brand', 'description' => '<p>&nbsp;<br></p>', 'slug' => 'brand', 'parent' => 0 ]; $aux = catalog_by_id(); check( !isset( $aux['taxonomy:product_brand'] ), 'Empty markup/nbsp counted as a native content page.' );
$terms[14]->description = '<p>A complete brand story.</p>'; $aux = catalog_by_id(); check( isset( $aux['taxonomy:product_brand'] ) && $aux['taxonomy:product_brand']['content_relevance']['status'] === 'relevant', 'Filled native brand description did not activate its content endpoint.' ); $terms[14]->description = '';
$registered['term:product_brand'] = [ 'rank_math_description' => [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ], 'thumbnail_id' => [ 'single' => true, 'type' => 'integer', 'show_in_rest' => true ], 'manufacturer_name' => [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ] ]; $registered_routes['/wp/v2/product_brand'][0]['args']['meta'] = [ 'properties' => [ 'rank_math_description' => [ 'type' => 'string' ], 'thumbnail_id' => [ 'type' => 'integer' ], 'manufacturer_name' => [ 'type' => 'string' ] ] ]; $term_metadata[14] = [ 'rank_math_description' => 'SEO only', 'thumbnail_id' => '99', 'manufacturer_name' => 'Routing identifier only' ];
$aux = catalog_by_id(); check( !isset( $aux['taxonomy:product_brand'] ), 'SEO/name/image-only configuration incorrectly activated a brand content page.' );
$registered['term:product_brand']['brand_copy'] = [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ]; $registered_routes['/wp/v2/product_brand'][0]['args']['meta']['properties']['brand_copy'] = [ 'type' => 'string' ]; $aux = catalog_by_id(); check( isset( $aux['taxonomy:product_brand'] ), 'Declared editable custom copy schema did not expose an otherwise empty taxonomy.' );
for ( $i = 0; $i < 1001; ++$i ) { $terms[10000 + $i] = (object) [ 'term_id' => 10000 + $i, 'taxonomy' => 'large_archive', 'name' => 'Archive ' . $i, 'description' => '', 'slug' => 'archive-' . $i, 'parent' => 0 ]; }
$aux = catalog_by_id(); $excluded = []; foreach ( Nova_Bridge_Suite_Document_Mapping::excluded_resources() as $diagnostic ) { $excluded[$diagnostic['resource_id']] = $diagnostic; } check( !isset( $aux['taxonomy:large_archive'] ) && $excluded['taxonomy:large_archive']['status'] === 'unknown' && $excluded['taxonomy:product_tag']['status'] === 'unused', 'Incomplete evidence became required setup or was falsely classified unused.' );
$previous_db = $wpdb;
class DocumentEvidenceDB { public $term_taxonomy = 'wp_term_taxonomy'; public $termmeta = 'wp_termmeta'; public $queries = []; public function prepare( $sql, ...$args ) { return [ $sql, $args ]; } public function get_var( $prepared ) { $this->queries[] = $prepared; $name = $prepared[1][0]; return in_array( $name, [ 'category', 'public_topic', 'missing_route_topic', 'large_archive' ], true ) ? '1' : '0'; } }
$wpdb = new DocumentEvidenceDB(); $aux = catalog_by_id(); check( $aux['taxonomy:large_archive']['content_relevance']['status'] === 'relevant' && !isset( $aux['taxonomy:product_tag'] ), 'Database EXISTS evidence did not distinguish filled large archives from unused auxiliary tags.' );
foreach ( $wpdb->queries as $query ) { check( strpos( $query[0], 'SELECT EXISTS(' ) === 0 && strpos( $query[0], 'LIMIT 1' ) !== false && in_array( $query[1][0], array_column( $taxonomies, 'name' ), true ), 'Relevance database query was not read-only, bounded and taxonomy-parameterized.' ); }
$wpdb = $previous_db;
$native_options_before = $options;
$native_response = response_data( Nova_Bridge_Suite_Endpoint_Mappings::creation( new DocumentRequest( [ 'resource_id' => 'post_type:testing' ] ) ) );
check( $native_response['system_managed'] && $native_response['mapping']['provider']['id'] === 'nova_blog' && strpos( $native_response['mapping_id'], 'native:' ) === 0 && $options === $native_options_before, 'Managed creation GET required local save or altered options.' );
$native_generated = $native_response['mapping']; $native_generated['fields']['/meta/blog_intro']['value'] = '<p>NOVA generated article intro</p>'; $native_generated['fields']['/meta/blog_faqs']['value'] = [ [ 'question' => 'Generated question?', 'answer' => '<p>Generated answer.</p>' ] ];
$native_validated = response_data( Nova_Bridge_Suite_Endpoint_Mappings::validate( new DocumentRequest( [], [ 'mapping' => $native_generated, 'mapping_id' => $native_response['mapping_id'], 'revision' => $native_response['revision'] ] ) ) );
check( $native_validated['valid'] && $native_validated['mapping']['instructions'] === $native_response['mapping']['instructions'] && $native_validated['mapping']['fields']['/meta/blog_intro']['instructions'] === $native_response['mapping']['fields']['/meta/blog_intro']['instructions'] && $options === $native_options_before, 'Real managed factory roundtrip lost immutable official notes or wrote options.' );
$native_service_response = response_data( Nova_Bridge_Suite_Endpoint_Mappings::creation( new DocumentRequest( [ 'resource_id' => 'post_type:service_page' ] ) ) ); check( $native_service_response['system_managed'] && $native_service_response['mapping']['provider']['id'] === 'nova_service' && $options === $native_options_before, 'Private legacy client Service default overrode native automatic ownership.' );
check( $native_writes === 0, 'Dedicated provider capture ran native writes or a migrating getter.' );
echo 'document-mapping-unit: ' . $checks . " assertions passed\n";
