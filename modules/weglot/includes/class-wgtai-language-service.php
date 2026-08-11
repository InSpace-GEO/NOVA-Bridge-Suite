<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves Weglot language codes and translated URLs.
 *
 * Weglot distinguishes an internal code (ISO 639-1, e.g. "fr") from an external
 * code (the URL segment, which the dashboard can customise, e.g. "fr-be"). This
 * bridge stores and keys everything on the internal code, and accepts either
 * spelling plus BCP-47 input from NOVA ("nl-NL").
 */
class WGTAI_Language_Service
{
    private ?array $destination_codes = null;
    private ?string $original_code    = null;

    public static function is_weglot_active(): bool
    {
        return defined('WEGLOT_VERSION') || function_exists('weglot_get_current_language');
    }

    /**
     * Normalizes a language code to lowercase with a single dash separator.
     */
    public function normalize(string $language_code): string
    {
        $language_code = strtolower(trim($language_code));
        $language_code = preg_replace('/[^a-z0-9]+/', '-', $language_code);

        return is_string($language_code) ? trim($language_code, '-') : '';
    }

    /**
     * Returns the primary subtag of a code ("nl-nl" => "nl").
     */
    public function primary_subtag(string $language_code): string
    {
        $language_code = $this->normalize($language_code);

        if ($language_code === '') {
            return '';
        }

        $parts = explode('-', $language_code);

        return $parts[0];
    }

    public function get_original_code(): string
    {
        if (null !== $this->original_code) {
            return $this->original_code;
        }

        $this->original_code = '';

        $language_service = $this->get_weglot_language_service();

        if ($language_service && method_exists($language_service, 'get_original_language')) {
            $entry = $language_service->get_original_language();

            if (is_object($entry) && method_exists($entry, 'getInternalCode')) {
                $this->original_code = $this->normalize((string) $entry->getInternalCode());
            }
        }

        if ($this->original_code === '' && function_exists('weglot_get_original_language')) {
            $fallback = weglot_get_original_language();

            if (is_string($fallback)) {
                $this->original_code = $this->normalize($fallback);
            }
        }

        return $this->original_code;
    }

    /**
     * Destination (translated) languages as internal codes.
     *
     * @return array<int,string>
     */
    public function get_destination_codes(): array
    {
        if (null !== $this->destination_codes) {
            return $this->destination_codes;
        }

        $this->destination_codes = [];

        foreach ($this->get_destination_entries() as $entry) {
            $code = $this->code_from_entry($entry);

            if ($code !== '' && ! in_array($code, $this->destination_codes, true)) {
                $this->destination_codes[] = $code;
            }
        }

        return $this->destination_codes;
    }

    /**
     * Maps an arbitrary caller-supplied code onto a configured Weglot destination
     * language. Accepts "fr", "FR", "fr-FR", "fr_FR" and Weglot external codes.
     *
     * @return string Internal code, or '' when the language is not configured.
     */
    public function resolve_destination_code(string $requested): string
    {
        $requested = $this->normalize($requested);

        if ($requested === '') {
            return '';
        }

        $destinations = $this->get_destination_codes();

        if (in_array($requested, $destinations, true)) {
            return $requested;
        }

        // External code (the URL segment) may differ from the internal code.
        foreach ($this->get_destination_entries() as $entry) {
            if (! is_object($entry) || ! method_exists($entry, 'getExternalCode')) {
                continue;
            }

            if ($this->normalize((string) $entry->getExternalCode()) === $requested) {
                return $this->code_from_entry($entry);
            }
        }

        // BCP-47 input from NOVA ("nl-NL") against an internal code ("nl").
        $primary = $this->primary_subtag($requested);

        if ($primary !== '' && in_array($primary, $destinations, true)) {
            return $primary;
        }

        foreach ($destinations as $code) {
            if ($this->primary_subtag($code) === $primary) {
                return $code;
            }
        }

        return '';
    }

    /**
     * True when the code resolves to the Weglot original (source) language.
     */
    public function is_original_language(string $requested): bool
    {
        $requested = $this->normalize($requested);
        $original  = $this->get_original_code();

        if ($requested === '' || $original === '') {
            return false;
        }

        return $requested === $original || $this->primary_subtag($requested) === $this->primary_subtag($original);
    }

    /**
     * Current request language as an internal code ('' when Weglot is inactive).
     */
    public function get_current_code(): string
    {
        if (! function_exists('weglot_get_current_language')) {
            return '';
        }

        try {
            $current = weglot_get_current_language();
        } catch (\Throwable $throwable) {
            return '';
        }

        return is_string($current) ? $this->normalize($current) : '';
    }

