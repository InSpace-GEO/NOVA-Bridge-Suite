<?php
/** Core taxonomy counts updated in the same transaction as the native mutation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Taxonomy_Counts {
    private static function error( string $message ): WP_Error { return new WP_Error( 'nova_writer_taxonomy_lifecycle', $message, [ 'status' => 409, 'blocked' => true ] ); }
    private static function table(): string {
        global $wpdb;
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/D', $wpdb->term_taxonomy ) ) { throw new RuntimeException( 'Invalid taxonomy table.' ); }
        return '`' . $wpdb->term_taxonomy . '`';
    }

    /** No metadata, terms, or hooks are changed by this capability inspection. */
    public static function plan( array $snapshot, string $operation, string $desired ) {
        global $wpdb;
        $changes_status = $desired !== ( $snapshot['post']['post_status'] ?? '' );
        if ( 'clone' !== $operation && ! $changes_status ) { return []; }
        // Core counts inherited attachment status through its parent. That wider
        // dependency is outside this scalar page writer's captured transaction.
        if ( 'clone' !== $operation && $changes_status ) {
            $children = $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->term_relationships} r ON r.object_id=p.ID WHERE p.post_parent=%d AND p.post_type='attachment' AND p.post_status='inherit' LIMIT 1", $snapshot['post']['ID'] ) );
            if ( $wpdb->last_error || $children ) { return self::error( 'Status changes with inherited attachment taxonomy dependencies require a separate verified adapter.' ); }
        }
        if ( empty( $snapshot['terms'] ) ) { return []; }
        if ( 'attachment' === ( $snapshot['post']['post_type'] ?? '' ) || has_filter( 'update_post_term_count_statuses' ) ) { return self::error( 'Attachment or customized status counts are outside the verified core taxonomy adapter.' ); }
        $engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name=%s', $wpdb->term_taxonomy ), ARRAY_A );
        if ( $wpdb->last_error || strtolower( $engine['Engine'] ?? '' ) !== 'innodb' ) { return self::error( 'Taxonomy counts require an InnoDB table.' ); }
        $trigger = $wpdb->get_var( $wpdb->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=%s LIMIT 1', $wpdb->term_taxonomy ) );
        if ( $wpdb->last_error || $trigger ) { return self::error( 'Taxonomy count triggers are not covered by this adapter.' ); }
        $terms = [];
        foreach ( $snapshot['terms'] as $relationship ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT term_taxonomy_id,term_id,taxonomy FROM ' . self::table() . ' WHERE term_taxonomy_id=%d', $relationship['term_taxonomy_id'] ), ARRAY_A );
            if ( $wpdb->last_error || ! $row ) { return self::error( 'A native taxonomy identity is missing.' ); }
            $taxonomy = get_taxonomy( $row['taxonomy'] );
            if ( ! $taxonomy || ( 'clone' === $operation && 'post_translations' === $row['taxonomy'] ) ) { return self::error( 'Cloning a translation group requires an explicit translation adapter.' ); }
            $callback = $taxonomy->update_count_callback;
            $types = array_values( array_unique( array_map( static function ( $type ) { return explode( ':', $type )[0]; }, (array) $taxonomy->object_type ) ) );
            sort( $types, SORT_STRING );
            if ( empty( $callback ) ) { $callback = count( array_filter( $types, 'post_type_exists' ) ) === count( $types ) ? '_update_post_term_count' : '_update_generic_term_count'; }
            if ( ! is_string( $callback ) || ! in_array( $callback, [ '_update_post_term_count', '_update_generic_term_count' ], true ) ) { return self::error( 'A custom taxonomy count callback needs a verified provider adapter.' ); }
            $function = new ReflectionFunction( $callback );
            if ( realpath( $function->getFileName() ) !== realpath( ABSPATH . WPINC . '/taxonomy.php' ) ) { return self::error( 'The core taxonomy count implementation is unavailable.' ); }
            if ( '_update_post_term_count' === $callback && ! in_array( $snapshot['post']['post_type'], $types, true ) ) { return self::error( 'The native post type is no longer registered for its taxonomy.' ); }
            $terms[] = [ 'term_taxonomy_id' => (int) $row['term_taxonomy_id'], 'term_id' => (int) $row['term_id'], 'taxonomy' => $row['taxonomy'], 'callback' => $callback, 'object_types' => $types ];
        }
        usort( $terms, static function ( $left, $right ) { return $left['term_taxonomy_id'] <=> $right['term_taxonomy_id']; } );
        return [ 'operation' => $operation, 'desired_status' => $desired, 'terms' => $terms ];
    }

    /** Called only inside the writer transaction, before its durable marker. */
    public static function apply( array $plan, ?array $before, array $after ) {
        global $wpdb;
        if ( ! $plan ) { return true; }
        $probe = self::plan( $before ?? $after, $plan['operation'], $plan['desired_status'] );
        if ( is_wp_error( $probe ) ) { return $probe; }
        if ( ( $probe['terms'] ?? [] ) !== $plan['terms'] ) { return self::error( 'Taxonomy identities or core count policy changed after planning.' ); }
        foreach ( $plan['terms'] as $term ) {
            $row = $wpdb->get_row( $wpdb->prepare( 'SELECT term_id,taxonomy,count FROM ' . self::table() . ' WHERE term_taxonomy_id=%d FOR UPDATE', $term['term_taxonomy_id'] ), ARRAY_A );
            if ( $wpdb->last_error || ! $row || (int) $row['term_id'] !== $term['term_id'] || $row['taxonomy'] !== $term['taxonomy'] ) { return self::error( 'The locked taxonomy identity changed.' ); }
            $generic = '_update_generic_term_count' === $term['callback'];
            $old_counted = null !== $before && ( $generic || 'publish' === $before['post']['post_status'] );
            $new_counted = $generic || 'publish' === $after['post']['post_status'];
            $delta = (int) $new_counted - (int) $old_counted;
            if ( $delta ) {
                if ( (int) $row['count'] + $delta < 0 ) { return self::error( 'The native term count is inconsistent and needs repair before publishing.' ); }
                if ( 1 !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET count=count+%d WHERE term_taxonomy_id=%d', $delta, $term['term_taxonomy_id'] ) ) ) { return self::error( 'The transactional native term count could not be persisted.' ); }
            }
        }
        return true;
    }

    /** Cache deletion can be retried after a committed transaction. */
    public static function cache( array $plan, int $post_id ): void {
        foreach ( $plan['terms'] ?? [] as $term ) {
            wp_cache_delete( $term['term_id'], 'terms' );
            wp_cache_delete( $post_id, $term['taxonomy'] . '_relationships' );
            wp_cache_delete( 'all_ids', $term['taxonomy'] );
            wp_cache_delete( 'get', $term['taxonomy'] );
        }
        if ( $plan ) { wp_cache_set( 'last_changed', microtime(), 'terms' ); }
    }
}
