<?php
/** Standalone fresh/upgrade setup lifecycle checks; no WordPress or network writes. */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
// Reuse the model's PHP-only WordPress stubs and its compatibility regression.
require __DIR__ . '/service-document-unit.php';

function add_option( $key, $value, $deprecated = '', $autoload = false ) { if ( array_key_exists( $key, $GLOBALS['test_options'] ) ) { return false; } $GLOBALS['test_options'][ $key ] = $value; return true; }
function delete_option( $key ) { unset( $GLOBALS['test_options'][ $key ] ); return true; }
function get_posts( $args ) { return array_keys( array_filter( $GLOBALS['test_posts'], static function ( $post ) { return 'service_page' === $post->post_type; } ) ); }
function get_post_stati() { return [ 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft' ]; }
function get_bloginfo( $key ) { return 'Site business'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . $path; }
function wp_generate_uuid4() { return 'token-' . ++$GLOBALS['test_uuid']; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['test_hooks'][ $hook ][] = [ $callback, $priority, $args ]; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { add_action( $hook, $callback, $priority, $args ); }
function wp_cache_delete( $key, $group ) { $GLOBALS['test_refreshed_options'][] = $key; return true; }
class OnboardingRequest extends TestRequest { public function get_header( $key ) { return 'X-WP-Nonce' === $key ? $this->get_param( '_nonce' ) : ''; } }
class OnboardingDatabase {
	public $options = 'test_options';
	public $posts = 'test_posts';
	public function esc_like( $value ) { return $value; }
	public function prepare( $sql, $value ) { return $sql; }
	public function get_col( $sql ) { return array_values( array_filter( array_keys( $GLOBALS['test_options'] ), static function ( $key ) { return str_starts_with( $key, 'service_cpt_' ); } ) ); }
	public function get_var( $sql ) { $GLOBALS['test_service_existence_sql'] = $sql; foreach ( $GLOBALS['test_posts'] as $post ) { if ( 'service_page' === $post->post_type ) { return $post->ID; } } return null; }
	public function delete( $table, $where, $formats ) {
		$key = $where['option_name'];
		if ( ! empty( $GLOBALS['test_compare_delete_race'] ) ) { $GLOBALS['test_options'][ $key ] = [ 'token' => 'new-rival-token', 'expires' => time() + 60 ]; unset( $GLOBALS['test_compare_delete_race'] ); }
		if ( ! array_key_exists( $key, $GLOBALS['test_options'] ) || serialize( $GLOBALS['test_options'][ $key ] ) !== $where['option_value'] ) { return 0; }
		unset( $GLOBALS['test_options'][ $key ] ); return 1;
	}
}
require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-onboarding.php';
use SEORAI\ServicePageCPT\ServiceOnboarding as Onboarding;
use SEORAI\ServicePageCPT\ServiceDocument as Document;
use SEORAI\ServicePageCPT\ServiceShared as Shared;

$checks = 0; $GLOBALS['test_uuid'] = 0; $GLOBALS['wpdb'] = new OnboardingDatabase();
$GLOBALS['test_posts'] = []; $GLOBALS['test_meta'] = []; $GLOBALS['test_options'] = []; $GLOBALS['test_edit'] = true;
Onboarding::bootstrap();
$state = Onboarding::state();
check( true === $state['required'] && false === $state['complete'] && 'fresh' === $state['classification'] && 0 === $state['revision'], 'genuinely fresh module requires setup and records a per-site lifecycle marker' );
check( isset( $GLOBALS['test_options'][ Onboarding::OPTION_STATE ] ) && ! isset( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ], $GLOBALS['test_options'][ Shared::OPTION_TEMPLATE ], $GLOBALS['test_options'][ Onboarding::OPTION_CONFIGURATION ] ), 'initialization does not install client presets, layouts or active identity' );
check( true === $state['editable'] && 64 === strlen( $state['fingerprint'] ), 'setup exposes an editable state and optimistic settings snapshot' );
check( [ 'business', 'cta', 'content', 'style', 'review' ] === $state['steps'] && 'inherit_site' === $state['brand']['mode'] && ! str_contains( json_encode( $state['draft']['presets'] ), 'rating' ), 'setup starts with site branding inheritance and empty optional proof instead of fabricated reviews' );
check( ! $GLOBALS['test_posts'] && ! $GLOBALS['test_meta'], 'setup initialization creates no preview posts or service content' );
check( str_contains( Onboarding::config()['setup_url'], 'page=nova-service-shared' ) && str_contains( Onboarding::config()['setup_url'], 'tab=setup' ) && 'nonce-wp_rest' === Onboarding::config()['nonce'], 'UI configuration targets the unified settings setup tab and standard REST nonce' );
$before = $GLOBALS['test_options']; $GLOBALS['test_edit'] = false;
check( is_wp_error( Onboarding::permission() ) && 403 === Onboarding::permission()->get_error_data()['status'] && is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save', 'expected_revision' => 0 ] ) ) ) && $before === $GLOBALS['test_options'], 'non-administrators cannot read via the endpoint or change site setup progress' );
$GLOBALS['test_edit'] = true;
check( is_wp_error( Onboarding::permission( new OnboardingRequest( [ '_nonce' => 'expired' ] ) ) ) && true === Onboarding::permission( new OnboardingRequest( [ '_nonce' => 'nonce-wp_rest' ] ) ), 'invalid supplied cookie nonce is rejected while a valid REST nonce is accepted' );
$progress = Onboarding::save( new OnboardingRequest( [ 'action' => 'save', 'expected_revision' => 0, 'step' => 'cta', 'draft' => [ 'business' => [ 'name' => '<strong>Actual company</strong>' ], 'ui' => [ 'cta_type' => 'booking', 'optional_panels' => [] ] ] ] ) );
check( ! is_wp_error( $progress ) && 1 === $progress['revision'] && 'cta' === $progress['step'] && 'Actual company' === $progress['draft']['business']['name'], 'per-site step and sanitized business identity persist for resuming setup' );
check( ! isset( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ], $GLOBALS['test_options'][ Shared::OPTION_TEMPLATE ], $GLOBALS['test_options'][ Onboarding::OPTION_CONFIGURATION ] ) && 'Site business' === get_bloginfo( 'name' ), 'draft progress never changes active client defaults or the WordPress site identity' );
$before = $GLOBALS['test_options'];
$stale = Onboarding::save( new OnboardingRequest( [ 'action' => 'save', 'expected_revision' => 0, 'step' => 'review', 'draft' => [ 'business' => [ 'name' => 'Stale overwrite' ] ] ] ) );
check( is_wp_error( $stale ) && 409 === $stale->get_error_data()['status'] && $before === $GLOBALS['test_options'], 'a second administrator with a stale revision cannot overwrite saved progress' );
$missing_cta = Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1 ] ) );
check( is_wp_error( $missing_cta ) && 400 === $missing_cta->get_error_data()['status'] && ! Onboarding::active_settings() && $before === $GLOBALS['test_options'], 'finish requires a real primary action and does not activate an incomplete draft' );
$missing_name = Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => [ 'business' => [ 'name' => '' ] ] ] ) );
check( is_wp_error( $missing_name ) && str_contains( $missing_name->get_error_message(), 'business name' ) && $before === $GLOBALS['test_options'], 'business name is essential even when optional trust and media are skipped' );
foreach ( [ [ 'business' => null ], [ 'ui' => null ], [ 'business' => [ 'website' => 'javascript:alert(1)' ] ], [ 'ui' => [ 'google_api_key' => 'Do not store keys' ] ] ] as $bad_draft ) {
	check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save', 'expected_revision' => 1, 'draft' => $bad_draft ] ) ) ) && $before === $GLOBALS['test_options'], 'malformed identity/display values or connection fields do not persist or alter progress' );
}
check( is_wp_error( Shared::save_settings( $state['draft']['presets'], $state['draft']['template'] ) ) && ! isset( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ] ), 'ordinary client-default settings cannot bypass pending fresh setup' );
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => 1, 'expected_fingerprint' => Onboarding::state()['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Bypass finish' ] ] ] ) ) ) && $before === $GLOBALS['test_options'], 'business-only settings cannot bypass required fresh setup' );
$rest_request = new OnboardingRequest( [ '_route' => '/wp/v2/services', 'status' => 'publish', 'nova_service_content' => [ 'rich_text_1' => [ 'body' => 'Do not post yet.' ] ] ] );
$blocked = Document::normalize_rest_request( null, null, $rest_request );
check( is_wp_error( $blocked ) && 409 === $blocked->get_error_data()['status'] && str_contains( $blocked->get_error_data()['setup_url'], 'tab=setup' ), 'fresh routine native REST posting fails before mutation with an actionable setup URL' );
check( is_wp_error( Document::validate_rest_insert( new stdClass(), $rest_request ) ) && ! $GLOBALS['test_posts'], 'the core REST insertion guard also blocks direct native create requests' );
check( 'draft' === Onboarding::guard_publish( [ 'post_type' => 'service_page', 'post_status' => 'publish' ] )['post_status'] && 'draft' === Onboarding::guard_publish( [ 'post_type' => 'service_page', 'post_status' => 'future' ] )['post_status'] && 'publish' === Onboarding::guard_publish( [ 'post_type' => 'post', 'post_status' => 'publish' ] )['post_status'], 'fresh service publish/scheduling becomes a saved draft without affecting other post types' );
$GLOBALS['test_posts'][2] = (object) [ 'ID' => 2, 'post_type' => 'service_page', 'post_status' => 'draft', 'post_title' => 'Setup draft', 'post_content' => '' ];
Document::reset_rest_flags(); Document::initialize_new_post( 2, get_post( 2 ), false );
check( Onboarding::is_required() && Document::is_v2( 2 ) && true === Document::save_document( 2, doc( [ section( 'hero', 'hero', [ 'body' => 'Draft only.' ] ) ] ) ), 'manual native setup drafts are editable without changing the fresh lifecycle classification' );
$draft_before = get_post_meta( 2, Document::META_DOCUMENT, true );
check( is_wp_error( Document::save_content( 2, [ 'rich_text_1' => [ 'body' => 'Blocked routine copy.' ] ] ) ) && $draft_before === get_post_meta( 2, Document::META_DOCUMENT, true ), 'direct routine content API is blocked while native draft editing remains available' );
check( is_wp_error( Document::apply_migration( new OnboardingRequest( [ 'id' => 2, 'action' => 'v2' ] ) ) ), 'fresh setup cannot be bypassed with native migration activation' );
$GLOBALS['test_posts'][2]->post_status = 'publish';
check( is_wp_error( Document::save_document( 2, doc( [] ) ) ) && $draft_before === get_post_meta( 2, Document::META_DOCUMENT, true ), 'direct published native writes are rejected while fresh setup is pending' );
$GLOBALS['test_posts'][2]->post_status = 'draft';
$finish_draft = Onboarding::state()['draft'];
foreach ( $finish_draft['presets']['sections'] as &$preset ) { if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'] = [ [ 'label' => 'Book an appointment', 'url' => '#nova-service-booking' ] ]; } }
unset( $preset );
$invalid_anchor = Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $finish_draft ] ) );
check( is_wp_error( $invalid_anchor ) && ! Onboarding::active_settings(), 'a decorative or missing booking anchor does not qualify as a working primary CTA' );
$secondary_fallback = $finish_draft;
foreach ( $secondary_fallback['presets']['sections'] as &$preset ) { if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'][] = [ 'label' => 'Secondary action', 'url' => '/contact/' ]; } }
unset( $preset );
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $secondary_fallback ] ) ) ) && ! Onboarding::active_settings(), 'a valid secondary button cannot conceal a broken visible primary booking target' );
foreach ( $finish_draft['presets']['sections'] as &$preset ) { if ( 'default_custom_cta' === $preset['id'] ) { $preset['data']['shortcode'] = '[contact-form-7 id="10"]'; } }
unset( $preset );
foreach ( $finish_draft['template']['sections'] as &$section ) { if ( 'custom_cta' === $section['type'] ) { $section['id'] = 'booking'; } }
unset( $section );
$raw_anchor_draft = $finish_draft;
foreach ( $raw_anchor_draft['presets']['sections'] as &$preset ) { if ( 'default_hero' === $preset['id'] ) { $preset['data']['buttons'][0]['url'] = '#booking'; } }
unset( $preset );
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $raw_anchor_draft ] ) ) ) && ! Onboarding::active_settings(), 'raw document IDs do not qualify as rendered anchors even when an enabled real form exists' );
$invalid_template = $finish_draft; $invalid_template['template']['sections'][0]['shared_key'] = 'default_people';
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $invalid_template ] ) ) ) && ! Onboarding::active_settings(), 'invalid shared layout references reject the whole final configuration' );
$concurrent_defaults = doc( [ section( 'external', 'cta', [ 'title' => 'Saved elsewhere' ] ) ] );
$GLOBALS['test_options'][ Shared::OPTION_PRESETS ] = $concurrent_defaults;
$conflict = Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $finish_draft ] ) );
check( is_wp_error( $conflict ) && 409 === $conflict->get_error_data()['status'] && $concurrent_defaults === get_option( Shared::OPTION_PRESETS ) && ! Onboarding::active_settings(), 'concurrent active-default changes are preserved instead of overwritten at finish' );
delete_option( Shared::OPTION_PRESETS );
$GLOBALS['test_options']['nova_service_onboarding_lock'] = [ 'token' => 'another-admin', 'expires' => time() + 60 ];
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'draft' => $finish_draft ] ) ) ) && ! Onboarding::active_settings(), 'another active setup save holds an atomic per-site lock' );
delete_option( 'nova_service_onboarding_lock' );
$GLOBALS['test_options']['nova_service_onboarding_lock'] = [ 'token' => 'expired-reader', 'expires' => time() - 1 ]; $GLOBALS['test_compare_delete_race'] = true;
$racing_before_state = get_option( Onboarding::OPTION_STATE );
$racing_save = Onboarding::save( new OnboardingRequest( [ 'action' => 'save', 'expected_revision' => 1 ] ) );
check( is_wp_error( $racing_save ) && 409 === $racing_save->get_error_data()['status'] && 'new-rival-token' === get_option( 'nova_service_onboarding_lock' )['token'] && $racing_before_state === get_option( Onboarding::OPTION_STATE ), 'an expired-lock stale reader cannot delete a newly acquired rival token or race a progress save' );
delete_option( 'nova_service_onboarding_lock' );
$GLOBALS['test_options']['nova_service_onboarding_lock'] = [ 'token' => 'actually-expired', 'expires' => time() - 1 ];
$content_before_finish = $GLOBALS['test_meta']; $posts_before_finish = serialize( $GLOBALS['test_posts'] );
$finished = Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 1, 'step' => 'review', 'draft' => $finish_draft ] ) );
check( ! is_wp_error( $finished ) && true === $finished['complete'] && false === $finished['required'] && 2 === $finished['revision'], 'confirming essential identity and a real form target completes fresh setup' );
check( ! isset( $GLOBALS['test_options']['nova_service_onboarding_lock'] ), 'an unchanged expired lock is recovered and only the owned replacement token is released' );
check( in_array( Onboarding::OPTION_STATE, $GLOBALS['test_refreshed_options'], true ) && in_array( Onboarding::OPTION_CONFIGURATION, $GLOBALS['test_refreshed_options'], true ), 'locked writes refresh request-cached options before checking revisions and active configuration' );
check( isset( $GLOBALS['test_options'][ Onboarding::OPTION_CONFIGURATION ] ) && ! isset( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ], $GLOBALS['test_options'][ Shared::OPTION_TEMPLATE ] ) && 'Actual company' === Onboarding::active_settings()['business']['name'], 'fresh identity, library and layout activate through one canonical option without duplicate raw active settings' );
check( $content_before_finish === $GLOBALS['test_meta'] && $posts_before_finish === serialize( $GLOBALS['test_posts'] ) && 'inherit_site' === Onboarding::active_settings()['brand']['mode'], 'finishing setup preserves existing draft content and site typography/color options' );
check( in_array( 'booking', array_column( Shared::get_template()['sections'], 'id' ), true ), 'Shared getters read the activated layout from the configuration bundle' );
check( true === Onboarding::guard_rest_request( $rest_request ) && 'publish' === Onboarding::guard_publish( [ 'post_type' => 'service_page', 'post_status' => 'publish' ] )['post_status'], 'confirmed fresh setup unlocks normal native posting and publishing' );
$active_before_repeat = Onboarding::active_settings();
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 2, 'draft' => [ 'business' => [ 'name' => 'Repeated finish' ] ] ] ) ) ) && $active_before_repeat === Onboarding::active_settings(), 'a repeated finish cannot overwrite a completed client configuration' );
$identity_state = Onboarding::state(); $fresh_options = $GLOBALS['test_options'];
$identity_saved = Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_state['revision'], 'expected_fingerprint' => $identity_state['fingerprint'], 'draft' => [ 'business' => [ 'phone' => '+31 40 1234567' ] ] ] ) );
$fresh_expected = $fresh_options; $fresh_expected[ Onboarding::OPTION_CONFIGURATION ]['business']['phone'] = '+31 40 1234567'; $fresh_expected[ Onboarding::OPTION_CONFIGURATION ]['onboarding_revision']++;
check( ! is_wp_error( $identity_saved ) && $fresh_expected === $GLOBALS['test_options'] && $identity_saved['complete'] && $identity_saved['editable'], 'completed fresh setup can edit identity only, retaining exact shared library, template and saved draft' );
$identity_before_noop = $GLOBALS['test_options'];
check( ! is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'expected_fingerprint' => $identity_saved['fingerprint'], 'draft' => [ 'business' => [ 'phone' => '+31 40 1234567' ] ] ] ) ) ) && $identity_before_noop === $GLOBALS['test_options'], 'unchanged business settings do not rewrite configuration or advance revisions' );
$updated_presets = Shared::get_presets(); $updated_presets['sections'][0]['data']['buttons'][0]['label'] = 'Updated booking';
check( true === Shared::save_presets( $updated_presets ) && 'Updated booking' === Shared::get_presets()['sections'][0]['data']['buttons'][0]['label'] && 'Actual company' === Onboarding::active_settings()['business']['name'] && ! isset( $GLOBALS['test_options'][ Shared::OPTION_PRESETS ] ), 'subsequent shared edits update the same bundle and retain business identity without falling back to raw options' );
$shared_updated_options = $GLOBALS['test_options'];
check( Onboarding::state()['revision'] === $identity_saved['revision'] + 1 && is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'expected_fingerprint' => $identity_saved['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Stale business form' ] ] ] ) ) ) && $shared_updated_options === $GLOBALS['test_options'], 'a shared editor update advances the bundle revision and rejects stale identity forms' );
$before_same_settings = $GLOBALS['test_options'];
check( true === Onboarding::save_active_settings( Shared::get_presets(), Shared::get_template() ) && $before_same_settings === $GLOBALS['test_options'], 'saving equivalent canonical documents does not advance active setup revisions for empty object data' );
$active_before_forbidden = Onboarding::active_settings(); $GLOBALS['test_edit'] = false;
check( is_wp_error( Onboarding::save_active_settings( doc( [] ), [] ) ) && $active_before_forbidden === Onboarding::active_settings(), 'direct active settings helper also requires an administrator and preserves the bundle on denial' );
$GLOBALS['test_edit'] = true;

