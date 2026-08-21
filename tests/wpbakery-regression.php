<?php
/**
 * Run with: wp eval-file tests/wpbakery-regression.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

function nova_wpb_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

nova_wpb_test_assert( function_exists( 'nova_wpb_parse_shortcodes_to_compact' ), 'Enable the WPBakery bridge before running this check.' );
nova_wpb_test_assert( function_exists( 'nova_wpb_apply_text_updates_to_compact' ), 'The WPBakery transformations are not loaded.' );

$shortcodes = <<<'WPB'
LEAD
[vc_row data-keep="row-opaque"]
[vc_column width="1/1"]Intro<!--keep-between-->
[split_line_heading text_content="Microneedling" custom_attr="keep-heading"]
[nectar_btn  text = 'Maak afspraak'   url=/eerste-consult-aanvragen/ data-keep="button&amp;opaque"]
[vc_btn title="Standard button" link='url:https%3A%2F%2Fold.example%2Fstart|title:Keep%20Title|target:_blank|rel:nofollow' data-keep="vc-btn-opaque" /]
[image_with_animation image_url="4839" animation="Fade In" data-keep="image-opaque" /]
[vc_empty_space height="32px"]opaque-spacer[/vc_empty_space]
[toggles style="default" data-keep="toggles-opaque"][toggle title="Vraag?" color="Default"]BEFORE[nectar_responsive_text font_size="18" data-keep="responsive-opaque"]<p>Antwoord <strong>blijft</strong>.</p>[/nectar_responsive_text]AFTER[/toggle][/toggles]
[nectar_cta link_text="Lees meer" url="/behandelingen/combinedtherapy/" data-keep="cta-opaque"]<p>CTA copy</p>[/nectar_cta]
[/vc_column]
[/vc_row]
TRAIL
WPB;

$compact   = nova_wpb_parse_shortcodes_to_compact( $shortcodes );
$roundtrip = nova_wpb_compact_to_shortcodes( $compact );

nova_wpb_test_assert( true === nova_wpb_validate_roundtrip_coverage( $shortcodes, $compact ), 'The complete Salient document failed the coverage check.' );
nova_wpb_test_assert( $shortcodes === $roundtrip, 'An unchanged WPBakery/Salient document was not preserved byte-for-byte.' );
nova_wpb_test_assert( 1 === substr_count( $roundtrip, '[nectar_btn ' ), 'The standalone Nectar button was dropped or duplicated.' );
nova_wpb_test_assert( false === strpos( $roundtrip, '[/nectar_btn]' ), 'A closing tag was added to a standalone Nectar button.' );
nova_wpb_test_assert( false !== strpos( $roundtrip, 'data-keep="image-opaque" /]' ), 'Explicit self-closing syntax was not preserved.' );
nova_wpb_test_assert( false !== strpos( $roundtrip, 'Intro<!--keep-between-->' ), 'Raw content between shortcode siblings was dropped.' );
nova_wpb_test_assert( false !== strpos( $roundtrip, 'BEFORE[nectar_responsive_text' ) && false !== strpos( $roundtrip, '[/nectar_responsive_text]AFTER' ), 'Raw content around a nested shortcode was dropped.' );

$outline        = nova_wpb_build_outline_from_compact( $compact, false );
$paths          = array();
$outline_by_tag = array();
foreach ( $outline as $item ) {
	$tag = isset( $item['tag'] ) ? (string) $item['tag'] : '';
	if ( '' !== $tag ) {
		$paths[ $tag ]          = (string) $item['path'];
		$outline_by_tag[ $tag ] = $item;
	}
}

foreach ( array( 'split_line_heading', 'nectar_btn', 'vc_btn', 'image_with_animation', 'toggle', 'nectar_responsive_text', 'nectar_cta' ) as $tag ) {
	nova_wpb_test_assert( isset( $outline_by_tag[ $tag ] ), 'The outline omitted ' . $tag . '.' );
}
nova_wpb_test_assert( ! isset( $outline_by_tag['vc_empty_space'] ), 'A spacer body was incorrectly advertised as editable.' );
nova_wpb_test_assert( isset( $outline_by_tag['split_line_heading']['fields']['text_content'] ), 'Split-line heading text was not exposed.' );
nova_wpb_test_assert( isset( $outline_by_tag['nectar_btn']['fields']['text'], $outline_by_tag['nectar_btn']['fields']['url'] ), 'Nectar button text/URL were not exposed on one path.' );
nova_wpb_test_assert( isset( $outline_by_tag['vc_btn']['fields']['title'], $outline_by_tag['vc_btn']['fields']['link_url'] ), 'Packed standard WPBakery button link was not exposed.' );
nova_wpb_test_assert( 'https://old.example/start' === $outline_by_tag['vc_btn']['fields']['link_url']['value'], 'Packed WPBakery URL was not decoded for editing.' );
nova_wpb_test_assert( isset( $outline_by_tag['image_with_animation']['fields']['image_url'] ), 'Nectar image was not exposed.' );
nova_wpb_test_assert( isset( $outline_by_tag['toggle']['fields']['title'] ), 'Toggle title was not exposed.' );
nova_wpb_test_assert( isset( $outline_by_tag['nectar_responsive_text']['fields']['body'] ), 'Responsive text body was not exposed.' );
nova_wpb_test_assert( isset( $outline_by_tag['nectar_cta']['fields']['link_text'], $outline_by_tag['nectar_cta']['fields']['url'], $outline_by_tag['nectar_cta']['fields']['body'] ), 'Nectar CTA carriers were not exposed on one path.' );

$text_map_keys = array();
foreach ( nova_wpb_build_text_map_from_compact( $compact ) as $item ) {
	$text_map_keys[ $item['path'] . '|' . $item['field'] ] = true;
}
nova_wpb_test_assert( isset( $text_map_keys[ $paths['nectar_btn'] . '|text' ], $text_map_keys[ $paths['nectar_btn'] . '|url' ] ), 'The text map omitted button field qualifiers.' );
nova_wpb_test_assert( isset( $text_map_keys[ $paths['vc_btn'] . '|link_url' ] ), 'The text map omitted the packed button URL carrier.' );
nova_wpb_test_assert( isset( $text_map_keys[ $paths['nectar_cta'] . '|body' ] ), 'The text map omitted a rich body carrier.' );

$single_field = nova_wpb_apply_transformations(
	$shortcodes,
	array(),
	array( array( 'path' => $paths['nectar_btn'], 'field' => 'text', 'text' => 'Boek consult' ) ),
	'',
	array()
);
nova_wpb_test_assert( ! is_wp_error( $single_field ), 'A supported single-field edit failed.' );
nova_wpb_test_assert(
	str_replace( "text = 'Maak afspraak'", "text = 'Boek consult'", $shortcodes ) === $single_field,
	'A single field edit changed unrelated whitespace, quote style, entities, attributes, or body bytes.'
);

$mixed_transform = nova_wpb_apply_transformations(
	$shortcodes,
	array( $paths['split_line_heading'] ),
	array( array( 'path' => $paths['nectar_btn'], 'field' => 'text', 'text' => 'Boek na verwijderen' ) ),
	'',
	array()
);
nova_wpb_test_assert( ! is_wp_error( $mixed_transform ), 'Combining removal with a later-sibling update failed.' );
nova_wpb_test_assert( false === strpos( $mixed_transform, '[split_line_heading' ), 'The requested earlier sibling was not removed.' );
nova_wpb_test_assert( false !== strpos( $mixed_transform, "text = 'Boek na verwijderen'" ), 'The update did not keep its original outline path.' );
nova_wpb_test_assert( false !== strpos( $mixed_transform, 'title="Standard button"' ), 'A later sibling was changed after path reindexing.' );

$updated = nova_wpb_apply_text_updates_to_compact(
	$compact,
	array(
		// Legacy path/text remains compatible and resolves to text_content.
		array( 'path' => $paths['split_line_heading'], 'text' => '<b>Nieuwe heading</b>' ),
		array( 'path' => $paths['nectar_btn'], 'field' => 'text', 'text' => 'Boek consult' ),
		array( 'path' => $paths['nectar_btn'], 'field' => 'url', 'text' => '/nieuw/#stap' ),
		array( 'path' => $paths['vc_btn'], 'field' => 'link_url', 'text' => 'https://new.example/path?x=1#section' ),
		array( 'path' => $paths['toggle'], 'field' => 'title', 'text' => '<b>Nieuwe vraag?</b>' ),
		array( 'path' => $paths['nectar_responsive_text'], 'field' => 'body', 'text' => '<p><em>Nieuw antwoord</em><script>alert(1)</script></p>' ),
		array( 'path' => $paths['nectar_cta'], 'field' => 'link_text', 'text' => 'Details' ),
		array( 'path' => $paths['nectar_cta'], 'field' => 'url', 'text' => '#details' ),
		array( 'path' => $paths['image_with_animation'], 'field' => 'image_url', 'text' => '999' ),
	)
);
nova_wpb_test_assert( ! is_wp_error( $updated ), 'Supported multi-field edits failed.' );
$updated_shortcodes = nova_wpb_compact_to_shortcodes( $updated );

nova_wpb_test_assert( false !== strpos( $updated_shortcodes, 'text_content="Nieuwe heading" custom_attr="keep-heading"' ), 'Legacy heading update did not target text_content or preserve its opaque attribute.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, "text = 'Boek consult'   url=/nieuw/#stap data-keep=\"button&amp;opaque\"" ), 'Multi-field button update failed or changed unrelated opening-tag bytes.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, "link='url:https%3A%2F%2Fnew.example%2Fpath%3Fx%3D1%23section|title:Keep%20Title|target:_blank|rel:nofollow' data-keep=\"vc-btn-opaque\"" ), 'Packed button URL update changed title/target/rel or unrelated bytes.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, 'title="Nieuwe vraag?" color="Default"' ), 'Toggle title was not sanitized as plain text.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, '<p><em>Nieuw antwoord</em>alert(1)</p>' ), 'Rich body sanitization removed safe markup or content.' );
nova_wpb_test_assert( false === strpos( $updated_shortcodes, '<script' ), 'Rich body update retained an unsafe script tag.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, 'link_text="Details" url="#details" data-keep="cta-opaque"' ), 'CTA text/fragment update failed or changed an unrelated attribute.' );
nova_wpb_test_assert( false !== strpos( $updated_shortcodes, 'image_url="999" animation="Fade In" data-keep="image-opaque" /]' ), 'Image update failed or changed structural attributes/syntax.' );
nova_wpb_test_assert( 1 === substr_count( $updated_shortcodes, '[nectar_btn ' ) && false === strpos( $updated_shortcodes, '[/nectar_btn]' ), 'Editing changed the standalone Nectar button structure.' );

$unsafe = nova_wpb_apply_transformations(
	$shortcodes,
	array(),
	array(
		array( 'path' => $paths['nectar_btn'], 'field' => 'url', 'text' => 'JaVaScRiPt:alert(1)' ),
	),
	'',
	array()
);
nova_wpb_test_assert( is_wp_error( $unsafe ), 'An unsafe JavaScript URL was accepted.' );
nova_wpb_test_assert( 'nova_wpb_invalid_field_value' === $unsafe->get_error_code(), 'Unsafe URL rejection returned the wrong error.' );

$unsupported = nova_wpb_apply_text_updates_to_compact(
	$compact,
	array( array( 'path' => $paths['nectar_btn'], 'field' => 'data-keep', 'text' => 'must-not-write' ) )
);
nova_wpb_test_assert( is_wp_error( $unsupported ), 'A structural shortcode attribute was writable.' );
nova_wpb_test_assert( 'nova_wpb_unsupported_field' === $unsupported->get_error_code(), 'Unsupported field rejection returned the wrong error.' );

$invalid_path = nova_wpb_apply_transformations(
	$shortcodes,
	array(),
	array( array( 'path' => '99.99', 'field' => 'body', 'text' => 'must-not-write' ) ),
	'',
	array()
);
nova_wpb_test_assert( is_wp_error( $invalid_path ), 'A nonexistent compact path was accepted.' );
nova_wpb_test_assert( 'nova_wpb_invalid_path' === $invalid_path->get_error_code(), 'Invalid path rejection returned the wrong error.' );

$incomplete = $compact;
array_pop( $incomplete[0]['children'][0]['children'] );
$coverage_error = nova_wpb_validate_roundtrip_coverage( $shortcodes, $incomplete );
nova_wpb_test_assert( is_wp_error( $coverage_error ), 'Incomplete compact data passed the fail-closed coverage check.' );
nova_wpb_test_assert( 422 === (int) $coverage_error->get_error_data()['status'], 'Coverage failure did not return HTTP 422 metadata.' );

nova_wpb_test_assert( function_exists( 'nova_wpb_replace_template_slots_with_sections' ), 'The WPBakery slot replacer is not loaded.' );

/*
 * NOVA-268: a cloned content-filled template must keep its chrome, pair each section's
 * title with its own body, and fill slots built from the theme's own text elements
 * rather than appending everything below the template's copy.
 */
