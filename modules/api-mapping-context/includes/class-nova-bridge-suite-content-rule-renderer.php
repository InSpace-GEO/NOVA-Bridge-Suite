<?php
/** Bounded semantic content normalization and deterministic native Elementor documents. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Content_Rule_Renderer {
    public const VERSION = 'nova-elementor-content-rules-v1';
    private const MAX_BYTES = 1048576;
    private const MAX_BLOCKS = 500;
    private const MAX_DEPTH = 8;
    private $warnings = [];
    private $count = 0;
    private $ids = [];
    private $anchors = [];
    private $seed;
    private $profile;

    public static function render( array $profile, array $input, string $seed ) {
        try {
            if ( '' === $seed || strlen( $seed ) > 256 || 1 !== preg_match( '//u', $seed ) ) { return Nova_Bridge_Suite_Content_Rules::error( 'seed', 'Supply a bounded stable rendering identity.' ); }
            $encoded = wp_json_encode( $input );
            if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES ) { return Nova_Bridge_Suite_Content_Rules::error( 'input_size', 'Content exceeds the one-megabyte renderer limit or is not valid UTF-8.' ); }
            if ( array_diff( array_keys( $input ), [ 'html', 'blocks', 'faqs', 'title' ] ) || ( array_key_exists( 'html', $input ) && array_key_exists( 'blocks', $input ) ) || ( ! array_key_exists( 'html', $input ) && ! array_key_exists( 'blocks', $input ) && ! array_key_exists( 'faqs', $input ) ) ) { return Nova_Bridge_Suite_Content_Rules::error( 'input', 'Supply HTML or typed blocks, with optional explicitly structured FAQs.' ); }
            $renderer = new self(); $renderer->seed = $seed; $renderer->profile = $profile;
            if ( array_key_exists( 'title', $input ) && ( ! is_string( $input['title'] ) || strlen( $input['title'] ) > 200 || 1 !== preg_match( '//u', $input['title'] ) || preg_match( '/[\x00-\x1F<>]/', $input['title'] ) ) ) { $renderer->fail( 'title', 'The native draft title must be plain UTF-8 text of at most 200 bytes.' ); }
            $blocks = [];
            if ( array_key_exists( 'html', $input ) ) {
                if ( ! is_string( $input['html'] ) ) { $renderer->fail( 'input', 'HTML content must be a string.' ); }
                $blocks = $renderer->html_blocks( $input['html'] );
            } elseif ( array_key_exists( 'blocks', $input ) ) { $blocks = $renderer->typed_blocks( $input['blocks'], 0 ); }
            if ( array_key_exists( 'faqs', $input ) ) { $blocks[] = $renderer->faq( $input['faqs'] ); }
            $elements = $renderer->elements( $blocks, 'blocks' );
            $layout = $profile['layout'];
            $root = $renderer->container( $elements, 'root', [ 'content_width' => 'boxed', 'boxed_width' => [ 'unit' => 'px', 'size' => $layout['max_width'], 'sizes' => [] ] ] );
            return [ 'elements' => [ $root ], 'blocks' => $blocks, 'warnings' => array_values( array_unique( $renderer->warnings ) ), 'renderer_version' => self::VERSION, 'preview_html' => $renderer->preview( $blocks ) ];
        } catch ( InvalidArgumentException $error ) { return Nova_Bridge_Suite_Content_Rules::error( 'content', $error->getMessage() ); }
    }

    private function fail( string $code, string $message ): void { throw new InvalidArgumentException( $code . ': ' . $message ); }
    private function counted(): void { if ( ++$this->count > self::MAX_BLOCKS ) { $this->fail( 'block_limit', 'Content contains more than 500 semantic blocks or FAQ items.' ); } }
    private function text( $value, string $name ): string {
        if ( ! is_string( $value ) || strlen( $value ) > self::MAX_BYTES || 1 !== preg_match( '//u', $value ) || false !== strpos( $value, "\0" ) ) { $this->fail( 'text', $name . ' must be bounded UTF-8 text.' ); }
        return $value;
    }
    private function safe_html( $value ): string {
        $html = $this->text( $value, 'HTML' );
        if ( ! function_exists( 'wp_kses_post' ) ) { $this->fail( 'html_policy', 'WordPress HTML sanitization is unavailable.' ); }
        $safe = wp_kses_post( $html );
        if ( $safe !== $html ) { $this->warnings[] = 'WordPress removed HTML that is outside its allowed post-content policy.'; }
        return $safe;
    }
    private function escaped( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
    private function list_array( $value ): bool { return is_array( $value ) && ( [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 ) ); }
    private function meaningful( string $html ): bool { return '' !== trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) || 1 === preg_match( '/<(?:img|video|audio|iframe|hr)\b|<[^>]+\b(?:id|name)\s*=/i', $html ); }
    private function anchor( $value ): string {
        if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/D', $value ) || isset( $this->anchors[ $value ] ) ) { $this->fail( 'anchor', 'Native anchors require unique IDs using letters, digits, hyphens or underscores.' ); }
        $this->anchors[ $value ] = true; return $value;
    }

    private function typed_blocks( $input, int $depth ): array {
        if ( $depth > self::MAX_DEPTH || ! $this->list_array( $input ) ) { $this->fail( 'blocks', 'Use ordered block arrays nested at most eight levels.' ); }
        $blocks = [];
        foreach ( $input as $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['type'] ?? null ) ) { $this->fail( 'blocks', 'Every typed block must identify its semantic type.' ); }
            $this->counted(); $type = $block['type'];
            if ( in_array( $type, [ 'heading', 'paragraph', 'rich_text' ], true ) ) {
                $allowed = 'heading' === $type ? [ 'type', 'level', 'html', 'text', 'anchor' ] : [ 'type', 'html', 'text', 'anchor' ];
                if ( array_diff( array_keys( $block ), $allowed ) || ( isset( $block['html'] ) && isset( $block['text'] ) ) || ( ! array_key_exists( 'html', $block ) && ! array_key_exists( 'text', $block ) ) ) { $this->fail( 'blocks', 'Supply a single text or HTML value for each text block.' ); }
                $html = array_key_exists( 'html', $block ) ? $this->safe_html( $block['html'] ) : $this->escaped( $this->text( $block['text'], 'Block text' ) );
                $normalized = [ 'type' => $type, 'html' => $html ];
                if ( isset( $block['anchor'] ) ) { $normalized['anchor'] = $this->anchor( $block['anchor'] ); }
                if ( 'heading' === $type ) {
                    $level = $block['level'] ?? 2;
                    if ( ! is_int( $level ) || $level < 1 || $level > 6 || preg_match( '/<(?:h[1-6]|p|div|section|ul|ol|table)\b/i', $html ) ) { $this->fail( 'heading', 'A heading requires a level from 1 to 6 and inline content only.' ); }
                    $normalized['level'] = $level;
                }
                elseif ( 'paragraph' === $type && preg_match( '/<(?:h[1-6]|p|div|section|ul|ol|table)\b/i', $html ) ) { $this->fail( 'paragraph', 'Paragraph blocks accept inline HTML. Use rich_text for complete HTML structures.' ); }
                $blocks[] = $normalized;
            } elseif ( 'image' === $type ) {
                if ( array_diff( array_keys( $block ), [ 'type', 'url', 'alt', 'anchor' ] ) ) { $this->fail( 'image', 'Image blocks accept a URL and alt text.' ); }
                $normalized = $this->image( $block['url'] ?? null, $block['alt'] ?? '' ); if ( isset( $block['anchor'] ) ) { $normalized['anchor'] = $this->anchor( $block['anchor'] ); } $blocks[] = $normalized;
            } elseif ( 'list' === $type ) {
                if ( array_diff( array_keys( $block ), [ 'type', 'ordered', 'items', 'html' ] ) || ! is_bool( $block['ordered'] ?? false ) || ( isset( $block['items'] ) && isset( $block['html'] ) ) ) { $this->fail( 'list', 'List blocks require ordered preference and either HTML or ordered items.' ); }
                $ordered = $block['ordered'] ?? false; $tag = $ordered ? 'ol' : 'ul';
                if ( isset( $block['html'] ) ) { $html = $this->safe_html( $block['html'] ); if ( ! preg_match( '/^\s*<' . $tag . '\b[^>]*>.*<\/' . $tag . '>\s*$/sD', $html ) ) { $this->fail( 'list', 'List HTML must contain one matching native list.' ); } }
                else {
                    if ( ! $this->list_array( $block['items'] ?? null ) || count( $block['items'] ) > self::MAX_BLOCKS ) { $this->fail( 'list', 'List items must be a bounded ordered array of inline HTML strings.' ); }
                    $html = '<' . $tag . '>';
                    foreach ( $block['items'] as $item ) { $this->counted(); $html .= '<li>' . $this->safe_html( $item ) . '</li>'; }
                    $html .= '</' . $tag . '>';
                }
                $blocks[] = [ 'type' => 'list', 'ordered' => $ordered, 'html' => $html ];
            } elseif ( 'faq' === $type ) {
                if ( array_diff( array_keys( $block ), [ 'type', 'items' ] ) ) { $this->fail( 'faq', 'FAQ blocks accept explicit question/answer items.' ); }
                $blocks[] = $this->faq( $block['items'] ?? null );
            } elseif ( 'group' === $type ) {
                if ( array_diff( array_keys( $block ), [ 'type', 'children', 'anchor' ] ) ) { $this->fail( 'group', 'Groups accept ordered children.' ); }
                $normalized = [ 'type' => 'group', 'children' => $this->typed_blocks( $block['children'] ?? null, $depth + 1 ) ]; if ( isset( $block['anchor'] ) ) { $normalized['anchor'] = $this->anchor( $block['anchor'] ); } $blocks[] = $normalized;
            } elseif ( 'repeat' === $type ) {
                if ( array_diff( array_keys( $block ), [ 'type', 'items' ] ) || ! $this->list_array( $block['items'] ?? null ) ) { $this->fail( 'repeat', 'Repeated groups require an ordered array of child groups.' ); }
                $children = [];
                foreach ( $block['items'] as $item ) {
                    $this->counted();
                    if ( is_array( $item ) && array_keys( $item ) === [ 'children' ] ) { $item = $item['children']; }
                    $children[] = [ 'type' => 'group', 'children' => $this->typed_blocks( $item, $depth + 1 ) ];
                }
                $blocks[] = [ 'type' => 'group', 'children' => $children ];
            } else { $this->fail( 'type', 'Unsupported semantic block type: ' . $type . '. Content was not discarded.' ); }
        }
        return $blocks;
    }

    private function image( $url, $alt ): array {
        $url = $this->text( $url, 'Image URL' ); $alt = $this->text( $alt, 'Image alt text' );
        $parts = parse_url( $url );
        if ( strlen( $url ) > 4096 || strlen( $alt ) > 2048 || ! is_array( $parts ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'https', 'http' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/[\x00-\x20<>"\\\\]/', $url ) ) { $this->fail( 'image', 'Images require an absolute HTTP(S) URL without embedded credentials.' ); }
        return [ 'type' => 'image', 'url' => $url, 'alt' => $alt ];
    }
    private function faq( $items ): array {
        if ( ! $this->list_array( $items ) || count( $items ) > 100 ) { $this->fail( 'faq', 'Supply at most 100 explicit FAQ question/answer pairs.' ); }
        $normalized = [];
        foreach ( $items as $item ) {
            $this->counted();
            if ( ! is_array( $item ) || array_diff( array_keys( $item ), [ 'question', 'answer' ] ) ) { $this->fail( 'faq', 'Every FAQ item requires question and answer fields.' ); }
            $question = $this->text( $item['question'] ?? null, 'FAQ question' );
            if ( strlen( $question ) > 4096 || preg_match( '/<[^>]*>/', $question ) ) { $this->fail( 'faq', 'FAQ questions must be plain text of at most 4096 bytes.' ); }
            $answer = $this->safe_html( $item['answer'] ?? null );
            if ( '' === trim( $question ) || ! $this->meaningful( $answer ) ) { $this->fail( 'faq', 'Every explicit FAQ must have a question and a nonempty answer.' ); }
            $normalized[] = [ 'question' => $question, 'answer' => $answer ];
        }
        return [ 'type' => 'faq', 'items' => $normalized ];
    }

    private function html_blocks( string $html ): array {
        $safe = $this->safe_html( $html );
        if ( '' === trim( $safe ) ) { return []; }
        if ( ! class_exists( 'DOMDocument' ) ) { $this->fail( 'html_parser', 'The DOM extension is required for structural HTML rendering.' ); }
        $previous = libxml_use_internal_errors( true );
        try {
            $document = new DOMDocument();
            $loaded = $document->loadHTML( '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $safe . '</body></html>', LIBXML_NONET );
            $errors = libxml_get_errors(); libxml_clear_errors();
            if ( ! $loaded || ! $document->getElementsByTagName( 'body' )->length ) { $this->fail( 'html_parser', 'The supplied HTML cannot be normalized safely.' ); }
            if ( $errors ) { $this->warnings[] = 'The HTML parser repaired markup before creating editable elements.'; }
            return $this->dom_blocks( $document->getElementsByTagName( 'body' )->item( 0 ), 0 );
        } finally { libxml_use_internal_errors( $previous ); }
    }
    private function inner_html( DOMNode $node ): string {
        $html = ''; foreach ( $node->childNodes as $child ) { $html .= $node->ownerDocument->saveHTML( $child ); } return $html;
    }
    private function dom_blocks( DOMNode $parent, int $depth ): array {
        if ( $depth > self::MAX_DEPTH ) { $this->fail( 'depth', 'HTML groups exceed eight nesting levels.' ); }
        $blocks = []; $inline = '';
        $flush = function () use ( &$blocks, &$inline ) { if ( $this->meaningful( $inline ) || ( ! $this->profile['hide_empty'] && '' !== trim( $inline ) ) ) { $this->counted(); $blocks[] = [ 'type' => 'paragraph', 'html' => $inline ]; } $inline = ''; };
        foreach ( $parent->childNodes as $node ) {
            if ( XML_COMMENT_NODE === $node->nodeType ) { continue; }
            if ( XML_TEXT_NODE === $node->nodeType ) { $inline .= $this->escaped( $node->nodeValue ); continue; }
            if ( ! $node instanceof DOMElement ) { $this->fail( 'html_node', 'An HTML node cannot be represented without content loss.' ); }
            $tag = strtolower( $node->tagName );
            if ( in_array( $tag, [ 'a', 'abbr', 'b', 'br', 'cite', 'code', 'em', 'i', 'mark', 'small', 'span', 'strong', 'sub', 'sup', 'u', 's' ], true ) ) { $inline .= $node->ownerDocument->saveHTML( $node ); continue; }
            $flush(); $this->counted();
            $native_tag = preg_match( '/^h[1-6]$/D', $tag ) || in_array( $tag, [ 'p', 'img', 'div', 'section', 'article', 'main' ], true );
            $allowed_attributes = 'img' === $tag ? [ 'id', 'class', 'style', 'src', 'alt', 'width', 'height' ] : [ 'id', 'class', 'style' ];
            $fallback = false;
            if ( $native_tag ) {
                foreach ( $node->attributes as $attribute ) { if ( ! in_array( strtolower( $attribute->name ), $allowed_attributes, true ) ) { $fallback = true; } }
                $anchor = $node->getAttribute( 'id' );
                if ( '' !== $anchor && ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/D', $anchor ) ) { $fallback = true; }
            }
            if ( $fallback ) { $blocks[] = [ 'type' => 'rich_text', 'html' => $node->ownerDocument->saveHTML( $node ) ]; $this->warnings[] = 'Rendered <' . $tag . '> as editable rich text to preserve its semantic attributes or anchor.'; continue; }
            if ( preg_match( '/^h([1-6])$/D', $tag, $match ) ) { $blocks[] = [ 'type' => 'heading', 'level' => (int) $match[1], 'html' => $this->inner_html( $node ) ]; if ( $node->hasAttribute( 'class' ) || $node->hasAttribute( 'style' ) ) { $this->warnings[] = 'HTML heading layout attributes are replaced by the rendering profile.'; } }
            elseif ( 'p' === $tag ) { $blocks[] = [ 'type' => 'paragraph', 'html' => $this->inner_html( $node ) ]; if ( $node->hasAttribute( 'class' ) || $node->hasAttribute( 'style' ) ) { $this->warnings[] = 'HTML paragraph layout attributes are replaced by the rendering profile.'; } }
            elseif ( in_array( $tag, [ 'ul', 'ol' ], true ) ) { $blocks[] = [ 'type' => 'list', 'ordered' => 'ol' === $tag, 'html' => $node->ownerDocument->saveHTML( $node ) ]; }
            elseif ( 'img' === $tag ) { $blocks[] = $this->image( $node->getAttribute( 'src' ), $node->getAttribute( 'alt' ) ); if ( $node->hasAttribute( 'class' ) || $node->hasAttribute( 'style' ) || $node->hasAttribute( 'width' ) || $node->hasAttribute( 'height' ) ) { $this->warnings[] = 'Native image elements use the source URL and alt text; source HTML image layout attributes are replaced by the rendering profile.'; } }
            elseif ( in_array( $tag, [ 'div', 'section', 'article', 'main' ], true ) ) { $blocks[] = [ 'type' => 'group', 'children' => $this->dom_blocks( $node, $depth + 1 ) ]; if ( $node->hasAttribute( 'class' ) || $node->hasAttribute( 'style' ) ) { $this->warnings[] = 'HTML group layout attributes are replaced by the rendering profile.'; } }
            else { $blocks[] = [ 'type' => 'rich_text', 'html' => $node->ownerDocument->saveHTML( $node ) ]; $this->warnings[] = 'Rendered <' . $tag . '> as editable rich text to preserve its content and structure.'; }
            if ( $native_tag && '' !== $node->getAttribute( 'id' ) ) { $blocks[ count( $blocks ) - 1 ]['anchor'] = $this->anchor( $node->getAttribute( 'id' ) ); }
        }
        $flush(); return $blocks;
    }

    private function id( string $path ): string {
        if ( count( $this->ids ) >= 2000 ) { $this->fail( 'element_limit', 'The rendered native document exceeds 2000 elements and FAQ tabs.' ); }
        $salt = 0;
        do { $id = substr( hash( 'sha256', self::VERSION . "\0" . $this->seed . "\0" . $path . "\0" . $salt++ ), 0, 7 ); } while ( isset( $this->ids[ $id ] ) );
        $this->ids[ $id ] = true; return $id;
    }
    private function styles( array $rule ): array {
        $native = []; $settings = $rule['settings'];
        foreach ( $settings as $key => $value ) {
            if ( 'typography_font_size' === $key ) { $native['typography_typography'] = 'custom'; $native['typography_font_size'] = [ 'unit' => 'px', 'size' => $value, 'sizes' => [] ]; }
            elseif ( 'line_height' === $key ) { $native['typography_typography'] = 'custom'; $native['typography_line_height'] = [ 'unit' => 'em', 'size' => $value, 'sizes' => [] ]; }
            elseif ( 'color' === $key ) { $native[ 'heading' === $rule['element'] ? 'title_color' : 'text_color' ] = $value; }
            elseif ( 'align' === $key ) { $native['align'] = $value; }
        }
        return $native;
    }
    private function widget( string $type, array $settings, string $path ): array { return [ 'id' => $this->id( $path ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => [] ]; }
    private function container( array $children, string $path, array $settings = [] ): array {
        $gap = $this->profile['layout']['gap'];
        return [ 'id' => $this->id( $path ), 'elType' => 'container', 'isInner' => 'root' !== $path, 'settings' => array_merge( [ 'flex_direction' => 'column', 'flex_gap' => [ 'column' => $gap, 'row' => $gap, 'unit' => 'px', 'isLinked' => true ] ], $settings ), 'elements' => $children ];
    }
    private function elements( array $blocks, string $prefix ): array {
        $elements = [];
        foreach ( $blocks as $index => $block ) {
            $path = $prefix . '.' . $index; $type = $block['type']; $rule = $this->profile['rules'][ $type ]; $settings = $this->styles( $rule );
            if ( isset( $block['anchor'] ) ) { $settings['_element_id'] = $block['anchor']; }
            if ( in_array( $type, [ 'heading', 'paragraph', 'rich_text', 'list' ], true ) ) {
                if ( $this->profile['hide_empty'] && ! $this->meaningful( $block['html'] ) && ! isset( $block['anchor'] ) ) { continue; }
                if ( 'heading' === $type ) {
                    $tag = $rule['settings']['html_tag'] ?? 'h' . $block['level'];
                    if ( 'heading' === $rule['element'] ) { $settings['title'] = $block['html']; $settings['header_size'] = $tag; }
                    else { $settings['editor'] = '<' . $tag . '>' . $block['html'] . '</' . $tag . '>'; }
                } else { $settings['editor'] = 'paragraph' === $type ? '<p>' . $block['html'] . '</p>' : $block['html']; }
                $elements[] = $this->widget( $rule['element'], $settings, $path );
            } elseif ( 'image' === $type ) {
                $settings['image'] = [ 'url' => $block['url'], 'id' => 0, 'alt' => $block['alt'] ]; $settings['image_size'] = 'full';
                $elements[] = $this->widget( 'image', $settings, $path );
            } elseif ( 'group' === $type ) {
                $children = $this->elements( $block['children'], $path . '.children' );
                if ( ! $children && $this->profile['hide_empty'] && ! isset( $block['anchor'] ) ) { continue; }
                $elements[] = $this->container( $children, $path, $settings );
            } elseif ( 'faq' === $type ) {
                if ( ! $block['items'] && $this->profile['hide_empty'] ) { continue; }
                if ( 'accordion' === $rule['element'] ) {
                    $tabs = [];
                    foreach ( $block['items'] as $i => $item ) { $tabs[] = [ '_id' => $this->id( $path . '.faq.' . $i ), 'tab_title' => $this->escaped( $item['question'] ), 'tab_content' => $item['answer'] ]; }
                    $settings['tabs'] = $tabs; $settings['active_item_no'] = '1'; $elements[] = $this->widget( 'accordion', $settings, $path );
                } else {
                    $children = [];
                    foreach ( $block['items'] as $i => $item ) { $children[] = $this->container( $this->elements( [ [ 'type' => 'heading', 'level' => 3, 'html' => $this->escaped( $item['question'] ) ], [ 'type' => 'rich_text', 'html' => $item['answer'] ] ], $path . '.faq.' . $i . '.content' ), $path . '.faq.' . $i ); }
                    $elements[] = $this->container( $children, $path, $settings );
                }
            }
        }
        return $elements;
    }
    private function preview( array $blocks ): string {
        $html = '';
        foreach ( $blocks as $block ) {
            $type = $block['type'];
            $anchor = isset( $block['anchor'] ) ? ' id="' . $this->escaped( $block['anchor'] ) . '"' : '';
            if ( isset( $block['html'] ) && $this->profile['hide_empty'] && ! $this->meaningful( $block['html'] ) && ! isset( $block['anchor'] ) ) { continue; }
            if ( 'heading' === $type ) { $tag = $this->profile['rules']['heading']['settings']['html_tag'] ?? 'h' . $block['level']; $html .= '<' . $tag . $anchor . '>' . $block['html'] . '</' . $tag . '>'; }
            elseif ( 'paragraph' === $type ) { $html .= '<p' . $anchor . '>' . $block['html'] . '</p>'; }
            elseif ( in_array( $type, [ 'rich_text', 'list' ], true ) ) { $html .= '' !== $anchor ? '<div' . $anchor . '>' . $block['html'] . '</div>' : $block['html']; }
            elseif ( 'image' === $type ) { $html .= '<img' . $anchor . ' src="' . $this->escaped( $block['url'] ) . '" alt="' . $this->escaped( $block['alt'] ) . '">'; }
            elseif ( 'group' === $type ) { $html .= '<div' . $anchor . ' class="nova-content-rule-group">' . $this->preview( $block['children'] ) . '</div>'; }
            elseif ( 'faq' === $type ) {
                if ( 'accordion' === $this->profile['rules']['faq']['element'] ) {
                    foreach ( $block['items'] as $item ) { $html .= '<details><summary>' . $this->escaped( $item['question'] ) . '</summary>' . $item['answer'] . '</details>'; }
                } elseif ( $block['items'] || ! $this->profile['hide_empty'] ) {
                    $html .= '<div class="nova-content-rule-faq">';
                    foreach ( $block['items'] as $item ) {
                        $html .= '<div class="nova-content-rule-faq-item">' . $this->preview( [ [ 'type' => 'heading', 'level' => 3, 'html' => $this->escaped( $item['question'] ) ], [ 'type' => 'rich_text', 'html' => $item['answer'] ] ] ) . '</div>';
                    }
                    $html .= '</div>';
                }
            }
        }
        return $html;
    }
}
