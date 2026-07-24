# Handoff-F: Phase 5 rework - #112 ST-3 /my-feed/events (Events + My RSVPs)

**Date:** 2026-07-23
**Branch:** `112-events-rsvps`
**Issue:** #112

This is a **targeted rework** in response to `handoff-T-green.md`'s BLOCKED verdict. It does not
redo the full ST-3 implementation — see `handoff-F.md` (the original Phase-5 handoff, preserved
as-is) for that. This document covers only the fix for T-GREEN's one production-code blocker.

**Note on `handoff-F.md`'s "Blocking finding for O" section:** that section's branch-topology
claim (ST-1 content missing from this branch) is **stale and out of scope for this rework** — the
branch is intentionally a single commit (`836a884`) off `main`, not stacked on any sibling story
branch, per this task's own instructions. Not re-litigated here.

## What was done

- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extend) — added a new
  `suppress_default_chrome` boolean variable (default `FALSE`) to the `do_streams_shell` theme
  hook's `variables` declaration (`theme()`), and changed `preprocessDoStreamsShell()` to check
  this flag: when `TRUE`, leaves `scope_tabs`/`ranking_control` both empty instead of building the
  default 4-tab/2-pill lists.
- `docs/groups/modules/do_streams/src/Controller/MyEventsController.php` (extend) — `buildShell()`
  now passes `'#suppress_default_chrome' => TRUE` on the `#theme => do_streams_shell` render array.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` (extend) — wrapped the
  `<nav class="shell-tabs">` block in `{% if scope_tabs %}` and the `<div class="shell-ranking">`
  block in `{% if ranking_control %}`, so an empty list suppresses the wrapper entirely (no
  empty-but-present landmark) rather than just producing zero `<span>` children inside a
  still-rendered `<nav>`/`<div>`.

## Design decisions

**Why a new boolean flag, not "respect a caller-supplied empty array" as the task literally
worded it.** I traced `\Drupal\Core\Theme\ThemeManager::render()` (lines ~190-213) directly before
implementing: when a `#theme => do_streams_shell` render array reaches `ThemeManager::render()`,
it converts each `#`-prefixed property to a plain `$variables` entry via
`array_key_exists("#$name", $element)` (not `isset()`), then backfills any STILL-unset key from
`hook_theme()`'s own declared default via `$variables += $info['variables']`. Since
`scope_tabs`/`ranking_control` both default to `[]` in that declaration, **a caller who explicitly
sets `#scope_tabs => []` and a caller who never sets `#scope_tabs` at all are indistinguishable by
the time `preprocessDoStreamsShell()` runs** — both see `$variables['scope_tabs'] === []`. This is
not a theoretical edge case: `StreamsShellTest::preprocessShellVariables()` (T's own pre-existing
Kernel-test harness, which calls this preprocess method directly, bypassing
`ThemeManager::render()`) ALSO pre-seeds `$variables['scope_tabs'] = []`/
`$variables['ranking_control'] = []` before invoking the hook, on the explicit expectation that the
hook OVERWRITES that empty seed with the full lists regardless (all 6 of that suite's contract
tests assert on the full 4-tab/2-pill output). An emptiness-based check would have broken all 6 of
those tests to fix this one bug — a straight regression trade, not a fix. A dedicated flag,
defaulting `FALSE` and left untouched by every existing caller (including that Kernel test's own
harness, which never sets this new key), is the only mechanism that is both backward-compatible
(every pre-#112 caller/test keeps building the full default lists, confirmed by re-running
`StreamsShellTest` — 6/6 still pass unmodified) and correctly suppressible by a caller that opts in
explicitly. Recorded at length in both `DoStreamsHooks.php`'s own docblock and here, since this is
a genuine correction of the task brief's literal mechanism, not merely an implementation detail.

**Twig guards, not just relying on `{% for %}` over `[]` producing zero children.** `{% for tab in
scope_tabs %}` over an empty array already produces no `<span>` children on its own — but the
`<nav aria-label="Stream scope">` wrapper and the `<div class="shell-ranking">`/`role="group"`
wrapper would still render as empty, childless landmarks. An empty landmark with `aria-label`
serves no purpose and is itself a small WCAG 2.2 AA concern (confusing screen-reader landmark
navigation to a no-op region), so I added `{% if %}` guards per the task's own item 4 instruction,
suppressing the wrapper entirely rather than leaving an empty shell of it.

