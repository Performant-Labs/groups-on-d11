# Website Front-End Pipeline — Standing Non-Functional Acceptance Criteria

> ⚠️ **WEBSITE FRONT-END PIPELINE — CORE (platform-agnostic).** Distinct from the coding
> pipelines in `workflow/`. Entry point: [`pipelines/README.md`](../../README.md).

This is a **standing** acceptance-criteria checklist that **auto-applies to every front-end
story**. O appends it to the brief's `### Acceptance criteria` for *every* phase (see
[`roles/orchestrator.md`](roles/orchestrator.md)); F implements against it and T/U/S verify
it like any other acceptance criterion. It does not replace the story's functional criteria
— it is the non-functional floor that holds regardless of who wrote the story.

> **Why this exists.** Perf, security/SRI, CSS quality, per-variant a11y, and accumulated
> cascade health were previously checked **only if** whoever wrote the acceptance criteria
> remembered them — memory-dependent, not structural. A theme this pipeline built audited
> with over-reach, specificity wars, perf, contrast, and SRI findings even though every
> per-diff gate passed. Making the floor standing is the highest-leverage, lowest-effort
> fix. Added per [#280](https://github.com/Performant-Labs/playbook/issues/280); maps to
> proposal [`../GATE-COVERAGE-PROPOSAL.md`](../GATE-COVERAGE-PROPOSAL.md) §3 **G1–G6**.

Measure each against the **production-shaped build** — CSS/JS **aggregation ON**, not the dev
environment (a dev-mode "102 stylesheets" artifact measures the wrong thing). Platform
mechanics for producing that build come from the active adapter; per-site budgets and the
variant list come from the project profile.

## Standing checklist (auto-included in every front-end brief)

- [ ] **Performance budget (G2).** Against an **aggregation-ON** build: render-blocking
      resource count, total CSS weight, total JS weight all within the profile's budget; and
      **LCP / CLS / INP** within target. (Measure on the production-shaped build, not dev.)
- [ ] **Subresource Integrity (G4).** Every third-party script/style carries `integrity` +
      `crossorigin`; no un-pinned third-party `@import`. The required response-header set is
      documented. (Headers are often server config — attribute theme-authored vs server.)
- [ ] **No new selector over-reach (G1).** No selector reaches beyond its component boundary,
      and the **aggregate** specificity stays flat — no *new* boundary leak vs the parent
      baseline (a subtheme legitimately overriding a parent component is allowed and noted).
- [ ] **Per-variant WCAG contrast + focus (G5).** Contrast and focus pass in **every shipped
      theme variant** (`theme--dark`, each `color_scheme`) — not only the default.
- [ ] **CSS code health (G3).** No shipped unused/dead CSS; `!important` and specificity-war
      counts bounded; total CSS payload under the profile's budget.
- [ ] **Layout / tap targets (G6).** No element overflows the viewport and every tap target
      is **≥ 44×44 CSS px** at **360 / 768 / 1280**. (Absolute rules, independent of any
      approved visual baseline.)

## Who verifies what

| Criterion | Earliest catch | Verifier |
|---|---|---|
| Performance budget (G2) | brief budget → F | perf gate / T |
| Subresource Integrity (G4) | brief → F | security check / T |
| Selector over-reach (G1) | F (CSS-DOM workflow phase) → A whole-theme | A |
| Per-variant a11y (G5) | brief → F | T (axe) + U (variant matrix) + S |
| CSS code health (G3) | F (stylelint guards) | A / CSS-quality gate |
| Layout / tap targets (G6) | brief → F | U (viewport × variant) + S (absolute rules) |

A criterion that does not apply to a given story (e.g. a comment-only change touches no
third-party assets) is marked **N/A with a one-line reason** in the brief — it is never
silently dropped.