    /**
     * Translated permalink for a post in the given language.
     *
     * Note: Weglot only rewrites the slug itself when the project has slug
     * translations (Pro plan and up) AND the slug is registered in the Weglot
     * dashboard. Otherwise this is the original slug under a language prefix.
     */
    public function get_translated_url(int $post_id, string $internal_code): string
    {
        $permalink = get_permalink($post_id);

        if (! is_string($permalink) || $permalink === '') {
            return '';
        }

        return $this->get_translated_url_for($permalink, $internal_code);
    }

    public function get_translated_url_for(string $url, string $internal_code): string
    {
        $internal_code = $this->normalize($internal_code);

        if ($url === '' || $internal_code === '' || ! function_exists('weglot_create_url_object')) {
            return '';
        }

        $entry = $this->get_language_entry($internal_code);

        if (! $entry) {
            return '';
        }

        try {
            $url_object = weglot_create_url_object($url);

            if (! is_object($url_object) || ! method_exists($url_object, 'getForLanguage')) {
                return '';
            }

            $translated = $url_object->getForLanguage($entry);
        } catch (\Throwable $throwable) {
            return '';
        }

        return is_string($translated) ? $translated : '';
    }

    /**
     * Language inventory in the same shape the Polylang and WPML bridges return.
     *
     * @return array<string,mixed>
     */
    public function get_languages(): array
    {
        $original    = $this->get_original_code();
        $current     = $this->get_current_code();
        $home        = trailingslashit(home_url());
        $default_url = $this->get_translated_url_for($home, $original);

        $languages = [];

        foreach ($this->get_all_entries() as $entry) {
            $code = $this->code_from_entry($entry);

            if ($code === '') {
                continue;
            }

            $external = is_object($entry) && method_exists($entry, 'getExternalCode')
                ? (string) $entry->getExternalCode()
                : $code;

            $english = is_object($entry) && method_exists($entry, 'getEnglishName')
                ? (string) $entry->getEnglishName()
                : $code;

            $native = is_object($entry) && method_exists($entry, 'getLocalName')
                ? (string) $entry->getLocalName()
                : $english;

            $home_url = $this->get_translated_url_for($home, $code);

            $languages[] = [
                'code'          => $code,
                'external_code' => $external,
                'is_default'    => $code === $original,
                'enabled'       => true,
                'is_current'    => $code === $current,
                'english_name'  => $english,
                'native_name'   => $native,
                'locale'        => $external,
                'flag_url'      => '',
                'home_url'      => $home_url !== '' ? $home_url : $home,
            ];
        }

        return [
            'default_language' => $original !== '' ? $original : null,
            'default_home_url' => $default_url !== '' ? $default_url : $home,
            'languages'        => $languages,
        ];
    }

    /**
     * @return object|null Weglot LanguageEntry
     */
    public function get_language_entry(string $internal_code)
    {
        $internal_code    = $this->normalize($internal_code);
        $language_service = $this->get_weglot_language_service();

        if (! $language_service || ! method_exists($language_service, 'get_language_from_internal')) {
            return null;
        }

        $entry = $language_service->get_language_from_internal($internal_code);

        if (is_object($entry)) {
            return $entry;
        }

        // Weglot indexes by its own internal code; retry on the primary subtag.
        $primary = $this->primary_subtag($internal_code);

        if ($primary !== '' && $primary !== $internal_code) {
            $entry = $language_service->get_language_from_internal($primary);
        }

        return is_object($entry) ? $entry : null;
    }

    /**
     * @return array<int,object>
     */
    private function get_destination_entries(): array
    {
        $language_service = $this->get_weglot_language_service();

        if (! $language_service || ! method_exists($language_service, 'get_destination_languages')) {
            return [];
        }

        $entries = $language_service->get_destination_languages();

        return is_array($entries) ? $entries : [];
    }

    /**
     * @return array<int,object>
     */
    private function get_all_entries(): array
    {
        $entries = [];
        $original = $this->get_language_entry($this->get_original_code());

        if ($original) {
            $entries[] = $original;
        }

        foreach ($this->get_destination_entries() as $entry) {
            $entries[] = $entry;
        }

        return $entries;
    }

    private function code_from_entry($entry): string
    {
        if (is_string($entry)) {
            return $this->normalize($entry);
        }

        if (is_object($entry) && method_exists($entry, 'getInternalCode')) {
            return $this->normalize((string) $entry->getInternalCode());
        }

        if (is_array($entry) && isset($entry['language_to'])) {
            return $this->normalize((string) $entry['language_to']);
        }

        return '';
    }

    /**
     * @return object|null Weglot Language_Service_Weglot
     */
    private function get_weglot_language_service()
    {
        if (! function_exists('weglot_get_service')) {
            return null;
        }

        try {
            $service = weglot_get_service('Language_Service_Weglot');
        } catch (\Throwable $throwable) {
            return null;
        }

        return is_object($service) ? $service : null;
    }
}
