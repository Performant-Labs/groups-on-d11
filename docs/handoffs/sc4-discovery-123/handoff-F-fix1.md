# Handoff-F-fix1: Phase 9 — F-U-1 fix

Date: 2026-07-23
Branch: 123-discovery-three-ways
Fixes: U's blocking finding F-U-1 (click/Enter on discovery.ranking tab flipped chrome only; no URL change, no content swap).

## Verdict

FIXED. `discovery-compare.spec.ts` now 11/11 GREEN (was 9/11 at U-time). Directory switcher `directory-toggle.spec.ts` 11/11 (+1 skipped, unchanged) — no regression. `do_showcase` Unit + Kernel 77/77 GREEN (`DirectoryTogglePreRenderTest` 8/8 confirmed).

## Fix chosen

**Option 1 (let the anchor navigate), applied CONDITIONALLY per-instance** — not the naive "just drop `preventDefault()` everywhere" that U's option 1 as written would have been. The naive form breaks the pre-existing `directory.layout` switcher hard: `directory-toggle.spec.ts` explicitly asserts `expect(page.url()).toBe(urlBefore)` on the click path (line 103) and again on the filter-preservation and pager-preservation paths (lines 141, 161) — the whole SC-5 contract is "flip a wrapper attribute in place, do NOT navigate, filters and pager survive."

The two switcher instances have genuinely different update models:

- `directory.layout` (SC-5) — client-side swap via a mirrored wrapper attribute (`data-do-directory-variant`) that CSS keys off; MUST `preventDefault()` on click/Enter, or filters/pager/URL are lost.
- `discovery.ranking` (SC-4) — no client-side swap mechanism; the server re-renders the correct embedded view for `?discovery=<id>`, so the anchor's `href` fallback IS the update path and MUST be followed.

The signal that already distinguishes them is the wrapper-mirror wiring the render layer sets:

- `DoShowcaseHooks::preprocessViewsView()` sets `data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-selector` on the `directory.layout` wrapper (lines 508–511).
- `ShowcaseController::page()` sets NEITHER on the `discovery.ranking` wrapper.

So the fix is: **if the wrapper carries the mirror pair → preventDefault + swap in place (existing behavior). If it does not → let the anchor navigate.** This piggybacks on data-driven wiring the caller already sets and adds **zero** per-instance branching (`instanceId === 'discovery.ranking'` etc.) — matching the file's existing convention (see `mirrorSelectionToWrapperAttribute()`).

Why not U's option 2 (`history.pushState` + fetch/swap)? Two reasons: (a) no HTMX / fetch-swap primitive exists in this codebase (U grepped, and I re-verified — no `fetch(` inside `docs/groups/modules/`); introducing one for a POC is disproportionate. (b) The user experience gap between "full navigation" and "instant swap" on `/showcase` is small (server responds quickly, the switcher stays in place across nav), and shipping the correct behavior with 8 lines of conditional beats shipping a partial swap the codebase has no precedent to maintain.

## Change

Single file: `docs/groups/modules/do_showcase/js/do_showcase.switcher.js`.

Added `usesMirrorModel(group)` helper (a boolean predicate reading the mirror pair off the wrapper, with full docblock explaining the two update models).

In `Drupal.behaviors.doShowcaseSwitcher.attach()`, cached `const isMirrorDriven = usesMirrorModel(group)` once per instance (only changes if the wrapper is re-rendered, in which case `once()` re-attaches).

Rewrote the `click` handler:

- Mirror-driven → unchanged (preventDefault + `select(id, true)`).
- Navigation-driven → persist choice to `sessionStorage`, then return without preventDefault so the browser follows `href`.

Rewrote the `keydown` handler's Enter/Space branch symmetrically:

