# Handoff-T-red: Phase 4 - #113 ST-4 Trending surface (/trending)

**Date:** 2026-07-23
**Branch:** 113-trending
**Brief / wireframe reviewed:** `docs/planning/handoffs/113-trending/brief.md`, `docs/planning/handoffs/113-trending/wireframe.md`, `docs/planning/handoffs/113-trending/survey.md`, `docs/planning/handoffs/113-trending/handoff-A.md`

## A precondition
Confirmed: A returned **PASS** on the plan (`handoff-A.md`, Phase 3) — "T may proceed to author the RED suite." Zero brief amendments required; three findings carry advisory-only guidance (empty-copy divergence, no-ranking-pill, library-attach mechanism) which this suite treats as watch items rather than blocking issues.

## Tests authored

All six tests live in `tests/e2e/trending.spec.ts` (NEW), tier: **e2e** (this is a cross-cutting route/access/rendering contract — views config + CSS + library attach + regression across two other routes — not unit-testable in isolation, and the project has no kernel/functional PHP test infra wired for this story per the survey's "No kernel test required — config-only + CSS + workflow").

| # | Test name | Acceptance criterion / behavior pinned | Why e2e (cheapest sufficient tier) |
|---|---|---|---|
| 1 | `anonymous GET /trending returns 200 with exactly one <h1> matching /trending/i` | Brief AC1 ("`/trending` renders 200 for anonymous..."); wireframe §1 (page skeleton, ONE h1) and §5 (anonymous === authenticated, no role gate) | Requires a real HTTP round-trip through Drupal's routing/views/access-plugin stack (`access.type: none`) — no PHP unit exists for "this route resolves publicly," and adding one would just re-implement Drupal core's routing test suite. |
| 2 | `the two 2-comment threads (score 6.0) appear in the first 10 rendered cards, and no empty-state string leaks through` | Brief AC2 ("Venue Logistics Thread"/"Patch Review Process RFC" in first 10 cards, score-ordered) + AC3 (empty state mutually exclusive with cards) | Ordering is the emergent result of a DB join (`do_discovery_hot_score` LEFT JOIN) + cron-computed scores + seeded comment counts — only observable by rendering the actual view against the actual seeded/cron'd database. Per A's watch #1, scoped to a positive top-10-window check (`:nth-of-type(-n+10)` + `getByRole(..., exact: true)`), not a whole-page substring probe that would pass even if the titles rendered on page 3. |
| 3 | `at least one .stream-card-wrapper card renders on /trending` | Brief plan Step 1 ("Keep: ... `style.row_class: stream-card-wrapper`"); wireframe §2 (card markup inherited) | Single-purpose DOM-presence check, split out from test 2 so a card-markup regression (e.g. F swaps `view_mode` or `row_class`) fails independently of the ordering assertion, per test-quality "each test names one behavior." |
| 4 | `regression guard: /hot still 200s with the "Hot Content" label (views.view.hot_content.yml untouched)` | Brief AC4 (`views.view.hot_content.yml` unchanged); A's watch #2 (Finding 6/7 sibling-collision + general regression risk on the sort-block source file) | E2E is the only tier that observes "the file's *behavior* as served" rather than just its diff — `git diff` alone (checked separately, not by this test) proves the file bytes didn't change, but this test proves the route F must not break still serves correctly post-change. |
| 5 | `library attach is mechanism-agnostic: trending.css referenced on /trending, following.css still referenced on /following` | Brief plan Step 3 (library registration + attach); A's Finding 3 (attach mechanism is F's choice — `views_pre_render` vs extending `preprocessViewsView()`) | Deliberately asserts only the observable HTML contract (substring match on rendered markup), not an internal hook/preprocess unit test, so the suite does not encode or constrain F's implementation choice per A's advisory. Includes a `/following` regression check (`following.css` still present) since F's likely path — extending the same `preprocessViewsView()` method with a second id() guard — could plausibly clobber the existing branch if written carelessly. |
| 6 | `WCAG-adjacent: exactly one <h1>; pager Next link (if present) has an accessible name` | Brief AC8 (WCAG 2.2 AA minimums); wireframe §7 | Accessibility-tree assertions (`getByRole`, `toHaveAccessibleName`) require a real rendered DOM + accessibility tree computation — this is inherently a browser-level check, not unit-testable. Pager presence is conditionally skipped (wireframe §3: pager omitted when the view fits on one page), avoiding a brittle hard requirement. |

