<?php
/**
 * Standalone unit checks for the WPBakery bridge.
 *
 * Runs without WordPress: the WP surfaces the module touches are stubbed below,
 * so this can be run on any box with plain PHP.
 *
 * Run with: php tests/wpbakery-unit.php
 *
 * For end-to-end verification against a real WPBakery site use
 * tests/wpbakery-regression.php (wp eval-file) instead. That file exercises the
 * same functions through WordPress's own kses/esc implementations; this one
 * stubs them, so sanitisation fidelity is covered there, not here.
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['shortcode_tags'] = array();

// ---------------------------------------------------------------------------
// WordPress stubs
// ---------------------------------------------------------------------------

class WP_Error {
	protected $code;
	protected $message;
	protected $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = null ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
}

/**
 * WP's esc_attr does not double-encode existing entities, which is what keeps
 * attributes such as data-keep="button&amp;opaque" byte-stable on re-serialize.
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8', false );
}

function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}

/**
 * Deliberately coarse: drops disallowed tags while keeping their inner text,
 * which is the behaviour the module depends on. Real kses fidelity is covered
 * by tests/wpbakery-regression.php.
 */
function wp_kses_post( $data ) {
	$data = (string) $data;
	$data = preg_replace( '@<(script|style|iframe|object|embed)\b[^>]*>@i', '', $data );
	$data = preg_replace( '@</(script|style|iframe|object|embed)>@i', '', $data );
	$data = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $data );
	return $data;
}

function wp_kses_bad_protocol( $string, $allowed_protocols ) {
	$string  = (string) $string;
	$scheme  = '';
	$trimmed = ltrim( $string );
	if ( preg_match( '/^([a-z][a-z0-9+.-]*)\s*:/i', $trimmed, $m ) ) {
		$scheme = strtolower( $m[1] );
	}
	if ( '' !== $scheme && ! in_array( $scheme, array_map( 'strtolower', (array) $allowed_protocols ), true ) ) {
		return '';
	}
	return $string;
}

function esc_url_raw( $url, $protocols = null ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return '';
	}
	$allowed = null === $protocols ? array( 'http', 'https', 'mailto', 'tel' ) : $protocols;
	return wp_kses_bad_protocol( $url, $allowed );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	return $single ? '' : array();
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	return true;
}

function get_page_uri( $post ) {
	return '';
}

function apply_filters( $hook, $value ) {
	return $value;
}

function add_shortcode( $tag, $callback ) {
	$GLOBALS['shortcode_tags'][ $tag ] = $callback;
}

// --- WP core shortcode parsing, copied verbatim in behaviour -----------------

function get_shortcode_regex( $tagnames = null ) {
	global $shortcode_tags;

	if ( empty( $tagnames ) ) {
		$tagnames = array_keys( $shortcode_tags );
	}
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

	return '\\['                          // Opening bracket.
		. '(\\[?)'                        // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
		. "($tagregexp)"                  // 2: Shortcode name.
		. '(?![\\w-])'                    // Not followed by word character or hyphen.
		. '('                             // 3: Unlimited attribute characters.
		.     '[^\\]\\/]*'                // Not a closing bracket or forward slash.
		.     '(?:'
		.         '\\/(?!\\])'            // A forward slash not followed by a closing bracket.
		.         '[^\\]\\/]*'            // Not a closing bracket or forward slash.
		.     ')*?'
		. ')'
		. '(?:'
		.     '(\\/)'                     // 4: Self closing tag...
		.     '\\]'                       // ...and closing bracket.
		. '|'
		.     '\\]'                       // Closing bracket.
		.     '(?:'
		.         '('                     // 5: Optionally, anything between the opening and closing shortcode tags.
		.             '[^\\[]*+'           // Not an opening bracket.
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
		.                 '[^\\[]*+'       // Not an opening bracket.
		.             ')*+'
		.         ')'
		.         '\\[\\/\\2\\]'          // Closing shortcode tag.
		.     ')?'
		. ')';
}

function get_shortcode_atts_regex() {
	return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
}

