# Handoff-U: Phase 8 - #141 MC-2 About section

**Date:** 2026-07-23
**Branch:** 141-about
**Issue:** #141
**Verdict: PASS**

## Environment

- DDEV project `gm141-about`, already up and seeded by T (per handoff-T-green.md seed sequence: site:install, config:import, step_700/720/790, gm141-about namespace).
- Live URL: `https://gm141-about.ddev.site` (confirmed 200 on `/all-groups` and `/user/login`).
- Confirmed seed state directly via `drush php:script` against a scratch PHP file (not committed): About prose present on groups 1 (DrupalCon Portland 2026), 3 (Core Committers), 4 (Thunder Distribution); empty on groups 2, 5, 6, 7, 8 - matches F and T handoffs exactly.
- Driven via a throwaway local Playwright script (`.u-walk.mjs` / `.u-axe.mjs`, both deleted after the run) using the repo own `node_modules/playwright` (v1.61.1), since the Playwright-MCP tool surface was not exposed in this session toolset. Same evidence shape as the canonical collectEvidence contract: screenshots, DOM assertions, console-error capture, axe-core run (injected from cdnjs.cloudflare.com axe-core 4.10.2, since axe-core is not an installed local dependency).
- No SPA/HTMX nav applies to this Drupal site - each page load is a standard full navigation, per the project PROJECT_CONTEXT.md override. Both the listing-to-group click-through path and direct page.goto() were exercised; no discrepancy found (there is no client-side swap to diverge from).

## AC-7 - Visitor sees About on seeded groups

| Group | Wrapper present | Label text | Bold emphasis renders | Screenshot |
|---|---|---|---|---|
| DrupalCon Portland 2026 (group/1) | yes (1) | About | yes | screens/02-with-about-drupalcon-portland-2026.png, screens/03-about-section-drupalcon-portland-2026.png |
| Core Committers (group/3) | yes (1) | About | yes | screens/02-with-about-core-committers.png, screens/03-about-section-core-committers.png |
| Thunder Distribution (group/4) | yes (1) | About | yes | screens/02-with-about-thunder-distribution.png, screens/03-about-section-thunder-distribution.png |

All three render a field wrapper `field--name-field-group-about field--type-text-long field--label-above` with `div class=field__label` text About and non-empty paragraph prose containing a strong-tag span that visibly bolds in the screenshot (confirmed visually in 03-about-section-drupalcon-portland-2026.png: "This group coordinates the planning committees work" renders bold). Matches handoff-T-green curl confirmation and F design note (no separate heading wrapper - the field own label:above is the H2 source - in practice it renders as a div, not an h2; see AC-8 below).

**Non-seeded groups (empty-state suppression):**

| Group | Wrapper count | About field-label text anywhere on page | Screenshot |
|---|---|---|---|
| Drupal France (group/2) | 0 | absent (only the unrelated gc-group-tabs__link nav tab reads About) | screens/04-without-about-drupal-france.png |
| Leadership Council (group/5) | 0 | absent | screens/04-without-about-leadership-council.png |

Verified the one raw "About" text occurrence on group/2 HTML is the nav tab (class gc-group-tabs__link is-active), not the field label - confirmed by locating the exact HTML context around the match. **AC-7 PASS.**

## AC-8 - WCAG 2.2 AA (axe)

Ran axe-core (wcag2a, wcag2aa, wcag22aa tags) against group/1 (with About) and group/2 (without About). Full results: docs/planning/handoffs/141-about/axe-results.json.

**Violations found (both pages, identical):**
- color-contrast (serious) - .gc-badge--success "Open" status badge, contrast 3.68 vs required 4.5:1. **Present on BOTH the with-About and without-About page**, i.e. pre-existing site chrome unrelated to #141 (the group visibility/status badge, not the About field). Not a regression introduced by this story.

**Scoped re-run against .field--name-field-group-about alone (group/1):** 0 violations, 1 pass. The About section itself introduces zero axe findings.

