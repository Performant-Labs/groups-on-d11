# Brief — #125 SC-6 Directory map view

**Review rigor:** none (per issue body).
**Story type:** UI feature — full pipeline (D → A → T(red) → F → T(green) → U → S).

## Objective
Add a live third variant — **Map** — to the `/all-groups` directory switcher (existing options: Cards,
Compact list). Groups with a geographic location plot as clickable Leaflet markers; clicking a marker
navigates to that group's page. All Leaflet assets served locally (no CDN). Four seeded groups gain
coordinates: Paris, Berlin, Brussels, Portland (OR).

## Acceptance criteria (from issue #125, POC bar)

- [ ] `?variant=map` on `/all-groups` renders a Leaflet map with 4 markers (Portland, Paris, Brussels, Berlin), replacing the Cards/Compact list output in that mode.
- [ ] Clicking a marker navigates to the group's page (canonical group URL).
- [ ] **Zero external network requests** for map assets. Assert no CDN hosts (unpkg.com, cdnjs.cloudflare.com, tile.openstreetmap.org, *.mapbox.com, etc.) appear in the page's network log. Tiles: **since even OpenStreetMap tiles hit an external host, tiles will NOT be loaded from the network in POC scope — the map renders with markers over a plain grey/pattern background, or we bundle a minimal `TileLayer` implementation that draws a CSS grid.** (See §Design note.)
- [ ] Cards + Compact list variants render identically to pre-story (no regression on `directory-cards.spec.ts` / `directory-toggle.spec.ts`).
- [ ] Groups without `field_group_location` don't plot (map variant caption/aria label indicates "showing groups with a location").
- [ ] `directory-map.spec.ts` verifies marker count, marker click → navigation, and zero-CDN network origin.
- [ ] HelpText entry already ships (SC-F1) — no addition required. (Confirmed in survey.)
- [ ] WCAG 2.2 AA: map container has an `aria-label`, keyboard-navigable marker list (fallback for screen readers), visible focus.
- [ ] `composer.json` append-only (geofield already present; no `geofield_map` runtime attach).

### Design note — tiles
The issue's "zero external network requests for map assets" acceptance criterion **rules out live OSM
tiles** (they are external). Two options, resolved by Coordinator default ("no clustering, no custom
icons — POC scope"):
- **Chosen:** render the map with **no tile layer** — a solid background inside `.leaflet-container`
  (light grey), Leaflet's default marker sprites (vendored) plotted at correct lat/lng using Leaflet's
  built-in Mercator projection. This is POC-honest: it demonstrates geospatial plotting without smuggling
  in external tile requests, and it makes the "zero CDN" assertion trivially provable.
- (Rejected: bundling raster tile assets locally — huge asset footprint, out of POC scope.)

## Input documents
- Issue: [#125](https://github.com/Performant-Labs/groups-on-d11/issues/125)
- Survey: `docs/handoffs/0125-directory-map/survey.md`
- Wireframe: to be produced by D (Phase 2) — mode (a) new wireframe, since the issue has no prior visual mock.

## Handoff locations
- `docs/handoffs/0125-directory-map/wireframe.md` (D)
- `docs/handoffs/0125-directory-map/handoff-A-plan.md` (A up-front)
- `docs/handoffs/0125-directory-map/handoff-T-red.md` (T red)
- `docs/handoffs/0125-directory-map/handoff-F.md` (F)
- `docs/handoffs/0125-directory-map/handoff-T-green.md` (T green)
- `docs/handoffs/0125-directory-map/handoff-U.md` (U)
- `docs/handoffs/0125-directory-map/handoff-S.md` (S)
- `docs/handoffs/0125-directory-map/decisions.md` (all phases append)

## Branch & PR
- Branch: `125-directory-map` (already created off `origin/main`)
- PR target: `main` — self-merge on CI-green + mergeable (POC lean pipeline standing rule)

## Owned files (disjoint — per issue "Owns" list, with survey refinements)
- `composer.json` / `composer.lock`: **no change** (geofield already required; no new deps)
- `docs/groups/modules/do_showcase/do_showcase.info.yml`: append `geofield` under `dependencies`
- `docs/groups/config/field.storage.group.field_group_location.yml` (new)
- `docs/groups/config/field.field.group.community_group.field_group_location.yml` (new)
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php`: flip `map` availability
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`: attach `do_showcase/directory-map`; preprocess row wrapper with `data-do-location-lat/lng`
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml`: append TWO library entries — (a) `leaflet:` pointing at `/libraries/leaflet/leaflet.js` + `.css` (bare vendor entry, reusable by any future map consumer), and (b) `directory-map:` (this story's JS/CSS) declaring `- do_showcase/leaflet` + `- do_showcase/switcher` as dependencies
- `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` (new)
- `docs/groups/modules/do_showcase/css/directory-map.css` (new)
- `docs/groups/libraries/leaflet/**` (new — SOURCE tree; Leaflet 1.9.4 assets: leaflet.js, leaflet.css, images/marker-icon.png, images/marker-icon-2x.png, images/marker-shadow.png)
- `web/libraries/leaflet/**` (gitignored assembly TARGET — populated at build/CI time by scripts/ci/assemble-libraries.sh; NEVER committed)
- `scripts/ci/assemble-libraries.sh` (new — sibling of assemble-config.sh; copies docs/groups/libraries/ into web/libraries/)
- `.github/workflows/test.yml`: add `bash scripts/ci/assemble-libraries.sh` call after every existing `bash scripts/ci/assemble-config.sh` invocation (three call sites per handoff-A finding #1)
- `docs/groups/config/views.view.all_groups.yml`: **append a field only** (geofield rendered as latitude/longitude); dependencies list gains `field.storage.group.field_group_location`
- `docs/groups/scripts/step_700_demo_data.php`: append coordinate seed for 4 groups
- `tests/e2e/directory-map.spec.ts` (new)

## Merge-order dependencies
- SC-F1 (switcher framework): **already merged** (#119 / VariantSwitcher).
- SC-5 (Cards+Compact toggle): **already merged** (#124 — commit c89bf40 base). The `map` entry in `directoryLayoutOptionIds()` is already reserved.

## Non-goals
- No custom marker icons (default Leaflet marker sprites).
- No marker clustering.
- No live tile layer (see design note).
- No "add my location" UX; no map-side filtering beyond what the switcher does.
- No geocoding — coordinates are seeded directly.
