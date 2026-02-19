<?php
/**
 * Layout transformer for Avada Builder shortcodes.
 */

declare(strict_types=1);

namespace NovaAvadaBridge;

use function esc_attr;
use function get_shortcode_regex;
use function sanitize_key;
use function shortcode_parse_atts;
use function wp_json_encode;

class Layout_Transformer {
    /**
     * Convert Avada shortcode string into a compact PHP array structure.
     */
    public function to_compact_layout(string $shortcodes): array {
        $shortcodes = trim($shortcodes);
        if ('' === $shortcodes) {
            return [];
        }

        $nodes = $this->parse_nodes($shortcodes, '');
        return $nodes;
    }

    /**
     * Convert a compact array payload back to Avada shortcodes.
     */
    public function from_layout(array $layout): string {
        $compiled = '';
        foreach ($layout as $node) {
            $compiled .= $this->build_shortcode_from_node($node);
        }

        return $compiled;
    }

    private function parse_nodes(string $content, string $parent_path): array {
        $pattern = '/' . get_shortcode_regex() . '/s';
        $nodes   = [];
        $offset  = 0;
        $length  = strlen($content);
        $index   = 0;

        if (!preg_match($pattern, $content)) {
            $text = trim($content);
            if ('' !== $text) {
                $nodes[] = [
                    'tag'        => 'text',
                    'attributes' => [],
                    'text'       => $text,
                    'children'   => [],
                    'path'       => $this->build_node_path($parent_path, $index++),
                ];
            }

            return $nodes;
        }

        while ($offset < $length && preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $start = (int) $match[0][1];

            if ($start > $offset) {
                $text = trim(substr($content, $offset, $start - $offset));
                if ('' !== $text) {
                    $nodes[] = [
                        'tag'        => 'text',
                        'attributes' => [],
                        'text'       => $text,
                        'children'   => [],
                        'path'       => $this->build_node_path($parent_path, $index++),
                    ];
                }
            }

            $full_match = $match[0][0];
            $tag        = $match[2][0] ?? '';
            $atts_raw   = $match[3][0] ?? '';
            $inner      = $match[5][0] ?? '';
            $node_path  = $this->build_node_path($parent_path, $index++);

            $child_nodes = $this->parse_nodes($inner, $node_path);
            $text_value  = $this->extract_text_content($inner, $pattern);

            if ($this->children_are_plain_text($child_nodes)) {
                // Keep text on the parent to avoid duplicate child text nodes when round-tripping.
                $combined = trim(implode('', array_map(static fn($n) => $n['text'] ?? '', $child_nodes)));
                $text_value = '' !== $combined ? $combined : $text_value;
                $child_nodes = [];
            }

            $nodes[] = [
                'tag'        => $this->sanitize_tag($tag),
                'attributes' => $this->normalize_attributes($atts_raw),
                'text'       => $text_value,
                'children'   => $child_nodes,
                'path'       => $node_path,
            ];

            $offset = $start + strlen($full_match);
        }

        if ($offset < $length) {
            $text = trim(substr($content, $offset));
            if ('' !== $text) {
                $nodes[] = [
                    'tag'        => 'text',
                    'attributes' => [],
                    'text'       => $text,
                    'children'   => [],
                    'path'       => $this->build_node_path($parent_path, $index++),
                ];
            }
        }

        return $nodes;
    }

    private function extract_text_content(string $content, string $pattern): ?string {
        if ('' === trim($content)) {
            return null;
        }

        $clean = trim(preg_replace($pattern, '', $content) ?? '');
        return '' === $clean ? null : $clean;
    }

    private function normalize_attributes(string $atts_raw): array {
        $parsed = shortcode_parse_atts(trim($atts_raw));
        if (empty($parsed)) {
            return [];
        }

        $normalized = [];
        foreach ($parsed as $key => $value) {
            if (is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                $value = wp_json_encode($value);
            }

            $normalized[$key] = (string) $value;
        }

        return $normalized;
    }

    private function build_shortcode_from_node(array $node): string {
        if (empty($node['tag']) || 'text' === $node['tag']) {
            return $node['text'] ?? '';
        }

        $tag        = $this->sanitize_tag((string) $node['tag']);
        $attributes = isset($node['attributes']) && is_array($node['attributes']) ? $node['attributes'] : [];
        $attribute_string = $this->stringify_attributes($attributes);

        $content = '';
        if (!empty($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $content .= $this->build_shortcode_from_node($child);
            }
        }

        if (!empty($node['text'])) {
            $content .= (string) $node['text'];
        }

        $self_closing = isset($node['self_closing']) ? (bool) $node['self_closing'] : false;
        if ($self_closing) {
            return sprintf('[%1$s%2$s /]', $tag, $attribute_string);
        }

        return sprintf('[%1$s%2$s]%3$s[/%1$s]', $tag, $attribute_string, $content);
    }

    private function stringify_attributes(array $attributes): string {
        $compiled = '';
        foreach ($attributes as $key => $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            $compiled .= sprintf(' %s="%s"', sanitize_key((string) $key), esc_attr((string) $value));
        }

        return $compiled;
    }

