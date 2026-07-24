# Survey — #124 SC-5: Directory compact-list vs cards toggle

## Scope
Add a compact-list display to the group directory alongside the existing cards on
`/all-groups`, toggled by the SC-F1 variant switcher ("Viewing: Cards | Compact list"
— Map arrives with #125/SC-6). Compact list = dense rows: name, type, member count,
visibility badge. Choice persists per session. Existing card display is unchanged.

## Files inspected

- `docs/groups/config/views.view.all_groups.yml` — the sole owned config file. Today
  has ONE display (`page_1`, path `all-groups`) with `style: default` + `row: fields`
  (label, description, created). There is no separately-named "cards" display today —
  the current directory IS the default fields row.
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — reusable
  role="radiogroup" render-array builder, keyed per `instance_id`. Selection resolved
  from caller-supplied `$current`; unknown/unavailable ids fall back to first available.
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` — client-side
  sessionStorage persistence keyed as `doShowcase.variant.<instance_id>`; `?variant=`
  URL param wins over stored value; roving tabindex + ArrowLeft/Right per WAI-ARIA.
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` — the
  precedent embed pattern:
  - reads `variant` from the request query,
  - calls `$this->switcher->build('directory.layout', [...], $variant)`,
  - `#cache['contexts'][] = 'url.query_args:variant';` — MUST-carry to avoid
    Dynamic Page Cache serving the first-cached variant to every request.
  - Attaches `do_showcase/switcher` + `do_chrome/tooltips`.
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — hook implementations
  file (attribute-based `#[Hook(...)]`); registers the theme hook, and injects the
  ribbon / persona-switcher / persona-banner via `page_top`. This is where a
  `hook_views_pre_render` (or similar) would live to inject the switcher into the
  view render, if we go the hook route.
- `docs/groups/modules/do_chrome/src/HelpText.php:171` — the
  `showcase.switcher.directory.layout` tooltip copy IS ALREADY SHIPPED. No new
  HelpText entry needed for the switcher tooltip.
- `docs/groups/modules/do_chrome/src/Hook/PageHelp.php:72` — page_help mapping for
  `view.all_groups.page_1` → `page.all_groups` already exists. HelpText entry for
  `page.all_groups` already ships. No new page_help entry needed either.
- `tests/e2e/directory-cards.spec.ts` (existing) and `tests/e2e/directory-filters.spec.ts`
  (existing) — patterns for `/all-groups` E2E; must stay green.

## Reuse & Analogous-Feature map

| Concern | Closest analogous surface | Extend or new? |
|---|---|---|
| Rendering the switcher over `/all-groups` | `ShowcaseController::page()` embed of the same switcher on `/showcase` (`directory.layout` instance) | **Extend**: reuse `VariantSwitcher::build('directory.layout', …)` verbatim. Same `instance_id` gives us free tooltip lookup + shared sessionStorage key. Attach point differs — see below. |
| Where to attach the switcher on `/all-groups` | `DoShowcaseHooks::pageTop()` (ribbon) — a `#[Hook('page_top')]` scoped only for the `view.all_groups.page_1` route match | **New hook method** in `DoShowcaseHooks` (`hook_views_pre_render` implemented as `#[Hook('views_pre_render')]` targeting `all_groups` view + `page_1` display). This inserts the switcher as the view's `#header` area OR as an `area_text_custom` — but a hook is cleaner because it also lets us set `#cache['contexts'][] = 'url.query_args:variant'` on the view render. **Extend** DoShowcaseHooks, do not create a new hook file. |
| Actually SHOWING compact vs cards | Views' native `display: page_2` (a second Page display) is the classical Views-first path. But the two displays would need different paths, which the switcher's ?variant= URL cannot toggle without a route change. | **Extend within the one Page display**: use `hook_views_pre_render` in `DoShowcaseHooks` (same one that attaches the switcher) to switch `$view->style_plugin` / `$view->rowPlugin` OR (simpler) let both variants render the same fields row, and drive presentation entirely via a CSS class on a wrapper: `.do-directory--variant-compact { … }` vs `.do-directory--variant-cards { … }`. Cards is what renders today with no wrapper class — the compact variant is a **CSS-only** delta. This preserves "existing card display unchanged" trivially. |
| Additional compact-row fields (type, member count, visibility badge) | These fields are already available: `field_group_type` (config confirms it's a group field), and visibility is derived. Member count needs a Views field. | **Extend the existing Page display** to add the extra fields to the row. In the cards variant, we hide them via `.do-directory--variant-cards .field--name-…` display:none; in the compact variant they show. Simpler alternative for POC: **render all four columns always; card-mode's existing CSS already hides/reflows them since it uses the default fields row too**. The CSS work is contained to `directory-compact.css`. |
| Session-persistence | Already free via `do_showcase.switcher.js` sessionStorage on `data-do-showcase-instance="directory.layout"`. | **Reuse verbatim.** |
| Filter/paging preserved across toggle | Because both variants share the SAME URL + SAME view + SAME query params, filters/paging are trivially preserved — the toggle only changes the wrapper CSS class via `?variant=` on the switcher's fallback links, and JS toggles it live. | **Reuse.** No new persistence layer. |
| CSS module | `docs/groups/modules/do_showcase/css/directory-compact.css` (new, per issue Owns section). | **New file, but only CSS** — the "compact" presentation delta only. Cards presentation stays where it is (browser defaults + existing chrome). |
| Playwright spec | `tests/e2e/directory-toggle.spec.ts` (new, per issue Owns section). | **New file** — the spec toggles both ways, verifies filters/paging preserved, verifies session persistence via reload. |
| HelpText entry for switcher | `showcase.switcher.directory.layout` already ships. | **Reuse verbatim.** No HelpText change. |

**Extend-vs-new recommendation (default: extend):** every non-trivial piece extends
an existing object. The only new files are the CSS delta and the Playwright spec, both
explicitly owned by this story. The new hook method (`viewsPreRender()`) is added to
the existing `DoShowcaseHooks` class alongside its siblings — same shape as the ribbon
and persona-banner hook methods.

## Forward-compat check (#125 SC-6 map)

SC-6 must APPEND a third option (`map`) to the switcher's option list on `/all-groups`
and ship a map display. Today, the SC-F1 stub on `/showcase` already declares three
options: `compact` / `cards` / `map(available: false)`. We MUST render the exact same
three options on `/all-groups` from the start — with `map` flagged `available: false`
— so #125 only has to (a) flip `available: true` on the map option and (b) attach its
map view rendering. If we ship only two options here, #125 has to also touch our new
hook to add the third, which crosses story boundaries.

**Decision:** the `/all-groups` switcher renders THREE options today
(`compact` / `cards` / `map` unavailable), matching `/showcase`'s stub verbatim. #125
becomes a one-line flip + map-rendering append.

## Assumptions / risks

- The compact variant is CSS-only (no new Views field). If the AC "member count" is
  read strictly as "a rendered number", we may need to add a Views field (relationship
  on group_content → count). For POC-bar delivery, a visible "N members" is
  achievable via a computed field or Views' aggregation; if that gets thorny in F,
  the fallback is to render a visibility badge + group-type badge only for POC and
  file member-count as a follow-up. **Flag to F: try Views' aggregation; if it forces
  a schema/relationship change, degrade to type + visibility badge only for POC and
  return the diff early for A review.**
- No conflict with SC-F1 (#119): we reuse the same instance_id and same switcher — the
  two switchers are literally the same widget, so session-persistence is unified.
  Navigating from `/showcase` (where the user picked "compact") to `/all-groups` will
  restore "compact" automatically. That is the intended UX.
- Views `hook_views_pre_render` supersedes an area-plugin approach because it lets us
  add cache contexts + attach libraries without touching the exported view YAML more
  than necessary. The view YAML change is limited to (optionally) a header area with
  a placeholder; if we can avoid touching the view entirely and inject purely from
  the hook, we do.

## Coordination with siblings

Three sibling orchestrators active on #116, #111, #145. This story touches:
- `docs/groups/config/views.view.all_groups.yml` — sole owner in this epic (issue says
  so; SC-6 appends AFTER our merge).
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — Hook file. #145 is
  WCAG backstop, likely doesn't touch this file. #116 / #111 unlikely to touch.
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` — we add ONE new library
  entry `directory-compact` (CSS-only). Merge conflicts unlikely.

No collisions foreseen.
