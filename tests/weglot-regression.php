<?php
/**
 * End-to-end check for the Weglot bridge against a live Weglot project.
 *
 * Run with: wp eval-file tests/weglot-regression.php
 *
 * Requires: NOVA Bridge Suite active, the Weglot bridge module enabled, and Weglot
 * active and configured with at least one destination language.
 *
 * For logic-only checks with no WordPress or Weglot present, use
 * tests/weglot-unit.php instead.
 */

if ('cli' !== PHP_SAPI) {
    exit(1);
}

function nova_weglot_test_assert($condition, $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function nova_weglot_test_request(string $method, string $route, array $body = [], array $params = []): WP_REST_Request
{
    $request = new WP_REST_Request($method, $route);
    $request->set_header('content-type', 'application/json');

    if (! empty($body)) {
        $request->set_body(wp_json_encode($body));
    }

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return $request;
}

$marker      = 'nova-weglot-regression-' . wp_generate_uuid4();
$created_ids = [];
$admins      = get_users(
    [
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ID',
    ]
);

nova_weglot_test_assert(! empty($admins), 'No administrator is available for the regression check.');
wp_set_current_user((int) $admins[0]);

try {
    nova_weglot_test_assert(defined('NOVA_BRIDGE_SUITE_VERSION'), 'NOVA Bridge Suite is not loaded.');
    nova_weglot_test_assert(defined('WGTAI_VERSION'), 'Enable the Weglot bridge before running this check.');
    nova_weglot_test_assert(
        WGTAI_Language_Service::is_weglot_active(),
        'Weglot is not active on this site.'
    );

    $language_service = new WGTAI_Language_Service();
    $storage_service  = new WGTAI_Storage_Service($language_service);

    $original     = $language_service->get_original_code();
    $destinations = $language_service->get_destination_codes();

    nova_weglot_test_assert('' !== $original, 'Weglot reports no original language.');
    nova_weglot_test_assert(
        ! empty($destinations),
        'Weglot has no destination languages configured; add one before running this check.'
    );

    $target = $destinations[0];
    printf("Weglot: original=%s destinations=%s (testing %s)\n", $original, implode(',', $destinations), $target);

    // --- languages endpoint -------------------------------------------------

    $server   = rest_get_server();
    $response = $server->dispatch(nova_weglot_test_request('GET', '/weglot-translations/v1/languages'));

    nova_weglot_test_assert(200 === $response->get_status(), 'GET /languages did not return 200.');

    $languages = $response->get_data();

    nova_weglot_test_assert(
        isset($languages['default_language']) && $languages['default_language'] === $original,
        'GET /languages reported the wrong default language.'
    );
    nova_weglot_test_assert(
        ! empty($languages['languages']),
        'GET /languages returned no languages.'
    );

    // --- source post --------------------------------------------------------

    $source_id = wp_insert_post(
        [
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => $marker . '-source',
            'post_content' => '<p>Source language body.</p>',
        ],
        true
    );

    nova_weglot_test_assert(! is_wp_error($source_id), 'Could not create the source post.');
    $created_ids[] = (int) $source_id;

    // --- store a translation ------------------------------------------------

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/posts',
            [
                'source_post_id' => (int) $source_id,
                'translations'   => [
                    [
                        'language' => $target,
                        'title'    => $marker . '-translated-title',
                        'content'  => '<p>' . $marker . '-translated-body</p>',
                        'excerpt'  => $marker . '-translated-excerpt',
                        'slug'     => $marker . '-translated-slug',
                        'meta'     => [
                            '_yoast_wpseo_title'    => $marker . '-translated-seo-title',
                            '_yoast_wpseo_metadesc' => $marker . '-translated-seo-desc',
                        ],
                    ],
                ],
            ]
        )
    );

    nova_weglot_test_assert(
        200 === $response->get_status(),
        'POST /posts returned ' . $response->get_status() . ' instead of 200: ' . wp_json_encode($response->get_data())
    );

    $data = $response->get_data();

    nova_weglot_test_assert(empty($data['errors']), 'POST /posts reported errors: ' . wp_json_encode($data['errors']));
    nova_weglot_test_assert(! empty($data['results'][0]['stored']), 'POST /posts did not report the payload as stored.');
    nova_weglot_test_assert(
        $data['results'][0]['language'] === $target,
        'POST /posts normalized the language to an unexpected code.'
    );
    nova_weglot_test_assert(
        ! empty($data['results'][0]['url']),
        'POST /posts did not report a translated URL; check that Weglot resolves URLs on this site.'
    );

    printf("Stored %s -> %s\n", $target, $data['results'][0]['url']);

    // No translated post may be created: Weglot serves the original row.
    $sibling = get_posts(
        [
            'post_type'        => 'any',
            'post_status'      => 'any',
            'numberposts'      => 5,
            's'                => $marker . '-translated-title',
            'suppress_filters' => true,
        ]
    );

    foreach ($sibling as $candidate) {
        nova_weglot_test_assert(
            (int) $candidate->ID === (int) $source_id,
            'A separate post was created for the translation; the Weglot bridge must not insert posts.'
        );
    }

    // --- read it back -------------------------------------------------------

    $payload = $storage_service->get((int) $source_id, $target);

    nova_weglot_test_assert(is_array($payload), 'The stored payload could not be read back.');
    nova_weglot_test_assert(
        $payload['title'] === $marker . '-translated-title',
        'The stored title did not round-trip.'
    );
    nova_weglot_test_assert(
        isset($payload['requested_slug']) && '' !== $payload['requested_slug'],
        'The requested slug was not recorded.'
    );
    nova_weglot_test_assert(
        $storage_service->get_stored_languages((int) $source_id) === [$target],
        'The stored-language index does not list exactly the stored locale.'
    );

    // --- a structured document survives real WordPress meta -------------------
    //
    // The path the unit harness can only approximate. update_metadata() runs
    // wp_unslash() over the value, so without wp_slash() every backslash in the
    // JSON is stripped here and nowhere else: \" terminates the string early and
    // \uXXXX loses its escape. Both an <a href> and a non-ASCII character are
    // needed, because they corrupt through different escapes.

    $builder_document = wp_json_encode(
        [[
            'id'       => 'sec1',
            'elType'   => 'section',
            'settings' => [],
            'elements' => [[
                'id'       => 'col1',
                'elType'   => 'column',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'hd1',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => ['title' => $marker . '-café-heading'],
                    ],
                    [
                        'id'         => 'tx1',
                        'elType'     => 'widget',
                        'widgetType' => 'text-editor',
                        'settings'   => [
                            'editor' => '<p><a href="https://example.com/fr/">' . $marker . '-link</a></p>',
                        ],
                    ],
                ],
            ]],
        ]]
    );

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/posts',
            [
                'source_post_id' => (int) $source_id,
                'translations'   => [
                    [
                        'language' => $target,
                        'meta'     => [
                            '_elementor_data' => $builder_document,
                            'nova_gallery'    => ['first' => $marker . '-one', 'second' => $marker . '-two'],
                        ],
                    ],
                ],
            ]
        )
    );

    nova_weglot_test_assert(
        200 === $response->get_status(),
        'POST of a structured document returned ' . $response->get_status() . ': ' . wp_json_encode($response->get_data())
    );

    $payload   = $storage_service->get((int) $source_id, $target);
    $stored_doc = $payload['meta']['_elementor_data'] ?? null;

    nova_weglot_test_assert(is_string($stored_doc), 'The structured document was not stored as a string.');

    $decoded_doc = json_decode((string) $stored_doc, true);

    nova_weglot_test_assert(
        is_array($decoded_doc),
        'The stored structured document no longer decodes - this is the missing wp_slash() failure.'
    );
    nova_weglot_test_assert(
        ($decoded_doc[0]['elements'][0]['elements'][1]['settings']['editor'] ?? '')
            === '<p><a href="https://example.com/fr/">' . $marker . '-link</a></p>',
        'The link inside the structured document did not survive storage.'
    );
    nova_weglot_test_assert(
        ($decoded_doc[0]['elements'][0]['elements'][0]['settings']['title'] ?? '') === $marker . '-café-heading',
        'The non-ASCII heading inside the structured document did not survive storage.'
    );

    // An array-valued meta key must reach a template intact, not as its first
    // element: get_metadata_raw() unwraps one level for a $single read.
    nova_weglot_test_assert(
        ($payload['meta']['nova_gallery']['second'] ?? '') === $marker . '-two',
        'An array meta value did not round-trip through storage.'
    );

    // --- a partial update must not erase the rest of the locale ---------------

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/posts',
            [
                'source_post_id' => (int) $source_id,
                'translations'   => [
                    ['language' => $target, 'title' => $marker . '-retitled'],
                ],
            ]
        )
    );

    nova_weglot_test_assert(
        200 === $response->get_status(),
        'A partial update returned ' . $response->get_status() . ': ' . wp_json_encode($response->get_data())
    );

    $payload = $storage_service->get((int) $source_id, $target);

    nova_weglot_test_assert(
        ($payload['title'] ?? '') === $marker . '-retitled',
        'A partial update did not apply the field it carried.'
    );
    nova_weglot_test_assert(
        ($payload['content'] ?? '') === '<p>' . $marker . '-translated-body</p>',
        'A partial update erased the stored content.'
    );
    nova_weglot_test_assert(
        ($payload['excerpt'] ?? '') === $marker . '-translated-excerpt',
        'A partial update erased the stored excerpt.'
    );
    nova_weglot_test_assert(
        is_string($payload['meta']['_elementor_data'] ?? null),
        'A partial update erased the stored meta map.'
    );

    // --- the index cannot be deleted through a locale code --------------------

    $response = $server->dispatch(
        nova_weglot_test_request('DELETE', '/weglot-translations/v1/posts/' . (int) $source_id . '/translations/languages')
    );

    nova_weglot_test_assert(
        $storage_service->get_stored_languages((int) $source_id) === [$target],
        'DELETE .../translations/languages wiped the stored-language index.'
    );

    $response = $server->dispatch(
        nova_weglot_test_request(
            'GET',
            '/weglot-translations/v1/posts/' . (int) $source_id . '/translations',
            [],
            ['id' => (int) $source_id]
        )
    );

    nova_weglot_test_assert(200 === $response->get_status(), 'GET /translations did not return 200.');

    $listing = $response->get_data();
    $stored  = null;
    $source  = null;

    foreach ($listing['translations'] as $item) {
        if (! empty($item['is_source'])) {
            $source = $item;
            continue;
        }

        if ($item['language'] === $target) {
            $stored = $item;
        }
    }

    nova_weglot_test_assert(null !== $source, 'GET /translations did not include the source language.');
    nova_weglot_test_assert(null !== $stored, 'GET /translations did not include the target language.');
    nova_weglot_test_assert(! empty($stored['stored']), 'GET /translations did not mark the locale as stored.');
    nova_weglot_test_assert(! empty($stored['url']), 'GET /translations did not report a translated URL.');

    // --- render path --------------------------------------------------------

    $render_service = new WGTAI_Render_Service($language_service, $storage_service);

    $title = $render_service->filter_title('untouched', (int) $source_id);
    nova_weglot_test_assert(
        'untouched' === $title,
        'The render service swapped content before resolve_payload() ran.'
    );

    // --- terms are explicitly unsupported -----------------------------------

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/terms',
            [
                'source_term_id' => 1,
                'taxonomy'       => 'category',
                'translations'   => [['language' => $target, 'name' => 'x']],
            ]
        )
    );

    nova_weglot_test_assert(
        501 === $response->get_status(),
        'POST /terms should return 501, got ' . $response->get_status() . '.'
    );

    // --- rejections ---------------------------------------------------------

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/posts',
            [
                'source_post_id' => (int) $source_id,
                'translations'   => [['language' => $original, 'title' => 'x']],
            ]
        )
    );

    nova_weglot_test_assert(
        207 === $response->get_status(),
        'Posting the original language should be rejected per-item with 207.'
    );

    $errors = $response->get_data()['errors'];
    nova_weglot_test_assert(
        ! empty($errors) && 'wgtai_same_language' === $errors[0]['code'],
        'Posting the original language returned the wrong error code.'
    );

    $response = $server->dispatch(
        nova_weglot_test_request(
            'POST',
            '/weglot-translations/v1/posts',
            [
                'source_post_id' => (int) $source_id,
                'translations'   => [['language' => 'zz', 'title' => 'x']],
            ]
        )
    );

    $errors = $response->get_data()['errors'];
    nova_weglot_test_assert(
        ! empty($errors) && 'wgtai_unknown_language' === $errors[0]['code'],
        'An unconfigured language returned the wrong error code.'
    );

    // --- delete -------------------------------------------------------------

    $response = $server->dispatch(
        nova_weglot_test_request(
            'DELETE',
            '/weglot-translations/v1/posts/' . (int) $source_id . '/translations/' . $target,
            [],
            [
                'id'       => (int) $source_id,
                'language' => $target,
            ]
        )
    );

    nova_weglot_test_assert(200 === $response->get_status(), 'DELETE did not return 200.');
    nova_weglot_test_assert(! empty($response->get_data()['deleted']), 'DELETE did not report a deletion.');
    nova_weglot_test_assert(
        null === $storage_service->get((int) $source_id, $target),
        'The payload survived deletion.'
    );
    nova_weglot_test_assert(
        [] === $storage_service->get_stored_languages((int) $source_id),
        'The stored-language index survived deletion.'
    );

    echo "weglot-regression: all checks passed\n";
} finally {
    foreach ($created_ids as $created_id) {
        wp_delete_post($created_id, true);
    }
}
