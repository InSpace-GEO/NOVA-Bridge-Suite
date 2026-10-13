<?php
/** Native defaults language regression using the real suite provider adapter. No network/database. */
namespace {
	if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['language_test'] = [ 'provider' => 'wpml', 'default' => 'nl', 'current' => 'nl', 'languages' => [ 'nl', 'en', 'fr' ], 'posts' => [ 101 => 'en', 102 => 'nl' ], 'options' => [], 'meta' => [], 'writes' => 0, 'allowed' => true, 'active' => null ];
	class WP_Error { public $code; public $message; public $data; public function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } }
	function get_option( $key, $default = false ) { return $GLOBALS['language_test']['options'][ $key ] ?? $default; }
	function update_option( $key, $value, $autoload = false ) { $GLOBALS['language_test']['writes']++; $GLOBALS['language_test']['options'][ $key ] = $value; return true; }
	function current_user_can( $capability, $id = 0 ) { return $GLOBALS['language_test']['allowed']; }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function has_filter( $name ) { return 'wpml' === $GLOBALS['language_test']['provider'] && 'wpml_object_id' === $name; }
	function apply_filters( $name, $value, ...$args ) {
		$test = $GLOBALS['language_test'];
		if ( 'wpml' !== $test['provider'] ) { return $value; }
		if ( 'wpml_default_language' === $name ) { return $test['default']; }
		if ( 'wpml_current_language' === $name ) { return $test['current']; }
		if ( 'wpml_active_languages' === $name ) { $languages = []; foreach ( $test['languages'] as $code ) { $languages[ $code ] = [ 'language_code' => $code, 'native_name' => strtoupper( $code ) ]; } return $languages; }
		if ( 'wpml_post_language_details' === $name ) { return isset( $test['posts'][ $args[0] ] ) ? [ 'language_code' => $test['posts'][ $args[0] ] ] : null; }
		return $value;
	}
	function pll_default_language( $field ) { return 'polylang' === $GLOBALS['language_test']['provider'] ? $GLOBALS['language_test']['default'] : ''; }
	function pll_current_language( $field ) { return 'polylang' === $GLOBALS['language_test']['provider'] ? $GLOBALS['language_test']['current'] : ''; }
	function pll_languages_list( $args ) { return 'polylang' === $GLOBALS['language_test']['provider'] ? $GLOBALS['language_test']['languages'] : []; }
	function pll_get_post_language( $post_id, $field ) { return 'polylang' === $GLOBALS['language_test']['provider'] ? ( $GLOBALS['language_test']['posts'][ $post_id ] ?? '' ) : ''; }
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( $value ) ); }
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ); }
	function absint( $value ) { return abs( (int) $value ); }
	function wp_kses_post( $value ) { return preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $value ); }
	function wp_kses( $value, $allow, $protocols = [] ) { return wp_kses_post( $value ); }
	function wp_kses_allowed_html( $context ) { return []; }
	function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
	function wp_upload_dir() { return [ 'baseurl' => 'https://example.test/wp-content/uploads' ]; }
	function esc_url_raw( $value, $protocols = [] ) { return $value; }
	function rest_validate_value_from_schema( $value, $schema, $field ) { return true; }
	function shortcode_exists( $tag ) { return true; }
	function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['language_test']['meta'][ $id ][ $key ] ?? ''; }
}
namespace SEORAI\ServicePageCPT {
	final class ServiceOnboarding {
		public static function active_settings() { return $GLOBALS['language_test']['active']; }
		public static function is_required() { return false; }
		public static function save_active_settings( $presets, $template ) { $GLOBALS['language_test']['active']['presets'] = $presets; $GLOBALS['language_test']['active']['template'] = $template; $GLOBALS['language_test']['writes']++; return true; }
	}
}
namespace {
	require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-wpml-support.php';
	require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-document.php';
	require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-languages.php';
	require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-shared.php';
	require dirname( __DIR__ ) . '/modules/service-page-cpt/includes/class-service-live-preview.php';
	use SEORAI\ServicePageCPT\ServiceDocument as Document;
	use SEORAI\ServicePageCPT\ServiceShared as Shared;
	use SEORAI\ServicePageCPT\ServiceLanguages as Languages;
	use SEORAI\ServicePageCPT\ServiceLivePreview as Preview;
	$checks = 0;
	function check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); } }
	function section( $id, $type, $data ) { return [ 'id' => $id, 'type' => $type, 'enabled' => true, 'data' => $data ]; }
	function document( $sections ) { return [ 'version' => 2, 'sections' => $sections ]; }
	function keyed( $document ) { return array_column( $document['sections'], null, 'id' ); }

	$library = document( [ section( 'default_cta', 'cta', [ 'title' => 'Plan je consult', 'body' => '<p>Persoonlijk advies.</p>', 'buttons' => [ [ 'label' => 'Neem contact op', 'url' => '/contact/' ] ], 'layout' => 'compact', 'width' => 'full' ] ), section( 'default_people', 'people', [ 'title' => 'Ons team', 'body' => '<p>Ontmoet ons team.</p>', 'images' => [ [ 'id' => 10, 'url' => 'https://example.test/team.jpg', 'alt' => 'Het team', 'caption' => '' ] ] ] ) ] );
	$GLOBALS['language_test']['options'][ Shared::OPTION_PRESETS ] = $library;
	$base = Shared::get_base_presets();
	$template = document( [ [ 'id' => 'main_cta', 'type' => 'cta', 'enabled' => true, 'shared_key' => 'default_cta', 'data' => (object) [] ], [ 'id' => 'main_people', 'type' => 'people', 'enabled' => true, 'shared_key' => 'default_people', 'data' => (object) [] ] ] );
	$GLOBALS['language_test']['options'][ Shared::OPTION_TEMPLATE ] = $template;
	$GLOBALS['language_test']['meta'][101][ Document::META_DOCUMENT ] = $template;
	$GLOBALS['language_test']['meta'][102][ Document::META_DOCUMENT ] = $template;
	$before = $GLOBALS['language_test']['options'];
	check( 'nl' === Languages::default_language() && 'nl' === Languages::current(), 'WPML uses provider default/current languages' );
	check( 'en' === Languages::current( 101 ), 'Assigned WPML post language overrides request language' );
	check( 'fr' === Languages::current( 101, 'fr' ), 'Settings preview supports an explicit active language' );
	$_REQUEST['lang'] = 'fr';
	check( 'fr' === Languages::current() && 'en' === Languages::current( 101 ), 'Settings GET language is respected while target post remains authoritative' );
	unset( $_REQUEST['lang'] );
	$_REQUEST['nova_bridge_suite_default_lang'] = 'en';
	check( 'nl' === Languages::default_language(), 'Submitted legacy hidden language cannot redefine native default language' );
	unset( $_REQUEST['nova_bridge_suite_default_lang'] );
	check( is_wp_error( Languages::validate( 'all' ) ) && is_wp_error( Languages::validate( 'xx' ) ), 'All-languages and unknown language saves are rejected' );
	check( 'en' === Languages::validate( 'EN' ) && Languages::is_translated( 'en' ), 'Enabled language codes normalize safely' );
	$GLOBALS['language_test']['languages'][] = 'pt-br'; $_REQUEST['lang'] = 'pt_BR';
	check( 'pt-br' === Languages::current() && 'pt-br' === Languages::validate( 'pt_BR' ), 'Regional codes work with the suite adapter underscore normalization' );
	check( 'Perguntas frequentes' === Languages::generic_section_title( 'faq', 'FAQ', 0, 'pt_BR' ), 'Factory headings use the base language of a regional language code' );
	unset( $_REQUEST['lang'] ); array_pop( $GLOBALS['language_test']['languages'] );
	check( $base === Shared::get_presets( 101 ), 'An untranslated post inherits all default-language content' );
	check( $before === $GLOBALS['language_test']['options'] && 0 === $GLOBALS['language_test']['writes'], 'Reading language defaults does not migrate or write options' );

	$english = $base;
	$english['sections'][0]['data']['title'] = 'Book your consultation';
	$english['sections'][0]['data']['buttons'] = [ [ 'label' => 'Contact us', 'url' => '/en/contact/' ] ];
	check( true === Shared::save_settings( $english, Shared::get_template(), 'en' ), 'Translated shared content saves through normal shared settings' );
	$translation = keyed( get_option( Shared::OPTION_TRANSLATIONS )['en'] );
	check( [ 'title', 'buttons' ] === array_keys( $translation['default_cta']['data'] ), 'Translation stores only content differences, not cloned inherited fields' );
	check( $library === get_option( Shared::OPTION_PRESETS ) && $template === get_option( Shared::OPTION_TEMPLATE ), 'Translated save preserves both existing canonical options' );
	$resolved = keyed( Shared::get_presets( 101 ) );
	check( 'Book your consultation' === $resolved['default_cta']['data']['title'] && '<p>Persoonlijk advies.</p>' === $resolved['default_cta']['data']['body'], 'Sparse title translation keeps inherited body instead of sanitizer-added empty body' );
	check( '/en/contact/' === $resolved['default_cta']['data']['buttons'][0]['url'], 'Language-specific CTA destination inherits automatically' );
	check( 'compact' === $resolved['default_cta']['data']['layout'], 'Presentation remains global in translated output' );
	check( 'Book your consultation' === keyed( Document::resolve_document( 101 ) )['main_cta']['data']['title'], 'Rendered document resolves using assigned post language' );
	check( 'Plan je consult' === keyed( Document::resolve_document( 102 ) )['main_cta']['data']['title'], 'Default-language page retains original shared content' );
	$with_labels = $template; $with_labels['sections'][] = section( 'faq', 'faq', [ 'title' => 'Veelgestelde vragen', 'body' => '', 'items' => [ [ 'question' => 'Original question?', 'answer' => '<p>Original answer.</p>' ] ] ] ); $with_labels['sections'][] = section( 'related', 'related', [ 'title' => 'Andere relevante diensten', 'body' => '' ] );
	$GLOBALS['language_test']['meta'][101][ Document::META_DOCUMENT ] = $with_labels; $stored_labels = serialize( $GLOBALS['language_test']['meta'][101] );
	$labels_resolved = keyed( Document::resolve_document( 101 ) );
	check( 'FAQ' === $labels_resolved['faq']['data']['title'] && 'Related services' === $labels_resolved['related']['data']['title'], 'Factory FAQ and related headings follow assigned page language rather than template text language' );
	check( $with_labels['sections'][2]['data']['items'] === array_map( static function ( $item ) { return array_intersect_key( $item, [ 'question' => true, 'answer' => true ] ); }, $labels_resolved['faq']['data']['items'] ) && $stored_labels === serialize( $GLOBALS['language_test']['meta'][101] ), 'Generic-heading localization preserves approved question/answer copy and all stored page metadata' );
	$labels_preview = keyed( Preview::candidate( 'page', 101, $with_labels )['document'] );
	check( 'FAQ' === $labels_preview['faq']['data']['title'] && 'Related services' === $labels_preview['related']['data']['title'], 'Visual page preview agrees with public generic-heading localization' );
	check( 'Welke behandeling past bij mij?' === Languages::generic_section_title( 'faq', 'Welke behandeling past bij mij?', 101 ) && 'Helpful next steps' === Languages::generic_section_title( 'related', 'Helpful next steps', 101 ), 'Custom section headings remain exactly as authored' );
	check( '' === Languages::generic_section_title( 'faq', '', 101 ) && 'Veelgestelde vragen' === Languages::generic_section_title( 'rich_text', 'Veelgestelde vragen', 101 ), 'Explicitly blank headings and service-specific rich text are never localized as generic labels' );
	$GLOBALS['language_test']['meta'][101][ Document::META_DOCUMENT ] = $template;
	check( 'en' === Shared::context( 101 )['languages']['current'], 'Posting context identifies the assigned page language' );
	check( 'Book your consultation' === keyed( Preview::candidate( 'page', 101, $template )['document'] )['main_cta']['data']['title'], 'Page editor preview resolves the post language' );
	check( 'Book your consultation' === keyed( Preview::candidate( 'template', 102, $template, 'en' )['document'] )['main_cta']['data']['title'], 'Default-layout preview can explicitly select another settings language' );
	$detached = Document::patch_content( $template, [ 'section_overrides' => [ 'main_cta' => [ 'shared_key' => '' ] ] ], false, 101 );
	check( 'Book your consultation' === keyed( $detached )['main_cta']['data']['title'] && ! isset( keyed( $detached )['main_cta']['shared_key'] ), 'API detaching a shared field copies the target language content' );
	$local = $template; $local['sections'][0]['data'] = [ 'title' => 'This page only' ];
	check( 'This page only' === Shared::resolve_section( $local['sections'][0], 101 )['data']['title'], 'Existing per-page overrides still win over translated defaults' );

	$english = Shared::get_presets( 101 ); $english['sections'][0]['data']['body'] = ''; $english['sections'][1]['data']['images'] = [];
	check( true === Shared::save_settings( $english, Shared::get_template(), 'en' ), 'Explicit empty translated text and collections save' );
	$resolved = keyed( Shared::get_presets( 101 ) );
	check( '' === $resolved['default_cta']['data']['body'] && [] === $resolved['default_people']['data']['images'], 'Explicit empty translations clear instead of falling back' );
	$base_now_empty = Shared::get_base_presets(); $base_now_empty['sections'][0]['data']['body'] = ''; $base_now_empty['sections'][1]['data']['images'] = [];
	check( true === Shared::save_settings( $base_now_empty, Shared::get_template(), 'nl' ) && true === Shared::save_presets( Shared::get_presets( 101 ), 'en' ), 'A no-op translated save succeeds while base fields are temporarily empty too' );
	$empty_overlay = keyed( get_option( Shared::OPTION_TRANSLATIONS )['en'] );
	check( array_key_exists( 'body', $empty_overlay['default_cta']['data'] ) && array_key_exists( 'images', $empty_overlay['default_people']['data'] ), 'Existing intentional empty translation fields survive equal empty base values' );
	check( true === Shared::save_settings( $base, Shared::get_template(), 'nl' ) && '' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['body'] && [] === keyed( Shared::get_presets( 101 ) )['default_people']['data']['images'], 'Later default-language content still respects the retained explicit translated clearing' );
	$english = Shared::get_presets( 101 ); unset( $english['sections'][0]['data']['body'] );
	check( true === Shared::save_settings( $english, Shared::get_template(), 'en' ) && '<p>Persoonlijk advies.</p>' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['body'], 'Omitting a translated content field restores inherited fallback' );
	$english = Shared::get_presets( 101 ); $english['sections'][0]['data']['layout'] = 'cards'; $snapshot = $GLOBALS['language_test']['options'];
	check( is_wp_error( Shared::save_settings( $english, Shared::get_template(), 'en' ) ) && $snapshot === $GLOBALS['language_test']['options'], 'A translated save cannot modify global styling or partially persist' );
	$english = Shared::get_presets( 101 ); $english['sections'][0]['enabled'] = false;
	check( is_wp_error( Shared::save_settings( $english, Shared::get_template(), 'en' ) ), 'A translated save cannot hide a shared section globally' );
	$english = Shared::get_presets( 101 ); $english['sections'] = array_reverse( $english['sections'] );
	check( is_wp_error( Shared::save_settings( $english, Shared::get_template(), 'en' ) ), 'Language content does not fork shared library order or variants' );
	$changed_template = $template; $changed_template['sections'] = array_reverse( $template['sections'] );
	check( is_wp_error( Shared::save_settings( Shared::get_presets( 101 ), $changed_template, 'en' ) ), 'Translated whole-settings save cannot replace global page layout' );
	$snapshot = $GLOBALS['language_test']['options'];
	check( is_wp_error( Shared::save_settings( $base, $template, 'xx' ) ) && $snapshot === $GLOBALS['language_test']['options'], 'Invalid language cannot accidentally save into default-language options' );
	$GLOBALS['language_test']['current'] = 'en';
	$layout_save = Shared::save_template( $changed_template );
	check( true === $layout_save && serialize( Shared::get_template() ) === serialize( Document::sanitize_document( $changed_template ) ), 'Layout-only save is explicitly global even in translated admin context' );
	check( 'Plan je consult' === keyed( get_option( Shared::OPTION_PRESETS ) )['default_cta']['data']['title'], 'Global layout save never installs translated content as base defaults' );
	$changed_base = Shared::get_base_presets(); $changed_base['sections'][0]['data']['body'] = '<p>Nieuw basisadvies.</p>';
	check( true === Shared::save_settings( $changed_base, Shared::get_template(), 'nl' ), 'Default-language changes use original canonical storage' );
	check( '<p>Nieuw basisadvies.</p>' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['body'], 'Untranslated fields keep following later default-language edits' );
	check( 'Book your consultation' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['title'], 'Later base edits preserve explicitly translated fields' );
	$GLOBALS['language_test']['active'] = [ 'presets' => Shared::get_base_presets(), 'template' => Shared::get_template(), 'business' => [ 'name' => 'Real business' ] ];
	$active_before = $GLOBALS['language_test']['active'];
	$english = Shared::get_presets( 101 ); $english['sections'][0]['data']['title'] = 'Schedule your visit';
	check( true === Shared::save_settings( $english, Shared::get_template(), 'en' ) && $active_before === $GLOBALS['language_test']['active'], 'Translated content does not overwrite canonical active onboarding bundle' );
	check( 'Schedule your visit' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['title'], 'Active onboarding bundle also resolves translated shared content' );

	$GLOBALS['language_test']['provider'] = 'polylang'; $GLOBALS['language_test']['current'] = 'fr';
	check( 'fr' === Languages::current() && 'en' === Languages::current( 101 ) && 'nl' === Languages::default_language(), 'Polylang settings and assigned post scopes use the same defaults contract' );
	check( 'Polylang' === Languages::context()['provider'] && 3 === count( Languages::context()['languages'] ), 'Polylang advertises enabled language choices through suite adapter' );
	check( 'Schedule your visit' === keyed( Shared::get_presets( 101 ) )['default_cta']['data']['title'], 'Existing translated defaults work with Polylang resolution' );
	check( 'Plan je consult' === keyed( Shared::get_presets( 0, 'fr' ) )['default_cta']['data']['title'], 'Another untranslated language falls back without requiring a cloned default set' );
	$GLOBALS['language_test']['allowed'] = false; $snapshot = $GLOBALS['language_test']['options'];
	check( is_wp_error( Shared::save_settings( $english, Shared::get_template(), 'en' ) ) && $snapshot === $GLOBALS['language_test']['options'], 'Translated settings require existing administrator permission' );
	$GLOBALS['language_test']['allowed'] = true; $GLOBALS['language_test']['provider'] = 'none';
	check( '' === Languages::current() && [] === Languages::context()['languages'] && Shared::get_base_presets() === Shared::get_presets( 101 ), 'A monolingual site continues to use existing global defaults' );
	check( 'Veelgestelde vragen' === Languages::generic_section_title( 'faq', 'Veelgestelde vragen', 101 ) && 'Andere relevante diensten' === Languages::generic_section_title( 'related', 'Andere relevante diensten', 101 ), 'Monolingual generic headings keep their saved wording' );
	echo 'PASS: ' . $checks . ' native service language checks.' . PHP_EOL;
}
