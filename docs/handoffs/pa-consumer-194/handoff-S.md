# Handoff-S: Phase 9 — #194 profile_activity.section consumer wiring

**Date:** 2026-07-24
**Branch:** 194-profile-activity-consumer @ HEAD `d014e29`
**Reviewed:** brief.md, handoff-T-red.md, handoff-F.md, handoff-U.md, decisions.md.

## Verdict

**REWORK** — in-scope edits are correct and match every acceptance criterion, but branch state is not PR-ready.

## Acceptance criteria (in-scope diff verified)

| # | AC | Result |
|---|----|--------|
| 1 | `data-do-tooltip="This person's recent published posts…"` on wrapper | PASS — DoStreamsHooks.php:632 sets it from `HelpText::get('profile_activity.section')`; U observed exact copy live. |
| 2 | `tabindex="0"` on wrapper | PASS — line 633; U confirmed keyboard-focusable. |
| 3 | `do_chrome/tooltips` attached | PASS — line 634; U saw `window.tippy` bound and `.tippy-box` firing. |
| 4 | Pre-existing `do-streams-profile-activity` + `do_streams/profile_activity` preserved | PASS — lines 630-631 untouched; test's no-regression assertions green. |
| 5 | `do_chrome` in `do_streams.info.yml` deps | PASS — line 15 added. |
| 6 | Kernel test asserts all four in one render pass | PASS — `ProfileActivityTooltipTest`, 1 method, 6 assertions on one `preprocessBlock()` invocation. |

Also verified: (a) HelpText.php untouched by this branch's in-scope diff (append-only contract respected); (b) sibling #193 surface (stream-card variants, do-streams-shell.html.twig) not touched; (c) F's handoff matches the actual scoped diff line-for-line; (d) U evidence PNGs exist on disk.

## Blocking issues (REWORK)

1. **Nothing is committed.** `git log origin/main..HEAD` is empty; all edits sit as staged + unstaged working-tree changes. A PR cannot be opened.
2. **Branch base is stale.** HEAD == `d014e29`; `origin/main` is at `9203cda` (`feat: #133 SD-6 honesty sweep` merged after this branch was cut). Status reports "behind origin/main by 1 commit, can be fast-forwarded." Must rebase / fast-forward before commit so the PR diff reflects only #194's ~15-line delta, not a spurious reversion of #133.
3. **Staging is partial and mixed with untracked artifacts.** Only `ProfileActivityTooltipTest.php`, `handoff-T-red.md`, `decisions.md` are staged. `DoStreamsHooks.php`, `do_streams.info.yml`, `brief.md`, `handoff-F.md`, `handoff-U.md`, `evidence/` are unstaged/untracked. Also present: an unrelated `.ddev/config.yaml` project-rename (`gm145-wcag` → `gm194-paconsumer`, T's environment fix) plus dozens of unstaged `web/*`, `config/sync/*`, `docs/groups/modules/do_chrome/*`, `docs/groups/modules/do_showcase/*` deltas that are all rebase-noise from the stale base. After rebase these should evaporate; if any survive, they must be reverted before commit.

## Required actions before PR

- `git fetch && git rebase origin/main` (clean fast-forward expected on the base; then re-verify only `DoStreamsHooks.php` + `do_streams.info.yml` + new test file + handoff docs remain).
- Decide whether `.ddev/config.yaml` project-rename is committed with this PR or reverted (env-only, but not in brief). Recommend revert unless O explicitly wants it kept.
- Stage exactly: `DoStreamsHooks.php`, `do_streams.info.yml`, `ProfileActivityTooltipTest.php`, `docs/handoffs/pa-consumer-194/**`.
- Commit + push + open PR. Post-rebase, re-run the Kernel test once inside DDEV to confirm still GREEN.

No REWORK on the code itself. This is a git-hygiene gate.
