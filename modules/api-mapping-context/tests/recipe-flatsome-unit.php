<?php
/** Pure parser/compiler and in-memory known-ID publication tests. No WordPress bootstrap or database. */
$core = getenv( 'NOVA_WP_CORE_FIXTURE' );
if ( ! $core || ! is_file( $core . '/shortcodes.php' ) ) { throw new RuntimeException( 'Set NOVA_WP_CORE_FIXTURE to the standalone WordPress shortcode parser.' ); }
define( 'ABSPATH', __DIR__ . '/no-wordpress/' ); define( 'UX_BUILDER_VERSION', '3.20.7-fixture' );
class WP_Error { public function __construct( public $code, public $message, public $data = [] ) {} public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_kses_post( $value ) { return preg_replace( '/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function current_user_can( ...$args ) { return $GLOBALS['permission']; }
function get_post_type_object( $type ) { return in_array( $type, [ 'page', 'post', 'service' ], true ) ? (object) [ 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'edit_posts' ] ] : null; }
function get_post( $id ) { if ( $GLOBALS['forbid_post_reads'] ) { throw new RuntimeException( 'Compilation attempted to read the example post.' ); } return $GLOBALS['posts'][$id] ?? null; }
function flatsome_get_block_id( $reference ) { if ( ctype_digit( (string) $reference ) ) { return (int) $reference; } foreach ( $GLOBALS['posts'] as $post ) { if ( 'blocks' === $post->post_type && ( $post->post_name ?? '' ) === $reference ) { return $post->ID; } } return 0; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['meta'][$id] ?? [] ); }
function get_page_template_slug( $id ) { $value = get_post_meta( $id, '_wp_page_template' ); return 'default' === $value ? '' : $value; }
function wp_get_attachment_url( $id ) { return 'https://fixture.test/uploads/' . $id . '.jpg'; }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_update_post( $data, $error = false ) { $data = wp_unslash( $data ); $id = $data['ID']; ++$GLOBALS['writes']; foreach ( $data as $key => $value ) { if ( 'meta_input' === $key ) { foreach ( $value as $name => $part ) { $GLOBALS['meta'][$id][$name] = $part; } } elseif ( 'page_template' === $key ) { $GLOBALS['meta'][$id]['_wp_page_template'] = $value; } else { $GLOBALS['posts'][$id]->$key = $value; } } return $id; }
function wp_insert_post( $data, $error = false ) { $id = ++$GLOBALS['next_id']; $GLOBALS['posts'][$id] = (object) []; return wp_update_post( $data + [ 'ID' => $id ], $error ); }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][$id][$key] ); }
function clean_post_cache( $id ) {}
function get_current_user_id() { return 7; }
function get_edit_post_link( $id, $mode ) { return 'https://fixture.test/edit/' . $id; }
function get_preview_post_link( $id ) { return 'https://fixture.test/preview/' . $id; }
function apply_filters( $hook, $value, ...$args ) { return 'ux_builder_meta_options' === $hook ? [ '_footer' => [ 'type' => 'select' ], '_wp_page_template' => [ 'type' => 'select' ], '_thumbnail_id' => [ 'type' => 'image' ], '_custom_header' => [ 'type' => 'select' ], '_edit_lock' => [ 'type' => 'textfield' ] ] : $value; }
class Flatsome_Unit_Registry {
    public function get( $tag ) {
        $tag = preg_replace( '/_inner(?:_[0-9]+)?$/', '', $tag ); if ( 'ux_text' === $tag ) { $tag = 'text'; }
        if ( in_array( $tag, [ 'row', 'col', 'section', 'ux_banner', 'text' ], true ) ) { $options = [ 'background_options' => [ 'type' => 'group', 'options' => [ 'bg' => [ 'type' => 'image' ], 'bg_pos' => [ 'type' => 'textfield' ] ] ] ]; if ( 'text' === $tag ) { $options['$content'] = [ 'type' => 'text-editor' ]; } return [ 'name' => ucfirst( $tag ), 'type' => 'container', 'options' => $options ]; }
        if ( in_array( $tag, [ 'title', 'button' ], true ) ) { return [ 'name' => ucfirst( $tag ), 'options' => [ 'text' => [ 'type' => 'textfield', 'heading' => 'Text' ], 'link' => [ 'type' => 'textfield' ], 'style' => [ 'type' => 'select' ] ] ]; }
        if ( 'ux_image' === $tag ) { return [ 'name' => 'Image', 'options' => [ 'id' => [ 'type' => 'image' ] ] ]; }
        if ( 'block' === $tag ) { return [ 'name' => 'Block', 'external' => true, 'options' => [ 'id' => [ 'type' => 'select' ] ] ]; }
        if ( 'client_photo' === $tag ) { return [ 'name' => 'Client photo', 'options' => [ 'photo' => [ 'type' => 'image' ], 'caption' => [ 'type' => 'textfield' ] ] ]; }
        return false;
    }
}
function ux_builder_shortcodes() { return new Flatsome_Unit_Registry(); }
function ux_row() {} function ux_col() {}
require $core . '/shortcodes.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-engine.php';
require dirname( __DIR__ ) . '/includes/class-nova-bridge-suite-recipe-flatsome.php';
$permission = true; $forbid_post_reads = false; $posts = $meta = []; $writes = 0; $next_id = 100;
$adapter = new Nova_Bridge_Suite_Recipe_Flatsome(); Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'flatsome', $adapter );
$checks = 0;
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function good( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_message() ); } return $value; }
function blueprint( $source ) { return [ 'document' => good( $GLOBALS['adapter']->capture_document( $source ) ), 'template' => '', 'meta' => [] ]; }
function find_field( array $fields, $needle ) { foreach ( $fields as $field ) { if ( str_contains( $field['target'], $needle ) ) { return $field; } } throw new RuntimeException( 'Missing field: ' . $needle ); }

