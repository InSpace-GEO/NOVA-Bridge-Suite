<?php
/** Normal UI capture/save/draft/recovery with actual media resolver and isolated memory fixtures. */
require __DIR__ . '/layout-recipes-api-unit.php';
$media_rows = []; $thumbnails = []; $media_lookup_failure = false; $thumbnail_save_failure = false;
class MediaRecipeDb extends RecipeDb {
    public $last_error = '';
    public function get_col( $statement ) { if ( $GLOBALS['media_lookup_failure'] ) { throw new RuntimeException( 'Simulated interrupted import' ); } $id = $GLOBALS['media_rows'][$statement['args'][1]] ?? 0; return $id ? [ $id ] : []; }
}
$wpdb = new MediaRecipeDb();
function wp_attachment_is_image( $id ) { return $id >= 9000; }
function wp_get_attachment_metadata( $id ) { return [ 'width' => 600, 'height' => 400 ]; }
function wp_get_attachment_url( $id ) { return 'https://site.example/uploads/imported-' . $id . '.jpg'; }
function get_post_thumbnail_id( $id ) { return $GLOBALS['thumbnails'][$id] ?? 0; }
function set_post_thumbnail( $id, $attachment ) { if ( $GLOBALS['thumbnail_save_failure'] ) { throw new RuntimeException( 'Simulated interruption before featured assignment' ); } $GLOBALS['thumbnails'][$id] = $attachment; return true; }
function delete_post_thumbnail( $id ) { unset( $GLOBALS['thumbnails'][$id] ); return true; }
function image_witness( $operation, $url, $id ) { $GLOBALS['media_rows']['nova.cpt-media/v1:' . hash( 'sha256', 'layout-draft:' . $operation . ':' . $url )] = $id; }
$administrator = true; $nonce_valid = true; $denied_caps = []; $native_fail = false;
$design = data( route( 'design', 'POST', [], [ 'resource_id' => 'post_type:post', 'builder' => 'core', 'reference_id' => 12, 'mode' => 'static' ] ) );
$featured = array_values( array_filter( $design['fields'], static function ( $field ) { return $field['target'] === '/native_post/featured_image'; } ) );
check( count( $featured ) === 1 && false === $featured[0]['required'], 'Normal Posts capture exposes optional featured image' );
check( ! in_array( '/native_post/featured_image', array_column( $design['recipe_preset']['bindings'], 'target' ), true ), 'Featured image is not silently selected by capture' );
$recipe = $design['recipe_preset']; $recipe['bindings'][] = [ 'target' => '/native_post/featured_image', 'source' => '/article_image', 'format' => 'url', 'required' => false, 'label' => 'Featured image', 'instructions' => '', 'native_media' => true ];
$content = $design['sample']; $content['article_image'] = 'https://assets.example/article.jpg';
$preview = data( route( 'preview', 'POST', [], [ 'resource_id' => 'post_type:post', 'recipe' => $recipe, 'seal' => $design['seal'], 'input' => [ 'content' => $content ] ] ) );
$saved = data( route( 'save', 'POST', [], [ 'resource_id' => 'post_type:post', 'recipe' => $recipe, 'seal' => $design['seal'], 'expected_revision' => 0, 'expected_default' => default_identity( 'post_type:post' ), 'label' => 'Article with featured image', 'content' => $content, 'preview_receipt' => $preview['preview_receipt'] ] ) );
check( $saved['recipe']['bindings'][count($saved['recipe']['bindings'])-1]['native_media'] === true, 'Normal save retains explicit media choice' );
$body = draft_body( $saved, $content ); image_witness( $body['operation_id'], $content['article_image'], 9001 ); $before = $writes;
$result = data( route( 'draft', 'POST', [], $body ) ); check( $writes === $before + 1 && get_post_thumbnail_id( $result['id'] ) === 9001, 'Normal UI draft creates exactly one native post and assigns retained image' );
$again = data( route( 'draft', 'POST', [], $body ) ); check( $again['id'] === $result['id'] && $writes === $before + 1, 'Completed request is idempotent' );

// Interrupted after the draft witness but before media resolution: recover the same ID.
$interrupted = draft_body( $saved, $content ); image_witness( $interrupted['operation_id'], $content['article_image'], 9002 ); $media_lookup_failure = true; $before = $writes;
rejected( route( 'draft', 'POST', [], $interrupted ), 409, 'Unfinished media cannot report successful UI draft' ); $media_lookup_failure = false;
$recovered = data( route( 'draft', 'GET', [ 'operation_id' => $interrupted['operation_id'] ] ) ); check( $writes === $before + 1 && get_post_thumbnail_id( $recovered['id'] ) === 9002, 'Resume after native witness does not create a duplicate' );

// Interrupted after import, before thumbnail association: retained attachment is reused.
$partial = draft_body( $saved, $content ); image_witness( $partial['operation_id'], $content['article_image'], 9003 ); $thumbnail_save_failure = true; $before = $writes;
rejected( route( 'draft', 'POST', [], $partial ), 409, 'Partial thumbnail save remains recoverable' ); $thumbnail_save_failure = false;
$recovered = data( route( 'draft', 'GET', [ 'operation_id' => $partial['operation_id'] ] ) ); check( $writes === $before + 1 && get_post_thumbnail_id( $recovered['id'] ) === 9003, 'Resume after import reuses the recorded attachment' );

$manual = draft_body( $saved, $content ); image_witness( $manual['operation_id'], $content['article_image'], 9004 ); $thumbnail_save_failure = true;
rejected( route( 'draft', 'POST', [], $manual ), 409, 'Prepare interrupted request for manual-edit guard' ); $thumbnail_save_failure = false;
$journal = $options['nova_bridge_layout_recipe_operations_v1'][$manual['operation_id']]; $ids = array_keys( $posts ); $id = max( $ids ); $thumbnails[$id] = 9876; $before = $writes;
rejected( route( 'draft', 'GET', [ 'operation_id' => $manual['operation_id'] ] ), 409, 'Recovery must not replace a human thumbnail change' );
check( get_post_thumbnail_id( $id ) === 9876 && $writes === $before, 'Manual featured choice and native draft remain untouched' );
$thumbnails[$id] = 0; $posts[$id]->post_content = 'Human edited body';
rejected( route( 'draft', 'GET', [ 'operation_id' => $manual['operation_id'] ] ), 409, 'Recovery must not replace a human body change' );
check( $posts[$id]->post_content === 'Human edited body' && get_post_thumbnail_id($id) === 0, 'Manual body change blocks media application' );
echo "PASS UI native-media capture/save/draft/recovery ($checks total isolated assertions).\n";
