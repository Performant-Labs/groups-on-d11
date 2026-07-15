# Proposal: closing the front-end build pipeline's quality-gate coverage gaps

**Status:** Design proposal — **no pipeline mechanics changed.** Plan only.
**Tracks:** `Performant-Labs/playbook#276` (the gap diagnosis).
**Evidence:** `Performant-Labs/website-audit:docs/playbook-quality-gap-analysis.md` (root-cause
analysis of the `performant_labs_v2` theme this pipeline built).

---

## 1. Context & the constraint that shapes this

A multi-dimension audit of a theme **this pipeline built** found over-reach, specificity wars,
perf, a11y-contrast, and security findings. The root cause is **not** model error slipping the
review gates — the model was compliant (zero per-edit `!important`, logged CSS-DOM-workflow
traces). It is **structural**: the build pipeline (the coding pipeline) gates *architecture per-diff*,
*structure/axe*, and *visual-fidelity + a WCAG floor*, but has **no gate** for performance,
whole-theme CSS quality, security headers/SRI, per-theme-variant a11y, or **accumulated**
cascade health; and the CSS-DOM discipline that would govern the cascade
(`languages/css/css-change-workflow.md`) is human-initiated and **not a phase**.

**Hard constraint (from `README.md`):** the build pipeline and the
[Website Audit pipeline](https://github.com/Performant-Labs/website-audit) are **deliberately
decoupled** — "kept separate and do not reference each other so they can diverge." This
proposal **respects that**: the pipeline *docs/roles* stay decoupled. Where a check already
exists in the audit project, we reuse its **detection library** (the zero-dep `@pl-audit/*`
ESM packages), not the audit *pipeline*. Sharing a library ≠ the pipelines referencing each
other. (Governance fork made explicit in §4.)

## 2. Design principle: catch each defect at the *earliest* point

Every gap is addressed at up to three points, earliest-first (the pipeline's stated
philosophy):

- **Brief / profile (design phase)** — make it an *acceptance criterion* so F is told up
  front. Cheapest catch; prevents the defect rather than detecting it.
- **Implement (F) + deterministic guards** — stylelint/hooks the model can't silently skip.
- **Gate (A / T / S, or a new automated gate)** — the backstop that blocks merge.

A defect that only has a *gate* and no *brief* criterion is caught late and re-worked; the
proposals below add the brief criterion **and** the gate for each.

## 3. Per-gap proposals

### G1 — Accumulated cascade health (the dominant gap)
- **Brief:** add "no new selector over-reach beyond the component boundary; keep aggregate
  specificity flat" to the standing acceptance criteria.
- **Implement:** **promote `languages/css/css-change-workflow.md` to a required phase** of F
  (today it's human-initiated and optional), and make its stylelint guards **non-opt-in** for
  front-end projects.
- **Gate (extend A):** A is per-diff and forgives pre-existing over-reach. Add a
  **whole-theme** check: run the cascade/over-reach detector over the *built stylesheet set*
  (not just the diff) and gate on an **accumulated** over-reach + specificity-war budget, with
  a documented allowance for "subtheme legitimately overriding a parent component."
- **Detection:** `@pl-audit/css-arch` already does this (boundary-escape + layer attribution).
  Reuse the library (§4).
- **Acceptance:** built theme's css-arch *Your-layer* score ≥ budget; zero *new* critical
  boundary leaks vs the parent baseline.

### G2 — Performance
- **Brief:** a **performance budget** (render-blocking count, total CSS/JS weight, LCP/CLS/INP
  targets) as acceptance criteria; require CSS aggregation ON in the audited build.
- **Gate:** new automated perf gate (the build's own `core/gates.md` entry) measuring
  render-blocking resources + page weight (+ Lighthouse when a CDP port is available).
- **Detection:** `@pl-audit/perf` (already aggregates render-blocking findings, computes
  weight; Lighthouse-capable).
- **Note:** must run against an **aggregation-ON** build, or it measures the dev environment
  (the "102 stylesheets" was a dev-mode artifact — see analysis §0).

### G3 — Whole-theme CSS quality
- **Brief:** "no unused/dead selectors shipped; bound `!important` and specificity-war counts;
  CSS payload under budget."
- **Gate:** automated, over the built CSS — `@pl-audit/css-quality` (unused/duplicate/
  specificity/`!important`/payload). Distinct from A (architecture) — this is code health.

### G4 — Security headers / Subresource Integrity
- **Brief:** "third-party scripts/styles carry SRI (`integrity`+`crossorigin`); document the
  required response-header set"; remove the un-pinned `@import` recommendation from
  `themes/dripyard-guidance.md`.
- **Gate:** automated SRI/header check — `@pl-audit/security`.
- **Scope note:** headers are often server config, not theme; the gate should *attribute*
  (theme-authored vs server) — depends on audit attribution (website-audit#42).

### G5 — Per-theme-variant a11y
- **Brief:** "contrast and focus pass in **every** shipped theme variant (`theme--dark`, each
  `color_scheme`), not just the default."
- **Gate (extend T/S):** parametrize the existing (already-absolute) contrast/axe checks over
  **viewport × variant**, not just viewport × section.
- **Detection:** `@pl-audit/a11y` per variant.

### G6 — Visual gate is baseline-relative
- **Problem:** a defect baked into the approved baseline reads as MATCH forever.
- **Gate (extend S):** keep the baseline pixel-diff for *fidelity*, but add **absolute-rule**
  checks independent of the baseline (no element exceeds the viewport; ≥44×44 tap targets;
  no collapsed containers) at 360/768/1280 — and **re-ratify baselines** on a cadence so an
  approved-but-wrong baseline can't mask a defect indefinitely.
- **Detection:** `@pl-audit/visual` (absolute overflow + tap-target).

### G7 — No U (UI-walkthrough) phase in the front-end build family
- **Problem:** the generic coding pipeline has a live-browser behavior phase (U); the theme-building
  family had no U phase.
- **Proposal:** add a **U phase** (or fold the behavior matrix into T2.5) — live-browser,
  every interactive control, console, reactive state, across viewport × variant. This is also
  where baseline-independent visual regressions surface.

### G8 — The brief template injects no standing non-functional checklist
- **Problem:** perf/security/CSS-quality/per-variant-a11y are checked only if whoever wrote
  the acceptance criteria remembered them.
- **Proposal:** add a **standing NFR checklist** to the brief template (`core/` + the
  coding-pipeline brief) that auto-includes G1–G6 acceptance criteria for every front-end story. This is
  the highest-leverage, lowest-effort change — it makes "catch early" structural, not
  memory-dependent.

## 4. The governance fork: where do the checks live?

Six of the eight gaps (G1–G6) are **already implemented** as detection logic in the
`@pl-audit/*` libraries (built in the website-audit repo). Two options:

- **Option A — build-native re-implementation.** Re-implement each check inside the build
  pipeline's `core/` tool engines. Maximal decoupling; duplicates logic; two codebases drift.
- **Option B — shared detection libraries (recommended).** Both pipelines depend on the same
  zero-dep `@pl-audit/*` packages as a **library dependency**; the pipeline *docs/roles* still
  never reference each other. DRY, single source of truth for "what counts as a defect," and
  honors the decoupling decision (which is about the *pipelines*, not the detection code).
  **Caveat:** this is a deliberate softening of "no shared code" — it needs the operator's
  sign-off, and it makes the build pipeline's gate quality depend on the audit libraries'
  attribution being correct (so **website-audit#42 is a prerequisite** — otherwise the gate
  mis-blames a subtheme for parent/env defects).

**Recommendation:** Option B for G1–G6, plus the pure-doc/process changes (promote CSS-DOM
workflow to a phase, brief NFR checklist, add U phase) which need no shared code at all.

## 5. Sequencing & dependencies

1. **G8 (brief NFR checklist)** + **G1 implement-half (promote CSS-DOM workflow to a phase,
   stylelint non-opt-in)** — pure process/doc, no code, highest leverage. Do first.
2. **Decide the §4 fork.** If Option B: land **website-audit#42 (attribution)** so gates
   don't mis-blame inherited/env defects.
3. **G1 gate-half + G3** (cascade health + CSS quality — one shared mechanism via
   `@pl-audit/css-arch` + `@pl-audit/css-quality`).
4. **G2 (perf, requires aggregation-ON builds), G4 (security/SRI), G5 (per-variant a11y),
   G6 (absolute visual + baseline re-ratify).**
5. **G7 (U phase)** — larger; can run in parallel.

## 6. Decomposition into #276 child issues

Each gap above becomes a child issue of #276, referencing its section here:
G1, G2, G3, G4, G5, G6, G7, G8 — plus a **decision issue for the §4 governance fork** (which
gates everything in Option B). The decision issue and G8 are the entry points.
