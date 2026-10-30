<?php
/** Lossless shortcode carrier: formatting and unselected HTML remain native source. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Flatsome_Document {
    private const TOKEN = '~\[(/?)([a-zA-Z_][a-zA-Z0-9_-]*)(?=[\s/\]])(?:"[^"]*"|\x27[^\x27]*\x27|[^\]\x22\x27])*\]~s';

    public static function parse( string $source ): array {
        if ( strlen( $source ) > 2097152 ) { throw new RuntimeException( 'The Flatsome document exceeds two megabytes.' ); }
        preg_match_all( self::TOKEN, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        preg_match_all( '~<!--[^>]*[\s\S]*?-->|<(script|style|iframe|form)\b[^>]*>[\s\S]*?</\1\s*>|<[^>]+>~i', $source, $opaque, PREG_OFFSET_CAPTURE );
        $tokens = [];
        foreach ( $matches as $match ) {
            $offset = $match[0][1]; $raw = $match[0][0];
            $inside_html = false;
            foreach ( $opaque[0] as $span ) { if ( $offset >= $span[1] && $offset < $span[1] + strlen( $span[0] ) ) { $inside_html = true; break; } }
            if ( $inside_html ) { continue; }
            // Escaped shortcodes are prose, never editable native controls.
            if ( ( $offset > 0 && '[' === $source[$offset - 1] ) || ( substr( $source, $offset + strlen( $raw ), 1 ) === ']' ) ) { continue; }
            $tokens[] = [ 'raw' => $raw, 'offset' => $offset, 'tag' => $match[2][0], 'closing' => '/' === $match[1][0], 'self' => 1 === preg_match( '~/\s*\]$~', $raw ) ];
        }
        $pairs = []; $stack = [];
        foreach ( $tokens as $i => $token ) {
            if ( $token['closing'] ) {
                // Standalone shortcodes can occur inside paired containers.
                for ( $j = count( $stack ) - 1; $j >= 0; --$j ) {
                    $open = $stack[$j];
                    if ( $tokens[$open]['tag'] === $token['tag'] ) { $pairs[$open] = $i; $stack = array_slice( $stack, 0, $j ); break; }
                }
            } elseif ( ! $token['self'] ) { $stack[] = $i; }
        }
        $count = 0;
        return self::range( $source, $tokens, $pairs, 0, count( $tokens ), 0, strlen( $source ), 0, $count );
    }

    private static function range( string $source, array $tokens, array $pairs, int $first, int $last, int $start, int $end, int $depth, int &$count ): array {
        if ( $depth > 32 ) { throw new RuntimeException( 'The Flatsome document exceeds 32 nested levels.' ); }
        $nodes = []; $cursor = $start;
        for ( $i = $first; $i < $last; ++$i ) {
            $token = $tokens[$i];
            if ( $token['offset'] < $cursor || $token['offset'] >= $end ) { continue; }
            if ( $token['closing'] ) { continue; }
            self::html( $nodes, substr( $source, $cursor, $token['offset'] - $cursor ) );
            $open_end = $token['offset'] + strlen( $token['raw'] ); $close = isset( $pairs[$i] ) && $pairs[$i] < $last ? $pairs[$i] : null;
            $attrs = shortcode_parse_atts( preg_replace( '~^\[[a-zA-Z_][a-zA-Z0-9_-]*|/?\]$~', '', $token['raw'] ) );
            $attrs = is_array( $attrs ) ? $attrs : [];
            $children = null === $close ? [] : self::range( $source, $tokens, $pairs, $i + 1, $close, $open_end, $tokens[$close]['offset'], $depth + 1, $count );
            $nodes[] = [ 'kind' => 'shortcode', 'tag' => $token['tag'], 'attributes' => $attrs, 'original_attributes' => $attrs, 'raw_open' => $token['raw'], 'raw_close' => null === $close ? '' : $tokens[$close]['raw'], 'children' => $children ];
            if ( ++$count > 4000 ) { throw new RuntimeException( 'The Flatsome document contains too many native nodes.' ); }
            $cursor = null === $close ? $open_end : $tokens[$close]['offset'] + strlen( $tokens[$close]['raw'] );
            if ( null !== $close ) { $i = $close; }
        }
        self::html( $nodes, substr( $source, $cursor, $end - $cursor ) );
        return $nodes;
    }

    /** Keep layout wrappers raw; complete adjacent body blocks form one rich-text destination. */
    private static function html( array &$nodes, string $source ): void {
        if ( '' === $source ) { return; }
        // Editor HTML/code islands remain opaque; never expose strings that merely look like prose inside them.
        if ( preg_match( '~<(?:script|style|iframe|form)\b|<!--(?!\s*/?wp:flatsome/uxbuilder)~i', $source ) ) { self::raw( $nodes, $source ); return; }
        preg_match_all( '~<(h[1-6]|p|ul|ol|table|blockquote|pre|figure|dl)\b[^>]*>~i', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
        $cursor = 0; $body = '';
        $flush = static function () use ( &$nodes, &$body ) { if ( '' !== $body ) { $nodes[] = [ 'kind' => 'html', 'tag' => 'body', 'raw_open' => '', 'content' => $body, 'raw_close' => '' ]; $body = ''; } };
        foreach ( $matches as $match ) {
            $offset = $match[0][1]; if ( $offset < $cursor ) { continue; }
            $tag = strtolower( $match[1][0] ); $open_end = $offset + strlen( $match[0][0] );
            preg_match_all( '~</?' . preg_quote( $tag, '~' ) . '\b[^>]*>~i', substr( $source, $open_end ), $ends, PREG_OFFSET_CAPTURE );
            $depth = 1; $close = null;
            foreach ( $ends[0] as $end ) { $depth += str_starts_with( $end[0], '</' ) ? -1 : 1; if ( 0 === $depth ) { $close = [ $end[0], $open_end + $end[1] ]; break; } }
            if ( null === $close ) { continue; }
            $gap = substr( $source, $cursor, $offset - $cursor );
            if ( '' !== trim( $gap ) ) { $flush(); self::raw( $nodes, $gap ); }
            elseif ( '' !== $body ) { $body .= $gap; }
            elseif ( '' !== $gap ) { self::raw( $nodes, $gap ); }
            if ( preg_match( '/^h[1-6]$/', $tag ) ) {
                $flush(); $nodes[] = [ 'kind' => 'html', 'tag' => $tag, 'raw_open' => $match[0][0], 'content' => substr( $source, $open_end, $close[1] - $open_end ), 'raw_close' => $close[0] ];
            } else { $body .= substr( $source, $offset, $close[1] + strlen( $close[0] ) - $offset ); }
            $cursor = $close[1] + strlen( $close[0] );
        }
        $flush();
        if ( $cursor < strlen( $source ) ) { self::raw( $nodes, substr( $source, $cursor ) ); }
    }

    private static function raw( array &$nodes, string $source ): void {
        $nodes[] = [ 'kind' => 'raw', 'content' => $source ];
    }

    public static function serialize( array $nodes ): string {
        $result = '';
        foreach ( $nodes as $node ) {
            if ( 'shortcode' === ( $node['kind'] ?? '' ) ) { $result .= self::opening( $node ) . ( $node['content'] ?? self::serialize( $node['children'] ) ) . $node['raw_close']; }
            elseif ( 'html' === ( $node['kind'] ?? '' ) ) { $result .= $node['raw_open'] . $node['content'] . $node['raw_close']; }
            else { $result .= $node['content']; }
        }
        return $result;
    }

    /** Change only chosen attribute values, preserving attribute order, quotes and other source bytes. */
    private static function opening( array $node ): string {
        $open = $node['raw_open']; $original = $node['original_attributes']; $attrs = $node['attributes'];
        foreach ( $attrs as $name => $value ) {
            if ( array_key_exists( $name, $original ) && (string) $original[$name] === (string) $value ) { continue; }
            if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_-]*$/D', $name ) ) { throw new RuntimeException( 'Invalid Flatsome attribute name.' ); }
            $encoded = htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
            $pattern = '~(?<=\s)(' . preg_quote( $name, '~' ) . '\s*=\s*)(?:"[^"]*"|\x27[^\x27]*\x27|[^\s\]]+)~i';
            $changed = 0;
            $open = preg_replace_callback( $pattern, static function ( $match ) use ( $encoded ) { $raw = substr( $match[0], strlen( $match[1] ) ); $quote = isset( $raw[0] ) && "'" === $raw[0] ? "'" : '"'; return $match[1] . $quote . $encoded . $quote; }, $open, -1, $changed );
            if ( ! $changed ) { $open = preg_replace_callback( '~\s*/?\]$~', static function ( $match ) use ( $name, $encoded ) { return ' ' . $name . '="' . $encoded . '"' . $match[0]; }, $open ); }
        }
        return $open;
    }
}
