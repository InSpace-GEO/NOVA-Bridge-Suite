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

$GLOBALS['wgtai_test_filter_returns'] = [];

function apply_filters($hook, $value)
{
    // Lets a check stand in for a site that hooks one of the module's documented
    // extension points, which the pass-through stub could not express.
    if (array_key_exists($hook, $GLOBALS['wgtai_test_filter_returns'])) {
        $override = $GLOBALS['wgtai_test_filter_returns'][$hook];

        return $override instanceof Closure ? $override($value) : $override;
    }

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
    // update_metadata() runs wp_unslash() over the value before writing, so a
    // caller must hand it slashed data. Reproducing that is the whole point: the
    // stub used to store $value verbatim, which is what let a missing wp_slash()
    // corrupt every structured document on a real site while the checks below
    // ("stored _elementor_data is still valid JSON") passed.
    // Simulates a write that does not land (a failing DB, a sanitize_meta filter
    // that rejects the value). The old payload stays in place, which is exactly
    // the case a null-only readback check reported as stored:true.
    if (! empty($GLOBALS['wgtai_test_meta_update_fails'])) {
        return false;
    }

    $GLOBALS['wgtai_test_meta'][(int) $post_id][$key] = wp_unslash($value);

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
    $value = (string) $value;

    // Enough to prove the sanitize path runs: drop script tags and their content.
    $value = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $value);

    // Real wp_kses_post rebuilds tag attributes and re-emits them double-quoted
    // (wp_kses_hair/wp_kses_attr). That is what corrupts a JSON blob passed
    // through it: an escaped \" becomes a raw ". Reproduced here so the
    // structured-document path is actually proven, not assumed.
    return preg_replace_callback(
        '#<[a-zA-Z][^>]*>#',
        static function ($match) {
            return str_replace('\\"', '"', $match[0]);
        },
        $value
    );
}

function wp_json_encode($value, $options = 0, $depth = 512)
{
    return json_encode($value, $options, $depth);
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

// map_deep + the strings-only helpers, matching WP: both recurse into arrays and
// leave non-strings alone. The old wp_unslash() stub only handled a bare string,
// so an array payload passed through it untouched.
function wgtai_test_map_deep($value, callable $callback)
{
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = wgtai_test_map_deep($item, $callback);
        }

        return $value;
    }

    return is_string($value) ? $callback($value) : $value;
}

function wp_slash($value)
{
    return wgtai_test_map_deep($value, 'addslashes');
}

function stripslashes_deep($value)
{
    return wgtai_test_map_deep($value, 'stripslashes');
}

