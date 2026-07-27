# Handoff-S: Phase 9 — #234 N-6 Daily digest worker (spec audit)

**Date:** 2026-07-26
**Branch:** 234-n6-daily-digest
**Handoff-F reviewed:** `handoff-F.md` (POC lean; T-green folded into F)
**Handoff-T-red reviewed:** `handoff-T-red.md`
**Handoff-A reviewed:** `handoff-A.md`
**Brief reviewed:** `brief.md`
**Decisions reviewed:** `decisions.md`
**Issue reviewed:** #234
**Verdict:** **PASS** — with one mandatory pre-PR mechanical action (see §Pre-PR action).

## A precondition

Confirmed: A returned PASS (3 warns, 0 block).

## T precondition (POC lean, folded into F)

Confirmed: F reports 14/14 target tests GREEN, 35/35 module regression GREEN, phpcs clean, all
reproduced across 3 stable runs.

## Acceptance-criteria coverage (AC1–AC10)

| AC | Backed by test | Verdict |
|---|---|---|
| AC1 command callable | `DailyDigestCommandTest::command()` resolves `\Drupal::service('do_notifications.digest_command')` and invokes `digestDaily()` in every test | PASS |
| AC2 10 items / 3 users → 3 digest rows, `window='daily'`, `send_at=now` | `testDigestDailyAggregatesPerUserAndConsumesSourceRows` | PASS |
| AC3 10 source rows deleted, others remain | same test, asserts `sourceRowCount()==6` + `remaining_mids` set membership | PASS |
| AC4 digest body traces to that user's messages | same test, asserts user A's subject contains `"7 updates"` (DigestRenderer fragment count) | PASS |
| AC5 in-window daily item not consumed | same test (2 in-window rows survive) | PASS |
| AC6 `immediately`/`weekly` not consumed | same test (4 other-frequency rows survive) | PASS |
| AC7 zero-item user → zero digest rows | `testUserWithNoInWindowItemsProducesNoDigestRow` (isolated) + main scenario incidental `$user_zero` | PASS |
| AC8 summary shape `['users_digested', 'items_consumed', 'digests_enqueued']` | main scenario (3, 10, 3) | PASS |
| AC9 `state.set('window_seconds', N)` narrows window | `testWindowSecondsStateOverrideNarrowsWindow` (before/after with same row) | PASS |
| AC10 phpcs clean | F: `--standard=Drupal,DrupalPractice` exit 0 on all 9 production files | PASS |

**Extra coverage (locked by A + T-red beyond the brief):**
- Orphan mid: `testOrphanedMidIsDroppedAndDeletedWithoutBlockingOtherMessages` — pins A's finding
  option (a): drop from digest AND delete queue row.
- Interface contracts: `DatabaseQueueBackendTest::testClaimDailyFiltersOnFrequencyAndThreshold`,
  `testClaimDailyReturnsRowsAcrossMultipleUsers`, `testDeleteByIdsRemovesOnlySpecifiedRows`,
  `testDeleteByIdsWithEmptyArrayIsNoOp`.
- Digest queue contract: 4 tests in `DigestQueueBackendTest` (enqueue/all/deleteByIds semantics
  + no-dedup contract + empty-array-safety).

## Issue #234 alignment

Issue text: *"Nightly 2 AM UTC cron. Aggregates 24h daily-queue messages per user."*

- Nightly / cron entry: correctly deferred (ops concern, documented in `DigestCommands`
  docblock as `drush cron:add do_notifications:digest-daily "0 2 * * *"` — matches brief
  non-goal).
- 2 AM UTC configurable: `state.get('do_notifications.digest_daily.hour_utc', 2)` documented as
  informational; UTC-only per user decision.
- 24h window configurable: `state.get('do_notifications.digest_daily.window_seconds', 86400)`
  — verified in `DigestCommands::digestDaily()` (line 106) and pinned by AC9 test.
- Per-user aggregation: verified — main scenario proves 3 distinct digest rows for 3 uids.
- 50-cap via N-8: correctly delegated — `DigestRenderer::render()` (N-8, shipped) enforces its
  own 50-cap; `DigestCommands` does not duplicate the logic.
- Skip-empty: `testUserWithNoInWindowItemsProducesNoDigestRow` + explicit guard in
  `DigestCommands::digestUser()` line 203.
- Delete originals: `queue->deleteByIds($consumed_ids)` at end of `digestDaily()` line 124;
  main scenario asserts source row count drops 16→6.
- Kernel test with 10-items/3-users: `testDigestDailyAggregatesPerUserAndConsumesSourceRows`
  is exactly that scenario.

Alignment: complete.

## A's three warns — disposition

