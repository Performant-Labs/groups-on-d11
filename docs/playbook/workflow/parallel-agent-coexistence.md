# Parallel Agent Coexistence — Worktree Protocol

> **Purpose:** Defines how to run two or more coding agents against the same repository at the same time without interference. Worktrees isolate *files*; this protocol isolates the *runtime* (database, ports, env) and defines the *merge hygiene* that keeps two branches from colliding. Includes a copy-paste prompt template for the secondary agent.

---

## When to use

Use this whenever a second agent (or a second interactive session) will be editing the same repo while a first agent is still active. The default and strongly preferred mechanism is **one git worktree per agent** — it is the approach recommended in Claude Code's own docs and the convergent practice across the parallel-agent community.

Do **not** run two agents in the same working directory on the same branch. Worktrees share one `.git` but give each agent its own checkout and its own branch, eliminating working-tree and index collisions.

---

## The trap: worktrees isolate files, not the runtime

A worktree gives each agent its own files and branch. It does **not** automatically isolate the resources that live *outside* git. These are where parallel agents actually break each other:

| Shared resource | Failure mode | Mitigation |
|-----------------|--------------|------------|
| **Database** (e.g. dev/test SQLite file) | One agent regenerates the schema → corrupts the other's runtime mid-task | Each worktree gets its **own** DB file (and its own test DB) |
| **Ports** (dev server, CSS watch, Playwright base URL) | Two processes bind the same port → one fails or serves stale output | Assign a **distinct port offset** per worktree |
| **Gitignored config** (`.env`, secrets, built assets) | Fresh worktree starts half-configured; agent flails | **Seed** these into the new worktree as step one |
| **External services / quotas** (gateways, API keys, rate limits) | Both agents hit the same backend; bulk jobs throttle each other | Aware-only for light use; separate keys/quotas for heavy use |
| **Shared config files** (`package.json`, lockfile, `tsconfig`, `CLAUDE.md`, schema) | Both edit → guaranteed conflict on the gnarliest files to merge | **Single owner**; the other stops and asks before touching |

The recurring lesson from practitioners: *the trap is the shared runtime, not the code.* Agents that isolate only files still get bitten by a shared DB, port, or `.env`.

---

## Operator setup (before launching the secondary agent)

1. **Snapshot WIP first.** Commit or stash any dirty state in the primary checkout so the second agent never starts on top of uncommitted work.
2. **Create the worktree** from the integration branch. Name the branch **and** the worktree dir with
   the **same issue-linked stem** `NNNN-<slug>` (zero-padded 4-digit issue number) — see
   [`../agent/naming.md`](../agent/naming.md) §Issue-linked branch, worktree & run naming — so the pair
   is obviously the same issue, never an opaque `agent-<hash>`:
   ```bash
   # stem = NNNN-<slug>, e.g. 0484-recall-card-round-2
   git worktree add .claude/worktrees/<NNNN-slug> -b <NNNN-slug> <BASE_BRANCH>
   ```
3. **Install deps** in the worktree (separate `node_modules`):
   ```bash
   cd <WORKTREE_DIR> && <INSTALL_CMD>     # e.g. npm install
   ```
4. **Seed gitignored config** into the worktree (`.env`, local secrets, any built assets the app needs to boot).
5. **Isolate the runtime** — give the worktree its own DB file and a distinct port offset (set in its `.env` / local config). Never let it point at the primary checkout's DB.
6. Launch the agent **in `<WORKTREE_DIR>`** with the prompt below.

> **Teardown:** when the branch has merged, `git worktree remove <WORKTREE_DIR>` from the primary checkout. Never `git worktree remove`/`prune` a sibling that's still in use.

---

## Merge / coordination hygiene

- **Disjoint file ownership** is the single most effective conflict-avoider. Agree up front which paths each agent owns; the other treats them as read-only.
- **Short-lived branches, merge often.** The longer both run, the more the base diverges. Prefer small scope + fast landing over two long-running mega-branches.
- **Designate merge order.** When both PRs are ready, decide who merges first; the other rebases onto the new base.
- **Single owner for shared/config files** (lockfile, `package.json`, `tsconfig`, schema, shared modules). If the other must touch one, it stops and asks.
- **No *opportunistic* refactors.** Scope discipline keeps disjoint ownership from quietly breaking — agents fix their task and don't "while I'm here" edit shared code. (This bars the *unplanned* drive-by, **not** a deliberate refactor-to-extend that the brief specified and A reviewed up front — that is the coding pipeline's reuse-first default, see `workflow-coding-pipeline.md` §"Reuse-first research". The test is *planned vs. snuck-in*: a spec'd extension of a shared module you own is fine; an unplanned edit to a shared/other-agent-owned module is the drive-by — stop and ask.)
- **Namespace handoff/scratch docs** so concurrent agents don't overwrite each other (e.g. unique phase slug per agent).

---

## Two stages: explore, then implement

You do **not** need to know either agent's task before launching them. Knowing the task matters only for *merge conflicts* (file overlap), and **conflicts only materialize when agents write.** That decouples the work into two stages:

| Stage | Agents | Conflict risk | What you need upfront |
|-------|--------|---------------|-----------------------|
| **1 — Exploration** | Read-only; survey and propose | None (no edits) | Nothing — the task is the *output* |
| **2 — Implementation** | Write code on disjoint scopes | Real | The chosen tasks + their file footprints |

