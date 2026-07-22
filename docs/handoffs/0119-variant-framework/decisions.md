# Decision journal — 0119-variant-framework (SC-F1)

Issue: https://github.com/Performant-Labs/groups-on-d11/issues/119
Epic: https://github.com/Performant-Labs/groups-on-d11/issues/117

## O — Phase 0 (pre-flight + setup) — 2026-07-22T08:37:00Z
- **Decided:** Orchestrate on `groups-on-d11` (GitHub) rather than the `groupsdrupalorg`
  (GitLab/Drupal.org) session this thread started in — the task targets a different repo. Set up
  an isolated git worktree on branch `0119-variant-framework`, branched from `origin/main`
  (c18f417), per `parallel-agent-coexistence.md` naming (`NNNN-<slug>`, zero-padded issue number).
  Seeded `.env` (gitignored review keys) into the worktree by copy, per task instruction. Ran
  `npm install` in the worktree (separate `node_modules`).
- **Assumed:** The worktree needs its own DB/port only at the Docker-verification step (U/T-live),
  not for static analysis/unit-style checks — no dev server is running yet.
- **Hedged:** `docs/playbook/workflow/orchestrator.md` pre-flight table calls for
  `docs/planning/SPEC.md` / `BUILD_PLAN.md`; this repo has no such docs — the GitHub issue + epic
  are the spec/plan of record for this pipeline instance. Treated as N/A rather than MISSING.
- **Evidence:** `git worktree add ... -b 0119-variant-framework origin/main`; `npm install`
  (silent, no errors); `gh issue view 119`; `gh issue view 117`.

### Mid-run incident: worktree instability, relocated
The first two worktree locations tried
(`groups-on-d11/.claude/worktrees/0119-variant-framework`, twice) proved unstable in this
environment: the directory disappeared entirely once (git also lost its `.git/worktrees/`
metadata for it — a clean-removal shape, not a crash), and on recreation at the same path the
working tree went inconsistent with its own HEAD (`git log`/`git show HEAD:<path>` succeeded but
the same tracked, non-gitignored files were absent on disk, and `git checkout HEAD -- docs/`
reported the path unknown to git from inside that worktree). The primary `groups-on-d11` checkout
and its `.claude/worktrees/agent-issue109` sibling also changed branch/location during this same
window, independent of any command this run issued — indicating environment-level
interference/reconciliation with paths under `groups-on-d11/.claude/worktrees/`, not a bug in this
run's git usage.

**Resolution:** relocated the worktree to `/Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework`
— the sibling-worktree convention already used by 5 other concurrent stories in this repo
(`groups-c1`, `groups-c2`, `groups-c3`, `groups-ch80`, `groups-fix68`, `groups-136-w0`), which have
persisted stably across this same session. Re-seeded `.env`, re-ran `npm install`, verified
`docs/playbook/workflow/dual-review.sh` present on disk before writing further handoffs. Re-wrote
`survey.md`/`brief.md` (identical research/conclusions carried over in this transcript — only the
files were lost, not the analysis). Per the crash-recovery rule: re-verifying state from disk at
each phase boundary from here on, not trusting a remembered read.

### Pre-flight checklist result (`docs/playbook/workflow/preflight-checklist.md`)
| Check | Result |
|---|---|
| Node version | v26.5.0 active (no `.nvmrc`/`engines` pin in this repo) — OK |
| Native deps / `npm run local` boots | No `npm run local` script; repo serves via Docker/drush runserver (CI pattern) — N/A, verified via Docker boot at U instead |
| gitleaks installed | `gitleaks version` → 8.30.1 — OK |
| Husky/lefthook hooks active | **MISSING** — `git config core.hooksPath` empty, no `.husky/`, no `lefthook.yml`. Pre-existing repo-wide gap, not introduced by this story. **Waived** — flagged, not blocking (no story-introduced regression, no hook infra to wire into on this branch). |
| `OPENAI_API_KEY` present | Present in `.env` (seeded from primary checkout) — OK for `second-opinion` gates |
| Role subagents resolve to project's `.claude/agents/` | No `.claude/agents/` dir in this repo — role docs live at `docs/playbook/workflow/*.md` as templates. Harness-registered generic pipeline `subagent_type`s briefed explicitly with this repo's conventions (Drupal 11 module code, Playwright `tests/e2e/`, Docker/drush — not groupsdrupalorg's DDEV). |
| `dual-review.sh` real interface | Confirmed at `docs/playbook/workflow/dual-review.sh`: `--mode <brief|diff> --brief <path> --out <path> [--base <ref>] [--handoff <path>]`, env `DUAL_REVIEW=1` (set in `.env`), `OPENAI_API_KEY` (present), `DUAL_REVIEW_MODEL=o4-mini` (set), `PIPELINE_CRITIC_PROVIDER=litellm`. Matches task instruction. |
| Worktree hygiene | Branch/worktree/handoff-dir stem `0119-variant-framework` used throughout, per `naming.md`. Relocated to `_worktrees/groups-0119-variant-framework` after instability (see above); `git worktree list` re-checked clean after relocation. |
| Handoff durability | `docs/handoffs/` is **not** gitignored (only `.env` is). Journal/survey/wireframe will reach the PR. |

### Two anomalous/boilerplate docs found and disregarded
- `docs/playbook/agent/naming.md` opens with an unrelated "OpenCloud" / "Contextual Nomenclature"
  section (prohibited generic filenames, `voting-app` examples) mismatched to this repo's domain.
  The real convention below it (`NNNN-<slug>`) is coherent and matches existing worktrees on disk
  — followed that, disregarded the OpenCloud section as noise.
- Repo-root `TESTING.md` is entirely Go-language testing conventions (`_test.go`, `testify`, build
  tags) — this is a PHP/Drupal + Playwright/TypeScript repo. Disregarded as stale/mismatched, not
  followed. Both flagged here rather than silently ignored.

### Models (per task instruction)
- Orchestrator (this session) and all downstream role subagents run Sonnet, **except** the Spec
  Auditor (S), which must run Opus. Recorded so O does not let S inherit Sonnet by default, and
  does not let any other role inherit Opus.
