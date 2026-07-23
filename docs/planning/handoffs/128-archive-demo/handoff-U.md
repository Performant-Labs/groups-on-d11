# Handoff-U: Phase 8 - #128 SD-3 Archive Demonstrator Seeds

**Date:** 2026-07-23
**Branch:** 128-archive-demo
**Issue:** #128
**Handoffs reviewed:** brief.md, handoff-T-red.md, handoff-F.md, handoff-T-green.md

## Verdict: PASS

Drove the live seeded DDEV site (http://gm128-archive-demo.ddev.site) headlessly with
Playwright, anonymous persona for AC-1a/1b/AC-2 and authenticated non-Organizer
(elena_garcia) for AC-1c, at desktop (1280x900) and mobile (360x800) viewports.
Every acceptance criterion T/F/T-green already proved via curl/status-code assertions is
confirmed here via actual DOM/visual rendering. No console errors, no layout breakage, no
new findings. One pre-existing gap (already flagged by T/F, out of #128's scope) is
reconfirmed as advisory-not-block.

## Environment

- Site already running: http://gm128-archive-demo.ddev.site (DDEV, left in post-fix
  state by T-green). Verified up via curl status-code check on /all-groups -- 200.
- Group IDs resolved live via drush php:eval: Legacy Infrastructure gid = 8
  (status=1, confirming F fix is live), Core Committers gid = 3 (control group).
- Drive method: standalone Playwright script (chromium, headless) run from the worktree
  root so node_modules/playwright resolved; no u-drive.mjs helper exists in this repo
  (correctly -- this is Drupal/DDEV, not the HTMX Language-Buddy stack the generic U
  contract's fast path targets, per the project override). Console error/pageerror
  listeners attached on every page/context used.

## Per-AC walkthrough

### AC-1a -- Discovery (anonymous, /all-groups)

| Check | Expected | Observed | Result |
|---|---|---|---|
| Page load | 200 | 200 | PASS |
| .gc-directory-card for Legacy Infrastructure visible | visible | visible | PASS |
| .gc-directory-card__type badge text | Archive | Archive | PASS |
| Card link accessible name | non-empty, descriptive | Legacy Infrastructure | PASS |
| Card href | /group/gid | /group/8 | PASS |
| data-do-tooltip anywhere on the card | n/a (documented gap) | absent | Advisory, not blocking (see below) |

Screenshot: screenshots/all-groups-desktop.png (all 8 groups render, Legacy
Infrastructure card visible bottom-right with Archive / Open / 1 member badges).
Mobile: screenshots/all-groups-360.png (single-column stack, no overlap/clipping,
Legacy Infrastructure card fully visible at bottom).

Advisory (not a blocker, pre-existing, out of #128 scope): confirmed T/F's finding --
the /all-groups card renders only the plain .gc-directory-card__type Archive label,
no data-do-tooltip attribute anywhere in the card markup. This is because the all_groups
View renders rows via Views fields, which never invokes hook_preprocess_group (where the
tooltip is attached). This is documented in the brief's non-goals (no theme/template
changes) and in T-RED/T-green's handoffs as a follow-up-ticket candidate, not a #128 defect.
Recording as advisory-not-block per the task instructions.

### AC-1b -- Group page badge + tooltip (/group/8)

| Check | Expected | Observed | Result |
|---|---|---|---|
| Click-through lands on | /group/gid | /group/8 | PASS |
| span.group__archived-badge visible | visible | visible | PASS |
| Badge text | contains Archived | ARCHIVED (visually uppercased via CSS; DOM text Archived) | PASS |
| data-do-tooltip attribute | truthy | "This group is archived: read-only. Everything stays visible for reference, but no new content can be posted here." | PASS |
| Hover shows tooltip | tooltip renders | confirmed visually (screenshot shows tooltip bubble open over badge) | PASS |

Screenshot: screenshots/group-legacy-infrastructure-desktop.png -- tooltip bubble captured
open, reading the full copy above the ARCHIVED badge. The page also renders a "Who can
do what" permission matrix (pre-existing do_chrome component) showing "Post content"
denied for every visitor type on this group -- independent visual corroboration of AC-1c's
enforcement. Mobile: screenshots/group-legacy-infrastructure-360.png, badge and matrix
render correctly stacked, no clipping.

### AC-1c -- Read-only enforcement (authenticated non-Organizer, elena_garcia)

| Check | Expected | Observed | Result |
|---|---|---|---|
| Login as elena_garcia/demo_password_2026 | succeeds | succeeded (no "Unrecognized username or password") | PASS |
| /group/8/content/create/group_node forum (Legacy Infrastructure, archived) | 403 | 403, "Access denied" / "You are not authorized to access this page." rendered | PASS |
| /group/3/content/create/group_node forum (Core Committers, control, same user) | 200 | 200, real "Add Group node (Forum)" form rendered with "Post to groups" -- Core Committers pre-checked | PASS |

Screenshots: screenshots/ac1c-archived-403.png (clean "Access denied" page, POC ribbon
and persona-switcher bar both intact, no layout break), screenshots/ac1c-control-200.png
(real content-create form, confirming the differential is genuinely archive-driven and not
a blanket permission gap for this user). This confirms the DOM-level behavior behind
T's curl-based 403/200 status assertions.

### AC-2 -- Public pinned post visibility (anonymous, /node/1)

| Check | Expected | Observed | Result |
|---|---|---|---|
| Anonymous load /node/1 | 200 | 200 | PASS |
| Heading | Sprint Planning: Portland 2026 | matches | PASS |
| span.pin-badge visible | visible | visible (PINNED, orange badge) | PASS |
| data-do-tooltip | truthy | "Pinned: this post is kept at the top of the group stream so newcomers see it first, regardless of date." | PASS |

Screenshot: screenshots/node-1-pinned.png.

## Regression sweep (visual)

- /all-groups (desktop + 360px): all 8 group cards render, consistent card grid, no
  overlap, footer/nav intact.
- /group/8 (desktop + 360px): nav, POC ribbon, persona-switcher bar, breadcrumb, badges,
  and the "Who can do what" table all render cleanly at both viewports.
- Console: zero JS errors or page errors across all pages/contexts driven (anonymous
  and authenticated). The only console entry captured was a browser-logged
  "Failed to load resource: the server responded with a status of 403 (Forbidden)" for the
  AC-1c archived-group request itself -- this is the browser's own network-log echo of the
  expected 403 response, not a script error or defect.

## A11y quick check (WCAG 2.2 AA, POC bar -- no obvious regression)

- Archive badge keyboard reachability: confirmed tabindex="0" present on
  span.group__archived-badge. Tabbed through /group/8 from page load: badge received
  focus naturally at Tab-stop 21 (after nav/menu/breadcrumb links), text "Archived" via
  document.activeElement.
- Focus visibility: computed style on the focused badge showed a visible
  default browser focus outline (1px solid, not suppressed by CSS).
- Tooltip text: non-empty and meaningful for both the Archive badge and the Pin badge
  (verbatim copy quoted above) -- describes the actual behavioral consequence (read-only /
  stays-pinned-at-top), not a vacuous label.
- Card link accessible name on /all-groups: "Legacy Infrastructure" -- a real,
  descriptive accessible name (not "click here" or bare icon).

No obvious a11y regression found. Full axe scan is S's responsibility per the pipeline
contract (S owns the visual/WCAG verdict); this was a quick-check only, as instructed.

## SPA-nav / hard-reload note

Not applicable in the U contract's SPA-nav sense -- this is a Drupal 11 site with standard
full-page navigation (no HTMX/SPA content swap in these surfaces), per the project
override. All navigations here (page.goto, click-through) are full Drupal page loads,
which is the only navigation model this stack has; there is no separate SPA path vs.
hard reload discrepancy to check.

## Findings summary

| Finding | Severity | Blocking? |
|---|---|---|
| /all-groups card lacks data-do-tooltip (Views-fields render bypasses hook_preprocess_group) | Advisory | No -- pre-existing, out of #128's Files In Scope, already flagged by T-RED/T-green, brief's non-goals forbid a template fix here. Recommend a follow-up ticket for the .gc-directory-card component. |

No shipped-code defects found. No REWORK required.

## Evidence (screenshots)

All under docs/planning/handoffs/128-archive-demo/screenshots/:
- all-groups-desktop.png, all-groups-360.png
- group-legacy-infrastructure-desktop.png, group-legacy-infrastructure-360.png
- ac1c-archived-403.png, ac1c-control-200.png
- node-1-pinned.png

## Verdict

PASS. All 4 acceptance criteria exercised via the live browser (AC-1a, AC-1b, AC-1c,
AC-2) render exactly as T/F/T-green's status-code and locator assertions predicted, at both
desktop and mobile viewports, with zero console errors and no visual/layout regressions.
Keyboard reachability and focus visibility confirmed for the interactive badge. The one
open item (/all-groups card tooltip gap) is advisory, pre-existing, and explicitly
out of #128's scope per the brief's non-goals.

Ready for S (Spec Auditor).
