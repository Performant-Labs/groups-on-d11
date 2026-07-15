# Pipelines — Index

This directory is the **single entry point** for the multi-agent pipelines in this guidance
library. Each pipeline is a distinct, self-contained way of working — **do not assume they
share anything.** Pick the one that matches the task; do not cross-wire them.

> ⚠️ **The pipelines are distinct and must not be conflated.** Editing one pipeline's files
> to "improve" another, or merging their agent prompts, breaks them. The website **build** and
> **audit** pipelines deliberately keep their own copies of shared concepts and **do not
> reference each other**, so they can diverge. The only sanctioned cross-reference is the build
> pipeline → the shared review-rigor gate (the `panel` dial + `dual-review.sh`) in `workflow/`.

## The pipelines

| Pipeline | Purpose | Location | Status |
|---|---|---|---|
| **🎨 Website Front-End (build)** | *Build* website front ends — implement CSS/components, mobile-first, WCAG, visual fidelity (O→F→A→T→U→S). | [`website-frontend/`](website-frontend/README.md) | **canonical** |
| **🔍 Website Audit** | *Audit* a website front end — O-W-O: find CSS layer-hierarchy / HTML / cascade problems, decompose into fix issues. Distinct sibling of the build pipeline. | **own repo:** [Performant-Labs/website-audit](https://github.com/Performant-Labs/website-audit) | **canonical (moved out)** |
| **🧱 Coding pipeline** | Test-first, general-purpose software development; per-story review-rigor dial (`none` / `second-opinion` / `panel`). | [`../workflow/`](../workflow/) (`workflow-coding-pipeline.md`, `dual-review.sh`, + role docs) | active, in `workflow/` |
| **🏛 Architecture audit** | Standalone module/coupling audit of an existing codebase (O→A→O). | [`../workflow/auditarchitecture.md`](../workflow/auditarchitecture.md) | active, in `workflow/` |

The website **build** and **audit** pipelines are separate by design: build *implements*,
audit *finds*. Audit produces fix issues that feed the build pipeline, but as a hand-off
deliverable — neither pipeline's files reference the other.

## Guides

- **Build — use it on a project:** [`website-frontend/GETTING-STARTED.md`](website-frontend/GETTING-STARTED.md)
- **Build — write a new platform plugin:** [`website-frontend/adapters/WRITING-AN-ADAPTER.md`](website-frontend/adapters/WRITING-AN-ADAPTER.md)
- **Audit — run an audit & the O-W-O flow:** now in its own repo → [Performant-Labs/website-audit](https://github.com/Performant-Labs/website-audit)

## Which one?

- **Building/implementing a website front end?** → Website Front-End (build) Pipeline (`website-frontend/`).
- **Auditing an existing website front end (CSS/HTML hierarchy, cascade)?** → Website Audit Pipeline → [Performant-Labs/website-audit](https://github.com/Performant-Labs/website-audit).
- **Writing application/backend code?** → the coding pipeline in `workflow/`.
- **Auditing an existing codebase's architecture?** → `workflow/auditarchitecture.md`.

## Notes for future agents

- **New pipelines go in a subdirectory here** (`pipelines/<name>/`) with their own README
  whose first lines state what the pipeline is and what it is *not*. Add a row above.
- The coding pipeline currently lives in `workflow/` (not yet migrated under `pipelines/`).
  It is indexed here in place; **do not move it** without updating the relative links in
  `workflow-coding-pipeline.md` and the `dual-review.sh` path the front-end pipeline
  references.
- `workflow/website-auditor.md` and `workflow/workflow-website-audit.md` are the **earlier,
  generic** website-audit templates. They are **superseded by** the
  [website-audit pipeline](https://github.com/Performant-Labs/website-audit) (now its own repo; W role, deterministic pre-scan, render
  harness, audit-flow + scope template). Prefer the new pipeline; the old files are left in
  place for history.
