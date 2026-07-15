# Coding pipeline — first real-run findings (2026-07-01)

> Source: the first end-to-end run of the reworked **test-first coding pipeline**, on Language Buddy
> #484 + #479 (recall-card round-2), merged as PR #490 → `main` @ `4ecb0b3`, main CI green. This is the
> validation record: what worked, and the plumbing gaps to fix before the pipeline runs clean
> unattended. Companion: [`preflight-checklist.md`](preflight-checklist.md) (the actionable gate).

## What worked — the layered gates each caught a real defect the green unit suite missed
This is the core validation result: **every review layer earned its cost by catching something the
1800-test suite passed over.**
- **Test-first (T RED, executed):** 2 integration tests *passed before the feature existed* (an
  invalid RED) — caught only because RED is run and inspected, not assumed. A code-first flow ships
  those as false-green.
- **A (up-front):** caught a Tier-B-vs-Tier-A persistence trap (a new pref would have been silently
  dropped from the save POST body) + a pref-key name clash across docs — both minutes to fix up front.
- **Brief gate (o4-mini):** forced spec precision (autoplay handling, pref reactivity/clamping) before
  code.
- **Diff gate (o4-mini):** caught a real mic phase bug — `micPhase` synchronously overwritten
  `listening`→`transcribing`, so the listening state never showed and manual-stop was dead.
- **U (live):** caught `this.$cleanup(...)` throwing on *every* live card mount (`$cleanup` is not an
  Alpine 3 magic) — invisible to the unit suite because it mocks/polyfills Alpine. Fixed with the
  correct `destroy()` hook.
- **PR-Agent:** flagged an always-on-timer perf issue (a 50 ms interval per card × hundreds of cards).

Takeaway: **do not treat a green unit suite as sufficient.** The diff gate + live U + outside review
are where the real behavioral/perf defects surfaced.

## Plumbing gaps to fix (each blocked or nearly-derailed the run)

| # | Finding | Impact | Fix |
|---|---------|--------|-----|
| 1 | **Pre-flight never checks the Node version.** Project pins Node 26; the shell defaulted to Node 20; `better-sqlite3` (and a stale *nested* `@mikro-orm/sqlite` copy) fail to load under the wrong ABI. | T's integration RED silently couldn't run; the dev server (`npm run local`) crashed on boot before U. Cost several detours. | Pre-flight asserts the pinned Node (`.nvmrc`/`engines`) is active **and** that `npm run local` actually boots + one integration test loads native deps. See checklist. |
| 2 | **The LB coding-pipeline subagents aren't registered in-session.** `designer`/`architecture-reviewer`/`tester`/`feature-implementor`/`spec-auditor` resolved to stale *global* "homepage-overhaul" variants (or were absent). | Every role had to be run via `general-purpose` + the worktree role-doc as a workaround. | Ensure the session loads the repo's `.claude/agents/`; pre-flight verifies each `subagent_type` slug resolves to the LB def before Phase 1. |
| 3 | **`dual-review.sh` doc ≠ reality.** The pipeline doc shows `--brief <file> --out <file>` + default `o4-mini`; the actual `.agents/scripts/dual-review.sh` uses `--mode <brief\|impl> --task-id <id>` over `.argos/stories/<id>/` and defaults to **`o3`**. | The brief gate errored ("Unknown flag: --brief") on first invocation; had to reverse-engineer the real interface + force `DUAL_REVIEW_MODEL=o4-mini`. | Corrected in `workflow-coding-pipeline.md` §Review-rigor mechanics; bump the script's config default `o3`→`o4-mini`. |
| 4 | **U (playwright-ui-walkthrough) can write files via Bash**, despite being "read-only." A killed U mid-run had edited production code + added a test + left a scratch `.mjs` in the repo. | Blurred the U/F/T boundary; O had to sort out which of U's edits to keep. (The fix U made was correct — but that's luck, not design.) | Hard-constrain U to read-only: no writes to `src/**`/tests (even via Bash); scratch drivers go to `/tmp`. Add to `ui-walkthrough.md` + O's spawn prompt. |
| 5 | **`docs/handoffs/` is gitignored**, so the decision journal + handoffs never reach the PR and are deleted with the worktree. | The audit trail (the pipeline's durable "why") was nearly lost on worktree teardown; had to be hand-copied out. | Either un-ignore `docs/handoffs/<run-slug>/` (at least `decisions.md`, `survey.md`, `wireframe.*`), or have Phase 11 archive it outside the worktree before teardown. |
| 6 | **Phase 11 has no worktree teardown or orphan-prune.** Phase 11 says only "delete the merged branch." The `git worktree remove` rule lives only in `parallel-agent-coexistence.md` and isn't cross-referenced. | 11 stale worktrees from prior crashed/finished runs had accumulated; cleanup was a separate manual ask. | Phase 11 now removes the run's worktree after branch deletion + prunes orphaned worktrees (see the pipeline-doc edit). |
| 7 | **Agents hand-author SVG icon geometry** (D shipped invalid-XML `----` comments + malformed hand-drawn glyphs across 3 revisions). | Repeated wireframe rework. | **Fixed already:** new [`../agent/svg-glyphs.md`](../agent/svg-glyphs.md) (Unicode/known-good paths, define-once + `<use>`, **render-and-look**) + a rule in `designer.md`. Generalizes to "verify any artifact you emit blind." |

## Process observations (kept for the pipeline owner)
- **Crash resilience:** the machine crashed mid-F; O recovered by *verifying the worktree + running the
  suite* rather than trusting/re-spawning blindly. Lesson: **after any crash, O must re-verify state
  from disk/tests, not from memory** (O once mis-dismissed the `$cleanup` finding by trusting a
  transient read — corrected only when U caught it live).
- **Committing to the feature branch mid-pipeline** (before final gates) was necessary so the diff gate
  + A-dup had a real diff and for crash-resilience; the "commit only after gates" rule is about `main`,
  not the feature branch. Worth stating explicitly.
- **Folding two issues into one run** (#479 into #484) worked once the base-branch ambiguity was
  surfaced up front — a near-miss that would have wasted a whole run if committed to silently.
