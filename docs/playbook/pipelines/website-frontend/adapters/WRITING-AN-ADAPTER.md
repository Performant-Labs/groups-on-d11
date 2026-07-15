# Writing an Adapter (a Platform Plugin)

> ⚠️ **WEBSITE FRONT-END PIPELINE — PLUGIN AUTHORING GUIDE.** How to add support for a new
> platform/stack without touching the core. Distinct from the coding pipelines in `workflow/`.
> Overview: [`../README.md`](../README.md). Skeleton to copy: [`_adapter-template.md`](_adapter-template.md).


> **Note (build pipeline):** the *audit-only* sections an adapter can carry — the
> injected-var scanner allowlist and `css-scan.py`/`render-inspect.cjs` tool config —
> belong to the separate **Website Audit Pipeline**'s adapter, not here. A build adapter
> only needs layer hierarchy, component model + reuse source, platform commands, and the
> responsive/nav convention.

An **adapter** is the platform plugin. It supplies the platform-specific "how" that the
platform-agnostic core roles defer to. Write one and the whole pipeline — roles, gates,
verification tiers, tool engines — runs on a new stack with **zero changes to core**.

---

## The one decision you make over and over: which tier?

Every piece of knowledge belongs to exactly one tier. Classify it before you write it:

| If the knowledge… | …it belongs in | Example |
|---|---|---|
| Is true for **any** website front end | **Core** (don't put it in the adapter) | "mobile-first", "reuse before create", "no specificity hacks", the verification ladder |
| Changes when you switch **platform/stack** but not when you switch site | **Adapter** (this file) | CSS layer hierarchy, component schema format, cache command, which vars are injected |
| Changes for **every site** on the same platform | **Profile** (not the adapter) | site URL, theme name, file paths, brand colors, the stateful-surface list |

**Decision rule:** *"Would this be the same on the next site built with this same stack?"*
Yes → adapter. No → profile. *"Would this be the same on a totally different stack?"* Yes →
it's already in core; don't repeat it.

Common mistakes:
- Putting a **site URL or theme name** in the adapter (that's profile — keep adapters reusable
  across every site on the platform).
- Restating **core principles** in the adapter (mobile-first is core; the adapter only says
  *how* breakpoints are expressed on this stack).
- Hardcoding platform facts in the **tool scripts** (they're engines — pass facts via flags/config).

## The required sections

Copy [`_adapter-template.md`](_adapter-template.md) to `adapters/<platform>.md` and fill each
section. Keep the headings exactly — the core roles cite them by name.

1. **Layer hierarchy** — the ordered places a style change can live, highest-correct-wins, and
   the telltales of wrong-layer work. Answer: where do design tokens live? component styles?
   what must never be patched directly?
2. **Component model & reuse source** — how components are defined (the schema/source-of-truth
   file), and **where existing components live** so F can reuse before creating. Plus any
   hard "never do" rules (version fields, registration).
3. **Platform commands** — cache clear/rebuild, dev server, how a local verification URL is
   formed. (Concrete host/port/id come from the profile; here you give the *shape*.)
4. **Responsive / nav convention** — the breakpoint system and any nav pattern, and *where*
   the breakpoint is defined (CSS, JS, tokens) so the pipeline can check they agree.

> A **build** adapter has these four sections. The audit-only sections (injected-var scanner
> allowlist, `css-scan.py`/`render-inspect.cjs` config) belong to the **Website Audit
> Pipeline**'s adapter — kept separate so the two can diverge.

## Worked example — sketching a Next.js + design-tokens adapter

To prove the model maps to a stack with no CMS, here's how each section would be answered for a
React/Next.js app with CSS variables for tokens, CSS Modules for component styles, and a shared
`@acme/ui` component package. (This is an illustration; flesh it out before real use.)

- **Layer hierarchy:** `1` global token definitions (`styles/tokens.css` `:root` + theme
  classes) → `3` component CSS Modules (`*.module.css`) → never patch the framework's rendered
  DOM via global selectors or inline `style={{}}` overrides. Telltales: `!important`, global
  selectors reaching into another component's module classes, inline style props doing layout.
- **Component model & reuse source:** components are React components; the **reuse source** is
  the shared `@acme/ui` package (and existing app components in `components/`) — search there
  before adding a new component. Source of truth for a component's API is its TypeScript props,
  not a YAML schema. No version-hash rule (unlike Canvas).
- **Platform commands:** dev server `next dev`; **no cache-clear step** (HMR); local URL is
  `http://localhost:<port><path>` (port from profile).
- **Responsive / nav convention:** breakpoints from the design-token scale (`--bp-*`) or the
  Tailwind config; nav switches at the `md` breakpoint — defined once in the token file, so the
  pipeline checks CSS and the nav component agree on it.

Notice what *didn't* change: the roles, the four verification tiers, the gates, mobile-first,
reuse-before-create, the tool engines. Only the four adapter sections differ. That's the plugin
boundary working.

## Test your adapter before trusting it

1. **One implementation cycle** on a trivial change: do F's layer decisions, A's review, and T's
   verification all reference your sections sensibly?
2. **Component reuse:** does F correctly find and reuse an existing component from your declared
   reuse source rather than creating a duplicate?
3. **Mobile-first:** does A flag a desktop-first authoring attempt against your breakpoint convention?

## Checklist

- [ ] All four sections filled; headings unchanged.
- [ ] No site-specific values (URLs, names, paths) — those go in the profile.
- [ ] No restating of core principles — only the platform *how*.
- [ ] Reuse source names where existing components live.
- [ ] Breakpoint location named (CSS/JS/tokens) so the pipeline can check they agree.
- [ ] Unmissable header marking it a Website Front-End (build) Pipeline adapter.
- [ ] Added to the adapter list in [`../README.md`](../README.md) (Contents).
