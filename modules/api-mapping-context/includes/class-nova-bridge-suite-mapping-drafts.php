<?php
/** Local mapping drafts. No publishing, remote synchronization or activation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Nova_Bridge_Suite_Mapping_Drafts {

    private const MAX_BYTES = 262144;
    private const OPTION_PREFIX = 'nova_mapping_draft_';

    public static function bootstrap(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ], 1002 );
    }

    public static function register_routes(): void {
        register_rest_route( 'nova-bridge/v1', '/mapping/catalog', [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'catalog_response' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ] );
        register_rest_route( 'nova-bridge/v1', '/mapping/draft', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_response' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'save_response' ], 'permission_callback' => [ __CLASS__, 'can_admin' ] ],
        ] );
    }

    public static function can_admin() {
        return current_user_can( 'manage_options' ) ? true : self::error( 'forbidden', 'Administrator access is required.', is_user_logged_in() ? 403 : 401 );
    }

    private static function error( string $code, string $message, int $status = 400 ): WP_Error {
        return new WP_Error( 'nova_mapping_draft_' . $code, $message, [ 'status' => $status ] );
    }

    private static function response( array $data ) {
        $response = rest_ensure_response( $data );
        $response->header( 'Cache-Control', 'private, no-store' );
        return $response;
    }

    public static function catalog_response( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $mode = $request->get_param( 'mode' ) ?: 'nova';
        if ( ! in_array( $mode, [ 'preview', 'nova' ], true ) ) { return self::error( 'mode', 'Choose preview or nova catalog mode.' ); }
        return self::response( self::catalog( $mode ) );
    }

    /** A trusted server adapter supplies this normalized shape, not an assumed NOVA HTTP envelope. */
    public static function catalog( string $mode ): array {
        if ( 'preview' === $mode ) { return self::preview_catalog(); }
        $unavailable = [ 'origin' => 'unavailable', 'templates' => [], 'message' => 'NOVA catalog is not connected. Existing drafts remain local and editable.' ];
        $value = apply_filters( 'nova_bridge_mapping_catalog', [ 'templates' => [] ] );
        if ( ! is_array( $value ) || ! isset( $value['templates'] ) || ! is_array( $value['templates'] ) || ! $value['templates'] || count( $value['templates'] ) > 100 ) { return $unavailable; }
        $templates = []; $identities = [];
        foreach ( $value['templates'] as $template ) {
            $normalized = self::normalize_template( $template );
            if ( false === $normalized ) { $unavailable['message'] = 'The NOVA catalog adapter returned an unsupported normalized catalog. Existing drafts are retained.'; return $unavailable; }
            $identity = wp_json_encode( [ $normalized['id'], $normalized['revision'] ] );
            if ( isset( $identities[ $identity ] ) ) { return $unavailable; }
            $identities[ $identity ] = true;
            $templates[] = $normalized;
        }
        return [ 'origin' => 'nova', 'templates' => $templates, 'message' => 'Catalog supplied by the installed NOVA adapter. Saving here creates a local draft only.' ];
    }

    private static function text( $value, int $max, bool $empty = true ): bool {
        return is_string( $value ) && strlen( $value ) <= $max && ( $empty || '' !== trim( $value ) ) && 1 === preg_match( '//u', $value ) && false === strpos( $value, "\0" );
    }

    private static function source_path( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}(?:\[\]\.[a-z][a-z0-9_]{1,63})?$/D', $value );
    }

    private static function key( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $value );
    }

    private static function normalize_template( $input ) {
        if ( ! is_array( $input ) ) { return false; }
        foreach ( [ 'id', 'revision', 'label', 'family' ] as $key ) { if ( ! self::text( $input[ $key ] ?? null, 200, false ) ) { return false; } }
        foreach ( [ 'fields', 'groups', 'protected_slots' ] as $key ) { if ( ! isset( $input[ $key ] ) || ! is_array( $input[ $key ] ) ) { return false; } }
        if ( count( $input['fields'] ) > 144 || count( $input['groups'] ) > 8 || count( $input['protected_slots'] ) > 16 ) { return false; }
        $result = array_intersect_key( $input, array_flip( [ 'id', 'revision', 'label', 'family' ] ) );
        $result['fields'] = []; $result['groups'] = []; $result['protected_slots'] = []; $sources = []; $groups = [];
        foreach ( $input['fields'] as $field ) {
            if ( ! is_array( $field ) || ! self::source_path( $field['source_path'] ?? null ) || ! self::text( $field['label'] ?? null, 200, false ) || ! in_array( $field['type'] ?? null, [ 'heading', 'rich_text', 'plain_text', 'list', 'image', 'link', 'meta' ], true ) || isset( $sources[ $field['source_path'] ] ) ) { return false; }
            $sources[ $field['source_path'] ] = true;
            $result['fields'][] = array_intersect_key( $field, array_flip( [ 'source_path', 'label', 'type' ] ) );
        }
        foreach ( $input['groups'] as $group ) {
            if ( ! is_array( $group ) || ! self::key( $group['key'] ?? null ) || isset( $groups[ $group['key'] ] ) || isset( $sources[ $group['key'] ] ) || ! self::text( $group['label'] ?? null, 200, false ) || ! is_int( $group['min'] ?? null ) || ! is_int( $group['max'] ?? null ) || $group['min'] < 0 || $group['max'] < $group['min'] || $group['max'] > 12 || ! is_array( $group['member_keys'] ?? null ) || ! $group['member_keys'] || count( $group['member_keys'] ) > 48 ) { return false; }
            $members = [];
            foreach ( $group['member_keys'] as $member ) {
                if ( ! self::key( $member ) || isset( $members[ $member ] ) || ! isset( $sources[ $group['key'] . '[].' . $member ] ) ) { return false; }
                $members[ $member ] = true;
            }
            $groups[ $group['key'] ] = $members;
            $result['groups'][] = [ 'key' => $group['key'], 'label' => $group['label'], 'min' => $group['min'], 'max' => $group['max'], 'member_keys' => array_keys( $members ) ];
        }
        foreach ( array_keys( $sources ) as $source ) {
            if ( false !== strpos( $source, '[].' ) ) { list( $group, $member ) = explode( '[].', $source, 2 ); if ( ! isset( $groups[ $group ][ $member ] ) ) { return false; } }
        }
        $protected = [];
        foreach ( $input['protected_slots'] as $slot ) {
            if ( ! is_array( $slot ) || ! self::key( $slot['key'] ?? null ) || ! self::text( $slot['label'] ?? null, 200, false ) || isset( $protected[ $slot['key'] ] ) || isset( $sources[ $slot['key'] ] ) || isset( $groups[ $slot['key'] ] ) ) { return false; }
            $protected[ $slot['key'] ] = true;
            $result['protected_slots'][] = [ 'key' => $slot['key'], 'label' => $slot['label'] ];
        }
        return $result;
    }

    private static function preview_catalog(): array {
        $common = [ 'page_heading' => [ 'Page heading', 'heading' ], 'summary' => [ 'Summary', 'rich_text' ], 'body' => [ 'Body', 'rich_text' ], 'bullets' => [ 'Bullets', 'list' ], 'hero_image' => [ 'Hero image', 'image' ], 'cta' => [ 'Call to action', 'link' ], 'seo_title' => [ 'SEO title', 'meta' ], 'seo_description' => [ 'SEO description', 'meta' ], 'slug' => [ 'Slug', 'meta' ] ];
        $families = [ 'informative' => [ 'sections', 'section', 0, 12, 'faq_block' ], 'category' => [ 'items', 'item', 0, 12, 'filters_block' ], 'service' => [ 'steps', 'step', 2, 6, 'faq_block' ] ];
        $templates = [];
        foreach ( $families as $family => $spec ) {
            $fields = [];
            foreach ( $common as $key => $details ) { $fields[] = [ 'source_path' => $key, 'label' => $details[0], 'type' => $details[1] ]; }
            $members = [ $spec[1] . '_heading', $spec[1] . '_body' ];
            $fields[] = [ 'source_path' => $spec[0] . '[].' . $members[0], 'label' => ucfirst( $spec[1] ) . ' heading', 'type' => 'heading' ];
            $fields[] = [ 'source_path' => $spec[0] . '[].' . $members[1], 'label' => ucfirst( $spec[1] ) . ' body', 'type' => 'rich_text' ];
            $templates[] = [ 'id' => 'preview-' . $family, 'revision' => 'preview-1', 'label' => ucfirst( $family ) . ' (local preview)', 'family' => $family, 'fields' => $fields, 'groups' => [ [ 'key' => $spec[0], 'label' => ucfirst( $spec[0] ), 'min' => $spec[2], 'max' => $spec[3], 'member_keys' => $members ] ], 'protected_slots' => [ [ 'key' => $spec[4], 'label' => 'faq_block' === $spec[4] ? 'FAQ block' : 'Filters block' ] ] ];
        }
        return [ 'origin' => 'preview', 'templates' => $templates, 'message' => 'Illustrative local preview based on the handover. IDs and displayed field types are not a canonical NOVA API contract. Nothing is synchronized or activated.' ];
    }

    private static function reference( array $input ) {
        $type = $input['reference_type'] ?? null; $id = $input['reference_id'] ?? null;
        if ( ! in_array( $type, [ 'post', 'term' ], true ) || ! ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) || (int) $id < 1 || ! self::text( $input['signature'] ?? null, 128, false ) ) { return self::error( 'reference', 'Supply a concrete reference and layout signature.' ); }
        $entity = Nova_Bridge_Suite_Strategy::entity( $type, (int) $id );
        if ( ! $entity ) { return self::error( 'reference', 'This reference is unavailable or cannot be edited.', 404 ); }
        $fingerprint = Nova_Bridge_Suite_Strategy::fingerprint( $entity );
        return [ 'entity' => $entity, 'signature' => $fingerprint['signature'], 'native_template' => $fingerprint['template'] ?? '', 'requested_signature' => $input['signature'] ];
    }

    private static function option_name( array $reference ): string {
        // Keep the previous draft discoverable when this reference's structure changes.
        return self::OPTION_PREFIX . hash( 'sha256', $reference['entity']['reference_type'] . ':' . $reference['entity']['reference_id'] );
    }

    /** Read the database directly; a cached option cannot be the basis of compare-and-swap. */
    private static function stored( string $name ): array {
        global $wpdb;
        $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
        if ( null === $raw && ! empty( $wpdb->last_error ) ) { return [ 'error' => self::error( 'storage_unavailable', 'Local draft storage is temporarily unavailable. No changes were saved.', 500 ) ]; }
        $value = null === $raw ? null : maybe_unserialize( $raw );
        return [ 'raw' => $raw, 'draft' => is_array( $value ) ? $value : null ];
    }

    private static function store( string $name, $previous, array $draft ) {
        global $wpdb;
        $value = maybe_serialize( $draft );
        if ( null === $previous ) {
            $changed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
        } else {
            $changed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s, autoload = 'no' WHERE option_name = %s AND BINARY option_value = BINARY %s", $value, $name, $previous ) );
        }
        if ( false === $changed ) { return self::error( 'storage_unavailable', 'Local draft storage is temporarily unavailable. No changes were saved.', 500 ); }
        if ( 1 !== $changed ) { return self::error( 'conflict', 'This draft changed elsewhere. Reload and reconcile your changes; nothing was overwritten.', 409 ); }
        wp_cache_delete( $name, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
        return true;
    }

    public static function get_response( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        $reference = self::reference( [ 'reference_type' => $request->get_param( 'reference_type' ), 'reference_id' => $request->get_param( 'reference_id' ), 'signature' => $request->get_param( 'signature' ) ] );
        if ( is_wp_error( $reference ) ) { return $reference; }
        $stored = self::stored( self::option_name( $reference ) );
        if ( isset( $stored['error'] ) ) { return $stored['error']; }
        $mode = $request->get_param( 'mode' ) ?: ( $stored['draft']['catalog_mode'] ?? 'nova' );
        if ( ! in_array( $mode, [ 'preview', 'nova' ], true ) ) { return self::error( 'mode', 'Choose preview or nova catalog mode.' ); }
        return self::draft_response( $stored['draft'], $reference, self::catalog( $mode ) );
    }

    private static function descriptor( string $path, array $field, array $reference ): array {
        $result = [ 'path' => $path, 'reference_type' => $reference['entity']['reference_type'], 'reference_id' => $reference['entity']['reference_id'], 'signature' => $reference['signature'] ];
        foreach ( [ 'route', 'transport', 'request_path', 'builder', 'write_mode', 'acf_key', 'binding', 'source', 'parent_path', 'element', 'logical_field' ] as $key ) {
            if ( isset( $field[ $key ] ) && self::text( $field[ $key ], 2000 ) ) { $result[ $key ] = $field[ $key ]; }
        }
        if ( isset( $field['selector_data'] ) && is_array( $field['selector_data'] ) && strlen( (string) wp_json_encode( $field['selector_data'] ) ) <= 8192 ) { $result['selector_data'] = $field['selector_data']; }
        return $result;
    }

    private static function exact_template( array $catalog, array $identity ) {
        foreach ( $catalog['templates'] as $template ) { if ( $template['id'] === $identity['id'] && $template['revision'] === $identity['revision'] ) { return $template; } }
        return null;
    }

    public static function save_response( $request ) {
        $permission = self::can_admin();
        if ( is_wp_error( $permission ) ) { return $permission; }
        if ( strlen( (string) $request->get_body() ) > self::MAX_BYTES ) { return self::error( 'size', 'The draft exceeds 256 KiB.' ); }
        $input = $request->get_json_params();
        if ( ! is_array( $input ) || ! self::text( $input['expected_revision'] ?? null, 100 ) ) { return self::error( 'revision', 'Supply expected_revision; use an empty string for a new draft.' ); }
        $reference = self::reference( $input );
        if ( is_wp_error( $reference ) ) { return $reference; }
        if ( $reference['signature'] !== $reference['requested_signature'] ) { return self::error( 'reference_changed', 'The reference layout changed. Your saved draft is retained; refresh and reconcile it before saving.', 409 ); }
        $name = self::option_name( $reference ); $stored = self::stored( $name );
        if ( isset( $stored['error'] ) ) { return $stored['error']; }
        $previous = $stored['draft'];
        if ( ( $previous['revision'] ?? '' ) !== $input['expected_revision'] || ( null !== $stored['raw'] && null === $previous ) ) { return self::error( 'conflict', 'This draft changed elsewhere. Reload and reconcile your changes; nothing was overwritten.', 409 ); }
        if ( $previous && $previous['signature'] !== $reference['signature'] && true !== ( $input['confirm_reference_change'] ?? false ) ) { return self::error( 'reference_changed', 'The saved draft belongs to an earlier layout. Review all targets and confirm the reference change before saving.', 409 ); }
        if ( ! in_array( $input['catalog_mode'] ?? null, [ 'preview', 'nova' ], true ) || ! is_array( $input['template'] ?? null ) || ! self::text( $input['template']['id'] ?? null, 200, false ) || ! self::text( $input['template']['revision'] ?? null, 200, false ) ) { return self::error( 'template', 'Choose an exact template and catalog mode.' ); }
        $catalog = self::catalog( $input['catalog_mode'] );
        $identity = [ 'id' => $input['template']['id'], 'revision' => $input['template']['revision'] ];
        $template = self::exact_template( $catalog, $identity );
        if ( ! $template && ( ! $previous || $previous['template'] !== $identity || $previous['catalog_mode'] !== $input['catalog_mode'] ) ) { return self::error( 'catalog_unavailable', 'That exact template revision is unavailable. Reconnect the catalog or choose an explicit local preview before creating a draft.', 409 ); }
        foreach ( [ 'label' => 200, 'guidance' => 16000 ] as $key => $limit ) { if ( ! self::text( $input[ $key ] ?? '', $limit ) ) { return self::error( 'text', 'The draft label or instructions are invalid or too long.' ); } }
        $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $reference['entity'] ), null, 'path' );
        $draft = self::validate_draft( $input, $previous, $reference, $inventory, $template );
        if ( is_wp_error( $draft ) ) { return $draft; }
        $draft['revision'] = wp_generate_uuid4();
        $draft['updated_at'] = gmdate( 'c' );
        $draft['status'] = 'preview' === $input['catalog_mode'] ? 'local_draft' : 'awaiting_backend';
        $draft['warnings'] = self::warnings( $draft, $reference, $inventory, $template );
        if ( strlen( (string) wp_json_encode( $draft ) ) > self::MAX_BYTES ) { return self::error( 'size', 'The normalized draft exceeds 256 KiB.' ); }
        $saved = self::store( $name, $stored['raw'], $draft );
        if ( is_wp_error( $saved ) ) { return $saved; }
        return self::draft_response( $draft, $reference, $catalog );
    }

    private static function validate_draft( array $input, $previous, array $reference, array $inventory, $template ) {
        $fields = $input['fields'] ?? []; $skips = $input['skipped_sources'] ?? []; $repeats = $input['repeat_slots'] ?? []; $routing = $input['routing'] ?? [];
        if ( ! is_array( $fields ) || count( $fields ) > 500 || ! is_array( $skips ) || count( $skips ) > 144 || ! is_array( $repeats ) || count( $repeats ) > 8 || ! is_array( $routing ) || ! in_array( $routing['operation'] ?? null, [ 'update', 'clone' ], true ) || ! self::text( $routing['locale'] ?? '', 64 ) || ( '' !== ( $routing['locale'] ?? '' ) && ! preg_match( '/^[A-Za-z0-9_-]+$/D', $routing['locale'] ) ) ) { return self::error( 'shape', 'Supply bounded fields, skipped sources, fixed repeat slots and valid routing.' ); }
        if ( 'term' === $reference['entity']['reference_type'] && 'clone' === $routing['operation'] ) { return self::error( 'routing', 'Category references support update drafts only; category cloning is not implemented.' ); }
        $publication = $routing['publication'] ?? ( 'clone' === $routing['operation'] ? 'draft' : 'preserve' );
        if ( ! in_array( $publication, [ 'preserve', 'publish', 'draft' ], true ) ) { return self::error( 'routing', 'Choose a valid publication policy.' ); }
        $draft = [ 'schema_version' => 1, 'signature' => $reference['signature'], 'reference_type' => $reference['entity']['reference_type'], 'reference_id' => $reference['entity']['reference_id'], 'template' => [ 'id' => $input['template']['id'], 'revision' => $input['template']['revision'] ], 'catalog_mode' => $input['catalog_mode'], 'label' => sanitize_text_field( $input['label'] ?? '' ), 'guidance' => sanitize_textarea_field( $input['guidance'] ?? '' ), 'fields' => [], 'skipped_sources' => [], 'repeat_slots' => [], 'routing' => [ 'operation' => $routing['operation'], 'locale' => $routing['locale'] ?? '' ], 'target_descriptors' => [] ];
        $snapshot = $template ?: ( $previous['catalog_snapshot'] ?? null );
        $draft['catalog_snapshot'] = $snapshot;
        $draft['routing']['post_type'] = $reference['entity']['post_type'];
        $draft['routing']['publication'] = $publication;
        $draft['routing']['native_template'] = $reference['native_template'];
        // A cached definition is only an editing aid; warnings still report the live catalog unavailable.
        $known_sources = $snapshot ? array_column( $snapshot['fields'], null, 'source_path' ) : [];
        if ( ! $snapshot ) {
            foreach ( $previous['fields'] ?? [] as $old ) { if ( ! empty( $old['source_path'] ) ) { $known_sources[ $old['source_path'] ] = true; } }
            foreach ( $previous['skipped_sources'] ?? [] as $old ) { $known_sources[ $old['source_path'] ] = true; }
            foreach ( $previous['repeat_slots'] ?? [] as $group_key => $slots ) { foreach ( $slots as $slot ) { foreach ( $slot['targets'] as $member => $path ) { $known_sources[ $group_key . '[].' . $member ] = true; } } }
        }
        $discarded = [];
        if ( ! is_array( $input['discard_stale_targets'] ?? [] ) || count( $input['discard_stale_targets'] ?? [] ) > 500 ) { return self::error( 'stale_discard', 'Supply a bounded list of explicitly discarded stale targets.' ); }
        foreach ( $input['discard_stale_targets'] ?? [] as $path ) {
            if ( ! Nova_Bridge_Suite_Strategy::valid_pointer( $path ) || ! isset( $previous['target_descriptors'][ $path ] ) || isset( $inventory[ $path ] ) ) { return self::error( 'stale_discard', 'Only a previously saved target missing from the current inventory can be explicitly discarded.' ); }
            $discarded[ $path ] = true;
        }
        $used_sources = []; $used_targets = []; $protected = []; $protected_slots = [];
        $known_protected = $snapshot ? array_column( $snapshot['protected_slots'], null, 'key' ) : [];
        foreach ( $fields as $path => $field ) {
            if ( ! Nova_Bridge_Suite_Strategy::valid_pointer( $path ) || ! is_array( $field ) || ! in_array( $field['mode'] ?? null, [ 'mapped', 'leave_empty', 'protected' ], true ) || ! self::text( $field['instructions'] ?? '', 8000 ) ) { return self::error( 'field', 'A selected field has an invalid path, mode or instructions.' ); }
            $source = $field['source_path'] ?? '';
            if ( isset( $discarded[ $path ] ) ) { return self::error( 'stale_discard', 'A target cannot be retained and explicitly discarded in the same draft.' ); }
            if ( 'mapped' === $field['mode'] ) {
                if ( ! self::source_path( $source ) || ( null !== $known_sources && ! isset( $known_sources[ $source ] ) ) ) { return self::error( 'source', 'A mapping uses a source absent from the exact selected template revision.' ); }
                if ( false !== strpos( $source, '[].' ) ) { return self::error( 'repeat_source', 'Map repeat-member sources through ordered fixed existing slots, not an individual field mapping.' ); }
                $used_sources[ $source ] = true;
            } elseif ( '' !== $source ) { return self::error( 'source', 'Protected and omitted fields must not have generated source paths.' ); }
            $old = $previous['fields'][ $path ] ?? null;
            if ( ! isset( $inventory[ $path ] ) ) {
                if ( ! $old || $old['mode'] !== $field['mode'] || $old['source_path'] !== $source ) { return self::error( 'stale_target', 'A target is no longer discovered. Its saved selection must be retained until the reference is reconciled.', 409 ); }
                $descriptor = $previous['target_descriptors'][ $path ] ?? [ 'path' => $path ];
            } else {
                if ( 'mapped' === $field['mode'] && empty( $inventory[ $path ]['writable'] ) && ( ! $old || $old['mode'] !== 'mapped' || $old['source_path'] !== $source ) ) { return self::error( 'unwritable', 'This target has no discovered writer. Choose a supported target.' ); }
                $descriptor = self::descriptor( $path, $inventory[ $path ], $reference );
            }
            $draft['fields'][ $path ] = [ 'mode' => $field['mode'], 'source_path' => $source, 'instructions' => sanitize_textarea_field( $field['instructions'] ?? '' ), 'binding' => $descriptor['binding'] ?? '' ];
            $protected_slot = $field['protected_slot'] ?? '';
            if ( ! is_string( $protected_slot ) || ( '' !== $protected_slot && ( 'protected' !== $field['mode'] || ! isset( $known_protected[ $protected_slot ] ) || isset( $protected_slots[ $protected_slot ] ) ) ) ) { return self::error( 'protected_slot', 'Each canonical protected region must bind one native target from the selected template.' ); }
            if ( '' !== $protected_slot ) { $draft['fields'][ $path ]['protected_slot'] = $protected_slot; $protected_slots[ $protected_slot ] = true; }
            $draft['target_descriptors'][ $path ] = $descriptor;
            $used_targets[ $path ] = true;
            if ( 'protected' === $field['mode'] ) { $protected[] = $path; }
        }
        foreach ( $previous['fields'] ?? [] as $path => $field ) {
            if ( ! isset( $inventory[ $path ] ) && ! isset( $draft['fields'][ $path ] ) && ! isset( $discarded[ $path ] ) ) { return self::error( 'stale_drop', 'A missing saved target was removed from this request. Its mapping has been retained; reconcile the stale target explicitly.', 409 ); }
        }
        $groups = $snapshot ? array_column( $snapshot['groups'], null, 'key' ) : null;
        $slot_ids = [];
        foreach ( $repeats as $group_key => $slots ) {
            if ( ! self::key( $group_key ) || ! is_array( $slots ) || count( $slots ) > 12 || ( null !== $groups && ! isset( $groups[ $group_key ] ) ) ) { return self::error( 'repeat', 'Use a known repeat group with at most twelve existing slots.' ); }
            $group = $groups[ $group_key ] ?? null;
            if ( $group && count( $slots ) > $group['max'] ) { return self::error( 'repeat_limit', 'The selected repeat group has fewer allowed slots.' ); }
            $draft['repeat_slots'][ $group_key ] = [];
            foreach ( $slots as $slot ) {
                if ( ! is_array( $slot ) || ! is_string( $slot['id'] ?? null ) || ! preg_match( '/^[A-Za-z0-9_-]{1,80}$/D', $slot['id'] ) || isset( $slot_ids[ $slot['id'] ] ) || ! is_array( $slot['targets'] ?? null ) || count( $slot['targets'] ) > 48 ) { return self::error( 'repeat_slot', 'Existing slots need unique stable IDs and a member-target map.' ); }
                $slot_ids[ $slot['id'] ] = true; $targets = [];
                foreach ( $slot['targets'] as $member => $path ) {
                    $source = $group_key . '[].' . $member;
                    if ( ! self::key( $member ) || ! Nova_Bridge_Suite_Strategy::valid_pointer( $path ) || isset( $used_targets[ $path ] ) || isset( $discarded[ $path ] ) || ( null !== $known_sources && ! isset( $known_sources[ $source ] ) ) ) { return self::error( 'repeat_target', 'Repeat members require distinct known targets and source paths.' ); }
                    if ( ! isset( $inventory[ $path ] ) || empty( $inventory[ $path ]['writable'] ) ) {
                        $old_slot = self::find_slot( $previous['repeat_slots'][ $group_key ] ?? [], $slot['id'] );
                        if ( ! $old_slot || ( $old_slot['targets'][ $member ] ?? null ) !== $path ) { return self::error( 'repeat_target', 'Select an existing writable native target for each repeat member.' ); }
                        $descriptor = $previous['target_descriptors'][ $path ] ?? [ 'path' => $path ];
                    } else { $descriptor = self::descriptor( $path, $inventory[ $path ], $reference ); }
                    $targets[ $member ] = $path; $used_targets[ $path ] = true; $used_sources[ $source ] = true;
                    $draft['target_descriptors'][ $path ] = $descriptor;
                }
                $draft['repeat_slots'][ $group_key ][] = [ 'id' => $slot['id'], 'targets' => $targets ];
            }
        }
        foreach ( $previous['repeat_slots'] ?? [] as $group_key => $slots ) {
            foreach ( $slots as $old_slot ) {
                $slot = self::find_slot( $draft['repeat_slots'][ $group_key ] ?? [], $old_slot['id'] );
                foreach ( $old_slot['targets'] as $member => $path ) { if ( ! isset( $inventory[ $path ] ) && ! isset( $discarded[ $path ] ) && ( ! $slot || ( $slot['targets'][ $member ] ?? null ) !== $path ) ) { return self::error( 'stale_drop', 'A missing saved repeat target cannot be silently discarded.', 409 ); } }
            }
        }
        $seen_skips = [];
        foreach ( $skips as $skip ) {
            if ( ! is_array( $skip ) || ! self::source_path( $skip['source_path'] ?? null ) || ! self::text( $skip['reason'] ?? null, 8000, false ) || isset( $seen_skips[ $skip['source_path'] ] ) || isset( $used_sources[ $skip['source_path'] ] ) || ( null !== $known_sources && ! isset( $known_sources[ $skip['source_path'] ] ) ) ) { return self::error( 'skip', 'Skipped sources need a unique unmapped source and an explicit reason.' ); }
            $seen_skips[ $skip['source_path'] ] = true;
            $reason = sanitize_textarea_field( $skip['reason'] );
            if ( '' === trim( $reason ) ) { return self::error( 'skip', 'Explain why this NOVA field is skipped.' ); }
            $draft['skipped_sources'][] = [ 'source_path' => $skip['source_path'], 'reason' => $reason ];
        }
        foreach ( $protected as $protected_path ) {
            foreach ( $draft['target_descriptors'] as $path => $descriptor ) {
                $mode = $draft['fields'][ $path ]['mode'] ?? 'mapped';
                $may_write = 'mapped' === $mode || ( 'clone' === $routing['operation'] && 'leave_empty' === $mode );
                if ( $path === $protected_path || ! $may_write ) { continue; }
                if ( self::overlaps( $draft['target_descriptors'][ $protected_path ], $descriptor ) ) { return self::error( 'protected_overlap', 'A generated target overlaps a protected target or its complete-parent writer. Choose disjoint fields.' ); }
            }
        }
        return $draft;
    }

    private static function find_slot( array $slots, string $id ) {
        foreach ( $slots as $slot ) { if ( $slot['id'] === $id ) { return $slot; } }
        return null;
    }

    private static function ancestor( string $first, string $second ): bool {
        return $first === $second || 0 === strpos( $second, rtrim( $first, '/' ) . '/' );
    }

    /** Conservative overlap checks for addresses we can resolve without executing a writer. */
    private static function overlaps( array $first, array $second ): bool {
        if ( self::ancestor( $first['path'], $second['path'] ) || self::ancestor( $second['path'], $first['path'] ) ) { return true; }
        if ( ! empty( $first['binding'] ) && $first['binding'] === ( $second['binding'] ?? '' ) ) { return true; }
        if ( ! empty( $first['acf_key'] ) && $first['acf_key'] === ( $second['acf_key'] ?? '' ) && ! ( 'existing_leaf' === ( $first['write_mode'] ?? '' ) && 'existing_leaf' === ( $second['write_mode'] ?? '' ) && ( $first['request_path'] ?? null ) !== ( $second['request_path'] ?? null ) ) ) { return true; }
        if ( ! empty( $first['selector_data'] ) || ! empty( $second['selector_data'] ) ) {
            // Builder request paths describe a mutation envelope shared by distinct widgets.
            if ( empty( $first['builder'] ) || $first['builder'] !== ( $second['builder'] ?? null ) ) { return false; }
            if ( ( $first['selector_data'] ?? null ) === ( $second['selector_data'] ?? null ) ) { return true; }
            $left = $first['selector_data']['field_key'] ?? ''; $right = $second['selector_data']['field_key'] ?? '';
            return $left && $right && ( $left === $right || 0 === strpos( $right, $left . '.' ) || 0 === strpos( $left, $right . '.' ) );
        }
        $left = $first['request_path'] ?? ''; $right = $second['request_path'] ?? '';
        if ( $left && $right && ( $first['route'] ?? null ) === ( $second['route'] ?? null ) && ( self::ancestor( $left, $right ) || self::ancestor( $right, $left ) ) ) { return true; }
        return false;
    }

    private static function warnings( array $draft, array $reference, array $inventory, $template ): array {
        $warnings = [ 'Local draft only: no mapping has been synchronized, sealed, activated or published.' ];
        if ( 'preview' === $draft['catalog_mode'] ) { $warnings[] = 'This draft uses illustrative preview IDs and field types. It must be reviewed against a real NOVA template before synchronization.'; }
        if ( ! $template ) { $warnings[] = 'The exact NOVA template revision is unavailable; source compatibility and coverage cannot be validated. Saved selections are retained.'; }
        if ( $reference['signature'] !== $draft['signature'] ) { $warnings[] = 'The reference layout has changed. Saved native targets are stale and require reconciliation.'; }
        $covered = [];
        foreach ( $draft['fields'] as $path => $field ) {
            if ( 'mapped' === $field['mode'] ) { $covered[ $field['source_path'] ] = true; }
            if ( 'protected' === $field['mode'] && empty( $field['protected_slot'] ) ) { $warnings[] = 'Protected target ' . $path . ' is an additional native guard. Associate a canonical protected region if this is the template component.'; }
        }
        foreach ( $draft['repeat_slots'] as $key => $slots ) { foreach ( $slots as $slot ) { foreach ( $slot['targets'] as $member => $path ) { $covered[ $key . '[].' . $member ] = true; } } }
        foreach ( $draft['target_descriptors'] as $path => $descriptor ) {
            if ( ! isset( $inventory[ $path ] ) ) { $warnings[] = 'Saved target is no longer discovered and has been retained: ' . $path; }
            elseif ( empty( $inventory[ $path ]['writable'] ) && ( ! isset( $draft['fields'][ $path ] ) || 'mapped' === $draft['fields'][ $path ]['mode'] ) ) { $warnings[] = 'Saved target currently has no writable transport: ' . $path; }
            if ( 'complete_parent' === ( $descriptor['write_mode'] ?? '' ) ) { $warnings[] = 'Nested target ' . $path . ' requires a complete-parent write; preservation is uncertified and publishing is not enabled.'; }
        }
        $skipped = [];
        foreach ( $draft['skipped_sources'] as $skip ) { $skipped[ $skip['source_path'] ] = true; }
        if ( $skipped ) { $warnings[] = 'Explicitly skipped NOVA fields: ' . implode( ', ', array_keys( $skipped ) ) . '. The backend may refuse sealing because it currently requires complete generated-source coverage.'; }
        if ( $template ) {
            $missing = [];
            foreach ( $template['fields'] as $field ) { if ( ! isset( $covered[ $field['source_path'] ] ) && ! isset( $skipped[ $field['source_path'] ] ) ) { $missing[] = $field['source_path']; } }
            if ( $missing ) { $warnings[] = 'Unmapped NOVA fields: ' . implode( ', ', $missing ) . '. This incomplete draft can be saved locally.'; }
            foreach ( $template['groups'] as $group ) {
                $slots = $draft['repeat_slots'][ $group['key'] ] ?? [];
                if ( count( $slots ) < $group['min'] ) { $warnings[] = 'Repeat group ' . $group['key'] . ' has fewer existing slots than the template minimum. No native rows will be created.'; }
                foreach ( $slots as $slot ) { if ( array_diff( $group['member_keys'], array_keys( $slot['targets'] ) ) ) { $warnings[] = 'Repeat slot ' . $slot['id'] . ' is incomplete; assign its remaining member fields.'; } }
            }
        }
        $warnings[] = 'Type compatibility, physical storage ownership and writer safety require backend and provider validation before publication.';
        return array_values( array_unique( $warnings ) );
    }

    private static function draft_response( $draft, array $reference, array $catalog ) {
        $warnings = []; $handoff = null;
        if ( $draft ) {
            $inventory = array_column( Nova_Bridge_Suite_Strategy::field_inventory( $reference['entity'] ), null, 'path' );
            $warnings = self::warnings( $draft, $reference, $inventory, self::exact_template( $catalog, $draft['template'] ) );
            $draft['warnings'] = $warnings;
            $bindings = [];
            foreach ( $draft['fields'] as $path => $field ) { $bindings[] = [ 'mode' => $field['mode'], 'source_path' => $field['source_path'], 'instructions' => $field['instructions'], 'target' => $draft['target_descriptors'][ $path ] ]; }
            $handoff = [ 'format' => 'nova_bridge_local_mapping_draft_v1', 'local_only' => true, 'verified' => false, 'synchronized' => false, 'activation' => 'not_requested', 'reference_type' => $draft['reference_type'], 'reference_id' => $draft['reference_id'], 'signature' => $draft['signature'], 'catalog_mode' => $draft['catalog_mode'], 'template' => $draft['template'], 'label' => $draft['label'], 'guidance' => $draft['guidance'], 'bindings' => $bindings, 'skipped_sources' => $draft['skipped_sources'], 'repeat_slots' => $draft['repeat_slots'], 'target_descriptors' => $draft['target_descriptors'], 'routing' => $draft['routing'], 'warnings' => $warnings ];
        }
        if ( $reference['requested_signature'] !== $reference['signature'] && ! $draft ) { $warnings[] = 'The requested layout signature is stale. Refresh the reference before creating a draft.'; }
        return self::response( [ 'draft' => $draft, 'handoff' => $handoff, 'catalog' => $catalog, 'warnings' => $warnings, 'reference' => [ 'reference_type' => $reference['entity']['reference_type'], 'reference_id' => $reference['entity']['reference_id'], 'signature' => $reference['signature'] ] ] );
    }
}
