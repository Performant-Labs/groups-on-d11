# Brief — #235 (N-7: Weekly digest worker)

**Epic:** #237 Notifications. **Depends on:** N-3 (#231, MERGED afebdec) + N-6 (#234/#267, merging).
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-n7-weekly-digest-235`
**Branch:** `235-n7-weekly-digest` (rebased on origin/main + will re-rebase after N-6 merges).
**Review-rigor:** `none` (POC lean pipeline).

## Objective

Deliver `drush do_notifications:digest-weekly`: aggregates every `frequency='weekly'` row in `do_notifications_queue` older than 7 days into one digest per recipient, enqueues each into `do_notifications_digest_queue` with `window='weekly'`, deletes consumed source rows. Skips empty digests. Uses N-8's `DigestRenderer` verbatim (its 50-fragment cap already applies).

## Reuse & Analogous-Feature map (mandatory)

**Analogous feature:** N-6 daily digest (`DigestCommands::digestDaily()`, MERGING in PR #267).

**Extend, do not duplicate:**

| Object | Action | Rationale |
|---|---|---|
| `DigestCommands` class | **EXTEND** — add `digestWeekly()` method + private `digestUserWeekly()` helper alongside existing `digestDaily()` / `digestUser()`. | N-6 already registers this class as `do_notifications.digest_command` in both `do_notifications.services.yml` and `drush.services.yml`. Drush 12+ attribute commands allow multiple `#[Command]` methods per class. NO new command class. |
| `QueueBackendInterface` | **EXTEND** — add `claimWeekly(int $olderThan): array`, same shape and idiom as `claimDaily()`. `deleteByIds()` is REUSED verbatim (already frequency-agnostic). | Class docblock (N-6) already names `claimWeekly()` as the intentional next call. |
| `DatabaseQueueBackend::claimWeekly()` | **ADD** — literal copy of `claimDaily()` with `'weekly'` in the frequency condition. | Symmetric implementation; one explicit grep-able method per frequency. |
| `MockQueueBackend::claimWeekly()` | **ADD** — literal copy of the in-memory `claimDaily()`, `'weekly'` filter. | Test parity. |
| `DigestQueueBackendInterface` / `DatabaseDigestQueueBackend` | **REUSE VERBATIM.** No change. | The `window` column already accepts 'weekly'; N-6's docblock and column description explicitly document this. |
| `DigestRenderer` (N-8) | **REUSE VERBATIM.** Call `render($messages, $recipient, 'weekly')`. | Its window arg is a free string; N-6 passes 'daily'. F must verify it doesn't hard-code 'daily' somewhere. |
| Digest queue schema | **REUSE.** No schema change. | The table is already deployed by N-6. |
| State override key | **NEW** — `do_notifications.digest_weekly.window_seconds`, default `604800` (7d). | Symmetric with `do_notifications.digest_daily.window_seconds`. |

**No new objects except: (1) two `claimWeekly()` methods (interface + 2 impls), (2) one `digestWeekly()` method + private helper on existing `DigestCommands`.**

**Anti-pattern to reject:** F creating a `WeeklyDigestCommands` class, a `WeeklyDigestQueueBackend`, or a `digest_queue_weekly` table. All would fork infra N-6 designed to be shared.

## Acceptance criteria

Mirror N-6's AC1–AC9 with weekly semantics:

- **AC1:** `drush do_notifications:digest-weekly` is a registered/callable command (verified via kernel test invoking `\Drupal::service('do_notifications.digest_command')->digestWeekly()`).
- **AC2:** 12 weekly items across 4 users (older than 7d) → exactly 4 rows in `do_notifications_digest_queue`, all with `window='weekly'`.
- **AC3:** The 12 consumed source rows are deleted from `do_notifications_queue`.
- **AC4:** Each user's digest subject reflects the correct fragment count from THAT user's own messages.
- **AC5:** A weekly item within the 7-day window is NOT consumed.
- **AC6:** `daily`/`immediately` rows older than 7d are NOT consumed.
- **AC7:** A user with zero in-window weekly items produces zero digest rows.
- **AC8:** `digestWeekly()` returns `['users_digested' => int, 'items_consumed' => int, 'digests_enqueued' => int]`.
- **AC9:** `state.set('do_notifications.digest_weekly.window_seconds', N)` narrows the window (mirrors N-6 AC9).
- **AC10 (orphan):** A queue row whose `mid` no longer resolves to a Message is dropped from the digest AND its own row is deleted (garbage-collected) — but does not block digesting the user's other valid messages.

## Test

**Kernel test:** `tests/src/Kernel/WeeklyDigestCommandTest.php` — structural copy of N-6's `DailyDigestCommandTest.php` with `weekly`/`604800` s substituted. Scenarios: (1) 12 items × 4 users integrated flow, (2) window-seconds state override, (3) orphan-mid garbage collection, (4) zero-in-window user produces nothing. `#[RunTestsInSeparateProcesses]`, extends `GroupsKernelTestBase`.

## Handoff files

- `docs/handoffs/235-n7-weekly-digest/decisions.md` (owned by O; every phase appends)
- `docs/handoffs/235-n7-weekly-digest/brief.md` (this file)
- `docs/handoffs/235-n7-weekly-digest/handoff-A.md`, `handoff-T.md`, `handoff-F.md`, `handoff-T-green.md`, `handoff-S.md` (ephemeral)

## DDEV / CI

DDEV project `gm235-weekly`. CI runs the standard 3-check matrix (kernel/functional/E2E). Post-PR foreground poll per prompt.
