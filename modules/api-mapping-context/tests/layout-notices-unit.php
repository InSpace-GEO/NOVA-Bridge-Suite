<?php
/** Only unrelated content-owned notice callbacks are suppressed, on the exact NOVA screen. */
define('ABSPATH', __DIR__);
define('NOVA_BRIDGE_SUITE_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir());
$screen = (object)['id' => 'settings_page_nova-settings'];
$administrator = true; $removed = []; $checks = 0;
function get_current_screen(){global $screen; return $screen;}
function current_user_can($cap){global $administrator; return $administrator;}
function wp_normalize_path($path){return str_replace('\\', '/', $path);}
function remove_action($hook,$callback,$priority){global $removed; $removed[]=[$hook,$callback,$priority];}
function own_notice(){}
function check($ok,$message){global $checks; ++$checks; if(!$ok){throw new RuntimeException($message);}}
require NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'modules/api-mapping-context/includes/class-nova-bridge-suite-layout-setup-admin.php';
$fixture = tempnam(sys_get_temp_dir(), 'nova-notices-');
if (!$fixture) {throw new RuntimeException('Cannot create notice fixture');}
try {
    file_put_contents($fixture, '<?php return [function(){throw new RuntimeException("Should never execute a notice");}, new class {static function notice(){} function __invoke(){}}];');
    $foreign = require $fixture;
    $entries = [
        ['function' => $foreign[0], 'accepted_args' => 0],
        ['function' => [$foreign[1], 'notice'], 'accepted_args' => 0],
        ['function' => $foreign[1], 'accepted_args' => 0],
        ['function' => 'own_notice', 'accepted_args' => 0],
        ['function' => 'strlen', 'accepted_args' => 1],
    ];
    $wp_filter = ['admin_notices' => (object)['callbacks' => [10 => $entries]], 'all_admin_notices' => (object)['callbacks' => [7 => [['function' => $foreign[0], 'accepted_args' => 0]]]]];
    Nova_Bridge_Suite_Layout_Setup_Admin::quiet_external_notices();
    check(count($removed) === 4, 'Foreign closure, method, invokable and all-admin notice were not scoped out');
    check(!array_filter($removed, function($entry){return $entry[1] === 'own_notice' || $entry[1] === 'strlen';}), 'NOVA or core callback removed');
    check($removed[3][0] === 'all_admin_notices' && $removed[3][2] === 7, 'Notice hook or priority changed');
    $removed=[]; $screen->id='dashboard';
    Nova_Bridge_Suite_Layout_Setup_Admin::quiet_external_notices();
    check(!$removed, 'Another WordPress screen lost notices');
    $screen->id='settings_page_nova-settings'; $administrator=false;
    Nova_Bridge_Suite_Layout_Setup_Admin::quiet_external_notices();
    check(!$removed, 'Non-administrator triggered notice filtering');
    $administrator=true; $screen=null;
    Nova_Bridge_Suite_Layout_Setup_Admin::quiet_external_notices();
    check(!$removed, 'Notice filter ran without a screen');
} finally {unlink($fixture);}
echo 'PASS '.$checks." scoped notice checks.\n";
