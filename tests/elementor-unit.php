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

function wp_rand($min = 0, $max = 0)
{
    return $min;
}

function wp_generate_uuid4()
{
    return '00000000-0000-4000-8000-000000000000';
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

/**
 * Read a key that a regression may have removed entirely, so the assertion
 * fails with a readable diff instead of a fatal.
 */
function nbs_el_at($array, $key, $default = null)
{
    return (is_array($array) && array_key_exists($key, $array)) ? $array[$key] : $default;
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

// ---------------------------------------------------------------------------
// diagnose_elementor_editability() + update_page() preflight
//
// Live incident: the slug lookup above returned an image attachment, and
// update_page() ran wp_update_post() and persist_meta() on it before
// persist_elementor_document() discovered that
// post_type_supports('attachment','elementor') is false. The rollback there
// only restores three _elementor_* meta keys, so the WP field and meta writes
// stuck. The mutation counters below are the proof that is gone.
// ---------------------------------------------------------------------------

nbs_el_seed_post(974, 'attachment', 'inherit', ['post_title' => 'magnesium-and-taurine.png']);
nbs_el_seed_post(555, 'page', 'publish');
nbs_el_seed_post(556, 'page', 'trash');
nbs_el_seed_post(557, 'page', 'publish');

$attachment_diagnosis = nbs_invoke($service, 'diagnose_elementor_editability', [974]);

nbs_check('the diagnosis reports the post type it refused', $attachment_diagnosis['post_type'], 'attachment');
nbs_check('an attachment does not support elementor', $attachment_diagnosis['checks']['post_type_supports_elementor'], false);
nbs_check_true('the attachment still exists, so post_exists is not the excuse', $attachment_diagnosis['checks']['post_exists']);
nbs_check_true('the user can still edit it, so this is not a capability failure', $attachment_diagnosis['checks']['can_edit_post']);
nbs_check('an attachment is not editable overall', $attachment_diagnosis['editable'], false);
nbs_check('the failed check is named', $attachment_diagnosis['failed'], ['post_type_supports_elementor']);
nbs_check('a post type mismatch is a 409, not a credentials-looking 403', $attachment_diagnosis['status'], 409);

$healthy_diagnosis = nbs_invoke($service, 'diagnose_elementor_editability', [555]);

nbs_check_true('a healthy page is editable', $healthy_diagnosis['editable']);
nbs_check('a healthy page has no failed checks', $healthy_diagnosis['failed'], []);
nbs_check_true('a healthy page supports elementor', $healthy_diagnosis['checks']['post_type_supports_elementor']);

$missing_diagnosis = nbs_invoke($service, 'diagnose_elementor_editability', [999999]);
nbs_check('a missing post is not editable', $missing_diagnosis['editable'], false);
nbs_check('a missing post reports no post type', $missing_diagnosis['post_type'], '');

$trashed_diagnosis = nbs_invoke($service, 'diagnose_elementor_editability', [556]);
nbs_check('a trashed page fails not_trashed', $trashed_diagnosis['checks']['not_trashed'], false);
nbs_check('a trashed page is not editable', $trashed_diagnosis['editable'], false);

$GLOBALS['nbs_el_options']['page_for_posts'] = 557;
$blog_diagnosis = nbs_invoke($service, 'diagnose_elementor_editability', [557]);
nbs_check('the blog posts page fails not_blog_posts_page', $blog_diagnosis['checks']['not_blog_posts_page'], false);
nbs_check('the blog posts page is a 409', $blog_diagnosis['status'], 409);
unset($GLOBALS['nbs_el_options']['page_for_posts']);

// A genuine capability failure keeps answering 403.
$GLOBALS['nbs_el_caps'] = ['edit_page' => false, '*' => true];
$cap_diagnosis          = nbs_invoke($service, 'diagnose_elementor_editability', [555]);
nbs_check('a per-type capability failure is caught', $cap_diagnosis['checks']['can_edit_post'], false);
nbs_check('a capability failure is a 403', $cap_diagnosis['status'], 403);
$GLOBALS['nbs_el_caps'] = ['*' => true];

// Elementor's role exclusion list is honoured without Elementor being present.
$GLOBALS['nbs_el_options']['elementor_exclude_user_roles'] = ['administrator'];
$role_diagnosis                                           = nbs_invoke($service, 'diagnose_elementor_editability', [555]);
nbs_check('an excluded role fails role_not_excluded', $role_diagnosis['checks']['role_not_excluded'], false);
nbs_check('an excluded role is a 403', $role_diagnosis['status'], 403);
unset($GLOBALS['nbs_el_options']['elementor_exclude_user_roles']);

nbs_check(
    'the diagnosis omits elementor_editable when Elementor is absent',
    array_key_exists('elementor_editable', $healthy_diagnosis['checks']),
    false
);

// --- update_page() must not touch a post Elementor refuses -------------------

$attachment_payload = [
    'title'   => 'Rewritten by the flow',
    'slug'    => 'rewritten-by-the-flow',
    'status'  => 'publish',
    'excerpt' => 'Should never land.',
    'meta'    => ['_yoast_wpseo_title' => 'Should never land either'],
];

nbs_el_reset_calls();
$attachment_result = $service->update_page(974, $attachment_payload);

nbs_check_true('updating an attachment is an error', is_wp_error($attachment_result));
nbs_check('the refusal has its own error code', $attachment_result->get_error_code(), 'seor_eb_elementor_not_editable');

$attachment_data = $attachment_result->get_error_data();
nbs_check('the refusal is a 409', $attachment_data['status'], 409);
nbs_check('the refusal names the post', $attachment_data['post_id'], 974);
nbs_check('the refusal names the post type', $attachment_data['post_type'], 'attachment');
nbs_check_true('the refusal carries the checks array', is_array($attachment_data['checks']) && !empty($attachment_data['checks']));
nbs_check('the refusal names which check failed', $attachment_data['failed'], ['post_type_supports_elementor']);

nbs_check('no wp_update_post() ran on the refused post', $GLOBALS['nbs_el_calls']['wp_update_post'], 0);
nbs_check('no update_post_meta() ran on the refused post', $GLOBALS['nbs_el_calls']['update_post_meta'], 0);
nbs_check('no delete_post_meta() ran on the refused post', $GLOBALS['nbs_el_calls']['delete_post_meta'], 0);
nbs_check('the refused post kept its title', get_post(974)->post_title, 'magnesium-and-taurine.png');
nbs_check('the refused post kept its slug', get_post(974)->post_name, 'post-974');
nbs_check('the refused post gained no meta', get_post_meta(974), []);

// A trashed page is refused just as bluntly, without mutation.
nbs_el_reset_calls();
$trashed_result = $service->update_page(556, $attachment_payload);
nbs_check('updating a trashed page is refused by code', $trashed_result->get_error_code(), 'seor_eb_elementor_not_editable');
nbs_check('a trashed page is not mutated either', $GLOBALS['nbs_el_calls']['wp_update_post'], 0);

// --- a healthy page still reaches the Elementor document write ---------------
//
// Elementor is not loaded in this harness, so the write fails at the Elementor
// boundary with reason 'elementor_api_unavailable'. That reason is itself the
// evidence that the preflight let the request through to the document write,
// and the counters show the WP-side mutations really did run.

nbs_el_reset_calls();
$healthy_result = $service->update_page(555, ['title' => 'A real page title']);

nbs_check_true('a healthy page is not blocked by the preflight', is_wp_error($healthy_result));
nbs_check('a healthy page reaches the document write', $healthy_result->get_error_code(), 'seor_eb_elementor_meta_write_failed');
$healthy_data = $healthy_result->get_error_data();
nbs_check('the healthy page failed at the Elementor boundary, not the preflight', $healthy_data['reason'], 'elementor_api_unavailable');
nbs_check_true('a healthy page really was mutated (proving the preflight passed)', $GLOBALS['nbs_el_calls']['wp_update_post'] > 0);
nbs_check('the healthy page took the new title', get_post(555)->post_title, 'A real page title');

// ---------------------------------------------------------------------------
// persist_elementor_document() must name the reason it refused
//
// Everything above ran with Elementor absent. From here a minimal Elementor
// surface is defined so the two distinct rejection causes can be exercised.
// It is eval()'d because the namespaced class has to appear in a file that is
// otherwise un-namespaced.
// ---------------------------------------------------------------------------

$GLOBALS['nbs_el_doc_editable'] = true;
$GLOBALS['nbs_el_doc_save']     = true;

eval(<<<'ELEMENTOR_STUB'
namespace Elementor;

class NBS_Stub_Document
{
    public function is_editable_by_current_user()
    {
        return (bool) $GLOBALS['nbs_el_doc_editable'];
    }

    public function set_is_built_with_elementor($value)
    {
    }

    public function save($data)
    {
        return (bool) $GLOBALS['nbs_el_doc_save'];
    }
}

class NBS_Stub_Documents
{
    public function get($post_id, $from_cache = true)
    {
        return new NBS_Stub_Document();
    }
}

class NBS_Stub_Elements
{
    public function get_element($type, $widget = null)
    {
        return true;
    }
}

class Plugin
{
    public $documents;
    public $elements_manager;
    private static $instance;

    public function __construct()
    {
        $this->documents        = new NBS_Stub_Documents();
        $this->elements_manager = new NBS_Stub_Elements();
    }

    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
ELEMENTOR_STUB
);

nbs_check_true('the Elementor stub is now visible to the module', class_exists('\Elementor\Plugin'));

// Cause 1: Elementor refuses the document outright. This is the live incident's
// inner failure, reached here directly so the preflight does not shadow it.
$GLOBALS['nbs_el_doc_editable'] = false;
$rejected = nbs_invoke($service, 'persist_elementor_document', [555, [], []]);

nbs_check_true('an Elementor refusal is an error', is_wp_error($rejected));
nbs_check('the error code is unchanged', $rejected->get_error_code(), 'seor_eb_elementor_meta_write_failed');
$rejected_data = $rejected->get_error_data();
nbs_check('reason is unchanged, callers branch on it', $rejected_data['reason'], 'elementor_save_rejected');
nbs_check('status is unchanged', $rejected_data['status'], 403);
nbs_check('post_id is unchanged', $rejected_data['post_id'], 555);
nbs_check_true('detail is present', array_key_exists('detail', $rejected_data));
nbs_check_true('detail is non-empty', '' !== nbs_el_at($rejected_data, 'detail', ''));
nbs_check('detail names the cause instead of hiding it in error_log()', nbs_el_at($rejected_data, 'detail', ''), 'The current user cannot edit this Elementor document.');
$rejected_checks = nbs_el_types(nbs_el_at($rejected_data, 'checks', null));
nbs_check_true('a rejection carries the diagnosis checks', !empty($rejected_checks));
nbs_check('a rejection reports the post type it was aimed at', nbs_el_at($rejected_data, 'post_type', ''), 'page');
nbs_check_true('the diagnosis now includes Elementor\'s own verdict', array_key_exists('elementor_editable', $rejected_checks));
nbs_check("Elementor's verdict is reported as false", nbs_el_at($rejected_checks, 'elementor_editable', null), false);

// Cause 2: Elementor accepts the document but its save lifecycle does not
// complete. Same reason string, different detail -- which is exactly why detail
// had to be exposed.
$GLOBALS['nbs_el_doc_editable'] = true;
$GLOBALS['nbs_el_doc_save']     = false;
$incomplete                     = nbs_invoke($service, 'persist_elementor_document', [555, [], []]);
$incomplete_data                = $incomplete->get_error_data();

nbs_check('a lifecycle failure shares the reason string', $incomplete_data['reason'], 'elementor_save_rejected');
nbs_check_true('a lifecycle failure has a non-empty detail', '' !== nbs_el_at($incomplete_data, 'detail', ''));
nbs_check('the two causes are distinguishable only by detail', nbs_el_at($incomplete_data, 'detail', ''), 'Elementor did not complete its document save lifecycle.');
nbs_check_true('the two details really differ', nbs_el_at($incomplete_data, 'detail', '') !== nbs_el_at($rejected_data, 'detail', ''));

$GLOBALS['nbs_el_doc_save'] = true;

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
