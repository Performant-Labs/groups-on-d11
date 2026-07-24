# Handoff-T-green: Phase 6 - #125 SC-6 Directory map view

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Issue:** #125
**Handoff-F reviewed:** `docs/handoffs/0125-directory-map/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/0125-directory-map/handoff-T-red.md`

## Task A — `directory-toggle.spec.ts` pre-documented expected failure

**Resolution: deleted (option a).** Removed the test
`'?variant=map (unavailable) falls back gracefully to compact, never blank'`
(previously lines 195-199 of `tests/e2e/directory-toggle.spec.ts`). This test
pinned the PRE-#125 contract (map unavailable -> fallback to compact) — a
contract #125 deliberately retires by making `map` available. Chose deletion
over a rewrite because `tests/e2e/directory-map.spec.ts` already exhaustively
covers the new positive contract (`?variant=map` resolves to `"map"`, renders
`.do-showcase-map`, no fallback) — a rewritten version of the old test would
duplicate that coverage with no new signal. `directory-toggle.spec.ts`'s
surrounding tests (three-option switcher shape, compact/cards persistence,
cross-page persistence) are untouched and remain valid — none of them assert
anything about map availability.

Final state: `tests/e2e/directory-toggle.spec.ts` no longer contains any
assertion about `map`'s availability; file compiles cleanly
(`npx esbuild tests/e2e/directory-toggle.spec.ts` — 0 errors).

## Task B — `directory-map.spec.ts` tabindex assertion

**Resolution: moved (option b).** F correctly flagged that the final
assertion (`await expect(mapOption).not.toHaveAttribute('tabindex', '-1');`)
in `'the Map option in the switcher is live/selectable...'` (evaluated on
plain `/all-groups`, where Cards is selected) asserted against the
already-shipped WAI-ARIA roving-tabindex contract in a state where Map is
legitimately `tabindex="-1"` (available-but-unselected). Confirmed against
`VariantSwitcher.php` line 254 (`'tabindex' => ($available && $is_selected)
? '0' : '-1'`) and the twig template (unconditional
`tabindex="{{ option.tabindex }}"`, `do-showcase-variant-switcher.html.twig`
line 31) — F's read is correct.

Deleted the broken assertion from the original test (its first three
assertions — visible, no `aria-disabled`, no "(soon)" text — remain and
still fully establish Map is live/selectable). Added a new test,
`'when Map is the selected variant, it carries the roving tabindex
(tabindex="0"), per the WAI-ARIA radiogroup pattern'`, which navigates to
`/all-groups?variant=map` (Map now selected) and asserts `aria-checked="true"`
+ `tabindex="0"` — the correct positive form of the same check, strengthening
coverage rather than just removing a broken assertion.

Final state: `tests/e2e/directory-map.spec.ts`'s primary describe block now
has 11 tests (was 10); net new assertions, no duplication — the new test
exercises a state (`?variant=map`, Map selected) the original suite did not
previously assert `tabindex` against at all.

## GREEN confirmation

**Execution method:** Analytical (source-trace), per the same fallback path
F used and the task's explicit instruction. No `php` binary, no `vendor/`
(config assembly failed at `composer install` prerequisite), and no
Playwright `node_modules` are present in this worktree. This worktree's DDEV
project (`gm129-activity`, per `.ddev/config.yaml`) is registered but its
`ddev describe`/boot points at a stale/missing sibling-worktree path
(`groups-st7-activity-129`) — untangling DDEV's shared project registry
mid-run risks corrupting state for other concurrent worktrees, so I did not
attempt to boot it. Ran what IS available in this environment:

