# Handoff-U: Phase 8 — #194 profile_activity.section consumer wiring

**Date:** 2026-07-24
**Branch:** 194-profile-activity-consumer
**Verdict:** PASS

## Run environment
- DDEV project `gm194-paconsumer`, Drupal 11.4.4, PHP 8.4, mariadb 11.8.
- Base URL: `https://gm194-paconsumer.ddev.site`.
- Fresh install (`site:install standard`) + `config:import` off `config/sync` (uuid re-aligned;
  `taxonomy_vocabulary.tags` pre-deleted to unblock cim), then `drush en` of all `do_*` custom
  modules incl. `do_streams` + `do_chrome` + `do_activity` + `do_activity_feed` (pmu/en cycle
  per CI #129 workaround). Seeds: `step_700_demo_data.php` (gives users maria_chen/…/elena
  each with 5 published nodes) + `step_7xx_backfill_activity.php`.
- Login via one-time `drush uli` (never hardcoded creds).
- Playwright 1.49.1 (chromium, headless, `ignoreHTTPSErrors`).
- Target profile: `/user/2` (maria_chen, 5 published posts).

## Per-control checklist

| # | Action | Expected | Observed | Verdict |
|---|--------|----------|----------|---------|
| 1 | GET `/user/2` as admin | 200; user-activity block rendered | 200; block `#block-do-streams-user-activity` present with `.do-streams-profile-activity` class | PASS |
| 2 | Read wrapper attrs | `data-do-tooltip` = HelpText copy, `tabindex="0"` | `data-do-tooltip="This person's recent published posts, newest first — scoped to what you can already see. Content in groups you cannot access never appears here."`, `tabindex="0"` — exact match to `HelpText::get('profile_activity.section')` | PASS |
| 3 | Library attached | `window.tippy` global present after page load | `!!window.tippy === true`; wrapper carries `data-once="do-chrome-tooltip"` (behavior bound exactly once) | PASS |
| 4 | Hover wrapper | `.tippy-box` appears with same copy | 1 `.tippy-box`, innerText matches HelpText verbatim | PASS |
| 5 | Mouse-out | Tooltip dismisses | `.tippy-box` count → 0 | PASS |
| 6 | Keyboard focus wrapper (`element.focus()`) | Focus lands on wrapper; tippy fires on focus | `document.activeElement === wrapper`; 1 `.tippy-box` visible with same copy | PASS |
| 7 | Mobile 360×800 viewport | Wrapper present, tooltip fires | Wrapper found; 1 `.tippy-box` after hover | PASS |
| 8 | Console | No JS errors | `[]` on both desktop and mobile runs | PASS |

## Wireframe conformance
Brief specifies "whole block wrapper is the trigger — no new badge/glyph". Rendered markup
matches exactly: the outer `<div>` for `views_block:user_activity-block_1` carries the
tooltip attributes; heading + rows sit inside untouched.

## Evidence
- `docs/handoffs/pa-consumer-194/evidence/u-hover.png` — desktop, mouse-hover, `.tippy-box`
  visible above the "Recent posts" block.
- `docs/handoffs/pa-consumer-194/evidence/u-focus.png` — desktop, keyboard focus (tab-reachable),
  same `.tippy-box` copy shown.
- `docs/handoffs/pa-consumer-194/evidence/u-mobile-hover.png` — 360×800 viewport, tooltip fires.

## Accessibility (WCAG 2.2 AA quick check)
- **2.1.1 Keyboard:** wrapper is tab-reachable (`tabindex="0"`) and `document.activeElement`
  confirmed after `.focus()`; the tooltip fires on focus with identical copy, not only on hover
  (per SC 1.4.13 "Content on Hover or Focus" trigger parity).
- **1.4.13 Content on Hover or Focus:** tippy default arrangement is dismissable
  (auto-hide on mouse-out confirmed), hoverable (Tippy default), and persistent until
  dismissed. Uses the existing `do_chrome/tooltips` library — no new styles, so contrast
  matches the vetted PermissionMatrixPanel precedent.
- **4.1.2 Name/Role/Value:** the `data-do-tooltip` value provides the accessible description
  Tippy renders; wrapper is a `<div>` acting as a focusable region (matches sibling
  `.do-chrome-perm-matrix__intro` pattern already shipped).

No new concerns beyond the pattern F reused verbatim.

## Findings
None. All six acceptance criteria from the brief hold under live-browser observation:
attribute presence, exact HelpText copy, `tabindex="0"`, library-attached (Tippy globally
bound), pre-existing wrapper class + `do_streams/profile_activity` library preserved
(observed alongside the new attrs), and the tooltip actually fires on both hover AND
keyboard focus — which is the whole point the kernel test could not prove.

## Verdict
**PASS.** Ready for S (Spec Auditor).

## Files touched by U (evidence only, no code)
- `docs/handoffs/pa-consumer-194/handoff-U.md` (this file)
- `docs/handoffs/pa-consumer-194/evidence/u-hover.png`
- `docs/handoffs/pa-consumer-194/evidence/u-focus.png`
- `docs/handoffs/pa-consumer-194/evidence/u-mobile-hover.png`
- `docs/handoffs/pa-consumer-194/decisions.md` (appended U entry)
