<?php
/** Exact delivery execution. An uncertain mutation is recovered, never replayed. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Posting_Worker {
    private $connection;
    private $jobs;
    private $client;
    private $configuration;
    private $writer;

    public function __construct( array $connection, $jobs = null, $client = null, $configuration = null, $writer = 'Nova_Bridge_Suite_Mapped_Writer' ) {
        $this->connection = $connection;
        $this->jobs = $jobs ?: new Nova_Bridge_Suite_Posting_Jobs();
        $this->client = $client ?: new Nova_Bridge_Suite_Posting_Client( $connection );
        $this->configuration = $configuration ?: static function ( $pin, $digest, $site, $client ) {
            return Nova_Bridge_Suite_Mapping_Sync::exact_configuration( $client, $pin, $digest, $site );
        };
        $this->writer = $writer;
    }

    private static function error( string $code, string $message ): WP_Error {
        return new WP_Error( 'nova_posting_' . $code, $message );
    }

    /** Start at most three deliveries within twenty seconds; in-flight operations have their own bounds. */
    public function run(): void {
        if ( empty( $this->connection['enabled'] ) ) { return; }
        $started = microtime( true );
        for ( $i = 0; $i < 3 && microtime( true ) - $started < 20; $i++ ) {
            $job = $this->jobs->claim( $this->connection['site_id'], ! empty( $this->connection['paused'] ) );
            if ( ! $job || is_wp_error( $job ) ) { break; }
            $this->process( $job );
        }
        $this->jobs->prune();
    }

    private function block( array $job, string $code ) {
        return $this->jobs->save( $job, [ 'state' => 'blocked', 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function retry( array $job, string $code ) {
        if ( $job['attempts'] >= 8 ) { return $this->block( $job, $code . '_retry_limit' ); }
        $delay = min( 3600, 30 * ( 2 ** max( 0, $job['attempts'] - 1 ) ) );
        return $this->jobs->save( $job, [ 'state' => 'ready', 'next_attempt' => time() + $delay, 'last_error' => substr( $code, 0, 100 ) ] );
    }

    private function save_payload( array $job, array $payload, string $phase, array $extra = [] ) {
        $saved = $this->jobs->save( $job, array_merge( [ 'phase' => $phase, 'payload' => array_merge( $job['payload'], $payload ), 'last_error' => '' ], $extra ) );
        if ( is_wp_error( $saved ) && 'nova_posting_journal_size' === $saved->get_error_code() ) {
            // Retain the last durable phase without the oversized addition; never enter mutation unjournaled.
            $this->block( $job, 'journal_size' );
        }
        return $saved;
    }

    private function receipt( array $job ) {
        $response = $this->client->request( 'GET', '/v1/content/' . rawurlencode( $job['content_id'] ) . '/result?version=' . $job['version'] );
        if ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            return 404 === (int) ( $data['status'] ?? 0 ) ? null : $response;
        }
        if ( 404 === (int) $response['status'] ) { return null; }
        return 200 === (int) $response['status'] && is_array( $response['body'] ) ? $response['body'] : self::error( 'receipt_unavailable', 'Receipt lookup was not definitive.' );
    }

    public static function receipt_matches( array $actual, array $expected, array $job ): bool {
        if ( ( $actual['content_id'] ?? null ) !== $job['content_id'] || ( $actual['version'] ?? null ) !== $job['version'] ) { return false; }
        foreach ( [ 'outcome', 'remote_post_id', 'fail_reason', 'cms_post_status' ] as $key ) {
            if ( ( $actual[ $key ] ?? null ) !== ( $expected[ $key ] ?? null ) ) { return false; }
        }
        // The service may normalize ISO timestamps; their instants must still agree.
        if ( isset( $expected['posted_at'] ) && ( ! is_string( $actual['posted_at'] ?? null ) || strtotime( $actual['posted_at'] ) !== strtotime( $expected['posted_at'] ) ) ) { return false; }
        return true;
    }

    public static function validate_content( array $content, array $job ) {
        if ( ( $content['content_id'] ?? null ) !== $job['content_id'] || ( $content['version'] ?? null ) !== $job['version'] || 'ready' !== ( $content['status'] ?? null ) ) { return self::error( 'exact_content_unavailable', 'The response is not the exact notified ready content version.' ); }
        if ( isset( $content['site_id'] ) && $content['site_id'] !== $job['site_id'] ) { return self::error( 'content_site_mismatch', 'The delivered site identity differs.' ); }
        if ( ! is_array( $content['fields'] ?? null ) ) { return self::error( 'content_invalid', 'The delivered fields are unavailable.' ); }
        if ( isset( $content['repeat_instances'] ) ) {
            if ( ! is_array( $content['repeat_instances'] ) || count( $content['repeat_instances'] ) > 8 ) { return self::error( 'repeat_identity_invalid', 'Repeat identities are outside the supported envelope.' ); }
            foreach ( $content['repeat_instances'] as $group => $slots ) {
                if ( ! is_string( $group ) || ! preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $group ) || ! is_array( $slots ) || array_values( $slots ) !== $slots || count( $slots ) > 12 ) { return self::error( 'repeat_identity_invalid', 'Repeat identities are outside the supported envelope.' ); }
                $seen_slots = []; $seen_instances = [];
                foreach ( $slots as $slot ) {
                    if ( ! is_array( $slot ) || count( $slot ) !== 2 || ! is_string( $slot['slot_id'] ?? null ) || ! is_string( $slot['instance_id'] ?? null ) || '' === $slot['slot_id'] || '' === $slot['instance_id'] || strlen( $slot['slot_id'] ) > 128 || strlen( $slot['instance_id'] ) > 128 || isset( $seen_slots[ $slot['slot_id'] ] ) || isset( $seen_instances[ $slot['instance_id'] ] ) ) { return self::error( 'repeat_identity_invalid', 'Repeat identity is missing, duplicated, or unsupported.' ); }
                    $seen_slots[ $slot['slot_id'] ] = true; $seen_instances[ $slot['instance_id'] ] = true;
                }
            }
        }
        return true;
    }

    private function configuration_for( array $content, array $job ) {
        foreach ( [ 'pin_id', 'digest', 'template_id', 'template_version' ] as $key ) {
            if ( ! is_string( $content[ $key ] ?? null ) || '' === $content[ $key ] || strlen( $content[ $key ] ) > 200 ) { return self::error( 'configuration_missing', 'This exact delivery does not carry its sealed configuration identity.' ); }
        }
        if ( ! is_callable( $this->configuration ) ) { return self::error( 'configuration_unavailable', 'An exact retained configuration is required.' ); }
        $configuration = call_user_func( $this->configuration, $content['pin_id'], $content['digest'], $job['site_id'], $this->client );
        if ( is_wp_error( $configuration ) ) { return $configuration; }
        if ( ! is_array( $configuration ) || ( $configuration['pin_id'] ?? null ) !== $content['pin_id'] || ! is_string( $configuration['digest'] ?? null ) || ! hash_equals( $configuration['digest'], $content['digest'] ) || ( $configuration['site_id'] ?? null ) !== $job['site_id'] ) { return self::error( 'configuration_mismatch', 'The exact retained configuration identity does not match this delivery.' ); }
        return $configuration;
    }

    /** Public for deterministic failure-injection tests; called only with a claimed journal row. */
    public function process( array $job ) {
        if ( $job['site_id'] !== ( $this->connection['site_id'] ?? null ) ) { return $this->block( $job, 'installation_mismatch' ); }
        $content_lock = 'content:' . $job['site_id'] . ':' . $job['content_id'];
        $locked = $this->jobs->lock( $content_lock );
        if ( is_wp_error( $locked ) ) { return $this->retry( $job, 'content_busy' ); }
        $target_lock = null;
        $previous_user = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        try {
            if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( (int) ( $this->connection['actor_user_id'] ?? 0 ) ); }
            $remote = $this->receipt( $job );
            if ( is_wp_error( $remote ) ) { return $this->retry( $job, 'receipt_lookup_unavailable' ); }
            if ( $remote ) {
                if ( isset( $job['payload']['receipt'] ) ) {
                    return self::receipt_matches( $remote, $job['payload']['receipt'], $job ) ? $this->jobs->save( $job, [ 'state' => 'complete', 'last_error' => '' ] ) : $this->block( $job, 'receipt_conflict' );
                }
                // Recover a local commit before comparing; an unrelated remote winner cannot authorize a write.
                if ( ! in_array( $job['phase'], [ 'applying', 'committed' ], true ) ) { return $this->block( $job, 'remote_receipt_without_local_commit' ); }
            }
            if ( 'receipt_pending' === $job['phase'] ) { return $this->drain_receipt( $job ); }
            if ( ! empty( $this->connection['paused'] ) && ! in_array( $job['phase'], [ 'applying', 'committed' ], true ) ) { return $this->retry( $job, 'paused' ); }

            if ( 'accepted' === $job['phase'] ) {
                // Query exact history and still verify the returned version; older services may ignore the query.
                $fetched = $this->client->request( 'GET', '/v1/content/' . rawurlencode( $job['content_id'] ) . '?version=' . $job['version'] );
                if ( is_wp_error( $fetched ) || 200 !== (int) ( $fetched['status'] ?? 0 ) || ! is_array( $fetched['body'] ?? null ) ) { return $this->retry( $job, 'content_fetch_unavailable' ); }
                $valid = self::validate_content( $fetched['body'], $job );
                if ( is_wp_error( $valid ) ) { return $this->block( $job, $valid->get_error_code() ); }
                $job = $this->save_payload( $job, [ 'content' => $fetched['body'] ], 'validated' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            if ( 'validated' === $job['phase'] ) {
                $configuration = $this->configuration_for( $job['payload']['content'], $job );
                if ( is_wp_error( $configuration ) ) {
                    $data = $configuration->get_error_data(); $status = (int) ( $data['status'] ?? 0 );
                    return 429 === $status || $status >= 500 ? $this->retry( $job, 'configuration_fetch_unavailable' ) : $this->block( $job, $configuration->get_error_code() );
                }
                $local = $configuration['local'] ?? [];
                $operation = $local['routing']['operation'] ?? null;
                $target = (int) ( $local['reference_id'] ?? 0 );
                if ( ! in_array( $operation, [ 'update', 'clone' ], true ) || 'post' !== ( $local['reference_type'] ?? null ) || $target < 1 ) { return $this->block( $job, 'routing_unavailable' ); }
                if ( ! is_callable( [ $this->writer, 'plan' ] ) ) { return $this->block( $job, 'writer_unavailable' ); }
                $publication = $local['routing']['publication'] ?? 'preserve';
                if ( ! in_array( $publication, [ 'preserve', 'publish', 'draft' ], true ) ) { return $this->block( $job, 'publication_policy_unavailable' ); }
                $desired_status = 'preserve' === $publication ? ( 'clone' === $operation ? 'draft' : get_post_status( $target ) ) : $publication;
                $context = [ 'site_id' => $job['site_id'], 'content_id' => $job['content_id'], 'version' => $job['version'], 'operation_id' => $job['operation_id'], 'target_post_id' => $target, 'operation' => $operation, 'desired_status' => $desired_status, 'actor_user_id' => (int) ( $this->connection['actor_user_id'] ?? 0 ) ];
                $context['repeat_instances'] = $job['payload']['content']['repeat_instances'] ?? [];
                $plan = call_user_func( [ $this->writer, 'plan' ], $job['payload']['content'], $configuration, $context );
                if ( is_wp_error( $plan ) ) { return $this->block( $job, $plan->get_error_code() ); }
                if ( ! is_array( $plan ) ) { return $this->block( $job, 'plan_invalid' ); }
                $job = $this->save_payload( $job, [ 'plan' => $plan, 'configuration' => $configuration, 'context' => $context ], 'planned', [ 'target_id' => (int) ( $plan['source_post_id'] ?? $plan['target_post_id'] ?? $target ) ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $target_lock = 'target:' . $job['site_id'] . ':' . $job['target_id'];
            $locked = $this->jobs->lock( $target_lock );
            if ( is_wp_error( $locked ) ) { $target_lock = null; return $this->retry( $job, 'target_busy' ); }
            $uncertain = $this->jobs->uncertain_target( $job );
            if ( is_wp_error( $uncertain ) ) { return $this->retry( $job, 'journal_unavailable' ); }
            if ( $uncertain ) { return $this->block( $job, 'other_mutation_unreconciled' ); }

            if ( 'planned' === $job['phase'] ) {
                $newer = $this->jobs->newer_committed( $job );
                if ( is_wp_error( $newer ) ) { return $this->retry( $job, 'journal_unavailable' ); }
                if ( $newer ) { return $this->block( $job, 'newer_version_already_committed' ); }
                // Crash anywhere after this durable boundary must go through recover(), never apply().
                $job = $this->save_payload( $job, [], 'applying' );
                if ( is_wp_error( $job ) ) { return $job; }
                $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
            } elseif ( 'applying' === $job['phase'] ) {
                if ( ! is_callable( [ $this->writer, 'recover' ] ) ) { return $this->block( $job, 'mutation_recovery_required' ); }
                $result = call_user_func( [ $this->writer, 'recover' ], $job['payload']['plan'], $job['operation_id'] );
                if ( is_array( $result ) && 'not_committed' === ( $result['state'] ?? null ) && true === ( $result['safe_to_apply'] ?? null ) ) {
                    if ( $remote ) { return $this->block( $job, 'remote_receipt_without_local_commit' ); }
                    if ( ! empty( $this->connection['paused'] ) ) { return $this->retry( $job, 'paused' ); }
                    $result = call_user_func( [ $this->writer, 'apply' ], $job['payload']['plan'], $job['operation_id'] );
                }
            } else { $result = $job['payload']['result'] ?? null; }
            if ( is_wp_error( $result ) ) { return $this->block( $job, $result->get_error_code() ); }
            if ( ! is_array( $result ) || empty( $result['post_id'] ) ) { return $this->block( $job, 'mutation_result_unverified' ); }
            if ( $remote ) {
                $status = $result['desired_status'] ?? $result['cms_post_status'] ?? null;
                $expected = [ 'outcome' => 'future' === $status ? 'scheduled' : 'posted', 'remote_post_id' => (string) $result['post_id'], 'cms_post_status' => $status, 'fail_reason' => null ];
                if ( ! self::receipt_matches( $remote, $expected, $job ) ) { return $this->block( $job, 'receipt_conflict' ); }
            }
            if ( 'committed' !== $job['phase'] ) {
                $job = $this->save_payload( $job, [ 'result' => $result ], 'committed', [ 'target_id' => (int) $result['post_id'] ] );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            // Only idempotent derived work/status completion runs after the CMS commit.
            if ( is_callable( [ $this->writer, 'finish' ] ) ) {
                $result = call_user_func( [ $this->writer, 'finish' ], $job['payload']['plan'], $result );
                if ( is_wp_error( $result ) ) { return $this->retry( $job, $result->get_error_code() ); }
                $job = $this->save_payload( $job, [ 'result' => $result ], 'committed' );
                if ( is_wp_error( $job ) ) { return $job; }
            }
            $verified = call_user_func( [ $this->writer, 'verify' ], $job['payload']['plan'], $result );
            if ( is_wp_error( $verified ) ) { return $this->retry( $job, $verified->get_error_code() ); }
            if ( ! is_array( $verified ) || empty( $verified['post_id'] ) || (int) $verified['post_id'] !== (int) $result['post_id'] || ! in_array( $verified['cms_post_status'] ?? null, [ 'draft', 'publish', 'future', 'private', 'pending' ], true ) ) { return $this->block( $job, 'verification_result_invalid' ); }
            $receipt = [ 'version' => $job['version'], 'outcome' => 'future' === $verified['cms_post_status'] ? 'scheduled' : 'posted', 'remote_post_id' => (string) $verified['post_id'], 'cms_post_status' => $verified['cms_post_status'], 'fail_reason' => null ];
            if ( ! empty( $verified['posted_at'] ) ) { $receipt['posted_at'] = $verified['posted_at']; }
            $job = $this->save_payload( $job, [ 'receipt' => $receipt ], 'receipt_pending' );
            if ( is_wp_error( $job ) ) { return $job; }
            if ( $remote ) { return self::receipt_matches( $remote, $receipt, $job ) ? $this->jobs->save( $job, [ 'state' => 'complete' ] ) : $this->block( $job, 'receipt_conflict' ); }
            return $this->drain_receipt( $job );
        } catch ( Throwable $error ) {
            // Do not log payloads or exception text; applying remains recover-only after any throw.
            return $this->retry( $job, 'worker_interrupted' );
        } finally {
            if ( function_exists( 'wp_set_current_user' ) ) { wp_set_current_user( $previous_user ); }
            if ( null !== $target_lock ) { $this->jobs->unlock( $target_lock ); }
            $this->jobs->unlock( $content_lock );
        }
    }

    private function drain_receipt( array $job ) {
        $receipt = $job['payload']['receipt'] ?? null;
        if ( ! is_array( $receipt ) ) { return $this->block( $job, 'receipt_journal_invalid' ); }
        $response = $this->client->request( 'POST', '/v1/content/' . rawurlencode( $job['content_id'] ) . '/result', $receipt );
        if ( is_wp_error( $response ) || ! in_array( (int) ( $response['status'] ?? 0 ), [ 200, 201 ], true ) || ! is_array( $response['body'] ?? null ) ) { return $this->retry( $job, 'receipt_delivery_unavailable' ); }
        return self::receipt_matches( $response['body'], $receipt, $job ) ? $this->jobs->save( $job, [ 'state' => 'complete', 'last_error' => '' ] ) : $this->block( $job, 'receipt_conflict' );
    }
}