// Existing customer installations are classified once and never forced through setup.
$GLOBALS['test_options'] = []; $GLOBALS['test_posts'] = [ 1 => (object) [ 'ID' => 1, 'post_type' => 'service_page', 'post_status' => 'trash', 'post_title' => 'Old client service' ] ];
$GLOBALS['test_meta'] = [ 1 => [ Document::META_MODE => 'legacy', 'sp_intro' => 'Do not migrate.' ] ];
$legacy_before = $GLOBALS['test_meta']; Onboarding::initialize();
check( ! Onboarding::is_required() && 'upgrade' === Onboarding::state()['classification'] && $legacy_before === $GLOBALS['test_meta'], 'even a trashed existing service identifies an upgrade without migrating or locking old clients' );
check( [] === Onboarding::state()['draft']['template'] && ! str_contains( json_encode( Onboarding::state()['draft']['presets'] ), 'Do not migrate' ) && Onboarding::state()['complete'] && Onboarding::state()['editable'], 'existing setup reflects the real empty template and editable settings without reading or migrating old page content' );
check( true === Onboarding::guard_rest_request( $rest_request ) && 'publish' === Onboarding::guard_publish( [ 'post_type' => 'service_page', 'post_status' => 'publish' ] )['post_status'], 'upgrade clients keep existing publication and posting behavior' );
$upgrade_options = $GLOBALS['test_options'];
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'finish', 'expected_revision' => 0, 'draft' => $finish_draft ] ) ) ) && $upgrade_options === $GLOBALS['test_options'], 'existing settings do not invoke the fresh-install activation path' );

