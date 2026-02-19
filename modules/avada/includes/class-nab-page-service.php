<?php
/**
 * Service responsible for interacting with WordPress pages + Avada meta.
 */

declare(strict_types=1);

namespace NovaAvadaBridge;

use WP_Error;
use WP_Post;

use function absint;
use function get_page_by_path;
use function get_permalink;
use function get_post;
use function get_post_meta;
use function get_post_status;
use function is_wp_error;
use function maybe_unserialize;
use function mysql_to_rfc3339;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_textarea_field;
use function sanitize_title;
use function update_post_meta;
use function wp_kses_post;
use function wp_insert_post;
use function wp_slash;
use function wp_update_post;

class Page_Service {
    private Layout_Transformer $transformer;

    public function __construct(Layout_Transformer $transformer) {
        $this->transformer = $transformer;
    }

    public function get_page($identifier): ?WP_Post {
        $post = null;

        if (is_numeric($identifier)) {
            $post = get_post((int) $identifier);
        } elseif (is_string($identifier) && '' !== $identifier) {
            $post = get_page_by_path(sanitize_text_field($identifier));
        }

        if (!$post) {
            return null;
        }

        return $post;
    }

    public function build_page_payload(WP_Post $post): array {
        $shortcodes = $this->get_builder_shortcodes($post);
        $compact    = $this->transformer->to_compact_layout($shortcodes);

        return [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'slug'          => $post->post_name,
            'status'        => $post->post_status,
            'modified_gmt'  => mysql_to_rfc3339($post->post_modified_gmt),
            'permalink'     => get_permalink($post),
            'excerpt'       => $post->post_excerpt,
            'layout'        => [
                'raw_shortcodes' => $shortcodes,
                'compact'        => $compact,
                'has_builder'    => '' !== $shortcodes,
            ],
            'meta'          => $this->collect_fusion_meta($post->ID),
        ];
    }

    public function update_page(int $post_id, array $data) {
        $args = $this->prepare_post_args($data);
        $args['ID'] = $post_id;

        $updated = wp_update_post($args, true);
        if (is_wp_error($updated)) {
            return $updated;
        }

        $this->persist_layout_and_meta($post_id, $data);

        return get_post($post_id);
    }

    public function create_page(array $data) {
        $args = $this->prepare_post_args($data);
        if (!isset($args['post_type'])) {
            $args['post_type'] = 'page';
        }

        $post_id = wp_insert_post($args, true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $this->persist_layout_and_meta($post_id, $data);

        return get_post($post_id);
    }

    private function prepare_post_args(array $data): array {
        $args = [];

        if (isset($data['status'])) {
            $args['post_status'] = sanitize_text_field((string) $data['status']);
        }

        if (isset($data['post_type'])) {
            $pt = sanitize_text_field((string) $data['post_type']);
            if (post_type_exists($pt)) {
                $args['post_type'] = $pt;
            }
        }

        if (isset($data['author'])) {
            $author_id = absint($data['author']);
            if ($author_id > 0 && get_user_by('id', $author_id)) {
                $args['post_author'] = $author_id;
            }
        }

        if (isset($data['parent'])) {
            $parent = absint($data['parent']);
            if ($parent > 0) {
                $args['post_parent'] = $parent;
            }
        }

        if (isset($data['title'])) {
            $args['post_title'] = sanitize_text_field((string) $data['title']);
        }

        if (isset($data['slug'])) {
            $args['post_name'] = sanitize_title((string) $data['slug']);
        }

        if (isset($data['excerpt'])) {
            $args['post_excerpt'] = sanitize_textarea_field((string) $data['excerpt']);
        }

        if (isset($data['content'])) {
            $args['post_content'] = wp_kses_post((string) $data['content']);
        }

        if (!isset($args['post_status'])) {
            $args['post_status'] = 'draft';
        }

        return $args;
    }

    private function persist_layout_and_meta(int $post_id, array $data): void {
        $layout = $data['layout'] ?? [];
        $shortcodes = '';
        $builder_touched = false;

        if (isset($layout['raw_shortcodes']) && is_string($layout['raw_shortcodes'])) {
            $shortcodes = $layout['raw_shortcodes'];
        } elseif (!empty($layout['compact']) && is_array($layout['compact'])) {
            $shortcodes = $this->transformer->from_layout($layout['compact']);
        }

        if ('' !== $shortcodes) {
            $slashed = wp_slash($shortcodes);
            update_post_meta($post_id, '_fusion_builder_shortcodes', $slashed);
            update_post_meta($post_id, '_fusion_builder_content', $slashed);
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => $slashed,
            ]);

            $status = isset($layout['status']) ? sanitize_text_field((string) $layout['status']) : 'active';
            update_post_meta($post_id, '_fusion_builder_status', $status);
            $builder_touched = true;
        }

