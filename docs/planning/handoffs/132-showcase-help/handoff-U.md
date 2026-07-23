# Handoff-U: Phase 8 - #132 SD-5 Showcase help

**Date:** 2026-07-23
**Branch:** 132-showcase-help
**Verdict: PASS**

## Environment

Fresh DDEV project `gm132-showcase-help` (own containers, config.yaml `name:` changed
from `pl-groups-on-d11` to avoid colliding with the primary checkout's running project;
no sibling container touched or removed). Full seed pipeline run exactly per
`.github/workflows/test.yml` in this worktree (which already includes the persona
seed step ahead of merge):

```
ddev start
ddev composer install
ddev exec bash scripts/ci/assemble-config.sh
ddev drush site:install -y --account-name=admin --account-pass=admin
# config_sync_directory pointed at ../config/sync + system.site uuid matched (same fix CI applies)
ddev drush config:set system.site uuid <config/sync UUID> -y
ddev drush cim -y
ddev drush en -y do_tests do_group_extras do_group_language do_group_mission do_group_pin \
  do_multigroup do_notifications do_profile_stats do_discovery
# seed: step_700_demo_data.php -> step_720_group_types.php -> step_780_nav_menu.php -> step_790_persona_switcher.php
ddev drush cache:rebuild -y
```

Site: `https://gm132-showcase-help.ddev.site`. Left running for S; teardown left to O/S if
no longer needed (`ddev stop gm132-showcase-help` / `ddev delete -O gm132-showcase-help`).

Drive: standard Playwright (`@playwright/test` installed via `npm install` +
`npx playwright install chromium`) against the seeded DDEV site — this is a Drupal/DDEV
project, not the HTMX/SPA stack, so the `u-drive.mjs` fast-path does not apply per the
project override; drove with plain `page.goto` / `selectOption` / `hover` / `focus`.

## Automated suite (live, not env-blocked here)

- `tests/e2e/showcase-help.spec.ts` (new, #132): **6/6 PASS**.
- `tests/e2e/showcase.spec.ts` + `tests/e2e/persona-switcher.spec.ts` (regression): **24/24 PASS**.
- Total **30/30 PASS**, 0 console errors across every run.

## Manual walkthrough findings

### Persona banner ⓘ (all 3 personas)
- Anonymous: banner absent (`0` elements) — PASS.
- Elena Garcia: banner HTML child order verified **`▶, text, switch_back, help`** — matches
  brief-amendments A1 exactly. `aria-label` and `data-do-tooltip` both equal the exact
  `showcase_help.persona_banner` copy from the brief. Hover -> tippy box appears with the
  exact copy. Keyboard: `.focus()` lands on the element, `document.activeElement` confirms,
  computed `outline-style: auto` / `outline-width: 1px` (visible focus ring) — PASS.
- Maria Chen and Groups-Moderate: same banner structure, same tooltip copy, hover verified
  for both — PASS. Switch-back removes the banner (`0` elements after) — PASS.

### `/showcase` tour page — 7 catalog entries
All 7 ids (`discovery-ranking`, `directory-presentation`, `membership-models`,
`group-type-homepages`, `stream-model`, `private-group-reveal`, `persona-switcher`) render
exactly one `[data-do-tooltip]` each, with `aria-label`/`data-do-tooltip` matching the brief
copy **verbatim** (checked string-for-string) and `tabindex="0"`. `HTTP 200` on `/showcase`.

### Map orientation ⓘ
`.do-showcase-map-help[data-do-tooltip]` present, `class="do-showcase-info do-showcase-map-help"`
(exact match to brief), copy matches verbatim, hover -> tippy shows it, `tabindex="0"` — PASS.

### Regression
- Ribbon, variant switcher, persona-switcher dropdown, deep-links: all covered by the 24
  passing regression tests above — no discrepancy found.
- Zero console errors/pageerrors across all three manual drive scripts and both Playwright runs.

### WCAG 2.2 AA spot-check
- Every new ⓘ has non-empty `aria-label` (verified programmatically for all 9 instances:
  1 banner x 3 personas + 7 tour entries + 1 map).
  All are `tabindex="0"`, keyboard-focusable, confirmed with a real `document.activeElement`
  check (not just attribute presence).
- Contrast: `.do-showcase-info` computed `color: rgb(43,53,59)` on transparent/inherited
  background on both banner and tour-page surfaces — consistent with the #120/#122 baseline
  class reused verbatim; no new color introduced.

## Verdict

**PASS.** All acceptance criteria in brief.md + brief-amendments.md verified live in a real
browser: 9/9 keys render correct non-empty copy, DOM order matches A1, explicit tooltips
library attach confirmed by tippy actually appearing (A2), anonymous/switch-back banner
lifecycle correct, no scope-guardrail keys touched, and the two coverage holes T flagged
(Functional + E2E "env-blocked") are now closed by this session's live execution — 30/30
Playwright tests green, plus manual DOM/keyboard/hover verification with zero console errors.
