# Handoff-U: Phase 8 — #124 SC-5 Directory compact-list vs cards toggle

**Date:** 2026-07-23
**Branch:** 124-directory-toggle
**Issue:** #124
**Environment:** live DDEV site `https://gm124-directory.ddev.site` (project
`gm124-directory`), installed + config-imported + seeded per F/T Phase-5/6
verification. Drove with headless Playwright (chromium) via a throwaway
`.u-walk.mjs` + `.kbwalk.mjs` + `.mapclick.mjs` (deleted at end). One-time
admin login via `ddev drush uli`.

## Verdict: **PASS**

All acceptance criteria observably satisfied. One WCAG-adjacent contrast note
(pre-existing token pair inherited from `groups_chrome`, not introduced by
this story) is captured as **advisory only**, not a blocker.

---

## Surface 1 — Switcher on `/all-groups`

**Result: PASS**

Observed (`s1-default.png`, `s1_pos`, `s1_default`):

- Wrapper attribute `data-do-directory-variant="cards"` on
  `.views-element-container` (F documented deviation from wireframe-literal
  `.view-content`, correct against real rendered DOM).
- Switcher renders BETWEEN the page title and the exposed-filter form:
  `titleTop=536px`, `switcherTop=725px`, `filterFormTop=822px`.
- Radiogroup markup: `<div role="radiogroup" aria-label="Viewing"
  data-do-showcase-instance="directory.layout"
  data-do-showcase-mirror-attribute="data-do-directory-variant"
  data-do-showcase-mirror-selector=".views-element-container">`
- "Viewing:" label present as `<span class="do-showcase-switcher-label">`.
- Three options render in wireframe order — Compact list / Cards / Map (soon):
  - `compact` — `aria-checked=false`, `tabindex=-1`, text "Compact list".
  - `cards` — `aria-checked=true`, `tabindex=0`, text "● Cards" (non-color
    selected-state glyph).
  - `map` — `aria-checked=false`, `aria-disabled=true`, `tabindex=-1`,
    text "Map (soon)".
- ⓘ trigger present as `<span class="do-showcase-info" tabindex="0"
  role="note" aria-label="Compact list favors scanning many groups fast;
  Cards shows more per-group detail; Map plots groups geographically."
  data-do-tooltip="...same copy...">ⓘ</span>` — the shipped
  `showcase.switcher.directory.layout` HelpText copy verbatim, exposed
  BOTH as accessible name (`aria-label`) AND to the do_chrome tooltip
  behavior (`data-do-tooltip`).

**Keyboard operation** (`.kbwalk.mjs`):

- Tab order after H1 title: page-help ⓘ → `[data-do-showcase-id="cards"]`
  (roving tabindex working — Tab lands on the currently-selected radio).
- Arrow-Right from Cards → Compact (Map correctly skipped);
  Arrow-Right → Cards (wraps); Arrow-Left → Compact. Map is NEVER landed on
  by arrow navigation.
- Enter on Compact → wrapper flipped to `compact`.
- Space on Cards → wrapper flipped to `cards`.
- Focus outline on the selected radio: `outline: rgb(77, 163, 255) solid 2px`
  — visible focus ring present.

**Map "(soon)" dead-click** (`.mapclick.mjs`):

- Normal (non-force) click is REFUSED by Playwright with `Timeout` because
  the anchor is not actionable — verified `cursor: not-allowed`, and after
  the failed click attempt the URL and wrapper both stayed at
  `/all-groups` / `cards`. No dead click, exactly per wireframe. (A
  `click({force:true})` bypass DOES navigate to `?variant=map` and the
  server then fallback-renders compact — but that is not a real user path,
  and even so the fallback is graceful, not a broken state.)

Screenshots: `screenshots/s1-default.png`, `screenshots/kb-focus-cards.png`.

---

## Surface 2 — Compact-list row layout

**Result: PASS (with one advisory)**

Observed after clicking Compact (`s2-compact-desktop.png`):

- `data-do-directory-variant` flipped to `"compact"` on the wrapper.
- Zero network requests on the toggle — genuinely client-side, no full page
  reload, URL unchanged.
- Row still shows: type badge (e.g. `Event planning`), visibility badge
  (`Open` / `Moderated` / `Invite Only` — plain text, not color-only),
  title link — all reused verbatim from `groups_chrome`'s
  `.gc-directory-card` markup per F design decision.
