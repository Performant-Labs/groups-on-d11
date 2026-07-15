# Core Tools — Website Front-End (build) Pipeline

> ⚠️ **WEBSITE FRONT-END (BUILD) PIPELINE — CORE TOOLS (platform-agnostic engines).** Distinct
> from the Website Audit Pipeline and the coding pipelines. Each tool is an engine;
> platform/project specifics are passed in as flags/config (adapter + profile), never hardcoded.
> (Audit tools — `css-scan.py`, `render-inspect.cjs` — live in the separate Website Audit Pipeline.)

| Tool | Tier | Deps | Config source |
|---|---|---|---|
| `axe-check.cjs` | T2 | `playwright`, `@axe-core/playwright` | `AXE_BASE_URL` from profile; paths as args |
| `state-invariants.spec.js` | T2.5 | `@playwright/test` | `STATE_INVARIANTS_CONFIG` → a project copy of `state-invariants.config.example.json` |
| `cascade-map.cjs` | A per-change gate | `playwright` | `<URL>` + optional `config.json` (`componentAttr`) |
| `perturb.cjs` | A per-change gate | `playwright` | `<URL> <cascade-map.json>` + `--max-targets/--budget-seconds/--waivers` |
| `pl-perf-gate.mjs` | T (perf gate, #282) | `playwright` (via `$PL_AUDIT_DIR/node_modules`) | `PL_AUDIT_DIR` + `PERF_MIN_SCORE` from profile; `<url …>` as args |
| `pl-audit-gate.mjs` | T / A gate (generic, #281 #283–#286) | `playwright` (+axe for a11y) via `$PL_AUDIT_DIR/node_modules` | `PL_AUDIT_DIR` + `DIM` + `MIN_SCORE` from profile; `<url …>` (one per variant) |

> `cascade-map.cjs` + `perturb.cjs` are this pipeline's **own copies** (the build and audit
> pipelines keep separate copies so they can diverge). A uses them for the per-change
> blast-radius gate — see `core/roles/architecture-reviewer.md`.
>
> `pl-perf-gate.mjs` is the **reference @pl-audit gate tool** (#279 Option B): it *imports* the
> shared `@pl-audit/*` detection libraries from a pinned checkout (`PL_AUDIT_DIR`) — sharing
> libraries, not the audit *pipeline*. It gates the **authored** perf score only (env/inherited/
> waived findings are reported, not failed). See `pl-audit-gates.md`. The other dimension gates
> (G1/G3–G6) follow this same pattern.
>
> `pl-audit-gate.mjs` is the **generic** form of that gate — `DIM=<a11y|css-arch|css-quality|
> perf|security|visual>` selects the dimension; it picks the right authored-score basis per
> dimension (perf → recompute excluding env artifacts; css-arch → `scoreOwn`; others → `score`).
> Pass one URL per theme variant (#285). It supersedes the per-dimension approach; `pl-perf-gate.mjs`
> is kept as the worked reference.
>
> **Known limitation (security/#284):** when the audited theme is NOT the active rendered theme
> (e.g. a v1 theme still serving global scripts), its third-party assets are attributed
> `authored` and may be gated against the new theme. Fair attribution there needs the deferred
> active-theme detection (website-audit#42 follow-up).

## Node deps

```bash
npm install --no-save playwright @axe-core/playwright @playwright/test
npx playwright install chromium
```

## Example invocations (values from a project profile)

```bash
# T2 accessibility
AXE_BASE_URL=https://site.ddev.site:8493 node axe-check.cjs / /articles

# T2.5 interaction
STATE_INVARIANTS_CONFIG=./state-invariants.config.json \
  STATE_INVARIANTS_ONLY=url-filter,pager npx playwright test state-invariants.spec.js
```