| Warn | Disposition | Verdict |
|---|---|---|
| #1 rename `hook_update_9001()` → `_11001()` | Honored verbatim; `do_notifications_update_11001()` in `.install` line 166 | PASS |
| #2 register `DigestCommands` in `drush.services.yml` ONLY | **Deviated (documented, justified):** registered in BOTH `do_notifications.services.yml` AND `drush.services.yml`. F's rationale verified: `\Drush\Runtime\LegacyServiceInstantiator` populates its own registry that is never merged into `\Drupal::getContainer()`, so a service registered only in `drush.services.yml` cannot be resolved via `\Drupal::service()` — which is exactly how the kernel test (and any future non-CLI caller) resolves it. Registering in both files gives the container-resolvable copy (used by tests + future callers) and the CLI-discoverable copy (used by real `drush`). The two instances are stateless with identical constructor deps; only one is ever invoked per process. This is a sound, evidence-backed correction to A's original recommendation. | ACCEPTED |
| #3 digest interface `all()` + `deleteByIds()`, no `drain()` | Honored verbatim; `DigestQueueBackendInterface` has no `drain()` (verified by reading the file) | PASS |

## F's second deviation (`Message::setCreatedTime((int) getCreatedTime())`)

**Verdict: ACCEPTABLE for this PR.** Do NOT block merge on moving this upstream.

Evidence F's characterization is accurate (spot-checked, not just accepted):
- `EmailRenderer::renderEventFragments()` line 202 passes `$message->getCreatedTime()` straight
  into a `created` slot the downstream code consumes as int.
- `DigestRenderer::render()` line 174 calls `gmdate('Y-m-d', $fragment['created'])`, which
  throws `TypeError` on a string.
- Every pre-existing `EmailRendererTest` / `DigestRendererTest` fixture creates a Message with
  `->save()` and hands the SAME in-memory object to the renderer (never reloads via
  `loadMultiple`), so the string-vs-int surface was never exercised before N-6.
- `DigestCommands` is the first caller to hand the renderer a genuinely storage-reloaded
  Message.

The in-memory normalization on line 195 is a one-line, non-persisted mutation at the boundary
this story owns — a clean fix. A follow-up to hoist the cast into
`EmailRenderer::renderEventFragments()` (or `DigestRenderer`) is worth doing, but under POC
lean's no-follow-ups rule, it is surfaced-once here and not filed.

## Anti-duplication (folded from A-dup step)

Reviewed the full staged diff (9 files, 741 lines added). Findings:

- `DigestRenderer` reused verbatim (called once from `DigestCommands::digestUser()` line 220;
  no token/render/aggregation duplication).
- `QueueBackendInterface` extended in place (not paralleled): `claimDaily()` + `deleteByIds()`
  added to the same file, both implementations `DatabaseQueueBackend` and `MockQueueBackend`
  kept in sync (no divergent contract).
- `DigestQueueBackendInterface` is genuinely new: distinct payload shape (`subject`,
  `body_text`, `body_html`, `send_at`, `window`, no `mid`), distinct contract (no dedup),
  distinct table. A's justification stands.
- `DigestCommands` is genuinely new: no prior `src/Commands/` class.
- `time.getRequestTime()` used consistently — no duplicate wall-clock plumbing.

**No hidden duplication found.**

## Architecture drift check

`DigestQueueBackendInterface` vs. sibling `QueueBackendInterface`:
- Naming: matches (`enqueue`, `count`, `deleteByIds`) — read-only accessor is `all()` (digest)
  vs. `drain()` (per-message) intentionally, per A's warn #3.
- Docblock cadence: matches (class-level rationale + per-method contract + rationale for
  design deltas).
- Constructor: matches (readonly promoted properties).
- Empty-array guard on `deleteByIds()`: matches (both files line 84–90).
- `{@inheritdoc}` on implementation methods: matches.

**No drift.**

## UTC-everywhere check

Grepped `docs/groups/modules/do_notifications/src` for timezone-sensitive constructs.

- `DigestCommands`: uses only `$this->time->getRequestTime()` (int UNIX ts) + integer
  arithmetic. No `date()`, no `DateTimeZone`, no `strtotime`. Docblock line 66 explicitly
  states "no user-timezone offset (UTC only, per brief)".
- `DigestRenderer`: uses `gmdate()` (UTC by definition) + `strtotime($day . ' 12:00:00 UTC')`
  (explicit UTC).
- `SubscriptionRouter::route()` line 136 uses `date('Y-m-d', ...)` — pre-existing N-2 code,
  out of scope for this story.

**UTC-only invariant holds for N-6.**

## Diff-gate check (folded)

`git diff --cached --stat`: exactly the 9 production files listed in F's handoff. No accidental
scratchpad, no `web/modules/custom/` build artifacts, no `config/sync/` churn in the staged
set.

**BUT — one PR-hygiene finding (see Pre-PR action below):** the 3 T-authored test files are
untracked/unstaged:
- `docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php` (NEW, untracked)
- `docs/groups/modules/do_notifications/tests/src/Kernel/DigestQueueBackendTest.php` (NEW, untracked)
- `docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php` (MODIFIED, unstaged)

