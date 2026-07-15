# Audit-only Workflow — Architecture Audit

> **Purpose:** The standalone architecture-audit pipeline for existing code. It does not implement code, does not run T or S, and does not open a PR by itself. Its output is a bounded findings report and, when useful, a decomposition of those findings into future coding-pipeline implementation cycles.

---

## Pipeline family — pick one per project

A project declares which pipeline its build plan runs on:

| Pipeline | Doc | Use when |
|----------|-----|----------|
| Coding pipeline | [`workflow-coding-pipeline.md`](workflow-coding-pipeline.md) | The default, test-first implementation pipeline (review-rigor dial per story). |
| **Audit-only** (this doc) | `auditarchitecture.md` | Surveying existing code for architectural drift; ships no code. |

Both number phases from **1** with no decimal or sub-letter phases.

This pipeline has three phases: **1 — Scope (O)**, **2 — Audit (A, audit mode)**, **3 — Decompose (O)**.

---

## Pipeline Overview

```text
O (Orchestrator)
|  validates that no implementation cycle is in flight
|  bounds the audit scope
|  writes audit-scope.md
v
A (Architecture Reviewer, audit mode)
|  reads audit-scope.md
|  walks the scoped source subtree
|  writes findings.md
v
O (Orchestrator)
|  reads findings.md
|  groups findings into themes
|  writes decomposition.md when findings should become work
v
Human Operator
   decides which proposed stories to start
```

The Architecture Reviewer uses the **same agent prompt** as the implementation pipeline: [`architecture-reviewer.md`](architecture-reviewer.md). The input artifact determines the mode. If O hands A a feature handoff and diff, A is in review mode. If O hands A an `audit-scope.md` and no feature diff, A is in audit mode.

---

## When to Use This Workflow

Use this workflow when the goal is to survey existing code for architectural drift, not to verify or implement a specific feature.

Good triggers:

- "Audit `src/modules/auth` for architecture drift."
- "Find places where routes bypass the service layer."
- "Review the dashboard module against current repository patterns."
- "Identify cross-module coupling in the reporting subsystem."

Do **not** use this workflow for:

- A feature branch diff. Use the coding pipeline; A will run in review mode (up-front + anti-duplication).
- Runtime verification, visual regression, or spec compliance. Those belong to T and S inside the coding pipeline.
- Unbounded "audit everything" requests. O must narrow the scope first.

---

## Phase 1 — Scope (O)

O creates a bounded audit scope before spawning A.

### Preconditions

1. No implementation cycle is in flight in the same worktree.
2. The audit id is unique.
3. The scope is bounded to a subsystem, directory, file set, or specific architectural concern.

If the request is too broad, O asks the operator for a narrower scope before continuing.

### What O Reads

- The operator's audit request.
- Project architecture docs, usually `docs/planning/architecture.md`, `docs/planning/project-architecture.md`, or equivalent.
- Relevant playbook docs (mounted at `docs/playbook/` in host projects):
  - `architecture/design-patterns.md`
  - `workflow/architecture-reviewer.md`
  - framework or language conventions relevant to the scope.
- A light tree survey of the proposed scope using `rg --files`, `find`, or equivalent.

### What O Writes

```text
docs/handoffs/audits/[audit-id]/audit-scope.md
```

Use this template:

```markdown
# Audit Scope: [audit-id]

**Date:** [YYYY-MM-DD]
**Requested scope:** [verbatim operator request]

## Scope

**Paths to walk:**
- [path]

**Paths to ignore:**
- [path]

**Depth of recursion:** [unlimited | N levels | top-level only]

**Specific subsystems or layers in focus:**
[Example: route -> handler -> service -> repository chain inside src/modules/auth.]

## Architectural Concerns

Apply these dimensions, citing the baseline docs or neighboring files that define the expected pattern:

- **Layering:** [project-specific expected chain]
- **Dependency direction:** [project-specific rule]
- **Naming and file structure:** [dominant naming/placement pattern]
- **Pattern consistency:** [validation, route registration, repository usage, error handling, etc.]
- **Cross-cutting concerns:** [auth, logging, transactions, caching, validation]
- **Abstraction level:** [over- and under-abstraction concerns]

## Baseline References

- [project architecture doc or source file]
- [playbook doc]
- [neighboring source file that demonstrates the pattern]

## Acceptance Criteria for findings.md

- Each finding has: number, severity (`block` | `warn`), file:line, drift dimension, finding, suggested remediation, estimated story size.
- Findings are prioritized by severity, then by leverage.
- Themes group findings with a shared root cause.
- No PASS/BLOCK verdict. The findings list is the result.
- "Out of scope but noticed" captures future audit candidates without expanding this audit.

## Notes for A

[Any context needed to interpret scope correctly.]
```

