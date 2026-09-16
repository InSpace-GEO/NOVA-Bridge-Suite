<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keep supported layouts intact; adapt only an unavailable accordion family. */
function nova_wpb_compatible_accordions( $shortcodes ) {
	// REST requests do not reach WPBakery's template_redirect registration hook.
	// Register its current map, which already excludes elements removed by the theme.
	if ( is_callable( array( 'WPBMap', 'addAllMappedShortcodes' ) ) ) {
		WPBMap::addAllMappedShortcodes();
	}
	$core = shortcode_exists( 'vc_tta_accordion' ) && shortcode_exists( 'vc_tta_section' );
	$salient = shortcode_exists( 'toggles' ) && shortcode_exists( 'toggle' );
	$needs_core = ! $core && false !== strpos( $shortcodes, '[vc_tta_accordion' );
	$needs_salient = ! $salient && false !== strpos( $shortcodes, '[toggles' );
	if ( ! $needs_core && ! $needs_salient ) {
		return $shortcodes;
	}
	$coverage = nova_wpb_validate_roundtrip_coverage( $shortcodes );
	if ( is_wp_error( $coverage ) ) {
		return $coverage;
	}
	$tree = nova_wpb_parse_shortcodes_to_compact( $shortcodes );
	$walk = function ( $nodes ) use ( &$walk, $core, $salient ) {
		$out = array();
		foreach ( $nodes as $node ) {
			$tag = $node['tag'] ?? '';
			$convert = ( 'vc_tta_accordion' === $tag && ! $core ) || ( 'toggles' === $tag && ! $salient );
			if ( ! $convert ) {
				if ( ! empty( $node['children'] ) ) {
					$node['children'] = $walk( $node['children'] );
				}
				$out[] = $node;
				continue;
			}
			$children = array();
			foreach ( $node['children'] ?? array() as $index => $section ) {
				if ( ! in_array( $section['tag'] ?? '', array( 'vc_tta_section', 'toggle' ), true ) ) {
					$children = array_merge( $children, $walk( array( $section ) ) );
					continue;
				}
				$title = (string) ( $section['attributes']['title'] ?? '' );
				$section['children'] = $walk( $section['children'] ?? array() );
				if ( $core || $salient ) {
					$section['tag'] = $core ? 'vc_tta_section' : 'toggle';
					$section['attributes'] = $core
						? array( 'title' => $title, 'tab_id' => substr( md5( $index . '|' . $title ), 0, 8 ) )
						: array( 'title' => $title, 'color' => 'Default', 'heading_tag' => 'h3' );
					$children[] = $section;
				} else {
					// No accordion implementation: retain every title and answer as visible content.
					$heading = array( 'tag' => 'vc_column_text', 'attributes' => array(), 'text' => '<h3>' . esc_html( $title ) . '</h3>' . ( $section['text'] ?? '' ), 'children' => array(), 'syntax' => 'paired', 'self_closing' => false );
					$children[] = $heading;
					$children = array_merge( $children, $section['children'] );
				}
			}
			if ( $core || $salient ) {
				$node['tag'] = $core ? 'vc_tta_accordion' : 'toggles';
				$node['attributes'] = $core
					? array( 'style' => 'flat', 'active_section' => '1', 'collapsible_all' => 'true' )
					: array( 'style' => 'default', 'accordion' => 'true' );
				$node['children'] = $children;
				$out[] = $node;
			} else {
				if ( '' !== trim( $node['text'] ?? '' ) ) {
					$out[] = array( 'tag' => 'vc_column_text', 'attributes' => array(), 'text' => $node['text'], 'children' => array(), 'syntax' => 'paired', 'self_closing' => false );
				}
				$out = array_merge( $out, $children );
			}
		}
		return $out;
	};
	return nova_wpb_compact_to_shortcodes( $walk( $tree ) );
}