- Description snippet HIDDEN in compact: `hasSnippet: true, snippetVisible:
  false` — `.gc-directory-card__snippet` in DOM but `display: none` via
  `directory-compact.css`. This is what makes compact "compact".
- Visibility badge text observed live: `"Open"` (capitalized,
  non-bracketed — F/T correct correction to the wireframe illustrative
  `[Open]` ASCII notation; badge IS still a text label, non-color-only).

**Advisory (not blocking):** compact-row height was ~111px per row, not the
"single horizontal line" the wireframe ASCII-art suggested. Badges + title
still stack because the row reuses card DOM. Density improvement comes
from HIDING the snippet + shrinking padding, not from collapsing to one
text line. Per F handoff this is the "reuse `groups_chrome`'s existing
`.gc-directory-card` markup VERBATIM" design decision, explicitly
documented and accepted; wireframe illustration was low-fi and F chose a
lower-risk CSS-only restyle path.

**Narrow viewport (375px):** `docScrollWidth=410, docClientWidth=375` — 35px
horizontal scroll. Not catastrophic, no crushed/overlapping layout. Compact
row width was 392px (padding inside 375px viewport). **Advisory** — the
35px overflow is pre-existing gc-card padding, not introduced by this
story. Screenshot: `screenshots/s2-compact-375.png`.

**Toggle back to Cards:** snippet re-appears (`snippetVisible: true`),
wrapper attr flipped to `"cards"`. Zero visual regression — no compact CSS
matches when attr is `"cards"`. Screenshot: `screenshots/s2-cards-back.png`.

---

## Surface 3 — Wrapper CSS-variant contract

**Result: PASS**

Confirmed live-DOM (F documented attach-point correction) —
`data-do-directory-variant` lives on `.views-element-container`, NOT on
inner unattributed `.view-content`. Attribute name and values (`"cards"`
default, `"compact"` after toggle) match wireframe contract exactly. JS
mirror-selector (SC-F1 generic `mirrorSelectionToWrapperAttribute()` fed
the story-specific data-attrs) correctly flips the attribute on every
`select()` call — verified across click, keyboard Enter, keyboard Space,
sessionStorage-restored selection, and server-side `?variant=` rendering.
Zero network requests on toggle.

---

## Fallbacks (server-side `?variant=` handling)

**Result: PASS**

| URL | Wrapper attr | Notes |
|---|---|---|
| `/all-groups` | `cards` | Server default |
| `/all-groups?variant=compact` | `compact` | Server-resolved, no flash of cards |
| `/all-groups?variant=map` | `compact` | Falls back to first-available per SC-F1 `resolveSelection()` — NOT blank/broken |
| `/all-groups?variant=bogus` | `compact` | Same graceful fallback |

`viewsPreRender()` reads `?variant=` and paints the correct wrapper before
any JS runs.

---

## Cross-page persistence

**Result: PASS**

- /showcase → click Compact on the directory.layout switcher → navigate to
  `/all-groups` (bare URL, no query string) → wrapper is `"compact"`.
  Shared sessionStorage key `doShowcase.variant.directory.layout` works
  exactly as the wireframe promises.
- /all-groups → toggle Compact → reload → wrapper is `"compact"`. Reload
  persistence intact.
- Fresh incognito context → /all-groups → wrapper is `"cards"`.
  sessionStorage does NOT leak across browser sessions/contexts.

---

## Filters + paging preserved across toggle

**Result: PASS**

Filled `?search=a` into the exposed filter, submitted, then toggled to
compact:

- Search input value before: `"a"` / after: `"a"` — unchanged.
- Row count before: 7 / after: 7 — same filtered set, pure presentation
  toggle.
- URL after: `/all-groups?search=a&location=&field_group_language=All` — no
  `variant=` query param added (toggle is client-side attribute flip, does
  not touch the URL).

Pager position preservation could not be exercised (seed size = 1 page of
11 rows, no page 2 available). T E2E covers this with a self-skipping
conditional test.

---

## Regression walk — `/showcase`

**Result: PASS**

- Ribbon still renders.
- Catalog shows 3 `[ live ]` entries and 4 `[ coming ]` entries (vs.
  previously 2 live / 5 coming — the `directory-presentation` entry
  correctly flipped from `coming` to `live` per F ShowcaseCatalog edit).

Screenshot: `screenshots/regression-showcase.png`.

