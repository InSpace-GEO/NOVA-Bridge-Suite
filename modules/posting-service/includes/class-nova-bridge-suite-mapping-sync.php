<?php
/** Publishing-template setup; native profiles and human instructions remain in WordPress. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Mapping_Sync {
    public const CONTRACT = 'nova-publishing-profile/v1';
    private $client;
    private $lock_name;
    private $lock_value;
    private $state_name;
    private $state;
    public function __construct( Nova_Bridge_Suite_Posting_Client $client ) { $this->client = $client; }

    public static function bootstrap(): void {
        add_filter( 'nova_bridge_mapping_catalog', [ __CLASS__, 'catalog' ] );
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ], 1003 );
    }
    public static function register_routes(): void {
        foreach ( [ 'sync' => 'POST', 'sync-state' => 'GET', 'activate' => 'POST', 'url-binding' => [ 'GET', 'POST' ], 'template-default' => [ 'GET', 'POST' ] ] as $route => $methods ) {
            register_rest_route( 'nova-bridge/v1', '/mapping/' . $route, [ 'methods' => $methods, 'callback' => [ __CLASS__, str_replace( '-', '_', $route ) . '_response' ], 'permission_callback' => [ 'Nova_Bridge_Suite_Mapping_Drafts', 'can_admin' ] ] );
        }
    }
    private static function connection(): array { return class_exists( 'Nova_Bridge_Suite_Posting_Settings' ) ? Nova_Bridge_Suite_Posting_Settings::connection() : []; }
    private static function error( string $code, string $message, int $status = 409 ): WP_Error { return Nova_Bridge_Suite_Writing_Adapter::error( $code, $message, $status ); }
    private static function json( $value ): string { return Nova_Bridge_Suite_Writing_Adapter::canonical_json( $value ); }
    private static function key( string $site, string $type, int $id ): string { return 'nova_mapping_sync_' . hash( 'sha256', $site . ':' . $type . ':' . $id ); }
    private static function profile_key( string $site, string $id, int $revision ): string { return 'nova_publishing_profile_' . hash( 'sha256', $site . ':' . $id . ':' . $revision ); }
    public static function state( string $type, int $id, string $site ): ?array { $state = get_option( self::key( $site, $type, $id ), null ); return is_array( $state ) ? $state : null; }
    private static function response( array $data ) { $response = rest_ensure_response( $data ); $response->header( 'Cache-Control', 'private, no-store' ); return $response; }
    private static function permission( $request ) {
        $permission = Nova_Bridge_Suite_Mapping_Drafts::can_admin(); if ( is_wp_error( $permission ) ) { return $permission; }
        return wp_verify_nonce( (string) $request->get_header( 'x-wp-nonce' ), 'wp_rest' ) ? true : self::error( 'nonce', 'A current WordPress REST nonce is required.', 403 );
    }
    public static function catalog( $previous ): array {
        $local = [ 'templates' => [ Nova_Bridge_Suite_Writing_Adapter::stock_catalog() ], 'api_available' => false, 'message' => 'The twelve delivery sources come from the pinned NOVA contract. Prepare mappings and human instructions locally; publishing-template API access is currently unavailable.' ];
        $connection = self::connection(); if ( empty( $connection['enabled'] ) ) { return $local; }
        $client = new Nova_Bridge_Suite_Posting_Client( $connection ); $reply = $client->site_request( 'GET', '/templates' );
        if ( is_wp_error( $reply ) || 200 !== (int) $reply['status'] || ! is_array( $reply['body'] ) || count( $reply['body'] ) > 99 ) { return $local; }
        $templates = [ Nova_Bridge_Suite_Writing_Adapter::stock_catalog() ];
        foreach ( $reply['body'] as $record ) {
            if ( ! Nova_Bridge_Suite_Writing_Adapter::template_record( $record ) ) { return $local; }
            if ( null === $record['retired_at'] ) { $templates[] = Nova_Bridge_Suite_Writing_Adapter::catalog_template( $record ); }
        }
        return [ 'templates' => $templates, 'api_available' => true, 'message' => 'Current delivery sources and site publishing templates loaded. Human instructions remain local; this API does not apply them during generation.' ];
    }
    private static function summary( ?array $state ): array {
        if ( ! $state ) { return [ 'status' => 'not_synced' ]; }
        if ( self::CONTRACT !== ( $state['contract'] ?? '' ) ) { return [ 'status' => 'legacy_retained', 'local_revision' => $state['local_revision'] ?? '', 'warnings' => [ 'Historical writing configuration retained. Select the current delivery catalog and explicitly review this profile before synchronization.' ] ]; }
        $result = array_intersect_key( $state, array_flip( [ 'status', 'local_revision', 'site_id', 'updated_at', 'error', 'warnings', 'instructions_sync' ] ) );
        if ( isset( $state['pending'] ) ) { $result['pending'] = [ 'name' => $state['pending']['method'] === 'POST' ? 'create_template' : 'update_template', 'requires_template_id' => 'POST' === $state['pending']['method'] ]; }
        if ( isset( $state['template'] ) ) { $result['template'] = [ 'id' => $state['template']['id'], 'revision' => $state['template']['revision'], 'etag' => '"' . $state['template']['revision'] . '"' ]; }
        return $result;
    }
    public static function sync_state_response( $request ) {
        $permission = self::permission( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $type = $request->get_param( 'reference_type' ); $id = $request->get_param( 'reference_id' );
        if ( ! in_array( $type, [ 'post', 'term' ], true ) || ! ctype_digit( (string) $id ) || (int) $id < 1 ) { return self::error( 'reference', 'Supply a concrete reference.', 400 ); }
        $connection = self::connection(); $state = self::state( $type, (int) $id, $connection['site_id'] ?? '' );
        return self::response( [ 'state' => self::summary( $state ), 'warnings' => $state['warnings'] ?? [], 'url_binding' => get_option( self::key( $connection['site_id'] ?? '', $type, (int) $id ) . '_page_setting', null ) ] );
    }
    public static function sync_response( $request ) { return self::run_response( $request, false ); }
    public static function activate_response( $request ) { return self::run_response( $request, true ); }
    private static function run_response( $request, bool $activate ) {
        $permission = self::permission( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = self::connection(); if ( empty( $connection['enabled'] ) ) { return self::error( 'connection_disabled', 'Enable the site connection before publishing-template setup.', 503 ); }
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || ! is_string( $input['expected_revision'] ?? null ) ) { return self::error( 'revision', 'Supply the saved local draft revision.', 400 ); }
        $read = new WP_REST_Request( 'GET' ); foreach ( [ 'reference_type', 'reference_id', 'signature' ] as $key ) { $read->set_param( $key, $input[ $key ] ?? null ); }
        $reply = Nova_Bridge_Suite_Mapping_Drafts::get_response( $read ); if ( is_wp_error( $reply ) ) { return $reply; }
        $data = $reply->get_data(); $draft = $data['draft'];
        if ( ! $draft || $draft['revision'] !== $input['expected_revision'] || $draft['signature'] !== $data['reference']['signature'] ) { return self::error( 'revision', 'Save and reconcile this local draft before synchronization.' ); }
        $valid = self::validate_live( $draft ); if ( is_wp_error( $valid ) ) { return $valid; }
        $service = new self( new Nova_Bridge_Suite_Posting_Client( $connection ) );
        $result = $activate ? $service->activate( $draft, [ 'actor_user_id' => (int) ( $connection['actor_user_id'] ?? 0 ) ] ) : $service->synchronize( $draft, $input['recover_template_id'] ?? '' );
        return is_wp_error( $result ) ? $result : self::response( [ 'state' => self::summary( $result ), 'warnings' => $result['warnings'] ?? [] ] );
    }

    private static function frozen_remote( array $record ): array { return array_intersect_key( $record, array_flip( [ 'id', 'name', 'page_type', 'definition', 'mapping', 'revision' ] ) ); }
    private static function matches( array $record, array $input ): bool {
        foreach ( $input as $key => $value ) { if ( ! array_key_exists( $key, $record ) || self::json( $value ) !== self::json( $record[ $key ] ) ) { return false; } }
        return true;
    }
    private static function remote_record( $reply, ?string $id = null ) {
        if ( is_wp_error( $reply ) ) { return $reply; }
        $record = $reply['body'] ?? null;
        if ( ! in_array( (int) ( $reply['status'] ?? 0 ), [ 200, 201 ], true ) || ! Nova_Bridge_Suite_Writing_Adapter::template_record( $record ) || ( null !== $id && $id !== $record['id'] ) || ( $reply['etag'] ?? '' ) !== '"' . $record['revision'] . '"' ) { return self::error( 'template_response', 'NOVA did not return a valid publishing template with its matching revision ETag.', 502 ); }
        return $record;
    }

    /** POST has no backend idempotency key: an unknown create is never blindly replayed. */
    public function synchronize( array $draft, string $recover_template_id = '' ) {
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ); if ( is_wp_error( $input ) ) { return $input; }
        $locked = $this->acquire( $draft ); if ( is_wp_error( $locked ) ) { return $locked; }
        try {
            $old = $this->state;
            if ( $old && self::CONTRACT === ( $old['contract'] ?? '' ) && isset( $old['pending'] ) && ( $old['local_revision'] ?? null ) !== $draft['revision'] ) { return self::error( 'pending_conflict', 'Recover the pending remote operation before changing the synchronized local revision.' ); }
            if ( $old && self::CONTRACT === ( $old['contract'] ?? '' ) && ( $old['local_revision'] ?? null ) === $draft['revision'] && in_array( $old['status'], [ 'synced_draft', 'active' ], true ) ) { return $old; }
            if ( ! $old || self::CONTRACT !== ( $old['contract'] ?? '' ) || ( $old['local_revision'] ?? null ) !== $draft['revision'] || 'rejected' === ( $old['status'] ?? '' ) ) {
                if ( $old && self::CONTRACT !== ( $old['contract'] ?? '' ) ) {
                    $archive = $this->state_name . '_legacy_' . substr( hash( 'sha256', self::json( $old ) ), 0, 20 );
                    if ( ! add_option( $archive, $old, '', false ) && get_option( $archive, null ) !== $old ) { return self::error( 'storage', 'The historical writing configuration could not be preserved.', 500 ); }
                }
                $this->state = [ 'contract' => self::CONTRACT, 'site_id' => $this->client->site_id(), 'local_revision' => $draft['revision'], 'local' => $draft, 'status' => 'pending', 'instructions_sync' => 'unsupported_local_only', 'warnings' => Nova_Bridge_Suite_Writing_Adapter::warnings( $draft ) ];
                $prior = $old && self::CONTRACT === ( $old['contract'] ?? '' ) ? ( $old['template'] ?? null ) : null;
                if ( $prior ) { $this->state['template'] = $prior; }
                $this->state['pending'] = [ 'method' => $prior ? 'PUT' : 'POST', 'path' => $prior ? '/templates/' . $prior['id'] : '/templates', 'body' => $input, 'expected_revision' => $prior['revision'] ?? 0 ];
                $saved = $this->checkpoint(); if ( is_wp_error( $saved ) ) { return $saved; }
                $fresh = true;
            } else { $fresh = false; }
            $pending = $this->state['pending'];
            if ( self::json( $pending['body'] ) !== self::json( $input ) ) { return self::error( 'pending_conflict', 'The pending remote input differs from this local draft.' ); }
            $record = null;
            if ( 'POST' === $pending['method'] && ! $fresh ) {
                if ( ! Nova_Bridge_Suite_Posting_Client::uuid( $recover_template_id ) ) { return self::error( 'create_unknown', 'The template creation acknowledgement is unknown. Review NOVA templates, then supply the created template UUID to recover. No second template was created.' ); }
                $record = self::remote_record( $this->client->site_request( 'GET', '/templates/' . $recover_template_id ), $recover_template_id );
            } elseif ( 'PUT' === $pending['method'] && ! $fresh ) {
                $record = self::remote_record( $this->client->site_request( 'GET', $pending['path'] ), substr( $pending['path'], strlen( '/templates/' ) ) );
                if ( is_wp_error( $record ) ) { return $record; }
                if ( $record['revision'] === $pending['expected_revision'] ) { $record = null; }
                elseif ( $record['revision'] !== $pending['expected_revision'] + 1 || ! self::matches( $record, $input ) ) { return self::error( 'revision_conflict', 'NOVA changed since this profile was saved. No overwrite was attempted; review the remote template.' ); }
            }
            if ( null === $record ) {
                $headers = 'PUT' === $pending['method'] ? [ 'If-Match' => '"' . $pending['expected_revision'] . '"' ] : [];
                $record = self::remote_record( $this->client->site_request( $pending['method'], $pending['path'], $input, $headers ) );
            }
            if ( is_wp_error( $record ) ) {
                $status = $record->get_error_data()['status'] ?? 0;
                // Explicit request rejection establishes no mutation. Transport/5xx outcomes
                // remain pending and must be reconciled without blindly repeating a create.
                if ( in_array( $status, [ 400, 401, 403, 404, 409, 412, 422, 428 ], true ) && $fresh ) { $this->state['status'] = 'rejected'; unset( $this->state['pending'] ); }
                $this->state['error'] = [ 'code' => $record->get_error_code(), 'message' => $record->get_error_message() ]; $this->checkpoint(); return $record;
            }
            if ( ! self::matches( $record, $input ) || null !== $record['retired_at'] || ( 'PUT' === $pending['method'] && ( $pending['path'] !== '/templates/' . $record['id'] || $record['revision'] !== $pending['expected_revision'] + 1 ) ) ) { return self::error( 'template_mismatch', 'The returned publishing template does not match the recorded request. Keep this operation pending for review.', 502 ); }
            $this->state['template'] = $record; $this->state['status'] = 'synced_draft'; unset( $this->state['pending'], $this->state['error'] );
            $saved = $this->checkpoint(); return is_wp_error( $saved ) ? $saved : $this->state;
        } finally { $this->release(); }
    }

    /** Local native capability approval; no removed seal/activation API is called. */
    public function activate( array $draft, array $context = [] ) {
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ); if ( is_wp_error( $input ) ) { return $input; }
        $locked = $this->acquire( $draft ); if ( is_wp_error( $locked ) ) { return $locked; }
        try {
            if ( ! $this->state || self::CONTRACT !== ( $this->state['contract'] ?? '' ) || $this->state['local_revision'] !== $draft['revision'] || ! in_array( $this->state['status'], [ 'synced_draft', 'active' ], true ) ) { return self::error( 'not_synced', 'Synchronize this exact local revision before approving native writes.' ); }
            $record = self::remote_record( $this->client->site_request( 'GET', '/templates/' . $this->state['template']['id'] ), $this->state['template']['id'] );
            if ( is_wp_error( $record ) ) { return $record; }
            if ( self::json( $record ) !== self::json( $this->state['template'] ) || ! $record['enabled'] || null !== $record['retired_at'] ) { return self::error( 'revision_conflict', 'The synchronized publishing template changed. Review and synchronize again.' ); }
            if ( ! class_exists( 'Nova_Bridge_Suite_Mapped_Writer' ) ) { return self::error( 'writer_unavailable', 'The native writer is unavailable.' ); }
            $probe = Nova_Bridge_Suite_Mapped_Writer::probe_mapping( $record, $record['mapping'], $draft, $context );
            if ( is_wp_error( $probe ) ) { return $probe; }
            $profile = [ 'contract' => self::CONTRACT, 'site_id' => $this->client->site_id(), 'template' => self::frozen_remote( $record ), 'remote' => self::frozen_remote( $record ), 'local' => $draft, 'profile_digest' => hash( 'sha256', self::json( $draft ) ) ];
            $key = self::profile_key( $profile['site_id'], $record['id'], $record['revision'] );
            if ( ! add_option( $key, $profile, '', false ) && self::json( get_option( $key, [] ) ) !== self::json( $profile ) ) { return self::error( 'profile_conflict', 'This remote revision already belongs to another local native profile. It cannot be reassigned.', 409 ); }
            $this->state['status'] = 'active'; $this->state['evidence'] = $probe; $saved = $this->checkpoint();
            return is_wp_error( $saved ) ? $saved : $this->state;
        } finally { $this->release(); }
    }

    public static function configuration_for_snapshot( array $snapshot, string $site_id ) {
        $remote = $snapshot['configuration'] ?? null;
        if ( ( $snapshot['site_id'] ?? null ) !== $site_id || ! is_array( $remote ) || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $remote['id'] ?? null ) || ! is_int( $remote['revision'] ?? null ) || $remote['revision'] < 1 ) { return self::error( 'profile_missing', 'This delivery has no supported frozen publishing configuration.' ); }
        $profile = get_option( self::profile_key( $site_id, $remote['id'], $remote['revision'] ), null );
        if ( ! is_array( $profile ) || self::CONTRACT !== ( $profile['contract'] ?? '' ) || ( $profile['site_id'] ?? null ) !== $site_id || self::json( $profile['template'] ?? null ) !== self::json( $remote ) || hash( 'sha256', self::json( $profile['local'] ?? null ) ) !== ( $profile['profile_digest'] ?? null ) ) { return self::error( 'profile_unapproved', 'The exact frozen publishing revision has no matching approved local native profile. No current or historical writing-pin fallback is allowed.' ); }
        $input = Nova_Bridge_Suite_Writing_Adapter::template_input( $profile['local'] ); if ( is_wp_error( $input ) ) { return $input; }
        unset( $input['enabled'] ); if ( ! self::matches( $remote, $input ) ) { return self::error( 'profile_projection', 'The retained local profile does not match the frozen delivery configuration.' ); }
        return $profile;
    }

    /** Freeze only local policy; delivery configuration never contains native CMS authority. */
    public static function local_policy( array $snapshot, array $configuration ) {
        $verified = self::configuration_for_snapshot( $snapshot, $snapshot['site_id'] ?? '' ); if ( is_wp_error( $verified ) ) { return $verified; }
        if ( self::json( $verified ) !== self::json( $configuration ) ) { return self::error( 'profile_identity', 'The supplied native profile is not the retained exact revision.' ); }
        $local = $configuration['local'];
        if ( 'post' !== ( $local['reference_type'] ?? '' ) ) { return self::error( 'target_type', 'Term publication has no certified writer.' ); }
        $url = $snapshot['url'] ?? null;
        $normalized = self::url_identity( $url ); $home = self::url_identity( home_url( '/' ) );
        if ( ! $normalized || ! $home || $normalized[0] !== $home[0] ) { return self::error( 'target_url', 'The delivery URL must identify this WordPress origin before native routing.' ); }
        if ( 'update' === ( $local['routing']['operation'] ?? '' ) && $normalized !== self::url_identity( get_permalink( $local['reference_id'] ) ) ) { return self::error( 'target_url', 'An update delivery URL does not match the approved WordPress page. Review its native association; no page was changed.' ); }
        return [ 'reference' => [ 'reference_type' => $local['reference_type'], 'reference_id' => $local['reference_id'], 'signature' => $local['signature'] ], 'routing' => $local['routing'], 'profile_digest' => $configuration['profile_digest'], 'configuration_id' => $configuration['template']['id'], 'configuration_revision' => $configuration['template']['revision'], 'url_id' => $snapshot['url_id'] ];
    }
    public static function frozen_policy( $client, array $snapshot, array $configuration ) { return self::local_policy( $snapshot, $configuration ); }
    private static function url_identity( $value ): ?array {
        if ( ! is_string( $value ) || '' === $value ) { return null; }
        if ( '/' === substr( $value, 0, 1 ) && '//' !== substr( $value, 0, 2 ) ) { $value = home_url( $value ); }
        $parts = parse_url( $value );
        if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', [ 'https', 'http' ], true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) { return null; }
        // Draft and plain WordPress permalinks identify the page in the query string.
        return [ strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ), rtrim( $parts['path'] ?? '/', '/' ), $parts['query'] ?? '' ];
    }

    /** Optional remote page selection. It contains template_id only, never native_reference. */
    public static function url_binding_response( $request ) { return self::setting_response( $request, false ); }
    public static function template_default_response( $request ) { return self::setting_response( $request, true ); }
    private static function setting_response( $request, bool $defaults ) {
        $permission = self::permission( $request ); if ( is_wp_error( $permission ) ) { return $permission; }
        $connection = self::connection(); if ( empty( $connection['enabled'] ) ) { return self::error( 'connection_disabled', 'Enable the site connection before setting up publishing templates.', 503 ); }
        $write = 'POST' === $request->get_method(); $input = $write ? $request->get_json_params() : $request->get_params();
        if ( ! is_array( $input ) || ! in_array( $input['reference_type'] ?? null, [ 'post', 'term' ], true ) || ! ctype_digit( (string) ( $input['reference_id'] ?? '' ) ) || (int) $input['reference_id'] < 1 ) { return self::error( 'reference', 'Supply the concrete WordPress reference.', 400 ); }
        if ( ! $defaults && ! Nova_Bridge_Suite_Posting_Client::id( $input['url_id'] ?? null ) ) { return self::error( 'url_id', 'Enter a trusted NOVA URL ID, not a WordPress post ID.', 400 ); }
        if ( $defaults && ! preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $input['source_page_type'] ?? '' ) ) { return self::error( 'page_type', 'Enter the NOVA source page type for this default.', 400 ); }
        $client = new Nova_Bridge_Suite_Posting_Client( $connection );
        $path = $defaults ? '/template-defaults' : '/pages/' . $input['url_id'] . '/setting';
        $state = self::state( $input['reference_type'], (int) $input['reference_id'], $connection['site_id'] );
        if ( $write ) {
            if ( ! $state || self::CONTRACT !== ( $state['contract'] ?? '' ) || 'active' !== $state['status'] || ( $input['expected_revision'] ?? null ) !== $state['local_revision'] || ( $input['signature'] ?? null ) !== $state['local']['signature'] ) { return self::error( 'profile_changed', 'Approve this exact local profile before choosing a remote default or page setting.' ); }
            $expected = $input['expected_server_revision'] ?? null;
            if ( ! is_int( $expected ) || $expected < 0 ) { return self::error( 'setting_revision', 'Review the current server revision; use zero only for a missing page setting.', 400 ); }
            $valid = self::validate_live( $state['local'] ); if ( is_wp_error( $valid ) ) { return $valid; }
            $body = [ 'template_id' => $state['template']['id'] ];
            if ( $defaults ) {
                $read = $client->site_request( 'GET', $path ); if ( is_wp_error( $read ) ) { return $read; }
                $valid = Nova_Bridge_Suite_Posting_Protocol::validate( isset( $read['raw_body'] ) ? json_decode( $read['raw_body'] ) : $read['body'], 'PublishingTemplateDefaults' ); if ( is_wp_error( $valid ) ) { return $valid; }
                if ( ( $read['body']['revision'] ?? null ) !== $expected || ( $read['etag'] ?? null ) !== '"' . $expected . '"' ) { return self::error( 'setting_revision', 'Template defaults changed. Review the server revision again.', 412 ); }
                $all = $read['body']['defaults'] ?? []; if ( ! is_array( $all ) ) { return self::error( 'setting_shape', 'NOVA returned invalid template defaults.', 502 ); }
                $all[ $input['source_page_type'] ] = $state['template']['id']; $body = [ 'defaults' => $all ];
            }
            $reply = $client->site_request( 'PUT', $path, $body, [ 'If-Match' => '"' . $expected . '"' ] );
        } else { $reply = $client->site_request( 'GET', $path ); }
        if ( is_wp_error( $reply ) ) {
            if ( ! $write && ! $defaults && 404 === ( $reply->get_error_data()['status'] ?? 0 ) ) { return self::response( [ 'url_binding' => null, 'server_revision' => 0, 'message' => 'No explicit page setting. Creating one requires server revision zero and NOVA ownership validation.' ] ); }
            return $reply;
        }
        $record = $reply['body']; $schema = $defaults ? 'PublishingTemplateDefaults' : 'PublishingPageSetting';
        $valid = Nova_Bridge_Suite_Posting_Protocol::validate( isset( $reply['raw_body'] ) ? json_decode( $reply['raw_body'] ) : $record, $schema ); if ( is_wp_error( $valid ) ) { return $valid; }
        if ( ( $reply['etag'] ?? null ) !== '"' . $record['revision'] . '"' ) { return self::error( 'setting_etag', 'The setting response has no matching revision ETag.', 502 ); }
        if ( $write && ( $record['revision'] !== $expected + 1 || ( $defaults ? ( $record['defaults'][ $input['source_page_type'] ] ?? null ) : $record['template_id'] ) !== $state['template']['id'] ) ) { return self::error( 'setting_mismatch', 'The returned selection differs from the intended template.', 502 ); }
        $result = $record + ( $defaults ? [ 'source_page_type' => $input['source_page_type'] ] : [ 'url_id' => $input['url_id'] ] );
        if ( $write ) {
            $name = self::key( $connection['site_id'], $input['reference_type'], (int) $input['reference_id'] ) . ( $defaults ? '_default' : '_page_setting' );
            update_option( $name, $result, false );
            // Immutable audit only. Native routing uses the frozen configuration's approved profile.
            $history = $name . '_' . substr( hash( 'sha256', self::json( $result ) ), 0, 24 );
            if ( ! add_option( $history, $result, '', false ) && get_option( $history, null ) !== $result ) { return self::error( 'setting_history', 'The setting changed remotely but its local audit could not be retained. Review before retrying.', 500 ); }
        }
        return self::response( [ $defaults ? 'template_default' : 'url_binding' => $result, 'server_revision' => $record['revision'], 'message' => $write ? 'Publishing selection saved. Native targets and human instructions remain in WordPress.' : 'Current server selection loaded. Updates require this exact revision.' ] );
    }

    public static function validate_live( array $draft ) {
        if ( Nova_Bridge_Suite_Writing_Adapter::is_destination_draft( $draft ) ) { return Nova_Bridge_Suite_Writing_Adapter::template_input( $draft ); }
        $entity = Nova_Bridge_Suite_Strategy::entity( $draft['reference_type'], (int) $draft['reference_id'] );
        if ( ! $entity || Nova_Bridge_Suite_Strategy::fingerprint( $entity )['signature'] !== $draft['signature'] ) { return self::error( 'reference_changed', 'The selected native layout has changed. Reconcile and save its local draft.' ); }
        $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $entity ), null, 'path' );
        foreach ( $draft['target_descriptors'] as $path => $descriptor ) {
            if ( ! isset( $inventory[ $path ] ) ) { return self::error( 'target_missing', 'A selected native target is missing: ' . $path ); }
            foreach ( [ 'route', 'transport', 'request_path', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'parent_path', 'element', 'logical_field', 'selector_data' ] as $key ) {
                if ( self::json( $descriptor[ $key ] ?? null ) !== self::json( $inventory[ $path ][ $key ] ?? null ) ) { return self::error( 'target_changed', 'A selected native destination changed. Reconcile and save the local draft.' ); }
            }
        }
        return true;
    }

    private function acquire( array $draft ) {
        global $wpdb;
        $this->state_name = self::key( $this->client->site_id(), $draft['reference_type'], (int) $draft['reference_id'] );
        $this->lock_name = $this->state_name . '_lock';
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name ) );
        $decoded = null === $old ? null : maybe_unserialize( $old );
        if ( is_array( $decoded ) && ( $decoded['expires'] ?? 0 ) > time() ) { return self::error( 'busy', 'Another configuration operation is running. Retry after it finishes.' ); }
        $this->lock_value = maybe_serialize( [ 'token' => wp_generate_uuid4(), 'expires' => time() + 300 ] );
        $changed = null === $old
            ? $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s,%s,'no')", $this->lock_name, $this->lock_value ) )
            : $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", $this->lock_value, $this->lock_name, $old ) );
        if ( 1 !== $changed ) { $this->lock_value = null; return self::error( 'busy', 'Configuration storage is busy or unavailable.', false === $changed ? 500 : 409 ); }
        $this->state = self::state( $draft['reference_type'], (int) $draft['reference_id'], $this->client->site_id() );
        return true;
    }

    private function owned(): bool {
        global $wpdb;
        $current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->lock_name ) );
        return is_string( $this->lock_value ) && $current === $this->lock_value && ( maybe_unserialize( $current )['expires'] ?? 0 ) > time();
    }

    private function release(): void {
        global $wpdb;
        if ( $this->lock_value ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $this->lock_name, $this->lock_value ) ); wp_cache_delete( $this->lock_name, 'options' ); }
        $this->lock_value = null;
    }

    private function checkpoint() {
        global $wpdb;
        if ( ! $this->owned() ) { return self::error( 'lease_lost', 'Synchronization ownership expired. Retry to recover the last recorded step.' ); }
        $this->state['updated_at'] = gmdate( 'c' );
        // A distinct value makes affected_rows=0 an unambiguous missing/expired lease.
        $this->state['checkpoint_id'] = wp_generate_uuid4();
        $value = maybe_serialize( $this->state ); $expires = (int) maybe_unserialize( $this->lock_value )['expires'];
        $saved = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) SELECT %s,%s,'no' FROM {$wpdb->options} AS lease WHERE lease.option_name=%s AND BINARY lease.option_value=BINARY %s AND UNIX_TIMESTAMP() < %d",
            $this->state_name, $value, $this->lock_name, $this->lock_value, $expires
        ) );
        if ( 0 === $saved ) {
            // Test ownership in the same statement that writes state; a stale worker cannot
            // overwrite the new owner's checkpoint after its earlier owned() read.
            $saved = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} AS state INNER JOIN {$wpdb->options} AS lease ON lease.option_name=%s SET state.option_value=%s,state.autoload='no' WHERE state.option_name=%s AND BINARY lease.option_value=BINARY %s AND UNIX_TIMESTAMP() < %d",
                $this->lock_name, $value, $this->state_name, $this->lock_value, $expires
            ) );
        }
        if ( false === $saved ) { return self::error( 'storage', 'Cannot retain synchronization progress. Retry the same revision.', 500 ); }
        if ( 1 !== $saved ) { return self::error( 'lease_lost', 'Synchronization ownership changed before progress could be saved.' ); }
        wp_cache_delete( $this->state_name, 'options' ); wp_cache_delete( 'notoptions', 'options' );
        return true;
    }

}