---

## Phase 2 — Audit (A, audit mode)

O spawns the same Architecture Reviewer used in the coding pipeline:

```text
subagent_type: architecture-reviewer
```

Prompt shape:

```text
Audit mode. Read docs/handoffs/audits/[audit-id]/audit-scope.md.
Walk the scoped subtree. Apply the architectural concerns and baseline references.
Write docs/handoffs/audits/[audit-id]/findings.md.
Do not write or modify source files. Do not return PASS/BLOCK.
```

### What A Reads

1. `audit-scope.md`
2. Every file under the scoped paths, prioritizing entry points first.
3. Neighboring files outside the scope only when needed to establish the dominant pattern.
4. Baseline docs cited by the scope file.

### What A Writes

```text
docs/handoffs/audits/[audit-id]/findings.md
```

The canonical template lives in [`architecture-reviewer.md`](architecture-reviewer.md) under "Audit Mode — Standalone Architecture Audit."

### A Boundaries

- A is read-only except for `findings.md`.
- A does not run tests.
- A does not judge spec compliance.
- A does not invent a new architecture. If no dominant pattern exists, A marks the ambiguity as `warn`.
- A does not expand scope silently.

---

## Phase 3 — Decompose (O)

O reads `findings.md` and decides which findings should become future implementation work.

### What O Reads

- `docs/handoffs/audits/[audit-id]/findings.md`
- Current project backlog, build plan, task list, or issue tracker
- Relevant planning docs so proposed stories do not duplicate existing work

### What O Writes

If findings should become implementation work, write:

```text
docs/handoffs/audits/[audit-id]/decomposition.md
```

Use this template:

```markdown
# Architecture Audit Decomposition: [audit-id]

**Date:** [YYYY-MM-DD]
**Source:** `docs/handoffs/audits/[audit-id]/findings.md`
**Findings input:** [count]
**Stories proposed:** [count]

## Disposition of Findings

| Finding # | Disposition | Justification |
|---|---|---|
| 1 | Story `[audit-id]-S1` | Standalone block-severity layering violation. |
| 2 | merged into `[audit-id]-S1` | Same root cause as #1. |
| 3 | dropped | Already tracked elsewhere. |

## Proposed Stories

### Story `[audit-id]-S1`: [short title]

**Source findings:** #[N]
**Severity carried over:** block | warn
**Estimated size:** XS | S | M | L

**Objective:**
[One sentence.]

**Acceptance criteria:**
- [criterion derived from suggested remediation]
- [verification expectation]

**Files in scope:**
- [path]

**Required references:**
- [architecture doc or playbook doc]

**Declared pipeline:**
coding-pipeline implementation cycle.

**Implementer notes:**
[Narrowing instructions, non-goals, or ambiguity requiring operator judgment.]

## Out of Scope but Noticed

[Carry forward items from findings.md that should become future audits.]
```

O does not automatically start proposed stories. The human operator chooses which story ids to start, and those stories run through the coding pipeline.

---

## Failure Modes

| Failure | O action |
|---|---|
| Scope is unbounded | Ask the operator to narrow paths or concern. |
| A writes malformed findings | Pause, surface the malformed section, and ask whether to rerun A or repair the scope instructions. |
| A finds no drift | Record that in `findings.md`; no decomposition is required. |
| Findings duplicate existing backlog | Mark as dropped or duplicate in `decomposition.md`. |
| Finding needs product/spec decision | Mark "operator decision required" in decomposition; do not turn it into implementation work until decided. |

---

## Relationship to the coding pipeline

Architecture audits produce candidate work; they do not replace the implementation pipeline.

```text
Audit-only:                 Phase 1 (O) -> Phase 2 (A, audit) -> Phase 3 (O)
Implementation of findings: the coding pipeline, per the accepted story
```

The same A prompt is used in both places. The input artifact determines whether A is reviewing a feature diff (coding pipeline Phase 3 up-front / Phase 7 anti-duplication) or auditing an existing subtree (Audit-only Phase 2).
