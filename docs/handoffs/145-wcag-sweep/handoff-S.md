# Handoff-S: Phase 9 — #145 MC-A11Y WCAG 2.2 AA audit sweep

**Date:** 2026-07-23
**Branch:** `145-wcag-sweep`   **Worktree:** `~/Projects/_worktrees/groups-wcag-145`
**Issue:** Performant-Labs/groups-on-d11#145
**Verdict:** **PASS** — ready for rebase + PR.

## Preconditions
- A precondition: PASS (Phase 3 up-front review PASS; decisions.md §"A — Phase 3").
- T precondition: zero blocking issues; T-GREEN shows 8 passed / 2 skipped against seeded `gm145-wcag.ddev.site`.
- U precondition: initial REWORK (skip-link occlusion) fixed by F; U rerun PASS on all four required surfaces.
- Dual-review diff-gate: round-2 PASS (all 6 BLOCK findings ACCEPTED by reviewer).

## Criterion-by-criterion evidence

| # | Acceptance criterion | Evidence | Result |
|---|---|---|---|
| 1 | Automated axe pass, no serious/critical on primary routes | `a11y-audit.md` table: 8 routes × 0/0/0; T-GREEN log `8 passed (9.2s), 2 skipped` | PASS |
| 2 | Waivers documented | `test.skip(true, reason)` for RTL + Maps in `a11y-audit.spec.ts`; `a11y-audit.md` §Waivers cites brief.md waivers | PASS |
| 3 | Manual keyboard traversal, visible focus | `handoff-U.md` Rerun: `Tab` on all four surfaces lands on skip-link (z 503 > ribbon 499); nav focus rectangles confirmed by screenshot | PASS |
| 4 | AA contrast | `tokens.css` diffs: success 3.68→4.75, info 4.16→4.75, warning 3.x→proactive; ratios computed in F handoff | PASS |
| 5 | Non-color status | U walk on `/all-groups`: Open/Archive/Geographical/Event planning/Distribution/Working group/Moderated badges carry text | PASS |
| 6 | Audit table attached | `docs/planning/handoffs/145-wcag-sweep/a11y-audit.md` staged; ready to paste into PR body | PASS |
| 7 | Playwright+axe spec reruns in CI | `tests/e2e/a11y-audit.spec.ts` staged, `@axe-core/playwright@^4.10.0` in package.json, `test:e2e` script present | PASS |
| 8 | Delivery per epic (branch + local test green + rendered DOM) | Branch `145-wcag-sweep` on worktree; two local passing runs (post-fix + rework); U's rendered-DOM confirmation via Playwright | PASS |

## Waiver soundness (issue's own "documented waivers for POC-acceptable" clause)
- RTL: no seeded RTL locale — display-only. Documented in-spec + brief. Consistent.
- Maps: no maps surface in the demo. Documented in-spec + brief. Consistent.
- Both are captured as grep-able `test.skip(...)` declarations that the reviewer round-2 accepted. Meets the issue clause.

## Dual-review triage evaluation
The 5-of-6 rejections in Phase 7 round 1 were sound: B-1/B-2/B-4 verifiable by grep on staged files; B-5 correctly identifies `test-results/` as a runtime dir per Playwright convention; B-6 matches the existing in-body `test.skip(condition, reason)` pattern in `manage-members.spec.ts:155` and was empirically confirmed by the passing run. B-3 (route drift) was rightly accepted and fixed in brief + spec. Round 2 accepted all responses.

## Staged file set (verified)
`git diff --cached --stat` returns exactly the intended set:
- `tests/e2e/a11y-audit.spec.ts` (new spec)
- `web/themes/custom/groups_chrome/css/tokens.css` (contrast fixes)
- `docs/groups/modules/do_showcase/css/do_showcase.css` (rework: z-index 1000 → 499)
- 3 handoff docs (`handoff-T-green.md`, `a11y-audit.md`, `decisions.md`)

No unrelated churn; no module refactors; scope respected.

## Test-quality audit
Suite is proportionate (one test per named surface + two grep-able waivers), each test names one behavior (route × zero serious/critical), assertions are behavior-based (impact filter), no snapshot padding, no mock-shaped tests. `auditRoute()` helper is a legitimate DRY, not a hidden coupling. PASS.

## Advisory notes (non-blocking, out of scope per issue)
- **do_streams config-install gap:** F noted `assemble-config.sh` pre-marks modules "enabled" so `config/install/` never auto-imports; F worked around by manual `drush config:import --partial`. Latent debt for a future `hook_install()` or assemble-script tweak — surfaced here per operator's request; **not a follow-up issue** per memory guidance ("POC — no follow-ups for merged-story latent debt"). Do not file.
- **`--gc-color-warning` fix is proactive** (badge renders nowhere yet); acceptable per project's "fix defect class, not the cited instance" retro. Leave.

Ready for rebase onto `main` and PR open.
