# OVERNIGHT-CI-FAIL — #144 MC-6 Create-Group flow (creator auto-Organizer + guided preview)

**PR:** https://github.com/Performant-Labs/groups-on-d11/pull/156
**Branch:** `144-auto-organizer` (head sha at park time = commit `05c1a09` — cycle 2 head)
**Worktree:** `~/Projects/_worktrees/groups-auto-organizer` (left in place for morning triage)
**Parked:** 2026-07-23, after CI cycle 2 E2E failure — per overnight-mode contract, same test failed twice = STOP.

## The failing test (both cycles)

`tests/e2e/create-group.spec.ts` — the single test
`Create-group flow (#144 MC-6) › creator becomes Organizer, lands on guided preview, and reaches manage-members`.
Kernel + Functional passed in both cycles; only the E2E job fails. Every OTHER E2E test in the suite passed (67 passed, 1 skipped pre-existing).

## Cycle 1 — https://github.com/Performant-Labs/groups-on-d11/actions/runs/29997510764/job/89174590720

**Symptom:** `Error: completeWizard(): exceeded maxSteps without leaving /group/add/` at line 96. Test ran ~7.5s = 6 iterations of the wizard-advance loop.

**Root cause (diagnosed, evidence-backed — NOT flakiness):**
The RED E2E used `page.locator('textarea[name="field_group_description[0][value]"]').fill()` guarded by an optional `isVisible({timeout: 500})` probe. On the assembled `community_group` type the description field is a **CKEditor 5** field — the hidden textarea `.fill()` does NOT propagate to CKEditor's model, so the form submitted with an empty required value and re-rendered on `/group/add/community_group/` with a validation error. `completeWizard()` clicked the same submit button 6× without ever leaving `/group/add/`. Also `field_group_visibility` is a REQUIRED radio group the RED did not set.

**Why local pipeline was green:** the sibling Functional test uses Mink `fillField()`, which writes the raw hidden-textarea value directly and bypasses CKEditor entirely. Only headless real-browser E2E exercises the CKEditor JS layer. The playwright-ui-walkthrough (U phase) apparently followed a similar shortcut path (or was against a differently-configured local site) and did not surface this.

**Evidence in-repo:** `tests/e2e/phase1.spec.ts:84` "authenticated user can create group" (which PASSED in the SAME CI cycle 1 run) shows the correct pattern: click `.ck-editor__editable` + `pressSequentially()` for description; `input[name="field_group_visibility"][value="open"]`.check() for visibility.

**Fix applied (commit `05c1a09`):** aligned E2E form-fill with the phase1 pattern. Source-only test change; no production code touched.

## Cycle 2 — https://github.com/Performant-Labs/groups-on-d11/actions/runs/29998053836/job/89176336924

**Symptom (verbatim):**
```
Error: expect(locator).toBeVisible() failed

    Locator: getByRole('heading', { level: 2 })
    Expected: visible
    Error: strict mode violation: getByRole('heading', { level: 2 }) resolved to 6 elements:
        1) <h2 class="visually-hidden">Toolbar items</h2>
        2) <h2 class="visually-hidden block__title" id="block-groups-chrome-main-menu-menu">Main navigation</h2>
        3) <h2 class="visually-hidden">Status message</h2>
        4) <h2 id="system-breadcrumb" class="visually-hidden">Breadcrumb</h2>
        5) <h2>What's next?</h2>
        6) <h2 class="visually-hidden block__title" id="block-groups-chrome-footer-menu-menu">Community</h2>

        at create-group.spec.ts:154
```

