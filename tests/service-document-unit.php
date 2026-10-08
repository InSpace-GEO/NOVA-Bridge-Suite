<?php
/** Standalone document behavior checks: php tests/service-document-unit.php. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/' );

class WP_Error {
	public $code; public $message; private $data;
	public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}
class WP_REST_Request {
	private $params;
	public function __construct( array $params = [] ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function has_param( $key ) { return array_key_exists( $key, $this->params ); }
	public function offsetGet( $key ) { return $this->get_param( $key ); }
}
class TestResponse {
	private $data;
	public function __construct( $data ) { $this->data = $data; }
	public function get_data() { return $this->data; }
	public function set_data( $data ) { $this->data = $data; }
}
class TestRequest implements ArrayAccess {
	private $params;
	public function __construct( array $params = [] ) { $this->params = $params; }
	public function get_param( $key ) { return $this->params[ $key ] ?? null; }
	public function has_param( $key ) { return array_key_exists( $key, $this->params ); }
	public function get_method() { return $this->params['_method'] ?? 'POST'; }
	public function get_route() { return $this->params['_route'] ?? '/wp/v2/services'; }
	public function set_param( $key, $value ) { $this->params[ $key ] = $value; }
	public function offsetExists( $key ): bool { return isset( $this->params[ $key ] ); }
	public function offsetGet( $key ): mixed { return $this->get_param( $key ); }
	public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}

$GLOBALS['test_meta'] = []; $GLOBALS['test_options'] = []; $GLOBALS['test_edit'] = true;
$GLOBALS['test_posts'] = [ 1 => (object) [ 'ID' => 1, 'post_title' => 'Original service', 'post_type' => 'service_page', 'post_status' => 'publish', 'post_content' => '<p>Custom saved content.</p>' ], 2 => (object) [ 'ID' => 2, 'post_title' => 'New service', 'post_type' => 'service_page', 'post_status' => 'draft', 'post_content' => '' ], 3 => (object) [ 'ID' => 3, 'post_title' => 'Auto Draft', 'post_type' => 'service_page', 'post_status' => 'auto-draft', 'post_content' => '' ], 10 => (object) [ 'ID' => 10, 'post_title' => 'Useful article', 'post_type' => 'post', 'post_status' => 'publish', 'post_excerpt' => 'Article details.' ], 20 => (object) [ 'ID' => 20, 'post_title' => 'Related service', 'post_type' => 'service_page', 'post_status' => 'publish' ] ];
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_post( $id ) { return $GLOBALS['test_posts'][ $id ] ?? null; }
function get_the_title( $id ) { return get_post( $id )->post_title; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['test_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['test_meta'][ $id ][ $key ] = wp_unslash( $value ); return true; }
function get_option( $key, $default = false ) { return $GLOBALS['test_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['test_options'][ $key ] = $value; return true; }
function wp_upload_dir() { return [ 'baseurl' => 'https://example.test/wp-content/uploads' ]; }
function current_user_can( $capability, $id = 0 ) { return $GLOBALS['test_edit']; }
function absint( $value ) { return abs( (int) $value ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_kses_post( $value ) { return strip_tags( preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $value ), '<p><a><em><strong><ul><ol><li><h2><h3><h4><table><thead><tbody><tr><th><td><img><figure><figcaption><div><blockquote><br>' ); }
function wp_kses_allowed_html( $context ) { return []; }
function wp_kses( $value, $allow, $protocols = [] ) { return wp_kses_post( str_replace( [ '<iframe', '</iframe' ], [ '<div', '</div' ], $value ) ); }
function esc_url_raw( $value, $protocols = [] ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function apply_filters( $hook, $value ) { return $value; }
function shortcode_exists( $tag ) { return in_array( $tag, [ 'contact-form-7', 'wpforms', 'dangerous_shortcode' ], true ); }
function wp_get_attachment_image_url( $id, $size ) { return 'https://example.test/image-' . $id . '.jpg'; }
function wp_get_attachment_caption( $id ) { return 'Image ' . $id; }
function get_post_thumbnail_id( $id ) { return 41; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_verify_nonce( $nonce, $action ) { return $nonce === wp_create_nonce( $action ); }
function get_permalink( $id ) { return 'https://example.test/service/' . $id; }
function add_query_arg( $query, $url ) { return $url . '?' . http_build_query( $query ); }
function rest_ensure_response( $value ) { return $value; }
function register_rest_field( $type, $key, $args ) { $GLOBALS['test_rest_fields'][ $key ] = $args; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['test_rest_routes'][] = $namespace . $route; }
function parse_blocks( $value ) { return [ [ 'blockName' => 'core/paragraph', 'innerHTML' => $value, 'innerBlocks' => [], 'attrs' => [] ] ]; }
// Real WP schema validation is covered by the staging regression. These checks
// exercise our own top-level, duplicate-ID, URL and shortcode validation.
function rest_validate_value_from_schema( $value, $schema, $field ) { return isset( $GLOBALS['test_schema_error'] ) ? new WP_Error( 'rest_not_in_enum', 'Invalid schema value.', [ 'param' => $field, 'details' => [ 'allowed' => [ 'full', 'half' ] ] ] ) : true; }

require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-document.php';
require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-shared.php';
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceShared as Shared;

$checks = 0;
function check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }
function section( $id, $type, $data ) { return [ 'id' => $id, 'type' => $type, 'enabled' => true, 'data' => $data ]; }
function doc( $sections ) { return [ 'version' => 2, 'sections' => $sections ]; }

$GLOBALS['test_meta'][1] = [ 'sp_hero_title' => 'Legacy title', 'sp_hero_primary_label' => 'Call now', 'sp_hero_primary_url' => 'tel:+31123456789', 'sp_intro' => '<p>Offer intro.</p>', 'sp_main_1' => '<h2>How it works</h2><p>Detailed content.</p>', 'sp_main_2' => '<p>Why clients choose us.</p>', 'sp_image_1' => 42, 'sp_tab_1_title' => 'Qualifications', 'sp_tab_1_content' => '<p>Qualified experts.</p>', 'sp_table' => [ [ 'Service', 'Details' ], [ 'Advice', 'Personal' ] ], 'sp_faq' => [ [ 'question' => 'How does it work?', 'answer' => '<p>We help personally.</p>' ] ], 'sp_related_posts' => [ 10, 20 ], 'sp_cta_title' => 'Get help', 'sp_cta_button_label' => 'Contact', 'sp_cta_button_url' => '/contact/' ];
$before_meta = $GLOBALS['test_meta']; $before_content = get_post( 1 )->post_content;
$preview = Document::migration_preview( 1 );
check( $GLOBALS['test_meta'] === $before_meta && get_post( 1 )->post_content === $before_content, 'migration preview does not mutate legacy data' );
check( 'legacy' === $preview['mode'] && false === $preview['has_saved_document'], 'existing pages remain legacy before explicit migration' );
$mapped = array_column( $preview['document']['sections'], null, 'id' );
check( 'Legacy title' === $mapped['hero']['data']['title'] && 'tel:+31123456789' === $mapped['hero']['data']['buttons'][0]['url'], 'hero and early legacy CTA are mapped' );
check( '<p>Offer intro.</p>' === $mapped['legacy_intro']['data']['body'] && 'general' === $mapped['legacy_intro']['data']['intent'], 'legacy body maps with unrestricted general intent' );
check( 42 === $mapped['legacy_image_1']['data']['images'][0]['id'] && isset( $mapped['legacy_table'], $mapped['legacy_tab_1'] ), 'images, table and tabs survive mapping' );
check( $before_meta[1]['sp_faq'][0]['answer'] === $mapped['legacy_faq']['data']['items'][0]['answer'] && [ 20 ] === $mapped['legacy_related']['data']['post_ids'], 'FAQ pairs and related service IDs survive' );
check( 'https://example.test/service/10' === $mapped['legacy_related']['data']['items'][0]['url'], 'legacy blog links remain visible as manual related cards' );
check( false !== strpos( json_encode( $preview['document'] ), 'Custom saved content.' ), 'custom saved HTML is retained' );
check( ! empty( $preview['warnings'] ), 'migration preview exposes review warnings' );

Document::apply_migration( new TestRequest( [ 'id' => 1, 'action' => 'migrate' ] ) );
check( Document::is_v2( 1 ), 'explicit migration activates v2' );
$edited = doc( [ section( 'edited', 'rich_text', [ 'body' => '<p>New native content.</p>', 'intent' => 'general' ] ) ] );
check( true === Document::save_document( 1, $edited ), 'valid edit saves native document' );
$native = get_post_meta( 1, Document::META_DOCUMENT, true );
Document::apply_migration( new TestRequest( [ 'id' => 1, 'action' => 'migrate' ] ) );
check( $native === get_post_meta( 1, Document::META_DOCUMENT, true ), 'repeated migration does not overwrite an edited v2 document' );
Document::apply_migration( new TestRequest( [ 'id' => 1, 'action' => 'legacy' ] ) );
check( ! Document::is_v2( 1 ) && $native === get_post_meta( 1, Document::META_DOCUMENT, true ), 'rollback switches mode while retaining native edits' );
foreach ( $before_meta[1] as $key => $value ) { check( get_post_meta( 1, $key, true ) === $value, 'legacy meta survives: ' . $key ); }
check( $before_content === get_post( 1 )->post_content, 'legacy post_content survives migration and rollback' );
Document::apply_migration( new TestRequest( [ 'id' => 1, 'action' => 'v2' ] ) );
check( Document::is_v2( 1 ) && $native === get_post_meta( 1, Document::META_DOCUMENT, true ), 'reactivation preserves native edits' );

check( is_wp_error( Document::validate_document( [ 'sections' => [] ] ) ), 'missing version is rejected' );
check( [] === Document::sanitize_document( doc( [ [ 'type' => [ 'malformed' ] ] ] ) )['sections'], 'malformed stored section types do not crash sanitization' );
$many_sections = [];
for ( $i = 0; $i < 65; $i++ ) { $many_sections[] = section( 'section_' . $i, 'rich_text', [ 'body' => '<p>Preserve this content.</p>' ] ); }
check( 65 === count( Document::sanitize_document( doc( $many_sections ) )['sections'] ), 'read sanitization never silently truncates migrated content beyond the write limit' );
check( is_wp_error( Document::validate_document( doc( [ section( 'same', 'cta', [] ), section( 'same', 'cta', [] ) ] ) ) ), 'duplicate IDs are rejected' );
check( is_wp_error( Document::validate_document( doc( [ section( 'bad', 'cta', [ 'buttons' => [ [ 'label' => 'bad', 'url' => 'javascript:alert(1)' ] ] ] ) ] ) ) ), 'script action URLs are rejected' );
check( is_wp_error( Document::validate_document( doc( [ section( 'bad', 'people', [ 'images' => [ [ 'url' => 'https://user:secret@example.test/p.jpg' ] ] ] ) ] ) ) ), 'URLs carrying credentials are rejected' );
check( true === Document::validate_document( doc( [ section( 'ok', 'cta', [ 'buttons' => [ [ 'url' => '#contact' ], [ 'url' => '/contact/' ], [ 'url' => 'mailto:team@example.test' ] ] ] ) ] ) ), 'supported anchor, path and email actions are accepted' );
check( Document::valid_map_url( 'https://www.google.com/maps/embed?pb=example' ) && ! Document::valid_map_url( 'https://www.google.com.evil.test/maps/embed' ), 'map URL host boundary is exact' );
check( '' !== Document::safe_shortcode( '[contact-form-7 id="5"]' ) && '' === Document::safe_shortcode( '[dangerous_shortcode]' ), 'only known installed form plugins are allowed' );
check( '' === Document::safe_shortcode( '[wpforms id="5"][dangerous_shortcode]' ), 'multiple or nested shortcode payloads are rejected' );
check( '' === Document::safe_shortcode( '[gravityform id="5"]' ) && '[gravityform id="5"]' === Document::sanitize_document( doc( [ section( 'inactive', 'custom_cta', [ 'shortcode' => '[gravityform id="5"]' ] ) ] ) )['sections'][0]['data']['shortcode'], 'inactive provider shortcode remains stored while frontend execution is disabled' );
check( is_wp_error( Document::validate_document( doc( [ section( 'unsafe', 'custom_cta', [ 'html' => '<iframe src="https://evil.test/embed"></iframe>' ] ) ] ) ) ), 'unapproved custom iframe provider is rejected' );
check( false === strpos( Document::safe_custom_html( '<iframe src="https://evil.test/embed"></iframe><p>Safe</p><script>alert(1)</script>' ), 'evil.test' ), 'blocked embed and script are stripped during sanitization' );
check( true === Document::validate_document( doc( [ section( 'safe', 'custom_cta', [ 'html' => '<iframe src="https://calendly.com/example"></iframe>' ] ) ] ) ), 'supported HTTPS booking provider is accepted' );
check( true === Document::validate_document( doc( [ section( 'form', 'custom_cta', [ 'html' => '<form action="/form-handler/" method="post"><input type="email" name="email"><button type="submit">Send</button></form>' ] ) ] ) ), 'site-relative native form submission is accepted' );
check( is_wp_error( Document::validate_document( doc( [ section( 'form', 'custom_cta', [ 'html' => '<form action="http://example.test/handler" method="post"></form>' ] ) ] ) ) ), 'insecure form submission is rejected' );
check( is_wp_error( Document::validate_document( doc( [ section( 'form', 'custom_cta', [ 'html' => '<form action="https://example.test/handler" method="delete"></form>' ] ) ] ) ) ), 'unsupported form method is rejected' );

$GLOBALS['test_edit'] = false;
check( is_wp_error( Document::save_document( 1, doc( [] ) ) ) && $native === get_post_meta( 1, Document::META_DOCUMENT, true ), 'unauthorized write preserves document' );
$GLOBALS['test_edit'] = true;
check( true === Document::save_document( 1, doc( [] ) ) && [] === Document::get_document( 1 )['sections'], 'explicit empty section list clears rendered content' );
Document::validate_rest_insert( new stdClass(), new TestRequest( [ 'id' => 1, 'title' => 'Update only title' ] ) );
check( [] === Document::get_document( 1 )['sections'], 'omitting document during REST update preserves saved sections' );

Document::validate_rest_insert( new stdClass(), new TestRequest( [ 'meta' => [ 'sp_main_1' => '<p>Legacy integration</p>' ] ] ) );
Document::initialize_new_post( 2, get_post( 2 ), false );
check( ! Document::is_v2( 2 ), 'older REST posting integrations create legacy pages' );
check( Document::request_uses_legacy_payload( new TestRequest( [ 'content' => '<p>Existing posting integration.</p>' ] ) ), 'plain-content posting integration remains compatible' );
Document::validate_rest_insert( new stdClass(), new TestRequest( [ 'nova_service_document' => doc( [] ), 'meta' => [ 'sp_main_1' => 'old' ] ] ) );
Document::initialize_new_post( 2, get_post( 2 ), false );
check( Document::is_v2( 2 ), 'explicit native document takes precedence over legacy payload' );
Document::initialize_new_post( 1, get_post( 1 ), true );
check( Document::is_v2( 1 ), 'ordinary update preserves the current mode' );
check( '' === Document::default_document( 3 )['sections'][0]['data']['title'], 'auto drafts never expose Auto Draft as a service heading' );

$presets = doc( [ section( 'primary_hero', 'hero', [ 'buttons' => [ [ 'label' => 'Shared contact', 'url' => '/contact/' ] ], 'images' => [ [ 'id' => 41, 'url' => 'https://example.test/team.jpg', 'alt' => 'Our team' ] ] ] ), section( 'team_variant', 'people', [ 'images' => [ [ 'id' => 42, 'url' => 'https://example.test/team-2.jpg', 'alt' => 'Team member' ] ] ] ), section( 'why-us', 'rich_text', [ 'title' => 'Business reasons', 'intent' => 'why', 'body' => '<p>Shared reasons.</p>' ] ) ] );
$hero_ref = section( 'hero', 'hero', (object) [] ); $hero_ref['shared_key'] = 'primary_hero';
$team_ref = section( 'team', 'people', (object) [] ); $team_ref['shared_key'] = 'team_variant';
$why_ref = section( 'business-reasons', 'rich_text', (object) [] ); $why_ref['shared_key'] = 'why-us';
$template = doc( [ $hero_ref, section( 'offer', 'rich_text', [ 'title' => 'Offer', 'intent' => 'offer', 'body' => '' ] ), $team_ref, $why_ref ] );
$document_before_template = Document::get_document( 2 );
check( true === Shared::save_settings( $presets, $template ), 'validated site library and default layout save together' );
check( 'primary_hero' === Document::default_document()['sections'][0]['shared_key'], 'new pages inherit the configured site layout' );
check( (object) [] == Document::default_document()['sections'][0]['data'], 'empty local shared data stays empty rather than overwriting inherited fields' );
check( json_encode( $document_before_template ) === json_encode( Document::get_document( 2 ) ), 'changing the site template does not restructure an existing native document' );
// Recreate the fixture as a new page after the site layout is configured.
unset( $GLOBALS['test_meta'][2] ); Document::reset_rest_flags(); Document::initialize_new_post( 2, get_post( 2 ), false );
check( true === Document::save_content( 2, [ 'hero' => [ 'body' => '<p>Specific service summary.</p>' ], 'offer' => [ 'body' => '<p>Specific offer.</p>', 'intent' => 'general' ], 'faq' => [ [ 'question' => 'Question?', 'answer' => '<p>Answer.</p>' ] ] ] ), 'simple content API saves onto site template' );
$raw = Document::get_document( 2 ); $resolved = Document::resolve_document( 2 );
check( 'primary_hero' === $raw['sections'][0]['shared_key'] && ! isset( $raw['sections'][0]['data']['buttons'] ), 'content posting retains shared reference and does not copy CTA selections into local data' );
check( 'Shared contact' === $resolved['sections'][0]['data']['buttons'][0]['label'] && '<p>Specific service summary.</p>' === $resolved['sections'][0]['data']['body'], 'shared CTA and local text merge correctly' );
$before_count = count( $raw['sections'] );
Document::save_content( 2, [ 'why' => [ 'body' => '<p>Specific source-backed reason.</p>' ] ] );
$patched_why = Document::get_document( 2 );
check( $before_count === count( $patched_why['sections'] ) && 'why-us' === $patched_why['sections'][3]['shared_key'] && '<p>Specific source-backed reason.</p>' === $patched_why['sections'][3]['data']['body'], 'provider write matches inherited intent on arbitrary-ID shared slot without appending or copying shared fields' );
check( '<p>Specific source-backed reason.</p>' === Document::get_content( 2 )['rich_text_3']['body'], 'generic provider read returns content written through the compatible older alias' );
Document::save_content( 2, [ 'why' => [ 'title' => 'Other useful information', 'body' => '<p>General fallback copy.</p>', 'intent' => 'general' ] ] );
Document::save_content( 2, [ 'why' => [ 'body' => '<p>Refreshed general copy.</p>' ] ] );
check( $before_count === count( Document::get_document( 2 )['sections'] ) && '<p>Refreshed general copy.</p>' === Document::get_content( 2 )['rich_text_3']['body'] && ! isset( Document::get_content( 2 )['rich_text_3']['intent'] ), 'generic arbitrary-ID shared slot stays stable while obsolete classification is absent from the routine payload' );
$presets['sections'][0]['data']['buttons'][0]['label'] = 'Updated shared CTA';
Shared::save_presets( $presets );
check( 'Updated shared CTA' === Document::resolve_document( 2 )['sections'][0]['data']['buttons'][0]['label'], 'shared edits update existing linked pages automatically' );
Document::save_content( 2, [ 'offer' => [ 'title' => 'Edited title only' ] ] );
check( '<p>Specific offer.</p>' === Document::get_content( 2 )['rich_text_1']['body'] && '<p>Specific service summary.</p>' === Document::get_content( 2 )['hero']['body'], 'omitted content fields preserve previous text and shared data' );
$raw = Document::get_document( 2 ); $raw['sections'][0]['data']['buttons'] = [];
Document::save_document( 2, $raw );
check( [] === Document::resolve_document( 2 )['sections'][0]['data']['buttons'], 'explicit empty local collection suppresses shared buttons' );
$raw['sections'][0]['data']['buttons'] = [ [ 'label' => 'Local CTA', 'url' => '/local/' ] ];
Document::save_document( 2, $raw );
$presets['sections'][0]['data']['buttons'][0]['label'] = 'New shared variant'; Shared::save_presets( $presets );
check( 'Local CTA' === Document::resolve_document( 2 )['sections'][0]['data']['buttons'][0]['label'], 'explicit local override survives later library edits' );
$hidden = Document::get_document( 2 ); $hidden['sections'][1]['enabled'] = false; Document::save_document( 2, $hidden );
Document::save_content( 2, [ 'offer' => [ 'body' => '<p>Refreshed hidden copy.</p>' ] ] );
check( false === Document::get_document( 2 )['sections'][1]['enabled'], 'content refresh respects an intentionally hidden existing section' );
Document::save_content( 2, [ 'hero' => [ 'body' => '' ], 'faq' => [] ] );
check( '' === Document::get_content( 2 )['hero']['body'] && [] === Document::get_content( 2 )['faq'] && 'Local CTA' === Document::resolve_document( 2 )['sections'][0]['data']['buttons'][0]['label'], 'explicit empty content fields clear only those fields and retain CTA selections' );
$before_settings = $GLOBALS['test_options']; $bad_template = $template; $bad_template['sections'][0]['shared_key'] = 'unknown';
check( is_wp_error( Shared::save_settings( $presets, $bad_template ) ) && $before_settings === $GLOBALS['test_options'], 'invalid template reference prevents partial settings mutation' );
$recursive = $presets; $recursive['sections'][0]['shared_key'] = 'team_variant';
check( is_wp_error( Shared::save_settings( $recursive, $template ) ), 'shared libraries cannot recursively reference other presets' );
$presets['sections'][0]['enabled'] = false; Shared::save_presets( $presets );
check( false === Document::resolve_document( 2 )['sections'][0]['enabled'], 'disabling a shared preset hides every linked section' );
$missing = $template; $missing['sections'][0]['shared_key'] = 'missing';
check( ! empty( Shared::warnings( $missing ) ), 'missing preset generates actionable context warning' );
check( false !== strpos( Document::safe_video_url( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&autoplay=1' ), 'autoplay=0' ), 'YouTube video URLs normalize without autoplay' );
check( false !== strpos( Document::safe_video_url( 'https://player.vimeo.com/video/12345?background=1&autoplay=1' ), 'background=0' ), 'Vimeo background/autoplay is disabled' );
check( 'https://example.test/wp-content/uploads/2026/video.mp4' === Document::safe_video_url( 'https://example.test/wp-content/uploads/2026/video.mp4' ) && '' === Document::safe_video_url( 'https://evil.test/video.mp4' ), 'native videos are restricted to this site upload directory' );
check( '' === Document::safe_video_url( 'https://example.test/wp-content/uploads/../private/video.mp4' ), 'upload video traversal path is rejected' );
check( isset( Document::context()['shared']['presets'], Document::context()['content_schema'] ), 'posting context includes reusable variants and content-only API schema' );
$colliding = doc( [ section( 'offer', 'cta', [] ), section( 'content_offer', 'cta', [] ) ] );
$colliding = Document::patch_content( $colliding, [ 'offer' => [ 'body' => '<p>General copy.</p>', 'intent' => 'general' ] ] );
$colliding = Document::patch_content( $colliding, [ 'offer' => [ 'body' => '<p>Updated copy.</p>' ] ] );
check( 3 === count( $colliding['sections'] ) && '<p>Updated copy.</p>' === $colliding['sections'][2]['data']['body'], 'provider field mapping remains stable after generated ID collisions and general-intent fallback' );
$row_template = doc( [ section( 'left', 'rich_text', [ 'width' => 'half', 'body' => '<p>Offer.</p>' ] ), section( 'right', 'credentials', [ 'width' => 'half', 'items' => [ [ 'title' => 'Verified qualification' ] ] ] ), section( 'full', 'cta', [ 'width' => 'full', 'buttons' => [ [ 'label' => 'Contact', 'url' => '/contact/' ] ] ] ) ] );
check( true === Document::save_document( 2, $row_template ) && [ 'half', 'half', 'full' ] === array_column( array_column( Document::get_document( 2 )['sections'], 'data' ), 'width' ), 'two half-row sections and a full-row section round-trip without altering their order or contents' );
$row_presets = doc( [ section( 'shared-half', 'rich_text', [ 'width' => 'half', 'body' => '<p>Reusable copy.</p>' ] ) ] );
$row_ref = section( 'linked-row', 'rich_text', (object) [] ); $row_ref['shared_key'] = 'shared-half';
check( true === Shared::save_settings( $row_presets, doc( [ $row_ref ] ) ) && 'half' === Shared::resolve_section( $row_ref )['data']['width'], 'row width inherits from a shared preset without copying it into local data' );
check( ! isset( ( (array) Document::default_document()['sections'][0]['data'] )['width'] ), 'default row reference remains sparse and follows future shared width changes' );
$row_ref['data'] = [ 'width' => 'full' ];
check( 'full' === Shared::resolve_section( $row_ref )['data']['width'], 'explicit full-row local override replaces an inherited half-row setting' );
$row_ref['data'] = (object) [];
check( 'half' === Shared::resolve_section( $row_ref )['data']['width'], 'removing the local width override resumes shared half-row inheritance' );

$saved_options = $GLOBALS['test_options']; $GLOBALS['test_options'] = [];
$fresh_defaults = Document::default_document();
check( [] === $GLOBALS['test_options'] && 9 === count( Shared::get_presets()['sections'] ) && 'default_hero' === $fresh_defaults['sections'][0]['shared_key'], 'fresh client defaults seed lazily without option writes and new pages follow the reserved hero' );
check( 'rich_text_1' === $fresh_defaults['sections'][1]['content_slot'] && '' === $fresh_defaults['sections'][1]['data']['title'] && ! isset( $fresh_defaults['sections'][1]['data']['intent'] ), 'fresh rich-text sections are generic with empty headings and no classification' );
$fresh_by_id = array_column( $fresh_defaults['sections'], null, 'id' );
check( true === $fresh_by_id['related']['enabled'] && 'default_people' === $fresh_by_id['people']['shared_key'], 'automatic related is enabled and shared people defaults require no per-page selection' );
$large_library = []; for ( $i = 0; $i < 60; $i++ ) { $large_library[] = section( 'existing_' . $i, 'cta', [] ); }
$GLOBALS['test_options'][ Shared::OPTION_PRESETS ] = doc( $large_library );
check( 60 === count( Shared::get_presets()['sections'] ) && 60 === count( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ]['sections'] ), 'lazy defaults do not expand an existing valid sixty-section library beyond its write capacity' );
$GLOBALS['test_options'] = [];
check( true === Document::save_document( 2, $fresh_defaults ), 'fresh generic template saves' );
$note = Document::meta_note( 2 );
check( false !== strpos( $note, '30% in rich_text_1' ) && false !== strpos( $note, '40% in rich_text_3' ) && false !== strpos( $note, 'Short content may use one slot' ), 'ordinary response notes provide three-slot proportions and the short-content escape case' );
check( ! isset( Document::content_schema()['properties']['offer'], Document::content_schema()['properties']['related_service_ids'], Document::document_schema()['properties']['sections']['items']['properties']['data']['properties']['intent'] ), 'published schemas advertise only generic content and optional section overrides' );
$reordered = $fresh_defaults; [ $reordered['sections'][1], $reordered['sections'][3] ] = [ $reordered['sections'][3], $reordered['sections'][1] ]; Document::save_document( 2, $reordered );
check( false !== strpos( Document::meta_note( 2 ), '30% in rich_text_3, 30% in rich_text_2 and 40% in rich_text_1' ), 'percentage guidance follows actual reordered section reading order rather than slot numbering' );
$reordered['sections'][1]['enabled'] = false; Document::save_document( 2, $reordered );
check( ! isset( Document::get_content( 2 )['rich_text_3'], Document::meta_descriptions( 2 )['rich_text_3'] ) && false !== strpos( Document::meta_note( 2 ), '40% in rich_text_2 and 60% in rich_text_1' ), 'disabled fields disappear and two remaining sections receive forty-sixty guidance in reading order' );
$reordered['sections'][2]['enabled'] = false; Document::save_document( 2, $reordered );
check( false !== strpos( Document::meta_note( 2 ), '100% of the remaining body' ), 'one visible rich-text slot receives the full body' );
$old_slot = section( 'custom_old', 'rich_text', [ 'body' => '<p>Unchanged old source.</p>', 'intent' => 'why' ] ); $old_slot['content_slot'] = 'why';
check( true === Document::validate_document( doc( [ $old_slot ] ) ) && 'rich_text_3' === Document::sanitize_document( doc( [ $old_slot ] ) )['sections'][0]['content_slot'], 'legacy slot metadata validates and normalizes without changing old source copy' );
$export = Document::public_document( doc( [ $old_slot ] ) );
check( 'rich_text_3' === $export['sections'][0]['content_slot'] && ! isset( $export['sections'][0]['data']['intent'] ) && '<p>Unchanged old source.</p>' === $export['sections'][0]['data']['body'], 'public document strips obsolete metadata after preserving its stable generic slot and body' );
$request = new TestRequest( [ '_route' => '/wp/v2/services/2', 'nova_service_content' => [ 'offer' => [ 'body' => '<p>Old client copy.</p>', 'intent' => 'general' ], 'related_service_ids' => [ 20 ] ] ] );
check( null === Document::normalize_rest_request( null, null, $request ) && isset( $request->get_param( 'nova_service_content' )['rich_text_1'] ) && ! isset( $request->get_param( 'nova_service_content' )['offer'], $request->get_param( 'nova_service_content' )['related_service_ids'] ) && 'rich_text_3' === $request->get_param( 'nova_service_document' )['sections'][1]['id'], 'legacy payload aliases normalize before core validation using the route ID without replacing the existing layout' );
Document::save_document( 2, $fresh_defaults );
check( true === Document::save_content( 2, [ 'rich_text_1' => [ 'body' => '<p>Exact first copy.</p>' ], 'section_overrides' => [ 'hero' => [ 'data' => [ 'buttons' => [ [ 'label' => 'Local CTA', 'url' => '/local/' ] ] ] ] ] ] ) && 'Local CTA' === Document::resolve_document( 2 )['sections'][0]['data']['buttons'][0]['label'], 'optional section override and generic text patch preserve shared references and override only supplied fields' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'hero' => [ 'inherit' => [ 'buttons' ] ] ] ] ) && ! isset( Document::get_document( 2 )['sections'][0]['data']['buttons'] ), 'inherit explicitly removes a local field override without detaching shared content' );
$before_invalid = json_encode( Document::get_document( 2 ) );
check( is_wp_error( Document::save_content( 2, [ 'rich_text_1' => [ 'body' => 'MUST_NOT_SAVE' ], 'section_overrides' => [ 'unknown' => [ 'enabled' => true ] ] ] ) ) && $before_invalid === json_encode( Document::get_document( 2 ) ), 'unknown override target rejects atomically before changing content' );
check( is_wp_error( Document::save_content( 2, [ 'section_overrides' => [ 'hero' => [ 'shared_key' => 'default_people' ] ] ] ) ) && $before_invalid === json_encode( Document::get_document( 2 ) ), 'different-type shared variant rejects atomically' );
$numeric_document = doc( [ section( '123', 'hero', [ 'body' => '<p>Numeric section ID.</p>' ] ) ] ); Document::save_document( 2, $numeric_document );
check( true === Document::save_content( 2, [ 'section_overrides' => [ '123' => [ 'data' => [ 'body' => '<p>Numeric ID updated.</p>' ] ] ] ] ) && '<p>Numeric ID updated.</p>' === Document::get_document( 2 )['sections'][0]['data']['body'], 'numeric-string section IDs remain patchable after JSON object keys become PHP integers' );
Document::save_document( 2, $fresh_defaults ); Document::save_content( 2, [ 'rich_text_1' => [ 'body' => '<p>Exact first copy.</p>' ] ] );
$response = Document::prepare_rest_response( new TestResponse( [ 'meta' => [ 'sp_main_1' => 'retained legacy', 'seo_custom' => 'keep SEO' ], 'meta_all' => [ 'sp_intro' => 'legacy' ], 'nova_service_context' => 'obsolete' ] ), get_post( 2 ), new TestRequest() )->get_data();
check( isset( $response['meta_note'], $response['meta_descriptions'], $response['nova_service_content']['rich_text_1'] ) && ! isset( $response['nova_service_context'], $response['meta']['sp_main_1'], $response['meta_all']['sp_intro'] ) && 'keep SEO' === $response['meta']['seo_custom'], 'regular response exposes Blog-style metadata and generic content while pruning only obsolete legacy response fields' );
Document::register_rest();
check( in_array( 'view', $GLOBALS['test_rest_fields']['nova_service_content']['schema']['context'], true ) && ! isset( $GLOBALS['test_rest_fields']['nova_service_context'] ) && ! in_array( 'nova-service/v1/context', $GLOBALS['test_rest_routes'], true ), 'generic content is readable in ordinary GET and the separate context field and route are absent' );
$GLOBALS['test_schema_error'] = true;
$schema_document_error = Document::validate_document( $fresh_defaults );
check( is_wp_error( $schema_document_error ) && 400 === $schema_document_error->get_error_data()['status'] && 'nova_service_document' === $schema_document_error->get_error_data()['param'] && [ 'full', 'half' ] === $schema_document_error->get_error_data()['details']['allowed'], 'early document schema validation returns HTTP400 while preserving core error details' );
$schema_content_error = Document::validate_content( [ 'rich_text_1' => [ 'body' => 'Schema fixture.' ] ] );
check( is_wp_error( $schema_content_error ) && 400 === $schema_content_error->get_error_data()['status'] && 'nova_service_content' === $schema_content_error->get_error_data()['param'], 'early content schema validation returns HTTP400 rather than an unclassified server error' );
unset( $GLOBALS['test_schema_error'] );
$trust_document = doc( [ section( 'credentials', 'credentials', [ 'layout' => 'badges', 'items' => [ [ 'title' => 'Actual qualification', 'image' => 'https://example.test/issuer.svg', 'url' => 'https://example.test/evidence' ] ] ] ), section( 'reviews', 'reviews', [ 'items' => [ [ 'body' => '<p>Published customer text.</p>', 'source_logo' => '/wp-content/uploads/publisher.svg', 'source_url' => 'https://www.trustpilot.com/review/example.test' ], [ 'body' => '<p>Second publisher text.</p>', 'source_logo' => 'https://example.test/publisher.svg' ] ] ] ) ] );
check( true === Document::validate_document( $trust_document ) && '/wp-content/uploads/publisher.svg' === Document::sanitize_document( $trust_document )['sections'][1]['data']['items'][0]['source_logo'], 'optional publisher logo accepts root-relative or HTTPS assets without changing review or credential source facts' );
$trust_document['sections'][0]['data']['layout'] = 'logos';
check( 'logos' === Document::sanitize_document( $trust_document )['sections'][0]['data']['layout'] && in_array( 'badges', Document::document_schema()['properties']['sections']['items']['properties']['data']['properties']['layout']['enum'], true ), 'badges and logos presentation choices are advertised and preserved' );
$trust_document['sections'][1]['data']['source_logo'] = 'https://example.test/common-publisher.svg';
check( true === Document::validate_document( $trust_document ) && 'https://example.test/common-publisher.svg' === Document::sanitize_document( $trust_document )['sections'][1]['data']['source_logo'], 'shared review section stores its default publisher logo once independently of card overrides' );
$bad_section_logo = $trust_document; $bad_section_logo['sections'][1]['data']['source_logo'] = '//evil.test/publisher.svg';
check( is_wp_error( Document::validate_document( $bad_section_logo ) ) && '' === Document::sanitize_document( $bad_section_logo )['sections'][1]['data']['source_logo'], 'shared default logo applies the same HTTPS and root-relative URL boundary as individual cards' );
foreach ( [ 'javascript:alert(1)', '//evil.test/logo.svg', 'http://example.test/logo.svg', 'https://user:secret@example.test/logo.svg' ] as $unsafe_logo ) {
	$trust_document['sections'][1]['data']['items'][0]['source_logo'] = $unsafe_logo;
	check( is_wp_error( Document::validate_document( $trust_document ) ) && '' === Document::sanitize_document( $trust_document )['sections'][1]['data']['items'][0]['source_logo'], 'unsafe publisher logo URL is rejected on writes and cleared defensively on reads: ' . $unsafe_logo );
}
$issuer_document = doc( [ section( 'credentials', 'credentials', [ 'items' => [ [ 'title' => 'Qualification', 'issuer' => '<strong>ANBOS</strong>', 'source' => 'Lees toelichting', 'url' => 'https://example.test/evidence' ] ] ] ) ] );
$issuer_item = Document::sanitize_document( $issuer_document )['sections'][0]['data']['items'][0];
check( true === Document::validate_document( $issuer_document ) && 'ANBOS' === $issuer_item['issuer'] && 'Lees toelichting' === $issuer_item['source'], 'credential issuing organization sanitizes independently while the existing evidence-link label is retained' );
$review_layouts = [ 'editorial', 'conversation', 'spotlight', 'publisher_cards', 'compact_rail', 'signature' ];
$review_schema = Document::document_schema()['properties']['sections']['items']['properties']['data']['properties'];
foreach ( array_merge( [ 'default', 'split', 'stacked', 'compact', 'cards', 'list', 'badges', 'logos' ], $review_layouts ) as $layout ) {
	$review_layout_document = doc( [ section( 'reviews', 'reviews', [ 'layout' => $layout, 'items' => [ [ 'body' => '<p>Exact customer wording.</p>', 'author' => 'Customer', 'source' => 'Personal feedback' ] ] ] ) ] );
	$review_layout_saved = Document::sanitize_document( $review_layout_document );
	check( in_array( $layout, $review_schema['layout']['enum'], true ) && true === Document::validate_document( $review_layout_document ) && $layout === $review_layout_saved['sections'][0]['data']['layout'] && '<p>Exact customer wording.</p>' === $review_layout_saved['sections'][0]['data']['items'][0]['body'], 'new or existing review layout round-trips without changing manual review text or requiring source URLs: ' . $layout );
}
$manual_reviews = doc( [ section( 'reviews', 'reviews', [ 'items' => [ [ 'author' => 'Customer', 'body' => '<p>Manually supplied review.</p>', 'source' => 'Client feedback' ] ] ] ) ] );
check( true === Document::validate_document( $manual_reviews ) && ! array_key_exists( 'link_sources', Document::sanitize_document( $manual_reviews )['sections'][0]['data'] ) && ! isset( Document::sanitize_document( $manual_reviews )['sections'][0]['data']['review_source'] ), 'manual reviews accept no source/profile/item URLs and older documents retain an omitted source-link setting' );
foreach ( [ true, false ] as $link_sources ) {
	$manual_reviews['sections'][0]['data']['link_sources'] = $link_sources;
	check( true === Document::validate_document( $manual_reviews ) && $link_sources === Document::sanitize_document( $manual_reviews )['sections'][0]['data']['link_sources'], 'explicit source-link JSON boolean survives unchanged: ' . ( $link_sources ? 'true' : 'false' ) );
}
foreach ( [ 'false', 'true', 0, 1, null, [], (object) [] ] as $invalid_boolean ) {
	$manual_reviews['sections'][0]['data']['link_sources'] = $invalid_boolean;
	$invalid_link_error = Document::validate_document( $manual_reviews );
	check( is_wp_error( $invalid_link_error ) && 400 === $invalid_link_error->get_error_data()['status'] && is_wp_error( Document::validate_content( [ 'section_overrides' => [ 'reviews' => [ 'data' => [ 'link_sources' => $invalid_boolean ] ] ] ] ) ) && ! array_key_exists( 'link_sources', Document::sanitize_document( $manual_reviews )['sections'][0]['data'] ), 'non-boolean source-link values are rejected before writes and are not cast on reads: ' . get_debug_type( $invalid_boolean ) );
}
$review_preset = section( 'review_style', 'reviews', [ 'layout' => 'editorial', 'link_sources' => true, 'items' => [ [ 'body' => '<p>Shared review.</p>', 'source' => 'Client feedback' ] ] ] );
check( true === Shared::save_presets( doc( [ $review_preset ] ) ), 'client review defaults accept the layout and strict source-link boolean' );
$review_ref = section( 'reviews', 'reviews', (object) [] ); $review_ref['shared_key'] = 'review_style';
check( true === Document::save_document( 2, doc( [ $review_ref ] ) ) && true === Document::resolve_document( 2 )['sections'][0]['data']['link_sources'] && ! array_key_exists( 'link_sources', (array) Document::get_document( 2 )['sections'][0]['data'] ), 'omitted local source-link setting inherits the client choice without filling the raw document' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'reviews' => [ 'data' => [ 'link_sources' => false, 'layout' => 'signature' ] ] ] ] ) && false === Document::resolve_document( 2 )['sections'][0]['data']['link_sources'] && 'signature' === Document::resolve_document( 2 )['sections'][0]['data']['layout'], 'page-level false overrides a shared true setting and preserves the selected review style' );
$review_before_invalid = get_post_meta( 2, Document::META_DOCUMENT, true );
check( is_wp_error( Document::save_content( 2, [ 'section_overrides' => [ 'reviews' => [ 'data' => [ 'link_sources' => 'false', 'items' => [] ] ] ] ] ) ) && $review_before_invalid === get_post_meta( 2, Document::META_DOCUMENT, true ), 'invalid source-link type blocks the whole override without clearing reviews or altering the saved document' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'reviews' => [ 'inherit' => [ 'link_sources' ] ] ] ] ) && true === Document::resolve_document( 2 )['sections'][0]['data']['link_sources'] && ! array_key_exists( 'link_sources', (array) Document::get_document( 2 )['sections'][0]['data'] ), 'inherit removes only the local link setting so client defaults apply again' );
$review_response = Document::prepare_rest_response( new TestResponse( [] ), get_post( 2 ), new TestRequest( [ '_method' => 'GET' ] ) )->get_data();
$review_guidance = $review_response['meta_descriptions']['section_overrides'];
check( 'boolean' === $review_schema['link_sources']['type'] && ! isset( $review_schema['link_sources']['default'] ) && false !== strpos( $review_guidance, implode( ', ', array_slice( $review_layouts, 0, -1 ) ) . ' and signature' ) && false !== strpos( $review_guidance, 'without any item, source or profile URL' ) && false !== strpos( $review_guidance, 'default true' ) && false !== strpos( $review_guidance, 'retaining source branding' ) && ! isset( $review_response['nova_service_context'] ), 'ordinary GET descriptions advertise all six review styles, optional manual URLs and branding-preserving link visibility without another context endpoint' );
$credential_layouts = [ 'logo_rail', 'featured_membership', 'editorial', 'credential_tiles', 'trust_band', 'evidence_accordion' ];
$credential_item = [ 'title' => 'Original membership', 'issuer' => 'Actual issuing organization', 'body' => '<p>Original supporting text.</p>', 'image' => 'https://example.test/issuer.svg', 'url' => 'https://example.test/evidence', 'source' => 'View evidence' ];
foreach ( array_merge( [ 'default', 'compact', 'cards', 'list', 'badges', 'logos' ], $credential_layouts ) as $layout ) {
	$credential_document = doc( [ section( 'credentials', 'credentials', [ 'layout' => $layout, 'items' => [ $credential_item ] ] ) ] );
	check( in_array( $layout, $review_schema['layout']['enum'], true ) && true === Document::save_document( 2, $credential_document ) && $layout === Document::get_document( 2 )['sections'][0]['data']['layout'] && $credential_item == Document::get_document( 2 )['sections'][0]['data']['items'][0] && ! array_key_exists( 'link_sources', Document::get_document( 2 )['sections'][0]['data'] ), 'new or existing credential style saves without altering evidence, issuer or content, and leaves omitted link visibility absent: ' . $layout );
}
$credential_preset = section( 'credential_style', 'credentials', [ 'layout' => 'featured_membership', 'link_sources' => false, 'items' => [ $credential_item ] ] );
check( true === Shared::save_presets( doc( [ $review_preset, $credential_preset ] ) ), 'review and credential client defaults store independent source-link choices and presentation styles' );
$credential_ref = section( 'credentials', 'credentials', (object) [] ); $credential_ref['shared_key'] = 'credential_style';
check( true === Document::save_document( 2, doc( [ $review_ref, $credential_ref ] ) ) && true === Document::resolve_document( 2 )['sections'][0]['data']['link_sources'] && false === Document::resolve_document( 2 )['sections'][1]['data']['link_sources'] && ! array_key_exists( 'link_sources', (array) Document::get_document( 2 )['sections'][1]['data'] ), 'linked credentials inherit false independently of a review default true without filling local settings' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'credentials' => [ 'data' => [ 'layout' => 'trust_band', 'link_sources' => true ] ] ] ] ) && true === Document::resolve_document( 2 )['sections'][1]['data']['link_sources'] && 'trust_band' === Document::resolve_document( 2 )['sections'][1]['data']['layout'] && $credential_item == Document::resolve_document( 2 )['sections'][1]['data']['items'][0], 'credential local true and style patch preserves inherited issuer and evidence' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'reviews' => [ 'data' => [ 'link_sources' => false ] ] ] ] ) && false === Document::resolve_document( 2 )['sections'][0]['data']['link_sources'] && true === Document::resolve_document( 2 )['sections'][1]['data']['link_sources'], 'changing review link visibility does not change a credential local choice' );
check( true === Document::save_content( 2, [ 'section_overrides' => [ 'credentials' => [ 'inherit' => [ 'link_sources', 'layout' ] ] ] ] ) && false === Document::resolve_document( 2 )['sections'][1]['data']['link_sources'] && 'featured_membership' === Document::resolve_document( 2 )['sections'][1]['data']['layout'] && ! array_key_exists( 'link_sources', (array) Document::get_document( 2 )['sections'][1]['data'] ), 'credential inherit removes local booleans/styles while restoring client defaults and retaining original content' );
$credential_before_invalid = get_post_meta( 2, Document::META_DOCUMENT, true );
check( is_wp_error( Document::save_content( 2, [ 'section_overrides' => [ 'credentials' => [ 'data' => [ 'link_sources' => 'false', 'items' => [] ] ] ] ] ) ) && $credential_before_invalid === get_post_meta( 2, Document::META_DOCUMENT, true ), 'invalid credential source-link boolean rejects atomically instead of clearing certificate data' );
$credential_response = Document::prepare_rest_response( new TestResponse( [] ), get_post( 2 ), new TestRequest( [ '_method' => 'GET' ] ) )->get_data();
$credential_guidance = $credential_response['meta_descriptions']['section_overrides'];
check( false !== strpos( $credential_guidance, implode( ', ', array_slice( $credential_layouts, 0, -1 ) ) . ' and evidence_accordion' ) && false !== strpos( $credential_guidance, 'Both presentation controls are optional' ) && false !== strpos( $credential_guidance, 'credential evidence image/link anchors while retaining credential content' ) && false !== strpos( $review_schema['link_sources']['description'], 'credentials; default true' ) && ! isset( $credential_response['nova_service_context'] ), 'ordinary GET and schema explain six optional credential styles and branding-preserving evidence-link visibility without a context endpoint' );
$GLOBALS['test_options'] = $saved_options;
echo 'PASS: ' . $checks . ' service document checks.' . PHP_EOL;
