<?php
/**
 * Staging canary for safe existing-page Avada text updates.
 *
 * Usage:
 * wp eval-file wp-content/plugins/nova-bridge-suite/tests/avada-safe-update-regression.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

$plugin_root = dirname(__DIR__);
require_once $plugin_root . '/modules/avada/includes/class-nab-layout-transformer.php';
require_once $plugin_root . '/modules/avada/includes/class-nab-page-service.php';
require_once $plugin_root . '/modules/avada/includes/class-nab-rest-controller.php';

foreach ([
    'fusion_builder_container',
    'fusion_builder_row',
    'fusion_builder_column',
    'fusion_title',
    'fusion_text',
    'fusion_button',
    'fusion_imageframe',
    'fusion_form',
] as $shortcode_tag) {
    add_shortcode($shortcode_tag, '__return_empty_string');
}

$transformer = new \NovaAvadaBridge\Layout_Transformer();
$service     = new \NovaAvadaBridge\Page_Service($transformer);
$controller  = new \NovaAvadaBridge\REST_Controller($service, $transformer);

$raw =
    '[fusion_builder_container][fusion_builder_row][fusion_builder_column]' .
    '[fusion_title size="2"]Editable section[/fusion_title]' .
    '[fusion_text]<p>LEAF_OLD</p>[/fusion_text]' .
    '[fusion_button url="#keep"]SIBLING_CTA_SENTINEL[/fusion_button]' .
    '[fusion_imageframe image_id="1"]SIBLING_IMAGE_SENTINEL[/fusion_imageframe]' .
    '[fusion_form form_post_id="1"]SIBLING_FORM_SENTINEL[/fusion_form]' .
    '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' .
    '[fusion_builder_container][fusion_builder_row][fusion_builder_column]' .
    '[fusion_text]<p>MIXED_OLD</p>[fusion_button url="#nested"]NESTED_CTA_SENTINEL[/fusion_button][/fusion_text]' .
    '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]' .
    '[fusion_builder_container][fusion_builder_row][fusion_builder_column]' .
    '[fusion_title size="2"]Final conversion block[/fusion_title]' .
    '[fusion_button url="#final"]FINAL_CTA_SENTINEL[/fusion_button]' .
    '[fusion_form form_post_id="2"]FINAL_FORM_SENTINEL[/fusion_form]' .
    '[/fusion_builder_column][/fusion_builder_row][/fusion_builder_container]';

$checks = [];
$error  = null;
$post_id = wp_insert_post([
    'post_type'    => 'page',
    'post_status'  => 'private',
    'post_title'   => 'NOVA Avada safe update staging canary',
    'post_content' => wp_slash($raw),
], true);

if (is_wp_error($post_id)) {
    WP_CLI::error('Could not create the private Avada canary: ' . $post_id->get_error_message());
}

update_post_meta($post_id, '_fusion_builder_shortcodes', wp_slash($raw));
update_post_meta($post_id, '_fusion_builder_content', wp_slash($raw));
update_post_meta($post_id, '_fusion_builder_status', 'active');

$find_node = static function (array $nodes, callable $predicate) use (&$find_node) {
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if ($predicate($node)) {
            return $node;
        }
        if (!empty($node['children']) && is_array($node['children'])) {
            $found = $find_node($node['children'], $predicate);
            if (null !== $found) {
                return $found;
            }
        }
    }
    return null;
};

$stored_shortcodes = static function (int $id): string {
    return (string) get_post_field('post_content', $id, 'raw');
};

$stores_converged = static function (int $id): bool {
    $content = (string) get_post_field('post_content', $id, 'raw');
    return '' !== $content &&
        $content === (string) get_post_meta($id, '_fusion_builder_shortcodes', true) &&
        $content === (string) get_post_meta($id, '_fusion_builder_content', true);
};

$sentinels = [
    'SIBLING_CTA_SENTINEL',
    'SIBLING_IMAGE_SENTINEL',
    'SIBLING_FORM_SENTINEL',
    'NESTED_CTA_SENTINEL',
    'FINAL_CTA_SENTINEL',
    'FINAL_FORM_SENTINEL',
];

$sentinels_once = static function (string $value) use ($sentinels): bool {
    foreach ($sentinels as $sentinel) {
        if (1 !== substr_count($value, $sentinel)) {
            return false;
        }
    }
    return true;
};

try {
    $stale_meta = str_replace('LEAF_OLD', 'META_ONLY_STALE', $raw);
    update_post_meta($post_id, '_fusion_builder_shortcodes', wp_slash($stale_meta));
    update_post_meta($post_id, '_fusion_builder_content', wp_slash($stale_meta));
    $canonical_payload = $service->build_page_payload(get_post($post_id));
    $checks['reads visible post content before stale Avada mirrors'] =
        $raw === ($canonical_payload['layout']['raw_shortcodes'] ?? '') &&
        false === strpos((string) ($canonical_payload['layout']['raw_shortcodes'] ?? ''), 'META_ONLY_STALE');

    $initial_compact = $transformer->to_compact_layout($stored_shortcodes($post_id));
    $checks['fixture has three independent roots'] = 3 === count($initial_compact);
    $checks['mixed content round-trips byte-identically'] =
        $raw === $transformer->from_layout($initial_compact);

    $leaf = $find_node($initial_compact, static function (array $node): bool {
        return 'fusion_text' === ($node['tag'] ?? '') &&
            false !== strpos((string) ($node['text'] ?? ''), 'LEAF_OLD');
    });
    $mixed = $find_node($initial_compact, static function (array $node): bool {
        return 'fusion_text' === ($node['tag'] ?? '') &&
            false !== strpos((string) ($node['text'] ?? ''), 'MIXED_OLD');
    });
    $checks['fixture exposes leaf and protected mixed targets'] =
        is_array($leaf) && empty($leaf['children']) &&
        is_array($mixed) && !empty($mixed['children']);

    if (!is_array($leaf) || !is_array($mixed)) {
        throw new RuntimeException('Could not resolve fixture text paths.');
    }

    $leaf_request = new WP_REST_Request('PATCH', '/nova-avada/v1/pages/' . $post_id);
    $leaf_request->set_param('identifier', (string) $post_id);
    $leaf_request->set_param('text_updates', [[
        'path' => (string) $leaf['path'],
        'text' => '<p>LEAF_UPDATED</p>',
    ]]);
    $leaf_result = $controller->update_item($leaf_request);
    $after_leaf = $stored_shortcodes($post_id);
    $checks['leaf controller update succeeds'] = $leaf_result instanceof WP_REST_Response;
    $checks['leaf text is replaced exactly once'] =
        1 === substr_count($after_leaf, 'LEAF_UPDATED') &&
        0 === substr_count($after_leaf, 'LEAF_OLD');
    $checks['leaf update preserves all protected sentinels'] = $sentinels_once($after_leaf);
    $checks['leaf update preserves private status'] = 'private' === get_post_status($post_id);
    $checks['leaf update converges visible content and Avada mirrors'] = $stores_converged($post_id);

    $after_leaf_compact = $transformer->to_compact_layout($after_leaf);
    $mixed_after_leaf = $find_node($after_leaf_compact, static function (array $node): bool {
        return 'fusion_text' === ($node['tag'] ?? '') &&
            false !== strpos((string) ($node['text'] ?? ''), 'MIXED_OLD');
    });
    if (!is_array($mixed_after_leaf)) {
        throw new RuntimeException('Protected mixed target disappeared after the leaf update.');
    }

    $before_rejected_hash = hash('sha256', $after_leaf);
    $protected_request = new WP_REST_Request('PATCH', '/nova-avada/v1/pages/' . $post_id);
    $protected_request->set_param('identifier', (string) $post_id);
    $protected_request->set_param('text_updates', [[
        'path' => (string) $mixed_after_leaf['path'],
        'text' => '<p>UNSAFE_REPLACEMENT</p>',
    ]]);
    $protected_result = $controller->update_item($protected_request);
    $after_rejected = $stored_shortcodes($post_id);
    $checks['protected mixed target returns HTTP 422 error'] =
        is_wp_error($protected_result) &&
        'nova_avada_protected_text_update' === $protected_result->get_error_code() &&
        422 === ($protected_result->get_error_data()['status'] ?? null);
    $checks['rejected update performs no layout write'] =
        $before_rejected_hash === hash('sha256', $after_rejected) &&
        0 === substr_count($after_rejected, 'UNSAFE_REPLACEMENT');
    $checks['rejected update preserves private status'] = 'private' === get_post_status($post_id);

    $before_append_compact = $transformer->to_compact_layout($after_rejected);
    $append_request = new WP_REST_Request('PATCH', '/nova-avada/v1/pages/' . $post_id);
    $append_request->set_param('identifier', (string) $post_id);
    $append_request->set_param('append_html', '<h2>Appended section</h2><p>APPEND_SENTINEL</p>');
    $append_request->set_param('publish_builder', true);
    $append_result = $controller->update_item($append_request);
    $after_append = $stored_shortcodes($post_id);
    $after_append_compact = $transformer->to_compact_layout($after_append);
    $checks['unanchored append controller update succeeds'] = $append_result instanceof WP_REST_Response;
    $checks['unanchored append creates one final independent root'] =
        count($after_append_compact) === count($before_append_compact) + 1 &&
        array_slice($after_append_compact, 0, count($before_append_compact)) === $before_append_compact &&
        false !== strpos(
            $transformer->from_layout([end($after_append_compact)]),
            'APPEND_SENTINEL'
        );
    $checks['append follows the final CTA/form root'] =
        false !== strpos($after_append, 'FINAL_FORM_SENTINEL') &&
        false !== strpos($after_append, 'APPEND_SENTINEL') &&
        strpos($after_append, 'FINAL_FORM_SENTINEL') < strpos($after_append, 'APPEND_SENTINEL');
    $checks['append preserves all protected sentinels'] = $sentinels_once($after_append);
    $checks['append preserves private status'] = 'private' === get_post_status($post_id);
    $checks['append converges visible content and Avada mirrors'] = $stores_converged($post_id);

    $read_request = new WP_REST_Request('GET', '/nova-avada/v1/pages/' . $post_id);
    $read_request->set_param('identifier', (string) $post_id);
    $read_request->set_param('layout_mode', 'outline');
    $read_request->set_param('outline_style', 'tree');
    $read_request->set_param('include_document', true);
    $read_request->set_param('text_map', true);
    $read_request->set_param('include_meta', false);
    $read_result = $controller->get_item($read_request);
    $read_data = $read_result instanceof WP_REST_Response ? $read_result->get_data() : [];
    $checks['tree plus text-map read contract is available'] =
        !empty($read_data['layout']['outline']) &&
        !empty($read_data['layout']['text_map']) &&
        false !== strpos((string) ($read_data['layout']['raw_shortcodes'] ?? ''), 'APPEND_SENTINEL');
} catch (Throwable $throwable) {
    $error = $throwable;
} finally {
    wp_delete_post($post_id, true);
}

$checks['private canary is deleted'] = null === get_post($post_id);

foreach ($checks as $label => $passed) {
    WP_CLI::line(($passed ? 'PASS' : 'FAIL') . '  ' . $label);
}

if ($error instanceof Throwable) {
    WP_CLI::warning('Canary exception: ' . $error->getMessage());
}

if ($error instanceof Throwable || in_array(false, $checks, true)) {
    WP_CLI::error('Avada safe update staging canary failed.');
}

WP_CLI::success('Avada safe update staging canary passed.');