function shortcode_parse_atts( $text ) {
	$atts    = array();
	$pattern = get_shortcode_atts_regex();
	$text    = preg_replace( "/[\x{00a0}\x{200b}]+/u", ' ', $text );
	if ( preg_match_all( $pattern, $text, $match, PREG_SET_ORDER ) ) {
		foreach ( $match as $m ) {
			if ( ! empty( $m[1] ) ) {
				$atts[ strtolower( $m[1] ) ] = stripcslashes( $m[2] );
			} elseif ( ! empty( $m[3] ) ) {
				$atts[ strtolower( $m[3] ) ] = stripcslashes( $m[4] );
			} elseif ( ! empty( $m[5] ) ) {
				$atts[ strtolower( $m[5] ) ] = stripcslashes( $m[6] );
			} elseif ( isset( $m[7] ) && strlen( $m[7] ) ) {
				$atts[] = stripcslashes( $m[7] );
			} elseif ( isset( $m[8] ) && strlen( $m[8] ) ) {
				$atts[] = stripcslashes( $m[8] );
			} elseif ( isset( $m[9] ) ) {
				$atts[] = stripcslashes( $m[9] );
			}
		}

		foreach ( $atts as &$value ) {
			if ( false !== strpos( $value, '<' ) ) {
				if ( 1 !== preg_match( '/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value ) ) {
					$value = '';
				}
			}
		}
		unset( $value );
	} else {
		$atts = ltrim( $text );
	}

	return $atts;
}

// ---------------------------------------------------------------------------
// Module under test
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../modules/wpbakery/includes/layout.php';
require_once __DIR__ . '/../modules/wpbakery/includes/transformations.php';

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

$nova_wpb_checks   = 0;
$nova_wpb_failures = array();

function nova_wpb_check( $condition, $message ) {
	global $nova_wpb_checks, $nova_wpb_failures;
	++$nova_wpb_checks;
	if ( ! $condition ) {
		$nova_wpb_failures[] = $message;
	}
}

function nova_wpb_report() {
	global $nova_wpb_checks, $nova_wpb_failures;
	if ( $nova_wpb_failures ) {
		echo 'FAIL (' . count( $nova_wpb_failures ) . ' of ' . $nova_wpb_checks . ' checks)' . PHP_EOL;
		foreach ( $nova_wpb_failures as $f ) {
			echo '  - ' . $f . PHP_EOL;
		}
		exit( 1 );
	}
	echo 'PASS: ' . $nova_wpb_checks . ' checks.' . PHP_EOL;
	exit( 0 );
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** A generic WPBakery template: hero, two content slots, an image row, a CTA. */
$nova_wpb_generic_template = '[vc_row el_id="hero"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Hero titel" font_container="tag:h1"]'
	. '[vc_column_text]<p>Hero copy</p>[/vc_column_text]'
	. '[vc_btn title="Vraag offerte aan" link="url:%2Fofferte%2F"]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_id="slot-a" el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude kop A" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude tekst A</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]'
	. '[vc_row][vc_column width="1/1"]'
	. '[vc_single_image image="123"]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_id="slot-b" el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude kop B" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude tekst B</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

/** A Salient/Nectar template: the theme ships its own heading and body elements. */
$nova_wpb_salient_template = '[vc_row][vc_column width="1/1"]'
	. '[split_line_heading text_content="Oude Salient kop"]'
	. '[nectar_responsive_text font_size="18"]<p>Oude Salient tekst</p>[/nectar_responsive_text]'
	. '[nectar_btn text="Maak afspraak" url="/contact/"]'
	. '[/vc_column][/vc_row]';

$nova_wpb_sections = array(
	array( 'title' => 'Sectie een', 'body' => '<p>Body een</p>', 'title_tag' => 'h2' ),
	array( 'title' => 'Sectie twee', 'body' => '<p>Body twee</p>', 'title_tag' => 'h2' ),
	array( 'title' => 'Sectie drie', 'body' => '<p>Body drie</p>', 'title_tag' => 'h2' ),
);

// ---------------------------------------------------------------------------
// NOVA-268: generated sections stay in their own template blocks
// ---------------------------------------------------------------------------

$report = null;
list( $filled, $left ) = nova_wpb_replace_template_slots_with_sections(
	$nova_wpb_generic_template,
	$nova_wpb_sections,
	'Pagina titel',
	true,
	$report
);

nova_wpb_check( false !== strpos( $filled, 'text="Hero titel"' ), 'The hero heading was overwritten by a generated section.' );
nova_wpb_check( false !== strpos( $filled, '<p>Hero copy</p>' ), 'The hero copy was overwritten by a generated section.' );
nova_wpb_check( false !== strpos( $filled, 'title="Vraag offerte aan"' ), 'The hero call-to-action button was destroyed.' );
nova_wpb_check( false !== strpos( $filled, 'image="123"' ), 'The image row was altered.' );

nova_wpb_check( false !== strpos( $filled, 'text="Sectie een"' ), 'Section 1 title did not land in the first content slot.' );
nova_wpb_check( false !== strpos( $filled, '<p>Body een</p>' ), 'Section 1 body did not land in the first content slot.' );
nova_wpb_check( false !== strpos( $filled, 'text="Sectie twee"' ), 'Section 2 title did not land in the second content slot.' );
nova_wpb_check( false !== strpos( $filled, '<p>Body twee</p>' ), 'Section 2 body did not land in the second content slot.' );

nova_wpb_check( false === strpos( $filled, 'Oude kop A' ) && false === strpos( $filled, 'Oude tekst A' ), 'Replaced template copy survived in slot A.' );
nova_wpb_check( false === strpos( $filled, 'Oude kop B' ) && false === strpos( $filled, 'Oude tekst B' ), 'Replaced template copy survived in slot B.' );

nova_wpb_check( 1 === count( $left ), 'The third section should overflow: only two content slots exist.' );
nova_wpb_check( isset( $left[0]['title'] ) && 'Sectie drie' === $left[0]['title'], 'The wrong section overflowed.' );

nova_wpb_check( 2 === $report['slots_found'], 'Expected exactly two eligible slots, got ' . $report['slots_found'] . '.' );
nova_wpb_check( 2 === $report['slots_filled'], 'Expected two slots filled, got ' . $report['slots_filled'] . '.' );
nova_wpb_check( 1 === $report['sections_appended'], 'Diagnostics reported the wrong append count.' );
nova_wpb_check( '' === $report['skipped'], 'A healthy document reported a skip reason.' );

// Body/title pairs must not desync: each body sits with its own title.
$pos_t1 = strpos( $filled, 'Sectie een' );
$pos_b1 = strpos( $filled, 'Body een' );
$pos_t2 = strpos( $filled, 'Sectie twee' );
$pos_b2 = strpos( $filled, 'Body twee' );
nova_wpb_check( $pos_t1 < $pos_b1 && $pos_b1 < $pos_t2 && $pos_t2 < $pos_b2, 'Section titles and bodies desynced across slots.' );

// ---------------------------------------------------------------------------
// The real Retoppers case: a theme that ships its own text elements
// ---------------------------------------------------------------------------

$report_s = null;
list( $filled_s, $left_s ) = nova_wpb_replace_template_slots_with_sections(
	$nova_wpb_salient_template,
	array( $nova_wpb_sections[0] ),
	'Pagina titel',
	true,
	$report_s
);

nova_wpb_check( 1 === $report_s['slots_found'], 'A Salient heading/body pair was not recognised as a slot (found ' . $report_s['slots_found'] . ').' );
nova_wpb_check( 1 === $report_s['slots_filled'], 'The Salient slot was not filled.' );
nova_wpb_check( 0 === count( $left_s ), 'The section appended instead of filling the Salient slot — this is the duplicate-content bug.' );
nova_wpb_check( false !== strpos( $filled_s, 'text_content="Sectie een"' ), 'The section title was not written to split_line_heading text_content.' );
nova_wpb_check( false !== strpos( $filled_s, '<p>Body een</p>' ), 'The section body was not written into nectar_responsive_text.' );
nova_wpb_check( false === strpos( $filled_s, 'Oude Salient kop' ), 'The old Salient heading survived alongside the new one.' );
nova_wpb_check( false === strpos( $filled_s, 'Oude Salient tekst' ), 'The old Salient body survived alongside the new one.' );
nova_wpb_check( false !== strpos( $filled_s, 'text="Maak afspraak"' ), 'The Nectar button label was overwritten or cleared.' );
nova_wpb_check( false !== strpos( $filled_s, 'url="/contact/"' ), 'The Nectar button URL was lost.' );

// ---------------------------------------------------------------------------
// Injection: a slot missing one half
// ---------------------------------------------------------------------------

$heading_only = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude kop" font_container="tag:h2"]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $heading_only, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'], 'A lone heading was not offered as a slot.' );
nova_wpb_check( 1 === $r['text_blocks_injected'], 'No text block was injected after a lone heading.' );
nova_wpb_check( false !== strpos( $out, 'text="Sectie een"' ), 'The lone heading did not receive the section title.' );
nova_wpb_check( false !== strpos( $out, '<p>Body een</p>' ), 'The injected text block did not receive the body.' );
nova_wpb_check( strpos( $out, 'Sectie een' ) < strpos( $out, 'Body een' ), 'The injected text block landed before its heading.' );
nova_wpb_check( 0 === count( $rest ), 'A fillable slot still overflowed.' );

$text_only = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_column_text]<p>Oude tekst</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $text_only, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['headings_injected'], 'No heading was injected before a lone text block.' );
nova_wpb_check( strpos( $out, 'Sectie een' ) < strpos( $out, 'Body een' ), 'The injected heading landed after its body.' );

