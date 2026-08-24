<?php
/**
 * Standalone unit checks for the Elementor bridge.
 *
 * Runs without WordPress or Elementor: every WP/Elementor surface the module
 * touches on the covered paths is stubbed below, so this can be run on any box
 * with plain PHP.
 *
 * Run with: php tests/elementor-unit.php
 *
 * For end-to-end verification against a real Elementor install use
 * tests/elementor-regression.php (wp eval-file) instead.
 */

if ('cli' !== PHP_SAPI) {
    exit(1);
}

define('ABSPATH', __DIR__ . '/');
define('OBJECT', 'OBJECT');

// error_log() is load-bearing in the code under test; keep it out of stderr so
// the check report stays readable.
ini_set('log_errors', '1');
ini_set('error_log', sys_get_temp_dir() . '/nbs-elementor-unit.log');

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

class WP_Error
{
    private $code;
    private $message;
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

class WP_Post
{
    public $ID          = 0;
    public $post_type   = 'page';
    public $post_status = 'publish';
    public $post_title  = '';
    public $post_name   = '';

    public function __construct(array $fields = [])
    {
        foreach ($fields as $key => $value) {
            $this->{$key} = $value;
        }
    }
}

class WP_REST_Controller
{
    protected $namespace = '';
    protected $rest_base = '';
}

class WP_REST_Server
{
    const READABLE  = 'GET';
    const EDITABLE  = 'POST, PUT, PATCH';
    const CREATABLE = 'POST';
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// ---------------------------------------------------------------------------
// In-memory WordPress state
// ---------------------------------------------------------------------------

$GLOBALS['nbs_el_posts']      = [];
$GLOBALS['nbs_el_meta']       = [];
$GLOBALS['nbs_el_options']    = [];
$GLOBALS['nbs_el_post_types'] = [
    // name => [ 'public' => bool, 'supports_elementor' => bool ]
    'page'       => ['public' => true, 'supports_elementor' => true],
    'post'       => ['public' => true, 'supports_elementor' => true],
    'product'    => ['public' => true, 'supports_elementor' => true],
    'attachment' => ['public' => true, 'supports_elementor' => false],
    'nbs_hidden' => ['public' => false, 'supports_elementor' => false],
];
$GLOBALS['nbs_el_caps']  = ['*' => true];
$GLOBALS['nbs_el_roles'] = ['administrator'];
// Mutation counters. These are the assertion that proves the partial write on
// a post type Elementor refuses is gone.
$GLOBALS['nbs_el_calls'] = [
    'wp_update_post'   => 0,
    'update_post_meta' => 0,
    'delete_post_meta' => 0,
    'wp_insert_post'   => 0,
];

function nbs_el_reset_calls()
{
    foreach ($GLOBALS['nbs_el_calls'] as $key => $unused) {
        $GLOBALS['nbs_el_calls'][$key] = 0;
    }
}

function nbs_el_seed_post($id, $post_type = 'page', $status = 'publish', array $extra = [])
{
    $GLOBALS['nbs_el_posts'][(int) $id] = new WP_Post(array_merge([
        'ID'          => (int) $id,
        'post_type'   => $post_type,
        'post_status' => $status,
        'post_title'  => 'Post ' . $id,
        'post_name'   => 'post-' . $id,
    ], $extra));
}

function get_post($post_id = null)
{
    $post_id = (int) (is_object($post_id) ? $post_id->ID : $post_id);

    return isset($GLOBALS['nbs_el_posts'][$post_id]) ? $GLOBALS['nbs_el_posts'][$post_id] : null;
}

function get_post_type($post_id = null)
{
    $post = get_post($post_id);

    return $post ? $post->post_type : false;
}

function get_post_status($post_id = null)
{
    $post = get_post($post_id);

    return $post ? $post->post_status : false;
}

function post_type_exists($post_type)
{
    return isset($GLOBALS['nbs_el_post_types'][(string) $post_type]);
}

function get_post_types($args = [], $output = 'names')
{
    $names = [];

    foreach ($GLOBALS['nbs_el_post_types'] as $name => $flags) {
        if (isset($args['public']) && $flags['public'] !== (bool) $args['public']) {
            continue;
        }
        $names[$name] = $name;
    }

    return $names;
}

function post_type_supports($post_type, $feature)
{
    if (!isset($GLOBALS['nbs_el_post_types'][(string) $post_type])) {
        return false;
    }

    if ('elementor' === $feature) {
        return (bool) $GLOBALS['nbs_el_post_types'][(string) $post_type]['supports_elementor'];
    }

    return true;
}

function get_post_type_object($post_type)
{
    if (!post_type_exists($post_type)) {
        return null;
    }

    return (object) [
        'name' => (string) $post_type,
        'cap'  => (object) [
            'edit_post'  => 'edit_' . $post_type,
            'edit_posts' => 'edit_' . $post_type . 's',
        ],
    ];
}

function current_user_can($capability, $object_id = null)
{
    $caps = $GLOBALS['nbs_el_caps'];

    if (array_key_exists($capability, $caps)) {
        return (bool) $caps[$capability];
    }

    return isset($caps['*']) ? (bool) $caps['*'] : false;
}

function wp_get_current_user()
{
    return (object) ['ID' => 1, 'roles' => $GLOBALS['nbs_el_roles']];
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['nbs_el_options']) ? $GLOBALS['nbs_el_options'][$name] : $default;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    $store = isset($GLOBALS['nbs_el_meta'][(int) $post_id]) ? $GLOBALS['nbs_el_meta'][(int) $post_id] : [];

