# Handoff-U: Phase 8 - #142 MC-3 Directory Location + Primary-language Filters

**Date:** 2026-07-23
**Branch:** 142-directory-filters
**Issue:** #142
**Handoff-T-green reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-T-green.md`
**Handoff-F reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-F.md`

## Run environment

- Driven against the already-running namespaced DDEV site `gm142-directory-filters`
  (`https://gm142-directory-filters.ddev.site`), mirroring T's Phase 6 GREEN setup - same
  worktree, same assembled config.
- `ddev exec bash scripts/ci/assemble-config.sh` re-run before the walkthrough (101 config
  files, 13 custom modules - matches F/T's runs, exit 0).
- Confirmed config already imported and DB already seeded (this worktree's DDEV instance
  carried state from T's Phase 6 pass): `views.view.all_groups` exposed-filter config
  inspected via `ddev drush config:get` shows `location` (`plugin_id: string`, field key
  `field_group_location_text_value`) and `field_group_language` (`plugin_id: language`,
  field key `field_group_language_value`) exactly as F shipped. `ddev drush sql:query`
  confirmed the 3 canonical seed groups present: `Filter Test Berlin English`,
  `Filter Test Paris French`, `Filter Test Berlin French`.
- Driven via a throwaway Playwright script (deleted after the run, not committed) using
  `@playwright/test`'s `chromium.launch()` directly and `@axe-core/playwright` (installed
  `--no-save` for this pass only; not added to `package.json`). No HTMX/SPA nav applies to
  this surface (standard Drupal Views exposed-form GET-submit + full page render) -
  confirmed no `hx-*` attributes anywhere on `/all-groups`.
- Screenshots stored under `docs/planning/handoffs/142-directory-filters/screenshots/`.

## Walkthrough checklist

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | Load `/all-groups` | Page renders; Search groups, Location, Primary language all visible; sensible tab order | All three controls visible. Tab order: Search groups (focused) -> Tab -> location input (confirmed via active-element id). Screenshot `01-initial-load-desktop.png` shows clean stacked layout, no overlap | PASS |
| 2 | Fill Location = "Berlin", click Filter | URL gets location=Berlin; results narrow to the 2 Berlin seed groups | Query string became `search=&location=Berlin&field_group_language=All`; cards = Filter Test Berlin English, Filter Test Berlin French only (Paris excluded). Screenshot `02-location-berlin.png` | PASS |
| 3 | Reload, select Language = French, click Filter | URL gets field_group_language=fr; only French-language groups shown | Query string became `search=&location=&field_group_language=fr`; cards = Filter Test Berlin French, Filter Test Paris French only (English excluded). Screenshot `03-language-french.png` | PASS |
| 4 | Reload, Location=Berlin + Language=fr, click Filter | Exactly 1 result - the Berlin+French intersection | Exactly 1 card: Filter Test Berlin French. Screenshot `04-combined-berlin-french.png` confirms clean single-card render with both filter values retained in the form | PASS |
| 5 | Click Reset | Form clears; full directory returns | location param removed from URL; location input value empty; all 3 seed groups + all pre-existing demo groups (Drupal Deutschland, Camp Organizers EMEA, Leadership Council, Thunder Distribution, Core Committers, Drupal France, DrupalCon Portland 2026) reappear. Screenshot `05-after-reset.png` | PASS |
| 6 | Access-safety: anonymous view of unfiltered directory | No archived/unlisted/private groups appear | Card-text scan for the known archived seed group "Legacy Infrastructure" (referenced in F's Tier-1 self-check) returned 0 matches. Consistent with kernel test testAnonymousExecutionExcludesArchivedGroup already pinning this at Phase 6 | PASS |
| 7 | WCAG 2.2 AA axe scan, full `/all-groups` page | No criticals/serious on the new filter form | Full-page scan found 1 serious finding: color-contrast on `.gc-badge--success` (the "Open" visibility badge), 10 node instances across directory cards. Not on this story's new surface - traced to `web/themes/custom/groups_chrome/css/primitives.css:39`, last touched by story #84 and #121/#122, unrelated to the new filter controls. A second axe scan scoped to just the exposed-filter form returned 0 violations of any severity. Focus-ring check: both new controls show a 6px double outline on focus (Olivero default), no CSS suppression | PASS (new surface clean; pre-existing badge contrast issue flagged as advisory) |
| 8 | Visual sanity, desktop + 360px mobile | No layout regressions on filter form or cards | Desktop: form and cards render cleanly stacked, Filter/Reset buttons inline, no overlap. Mobile 360px: all three controls stack full-width, remain visible and labeled, cards reflow to single column, no horizontal scroll/clipping. Screenshot `06-mobile-360.png` | PASS |

## Console / runtime errors

Console and page-error listeners were attached across the entire walkthrough (all 6
navigations): zero console errors, zero page errors observed.

## SPA-nav note

N/A for this story - `/all-groups` is a standard Drupal Views page with a GET-submitted
exposed-filter form (full page reload on Filter/Reset), not an HTMX/SPA swap surface.
Verified no `hx-*` attributes present anywhere in the rendered page. The "reach every page
via SPA nav" rule does not apply here per the project override; a hard page load is the
real user path for this surface.

## Wireframe conformance

Brief's D phase is explicitly N/A ("no new UI surface") - confirmed no wireframe exists to
compare against (also independently confirmed by F's handoff "Deviations from spec/
wireframe" section). Both new controls are added inline in the existing exposed-filter form
using the same visual pattern as the pre-existing Search groups control (same label style,
same input sizing, same Filter/Reset button row) - no divergent pattern introduced.

## Findings

Advisory only (not blocking this story): color-contrast (serious, axe) on
`.gc-badge--success` (the green "Open" visibility badge shown on every directory card).
Pre-existing since story #84; not touched or introduced by #142. Flagged for a future
story/backlog item, not a rework item here - the new filter-form surface itself scans clean
(0 axe violations when scoped to the form).

No behavioral defects found. All filter/combination/reset/access-safety behavior matches the
brief and T's GREEN kernel + e2e results exactly.

## Verdict

PASS

## Evidence

- `docs/planning/handoffs/142-directory-filters/screenshots/01-initial-load-desktop.png`
- `docs/planning/handoffs/142-directory-filters/screenshots/02-location-berlin.png`
- `docs/planning/handoffs/142-directory-filters/screenshots/03-language-french.png`
- `docs/planning/handoffs/142-directory-filters/screenshots/04-combined-berlin-french.png`
- `docs/planning/handoffs/142-directory-filters/screenshots/05-after-reset.png`
- `docs/planning/handoffs/142-directory-filters/screenshots/06-mobile-360.png`
- Raw walkthrough output (URLs, card contents, axe results, console errors) captured
  inline in the checklist above; throwaway driver scripts used to drive the walkthrough
  were deleted after the run per protocol, not committed.

Ready for S (Spec Auditor) - the new filter-form surface is behaviorally sound and
axe-clean; S owns the final visual/WCAG verdict including a call on whether the
pre-existing `.gc-badge--success` contrast finding warrants a backlog note.
