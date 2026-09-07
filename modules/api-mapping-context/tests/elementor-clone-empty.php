<?php
/** wp eval-file: creates temporary drafts and removes them in finally; saved mappings are never changed. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$ids=[]; $original_user=get_current_user_id(); $override=null;
$filter=static function($value) use (&$override) { return $override ?? $value; };
$canonical=static function(array $nodes) use (&$canonical) { foreach($nodes as &$node){if(($node['settings']??null)===[]){unset($node['settings']);}if(($node['isInner']??null)===false){unset($node['isInner']);}if(isset($node['elements'])){$node['elements']=$canonical($node['elements']);}}return $nodes;};
$assert=static function($ok,$message) { if(!$ok) { throw new RuntimeException($message); } };
try {
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]); rest_get_server();
 $service=new \SEOR_Elementor_Bridge\Elementor_Service(); $source=4171;
 $original_meta=get_post_meta($source,'_elementor_data',true); $document=$service->get_elementor_document_data($source);
 $option=Nova_Bridge_Suite_Strategy::get_option();
 $q=new WP_REST_Request('GET','/nova-bridge/v1/mapping/layout');$q->set_param('reference_type','post');$q->set_param('reference_id',$source);
 $layout=rest_do_request($q)->get_data(); $assert(isset($layout['signature']),'Reference layout exists.');
 $builder_fields=array_values(array_filter($layout['fields'],static function($f){return ($f['builder']??'')==='elementor';}));
 $assert(count($builder_fields)>=2,'Reference contains multiple Elementor fields.'); $target=$builder_fields[1];
 $override=$option; $override['profiles'][$layout['signature']]=['id'=>$layout['signature'],'signature'=>$layout['signature'],'label'=>'Temporary clone test','reference_type'=>'post','reference_id'=>$source,'reference_post_id'=>$source,'guidance'=>'','fields'=>[$target['path']=>['mapping'=>'leave_empty','description'=>'','binding'=>$target['binding']??'']]];
 add_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$filter,999);
 $create=static function($fields=[]) use($source,&$ids){$q=new WP_REST_Request('POST','/seor-bridge/v1/pages');$q->set_header('content-type','application/json');$q->set_body(wp_json_encode(['source_page_id'=>$source,'title'=>'NOVA temporary clone omission test','post_type'=>'post','status'=>'draft','fields'=>$fields]));$r=rest_do_request($q);$d=$r->get_data();if($r->get_status()>=300||empty($d['post_id'])){throw new RuntimeException(wp_json_encode($d));}$ids[]=(int)$d['post_id'];return (int)$d['post_id'];};
 $key=$target['selector_data']['field_key'];
 $clone=$create([['field_key'=>$key,'value'=>'Must be empty instead']]);
 $expected=$service->apply_field_mutations($document,[['field_key'=>$key,'value'=>'']]);
 $assert($canonical($service->get_elementor_document_data($clone))==$canonical($expected),'Only the omitted field is cleared; structure/settings/other content are identical.');
 $assert(get_post_status($clone)==='draft','Clone remains a draft.');
 $override=$option; unset($override['profiles'][$layout['signature']]);
 $control=$create(); $assert($canonical($service->get_elementor_document_data($control))==$canonical($document),'Unmapped clone preserves its source content.');
 $assert(get_post_meta($source,'_elementor_data',true)===$original_meta,'Reference content is unchanged.');
 WP_CLI::success('PASS clone omission, explicit-value override, structure preservation, unmapped clone and source preservation.');
} finally {
 remove_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$filter,999);
 foreach($ids as $id){wp_delete_post($id,true);} wp_set_current_user($original_user);
}
