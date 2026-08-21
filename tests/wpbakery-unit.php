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

	public function add_data( $data, $code = '' ) {
		$this->data = $data;
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

// Unsafe round-trip errors retain their coverage data and expose slot diagnostics.
$coverage_data = array(
	'status'     => 422,
	'byte_exact' => false,
	'source'     => array( 'shortcodes' => 2 ),
	'serialized' => array( 'shortcodes' => 1 ),
);
$coverage_error = new WP_Error( 'nova_wpb_unsafe_roundtrip', 'Unsafe WPBakery document.', $coverage_data );
$slot_report = nova_wpb_new_slot_report( array( array( 'title' => 'Section' ) ) );
$slot_report['skipped'] = 'unsafe_roundtrip';
$slot_report['shell'] = array( 'row' => array( 'el_class' => 'internal' ) );
$reported_error = nova_wpb_attach_slot_report_to_error( $coverage_error, $slot_report );
$reported_data  = $reported_error->get_error_data();
nova_wpb_check( $reported_error === $coverage_error, 'Attaching slot diagnostics replaced the original WP_Error.' );
nova_wpb_check( 'nova_wpb_unsafe_roundtrip' === $reported_error->get_error_code(), 'Attaching slot diagnostics changed the error code.' );
nova_wpb_check( 'Unsafe WPBakery document.' === $reported_error->get_error_message(), 'Attaching slot diagnostics changed the error message.' );
nova_wpb_check( $coverage_data === array_intersect_key( $reported_data, $coverage_data ), 'Attaching slot diagnostics changed round-trip coverage data.' );
nova_wpb_check( isset( $reported_data['nova'] ) && 'unsafe_roundtrip' === $reported_data['nova']['skipped'] && 1 === $reported_data['nova']['sections_total'], 'Unsafe round-trip diagnostics were not attached to the error.' );
nova_wpb_check( ! isset( $reported_data['nova']['shell'] ), 'Internal slot shell data leaked into the error response.' );
nova_wpb_check( 'not-an-error' === nova_wpb_attach_slot_report_to_error( 'not-an-error', $slot_report ), 'A non-error value was changed while attaching diagnostics.' );
nova_wpb_check( $coverage_error === nova_wpb_attach_slot_report_to_error( $coverage_error, null ), 'A non-array report changed the error.' );

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

// ---------------------------------------------------------------------------
// type:"faq" sections never fill a slot as plain text (Retoppers live-test finding)
// ---------------------------------------------------------------------------

$faq_section = array(
	'title'     => 'Veelgestelde vragen',
	'body'      => '<h3>Vraag een</h3><p>Antwoord een</p><h3>Vraag twee</h3><p>Antwoord twee</p>',
	'title_tag' => 'h2',
	'type'      => 'faq',
);

$one_slot_doc = '[vc_row el_class="content"][vc_column width="1/1"]'
	. '[split_line_heading text_content="Kop"]'
	. '[nectar_responsive_text]<p>Tekst</p>[/nectar_responsive_text]'
	. '[/vc_column][/vc_row]';

$r = null;
list( $out, $rest ) = nova_wpb_replace_template_slots_with_sections( $one_slot_doc, array( $faq_section, $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_filled'], 'A single available slot did not get filled when a FAQ section led the list.' );
nova_wpb_check( false !== strpos( $out, 'text_content="Sectie een"' ), 'The ordinary section behind a FAQ section did not take the free slot.' );
nova_wpb_check( false === strpos( $out, 'Veelgestelde vragen' ) && false === strpos( $out, 'Vraag een' ), 'A FAQ section was written into a slot as plain text.' );
nova_wpb_check( 1 === count( $rest ) && 'faq' === strtolower( (string) $rest[0]['type'] ), 'The FAQ section was consumed instead of passed through to $remaining.' );

// FAQ-only run: nothing to fill, the section must come back whole, not dropped.
$r = null;
list( $out_faq_only, $rest_faq_only ) = nova_wpb_replace_template_slots_with_sections( $one_slot_doc, array( $faq_section ), '', true, $r );
nova_wpb_check( 0 === $r['slots_filled'], 'A FAQ-only section list still reported a filled slot.' );
nova_wpb_check( 1 === count( $rest_faq_only ) && 'Veelgestelde vragen' === $rest_faq_only[0]['title'], 'A lone FAQ section was lost instead of passed through.' );

// End-to-end: slot-fill then apply_transformations, the order pages.php actually runs in.
$final = nova_wpb_apply_transformations( $out, array(), array(), '', $rest );
nova_wpb_check( false !== strpos( $final, '[vc_tta_accordion' ), 'No native vc_tta_accordion was produced end-to-end.' );
nova_wpb_check( 1 === substr_count( $final, '[vc_tta_accordion' ), 'End-to-end produced more than one accordion for one FAQ section.' );
nova_wpb_check( 1 === substr_count( $final, '[vc_custom_heading text="Veelgestelde vragen"' ), 'The FAQ section title was not rendered as an overall heading end-to-end.' );
nova_wpb_check( strpos( $final, '[vc_custom_heading text="Veelgestelde vragen"' ) < strpos( $final, '[vc_tta_accordion' ), 'The FAQ section title was not rendered before its accordion.' );
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Vraag een" tab_id="[a-f0-9]{8}"\]\[vc_column_text\]Antwoord een\[\/vc_column_text\]\[\/vc_tta_section\]/', $final ),
	'Question one did not become its own vc_tta_section end-to-end.'
);
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Vraag twee" tab_id="[a-f0-9]{8}"\]\[vc_column_text\]Antwoord twee\[\/vc_column_text\]\[\/vc_tta_section\]/', $final ),
	'Question two did not become its own vc_tta_section end-to-end.'
);
nova_wpb_check( false === strpos( $final, '<h3>Vraag een</h3>' ), 'The FAQ body still contained a raw h3 instead of being exploded into vc_tta_section items.' );
nova_wpb_check( false === strpos( $final, 'ot_faqs' ), 'A theme shortcode (ot_faqs) was emitted instead of the native WPBakery accordion.' );

// ---------------------------------------------------------------------------
// apply_transformations(): a multi-question FAQ body explodes into one
// vc_tta_section per question inside a single vc_tta_accordion, using
// WPBakery's own accordion element rather than a theme-dependent shortcode.
// ---------------------------------------------------------------------------

$faq_only_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_section ) );
nova_wpb_check( 1 === substr_count( $faq_only_out, '[vc_tta_accordion' ), 'A two-question FAQ section did not produce exactly one accordion.' );
nova_wpb_check( 2 === substr_count( $faq_only_out, '[vc_tta_section title=' ), 'A two-question FAQ section did not produce two vc_tta_section items.' );
nova_wpb_check( 1 === substr_count( $faq_only_out, '[vc_custom_heading text="Veelgestelde vragen"' ), 'A FAQ append did not render its supplied section title exactly once.' );
nova_wpb_check( false !== strpos( $faq_only_out, 'font_container="tag:h2"' ), 'A FAQ append did not honor its supplied H2 title tag.' );
nova_wpb_check( strpos( $faq_only_out, '[vc_custom_heading text="Veelgestelde vragen"' ) < strpos( $faq_only_out, '[vc_tta_accordion' ), 'A FAQ append rendered its title after the accordion.' );
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Vraag een" tab_id="([a-f0-9]{8})"\]\[vc_column_text\]Antwoord een\[\/vc_column_text\]\[\/vc_tta_section\]/', $faq_only_out, $m1 ),
	'Question one was not rendered as its own vc_tta_section.'
);
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Vraag twee" tab_id="([a-f0-9]{8})"\]\[vc_column_text\]Antwoord twee\[\/vc_column_text\]\[\/vc_tta_section\]/', $faq_only_out, $m2 ),
	'Question two was not rendered as its own vc_tta_section.'
);
nova_wpb_check( empty( $m1 ) || empty( $m2 ) || $m1[1] !== $m2[1], 'Two different questions were assigned the same tab_id.' );
nova_wpb_check( false === strpos( $faq_only_out, 'title="Veelgestelde vragen"' ), 'The section title leaked into a vc_tta_section instead of being discarded once questions were found.' );
nova_wpb_check( false === strpos( $faq_only_out, 'ot_faqs' ), 'A theme shortcode (ot_faqs) was emitted instead of the native WPBakery accordion.' );

