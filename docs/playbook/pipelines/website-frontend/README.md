# 🎨 Website Front-End Pipeline (build / implementation — O → D → A → T → F → T → A → U → S)

> # ⚠️ READ THIS FIRST — WHAT THIS IS AND IS NOT
>
> This is the **Website Front-End (build) Pipeline** — a multi-agent pipeline for **building**
> website front ends (CSS layer hierarchy, component reuse, mobile-first layout, WCAG
> accessibility, visual fidelity). Flow: **O → D → A → T → F → T → A → U → S**.
>
> **It is a DISTINCT pipeline.** It is **NOT**:
> - the **Website Audit Pipeline** [`../website-audit/`](https://github.com/Performant-Labs/website-audit) — that one
>   only *finds* problems (O-W-O); this one *implements*. They are kept separate and **do not
>   reference each other** so they can diverge.
> - the dual-review / tri-review **coding** pipelines in [`workflow/`](../../workflow/).
>
> The *only* shared piece is the tri-review gate, which this pipeline **references** (never
> copies) — see [`core/gates.md`](core/gates.md). Index: [`../README.md`](../README.md).

---

## What it does

**O → D → A → T → F → T → A → U → S (implementation):** O orchestrates → **F** implements front-end code → **A**
reviews architecture → **T** verifies structurally (T1 headless, T2 structural/axe, T2.5
interaction) → **U** walks the live UI in a real browser (every interactive control, console,
reactive state, across viewport × theme-variant) → **S** audits visual + WCAG. Auditing an
existing front end is a *separate* pipeline — see
[`../website-audit/`](https://github.com/Performant-Labs/website-audit).

> **U (UI walkthrough) added per [#287](https://github.com/Performant-Labs/playbook/issues/287)**
> (proposal [`GATE-COVERAGE-PROPOSAL.md`](GATE-COVERAGE-PROPOSAL.md) §3 G7). The headless tiers
> (T) and the visual/WCAG audit (S) never open a browser and click — an entire class of
> client-behavior defects (controls that never initialize, swaps that don't fire, state that
> only breaks on SPA navigation) is invisible to them. U is the gate that exercises behavior.
> Like the tri-review gate, this pipeline **references** the generic U contract
> [`workflow/ui-walkthrough.md`](../../workflow/ui-walkthrough.md) rather than copying it — see
> [`core/roles/ui-walkthrough.md`](core/roles/ui-walkthrough.md).

## Three-tier architecture (the important part)

The pipeline separates **generic engine** from **platform knowledge** from **per-site config**,
exactly like a framework + driver + connection string:

| Tier | Dir | What lives here | Changes when… |
|---|---|---|---|
| **Core** | [`core/`](core/) | Roles, principles, verification tiers, gates, tool engines — **platform-agnostic** | (rarely — the pipeline itself improves) |
| **Adapter** | [`adapters/`](adapters/) | One file per platform: layer hierarchy, component model, commands — the platform "how" | you target a different stack |
| **Profile** | [`profile.example.md`](profile.example.md) | Per-site values: URLs, paths, theme name, inventories | every site |

A running agent is **composed**: `core role + active adapter + project profile`. To support a
new stack (WordPress, Next.js, plain HTML), write one new adapter from
[`adapters/_adapter-template.md`](adapters/_adapter-template.md) — core is untouched.

## Contents

```
core/
  principles.md            mobile-first, reuse-before-create, no-hacks, state-survival, a11y
  verification-tiers.md    T1 headless · T2 structural · T2.5 interaction · T3 visual
  gates.md                 optional tri-review gate + U hard gate (REFERENCE workflow/, don't own)
  nfr-checklist.md         standing non-functional acceptance criteria (auto-applied to every brief)
  modes.md                 autonomous/human-in-the-loop, escalation, human-relay fallback
  roles/                   orchestrator, feature-implementor, architecture-reviewer,
                           tester, ui-walkthrough (REFERENCES workflow/), spec-auditor
  tools/                   axe-check.cjs, state-invariants.spec.js
adapters/
  drupal-canvas-sdc.md            Drupal + Canvas + SDC (DripYard layer system) — component-scoped BEM
  fastify-eta-tailwind-htmx.md    Fastify + Eta + Tailwind 4 + HTMX/Alpine — utility-first server-rendered
  _adapter-template.md            skeleton for a new platform
profile.example.md         copy per site into the project repo
```

## To run it on a project

**Full walkthrough:** [`GETTING-STARTED.md`](GETTING-STARTED.md) — instantiate on a project,
compose the agents, run a coding-pipeline cycle, verification quickstart, gotchas.

**Write a plugin for a new stack:** [`adapters/WRITING-AN-ADAPTER.md`](adapters/WRITING-AN-ADAPTER.md)
— the tier-classification rule, the six required sections, a worked Next.js example, and a test
checklist.

In brief: (1) pick or write the **adapter**; (2) copy `profile.example.md` into the project and
fill it in; (3) instantiate the agents by composing each `core/roles/*` with the adapter +
profile (in Claude Code, thin `~/.claude/agents/*` pointers); (4) drive cycles via O.

## Reference instantiation

The `performantlabs.com` homepage overhaul (`pl2`) is the live reference instantiation:
Drupal-Canvas-SDC adapter + a local-only profile. Its runbook and handoffs live in that
project's repo, not here.
