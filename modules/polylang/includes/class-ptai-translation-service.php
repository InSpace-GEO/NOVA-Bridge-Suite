<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

class PTAI_Translation_Service
{
    private ?array $available_languages = null;

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
        $polylang_ready = $this->ensure_polylang_ready();
        if (is_wp_error($polylang_ready)) {
            return $polylang_ready;
        }

        $source_post = get_post($source_post_id);

        if (! $source_post) {
            return new \WP_Error('ptai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? $this->normalize_language_code((string) $translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('ptai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        if (! $this->is_supported_language($language_code)) {
            return new \WP_Error(
                'ptai_unknown_language',
                sprintf('Language "%s" is not enabled in Polylang.', $language_code),
                ['status' => 400]
            );
        }

        $source_lang = $this->get_post_language($source_post_id);

        if ($source_lang === '') {
            return new \WP_Error(
                'ptai_missing_source_language',
                'Source post has no Polylang language assigned.',
                ['status' => 400]
            );
        }

        if ($language_code === $source_lang) {
            return new \WP_Error(
                'ptai_same_language',
                'Translation language matches source language. Edit the source post directly instead.',
                ['status' => 400]
            );
        }

        $explicit_translation_id = $this->extract_numeric_id($translation, ['post_id', 'translation_id', 'term_id']);
        $existing_translation_id = $explicit_translation_id;

        if (! $existing_translation_id && function_exists('pll_get_post')) {
            $existing_translation_id = absint(pll_get_post($source_post_id, $language_code));
        }

        if ($existing_translation_id === $source_post_id) {
            $existing_translation_id = 0;
        }

        $existing_translation_post = $existing_translation_id ? get_post($existing_translation_id) : null;

        if ($existing_translation_id && (! $existing_translation_post || $existing_translation_post->post_type !== $source_post->post_type)) {
            return new \WP_Error(
                'ptai_invalid_translation_target',
                'Existing translation target is invalid or has a different post type.',
                ['status' => 409]
            );
        }

        $post_data = $this->build_post_data($translation, $source_post, $existing_translation_post);

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

        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($translation_id, $language_code);
        }

        $saved = $this->save_post_translation_map($source_post_id, $source_lang, $translation_id, $language_code);
        if (is_wp_error($saved)) {
            return $saved;
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
            'translation_id' => (int) $translation_id,
            'language'       => $language_code,
            'created'        => $created,
        ];
    }

