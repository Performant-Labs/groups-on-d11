# Handoff-F: Phase 5 - #125 SC-6 Directory map view

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Issue:** #125

## What was done

- `docs/groups/libraries/leaflet/leaflet.js` (new) — Leaflet 1.9.4, fetched from `unpkg.com/leaflet@1.9.4/dist/leaflet.js` at build time (curl, not left in code), verified as genuine 1.9.4 (`t.version="1.9.4"` in the bundle tail, header comment confirms).
- `docs/groups/libraries/leaflet/leaflet.css` (new) — Leaflet 1.9.4 CSS, same source, verified via Leaflet-specific selectors.
- `docs/groups/libraries/leaflet/images/marker-icon.png`, `marker-icon-2x.png`, `marker-shadow.png` (new) — default marker sprites, verified via PNG magic bytes + correct dimensions (25×41, 50×82, 41×41).
- `docs/groups/libraries/leaflet/LICENSE` (new) — BSD-2-Clause, fetched from the `v1.9.4` GitHub tag for provenance.
- `scripts/ci/assemble-libraries.sh` (new) — sibling of `assemble-config.sh`; plain idempotent recursive copy of `docs/groups/libraries/*` into gitignored `web/libraries/`. Executable bit set. Ran and re-ran locally to confirm idempotency and confirm `web/libraries/leaflet/**` populates correctly and stays outside `git status` (matches `.gitignore:9`).
- `.github/workflows/test.yml` — added `bash scripts/ci/assemble-libraries.sh` immediately after each of the three existing `bash scripts/ci/assemble-config.sh` call sites (lines 98/230/441 pre-edit), same indentation/style. Confirmed with a Python YAML parse that the file is still well-formed and all three jobs (kernel/functional/e2e) are intact.
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — removed `'available' => FALSE` from the `map` entry in `directoryLayoutOptionIds()` (the one-line flip); updated the method's docblock from "flips" (future tense) to "DID flip" (past tense) and added a short note citing this story, per the task's explicit instruction.
- `docs/groups/modules/do_showcase/do_showcase.info.yml` — appended `geofield:geofield` under `dependencies` (matching the existing `project:module` shape of `drupal:block` / `do_chrome:do_chrome`). Did NOT add `geofield_map`.
- `docs/groups/config/field.storage.group.field_group_location.yml` (new) — `type: geofield`, `cardinality: 1`, `translatable: false`, `dependencies.module: [geofield, group]`, modeled on the sibling `field.storage.group.field_group_location_text.yml`'s shape.
- `docs/groups/config/field.field.group.community_group.field_group_location.yml` (new) — instance on `community_group`, label "Geographic location", `required: false`, `dependencies.config: [field.storage.group.field_group_location, group.type.community_group]` + `dependencies.module: [geofield]`.
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` — appended two entries: `leaflet:` (bare vendor entry, leading-slash paths `/libraries/leaflet/leaflet.js` + `.css`, no dependencies) and `directory-map:` (this story's JS/CSS, depends on `do_showcase/leaflet` + `do_showcase/switcher`).
- `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` (new) — Drupal behavior `doShowcaseDirectoryMap`. Reads `data-do-location-lat/-lng/-url/-name` off each `.views-row`, plots one Leaflet marker per resolvable location (direct-click-navigates, native `title` hover per D-gate approval), builds the SR-only fallback `<ul>`, renders the truthful caption. Sets `L.Icon.Default.imagePath` explicitly. Uses a `MutationObserver` on the wrapper's `data-do-directory-variant` attribute (not a drive-by edit to `do_showcase.switcher.js`) to react to the existing live client-side toggle, since that toggle only mutates one attribute and does not re-trigger Drupal's `once()`-gated behavior re-attach.
- `docs/groups/modules/do_showcase/css/directory-map.css` (new) — every selector scoped under `.views-element-container[data-do-directory-variant="map"]`; hides `.view-content` + `.pager`; sizes `.do-showcase-map`; plain-grey `.leaflet-container` background; `visually-hidden:focus-within` reveal on the fallback list (built on Drupal core's own `.visually-hidden` utility class, not a hand-rolled clip pattern); an inverse-case rule (`:not([data-do-directory-variant="map"])`) hides the map/caption/fallback-list once a live toggle has created them, so toggling back to Cards/Compact doesn't leave a stray map box (found and fixed during self-check — see Design decisions).
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — `viewsPreRender()`: appended an unconditional `$view->element['#attached']['library'][] = 'do_showcase/directory-map';` push (after the existing `directory-compact` push, nothing removed/reordered). New `#[Hook('preprocess_views_view_unformatted')]` method `preprocessViewsViewUnformatted()` + new private helper `groupLocationAttributes()`: reads each row's already-loaded entity off `$view->result[$index]->_entity`, computes `data-do-location-lat/-lng/-url/-name` from `field_group_location` + `field_group_location_text`, writes them onto the row's `Attribute` object. One hook, one loop, one place (A-plan's "simpler alternative").
- `docs/groups/config/views.view.all_groups.yml` — appended a `field_group_location` field entry (type `geofield_default`, `data: lat_lon`, `exclude: true` — added for cache-tag correctness per the brief's own note, not because the preprocess hook needs the rendered field output) between `field_group_description` and `created`; `dependencies.config` gained `field.storage.group.field_group_location`, `dependencies.module` gained `geofield`.
- `docs/groups/scripts/step_700_demo_data.php` — appended Step 738 (coordinate + city-text seed for the 4 target groups). See "Deviation from spec" below — this block does MORE than the brief literally asked for (it also seeds `field_group_location_text`), because the brief's own survey.md assumption that this field was "already set for all four seed groups" turned out to be factually wrong against this file's own prior content.

## Design decisions

- **`MutationObserver` instead of editing `do_showcase.switcher.js`.** The switcher's own click handler mutates `data-do-directory-variant` on the wrapper in place (no DOM insert/remove), so Drupal's `once()`-gated `Drupal.behaviors.attach()` never re-fires for an already-attached element. Rather than adding a directory-map-specific branch to the shared, deliberately-agnostic `do_showcase.switcher.js` (which its own docblock explicitly says stays data-driven/single-responsibility), I gave `do_showcase.directory-map.js` its own `MutationObserver` watching exactly one attribute on the wrapper it already holds a reference to. This is a read-only observation of the switcher's mirror-driven mechanism, not a change to it — no drive-by edit to shared/other-story-owned code.
- **Map container/caption/fallback-list created once, hidden (not removed) when not in map mode.** Follows the SAME "hide via CSS, keep in DOM" discipline `directory-compact.css` and this story's own `.view-content` rule already use. Caught during self-check that I had NOT written the inverse-case hide rule (map elements would stay visible after a toggle away from Map) — added `.views-element-container:not([data-do-directory-variant="map"]) .do-showcase-map, ... { display: none; }` to close that gap before handoff, rather than leaving it as a "known issue."
- **`map.invalidateSize()` on re-entry.** Leaflet computes its internal pixel geometry at construction time; a container that was `display:none` while hidden needs an explicit recompute before `fitBounds()`/`setView()` on a subsequent toggle back into map mode produce a sane viewport. Only called on the "map already exists" branch, never on first construction (where the container is already visible).
- **`role="application"` DROPPED per the D-gate resolution** already recorded in decisions.md — used a plain `<div>` + `aria-label`, no `role` at all (Leaflet's own base container ships no conflicting role by default).
- **Native `title` attribute on markers, per the D-gate APPROVAL** already recorded in decisions.md (Q1) — added at zero extra library/DOM cost.
- **Zero-groups defensive branch: DEFERRED per the D-gate resolution** (Q3) — `plotMarkers()` still handles the 0-marker case defensively (a sensible default `setView` rather than a Leaflet exception from `fitBounds()` on an empty set), but no centered "No groups currently have a location set" helper copy was added — the caption alone ("Showing 0 of N groups with a location") is the only signal, matching the accepted POC simplification.
- **`exclude: true` on the new Views field.** The preprocess hook loads the entity directly and never reads the Views-rendered field output, so the field is exposed for cache-tag/dependency correctness only (per the brief's own note), not for rendering.

## Reuse / extend-vs-new

Extended every seam survey.md named as extend-not-fork: `VariantSwitcher::directoryLayoutOptionIds()` (one-line flip), the wrapper-attribute contract (`data-do-directory-variant`, third value, no new attribute), the `viewsPreRender()`/`preprocessViewsView()` hook pair (appended to, not forked), the `directory-compact` peer-library pattern (mirrored for `directory-map` + a new bare `leaflet` vendor entry, per A-plan resolution #2), and the `field.storage`/`field.field` YAML pair convention (`field_group_language`/`field_group_location_text` as models). The only genuinely new artifacts are exactly the ones survey.md called out in advance: the geofield storage/instance YAML, the vendored Leaflet library, the map JS/CSS, and the new `preprocess_views_view_unformatted` hook (a new hook TYPE on the existing class, not a new class/service). No parallel path was created where the brief called for an extension.

## Architecture notes for A

- **New hook type on an existing class.** `DoShowcaseHooks` gains a `#[Hook('preprocess_views_view_unformatted')]` implementation (previously only `theme`, `page_top` ×3, `views_pre_render`, `preprocess_views_view`). Scoped identically to its siblings via the existing `isDirectoryView()` guard — no new guard logic introduced.
- **Client-side re-render mechanism (`MutationObserver`) is new to this codebase** — no prior file uses one. Deliberately scoped to a single attribute on a single element this behavior already holds a reference to (not a document-wide observer), and deliberately kept out of the shared `do_showcase.switcher.js` (see Design decisions above). Flagging for A's attention as a genuinely new client-side pattern, not because it touches shared code.
- **Schema/contract change:** new field `field_group_location` (geofield) on `community_group`; new Views field entry (excluded from render) on `all_groups`. Both append-only, both declare explicit `dependencies.module: [geofield]` per A-plan Finding #3's resolution.
- **No shared/other-agent-owned file was refactored** — every edit to `VariantSwitcher.php`, `DoShowcaseHooks.php`, `do_showcase.libraries.yml`, `views.view.all_groups.yml`, `do_showcase.info.yml`, and `step_700_demo_data.php` is a pure append or a single named one-line change the brief pre-authorized; nothing else in any of those files was touched.

## Deviations from spec / wireframe

1. **Task item #13 (delete the `directory-toggle.spec.ts` map-unavailable test) — NOT done, by design.** Both my own operating rule (F never writes/edits/deletes tests) and T-red's own handoff (`handoff-T-red.md`: *"T-green must delete or rewrite that one test (T owns test repair) rather than treat its failure as a blocker on F"*) agree this is T-green's responsibility, not F's. I left `tests/e2e/directory-toggle.spec.ts` completely untouched. The test at lines 195-199 (`'?variant=map (unavailable) falls back gracefully to compact, never blank'`) WILL fail once this story's changes land — this is the expected, pre-documented contract transition, not a regression I introduced or need to explain away. Flagging this explicitly so it is not mistaken for an F defect at T-green time.

2. **Seed-data gap found and fixed: `field_group_location_text` was never set on the 4 target groups.** `survey.md`'s assumption ("an existing free-text field already set on all 4 seed groups") is factually wrong against `step_700_demo_data.php`'s own prior content — Step 730 (group creation) never sets `field_group_location_text` at all; only Step 737's three disjoint "Filter Test ..." groups get it. Without a fix, every plotted group's "— City" text and marker `title` would render as a bare group name with no city, which would fail `directory-map.spec.ts`'s "reading Group Name — City" assertion for a reason having nothing to do with this story's map/marker code. Fixed by extending my own Step 738 block (already touching these exact 4 groups by label to seed coordinates) to also backfill the city text, idempotently (only when currently empty). Recorded in-line in the script's own comment.

3. **`views.view.all_groups.yml`'s new field is `type: geofield_default` with `settings: {data: lat_lon}` rather than a bare `latlon` formatter type** — `geofield_default` is the actual formatter plugin id geofield ships (there is no separate `latlon` formatter id); `data: lat_lon` is that formatter's own setting selecting the "Lat / Lon" display mode. This is a naming clarification against the brief's item #11 wording ("type: geofield_default... or `latlon` formatter (whichever the geofield module ships)"), not a functional deviation — the field is `exclude: true` regardless, so its exact formatter settings never render.

## Tier 1 self-check (incl. tests now GREEN)

**No local PHP binary, no DDEV boot in this worktree** (a sibling worktree holds the running project, per the task's explicit instruction — avoiding a container/port collision). Self-check performed at the same analytical rigor T-red used for RED confirmation:

- **YAML:** all 6 touched/new YAML files (`views.view.all_groups.yml`, `do_showcase.info.yml`, `do_showcase.libraries.yml`, both new field YAMLs, `.github/workflows/test.yml`) parse cleanly via `python3 -c "import yaml; yaml.safe_load(...)"` — confirmed above, all `OK`.
- **PHP:** brace/paren balance confirmed on all 3 touched PHP files (`VariantSwitcher.php` 38/38 braces, 142/142 parens; `DoShowcaseHooks.php` 29/29 braces, 249/249 parens; `step_700_demo_data.php` 129/129 braces, 443/443 parens, 181/181 brackets).
- **JS:** `node --check docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` → exits 0, no syntax errors (Node v25.6.1 available in this environment).
- **CSS:** brace-balance confirmed (10/10) on `directory-map.css`.
- **`scripts/ci/assemble-libraries.sh`:** ran twice locally (idempotency proof) — `web/libraries/leaflet/{leaflet.js,leaflet.css,LICENSE,images/*.png}` populate correctly; `git status --porcelain web/libraries/` returns empty and `git check-ignore -v` confirms the match against `.gitignore:9` — the assembled copy correctly never enters git.
- **Leaflet asset provenance:** confirmed genuine 1.9.4 via version string in the bundle (`t.version="1.9.4"`) and header comment; byte sizes sane (leaflet.js 147,552 bytes, leaflet.css 14,806 bytes); marker PNGs verified via magic bytes + correct pixel dimensions (25×41 / 50×82 / 41×41).
- **T's authored tests, traced analytically against the implementation (no live PHPUnit/Playwright run possible in this worktree):**
  - Unit `testDirectoryLayoutOptionsMapEntryIsNowAvailable()` — GREEN (traced: `map` entry now carries no `available` key at all; `$map['available'] ?? TRUE` evaluates TRUE).
  - Kernel `testMapQueryParamResolvesWrapperToMap()` — GREEN (traced: `resolveSelection()`'s first loop now matches `id==='map' && available===TRUE` directly, returns `'map'`, no fallback).
  - Kernel `testDirectoryMapLibraryAttachedWhenMapVariantActive()` — GREEN (traced: `directory-map` string now unconditionally pushed).
  - Kernel `testExistingLibrariesRemainAttachedAlongsideDirectoryMap()` — stays GREEN (pre-existing pushes untouched).
  - E2E (10 primary + 3 non-regression cases in `directory-map.spec.ts`) — 9 of 10 primary cases traced GREEN against the implementation (including the seed-data gap fix above); **1 flagged as a pre-existing test-authorship defect, not an implementation gap** — see "Tests that look wrong" below. All 3 non-regression cases traced GREEN (unaffected by this story's changes).
  - `directory-toggle.spec.ts`, `directory-cards.spec.ts` — untouched, unaffected, except the one pre-documented expected-failure (see Deviation #1 above).

## Tests that look wrong (for T)

**`tests/e2e/directory-map.spec.ts`, test `'the Map option in the switcher is live/selectable — no aria-disabled, no "(soon)" label'` (line 137), specifically the final assertion `await expect(mapOption).not.toHaveAttribute('tabindex', '-1');` (line 147).**

This test navigates to plain `/all-groups` (no `?variant=`), where Cards is selected by default. Under the ALREADY-SHIPPED roving-tabindex pattern (`VariantSwitcher::build()`, pinned by `VariantSwitcherTest::testExactlyOneAvailableOptionHasRovingTabindexZero()` and `testUnavailableOptionIsNeverTheRovingTabindexTarget()`), only the CURRENTLY-SELECTED option ever carries `tabindex="0"`; every other option — available or not — carries `tabindex="-1"` and is reachable only via arrow keys once focus enters the radiogroup, not via Tab. On this exact page load, Map is available-but-unselected, so its rendered `tabindex` is legitimately `"-1"` — confirmed by tracing `VariantSwitcher::build()` (line 248: `'tabindex' => ($available && $is_selected) ? '0' : '-1'`) and the template that renders it unconditionally (`do-showcase-variant-switcher.html.twig` line 31: `tabindex="{{ option.tabindex }}"`, no conditional).

This assertion is asserting against a behavior this story does not change and correctly should not change — "fixing" it (e.g. making every available option simultaneously `tabindex="0"`) would violate the WAI-ARIA radiogroup roving-tabindex pattern and regress two existing, currently-passing Unit tests. The first three assertions in this same test (`toBeVisible()`, `not.toHaveAttribute('aria-disabled', 'true')`, `not.toContainText('Map (soon)')`) are all correct and all pass against my implementation — only the final `tabindex` assertion is the defect. Recommend T either delete that one line, or move the `tabindex` check to a state where Map IS the selected option (e.g. `/all-groups?variant=map`, where the roving-tabindex slot correctly lands on Map itself).

## Known issues

None beyond the one test-authorship item flagged above, which is T's to resolve, not an implementation gap.

## Files changed

- `.github/workflows/test.yml`
- `docs/groups/config/field.field.group.community_group.field_group_location.yml` (new)
- `docs/groups/config/field.storage.group.field_group_location.yml` (new)
- `docs/groups/config/views.view.all_groups.yml`
- `docs/groups/libraries/leaflet/leaflet.js` (new)
- `docs/groups/libraries/leaflet/leaflet.css` (new)
- `docs/groups/libraries/leaflet/LICENSE` (new)
- `docs/groups/libraries/leaflet/images/marker-icon.png` (new)
- `docs/groups/libraries/leaflet/images/marker-icon-2x.png` (new)
- `docs/groups/libraries/leaflet/images/marker-shadow.png` (new)
- `docs/groups/modules/do_showcase/css/directory-map.css` (new)
- `docs/groups/modules/do_showcase/do_showcase.info.yml`
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml`
- `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` (new)
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php`
- `docs/groups/scripts/step_700_demo_data.php`
- `scripts/ci/assemble-libraries.sh` (new)

No test files are in this list (T's `DirectoryMapPreRenderTest.php`, `VariantSwitcherTest.php`, and `tests/e2e/directory-map.spec.ts` were already staged by T before this handoff; I did not create, edit, or re-stage any of them).
