# Contextual Nomenclature Standards

> [!WARNING]
> **Submittability Constraint:** Generic variable and file naming is explicitly prohibited.

To guarantee zero enterprise collision states across the massive OpenCloud microservice galaxy and to eliminate AI developer ambiguity, all code assets must strictly adhere to the Contextual Nomenclature policy.

## 🚫 Prohibited Generic Names
- `data.json`
- `app.db`
- `store.sqlite`
- `main.js` (unless explicitly required by a rigid framework entrypoint)
- `utils.ts` (without a descriptive prefix/suffix)

## ✅ The Contextual Mandate
All databases, logic domains, API endpoints, SQL tables, and component files **must** be hyper-descriptive, utilizing contextual prefixes:
- **Files/Databases**: `feature-votes-store.sqlite`, `voting-feature-schema.sql`
- **Data Models**: `VotingFeatureModel`, `FeatureVoteEvent`
- **API Interfaces**: `IVoteSubmissionPayload` instead of `Payload`

### When Generic Suffixes Are Acceptable
A contextual prefix **redeems** an otherwise-generic suffix. For example:
- ❌ `app` — no context, could be anything
- ❌ `api` — misleading if the service does more than serve API routes
- ✅ `voting-app` — the `voting-` prefix provides full domain context
- ✅ `feature-votes-store.sqlite` — `feature-votes-` prefix is unambiguous

The key test: **could another extension in the same ecosystem collide with this name?** If yes, the name needs more context. If `voting-` is already unique within the OpenCloud extension galaxy, then `-app` is a perfectly valid suffix.

### The Enterprise Rationale
Generic sprawl in enterprise microservice architecture transforms shared storage networks into collision disaster zones. For example, if multiple OpenCloud extensions attempt to mount or initialize a local file named `app.db`, catastrophic data overwriting and concurrency failures will instantly occur across the container orchestrator. Descriptive prefixing prevents domain crossover natively without requiring complex logical orchestration.

---

## Issue-linked branch, worktree & run naming

Every issue-backed **branch**, its **worktree**, and its **run/handoff dir** share ONE stem so all
three are obviously the same issue at a glance:

```
NNNN-<slug>
```
- **`NNNN`** = the GitHub issue number, **zero-padded to a minimum of 4 digits** — `0019`, `0484`,
  `7211`. Issues ≥ 10000 use their natural width (`12345-<slug>`). Zero-padding keeps `git branch` /
  `git worktree list` / `ls` sorted numerically.
- **`<slug>`** = short kebab-case description (≤ ~4 words), e.g. `recall-card-round-2`.

| Artifact | Name (identical stem) |
|----------|-----------------------|
| Branch | `NNNN-<slug>` — e.g. `0484-recall-card-round-2` |
| Worktree dir | `.claude/worktrees/NNNN-<slug>` |
| Run / handoff dir | `docs/handoffs/NNNN-<slug>/` |

**Why:** one identifier in three places → `grep 0484` (or a sort) finds the branch, its worktree, and
its decision journal together. This replaces auto-generated `agent-<hash>` worktree names, which hid
which issue each belonged to and let stale ones accumulate unnoticed.

### Rules
- **No `type/` prefix on the branch** — the name *starts* with the number (that's the point). The
  conventional-commit **type** (`feat`/`fix`/…) lives in the commit messages (enforced by the
  commit-msg hook), not the branch name.
- **Folded / multi-issue runs** (one feature closing several issues): lead with the **primary** issue
  number; record the co-issues in `brief.md` + the PR "Closes". e.g. a run closing #484 + #479 →
  `0484-recall-card-round-2` (brief notes it folds #479). A two-number stem (`0484-0479-<slug>`) is
  allowed but the single-primary form is preferred.
- **Epics** — same rule with the epic number (`0483-<slug>`) for a long-lived integration branch.
- **Non-issue work** (spikes/chores with no issue) — use a **non-numeric** prefix (`x-<slug>`,
  `spike-<slug>`) so the leading-digit namespace is reserved for real issues. Prefer filing an issue.
- **One stem, three places** — reuse the exact stem across GitHub (branch), local FS (worktree), and
  the run journal; never let them drift.
- **Enforcement:** intentionally **convention-only** — no branch-name git hook or CI check. This is a
  documented convention that agents follow by reading it, not a deterministic gate. Add a branch-name
  validator (pre-push + required CI check, with an `x-`/`spike-`/`release-`/bot allowlist) **only if
  drift becomes a real problem** (decided 2026-07-01). A repo hook can't rename harness-auto
  `agent-<hash>` worktrees anyway — those are handled by convention + the Phase-11 orphan-prune.
