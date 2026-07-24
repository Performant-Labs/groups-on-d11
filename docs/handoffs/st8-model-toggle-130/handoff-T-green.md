# Handoff-T-green: Phase 6 - ST-8 Model comparison toggle (#130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Issue:** #130
**Handoff-F reviewed:** `docs/handoffs/st8-model-toggle-130/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/st8-model-toggle-130/handoff-T-red.md`

## Execution environment note

Same constraint F documented: the worktree has no `vendor/`, and DDEV (`pl-groups-on-d11`)
mounts only the primary checkout (`~/Projects/groups-on-d11`) via Mutagen. Followed F's
documented procedure: backed up the primary checkout's `docs/groups/modules/{do_streams,
do_showcase,do_chrome}` + `docs/groups/config` + confirmed no `tests/e2e/model-toggle.spec.ts`
existed there, overlaid the worktree's current versions of the same, ran `composer install`
(vendor was also missing there) + `scripts/ci/assemble-config.sh` inside `ddev exec`, ran every
verification command below, then **fully reverted** the primary checkout: restored the four
backed-up trees, deleted the copied e2e spec, removed `web/modules/custom`/`vendor`, and
`git checkout -- config/sync`. Diffed `git status --short` post-revert against a pre-session
snapshot — **byte-identical**, confirmed clean. No file was authored in the primary checkout;
every production file was already committed in the worktree (F's work) before this session
started.

## GREEN confirmation

**Kernel — target test** (`StreamModelTogglePreRenderTest.php`):
```
FFFFFFFD -> now DDDDDDDD                                            8 / 8 (100%)
Tests: 8, Assertions: 201, Deprecations: 5, PHPUnit Deprecations: 9.
```
0 Failures, 0 Errors. All 7 previously-RED assertions now pass (switcher injection, cache
context, default/deep-link/fallback resolution, library attachment); the negative-case test
(`testHookDoesNotFireForADifferentViewId`) stayed green throughout, as required.

**Unit — target tests** (`StreamModelHelpTextTest.php` + `VariantSwitcherTest.php` +
`ShowcaseCatalogTest.php`):
```
28 / 28 (100%)
Tests: 28, Assertions: 154, PHPUnit Deprecations: 31.
```
0 Failures, 0 Errors — includes all 15 pre-existing `VariantSwitcherTest` cases, 9 pre-existing
`ShowcaseCatalogTest` cases (both unaffected by the additions), the 1 new
`testStreamModelOptions`, 1 new `testStreamModelEntryIsLiveWithActivityStreamRouteAnd
CorrectedDecisionSentence`, and both new `StreamModelHelpTextTest` cases.

**Spot-check — test still fails if behavior is removed:** Read `ModelToggleHooks.php` in full.
Confirmed the Kernel test asserts observable output only (wrapper `data-do-stream-model`
attribute value, `#cache['contexts']` array membership, `#header['switcher']` presence,
`#attached['library']` membership) — none of it couples to `ModelToggleHooks`'s internal method
names or private helpers (`isStreamModelView()`/`requestedVariant()` are never referenced by the
test). Removing any of the three `viewsPreRender()` responsibilities or the
`preprocessViewsView()` injection would immediately fail the corresponding assertion — this is
the same shape as the original RED (each assertion failed for exactly the absence of the
behavior it now confirms), so the suite pins behavior, not implementation.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Kernel target suite | `phpunit ... StreamModelTogglePreRenderTest.php` | 8/8 pass | 8/8 pass, 0 failures | PASS |
| Unit target suites | `phpunit ... StreamModelHelpTextTest.php VariantSwitcherTest.php ShowcaseCatalogTest.php` | 28/28 pass | 28/28 pass, 0 failures | PASS |
| `FollowingFeedTest.php` (delegation-fix specific check) | `phpunit ... FollowingFeedTest.php` | 2/2 pass (unchanged) | 2/2 pass, 0 failures — `/following` library attach still fires through the `DoStreamsHooks::preprocessViewsView()` delegation | PASS |
| Full do_streams+do_showcase+do_chrome sweep (Kernel+Unit+Functional) | `phpunit ... $(find ... -path '*/tests/src/Kernel') $(find ... Unit) $(find ... Functional)` | all pre-existing green | **159 tests, 2103 assertions, 0 failures/errors** (only deprecation noise) | PASS |
| phpcs on 7 claimed-clean files | `phpcs --standard=Drupal,DrupalPractice` on `ModelToggleHooks.php`, `model-toggle.css`, `do_streams.libraries.yml`, `do_streams.services.yml`, `do_streams.info.yml`, `VariantSwitcher.php`, `ShowcaseCatalog.php` | 0 findings | 0 findings | PASS |
| phpcs on `DoStreamsHooks.php` | `phpcs ...` | only pre-existing warnings on untouched lines | 0 errors, 4 warnings — verified by direct Read: lines 156/185/217 (`applyLastActivityRanking`/`applyHotRanking`/`applyPinnedRanking`, untouched) and line 334 (`viewsPostRender`, untouched); the new delegation line (456) has 0 findings | PASS |
| phpcs on `HelpText.php` | `phpcs ...` | only pre-existing findings, none on new/modified lines 180-188/293-301 | 18 errors + 8 warnings, all on lines 21-178; grep confirmed the new key (line 188) and the corrected key (lines 300-301) carry zero findings | PASS |
| Server/build sanity | `composer install` + `assemble-config.sh` inside DDEV | succeeds | succeeded (both worktree and primary checkout required `composer install` first — vendor was absent in both) | PASS |