$fixtures = [
    "Before\n[section bg='7' bg_pos=\"20% 50%\"][row][col span='6']<h1 class=\"hero\">Service &amp; care</h1><p style='color:red'>One <strong>body</strong>.</p>[button text='Click &amp; go' link=\"https://example.test/?a=1&amp;b=2\"][/col][/row][/section]\nAfter",
    '[row][col][row_inner][col_inner]<p>Nested</p>[/col_inner][/row_inner][/col][/row]',
    '[row][row]<p>Same name nesting</p>[/row][/row]',
    '[[ux_image id="1"]] [ux_image id="2" /] [unknown foo="preserve"]<p>Interactive body</p>[/unknown]',
    '<!-- wp:flatsome/uxbuilder --><div class="frame">[section]<h2>Heading</h2><p>Paragraph</p>[/section]</div><!-- /wp:flatsome/uxbuilder -->',
    '<p>Plain HTML before</p>[ux_text font_size="1.2"]<p>Styled body</p>[/ux_text]<p>Plain HTML after</p>',
];
foreach ( $fixtures as $source ) { $b = blueprint( $source ); check( good( $adapter->finalize( $b, 'roundtrip' ) )['native_document'] === $source, 'Capturing and compiling preserves every native source byte.' ); }
$b = blueprint( $fixtures[0] ); $fields = $adapter->fields( $b );
$heading = null; $body = null; foreach ( $fields as $field ) { if ( 'heading' === $field['kind'] ) { $heading = $field; } if ( 'body' === $field['kind'] && str_contains( $field['value'], "<p style='color:red'>" ) ) { $body = $field; } }
check( $heading && $body, 'Unwrapped native HTML headings and paragraphs are editable.' );
$frozen = $b;
$b = good( $adapter->bind( $b, [ 'target' => $heading['target'], 'format' => 'text' ], 'New heading & value' ) );
$b = good( $adapter->bind( $b, [ 'target' => $body['target'], 'format' => 'html' ], '<p>New <em>body</em>.</p>' ) );
$button = find_field( $fields, '/attributes/text' ); $b = good( $adapter->bind( $b, [ 'target' => $button['target'], 'format' => 'text' ], 'Read "more" & book' ) );
$out = good( $adapter->finalize( $b, 'edited' ) )['native_document'];
check( str_contains( $out, '<h1 class="hero">New heading &amp; value</h1>' ) && str_contains( $out, "<p style='color:red'>New <em>body</em>.</p>" ), 'Text bindings preserve native heading/paragraph styling without duplicate wrappers.' );
check( str_contains( $out, "bg='7' bg_pos=\"20% 50%\"" ) && str_contains( $out, 'span=\'6\'' ) && str_contains( $out, "text='Read &quot;more&quot; &amp; book'" ), 'Only selected shortcode values change, retaining native formatting and design attributes.' );
check( $frozen === blueprint( $fixtures[0] ), 'Bindings do not mutate the captured source blueprint.' );
$image_fields = $adapter->fields( blueprint( '[section bg="12"][ux_image id="34"][client_photo photo="56" caption="Client"][/section]' ) );
foreach ( [ '/attributes/bg', '/attributes/id', '/attributes/photo' ] as $target ) { $field = find_field( $image_fields, $target ); check( $field['format'] === 'url' && $field['native_media']['kind'] === 'flatsome' && $field['native_media']['id_target'] === $field['target'], 'Registered native image controls expose attachment-ID placement through an image-URL mapping.' ); }
check( ! in_array( 'bg_pos', array_column( $fields, 'kind' ), true ), 'Native layout settings are not exposed as editorial text.' );
$linked = blueprint( '[section][block id="123"][/section]' );
check( good( $adapter->finalize( $linked, 'linked' ) )['native_document'] === '[section][block id="123"][/section]' && count( good( $adapter->describe( $linked ) )['reusable_blocks'] ) === 1, 'Reusable UX Blocks remain exact live links unless explicitly captured.' );
$unknown = $adapter->fields( blueprint( '[unknown]<h2>Dynamic title</h2><p>Dynamic body</p>[/unknown]' ) );
check( count( array_filter( $unknown, static fn( $field ) => $field['writable'] ) ) === 0, 'Unknown interactive shortcode content remains protected.' );
$script = $adapter->fields( blueprint( '<script>"<p>Not visible prose</p>"</script>' ) );
check( count( array_filter( $script, static fn( $field ) => $field['writable'] ) ) === 0, 'Script-looking prose is not exposed as a content field.' );
$script = $adapter->fields( blueprint( '<script>"[title text=\'Do not bind\']"</script><!-- [title text="Comment"] -->' ) );
check( count( array_filter( $script, static fn( $field ) => $field['writable'] ) ) === 0, 'Shortcode-looking code and HTML comments remain opaque.' );

