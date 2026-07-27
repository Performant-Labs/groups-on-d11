# Brief — Issue #231: N-3 Notification delivery worker

**Epic:** #237 Notifications. **Depends on:** N-1 (#229, merged — DatabaseQueueBackend + LogMailer), N-4 (#232, merged — EmailRenderer), N-8 (#236, merged — DigestRenderer).

**Review rigor:** none (POC lean pipeline: O → A → T(RED) → F → T(GREEN) → diff-gate → S → PR → CI-green → self-merge).

## Objective

Ship a Drush command `do_notifications:deliver` that drains queued notifications and delivers each via the LogMailer notifier, wiring the four existing subsystems (queue backend, email renderer, digest renderer, log mailer) into one end-to-end pipeline. No scheduling; user/ops runs the command.

## Acceptance criteria

1. Drush command class `Drupal\do_notifications\Drush\Commands\DoNotificationsCommands` exposes `do_notifications:deliver` (alias `do:notif:deliver`) with option `--type=<all|immediately|daily|weekly>` (default `all`).
2. Command drains queue entries matching the requested frequency filter (all if `all`), leaving non-matching entries in the queue.
3. For each drained entry:
   - `immediately` frequency: load the Message, load the recipient, call `EmailRenderer::render()`, then invoke `log_mailer` via message_notify's plugin manager with the `{uid, mid, template}` output payload.
   - `daily` / `weekly` frequency: group entries by recipient (uid), for each recipient load all their Messages, load the recipient user, call `DigestRenderer::render($messages, $recipient, 'daily'|'weekly')`, then invoke `log_mailer` ONCE per recipient with `{uid, mid: first-mid, template: 'digest_<window>'}`.
4. Command is idempotent: a second run with nothing queued exits 0, writes nothing.
5. Skips (does not fail) individual entries whose Message or recipient no longer exists; logs a warning to watchdog channel `do_notifications`.
6. Kernel test: enqueue 3 `immediately` + 2 `daily` entries → run command with `--type=immediately` → assert 3 file-sink log lines + 3 watchdog entries + queue count == 2 (daily remaining).
7. `QueueBackendInterface::drain()` signature extended to `drain(?string $frequency = null): array` (BC — existing callers pass no arg); MockQueueBackend + DatabaseQueueBackend both updated. Existing DatabaseQueueBackendTest / MockQueueBackend tests still pass.

## Reuse & Analogous-Feature map

- **Analogous feature:** none in this repo has drush commands. Nearest analogous invocation surface = `SubscriptionRouter` (called from a hook to fan out subscriptions into queue writes). This story is the mirror: fan out queue reads into deliveries.
- **Extend, do NOT duplicate:**
  - `Drupal\do_notifications\Queue\QueueBackendInterface::drain()` — **extend signature** with optional `?string $frequency = null`. Both impls updated. No new "drainByFrequency" method.
  - `Drupal\do_notifications\Email\EmailRenderer::render()` — **reuse as-is** for per-event; already returns `{subject, body_text, body_html}`.
  - `Drupal\do_notifications\Email\DigestRenderer::render()` — **reuse as-is** for digest windows.
  - `Drupal\do_notifications\Plugin\Notifier\LogMailer::deliver()` — **reuse as-is**; call via `plugin.message_notify.notifier.manager` service.
- **New objects (justified):**
  - `src/Drush/Commands/DoNotificationsCommands.php` — no existing drush commands in this module; a new commands class is the standard Drush 13 pattern (attributes-based). Registered via `drush.services.yml`.

## Non-goals

- Cron scheduling (user/ops concern).
- Retry logic on failed delivery (LogMailer never fails; retry design deferred to when a real mailer replaces it).
- Alerting on service downtime.
- New UI (this is CLI-only).
- Modifying LogMailer's payload contract.

## Input documents

- Issue: gh issue view 231
- Epic: #237
- Predecessor PRs: #260 (N-1), #232 (N-4), #236 (N-8)
- Queue interface: `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php`
- Renderers: `docs/groups/modules/do_notifications/src/Email/{EmailRenderer,DigestRenderer}.php`
- Notifier: `docs/groups/modules/do_notifications/src/Plugin/Notifier/LogMailer.php`

## Files expected to change

- **New:** `docs/groups/modules/do_notifications/src/Drush/Commands/DoNotificationsCommands.php`
- **New:** `docs/groups/modules/do_notifications/drush.services.yml`
- **New:** `docs/groups/modules/do_notifications/tests/src/Kernel/DeliveryWorkerTest.php`
- **Extend:** `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (drain signature)
- **Extend:** `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (drain impl)
- **Extend:** `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (drain impl)

## Forward-compat check

No downstream N-* story consumes the drush command surface directly; N-9 (scheduler) will invoke it via cron. Extending `drain()` with an optional arg preserves BC for every existing caller. No conflict.

## Handoff paths

`docs/handoffs/231-n3-delivery-worker/{brief,survey,decisions,handoff-A,handoff-T-red,handoff-F,handoff-T-green,handoff-S}.md`

## Branch / PR

- Branch: `231-n3-delivery-worker` (base `main` @ 3ec16f2, worktree at `~/Projects/_worktrees/groups-n3-delivery-worker-231`).
- PR body: "Closes #231. Part of epic #237. drush do_notifications:deliver — consumes DatabaseQueueBackend (N-1) + renders N-4 templates + delivers via LogMailer."
