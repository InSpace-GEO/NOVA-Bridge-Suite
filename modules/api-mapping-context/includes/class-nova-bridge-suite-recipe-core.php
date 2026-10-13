<?php
/** Native WordPress content fields, including taxonomy content without drafts. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Nova_Bridge_Suite_Recipe_Core {
    public static function register(): void { Nova_Bridge_Suite_Recipe_Engine::register_adapter( 'core', new self() ); }
    public static function design( array $resource, int $id = 0, string $mode = 'mixed' ) {
        $term = 'taxonomy' === $resource['type']; $entity = $id ? ( $term ? get_term( $id, $resource['taxonomy'] ) : get_post( $id ) ) : null;
        if ( $id && ( ! $entity || is_wp_error( $entity ) ) ) { return new WP_Error( 'nova_recipe_core', 'The WordPress reference no longer exists.', [ 'status' => 404 ] ); }
        $blueprint = [ 'document' => [ 'title' => $entity ? ( $term ? $entity->name : $entity->post_title ) : '', 'intro' => '', 'sections' => [ [ 'heading' => '', 'body' => $entity ? ( $term ? $entity->description : $entity->post_content ) : '<p>Example section content.</p>' ] ], 'faqs' => [ [ 'question' => '', 'answer' => '' ] ] ], 'resource_id' => $resource['id'], 'type' => $term ? 'taxonomy' : 'post_type', 'post_type' => $resource['post_type'] ?? '', 'taxonomy' => $resource['taxonomy'] ?? '' ];
        $blueprint['template'] = ! $term && $id ? (string) get_page_template_slug( $id ) : '';
        if ( $id ) {
            $blueprint['document'] = [ 'title' => $term ? $entity->name : $entity->post_title, 'sections' => [ [ 'body' => $term ? $entity->description : $entity->post_content ] ], 'faqs' => [] ];
            return [ 'blueprint' => $blueprint, 'suggested_bindings' => [ [ 'target' => '/document/title', 'source' => '/title' ], [ 'target' => '/document/sections/0/body', 'source' => '/body' ] ],
                'suggested_regions' => 'static' === $mode ? [] : [ [ 'target' => '/document/sections', 'start' => 0, 'count' => 1, 'source' => '/sections', 'label' => 'Complete content field', 'kind' => 'repeat' ] ] ] + self::describe( $blueprint );
        }
        if ( 'static' === $mode ) { $blueprint['document']['faqs'] = []; }
        return [ 'blueprint' => $blueprint, 'suggested_bindings' => [ [ 'target' => '/document/title', 'source' => '/title' ], [ 'target' => '/document/intro', 'source' => '/intro' ], [ 'target' => '/document/sections/0/body', 'source' => '/body' ] ], 'suggested_regions' => 'static' === $mode ? [] : [ [ 'target' => '/document/sections', 'start' => 0, 'count' => 1, 'source' => '/sections', 'label' => 'Article sections', 'kind' => 'repeat' ], [ 'target' => '/document/faqs', 'start' => 0, 'count' => 1, 'source' => '/faqs', 'label' => 'Frequently asked questions', 'kind' => 'repeat' ] ], 'warnings' => $id ? [ 'This reads the WordPress content field. To preserve a page builder design, choose that builder instead.' ] : [] ] + self::describe( $blueprint );
    }
    public static function describe( array $blueprint ): array {
        $fields = [ [ 'target' => '/document/title', 'label' => 'Page or category title', 'format' => 'text', 'kind' => 'heading', 'writable' => true ] ]; $containers = [];
        if ( array_key_exists( 'intro', $blueprint['document'] ) ) { $fields[] = [ 'target' => '/document/intro', 'label' => 'Introduction', 'format' => 'html', 'kind' => 'body', 'writable' => true ]; }
        foreach ( [ 'sections' => [ 'heading' => 'text', 'body' => 'html' ], 'faqs' => [ 'question' => 'text', 'answer' => 'html' ] ] as $collection => $columns ) { $items = []; foreach ( $blueprint['document'][$collection] ?? [] as $index => $row ) { $items[] = [ 'index' => $index, 'label' => ucfirst( $collection ) . ' example ' . ( $index + 1 ) ]; foreach ( $columns as $key => $format ) { if ( ! array_key_exists( $key, $row ) ) { continue; } $fields[] = [ 'target' => '/document/' . $collection . '/' . $index . '/' . $key, 'label' => ucfirst( $key ) . ' in ' . $collection . ' ' . ( $index + 1 ), 'format' => $format, 'kind' => $key, 'writable' => true ]; } } if ( $items ) { $containers[] = [ 'target' => '/document/' . $collection, 'label' => ucfirst( $collection ), 'items' => $items ]; } }
        return [ 'fields' => $fields, 'containers' => $containers, 'warnings' => 'taxonomy' === ( $blueprint['type'] ?? '' ) ? [ 'Categories have no draft status. Review the translated field values here; this wizard does not publish a category.' ] : [] ];
    }
    public function validate_blueprint( array $blueprint ) { return is_array( $blueprint['document'] ?? null ) && is_array( $blueprint['document']['sections'] ?? null ) && is_array( $blueprint['document']['faqs'] ?? null ) ? $blueprint : new WP_Error( 'nova_recipe_core', 'A complete WordPress content blueprint is required.', [ 'status' => 422 ] ); }
    public function validate_binding( array $native, array $binding ) { $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $binding['target'], $found ); return $found && is_scalar( $value ) ? true : new WP_Error( 'nova_recipe_core', 'Bind an existing WordPress content field.', [ 'status' => 422 ] ); }
    public function validate_region( array $native, array $region ) { $value = Nova_Bridge_Suite_Recipe_Engine::get( $native, $region['target'], $found ); return $found && Nova_Bridge_Suite_Recipe_Engine::list_array( $value ) ? true : new WP_Error( 'nova_recipe_core', 'Choose a declared WordPress content collection.', [ 'status' => 422 ] ); }
    public function instantiate( array $prototype, string $seed, array $context = [] ) { return [ 'native' => $prototype, 'context' => $context ]; }
    public function bind( array $native, array $binding, $value, array $context = [] ) { Nova_Bridge_Suite_Recipe_Engine::put( $native, $binding['target'], $value ); return $native; }
    public function replace_region( array $native, array $region, array $children, array $context = [] ) { Nova_Bridge_Suite_Recipe_Engine::splice( $native, $region['target'], $region['start'], $region['count'], $children ); return $native; }
    public function finalize( array $blueprint, string $seed, array $context = [] ) {
        $doc = $blueprint['document']; $html = wp_kses_post( $doc['intro'] ?? '' );
        foreach ( $doc['sections'] ?? [] as $index => $section ) {
            $body = $section['body'] ?? '';
            $original = $context['recipe']['blueprint']['document']['sections'][$index]['body'] ?? null;
            $unchanged = ! array_key_exists( 'intro', $doc ) && [] === $doc['faqs'] && $body === $original;
            $html .= ( '' !== ( $section['heading'] ?? '' ) ? '<h2>' . esc_html( $section['heading'] ) . '</h2>' : '' ) . ( $unchanged ? $body : wp_kses_post( $body ) );
        }
        if ( $doc['faqs'] ?? [] ) { $html .= '<section class="nova-faq"><h2>Frequently asked questions</h2>'; foreach ( $doc['faqs'] as $faq ) { $html .= '<details><summary>' . esc_html( $faq['question'] ) . '</summary>' . wp_kses_post( $faq['answer'] ) . '</details>'; } $html .= '</section>'; }
        return [ 'native_document' => $doc, 'writer_payload' => 'taxonomy' === $blueprint['type'] ? [ 'name' => $doc['title'], 'description' => $html ] : [ 'title' => $doc['title'], 'content' => $html, 'template' => $blueprint['template'] ?? '' ], 'preview_html' => $html, 'warnings' => [], 'summary' => [ 'sections' => count( $doc['sections'] ), 'faq_items' => count( $doc['faqs'] ) ] ];
    }
    public static function write_draft( array $payload, array $post_fields ) {
        $fields = [ 'post_type' => $post_fields['post_type'], 'post_status' => 'draft', 'post_title' => $post_fields['title'], 'post_content' => $payload['content'] ?? '', 'post_author' => get_current_user_id() ]; if ( ! empty( $payload['template'] ) ) { $fields['page_template'] = $payload['template']; }
        $id = wp_insert_post( wp_slash( $fields ), true ); if ( is_wp_error( $id ) ) { return $id; }
        if ( (string) get_post_field( 'post_content', $id ) !== ( $payload['content'] ?? '' ) ) { wp_delete_post( $id, true ); return new WP_Error( 'nova_recipe_core', 'WordPress did not retain the planned native content; the new draft was removed.', [ 'status' => 500 ] ); } return new WP_REST_Response( [ 'id' => $id ], 201 );
    }
}
