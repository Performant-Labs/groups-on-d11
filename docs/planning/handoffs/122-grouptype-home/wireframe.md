# Wireframe — #122 SC-3 Group-type-driven homepages

**Mode:** (a) generated low-fi (ASCII/markdown; no images — matches survey's existing text-first
conventions and this theme's plain-CSS/no-build-step approach).

**Scope:** `/group/{id}` full-page render (`group--full.html.twig` and its theme-suggestion
variants), rendered by `groups_chrome_preprocess_group()`.

---

## 1. Page anatomy — all four states

Common chrome (unchanged across all states): page `<h1>` (rendered by the content_above
region, NOT by this template — confirmed in the existing twig comment at
`group--full.html.twig` lines 38–41), then the `.gc-group-header` block (badges, avatars,
join/leave action), then — **new** — the optional lead section, then the existing tab bar,
then `{{ content }}`.

```
<h1>{{ group label }}</h1>                         ← page title region (untouched)

┌─ .gc-group-header ────────────────────────────────────────────────┐
│ [Event planning]  [Open]  [42 members]        [Join group]        │  ← existing badges/action
│ (●)(●)(●)(●)(●)(●)(●)(●) +12                                      │  ← existing avatar row
└─────────────────────────────────────────────────────────────────┘

┌─ .gc-group-lead  (NEW — only when leading_section is set) ───────┐
│ Upcoming events  ⓘ                                                │  ← <h2> + tooltip trigger
│  • DrupalCon Portland 2026 keynote — Mar 3          [link]        │
│  • Sprint sign-up open — Mar 4                      [link]        │
│  • Volunteer orientation — Mar 5                    [link]        │
│  See all events →                                                 │
└─────────────────────────────────────────────────────────────────┘

┌─ .gc-group-tabs (nav) ─────────────────────────────────────────────┐
│  Stream   Events   Members   About                                │  ← existing, order unchanged
└─────────────────────────────────────────────────────────────────┘

{{ content }}                                                          ← existing body
```

### State: Events-first (DrupalCon Portland 2026, `field_group_type` = Event planning)

```
┌─ .gc-group-lead ───────────────────────────────────────────────────┐
│ Upcoming events  ⓘ                                                 │
│  • DrupalCon Portland 2026 — Main Conference          Mar 3, 2026  │
│  • Contribution Day sign-up                           Mar 2, 2026  │
│  • Trainings — Migrating to Drupal 11                 Mar 1, 2026  │
│  See all events →                                                  │
└──────────────────────────────────────────────────────────────────┘
```
Heading text: **"Upcoming events"**. Items ordered soonest-first (query below).

### State: Discussion-first (Core Committers, `field_group_type` = Working group)

```
┌─ .gc-group-lead ───────────────────────────────────────────────────┐
│ Recent discussions  ⓘ                                              │
│  • RFC: deprecating the legacy patch queue           2 days ago    │
│  • Core Committers office hours — agenda             4 days ago    │
│  • Triage backlog for 11.3                           1 week ago    │
│  See all discussions →                                             │
└──────────────────────────────────────────────────────────────────┘
```
Heading text: **"Recent discussions"**. Items ordered newest-first (by `node.created`).

### State: Docs-first (Thunder Distribution, `field_group_type` = Distribution)

```
┌─ .gc-group-lead ───────────────────────────────────────────────────┐
│ Documentation  ⓘ                                                   │
│  • Getting started with Thunder                                   │
│  • Upgrading from Thunder 7 to 8                                   │
│  • Media library configuration guide                              │
│  See all documentation →                                           │
└──────────────────────────────────────────────────────────────────┘
```
Heading text: **"Documentation"**. Items ordered newest-first (same as discussion; docs don't
carry a natural "soonest" ordering).

### State: Fallback / unmapped (Geographical, Archive, or unset `field_group_type`)

```
<h1>{{ group label }}</h1>

┌─ .gc-group-header ────────────────────────────────────────────────┐
│ [Geographical]  [Open]  [18 members]           [Join group]       │
│ (●)(●)(●)(●)                                                      │
└─────────────────────────────────────────────────────────────────┘

┌─ .gc-group-tabs (nav) ─────────────────────────────────────────────┐
│  Stream   Events   Members   About                                │
└─────────────────────────────────────────────────────────────────┘

{{ content }}
```

**No `.gc-group-lead` block renders at all** — not an empty container, not a header-only
stub. This must be **byte-identical** to today's DOM in that region. See §7 for the explicit
fallback contract.

---

## 2. Lead section — position, structure, semantics

- **Position:** directly **between** `.gc-group-header` and `.gc-group-tabs`, inside the same
  `{% if gc_group %}` branch of `group--full.html.twig`, as a sibling `<section>` — not
  nested inside the header (header is identity/action chrome; lead section is content
  preview) and not inside the tab `<nav>` (tabs stay a pure nav landmark).
- **Landmark / heading level:** wrapped in `<section class="gc-group-lead" aria-labelledby="gc-group-lead-heading">`.
  Heading is `<h2 id="gc-group-lead-heading">` — confirmed correct: the page `<h1>` is the
  group name (rendered upstream in content_above, per the existing twig comment), so the lead
  section heading is one level down, matching how `.gc-group-header` avoids re-emitting an
  `<h1>` for the same reason. No other `<h2>` currently exists on this page, so this is the
  first and only `<h2>` — clean, unambiguous heading hierarchy.
- **Top N = 3.** Justification: (a) matches the member-avatar pattern's implicit "preview,
  not full list" idiom already on this page (avatar row shows up to 8 then "+N", tabs link out
  to full views) — the lead section is a teaser, not a replacement for the Events/Stream tab;
  (b) 3 items fit on one line each without scrolling on a 600px-wide mobile view (existing
  breakpoint in `group-page.css`); (c) keeps the query cheap (three storage queries capped at
  `range(0, 3)`, mirroring the `$avatar_limit = 8` capped-loop pattern already in the
  preprocess). N is a named constant in the preprocess (`const LEAD_ITEM_LIMIT = 3`) so F can
  tune it in one place if A disagrees.
