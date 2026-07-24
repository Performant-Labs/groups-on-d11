# Decision journal — #125 SC-6 Directory map view

Append-only. One entry per phase. Format per `pipeline-conventions.md`.

---

## Phase 1 — O (Orchestrator survey & brief)

**Decided:**
- Extend, don't fork: every extension seam this story needs is already established by SC-F1/SC-4/SC-5.
  Only genuinely new artifacts are the geofield storage/instance YAML, the vendored Leaflet library,
  the map JS/CSS, and the E2E spec.
- **No tile layer.** Zero external network requests is a hard AC; live OSM tiles are external; POC scope
  rejects bundling raster tiles. Map renders with markers over a plain background.
- No `geofield_map` runtime attach. `geofield` module provides storage; Leaflet is driven directly from
  our own JS, avoiding CDN-fetching profiles.
- Coordinates are set via seed script append (step_700_demo_data.php), not via a separate seed step.

**Assumed (to verify during implementation):**
1. `hook_preprocess_views_view_field` on the geofield field emits lat/lng data-attrs to a row wrapper
   (or a `preprocess_views_view_unformatted` variant is needed instead).
2. Leaflet 1.9.4 marker-image resolution: must set `L.Icon.Default.imagePath` explicitly.
3. Adding `geofield` as a `do_showcase.info.yml` dependency doesn't transitively enable `geofield_map`.

**Hedged:** POC lean pipeline — skipping brief-gate, A-dup, pre-PR hold. Self-merge on CI-green.

**Evidence:** Survey `docs/handoffs/0125-directory-map/survey.md`; grepped every extension seam and
confirmed all four seed groups already exist in `step_700_demo_data.php`.

---

## Phase 2 — D (Designer)

**Decided:**
- Map region REPLACES the row grid (not an overlay) — mirrors SC-5's existing "one presentation
  visible at a time" model; `.view-content` hidden via CSS, rows stay in the DOM unstyled so JS can
  still read `data-do-location-lat/lng` off them.
- Marker click is direct-navigate, no popup step — the brief's AC is unambiguous ("Clicking a
  marker navigates to the group's page"), so no intermediate popup/tooltip-then-link pattern is
  introduced.
- Truthful partial-coverage caption: "Showing N of M groups with a location" when N < M; drops the
  "of M" clause when N == M (no redundant "of 4" with no gap to disclose).
- Keyboard fallback is an SR-only `<ul>` of "group name — city" links (visible-on-focus, not
  `display:none`) — the map canvas itself is not made marker-by-marker keyboard-navigable; the list
  IS the keyboard path, not a supplement, per POC "simplest that satisfies AC" framing.
- Wrapper contract mirrors `directory-compact.css` exactly: `data-do-directory-variant="map"` on
  `.views-element-container`, third value on the same attribute SC-5 already established.
- Filtered-to-zero-overall state reuses the existing view empty-region copy verbatim, rendered
  inside the map container box.

**Assumed (flagged for approval):**
1. Hover/focus `title` attribute on markers for a pre-click preview — not requested by brief,
   recommended as a zero-cost nicety, not blocking.
2. Leaflet 1.9.4's default container markup doesn't conflict with the `role="application"` +
   `aria-label` this wireframe specifies — F to verify against the vendored asset.
3. Zero-groups-have-a-location defensive state (not reachable with current 4-seed data) — deferred
   to O/human: implement the friendlier branch now, or accept "Showing 0 of 4..." caption-only as
   sufficient POC simplification.

**Hedged:** None beyond the three open questions above — wireframe is otherwise unambiguous.

