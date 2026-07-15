# Adapter — Fastify + Eta + Tailwind 4 + HTMX/Alpine (utility-first server-rendered)

> ⚠️ **WEBSITE FRONT-END PIPELINE — PLATFORM ADAPTER.** This file supplies the
> *platform-specific* knowledge the core roles defer to. Pair it with a project profile
> (per-site values). Core principles: [`../core/principles.md`](../core/principles.md).
> Distinct from the coding pipelines in `workflow/`.

**Platform:** Server-rendered HTML from **Eta** templates, served by **Fastify**, styled
**utility-first with Tailwind CSS 4** (CSS-first config — no `tailwind.config.js`), with
**Flowbite** components and **HTMX + Alpine (CSP build)** for interactivity. **Applies to:**
any site whose front end is built this way, regardless of domain. Swap this file for a
different adapter to target a different stack.

This stack is **utility-first**, which inverts the assumptions of component-scoped-CSS
platforms (BEM/SDC): the **template is the primary styling surface** (utility classes on
elements), and hand-written CSS is a thin global layer of last resort. Read the sections below
with that inversion in mind. Concrete values (repo paths, URLs, script names, breakpoint
overrides) come from the **profile**, not here.

---

## Layer hierarchy

Style changes respect this order; the **highest correct layer wins**:

- **Layer 1 — design tokens.** A Tailwind `@theme { … }` block in the CSS entry file (colors,
  spacing, type scale, radii as theme variables). Token *values* live here; nothing else does.
- **Layer 2 — utility classes in the Eta templates.** The **primary** styling surface. Layout,
  spacing, color, type, responsive behavior are expressed as utilities on the markup
  (`class="mt-4 md:flex …"`). Most style changes happen *here*, in the template — not in CSS.
- **Layer 3 — hand-written CSS** in the entry file (a `@layer components` block or plain rules).
  Use **only** when utilities cannot express it: SVG-chart styling, focus-ring geometry,
  pseudo-elements (`::-webkit-…`), stateful container-class toggles (`.mode-x .thing`),
  HTMX/Alpine transition states. This layer is **global** and is the one place over-reach can
  happen — it is what the blast-radius gate guards.
- **Never:** inline `style="…"` doing layout; `!important` (if you want it you are at the wrong
  layer — move up); arbitrary-value utilities (`mt-[13px]`) where a token/standard step exists;
  raw hex in templates where a theme token exists.

**Telltales of wrong-layer work:** any `!important`; a **new** ID selector or broad
element/attribute selector in the hand-written CSS; a `style=` attribute doing layout; raw hex
in a template; a `max-width` media query with no comment justifying why it can't be mobile-first
(see principle 1). The first three are caught mechanically by the blast-radius gate (below).

## Component model & reuse source

- **Components are Eta partials** (and layouts). A partial's "schema" is its **`it.*` interface**
  — the data fields it reads. **Read the partial before referencing or passing props** — never
  invent an `it.` field from memory (the utility-first analog of "read the `.component.yml`").
- **Reuse source (principle 2):** the project's **partials directory** + **layout templates** +
  the **Flowbite** component set. Search there before creating a new partial; a new partial that
  duplicates a configurable existing one (or whose handoff lacks the reuse search) is a
  block-level finding. The concrete partial/layout paths are in the **profile**.
- **No version/registration rule:** unlike Canvas/SDC there is no `component_version` hash and no
  registry to update — a partial is reusable as soon as it exists and is `include`-d.