// Re-running over its own output must not mint new tab_ids (idempotence).
$faq_only_out_2 = nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_section ) );
nova_wpb_check( $faq_only_out === $faq_only_out_2, 'Re-running the same FAQ section produced a different accordion (non-deterministic tab_id).' );

// Reuse the cleared Retoppers FAQ slot instead of appending below template chrome.
$retoppers_doc = '[vc_row el_class="faq-slot"][vc_column]'
	. '[vc_column_text el_class="faq-title"]</p><h2>Veelgestelde vragen</h2><p>[/vc_column_text]'
	. '[vc_tta_accordion style="modern" active_section="2"]'
	. '[vc_tta_section title="Oud een"][vc_column_text]Oud antwoord een[/vc_column_text][/vc_tta_section]'
	. '[vc_tta_section title="Oud twee"][vc_column_text]Oud antwoord twee[/vc_column_text][/vc_tta_section]'
	. '[vc_tta_section title="Oud drie"][vc_column_text]Oud antwoord drie[/vc_column_text][/vc_tta_section]'
	. '[vc_tta_section title="Oud vier"][vc_column_text]Oud antwoord vier[/vc_column_text][/vc_tta_section]'
	. '[/vc_tta_accordion][/vc_column][/vc_row]'
	. '[vc_row][vc_column][vso_auteur_block name="Yarno"][/vc_column][/vc_row]'
	. '[vc_row][vc_column][vso_laatste_blog_items amount="3"][/vc_column][/vc_row]';
