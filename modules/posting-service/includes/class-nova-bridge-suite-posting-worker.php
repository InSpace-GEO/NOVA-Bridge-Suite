<?php
/** Durable delivery intake, native-write reconciliation and exact connector-event replay. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Worker {
    private $connection;
    private $jobs;
    private $client;
    private $configuration;
    private $writer;
    private $policy;
    private $recovery_only = false;

    public function __construct( array $connection, $jobs = null, $client = null, $configuration = null, $writer = 'Nova_Bridge_Suite_Mapped_Writer', $policy = null ) {
        $this->connection = $connection;
        $this->jobs = $jobs ?: new Nova_Bridge_Suite_Posting_Jobs();
        $this->client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $this->configuration = $configuration ?: [ 'Nova_Bridge_Suite_Mapping_Sync', 'configuration_for_snapshot' ];
        $this->policy = $policy ?: [ 'Nova_Bridge_Suite_Mapping_Sync', 'frozen_policy' ];
        $this->writer = $writer;
    }

    public function run(): void {
        if ( empty( $this->connection['enabled'] ) ) { return; }
        $discovery = ( new Nova_Bridge_Suite_Posting_Discovery( $this->connection, $this->jobs, $this->client ) )->run();
        $status = is_wp_error( $discovery ) ? (int) ( $discovery->get_error_data()['status'] ?? 0 ) : 0;
        if ( 401 === $status ) { return; }
        $this->recovery_only = ! empty( $this->connection['paused'] ) || 403 === $status;
        $started = microtime( true );
        for ( $i = 0; $i < 3 && microtime( true ) - $started < 20; ++$i ) {
            $job = $this->jobs->claim( $this->connection['site_id'], $this->recovery_only );
            if ( ! $job || is_wp_error( $job ) ) { break; }
            $result = $this->process( $job );
            if ( is_array( $result ) && in_array( $result['last_error'] ?? '', [ 'authentication_required', 'site_ineligible' ], true ) ) { break; }
        }
        $this->jobs->prune();
    }

    private function block( array $job, string $code ) {
        return $this->jobs->save( $job, [ 'state' => 'blocked', 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function retry( array $job, string $code, string $retry_after = '' ) {
        if ( $job['attempts'] >= 8 ) { return $this->block( $job, $code . '_retry_limit' ); }
        $delay = min( 3600, 30 * ( 2 ** max( 0, $job['attempts'] - 1 ) ) );
        if ( '' !== $retry_after ) {
            $seconds = ctype_digit( $retry_after ) ? (float) $retry_after : max( 0, ( strtotime( $retry_after ) ?: time() ) - time() );
            if ( $seconds > 86400 ) { return $this->block( $job, 'retry_after_out_of_bounds' ); }
            $delay = max( $delay, (int) $seconds );
        }
        return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + $delay, 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function paused( array $job ) {
        return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + 300, 'attempts' => 0, 'last_error' => 'paused' ] );
    }

    private function remote_error( array $job, WP_Error $error ) {
        $data = $error->get_error_data(); $status = (int) ( $data['status'] ?? 0 );
        if ( 401 === $status || 403 === $status ) {
            ( new Nova_Bridge_Suite_Posting_Discovery( $this->connection, $this->jobs, $this->client ) )->suspend( $error );
            return $this->block( $job, 401 === $status ? 'authentication_required' : 'site_ineligible' );
        }
        if ( in_array( $status, [ 409, 410, 412 ], true ) ) { return $this->block( $job, 'remote_identity_conflict' ); }
        if ( 404 === $status || 429 === $status || $status >= 500 || 'nova_posting_transport' === $error->get_error_code() ) {
            return $this->retry( $job, $error->get_error_code(), (string) ( $data['retry_after'] ?? '' ) );
        }
        return $this->block( $job, $error->get_error_code() );
    }

    private function save_payload( array $job, array $payload, string $phase, array $extra = [] ) {
        $saved = $this->jobs->save( $job, array_merge( [ 'phase' => $phase, 'payload' => array_merge( $job['payload'], $payload ), 'last_error' => '' ], $extra ) );
        if ( is_wp_error( $saved ) && 'nova_posting_journal_size' === $saved->get_error_code() ) { $this->block( $job, 'journal_size' ); }
        return $saved;
    }

    private static function path( array $job ): string {
        return '/deliveries/' . rawurlencode( $job['payload']['discovery']['id'] );
    }

    private function fetch( array $job ) {
        // The attempt header can change without changing frozen bytes: always request full 200.
        $reply = $this->client->site_request( 'GET', self::path( $job ) );
        if ( is_wp_error( $reply ) ) { return $reply; }
        $snapshot = Nova_Bridge_Suite_Posting_Protocol::snapshot( $reply, $job );
        if ( is_wp_error( $snapshot ) ) { return $snapshot; }
        if ( isset( $job['payload']['snapshot_raw'] ) && $reply['raw_body'] !== $job['payload']['snapshot_raw'] ) {
            return Nova_Bridge_Suite_Posting_Protocol::error( 'snapshot_changed', 'An immutable delivery changed its bytes.' );
        }
        return [ 'content' => $snapshot, 'snapshot_raw' => $reply['raw_body'], 'delivery_etag' => $reply['etag'], 'attempt_id' => $reply['attempt_id'] ];
    }

    /** An operator-issued attempt never authorizes repeating a known or uncertain CMS write. */
    private function check_attempt( array $job, array $fetched ) {
        $old = $job['payload']['attempt_id'] ?? null;
        if ( $old === $fetched['attempt_id'] ) {
            return $this->save_payload( $job, [], 'committed', [ 'state' => 'complete' ] );
        }
        $proof = $job['payload']['native_absence_proof'] ?? [];
        if ( true !== ( $proof['no_native_commit'] ?? false ) || ! empty( $job['payload']['result']['post_id'] ) ) {
            return $this->block( $job, 'attempt_changed_without_native_absence' );
        }
        if ( isset( $job['payload']['plan'] ) ) {
            $current = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
            if ( ! is_array( $current ) || 'not_committed' !== ( $current['state'] ?? null ) || true !== ( $current['no_native_commit'] ?? false ) ) {
                return $this->block( $job, 'attempt_changed_without_native_absence' );
            }
        }
        $payload = $job['payload'];
        $history = $payload['prior_attempts'] ?? [];
        if ( count( $history ) >= 8 ) { return $this->block( $job, 'attempt_history_limit' ); }
        $history[] = array_intersect_key( $payload, array_flip( [ 'attempt_id', 'events', 'native_absence_proof', 'result' ] ) );
        foreach ( [ 'events', 'pending_event', 'event_bytes', 'event_sha256', 'event_continue', 'plan', 'context', 'configuration', 'selected_policy', 'result', 'native_absence_proof', 'failure_code' ] as $key ) { unset( $payload[ $key ] ); }
        $payload = array_merge( $payload, $fetched, [ 'prior_attempts' => $history ] );
        return $this->jobs->save( $job, [ 'payload' => $payload, 'phase' => 'validated', 'target_id' => 0, 'last_error' => '' ] );
    }

    /** Public to allow deterministic process-loss tests at journal/native boundaries. */
    public function process( array $job ) {
        if ( empty( $this->connection['enabled'] ) ) { return $this->block( $job, 'connection_disabled' ); }
        if ( $job['site_id'] !== ( $this->connection['site_id'] ?? null ) ) { return $this->block( $job, 'installation_mismatch' ); }
        if ( ( $job['payload']['protocol'] ?? null ) !== Nova_Bridge_Suite_Posting_Protocol::SNAPSHOT ) { return $this->block( $job, 'legacy_protocol_requires_review' ); }
        if ( ( $job['payload']['business_result_id'] ?? null ) !== $job['operation_id'] || ! Nova_Bridge_Suite_Posting_Protocol::uuid( $job['operation_id'] ) ) { return $this->block( $job, 'operation_identity_invalid' ); }
        $lock = 'delivery:' . $job['site_id'] . ':' . $job['content_id'];
        if ( is_wp_error( $this->jobs->lock( $lock ) ) ) { return $this->retry( $job, 'delivery_busy' ); }
        $target_lock = null; $previous_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        try {
            if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( (int) ( $this->connection['actor_user_id'] ?? 0 ) ); }
            if ( 'event_pending' === $job['phase'] ) {
                $job = $this->drain_event( $job );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] || 'received' !== $job['phase'] ) { return $job; }
            }
            $paused = $this->recovery_only || ! empty( $this->connection['paused'] );
            if ( $paused && ! in_array( $job['phase'], [ 'applying', 'committed' ], true ) ) { return $this->paused( $job ); }
            $fetched = $this->fetch( $job );
            if ( is_wp_error( $fetched ) ) { return $this->remote_error( $job, $fetched ); }
            if ( 'attempt_check' === $job['phase'] ) {
                $job = $this->check_attempt( $job, $fetched );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] ) { return $job; }
            } elseif ( isset( $job['payload']['attempt_id'] ) && $job['payload']['attempt_id'] !== $fetched['attempt_id'] ) {
                return $this->block( $job, 'attempt_changed_during_execution' );
            }
            if ( 'accepted' === $job['phase'] ) {
                $job = $this->save_payload( $job, $fetched, 'validated' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $snapshot = $fetched['content'];
            if ( 'validated' === $job['phase'] ) {
                $job = $this->queue_event( $job, $snapshot, 'received_complete', [], 'received' );
                if ( is_wp_error( $job ) || 'running' !== $job['state'] || 'received' !== $job['phase'] ) { return $job; }
            }
            if ( 'received' === $job['phase'] ) {
                $configuration = call_user_func( $this->configuration, $snapshot, $job['site_id'] );
                if ( is_wp_error( $configuration ) ) { return $this->native_failure( $job, $snapshot, $configuration->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ] ); }
                $selected = call_user_func( $this->policy, $this->client, $snapshot, $configuration );
                if ( is_wp_error( $selected ) ) { return $this->native_failure( $job, $snapshot, $selected->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ] ); }
                $reference = $selected['reference'] ?? []; $routing = $selected['routing'] ?? [];
                $operation = $routing['operation'] ?? null; $target = $reference['reference_id'] ?? 0;
                if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || 'post' !== ( $reference['reference_type'] ?? null ) || ! is_int( $target ) || $target < 1 ) { return $this->native_failure( $job, $snapshot, 'routing_unavailable', [ 'no_native_commit' => true ] ); }
                $publication = $routing['publication'] ?? 'preserve';
                if ( ! in_array( $publication, [ 'preserve', 'publish', 'draft' ], true ) ) { return $this->native_failure( $job, $snapshot, 'publication_policy_unavailable', [ 'no_native_commit' => true ] ); }
                $desired = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : get_post_status( $target ) ) : $publication;
                $context = [ 'site_id' => $job['site_id'], 'delivery_id' => $snapshot['id'], 'content_id' => $snapshot['content_item_id'], 'content_item_id' => $snapshot['content_item_id'], 'content_item_version_id' => $snapshot['content_item_version_id'], 'url_id' => $snapshot['url_id'], 'version' => $snapshot['version_number'], 'operation_id' => $job['operation_id'], 'business_result_id' => $job['operation_id'], 'target_post_id' => $target, 'operation' => $operation, 'desired_status' => $desired, 'actor_user_id' => (int) ( $this->connection['actor_user_id'] ?? 0 ), 'attempt_id' => $fetched['attempt_id'], 'source_sha256' => $snapshot['source_sha256'], 'snapshot_sha256' => hash( 'sha256', $fetched['snapshot_raw'] ), 'delivery_etag' => $fetched['delivery_etag'] ];
                $job = $this->save_payload( $job, [ 'configuration' => $configuration, 'selected_policy' => $selected, 'context' => $context ], 'received' );
                if ( is_wp_error( $job ) ) { return $job; }
                $plan = call_user_func( [ $this->writer, 'plan' ], $snapshot, $configuration, $context );
                if ( is_wp_error( $plan ) ) { return $this->native_failure( $job, $snapshot, $plan->get_error_code(), [ 'no_native_commit' => true, 'boundary' => 'before_apply' ] ); }
                if ( ! is_array( $plan ) ) { return $this->block( $job, 'plan_invalid' ); }
                $job = $this->save_payload( $job, [ 'plan' => $plan ], 'planned', [ 'target_id' => $target ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            if ( ! in_array( $job['phase'], [ 'planned', 'applying', 'committed' ], true ) ) { return $this->block( $job, 'journal_phase_invalid' ); }
            $target_lock = 'target:' . $job['site_id'] . ':' . $job['target_id'];
            if ( is_wp_error( $this->jobs->lock( $target_lock ) ) ) { $target_lock = null; return $this->retry( $job, 'target_busy' ); }
            $uncertain = $this->jobs->uncertain_target( $job );
            if ( is_wp_error( $uncertain ) ) { return $this->retry( $job, 'journal_unavailable' ); }
            if ( $uncertain ) { return $this->block( $job, 'other_mutation_unreconciled' ); }
            if ( 'planned' === $job['phase'] ) {
                $job = $this->save_payload( $job, [], 'applying' );
                if ( is_wp_error( $job ) ) { return $job; }
                $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
            } elseif ( 'applying' === $job['phase'] ) {
                $result = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
                if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? null ) && true === ( $result['safe_to_apply'] ?? null ) ) {
                    if ( $paused ) { return $this->paused( $job ); }
                    $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
                }
            } else { $result = $job['payload']['result'] ?? null; }
            if ( is_wp_error( $result ) ) {
                if ( 'nova_writer_ambiguous_commit' === $result->get_error_code() ) { return $this->retry( $job, 'native_commit_reconciliation' ); }
                $proof = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
                if ( is_array( $proof ) && 'not_committed' === ( $proof['state'] ?? null ) && true === ( $proof['no_native_commit'] ?? false ) ) { return $this->native_failure( $job, $snapshot, $proof['failure_code'] ?? $result->get_error_code(), $proof ); }
                return is_array( $proof ) && ! empty( $proof['post_id'] ) ? $this->retry( $job, 'native_commit_reconciliation' ) : $this->block( $job, $result->get_error_code() );
            }
            if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? null ) && true === ( $result['no_native_commit'] ?? false ) && false === ( $result['safe_to_apply'] ?? null ) ) { return $this->native_failure( $job, $snapshot, $result['failure_code'] ?? 'native_target_unavailable', $result ); }
            if ( ! is_array( $result ) || empty( $result['post_id'] ) ) { return $this->block( $job, 'mutation_result_unverified' ); }
            if ( 'committed' !== $job['phase'] ) {
                $job = $this->save_payload( $job, [ 'result' => $result ], 'committed' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            if ( is_callable( [ $this->writer, 'finish' ] ) ) {
                $result = call_user_func( [ $this->writer, 'finish' ], $job['payload']['plan'], $result );
                if ( is_wp_error( $result ) ) { return $this->retry( $job, $result->get_error_code() ); }
                $job = $this->save_payload( $job, [ 'result' => $result ], 'committed' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $verified = call_user_func( [ $this->writer, 'verify' ], $job['payload']['plan'], $result );
            if ( is_wp_error( $verified ) ) { return $this->retry( $job, $verified->get_error_code() ); }
            if ( ! is_array( $verified ) || (int) ( $verified['post_id'] ?? 0 ) !== (int) $result['post_id'] || ! in_array( $verified['cms_post_status'] ?? null, [ 'draft', 'publish', 'future', 'private', 'pending' ], true ) ) { return $this->block( $job, 'verification_result_invalid' ); }
            if ( 'publish' !== $verified['cms_post_status'] ) {
                // Stored drafts/scheduled posts stay waiting_in_draft in NOVA until actual publication.
                return $this->save_payload( $job, [ 'result' => $verified ], 'committed', [ 'state' => 'ready', 'next_attempt' => time() + 300, 'attempts' => 0, 'last_error' => 'awaiting_publication' ] );
            }
            return $this->queue_event( $job, $snapshot, 'publication_succeeded', [ 'published_at' => Nova_Bridge_Suite_Posting_Protocol::now(), 'publication_ref' => 'wordpress:post:' . $verified['post_id'] . ';operation:' . $job['operation_id'] ], 'complete' );
        } catch ( Throwable $error ) {
            return $this->retry( $job, 'worker_interrupted' );
        } finally {
            if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( $previous_user ); }
            if ( null !== $target_lock ) { $this->jobs->unlock( $target_lock ); }
            $this->jobs->unlock( $lock );
        }
    }

    private function native_failure( array $job, array $snapshot, string $code, array $proof ) {
        $code = preg_match( '/^[a-z0-9_]{1,100}$/D', $code ) ? $code : 'native_failure';
        return $this->queue_event( $job, $snapshot, 'publication_failed', [ 'reason' => $code ], 'complete', [ 'native_absence_proof' => $proof, 'failure_code' => $code ] );
    }

    private function queue_event( array $job, array $snapshot, string $kind, array $evidence, string $continue, array $extra = [] ) {
        $event = array_merge( [ 'event_id' => strtolower( wp_generate_uuid4() ), 'kind' => $kind, 'site_id' => $job['site_id'], 'delivery_id' => $snapshot['id'], 'content_item_version_id' => $snapshot['content_item_version_id'], 'version_number' => $snapshot['version_number'], 'source_sha256' => $snapshot['source_sha256'], 'attempt_id' => $job['payload']['attempt_id'], 'delivery_etag' => $job['payload']['delivery_etag'] ], $evidence );
        if ( is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ) ) { return $this->block( $job, 'event_schema' ); }
        $bytes = Nova_Bridge_Suite_Receipt_Json::encode( $event );
        $job = $this->save_payload( $job, array_merge( $extra, [ 'pending_event' => $event, 'event_bytes' => $bytes, 'event_sha256' => hash( 'sha256', $bytes ), 'event_continue' => $continue ] ), 'event_pending' );
        if ( is_wp_error( $job ) ) { return $job; }
        try { return $this->drain_event( $job ); }
        catch ( Throwable $error ) { return $this->retry( $job, 'worker_interrupted' ); }
    }

    private function drain_event( array $job ) {
        $bytes = $job['payload']['event_bytes'] ?? null; $event = $job['payload']['pending_event'] ?? [];
        if ( ! is_string( $bytes ) || ! hash_equals( hash( 'sha256', $bytes ), $job['payload']['event_sha256'] ?? '' ) || is_wp_error( Nova_Bridge_Suite_Posting_Protocol::event_input( $event ) ) || Nova_Bridge_Suite_Receipt_Json::encode( $event ) !== $bytes ) { return $this->block( $job, 'event_journal_invalid' ); }
        $snapshot = $job['payload']['content'] ?? [];
        foreach ( [ 'site_id' => $job['site_id'], 'delivery_id' => $job['payload']['discovery']['id'], 'attempt_id' => $job['payload']['attempt_id'], 'delivery_etag' => $job['payload']['delivery_etag'], 'content_item_version_id' => $snapshot['content_item_version_id'] ?? null, 'version_number' => $snapshot['version_number'] ?? null, 'source_sha256' => $snapshot['source_sha256'] ?? null ] as $key => $value ) {
            if ( ( $event[ $key ] ?? null ) !== $value ) { return $this->block( $job, 'event_journal_identity' ); }
        }
        $response = $this->client->site_request_bytes( 'POST', self::path( $job ) . '/events', $bytes );
        if ( is_wp_error( $response ) ) { return $this->remote_error( $job, $response ); }
        $accepted = Nova_Bridge_Suite_Posting_Protocol::accepted( $response, $event );
        if ( is_wp_error( $accepted ) ) { return $this->block( $job, $accepted->get_error_code() ); }
        $events = $job['payload']['events'] ?? [];
        $events[ $event['kind'] ] = [ 'bytes' => $bytes, 'sha256' => hash( 'sha256', $bytes ), 'accepted' => $accepted ];
        $continue = $job['payload']['event_continue'] ?? '';
        if ( ! in_array( $continue, [ 'received', 'complete' ], true ) || ( 'received' === $continue ) !== ( 'received_complete' === $event['kind'] ) ) { return $this->block( $job, 'event_continuation_invalid' ); }
        // 202/pending confirms durable acceptance only. Never label it backend-applied.
        return $this->save_payload( $job, [ 'events' => $events ], 'complete' === $continue ? 'committed' : 'received', 'complete' === $continue ? [ 'state' => 'complete', 'last_error' => $job['payload']['failure_code'] ?? '' ] : [] );
    }
}
