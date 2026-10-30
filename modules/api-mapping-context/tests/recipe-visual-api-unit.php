<?php
/** Full registered API harness plus signed source/manual-selection regressions. */
require __DIR__ . '/layout-recipes-api-unit.php';
function home_url( $path = '/' ) { return 'https://site.example' . $path; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function url_to_postid( $url ) { return 'https://site.example/original/' === $url ? 11 : ( 'https://site.example/article/' === $url ? 12 : 0 ); }
function add_query_arg( $args, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $args ); }
class Nova_Bridge_Suite_Mapping_Preview { public static function url( $source ) { return $source['url'] . '?authenticated-preview=1'; } }
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-visual.php';
$visual_checks = 0;
function visual_ok( $condition, $message ) { global $visual_checks; ++$visual_checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function visual_result( $response ) { if ( is_wp_error( $response ) ) { throw new RuntimeException( $response->get_error_message() ); } return $response->get_data(); }
$administrator = true; $nonce_valid = true; $denied_caps = []; $posts[11]->post_type = 'page'; $posts[11]->post_content = '<p>Original source.</p>'; $posts[11]->post_title = 'Original example';
$resource = Nova_Bridge_Suite_Document_Mapping::resources()[0];
$source_before = serialize( $posts[11] ); $settings_before = serialize( $options ); $writes_before = $writes;
$design = visual_result( Nova_Bridge_Suite_Layout_Recipes::design( new RecipeRequest( [], [ 'resource_id'=>'post_type:page','builder'=>'auto','reference_id'=>11,'mode'=>'static','manual_selection'=>true ] ) ) );
visual_ok( $design['builder'] === 'core', 'Auto capture identifies the real provider.' );
$body_fields = array_values( array_filter( $design['fields'], static function ( $field ) { return empty( $field['native_media'] ); } ) );
$media_fields = array_values( array_filter( $design['fields'], static function ( $field ) { return ! empty( $field['native_media'] ); } ) );
visual_ok( count( $body_fields ) === 2 && count( $design['preview_fields'] ) === count( $design['fields'] ), 'Captured Core inventory keeps the real title/body fields and complete preview inventory.' );
visual_ok( count( $media_fields ) === 1 && $media_fields[0]['target'] === '/native_post/featured_image' && false === $media_fields[0]['required'], 'Thumbnail-capable native destinations expose an optional Featured image alongside their real body fields.' );
visual_ok( $design['recipe_preset']['bindings'] === [] && $design['recipe_preset']['regions'] === [], 'A manual capture keeps content fixed until explicitly selected.' );
visual_ok( $design['sample'] instanceof stdClass, 'Empty manual sample exports as a JSON object, never an array.' );
visual_ok( $design['source_reference']['reference_id'] === 11 && $design['source_reference']['resource_id'] === 'post_type:page', 'Source identity is explicit.' );
visual_ok( $design['visual_preview']['status'] === 'available' && strpos( $design['visual_preview']['url'],'nova_recipe_canvas=') !== false, 'Authenticated correlated canvas URL exists.' );
visual_ok( $design['preview_fields'][0]['locator']['reference_id'] === 11 && $design['preview_fields'][0]['field_id'] === $design['fields'][0]['field_id'], 'Canvas IDs belong to the captured native inventory.' );
visual_ok( $design['fields'][0]['current_value'] === 'Original example', 'Current native values are shown independently of mapping selection.' );
visual_ok( serialize($posts[11]) === $source_before && serialize($options) === $settings_before && $writes === $writes_before, 'Capture has no page/settings/backend writes.' );
$url_design = visual_result( Nova_Bridge_Suite_Layout_Recipes::design( new RecipeRequest( [], [ 'resource_id'=>'post_type:page','builder'=>'auto','reference_id'=>0,'reference_url'=>'https://site.example/original/','mode'=>'static','manual_selection'=>true ] ) ) );
visual_ok( $url_design['source_reference']['reference_id'] === 11, 'Local URL resolves to the exact source ID.' );
foreach ( [ 'https://attacker.example/original/','https://user:pass@site.example/original/','javascript:alert(1)' ] as $url ) { visual_ok( is_wp_error( Nova_Bridge_Suite_Recipe_Visual::resolve_url($resource,$url) ), 'External/credential-bearing URL refused.' ); }
visual_ok( is_wp_error( Nova_Bridge_Suite_Layout_Recipes::design( new RecipeRequest([],['resource_id'=>'post_type:page','builder'=>'auto','reference_id'=>12,'reference_url'=>'https://site.example/original/','mode'=>'static']) ) ), 'Conflicting source URL and ID rejected.' );
visual_ok( is_wp_error( Nova_Bridge_Suite_Recipe_Visual::resolve_url($resource,'https://site.example/article/') ), 'Source post type must match destination.' );
visual_ok( is_wp_error( Nova_Bridge_Suite_Layout_Recipes::design( new RecipeRequest([],['resource_id'=>'post_type:page','builder'=>'auto','reference_id'=>0,'mode'=>'static']) ) ), 'Auto detection needs an existing example.' );
$recipe=$design['recipe_preset'];$recipe['bindings'][]=['id'=>'nova_role_intro_123','label'=>'Introduction','target'=>'/document/sections/0/body','source'=>'/custom/intro_123','format'=>'html','required'=>false,'instructions'=>'Introduce the subject of this page.'];
$body=['recipe'=>$recipe,'source_reference'=>$design['source_reference'],'source_receipt'=>$design['source_receipt']];
visual_ok( is_array(Nova_Bridge_Suite_Recipe_Visual::validate_source($body,$recipe,$resource)), 'Certified source validates against current native design.' );
$reordered=$body;$reordered['source_reference']=array_reverse($body['source_reference'],true);
visual_ok( is_array(Nova_Bridge_Suite_Recipe_Visual::validate_source($reordered,$recipe,$resource)), 'Source receipt is independent of object key order.' );
foreach ( ['reference_id'=>12,'builder'=>'elementor','native_digest'=>str_repeat('a',64)] as $key=>$value ) { $forged=$body;$forged['source_reference'][$key]=$value;visual_ok(is_wp_error(Nova_Bridge_Suite_Recipe_Visual::validate_source($forged,$recipe,$resource)),'Source identity/digest forgery refused.'); }
$changed=$body;$changed['source_reference']['title']='Injected title';$changed['source_reference']['url']='https://attacker.example/';$canonical=Nova_Bridge_Suite_Recipe_Visual::validate_source($changed,$recipe,$resource);
visual_ok( is_array($canonical) && $canonical['title']==='Original example' && $canonical['url']===get_permalink(11), 'Presentation strings and URLs are recomputed, not trusted.' );
$posts[11]->post_content='<p>Changed while mapping.</p>';
visual_ok( is_wp_error(Nova_Bridge_Suite_Recipe_Visual::validate_source($body,$recipe,$resource)), 'Changed source refuses an outdated mapping save.' );
$stale=Nova_Bridge_Suite_Recipe_Visual::enrich($design,$resource,0,$body['source_reference'],$body['source_receipt']);
visual_ok( $stale['visual_preview']['status']==='stale' && !isset($stale['visual_preview']['url']), 'A stale example never receives misleading clickable provenance.' );
$posts[11]->post_content='<p>Original source.</p>';
$preview=visual_result(Nova_Bridge_Suite_Layout_Recipes::preview(new RecipeRequest([],['recipe'=>$recipe,'seal'=>$design['seal'],'input'=>['content'=>['custom'=>['intro_123'=>'<p>Review introduction.</p>']]]])));
$save=['id'=>wp_generate_uuid4(),'expected_revision'=>0,'expected_default'=>Nova_Bridge_Suite_Layout_Recipes::defaults()['post_type:page']??null,'resource_id'=>'post_type:page','label'=>'Visual example','recipe'=>$recipe,'seal'=>$design['seal'],'content'=>['custom'=>['intro_123'=>'<p>Review introduction.</p>']],'preview_receipt'=>$preview['preview_receipt'],'source_reference'=>$design['source_reference'],'source_receipt'=>$design['source_receipt']];
$saved=visual_result(Nova_Bridge_Suite_Layout_Recipes::save(new RecipeRequest([],$save)));
visual_ok( $saved['source_reference']['reference_id']===11 && $saved['source_receipt']===$design['source_receipt'], 'Source evidence is retained with immutable revision.' );
$profile=visual_result(Nova_Bridge_Suite_Layout_Recipes::profile(new RecipeRequest(['id'=>$saved['id']])));
visual_ok( $profile['design']['visual_preview']['status']==='available' && $profile['design']['visual_preview']['session_id']!==$design['visual_preview']['session_id'], 'Reload issues a fresh canvas session for the same certified source.' );
$recipe['bindings'][0]['id']='nova_role_custom_123';$recipe['bindings'][0]['instructions']='';
visual_ok( is_wp_error(Nova_Bridge_Suite_Layout_Recipes::preview(new RecipeRequest([],['recipe'=>$recipe,'seal'=>$design['seal'],'input'=>['content'=>[]]]))), 'Custom purpose requires an explicit instruction.' );
$recipe['bindings'][0]['instructions']='A short plain-language promise relevant to the page.';
visual_ok( !is_wp_error(Nova_Bridge_Suite_Layout_Recipes::preview(new RecipeRequest([],['recipe'=>$recipe,'seal'=>$design['seal'],'input'=>['content'=>['custom'=>['intro_123'=>'<p>Custom promise.</p>']]]]))), 'An instructed custom purpose compiles.' );
$compound = $design; $compound['builder'] = 'gutenberg'; $compound['blueprint'] = [ 'document' => [ [ 'innerHTML' => '<figure><img src="https://site.example/a.jpg" /></figure>' ] ] ]; $compound['fields'] = [ [ 'target'=>'/document/0/innerHTML','label'=>'Image URL','format'=>'url','kind'=>'image_url','value'=>'https://site.example/a.jpg','writable'=>true,'native_node_id'=>'internal-block-id','locator'=>[ 'strategy'=>'native_anchor','anchor'=>'hero-photo' ] ] ]; $compound['containers']=[];
$semantic = Nova_Bridge_Suite_Recipe_Visual::enrich($compound,$resource);
visual_ok( $semantic['fields'][0]['current_value']==='https://site.example/a.jpg', 'Compound carriers show the decoded content value, never native HTML as a URL.' );
visual_ok( $semantic['preview_fields'][0]['locator']['strategy']==='native_anchor' && $semantic['preview_fields'][0]['locator']['node_id']==='hero-photo', 'Native DOM anchors use the exact declared anchor rather than an internal builder node ID.' );
$term_visual=Nova_Bridge_Suite_Recipe_Visual::enrich($design,['type'=>'taxonomy','id'=>'taxonomy:product_cat','taxonomy'=>'product_cat']);
visual_ok( $term_visual['preview_fields'][0]['locator']['control_path']===['name'], 'Term title uses its native archive-name locator.' );
$body_locator=array_values(array_filter($term_visual['preview_fields'],static function($f){return $f['target']==='/document/sections/0/body';}));
visual_ok( $body_locator[0]['locator']['control_path']===['description'], 'Term content uses its native archive-description locator.' );
echo 'PASS '.$visual_checks." visual recipe API checks.\n";
