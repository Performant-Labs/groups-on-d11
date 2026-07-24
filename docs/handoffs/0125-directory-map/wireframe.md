# Wireframe — 0125-directory-map (SC-6: directory map view)

Low-fidelity, structure-only. Mode (a) — generated. This wireframe covers ONLY the delta this
story introduces on `/all-groups`: flipping `Map` from `(soon)` to a live, selectable third
variant, and the new map-region presentation that replaces the row grid in that mode. It does
**not** re-design the switcher control itself (radiogroup structure, focus ring, `●` glyph, ⓘ
tooltip trigger, no-JS fallback) — that low-fi device is already specified in
`docs/handoffs/0119-variant-framework/wireframe.md` Surface 1 and `docs/handoffs/0124-directory-
toggle/wireframe.md` Surface 1/3, and is reused verbatim, with `Map` now `available: TRUE`. It
also does NOT re-design the Cards or Compact-list presentations, which are unchanged pass-through
(pinned by `directory-cards.spec.ts` / `directory-toggle.spec.ts` per the brief's AC).

---

## Surface 1 — Switcher + map region on `/all-groups`

### State: default (Cards selected — unchanged, Map now live not "(soon)")

```
================================================================
 All Groups
================================================================
 Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
          │ Compact list │  ● Cards     │     Map      │
          └──────────────┴──────────────┴──────────────┘

 [ Search groups ______ ]  [ Location ______ ]  [ Primary language ▾ ]
 [ Filter ]  [ Reset ]

 ────────────────────────────────────────────────────────────
   ( existing card-mode directory renders here, unchanged )
 ────────────────────────────────────────────────────────────
 [ « ]  1  2  3  [ » ]
================================================================
```

- Only visible delta from SC-5's shipped switcher: the third option's label changes from
  `Map (soon)` / `aria-disabled="true"` to plain `Map`, selectable, because
  `VariantSwitcher::directoryLayoutOptionIds()`'s `map` entry flips `available: FALSE` ->
  `available: TRUE` (survey.md — "single-line flip"). No other switcher markup changes.
- ⓘ tooltip copy is unchanged (`HelpText::get('showcase.switcher.directory.layout')` already
  reads "...Map plots groups geographically." — shipped by SC-F1, zero edits here).

### State: Map selected (`?variant=map`, or clicked live — many groups have a location)

```
================================================================
 All Groups
================================================================
 Viewing: ┌──────────────┬──────────────┬──────────────┐  ⓘ
          │ Compact list │    Cards     │   ● Map      │
          └──────────────┴──────────────┴──────────────┘

 [ Search groups ______ ]  [ Location ______ ]  [ Primary language ▾ ]
 [ Filter ]  [ Reset ]

 Showing 4 groups with a location.
 ┌──────────────────────────────────────────────────────────┐
 │ (plain light-grey background — no tiles)                 │
 │                                                            │
 │              📍Berlin        📍Paris                      │
 │                                                            │
 │                              📍Brussels                   │
 │                                                            │
 │                                                            │
 │  📍Portland                                                │
 │                                                            │
 └──────────────────────────────────────────────────────────┘
  aria-label="Map of groups with a location" (see Surface 2)
================================================================
```

- **The map region REPLACES the row grid** (`.view-content`), it does not overlay it — same
  "one presentation visible at a time" model SC-5 already established for Cards vs. Compact
  (only one `data-do-directory-variant` value is active; the map container and the row grid are
  mutually exclusive in the DOM's visible state, matching the existing pattern of hiding the
  inactive variant's markup rather than stacking both — see Surface 3 contract). The pager
  below the row grid is likewise hidden in map mode (pagination has no meaning for a single
  all-markers view) — **truthfully hidden, not shown-but-inert**.
- **Filters/exposed-filter form stay visible and functional above the map**, exactly as in
  Cards/Compact mode — Location/language filters still narrow which rows (and therefore which
  markers) are eligible to plot. This matches SC-5's "filters survive a toggle" contract
  (`directory-toggle.spec.ts` already pins filters+pager surviving a variant switch; this story
  extends that same guarantee to markers).
- **Caption line** ("Showing N groups with a location.") sits between the filter row and the map
  container, visible text (not just an `aria-label` — a sighted user needs the same truthful
  count a screen-reader user gets). N is the count of rows with resolvable coordinates AFTER
  filters apply, not a fixed "4."
- **Map dimensions:** a single fixed-aspect box, full content-column width, height constrained
  (low-fi: roughly 16:9, e.g. `min-height: 400px`) — same content-column width the card grid
  already occupies, so the map doesn't force a new page-width breakpoint.
- **Marker behavior:** default Leaflet marker sprites (vendored, unmodified), one per group with
  a location, positioned via Leaflet's built-in Mercator projection against lat/lng — no
  clustering, no custom icon (non-goals, confirmed below). **Click behavior is direct-click, not
  popup-first**: clicking a marker navigates immediately to that group's canonical page (brief
  AC-2 — "Clicking a marker navigates to the group's page"). This is a deliberate deviation from
  Leaflet's popup-on-click default — the brief's own AC is unambiguous that a click IS the
  navigation, not a two-step "click to reveal a popup, then click a link inside it." No popup
  step is introduced. (If a human reviewer wants a hover/focus tooltip showing the group name
  before commit-to-click, that is a **non-goal** for this POC per brief's "no map-side filtering
  beyond what the switcher does" framing — flagged under Open Questions below since it's a
  plausible UX improvement but not requested.)
- **No background tiles** — solid light-grey `.leaflet-container` background (brief's Design
  note, zero-CDN constraint). This is visually honest: the low-fi mock above shows a blank grey
  box with pins, not a map with roads/labels, because that's genuinely what ships.

### State: Map selected — only SOME groups have a location (truthful partial state)

```
 Showing 2 of 6 groups with a location.
 ┌──────────────────────────────────────────────────────────┐
 │ (plain background)                                        │
 │              📍Berlin        📍Paris                      │
 └──────────────────────────────────────────────────────────┘
```

- Caption reads **"Showing N of M groups with a location"** (M = total groups matching current
  filters, N = subset with resolvable coordinates) whenever N < M — the two-number form is
  strictly more truthful than "Showing N groups" alone, because it tells the visitor groups exist
  that simply aren't plotted (mirrors the brief AC: "map variant caption/aria label indicates
  'showing groups with a location'"). When N == M (all filtered groups have a location, e.g. the
  4-seed POC dataset with no filter applied), the caption drops the "of M" clause and reads
  **"Showing 4 groups with a location"** (first mock above) — no redundant "of 4" when there's no
  gap to disclose.
