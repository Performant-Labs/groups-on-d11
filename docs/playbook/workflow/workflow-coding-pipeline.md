# The Coding Pipeline — Multi-Agent Workflow

> **Purpose:** Defines the single Performant Labs **coding pipeline** for phased project
> implementation. Each build-plan phase (one feature) becomes one cycle through this pipeline.
> The Orchestrator drives the pipeline by spawning the D/A/T/F/U/S subagents itself; the human
> operator's role is approval at gates, not relay.
>
> **This pipeline is test-first.** The Tester authors the suite from the approved spec +
> wireframe and confirms it fails (RED) *before* the Feature implementor writes a line of
> production code; T then re-runs to confirm GREEN. Architecture review happens **up front**,
> on the brief and wireframe, before code exists. A conditional **Design** phase produces a
> human-approved wireframe for any UI surface before tests or code.
>
> **Testing is split in two:** **T (Tester)** owns the automated, headless half (Tier 1 +
> Tier 2 — build, lint, tests, coverage, types, contracts) and now *brackets* implementation
> (author/RED before F, verify/GREEN after F); **U (UI Walkthrough)** runs the live-browser
> half — driving every interactive control on the real SPA-navigation path and checking the
> built UI against the approved wireframe. T's green suite never opens a browser, so client
> behavior (Alpine/HTMX init, swaps, reactive bindings) is invisible to it; U is the gate that
> catches it. U runs only when the change touches a UI surface.

> **Renamed & consolidated (2026-06).** This document replaces the former three-doc OFATS
> family — `workflow-ofats-generic.md`, `workflow-ofats-dual-review.md`, and
> `workflow-ofats-tri-review.md` — which were `git rm`'d when this doc landed. The name
> "OFATUS" / "OFATS" is retired as a product name; **`O-D-A-T-F-T-U-S`** survives only as an
> internal phase-order shorthand. The dual/tri-review *variants* are replaced by the single
> per-story **review-rigor dial** (`none` / `second-opinion` / `panel`) defined below; the
> outside-reviewer runner [`dual-review.sh`](dual-review.sh) is unchanged and is the engine
> behind `second-opinion` and `panel`. Shared primitives (decision journal, role-doc template,
> gate mechanics, review-rigor definition) live in [`pipeline-conventions.md`](pipeline-conventions.md)
> and are not duplicated here — read it first.

---

## Phases

