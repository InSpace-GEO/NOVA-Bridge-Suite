<?php
/**
 * wp eval-file tests/elementor-faq-list-regression.php [path/to/faq-widget-source.json]
 * Uses temporary drafts and a process-local widget when the custom widget is absent.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }

$checks = 0;
$ids = array();
$check = static function ( $condition, $message ) use ( &$checks ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	$checks++;
};
$request = static function ( $method, $route, $body = null ) {
	$query = array();
	if ( false !== strpos( $route, '?' ) ) {
		list( $route, $query_string ) = explode( '?', $route, 2 );
		parse_str( $query_string, $query );
	}
	$r = new WP_REST_Request( $method, $route );
	$r->set_query_params( $query );
	if ( null !== $body ) {
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $r );
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$check( ! empty( $admins ), 'Administrator required.' );
wp_set_current_user( (int) $admins[0] );
$manager = \Elementor\Plugin::instance()->widgets_manager;
$fixture_registered = ! $manager->get_widget_types( 'faq_list_b80b19e2' );
if ( $fixture_registered ) {
	// Match the real widget's storage contract without installing a substitute widget.
	class NOVA_FAQ_List_Test_Widget extends \Elementor\Widget_Base {
		public function get_name() { return 'faq_list_b80b19e2'; }
		public function get_title() { return 'FAQ List Test'; }
		protected function register_controls() {
			$this->start_controls_section( 'faq_content', array( 'label' => 'FAQ' ) );
			$rows = new \Elementor\Repeater();
			$rows->add_control( 'question', array( 'type' => \Elementor\Controls_Manager::TEXT ) );
			$rows->add_control( 'answer', array( 'type' => \Elementor\Controls_Manager::WYSIWYG ) );
			$this->add_control( 'faq_items', array( 'type' => \Elementor\Controls_Manager::REPEATER, 'fields' => $rows->get_controls() ) );
			$this->end_controls_section();
		}
		protected function render() {
			foreach ( $this->get_settings_for_display( 'faq_items' ) as $row ) {
				echo '<details><summary>' . esc_html( $row['question'] ) . '</summary>' . wp_kses_post( $row['answer'] ) . '</details>';
			}
		}
	}
	$manager->register( new NOVA_FAQ_List_Test_Widget() );
}

try {
	$widgets = array();
	if ( ! empty( $args[0] ) ) {
		$widgets = json_decode( file_get_contents( $args[0] ), true );
	} else {
		$rows = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$rows[] = array( '_id' => 'row' . $i, 'question' => 'Original question ' . $i, 'answer' => '<p>Original answer ' . $i . '</p>' );
		}
		$widgets[] = array( 'id' => '86a933e', 'elType' => 'widget', 'widgetType' => 'faq_list_b80b19e2', 'settings' => array( 'faq_items' => $rows, '_padding' => array( 'unit' => 'px', 'top' => '20' ) ), 'elements' => array() );
	}
	$check( 1 === count( $widgets ) && 5 === count( $widgets[0]['settings']['faq_items'] ), 'Expected the five-row FAQ widget.' );
	$heading = array( 'id' => 'faqtitle', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Preserved FAQ heading', 'header_size' => 'h2' ), 'elements' => array() );
	$document = array( array( 'id' => 'faqwrap', 'elType' => 'container', 'settings' => array( 'content_width' => 'boxed' ), 'elements' => array( $heading, $widgets[0] ) ) );
	$source = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'NOVA FAQ regression source ' . wp_generate_uuid4() ), true );
	$check( ! is_wp_error( $source ), 'Source creation failed.' );
	$ids[] = $source;
	update_post_meta( $source, '_elementor_data', wp_slash( wp_json_encode( $document ) ) );
	update_post_meta( $source, '_elementor_edit_mode', 'builder' );
	update_post_meta( $source, '_elementor_template_type', 'page' );
	// Establish Elementor's canonical document shape before comparing clone preservation.
	$service = new \SEOR_Elementor_Bridge\Elementor_Service();
	$canonical = $service->update_page( $source, array( 'elementor_data' => $document ) );
	$check( ! is_wp_error( $canonical ), 'Could not normalize the fixture through Elementor.' );
	$source_raw = get_post_meta( $source, '_elementor_data', true );
	$document = json_decode( $source_raw, true );
	$get = $request( 'GET', '/seor-bridge/v1/pages/' . $source . '/fields' );
	$check( 200 === $get->get_status(), 'FAQ field discovery request failed.' );
	$fields = $get->get_data()['fields'];
	$faq = array_values( array_filter( $fields, static function ( $f ) { return '86a933e' === $f['element_id']; } ) );
	$check( 10 === count( $faq ), 'FAQ discovery must expose all ten question/answer fields (baseline bug).' );
	$changes = array();
	$expected = $document;
	foreach ( $faq as $f ) {
		$check( in_array( $f['key'], array( 'question', 'answer' ), true ), 'FAQ styling or row IDs exposed as content.' );
		$index = (int) $f['path'][1];
		$check( array( 'faq_items', (string) $index, $f['key'] ) === $f['path'], 'Incorrect nested FAQ selector.' );
		$value = 'question' === $f['key'] ? 'Nieuwe vraag ' . $index . ' — café?' : '<p>Antwoord ' . $index . ' met <strong>opmaak</strong>, één aanspreekpunt en 💡.</p><ul><li>Stap één</li></ul>';
		$changes[] = array( 'field_key' => $f['field_key'], 'value' => $value );
		$expected[0]['elements'][1]['settings']['faq_items'][$index][$f['key']] = $value;
	}
	$clone_response = $request( 'POST', '/seor-bridge/v1/pages', array( 'title' => 'NOVA FAQ regression clone ' . wp_generate_uuid4(), 'status' => 'draft', 'source_page_id' => $source, 'fields' => $changes ) );
	$check( 201 === $clone_response->get_status(), 'Clone failed: ' . wp_json_encode( $clone_response->get_data() ) );
	$clone = (int) $clone_response->get_data()['post_id'];
	$ids[] = $clone;
	$stored = json_decode( get_post_meta( $clone, '_elementor_data', true ), true );
	$check( $expected === $stored, 'Clone changed structure, style, IDs, siblings, or FAQ values.' );
	$check( $source_raw === get_post_meta( $source, '_elementor_data', true ), 'Cloning changed the source.' );
	$single = array( array( 'element_id' => '86a933e', 'path' => 'faq_items.2.answer', 'value' => '<p>Bijgewerkt <a href="https://example.com/">antwoord</a>.</p>' ) );
	$updated = $request( 'POST', '/seor-bridge/v1/pages/' . $clone, array( 'fields' => $single ) );
	$check( 200 === $updated->get_status(), 'Partial update failed: ' . wp_json_encode( $updated->get_data() ) );
	$expected[0]['elements'][1]['settings']['faq_items'][2]['answer'] = $single[0]['value'];
	$check( $expected === json_decode( get_post_meta( $clone, '_elementor_data', true ), true ), 'Partial update modified unrelated content.' );
	$read = $request( 'GET', '/seor-bridge/v1/pages/' . $clone . '/fields' )->get_data();
	$check( false !== strpos( wp_json_encode( $read ), 'Bijgewerkt' ), 'Saved answer missing from REST readback.' );
	$html = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $clone );
	$check( false !== strpos( $html, 'Nieuwe vraag 4' ) && false !== strpos( $html, '<strong>opmaak</strong>' ) && false !== strpos( $html, 'Bijgewerkt' ), 'Saved FAQ text/HTML missing from Elementor render.' );
	$raw_before = get_post_meta( $clone, '_elementor_data', true );
	$repeat = $request( 'POST', '/seor-bridge/v1/pages/' . $clone, array( 'fields' => $single ) );
	$check( 200 === $repeat->get_status() && $raw_before === get_post_meta( $clone, '_elementor_data', true ), 'Repeated update changed stored content.' );
	wp_set_current_user( 0 );
	$denied = $request( 'POST', '/seor-bridge/v1/pages/' . $clone, array( 'fields' => $changes ) );
	$check( $denied->get_status() >= 400 && $raw_before === get_post_meta( $clone, '_elementor_data', true ), 'Anonymous write was accepted.' );
	echo wp_json_encode( array( 'passed' => $checks, 'widget' => 'faq_list_b80b19e2', 'rows' => 5, 'process_local_widget_fixture' => $fixture_registered, 'temporary_post_ids' => $ids ) ) . PHP_EOL;
} finally {
	wp_set_current_user( (int) $admins[0] );
	foreach ( array_reverse( $ids ) as $id ) { wp_delete_post( $id, true ); }
	if ( $fixture_registered ) { $manager->unregister( 'faq_list_b80b19e2' ); }
	echo 'Temporary FAQ test pages removed.' . PHP_EOL;
}
