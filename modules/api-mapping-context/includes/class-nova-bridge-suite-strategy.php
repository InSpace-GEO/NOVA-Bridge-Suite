<?php
/** Strategy-scoped, private authoring contracts. @package NOVA_Bridge_Suite */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Strategy {

	public const OPTION_NAME = 'nova_bridge_suite_strategy';
	public const MAX_BYTES = 10485760;
	private const MAX_ROWS = 10000;
	private const INVENTORY_BATCH = 250;
	private static $catalog = null;
	private static $fingerprints = [];
	private static $inventories = [];
	private static $resources = null;
	private static $prepared = [];
	private static $element_paths = [];

	public static function bootstrap(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ], 1001 );
		add_action( 'init', [ __CLASS__, 'register_prepare_filters' ], 1001 );
		add_filter( 'nova_bridge_strategy_decorate_record', [ __CLASS__, 'decorate_record' ], 10, 3 );
	}

	public static function register_routes(): void {
		foreach ( [
			'/mapping' => [ 'GET', 'mapping_response', 'can_admin' ],
			'/mapping/layout' => [ 'GET', 'layout_response', 'can_admin' ],
			'/mapping/enable-bridge' => [ 'POST', 'enable_bridge_response', 'can_admin' ],
			'/strategy' => [ 'GET', 'get_response', 'can_admin' ],
			'/strategy/import' => [ 'POST', 'import_response', 'can_admin' ],
			'/strategy/remove-import' => [ 'POST', 'remove_import_response', 'can_admin' ],
			'/strategy/profiles' => [ 'POST', 'save_profile_response', 'can_admin' ],
			'/strategy/assign' => [ 'POST', 'assign_response', 'can_admin' ],
			'/strategy/context' => [ 'GET', 'context_response', 'can_read_context' ],
		] as $route => $spec ) {
			register_rest_route( 'nova-bridge/v1', $route, [ 'methods' => $spec[0], 'callback' => [ __CLASS__, $spec[1] ], 'permission_callback' => [ __CLASS__, $spec[2] ] ] );
		}
		self::register_prepare_filters();
	}

	public static function can_admin() {
		return current_user_can( 'manage_options' ) ? true : self::error( 'forbidden', 'Administrator access is required.', is_user_logged_in() ? 403 : 401 );
	}

	/** Enable only a known builder module; the next request loads its runtime. */
	public static function enable_bridge_response( $request ) {
		$permission = self::can_admin();
		if ( is_wp_error( $permission ) ) { return $permission; }
		$builder = $request->get_param( 'builder' );
		if ( ! is_string( $builder ) || ! in_array( $builder, [ 'gutenberg', 'elementor', 'beaver', 'wpbakery', 'divi', 'avada', 'breakdance' ], true ) ) { return self::error( 'builder', 'Choose a supported builder bridge.' ); }
		$key = 'gutenberg' === $builder ? 'gutenberg_bridge' : 'pagebuilder_' . $builder;
		$conflict = nova_bridge_suite_get_module_conflict( $key );
		if ( $conflict ) { return self::error( 'bridge_conflict', 'A standalone bridge is active (' . $conflict . '). Resolve this in Modules before enabling the bundled bridge.', 409 ); }
		$settings = get_option( NOVA_BRIDGE_SUITE_OPTION, [] );
		if ( ! is_array( $settings ) ) { $settings = []; }
		$settings[ $key ] = 1;
		update_option( NOVA_BRIDGE_SUITE_OPTION, $settings );
		if ( empty( nova_bridge_suite_get_settings()[ $key ] ) ) { return self::error( 'bridge_enable', 'The bridge setting could not be saved. Please try again.', 500 ); }
		return rest_ensure_response( [ 'enabled' => true, 'builder' => $builder ] );
	}

	public static function can_read_context( $request ) {
		if ( ! is_user_logged_in() ) { return self::error( 'forbidden', 'Authentication is required.', 401 ); }
		$url = $request->get_param( 'url' );
		if ( ! is_string( $url ) || '' === trim( $url ) ) { return self::error( 'url', 'Supply the intended URL.', 400 ); }
		if ( current_user_can( 'manage_options' ) ) { return true; }
		$contract = self::contract_for_url( $url );
		if ( is_wp_error( $contract ) ) { return $contract; }
		$type = $contract['reference_type'] ?? 'post';
		$id = (int) ( $contract['post_id'] ?: ( $contract['term_id'] ?: $contract['reference_id'] ) );
		if ( ! $id || ! current_user_can( 'term' === $type ? 'edit_term' : 'edit_post', $id ) ) { return self::error( 'forbidden', 'You cannot edit the selected reference.', 403 ); }
		if ( ! $contract['post_id'] && ! $contract['term_id'] ) {
			$object = 'term' === $type ? get_taxonomy( $contract['post_type'] ) : get_post_type_object( $contract['post_type'] );
			$cap = 'term' === $type ? ( $object->cap->manage_terms ?? '' ) : ( $object->cap->create_posts ?? '' );
			if ( ! $cap || ! current_user_can( $cap ) ) { return self::error( 'forbidden', 'You cannot create this content type.', 403 ); }
		}
		return true;
	}

	private static function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( 'nova_strategy_' . $code, $message, [ 'status' => $status ] );
	}

	public static function get_option(): array {
		$default = [ 'version' => 1, 'imported_at' => '', 'source_host' => '', 'rows' => [], 'profiles' => [], 'assignments' => [] ];
		$value = get_option( self::OPTION_NAME, $default );
		$option = is_array( $value ) ? array_merge( $default, $value ) : $default;
		if ( ! isset( $option['imports'] ) ) {
			$option['imports'] = $option['rows'] ? [ [ 'id' => 'legacy', 'name' => 'Previously imported strategy', 'imported_at' => $option['imported_at'], 'source_host' => $option['source_host'], 'rows' => $option['rows'] ] ] : [];
		}
		return $option;
	}

	private static function persist( array $value ): void {
		if ( false === get_option( self::OPTION_NAME, false ) ) { add_option( self::OPTION_NAME, $value, '', 'no' ); }
		else { update_option( self::OPTION_NAME, $value, false ); }
	}

	/** RFC 4180 parsing retains embedded commas and newlines; content never enters storage. */
	public static function parse_import( array $input ) {
		$rows = [];
		if ( isset( $input['csv'] ) ) {
			if ( ! is_string( $input['csv'] ) || strlen( $input['csv'] ) > self::MAX_BYTES ) { return self::error( 'size', 'The CSV must be text of at most 10 MB.' ); }
			$csv = $input['csv'];
			if ( preg_match( '//u', $csv ) !== 1 ) { return self::error( 'encoding', 'Export the CSV as UTF-8.' ); }
			$stream = fopen( 'php://temp/maxmemory:2097152', 'w+' );
			if ( ! $stream ) { return self::error( 'stream', 'Could not open the import stream.', 500 ); }
			fwrite( $stream, preg_replace( '/^\xEF\xBB\xBF/', '', $csv ) );
			rewind( $stream );
			$header = fgetcsv( $stream, 0, ',', '"', '' );
			if ( ! is_array( $header ) ) { fclose( $stream ); return self::error( 'header', 'The CSV has no header.' ); }
			$header = array_map( static function ( $item ) { return strtolower( trim( (string) $item ) ); }, $header );
			if ( 1 !== count( array_keys( $header, 'url', true ) ) ) { fclose( $stream ); return self::error( 'header', 'The CSV must contain exactly one url column.' ); }
			$line = 1;
			while ( false !== ( $data = fgetcsv( $stream, 0, ',', '"', '' ) ) ) {
				++$line;
				if ( [ null ] === $data || [ '' ] === $data ) { continue; }
				if ( count( $data ) !== count( $header ) ) { fclose( $stream ); return self::error( 'row', 'CSV record ' . $line . ' has a different number of columns than the header.' ); }
				$record = array_combine( $header, $data );
				$rows[] = [ 'url' => $record['url'], 'page_type' => $record['page_type'] ?? '', 'locale' => $record['locale'] ?? '' ];
				if ( count( $rows ) > self::MAX_ROWS ) { fclose( $stream ); return self::error( 'rows', 'Import at most 10,000 URLs at once.' ); }
			}
			fclose( $stream );
		} elseif ( isset( $input['urls'] ) && is_array( $input['urls'] ) ) {
			if ( count( $input['urls'] ) > self::MAX_ROWS || strlen( (string) wp_json_encode( $input['urls'] ) ) > self::MAX_BYTES ) { return self::error( 'size', 'Import at most 10,000 URLs and 10 MB.' ); }
			$rows = $input['urls'];
		} else { return self::error( 'input', 'Supply a CSV string or a urls array.' ); }
		if ( ! $rows ) { return self::error( 'empty', 'The strategy contains no URLs.' ); }
		$normalized = [];
		$host = '';
		foreach ( $rows as $index => $row ) {
			$row = is_string( $row ) ? [ 'url' => $row ] : $row;
			if ( ! is_array( $row ) || ! isset( $row['url'] ) || ! is_string( $row['url'] ) ) { return self::error( 'url', 'Invalid URL at record ' . ( $index + 1 ) . '.' ); }
			$url = trim( $row['url'] );
			if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) { $url = home_url( $url ); }
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) || empty( $parts['host'] ) || isset( $parts['user'], $parts['pass'] ) || isset( $parts['user'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) || strlen( $url ) > 2048 ) { return self::error( 'url', 'Record ' . ( $index + 1 ) . ' needs an HTTP(S) URL without credentials, query, or fragment.' ); }
			$row_host = self::host( $parts['host'] );
			if ( $host && $host !== $row_host ) { return self::error( 'host', 'Import one website host per strategy.' ); }
			$host = $row_host;
			$path = self::path( $url );
			if ( false === $path ) { return self::error( 'path', 'The URL at record ' . ( $index + 1 ) . ' contains an invalid path.' ); }
			$id = substr( hash( 'sha256', $host . $path ), 0, 24 );
			$normalized[ $id ] = [ 'id' => $id, 'url' => esc_url_raw( $url ), 'path' => $path, 'page_type' => sanitize_text_field( substr( is_scalar( $row['page_type'] ?? '' ) ? (string) ( $row['page_type'] ?? '' ) : '', 0, 80 ) ), 'locale' => sanitize_text_field( substr( is_scalar( $row['locale'] ?? '' ) ? (string) ( $row['locale'] ?? '' ) : '', 0, 30 ) ) ];
		}
		return [ 'source_host' => $host, 'rows' => array_values( $normalized ) ];
	}

	private static function host( string $host ): string { return preg_replace( '/^www\./', '', strtolower( $host ) ); }

	/** Canonical path matching never fetches an imported URL. */
	public static function path( string $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) { return false; }
		$path = rawurldecode( $parts['path'] ?? '/' );
		if ( preg_match( '/[\x00-\x20\\\\]/', $path ) || preg_match( '#(^|/)\.{1,2}(/|$)#', $path ) || preg_match( '/%(2f|5c)/i', $parts['path'] ?? '' ) ) { return false; }
		return '/' . trim( preg_replace( '#/+#', '/', $path ), '/' ) . ( '/' === $path || '' === trim( $path, '/' ) ? '' : '/' );
	}

	private static function parent_path( string $path ): string { $parent = preg_replace( '#/[^/]+/$#', '/', $path ); return '/' === $parent ? '/' : '/' . trim( $parent, '/' ) . '/'; }

	public static function import_response( $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES + 1048576 ) { return self::error( 'size', 'The import request is too large.' ); }
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) { return self::error( 'input', 'Supply JSON containing csv or urls.' ); }
		$option = self::update_imports( self::get_option(), $input );
		if ( is_wp_error( $option ) ) { return $option; }
		self::persist( $option );
		return self::get_response();
	}

	/** Validate the complete batch before persisting anything. Legacy API inputs still replace. */
	public static function update_imports( array $option, array $input ) {
		$files = $input['files'] ?? null;
		$imports = $option['imports'] ?? [];
		if ( null === $files ) { $files = [ array_merge( $input, [ 'name' => 'Imported strategy' ] ) ]; $imports = []; }
		if ( ! is_array( $files ) || ! $files || count( $files ) + count( $imports ) > 50 ) { return self::error( 'files', 'Keep between 1 and 50 strategy files.' ); }
		$bytes = 0;
		foreach ( $files as $file ) {
			if ( ! is_array( $file ) || ! is_string( $file['name'] ?? null ) ) { return self::error( 'file', 'Each file needs a name and CSV content.' ); }
			$bytes += strlen( (string) wp_json_encode( $file ) );
			if ( $bytes > self::MAX_BYTES ) { return self::error( 'size', 'Upload at most 10 MB per batch.' ); }
			$parsed = self::parse_import( $file );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$imports[] = [ 'id' => wp_generate_uuid4(), 'name' => sanitize_text_field( substr( basename( $file['name'] ), 0, 160 ) ), 'imported_at' => gmdate( 'c' ), 'source_host' => $parsed['source_host'], 'rows' => $parsed['rows'] ];
		}
		return self::combine_imports( $option, $imports );
	}

	public static function combine_imports( array $option, array $imports ) {
		$rows = []; $host = ''; $count = 0;
		foreach ( $imports as $import ) {
			if ( $host && $host !== $import['source_host'] ) { return self::error( 'host', 'All strategy files must target the same website. Remove other-client imports first.' ); }
			$host = $import['source_host']; $count += count( $import['rows'] );
			foreach ( $import['rows'] as $row ) { $rows[ $row['id'] ] = $row; }
		}
		if ( $count > self::MAX_ROWS || strlen( (string) wp_json_encode( $imports ) ) > self::MAX_BYTES ) { return self::error( 'size', 'Keep at most 10,000 URL rows and 10 MB across all imported files.' ); }
		$option['imports'] = array_values( $imports ); $option['rows'] = array_values( $rows ); $option['source_host'] = $host;
		$option['imported_at'] = $imports ? gmdate( 'c' ) : '';
		$option['assignments'] = array_intersect_key( $option['assignments'], $rows );
		return $option;
	}

	public static function remove_import_response( $request ) {
		$id = $request->get_param( 'id' ); $option = self::get_option();
		if ( ! is_string( $id ) || ! in_array( $id, array_column( $option['imports'], 'id' ), true ) ) { return self::error( 'import', 'This imported file no longer exists.', 404 ); }
		$imports = array_values( array_filter( $option['imports'], static function ( $file ) use ( $id ) { return $file['id'] !== $id; } ) );
		$updated = self::combine_imports( $option, $imports );
		if ( is_wp_error( $updated ) ) { return $updated; }
		self::persist( $updated );
		return self::get_response();
	}

	private static function import_summaries( array $option ): array {
		return array_map( static function ( $file ) { return [ 'id' => $file['id'], 'name' => $file['name'], 'imported_at' => $file['imported_at'], 'url_count' => count( $file['rows'] ) ]; }, $option['imports'] );
	}

	/** Editorial objects only; a public builder library is still infrastructure. */
	private static function editorial_post_type( $type ): bool {
		$type = is_string( $type ) ? get_post_type_object( $type ) : $type;
		if ( ! $type instanceof WP_Post_Type ) { return false; }
		$name = (string) $type->name;
		if ( in_array( $name, [ 'post', 'page' ], true ) ) { return true; }
		if ( ! empty( $type->_builtin )
			|| in_array( $name, [ 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_webhook', 'shop_subscription' ], true )
			|| preg_match( '/^(?:acf|acfe|elementor|woocommerce|wc|wp|shop)[_-]|^e-|(?:^|[_-])(?:nav|navigation|menus?|orders?|payments?|countr(?:y|ies)|shipping|tax|logs?|transactions?|issues?|tickets?|fonts?|icons?|snippets?)$/i', $name ) ) { return false; }
		$managed = function_exists( 'nova_bridge_suite_get_managed_blog_post_types' ) ? (array) nova_bridge_suite_get_managed_blog_post_types() : [];
		$registered = get_registered_meta_keys( 'post', $name );
		$suite_owned = in_array( $name, $managed, true )
			|| ( 'service_page' === $name && class_exists( 'SEORAI\\ServicePageCPT\\Plugin', false ) )
			|| ( isset( $registered['blog_intro'], $registered['blog_part_1'] ) );
		if ( $suite_owned ) { return true; }
		$acf = false;
		if ( function_exists( 'acf_get_field_groups' ) ) {
			try { $acf = ! empty( acf_get_field_groups( [ 'post_type' => $name ] ) ); }
			catch ( Throwable $error ) { $acf = false; }
		}
		$builder = post_type_supports( $name, 'elementor' ) || post_type_supports( $name, 'fl-builder' )
			|| ( function_exists( 'vc_editor_post_types' ) && in_array( $name, (array) vc_editor_post_types(), true ) )
			|| ( function_exists( 'et_builder_enabled_for_post_type' ) && et_builder_enabled_for_post_type( $name ) );
		$signals = [
			'public' => ! empty( $type->public ), 'publicly_queryable' => ! empty( $type->publicly_queryable ),
			'show_in_rest' => ! empty( $type->show_in_rest ), 'show_ui' => ! empty( $type->show_ui ),
			'title' => post_type_supports( $name, 'title' ), 'editor' => post_type_supports( $name, 'editor' ),
			'excerpt' => post_type_supports( $name, 'excerpt' ), 'custom_fields' => post_type_supports( $name, 'custom-fields' ),
			'acf' => $acf, 'builder' => $builder, 'suite_owned' => false, 'denied_family' => false,
		];
		$include = $signals['show_ui'] && ( $signals['editor'] || $signals['excerpt'] || $signals['custom_fields'] || $acf || $builder );
		return (bool) apply_filters( 'nova_bridge_suite_content_context_include_post_type', $include, $type, $signals );
	}

	/** WooCommerce workflow/index pages are not content-layout references. */
	private static function commerce_page_ids(): array {
		$ids = [];
		foreach ( [ 'shop', 'cart', 'checkout', 'myaccount' ] as $page ) {
			// Keep the configured source as well as a language-filtered WooCommerce ID.
			$configured = (int) get_option( 'woocommerce_' . $page . '_page_id' );
			$current = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( $page ) : $configured;
			if ( $configured > 0 ) { $ids[] = $configured; }
			if ( $current > 0 ) { $ids[] = $current; }
		}
		foreach ( array_unique( $ids ) as $id ) {
			if ( function_exists( 'pll_get_post_translations' ) ) {
				foreach ( (array) pll_get_post_translations( $id ) as $translated_id ) { if ( (int) $translated_id > 0 ) { $ids[] = (int) $translated_id; } }
			}
			if ( false !== has_filter( 'wpml_element_trid' ) && false !== has_filter( 'wpml_get_element_translations' ) ) {
				$trid = apply_filters( 'wpml_element_trid', null, $id, 'post_page' );
				if ( $trid ) {
					foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_page' ) as $translation ) {
						$translated_id = is_object( $translation ) ? ( $translation->element_id ?? 0 ) : ( is_array( $translation ) ? ( $translation['element_id'] ?? 0 ) : 0 );
						if ( (int) $translated_id > 0 ) { $ids[] = (int) $translated_id; }
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Old duplicate workflow pages can survive after WooCommerce page settings change. */
	private static function commerce_workflow_page( WP_Post $post ): bool {
		if ( 'page' !== $post->post_type ) { return false; }
		if ( in_array( (int) $post->ID, self::commerce_page_ids(), true ) ) { return true; }
		$content = (string) $post->post_content;
		foreach ( [ 'woocommerce/cart', 'woocommerce/checkout' ] as $block ) {
			if ( function_exists( 'has_block' ) && has_block( $block, $content ) ) { return true; }
		}
		$pattern = get_shortcode_regex( [ 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account' ] );
		if ( preg_match_all( '/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				// Escaped documentation examples such as [[woocommerce_cart]] do not render a cart.
				if ( '[' !== $match[1] || ']' !== $match[6] ) { return true; }
			}
		}
		return false;
	}

	/** Cached per request, bounded, and explicit about incomplete site discovery. */
	private static function catalog(): array {
		if ( null !== self::$catalog ) { return self::$catalog; }
		$types = [];
		foreach ( get_post_types( [], 'objects' ) as $type ) {
			if ( self::editorial_post_type( $type ) && current_user_can( $type->cap->edit_posts ) ) { $types[] = $type->name; }
		}
		$entities = [];
		// Enumerate in batches: a recent-page sample cannot establish unique-layout coverage.
		if ( $types ) {
			$page = 1;
			do {
				$ids = get_posts( [ 'post_type' => $types, 'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future' ], 'post__not_in' => self::commerce_page_ids(), 'posts_per_page' => self::INVENTORY_BATCH, 'paged' => $page++, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true, 'no_found_rows' => true ] );
				foreach ( $ids as $id ) { $entity = self::entity( 'post', (int) $id ); if ( $entity ) { $entities[] = $entity; } }
			} while ( count( $ids ) === self::INVENTORY_BATCH );
		}
		if ( taxonomy_exists( 'product_cat' ) ) {
			$offset = 0;
			do {
				$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => self::INVENTORY_BATCH, 'offset' => $offset, 'orderby' => 'term_id', 'order' => 'ASC', 'suppress_filter' => true ] );
				if ( is_wp_error( $terms ) ) { throw new RuntimeException( 'Product category inventory failed.' ); }
				foreach ( $terms as $term ) { $entity = self::entity( 'term', (int) $term->term_id ); if ( $entity ) { $entities[] = $entity; } }
				$offset += self::INVENTORY_BATCH;
			} while ( count( $terms ) === self::INVENTORY_BATCH );
		}
		self::$catalog = [ 'entities' => $entities, 'truncated' => false ];
		return self::$catalog;
	}

	public static function entity( string $type, int $id ) {
		if ( ! in_array( $type, [ 'post', 'term' ], true ) || ! current_user_can( 'term' === $type ? 'edit_term' : 'edit_post', $id ) ) { return null; }
		$object = 'term' === $type ? get_term( $id, 'product_cat' ) : get_post( $id );
		if ( ! $object || is_wp_error( $object ) ) { return null; }
		if ( 'post' === $type && ( ! self::editorial_post_type( $object->post_type )
			|| in_array( $object->post_status, [ 'trash', 'auto-draft', 'inherit' ], true )
			|| self::commerce_workflow_page( $object ) ) ) { return null; }
		$url = 'term' === $type ? get_term_link( $object ) : get_permalink( $id );
		if ( ! is_string( $url ) ) { return null; }
		$parts = wp_parse_url( $url );
		$clean_url = is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true )
			&& ! empty( $parts['host'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) && ! isset( $parts['user'] )
			&& self::host( $parts['host'] ) === self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$path = $clean_url ? self::path( $url ) : false;
		// Draft preview/query URLs remain selectable references, never fake homepages.
		$has_public_path = is_string( $path );
		return [ 'reference_type' => $type, 'reference_id' => $id, 'reference_post_id' => 'post' === $type ? $id : 0, 'reference_term_id' => 'term' === $type ? $id : 0, 'post_type' => 'term' === $type ? 'product_cat' : $object->post_type, 'title' => 'term' === $type ? $object->name : $object->post_title, 'url' => $url, 'path' => $has_public_path ? $path : null, 'parent_path' => $has_public_path ? self::parent_path( $path ) : null, 'has_public_path' => $has_public_path, 'post_status' => 'post' === $type ? $object->post_status : 'term' ];
	}

	/** Values are removed, but ordered node types, attributes, and actual field schemas remain. */
	public static function shape( $value, string $key = '', int $depth = 0 ) {
		if ( $depth > 50 ) { return [ 'depth_limit' => true ]; }
		if ( is_object( $value ) ) { $value = (array) $value; }
		if ( is_array( $value ) ) {
			$result = [];
			$list = [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			foreach ( $value as $name => $item ) {
				if ( in_array( (string) $name, [ 'id', '_id', 'uuid', '_element_id', 'node', 'parent', 'innerHTML', 'innerContent' ], true ) ) { continue; }
				$result[ $list ? count( $result ) : (string) $name ] = self::shape( $item, (string) $name, $depth + 1 );
			}
			if ( ! $list ) { ksort( $result ); }
			return $result;
		}
		if ( in_array( $key, [ 'elType', 'widgetType', 'blockName', 'type', 'tag', 'acf_fc_layout', 'template_id', 'templateID', 'layout', 'columns', 'column', 'width', 'flex_direction', 'content_width', 'tagName', 'html_tag', 'level', 'parent_index', 'position' ], true ) ) { return $value; }
		return gettype( $value );
	}

	private static function acf_schema( array $fields ): array {
		$schema = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) { continue; }
			$item = [ 'key' => (string) ( $field['key'] ?? '' ), 'name' => (string) ( $field['name'] ?? '' ), 'type' => (string) ( $field['type'] ?? '' ) ];
			if ( ! empty( $field['sub_fields'] ) ) { $item['sub_fields'] = self::acf_schema( $field['sub_fields'] ); }
			foreach ( $field['layouts'] ?? [] as $layout ) { $item['layouts'][] = [ 'name' => $layout['name'] ?? '', 'sub_fields' => self::acf_schema( $layout['sub_fields'] ?? [] ) ]; }
			$schema[] = $item;
		}
		return $schema;
	}

	private static function acf_groups( array $entity ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) { return []; }
		$acf_id = 'term' === $entity['reference_type'] ? 'product_cat_' . $entity['reference_id'] : $entity['reference_id'];
		$groups = acf_get_field_groups( [ 'post_id' => $acf_id ] );
		$result = [];
		foreach ( is_array( $groups ) ? $groups : [] as $group ) { $group['fields'] = acf_get_fields( $group ) ?: []; $result[] = $group; }
		return $result;
	}

	/** Capture selected flexible layouts and nested group/repeater structures, never editorial values. */
	private static function acf_value_layout( array $field, $value ): array {
		$type = $field['type'] ?? '';
		if ( ! in_array( $type, [ 'flexible_content', 'repeater', 'group' ], true ) ) { return []; }
		if ( ! is_array( $value ) ) { return 'repeater' === $type ? [ [ 'type' => 'repeater' ] ] : []; }
		$rows = 'group' === $type ? [ $value ] : $value;
		$result = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$layout = [ 'type' => $type ];
			$children = $field['sub_fields'] ?? [];
			if ( 'flexible_content' === $type ) {
				$layout['layout'] = $row['acf_fc_layout'] ?? '';
				foreach ( $field['layouts'] ?? [] as $candidate ) { if ( ( $candidate['name'] ?? '' ) === $layout['layout'] ) { $children = $candidate['sub_fields'] ?? []; break; } }
			}
			foreach ( $children as $child ) { $nested = self::acf_value_layout( $child, $row[ $child['name'] ?? '' ] ?? ( $row[ $child['key'] ?? '' ] ?? null ) ); if ( $nested ) { $layout['children'][ $child['name'] ] = $nested; } }
			if ( 'group' !== $type || ! empty( $layout['children'] ) ) { $result[] = $layout; }
		}
		// Repeating the same schema with more content rows does not create a new template.
		if ( 'repeater' === $type ) {
			$distinct = [];
			foreach ( $result as $layout ) { $distinct[ (string) wp_json_encode( $layout ) ] = $layout; }
			return $distinct ? array_values( $distinct ) : [ [ 'type' => 'repeater' ] ];
		}
		return $result;
	}

	public static function fingerprint( array $entity ): array {
		$key = $entity['reference_type'] . ':' . $entity['reference_id'];
		if ( isset( self::$fingerprints[ $key ] ) ) { return self::$fingerprints[ $key ]; }
		$id = $entity['reference_id'];
		$builders = [];
		$structure = [];
		$template = 'post' === $entity['reference_type'] ? (string) get_page_template_slug( $id ) : 'taxonomy-product_cat';
		$schema = [];
		$acf_layouts = [];
		foreach ( self::acf_groups( $entity ) as $group ) {
			$schema[] = [ 'key' => $group['key'] ?? '', 'fields' => self::acf_schema( $group['fields'] ) ];
			if ( function_exists( 'get_field' ) ) {
				foreach ( $group['fields'] as $field ) {
					if ( ! in_array( $field['type'] ?? '', [ 'flexible_content', 'repeater', 'group' ], true ) ) { continue; }
					$acf_id = 'term' === $entity['reference_type'] ? 'product_cat_' . $id : $id;
					$acf_layouts[ $field['key'] ] = self::acf_value_layout( $field, get_field( $field['key'], $acf_id, false ) );
				}
			}
		}
		if ( 'post' === $entity['reference_type'] ) {
			$post = get_post( $id );
			$content = (string) $post->post_content;
			foreach ( [ 'elementor' => [ '_elementor_data' ], 'beaver' => [ '_fl_builder_data' ], 'breakdance' => [ '_breakdance_data', 'breakdance_data' ] ] as $builder => $keys ) {
				foreach ( $keys as $meta_key ) {
					$data = get_post_meta( $id, $meta_key, true );
					if ( empty( $data ) ) { continue; }
					if ( is_string( $data ) ) { $data = json_decode( $data, true ); }
					if ( ! is_array( $data ) ) { $structure[ $builder ] = [ 'uninspectable_document' => $id ]; }
					else {
						if ( 'beaver' === $builder ) {
							// Beaver node IDs and parent IDs differ per document; encode parent indices.
							$indexes = array_flip( array_map( 'strval', array_keys( $data ) ) );
							$nodes = [];
							foreach ( $data as $node ) { $node = (array) $node; $parent = (string) ( $node['parent'] ?? '' ); $node['parent_index'] = isset( $indexes[ $parent ] ) ? $indexes[ $parent ] : -1; $nodes[] = $node; }
							$data = $nodes;
						}
						$structure[ $builder ] = self::shape( $data );
					}
					$builders[] = $builder;
					break;
				}
			}
			if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) { $builders[] = 'gutenberg'; $structure['gutenberg'] = self::shape( parse_blocks( $content ) ); }
			foreach ( [ 'wpbakery' => 'vc_', 'divi' => 'et_pb_', 'avada' => 'fusion_' ] as $builder => $prefix ) {
				if ( false === strpos( $content, '[' . $prefix ) ) { continue; }
				$builders[] = $builder;
				preg_match_all( '/\[(\/?)(' . preg_quote( $prefix, '/' ) . '[a-zA-Z0-9_-]+)([^\]]*)\]/', $content, $matches, PREG_SET_ORDER );
				foreach ( $matches as $match ) { $attrs = shortcode_parse_atts( $match[3] ); $structure[ $builder ][] = [ 'tag' => $match[1] . $match[2], 'attrs' => self::shape( is_array( $attrs ) ? $attrs : [] ) ]; }
			}
		}
		$meta_keys = [];
		$meta_type = 'term' === $entity['reference_type'] ? 'term' : 'post';
		$registered = array_merge( get_registered_meta_keys( $meta_type ), get_registered_meta_keys( $meta_type, $entity['post_type'] ) );
		foreach ( $registered as $name => $registration ) { if ( ! is_protected_meta( $name, $entity['reference_type'] ) ) { $meta_keys[ $name ] = $registration['type'] ?? 'string'; } }
		ksort( $meta_keys );
		$native = empty( $builders ) && empty( $schema ) && empty( $meta_keys );
		// NOVA's own CPTs already ship complete, field-level authoring instructions.
		$self_describing = 'service_page' === $entity['post_type'] || 0 === strpos( $entity['post_type'], 'nova_blog' ) || 0 === strpos( $entity['post_type'], 'seor_blog' );
		if ( 'post' === $entity['reference_type'] && isset( $registered['blog_intro'] ) && isset( $registered['blog_part_1'] ) ) { $self_describing = true; }
		$signature = hash( 'sha256', (string) wp_json_encode( [ 'algorithm' => 1, 'type' => $entity['reference_type'], 'post_type' => $entity['post_type'], 'template' => $template, 'builders' => $structure, 'acf' => $schema, 'acf_layouts' => $acf_layouts, 'meta' => $meta_keys ] ) );
		return self::$fingerprints[ $key ] = [ 'signature' => $signature, 'post_type' => $entity['post_type'], 'template' => $template, 'builders' => array_values( array_unique( $builders ) ), 'needs_mapping' => ! $native && ! $self_describing, 'self_describing' => $self_describing ];
	}

	/** Concrete creation defaults preserve the intended hierarchy; they never mutate a reference. */
	private static function creation_plan( array $row, array $reference ): array {
		$defaults = [ 'slug' => sanitize_title( basename( trim( $row['path'], '/' ) ) ) ];
		$type = $reference['reference_type'];
		$object = 'term' === $type ? get_term( $reference['reference_id'], 'product_cat' ) : get_post( $reference['reference_id'] );
		$registration = 'term' === $type ? get_taxonomy( 'product_cat' ) : get_post_type_object( $reference['post_type'] );
		$parent = 'term' === $type ? (int) $object->parent : (int) $object->post_parent;
		if ( 'post' === $type ) { $defaults['template'] = (string) get_page_template_slug( $reference['reference_id'] ); }
		$desired_parent = self::parent_path( $row['path'] );
		if ( $reference['parent_path'] === $desired_parent ) { if ( ! empty( $registration->hierarchical ) ) { $defaults['parent'] = $parent; } return [ 'ready' => true, 'defaults' => $defaults, 'warning' => '' ]; }
		if ( empty( $registration->hierarchical ) ) { return [ 'ready' => false, 'defaults' => $defaults, 'warning' => 'The selected content type uses a different permalink prefix. Select an example with the intended URL hierarchy or configure its permalink structure.' ]; }
		if ( '/' === $desired_parent && 'page' === $reference['post_type'] ) { $defaults['parent'] = 0; return [ 'ready' => true, 'defaults' => $defaults, 'warning' => '' ]; }
		$matches = [];
		foreach ( self::catalog()['entities'] as $candidate ) { if ( $candidate['reference_type'] === $type && $candidate['post_type'] === $reference['post_type'] && $candidate['path'] === $desired_parent ) { $matches[] = $candidate; } }
		if ( 1 === count( $matches ) ) { $defaults['parent'] = $matches[0]['reference_id']; return [ 'ready' => true, 'defaults' => $defaults, 'warning' => '' ]; }
		return [ 'ready' => false, 'defaults' => $defaults, 'warning' => 'The intended parent URL does not resolve uniquely. Create or select the parent before publishing this child URL.' ];
	}

	private static function row_analysis( array $row, array $option ): array {
		$catalog = self::catalog();
		$exact = [];
		$siblings = [];
		foreach ( $catalog['entities'] as $entity ) {
			if ( $entity['path'] === $row['path'] ) { $exact[] = $entity; }
			elseif ( $entity['parent_path'] === self::parent_path( $row['path'] ) ) { $siblings[] = $entity; }
		}
		// An exact WordPress resolution remains available beyond the bounded site catalog.
		$resolved_id = url_to_postid( home_url( $row['path'] ) );
		if ( $resolved_id && ! in_array( $resolved_id, array_column( $exact, 'reference_post_id' ), true ) ) { $resolved = self::entity( 'post', $resolved_id ); if ( $resolved && $resolved['path'] === $row['path'] ) { $exact[] = $resolved; } }
		$result = array_merge( $row, [ 'parent_path' => self::parent_path( $row['path'] ), 'depth' => count( array_filter( explode( '/', $row['path'] ), 'strlen' ) ), 'post_id' => 0, 'term_id' => 0, 'reference_type' => 'post', 'reference_id' => 0, 'reference_post_id' => 0, 'reference_term_id' => 0, 'signature' => '', 'profile_id' => '', 'needs_mapping' => true, 'status' => 'needs_reference', 'basis' => 'No unique existing layout matches this future URL. Select an example.', 'candidates' => [] ] );
		$reference = null;
		$assigned = $option['assignments'][ $row['id'] ] ?? null;
		if ( 1 === count( $exact ) ) { $reference = $exact[0]; $result['post_id'] = $reference['reference_post_id']; $result['term_id'] = $reference['reference_term_id']; $result['basis'] = 'Existing WordPress object at this exact path.'; }
		elseif ( is_array( $assigned ) ) {
			$reference = self::entity( $assigned['reference_type'], (int) $assigned['reference_id'] );
			if ( $reference ) {
				$current = self::fingerprint( $reference );
				if ( $current['signature'] !== ( $assigned['signature'] ?? '' ) ) { $reference = null; $result['basis'] = 'The selected example changed layout. Select and confirm its current layout again.'; }
				else { $result['basis'] = 'Example explicitly selected by an administrator.'; }
			}
		}
		$candidates = $exact ?: $siblings;
		$unique = [];
		foreach ( $candidates as $candidate ) {
			$fp = self::fingerprint( $candidate );
			if ( ! isset( $unique[ $fp['signature'] ] ) ) { $unique[ $fp['signature'] ] = array_merge( $candidate, $fp ); }
		}
		$result['candidates'] = array_values( $unique );
		if ( ! $reference && ! $assigned && 0 === count( $exact ) && 1 === count( $unique ) && ! $catalog['truncated'] ) { $result['status'] = 'suggested'; $result['basis'] = 'One layout exists among immediate URL siblings. Confirm an example before posting.'; }
		if ( $catalog['truncated'] && ! $reference ) { $result['basis'] = 'Site inventory exceeded 5,000 posts or terms. Select an explicit example; automatic inference is disabled.'; }
		if ( $reference ) {
			$fp = self::fingerprint( $reference );
			$profile = $option['profiles'][ $fp['signature'] ] ?? null;
			$result = array_merge( $result, $fp, array_intersect_key( $reference, array_flip( [ 'reference_type', 'reference_id', 'reference_post_id', 'reference_term_id', 'post_type' ] ) ) );
			$result['status'] = ! $fp['needs_mapping'] ? 'native' : ( $profile ? 'ready' : 'needs_mapping' );
			$result['profile_id'] = $profile ? $fp['signature'] : '';
			if ( $profile && 'ready' === $result['status'] ) {
				$bound = self::profile_fields_for_entity( $profile, $reference );
				$inventory = array_column( self::field_inventory( $reference ), null, 'path' );
				if ( count( $bound ) < count( $profile['fields'] ) ) { $result['status'] = 'needs_mapping'; $result['basis'] .= ' Some saved fields cannot be bound uniquely to this document.'; }
				foreach ( $bound as $pointer => $field ) { if ( empty( $inventory[ $pointer ]['writable'] ) ) { $result['status'] = 'needs_mapping'; $result['basis'] .= ' A mapped field has no writable transport.'; break; } }
			}
			if ( ! $result['post_id'] && ! $result['term_id'] && $fp['builders'] ) { $result['status'] = 'needs_layout'; $result['basis'] .= ' Create or clone this layout for the new URL, then inspect its own bridge before posting.'; }
			if ( ! $result['post_id'] && ! $result['term_id'] ) {
				$plan = self::creation_plan( $result, $reference );
				$result['create_defaults'] = $plan['defaults'];
				if ( ! $plan['ready'] ) { $result['status'] = 'needs_parent'; $result['basis'] .= ' ' . $plan['warning']; }
			}
		}
		return $result;
	}

	public static function get_response() {
		$option = self::get_option();
		$rows = [];
		$layouts = [];
		$summary = [ 'total' => count( $option['rows'] ), 'ready' => 0, 'native' => 0, 'needs_mapping' => 0, 'needs_reference' => 0, 'suggested' => 0, 'needs_layout' => 0, 'needs_parent' => 0 ];
		foreach ( $option['rows'] as $row ) {
			$row = self::row_analysis( $row, $option );
			$rows[] = $row;
			++$summary[ $row['status'] ];
			if ( ! $row['signature'] ) { continue; }
			$sig = $row['signature'];
			if ( ! isset( $layouts[ $sig ] ) ) {
				$reference = self::entity( $row['reference_type'], $row['reference_id'] );
				$profile = $option['profiles'][ $sig ] ?? null;
				if ( $profile ) { $saved_reference = self::entity( $profile['reference_type'], $profile['reference_id'] ); if ( $saved_reference && self::fingerprint( $saved_reference )['signature'] === $sig ) { $reference = $saved_reference; } }
				$layouts[ $sig ] = array_merge( self::fingerprint( $reference ), $reference, [ 'post_ids' => [], 'row_ids' => [], 'profile' => $option['profiles'][ $sig ] ?? null, 'fields' => self::field_inventory( $reference ) ] );
			}
			$layouts[ $sig ]['row_ids'][] = $row['id'];
			if ( $row['post_id'] ) { $layouts[ $sig ]['post_ids'][] = $row['post_id']; }
		}
		usort( $rows, static function ( $a, $b ) { return strnatcasecmp( $a['path'], $b['path'] ); } );
		$summary['unique_layouts'] = count( $layouts );
		return rest_ensure_response( [ 'version' => 1, 'imports' => self::import_summaries( $option ), 'imported_at' => $option['imported_at'], 'source_host' => $option['source_host'], 'site_host' => self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), 'rows' => $rows, 'layouts' => array_values( $layouts ), 'profiles' => array_values( $option['profiles'] ), 'summary' => $summary, 'inventory_truncated' => self::catalog()['truncated'], 'references' => self::catalog()['entities'] ] );
	}

	/** One inventory, two scopes. Detailed field extraction is deferred until selection. */
	public static function mapping_response( $request ) {
		$scope = (string) $request->get_param( 'scope' );
		if ( ! in_array( $scope, [ '', 'all', 'strategy' ], true ) ) { return self::error( 'scope', 'Choose all or strategy scope.' ); }
		$scope = $scope ?: 'all';
		$option = self::get_option();
		$groups = [];
		$unresolved = [];
		$rows = [];
		foreach ( self::catalog()['entities'] as $entity ) {
			$fp = self::fingerprint( $entity );
			$sig = $fp['signature'];
			if ( ! isset( $groups[ $sig ] ) ) {
				$groups[ $sig ] = array_merge( $entity, $fp, [ 'profile' => $option['profiles'][ $sig ] ?? null, 'members' => [], 'rows' => [] ] );
			}
			$groups[ $sig ]['members'][] = $entity;
			// Prefer a published representative over a draft.
			if ( 'publish' === $entity['post_status'] && 'publish' !== $groups[ $sig ]['post_status'] ) { $groups[ $sig ] = array_merge( $groups[ $sig ], $entity ); }
		}
		foreach ( $option['rows'] as $row ) {
			$row = self::row_analysis( $row, $option );
			$rows[] = $row;
			if ( ! $row['signature'] || ! $row['reference_id'] ) { $unresolved[] = $row; continue; }
			if ( isset( $groups[ $row['signature'] ] ) ) { $groups[ $row['signature'] ]['rows'][] = $row; }
		}
		$total = count( $groups );
		if ( 'strategy' === $scope ) { $groups = array_filter( $groups, static function ( $g ) { return ! empty( $g['rows'] ); } ); }
		foreach ( $groups as &$group ) {
			$preferred = $group['profile'] ? self::entity( $group['profile']['reference_type'], (int) $group['profile']['reference_id'] ) : null;
			if ( ! $preferred && 'strategy' === $scope && ! empty( $group['rows'] ) ) { $row = $group['rows'][0]; $preferred = self::entity( $row['reference_type'], (int) $row['reference_id'] ); }
			if ( $preferred && self::fingerprint( $preferred )['signature'] === $group['signature'] ) { $group = array_merge( $group, $preferred ); }
			$group['label'] = $group['profile']['label'] ?? $group['title'];
			$group['mapping_status'] = $group['profile'] ? 'mapped' : ( $group['needs_mapping'] ? 'unmapped' : 'native' );
		}
		unset( $group );
		usort( $groups, static function ( $a, $b ) { return strnatcasecmp( $a['label'], $b['label'] ); } );
		$response = rest_ensure_response( [ 'scope' => $scope, 'layouts' => array_values( $groups ), 'unresolved' => $unresolved, 'rows' => $rows, 'references' => self::catalog()['entities'], 'imports' => self::import_summaries( $option ), 'imported_at' => $option['imported_at'], 'source_host' => $option['source_host'], 'site_host' => self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), 'summary' => [ 'site_layouts' => $total, 'visible_layouts' => count( $groups ), 'site_items' => count( self::catalog()['entities'] ), 'strategy_urls' => count( $rows ), 'unresolved' => count( $unresolved ) ] ] );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public static function layout_response( $request ) {
		$entity = self::request_reference( [ 'reference_type' => $request->get_param( 'reference_type' ), 'reference_id' => $request->get_param( 'reference_id' ) ] );
		if ( ! $entity ) { return self::error( 'reference', 'Select an editable reference.', 404 ); }
		$fp = self::fingerprint( $entity );
		if ( $request->get_param( 'signature' ) && $request->get_param( 'signature' ) !== $fp['signature'] ) { return self::error( 'changed', 'This layout changed. Refresh the inventory.', 409 ); }
		$profile = self::get_option()['profiles'][ $fp['signature'] ] ?? null;
		$providers = [];
		if ( 'post' === $entity['reference_type'] ) {
			$q = new WP_REST_Request( 'GET' ); $q->set_param( 'post_id', $entity['reference_id'] );
			$bridge = Nova_Bridge_Suite_Content_Context::get_bridge_fields_response( $q );
			if ( ! is_wp_error( $bridge ) ) { $providers = $bridge->get_data()['providers']; }
		}
		$bound = $profile ? self::profile_fields_for_entity( $profile, $entity ) : [];
		$missing = $profile ? count( $profile['fields'] ) - count( $bound ) : 0;
		if ( $profile ) { $profile['fields'] = $bound; }
		$response = rest_ensure_response( array_merge( $entity, $fp, [ 'profile' => $profile, 'fields' => self::field_inventory( $entity ), 'providers' => $providers, 'unbound_fields' => $missing, 'preview_url' => class_exists( 'Nova_Bridge_Suite_Mapping_Preview' ) ? Nova_Bridge_Suite_Mapping_Preview::url( $entity ) : '' ] ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function request_reference( array $input ) {
		$type = $input['reference_type'] ?? 'post';
		$id = $input['reference_id'] ?? ( $input['reference_post_id'] ?? ( $input['reference_term_id'] ?? 0 ) );
		if ( ! is_string( $type ) || ! is_numeric( $id ) || (int) $id < 1 ) { return null; }
		return self::entity( $type, (int) $id );
	}

	public static function assign_response( $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) { return self::error( 'input', 'Supply a row_id and reference.' ); }
		$option = self::get_option();
		$ids = isset( $input['row_ids'] ) ? $input['row_ids'] : [ $input['row_id'] ?? '' ];
		if ( ! is_array( $ids ) || ! $ids || count( $ids ) > self::MAX_ROWS ) { return self::error( 'row', 'Supply between one and 10,000 row IDs.' ); }
		$known = array_fill_keys( array_column( $option['rows'], 'id' ), true );
		foreach ( $ids as $id ) { if ( ! is_string( $id ) || ! isset( $known[ $id ] ) ) { return self::error( 'row', 'A selected strategy row does not exist. No assignments were changed.' ); } }
		if ( ! empty( $input['clear'] ) ) { foreach ( $ids as $id ) { unset( $option['assignments'][ $id ] ); } self::persist( $option ); return self::get_response(); }
		$reference = self::request_reference( $input );
		if ( ! $reference ) { return self::error( 'reference', 'Select an editable WordPress post or product category.' ); }
		foreach ( $ids as $id ) { $option['assignments'][ $id ] = [ 'reference_type' => $reference['reference_type'], 'reference_id' => $reference['reference_id'], 'signature' => self::fingerprint( $reference )['signature'] ]; }
		self::persist( $option );
		return self::get_response();
	}

	/** Inventory only exposes discovered, concrete fields; arbitrary pointers are not accepted. */
	public static function field_inventory( array $entity ): array {
		$key = $entity['reference_type'] . ':' . $entity['reference_id'];
		if ( isset( self::$inventories[ $key ] ) ) { return self::$inventories[ $key ]; }
		if ( null === self::$resources ) { self::$resources = class_exists( 'Nova_Bridge_Suite_Content_Context' ) ? Nova_Bridge_Suite_Content_Context::discover_resources()['resources'] : []; }
		$fields = [];
		foreach ( self::$resources as $resource ) {
			if ( ( 'post' === $entity['reference_type'] && ( $resource['post_type'] ?? '' ) === $entity['post_type'] ) || ( 'term' === $entity['reference_type'] && ( $resource['taxonomy'] ?? '' ) === $entity['post_type'] ) ) {
				foreach ( $resource['fields'] as $field ) { if ( ! empty( $field['path'] ) && 'builder' !== ( $field['source'] ?? '' ) && 'acf' !== ( $field['source'] ?? '' ) ) { $fields[ $field['path'] ] = $field; } }
			}
		}
		if ( ! $fields ) {
			foreach ( 'term' === $entity['reference_type'] ? [ 'name', 'description', 'slug' ] : [ 'title', 'content', 'excerpt', 'slug', 'status', 'featured_media', 'template' ] as $name ) { $fields[ '/' . $name ] = [ 'path' => '/' . $name, 'label' => ucfirst( $name ), 'type' => 'string', 'source' => 'native', 'writable' => true ]; }
		}
		$add_acf = static function ( array $items, string $prefix, bool $rest ) use ( &$fields, &$add_acf ) {
			foreach ( $items as $field ) {
				$name = $field['name'] ?? '';
				if ( ! $name ) { continue; }
				$pointer = $prefix . '/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $name );
				$fields[ $pointer ] = [ 'path' => $pointer, 'label' => $field['label'] ?? $name, 'type' => $field['type'] ?? 'string', 'source' => 'acf', 'writable' => $rest, 'native_description' => $field['instructions'] ?? '', 'description' => '', 'mapping' => '', 'acf_key' => $field['key'] ?? '', 'availability' => $rest ? 'available' : 'unavailable' ];
				if ( ! empty( $field['sub_fields'] ) ) { $add_acf( $field['sub_fields'], $pointer . ( 'repeater' === ( $field['type'] ?? '' ) ? '/*' : '' ), $rest ); }
			}
		};
		$native_object = 'term' === $entity['reference_type'] ? get_taxonomy( $entity['post_type'] ) : get_post_type_object( $entity['post_type'] );
		foreach ( self::acf_groups( $entity ) as $group ) { $add_acf( $group['fields'], '/acf', ! empty( $group['show_in_rest'] ) && ! empty( $native_object->show_in_rest ) ); }
		if ( 'post' === $entity['reference_type'] && class_exists( 'Nova_Bridge_Suite_Content_Transport' ) ) {
			$catalog = Nova_Bridge_Suite_Content_Transport::field_catalog( $entity['post_type'], $entity['reference_id'] );
			$route = Nova_Bridge_Suite_Content_Transport::route_for( $entity['post_type'] );
			foreach ( $catalog['acf'] as $name => $field ) {
				if ( ! current_user_can( 'edit_post_meta', $entity['reference_id'], $name ) ) { continue; }
				$pointer = '/meta_all/acf/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $name );
				$fields[ $pointer ] = [ 'path' => $pointer, 'label' => $field['label'] ?? $name, 'type' => $field['type'] ?? 'string', 'source' => 'acf', 'writable' => true, 'transport' => 'nova_content_bridge', 'route' => $route . '/{id}', 'request_path' => $pointer, 'methods' => [ 'POST', 'PUT', 'PATCH' ], 'acf_key' => $field['key'], 'native_description' => $field['instructions'] ?? '', 'availability' => 'available', 'description' => '', 'mapping' => '' ];
			}
			foreach ( $catalog['meta'] as $name => $field ) {
				if ( ! current_user_can( 'edit_post_meta', $entity['reference_id'], $name ) ) { continue; }
				$pointer = '/meta_all/' . str_replace( [ '~', '/' ], [ '~0', '~1' ], $name );
				$fields[ $pointer ] = [ 'path' => $pointer, 'label' => $name, 'type' => $field['type'] ?? 'string', 'source' => 'meta', 'writable' => true, 'transport' => 'nova_content_bridge', 'route' => $route . '/{id}', 'request_path' => $pointer, 'methods' => [ 'POST', 'PUT', 'PATCH' ], 'native_description' => $field['description'] ?? '', 'availability' => 'available', 'description' => '', 'mapping' => '' ];
			}
		}
		if ( 'post' === $entity['reference_type'] && class_exists( 'Nova_Bridge_Suite_Content_Context' ) ) {
			$request = new WP_REST_Request( 'GET' );
			$request->set_param( 'post_id', $entity['reference_id'] );
			$bridge = Nova_Bridge_Suite_Content_Context::get_bridge_fields_response( $request );
			if ( ! is_wp_error( $bridge ) ) { foreach ( $bridge->get_data()['fields'] as $field ) { $fields[ $field['path'] ] = $field; } }
		}
		$fields = apply_filters( 'nova_bridge_strategy_fields', $fields, $entity );
		foreach ( $fields as &$field ) { if ( 'builder' === ( $field['source'] ?? '' ) ) { $field['binding'] = self::builder_binding( $field, $entity ); } }
		unset( $field );
		return self::$inventories[ $key ] = array_values( $fields );
	}

	/** Stable selectors come from the live bridge; Elementor IDs are translated to tree positions. */
	private static function builder_binding( array $field, array $entity ): string {
		$builder = $field['builder'] ?? '';
		$selector = $field['selector_data'] ?? [];
		if ( 'elementor' === $builder ) {
			$id = $entity['reference_id'];
			if ( ! isset( self::$element_paths[ $id ] ) ) {
				$data = get_post_meta( $id, '_elementor_data', true );
				$data = is_array( $data ) ? $data : json_decode( (string) $data, true );
				$paths = [];
				$walk = static function ( array $nodes, string $prefix, int $depth = 0 ) use ( &$walk, &$paths ) {
					if ( $depth > 50 ) { return; }
					foreach ( array_values( $nodes ) as $index => $node ) {
						if ( ! is_array( $node ) ) { continue; }
						$path = $prefix . '/' . $index;
						if ( isset( $node['id'] ) ) { $node_id = (string) $node['id']; $paths[ $node_id ] = array_key_exists( $node_id, $paths ) ? false : $path; }
						if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) { $walk( $node['elements'], $path . '/elements', $depth + 1 ); }
					}
				};
				$walk( is_array( $data ) ? $data : [], '' );
				self::$element_paths[ $id ] = $paths;
			}
			$parts = explode( '|', $selector['field_key'] ?? '', 2 );
			$path = self::$element_paths[ $id ][ $parts[0] ] ?? false;
			if ( false === $path || ! isset( $parts[1] ) ) { return ''; }
			$selector = [ 'node_path' => $path, 'field_path' => $parts[1] ];
		} elseif ( ! in_array( $builder, [ 'wpbakery', 'divi', 'beaver', 'breakdance', 'avada', 'gutenberg' ], true ) || ! $selector ) { return ''; }
		return hash( 'sha256', (string) wp_json_encode( [ 'builder' => $builder, 'element' => $field['element'] ?? '', 'logical_field' => $field['logical_field'] ?? '', 'selector' => $selector ] ) );
	}

	public static function valid_pointer( $pointer ): bool {
		return is_string( $pointer ) && strlen( $pointer ) <= 600 && 1 === preg_match( '#^/(?:[^~\x00-\x1F]|~[01])*$#D', $pointer ) && false === strpos( $pointer, '__proto__' );
	}

	public static function save_profile_response( $request ) {
		if ( strlen( (string) $request->get_body() ) > 262144 ) { return self::error( 'size', 'The profile is too large.' ); }
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) { return self::error( 'input', 'Supply a profile object.' ); }
		$reference = self::request_reference( $input );
		if ( ! $reference ) { return self::error( 'reference', 'Select an editable reference.' ); }
		$fp = self::fingerprint( $reference );
		if ( ( $input['signature'] ?? '' ) !== $fp['signature'] ) { return self::error( 'changed', 'The reference layout changed. Refresh its fields before saving.', 409 ); }
		$raw_fields = $input['fields'] ?? [];
		if ( ! is_array( $raw_fields ) || count( $raw_fields ) > 500 ) { return self::error( 'fields', 'Supply at most 500 mapped fields.' ); }
		$allowed = array_column( self::field_inventory( $reference ), null, 'path' );
		$sources = [ '', 'leave_empty', 'title', 'meta_title', 'h1', 'meta_description', 'content', 'content_html', 'top_content', 'bottom_content', 'image_url', 'image_urls', 'image_alt', 'featured_media', 'url', 'slug', 'locale', 'publish_date', 'primary_keyword', 'secondary_keywords' ];
		$fields = [];
		foreach ( $raw_fields as $pointer => $field ) {
			if ( ! self::valid_pointer( $pointer ) || ! isset( $allowed[ $pointer ] ) || ! is_array( $field ) ) { return self::error( 'pointer', 'A mapping points to a field that is not present on this reference.' ); }
			$mapping = $field['mapping'] ?? '';
			$description = $field['description'] ?? '';
			if ( ! is_string( $mapping ) || ! in_array( $mapping, $sources, true ) || ! is_string( $description ) || strlen( $description ) > 8000 ) { return self::error( 'field', 'Use a supported NOVA source field and a description of at most 8,000 bytes.' ); }
			if ( '' !== $mapping || '' !== trim( $description ) ) { $fields[ $pointer ] = [ 'mapping' => $mapping, 'description' => sanitize_textarea_field( $description ), 'binding' => $allowed[ $pointer ]['binding'] ?? '' ]; }
		}
		$guidance = $input['guidance'] ?? '';
		$label = $input['label'] ?? $reference['title'];
		if ( ! is_string( $guidance ) || strlen( $guidance ) > 16000 || ! is_string( $label ) || strlen( $label ) > 200 ) { return self::error( 'guidance', 'Use a label of at most 200 bytes and guidance of at most 16,000 bytes.' ); }
		$option = self::get_option();
		if ( ! empty( $input['delete'] ) ) { unset( $option['profiles'][ $fp['signature'] ] ); }
		else {
			if ( ! $fields && '' === trim( $guidance ) ) { return self::error( 'empty', 'Add authoring guidance or map at least one field.' ); }
			$option['profiles'][ $fp['signature'] ] = [ 'id' => $fp['signature'], 'signature' => $fp['signature'], 'label' => sanitize_text_field( $label ), 'reference_type' => $reference['reference_type'], 'reference_id' => $reference['reference_id'], 'reference_post_id' => $reference['reference_post_id'], 'reference_term_id' => $reference['reference_term_id'], 'guidance' => sanitize_textarea_field( $guidance ), 'fields' => $fields, 'updated_at' => gmdate( 'c' ) ];
		}
		if ( strlen( (string) wp_json_encode( $option['profiles'] ) ) > 2097152 ) { return self::error( 'size', 'Saved profiles exceed the 2 MB limit.' ); }
		self::persist( $option );
		do_action( 'nova_bridge_strategy_profile_saved', $option['profiles'][ $fp['signature'] ] ?? null, $reference );
		return self::get_response();
	}

	/** Explicit omission overrides authoring guidance; it never means writing an empty string. */
	public static function mapping_metadata( array $fields ): array {
		$result = [ 'meta_descriptions' => [], 'nova_content_mappings' => [], 'nova_omit_fields' => [] ];
		foreach ( $fields as $pointer => $field ) {
			if ( ! empty( $field['description'] ) ) { $result['meta_descriptions'][ $pointer ] = $field['description']; }
			if ( ! empty( $field['mapping'] ) ) { $result['nova_content_mappings'][ $pointer ] = $field['mapping']; }
			if ( 'leave_empty' === ( $field['mapping'] ?? '' ) ) {
				$result['nova_omit_fields'][] = $pointer;
				$result['meta_descriptions'][ $pointer ] = 'Do not send this field in any publishing payload. Omit the key entirely; do not send an empty string or null and do not clear existing content. This overrides other guidance for this field.';
			}
		}
		return $result;
	}

	public static function context_response( $request ) { return rest_ensure_response( self::contract_for_url( (string) $request->get_param( 'url' ) ) ); }

	public static function contract_for_url( string $url ) {
		$option = self::get_option();
		$parts = wp_parse_url( $url );
		$host = is_array( $parts ) && isset( $parts['host'] ) ? self::host( $parts['host'] ) : '';
		if ( $host && ! in_array( $host, [ $option['source_host'], self::host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ], true ) ) { return self::error( 'host', 'The URL is outside the imported strategy and this site.' ); }
		$path = self::path( $url );
		if ( false === $path || ! is_array( $parts ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) { return self::error( 'url', 'Supply a valid clean URL.' ); }
		foreach ( $option['rows'] as $row ) {
			if ( $row['path'] !== $path ) { continue; }
			$row = self::row_analysis( $row, $option );
			$row['ready'] = in_array( $row['status'], [ 'ready', 'native' ], true );
			$row['meta_descriptions'] = [];
			$row['nova_content_mappings'] = [];
			$row['nova_omit_fields'] = [];
			$row['guidance'] = '';
			$row['write'] = null;
			$row['warnings'] = [];
			if ( ! $row['reference_id'] ) { return $row; }
			$entity = self::entity( $row['reference_type'], $row['reference_id'] );
			$profile = $option['profiles'][ $row['signature'] ] ?? null;
			$row['reference_url'] = $entity['url'];
			$row['guidance'] = $profile['guidance'] ?? '';
			$fields = self::profile_fields_for_entity( $profile ?: [], $entity );
			$row = array_merge( $row, self::mapping_metadata( $fields ) );
			if ( ! $row['post_id'] && ! $row['term_id'] ) {
				foreach ( array_keys( $row['meta_descriptions'] + $row['nova_content_mappings'] ) as $pointer ) { if ( 0 === strpos( $pointer, '/@builders/' ) ) { unset( $row['meta_descriptions'][ $pointer ], $row['nova_content_mappings'][ $pointer ] ); } }
				$row['nova_omit_fields'] = array_values( array_filter( $row['nova_omit_fields'], static function ( $pointer ) { return 0 !== strpos( $pointer, '/@builders/' ); } ) );
				if ( $row['builders'] ) { $row['ready'] = false; $row['warnings'][] = 'Creating builder content requires cloning/creating the selected layout and reading the new document bridge before using its selectors. Reference document selectors are intentionally omitted.'; }
			}
			$object = 'term' === $entity['reference_type'] ? get_taxonomy( $entity['post_type'] ) : get_post_type_object( $entity['post_type'] );
			$route = ! empty( $object->show_in_rest ) ? '/' . trim( ( $object->rest_namespace ?? '' ) ?: 'wp/v2', '/' ) . '/' . trim( ( $object->rest_base ?? '' ) ?: $entity['post_type'], '/' ) : '';
			$row['write'] = [ 'available' => (bool) $route, 'route' => $route . ( $row['post_id'] || $row['term_id'] ? '/' . ( $row['post_id'] ?: $row['term_id'] ) : '' ), 'method' => $row['post_id'] || $row['term_id'] ? 'PATCH' : 'POST', 'post_type' => $entity['post_type'], 'transport' => 'wordpress' ];
			$row['field_contracts'] = array_values( array_filter( self::field_inventory( $entity ), static function ( $field ) use ( $fields ) { return isset( $fields[ $field['path'] ] ) && 'leave_empty' !== ( $fields[ $field['path'] ]['mapping'] ?? '' ); } ) );
			if ( ! $row['post_id'] && ! $row['term_id'] ) { $row['field_contracts'] = array_values( array_filter( $row['field_contracts'], static function ( $field ) { return 'builder' !== ( $field['source'] ?? '' ); } ) ); }
			elseif ( $profile && count( $fields ) < count( $profile['fields'] ) ) { $row['ready'] = false; $row['warnings'][] = 'Some saved fields have no unique match on this document. Refresh the layout mapping before posting.'; }
			$uses_meta_all = false;
			foreach ( $fields as $pointer => $field ) { if ( 'leave_empty' !== ( $field['mapping'] ?? '' ) && 0 === strpos( $pointer, '/meta_all/' ) ) { $uses_meta_all = true; } }
			if ( 'post' === $entity['reference_type'] && class_exists( 'Nova_Bridge_Suite_Content_Transport' ) && ( ! $route || $uses_meta_all ) ) {
				$bridge_route = Nova_Bridge_Suite_Content_Transport::route_for( $entity['post_type'] );
				if ( $bridge_route ) { $row['write']['available'] = true; $row['write']['route'] = $bridge_route . ( $row['post_id'] ? '/' . $row['post_id'] : '' ); $row['write']['transport'] = 'nova_content_bridge'; }
			}
			if ( 'term' === $entity['reference_type'] ) {
				foreach ( self::$resources ?? [] as $resource ) { if ( ( $resource['taxonomy'] ?? '' ) === 'product_cat' && ! empty( $resource['usable'] ) && ! empty( $resource['route'] ) ) { $row['write']['available'] = true; $row['write']['route'] = $resource['route'] . ( $row['term_id'] ? '/' . $row['term_id'] : '' ); $row['write']['transport'] = 0 === strpos( $resource['route'], '/wc/' ) ? 'woocommerce' : 'wordpress'; break; } }
			}
			foreach ( $row['field_contracts'] as $field ) {
				if ( empty( $field['writable'] ) ) { $row['ready'] = false; $row['warnings'][] = 'Mapped field ' . $field['path'] . ' has no verified writable transport.'; }
			}
			$row = apply_filters( 'nova_bridge_strategy_contract', $row, $entity );
			if ( empty( $row['write']['available'] ) ) { $row['ready'] = false; $row['warnings'][] = 'No enabled write transport is available for this content type.'; }
			return $row;
		}
		return self::error( 'not_imported', 'This URL has not been imported into the strategy.', 404 );
	}

	private static function profile_fields_for_entity( array $profile, array $entity ): array {
		$available = array_column( self::field_inventory( $entity ), null, 'path' );
		$bindings = [];
		foreach ( $available as $pointer => $field ) { if ( ! empty( $field['binding'] ) ) { $binding = $field['binding']; $bindings[ $binding ] = array_key_exists( $binding, $bindings ) ? false : $pointer; } }
		$fields = [];
		foreach ( $profile['fields'] ?? [] as $pointer => $field ) {
			if ( 0 === strpos( $pointer, '/@builders/' ) && (int) ( $profile['reference_post_id'] ?? 0 ) !== $entity['reference_id'] ) {
				$target = $bindings[ $field['binding'] ?? '' ] ?? false;
				if ( ! $target || 'post' !== $entity['reference_type'] ) { continue; }
				$pointer = $target;
			}
			if ( ! isset( $available[ $pointer ] ) ) { continue; }
			$fields[ $pointer ] = $field;
		}
		return $fields;
	}

	public static function register_prepare_filters(): void {
		foreach ( get_post_types( [], 'names' ) as $post_type ) {
			if ( isset( self::$prepared[ $post_type ] ) ) { continue; }
			add_filter( 'rest_prepare_' . $post_type, [ __CLASS__, 'prepare_post' ], 11000, 3 );
			self::$prepared[ $post_type ] = true;
		}
		if ( ! isset( self::$prepared['taxonomy:product_cat'] ) ) { add_filter( 'rest_prepare_product_cat', [ __CLASS__, 'prepare_term' ], 11000, 3 ); add_filter( 'woocommerce_rest_prepare_product_cat', [ __CLASS__, 'prepare_term' ], 11000, 3 ); self::$prepared['taxonomy:product_cat'] = true; }
	}

	public static function prepare_post( $response, $post, $request ) {
		if ( ! $post instanceof WP_Post || ! $request instanceof WP_REST_Request || 'edit' !== $request->get_param( 'context' ) || ! current_user_can( 'edit_post', $post->ID ) || ! $response instanceof WP_REST_Response || $response->get_status() >= 300 ) { return $response; }
		$data = $response->get_data();
		if ( is_array( $data ) ) { $response->set_data( self::decorate_record( $data, 'post', (int) $post->ID ) ); }
		return $response;
	}

	public static function prepare_term( $response, $term, $request ) {
		if ( ! $term instanceof WP_Term || ! $request instanceof WP_REST_Request || 'edit' !== $request->get_param( 'context' ) || ! current_user_can( 'edit_term', $term->term_id ) || ! $response instanceof WP_REST_Response || $response->get_status() >= 300 ) { return $response; }
		$data = $response->get_data();
		if ( is_array( $data ) ) { $response->set_data( self::decorate_record( $data, 'term', (int) $term->term_id ) ); }
		return $response;
	}

	/** Also used by the opt-in bridge writer's authenticated response. */
	public static function decorate_record( array $data, string $type, int $id ): array {
		$entity = self::entity( $type, $id );
		if ( ! $entity ) { return $data; }
		$option = self::get_option();
		if ( ! $option['profiles'] ) { return $data; }
		$fp = self::fingerprint( $entity );
		$profile = $option['profiles'][ $fp['signature'] ] ?? null;
		if ( ! $profile ) { return $data; }
		$fields = self::profile_fields_for_entity( $profile, $entity );
		$metadata = self::mapping_metadata( $fields );
		$descriptions = $metadata['meta_descriptions'];
		$mappings = $metadata['nova_content_mappings'];
		$data['nova_omit_fields'] = $metadata['nova_omit_fields'];
		if ( $profile['guidance'] ) { $descriptions['/@nova/layout'] = $profile['guidance']; }
		foreach ( [ 'meta_descriptions' => $descriptions, 'nova_content_mappings' => $mappings ] as $key => $values ) {
			if ( ! isset( $data[ $key ] ) || ( is_array( $data[ $key ] ) && ( [] === $data[ $key ] || array_keys( $data[ $key ] ) !== range( 0, count( $data[ $key ] ) - 1 ) ) ) ) { $data[ $key ] = array_merge( $data[ $key ] ?? [], $values ); }
		}
		$data['nova_strategy_context'] = [ 'profile_id' => $profile['id'], 'label' => $profile['label'], 'signature' => $fp['signature'], 'guidance' => $profile['guidance'], 'reference_type' => $profile['reference_type'], 'reference_id' => $profile['reference_id'], 'builder_selectors' => 'Read this document’s bridge before writing. Document-qualified selectors from another example are never reused.' ];
		return $data;
	}
}
