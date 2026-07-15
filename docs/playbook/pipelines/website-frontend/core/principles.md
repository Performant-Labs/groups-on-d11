# Website Front-End Pipeline — Core Principles

> ⚠️ **WEBSITE FRONT-END PIPELINE — CORE (platform-agnostic).** This pipeline builds
> website front ends. It is **distinct** from the dual-review / tri-review *coding*
> pipelines in `workflow/`. Do not merge the two. Entry point: [`pipelines/README.md`](../../README.md).

These principles hold for **any** website front end regardless of CMS, framework, or
build system. Platform-specific mechanics (how layers work, how components register,
what commands to run) live in the **active adapter** under `adapters/`. Per-site values
(URLs, paths, names) live in the **project profile**. A role prompt cites these
principles by name and defers the "how" to the adapter.

---

## 1. Mobile-first authoring

Base (unqueried) styles target the smallest viewport. Scale **up** with `min-width`
queries. A `max-width` query is the exception and requires an inline comment naming the
reason it cannot be expressed mobile-first. Desktop-first authoring (wide-viewport base
styles patched down with `max-width`) is architectural drift.

## 2. Reuse before create

Before creating any component, search the platform's existing component library (see the
adapter's *Component reuse source*). If an existing component can be configured to do the
job with a modicum of effort — props, slots, modifier classes, token overrides — use it.
Create new only when none can be configured, and record the search and verdict in the
handoff. A new component that duplicates a configurable existing one is a block-level
finding.

## 3. Override at the highest correct layer; no specificity hacks

Every style change goes at the highest correct layer in the platform's hierarchy (see the
adapter's *Layer hierarchy*). Never patch rendered output directly. **No `!important`** —
if you want it, you are at the wrong layer; trace upward. No ID selectors in CSS, no
artificially inflated selector chains, no `all: unset` on broad selectors.

**The CSS-DOM change workflow is a REQUIRED step of F for any CSS change** (not
human-discretion). Every CSS change runs the loop in
[`languages/css/css-change-workflow.md`](../../../languages/css/css-change-workflow.md) —
trace the chain, fix at the highest correct layer, verify, and log the decision — and its
deterministic stylelint guards (`declaration-no-important`, `selector-max-specificity`,
`selector-max-id`) are **non-opt-in (required)** for front-end projects: they must be
bootstrapped before F runs. A CSS change with no logged workflow trace is a block-level
finding. (#281; proposal [`../GATE-COVERAGE-PROPOSAL.md`](../GATE-COVERAGE-PROPOSAL.md) §3 G1.)

## 4. State must survive navigation

User state that belongs in the URL or storage (filters, pagination, active language,
search query) **must survive navigate-away-and-return**. Ephemeral open/closed UI state
(menus, dropdowns) is expected to reset. This is verified, not assumed — see
[`verification-tiers.md`](verification-tiers.md) Tier 2.5.

## 5. Accessibility is a gate, not a nicety

WCAG AA is the floor: body text ≥ 4.5:1, large text (≥ 18pt / 14pt bold) ≥ 3.0:1, focus
ring ≥ 3:1, link text ≥ 4.5:1; touch targets ≥ 44×44 CSS px; no skipped heading levels;
landmarks present; interactive elements semantic. Verified with tooling (axe-core) plus
numeric checks, not eyeballing.

## 6. Verification proportionality

Prove each claim at the **cheapest tier that can prove it** (see
[`verification-tiers.md`](verification-tiers.md)). Don't re-prove at an expensive tier
what a cheap one already settled. Visual (Tier 3) runs only when the change is visually
observable; a provably non-rendering change (comment, identical-computed-value refactor)
may skip it with evidence.
