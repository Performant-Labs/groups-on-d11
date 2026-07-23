# Wireframe — 0124-directory-toggle (SC-5: directory compact-list vs cards toggle)

Low-fidelity, structure-only. Mode (a) — generated. This wireframe covers ONLY the delta this
story introduces on `/all-groups`: mounting the SC-F1 switcher as the view's `#header`, the new
compact-row layout, and the wrapper CSS-variant contract. It does **not** re-design the switcher
control itself — that low-fi device (radiogroup structure, focus ring, unavailable-option
treatment, no-JS fallback) is already specified in
`docs/handoffs/0119-variant-framework/wireframe.md` Surface 1 and is reused verbatim, with the
options list changed to `Compact list / Cards / Map (soon)` as this issue specifies. It also does
NOT re-design the existing cards presentation, which is unchanged pass-through.

---

## Surface 1 — Switcher mounted as `/all-groups` view `#header`

### State: default (Cards selected — the page's default, no `?variant=` in URL)

```
================================================================
 All Groups
================================================================
 Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
          │ Compact list │  ● Cards     │  Map (soon)  │
          └──────────────┴──────────────┴──────────────┘

 [ Search groups ______ ]  [ Location ______ ]  [ Primary language ▾ ]
 [ Filter ]  [ Reset ]

 ────────────────────────────────────────────────────────────
   ( existing card-mode directory renders here, BYTE-IDENTICAL
     to today — see Surface 2 "cards" note: no wrapper class,
     no compact CSS matches )
 ────────────────────────────────────────────────────────────
 [ « ]  1  2  3  [ » ]
================================================================
```

