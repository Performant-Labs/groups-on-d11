# OVERNIGHT-CI-FAIL — #127 SD-2 Card ⓘ tooltips (PR#161)

**Status at park:** PARKED at PR#161 for morning triage. Local implementation is complete and verified GREEN (7/7 e2e, 12/12 unit, regression clean). Not merged tonight due to GitHub Actions trigger anomaly + inherited main-red.

## Timeline
1. Full pipeline O → A → T(RED) → F → T(GREEN) → diff-gate → U → S completed clean. S PASS on 6/6 issue-#127 ACs.
2. PR#161 opened after rebase on origin/main.
3. **CI cycle 1** (base sha `2fc93c0`): Kernel ✓, Functional ✓, E2E **FAIL** — 2 of my new `element-tooltips.spec.ts` tests failed on directory-card locators (test-authorship bug: over-assumed every `/all-groups` card carries a type badge, but the twig conditionally renders it only when `field_group_type` is set — 4 of 12 seeded groups lack it).
4. **T CI-fix authored** (commits `208d192` + `9ab4846`): pinned the two directory-card tests to a card carrying all 3 target elements (type, visibility, members) via a `fullDirectoryCard(page)` helper. Verified GREEN locally on `gm127-card-tooltips` DDEV (12-group seed exactly reproducing CI's field_group_type gap): 7/7 target e2e + 3/3 `directory-cards.spec.ts` regression.
5. Pushed `9ab4846` to origin — **no CI run triggered** for that sha. Same GitHub Actions trigger anomaly that hit sibling PRs #132 and #139 tonight.
6. **Empty re-trigger commit** `ddb3645` pushed per coordinator direction — also **failed to trigger CI**. Trigger anomaly persists.
7. Meanwhile main went red from #141 merge (phase3/phase4 E2E broken). Even a successful trigger would surface inherited failures.
8. Per user decision: no more hotfixes tonight; morning triage.

## What is genuinely done (verified)
- **Implementation** (`d42f716` → rebased `1dc858b`): 5 new HelpText keys under `card.*` namespace + 2 preprocess extensions (`groups_chrome.theme`) + 6 twig ⓘ triggers across the two card templates. Diff is exactly the brief's owned files; append-only invariant respected; disjoint from #126 (sibling merged).
- **Test suite fix** (`208d192`): directory-card locators robust against the field_group_type gap that CI seeds expose.
- **Local verification** (post-fix): 7/7 element-tooltips e2e, 3/3 directory-cards regression, 12/12 HelpText unit, no new lint. Live UI walkthrough (U) previously PASS.
- **Diff-gate** (o4-mini): PASS, 0 BLOCK.
- **S audit**: PASS, 6/6 ACs met.

## What's blocking merge (transient / not #127's fault)
1. **GitHub Actions trigger anomaly** — pushes to `127-card-tooltips` (both real `9ab4846` and empty `ddb3645`) did not create workflow runs. Sibling PRs #132/#139 hit the same anomaly tonight. Not a code issue.
2. **Main-red from #141** — phase3.spec.ts / phase4.spec.ts broken on main post-merge. Would surface as inherited failures on any PR#161 CI run. Requires a main-side hotfix (not #127's turf).

## Morning triage checklist
1. Confirm main is green (or wait for the #141 hotfix to land).
2. `cd ~/Projects/_worktrees/groups-card-tooltips`
3. `git fetch origin && git rebase origin/main` — will pick up the main hotfix and drop the empty `ddb3645` (or keep it, harmless).
4. `git push --force-with-lease` — regular push (not force to same sha); should trigger CI cleanly per the pattern that broke tonight only on force-pushes to already-force-pushed heads.
5. If CI still doesn't fire: manually re-run via `gh workflow run` or GitHub UI, or add another normal (non-empty) commit if that clears the trigger.
6. Expected CI outcome: all green. Merge per overnight-mode authorization on the fresh green: `gh pr merge 161 --repo Performant-Labs/groups-on-d11 --merge --delete-branch`.
7. Post-merge: `git -C ~/Projects/groups-on-d11 worktree remove ~/Projects/_worktrees/groups-card-tooltips`; `ddev delete -Oy gm127-card-tooltips` (in worktree dir before removing); notify main `[127] MERGED at <sha>; branch deleted; worktree pruned`.

## Handoff files (all committed)
- `brief.md`, `survey.md`, `decisions.md` (full journal O → S + T CI-fix)
- `handoff-F.md`, `handoff-T-red.md`, `handoff-T-green.md`, `handoff-U.md`, `handoff-S.md`
- `diff-gate-round1.md`
- This file

## Untracked local state (do not commit; morning tools should restore)
- `.env` (copied from primary for dual-review)
- `.ddev/config.local.yaml` (renames project to `gm127-card-tooltips` to avoid sibling collision)
- `web/sites/default/settings.php` local override (config_sync_directory pointing at `../config/sync`)
- Assembled `web/modules/custom/*`, `config/sync/*` (build artifacts)
