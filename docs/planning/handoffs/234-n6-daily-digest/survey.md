# Survey — #234 N-6 Daily digest worker

## Files read (worktree)

- `docs/groups/modules/do_notifications/do_notifications.services.yml` — DI wiring; frequency_resolver + digest_renderer + queue backend all live here.
- `docs/groups/modules/do_notifications/do_notifications.install` — current schema for `do_notifications_queue`: `(id, uid, mid, template, frequency, day, created)`. **No `send_at`, no `payload/body` column.**
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — enqueue/drain/count contract; dedup on `(uid, mid, frequency, day)`.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` — `Connection::merge()` upsert path.
- `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php` — N-8 aggregator. `render(array $messages, UserInterface $recipient, string $window)` returns `['subject','body_text','body_html']`. Cap = 50, with `...and N more` overflow line. Sorts fragments by day newest-first.
- `docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php` — installs `do_notifications` schema explicitly; `setUp()` calls `$this->installSchema('do_notifications', ['do_notifications_queue']);`.
- `docs/groups/modules/do_notifications/tests/src/Kernel/DigestRendererTest.php` — good template for kernel test using `GroupsKernelTestBase`, `RunTestsInSeparateProcesses`, activity_* Messages.
- GitHub issue #234 (N-6). Epic #237.
- PR #261 (N-5, OPEN) diff — introduces `FrequencyResolver` + adds `send_at` to the `enqueue()` interface docblock but does NOT (yet) add the `send_at` schema column to `do_notifications_queue`. States N-1 "MUST" add it — but shipped N-1 does not.
- Issue #231 (N-3) — OPEN, not merged.

## State snapshot

- N-1 (#229) MERGED — queue table + DB backend live.
- N-4 (#232), N-8 (#236) MERGED — EmailRenderer + DigestRenderer live.
- N-5 (#233) — PR #261 OPEN, not merged. Introduces FrequencyResolver but no schema migration for `send_at` yet.
- N-3 (#231) — OPEN, not started.

## Reuse & Analogous-Feature map

| Concern | Existing object to extend | Recommendation |
|---------|---------------------------|-----------------|
| Read queued items by frequency | `DatabaseQueueBackend` (has `drain()` but returns ALL rows) | **EXTEND**: add `claimByFrequency(string $frequency, int $olderThan): array` — returns rows matching `frequency=? AND created < ?`, does NOT delete (deletion happens after successful digest enqueue, in a separate step so a mid-run failure is idempotent on next run). Also add `deleteByIds(array $ids): void`. Keep the queue backend the single owner of `do_notifications_queue` SQL. |
| Render per-user digest | `DigestRenderer::render($messages, $user, 'daily')` | **REUSE VERBATIM** — this is exactly what N-8 shipped for. Load `Message` entities via `mid` list, hand to renderer. |
| Store the aggregated digest for delivery | *Nothing analogous.* `do_notifications_queue` is per-`mid`; a digest is per-aggregation with no single `mid`. | **NEW OBJECT (justified)**: new table `do_notifications_digest_queue` + new class `DigestQueueBackend` implementing a new narrow `DigestQueueBackendInterface` (`enqueue(uid, window, subject, body_text, body_html, send_at)`, `drain()`, `count()`). Mirrors the pattern established by `QueueBackendInterface` + `DatabaseQueueBackend` so N-3 (delivery worker) has an analogous, predictable API to consume. Rationale for NEW rather than extend: the per-message queue's `(uid, mid, frequency, day)` dedup contract cannot express a per-aggregation row (no `mid`); jamming a nullable `mid` + payload columns onto the existing table would violate its documented contract and collide with N-5's pending `send_at` column addition. |
| Drush command | *No existing `src/Commands/` dir in do_notifications.* Drush 12+ pattern = attribute-based `#[Command]` classes registered via `drush.services.yml`. | **NEW** (no analogous command in module). `src/Commands/DigestCommands.php`. |
| Configurable cron hour | `state` service (used by `SubscriptionRouter`; injected via `@state`) | **REUSE** — `state.set('do_notifications.digest_daily.hour_utc', 2)` with default 2. Command reads state at run time. |
| Test base | `Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase` (used by `DigestRendererTest`) | **REUSE**. |

## Extend-vs-new default check

- Queue reads/writes: **EXTEND** existing backend (per-message queue).
- Digest storage: **NEW** table + class + narrow interface (see justification above; A must approve or block).
- Digest render: **REUSE** DigestRenderer verbatim.
- Drush command: **NEW** (no analogous command).

## Key findings / conflicts vs task prompt

1. **Task prompt assumes `send_at` on `do_notifications_queue`.** Not present; N-5's PR #261 documents it in the interface docblock but hasn't added the column. This story reads by `frequency` + `created` timestamp, not `send_at`, to avoid depending on the pending #261 schema migration.
2. **Task prompt says "enqueue a new item `frequency='digest_daily'` with the rendered body".** The current queue schema has no body column and dedups on `mid` (which a digest doesn't have). Design proposes a separate `do_notifications_digest_queue` table for aggregated payloads — same idea, cleaner separation. If A prefers modifying `do_notifications_queue`, respawn with adjusted plan.
3. **N-3 not merged.** The delivery worker that will consume `do_notifications_digest_queue` doesn't exist yet. Kernel test asserts enqueue-side only, per prompt's mocking guidance.
4. **No conflict with #261.** #261 modifies `do_notifications_queue` (adds `send_at` + `frequency` cols); we add a *separate* new table and don't touch #261's file. If #261 merges first, N-6 rebases cleanly.
5. **No timezone concerns.** UTC everywhere per user decision.

## Forward-compat check

Downstream consumers of this story:
- **N-3 (#231)** will drain `do_notifications_digest_queue` and send emails. The interface must give N-3: `(uid, subject, body_text, body_html, send_at)` and a way to mark-sent-or-delete. Our proposed `DigestQueueBackendInterface` provides all of that.
- **N-7 (#235, weekly digest)** — should reuse the same `do_notifications_digest_queue` table with `window='weekly'`, differing only in the SQL filter (frequency='weekly' AND created < NOW() - 7d) and the DigestRenderer arg. The design supports this: the new table's `window` column accepts both `daily` and `weekly`; the new backend's methods take `window` as a parameter.

No forward-conflict.