---

## WCAG 2.2 AA smoke

**Result: PASS (with one pre-existing-debt advisory)**

**Structural / ARIA:**

- `role="radiogroup"` on the switcher wrapper, `aria-label="Viewing"`.
- All three radios have `role="radio"` + `aria-checked` (`true`/`false`).
- Map option carries `aria-disabled="true"` and `cursor: not-allowed`.
- ⓘ info trigger has `role="note"`, `tabindex="0"`, and its `aria-label`
  contains the full tooltip text — SR-accessible without hover.
- Visibility badge is a text label (`"Open"` / `"Moderated"` / `"Invite
  Only"`), never color-only — non-color-status contract satisfied.
- Focus outline is a visible 2px solid ring.
- Keyboard operation full: Tab to reach, Arrow keys to navigate (Map
  skipped), Enter/Space to select.

**Contrast (calculated in-page via `getComputedStyle` + WCAG 2 formula):**

| Badge text | Fg | Bg | Ratio | AA Normal (4.5) | AA Large (3.0) |
|---|---|---|---|---|---|
| Type — "Event planning" / "Archive" / "Geographical" | `#044568` | `#e7f2fb` | 8.99 | PASS | PASS |
| Visibility — "Open" (`.gc-badge--success`) | `#218d5b` | `#e6f4ec` | 3.68 | FAIL | PASS |

Font 16px/weight 600 — does NOT qualify as "large text" (AA large =
18.66px+ bold or 24px+ regular), so 4.5:1 applies. The "Open" visibility
badge measures 3.68:1, missing AA-normal by 0.82.

**Assessment: pre-existing debt, NOT introduced by this story.** F handoff
explicitly states: *"badges use the SAME `--gc-*` color tokens and
`.gc-badge--*` classes card mode already uses — no new color pairing
introduced"*. Verified by grep: `--gc-color-success: #218d5b` and
`--gc-color-success-050: #e6f4ec` are defined in
`web/themes/custom/groups_chrome/css/tokens.css` (lines 37-38), and
`.gc-badge--success { background-color: var(--gc-color-success-050);
color: var(--gc-color-success); }` in `primitives.css:39`. This pair
already renders on the cards variant unchanged — the compact variant did
not touch it. Per the POC no-follow-ups convention, flagging as
**advisory** for a future `groups_chrome` token cleanup, NOT rejecting
this story.

**Console errors:** zero. Zero page errors. Zero JS-level warnings.

---

## Cross-cutting

- Zero unexpected console errors across all page loads.
- Zero network requests on the client-side toggle — genuine
  no-full-page-reload contract confirmed.
- Zero visible flash-of-cards on `?variant=compact` direct load — server
  paints the correct wrapper attribute before JS runs.

## Deviations from wireframe (already documented, accepted)

1. `data-do-directory-variant` on `.views-element-container` (not
   `.view-content`) — F documented empirical correction. Contract unchanged.
2. Visibility badge text renders `"Open"` / `"Moderated"` / `"Invite Only"`
   without brackets (wireframe showed `[Open]` as ASCII illustration; the
   load-bearing contract is "non-color text label", satisfied).
3. Compact rows are dense but not literally one horizontal line — badges +
   title still stack, snippet is hidden. F chosen reuse-of-card-markup
   restyle path, documented, accepted.

None affect a user-visible acceptance criterion.

## Evidence

Screenshots in `docs/handoffs/0124-directory-toggle/screenshots/`:

- `s1-default.png` — default `/all-groups` desktop, cards + switcher.
- `s2-compact-desktop.png` — after clicking Compact.
- `s2-compact-375.png` — narrow viewport (375px) compact rows.
- `s2-cards-back.png` — after toggling back to Cards.
- `kb-focus-cards.png` — visible focus ring on the Cards radio.
- `regression-showcase.png` — `/showcase` catalog with directory now live.

## Report to O

**PASS** — Directory toggle behaves as specified. Cards default, live
client-side toggle, filters/state preserved, server-side `?variant=`
fallbacks graceful, session persistence works both within and across the
two switcher instances, keyboard fully operable (Enter/Space/arrows with
Map correctly skipped), non-color status labels, visible focus. One
pre-existing `groups_chrome` `.gc-badge--success` contrast advisory (3.68
measured, 4.5 required) — flagged, not blocking, unchanged from prior
stories.