Also unstaged/untracked in the tree (correctly not in this PR):
- `.ddev/*` config churn — T-red's environment fix (documented in handoff-T-red.md), not shipping.
- `config/sync/*` — pre-existing build/assembly artifacts on this branch.
- `web/modules/custom/`, `web/autoload_runtime.php`, `web/*` — assembled-layout artifacts.
- `docs/planning/handoffs/234-n6-daily-digest/*` — the handoff dir itself; ship if convention
  is to include planning docs in the PR (this repo's other N-* PRs vary).

## Scope check

Delivered exactly the phase scope. No over-delivery (no weekly digest, no delivery worker, no
new user-facing UI, no schema changes to `do_notifications_queue`). No under-delivery (all
AC1–AC10 satisfied).

## Test-quality audit (rubric per `testing/test-quality.md` §7)

| Criterion | Verdict | Notes |
|---|---|---|
| Per-test: names one behavior | PASS | Each test name is a specific behavior claim (aggregate/consume, narrow window, orphan garbage-collect, zero-item skip, empty-array no-op, filter-on-frequency-and-threshold). |
| Per-test: fails in isolation for right reason | PASS | Verified by T-red's RED run — 12 tests failed for schema-not-defined or method-undefined, none for authorship bugs. |
| Per-test: cheapest sufficient tier | PASS | Kernel is genuinely required for all 12 (real DB, real Message entities, real render pipeline). Unit-level would require stubbing exactly the integration this story is about. |
| Per-test: behavior not implementation | PASS | Asserts on public observable state (source row count, digest queue rows, summary return value, subject contents) — no assertions on internal method calls. |
| Per-suite: proportionate to change | PASS | 12 story-specific tests for 10 ACs + 1 orphan behavior + 4 method-contract guards. Not fan-out; each test guards a distinct claim. |
| No assertion-free/tautological | PASS | Every test has multiple, differentiating assertions. |
| No mock-shaped | PASS | Command invoked as a real container-resolved service; no method-call spies. |
| No snapshot-everything | PASS | Asserts on specific fields, not whole objects. |
| Suite consolidation opportunities | PASS | The main scenario correctly bundles AC2–AC6+AC8 into one integrated test (per prompt guidance); AC7 + AC9 + orphan correctly split out because each needs its own before/after fixture. |
| "Delete or merge" findings | NONE at test level. F flagged a minor dead-code helper (`sourceQueue()` defined on line 129 of `DailyDigestCommandTest.php`, never called). Non-blocking cosmetic lint; safe to remove or leave. |

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API consistency | PASS | Interface method signatures match neighboring `QueueBackendInterface`; DI wiring matches module convention. |
| Error handling | PASS | Empty-array guard on both `deleteByIds()`; orphan-mid handled; unloadable-recipient handled; missing-Message handled. |
| UI/UX | N/A | Backend service + Drush command; no UI surface. |
| Accessibility | N/A | No UI. |
| Architecture gate | PASS | A returned PASS; deviations reviewed above. |
| Code organization | PASS | Class docblocks are detailed and cross-reference the ecosystem accurately. `DigestCommands::digestDaily()` is a compact orchestrator (26 lines); `digestUser()` extracts per-user logic cleanly. |
| Security | PASS | No user input surface (cron-invoked). All DB access via query builder (parameterized). No secret handling. `insert()`/`select()`/`delete()` all use the Drupal DB API. |
| Performance | PASS | Single `claimDaily()` SELECT + `loadMultiple()` per uid + single `deleteByIds()` at end. No N+1 query pattern. `orderBy('uid', 'ASC')` on `claimDaily()` enables efficient PHP-side grouping. |
| Visual regression | N/A | No UI. |
| Naming consistency | PASS | Matches N-1/N-2/N-4/N-8 idioms: `Database*Backend`, `*BackendInterface`, `*Renderer`, `*Commands`. State keys namespaced `do_notifications.digest_daily.*`. |
| Test quality | PASS | See §Test-quality audit above. |

## Pre-PR action (mandatory)

Before running `gh pr create`, stage the three T-authored test files. Otherwise the PR ships
production code with no tests visible to CI:

```bash
git add \
  docs/groups/modules/do_notifications/tests/src/Kernel/DailyDigestCommandTest.php \
  docs/groups/modules/do_notifications/tests/src/Kernel/DigestQueueBackendTest.php \
  docs/groups/modules/do_notifications/tests/src/Kernel/DatabaseQueueBackendTest.php
```

(All 3 are explicitly listed by path — no `git add -A` — per repo hygiene. The other unstaged
paths in the tree are correctly excluded: build artifacts, `.ddev/*` env fixes, pre-existing
`config/sync/*` churn.)

This is mechanical PR hygiene, not a code defect. Verdict remains PASS.

## Advisory notes (non-blocking, no follow-up filed per POC no-follow-ups)

1. **`sourceQueue()` unused helper** in `DailyDigestCommandTest.php` line 129 — safe to remove
   in a future editing pass. Not a functional issue; not worth its own commit.
2. **`Message::getCreatedTime()` string-vs-int quirk** — could be hoisted into
   `EmailRenderer::renderEventFragments()` as a `(int)` cast on the `created` slot, which
   would remove the boundary normalization from `DigestCommands` and protect any future
   caller. F's fix is correct for this PR; upstream fix is a future refactor if/when someone
   else hits the same seam.

## Verdict

**PASS** — all acceptance criteria met, spec-compliant, quality acceptable, no architectural
drift, no anti-duplication, UTC-only holds. Ready for O to stage the 3 test files (see Pre-PR
action) and open the PR.
