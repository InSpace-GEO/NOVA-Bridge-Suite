<?php
/**
 * Standalone in-memory request/storage tests; no live WordPress writes.
 * For grammar/lifecycle coverage, pass --wp-parser=/path/class-wp-block-parser.php
 * or NOVA_WP_BLOCK_PARSER pointing to genuine WordPress core (with its siblings).
 * Without that dependency, grammar/success cases are explicitly skipped.
 */
define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
define( 'ET_BUILDER_VERSION', in_array( '--divi4', $argv, true ) ? '4.27.4' : '5.7.3' );
$parser_file = getenv( 'NOVA_WP_BLOCK_PARSER' );
foreach ( $argv as $argument ) { if ( 0 === strpos( $argument, '--wp-parser=' ) ) { $parser_file = substr( $argument, 12 ); } }
if ( $parser_file ) {
    if ( ! is_file( $parser_file ) ) { throw new RuntimeException( 'The genuine WordPress parser path does not exist.' ); }
    foreach ( [ 'class-wp-block-parser-block.php', 'class-wp-block-parser-frame.php' ] as $dependency ) { require_once dirname( $parser_file ) . '/' . $dependency; }
    require_once $parser_file;
}
class WP_Error { private $code, $message, $data; function __construct( $code, $message, $data = [] ) { $this->code = $code; $this->message = $message; $this->data = $data; } function get_error_code() { return $this->code; } function get_error_data() { return $this->data; } function get_error_message() { return $this->message; } }
class WP_REST_Response { private $data, $status; function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; } function get_data() { return $this->data; } function get_status() { return $this->status; } }
class WP_REST_Request extends ArrayObject { private $body = ''; function set_body( $body ) { $this->body = $body; } function get_body() { return $this->body; } function get_json_params() { return json_decode( $this->body, true ); } function set_param( $key, $value ) { $this[$key] = $value; } function get_param( $key ) { $json = $this->get_json_params(); return isset( $this[$key] ) ? $this[$key] : ( $json[$key] ?? null ); } }
class WP_Post { public $ID, $post_type, $post_status, $post_title, $post_content, $post_name, $post_parent, $post_author, $post_excerpt; function __construct( array $data ) { foreach ( array_merge( [ 'ID' => 0, 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => '', 'post_content' => '', 'post_name' => '', 'post_parent' => 0, 'post_author' => 7, 'post_excerpt' => '' ], $data ) as $key => $value ) { $this->$key = $value; } } }
$posts = []; $meta = []; $inserts = 0; $updates = 0; $deletes = 0; $checks = 0; $denied = []; $mismatch = false; $meta_failure = false;
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function current_user_can( $cap, ...$args ) { global $denied; return ! in_array( $cap, $denied, true ); }
function get_current_user_id() { return 7; }
function post_type_exists( $type ) { return in_array( $type, [ 'page', 'post', 'custom' ], true ); }
function get_post_type_object( $type ) { if ( ! post_type_exists( $type ) ) { return null; } return (object) [ 'show_in_rest' => true, 'cap' => (object) [ 'edit_posts' => 'edit_posts', 'create_posts' => 'custom' === $type ? 'create_custom' : 'edit_posts', 'publish_posts' => 'publish_posts', 'edit_others_posts' => 'edit_others_posts' ] ]; }
function get_post( $id ) { global $posts; return $posts[(int) $id] ?? null; }
function get_post_field( $field, $id ) { $post = get_post( $id ); return $post ? $post->$field : ''; }
function get_page_by_path( $path, $format = 'OBJECT', $type = 'page' ) { global $posts; foreach ( $posts as $post ) { if ( $post->post_name === $path && $post->post_type === $type ) { return $post; } } return null; }
function wp_slash( $value ) { return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value ); }
function wp_unslash( $value ) { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value ); }
function wp_insert_post( $data, $error = false ) { global $posts, $inserts, $mismatch; ++$inserts; $data = wp_unslash( $data ); $data['ID'] = 100 + $inserts; if ( $mismatch ) { $data['post_content'] .= "\n"; } $posts[$data['ID']] = new WP_Post( $data ); return $data['ID']; }
function wp_update_post( $data, $error = false ) { global $posts, $updates; ++$updates; $data = wp_unslash( $data ); foreach ( $data as $key => $value ) { $posts[$data['ID']]->$key = $value; } return $data['ID']; }
function wp_delete_post( $id, $force = false ) { global $posts, $meta, $deletes; ++$deletes; unset( $posts[$id], $meta[$id] ); return true; }
function get_post_meta( $id, $key = '', $single = true ) { global $meta; if ( '' === $key ) { $result = []; foreach ( $meta[$id] ?? [] as $name => $value ) { $result[$name] = [ $value ]; } return $result; } return $meta[$id][$key] ?? ''; }
function update_post_meta( $id, $key, $value ) { global $meta; $meta[$id][$key] = $value; return true; }
function add_post_meta( $id, $key, $value ) { return update_post_meta( $id, $key, $value ); }
function delete_post_meta( $id, $key ) { global $meta; unset( $meta[$id][$key] ); }
function maybe_unserialize( $value ) { return $value; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_strip_all_tags( $value ) { return strip_tags( $value ); }
function sanitize_title( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9-]+/', '-', $value ) ); }
function wp_kses_post( $value ) { return $value; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function cf_tmrb_update_post_meta_all_payload( $value, $post ) { global $meta_failure; return $meta_failure ? new WP_Error( 'fixture_meta_failure', 'Metadata failed.', [ 'status' => 500 ] ) : true; }
require dirname( __DIR__ ) . '/modules/divi/includes/pages.php';
function check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function request_body( array $body, $id = null ) { $request = new WP_REST_Request(); $request->set_body( wp_json_encode( $body ) ); if ( null !== $id ) { $request->set_param( 'id_or_slug', (string) $id ); } return $request; }
function reject_before_write( array $body, $status, $code = null ) { global $posts, $inserts, $updates, $deletes; $before = [ $posts, $inserts, $updates, $deletes ]; $result = nova_divi_create_page( request_body( $body ) ); check( is_wp_error( $result ) && $status === ( $result->get_error_data()['status'] ?? null ), 'Creation guard returned the wrong status.' ); if ( $code ) { check( $code === $result->get_error_code(), 'Creation guard returned the wrong code: ' . $result->get_error_code() ); } check( $before === [ $posts, $inserts, $updates, $deletes ], 'A rejected native request changed storage.' ); }
// Use json_encode for exact valid JSON escapes rather than a hand-written grammar stub.
$attrs = [ 'content' => [ 'innerContent' => [ 'desktop' => [ 'value' => '<h2>Native 🛰️</h2><p>Quoted "text" and \\ path.</p>' ], 'tablet' => [ 'value' => 'Tablet copy' ], 'phone' => [ 'value' => 'Phone copy' ] ] ], 'builderVersion' => '5.7.3', 'opaque' => [ 'keep' => '< & > --' ] ];
$document = '<!-- wp:divi/placeholder -->' . "\n" . '<!-- wp:divi/text ' . wp_json_encode( $attrs ) . ' /-->' . "\n" . '<!-- /wp:divi/placeholder -->';
$base = [ 'title' => 'Source-free native draft', 'status' => 'draft', 'layout' => [ 'native_document' => $document ] ];
check( null === nova_divi5_validate_create_request( [ 'title' => 'Legacy request' ] ), 'The new mode changed ordinary create dispatch.' );
foreach ( [ [ 'source_page_id' => 0 ], [ 'source_page' => '' ], [ 'text_updates' => [ [ 'path' => '0.0', 'text' => 'change' ] ] ], [ 'remove_paths' => [ '0.0' ] ], [ 'append_sections' => [ [ 'html' => 'append' ] ] ], [ 'append_html' => ' ' ], [ 'content' => '<p>Other body</p>' ], [ 'content' => [] ] ] as $conflict ) { reject_before_write( array_merge( $base, $conflict ), 400, 'nova_divi5_native_create_conflict' ); }
foreach ( [ 'raw_shortcodes' => '', 'compact' => [] ] as $key => $value ) { $body = $base; $body['layout'][$key] = $value; reject_before_write( $body, 400, 'nova_divi5_native_create_conflict' ); }
foreach ( [ [ 'text_updates' => false ], [ 'remove_paths' => '0.0' ], [ 'append_html' => 0 ], [ 'append_sections' => null ] ] as $malformed ) { reject_before_write( array_merge( $base, $malformed ), 400, 'rest_invalid_param' ); }
$denied = [ 'edit_posts' ]; reject_before_write( $base, 403, 'nova_divi_forbidden' );
$denied = [ 'publish_posts' ]; reject_before_write( array_merge( $base, [ 'status' => 'publish' ] ), 403, 'nova_divi_forbidden_publish' ); $denied = [];
foreach ( [ '', null, [], "bad\0document", "\xC3\x28" ] as $invalid ) { $result = nova_divi5_validate_create_document( $invalid ); check( is_wp_error( $result ) && 'nova_divi5_invalid_document' === $result->get_error_code(), 'Invalid native bytes were accepted before parsing.' ); }
$result = nova_divi5_validate_create_document( str_repeat( ' ', 2097153 ) ); check( is_wp_error( $result ) && 'nova_divi5_document_limit' === $result->get_error_code(), 'Oversized native document was not bounded.' );
foreach ( [ 'plain' => '<p>Original plain body</p>', 'divi4' => '[et_pb_text]Original shortcode body[/et_pb_text]', 'native' => $document ] as $kind => $content ) { $id = count( $posts ) + 1; $posts[$id] = new WP_Post( [ 'ID' => $id, 'post_title' => 'Original ' . $kind, 'post_content' => $content ] ); $before_updates = $updates; $result = nova_divi_update_page( request_body( [ 'title' => 'Must stay unchanged', 'layout' => [ 'native_document' => $document ] ], $id ) ); check( is_wp_error( $result ) && 'nova_divi5_native_create_only' === $result->get_error_code() && 422 === $result->get_error_data()['status'], 'An update accepted create-only native input on ' . $kind ); check( $posts[$id]->post_content === $content && $posts[$id]->post_title === 'Original ' . $kind && $updates === $before_updates, 'Create-only update rejection changed ' . $kind ); }
if ( ! nova_divi5_native_create_available() ) {
    reject_before_write( $base, 422, 'nova_divi5_native_create_unavailable' );
    check( false === nova_divi5_can_create_native_document(), 'Native creation was advertised without its provider/parser.' );
    echo 'PASS ' . $checks . " source-free pre-write and update guards. SKIP genuine-parser success/grammar cases (provider or parser unavailable).\n";
    exit;
}
check( nova_divi5_validate_create_document( $document ) === $document, 'Native validator changed valid bytes.' );
$spaced = str_replace( '<!-- wp:', '<!--  wp:', $document ); check( nova_divi5_validate_create_document( $spaced ) === $spaced, 'Valid WordPress delimiter whitespace was rejected.' );
$invalid_documents = [ '<p>Outside</p>' . $document, str_replace( "\n", "\n<p>Between</p>\n", $document ), $document . $document, '<!-- wp:divi/placeholder /-->', str_replace( '<!-- /wp:divi/placeholder -->', '<!-- /wp:divi/text -->', $document ), str_replace( '<!-- /wp:divi/placeholder -->', '', $document ), str_replace( '"opaque":', '"opaque":{},"opaque":', $document ), str_replace( 'Tablet copy', '[et_pb_text]Legacy[/et_pb_text]', $spaced ) ];
foreach ( $invalid_documents as $invalid ) { reject_before_write( array_merge( $base, [ 'layout' => [ 'native_document' => $invalid ] ] ), 422 ); }
$large = '<!-- wp:divi/placeholder --><!-- wp:divi/text {"content":"' . str_repeat( 'x', 2097152 ) . '"} /--><!-- /wp:divi/placeholder -->'; reject_before_write( array_merge( $base, [ 'layout' => [ 'native_document' => $large ] ] ), 422, 'nova_divi5_document_limit' );
$too_many = '<!-- wp:divi/placeholder -->' . str_repeat( '<!-- wp:divi/text {} /-->', 1000 ) . '<!-- /wp:divi/placeholder -->'; reject_before_write( array_merge( $base, [ 'layout' => [ 'native_document' => $too_many ] ] ), 422, 'nova_divi5_document_limit' );
$too_deep = str_repeat( '<!-- wp:divi/placeholder -->', 33 ) . str_repeat( '<!-- /wp:divi/placeholder -->', 33 ); reject_before_write( array_merge( $base, [ 'layout' => [ 'native_document' => $too_deep ] ] ), 422, 'nova_divi5_document_limit' );
$nested = 'value'; for ( $level = 0; $level < 33; ++$level ) { $nested = [ 'child' => $nested ]; } $json_deep = '<!-- wp:divi/placeholder --><!-- wp:divi/text ' . wp_json_encode( $nested ) . ' /--><!-- /wp:divi/placeholder -->'; reject_before_write( array_merge( $base, [ 'layout' => [ 'native_document' => $json_deep ] ] ), 422, 'nova_divi5_document_limit' );
$denied = [ 'unfiltered_html' ]; reject_before_write( $base, 403, 'nova_divi5_native_create_forbidden' ); check( ! nova_divi5_can_create_native_document(), 'Native creation was advertised without raw-document permission.' );
$denied = [ 'create_custom' ]; reject_before_write( array_merge( $base, [ 'post_type' => 'custom' ] ), 403, 'nova_divi5_native_create_forbidden' ); check( ! nova_divi5_can_create_native_document( 'custom' ), 'A distinct denied create_posts capability was ignored.' ); $denied = [];
foreach ( [ $document, $spaced ] as $valid ) { $result = nova_divi_create_page( request_body( array_merge( $base, [ 'layout' => [ 'native_document' => $valid ] ] ) ) ); check( $result instanceof WP_REST_Response && 201 === $result->get_status(), 'Valid source-free native creation failed.' ); $id = $result->get_data()['id']; check( get_post_field( 'post_content', $id ) === $valid && 'on' === get_post_meta( $id, '_et_pb_use_builder' ), 'Native creation did not preserve bytes/builder activation.' ); check( $result->get_data()['document_hash'] === nova_divi5_document_hash( $valid ) && $result->get_data()['divi']['format'] === 'divi5-blocks' && $result->get_data()['divi']['native_create'] === true, 'Native creation response omitted its truthful capability/hash/format.' ); }
$wrapped = [ 'content' => wp_json_encode( $base ) ]; $result = nova_divi_create_page( request_body( $wrapped ) ); check( $result instanceof WP_REST_Response && get_post_field( 'post_content', $result->get_data()['id'] ) === $document, 'Legacy JSON transport wrapping lost native source-free support.' );
reject_before_write( [ 'content' => wp_json_encode( array_merge( $base, [ 'content' => '<p>Actual conflicting body</p>' ] ) ) ], 400, 'nova_divi5_native_create_conflict' );
$before_posts = array_keys( $posts ); $before_deletes = $deletes; $mismatch = true; $result = nova_divi_create_page( request_body( $base ) ); $mismatch = false; check( is_wp_error( $result ) && 'nova_divi5_storage_mismatch' === $result->get_error_code(), 'Read-back mismatch was accepted.' ); check( array_keys( $posts ) === $before_posts && $deletes === $before_deletes + 1, 'A mismatched newly-created native page was not removed.' );
$meta_failure = true; $result = nova_divi_create_page( request_body( array_merge( $base, [ 'meta_all' => [ 'test' => 'value' ] ] ) ) ); $meta_failure = false; check( is_wp_error( $result ) && 'fixture_meta_failure' === $result->get_error_code() && array_keys( $posts ) === $before_posts, 'Metadata failure retained a partial new native page.' );
$source_id = 99; $posts[$source_id] = new WP_Post( [ 'ID' => $source_id, 'post_title' => 'Inspected existing native page', 'post_content' => $document ] ); $meta[$source_id] = [ 'source_marker' => 'retained' ];
$denied = [ 'unfiltered_html' ]; $clone = nova_divi_create_page( request_body( [ 'title' => 'Legacy native clone', 'status' => 'draft', 'source_page_id' => $source_id ] ) ); $denied = []; check( $clone instanceof WP_REST_Response && get_post_field( 'post_content', $clone->get_data()['id'] ) === $document && get_post_field( 'post_content', $source_id ) === $document, 'The new raw mode broke inspected native clone compatibility.' );
echo 'PASS ' . $checks . " source-free Divi 5 checks with genuine WordPress parser (guards, grammar, byte storage, cleanup and legacy clone).\n";
