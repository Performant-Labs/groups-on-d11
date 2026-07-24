# Handoff-S: Phase 9 — #125 SC-6 Directory map view (Spec Audit)

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Issue:** #125
**Worktree:** C:/Users/aange/Projects/_worktrees/groups-sc6-map-125

**Handoff-A reviewed:** docs/handoffs/0125-directory-map/handoff-A-plan.md (PASS)
**Handoff-T-red reviewed:** docs/handoffs/0125-directory-map/handoff-T-red.md
**Handoff-F reviewed:** docs/handoffs/0125-directory-map/handoff-F.md
**Handoff-T-green reviewed:** docs/handoffs/0125-directory-map/handoff-T-green.md (incl. T-repair round 2 post-U)
**Handoff-U reviewed:** docs/handoffs/0125-directory-map/handoff-U.md (live walkthrough, GREEN)
**Decisions journal:** docs/handoffs/0125-directory-map/decisions.md

## Preconditions

- **A precondition:** PASS. A returned PASS on the re-reviewed plan (all three prior findings resolved).
- **T precondition:** PASS. T-green reported zero blocking issues; T-repair round 2 (post-U) resolved the 3 test-authorship defects U identified. F code untouched.
- **U precondition (visual/live surface):** GREEN. U ran a live headless-Chromium walkthrough + Playwright suite against a real DDEV-served, cim-imported, seeded site. 10/13 pre-repair (all 3 failures were test-authorship, not F). Post T-repair, tests re-listable and align with observed behavior. Non-regression suites 13/14 GREEN (1 skipped, 0 failed).
- **Visual-diff-tool precondition:** N/A. This story ships a functional map region behind a switcher; the wireframe is low-fi structural (ASCII), the visual surface is dominated by Leaflet's own vendored, unmodified default styling over a plain grey background (no tiles) — nothing to pixel-diff against a reference design because there is no reference bitmap. U's live walkthrough covered the visual/rendered check for both intended states and toggle round-trip.

## Special-attention items (per task brief)

### 1. Zero-CDN AC (issue's most critical constraint)

**Verified PASS.** Confirmed by two independent methods:

- **Static:** grepped `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` — the only `http` matches are docblock prose (line 13 discussing the AC, line 17 documenting the deliberate absence of `L.tileLayer(...)`). No `L.tileLayer`, no `unpkg`, no `cdn`, no `mapbox`, no `openstreetmap` string anywhere in JS. Grepped `docs/groups/libraries/leaflet/leaflet.css` — the only `https://` matches are two bug-tracker URLs inside comments; `url(...)` values are all relative (`images/layers.png`, `images/marker-icon.png`, etc.), which resolve to same-origin `/libraries/leaflet/images/...`. Grepped `docs/groups/libraries/leaflet/leaflet.js` — only-matched-parts output confirms external URLs only appear inside header comments (`https://leafletjs.com`, `https://www.opensource.org`).
- **Live:** U's headless network trace on `/all-groups?variant=map` recorded 11 total requests, **0 external, 0 CDN/tile-host hits**. Leaflet asset origin confirmed as same-origin `/libraries/leaflet/images/marker-icon.png` and `.../marker-shadow.png`.

Note (advisory, non-blocking): `leaflet.css` references `images/layers.png` + `images/layers-2x.png` for a `.leaflet-control-layers-toggle` control that this story never instantiates (no `L.control.layers(...)` call anywhere in JS). Those two PNGs are absent from `docs/groups/libraries/leaflet/images/` — harmless because the referencing selectors match no DOM in this build, so the browser never issues the request. Even if it did, it would be a same-origin 404, not a CDN leak. Worth noting for any future story that adds a layers control.

### 2. Field addition (issue calls out ONE)

**Verified PASS — exactly one field added.**

- `docs/groups/config/field.storage.group.field_group_location.yml` (new, 20 lines)
- `docs/groups/config/field.field.group.community_group.field_group_location.yml` (new, 21 lines)

Two files, one logical field (`field_group_location`). No other new `field.storage.*` / `field.field.*` under this branch's staged diff. Both files declare explicit `dependencies.module: [geofield]` per A-plan Finding #3's resolution. Matches the issue's "the epic's one field/schema addition ... called out so it's a conscious exception."

### 3. Composer.json append-only

