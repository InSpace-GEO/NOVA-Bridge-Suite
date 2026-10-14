<?php
namespace {
/** Real term schema/ACF transport with isolated native persistence and callback seams. */
define( 'ABSPATH', __DIR__ );
$checks = 0; $registered = []; $term_values = []; $terms = []; $denied = []; $writes = 0; $options = []; $routes = []; $fail_add_once = false; $acf_reject = false; $admin = true;
$term_sanitizers = []; $term_write_mutator = null;
$GLOBALS['wp_rest_additional_fields'] = [];
class WP_Error { private $code; private $message; private $data; function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_message() { return $this->message; } function get_error_data() { return $this->data; } }
class WP_REST_Response { private $data; public $headers = []; function __construct( $data ) { $this->data = $data; } function get_data() { return $this->data; } function header( $name, $value ) { $this->headers[$name] = $value; } }
class WP_REST_Posts_Controller {}
#[AllowDynamicProperties] class WP_Post {}
#[AllowDynamicProperties] class WP_Post_Type { function __construct(array $data){foreach($data as $key=>$value){$this->$key=$value;}} }
class WP_REST_Request {
    private $body; private $headers; public $params = [];
    function __construct( $method = 'GET', $params = [], $body = null, $headers = [] ) { $this->params = $params; $this->body = $body; $this->headers = $headers; }
    function set_param( $key, $value ) { $this->params[$key] = $value; }
    function get_param( $key ) { return $this->params[$key] ?? null; }
    function get_header( $key ) { return $this->headers[$key] ?? null; }
    function get_body() { return json_encode( $this->body ); }
    function get_json_params() { return $this->body; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function rest_ensure_response( $value ) { return new WP_REST_Response( $value ); }
function register_rest_route( $namespace, $route, $handlers ) { $GLOBALS['routes'][$namespace . $route] = $handlers; }
function add_action( $name, $fn, $priority = 10, $args = 1 ) { $GLOBALS['actions'][$name][] = $fn; } function remove_action( $name, $fn, $priority = 10 ) { $GLOBALS['actions'][$name] = array_values(array_filter($GLOBALS['actions'][$name] ?? [], static function($entry)use($fn){return $entry !== $fn;})); }
function add_filter( $name, $fn, $priority = 10, $args = 1 ) { $GLOBALS['filters'][$name][] = $fn; } function remove_filter( $name, $fn, $priority = 10 ) { $GLOBALS['filters'][$name] = array_values(array_filter($GLOBALS['filters'][$name] ?? [], static function($entry)use($fn){return $entry !== $fn;})); }
function current_user_can( $cap, ...$arguments ) { return $GLOBALS['admin'] && ! in_array( $cap . ':' . implode( ':', $arguments ), $GLOBALS['denied'], true ); }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && in_array( $action, [ 'wp_rest', 'wccbp_save_term' ], true ); }
function wp_salt( $scheme ) { return 'term-unit-' . $scheme; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function get_taxonomy( $taxonomy ) { return 'product_cat' === $taxonomy ? (object) [ 'name' => $taxonomy, 'public' => true, 'publicly_queryable' => true, 'show_ui' => true, 'show_in_rest'=>true, 'object_type'=>['product'], 'rest_base'=>'product-categories', 'cap'=>(object)['manage_terms'=>'manage_product_terms','edit_terms'=>'edit_product_terms'] ] : null; }
function get_term( $id, $taxonomy = '' ) { $term = $GLOBALS['terms'][$id] ?? null; return $term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ? $term : null; }
function get_registered_meta_keys( $type, $subtype = '' ) { return $GLOBALS['registered'][$type . ':' . $subtype] ?? []; }
function register_term_meta( $taxonomy, $key, $args ) { $GLOBALS['registered']['term:' . $taxonomy][$key] = $args + [ 'single' => false, 'type' => 'string', 'show_in_rest' => false, 'description' => '' ]; }
function taxonomy_exists( $taxonomy ) { return 'product_cat' === $taxonomy; }
function register_rest_field( $taxonomy, $key, $args ) { $GLOBALS['wp_rest_additional_fields'][$taxonomy][$key] = $args; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, ( 'post' === $type ? ( $GLOBALS['post_values'][$id] ?? [] ) : ( $GLOBALS['term_values'][$id] ?? [] ) ) ); }
function get_term_meta( $id, $key = '', $single = false ) { $values = $GLOBALS['term_values'][$id] ?? []; if ( '' === $key ) { return $values; } return array_key_exists( $key, $values ) ? ( $single ? ( $values[$key][0] ?? '' ) : $values[$key] ) : ( $single ? '' : [] ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function stored( $value ) { return is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_int( $value ) || is_float( $value ) ? (string) $value : $value ); }
function update_term_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $value = stored( wp_unslash( $value ) ); $same = get_term_meta( $id, $key, true ) === $value; $GLOBALS['term_values'][$id][$key] = [ $value ]; return ! $same; }
function delete_term_meta( $id, $key ) { ++$GLOBALS['writes']; unset( $GLOBALS['term_values'][$id][$key] ); return true; }
function add_term_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $value = wp_unslash( $value ); if ( $GLOBALS['fail_add_once'] && 'reject' === $value ) { $GLOBALS['fail_add_once'] = false; return false; } $GLOBALS['term_values'][$id][$key][] = stored( $value ); return 1; }
function sanitize_term_field( $field, $value, $id, $taxonomy, $context ) { $filter = $GLOBALS['term_sanitizers'][$field] ?? null; return is_callable( $filter ) ? call_user_func( $filter, $value, $id, $taxonomy, $context ) : $value; }
function wp_update_term( $id, $taxonomy, $fields ) { ++$GLOBALS['writes']; foreach ( $fields as $key => $value ) { $GLOBALS['terms'][$id]->$key = wp_unslash( sanitize_term_field( $key, $value, $id, $taxonomy, 'db' ) ); } if ( is_callable( $GLOBALS['term_write_mutator'] ) ) { call_user_func( $GLOBALS['term_write_mutator'], $GLOBALS['terms'][$id] ); } return [ 'term_id' => $id ]; }
function sanitize_meta( $key, $value, $type, $taxonomy = '' ) { $registration = array_merge( get_registered_meta_keys( $type ), get_registered_meta_keys( $type, $taxonomy ) )[$key] ?? []; return is_callable( $registration['sanitize_callback'] ?? null ) ? call_user_func( $registration['sanitize_callback'], $value ) : $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_kses_post( $value ) { return preg_replace( '~<script\b[^>]*>.*?</script>~is', '', $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function acf_get_field_groups( $screen ) { return [ [ 'key' => 'group_category', 'active' => true ] ]; }
function acf_get_fields( $group ) { return array_values( $GLOBALS['acf_schema'] ); }
function acf_get_field_type( $type ) { return (object) [ 'name' => $type ]; }
function get_field( $key, $id, $formatted = true ) { return $GLOBALS['acf_values'][$id][$key] ?? false; }
function normalize_acf( $value, array $field ) {
    if ( 'group' === $field['type'] ) { $out = []; foreach ( $field['sub_fields'] as $child ) { $out[$child['name']] = normalize_acf( $value[$child['key']] ?? $value[$child['name']], $child ); } return $out; }
    if ( 'repeater' === $field['type'] ) { $out = []; foreach ( $value as $row ) { $out[] = normalize_acf( $row, [ 'type' => 'group', 'sub_fields' => $field['sub_fields'] ] ); } return $out; }
    return $value;
}
function update_field( $key, $value, $id ) { ++$GLOBALS['writes']; if ( $GLOBALS['acf_reject'] ) { return false; } foreach ( $GLOBALS['acf_schema'] as $field ) { if ( $field['key'] === $key ) { $GLOBALS['acf_values'][$id][$key] = normalize_acf( $value, $field ); return true; } } return false; }
function acf_flush_value_cache( ...$args ) {}
function field( $name, $type = 'text', $extra = [] ) { return [ 'key' => 'field_' . $name, 'name' => $name, 'label' => $name, 'type' => $type ] + $extra; }
function check( $value, $label ) { ++$GLOBALS['checks']; if ( ! $value ) { throw new RuntimeException( $label ); } }
function good( $value, $label ) { check( ! is_wp_error( $value ), $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); return $value; }
function rejected( $value, $status, $label ) { check( is_wp_error( $value ) && ( $value->get_error_data()['status'] ?? 0 ) === $status, $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_message() : '' ) ); }
define( 'ELEMENTOR_VERSION', '3.35.1' ); define( 'WC_VERSION', '10.4' ); $wp_version = '6.9';
$posts = []; $post_values = []; $actor = 1; $inserts = 0; $native_saves = 0; $crash_native_save = false; $actions = []; $filters = []; $acf_schema = []; $acf_values = [];
function add_option( $key, $value, $unused = '', $autoload = false ) { if ( array_key_exists( $key, $GLOBALS['options'] ) ) { return false; } $GLOBALS['options'][$key] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { if ( ! empty($GLOBALS['crash_checkpoint']) && ($value['phase'] ?? '') === 'complete' ) { $GLOBALS['crash_checkpoint']=false;throw new RuntimeException('Response lost before completion checkpoint'); } $GLOBALS['options'][$key] = $value; return true; }
function get_current_user_id() { return $GLOBALS['actor']; } function wp_set_current_user( $id ) { $GLOBALS['actor'] = $id; }
function home_url( $path = '/' ) { return 'https://site.example' . $path; }
function get_permalink( $id ) { return 'https://site.example/page-' . $id . '/'; }
function get_term_link( $id, $taxonomy = '' ) { return 'https://site.example/category/' . ( is_object( $id ) ? $id->slug : get_term( $id )->slug ) . '/'; }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
function wp_parse_url( $url ) { return parse_url( $url ); }
function get_term_by( $field, $value, $taxonomy ) { foreach ( $GLOBALS['terms'] as $term ) { if ( $term->taxonomy === $taxonomy && $term->$field === $value ) { return $term; } } return false; }
function wp_insert_term( $name, $taxonomy, $args ) { ++$GLOBALS['inserts']; $id = 100 + count( $GLOBALS['terms'] ); $GLOBALS['terms'][$id] = (object) [ 'term_id' => $id, 'taxonomy' => $taxonomy, 'name' => $name, 'description' => '', 'slug' => $args['slug'], 'parent' => $args['parent'] ]; foreach ( $GLOBALS['actions']['created_term'] ?? [] as $fn ) { $fn( $id, $id, $taxonomy ); } return [ 'term_id' => $id ]; }
function get_post( $id ) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta( $id, $key = '', $single = false ) { if ( '' === $key ) { return array_map( static function($value){return [$value];}, $GLOBALS['post_values'][$id] ?? [] ); } return array_key_exists($key,$GLOBALS['post_values'][$id] ?? []) ? $GLOBALS['post_values'][$id][$key] : ''; }
function update_post_meta( $id, $key, $value ) { ++$GLOBALS['writes']; $GLOBALS['post_values'][$id][$key] = stored( wp_unslash( $value ) ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['post_values'][$id][$key] ); return true; }
function get_page_template_slug( $id ) { return get_post_meta( $id, '_wp_page_template', true ); }
function get_post_thumbnail_id( $id ) { return 71; }
function get_field_object( $key, $id, $formatted = false, $load = false ) { foreach($GLOBALS['acf_schema'] as $field){if($field['key']===$key){return $field;}}return false; }
function get_post_status( $id ) { return get_post( $id )->post_status; }
function get_post_type( $id ) { return get_post( $id )->post_type; }
function wp_update_post( $input, $errors = false ) { ++$GLOBALS['writes']; $input = wp_unslash( $input ); $post = get_post( $input['ID'] ); foreach ( $input as $key => $value ) { if ( 'ID' !== $key ) { $post->$key = $value; } } $post->post_modified = '2026-10-14 12:00:00'; return $post->ID; }
function clean_post_cache( $id ) {}
function __( $value, $domain = '' ) { return $value; }
function apply_filters( $name, $value, ...$args ) { foreach ( $GLOBALS['filters'][$name] ?? [] as $fn ) { $value = $fn( $value, ...$args ); } return $value; }
function do_action( $name, ...$args ) { foreach ( $GLOBALS['actions'][$name] ?? [] as $fn ) { $fn( ...$args ); } }
function maybe_unserialize( $value ) { return $value; }
function get_posts( $args ) { return []; }
function get_terms( $args ) { return []; }
function get_post_types( $args, $output ) { return [ 'page' => get_post_type_object( 'page' ), 'product' => get_post_type_object( 'product' ) ]; }
function get_post_type_object( $name ) { return in_array($name,['page','product'],true) ? new WP_Post_Type([ 'name' => $name, 'public' => true, 'show_ui' => true, 'show_in_rest' => true, 'rest_base' => $name === 'page' ? 'pages' : 'products', 'cap' => (object) [ 'edit_posts' => 'edit_pages', 'create_posts' => 'edit_pages' ], 'labels' => (object) [ 'name' => 'Pages' ] ]) : null; }
function get_taxonomies( $args, $output ) { return [ get_taxonomy( 'product_cat' ) ]; }
function rest_validate_value_from_schema( $value, $schema, $path = '' ) {
    if(isset($schema['anyOf'])){foreach($schema['anyOf'] as $branch){if(!is_wp_error(rest_validate_value_from_schema($value,$branch,$path))){return true;}}return new WP_Error('schema','No permitted value branch',['status'=>422]);}
    if(is_array($schema['type'] ?? null)){foreach($schema['type'] as $type){$branch=$schema;$branch['type']=$type;if(!is_wp_error(rest_validate_value_from_schema($value,$branch,$path))){return true;}}return new WP_Error('schema','Wrong nullable value type',['status'=>422]);}
    if(array_key_exists('const',$schema) && $schema['const']!==$value){return new WP_Error('schema','Wrong sentinel value',['status'=>422]);}
    $type = $schema['type'] ?? ''; $valid = 'object' === $type ? is_array($value) : ( 'array' === $type ? is_array($value) : ( 'string' === $type ? is_string($value) : ( 'boolean' === $type ? is_bool($value) : ( 'integer' === $type ? is_int($value) : ( 'number' === $type ? is_int($value)||is_float($value) : ('null'===$type ? null===$value : true) ) ) ) ) );
    if ( ! $valid ) { return new WP_Error('schema','Wrong value type', ['status'=>422]); }
    if ( 'object' === $type ) { if ( false === ( $schema['additionalProperties'] ?? true ) && array_diff(array_keys($value),array_keys($schema['properties'] ?? [])) ) { return new WP_Error('schema','Unknown property',['status'=>422]); } foreach($schema['required'] ?? [] as $key){if(!array_key_exists($key,$value)){return new WP_Error('schema','Missing property',['status'=>422]);}} foreach($value as $key=>$item){if(isset($schema['properties'][$key])){$result=rest_validate_value_from_schema($item,$schema['properties'][$key],$path.'.'.$key);if(is_wp_error($result)){return $result;}}} }
    if('array'===$type){foreach($value as $item){$result=rest_validate_value_from_schema($item,$schema['items'] ?? [],$path);if(is_wp_error($result)){return $result;}}} return true;
}
function rest_sanitize_value_from_schema( $value, $schema, $path = '' ) { return $value; }
class Mapped_DB { function prepare($sql,...$args){return [$sql,$args];} function get_var($query){return '1';} }
$wpdb = new Mapped_DB();
class Mapped_Routes { function get_routes(){return ['/wp/v2/pages'=>[['methods'=>'POST','args'=>['title'=>[],'content'=>[],'excerpt'=>[]]]],'/wc/v3/products/categories'=>[['methods'=>'POST','args'=>['name'=>[],'description'=>[]]]]];} }
$wp_rest_server = new Mapped_Routes();
class Nova_Bridge_Suite_Content_Context { public static $extra=[]; static function discover_resources(){return ['resources'=>[['id'=>'post_type:page','type'=>'post_type','post_type'=>'page','availability'=>'available','status'=>'available','usable'=>true,'route'=>'/wp/v2/pages','methods'=>['POST'],'fields'=>array_merge([['path'=>'/title','writable'=>true],['path'=>'/content','writable'=>true],['path'=>'/excerpt','writable'=>true]],self::$extra)],['id'=>'taxonomy:product_cat','type'=>'taxonomy','taxonomy'=>'product_cat','availability'=>'available','status'=>'available','usable'=>true,'route'=>'/wc/v3/products/categories','methods'=>['POST'],'fields'=>[['path'=>'/name','writable'=>true],['path'=>'/description','writable'=>true]]]]];} }
foreach ( ['recipe-engine','content-transport','recipe-term-fields','recipe-core','recipe-acf','term-content','document-mapping','layout-recipes'] as $file ) { require dirname(__DIR__,2).'/api-mapping-context/includes/class-nova-bridge-suite-'.$file.'.php'; }
foreach ( ['posting-client','receipt-json','mapped-content-writer','mapped-content-connector'] as $file ) { require dirname(__DIR__).'/includes/class-nova-bridge-suite-'.$file.'.php'; }
require dirname(__DIR__,2).'/elementor/includes/class-elementor-service.php';
require dirname(__DIR__,2).'/woocommerce/wc-content-below-products.php';
Nova_Bridge_Suite_Recipe_Core::register(); Nova_Bridge_Suite_Recipe_ACF::register();
WCCBP_Content_Below_Products::register_term_meta(); WCCBP_Content_Below_Products::register_rest_field();
use Nova_Bridge_Suite_Recipe_Engine as Engine;
use Nova_Bridge_Suite_Mapped_Content_Writer as Writer;
function uuid($n){return '1a111111-1111-4111-8111-'.sprintf('%012d',$n);}
function wire_delivery($mapping,$id,$content,$publication=null){$out=['delivery_id'=>uuid($id),'mapping_id'=>$mapping['mapping_id'],'mapping_revision'=>$mapping['revision'],'mapping'=>$mapping,'mapping_digest'=>Writer::wire_digest($mapping),'content'=>$content,'status'=>'pending'];if(null!==$publication){$out['publication']=$publication;}return $out;}
$wire_vector=["\u{E000}"=>1,"\u{1F600}"=>2,'decimal'=>0.00001,'zero'=>-0.0,'separator'=>"\u{2028}"];
$wire_canonical='{"decimal":0.00001,"separator":"'."\u{2028}".'","zero":0,"😀":2,"":1}';
check(Writer::wire_digest($wire_vector)===hash('sha256',$wire_canonical),'Wire digest follows ECMAScript float, signed-zero, Unicode and UTF-16 property ordering.');
check(Writer::wire_digest($wire_vector)!==Engine::digest($wire_vector),'Shared wire hashing does not change the historical compiler hash domain.');
$site = uuid(1); $source = 107; $recipe_id = uuid(2);
$terms[$source] = (object)['term_id'=>$source,'taxonomy'=>'product_cat','name'=>'Reference category','description'=>'<p>Reference top.</p>','slug'=>'reference','parent'=>0];
$term_values[$source] = ['content_below_products'=>['<p>Reference bottom.</p>'],'_client_bottom'=>['<p>Client custom copy.</p>']];
$resource=['id'=>'taxonomy:product_cat','type'=>'taxonomy','taxonomy'=>'product_cat'];
$design=good(Nova_Bridge_Suite_Recipe_Core::design($resource,$source,'static',['_client_bottom'=>['type'=>'string','format'=>'html']]),'Real category design captured.');
$bindings=[['target'=>'/document/title','source'=>'/title','format'=>'text','required'=>true,'label'=>'Category title'],['target'=>'/document/sections/0/body','source'=>'/top','format'=>'html','required'=>true,'label'=>'Top content']];
foreach(Nova_Bridge_Suite_Recipe_Core::describe($design['blueprint'])['fields'] as $field){if(($field['custom_field_key'] ?? '')==='content_below_products'){$bindings[]=['target'=>$field['target'],'source'=>'/bottom','format'=>'html','required'=>true,'label'=>'Bottom content'];}if(($field['custom_field_key'] ?? '')==='_client_bottom'){$bindings[]=['target'=>$field['target'],'source'=>'/custom','format'=>'html','required'=>true,'label'=>'Custom content'];}}
$recipe=['format'=>Engine::FORMAT,'builder'=>'core','mode'=>'static','blueprint'=>$design['blueprint'],'bindings'=>$bindings,'regions'=>[],'instructions'=>'Keep top and bottom separate.'];
$sample=['title'=>'Preview','top'=>'<p>Top.</p>','bottom'=>'<p>Bottom.</p>','custom'=>'<p>Custom.</p>'];
$compiled=good(Engine::compile($recipe,$sample,'unit'),'Real recipe compilation.');
$record=['id'=>$recipe_id,'revision'=>1,'resource_id'=>$resource['id'],'label'=>'Categories','builder'=>'core','mode'=>'static','recipe'=>$recipe,'seal'=>hash_hmac('sha256',Engine::json(['core',$recipe['blueprint']]),wp_salt('auth')),'content_schema'=>$compiled['content_schema'],'sample'=>$sample];
$options['nova_bridge_layout_recipes_v1']=['format'=>'nova-layout-recipes-store/v1','profiles'=>[$recipe_id=>[1=>$record]],'defaults'=>[$resource['id']=>['id'=>$recipe_id,'revision'=>1]]];
$mapping=good(Writer::export_default($site,$resource['id']),'Modern default exports through reviewed immutable recipe.');
check($mapping['operation']==='create' && !isset($mapping['recipe']) && !isset($mapping['seal']) && $mapping['content']['top']==='','Upload contains blank values and no native write authority.');
$source_before=serialize([$terms[$source],$term_values[$source]]);
$delivery=wire_delivery($mapping,3,['title'=>'Mapped category','top'=>'<p>Distinct top.</p>','bottom'=>'<p>Distinct bottom.</p>','custom'=>'<p>Distinct custom.</p>'],['slug'=>'mapped-category','status'=>'publish','title'=>'Mapped category']);
$result=good(Writer::deliver($site,$delivery),'Mapped category delivery succeeds with real schema/compiler/native term/Woo adapters.');$new_id=(int)$result['remote_id'];
check($result['status']==='applied' && $terms[$new_id]->name==='Mapped category' && $terms[$new_id]->description==='<p>Distinct top.</p>','Translated native category identity and top are saved.');
check(get_term_meta($new_id,'content_below_products',true)==='<p>Distinct bottom.</p>' && get_term_meta($new_id,'_client_bottom',true)==='<p>Distinct custom.</p>','Woo bottom and sealed undeclared custom field initialized independently.');
check($source_before===serialize([$terms[$source],$term_values[$source]]),'Creating the category preserves source content.');
$writes_before=$writes;$inserts_before=$inserts;
check(Writer::deliver($site,$delivery)===$result && $writes===$writes_before && $inserts===$inserts_before,'Exact retry returns retained verified receipt without repeating setters.');
$changed=$delivery;$changed['content']['title']='Conflict';rejected(Writer::deliver($site,$changed),409,'Reusing operation identity for different content rejects.');
$unknown=$delivery;$unknown['delivery_id']=uuid(4);$unknown['content']['unexpected']='Denied';rejected(Writer::deliver($site,$unknown),422,'Unknown generated field rejects before insertion.');
check($inserts===$inserts_before,'Rejected content did not create a category.');
$wrong=$delivery;$wrong['delivery_id']=uuid(5);$wrong['mapping']['instructions']='Forged';$wrong['mapping_digest']=Writer::wire_digest($wrong['mapping']);rejected(Writer::deliver($site,$wrong),409,'Remote native policy cannot replace exact retained local mapping.');
$options['nova_bridge_layout_recipes_v1']['defaults'][$resource['id']]['revision']=2;$stale=$delivery;$stale['delivery_id']=uuid(6);$stale['publication']['slug']='stale-category';rejected(Writer::deliver($site,$stale),409,'Changed creation default rejects stale generated content.');$options['nova_bridge_layout_recipes_v1']['defaults'][$resource['id']]['revision']=1;
$category_lost=$delivery;$category_lost['delivery_id']=uuid(14);$category_lost['publication']['slug']='recovered-category';$crash_checkpoint=true;
rejected(Writer::deliver($site,$category_lost),500,'Lost category completion checkpoint retains an uncertain witnessed operation.');$inserts_before=$inserts;
$category_recovered=good(Writer::deliver($site,$category_lost),'A complete witnessed category can be recovered by native field readback.');
check($category_recovered['status']==='applied' && $inserts===$inserts_before && get_term_meta((int)$category_recovered['remote_id'],'content_below_products',true)==='<p>Distinct bottom.</p>','Category recovery confirms its fields without inserting or reapplying content.');
$term_sanitizers['description']=static function($value){return strip_tags($value,'<strong>');};$sanitized_lost=$delivery;$sanitized_lost['delivery_id']=uuid(23);$sanitized_lost['publication']['slug']='sanitized-recovery-category';$crash_checkpoint=true;
rejected(Writer::deliver($site,$sanitized_lost),500,'A native-sanitized category with lost completion checkpoint remains witnessed.');$inserts_before=$inserts;$writes_before=$writes;
$sanitized_recovered=good(Writer::deliver($site,$sanitized_lost),'Category recovery uses the same native DB sanitizer expected by its actual term writer.');
check($inserts===$inserts_before && $writes===$writes_before && $terms[(int)$sanitized_recovered['remote_id']]->description==='Distinct top.','Recovering a sanitized completed category causes no extra native mutation.');unset($term_sanitizers['description']);
$post=new WP_Post();foreach(['ID'=>31,'post_type'=>'page','post_title'=>'Original page','post_content'=>'Derived Elementor cache','post_excerpt'=>'Original excerpt','post_name'=>'unique-page','post_status'=>'publish','post_parent'=>0,'post_author'=>1,'menu_order'=>0,'post_modified'=>'2026-10-14 10:00:00','post_modified_gmt'=>'2026-10-14 08:00:00'] as $key=>$value){$post->$key=$value;}$posts[31]=$post;
$document=[['id'=>'container','elType'=>'container','settings'=>['padding_mobile'=>['top'=>'20'],'background_color'=>'#7711ff'],'elements'=>[['id'=>'heading1','elType'=>'widget','widgetType'=>'heading','settings'=>['title'=>'Unique heading','header_size'=>'h1','title_color'=>'#8800ff'],'elements'=>[]],['id'=>'editor1','elType'=>'widget','widgetType'=>'text-editor','settings'=>['editor'=>'<p>Original article.</p>','align_mobile'=>'left'],'elements'=>[]],['id'=>'button1','elType'=>'widget','widgetType'=>'button','settings'=>['text'=>'Contact','link'=>['url'=>'https://site.example/contact/','is_external'=>false]],'elements'=>[]]]]];
$post_values[31]=['_elementor_data'=>json_encode($document),'_elementor_edit_mode'=>'builder','_elementor_page_settings'=>['hide_title'=>'yes','custom_css'=>'.original{}'],'_wp_page_template'=>'elementor_header_footer'];
$path='/elementor_document/0/elements/1/settings/editor';
$snapshot=good(Writer::capture_snapshot($site,uuid(7),'post_type:page',['reference_type'=>'post','reference_id'=>31],[$path]),'Fresh existing Elementor snapshot uses actual native provider reader.');
check(array_keys($snapshot['content'])===[$path] && $snapshot['content'][$path]==='<p>Original article.</p>' && $snapshot['operation']==='rewrite' && $snapshot['target']['url']===get_permalink(31),'Unique item content and approved paths supplied without creation default.');
check(!isset($snapshot['structure']) && !isset($snapshot['recipe']),'Protected native scaffold never leaves the plugin.');
$reordered_snapshot=json_decode(Nova_Bridge_Suite_Receipt_Json::encode($snapshot),true);check($reordered_snapshot['target']!==$snapshot['target'] && Writer::wire_digest($reordered_snapshot['target'])===Writer::wire_digest($snapshot['target']),'JSONB object member reordering preserves native target authority.');
$rewrite=wire_delivery($reordered_snapshot,8,[$path=>'<p>Surgical replacement.</p>']);$before=json_decode($post_values[31]['_elementor_data'],true);$settings_before=$post_values[31]['_elementor_page_settings'];
$receipt=good(Writer::deliver($site,$rewrite),'Surgical rewrite uses real Elementor bridge and native save lifecycle.');
$expected=$before;$expected[0]['elements'][1]['settings']['editor']='<p>Surgical replacement.</p>';
check(json_decode($post_values[31]['_elementor_data'],true)===$expected && $post_values[31]['_elementor_page_settings']===$settings_before && $post->post_title==='Original page','Only selected leaf changes: ordering, identity, styles, mobile settings, link and title remain identical.');
$writes_before=$writes;$saves_before=$native_saves;check(Writer::deliver($site,$rewrite)===$receipt && $writes===$writes_before && $native_saves===$saves_before,'Rewrite retry does not re-save existing native content.');
$protected=$rewrite;$protected['delivery_id']=uuid(9);$protected['content']=['/core/slug'=>'unauthorized'];rejected(Writer::deliver($site,$protected),422,'Protected or omitted native fields cannot become remote edits.');
$fresh=good(Writer::capture_snapshot($site,uuid(10),'post_type:page',['reference_type'=>'post','reference_id'=>31],[$path]),'Next rewrite reads fresh existing state.');$document=$expected;$document[0]['elements'][1]['settings']['editor']='<p>Human edit.</p>';$post_values[31]['_elementor_data']=json_encode($document);$stale=wire_delivery($fresh,11,[$path=>'<p>Must not overwrite.</p>']);rejected(Writer::deliver($site,$stale),409,'Stale source content rejects before native save.');
check($native_saves===$saves_before && json_decode($post_values[31]['_elementor_data'],true)===$document,'A stale delivery preserves human changes.');
$fresh=good(Writer::capture_snapshot($site,uuid(12),'post_type:page',['reference_type'=>'post','reference_id'=>31],[$path]),'Response-loss capture.');$crash_native_save=true;$lost=wire_delivery($fresh,13,[$path=>'<p>Saved despite lost response.</p>']);rejected(Writer::deliver($site,$lost),500,'Response loss becomes an uncertain retained operation.');$saves_before=$native_saves;$recovered=good(Writer::deliver($site,$lost),'Native readback can recover a completed rewrite after response loss.');check($recovered['status']==='applied' && $native_saves===$saves_before,'Recovery never repeats a native save.');
$acf_schema=['rows'=>field('rows','repeater',['sub_fields'=>[field('question'),field('answer','wysiwyg')]])];
$acf_values[31]=['field_rows'=>[['question'=>'First?','answer'=>'<p>First answer.</p>'],['question'=>'Second?','answer'=>'<p>Second answer.</p>']]];
Nova_Bridge_Suite_Content_Context::$extra=[['path'=>'/acf/rows','acf_field_key'=>'field_rows','label'=>'FAQ rows','type'=>'repeater','writable'=>true],['path'=>'/acf/rows/*/answer','label'=>'FAQ answer','type'=>'rich_text','writable'=>true]];
$acf_path='/acf/rows/0/answer';$acf_snapshot=good(Writer::capture_snapshot($site,uuid(15),'post_type:page',['reference_type'=>'post','reference_id'=>31],[$acf_path]),'Nested ACF leaves come from the real item and declared provider schema.');
$acf_before=$acf_values[31]['field_rows'];$native_before=$post_values[31]['_elementor_data'];$acf_delivery=wire_delivery($acf_snapshot,16,[$acf_path=>'<p>First rewritten answer.</p>']);
$acf_result=good(Writer::deliver($site,$acf_delivery),'Nested ACF rewrite uses validated complete parent transport.');
$acf_expected=$acf_before;$acf_expected[0]['answer']='<p>First rewritten answer.</p>';
check($acf_values[31]['field_rows']===$acf_expected && $post_values[31]['_elementor_data']===$native_before,'Updating a nested ACF answer preserves its question, row count, sibling row and builder document.');
$registered['post:page']['nullable_copy']=['single'=>true,'type'=>'string','show_in_rest'=>['schema'=>['type'=>['string','null']]]];
$registered['post:page']['nullable_number']=['single'=>true,'type'=>'integer','show_in_rest'=>['schema'=>['type'=>['integer','null']]]];
$post_values[31]['nullable_copy']=null;$post_values[31]['nullable_number']=null;$acf_schema['empty_text']=field('empty_text');$acf_values[31]['field_empty_text']=false;
Nova_Bridge_Suite_Content_Context::$extra=array_merge(Nova_Bridge_Suite_Content_Context::$extra,[['path'=>'/meta/nullable_copy','label'=>'Nullable copy','type'=>'string','writable'=>true],['path'=>'/meta/nullable_number','label'=>'Nullable number','type'=>'integer','writable'=>true],['path'=>'/acf/empty_text','acf_field_key'=>'field_empty_text','label'=>'Empty ACF text','type'=>'text','writable'=>true]]);
$empty_paths=['/meta/nullable_copy','/meta/nullable_number','/acf/empty_text'];$empty_snapshot=good(Writer::capture_snapshot($site,uuid(17),'post_type:page',['reference_type'=>'post','reference_id'=>31],$empty_paths),'Declared nullable/native empty scalar fields export without poisoning the snapshot.');
check($empty_snapshot['content']['/meta/nullable_copy']===null && $empty_snapshot['content']['/acf/empty_text']===false && $empty_snapshot['schema']['properties']['/acf/empty_text']['anyOf'][0]['type']==='string','Current sentinels stay explicit while new values follow the declared field type.');
good(rest_validate_value_from_schema($empty_snapshot['content'],$empty_snapshot['schema']),'Fresh current sentinels satisfy their explicit schema.');
$writes_before=$writes;$empty_unchanged=good(Writer::deliver($site,wire_delivery($empty_snapshot,22,['/acf/empty_text'=>false,'/meta/nullable_copy'=>null])),'Explicitly unchanged empty sentinels are preserved without invalid native setters.');check($writes===$writes_before && $empty_unchanged['changed_paths']===[],'Unchanged snapshot fields produce no native mutation.');
$bad_empty=wire_delivery($empty_snapshot,18,['/acf/empty_text'=>true]);$writes_before=$writes;rejected(Writer::deliver($site,$bad_empty),422,'An empty ACF false sentinel cannot authorize arbitrary booleans.');check($writes===$writes_before,'Wrong sentinel input does not call native setters.');
$empty_result=good(Writer::deliver($site,wire_delivery($empty_snapshot,19,['/meta/nullable_copy'=>'Filled native copy','/meta/nullable_number'=>7,'/acf/empty_text'=>'Filled ACF text'])),'Nullable declared string/integer and false-returning ACF text can be filled using native transport.');
check($empty_result['status']==='applied' && $post_values[31]['nullable_copy']==='Filled native copy' && $post_values[31]['nullable_number']==='7' && $acf_values[31]['field_empty_text']==='Filled ACF text','Readback handles only changed declared metadata scalar storage semantics.');
$connection=['base_url'=>'https://preview.example','site_id'=>$site,'token'=>str_repeat('a',32),'enabled'=>true,'paused'=>false,'actor_user_id'=>1];
class Mapped_Test_Client extends Nova_Bridge_Suite_Posting_Client { public $calls=[];public $deliveries=[];public $requests=[];public $fail_receipt=true;public $roundtrip=false;function connection_error(){return null;}function site_request(string $method,string $path,$body=null,array $headers=[]){$this->calls[]=[$method,$path,$body];if($method==='PUT'){return ['status'=>200,'body'=>['mapping'=>$this->roundtrip ? json_decode(Nova_Bridge_Suite_Receipt_Json::encode($body),true) : $body,'digest'=>Writer::wire_digest($body)]];}if($path==='/mapped/snapshot-requests'){return ['status'=>200,'body'=>['requests'=>$this->requests]];}if($path==='/mapped/deliveries'){return ['status'=>200,'body'=>['deliveries'=>$this->deliveries]];}if(strpos($path,'/mapped/deliveries/')===0 && $this->fail_receipt){$this->fail_receipt=false;return new WP_Error('transport','Unknown receipt acknowledgment',['status'=>502]);}return ['status'=>200,'body'=>[]];} }
$api=new Mapped_Test_Client($connection);$api->deliveries=[$delivery];$connector=new Nova_Bridge_Suite_Mapped_Content_Connector($connection,$api);$writes_before=$writes;$inserts_before=$inserts;$first=good($connector->poll(),'Preview connector uses scoped pull and upload.');$second=good($connector->poll(),'Lost receipt acknowledgment replays exact durable receipt.');
$receipts=array_values(array_filter($api->calls,static function($call){return $call[0]==='POST' && strpos($call[1],'/mapped/deliveries/')===0;}));
check($first['errors'] && $second['deliveries']===1 && $receipts[0][2]===$receipts[1][2] && $writes===$writes_before && $inserts===$inserts_before,'Connector receipt retry preserves exact body and causes no extra category/save.');
$disabled=new Nova_Bridge_Suite_Mapped_Content_Connector(array_replace($connection,['enabled'=>false]),$api);$calls=count($api->calls);rejected($disabled->poll(),503,'Disabled modern connection performs no requests.');check(count($api->calls)===$calls,'Installing feature cannot activate existing queues or credentials.');
$numeric_site=uuid(20);$schema_before=$options['nova_bridge_layout_recipes_v1']['profiles'][$recipe_id][1]['content_schema'];$options['nova_bridge_layout_recipes_v1']['profiles'][$recipe_id][1]['content_schema']['properties']['title']['minLength']=-0.0;
$numeric_mapping=good(Writer::export_default($numeric_site,$resource['id']),'Export keeps wire-equivalent native float metadata.');$numeric_wire=json_decode(Nova_Bridge_Suite_Receipt_Json::encode($numeric_mapping),true);
check(Engine::digest($numeric_mapping)!==Engine::digest($numeric_wire) && Writer::wire_digest($numeric_mapping)===Writer::wire_digest($numeric_wire),'Signed-zero float normalization is equivalent only in the shared wire hash domain.');
$numeric_delivery=wire_delivery($numeric_wire,21,$sample,['slug'=>'numeric-wire-category','title'=>'Numeric wire','status'=>'publish']);good(Writer::deliver($numeric_site,$numeric_delivery),'Incoming frozen mapping uses RFC-equivalent local authority across JSON number normalization.');
$numeric_connection=array_replace($connection,['site_id'=>$numeric_site]);$numeric_api=new Mapped_Test_Client($numeric_connection);$numeric_api->roundtrip=true;good((new Nova_Bridge_Suite_Mapped_Content_Connector($numeric_connection,$numeric_api))->sync_defaults(),'Upload acknowledgment compares RFC wire identity after numeric JSON normalization.');
$options['nova_bridge_layout_recipes_v1']['profiles'][$recipe_id][1]['content_schema']=$schema_before;
echo 'PASS '.$checks." mapped-content native adapter checks.\n";
}
namespace Elementor {
    class Plugin { public $documents;public $elements_manager;public static function instance(){static $p;if(!$p){$p=new self();$p->documents=new Documents();$p->elements_manager=new Elements();}return $p;} }
    class Documents { function get($id,$cached=true){return new Document($id);} }
    class Elements { function get_element($elType,$widget=null){return (object)[];} }
    class Document { private $id;function __construct($id){$this->id=$id;}function get_elements_data(){return json_decode(\get_post_meta($this->id,'_elementor_data',true),true);}function is_editable_by_current_user(){return true;}function set_is_built_with_elementor($value){}function save($input){$input=\apply_filters('elementor/document/save/data',$input,$this);++$GLOBALS['native_saves'];\update_post_meta($this->id,'_elementor_data',\wp_slash(json_encode($input['elements'])));\do_action('elementor/editor/after_save',$this->id,$input['elements']);if($GLOBALS['crash_native_save']){$GLOBALS['crash_native_save']=false;throw new \RuntimeException('Lost native response');}return true;} }
}
