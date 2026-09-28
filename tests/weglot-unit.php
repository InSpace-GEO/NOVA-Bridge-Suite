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

    /**
     * Trimmed stand-in for WP's own: derives endpoint args from the
     * controller's item schema. Faithful enough that the /posts route's args
     * are non-empty here, so a regression that empties them is visible rather
     * than hidden behind a stub that always returned [].
     *
     * @return array<string,mixed>
     */
    public function get_endpoint_args_for_item_schema($method = 'GET'): array
    {
        $schema     = method_exists($this, 'get_item_schema') ? $this->get_item_schema() : [];
        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        $required   = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : [];
        $args       = [];

        foreach ($properties as $field => $params) {
            $args[$field]             = is_array($params) ? $params : [];
            $args[$field]['required'] = in_array($field, $required, true);
        }

        return $args;
    }
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
    // No 'is_singular' flag on purpose: is_singular() below computes it from
    // the page-type booleans instead of reading a switch that defaulted to
    // true regardless of what else the context claimed to be.
    'is_tax'       => false,
    'is_category'  => false,
    'is_tag'       => false,
    'is_feed'      => false,
    'queried_object' => null,
    'queried_id'  => 0,
    'current_id'  => 0,
    'current_lang' => '',
];

// Second, deliberately separate in-memory store for term meta. Kept apart from
// wgtai_test_meta (post meta) so a seam wired to the wrong table -- a term read
// that resolves through get_post_meta(), or vice versa -- reads back empty or
// wrong data instead of accidentally finding the right value in a table that
// happens to share the request's numeric id.
$GLOBALS['wgtai_test_termmeta']          = [];
$GLOBALS['wgtai_test_terms']             = [];
$GLOBALS['wgtai_test_taxonomies']        = [];
$GLOBALS['wgtai_test_registered_routes'] = [];

