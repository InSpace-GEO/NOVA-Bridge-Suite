<?php
/** Reproduce flat matrix storage with an unsupported form sibling. Temporary draft only. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$id=0;$old_user=get_current_user_id();$group='group_nova_flat_matrix_test';
function nova_flat_check($ok,$message) { if (!$ok) { throw new RuntimeException($message); } }
try {
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
 $id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Winter tyre test heading'],true);
 nova_flat_check(!is_wp_error($id),'Draft failed');
 acf_add_local_field_group(['key'=>$group,'title'=>'Temporary flat matrix','show_in_rest'=>0,'location'=>[[['param'=>'post','operator'=>'==','value'=>(string)$id]]],'fields'=>[
  ['key'=>'field_nova_flat_matrix','name'=>'matrix','type'=>'flexible_content','layouts'=>[
   ['key'=>'layout_nova_intro','name'=>'intro','sub_fields'=>[['key'=>'field_nova_flat_intro','name'=>'intro','label'=>'Intro','type'=>'textarea']]],
   ['key'=>'layout_nova_button','name'=>'button','sub_fields'=>[['key'=>'field_nova_flat_label','name'=>'button__label','label'=>'Button label','type'=>'text']]],
   ['key'=>'layout_nova_form','name'=>'form','sub_fields'=>[['key'=>'field_nova_flat_form','name'=>'form','type'=>'gravity_forms']]]
  ]]
 ]]);
 $original=['matrix'=>['intro','button','form'],'_matrix'=>'field_nova_flat_matrix','matrix_0_intro'=>'Original matrix introduction','_matrix_0_intro'=>'field_nova_flat_intro','matrix_1_button__label'=>'Order winter tyres?','_matrix_1_button__label'=>'field_nova_flat_label','matrix_2_form'=>'1','_matrix_2_form'=>'field_nova_flat_form'];
 foreach($original as $key=>$value) { update_post_meta($id,$key,$value); }
 rest_get_server();
 $entity=Nova_Bridge_Suite_Strategy::entity('post',$id);
 $fields=array_column(Nova_Bridge_Suite_Strategy::field_inventory($entity),null,'path');
 nova_flat_check(!isset($fields['/meta_all/acf/matrix']),'Unsupported form must not get a whole-parent writer');
 foreach(['matrix_0_intro','matrix_1_button__label'] as $key) {
  nova_flat_check(($fields['/meta_all/'.$key]['transport']??'')==='wordpress_meta_all','Missing flat leaf contract: '.$key);
  nova_flat_check($fields['/meta_all/'.$key]['request_path']==='/meta_all/'.$key,'Wrong write path');
 }
 nova_flat_check(!isset($fields['/meta_all/matrix_2_form']),'Form config must not become content');
 nova_flat_check($fields['/title']['preview_text']==='Winter tyre test heading','Missing native heading text');
 $q=new WP_REST_Request('PATCH','/wp/v2/pages/'.$id);$q->set_param('meta_all',['matrix_0_intro'=>'Updated matrix introduction','matrix_1_button__label'=>'New winter tyre button']);
 $response=rest_do_request($q);
 nova_flat_check($response->get_status()===200,'Native meta_all PATCH failed: '.wp_json_encode($response->get_data()));
 $expected=array_merge($original,['matrix_0_intro'=>'Updated matrix introduction','matrix_1_button__label'=>'New winter tyre button']);
 foreach($expected as $key=>$value) { nova_flat_check(get_post_meta($id,$key,true)===$value,'Wrong or changed sibling: '.$key.' actual='.wp_json_encode(get_post_meta($id,$key,true))); }
 nova_flat_check(!metadata_exists('post',$id,'intro') && !metadata_exists('post',$id,'button__label'),'Nested update created top-level stray data');
 echo "PASS flat matrix discovery and real REST PATCH; form, row markers and hidden references preserved\n";
} finally {
 if(is_int($id)&&$id) { wp_delete_post($id,true); }
 if(function_exists('acf_remove_local_field_group')) { acf_remove_local_field_group($group); }
 wp_set_current_user($old_user);
}