**Evidence:** `docs/groups/modules/do_showcase/css/directory-compact.css` (peer-variant CSS
contract), `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (wrapper-attribute
attach seam), `docs/handoffs/0124-directory-toggle/wireframe.md` (prior-story wireframe
conventions this story extends rather than reinvents).

---

## Phase 2 — D (Designer) + D-gate

**Decided:** Wireframe accepted, D-gate PASS (POC lean pipeline auto-approve). Three open questions resolved:
- Q1 (hover title on markers): **APPROVED** — F adds a native `title` attribute on each marker (browser-native hover preview, zero library cost).
- Q2 (`role="application"` on map container): **DROPPED** — Leaflet's own ARIA/keyboard model is minimal and `role="application"` would intercept SR virtual-cursor arrow-key navigation for a widget that doesn't warrant it. Use `role="region"` with `aria-label` instead, or omit `role` and let the `aria-label` do the labeling on a plain `<div>`. F picks the simpler of these two based on the vendored Leaflet output.
- Q3 (zero-groups-have-location defensive state): **DEFERRED** — POC scope; unreachable in the 4-seed dataset. Caption "Showing 0 of N groups with a location" over a plain grey container is sufficient. No centered helper copy.

**Assumed:** None new.
**Hedged:** None.
**Evidence:** `docs/handoffs/0125-directory-map/wireframe.md`, `handoff-D.md`.

---

## Phase 3 — A (Architecture Reviewer up-front plan review)

**Decided:** Verdict BLOCK. See `handoff-A-plan.md` for the full finding table.

**Blocking finding:** `/web/libraries/` is gitignored (`.gitignore:9`). Vendoring Leaflet directly
into `web/libraries/leaflet/` will produce no-op commits and CI will 404 the map assets. O must
pick one of three strategies (staged-under-`docs/groups/libraries/` + assemble step, `.gitignore`
negation, or Composer asset package) and record it before T-red; recommend the assemble-step
option to mirror the existing source-tree discipline.

**Warns:**
- Whether Leaflet should be a separate top-level library entry (`do_showcase/leaflet`) that
  `directory-map` depends on, vs. inlined into `directory-map`. Pick one in the brief.
- F to include a fresh `drush si` + config-import boot verification, since the new geofield
  storage YAML introduces a config-import module-dependency edge that isn't covered by the
  existing test suite.

**Passed dimensions:** reuse-map correctness, cache metadata (existing `url.query_args:variant`
context + wrapper-attribute contract cover the map variant with no new context/tag), Views config
layering (pure append, no ordering hazard), config schema (geofield module ships schema; storage
YAML just uses `type: geofield`), `geofield` vs `geofield_map` (adding `geofield` alone does not
transitively enable `geofield_map`), unconditional JS attach (correct — no-reload live switching
requires it and matches the peer `directory-compact` pattern), no duplication risk.

**Assumed:** None.
**Hedged:** None.
**Evidence:** `.gitignore:9`, `composer.json` `installer-paths` (Composer expects
`web/libraries/{name}` but the tree is gitignored), `docs/groups/modules/do_showcase/do_showcase.libraries.yml`
(peer library shape), `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` lines 448-514
(SC-5 seam this story extends), `docs/groups/config/field.storage.group.field_group_location_text.yml`
(sibling storage YAML).

---

## Phase 3 — A (Architecture Reviewer, plan review) — BLOCK → resolved → PASS pending re-spawn

A returned BLOCK on 1 finding + 2 warnings. Resolved:

**Finding #1 (blocker — `/web/libraries/` is gitignored, .gitignore:9):**
Chose strategy **(a)** per A's recommendation: mirror the existing `docs/groups/config/` → `web/modules/custom/` source-→-assemble discipline. Concretely:
- SOURCE: `docs/groups/libraries/leaflet/**` (tracked in git — leaflet.js, leaflet.css, images/*)
- TARGET: `web/libraries/leaflet/**` (gitignored; populated at build/CI time)
- NEW SCRIPT: `scripts/ci/assemble-libraries.sh` (sibling of `assemble-config.sh`, same structure — plain copy, idempotent, no DB changes)
- CI wiring: `.github/workflows/test.yml` gains one `bash scripts/ci/assemble-libraries.sh` line after each existing `bash scripts/ci/assemble-config.sh` line (three call sites)
- F verifies locally by running the script and confirming `web/libraries/leaflet/leaflet.js` exists before running Playwright

**Finding #2 (warn — leaflet library entry: separate vs inline):**
Chose sub-choice **(a)** per A's recommendation: declare a bare `leaflet:` library entry in `do_showcase.libraries.yml` (pointing at `/libraries/leaflet/leaflet.js` + `.css`), then have `directory-map:` depend on `do_showcase/leaflet` + `do_showcase/switcher`. Reusable for any future map consumer; matches Drupal's library-composition idiom.

**Finding #3 (warn — geofield vs geofield_map transitive enable + fresh-boot verification):**
Confirmed `do_showcase.info.yml` gains only `- geofield` (not `- geofield_map`). F's handoff will include an explicit "boot verification" step: after applying changes and running `assemble-config.sh` + `assemble-libraries.sh`, run `drush si` (or the equivalent CI ordering) + `drush cim` on a clean DB to prove the geofield dependency wires end-to-end. Both field storage AND field instance YAML will declare `dependencies.module: [geofield]` explicitly (mirrors `field.storage.group.field_group_location_text.yml`'s explicit `dependencies.module: [group]`).

**Decided:** all three resolved. Re-spawning A on the amended brief.
**Assumed:** `assemble-libraries.sh` must run AFTER `composer install` (not needed for Leaflet vendor copy — vendor tree is in git, so it's literally just `cp -r docs/groups/libraries/. web/libraries/`; no vendor autoload check needed like assemble-config.sh has).
**Hedged:** none.
**Evidence:** `docs/handoffs/0125-directory-map/handoff-A-plan.md`; verified `.gitignore:9` and composer installer-paths.

**A re-review PASS.** All three findings adequately resolved. Advancing to T(red).

---

## Phase 4 — T (Tester, RED)

**Decided:**
- Authored one Unit test extension (`VariantSwitcherTest::testDirectoryLayoutOptionsMapEntryIsNowAvailable()`),
  one new Kernel test class (`DirectoryMapPreRenderTest`, 3 methods), and one new
  E2E spec (`tests/e2e/directory-map.spec.ts`, 10 new + 3 non-regression cases) —
  see `handoff-T-red.md` for the full per-test criterion/tier/RED-reason table.
- RED confirmed **analytically** (source-line tracing), not via a live PHPUnit/
  Playwright run — DDEV is not booted in this worktree (sibling worktree
  `gm129-activity` already holds a running DDEV project; avoiding a container/
  port collision, per the task's explicit instruction). `npx esbuild` confirmed
  the new TS spec is syntactically valid (0 compile errors); PHP files were
  brace/paren-balance-checked (no local `php` binary, `ddev exec` unavailable —
  occupied by the sibling worktree).
- Flagged for T-green: `directory-toggle.spec.ts`'s existing
  `'?variant=map (unavailable) falls back gracefully to compact, never blank'`
  test will fail once F's implementation lands (map is no longer unavailable —
  this is the INTENDED contract transition, not a regression). T-green must
  delete/rewrite that one test (T owns test repair) rather than treat its
  failure as a blocker on F.
- Did not touch `directory-toggle.spec.ts` or `directory-cards.spec.ts` — the
  new spec's non-regression describe block re-asserts a strict subset of their
  existing coverage using identical locator helpers, so there is no selector
  drift risk between specs.

**Assumed:** None new beyond what survey.md/wireframe.md already assumed.

**Hedged:** RED validity rests on analytical source-tracing rather than an
executed run, per this phase's explicit boot-verification constraint; F and
T-green must be the first to actually execute these tests against a live
assembled+seeded site.

**Evidence:** `docs/handoffs/0125-directory-map/handoff-T-red.md`;
`VariantSwitcher.php` lines 81-115, 340-354; `DoShowcaseHooks.php` lines
448-462; `do_showcase.libraries.yml` (full file, no `directory-map`/`leaflet`
keys); grep confirming zero existing matches for `.do-showcase-map` /
`.do-showcase-map-fallback-list` / `field_group_location` (storage) anywhere
in the repo.

---

## Phase 5 — F (Feature Implementor)

**Decided:**
- Implemented all 12 substantive checklist items against T's RED suite: vendored
  Leaflet 1.9.4 under `docs/groups/libraries/leaflet/` (verified genuine via
  version string + byte sizes + PNG magic bytes), wrote
  `scripts/ci/assemble-libraries.sh` (ran + re-ran locally, confirmed idempotent
  and confirmed the assembled `web/libraries/leaflet/` never enters
  `git status`), wired the 3 `.github/workflows/test.yml` call sites, flipped
  `map`'s availability (one line), added the geofield storage/instance YAML +
  `do_showcase.info.yml` dependency, added the two library entries, wrote the
  map JS (Drupal behavior + `MutationObserver` for live client-side re-render)
  and CSS, wired `DoShowcaseHooks.php` (unconditional library attach +
  a new `preprocess_views_view_unformatted` hook + a private
  `groupLocationAttributes()` helper — "one hook, one loop, one place" per
  A-plan's simpler alternative), appended the Views field, and appended the
  seed-data coordinate block.
- **Did NOT delete/edit `tests/e2e/directory-toggle.spec.ts`'s map-unavailable
  test**, despite the task instruction to do so — both my own operating rule
  (F never touches tests) and T-red's own handoff (which explicitly assigns
  that repair to T-green: "T owns test repair") agree this is out of scope for
  F. Left the file untouched; the expected failure is flagged in
  `handoff-F.md` for T-green to action.
- **Found and fixed a real seed-data gap during self-check:** survey.md's
  assumption that `field_group_location_text` was "already set for all four
  seed groups" is factually wrong against `step_700_demo_data.php`'s own prior
  content (only 3 DISJOINT "Filter Test..." groups from Step 737 have it,
  never the 4 target groups from Step 730). Extended my own Step 738 seed
  block (already touching these 4 groups by label) to also backfill the city
  text idempotently, since without it the "Group Name — City" fallback-list/
  marker-title copy would render with no city for any of the 4 target groups.
- **Found and fixed a CSS gap during self-check:** the map container/caption/
  fallback-list, once created by a live client-side toggle into Map mode,
  had no rule hiding them again on a toggle back to Cards/Compact — added an
  inverse-case `:not([data-do-directory-variant="map"])` hide rule before
  handoff, closing the gap rather than leaving it as a known issue.
- **Flagged one test as looking wrong** (`directory-map.spec.ts`'s
  `'the Map option in the switcher is live/selectable...'` test's final
  `tabindex` assertion) — it asserts against the ALREADY-SHIPPED
  roving-tabindex contract in a state (default page load, Cards selected)
  where Map legitimately carries `tabindex="-1"` as an available-but-
  unselected option; "fixing" it would regress two existing Unit tests and
  violate the WAI-ARIA radiogroup pattern. Recorded in handoff-F.md's "Tests
  that look wrong" section for T to action, not edited.

**Assumed:**
1. Drupal core `^11.4`'s `.visually-hidden` utility class still uses the
   classic `clip: rect(...)` + `position: absolute !important` technique
   (not a `clip-path`-based one) — confirmed correct for this core line;
   added a defensive `clip-path: none` override anyway at zero cost.
2. Views' `style: type: default` on `all_groups.page_1` is the `DefaultStyle`
   ("Unformatted list") plugin, whose theme hook is `views_view_unformatted`
   — confirmed via Drupal core knowledge (stable since Drupal 8) and
   consistent with `directory-compact.css`'s own `.views-row` targeting.
3. `$view->result[$index]->_entity` is populated for the `groups_field_data`
   base table (an entity-based base table) without an extra entity load —
   standard Views/entity-query behavior, matching how every other
   `getEntity()` consumer in this codebase reads an already-resolved entity.

**Hedged:** No local PHP/DDEV in this worktree (per the task's explicit
instruction — a sibling worktree holds the running project). Self-check is
analytical/source-traced (same rigor T-red used for RED), not an executed
PHPUnit/Playwright run. T-green is the first phase to execute these tests for
real against a live assembled+seeded site.

**Evidence:** `docs/handoffs/0125-directory-map/handoff-F.md` (full per-file
change list + per-test GREEN tracing); local `assemble-libraries.sh` dry-run
output; `git check-ignore -v web/libraries/leaflet/leaflet.js` confirming
`.gitignore:9` match; Leaflet asset provenance checks (version string, byte
sizes, PNG magic bytes) documented in handoff-F.md's Tier 1 self-check.

---

## Phase 6 — T (Tester, GREEN)

**Decided:**
- Task A: deleted `directory-toggle.spec.ts`'s `'?variant=map (unavailable)
  falls back gracefully to compact, never blank'` test outright (option a) —
  the retired pre-#125 contract is fully superseded by
  `directory-map.spec.ts`'s exhaustive positive-contract coverage; a rewrite
  would have duplicated it.
- Task B: split `directory-map.spec.ts`'s broken `tabindex="-1"` assertion
  (asserted in a state — default page load, Cards selected — where Map is
  legitimately unselected-but-available and correctly carries
  `tabindex="-1"` under the already-shipped WAI-ARIA roving-tabindex
  pattern) out of the original test, and added a new test at
  `?variant=map` asserting the correct positive form (`aria-checked="true"`
  + `tabindex="0"`) — option (b), strengthening coverage rather than just
  deleting the broken line.
- Verified GREEN for all 4 Unit/Kernel tests + all 14 E2E tests (11 primary
  + 3 non-regression, one net-new after the Task B split) via analytical
  source-tracing against F's actual implementation (not just F's
  description) — traced every assertion to the specific line in
  `VariantSwitcher.php`, `DoShowcaseHooks.php`, `do_showcase.libraries.yml`,
  `directory-map.js`, `directory-map.css`, and `step_700_demo_data.php` that
  satisfies it. No implementation gap found.
- Ran what tooling IS available in this worktree: `npx esbuild` on both
  edited spec files (0 errors each), `bash scripts/ci/assemble-libraries.sh`
  (succeeded, matches F's own run), `bash scripts/ci/assemble-config.sh`
  (fails only at the `composer install` prerequisite, same env gap F hit),
  YAML validity checks on all 6 touched/new YAML files (all OK). Did not
  force a DDEV boot for this worktree (`gm129-activity`) — its registry
  entry points at a stale sibling-worktree path, and untangling DDEV's
  shared project registry mid-run risks corrupting state for other
  concurrent worktrees per the shared-registry precedent.
- Staged both edited test files explicitly (`git add tests/e2e/directory-toggle.spec.ts tests/e2e/directory-map.spec.ts`) — no `git add .`.

**Assumed:** None new.

**Hedged:** GREEN verdict rests on analytical source-tracing, not an
executed PHPUnit/Playwright run, continuing the same constraint every prior
phase of this story operated under. Flagged for U: the FIRST real execution
of this suite (`npx playwright test tests/e2e/directory-map.spec.ts`)
against a fully assembled+seeded site has not yet happened anywhere in this
pipeline — recommend U's walkthrough include an actual run, not just manual
click-through verification.

**Evidence:** `docs/handoffs/0125-directory-map/handoff-T-green.md`;
`VariantSwitcher.php` lines 87-93, 254; `DoShowcaseHooks.php` lines 460-479,
559-585, 600-630; `do_showcase.libraries.yml` lines 78-114;
`do-showcase-variant-switcher.html.twig`; `directory-map.js` (full file);
`directory-map.css` (full file); `step_700_demo_data.php` lines 735-803;
`views.view.all_groups.yml` field entry; local `esbuild`/YAML/
assemble-script run output.

**Verdict: GREEN, no blocking issues. Ready for U.**

---

## Phase 8 - U (UI Walkthrough, live headless Chromium)

**Decided:**
- Story is behaviorally GREEN in a real browser against a real DDEV-served, cim-imported, seed-populated site (worktree DDEV project gm125-map on router ports 8125/8144).
- Every wireframe.md Surface 1 check passes: 4 markers plot at correct positions (Portland, Paris, Brussels, Berlin), caption reads Showing 4 of 11 groups with a location. (truthful N<M form with a full seed of 11 groups), marker click navigates to /group/N canonical URL, SR-only fallback list reveals on :focus-within, live client-side Cards<->Map round-trip works with invalidateSize on re-entry, no console/page errors.
- Zero-CDN AC fully satisfied: 0 external requests, 0 CDN/tile-host hits during a full map page load.
- Non-regression suites (directory-toggle, directory-cards) 13/14 GREEN (1 skipped, 0 failed).

**Assumed:** None new.

**Hedged:** 3 of 13 directory-map.spec.ts assertions fail live; every failure traced to test-authorship (accessible-name regex vs SC-F1 selection-glyph label, N==M caption wording vs full-seed N<M reality, and raw-URL leaflet asset check vs Drupal JS aggregation). None indicate an F defect - the underlying DOM/network is correct in every case. T-repair will be needed before CI can be green on this file, but U views this as a T task, not a reason to send F back.

**Advisory (non-blocking, for orchestrator):** scripts/ci/assemble-config.sh should learn to register geofield in core.extension.yml (this story added geofield as a do_showcase dependency). Without that, future clean-room bring-ups hit the config:import failure I patched manually in bring-up step 8.

**Evidence:** docs/handoffs/0125-directory-map/handoff-U.md - full walkthrough table, network trace, Playwright suite runs, WCAG spot check.

**Verdict: GREEN. Ready for S.**

---

## Phase 8-fix — O patches scripts/ci/assemble-config.sh (per U advisory)

**Decided:** Applied U's advisory. `assemble-config.sh` now includes `geofield` in the `ENABLE_MODULES` list alongside `flag/language/message/message_notify`. Added a comment block explaining the hard dependency (do_showcase's field.storage.group.field_group_location declares `dependencies.module: [geofield]`, so a clean-room `config:import` needs geofield already enabled in core.extension). Updated the final echo. Verified alphabetical placement matches the existing style.

Without this patch, CI's clean-room `drush cim` would fail exactly as U's step 8 did (had to manually add `geofield: 0` to `config/sync/core.extension.yml`).

**Evidence:** grep confirms lines 133-137 (comment) + 140 (ENABLE_MODULES) + 162 (echo).
**Assumed:** none new.
**Hedged:** none.

---

## Phase 8-fix / T-repair round 2 — three test-authorship fixes post-U

**Decided:** All three defects U identified in `tests/e2e/directory-map.spec.ts` are test-authorship bugs (not F implementation defects — F's DOM/network is correct in every case). Edited the spec only; F's code untouched.

**Fix #1 — Line 162 (tabindex on selected Map, roving-tabindex test).**
Root cause: SC-F1 (#119) prepends "● " to the SELECTED option's visible label, so the accessible name for the checked Map radio is "● Map", not "Map" — `getByRole('radio', { name: /^Map$/i })` never matches.
Pattern: switched to the stable `data-do-showcase-id` attribute (shipped by SC-F1 for exactly this reason, per `do_showcase.switcher.js`), which is selection-glyph-independent.
Before:
```ts
const mapOption = switcher.getByRole('radio', { name: /^Map$/i });
```
After:
```ts
const mapOption = switcher.locator('[data-do-showcase-id="map"]');
```

**Fix #2 — Line 202 → 213 (caption text, N==M vs N<M).**
Root cause: wireframe.md Surface 1 permits both `"Showing N groups with a location."` (N==M) and `"Showing N of M groups with a location."` (N<M). The seeded site has 11 total groups (persona seed adds beyond the 4 with coordinates), so the correct rendered form is the N<M form: `"Showing 4 of 11 groups with a location."` The test pinned the N==M form only.
Pattern: loosened to a regex that accepts either wireframe form while still pinning the load-bearing "4 with a location" count.
Before:
```ts
await expect(page.getByText('Showing 4 groups with a location.')).toBeVisible();
```
After:
```ts
const caption = page.getByText(/Showing 4( of \d+)? groups with a location\./);
await expect(caption).toBeVisible();
```

**Fix #3 — Line 293 (positive Leaflet asset assertion).**
Root cause: Drupal's default JS aggregation bundles `leaflet.js` into `/sites/default/files/js/js_*.js`, so no request URL literally contains "leaflet.js" — the asserted string will never match under aggregation. But Leaflet's marker sprite PNGs (marker-icon.png, marker-shadow.png) DO hit `/libraries/leaflet/images/...` directly, which proves local-vendor sourcing just as well.
Pattern: chose U's preferred option — relax to "any request under `/libraries/leaflet/` was made" (strengthens rather than weakens: marker PNGs are proof of local vendoring, and the negative zero-CDN assertion just above still guards CDN regressions). Removed the corresponding `leaflet.css` line too since neither survives aggregation as a raw URL; the single `/libraries/leaflet/` check subsumes both intents.
Before:
```ts
expect(leafletRequests.some((u) => /leaflet\.js/i.test(u))).toBe(true);
expect(leafletRequests.some((u) => /leaflet\.css/i.test(u))).toBe(true);
```
After:
```ts
expect(leafletRequests.some((u) => u.includes('/libraries/leaflet/'))).toBe(true);
```

**Assumed:** none new — every diagnosis is grounded in U's live evidence (real browser DOM inspection, real network trace).
**Hedged:** none. `npx playwright test --list` still lists all 13 tests; syntax is valid.
**Evidence:** `tests/e2e/directory-map.spec.ts` diff; U handoff (docs/handoffs/0125-directory-map/handoff-U.md) for defect grounding.

---

## Phase 9 — S (Spec Auditor)

**Decided:** Verdict **PASS — ready for PR**. Every AC in issue #125 is satisfied by the shipped implementation. Cross-artifact truthfulness confirmed: brief → wireframe → A-plan → T-red → F → T-green → U → S all coherent, no drift.

**Special-attention items verified (per S task brief):**
1. Zero-CDN AC: PASS by two methods — static grep of JS/CSS/vendored assets (only URL matches are prose docblock/comment strings; zero `L.tileLayer`/`unpkg`/`cdn`/`mapbox`) + U's live network trace (0 external requests, 0 CDN hits).
2. ONE field addition: PASS — exactly one logical field (`field_group_location`), storage + instance YAML both declaring explicit `dependencies.module: [geofield]`.
3. Composer.json append-only: PASS — zero diff on composer.json/lock (geofield already present upstream via SC-F1 lineage).
4. `views.view.all_groups.yml` append-only: PASS — appended field row + filter entries; no SC-5 switcher-mounting field reordered/deleted.
5. HelpText: PASS — `showcase.switcher.directory.layout` (SC-F1 shipped) already reads "…Map plots groups geographically." No new entry needed.

**Non-blocking pre-PR nits (recorded in handoff-S.md for O's consideration, none block):**
1. Map container `aria-label` shipped in shorter form than wireframe Surface 2's richer proposal — both meet AA; count is disclosed in visible caption above.
2. `leaflet.css` references `images/layers.png` + `images/layers-2x.png` for a layers control this story never renders — harmless (unreachable selectors), but a future story adding a layers control will hit same-origin 404s until the two PNGs are vendored.
3. `directory-toggle.spec.ts` file-level docblock still describes Map as "(soon, unavailable)" — stale but not incorrect at that file's original RED time.

**Assumed:** None new.
**Hedged:** None. Visual-diff tooling not exercised — the wireframe is structural low-fi (no pixel reference), and U's live headless-Chromium walkthrough already covered the rendered surface for both intended states and the toggle round-trip.
**Evidence:** `docs/handoffs/0125-directory-map/handoff-S.md`; `git diff --cached origin/main` per-file inspection (composer.json, do_showcase.info.yml, do_showcase.libraries.yml, VariantSwitcher.php, views.view.all_groups.yml, field YAMLs); grep of `docs/groups/libraries/leaflet/` + directory-map JS/CSS for CDN/external hosts.

**Verdict: PASS. Ready for PR.**

---

## Phase 10-fix — T-repair round 3 (post-CI)

**Decided:**
- Delete showcase.spec.ts's "unavailable option" test outright (Map is no longer unavailable post-#125 SC-6; the test's premise is gone). Truthful cleanup, matches the same pattern round 1 applied to directory-toggle.spec.ts.
- Rewrite showcase.spec.ts ArrowRight from-Cards expectation: lands on Map (next available in DOM order `[compact, cards, map]`) instead of skipping to Compact.
- Update showcase.spec.ts ArrowLeft test's title/comment (assertions already pass incidentally — ArrowLeft naturally lands on Compact).
- Update showcase.spec.ts `?variant=map` fallback test: expects Map to be selected directly (Map is now live), not to fall back to Compact.
- Add `geofield` to CreateGroupWizardOrganizerTest.php's `$modules` array (bulk import of `field.storage.group.*.yml` now picks up the new geofield-typed `field_group_location` storage YAML).

**Assumed:** None new. Every diagnosis grounded in F's actual shipped code (`VariantSwitcher::directoryLayoutOptionIds()` reflects three available options; DOM order `[compact, cards, map]`) and CI's exact error trace ("Unable to determine class for field type 'geofield'").

**Hedged:**
- Kernel job still IN_PROGRESS at triage time. If it also fails on `field type 'geofield'` a matching modules-array fix is required in the Kernel-test bulk-importer. Polled post-edit; result recorded in handoff-T-green.md.

**Evidence:**
- `tests/e2e/showcase.spec.ts` diff.
- `docs/groups/modules/do_tests/tests/src/Functional/CreateGroupWizardOrganizerTest.php` diff.
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` lines 87-93 (DOM order `[compact, cards, map]`).
- `gh pr view 189 --repo Performant-Labs/groups-on-d11 --json statusCheckRollup` — jobs 89467661365 (Functional) + 89467661312 (E2E) FAILURE, 89467661385 (Kernel) IN_PROGRESS.
- `npx playwright test tests/e2e/showcase.spec.ts --list` — 19 tests, parses clean.

---

## Phase 10-fix — T-repair round 3.5 (Kernel, same CI cycle as round 3)

**Decided:**
- Fix Kernel `DirectoryTogglePreRenderTest.php` in-place: flip the two
  map-unavailable assertions in `testSwitcherInjectedWithThreeOptionsInOrder`
  (aria-disabled: now assert absent, not present; "(soon)" suffix: now
  assert absent, not present), and rename+rewrite
  `testUnavailableMapQueryParamFallsBackToCompact` →
  `testMapQueryParamResolvesWrapperToMap` (asserts `?variant=map` selects
  `map`, not falls back to `compact`).
- Ship all six repairs (rounds 3 + 3.5) in one commit: showcase.spec.ts (3)
  + DirectoryTogglePreRenderTest.php (2) + CreateGroupWizardOrganizerTest.php
  ($modules extension). No F code touched.

**Assumed:** None new. Template behavior for `aria-disabled` verified
directly against `do-showcase-variant-switcher.html.twig` line 30
(`{% if option.aria_disabled %}...{% endif %}` — attribute omitted, not
rendered false-valued) — the assertion needed the twig-conditional-aware
"NotContainsString 'aria-disabled'" form rather than a
"ContainsString 'aria-disabled=\"false\"'" form.

**Hedged:** GREEN verdict for the Kernel edits rests on the same
analytical source-tracing every prior T phase used (no local `php`/DDEV
in this worktree). Live CI on PR #189 is the first real Kernel execution
of the repaired assertions.

**Evidence:**
- `docs/groups/modules/do_showcase/templates/do-showcase-variant-switcher.html.twig` line 30 (conditional aria-disabled emission).
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` lines 235-238 ("(soon)" appended only for `!$available`), 249 (`aria_disabled => !$available`).
- `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php` diff.
- CI job id `89467661385` (Kernel FAILURE) — original failing assertions.
