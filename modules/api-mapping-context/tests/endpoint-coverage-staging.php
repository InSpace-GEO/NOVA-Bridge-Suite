<?php
/** Read-only live coverage and official native mapping canary. */
if(!defined('WP_CLI')||!WP_CLI||rtrim(home_url('/'),'/')!=='https://inspace-seo.online'){throw new RuntimeException('Authorized staging only.');}
$coverageDir='/data/sites/web/inspace-seoonline/nova-endpoint-coverage-20261012';if(realpath($coverageDir)!==$coverageDir){throw new RuntimeException('Private backup required.');}
global $wpdb;$option='nova_bridge_endpoint_mappings_v1';
$before=$wpdb->get_row($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name=%s',$option),ARRAY_A);
$admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);if(!$admins){throw new RuntimeException('Administrator required.');}wp_set_current_user((int)$admins[0]);
$GLOBALS['coverage_checks']=0;
function coverage_check($ok,$message){++$GLOBALS['coverage_checks'];if(!$ok){throw new RuntimeException($message);}}
function coverage_request($method,$path,$params=[]){$r=new WP_REST_Request($method,'/nova-bridge/v1/mapping/'.$path);if($method==='GET'){$r->set_query_params($params);}else{$r->set_header('content-type','application/json');$r->set_body(wp_json_encode($params));}return rest_do_request($r);}
function coverage_data($response){coverage_check($response->get_status()===200,'Unexpected REST status: '.$response->get_status().' '.wp_json_encode($response->get_data()));return $response->get_data();}
function coverage_sample(array $schema){$type=$schema['type']??'string';if($type==='array'){return [coverage_sample($schema['items']??['type'=>'string'])];}if($type==='object'){$v=[];foreach($schema['properties']??[] as $key=>$child){$v[$key]=coverage_sample($child);}return $v;}if($type==='integer'||$type==='number'){return 1;}if($type==='boolean'){return false;}if(in_array($schema['format']??'',['uri','uri-reference','url'],true)){return 'https://example.invalid/';}return 'Native content canary';}
$catalog=coverage_data(coverage_request('GET','endpoints'));$resources=[];foreach($catalog['resources'] as $resource){$resources[$resource['id']]=$resource;}
coverage_check(isset($resources['post_type:page'],$resources['post_type:post'],$resources['taxonomy:product_cat']),'Primary client destinations missing.');
coverage_check(!empty($resources['post_type:product']['coming_soon'])&&empty($resources['post_type:product']['require_creation_default']),'Products still require client setup.');
$optional=[];foreach(['category','post_tag','product_brand','product_tag'] as $taxonomy){$r=$resources['taxonomy:'.$taxonomy]??null;$optional[$taxonomy]=$r?'visible':'hidden';if($r){coverage_check(in_array($r['content_relevance']['status']??'', ['relevant','unknown'],true),'Optional taxonomy appears without content-page evidence.');}}
$types=(array)nova_bridge_suite_get_managed_blog_post_types();if(post_type_exists('service_page')){$types[]='service_page';}$managed=0;$collections=0;
foreach(array_unique($types) as $type){
    $id='post_type:'.$type;$resource=$resources[$id]??[];
    coverage_check(!empty($resource['system_managed'])&&!empty($resource['native_mapping_available'])&&empty($resource['client_configurable']),'NOVA endpoint still needs client mapping: '.$type);
    $official=coverage_data(coverage_request('GET','creation',['resource_id'=>$id]));
    coverage_check(!empty($official['system_managed'])&&$official['saved']===false&&$official['mapping']['target']['reference_id']===0&&$official['revision']===1,'Official native blank mapping unavailable.');
    $returned=$official['mapping'];$changed=false;
    foreach($returned['fields'] as $path=>&$field){if($field['writable']&&!$field['protected']&&is_string($field['value'])){$field['value']='Generated native content';$changed=true;break;}}unset($field);
    coverage_check($changed,'Official template has no writable editorial content.');
    coverage_check(coverage_data(coverage_request('POST','validate',['mapping'=>$returned,'mapping_id'=>$official['mapping_id'],'revision'=>1]))['valid'],'Official returned values did not validate.');
    foreach($official['mapping']['fields'] as $path=>$field){if($field['writable']&&!$field['protected']&&is_array($field['value'])&&isset($field['native_schema'])){$collection=$official['mapping'];$collection['fields'][$path]['value']=coverage_sample($field['native_schema']);coverage_check(coverage_data(coverage_request('POST','validate',['mapping'=>$collection,'mapping_id'=>$official['mapping_id'],'revision'=>1]))['valid'],'Native typed editorial collection did not validate.');++$collections;break;}}
    $stale=coverage_request('POST','validate',['mapping'=>$returned,'mapping_id'=>$official['mapping_id'].'-old','revision'=>1]);coverage_check($stale->get_status()===409,'Stale official identity was accepted.');
    $override=coverage_request('POST','creation',['resource_id'=>$id,'reference_type'=>'post','reference_id'=>0,'source_document_version'=>$official['source_document_version'],'selected_fields'=>$official['selected_fields'],'label'=>'Forbidden client override','instructions'=>'Do not retain this.','expected_revision'=>0]);coverage_check($override->get_status()>=400&&$override->get_status()<500,'A client can replace the NOVA mapping.');
    $items=get_posts(['post_type'=>$type,'post_status'=>['draft','publish','private'],'numberposts'=>1,'suppress_filters'=>true]);if($items){$nativeId=(int)$items[0]->ID;$hash=hash('sha256',serialize([get_post($nativeId,ARRAY_A),get_post_meta($nativeId)]));$existing=coverage_data(coverage_request('GET','existing',['resource_id'=>$id,'reference_type'=>'post','reference_id'=>$nativeId]));coverage_check($existing['mapping']['mode']==='existing'&&$hash===hash('sha256',serialize([get_post($nativeId,ARRAY_A),get_post_meta($nativeId)])),'An existing NOVA item was changed during inspection.');}
    ++$managed;
}
coverage_check($managed>0,'Official native mappings are missing.');
$after=$wpdb->get_row($wpdb->prepare('SELECT option_name,option_value,autoload FROM `'.$wpdb->options.'` WHERE option_name=%s',$option),ARRAY_A);coverage_check($before===$after,'Managed reads/validation changed client mapping history.');
wp_set_current_user(0);coverage_check(coverage_request('GET','endpoints')->get_status()===403,'Anonymous mapping access allowed.');
$result=['passed'=>true,'checks'=>$GLOBALS['coverage_checks'],'managed_native_endpoints'=>$managed,'typed_native_collection_roundtrips'=>$collections,'optional_taxonomies'=>$optional,'client_defaults_unchanged'=>true,'native_content_unchanged'=>true];$raw=wp_json_encode($result);file_put_contents($coverageDir.'/canary-result.json',$raw);chmod($coverageDir.'/canary-result.json',0600);echo $raw."\n";
