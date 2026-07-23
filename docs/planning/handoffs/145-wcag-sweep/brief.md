# Brief — #145 MC-A11Y WCAG 2.2 AA audit sweep

**Issue:** Performant-Labs/groups-on-d11#145
**Branch:** `145-wcag-sweep`   **Worktree:** `~/Projects/_worktrees/groups-wcag-145`   **DDEV:** `gm145-wcag`
**Review-rigor:** `second-opinion` (per issue)
**Pipeline:** O → A → T(RED) → F → T(GREEN) → diff-gate (dual-review) → U → S → rebase+CI → PR. D skipped (no new UI surface — a11y fixes only). A-dup / brief-gate / pre-PR-hold: cut (POC lean).

## Objective
Add `tests/e2e/a11y-audit.spec.ts` running axe-core against the eight named surfaces (see survey), fix any serious/critical violations at their source, and attach the audit table to the PR.

## Acceptance criteria (checkbox)
- [ ] `tests/e2e/a11y-audit.spec.ts` exists; runs under `npm run test:e2e` against a seeded `gm145-wcag.ddev.site`.
- [ ] `@axe-core/playwright` added as devDependency; `npm install` clean.
- [ ] Zero serious/critical axe violations across the eight surfaces (waivers documented inline via `test.skip('route', 'justification')` — grep-able in the diff).
- [ ] Route → violation-count table written by the spec to `test-results/a11y-audit.md` (Playwright-writable output dir); copied to `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` before PR and pasted into PR body.
- [ ] Keyboard walk (U): Tab traversal reaches every interactive control on directory / group-home / manage-members / create-group; visible focus at every stop.
- [ ] Any code fixes are scoped to a11y (aria, alt, headings, focus-visible, contrast). No module refactors, no unrelated markup churn.
- [ ] CI green post-rebase.

## Surfaces (fixed list — do not expand)
`/` (front page + persona-switcher block scoped scan), `/all-groups` (directory — the codebase route; brief originally said `/groups`), `/group/{seed}` (group homepage), `/showcase`, `/group/{seed}/members`, `/group/add/{type}`, `/do-streams/demo/global` (representative streams route). The persona-switcher is a block, not a standalone `/personas` route — audited via a scoped scan on `/`.

## Out of scope / waivers
- RTL toggle: documented waiver (display-only, no seeded RTL locale).
- Maps: no maps surface exists in the demo (waiver).
- Manual screen-reader pass: automated axe + keyboard walk is the POC bar.
- Module refactors: if a serious/critical would require rewriting a Drupal module, downgrade to documented waiver (>2 such → escalate to O).

## Sibling-collision note (from A advisory)
#116, #111, #124 may touch `/`, `/showcase`, `/streams/*` templates concurrently. Rebase discipline at Phase 10 will handle it; no plan change needed.

## Handoff paths
- Survey: `docs/planning/handoffs/145-wcag-sweep/survey.md`
- Decisions: `docs/planning/handoffs/145-wcag-sweep/decisions.md`
- Audit report (generated): `test-results/a11y-audit.md` → copied to `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` for PR.