// Two injections in one column, and outer+inner columns at once: the case that
// pins the descending-order requirement for splices.
$two_lone = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="A" font_container="tag:h2"]'
	. '[vc_custom_heading text="B" font_container="tag:h2"]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $two_lone, array( $nova_wpb_sections[0], $nova_wpb_sections[1] ), '', true, $r );
nova_wpb_check( 2 === $r['slots_found'] && 2 === $r['text_blocks_injected'], 'Two lone headings did not each receive a text block.' );
nova_wpb_check( strpos( $out, 'Sectie een' ) < strpos( $out, 'Body een' ), 'First injected body landed out of order.' );
nova_wpb_check( strpos( $out, 'Body een' ) < strpos( $out, 'Sectie twee' ), 'Injections crossed each other.' );
nova_wpb_check( strpos( $out, 'Sectie twee' ) < strpos( $out, 'Body twee' ), 'Second injected body landed out of order.' );

$nested = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Outer" font_container="tag:h2"]'
	. '[vc_row_inner][vc_column_inner width="1/1"]'
	. '[vc_custom_heading text="Inner" font_container="tag:h2"]'
	. '[/vc_column_inner][/vc_row_inner]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $nested, array( $nova_wpb_sections[0], $nova_wpb_sections[1] ), '', true, $r );
