<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

class WTAI_Translation_Service
{
    /**
     * Create or update a translation for the given source post.
     *
     * @param int   $source_post_id
     * @param array $translation
     *
     * @return array|\WP_Error
     */
    public function upsert_post_translation(int $source_post_id, array $translation)
    {
        $source_post = get_post($source_post_id);

        if (! $source_post) {
            return new \WP_Error('wtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? sanitize_text_field($translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('wtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        $post_type     = $source_post->post_type;
        $element_type  = $this->get_element_type($post_type);
        $trid          = apply_filters('wpml_element_trid', null, $source_post_id, $element_type);
        $lang_details  = $this->get_language_details($source_post_id, $element_type);
        $source_lang   = $lang_details['language_code'] ?? apply_filters('wpml_default_language', null);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source post.', ['status' => 400]);
        }

        $existing_translation_id   = apply_filters('wpml_object_id', $source_post_id, $post_type, false, $language_code);
        $existing_translation_post = $existing_translation_id ? get_post($existing_translation_id) : null;
        $post_data                 = $this->build_post_data($translation, $source_post, $existing_translation_post);

        if ($existing_translation_id) {
            $post_data['ID'] = $existing_translation_id;
            $translation_id  = wp_update_post($post_data, true);
            $created         = false;
        } else {
            $translation_id = wp_insert_post($post_data, true);
            $created        = true;
        }

        if (is_wp_error($translation_id)) {
            return $translation_id;
        }

        if (! $existing_translation_id) {
            do_action(
                'wpml_set_element_language_details',
                [
                    'element_id'           => $translation_id,
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_lang,
                ]
            );
        }

        if (! empty($translation['meta']) && is_array($translation['meta'])) {
            $this->sync_meta($translation_id, $translation['meta']);
        }

        if (! empty($translation['custom_fields']) && is_array($translation['custom_fields'])) {
            $this->sync_meta($translation_id, $translation['custom_fields']);
        }

        if (! empty($translation['taxonomies']) && is_array($translation['taxonomies'])) {
            $this->sync_taxonomies($translation_id, $translation['taxonomies']);
        }

        return [
            'source_post_id' => $source_post_id,
            'translation_id' => $translation_id,
            'language'       => $language_code,
            'created'        => $created,
        ];
    }

    /**
     * Create or update a term translation for the given source term.
     *
     * @param int   $source_term_id
     * @param string $taxonomy
     * @param array $translation
     *
     * @return array|\WP_Error
     */
    public function upsert_term_translation(int $source_term_id, string $taxonomy, array $translation, ?int $trid_override = null)
    {
        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('wtai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? sanitize_text_field($translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('wtai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        $element_type = 'tax_' . $taxonomy;
        $trid         = ($trid_override && $trid_override > 0)
            ? (int) $trid_override
            : apply_filters('wpml_element_trid', null, $source_term_id, $element_type);
        $lang_details = $this->get_language_details($source_term_id, $element_type);
        $source_lang  = $lang_details['language_code'] ?? apply_filters('wpml_default_language', null);
        $slug_for_lookup = '';
        if (! empty($translation['slug'])) {
            $slug_for_lookup = sanitize_title($translation['slug']);
        } elseif (! empty($translation['name'])) {
            $slug_for_lookup = sanitize_title($translation['name']);
        }
        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);
        $explicit_translation_id = isset($translation['term_id']) ? absint($translation['term_id']) : 0;

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source term.', ['status' => 400]);
        }

        // This endpoint is meant for translating into other languages; avoid mutating the source language entry here.
        if ($language_code === $source_lang) {
            return new \WP_Error(
                'wtai_same_language',
                'Translation language matches source language. Edit the source term directly instead.',
                ['status' => 400]
            );
        }

        // Prefer explicit term_id; only fall back to TRID/wpml_object_id when not provided.
        $existing_translation_id = $explicit_translation_id;
        if (! $existing_translation_id && is_array($translations) && isset($translations[$language_code]->element_id)) {
            $maybe_id = (int) $translations[$language_code]->element_id;
            if ($maybe_id && $maybe_id !== $source_term_id) {
                $existing_translation_id = $maybe_id;
            }
        }

        // Fallback to wpml_object_id lookup.
        if (! $existing_translation_id) {
            $existing_translation_id = apply_filters('wpml_object_id', $source_term_id, $taxonomy, false, $language_code);
        }

        // If still pointing at the source, clear it so we don't mutate the source term.
        if ($existing_translation_id === $source_term_id) {
            $existing_translation_id = 0;
        }

        // If we already resolved a target term (explicit or mapped), skip slug conflict checks.
        $skip_slug_checks = ($explicit_translation_id > 0) || ($existing_translation_id > 0);

        if (! $skip_slug_checks && ! $existing_translation_id && $slug_for_lookup !== '') {
            $slug_term = get_term_by('slug', $slug_for_lookup, $taxonomy);

            if ($slug_term && ! is_wp_error($slug_term)) {
                $slug_lang = $this->get_language_details((int) $slug_term->term_id, $element_type);
                $slug_trid = apply_filters('wpml_element_trid', null, (int) $slug_term->term_id, $element_type);
                $slug_id   = (int) $slug_term->term_id;
                $slug_lang_code = $slug_lang['language_code'] ?? '';
                $debug_context = [
                    'source_term_id'          => $source_term_id,
                    'language_code'           => $language_code,
                    'slug'                    => $translation['slug'],
                    'slug_term_id'            => $slug_id,
                    'slug_lang'               => $slug_lang_code ?: null,
                    'slug_trid'               => $slug_trid ?: null,
                    'existing_translation_id' => $existing_translation_id ?: null,
                    'trid'                    => $trid ?: null,
                ];

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    do_action(
                        'nova_bridge_suite/log',
                        sprintf(
                            'WTAI slug lookup: source=%d lang=%s slug=%s => slug_id=%d slug_lang=%s slug_trid=%s existing_translation_id=%s trid=%s',
                            $source_term_id,
                            $language_code,
                            $slug_for_lookup,
                            $slug_id,
                            $slug_lang_code ?: 'none',
                            $slug_trid ?: 'none',
                            $existing_translation_id ?: 'none',
                            $trid ?: 'none'
                        )
                    );
                }

                // Never adopt the source term as a translation target.
                if ($slug_id === $source_term_id) {
                    // Prefer explicit target term if provided.
                    if ($explicit_translation_id && $explicit_translation_id !== $source_term_id) {
                        $existing_translation_id = $explicit_translation_id;
                    } elseif (is_array($translations) && isset($translations[$language_code]->element_id)) {
                        $existing_translation_id = (int) $translations[$language_code]->element_id;
                        if ($existing_translation_id === $source_term_id) {
                            $existing_translation_id = 0;
                        }
                    }

                    // As a fallback, pick any other term in the TRID that is not the source.
                    if (! $existing_translation_id && is_array($translations)) {
                        foreach ($translations as $code => $info) {
                            if (isset($info->element_id) && (int) $info->element_id !== $source_term_id) {
                                $existing_translation_id = (int) $info->element_id;
                                break;
                            }
                        }
                    }

                    // If we have a real translation ID, proceed; otherwise error.
                    if (! $existing_translation_id) {
                        return new \WP_Error(
                            'slug_in_use_source',
                            'Slug already used by the source term. Provide a unique slug for the target language.',
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                }

                // If the slug is already assigned to another language, block.
                if ($slug_lang_code !== '' && $slug_lang_code !== $language_code) {
                    return new \WP_Error(
                        'slug_in_use_other_language',
                        sprintf(
                            'Slug already in use by another language (%s). Provide a unique slug for %s.',
                            $slug_lang_code,
                            $language_code
                        ),
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // If the slug belongs to another TRID but same language, adopt and re-link to this TRID.
                if (! empty($slug_trid) && $slug_trid !== $trid && $slug_lang_code === $language_code) {
                    $existing_translation_id = $slug_id;
                } elseif (! empty($slug_trid) && $slug_trid !== $trid && $slug_lang_code !== '') {
                    return new \WP_Error(
                        'slug_in_use_other_trid',
                        'Slug exists in a different translation group. Provide a unique slug for this group.',
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // If the slug is already in this TRID (or unassigned), adopt and then set the correct language.
                if (! $existing_translation_id) {
                    $existing_translation_id = $slug_id;
                }
            }
        }

        $existing_translation_term = $existing_translation_id ? get_term($existing_translation_id, $taxonomy) : null;
        $term_args               = $this->build_term_data($translation, $source_term, $existing_translation_term);

        $translation_term_id     = null;

        // If we already have a target term, update it directly at DB level to bypass WP duplicate slug checks.
        if ($existing_translation_id) {
            global $wpdb;
            $translation_term_id = $existing_translation_id;
            $created = false;

            $current_slug = $existing_translation_term && isset($existing_translation_term->slug)
                ? $existing_translation_term->slug
                : ($term_args['slug'] ?? '');

            $name = $term_args['name'] ?? ($existing_translation_term->name ?? '');
            $description = $term_args['description'] ?? ($existing_translation_term->description ?? '');

            // phpcs:disable WordPress.DB.DirectDatabaseQuery
            $updated_terms = $wpdb->update(
                $wpdb->terms,
                ['name' => $name, 'slug' => $current_slug],
                ['term_id' => $translation_term_id]
            );

            $tt_id = $existing_translation_term->term_taxonomy_id ?? 0;
            $updated_tax = $tt_id ? $wpdb->update(
                $wpdb->term_taxonomy,
                ['description' => $description],
                ['term_taxonomy_id' => $tt_id]
            ) : 0;
            // phpcs:enable WordPress.DB.DirectDatabaseQuery

            if ($updated_terms === false || $updated_tax === false) {
                return new \WP_Error(
                    'term_update_failed',
                    'Failed to update existing translation term.',
                    [
                        'status' => 500,
                        'debug'  => [
                            'source_term_id'          => $source_term_id,
                            'language_code'           => $language_code,
                            'existing_translation_id' => $existing_translation_id,
                            'term_args'               => $term_args,
                        ],
                    ]
                );
            }

            clean_term_cache([$translation_term_id], $taxonomy);
            $result = ['term_id' => $translation_term_id];
        } else {
            $result = wp_insert_term($term_args['name'], $taxonomy, $term_args);
            $created = true;
        }

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $adopt_id   = null;
            $debug_context = [
                'source_term_id'          => $source_term_id,
                'language_code'           => $language_code,
                'existing_translation_id' => $existing_translation_id ?: null,
                'term_args'               => $term_args,
                'wp_error_code'           => $error_code,
                'wp_error_data'           => $result->get_error_data(),
                'requested_slug'          => $translation['slug'] ?? null,
                'lookup_slug'             => $slug_for_lookup ?: null,
            ];

            if ($error_code === 'term_exists') {
                $adopt_id = (int) $result->get_error_data('term_exists');
            } elseif ($error_code === 'duplicate_term_slug') {
                // If caller explicitly targeted a translation term, prefer updating it directly.
                if ($explicit_translation_id && $explicit_translation_id !== $source_term_id) {
                    $adopt_id = $explicit_translation_id;
                    if (isset($term_args['slug'])) {
                        unset($term_args['slug']);
                    }
                }

                // Try multiple sources to identify the existing term we should adopt/update.
                $adopt_id = $adopt_id ?: (int) $result->get_error_data(); // some WP errors store the term_id directly
                if (! $adopt_id) {
                    $adopt_id = (int) $result->get_error_data('term_exists');
                }
                if (! $adopt_id && ! empty($term_args['slug'])) {
                    $slug_term = get_term_by('slug', $term_args['slug'], $taxonomy);
                    if ($slug_term && ! is_wp_error($slug_term)) {
                        $adopt_id = (int) $slug_term->term_id;
                    }
                }
                if (! $adopt_id && $slug_for_lookup !== '' && empty($term_args['slug'])) {
                    $slug_term = get_term_by('slug', $slug_for_lookup, $taxonomy);
                    if ($slug_term && ! is_wp_error($slug_term)) {
                        $adopt_id = (int) $slug_term->term_id;
                    }
                }
                // If still nothing, try TRID mapping for this language (update existing translation).
                if (! $adopt_id && is_array($translations) && isset($translations[$language_code]->element_id)) {
                    $adopt_id = (int) $translations[$language_code]->element_id;
                }
                // If explicit term_id was provided, adopt that as last resort.
                if (! $adopt_id && $explicit_translation_id) {
                    $adopt_id = $explicit_translation_id;
                }

                // If the slug resolves to the source term but we already have a concrete target,
                // prefer the translation we intend to update and drop the slug to avoid re-checks.
                if ($adopt_id === $source_term_id && ($existing_translation_id || $explicit_translation_id)) {
                    $adopt_id = $existing_translation_id ?: $explicit_translation_id;
                    if (isset($term_args['slug'])) {
                        unset($term_args['slug']);
                    }
                }

                // If we have a target term, retry an update without slug to bypass duplicate checks.
                if ($adopt_id || $existing_translation_id || $explicit_translation_id) {
                    $target_id = $adopt_id ?: ($existing_translation_id ?: $explicit_translation_id);
                    $retry_args = $term_args;
                    if (isset($retry_args['slug'])) {
                        unset($retry_args['slug']);
                    }
                    $retry = wp_update_term($target_id, $taxonomy, $retry_args);
                    if (! is_wp_error($retry)) {
                        $translation_term_id = $target_id;
                        $created = false;
                        // proceed to language setting/meta below
                    } else {
                        // If retry still fails and we have context, return a richer error.
                        return new \WP_Error(
                            'duplicate_term_slug',
                            $result->get_error_message(),
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                } else {
                    // If still nothing, return a richer error with context.
                    return new \WP_Error(
                        'duplicate_term_slug',
                        $result->get_error_message(),
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }
            }

            if ($translation_term_id) {
                // already handled via retry
            } elseif ($adopt_id) {
                $adopt_lang = $this->get_language_details($adopt_id, $element_type);
                $adopt_trid = apply_filters('wpml_element_trid', null, $adopt_id, $element_type);
                $adopt_lang_code = $adopt_lang['language_code'] ?? '';
                $debug_context = [
                    'source_term_id'          => $source_term_id,
                    'language_code'           => $language_code,
                    'slug'                    => $term_args['slug'] ?? '',
                    'slug_term_id'            => $adopt_id,
                    'slug_lang'               => $adopt_lang_code ?: null,
                    'slug_trid'               => $adopt_trid ?: null,
                    'existing_translation_id' => $existing_translation_id ?: null,
                    'trid'                    => $trid ?: null,
                ];

                if ($adopt_id === $source_term_id) {
                    return new \WP_Error(
                        'slug_in_use_source',
                        'Slug already used by the source term. Provide a unique slug for the target language.',
                        ['status' => 409, 'debug' => $debug_context]
                    );
                }

                // Allow adoption if same language; block only when another language already owns the slug in a different TRID.
                if (! empty($adopt_trid) && $adopt_trid !== $trid) {
                    if ($adopt_lang_code !== '' && $adopt_lang_code !== $language_code) {
                        return new \WP_Error(
                            'slug_in_use_other_trid',
                            'Slug exists in a different translation group. Provide a unique slug for this group.',
                            ['status' => 409, 'debug' => $debug_context]
                        );
                    }
                }

                $translation_term_id = $adopt_id;
                $created             = false;
                // attempt to update the adopted term with desired args
                $update_result = wp_update_term($translation_term_id, $taxonomy, $term_args);
                if (is_wp_error($update_result)) {
                    return $update_result;
                }
            } else {
                return $result;
            }
        } else {
            $translation_term_id = $result['term_id'];
        }

        $current_lang = $this->get_language_details($translation_term_id, $element_type);
        $current_trid = apply_filters('wpml_element_trid', null, $translation_term_id, $element_type);

        if (
            empty($current_lang['language_code']) ||
            $current_lang['language_code'] !== $language_code ||
            $current_trid !== $trid
        ) {
            do_action(
                'wpml_set_element_language_details',
                [
                    'element_id'           => $translation_term_id,
                    'element_type'         => $element_type,
                    'trid'                 => $trid,
                    'language_code'        => $language_code,
                    'source_language_code' => $source_lang,
                ]
            );
        }

        if (! empty($translation['meta']) && is_array($translation['meta'])) {
            $this->sync_term_meta($translation_term_id, $taxonomy, $translation['meta']);
        }

        return [
            'source_term_id'      => $source_term_id,
            'translation_term_id' => $translation_term_id,
            'taxonomy'            => $taxonomy,
            'language'            => $language_code,
            'created'             => $created,
        ];
    }

    private function build_post_data(array $translation, \WP_Post $source_post, \WP_Post $existing_translation = null): array
    {
        $current_defaults = $existing_translation ?: $source_post;
        $data = [
            'post_type'   => $source_post->post_type,
            'post_status' => $translation['status'] ?? $current_defaults->post_status,
        ];

        if ($existing_translation === null) {
            $data['post_author'] = $translation['author'] ?? $source_post->post_author;
        }

        $this->maybe_set_field($data, 'post_title', $translation, 'title', $existing_translation ? null : $source_post->post_title, 'wp_strip_all_tags');
        $this->maybe_set_field($data, 'post_name', $translation, 'slug', $existing_translation ? null : $source_post->post_name, 'sanitize_title');
        $this->maybe_set_field($data, 'post_content', $translation, 'content', $existing_translation ? null : $source_post->post_content, 'wp_kses_post');
        $this->maybe_set_field($data, 'post_excerpt', $translation, 'excerpt', $existing_translation ? null : $source_post->post_excerpt, 'wp_kses_post');
        $this->maybe_set_field($data, 'post_parent', $translation, 'parent_id', $existing_translation ? null : $source_post->post_parent, 'absint');

        if (isset($translation['comment_status'])) {
            $data['comment_status'] = $translation['comment_status'] === 'open' ? 'open' : 'closed';
        }

        return $data;
    }

    private function maybe_set_field(array &$data, string $target_key, array $translation, string $translation_key, $default = null, $sanitize_callback = null): void
    {
        if (array_key_exists($translation_key, $translation)) {
            $value = $translation[$translation_key];
        } elseif ($default !== null) {
            $value = $default;
        } else {
            return;
        }

        if ($sanitize_callback && is_callable($sanitize_callback)) {
            $value = call_user_func($sanitize_callback, $value);
        }

        $data[$target_key] = $value;
    }

    private function get_element_type(string $post_type): string
    {
        return 'post_' . $post_type;
    }

    private function get_language_details(int $element_id, string $element_type): array
    {
        $details = apply_filters(
            'wpml_element_language_details',
            null,
            [
                'element_id'   => $element_id,
                'element_type' => $element_type,
            ]
        );

        return is_array($details) ? $details : [];
    }

    private function sync_meta(int $post_id, array $meta): void
    {
        foreach ($meta as $key => $value) {
            if ($value === null) {
                delete_post_meta($post_id, $key);
                continue;
            }

            update_post_meta($post_id, $key, $value);
        }
    }

    private function sync_taxonomies(int $post_id, array $taxonomies): void
    {
        foreach ($taxonomies as $taxonomy => $terms) {
            if (! taxonomy_exists($taxonomy)) {
                continue;
            }

            wp_set_object_terms($post_id, $terms, $taxonomy);
        }
    }

    public function get_languages(): array
    {
        $default_language = apply_filters('wpml_default_language', null);
        $active_languages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);

        if (! is_array($active_languages)) {
            return [
                'default_language' => $default_language,
                'default_home_url' => trailingslashit(home_url()),
                'languages'        => [],
            ];
        }

        $languages = [];
        $default_home_url = null;

        foreach ($active_languages as $code => $data) {
            $is_current = isset($data['active']) ? ((int) $data['active'] === 1) : null;
            $home_url   = $this->get_language_home_url($data, $code, $default_language);

            $languages[] = [
                'code'         => $code,
                'is_default'   => $code === $default_language,
                'enabled'      => true, // present in WPML active languages list.
                'is_current'   => $is_current,
                'english_name' => $data['english_name'] ?? '',
                'native_name'  => $data['native_name'] ?? '',
                'locale'       => $data['default_locale'] ?? '',
                'flag_url'     => $data['country_flag_url'] ?? '',
                'home_url'     => $home_url,
            ];

            if ($code === $default_language) {
                $default_home_url = $home_url;
            }
        }

        if (! $default_home_url) {
            $default_home_url = $this->get_language_home_url(
                $active_languages[$default_language] ?? [],
                $default_language,
                $default_language
            );
        }

        return [
            'default_language' => $default_language,
            'default_home_url' => $default_home_url,
            'languages'        => $languages,
        ];
    }

    public function get_term_translations(int $source_term_id, string $taxonomy)
    {
        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('wtai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('wtai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $element_type = 'tax_' . $taxonomy;
        $trid         = apply_filters('wpml_element_trid', null, $source_term_id, $element_type);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source term.', ['status' => 400]);
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);

        $items = [];
        if (is_array($translations)) {
            foreach ($translations as $code => $info) {
                if (! isset($info->element_id)) {
                    continue;
                }
                $term_obj = get_term((int) $info->element_id, $taxonomy);
                if (! $term_obj || is_wp_error($term_obj)) {
                    continue;
                }
                $items[] = [
                    'language' => $code,
                    'term_id'  => (int) $info->element_id,
                    'slug'     => $term_obj->slug,
                    'name'     => $term_obj->name,
                    'is_source'=> ((int) $info->element_id === (int) $source_term_id),
                ];
            }
        }

        return [
            'source_term_id' => $source_term_id,
            'taxonomy'       => $taxonomy,
            'trid'           => $trid,
            'translations'   => $items,
        ];
    }

    public function get_post_translations(int $source_post_id)
    {
        $source_post = get_post($source_post_id);

        if (! $source_post || ! isset($source_post->post_type)) {
            return new \WP_Error('wtai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $element_type = $this->get_element_type($source_post->post_type);
        $trid         = apply_filters('wpml_element_trid', null, $source_post_id, $element_type);

        if (! $trid) {
            return new \WP_Error('wtai_missing_trid', 'WPML translation group not found for source post.', ['status' => 400]);
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $element_type);

        $items = [];
        if (is_array($translations)) {
            foreach ($translations as $code => $info) {
                if (! isset($info->element_id)) {
                    continue;
                }
                $post_obj = get_post((int) $info->element_id);
                if (! $post_obj || is_wp_error($post_obj)) {
                    continue;
                }
                $items[] = [
                    'language'   => $code,
                    'post_id'    => (int) $info->element_id,
                    'slug'       => $post_obj->post_name,
                    'title'      => $post_obj->post_title,
                    'status'     => $post_obj->post_status,
                    'post_type'  => $post_obj->post_type,
                    'is_source'  => ((int) $info->element_id === (int) $source_post_id),
                ];
            }
        }

        return [
            'source_post_id' => $source_post_id,
            'post_type'      => $source_post->post_type,
            'trid'           => $trid,
            'translations'   => $items,
        ];
    }

    private function get_language_home_url(array $language_data, string $language_code, string $default_language): string
    {
        $base_home = trailingslashit(home_url());
        $url       = isset($language_data['url']) && is_string($language_data['url']) ? $language_data['url'] : '';

        if ($url === '') {
            $url = apply_filters('wpml_home_url', null, $language_code);
        }

        if (! $url) {
            $url = apply_filters('wpml_permalink', $base_home, $language_code);
        }

        if (! $url) {
            $url = $base_home;
        }

        if ($url === $base_home && $language_code !== $default_language) {
            $url = add_query_arg('lang', $language_code, $base_home);
        }

        return trailingslashit($url);
    }

    private function build_term_data(array $translation, \WP_Term $source_term, ?\WP_Term $existing_translation = null): array
    {
        $defaults = [
            'name'        => $existing_translation ? $existing_translation->name : $source_term->name,
            'slug'        => $existing_translation ? $existing_translation->slug : $source_term->slug,
            'description' => $existing_translation ? $existing_translation->description : $source_term->description,
            'parent'      => $existing_translation ? $existing_translation->parent : $source_term->parent,
        ];

        $args = [];
        $this->maybe_set_term_field($args, 'name', $translation, 'name', $defaults['name'], 'wp_strip_all_tags');
        // Slug: if not provided, leave untouched (no default) to avoid stealing the source slug on update.
        $this->maybe_set_term_field($args, 'slug', $translation, 'slug', $existing_translation ? $existing_translation->slug : ($existing_translation === null ? $source_term->slug : null), 'sanitize_title', false);
        $this->maybe_set_term_field($args, 'description', $translation, 'description', $defaults['description'], 'wp_kses_post');
        $this->maybe_set_term_field($args, 'parent', $translation, 'parent_id', $defaults['parent'], 'absint');

        if (! isset($args['name']) || $args['name'] === '') {
            $args['name'] = $defaults['name'];
        }

        return $args;
    }

    private function maybe_set_term_field(
        array &$data,
        string $target_key,
        array $translation,
        string $translation_key,
        $default = null,
        $sanitize_callback = null,
        bool $use_default_when_missing = true
    ): void
    {
        if (array_key_exists($translation_key, $translation)) {
            $value = $translation[$translation_key];
        } elseif ($use_default_when_missing && $default !== null) {
            $value = $default;
        } else {
            return;
        }

        if ($sanitize_callback && is_callable($sanitize_callback)) {
            $value = call_user_func($sanitize_callback, $value);
        }

        $data[$target_key] = $value;
    }

    private function sync_term_meta(int $term_id, string $taxonomy, array $meta): void
    {
        foreach ($meta as $key => $value) {
            if ($this->maybe_set_yoast_term_meta($taxonomy, $term_id, $key, $value)) {
                continue;
            }

            if ($value === null) {
                delete_term_meta($term_id, $key);
                continue;
            }

            update_term_meta($term_id, $key, $value);
        }
    }

    private function maybe_set_yoast_term_meta(string $taxonomy, int $term_id, string $key, $value): bool
    {
        $mapped = $this->map_yoast_term_key($key);
        if ($mapped === '') {
            return false;
        }

        $changed = $this->update_yoast_taxonomy_meta_option($taxonomy, $term_id, $mapped, $value);

        if (class_exists('\\WPSEO_Taxonomy_Meta')) {
            \WPSEO_Taxonomy_Meta::set_value($taxonomy, $term_id, $mapped, $value);
        }

        if ($changed) {
            $this->touch_yoast_term_indexable($term_id);
        }

        // Also mirror to term meta keys so UI/plugins that read term_meta see values.
        if ($mapped === 'wpseo_title') {
            update_term_meta($term_id, 'wpseo_title', $value);
            update_term_meta($term_id, '_yoast_wpseo_title', $value);
        } elseif ($mapped === 'wpseo_desc') {
            update_term_meta($term_id, 'wpseo_metadesc', $value);
            update_term_meta($term_id, 'wpseo_desc', $value);
            update_term_meta($term_id, '_yoast_wpseo_metadesc', $value);
        } elseif ($mapped === 'wpseo_canonical') {
            update_term_meta($term_id, 'wpseo_canonical', $value);
            update_term_meta($term_id, '_yoast_wpseo_canonical', $value);
        }

        return true;
    }

    private function map_yoast_term_key(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        if (strpos($key, '_yoast_wpseo_') === 0) {
            $rest = substr($key, strlen('_yoast_wpseo_'));
            if ($rest === 'metadesc' || $rest === 'metadescription') {
                return 'wpseo_desc';
            }
            return 'wpseo_' . $rest;
        }

        if ($key === 'wpseo_metadesc' || $key === 'wpseo_metadescription') {
            return 'wpseo_desc';
        }

        if (strpos($key, 'wpseo_') === 0) {
            return $key;
        }

        return '';
    }

    private function update_yoast_taxonomy_meta_option(string $taxonomy, int $term_id, string $yoast_key, $value): bool
    {
        $all = get_option('wpseo_taxonomy_meta');
        if (! is_array($all)) {
            $all = [];
        }
        if (! isset($all[$taxonomy]) || ! is_array($all[$taxonomy])) {
            $all[$taxonomy] = [];
        }

        $term_id = (int) $term_id;
        if (! isset($all[$taxonomy][$term_id]) || ! is_array($all[$taxonomy][$term_id])) {
            $all[$taxonomy][$term_id] = [];
        }

        $current = $all[$taxonomy][$term_id][$yoast_key] ?? null;

        if ($current === $value) {
            return false;
        }

        $all[$taxonomy][$term_id][$yoast_key] = $value;
        update_option('wpseo_taxonomy_meta', $all);

        return true;
    }

    private function touch_yoast_term_indexable(int $term_id): void
    {
        if (function_exists('YoastSEO') && class_exists('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository')) {
            try {
                $yoast = YoastSEO();
                if (is_object($yoast) && isset($yoast->classes) && method_exists($yoast->classes, 'get')) {
                    $repo = $yoast->classes->get('\\Yoast\\WP\\SEO\\Repositories\\Indexable_Repository');
                    if (is_object($repo)) {
                        if (method_exists($repo, 'delete_by_object_id_and_type')) {
                            $repo->delete_by_object_id_and_type($term_id, 'term');
                        } elseif (method_exists($repo, 'find_by_id_and_type') && method_exists($repo, 'delete')) {
                            $idx = $repo->find_by_id_and_type($term_id, 'term');
                            if ($idx) {
                                $repo->delete($idx);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // best effort
            }
        }

        do_action('edited_term', $term_id, 0, '');
        clean_term_cache($term_id);
    }
}