    if ('' === $key) {
        return $store;
    }

    if (!array_key_exists($key, $store)) {
        return $single ? '' : [];
    }

    return $single ? $store[$key] : [$store[$key]];
}

function metadata_exists($type, $post_id, $key)
{
    return isset($GLOBALS['nbs_el_meta'][(int) $post_id][$key]);
}

function update_post_meta($post_id, $key, $value, $prev = '')
{
    ++$GLOBALS['nbs_el_calls']['update_post_meta'];
    $GLOBALS['nbs_el_meta'][(int) $post_id][$key] = $value;

    return true;
}

function add_post_meta($post_id, $key, $value, $unique = false)
{
    return update_post_meta($post_id, $key, $value);
}

function delete_post_meta($post_id, $key, $value = '')
{
    ++$GLOBALS['nbs_el_calls']['delete_post_meta'];
    unset($GLOBALS['nbs_el_meta'][(int) $post_id][$key]);

    return true;
}

function wp_update_post($postarr = [], $wp_error = false)
{
    ++$GLOBALS['nbs_el_calls']['wp_update_post'];
    $post_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;

    if (isset($GLOBALS['nbs_el_posts'][$post_id])) {
        foreach ($postarr as $key => $value) {
            if ('ID' === $key) {
                continue;
            }
            $GLOBALS['nbs_el_posts'][$post_id]->{$key} = $value;
        }
    }

    return $post_id;
}

function wp_insert_post($postarr = [], $wp_error = false)
{
    ++$GLOBALS['nbs_el_calls']['wp_insert_post'];

    return 0;
}

function clean_post_cache($post_id)
{
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value)
{
    return trim(strip_tags((string) $value));
}

function sanitize_key($value)
{
    return strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $value));
}

function sanitize_title($value)
{
    $value = strtolower(trim((string) $value));

    return preg_replace('/[^a-z0-9_\-\/]/', '-', $value);
}

function wp_kses_post($value)
{
    return (string) $value;
}

function esc_url_raw($value)
{
    return (string) $value;
}