- **Item structure — minimal:**
  ```
  <li class="gc-group-lead__item">
    <a href="{{ item.url }}" class="gc-group-lead__link">{{ item.title }}</a>
    {% if item.meta %}<span class="gc-group-lead__meta">{{ item.meta }}</span>{% endif %}
  </li>
  ```
  `title` = node label, linked to the node's canonical URL (no new route). `meta` is optional,
  one line: events show the formatted date (reuses the `date_formatter` service already
  injected for `last_activity_display`); discussion/docs show a relative "N days/weeks ago"
  string (reuses `formatTimeDiffSince`, already used at theme.php lines ~465–470). No body
  excerpt, no author, no thumbnail — deliberately minimal per the "low-fi, not a feed" brief.
- **Empty-state behavior — decision: hide the whole section.** If the group's leading content
  type has zero qualifying nodes (e.g. an Event-planning group with no event nodes yet), do
  **not** render `.gc-group-lead` at all — same as the `null` fallback. Rationale: an empty
  "Upcoming events" box with only a "See all events →" link duplicates the Events tab with no
  new information and no clear next action (the brief gives no prerequisite-setup flow to
  point at, unlike a typical empty-state pattern). `leading_section` stays `'events'` /
  `'discussion'` / `'docs'` (so the theme suggestion still applies and future content
  populates the section automatically), but a new preprocess flag,
  `gc_group.lead_items` (empty array when nothing qualifies), gates the twig's render — the
  twig checks `gc_group.lead_items is not empty`, not just `leading_section`.
- **"See all" link target:** the existing per-group view path for that content, reusing the
  same tab URLs already built in the preprocess (`/group/{id}/events` for events).
  Discussion and docs have no dedicated tab today, so their "See all" link points at the
  existing `/group/{id}/stream` view filtered by content type if a query-string filter already
  exists in `group_nodes`/`hot_content`, **else** (simpler, no new route) at the plain
  `/group/{id}/stream` (unfiltered) with link text "See all discussions in the stream →" /
  "See all documentation in the stream →" — A should confirm whether `group_nodes` already
  exposes a type-filtered path; if not, ship the unfiltered stream link rather than inventing
  a route. This is flagged under Open Questions.

---

## 3. Tooltip on section header

- **Trigger element:** a `ⓘ` (U+24D8 CIRCLED LATIN SMALL LETTER I) `<span>` immediately after
  the `<h2>` text, exactly mirroring the existing `GroupTypeContentHelp::infoTrigger()`
  pattern (`do_chrome` module) — not a new icon, not new markup shape:
  ```html
  <h2 id="gc-group-lead-heading" class="gc-group-lead__heading">
    {{ gc_group.lead_label }}
    <span class="do-chrome-info gc-group-lead__help"
          tabindex="0"
          role="note"
          aria-label="{{ tooltip_copy }}"
          data-do-tooltip="{{ tooltip_copy }}">ⓘ</span>
  </h2>
  ```
  Focusable (`tabindex="0"`), has an accessible name via `aria-label` (screen readers announce
  the full copy even before/without JS), and reuses the `do-chrome-info` class already styled
  for contrast + focus ring in `do_chrome.css` (verify AA contrast carries over — same class,
  same theme, so it should; F should spot-check against `.gc-group-lead`'s background, which
  is the plain page background, same as the form contexts `do-chrome-info` already ships in).
