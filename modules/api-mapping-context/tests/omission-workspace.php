<?php
/** wp eval-file: intercept option writes to verify omission end-to-end without site mutations. */
if (!defined('WP_CLI') || !WP_CLI) { exit(1); }
$original_user = get_current_user_id(); $captured = null;
$capture = static function($value, $old) use (&$captured) { $captured = $value; return $old; };
$read = static function($value) use (&$captured) { return $captured ?? $value; };
$assert = static function($ok, $message) { if (!$ok) { throw new RuntimeException($message); } };
try {
 $admins=get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']); wp_set_current_user((int)$admins[0]); rest_get_server();
 $original=Nova_Bridge_Suite_Strategy::get_option(); $profile=reset($original['profiles']);
 $assert($profile && $profile['reference_type']==='post','Requires an existing post layout profile.');
 $profile['fields']['/excerpt']=['mapping'=>'leave_empty','description'=>'Conflicting fill instruction'];
 add_filter('pre_update_option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$capture,999,2);
 add_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$read,999);
 $request=new WP_REST_Request('POST','/nova-bridge/v1/strategy/profiles'); $request->set_header('content-type','application/json'); $request->set_body(wp_json_encode($profile));
 $response=rest_do_request($request); $assert($response->get_status()===200,'Profile save accepts explicit omission.');
 $assert($captured['profiles'][$profile['signature']]['fields']['/excerpt']['mapping']==='leave_empty','Omission persists in profile data.');
 $contract=Nova_Bridge_Suite_Strategy::contract_for_url(get_permalink($profile['reference_id']));
 $assert(!is_wp_error($contract) && in_array('/excerpt',$contract['nova_omit_fields'],true),'Strategy contract exports omission.');
 $assert(!in_array('/excerpt',array_column($contract['field_contracts'],'path'),true),'Omitted field is excluded from write contracts.');
 $decorated=Nova_Bridge_Suite_Strategy::decorate_record(['nova_content_mappings'=>['/excerpt'=>'content']],'post',$profile['reference_id']);
 $assert($decorated['nova_content_mappings']['/excerpt']==='leave_empty' && in_array('/excerpt',$decorated['nova_omit_fields'],true),'Profile omission overrides endpoint defaults.');
 $assert(strpos($decorated['meta_descriptions']['/excerpt'],'Omit the key entirely')!==false,'Omission overrides conflicting guidance.');
 WP_CLI::success('PASS 6 omission save and publishing-context checks (no site mutations).');
} finally {
 remove_filter('pre_update_option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$capture,999); remove_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$read,999); wp_set_current_user($original_user);
}