The pipeline has **eleven** numbered phases. Each runs once per cycle; rework loops re-enter at
the relevant phase (see [Rework Flow](#rework-flow)). Phases keep their number even when an
N/A skip applies (D, U). The internal phase-order shorthand is **O → D → A → T → F → T → A → U → S**
(the two T's are the *author/RED* and *verify/GREEN* halves of one role; the second A is the
anti-duplication gate).

| Phase | Owner | Output |
|-------|-------|--------|
| **1 — Survey & Brief** | O | `survey.md` (incl. Reuse & Analogous-Feature map + forward-compat table when applicable); `brief.md`; GitHub issue + branch with acceptance criteria; `decisions.md` created |
| **2 — Design (wireframe)** | D | `wireframe.*` + `handoff-D.md`; **human approval gate** — **conditional**: runs when the change has a UI surface, else N/A |
| **3 — Architecture review (up front)** | A | `handoff-A.md` reviewing the brief + approved wireframe against existing patterns (PASS / BLOCK) — **before** tests/code |
| **4 — Author tests (RED)** | T | failing test suite + `handoff-T-red.md`; T confirms the suite fails for the right reason |
| **5 — Implement** | F | code + `handoff-F.md`; F implements against the brief + wireframe + failing tests until green (**F writes no tests**) |
| **6 — Verify tests (GREEN) + Tier 2** | T | `handoff-T-green.md` (independent Tier 1 GREEN + Tier 2, automated/headless) |
| **7 — Anti-duplication gate** | A | `handoff-A-dup.md` — A confirms F **extended** the analogous feature rather than building a parallel path (PASS / BLOCK) — **before** the expensive U/S audits |
| **8 — UI Walkthrough** | U | `handoff-U.md` (live-browser behavior on the SPA-nav path **+ built-UI-vs-wireframe check**; PASS / REWORK) — **conditional**: UI surfaces only, else N/A |
| **9 — Spec audit** | S | `handoff-S.md` (Tier 3 visual/WCAG/spec; PASS / REWORK / ADVISORY-HOLD) |
| **10 — PR, PR-side review, & close** | O | merged PR (after CI + automated review resolved); Chain Summary appended to `decisions.md` |
| **11 — Post-merge sweep** | O | `main` verified advanced + green; branch cleaned; next story unblocked |

> **Conditional & always-on parts** (formerly the dual-review additions; now standard):
> - **Forward-compat check** (in Phase 1) — runs when the story creates a shared
>   component/partial/contract later stories consume. Conditional; recorded in `survey.md`.
> - **Written brief** (Phase 1) — **always**. The brief is the issue body F implements against
>   and declares the review-rigor dial (§Review-rigor).
> - **Review-rigor gates** — per story, `none` / `second-opinion` / `panel` (§Review-rigor),
>   applied at the brief (after Phase 1) and at the diff (after Phase 6).

> **Phase 2 (Design) is a human approval gate when applicable.** It runs whenever the change
> has a UI surface. D produces (or ingests) a wireframe and O presents it to the operator for
> explicit sign-off **before** any tests or code. Silence ≠ approval. When there is no UI
> surface, O records "Phase 2: N/A (no UI surface)" and proceeds to A — a skip must be a
> declared, justified N/A, never silent. See [`designer.md`](designer.md).

> **Phase 7 (anti-duplication gate)** is A's second pass, and it runs **before the expensive
> U/S audits** — duplication is a structural problem, so catching a parallel-path BLOCK here means
> a dup rejection doesn't waste a live-browser walkthrough (U) plus a visual/WCAG audit (S). A
> re-reads the diff against the Reuse & Analogous-Feature map from Phase 1 and **BLOCKs if F
> created a parallel path** (a new object that duplicates an existing one) where the map called for
> extending/refactoring an existing object. See
> [§Reuse-first research](#reuse-first-research-and-the-anti-duplication-gate) and
> [`architecture-reviewer.md`](architecture-reviewer.md).

> **Phase 8 (UI Walkthrough) is a hard gate when applicable.** It runs whenever the diff
> touches a UI / client-behavior surface — templates/views, client bundles or component
> factories (`src/client/**`, Alpine/Stimulus), HTMX/Alpine directives, CSS affecting
> layout/visibility, or any HTML-rendering route. U drives the live UI in a real browser,
> reaching each page **via SPA navigation (not a hard reload)**, exercising **every**
> interactive control, checking console + reactive state, **and confirming the built UI matches
> the approved wireframe**. A `REWORK` respawns F; O does **not** advance to S until U passes.
> No UI surface → "Phase 8: N/A". **Default backend: `playwright-ui-walkthrough`** (Playwright
> MCP — portable, local + unattended); the Preview-based `ui-walkthrough` is a local-interactive
> alternative. See [`ui-walkthrough.md`](ui-walkthrough.md).

> **Phase 10 closes the GitHub-side loop.** If the work goes through a PR, O does not treat the
> in-session gates as sufficient: it waits for the PR's own CI checks and any automated PR
> review to complete, reads them, drives fixes for any red check / unresolved finding, and only
> then requests merge. A story is not done until the PR's own checks are green and its reviews
> are resolved; absence of CI/PR-review is stated explicitly, never treated as a pass.

> **Phase 11 (post-merge sweep).** After merge, O verifies `main` actually advanced and contains
> the merged code (don't trust the "merged" badge — a PR based on the wrong branch merges into
> *that* branch, not `main`), confirms `main`'s own CI is green, deletes the merged branch (or
> confirms auto-delete), **removes the run's worktree (`git worktree remove` from the primary
> checkout — archive `decisions.md`/`survey.md`/`wireframe.*` first if `docs/handoffs/` is gitignored)
> and prunes any orphaned worktrees left by prior crashed/finished runs (`git worktree list` →
> remove stale ones; never remove one still in use)**, updates the task tracker, unblocks/queues the
> next story, and writes the **Chain Summary** to `decisions.md`. (Worktree teardown was previously
> documented only in [`parallel-agent-coexistence.md`](parallel-agent-coexistence.md) and not
> cross-referenced here — which is why stale worktrees accumulated; see
> [`first-run-findings-2026-07.md`](first-run-findings-2026-07.md) §6.)

---

## Pipeline Overview

```text
O (Orchestrator)
|  runs pre-flight checks; creates decisions.md
|  surveys code → Reuse & Analogous-Feature map (+ forward-compat table if applicable)
|  writes brief.md (declares review-rigor); creates issue + branch
v
D (Designer)              [conditional — only if a UI surface is involved]
|  produces a low-fi wireframe (or ingests the user-supplied one as source of truth)
|  O presents wireframe → HUMAN APPROVAL GATE (no code until approved)
|  writes handoff-D.md
v
[review-rigor: brief gate — none / second-opinion / panel]
v
A (Architecture Reviewer)         — UP FRONT, before tests/code
|  reads brief + approved wireframe + Reuse map; audits the PLAN against existing patterns
|  writes handoff-A.md with PASS or BLOCK
v
T (Tester — AUTHOR / RED)
|  authors the test suite from the approved brief + wireframe
|  runs it and confirms it FAILS for the right reason (RED)
|  writes handoff-T-red.md
v
F (Feature Implementor)
|  reads brief + wireframe + the failing tests
|  implements against them until green; WRITES NO TESTS
|  writes handoff-F.md
v
T (Tester — VERIFY / GREEN + Tier 2)
|  re-runs the suite: confirms GREEN; runs Tier 2 (coverage, types, contracts, security)
|  writes handoff-T-green.md
v
[review-rigor: diff gate — none / second-opinion / panel]
v
A (Anti-duplication gate)         — BEFORE the expensive U/S audits
|  re-reads diff vs Reuse map; BLOCKs a parallel path that should have extended
|  writes handoff-A-dup.md
v
U (UI Walkthrough)        [conditional — only if a UI surface was touched]
|  drives the live UI on the SPA-nav path, exercising every control;
|  checks console + reactive state AND built-UI-vs-approved-wireframe
|  writes handoff-U.md with PASS or REWORK
v
S (Spec Auditor)
|  runs Tier 3 visual / WCAG / spec compliance + final quality audit
|  writes handoff-S.md
v
O (Orchestrator)
   opens PR, closes PR-side CI/review loop, merges, sweeps main,
   writes Chain Summary to decisions.md
```

**The Orchestrator drives the pipeline.** O spawns each downstream agent via the Agent tool
(`subagent_type: designer` / `architecture-reviewer` / `tester` / `feature-implementor` /
`playwright-ui-walkthrough` / `spec-auditor`), passing the relevant handoff path in the spawn
prompt. The `subagent_type` slugs are **stable** — they survived this rename; downstream repos
reference them. O reads each handoff when the subagent returns, decides whether to advance or
rework, and consults the human only at the **Design approval gate**, other build-plan Approval
Checkpoints, or when an external decision is required. (Fallback for clients without subagent
access: see [§Manual-Relay Fallback](#manual-relay-fallback).)

---

## Phase 1 detail — Survey & Brief (O)

**Pre-flight first.** Before the survey, O runs [`preflight-checklist.md`](preflight-checklist.md) and
records the result in `decisions.md` — it verifies the pinned **Node version** is active and native
deps + `npm run local` actually boot, that gitleaks/husky/`OPENAI_API_KEY` are present, that the role
**subagent slugs resolve to the project's `.claude/agents/`**, and the real `dual-review.sh` interface.
(These gaps silently derailed the first run — see [`first-run-findings-2026-07.md`](first-run-findings-2026-07.md).)

Before O writes the brief, it reads the existing code for every file the phase will touch. A
brief written without reading the actual code is speculation — the survey grounds it in what
exists today, not what the spec imagines.

O reads:
1. Every file listed in the build-plan phase as in-scope for modification or creation.
2. The layout / shell files those files inherit from (base templates, shared layouts, route
   registration files).
3. Existing tests that cover those files — the brief must name any test that will break or need
   updating.
4. Any existing components the phase proposes to create (confirm they don't already exist in a
   different form under a different name).

### Reuse & Analogous-Feature map (required)

The survey's most load-bearing output. See
[§Reuse-first research](#reuse-first-research-and-the-anti-duplication-gate) for the full
contract. In short, O must:
- **Map relevant code** the change interacts with.
- **Name the closest analogous feature** already in the repo and the objects it touches.
- Give an explicit **extend-vs-new recommendation that defaults to extend/refactor** an existing
  object — a *new* object is only allowed when justified in writing in the survey.

### Forward-compat check (conditional)

Runs when the story **creates a shared component, template partial, design pattern, or API/data
contract** that later stories will consume. For each downstream consumer, extract the
parameter/return shape, variants, and context-specific differences it needs; confirm the proposed
design satisfies all of them; if two consumers conflict, **halt and raise with the operator**.
Output: a `consumer story | required capability | satisfied (yes/no/needs discussion)` table in
`survey.md`. Skip (record "Forward-compat: N/A") for stories that only modify existing files.

### The brief

O writes `docs/handoffs/<run-slug>/brief.md` — the issue body F implements against. Template:

```markdown
## Phase [N] — [Title]

**Branch:** [branch-name]
**Review-rigor:** none | second-opinion | panel   (if not none: why — risk/size justification)
**Forward-compat:** done — see survey.md | N/A
**Design (Phase 2):** wireframe approved — see handoff-D.md | N/A (no UI surface)

### Objective
[One sentence describing the deliverable.]

### Codebase survey & Reuse map
Read before writing code: `docs/handoffs/<run-slug>/survey.md`
Closest analogous feature: [name] — extend `[object]` (default) | new object justified: [why]
Key findings: [1–3 bullets — conventions to preserve, tests that will break, surprises]

### Approved wireframe
[Path to wireframe.* + handoff-D.md, or "N/A — no UI surface"]

### Input documents
- [ ] [spec section]
- [ ] [build plan phase]
- [ ] [playbook standards relevant to this story]

### Acceptance criteria
[Copied from the build plan phase, as checkboxes.]

### Handoff locations
T-red writes: `docs/handoffs/<run-slug>/handoff-T-red.md`
F writes:     `docs/handoffs/<run-slug>/handoff-F.md`

### Operating rules
- Read the survey + Reuse map before writing any code; don't rely on the spec alone.
- **Extend the named analogous object** unless the survey justified a new one. A parallel
  path where the map called for an extension is an anti-duplication BLOCK (Phase 7).
- Implement against the failing tests authored in Phase 4 (test-first) — F writes no tests.
- Follow project conventions; read source before referencing types/schemas/APIs.
- Stage files by explicit path, never `git add .`.
```

The brief declares the review-rigor dial. Every phase appends to `decisions.md` (§Decision journal).

---

## Phase 2 detail — Design / wireframe (D, conditional)

D runs whenever the change has a UI surface. It produces a human-approved wireframe **before**
tests or code, so T authors tests and F implements against an agreed visual/behavioral target
rather than discovering it late in U or S. Two modes:

- **(a) Pipeline-generated low-fi wireframe.** D produces a low-fidelity wireframe — SVG/HTML or
  ASCII — of the screens/states the change introduces or alters, and O presents it to the operator
  for sign-off. The approved file becomes source of truth.
- **(b) User-supplied wireframe.** The operator supplies a wireframe up front. D ingests it,
  confirms it covers the states the change needs (empty/one/many/error where relevant), and it
  becomes source of truth without a generation step. O still records explicit approval.

Either way the output is a stable artifact (`docs/handoffs/<run-slug>/wireframe.*`) plus
`handoff-D.md`, and an **explicit human approval** recorded in `decisions.md`. No tests or code
start until the wireframe is approved. When there is no UI surface, O records "Phase 2: N/A (no
UI surface)" and proceeds to A. See [`designer.md`](designer.md).

---

## Phase 3 detail — Architecture review, up front (A)

A now reviews the **plan** before any code exists: the brief, the approved wireframe, and the
Reuse & Analogous-Feature map, audited **against the patterns that already exist** in the project
source tree and planning docs. A confirms the proposed approach (the object to extend, the layer
it lives in, the contract shape) is consistent with neighboring code *before* T writes tests
against it and F implements it — catching layering/placement/naming drift when it costs minutes
instead of a rework cycle. A returns PASS / BLOCK; BLOCK routes back to O to amend the brief/Reuse
map (and re-present the wireframe if it changed). A reviews the diff again at Phase 7 (the
anti-duplication gate). See [`architecture-reviewer.md`](architecture-reviewer.md).

---

## Phase 4 detail — Author tests, RED (T)

T authors the test suite from the **approved brief + wireframe** *before* F writes production
code. T then runs the suite and confirms it **fails for the right reason** (the assertion the
feature will satisfy fails, not a setup/import error). A suite that passes before the feature
exists, or fails for an unrelated reason, is not a valid RED — T fixes the test, not the absence
of the feature. T's RED handoff names each test, the behavior/acceptance-criterion it pins, and
the exact failing output. F implements against these tests. T owns all test authorship; **F writes
none.** See [`tester.md`](tester.md).

---

## Phase 6 detail — Verify tests, GREEN + Tier 2 (T)

After F implements, T re-runs the same suite independently and confirms it now **passes (GREEN)**,
then runs Tier 2 (coverage vs. acceptance criteria, test quality, type safety, error handling,
data integrity, API contracts, security, migration safety). T also re-checks that the suite still
fails if F's change is reverted in spirit — i.e. the tests pin behavior, not implementation. T's
test-quality duties are unchanged: flag invalid/redundant tests for deletion or merge, not just
missing coverage. See [`tester.md`](tester.md).

---

## Review-rigor (replaces the dual/tri-review variants)

There is **one** pipeline; rigor is a per-story **dial**, not a separate pipeline. The dial is
defined in [`pipeline-conventions.md` §3](pipeline-conventions.md). Each story's brief declares it:

| Dial | What runs at each gate | Use when |
|------|------------------------|----------|
| **`none`** | In-session review only (A / T / U / S). No outside reviewer. | Thin read-and-render, templating, mechanical changes. |
| **`second-opinion`** | **+ one outside model** (o4-mini via [`dual-review.sh`](dual-review.sh)) reviews the brief and the diff. `hard` findings block. | Foundational / component-creating, security-sensitive, large/high-blast-radius, subtle-correctness. |
| **`panel`** | **+ a cross-vendor pair** on a **byte-identical** prompt: o4-mini (cross-vendor) **and** a fresh-context Opus subagent (same-vendor), plus a **reconciliation** artifact. | High-stakes changes where both an outside-vendor and a same-vendor opinion, and the o4-mini-vs-Opus comparison, earn their cost. |

**Two gate points** (unchanged from the old Phases 4 & 7): the **brief gate** fires after Phase 1
(a flaw caught in the brief costs minutes; the same flaw caught in the diff costs an
implement-and-rework cycle), and the **diff gate** fires after Phase 6 (T-green), before U/S. A
`hard` finding at the brief gate is resolved (brief amended, or recorded why rejected) before
Phase 2/3; a `hard` finding at the diff gate blocks merge — O respawns F to fix, and the cycle
re-enters at A.

**Mechanics of the outside-reviewer run.** [`dual-review.sh`](dual-review.sh) is the canonical,
project-agnostic runner for both `second-opinion` and `panel`:

```bash
# brief gate (second-opinion)
DUAL_REVIEW=1 dual-review.sh --mode brief --brief <brief.md> --out <review.md>
# diff gate (second-opinion)
DUAL_REVIEW=1 dual-review.sh --mode diff --brief <brief.md> --base <gitref> --out <review.md>
```

> ⚠ **Repo-wired implementations may differ — check the actual script.** As of the first LB run, the
> wired `.agents/scripts/dual-review.sh` does **not** take `--brief/--out`; it is
> `--mode <brief|impl> --task-id <id> [--round N]`, reading `.argos/stories/<id>/brief.md` (+
> `feature-handoff.md` for `impl`) and writing `.argos/stories/<id>/dual-{brief,impl}-review[-2].md`,
> with default model **`o3`** (pass `DUAL_REVIEW_MODEL=o4-mini`). Round 2 reads the O response at
> `.argos/stories/<id>/argos-{brief,impl}-response.md`. Reconcile the doc and the script; until then,
> read the script's usage header before invoking. See [`first-run-findings-2026-07.md`](first-run-findings-2026-07.md) §3.

For **`panel`**, capture the canonical prompt **once** with `--dump-only` (writes a
`${OUT}.prompt.txt` sidecar), then fan out **both** arms on that one file so they see a
byte-identical snapshot: the o4-mini arm via `--prompt-file <sidecar>`, and the Opus arm as a
fresh, **read-only** subagent fed the sidecar verbatim. The o4-mini prompt is canonical (Opus
reviews under the same rubric), which is what makes the comparison controlled.

| Var | Purpose |
|-----|---------|
| `DUAL_REVIEW` | `1` enables; unset/`0` makes the script a no-op |
| `OPENAI_API_KEY` | required for the o4-mini arm; sourced from `.env` if present |
| `DUAL_REVIEW_MODEL` | model override (default `o4-mini`; `o*` models run at `reasoning.effort=high`) |

**Disposition (O owns it).** The outside review **advises**; it does not edit code or commit. A
`hard` finding real in *either* arm is folded; disagreement between arms is adjudicated explicitly
in the reconciliation artifact. The gate does **not** replace A / T / U / S — it is an *additional*
outside opinion. A gate that returns no `hard` findings is a pass; silence is not a reason to add
findings.

**Reconciliation / comparison deliverable.** When a gate ran, O writes a comparison to
`docs/handoffs/<run-slug>/review-comparison.md`:
- **`panel`** compares the two *outside* reviewers (o4-mini vs Opus) on the byte-identical prompt —
  a controlled **model** comparison plus a reconciliation of disagreements.
- **`second-opinion`** compares the one outside reviewer (o4-mini) against the in-pipeline A/T/S
  gates — a **gate-ROI** read (what o4-mini caught that A/T/S also caught, what only o4-mini caught,
  what only A/T/S caught, real-vs-noise, and a net "did the gate earn its cost" line). **Caveat to
  state in the report:** this is *not* a controlled model comparison — o4-mini and A/T/S receive
  different inputs (raw adversarial prompt vs. role-specific prompts with pipeline context), so it
  measures gate coverage/ROI, not model skill. Only `panel`'s identical prompt supports the
  model-skill read.

**Loop discipline.** Each gate declares a retry cap; on a still-unresolved `hard` finding after
round 2 (`--round 2 --response <response.md>`), escalate to the operator rather than spinning.

---

## Reuse-first research and the anti-duplication gate

The pipeline defaults to **extending existing code, not adding parallel code.** Two mechanisms
enforce it.

### O's Reuse & Analogous-Feature map (Phase 1)

The survey must include, as a dedicated section in `survey.md`:

```markdown
### Reuse & Analogous-Feature map
- **Relevant code mapped:** [files/modules the change interacts with, one line each]
- **Closest analogous feature:** [name] — implemented in [file(s)], objects: [classes/functions/components]
- **Objects this change would touch:** [list]
- **Extend-vs-new recommendation:** EXTEND `[object]` (default)
  | NEW `[object]` — justification: [why an existing object genuinely cannot be extended/refactored]
```

The default is **extend/refactor an existing object**. A *new* object is permitted only when the
survey justifies in writing why no existing object can be extended (e.g. a genuinely different
domain concept, a contract that would be polluted, an extension that would violate a layer
boundary). "It was faster to start fresh" is not a justification.

### A's anti-duplication gate (Phase 7)

After T-green (and the diff review-rigor gate), and **before** the U/S audits, A re-reads the diff
against the Reuse map and returns PASS / BLOCK:
- **BLOCK** when F created a **parallel path** — a new object that duplicates the behavior of the
  analogous object the map named — where the map called for extending/refactoring it, and the
  brief did not justify a new object. The fix is to fold the new path into the existing object.
- **PASS** when F extended the named object, or built a new object the survey/brief explicitly
  justified.

### Reconciliation with the parallel-agent coexistence protocol

[`parallel-agent-coexistence.md`](parallel-agent-coexistence.md) bars **"no opportunistic /
drive-by refactors of shared modules"** (§Merge hygiene, §Stage-2 template). That rule and the
reuse-first default are **complementary, not contradictory**:

- A **deliberate, spec'd refactor-to-extend** named in the survey/brief and reviewed by A up front
  (Phase 3) is *desired* — it is the planned way to avoid duplication, not a drive-by.
- An **unplanned edit to shared code** that another concurrent agent owns, or a "while I'm here"
  refactor not in the brief, remains **barred** — it is exactly the merge magnet the coexistence
  protocol guards. When reuse-first would require touching a shared/owned module not in scope, F
  **stops and asks** (per the coexistence single-owner rule) rather than refactoring unilaterally.

In one line: *plan the refactor and it's reuse-first; sneak it in and it's a drive-by.*

---

## Deterministic hooks layer (mandated preconditions + pipeline-rule enforcement)

The pipeline assumes a layer of **deterministic git/CI hooks** beneath it. Agents are
probabilistic; hooks are not. The hooks below are *mandated by this pipeline* — this section
documents them; wiring them into a given app repo is a **separate task** (do not modify other
repos from here).

### Pipeline-INDEPENDENT hooks — required preconditions (catalog)

These guard correctness regardless of which pipeline runs; a repo running this pipeline must have
them in place. O's pre-flight checks should confirm they exist.

| Hook | Stage | What it enforces |
|------|-------|------------------|
| **gitleaks** | pre-commit | No secrets/keys committed. |
| **lint-staged** | pre-commit | Lint + format only the staged files. |
| **block-main + fast unit tests** | pre-push | Refuse a push to `main`; run the fast unit subset before any push. |
| **commit-msg format** | commit-msg | Conventional-commit / project message format. |
| **GitHub branch protection** | server-side | Required status checks + review before merge to `main`; no force-push. |

### Pipeline-rule-enforcing hooks — to be wired (describe)

These encode *this pipeline's* rules deterministically so an agent can't skip a gate by accident.
They are **to-be-wired** (described here; implemented per repo in the separate hooks task):

| Hook | Enforcement layer | Enforces which pipeline rule |
|------|-------------------|------------------------------|
| **block commit-to-main** | harness PreToolUse(Bash) — block the agent locally; GitHub branch protection (precondition table above) is the server-side backstop | O never commits feature work directly to `main` (Phase 10 goes through a PR). |
| **forbidden-path guard** | harness PreToolUse(Edit/Write/Bash) — refuse edits to protected/shared-owned paths before they happen | F/agents don't edit protected/shared-owned paths (coexistence §ownership) outside an approved, spec'd scope. |
| **tests-before-code check** | CI check — verify in PR history that a failing-test commit / `handoff-T-red.md` precedes F's production-code commit. **NOT a git hook** (a local hook can't see phase history) | A failing test authored (RED, Phase 4) exists for the change before F's production code lands — enforces test-first. |
| **decision-journal-updated check** | CI check (or a commit hook as a weaker local nudge) | A commit for a phase also updated `decisions.md` for that phase (§Decision journal). |
| **design-gate check** | CI check (or O pre-flight). **NOT a git hook** (a local hook can't see wireframe approval) | A UI-touching change carries an approved `wireframe.*` + recorded approval (Phase 2) before tests/code. |

A "hook" here spans layers — a **git hook** catches local mistakes, **CI** catches what reaches
the PR, **harness PreToolUse** catches agent actions before they happen, and **O pre-flight**
catches missing preconditions — so pick the layer that can actually *observe* the rule:
**tests-before-code** and **design-gate cannot be git hooks** (a local hook can't see phase history
or wireframe approval), which is why they live in CI / pre-flight, not pre-commit.

---

## Decision journal

Every run keeps a single append-only **decision journal**, defined in
[`pipeline-conventions.md` §1](pipeline-conventions.md). It is wired into this pipeline as follows:

- **O creates it** at `docs/handoffs/<run-slug>/decisions.md` in Phase 1 and owns its existence.
- **Every phase appends its own entry after it completes** — O, D, A, T (both halves), F, U, S —
  using the entry format (Decided / Assumed / Hedged / Evidence). No exceptions.
- **O writes the closing Chain Summary** in Phase 11 (Outcome / Key decisions / Open assumptions
  still unverified / Follow-ups filed).
- An assumption that survives to the Chain Summary unverified is flagged, not buried.

O references and enforces this in [`orchestrator.md`](orchestrator.md).

---

## Stacked-PR cadence

**One pipeline run = one complete feature = one PR.** A run carries a feature from survey through
post-merge sweep and lands it as a single, coherent, reviewable PR.

- **Do not split one feature across migration / backend / UI PRs.** A feature whose acceptance
  criteria span a schema migration, an API handler, and a UI is still **one** feature and one PR —
  if it is too large, that is a *scope-cap split into separate features* (each its own full run and
  PR), not a slicing of one feature into layer-PRs that can't be reviewed or reverted independently.
- **Stack the next feature on top while this one is in review.** When run N's PR is open and
  awaiting CI / review, branch run N+1 **on top of** run N's branch (not on stale `main`) and start
  its survey. This keeps the operator unblocked without merging un-reviewed work. When run N merges,
  rebase N+1 onto the new `main`.
- **Land in order.** The base feature merges first; stacked features rebase onto the new base.
  Keep stacks shallow — a tall stack of un-merged features re-creates the long-running-branch
  divergence the coexistence protocol warns about.

This is the cadence companion to [`parallel-agent-coexistence.md`](parallel-agent-coexistence.md):
that doc handles *concurrent* agents on *disjoint* scopes; this handles *sequential* features
stacked by one operator.

---

## Agent Roles and Boundaries

| Agent | Slug | Can do | Cannot do |
|-------|------|--------|-----------|
| **O** Orchestrator | `orchestrator` | Create issues/branches, survey, write brief, own `decisions.md`, present gates, commit/merge, decide | Write implementation code, author tests, run verification |
| **D** Designer | `designer` | Read brief/spec, produce or ingest a wireframe, write `handoff-D.md` | Write production code or tests, commit, approve its own wireframe |
| **A** Architecture Reviewer | `architecture-reviewer` | Review the brief+wireframe up front (Phase 3) and the diff for drift + duplication (Phase 7, before U/S); PASS/BLOCK | Write code, fix drift, run behavioral verification, judge spec compliance |
| **T** Tester | `tester` | Author tests (RED) from brief+wireframe; verify GREEN + run Tier 2 (automated/headless) | Write production code, fix failures, drive the live UI, run Tier 3, commit |
| **F** Feature Implementor | `feature-implementor` | Read brief+wireframe+failing tests, write production code, run Tier 1 to self-check, stage files | **Author tests**, commit, push, create PRs, skip the failing tests |
| **U** UI Walkthrough | `playwright-ui-walkthrough` (default) / `ui-walkthrough` | Drive the live UI on the SPA-nav path, exercise every control, check console+state **and built-UI-vs-wireframe**, PASS/REWORK | Write code, fix defects, judge visual/WCAG regression (S's job), commit |
| **S** Spec Auditor | `spec-auditor` | Tier 3 visual/WCAG/spec compliance + final quality audit | Write code, fix issues, commit, proceed past A/T/U blockers |

> **Slugs are stable across this rename.** Names and order changed; the registered
> `subagent_type` slugs did not — downstream repos reference them.

---

## Model Assignment per Role

Match the model to the role, not one model for the whole pipeline. **The durable heuristic:** use
the **strongest reasoning model** for the roles that *decide* — **O, A, S** — and for any single
**keystone/foundational** implementation. Use the **throughput model** for the roles that *produce
or execute against a clear spec* — **F**, **T**, **D**, **U**.

| Role | Tier | Default model |
|------|------|---------------|
| **O** Orchestrator | strongest reasoning | Opus |
| **D** Designer | throughput | Sonnet 5, medium effort |
| **A** Architecture Reviewer | strongest reasoning | Opus |
| **T** Tester | throughput | Sonnet 5, medium effort |
| **F** Implementor | throughput | Sonnet 5, `effort: max` — bump to Opus on keystone/foundational stories. Workflow-orchestrated ("Ultracode") invocation is an operator-invoked escalation for a specific high-risk story, not the default (see `feature.md`) |
| **U** UI Walkthrough | throughput | Sonnet 5, medium effort |
| **S** Spec Auditor | strongest reasoning | Opus |
| Review-rigor outside reviewer (`second-opinion`/`panel`) | independent strong external | o4-mini (+ fresh-context Opus for `panel`) |

Notes:
- A weak reviewer rubber-stamps — never run **A** or **S** on the throughput tier.
- Cheapest defensible config: **Opus for O/A/S; Sonnet 5 (medium effort) for D/T/U; Sonnet 5 (`effort: max`) for F**. Highest quality: Opus throughout.

---

## Verification and Review Ownership

| Gate | Owner | Speed | What it checks |
|------|-------|-------|---------------|
| **Design / wireframe approval** | D produces, O+human approve | Fast (human) | The agreed UI target before tests/code (Phase 2). |
| **Architecture review (up front)** | A | Fast/thorough | Layering, dependency direction, placement, naming, pattern consistency — of the *plan* (Phase 3). |
| **Tests RED** | T | Fast | A failing suite that pins each acceptance criterion, failing for the right reason (Phase 4). |
| **F self-check (Tier 1)** | F | Instant/fast | Build, lint, server starts, API smoke — F's own confidence before handoff. |
| **Tier 1 GREEN** | T | Instant (1-10s) | The authored suite now passes; build/lint/server/API smoke independently (Phase 6). |
| **Tier 2** | T | Fast (10-60s) | Coverage vs. criteria, test quality, type safety, error handling, data integrity, API contracts, security, migration safety (Phase 6). |
| **Anti-duplication** | A | Fast | F extended the analogous object rather than building a parallel path (Phase 7, before the expensive U/S audits). |
| **Tier 3-behavioral (UI Walkthrough)** | U | Thorough (live) | Live-browser behavior on the real SPA-nav path + built-UI-vs-wireframe; every control driven, swaps/bindings correct, console clean, mobile (360px). UI surfaces only (Phase 8). |
| **Tier 3-appearance (Spec Audit)** | S | Thorough (1-5 min) | Spec compliance, visual/UX match, accessibility/WCAG, visual regression, final scope and quality gate (Phase 9). |

> **Why Tier 3 is split.** "It looks right in the diff / the screenshot" and "every control works
> when a user clicks it" are different claims. S reads the diff and compares appearance/spec; U
> *drives behavior* and checks it against the approved wireframe. A component can be pixel-perfect
> in a screenshot and completely dead (never initialized) underneath — only clicking through on the
> SPA-nav path exposes that. U owns behavior; S owns appearance.

---

## Handoff Documents

All handoffs live under `docs/handoffs/<run-slug>/`. They are coordination artifacts, not project
documentation. Most are deleted after the phase commits — **except `survey.md`, `wireframe.*`, and
`decisions.md`**, which must survive until after the PR merges (F reads survey + wireframe during
implementation; `decisions.md` is the durable audit trail).

Naming: `handoff-<Letter>.md` (`D`, `A`, `T-red`, `F`, `T-green`, `U`, `S`, `A-dup`). Rework rounds
suffix `-rework`, `-rework-2`, … for an implicit audit trail.

---

## Rework Flow

When **A returns BLOCK at Phase 3 (up-front review)**:
1. O reads the A handoff and amends the brief / Reuse map (and re-presents the wireframe if it
   changed). 2. A re-reviews. 3. If A blocks **more than twice**, O pauses and escalates to the
   operator about scope or architecture ambiguity. *(Tests/code have not been written yet, so there
   is no F to respawn — the fix is to the plan.)*

When **T's RED is invalid (Phase 4)** (suite passes before the feature, or fails for the wrong
reason): T fixes the *tests*, not by waiting for F. O does not advance to F until T reports a valid
RED.

When **T's GREEN fails or Tier 2 finds blockers (Phase 6)**:
1. O reads the T handoff and respawns F with the findings. 2. F fixes on the same branch (still
   writing no tests; if a test is wrong, that routes back to T). 3. The cycle resumes at A (drift
   re-check) if architecture changed, then T re-verifies GREEN + Tier 2.

When **A returns BLOCK at Phase 7 (anti-duplication)** — which now fires **before** U/S, so a dup
rejection doesn't waste a live-browser walkthrough + visual audit:
1. O respawns F to fold the parallel path into the analogous object the Reuse map named. 2. T
   repairs/extends the tests for the merged object. 3. The cycle resumes at A (drift, Phase 3
   re-check if architecture changed) → T (GREEN) → A-dup → U → S. If folding is genuinely wrong, O
   escalates to amend the survey's extend-vs-new justification.

When **U returns REWORK (Phase 8)** (behavioral UI defect, or built UI diverges from the approved
wireframe):
1. O respawns F with the U findings (failing control(s), console errors, wireframe-mismatch, repro
   steps). 2. F fixes the behavior on the same branch. 3. **T adds/repairs a test** that would have
   caught the defect (a behavioral defect no test covers is a coverage gap — and T, not F, owns the
   test). 4. The cycle resumes at A (if architecture changed) → T (GREEN) → A-dup → U → S. O does
   not advance to S until U returns PASS.

When **S returns REWORK (Phase 9)**:
1. O files a rework issue quoting the S findings. 2. F fixes on the same branch (T repairs any test
   the fix needs). 3. The cycle resumes at A → T (GREEN) → A-dup → U (if UI) → S. 4. More than
   **two** S cycles → pause and escalate.

When a **review-rigor `hard` finding** is open (brief or diff gate): it is resolved (brief amended,
or diff fixed and re-entering at A) and the gate re-runs (`--round 2`); a still-unresolved `hard`
finding after round 2 escalates to the operator.

These caps exist so a stuck story surfaces to a human instead of looping forever.

---

## ADVISORY-HOLD Flow (defective source of truth)

S has three verdicts: `PASS`, `REWORK`, and `ADVISORY-HOLD`. `ADVISORY-HOLD` fires when S's
preview/spec sanity check finds the **source of truth itself** violates a standard convention —
e.g. a wireframe/preview showing a hamburger menu at desktop widths against the navbar-expand-lg
convention; an API spec whose error codes contradict standard HTTP semantics; a schema that allows
a value the invariants section forbids.

When S returns `ADVISORY-HOLD`: O surfaces the advisory to the operator and **does not respawn F**;
the operator decides — update the spec/wireframe, update the brief/convention reference, or accept
the deviation. Then O either kicks off a spec/wireframe-update cycle or resumes the phase with the
deviation noted as intentional. The point is to prevent re-running the whole chain against a
defective source of truth.

---

## Operator-Decision Threshold (when O escalates)

Default for O: **execute**. The spec/wireframe and architecture conventions are the sources of
truth; if they are unambiguous and the path is deterministic, O does the work and reports — no
option menus. O escalates only when one of three triggers fires:

1. **Scope change** — the work would extend beyond the issue's acceptance criteria.
2. **Design or architecture ambiguity in the source of truth** — the spec/wireframe is internally
   inconsistent, contradicts the brief, or the architecture baseline has no dominant pattern.
3. **Breaking change to shared scope** — a fix would alter something used outside this phase's
   scope (also triggers the coexistence single-owner stop-and-ask).

O does NOT escalate to choose between two paths that reach the same documented end state, or to ask
permission at uncontroversial steps. The **Design approval gate (Phase 2)** and any build-plan
Approval Checkpoints are separate, always-required human gates — not escalations.

---

## F Scope Cap (propose splits before starting)

F has a hard scope cap. If implementing the brief's acceptance criteria would touch more than ~6
files, more than one component family, or more than one design/system surface, **F stops before
writing code and proposes a 2–3 sub-phase split to the operator** — each sub-phase being its own
full run and PR (see [§Stacked-PR cadence](#stacked-pr-cadence)). The operator picks which split to
start with. The cap exists because mid-cycle stalls are more expensive than a short up-front scope
conversation. See [`feature.md`](feature.md) §"Scope Cap".

---

## Quick Reference for the Human Operator

### Starting a Cycle (default — O drives)

1. Open the O session. Tell O: `Open Phase [N].`
2. O runs pre-flight checks, creates `decisions.md`, surveys (incl. the Reuse map), writes the
   brief, creates the issue + branch, then **spawns D → A → T(red) → F → T(green) → A-dup → U → S itself**
   via the Agent tool (D and U run only when a UI surface is involved). You don't paste prompts or
   open new sessions.
3. O surfaces to you, and only these:
   - **The Design approval gate** (Phase 2) — explicit sign-off on the wireframe before tests/code.
   - **Approval Checkpoints** marked in the build plan — explicit "approved" required.
   - Decisions that need operator judgment per the **Operator-Decision Threshold**.
   - The final S verdict and any artifacts S produced.
4. Reply to O. O proceeds to PR + close the cycle, or kicks off rework.

### Manual-Relay Fallback (only when O cannot spawn subagents)

Use this only if O explicitly tells you the Agent tool / subagent spawning is unavailable. Then O
creates the issue + branch and prints a hand-off message per agent; you open a new agent session,
paste the role prompt from the canonical agent files, forward O's hand-off, and report each
handoff path back. O reads each handoff and continues the decision gate.

### Gate cheat-sheet

| Event | What O does |
|-------|-------------|
| Wireframe ready (Phase 2) | Presents to you; **waits for explicit approval** before A/T/F. |
| A BLOCK (Phase 3) | Amends the brief/Reuse map; re-reviews. No code written yet. |
| Invalid RED (Phase 4) | T fixes the tests; no advance to F until RED is valid. |
| T-green BLOCK (Phase 6) | Respawns F; resumes at A (if arch changed) → T. |
| A-dup BLOCK (Phase 7) | Respawns F to fold the parallel path into the analogous object (before U/S); resumes A→T→A-dup→U→S. |
| U REWORK (Phase 8) | Respawns F + T repairs a covering test; resumes A→T→A-dup→U. |
| S REWORK (Phase 9) | Files rework issue, respawns F; resumes A→T→A-dup→U→S. |
| `hard` review-rigor finding | Resolves (brief amend / diff fix), re-runs the gate (`--round 2`); escalates if still open. |

---

## Project Files Expected

| File | Purpose | Created by |
|------|---------|-----------|
| `docs/planning/SPEC.md` | Project specification — source of truth for what to build | Human or O |
| `docs/planning/BUILD_PLAN.md` | Phased build plan with acceptance criteria per phase | Human or O |
| `docs/handoffs/<run-slug>/` | Per-run handoff + `decisions.md` directory | O (created in Phase 1) |
| `CLAUDE.md` | Project-level instructions for AI agents | Human |
| Deterministic hooks (gitleaks, lint-staged, pre-push, commit-msg, branch protection) | Required preconditions (§Deterministic hooks layer) | Human / separate hooks task |

If any required item is missing, O's pre-flight checks flag it.