nova_wpb_check( 2 === $r['slots_found'], 'Nested inner column was not scanned for slots.' );
nova_wpb_check( 2 === $r['text_blocks_injected'], 'Simultaneous outer and inner injections did not both land.' );
nova_wpb_check( false !== strpos( $out, '<p>Body een</p>' ) && false !== strpos( $out, '<p>Body twee</p>' ), 'An injection was lost when outer and inner columns were spliced in one run.' );
nova_wpb_check( 1 === substr_count( $out, '[vc_row_inner]' ), 'The inner row was duplicated or dropped.' );

// Sibling columns: a heading may never pair with another column's text block.
$siblings = '[vc_row el_class="content"]'
	. '[vc_column width="1/2"][vc_custom_heading text="Links" font_container="tag:h2"][/vc_column]'
	. '[vc_column width="1/2"][vc_column_text]<p>Rechts</p>[/vc_column_text][/vc_column]'
	. '[/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $siblings, array( $nova_wpb_sections[0], $nova_wpb_sections[1] ), '', true, $r );
nova_wpb_check( 2 === $r['slots_found'], 'Sibling columns were merged into one slot.' );
nova_wpb_check( 1 === $r['headings_injected'] && 1 === $r['text_blocks_injected'], 'Each sibling column should have been completed on its own.' );

// ---------------------------------------------------------------------------
// Chrome, overflow styling and idempotence
// ---------------------------------------------------------------------------

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $nova_wpb_generic_template, $nova_wpb_sections, 'Pagina titel', true, $r );
nova_wpb_check( is_array( $r['shell'] ) && isset( $r['shell']['row'] ), 'No row shell was captured for overflow sections.' );
nova_wpb_check( ! isset( $r['shell']['row']['el_id'] ), 'The captured shell kept el_id and would duplicate a DOM id.' );
nova_wpb_check( isset( $r['shell']['row']['el_class'] ) && 'content' === $r['shell']['row']['el_class'], 'The shell did not carry the filled slot row styling.' );

// Re-running the fill over its own output must not inject a second time.
$r2 = null;
list( $out2, $rest2 ) = nova_wpb_replace_template_slots_with_sections( $out, $nova_wpb_sections, 'Pagina titel', true, $r2 );
nova_wpb_check( 0 === $r2['headings_injected'] && 0 === $r2['text_blocks_injected'], 'Re-running the fill injected duplicate nodes.' );

// Page-title suppression applies to slot 0 only, and never to the hero.
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections(
	$nova_wpb_generic_template,
	array( array( 'title' => 'Pagina titel', 'body' => '<p>Body een</p>', 'title_tag' => 'h2' ) ),
	'Pagina titel',
	true,
	$r
);
nova_wpb_check( false !== strpos( $out, 'text="Hero titel"' ), 'Title suppression blanked the hero instead of the first slot.' );
nova_wpb_check( false !== strpos( $out, 'text=""' ), 'A section title repeating the page H1 was not suppressed in slot 0.' );

// An unfilled slot inside an eligible row is cleared, not left as stale copy
// sitting above the appended sections.
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $nova_wpb_generic_template, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( false !== strpos( $out, 'text="Sectie een"' ), 'The single section did not fill the first slot.' );
nova_wpb_check( false === strpos( $out, 'Oude kop B' ), 'An unfilled slot kept its template heading.' );
nova_wpb_check( false === strpos( $out, 'Oude tekst B' ), 'An unfilled slot kept its template body.' );
nova_wpb_check( false !== strpos( $out, 'text="Hero titel"' ), 'Clearing reached the hero row.' );
nova_wpb_check( false !== strpos( $out, 'title="Vraag offerte aan"' ), 'Clearing reached the hero button.' );

// Title suppression must not apply beyond slot 0: a later section legitimately
// repeating the page title keeps its heading.
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections(
	$nova_wpb_generic_template,
	array(
		array( 'title' => 'Andere kop', 'body' => '<p>Body een</p>', 'title_tag' => 'h2' ),
		array( 'title' => 'Pagina titel', 'body' => '<p>Body twee</p>', 'title_tag' => 'h2' ),
	),
	'Pagina titel',
	true,
	$r
);
nova_wpb_check( false !== strpos( $out, 'text="Andere kop"' ), 'Slot 0 title was not written.' );
nova_wpb_check( false !== strpos( $out, 'text="Pagina titel"' ), 'A later section repeating the page title was wrongly suppressed.' );