$retoppers_remove = array(
	'0.0.0',
	'0.0.1.0', '0.0.1.0.0',
	'0.0.1.1', '0.0.1.1.0',
	'0.0.1.2', '0.0.1.2.0',
	'0.0.1.3', '0.0.1.3.0',
);
$faq_section_three         = $faq_section;
$faq_section_three['body'] = $faq_section['body'] . '<h3>Vraag drie</h3><p>Antwoord drie</p>';
$faq_section_three['title'] = 'Nieuwe veelgestelde vragen';
$faq_section_three['title_tag'] = 'h3';

// PATCH-shaped one pass and CREATE-shaped remove-then-append must be identical.
$retoppers_out = nova_wpb_apply_transformations( $retoppers_doc, $retoppers_remove, array(), '', array( $faq_section_three ) );
$retoppers_empty = nova_wpb_apply_transformations( $retoppers_doc, $retoppers_remove, array(), '', array() );
$retoppers_two_pass = nova_wpb_apply_transformations( $retoppers_empty, array(), array(), '', array( $faq_section_three ) );
nova_wpb_check( $retoppers_out === $retoppers_two_pass, 'One-pass and two-pass FAQ replacement produced different documents.' );
nova_wpb_check( 1 === substr_count( $retoppers_out, '[vc_tta_accordion' ), 'An empty template FAQ slot produced a second accordion.' );
nova_wpb_check( 3 === substr_count( $retoppers_out, '[vc_tta_section title=' ), 'The reused FAQ slot did not receive every generated question.' );
nova_wpb_check( 3 === substr_count( $retoppers_out, '[vc_row' ), 'Reusing the FAQ slot changed the top-level row count.' );
nova_wpb_check( false !== strpos( $retoppers_out, '[vc_tta_accordion style="modern" active_section="2"]' ), 'Reusing the FAQ slot discarded its template attributes.' );
$retoppers_heading_pos = strpos( $retoppers_out, '[vc_custom_heading text="Nieuwe veelgestelde vragen"' );
$retoppers_faq_pos    = strpos( $retoppers_out, '[vc_tta_accordion' );
$retoppers_author_pos = strpos( $retoppers_out, 'vso_auteur_block' );
$retoppers_latest_pos = strpos( $retoppers_out, 'vso_laatste_blog_items' );
nova_wpb_check( false !== $retoppers_heading_pos && false !== $retoppers_faq_pos && false !== $retoppers_author_pos && false !== $retoppers_latest_pos, 'The transformed document lost a required FAQ heading or template marker.' );
nova_wpb_check( 1 === substr_count( $retoppers_out, '[vc_custom_heading text="Nieuwe veelgestelde vragen"' ), 'A removed template FAQ heading was not replaced exactly once.' );
nova_wpb_check( false !== strpos( $retoppers_out, 'font_container="tag:h3"' ), 'The reused FAQ slot did not honor its supplied H3 title tag.' );
nova_wpb_check( $retoppers_heading_pos < $retoppers_faq_pos, 'The replacement FAQ heading was not inserted before the reused accordion.' );
nova_wpb_check( $retoppers_faq_pos < $retoppers_author_pos, 'The FAQ moved below the template author block.' );
nova_wpb_check( $retoppers_faq_pos < $retoppers_latest_pos, 'The FAQ moved below the related-post block.' );
nova_wpb_check( $retoppers_author_pos < $retoppers_latest_pos, 'Reusing the FAQ slot changed the template chrome order.' );
nova_wpb_check( false === strpos( $retoppers_out, 'Oud een' ) && false === strpos( $retoppers_out, 'Oud antwoord vier' ), 'Old FAQ content survived the replacement.' );
nova_wpb_check( $retoppers_doc === nova_wpb_compact_to_shortcodes( nova_wpb_parse_shortcodes_to_compact( $retoppers_doc ) ), 'The empty FAQ template fixture does not round-trip byte-exactly.' );