## Tier 2 results

- **Test coverage:** Every AC (1-10) maps to at least one authored test, per T-red's table and
  the brief's AC list. AC-8 (WCAG/keyboard) is explicitly U's job (Playwright-MCP live
  walkthrough), not T's — correctly deferred, not a coverage hole.
- **Test quality:** Spot-checked (see "GREEN confirmation" above) — the Kernel suite asserts
  behavior (attribute values, cache-context membership, header injection, library attachment),
  not implementation internals. No redundant tests found: each Kernel case pins a distinct
  criterion (default/deep-link/fallback/library-attach/cache-context/negative-scope), no
  duplication with the Unit-level `VariantSwitcherTest`/`ShowcaseCatalogTest` additions (those
  pin the pure-function/data-shape layer, the Kernel test pins the render-pipeline integration —
  cheapest-sufficient-tier split is correct, matches #124's proven precedent). Suite size (8
  Kernel + 4 Unit = 12 new test methods) is proportionate to the change (one new hook class, one
  new switcher-options method, one catalog flip, two HelpText keys).
- **Type safety:** `declare(strict_types=1)` present in `ModelToggleHooks.php`; constructor
  properties are explicitly typed (`?VariantSwitcher`, `RequestStack`); no `mixed`/untyped
  parameters introduced in production code (the pre-existing `mixed $query` in
  `DoStreamsHooks.php`'s ranking methods is untouched, unrelated to this story).
- **Error handling:** Fallback path (unavailable/unknown `?variant=` value) is tested
  (`testUnavailableContentQueryParamFallsBackToActivity`, `testUnknownQueryParamFallsBackToActivity`)
  and reuses `VariantSwitcher::resolveCurrent()`'s existing fallback rule rather than
  hand-duplicating it — no new error-handling logic was introduced to test separately.
- **Data integrity:** N/A — no schema, entity, or database write path introduced (hook-only,
  render-pipeline feature; confirmed by F's handoff and by the Files-changed list, no migration/
  schema files touched).
- **API contract:** `streamModelOptions()`'s return shape verified via `assertSame()` against
  the literal brief-specified array (id/label/available key order and values) — matches
  `directoryLayoutOptions()`'s established shape 1:1 as required.
- **Security:** No new user input is trusted beyond the pre-existing `?variant=` query-string
  pattern (already validated via the same `resolveCurrent()` allowlist-fallback rule #124 uses);
  no new access-control surface (view display remains equally publicly viewable as before — this
  story adds a header decoration, not a new route/permission).
- **Migration safety:** N/A — no `.install`/schema files changed.
- **Playwright / E2E:** `npx playwright test tests/e2e/model-toggle.spec.ts --list` (Phase-4
  registration check) confirmed 11 tests register cleanly. A **live** run was attempted this
  phase and found **unrunnable in this session**: no `gm130-toggle` (or any per-story) DDEV
  project exists yet, and the primary checkout's Windows-host `node_modules` for Playwright are
  not installed (`npx playwright test ... --list` at the host level threw
  `MODULE_NOT_FOUND` resolving `playwright.config.ts`'s own dependency chain — an environment
  gap, not a test-authorship defect). **This is a coverage hole for this local pass, not a
  pass** — flagging per the task's own instruction so **U** knows `/stream`'s switcher surface
  (AC-1 through AC-7, AC-9) has NOT yet been exercised live in this session and must be walked
  by U/CI. The CI runner (Uranus pl-runner container) is expected to run this against a fully
  seeded, assembled site per the standard pipeline.

## Acceptance criteria status

| AC | Criterion | Status | Backing test |
|---|---|---|---|
| AC-1 | Switcher renders w/ 2 options (Content soon-disabled, Activity selected) | PASS (Kernel) | `testSwitcherInjectedWithTwoOptionsInOrder` |
| AC-2 | Wrapper `data-do-stream-model="activity"` on default load | PASS (Kernel) | `testNoQueryParamDefaultsWrapperToActivity` |
| AC-3 | `?variant=content` falls back to `activity` | PASS (Kernel) | `testUnavailableContentQueryParamFallsBackToActivity` |
| AC-4 | `?variant=activity` deep link, no reload flash | PASS (Kernel; server-resolved, no flash by construction) | `testActivityQueryParamSetsWrapperToActivity` |
| AC-5 | `/showcase` lists `stream-model` as `live` w/ working link | PASS (Unit) | `testStreamModelEntryIsLiveWithActivityStreamRouteAndCorrectedDecisionSentence` |
| AC-6 | Tooltip copy present, matches approved text | PASS (Unit) | `testSwitcherStreamModelTooltipCopyIsPresentAndMatchesApprovedCopy` |
| AC-7 | Content option `aria-disabled`, `tabindex="-1"`, "(soon)" label | PASS (Kernel, render-array shape) | `testSwitcherInjectedWithTwoOptionsInOrder` |
| AC-8 | WCAG 2.2 AA radiogroup, keyboard, focus, non-color cue | **DEFERRED to U** — not T's tier | N/A — U walkthrough |
| AC-9 | `url.query_args:variant` cache context bubbles, no cross-variant pollution | PASS (Kernel) | `testViewDeclaresUrlQueryArgsVariantCacheContextDirectly` |
| AC-10 | No regression on #124 directory toggle / #116 activity_stream | PASS | Full 159-test sweep, 0 failures; `FollowingFeedTest` specifically re-verified |

## Blocking issues

None.

## Advisory notes

- E2E is registration-verified only in this local pass (11 tests list cleanly); the live run is
  unverified locally for environment reasons (no seeded per-story DDEV project, host-level
  Playwright deps absent) and must be confirmed by U/CI before this story is considered fully
  walked. This is the same posture the task instructions anticipated and explicitly permit.
- phpcs docblock/house-style findings on the 4 test files (`StreamModelTogglePreRenderTest.php`,
  `VariantSwitcherTest.php`, `ShowcaseCatalogTest.php`, and pre-existing findings pattern) match
  the same category T-red already flagged as non-blocking, consistent with the merged
  `DirectoryTogglePreRenderTest.php` precedent's own findings — no new category introduced.
- F's non-regression claim (125 tests, Unit+Kernel only) is a subset of this session's broader
  159-test sweep (which also included the do_chrome/do_showcase Functional suites) — both are
  0-failure; the broader number is not a discrepancy, just a wider net.

## Decision

**T-green complete, no blocking issues.** This is a UI surface (`/stream` switcher + `/showcase`
catalog entry + tooltip) — **ready for U**, with the explicit note above that the E2E live run
is unverified locally and U's walkthrough is the first live exercise of AC-1 through AC-9 plus
the WCAG/keyboard AC-8.
