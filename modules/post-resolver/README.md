# Nova Post Resolver

Secure resolver endpoint for mapping a WordPress object ID to a reachable REST endpoint.

## Endpoint

- `GET /wp-json/nova-post-resolver/v1/resolve/<id>`

## Query Parameters

- `include_response` (bool, default `false`): include matched endpoint JSON payload.
- `probe_all` (bool, default `false`): continue probing and return `matches`.
- `debug` (bool, default `false`): include checked route trace.
- `context` (string, default `view`): pass `view` or `edit` to internal requests.
- `refresh_routes` (bool, default `false`): bypass route discovery cache.
- `max_probes` (int, default `80`): cap bridge route probes.
- `show_templates` (bool, default `false`): show discovered templates when debug is enabled.

## Security Rules

- Auth required by default.
- Anonymous access only if `npr_allow_anonymous` filter returns true.
- Logged-in users must have `edit_posts`.
- Sensitive flags (`debug`, `probe_all`, `include_response`, `refresh_routes`, `show_templates`) require `manage_options`.
- `context=edit` requires `edit_posts`.

## Caching and Limits

- Route template discovery cache: transient, 6 hours.
- Resolver result cache: transient, 10 minutes, only for `context=view` when sensitive/verbose flags are all false.
- Rate limit: 60 requests per 60 seconds by default.

## Filters

- `npr_allow_anonymous` (bool, `WP_REST_Request $request`)
- `npr_include_route_regex` (string)
- `npr_exclude_route_regex` (string)
- `npr_default_max_probes` (int)
- `npr_result_cache_ttl` (int seconds, `WP_REST_Request $request`)
- `npr_rate_limit` (int, `WP_REST_Request $request`)
- `npr_rate_window` (int seconds, `WP_REST_Request $request`)