- Groups without `field_group_location` are **silently absent from the map** — no dead marker,
  no placeholder pin at `(0,0)`, no error glyph. The caption is the ONLY signal that some groups
  aren't shown; this matches survey.md's resolved behavior ("the row simply carries no
  `data-do-location-*` attrs; JS skips it silently").

### State: Map selected — zero groups have a location (edge case, defensive)

```
 Showing 0 of 6 groups with a location.
 ┌──────────────────────────────────────────────────────────┐
 │ (plain background, no markers)                            │
 │                                                            │
 │         No groups currently have a location set.          │
 │                                                            │
 └──────────────────────────────────────────────────────────┘
```

- Not reachable in the POC's seeded dataset (4 of 4 groups always have coordinates), but the
  container must not render a bare empty grey box with no explanation if a future group is added
  without a location and the dataset temporarily has zero geocoded groups matching the filter.
  Centered text inside the map container, same caption-line wording pattern as the partial state.
  **F should confirm this is trivial to add** (a simple marker-count check in
  `do_showcase.directory-map.js`) — flagged as a cheap defensive state, not a new design surface.

### State: Map selected — filtered to zero groups overall (pre-existing empty-result case)

```
 Showing 0 groups with a location.
 ┌──────────────────────────────────────────────────────────┐
 │  No groups match your search.                             │
 │  Try a different search term or clear your filters.       │
 └──────────────────────────────────────────────────────────┘
```

- Reuses the SAME existing view empty-region copy SC-5's wireframe already documented for
  Compact/Cards (`views.view.all_groups.yml`'s `area_text_custom`) — rendered inside the map
  container's box rather than a blank grey rectangle, so the "no results" message is visually
  consistent across all three variants. No new copy authored by this story.

---

## Surface 2 — Keyboard / AA behavior

### Map container `aria-label`

```html
<div class="do-showcase-map"
     role="application"
     aria-label="Map of groups with a location. 4 groups shown. Use the list below to navigate to a group by keyboard.">
  <!-- Leaflet mounts its canvas/DOM here -->
</div>
```

- `aria-label` states the truthful count AND signals the keyboard fallback exists — a screen
  reader user should never be left wondering "is there a way to reach these markers without a
  mouse?" The label answers that in the same string.
- `role="application"` is the standard pattern for a JS-driven interactive map widget where
  arrow-key semantics are owned by the map library itself, not native document flow — this
  avoids the screen reader intercepting arrow keys for its own virtual-cursor navigation inside
  the map region. (F should confirm Leaflet's own internal ARIA handling doesn't already supply
  an equivalent role before duplicating one — flagged below.)

### Keyboard fallback — SR-only list of "group name — city"

```html
<ul class="do-showcase-map-fallback-list visually-hidden">
  <li><a href="/group/12">Drupal France — Paris</a></li>
  <li><a href="/group/15">Drupal Deutschland — Berlin</a></li>
  <li><a href="/group/9">Camp Organizers EMEA — Brussels</a></li>
  <li><a href="/group/22">DrupalCon Portland 2026 — Portland, OR</a></li>
</ul>
```

- Rendered **immediately after** the map container, visually hidden (`visually-hidden` /
  clip-based CSS pattern, NOT `display:none` — must stay in the accessibility tree and be
  reachable by Tab), so keyboard/screen-reader users get an equivalent way to reach every group
  a mouse user reaches by clicking a marker, without needing to operate the map's pixel-based
  interaction model at all.
- **List content is "group name — city"** exactly as specified — city is the free-text
  `field_group_location_text` value already on each group (survey.md confirms this field already
  exists and is set for all four seed groups), NOT derived from reverse-geocoding the lat/lng.
  Each `<li>` is a real, independently focusable `<a>` to the group's canonical page — identical
  destination and identical link semantics to clicking that group's marker (same URL, same
  navigation, just a different input modality).
- **Order matches marker plot order** (same order the view's rows already render in, i.e. the
  same order Cards/Compact show groups) — no separate sort invented for the fallback list.
- This list is generated by the SAME JS that reads `data-do-location-lat/lng` off each row
  wrapper (survey.md's resolved seam) — one data source, two renderings (marker + list item), so
  they cannot drift out of sync with each other.

### Visible focus

- Each `<a>` in the SR-only fallback list gets the SAME focus-visible treatment already used
  elsewhere in this codebase (`directory-compact.css`'s `.gc-directory-card__title a:focus-
  visible { outline: 2px solid var(--gc-color-primary); outline-offset: 2px; }`) — no new focus
  ring design, reused token.
- The fallback list itself, though visually hidden by default, MUST become visible-on-focus for
  sighted keyboard users (a common accessible pattern: `.visually-hidden:focus-within` reveals the
  list) — otherwise a sighted keyboard-only user tabs into invisible links with no visual
  confirmation of where focus is. **This is a real requirement, not optional** — flagged
  explicitly so F doesn't ship `display:none`-on-focus-too by mistake, which would fail the
  "visible focus" AC for this exact population.
- Leaflet's own default marker icons are NOT independently keyboard-focusable in a meaningful way
  (Leaflet markers are `<img>`/`<div>` elements without native tab semantics unless a plugin adds
  it) — this story does **not** attempt to make the map canvas itself keyboard-navigable
  marker-by-marker; the SR-only list IS the keyboard path, not a supplement to a keyboard-
  operable map. This is the simplest-that-satisfies-AC choice for a POC (brief's own framing).

---

## Surface 3 — Wrapper attribute contract

### Contract (mirrors `directory-compact.css`'s contract exactly — same attribute, third value)

```
<div class="views-element-container" data-do-directory-variant="cards">    <-- default
  ...row grid renders, map region absent from DOM...
</div>

<div class="views-element-container" data-do-directory-variant="compact">  <-- SC-5, unchanged
  ...rows reflow to dense list...
</div>

<div class="views-element-container" data-do-directory-variant="map">      <-- THIS STORY
  ...row grid hidden via CSS, map container + SR-only fallback list shown...
</div>
```

- **Every new selector in `directory-map.css` and every DOM query in `do_showcase.directory-
  map.js` is scoped under `.views-element-container[data-do-directory-variant="map"]`** — the
  identical wrapper element and attribute `directory-compact.css` already keys off (confirmed
  live-DOM location: `DoShowcaseHooks::viewsPreRender()`'s `$view->element['#attributes']` lands
  on `.views-element-container`, NOT the inner `.view.view-id-all_groups` div — see that file's
  own docblock, unchanged by this story).
- When the attribute is absent, `="cards"`, or `="compact"`, **nothing in `directory-map.css`
  matches** — Cards and Compact continue to render exactly as before (brief AC: "Cards + Compact
  list variants render identically to pre-story").
- `directory-map.css`'s ONLY structural job: `.views-element-container[data-do-directory-
  variant="map"] .view-content { display: none; }` (hide the row grid — but the ROWS THEMSELVES
  stay in the DOM, unstyled-but-present, because `do_showcase.directory-map.js` reads their
  `data-do-location-lat/lng` attributes to build markers; hiding via CSS, not removing via JS, is
  what keeps that data-read possible) plus showing/sizing the new `.do-showcase-map` container
  and revealing the SR-only fallback list per Surface 2.
- **JS attach**: `do_showcase.directory-map.js` is a Drupal behavior, attached only when
  `do_showcase/directory-map` library loads (attached unconditionally alongside `directory-
  compact` in `viewsPreRender()`, per survey.md's "Attached by the same hook" — the library
  itself no-ops harmlessly if the wrapper attribute isn't `"map"` on `behavior.attach()`, exactly
  mirroring how `directory-compact.css`'s scoped selectors no-op when the attribute doesn't
  match). The behavior checks the wrapper's `data-do-directory-variant` value before doing any
  Leaflet work — no wasted map initialization when Cards/Compact is active.

---

## Non-goals confirmation (per brief)

Confirmed low-fi honors every non-goal — nothing in this wireframe introduces:

- **No custom marker icons** — the mock's 📍 glyphs are a low-fi ASCII stand-in for Leaflet's
  stock default blue-pin sprite (`marker-icon.png`), not a designed icon. Production renders
  Leaflet's unmodified default.
- **No marker clustering** — even if two groups' markers visually overlap at low zoom (not a
  concern with only 4 seed groups spread across 3 countries), no clustering plugin/behavior is
  introduced. Overlapping markers is an accepted POC-scope limitation, not a bug to fix here.
- **No live tile layer** — confirmed throughout Surface 1: every map mock shows a plain grey
  background, never a rendered street/terrain tile image.
- **No "add my location" UX** — no geolocation prompt, no "near me" control anywhere in this
  wireframe.
- **No map-side filtering** — the existing Location/language exposed filters (already rendered
  above the map, per Surface 1) are the ONLY filtering mechanism; no draw-a-radius, no
  click-to-filter-by-region control is introduced.
- **No geocoding UI** — coordinates are seed data (`step_700_demo_data.php`); no admin-facing
  "set this group's location on a map" widget is part of this story's UI surface (that would be a
  separate future story against the group edit form, out of scope here).

---

## Open questions for approval

1. **Hover/focus preview before click-to-navigate.** The direct-click-navigates behavior (Surface
   1) is unambiguous per the brief's AC wording, but it means a mouse user gets zero
   confirmation of which group a marker represents before committing to the click (no popup, no
   tooltip). A cheap, non-blocking addition would be a native `title` attribute on each marker
   (browser-native tooltip on hover, zero extra library code, zero extra DOM, satisfies "some
   preview" without introducing a popup step) — recommend approving this as an implementation
   nicety, but flagging since the brief didn't request it and it's technically outside "exactly
   what's asked."
2. **`role="application"` on the map container** — needs confirmation that Leaflet 1.9.4's own
   default container markup doesn't already ship a conflicting `role`/`aria-*` combination that
   this story's `aria-label` would need to merge with rather than set fresh. Low risk (Leaflet's
   base container is a plain unstyled `<div>` by default), but F should verify against the
   vendored 1.9.4 output before assuming this wireframe's exact HTML shape.
3. **Zero-groups-have-a-location defensive state** (Surface 1, fourth state) — not reachable with
   the current 4-seed dataset. Confirm with O whether F should bother implementing this exact
   copy/branch for a state that can't occur today, or whether "N of M" naturally degrading to
   "Showing 0 of 4 groups with a location" (still truthful, just without the friendlier centered
   message) is an acceptable simplification for POC scope.

---

## Existing components/patterns reused

- SC-F1 `VariantSwitcher::build()` + `do-showcase-variant-switcher.html.twig` +
  `do_showcase.switcher.js` — verbatim, same `instance_id` (`directory.layout`), same three-option
  radiogroup shape; only the `map` option's `available` flag and label text change.
- SC-5's `data-do-directory-variant` wrapper-attribute contract (`.views-element-container`,
  set by `DoShowcaseHooks::viewsPreRender()`) — extended with a third value, not redesigned.
- SC-5's "hide the inactive variant's markup via CSS, keep rows in DOM" pattern
  (`directory-compact.css`'s scoped-selector approach) — this story's `directory-map.css` follows
  the identical shape (hide `.view-content`, show `.do-showcase-map` instead).
- `HelpText::get('showcase.switcher.directory.layout')` — verbatim, already shipped, no new copy.
- `directory-compact.css`'s `:focus-visible` outline token
  (`var(--gc-color-primary)` / `2px solid` / `2px offset`) — reused for the SR-only fallback
  list's link focus state, no new focus design.
- The existing `views.view.all_groups.yml` empty-region copy ("No groups match your search...")
  — reused verbatim inside the map container for the filtered-to-zero-overall state.
- `field_group_location_text` (already-existing free-text city field, confirmed in survey.md) —
  reused as the fallback list's "— city" copy source; no reverse-geocoding introduced.
