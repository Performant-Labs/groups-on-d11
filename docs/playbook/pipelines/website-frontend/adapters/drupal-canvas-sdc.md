# Adapter — Drupal + Canvas + SDC (DripYard layer system)

> ⚠️ **WEBSITE FRONT-END PIPELINE — PLATFORM ADAPTER.** This file supplies the
> *platform-specific* knowledge the core roles defer to. Pair it with a project profile
> (per-site values). Core principles: [`../core/principles.md`](../core/principles.md).
> Distinct from the coding pipelines in `workflow/`.

**Platform:** Drupal with Canvas + Single-Directory Components (SDC), themes built on the
DripYard base theme. **Applies to:** any site whose front end is a DripYard-derived Drupal
theme. Swap this file for a different adapter to target a different stack.

The core roles cite the sections below by name (e.g. *"override per the adapter's Layer
hierarchy"*). Project-specific values (theme machine name, paths, URLs) come from the
profile, not here.

---

## Layer hierarchy

CSS overrides respect this order; the highest correct layer wins:

- **Layer 1 — config** — admin/config values via `drush` (e.g. `drush php-eval`, theme settings).
- **Layer 3 — theme tokens** — design tokens in the theme's `css/base.css` (or `css/_variables/`). Token overrides use the theme-zone wrapper, e.g. `html .theme--white { --token: value; }`.
- **Layer 5 — component CSS** — per-component stylesheets added via `libraries-extend` in `<theme>.libraries.yml`, living in `css/components/[name].css`.
- **Layer 4 — rendered component output** — Canvas/SDC output. **Never patched directly.**

Telltales of wrong-layer work: `!important`; ID selectors; selector chains > 3; inflated
prefixes other than the legitimate `html .theme--white {}`; component-specific selectors in
`base.css`; token definitions inside a component file; a component's internals styled from
another file; `@import` inside a component instead of `libraries-extend`.

## Component model & reuse source

- **Schema source of truth:** each component's `.component.yml`. Never write a prop, slot,
  class, or `component_id` from memory — read the schema first.
- **Reuse source (principle 2):** the **DripYard base theme's component library** (parent
  theme `components/`) plus any intermediate theme in the chain. Search there before
  creating a new component in the active (child) theme.
- **Canvas constraint:** `component_version` must **never** be NULL/empty — Canvas throws
  `OutOfRangeException`. Preserve the existing valid hash when patching; for new instances
  copy the valid hash from the `.component.yml` or an existing instance.

## Platform commands

- **Cache clear (run before any T1 check):** `ddev drush cr` (or `drush cr` without ddev).
- **Local URL + theme param:** from the profile (`site_url`, `theme_machine_name`).

## Responsive / nav convention

`navbar-expand-lg` pattern: hamburger below the large breakpoint, full inline nav at/above
it. The canonical breakpoint is **992px** unless the project profile overrides it. Verify
the CSS *and* the JS (`isDesktopNav()` width constant) agree — a mismatch creates a window
where both or neither nav is visible.

## Blast-radius gate (the gate A's core role dispatches to)

This is a **component-scoped CSS stack**, so the gate is this pipeline's own
`tools/cascade-map.cjs` + `tools/perturb.cjs` (containment-escape detection via the component
anchor `data-component-id`, then ACTIVE/DORMANT classification). Run them and apply the
verdict mapping exactly as described in the core role's *Per-change blast-radius gate*
(component-scoped branch): a new ACTIVE boundary-escape on a high-risk layout/box/position
property = `block`; a new DORMANT escape or an active escape on a low-risk prop = `warn`;
pre-existing escapes the change didn't introduce are noted, not blocked.

> CSS-hierarchy / cascade *auditing* of this platform (the scanner config, injected-var
> allowlist, render harness) lives in the separate **Website Audit Pipeline**'s
> `drupal-canvas-sdc` adapter — kept distinct so the two can diverge.