**Verified this doesn't distinguish/break `StreamsShellTest::testNoHardcodedRoutePathsInRenderedTabMarkup`**
(the one test in that suite that goes through the real `renderRoot()`/`ThemeManager::render()`
pipeline rather than calling the preprocess method directly): that test's own
`buildShellRenderArray()` helper sets `#active_scope`/`#active_ranking`/`#results` only, no
`#suppress_default_chrome` key — so it defaults to `FALSE`, the full tab list still builds, and
`{% if scope_tabs %}` is truthy (non-empty), so nothing is suppressed for that test. Confirmed by
re-running the full `StreamsShellTest` suite: 6/6 pass, unmodified.

## Reuse / extend-vs-new

- Extended the EXISTING `do_streams_shell` theme-hook contract (per handoff-T-green.md's own
  suggested fix shape (a), "a small, backward-compatible template change") rather than option (b)
  (MyEventsController bypassing the shared shell theme hook entirely and rendering its own
  wrapper). Option (a) keeps `MyEventsController` consistent with every other `do_streams` story's
  own `#theme => do_streams_shell` invocation pattern (`MyFeedController`, the `following_feed`
  view's preprocess) — no parallel/duplicate shell markup introduced.
- No new files. All three edits are extensions of existing production files this issue (and
  #109/#110) already owns.

## Architecture notes for A

- **`suppress_default_chrome` is a new, backward-compatible `do_streams_shell` theme-hook
  contract variable.** This IS the "one-line-of-reasoning decision" handoff-T-green.md flagged for
  A to re-check at Phase 7 — a genuine shell-contract change, but additive (new optional variable,
  defaults preserve 100% of existing behavior for every caller that doesn't set it) rather than a
  breaking change to `scope_tabs`/`ranking_control`'s own existing shape.
- Every existing `do_streams_shell` caller (`MyFeedController` on `origin/main` post-merge,
  `StreamsShellTest`'s own harness, the `following_feed` view's rendering path) is unaffected — none
  of them set the new key, so `preprocessDoStreamsShell()` behaves identically to before for them.
  Verified via the full `do_streams`+`do_discovery`+`do_chrome`+`do_group_pin` Kernel regression
  (45/45 pass) and the `do_streams`+`do_chrome` Functional regression (5/5 pass), plus
  `nav.spec.ts`+`showcase.spec.ts` E2E (26/26 pass) — none of which touch `/my-feed/events` but
  several of which render `do_streams_shell`-themed markup elsewhere.
- Traced `ThemeManager::render()`'s exact `array_key_exists()` + `+=` merge mechanics directly
  (web/core/lib/Drupal/Core/Theme/ThemeManager.php:190-213) as part of ruling out the
  emptiness-check approach — worth keeping in mind for any FUTURE `do_streams_shell` caller that
  wants to suppress only ONE of `scope_tabs`/`ranking_control` (not both): the current
  implementation ties both to the single `suppress_default_chrome` flag, since #112 needed both
  suppressed together; a future story needing to suppress just one would need either a second flag
  or a signature change to accept a granular value.

## Deviations from spec / wireframe

- **Mechanism deviation from the task's literal wording** (documented above in "Design
  decisions"): the task instructed "pass `#scope_tabs => []`" / "respect a caller-supplied
  non-null value including an explicit empty array" — implemented instead as a dedicated boolean
  flag (`#suppress_default_chrome => TRUE`), because the literal mechanism is not implementable
  against Drupal's real render pipeline without breaking `StreamsShellTest`'s existing 6 contract
  tests (proven by tracing `ThemeManager::render()` directly, not merely inferred). The
  OBSERVABLE outcome the task asked for — the shell's generic chrome suppressed on
  `/my-feed/events`, `MyEventsController`'s own 2-tab toggle the only thing that renders — is
  achieved identically; only the internal signal differs.
- No visual deviation from the approved wireframe: the fix removes phantom chrome that was never
  part of the wireframe in the first place; the page's real, wireframe-specified 2-tab toggle is
  unchanged.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:** `ddev exec "bash scripts/ci/assemble-config.sh"` — exit 0 (config: 129 files;
modules: 14 custom modules copied; core.extension patched). Re-ran twice (once after a deliberate
`git stash` used to obtain an apples-to-apples phpcs baseline comparison, once after `git stash
pop` restored my edits) — both times exit 0, and confirmed via `grep -c 'suppress_default_chrome'`
that the assembled `web/modules/custom/` copy reflects my edits after the final re-assemble.

**Kernel** (`MyEventsViewTest` + `StreamsShellTest`, since my change touches shared preprocess
logic `StreamsShellTest` also exercises): **11/11 pass** (5 + 6), re-confirmed on a second run
after the stash/restore dance — identical result both times. The two `⚠` markers
(`testUpcomingDisplayExecutesAndReturnsOnlyEventBundleNodes`,
`testNoHardcodedRoutePathsInRenderedTabMarkup`) are pre-existing deprecation warnings, not
failures, matching T-green's own baseline report.

**Functional** (`MyEventsRouteTest`): **4/4 pass**, re-confirmed on a second run.

**E2E** (`tests/e2e/my-events.spec.ts`, run against the live seeded
`http://gm112-events.ddev.site`): **5/5 pass**, including the previously-blocked "Global toggle
widens Upcoming beyond elena's memberships" test — re-confirmed on a second run after a
`drush cache:rebuild`. Live-verified via direct `curl` with an authenticated session
(`drush user:login --uid=4`) before running Playwright: `grep -o` on the rendered HTML shows
`data-testid="do-streams-shell-tab"` resolves to exactly 2 elements (`global`, `my_feed`), zero
duplicates; `"Stream scope"` (the phantom shell nav's own `aria-label`) and
`"do-streams-shell-ranking"` (the dead ranking-pill wrapper) both occur 0 times on the page;
`"Events scope"` (`MyEventsController`'s own nav's `aria-label`) occurs exactly once. Also
confirmed `?scope=global` still widens correctly (Thunder Editorial Workshop appears, 8 event cards
total, RSVP chips and iCal links all intact).

**phpcs** (`--standard=Drupal,DrupalPractice`):
- `MyEventsController.php`: 0 errors, 0 warnings.
- `do-streams-shell.html.twig`: 0 errors, 0 warnings.
- `DoStreamsHooks.php`: 1 error, 8 warnings — **byte-verified identical in count/category to the
  pre-rework baseline** (confirmed via a deliberate `git stash` of exactly these 3 files,
  `assemble-config.sh` re-run to regenerate the baseline `web/modules/custom/` copy, phpcs run
  against that restored-baseline copy: also 1 error / 8 warnings, all `\Drupal calls should be
  avoided` + the same pre-existing single docblock short-description error — the absolute line
  numbers shifted forward by my edit's added docblock content, but the violation SET is identical;
  zero net-new violations). Stash was popped immediately after the baseline check and
  `assemble-config.sh` re-run again to restore my edits in the assembled copy before continuing.

**Full regression** (all re-confirmed after the stash/restore dance, on a final run):
- `do_streams`+`do_discovery`+`do_chrome`+`do_group_pin` Kernel: **45/45 pass** (matches
  T-green's own prior count exactly).
- `do_streams`+`do_chrome` Functional: **5/5 pass** (matches T-green's own prior count exactly).
- `nav.spec.ts`+`showcase.spec.ts` E2E: **26/26 pass** (no admin-password fixup needed this time —
  the DB was already in the state T-green's earlier fix left it, in this same worktree).

## Tests that look wrong (for T)

None. T's own fixes (fixture, `my-events.spec.ts`) were reviewed and left untouched, per the task's
instructions — they are correct and require no further changes from this rework.

## Known issues

None. All acceptance criteria previously blocked by the duplicate-nav production bug (#5/#7, and
the AA component of #10 per handoff-T-green.md's table) are now met — E2E acceptance criterion #5
("Global toggle widens Upcoming beyond memberships") passes, and the duplicate-navigation-landmark
WCAG concern is resolved (the phantom 4-tab nav and dead ranking-pill wrapper no longer render at
all on this route).

## Files changed

- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extend)
- `docs/groups/modules/do_streams/src/Controller/MyEventsController.php` (extend)
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig` (extend)
