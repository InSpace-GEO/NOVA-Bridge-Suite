<?php
/**
 * wp eval-file tests/beaver-repeaters-regression.php /path/to/full-response.json [output-dir]
 * Uses temporary drafts and the supplied native layout; deletes all test posts.
 * Requires Beaver Builder active. No substitute third-party renderers are installed.
 */
if ( 'cli' !== PHP_SAPI ) { exit( 1 ); }
if ( ! function_exists( 'nova_bb_get_page' ) || ! class_exists( 'FLBuilder' ) ) {
    throw new RuntimeException( 'Enable the Beaver bridge and Beaver Builder first.' );
}
$checks = 0;
$ids = array();
$check = static function ( $ok, $message ) use ( &$checks ) {
    if ( ! $ok ) { throw new RuntimeException( $message ); }
    ++$checks;
};
$request = static function ( $method, $route, $body = null, $query = array() ) {
    $r = new WP_REST_Request( $method, $route );
    $r->set_query_params( $query );
    if ( null !== $body ) {
        $r->set_header( 'Content-Type', 'application/json' );
        $r->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $r );
};
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$check( ! empty( $admin ), 'Administrator required.' );
wp_set_current_user( (int) $admin[0] );
$fixture = json_decode( file_get_contents( $args[0] ), true );
$check( 3312 === $fixture['id'] && 158 === count( $fixture['document'] ), 'Expected Variso page 3312 fixture.' );
$original = nova_bb_normalize_incoming_nodes( $fixture['document'] );
$new_post = static function ( $name, $nodes ) use ( &$ids, $check ) {
    $id = wp_insert_post( array( 'post_title' => 'NOVA BB286 test ' . $name . ' ' . wp_generate_uuid4(), 'post_type' => 'page', 'post_status' => 'draft' ), true );
    $check( ! is_wp_error( $id ), 'Could not create temporary source.' );
    $ids[] = $id;
    update_post_meta( $id, '_fl_builder_data', wp_slash( $nodes ) );
    update_post_meta( $id, '_fl_builder_enabled', 1 );
    return $id;
};
$settings_list = static function ( $nodes ) {
    $result = array();
    $walk = static function ( $tree ) use ( &$walk, &$result ) {
        foreach ( $tree as $n ) {
            $result[] = array( 'type' => $n['type'], 'settings' => json_decode( wp_json_encode( $n['settings'] ), true ), 'children' => count( $n['children'] ) );
            $walk( $n['children'] );
        }
    };
    $walk( nova_bb_flat_to_tree( $nodes ) );
    return $result;
};
try {
    $source = $new_post( 'source', $original );
    $source_snapshot = serialize( get_post_meta( $source, '_fl_builder_data', true ) );
    $response = $request( 'GET', '/nova-beaver/v1/pages/' . $source, null, array( 'text_map' => true, 'include_document' => false ) );
    $check( 200 === $response->get_status(), 'GET failed.' );
    $data = $response->get_data();
    $check( 83 === count( $data['layout']['outline'] ), 'GET must expose 58 original entries + 25 repeater items.' );
    $check( null === $data['document'], 'Compact GET unexpectedly includes full document.' );
    $by_path = array_column( $data['layout']['outline'], null, 'path' );
    $map = array_column( $data['text_map'], null, 'path' );
    foreach ( $fixture['text_map'] as $old ) {
        $check( isset( $by_path[ $old['path'] ] ) && $old['text'] === $by_path[ $old['path'] ]['text'], 'Existing outline entry changed: ' . $old['path'] );
    }
    // Explicit fixture storage map, independent of bridge schema helpers.
    $modules = array(
        array( '7.0.0.1', 'n1jkxtg24w9y', 'faqs', 'question', 'answer', 7 ),
        array( '4.0.0.1.0.0', 'him7o8z0yga3', 'list_items', null, null, 4 ),
        array( '4.0.0.1.1.0', 'lq8aopn2sedc', 'list_items', null, null, 5 ),
        array( '11.0.0.0.1.1', 'h84f6s3zan2b', 'list_items', null, null, 6 ),
        array( '3.0.0.0', 'nsqi3e9c7kjm', 'slides', 'title', 'text', 3 ),
    );
    $expected = json_decode( wp_json_encode( $fixture['document'] ), true );
    $updates = array();
    foreach ( $modules as $m ) {
        list( $path, $node_id, $collection, $label, $body, $count ) = $m;
        $items = $fixture['document'][ $node_id ]['settings'][ $collection ];
        $check( $count === count( $items ), 'Unexpected fixture count.' );
        foreach ( $items as $i => $item ) {
            $item_path = $path . '@' . $i;
            $old_label = null === $label ? $item : $item[ $label ];
            $check( wp_strip_all_tags( $old_label ) === $by_path[ $item_path ]['text'], 'Missing item label: ' . $item_path );
            $new_label = 'Prüfung & Qualität ' . $item_path;
            $updates[] = array( 'path' => $item_path, 'text' => '<b>' . $new_label . '</b>' );
            if ( null === $label ) {
                $expected[ $node_id ]['settings'][ $collection ][ $i ] = $new_label;
                $check( ! isset( $by_path[ $item_path ]['content'] ), 'String item should not expose an answer.' );
            } else {
                $expected[ $node_id ]['settings'][ $collection ][ $i ][ $label ] = $new_label;
            }
            if ( null !== $body ) {
                $check( $item[ $body ] === $by_path[ $item_path ]['content'], 'Missing rich item body.' );
                $check( $item[ $body ] === $map[ $item_path ]['content'], 'text_map must retain item body.' );
                $html = '<p>Antwort ' . $item_path . ' <strong>Größe</strong> <a href="https://example.com/">Link</a></p><ul><li>Ein Punkt</li></ul>';
                $updates[] = array( 'path' => $item_path, 'field' => 'body', 'text' => $html );
                $expected[ $node_id ]['settings'][ $collection ][ $i ][ $body ] = $html;
            }
        }
    }
    $hero_id = $fixture['layout']['compact'][0]['children'][0]['children'][0]['children'][0]['children'][0]['children'][0]['node_id'];
    $updates[] = array( 'path' => '0.0.0.0.0.0', 'text' => 'Neue Dienstleistung' );
    $expected[ $hero_id ]['settings']['heading'] = 'Neue Dienstleistung';
    $create = $request( 'POST', '/nova-beaver/v1/pages', array( 'title' => 'NOVA BB286 mapped clone', 'status' => 'draft', 'post_type' => 'page', 'source_page_id' => $source, 'keep_source_content' => true, 'text_updates' => $updates ) );
    $created = $create->get_data();
    if ( ! empty( $created['id'] ) ) { $ids[] = (int) $created['id']; }
    $check( $create->get_status() < 300 && ! empty( $created['id'] ), 'Clone create failed: ' . wp_json_encode( $created ) );
    $clone = (int) $created['id'];
    $check( 158 === $created['node_count'] && true === $created['beaver']['bb_active'], 'Builder clone response invalid.' );
    $actual = get_post_meta( $clone, '_fl_builder_data', true );
    $check( $settings_list( $expected ) === $settings_list( $actual ), 'Clone changed unrelated settings, lost a paired update, or used the wrong native field.' );
    $check( empty( array_intersect( array_keys( $original ), array_keys( $actual ) ) ), 'Clone reused source node IDs.' );
    $check( 'draft' === get_post_status( $clone ), 'Test clone must remain draft.' );
    $check( $source_snapshot === serialize( get_post_meta( $source, '_fl_builder_data', true ) ), 'Source was mutated.' );
    $get = $request( 'GET', '/nova-beaver/v1/pages/' . $clone, null, array( 'text_map' => true ) )->get_data();
    $readback = array_column( $get['layout']['outline'], null, 'path' );
    foreach ( $updates as $u ) {
        $key = isset( $u['field'] ) ? 'content' : 'text';
        $value = 'content' === $key ? $u['text'] : wp_strip_all_tags( $u['text'] );
        $check( $value === $readback[ $u['path'] ][ $key ], 'Readback differs: ' . $u['path'] . ' ' . $key );
    }
    $fallback = get_post_field( 'post_content', $clone );
    $check( false !== strpos( $fallback, 'Antwort 7.0.0.1@0' ) && false !== strpos( $fallback, '<strong>Größe</strong>' ), 'FAQ fallback HTML missing.' );
    $check( false !== strpos( $fallback, 'Antwort 3.0.0.0@0' ) && false !== strpos( $fallback, '<li>Prüfung &amp; Qualität 4.0.0.1.0.0@0</li>' ), 'Slider/list fallback missing.' );
    if ( ! empty( $args[1] ) ) {
        file_put_contents( $args[1] . '/staging-get-example.json', wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        file_put_contents( $args[1] . '/staging-clone-readback.json', wp_json_encode( $get, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
    }
    // PATCH pairs, aliases, sanitization, removal ordering, and unchanged native settings.
    $before_patch = $actual;
    $patch_expected = $settings_list( $before_patch );
    $patch_updates = array(
        array( 'path' => '7.0.0.1@1', 'text' => 'Frage nach Entfernung' ),
        array( 'path' => '7.0.0.1@1', 'field' => 'answer', 'text' => '<p onclick="bad()">Sichere <em>Antwort</em></p><script>bad()</script>' ),
        array( 'path' => '3.0.0.0@1', 'field' => 'title', 'text' => 'Neue Folie' ),
        array( 'path' => '3.0.0.0@1', 'field' => 'content', 'text' => '<p>Neuer Folientext</p>' ),
        array( 'path' => '4.0.0.1.0.0@1', 'text' => 'Neuer Vorteil' ),
        array( 'path' => '7.0.0.1@999', 'text' => 'Do not create this item' ),
    );
    $remove = array( '7.0.0.1@0', '3.0.0.0@0', '4.0.0.1.0.0@0', '4.0.0.1.1.0@4', '11.0.0.0.1.1@5' );
    $patch = $request( 'PATCH', '/nova-beaver/v1/pages/' . $clone, array( 'text_updates' => $patch_updates, 'remove_paths' => $remove ) );
    $check( $patch->get_status() < 300, 'PATCH failed.' );
    $after_patch = get_post_meta( $clone, '_fl_builder_data', true );
    $check( array_keys( $before_patch ) === array_keys( $after_patch ), 'PATCH changed node IDs.' );
    $patched = array_column( $request( 'GET', '/nova-beaver/v1/pages/' . $clone )->get_data()['layout']['outline'], null, 'path' );
    $check( 78 === count( $patched ), 'Removal count or invalid-index behavior incorrect.' );
    $check( 'Frage nach Entfernung' === $patched['7.0.0.1@0']['text'], 'FAQ update/removal order failed.' );
    $safe = wp_kses_post( $patch_updates[1]['text'] );
    $check( $safe === $patched['7.0.0.1@0']['content'] && false === strpos( $safe, 'onclick' ) && false === strpos( $safe, '<script' ), 'FAQ native answer alias not sanitized as HTML.' );
    $check( 'Neue Folie' === $patched['3.0.0.0@0']['text'] && '<p>Neuer Folientext</p>' === $patched['3.0.0.0@0']['content'], 'Slide pair failed.' );
    $check( 'Neuer Vorteil' === $patched['4.0.0.1.0.0@0']['text'], 'String list update/removal failed.' );
    $check( ! metadata_exists( 'post', $clone, '_fl_builder_draft' ), 'Builder-session draft should not linger.' );

    // Existing accordion/tabs behavior plus two fields on one ordinary module.
    $tree = array();
    foreach ( array( 'accordion', 'tabs' ) as $module ) {
        $tree[] = array( 'type' => 'module', 'settings' => (object) array( 'type' => $module, 'items' => array( (object) array( 'label' => 'Old label', 'content' => '<p>Old body</p>', 'custom' => 'preserve' ) ) ), 'children' => array() );
    }
    $tree[] = array( 'type' => 'module', 'settings' => (object) array( 'type' => 'callout', 'title' => 'Old title', 'text' => '<p>Old text</p>' ), 'children' => array() );
    $legacy = $new_post( 'legacy', nova_bb_tree_to_flat( $tree ) );
    $legacy_updates = array();
    foreach ( array( '0@0', '1@0', '2' ) as $path ) {
        $legacy_updates[] = array( 'path' => $path, 'text' => 'New title ' . $path );
        $legacy_updates[] = array( 'path' => $path, 'field' => 'body', 'text' => '<p>New body ' . $path . '</p>' );
    }
    $check( $request( 'PATCH', '/nova-beaver/v1/pages/' . $legacy, array( 'text_updates' => $legacy_updates ) )->get_status() < 300, 'Legacy PATCH failed.' );
    $legacy_flat = array_values( get_post_meta( $legacy, '_fl_builder_data', true ) );
    foreach ( array( 0, 1 ) as $i ) {
        $item = $legacy_flat[ $i ]->settings->items[0];
        $check( 'New title ' . $i . '@0' === $item->label && '<p>New body ' . $i . '@0</p>' === $item->content && 'preserve' === $item->custom, 'Legacy repeater regression.' );
    }
    $check( 'New title 2' === $legacy_flat[2]->settings->title && '<p>New body 2</p>' === $legacy_flat[2]->settings->text, 'Multiple ordinary-node updates lost.' );
    $legacy_outline = $request( 'GET', '/nova-beaver/v1/pages/' . $legacy )->get_data()['layout']['outline'];
    $check( 'New title 0@0' === $legacy_outline[0]['text'] && '<p>New body 0@0</p>' === $legacy_outline[0]['content'], 'Legacy GET body missing.' );
    $check( $request( 'PATCH', '/nova-beaver/v1/pages/' . $legacy, array( 'remove_paths' => array( '0@0', '1@0' ) ) )->get_status() < 300, 'Legacy removal failed.' );
    $legacy_flat = array_values( get_post_meta( $legacy, '_fl_builder_data', true ) );
    $check( array() === $legacy_flat[0]->settings->items && array() === $legacy_flat[1]->settings->items, 'Legacy removal left entries.' );
    wp_set_current_user( 0 );
    $check( $request( 'GET', '/nova-beaver/v1/pages/' . $clone )->get_status() >= 400, 'Anonymous GET allowed.' );
    $check( $request( 'PATCH', '/nova-beaver/v1/pages/' . $clone, array( 'title' => 'Unauthorized' ) )->get_status() >= 400, 'Anonymous PATCH allowed.' );
    wp_set_current_user( (int) $admin[0] );
    echo wp_json_encode( array( 'ok' => true, 'checks' => $checks, 'version' => NOVA_BRIDGE_SUITE_VERSION, 'source_nodes' => 158, 'outline_entries' => 83, 'native_renderer_available' => array_keys( FLBuilderModel::$modules ) ), JSON_PRETTY_PRINT ) . "\n";
} finally {
    wp_set_current_user( (int) $admin[0] );
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
    echo 'Cleaned temporary posts: ' . implode( ', ', $ids ) . "\n";
}
