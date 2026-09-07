<?php
/**
 * Staging-only NOVA strategy and hidden-content integration canary.
 * Run: wp eval-file /absolute/path/strategy-staging-integration.php
 * Creates temporary content/users and restores mapping options in finally.
 * Does not enable/disable plugins, change roles, flush rewrites, or send mail.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }

final class Nova_Strategy_Staging_Canary {

	private $prefix;
	private $old_user;
	private $admin_id;
	private $users = [];
	private $posts = [];
	private $terms = [];
	private $options = [];
	private $failures = [];
	private $passed = 0;
	private $group_key;
	private $post_type = 'nova_map_test';
	private $meta_key = 'nova_map_registered';
	private $fixtures = [];
	private $registered = false;

	public function run(): void {
		$this->prefix = 'nova-map-' . strtolower( wp_generate_password( 10, false, false ) );
		$this->group_key = 'group_' . str_replace( '-', '_', $this->prefix );
		$this->old_user = get_current_user_id();
		try {
			$this->require_environment();
			$this->snapshot_options();
			$this->create_fixtures();
			$this->test_catalog_eligibility();
			$this->test_strategy();
			$this->test_builder_binding();
			$this->test_transport();
		} catch ( Throwable $error ) {
			$this->failures[] = 'Test setup or prerequisite: ' . $error->getMessage();
		} finally {
			$this->cleanup();
		}
		WP_CLI::line( 'Canary assertions passed: ' . $this->passed );
		if ( $this->failures ) {
			foreach ( $this->failures as $failure ) { WP_CLI::warning( $failure ); }
			WP_CLI::error( 'Strategy staging integration failed (' . count( $this->failures ) . ' failures). Fixture cleanup completed.' );
		}
		WP_CLI::success( 'Strategy mapping, hidden ACF transport, authorization, and cleanup checks passed.' );
	}

	private function check( bool $condition, string $message ): void {
		if ( ! $condition ) { $this->failures[] = $message; WP_CLI::line( 'FAIL  ' . $message ); return; }
		++$this->passed;
		WP_CLI::line( 'PASS  ' . $message );
	}

	private function require_environment(): void {
		foreach ( [ 'Nova_Bridge_Suite_Content_Context', 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Content_Transport' ] as $class ) {
			if ( ! class_exists( $class ) ) { throw new RuntimeException( 'Required class is unavailable: ' . $class ); }
		}
		if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'update_field' ) ) { throw new RuntimeException( 'ACF/SCF native field APIs are required.' ); }
		if ( ! taxonomy_exists( 'product_cat' ) ) { throw new RuntimeException( 'WooCommerce product_cat is required.' ); }
		if ( post_type_exists( $this->post_type ) ) { throw new RuntimeException( 'Refusing to overwrite an existing nova_map_test post type.' ); }
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		if ( empty( $admins ) ) { throw new RuntimeException( 'No administrator available for authenticated in-process REST tests.' ); }
		$this->admin_id = (int) $admins[0];
		wp_set_current_user( $this->admin_id );
	}

	private function snapshot_options(): void {
		$names = [ Nova_Bridge_Suite_Content_Context::OPTION_NAME ];
		foreach ( [ 'Nova_Bridge_Suite_Strategy', 'Nova_Bridge_Suite_Content_Transport' ] as $class ) {
			$reflection = new ReflectionClass( $class );
			foreach ( $reflection->getConstants() as $key => $value ) {
				if ( is_string( $value ) && false !== strpos( $key, 'OPTION' ) ) { $names[] = $value; }
			}
		}
		foreach ( array_unique( $names ) as $name ) {
			$missing = new stdClass();
			$value = get_option( $name, $missing );
			$this->options[ $name ] = [ 'exists' => $missing !== $value, 'value' => $value ];
		}
	}

	private function create_user( string $role ): int {
		$login = str_replace( '-', '_', $this->prefix ) . '_' . $role;
		$id = wp_insert_user( [ 'user_login' => $login, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $login . '@example.invalid', 'role' => $role ] );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create fixture user: ' . $id->get_error_message() ); }
		$this->users[ $role ] = (int) $id;
		return (int) $id;
	}

	private function create_post( string $suffix, array $args = [] ): int {
		$args = array_merge( [ 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $this->prefix . ' ' . $suffix, 'post_name' => $this->prefix . '-' . $suffix, 'post_author' => $this->admin_id ], $args );
		$id = wp_insert_post( wp_slash( $args ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create fixture post: ' . $id->get_error_message() ); }
		$this->posts[] = (int) $id;
		return (int) $id;
	}

	private function key( string $suffix ): string { return 'field_' . str_replace( '-', '_', $this->prefix ) . '_' . $suffix; }

	private function create_fixtures(): void {
		register_post_type( $this->post_type, [
			'label' => 'NOVA temporary mapping test', 'public' => false, 'show_ui' => true,
			'publicly_queryable' => true, 'show_in_rest' => false, 'hierarchical' => true,
			'capability_type' => 'post', 'map_meta_cap' => true,
			'supports' => [ 'title', 'editor', 'custom-fields', 'author', 'page-attributes' ],
			'rewrite' => [ 'slug' => $this->prefix . '-hidden', 'with_front' => false ],
		] );
		$this->registered = true;
		register_post_meta( $this->post_type, $this->meta_key, [ 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'description' => 'Fixture editorial subtitle', 'auth_callback' => static function ( $allowed, $meta_key, $post_id ) { return $post_id > 0 && current_user_can( 'edit_post', $post_id ); } ] );
		register_post_meta( $this->post_type, 'nova_map_locked', [ 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'description' => 'Fixture denied editorial field', 'auth_callback' => '__return_false' ] );
		acf_add_local_field_group( [
			'key' => $this->group_key, 'title' => $this->prefix . ' private ACF', 'active' => true, 'show_in_rest' => false,
			'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => $this->post_type ] ], [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ] ] ],
			'fields' => [
				[ 'key' => $this->key( 'intro' ), 'name' => 'nova_map_intro', 'label' => 'Mapped introduction', 'type' => 'text' ],
				[ 'key' => $this->key( 'group' ), 'name' => 'nova_map_group', 'label' => 'Grouped copy', 'type' => 'group', 'sub_fields' => [
					[ 'key' => $this->key( 'group_heading' ), 'name' => 'heading', 'label' => 'Heading', 'type' => 'text' ],
					[ 'key' => $this->key( 'group_body' ), 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg' ],
				] ],
				[ 'key' => $this->key( 'repeater' ), 'name' => 'nova_map_faqs', 'label' => 'FAQ rows', 'type' => 'repeater', 'sub_fields' => [
					[ 'key' => $this->key( 'question' ), 'name' => 'question', 'label' => 'Question', 'type' => 'text' ],
					[ 'key' => $this->key( 'answer' ), 'name' => 'answer', 'label' => 'Answer', 'type' => 'textarea' ],
				] ],
				[ 'key' => $this->key( 'flex' ), 'name' => 'nova_map_sections', 'label' => 'Flexible sections', 'type' => 'flexible_content', 'layouts' => [
					'layout_' . str_replace( '-', '_', $this->prefix ) . '_copy' => [ 'key' => 'layout_' . str_replace( '-', '_', $this->prefix ) . '_copy', 'name' => 'copy', 'label' => 'Copy section', 'display' => 'block', 'sub_fields' => [
						[ 'key' => $this->key( 'flex_heading' ), 'name' => 'heading', 'label' => 'Section heading', 'type' => 'text' ],
						[ 'key' => $this->key( 'flex_body' ), 'name' => 'body', 'label' => 'Section body', 'type' => 'wysiwyg' ],
					] ],
				] ],
			],
		] );
		$this->create_user( 'editor' );
		$this->create_user( 'subscriber' );
		$this->create_user( 'contributor' );
		$parent = $this->create_post( 'parent', [ 'post_name' => $this->prefix, 'post_status' => 'publish' ] );
		$first = $this->create_post( 'alpha', [ 'post_parent' => $parent, 'post_name' => 'alpha', 'post_status' => 'publish', 'post_content' => '<h2>First heading</h2><p>Alpha body.</p>' ] );
		$second = $this->create_post( 'beta', [ 'post_parent' => $parent, 'post_name' => 'beta', 'post_status' => 'publish', 'post_content' => '<h2>Second heading</h2><p>Beta body with different values.</p>' ] );
		$builder = $this->create_post( 'builder', [ 'post_name' => $this->prefix . '-builder', 'post_status' => 'publish', 'post_content' => '<h2>Builder content</h2>' ] );
		foreach ( [ $first => 'Alpha', $second => 'Beta' ] as $id => $label ) {
			update_field( $this->key( 'intro' ), $label . ' introduction', $id );
			update_field( $this->key( 'group' ), [ 'heading' => $label . ' heading', 'body' => '<p>' . $label . ' group body.</p>' ], $id );
			update_field( $this->key( 'repeater' ), [ [ 'question' => $label . ' question?', 'answer' => $label . ' answer.' ] ], $id );
			update_field( $this->key( 'flex' ), [ [ 'acf_fc_layout' => 'copy', 'heading' => $label . ' section', 'body' => '<p>' . $label . ' flex body.</p>' ] ], $id );
		}
		update_post_meta( $builder, '_elementor_edit_mode', 'builder' );
		update_post_meta( $builder, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'canarycontainer', 'elType' => 'container', 'settings' => [], 'elements' => [
			[ 'id' => 'canaryheading', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Distinct structure' ], 'elements' => [] ],
			[ 'id' => 'canarycopy', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Builder copy.</p>' ], 'elements' => [] ],
		] ] ] ) ) );
		$builder_mirror = $this->create_post( 'builder-mirror', [ 'post_name' => $this->prefix . '-builder-mirror', 'post_status' => 'publish', 'post_content' => '<h2>Different mirrored builder text</h2>' ] );
		update_post_meta( $builder_mirror, '_elementor_edit_mode', 'builder' );
		update_post_meta( $builder_mirror, '_elementor_data', wp_slash( wp_json_encode( [ [ 'id' => 'mirrorcontainer', 'elType' => 'container', 'settings' => [], 'elements' => [
			[ 'id' => 'mirrorheading', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => [ 'title' => 'Same layout, different IDs and text' ], 'elements' => [] ],
			[ 'id' => 'mirrorcopy', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => [ 'editor' => '<p>Different mirrored text.</p>' ], 'elements' => [] ],
		] ] ] ) ) );
		$term = wp_insert_term( $this->prefix . ' category', 'product_cat', [ 'slug' => $this->prefix . '-category', 'description' => 'Temporary category copy.' ] );
		if ( is_wp_error( $term ) ) { throw new RuntimeException( 'Could not create fixture category: ' . $term->get_error_message() ); }
		$this->terms[] = (int) $term['term_id'];
		$hidden = $this->create_post( 'hidden', [ 'post_type' => $this->post_type, 'post_status' => 'private', 'post_author' => $this->users['editor'], 'post_content' => '<p>Hidden fixture copy.</p>' ] );
		$draft_reference = $this->create_post( 'draft-reference', [ 'post_status' => 'draft', 'post_content' => '<p>Selectable draft layout reference.</p>' ] );
		update_field( $this->key( 'intro' ), 'Existing hidden introduction', $hidden );
		update_post_meta( $hidden, $this->meta_key, 'Before update' );
		$this->fixtures = compact( 'parent', 'first', 'second', 'builder', 'builder_mirror', 'hidden', 'draft_reference' );
		$this->fixtures['term'] = (int) $term['term_id'];
		$this->fixtures['future_path'] = trailingslashit( (string) wp_parse_url( get_permalink( $parent ), PHP_URL_PATH ) ) . 'future/';
		$this->fixtures['hidden_path'] = '/' . $this->prefix . '-hidden/future/';
		$this->check( false === get_post_type_object( $this->post_type )->show_in_rest, 'Fixture CPT starts with REST disabled.' );
		$this->check( empty( acf_get_field_group( $this->group_key )['show_in_rest'] ), 'Fixture ACF group starts with REST disabled.' );
	}

	private function request( string $method, string $route, array $params = [], ?int $user = null ): WP_REST_Response {
		if ( null !== $user ) { wp_set_current_user( $user ); }
		// eval-file runs many requests in one PHP process; model fresh HTTP request caches.
		$this->reset_request_caches();
		$request = new WP_REST_Request( $method, $route );
		if ( in_array( $method, [ 'POST', 'PATCH', 'PUT' ], true ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		} else { $request->set_query_params( $params ); }
		$response = rest_do_request( $request );
		$data = $response->get_data();
		if ( 'POST' === $method && false !== strpos( $route, '/content/' . $this->post_type ) ) {
			$id = (int) ( $data['id'] ?? $data['data']['post_id'] ?? 0 );
			if ( $id && get_post_type( $id ) === $this->post_type ) { $this->posts[] = $id; }
		}
		return $response;
	}

	private function reset_request_caches(): void {
		foreach ( [ 'catalog' => null, 'fingerprints' => [], 'inventories' => [], 'resources' => null ] as $name => $default ) {
			if ( ! property_exists( 'Nova_Bridge_Suite_Strategy', $name ) ) { continue; }
			$property = new ReflectionProperty( 'Nova_Bridge_Suite_Strategy', $name );
			$property->setAccessible( true );
			$property->setValue( null, $default );
		}
		Nova_Bridge_Suite_Content_Context::flush_config_cache();
	}

	private function rows_by_path( array $data ): array {
		$rows = [];
		foreach ( $data['rows'] ?? [] as $row ) { $rows[ $row['path'] ] = $row; }
		return $rows;
	}

	private function profile( int $post_id, string $signature, string $guidance ): array {
		return [ 'signature' => $signature, 'reference_type' => 'post', 'reference_id' => $post_id, 'label' => $this->prefix . ' shared profile', 'guidance' => $guidance, 'fields' => [ '/title' => [ 'mapping' => 'h1', 'description' => 'Use the supplied visible H1.' ] ] ];
	}

	/** Existing system content is only read; temporary URL/page-option filters never persist. */
	private function test_catalog_eligibility(): void {
		wp_set_current_user( $this->admin_id );
		$eligibility = new ReflectionMethod( 'Nova_Bridge_Suite_Strategy', 'editorial_post_type' );
		$eligibility->setAccessible( true );
		foreach ( [ 'post', 'page', $this->post_type ] as $post_type ) {
			$this->check( true === $eligibility->invoke( null, $post_type ), 'Strategy eligibility preserves editorial post type ' . $post_type . '.' );
		}
		$managed = function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) ? (array) nova_bridge_suite_get_managed_blog_post_types() : [];
		if ( post_type_exists( 'service_page' ) ) { $managed[] = 'service_page'; }
		foreach ( array_unique( $managed ) as $post_type ) {
			if ( post_type_exists( $post_type ) ) { $this->check( true === $eligibility->invoke( null, $post_type ), 'Strategy eligibility preserves self-describing NOVA type ' . $post_type . '.' ); }
		}
		foreach ( [ 'elementor_library', 'product', 'product_variation', 'attachment', 'wp_template', 'wp_template_part', 'wp_global_styles', 'nav_menu_item' ] as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) { continue; }
			$this->check( false === $eligibility->invoke( null, $post_type ), 'Strategy eligibility excludes system/library/product type ' . $post_type . '.' );
			$ids = get_posts( [ 'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'suppress_filters' => true ] );
			if ( $ids ) { $this->check( null === Nova_Bridge_Suite_Strategy::entity( 'post', (int) $ids[0] ), 'Direct reference lookup cannot bypass excluded type ' . $post_type . '.' ); }
		}
		$fixture_page = $this->fixtures['first'];
		foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $commerce_page ) {
			$hook = 'woocommerce_get_' . $commerce_page . '_page_id';
			$page_filter = static function () use ( $fixture_page ) { return $fixture_page; };
			add_filter( $hook, $page_filter, 99 );
			try { $this->check( null === Nova_Bridge_Suite_Strategy::entity( 'post', $fixture_page ), 'Configured WooCommerce ' . $commerce_page . ' page is excluded as a layout reference.' ); }
			finally { remove_filter( $hook, $page_filter, 99 ); }
		}
		$this->check( null !== Nova_Bridge_Suite_Strategy::entity( 'post', $fixture_page ), 'Removing temporary commerce-role filter restores an ordinary page reference.' );
		$commerce_ids = new ReflectionMethod( 'Nova_Bridge_Suite_Strategy', 'commerce_page_ids' );
		$commerce_ids->setAccessible( true );
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$expected_translations = [];
			foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $commerce_page ) {
				$base_id = (int) get_option( 'woocommerce_' . $commerce_page . '_page_id' );
				if ( $base_id > 0 ) { foreach ( (array) pll_get_post_translations( $base_id ) as $translation_id ) { if ( (int) $translation_id > 0 ) { $expected_translations[] = (int) $translation_id; } } }
			}
			$excluded = $commerce_ids->invoke( null );
			$this->check( ! array_diff( $expected_translations, $excluded ), 'All available Polylang translations of configured WooCommerce pages are excluded.' );
		}
		$translated_page = $this->fixtures['second'];
		$fixture_trid = 'nova-canary-' . $fixture_page;
		$cart_source = static function () use ( $fixture_page ) { return $fixture_page; };
		$wpml_trid = static function ( $trid, $element_id, $element_type ) use ( $fixture_page, $fixture_trid ) { return $fixture_page === (int) $element_id && 'post_page' === $element_type ? $fixture_trid : $trid; };
		$wpml_translations = static function ( $translations, $trid, $element_type ) use ( $fixture_trid, $fixture_page, $translated_page ) { return $trid === $fixture_trid && 'post_page' === $element_type ? [ 'nl' => (object) [ 'element_id' => $fixture_page ], 'en' => (object) [ 'element_id' => $translated_page ] ] : $translations; };
		add_filter( 'woocommerce_get_cart_page_id', $cart_source, 99 );
		add_filter( 'wpml_element_trid', $wpml_trid, 99, 3 );
		add_filter( 'wpml_get_element_translations', $wpml_translations, 99, 3 );
		try {
			$this->check( in_array( $translated_page, $commerce_ids->invoke( null ), true ), 'Available WPML translation hooks expand the configured WooCommerce page exclusion set.' );
			$this->check( null === Nova_Bridge_Suite_Strategy::entity( 'post', $translated_page ), 'Translated WooCommerce workflow page cannot be explicitly selected as a reference.' );
		} finally {
			remove_filter( 'woocommerce_get_cart_page_id', $cart_source, 99 );
			remove_filter( 'wpml_element_trid', $wpml_trid, 99 );
			remove_filter( 'wpml_get_element_translations', $wpml_translations, 99 );
		}
		$workflow_page = new ReflectionMethod( 'Nova_Bridge_Suite_Strategy', 'commerce_workflow_page' );
		$workflow_page->setAccessible( true );
		$in_memory_page = clone get_post( $fixture_page );
		foreach ( [ '[woocommerce_cart]', '[woocommerce_checkout]', '[woocommerce_my_account]', '<!-- wp:woocommerce/cart --><div>Cart</div><!-- /wp:woocommerce/cart -->', '<!-- wp:woocommerce/checkout /-->' ] as $workflow_content ) {
			$in_memory_page->post_content = $workflow_content;
			$this->check( true === $workflow_page->invoke( null, $in_memory_page ), 'Recognizes an orphaned WooCommerce workflow shortcode or cart/checkout block.' );
		}
		foreach ( [ '<p>Advice about shopping carts, checkout conversion, customer accounts, and products.</p>', '[products limit="3"]', '[[woocommerce_cart]]', '<!-- wp:woocommerce/product-collection /-->' ] as $editorial_content ) {
			$in_memory_page->post_content = $editorial_content;
			$this->check( false === $workflow_page->invoke( null, $in_memory_page ), 'General editorial/product content or escaped shortcode examples remain eligible.' );
		}
		$this->check( null !== Nova_Bridge_Suite_Strategy::entity( 'post', $translated_page ), 'Removing temporary translation hooks restores the ordinary page without persisting changes.' );
		$draft_id = $this->fixtures['draft_reference'];
		$query_link = static function ( $url, $id ) use ( $draft_id ) { return (int) $id === $draft_id ? home_url( '/?page_id=' . $draft_id ) : $url; };
		add_filter( 'page_link', $query_link, 99, 2 );
		try {
			$this->reset_request_caches();
			$draft = Nova_Bridge_Suite_Strategy::entity( 'post', $draft_id );
			$this->check( is_array( $draft ) && $draft_id === $draft['reference_id'], 'A draft with only a query permalink remains explicitly selectable as a reference.' );
			$this->check( is_array( $draft ) && null === $draft['path'] && null === $draft['parent_path'] && false === $draft['has_public_path'], 'A query-only draft permalink has no path and cannot masquerade as the homepage.' );
			$catalog_method = new ReflectionMethod( 'Nova_Bridge_Suite_Strategy', 'catalog' );
			$catalog_method->setAccessible( true );
			$catalog = $catalog_method->invoke( null );
			$catalog_drafts = array_values( array_filter( $catalog['entities'], static function ( $entity ) use ( $draft_id ) { return 'post' === $entity['reference_type'] && $draft_id === $entity['reference_id']; } ) );
			$this->check( 1 === count( $catalog_drafts ) && null === $catalog_drafts[0]['parent_path'], 'Reference catalog keeps the draft without placing it among root URL siblings.' );
			$analysis = new ReflectionMethod( 'Nova_Bridge_Suite_Strategy', 'row_analysis' );
			$analysis->setAccessible( true );
			foreach ( [ '/', '/' . $this->prefix . '-root-future/' ] as $path ) {
				$row = $analysis->invoke( null, [ 'id' => 'temporary-unpersisted-check', 'path' => $path, 'url' => home_url( $path ), 'page_type' => 'service', 'locale' => 'nl-NL' ], [ 'assignments' => [], 'profiles' => [] ] );
				$candidate_ids = array_column( $row['candidates'], 'reference_id' );
				$this->check( $draft_id !== (int) $row['post_id'] && ! in_array( $draft_id, $candidate_ids, true ), 'Query-only draft never participates in automatic matching for ' . $path . '.' );
			}
		} finally { remove_filter( 'page_link', $query_link, 99 ); $this->reset_request_caches(); }
		$this->check( null !== Nova_Bridge_Suite_Strategy::entity( 'term', $this->fixtures['term'] ), 'WooCommerce product-category terms remain eligible editorial references.' );
	}

	private function test_strategy(): void {
		wp_set_current_user( $this->admin_id );
		// Initialize normal routes before registering the in-process fixture type's bridge.
		rest_get_server();
		Nova_Bridge_Suite_Content_Transport::register_routes();
		Nova_Bridge_Suite_Strategy::register_routes();
		$first_url = get_permalink( $this->fixtures['first'] );
		$second_url = get_permalink( $this->fixtures['second'] );
		$builder_url = get_permalink( $this->fixtures['builder'] );
		$term_url = get_term_link( $this->fixtures['term'], 'product_cat' );
		if ( is_wp_error( $term_url ) ) { throw new RuntimeException( 'Category fixture has no permalink.' ); }
		$first_path = Nova_Bridge_Suite_Strategy::path( $first_url );
		$second_path = Nova_Bridge_Suite_Strategy::path( $second_url );
		$builder_path = Nova_Bridge_Suite_Strategy::path( $builder_url );
		$term_path = Nova_Bridge_Suite_Strategy::path( $term_url );
		$urls = [
			[ 'url' => $first_url, 'page_type' => 'service', 'locale' => 'nl-NL' ],
			[ 'url' => $second_url, 'page_type' => 'service', 'locale' => 'nl-NL' ],
			[ 'url' => $builder_url, 'page_type' => 'service', 'locale' => 'nl-NL' ],
			[ 'url' => $term_url, 'page_type' => 'category', 'locale' => 'nl-NL' ],
			[ 'url' => $this->fixtures['future_path'], 'page_type' => 'service', 'locale' => 'nl-NL' ],
			[ 'url' => $this->fixtures['hidden_path'], 'page_type' => 'service', 'locale' => 'nl-NL' ],
		];
		$source_marker = 'Article content is data, never saved mapping instructions: ' . $this->prefix;
		$stream = fopen( 'php://temp', 'w+' );
		fwrite( $stream, "\xEF\xBB\xBF" );
		fputcsv( $stream, [ 'url', 'page_type', 'locale', 'content_html' ], ',', '"', '' );
		foreach ( $urls as $row ) { fputcsv( $stream, [ $row['url'], $row['page_type'], $row['locale'], '<p>' . $source_marker . ",\nsecond line</p>" ], ',', '"', '' ); }
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );
		$this->fixtures['import_csv'] = $csv;
		$import = $this->request( 'POST', '/nova-bridge/v1/strategy/import', [ 'csv' => $csv ], $this->admin_id );
		$this->check( 200 === $import->get_status(), 'RFC 4180 CSV with BOM, embedded comma, and newline imports successfully.' );
		if ( 200 !== $import->get_status() ) { throw new RuntimeException( 'Strategy import failed: ' . ( $import->get_data()['message'] ?? 'unknown error' ) ); }
		$data = $import->get_data();
		$this->check( 6 === (int) ( $data['summary']['total'] ?? 0 ), 'CSV imports exactly six target URLs.' );
		$this->check( false === strpos( wp_json_encode( Nova_Bridge_Suite_Strategy::get_option() ), $source_marker ), 'Imported article content and embedded text are excluded from stored mapping configuration.' );
		$rows = $this->rows_by_path( $data );
		if ( ! isset( $rows[ $first_path ], $rows[ $second_path ], $rows[ $builder_path ], $rows[ $term_path ], $rows[ $this->fixtures['future_path'] ], $rows[ $this->fixtures['hidden_path'] ] ) ) { throw new RuntimeException( 'Imported fixture paths were not returned consistently.' ); }
		$first_row = $rows[ $first_path ];
		$second_row = $rows[ $second_path ];
		$builder_row = $rows[ $builder_path ];
		$future_row = $rows[ $this->fixtures['future_path'] ];
		$hidden_row = $rows[ $this->fixtures['hidden_path'] ];
		$this->check( $this->fixtures['first'] === (int) $first_row['post_id'] && $this->fixtures['second'] === (int) $second_row['post_id'], 'Existing pages resolve by exact URL hierarchy.' );
		$this->check( '' !== $first_row['signature'] && $first_row['signature'] === $second_row['signature'], 'Identical ACF schemas and layouts deduplicate despite different field values.' );
		$this->check( '' !== $builder_row['signature'] && $builder_row['signature'] !== $first_row['signature'], 'Different Elementor structure remains a separate layout.' );
		$this->check( $this->fixtures['term'] === (int) $rows[ $term_path ]['term_id'] && 'term' === $rows[ $term_path ]['reference_type'], 'Product-category URL resolves as a taxonomy term.' );
		$this->check( 3 === (int) ( $data['summary']['unique_layouts'] ?? 0 ), 'Four existing targets produce exactly three unique layouts.' );
		$this->check( 'needs_mapping' === $first_row['status'], 'Custom ACF layout requires guidance before it is ready.' );
		$this->check( 'suggested' === $future_row['status'] && 0 === (int) $future_row['reference_id'], 'Future sibling is suggested without silently assigning a reference.' );
		$guidance = 'Private canary guidance ' . $this->prefix . ': preserve conversion sections and map only supplied content.';
		$profile = $this->profile( $this->fixtures['first'], $first_row['signature'], $guidance );
		$saved = $this->request( 'POST', '/nova-bridge/v1/strategy/profiles', $profile, $this->admin_id );
		$this->check( 200 === $saved->get_status(), 'Administrator saves one shared layout profile.' );
		$saved_rows = $this->rows_by_path( $saved->get_data() );
		$this->check( 'ready' === ( $saved_rows[ $first_path ]['status'] ?? '' ) && 'ready' === ( $saved_rows[ $second_path ]['status'] ?? '' ), 'Saving one profile makes both matching layouts ready.' );
		$this->check( 'needs_mapping' === ( $saved_rows[ $builder_path ]['status'] ?? '' ), 'A distinct builder layout is not marked ready by another profile.' );
		$future_before = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $this->fixtures['future_path'] ], $this->admin_id );
		$this->check( 200 === $future_before->get_status() && false === ( $future_before->get_data()['ready'] ?? true ), 'Unassigned future target remains unready after its sibling profile is saved.' );
		$assigned = $this->request( 'POST', '/nova-bridge/v1/strategy/assign', [ 'row_id' => $future_row['id'], 'reference_type' => 'post', 'reference_id' => $this->fixtures['first'] ], $this->admin_id );
		$this->check( 200 === $assigned->get_status(), 'Administrator explicitly assigns the future target to a reusable example.' );
		$context = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $this->fixtures['future_path'] ], $this->users['editor'] );
		$contract = $context->get_data();
		$this->check( 200 === $context->get_status() && true === ( $contract['ready'] ?? false ), 'Editor receives a ready contract after explicit assignment.' );
		$this->check( 'h1' === ( $contract['nova_content_mappings']['/title'] ?? null ) && $guidance === ( $contract['guidance'] ?? null ), 'URL contract contains direct source mapping and administrator guidance.' );
		$this->check( 'POST' === ( $contract['write']['method'] ?? '' ) && 0 === (int) ( $contract['post_id'] ?? -1 ), 'Future contract selects creation rather than updating its reference.' );
		$existing_context = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $second_url ], $this->users['editor'] );
		$this->check( 200 === $existing_context->get_status() && 'PATCH' === ( $existing_context->get_data()['write']['method'] ?? '' ), 'Existing target contract selects its own update operation.' );
		$edit_page = $this->request( 'GET', '/wp/v2/pages/' . $this->fixtures['second'], [ 'context' => 'edit' ], $this->users['editor'] );
		$edit_data = $edit_page->get_data();
		$this->check( 200 === $edit_page->get_status() && 'h1' === ( $edit_data['nova_content_mappings']['/title'] ?? null ), 'Native authenticated page response carries its shared profile mapping.' );
		$this->check( $guidance === ( $edit_data['meta_descriptions']['/@nova/layout'] ?? null ), 'Native authenticated response embeds layout guidance in meta_descriptions.' );
		foreach ( [ 0, $this->users['editor'] ] as $viewer ) {
			$public = $this->request( 'GET', '/wp/v2/pages/' . $this->fixtures['second'], [ 'context' => 'view' ], $viewer );
			$this->check( 200 === $public->get_status() && false === strpos( wp_json_encode( $public->get_data() ), $guidance ) && ! isset( $public->get_data()['nova_strategy_context'] ), 'View-context response never exposes private mapping guidance (actor ' . $viewer . ').' );
		}
		$anon_context = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $first_url ], 0 );
		$this->check( 401 === $anon_context->get_status(), 'Anonymous URL-context lookup is denied.' );
		$subscriber_context = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $first_url ], $this->users['subscriber'] );
		$this->check( 403 === $subscriber_context->get_status(), 'A user without edit permission cannot read a mapping contract.' );
		foreach ( [ 'GET' => '/nova-bridge/v1/strategy', 'POST' => '/nova-bridge/v1/strategy/profiles' ] as $method => $route ) {
			$editor_admin = $this->request( $method, $route, 'POST' === $method ? $profile : [], $this->users['editor'] );
			$this->check( 403 === $editor_admin->get_status(), 'Editor cannot access administrator mapping action ' . $method . ' ' . $route . '.' );
		}
		$invalid_profile = $profile;
		$invalid_profile['fields']['/meta_all/__proto__/bad'] = [ 'mapping' => 'content', 'description' => 'Invalid pointer.' ];
		$invalid = $this->request( 'POST', '/nova-bridge/v1/strategy/profiles', $invalid_profile, $this->admin_id );
		$this->check( 400 === $invalid->get_status(), 'Prototype-related or undiscovered field pointer is rejected.' );
		$stale_profile = $profile;
		$stale_profile['signature'] = str_repeat( '0', 64 );
		$stale = $this->request( 'POST', '/nova-bridge/v1/strategy/profiles', $stale_profile, $this->admin_id );
		$this->check( 409 === $stale->get_status(), 'Stale layout fingerprint rejects profile save with HTTP 409.' );
		$before_invalid_import = Nova_Bridge_Suite_Strategy::get_option();
		$invalid_csv = $this->request( 'POST', '/nova-bridge/v1/strategy/import', [ 'csv' => "url,page_type\n/a/,service,extra\n" ], $this->admin_id );
		$this->check( 400 === $invalid_csv->get_status() && $before_invalid_import === Nova_Bridge_Suite_Strategy::get_option(), 'Malformed CSV is rejected atomically without replacing the imported strategy.' );
		$traversal = $this->request( 'POST', '/nova-bridge/v1/strategy/import', [ 'urls' => [ '/a/%2e%2e/b/' ] ], $this->admin_id );
		$this->check( 400 === $traversal->get_status(), 'Encoded path traversal is rejected during import.' );
		$cross_host = $this->request( 'POST', '/nova-bridge/v1/strategy/import', [ 'urls' => [ $first_url, 'https://example.invalid/elsewhere/' ] ], $this->admin_id );
		$this->check( 400 === $cross_host->get_status(), 'Mixed website hosts are rejected in a single strategy.' );
		$hidden_assigned = $this->request( 'POST', '/nova-bridge/v1/strategy/assign', [ 'row_id' => $hidden_row['id'], 'reference_type' => 'post', 'reference_id' => $this->fixtures['hidden'] ], $this->admin_id );
		$this->check( 200 === $hidden_assigned->get_status(), 'A private REST-disabled CPT can be explicitly selected as a reference.' );
		$hidden_rows = $this->rows_by_path( $hidden_assigned->get_data() );
		$hidden_signature = $hidden_rows[ $this->fixtures['hidden_path'] ]['signature'] ?? '';
		$hidden_profile = $this->profile( $this->fixtures['hidden'], $hidden_signature, $guidance . ' Hidden CPT.' );
		$hidden_profile['fields']['/meta_all/acf/nova_map_intro'] = [ 'mapping' => 'top_content', 'description' => 'Use the source intro in this hidden ACF field.' ];
		$hidden_saved = $this->request( 'POST', '/nova-bridge/v1/strategy/profiles', $hidden_profile, $this->admin_id );
		$this->check( 200 === $hidden_saved->get_status(), 'Hidden CPT profile accepts the verified meta_all ACF transport path.' );
		$hidden_context = $this->request( 'GET', '/nova-bridge/v1/strategy/context', [ 'url' => $this->fixtures['hidden_path'] ], $this->users['editor'] );
		$hidden_contract = $hidden_context->get_data();
		$this->check( 200 === $hidden_context->get_status() && true === ( $hidden_contract['ready'] ?? false ) && ( '/nova-bridge/v1/content/' . $this->post_type ) === ( $hidden_contract['write']['route'] ?? '' ), 'Hidden CPT strategy contract resolves an available private bridge create route.' );
		$hidden_read = $this->request( 'GET', '/nova-bridge/v1/content/' . $this->post_type . '/' . $this->fixtures['hidden'], [], $this->users['editor'] );
		$this->check( 200 === $hidden_read->get_status() && ( $guidance . ' Hidden CPT.' ) === ( $hidden_read->get_data()['meta_descriptions']['/@nova/layout'] ?? null ), 'Hidden endpoint response preserves strategy profile guidance.' );
		$this->check( 'top_content' === ( $hidden_read->get_data()['nova_content_mappings']['/meta_all/acf/nova_map_intro'] ?? null ), 'Hidden endpoint uses the established nova_content_mappings response name.' );
		$this->fixtures['guidance'] = $guidance;
	}

	private function test_builder_binding(): void {
		wp_set_current_user( $this->admin_id );
		$this->reset_request_caches();
		$source = Nova_Bridge_Suite_Strategy::entity( 'post', $this->fixtures['builder'] );
		$target = Nova_Bridge_Suite_Strategy::entity( 'post', $this->fixtures['builder_mirror'] );
		$source_fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $source );
		$target_fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $target );
		$this->check( $source_fingerprint['signature'] === $target_fingerprint['signature'], 'Elementor documents with identical structure deduplicate despite different element IDs and text.' );
		$source_fields = Nova_Bridge_Suite_Strategy::field_inventory( $source );
		$target_fields = Nova_Bridge_Suite_Strategy::field_inventory( $target );
		$source_field = null;
		$target_field = null;
		foreach ( $source_fields as $field ) {
			if ( 'builder' === ( $field['source'] ?? '' ) && 0 === strpos( $field['selector_data']['field_key'] ?? '', 'canaryheading|' ) ) { $source_field = $field; break; }
		}
		foreach ( $target_fields as $field ) {
			if ( 'builder' === ( $field['source'] ?? '' ) && 0 === strpos( $field['selector_data']['field_key'] ?? '', 'mirrorheading|' ) ) { $target_field = $field; break; }
		}
		$this->check( is_array( $source_field ) && is_array( $target_field ), 'Both Elementor documents expose their actual heading fields through the compact bridge.' );
		if ( $source_field && $target_field ) {
			$this->check( ! empty( $source_field['binding'] ) && $source_field['binding'] === ( $target_field['binding'] ?? '' ), 'Equivalent Elementor heading fields share a structural binding independent of IDs.' );
			$profile = [
				'signature' => $source_fingerprint['signature'], 'reference_type' => 'post', 'reference_id' => $this->fixtures['builder'],
				'label' => $this->prefix . ' builder profile', 'guidance' => 'Use the live target document selectors.',
				'fields' => [ $source_field['path'] => [ 'mapping' => 'h1', 'description' => 'Use the supplied H1 in the heading widget.' ] ],
			];
			$saved = $this->request( 'POST', '/nova-bridge/v1/strategy/profiles', $profile, $this->admin_id );
			$this->check( 200 === $saved->get_status(), 'Administrator saves a heading mapping once for the shared Elementor layout.' );
			$target_response = $this->request( 'GET', '/wp/v2/pages/' . $this->fixtures['builder_mirror'], [ 'context' => 'edit' ], $this->users['editor'] );
			$target_data = $target_response->get_data();
			$this->check( 200 === $target_response->get_status() && 'h1' === ( $target_data['nova_content_mappings'][ $target_field['path'] ] ?? null ), 'Shared Elementor mapping is rebound to the target document-qualified pointer.' );
			$this->check( ! isset( $target_data['nova_content_mappings'][ $source_field['path'] ] ), 'Target response does not reuse the reference document pointer.' );
			$this->check( 0 === strpos( $target_field['write']['payload_item']['field_key'] ?? '', 'mirrorheading|' ) && false === strpos( wp_json_encode( $target_field['write'] ?? [] ), 'canaryheading' ), 'Resolved target write payload uses its own Elementor element ID.' );
		}
		$before = Nova_Bridge_Suite_Strategy::get_option();
		$reimport = $this->request( 'POST', '/nova-bridge/v1/strategy/import', [ 'csv' => $this->fixtures['import_csv'] ], $this->admin_id );
		$after = Nova_Bridge_Suite_Strategy::get_option();
		$this->check( 200 === $reimport->get_status() && $before['profiles'] === $after['profiles'], 'Re-importing the strategy preserves all saved layout profiles.' );
		$this->check( $before['assignments'] === $after['assignments'], 'Re-importing existing target URLs preserves their explicit reference assignments.' );
	}

	private function test_transport(): void {
		wp_set_current_user( $this->admin_id );
		Nova_Bridge_Suite_Content_Transport::register_routes();
		$route = '/nova-bridge/v1/content/' . $this->post_type;
		$id = $this->fixtures['hidden'];
		$read = $this->request( 'GET', $route . '/' . $id, [], $this->admin_id );
		$this->check( 200 === $read->get_status(), 'Administrator reads a private CPT through its bridge endpoint.' );
		$read_data = json_decode( wp_json_encode( $read->get_data() ), true );
		$this->check( 'Existing hidden introduction' === ( $read_data['meta_all']['acf']['nova_map_intro'] ?? null ), 'REST-disabled ACF introduction is visible under meta_all.acf.' );
		$this->check( 'Before update' === ( $read_data['meta_all'][ $this->meta_key ] ?? null ), 'Registered REST-disabled meta is visible to an authorized editor.' );
		$this->check( false === ( $read_data['nova_transport']['native_show_in_rest'] ?? true ), 'Response explicitly identifies a native REST-disabled post type.' );
		$this->check( ! array_key_exists( 'nova_map_locked', $read_data['meta_all'] ?? [] ), 'Denied registered field is excluded from authenticated reads.' );
		$patch = [
			'title' => $this->prefix . ' updated hidden title',
			'meta_all' => [
				$this->meta_key => 'Updated registered value',
				'acf' => [
					'nova_map_intro' => 'Updated hidden introduction',
					'nova_map_group' => [ 'heading' => 'Group heading', 'body' => '<p>Group body <strong>preserved</strong>.</p>' ],
					'nova_map_faqs' => [ [ 'question' => 'First?', 'answer' => 'First answer.' ], [ 'question' => 'Second?', 'answer' => 'Second answer.' ] ],
					'nova_map_sections' => [ [ 'acf_fc_layout' => 'copy', 'heading' => 'Flex heading', 'body' => '<p>Flexible copy.</p>' ] ],
				],
			],
		];
		$updated = $this->request( 'PATCH', $route . '/' . $id, $patch, $this->users['editor'] );
		$this->check( 200 === $updated->get_status(), 'Editor updates a private CPT and all four hidden ACF field shapes.' );
		$this->check( $patch['title'] === get_post( $id )->post_title, 'Native post title persisted alongside mapped fields.' );
		$this->check( 'Updated registered value' === get_post_meta( $id, $this->meta_key, true ), 'Registered meta write persisted.' );
		$this->check( 'Updated hidden introduction' === get_field( $this->key( 'intro' ), $id, false ), 'ACF text value persisted through native ACF APIs.' );
		$group = get_field( $this->key( 'group' ), $id );
		$this->check( is_array( $group ) && 'Group heading' === ( $group['heading'] ?? null ) && false !== strpos( $group['body'] ?? '', '<strong>preserved</strong>' ), 'ACF group subfields and allowed HTML persisted.' );
		$rows = get_field( $this->key( 'repeater' ), $id );
		$this->check( is_array( $rows ) && 2 === count( $rows ) && 'Second?' === ( $rows[1]['question'] ?? null ), 'ACF repeater rows persisted with correct subfield references.' );
		$flex = get_field( $this->key( 'flex' ), $id );
		$this->check( is_array( $flex ) && 'copy' === ( $flex[0]['acf_fc_layout'] ?? null ) && 'Flex heading' === ( $flex[0]['heading'] ?? null ), 'ACF flexible content layout and children persisted.' );
		$this->check( $this->key( 'intro' ) === get_post_meta( $id, '_nova_map_intro', true ), 'ACF field-key reference metadata is preserved.' );
		$structured_read = $this->request( 'GET', $route . '/' . $id, [], $this->users['editor'] );
		$structured_data = json_decode( wp_json_encode( $structured_read->get_data() ), true );
		$read_acf = $structured_data['meta_all']['acf'] ?? [];
		$this->check( 200 === $structured_read->get_status() && 'Group heading' === ( $read_acf['nova_map_group']['heading'] ?? null ), 'Bridge GET presents structured ACF children by field names.' );
		$this->check( 'Second?' === ( $read_acf['nova_map_faqs'][1]['question'] ?? null ) && 'copy' === ( $read_acf['nova_map_sections'][0]['acf_fc_layout'] ?? null ), 'Bridge GET preserves repeater lists and flexible-layout discriminators.' );
		$roundtrip_fields = array_intersect_key( $read_acf, array_fill_keys( [ 'nova_map_intro', 'nova_map_group', 'nova_map_faqs', 'nova_map_sections' ], true ) );
		$roundtrip = $this->request( 'PATCH', $route . '/' . $id, [ 'meta_all' => [ 'acf' => $roundtrip_fields ] ], $this->users['editor'] );
		$this->check( 4 === count( $roundtrip_fields ) && 200 === $roundtrip->get_status(), 'Structured values returned by GET can be PATCHed unchanged.' );
		$reject_hook = 'acf/update_value/key=' . $this->key( 'intro' );
		$reject_save = static function () { return 'Updated hidden introduction'; };
		add_filter( $reject_hook, $reject_save, 99, 3 );
		try {
			$rejected_save = $this->request( 'PATCH', $route . '/' . $id, [ 'meta_all' => [ 'acf' => [ 'nova_map_intro' => 'This value is rejected by a fixture save filter' ] ] ], $this->users['editor'] );
			$this->check( 500 === $rejected_save->get_status(), 'A field save filter that rejects the requested value is reported as a failed write.' );
			$this->check( 'Updated hidden introduction' === get_field( $this->key( 'intro' ), $id, false ), 'Read-back verification identifies the retained value after a rejected ACF save.' );
		} finally { remove_filter( $reject_hook, $reject_save, 99 ); }
		$before_title = get_post( $id )->post_title;
		$invalids = [
			'unknown top-level field' => [ 'title' => 'Must never persist', 'arbitrary_content_key' => 'bad' ],
			'protected raw meta' => [ 'title' => 'Must never persist', 'meta_all' => [ '_edit_lock' => 'bad' ] ],
			'unknown ACF field' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'unknown_field' => 'bad' ] ] ],
			'dotted ACF leaf path' => [ 'title' => 'Must never persist', 'meta_all' => [ 'nova_map_group.heading' => 'bad' ] ],
			'unknown nested group field' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'nova_map_group' => [ 'unknown_child' => 'bad' ] ] ] ],
			'partial group value' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'nova_map_group' => [ 'heading' => 'Must preserve missing body' ] ] ] ],
			'partial repeater row' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'nova_map_faqs' => [ [ 'question' => 'Missing answer' ] ] ] ] ],
			'wrong repeater type' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'nova_map_faqs' => 'bad' ] ] ],
			'unknown flexible layout' => [ 'title' => 'Must never persist', 'meta_all' => [ 'acf' => [ 'nova_map_sections' => [ [ 'acf_fc_layout' => 'unknown_layout' ] ] ] ] ],
			'duplicate ACF alias' => [ 'title' => 'Must never persist', 'meta_all' => [ 'nova_map_intro' => 'one', 'acf' => [ 'nova_map_intro' => 'two' ] ] ],
		];
		foreach ( $invalids as $label => $body ) {
			$invalid = $this->request( 'PATCH', $route . '/' . $id, $body, $this->admin_id );
			$this->check( 400 === $invalid->get_status(), 'Rejects ' . $label . ' with HTTP 400.' );
			$this->check( $before_title === get_post( $id )->post_title, 'Invalid ' . $label . ' does not partially change native content.' );
		}
		$denied_meta = $this->request( 'PATCH', $route . '/' . $id, [ 'title' => 'Must never persist', 'meta_all' => [ 'nova_map_locked' => 'bad' ] ], $this->admin_id );
		$this->check( 403 === $denied_meta->get_status() && $before_title === get_post( $id )->post_title, 'Registered-meta authorization is enforced even for administrators, before mutation.' );
		foreach ( [ 0 => 401, $this->users['subscriber'] => 403, $this->users['contributor'] => 403 ] as $user_id => $expected ) {
			$private_read = $this->request( 'GET', $route . '/' . $id, [], (int) $user_id );
			$this->check( $expected === $private_read->get_status(), 'Unauthorized actor ' . (int) $user_id . ' cannot read private CPT fields.' );
			$private_write = $this->request( 'PATCH', $route . '/' . $id, [ 'title' => 'Must never persist' ], (int) $user_id );
			$this->check( $expected === $private_write->get_status() && $before_title === get_post( $id )->post_title, 'Unauthorized actor ' . (int) $user_id . ' cannot edit another user\'s private CPT.' );
		}
		$anon_list = $this->request( 'GET', $route, [], 0 );
		$this->check( 401 === $anon_list->get_status(), 'Anonymous collection read is denied.' );
		$create = $this->request( 'POST', $route, [ 'title' => $this->prefix . ' created draft', 'slug' => $this->prefix . '-created-draft', 'status' => 'draft', 'meta_all' => [ 'acf' => [ 'nova_map_intro' => 'Created via bridge' ] ] ], $this->users['contributor'] );
		$created_id = (int) ( $create->get_data()['id'] ?? 0 );
		$this->check( 201 === $create->get_status() && $created_id > 0, 'Contributor can create an owned draft with a hidden ACF field.' );
		if ( $created_id ) {
			$this->check( 'draft' === get_post_status( $created_id ) && 'Created via bridge' === get_field( $this->key( 'intro' ), $created_id, false ), 'New draft remains unpublished and its ACF value is persisted.' );
			$own_patch = $this->request( 'PATCH', $route . '/' . $created_id, [ 'meta_all' => [ 'acf' => [ 'nova_map_intro' => 'Owned draft update' ] ] ], $this->users['contributor'] );
			$this->check( 200 === $own_patch->get_status(), 'Contributor can edit their own draft.' );
			$publish_denied = $this->request( 'PATCH', $route . '/' . $created_id, [ 'status' => 'publish' ], $this->users['contributor'] );
			$this->check( 403 === $publish_denied->get_status() && 'draft' === get_post_status( $created_id ), 'Contributor cannot publish a draft through the hidden endpoint.' );
			$editor_publish = $this->request( 'PATCH', $route . '/' . $created_id, [ 'status' => 'publish' ], $this->users['editor'] );
			$this->check( 200 === $editor_publish->get_status() && 'publish' === get_post_status( $created_id ), 'Editor publication permission follows native WordPress capabilities.' );
		}
		$create_publish = $this->request( 'POST', $route, [ 'title' => $this->prefix . ' forbidden publish', 'slug' => $this->prefix . '-forbidden-publish', 'status' => 'publish' ], $this->users['contributor'] );
		$this->check( 403 === $create_publish->get_status(), 'Contributor cannot bypass publication permission during creation.' );
		$create_denied_meta = $this->request( 'POST', $route, [ 'title' => $this->prefix . ' denied meta create', 'slug' => $this->prefix . '-denied-meta-create', 'meta_all' => [ $this->meta_key => 'No object ID yet' ] ], $this->admin_id );
		$this->check( 403 === $create_denied_meta->get_status(), 'Object-dependent registered-meta authorization requires draft creation before field update.' );
		$core_hidden = $this->request( 'GET', '/wp/v2/' . $this->post_type . '/' . $id, [], $this->admin_id );
		$this->check( 404 === $core_hidden->get_status() && false === get_post_type_object( $this->post_type )->show_in_rest, 'Bridge writes do not enable the public core CPT REST endpoint.' );
	}

	private function cleanup(): void {
		wp_set_current_user( $this->admin_id ?: $this->old_user );
		foreach ( array_reverse( array_unique( $this->posts ) ) as $id ) { wp_delete_post( $id, true ); }
		foreach ( $this->terms as $id ) { wp_delete_term( $id, 'product_cat' ); }
		if ( ! function_exists( 'wp_delete_user' ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; }
		foreach ( $this->users as $id ) { wp_delete_user( $id ); }
		if ( $this->group_key && function_exists( 'acf_remove_local_field_group' ) ) { acf_remove_local_field_group( $this->group_key ); }
		if ( $this->registered ) { unregister_post_meta( $this->post_type, $this->meta_key ); unregister_post_meta( $this->post_type, 'nova_map_locked' ); unregister_post_type( $this->post_type ); }
		foreach ( $this->options as $name => $snapshot ) {
			if ( $snapshot['exists'] ) { update_option( $name, $snapshot['value'], false ); }
			else { delete_option( $name ); }
		}
		if ( class_exists( 'Nova_Bridge_Suite_Content_Context' ) ) { Nova_Bridge_Suite_Content_Context::flush_config_cache(); }
		wp_set_current_user( $this->old_user );
	}
}

( new Nova_Strategy_Staging_Canary() )->run();
