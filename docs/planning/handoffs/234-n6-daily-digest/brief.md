# Brief — #234 N-6 Daily digest worker

**Run slug:** 234-n6-daily-digest
**Branch:** 234-n6-daily-digest (base: origin/main @ 7d3326a)
**Epic:** #237 Notifications
**Review-rigor dial:** `none` (POC lean pipeline per memory feedback_poc_lean_pipeline; A is still run; diff-gate is dropped in favor of A + S).
**Pipeline:** O → A → T(RED) → F → T(GREEN) → S → PR → CI → self-merge. **NO D. NO U. NO A-dup separate step (folded into A on diff review).**

## Objective

Ship the `do_notifications:digest-daily` drush command that runs nightly (default 02:00 UTC, configurable via state), aggregates the last 24 hours of `frequency='daily'` queued items per recipient using N-8's `DigestRenderer`, and enqueues one aggregated digest email per user into a new `do_notifications_digest_queue` table for the (not-yet-merged) N-3 delivery worker to consume. Deletes consumed originals from `do_notifications_queue`. Skips users with zero items in window. N-8's 50-cap semantics are already enforced by `DigestRenderer::render()` itself.

## Design (locked pending A approval)

### New objects (justified in survey)

1. **`do_notifications_digest_queue`** table via `hook_update_9001()` (module version bump). Columns:
   - `id` serial PK
   - `uid` unsigned int not null (recipient user id)
   - `window` varchar(16) not null (`'daily'` or `'weekly'`)
   - `subject` varchar(255) not null (rendered subject line)
   - `body_text` longtext not null
   - `body_html` longtext not null
   - `send_at` unsigned int not null (unix timestamp; earliest time to send)
   - `created` unsigned int not null (unix timestamp)
   - index on `(send_at)` (consumer query pattern: WHERE send_at <= NOW())

2. **`src/Queue/DigestQueueBackendInterface.php`** — narrow contract:
   - `enqueue(int $uid, string $window, string $subject, string $bodyText, string $bodyHtml, int $sendAt): int` — returns inserted row id.
   - `drain(): array` — read+delete all rows (mirrors QueueBackendInterface pattern; test/consumer helper).
   - `count(): int`
   - `all(): array` — read without delete (for the kernel test to assert enqueue-side state).

3. **`src/Queue/DatabaseDigestQueueBackend.php`** — DB-persistent implementation. Service `do_notifications.digest_queue`.

4. **`src/Commands/DigestCommands.php`** — Drush 12+ attribute style:
   - `#[Command('do_notifications:digest-daily')]`
   - Reads `state.get('do_notifications.digest_daily.hour_utc', 2)` (informational only; the command runs when cron invokes it; the hour is documented state for ops).
   - Reads `state.get('do_notifications.digest_daily.window_seconds', 86400)` (defaults 24h) — allows tests to shrink the window.
   - Reads `time.getRequestTime()` for `now`; computes `threshold = now - windowSeconds`.
   - Calls new `QueueBackendInterface::claimDaily(int $threshold): array` returning `[['id','uid','mid','template','frequency','day','created'], ...]` filtered `frequency='daily' AND created < :threshold`. Grouped by uid in PHP.
   - For each uid group: load Message entities by `mid` (via `entity_type.manager` message storage `loadMultiple`), skip messages that fail to load, load User entity, skip if user unloadable, call `DigestRenderer::render($messages, $user, 'daily')`, insert one row into `do_notifications_digest_queue` via new backend (send_at = now), then delete the consumed queue rows via new `QueueBackendInterface::deleteByIds(array $ids): void`.
   - If zero items for a user: skipped (no empty digest).
   - Returns Drush command result summary (`[users_digested, items_consumed, digests_enqueued]`) — printable for cron logs.

### Extensions to existing interface (justified)

