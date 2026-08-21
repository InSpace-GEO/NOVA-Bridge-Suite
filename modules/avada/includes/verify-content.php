<?php
/**
 * Verify anchored Avada HTML insertion without writing to the database.
 *
 * Usage:
 * wp eval-file wp-content/plugins/nova-bridge-suite/modules/avada/includes/verify-content.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}

require_once __DIR__ . '/class-nab-layout-transformer.php';
require_once __DIR__ . '/class-nab-page-service.php';

$node = static function (string $tag, string $path, string $text = '', array $children = []): array {
    return [
        'tag'        => $tag,
        'path'       => $path,
        'attributes' => [],
        'text'       => '' === $text ? null : $text,
        'children'   => $children,
    ];
};

$content_root = $node('fusion_builder_container', '0', '', [
    $node('fusion_builder_row', '0.0', '', [
        $node('fusion_builder_column', '0.0.0', '', [
            $node('fusion_text', '0.0.0.0', '<p>Existing content</p>'),
        ]),
    ]),
]);
$blogs_root = $node('fusion_builder_container', '1', '', [
    $node('fusion_builder_row', '1.0', '', [
        $node('fusion_builder_column', '1.0.0', '', [
            $node('fusion_title', '1.0.0.0', '<h3>Blogs over code 95</h3>'),
            $node('fusion_blog', '1.0.0.1'),
        ]),
    ]),
]);
$layout = [
    'raw_shortcodes' => 'stale',
    'compact'        => [$content_root, $blogs_root],
];

$service = new \NovaAvadaBridge\Page_Service(new \NovaAvadaBridge\Layout_Transformer());
$result = $service->append_html_block($layout, '<h2>Inserted content</h2><p>Body</p>', '1.0.0.0');

$checks = [
    'anchored insertion succeeds'     => !is_wp_error($result),
    'new independent root is inserted' => !is_wp_error($result) && 3 === count($result['compact']),
    'blogs root is unchanged'         => !is_wp_error($result) && $blogs_root === $result['compact'][2],
    'stale raw shortcodes are removed' => !is_wp_error($result) && !isset($result['raw_shortcodes']),
];

if (!is_wp_error($result)) {
    $shortcodes = (new \NovaAvadaBridge\Layout_Transformer())->from_layout($result['compact']);
    $inserted = strpos($shortcodes, 'Inserted content');
    $heading  = strpos($shortcodes, 'Blogs over code 95');
    $cards    = strpos($shortcodes, '[fusion_blog');
    $checks['inserted content is serialized'] = false !== $inserted;
    $checks['blog heading is serialized'] = false !== $heading;
    $checks['blog cards are serialized'] = false !== $cards;
    $checks['render order is content, heading, cards'] =
        false !== $inserted && false !== $heading && false !== $cards && $inserted < $heading && $heading < $cards;
}

$missing = $service->append_html_block($layout, '<p>Never inserted</p>', '9.0.0');
$checks['unknown path fails safely'] =
    is_wp_error($missing) && 'nova_avada_insert_path_not_found' === $missing->get_error_code();

$empty = $service->append_html_block([], '<p>Never inserted</p>', '0.0.0');
$checks['anchored empty layout fails safely'] =
    is_wp_error($empty) && 'nova_avada_insert_path_not_found' === $empty->get_error_code();

$legacy_title = $node('fusion_title', '0.0.0.0', '<h2>Old heading</h2>');
$legacy_update = $service->apply_text_updates(
    ['compact' => [$legacy_title]],
    [['path' => '0.0.0.0', 'text' => 'New heading']]
);
$legacy_shortcode = (new \NovaAvadaBridge\Layout_Transformer())->from_layout($legacy_update['compact']);
$checks['legacy title gains its intended H2 size'] =
    '[fusion_title size="2"]New heading[/fusion_title]' === $legacy_shortcode;

$modern_title = $node('fusion_title', '0.0.0.0', 'Old subheading');
$modern_title['attributes']['size'] = '3';
$modern_update = $service->apply_text_updates(
    ['compact' => [$modern_title]],
    [['path' => '0.0.0.0', 'text' => 'New subheading']]
);
$modern_shortcode = (new \NovaAvadaBridge\Layout_Transformer())->from_layout($modern_update['compact']);
$checks['explicit title size stays unchanged'] =
    '[fusion_title size="3"]New subheading[/fusion_title]' === $modern_shortcode;

$nested_text = $node('fusion_text', '0.0.0.0', '<p>Old copy</p>', [
    $node('text', '0.0.0.0.0', '<p>Old fragment</p>'),
    $node('fusion_button', '0.0.0.0.1', 'Keep CTA'),
    $node('fusion_imageframe', '0.0.0.0.2', 'https://example.com/keep.jpg'),
    $node('fusion_form', '0.0.0.0.3'),
]);
$nested_update = $service->apply_text_updates(
    ['compact' => [$nested_text]],
    [['path' => '0.0.0.0', 'text' => '<p>New copy</p>']]
);
$checks['nested text target fails safely'] =
    is_wp_error($nested_update) &&
    'nova_avada_protected_text_update' === $nested_update->get_error_code() &&
    422 === ($nested_update->get_error_data()['status'] ?? null) &&
    '0.0.0.0' === ($nested_update->get_error_data()['path'] ?? null);
$checks['nested source remains unchanged'] = '<p>Old copy</p>' === $nested_text['text'];

$safe_root = $node('fusion_builder_container', '2', '', [
    $node('fusion_builder_row', '2.0', '', [
        $node('fusion_builder_column', '2.0.0', '', [
            $node('fusion_text', '2.0.0.0', '<p>Editable leaf</p>'),
            $node('fusion_button', '2.0.0.1', 'Keep CTA'),
            $node('fusion_imageframe', '2.0.0.2', 'https://example.com/keep.jpg'),
            $node('fusion_form', '2.0.0.3'),
        ]),
    ]),
]);
$safe_update = $service->apply_text_updates(
    ['compact' => [$safe_root]],
    [['path' => '2.0.0.0', 'text' => '<p>Updated leaf</p>']]
);
$checks['leaf text update succeeds'] = !is_wp_error($safe_update);
$checks['leaf update preserves protected siblings'] =
    !is_wp_error($safe_update) &&
    array_slice($safe_root['children'][0]['children'][0]['children'], 1) ===
        array_slice($safe_update['compact'][0]['children'][0]['children'][0]['children'], 1);

$summary = (new \NovaAvadaBridge\Layout_Transformer())->to_outline_summary([$nested_text]);
$checks['summary exposes nested child risk'] =
    true === ($summary[0]['has_children'] ?? false) &&
    ['text', 'fusion_button', 'fusion_imageframe', 'fusion_form'] === ($summary[0]['child_tags'] ?? []);

$mixed_content = $node('fusion_text', '0', '<p>Mixed copy</p>', [
    $node('text', '0.0', '<p>Mixed copy</p>'),
    $node('fusion_button', '0.1', 'Nested CTA'),
]);
$mixed_shortcode = (new \NovaAvadaBridge\Layout_Transformer())->from_layout([$mixed_content]);
$checks['mixed text and shortcode order round-trips without duplication'] =
    '[fusion_text]<p>Mixed copy</p>[fusion_button]Nested CTA[/fusion_button][/fusion_text]' === $mixed_shortcode;

$unanchored = $service->append_html_block($layout, '<h2>Independent content</h2>');
$checks['unanchored append creates an independent root'] =
    !is_wp_error($unanchored) &&
    3 === count($unanchored['compact']) &&
    $blogs_root === $unanchored['compact'][1];
$unanchored_shortcode = !is_wp_error($unanchored)
    ? (new \NovaAvadaBridge\Layout_Transformer())->from_layout($unanchored['compact'])
    : '';
$blogs_position = strpos($unanchored_shortcode, 'Blogs over code 95');
$independent_position = strpos($unanchored_shortcode, 'Independent content');
$checks['unanchored append follows existing roots'] =
    false !== $blogs_position &&
    false !== $independent_position &&
    $blogs_position < $independent_position;

$prepare_args = new ReflectionMethod($service, 'prepare_post_args');
$prepare_args->setAccessible(true);
$update_args = $prepare_args->invoke($service, [], false);
$create_args = $prepare_args->invoke($service, [], true);
$explicit_args = $prepare_args->invoke($service, ['status' => 'private'], false);
$checks['update without status preserves existing status'] = !isset($update_args['post_status']);
$checks['create without status defaults to draft'] = 'draft' === ($create_args['post_status'] ?? null);
$checks['explicit update status remains supported'] = 'private' === ($explicit_args['post_status'] ?? null);

foreach ($checks as $label => $passed) {
    WP_CLI::line(($passed ? 'PASS' : 'FAIL') . '  ' . $label);
}

if (in_array(false, $checks, true)) {
    WP_CLI::error('Anchored Avada insertion verification failed.');
}

WP_CLI::success('Anchored Avada insertion verification passed.');