function wp_unslash($value)
{
    return stripslashes_deep($value);
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

/**
 * What get_post_meta() actually hands a template, given what our filter returned.
 *
 * Mirrors get_metadata_raw():
 *
 *     if ( null !== $check ) {
 *         if ( $single && is_array( $check ) ) { return $check[0]; }
 *         return $check;
 *     }
 *
 * Asserting the filter's raw return value hid the $single bug -- the effective
 * value is what a theme sees, so that is what these checks compare.
 */
function wgtai_test_meta_via_wp($filtered, bool $single)
{
    if (null === $filtered) {
        // Filter declined; WP falls through to the real meta table.
        return null;
    }

    if ($single && is_array($filtered)) {
        return $filtered[0];
    }

    return $filtered;
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
    // Nullable: a check sets the service to null to simulate a call that lands
    // before Weglot's plugins_loaded container is built.
    $service = $GLOBALS['wgtai_test_weglot_language_service'];

    return is_object($service) ? $service->original : null;
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

wgtai_check(
    'meta single returns the value',
    wgtai_test_meta_via_wp($render->filter_post_metadata(null, 42, 'blog_intro', true), true),
    'Intro FR'
);
wgtai_check(
    'meta non-single returns an array',
    wgtai_test_meta_via_wp($render->filter_post_metadata(null, 42, 'blog_intro', false), false),
    ['Intro FR']
);
wgtai_check('meta untouched for another post', $render->filter_post_metadata(null, 77, 'blog_intro', true), null);
wgtai_check('untranslated meta key falls through', $render->filter_post_metadata(null, 42, 'other_key', true), null);
wgtai_check('empty meta key falls through', $render->filter_post_metadata(null, 42, '', true), null);
wgtai_check('yoast title filter swaps', $render->filter_yoast_title('NL title'), 'Titre FR');

// A payload must not be able to rewrite the bridge's own storage keys mid-request.
$storage->save(
    42,
    [
        'language' => 'fr',
        'title'    => 'Chauffage au sol',
        'content'  => '<p>Bonjour</p>',
        'meta'     => [
            '_yoast_wpseo_title'                => 'Titre FR',
            '_yoast_wpseo_metadesc'             => 'Description FR',
            WGTAI_Storage_Service::META_INDEX   => ['xx'],
            '_nova_weglot_i18n_fr'              => ['title' => 'poisoned'],
        ],
    ]
);
$GLOBALS['wgtai_test_filters'] = [];
$render_reserved = new WGTAI_Render_Service($languages, $storage);
$render_reserved->resolve_payload();

wgtai_check(
    'reserved index key is not overridable',
    $render_reserved->filter_post_metadata(null, 42, WGTAI_Storage_Service::META_INDEX, true),
    null
);
wgtai_check(
    'reserved payload key is not overridable',
    $render_reserved->filter_post_metadata(null, 42, '_nova_weglot_i18n_fr', true),
    null
);
wgtai_check('stored languages unaffected by a poisoned payload', $storage->get_stored_languages(42), ['fr']);
wgtai_check('payload still readable after poisoning attempt', $storage->get(42, 'fr')['title'], 'Chauffage au sol');
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
wgtai_check(
    'no builder exclusions without an _elementor_data payload',
    (bool) preg_grep('/^\.elementor-element-/', $de_blocks),
    false
);

// ---------------------------------------------------------------------------
// Page-builder pages: _elementor_data payloads
//
// Elementor renders from _elementor_data, not post_content, so the payload
// reaches the page through the meta filter -- and that markup carries no
// .nova-weglot-i18n wrapper. Weglot must be told to leave the elements we
// rewrote alone, or it re-translates our own copy (garbling it, and spending
// the quota this bridge exists to avoid).
// ---------------------------------------------------------------------------

function wgtai_test_elementor_doc(string $heading, string $body): string
{
    return json_encode(
        [[
            'id'       => 'sec1',
            'elType'   => 'section',
            'settings' => [],
            'elements' => [[
                'id'       => 'col1',
                'elType'   => 'column',
                'settings' => [],
                'elements' => [
                    ['id' => 'hd1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['title' => $heading]],
                    ['id' => 'tx1', 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => ['editor' => $body]],
                ],
            ]],
        ]]
    );
}

wgtai_test_seed_post(44, 'elementor-pagina', 'Elementor pagina');
$GLOBALS['wgtai_test_meta'][44]['_elementor_data'] = wgtai_test_elementor_doc('NL kop', '<p>NL body</p>');

// Only the heading is translated; the text editor keeps the source value.
$storage->save(44, [
    'language' => 'fr',
    'title'    => 'Page FR',
    'meta'     => ['_elementor_data' => wgtai_test_elementor_doc('Titre FR', '<p>NL body</p>')],
]);

$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'fr';
$GLOBALS['wgtai_test_context']['queried_id']   = 44;
$GLOBALS['wgtai_test_context']['current_id']   = 44;

$render_el = new WGTAI_Render_Service($languages, $storage);
$render_el->resolve_payload();
$el_blocks = $render_el->filter_exclude_blocks([]);

wgtai_check_true('changed element excluded from Weglot', in_array('.elementor-element-hd1', $el_blocks, true));
wgtai_check(
    'unchanged element still translated by Weglot',
    in_array('.elementor-element-tx1', $el_blocks, true),
    false
);
wgtai_check(
    'unchanged container still translated by Weglot',
    in_array('.elementor-element-sec1', $el_blocks, true),
    false
);
wgtai_check_true('wrapper class still excluded on a builder page', in_array('.nova-weglot-i18n', $el_blocks, true));

// The diff must be real: an identical document means we changed nothing.
$storage->save(44, [
    'language' => 'de',
    'meta'     => ['_elementor_data' => wgtai_test_elementor_doc('NL kop', '<p>NL body</p>')],
]);
$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'de';

$render_same = new WGTAI_Render_Service($languages, $storage);
$render_same->resolve_payload();
wgtai_check(
    'an identical document excludes nothing',
    (bool) preg_grep('/^\.elementor-element-/', $render_same->filter_exclude_blocks([])),
    false
);

// With no readable original we cannot tell what we changed, so everything we
// serve is treated as ours -- re-translating our own copy is the worse fault.
$GLOBALS['wgtai_test_meta'][44]['_elementor_data'] = 'not json at all';
$GLOBALS['wgtai_test_filters']                     = [];
$GLOBALS['wgtai_test_context']['current_lang']     = 'fr';

$render_nodoc = new WGTAI_Render_Service($languages, $storage);
$render_nodoc->resolve_payload();
$nodoc_blocks = $render_nodoc->filter_exclude_blocks([]);
wgtai_check_true('unreadable original excludes the heading', in_array('.elementor-element-hd1', $nodoc_blocks, true));
wgtai_check_true('unreadable original excludes the body too', in_array('.elementor-element-tx1', $nodoc_blocks, true));

// WordPress stores _elementor_data slashed; the diff must still work.
$GLOBALS['wgtai_test_meta'][44]['_elementor_data'] = addslashes(wgtai_test_elementor_doc('NL kop', '<p>NL body</p>'));
$GLOBALS['wgtai_test_filters']                     = [];

$render_slashed = new WGTAI_Render_Service($languages, $storage);
$render_slashed->resolve_payload();
$slashed_blocks = $render_slashed->filter_exclude_blocks([]);
wgtai_check_true('slashed original still diffs', in_array('.elementor-element-hd1', $slashed_blocks, true));
wgtai_check(
    'slashed original does not over-exclude',
    in_array('.elementor-element-tx1', $slashed_blocks, true),
    false
);

// An element ID that would break out of a CSS selector must never be emitted.
$GLOBALS['wgtai_test_meta'][44]['_elementor_data'] = json_encode([['id' => 'ok1', 'settings' => ['title' => 'NL']]]);
$storage->save(44, [
    'language' => 'fr',
    'meta'     => ['_elementor_data' => json_encode([
        ['id' => 'ok1', 'settings' => ['title' => 'FR']],
        ['id' => 'bad{color:red}', 'settings' => ['title' => 'FR']],
    ])],
]);
$GLOBALS['wgtai_test_filters'] = [];

$render_badid = new WGTAI_Render_Service($languages, $storage);
$render_badid->resolve_payload();
$badid_blocks = $render_badid->filter_exclude_blocks([]);
wgtai_check_true('safe element id emitted', in_array('.elementor-element-ok1', $badid_blocks, true));
wgtai_check(
    'unsafe element id never reaches a selector',
    (bool) preg_grep('/[{}]/', $badid_blocks),
    false
);

// --- structured meta must survive sanitisation -----------------------------
// wp_kses_post rebuilds tag attributes double-quoted, which turns an escaped \"
// inside a JSON blob into a raw " and truncates the document. Structured keys
// must be sanitised leaf-by-leaf instead.
$linked = wgtai_test_elementor_doc('Titre FR', '<p><a href="https://example.com/fr/">Lien</a></p>');
$storage->save(44, ['language' => 'fr', 'meta' => ['_elementor_data' => $linked]]);
$stored_doc = $storage->get(44, 'fr')['meta']['_elementor_data'];

wgtai_check_true('stored _elementor_data is still valid JSON', is_array(json_decode($stored_doc, true)));
$decoded_doc = json_decode($stored_doc, true);
wgtai_check(
    'the link inside the document survived intact',
    $decoded_doc[0]['elements'][0]['elements'][1]['settings']['editor'],
    '<p><a href="https://example.com/fr/">Lien</a></p>'
);
wgtai_check(
    'translated heading survived intact',
    $decoded_doc[0]['elements'][0]['elements'][0]['settings']['title'],
    'Titre FR'
);

// ...but sanitisation must still happen inside the document, and inside arrays,
// which previously bypassed kses entirely.
$scripted = json_encode([['id' => 'x1', 'settings' => ['title' => 'Hi<script>alert(1)</script>']]]);
$storage->save(44, [
    'language' => 'fr',
    'meta'     => [
        '_elementor_data' => $scripted,
        'nested_field'    => ['deep' => ['bad' => 'Hi<script>alert(1)</script>']],
    ],
]);
$after = $storage->get(44, 'fr')['meta'];
wgtai_check(
    'script stripped inside a structured document',
    json_decode($after['_elementor_data'], true)[0]['settings']['title'],
    'Hi'
);
wgtai_check('script stripped inside a nested array meta value', $after['nested_field']['deep']['bad'], 'Hi');

// A post with no stored payload for the current language must be left alone.
wgtai_test_seed_post(43, 'andere-pagina', 'Andere pagina');
$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 43;
$GLOBALS['wgtai_test_context']['current_id'] = 43;

$render_none = new WGTAI_Render_Service($languages, $storage);
$render_none->resolve_payload();
wgtai_check('no filters for a post without a payload', wgtai_test_filter_registered('the_content'), false);
wgtai_check('content untouched without a payload', $render_none->filter_content('<p>Hallo</p>'), '<p>Hallo</p>');

// --- update contract: omitted keeps, null clears, value replaces -------------
//
// build_payload() used to start from an empty array, so a follow-up POST that
// carried only the field it was fixing erased the locale's content, excerpt and
// whole meta map while still answering 200 stored:true.

wgtai_test_seed_post(50, 'merge-post', 'Merge post');
$GLOBALS['wgtai_test_context']['current_lang'] = 'fr';

$storage->save(50, [
    'language' => 'fr',
    'title'    => 'Titre FR',
    'content'  => '<p>Contenu FR</p>',
    'excerpt'  => 'Extrait FR',
    'meta'     => ['blog_intro' => 'Intro FR', 'blog_outro' => 'Outro FR'],
]);
$first_created = $storage->get(50, 'fr')['created_at'];

// A typo fix that carries only the title.
$storage->save(50, ['language' => 'fr', 'title' => 'Nouveau titre']);
$merged = $storage->get(50, 'fr');

wgtai_check('a partial update replaces the supplied field', $merged['title'], 'Nouveau titre');
wgtai_check('a partial update keeps the content', $merged['content'], '<p>Contenu FR</p>');
wgtai_check('a partial update keeps the excerpt', $merged['excerpt'], 'Extrait FR');
wgtai_check('a partial update keeps the meta map', $merged['meta']['blog_intro'], 'Intro FR');
wgtai_check('a partial update keeps created_at', $merged['created_at'], $first_created);

// Meta merges per key, like update_post_meta.
$storage->save(50, ['language' => 'fr', 'meta' => ['blog_intro' => 'Intro v2']]);
$merged = $storage->get(50, 'fr');

wgtai_check('a meta key is replaced', $merged['meta']['blog_intro'], 'Intro v2');
wgtai_check('sibling meta keys survive', $merged['meta']['blog_outro'], 'Outro FR');

// An explicit null is the way to clear, which nothing could do before.
$storage->save(50, ['language' => 'fr', 'excerpt' => null, 'meta' => ['blog_outro' => null]]);
$merged = $storage->get(50, 'fr');

wgtai_check('null clears a top-level field', isset($merged['excerpt']), false);
wgtai_check('null clears a meta key', isset($merged['meta']['blog_outro']), false);
wgtai_check('clearing one field leaves the rest', $merged['title'], 'Nouveau titre');
wgtai_check('clearing one meta key leaves the rest', $merged['meta']['blog_intro'], 'Intro v2');

// An empty meta object means "this request carries no meta keys", not "delete
// everything" -- there is no whole-map wipe in the contract.
$storage->save(50, ['language' => 'fr', 'meta' => []]);
wgtai_check('an empty meta object clears nothing', $storage->get(50, 'fr')['meta']['blog_intro'], 'Intro v2');

// --- the write is confirmed against what was requested -----------------------

$GLOBALS['wgtai_test_meta_update_fails'] = true;
$failed_write = $storage->save(50, ['language' => 'fr', 'title' => 'Jamais stocké']);
$GLOBALS['wgtai_test_meta_update_fails'] = false;

wgtai_check_true('a write that does not land is reported as an error', is_wp_error($failed_write));
wgtai_check(
    '...with the store-failed code',
    is_wp_error($failed_write) ? $failed_write->get_error_code() : null,
    'wgtai_store_failed'
);
wgtai_check('...and the previous payload is untouched', $storage->get(50, 'fr')['title'], 'Nouveau titre');

// --- the index key cannot be reached through a locale code -------------------
//
// DELETE .../translations/languages: 'languages' resolves to no destination, so
// the controller falls back to normalize(). While the index lived under
// META_PREFIX that landed on the index itself and wiped it, orphaning every
// stored payload while the endpoint answered 200 deleted:true.

wgtai_check_true(
    'the index key sits outside the payload prefix',
    0 !== strpos(WGTAI_Storage_Service::META_INDEX, WGTAI_Storage_Service::META_PREFIX)
);
wgtai_check(
    'meta_key("languages") cannot collide with the index',
    $storage->meta_key('languages') === WGTAI_Storage_Service::META_INDEX,
    false
);
wgtai_check('"languages" resolves to no destination language', $languages->resolve_destination_code('languages'), '');

$storage->delete(50, $languages->normalize('languages'));
wgtai_check('deleting "languages" leaves the stored-language index intact', $storage->get_stored_languages(50), ['fr']);
wgtai_check('deleting "languages" leaves the payload intact', $storage->get(50, 'fr')['title'], 'Nouveau titre');

// --- array-valued meta survives the round trip -------------------------------

$storage->save(50, ['language' => 'fr', 'meta' => ['gallery' => ['a' => 'x', 'b' => 'y']]]);

$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 50;
$GLOBALS['wgtai_test_context']['current_id'] = 50;

$render_50 = new WGTAI_Render_Service($languages, $storage);
$render_50->resolve_payload();

wgtai_check(
    'an array meta value survives a single read',
    wgtai_test_meta_via_wp($render_50->filter_post_metadata(null, 50, 'gallery', true), true),
    ['a' => 'x', 'b' => 'y']
);
wgtai_check(
    'an array meta value survives a multi read',
    wgtai_test_meta_via_wp($render_50->filter_post_metadata(null, 50, 'gallery', false), false),
    [['a' => 'x', 'b' => 'y']]
);
wgtai_check(
    'a string meta value still reads back as itself',
    wgtai_test_meta_via_wp($render_50->filter_post_metadata(null, 50, 'blog_intro', true), true),
    'Intro v2'
);

// --- a structured document posted as an array is normalized to JSON ----------

$storage->save(50, [
    'language' => 'fr',
    'meta'     => [
        '_elementor_data' => [[
            'id'       => 'sec9',
            'settings' => [],
            'elements' => [['id' => 'hd9', 'settings' => ['title' => 'Titre depuis un tableau']]],
        ]],
    ],
]);
$as_stored = $storage->get(50, 'fr')['meta']['_elementor_data'];

wgtai_check_true('an array-valued structured key is stored as a string', is_string($as_stored));
wgtai_check(
    'an array-valued structured key still decodes',
    is_string($as_stored) ? (json_decode($as_stored, true)[0]['elements'][0]['settings']['title'] ?? null) : null,
    'Titre depuis un tableau'
);

// --- builder exclusions: leaf widgets only, text diffed ----------------------

function wgtai_test_builder_doc(array $section_settings, array $widgets): string
{
    $children = [];

    foreach ($widgets as $id => $settings) {
        $children[] = ['id' => $id, 'elType' => 'widget', 'settings' => $settings];
    }

    return json_encode([[
        'id'       => 'sec1',
        'elType'   => 'section',
        'settings' => $section_settings,
        'elements' => [[
            'id'       => 'col1',
            'elType'   => 'column',
            'settings' => [],
            'elements' => $children,
        ]],
    ]]);
}

wgtai_test_seed_post(60, 'builder-post', 'Builder post');

// The post's real document. tx1 differs from the stored copy only by an entity
// the kses + json round trip normalizes, and by a non-string setting; sec1
// differs by a real string setting, but it is a container.
$GLOBALS['wgtai_test_meta'][60]['_elementor_data'] = wgtai_test_builder_doc(
    ['background' => '#ffffff'],
    [
        'hd1' => ['title' => 'Titel NL'],
        'tx1' => ['editor' => '<p>Caf&eacute; local</p>', 'size' => 15],
    ]
);

$storage->save(60, [
    'language' => 'fr',
    'meta'     => [
        '_elementor_data' => wgtai_test_builder_doc(
            ['background' => '#000000'],
            [
                'hd1' => ['title' => 'Titre FR'],
                'tx1' => ['editor' => '<p>Café local</p>', 'size' => 20],
            ]
        ),
    ],
]);

$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 60;
$GLOBALS['wgtai_test_context']['current_id'] = 60;

$render_60 = new WGTAI_Render_Service($languages, $storage);
$render_60->resolve_payload();
$blocks_60 = $render_60->filter_exclude_blocks([]);

wgtai_check_true('a retranslated widget is excluded', in_array('.elementor-element-hd1', $blocks_60, true));
wgtai_check(
    'a widget differing only by an entity round trip is NOT excluded',
    in_array('.elementor-element-tx1', $blocks_60, true),
    false
);
wgtai_check(
    'a changed section is NOT excluded, which would freeze its whole subtree',
    in_array('.elementor-element-sec1', $blocks_60, true),
    false
);
wgtai_check(
    'a column is NOT excluded either',
    in_array('.elementor-element-col1', $blocks_60, true),
    false
);

// --- exclusions follow the structured-key list, not a hardcoded builder ------
//
// Extending nova_weglot_structured_meta_keys used to buy sanitisation with no
// notranslate protection: the payload landed and Weglot re-translated it.

$GLOBALS['wgtai_test_filter_returns']['nova_weglot_structured_meta_keys']      = ['_elementor_data', '_custom_builder'];
$GLOBALS['wgtai_test_filter_returns']['nova_weglot_builder_selector_templates'] = [
    '_elementor_data' => '.elementor-element-%s',
    '_custom_builder' => '.cb-element-%s',
];

wgtai_test_seed_post(61, 'custom-builder-post', 'Custom builder post');
$GLOBALS['wgtai_test_meta'][61]['_custom_builder'] = wgtai_test_builder_doc([], ['w1' => ['title' => 'Titel NL']]);

$storage->save(61, [
    'language' => 'fr',
    'meta'     => ['_custom_builder' => wgtai_test_builder_doc([], ['w1' => ['title' => 'Titre FR']])],
]);

$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 61;
$GLOBALS['wgtai_test_context']['current_id'] = 61;

$render_61 = new WGTAI_Render_Service($languages, $storage);
$render_61->resolve_payload();
$blocks_61 = $render_61->filter_exclude_blocks([]);

wgtai_check_true('a registered non-Elementor builder gets exclusions too', in_array('.cb-element-w1', $blocks_61, true));

$GLOBALS['wgtai_test_filter_returns'] = [];

// --- the selectors filter is cast, not trusted -------------------------------

$GLOBALS['wgtai_test_filter_returns']['nova_weglot_notranslate_selectors'] = '.entry-content';

$warnings = 0;
set_error_handler(static function () use (&$warnings) {
    ++$warnings;

    return true;
});
$blocks_cast = $render_61->filter_exclude_blocks([]);
restore_error_handler();

wgtai_check('a string from the selectors filter emits no warning', $warnings, 0);
wgtai_check_true('a string from the selectors filter is still applied', in_array('.entry-content', $blocks_cast, true));

$GLOBALS['wgtai_test_filter_returns'] = [];

// --- head/body title gating --------------------------------------------------
//
// The gate used to read payload['title'] while the code that actually rewrites
// <title> on a Yoast site reads meta['_yoast_wpseo_title']. Both mismatches
// shipped: an un-excluded Yoast title Weglot re-translated, and an excluded
// source-language title marked do-not-touch.

wgtai_test_seed_post(70, 'title-post', 'Titel NL');
$storage->save(70, ['language' => 'fr', 'title' => 'Titre FR']);

$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 70;
$GLOBALS['wgtai_test_context']['current_id'] = 70;

$render_70 = new WGTAI_Render_Service($languages, $storage);
$render_70->resolve_payload();
$blocks_70 = $render_70->filter_exclude_blocks([]);

wgtai_check_true('without an SEO plugin our document_title_parts title is excluded', in_array('title', $blocks_70, true));
wgtai_check_true('the theme-rendered body title is excluded too', in_array('.entry-title', $blocks_70, true));
wgtai_check_true('block themes are covered', in_array('.wp-block-post-title', $blocks_70, true));
wgtai_check(
    'og:title is NOT excluded when nothing emits ours',
    in_array('meta[property="og:title"]', $blocks_70, true),
    false
);

// A payload carrying only the Yoast title: wpseo_title IS registered, so the
// head is ours and must be excluded even with no top-level title.
wgtai_test_seed_post(71, 'yoast-only-post', 'Titel NL');
$storage->save(71, ['language' => 'fr', 'meta' => ['_yoast_wpseo_title' => 'Titre SEO FR']]);

$GLOBALS['wgtai_test_filters']               = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 71;
$GLOBALS['wgtai_test_context']['current_id'] = 71;

$render_71 = new WGTAI_Render_Service($languages, $storage);
$render_71->resolve_payload();
$blocks_71 = $render_71->filter_exclude_blocks([]);

wgtai_check_true('wpseo_title is registered for a yoast-only payload', wgtai_test_filter_registered('wpseo_title'));
wgtai_check_true('a yoast-only payload excludes the document title', in_array('title', $blocks_71, true));
wgtai_check_true('a yoast-only payload excludes og:title', in_array('meta[property="og:title"]', $blocks_71, true));
wgtai_check(
    'a yoast-only payload does NOT exclude the body title it never translated',
    in_array('.entry-title', $blocks_71, true),
    false
);

// Yoast active from here on -- WPSEO_VERSION cannot be undefined again, so this
// case runs last.
define('WPSEO_VERSION', '25.0');

$GLOBALS['wgtai_test_filters'] = [];
$GLOBALS['wgtai_test_context']['queried_id'] = 70;
$GLOBALS['wgtai_test_context']['current_id'] = 70;

$render_70_yoast = new WGTAI_Render_Service($languages, $storage);
$render_70_yoast->resolve_payload();
$blocks_70_yoast = $render_70_yoast->filter_exclude_blocks([]);

wgtai_check(
    'with Yoast active and no _yoast_wpseo_title the head is NOT excluded',
    in_array('title', $blocks_70_yoast, true),
    false
);
wgtai_check_true(
    'the body title is still excluded, because the_title is still ours',
    in_array('.entry-title', $blocks_70_yoast, true)
);

// --- a failed language lookup is not memoized --------------------------------

$saved_weglot_service                          = $GLOBALS['wgtai_test_weglot_language_service'];
$GLOBALS['wgtai_test_weglot_language_service'] = null;

$cold = new WGTAI_Language_Service();

wgtai_check('destination codes are empty before Weglot is up', $cold->get_destination_codes(), []);
wgtai_check('the original code is empty before Weglot is up', $cold->get_original_code(), '');

$GLOBALS['wgtai_test_weglot_language_service'] = $saved_weglot_service;

wgtai_check('destination codes recover once Weglot is up', $cold->get_destination_codes(), ['fr', 'de']);
wgtai_check('the original code recovers once Weglot is up', $cold->get_original_code(), 'nl');
wgtai_check('a destination resolves again after the cold start', $cold->resolve_destination_code('fr-BE'), 'fr');

// --- locale is not a duplicate of the URL segment ----------------------------

$inventory = $languages->get_languages();
$fr_entry  = null;

foreach ($inventory['languages'] as $entry) {
    if ('fr' === $entry['code']) {
        $fr_entry = $entry;
    }
}

wgtai_check('the Weglot URL segment is reported as external_code', $fr_entry['external_code'], 'fr-be');
wgtai_check('locale is empty rather than a duplicate of external_code', $fr_entry['locale'], '');

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
