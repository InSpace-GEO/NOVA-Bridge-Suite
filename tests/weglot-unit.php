<?php
/**
 * Standalone unit checks for the Weglot bridge.
 *
 * Runs without WordPress or Weglot: the WP and Weglot surfaces the module touches
 * are stubbed below, so this can be run on any box with plain PHP.
 *
 * Run with: php tests/weglot-unit.php
 *
 * For end-to-end verification against a real Weglot project use
 * tests/weglot-regression.php (wp eval-file) instead.
 */

if ('cli' !== PHP_SAPI) {
    exit(1);
}

define('ABSPATH', __DIR__ . '/');

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

class WP_Error
{
    private string $code;
    private string $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code    = (string) $code;
        $this->message = (string) $message;
        $this->data    = $data;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_data()
    {
        return $this->data;
    }
}

class WP_REST_Controller
{
    protected $namespace = '';
    protected $rest_base = '';
}

function is_wp_error($thing): bool
{
    return $thing instanceof WP_Error;
}

$GLOBALS['wgtai_test_meta']    = [];
$GLOBALS['wgtai_test_filters'] = [];
$GLOBALS['wgtai_test_posts']   = [];
$GLOBALS['wgtai_test_context'] = [
    'is_admin'    => false,
    'is_singular' => true,
    'queried_id'  => 0,
    'current_id'  => 0,
    'current_lang' => '',
];

function add_action($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['wgtai_test_filters'][$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['wgtai_test_filters'][$hook][] = $callback;
}

function apply_filters($hook, $value)
{
    return $value;
}

function wgtai_test_filter_registered(string $hook): bool
{
    return ! empty($GLOBALS['wgtai_test_filters'][$hook]);
}

function get_post($post_id)
{
    return $GLOBALS['wgtai_test_posts'][(int) $post_id] ?? null;
}

function get_post_meta($post_id, $key, $single = false)
{
    $value = $GLOBALS['wgtai_test_meta'][(int) $post_id][$key] ?? null;

    if (null === $value) {
        return $single ? '' : [];
    }

    return $single ? $value : [$value];
}

function update_post_meta($post_id, $key, $value)
{
    $GLOBALS['wgtai_test_meta'][(int) $post_id][$key] = $value;

    // WordPress returns false when the stored value is already identical, not only
    // on failure. The flag reproduces that so the storage service cannot regress
    // into treating a no-op write as an error.
    if (! empty($GLOBALS['wgtai_test_meta_update_returns_false'])) {
        return false;
    }

    return true;
}

function delete_post_meta($post_id, $key)
{
    if (! isset($GLOBALS['wgtai_test_meta'][(int) $post_id][$key])) {
        return false;
    }

    unset($GLOBALS['wgtai_test_meta'][(int) $post_id][$key]);

    return true;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function wp_kses_post($value)
{
    // Enough to prove the sanitize path runs: drop script tags and their content.
    return preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string) $value);
}

function sanitize_title($value)
{
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);

    return trim((string) $value, '-');
}

function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function current_user_can($capability, $object_id = null): bool
{
    // Force the kses path in the storage service.
    return 'unfiltered_html' !== $capability;
}

function trailingslashit($value)
{
    return rtrim((string) $value, '/') . '/';
}

function home_url($path = '')
{
    return 'https://example.com' . $path;
}

function get_permalink($post_id)
{
    $post = get_post($post_id);

    return $post ? 'https://example.com/' . $post->post_name . '/' : false;
}

function is_admin(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_admin'];
}

function is_singular(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_singular'];
}

function get_queried_object_id()
{
    return (int) $GLOBALS['wgtai_test_context']['queried_id'];
}

function get_the_ID()
{
    return (int) $GLOBALS['wgtai_test_context']['current_id'];
}

// ---------------------------------------------------------------------------
// Weglot stubs
// ---------------------------------------------------------------------------

class WGTAI_Test_Language_Entry
{
    private string $internal;
    private string $external;
    private string $english;
    private string $local;

