<?php
/**
 * Renderer regression checks with isolated WordPress/model adapters; no database.
 * Run: php tests/service-renderer-unit.php [plugin-root]
 * Actual WordPress sanitization and REST persistence need staging integration tests.
 */

namespace {
	if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['nova_test_document'] = [ 'version' => 2, 'sections' => [] ];
	$GLOBALS['nova_test_preview'] = false;
	$GLOBALS['nova_test_native'] = true;
	$GLOBALS['nova_test_singular'] = true;
	$GLOBALS['nova_test_shortcodes'] = 0;
	$GLOBALS['nova_test_assets'] = [];
	$GLOBALS['nova_test_asset_versions'] = [];
	$GLOBALS['nova_test_dequeues'] = [];
	$GLOBALS['nova_test_options'] = [];
	$GLOBALS['nova_test_review_resolutions'] = 0;
	$GLOBALS['nova_test_review_live'] = null;
	$GLOBALS['nova_test_related_queries'] = [];
	$GLOBALS['nova_test_related_candidates'] = [];
	$GLOBALS['nova_test_related_term_candidates'] = [];
	$GLOBALS['nova_test_taxonomies'] = [];
	$GLOBALS['nova_test_terms'] = [];
	$GLOBALS['nova_test_wpml_languages'] = [];
	$GLOBALS['nova_test_posts'] = [
		1 => [ 'type' => 'service_page', 'status' => 'publish', 'title' => 'Current service' ],
		2 => [ 'type' => 'service_page', 'status' => 'publish', 'title' => 'Published related service' ],
		3 => [ 'type' => 'service_page', 'status' => 'draft', 'title' => 'Draft secret' ],
		4 => [ 'type' => 'post', 'status' => 'publish', 'title' => 'Unrelated blog' ],
		5 => [ 'type' => 'service_page', 'status' => 'publish', 'title' => 'Protected secret', 'password' => true ],
	];
	function get_post_type( $id ) { return $GLOBALS['nova_test_posts'][ $id ]['type'] ?? ''; }
	function get_post_status( $id ) { return $GLOBALS['nova_test_posts'][ $id ]['status'] ?? ''; }
	function get_the_title( $id ) { return $GLOBALS['nova_test_posts'][ $id ]['title'] ?? ''; }
	function get_the_excerpt( $id ) { throw new \RuntimeException( 'Renderer must never call filtered get_the_excerpt: it can recurse into the singular service renderer.' ); }
	function get_post( $id ) { return isset( $GLOBALS['nova_test_posts'][ $id ] ) ? (object) [ 'ID' => $id, 'post_excerpt' => $GLOBALS['nova_test_posts'][ $id ]['excerpt'] ?? 'Raw related service summary.', 'post_content' => '', 'post_password' => ! empty( $GLOBALS['nova_test_posts'][ $id ]['password'] ) ? 'protected' : '' ] : null; }
	function get_posts( $args ) { $GLOBALS['nova_test_related_queries'][] = $args; return isset( $args['tax_query'] ) ? $GLOBALS['nova_test_related_term_candidates'] : $GLOBALS['nova_test_related_candidates']; }
	function get_object_taxonomies( $type, $output = 'names' ) { return $GLOBALS['nova_test_taxonomies']; }
	function wp_get_object_terms( $id, $taxonomy, $args = [] ) { return $GLOBALS['nova_test_terms'][ $id ][ $taxonomy ] ?? []; }
	function get_post_meta( $id, $key, $single = false ) { return ''; }
	function get_post_thumbnail_id( $id ) { return 0; }
	function get_permalink( $id ) { return 'https://example.test/services/' . $id . '/'; }
	function post_password_required( $id ) { return ! empty( $GLOBALS['nova_test_posts'][ $id ]['password'] ) && empty( $GLOBALS['nova_test_posts'][ $id ]['unlocked'] ); }
	function get_the_password_form( $id ) { return '<form id="password-form"></form>'; }
	function is_singular( $type ) { return $GLOBALS['nova_test_singular']; }
	function get_queried_object_id() { return 1; }
	function get_option( $key, $default = false ) { return $GLOBALS['nova_test_options'][ $key ] ?? $default; }
	function sanitize_hex_color( $color ) { return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color ) ? $color : null; }
	function sanitize_html_class( $value ) { return preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ); }
	function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
	function esc_url( $value ) { return preg_match( '/^\s*(javascript|data):/i', $value ) ? '' : esc_attr( $value ); }
	function esc_url_raw( $value, $protocols = null ) { return $value; }
	function __( $value, $domain = '' ) { return $value; }
	function _n( $single, $plural, $count, $domain = '' ) { return 1 === $count ? $single : $plural; }
	final class WP_Error {
		private $data;
		public function __construct( $data = [] ) { $this->data = $data; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function esc_attr__( $value, $domain = '' ) { return esc_attr( $value ); }
	function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
	function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
	function wp_kses_post( $value ) { return preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $value ); }
	function wpautop( $value ) { return false !== strpos( $value, '<p' ) ? $value : '<p>' . $value . '</p>'; }
	function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
	function number_format_i18n( $value, $precision = 0 ) { return number_format( $value, $precision ); }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function wp_trim_words( $value, $count, $more = '' ) { return implode( ' ', array_slice( preg_split( '/\s+/', $value ), 0, $count ) ); }
	function wp_get_attachment_image( $id, $size, $icon, $attrs ) { return 10 === $id ? '<img class="nova-service__image" src="https://example.test/image.jpg" alt="' . esc_attr( $attrs['alt'] ?? '' ) . '" />' : ''; }
	function wp_get_attachment_image_url( $id, $size ) { return 10 === $id ? 'https://example.test/poster.jpg' : false; }
	function do_shortcode( $value ) { $GLOBALS['nova_test_shortcodes']++; return '<form id="provider-form"><input aria-label="Email" /></form>'; }
	function apply_filters( $hook, $value, ...$args ) {
		if ( 'nova_service_v2/enable_faq_schema' === $hook ) { return $GLOBALS['nova_test_faq_schema'] ?? $value; }
		if ( 'wpml_post_language_details' === $hook && isset( $GLOBALS['nova_test_wpml_languages'][ $args[0] ] ) ) { return [ 'language_code' => $GLOBALS['nova_test_wpml_languages'][ $args[0] ] ]; }
		return $value;
	}
	function plugins_url( $path, $file ) { return 'https://example.test/plugin/' . $path; }
	function wp_enqueue_style( $handle, ...$args ) { $GLOBALS['nova_test_assets'][] = $handle; $GLOBALS['nova_test_asset_versions'][ $handle ] = $args[2]; }
	function wp_enqueue_script( $handle, ...$args ) { $GLOBALS['nova_test_assets'][] = $handle; $GLOBALS['nova_test_asset_versions'][ $handle ] = $args[2]; }
	function wp_dequeue_style( $handle ) { $GLOBALS['nova_test_dequeues'][] = $handle; }
	function add_action( ...$args ) {}
	function add_filter( ...$args ) {}
}

namespace SEORAI\ServicePageCPT {
	final class ServiceReviewImporter {
		public static function canonical_profile_url( string $url ): string {
			return '' !== self::provider_for_url( $url ) ? $url : '';
		}
		public static function provider_for_url( string $url ): string {
			if ( \preg_match( '#^https://(?:www\.)?trustpilot\.com/review/[^/?]+(?:[/?]|$)#', $url ) ) { return 'trustpilot'; }
			if ( \preg_match( '#^https://www\.google\.com/maps/place/[^/?]+(?:[/?]|$)#', $url ) ) { return 'google'; }
			if ( \preg_match( '#^https://www\.kiyoh\.com/reviews/[^/?]+(?:[/?]|$)#', $url ) ) { return 'kiyoh'; }
			if ( \preg_match( '#^https://www\.webwinkelkeur\.nl/webshop/[^/?]+(?:[/?]|$)#', $url ) ) { return 'webwinkelkeur'; }
			if ( \preg_match( '#^https://www\.thuiswinkel\.org/leden/[^/?]+(?:[/?]|$)#', $url ) ) { return 'thuiswinkel'; }
			return '';
		}
		public static function resolve_source( array $source ) {
			$GLOBALS['nova_test_review_resolutions']++;
			return $GLOBALS['nova_test_review_live'];
		}
	}
	final class ServiceDocument {
		public static function get_document( int $id ): array { return $GLOBALS['nova_test_document']; }
		public static function resolve_document( int $id ): array {
			if ( ! empty( $GLOBALS['nova_test_reenter'] ) ) {
				unset( $GLOBALS['nova_test_reenter'] );
				$GLOBALS['nova_test_reentered_html'] = ServiceRenderer::render( $id );
			}
			return $GLOBALS['nova_test_documents'][ $id ] ?? $GLOBALS['nova_test_document'];
		}
		public static function is_preview( int $id ): bool { return $GLOBALS['nova_test_preview']; }
		public static function is_v2( int $id ): bool { return $GLOBALS['nova_test_native']; }
		public static function safe_custom_html( string $html ): string { return \wp_kses_post( $html ); }
		public static function safe_shortcode( string $shortcode ): string { return '[contact-form-7 id="1"]' === $shortcode ? $shortcode : ''; }
		public static function valid_map_url( string $url ): bool { return (bool) \preg_match( '#^https://(?:www\.)?google\.com/maps(?:/embed(?:\?|/)|\?cid=[0-9]+&output=embed)#', $url ); }
		public static function safe_video_url( string $url ): string {
			if ( \preg_match( '#^https://(?:www\.)?(?:youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/video/)#', $url ) ) { return \strtok( $url, '?' ); }
			return \preg_match( '#^https://example\.test/wp-content/uploads/[^?]+\.(mp4|webm)(?:\?|$)#', $url ) ? $url : '';
		}
	}
}

