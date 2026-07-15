# Coding pipeline — O pre-flight checklist

> O runs these **before Phase 1** and records the result in `decisions.md`. A failed check is fixed (or
> explicitly waived with a reason) before the survey/brief. Motivated by the first-run findings
> ([`first-run-findings-2026-07.md`](first-run-findings-2026-07.md)) — several of these gaps silently
> derailed that run (Node version, agent registration, dual-review interface).

## Environment / toolchain
- [ ] **Node version matches the project pin.** `node -v` equals `.nvmrc` / `engines.node` (e.g. Node 26).
      The default shell node is often older — activate the right one and use it for **every**
      node/npm/npx call (subagents too). *Why: `better-sqlite3` and other native deps fail to `dlopen`
      under the wrong ABI — integration tests and the dev server break in confusing ways.*
- [ ] **Native deps actually load.** After confirming Node, run one DB-backed integration test AND
      `npm run local` (or the app's boot command) to confirm it **serves** — not just that unit tests
      pass. If a nested native module (e.g. `@mikro-orm/sqlite/node_modules/better-sqlite3`) is stale,
      `npm rebuild <pkg>` and rebuild the nested copy too.
- [ ] **`gitleaks` installed** (pre-commit fails closed without it) — `gitleaks version`.
- [ ] **Husky hooks active** — `git config core.hooksPath` resolves to the repo hooks.
- [ ] **Review-rigor prereqs** — `OPENAI_API_KEY` present (`.env` or env) if the story's dial is
      `second-opinion`/`panel`.

## Pipeline wiring
- [ ] **The role subagents resolve to the *project's* defs.** Confirm `subagent_type: designer /
      architecture-reviewer / tester / feature-implementor / playwright-ui-walkthrough / spec-auditor`
      spawn the repo's `.claude/agents/` versions, not stale global variants. If the session didn't
      load them (e.g. started on a pre-pipeline checkout), either reload or fall back to
      `general-purpose` + the worktree role-doc (and note it).
- [ ] **`dual-review.sh` interface is the real one.** LB's `.agents/scripts/dual-review.sh` is
      `--mode <brief|impl> --task-id <id> [--round N]`, reading `.argos/stories/<id>/brief.md` +
      `feature-handoff.md` and writing `.argos/stories/<id>/dual-*-review*.md`; default model is `o3` —
      pass `DUAL_REVIEW_MODEL=o4-mini` (or fix the config). It is **not** `--brief/--out`.
- [ ] **Worktree hygiene.** Name the branch **and** worktree dir with the same issue-linked stem
      `NNNN-<slug>` (zero-padded 4-digit issue number — [`../agent/naming.md`](../agent/naming.md)), e.g.
      branch `0484-recall-card-round-2` + `.claude/worktrees/0484-recall-card-round-2` — never an opaque
      `agent-<hash>`. The worktree has its own (or correctly symlinked) `node_modules`, `.env`, and built
      assets; a **distinct port + DB** from the primary checkout (`parallel-agent-coexistence.md`). Also:
      `git worktree list` — prune orphaned worktrees from prior crashed/finished runs so they don't accumulate.

## Handoff durability
- [ ] **Decide handoff persistence up front.** If `docs/handoffs/` is gitignored, the decision journal
      won't reach the PR and dies with the worktree — plan to un-ignore `<run-slug>/` (at least
      `decisions.md`/`survey.md`/`wireframe.*`) or archive it outside the worktree in Phase 11.

## Required project docs (existing)
- [ ] `docs/planning/SPEC.md`, `docs/planning/BUILD_PLAN.md`, `docs/handoffs/` present.

## Crash-recovery rule
- [ ] If a subagent/session **crashes mid-run**, O re-verifies state **from disk + a full test run**
      before continuing — never trust a transient/remembered read of a file (a lost in-flight edit
      caused a real bug to be committed + mis-dismissed on the first run).
