# Website Front-End Pipeline — Operating Modes & Escalation

> ⚠️ **WEBSITE FRONT-END PIPELINE — CORE (platform-agnostic).** Distinct from the coding
> pipelines in `workflow/`. Entry point: [`../README.md`](../README.md).

How O runs the pipeline and when it involves the operator. Platform-agnostic; the
*integration/commit posture* (push/PR vs local-only merge) comes from the project profile.
O propagates the active mode to every spawn via a `**Mode:**` line.

## Two modes

| Mode | Operator engagement | When |
|---|---|---|
| **Autonomous** (default) | Approves kickoff; sees the running log on return; surfaced only on hard-stop-floor events | Multi-cycle work, well-documented runbooks, operator step-away |
| **Human-in-the-loop** (opt-in) | Approves every checkpoint; consulted on ADVISORY-HOLD, F's scope-split, F's layer choices, spec ambiguity | First pass on a new pattern, high-risk areas |

## Autonomous mode

- **Prerequisite:** every approval checkpoint has a documented recommendation or a kickoff
  pre-commitment. Refuse autonomous mode otherwise; ask the operator to amend the runbook.
- **Silent-park** (log + continue, do not surface): S returns ADVISORY-HOLD; F's scope cap
  triggers a split; a cycle reaches its 3rd rework round.
- **Hard-stop floor** (surface immediately): verification environment broken; availability
  broken on already-shipped pages; new WCAG regression on shipped pages; unexpected data/schema
  deletion; two consecutive parked cycles.
- **Durable record:** maintain a sprint orchestrator log; produce a self-contained sprint-wrap
  at completion. Per-cycle handoffs stay ephemeral.
- **Integrate** each cycle per the **profile's posture** after S returns PASS (e.g. `--no-ff`
  merge for local-only repos; PR for normal repos). Do not batch to wrap.
- **Does not auto-start:** wait for the operator's explicit "go" at kickoff.

## Human-in-the-loop mode

Every checkpoint pauses for approval; ADVISORY-HOLD pauses; F's scope-split and layer-approval
steps surface via O; the operator-decision threshold collapses toward escalate-by-default.

## Operator-decision threshold (both modes; only the disposition differs)

Default: **execute** when the preview/spec is unambiguous and the path is deterministic.
Escalate (autonomous: per silent-park/hard-stop rules; human-in-the-loop: immediately) only on:

1. **Scope change** — work would extend beyond the issue's acceptance criteria.
2. **Design ambiguity in the source of truth** — preview/spec internally inconsistent,
   contradicts the brief, or violates a convention unresolvable from documents alone.
3. **Breaking change to shared scope** — a fix alters a component/template/token used outside
   this phase's scope.

Do **not** escalate for: choosing between two paths that reach the same documented end state;
permission-seeking at uncontroversial steps; presenting menus the preview already answers.

## Gate checkpoint messages (operator comms)

Keep them tight: **URLs + viewports + sections + the decision asked.** No file lists, no
change enumerations, no advisory recaps.

## Human-relay fallback

If subagent spawning is genuinely unavailable (older client, no Agent tool): print a hand-off
message the human pastes into a fresh agent session, and wait for the human to report each
agent's completion. Use only when spawning actually fails — never as a default. State
explicitly that you are falling back, and why.