    private function sanitize_tag(string $tag): string {
        return preg_replace('/[^a-z0-9_\-]/i', '', $tag) ?? '';
    }

    /**
     * Reduce compact nodes to a lighter outline (tag/text/path only).
     *
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return array<int, array<string, mixed>>
     */
    public function to_outline(array $nodes): array {
        $outline = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || 'text' === ($node['tag'] ?? '')) {
                continue;
            }

            $children = [];
            if (!empty($node['children']) && is_array($node['children'])) {
                $children = $this->to_outline($node['children']);
            }

            $outline_node = [
                'tag'      => $node['tag'] ?? '',
                'path'     => $node['path'] ?? null,
                'text'     => $node['text'] ?? null,
                'children' => $children,
            ];

            $label = $this->derive_outline_label($node);
            if (null !== $label) {
                $outline_node['label'] = $label;
            }

            $outline[] = $outline_node;
        }

        return $outline;
    }

    /**
     * Produce a flattened outline tailored for text updates.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, string>> $ancestors
     *
     * @return array<int, array<string, mixed>>
     */
    public function to_outline_summary(array $nodes, array $ancestors = []): array {
        $summary = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['tag'])) {
                continue;
            }

            $tag        = (string) $node['tag'];
            $descriptor = $this->build_outline_descriptor($node);
            $next_chain = array_merge($ancestors, [$descriptor]);

            if (!empty($node['text']) && 'text' !== $tag) {
                $entry = [
                    'path' => isset($node['path']) ? (string) $node['path'] : '',
                    'tag'  => $tag,
                    'text' => (string) $node['text'],
                ];

                if (!empty($descriptor['label'])) {
                    $entry['label'] = $descriptor['label'];
                }

                $context = $this->format_outline_context($ancestors);
                if ('' !== $context) {
                    $entry['context'] = $context;
                }

                $summary[] = $entry;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $summary = array_merge($summary, $this->to_outline_summary($node['children'], $next_chain));
            }
        }

        return $summary;
    }

    private function derive_outline_label(array $node): ?string {
        if (empty($node['attributes']) || !is_array($node['attributes'])) {
            return null;
        }

        $candidate_keys = ['admin_label', 'name', 'title', 'heading', 'before_text', 'after_text'];
        foreach ($candidate_keys as $candidate) {
            if (!empty($node['attributes'][$candidate])) {
                return (string) $node['attributes'][$candidate];
            }
        }

        return null;
    }

    /**
     * Extract flattened text nodes for easier updates.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, string>> $ancestors
     *
     * @return array<int, array<string, string>>
     */
    public function extract_text_map(array $nodes, array $ancestors = []): array {
        $map = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['tag'])) {
                continue;
            }

            $tag        = (string) $node['tag'];
            $descriptor = $this->build_outline_descriptor($node);
            $next_chain = array_merge($ancestors, [$descriptor]);

            if (!empty($node['text']) && 'text' !== $tag) {
                $entry = [
                    'path' => isset($node['path']) ? (string) $node['path'] : '',
                    'tag'  => $tag,
                    'text' => (string) $node['text'],
                ];

                if (!empty($descriptor['label'])) {
                    $entry['label'] = $descriptor['label'];
                }

                $context = $this->format_outline_context($ancestors);
                if ('' !== $context) {
                    $entry['context'] = $context;
                }

                $map[] = $entry;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $map = array_merge($map, $this->extract_text_map($node['children'], $next_chain));
            }
        }

        return $map;
    }

    private function build_outline_descriptor(array $node): array {
        $descriptor = [
            'tag' => isset($node['tag']) ? (string) $node['tag'] : '',
        ];

        $label = $this->derive_outline_label($node);
        if (null !== $label) {
            $descriptor['label'] = $label;
        }

        if (isset($node['path'])) {
            $descriptor['path'] = (string) $node['path'];
        }

        return $descriptor;
    }

    private function format_outline_context(array $ancestors): string {
        if (empty($ancestors)) {
            return '';
        }

        $parts = [];
        foreach ($ancestors as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            if (!empty($ancestor['label'])) {
                $parts[] = (string) $ancestor['label'];
            } elseif (!empty($ancestor['tag'])) {
                $parts[] = (string) $ancestor['tag'];
            }
        }

        $parts = array_filter(array_map('trim', $parts));

        return implode(' > ', $parts);
    }

    private function build_node_path(string $parent_path, int $index): string {
        $segment = (string) $index;
        return '' === $parent_path ? $segment : $parent_path . '.' . $segment;
    }

    /**
     * Determine if all children are plain text nodes.
     *
     * @param array<int, array<string, mixed>> $children
     */
    private function children_are_plain_text(array $children): bool {
        if (empty($children)) {
            return false;
        }

        foreach ($children as $child) {
            if (!is_array($child) || ($child['tag'] ?? '') !== 'text') {
                return false;
            }
        }

        return true;
    }
}
