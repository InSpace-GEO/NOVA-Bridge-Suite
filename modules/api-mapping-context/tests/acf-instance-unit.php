<?php
define('ABSPATH', __DIR__);
function wp_json_encode($value) { return json_encode($value); }
function wp_strip_all_tags($value) { return strip_tags($value); }
require dirname(__DIR__) . '/includes/class-nova-bridge-suite-strategy.php';
$field = ['type'=>'flexible_content','layouts'=>[
 ['name'=>'copy','label'=>'Copy','sub_fields'=>[
  ['name'=>'body','key'=>'field_body','label'=>'Body','type'=>'wysiwyg'],
  ['name'=>'color','key'=>'field_color','type'=>'color_picker'],
  ['name'=>'items','type'=>'repeater','sub_fields'=>[['name'=>'title/line','key'=>'field_title','type'=>'text']]]
 ]],
 ['name'=>'photo','label'=>'Photo','sub_fields'=>[['name'=>'image','type'=>'image']]]
]];
$values=[['acf_fc_layout'=>'copy','body'=>'<p>Distinct article &amp; content</p>','color'=>'#fff','items'=>[['title/line'=>'First nested title']]],['acf_fc_layout'=>'photo','image'=>12],['acf_fc_layout'=>'copy','body'=>'Second article copy','items'=>[]]];
$parent=['path'=>'/meta_all/acf/matrix','request_path'=>'/meta_all/acf/matrix','route'=>'/nova-bridge/v1/content/pages/{id}','writable'=>true,'source'=>'acf'];
$fields=[];$method=new ReflectionMethod(Nova_Bridge_Suite_Strategy::class,'acf_instance_fields');
$method->invokeArgs(null,[&$fields,$field,$values,$parent['path'],$parent,'Matrix']);
function check($ok) { if (!$ok) { throw new RuntimeException('ACF instance regression failed'); } }
check(count($fields)===4);
$body=$fields['/meta_all/acf/matrix/0/body'];
check($body['preview_text']==='Distinct article & content');
check($body['request_path']==='/meta_all/acf/matrix' && $body['write_mode']==='complete_parent');
check($fields['/meta_all/acf/matrix/0/items/0/title~1line']['current_value']==='First nested title');
check($fields['/meta_all/acf/matrix/1/image']['current_value']==='12');
check(!isset($fields['/meta_all/acf/matrix/0/color']) && !isset($fields['/meta_all/acf/matrix/1/body']));
check(str_contains($body['native_description'],'COMPLETE parent') && str_contains($body['native_description'],'acf_fc_layout'));
echo "PASS concrete flexible rows, nested repeater, escaping, values, active layouts and whole-parent contracts\n";
