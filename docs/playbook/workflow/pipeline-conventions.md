# Pipeline Conventions (shared primitives)

> Shared building blocks for **all** Performant Labs pipelines — the coding pipeline and the
> infra-change pipeline both build on these. Defined once here so the pipelines don't drift.
> If a pipeline needs to deviate, it states the deviation explicitly and says why.

---

## 1. The decision journal (`decisions.md`)

Every pipeline run keeps a single **decision journal** — a per-run, append-only audit trail
of *what was decided, what was assumed, and where an agent hedged*. It is the durable record
of intent that handoffs (which capture *outputs*) do not.

**Location:** `docs/handoffs/<run-slug>/decisions.md` (coding) or
`changes/<change-slug>/decisions.md` (infra). One file per run.

**Who writes it:** every phase appends its own entry **after** it completes — no exceptions.
The Orchestrator (O) owns the file's existence and writes the closing Chain Summary.

**Entry format (append one per phase):**
```markdown
## <phase letter/name> — <ISO timestamp>
- **Decided:** <the concrete choices made this phase>
- **Assumed:** <every assumption relied on that was not verified>
- **Hedged:** <anything uncertain, deferred, or done with low confidence — and why>
- **Evidence:** <commands run / files read / links that ground the above>
```

**Closing entry (O writes at the end):**
```markdown
## Chain Summary — <ISO timestamp>
- **Outcome:** <merged / applied / blocked / abandoned>
- **Key decisions:** <the 3–7 that future readers must know>
- **Open assumptions still unverified at close:** <list, or "none">
- **Follow-ups filed:** <issue links>
```

**Rule:** an assumption that survives to the Chain Summary unverified is a flag, not a
footnote — call it out so the next run (or the human) can close it.

---

## 2. Role-doc template

Every agent role (coding or infra) is defined by a doc with this shape. Keep the
`subagent_type` **slug stable** across renames — downstream repos reference it.

```markdown
---
name: <stable-slug>            # e.g. feature-implementor — DO NOT rename casually
description: <pipeline> <Role> (<Letter>) — <one-line scope>
tools: <explicit allowlist>    # least privilege; read-only roles get no write/edit
model: <tier>                  # strongest reasoning for deciding roles (O/A/S); throughput for producing roles (F/T)
effort: <tier>                 # medium for producing roles (D/T/U); F defaults to max (single Agent call) -- Workflow-orchestrated "ultracode" invocation is an operator-invoked escalation for specific high-risk stories, not automatic
---

You are the <Role> (<Letter>) in the <pipeline>. <One-paragraph mandate.>

## Inputs
<the handoffs/artifacts this role reads, by path>

## What you do
<numbered, concrete steps>

## What you do NOT do
<explicit anti-scope — the boundaries are structural, not advisory>

## Output
<the artifact this role writes, with a template; plus the decision-journal entry it appends>

## Decision logic
<PASS / BLOCK / REWORK criteria and what message to hand back>
```

**Least privilege:** a role gets only the tools its job needs. Read-only roles (researchers,
reviewers, verifiers) have **no** edit/write/commit tools — the boundary is enforced by the
tool list, not by instruction.

---

## 3. Gates & approval mechanics

A **gate** is a point where the pipeline pauses for an explicit human decision. Gates are
declared in the run's build plan / change spec; an agent **never infers approval** from prior
context.

**Two gate kinds:**
- **Approval gate (human).** O presents a summary + the artifact under review and waits for
  explicit approval before advancing. Silence ≠ approval. Examples: wireframe approval (coding
  D phase), spec/brief approval, pre-apply approval (infra).
- **Verification gate (automated, may loop).** An agent returns PASS / BLOCK / REWORK; on a
  non-PASS the pipeline loops back to the owning phase and re-runs the gate. The human sees
  only the resolved result unless the loop exhausts its retries, then it escalates.

**Verdict vocabulary (shared):**
- **PASS** — proceed.
- **BLOCK** — a hard problem; route back to the responsible phase, do not advance.
- **REWORK** — fix-and-re-run, same phase.
- **ADVISORY-HOLD** — non-blocking concerns surfaced for the human to weigh; advances only on
  explicit human acknowledgement.

**Review-rigor (where a pipeline supports outside reviewers):** a per-run dial —
`none` (in-session review only) / `second-opinion` (+ one outside model) /
`panel` (+ a cross-vendor pair on a byte-identical prompt, with a reconciliation artifact).

**Loop discipline:** every automated loop declares a retry cap; on exhaustion it escalates to
the human rather than spinning. Caps and escalation targets live in each pipeline's doc.

---

## 4. Artifacts & naming (shared)

- Handoffs: `docs/handoffs/<run-slug>/handoff-<Letter>.md` (coding).
- Decision journal: `.../decisions.md` (§1).
- Rework rounds: suffix the handoff `-rework`, `-rework-2`, … (implicit audit trail).
- One run = one coherent unit of work = one PR (see each pipeline's cadence section).
