<?php
/** Standalone preview lifecycle, authentication and URL regression checks. */
define('ABSPATH', __DIR__);
$hooks = []; $logged = true; $admin = true; $entity = true;
function add_action($hook, $callback, $priority = 10) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter(...$args) {}
function remove_action(...$args) {}
function nocache_headers() {}
function is_user_logged_in() { return $GLOBALS['logged']; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function wp_verify_nonce($nonce, $action) { return $nonce === $action; }
function wp_create_nonce($action) { return $action; }
function wp_die($message, $title, $args) { throw new RuntimeException($message, $args['response']); }
function sanitize_key($s) { return $s; }
function wp_unslash($s) { return $s; }
function absint($v) { return abs((int)$v); }
function wp_parse_url($url) { return parse_url($url); }
function admin_url() { return 'https://example.test/wp-admin/'; }
function get_post_status($id) { return 'publish'; }
function wp_generate_uuid4() { static $n = 0; return 'request-' . ++$n; }
function add_query_arg($args, $url) { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args); }
class Nova_Bridge_Suite_Strategy { static function entity($type, $id) { return $GLOBALS['entity']; } }
require dirname(__DIR__) . '/includes/class-nova-bridge-suite-mapping-preview.php';
function check($ok) { if (!$ok) { throw new RuntimeException('Preview regression failed'); } }
Nova_Bridge_Suite_Mapping_Preview::bootstrap();
check($hooks['wp_loaded'][0][1] === 'prepare' && $hooks['init'][0][1] === 'no_cache');
$_GET = ['nova_mapping_preview'=>'post', 'nova_reference'=>1, '_nova_nonce'=>'nova_mapping_preview:post:1'];
Nova_Bridge_Suite_Mapping_Preview::prepare();
foreach (['logged'=>[401,'login session'], 'admin'=>[403,'Administrator'], 'entity'=>[403,'reference']] as $key=>$expect) {
 $GLOBALS[$key] = false;
 try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Access unexpectedly allowed'); }
 catch (RuntimeException $e) { check($e->getCode() === $expect[0] && str_contains($e->getMessage(), $expect[1])); }
 $GLOBALS[$key] = true;
}
$_GET['_nova_nonce'] = 'bad';
try { Nova_Bridge_Suite_Mapping_Preview::prepare(); throw new RuntimeException('Invalid nonce allowed'); }
catch (RuntimeException $e) { check($e->getCode() === 403 && str_contains($e->getMessage(), 'Refresh preview')); }
$ref = ['reference_type'=>'post', 'reference_id'=>1, 'url'=>'http://www.example.test/page/?x=1'];
$first = Nova_Bridge_Suite_Mapping_Preview::url($ref);
check(str_starts_with($first, 'https://example.test/page/?x=1&'));
check($first !== Nova_Bridge_Suite_Mapping_Preview::url($ref));
$ref['url'] = 'https://other.test/page/';
check(str_starts_with(Nova_Bridge_Suite_Mapping_Preview::url($ref), $ref['url']));
echo "PASS preview lifecycle, authentication, nonce, reference permissions, origin and fresh URLs\n";