        if (!empty($data['meta']) && is_array($data['meta'])) {
            foreach ($data['meta'] as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }

                $meta_key = sanitize_key($key);
                update_post_meta($post_id, $meta_key, $value);
            }
        }

        if ($builder_touched || array_key_exists('publish_builder', $data)) {
            $this->finalize_builder($post_id, $data, $builder_touched);
        }
    }

    private function collect_fusion_meta(int $post_id): array {
        $meta     = get_post_meta($post_id);
        $filtered = [];

        foreach ($meta as $key => $values) {
            $is_fusion = (0 === strpos($key, '_fusion') || 0 === strpos($key, 'fusion_'));
            $is_page_options = 0 === strpos($key, 'pyre_'); // Avada page options prefix.
            $is_template_meta = '_wp_page_template' === $key;
            if (!$is_fusion && !$is_page_options && !$is_template_meta) {
                continue;
            }

            $filtered[$key] = 1 === count($values) ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        return $filtered;
    }

    private function get_builder_shortcodes(WP_Post $post): string {
        $meta_keys = [
            '_fusion_builder_shortcodes',
            '_fusion_builder_content',
            'fusion_builder_content',
        ];

        foreach ($meta_keys as $key) {
            $value = get_post_meta($post->ID, $key, true);
            if (is_string($value) && '' !== trim($value)) {
                return (string) $value;
            }
        }

        $content = (string) $post->post_content;
        if ('' !== trim($content)) {
            return $content;
        }

        return '';
    }

    /**
     * Apply path-based text updates to a layout array.
     *
     * @param array<string, mixed> $layout
     * @param array<int, array<string, string>> $updates
     */
    public function apply_text_updates(array $layout, array $updates): array {
        if (empty($layout['compact']) || !is_array($layout['compact']) || empty($updates)) {
            return $layout;
        }

        $map = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                continue;
            }

            if (!isset($update['path']) || !array_key_exists('text', $update)) {
                continue;
            }

            $map[(string) $update['path']] = wp_kses_post((string) $update['text']);
        }

        if (empty($map)) {
            return $layout;
        }

        $layout['compact'] = $this->replace_text_in_nodes($layout['compact'], $map);
        if (isset($layout['raw_shortcodes'])) {
            unset($layout['raw_shortcodes']);
        }

        return $layout;
    }

    /**
     * Append sanitized HTML as a new fusion_text node at the bottom of the layout.
     *
     * @param array<string, mixed> $layout
     */
    public function append_html_block(array $layout, string $html): array {
        if ('' === trim($html)) {
            return $layout;
        }

        $sanitized = wp_kses_post($html);
        if ('' === $sanitized) {
            return $layout;
        }

        if (!empty($layout['compact']) && is_array($layout['compact'])) {
            $layout['compact'] = $this->append_to_last_column($layout['compact'], $sanitized);
            return $layout;
        }

        // Fallback: create a minimal container/row/column/text chain.
        $layout['compact'] = [[
            'tag'        => 'fusion_builder_container',
            'attributes' => [],
            'text'       => null,
            'children'   => [[
                'tag'        => 'fusion_builder_row',
                'attributes' => [],
                'text'       => null,
                'children'   => [[
                    'tag'        => 'fusion_builder_column',
                    'attributes' => ['type' => '1_1'],
                    'text'       => null,
                    'children'   => [[
                        'tag'        => 'fusion_text',
                        'attributes' => [],
                        'text'       => $sanitized,
                        'children'   => [],
                    ]],
                ]],
            ]],
        ]];

        return $layout;
    }

    /**
     * Append structured sections (title + body) as fusion_title and fusion_text nodes.
     *
     * @param array<string, mixed>   $layout
     * @param array<int, array<string,string>> $sections
     */
    public function append_sections(array $layout, array $sections): array {
        if (empty($sections)) {
            return $layout;
        }

        if (empty($layout['compact']) || !is_array($layout['compact'])) {
            // Initialize a minimal container/row/column if none exists.
            $layout['compact'] = [[
                'tag'        => 'fusion_builder_container',
                'attributes' => [],
                'text'       => null,
                'children'   => [[
                    'tag'        => 'fusion_builder_row',
                    'attributes' => [],
                    'text'       => null,
                    'children'   => [[
                        'tag'        => 'fusion_builder_column',
                        'attributes' => ['type' => '1_1'],
                        'text'       => null,
                        'children'   => [],
                    ]],
                ]],
            ]];
        }

        $nodes = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $title     = isset($section['title']) ? sanitize_text_field((string) $section['title']) : '';
            $body_raw  = isset($section['body']) ? (string) $section['body'] : '';
            $title_tag = isset($section['title_tag']) ? strtolower((string) $section['title_tag']) : '';
            $heading_tag = in_array($title_tag, ['h2', 'h3', 'h4'], true) ? $title_tag : 'h2';

            $body = wp_kses_post($body_raw);

            if ('' === trim($title) && '' === trim($body)) {
                continue;
            }

            if ('' !== trim($title)) {
                $nodes[] = [
                    'tag'        => 'fusion_title',
                    'attributes' => [],
                    'text'       => sprintf('<%1$s>%2$s</%1$s>', $heading_tag, $title),
                    'children'   => [],
                ];
            }

            if ('' !== trim($body)) {
                $nodes[] = [
                    'tag'        => 'fusion_text',
                    'attributes' => [],
                    'text'       => $body,
                    'children'   => [],
                ];
            }
        }

        if (empty($nodes)) {
            return $layout;
        }

        $layout['compact'] = $this->append_nodes_to_last_column($layout['compact'], $nodes);
        return $layout;
    }

    /**
     * Remove nodes by outline path.
     *
     * @param array<string, mixed> $layout
     * @param array<int, string>   $paths
     */
    public function remove_paths(array $layout, array $paths): array {
        if (empty($paths) || empty($layout['compact']) || !is_array($layout['compact'])) {
            return $layout;
        }

        $map = [];
        foreach ($paths as $path) {
            $p = (string) $path;
            if ('' !== trim($p)) {
                $map[$p] = true;
            }
        }

        if (empty($map)) {
            return $layout;
        }

        $layout['compact'] = $this->remove_nodes_by_path($layout['compact'], $map);
        return $layout;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, string>            $map
     *
     * @return array<int, array<string, mixed>>
     */
    private function replace_text_in_nodes(array $nodes, array $map): array {
        foreach ($nodes as &$node) {
            $path = isset($node['path']) ? (string) $node['path'] : null;
            if ($path && array_key_exists($path, $map)) {
                $node['text'] = $map[$path];
                // Drop any child text fragments to avoid concatenating old + new content.
                if (isset($node['children']) && is_array($node['children'])) {
                    $node['children'] = [];
                }
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->replace_text_in_nodes($node['children'], $map);
            }
        }
        unset($node);

        return $nodes;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, bool>               $paths
     *
     * @return array<int, array<string, mixed>>
     */
    private function remove_nodes_by_path(array $nodes, array $paths): array {
        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $path = isset($node['path']) ? (string) $node['path'] : '';
            $should_remove = '' !== $path && isset($paths[$path]);

            if ($should_remove) {
                continue;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->remove_nodes_by_path($node['children'], $paths);
            }

            $result[] = $node;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $new_nodes
     */
    private function append_nodes_to_last_column(array $nodes, array $new_nodes): array {
        $column_ref = &$this->find_last_column_ref($nodes);
        if (null !== $column_ref) {
            if (!isset($column_ref['children']) || !is_array($column_ref['children'])) {
                $column_ref['children'] = [];
            }
            foreach ($new_nodes as $new_node) {
                $column_ref['children'][] = $new_node;
            }
            return $nodes;
        }
        unset($column_ref);

        // Fallback: create a container/row/column and append.
        $nodes[] = [
            'tag'        => 'fusion_builder_container',
            'attributes' => [],
            'text'       => null,
            'children'   => [[
                'tag'        => 'fusion_builder_row',
                'attributes' => [],
                'text'       => null,
                'children'   => [[
                    'tag'        => 'fusion_builder_column',
                    'attributes' => ['type' => '1_1'],
                    'text'       => null,
                    'children'   => $new_nodes,
                ]],
            ]],
        ];

        return $nodes;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function append_to_last_column(array $nodes, string $sanitized_html): array {
        $column_ref = &$this->find_last_column_ref($nodes);
        if (null !== $column_ref) {
            if (!isset($column_ref['children']) || !is_array($column_ref['children'])) {
                $column_ref['children'] = [];
            }

            $column_ref['children'][] = [
                'tag'        => 'fusion_text',
                'attributes' => [],
                'text'       => $sanitized_html,
                'children'   => [],
            ];
            return $nodes;
        }
        unset($column_ref);

        // If no column found, create a container/row/column/text and append to root.
        $nodes[] = [
            'tag'        => 'fusion_builder_container',
            'attributes' => [],
            'text'       => null,
            'children'   => [[
                'tag'        => 'fusion_builder_row',
                'attributes' => [],
                'text'       => null,
                'children'   => [[
                    'tag'        => 'fusion_builder_column',
                    'attributes' => ['type' => '1_1'],
                    'text'       => null,
                    'children'   => [[
                        'tag'        => 'fusion_text',
                        'attributes' => [],
                        'text'       => $sanitized_html,
                        'children'   => [],
                    ]],
                ]],
            ]],
        ];

        return $nodes;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return array<string, mixed>|null reference
     */
    private function &find_last_column_ref(array &$nodes) {
        $null = null;
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            if (!isset($nodes[$i]) || !is_array($nodes[$i])) {
                continue;
            }

            if (($nodes[$i]['tag'] ?? '') === 'fusion_builder_column') {
                return $nodes[$i];
            }

            if (!empty($nodes[$i]['children']) && is_array($nodes[$i]['children'])) {
                $child_ref = &$this->find_last_column_ref($nodes[$i]['children']);
                if (null !== $child_ref) {
                    return $child_ref;
                }
            }
        }

        return $null;
    }

    /**
     * Flush Avada caches and optionally bump the post revision when publishing builder changes.
     */
    private function finalize_builder(int $post_id, array $data, bool $builder_touched): void {
        $should_publish = $this->should_publish_builder($post_id, $data);
        $should_flush   = $builder_touched || $should_publish;

        if ($should_flush) {
            $this->clear_fusion_caches($post_id);
        }

        if ($should_publish) {
            $this->bump_post_revision($post_id);
        }
    }

    private function should_publish_builder(int $post_id, array $data): bool {
        if (array_key_exists('publish_builder', $data)) {
            return $this->is_truthy($data['publish_builder']);
        }

        return 'publish' === get_post_status($post_id);
    }

    private function clear_fusion_caches(int $post_id): void {
        if (function_exists('fusion_reset_all_caches')) {
            fusion_reset_all_caches();
        }

        if (function_exists('fusion_reset_page_cached_css')) {
            fusion_reset_page_cached_css($post_id);
        }

        if (class_exists('\\Fusion_Dynamic_CSS') && method_exists('\\Fusion_Dynamic_CSS', 'remove_post_dynamic_css')) {
            \Fusion_Dynamic_CSS::remove_post_dynamic_css($post_id);
        }
    }

    private function bump_post_revision(int $post_id): void {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return;
        }

        wp_update_post([
            'ID'          => $post_id,
            'post_status' => $post->post_status,
        ]);
    }

    private function is_truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool) (int) $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return !empty($value);
    }
}
