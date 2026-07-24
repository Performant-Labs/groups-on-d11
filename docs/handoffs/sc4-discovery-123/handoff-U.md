# Handoff-U: Phase 8 UI Walkthrough - #123 SC-4 Discovery three ways

Date: 2026-07-23
Branch: 123-discovery-three-ways
Backend: Playwright (@playwright/test chromium, headless) driven from Node against a live DDEV-seeded site.

## Verdict

REWORK - one behavioral defect that a real user will hit on the very first interaction: clicking (or keyboard-activating) a discovery tab flips the switcher chrome (aria-checked, bullet glyph, roving tabindex=0) but does not swap the embedded view region and does not update the URL. The user sees the Hot tab marked selected while the list below still shows Recent. This diverges from wireframe.md contract (embedded view region swaps per active tab) and is the exact reason T criterion-6 Playwright tests time out on waitForURL matching discovery=hot.

Everything else - deep-link routing, tooltip, contrast, focus, keyboard-nav semantics - passes.

## Run environment

- Worktree: C:/Users/aange/Projects/_worktrees/groups-sc4-discovery-123
- DDEV project: gm123-discovery (drupal10 profile, PHP 8.4, mariadb 11.8)
- Site URL: http://gm123-discovery.ddev.site
- Install + seed (mirrors .github/workflows/test.yml E2E job):
  1. bash scripts/ci/assemble-config.sh (inside container).
  2. drush site:install standard --db-url=mysql://db:db@db:3306/db --account-pass=admin -y.
  3. Append the config_sync_directory setting pointing at ../config/sync to settings.php (drush install writes a random hashed sync dir first; a later assignment wins per PHP).
  4. drush config:import -y (imports the 3 target views + all other config).
  5. drush en -y do_showcase do_chrome and the other do_* modules.
  6. drush php:script the five seed scripts (step_700_demo_data, step_720_group_types, step_780_nav_menu, step_790_persona_switcher, step_7xx_backfill_activity) running as uid 1; drush cache:rebuild.
- Live-browser evidence via a throwaway .u-walk.mjs at repo root (deleted after the run) that logged in as admin/admin and drove the discovery region across default / click-Hot / click-Promoted / deep-Hot / deep-Promoted / Arrow-Right, plus 360px viewport.
- Authored spec run: BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts --reporter=list -> 9 passed, 2 failed (same defect, once via mouse click, once via keyboard Enter).

## Per-control checklist

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | GET /showcase (no query) | Section renders below catalog; Recent selected by default; embed chronological | H2 present with correct title and decision paragraph; three radios with Recent aria-checked=true; embed shows a recent Documentation post (Upgrading from Thunder 7 to 8, 2 minutes 37 seconds ago) | PASS |
| 2 | GET /showcase?discovery=hot | Hot pre-selected; embed shows hot_content view | Hot aria-checked=true; embed differs from Recent - includes Thunder 7 Roadmap Discussion with Follow-content controls | PASS |
| 3 | GET /showcase?discovery=promoted | Promoted pre-selected; both required seeded titles present | Both required seeded titles (Getting Started with Paragraphs, Community Code of Conduct) visible; screenshot showcase-promoted.png confirms. Note: promoted_content view lists all published nodes not only promote_homepage-flagged - pre-existing latent gap flagged by T, out of scope per A-plan Risk 3 (embed AS-IS). | PASS |
| 4 | Click Hot radio (mouse) | URL becomes ?discovery=hot; aria-checked flips; embed re-renders | URL stays /showcase (no history push, no navigation); aria-checked flips to Hot with bullet-Hot label; EMBED REGION UNCHANGED - still Recent content (innerText byte-identical to pre-click) | FAIL |
| 5 | Click Promoted radio (mouse) | Same as #4 for Promoted | Same failure: aria-checked flips, URL unchanged, embed still Recent | FAIL |
| 6 | Focus Recent, press Arrow-Right | Selection moves to Hot; embed swaps | Selection/tabindex move to Hot correctly; embed and URL unchanged - same bug via keyboard | FAIL |
| 7 | Focus Recent, press Enter (via authored spec) | Same as #4 | waitForURL matching discovery=hot times out at 20s - same root cause | FAIL (discovery-compare.spec.ts:214) |
| 8 | Wrapper tooltip exactly once on discovery.ranking switcher | Exactly one data-do-tooltip on the wrapper (POC, not per-option) | count() = 1. aria-label and data-do-tooltip both carry the HelpText copy | PASS |
| 9 | directory.layout switcher coexists with own ?variant= key | Both switchers render independently; ?variant=cards does not affect discovery state | showcase-default.png shows top switcher with bullet-Cards selected simultaneously with lower discovery switcher unaffected. Two DISTINCT query keys on anchor hrefs (?variant=cards upper, ?discovery=recent lower). | PASS |
| 10 | Focus ring visible on radio (WCAG 2.4.7 / criterion 8) | Visible focus indicator | Computed style on focus: outline rgb(77,163,255) solid 2px - distinct 2px solid blue ring, clearly visible on white (~5:1 contrast). Confirmed via focus-recent.png. | PASS |
| 11 | Color contrast (WCAG 1.4.3 / criterion 8) | >= 4.5:1 | H2 rgb(11,13,15) on white (~19:1); body p rgb(43,53,59) on white (~13:1); tooltip trigger rgb(43,53,59) on white (~13:1); POC ribbon rgb(255,255,255) on rgb(26,26,26) (~15:1). All comfortably AA. | PASS |
| 12 | Non-color status conveyed | Selected marked with bullet glyph plus aria-checked=true, not color alone | Confirmed for every selected state; text label prepended with bullet for the selected option. | PASS |
| 13 | 360px mobile viewport | Legible, operable, no clipped layout | showcase-360.png renders section stacked with switcher pills wrapping cleanly; no horizontal overflow; H2 and copy fully visible. | PASS |
| 14 | Console errors / pageerrors during walk | None | consoleErrors = [] across the full walk. Zero pageerrors. | PASS |
| 15 | /hot standalone route non-regression | Unchanged | Authored spec /hot standalone page is unaffected passed live (1.5s) | PASS |
| 16 | directory.layout stub still functions | Unchanged | Authored spec directory.layout stub switcher on /showcase still renders unaffected passed live (464ms) | PASS |