// clear_remaining = false leaves untouched template copy alone.
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $nova_wpb_generic_template, array( $nova_wpb_sections[0] ), '', false, $r );
nova_wpb_check( false !== strpos( $out, 'Oude kop B' ), 'clear_remaining=false still blanked an unfilled slot.' );

// A template with no eligible row appends everything and says so.
$chrome_only = '[vc_row][vc_column width="1/1"][vc_custom_heading text="Titel" font_container="tag:h1"][/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $chrome_only, $nova_wpb_sections, '', true, $r );
nova_wpb_check( 0 === $r['slots_found'] && 3 === $r['sections_appended'], 'A chrome-only template should append every section.' );
nova_wpb_check( 0 === $r['rows_eligible'] && 1 === $r['rows_ineligible'], 'Row eligibility counters were wrong for a chrome-only template.' );
nova_wpb_check( false !== strpos( $out, 'text="Titel"' ), 'A chrome-only template was modified.' );

// Buttons and images inside an eligible row keep their copy.
$row_with_button = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Kop" font_container="tag:h2"]'
	. '[vc_column_text]<p>Tekst</p>[/vc_column_text]'
	. '[nectar_btn text="Lees meer" url="/meer/"]'
	. '[image_with_animation image_url="77"]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $row_with_button, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'], 'A content row containing a button was not fillable (this is what caused duplicate content).' );
nova_wpb_check( false !== strpos( $out, 'text="Lees meer"' ), 'A button label inside a filled row was overwritten or cleared.' );
nova_wpb_check( false !== strpos( $out, 'url="/meer/"' ), 'A button URL inside a filled row was lost.' );
nova_wpb_check( false !== strpos( $out, 'image_url="77"' ), 'An image inside a filled row was cleared.' );

// FAQ/accordion carriers are never treated as heading slots.
$with_toggle = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[toggles][toggle title="Vraag?"]Antwoord[/toggle][/toggles]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $with_toggle, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 0 === $r['slots_found'], 'An accordion title was offered as a heading slot.' );
nova_wpb_check( false !== strpos( $out, 'title="Vraag?"' ), 'An accordion question was overwritten.' );

// Degenerate inputs.
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( '', $nova_wpb_sections, '', true, $r );
nova_wpb_check( '' === $out && 3 === count( $rest ), 'An empty template did not pass every section through.' );
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $nova_wpb_generic_template, array(), '', true, $r );
nova_wpb_check( $out === $nova_wpb_generic_template && 0 === count( $rest ), 'An empty section list modified the template.' );

// ---------------------------------------------------------------------------
// Path helpers
// ---------------------------------------------------------------------------

nova_wpb_check( '1.2' === nova_wpb_path_parent( '1.2.3' ), 'path_parent failed on a nested path.' );
nova_wpb_check( '' === nova_wpb_path_parent( '4' ), 'path_parent should be empty at top level.' );
nova_wpb_check( 3 === nova_wpb_path_index( '1.2.3' ), 'path_index failed on a nested path.' );
nova_wpb_check( 4 === nova_wpb_path_index( '4' ), 'path_index failed at top level.' );
nova_wpb_check( -1 === nova_wpb_compare_paths( '1.2', '1.10' ), 'compare_paths ordered numerically-larger segments lexically.' );
nova_wpb_check( 1 === nova_wpb_compare_paths( '2', '1.9' ), 'compare_paths mis-ordered across depths.' );
nova_wpb_check( 0 === nova_wpb_compare_paths( '1.2', '1.2' ), 'compare_paths did not report equality.' );
nova_wpb_check( -1 === nova_wpb_compare_paths( '1.2', '1.2.0' ), 'A parent must sort before its own child.' );

// ---------------------------------------------------------------------------
// 2.7.9 behaviour must survive this change
// ---------------------------------------------------------------------------

$salient_doc = '[vc_row][vc_column width="1/1"][split_line_heading text_content="Kop"][nectar_btn  text = \'Maak afspraak\'   url=/contact/ data-keep="a&amp;b"][/vc_column][/vc_row]';
$compact_doc = nova_wpb_parse_shortcodes_to_compact( $salient_doc );
nova_wpb_check( true === nova_wpb_validate_roundtrip_coverage( $salient_doc, $compact_doc ), 'A Salient document no longer round-trips.' );
nova_wpb_check( $salient_doc === nova_wpb_compact_to_shortcodes( $compact_doc ), 'Re-serialization is no longer byte-exact.' );

