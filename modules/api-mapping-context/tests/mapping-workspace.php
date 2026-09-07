<?php
/** Read-only live WordPress checks: wp eval-file this-file.php. No option/content writes. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit(1); }
$old_user = get_current_user_id();
$checks = 0;
$assert = static function( $condition, $message ) use ( &$checks ) { if ( ! $condition ) { throw new RuntimeException( $message ); } ++$checks; };
$call = static function( $route, $params = [] ) { $q = new WP_REST_Request( 'GET', '/nova-bridge/v1/' . $route ); foreach( $params as $key => $value ) { $q->set_param( $key, $value ); } return rest_do_request( $q ); };
try {
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	wp_set_current_user( (int) $admins[0] ); rest_get_server();
	$expected_modules = nova_bridge_suite_get_targeted_rest_module_keys('nova-bridge/v1/content-endpoints');
	foreach(['nova-bridge/v1/mapping','nova-bridge/v1/mapping/layout','nova-bridge/v1/strategy','nova-bridge/v1/strategy/profiles','nova-bridge/v1/strategy/context'] as $route) { $assert(nova_bridge_suite_get_targeted_rest_module_keys($route) === $expected_modules, 'Mapping routes load the same enabled module dependencies as discovery.'); }
	$all = $call( 'mapping' ); $assert( 200 === $all->get_status(), 'All-site inventory succeeds.' ); $all = $all->get_data();
	$assert( 'all' === $all['scope'], 'Default scope includes the entire eligible site.' );
	$assert( count( $all['layouts'] ) === $all['summary']['site_layouts'], 'Every site layout is in the default queue.' );
	$assert( count( $all['references'] ) === array_sum( array_map( static function( $l ){ return count($l['members']); }, $all['layouts'] ) ), 'Each eligible content item belongs to one layout.' );
	$assert( 400 === $call( 'mapping', [ 'scope' => 'invalid' ] )->get_status(), 'Unknown scopes are rejected.' );
	$strategy = $call( 'mapping', [ 'scope' => 'strategy' ] )->get_data();
	foreach( $strategy['layouts'] as $layout ) { $assert( count($layout['rows']) > 0, 'Scoped layouts have strategy rows.' ); }
	$none = static function( $value ) { $value['rows'] = []; $value['assignments'] = []; return $value; };
	add_filter( 'option_' . Nova_Bridge_Suite_Strategy::OPTION_NAME, $none );
	try {
		$empty_all = $call('mapping')->get_data(); $empty_scope = $call('mapping', ['scope'=>'strategy'])->get_data();
		$assert( count($empty_all['layouts']) === count($all['layouts']), 'No import still returns all site layouts.' );
		$assert( [] === $empty_scope['layouts'], 'No import returns an empty strategy scope.' );
	} finally { remove_filter('option_' . Nova_Bridge_Suite_Strategy::OPTION_NAME, $none); }
	if( $all['layouts'] ) {
		$l = $all['layouts'][0]; $params = ['reference_type'=>$l['reference_type'],'reference_id'=>$l['reference_id'],'signature'=>$l['signature']];
		$detail = $call('mapping/layout',$params); $assert(200 === $detail->get_status(),'Selected layout fields load.'); $detail=$detail->get_data();
		$assert(isset($detail['fields'],$detail['providers'],$detail['unbound_fields']),'Detail includes fields and completeness diagnostics.');
		parse_str((string)wp_parse_url($detail['preview_url'],PHP_URL_QUERY),$query);
		$assert(wp_verify_nonce($query['_nova_nonce'] ?? '', 'nova_mapping_preview:'.$l['reference_type'].':'.$l['reference_id']), 'Preview nonce is bound to the reference and user.');
		$assert(!wp_verify_nonce($query['_nova_nonce'] ?? '', 'nova_mapping_preview:post:0'), 'Preview nonce cannot select a different reference.');
		$params['signature']='stale'; $assert(409 === $call('mapping/layout',$params)->get_status(),'Changed layouts require refresh.');
	}

	foreach( $all['references'] as $reference ) {
		if( 'page' !== $reference['post_type'] ) { continue; }
		$parsed = Nova_Bridge_Suite_Strategy::parse_import(['urls'=>[home_url('/nova-qa-absent-parent-'.wp_rand().'/child/')]]);
		$fp = Nova_Bridge_Suite_Strategy::fingerprint($reference);
		$parent_case = static function($value) use($parsed,$fp,$reference) {
			$value['rows']=$parsed['rows']; $value['assignments']=[$parsed['rows'][0]['id']=>['reference_type'=>'post','reference_id'=>$reference['reference_id'],'signature'=>$fp['signature']]]; return $value;
		};
		add_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$parent_case);
		try {
			$case=$call('mapping',['scope'=>'strategy'])->get_data();
			$assert('needs_parent' === $case['rows'][0]['status'],'Missing publishing parent has its own status.');
			$assert(1 === count($case['layouts']) && [] === $case['unresolved'],'A missing parent does not hide a selected layout.');
		} finally { remove_filter('option_'.Nova_Bridge_Suite_Strategy::OPTION_NAME,$parent_case); }
		break;
	}

	foreach( $all['layouts'] as $layout ) {
		if( ! in_array('gutenberg',$layout['builders'],true) ) { continue; }
		$q=new WP_REST_Request('GET');$q->set_param('post_id',$layout['reference_id']);
		$bridge=Nova_Bridge_Suite_Content_Context::get_bridge_fields_response($q)->get_data();
		$provider=array_values(array_filter($bridge['providers'],static function($p){return $p['id']==='gutenberg';}))[0];
		if(!defined('NOVA_GUT_PLUGIN_DIR')) {
			$assert(!$provider['available'],'Disabled Gutenberg bridge is reported unavailable.');
			$assert(isset($provider['module_key'],$provider['module_enabled'],$provider['message']),'Unavailable provider includes actionable module metadata.');
			$assert(!array_filter($bridge['fields'],static function($f){return $f['builder']==='gutenberg';}),'Disabled Gutenberg does not advertise a fabricated bridge write route.');
		} else {
			$assert($provider['available'],'Loaded Gutenberg document inspection remains available.');
		}
		break;
	}
	$tabs = nova_bridge_suite_get_settings_tabs();
	$assert(isset($tabs['strategy-mapping']) && !isset($tabs['api-mapping-context']), 'Only one mapping tab remains.');
	$assert(in_array('api-mapping-context',$tabs['strategy-mapping']['legacy_slugs'],true), 'Legacy bookmarks remain supported.');
	wp_set_current_user(0);
	$assert(401 === $call('mapping')->get_status(), 'Anonymous inventory access denied.');
	$assert(401 === $call('mapping/layout',['reference_type'=>'post','reference_id'=>1])->get_status(), 'Anonymous fields access denied.');
} finally { wp_set_current_user($old_user); }
WP_CLI::success('PASS '.$checks.' mapping workspace checks (no site mutations).');