- The switcher sits between the page title and the exposed-filter form, rendered as the view's
  `#header` region (injected by `viewsPreRender()`, NOT exported into the view YAML — per the
  brief's locked design decision). This matches the `/showcase` page's placement of the same
  widget above its content list.
- Options, in order, exactly as specified: **Compact list** / **Cards** (default-selected) /
  **Map** (`available: false`, renders "Map (soon)", `aria-disabled="true"`). Same three options,
  same order, same instance_id (`directory.layout`) as the `/showcase` stub — this is the *same*
  switcher widget, not a re-implementation.
- "Viewing:" label precedes the control (per `do-showcase-variant-switcher.html.twig` line 25 —
  already fixed by SC-F1, confirmed unchanged here).
- ⓘ trigger renders `HelpText::get('showcase.switcher.directory.layout')` — the already-shipped
  copy: *"Compact list favors scanning many groups fast; Cards shows more per-group detail; Map
  plots groups geographically."* No new HelpText entry.
- Filters/pager render below the switcher, in their existing position and markup — completely
  unaffected by this story.

### State: Compact list selected (via click, no reload)

```
================================================================
 All Groups
================================================================
 Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
          │  ● Compact list │   Cards   │  Map (soon)  │
          └──────────────┴──────────────┴──────────────┘

 [ Search groups ______ ]  [ Location ______ ]  [ Primary language ▾ ]
 [ Filter ]  [ Reset ]

 ────────────────────────────────────────────────────────────
  ( compact rows render here — see Surface 2 )
 ────────────────────────────────────────────────────────────
 [ « ]  1  2  3  [ » ]
================================================================
```

- Same URL, same query params (filters + `page` untouched) — only the wrapper's
  `data-do-directory-variant` attribute flips (see Surface 3). No network request, no full page
  reload; the JS click handler already does this generically for any switcher instance (SC-F1
  `do_showcase.switcher.js`), this story just adds the CSS that makes the attribute flip visible.

### State: no-JS fallback (`?variant=compact` in URL)

```
 Viewing: [ Compact list (current) ]  [ Cards ]  [ Map ]
           ^ plain <a href="?variant=compact"> link, marked
             "(current)" in the link's own visible text —
             navigating this link reloads /all-groups?variant=compact
             and the SERVER renders the compact wrapper class
             directly (see Fallback behavior below) — no JS
             required for the initial correct state.
```

### State: `map` selected via URL fallback while unavailable (defensive)

```
 ?variant=map is requested, but "Map" is available:false today.
 VariantSwitcher::resolveSelection() falls back to the first
 available option (Compact list) — the directory renders in
 compact mode, NOT a blank/broken map. Same graceful-fallback
 behavior SC-F1 already guarantees; this story adds no new logic,
 just confirms the existing contract holds for this instance.
```

---

## Surface 2 — Compact-row layout (one row per group)

### State: many (typical — several groups match current filters)

```
 ────────────────────────────────────────────────────────────
  Riverside Book Club          [Discussion]  24 members  [Open]
  Downtown Runners             [Sports]      112 members [Moderated]
  Oak Street Makers            [Hobby]       8 members   [Invite only]
  Night Owls Coding Collective [Tech]        3 members   [Open]
 ────────────────────────────────────────────────────────────
```

- **Horizontal order, left to right, one row per group:**
  1. **Group name** — linked to the group's canonical page (same `link_to_entity: true` field the
     cards mode already uses — `label` field, unchanged).
  2. **Type badge** — `[Type]` bracketed text (non-color, matches the `/showcase` `[ live ]` /
     `[ coming ]` badge convention from #119's wireframe), sourced from `field_group_type`
     (taxonomy-term reference — renders the term's name, e.g. "Discussion", "Sports").
  3. **Member count** — plain text `"N members"` (per brief AC; F tries Views aggregation first,
     degrades to type+visibility only if that forces a schema change — flagged in survey.md, not
     a design concern for this wireframe, but if degraded, this column is simply omitted and the
     row becomes name / type / visibility only — **no dead placeholder, no "—"** in its place).
  4. **Visibility badge** — bracketed text label (see below), rightmost.
- **Typography scale relative to card mode:** compact rows use the SAME base font-size as card-mode
  body text (no separate smaller compact-mode type scale is introduced — density comes from
  removing the description text and card padding/borders, not from shrinking text, which would
  regress AA text-scaling/reflow). Row height is tight (single line per group, no wrapped
  description) — this is what makes it "compact" relative to the taller card boxes.
- **Spacing:** each row is a single horizontal flex/grid line with consistent gutters between the
  four fields (low-fi: two-plus-space gaps as shown above); rows are separated by a thin rule or
  alternating-row background — no color-only row separation is required by AA, but a visible rule
  is the simplest zero-risk choice. No card border/shadow/padding box per row (that's the visual
  delta from cards — dense list, not a grid of boxes).
- **Non-color status treatment for the visibility badge:** the badge is a TEXT label —
  `[Open]` / `[Moderated]` / `[Invite only]` — mapped from the `field_group_visibility` stored
  values (`open` / `moderated` / `invite_only`, confirmed in
  `docs/groups/modules/do_chrome/src/Hook/VisibilityTooltip.php`). Any background/foreground color
  applied on top of the text is a purely decorative reinforcement, never the sole cue — the same
  non-color-status pattern the `/showcase` catalog badges and the switcher's `●` selection glyph
  already use in this codebase. Truthful labels only — no invented visibility states.
- **Description field:** the `field_group_description` column that cards show is NOT rendered in
  compact rows (that's the density trade the issue's own framing describes: "information density
  vs. visual scannability" — the switcher's own tooltip copy says exactly this). The Views field
  stays attached to the display (all four/five fields render for both variants, per the brief's
  locked decision — "All four fields render in both variants; card CSS visually hides or restyles
  them") — compact CSS hides `field_group_description` and `created`; cards CSS keeps them exactly
  as before and hides/reflows the new type/member-count/visibility fields it doesn't currently
  show. This wireframe does not prescribe the cards-mode hide rule beyond "must look byte-identical
  to today" — that is F's CSS-selector work, not a new visual design.

### State: empty (no groups match current filters)

```
 ────────────────────────────────────────────────────────────
  No groups match your search.
  Try a different search term or clear your filters.
 ────────────────────────────────────────────────────────────
```

- Reuses the view's EXISTING empty-region copy/behavior (the `area_text_custom` empty region
  already defined in `views.view.all_groups.yml` — "No groups yet" / "Be the first to start a
  community" / "Create a group" link — is the TRUE zero-groups-ever-exist case; a filtered-to-zero
  result under an exposed filter is a different, more common case not currently distinguished in
  the view config). **Open question:** does the existing view empty region already distinguish
  "no groups at all" vs. "no groups match this filter"? If not, that's pre-existing behavior this
  story does not change — the compact variant reuses whatever the empty region renders today,
  unchanged, in both variants. Flagged under Open Questions below in case F finds the copy
  misleading for the filtered-empty case, but that is out of this story's scope to fix.

### State: one (single group matches)

```
 ────────────────────────────────────────────────────────────
  Riverside Book Club          [Discussion]  24 members  [Open]
 ────────────────────────────────────────────────────────────
```

- Identical row rendering to "many" — no special singular-count treatment needed since the row
  itself has no count-dependent copy (the pager/result-count summary, if any, is unchanged
  existing view behavior, not something this story adds).

### State: error (view render fails / field data missing for a group)

```
 ────────────────────────────────────────────────────────────
  Riverside Book Club          [Uncategorized]  — members  [Open]
 ────────────────────────────────────────────────────────────
```

- If `field_group_type` is empty for a given group (not every group need have a type term set),
  the type badge renders `[Uncategorized]` (truthful placeholder), never a blank badge or a PHP
  notice. If member count is unavailable for a specific row (aggregation edge case), render
  `"— members"` rather than `"0 members"` (0 is a truthful count; an unavailable/failed count is
  not the same as zero) — **flagged as an open question below**, since the brief doesn't specify
  this distinction and F may find zero-vs-unavailable indistinguishable at the Views-aggregation
  level, in which case rendering "0 members" for both is an acceptable, documented simplification.

---

## Surface 3 — Wrapper CSS-variant class contract

### Contract (confirmed against the brief's locked design decision)

```
<div class="view-content" data-do-directory-variant="cards">   <-- DEFAULT
  ...rows (same fields/markup in both variants)...
</div>

<div class="view-content" data-do-directory-variant="compact">  <-- after toggle
  ...same rows, same markup, only CSS matching changes...
</div>
```

- **The attribute is applied to the view's `.view-content` wrapper element** — the element Views
  already renders once per display around the row set (not a new sibling wrapper, not the outer
  `<div class="view view-all-groups">` region, not per-row). This is the natural attach point
  because it is the ONE element `hook_views_pre_render` can reliably annotate via
  `$view->element['#attributes']` (or an `#attached` + template preprocess on `views_view`) without
  restructuring the row/style plugins.
- `data-do-directory-variant="cards"` (or the attribute simply absent) — cards render EXACTLY as
  today. No compact CSS selector may match when the attribute is absent or `="cards"`.
  `data-do-directory-variant="compact"` — the ONLY value the new `directory-compact.css` rules key
  off (e.g. `.view-content[data-do-directory-variant="compact"] .views-row { ... }`). This satisfies
  the brief's AC: "no wrapper class is applied [in cards state] and no compact CSS matches."
- **JS toggles this same attribute** — `do_showcase.switcher.js`'s existing `select()` handler
  (SC-F1, unchanged) fires a click/keydown selection event; this story's ONLY JS-adjacent addition
  (if any is needed beyond what SC-F1 already does generically) is wiring that selection event to
  set `data-do-directory-variant` on `.view-content` — confirm with F whether this needs a small
  new behavior in a `do_showcase.directory-compact.js` file or can be expressed as a CSS attribute
  selector keyed on the SAME `aria-checked` state already toggled on the switcher's own DOM
  (e.g. `body:has([data-do-showcase-id="compact"][aria-checked="true"]) .view-content { }` if
  `:has()` support is acceptable, or a minimal dedicated behavior if not) — **this is an
  implementation choice for F, not a design decision**; the wireframe only fixes the CONTRACT
  (attribute name, values, element it lives on), not the exact JS/CSS mechanism connecting the two.
  **Flagging as open question below** since the brief says "JS toggles a wrapper class" but doesn't
  specify whether that's a new small behavior or a CSS-only `:has()` trick.

---

## A11y contract (confirmed, no new keyboard/focus code)

| Requirement | Source | Confirmed unchanged |
|---|---|---|
| Keyboard operation (Tab, Arrow-Left/Right, Enter/Space) | SC-F1 `do_showcase.switcher.js` | Reused verbatim — this story adds zero keyboard-handling code. |
| Visible focus outline | SC-F1 CSS token (double-border low-fi stand-in per 0119 wireframe Surface 1 "State: focus") | Reused verbatim. |
| "(soon)" suffix + `aria-disabled` on Map | `VariantSwitcher::build()` (already implemented) | Reused verbatim — this story supplies the SAME three options with Map's `available: false`, the class does the rest. |
| Radiogroup label ("Viewing") | `do-showcase-variant-switcher.html.twig` line 25 | Reused verbatim. |
| Contrast ≥ 4.5:1 on visibility badge (light/dark) | New requirement for THIS story's new compact-row markup | F's CSS pass must verify; this wireframe specifies the badge is TEXT (see Surface 2) so contrast failure cannot also be a non-color-cue failure — the text itself remains legible/readable independent of any background tint chosen. |
| Non-color status (badge = text label, not dot) | New requirement for THIS story | Confirmed in Surface 2 — `[Open]` / `[Moderated]` / `[Invite only]` are the load-bearing cue. |

No new keyboard code, no new focus-ring CSS, no new ARIA pattern is introduced by this story —
the only new AA surface to verify is contrast on the new compact-row badge text, which F must
check against both the site's light theme and (if one exists) a dark mode.

---

## Fallback behavior (no-JS — server renders correct initial state)

**This must NOT be JS-only.** The Twig/hook layer renders the correct wrapper attribute
server-side, based on the `?variant=` query parameter, BEFORE any JS runs:

```
GET /all-groups                     -> data-do-directory-variant="cards"   (server default)
GET /all-groups?variant=compact     -> data-do-directory-variant="compact" (server-resolved)
GET /all-groups?variant=cards       -> data-do-directory-variant="cards"   (server-resolved)
GET /all-groups?variant=map         -> falls back to first available (compact) per
                                        VariantSwitcher::resolveSelection() — server-resolved,
                                        same graceful-fallback contract as the switcher itself.
```

- `viewsPreRender()` reads `$request->query->get('variant')`, resolves it through the SAME
  selection logic the switcher uses (first-available fallback for unknown/unavailable values —
  do not duplicate that logic ad hoc; call through `VariantSwitcher` or mirror its exact fallback
  rule), and sets the resolved variant on the view's render array / attributes BEFORE the view
  renders its rows — this is what makes the no-JS `?variant=` links in Surface 1's fallback state
  actually work (a plain link navigation reloads the page and the server must already know which
  variant to paint, since there is no JS to fix it up after the fact).
- This is the same `#cache['contexts'][] = 'url.query_args:variant'` requirement the brief and
  survey already call out (Dynamic Page Cache must vary on this param, or the fallback breaks for
  the second visitor who gets a cached response for the wrong variant).
- **This is explicitly an F implementation task, not a JS-only fix** — flagging per the brief's
  own instruction to call this out so F wires it into the `hook_views_pre_render` method, not
  merely into `do_showcase.switcher.js`.

---

## Interaction with the SC-F1 `/showcase` stub (shared sessionStorage)

The sessionStorage key `doShowcase.variant.directory.layout` is the SAME key for both the
`/showcase` stub instance and this story's `/all-groups` instance — because both call
`VariantSwitcher::build('directory.layout', ...)` with the identical `instance_id`. This is
**intended UX, not a bug**:

```
1. User visits /showcase, selects "Compact list" on the directory.layout stub switcher.
   -> sessionStorage['doShowcase.variant.directory.layout'] = 'compact'
2. User navigates to /all-groups (no ?variant= in the URL).
   -> do_showcase.switcher.js reads the stored value, re-selects "Compact list" client-side.
   -> The directory renders compact, matching the user's prior choice on /showcase.
```

**U should test for this** (per the story's own instruction): navigate `/showcase` → select
compact → navigate to `/all-groups` (bare URL, no query string) → assert the directory renders in
compact mode, not cards. This is the cross-page persistence the shared `instance_id` buys for
free, and it is correct, not a leak to guard against.

---

## No wireframe reinvention of the switcher device itself

Surface 1's switcher control (segmented radiogroup, `●` selection glyph, roving tabindex, ⓘ
tooltip trigger, "(soon)" suffix, no-JS `?variant=` links, focus-ring treatment) is **the exact
same low-fi device** specified in `docs/handoffs/0119-variant-framework/wireframe.md` Surface 1.
This wireframe only supplies the DELTA: which three options render (`Compact list` / `Cards` /
`Map (soon)`, same as `/showcase`'s own stub — no invented fourth option), where it mounts
(`/all-groups` view `#header`, not a controller page body), and the new compact-row/wrapper-class
material that did not exist in #119's scope.

---

## Open questions for approval

1. **JS-to-wrapper-attribute wiring mechanism** — is `select()` in `do_showcase.switcher.js`
   extended with a small directory-specific callback (or a generic "on select, toggle a
   caller-supplied wrapper attribute" hook), or does F use a CSS `:has()` selector keyed on the
   switcher's own `aria-checked` DOM state to avoid touching the shared JS file at all? Either
   satisfies "no full page reload, live class flip" — this is an implementation choice, not a
   visual/copy decision, but O/human should confirm which approach F should take before F starts,
   since it affects whether `do_showcase.switcher.js` (a SHARED file used by other instances) gets
   touched by this story.
2. **Filtered-to-zero-results empty copy** — does "No groups match your search" already exist as
   distinct copy from the true empty-directory case ("No groups yet / Be the first...")? The
   current `views.view.all_groups.yml` only shows one `area_text_custom` empty region. If the
   filtered-empty case currently shows the same "Be the first to start a community" copy even when
   groups DO exist but none match the filter, that is a pre-existing truthful-copy gap this story
   does not fix — flagging so O can decide whether it's in scope or a follow-up.
3. **Member count "unavailable" vs. "zero"** — if F's Views-aggregation attempt can't distinguish a
   genuinely-zero-member group from an aggregation failure, is rendering "0 members" for both an
   acceptable simplification, or must F surface the distinction? Recommend accepting the
   simplification (zero members is itself a realistic, valid state) unless O objects.

---

## Existing components/patterns reused

- SC-F1 `VariantSwitcher::build()` + `do-showcase-variant-switcher.html.twig` +
  `do_showcase.switcher.js` — verbatim, same `instance_id`.
- SC-F1 `/showcase` stub's three-option list shape (`Compact list` / `Cards` / `Map(soon)`) —
  same order, same labels.
- `showcase.switcher.directory.layout` HelpText copy — verbatim, already shipped.
- `/showcase` catalog's non-color `[ live ]` / `[ coming ]` bracketed-text badge convention —
  reused for both the type badge and the visibility badge in compact rows.
- `VisibilityTooltip.php`'s `open` / `moderated` / `invite_only` value set — reused as the source
  of truth for the visibility badge's three text labels (`Open` / `Moderated` / `Invite only`).
- The existing `views.view.all_groups.yml` empty-region copy and exposed-filter form — unchanged
  pass-through in both variants.