$outline_doc = nova_wpb_build_outline_from_compact( $compact_doc, false );
$btn_path    = '';
foreach ( $outline_doc as $item ) {
	if ( 'nectar_btn' === $item['tag'] ) {
		$btn_path = $item['path'];
	}
}
nova_wpb_check( '' !== $btn_path, 'The outline no longer exposes the Nectar button.' );
$field_edit = nova_wpb_apply_transformations( $salient_doc, array(), array( array( 'path' => $btn_path, 'field' => 'text', 'text' => 'Boek nu' ) ), '', array() );
nova_wpb_check( ! is_wp_error( $field_edit ), 'Field-qualified text_updates broke.' );
nova_wpb_check( false !== strpos( $field_edit, 'text = \'Boek nu\'' ), 'A field-qualified edit did not land.' );
$bad_field = nova_wpb_apply_text_updates_to_compact( $compact_doc, array( array( 'path' => $btn_path, 'field' => 'data-keep', 'text' => 'nope' ) ) );
nova_wpb_check( is_wp_error( $bad_field ) && 'nova_wpb_unsupported_field' === $bad_field->get_error_code(), 'Structural attributes became writable.' );

// ---------------------------------------------------------------------------
// Which nodes may host content: nova_wpb_slot_carrier_for_node()
// ---------------------------------------------------------------------------

/** Build a compact node the way the parser would, for direct carrier checks. */
function nova_wpb_test_node( $tag, $attributes = array(), $text = '', $children = array() ) {
	return array(
		'tag'          => $tag,
		'attributes'   => $attributes,
		'text'         => $text,
		'self_closing' => ( '' === $text && empty( $children ) ),
		'children'     => $children,
	);
}

function nova_wpb_test_carrier( $tag, $attributes = array(), $text = '' ) {
	return nova_wpb_slot_carrier_for_node( nova_wpb_test_node( $tag, $attributes, $text ) );
}

$c = nova_wpb_test_carrier( 'vc_column_text', array(), '<p>x</p>' );
nova_wpb_check( is_array( $c ) && 'text' === $c['kind'] && 'body' === $c['field'], 'vc_column_text is no longer a body slot.' );

$c = nova_wpb_test_carrier( 'vc_custom_heading', array( 'text' => 'Kop', 'font_container' => 'tag:h2' ) );
nova_wpb_check( is_array( $c ) && 'heading' === $c['kind'] && 'text' === $c['field'], 'vc_custom_heading is no longer a heading slot.' );

$c = nova_wpb_test_carrier( 'split_line_heading', array( 'text_content' => 'Kop' ) );
nova_wpb_check( is_array( $c ) && 'heading' === $c['kind'] && 'text_content' === $c['field'], 'Salient split_line_heading lost its heading carrier.' );

$c = nova_wpb_test_carrier( 'nectar_responsive_text', array(), '<p>x</p>' );
nova_wpb_check( is_array( $c ) && 'text' === $c['kind'] && 'body' === $c['field'], 'Salient nectar_responsive_text lost its body carrier.' );

nova_wpb_check( null === nova_wpb_test_carrier( 'vc_btn', array( 'title' => 'Klik', 'link' => 'url:%2Fx%2F' ) ), 'A vc_btn was offered as a heading slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_btn', array( 'title' => 'Klik' ) ), 'A vc_btn without a link attribute was offered as a heading slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'nectar_btn', array( 'text' => 'Klik', 'url' => '/x/' ) ), 'A nectar_btn was offered as a slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'nectar_cta', array( 'link_text' => 'Klik', 'url' => '/x/' ) ), 'A nectar_cta was offered as a slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_single_image', array( 'image' => '12' ) ), 'An image was offered as a slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_row' ), 'A layout row was offered as a slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'toggle', array( 'title' => 'Vraag?' ), 'Antwoord' ), 'An accordion item was offered as a slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_empty_space', array( 'height' => '20px' ) ), 'A spacer was offered as a slot.' );
nova_wpb_check( null === nova_wpb_slot_carrier_for_node( array() ), 'An empty node was offered as a slot.' );
nova_wpb_check( null === nova_wpb_slot_carrier_for_node( 'not-a-node' ), 'A non-array node was offered as a slot.' );

// Embeds: default_text_field_for_tag falls back to 'body' for any unmapped leaf with
// inner text, which made raw/media shortcodes look like prose containers.
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_raw_html', array(), 'PGRpdj4=' ), 'vc_raw_html was offered as a body slot; its payload would be overwritten.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_gmaps', array(), '<iframe></iframe>' ), 'An embedded map was offered as a body slot.' );
nova_wpb_check( null === nova_wpb_test_carrier( 'vc_raw_js', array(), 'var a=1;' ), 'A raw JS block was offered as a body slot.' );

