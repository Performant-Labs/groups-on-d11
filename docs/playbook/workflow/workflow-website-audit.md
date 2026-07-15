# Website Audit Workflow — O-W-O

> **Purpose:** Defines the website-specific audit pipeline for component-based websites. Detects CSS layer hierarchy violations, HTML structural problems, and cascade errors introduced by incorrect layer placement. Two-phase pipeline (static, then render) controlled by a `phase` switch in the scope document. Not a substitute for S's visual fidelity audit in the coding pipeline.

---

## Pipeline Overview

```text
O (Orchestrator)
|  validates no implementation cycle is in flight
|  defines phase and scope
|  writes audit-scope.md
v
W (Website Auditor)
|  reads audit-scope.md
|  Phase 1 (static): reads CSS/HTML/template files — telltale signs of incorrect cascade placement
|  Phase 2 (render): browser-based cascade resolution verification
|  writes one HTML report
v
O (Orchestrator)
|  reads HTML report
|  decomposes critical and warning findings into future coding-pipeline issues
v
Human Operator
   decides which proposed stories to start
```

**Phase discipline.** Static always precedes render. `phase: render` is only valid when a prior static run for this exact scope exists and outstanding critical findings = 0. Rendering over broken structure produces noise, not signal.

---

## When to Use This Workflow

Use when the goal is to find CSS/HTML hierarchy problems in existing code, not to verify a specific feature.

Good triggers:
- "Audit the homepage CSS for incorrect layer placement."
- "Find `!important` usage and other signs of cascade fighting across the theme."
- "Check whether the card component's styles belong at the right layer."
- "Verify the heading structure of the articles page."
- "After fixing static issues, verify that the cascade resolves correctly in the browser."

Do **not** use this workflow for:
- Visual fidelity against a design brief. Use S in the coding pipeline.
- General code architecture (module coupling, API contracts, service layering). Use O-A-O.
- Feature implementation. Use the coding pipeline.
- Pixel-level visual regression. Use S.

---

## Two Phases

| Phase | Mode | What runs |
|---|---|---|
| `static` | File reading only | Telltale signs of incorrect cascade placement: `!important`, ID selectors, specificity escalation, wrong-layer placement, variable chain problems, HTML structure violations, component schema drift |
| `render` | Browser rendering | Cascade resolution at computed-style level: variable values, color token accuracy, responsive breakpoint activation, WCAG contrast at actual computed values |
| `both` | Full audit | Static first, then render. Only valid when scope has no outstanding static criticals. |

---

## Audit Scope Document

O creates `audit-scope.md` before spawning W.

Path: `docs/[project]/handoffs/audits/[audit-id]/audit-scope.md`

Template:

```markdown
# Website Audit Scope: [audit-id]

**Date:** [YYYY-MM-DD]
**Phase:** static | render | both
**Requested scope:** [verbatim operator request]

## Scope

**type:** component | page | theme
**target:** [URL for render phase, or root path for static phase]

**Files to analyse:**
- [path or glob]

**Files to ignore:**
- [path]

## Focus Areas

Apply all dimensions unless explicitly narrowed:
- all
# or one or more of:
- css-layer-hierarchy
- variable-chain
- coupling-signals
- html-structure
- component-schema
- render-cascade
- wcag-contrast

## Layer Hierarchy

[Describe the project's expected CSS layer order. Example:
Layer 1 — config (drush/admin)
Layer 3 — theme tokens in css/base.css
Layer 5 — component CSS via libraries-extend
Layer 4 (rendered component output) — never patched directly
]

## Baseline References

- [project CSS architecture doc]
- [component schema root path]
- [theme token file path]

## Prior Audit Context

**Prior static run (render phase only):** [path to prior static HTML report, or "none"]
**Outstanding static criticals from prior run:** [count, or "0 — render permitted"]

## Notes for W

[Any context needed to interpret scope correctly.]
```

---

## Phase W1 — Scope (O)

### Preconditions

1. No implementation cycle is in flight in the same worktree.
2. The audit id is unique.
3. The scope is bounded: a named component, a specific page, or a defined theme subtree — not the entire codebase.
4. If `phase` is `render` or `both`: a prior static run for this scope shows outstanding criticals = 0.

### What O Reads

- The operator's audit request.
- The project's CSS architecture docs and theme layer documentation.
- A light tree survey of the proposed scope.
- The prior static HTML report if this is a render-phase run.

If the scope is too broad, O asks the operator to narrow it before spawning W.

---

## Phase W2 — Audit (W)

O spawns the Website Auditor agent with the scope doc path.

```text
subagent_type: website-auditor
```

Prompt shape:

```text
Website audit. Read docs/[project]/handoffs/audits/[audit-id]/audit-scope.md.
Run the [static | render | both] phase as specified.
Write docs/[project]/handoffs/audits/[audit-id]/website-audit-[slug]-[phase].html.
Do not write or modify source files.
```

W is read-only except for the HTML report output.

---

## Phase W3 — Decompose (O)

O reads the HTML report and writes a decomposition for findings that should become implementation work.

Path: `docs/[project]/handoffs/audits/[audit-id]/decomposition.md`

Each proposed story carries:
- Source finding number(s)
- Severity carried over (`critical` | `warning`)
- Objective (one sentence)
- Acceptance criteria derived from W's suggested remediations
- Files in scope
- Declared pipeline: the coding pipeline implementation cycle

O does not automatically start proposed stories. The operator selects which to run.

---

## HTML Report Format

One HTML report per run. Self-contained — no CDN dependencies, all styles inline, opens cleanly via `file://`.

Required sections:
1. **Audit header** — audit-id, date, phase, scope type, target, focus areas.
2. **Summary** — finding counts by severity: `critical` / `warning` / `info`.
3. **Per-dimension sections** — one `<section>` per focus area. Each finding contains severity badge, location (`file:line`), code excerpt or computed-vs-expected table, explanation, suggested remediation.
4. **Dimensions with zero findings** — listed with a green "Clean" badge so coverage is visible.
5. **Out of scope but noticed** — patterns worth flagging for a future audit.

---

## Failure Modes

| Failure | O action |
|---|---|
| Scope is unbounded | Narrow to component or page before spawning W |
| `phase: render` but static criticals exist | Block render — address criticals first |
| W cannot reach the render target | Check server status and re-spawn |
| W finds no issues | Report that in the HTML; no decomposition required |
| Finding needs product/spec decision | Mark "operator decision required" in decomposition |

---

## Relationship to Other Pipelines

```text
Website audit (static):  O → W → O   (find CSS/HTML hierarchy problems)
Website audit (render):  O → W → O   (verify cascade resolution after static clean)
Architecture audit:      O → A → O   (module coupling, API contracts, service layering)
Implementation:          O → F → A → T → S → O   (fix findings and verify)
```

S in the coding pipeline verifies visual fidelity against a design brief. W verifies structural hierarchy correctness. They are complementary: W finds the structural problems, S confirms the visual result after they are fixed.