    public function __construct(string $internal, string $external, string $english, string $local)
    {
        $this->internal = $internal;
        $this->external = $external;
        $this->english  = $english;
        $this->local    = $local;
    }

    public function getInternalCode()
    {
        return $this->internal;
    }

    public function getExternalCode()
    {
        return $this->external;
    }

    public function getEnglishName()
    {
        return $this->english;
    }

    public function getLocalName()
    {
        return $this->local;
    }
}

class WGTAI_Test_Weglot_Language_Service
{
    /** @var array<string,WGTAI_Test_Language_Entry> */
    public array $entries = [];

    public string $original = 'nl';

    /** @var array<int,string> */
    public array $destinations = ['fr', 'de'];

    public function __construct()
    {
        $this->entries = [
            'nl' => new WGTAI_Test_Language_Entry('nl', 'nl', 'Dutch', 'Nederlands'),
            'fr' => new WGTAI_Test_Language_Entry('fr', 'fr-be', 'French', 'Français'),
            'de' => new WGTAI_Test_Language_Entry('de', 'de', 'German', 'Deutsch'),
        ];
    }

    public function get_original_language()
    {
        return $this->entries[$this->original] ?? null;
    }

    public function get_destination_languages()
    {
        $out = [];

        foreach ($this->destinations as $code) {
            if (isset($this->entries[$code])) {
                $out[] = $this->entries[$code];
            }
        }

        return $out;
    }

    public function get_language_from_internal($internal_code)
    {
        return $this->entries[$internal_code] ?? null;
    }
}

class WGTAI_Test_Url
{
    private string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function getForLanguage($language)
    {
        if (! is_object($language) || ! method_exists($language, 'getExternalCode')) {
            return false;
        }

        $external = $language->getExternalCode();
        $path     = (string) parse_url($this->url, PHP_URL_PATH);

        if ('nl' === $language->getInternalCode()) {
            return 'https://example.com' . $path;
        }

        return 'https://example.com/' . $external . $path;
    }
}

$GLOBALS['wgtai_test_weglot_language_service'] = new WGTAI_Test_Weglot_Language_Service();

function weglot_get_service($service)
{
    if ('Language_Service_Weglot' === $service) {
        return $GLOBALS['wgtai_test_weglot_language_service'];
    }

    return null;
}

function weglot_get_current_language()
{
    return $GLOBALS['wgtai_test_context']['current_lang'];
}

function weglot_get_original_language()
{
    return $GLOBALS['wgtai_test_weglot_language_service']->original;
}