// A heading carrying WPBakery's packed `link` attribute is still a heading.
$c = nova_wpb_test_carrier( 'vc_custom_heading', array( 'text' => 'Kop', 'link' => 'url:%23|||', 'font_container' => 'tag:h2' ) );
nova_wpb_check( is_array( $c ) && 'heading' === $c['kind'], 'A linked heading was refused as a slot; the template copy would survive next to an injected duplicate.' );

// An explicit url attribute still disqualifies: that is a link element, not a heading.
nova_wpb_check( null === nova_wpb_test_carrier( 'some_theme_link', array( 'text' => 'Klik', 'url' => '/x/' ) ), 'An unknown element with its own url attribute was offered as a slot.' );

// ---------------------------------------------------------------------------
// A linked heading fills in place instead of gaining a duplicate
// ---------------------------------------------------------------------------

$linked_heading = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude kop" link="url:%23|||" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude tekst</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $linked_heading, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'], 'A linked heading + text pair was not recognised as one slot.' );
nova_wpb_check( 0 === $r['headings_injected'], 'A second heading was injected next to a linked heading — this is the duplicate heading on the page.' );
nova_wpb_check( false === strpos( $out, 'Oude kop' ), 'The linked heading kept its template copy.' );
nova_wpb_check( 1 === substr_count( $out, 'vc_custom_heading' ), 'The document ended up with two headings where the template had one.' );
nova_wpb_check( false !== strpos( $out, 'link="url:%23|||"' ), 'The heading link was dropped while writing the section title.' );
nova_wpb_check( false !== strpos( $out, 'text="Sectie een"' ), 'The section title did not land in the linked heading.' );

// ---------------------------------------------------------------------------
// Embeds inside a fillable row are left alone
// ---------------------------------------------------------------------------

$row_with_raw = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_raw_html]PGRpdj5tYXA8L2Rpdj4=[/vc_raw_html]'
	. '[vc_column_text]<p>Oude tekst</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $row_with_raw, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'], 'A raw HTML block was counted as a content slot.' );
nova_wpb_check( false !== strpos( $out, 'PGRpdj5tYXA8L2Rpdj4=' ), 'A raw HTML payload was overwritten by a generated section.' );
nova_wpb_check( false !== strpos( $out, '<p>Body een</p>' ), 'The section body did not reach the real text block.' );
nova_wpb_check( strpos( $out, 'PGRpdj5tYXA8L2Rpdj4=' ) < strpos( $out, 'Sectie een' ), 'The injected heading landed above the raw HTML block instead of beside its own body.' );
nova_wpb_check( strpos( $out, 'Sectie een' ) < strpos( $out, 'Body een' ), 'The injected heading landed after its body.' );

// ---------------------------------------------------------------------------
// Blank rows are pruned on any theme, not only on vc_* elements
// ---------------------------------------------------------------------------

$salient_two_rows = '[vc_row][vc_column width="1/1"]'
	. '[split_line_heading text_content="Kop A"]'
	. '[nectar_responsive_text]<p>Tekst A</p>[/nectar_responsive_text]'
	. '[/vc_column][/vc_row]'
	. '[vc_row][vc_column width="1/1"]'
	. '[split_line_heading text_content="Kop B"]'
	. '[nectar_responsive_text]<p>Tekst B</p>[/nectar_responsive_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $salient_two_rows, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 2 === $r['slots_found'] && 1 === $r['slots_filled'], 'Salient slot detection changed.' );
nova_wpb_check( false === strpos( $out, 'Kop B' ) && false === strpos( $out, 'Tekst B' ), 'The unfilled Salient row kept its template copy.' );
nova_wpb_check( 1 === substr_count( $out, '[vc_row]' ), 'The emptied Salient row was left on the page as a blank block.' );
nova_wpb_check( false === strpos( $out, 'split_line_heading text_content=""' ), 'An emptied Salient heading survived the prune.' );

