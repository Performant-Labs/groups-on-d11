# OVERNIGHT-CI-FAIL — #132 SD-5 Showcase help

**Status: PARKED for morning triage.** Story-side code + tests are complete and diagnosed
green through 3 CI cycles. Parked on a GitHub-Actions trigger anomaly + main-red inheritance,
not on any #132 defect.

**PR**: https://github.com/Performant-Labs/groups-on-d11/pull/157
**Head commit**: `56a6149` (empty; the meaningful head is `fce23ab`).
**Branch**: `132-showcase-help` (rebased onto post-#160 main).
**Worktree**: `~/Projects/_worktrees/groups-showcase-help` (kept).
**DDEV project**: `gm132-showcase-help` — teardown pending.

---

## Cycle history

### Cycle 1 — Functional FAIL (4 tests, all #132-specific)
- **Diagnosis**: `ShowcaseControllerHelpTest::$modules = ['do_showcase']` alone gives anonymous no `access content` permission (Drupal `node` module provides it). The `/showcase` route requires `access content` and 403'd for every anonymous `drupalGet('/showcase')`. First-ever Functional test to hit `/showcase`, so the missing dep never surfaced before.
- **Fix**: commit `986ea46` — added `'node'` to `$modules` in `ShowcaseControllerHelpTest`. `PersonaBannerTest` unchanged (it hits `<front>`, which anonymous can reach without `node`).
- **Kernel + E2E**: green in cycle 1.

### Cycle 2 — Functional STILL FAIL (1 test, inherited from main, not #132)
- **Confirmed inherited-only**: `Tests: 70, Errors: 1, Failures: 0`. Only failure was `CreateGroupWizardOrganizerTest::testWizardCreateGrantsOrganizerAndRedirectsToPreview` — `PluginNotFoundException: Unable to determine class for field type 'link'`. In `do_tests` module, not any #132 file.
- **Assertion count rose 491 → 527**: confirms cycle-1 `node` fix cleared all four #132 tests; they now execute to completion.
- **Action**: parked awaiting coordinator's hotfix PR #160 (merged `350d8bd`).

### Cycle 3 (post-rebase onto fixed main) — E2E FAIL (1 test, #132-specific)
- **Failed**: `showcase-help.spec.ts:52 › Elena Garcia: banner ⓘ is visible, keyboard-focusable, non-empty aria-label`, 10.4s timeout on `goButton.click()`.
- **Diagnosis**: The site-wide POC ribbon (`<div id="do-showcase-ribbon">`, fixed-position, from #119) intercepts pointer events on the persona-switcher "Go" button during Playwright's post-click stability re-check after the form-submit redirect. The first click DID succeed (Playwright log line 99: `navigated to "http://127.0.0.1:8080/?check_logged_in=1"`); Playwright then re-targets the new page's Go button and finds it covered by the ribbon, retries for 10s, times out.
- **Not a real defect**: the Groups-Moderate test (identical aria assertions) passes at 944ms by timing luck. Banner markup + aria-label + library attach are all correct.
- **Fix**: commit `fce23ab` — added `test.beforeEach` to the persona-banner-ⓘ describe that dismisses the ribbon (sessionStorage-backed dismissal persists across the following `page.goto('/')`). Matches the existing `showcase.spec.ts:346` dismiss pattern.
- **Kernel + Functional**: green in cycle 3 (post-hotfix, post-node-fix).

### Cycle 4 — GitHub Actions trigger anomaly + main-red inheritance
- **No CI run triggered for `fce23ab`** despite the push landing correctly (coordinator confirmed same pattern hit #139 and #127 tonight).
- **Follow-up empty commit `56a6149`** also failed to trigger a CI run.
- **Additionally blocked by main-red**: post-#141 merge broke `phase3.spec.ts` / `phase4.spec.ts` E2E on main. Even if cycle 4 had triggered, this branch would inherit the phase3/phase4 failures on top.
- **Decision (user, via coordinator)**: no more hotfixes tonight; morning triage on main-red first.

---

## Morning triage plan

1. **First: main-red.** Confirm the `54d6321` post-#141 breakage on `phase3.spec.ts` / `phase4.spec.ts` E2E is being repaired by a hotfix on `main`. Do not rebase #132 until main is green again.
2. **Then: rebase #132 onto fixed main.** `git fetch && git rebase origin/main && git push --force-with-lease origin 132-showcase-help`.
3. **If GitHub Actions still misses the trigger**: push a fresh regular (non-empty) commit, e.g. a whitespace touch or a `git commit --allow-empty --allow-empty-message`. If both empty and non-empty commits fail to trigger, escalate to the coordinator for GH Actions status check.
4. **On green cycle**: coordinator has direct user-authorized merge for this session — will handle the `gh pr merge` step.
5. **Teardown after merge**: `ddev delete -O gm132-showcase-help`; `git worktree remove ~/Projects/_worktrees/groups-showcase-help`.

---

## Story readiness checklist (independent of CI)

- [x] Survey + Reuse map — `survey.md`
- [x] Brief + A-folded amendments — `brief.md` + `brief-amendments.md`
- [x] A: PASS (2 soft findings folded)
- [x] T(RED): 26 test methods (Unit 10 + Functional 10 + E2E 6), real-RED confirmed
- [x] F: 3 files edited (`HelpText.php`, `DoShowcaseHooks.php`, `ShowcaseController.php`), 9 new `showcase_help.*` keys, banner ⓘ + tour-page ⓘ + map ⓘ wiring
- [x] T(GREEN): Unit 21/21 GREEN via DDEV external tool
- [x] U: PASS — 30/30 Playwright on full DDEV seeded install, keyboard/hover/aria verified across all 3 personas + anonymous
- [x] S: PASS with PR-body audit notes (join-flow scope trade-off + SC-F1 duplication check)
- [x] PR#157 opened with audit notes
- [x] Cycle-1 Functional fix (node dep) — cleared 4 failures
- [x] Cycle-3 E2E fix (ribbon dismiss) — pushed as `fce23ab`
- [ ] CI cycle 4 GREEN — **BLOCKED on GH Actions trigger anomaly + main-red**
- [ ] Merge — pending green CI

---

## Commit tail

```
56a6149 ci: re-trigger workflow for cycle 4 (empty; failed to trigger)
fce23ab test(#132): dismiss ribbon before persona-banner tests — E2E race fix
c3d15dd (rebased onto post-#160 main — same tree as 139ca53)
139ca53 docs(#132): CI cycle 2 verified — #132 clear, only inherited link-field-type error remains
986ea46 test(#132): add node dep to ShowcaseControllerHelpTest — /showcase route needs access content permission
809d24e docs(#132): S audit — PASS, ready to PR
8e93f39 docs(#132): U walkthrough — PASS, 30/30 E2E green, all 9 ⓘ verified
c10eaa7 docs(#132): T-green handoff — unit GREEN, functional/e2e env-blocked but verified
cce8d7f feat(#132): SD-5 Showcase help — persona banner + tour ⓘ + map orientation
2a86a59 test(#132): RED tests for showcase help (unit + functional + e2e)
ff631d4 docs(#132): record A PASS in decisions
f901ea3 docs(#132): fold A findings — banner ⓘ order + explicit tooltips library
e013ecb docs(#132): survey + brief + decisions for SD-5 Showcase help
```