- **Data attribute wiring:** `data-do-tooltip="{{ tooltip_copy }}"` — the exact existing
  pattern (`AudienceTooltip`, `GroupTypeContentHelp`). `do_chrome/tooltips` JS
  (`do_chrome.tooltips.js`) is **already attached globally** by
  `DoChromeHooks::pageAttachments()`, so `groups_chrome` does **not** need to attach that
  library itself — only render the attribute. `tooltip_copy` comes from
  `\Drupal\do_chrome\HelpText::get('group_type.homepage_adapts')`, called from
  `groups_chrome_preprocess_group()` (theme calling a module service class is already the
  established cross-boundary pattern — `HelpText` is a static-method value class, no DI
  needed).
- **Copy (append-only key, `group_type.homepage_adapts`):** *"This page adapts to the group's
  type — it leads with events, discussion, or documentation depending on how the group is
  categorised."* (Improves the brief's placeholder by naming the three concrete variants, so
  a first-time reader immediately understands what "adapts" means without hunting for it —
  consistent with the descriptive, non-vague style of every other `HelpText` entry.)

---

## 4. Template strategy — DECISION: (b) one base twig + `{% if leading_section %}` block

**Chosen: option (b).** A single `group--full.html.twig` gains one new conditional block
(`{% if gc_group.lead_items is not empty %}` — see §2); the three suggestion-target twigs
(`group--community_group--events-first.html.twig`,
`group--community_group--discussion-first.html.twig`,
`group--community_group--docs-first.html.twig`) are **near-empty**, each just
`{% include '@groups_chrome/group/group--full.html.twig' %}` (or Drupal's native
`{{ include() }}`/inheritance via `{% extends %}` — F picks whichever the theme's existing
include patterns favor; no other twig in this theme currently extends another, so a direct
`{% include %}` with the parent's variables passed through is the safer/more-explicit choice).

**Why (b) over (a):** the three variants differ ONLY in `gc_group.leading_section` /
`lead_label` / `lead_items` values — all already computed by the preprocess, not by the
template. There is no per-variant markup difference (same `<section>`, same heading + ⓘ,
same `<ul>` of items) — only the *content* differs. Three full duplicate twigs (option a)
would triplicate ~80 lines of identical header/tabs markup for zero markup variation, directly
violating the "reuse, don't reinvent" instruction and creating three places a future header
tweak must be repeated. Option (b) keeps `group--full.html.twig` the single source of markup
truth; the three suggestion twigs exist ONLY to satisfy the story's explicit ask that "the
theme-suggestion mechanism exists" (AC: `theme_suggestions_group_alter()` must return
`group__community_group__{x}_first` and Drupal must find a matching template file for that
suggestion to actually apply — if no file existed, Drupal would silently fall back to
`group--community-group.html.twig`/`group.html.twig`, defeating the mechanism). This is
explicitly allowed by the brief's own hedge ("D may decide a single template + variant flag is
cleaner — the story just requires the suggestion mechanism exists").

**For A/T/F:** the three suggestion twigs are trivial passthroughs; all real assertions
(lead section content per exemplar, fallback silence) target markup produced by
`group--full.html.twig` regardless of which template name Drupal resolved to. T should assert
against rendered DOM/classes (`.gc-group-lead`), not against which twig filename fired.

---

## 5. CSS approach

- New file: `web/themes/custom/groups_chrome/css/group-type-homepage.css`.
- New library entry in `groups_chrome.libraries.yml`:
  ```yaml
  # Group-type homepage lead section (#122). Attached ONLY when
  # groups_chrome_preprocess_group() sets gc_group.lead_items (non-empty) — an
  # unmapped/untyped group attaches nothing new, so the fallback page's CSS
  # payload is byte-identical to before this story.
  group_type_homepage:
    version: 1.x
    css:
      theme:
        css/group-type-homepage.css: {}
    dependencies:
      - groups_chrome/global
  ```
- Attachment: in `groups_chrome_preprocess_group()`, attach
  `groups_chrome/group_type_homepage` **inside** the same conditional that populates
  `gc_group.lead_items` (i.e., only when the array ends up non-empty) — NOT unconditionally
  alongside the existing `groups_chrome/group_page` attach at the top of the function. This
  is the AC-critical wiring: verify by asserting `drupalSettings`/rendered `<link>`/`<style>`
  markup contains no reference to `group-type-homepage.css` on the fallback page.
- Selectors: all scoped under `.gc-group-lead` (BEM-flavored, matching the existing
  `.gc-group-header__*` / `.gc-avatar__*` convention):
  `.gc-group-lead`, `.gc-group-lead__heading`, `.gc-group-lead__help` (the ⓘ — thin
  override of `do-chrome-info` spacing only, no color/contrast override),
  `.gc-group-lead__list`, `.gc-group-lead__item`, `.gc-group-lead__link`,
  `.gc-group-lead__meta`, `.gc-group-lead__see-all`. All values pulled from `tokens.css`
  (`--gc-space-*`, `--gc-color-*`, `--gc-font-*`) — no hard-coded colors/spacing, matching
  every other component file in this theme.

