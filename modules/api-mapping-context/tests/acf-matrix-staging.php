<?php
/** Staging only: temporary ACF group and draft; all removed in finally. No mapping writes. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$old_user = get_current_user_id(); $id = 0; $group = 'group_nova_matrix_regression';
function nova_matrix_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
try {
 wp_set_current_user((int)get_users(['role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
 $id = wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'NOVA temporary matrix regression'],true);
 nova_matrix_assert(!is_wp_error($id),'Draft creation failed');
 acf_add_local_field_group(['key'=>$group,'title'=>'Temporary matrix regression','show_in_rest'=>0,'location'=>[[['param'=>'post','operator'=>'==','value'=>(string)$id]]],'fields'=>[
  ['key'=>'field_nova_matrix_regression','name'=>'nova_test_matrix','label'=>'Matrix','type'=>'flexible_content','layouts'=>[
   'layout_nova_copy'=>['key'=>'layout_nova_copy','name'=>'copy','label'=>'Copy','display'=>'block','sub_fields'=>[
    ['key'=>'field_nova_matrix_body','name'=>'body','label'=>'Body','type'=>'wysiwyg'],
    ['key'=>'field_nova_matrix_title','name'=>'title','label'=>'Title','type'=>'text']
   ]]
  ]]
 ]]);
 update_field('field_nova_matrix_regression',[
  ['acf_fc_layout'=>'copy','field_nova_matrix_body'=>'<p>First original content</p>','field_nova_matrix_title'=>'Keep first title'],
  ['acf_fc_layout'=>'copy','field_nova_matrix_body'=>'<p>Second original content</p>','field_nova_matrix_title'=>'Keep second title']
 ],$id);
 rest_get_server();
 $entity=Nova_Bridge_Suite_Strategy::entity('post',$id);
 $fields=array_column(Nova_Bridge_Suite_Strategy::field_inventory($entity),null,'path');
 $path='/meta_all/acf/nova_test_matrix/1/body';
 nova_matrix_assert(isset($fields[$path]) && $fields[$path]['writable'],'Concrete matrix body missing');
 nova_matrix_assert($fields[$path]['request_path']==='/meta_all/acf/nova_test_matrix' && $fields[$path]['write_mode']==='complete_parent','Incorrect leaf writer contract');
 nova_matrix_assert($fields['/acf/nova_test_matrix']['alternative_path']==='/meta_all/acf/nova_test_matrix','Native REST fallback missing');
 $route=Nova_Bridge_Suite_Content_Transport::route_for('page').'/'.$id;
 $read=rest_do_request(new WP_REST_Request('GET',$route));
 nova_matrix_assert($read->get_status()===200,'Matrix GET failed');
 $read_data=json_decode(wp_json_encode($read->get_data()),true); $before=$read_data['meta_all']['acf']['nova_test_matrix']; $next=$before;
 $next[1]['body']='<p>Updated second content</p>';
 $request=new WP_REST_Request('PATCH',$route);$request->set_param('meta_all',['acf'=>['nova_test_matrix'=>$next]]);
 $result=rest_do_request($request);
 nova_matrix_assert($result->get_status()===200,'Complete matrix write failed: '.wp_json_encode($result->get_data()));
 $after=Nova_Bridge_Suite_Content_Transport::read_fields(get_post($id))['acf']['nova_test_matrix'];
 nova_matrix_assert($after===$next,'Matrix write failed to preserve siblings, ordering or layout markers');
 echo "PASS live ACF matrix inventory, native REST fallback, GET/PATCH contract and sibling preservation\n";
} finally {
 if (is_int($id) && $id) { wp_delete_post($id,true); }
 if (function_exists('acf_remove_local_field_group')) { acf_remove_local_field_group($group); }
 wp_set_current_user($old_user);
}