**Interpretation:** The cycle 1 fix WORKED — the form submitted, the group was created, `/group/{group}/created` was reached, the guided-preview `<h1>` was found and matched the group name (assertions on lines 132–134 passed), the "Organizer" paragraph was found (line 136 passed). The failure is at the NEXT assertion (line 154): `getByRole('heading', {level: 2}).toBeVisible()` — a plain unscoped locator that strict-mode-collides with the 6 h2 elements the seeded/themed page has (toolbar, main-menu block, status-message region, breadcrumb, our controller's "What's next?", footer-menu block).

**Root cause (diagnosed, evidence-backed — NOT flakiness):**
Test-authorship defect, same class as cycle 1 — a locator that works on the U walkthrough's minimal theme but strict-collides on CI's fully-seeded site with block-layout h2 headings. Our production `<h2>What's next?</h2>` IS on the page — that's item 5 in the resolved-elements list. This is exactly the "getByLabel(/.../) can strict-mode-collide on a seeded page" gotcha the header comment on this file warns about (WAVE-EXECUTION-HANDOFF §6.9), applied to `getByRole('heading')` instead.

## Best current theory — fix in the morning (test-only, one line each)

Two locators in `tests/e2e/create-group.spec.ts` need scoping to the guided-preview content region so they don't collide with theme chrome:

1. **Line 154** (the failing one): `page.getByRole('heading', {level: 2})` → scope to the preview content, e.g. one of:
   - `page.getByRole('heading', {level: 2, name: /What's next\?/})` — scope by accessible name (the exact copy F shipped).
   - `page.locator('main').getByRole('heading', {level: 2, name: /What's next\?/})` — scope to main content region.
   - `page.locator('.do-group-membership--next-steps').getByRole('heading', {level: 2})` — scope to the CSS class F actually shipped (per handoff-F.md — `.do-group-membership--next-steps` on the preview container).

2. **Line 155** (`await expect(page.locator('h3')).toHaveCount(0)`): also unscoped and would similarly false-fail if the theme ever adds an h3 anywhere (`block-title-h3` styling variants exist). Scope this to the same preview container.

3. **Line 157** (`const ctaList = page.locator('ul').filter(...).first()`): also unscoped — the first `<ul>` with links on the page might be the primary nav or footer nav, not the preview CTAs. Scope to the preview container too.

The `.do-group-membership--next-steps` container class is the most reliable scoping anchor — it's a stable production selector F introduced specifically for this feature (see handoff-F.md "Files changed" `css/create-group.css` note).

## What a human would need to verify before merging the morning fix

1. Confirm the production markup DOES use `.do-group-membership--next-steps` as the ul/section container class — grep `docs/groups/modules/do_group_membership/src/Controller/GroupCreatedPreviewController.php` and the `create-group.css` file for the exact class name and its scope.
2. Confirm the H1 assertion on line 132 (`page.getByRole('heading', {level: 1}).toBeVisible()`) does NOT also strict-mode-collide on the seeded page — the two earlier passing assertions on lines 132–136 suggest the page-title H1 is unique enough, but this should be re-checked because CI produces block-title `<h1>`s in some layouts. If it is a latent second collision, scope it too.
3. **Re-audit every OTHER unscoped `getByRole('heading', ...)` / `page.locator('ul'|'h3'|'p')` in every recent E2E spec** — same defect class likely present elsewhere and just not yet triggered by seed content.
4. Confirm the E2E-cycle-2 result was reproducible (not one-off flake): if the same locator fails a third time on a clean CI re-run with no test change, that would rule out any race-condition theory.
5. After locator scoping is fixed, run the E2E LOCALLY against a fully-seeded site (not the isolated fixture) before pushing — the `assemble → drush site:install → drush cim → step_*.php seeds → runserver → npx playwright test` sequence per PROJECT_CONTEXT.md.

## What I chose NOT to do

- Did NOT push a third fix — overnight contract said "same test twice = PARK." Contract is contract.
- Did NOT merge.
- Did NOT force-push.
- Did NOT tear down the `gm144-*` DDEV project (left running in case morning wants to run local E2E against it; can be torn down with `ddev delete -Oy gm144-auto-organizer` if resources are needed).
- Did NOT close or comment on PR #156.
- Left the worktree in place.

## Files at pause point

- Branch `144-auto-organizer` at commit `05c1a09` (`test(#144): E2E — use CKEditor click+type for description + check visibility radio`).
- All handoffs including this file in `docs/planning/handoffs/144-auto-organizer/`.
- All production code / other tests unchanged from commit `c160ff1` and prior; only `tests/e2e/create-group.spec.ts` was touched in the CI-driven fix cycles.
- No production defects known — the failure is confined to E2E test locator authorship.
