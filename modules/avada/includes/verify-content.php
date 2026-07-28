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

foreach ($checks as $label => $passed) {
    WP_CLI::line(($passed ? 'PASS' : 'FAIL') . '  ' . $label);
}

if (in_array(false, $checks, true)) {
    WP_CLI::error('Anchored Avada insertion verification failed.');
}

WP_CLI::success('Anchored Avada insertion verification passed.');
