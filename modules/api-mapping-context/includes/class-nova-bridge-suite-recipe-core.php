<?php
/** Native WordPress content fields, including taxonomy content without drafts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Recipe_Core {
    private $taxonomy_scope = false;
    public static function register(): void { Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'core', new self() ); }
    public static function design( array $resource, int $id = 0, string $mode = 'mixed', array $declared_fields = [] ) {
        $term = 'taxonomy' === $resource['type']; $entity = $id ? ( $term ? get_term( $id, $resource['taxonomy'] ) : get_post( $id ) ) : null;
        if ( $id && ( ! $entity || is_wp_error( $entity ) ) ) { return new WP_Error( 'nova_recipe_core', 'The WordPress reference no longer exists.', [ 'status' => 404 ] ); }
        $blueprint = [ 'document' => [ 'title' => $entity ? ( $term ? $entity->name : $entity->post_title ) : '', 'intro' => '', 'sections' => [ [ 'heading' => '', 'body' => $entity ? ( $term ? $entity->description : $entity->post_content ) : '<p>Example section content.</p>' ] ], 'faqs' => [ [ 'question' => '', 'answer' => '' ] ] ], 'resource_id' => $resource['id'], 'type' => $term ? 'taxonomy' : 'post_type', 'post_type' => $resource['post_type'] ?? '', 'taxonomy' => $resource['taxonomy'] ?? '' ];
        $blueprint['template'] = ! $term && $id ? (string) get_page_template_slug( $id ) : '';
        if ( $term && class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ) {
            $extra = Nova_Bridge_Suite_Recipe_Term_Fields::capture( $resource, $id, $declared_fields );
            if ( is_wp_error( $extra ) ) { return $extra; }
            $blueprint['term_fields'] = $extra['term_fields'];
        }
        $custom_bindings = [];
        if ( isset( $blueprint['term_fields'] ) ) { foreach ( Nova_Bridge_Suite_Recipe_Term_Fields::describe( $blueprint )['fields'] as $field ) { if ( ! empty( $field['writable'] ) ) { $custom_bindings[] = [ 'target' => $field['target'], 'source' => 'content_below_products' === ( $field['custom_field_key'] ?? '' ) ? '/bottom_content' : '/custom/field_' . substr( hash( 'sha256', $field['target'] ), 0, 16 ) ]; } } }
        if ( $id || $term ) {
            $blueprint['document'] = [ 'title' => $entity ? ( $term ? $entity->name : $entity->post_title ) : '', 'sections' => [ [ 'body' => $entity ? ( $term ? $entity->description : $entity->post_content ) : '' ] ], 'faqs' => [] ];
            return [ 'blueprint' => $blueprint, 'suggested_bindings' => array_merge( [ [ 'target' => '/document/title', 'source' => '/title' ], [ 'target' => '/document/sections/0/body', 'source' => $term ? '/top_content' : '/body' ] ], $custom_bindings ),
                'suggested_regions' => 'static' === $mode ? [] : [ [ 'target' => '/document/sections', 'start' => 0, 'count' => 1, 'source' => '/sections', 'label' => 'Complete content field', 'kind' => 'repeat' ] ] ] + self::describe( $blueprint );
        }
        if ( 'static' === $mode ) { $blueprint['document']['faqs'] = []; }
        return [ 'blueprint' => $blueprint, 'suggested_bindings' => [ [ 'target' => '/document/title', 'source' => '/title' ], [ 'target' => '/document/intro', 'source' => '/intro' ], [ 'target' => '/document/sections/0/body', 'source' => '/body' ] ], 'suggested_regions' => 'static' === $mode ? [] : [ [ 'target' => '/document/sections', 'start' => 0, 'count' => 1, 'source' => '/sections', 'label' => 'Article sections', 'kind' => 'repeat' ], [ 'target' => '/document/faqs', 'start' => 0, 'count' => 1, 'source' => '/faqs', 'label' => 'Frequently asked questions', 'kind' => 'repeat' ] ], 'warnings' => $id ? [ 'This reads the WordPress content field. To preserve a page builder design, choose that builder instead.' ] : [] ] + self::describe( $blueprint );
    }
    public static function describe( array $blueprint ): array {
        $term = 'taxonomy' === ( $blueprint['type'] ?? '' );
        $fields = [ [ 'target' => '/document/title', 'label' => $term ? 'Category title' : 'Page or category title', 'format' => 'text', 'kind' => 'heading', 'context' => $term ? 'taxonomy' : 'post', 'writable' => true ] ]; $containers = [];
        if ( array_key_exists( 'intro', $blueprint['document'] ) ) { $fields[] = [ 'target' => '/document/intro', 'label' => 'Introduction', 'format' => 'html', 'kind' => 'body', 'writable' => true ]; }
        foreach ( [ 'sections' => [ 'heading' => 'text', 'body' => 'html' ], 'faqs' => [ 'question' => 'text', 'answer' => 'html' ] ] as $collection => $columns ) { $items = []; foreach ( $blueprint['document'][$collection] ?? [] as $index => $row ) { $items[] = [ 'index' => $index, 'label' => ucfirst( $collection ) . ' example ' . ( $index + 1 ) ]; foreach ( $columns as $key => $format ) { if ( ! array_key_exists( $key, $row ) ) { continue; } $fields[] = [ 'target' => '/document/' . $collection . '/' . $index . '/' . $key, 'label' => ucfirst( $key ) . ' in ' . $collection . ' ' . ( $index + 1 ), 'format' => $format, 'kind' => $key, 'writable' => true ]; } } if ( $items ) { $containers[] = [ 'target' => '/document/' . $collection, 'label' => ucfirst( $collection ), 'items' => $items ]; } }
        if ( $term ) { foreach ( $fields as &$field ) { $field['context'] = 'taxonomy'; if ( '/document/sections/0/body' === $field['target'] ) { $field['label'] = 'Top category content'; $field['kind'] = 'top_content'; } } unset( $field ); }
        $result = [ 'fields' => $fields, 'containers' => $containers, 'warnings' => $term ? [ 'Categories have no draft status. Review the translated field values here; this wizard does not publish a category.' ] : [] ];
        if ( isset( $blueprint['term_fields'] ) && class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ) { $extra = Nova_Bridge_Suite_Recipe_Term_Fields::describe( $blueprint ); if ( is_wp_error( $extra ) ) { return $result; } foreach ( [ 'fields', 'containers', 'warnings' ] as $part ) { $result[$part] = array_merge( $result[$part], $extra[$part] ?? [] ); } $result['excluded_fields'] = $extra['excluded_fields'] ?? []; $result['declared_term_fields'] = $blueprint['term_fields']['local_declarations'] ?? []; }
        if ( $term ) { foreach ( $result['fields'] as &$field ) { $field['context'] = 'taxonomy'; } unset( $field ); }
        return $result;
    }
    public function validate_blueprint( array $blueprint ) { if ( ! is_array( $blueprint['document'] ?? null ) || ! is_array( $blueprint['document']['sections'] ?? null ) || ! is_array( $blueprint['document']['faqs'] ?? null ) ) { return new WP_Error( 'nova_recipe_core', 'A complete WordPress content blueprint is required.', [ 'status' => 422 ] ); } $this->taxonomy_scope = 'taxonomy' === ( $blueprint['type'] ?? '' ); if ( isset( $blueprint['term_fields'] ) ) { if ( ! class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ) { return new WP_Error( 'nova_recipe_core', 'The declared category-field provider is unavailable.', [ 'status' => 409 ] ); } $checked = Nova_Bridge_Suite_Recipe_Term_Fields::validate_blueprint( $blueprint ); if ( is_wp_error( $checked ) ) { return $checked; } } return $blueprint; }
    public function preserve_missing_binding( array $native, array $binding, array $context = [] ): bool { return $this->taxonomy_scope; }
    public function validate_binding( array $native, array $binding ) { if ( class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) && Nova_Bridge_Suite_Recipe_Term_Fields::owns( $binding['target'] ) ) { return Nova_Bridge_Suite_Recipe_Term_Fields::validate_binding( $native, $binding ); } $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $binding['target'], $found ); return $found && ( is_scalar( $value ) || ( null === $value && ! isset( $native['document'] ) ) ) ? true : new WP_Error( 'nova_recipe_core', 'Bind an existing WordPress content field.', [ 'status' => 422 ] ); }
    public function validate_region( array $native, array $region ) { if ( class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) && Nova_Bridge_Suite_Recipe_Term_Fields::owns( $region['target'] ) ) { return Nova_Bridge_Suite_Recipe_Term_Fields::validate_region( $native, $region ); } $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'], $found ); return $found && Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ? true : new WP_Error( 'nova_recipe_core', 'Choose a declared WordPress content collection.', [ 'status' => 422 ] ); }
    public function validate_plan_scope( array $native, array $bindings, array $regions ) { return isset( $native['term_fields'] ) && class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ? Nova_Bridge_Suite_Recipe_Term_Fields::validate_plan_scope( $native, $bindings, $regions ) : true; }
    public function augment_content_schema( array $recipe, array $schema ) { return isset( $recipe['blueprint']['term_fields'] ) && class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) ? Nova_Bridge_Suite_Recipe_Term_Fields::augment_content_schema( $recipe, $schema ) : $schema; }
    public function instantiate( array $prototype, string $seed, array $context = [] ) { return [ 'native' => $prototype, 'context' => $context ]; }
    public function bind( array $native, array $binding, $value, array $context = [] ) { if ( class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) && Nova_Bridge_Suite_Recipe_Term_Fields::owns( $binding['target'] ) ) { return Nova_Bridge_Suite_Recipe_Term_Fields::bind( $native, $binding, $value ); } Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); return $native; }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) { if ( class_exists( 'Nova_Bridge_Suite_Recipe_Term_Fields' ) && Nova_Bridge_Suite_Recipe_Term_Fields::owns( $region['target'] ) ) { return Nova_Bridge_Suite_Recipe_Term_Fields::replace_region( $native, $region, $children ); } Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native; }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $doc = $blueprint['document']; $html = wp_kses_post( $doc['intro'] ?? '' );
        foreach ( $doc['sections'] ?? [] as $index => $section ) {
            $body = $section['body'] ?? '';
            $original = $context['recipe']['blueprint']['document']['sections'][$index]['body'] ?? null;
            $unchanged = ! array_key_exists( 'intro', $doc ) && [] === $doc['faqs'] && $body === $original;
            $html .= ( '' !== ( $section['heading'] ?? '' ) ? '<h2>' . esc_html( $section['heading'] ) . '</h2>' : '' ) . ( $unchanged ? $body : wp_kses_post( $body ) );
        }
        if ( $doc['faqs'] ?? [] ) { $html .= '<section class="nova-faq"><h2>Frequently asked questions</h2>'; foreach ( $doc['faqs'] as $faq ) { $html .= '<details><summary>' . esc_html( $faq['question'] ) . '</summary>' . wp_kses_post( $faq['answer'] ) . '</details>'; } $html .= '</section>'; }
        $result = [ 'native_document' => $doc, 'writer_payload' => 'taxonomy' === $blueprint['type'] ? [ 'name' => $doc['title'], 'description' => $html ] : [ 'title' => $doc['title'], 'content' => $html, 'template' => $blueprint['template'] ?? '' ], 'preview_html' => $html, 'warnings' => [], 'summary' => [ 'sections' => count( $doc['sections'] ), 'faq_items' => count( $doc['faqs'] ) ] ];
        if ( 'taxonomy' === $blueprint['type'] ) {
            // Omitted optional input must not copy an example category's value into another item.
            $mapped = []; $present = []; $content = $context['content'] ?? []; $recipe = $context['recipe'] ?? [];
            foreach ( $recipe['bindings'] ?? [] as $binding ) { if ( 0 !== strpos( $binding['target'], '/document/' ) ) { continue; } $key = '/document/title' === $binding['target'] ? 'name' : 'description'; $mapped[$key] = true; Nova_Bridge_Suite_Recipe_Engine::get( $content, $binding['source'], $found ); if ( $found ) { $present[$key] = true; } }
            foreach ( $recipe['regions'] ?? [] as $region ) { if ( 0 !== strpos( $region['target'], '/document/' ) ) { continue; } $mapped['description'] = true; $value = Nova_Bridge_Suite_Recipe_Engine::get( $content, $region['source'], $found ); if ( ( $found && ( $value || 'keep' !== $region['empty'] ) ) || 'remove' === $region['empty'] ) { $present['description'] = true; } }
            foreach ( $mapped as $key => $unused ) { if ( ! isset( $present[$key] ) ) { unset( $result['writer_payload'][$key] ); } }
        }
        if ( isset( $blueprint['term_fields'] ) ) {
            $targets = []; $content = $context['content'] ?? []; $recipe = $context['recipe'] ?? [];
            foreach ( $recipe['bindings'] ?? [] as $binding ) { Nova_Bridge_Suite_Recipe_Engine::get( $content, $binding['source'], $found ); if ( $found || ! empty( $binding['required'] ) ) { $targets[] = $binding['target']; } }
            foreach ( $recipe['regions'] ?? [] as $region ) { $value = Nova_Bridge_Suite_Recipe_Engine::get( $content, $region['source'], $found ); if ( $found || 'remove' === $region['empty'] ) { if ( ! $value && 'keep' === $region['empty'] ) { continue; } $targets[] = $region['target']; } }
            $extra = Nova_Bridge_Suite_Recipe_Term_Fields::finalize( $blueprint, $recipe['blueprint'] ?? [], $targets ); if ( is_wp_error( $extra ) ) { return $extra; }
            foreach ( $extra['writer_payload'] ?? [] as $key => $value ) { if ( array_key_exists( $key, $result['writer_payload'] ) ) { return new WP_Error( 'nova_recipe_core', 'A custom category field conflicts with a native field.', [ 'status' => 409 ] ); } $result['writer_payload'][$key] = $value; }
            $result['native_document']['term_fields'] = $blueprint['term_fields']; $result['warnings'] = array_merge( $result['warnings'], $extra['warnings'] ?? [] );
            $result['summary']['custom_fields'] = count( $blueprint['term_fields']['declarations'] ?? [] );
            foreach ( self::describe( $blueprint )['fields'] as $field ) { if ( ! empty( $field['writable'] ) && 0 === strpos( $field['target'], '/term_fields/values/' ) ) { $value = Nova_Bridge_Suite_Recipe_Engine::get( $blueprint, $field['target'], $found ); if ( $found && is_scalar( $value ) ) { $result['preview_html'] .= '<section><h3>' . esc_html( $field['label'] ) . '</h3>' . ( 'html' === $field['format'] ? wp_kses_post( (string) $value ) : '<p>' . esc_html( (string) $value ) . '</p>' ) . '</section>'; } } }
        }
        return $result;
    }
    public static function write_draft( array $payload, array $post_fields ) {
        $fields = [ 'post_type' => $post_fields['post_type'], 'post_status' => 'draft', 'post_title' => $post_fields['title'], 'post_content' => $payload['content'] ?? '', 'post_author' => get_current_user_id() ]; if ( ! empty( $payload['template'] ) ) { $fields['page_template'] = $payload['template']; }
        $id = wp_insert_post( wp_slash( $fields ), true ); if ( is_wp_error( $id ) ) { return $id; }
        if ( (string) get_post_field( 'post_content', $id ) !== ( $payload['content'] ?? '' ) ) { wp_delete_post( $id, true ); return new WP_Error( 'nova_recipe_core', 'WordPress did not retain the planned native content; the new draft was removed.', [ 'status' => 500 ] ); } return new WP_REST_Response( [ 'id' => $id ], 201 );
    }
}
