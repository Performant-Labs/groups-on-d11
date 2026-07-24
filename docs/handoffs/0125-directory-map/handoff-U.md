# Handoff-U: Phase 8 - #125 SC-6 Directory map view

**Date:** 2026-07-24
**Branch:** 125-directory-map
**Worktree:** C:/Users/aange/Projects/_worktrees/groups-sc6-map-125
**DDEV project:** gm125-map (renamed from stale gm129-activity; router ports 8125/8144)
**Base URL under test:** http://gm125-map.ddev.site:8125

## Bring-up log

1. Edited .ddev/config.yaml: name gm125-map, router_http_port 8125, router_https_port 8144.
2. bash scripts/ci/assemble-libraries.sh -> OK (web/libraries/leaflet/ populated: leaflet.js, leaflet.css, LICENSE, images/).
3. ddev start -> OK.
4. ddev composer install -> OK.
5. ddev exec bash scripts/ci/assemble-config.sh -> OK (host has no php; must run inside DDEV).
6. ddev exec bash scripts/ci/assemble-libraries.sh -> OK.
7. drush site:install standard (via ddev exec, DB url mysql://db:db@db:3306/db) -> OK.
8. **Hiccup #1:** drush config:import failed because geofield was not listed in config/sync/core.extension.yml. assemble-config.sh only registers do_* modules + flag/language/message/message_notify; it does NOT register contrib deps that new modules require. Fixed by inserting geofield: 0 into config/sync/core.extension.yml (alphabetical). Worth capturing as an assemble-config.sh improvement; flagged for orchestrator, not blocking.
9. drush en geofield + drush config:import -y -> OK.
10. drush en for all do_* modules + admin password reset + pmu/en do_activity_feed cycle + seed scripts (step_700, step_720, step_780, step_790, step_7xx_backfill_activity, step_795), drush cron, drush cache:rebuild -y -> all OK.
11. Verified via php:script: 4 groups have field_group_location set (Portland OR, Paris, Brussels, Berlin) with matching field_group_location_text. Step 738 idempotency ran clean.

## Manual walkthrough - wireframe.md Surface 1 (LIVE, headless Chromium)

| Check | Expected | Observed | Verdict |
|---|---|---|---|
| Switcher options on /all-groups | Compact list, Cards, Map (Map live) | 3 radios; Map aria-checked=false, tabindex=-1, no (soon) label | PASS |
| ?variant=map sets wrapper attr | data-do-directory-variant=map | Confirmed | PASS |
| .do-showcase-map container appears | Visible, contains Leaflet DOM | div.do-showcase-map.leaflet-container present, tabindex=0 | PASS |
| .view-content hidden in map mode | display: none | computed display === none | PASS |
| Pager hidden in map mode | Not visible | Confirmed hidden | PASS |
| Exactly 4 markers plot | 4 .leaflet-marker-icon inside .do-showcase-map | 4 markers | PASS |
| Truthful caption text | wireframe permits both N==M and N<M forms | Showing 4 of 11 groups with a location. (11 total, 4 with coords) | PASS (see caption note) |
| Filters remain visible above map | Filter form present | Present | PASS |
| Marker click navigates to group canonical | URL matches /group/N | Programmatic click navigates to /group/1 | PASS |
| Fallback list has 4 Name-City links | 4 li>a items | 4 items: DrupalCon Portland 2026 - Portland OR; Drupal Deutschland - Berlin; Camp Organizers EMEA - Brussels; Drupal France - Paris | PASS |
| Fallback list visually-hidden default, reveals on focus-within | 1x1 collapsed, expands after focus | Hidden box 1x1; after focus 764x150 | PASS |
| Map aria-label names region | e.g. Map of groups with a location | Map of groups with a location. | PASS (see aria-label note) |
| Marker native title attribute (D-gate approved) | Group name - city | All 4 markers carry title matching fallback text | PASS |
| Toggle Map -> Cards -> Map | Map hides, row grid returns; then re-shows with 4 markers | variant transitions confirmed; .view-content display=grid on Cards; .do-showcase-map display=none on Cards; back to Map still has 4 markers | PASS |
| Zero console/page errors during walkthrough | 0 | 0 errors | PASS |

**Caption note:** the fully-seeded dataset has 11 groups total (persona seed step_790 adds beyond the 4 with coordinates). The wireframe N==M special case only applies when every group has a location; with 4-of-11 the two-number form is what the caption renders - exactly the N<M wording the wireframe itself specifies. Correct behavior. It nevertheless causes one authored E2E test (which pins the N==M wording) to fail; flagged below as a test-vs-seeded-reality mismatch, not an implementation bug.

**aria-label note:** wireframe.md Surface 2 proposed a richer label (Map of groups with a location. N groups shown. Use the list below to navigate to a group by keyboard.), but the implementation ships the shorter Map of groups with a location. Still names the region and its purpose per WCAG 2.2 AA; the count is disclosed in the visible caption immediately above. Non-blocking for POC; S may consider enriching before merge.

## Zero-CDN network check (critical AC)

Live Playwright trace of /all-groups?variant=map page load + 3s settle:
- Total requests: 11
- External requests (non-gm125-map.ddev.site, non-localhost, non-127.0.0.1): 0
- CDN/tile-host hits (unpkg.com, cdnjs.cloudflare.com, tile.openstreetmap.org, tile.osm.org, mapbox.com, googleapis.com): 0
- Leaflet asset origin: http://gm125-map.ddev.site:8125/libraries/leaflet/images/marker-icon.png and .../marker-shadow.png. Leaflet JS + CSS come from Drupal aggregated asset bundle (see failure #3 below).

**Verdict: PASS.** Zero-CDN AC fully satisfied.

## Live Playwright suite runs

### tests/e2e/directory-map.spec.ts

**Result:** 10 passed, 3 failed (13 tests total).

**Failures - all three are test-authorship issues, NOT implementation bugs:**

1. **when Map is the selected variant, it carries the roving tabindex tabindex=0** (line 149). Test uses switcher.getByRole(radio, name: /^Map$/i) after ?variant=map. But the selected option rendered label is (bullet) Map with a leading bullet glyph SC-F1 shipped as the selection indicator, so Playwright accessible name becomes (bullet) Map, which does not match /^Map$/i. The DOM element IS correctly aria-checked=true tabindex=0 (verified via direct inspection). Fix for T: switch to switcher.locator([data-do-showcase-id=map]) which matches regardless of selection glyph (SC-F1 shipped that data attribute for exactly this reason), or relax the regex. Not F.

2. **the caption states the truthful count Showing 4 groups with a location.** (line 198). Test pins the N==M form; live seeded dataset gives N<M form Showing 4 of 11 groups with a location. Both are correct wireframe forms. Fix for T: loosen the assertion to a regex that permits both forms, or make it seed-count-aware.

3. **zero external network requests during the map page load, every Leaflet asset comes from /libraries/leaflet/** (line 266). Forbidden-request assertion (the real AC) passes (0 offenders). The positive assertion expect(leafletRequests.some((u) => /leaflet.js/i.test(u))).toBe(true) fails because Drupal default JS aggregation combines leaflet.js into /sites/default/files/js/js_ib_...js. The bundle content DOES contain the Leaflet code (grepping doShowcaseDirectoryMap, leaflet, invalidateSize inside the aggregated bundle; all present). Only the marker PNGs remain at /libraries/leaflet/images/. Would pass on drush runserver (CI, aggregation off by default). Fix for T: search bundle content instead of URL, disable aggregation in the test fixture (drush config:set system.performance css.preprocess 0 / js.preprocess 0), or relax to any-request-under-/libraries/leaflet/-was-made (marker PNGs already qualify).

All three failures are the same category T-green Advisory notes flagged; this is the first-ever live execution of the suite. None indicate an F defect; the underlying behavior is correct in every case (confirmed by direct DOM/network inspection).

### tests/e2e/directory-toggle.spec.ts + tests/e2e/directory-cards.spec.ts

**Result:** 13 passed, 1 skipped, 0 failed (14 tests total). **Non-regression PASS.**

## WCAG 2.2 AA spot check

- Map container aria-label: Map of groups with a location. Present, names the region. Wireframe suggested count/navigation hint not present; count is disclosed in visible caption immediately above. Meets AA region-has-accessible-name bar.
- Keyboard fallback: SR-only ul.do-showcase-map-fallback-list.visually-hidden with 4 real anchor elements linking to canonical group pages. Reveals visually on :focus-within (1x1 -> 764x150 confirmed); sighted keyboard-only users see focus target when they Tab in.
- Marker title attribute: all 4 markers carry title=Name-City, per D-gate Q1 approval.
- Focus rings: map container is tabindex=0 and picks up Leaflet default outline; fallback-list anchors get the codebase shared :focus-visible outline.
- Caption contrast: default body text on default background; not measured pixel-precisely; visually normal body-text contrast, no obvious violation. S should confirm formally with axe if that rigor is required.

## Verdict

**GREEN - advance to S.**

- Every user-facing AC in brief.md is behaviorally satisfied in a real browser against a real seeded site: Map option live, ?variant=map renders 4 markers over a plain-grey Leaflet canvas with **0 external network requests**, marker click navigates to canonical group page, SR-only fallback list ships and reveals on focus, live client-side Cards<->Map round-trip works (invalidateSize on re-entry; 4 markers still visible), Cards+Compact non-regression fully GREEN.
- The 3 directory-map.spec.ts failures are all test-authorship issues (label-glyph mismatch, seed-count-vs-pinned-wording, JS-aggregation-vs-raw-URL). None reflect an F defect. They will need T repair before this story CI can go green, but that is a T concern, not a reason to send F back through F/T/U again.
- Advisory for orchestrator: assemble-config.sh should learn about geofield (added by this story) so future clean-room bring-ups do not need the manual core.extension.yml patch I applied in step 8. Not blocking.

## Evidence

- Handoff written from live-run observations against http://gm125-map.ddev.site:8125 on 2026-07-24.
- Playwright transcripts under test-results/ in this worktree.
- Temp scripts (check-groups.php, .u-*.mjs, import-config.sh, seed-run.sh) cleaned up per U protocol.