- `npx esbuild tests/e2e/directory-map.spec.ts --outfile=...` -> 0 errors (compiles cleanly, including the new test).
- `npx esbuild tests/e2e/directory-toggle.spec.ts --outfile=...` -> 0 errors (compiles cleanly after the deletion).
- `bash scripts/ci/assemble-libraries.sh` -> ran successfully, confirmed `web/libraries/leaflet/{leaflet.js,leaflet.css,LICENSE,images/*}` populate and stay outside `git status` (matches F's own local verification).
- `bash scripts/ci/assemble-config.sh` -> fails only on the `composer install` prerequisite (`vendor/autoload.php missing`), same environment gap F hit; not a defect in F's change.
- `python3 -c "import yaml; yaml.safe_load(...)"` on all 6 touched/new YAML files (`views.view.all_groups.yml`, `do_showcase.libraries.yml`, both new field YAMLs, `.github/workflows/test.yml` parsed via full-file job-key check) -> all OK.

Traced every authored test against F's actual (not just described) source:

| Test | Trace | Verdict |
|---|---|---|
| Unit `testDirectoryLayoutOptionsMapEntryIsNowAvailable` | `VariantSwitcher.php` line 91: `map` entry is now `['id' => 'map']` — no `available` key at all. `$map['available'] ?? TRUE` evaluates TRUE. | GREEN |
| Kernel `testMapQueryParamResolvesWrapperToMap` | `resolveCurrent()`'s first-loop match (`id === current && available`) now succeeds for `map` since `available` defaults TRUE; `viewsPreRender()` sets `#attributes['data-do-directory-variant'] = 'map'`. | GREEN |
| Kernel `testDirectoryMapLibraryAttachedWhenMapVariantActive` | `DoShowcaseHooks.php` line 478: `$view->element['#attached']['library'][] = 'do_showcase/directory-map';` — unconditional push, confirmed present. `do_showcase.libraries.yml` line 105 declares the `directory-map:` entry (depends on `do_showcase/leaflet` + `do_showcase/switcher`). | GREEN |
| Kernel `testExistingLibrariesRemainAttachedAlongsideDirectoryMap` | Lines 471-472 (`do_showcase/switcher`, `do_showcase/directory-compact`) untouched/still pushed alongside the new line 478. | GREEN |
| E2E: Map live/selectable (3 remaining assertions) | `available` key absent -> `build()`'s truthful-labeling omits `aria-disabled`/"(soon)". | GREEN |
| E2E: Map selected carries `tabindex="0"` (new test) | `build()` line 254: `($available && $is_selected) ? '0' : '-1'`; both TRUE when `?variant=map` resolves selection to map. Twig renders unconditionally. | GREEN |
| E2E: `.do-showcase-map` container replaces row grid | `do_showcase.directory-map.js` `ensureMapContainer()` inserts `.do-showcase-map` before `.view-content`; `directory-map.css` lines 46-48, 69-78 hide `.view-content` + `.pager` under the map-mode selector. | GREEN |
| E2E: exactly 4 markers | `plotMarkers()` creates one `L.marker` per `collectLocations()` entry; `DoShowcaseHooks::groupLocationAttributes()` emits `data-do-location-*` for each of the 4 seeded groups (`step_700_demo_data.php` Step 738, lines 770-803, sets `field_group_location` coords for Paris/Berlin/Brussels/Portland). Leaflet's stock marker DOM is `.leaflet-marker-icon`. | GREEN |
| E2E: caption truthful count | `setCaptionText()` (JS lines 208-221) produces exactly "Showing @count groups with a location." via `Drupal.formatPlural` when `plotted === total` — matches 4-of-4 seeded state. | GREEN |
| E2E: marker click navigates | `plotMarkers()` line 303-305: `marker.on('click', () => window.location.assign(location.url))`; `location.url` = `$group->toUrl()->toString()` from the preprocess hook. | GREEN |
| E2E: SR-only fallback list, 4 items "Name — City" | `populateFallbackList()` builds `<li><a href=url>{name}</a></li>` per location; `groupLocationAttributes()` line 623 builds `$name = $group->label() . ' — ' . $city` (em dash, spaces both sides) when city is set; seed data backfills `field_group_location_text` for all 4 groups (Step 738). Regex `—\s*${city}` matches "Portland, OR" via substring for "Portland". | GREEN |
| E2E: fallback list visible-on-focus | `ensureFallbackList()` sets class `visually-hidden`; `directory-map.css` lines 132-144: `:focus-within` override sets `position: static`, `width/height: auto` -> non-1px bounding box once a descendant is focused. | GREEN |
| E2E: zero external network requests / Leaflet loads from `/libraries/leaflet/` | `leaflet:` library entry (line 88-94) uses `/libraries/leaflet/leaflet.js` + `.css` (no CDN URL anywhere); `directory-map.js` has no `L.tileLayer()` call; `assemble-libraries.sh` populates `web/libraries/leaflet/**` locally, confirmed via direct run. | GREEN |
| E2E: toggling Cards->Map->Cards, no reload | `MutationObserver` on `data-do-directory-variant` (JS lines 402-409) calls `renderMap()` on every transition into map mode without a page reload; CSS inverse-hide rule (lines 63-67) hides map elements on transition back out; `map.invalidateSize()` (line 369) on re-entry. | GREEN |
| E2E non-regression x3 (switcher 3-option shape, cards-default, filters survive Map toggle) | None of F's changes touch `do_showcase.switcher.js`, the cards template, or filter-form handling; `directory-map.js`'s own click handler on markers is scoped inside `.do-showcase-map`, not the filter form. | GREEN |

**Spot-check that tests still fail if behavior is removed:** re-traced the
Unit test against the UNCHANGED (pre-F) source line quoted in T-red
(`'available' => FALSE`) — confirmed it fails exactly as T-red documented;
re-traced the Kernel library-attach test against a hypothetical removal of
line 478 — `assertContains('do_showcase/directory-map', ...)` would fail
(string absent). Both tests genuinely pin the behavior, not merely pass
regardless.

## Tier 1 results

| Check | Command | Expected | Actual | Verdict |
|---|---|---|---|---|
| E2E spec syntax (directory-map.spec.ts) | `npx esbuild tests/e2e/directory-map.spec.ts` | 0 errors | 0 errors | PASS |
| E2E spec syntax (directory-toggle.spec.ts, post-edit) | `npx esbuild tests/e2e/directory-toggle.spec.ts` | 0 errors | 0 errors | PASS |
| YAML validity (6 touched/new files) | `python3 -c "import yaml; yaml.safe_load(...)"` | parses cleanly | all OK | PASS |
| CI workflow structure | `python3 -c "import yaml; ...jobs.keys()"` | 3 jobs (kernel/functional/e2e) intact | `['kernel', 'functional', 'e2e']` | PASS |
| `assemble-libraries.sh` | `bash scripts/ci/assemble-libraries.sh` | populates `web/libraries/leaflet/**`, idempotent, gitignored | confirmed (matches F's own run) | PASS |
| `assemble-config.sh` | `bash scripts/ci/assemble-config.sh` | copies config+modules | copied 133 config files + 15 modules; then errors on missing `vendor/autoload.php` (composer not installed in this worktree) | PASS for the part exercised (same env gap F hit — not a code defect) |
| Live PHPUnit/Playwright run | N/A | — | Not possible: no `php`, no `vendor/`, no `node_modules` in this worktree; DDEV project for this worktree points at a stale sibling path — did not force a boot (shared-registry risk) | ANALYTICAL FALLBACK (documented per-test above) |

## Tier 2 results

- **Test coverage:** every acceptance criterion in brief.md (Map available,
  wrapper resolves to `map`, library attached, markers render, truthful
  caption, direct-click-navigate, SR-only fallback list, focus-visible
  reveal, zero-CDN, live client-side toggle, non-regression) is backed by
  exactly one test at the cheapest sufficient tier — PASS.
- **Test quality (test-quality.md §7):** every test names a specific
  behavior in its title; each authored RED failure traced to one specific
  unimplemented line/artifact (T-red's own table); no test duplicates
  another (Kernel tests assert server-side render-array/cache-metadata only,
  E2E tests assert client-rendered DOM/network only — zero assertion
  overlap). The suite is proportionate: 1 Unit + 3 Kernel + 14 E2E for a
  feature touching schema, hook, JS behavior, CSS, and vendored assets is
  not over-provisioned. Both T-authorship defects flagged by F were genuine
  (not F being wrong) — repaired above, one by deletion (no coverage loss,
  since `directory-map.spec.ts` already covers the new contract), one by
  moving to a stronger positive-form assertion (coverage gain). PASS.
- **Type safety:** TypeScript spec files use typed Playwright fixtures
  (`Page`, `Request` imports); no `any` casts introduced in either edited
  file. PHP files use `declare(strict_types=1)` and typed method signatures
  throughout (confirmed in F's PHP changes). PASS.
- **Error handling:** `groupLocationAttributes()` returns `NULL` (safely
  skipped by the preprocess hook) for groups with no `field_group_location`
  value or empty lat/lng — confirmed no exception path; `plotMarkers()`
  defensively handles the 0-marker case (`setView` default) and the
  1-marker case (skips `fitBounds()`, which throws on a 0-2-point
  `LatLngBounds` in some Leaflet versions). PASS.
- **Data integrity:** seed script change (`step_700_demo_data.php` Step 738)
  is idempotent (`isEmpty()` guards before every `set()`), append-only,
  confirmed via F's own dry-run description; field storage/instance YAML
  both declare explicit `dependencies.module: [geofield]` per A-plan
  Finding #3's resolution. PASS.
- **API contract:** `views.view.all_groups.yml`'s new field is
  `exclude: true` (never rendered, read only via the entity directly in the
  preprocess hook) — matches brief's own note; no response-shape change to
  any existing route. PASS.
- **Security:** no new user input is trusted — `?variant=map` flows through
  the SAME `resolveCurrent()` allowlist-style resolution (first-match against
  a known id list) every other variant already uses; no raw query value is
  ever echoed into markup/attributes without going through that resolution.
  PASS.
- **Migration safety:** new field storage/instance YAML are pure additions
  (new field on `community_group`), no existing field/schema altered;
  reversible via standard config-entity deletion since geofield has no
  destructive migration hook. PASS.
- **Playwright suite structural check:** `npx esbuild` used as the syntax-
  validity proxy in place of `npx playwright test --list` (no
  `node_modules` in this worktree — same gap T-red and F both hit); both
  edited spec files compile with 0 errors. This is a **coverage gap in this
  environment, not in the suite** — flagging that the FIRST real
  `npx playwright test --list`/execution against a fully assembled+seeded
  site has not yet happened in ANY phase of this story (T-red, F, and
  T-green all ran analytically). **U must be the first to actually execute
  this suite live** — treat U's walkthrough as also covering this residual
  verification gap, not just the manual click-through.

## Acceptance criteria status (brief.md)

| Criterion | Test | Verdict |
|---|---|---|
| `?variant=map` renders a live Leaflet map (no longer "(soon)") | Unit + Kernel + E2E "Map option live/selectable" | PASS |
| Map replaces the row grid, pager hidden | E2E ".do-showcase-map container..." | PASS |
| One marker per group with a location; direct-click navigates | E2E "exactly 4 markers", "clicking a marker navigates" | PASS |
| Truthful count caption | E2E "caption states the truthful count" | PASS |
| SR-only keyboard fallback list, visible-on-focus | E2E "SR-only fallback list...", "visually hidden...reveals on focus-within" | PASS |
| Zero external network requests (no CDN, no tiles) | E2E "zero external network requests..." | PASS |
| Live client-side toggle (no reload), round-trips | E2E "toggling Cards -> Map -> Cards..." | PASS |
| Cards + Compact render identically to pre-story (non-regression) | E2E non-regression x3 | PASS |
| Roving-tabindex contract preserved for the selected option | E2E new "carries the roving tabindex..." test | PASS |

## Blocking issues

None. No implementation gap found — every trace against F's actual source
confirms the intended behavior. Both test-authorship issues F flagged were
genuine test defects (not F errors) and have been repaired as documented
above.

## Advisory notes

- **Residual "never actually executed" gap spans this entire story.**
  T-red, F, and T-green all performed source-line/structural tracing rather
  than a live PHPUnit/Playwright run, because no phase in this worktree had
  a bootable DDEV instance without risking a shared-registry collision with
  other concurrent worktrees. This is a real (if narrow) residual risk:
  every GREEN verdict above is a trace against static source, not an
  executed assertion. Recommend U's walkthrough explicitly include a
  `npx playwright test tests/e2e/directory-map.spec.ts` run (not just manual
  clicking) against the fully assembled+seeded site, since that would be the
  first real execution of this suite in the entire pipeline.
- `directory-toggle.spec.ts`'s file-level docblock (lines 3-9) still
  describes the switcher as having "Map (soon, unavailable)" as its third
  option — this is historical context describing the state AT SC-5's own
  RED time and is not incorrect for that file's own scope; left unedited
  since correcting it is a documentation nicety, not a test-authorship
  defect, and is out of scope for this repair.

---

## T-repair round 2 (post-U)

**Trigger:** U's live headless Playwright run against the seeded DDEV site (`handoff-U.md`) came back 10/13 GREEN with 3 failures, ALL diagnosed as test-authorship defects (not F implementation bugs). F's DOM / network behavior verified correct in every case via U's direct inspection.

**Edited (test file only, F's code untouched):** `tests/e2e/directory-map.spec.ts`

1. **Line ~162** (roving-tabindex test) — switched Map-radio locator from `getByRole('radio', {name: /^Map$/i})` to `switcher.locator('[data-do-showcase-id="map"]')`. SC-F1 prepends a "● " selection glyph to the checked option's label so the accessible name becomes "● Map"; the stable `data-do-showcase-id` attribute is selection-glyph-independent (shipped by SC-F1 for exactly this reason).
2. **Line ~202** (caption count) — loosened the exact-string assertion `"Showing 4 groups with a location."` to a regex `/Showing 4( of \d+)? groups with a location\./` that accepts either wireframe Surface 1 caption form (N==M or N<M). Seeded site has 11 total groups so the correct render is "Showing 4 of 11 groups with a location." Still pins the load-bearing "4 with location" count.
3. **Line ~293** (positive Leaflet asset assertion) — replaced `/leaflet\.js/i` and `/leaflet\.css/i` URL substring checks (never match under Drupal JS aggregation, which bundles into `/sites/default/files/js/js_*.js`) with `u.includes('/libraries/leaflet/')`. Leaflet's marker sprite PNGs still hit `/libraries/leaflet/images/...` directly and prove local-vendor sourcing. The negative zero-CDN assertion (the real AC) is unchanged.

**Verification:** `npx playwright test tests/e2e/directory-map.spec.ts --list` succeeds, all 13 tests still enumerated. Live re-run against the seeded site is O's next step (T does not re-drive U's live browser).

**Blocking issues:** none new. **F code unchanged.** Ready for U re-verify (or O may accept U's forensic evidence + the targeted test edits and route directly to S).

---

## T-repair round 3 (post-CI, on PR #189)

**Trigger:** After PR #189 opened, remote CI surfaced 4 test failures (3 E2E + 1 Functional) — all diagnosed as stale-contract test-authorship defects (same category as rounds 1 and 2). F's implementation confirmed correct; only the tests needed updating.

### Edits (test files only; F production code untouched)

**File: `tests/e2e/showcase.spec.ts`**

1. **Deleted lines 185-201** — the `'an unavailable option is present, marked, and not a dead click'` test. Its entire premise (Map has `aria-disabled=true`, `tabindex=-1`, label `/soon/i`) no longer applies now that #125 SC-6 flipped `map` to `available` in `VariantSwitcher::directoryLayoutOptionIds()`. `/showcase` and `/all-groups` share `directoryLayoutOptions()`, so every option is live on both. Added an explanatory block comment above the surviving arrow-key tests documenting the retirement. Truthful cleanup, matches the pattern T-green round 1 applied to `directory-toggle.spec.ts`'s map-fallback test.

2. **Rewrote lines 229-262 (was, now 221-249)** — `'ArrowRight moves selection to the next available option and rolls the roving tabindex'`. DOM order is `[compact, cards, map]`. **Before:** ArrowRight from Cards was asserted to SKIP Map and wrap to Compact. **After:** ArrowRight from Cards moves to Map (next available in DOM order — no skipping needed, Map is live). Switched the Map locator from `getByRole('radio', {name: /Map/i})` to `switcher.locator('[data-do-showcase-id="map"]')` — the "● " selection glyph SC-F1 prepends to the checked option would otherwise make the accessible name "● Map" (same guard the round-2 fix already applied to `directory-map.spec.ts`). Removed the trailing "unavailable option is never a valid arrow-nav target" assertion — no unavailable option exists. Updated leading comment.

3. **Updated lines 264-289 (now 251-274)** — `'ArrowLeft moves selection to the previous available option'`. The assertions themselves already pass incidentally (ArrowLeft from Cards naturally lands on Compact whether or not Map is available), so behavior-wise this was already GREEN. Updated title (dropped "skipping the unavailable one") and leading comment to reflect the new "all three available" reality — truthful test-doc consistency.

4. **Updated lines 291-308 (now 276-293)** — `'no-JS ?variant= fallback still works unmodified by the arrow-key fix'`. **Before:** asserted `?variant=map` fell back to `compact` (because Map was unavailable). **After:** `?variant=map` resolves to `map` itself (Map is live). Switched target to Map locator via `data-do-showcase-id="map"`, updated inline comment.

**File: `docs/groups/modules/do_tests/tests/src/Functional/CreateGroupWizardOrganizerTest.php`**

5. **Line 98** — added `'geofield'` to `protected static $modules`, alphabetized the whole list for consistency. Root cause: the test's `importRealCommunityGroupConfig()` helper bulk-imports every `field.storage.group.*.yml` in the assembled config dir; #125 SC-6 added `field.storage.group.field_group_location.yml` (geofield-typed), so the helper now picks it up and Drupal throws `PluginNotFoundException: Unable to determine class for field type 'geofield'` unless the module is enabled in the kernel bootstrap. Same defect pattern the existing docblock (lines 91-97) documents for `image`. Extended the docblock to name `geofield` and the identical rationale.

Confirmed via grep that `importRealCommunityGroupConfig()` is defined and used **only** in this one test — no other Functional test needs the same fix.

### Verification

- `npx playwright test tests/e2e/showcase.spec.ts --list` succeeds: 19 tests enumerated (was 20 before the retirement).
- No F production code touched.

### Kernel job

Kernel job (id `89467661385`) was still IN_PROGRESS at time of triage; polled after the E2E + Functional edits to see whether the analogous `field type 'geofield'` error surfaces there and needs the same modules-array fix in whichever Kernel test bulk-imports storage YAMLs. Result documented in the "Kernel post-check" subsection immediately below once available.

### Blocking issues

None new. F code unchanged.

---

## T-repair round 3.5 (Kernel post-check, same PR #189 CI cycle)

**Trigger:** Kernel job (id `89467661385`) finished FAILURE with 2 additional
stale-contract test defects in
`docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php`
— same category as round 3's E2E + Functional fixes (map's pre-#125
unavailable/(soon)/fallback contract asserted as if still current). F code
untouched.

### Edits (test file only)

**File: `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php`**

1. **`testSwitcherInjectedWithThreeOptionsInOrder` (~line 251-260)** — the
   test's premise (three options render in order) is still valid; only the
   "map is unavailable" flavor is stale. Flipped the two map-specific
   assertions:
   - `assertStringContainsString('aria-disabled="true"', $map_tag_match[0])`
     → `assertStringNotContainsString('aria-disabled', $map_tag_match[0])`.
     Confirmed against the shipped template
     `do-showcase-variant-switcher.html.twig` line 30
     (`{% if option.aria_disabled %}aria-disabled="true"{% endif %}`): the
     attribute is OMITTED for available options, NOT rendered as
     `aria-disabled="false"`. `VariantSwitcher::build()` sets
     `aria_disabled => !$available`, so for map (now `available: TRUE`
     since #125 SC-6) the twig conditional evaluates FALSE and the
     attribute isn't emitted.
   - `assertStringContainsString('Map (soon)', $html)` →
     `assertStringNotContainsString('Map (soon)', $html)`. The
     "(soon)" suffix is appended only for `!$available` options
     (VariantSwitcher.php line 235-238); with map now live the plain
     "Map" label ships.
   - Updated the leading comment from "SC-6 flips this later" to
     "SC-6 flipped this — map is now available." Truthful test-doc.

2. **`testUnavailableMapQueryParamFallsBackToCompact` (~line 306-317)** —
   renamed to `testMapQueryParamResolvesWrapperToMap`; updated
   assertion `assertSame('compact', $attribute, ...)` →
   `assertSame('map', $attribute, ...)`; rewrote docblock to state the
   post-#125 SC-6 contract (map resolves to itself, no fallback) and
   note the graceful-unknown-id fallback contract remains covered by
   the sibling `testUnknownQueryParamFallsBackToCompact()` (untouched).

### Verification

- Diff inspected — both edits surgical, no unintended changes elsewhere in
  the file.
- No F production code touched.
- Live re-run against a fully assembled+seeded site is O's next step (T
  does not run CI or push).

### Blocking issues

None new. Same category as rounds 1, 2, 3: stale-contract test-authorship
defects; every AC still satisfied by shipped F code.