// Same shape on vc_* elements: parity between themes.
$generic_two_rows = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Kop A" font_container="tag:h2"]'
	. '[vc_column_text]<p>Tekst A</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Kop B" font_container="tag:h2"]'
	. '[vc_column_text]<p>Tekst B</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out_g, $rest ) = nova_wpb_replace_template_slots_with_sections( $generic_two_rows, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === substr_count( $out_g, '[vc_row' ), 'The emptied vc_* row was left on the page.' );

// A row whose carriers are empty but which still holds a button is real content.
$blank_with_button = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[split_line_heading text_content=""]'
	. '[nectar_btn text="Neem contact op" url="/contact/"]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $blank_with_button, array(), '', true, $r );
nova_wpb_check( $out === $blank_with_button, 'A row holding a button was pruned as if it were blank.' );

// Direct predicate checks.
$blank_row = nova_wpb_parse_shortcodes_to_compact( '[vc_row][vc_column width="1/1"][split_line_heading text_content=""][nectar_responsive_text][/nectar_responsive_text][/vc_column][/vc_row]' );
nova_wpb_check( true === nova_wpb_row_is_blank_after_clearing( $blank_row[0] ), 'A row of empty theme carriers was not recognised as blank.' );

$button_row = nova_wpb_parse_shortcodes_to_compact( '[vc_row][vc_column width="1/1"][split_line_heading text_content=""][nectar_btn text="Klik" url="/x/"][/vc_column][/vc_row]' );
nova_wpb_check( false === nova_wpb_row_is_blank_after_clearing( $button_row[0] ), 'A row holding a button was reported blank.' );

$image_row = nova_wpb_parse_shortcodes_to_compact( '[vc_row][vc_column width="1/1"][vc_single_image image="9"][/vc_column][/vc_row]' );
nova_wpb_check( false === nova_wpb_row_is_blank_after_clearing( $image_row[0] ), 'An image-only row was reported blank; it has no carriers at all.' );

// A row with no content carriers at all is not "blank", it is chrome: pruning a
// spacer/divider row would delete template layout the sections never asked for.
$spacer_row = nova_wpb_parse_shortcodes_to_compact( '[vc_row][vc_column width="1/1"][vc_empty_space height="60px"][vc_separator][/vc_column][/vc_row]' );
nova_wpb_check( false === nova_wpb_row_is_blank_after_clearing( $spacer_row[0] ), 'A spacer row with no carriers was reported blank and would be pruned.' );

$spacer_doc = '[vc_row el_class="content"][vc_column width="1/1"][vc_empty_space height="60px"][/vc_column][/vc_row]'
	. '[vc_row el_class="content"][vc_column width="1/1"][vc_custom_heading text="Kop" font_container="tag:h2"][vc_column_text]<p>Tekst</p>[/vc_column_text][/vc_column][/vc_row]';
$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $spacer_doc, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( false !== strpos( $out, 'vc_empty_space' ), 'A spacer row was pruned out of the template.' );

$full_row = nova_wpb_parse_shortcodes_to_compact( '[vc_row][vc_column width="1/1"][split_line_heading text_content="Kop"][/vc_column][/vc_row]' );
nova_wpb_check( false === nova_wpb_row_is_blank_after_clearing( $full_row[0] ), 'A row with copy in it was reported blank.' );

nova_wpb_check( false === nova_wpb_row_is_blank_after_clearing( array( 'tag' => 'vc_column' ) ), 'A non-row node was accepted by the blank-row predicate.' );

// ---------------------------------------------------------------------------
// Mixed themes in one document, and idempotence on theme elements
// ---------------------------------------------------------------------------

$mixed = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[split_line_heading text_content="Kop A"]'
	. '[nectar_responsive_text]<p>Tekst A</p>[/nectar_responsive_text]'
	. '[/vc_column][/vc_row]'
	. '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[vc_custom_heading text="Kop B" font_container="tag:h2"]'
	. '[vc_column_text]<p>Tekst B</p>[/vc_column_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $mixed, array( $nova_wpb_sections[0], $nova_wpb_sections[1] ), '', true, $r );
nova_wpb_check( 2 === $r['slots_found'] && 2 === $r['slots_filled'] && 0 === count( $rest ), 'A mixed-theme template did not fill both slots.' );
nova_wpb_check( false !== strpos( $out, 'text_content="Sectie een"' ), 'Section 1 did not land in the theme heading.' );
nova_wpb_check( false !== strpos( $out, 'text="Sectie twee"' ), 'Section 2 did not land in the vc_* heading.' );
nova_wpb_check( strpos( $out, 'Sectie een' ) < strpos( $out, 'Sectie twee' ), 'Sections landed out of document order across themes.' );

$r2 = null;
list( $out2, $rest2 ) = nova_wpb_replace_template_slots_with_sections( $out, array( $nova_wpb_sections[0], $nova_wpb_sections[1] ), '', true, $r2 );
nova_wpb_check( 0 === $r2['headings_injected'] && 0 === $r2['text_blocks_injected'], 'Re-running the fill over theme elements injected duplicates.' );
nova_wpb_check( 2 === $r2['slots_found'], 'Filled theme slots stopped being recognised on a second pass.' );

// Serialization stays byte-exact for the documents these paths touch.
$linked_doc = '[vc_row el_class="content"][vc_column width="1/1"][vc_custom_heading text="Kop" link="url:%23|||" font_container="tag:h2"][vc_raw_html]PGRpdj4=[/vc_raw_html][/vc_column][/vc_row]';
nova_wpb_check( $linked_doc === nova_wpb_compact_to_shortcodes( nova_wpb_parse_shortcodes_to_compact( $linked_doc ) ), 'A linked-heading + raw-HTML document no longer round-trips byte-exactly.' );

nova_wpb_report();