$rich = '<p>First paragraph.</p><h2>New section</h2><p>Second paragraph.</p><ul><li>First item</li><li>Second item</li></ul><table><tbody><tr><td>Complete table</td></tr></tbody></table>';
foreach ( [ '[ux_text font_size="1.2"]<p class="lead">Old one.</p><h2>Old heading</h2><p>Old two.</p>[/ux_text]', '[col class="frame"]<p class="lead">Old one.</p><p>Old two.</p>[/col]' ] as $source ) {
    $b = blueprint( $source ); $body_fields = array_values( array_filter( $adapter->fields( $b ), static fn( $field ) => $field['writable'] && $field['kind'] === 'body' ) );
    check( count( $body_fields ) === 1, 'A native editor content region or adjacent unstyled body has one complete rich-text destination.' );
    $edited = good( $adapter->bind( $b, [ 'target' => $body_fields[0]['target'], 'format' => 'html' ], $rich ) ); $out = good( $adapter->finalize( $edited, 'multi-block' ) )['native_document'];
    $expected = str_replace( '<p>First paragraph.</p>', '<p class="lead">First paragraph.</p>', $rich );
    check( str_contains( $out, $expected ) && substr_count( $out, '<p' ) === 2 && substr_count( $out, '<h2' ) === 1 && str_contains( $out, '</p><h2>' ) && str_contains( $out, '</p><ul>' ) && str_contains( $out, '</ul><table>' ), 'Multi-block mapped output retains paragraphs, heading, list and table without nesting them inside an old paragraph.' );
    check( str_starts_with( $out, substr( $source, 0, strpos( $source, ']' ) + 1 ) ), 'The rich-text replacement preserves its surrounding native design shortcode.' );
}
$b = blueprint( '[ux_text font_size="2"]<h1 class="hero">Old heading</h1>[/ux_text]' ); $heading_field = array_values( array_filter( $adapter->fields( $b ), static fn( $field ) => $field['kind'] === 'heading' ) )[0];
$edited = good( $adapter->bind( $b, [ 'target' => $heading_field['target'], 'format' => 'text' ], 'New title' ) ); check( good( $adapter->finalize( $edited, 'heading' ) )['native_document'] === '[ux_text font_size="2"]<h1 class="hero">New title</h1>[/ux_text]', 'A standalone heading in a native text control keeps its heading and style shell.' );
$b = blueprint( '[ux_text font_size="1.2"][/ux_text]' ); $empty_body = array_values( array_filter( $adapter->fields( $b ), static fn( $field ) => $field['kind'] === 'body' ) );
check( count( $empty_body ) === 1 && $empty_body[0]['writable'] && $empty_body[0]['format'] === 'html' && $empty_body[0]['value'] === '', 'A declared empty UX text control remains available for content mapping.' );
$edited = good( $adapter->bind( $b, [ 'target' => $empty_body[0]['target'], 'format' => 'html' ], $rich ) ); check( good( $adapter->finalize( $edited, 'empty-filled' ) )['native_document'] === '[ux_text font_size="1.2"]' . $rich . '[/ux_text]', 'A blank native text control accepts a complete multi-block rich-text value.' );