## Findings

### F-U-1 (blocking) - Clicking a discovery tab updates the switcher chrome but not the embedded view or the URL.

Evidence.

- Before click: URL = /showcase; embed innerText begins with a Recent post (Upgrading from Thunder 7 to 8, 2 minutes 37 seconds ago).
- After clicking Hot: URL = /showcase (unchanged); embed innerText byte-identical to pre-click; only radio state changes - Hot aria-checked=true, tabindex=0, label prepended with bullet.
- After clicking Promoted: same pattern.
- Deep links ?discovery=hot and ?discovery=promoted DO render the correct different content, proving the server side works. Gap is purely client-side.
- Root cause in code (readable, not speculation): docs/groups/modules/do_showcase/js/do_showcase.switcher.js click handler calls event.preventDefault() then delegates to a local select(id, persist) that only mutates aria-checked, the label glyph, roving tabindex, sessionStorage, and the optional mirror-attribute. There is NO history.pushState, no fetch/reload of the region, no views_embed_view re-request. The anchor (href=?discovery=hot, the correct no-JS fallback) is thrown away by preventDefault. Same defect present in the keydown Enter/Space branch.
- Authored Playwright spec fails on this same behavior:
  - clicking each tab updates the URL to ?discovery=<id> and changes the embedded content - TimeoutError at page.waitForURL (line 122).
  - WCAG-adjacent smoke: the discovery.ranking switcher is keyboard-operable - same TimeoutError at line 214 (Enter path).
  - Full run: 9 passed, 2 failed.

Impact. A user clicking a tab sees the switcher say bullet-Hot while the list beneath is still Recent - a lie about state. Also means share-this-URL-to-show-a-friend-the-Hot-tab does not work from an interactive session (only from a hand-typed / server-rendered deep-link).

Wireframe conformance. wireframe.md lines 33-37 explicitly show embedded view region swaps per active tab and lines 139-141 state Enter/Space or click activates -> navigates to ?discovery=<id> (no-JS fallback link) or, with do_showcase/switcher JS progressive enhancement, swaps in place (existing framework behavior, unchanged). Current JS does neither.

Why headless / Functional tests missed it. T DiscoveryRankingControllerTest uses BrowserTestBase (no JS), so it only exercises the server-side deep-link path, which works. The Unit tests pin markup and href shape but do not exercise the JS. This is exactly the interactive-swap failure class U is meant to catch, and the reason criterion 6 was explicitly deferred to this phase.

Not fixed here (U reports, does not fix). Two candidate fix shapes for F/D:
1. Let the anchor navigate - drop event.preventDefault() in the click handler (and equivalent in the Enter/Space keydown branch) so the browser follows the anchor href, producing a real navigation with correct server-rendered content. Simplest fix; loses instant feel. Note: JS is shared with the pre-existing directory.layout switcher - whichever fix F chooses must not regress its behavior on /showcase or /all-groups (T directory-toggle.spec.ts and criterion-16 non-regression above must stay green).
2. Push URL + partial swap - history.pushState in select(), then either location.reload() or fetch /showcase?discovery=<id> and swap .views-element-container inside [data-do-discovery-ranking]. Preserves instant feel but needs either a full-page fetch or a partial endpoint.

Because there is no existing HTMX / fetch-swap primitive in this codebase (grepped: no hx-* attributes, no fetch()-based partial swap helper in do_showcase or do_chrome), path 1 is the minimum-surface fix and matches the no-JS-fallback-stays-authoritative comment already in the JS file.

### Non-findings (verified, not defects)

- Promoted view shows all published nodes, not just promote_homepage-flagged ones. Pre-existing latent gap in views.view.promoted_content.yml, surfaced by T in handoff-T-red.md, out of scope per A-plan Risk 3 (embed AS-IS). Both required seeded titles present; acceptance criterion as written is satisfied.
- Wireframe per-tab meta styling (bullet-hot glyph on top row, [promoted] badge per row, created-X-ago meta line) not visible because story reused existing views AS-IS rather than restyling. Honestly captured in the A-plan; not a U defect.
- Top-of-page Skip-to-main-content link has an invisible white-on-white focus outline. Pre-existing site-wide theme concern, unrelated to this story.

## Handoff to next role

REWORK. F must fix F-U-1: the discovery.ranking switcher JS click/Enter/Space handler must produce a URL change AND a corresponding content change - either by removing preventDefault() (path 1) or by adding history.pushState + swap (path 2). Because the JS is shared with the pre-existing directory.layout switcher (same do_showcase/switcher library), F must confirm directory-toggle.spec.ts and the criterion-16 non-regression above stay green.

After F fix, re-run BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts - all 11 tests must pass; then T re-runs and U re-verifies.

Screenshots (evidence):
- C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/showcase-default.png
- C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/showcase-hot.png (deep link)
- C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/showcase-promoted.png (deep link)
- C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/showcase-360.png (mobile)
- C:/Users/aange/AppData/Local/Temp/claude/C--Users-aange-Projects-groups-on-d11/3f8c6656-8990-47c8-9917-3ecdcd64c1ce/scratchpad/focus-recent.png (focus ring)