// A surviving live-style HTML heading is updated in place, not duplicated.
$retoppers_keep_heading_remove = array_slice( $retoppers_remove, 1 );
$retoppers_keep_heading_out = nova_wpb_apply_transformations( $retoppers_doc, $retoppers_keep_heading_remove, array(), '', array( $faq_section_three ) );
nova_wpb_check( 0 === substr_count( $retoppers_keep_heading_out, '[vc_custom_heading' ), 'A surviving FAQ heading caused a second custom heading to be injected.' );
nova_wpb_check( false !== strpos( $retoppers_keep_heading_out, '[vc_column_text el_class="faq-title"]</p><h3>Nieuwe veelgestelde vragen</h3><p>[/vc_column_text]' ), 'The surviving HTML FAQ heading was not updated in place with its wrapper preserved.' );
nova_wpb_check( 1 === substr_count( $retoppers_keep_heading_out, 'Nieuwe veelgestelde vragen' ), 'The surviving HTML FAQ heading was duplicated.' );

// A surviving vc_custom_heading is also updated in place with unrelated attributes intact.
$custom_heading_doc = '[vc_row][vc_column]'
	. '[vc_custom_heading text="Oude FAQ titel" use_theme_fonts="no" font_container="tag:h2" el_class="keep-heading"]'
	. '[vc_tta_accordion style="modern"][/vc_tta_accordion]'
	. '[/vc_column][/vc_row]';
$custom_heading_out = nova_wpb_apply_transformations( $custom_heading_doc, array(), array(), '', array( $faq_section_three ) );
nova_wpb_check( 1 === substr_count( $custom_heading_out, '[vc_custom_heading' ), 'Updating a structured FAQ heading created a duplicate heading.' );
nova_wpb_check( false !== strpos( $custom_heading_out, 'text="Nieuwe veelgestelde vragen"' ), 'The structured FAQ heading text was not updated.' );
nova_wpb_check( false !== strpos( $custom_heading_out, 'font_container="tag:h3"' ), 'The structured FAQ heading tag was not updated.' );
nova_wpb_check( false !== strpos( $custom_heading_out, 'use_theme_fonts="no"' ) && false !== strpos( $custom_heading_out, 'el_class="keep-heading"' ), 'Updating the structured FAQ heading discarded unrelated attributes.' );

