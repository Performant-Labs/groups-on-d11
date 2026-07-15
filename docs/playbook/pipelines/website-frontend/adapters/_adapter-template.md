# Adapter — TEMPLATE (copy to build a new platform adapter)

> ⚠️ **WEBSITE FRONT-END PIPELINE — PLATFORM ADAPTER (skeleton).** Copy this file to
> `adapters/<platform>.md` and fill every section. The core roles defer to these sections
> by name, so keep the headings. Distinct from the coding pipelines in `workflow/`.


> **Note (build pipeline):** the *audit-only* sections an adapter can carry — the
> injected-var scanner allowlist and `css-scan.py`/`render-inspect.cjs` tool config —
> belong to the separate **Website Audit Pipeline**'s adapter, not here. A build adapter
> only needs layer hierarchy, component model + reuse source, platform commands, and the
> responsive/nav convention.

An adapter supplies the **platform-specific "how"** the platform-agnostic core defers to.
If you can answer the sections below for your stack (WordPress block themes, a Next.js
design system, a Rails/ViewComponent app, plain HTML/CSS), you can run this pipeline on it
without touching core. Per-site values stay in the project profile, not here.

---

## Layer hierarchy
The ordered places a style change can live, highest-correct-wins, and the telltales of
wrong-layer work for this stack. (Where do design tokens live? Component styles? What must
never be patched directly?)

## Component model & reuse source
How components are defined (schema/format), the source-of-truth file, and **where existing
components live so they can be reused before creating new ones**. Any "must never do" rules
(e.g. version fields, registration requirements).

## Platform commands
Cache clear / rebuild, dev server, and how to form a local URL for verification. (The
concrete values — host, port, theme/app id — come from the profile.)

## Responsive / nav convention
The breakpoint system and any nav pattern, plus where the breakpoint is defined (CSS, JS,
tokens) so the pipeline can check they agree.
