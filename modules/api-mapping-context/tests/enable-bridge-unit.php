<?php
/** Run: php enable-bridge-unit.php. Isolated settings and permission checks. */
define('ABSPATH', __DIR__);
define('NOVA_BRIDGE_SUITE_OPTION', 'suite');
class WP_Error { public $code, $message, $data; public function __construct($code, $message, $data = []) { $this->code = $code; $this->message = $message; $this->data = $data; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function current_user_can($cap) { return $GLOBALS['admin']; }
function is_user_logged_in() { return $GLOBALS['admin']; }
function nova_bridge_suite_get_module_conflict($key) { return $GLOBALS['conflict']; }
function get_option($key, $default = []) { return $GLOBALS['settings']; }
function update_option($key, $value) { $GLOBALS['writes']++; $GLOBALS['settings'] = $value; }
function nova_bridge_suite_get_settings() { return $GLOBALS['settings']; }
function rest_ensure_response($v) { return $v; }
require dirname(__DIR__) . '/includes/class-nova-bridge-suite-strategy.php';
$admin = false; $conflict = ''; $writes = 0; $settings = ['unrelated' => 7, 'pagebuilder_elementor' => 0];
$call = static function($builder) { return Nova_Bridge_Suite_Strategy::enable_bridge_response(new class($builder) { private $builder; public function __construct($builder) { $this->builder = $builder; } public function get_param($key) { return $this->builder; } }); };
$check = static function($ok) { if (!$ok) { throw new RuntimeException('Bridge enable check failed'); } };
$check(is_wp_error($call('gutenberg')) && $writes === 0);
$admin = true;
foreach (['unknown', 'cpt', ['gutenberg'], null] as $invalid) { $check(is_wp_error($call($invalid)) && $writes === 0); }
$conflict = 'Standalone plugin';
$check(is_wp_error($call('gutenberg')) && $writes === 0);
$conflict = '';
foreach (['gutenberg', 'elementor', 'beaver', 'wpbakery', 'divi', 'avada', 'breakdance'] as $builder) {
 $before = $settings;
 $check($call($builder)['enabled'] === true);
 $key = $builder === 'gutenberg' ? 'gutenberg_bridge' : 'pagebuilder_' . $builder;
 $before[$key] = 1;
 $check($settings === $before);
}
$before = $settings; $check($call('gutenberg')['enabled'] && $settings === $before);
echo "PASS bridge permissions, allowlist, conflicts, seven builders, settings preservation and repeat enable\n";