// Never rewrite a raw/structured block merely because its payload contains one heading.
$raw_heading_doc = '[vc_row][vc_column]'
	. '[vc_raw_html]<h2>Do not touch</h2>[/vc_raw_html]'
	. '[vc_tta_accordion style="modern"][/vc_tta_accordion]'
	. '[/vc_column][/vc_row]';
$raw_heading_out = nova_wpb_apply_transformations( $raw_heading_doc, array(), array(), '', array( $faq_section_three ) );
nova_wpb_check( false !== strpos( $raw_heading_out, '[vc_raw_html]<h2>Do not touch</h2>[/vc_raw_html]' ), 'FAQ heading reuse overwrote a never-slot raw HTML block.' );
nova_wpb_check( 1 === substr_count( $raw_heading_out, '[vc_custom_heading text="Nieuwe veelgestelde vragen"' ), 'A safe FAQ heading was not inserted after refusing to rewrite raw HTML.' );
nova_wpb_check( strpos( $raw_heading_out, '[vc_raw_html]' ) < strpos( $raw_heading_out, '[vc_custom_heading' ) && strpos( $raw_heading_out, '[vc_custom_heading' ) < strpos( $raw_heading_out, '[vc_tta_accordion' ), 'The safe FAQ heading was not inserted directly before the accordion.' );

// Ambiguous or occupied accordions are not overwritten; current append fallback remains.
$ambiguous_doc = '[vc_row][vc_column][vc_tta_accordion][/vc_tta_accordion][/vc_column][/vc_row]'
	. '[vc_row][vc_column][vc_tta_accordion][/vc_tta_accordion][/vc_column][/vc_row]';
$ambiguous_out = nova_wpb_apply_transformations( $ambiguous_doc, array(), array(), '', array( $faq_section ) );
nova_wpb_check( 3 === substr_count( $ambiguous_out, '[vc_tta_accordion' ), 'Multiple empty FAQ slots did not use the append fallback.' );
nova_wpb_check( 1 === substr_count( $ambiguous_out, '[vc_custom_heading text="Veelgestelde vragen"' ), 'The ambiguous-slot fallback did not render one overall FAQ heading.' );

$occupied_doc = '[vc_row][vc_column][vc_tta_accordion][vc_tta_section title="Bestaand"][vc_column_text]Bestaand antwoord[/vc_column_text][/vc_tta_section][/vc_tta_accordion][/vc_column][/vc_row]';
$occupied_out = nova_wpb_apply_transformations( $occupied_doc, array(), array(), '', array( $faq_section ) );
nova_wpb_check( 2 === substr_count( $occupied_out, '[vc_tta_accordion' ), 'A populated accordion was overwritten instead of using the append fallback.' );
nova_wpb_check( false !== strpos( $occupied_out, 'Bestaand antwoord' ), 'The append fallback modified an existing populated accordion.' );
nova_wpb_check( 1 === substr_count( $occupied_out, '[vc_custom_heading text="Veelgestelde vragen"' ), 'The occupied-slot fallback did not render one overall FAQ heading.' );

// No "<h3>Q</h3><p>A</p>" pairs: fall back to one item under the section title.
$faq_no_pairs = array(
	'title'     => 'Veelgestelde vragen',
	'body'      => '<p>Gewone tekst zonder vraag-structuur.</p>',
	'title_tag' => 'h2',
	'type'      => 'faq',
);
$fallback_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_no_pairs ) );
nova_wpb_check( 1 === substr_count( $fallback_out, '[vc_tta_accordion' ), 'A body with no Q/A pairs did not produce exactly one fallback accordion.' );
nova_wpb_check( 1 === substr_count( $fallback_out, '[vc_tta_section title=' ), 'A body with no Q/A pairs produced more or fewer than one fallback item.' );
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Veelgestelde vragen" tab_id="[a-f0-9]{8}"\]\[vc_column_text\]<p>Gewone tekst zonder vraag-structuur\.<\/p>\[\/vc_column_text\]\[\/vc_tta_section\]/', $fallback_out ),
	'The no-pairs fallback did not wrap the whole body under the section title.'
);
nova_wpb_check( 1 === substr_count( $fallback_out, '[vc_custom_heading text="Veelgestelde vragen"' ), 'The no-pairs fallback did not retain the overall FAQ heading contract.' );