$posts[10] = (object) [ 'ID' => 10, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Example', 'post_excerpt' => '', 'post_content' => $fixtures[0] ];
$meta[10] = [ '_footer' => 'disabled', '_custom_header' => 'transparent', '_thumbnail_id' => '9', '_edit_lock' => 'private', '_wp_page_template' => 'page-blank.php' ];
$captured = good( $adapter->capture( 10 ) );
check( array_keys( $captured['blueprint']['meta'] ) === [ '_footer', '_custom_header' ] && $captured['blueprint']['template'] === 'page-blank.php', 'Native declared presentation metadata and page template are frozen; media and editor identity are excluded.' );
$forbid_post_reads = true; $compiled = good( $adapter->finalize( $captured['blueprint'], 'source-gone' ) ); $forbid_post_reads = false;
check( $compiled['native_document'] === $fixtures[0], 'Compilation uses only the frozen document after the example becomes unreadable.' );
$posts[20] = (object) [ 'ID' => 20, 'post_type' => 'service', 'post_status' => 'draft', 'post_title' => 'Old', 'post_excerpt' => '', 'post_content' => '', 'post_author' => 9 ];
good( $adapter->publication_apply( 20, $compiled['writer_payload'], [ 'title' => "Brand new client's service", 'excerpt' => 'New excerpt' ] ) );
check( $posts[20]->post_content === $fixtures[0] && $posts[20]->post_title === "Brand new client's service" && $posts[20]->post_status === 'draft' && $posts[20]->post_author === 9 && $meta[20]['_footer'] === 'disabled', 'Known-ID writing saves exact shortcode source/design while retaining status, author and identity.' );
$snapshot = good( $adapter->publication_snapshot( 20, $compiled['writer_payload'] ) ); $before = $writes; good( $adapter->publication_verify( 20, $compiled['writer_payload'] ) ); check( $writes === $before, 'Native verification is read-only.' );
$posts[20]->post_content .= '<p>Changed</p>'; check( is_wp_error( $adapter->publication_verify( 20, $compiled['writer_payload'] ) ), 'Readback detects a changed shortcode document.' );
$absent = $compiled['writer_payload']; $absent['meta']['_footer'] = [ 'present' => false, 'value' => '' ]; good( $adapter->publication_apply( 20, $absent, [ 'title' => 'Replacement' ] ) ); check( ! metadata_exists( 'post', 20, '_footer' ), 'A captured absent presentation value removes only that explicitly captured setting.' );
$permission = false; $before = $writes; check( is_wp_error( $adapter->publication_apply( 20, $absent, [ 'title' => 'Denied' ] ) ) && $writes === $before, 'Permission denial performs no native write.' ); $permission = true;
// Selective capture is read-only and keeps occurrence paths stable, including descendants.
$posts[301] = (object) [ 'ID' => 301, 'post_type' => 'blocks', 'post_status' => 'publish', 'post_title' => 'Service hero', 'post_name' => 'hero', 'post_content' => '[section bg="7"][row][col span="6"][ux_text]<p>Stored service text.</p>[/ux_text][block id="302"][/col][/row][/section]' ];
$posts[302] = (object) [ 'ID' => 302, 'post_type' => 'blocks', 'post_status' => 'publish', 'post_title' => 'Shared footer', 'post_name' => 'shared-footer', 'post_content' => '[section][row][col][row_inner][col_inner]<p>Nested frozen text.</p>[/col_inner][/row_inner][/col][/row][/section]' ];
$posts[30] = (object) [ 'ID' => 30, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[section][row][col]<h1>Page title</h1>[block id="hero"][block id="301"]<p>After the blocks.</p>[/col][/row][/section]' ];
$before = $writes; $captured = good( $adapter->capture( 30 ) ); $original = $captured['blueprint'];
check( count( $captured['reusable_blocks'] ) === 2 && array_column( $captured['reusable_blocks'], 'mode' ) === [ 'linked', 'linked' ], 'Capture defaults every reusable block occurrence to linked, including repeated references.' );
check( Nova_Bridge_Suite_Recipe_Flatsome::has_document( '[block id="301"]' ), 'A block-only page is detected as Flatsome when the native theme is active.' );
check( ! array_filter( $captured['fields'], static fn( $f ) => str_contains( $f['target'], '/attributes/id' ) ), 'Linked block references never expose their IDs or live block content as mapping destinations.' );
$first_target = $captured['reusable_blocks'][0]['target']; $second_target = $captured['reusable_blocks'][1]['target'];
$expanded = good( $adapter->expand_block( $original, $first_target ) );
check( $writes === $before && count( $expanded['reusable_blocks'] ) === 3 && $expanded['reusable_blocks'][0]['mode'] === 'captured', 'Expanding one occurrence captures read-only and discovers its still-linked child.' );
$second = array_values( array_filter( $expanded['reusable_blocks'], static fn( $b ) => $b['target'] === $second_target ) );
check( count( $second ) === 1 && $second[0]['mode'] === 'linked', 'The duplicate block occurrence and its native path remain unchanged.' );
check( Nova_Bridge_Suite_Recipe_Flatsome::linked_blueprint( $expanded['blueprint'] ) === $original, 'Source provenance projects captured blocks back to their exact original protected native nodes.' );
$original_after = array_values( array_filter( $captured['fields'], static fn( $f ) => str_contains( $f['value'], 'After the blocks' ) ) )[0];
$expanded_after = array_values( array_filter( $expanded['fields'], static fn( $f ) => str_contains( $f['value'], 'After the blocks' ) ) )[0];
check( $original_after['target'] === $expanded_after['target'], 'Existing mapping destinations outside the captured occurrence retain their paths.' );
$body = array_values( array_filter( $expanded['fields'], static fn( $f ) => str_contains( $f['value'], 'Stored service text' ) ) )[0];
$bound = good( $adapter->bind( $expanded['blueprint'], [ 'target' => $body['target'], 'format' => 'html' ], '<p>Mapped frozen text.</p>' ) );
$out = good( $adapter->finalize( $bound, 'selective' ) )['native_document'];
check( str_contains( $out, '[section_inner bg="7"][row_inner][col_inner span="6"]' ) && str_contains( $out, '[block id="302"]' ) && str_contains( $out, '[block id="301"]' ) && ! str_contains( $out, 'id="hero"' ), 'Only the selected block is inlined with safe native nesting aliases; its child and duplicate remain linked.' );
$nested_target = $expanded['reusable_blocks'][1]['target']; $nested = good( $adapter->expand_block( $bound, $nested_target ) );
$nested_out = good( $adapter->finalize( $nested['blueprint'], 'nested' ) )['native_document'];
check( str_contains( $nested_out, '[section_inner_1][row_inner_1][col_inner_1][row_inner_2][col_inner_2]' ), 'Nested selected blocks and their original native aliases avoid all introduced ancestor tag collisions.' );
$options = good( $adapter->capture( 30, [ 'block_expansions' => [ $nested_target, $first_target ] ] ) );
check( count( array_filter( $options['reusable_blocks'], static fn( $b ) => 'captured' === $b['mode'] ) ) === 2, 'Optional capture selections resolve parent first even if their input order is reversed.' );
unset( $posts[301], $posts[302], $posts[30] ); $forbid_post_reads = true;
check( good( $adapter->finalize( $nested['blueprint'], 'sources-deleted' ) )['native_document'] === $nested_out, 'Frozen selected blocks compile after their source blocks and source page are deleted, without any source read.' );
check( Nova_Bridge_Suite_Recipe_Flatsome::linked_blueprint( $nested['blueprint'] ) === $original, 'Even recursively captured source projection never resolves deleted blocks.' ); $forbid_post_reads = false;
check( is_wp_error( $adapter->expand_block( $original, $first_target ) ), 'A missing block fails only explicit capture; the original live reference stays intact.' );
check( good( $adapter->finalize( $original, 'still-linked' ) )['native_document'] === '[section][row][col]<h1>Page title</h1>[block id="hero"][block id="301"]<p>After the blocks.</p>[/col][/row][/section]', 'An unresolved unselected link does not prevent preserving the original layout.' );
$inside_text = blueprint( '[ux_text font_size="1.2"]<p>Before</p>[block id="301"]<p>After</p>[/ux_text]' );
$inside = good( $adapter->describe( $inside_text ) );
check( count( $inside['reusable_blocks'] ) === 1 && ! array_filter( $inside['fields'], static fn( $f ) => str_contains( $f['value'], '[block' ) && $f['writable'] ), 'Block references inside native text controls retain their own paths and cannot be overwritten through a whole-body binding.' );
$protected = good( $adapter->describe( blueprint( '[unknown][block id="301"][/unknown]' ) ) );
check( ! $protected['reusable_blocks'][0]['expandable'] && is_wp_error( $adapter->expand_block( blueprint( '[unknown][block id="301"][/unknown]' ), $protected['reusable_blocks'][0]['target'] ) ), 'A block inside an unknown interactive shortcode remains protected.' );
$posts[303] = (object) [ 'ID' => 303, 'post_type' => 'blocks', 'post_status' => 'publish', 'post_title' => 'Recursive', 'post_content' => '[block id="303"]' ];
$cycle = good( $adapter->expand_block( blueprint( '[block id="303"]' ), '/document/0' ) );
check( is_wp_error( $adapter->expand_block( $cycle['blueprint'], '/document/0/children/0' ) ), 'Explicit selection cannot recursively capture a block already present in its ancestor chain.' );
$permission = false; check( is_wp_error( $adapter->expand_block( blueprint( '[block id="303"]' ), '/document/0' ) ), 'Explicit block capture requires authoring permission.' ); $permission = true;
$shortcode_tags = [ 'row' => 'ux_row', 'col' => 'ux_col', 'section' => static fn() => 'native-section', 'row_inner' => static fn() => 'existing-custom' ]; $existing_alias = $shortcode_tags['row_inner'];
Nova_Bridge_Suite_Flatsome_Document::register_nested_aliases();
check( $shortcode_tags['row_inner'] === $existing_alias && $shortcode_tags['row_inner_8'] === $shortcode_tags['row'] && $shortcode_tags['section_inner_2'] === $shortcode_tags['section'] && ! isset( $shortcode_tags['section_inner_9'] ), 'Frontend aliases are bounded native callback aliases and never overwrite an existing shortcode.' );
check( $writes === $before, 'All selective block capture, projection and compilation checks perform no native writes.' );
echo 'Flatsome recipe checks: ' . $checks . " passed.\n";
