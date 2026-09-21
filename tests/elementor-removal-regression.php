<?php
/** wp eval-file tests/elementor-removal-regression.php /path/to/aceg-get.json */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
$checks = 0;
$ids = array();
$assert = static function ( $ok, $message ) use ( &$checks ) {
	if ( ! $ok ) { throw new RuntimeException( $message ); }
	$checks++;
};
$request = static function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $body ) ); }
	return rest_do_request( $r );
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$find = function ( array $nodes, $id ) use ( &$find ) {
	foreach ( $nodes as $n ) {
		if ( $n['id'] === $id ) { return $n; }
		if ( ! empty( $n['elements'] ) ) { $found = $find( $n['elements'], $id ); if ( $found ) { return $found; } }
	}
	return null;
};
$read = static function ( $id ) { return json_decode( get_post_meta( $id, '_elementor_data', true ), true ); };
$create = static function ( array $body ) use ( $request, $assert, &$ids ) {
	$r = $request( 'POST', '/seor-bridge/v1/pages', array_merge( array( 'title' => 'NOVA 2.8.8 test ' . wp_generate_uuid4(), 'status' => 'draft', 'post_type' => 'post' ), $body ) );
	$assert( 201 === $r->get_status(), 'Create failed: ' . wp_json_encode( $r->get_data() ) );
	$id = (int) $r->get_data()['post_id']; $ids[] = $id; return $id;
};
try {
	$fixture = json_decode( file_get_contents( $args[0] ), true );
	$assert( is_array( $fixture['document'] ), 'ACEG fixture required.' );
	$settings = array( 'hide_title' => 'yes', 'post_status' => 'draft' );
	$source = $create( array( 'elementor_data' => $fixture['document'], 'elementor_page_settings' => $settings, 'template' => 'elementor_header_footer' ) );
	$original = $read( $source );
	$descriptor = $request( 'GET', '/seor-bridge/v1/pages/' . $source )->get_data();
	$assert( array( 'remove_elements', 'remove_accordion_items' ) === $descriptor['capabilities'], 'Removal capabilities missing from GET.' );
	$accordion = $find( $original, '49d97476' );
	$assert( 13 === count( $accordion['settings']['items'] ) && 13 === count( $accordion['elements'] ), 'Expected 13 paired source items.' );
	$assert( $settings === get_post_meta( $source, '_elementor_page_settings', true ), 'Primary Hide Title not persisted.' );
	$unchanged = $create( array( 'source_page_id' => $source, 'fields' => array(), 'remove_elements' => array(), 'remove_accordion_items' => array() ) );
	$assert( $original === $read( $unchanged ), 'Empty/omitted operations must preserve the whole layout.' );
	$changes = array( array( 'field_key' => '49d97476|items.0.item_title', 'value' => 'Unique retained question' ), array( 'field_key' => '44d5eda9|editor', 'value' => '<p>Unique retained answer</p>' ) );
	$five = $create( array( 'source_page_id' => $source, 'fields' => $changes, 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => range( 5, 12 ) ) ) ) );
	$after = $find( $read( $five ), '49d97476' );
	$assert( 5 === count( $after['settings']['items'] ) && 5 === count( $after['elements'] ), '13-to-5 removal must trim questions AND answers.' );
	$assert( 'Unique retained question' === $after['settings']['items'][0]['item_title'], 'Question update lost.' );
	$assert( '<p>Unique retained answer</p>' === $find( $after['elements'], '44d5eda9' )['settings']['editor'], 'Answer update lost.' );
	$assert( $after['settings']['items'][4]['_id'] === $accordion['settings']['items'][4]['_id'], 'Kept item ID changed.' );
	$assert( $after['elements'][4] === $accordion['elements'][4], 'Kept answer/container changed.' );
	$assert( $find( $read( $five ), '4b9921e0' ) === $find( $original, '4b9921e0' ), 'Unrelated contact button changed.' );
	$html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $five );
	$assert( false !== strpos( $html, 'Unique retained question' ) && false === strpos( $html, 'Wat houdt een elektrische keuring in?' ), 'Rendered output retains a deleted FAQ.' );
	$zero = $create( array( 'source_page_id' => $source, 'remove_elements' => array( '26dadb63', '49d97476' ) ) );
	$assert( null === $find( $read( $zero ), '49d97476' ) && null === $find( $read( $zero ), '26dadb63' ), 'Zero FAQs must remove heading and accordion.' );
	$assert( null === $find( $read( $zero ), '44d5eda9' ), 'Deleted accordion retained child content.' );
	$assert( $find( $read( $zero ), '6d3e91f3' ) === $find( $original, '6d3e91f3' ), 'Removing FAQs changed main content.' );
	// Editing a later row before deleting earlier rows must not shift the target.
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $unchanged, array( 'fields' => array( array( 'field_key' => '49d97476|items.5.item_title', 'value' => 'Edited original row five' ) ), 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => array( 3, 1, 3 ) ) ) ) );
	$assert( 200 === $r->get_status(), 'Noncontiguous update failed.' );
	$edited = $find( $read( $unchanged ), '49d97476' );
	$assert( 11 === count( $edited['settings']['items'] ) && 'Edited original row five' === $edited['settings']['items'][3]['item_title'], 'Indices shifted before edits or duplicates removed extra rows.' );
	$assert( $edited['elements'][3]['id'] === $accordion['elements'][5]['id'], 'Noncontiguous removal mismatched answer.' );
	// Direct document replacement must still run removals without any fields.
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $unchanged, array( 'elementor_data' => $original, 'remove_elements' => array( '26dadb63', '49d97476' ) ) );
	$assert( 200 === $r->get_status() && null === $find( $read( $unchanged ), '49d97476' ), 'Direct document replacement bypassed removals.' );
	$before = get_post_meta( $five, '_elementor_data', true );
	foreach ( array(
		array( 'remove_elements' => array( 'unknown' ) ),
		array( 'remove_elements' => array( $after['elements'][0]['id'] ) ),
		array( 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => array( 99 ) ) ) ),
		array( 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => array( -1 ) ) ) ),
		array( 'remove_accordion_items' => array( array( 'element_id' => '26dadb63', 'indices' => array( 0 ) ) ) ),
		array( 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => range( 0, 4 ) ) ) )
	) as $invalid ) {
		$r = $request( 'POST', '/seor-bridge/v1/pages/' . $five, $invalid );
		$assert( $r->get_status() >= 400 && $before === get_post_meta( $five, '_elementor_data', true ), 'Invalid removal changed the document.' );
	}
	// Prove the pre-existing repeater replacement behavior that other clients use.
	$tabs = array();
	for ( $i = 0; $i < 8; $i++ ) { $tabs[] = array( '_id' => 'tab' . $i, 'tab_title' => 'Question ' . $i, 'tab_content' => '<p>Answer ' . $i . '</p>' ); }
	$classic_doc = array( array( 'id' => 'classicwrap', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'classicfaq', 'elType' => 'widget', 'widgetType' => 'accordion', 'settings' => array( 'tabs' => $tabs ), 'elements' => array() ) ) ) );
	$classic = $create( array( 'elementor_data' => $classic_doc ) );
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $classic, array( 'fields' => array( array( 'field_key' => 'classicfaq|tabs', 'value' => array_slice( $tabs, 0, 3 ) ) ) ) );
	$assert( 200 === $r->get_status() && 3 === count( $find( $read( $classic ), 'classicfaq' )['settings']['tabs'] ), 'Existing complete repeater replacement regressed.' );
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $classic, array( 'remove_accordion_items' => array( array( 'element_id' => 'classicfaq', 'indices' => array( 1 ) ) ) ) );
	$assert( 200 === $r->get_status() && 2 === count( $find( $read( $classic ), 'classicfaq' )['settings']['tabs'] ), 'Classic accordion removal failed.' );
	// Exercise the real Polylang create + Elementor translation update path.
	$languages = pll_languages_list( array( 'fields' => 'slug' ) );
	$assert( count( $languages ) >= 2, 'Two Polylang languages required.' );
	pll_set_post_language( $five, $languages[0] );
	$r = $request( 'POST', '/polylang-translations/v1/posts', array( 'source_post_id' => $five, 'translations' => array( array( 'language' => $languages[1], 'title' => 'NOVA 2.8.8 translation ' . wp_generate_uuid4(), 'status' => 'draft', 'content' => '<p>Translated test.</p>' ) ) ) );
	$registered = $r->get_data();
	$assert( $r->get_status() < 300 && empty( $registered['errors'] ) && ! empty( $registered['results'][0]['translation_id'] ), 'Polylang creation failed: ' . wp_json_encode( $registered ) );
	$translation = (int) $registered['results'][0]['translation_id']; $ids[] = $translation;
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $translation, array( 'source_page_id' => $five, 'template' => 'elementor_header_footer', 'elementor_page_settings' => array( 'hide_title' => 'yes' ), 'fields' => array( array( 'field_key' => '50dcdefe|title', 'value' => 'Translated heading' ) ) ) );
	$assert( 200 === $r->get_status(), 'Translation Elementor update failed.' );
	$assert( 'yes' === get_post_meta( $translation, '_elementor_page_settings', true )['hide_title'], 'Translated Hide Title missing.' );
	$assert( 'elementor_header_footer' === get_page_template_slug( $translation ), 'Translated template missing.' );
	$assert( 5 === count( $find( $read( $translation ), '49d97476' )['settings']['items'] ), 'Translation resurrected deleted FAQs.' );
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $translation, array( 'elementor_data' => $read( $five ), 'elementor_page_settings' => array( 'hide_title' => 'yes' ), 'remove_accordion_items' => array( array( 'element_id' => '49d97476', 'indices' => array( 4 ) ) ) ) );
	$assert( 200 === $r->get_status() && 4 === count( $find( $read( $translation ), '49d97476' )['settings']['items'] ), 'Existing translation update failed.' );
	$assert( $original === $read( $source ), 'Source template was modified.' );
	wp_set_current_user( 0 );
	$r = $request( 'POST', '/seor-bridge/v1/pages/' . $five, array( 'remove_elements' => array( '49d97476' ) ) );
	$assert( $r->get_status() >= 400 && $before === get_post_meta( $five, '_elementor_data', true ), 'Anonymous removal was accepted.' );
	echo wp_json_encode( array( 'passed' => $checks, 'version' => NOVA_BRIDGE_SUITE_VERSION, 'temporary_post_ids' => $ids ) ) . PHP_EOL;
} finally {
	wp_set_current_user( (int) $admins[0] );
	foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
	echo 'Temporary removal test posts removed.' . PHP_EOL;
}