// Partial FAQ conversion is lossless: mixed or malformed bodies fall back whole.
$partial_faq_bodies = array(
	'preamble'       => '<p>Inleiding behouden.</p><h3>Vraag een</h3><p>Antwoord een</p>',
	'empty question' => '<h3>Vraag een</h3><p>Antwoord een</p><h3></h3><p>Los antwoord behouden.</p>',
	'empty answer'   => '<h3>Vraag een</h3><p>Antwoord een</p><h3>Vraag zonder antwoord</h3>',
	'unmatched h3'   => '<h3>Vraag een</h3><p>Antwoord een</p><h3>Onvolledig<p>Niet verliezen.</p>',
);
foreach ( $partial_faq_bodies as $label => $body ) {
	nova_wpb_check(
		$body === nova_wpb_convert_faq_html_to_vc_tta_accordion( $body ),
		'Partial FAQ conversion lost content: ' . $label
	);
}

$faq_with_preamble = $faq_section;
$faq_with_preamble['body'] = $partial_faq_bodies['preamble'];
$preamble_slot_doc = '[vc_row][vc_column][vc_tta_accordion style="modern"][/vc_tta_accordion][/vc_column][/vc_row]'
	. '[vc_row][vc_column][vso_auteur_block name="Yarno"][/vc_column][/vc_row]';
$preamble_slot_out = nova_wpb_apply_transformations( $preamble_slot_doc, array(), array(), '', array( $faq_with_preamble ) );
nova_wpb_check( 1 === substr_count( $preamble_slot_out, '[vc_tta_accordion' ), 'A partial FAQ body created a second accordion instead of reusing the template slot.' );
nova_wpb_check( 1 === substr_count( $preamble_slot_out, '[vc_tta_section title=' ), 'A partial FAQ body did not use the one-item lossless fallback.' );
nova_wpb_check( false !== strpos( $preamble_slot_out, '<p>Inleiding behouden.</p>' ) && false !== strpos( $preamble_slot_out, '<h3>Vraag een</h3>' ) && false !== strpos( $preamble_slot_out, '<p>Antwoord een</p>' ), 'The lossless FAQ fallback dropped preamble, question, or answer content.' );
nova_wpb_check( false !== strpos( $preamble_slot_out, '[vc_tta_accordion style="modern"]' ), 'The lossless FAQ fallback discarded template accordion attributes.' );
nova_wpb_check( strpos( $preamble_slot_out, '[vc_custom_heading text="Veelgestelde vragen"' ) < strpos( $preamble_slot_out, '[vc_tta_accordion' ), 'The lossless FAQ fallback moved its heading below the accordion.' );
nova_wpb_check( strpos( $preamble_slot_out, '[vc_tta_accordion' ) < strpos( $preamble_slot_out, 'vso_auteur_block' ), 'The lossless FAQ fallback moved below the author block.' );

