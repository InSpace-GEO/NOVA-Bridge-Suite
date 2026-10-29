# Shared NOVA site delivery routing

This opt-in plugin feature supports several WordPress installations sharing one
NOVA site UUID, access token and notification signing secret. Posting-service and
its template selection contract are unchanged. The default is disabled.

## Setup

In each installation's NOVA connection details, expand **Shared website delivery
routing** and enable **Only process deliveries belonging to this WordPress
installation**. Its configured public WordPress home URL determines ownership.

For a main installation at `https://example.test/` and installations under
`/eindhoven`, `/schiphol`, `/rotterdam` and `/brussel`, enter those four paths in
the main installation's excluded paths, one per line. The other installations
automatically restrict ownership to their own home path. Exclusions always start
at the domain root, including any directory above a nested test network.

Paths match at segment boundaries. `/eindhoven/parking/` belongs to Eindhoven;
`/eindhoven-extra/` does not. Absolute URLs and domain-root-relative paths are
compared by origin and normalized path. Query parameters do not select an
installation. Ambiguous URLs or invalid routing settings block locally for
review; they are not treated as proof of another installation's ownership.

## Forwarder contract

Configure the existing posting-service webhook URL to the operator's central
receiver. The receiver forwards the exact raw body, `Content-Type`,
`X-Nova-Timestamp` and `X-Nova-Signature` to each installation's displayed ready
notification URL. Do not reconstruct JSON or modify the timestamp/signature.
Current notification authentication permits a five-minute clock window.

The receiver must verify the signed notification and forward promptly. It must
not acknowledge and silently discard failed forwards: either durably accept
responsibility for retrying them or return an error for the sender to retry.
Dispatch the outgoing requests concurrently, or durably queue them; sequential
forwarding adds all five response times to the receiver's acknowledgement time.
Replaying an expired signed notification requires a separately designed retry
mechanism; changing its timestamp alone invalidates the signature.

Each webhook wakes that installation's authenticated delivery discovery. The
hint contains no destination URL and does not restrict discovery to one item.
The worker therefore checks ownership after validating the snapshot, before
mapping lookup, predecessor reconciliation, any delivery receipt or CMS write.
Foreign work is retained as `complete / skipped`, with no events sent to NOVA.
Only the owning installation follows the existing mapping and publication path.

Skipped work does not consume the three normal work slots. Opted-in connections
can examine up to 100 claimed jobs within the existing 20-second batch budget;
only three non-skipped jobs proceed per batch. This is still bounded processing,
not a guarantee that one notification drains an arbitrary backlog. No cron,
automatic retry service or posting-service API is added.

Repeated discovery at the same cursor does not reopen a skipped job. A fresh
backend discovery checkpoint rechecks ownership before allowing a new attempt.
Changing settings alone does not automatically replay previously skipped jobs.
Already-started receipts and native recovery keep their existing path even if
routing settings subsequently change.

## Shared mappings

The UUID still has one shared set of posting-service template defaults. This
feature does not introduce per-installation default templates or approve a
template in another installation. The owning installation must have the exact
approved local profile for its delivery, or use its existing local-rule flow.
Configure this before forwarding; sharing credentials alone does not share
local layouts.

## Verification

Run `php modules/posting-service/tests/shared-site-routing-unit.php` for signed
webhook/discovery/worker checks using independent in-memory installation stores.
It exercises five destinations against five installations, duplicate wakes,
replacement attempts, excluded and encoded paths, root-relative URLs, missing
foreign profiles, existing receipt recovery and mixed shared-feed batches.
Run the connection-settings and journal suites for persistence boundaries.
These tests use fake HTTP and fake WordPress storage, not a live database.

A real WordPress Multisite test is a separate acceptance step. Use an
explicitly authorized disposable staging network with its own table prefix and
per-site plugin settings/journals. Keep live credentials out of that network,
intercept service HTTP with synthetic fixtures, and verify per-site activation,
ready routes, stored content, receipts and cleanup. Test separate installations
as well: a WordPress network is not identical to the customer's five independent
installations. Do not convert a shared staging site merely to run this check.

## Recorded staging acceptance, 2026-10-29

With explicit staging-only authorization, the candidate was network-activated in
an isolated five-site subdirectory network on inspace-seo.com, using its installed
WordPress 7.1.4 and PHP 8.1.34. The original single-site installation was retained.
The test used real HTTPS webhook requests, per-site WordPress tables, approved
fixture profiles, the actual mapping/worker/native-writer path and real pages.
It exercised V1 direct title/body mappings updating existing fixture pages, not
V3/Elementor stored-layout creation.
Only posting-service HTTP responses and delivery inputs were synthetic; this was
not a production-backend or LLM adaptation test.

- All five ready endpoints accepted the same signed notification with HTTP 202.
- Each site applied its own delivery; all twenty foreign combinations ended as
  local skips without receipts. The receiving sites emitted exactly five received
  and five successful-publication events to the fixture transport.
- All five rendered pages returned HTTP 200 with exactly one expected heading
  and the expected body. Repeated notifications left posts and metadata unchanged
  and emitted no extra receipts.
- An unsigned webhook returned 401; the private test pages rejected access without
  the separate test-access header.
- Five simultaneous forwards completed in 0.906 seconds in the measured replay.
  The initial sequential forwarding run totalled approximately 2.48 seconds.
  These are staging measurements, not a production latency guarantee.
- Cleanup removed the temporary network and private helper directory and verified
  zero remaining tables under its unique prefix. The original home URL,
  single-site mode, checked configuration/plugin file hashes and selected NOVA,
  activation and permalink option hashes matched the pre-test baseline.

The acceptance test did not change posting-service or the original staging site's
installed plugin.
