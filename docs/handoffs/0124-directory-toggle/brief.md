# Brief — #124 SC-5: Directory compact-list vs cards toggle on /all-groups

**Review rigor:** none (per issue).
**UI surface:** YES — D + U both run.
**Depends on:** #119 (SC-F1 variant framework — merged; VariantSwitcher + JS + template + CSS + `showcase.switcher.directory.layout` HelpText entry all shipped).
**Merge order:** #124 → #125 (SC-6 map appends to `views.view.all_groups.yml` after us).

## Objective
Render the SC-F1 variant switcher over `/all-groups` with three options — **Cards
(default)** / **Compact list** / **Map (soon)** — so users can toggle presentation
without losing filters or paging. Ship the compact-list CSS and its Playwright spec.

## Acceptance criteria

- [ ] The switcher renders at the top of `/all-groups`, labeled "Viewing:", with
      three options in order: **Cards** (selected by default), **Compact list**,
      **Map** (aria-disabled + "(soon)" suffix — SC-6 flips it on).
- [ ] Selecting **Compact list** switches the group directory to dense rows (name,
      type, member count, visibility badge) without a full page reload — filters and
      current page are preserved (same URL, same query params, only the wrapper's
      CSS variant class flips).
- [ ] Selecting **Cards** restores the current card presentation exactly (no CSS
      regression — the existing card layout is byte-identical when in the "cards"
      state, i.e. no wrapper class is applied and no compact CSS matches).
- [ ] Choice persists per session via the SC-F1 switcher's existing sessionStorage
      key (`doShowcase.variant.directory.layout`). No new persistence layer.
- [ ] `?variant=compact` or `?variant=cards` in the URL wins over sessionStorage
      (no-JS fallback) — same as the SC-F1 stub.
- [ ] Compact rows show the same access-filtered group set as cards (the toggle is
      pure presentation — no query change).
- [ ] The switcher's ⓘ tooltip renders the shipped
      `showcase.switcher.directory.layout` HelpText copy.
- [ ] `/showcase` catalog entry `directory-presentation` — TODAY marked `coming` in
      `ShowcaseCatalog::entries()` — flips to `status: live` with
      `route: do_showcase.showcase` replaced by a route to `/all-groups`. (Or: leave
      the entry `coming` since the switcher is on `/all-groups`, not `/showcase`.
      **Recommendation:** flip to `live` with `route` pointing at
      `entity.group.collection`-equivalent — the `view.all_groups.page_1` route,
      typically `view.all_groups.page_1`. Verify route name in F.)
- [ ] `tests/e2e/directory-toggle.spec.ts` toggles both ways, verifies filters
      preserved across toggle, verifies session persistence across reload.
- [ ] Existing suites stay green — `directory-cards.spec.ts`,
      `directory-filters.spec.ts` unchanged. New CSS never applies when in cards
      mode.
- [ ] WCAG 2.2 AA: labels present (radiogroup + radio labels), keyboard operable
      (Enter/Space + arrow keys via SC-F1's existing JS), visible focus (SC-F1 CSS
      handles it), AA contrast on compact rows and the visibility badge, non-color
      status (badge carries text, not just color).
- [ ] HelpText backstop honored: `showcase.switcher.directory.layout` already ships;
      no new HelpText entry needed. (SD-6 backstop, not planned scope.)
- [ ] Namespaced-docker rendered-DOM check + `npx playwright test` green before PR.

## Owned files (per issue)

- `docs/groups/config/views.view.all_groups.yml` (edit — sole owner; add fields for
  compact rows if needed; optional header area — see design decisions).
- `docs/groups/modules/do_showcase/css/directory-compact.css` (new).
- `tests/e2e/directory-toggle.spec.ts` (new).

## Also touched (justified)

- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — ADD one method,
  `#[Hook('views_pre_render')]` `viewsPreRender(ViewExecutable $view)`, matched on
  view id `all_groups` + display id `page_1`. Injects the switcher render array as
  the view's `#header`, adds the `url.query_args:variant` cache context, sets a
  wrapper CSS variant class on the view's rendered attributes based on `?variant=`.
  **Justification:** same pattern as the ribbon / persona hooks in this file; hook
  is the least-invasive way to add cache metadata + a header area without
  restructuring the view YAML into a non-portable shape.
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` — ADD a
  `directory-compact` library (CSS-only, `directory-compact.css`), attached from the
  same hook when it fires on `all_groups.page_1`.
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — flip
  `directory-presentation` entry to `status: live` and set its `route` to
  `view.all_groups.page_1` (or the correct route id — F to verify).

## Design decisions locked in the brief

- **One display, wrapper CSS class drives the variant.** Do NOT add a `page_2`
  display for compact. Both variants share `page_1`, so filters/paging are
  preserved trivially. The compact variant is a CSS delta only; JS toggles a
  wrapper class (`data-do-directory-variant="compact"` on the view wrapper),
  matching the switcher's roving-tabindex click handler pattern.
- **Three options, not two.** Ship `compact` / `cards` / `map(available: false)`
  from day one so #125 becomes a single `available: true` flip + map rendering
  append. Matches the SC-F1 stub on `/showcase`.
- **Fields present in row output.** The row uses Views fields (already the case).
  For the compact variant we need at minimum: label, type (`field_group_type`),
  member count, visibility badge. If member count forces a schema/relationship
  change, degrade to type + visibility badge only and file a follow-up.
  Visibility badge is derived from `field_group_visibility` (already in the view's
  field storage). All four fields render in both variants; card CSS visually hides
  or restyles them (existing behavior).
- **No `#header` area edit in the YAML.** The hook injects the switcher render
  array as the view's `#header` at render time (not exported into config), keeping
  YAML changes minimal and preventing config-export drift.

## Input documents

- Issue #124 (this story)
- Issue #117 (Showcase epic framing)
- Issue #119 (SC-F1 framework — merged)
- Issue #125 (SC-6 map — downstream forward-compat)
- `docs/handoffs/0124-directory-toggle/survey.md`
- `~/Projects/playbook/workflow/pipeline-conventions.md`

## Handoffs directory

`docs/handoffs/0124-directory-toggle/`

## Branch

`124-directory-toggle` (worktree at `~/Projects/_worktrees/groups-directory-toggle-124`)
