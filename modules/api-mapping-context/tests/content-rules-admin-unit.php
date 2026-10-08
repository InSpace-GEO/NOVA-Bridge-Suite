<?php
/** Standalone route/permission checks; no WordPress writes or external calls. */
define('ABSPATH',__DIR__);$allowed=true;$nonce_valid=true;$routes=[];
class WP_Error {public $code;public $message;public $data;function __construct($code,$message,$data=[]){$this->code=$code;$this->message=$message;$this->data=$data;}}
function current_user_can($cap){global $allowed;return $allowed&&$cap==='manage_options';}
function wp_verify_nonce($value,$action){global $nonce_valid;return $nonce_valid&&$value==='valid'&&$action==='wp_rest';}
function register_rest_route($namespace,$path,$handlers){global $routes;$routes[$namespace.$path]=$handlers;}
function __( $value,$domain ){return $value;}
function is_wp_error($value){return $value instanceof WP_Error;}
function rest_ensure_response($value){return new class($value){public $data;function __construct($value){$this->data=$value;}function header($name,$value){}};}
class Nova_Bridge_Suite_Posting_Settings {static function connection(){return ['site_id'=>'22222222-2222-4222-8222-222222222222'];}}
class Nova_Bridge_Suite_Content_Rules {static $type='book';static $bindings=0;static $unbindings=0;static function get($id){return ['id'=>$id,'revision'=>2,'post_type'=>self::$type];}static function bind($site,$id,$revision){self::$bindings++;return true;}static function unbind($site){self::$unbindings++;return true;}}
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
require dirname(__DIR__).'/includes/class-nova-bridge-suite-content-rules-admin.php';
$request=new class {function get_header($name){return $name==='x-wp-nonce'?'valid':'';}};
check(Nova_Bridge_Suite_Content_Rules_Admin::can_admin($request)===true,'Authorized administrator rejected');
$allowed=false;check(is_wp_error(Nova_Bridge_Suite_Content_Rules_Admin::can_admin($request)),'Non-administrator allowed');
$allowed=true;$nonce_valid=false;check(is_wp_error(Nova_Bridge_Suite_Content_Rules_Admin::can_admin($request)),'Missing or expired nonce allowed');
Nova_Bridge_Suite_Content_Rules_Admin::routes();check(count($routes)===5,'Expected five local route paths');
foreach($routes as $route=>$handlers){check(strpos($route,'nova-bridge/v1/mapping/content-rules/')===0,'Route bypasses mapping module loader');foreach($handlers as $handler){check($handler['permission_callback']===[Nova_Bridge_Suite_Content_Rules_Admin::class,'can_admin'],'Route lacks administrator/nonce gate');}}
$tabs=Nova_Bridge_Suite_Content_Rules_Admin::tab(['strategy-mapping'=>['label'=>'Mapping'],'modules'=>['label'=>'Modules']]);check(array_keys($tabs)===['strategy-mapping','content-rules','modules'],'Rule editor did not stay adjacent to Mapping');
$request=new class {public $enabled=true;function get_body(){return json_encode($this->get_json_params());}function get_json_params(){return ['site_id'=>'22222222-2222-4222-8222-222222222222','enabled'=>$this->enabled,'profile_id'=>'11111111-1111-4111-8111-111111111111','profile_revision'=>2];}};
$result=Nova_Bridge_Suite_Content_Rules_Admin::save_binding($request);check(is_wp_error($result)&&strpos($result->code,'delivery_post_type')!==false,'Custom post type delivery binding allowed');check(Nova_Bridge_Suite_Content_Rules::$bindings===0,'Unsupported binding reached the store');
$request->enabled=false;check(Nova_Bridge_Suite_Content_Rules_Admin::save_binding($request)->data===true&&Nova_Bridge_Suite_Content_Rules::$unbindings===1,'Custom post type could not turn delivery preference off');
$request->enabled=true;Nova_Bridge_Suite_Content_Rules::$type='post';check(Nova_Bridge_Suite_Content_Rules_Admin::save_binding($request)->data===true&&Nova_Bridge_Suite_Content_Rules::$bindings===1,'Supported post profile binding rejected');
echo "PASS content-rule admin permissions, nonce and local routes\n";
