# Handoff-T-red: Phase 4 - #125 SC-6 Directory map view

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Brief / wireframe reviewed:** `docs/handoffs/0125-directory-map/brief.md`,
`docs/handoffs/0125-directory-map/survey.md`,
`docs/handoffs/0125-directory-map/wireframe.md`,
`docs/handoffs/0125-directory-map/handoff-A-plan.md`,
`docs/handoffs/0125-directory-map/decisions.md`

## A precondition
Confirmed: A returned **PASS** on the plan (Phase 3, re-review after all three
findings resolved — see `handoff-A-plan.md` verdict and `decisions.md`'s final
Phase 3 entry: "A re-review PASS. All three findings adequately resolved.
Advancing to T(red).").

## Boot-verification note
Per the task's explicit instruction, DDEV is not booted in this worktree (sibling
worktree `gm129-activity` already holds a running DDEV project; avoiding a
container-name/port collision). RED is confirmed **analytically** — by reading
the exact unchanged source lines the new assertions target — rather than by
executing PHPUnit/Playwright against a live site. Syntax validity was confirmed
mechanically:
- PHP: brace/paren balance check (no local `php` binary in this shell; `ddev
  exec php -l` unavailable because DDEV is occupied by the sibling worktree).
  Both PHP files balance (Unit: 38/38 braces; Kernel: 14/14 braces), and their
  structure mirrors two files (`VariantSwitcherTest.php`, the pre-existing
  file with 25 passing tests already in it; `DirectoryTogglePreRenderTest.php`)
  that already pass CI — the new methods use the identical class/namespace/
  fixture-loading shape.
- TypeScript: `npx esbuild tests/e2e/directory-map.spec.ts` compiles cleanly
  (0 errors), confirming the spec is syntactically valid and importable by
  Playwright's runner. `npx playwright test --list` itself could not run
  (no `node_modules` installed in this worktree checkout — a workspace-level
  dependency, not a defect in the spec), so `esbuild` stood in as the
  syntax-validity proxy per the task's "confirm RED analytically" instruction.

## Tests authored

### 1. Unit — `VariantSwitcherTest::testDirectoryLayoutOptionsMapEntryIsNowAvailable()`
**File:** `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php`
(existing file extended; one new test method + one docblock note added to the
class-level comment; no existing method touched).