Use Stage 1 when you want to *discover* what to build next. Each agent surveys the repo from a different angle and returns a written proposal including the **file footprint** each candidate would touch. Those footprints are exactly the input you need to carve **disjoint scopes** in Stage 2 — which is when the full implementation template below applies, with `<TASK>` and `<PROTECTED_PATHS>` filled from what the agents reported.

Give each agent a **different angle** (e.g. one on features/UX, one on tech-debt/tests) so the proposals are complementary rather than redundant.

### Stage 1 — exploration prompt template

Read-only; the guard is *"propose, don't implement"* — there is no ownership/scope section to fill because nothing gets written.

```
You are in a dedicated git worktree at <WORKTREE_DIR> on branch <BRANCH>. Another
agent is exploring this same repo concurrently in a separate worktree. We share one
.git but separate working trees.

THIS IS A READ-ONLY EXPLORATION TASK. Do NOT edit, create, or commit any files. Your
deliverable is a written proposal, not code.
- Stay in <WORKTREE_DIR>. Don't cd elsewhere or switch branches.
- Read freely. Run read-only commands (typecheck, tests, greps) if useful, but change
  nothing on disk.

GOAL
Survey the codebase and <BACKLOG_SOURCE — e.g. docs/planning/tasks.md, open issues,
recent diffs> and propose the best next thing(s) to work on. <OPTIONAL ANGLE — e.g.
"focus on UX gaps" / "focus on test coverage" / "focus on tech debt">.

RETURN
- 2-4 candidate tasks, each with: what & why, rough scope, and the FILE FOOTPRINT it
  would touch (so scopes can be made disjoint before implementation).
- Your top recommendation and the reasoning.
```

---

## Stage 2 — implementation prompt template

Fill the `<PLACEHOLDERS>` and paste as the opening message to the secondary agent, started **in its worktree directory**.

```
You are working in a dedicated git worktree at <WORKTREE_DIR> on branch <BRANCH>,
branched from <BASE_BRANCH>. One or more other agents are running concurrently in
other checkouts/branches of this same repository. We share one .git but have
separate working trees. Follow these rules so we don't interfere.

ISOLATION CONTRACT
- Stay in this worktree directory (<WORKTREE_DIR>). Never `cd` into another checkout,
  and never `git checkout`/switch this worktree to another branch.
- Commit only on <BRANCH>. Do not commit, rebase, or force-push <BASE_BRANCH> or any
  other agent's branch. Never `git worktree remove`/`prune` a sibling checkout.
- Use THIS worktree's own database — a separate DB file from any other checkout. You
  may regenerate YOUR OWN schema freely; never touch another checkout's DB.
- Bind NON-DEFAULT ports for any dev server / asset watcher / e2e base URL — assume the
  defaults are already taken by another agent. (Use the offset configured in this
  worktree's local env.)
- As your FIRST step, confirm gitignored config is present (.env, secrets, built
  assets). If the app can't boot, seed them before doing task work.

OWNERSHIP & SCOPE
- Treat these paths as read-only context — do NOT edit them: <PROTECTED_PATHS>.
  If your task genuinely requires changing one, STOP and ask first.
- Do NOT edit shared config files (package.json, lockfile, tsconfig, CLAUDE.md, schema)
  without stopping to ask — they are merge magnets owned elsewhere.
- Keep scope narrow. No opportunistic / drive-by refactors of shared modules. (A *spec'd*
  refactor-to-extend named in your brief and reviewed by A up front is allowed — that is reuse-first,
  not a drive-by. An *unplanned* edit to a shared/other-agent-owned module is not: stop and ask.)
- Expect to rebase: another branch may merge before yours. Keep commits small and land
  fast rather than accumulating a long-running branch.

PROJECT CONVENTIONS
- <PROJECT_PIPELINE_AND_DOCS — e.g. "This repo uses the coding pipeline in CLAUDE.md;
  read it and the referenced playbook docs before implementing. Never modify
  docs/planning/*. Write any handoff to docs/handoffs/<your-unique-slug>-F.md.">

YOUR TASK
> <TASK — what to build, issue #, acceptance criteria, relevant files>

WHEN DONE
- Run <VERIFY_CMDS — e.g. `npm run typecheck` and `npm test`>, write the handoff, and
  stop without committing to a protected branch.
```

---

## Placeholder reference

| Placeholder | Meaning |
|-------------|---------|
| `<WORKTREE_DIR>` | Absolute or sibling path of the secondary worktree, e.g. `../<repo>-wt2` |
| `<BRANCH>` | The secondary agent's branch |
| `<BASE_BRANCH>` | Branch the worktree was cut from (usually `main`) |
| `<PROTECTED_PATHS>` | Paths the primary agent is actively editing — the secondary must not touch |
| `<BACKLOG_SOURCE>` | (Stage 1) Where candidate work lives — backlog file, open issues, recent diffs |
| `<OPTIONAL ANGLE>` | (Stage 1) The lens this agent explores from, to keep proposals complementary |
| `<PROJECT_PIPELINE_AND_DOCS>` | Project-specific pipeline + required reading + handoff location |
| `<TASK>` | The secondary agent's actual task, with acceptance criteria |
| `<VERIFY_CMDS>` | Project verification commands |
| `<INSTALL_CMD>` / `<VERIFY_CMDS>` | Project package-manager + check commands |