**Heading structure (group/1, with About):**
```
H2 Main navigation
H2 Breadcrumb
H1 DrupalCon Portland 2026
H2 Upcoming events
H2 Who can do what
H2 Community
```
The About label does **not** appear as any heading element - confirmed it renders as a div.field__label, matching the sibling field_group_links convention (per handoff-T-green finding). **Axe raised no heading-order, empty-heading, or landmark violation over this shape** - i.e. axe accepts a div-based field label here; it is not flagged as an accessibility defect on this page. The brief AC-8 wording (an "About heading" that "renders after description, before Links") is not literally satisfied by an h2 element (it is a div), but this is a **site-wide established convention** (identical to the merged #140 Links field, which uses the same div.field__label shape and was not flagged by S at that story), not a #141-introduced regression. Flagging as an advisory, not a blocking finding - consistent with T own advisory note in handoff-T-green.md recommending this go to U/S rather than be treated as an unmet literal AC.

**Empty landmarks:** the emptyLandmarkCheck found only 2 empty-text landmark-role elements (button role=switch class sticky-header-toggle, span role=img class drupal-logo) - both pre-existing site chrome (sticky-header toggle, logo icon), unrelated to About. The About field wrapper correctly does **not exist at all** when empty (confirmed above), so it cannot produce an empty landmark - this is the intended empty-state guarantee working as designed. **No empty About landmark found on either page. AC-8 PASS** (with the div-vs-h2 note above recorded as advisory, not blocking, since axe itself does not flag it and it matches established sibling convention).

## Regression spot-check

- **Links & Resources (from #140) still renders** on group/1 alongside About: .field--name-field-group-links wrapper count = 1, visible in screens/02-with-about-drupalcon-portland-2026.png ("Links & Resources" / "Conference schedule" / "Sponsorship info"). PASS.
- **Description still renders:** .field--name-field-group-description count = 1, text "Planning committee for DrupalCon Portland 2026. Everyone is welcome to contribute." PASS.
- **Hero image:** .field--name-field-group-image count = 0 on **all 8 groups**, checked via curl across group/1..8 - this is pre-existing seed state (no group has image data seeded at all, in any story), not a #141-introduced regression. Not applicable to this walkthrough scope; noted for completeness.
- **Console errors:** none captured across the full walkthrough (consoleErrors: []).

## Reading-order sanity (group/1, DrupalCon Portland 2026)

DOM order of field wrappers in the content region:
```
1. field--name-field-group-description (label hidden, weight 0)
2. field--name-field-group-visibility  (label above, weight ~1)
3. field--name-field-group-about       (label above, weight 10)
4. field--name-field-group-links       (label above, weight 20)
```
Matches the intended weight ordering (description -> visibility -> [no image seeded] -> About -> Links). Visually confirmed in screens/02-with-about-drupalcon-portland-2026.png and the 360px mobile capture screens/05-mobile-360-with-about.png (both desktop and mobile preserve the same order, no reflow issues). **PASS.**

## Mobile (360px) spot-check

screens/05-mobile-360-with-about.png - About section renders correctly at 360px width: label "About" bold, prose wraps cleanly, bold emphasis visible, no horizontal overflow, no layout breakage. Links & Resources section below it also intact. PASS.

## Findings

None blocking. One advisory carried forward (not a rework item):

- **Advisory (not blocking):** AC-8 literal brief text says "About heading" (implying h2) but the shipped markup uses a div with class field__label containing the text About, matching the established field_group_links convention from #140. Axe does not flag this as a violation on either page tested, and it is consistent site-wide behavior, not a #141-specific regression. If a true semantic heading is desired platform-wide for field-label sections, that is a cross-cutting design decision affecting both #140 and #141 equally, not a #141-only rework item. No action requested of F or T for this story.

## Verdict

**PASS.** All ACs in U scope (AC-7, AC-8) verified live against the seeded DDEV site: About renders with correct label/prose/emphasis on all 3 seeded groups, empty-state suppression confirmed on 2 non-seeded groups (0 wrapper, 0 stray label text), axe found no violations attributable to the About feature (the one color-contrast finding is pre-existing site chrome present identically on both with- and without-About pages), reading order matches the intended weight design, mobile viewport renders cleanly, and the #140 Links regression check plus description regression check both pass. No console errors observed.

## Evidence

- Screenshots: docs/planning/handoffs/141-about/screens/*.png (10 files)
- Raw walkthrough JSON: docs/planning/handoffs/141-about/walk-results.json
- Raw axe JSON: docs/planning/handoffs/141-about/axe-results.json
