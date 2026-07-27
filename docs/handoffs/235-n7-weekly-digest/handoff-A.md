# Handoff-A: Phase 3 - #235 N-7 Weekly digest worker  (up-front plan review)

**Date:** 2026-07-26
**Branch:** 235-n7-weekly-digest
**Brief reviewed:** docs/handoffs/235-n7-weekly-digest/brief.md
**Reuse map:** brief.md §"Reuse & Analogous-Feature map"
**Wireframe:** N/A (no UI surface)
**Verdict:** PASS

## Summary

The brief proposes a strict N-6 mirror: one new method per frequency on the existing
`DigestCommands` class, one new `claimWeekly()` method on the existing `QueueBackendInterface`
(plus its two impls), and verbatim reuse of `DigestQueueBackendInterface`, its DB backend, the
digest schema, and `DigestRenderer`. Direct inspection of every N-6 source referenced in the
Reuse map confirms every asserted extension point is genuine: the interface's own docblock
already names `claimWeekly()` as the intended next symbol, the digest queue's `$window` param
and column already accept `'daily' or 'weekly'`, and `DigestRenderer::VALID_WINDOWS` already
whitelists `'weekly'`. No parallel classes/tables/interfaces are proposed. No drift; T can
author RED tests directly against this plan.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| — | — | — | — | Plan is consistent with existing patterns. | — |

Evidence checked against each concern in the prompt:

1. **N-6 extension vs. parallel path.** Plan extends the analogous objects named in the map;
   no new command class, no new queue backend, no new schema. Anti-pattern list is explicit.
2. **`claimWeekly()` symmetry.** `QueueBackendInterface::claimDaily()` docblock (lines 119–123)
   pre-declares `claimWeekly()` as an intentional sibling in "the same idiom — one explicit,
   grep-able method per frequency". The brief's shape (`claimWeekly(int $olderThan): array`,
   literal copy of `claimDaily()` with `'weekly'` in the frequency condition) matches this
   directive verbatim in both `DatabaseQueueBackend` (SQL condition swap) and
   `MockQueueBackend` (in-memory filter swap).
3. **`DigestQueueBackendInterface` frequency-agnostic.** Confirmed: `enqueue()`'s `$window`
   param is documented as `'daily' or 'weekly'` (line 44). `DatabaseDigestQueueBackend` already
   deployed by N-6. No change needed. Reuse-verbatim is correct.
4. **`DigestRenderer::render($messages, $recipient, 'weekly')`.** Confirmed: line 56 declares
   `private const VALID_WINDOWS = ['daily', 'weekly'];` and `WINDOW_LABELS` maps `'weekly' =>
   'week'`. `render()` fails fast on anything else — passing `'weekly'` is a first-class,
   pre-designed code path, not a hope-it-works string. The brief's "F must verify it doesn't
   hard-code 'daily' somewhere" note is already discharged by the constant.
5. **State key `do_notifications.digest_weekly.window_seconds`.** Unambiguous mirror of
   `do_notifications.digest_daily.window_seconds` (DigestCommands.php line 106). Distinct key,
   no collision risk. Default `604800` (7 * 86400) matches the AC5 boundary.
6. **Drush 12 attribute-command multi-method-per-class.** Verified by inspection of the
   existing setup: `DigestCommands` uses `Drush\Attributes\Command` at method level (not
   class-level `AsCommand`), and its class docblock (lines 36–46) explicitly documents this
   choice. A second `#[Command(name: 'do_notifications:digest-weekly')]`-attributed method on
   the same class is the idiomatic addition — no need to split into `WeeklyDigestCommands`.
   The single `do_notifications.digest_command` service registration in BOTH
   `do_notifications.services.yml` and `drush.services.yml` needs NO change (same class, same
   constructor deps, second command surfaces automatically via method attribute scan).
7. **Orphan-mid GC (AC10).** N-6's `digestUser()` already implements orphan-row garbage
   collection (DigestCommands.php lines 173–201); AC10 is a copy of that contract for the
   weekly path's private `digestUserWeekly()` helper. Sound.
8. **Message `created` int/string quirk.** N-6's `digestUser()` normalizes
   `$message->setCreatedTime((int) $message->getCreatedTime())` at the boundary (lines
   180–195) to avoid `gmdate()` TypeErrors downstream. The weekly helper must do the same. Not
   a blocker — flagging so T's test fixtures round-trip through storage (as N-6's do), and F
   remembers to copy this normalization; missing it would cause a runtime TypeError under AC2,
   caught by T's GREEN run, not architectural drift.

## Notes for O

None. Proceed to Phase 4 (T RED).

## Patterns referenced

- `docs/groups/modules/do_notifications/src/Commands/DigestCommands.php` (esp. lines 36–46 on
  attribute-command choice; 90–235 for the pattern the weekly method mirrors)
- `docs/groups/modules/do_notifications/src/Queue/QueueBackendInterface.php` (esp. lines
  119–123, `claimWeekly()` pre-declared as a sibling)
- `docs/groups/modules/do_notifications/src/Queue/DatabaseQueueBackend.php` (esp. lines
  148–172, `claimDaily()` shape to mirror)
- `docs/groups/modules/do_notifications/src/Queue/MockQueueBackend.php` (esp. lines 118–132)
- `docs/groups/modules/do_notifications/src/Queue/DigestQueueBackendInterface.php` (line 44,
  window arg accepts 'daily' or 'weekly')
- `docs/groups/modules/do_notifications/src/Email/DigestRenderer.php` (lines 54–64,
  `VALID_WINDOWS` / `WINDOW_LABELS` already include 'weekly')
- `docs/groups/modules/do_notifications/do_notifications.services.yml` +
  `drush.services.yml` (`do_notifications.digest_command` single service registration —
  no change needed for the added method)
- `docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php` (test
  structure T will mirror)