// Upgrade identity is global and never creates a competing copy of active defaults.
$preserved_presets = doc( [ section( 'default_reviews', 'reviews', [ 'title' => 'Actual client reviews', 'layout' => 'compact_rail', 'items' => [ [ 'author' => 'Actual customer', 'body' => 'Preserve the quote.', 'source' => 'Google', 'image' => 'https://example.test/customer.jpg' ] ] ] ), section( 'vital_example', 'people', [ 'body' => 'Client-specific variant', 'images' => [ [ 'id' => 99, 'url' => 'https://example.test/team.jpg', 'alt' => 'Actual team' ] ] ] ) ] );
$preserved_template = Document::sanitize_document( doc( [ array_merge( section( 'rich_text_1', 'rich_text', [ 'title' => 'Content first', 'body' => 'Existing layout' ] ), [ 'content_slot' => 'rich_text_1' ] ), array_merge( section( 'proof', 'reviews', [] ), [ 'shared_key' => 'default_reviews' ] ) ] ) );
$GLOBALS['test_options'][ Shared::OPTION_PRESETS ] = $preserved_presets; $GLOBALS['test_options'][ Shared::OPTION_TEMPLATE ] = $preserved_template;
$GLOBALS['test_options'][ Onboarding::OPTION_STATE ]['draft'] = [ 'business' => [ 'name' => 'Existing setup business', 'website' => 'https://actual-company.test/', 'phone' => '+31 40 1111111' ], 'presets' => doc( [] ), 'template' => [] ];
$identity_state = Onboarding::state(); $upgrade_before_identity = $GLOBALS['test_options']; $upgrade_posts = serialize( $GLOBALS['test_posts'] ); $upgrade_meta = $GLOBALS['test_meta'];
check( 'Actual client reviews' === $identity_state['draft']['presets']['sections'][0]['data']['title'] && serialize( $preserved_template ) === serialize( $identity_state['draft']['template'] ) && [ 'name' => 'Existing setup business', 'website' => 'https://actual-company.test/', 'phone' => '+31 40 1111111' ] === $identity_state['draft']['business'] && $upgrade_before_identity === $GLOBALS['test_options'], 'existing setup retains previously configured draft identity without adopting obsolete draft proof or layout and without option writes' );
$GLOBALS['test_options'][ Onboarding::OPTION_STATE ]['draft']['business']['website'] = 'javascript:alert(1)';
check( 'Site business' === Onboarding::state()['draft']['business']['name'] && 'https://example.test/' === Onboarding::state()['draft']['business']['website'], 'invalid prior draft identity falls back to site identity without importing invalid values' );
$GLOBALS['test_options'] = $upgrade_before_identity;
$identity_saved = Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_state['revision'], 'expected_fingerprint' => $identity_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => '<b>Updated company</b>', 'website' => 'https://actual-company.test/', 'phone' => '+31 40 1234567' ] ] ] ) );
$upgrade_expected = $upgrade_before_identity; $upgrade_expected[ Onboarding::OPTION_STATE ]['business'] = [ 'name' => 'Updated company', 'website' => 'https://actual-company.test/', 'phone' => '+31 40 1234567' ]; $upgrade_expected[ Onboarding::OPTION_STATE ]['revision']++;
check( ! is_wp_error( $identity_saved ) && $upgrade_expected === $GLOBALS['test_options'] && ! Onboarding::active_settings() && $upgrade_posts === serialize( $GLOBALS['test_posts'] ) && $upgrade_meta === $GLOBALS['test_meta'], 'explicit business save updates only upgrade identity and revision, retaining exact raw presets, arbitrary layout, draft and legacy posts' );
$upgrade_noop = $GLOBALS['test_options'];
check( ! is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'expected_fingerprint' => $identity_saved['fingerprint'], 'draft' => [ 'business' => [] ] ] ) ) ) && $upgrade_noop === $GLOBALS['test_options'], 'omitted upgrade identity values are preserved without a no-op options write' );
foreach ( [ [ 'business' => null ], [ 'business' => [ 'website' => 'https://user:secret@example.test/' ] ], [ 'business' => [ 'name' => 'Overwrite' ], 'presets' => doc( [] ) ], [ 'business' => [ 'name' => 'Overwrite' ], 'template' => [] ], [ 'business' => [ 'unknown' => 'value' ] ] ] as $bad_identity ) {
	check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'expected_fingerprint' => $identity_saved['fingerprint'], 'draft' => $bad_identity ] ) ) ) && $upgrade_noop === $GLOBALS['test_options'], 'business-only saves reject invalid identity and attempts to overwrite other settings' );
}
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'draft' => [ 'business' => [ 'name' => 'No snapshot' ] ] ] ) ) ) && $upgrade_noop === $GLOBALS['test_options'], 'an upgrade identity save must supply the current settings fingerprint' );
$GLOBALS['test_options']['nova_service_shared_translations'] = [ 'nl' => doc( [ section( 'default_reviews', 'reviews', [ 'title' => 'Vertaalde beoordelingen' ] ) ] ) ];
$translated_before = $GLOBALS['test_options'];
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_saved['revision'], 'expected_fingerprint' => $identity_saved['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Stale translation snapshot' ] ] ] ) ) ) && $translated_before === $GLOBALS['test_options'], 'translated defaults changed in another editor reject a stale global identity save' );
$identity_state = Onboarding::state(); $GLOBALS['test_options'][ Shared::OPTION_PRESETS ]['sections'][0]['data']['title'] = 'Another administrator'; $concurrent_before = $GLOBALS['test_options'];
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'expected_revision' => $identity_state['revision'], 'expected_fingerprint' => $identity_state['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Stale library snapshot' ] ] ] ) ) ) && $concurrent_before === $GLOBALS['test_options'], 'raw upgrade defaults changed in another editor also reject a stale identity save without rewriting them' );
$GLOBALS['test_posts'] = []; $GLOBALS['test_options'] = [ Shared::OPTION_PRESETS => doc( [] ) ]; Onboarding::initialize();
check( ! Onboarding::is_required() && 'existing_configuration' === Onboarding::state()['reason'], 'an explicitly saved native library is an upgrade even when empty and no services exist' );
check( true === Shared::save_settings( doc( [] ), [] ) && isset( $GLOBALS['test_options'][ Shared::OPTION_TEMPLATE ] ) && ! Onboarding::active_settings(), 'upgrade shared settings retain their original raw option path' );
$GLOBALS['test_options'] = []; $GLOBALS['test_posts'] = [ 4 => (object) [ 'ID' => 4, 'post_type' => 'service_page', 'post_status' => 'unregistered_old_workflow' ] ]; Onboarding::initialize();
check( ! Onboarding::is_required() && 'existing_services' === Onboarding::state()['reason'] && ! str_contains( $GLOBALS['test_service_existence_sql'], 'post_status' ), 'a custom or orphaned service status is detected before status registration and remains an upgrade' );
$GLOBALS['test_posts'] = [];
$GLOBALS['test_options'] = [ 'service_cpt_global_hero_primary_url' => '/actual-client-contact/' ]; Onboarding::initialize();
check( ! Onboarding::is_required(), 'meaningful legacy CTA configuration without service posts also bypasses required onboarding' );
$GLOBALS['test_options'] = []; foreach ( Onboarding::factory_settings() as $key => $value ) { $GLOBALS['test_options'][ $key ] = is_array( $value ) ? $value[0] : $value; } Onboarding::initialize();
check( Onboarding::is_required(), 'exact automatically seeded activation defaults alone still classify as a fresh install' );
$GLOBALS['test_options'] = [ 'service_cpt_color_accent' => '#123456' ]; Onboarding::initialize();
check( ! Onboarding::is_required() && '#123456' === get_option( 'service_cpt_color_accent' ), 'customer color choices classify as an upgrade and remain intact' );
check( ! isset( $GLOBALS['test_hooks']['template_redirect'] ) && PHP_INT_MAX === $GLOBALS['test_hooks']['wp_insert_post_data'][0][1], 'required setup installs no public frontend redirect and checks publishing after ordinary post filters' );
// Selected-language proof stays in its own library; business identity is global.
class Nova_Bridge_Suite_WPML_Support { public static function get_settings_language_context() { return $GLOBALS['test_language_context'] ?? null; } }
function pll_default_language( $field = 'slug' ) { return $GLOBALS['test_default_language'] ?? ''; }
require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-languages.php';
$GLOBALS['test_language_context'] = [ 'provider' => 'polylang', 'current' => 'nl', 'languages' => [ [ 'code' => 'en', 'label' => 'English' ], [ 'code' => 'nl', 'label' => 'Nederlands' ] ] ];
$GLOBALS['test_default_language'] = 'en';
$GLOBALS['test_options'] = [ Shared::OPTION_PRESETS => $preserved_presets, Shared::OPTION_TEMPLATE => $preserved_template, 'nova_service_shared_translations' => [ 'nl' => doc( [ section( 'default_reviews', 'reviews', [ 'title' => 'Klanten over ons' ] ) ] ) ] ];
Onboarding::initialize(); $localized_identity = Onboarding::state( 'nl' ); $localized_before = $GLOBALS['test_options'];
check( 'nl' === $localized_identity['language'] && 'Klanten over ons' === $localized_identity['draft']['presets']['sections'][0]['data']['title'] && serialize( $preserved_template ) === serialize( $localized_identity['draft']['template'] ), 'existing setup reads selected-language proof and the same global default layout' );
$localized_saved = Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'language' => 'nl', 'expected_revision' => $localized_identity['revision'], 'expected_fingerprint' => $localized_identity['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Multilingual company' ] ] ] ) );
$localized_expected = $localized_before; $localized_expected[ Onboarding::OPTION_STATE ]['business'] = [ 'name' => 'Multilingual company', 'website' => 'https://example.test/', 'phone' => '' ]; $localized_expected[ Onboarding::OPTION_STATE ]['revision']++;
check( ! is_wp_error( $localized_saved ) && $localized_expected === $GLOBALS['test_options'] && 'nl' === $localized_saved['language'] && 'Multilingual company' === Onboarding::state( 'en' )['draft']['business']['name'], 'localized business save changes only global identity and preserves exact base and translated client defaults' );
$language_before_invalid = $GLOBALS['test_options'];
check( is_wp_error( Onboarding::save( new OnboardingRequest( [ 'action' => 'save_business', 'language' => 'unknown', 'expected_revision' => $localized_saved['revision'], 'expected_fingerprint' => $localized_saved['fingerprint'], 'draft' => [ 'business' => [ 'name' => 'Wrong language' ] ] ] ) ) ) && $language_before_invalid === $GLOBALS['test_options'], 'unsupported language selection cannot fall through to a global identity write' );
check( 'nl' === Onboarding::config( 'nl' )['state']['language'] && 'Klanten over ons' === Onboarding::config( 'nl' )['state']['draft']['presets']['sections'][0]['data']['title'], 'setup localization config uses the explicit language chosen in settings' );
echo 'PASS: ' . $checks . ' service onboarding checks.' . PHP_EOL;
