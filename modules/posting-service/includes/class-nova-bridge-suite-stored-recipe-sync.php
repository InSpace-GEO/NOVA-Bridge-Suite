<?php
/** Saved Elementor layouts use the existing publishing-template and default APIs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Stored_Recipe_Sync {
    private static function error( string $code, string $message ): WP_Error { return new WP_Error( 'nova_stored_recipe_' . $code, $message, [ 'status' => 409 ] ); }
    private static function same( $a, $b ): bool { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $a ) === Nova_Bridge_Suite_Writing_Adapter::canonical_json( $b ); }
    private static function defaults( $reply ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $body = $reply['body'] ?? [];
        if ( ( $reply['status'] ?? 0 ) !== 200 || ! is_array( $body['defaults'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( $reply['etag'] ?? '' ) !== '"' . $body['revision'] . '"' ) { return self::error( 'defaults', 'Posting service did not return template defaults with their exact revision.' ); }
        foreach ( $body['defaults'] as $id ) { if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) ) { return self::error( 'defaults', 'Posting service returned an invalid template default.' ); } }
        return $body['defaults'];
    }

    public static function sync_editor( array $connection, callable $load, bool $saving = false, string $recipe_id = '', $client = null, $locks = null, string $recover_template_id = '' ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $connection['enabled'] ) ) { return self::error( 'permission', 'Enable the posting connection and sign in as an administrator before synchronizing.' ); }
        $client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $invalid = $client->connection_error(); if ( is_wp_error( $invalid ) ) { return $invalid; }
        $locks = $locks ?: new Nova_Bridge_Suite_Posting_Jobs(); $lock = 'stored-recipe-editor:' . $client->site_id();
        $owned = $locks->lock( $lock ); if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $previous = $recipe_id ? Nova_Bridge_Suite_Mapping_Sync::scope_state( $client->site_id(), 'recipe:' . $recipe_id ) : null;
            if ( $saving && isset( $previous['pending'] ) ) { return self::error( 'sync_pending', 'Sync the saved revision to reconcile its pending remote operation before saving another revision.' ); }
            $saved = $load(); if ( is_wp_error( $saved ) ) { return $saved; }
            $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $saved['id'], $saved['revision'] );
            $result = is_wp_error( $record ) ? $record : self::synchronize( $connection, $client, $record, $recover_template_id );
            $saved['posting_sync'] = is_wp_error( $result ) ? [ 'status' => 'failed', 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] : $result;
            $saved['local_only'] = is_wp_error( $result );
            return $saved;
        } finally { $locks->unlock( $lock ); }
    }

    private static function synchronize( array $connection, $client, array $record, string $recover_template_id ) {
        $default = Nova_Bridge_Suite_Layout_Recipes::defaults()[$record['resource_id']] ?? [];
        if ( ( $default['id'] ?? '' ) !== $record['id'] || ( $default['revision'] ?? 0 ) !== $record['revision'] ) { return self::error( 'default_changed', 'Reload the current saved creation default before synchronizing.' ); }
        $draft = Nova_Bridge_Suite_Stored_Layout::from_recipe( $record ); if ( is_wp_error( $draft ) ) { return $draft; }
        $page_type = $draft['profile_page_type'];
        $reply = $client->site_request( 'GET', '/template-defaults' ); $defaults = self::defaults( $reply ); if ( is_wp_error( $defaults ) ) { return $defaults; }
        $scope = 'recipe:' . $record['id']; $previous = Nova_Bridge_Suite_Mapping_Sync::scope_state( $client->site_id(), $scope );
        if ( isset( $previous['template']['page_type'] ) && $previous['template']['page_type'] !== $page_type ) { return self::error( 'page_type_changed', 'Create a separate template for another NOVA content type. The synchronized template keeps its existing default and page type.' ); }
        $selected = $defaults[$page_type] ?? null;
        if ( $selected && $selected !== ( $previous['template']['id'] ?? null ) ) { return self::error( 'default_conflict', 'This NOVA content type already selects another template. Review that default before replacing it; no remote template was changed.' ); }
        $sync = new Nova_Bridge_Suite_Mapping_Sync( $client, $scope );
        $state = $sync->synchronize( $draft, $recover_template_id ); if ( is_wp_error( $state ) ) { return $state; }
        $state = $sync->activate( $draft, [ 'actor_user_id' => (int) ( $connection['actor_user_id'] ?? 0 ) ] ); if ( is_wp_error( $state ) ) { return $state; }
        // Approve native authority before selecting a default which can receive deliveries immediately.
        $id = $state['template']['id'];
        if ( $selected !== $id ) {
            $defaults[$page_type] = $id;
            $confirmed = self::defaults( $client->site_request( 'PUT', '/template-defaults', [ 'defaults' => $defaults ], [ 'If-Match' => $reply['etag'] ] ) );
            if ( is_wp_error( $confirmed ) ) { return $confirmed; }
            if ( ! self::same( $confirmed, $defaults ) ) { return self::error( 'default_conflict', 'Posting service did not confirm the selected creation template.' ); }
        }
        return [ 'status' => 'synced', 'verified' => true, 'template_id' => $id, 'template_revision' => $state['template']['revision'], 'local_revision' => $record['revision'], 'recipe_revision' => $record['revision'], 'mapping_version' => $state['template']['definition']['version'], 'page_type' => $page_type, 'message' => 'The saved layout and field instructions are ready for new-page delivery without reading the example page.' ];
    }
}