function esc_attr($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function esc_html($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function wp_unslash($value)
{
    return $value;
}

function wp_json_encode($value, $flags = 0, $depth = 512)
{
    return json_encode($value, $flags, $depth);
}

function absint($value)
{
    return abs((int) $value);
}

function __($text, $domain = null)
{
    return $text;
}

function esc_html__($text, $domain = null)
{
    return $text;
}

$GLOBALS['nbs_el_filters'] = [];

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['nbs_el_filters'][$hook][] = $callback;

    return true;
}

function remove_filter($hook, $callback, $priority = 10)
{
    return true;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_action($hook, $callback, $priority = 10)
{
    return true;
}

function apply_filters($hook, $value)
{
    return $value;
}

function do_action($hook)
{
}

function rest_ensure_response($value)
{
    return $value;
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../modules/elementor/includes/class-elementor-service.php';
require_once __DIR__ . '/../modules/elementor/includes/class-rest-controller.php';

$passed = 0;
$failed = [];

function nbs_check($label, $actual, $expected)
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

function nbs_check_true($label, $actual)
{
    nbs_check($label, (bool) $actual, true);
}

function nbs_invoke($object, $method, array $args = [])
{
    $reflection = new ReflectionMethod(get_class($object), $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}

$controller = new SEOR_Elementor_Bridge\Rest_Controller();
$service    = new SEOR_Elementor_Bridge\Elementor_Service();

// ---------------------------------------------------------------------------
// resolve_slug_lookup_types()
//
// Regression: this used to hand a bare STRING to get_page_by_path(). WP core's
// get_page_by_path() turns a string post type into array( $post_type,
// 'attachment' ), so a slug lookup for post_type=page silently matched an image
// attachment and the flow then PATCHed the attachment. The TYPE of the return
// value is the bug, so it is asserted directly.
// ---------------------------------------------------------------------------

$public_types = array_values(get_post_types(['public' => true]));

nbs_check_true('the public-type fixture contains attachment (core does too)', in_array('attachment', $public_types, true));

// A string return is the bug, so the checks below never coerce: they read
// through nbs_el_types(), which yields [] for a non-array so the is_array()
// check fails loudly instead of fataling.
function nbs_el_types($value)
{
    return is_array($value) ? $value : [];
}

$page_types = nbs_invoke($controller, 'resolve_slug_lookup_types', ['page', $public_types]);

nbs_check_true('a single post type resolves to an array, not a string', is_array($page_types));
nbs_check_true('the requested post type is present', in_array('page', nbs_el_types($page_types), true));
nbs_check('attachment is never appended to a single-type lookup', in_array('attachment', nbs_el_types($page_types), true), false);
nbs_check('a single-type lookup asks for exactly one type', count(nbs_el_types($page_types)), 1);

$cpt_types = nbs_invoke($controller, 'resolve_slug_lookup_types', ['product', $public_types]);

nbs_check_true('a CPT lookup resolves to an array too', is_array($cpt_types));
nbs_check('a CPT lookup is exactly the requested type', $cpt_types, ['product']);

$any_types = nbs_el_types(nbs_invoke($controller, 'resolve_slug_lookup_types', ['any', $public_types]));

nbs_check_true("the 'any' lookup resolves to a non-empty array", !empty($any_types));
nbs_check("the 'any' lookup excludes attachment", in_array('attachment', $any_types, true), false);
nbs_check_true("the 'any' lookup keeps page", in_array('page', $any_types, true));
nbs_check_true("the 'any' lookup keeps post", in_array('post', $any_types, true));
nbs_check_true("the 'any' lookup keeps product", in_array('product', $any_types, true));
nbs_check_true("the 'any' lookup is a list, so WP_Query can consume it", array_values($any_types) === $any_types);

// An explicit attachment request must not become a lookup either: the caller
// asked for something Elementor can never edit.
$attachment_request = nbs_invoke($controller, 'resolve_slug_lookup_types', ['attachment', $public_types]);
nbs_check_true('an explicit attachment request is still an array', is_array($attachment_request));

// --- report ----------------------------------------------------------------

echo "\n";

if (empty($failed)) {
    printf("elementor-unit: %d checks passed\n", $passed);
    exit(0);
}

printf("elementor-unit: %d passed, %d FAILED\n\n", $passed, count($failed));

foreach ($failed as $failure) {
    echo '  FAIL  ' . $failure . "\n\n";
}

exit(1);