- **Interactivity:** HTMX drives server-fragment swaps; Alpine (CSP build) drives local UI state.
  Because the **CSP Alpine build forbids inline expressions**, behavior lives in registered
  components/handlers (per the project's client code), not inline `x-…` expressions with
  arbitrary JS — keep that in mind when reusing or extending an interactive partial.

## Platform commands

- **"Cache-clear" before any T1 check = rebuild the Tailwind CSS.** Tailwind 4 JIT only emits a
  utility if it scanned that class in a source file (`@source` globs). **A new utility used in a
  template is invisible until the CSS is rebuilt** — so the stylesheet must be rebuilt before
  T1, the same way a CMS cache must be cleared. The underlying command is the Tailwind CLI
  (`@tailwindcss/cli -i <entry> -o <out>`); the project's exact script name (e.g. a `css:build`
  npm script) is in the **profile**.
- **Dev server:** the project's server-run script (e.g. a `dev` script running the Fastify app);
  named in the **profile**.
- **Local verification URL:** host/port from the **profile**. If the app is **auth-gated**, the
  profile also supplies the **authenticated-session recipe** (a seed + a saved browser
  `storageState`) — F/T/S load it so headless and visual checks hit real pages, not a login
  redirect.

## Responsive / nav convention

- **Breakpoints:** Tailwind's scale (default `sm 640 · md 768 · lg 1024 · xl 1280` unless the
  profile declares overrides in the `@theme` block). Expressed as utility variants (`md:`,
  `lg:`) **in the templates** — there is no separate breakpoint stylesheet. Mobile-first is the
  default direction of the utility system (unprefixed = smallest; `min-width:` variants scale up).
- **Nav:** the common pattern is a **Flowbite drawer** — an off-canvas sidebar toggled by a
  hamburger that is shown only below a breakpoint (e.g. `sm:hidden`) and a static sidebar at/above
  it. Where a drawer is used, the **breakpoint in the template utilities and any width constant in
  the drawer's JS must agree** — a mismatch creates a window where both or neither nav shows.
  Verify both. The exact breakpoint + nav element ids are in the **profile**.

## Blast-radius gate (the gate A's core role dispatches to)

`tools/cascade-map.cjs` + `tools/perturb.cjs` are **N/A on this stack** — utility-first CSS has
no component-scoped selectors to leak past a boundary, and there is no `data-component-id` anchor.
**The gate is a linter** over the only surface where global over-reach can occur: the
**hand-written CSS** (Layer 3, the entry file).

- **Tool:** **Stylelint** (`stylelint-config-standard` base) run on the entry CSS file. The
  project's config path is in the **profile**; the **ruleset must be baseline-calibrated** against
  the existing CSS so it caps *new* escalation without flagging legitimate existing rules.
  Recommended rules (calibrate the numeric ceilings to the project's current maximum):
  - `declaration-no-important: true` — no `!important` anywhere.
  - `selector-max-id` — cap at the count of existing legitimate ID-scoped rules (often `0`–`1`);
    blocks new ID hacks.
  - `selector-max-specificity` — a ceiling at the project's current max specificity + small
    headroom; blocks specificity escalation.
  - `selector-max-compound-selectors` / `selector-max-type` — cap at the existing deepest chain /
    broadest element selector; blocks new over-broad global selectors (the real over-reach risk).
  - `no-duplicate-selectors`, `declaration-block-no-duplicate-properties` — hygiene.
  - **Tailwind 4 compatibility:** allow the CSS-first at-rules so the linter does not false-flag
    them — `@import, @theme, @source, @apply, @layer, @variant, @custom-variant, @utility, @config`
    (e.g. via `at-rule-no-unknown` `ignoreAtRules` or an allow-list). Do **not** use a Tailwind-v3
    preset.
- **Verdict mapping:** any Stylelint **error** on the changed CSS = `block`; a **warning** =
  `warn`. Pre-existing violations the change didn't introduce are noted, not blocked (clean the
  baseline separately). Honor any project blast-radius waivers in the profile.
- **Secondary (not the primary gate):** a grep over the changed templates for inline-layout
  `style=` and raw hex where a token exists — A flags these by eye; there is no Eta-aware linter.

> CSS-hierarchy / cascade *auditing* of an existing front end is the separate **Website Audit
> Pipeline**'s job, not this build adapter's.