function add_action($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['wgtai_test_filters'][$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1): void
{
    $GLOBALS['wgtai_test_filters'][$hook][] = $callback;
}

$GLOBALS['wgtai_test_filter_returns'] = [];

function apply_filters($hook, $value, ...$args)
{
    // Variadic on purpose: apply_filters($hook, $value) used to drop every
    // argument after $value, which made a 2+-arg filter (e.g. WooCommerce's
    // woocommerce_taxonomy_archive_description_raw($desc, $term)) impossible to
    // exercise here -- a callback registered with more than one declared
    // parameter would only ever see the first.
    //
    // Lets a check stand in for a site that hooks one of the module's documented
    // extension points, which the pass-through stub could not express.
    if (array_key_exists($hook, $GLOBALS['wgtai_test_filter_returns'])) {
        $override = $GLOBALS['wgtai_test_filter_returns'][$hook];

        return $override instanceof Closure ? $override($value, ...$args) : $override;
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

$GLOBALS['wgtai_test_denied_caps'] = [];

function current_user_can($capability, $object_id = null): bool
{
    // Force the kses path in the storage service.
    if ('unfiltered_html' === $capability) {
        return false;
    }

    // Lets a REST permissions_check() check simulate a user who lacks one
    // specific capability, without disturbing every other call site (which
    // still gets the default "yes" the storage/sanitiser checks rely on).
    return ! in_array($capability, $GLOBALS['wgtai_test_denied_caps'], true);
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

class WP_Term
{
    public int $term_id;
    public string $name;
    public string $slug;
    public string $description;
    public string $taxonomy;
    public int $parent;

    public function __construct(array $args)
    {
        $this->term_id     = (int) ($args['term_id'] ?? 0);
        $this->name        = (string) ($args['name'] ?? '');
        $this->slug        = (string) ($args['slug'] ?? '');
        $this->description = (string) ($args['description'] ?? '');
        $this->taxonomy    = (string) ($args['taxonomy'] ?? '');
        $this->parent      = (int) ($args['parent'] ?? 0);
    }
}

/**
 * @return WP_Term|WP_Error
 */
function get_term($term_id, $taxonomy = '')
{
    $term = $GLOBALS['wgtai_test_terms'][(int) $term_id] ?? null;

    if (! $term instanceof WP_Term) {
        return new WP_Error('invalid_term', 'Invalid term ID.');
    }

    if ('' !== $taxonomy && $term->taxonomy !== $taxonomy) {
        return new WP_Error('invalid_taxonomy', 'Term does not belong to the given taxonomy.', ['status' => 404]);
    }

    return $term;
}

function get_term_meta($term_id, $key = '', $single = false)
{
    // Deliberately reads $GLOBALS['wgtai_test_termmeta'], never
    // wgtai_test_meta: the two are different tables in real WordPress
    // (wp_termmeta vs wp_postmeta), and a seam wired to the wrong one must
    // read back empty/wrong data here, not accidentally find the right value
    // because both stores share the same PHP array.
    $value = $GLOBALS['wgtai_test_termmeta'][(int) $term_id][$key] ?? null;

    if (null === $value) {
        return $single ? '' : [];
    }

    return $single ? $value : [$value];
}

function update_term_meta($term_id, $key, $value)
{
    if (! empty($GLOBALS['wgtai_test_meta_update_fails'])) {
        return false;
    }

    $GLOBALS['wgtai_test_termmeta'][(int) $term_id][$key] = wp_unslash($value);

    if (! empty($GLOBALS['wgtai_test_meta_update_returns_false'])) {
        return false;
    }

    return true;
}

function delete_term_meta($term_id, $key)
{
    if (! isset($GLOBALS['wgtai_test_termmeta'][(int) $term_id][$key])) {
        return false;
    }

    unset($GLOBALS['wgtai_test_termmeta'][(int) $term_id][$key]);

    return true;
}

function taxonomy_exists($taxonomy): bool
{
    return isset($GLOBALS['wgtai_test_taxonomies'][$taxonomy]);
}

/**
 * @return object|false
 */
function get_taxonomy($taxonomy)
{
    return $GLOBALS['wgtai_test_taxonomies'][$taxonomy] ?? false;
}

/**
 * @return string|WP_Error
 */
function get_term_link($term, $taxonomy = '')
{
    if (! $term instanceof WP_Term) {
        $term = get_term((int) $term, $taxonomy);
    }

    if (is_wp_error($term) || ! ($term instanceof WP_Term)) {
        return new WP_Error('invalid_term', 'Invalid term.');
    }

    return 'https://example.com/' . $term->taxonomy . '/' . $term->slug . '/';
}

function wgtai_test_seed_term(int $id, string $taxonomy, string $slug, string $name, string $description = ''): void
{
    $GLOBALS['wgtai_test_terms'][$id] = new WP_Term([
        'term_id'     => $id,
        'name'        => $name,
        'slug'        => $slug,
        'description' => $description,
        'taxonomy'    => $taxonomy,
    ]);

    if (! isset($GLOBALS['wgtai_test_taxonomies'][$taxonomy])) {
        $GLOBALS['wgtai_test_taxonomies'][$taxonomy] = (object) [
            'name' => $taxonomy,
            'cap'  => (object) ['edit_terms' => 'manage_categories'],
        ];
    }
}

class WP_REST_Server
{
    public const CREATABLE = 'POST';
    public const READABLE  = 'GET';
    public const EDITABLE  = 'POST, PUT, PATCH';
    public const DELETABLE = 'DELETE';
}

class WP_REST_Request
{
    private string $method;
    private string $route;
    private array $params = [];

    public function __construct(string $method = 'GET', string $route = '')
    {
        $this->method = $method;
        $this->route  = $route;
    }

    public function set_param($key, $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_param($key)
    {
        return $this->params[$key] ?? null;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function get_method(): string
    {
        return $this->method;
    }
}

class WP_REST_Response
{
    private $data;
    private int $status;

    public function __construct($data, int $status = 200)
    {
        $this->data   = $data;
        $this->status = $status;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

function register_rest_route($namespace, $route, $args): void
{
    $GLOBALS['wgtai_test_registered_routes'][] = [
        'namespace' => $namespace,
        'route'     => $route,
        'args'      => $args,
    ];
}

function is_admin(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_admin'];
}

function is_tax(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_tax'];
}

function is_category(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_category'];
}

function is_tag(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_tag'];
}

function is_feed(): bool
{
    return (bool) $GLOBALS['wgtai_test_context']['is_feed'];
}

function get_queried_object()
{
    return $GLOBALS['wgtai_test_context']['queried_object'] ?? null;
}

/**
 * Honest, not a flag: real WordPress never has is_singular() true at the same
 * time as is_tax()/is_category()/is_tag()/is_feed(), because they describe
 * mutually exclusive query types. The stub used to read an independent
 * 'is_singular' switch that defaulted to true regardless of what else the
 * context claimed to be, which made an archive check vacuous -- the post
 * render service's is_singular() gate would have let it through even while
 * is_tax() was also true.
 */
function is_singular(): bool
{
    return ! (is_tax() || is_category() || is_tag() || is_feed());
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
require_once __DIR__ . '/../modules/weglot/includes/class-wgtai-storage-entity.php';
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

// --- harness self-checks: the three Stage-3-load-bearing harness changes ---
//
// These pin the harness capability itself, independent of any render-service
// behaviour (none is added here): a 2+-arg filter such as WooCommerce's
// woocommerce_taxonomy_archive_description_raw($desc, $term) would have been
// impossible to exercise under the old 2-arg apply_filters() stub, and an
// archive check would have been vacuous under the old is_singular() flag that
// defaulted to true regardless of page type.

$GLOBALS['wgtai_test_filter_returns']['wgtai_test_variadic_probe'] = static function ($value, $a, $b) {
    return $value . '|' . $a . '|' . $b;
};
wgtai_check(
    'apply_filters() passes extra arguments through to a closure override',
    apply_filters('wgtai_test_variadic_probe', 'base', 'lang', 42),
    'base|lang|42'
);
unset($GLOBALS['wgtai_test_filter_returns']['wgtai_test_variadic_probe']);

wgtai_check_true('is_singular() is true by default (no page-type flag set)', is_singular());

$GLOBALS['wgtai_test_context']['is_tax'] = true;
wgtai_check('is_singular() is false while is_tax() is true', is_singular(), false);
$GLOBALS['wgtai_test_context']['is_tax'] = false;

$GLOBALS['wgtai_test_context']['is_category'] = true;
wgtai_check('is_singular() is false while is_category() is true', is_singular(), false);
$GLOBALS['wgtai_test_context']['is_category'] = false;

wgtai_check_true('is_singular() is true again once every page-type flag clears', is_singular());

$GLOBALS['wgtai_test_context']['queried_object'] = 'probe-object';
wgtai_check('get_queried_object() reflects the test context', get_queried_object(), 'probe-object');
$GLOBALS['wgtai_test_context']['queried_object'] = null;

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

// --- PREQUEL: pin today's behaviour of the two seams the upcoming refactor
// changes (fields[] from save(), and the source_post_id key), before any
// production code moves. If these ever fail after the refactor, the refactor
// broke behaviour rather than moving it.
wgtai_check("today's save() fields[] lists title/content/excerpt/meta", $saved['fields'], ['title', 'content', 'excerpt', 'meta']);
wgtai_check_true("today's save() keys the entity id as source_post_id", array_key_exists('source_post_id', $saved));
wgtai_check("today's save() source_post_id matches the post", $saved['source_post_id'], 42);
wgtai_check("today's stored payload itself carries source_post_id", $payload['source_post_id'], 42);

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

// A builder page must never have the payload's `content` printed into it. The
// builder owns the render, so this filter's output only reaches a visitor when
// the builder produced nothing -- and then it lands as raw HTML with the page
// template gone (observed on 24peptides /fr/). Serving the source layout, which
// Weglot still translates, is the better failure.
$storage->save(44, [
    'language' => 'fr',
    'content'  => '<h1>Titre FR</h1><p>corps brut</p>',
    'meta'     => ['_elementor_data' => wgtai_test_elementor_doc('Titre FR', '<p>NL body</p>')],
]);
$GLOBALS['wgtai_test_filters'] = [];
$render_el_content = new WGTAI_Render_Service($languages, $storage);
$render_el_content->resolve_payload();

wgtai_check(
    'raw content is not injected on a builder page',
    $render_el_content->filter_content('<p>builder output</p>'),
    '<p>builder output</p>'
);
wgtai_check_true(
    '...while the builder document is still served to the builder',
    false !== strpos(
        (string) wgtai_test_meta_via_wp(
            $render_el_content->filter_post_metadata(null, 44, '_elementor_data', true),
            true
        ),
        'Titre FR'
    )
);

// Elementor's element cache must be out of the way, or the locale gets whichever
// language rendered FIRST: print_elements() echoes the rendered HTML stored in
// _elementor_element_cache without ever consulting _elementor_data. Measured on
// 24peptides /de/: 22 of 22 body widgets English, all 22 marked notranslate by our
// own exclusions, while <title>/og/meta were correctly German.
wgtai_check_true(
    'element cache disabled for the request on a builder page',
    wgtai_test_filter_registered('option_elementor_element_cache_ttl')
);
wgtai_check(
    '...by forcing the option to disable',
    $render_el_content->filter_element_cache_ttl('24'),
    'disable'
);
wgtai_check_true(
    '...including when the option is unset (default_option)',
    wgtai_test_filter_registered('default_option_elementor_element_cache_ttl')
);
// Elementor reads the cache with get_json_meta() -> get_post_meta($id,$k,true),
// then bails on empty($cache['timeout']). An empty string gets it there.
$cached = wgtai_test_meta_via_wp(
    $render_el_content->filter_post_metadata(null, 44, '_elementor_element_cache', true),
    true
);
wgtai_check('the cached-HTML meta reads back empty', $cached, '');
wgtai_check_true(
    '...so Elementor would re-render instead of echoing the cache',
    empty(is_string($cached) && '' !== $cached ? json_decode($cached, true) : [])
);

// The suppression is scoped to builder payloads: a post_content page still gets
// its translated body, or this guard would blank every classic page.
$storage->save(44, ['language' => 'de', 'content' => '<p>DE Text</p>']);
$GLOBALS['wgtai_test_filters']                 = [];
$GLOBALS['wgtai_test_context']['current_lang'] = 'de';
$render_el_classic = new WGTAI_Render_Service($languages, $storage);
$render_el_classic->resolve_payload();

wgtai_check_true(
    'a payload without a builder document still replaces the body',
    false !== strpos($render_el_classic->filter_content('<p>NL body</p>'), 'DE Text')
);

$GLOBALS['wgtai_test_context']['current_lang'] = 'fr';

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

// --- an unusable structured document must fail the write, not land silently ---
// Observed on 24peptides /fr/: a locale whose _elementor_data was stored
// unusable rendered an EMPTY Elementor document, so Elementor left the_content
// alone and the theme printed the payload's raw `content` HTML on the page. Both
// routes to that state used to return 200 stored:true.
$good_doc = wgtai_test_elementor_doc('Titre FR', '<p>FR body</p>');
$storage->save(44, ['language' => 'fr', 'meta' => ['_elementor_data' => $good_doc], 'title' => 'Bon titre']);
$before_bad = $storage->get(44, 'fr');

// 1. A structured key whose value is not decodable JSON.
$not_json = $storage->save(44, [
    'language' => 'fr',
    'meta'     => ['_elementor_data' => '<p>raw html, not a document</p>'],
]);
wgtai_check_true('a non-JSON builder document is rejected', is_wp_error($not_json));
wgtai_check('...with a code the caller can branch on', $not_json->get_error_code(), 'wgtai_invalid_structured_meta');
wgtai_check('...naming the offending meta key', $not_json->get_error_data()['meta_key'], '_elementor_data');
wgtai_check_true(
    '...and the last good payload is left untouched',
    $storage->get(44, 'fr') === $before_bad
);

// 2. A document that decodes but cannot be re-encoded after sanitisation.
//    Invalid UTF-8 is the realistic trigger (wp_json_encode() returns false and
//    the old code stored '' for the key). It has to arrive as an ARRAY to reach
//    this branch: as a string it would fail json_decode first and be caught
//    above, which is why the previous fixture never exercised this guard.
$encode_failed = $storage->save(44, [
    'language' => 'fr',
    'meta'     => ['_elementor_data' => [['id' => 'u1', 'settings' => ['title' => "b\xB0d"]]]],
]);
wgtai_check_true('a document that cannot be re-encoded is rejected', is_wp_error($encode_failed));
wgtai_check(
    '...with the encode-failure code, not the decode one',
    $encode_failed->get_error_code(),
    'wgtai_structured_encode_failed'
);
wgtai_check_true(
    '...and it too leaves the stored payload alone',
    $storage->get(44, 'fr') === $before_bad
);

// 3. The rejection is per-request, not sticky: a good document still stores.
$recovered = $storage->save(44, ['language' => 'fr', 'meta' => ['_elementor_data' => $good_doc]]);
wgtai_check_true('a valid document still stores after a rejection', ! is_wp_error($recovered));
wgtai_check_true(
    '...and Elementor would read a non-empty tree back',
    ! empty(json_decode($storage->get(44, 'fr')['meta']['_elementor_data'], true))
);

// ---------------------------------------------------------------------------
// A human edits the source post AFTER we stored translations
// ---------------------------------------------------------------------------
// The worry these checks answer: someone changes one word in the source post and
// Weglot re-translates the page, destroying our copy.
//
// It cannot destroy it. This module registers no save_post hook and payloads are
// written only through the REST endpoint, so nothing an editor (or Weglot, which
// never writes to the database at all) does can alter what we stored.
//
// What an edit DOES move is the Elementor exclusion set, because that is diffed
// against the LIVE document at render time. The consequence is asserted below
// and is the reason a source edit needs an operational answer, not just a
// reassurance: an element we did not translate flips to excluded, so Weglot
// stops translating it and the source language is stranded on the locale page.

function wgtai_test_render_for(int $post_id, string $language, $languages, $storage): WGTAI_Render_Service
{
    $GLOBALS['wgtai_test_filters']                 = [];
    $GLOBALS['wgtai_test_context']['current_lang'] = $language;
    $GLOBALS['wgtai_test_context']['queried_id']   = $post_id;
    $GLOBALS['wgtai_test_context']['current_id']   = $post_id;

    $render = new WGTAI_Render_Service($languages, $storage);
    $render->resolve_payload();

    return $render;
}

$edit_source_body = '<p>EN body</p>';
wgtai_test_seed_post(45, 'edited-pagina', 'Edited pagina');
$GLOBALS['wgtai_test_meta'][45]['_elementor_data'] = wgtai_test_elementor_doc('EN heading', $edit_source_body);

// We translated the heading only. The text editor still carries source copy, so
// Weglot is supposed to machine-translate that one element.
$storage->save(45, [
    'language' => 'fr',
    'title'    => 'Page FR',
    'meta'     => ['_elementor_data' => wgtai_test_elementor_doc('Titre FR', $edit_source_body)],
]);

$edit_before = wgtai_test_render_for(45, 'fr', $languages, $storage)->filter_exclude_blocks([]);
wgtai_check_true('before any edit: our translated heading is excluded', in_array('.elementor-element-hd1', $edit_before, true));
wgtai_check(
    'before any edit: Weglot still translates the element we left alone',
    in_array('.elementor-element-tx1', $edit_before, true),
    false
);

// The edit: one word changed, in the element we did NOT translate.
$GLOBALS['wgtai_test_meta'][45]['_elementor_data'] = wgtai_test_elementor_doc('EN heading', '<p>EN body, reworded</p>');
$edit_render = wgtai_test_render_for(45, 'fr', $languages, $storage);
$edit_after  = $edit_render->filter_exclude_blocks([]);
$edit_stored = json_decode($storage->get(45, 'fr')['meta']['_elementor_data'], true);

// 1. The reassuring half: the edit cannot reach what we stored.
wgtai_check(
    'a source edit leaves our stored translation alone',
    $edit_stored[0]['elements'][0]['elements'][0]['settings']['title'],
    'Titre FR'
);
wgtai_check_true('the language index survives a source edit', in_array('fr', $storage->get_stored_languages(45), true));

// 2. The edit does not reach the locale page either -- we serve the stored
//    document verbatim, so the locale silently keeps pre-edit copy.
wgtai_check(
    'the edited wording never reaches the locale page',
    wgtai_test_meta_via_wp($edit_render->filter_post_metadata(null, 45, '_elementor_data', true), true),
    $storage->get(45, 'fr')['meta']['_elementor_data']
);
wgtai_check(
    'the locale page still serves the pre-edit source body',
    json_decode(wgtai_test_meta_via_wp($edit_render->filter_post_metadata(null, 45, '_elementor_data', true), true), true)[0]['elements'][0]['elements'][1]['settings']['editor'],
    $edit_source_body
);

// 3. The damaging half: the edited element now differs from our stored copy, so
//    it is excluded and Weglot stops translating it. One word changed in the
//    source turns a machine-translated element into raw source language on the
//    locale page. Asserted so the behaviour is pinned, not endorsed.
wgtai_check_true(
    'edit-sensitivity: the edited element flips to excluded',
    in_array('.elementor-element-tx1', $edit_after, true)
);
wgtai_check_true('our own element stays excluded across the edit', in_array('.elementor-element-hd1', $edit_after, true));

// 4. The mirror case: an edit that makes the source match our translation
//    un-excludes it, and Weglot re-translates our own copy.
$GLOBALS['wgtai_test_meta'][45]['_elementor_data'] = wgtai_test_elementor_doc('Titre FR', $edit_source_body);
$edit_collide = wgtai_test_render_for(45, 'fr', $languages, $storage)->filter_exclude_blocks([]);
wgtai_check(
    'edit-sensitivity: a source edit matching our copy un-excludes it',
    in_array('.elementor-element-hd1', $edit_collide, true),
    false
);

// 5. post_content pages carry no such sensitivity: filter_content replaces the
//    body wholesale and the .nova-weglot-i18n wrapper is excluded unconditionally,
//    so an edit costs staleness only -- never stranded source language.
wgtai_test_seed_post(46, 'edited-klassiek', 'Edited klassiek');
$GLOBALS['wgtai_test_posts'][46]->post_content = '<p>EN body</p>';
$storage->save(46, ['language' => 'fr', 'title' => 'Page FR', 'content' => '<p>Corps FR</p>']);

$classic_render = wgtai_test_render_for(46, 'fr', $languages, $storage);
$classic_before = $classic_render->filter_content('<p>EN body</p>');
$GLOBALS['wgtai_test_posts'][46]->post_content = '<p>EN body, reworded</p>';
$classic_render = wgtai_test_render_for(46, 'fr', $languages, $storage);
$classic_after  = $classic_render->filter_content('<p>EN body, reworded</p>');

wgtai_check('a post_content edit does not change what we serve', $classic_after, $classic_before);
wgtai_check_true('our copy is still what renders', str_contains($classic_after, 'Corps FR'));
wgtai_check(
    'the edited wording never reaches the locale page',
    str_contains($classic_after, 'reworded'),
    false
);
wgtai_check_true(
    'the wrapper is excluded regardless of the edit',
    in_array('.nova-weglot-i18n', $classic_render->filter_exclude_blocks([]), true)
);
wgtai_check(
    'no element-level exclusions to shift on a post_content page',
    (bool) preg_grep('/^\.elementor-element-/', $classic_render->filter_exclude_blocks([])),
    false
);

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

// ---------------------------------------------------------------------------
// Stage 1b: term storage (WGTAI_Storage_Service::save_term() and its peers)
//
// One class, no subclass: save_term() differs from save() only in which
// WGTAI_Storage_Entity descriptor it threads through build_payload(). These
// checks prove that descriptor swap cannot leak between entities and that the
// term contract matches the post one everywhere it is supposed to.
// ---------------------------------------------------------------------------

// --- cross-entity isolation: same numeric id, two different meta tables ----
//
// term_id and post_id are independent WordPress id spaces, so nothing stops a
// term and a post from sharing a numeric id. The mutation this guards against:
// term_entity()'s read_meta/write_meta seams pointed at get_post_meta()/
// update_post_meta() instead of the term functions, or vice versa for posts.
// wgtai_test_termmeta and wgtai_test_meta are separate PHP arrays specifically
// so that mistake reads back wrong (or empty) data here instead of accidentally
// finding the right value because both stores share one array.

wgtai_test_seed_term(90, 'product_cat', 'vloerverwarming-cat', 'Vloerverwarming');
wgtai_test_seed_post(90, 'niet-de-term', 'Niet de term');

$term_90 = $storage->save_term(90, ['language' => 'fr', 'name' => 'Chauffage au sol (terme)']);
$post_90 = $storage->save(90, ['language' => 'fr', 'title' => 'Pas le terme']);

wgtai_check('save_term for id 90 succeeds', is_wp_error($term_90), false);
wgtai_check('save for the post sharing id 90 succeeds', is_wp_error($post_90), false);
wgtai_check(
    'a term payload does not leak into the post service for the same numeric id',
    $storage->get(90, 'fr')['title'] ?? null,
    'Pas le terme'
);
wgtai_check(
    'a post payload does not leak into the term service for the same numeric id',
    $storage->get_term(90, 'fr')['name'] ?? null,
    'Chauffage au sol (terme)'
);
wgtai_check('the post index for id 90 is unaffected by the term of the same id', $storage->get_stored_languages(90), ['fr']);
wgtai_check('the term index for id 90 is unaffected by the post of the same id', $storage->get_term_stored_languages(90), ['fr']);

// --- the full save_term() contract: fields[], omit/null/value, slug, url ----

wgtai_test_seed_term(91, 'product_cat', 'warmtepompen-91', 'Warmtepompen', 'NL beschrijving');

$term_91 = $storage->save_term(91, [
    'language'    => 'fr',
    'name'        => 'Pompes à chaleur',
    'description' => '<p>FR</p><script>alert(1)</script>',
]);

wgtai_check('save_term succeeds', is_wp_error($term_91), false);
wgtai_check('save_term keys the entity id as source_term_id', $term_91['source_term_id'], 91);
wgtai_check_true('save_term reports stored', $term_91['stored']);
wgtai_check_true('first save_term is a create', $term_91['created']);
wgtai_check("save_term's fields[] lists name/description, not meta", $term_91['fields'], ['name', 'description']);
wgtai_check(
    "save_term's url is the real archive url",
    $term_91['url'],
    'https://example.com/fr-be/product_cat/warmtepompen-91/'
);

$term_91_payload = $storage->get_term(91, 'fr');
wgtai_check('term description is sanitised like post content', $term_91_payload['description'], '<p>FR</p>');

// omit/null/value contract, same as posts (:230-240)
$storage->save_term(91, ['language' => 'fr', 'name' => 'Pompes a chaleur v2']);
$term_91_partial = $storage->get_term(91, 'fr');
wgtai_check('a partial term update replaces the supplied field', $term_91_partial['name'], 'Pompes a chaleur v2');
wgtai_check('a partial term update keeps the description', $term_91_partial['description'], '<p>FR</p>');

$storage->save_term(91, ['language' => 'fr', 'description' => null]);
wgtai_check('null clears a term field', isset($storage->get_term(91, 'fr')['description']), false);
wgtai_check('clearing description leaves the name', $storage->get_term(91, 'fr')['name'], 'Pompes a chaleur v2');

// slug: recorded, reported, never routed -- same contract as posts (:266-269)
$term_91_slug = $storage->save_term(91, ['language' => 'fr', 'slug' => 'Nouvelle Slug']);
wgtai_check('term slug is sanitised and parked', $storage->get_term(91, 'fr')['requested_slug'], 'nouvelle-slug');
wgtai_check('the parked term slug never appears in fields[]', in_array('requested_slug', $term_91_slug['fields'], true), false);

// ordinary term meta still merges normally
$storage->save_term(91, ['language' => 'fr', 'meta' => ['blog_intro' => 'Intro FR term']]);
wgtai_check('ordinary term meta stores normally', $storage->get_term(91, 'fr')['meta']['blog_intro'], 'Intro FR term');

// review item 3: the term descriptor's empty structured list makes a
// page-builder meta key a hard rejection, not quiet sanitisation with no
// notranslate protection.
$term_structured = $storage->save_term(91, ['language' => 'fr', 'meta' => ['_elementor_data' => '{"ok":true}']]);
wgtai_check_true('a term payload cannot carry a page-builder document', is_wp_error($term_structured));
wgtai_check(
    '...with a code the caller can branch on',
    is_wp_error($term_structured) ? $term_structured->get_error_code() : null,
    'wgtai_structured_meta_not_supported'
);
wgtai_check(
    '...naming the offending meta key',
    is_wp_error($term_structured) ? $term_structured->get_error_data()['meta_key'] : null,
    '_elementor_data'
);
wgtai_check(
    '...and the term is left exactly as it was before the rejected write',
    $storage->get_term(91, 'fr')['name'] ?? null,
    'Pompes a chaleur v2'
);
wgtai_check('...and nothing about the rejected key was written', isset($storage->get_term(91, 'fr')['meta']['_elementor_data']), false);

// unknown language -> wgtai_unknown_language, nothing written
$term_unknown = $storage->save_term(91, ['language' => 'es', 'name' => 'x']);
wgtai_check_true('save_term rejects an unconfigured language', is_wp_error($term_unknown));
wgtai_check('...with the unknown-language code', $term_unknown->get_error_code(), 'wgtai_unknown_language');
wgtai_check('...and nothing was written for es', $storage->get_term(91, 'es'), null);

// missing term -> wgtai_missing_term
$term_missing = $storage->save_term(999999, ['language' => 'fr', 'name' => 'x']);
wgtai_check_true('save_term rejects an unknown term', is_wp_error($term_missing));
wgtai_check('...with the missing-term code', $term_missing->get_error_code(), 'wgtai_missing_term');

// delete_term
wgtai_check_true('delete_term removes a locale', $storage->delete_term(91, 'fr'));
wgtai_check('term payload is gone after delete_term', $storage->get_term(91, 'fr'), null);
wgtai_check('term index shrinks after delete_term', $storage->get_term_stored_languages(91), []);

// ---------------------------------------------------------------------------
// Stage 2: REST -- retiring the 501 (WGTAI_REST_Controller)
// ---------------------------------------------------------------------------

$rest = new WGTAI_REST_Controller($storage, $languages);

wgtai_check('the 501 stub is gone', method_exists($rest, 'terms_not_supported'), false);

// --- permissions_check: the term branch must precede the post branch -------
//
// Review item 10's mutant: an id that is BOTH a valid term id and an editable
// post id must still be denied on a /terms route when the caller lacks the
// term capability. Post 42 already exists (seeded at the top of this file);
// term 42 is seeded fresh here, in a taxonomy table posts never touch. If the
// term branch were dropped (or evaluated after the post branch), both
// requests below fall through to the post branch's id/source_post_id lookup,
// find no such param, and answer current_user_can('edit_posts') === true --
// flipping every check below from false to true.

wgtai_test_seed_term(42, 'product_cat', 'bestaande-term', 'Bestaande term');

$GLOBALS['wgtai_test_denied_caps'] = ['edit_term', 'edit_terms', 'manage_categories'];

$collision_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$collision_request->set_param('source_term_id', 42);
$collision_request->set_param('taxonomy', 'product_cat');
$collision_request->set_param('translations', [['language' => 'fr', 'name' => 'x']]);

wgtai_check(
    'permissions_check denies POST /terms even when the same numeric id is an editable post',
    $rest->permissions_check($collision_request),
    false
);

$collision_get_request = new WP_REST_Request('GET', '/weglot-translations/v1/terms/42/translations');
$collision_get_request->set_param('id', 42);
$collision_get_request->set_param('taxonomy', 'product_cat');

wgtai_check(
    'permissions_check denies GET .../terms/{id} even when the same numeric id is an editable post',
    $rest->permissions_check($collision_get_request),
    false
);

$no_id_term_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$no_id_term_request->set_param('taxonomy', 'product_cat');
$no_id_term_request->set_param('translations', [['language' => 'fr', 'name' => 'y']]);

wgtai_check(
    "permissions_check denies a user without the taxonomy's edit_terms cap",
    $rest->permissions_check($no_id_term_request),
    false
);

$GLOBALS['wgtai_test_denied_caps'] = [];

wgtai_check_true('permissions_check allows a user with the taxonomy cap', $rest->permissions_check($no_id_term_request));

$no_taxonomy_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$no_taxonomy_request->set_param('translations', [['language' => 'fr', 'name' => 'z']]);

wgtai_check('permissions_check denies a /terms request with no taxonomy', $rest->permissions_check($no_taxonomy_request), false);

$post_permission_request = new WP_REST_Request('POST', '/weglot-translations/v1/posts');
$post_permission_request->set_param('source_post_id', 42);

wgtai_check_true('permissions_check still allows editing a post (the post branch is not weakened)', $rest->permissions_check($post_permission_request));

// --- create_term_translations: happy path, mixed batch, wrong taxonomy -----

wgtai_test_seed_term(81, 'product_cat', 'cat-81', 'Categorie 81 NL');

$clean_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$clean_request->set_param('source_term_id', 81);
$clean_request->set_param('taxonomy', 'product_cat');
$clean_request->set_param('translations', [['language' => 'fr', 'name' => 'Categorie 81 FR']]);

$clean_response = $rest->create_term_translations($clean_request);
wgtai_check('an all-good batch answers 200', $clean_response->get_status(), 200);
wgtai_check('an all-good batch reports no errors', $clean_response->get_data()['errors'], []);

wgtai_test_seed_term(80, 'product_cat', 'cat-80', 'Categorie NL', 'Beschrijving NL');

$create_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$create_request->set_param('source_term_id', 80);
$create_request->set_param('taxonomy', 'product_cat');
$create_request->set_param('translations', [
    ['language' => 'fr', 'name' => 'Categorie FR', 'description' => '<p>FR</p>'],
    ['language' => 'zz', 'name' => 'Nope'],
]);

$create_response = $rest->create_term_translations($create_request);

wgtai_check_true('POST /terms no longer 501s', $create_response instanceof WP_REST_Response);
wgtai_check('a mixed batch answers 207', $create_response->get_status(), 207);
wgtai_check('...with exactly the good language in results[]', count($create_response->get_data()['results']), 1);
wgtai_check('...and the failing language in errors[]', $create_response->get_data()['errors'][0]['language'], 'zz');
wgtai_check('...naming the right error code', $create_response->get_data()['errors'][0]['code'], 'wgtai_unknown_language');

$good_result = $create_response->get_data()['results'][0];
wgtai_check('the good result reports source_term_id', $good_result['source_term_id'], 80);
wgtai_check('the good result normalizes the language', $good_result['language'], 'fr');
wgtai_check('the good result lists name+description in fields[]', $good_result['fields'], ['name', 'description']);
wgtai_check('the good result reports the real archive url, not a slug', $good_result['url'], 'https://example.com/fr-be/product_cat/cat-80/');
wgtai_check_true('the good result reports stored', $good_result['stored']);
wgtai_check_true('the good result is a create', $good_result['created']);
wgtai_check('no ignored fields when parent_id is absent', $good_result['ignored_fields'], []);

$wrong_tax_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$wrong_tax_request->set_param('source_term_id', 80);
$wrong_tax_request->set_param('taxonomy', 'category');
$wrong_tax_request->set_param('translations', [['language' => 'fr', 'name' => 'x']]);

$wrong_tax_response = $rest->create_term_translations($wrong_tax_request);
wgtai_check_true('a term in the wrong taxonomy is rejected', is_wp_error($wrong_tax_response));
wgtai_check('...with a 404', is_wp_error($wrong_tax_response) ? $wrong_tax_response->get_error_data()['status'] : null, 404);
wgtai_check('...with a taxonomy-mismatch code', is_wp_error($wrong_tax_response) ? $wrong_tax_response->get_error_code() : null, 'wgtai_taxonomy_mismatch');

$missing_term_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$missing_term_request->set_param('source_term_id', 987654);
$missing_term_request->set_param('taxonomy', 'product_cat');
$missing_term_request->set_param('translations', [['language' => 'fr', 'name' => 'x']]);

$missing_term_response = $rest->create_term_translations($missing_term_request);
wgtai_check_true('a nonexistent term 404s', is_wp_error($missing_term_response));
wgtai_check(
    '...with the missing-term code',
    is_wp_error($missing_term_response) ? $missing_term_response->get_error_code() : null,
    'wgtai_missing_term'
);

// Review item 9: every locale rejected must still answer 207 with zero
// results, not a bare top-level error that hides which locales failed and why.
$all_bad_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$all_bad_request->set_param('source_term_id', 80);
$all_bad_request->set_param('taxonomy', 'product_cat');
$all_bad_request->set_param('translations', [
    ['language' => 'zz', 'name' => 'a'],
    ['language' => 'yy', 'name' => 'b'],
]);
$all_bad_response = $rest->create_term_translations($all_bad_request);

wgtai_check('an all-rejected batch still answers 207, not a bare error', $all_bad_response->get_status(), 207);
wgtai_check('...with zero results', $all_bad_response->get_data()['results'], []);
wgtai_check('...and every language accounted for in errors[]', count($all_bad_response->get_data()['errors']), 2);

// --- parent_id: accepted, ignored, and named --------------------------------

$parent_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$parent_request->set_param('source_term_id', 80);
$parent_request->set_param('taxonomy', 'product_cat');
$parent_request->set_param('translations', [
    ['language' => 'de', 'name' => 'Kategorie DE', 'parent_id' => 99],
]);
$parent_response = $rest->create_term_translations($parent_request);

wgtai_check('parent_id is reported in ignored_fields', $parent_response->get_data()['results'][0]['ignored_fields'], ['parent_id']);
wgtai_check('parent_id is never stored on the term payload', array_key_exists('parent_id', $storage->get_term(80, 'de') ?? []), false);

// --- slug: recorded, reported, never routed; url is the real archive url ---

$slug_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$slug_request->set_param('source_term_id', 80);
$slug_request->set_param('taxonomy', 'product_cat');
$slug_request->set_param('translations', [
    ['language' => 'de', 'name' => 'Kategorie DE', 'slug' => 'Andere Slug'],
]);
$slug_response = $rest->create_term_translations($slug_request);

wgtai_check('the requested term slug is recorded', $storage->get_term(80, 'de')['requested_slug'], 'andere-slug');
wgtai_check(
    'the requested term slug never appears in fields[]',
    in_array('requested_slug', $slug_response->get_data()['results'][0]['fields'], true),
    false
);
wgtai_check(
    'results[].url is the real archive url, never the requested slug',
    $slug_response->get_data()['results'][0]['url'],
    'https://example.com/de/product_cat/cat-80/'
);

// --- contract_notes(): term-specific line present ---------------------------

wgtai_check_true(
    'term responses carry a term-specific note about url vs. slug',
    in_array(
        'For terms, results[].url is always the real taxonomy archive URL for this term and language, never the requested slug - write that value to public.url, not the slug field.',
        $create_response->get_data()['notes'],
        true
    )
);

// --- get_term_translations / delete_term_translation ------------------------

$get_request = new WP_REST_Request('GET', '/weglot-translations/v1/terms/80/translations');
$get_request->set_param('id', 80);
$get_request->set_param('taxonomy', 'product_cat');

$get_response = $rest->get_term_translations($get_request);
wgtai_check_true('GET /terms/{id}/translations succeeds', $get_response instanceof WP_REST_Response);
wgtai_check('GET reports the taxonomy', $get_response->get_data()['taxonomy'], 'product_cat');

$get_listing = $get_response->get_data()['translations'];
$get_fr      = null;

foreach ($get_listing as $get_item) {
    if (! empty($get_item['language']) && 'fr' === $get_item['language']) {
        $get_fr = $get_item;
    }
}

wgtai_check_true('GET lists the stored fr translation', null !== $get_fr && ! empty($get_fr['stored']));
wgtai_check('GET reports the real archive url for fr', $get_fr['url'] ?? null, 'https://example.com/fr-be/product_cat/cat-80/');

$get_mismatch_request = new WP_REST_Request('GET', '/weglot-translations/v1/terms/80/translations');
$get_mismatch_request->set_param('id', 80);
$get_mismatch_request->set_param('taxonomy', 'category');

$get_mismatch_response = $rest->get_term_translations($get_mismatch_request);
wgtai_check_true('GET on the wrong taxonomy 404s', is_wp_error($get_mismatch_response));
wgtai_check(
    '...with status 404',
    is_wp_error($get_mismatch_response) ? $get_mismatch_response->get_error_data()['status'] : null,
    404
);

$delete_request = new WP_REST_Request('DELETE', '/weglot-translations/v1/terms/80/translations/fr');
$delete_request->set_param('id', 80);
$delete_request->set_param('language', 'fr');
$delete_request->set_param('taxonomy', 'product_cat');

$delete_response = $rest->delete_term_translation($delete_request);
wgtai_check('DELETE term translation reports 200', $delete_response->get_status(), 200);
wgtai_check_true('DELETE term translation reports deleted', $delete_response->get_data()['deleted']);
wgtai_check('the payload is gone after DELETE', $storage->get_term(80, 'fr'), null);

$GLOBALS['wgtai_test_denied_caps'] = [];

// ---------------------------------------------------------------------------
// Verification pass (adversarial): the wire contract exactly as n8n sends it,
// the registered route surface, what a rejection leaves behind in the store,
// idempotence, and raw cross-store isolation.
// ---------------------------------------------------------------------------

// --- the registered route surface -------------------------------------------
//
// The flow POSTs to https://{domain}/wp-json/weglot-translations/v1/terms, and
// the bridge is ALREADY installed on both client sites at the 501-stub build,
// where /terms answers with methods ["GET","POST"] and no args. Namespace, path
// and method are therefore a live contract, not an internal detail: a typo here
// is a posting run that 404s on all 14 category rows. The /posts, /languages
// and /posts/{id}/translations assertions are deliberate regression guards for
// routes the flow already depends on -- they pass at fd8a14d on purpose,
// exactly like the prequel checks.

$GLOBALS['wgtai_test_registered_routes'] = [];
$rest->register_routes();

$wgtai_routes = [];

foreach ($GLOBALS['wgtai_test_registered_routes'] as $wgtai_route_entry) {
    $wgtai_routes[$wgtai_route_entry['namespace'] . $wgtai_route_entry['route']] = $wgtai_route_entry['args'][0] ?? [];
}

$terms_route    = 'weglot-translations/v1/terms';
$term_get_route = 'weglot-translations/v1/terms/(?P<id>\\d+)/translations';
$term_del_route = 'weglot-translations/v1/terms/(?P<id>\\d+)/translations/(?P<language>[A-Za-z0-9_-]+)';

wgtai_check('POST /terms is registered at exactly the path the flow posts to', isset($wgtai_routes[$terms_route]), true);
wgtai_check('/terms accepts POST', $wgtai_routes[$terms_route]['methods'] ?? null, 'POST');
wgtai_check('/terms dispatches to create_term_translations', $wgtai_routes[$terms_route]['callback'][1] ?? null, 'create_term_translations');
wgtai_check('/terms is still permission-gated', $wgtai_routes[$terms_route]['permission_callback'][1] ?? null, 'permissions_check');
wgtai_check_true('/terms now declares args (the live 501 stub declares none)', ! empty($wgtai_routes[$terms_route]['args']));
wgtai_check('GET /terms/{id}/translations is registered', $wgtai_routes[$term_get_route]['methods'] ?? null, 'GET');
wgtai_check('DELETE /terms/{id}/translations/{language} is registered', $wgtai_routes[$term_del_route]['methods'] ?? null, 'DELETE');

// Regression guards for the routes the flow already uses.
wgtai_check('POST /posts is still registered', $wgtai_routes['weglot-translations/v1/posts']['methods'] ?? null, 'POST');
wgtai_check('GET /posts/{id}/translations is still registered', $wgtai_routes['weglot-translations/v1/posts/(?P<id>\\d+)/translations']['methods'] ?? null, 'GET');
wgtai_check('...and still dispatches to get_post_translations', $wgtai_routes['weglot-translations/v1/posts/(?P<id>\\d+)/translations']['callback'][1] ?? null, 'get_post_translations');
wgtai_check('GET /languages is still registered', $wgtai_routes['weglot-translations/v1/languages']['methods'] ?? null, 'GET');
wgtai_check_true('the /posts route still declares source_post_id', isset($wgtai_routes['weglot-translations/v1/posts']['args']['source_post_id']));

$wgtai_namespaces = [];

foreach ($GLOBALS['wgtai_test_registered_routes'] as $wgtai_route_entry) {
    $wgtai_namespaces[$wgtai_route_entry['namespace']] = true;
}

wgtai_check('every route lives in the one namespace the flow URL uses', array_keys($wgtai_namespaces), ['weglot-translations/v1']);

// --- get_term_endpoint_args(): the flow must not be 400'd over a key we
// --- accept but do not store.
//
// Parse translations emits content_below_products twice -- once at the top
// level of each translation and once inside meta. WP only rejects unknown
// object properties when a schema sets additionalProperties => false, so the
// absence of that flag anywhere in this tree is the whole reason the flow's
// request validates. Asserted structurally because the harness has no copy of
// rest_validate_value_from_schema().

$term_args_reflection = new ReflectionMethod('WGTAI_REST_Controller', 'get_term_endpoint_args');
$term_args_reflection->setAccessible(true);
$term_args = $term_args_reflection->invoke($rest);

$wgtai_required_top = [];

foreach ($term_args as $arg_name => $arg_spec) {
    if (! empty($arg_spec['required'])) {
        $wgtai_required_top[] = $arg_name;
    }
}

wgtai_check('the term endpoint requires exactly what the flow sends', $wgtai_required_top, ['source_term_id', 'taxonomy', 'translations']);
wgtai_check('trid is accepted but never required (the term branch does not send it)', ! empty($term_args['trid']['required']), false);
wgtai_check('translations[] requires only language', $term_args['translations']['items']['required'] ?? null, ['language']);

$wgtai_has_closed_object = static function (array $node) use (&$wgtai_has_closed_object): bool {
    if (array_key_exists('additionalProperties', $node) && false === $node['additionalProperties']) {
        return true;
    }

    foreach ($node as $child) {
        if (is_array($child) && $wgtai_has_closed_object($child)) {
            return true;
        }
    }

    return false;
};

wgtai_check(
    'no term-args schema closes its object, so the flow extra content_below_products cannot 400',
    $wgtai_has_closed_object($term_args),
    false
);

// --- the wire contract, byte for byte as Parse translations builds it -------

wgtai_test_seed_term(120, 'product_cat', 'cat-120', 'Category 120 EN', 'EN description');

$flow_request = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$flow_request->set_param('source_term_id', 120);
$flow_request->set_param('taxonomy', 'product_cat');
$flow_request->set_param('translations', [
    [
        'language'               => 'de',
        'name'                   => 'Kategorie 120',
        'slug'                   => 'Kategorie 120 Slug',
        'description'            => '<p>DE Beschreibung</p>',
        'content_below_products' => '<p>DE unten (top level)</p>',
        'meta'                   => [
            '_yoast_wpseo_title'     => 'DE titel',
            '_yoast_wpseo_metadesc'  => 'DE meta description',
            'content_below_products' => '<p>DE unten (meta)</p>',
        ],
    ],
]);

$flow_response = $rest->create_term_translations($flow_request);

wgtai_check_true('the flow request shape is accepted, not rejected', $flow_response instanceof WP_REST_Response);
wgtai_check('the flow request answers 200', $flow_response->get_status(), 200);

$flow_data   = $flow_response->get_data();
$flow_result = $flow_data['results'][0] ?? [];

// The fields Registered Translations To Status and Collect Live URLs read.
wgtai_check_true('results[].language is present for the flow to key on', array_key_exists('language', $flow_result));
wgtai_check_true('results[].stored is present for the flow to key on', array_key_exists('stored', $flow_result));
wgtai_check_true('results[].url is present for Collect Live URLs', array_key_exists('url', $flow_result));
wgtai_check('errors[] is always an array, even when empty', $flow_data['errors'], []);

$flow_payload = $storage->get_term(120, 'de');

wgtai_check('the Yoast title from the flow lands in meta', $flow_payload['meta']['_yoast_wpseo_title'] ?? null, 'DE titel');
wgtai_check('the Yoast meta description from the flow lands in meta', $flow_payload['meta']['_yoast_wpseo_metadesc'] ?? null, 'DE meta description');
wgtai_check('the meta content_below_products from the flow is stored', $flow_payload['meta']['content_below_products'] ?? null, '<p>DE unten (meta)</p>');

// The top-level spelling is accepted and dropped: it must NOT be stored a
// second time as a payload field, which would give one region two stored
// shapes and two contradictory reports in fields[].
wgtai_check('the top-level content_below_products is not stored as a payload field', array_key_exists('content_below_products', $flow_payload), false);
wgtai_check('...and does not appear in fields[]', in_array('content_below_products', $flow_result['fields'], true), false);

// results[].url goes straight into public.url, so a requested slug echoed
// there is a fiction in the database, not a cosmetic defect.
wgtai_check('results[].url carries no trace of the requested slug', strpos((string) $flow_result['url'], 'kategorie-120-slug'), false);
wgtai_check('results[].url is the real archive URL for the language', $flow_result['url'], 'https://example.com/de/product_cat/cat-120/');

// --- what a rejection leaves behind, read from the store, not from the
// --- return value.

wgtai_test_seed_term(121, 'product_cat', 'cat-121', 'Category 121 EN');

$store_before_missing = $GLOBALS['wgtai_test_termmeta'];

$reject_missing = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$reject_missing->set_param('source_term_id', 987654);
$reject_missing->set_param('taxonomy', 'product_cat');
$reject_missing->set_param('translations', [['language' => 'de', 'name' => 'Nichts']]);
$rest->create_term_translations($reject_missing);

wgtai_check('a missing term creates no termmeta row at all', isset($GLOBALS['wgtai_test_termmeta'][987654]), false);
wgtai_check('...and leaves every other stored term untouched', $GLOBALS['wgtai_test_termmeta'], $store_before_missing);

$storage->save_term(121, ['language' => 'de', 'name' => 'Kategorie 121']);
$store_before_mismatch = $GLOBALS['wgtai_test_termmeta'][121];

$reject_taxonomy = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$reject_taxonomy->set_param('source_term_id', 121);
$reject_taxonomy->set_param('taxonomy', 'category');
$reject_taxonomy->set_param('translations', [['language' => 'fr', 'name' => 'Wrong taxonomy']]);
$rest->create_term_translations($reject_taxonomy);

wgtai_check('a wrong-taxonomy request writes nothing to the term meta', $GLOBALS['wgtai_test_termmeta'][121], $store_before_mismatch);
wgtai_check('...and no payload key exists for the language it named', isset($GLOBALS['wgtai_test_termmeta'][121]['_nova_weglot_i18n_fr']), false);

$reject_language = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$reject_language->set_param('source_term_id', 121);
$reject_language->set_param('taxonomy', 'product_cat');
$reject_language->set_param('translations', [['language' => 'es', 'name' => 'Nada']]);
$rest->create_term_translations($reject_language);

wgtai_check('an unknown language writes no payload key', isset($GLOBALS['wgtai_test_termmeta'][121]['_nova_weglot_i18n_es']), false);
wgtai_check('...and does not enter the stored-language index', $GLOBALS['wgtai_test_termmeta'][121]['_nova_weglot_languages'], ['de']);

$reject_builder = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$reject_builder->set_param('source_term_id', 121);
$reject_builder->set_param('taxonomy', 'product_cat');
$reject_builder->set_param('translations', [['language' => 'de', 'meta' => ['_elementor_data' => '{"ok":true}']]]);
$reject_builder_response = $rest->create_term_translations($reject_builder);

wgtai_check('a builder meta key is refused over REST too, as a 207', $reject_builder_response->get_status(), 207);
wgtai_check('...with the structured-meta code in errors[]', $reject_builder_response->get_data()['errors'][0]['code'] ?? null, 'wgtai_structured_meta_not_supported');
wgtai_check('...and the stored payload untouched in the store itself', $GLOBALS['wgtai_test_termmeta'][121], $store_before_mismatch);

// --- idempotence: a repeated save changes nothing observable but the clock --

wgtai_test_seed_term(122, 'product_cat', 'cat-122', 'Category 122 EN');

$idem_payload = ['language' => 'de', 'name' => 'Kategorie 122', 'description' => '<p>DE</p>'];
$idem_first   = $storage->save_term(122, $idem_payload);
$idem_stored1 = $storage->get_term(122, 'de');
$idem_second  = $storage->save_term(122, $idem_payload);
$idem_stored2 = $storage->get_term(122, 'de');

wgtai_check_true('the first save_term of the pair is a create', $idem_first['created']);
wgtai_check('a repeated identical save is not a create', $idem_second['created'], false);
wgtai_check_true('a repeated identical save still reports stored', $idem_second['stored']);
wgtai_check('a repeated identical save reports the same fields[]', $idem_second['fields'], $idem_first['fields']);
wgtai_check('a repeated identical save reports the same url', $idem_second['url'], $idem_first['url']);

unset($idem_stored1['updated_at'], $idem_stored2['updated_at']);
wgtai_check('a repeated identical save changes nothing but updated_at', $idem_stored2, $idem_stored1);
wgtai_check('a repeated identical save adds no second meta row', count($GLOBALS['wgtai_test_termmeta'][122]), 2);

// The reader dedupes the index, so a writer that appended unconditionally would
// be invisible through get_term_stored_languages(); the stored row itself would
// still grow by one entry on every re-post. Assert the raw row, not the reader.
wgtai_check(
    'a repeated identical save does not duplicate the raw stored-language index',
    $GLOBALS['wgtai_test_termmeta'][122]['_nova_weglot_languages'],
    ['de']
);

// --- raw cross-store isolation ----------------------------------------------
//
// The service-level leakage checks above go through get()/get_term(). These
// read the two stub stores directly, so a seam wired to the wrong table is
// caught even where the read-back guard would have masked it by returning a
// WP_Error rather than a wrong value.

wgtai_test_seed_term(123, 'product_cat', 'cat-123', 'Category 123 EN');
wgtai_test_seed_post(124, 'post-124', 'Post 124 EN');

$storage->save_term(123, ['language' => 'de', 'name' => 'Kategorie 123']);
wgtai_check('storing a term writes no post meta for that id', isset($GLOBALS['wgtai_test_meta'][123]), false);

$storage->save(124, ['language' => 'de', 'title' => 'Beitrag 124']);
wgtai_check('storing a post writes no term meta for that id', isset($GLOBALS['wgtai_test_termmeta'][124]), false);

// --- omit / null / value on name, mirroring the description checks ----------

wgtai_test_seed_term(125, 'product_cat', 'cat-125', 'Category 125 EN');
$storage->save_term(125, ['language' => 'de', 'name' => 'Kategorie 125', 'description' => '<p>DE 125</p>']);
$storage->save_term(125, ['language' => 'de', 'description' => '<p>DE 125 v2</p>']);

wgtai_check('an omitted name leaves the stored name alone', $storage->get_term(125, 'de')['name'], 'Kategorie 125');

$storage->save_term(125, ['language' => 'de', 'name' => null]);
wgtai_check('an explicit null clears the name', isset($storage->get_term(125, 'de')['name']), false);
wgtai_check('clearing the name leaves the description', $storage->get_term(125, 'de')['description'], '<p>DE 125 v2</p>');

// --- a malformed entry must not take the rest of the batch down -------------

wgtai_test_seed_term(126, 'product_cat', 'cat-126', 'Category 126 EN');

$mixed_shape = new WP_REST_Request('POST', '/weglot-translations/v1/terms');
$mixed_shape->set_param('source_term_id', 126);
$mixed_shape->set_param('taxonomy', 'product_cat');
$mixed_shape->set_param('translations', ['not-an-array', ['language' => 'de', 'name' => 'Kategorie 126']]);

$mixed_shape_response = $rest->create_term_translations($mixed_shape);
$mixed_shape_data     = $mixed_shape_response->get_data();

wgtai_check('a malformed translation entry answers 207, not a bare error', $mixed_shape_response->get_status(), 207);
wgtai_check_true('every errors[] entry carries the language key the flow reads', array_key_exists('language', $mixed_shape_data['errors'][0]));
wgtai_check('a malformed entry does not abort the batch', count($mixed_shape_data['results']), 1);
wgtai_check('the good locale in a malformed batch is really stored', $storage->get_term(126, 'de')['name'], 'Kategorie 126');

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