    /**
     * Create or update a term translation for the given source term.
     *
     * @param int    $source_term_id
     * @param string $taxonomy
     * @param array  $translation
     *
     * @return array|\WP_Error
     */
    public function upsert_term_translation(int $source_term_id, string $taxonomy, array $translation, ?int $group_override = null)
    {
        unset($group_override);

        $polylang_ready = $this->ensure_polylang_ready();
        if (is_wp_error($polylang_ready)) {
            return $polylang_ready;
        }

        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('ptai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('ptai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $language_code = isset($translation['language']) ? $this->normalize_language_code((string) $translation['language']) : '';

        if ($language_code === '') {
            return new \WP_Error('ptai_missing_language', 'Translation language is required.', ['status' => 400]);
        }

        if (! $this->is_supported_language($language_code)) {
            return new \WP_Error(
                'ptai_unknown_language',
                sprintf('Language "%s" is not enabled in Polylang.', $language_code),
                ['status' => 400]
            );
        }

        $source_lang = $this->get_term_language($source_term_id);

        if ($source_lang === '') {
            return new \WP_Error(
                'ptai_missing_source_language',
                'Source term has no Polylang language assigned.',
                ['status' => 400]
            );
        }

        if ($language_code === $source_lang) {
            return new \WP_Error(
                'ptai_same_language',
                'Translation language matches source language. Edit the source term directly instead.',
                ['status' => 400]
            );
        }

        $explicit_translation_id = $this->extract_numeric_id($translation, ['term_id', 'translation_term_id']);
        $existing_translation_id = $explicit_translation_id;

        if (! $existing_translation_id && function_exists('pll_get_term')) {
            $existing_translation_id = absint(pll_get_term($source_term_id, $language_code));
        }

        if ($existing_translation_id === $source_term_id) {
            $existing_translation_id = 0;
        }

        $slug_for_lookup = '';
        if (! empty($translation['slug'])) {
            $slug_for_lookup = sanitize_title((string) $translation['slug']);
        } elseif (! empty($translation['name'])) {
            $slug_for_lookup = sanitize_title((string) $translation['name']);
        }

        if (! $existing_translation_id && $slug_for_lookup !== '') {
            $slug_term = get_term_by('slug', $slug_for_lookup, $taxonomy);

            if ($slug_term && ! is_wp_error($slug_term)) {
                $slug_term_id = (int) $slug_term->term_id;
                if ($slug_term_id === $source_term_id) {
                    return new \WP_Error(
                        'slug_in_use_source',
                        'Slug already used by the source term. Provide a unique slug for the target language.',
                        ['status' => 409]
                    );
                }

                $slug_lang = $this->get_term_language($slug_term_id);

                if ($slug_lang !== '' && $slug_lang !== $language_code) {
                    return new \WP_Error(
                        'slug_in_use_other_language',
                        sprintf(
                            'Slug already in use by another language (%s). Provide a unique slug for %s.',
                            $slug_lang,
                            $language_code
                        ),
                        ['status' => 409]
                    );
                }

                $existing_translation_id = $slug_term_id;
            }
        }

        $existing_translation_term = $existing_translation_id ? get_term($existing_translation_id, $taxonomy) : null;

        if ($existing_translation_id && (! $existing_translation_term || is_wp_error($existing_translation_term))) {
            return new \WP_Error('ptai_invalid_translation_target', 'Existing translation term not found.', ['status' => 404]);
        }

        $term_args = $this->build_term_data($translation, $source_term, $existing_translation_term instanceof \WP_Term ? $existing_translation_term : null);

        if ($existing_translation_id) {
            $result  = wp_update_term($existing_translation_id, $taxonomy, $term_args);
            $created = false;
        } else {
            $result  = wp_insert_term($term_args['name'], $taxonomy, $term_args);
            $created = true;
        }

        if (is_wp_error($result)) {
            $adopt_id = $this->resolve_term_conflict_id($result, $taxonomy, $term_args);

            if (! $adopt_id) {
                return $result;
            }

            if ($adopt_id === $source_term_id) {
                return new \WP_Error(
                    'slug_in_use_source',
                    'Slug already used by the source term. Provide a unique slug for the target language.',
                    ['status' => 409]
                );
            }

            $adopt_lang = $this->get_term_language($adopt_id);
            if ($adopt_lang !== '' && $adopt_lang !== $language_code) {
                return new \WP_Error(
                    'slug_in_use_other_language',
                    sprintf(
                        'Slug already in use by another language (%s). Provide a unique slug for %s.',
                        $adopt_lang,
                        $language_code
                    ),
                    ['status' => 409]
                );
            }

            $retry_args = $term_args;
            if (isset($retry_args['slug'])) {
                unset($retry_args['slug']);
            }

            $retry = wp_update_term($adopt_id, $taxonomy, $retry_args);
            if (is_wp_error($retry)) {
                return $retry;
            }

            $translation_term_id = $adopt_id;
            $created             = false;
        } else {
            $translation_term_id = isset($result['term_id']) ? (int) $result['term_id'] : (int) $existing_translation_id;
        }

        if ($translation_term_id <= 0) {
            return new \WP_Error('ptai_term_write_failed', 'Failed to create or update translated term.', ['status' => 500]);
        }

        if (function_exists('pll_set_term_language')) {
            pll_set_term_language($translation_term_id, $language_code);
        }

        $saved = $this->save_term_translation_map($source_term_id, $taxonomy, $source_lang, $translation_term_id, $language_code);
        if (is_wp_error($saved)) {
            return $saved;
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

    public function get_languages(): array
    {
        $polylang_ready = $this->ensure_polylang_ready();
        if (is_wp_error($polylang_ready)) {
            return [
                'default_language' => null,
                'default_home_url' => trailingslashit(home_url()),
                'languages'        => [],
            ];
        }

        $default_language = '';
        if (function_exists('pll_default_language')) {
            $default_language = (string) pll_default_language('slug');
        }
        $default_language = $this->normalize_language_code($default_language);

        $language_codes = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
        if (! is_array($language_codes)) {
            $language_codes = [];
        }

        $raw_languages    = $this->get_raw_languages();
        $current_language = function_exists('pll_current_language')
            ? $this->normalize_language_code((string) pll_current_language('slug'))
            : '';

        $languages        = [];
        $default_home_url = '';

        foreach ($language_codes as $code) {
            if (! is_string($code)) {
                continue;
            }

            $code = $this->normalize_language_code($code);
            if ($code === '') {
                continue;
            }

            $raw     = $raw_languages[$code] ?? [];
            $details = $this->get_polylang_language_details($code);

            $native_name = $details['native_name']
                ?: (is_string($raw['name'] ?? null) ? (string) $raw['name'] : $code);
            $locale      = $details['locale']
                ?: (is_string($raw['locale'] ?? null) ? (string) $raw['locale'] : '');
            $flag_url    = $details['flag_url']
                ?: (is_string($raw['flag'] ?? null) ? (string) $raw['flag'] : '');

            $home_url = $this->get_language_home_url($code, $default_language, $raw);

            $languages[] = [
                'code'         => $code,
                'is_default'   => $code === $default_language,
                'enabled'      => true,
                'is_current'   => $code === $current_language,
                'english_name' => $details['english_name'] ?: $native_name,
                'native_name'  => $native_name,
                'locale'       => $locale,
                'flag_url'     => $flag_url,
                'home_url'     => $home_url,
            ];

            if ($code === $default_language) {
                $default_home_url = $home_url;
            }
        }

        if ($default_home_url === '') {
            $default_home_url = $this->get_language_home_url($default_language, $default_language, []);
        }

        return [
            'default_language' => $default_language ?: null,
            'default_home_url' => $default_home_url,
            'languages'        => $languages,
        ];
    }

    public function get_term_translations(int $source_term_id, string $taxonomy)
    {
        $polylang_ready = $this->ensure_polylang_ready();
        if (is_wp_error($polylang_ready)) {
            return $polylang_ready;
        }

        if (! taxonomy_exists($taxonomy)) {
            return new \WP_Error('ptai_missing_taxonomy', 'Taxonomy not found.', ['status' => 404]);
        }

        $source_term = get_term($source_term_id, $taxonomy);

        if (! $source_term || is_wp_error($source_term)) {
            return new \WP_Error('ptai_missing_source_term', 'Source term not found.', ['status' => 404]);
        }

        $translations = function_exists('pll_get_term_translations') ? pll_get_term_translations($source_term_id) : [];
        if (! is_array($translations)) {
            $translations = [];
        }

        $translations = $this->normalize_term_translations_map($translations, $taxonomy);

        if (empty($translations)) {
            $source_lang = $this->get_term_language($source_term_id);
            if ($source_lang !== '') {
                $translations[$source_lang] = $source_term_id;
            }
        }

        $items = [];
        foreach ($translations as $code => $term_id) {
            $term_obj = get_term((int) $term_id, $taxonomy);
            if (! $term_obj || is_wp_error($term_obj)) {
                continue;
            }

            $items[] = [
                'language'  => $code,
                'term_id'   => (int) $term_id,
                'slug'      => $term_obj->slug,
                'name'      => $term_obj->name,
                'is_source' => ((int) $term_id === (int) $source_term_id),
            ];
        }

        return [
            'source_term_id' => $source_term_id,
            'taxonomy'       => $taxonomy,
            'translations'   => $items,
        ];
    }

    public function get_post_translations(int $source_post_id)
    {
        $polylang_ready = $this->ensure_polylang_ready();
        if (is_wp_error($polylang_ready)) {
            return $polylang_ready;
        }

        $source_post = get_post($source_post_id);

        if (! $source_post || ! isset($source_post->post_type)) {
            return new \WP_Error('ptai_missing_source', 'Source post not found.', ['status' => 404]);
        }

        $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($source_post_id) : [];
        if (! is_array($translations)) {
            $translations = [];
        }

        $translations = $this->normalize_post_translations_map($translations);

        if (empty($translations)) {
            $source_lang = $this->get_post_language($source_post_id);
            if ($source_lang !== '') {
                $translations[$source_lang] = $source_post_id;
            }
        }

        $items = [];
        foreach ($translations as $code => $post_id) {
            $post_obj = get_post((int) $post_id);
            if (! $post_obj || is_wp_error($post_obj)) {
                continue;
            }

            $items[] = [
                'language'  => $code,
                'post_id'   => (int) $post_id,
                'slug'      => $post_obj->post_name,
                'title'     => $post_obj->post_title,
                'status'    => $post_obj->post_status,
                'post_type' => $post_obj->post_type,
                'is_source' => ((int) $post_id === (int) $source_post_id),
            ];
        }

        return [
            'source_post_id' => $source_post_id,
            'post_type'      => $source_post->post_type,
            'translations'   => $items,
        ];
    }

    /**
     * @return true|\WP_Error
     */
    private function ensure_polylang_ready()
    {
        if (! function_exists('pll_languages_list')) {
            return new \WP_Error(
                'ptai_polylang_missing',
                'Polylang API functions are unavailable. Ensure Polylang is active.',
                ['status' => 500]
            );
        }

        return true;
    }

    private function normalize_language_code(string $language_code): string
    {
        $language_code = trim(strtolower($language_code));
        if ($language_code === '') {
            return '';
        }

        $normalized = preg_replace('/[^a-z0-9_-]/', '', $language_code);

        return is_string($normalized) ? $normalized : '';
    }

    private function is_supported_language(string $language_code): bool
    {
        $language_code = $this->normalize_language_code($language_code);
        if ($language_code === '') {
            return false;
        }

        if (null === $this->available_languages) {
            $languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : [];
            if (! is_array($languages)) {
                $languages = [];
            }

            $normalized = [];
            foreach ($languages as $code) {
                if (! is_string($code)) {
                    continue;
                }

                $code = $this->normalize_language_code($code);
                if ($code !== '') {
                    $normalized[$code] = true;
                }
            }

            $this->available_languages = $normalized;
        }

        return ! empty($this->available_languages[$language_code]);
    }

    private function get_post_language(int $post_id): string
    {
        if (! function_exists('pll_get_post_language')) {
            return '';
        }

        $language = pll_get_post_language($post_id, 'slug');

        return is_string($language) ? $this->normalize_language_code($language) : '';
    }

    private function get_term_language(int $term_id): string
    {
        if (! function_exists('pll_get_term_language')) {
            return '';
        }

        $language = pll_get_term_language($term_id, 'slug');

        return is_string($language) ? $this->normalize_language_code($language) : '';
    }

    /**
     * @return true|\WP_Error
     */
    private function save_post_translation_map(int $source_post_id, string $source_lang, int $target_post_id, string $target_lang)
    {
        if (! function_exists('pll_save_post_translations')) {
            return new \WP_Error(
                'ptai_polylang_missing',
                'Polylang post translation API is unavailable.',
                ['status' => 500]
            );
        }

        $translations = function_exists('pll_get_post_translations') ? pll_get_post_translations($source_post_id) : [];
        if (! is_array($translations)) {
            $translations = [];
        }

        $translations = $this->normalize_post_translations_map($translations);
        $translations[$source_lang] = $source_post_id;
        $translations[$target_lang] = $target_post_id;

        try {
            pll_save_post_translations($translations);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ptai_save_map_failed',
                'Failed to link post translations in Polylang.',
                [
                    'status'  => 500,
                    'message' => $e->getMessage(),
                ]
            );
        }

        return true;
    }

    /**
     * @return true|\WP_Error
     */
    private function save_term_translation_map(int $source_term_id, string $taxonomy, string $source_lang, int $target_term_id, string $target_lang)
    {
        if (! function_exists('pll_save_term_translations')) {
            return new \WP_Error(
                'ptai_polylang_missing',
                'Polylang term translation API is unavailable.',
                ['status' => 500]
            );
        }

        $translations = function_exists('pll_get_term_translations') ? pll_get_term_translations($source_term_id) : [];
        if (! is_array($translations)) {
            $translations = [];
        }

        $translations = $this->normalize_term_translations_map($translations, $taxonomy);
        $translations[$source_lang] = $source_term_id;
        $translations[$target_lang] = $target_term_id;

        try {
            pll_save_term_translations($translations);
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ptai_save_map_failed',
                'Failed to link term translations in Polylang.',
                [
                    'status'  => 500,
                    'message' => $e->getMessage(),
                ]
            );
        }

        return true;
    }

    private function normalize_post_translations_map(array $translations): array
    {
        $normalized = [];

        foreach ($translations as $language_code => $post_id) {
            if (! is_string($language_code)) {
                continue;
            }

            $language_code = $this->normalize_language_code($language_code);
            $post_id       = absint($post_id);

            if ($language_code === '' || $post_id <= 0) {
                continue;
            }

            if (! get_post($post_id)) {
                continue;
            }

            $normalized[$language_code] = $post_id;
        }

        return $normalized;
    }

    private function normalize_term_translations_map(array $translations, string $taxonomy): array
    {
        $normalized = [];

        foreach ($translations as $language_code => $term_id) {
            if (! is_string($language_code)) {
                continue;
            }

            $language_code = $this->normalize_language_code($language_code);
            $term_id       = absint($term_id);

            if ($language_code === '' || $term_id <= 0) {
                continue;
            }

            $term = get_term($term_id, $taxonomy);
            if (! $term || is_wp_error($term)) {
                continue;
            }

            $normalized[$language_code] = $term_id;
        }

        return $normalized;
    }

    private function extract_numeric_id(array $input, array $candidate_keys): int
    {
        foreach ($candidate_keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = absint($input[$key]);
            if ($value > 0) {
                return $value;
            }
        }

        return 0;
    }

    private function resolve_term_conflict_id(\WP_Error $error, string $taxonomy, array $term_args): int
    {
        $error_code = (string) $error->get_error_code();

        if ($error_code !== 'term_exists' && $error_code !== 'duplicate_term_slug') {
            return 0;
        }

        $candidate = $error->get_error_data('term_exists');
        $adopt_id  = absint($candidate);

        if (! $adopt_id) {
            $data = $error->get_error_data();

            if (is_numeric($data)) {
                $adopt_id = absint($data);
            } elseif (is_array($data)) {
                if (! empty($data['term_exists'])) {
                    $adopt_id = absint($data['term_exists']);
                } elseif (! empty($data['term_id'])) {
                    $adopt_id = absint($data['term_id']);
                }
            }
        }

        if (! $adopt_id && ! empty($term_args['slug'])) {
            $slug_term = get_term_by('slug', (string) $term_args['slug'], $taxonomy);
            if ($slug_term && ! is_wp_error($slug_term)) {
                $adopt_id = (int) $slug_term->term_id;
            }
        }

        return $adopt_id;
    }

    private function get_raw_languages(): array
    {
        if (! function_exists('pll_the_languages')) {
            return [];
        }

        $raw = pll_the_languages(
            [
                'raw'           => 1,
                'hide_if_empty' => 0,
            ]
        );

        if (! is_array($raw)) {
            return [];
        }

        $mapped = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && is_array($value)) {
                $slug = $this->normalize_language_code($key);
                if ($slug !== '') {
                    $mapped[$slug] = $value;
                }
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $slug = isset($value['slug']) && is_string($value['slug'])
                ? $this->normalize_language_code($value['slug'])
                : '';

            if ($slug !== '') {
                $mapped[$slug] = $value;
            }
        }

        return $mapped;
    }

