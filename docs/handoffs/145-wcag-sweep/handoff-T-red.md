# Handoff-T-red: Phase 4 - #145 MC-A11Y WCAG 2.2 AA audit sweep

**Date:** 2026-07-23
**Branch:** 145-wcag-sweep
**Brief / wireframe reviewed:** docs/planning/handoffs/145-wcag-sweep/brief.md, docs/planning/handoffs/145-wcag-sweep/survey.md, docs/planning/handoffs/145-wcag-sweep/decisions.md (A's Phase-3 PASS entry)

## A precondition
Confirmed: A returned PASS on the plan (Phase 3, decisions.md) — per-route `test(...)` loop (not describe-per-surface) endorsed, `@axe-core/playwright` confirmed as the right dep, sibling-collision risk (#116/#111/#124) flagged as merge-order only, not a block.

## Tests authored
All in `tests/e2e/a11y-audit.spec.ts`, one `test(...)` per surface (survey's fixed eight), each running the shared `auditRoute()` helper (AxeBuilder wcag2a/aa + wcag21a/aa + wcag22aa tags, filters serious/critical impact, appends a row to `test-results/a11y-audit.md`, asserts the filtered array is empty). Tier: e2e (axe requires a real rendered DOM + computed styles — no cheaper tier applies to this cross-cutting accessibility sweep).

1. `/ (front page) has no serious/critical axe violations` — pins the directory-landing page.
2. `/all-groups (directory + card grid + filters) has no serious/critical axe violations` — brief's "/groups" shorthand; real route confirmed via nav.spec.ts/directory-cards.spec.ts.
3. `/group/{seed} (group homepage) has no serious/critical axe violations` — resolves a real seeded group ("DrupalCon Portland 2026") via the public directory, mirroring group-about.spec.ts's gid-instability workaround.
4. `/showcase (variant switcher + POC ribbon) has no serious/critical axe violations`.
5. `/personas (persona banner switcher) has no serious/critical axe violations`.
6. `/group/{seed}/members (manage-members table) has no serious/critical axe violations` — same seed-resolution pattern + `/members` suffix.
7. `/group/add/{type} (create-group form) has no serious/critical axe violations` — `community_group`, matching create-group.spec.ts/nav.spec.ts.
8. `/do-streams/demo/{scope} (shared stream shell, representative route) has no serious/critical axe violations` — brief's "/streams/{one}" shorthand; real route is `do-streams/demo/{membership|following|global}` (do_streams views config); `global` picked as representative.

Waivers (documented, not scope creep): `test.skip('RTL toggle audit', ...)` and `test.skip('Maps surface audit', ...)` — grep-able per brief's requirement.

## RED confirmation
Command:
```
npx playwright test tests/e2e/a11y-audit.spec.ts --list
```
Output:
```
Error: Cannot find module '@playwright/test'
... MODULE_NOT_FOUND, thrown while loading playwright.config.ts
```
This worktree has no `node_modules` at all yet (`ls node_modules` → No such file or directory) — even the pre-existing base dependency fails to resolve, not only the newly-added `@axe-core/playwright`. This is the correct RED reason: a missing-dependency failure in an unprepared environment, not a false "0 violations" pass and not an authoring defect in the spec.

Independent syntax sanity check: `node --check tests/e2e/a11y-audit.spec.ts` exits 0 — confirms the spec file itself is well-formed; the RED is entirely attributable to the absent `node_modules`, which is F's environment-prep responsibility (per the task's own instruction not to `npm install` here).

## Ready for F
Confirmed RED is valid. F should: `npm install` (picks up `@axe-core/playwright` added to `package.json`), assemble + seed `gm145-wcag.ddev.site`, run the spec against the live site, and fix any real serious/critical violations at their source (or downgrade to a documented waiver per the brief's fix envelope, escalating to O if more than two such waivers accumulate). Full detail in `docs/planning/handoffs/145-wcag-sweep/decisions.md` under "T — Phase 4 (RED)".