**Not written / explicitly out of scope for this suite:**
- No kernel/PHP test for `do_discovery_hot_score` scoring math — that logic is pre-existing on `main` (survey: "All infrastructure... is ALREADY on main"), not part of this story's diff.
- No test for the `do_chrome` HelpText mapping — survey confirms `PageHelpRouteMapTest.php:52` already asserts `view.trending.page_1 → page.trending`; re-asserting it here would duplicate an existing kernel test (test-quality: don't duplicate another test).
- No test forcing the empty state — brief/task instruction explicitly say not to attempt this against the seeded (non-empty) site; the mutually-exclusive check in test 2 is the sufficient substitute.

## RED confirmation

**Environment note:** no local DDEV instance was booted for this run. Per the task's explicit instruction ("if the local server isn't running, that's fine — record the failure signature; CI will run it against the seeded served site"), the RED was verified two ways:

1. **Static/parse-level RED** — confirms the spec is syntactically and structurally sound (no import/typo/setup errors):
   ```
   npx playwright test tests/e2e/trending.spec.ts --list
   ```
   Output: all 6 tests discovered cleanly, e.g.
   ```
   Listing tests:
     [chromium] › trending.spec.ts:80:7 › ST-4 — Trending (/trending) — #113 › anonymous GET /trending returns 200 with exactly one <h1> matching /trending/i
     [chromium] › trending.spec.ts:91:7 › ... the two 2-comment threads (score 6.0) appear in the first 10 rendered cards, and no empty-state string leaks through
     [chromium] › trending.spec.ts:132:7 › ... at least one .stream-card-wrapper card renders on /trending
     [chromium] › trending.spec.ts:139:7 › ... regression guard: /hot still 200s with the "Hot Content" label (views.view.hot_content.yml untouched)
     [chromium] › trending.spec.ts:148:7 › ... library attach is mechanism-agnostic: trending.css referenced on /trending, following.css still referenced on /following
     [chromium] › trending.spec.ts:163:7 › ... WCAG-adjacent: exactly one <h1>; pager Next link (if present) has an accessible name
   Total: 6 tests in 1 file
   ```

2. **Execution-level RED**:
   ```
   npx playwright test tests/e2e/trending.spec.ts --reporter=list
   ```
   Result: **6 failed / 6 total.** Every failure has the identical signature:
   ```
   Error: page.goto: net::ERR_CONNECTION_REFUSED at https://groups-on-d11-build.ddev.site:8493/trending
   ```
   (test 4 fails at `page.goto('/user/login')` instead, same `ERR_CONNECTION_REFUSED` cause — no server is running at all).

   This is the correct RED for the right reason at this stage: no DDEV site is booted in this environment, and even if one were booted from the current worktree state, `/trending` does not exist yet (no `views.view.trending.yml`, no CSS, no library attach), so the first real assertion (`expect(res?.status()).toBe(200)`) would fail on a 404 instead. The `ERR_CONNECTION_REFUSED` variant is the environment-level equivalent of that same "the route doesn't exist / isn't reachable yet" failure — it is not a test-authorship defect (no missing import, no typo, no setup exception inside the test body itself; the `--list` run above proves the spec itself is well-formed). CI's e2e job serves a real, seeded, cron'd Drupal site, where this same suite will instead fail on the 404/assertion path pre-F and pass post-F.

## Ready for F
Confirmed RED is valid (structurally sound suite, execution fails for an environment/route-does-not-exist reason consistent with "the feature does not exist yet," not a setup/import defect). F may implement against `tests/e2e/trending.spec.ts`.

## Deviations from the brief (documented, not silently resolved)
1. **`/hot` access model**: task prompt asked me to check `views.view.hot_content.yml`'s `access:` block to confirm whether login is required before writing the regression test. Inspection found **no `access:` key present at all** in that file — Drupal Views defaults to public/unrestricted when no access plugin is configured. `/hot` is therefore NOT auth-gated. I kept the `login()` call in test 4 anyway (as a superset check — an authenticated user must also still see `/hot` unaffected) rather than removing it, since the brief's own survey text describes `/hot` as "already... in production... publicly" — consistent with this finding. No production code implicated; purely a test-authorship note.
2. Per-instruction, I did not attempt to spin up DDEV locally for this phase; RED was verified via parse-level discovery + connection-refused execution, both captured above, per the task's explicit allowance.

## Assumptions I could not independently verify in this run
- That CI's e2e job (which does boot a real seeded DDEV site) will show the "true" pre-F RED signature (404 on `/trending`, not connection-refused) — not independently re-verified here since no local DDEV instance was booted for this story, per task instruction. This is a reasonable inference from the `.github/workflows/test.yml` job definition (confirmed the seed + cache:rebuild steps exist and run before the e2e suite) but not something I directly observed running.
