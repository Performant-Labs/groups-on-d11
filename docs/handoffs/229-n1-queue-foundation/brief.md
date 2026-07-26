# Brief — #229 N-1 Queue foundation + LogMailer

## Objective
Land the DB-backed queue + LogMailer notifier that N-2's mock queue is a placeholder for. After this story, `do_notifications.queue` service is a real DB-persistent queue, and message_notify has a `LogMailer` Notifier plugin that writes deliveries to watchdog + a file sink.

## Scope
1. `Drupal\do_notifications\Queue\DatabaseQueueBackend implements QueueBackendInterface`
   - Schema: `do_notifications_queue` table with columns `id` (serial PK), `uid` (int), `mid` (int), `template` (varchar 128), `frequency` (varchar 32), `day` (varchar 10), `created` (int UNIX ts). Unique key on `(uid, mid, frequency, day)`.
   - `enqueue($item)`: use `$database->merge('do_notifications_queue')->keys([...])->fields([...])->execute()` — idiomatic Drupal upsert, portable across MySQL/PostgreSQL, no exception-as-control-flow. Match `MockQueueBackend` dedup semantics exactly (silent drop on repeat tuple).
   - `drain()`: SELECT all rows in `id` order, DELETE all, return array of items (uid/mid/template/frequency/day).
   - `count()`: SELECT COUNT(*).
   - Constructor: injects `@database`.
2. `Drupal\do_notifications\Plugin\Notifier\LogMailer` — `@Notifier` annotated (id=log_mailer, title="Log Mailer", viewModes={email}).
   - `deliver(array $output)`: writes one log line per delivery to `\Drupal::logger('do_notifications')`, appends one-liner to the log file (path from `\Drupal::service('file_system')->getTempDirectory() . '/notifications.log'` — portable + kernel-testable, not hardcoded `/tmp`). Returns `TRUE`.
   - Line format: `[YYYY-MM-DDTHH:MM:SSZ] uid=<uid> mid=<mid> template=<template>` (UTC per epic).
3. `docs/groups/modules/do_notifications/do_notifications.install` — `do_notifications_schema()` returning the table definition. Keep MockQueueBackend class intact for unit tests.
4. Swap `do_notifications.queue` service class → `DatabaseQueueBackend`, add `arguments: ['@database']`.

## Extend-vs-new
- **Extend:** N-2's `QueueBackendInterface` — do NOT add a `retry` field or method (Option A trim).
- **New (justified):** `DatabaseQueueBackend` class (parallel implementation, not duplication — that's the swap point), `.install` file (module has none), `LogMailer` plugin.

## Acceptance criteria
- [ ] `DatabaseQueueBackend` implements `QueueBackendInterface` — signatures identical.
- [ ] `do_notifications.services.yml` swaps class + injects `@database`.
- [ ] `do_notifications.install` creates `do_notifications_queue` with unique key.
- [ ] `LogMailer` is a `@Notifier` plugin registered under `Plugin/Notifier/`.
- [ ] Kernel test: install schema → enqueue 3 distinct items → assert row count = 3, `drain()` returns 3 items, DB now empty → instantiate LogMailer, invoke `deliver()` 3× → assert 3 watchdog entries (`\Drupal::logger` captured) + 3 lines appended to log file path.
- [ ] Dedup test: enqueue same tuple twice → count=1, drain returns 1.
- [ ] `phpcs` clean on all new/changed files.

## Non-goals
- No retry column, no `claim/release`, no cron worker. All deferred to N-3+.

## Notes for F (from A review)
- Use `merge()` NOT try/catch on unique violation.
- Use `file_system->getTempDirectory()` NOT hardcoded `/tmp`.

## Handoffs
`docs/handoffs/229-n1-queue-foundation/{decisions,brief,survey}.md`, plus per-phase handoff-A/T/F/S.

## Branch
`229-n1-queue-foundation` (in worktree `~/Projects/_worktrees/groups-n1-queue-foundation-229`).

## Review-rigor
`none` — POC lean. Diff-gate only.