// Invalid heading tags are normalized to H2; an empty title emits no heading.
$faq_invalid_tag = $faq_section;
$faq_invalid_tag['title_tag'] = 'h1';
$invalid_tag_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_invalid_tag ) );
nova_wpb_check( false !== strpos( $invalid_tag_out, 'font_container="tag:h2"' ), 'An invalid FAQ heading tag was not normalized to H2.' );
$faq_empty_title = $faq_section;
$faq_empty_title['title'] = '';
$empty_title_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_empty_title ) );
nova_wpb_check( 0 === substr_count( $empty_title_out, '[vc_custom_heading' ), 'An empty FAQ title emitted an empty heading.' );
$empty_title_no_pairs = $faq_no_pairs;
$empty_title_no_pairs['title'] = '';
$empty_title_no_pairs_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $empty_title_no_pairs ) );
nova_wpb_check( false !== strpos( $empty_title_no_pairs_out, '<p>Gewone tekst zonder vraag-structuur.</p>' ), 'A titleless unstructured FAQ body was dropped.' );
nova_wpb_check( 1 === substr_count( $empty_title_no_pairs_out, '[vc_tta_section title="FAQ"' ), 'A titleless unstructured FAQ body did not receive the deterministic fallback item title.' );
$empty_title_partial = $faq_with_preamble;
$empty_title_partial['title'] = '';
$empty_title_partial_out = nova_wpb_apply_transformations( '', array(), array(), '', array( $empty_title_partial ) );
nova_wpb_check( false !== strpos( $empty_title_partial_out, '<p>Inleiding behouden.</p>' ) && false !== strpos( $empty_title_partial_out, '<h3>Vraag een</h3>' ) && false !== strpos( $empty_title_partial_out, '<p>Antwoord een</p>' ), 'A titleless partial FAQ body was dropped.' );
nova_wpb_check( 1 === substr_count( $empty_title_partial_out, '[vc_tta_section title="FAQ"' ), 'A titleless partial FAQ body did not receive the deterministic fallback item title.' );
$empty_title_slot_doc = '[vc_row][vc_column][vc_column_text]<h2>Template FAQ title</h2>[/vc_column_text][vc_tta_accordion][/vc_tta_accordion][/vc_column][/vc_row]';
$empty_title_slot_out = nova_wpb_apply_transformations( $empty_title_slot_doc, array(), array(), '', array( $faq_empty_title ) );
nova_wpb_check( false !== strpos( $empty_title_slot_out, '<h2>Template FAQ title</h2>' ), 'An empty supplied FAQ title unexpectedly deleted the template heading.' );
nova_wpb_check( 0 === substr_count( $empty_title_slot_out, '[vc_custom_heading' ), 'An empty supplied FAQ title injected a duplicate heading into the template slot.' );

// An empty FAQ body must not emit an empty accordion shell.
$faq_empty = array( 'title' => 'Veelgestelde vragen', 'body' => '', 'title_tag' => 'h2', 'type' => 'faq' );
nova_wpb_check( '' === nova_wpb_apply_transformations( '', array(), array(), '', array( $faq_empty ) ), 'An empty FAQ section emitted an accordion shell with no content.' );