function weglot_create_url_object($url)
{
    return new WGTAI_Test_Url((string) $url);
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../modules/weglot/includes/class-wgtai-language-service.php';
require_once __DIR__ . '/../modules/weglot/includes/class-wgtai-storage-service.php';
require_once __DIR__ . '/../modules/weglot/includes/class-wgtai-render-service.php';
require_once __DIR__ . '/../modules/weglot/includes/class-wgtai-rest-controller.php';

$passed = 0;
$failed = [];

function wgtai_check(string $label, $actual, $expected): void
{
    global $passed, $failed;

    if ($actual === $expected) {
        ++$passed;

        return;
    }

    $failed[] = sprintf(
        "%s\n    expected: %s\n    actual:   %s",
        $label,
        var_export($expected, true),
        var_export($actual, true)
    );
}

function wgtai_check_true(string $label, $actual): void
{
    wgtai_check($label, (bool) $actual, true);
}

function wgtai_test_seed_post(int $id, string $slug, string $title): void
{
    $GLOBALS['wgtai_test_posts'][$id] = (object) [
        'ID'          => $id,
        'post_name'   => $slug,
        'post_title'  => $title,
        'post_status' => 'publish',
        'post_type'   => 'blog',
    ];
}

$languages = new WGTAI_Language_Service();
$storage   = new WGTAI_Storage_Service($languages);

// --- language normalization -------------------------------------------------

wgtai_check('normalize lowercases and dashes', $languages->normalize('nl_NL'), 'nl-nl');
wgtai_check('normalize trims junk', $languages->normalize('  FR  '), 'fr');
wgtai_check('primary subtag of nl-NL', $languages->primary_subtag('nl-NL'), 'nl');

wgtai_check('original language resolves', $languages->get_original_code(), 'nl');
wgtai_check('destination codes', $languages->get_destination_codes(), ['fr', 'de']);

wgtai_check('resolve exact internal code', $languages->resolve_destination_code('fr'), 'fr');
wgtai_check('resolve uppercase', $languages->resolve_destination_code('DE'), 'de');
wgtai_check('resolve BCP-47 from NOVA', $languages->resolve_destination_code('fr-FR'), 'fr');
wgtai_check('resolve external code fr-be', $languages->resolve_destination_code('fr-be'), 'fr');
wgtai_check('unconfigured language rejected', $languages->resolve_destination_code('es'), '');
wgtai_check('empty language rejected', $languages->resolve_destination_code(''), '');

wgtai_check_true('nl is the original language', $languages->is_original_language('nl'));
wgtai_check_true('nl-NL is the original language', $languages->is_original_language('nl-NL'));
wgtai_check('fr is not the original language', $languages->is_original_language('fr'), false);

// --- storage ---------------------------------------------------------------

wgtai_check('meta key uses underscores', $storage->meta_key('fr-be'), '_nova_weglot_i18n_fr_be');

wgtai_test_seed_post(42, 'vloerverwarming', 'Vloerverwarming');

$missing = $storage->save(999, ['language' => 'fr']);
wgtai_check_true('save rejects unknown post', is_wp_error($missing));
wgtai_check('unknown post code', $missing->get_error_code(), 'wgtai_missing_source');

$no_lang = $storage->save(42, ['title' => 'x']);
wgtai_check_true('save requires a language', is_wp_error($no_lang));
wgtai_check('missing language code', $no_lang->get_error_code(), 'wgtai_missing_language');

$same = $storage->save(42, ['language' => 'nl', 'title' => 'x']);
wgtai_check_true('save rejects the original language', is_wp_error($same));
wgtai_check('same language code', $same->get_error_code(), 'wgtai_same_language');

$unknown = $storage->save(42, ['language' => 'es', 'title' => 'x']);
wgtai_check_true('save rejects an unconfigured language', is_wp_error($unknown));
wgtai_check('unknown language code', $unknown->get_error_code(), 'wgtai_unknown_language');

$saved = $storage->save(
    42,
    [
        'language' => 'fr-FR',
        'title'    => 'Chauffage au sol',
        'content'  => '<p>Bonjour</p><script>alert(1)</script>',
        'excerpt'  => 'Résumé',
        'slug'     => 'Chauffage Au Sol',
        'meta'     => [
            '_yoast_wpseo_title'   => 'Chauffage au sol | Titre',
            '_yoast_wpseo_metadesc' => 'Description FR',
            'blog_intro'           => 'Intro FR',
        ],
    ]
);

wgtai_check('save succeeds', is_wp_error($saved), false);
wgtai_check('save normalizes BCP-47 to internal code', $saved['language'], 'fr');
wgtai_check_true('save reports stored', $saved['stored']);
wgtai_check_true('first save is a create', $saved['created']);
wgtai_check('save reports the translated url', $saved['url'], 'https://example.com/fr-be/vloerverwarming/');

$payload = $storage->get(42, 'fr');
wgtai_check_true('payload is stored', is_array($payload));
wgtai_check('title stored', $payload['title'], 'Chauffage au sol');
wgtai_check('script stripped from content', $payload['content'], '<p>Bonjour</p>');
wgtai_check('slug recorded but parked', $payload['requested_slug'], 'chauffage-au-sol');
wgtai_check('meta stored', $payload['meta']['blog_intro'], 'Intro FR');
wgtai_check('stored languages index', $storage->get_stored_languages(42), ['fr']);

$resaved = $storage->save(42, ['language' => 'fr', 'title' => 'Nouveau titre']);
wgtai_check('second save is an update', $resaved['created'], false);
wgtai_check('index not duplicated', $storage->get_stored_languages(42), ['fr']);
wgtai_check(
    'created_at preserved across updates',
    $storage->get(42, 'fr')['created_at'],
    $payload['created_at']
);

// A no-op write (identical payload, or WordPress reporting "unchanged") must not
// surface as a 500 to the posting flow.
$GLOBALS['wgtai_test_meta_update_returns_false'] = true;
$identical = $storage->save(42, ['language' => 'fr', 'title' => 'Nouveau titre']);
wgtai_check('unchanged write is not an error', is_wp_error($identical), false);
wgtai_check_true('unchanged write still reports stored', is_array($identical) && ! empty($identical['stored']));

$changed = $storage->save(42, ['language' => 'fr', 'title' => 'Encore un titre']);
wgtai_check('changed write is not an error when WP reports false', is_wp_error($changed), false);
wgtai_check('changed write persisted', $storage->get(42, 'fr')['title'] ?? null, 'Encore un titre');
$GLOBALS['wgtai_test_meta_update_returns_false'] = false;

// custom_fields is an alias for meta
$storage->save(42, ['language' => 'de', 'custom_fields' => ['blog_intro' => 'Intro DE']]);
wgtai_check('custom_fields aliases meta', $storage->get(42, 'de')['meta']['blog_intro'], 'Intro DE');
wgtai_check('index holds both languages', $storage->get_stored_languages(42), ['de', 'fr']);

wgtai_check_true('delete removes a locale', $storage->delete(42, 'de'));
wgtai_check('index shrinks after delete', $storage->get_stored_languages(42), ['fr']);
wgtai_check('deleted payload is gone', $storage->get(42, 'de'), null);
wgtai_check('delete is idempotent', $storage->delete(42, 'de'), false);

// --- render ----------------------------------------------------------------

// Re-store a full FR payload for the render checks.
$storage->save(
    42,
    [
        'language' => 'fr',
        'title'    => 'Chauffage au sol',
        'content'  => '<p>Bonjour</p>',
        'excerpt'  => 'Résumé FR',
        'meta'     => [
            '_yoast_wpseo_title'    => 'Titre FR',
            '_yoast_wpseo_metadesc' => 'Description FR',
            'blog_intro'            => 'Intro FR',
        ],
    ]
);

// Original-language request: nothing must be swapped.
$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'nl';
$GLOBALS['wgtai_test_context']['queried_id']   = 42;
$GLOBALS['wgtai_test_context']['current_id']   = 42;

$render_nl = new WGTAI_Render_Service($languages, $storage);
$render_nl->resolve_payload();
wgtai_check('no filters registered on the original language', wgtai_test_filter_registered('the_content'), false);
wgtai_check('content untouched on the original language', $render_nl->filter_content('<p>Hallo</p>'), '<p>Hallo</p>');

// Admin request: nothing must be swapped.
$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'fr';
$GLOBALS['wgtai_test_context']['is_admin']     = true;

$render_admin = new WGTAI_Render_Service($languages, $storage);
$render_admin->resolve_payload();
wgtai_check('no filters registered in admin', wgtai_test_filter_registered('the_content'), false);

$GLOBALS['wgtai_test_context']['is_admin'] = false;

// Translated request: payload applies.
$GLOBALS['wgtai_test_filters'] = [];

$render = new WGTAI_Render_Service($languages, $storage);
$render->resolve_payload();

wgtai_check_true('the_content filter registered', wgtai_test_filter_registered('the_content'));
wgtai_check_true('get_post_metadata filter registered', wgtai_test_filter_registered('get_post_metadata'));
wgtai_check_true('weglot_exclude_blocks filter registered', wgtai_test_filter_registered('weglot_exclude_blocks'));
wgtai_check_true('yoast title filter registered', wgtai_test_filter_registered('wpseo_title'));

$content = $render->filter_content('<p>Hallo</p>');
wgtai_check_true('content is swapped', false !== strpos($content, '<p>Bonjour</p>'));
wgtai_check_true('content is marked notranslate', false !== strpos($content, 'data-wg-notranslate'));
wgtai_check_true('content carries the locale class', false !== strpos($content, 'nova-weglot-i18n--fr'));
wgtai_check_true('source content is replaced', false === strpos($content, 'Hallo'));

wgtai_check('title swapped for the target post', $render->filter_title('Vloerverwarming', 42), 'Chauffage au sol');
wgtai_check('title untouched for another post', $render->filter_title('Andere', 77), 'Andere');
wgtai_check('title untouched without a post id', $render->filter_title('Andere'), 'Andere');
wgtai_check('excerpt swapped', $render->filter_excerpt('NL', (object) ['ID' => 42]), 'Résumé FR');
wgtai_check('excerpt untouched for another post', $render->filter_excerpt('NL', (object) ['ID' => 77]), 'NL');

$parts = $render->filter_document_title_parts(['title' => 'Vloerverwarming', 'site' => 'Example']);
wgtai_check('document title swapped', $parts['title'], 'Chauffage au sol');
wgtai_check('document title keeps other parts', $parts['site'], 'Example');

wgtai_check('meta single returns the value', $render->filter_post_metadata(null, 42, 'blog_intro', true), 'Intro FR');
wgtai_check('meta non-single returns an array', $render->filter_post_metadata(null, 42, 'blog_intro', false), ['Intro FR']);
wgtai_check('meta untouched for another post', $render->filter_post_metadata(null, 77, 'blog_intro', true), null);
wgtai_check('untranslated meta key falls through', $render->filter_post_metadata(null, 42, 'other_key', true), null);
wgtai_check('empty meta key falls through', $render->filter_post_metadata(null, 42, '', true), null);
wgtai_check('yoast title filter swaps', $render->filter_yoast_title('NL title'), 'Titre FR');
wgtai_check('yoast metadesc filter swaps', $render->filter_yoast_metadesc('NL desc'), 'Description FR');

$blocks = $render->filter_exclude_blocks(['.amount']);
wgtai_check_true('existing exclude blocks preserved', in_array('.amount', $blocks, true));
wgtai_check_true('wrapper class excluded', in_array('.nova-weglot-i18n', $blocks, true));
wgtai_check_true('title excluded', in_array('title', $blocks, true));
wgtai_check_true('description excluded', in_array('meta[name="description"]', $blocks, true));
wgtai_check_true('og:title excluded', in_array('meta[property="og:title"]', $blocks, true));

// A payload with no title/metadesc must NOT blanket-exclude the head, or Weglot
// would stop translating fields we never overrode.
$storage->save(42, ['language' => 'de', 'content' => '<p>Hallo DE</p>']);
$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'de';

$render_de = new WGTAI_Render_Service($languages, $storage);
$render_de->resolve_payload();

$de_blocks = $render_de->filter_exclude_blocks([]);
wgtai_check('title not excluded without a translated title', in_array('title', $de_blocks, true), false);
wgtai_check(
    'description not excluded without a translated metadesc',
    in_array('meta[name="description"]', $de_blocks, true),
    false
);
wgtai_check_true('wrapper still excluded', in_array('.nova-weglot-i18n', $de_blocks, true));
wgtai_check('yoast title filter not registered without a title', wgtai_test_filter_registered('wpseo_title'), false);

// A post with no stored payload for the current language must be left alone.
wgtai_test_seed_post(43, 'andere-pagina', 'Andere pagina');
$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 43;
$GLOBALS['wgtai_test_context']['current_id'] = 43;

$render_none = new WGTAI_Render_Service($languages, $storage);
$render_none->resolve_payload();
wgtai_check('no filters for a post without a payload', wgtai_test_filter_registered('the_content'), false);
wgtai_check('content untouched without a payload', $render_none->filter_content('<p>Hallo</p>'), '<p>Hallo</p>');

// --- report ----------------------------------------------------------------

echo "\n";

if (empty($failed)) {
    printf("weglot-unit: %d checks passed\n", $passed);
    exit(0);
}

printf("weglot-unit: %d passed, %d FAILED\n\n", $passed, count($failed));

foreach ($failed as $failure) {
    echo '  FAIL  ' . $failure . "\n\n";
}

exit(1);
