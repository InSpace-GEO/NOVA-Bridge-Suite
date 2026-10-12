<?php
/** Explicit staging canary. Run with wp eval-file; restores only its exact owned registry state. */
if ( ! defined('WP_CLI') || ! WP_CLI || rtrim(home_url('/'), '/') !== 'https://inspace-seo.online' ) { throw new RuntimeException('Authorized staging only.'); }
$directory='/data/sites/web/inspace-seoonline/nova-endpoint-defaults-20261012';
if ( realpath($directory)!==$directory ) { throw new RuntimeException('Private staging backup required.'); }
global $wpdb;
$option='nova_bridge_endpoint_mappings_v1';
$original=$wpdb->get_row($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name=%s',$option),ARRAY_A);
if ($original) { throw new RuntimeException('A user default exists; do not replace it for a test.'); }
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
if (!$admins) { throw new RuntimeException('No administrator available.'); }
wp_set_current_user((int)$admins[0]);
register_post_type('nova_map_canary',['public'=>false,'show_ui'=>true,'show_in_rest'=>true,'supports'=>['title','editor','excerpt'],'label'=>'Temporary mapping test']);
$GLOBALS['document_canary_checks']=0;$ownedRaw=null;$fixture=0;$saved=null;
function document_canary_check($value,$message){++$GLOBALS['document_canary_checks'];if(!$value){throw new RuntimeException($message);}}
function document_canary_request($method,$route,$params=[]){$r=new WP_REST_Request($method,'/nova-bridge/v1/mapping/'.$route);if($method==='GET'){$r->set_query_params($params);}else{$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($params));}return rest_do_request($r);}
function document_canary_data($response,$status=200){document_canary_check($response->get_status()===$status,'Unexpected REST status: '.$response->get_status().' '.wp_json_encode($response->get_data()));return $response->get_data();}
try {
    $catalog=document_canary_data(document_canary_request('GET','endpoints'));
    $ids=array_column($catalog['resources'],'id');
    document_canary_check(in_array('post_type:page',$ids,true)&&in_array('post_type:post',$ids,true)&&in_array('taxonomy:category',$ids,true),'Missing native endpoint coverage.');
    $nativeTypes=function_exists('nova_bridge_suite_get_managed_blog_post_types')?(array)nova_bridge_suite_get_managed_blog_post_types():[];
    if(post_type_exists('service_page')){$nativeTypes[]='service_page';}
    foreach(array_unique($nativeTypes) as $type){
        document_canary_check(in_array('post_type:'.$type,$ids,true),'A registered NOVA editorial endpoint was omitted: '.$type);
        $standard=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:'.$type,'reference_type'=>'post','reference_id'=>0]));
        document_canary_check($standard['mapping']['target']['reference_id']===0&&$standard['mapping']['provider']['id']!=='core','A dedicated native endpoint has only a generic empty core body.');
        $items=get_posts(['post_type'=>$type,'post_status'=>['draft','publish','private'],'numberposts'=>1,'suppress_filters'=>true]);
        if($items){$nativeId=(int)$items[0]->ID;$digest=hash('sha256',serialize([get_post($nativeId,ARRAY_A),get_post_meta($nativeId)]));$native=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:'.$type,'reference_type'=>'post','reference_id'=>$nativeId]));document_canary_check($native['mapping']['provider']['id']!=='core'&&$digest===hash('sha256',serialize([get_post($nativeId,ARRAY_A),get_post_meta($nativeId)])),'A dedicated native read omitted its provider or changed the existing item.');}
    }
    $terms=get_terms(['taxonomy'=>'category','hide_empty'=>false,'number'=>1]);
    if(!is_wp_error($terms)&&$terms){$term=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'taxonomy:category','reference_type'=>'term','reference_id'=>(int)$terms[0]->term_id]));document_canary_check($term['mapping']['fields']['/core/description']['value']===$terms[0]->description,'Existing taxonomy content was not captured in full.');}
    $empty=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:nova_map_canary','reference_type'=>'post','reference_id'=>0]));
    document_canary_check($empty['mapping']['target']['reference_id']===0&&$empty['mapping']['fields']['/core/content']['value']==='','Zero-item endpoint does not support standard blank fields.');
    $page=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>5267]));
    document_canary_check($page['mapping']['provider']['id']==='elementor','Elementor native provider was not captured.');
    $long=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>5255]));
    $raw=get_post_meta(5255,'_elementor_data',true);$native=is_array($raw)?$raw:json_decode($raw,true);
    document_canary_check($long['mapping']['structure']['elementor_document']===$native,'Elementor document or widget order was lost.');
    $largest=0;foreach($long['mapping']['fields'] as $field){if(is_string($field['value'])){$largest=max($largest,strlen($field['value']));}}
    document_canary_check($largest>600,'Existing content was truncated to the old inventory preview length.');
    $selected=[];foreach($page['mapping']['fields'] as $path=>$field){if($field['writable']&&!$field['protected']&&is_string($field['value'])){$selected[]=$path;}}
    $payload=['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>5267,'source_document_version'=>$page['source_document_version'],'selected_fields'=>$selected,'label'=>'Temporary staging canary default','instructions'=>'Write a useful introduction.','field_descriptions'=>[],'expected_revision'=>0];
    $preview=document_canary_data(document_canary_request('POST','creation/preview',$payload));
    document_canary_check(!get_option($option,false),'An unsaved preview persisted a default.');
    foreach($selected as $path){document_canary_check($preview['mapping']['fields'][$path]['value']===''&&$preview['mapping']['fields'][$path]['current_value']==='','Selected creation field retained source content.');}
    document_canary_check($preview['mapping']['target']['reference_id']===0&&!isset($preview['source_evidence']),'Public creation mapping leaked private source evidence or target identity.');
    $saved=document_canary_data(document_canary_request('POST','creation',$payload));
    $ownedRaw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM `'.$wpdb->options.'` WHERE option_name=%s',$option));
    document_canary_check($saved['saved']&&$saved['revision']===1,'Default save did not retain its reviewed revision.');
    document_canary_data(document_canary_request('POST','creation',$payload),409);
    $returned=$saved['mapping'];$returned['fields'][$selected[0]]['value']='New generated text';
    $validated=document_canary_data(document_canary_request('POST','validate',['mapping'=>$returned,'mapping_id'=>$saved['mapping_id'],'revision'=>1]));
    document_canary_check($validated['valid']&&$validated['mapping']['instructions']===$payload['instructions'],'Creation values roundtrip or authored instructions failed.');
    $altered=$returned;$altered['structure']['core']['parent']=999;
    document_canary_data(document_canary_request('POST','validate',['mapping'=>$altered,'mapping_id'=>$saved['mapping_id'],'revision'=>1]),400);
    $filled=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:page','reference_type'=>'post','reference_id'=>5267]));
    $rewritten=$filled['mapping'];$rewritten['fields'][$selected[0]]['value']='Rewritten existing value';
    document_canary_check(document_canary_data(document_canary_request('POST','validate',['mapping'=>$rewritten]))['valid'],'Existing full document values roundtrip failed.');
    $fixture=wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'NOVA endpoint canary temporary','post_content'=>'<!-- wp:group {"style":{"spacing":{"padding":{"top":"12px"}}}} --><div class="wp-block-group" style="padding-top:12px"><!-- wp:heading --><h2 class="wp-block-heading">Original heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Original paragraph</p><!-- /wp:paragraph --><!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>First list item</li><!-- /wp:list-item --><!-- wp:list-item --><li>Second list item</li><!-- /wp:list-item --></ul><!-- /wp:list --></div><!-- /wp:group -->'],true);
    if(is_wp_error($fixture)){throw new RuntimeException('Cannot create isolated Gutenberg canary.');}
    $gutenberg=document_canary_data(document_canary_request('GET','existing',['resource_id'=>'post_type:post','reference_type'=>'post','reference_id'=>$fixture]));
    document_canary_check($gutenberg['mapping']['provider']['id']==='gutenberg','Gutenberg provider not captured.');
    $paths=[];foreach($gutenberg['mapping']['fields'] as $path=>$field){if(($field['builder']??'')==='gutenberg'&&$field['writable']){$paths[]=$path;}}
    document_canary_check(count($paths)===4,'Supported Gutenberg heading/paragraph/list text leaves missing.');
    $blank=Nova_Bridge_Suite_Document_Mapping::blank($gutenberg['mapping'],$paths);
    document_canary_check(!is_wp_error($blank)&&strpos(wp_json_encode($blank['structure']),'Original paragraph')===false&&strpos($blank['structure']['core']['content'],'padding-top:12px')!==false,'Gutenberg blank lost wrappers or leaked selected text.');
    document_canary_check(strpos($blank['structure']['core']['content'],'<li></li>')!==false&&strpos($blank['structure']['core']['content'],'First list item')===false,'Gutenberg list blanking did not retain explicit list wrappers.');
    wp_update_post(['ID'=>$fixture,'post_content'=>str_replace('Original paragraph','Edited after export',get_post($fixture)->post_content)]);
    document_canary_data(document_canary_request('POST','validate',['mapping'=>$gutenberg['mapping']]),409);
    wp_set_current_user(0);document_canary_data(document_canary_request('GET','endpoints'),403);wp_set_current_user((int)$admins[0]);
    $result=['passed'=>true,'checks'=>$GLOBALS['document_canary_checks'],'largest_complete_value_bytes'=>$largest,'existing_native_pages_unchanged'=>true,'temporary_registry_restored'=>true];
    file_put_contents($directory.'/canary-mapping.json',wp_json_encode($saved,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));chmod($directory.'/canary-mapping.json',0600);
    file_put_contents($directory.'/canary-result.json',wp_json_encode($result));chmod($directory.'/canary-result.json',0600);
    echo wp_json_encode($result)."\n";
} finally {
    if($fixture&&get_post($fixture)&&get_post($fixture)->post_title==='NOVA endpoint canary temporary'){wp_delete_post($fixture,true);}
    if($ownedRaw!==null){$changed=$wpdb->query($wpdb->prepare('DELETE FROM `'.$wpdb->options.'` WHERE option_name=%s AND BINARY option_value=BINARY %s',$option,$ownedRaw));if($changed!==1){throw new RuntimeException('Concurrent registry edit detected. Test default retained for manual reconciliation.');}wp_cache_delete($option,'options');wp_cache_delete('notoptions','options');wp_cache_delete('alloptions','options');}
}
