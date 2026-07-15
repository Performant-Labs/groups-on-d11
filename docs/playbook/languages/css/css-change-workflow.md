# CSS Change Workflow

An AI-assisted process for making CSS changes at the **correct layer** in the project's design-system hierarchy. Prevents the per-page CSS anti-pattern, eliminates **per-edit** `!important`, and surfaces fighting overrides early. It governs each *change*; on its own it does **not** bound the cascade's *accumulated* state — see [Known limitations & pipeline integration](#known-limitations--pipeline-integration). Originally human-initiated; under the [Website Front-End Pipeline](../../pipelines/website-frontend/README.md) it is now a **required step of F** for every CSS change, with its stylelint guards **non-opt-in** (#281; proposal §3 G1) — see [Adopting this workflow on a new project](#adopting-this-workflow-on-a-new-project).

This doc is **stack-agnostic**. The 5-layer model below applies to Tailwind, plain CSS-with-tokens, Vue + scoped styles, CMS theming, or any combination. Stack-specific layer mappings are at the bottom under [Layer mappings by stack](#layer-mappings-by-stack).

> [!IMPORTANT]
> This workflow assumes a project with a **token-driven design system** — that is, a base layer of CSS custom properties (or Tailwind `@theme` block, or equivalent) from which colour, spacing, and typography decisions cascade. If your project is using utility-soup-only with no design tokens, the layer model below collapses to two layers (global + scoped) and Steps 2–3 simplify accordingly. The workflow itself still applies.

---

## Known limitations & pipeline integration

> Added 2026-06 after a root-cause analysis of a theme this workflow helped build
> (`Performant-Labs/website-audit:docs/playbook-quality-gap-analysis.md`; tracked in
> `Performant-Labs/playbook#276`). The model followed this workflow faithfully — zero
> per-edit `!important`, logged bottom-up/top-down traces, recorded layer rulings — and the
> theme *still* audited with selector over-reach and specificity wars. That is not a model
> error; it is a property of where this workflow sits and what it measures. Read these before
> relying on it as a quality guarantee.

1. **It governs each edit, not the accumulated cascade.** Every individual change can be
   locally correct (right layer, no `!important`, under the specificity cap) while the
   *sum* of many changes drifts into over-reach and specificity wars. This workflow has no
   step that measures or budgets the **whole stylesheet's** accumulated state.

2. **It is per-change — and historically not a gated pipeline phase.** A per-diff
   architecture gate that *does* run typically forgives "pre-existing over-reach the change
   didn't introduce," so *accumulation* is gated by nobody. **Resolved for Website Front-End
   Pipeline projects (#281):** this workflow is now a **required step of F** for every CSS
   change (not human discretion) — see [Adopting this workflow on a new project](#adopting-this-workflow-on-a-new-project).
   The accumulated over-reach/specificity check (a whole-theme gate, proposal §3 G1
   gate-half) is still pending; the required-phase status closes the human-discretion half of
   this gap.

3. **The per-edit specificity cap is satisfiable while still cascade-fighting.** A cap like
   `selector-max-specificity: "0,4,0"` bounds one selector, not the war: `:has()`, `:not()`,
   and doubled-class patterns (`.block.block--variant …`) stay under the cap yet keep
   escalating against an entrenched style. The cap is necessary, not sufficient.

4. **"Fix at the highest correct layer to win the cascade" is locally optimal but globally
   accretive when the project sits on a heavy inherited stack.** A subtheme overriding a
   deep parent theme (e.g. a 100+-file base) must, by construction, author selectors more
   specific than the parent's to win — so faithfully applying Steps 2–4 *produces*
   specificity accretion. When you are overriding a fat parent, treat reducing the override
   surface (or pushing fixes upstream into the parent) as part of "the highest correct
   layer," and watch the accumulated specificity, not just each diff.

5. **The deterministic enforcement is opt-in — except under the front-end pipeline.** The
   stylelint/pre-commit guards in
   [Adopting this workflow on a new project](#adopting-this-workflow-on-a-new-project) only
   exist if the project ran that bootstrap; absent it, even the per-edit guarantees are
   advisory. **For Website Front-End Pipeline projects these guards are now non-opt-in
   (#281)** — the bootstrap must be done before F runs. For other stacks the enforcement
   remains opt-in.

---

## Roles

| Actor | Responsibility |
|---|---|
| **Human** | Describe the desired change, approve the layer/scope, provide visual sign-off |
| **the AI** | Trace the variable chain, identify the correct layer, make the change, verify, log |
| **Deterministic tools** (linter, pre-commit hooks) | Enforce mechanically — flag `!important`, direct property overrides, and changed files not recorded in the change log |

---

## The 5-layer model

Every token-driven design system can be described by five layers, ordered from broadest impact to narrowest. **Higher layers are cheaper to maintain.** The workflow's discipline is: rule out higher layers before proposing a fix at a lower one.

| Layer | What lives here | Example mechanism |
|---|---|---|
| **L1 — Source of truth / config** | Brand/system canonical values: brand colour, base font size, dark-mode flag | CMS theme settings, env vars, root design-token JSON, `:root { --token: }`, Tailwind `@theme {}` |
| **L2 — Derived / computed values** | Values automatically derived from L1 via scale rules, colour functions, or build-time generation | Colour scales via `oklch()` / `color-mix()`, modular type ramp, spacing-scale steps |
| **L3 — Theme variants / contextual zones** | Cross-cutting token overrides scoped to a "zone" or variant (dark mode, brand variant, surface vs primary) | `[data-theme="dark"] { --color-surface: ... }`, `.theme--primary { --link-color: ... }`, `html.brand-x` |
| **L4 — Global semantic component classes** | Reusable component patterns reached across the app (`button`, `card`, `pill`) | `@layer components` (Tailwind v4), global CSS file with semantic class names, BEM block definitions |
| **L5 — Component-scoped or instance overrides** | Single-component or single-instance tweaks | Vue SFC `<style scoped>`, CSS modules, scoped class on one route, library-level component override |

The higher the layer, the more places the change applies. The lower the layer, the more risk of fighting overrides accumulating across sessions.

### Token kinds

The model applies to **all** token kinds, not just colour. Quick examples of where each kind tends to live:

| Token kind | Typical L1 | Typical L2 derivation | Common L4–L5 risk |
|---|---|---|---|
| Colour | `--color-brand` | `oklch()` / `color-mix()` shade ramp | Brand drift from one-off hex codes at L5 |
| Spacing | `--space-1` (e.g., `0.25rem`) | Modular scale: `--space-2` = `--space-1 * φ` | Inconsistent gaps when L5 hard-codes `padding: 14px` |
| Typography | `--font-size-base`, `--font-sans` | Type ramp via `clamp()` and modular scale | Off-scale font sizes injected at L4 break vertical rhythm |
| Border radii | `--radius-md` | `--radius-lg` = `--radius-md * 2` | Inconsistent corner shapes when L5 sets `border-radius` directly |
| Shadows | `--shadow-color`, `--shadow-md` | Layered shadow recipes derived from a single colour | L5 shadow overrides reduce dimensional consistency |
| Z-index scale | `--z-base`, `--z-overlay`, `--z-modal` | (rarely derived) | Stacking-context wars when L5 sets arbitrary integers |
| Animation timing | `--duration-fast`, `--ease-out` | (rarely derived) | Inconsistent motion when L5 inlines `transition: ... 250ms` |

Z-index in particular is a poor fit for L5 because stacking context interactions are global by nature — keep z-index decisions at L4.

---

## Outside the layer model

Some CSS isn't a value — it's a **condition** or a **structural at-rule**. These don't get their own layer; they attach to the layer of the thing they modify (for conditions) or default to L4 (for structural at-rules).

### State pseudo-classes

Style for an interaction state lives at the same layer as the resting style of the same selector.

- `:hover` — pointer/cursor over the element
- `:focus` — keyboard or programmatic focus
- `:focus-visible` — focus that the browser deems "should show a ring" (keyboard, not mouse). Prefer this over `:focus` for ring/outline styling so mouse users don't get the noisy ring
- `:active` — being clicked or activated
- `:disabled` — disabled state (form controls, ARIA-disabled buttons)
- `:visited` — visited link (with privacy-restricted property set)
- `:checked`, `:indeterminate` — form control state

A button's `:hover` style sits in the same `@layer components` block as the button's resting style. A pill's `:focus-visible` ring sits in the same component class. **Don't** push interaction states down a layer "because they're separate" — they're not.

### Responsive and contextual conditions

Same rule: the at-rule attaches to the layer of the rule it wraps.

- `@media (min-width: …)`, `@media (prefers-reduced-motion)`, `@media (prefers-color-scheme: dark)`
- `@container (...)`
- `@supports (...)`

> **Author mobile-first.** The default responsive direction is **`min-width`**: the unprefixed rule is the *mobile base*, and breakpoints layer larger-screen enhancements on top. `@media (max-width: …)` is the exception (a targeted narrow-only patch), not the default. See [`./responsive.md`](responsive.md).

```css
/* Still L4 — responsive variant of an L4 component class.
   Base = mobile; min-width enhances upward (mobile-first). */
@layer components {
  .btn-primary { padding: var(--space-1) var(--space-3); }   /* mobile base */
  @media (min-width: 641px) {
    .btn-primary { padding: var(--space-2) var(--space-4); } /* desktop enhancement */
  }
}
```

`prefers-color-scheme: dark` is the conventional trigger for L3 dark-mode token overrides — it's still L3, just media-gated.

### Structural at-rules

These declare *things*, not *values*. They default to L4.

- `@keyframes` — animation definitions
- `@font-face` — font-file declarations (in `app-fonts.css` or equivalent — **never** at L5)
- `@property` — typed custom-property registrations (lets the browser interpolate them)
- Print stylesheets (`@media print { ... }`)

Always at L4 because they're shared infrastructure. A keyframe defined in a single Vue SFC's `<style scoped>` cannot be reused — that's an anti-pattern.

`@property` registrations have one wrinkle: they belong with their token. If `--gradient-angle` is L1 (a design token), its `@property` registration sits next to the L1 declaration. If `--btn-shake-amplitude` is L4-component-internal, its registration lives in `@layer components`.

---

## The Steps

### Step 1 — Human: Describe the desired change in plain English

Open the AI and describe what looks wrong or what you want to achieve. **No CSS, no file names, no variable names required.**

> *"The footer link colour is too light."*
> *"I want the header to use our brand orange as the background."*
> *"The body text feels too small on mobile."*

This is the only required input to start the loop.

---

### Step 2 — the AI: Run the trace and check every higher layer first

the AI does **two passes** — bottom-up trace to find the origin, then a top-down eligibility check to find the highest layer where the fix is correct. Nothing is proposed until each higher layer has been explicitly ruled out.

#### Modifying an existing token vs. adding a new one

Before Pass 1, the AI determines whether the requested change can be expressed by **modifying an existing token** or whether it requires **adding a new token to the design system**. The two paths share Step 3 (human approval) but differ in scope:

| Path | What happens at Step 2 | What Step 3 approves |
|---|---|---|
| **Modify existing** | Standard two-pass trace below | Layer + scope of the modification |
| **Add new token** | Trace shows there is **no existing token** that can carry this change. AI proposes a new token (name + initial value + target layer, usually L1) and notes which existing tokens it should be derived-from-or-related-to | Two things: (1) extending the design system at all, and (2) the proposed name, value, and layer of the new token |

The "add new token" path is more deliberate because adding is harder to reverse than modifying — every consumer that adopts the new token becomes coupled to its name. When in doubt, the AI proposes "modify" first. The human can request "add" if modify isn't sufficient.

A new token's first appearance in the change log uses the `[added]` tag (see [Change Log](#change-log)).

#### Pass 1 — Bottom-up trace (find the origin)
```
Property:      color on <a> inside .site-footer
Current value: oklch(0.48 0.12 264)  [computed]
Declared by:   .theme--primary { --link-color: var(--color-brand-text); }
Comes from:    L3 (theme-primary zone)
Traces to:     --color-brand-text (L2 derived) → brand_color (L1 config)
```

---

**DOM-inspection gate — mandatory before Pass 2.**

Pass 2 cannot start until **one** of the following is true:

- **(a)** the relevant ancestor DOM chain has been inspected with live-page evidence — Tier 1 (curl + grep) is sufficient; Tier 2 (ARIA structural read) is used when JS renders into the chain — and the inspected wrappers and their applied width/padding/overflow constraints are recorded in the trace worksheet; **or**
- **(b)** the proposed change is unambiguously at **L1** (config) or **L3** (variant-token override), where no DOM wrapper is involved.

Any L4 or L5 change that targets a structural wrapper (header, region, layout container, a route's outer element, etc.) **requires (a)**. Writing defensive selectors for wrappers that have not been DOM-verified is the anti-pattern this gate exists to prevent — it produces stylesheets full of selectors for elements that never render on the page they were meant to fix, and once committed those selectors silently rot.

The verification protocol is defined in [`../../testing/verification-cookbook.md`](../../testing/verification-cookbook.md) §Tier 1 and §Tier 2. Use the fastest tier that answers *"does this wrapper render, and does it impose the constraint we're trying to defeat?"*

**Worksheet addition — Pass 2 entry gate:**
```
DOM inspection evidence (required for L4/L5 structural fixes):
  [ ] Tier 1: wrapper exists in rendered HTML       (command run + match/no-match)
  [ ] Tier 1: wrapper has a width/padding/overflow
              cap imposed by upstream CSS           (file + line)
  [ ] Tier 2: (only if JS-rendered)                 (landmark path)
  [ ] N/A — change is L1 or L3                      (explain)
```

If a row is checked but the evidence string is missing, the gate is closed. Pass 2 does not start.

---

#### Pass 2 — Top-down eligibility (rule out higher layers first)

**Worked example A — colour change ("the footer link colour is too light"):**
```
L1 check: Is the canonical config value wrong?
          → brand_color is set to #1B3A6B — correct brand value. NOT the fix.

L2 check: Is the derived value computed incorrectly from L1?
          → --color-brand-text resolves to white (correct over a dark background).
             The problem is the link colour, not brightness detection. NOT the fix.

L3 check: Can a variant-zone token override solve this?
          → YES. This is a deliberate brand deviation from the auto-derived colour.
             Correct layer: L3. Scope: all links in primary zones, every page.

→ Proposed fix: L3
   html .theme--primary { --link-color: #F4A942; }
   File: css/base.css
   Ruling: L1 and L2 checked and ruled out.
```

**Worked example B — spacing change ("the case-row padding feels too tight on mobile"):**
```
L1 check: Is the canonical spacing scale wrong?
          → --space-1 = 0.25rem, --space-2 = 0.5rem, etc. The scale is internally
             consistent and used elsewhere correctly. NOT the fix.

L2 check: Is the derived padding computed incorrectly?
          → .case-row uses padding: var(--space-2) var(--space-3) — derives correctly
             from L1. Tight feel is a deliberate density choice at default viewport,
             not a bug in derivation. NOT the fix.

L3 check: Is there a "compact" or "comfortable" density variant zone?
          → No density-variant zones exist in this project. Adding one would be a
             new-token request (see "Modifying vs adding"). NOT the fix today.

L4 check: Should the .case-row component class itself carry roomier padding on
          mobile and tighten up at the desktop breakpoint?
          → YES. This is a responsive concern of the component, attaches to the
             same L4 selector. Mobile-first: the roomier padding is the base;
             the tighter desktop density is layered on with @media (min-width).
             Correct layer: L4. Scope: every .case-row.

→ Proposed fix: L4 (mobile-first — base = mobile, min-width enhances upward)
   @layer components {
     .case-row { padding: var(--space-3) var(--space-4); }   /* mobile base — roomier */
     @media (min-width: 641px) {
       .case-row { padding: var(--space-2) var(--space-3); } /* desktop density */
     }
   }
   File: src/assets/input.css
   Ruling: L1+L2+L3 checked and ruled out. Responsive variant attaches to L4
   per "Outside the layer model"; authored mobile-first per responsive.md.
```

Human sees the full two-pass report — nothing has been written yet.

---

### Step 3 — Human: Approves the layer and scope

the AI presents options **ordered from highest to lowest layer**. The human chooses one — or overrides downward with a reason.

**Options presented in this order:**

1. **L1 — Config fix** *(only shown if L1 is the root cause)*
   → the AI updates the canonical config (CMS setting, root token file, env value). CSS is not touched.

2. **L3 — Variant-token override** *(the proposed default if L1 is ruled out)*
   *"Set `--link-color` in all primary/dark zones — affects every page."*
   → the AI proceeds to Step 4 at L3.

3. **L5 — Component-scoped override** *(targeted; only if L3 is intentionally too broad)*
   *"Scope this to the footer component only."*
   → the AI revises to an L5 override and notes in the change log that L3 was available but deliberately not used.

> If the human chooses a lower layer than the AI proposed, they must provide a reason. the AI records that reason in the change log entry so future sessions understand the scope was intentionally limited.

This is the only judgment call requiring a human.

---

### Step 4 — the AI: Make the change

the AI writes the CSS (or config change) directly to the permanent file at the approved layer. **No staging through a temporary mechanism** (admin-UI injectors, runtime patches, dev-only overrides) — those create orphan-entity risk and a manual cleanup step that is reliably forgotten.

**Iteration speed**: keep a local watch task running during visual work so file saves surface in the browser within ~1s. Two acceptable setups:

1. **Native HMR** (Vite, esbuild watch, webpack-dev-server, browsersync) pointed at the project's CSS source files. CSS-only edits do not require any cache rebuild on the framework side.
2. **Aggregation off + targeted cache-tag invalidation** — for CMSes or build pipelines that bundle CSS:
   ```bash
   # Disable aggregation/bundling (one-time)
   <framework cmd to disable CSS bundling>

   # Invalidate the smallest possible cache tag on each save
   <framework cmd to invalidate the CSS cache only>
   ```

Either way, **the edit lives in the permanent file from the first keystroke.** Shadow-overrides that are "temporary until we figure it out" are the dominant source of `!important` accumulation.

the AI drafts the CSS (or config change) at the approved layer, with a comment that records the layer and the ruling from Pass 2:

```css
/* [L3] --link-color: brand override (L1+L2 ruled out — intentional deviation
   from auto-derived colour). See css-change-log.md:L42 */
html .theme--primary {
  --link-color: #F4A942;
}
```

the AI appends an entry to the change log. The entry differs based on the ruling:

**If the approved layer is the highest eligible layer (structural fix):**
```
[L3] --link-color in .theme--primary → #F4A942  css/base.css:L47  2026-04-18
  Ruling: L1 correct, L2 auto-derived, L3 is correct layer.
```

**If the human chose a lower layer than proposed (intentional deviation):**
```
[L5] --link-color scoped to .site-footer → #F4A942  css/footer.css:L3  2026-04-18
  Ruling: L3 was available but scoped down by human — footer-only treatment intended.
```

This distinction matters for the loop: a structural fix at L3 should not be revisited. A deliberate scope-down to L5 should be reviewed if a global change is later requested.

---

### Step 5 — the AI: Run T1 + T2 verification

the AI follows the Three-Tier Verification Hierarchy defined in [`../../testing/verification-cookbook.md`](../../testing/verification-cookbook.md). That document is the authoritative reference — this section names which tiers are mandatory at Step 5 and what counts as passing.

1. **T1 — Headless (curl + grep), 1–5 s.** Rebuild the CSS bundle if applicable, request the target page, and grep for the variable value or selector in the rendered HTML/CSS. **Mandatory.** T1 confirms the file is being served and the token/property is present.
   ```bash
   # Rebuild whatever your stack rebuilds for CSS (no-op if HMR'd)
   <framework cmd>

   # Confirm the token landed
   curl -sk "$URL" | grep -oE -- '--link-color:[^;]+;' | head -1
   ```
2. **T2 — ARIA structural tree, 5–10 s.** Read the rendered structure and confirm the **computed** value of the changed property matches what was written. T2 is required when the change affects structural behaviour (layout, visibility, focus order) **or** when T1 alone cannot confirm the fix landed (e.g., the property is set by JS at runtime).
3. **Affected component / E2E test suite.** If the change touches a component that has Vitest component tests or Playwright E2E coverage, the AI **must** run the affected test files as part of confirming T2. A computed-style change can break a snapshot or screenshot test that T1/T2 alone won't surface. See the relevant testing conventions doc for your stack — for Vue 3 + Vitest see [`../../frameworks/vue/conventions.md`](../../frameworks/vue/conventions.md) and [`../../frameworks/vitest/conventions.md`](../../frameworks/vitest/conventions.md).

**Never skip T1 in favour of a screenshot. Never skip T2 when the change is structural. Never declare T2 passing while affected component tests are red.**

The human sees nothing unless T1 or T2 fails.

#### Diagnosing T2 failures

If the computed value does not match what was written, **something downstream is overriding the rule.** The AI does not jump straight back to Step 2; it runs this diagnostic in order and reports the finding before the next change attempt:

1. **Specificity calculation.** Identify all rules in the cascade that touch this property on this element. Compute their specificity as `(inline, ID, class+attr+pseudo-class, type+pseudo-element)`. The rule with highest specificity wins ties; later rules win same-specificity ties. If a higher-specificity rule than the one we wrote is delivering the wrong value, **that rule is the conflict** — not ours.
2. **`!important` audit.** Grep the project's CSS for `!important` declarations of the same property. Any of them that *also* match this element will trump our rule regardless of specificity. `!important` declarations are themselves a smell — flag any found that aren't already in the change log.
3. **Cascade-layer order check.** If the project uses CSS `@layer` ordering (recommended — see [Layer mappings by stack](#layer-mappings-by-stack)), confirm our rule lives in a layer declared *after* any conflicting rule's layer. Layer order beats specificity within layered CSS.
4. **Computed-style trace.** In a real browser (DevTools or Playwright headless), inspect the element's computed style for this property. The "Styles" panel lists all declarations, ordered by override. Identify the winning declaration's source file and selector — that's the actual conflict.
5. **JS runtime override.** If the property is being set by JavaScript at runtime (inline `element.style.foo = …` or a CSS-in-JS library), no static rule will land. Confirm by checking for `style="..."` attributes on the rendered element; trace which JS code sets it.

After diagnosis, the AI **reports the conflict source** (file + line + reason) and:
- If the conflict is itself an entry in the change log: flag the [Loop](#the-loop) violation and propose unwinding.
- If the conflict is a higher-specificity rule with no log entry: that rule pre-dates the workflow. Add a `[discovered]`-tagged log entry for it, then re-run Step 2 with the new context.
- If the conflict is `!important` or runtime JS: surface to the human at Step 3 — the fix usually requires removing the conflict, which is a scope decision.

---

### Step 6 — Human: Visual sign-off (T3 — only when needed)

T3 (browser screenshot, 60–90 s) is the last tier and is **blocking** when the change involves brand colour, typography, spacing, or any decision that cannot be judged from curl or ARIA output. Follow the protocol in [`../../testing/visual-regression-strategy.md`](../../testing/visual-regression-strategy.md): one screenshot = one design slice vs. one live viewport; pass pre-sliced reference assets (never the full composite); write findings incrementally to a visual-regression report before returning.

If the change is purely mechanical (a variable value that was demonstrably wrong, verified at T1/T2), T3 may be skipped. If it involves brand intent, the human is the judge.

> *"Yes, that's the right shade"* → done.
> *"Still not right"* → back to Step 1 with the new description.

**Do not open a browser/screenshot loop until T1 and T2 have both passed.** A screenshot that looks right while T2 is failing is masking a structural defect that will surface on another page.

---

### Step 7 — Human/the AI: Finalize and commit

Re-enable any aggregation/bundling that was turned off for iteration. Run the framework's cache rebuild so the committed version matches the production-like path. Confirm the page still passes T1 in the production-like mode before committing.

The change log entry written during the process travels with the commit. Git history plus the log gives a complete record of what was changed, at what layer, and when.

---

## The Loop

Before Step 2 of any new change request, **the AI reads the change log first.** If the property being requested has already been overridden in a previous session, the AI flags it:

> *"`--link-color` in `.theme--primary` was already set at L3 on 2026-04-18 (css/base.css:L47). A second override here would mean the first fix was at the wrong layer. Should we revise that instead?"*

This prevents the accumulation of fighting overrides across sessions.

Entries marked `[superseded]` or `[removed]` (see [Change Log](#change-log) for syntax) **do not** trigger the Loop warning — they are intentionally archived and no longer active. The AI skips them when scanning for conflicts.

### When to skip the workflow

The full 7-step loop is overkill for a few specific cases. The AI may skip the workflow when **all** of the following are true:

- The change lives in code that is **explicitly throwaway**: a debug overlay, a single-render demo, a sandbox route, a one-off prototype that won't ship to users.
- The change is **scoped to a single file** that is itself flagged as throwaway (e.g., `*.sandbox.vue`, `playground/`, `__debug__/`).
- The change does **not** modify any token, component class, or theme-zone selector that is reachable from production code.

If any of those is false, the workflow applies. **Skipped changes do not get a change-log entry** — the log is for production-affecting decisions only.

When in doubt, do the workflow. Skip is a deliberate exemption, not a default.

---

## Change Log

A single project-wide log file. Common locations:
- `docs/css-change-log.md`
- `docs/design/css-change-log.md`
- `<theme>/docs/css-change-log.md`

Pick one, commit it to git, and have the AI update it at Step 4 and read it at the start of every new session.

**Format (active entry):**
```
[L<N>] <token-or-property> in <selector> → <value>  <file>:L<line>  YYYY-MM-DD  [tag]
  Ruling: <one-line summary of the Pass 2 trace>
```

**Tags:**

| Tag | Meaning |
|---|---|
| (no tag) | Standard active entry |
| `[added]` | This entry is the **first appearance of a new token** in the design system (vs. modifying an existing one) |
| `[discovered]` | This entry retroactively records an override that pre-dated the workflow, surfaced via T2 conflict diagnosis |
| `[superseded by L<line>]` | A later entry made this one obsolete. The back-pointer references the line number of the superseding entry |
| `[removed YYYY-MM-DD]` | The override was deleted from the codebase — the entry is kept in the log for history |

**Example entries:**
```
L1: [L1] brand_color → #1B3A6B  config (cms-cli)  2026-04-17
      Ruling: brand spec change — config layer is the only correct fix.

L4: [L1] --space-3xl  →  6rem  src/assets/input.css:L18  2026-04-17  [added]
      Ruling: existing scale ends at --space-2xl=4rem; hero needed wider spacing.

L7: [L3] --color-surface in .theme--white → #F5F5F2  css/base.css:L12  2026-04-18
      Ruling: L1+L2 correct; surface is variant-zone-specific.

L10: [L3] --link-color in .theme--primary → #F4A942  css/base.css:L47  2026-04-18
       Ruling: deliberate brand deviation from auto-derived colour.

L13: [L5] --button-bg in .button--primary → #F4A942  css/button.css:L3  2026-04-19  [superseded by L20]
       Ruling: L3 available but scoped down — primary-button-only emphasis intended.

L16: [L5] !important on .legacy-banner color  src/assets/legacy.css:L88  unknown date  [discovered]
       Ruling: pre-workflow override exposed via T2 conflict diagnosis on 2026-04-22.
       Conflict with L10 — needs removal once banner is replaced.

L19: [L4] .footer color: #6b7280  src/assets/input.css:L102  2026-04-25  [removed 2026-05-02]
       Ruling: replaced by L3 token --color-text-muted; entry kept for history.

L20: [L3] --button-bg in .theme--primary → #F4A942  css/base.css:L60  2026-04-26
       Ruling: L5 entry at L13 was scoped too narrow — promoted to L3 zone.
```

### Pruning the log

When a new change makes an old entry obsolete, **mark it superseded; don't delete the line.** The supersession back-pointer (`[superseded by L20]`) lets future sessions trace the history of a decision.

The change log is append-mostly: lines never get reordered (it would invalidate every existing back-pointer). Two exceptions:
- Adding a `[superseded by L<n>]` or `[removed YYYY-MM-DD]` tag to an existing line — same-line edit, expected.
- A periodic archival pass (every ~6 months) that moves all entries tagged `[superseded]` or `[removed]` into a `## Archive` section at the bottom of the file. Active entries stay at the top. The Loop scan only reads the active section.

---

## Human Touchpoints — Summary

| Step | Human action | Why human, not the AI |
|---|---|---|
| 1 | Describe the change | You know what's wrong |
| 3 | Approve layer + scope | Judgment call — site-wide vs targeted |
| 6 | Visual sign-off | Brand intent cannot be evaluated by AI |
| 7 | Commit | Code ownership |

All other steps — trace, file edit, cache clear, verification, log update — are the AI.

---

## Layer mappings by stack

The 5-layer model is abstract. Here is how it maps to common stacks. Add your project's stack to its own doc when you adopt this workflow.

### Tailwind v4 + Vue 3 (Vite)

| Layer | Where it lives | How to change |
|---|---|---|
| L1 | `@theme {}` block in `src/assets/input.css` | Edit the `--color-brand` etc. in the `@theme` block |
| L2 | Auto-derived utilities (Tailwind generates a colour scale from a single token via `--color-brand-50…900`) | Adjust the `@theme` ramp definitions; rare to need a manual override |
| L3 | Variant selectors on `<html>` or a wrapper: `html.dark`, `html.brand-x` | Add a rule: `html.dark { --color-surface: …; }` in `input.css` |
| L4 | `@layer components` block in `input.css`: `.btn-primary`, `.pill-status` | Define semantic classes with `@apply` or token references inside the `@layer components` block |
| L5 | Vue SFC `<style scoped>` or one-off utility class on the element | Edit the SFC's `<style scoped>` block |

**Use CSS `@layer` to enforce cascade order.** The L1–L5 numbering should correspond to actual CSS cascade layers so the cascade enforces priority, not just specificity. Declare the order at the top of `src/assets/input.css`:

```css
@layer tokens, derived, theme, components, scoped;
```

L1 declarations live in `@layer tokens`, L2 in `derived`, L3 in `theme`, L4 in `components`, L5 in `scoped`. Tailwind v4 lays this out automatically for `@theme {}` and `@layer components {}` — you only need to add the `@layer scoped` declaration for SFC styles if you want them to participate. Without an explicit cascade-layer declaration, conflicts are resolved by selector specificity alone, which is fragile.

Cache rebuild: `pnpm css:build` (or just rely on Vite HMR while developing).

### Plain CSS + design tokens + any component framework

| Layer | Where it lives | How to change |
|---|---|---|
| L1 | `:root { --token: … }` in a single `design-tokens.css` | Edit the root variable |
| L2 | Derived blocks computed via `color-mix()`, `oklch()`, `calc()` from L1 | Edit the derivation rule (rare) |
| L3 | Variant selectors: `[data-theme="dark"]`, `.brand-x`, etc. | Add or extend the variant block |
| L4 | Global semantic stylesheets: `components.css`, `buttons.css` | Add or extend a semantic class |
| L5 | Component module CSS, scoped styles, BEM modifier | Edit the component's own stylesheet |

Cache rebuild: whatever bundles your CSS (`npm run build`, esbuild, etc.). HMR if available.

### CMS-driven theme (e.g., Drupal + token-derived theme)

| Layer | Where it lives | How to change |
|---|---|---|
| L1 | CMS theme settings stored in the database (brand colour, font choice, etc.) | Update via the CMS's CLI/admin (`drush config:set …` or equivalent) |
| L2 | Auto-derived shades computed at render time via `oklch()` / `color-mix()` | Adjust the derivation rule in the theme's CSS |
| L3 | Theme-zone selectors on the `<body>` or a wrapper: `.theme--primary`, `.theme--surface` | Edit the zone block in the theme's `base.css` |
| L4 | Global semantic CSS in the theme's `base.css` | Add or extend a semantic class |
| L5 | Per-component overrides via the framework's component-scoping mechanism (e.g., `libraries-extend` in Drupal) | Add a scoped stylesheet attached to the specific component |

Cache rebuild: framework-specific (e.g., `drush cr` for Drupal). Use the most-targeted cache-tag invalidation possible during iteration.

---

## Adopting this workflow on a new project

The workflow assumes a few things already exist in the project. Bootstrap them once, then the AI can run the loop from any session.

> **Required (not opt-in) for Website Front-End Pipeline projects.** For any project run
> under the [Website Front-End Pipeline](../../pipelines/website-frontend/README.md), this
> workflow is a **required step of F** for every CSS change — not human-discretion — and the
> deterministic stylelint guards below (`declaration-no-important`, `selector-max-specificity`,
> `selector-max-id`) are **non-opt-in**: the bootstrap (steps 1–6) must be done before F
> runs, and a CSS change with no logged workflow trace is a block-level finding. See
> [`pipelines/website-frontend/core/principles.md`](../../pipelines/website-frontend/core/principles.md)
> §3 and [`core/roles/feature-implementor.md`](../../pipelines/website-frontend/core/roles/feature-implementor.md).
> (#281; proposal §3 G1.) Other stacks may still adopt it at their discretion as below.

### 1. Create the change log

Pick a stable path and create an empty file with a header:

```bash
# Common paths — pick one
docs/css-change-log.md
docs/design/css-change-log.md
<theme>/docs/css-change-log.md
```

Initial content:

```markdown
# CSS Change Log

Tracks every CSS-layer override decision per the workflow at
languages/css/css-change-workflow.md. The AI reads this file
at the start of every CSS change request and updates it at Step 4.

## Active

(no entries yet)

## Archive

(superseded and removed entries — periodic archival pass)
```

### 2. Document this project's L1–L5 mapping

The 5-layer model is abstract. Each project resolves it concretely. Add a short doc at `docs/design/css-layer-mapping.md` (or equivalent) that names the actual files, selector patterns, and tools for *this project's* L1–L5. Use the [Layer mappings by stack](#layer-mappings-by-stack) tables above as a starting template.

### 3. Configure stylelint

The workflow's deterministic-tools row depends on a stylelint config that flags the patterns the workflow forbids. Use the [stylelint-config-standard](https://github.com/stylelint/stylelint-config-standard) community config as the base, then add the workflow-specific overrides:

```bash
pnpm add -D stylelint stylelint-config-standard
```

`.stylelintrc.json`:

```json
{
  "extends": ["stylelint-config-standard"],
  "rules": {
    "declaration-no-important": true,
    "no-descending-specificity": null,
    "selector-max-specificity": ["0,4,0", { "ignoreSelectors": [":root", "html"] }],
    "selector-max-id": 0,
    "custom-property-pattern": null
  }
}
```

The `declaration-no-important: true` rule is the load-bearing one — it mechanically enforces the workflow's "no `!important`" rule, leaving any override that needs to use `!important` (e.g., a `[discovered]` legacy entry) to be explicitly opted out of with a `/* stylelint-disable */` comment plus a change-log entry.

`selector-max-specificity` blocks the most common specificity-war pattern (deeply-nested selectors) without forcing a rewrite of `:root` or `html`.

`custom-property-pattern: null` is intentional — the workflow doesn't constrain the *naming* of custom properties (different stacks have different conventions). [`agent/naming.md`](../../agent/naming.md) covers naming conventions.

### 4. Configure the pre-commit hook

Run stylelint on staged CSS files before each commit. With `lefthook`:

```yaml
# lefthook.yml
pre-commit:
  parallel: true
  commands:
    stylelint:
      glob: "*.{css,scss}"
      run: pnpm stylelint {staged_files}
```

Or with `husky` + `lint-staged`:

```json
"lint-staged": {
  "*.{css,scss}": ["stylelint"]
}
```

### 5. Establish CSS cascade-layer ordering

If the stack supports CSS `@layer` (Tailwind v4 native, plain CSS native, most modern build tools), declare the layer order at the top of the entry stylesheet:

```css
@layer tokens, derived, theme, components, scoped;
```

This makes the workflow's L1–L5 priority enforced by the cascade itself, not just by specificity. See [Layer mappings by stack](#layer-mappings-by-stack) for stack-specific notes.

### 6. First commit

Commit the empty log, the layer-mapping doc, the stylelint config, and the cascade-layer declaration in one bootstrap commit:

```
chore(css): adopt CSS Change Workflow — bootstrap log, mapping, lint, cascade
```

After this, every CSS change goes through the 7-step workflow.

---

## See also

- [`../../testing/verification-cookbook.md`](../../testing/verification-cookbook.md) — Three-Tier Verification Hierarchy. T1 + T2 are mandatory at Step 5.
- [`../../testing/visual-regression-strategy.md`](../../testing/visual-regression-strategy.md) — T3 protocol used at Step 6.
- [`../../agent/naming.md`](../../agent/naming.md) — naming conventions for change-log file names and any new CSS class names introduced.
- [`../../frameworks/vue/conventions.md`](../../frameworks/vue/conventions.md) and [`../../frameworks/vitest/conventions.md`](../../frameworks/vitest/conventions.md) — required reading for Step 5 component-test integration in Vue projects.