// The create-time slot filler must preserve a native FAQ row for the final FAQ pass.
$faq_slot_doc = '[vc_row][vc_column][vc_column_text]<h2>Veelgestelde vragen</h2>[/vc_column_text][vc_tta_accordion][/vc_tta_accordion][/vc_column][/vc_row]';
$r = null;
list( $faq_slot_after_fill, $faq_slot_remaining ) = nova_wpb_replace_template_slots_with_sections( $faq_slot_doc, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( $faq_slot_doc === $faq_slot_after_fill, 'The create-time slot filler modified a native FAQ placeholder row.' );
nova_wpb_check( 0 === $r['slots_found'] && 1 === count( $faq_slot_remaining ), 'The create-time slot filler consumed content into a native FAQ placeholder row.' );

// An FAQ column protects itself without making a sibling content column ineligible.
$mixed_faq_slot_doc = '[vc_row]'
	. '[vc_column width="1/2"][vc_custom_heading text="Oude kop" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude tekst</p>[/vc_column_text][/vc_column]'
	. '[vc_column width="1/2"][vc_column_text]<h2>Veelgestelde vragen</h2>[/vc_column_text]'
	. '[vc_tta_accordion style="modern"][/vc_tta_accordion][/vc_column]'
	. '[/vc_row]';
$r = null;
list( $mixed_faq_slot_out, $mixed_faq_slot_remaining ) = nova_wpb_replace_template_slots_with_sections( $mixed_faq_slot_doc, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'] && 1 === $r['slots_filled'] && 0 === count( $mixed_faq_slot_remaining ), 'An FAQ column made its sibling content column unfillable.' );
nova_wpb_check( false !== strpos( $mixed_faq_slot_out, 'text="Sectie een"' ) && false !== strpos( $mixed_faq_slot_out, '<p>Body een</p>' ), 'Generated content did not replace the sibling column slot.' );
nova_wpb_check( false === strpos( $mixed_faq_slot_out, 'Oude kop' ) && false === strpos( $mixed_faq_slot_out, 'Oude tekst' ), 'Old sibling-column copy survived beside generated content.' );
nova_wpb_check( false !== strpos( $mixed_faq_slot_out, '<h2>Veelgestelde vragen</h2>' ) && false !== strpos( $mixed_faq_slot_out, '[vc_tta_accordion style="modern"]' ), 'Filling a sibling column modified the reserved FAQ column.' );

$nested_faq_slot_doc = '[vc_row][vc_column width="1/1"]'
	. '[vc_custom_heading text="Oude buitenkop" font_container="tag:h2"]'
	. '[vc_column_text]<p>Oude buitentekst</p>[/vc_column_text]'
	. '[vc_row_inner][vc_column_inner width="1/1"][vc_column_text]<h2>Veelgestelde vragen</h2>[/vc_column_text]'
	. '[vc_tta_accordion style="modern"][/vc_tta_accordion][/vc_column_inner][/vc_row_inner]'
	. '[/vc_column][/vc_row]';
$r = null;
list( $nested_faq_slot_out, $nested_faq_slot_remaining ) = nova_wpb_replace_template_slots_with_sections( $nested_faq_slot_doc, array( $nova_wpb_sections[0] ), '', true, $r );
nova_wpb_check( 1 === $r['slots_found'] && 1 === $r['slots_filled'] && 0 === count( $nested_faq_slot_remaining ), 'A nested FAQ column made its outer content column unfillable.' );
nova_wpb_check( false !== strpos( $nested_faq_slot_out, 'text="Sectie een"' ) && false !== strpos( $nested_faq_slot_out, '<p>Body een</p>' ), 'Generated content did not replace the outer slot beside a nested FAQ column.' );
nova_wpb_check( false !== strpos( $nested_faq_slot_out, '<h2>Veelgestelde vragen</h2>' ) && false !== strpos( $nested_faq_slot_out, '[vc_tta_accordion style="modern"]' ), 'Filling an outer slot modified its nested FAQ column.' );

// The single-mega-section expander tags its FAQ subsection instead of pre-converting it.
$mega_section = array(
	array(
		'title'     => '',
		'body'      => '<h2>Intro</h2><p>Introtekst</p>'
			. '<h2>Veelgestelde vragen</h2><h3>Vraag A</h3><p>Antwoord A</p>',
		'title_tag' => 'h2',
	),
);
$expanded = nova_wpb_expand_single_html_section_to_multiple( $mega_section, 'Pagina titel' );
nova_wpb_check( 2 === count( $expanded ), 'The mega-section split did not produce an intro chunk plus an FAQ chunk.' );
$faq_chunk = $expanded[1];
nova_wpb_check( 'Veelgestelde vragen' === $faq_chunk['title'], 'The second chunk was not the FAQ subsection.' );
nova_wpb_check( isset( $faq_chunk['type'] ) && 'faq' === $faq_chunk['type'], 'The mega-section expander did not tag its FAQ subsection as type:"faq".' );
nova_wpb_check( false !== strpos( $faq_chunk['body'], '<h3>Vraag A</h3>' ), 'The mega-section expander pre-converted the FAQ body instead of leaving it raw for apply_transformations.' );

$mega_out = nova_wpb_apply_transformations( '', array(), array(), '', $expanded );
nova_wpb_check(
	1 === preg_match( '/\[vc_tta_section title="Vraag A" tab_id="[a-f0-9]{8}"\]\[vc_column_text\]Antwoord A\[\/vc_column_text\]\[\/vc_tta_section\]/', $mega_out ),
	'A FAQ subsection found inside a single mega-section did not become a native accordion.'
);

nova_wpb_report();
