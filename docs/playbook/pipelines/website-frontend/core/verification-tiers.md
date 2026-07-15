# Website Front-End Pipeline — Verification Tiers

> ⚠️ **WEBSITE FRONT-END PIPELINE — CORE (platform-agnostic).** Distinct from the coding
> pipelines in `workflow/`. Entry point: [`pipelines/README.md`](../../README.md).

The pipeline verifies front-end work in tiers, cheapest first. Platform commands (cache
clear, local URL) come from the **adapter** and **profile**; the tier *structure* below is
platform-agnostic.

## The ladder

| Tier | What it proves | Tooling | Owner |
|---|---|---|---|
| **T1 — Headless** | Page serves; expected markup/CSS variables present; `srcset` URLs resolve | `curl` + `grep`; platform cache-clear first | F (self), T (independent) |
| **T2 — Structural** | Heading hierarchy, landmarks, semantics, ARIA, contrast | **axe-core** (`tools/axe-check.cjs`) preferred; numeric contrast fallback | F (self), T (independent) |
| **T2.5 — Interaction** | State survives navigation (principle 4) | `tools/state-invariants.spec.js` against the profile's surface inventory | T |
| **U — Live-browser behavior** | Every interactive control behaves on the real user path; reactive components initialize; clean console/network — across viewport × theme-variant | live browser (Playwright MCP default), per the referenced U contract | U |
| **T3 — Visual** | Rendered output matches the design intent at each breakpoint | Playwright screenshots + pixel diff, smallest viewport first | S |

**Precondition order:** T1 → T2 → (T2.5 if the change touches an inventoried stateful
surface) → (U if the change touches a UI / client-behavior surface) → T3. Never run T3
before T2. At the smallest viewport, *no horizontal overflow* is a T3 precondition, not a
finding.

## U (UI walkthrough) — what it covers that T and T3 do not

T1/T2/T2.5 operate on served HTML and the structural tree; T3 (S) diffs rendered pixels.
**Neither opens a browser and clicks.** A class of client-behavior defects — reactive
components that never initialize, swaps that fire on the wrong target, state that only
breaks on SPA navigation — passes every headless tier and still ships broken. **U** is the
live-browser phase that exercises behavior. It runs whenever the diff touches a UI /
client-behavior surface (templates/views, client scripts, reactive directives, CSS that
drives interactive state, HTML-rendering routes); O records "U: N/A" only when none is
touched. The *what/how* lives in the referenced contract
([`roles/ui-walkthrough.md`](roles/ui-walkthrough.md) → `workflow/ui-walkthrough.md`); the
front-end matrix is **viewport × theme-variant**, not just viewport. Added per
[#287](https://github.com/Performant-Labs/playbook/issues/287)
([`../GATE-COVERAGE-PROPOSAL.md`](../GATE-COVERAGE-PROPOSAL.md) §3 G7).

## When T3 (S) runs — the gate rule

S runs whenever the diff touches a **rendered visual property** — color, spacing, layout,
typography, imagery, breakpoints. A pure refactor with provably identical computed values
(e.g. extracting a hardcoded value into a custom property) or a comment-only change may
skip S **only if** T's handoff includes a computed-value equivalence check, or the change
is provably non-rendering. Record every skip and its justification in the orchestrator log.

## Tooling notes

- **axe-core** (`tools/axe-check.cjs`): run at the smallest and a large viewport; report by
  rule id. Replaces hand-computed luminance for the common cases; keep numeric checks for
  tokens axe can't see.
- **State invariants** (`tools/state-invariants.spec.js`): the 4-step invariant per surface
  — establish state → navigate away → return → assert survived. All four steps required; a
  test that filters and asserts without leaving the page does **not** cover persistence.
- **srcset resolution** (T1): when the change touches images/media, every `srcset` URL in
  the rendered HTML must return 200. (A real incident shipped because the HTML looked
  correct but every derivative URL 500'd.)