$template = '[vc_row el_id="hero"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Hero titel" font_container="tag:h1"]'
	. '[vc_column_text]<p>Hero copy</p>[/vc_column_text]'
	. '[vc_btn title="Vraag offerte aan" link="url:%2Fofferte%2F"]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_id="slot-a" el_class="content"][vc_column width="1/1"]'
	. '[split_line_heading text_content="Oude Salient kop"]'
	. '[nectar_responsive_text font_size="18"]<p>Oude Salient tekst</p>[/nectar_responsive_text]'
	. '[nectar_btn text="Lees meer" url="/meer/"]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude kop B" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude tekst B</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$slot_sections = array(
	array( 'title' => 'Sectie een', 'body' => '<p>Body een</p>', 'title_tag' => 'h2' ),
	array( 'title' => 'Sectie twee', 'body' => '<p>Body twee</p>', 'title_tag' => 'h2' ),
	array( 'title' => 'Sectie drie', 'body' => '<p>Body drie</p>', 'title_tag' => 'h2' ),
);

$slot_report = null;
list( $slot_filled, $slot_left ) = nova_wpb_replace_template_slots_with_sections( $template, $slot_sections, 'Pagina titel', true, $slot_report );

nova_wpb_test_assert( 2 === $slot_report['slots_found'], 'Expected two eligible slots, got ' . $slot_report['slots_found'] . '.' );
nova_wpb_test_assert( 2 === $slot_report['slots_filled'], 'Both content slots should have been filled.' );
nova_wpb_test_assert( 1 === count( $slot_left ), 'The third section should overflow.' );

