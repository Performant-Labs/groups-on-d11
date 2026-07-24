# Wireframe — #123 SC-4 Discovery three ways

**Mode:** (a) generated low-fi
**Surface:** `/showcase` — new section, below the existing `directory.layout` stub switcher
and the catalog entry list, ABOVE the page footer.

## Layout — `/showcase` (annotated ASCII)

```
/showcase
+--------------------------------------------------------------------+
| [POC ribbon — existing, unchanged]                                 |
+--------------------------------------------------------------------+
| Discovery ranking, presented three ways...  (intro <p>, existing)   |
|                                                                      |
| Viewing: (●) Compact list   Cards   Map (soon)   ⓘ                  |  <- EXISTING
|          ^ directory.layout switcher, ?variant=, UNCHANGED           |     stub
|                                                                      |
| --- catalog entries list (existing, unchanged) -------------------- |
| Discovery ranking            [ live ]  ⓘ                            |
|   Compares three ways to surface groups: Recent, Hot, Promoted —    |
|   the decision: how much editorial curation vs. raw recency.        |
|   [View this comparison]  (deep-links to the section below)         |
| Directory presentation       [ live ]  ⓘ  ...                       |
| ...                                                                  |
+--------------------------------------------------------------------+
| ## Discovery ranking: Recent / Hot / Promoted        <- NEW H2      |
|                                                                      |
| <p> Same three groups, three orderings. Recent is chronological,    |
| Hot is engagement-ranked (comments), Promoted is editorially        |
| curated. Switch tabs to feel the difference. </p>  <- decision copy |
|                                                                      |
| Viewing: (●) Recent   Hot   Promoted   ⓘ                            |  <- NEW
|          ^ discovery.ranking switcher, ?discovery=, instance #2      |     switcher
|                                                                      |
| [ embedded view region — swaps per active tab, see states below ]   |
+--------------------------------------------------------------------+
```

## Two switchers, two distinct query keys (explicit callout)

- Existing: `directory.layout` instance -> `?variant=compact|cards|map`
- New: `discovery.ranking` instance -> `?discovery=recent|hot|promoted`
- **Justification (why not share `?variant=`):** both switchers can be open
  simultaneously on the same `/showcase` page load; a shared key would force
  one switcher's selection to silently override the other's on every link/
  deep-link (each option's `href` is a *replace*, not a *merge*, of that key).
  A second, distinct key lets each switcher preserve the other's state.
- **Known blocker for A/T (see Risks):** `VariantSwitcher::build()` currently
  hardcodes `'href' => '?variant=' . rawurlencode($id)'` (VariantSwitcher.php:155).
  It does not yet parameterize the query key per instance. This wireframe
  assumes `build()` gains a query-key parameter (or the controller
  post-processes `#options[*]['href']`) — a plan-time decision for A, not D.

## Tab labels + tooltip copy (per-tab decision framing)

| Tab id     | Label     | Tooltip (ⓘ, HelpText key `showcase.switcher.discovery.ranking`) |
|------------|-----------|------------------------------------------------------------------|
| `recent`   | Recent    | *shared switcher tooltip, not per-option* — see below            |
| `hot`      | Hot       | (same)                                                            |
| `promoted` | Promoted  | (same)                                                            |

`VariantSwitcher` ships **one tooltip per switcher wrapper**, not one per
option (house pattern — see VariantSwitcher.php:35-37). POC copy for the
single `showcase.switcher.discovery.ranking` HelpText entry:

> "Recent = chronological (newest first). Hot = engagement-ranked (most
> commented first, recalculated by cron). Promoted = editorially curated
> (hand-picked via the promote-to-homepage flag)."

If a **per-tab** tooltip is later wanted, that is new `VariantSwitcher`
surface (out of scope here — flagged below under Open questions).

## Data states beneath the switcher

Each embedded view (`views_embed_view('discovery_compare', '<display>')`)
renders 5-10 rows. Row shape varies slightly per tab to make the ranking
signal visible, not just the title:

### Recent (many) — chronological
```
1. "Welcome thread"                 created 2h ago
2. "Onboarding checklist"            created 5h ago
3. "Style guide draft"               created 1d ago
...
```
Meta line: `created <relative time>`.

### Hot (many) — engagement-ranked
```
1. "Style guide draft"      12 comments   ● hot
2. "Welcome thread"          4 comments
3. "Onboarding checklist"    1 comment
...
```
Meta line: `<N> comments`. Top row additionally carries a non-color `●`
prefix + "hot" text label (never color-only) when it is the top-ranked row.

### Promoted (one/many — acceptance requires >=2 seeded)
```
1. "Community guidelines"   [promoted]
2. "Featured group spotlight" [promoted]
```
Meta line: `[promoted]` badge, plain text (not color-only), on every row
(all rows in this display are promoted by definition).