**Criterion/behavior pinned:** `VariantSwitcher::directoryLayoutOptions()`'s
`map` entry must now be a LIVE option — `available` is `TRUE` or the key is
absent entirely (defaults `TRUE` via `normalizeOptions()`), the same shape
`compact`/`cards` already carry (brief.md AC #1: "`?variant=map`... renders a
Leaflet map"; survey.md: "single-line flip").

**Tier:** Unit — `VariantSwitcher` is a plain, no-DI, no-service-dependency
class (`StringTranslationTrait` only); this is the cheapest tier that can
observe the machine-spec shape without any Views/render-pipeline machinery.

**RED reason (specific source line):**
`VariantSwitcher.php` line 85 —
```php
['id' => 'map', 'available' => FALSE],
```
in `directoryLayoutOptionIds()` (private, called by the public
`directoryLayoutOptions()` under test). The new assertion —
`$this->assertTrue($map['available'] ?? TRUE, ...)` — fails against this
unchanged source because `$map['available']` is literally `FALSE`, not
absent/`TRUE`. This is a genuine assertion failure (the feature's own
behavior), not an import/typo/setup error — every other test in this file
(25 pre-existing methods, all currently green against the unchanged source)
continues to pass unaffected, confirming the new test's failure is isolated
to the one line this story must change.

**Implementation gate for F (to turn GREEN):** remove `'available' => FALSE`
from the `map` entry in `directoryLayoutOptionIds()` (VariantSwitcher.php line
85) — exactly the one-line flip survey.md and the method's own docblock
already name.

---

### 2. Kernel — `DirectoryMapPreRenderTest`
**File:** `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryMapPreRenderTest.php` (new)

Three test methods, all against a real `ViewExecutable` for the `all_groups`
view (`page_1` display), fixture-installed from the SAME module-local fixture
`DirectoryTogglePreRenderTest` already uses
(`tests/fixtures/config/views.view.all_groups.yml` — never a source-relative
path, per PROJECT_CONTEXT.md).

**a. `testMapQueryParamResolvesWrapperToMap()`**
- **Criterion/behavior pinned:** `?variant=map` resolves
  `data-do-directory-variant` to `"map"` (wireframe.md Surface 3 "Contract") —
  NOT a fallback to `compact`/`cards`, which is the OLD (pre-#125) behavior
  `DirectoryTogglePreRenderTest::testUnavailableMapQueryParamFallsBackToCompact()`
  already pins and continues to pin correctly for as long as that older test's
  own fixture/assumption (map unavailable) held. This new test pins the
  POST-#125 behavior once availability flips.
- **Tier:** Kernel — `hook_views_pre_render` is a Views-runtime hook; no pure
  Unit test can invoke it (no view-execution machinery), and a
  Functional/E2E test would exercise this same server-side resolution
  redundantly and far more expensively for what is fundamentally a render-
  array/cache-metadata contract (mirrors `DirectoryTogglePreRenderTest`'s own
  class-docblock rationale verbatim).
- **RED reason (specific source line):** same root cause as test #1 —
  `VariantSwitcher.php` line 85's `'available' => FALSE`. `DoShowcaseHooks::viewsPreRender()`
  (line 456) calls `$this->switcher->resolveCurrent($option_specs,
  $requested_variant)`, which (via the private `resolveSelection()`) falls
  back to the first AVAILABLE option — `compact` — whenever `map` is
  unavailable. The assertion `assertSame('map', $attribute, ...)` fails
  against unchanged source because the actual resolved value is currently
  `'compact'`.
- **Implementation gate for F:** the same one-line flip (test #1's gate)
  automatically fixes this test too — no separate hook-level code change is
  needed for resolution to work, since `viewsPreRender()` already calls
  `resolveCurrent()` generically.

**b. `testDirectoryMapLibraryAttachedWhenMapVariantActive()`**
- **Criterion/behavior pinned:** the new `do_showcase/directory-map` library
  (brief.md "Owned files": new library entry, depends on
  `do_showcase/leaflet` + `do_showcase/switcher`) is attached to the view's
  render array when the map variant is active (survey.md: "Attached by the
  same hook" as `directory-compact`).
- **Tier:** Kernel — same rationale as (a); `#attached['library']` is a
  render-array-level assertion, not a click-through concern.
- **RED reason (specific source lines):**
  1. `do_showcase.libraries.yml` (read in full above) has NO `directory-map:`
     key at all today — only `switcher`, `ribbon`, `persona-switcher`,
     `directory-compact`, `discovery-compare`.
  2. `DoShowcaseHooks::viewsPreRender()` (lines 449-462) only ever pushes
     `'do_showcase/switcware'` and `'do_showcase/directory-compact'` onto
     `$view->element['#attached']['library']` (line 460-461) — no
     `'do_showcase/directory-map'` push exists anywhere in the hook.
  The assertion `assertContains('do_showcase/directory-map', $libraries,
  ...)` fails because that string is simply absent from the array — a clean
  "missing feature" failure, not an error.
- **Implementation gate for F:**
  1. Add a `directory-map:` entry to `do_showcase.libraries.yml` (CSS +
     JS + Leaflet dependency, per brief.md "Owned files" #60 and A-plan
     resolution #2 — a bare `leaflet:` entry `directory-map:` depends on).
  2. In `viewsPreRender()`, push `'do_showcase/directory-map'` onto
     `$view->element['#attached']['library']` — unconditionally, mirroring
     how `directory-compact` is attached unconditionally today (survey.md:
     "the library itself no-ops harmlessly if the wrapper attribute isn't
     `map`").

**c. `testExistingLibrariesRemainAttachedAlongsideDirectoryMap()`**
- **Criterion/behavior pinned:** non-regression — `do_showcase/switcher` and
  `do_showcase/directory-compact` remain attached; #125 is additive, not a
  replacement.
- **Tier/RED reason:** this test currently PASSES against unchanged source
  (both libraries are already attached today) — it is a regression guard, not
  a RED-at-authoring-time test. Included deliberately so F's change is
  proven not to have accidentally removed either existing attach while adding
  the new one (Tier 2 "non-regression" concern folded into T-red rather than
  deferred solely to T-green, since it costs nothing extra to author now).

---

### 3. E2E — `tests/e2e/directory-map.spec.ts` (new)

Ten new test cases in the primary `#125 SC-6` describe block, plus three
explicit non-regression cases in a second describe block. All assert against
markup/behavior that does not exist yet.

| Test | Criterion pinned | RED reason |
|---|---|---|
| `the Map option in the switcher is live/selectable...` | Map option has no `aria-disabled="true"`, no "(soon)" label, not `tabindex="-1"` | `VariantSwitcher.php` line 85 (`available => FALSE`) + `build()`'s truthful-labeling logic (lines 229-233, 243) still render `"Map (soon)"` + `aria-disabled="true"` + `tabindex="-1"` today |
| `?variant=map renders a .do-showcase-map container...` | Wrapper resolves to `"map"`; `.do-showcase-map` visible; `.view-content` hidden; pager hidden | `.do-showcase-map` does not exist in any twig/CSS/JS yet; wrapper still resolves to `"compact"` (same root cause as kernel test 2a) |
| `exactly 4 markers render...` | 4 Leaflet markers for the 4 seeded groups with coordinates | `field_group_location` field does not exist yet (survey.md: new storage/instance YAML); no Leaflet init JS exists; `.leaflet-marker-icon` cannot appear |
| `the caption states the truthful count...` | Visible text "Showing 4 groups with a location." | No caption markup exists yet (new to this story) |
| `clicking a marker navigates to a group canonical page` | Direct-click-navigates (wireframe.md Surface 1, no popup step) | No markers exist to click; no click handler exists |
| `the SR-only fallback list has 4 items...` | `ul.do-showcase-map-fallback-list` with 4 `<li><a>` reading "Name — City", each linking to `/group/\d+` | `.do-showcase-map-fallback-list` does not exist; `field_group_location_text` is populated today but nothing renders it into this list shape |
| `the fallback list is visually hidden by default but reveals on focus-within` | WCAG AA visible-focus requirement (wireframe.md Surface 2, explicitly flagged as "not optional") | List does not exist at all |
| `zero external network requests...` | No CDN/tile-host requests; Leaflet assets load from `/libraries/leaflet/` | `web/libraries/leaflet/**` is gitignored and unpopulated (A-plan blocker, resolved via `assemble-libraries.sh` which F must also write/run); no Leaflet JS/CSS is loaded by any page today, so the positive assertion (`leaflet.js`/`leaflet.css` actually requested) also fails |
| `toggling Cards -> Map -> Cards works client-side...` | Live client-side variant switch, filters/markers survive round-trip | Map option is unavailable today — clicking it is a no-op under current switcher JS (unavailable options don't receive a click-driven variant change); `.do-showcase-map` and markers don't exist |
| **Non-regression:** `directory-toggle.spec.ts` parity test | Switcher still renders 3 options, Cards default | Currently **passes** unmodified (baseline regression guard, not new-feature RED) |
| **Non-regression:** `directory-cards.spec.ts` parity test | Cards still render by default | Currently **passes** unmodified (baseline regression guard) |
| **Non-regression:** filters preserved across a Map toggle | Filters survive a toggle to Map and back (extends the existing Compact/Cards guarantee to the new third option) | Currently fails for the same reason as "toggling Cards -> Map -> Cards" above — Map is not clickable/live yet |

**Tier rationale:** E2E is the correct (not over-provisioned) tier for these
assertions because every one of them is fundamentally about the CLIENT-SIDE,
rendered-DOM, real-browser-network behavior: Leaflet's own marker rendering,
real click-driven navigation, real network request interception for the
zero-CDN guarantee, and real focus/visibility computed-style behavior. None of
this is observable at Kernel level (no browser, no JS execution, no real
network stack) — the Kernel test above deliberately covers only the
server-side render-array/library-attach contract, with zero overlap in
assertions.

### Cross-story regression safety
- `tests/e2e/directory-toggle.spec.ts` and `tests/e2e/directory-cards.spec.ts`
  are **untouched** (no edits to either file) — the new spec's non-regression
  describe block re-asserts a strict subset of what those two files already
  cover (three-option switcher, Cards default, filter preservation) using the
  SAME locators (`switcherLocator`, `directoryWrapperLocator`, mirrored 1:1
  from `directory-toggle.spec.ts`'s own helper functions) so there is no
  drift risk between the two specs' selector contracts.
- No assertion in the new spec depends on Map being unavailable — the one
  place the OLD toggle spec asserts unavailable-Map fallback
  (`'?variant=map (unavailable) falls back gracefully to compact, never
  blank'`) is a test **this story's implementation will make FAIL** once F
  flips availability (its own assertion — wrapper resolves to `"compact"` for
  `?variant=map` — becomes false once `map` resolves to `"map"` itself).
  This is flagged explicitly for F/T-green: `directory-toggle.spec.ts`'s
  `'?variant=map (unavailable) falls back gracefully to compact, never
  blank'` test is **expected to need deletion or rewrite** at T-green time,
  since the behavior it pins (map unavailable) is precisely what #125 changes
  on purpose. This is not a defect in either spec — it is the intended
  contract transition, called out here so T-green does not mistake the
  resulting failure for a real regression.

## RED confirmation

Executed as an analytical/structural proof (DDEV unavailable in this
worktree — see "Boot-verification note" above) rather than a live run:

**Unit (`VariantSwitcherTest`):**
- Source inspected: `VariantSwitcher.php` lines 81-87 (`directoryLayoutOptionIds()`).
- New method: `testDirectoryLayoutOptionsMapEntryIsNowAvailable()`.
- Traced execution: `directoryLayoutOptions()` (line 104) iterates
  `directoryLayoutOptionIds()`'s three entries; the `map` entry carries
  `'available' => FALSE` verbatim (no transformation of that key occurs in
  the loop body, lines 106-113) — so `$map['available']` in the test is
  `FALSE`, making `assertTrue(FALSE, ...)` fail. **Confirmed: fails for the
  right reason** (the exact behavior gap, not a setup/import error — the
  class instantiates and the method call succeeds; only the returned data
  shape is wrong).

**Kernel (`DirectoryMapPreRenderTest`):**
- Source inspected: `DoShowcaseHooks.php` lines 448-462
  (`viewsPreRender()`), `VariantSwitcher.php` lines 340-354
  (`resolveSelection()`), `do_showcase.libraries.yml` (full file, no
  `directory-map` key).
- `testMapQueryParamResolvesWrapperToMap()`: traced
  `resolveCurrent(['compact','cards','map(unavailable)'], 'map')` ->
  `resolveSelection()` -> first loop (`$option['id'] === $current &&
  $option['available']`) never matches (map's `available` is `FALSE`) ->
  second loop returns first available (`compact`). Test asserts `'map'`;
  actual is `'compact'`. **Confirmed: fails for the right reason.**
- `testDirectoryMapLibraryAttachedWhenMapVariantActive()`: traced
  `$view->element['#attached']['library']` after `viewsPreRender()` runs ->
  contains exactly `['do_showcase/switcher', 'do_showcase/directory-compact']`
  (lines 460-461) -> `assertContains('do_showcase/directory-map', ...)`
  fails (string absent from array). **Confirmed: fails for the right
  reason** (missing attach, not a wrong-type/setup error).
- `testExistingLibrariesRemainAttachedAlongsideDirectoryMap()`: traced same
  array -> both strings already present -> **this assertion currently
  PASSES** (intentional non-regression guard, documented above as not a
  RED-at-authoring-time case).

**E2E (`directory-map.spec.ts`):**
- Source inspected: `do_showcase.libraries.yml` (no `directory-map`/`leaflet`
  keys), grep confirms no `.do-showcase-map`/`do-showcase-map-fallback-list`
  selector exists anywhere in `docs/groups/modules/do_showcase/`
  (css/js/templates), `field.storage.group.field_group_location.yml` does
  not exist (only `field_group_location_text` does), `web/libraries/leaflet/`
  does not exist.
- Every assertion in the primary describe block targets one of: (a) the
  `available: FALSE` root cause shared with the Unit/Kernel tests above, (b)
  markup/CSS classes (`.do-showcase-map`, `.do-showcase-map-fallback-list`,
  `.leaflet-marker-icon`) that do not exist in any template/CSS file in the
  repository today (confirmed via grep — zero matches), or (c) a
  `field_group_location` field + seeded coordinates that do not exist yet.
  Each of these is a genuine "feature not yet built" failure mode, not a
  test-authorship defect. `npx esbuild` confirms the file is syntactically
  valid TypeScript (0 compile errors), so any failure at real-run time will
  be an assertion/timeout failure against real (missing) DOM state — not an
  import/syntax error masquerading as RED.
- The two non-regression parity cases (switcher-3-options, cards-default)
  are traced against `DoShowcaseHooks.php`'s CURRENT behavior and **already
  pass** — correctly, since they guard existing behavior this story must not
  break, not new behavior it must add.

## Ready for F
Confirmed RED is valid (analytically, per the boot-verification constraint
stated in the task). F may implement against these tests. Each RED failure
above traces to a specific, currently-unimplemented line/artifact named in
the brief's "Owned files" list — no test fails for an import/typo/setup
reason; no test is coincidentally green before any code exists (the two
library-attach non-regression cases that DO pass today are explicitly
labeled as such, not conflated with the new-feature RED set).

## Implementation gates for F (consolidated)
1. `VariantSwitcher.php` line 85: remove `'available' => FALSE` from the
   `map` entry (turns GREEN: Unit test, Kernel test 2a).
2. `do_showcase.libraries.yml`: add `leaflet:` (bare vendor entry) +
   `directory-map:` (depends on `do_showcase/leaflet` +
   `do_showcase/switcher`) library entries.
3. `DoShowcaseHooks::viewsPreRender()`: push
   `'do_showcase/directory-map'` onto `$view->element['#attached']['library']`
   unconditionally (turns GREEN: Kernel test 2b).
4. New `field.storage.group.field_group_location.yml` +
   `field.field.group.community_group.field_group_location.yml` (geofield
   type, `dependencies.module: [geofield]`), `do_showcase.info.yml` gains
   `geofield` dependency.
5. `views.view.all_groups.yml`: append a geofield lat/lng field display.
6. `DoShowcaseHooks.php`: preprocess seam projecting
   `data-do-location-lat/lng` onto each row wrapper.
7. New `do_showcase.directory-map.js` (Drupal behavior: reads
   `?variant=map` / wrapper attribute, builds Leaflet markers + the SR-only
   fallback list, direct-click-navigates, sets `L.Icon.Default.imagePath`).
8. New `directory-map.css` (hides `.view-content` + pager in map mode, sizes
   `.do-showcase-map`, visually-hidden + focus-within pattern for the
   fallback list).
9. `docs/groups/libraries/leaflet/**` (vendored source) +
   `scripts/ci/assemble-libraries.sh` + `web/libraries/leaflet/**` populated
   locally before running the E2E suite (A-plan-resolved gitignore
   strategy) — required for the zero-CDN + marker-rendering E2E tests to
   even load Leaflet.
10. `step_700_demo_data.php`: append lat/lng coordinates for the 4 seeded
    groups (Paris, Berlin, Brussels, Portland).
11. **Flag for T-green:** `directory-toggle.spec.ts`'s
    `'?variant=map (unavailable) falls back gracefully to compact, never
    blank'` test will fail once gate #1 lands — this is the EXPECTED,
    intentional contract transition (map is no longer unavailable), not a
    regression. T-green must delete or rewrite that one test (T owns test
    repair, not F) rather than treat its failure as a blocker.