    private function get_polylang_language_details(string $language_code): array
    {
        $details = [
            'english_name' => '',
            'native_name'  => '',
            'locale'       => '',
            'flag_url'     => '',
        ];

        if (! function_exists('PLL')) {
            return $details;
        }

        $pll = PLL();
        if (! is_object($pll) || ! isset($pll->model) || ! is_object($pll->model) || ! method_exists($pll->model, 'get_language')) {
            return $details;
        }

        $language = $pll->model->get_language($language_code);

        if (! is_object($language)) {
            return $details;
        }

        if (isset($language->name) && is_string($language->name)) {
            $details['native_name'] = $language->name;
            $details['english_name'] = $language->name;
        }

        if (isset($language->locale) && is_string($language->locale)) {
            $details['locale'] = $language->locale;
        }

        if (isset($language->flag_url) && is_string($language->flag_url)) {
            $details['flag_url'] = $language->flag_url;
        } elseif (isset($language->flag) && is_object($language->flag) && isset($language->flag->url) && is_string($language->flag->url)) {
            $details['flag_url'] = $language->flag->url;
        }

        return $details;
    }

    private function get_language_home_url(string $language_code, string $default_language, array $raw_data): string
    {
        $base_home = trailingslashit(home_url());
        $url       = '';

        if ($language_code !== '' && function_exists('pll_home_url')) {
            $candidate = pll_home_url($language_code);
            if (is_string($candidate) && $candidate !== '') {
                $url = $candidate;
            }
        }

        if ($url === '' && isset($raw_data['url']) && is_string($raw_data['url'])) {
            $url = $raw_data['url'];
        }

        if ($url === '') {
            $url = $base_home;
        }

        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            $url = home_url($url);
        }

        if ($url === $base_home && $language_code !== '' && $language_code !== $default_language) {
            $url = add_query_arg('lang', $language_code, $base_home);
        }

        return trailingslashit($url);
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