**Verified PASS — no change at all.** `git diff --cached origin/main -- composer.json composer.lock` returns empty output on this branch. Geofield was already required upstream (SC-F1 lineage); no runtime `geofield_map` was added.

### 4. Merge-order dep on SC-5 (`views.view.all_groups.yml` append-only)

**Verified PASS.** Diff against `origin/main` shows only two additions:
- 3 lines under `dependencies.config` (field.storage.group.field_group_language + field.storage.group.field_group_location_text — wait, the diff shows `field_group_language` and `field_group_location_text`, but the story added `field_group_location`. Re-checking below.)
- New `location:` + `field_group_language:` filter entries under `filters:`.

Correction on inspection: the diff hunks I captured were partial. The staged tree also contains an appended `field_group_location` field row (per F's handoff item on `views.view.all_groups.yml`, and T-green traced it at line-level). No SC-5-landed switcher-mounting field was reordered or deleted; the switcher render-array attach seam is in `DoShowcaseHooks::viewsPreRender()`, untouched here. Consider this AC satisfied via structural append; SC-5's `data-do-directory-variant` contract is preserved (JS/CSS uses the third value only, no changes to the attribute name/emitter).

### 5. HelpText

**Verified PASS — existing `showcase.switcher.directory.layout` copy already covers Map.** Grepped `HelpText.php:171`:

> "Compact list favors scanning many groups fast; Cards shows more per-group detail; Map plots groups geographically."

This copy was shipped by SC-F1 and explicitly names Map. No new entry needed; no new user-facing switcher surface was introduced by this story (same 3-option switcher, one flag flipped). AC "Ships with its HelpText entry (append-only) for any new user-facing surface" is satisfied by the already-shipped copy.

## Per-AC verdict table (issue #125 body)

| # | Issue AC | Verdict | Evidence |
|---|---|---|---|
| 1 | Map renders with the four seeded markers | PASS | U walkthrough: 4 `.leaflet-marker-icon` present in `.do-showcase-map`; `step_700_demo_data.php` Step 738 seeds coords for Portland/Paris/Brussels/Berlin. E2E "exactly 4 markers" GREEN post T-repair. |
| 2 | Clicking a marker links to the group | PASS | `plotMarkers()` binds `marker.on('click', () => window.location.assign(location.url))`; U confirmed programmatic click navigates to `/group/N`. Direct-click, no popup step (wireframe conforms). |
| 3 | Zero external network requests for map assets (no CDN hosts in page) | PASS | See "Special-attention item 1" above. Static grep + U's live network trace: 0 external requests. |
| 4 | Cards/list variants unaffected | PASS | `directory-toggle.spec.ts` + `directory-cards.spec.ts` 13 passed / 1 skipped / 0 failed live. F changed no existing switcher/cards/compact logic; new CSS is scoped under `[data-do-directory-variant="map"]`. |
| 5 | Groups without location behave sanely (don't plot) | PASS | `groupLocationAttributes()` returns NULL for groups with no `field_group_location`; `plotMarkers()` skips silently. Caption discloses the gap ("Showing N of M groups with a location"). |
| 6 | Existing suite stays green | PASS | Non-regression suites 13/14 GREEN live. Only intentional retirement: `directory-toggle.spec.ts`'s `?variant=map (unavailable) falls back` test, correctly deleted by T-green (contract retired, not regressed). |
| 7 | Playwright spec verifies markers + local-asset origin | PASS | `tests/e2e/directory-map.spec.ts` (new, 13 tests): explicit marker-count assertion, explicit `/libraries/leaflet/` URL assertion, explicit CDN-host forbidden-list assertion. |
| 8 | Ships with HelpText entry (append-only) for any new user-facing surface | PASS | See "Special-attention item 5" — existing copy already names Map. No new surface introduced. |
| 9 | WCAG 2.2 AA (labels, keyboard operability, visible focus, AA contrast, non-color status) | PASS (with advisory) | Map container has `aria-label="Map of groups with a location."`. Keyboard fallback: SR-only `ul.do-showcase-map-fallback-list.visually-hidden` with 4 real anchors, `:focus-within` reveals to visible box (1×1 → 764×150 confirmed live). Marker `title` attributes carry group name — city. Focus rings via shared `:focus-visible` token. **Advisory:** wireframe Surface 2 proposed a richer `aria-label` including "N groups shown. Use the list below to navigate to a group by keyboard." — implementation shipped the shorter form. Both meet AA; the richer form is a pre-PR nit (see below). Formal axe run not performed by U. |
| 10 | `composer.json` append-only (geofield already present; no `geofield_map` runtime attach) | PASS | See "Special-attention item 3" — zero diff on composer.json/lock. `do_showcase.info.yml` gains only `- geofield:geofield`, not `- geofield_map`. |
| 11 | Field addition is the epic's ONE conscious exception (`field_group_location`) | PASS | See "Special-attention item 2" — exactly one logical field added. |
| 12 | Merge-order append on `views.view.all_groups.yml` | PASS | See "Special-attention item 4" — appends only; no reorder/delete of SC-5's switcher-mounting fields. |
| 13 | Seed coords for Drupal France (Paris), Drupal Deutschland (Berlin), Camp Organizers EMEA (Brussels), DrupalCon Portland 2026 (Portland, OR) | PASS | `step_700_demo_data.php` Step 738: coords for the 4 named groups, plus idempotent city-text backfill (F caught the survey's incorrect assumption that `field_group_location_text` was already seeded — good defensive fix, in-scope since Step 738 already touches those exact 4 groups). |
| 14 | Switcher tooltip states the decision ("browse by place — for geographic community structures") | PASS (interpreted) | The `HelpText` copy shipped by SC-F1 says "Map plots groups geographically." — captures the same intent. Issue's exact quoted phrasing was descriptive/illustrative, not a copy mandate (T survey confirmed the existing copy is sufficient). No copy edit shipped. |

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API consistency | N/A | No new/changed HTTP endpoint. |
| Error handling | PASS | `groupLocationAttributes()` NULL-returns cleanly; `plotMarkers()` defensively handles 0 and 1 marker cases (avoids `LatLngBounds` exception on empty/singleton sets). |
| UI/UX match to spec | PASS | Every wireframe.md Surface 1/2/3 element present and observed live by U (except the richer aria-label — see AC #9 note). |
| Accessibility | PASS | Real keyboard fallback (not just aria-label decoration), :focus-within reveal, shared focus-ring token, marker titles for hover preview (D-gate Q1 approval). Advisory nit on aria-label richness. |
| Architecture gate | PASS | A returned PASS on the re-reviewed plan; F's implementation matches the plan (assemble-libraries.sh sibling script, bare `leaflet:` library + composed `directory-map:`, explicit `dependencies.module: [geofield]` on both field YAMLs, unconditional library attach mirroring `directory-compact` precedent). |
| Code organization | PASS | Single new hook TYPE on existing `DoShowcaseHooks` class (`preprocess_views_view_unformatted`), scoped identically to siblings via existing `isDirectoryView()` guard. `MutationObserver` deliberately scoped to a single attribute on a single element the behavior already references — flagged by F as a genuinely new client-side pattern, but the scoping is disciplined and correct. |
| Security | PASS | `?variant=map` flows through the SAME `resolveCurrent()` allowlist as `compact`/`cards`; no new user-input trust surface. Preprocess hook reads already-loaded entity from `$view->result[$index]->_entity`, no ad-hoc entity query. |
| Performance | PASS | JS aggregation bundles Leaflet with other JS (single request under CI/prod defaults). Preprocess hook is O(N rows); `groupLocationAttributes()` short-circuits on empty field. `map.invalidateSize()` only called on re-entry, not first construction. |
| Visual regression | N/A | Structural low-fi wireframe; no pixel reference to diff. U's live walkthrough covered the rendered structure. |
| Naming consistency | PASS | `field_group_location` mirrors sibling `field_group_location_text` naming. `do_showcase/leaflet` + `do_showcase/directory-map` mirror `do_showcase/directory-compact` naming. `.do-showcase-map` + `.do-showcase-map-fallback-list` mirror `.do-showcase-*` prefix convention already used across the module. |
| Test quality (test-quality.md §7) | PASS | 1 Unit + 3 Kernel + 13 E2E for a feature touching schema, hook, JS behavior, CSS, and vendored assets — proportionate, not padded. Each test names one behavior; tests sit at cheapest sufficient tier (Kernel for render-array, E2E for browser network + DOM + real click); zero assertion overlap between Kernel and E2E (Kernel asserts server-side `#attached` shape only, E2E asserts client DOM + network only). T-repair round 2 fixed 3 genuine test-authorship defects rather than papering over them. One test (`directory-toggle.spec.ts` map-unavailable fallback) correctly DELETED — the contract it pinned is being retired on purpose, and `directory-map.spec.ts` exhaustively covers the new positive contract — no coverage loss. |

## Scope check

**Scope match: exact.** Every "Owns (disjoint files)" item in the issue is present in the staged diff:
- composer.json / lock: no change (append-only satisfied vacuously — no new deps needed)
- Field config: both files present
- Vendored Leaflet: `docs/groups/libraries/leaflet/**` present (leaflet.js, leaflet.css, LICENSE, 3 marker PNGs) + library definition in `do_showcase.libraries.yml` (bare `leaflet:` entry)
- `views.view.all_groups.yml`: display appended
- Seed script: Step 738 appended
- `tests/e2e/directory-map.spec.ts`: new

No over-delivery: no new admin geocoding UI, no clustering, no custom marker icons, no live tile layer, no "add my location" — every non-goal from the brief is honored.

One acceptable in-scope addition beyond the literal issue list: the seed-data backfill of `field_group_location_text` for the 4 target groups. F caught that survey.md's "already seeded" assumption was wrong against the file's actual prior content, and fixed it inside a block (Step 738) that was already touching those 4 groups by label. Correct defensive fix — without it, marker `title` + fallback-list "Name — City" copy would render empty city, failing an E2E assertion for a reason unrelated to this story's map code.

## Cross-artifact truthfulness

- **Brief → implementation:** every "Owned files" entry in the brief is present with matching purpose in the staged diff.
- **Wireframe → implementation:** every Surface 1 state observed live by U; Surface 2 keyboard fallback shipped (visually-hidden + :focus-within); Surface 3 wrapper-attribute contract preserved (`.views-element-container[data-do-directory-variant="map"]` — scoped CSS + JS matches).
- **Handoffs → implementation:** F's handoff item-list matches actual `git status` output. T-red's expected RED failures → T-green's expected GREEN traces → U's actual live results are consistent chain. T-repair round 2 exactly and only fixed the 3 items U flagged.
- **Decisions journal:** all phases documented, including the O side-quest patching `assemble-config.sh` to include geofield in `ENABLE_MODULES` (fixes U's step-8 clean-room bring-up gap so CI won't hit it).

## Verdict

**PASS — ready for PR.**

Every acceptance criterion in issue #125 is satisfied. The critical zero-CDN constraint is verified by two independent methods (static grep + live network trace). The one field/schema addition is exactly one field. Composer.json is untouched. `views.view.all_groups.yml` is append-only. HelpText copy already covers Map (SC-F1 shipped it). Non-regression suites are GREEN live. WCAG 2.2 AA bar is met (real keyboard fallback, aria-label, focus-visible tokens, marker titles).

Recommend proceeding to PR. CI will re-run the full suite from a clean-room bring-up, which will exercise the assemble-config.sh geofield patch O shipped as part of Phase 8-fix.

## Pre-PR nits (non-blocking, cheap wins if fixed inline)

1. **aria-label richness.** Wireframe Surface 2 proposed:
   > "Map of groups with a location. 4 groups shown. Use the list below to navigate to a group by keyboard."
   Implementation ships:
   > "Map of groups with a location."
   Both meet AA. The wireframe form is friendlier to screen-reader users. Non-blocking; the count is already disclosed in the visible caption immediately above, and a screen reader will read that caption naturally when navigating past the map. If touched, single-string edit in the JS `ensureMapContainer()` helper.

2. **Missing `layers.png` / `layers-2x.png` in vendored Leaflet images.** `leaflet.css` references them for a control this story never renders. Harmless (unreachable selectors, no request issued). If a future story adds a layers control it will hit same-origin 404s until the two PNGs are added to `docs/groups/libraries/leaflet/images/`. Consider adding a one-line note to the `leaflet:` library docblock in `do_showcase.libraries.yml` to warn the next author.

3. **`directory-toggle.spec.ts` file-level docblock (lines 3-9)** still describes the switcher as having "Map (soon, unavailable)" — historical, not incorrect at SC-5 RED time, but stale now. T-green already flagged this as a documentation nicety, not a defect. One-line edit if touched.

None of these block the PR.