nova_wpb_test_assert( false !== strpos( $slot_filled, 'text="Hero titel"' ), 'The hero heading was overwritten.' );
nova_wpb_test_assert( false !== strpos( $slot_filled, '<p>Hero copy</p>' ), 'The hero copy was overwritten.' );
nova_wpb_test_assert( false !== strpos( $slot_filled, 'title="Vraag offerte aan"' ), 'The hero button was destroyed.' );

nova_wpb_test_assert( false !== strpos( $slot_filled, 'text_content="Sectie een"' ), 'A Salient heading slot did not receive the section title.' );
nova_wpb_test_assert( false !== strpos( $slot_filled, '<p>Body een</p>' ), 'A Salient body slot did not receive the section body.' );
nova_wpb_test_assert( false === strpos( $slot_filled, 'Oude Salient kop' ), 'The Salient template copy survived next to the generated copy.' );
nova_wpb_test_assert( false === strpos( $slot_filled, 'Oude Salient tekst' ), 'The Salient template body survived next to the generated copy.' );
nova_wpb_test_assert( false !== strpos( $slot_filled, 'text="Lees meer"' ), 'A button inside a filled row was overwritten.' );

nova_wpb_test_assert( false !== strpos( $slot_filled, 'text="Sectie twee"' ), 'The second section did not fill the second slot.' );
nova_wpb_test_assert(
	strpos( $slot_filled, 'Sectie een' ) < strpos( $slot_filled, 'Body een' )
	&& strpos( $slot_filled, 'Body een' ) < strpos( $slot_filled, 'Sectie twee' )
	&& strpos( $slot_filled, 'Sectie twee' ) < strpos( $slot_filled, 'Body twee' ),
	'Section titles and bodies desynced across slots.'
);

nova_wpb_test_assert( is_array( $slot_report['shell'] ) && ! isset( $slot_report['shell']['row']['el_id'] ), 'The overflow shell would duplicate a DOM id.' );
nova_wpb_test_assert( '' === $slot_report['skipped'], 'A healthy document reported a skip reason.' );

echo 'PASS: lossless WPBakery/Salient parsing, field-qualified edits, fail-closed URL/coverage checks, and NOVA-268 slot filling.' . PHP_EOL;