---

## 6. WCAG 2.2 AA notes

- **Focus order:** header (badges, avatars, join/leave button) → **lead section** (ⓘ trigger,
  then item links, then "See all" link) → tab bar → body content. This is the natural reading
  order (top-to-bottom, matching visual layout) and is **not** confusing: the lead section sits
  where a sighted user's eye lands first below the header, so keyboard focus order matches
  visual order — no `tabindex` overrides needed anywhere except the ⓘ's own `tabindex="0"`
  (required because a bare `<span>` isn't natively focusable).
- **Contrast:** heading text uses `--gc-color-text` (`#1b2733`) on `--gc-color-bg` (`#ffffff`)
  — same as all other body/heading text on this page, already AA (>13:1). The ⓘ trigger
  reuses `do-chrome-info`'s existing styling (already shipped, already used in the group
  add/edit form context) — F must spot-check computed contrast in this new surface's actual
  background (plain white page background here vs. a form-row background there) rather than
  assume, per the "render and look" discipline; flagged as an open question below since I have
  not rendered this myself against `do_chrome.css`'s exact color values.
- **Keyboard operability:** every item title is a plain `<a href>` (native keyboard/Enter
  activation, no JS-dependent click handlers) — same as the existing tab-bar links and avatar
  titles. "See all" is likewise a plain `<a href>`. No custom widgets, no `role="button"` on a
  non-native element.
- **Tooltip trigger accessible name:** `aria-label="{{ tooltip_copy }}"` on the ⓘ span
  (screen readers get the full sentence on focus, independent of whether tippy.js has
  initialized) plus `data-do-tooltip` for the visual hover/focus popup — this is the exact
  dual-channel pattern `GroupTypeContentHelp::infoTrigger()` already uses (`role="note"` +
  `aria-label` + `data-do-tooltip`), so no new accessibility pattern is introduced.

---

## 7. Explicit fallback contract

When `gc_group.leading_section` is `null` (Geographical, Archive, or `field_group_type`
unset) — **or** when it is set but `gc_group.lead_items` ends up empty (§2 empty-state
decision) — the following MUST hold, unchanged from pre-#122 behavior:

- **No new DOM:** no `.gc-group-lead` element (or any element with that class prefix) exists
  anywhere in the rendered `<article class="gc-group-page">`.
- **No new library:** the response's aggregated CSS/JS does not reference
  `group-type-homepage.css` or the `groups_chrome/group_type_homepage` library.
- **`gc_group.tabs` unchanged:** same four tabs, same order (`stream, events, members,
  about`), same `active`/`aria-current` logic as today — the AC explicitly calls this out.
- **No HelpText/tooltip markup:** no `data-do-tooltip` attribute referencing
  `group_type.homepage_adapts` renders anywhere on a fallback page.

**E2E DOM-diff assertion (for T):**
```ts
// On an unmapped-type group page (e.g. seed a Geographical-typed group, or use
// an existing one from step_720_group_types.php):
await expect(page.locator('.gc-group-page .gc-group-lead')).toHaveCount(0);
await expect(page.locator('link[href*="group-type-homepage"]')).toHaveCount(0);
// Tab bar text + order assertion, unchanged from any pre-#122 baseline spec:
await expect(page.locator('.gc-group-tabs__link')).toHaveText(
  ['Stream', 'Events', 'Members', 'About']
);
```

---

## Open questions for approval

1. **"See all" target for discussion/docs:** confirmed no dedicated `/group/{id}/discussion`
   or `/group/{id}/documentation` route/tab exists today (brief forbids new routes). Proposed:
   link to the unfiltered `/group/{id}/stream` with descriptive link text, unless A finds an
   existing type-filtered path in `group_nodes`/`hot_content` views config — A should verify
   and confirm before F builds this link.
2. **ⓘ trigger contrast in this new background context:** I have not rendered
   `.gc-group-lead__help` against the live page to visually confirm AA contrast holds (the
   `do-chrome-info` class is reused, not redesigned, but its prior use context was a form row,
   not this page's plain background) — F/A should spot-check with the actual rendered page
   before considering the AA AC satisfied.
3. **Top N = 3:** stated as a firm recommendation, not an open question, but flagged here in
   case the operator wants a different number (2 or 4 are the only other numbers considered
   and rejected — 2 felt thin for "docs-first," 4+ risks wrapping on the 600px breakpoint).

No other state is ambiguous; the four states + fallback are fully specified above.