`QueueBackendInterface` gains two methods:
- `claimDaily(int $olderThan): array` — returns rows (including their `id`) matching `frequency='daily' AND created < :olderThan`, ordered by `(uid, created)`. Does NOT delete. Named `claimDaily` (not `claimByFrequency`) because N-7 will add its own `claimWeekly` in the next story — keeps each frequency's SQL explicit and grep-able.
- `deleteByIds(array $ids): void` — deletes matching `id IN (...)`.

`MockQueueBackend` gets the same two methods with in-memory equivalents (so unit tests that use the mock don't break).

### Non-goals (explicit)

- No email sending (N-3's job; the command's job ends at digest_queue enqueue).
- No weekly digest (N-7).
- No cron entry — ops concern; documented in the command's docblock as "wire via `drush cron:add do_notifications:digest-daily "0 2 * * *"`".
- No re-render at delivery time; we store the rendered body at digest time.
- No user-timezone offset; UTC only (per user decision).
- No changes to `do_notifications_queue` schema (leaves N-5 PR #261's pending column additions unblocked).

## Acceptance criteria (each backed by a test)

- [ ] AC1: Command `do_notifications:digest-daily` is registered and callable via drush.
- [ ] AC2: Given 10 queued `frequency='daily'` items older than the window across 3 users (7/2/1), running the command inserts exactly 3 rows into `do_notifications_digest_queue`, one per uid, `window='daily'`, `send_at=now`.
- [ ] AC3: The 10 consumed rows are deleted from `do_notifications_queue`.
- [ ] AC4: Each digest row's `body_text`/`body_html` was produced by `DigestRenderer::render()` for that user's messages (assert the rendered content contains a fragment that could only have come from those messages — e.g. count of bullets matches).
- [ ] AC5: A `frequency='daily'` item WITHIN the window (created > threshold) is NOT consumed and NOT digested.
- [ ] AC6: A `frequency='immediately'` or `frequency='weekly'` item older than the window is NOT consumed by this command.
- [ ] AC7: A user with zero items in the window produces zero digest rows (no empty-digest email).
- [ ] AC8: Command returns a summary result `['users_digested' => 3, 'items_consumed' => 10, 'digests_enqueued' => 3]` (or logs it — precise API-vs-log surface locked in T-red).
- [ ] AC9: `state.set('do_notifications.digest_daily.window_seconds', N)` overrides the default 24h window (tests use this to compress time).
- [ ] AC10: coding standards clean (`vendor/bin/phpcs docs/groups/modules/do_notifications`).

## Files expected to be touched

- `docs/groups/modules/do_notifications/do_notifications.install` — add `do_notifications_digest_queue` schema + `hook_update_9001()`.
- `docs/groups/modules/do_notifications/do_notifications.services.yml` — register `do_notifications.digest_queue` + `do_notifications.digest_command` (if command needs DI; Drush command classes are auto-discovered but their constructor deps come via `services.yml`).
- `docs/groups/modules/do_notifications/drush.services.yml` — NEW (register command for Drush).
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` — add 2 methods.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` — implement 2 methods.
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` — implement 2 methods.
- `docs/groups/modules/do_notifications/src/Queue/DigestQueueBackendInterface.php` — NEW.
- `docs/groups/modules/do_notifications/src/Queue/DatabaseDigestQueueBackend.php` — NEW.
- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php` — NEW.
- `docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php` — NEW.

## Handoff paths

- Survey: `docs/planning/handoffs/234-n6-daily-digest/survey.md`
- Brief: `docs/planning/handoffs/234-n6-daily-digest/brief.md` (this file)
- Decisions: `docs/planning/handoffs/234-n6-daily-digest/decisions.md`
- A: `handoff-A.md`
- T-red: `handoff-T-red.md`
- F: `handoff-F.md`
- T-green: `handoff-T-green.md`
- S: `handoff-S.md`

## Verification

- Kernel: `vendor/bin/phpunit -c web/core/phpunit.xml.dist docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php` (via assembled layout — run per repo CI pattern).
- Regression: full `do_notifications` kernel suite must remain green (schema change + interface extension touches sibling test surface).
- Lint: `vendor/bin/phpcs docs/groups/modules/do_notifications`.