namespace {
	use SEORAI\ServicePageCPT\ServiceRenderer;
	$nova_test_root = rtrim( $argv[1] ?? dirname( __DIR__ ), '/\\' );
	require $nova_test_root . '/modules/service-page-cpt/includes/class-service-renderer.php';
	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) {
		$checks++;
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	};
	$section = static function ( $id, $type, $data, $enabled = true ) { return [ 'id' => $id, 'type' => $type, 'data' => $data, 'enabled' => $enabled ]; };
	$render = static function ( array $sections ) {
		$GLOBALS['nova_test_document']['sections'] = $sections;
		return ServiceRenderer::render( 1 );
	};

	$html = $render( [ $section( 'offer', 'rich_text', [ 'title' => 'What we offer', 'body' => '<p>&nbsp;</p>' ] ), $section( 'no-action', 'cta', [ 'title' => 'Empty CTA' ] ) ] );
	$assert( 1 === substr_count( $html, '<h1>' ), 'A service without a hero needs one page H1.' );
	$assert( false === strpos( $html, 'What we offer' ) && false === strpos( $html, 'Empty CTA' ), 'Blank default sections leaked into the page.' );
	$html = $render( [ $section( 'late', 'rich_text', [ 'body' => 'First in chosen order' ] ), $section( 'hero', 'hero', [ 'title' => 'First hero' ] ), $section( 'hero2', 'hero', [ 'title' => 'Second hero' ] ), $section( 'disabled', 'rich_text', [ 'body' => 'Hidden text' ], false ) ] );
	$assert( 1 === preg_match_all( '/<h1(?:\s|>)/', $html ), 'Multiple heroes created multiple H1s.' );
	$assert( strpos( $html, 'First in chosen order' ) < strpos( $html, 'First hero' ), 'Renderer did not preserve the chosen section order.' );
	$assert( false === strpos( $html, 'Hidden text' ), 'Disabled section was rendered.' );
	$html = $render( [ $section( 'missing-shared', 'hero', (object) [] ) ] );
	$assert( 1 === preg_match_all( '/<h1(?:\s|>)/', $html ) && false !== strpos( $html, 'Current service' ), 'An unresolved empty shared hero must retain the native page title H1.' );
	$html = $render( [ $section( 'hero', 'hero', [ 'title' => 'Page title' ] ), $section( 'imported', 'rich_text', [ 'body' => '<h1 class="legacy">Imported heading</h1><p>Copy</p>' ] ), $section( 'embed-title', 'custom_cta', [ 'html' => '<h1>Form heading</h1>' ] ) ] );
	$assert( 1 === preg_match_all( '/<h1(?:\s|>)/', $html ) && false !== strpos( $html, '<h2 class="legacy">Imported heading</h2>' ) && false !== strpos( $html, '<h2>Form heading</h2>' ), 'Imported rich text and custom HTML must retain the page\'s unique H1.' );
	$GLOBALS['nova_test_documents'][1] = [ 'version' => 2, 'sections' => [ $section( 'shared', 'rich_text', [ 'body' => 'Resolved shared content' ] ) ] ];
	$html = $render( [] );
	$assert( false !== strpos( $html, 'Resolved shared content' ), 'Renderer ignored the resolved shared document.' );
	unset( $GLOBALS['nova_test_documents'][1] );
	$GLOBALS['nova_test_reenter'] = true;
	$html = $render( [] );
	$assert( '' === $GLOBALS['nova_test_reentered_html'] && false !== strpos( $html, 'nova-service-v2' ), 'Re-entrant filters should terminate while the outer render completes.' );

	$html = $render( [ $section( 'cta', 'cta', [ 'title' => 'Contact us', 'images' => [ [ 'id' => 10, 'alt' => 'Team member' ] ], 'buttons' => [ [ 'label' => 'Book', 'url' => '#appointment' ] ] ] ), $section( 'gallery', 'people', [ 'images' => [ [ 'url' => 'https://example.test/person1.jpg', 'alt' => 'First' ], [ 'url' => 'https://example.test/person2.jpg', 'alt' => 'Second' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'Team member' ), 'CTA image needed by legacy migration was lost.' );
	$assert( 3 === substr_count( $html, '<img ' ), 'Multiple image gallery lost images.' );
	$assert( false !== strpos( $html, 'href="#appointment"' ), 'Same-page CTA link was lost.' );
	$office_images = [ [ 'url' => 'https://example.test/office-front.jpg', 'alt' => 'Office front' ], [ 'url' => 'https://example.test/office-inside.jpg', 'alt' => 'Office interior' ], [ 'url' => 'https://example.test/office-meeting.jpg', 'alt' => 'Meeting room' ] ];
	$office_data = [ 'title' => 'Visit our office', 'body' => '<p>Office introduction.</p>', 'address' => "Campus 91\nEindhoven", 'layout' => 'split', 'images' => $office_images ];
	$html = $render( [ $section( 'office', 'location', $office_data ) ] );
	$assert( 3 === substr_count( $html, '<img ' ) && false !== strpos( $html, 'nova-service__gallery--multiple' ), 'Older location sections without a selection mode must keep their complete gallery.' );
	$office_data['image_selection'] = 'all';
	$assert( 3 === substr_count( $render( [ $section( 'office', 'location', $office_data ) ] ), '<img ' ), 'Explicit all mode must keep every location photo.' );
	$office_data['image_selection'] = 'one_per_page';
	$office_document = [ 'version' => 2, 'sections' => [ $section( 'office', 'location', $office_data ) ] ];
	$office_before = $office_document;
	$office_first = ServiceRenderer::render_candidate( 1, $office_document, 'Service one' );
	$assert( 1 === substr_count( $office_first, '<img ' ) && false !== strpos( $office_first, 'nova-service__gallery--single' ) && false !== strpos( $office_first, 'Office introduction.' ) && false !== strpos( $office_first, 'Campus 91' ) && false !== strpos( $office_first, 'nova-service__layout--split' ), 'Pooled office rendering must show one photo beside retained introduction and address.' );
	$assert( $office_first === ServiceRenderer::render_candidate( 1, $office_document, 'Service one' ) && $office_before === $office_document, 'Repeated office renders must be identical without mutating the candidate or pool.' );
	$reordered_office = $office_document; $reordered_office['sections'][0]['data']['images'] = array_reverse( $office_images );
	$assert( $office_first === ServiceRenderer::render_candidate( 1, $reordered_office, 'Service one' ), 'Reordering the shared photo pool must not change a page selection.' );
	$office_choices = [];
	for ( $office_post_id = 1; $office_post_id <= 24; $office_post_id++ ) {
		$office_html = ServiceRenderer::render_candidate( $office_post_id, $office_document, 'Office service' );
		$assert( 1 === substr_count( $office_html, '<img ' ), 'Each page using a nonempty office pool must display exactly one photo.' );
		preg_match( '/<img\\b[^>]*\\bsrc="([^"]+)"/', $office_html, $office_match );
		$office_choices[] = $office_match[1] ?? '';
	}
	$assert( count( array_unique( $office_choices ) ) > 1, 'The office pool must provide variation across service pages rather than always selecting its first image.' );
	$office_with_blanks = $office_document; $office_with_blanks['sections'][0]['data']['images'][] = [ 'id' => 0, 'url' => '', 'alt' => '' ]; $office_with_blanks['sections'][0]['data']['images'][] = [ 'id' => 999, 'url' => '' ];
	$assert( $office_first === ServiceRenderer::render_candidate( 1, $office_with_blanks, 'Service one' ), 'Blank and missing-attachment pool entries must not suppress a usable office photo.' );
	$empty_office = $office_document; $empty_office['sections'][0]['data']['images'] = [];
	$empty_office_html = ServiceRenderer::render_candidate( 1, $empty_office, 'Service one' );
	$assert( false === strpos( $empty_office_html, '<img ' ) && false !== strpos( $empty_office_html, 'Office introduction.' ) && false !== strpos( $empty_office_html, 'Campus 91' ), 'An empty office pool must retain its content and address without fabricating a photo.' );
	$fixed_office = $office_document; $fixed_office['sections'][0]['data']['images'] = [ [ 'url' => 'https://example.test/chosen-office.jpg', 'alt' => 'Chosen office photo' ] ];
	$assert( false !== strpos( ServiceRenderer::render_candidate( 1, $fixed_office, 'Service one' ), 'src="https://example.test/chosen-office.jpg"' ) && false !== strpos( ServiceRenderer::render_candidate( 24, $fixed_office, 'Service twenty-four' ), 'src="https://example.test/chosen-office.jpg"' ), 'A one-image local override must stay fixed independently of the page seed.' );
	$html = $render( [ $section( 'team', 'people', [ 'image_selection' => 'one_per_page', 'images' => $office_images ] ) ] );
	$assert( 3 === substr_count( $html, '<img ' ), 'Office pool selection must not silently change people or other image galleries.' );
	$office_map_document = $office_document;
	$office_map_document['sections'][0]['data']['map_url'] = 'https://www.google.com/maps/embed?pb=verified-office';
	$office_map_before = $office_map_document;
	$office_map_html = ServiceRenderer::render_candidate( 1, $office_map_document, 'Current service' );
	$assert( 1 === substr_count( $office_map_html, '<iframe ' ) && 1 === substr_count( $office_map_html, '<img ' ) && 1 === substr_count( $office_map_html, '<h2' ) && false !== strpos( $office_map_html, 'class="nova-service__map nova-service__location-map"' ) && false !== strpos( $office_map_html, 'title="Visit our office — Google Maps"' ) && false !== strpos( $office_map_html, 'loading="lazy"' ), 'A location map must appear once with an accessible lazy iframe and one stable pool photo.' );
	$assert( false === strpos( $office_map_html, '<address ' ) && false === strpos( $office_map_html, 'Campus 91' ) && false === strpos( $office_map_html, 'Open in Google Maps' ) && $office_map_before === $office_map_document, 'A location map replaces the visible plain address without duplicate content, source links, or changes to its stored address.' );
	if ( class_exists( '\\DOMDocument' ) ) {
		$office_dom = new \DOMDocument(); @$office_dom->loadHTML( $office_map_html ); $office_xpath = new \DOMXPath( $office_dom );
		$office_copy = '//section[@id="nova-service-office"]//div[@class="nova-service__content-copy"]';
		$assert( 1 === $office_xpath->query( $office_copy . '/div[@class="nova-service__rich"]/following-sibling::div[@class="nova-service__map nova-service__location-map"]/iframe' )->length && 1 === $office_xpath->query( '//section[@id="nova-service-office"]//div[@class="nova-service__content-media"]//img' )->length, 'The office embed must sit directly after its paragraph in the text column, with the photo in the separate media column.' );
	}
	$GLOBALS['nova_test_document'] = $office_map_document;
	$assert( $office_map_html === ServiceRenderer::render( 1 ), 'Saved frontend and request-local candidate must use the same office map structure and selected photo.' );
	$blank_office_map = $office_map_document; $blank_office_map['sections'][0]['data']['map_url'] = '';
	$blank_office_map_html = ServiceRenderer::render_candidate( 1, $blank_office_map, 'Current service' );
	$legacy_office_map = $office_map_document; unset( $legacy_office_map['sections'][0]['data']['map_url'] );
	$assert( false === strpos( $blank_office_map_html, '<iframe ' ) && false !== strpos( $blank_office_map_html, '<address class="nova-service__address">Campus 91' ) && $blank_office_map_html === ServiceRenderer::render_candidate( 1, $legacy_office_map, 'Current service' ), 'Empty and omitted location map URLs must keep the legacy plain address without generating an embed.' );
	foreach ( [ 'https://evil.test/maps/embed?pb=office', 'https://google.com.evil.test/maps/embed?pb=office', 'http://www.google.com/maps/embed?pb=office', 'https://user:password@www.google.com/maps/embed?pb=office', 'https://www.google.com:444/maps/embed?pb=office', 'https://www.google.com/maps/place/Our-office/' ] as $unsafe_office_map_url ) {
		$unsafe_office_map = $office_map_document; $unsafe_office_map['sections'][0]['data']['map_url'] = $unsafe_office_map_url;
		$unsafe_office_html = ServiceRenderer::render_candidate( 1, $unsafe_office_map, 'Current service' );
		$assert( false === strpos( $unsafe_office_html, '<iframe ' ) && false === strpos( $unsafe_office_html, 'nova-service__location-map' ) && false !== strpos( $unsafe_office_html, '<address class="nova-service__address">Campus 91' ) && 1 === substr_count( $unsafe_office_html, '<img ' ), 'A defensively invalid location map must retain the plain address and selected photo.' );
	}
	$map_without_address = $office_map_document; $map_without_address['sections'][0]['data']['address'] = ''; $map_without_address['sections'][0]['data']['body'] = '';
	$assert( 1 === substr_count( ServiceRenderer::render_candidate( 1, $map_without_address, 'Current service' ), '<iframe ' ), 'A configured location embed must remain usable without an optional address or paragraph.' );
	$html = $render( [ $section( 'split', 'rich_text', [ 'layout' => 'split', 'body' => 'Copy next to image', 'images' => [ [ 'id' => 10 ] ] ] ), $section( 'compact', 'credentials', [ 'layout' => 'compact', 'items' => [ [ 'title' => 'Verified award', 'image' => 'https://example.test/award.jpg' ] ] ] ), $section( 'invalid-layout', 'rich_text', [ 'layout' => 'bad" onclick="alert(1)', 'body' => 'Safe layout' ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__layout--split' ) && false !== strpos( $html, 'nova-service__content-grid' ) && false !== strpos( $html, 'nova-service__layout--compact' ) && false !== strpos( $html, 'nova-service__credential-copy' ), 'Presentation variations need usable text/media and credential wrappers.' );
	$assert( false === strpos( $html, 'onclick=' ) && false !== strpos( $html, 'nova-service__layout--default' ), 'Unrecognized layout values must fall back safely.' );
	if ( class_exists( '\\DOMDocument' ) ) {
		$dom = new \DOMDocument();
		@$dom->loadHTML( $html );
		$xpath = new \DOMXPath( $dom );
		$assert( 3 === $xpath->query( '//article[@id="nova-service-v2"]/section' )->length, 'Credential markup must not corrupt neighboring section containment.' );
	}

	$html = $render( [ $section( 'offer-half', 'rich_text', [ 'width' => 'half', 'body' => 'Offer column' ] ), $section( 'hidden-half', 'rich_text', [ 'width' => 'half', 'body' => 'Hidden column' ], false ), $section( 'empty-half', 'cta', [ 'width' => 'half' ] ), $section( 'how-half', 'rich_text', [ 'width' => 'half', 'body' => 'How column' ] ), $section( 'full-between', 'rich_text', [ 'width' => 'full', 'body' => 'Full width between rows' ] ), $section( 'unpaired-half', 'rich_text', [ 'width' => 'half', 'body' => 'Final column' ] ), $section( 'invalid-width', 'rich_text', [ 'width' => 'half" onclick="alert(1)', 'body' => 'Safe full width' ] ) ] );
	$assert( false === strpos( $html, 'Hidden column' ) && false === strpos( $html, 'onclick=' ) && 2 === substr_count( $html, 'class="nova-service__row nova-service__container"' ), 'Column rows must pair only rendered half-width sections without resurrecting hidden or empty elements.' );
	if ( class_exists( '\\DOMDocument' ) ) {
		$dom = new \DOMDocument();
		@$dom->loadHTML( $html );
		$xpath = new \DOMXPath( $dom );
		$assert( 2 === $xpath->query( '(//div[contains(@class,"nova-service__row")])[1]/section' )->length && 1 === $xpath->query( '(//div[contains(@class,"nova-service__row")])[2]/section' )->length, 'An odd column must close its row correctly without absorbing the next full-width section.' );
		$assert( 2 === $xpath->query( '//article[@id="nova-service-v2"]/section[contains(@class,"nova-service__width--full")]' )->length && 5 === $xpath->query( '//article[@id="nova-service-v2"]//section' )->length, 'Column wrappers must preserve section order, containment, and the full-width fallback.' );
		$assert( 2 === $xpath->query( '//article[@id="nova-service-v2"]/div[contains(@class,"nova-service__band")]/div[contains(@class,"nova-service__row")]' )->length && 1 === $xpath->query( '//div[contains(@class,"nova-service__band--soft")]' )->length && 1 === $xpath->query( '//div[contains(@class,"nova-service__band--plain")]' )->length, 'Rendered column rows need alternating full-width bands without wrapping intervening full-width sections or losing an odd final row.' );
	}

	$html = $render( [ $section( 'youtube', 'video', [ 'title' => 'Meet our team', 'video_url' => 'https://www.youtube-nocookie.com/embed/abc123?autoplay=1' ] ), $section( 'uploaded', 'video', [ 'video_url' => 'https://example.test/wp-content/uploads/team.mp4', 'images' => [ [ 'id' => 10 ] ] ] ), $section( 'invalid-video', 'video', [ 'title' => 'Invisible', 'video_url' => 'https://evil.test/video.mp4' ] ) ] );
	$assert( 1 === substr_count( $html, '<iframe ' ) && false !== strpos( $html, 'title="Meet our team"' ) && false !== strpos( $html, 'loading="lazy"' ), 'Approved video embeds need accessible titles and lazy loading.' );
	$assert( false !== strpos( $html, '<video controls playsinline preload="metadata"' ) && false !== strpos( $html, 'poster="https://example.test/poster.jpg"' ) && false !== strpos( $html, 'type="video/mp4"' ), 'Uploaded video needs native controls, metadata loading, and optional attachment poster.' );
	$assert( false === strpos( $html, 'autoplay' ) && false === strpos( $html, 'evil.test' ) && false === strpos( $html, 'Invisible' ), 'Videos must not autoplay or render disallowed sources.' );

	$html = $render( [ $section( 'map', 'map', [ 'map_url' => 'https://google.com/maps/embed?pb=example' ] ) ] );
	$assert( false !== strpos( $html, '<iframe ' ), 'Validated google.com embed URL was ignored.' );
	$html = $render( [ $section( 'bad-map', 'map', [ 'map_url' => 'https://google.com.evil.test/maps/embed?pb=example' ] ), $section( 'address', 'map', [ 'address' => 'Example street 10, Amsterdam' ] ) ] );
	$assert( false === strpos( $html, 'evil.test' ) && 1 === substr_count( $html, '<iframe ' ), 'Maps should reject untrusted hosts and render a verified address query.' );

	$review_source = [ 'provider' => 'trustpilot', 'url' => 'https://www.trustpilot.com/review/example.test', 'business_name' => 'Example company', 'average_rating' => 4.6, 'review_count' => 245 ];
	$html = $render( [ $section( 'reviews', 'reviews', [ 'review_source' => (object) $review_source, 'items' => [ [ 'body' => 'One handpicked positive review', 'author' => 'Customer', 'rating' => 5 ] ] ] ) ] );
	$assert( false !== strpos( $html, 'Overall rating: 4.6 out of 5 on Trustpilot' ) && false !== strpos( $html, '245 reviews' ), 'Provider aggregate must keep the actual overall score/count independently of selected cards.' );
	$assert( false !== strpos( $html, 'Example company' ) && false !== strpos( $html, 'href="https://www.trustpilot.com/review/example.test"' ) && false !== strpos( $html, 'One handpicked positive review' ), 'Provider summary, profile link, and selected review should remain visible together.' );
	$combined_source = $review_source;
	$combined_source['provider'] = 'webwinkelkeur';
	$combined_source['url'] = 'https://www.webwinkelkeur.nl/webshop/Example_123';
	$combined_source['aggregate_scope'] = 'combined';
	$html = $render( [ $section( 'aggregate-only', 'reviews', [ 'review_source' => $combined_source ] ) ] );
	$assert( false !== strpos( $html, '245 reviews' ) && false === strpos( $html, '<figure ' ) && false !== strpos( $html, 'Combined score published by WebwinkelKeur' ), 'Aggregate-only providers must not invent individual cards, and combined provider scores must be identified.' );
	$ten_source = $review_source;
	$ten_source['provider'] = 'kiyoh';
	$ten_source['url'] = 'https://www.kiyoh.com/reviews/1234/example';
	$ten_source['rating_scale'] = 10;
	$ten_source['average_rating'] = 9.5;
	$html = $render( [ $section( 'ten-point-scale', 'reviews', [ 'review_source' => $ten_source, 'items' => [ [ 'body' => 'Published ten-point review', 'rating' => 4.5, 'original_rating' => 9, 'original_scale' => 10 ] ] ] ) ] );
	$assert( false !== strpos( $html, 'Overall rating: 9.5 out of 10 on Kiyoh' ) && false !== strpos( $html, 'width:95%' ) && false !== strpos( $html, 'Rated 9.0 out of 10' ), 'Published ten-point aggregate and review scores must retain their original scale while stars use proportional fill.' );
	$html = $render( [ $section( 'cards', 'reviews', [ 'layout' => 'cards', 'items' => [ [ 'body' => 'Card feedback' ] ] ] ), $section( 'list', 'reviews', [ 'layout' => 'list', 'items' => [ [ 'body' => 'List feedback' ] ] ] ), $section( 'compact', 'reviews', [ 'layout' => 'compact', 'items' => [ [ 'body' => 'Compact feedback' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__layout--cards' ) && false !== strpos( $html, 'nova-service__layout--list' ) && false !== strpos( $html, 'nova-service__layout--compact' ), 'Review cards, list, and compact presentation choices must retain the actual feedback.' );
	$html = $render( [ $section( 'profile-only', 'reviews', [ 'review_source' => [ 'provider' => 'trustpilot', 'url' => $review_source['url'] ] ] ) ] );
	$assert( false !== strpos( $html, 'Trustpilot' ) && false === strpos( $html, 'Overall rating:' ) && false === strpos( $html, 'nova-service__review-count' ), 'Unknown provider averages/counts must stay unknown while the source link remains usable.' );
	$bad_source = $review_source;
	$bad_source['url'] = 'https://trustpilot.com.evil.test/review/example.test';
	$html = $render( [ $section( 'untrusted-source', 'reviews', [ 'review_source' => $bad_source, 'items' => [ [ 'body' => 'Keep manual feedback' ] ] ] ) ] );
	$assert( false === strpos( $html, 'evil.test' ) && false === strpos( $html, '245 reviews' ) && false !== strpos( $html, 'Keep manual feedback' ), 'An untrusted source must not publish an aggregate or hide separate manual feedback.' );
	$bad_source['url'] = $review_source['url'];
	$bad_source['provider'] = 'google';
	$html = $render( [ $section( 'mismatched-source', 'reviews', [ 'review_source' => $bad_source ] ) ] );
	$assert( false === strpos( $html, 'nova-service__review-summary' ) && 0 === $GLOBALS['nova_test_review_resolutions'], 'Provider/profile mismatch must not display metadata or trigger a live fetch.' );
	$zero_source = $review_source;
	$zero_source['review_count'] = 0;
	$html = $render( [ $section( 'no-reviews', 'reviews', [ 'review_source' => $zero_source ] ) ] );
	$assert( false !== strpos( $html, '0 reviews' ) && false === strpos( $html, 'Overall rating:' ), 'An empty provider profile must not claim an overall rating.' );
	$invalid_source = $review_source;
	$invalid_source['average_rating'] = 6;
	$invalid_source['review_count'] = 1.5;
	$html = $render( [ $section( 'invalid-aggregate', 'reviews', [ 'review_source' => $invalid_source ] ) ] );
	$assert( false === strpos( $html, 'Overall rating:' ) && false === strpos( $html, 'nova-service__review-count' ), 'Malformed out-of-scale scores/fractional review counts must not become public claims.' );
	$google_source = [ 'provider' => 'google', 'url' => 'https://www.google.com/maps/place/Example', 'google_place_id' => 'place-id', 'selected_review_ids' => [ 'selected' ], 'average_rating' => 4.9, 'review_count' => 9999 ];
	$GLOBALS['nova_test_review_live'] = [ 'provider' => 'google', 'average_rating' => 4.2, 'review_count' => 89, 'business_name' => 'Live Google business', 'reviews' => [ [ 'id' => 'selected', 'body' => 'Live selected Google review', 'rating' => 3, 'author' => 'Review author', 'author_url' => 'https://www.google.com/maps/contrib/123', 'author_image' => 'https://lh3.googleusercontent.com/avatar', 'url' => 'https://www.google.com/maps/reviews/data=review', 'date' => '2026-10-05' ] ] ];
	$html = $render( [ $section( 'live-google', 'reviews', [ 'review_source' => $google_source, 'items' => [ [ 'body' => 'Independent manual feedback', 'rating' => 5 ] ] ] ) ] );
	$assert( 1 === $GLOBALS['nova_test_review_resolutions'] && false !== strpos( $html, 'Overall rating: 4.2 out of 5 on Google Maps' ) && false !== strpos( $html, '89 reviews' ) && false === strpos( $html, '9,999' ), 'Google must display current resolver data instead of stale stored aggregate metadata.' );
	$assert( false !== strpos( $html, 'Selected reviews from Google; ordered by relevance.' ) && false !== strpos( $html, 'aria-label="Google Maps" translate="no"' ) && false !== strpos( $html, 'alt="Google Maps"' ), 'Current Google review content requires clear selection context and accessible official Google Maps attribution.' );
	$assert( false !== strpos( $html, 'href="https://www.google.com/maps/contrib/123"' ) && false !== strpos( $html, 'src="https://lh3.googleusercontent.com/avatar"' ) && false !== strpos( $html, 'href="https://www.google.com/maps/reviews/data=review"' ), 'Google review author profile, available avatar, and review URL attribution must survive rendering.' );
	$assert( false === strpos( $html, 'Additional customer feedback' ) && false !== strpos( $html, 'Independent manual feedback' ) && false !== strpos( $html, 'nova-service__review-source">Customer testimonial' ), 'Manual feedback needs its own honest source badge without a redundant second heading or false Google attribution.' );
	$GLOBALS['nova_test_review_live']['reviews'] = [];
	$html = $render( [ $section( 'google-empty-selection', 'reviews', [ 'review_source' => $google_source ] ) ] );
	$assert( false !== strpos( $html, '89 reviews' ) && false === strpos( $html, 'Live selected Google review' ) && false === strpos( $html, 'ordered by relevance' ), 'An empty or unavailable Google selection should preserve the live aggregate without substituting other reviews.' );
	$GLOBALS['nova_test_review_live'] = new \WP_Error();
	$html = $render( [ $section( 'google-unavailable', 'reviews', [ 'review_source' => $google_source, 'items' => [ [ 'body' => 'Available manual feedback' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'aria-label="Google Maps"' ) && false !== strpos( $html, 'Available manual feedback' ) && false === strpos( $html, 'Overall rating:' ) && false === strpos( $html, 'nova-service__review-count' ), 'A failed Google lookup must retain the verified source and manual feedback without fabricated or cached scores.' );
	$GLOBALS['nova_test_review_live'] = [ 'provider' => 'google', 'embed_url' => 'https://www.google.com/maps?cid=123&output=embed', 'reviews' => [], 'average_rating' => null, 'review_count' => null ];
	$google_widget_source = $google_source;
	$google_widget_source['display'] = 'widget';
	$html = $render( [ $section( 'google-widget', 'reviews', [ 'review_source' => $google_widget_source ] ) ] );
	$assert( false !== strpos( $html, 'src="https://www.google.com/maps?cid=123&amp;output=embed"' ) && false !== strpos( $html, 'title="Business profile on Google Maps"' ) && false === strpos( $html, 'Overall rating:' ), 'An approved Google fallback widget must render lazily with its profile attribution and without invented aggregate numbers.' );
	$google_profile_source = $google_source;
	unset( $google_profile_source['selected_review_ids'] );
	$google_profile_source['embed_url'] = 'https://www.google.com/maps?cid=123&output=embed';
	$before_resolutions = $GLOBALS['nova_test_review_resolutions'];
	$html = $render( [ $section( 'google-default-profile', 'reviews', [ 'review_source' => $google_profile_source, 'items' => [ [ 'body' => 'Manual testimonial with source', 'author' => 'Client', 'source' => 'Customer email' ] ] ] ) ] );
	$assert( false === strpos( $html, '<iframe ' ) && false !== strpos( $html, 'aria-label="Google Maps"' ) && false === strpos( $html, 'Overall rating:' ) && $before_resolutions === $GLOBALS['nova_test_review_resolutions'], 'Older Google sources with an embed URL must default to a compact profile link without a map, unknown score, or network lookup.' );
	$assert( false !== strpos( $html, 'nova-service__review-profile-label">View reviews on</span>' ) && false !== strpos( $html, 'aria-label="Google Maps" translate="no"' ), 'A profile-only Google source must explain where visitors can read the reviews while preserving provider attribution.' );
	$assert( false !== strpos( $html, 'nova-service__review-source">Customer email' ) && false === strpos( $html, 'Additional customer feedback' ), 'Manual review source labels must remain visible beside an independent Google profile link.' );
	$GLOBALS['nova_test_review_live'] = new \WP_Error( [ 'fallback' => [ 'provider' => 'google', 'embed_url' => 'https://www.google.com/maps?cid=123&output=embed' ] ] );
	$html = $render( [ $section( 'google-error-widget', 'reviews', [ 'review_source' => $google_widget_source ] ) ] );
	$assert( false !== strpos( $html, '<iframe ' ) && false === strpos( $html, 'Overall rating:' ), 'A trusted Google fallback carried by an import error should remain usable.' );
	$GLOBALS['nova_test_review_live'] = [ 'provider' => 'google', 'embed_url' => 'https://evil.test/maps/embed?cid=123', 'reviews' => [] ];
	$html = $render( [ $section( 'google-bad-widget', 'reviews', [ 'review_source' => $google_widget_source ] ) ] );
	$assert( false === strpos( $html, 'evil.test' ) && false === strpos( $html, '<iframe ' ) && false !== strpos( $html, 'aria-label="Google Maps"' ), 'Fallback widgets must still reject untrusted origins while retaining the real profile link.' );
	$GLOBALS['nova_test_review_live'] = null;
	$html = $render( [ $section( 'manual-provenance', 'reviews', [ 'items' => [ [ 'body' => 'A review imported manually', 'source' => 'Kiyoh', 'source_url' => 'https://www.kiyoh.com/reviews/1234/example' ], [ 'body' => 'A linked source without a custom label', 'url' => 'https://customer.example.test/feedback' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__review-source--kiyoh' ) && false !== strpos( $html, 'href="https://www.kiyoh.com/reviews/1234/example"' ) && false !== strpos( $html, '>Kiyoh <span' ) && false !== strpos( $html, '>customer.example.test <span' ), 'Every linked review needs a clear individual source rather than borrowing the section provider.' );

	$html = $render( [ $section( 'publisher-branded', 'reviews', [ 'source_logo' => 'https://example.test/publisher-logo.png', 'items' => [ [ 'body' => 'Published on the business website', 'source' => 'Google reviews', 'url' => 'https://example.test/reviews/' ], [ 'body' => 'Another publisher quote', 'source' => 'Customer website', 'url' => 'https://customer.example.test/reviews/', 'source_logo' => '/wp-content/uploads/customer-logo.png' ], [ 'body' => 'Logo deliberately cleared', 'source' => 'Customer email', 'source_logo' => '' ] ] ] ) ] );
	$assert( 2 === substr_count( $html, 'class="nova-service__provider-logo"' ) && false !== strpos( $html, 'src="https://example.test/publisher-logo.png"' ) && false !== strpos( $html, 'src="/wp-content/uploads/customer-logo.png"' ), 'Reviews should inherit one shared publisher logo while preserving per-item logo overrides and explicit clearing.' );
	$assert( false === strpos( $html, 'nova-service__review-source--google' ) && false === strpos( $html, 'google-maps.svg' ) && false === strpos( $html, 'Google reviews' ) && false !== strpos( $html, 'nova-service__source-text">example.test</span>' ), 'An own-site quote must not gain Google attribution from a free-text source name.' );
	$testimonial_sections = [ $section( 'customer-quotes', 'reviews', [ 'source_logo' => 'https://example.test/publisher-logo.png', 'items' => [ [ 'body' => '<p>Ik voelde me op mijn gemak, en kreeg heldere uitleg.</p>', 'author' => 'Vivian', 'source' => 'Published on the company website', 'url' => 'https://example.test/customer-quotes/' ], [ 'body' => '<p>This customer supplied a real photo and rating.</p>', 'author' => 'Eline', 'author_image' => 'https://customer.example.test/real-photo.jpg', 'author_url' => 'https://customer.example.test/profile/', 'rating' => 4.5, 'url' => 'https://example.test/customer-quotes/' ], [ 'body' => '<p>The original third-party review.</p>', 'author' => 'Review author', 'rating' => 5, 'url' => 'https://nl.trustpilot.com/reviews/actual-review' ] ] ] ) ];
	$html = $render( $testimonial_sections );
	$assert( $testimonial_sections === $GLOBALS['nova_test_document']['sections'] && false !== strpos( $html, '<p>Ik voelde me op mijn gemak, en kreeg heldere uitleg.</p>' ) && false !== strpos( $html, '<strong>Vivian</strong>' ), 'Testimonial presentation must retain the original quote, first name, source URL and stored ratings without modifying the document.' );
	if ( class_exists( '\\DOMDocument' ) ) {
		$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
		$assert( 2 === $xpath->query( '//figure[contains(@class,"nova-service__testimonial")]//span[@class="nova-service__quote-mark" and @aria-hidden="true"]' )->length && 2 === $xpath->query( '//figcaption[@class="nova-service__testimonial-footer"]//span[contains(@class,"nova-service__review-source")]' )->length && 0 === $xpath->query( '//figure[contains(@class,"nova-service__testimonial")]//div[@class="nova-service__review-head"]' )->length, 'Website quotes need decorative accessible-safe quote marks and publisher source attribution in the customer footer, rather than vendor headers.' );
		$assert( 1 === $xpath->query( '//figure[.//strong[text()="Vivian"]]//span[@class="nova-service__testimonial-initial" and @aria-hidden="true" and text()="V"]' )->length && 0 === $xpath->query( '//figure[.//strong[text()="Vivian"]]//div[@class="nova-service__rating"]' )->length && 1 === $xpath->query( '//figure[.//strong[text()="Eline"]]//img[@src="https://customer.example.test/real-photo.jpg"]' )->length && 0 === $xpath->query( '//figure[.//strong[text()="Eline"]]//span[@class="nova-service__testimonial-initial"]' )->length, 'Only supplied customer photos or honest name initials may represent people, and an unrated testimonial must not acquire stars.' );
		$assert( 1 === $xpath->query( '//figure[.//strong[text()="Eline"]]/figcaption//div[@class="nova-service__rating" and @aria-label="Rated 4.5 out of 5"]' )->length && 1 === $xpath->query( '//figure[.//strong[text()="Review author"]]/div[@class="nova-service__review-head"]//div[@class="nova-service__rating"]' )->length && 0 === $xpath->query( '//figure[.//strong[text()="Review author"]]//span[@class="nova-service__quote-mark"]' )->length, 'Explicit manual ratings remain secondary footer information while vendor reviews keep their source and rating header.' );
	}
	$html = $render( [ $section( 'google-brand', 'reviews', [ 'items' => [ [ 'body' => 'A review with its actual Google source', 'url' => 'https://www.google.com/maps/reviews/data=actual-review', 'source_logo' => 'https://example.test/wrong-publisher.png' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__review-source--google' ) && false !== strpos( $html, 'src="https://example.test/plugin/assets/review-providers/google-maps.svg"' ) && false !== strpos( $html, 'alt="Google Maps"' ) && false === strpos( $html, 'wrong-publisher.png' ), 'A verified Google source must use its packaged official mark with attribution instead of an unrelated custom publisher logo.' );
	$html = $render( [ $section( 'provider-brands', 'reviews', [ 'items' => [ [ 'body' => 'Trustpilot source', 'url' => 'https://nl.trustpilot.com/reviews/actual-review', 'source_logo' => 'https://example.test/permitted-provider-logo.png' ], [ 'body' => 'WebwinkelKeur source', 'url' => 'https://www.webwinkelkeur.nl/webshop/Example_123/reviews' ], [ 'body' => 'Kiyoh source', 'url' => 'https://www.kiyoh.com/reviews/1234/example' ], [ 'body' => 'Thuiswinkel source', 'url' => 'https://www.thuiswinkel.org/leden/example/' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__review-source--trustpilot' ) && false !== strpos( $html, 'nova-service__review-source--webwinkelkeur' ) && false !== strpos( $html, 'nova-service__review-source--kiyoh' ) && false !== strpos( $html, 'nova-service__review-source--thuiswinkel' ) && false !== strpos( $html, 'permitted-provider-logo.png' ), 'Actual review/profile URLs should produce clear provider branding and accept an explicitly permitted provider logo without bundling other trademarks.' );
	$html = $render( [ $section( 'fake-provider', 'reviews', [ 'items' => [ [ 'body' => 'Keep the actual source', 'source' => 'Trustpilot', 'url' => 'https://trustpilot.com.evil.test/reviews/fake', 'source_logo' => '//evil.test/logo.svg' ], [ 'body' => 'Safe plain testimonial', 'source_logo' => 'javascript:alert(1)' ] ] ] ) ] );
	$assert( false === strpos( $html, 'nova-service__review-source--trustpilot' ) && false === strpos( $html, 'nova-service__provider-logo' ) && false === strpos( $html, 'javascript:' ) && false !== strpos( $html, '>trustpilot.com.evil.test <span' ), 'Lookalike provider hosts and unsafe publisher image URLs must never receive provider marks or executable image sources.' );
	$html = $render( [ $section( 'branded-summary', 'reviews', [ 'review_source' => $review_source, 'source_logo' => 'https://example.test/permitted-summary-logo.png' ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__provider-brand--trustpilot' ) && false !== strpos( $html, 'permitted-summary-logo.png' ) && false !== strpos( $html, '245 reviews' ), 'The profile/aggregate row must use the section source logo while retaining the actual provider score and count.' );
	$html = $render( [ $section( 'issuer-badges', 'credentials', [ 'layout' => 'badges', 'items' => [ [ 'title' => 'Published professional membership', 'issuer' => 'Actual issuer', 'image' => 'https://example.test/issuer-logo.png', 'url' => 'https://example.test/membership-evidence/', 'source' => 'Read the published evidence', 'body' => 'The company publishes this membership.' ], [ 'title' => 'Published registration code', 'issuer' => 'Register', 'body' => 'The company publishes its registration code.' ] ] ] ), $section( 'issuer-logos', 'credentials', [ 'layout' => 'logos', 'items' => [ [ 'title' => 'Logo strip membership', 'image' => 'https://example.test/issuer-two.png' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__layout--badges' ) && false !== strpos( $html, 'nova-service__layout--logos' ) && false !== strpos( $html, 'nova-service__credential-issuer">Actual issuer</p>' ) && false !== strpos( $html, 'aria-label="View evidence for Published professional membership"' ), 'Credential badge/logo presentations need issuer identity and a direct evidence link from the real logo.' );
	$assert( 2 === substr_count( $html, 'nova-service__credential-mark"' ) && false !== strpos( $html, '>Read the published evidence <span' ) && false !== strpos( $html, 'Published registration code' ), 'Evidence link labels must remain backward compatible, and registration-only proof must not invent a logo or seal.' );
	$credential_long_body = '<p>' . str_repeat( 'The complete factual registration explanation remains available. ', 20 ) . '</p>';
	$credential_items = [
		[ 'title' => '', 'body' => '' ],
		[ 'title' => 'Actual membership ' . str_repeat( 'with a longer supplied title ', 7 ), 'issuer' => 'Actual professional association', 'image' => 'https://example.test/actual-issuer-logo.png', 'body' => $credential_long_body, 'url' => 'https://example.test/published-evidence/', 'source' => 'Read the published evidence' ],
		[ 'title' => 'Actual registration code', 'issuer' => 'Actual register', 'body' => '<p>Published registration text only.</p>', 'url' => 'https://example.test/registration/', 'source' => 'Read registration evidence' ],
		[ 'image' => 'https://example.test/image-only-logo.png' ],
		[ 'body' => '<p>The body-only factual explanation.</p>' ],
	];
	foreach ( [ 'logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion' ] as $design ) {
		$input = [ $section( 'credentials-' . $design, 'credentials', [ 'layout' => $design, 'link_sources' => false, 'items' => $credential_items ] ) ];
		$html = $render( $input );
		$assert( false !== strpos( $html, 'nova-service__layout--' . $design ) && false !== strpos( $html, $credential_long_body ) && false !== strpos( $html, 'The body-only factual explanation.' ) && false !== strpos( $html, 'image-only-logo.png' ) && $input === $GLOBALS['nova_test_document']['sections'], 'Credential ' . $design . ' must retain every valid long/image-only/body-only item and stored evidence without metadata changes.' );
		$assert( false === strpos( $html, '<a ' ) && false === strpos( $html, 'Read the published evidence' ) && false === strpos( $html, 'Read registration evidence' ) && 2 === substr_count( $html, 'class="nova-service__image"' ) && false !== strpos( $html, 'Actual professional association' ), 'Credential ' . $design . ' must unlink actual marks/evidence without leaving dead CTA labels or inventing logos for text-only proof.' );
		if ( class_exists( '\\DOMDocument' ) ) {
			$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
			if ( 'evidence_accordion' === $design ) {
				$assert( 4 === $xpath->query( '//details[@class="nova-service__credential-disclosure"]' )->length && 1 === $xpath->query( '//details[@class="nova-service__credential-disclosure" and @open]' )->length && 4 === $xpath->query( '//details[@class="nova-service__credential-disclosure"]/summary/h3' )->length && 0 === $xpath->query( '//details/summary//a' )->length && 0 === $xpath->query( '//details/summary//*[@tabindex]' )->length, 'Evidence accordion must use one native heading summary per valid item, open only the first valid entry, and avoid nested links or redundant keyboard controls.' );
				$assert( 1 === $xpath->query( '(//details[@class="nova-service__credential-disclosure"])[1][@open]' )->length && false !== strpos( $xpath->query( '(//details[@class="nova-service__credential-disclosure"])[1]/summary' )->item( 0 )->textContent, 'Actual membership' ), 'An empty leading input item must not steal the initially open credential disclosure.' );
			} else {
				$expected_symbols = 'editorial' === $design ? 1 : 2;
				$assert( 4 === $xpath->query( '//article[contains(@class,"nova-service__credential--designed")]' )->length && $expected_symbols === $xpath->query( '//span[@class="nova-service__credential-symbol" and @aria-hidden="true"]' )->length, 'Credential ' . $design . ' must render all valid factual entries with neutral information icons only where no actual mark exists.' );
			}
			if ( 'featured_membership' === $design ) {
				$assert( 1 === $xpath->query( '//article[contains(@class,"nova-service__credential--featured")]' )->length && 3 === $xpath->query( '//div[@class="nova-service__credential-facts"]/article' )->length && false !== strpos( $xpath->query( '//article[contains(@class,"nova-service__credential--featured")]' )->item( 0 )->textContent, 'Actual membership' ), 'Featured membership must feature the first valid selection and retain every remaining item in factual rows.' );
			}
		}
		$linked = $input; $linked[0]['data']['link_sources'] = true;
		$html = $render( $linked );
		$assert( false !== strpos( $html, 'href="https://example.test/published-evidence/"' ) && false !== strpos( $html, '>Read the published evidence <span' ), 'Credential ' . $design . ' must preserve original evidence URLs and labels when source links are enabled.' );
	}
	$html = $render( [ $section( 'single-feature', 'credentials', [ 'layout' => 'featured_membership', 'items' => [ [ 'title' => 'The only factual credential' ] ] ] ) ] );
	$assert( 1 === substr_count( $html, 'nova-service__credential--featured' ) && false === strpos( $html, 'nova-service__credential-facts' ), 'A single featured credential must not invent an empty facts column or extra entries.' );
	$html = $render( [ $section( 'all-empty-credentials', 'credentials', [ 'layout' => 'evidence_accordion', 'title' => 'Must not show empty heading', 'items' => [ [], [ 'body' => ' ' ] ] ] ) ] );
	$assert( false === strpos( $html, 'Must not show empty heading' ) && false === strpos( $html, 'credential-disclosure' ), 'All-empty credential input must omit the entire section rather than fabricate a disclosure.' );
	foreach ( [ 'logo_rail', 'credential_tiles', 'trust_band' ] as $design ) {
		$one = $render( [ $section( 'one-credential', 'credentials', [ 'layout' => $design, 'items' => [ [ 'title' => 'One actual credential' ] ] ] ) ] );
		$two = $render( [ $section( 'two-credentials', 'credentials', [ 'layout' => $design, 'items' => [ [ 'title' => 'First actual credential' ], [ 'title' => 'Second actual credential' ] ] ] ) ] );
		$assert( false !== strpos( $one, 'style="--nova-service-credential-columns:1"' ) && false !== strpos( $two, 'style="--nova-service-credential-columns:2"' ), $design . ' must size the desktop row to actual valid item count instead of reserving an empty third column.' );
	}

	$long_quote = '<p>' . str_repeat( 'The complete genuine quote stays available, including its longer explanation. ', 18 ) . '</p>';
	$design_items = [
		[ 'author' => 'Tim', 'body' => $long_quote, 'source' => 'Company website', 'url' => 'https://example.test/quotes/', 'author_url' => 'https://example.test/customer/tim/' ],
		[ 'author' => 'Jacinta', 'body' => '<p>A second literal quote without a required URL.</p>', 'source' => 'Google' ],
		[ 'author' => 'Third-party customer', 'body' => '<p>A literal vendor review with its real supplied rating.</p>', 'url' => 'https://nl.trustpilot.com/reviews/actual-review', 'author_image' => 'https://example.test/supplied-photo.jpg', 'author_url' => 'https://example.test/customer/vendor/', 'rating' => 4.5 ],
		[ 'body' => '<p>The unnamed quote also remains visible.</p>', 'source' => 'Published testimonial' ],
	];
	foreach ( [ 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature' ] as $design ) {
		$input = [ $section( 'design-' . $design, 'reviews', [ 'layout' => $design, 'link_sources' => false, 'source_logo' => 'https://example.test/publisher-logo.png', 'items' => $design_items ] ) ];
		$html = $render( $input );
		$assert( 4 === substr_count( $html, 'class="nova-service__review nova-service__card' ) && false !== strpos( $html, 'nova-service__layout--' . $design ) && false !== strpos( $html, $long_quote ) && false !== strpos( $html, 'The unnamed quote also remains visible.' ) && $input === $GLOBALS['nova_test_document']['sections'], 'Design ' . $design . ' must retain every mixed-source card, full long quote, original names and stored metadata.' );
		if ( class_exists( '\\DOMDocument' ) ) {
			$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
			$captions_valid = true;
			foreach ( $xpath->query( '//figure[contains(@class,"nova-service__review--designed")]' ) as $figure ) {
				$captions = $xpath->query( './figcaption', $figure );
				$children = $xpath->query( './*', $figure );
				if ( $captions->length > 1 || ( 1 === $captions->length && $captions->item( 0 ) !== $children->item( 0 ) && $captions->item( 0 ) !== $children->item( $children->length - 1 ) ) ) { $captions_valid = false; }
			}
			$assert( $captions_valid && 0 === $xpath->query( '//a' )->length && false !== strpos( $html, 'publisher-logo.png' ) && false !== strpos( $html, 'Google</span>' ) && false === strpos( $html, 'google-maps.svg' ), 'Design ' . $design . ' needs valid first/last figcaptions, unlinked sources/authors with retained branding, and an honest no-URL manual label.' );
			if ( 'conversation' === $design ) { $assert( 4 === $xpath->query( '//div[@class="nova-service__review-bubble"]' )->length, 'Conversation needs a real bubble wrapper for each complete quote.' ); }
			if ( in_array( $design, [ 'publisher_cards', 'compact_rail' ], true ) ) { $assert( 3 === $xpath->query( '//figure/figcaption[@class="nova-service__review-identity"]' )->length, $design . ' needs real customer identities before the quotes, without inventing an anonymous author.' ); }
			if ( 'signature' === $design ) { $assert( 3 === $xpath->query( '//figcaption[@class="nova-service__signature-identity"]' )->length && 4 === $xpath->query( '//div[@class="nova-service__signature-content"]' )->length && 1 === $xpath->query( '//figure[contains(@class,"nova-service__review--signature") and contains(@class,"nova-service__review--anonymous")]' )->length, 'Signature needs an identity rail alongside each complete quote, with an empty-identity fallback instead of fabricated people.' ); }
		}
	}
	$html = $render( [ $section( 'unlink-profile-and-author', 'reviews', [ 'link_sources' => false, 'review_source' => $review_source, 'source_logo' => 'https://example.test/summary-logo.png', 'items' => [ [ 'author' => 'Actual customer', 'author_url' => 'https://example.test/customer/', 'body' => 'The original rated review.', 'rating' => 4, 'url' => 'https://www.trustpilot.com/reviews/actual-review' ] ] ] ) ] );
	$assert( false === strpos( $html, '<a ' ) && false !== strpos( $html, 'summary-logo.png' ) && false !== strpos( $html, '245 reviews' ) && false !== strpos( $html, '<strong>Actual customer</strong>' ) && false !== strpos( $html, 'Rated 4.0 out of 5' ), 'Source-link opt-out must remove profile/source/author anchors while preserving source branding, real aggregates and explicitly supplied rating/identity.' );
	$html = $render( [ $section( 'google-profile-unlinked', 'reviews', [ 'link_sources' => false, 'review_source' => [ 'provider' => 'google', 'url' => 'https://www.google.com/maps/place/Example' ] ] ) ] );
	$assert( false === strpos( $html, '<a ' ) && false === strpos( $html, 'View reviews on' ) && false !== strpos( $html, 'google-maps.svg' ) && false !== strpos( $html, 'role="img" aria-label="Google Maps"' ), 'An ordinary keyless Google profile may become plain branded attribution without a misleading link CTA.' );
	$html = $render( [ $section( 'links-default-on', 'reviews', [ 'items' => [ [ 'body' => 'Original quote.', 'author' => 'Tim', 'author_url' => 'https://example.test/customer/', 'source' => 'Company website', 'url' => 'https://example.test/quotes/' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'href="https://example.test/quotes/"' ) && false !== strpos( $html, 'href="https://example.test/customer/"' ), 'Omitted source-link setting must preserve previous source and author links.' );
	$GLOBALS['nova_test_review_live'] = [ 'provider' => 'google', 'reviews' => [ [ 'id' => 'selected', 'body' => 'Actual live imported Google quote.', 'author' => 'Actual Google author', 'author_url' => 'https://www.google.com/maps/contrib/123', 'url' => 'https://www.google.com/maps/reviews/data=actual-review', 'rating' => 5 ] ] ];
	$html = $render( [ $section( 'live-required-attribution', 'reviews', [ 'layout' => 'spotlight', 'link_sources' => false, 'review_source' => $google_source ] ) ] );
	$assert( false !== strpos( $html, 'href="https://www.google.com/maps/reviews/data=actual-review"' ) && false !== strpos( $html, 'href="https://www.google.com/maps/contrib/123"' ) && false !== strpos( $html, 'href="https://www.google.com/maps/place/Example"' ) && false !== strpos( $html, 'nova-service__provider-mark--google' ), 'Live imported Google content must preserve required supplied review/author/profile attribution links and the official logo even when ordinary source links are disabled.' );
	$GLOBALS['nova_test_review_live'] = null;

	foreach ( [ true, false ] as $linked ) {
		$input = [ $section( 'single-google-attribution', 'reviews', [ 'link_sources' => $linked, 'review_source' => [ 'provider' => 'google', 'url' => 'https://www.google.com/maps/place/Example' ], 'items' => [ [ 'body' => 'Original manually supplied review.', 'source' => 'Google Maps', 'url' => 'https://www.google.com/maps/reviews/data=actual-review' ] ] ] ) ];
		$html = $render( $input );
		if ( class_exists( '\\DOMDocument' ) ) {
			$dom = new \DOMDocument(); @$dom->loadHTML( $html ); $xpath = new \DOMXPath( $dom );
			$profile = $xpath->query( '//span[contains(@class,"nova-service__provider-brand--google")]' )->item( 0 );
			$card_source = $xpath->query( '//span[contains(@class,"nova-service__review-source--google")]' )->item( 0 );
			$valid = true;
			foreach ( [ $profile, $card_source ] as $source_block ) {
				if ( '' !== trim( $source_block->textContent ) || 1 !== $xpath->query( './/img[@alt="Google Maps"]', $source_block )->length || 1 !== $xpath->query( $linked ? './a[@aria-label="Google Maps"]//img' : './span[@role="img" and @aria-label="Google Maps"]//img', $source_block )->length || ( ! $linked && 0 !== $xpath->query( './/a', $source_block )->length ) ) { $valid = false; }
			}
			$assert( $valid && $input === $GLOBALS['nova_test_document']['sections'], 'Google profile and card attribution must display one official logo without duplicate visible names, with a named logo link or static named mark according to the setting.' );
		}
		$input = [ $section( 'named-publisher-logo', 'reviews', [ 'link_sources' => $linked, 'source_logo' => 'https://example.test/publisher-logo.png', 'items' => [ [ 'body' => 'Original clinic testimonial.', 'source' => 'Vital-website · testimonialfragment', 'url' => 'https://example.test/testimonial/' ] ] ] ) ];
		$html = $render( $input );
		$assert( 1 === substr_count( $html, 'nova-service__source-text">Vital-website · testimonialfragment</span>' ) && false !== strpos( $html, 'aria-label="Vital-website · testimonialfragment"' ) && ( $linked ? false !== strpos( $html, 'href="https://example.test/testimonial/"' ) : false === strpos( $html, '<a ' ) ), 'Informative publisher labels must remain exactly once beside a meaningfully named linked/static logo.' );
	}
	$html = $render( [ $section( 'no-logo-source-label', 'reviews', [ 'items' => [ [ 'body' => 'Original provider review.', 'source' => 'Original provider note', 'url' => 'https://www.trustpilot.com/reviews/actual-review', 'source_logo' => '' ] ] ] ) ] );
	$assert( false !== strpos( $html, '>Trustpilot <span' ) && 1 === substr_count( $html, 'nova-service__source-text">Original provider note</span>' ) && false === strpos( $html, 'nova-service__provider-logo' ), 'A missing logo must fall back to readable linked provider text while retaining a supplied additional source note once.' );

	$html = $render( [ $section( 'faq', 'faq', [ 'items' => [ [ 'question' => 'Can </script><script>alert(1)</script> be text?', 'answer' => '<p>Yes, visible answer.</p>' ], [ 'question' => 'Empty answer', 'answer' => '' ] ] ] ), $section( 'hidden-faq', 'faq', [ 'items' => [ [ 'question' => 'Hidden FAQ', 'answer' => 'Hidden answer' ] ] ], false ) ] );
	$assert( 1 === substr_count( $html, '<details ' ) && false === strpos( $html, 'Hidden FAQ' ), 'Only visible, complete FAQs should render.' );
	preg_match( '/<script type="application\/ld\+json"[^>]*>(.*?)<\/script>/s', $html, $match );
	$schema = json_decode( $match[1] ?? '', true );
	$assert( is_array( $schema ) && 1 === count( $schema['mainEntity'] ), 'FAQ JSON must parse and describe exactly the visible questions.' );
	$assert( false === strpos( $match[1], '</script>' ) && false !== strpos( $match[1], '\\u003C' ), 'FAQ JSON can close its script element.' );
	$GLOBALS['nova_test_faq_schema'] = false;
	$html = $render( [ $section( 'faq', 'faq', [ 'items' => [ [ 'question' => 'Visible', 'answer' => 'Answer' ] ] ] ) ] );
	$assert( false !== strpos( $html, '<details ' ) && false === strpos( $html, 'application/ld+json' ), 'Schema opt-out should retain the visible FAQ.' );
	unset( $GLOBALS['nova_test_faq_schema'] );

	$html = $render( [ $section( 'related', 'related', [ 'post_ids' => [ 1, 2, 2, 3, 4, 5 ] ] ) ] );
	$assert( 1 === substr_count( $html, 'nova-service__related-card' ) && false !== strpos( $html, 'Published related service' ), 'Related cards should deduplicate and only show other published, unprotected services.' );
	$assert( false === strpos( $html, 'Draft secret' ) && false === strpos( $html, 'Protected secret' ) && false === strpos( $html, 'Unrelated blog' ), 'Related cards exposed ineligible posts.' );
	$html = $render( [ $section( 'small-related', 'related', [ 'items' => [ [ 'title' => 'Related offer', 'url' => 'https://example.test/related/', 'image' => 'https://example.test/thumb.jpg', 'body' => '<p>One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen nineteenth twentieth</p>' ] ] ] ) ] );
	$assert( false !== strpos( $html, 'nova-service__related-copy' ) && false !== strpos( $html, '<p class="nova-service__related-summary">One two three four five six seven eight nine ten eleven twelve thirteen fourteen fifteen sixteen seventeen eighteen</p>' ) && false === strpos( $html, 'nineteenth' ), 'Related service excerpts need a short plain-text summary suitable for compact thumbnail cards.' );

	foreach ( [ 6 => 'Recent service six', 7 => 'Recent service seven', 8 => 'Recent service eight' ] as $id => $title ) {
		$GLOBALS['nova_test_posts'][ $id ] = [ 'type' => 'service_page', 'status' => 'publish', 'title' => $title ];
	}
	$GLOBALS['nova_test_posts'][9] = [ 'type' => 'service_page', 'status' => 'publish', 'title' => 'Unlocked protected secret', 'password' => true, 'unlocked' => true ];
	// A query adapter deliberately returns ineligible/duplicate results as a
	// plugin filter might; the public renderer still has to defend the output.
	$GLOBALS['nova_test_related_candidates'] = [ 1, 3, 4, 5, 9, 2, 2, 6, 7, 8 ];
	$html = $render( [ $section( 'automatic-related', 'related', [ 'title' => 'More services' ] ) ] );
	$assert( 3 === substr_count( $html, 'nova-service__related-card' ) && false !== strpos( $html, 'Published related service' ) && false !== strpos( $html, 'Recent service six' ) && false !== strpos( $html, 'Recent service seven' ) && false === strpos( $html, 'Recent service eight' ), 'Related services should automatically show three eligible recent services without IDs in the posting document.' );
	$assert( false === strpos( $html, 'Draft secret' ) && false === strpos( $html, 'Unrelated blog' ) && false === strpos( $html, 'Protected secret' ) && false === strpos( $html, 'Unlocked protected secret' ) && false === strpos( $html, 'services/1/' ), 'Automatic cards must exclude the current page, other post types, drafts and even password-protected posts unlocked for the current visitor.' );
	$query = end( $GLOBALS['nova_test_related_queries'] );
	$assert( 'service_page' === $query['post_type'] && 'publish' === $query['post_status'] && false === $query['has_password'] && false === $query['suppress_filters'] && 12 === $query['posts_per_page'] && [ 1 ] === $query['post__not_in'], 'Automatic discovery must retain multilingual filters and use a bounded published-service query excluding the current page.' );
	$html = $render( [ $section( 'empty-selection', 'related', [ 'post_ids' => [], 'items' => [] ] ) ] );
	$assert( 3 === substr_count( $html, 'nova-service__related-card' ), 'Explicit empty related collections must resume the same automatic selection as omitted fields.' );
	$before_queries = count( $GLOBALS['nova_test_related_queries'] );
	$html = $render( [ $section( 'manual-related', 'related', [ 'post_ids' => [ 2 ] ] ) ] );
	$assert( 1 === substr_count( $html, 'nova-service__related-card' ) && false === strpos( $html, 'Recent service six' ) && $before_queries === count( $GLOBALS['nova_test_related_queries'] ), 'Existing handpicked related IDs must remain a manual override without automatic additions.' );
	$html = $render( [ $section( 'invalid-manual-related', 'related', [ 'post_ids' => [ 3, 4 ] ] ) ] );
	$assert( false === strpos( $html, 'nova-service__related-card' ) && $before_queries === count( $GLOBALS['nova_test_related_queries'] ), 'An explicit but ineligible old manual selection must not silently be replaced with different services.' );
	$html = $render( [ $section( 'custom-related', 'related', [ 'items' => [ [ 'title' => 'Existing custom article', 'url' => 'https://example.test/article/' ] ] ] ) ] );
	$assert( 1 === substr_count( $html, 'nova-service__related-card' ) && false !== strpos( $html, 'Existing custom article' ) && $before_queries === count( $GLOBALS['nova_test_related_queries'] ), 'Legacy custom link cards must remain an optional manual override.' );
	$html = $render( [ $section( 'hidden-related', 'related', [], false ) ] );
	$assert( false === strpos( $html, 'nova-service__related-card' ) && $before_queries === count( $GLOBALS['nova_test_related_queries'] ), 'Disabling related services must hide the automatic section without querying candidates.' );
	$GLOBALS['nova_test_taxonomies'] = [ 'service_topic' => (object) [ 'public' => true ], 'private_internal' => (object) [ 'public' => false ], 'language' => (object) [ 'public' => true ] ];
	$GLOBALS['nova_test_terms'][1] = [ 'service_topic' => [ 42 ], 'private_internal' => [ 99 ], 'language' => [ 100 ] ];
	$GLOBALS['nova_test_related_term_candidates'] = [ 2, 3, 1, 5, 6 ];
	$GLOBALS['nova_test_related_candidates'] = [ 2, 7, 6, 8 ];
	$before_queries = count( $GLOBALS['nova_test_related_queries'] );
	$html = $render( [ $section( 'term-related', 'related', [] ) ] );
	$assert( strpos( $html, 'Published related service' ) < strpos( $html, 'Recent service six' ) && strpos( $html, 'Recent service six' ) < strpos( $html, 'Recent service seven' ) && false === strpos( $html, 'Recent service eight' ), 'Services sharing a subject/location taxonomy should appear first, followed by recent services to fill the three cards.' );
	$queries = array_slice( $GLOBALS['nova_test_related_queries'], $before_queries );
	$assert( 2 === count( $queries ) && 2 === count( $queries[0]['tax_query'] ) && 'service_topic' === $queries[0]['tax_query'][0]['taxonomy'] && ! isset( $queries[1]['tax_query'] ) && in_array( 2, $queries[1]['post__not_in'], true ) && in_array( 6, $queries[1]['post__not_in'], true ), 'Relevance discovery must ignore private/language taxonomies and deduplicate the recent-post fallback.' );
	$GLOBALS['nova_test_taxonomies'] = [];
	$GLOBALS['nova_test_related_candidates'] = [ 6, 2, 7, 8 ];
	$GLOBALS['nova_test_wpml_languages'] = [ 1 => 'nl', 2 => 'nl', 6 => 'en', 7 => 'nl', 8 => 'nl' ];
	$html = $render( [ $section( 'wpml-related', 'related', [] ) ] );
	$assert( 3 === substr_count( $html, 'nova-service__related-card' ) && false === strpos( $html, 'Recent service six' ) && false !== strpos( $html, 'Recent service eight' ), 'WPML-related services must match the source page language even if a query filter returns another language.' );
	if ( ! function_exists( 'pll_get_post_language' ) ) {
		function pll_get_post_language( $id, $field = 'slug' ) { return $GLOBALS['nova_test_pll_languages'][ $id ] ?? false; }
	}
	$GLOBALS['nova_test_pll_languages'] = [ 1 => 'nl', 2 => 'nl', 6 => 'en', 7 => 'nl', 8 => 'nl' ];
	$html = $render( [ $section( 'polylang-related', 'related', [] ) ] );
	$query = end( $GLOBALS['nova_test_related_queries'] );
	$assert( 'nl' === $query['lang'] && 3 === substr_count( $html, 'nova-service__related-card' ) && false === strpos( $html, 'Recent service six' ), 'Polylang discovery must query the source page language and independently reject other-language candidates.' );
	$GLOBALS['nova_test_pll_languages'] = [ 6 => 'en', 8 => 'en' ];
	$html = $render( [ $section( 'unassigned-language', 'related', [] ) ] );
	$query = end( $GLOBALS['nova_test_related_queries'] );
	$assert( 2 === substr_count( $html, 'nova-service__related-card' ) && false === strpos( $html, 'Recent service six' ) && false === strpos( $html, 'Recent service eight' ) && ! isset( $query['lang'] ), 'An unassigned source language must not invent one or disable the plugin filter to show differently assigned services.' );
	$GLOBALS['nova_test_related_candidates'] = [];
	$html = $render( [ $section( 'no-related-candidates', 'related', [ 'title' => 'Empty related heading' ] ) ] );
	$assert( false === strpos( $html, 'nova-service__related-card' ) && false === strpos( $html, 'Empty related heading' ), 'No eligible services must omit the automatic section rather than render an empty heading.' );

	$html = $render( [ $section( 'form', 'custom_cta', [ 'shortcode' => '[contact-form-7 id="1"]' ] ), $section( 'invalid', 'custom_cta', [ 'shortcode' => '[unknown]' ] ) ] );
	$assert( 1 === $GLOBALS['nova_test_shortcodes'] && false !== strpos( $html, 'data-nova-service-embed' ) && false !== strpos( $html, 'provider-form' ), 'Approved plugin CTA should render once in its CSS-compatible light-DOM wrapper.' );

	$GLOBALS['nova_test_preview'] = true;
	$html = $render( [] );
	$assert( false !== strpos( $html, 'This preview does not change the published page' ), 'Preview must explicitly explain its non-mutating behavior.' );
	$GLOBALS['nova_test_preview'] = false;
	$GLOBALS['nova_test_options'] = [];
	$html = $render( [] );
	$assert( false === strpos( $html, '--nova-service-primary:' ) && false === strpos( $html, '--nova-service-text:' ) && false === strpos( $html, '--nova-service-surface:' ) && false === strpos( $html, '--nova-service-soft:' ) && false === strpos( $html, '--nova-service-soft-text:' ) && false === strpos( $html, '--nova-service-hero-text:' ), 'Unset Service colors must leave brand, body, surface, soft-panel, and hero selection to the site\'s global CSS tokens.' );
	$GLOBALS['nova_test_options'] = [ 'service_cpt_color_primary' => '#123456', 'service_cpt_color_text' => 'red;display:none', 'service_cpt_header_offset' => '3rem', 'service_cpt_content_width' => '100%;background:red' ];
	$html = $render( [] );
	$assert( false !== strpos( $html, '--nova-service-primary:#123456' ) && false !== strpos( $html, '--nova-service-header-offset:3rem' ), 'Valid site brand/spacing settings should be retained.' );
	$assert( false === strpos( $html, 'display:none' ) && false === strpos( $html, 'background:red' ), 'Unsafe CSS option values reached the root style.' );
	$GLOBALS['nova_test_options'] = [ 'service_cpt_color_primary' => '#111827', 'service_cpt_color_surface' => '#ffffff', 'service_cpt_color_text' => '#ffffff', 'service_cpt_color_accent' => '#2563EB', 'service_cpt_color_hero_bg' => '#ffffff', 'service_cpt_color_hero_text' => '#ffffff', 'service_cpt_color_cta_text' => '#111827', 'service_cpt_color_button_bg' => '#111827', 'service_cpt_color_button_text' => '#111827' ];
	$html = $render( [] );
	$assert( false !== strpos( $html, '--nova-service-hero-bg:#ffffff' ) && false !== strpos( $html, '--nova-service-hero-text:#111827' ), 'An old white-on-white image-hero palette must become readable on the native solid hero.' );
	$assert( false !== strpos( $html, '--nova-service-text:#111827' ) && false !== strpos( $html, '--nova-service-cta-text:#ffffff' ) && false !== strpos( $html, '--nova-service-button-text:#ffffff' ), 'Low-contrast paper/CTA/button pairs must use readable local copy colors.' );
	$assert( false !== strpos( $html, '--nova-service-accent:#2563EB' ) && false !== strpos( $html, '--nova-service-soft:#f2f6fe' ), 'The accent must be retained while the soft panel uses a subtle neutral tint.' );
	$GLOBALS['nova_test_options'] = [ 'service_cpt_color_hero_bg' => '#102333', 'service_cpt_color_hero_text' => '#fefdfc', 'service_cpt_color_primary' => '#133333', 'service_cpt_color_cta_text' => '#fefdfc' ];
	$html = $render( [] );
	$assert( false !== strpos( $html, '--nova-service-hero-bg:#102333' ) && false !== strpos( $html, '--nova-service-hero-text:#fefdfc' ) && false !== strpos( $html, '--nova-service-cta-text:#fefdfc' ), 'Readable customized brand palettes must remain unchanged.' );
	$GLOBALS['nova_test_options'] = [ 'service_cpt_color_hero_bg' => '#808080', 'service_cpt_color_hero_text' => '#888888' ];
	$html = $render( [] );
	$assert( false !== strpos( $html, '--nova-service-hero-text:#000000' ), 'Mid-tone low-contrast backgrounds need a fallback meeting normal-text contrast.' );
	$assert( '' === ServiceRenderer::render( 4 ), 'Renderer must reject other post types.' );
	$assert( '<form id="password-form"></form>' === ServiceRenderer::render( 5 ), 'Password-protected services must keep the password gate.' );
	ServiceRenderer::enqueue_assets();
	$assert( 2 === count( $GLOBALS['nova_test_assets'] ), 'Native page assets were not enqueued.' );
	$assert( [ 'service-cpt-frontend' ] === $GLOBALS['nova_test_dequeues'], 'A native service must remove only its legacy stylesheet.' );
	$assert( false !== strpos( $GLOBALS['nova_test_asset_versions']['nova-service-frontend-v2'], (string) filemtime( $nova_test_root . '/modules/service-page-cpt/assets/service-frontend-v2.js' ) ), 'Native asset versions must change with their file timestamps.' );
	$GLOBALS['nova_test_singular'] = false;
	ServiceRenderer::enqueue_assets();
	$assert( 2 === count( $GLOBALS['nova_test_assets'] ), 'Service assets leaked onto another page.' );
	ServiceRenderer::remove_legacy_style();
	$assert( [ 'service-cpt-frontend' ] === $GLOBALS['nova_test_dequeues'], 'Legacy stylesheet removal must not affect other pages.' );
	require_once $nova_test_root . '/modules/service-page-cpt/includes/class-service-live-preview.php';
	$preview_flag = new \ReflectionProperty( \SEORAI\ServicePageCPT\ServiceLivePreview::class, 'rendering' );
	$preview_flag->setValue( null, true );
	$shortcodes_before = $GLOBALS['nova_test_shortcodes'];
	$review_requests_before = $GLOBALS['nova_test_review_resolutions'];
	$saved_document_before = $GLOBALS['nova_test_document'];
	$candidate = [ 'version' => 2, 'sections' => [ $section( 'candidate-hero', 'hero', [ 'body' => 'Unsaved candidate content' ] ), $section( 'candidate-form', 'custom_cta', [ 'shortcode' => '[contact-form-7 id="1"]' ] ), $section( 'candidate-google', 'reviews', [ 'review_source' => $google_source ] ) ] ];
	try { $candidate_html = ServiceRenderer::render_candidate( 1, $candidate, 'Unsaved title <safe>' ); }
	finally { $preview_flag->setValue( null, false ); }
	$assert( false !== strpos( $candidate_html, 'Unsaved title &lt;safe&gt;' ) && false !== strpos( $candidate_html, 'Unsaved candidate content' ) && false !== strpos( $candidate_html, 'data-nova-service-section-id="candidate-hero"' ) && $saved_document_before === $GLOBALS['nova_test_document'], 'Candidate rendering must reflect unsaved content/title and raw section IDs without changing saved documents.' );
	$assert( $shortcodes_before === $GLOBALS['nova_test_shortcodes'] && $review_requests_before === $GLOBALS['nova_test_review_resolutions'] && false !== strpos( $candidate_html, 'read-only preview placeholder' ), 'Live candidate rendering must not execute provider shortcodes or refresh Google review connections.' );
	$normal_after_candidate = $render( [ $section( 'saved-hero', 'hero', [] ) ] );
	$assert( false !== strpos( $normal_after_candidate, 'Current service' ) && false === strpos( $normal_after_candidate, 'Unsaved title' ) && ! \SEORAI\ServicePageCPT\ServiceLivePreview::is_rendering(), 'Candidate title/provider-preview flags must not leak into the normal renderer.' );
	echo 'Service renderer: ' . $checks . " regression assertions passed.\n";
}
