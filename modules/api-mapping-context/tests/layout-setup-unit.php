<?php
/** Navigation, saved-reference permissions and local-only onboarding checks. */
define('ABSPATH', __DIR__);
define('NOVA_BRIDGE_SUITE_PLUGIN_DIR', dirname(__DIR__, 3) . '/');
define('NOVA_BRIDGE_SUITE_PLUGIN_FILE', NOVA_BRIDGE_SUITE_PLUGIN_DIR . 'nova-bridge-suite.php');
define('NOVA_BRIDGE_SUITE_VERSION', '3.0.0');
define('ARRAY_A', 'ARRAY_A');
$filters=[]; $actions=[]; $administrator=true; $woken=0;
function add_filter($name,$callback,$priority=10){global $filters;$filters[$name][$priority][]=$callback;}
function add_action($name,$callback,$priority=10,$accepted=1){global $actions;$actions[$name][$priority][]=$callback;}
function apply_filters($name,$value){global $filters;$callbacks=$filters[$name]??[];ksort($callbacks);foreach($callbacks as $group){foreach($group as $callback){$value=call_user_func($callback,$value);}}return $value;}
function __($value,$domain=''){return $value;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function sanitize_text_field($value){return strip_tags($value);}
function wp_unslash($value){return $value;}
function esc_html($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_html__($value,$domain=''){return esc_html($value);}
function esc_attr($value){return htmlspecialchars($value,ENT_QUOTES);}
function esc_attr__($value,$domain=''){return esc_attr($value);}
function esc_url($value){return htmlspecialchars($value,ENT_QUOTES);}
function admin_url($path){return 'https://site.example/wp-admin/'.$path;}
function plugin_dir_url($file){return 'https://site.example/wp-content/plugins/nova-bridge-suite/';}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function current_user_can($cap,...$args){global $administrator;return $cap==='manage_options'?$administrator:($cap==='edit_post'&&($args[0]??0)===17);}
function get_post($id){return $id===17?(object)['post_title'=>'Existing article layout']:false;}
function get_term($id){return false;}
function is_wp_error($value){return false;}
function is_serialized($value){return preg_match('/^[aOs]:/',$value)===1;}
class UnsafeSavedObject {function __wakeup(){global $woken;++$woken;}}
class Nova_Bridge_Suite_Content_Rules {static function profiles(){return [['id'=>'11111111-1111-4111-8111-111111111111','label'=>'Article rules','revision'=>2]];}}
class Nova_Bridge_Suite_Posting_Settings {static function connection(){return ['enabled'=>false,'paused'=>true];}static function render(){echo '<div>Connection settings form</div>';}}
function fixture_render(){echo '<section>INNER VIEW</section>';}
function fixture_tabs($tabs){return ['strategy-mapping'=>['label'=>'Fill existing layout','render_callback'=>'fixture_render','legacy_slugs'=>['mapping']],'content-rules'=>['label'=>'Build new content','render_callback'=>'fixture_render']]+$tabs;}
class LayoutFakeDb {
    public $options='wp_options'; public $rows=[];
    function prepare($sql,...$args){return $sql;}
    function esc_like($value){return addcslashes($value,'_%');}
    function get_results($sql,$mode){return $this->rows;}
}
$wpdb=new LayoutFakeDb();
foreach([17,18] as $id){$draft=['schema_version'=>1,'reference_type'=>'post','reference_id'=>$id,'label'=>'Saved '.$id];$wpdb->rows[]=['option_name'=>'nova_mapping_draft_'.hash('sha256','post:'.$id),'option_value'=>serialize($draft)];}
$wpdb->rows[]=['option_name'=>'nova_mapping_draft_'.hash('sha256','unsafe'),'option_value'=>serialize(new UnsafeSavedObject())];
require NOVA_BRIDGE_SUITE_PLUGIN_DIR.'includes/settings.php';
add_filter('nova_bridge_suite_settings_tabs','fixture_tabs',100);
$checks=0;
function check($ok,$message){global $checks;++$checks;if(!$ok){throw new RuntimeException($message);}}
$tabs=nova_bridge_suite_get_settings_tabs();
check(array_keys($tabs)===['layouts','nova-connection','strategy-mapping','content-rules','modules'],'Shared top-level navigation lost a route');
check($tabs['strategy-mapping']['nav_parent']==='layouts'&&$tabs['content-rules']['nav_parent']==='layouts','Mode routes were not kept below Layouts');
Nova_Bridge_Suite_Layout_Setup_Admin::bootstrap();
check(count($filters['nova_bridge_suite_settings_tabs'][102])===1,'Shared shell registered twice');
$saved=Nova_Bridge_Suite_Layout_Setup_Admin::saved_existing();
check(count($saved)===1&&$saved[0]['reference_id']===17,'Saved setups leaked an inaccessible reference or malformed record');
check($woken===0,'Saved setup projection instantiated an arbitrary serialized object');
$_GET=['lang'=>'nl'];
check(strpos(Nova_Bridge_Suite_Layout_Setup_Admin::url('content-rules',['view'=>'workspace']),'lang=nl')!==false,'Saved setup links lost the selected language');
ob_start();nova_bridge_suite_render_settings_page();$html=ob_get_clean();
check(strpos($html,'How should your articles fit your website?')!==false,'The default settings route skipped guided approach selection');
check(strpos($html,'name="new" value="1"')!==false&&strpos($html,'name="view" value="setup"')!==false,'Starting setup did not explicitly choose a new guided journey');
check(strpos($html,'No source page or separate field mapping is needed.')!==false,'The two approaches still appeared dependent');
check(strpos($html,'profile_id=11111111-1111-4111-8111-111111111111')!==false&&strpos($html,'reference_id=17')!==false,'Saved workspaces lost their identities');
check(strpos($html,'reference_id=18')===false,'An inaccessible saved-reference link was rendered');
$_GET=['tab'=>'content-rules'];ob_start();nova_bridge_suite_render_settings_page();$html=ob_get_clean();
check(strpos($html,'INNER VIEW')!==false,'Old mode URL stopped rendering its editor');
check(strpos($html,'tab=layouts')!==false&&strpos($html,'nav-tab-active')!==false,'Mode URL failed to highlight its Layouts parent');
check(strpos($html,'>Build new content</a>')===false,'Hidden child route still appeared as another competing main tab');
$_GET=['tab'=>'mapping'];ob_start();nova_bridge_suite_render_settings_page();$html=ob_get_clean();
check(strpos($html,'INNER VIEW')!==false,'Legacy mapping URL did not resolve');
$administrator=false;ob_start();Nova_Bridge_Suite_Layout_Setup_Admin::render();$html=ob_get_clean();
check($html==='','Non-administrator could render saved layout setup information');
echo 'PASS '.$checks." guided layout navigation and saved-reference checks.\n";