- Mirror-driven → unchanged.
- Navigation-driven → persist, `preventDefault()` on the key event (Enter/Space on `role="radio"` do not natively fire the anchor's click), then `window.location.assign(option.getAttribute('href'))`.

Arrow-Left/Right/Up/Down behavior unchanged — moves selection + focus via `moveSelection()`. For a navigation-driven instance the chrome flip is transient (page navigates on Enter), matching the WAI-ARIA radiogroup pattern that arrows preview selection and Enter commits.

The pre-existing `queryKeyForGroup()` URL-wins logic still works unmodified — after a navigation-driven click lands on `?discovery=hot`, the next attach() reads `queryKey='discovery'`, sees the param present, skips the sessionStorage restore, and the server-rendered `aria-checked="true"` on Hot stays authoritative.

## Verification

### Live smoke (via Playwright against the persisted DDEV site)

`http://gm123-discovery.ddev.site` — the same site U used, re-seeded per U's handoff.

After `bash scripts/ci/assemble-config.sh` inside the container (docs/ → web/, mandatory since the served copy is `web/modules/custom/`; forgetting this cost one wasted Playwright run on the same-cached JS) and `drush cache:rebuild`:

- `/showcase` → click Hot tab → URL becomes `/showcase?discovery=hot`, embedded view region changes (Hot content, includes Follow-content controls not present in Recent). Click Recent → URL becomes `/showcase?discovery=recent`, embed changes back. Click Promoted → `/showcase?discovery=promoted`, embed shows the two seeded promoted titles.
- `/showcase` → Tab into Recent → ArrowRight moves focus to Hot → Enter navigates to `/showcase?discovery=hot`; embed matches.
- `/all-groups` → click Compact list → wrapper `data-do-directory-variant` flips to `compact`, URL unchanged; description text hidden; click Cards → attribute flips back, description restored, URL still unchanged. Filter (`search=Book`) + toggle preserves the query. All 11 live directory tests pass; the 1 skipped is conditional-on-page-2-existing (seed size), unchanged from pre-fix.

### Playwright

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts --reporter=list`:

```
Running 11 tests using 1 worker
  ok  1  … renders an H2 heading and a switcher with three options (6.4s)
  ok  2  … clicking each tab updates the URL to ?discovery=<id> and changes the embedded content (5.1s)
  ok  3  … the Promoted tab shows exactly the two seeded promoted nodes (436ms)
  ok  4  … the Hot tab shows commented threads … (454ms)
  ok  5  … deep-linking to /showcase?discovery=hot pre-selects the Hot tab (402ms)
  ok  6  … role="radiogroup" and a non-empty aria-label (454ms)
  ok  7  … exactly ONE wrapper-level tooltip trigger (387ms)
  ok  8  … both switchers coexist: ?variant=cards&discovery=hot (1.1s)
  ok  9  … WCAG-adjacent smoke: keyboard-operable (587ms)
  ok 10  … /hot standalone page is unaffected (1.0s)
  ok 11  … directory.layout stub switcher still renders unaffected (424ms)

  11 passed (17.7s)
```

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/directory-toggle.spec.ts --reporter=list` — 11 passed, 1 skipped (pager-page-2, seed-conditional; same as before fix).

### PHPUnit (do_showcase Unit + Kernel, non-regression)

`ddev exec "cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db:3306/db ../vendor/bin/phpunit -c core modules/custom/do_showcase/tests/src/Unit modules/custom/do_showcase/tests/src/Kernel"`:

```
Tests: 77, Assertions: 625, Deprecations: 6, PHPUnit Deprecations: 77.
OK, but there were issues!  ← deprecations only (RunTestsInSeparateProcesses notice + trigger_error notices); zero failures/errors.
```

Includes `DirectoryTogglePreRenderTest` 8/8 (the test T-red flagged specifically as pinning the shared-JS caller's 3-arg BC call form). Confirms `VariantSwitcher::build()` still works BC-safely from `DoShowcaseHooks.php:492` and (Unit-tier) from `ShowcaseController.php:228` — those two 3-arg call sites unchanged, not touched by this fix.

### phpcs

Zero PHP files changed. JS file (`do_showcase.switcher.js`) run through `phpcs --standard=web/core/phpcs.xml.dist` → exit 0 (Drupal's phpcs standard treats `.js` under this ruleset as pass-through; the 6 pre-existing "TRUE/FALSE/NULL must be uppercase" false-positives F's Phase-5 handoff noted are on a DIFFERENT standard the F-Phase-5 run used).

## Constraints honored

- Tests untouched (T owns those).
- Zero PHP files modified — no PHP phpcs surface at risk.
- 3-arg BC callers at `DoShowcaseHooks.php:492` and `ShowcaseController.php:228` unchanged.
- No new HTMX/fetch primitive introduced.
- No per-instance `if (instanceId === '...')` branch — the split is data-driven off wrapper attributes.

## Handoff to next role

T for GREEN re-verification (re-run the full non-regression sweep + the Playwright case that was failing). After T-GREEN, U for re-walkthrough.

Nothing else in the story needs a re-run — the PHP surface (VariantSwitcher, ShowcaseController, HelpText, DoShowcaseHooks), the CSS, and the library registration were not touched by this fix and remain as they landed in Phase 5.

## Evidence

- Diff: single hunk in `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` (adds `usesMirrorModel()` helper + rewrites the click/keydown Enter/Space branches to switch on it; ~65 lines net, mostly the helper's docblock).
- Assembled copy at `web/modules/custom/do_showcase/js/do_showcase.switcher.js` (via `scripts/ci/assemble-config.sh`).
- Playwright run outputs above.
- PHPUnit run output above.
