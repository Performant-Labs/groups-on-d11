# @pl-audit gate tools — consuming the shared detection libraries

**Status:** mechanism decision for the gate-implementation wave (`#276` → §4 fork decided
**Option B** in `#279`). Companion to [`../GATE-COVERAGE-PROPOSAL.md`](../GATE-COVERAGE-PROPOSAL.md).

Per #279, the build pipeline's new quality gates **reuse the `@pl-audit/*` detection
libraries** (zero-dep ESM packages in the Website Audit repo) rather than re-implementing
them. This shares **libraries**, not pipelines — the two pipelines' docs/roles still never
reference each other (README "do not reference each other"). This doc defines *how* the gate
tools obtain and run those libraries, matching the existing `core/tools/` convention
(standalone script, deps via `npm install --no-save`, config from the profile, exit-code = pass/fail).

## Consumption mechanism (decided: pinned checkout)

The `@pl-audit/*` packages are an **internal, unpublished** monorepo (`website-audit/lib/*`,
`"private": true`). To avoid standing up registry/publish infra, gate tools consume them from
a **pinned local checkout**:

- The project **profile** declares `PL_AUDIT_DIR` (path to a checkout of
  `Performant-Labs/website-audit`) and `PL_AUDIT_REF` (a pinned commit/tag — reproducibility).
- A one-time bootstrap step clones/fetches that ref into `PL_AUDIT_DIR` (CI does the same).
- Gate tools `import` the dimension engines + render harness **by path** from
  `$PL_AUDIT_DIR/lib/...` — e.g. `import('${PL_AUDIT_DIR}/lib/perf/index.js')`. The packages
  are zero-build ESM, so they run directly from the checkout; only the browser deps
  (`playwright`) are `npm install --no-save`'d, exactly like `axe-check.cjs`.

**Why not a registry / git-dep now:** the packages are private/UNLICENSED and live in `lib/*`
subdirs (the repo root isn't `@pl-audit/*`), so `npm install github:…` wouldn't resolve them
by name. Pinned-checkout needs zero infra and is reproducible via `PL_AUDIT_REF`.
**Future upgrade (optional):** publish `@pl-audit/*` to GitHub Packages (private) and install
by version; the gate-tool API stays the same.

## The gate-tool pattern (mirrors `axe-check.cjs`)

Each gate is a `core/tools/pl-<dim>-gate.cjs` (or `.mjs`) that:
1. Reads the base URL + per-gate **budget** + theme-variant list from the **profile** (env/args).
2. Renders the **production-shaped build** via `@pl-audit/render-harness` (CSS/JS
   **aggregation ON** — see the perf note) at the required viewport(s) × variant(s).
3. Runs the `@pl-audit/<dim>` engine against that RenderContext.
4. **Scores on AUTHORED provenance only** — uses `@pl-audit/contract` `findingProvenance()`
   (#42) so `inherited` (parent/vendor) and `environment` (e.g. aggregation-off) findings are
   **reported but not gated against the subtheme**; `waived` (explicit `audit-waivers.json`)
   excluded from both. This is why `website-audit#42` was a prerequisite.
5. Exits non-zero when an authored finding breaches the profile budget; prints the findings
   (with provenance) for the role handoff.

## Per-gate → library map

| Gate (issue) | `@pl-audit` package | Notes |
|---|---|---|
| G1 cascade-health (`#281` gate-half) | `@pl-audit/css-arch` | whole-theme accumulated over-reach (A is per-diff today) |
| G2 performance (`#282`) | `@pl-audit/perf` | **must** run an aggregation-ON build; respects the env-artifact tag (#42) |
| G3 CSS quality (`#283`) | `@pl-audit/css-quality` | unused/duplicate/specificity/!important/payload |
| G4 security/SRI (`#284`) | `@pl-audit/security` | attributes theme-authored vs server (needs #42) |
| G5 per-variant a11y (`#285`) | `@pl-audit/a11y` | run per `theme--*` / `color_scheme` variant |
| G6 visual-absolute (`#286`) | `@pl-audit/visual` | absolute overflow/tap-target rules, baseline-independent |

## Wiring (per gate)
- Tool lands in `core/tools/`; add a row to `core/tools/README.md` (Tier / Deps / Config).
- The owning role (T for automated dimensions; A for G1 cascade-health) invokes it; `core/gates.md`
  records it as a gate. Budgets + variant list live in the **profile**, never hardcoded.
- The standing NFR acceptance criteria (`core/nfr-checklist.md`, #280) already declare the
  budgets these gates enforce — the gates are the automated backstop for that floor.

## Determinism & scope
- Gate tools are deterministic given a pinned `PL_AUDIT_REF` + a fixed build. No wall-clock in
  pass/fail logic.
- Provenance-scoped gating (authored-only) is the contract that keeps a subtheme from being
  failed for its parents'/environment's defects.
