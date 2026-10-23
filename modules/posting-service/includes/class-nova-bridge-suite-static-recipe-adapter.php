<?php
/** Explicit stock-field compatibility for reviewed, scalar WooCommerce category recipes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Static_Recipe_Adapter {
    public const MODE = 'static_recipe_v1';
    private const PREFIX = 'nova_static_recipe_v1_';
    public static function error( string $code, string $message ): WP_Error { return new WP_Error( 'nova_static_' . $code, $message ); }
    public static function digest( array $value ): string { return hash( 'sha256', Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ) ); }
    private static function key( string $kind, string $identity ): string { return self::PREFIX . $kind . '_' . hash( 'sha256', $identity ); }
    private static function frozen( array $record ): array { return array_intersect_key( $record, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); }

    /** Serialize editor saves and retries. A lost PUT acknowledgement must be reconciled first. */
    public static function sync_editor( array $connection, callable $load, bool $saving = false, $client = null, $locks = null ) {
        if ( ! current_user_can( 'manage_options' ) || empty( $connection['enabled'] ) ) { return self::error( 'permission', 'Enable the posting connection and sign in as an administrator before synchronizing.' ); }
        $client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $invalid = $client->connection_error(); if ( is_wp_error( $invalid ) ) { return $invalid; }
        $key = self::key( 'editor', $client->site_id() );
        $locks = $locks ?: new Nova_Bridge_Suite_Posting_Jobs(); $lock = 'static-editor:' . $key;
        $owned = $locks->lock( $lock ); if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $pending = get_option( $key, null );
            if ( $saving && null !== $pending ) { return self::error( 'sync_pending', 'Use Sync saved revision to finish the previous posting-service update before saving another revision.' ); }
            $saved = $load(); if ( is_wp_error( $saved ) ) { return $saved; }
            try { $result = self::sync_editor_record( $client, $key, $saved, $pending ); }
            catch ( Throwable $error ) { $result = self::error( 'sync_interrupted', 'The synchronization could not be confirmed. Use Sync saved revision to reconcile it.' ); }
            $saved['posting_sync'] = is_wp_error( $result ) ? [ 'status' => 'failed', 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] : $result;
            $saved['local_only'] = is_wp_error( $result );
            return $saved;
        } finally { $locks->unlock( $lock ); }
    }

    private static function editor_remote( $reply, string $id, bool $creating = false ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $remote = $reply['body'] ?? null;
        if ( ! in_array( $reply['status'] ?? null, $creating ? [ 200, 201 ] : [ 200 ], true ) || ! is_array( $remote ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) || ( $remote['id'] ?? null ) !== $id || ! is_int( $remote['revision'] ?? null ) || $remote['revision'] < 1 || ( $remote['enabled'] ?? null ) !== true || ( $remote['retired_at'] ?? null ) !== null || ( $remote['page_type'] ?? null ) !== 'category' || ( $reply['etag'] ?? '' ) !== '"' . $remote['revision'] . '"' ) { return self::error( 'template_identity', 'The service did not return the enabled category template and its exact revision ETag.' ); }
        return $remote;
    }

    /** Create the first category default, or update the existing locally approved template. */
    private static function sync_editor_record( $client, string $key, array $saved, $pending ) {
        $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $saved['id'], $saved['revision'] ); if ( is_wp_error( $record ) ) { return $record; }
        $default = Nova_Bridge_Suite_Layout_Recipes::defaults()['taxonomy:product_cat'] ?? [];
        if ( ( $default['id'] ?? null ) !== $record['id'] || ( $default['revision'] ?? null ) !== $record['revision'] ) { return self::error( 'default_changed', 'Reload the current category creation default before synchronizing.' ); }
        $projection = self::project_record( $record ); if ( is_wp_error( $projection ) ) { return $projection; }
        $reply = $client->site_request( 'GET', '/template-defaults' ); if ( is_wp_error( $reply ) ) { return $reply; }
        $defaults = self::editor_defaults( $reply ); if ( is_wp_error( $defaults ) ) { return $defaults; }
        $id = $reply['body']['defaults']['category'] ?? null;
        if ( ( null === $id && null === $pending ) || ( $pending['operation'] ?? '' ) === 'create' ) { return self::create_editor_default( $client, $key, $record, $projection, $pending, $reply ); }
        if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) ) { return self::error( 'sync_conflict', 'The category default changed while an update was pending.' ); }
        $remote = self::editor_remote( $client->site_request( 'GET', '/templates/' . $id ), $id ); if ( is_wp_error( $remote ) ) { return $remote; }
        if ( null === $pending ) {
            $prior = get_option( self::key( 'profile', $client->site_id() . ':' . $id . ':' . $remote['revision'] ), null );
            $unsigned = is_array( $prior ) ? $prior : []; unset( $unsigned['profile_digest'] );
            // Reuse only this site's retained authority, never a profile from another registration.
            if ( ! is_array( $prior ) || ( $prior['mode'] ?? '' ) !== self::MODE || ! hash_equals( self::digest( $unsigned ), (string) ( $prior['profile_digest'] ?? '' ) ) || ( $prior['site_id'] ?? '' ) !== $client->site_id() || ( $prior['origin'] ?? '' ) !== rtrim( home_url( '/' ), '/' ) || ( ( $prior['scope'] ?? '' ) !== 'site_credential' && ( ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $prior['client_id'] ?? null ) || ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $prior['domain_id'] ?? null ) ) ) || self::digest( $prior['template'] ?? [] ) !== self::digest( self::frozen( $remote ) ) ) { return self::error( 'profile_scope', 'The current remote category revision has no matching approved local authority. Review the site setup before synchronizing.' ); }
            if ( self::digest( $prior['record'] ) === self::digest( $record ) ) { return self::editor_summary( $record, $remote ); }
            $profile = array_intersect_key( $prior, array_flip( [ 'mode', 'site_id', 'client_id', 'domain_id', 'scope', 'origin' ] ) ) + [ 'record' => $record, 'projection' => $projection ];
            $pending = [ 'profile' => $profile, 'base_template' => self::frozen( $remote ) ];
            if ( ! add_option( $key, $pending, '', false ) ) { return self::error( 'sync_storage', 'Could not retain the template update before contacting posting service.' ); }
        }
        $profile = $pending['profile'] ?? []; $base = $pending['base_template'] ?? [];
        if ( ( $profile['site_id'] ?? '' ) !== $client->site_id() || ( $profile['origin'] ?? '' ) !== rtrim( home_url( '/' ), '/' ) || self::digest( $profile['record'] ?? [] ) !== self::digest( $record ) || self::digest( $profile['projection'] ?? [] ) !== self::digest( $projection ) || ( $base['id'] ?? '' ) !== $id || ! is_int( $base['revision'] ?? null ) ) { return self::error( 'sync_conflict', 'The pending update belongs to another revision or category default. No overwrite was attempted.' ); }
        if ( $remote['revision'] === $base['revision'] && self::digest( self::frozen( $remote ) ) === self::digest( $base ) ) {
            // Even unchanged scalar fields need a new remote revision for the new native recipe authority.
            $remote = self::editor_remote( $client->site_request( 'PUT', '/templates/' . $id, $projection['input'], [ 'If-Match' => '"' . $base['revision'] . '"' ] ), $id );
            if ( is_wp_error( $remote ) ) { return $remote; }
        }
        if ( $remote['revision'] !== $base['revision'] + 1 ) { return self::error( 'sync_conflict', 'The remote template changed concurrently. No further overwrite was attempted.' ); }
        foreach ( $projection['input'] as $name => $value ) { if ( self::digest( [ $remote[$name] ?? null ] ) !== self::digest( [ $value ] ) ) { return self::error( 'template_projection', 'The remote revision differs from this saved category mapping.' ); } }
        $profile['template'] = self::frozen( $remote ); $profile['profile_digest'] = self::digest( $profile );
        $approved = self::key( 'profile', $client->site_id() . ':' . $id . ':' . $remote['revision'] );
        if ( ! add_option( $approved, $profile, '', false ) && get_option( $approved, null ) !== $profile ) { return self::error( 'profile_conflict', 'This remote revision already has a different immutable local authority.' ); }
        delete_option( $key );
        if ( null !== get_option( $key, null ) ) { return self::error( 'sync_storage', 'The mapping was approved, but its pending update could not be cleared. Retry synchronization.' ); }
        return self::editor_summary( $record, $remote );
    }

    private static function editor_summary( array $record, array $remote ): array {
        return [ 'status' => 'synced', 'recipe_revision' => $record['revision'], 'template_id' => $remote['id'], 'template_revision' => $remote['revision'] ];
    }

    private static function editor_defaults( $reply ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $body = $reply['body'] ?? [];
        if ( ( $reply['status'] ?? null ) !== 200 || ! is_array( $body['defaults'] ?? null ) || ! is_int( $body['revision'] ?? null ) || $body['revision'] < 1 || ( $reply['etag'] ?? '' ) !== '"' . $body['revision'] . '"' ) { return self::error( 'defaults_identity', 'The service did not return category defaults with an exact revision ETag.' ); }
        foreach ( $body['defaults'] as $id ) { if ( ! Nova_Bridge_Suite_Posting_Protocol::uuid( $id ) ) { return self::error( 'defaults_identity', 'The service returned an invalid template default.' ); } }
        return $body['defaults'];
    }

    /** The site credential authorizes first-time setup without private backoffice client/domain IDs. */
    private static function create_editor_default( $client, string $key, array $record, array $projection, $pending, array $defaults_reply ) {
        $fresh = null === $pending;
        if ( $fresh ) {
            $pending = [ 'operation' => 'create', 'profile' => [ 'mode' => self::MODE, 'scope' => 'site_credential', 'site_id' => $client->site_id(), 'origin' => rtrim( home_url( '/' ), '/' ), 'record' => $record, 'projection' => $projection ] ];
            if ( ! add_option( $key, $pending, '', false ) ) { return self::error( 'sync_storage', 'Could not retain the first template creation before contacting posting service.' ); }
        }
        $profile = $pending['profile'] ?? [];
        if ( ( $profile['site_id'] ?? '' ) !== $client->site_id() || ( $profile['origin'] ?? '' ) !== rtrim( home_url( '/' ), '/' ) || self::digest( $profile['record'] ?? [] ) !== self::digest( $record ) || self::digest( $profile['projection'] ?? [] ) !== self::digest( $projection ) ) { return self::error( 'sync_conflict', 'The pending creation belongs to another site or saved revision.' ); }
        $id = $pending['template_id'] ?? null;
        if ( ! $fresh && ! $id ) { return self::error( 'create_uncertain', 'A template create was attempted but its UUID could not be confirmed. Reconcile it before retrying; no duplicate was created.' ); }
        $reply = $id ? $client->site_request( 'GET', '/templates/' . $id ) : $client->site_request( 'POST', '/templates', $projection['input'] );
        if ( is_wp_error( $reply ) ) { return $reply; }
        $remote = self::editor_remote( $reply, $id ?: ( $reply['body']['id'] ?? '' ), $fresh ); if ( is_wp_error( $remote ) ) { return $remote; }
        foreach ( $projection['input'] as $name => $value ) { if ( self::digest( [ $remote[$name] ?? null ] ) !== self::digest( [ $value ] ) ) { return self::error( 'template_projection', 'The created template differs from this saved category mapping.' ); } }
        $pending['template_id'] = $remote['id']; update_option( $key, $pending, false );
        if ( get_option( $key, null ) !== $pending ) { return self::error( 'sync_storage', 'Could not retain the created template identity.' ); }
        $profile['template'] = self::frozen( $remote ); $profile['profile_digest'] = self::digest( $profile );
        $approved = self::key( 'profile', $client->site_id() . ':' . $remote['id'] . ':' . $remote['revision'] );
        if ( ! add_option( $approved, $profile, '', false ) && get_option( $approved, null ) !== $profile ) { return self::error( 'profile_conflict', 'This remote revision already has a different immutable local authority.' ); }
        // Approve locally before selecting the default: new deliveries can arrive immediately afterwards.
        $defaults = $defaults_reply['body']['defaults']; $selected = $defaults['category'] ?? null;
        if ( null !== $selected && $selected !== $remote['id'] ) { return self::error( 'sync_conflict', 'Another category default was selected. No overwrite was attempted.' ); }
        if ( null === $selected ) {
            $defaults['category'] = $remote['id'];
            $confirmed = self::editor_defaults( $client->site_request( 'PUT', '/template-defaults', [ 'defaults' => $defaults ], [ 'If-Match' => $defaults_reply['etag'] ] ) );
            if ( is_wp_error( $confirmed ) ) { return $confirmed; }
            if ( self::digest( $confirmed ) !== self::digest( $defaults ) ) { return self::error( 'sync_conflict', 'The service did not confirm the selected category default.' ); }
        }
        delete_option( $key );
        if ( null !== get_option( $key, null ) ) { return self::error( 'sync_storage', 'The mapping is ready, but its pending creation could not be cleared. Retry synchronization.' ); }
        return self::editor_summary( $record, $remote );
    }

    /** The UI stores semantic role IDs independently of editable labels and source names. */
    public static function project_record( array $record, string $page_type = 'category' ) {
        $recipe = $record['recipe'] ?? [];
        if ( ( $record['resource_id'] ?? '' ) !== 'taxonomy:product_cat' || ( $record['builder'] ?? '' ) !== 'core' || ( $record['mode'] ?? '' ) !== 'static' || ( $recipe['mode'] ?? '' ) !== 'static' || ( $recipe['builder'] ?? '' ) !== 'core' || ! empty( $recipe['regions'] ) || isset( $recipe['custom_fields'] ) ) { return self::error( 'unsupported_recipe', 'This compatibility adapter requires a static core product-category template without repeat regions or ACF companions.' ); }
        if ( ! preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $page_type ) ) { return self::error( 'page_type', 'Supply an explicit NOVA page type.' ); }
        $sources = []; $fields = []; $mapping = [];
        foreach ( $recipe['bindings'] ?? [] as $binding ) {
            if ( ! preg_match( '/^nova_role_(title|top_content|bottom_content)_[a-f0-9]{16}$/D', $binding['id'] ?? '', $match ) || ! is_string( $binding['source'] ?? null ) || ! is_bool( $binding['required'] ?? null ) ) { return self::error( 'unsupported_role', 'Only explicit title, top category content and bottom category content roles are supported. Custom and ambiguous roles require another adapter.' ); }
            $role = $match[1]; $target = $binding['target'] ?? ''; $source = 'title' === $role ? 'h1' : $role;
            if ( isset( $sources[$source] ) || ( 'title' === $role && ( '/document/title' !== $target || 'text' !== ( $binding['format'] ?? '' ) ) ) || ( 'top_content' === $role && ( '/document/sections/0/body' !== $target || 'html' !== ( $binding['format'] ?? '' ) ) ) ) { return self::error( 'ambiguous_role', 'Each supported stock source must identify one compatible native category field.' ); }
            if ( 'bottom_content' === $role ) {
                $parts = explode( '/', $target ); $declaration = $recipe['blueprint']['term_fields']['declarations'][$parts[3] ?? ''] ?? [];
                if ( count( $parts ) !== 4 || array_slice( $parts, 0, 3 ) !== [ '', 'term_fields', 'values' ] || ( $declaration['key'] ?? '' ) !== 'content_below_products' || ( $declaration['transport'] ?? '' ) !== 'rest' || ( $declaration['rest_key'] ?? '' ) !== 'content_below_products' || ( $declaration['schema']['type'] ?? '' ) !== 'string' || 'html' !== ( $binding['format'] ?? '' ) ) { return self::error( 'bottom_field', 'Bottom content must identify the declared scalar content_below_products REST field.' ); }
            }
            $sources[$source] = $binding;
            $fields[] = [ 'id' => $binding['id'], 'label' => $binding['label'] ?? $role, 'kind' => 'title' === $role ? 'text' : 'rich_text', 'required' => $binding['required'] ];
            // A category's visible name comes from h1; stock title is the SEO <title>.
            $mapping[] = [ 'field_id' => $binding['id'], 'source_field' => $source ];
        }
        if ( count( $sources ) !== 3 ) { return self::error( 'incomplete_recipe', 'Review one title, one top content field and one bottom content field before enabling this category adapter.' ); }
        return [ 'input' => [ 'name' => $record['label'] ?? 'WooCommerce category', 'page_type' => $page_type, 'enabled' => true, 'definition' => [ 'version' => 1, 'fields' => $fields ], 'mapping' => [ 'version' => 1, 'fields' => $mapping ] ], 'sources' => $sources ];
    }

    /** Local CLI/admin setup only. Unknown template creates require an explicitly recovered UUID. */
    public static function sync_and_approve( array $connection, string $recipe_id, int $revision, string $client_id, string $domain_id, string $page_type = 'category', ?string $recovered_template_id = null, $client = null, $locks = null ) {
        if ( ! current_user_can( 'manage_options' ) || ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $client_id ) || ! Nova_Bridge_Suite_Posting_Protocol::decimal_id( $domain_id ) ) { return self::error( 'permission', 'An administrator must explicitly approve the client and domain identity.' ); }
        $client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection ); $invalid = $client->connection_error(); if ( is_wp_error( $invalid ) ) { return $invalid; }
        $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $recipe_id, $revision ); if ( is_wp_error( $record ) ) { return $record; }
        $projection = self::project_record( $record, $page_type ); if ( is_wp_error( $projection ) ) { return $projection; }
        $profile = [ 'mode' => self::MODE, 'site_id' => $client->site_id(), 'client_id' => $client_id, 'domain_id' => $domain_id, 'origin' => rtrim( home_url( '/' ), '/' ), 'record' => $record, 'projection' => $projection ];
        $intent = self::digest( $profile ); $key = self::key( 'setup', $client->site_id() . ':' . $recipe_id . ':' . $revision );
        $locks = $locks ?: new Nova_Bridge_Suite_Posting_Jobs(); $lock = 'static-setup:' . $key; $owned = $locks->lock( $lock ); if ( is_wp_error( $owned ) ) { return $owned; }
        try {
            $state = get_option( $key, null );
            if ( is_array( $state ) && ( $state['intent'] ?? '' ) !== $intent ) { return self::error( 'setup_conflict', 'This recipe revision was already prepared for another scope or projection.' ); }
            if ( $recovered_template_id && ! Nova_Bridge_Suite_Posting_Protocol::uuid( $recovered_template_id ) ) { return self::error( 'template_id', 'Supply the actual recovered publishing template UUID.' ); }
            $remote_id = $recovered_template_id ?: ( $state['template_id'] ?? null );
            if ( ! $remote_id && is_array( $state ) ) { return self::error( 'create_uncertain', 'A template create was already attempted. Supply its actual UUID for reconciliation; do not create a duplicate.' ); }
            if ( $remote_id ) { $reply = $client->site_request( 'GET', '/templates/' . $remote_id ); }
            else {
                if ( ! add_option( $key, [ 'intent' => $intent, 'phase' => 'creating' ], '', false ) ) { return self::error( 'setup_storage', 'Could not retain the template creation intent.' ); }
                $reply = $client->site_request( 'POST', '/templates', $projection['input'] );
            }
            if ( is_wp_error( $reply ) ) { return $reply; }
            $remote = $reply['body'] ?? []; $expected = $projection['input'];
            if ( ! is_array( $remote ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $remote['id'] ?? null ) || ! is_int( $remote['revision'] ?? null ) || $remote['revision'] < 1 || ( $remote_id && $remote['id'] !== $remote_id ) || ( $remote['enabled'] ?? null ) !== true || ( $remote['retired_at'] ?? null ) !== null || ( $reply['etag'] ?? '' ) !== '"' . $remote['revision'] . '"' ) { return self::error( 'template_identity', 'The service did not return an enabled exact template revision and matching ETag.' ); }
            foreach ( $expected as $name => $value ) { if ( self::digest( [ $remote[$name] ?? null ] ) !== self::digest( [ $value ] ) ) { return self::error( 'template_projection', 'The remote template differs from the explicitly reviewed scalar projection.' ); } }
            $profile['template'] = self::frozen( $remote ); $profile['profile_digest'] = self::digest( $profile );
            $approved = self::key( 'profile', $client->site_id() . ':' . $remote['id'] . ':' . $remote['revision'] );
            if ( ! add_option( $approved, $profile, '', false ) && get_option( $approved, null ) !== $profile ) { return self::error( 'profile_conflict', 'This remote revision already has a different immutable local authority.' ); }
            update_option( $key, [ 'intent' => $intent, 'phase' => 'approved', 'template_id' => $remote['id'], 'template_revision' => $remote['revision'] ], false );
            return [ 'template_id' => $remote['id'], 'template_revision' => $remote['revision'], 'recipe_id' => $recipe_id, 'recipe_revision' => $revision, 'profile_digest' => $profile['profile_digest'], 'warnings' => [ 'Human instructions remain local. This API delivers the existing h1, top_content and bottom_content fields without generation or adaptation rules.' ] ];
        } finally { $locks->unlock( $lock ); }
    }

    /** Null means another adapter owns this revision; an invalid known profile never falls back. */
    public static function configuration_for_snapshot( array $snapshot, string $site ) {
        $remote = $snapshot['configuration'] ?? null; if ( ! is_array( $remote ) ) { return null; }
        $profile = get_option( self::key( 'profile', $site . ':' . ( $remote['id'] ?? '' ) . ':' . ( $remote['revision'] ?? '' ) ), null );
        if ( null === $profile ) { return null; }
        if ( ! is_array( $profile ) ) { return self::error( 'profile_corrupt', 'The retained static category authority is invalid.' ); }
        $valid = self::validate_profile( $snapshot, $profile ); return is_wp_error( $valid ) ? $valid : $profile;
    }
    public static function validate_profile( array $snapshot, array $profile ) {
        $unsigned = $profile; unset( $unsigned['profile_digest'] );
        // Editor-created profiles trust the authenticated site boundary, as the mapped adapter does.
        // Explicit CLI approvals retain their additional client/domain pins.
        $tenant = ( $profile['scope'] ?? '' ) === 'site_credential'
            ? Nova_Bridge_Suite_Posting_Protocol::decimal_id( $snapshot['client_id'] ?? null ) && Nova_Bridge_Suite_Posting_Protocol::decimal_id( $snapshot['domain_id'] ?? null )
            : ( $profile['client_id'] ?? '' ) === ( $snapshot['client_id'] ?? null ) && ( $profile['domain_id'] ?? '' ) === ( $snapshot['domain_id'] ?? null );
        if ( ( $profile['mode'] ?? '' ) !== self::MODE || ! hash_equals( self::digest( $unsigned ), (string) ( $profile['profile_digest'] ?? '' ) ) || ( $profile['site_id'] ?? '' ) !== ( $snapshot['site_id'] ?? null ) || ! $tenant || ( $profile['origin'] ?? '' ) !== rtrim( home_url( '/' ), '/' ) || ( $snapshot['page_type_frontend'] ?? '' ) !== ( $profile['template']['page_type'] ?? null ) || self::digest( $snapshot['configuration'] ?? [] ) !== self::digest( $profile['template'] ?? [] ) ) { return self::error( 'profile_scope', 'The delivery differs from its approved site, client, domain, page type or frozen template revision.' ); }
        $record = Nova_Bridge_Suite_Layout_Recipes::delivery_record( $profile['record']['id'], $profile['record']['revision'] ); if ( is_wp_error( $record ) ) { return $record; }
        if ( self::digest( $record ) !== self::digest( $profile['record'] ) ) { return self::error( 'recipe_changed', 'The approved immutable local recipe changed.' ); }
        return true;
    }
    public static function policy( array $snapshot, array $profile ) {
        $valid = self::validate_profile( $snapshot, $profile ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ! is_string( $snapshot['url'] ?? null ) ) { return self::error( 'target_url', 'A category delivery requires its planned site URL.' ); }
        $target = Nova_Bridge_Suite_Mapped_Content_Target::creation( 'taxonomy:product_cat', $snapshot['url'], $snapshot['language'] ?? null ); if ( is_wp_error( $target ) ) { return $target; }
        return [ 'reference' => [ 'reference_type' => 'term', 'reference_id' => 0 ], 'routing' => [ 'operation' => 'create', 'publication' => 'publish' ], 'target' => $target ];
    }
}
