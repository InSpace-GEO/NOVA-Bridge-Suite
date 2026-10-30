# Backend-authorized delivery handover

Implements the NOVA Bridge follow-up in posting-service PR #79
(`docs/backend-resend-retries.md`, reviewed contract at `b0b78d9`).
No new endpoint, migration, retry flag or polling cron is introduced.

## Behaviour

- Discovery makes a blocked or backed-off job runnable when it observes a fresh
  checkpoint. Only authenticated delivery GET and its current attempt header
  authorize a takeover; a webhook alone cannot do so.
- A changed attempt archives previous exact receipt bytes and acceptance records.
  If there is a frozen native operation, the worker recovers that operation under
  the target lock. An already verified write is acknowledged for the new attempt;
  a uniquely identified draft reservation is resumed without creating a second page.
- GET 410 completes the local job in the `superseded` phase. Its pending receipts,
  plan and native result remain audit evidence, but are no longer sent.
- A common site/URL-ID lock serializes old and new delivery workers. Before a
  newer content version proceeds, unfinished predecessors at that URL must return
  410. The stored-layout, NOVA CPT and local-rule writers reconcile their owned
  native target and release the old association for the new version. Old native
  operation records remain intact. The old job lease is revoked using its journal
  revision, so a delayed worker cannot commit its old result.
- Authority is rechecked before applying/recovering native work, before finishing
  it, and before each receipt. A callback rejected during a concurrent attempt
  change triggers another authority check rather than stranding the replacement.
- Final receipts retained from the unified-endpoint upgrade can replay their
  original bytes only while the same attempt is still current. Their original
  ETag is never replaced by a newer public projection's hash.

## Boundaries

The posting service must supply the PR #79 attempt and retirement behaviour.
Attested successes remain terminal, as in that contract. A new CIV ID with an
unchanged version number is not treated as a higher content version.

Native ownership and URL checks remain. A newer authorized stored-layout version
replaces content and design changes since the previous publication; it captures
the current page as its new operation baseline. In-flight change detection and
same-operation verification remain. If a partially written page
cannot be verified for the same frozen operation, or an interrupted creation has
no uniquely identifiable reservation, the job still requires reconciliation. A
backend resend does not justify creating a second page or guessing native state.
Higher versions can adopt a known, owned partial target through the explicit
retirement path. Legacy mapped/term writers use their existing recovery proof;
this change does not invent ownership for ambiguous legacy writes.

A remote GET and a WordPress write are not one transaction. An already executing
native write cannot be recalled if backend authority changes during that call.
Its evidence is retained and reconciled; old receipts are checked again before
send. Backend receipt correlation remains the separate coordination described
in PR #79.

## Local staging simulation

The standalone PHP fixtures mock HTTP, WordPress and storage in memory. They do
not bootstrap a site, connect to a database or create live pages. Coverage includes:

- failure before write, lost insert/save replies, and lost success/failure ACKs;
- replacement after planning or during apply, plus rejected delayed callbacks;
- real stored-layout writer recovery with changed attempt and exactly one page;
- newer-version adoption for stored layouts, Service/Blog CPTs and local rules;
- unknown reservations, manual changes, competing URL locks and stale leases;
- discovery rearming, terminal retirement, exact history retention and unified
  delivery compatibility.

Run `backend-resend-worker-unit.php`, `posting-jobs-unit.php`,
`stored-layout-delivery-unit.php`, `stored-layout-writer-unit.php`,
`owned-cpt-writer-unit.php`, and `rule-writer-unit.php` under
`modules/posting-service/tests/`, together with the existing discovery, webhook,
unified, V3, static-category and mapped-writer suites.

Staging installation is a filesystem-only package replacement with a backup and
hash verification. It does not establish a successful live publication or run a
WordPress UI test.