### Empty state (fallback copy — Promoted MUST NOT hit this per acceptance,
but Recent/Hot could on an unseeded install)

```
+----------------------------------------------------------+
| No content yet for this view.                             |
| Recent:   "No recent activity yet — check back once       |
|            groups start posting."                         |
| Hot:      "Nothing is trending yet — engagement rankings   |
|            appear once threads get comments."              |
| Promoted: "No groups are promoted yet — an editor flags    |
|            content via the promote-to-homepage flag."      |
+----------------------------------------------------------+
```
Truthful, non-blocking copy — no dead CTA, no silent blank region.

### Error state (view fails to render / display missing)
```
"This comparison couldn't load right now. Try refreshing, or view Recent /
Hot / Promoted directly." (falls back to the closest existing standalone
view/page if resolvable; otherwise plain text only, no broken embed left
in the DOM)
```

## Keyboard / focus behavior

Reuses `VariantSwitcher`'s existing `role="radiogroup"` + roving-tabindex
semantics as-is — **no new interaction code**:
- Tab into the group lands on the single `tabindex="0"` (selected) option.
- Arrow-Left/Right moves focus among all options (available options only
  reachable this way — no "soon" options here, all three are live per
  acceptance).
- Enter/Space or click activates -> navigates to `?discovery=<id>`
  (no-JS fallback link) or, with `do_showcase/switcher` JS progressive
  enhancement, swaps in place (existing framework behavior, unchanged).
- Selected option shows leading `●` glyph + `aria-checked="true"`.

## WCAG 2.2 AA notes

- Wrapper: `role="radiogroup"`, `aria-label="Viewing"` (existing pattern,
  reused verbatim).
- Each option: `role="radio"`, `aria-checked`, visible focus ring
  (`:focus-visible` outline, existing `do_showcase.css` rule extends here —
  new `discovery-compare.css` should NOT redefine focus style, just import/
  match the same outline color+width for consistency).
- Non-color status: `●` selected glyph (existing), `[promoted]` and
  "N comments" as plain text (never conveyed by color alone).
- Contrast: new `discovery-compare.css` meta-line text must be checked
  >= 4.5:1 against its background (do not introduce a lighter gray than the
  existing `#767676` disabled-state gray, which is the current AA floor in
  this stylesheet).
- Labels: switcher wrapper `aria-label`, embedded view region should carry
  an `aria-label` or precede an `<h2>`/`<h3>` that names it (the new H2
  above satisfies this via standard heading-associates-region convention).

## Cache context callout

Add `url.query_args:discovery` to `$build['#cache']['contexts']` in
`ShowcaseController::page()`, alongside the existing
`url.query_args:variant` — **both** must be present since the page now
varies by two independent query args. Same fix-loop lesson as #119 round 3
(VariantSwitcher.php:190-197 / ShowcaseController.php:116-122): omitting
either means Dynamic Page Cache serves a stale variant/discovery
combination to a later request.

## Screens & states summary

| State | Recent | Hot | Promoted |
|---|---|---|---|
| Many (seeded, expected) | list, `created X ago` | list, `N comments`, top marked | list, `[promoted]` |
| Empty (unseeded install) | fallback copy above | fallback copy above | MUST NOT occur per acceptance; fallback defined anyway |
| Error (view/display missing) | shared error copy above | same | same |

## Existing components/patterns reused

- `VariantSwitcher::build()` render array + `do-showcase-variant-switcher`
  CSS class (`do_showcase.css`) — no new switcher primitive.
- `do-showcase-catalog-entry` / `do-showcase-status-badge` visual vocabulary
  for the H2 section's framing, kept consistent with the catalog list above
  it.
- `views_embed_view()` embed pattern (implied by SC-5's Views-integration
  precedent) rather than a hand-rolled query/render loop.
- `ⓘ` HelpText tooltip trigger pattern (`data-do-tooltip`, `role="note"`,
  `tabindex="0"`) — identical markup shape to the existing map-help and
  per-entry help triggers in `ShowcaseController::page()`.

## Open questions for approval

1. **Per-tab tooltip vs. one shared tooltip** — this wireframe assumes ONE
   shared tooltip per the existing `VariantSwitcher` contract (no per-option
   tooltip support exists today). If the issue's "each tab's tooltip states
   the decision it represents" is read as requiring three DISTINCT tooltip
   strings per option, that's new `VariantSwitcher` surface — flag to A.
2. **Query-key parameterization** — `VariantSwitcher::build()` hardcodes
   `?variant=`. A must decide: extend `build()`'s signature with a
   query-key parameter, or have the controller rewrite `#options[*]['href']`
   post-build. Either is fine design-wise; not a D decision.

## Approval
Auto-approved per POC lean pipeline (no human sign-off gate for this run).
