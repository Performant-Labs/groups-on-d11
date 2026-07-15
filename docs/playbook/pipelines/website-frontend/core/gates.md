# Website Front-End Pipeline — Adversarial Review Gates

> ⚠️ **WEBSITE FRONT-END PIPELINE — CORE (platform-agnostic).** Distinct from the coding
> pipelines in `workflow/`. Entry point: [`pipelines/README.md`](../../README.md).

This pipeline can run optional adversarial-review gates at two points. **It does not own
the gate mechanism** — it *references* the shared review-rigor gate that lives with the
coding pipeline, so there is one implementation to maintain.

## Shared dependency (do not duplicate)

- Gate mechanics: [`workflow/workflow-coding-pipeline.md`](../../../workflow/workflow-coding-pipeline.md) §"Review-rigor" (the `panel` dial = two outside reviewers on a byte-identical prompt; the former `tri-review.md` mechanics were folded in here when the pipeline docs were consolidated)
- Gate script: [`workflow/dual-review.sh`](../../../workflow/dual-review.sh)
- U (UI walkthrough) contract: [`workflow/ui-walkthrough.md`](../../../workflow/ui-walkthrough.md)

The website pipeline **uses** these as components. It must not copy or modify them. If the
review-rigor mechanism or the U contract changes, this pipeline inherits the change for free.
These are the only overlaps between the website pipeline and the coding pipeline — and they
are by reference only.

## U (UI-walkthrough) gate — live-browser behavior

The pipeline is **O → D → A → T → F → T → A → U → S**: after T (headless/structural) and before/with S
(visual + WCAG), **U** drives the live UI in a real browser. It is a **hard gate** that runs
automatically whenever the diff touches a UI / client-behavior surface (templates/views,
client scripts, reactive directives, CSS that drives interactive state, HTML-rendering
routes); O records "U: N/A (no UI surface touched)" only when there is none. A `REWORK`
verdict routes back to F. The role contract references the generic U template — see
[`roles/ui-walkthrough.md`](roles/ui-walkthrough.md). The front-end walkthrough matrix is
**viewport × theme-variant**. Added per
[#287](https://github.com/Performant-Labs/playbook/issues/287)
([`../GATE-COVERAGE-PROPOSAL.md`](../GATE-COVERAGE-PROPOSAL.md) §3 G7).

## Where the gates sit

- **Brief gate** — after O writes the brief, before F. Catches design/scope flaws while a
  fix costs minutes.
- **Diff gate** — after A returns PASS, before T. `hard`/BLOCK findings route back to F.

Two outside reviewers (o4-mini cross-vendor + latest Opus same-vendor) review a byte-identical
prompt; O reconciles. Procedure, the dump-once-then-fan-out flow, the read-only Opus
wrapper, and the comparison deliverable are all defined in the `panel` dial of
`workflow/workflow-coding-pipeline.md` §"Review-rigor" — follow it verbatim.

## Enablement (per cycle, declared in the brief)

`Front-end tri-review of brief: on|off` / `… of diff: on|off`.

**Default on** for: component-creating stories, shared-token/theme changes, nav/header or
other high-blast-radius surfaces. **Default off** for: single-file token fixes and
comment-only changes. Record the declaration and rationale in the orchestrator log.
