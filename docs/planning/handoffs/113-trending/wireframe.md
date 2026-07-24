# Wireframe — #113 ST-4 Trending surface (`/trending`)

**Mode:** (a) generated low-fi (ASCII/markdown — page is a plain Views page; no novel
interaction surface, so a hi-fi rendering tool adds no signal here).

`/trending` is a **plain Views page**, NOT a `do_streams_shell` consumer (per
`do_streams/README.md` "Shell contract" — the shell's own `trending` *tab* is a
different, unrelated consumer of the same ranking value; this story's route does not
attach to the shell at all). That constrains this wireframe: no scope tabs, no ranking
pill, no shell chrome beyond the site's normal header/nav.

---

## 1. Page skeleton (all states)

```
┌────────────────────────────────────────────────────────────────┐
│ [ site header / primary nav — inherited, unchanged by ST-4 ]   │
├────────────────────────────────────────────────────────────────┤
│                                                                  │
│  <h1>Trending</h1>          <-- Views page title, from          │
│                                  display_options.title:'Trending'│
│                                  ONE H1 on the page (WCAG)       │
│                                                                  │
│  [ exposed filter: Content type ▾ ]   <-- inherited from         │
│                                            hot_content.yml       │
│                                            filter, optional      │
│                                                                  │
│  ── stream_card rows (see §2) ──────────────────────────────    │
│                                                                  │
│  [ pager: « Prev  1 2 3 ... Next » ]  <-- Views "full" pager,    │
│                                            h4 heading level,     │
│                                            10 items/page,        │
│                                            keyboard-focusable    │
│                                            links (not divs)      │
│                                                                  │
│ [ site footer — inherited, unchanged ]                          │
└────────────────────────────────────────────────────────────────┘
```

- Header/nav/footer: 100% inherited site chrome. ST-4 does not touch nav (issue
  explicitly out of scope — "No nav-menu entry").
- `<h1>`: rendered by the Views page title mechanism ("Trending"). This is the page's
  ONLY h1 — stream_card's internal node titles render as `<h3>`/`<h4>` (existing
  convention, confirmed by `following_feed`'s use of the same row/view_mode pair —
  not re-litigated here).
- No scope tabs, no "Recent / Hot" ranking pill — see §5.

---

## 2. Stream card row (many-state) — inherited, not redesigned

```
┌──────────────────────────────────────────────────────────────┐
│ [stream-card-wrapper]                                          │
│  ┌────────────────────────────────────────────────────────┐   │
│  │ Venue Logistics Thread                     [forum]      │   │
│  │ posted by @author · 3 days ago                          │   │
│  │ 2 comments                                               │   │
│  └────────────────────────────────────────────────────────┘   │
│ [stream-card-wrapper]                                          │
│  ┌────────────────────────────────────────────────────────┐   │
│  │ Patch Review Process RFC                   [forum]      │   │
│  │ posted by @author · 2 days ago                          │   │
│  │ 2 comments                                               │   │
│  └────────────────────────────────────────────────────────┘   │
│ [stream-card-wrapper]  ... (up to 10 per page, score DESC,     │
│                              created DESC tiebreak)             │
└──────────────────────────────────────────────────────────────┘
```

- Card markup/visuals: 100% inherited from the `stream_card` node view mode +
  `groups_chrome/css/stream.css`, exactly as `following_feed` and (structurally)
  `hot_content` already render. **This wireframe does not redesign the card.**
  `trending.css` adds ONLY container/empty-state spacing (mirrors `following.css`
  pattern — see brief Step 2).
- Sort is invisible chrome (score DESC, created DESC) — no "sorted by hot score"
  label is shown to the user; ordering is implicit, matching `hot_content`'s existing
  convention (no on-card badge or numeric score exposed to end users on `/trending`,
  unlike `hot_content.yml`'s admin-facing `score` field — that field is NOT carried
  into `stream_card`/trending's field set, so nothing here is color-only or
  score-only status; see §6).
- Ordering credibility (from survey/brief): "Venue Logistics Thread" and "Patch
  Review Process RFC" (score 6.0 each, post-cron) sit at/near the top, ahead of
  0-comment nodes (score 0.0).

## 2a. Empty state

```
┌──────────────────────────────────────────────────────────────┐
│                                                                  │
│                 Nothing trending yet.                          │
│                                                                  │
│              [ .gc-empty container, centered,                  │
│                padding: 2rem 1rem, per trending.css ]          │
│                                                                  │
└──────────────────────────────────────────────────────────────┘
```

- Renders when the view has zero rows — pre-cron on a fresh install, or (edge case)
  a database with no published nodes of the exposed content types.
- Copy: **`"Nothing trending yet."`** — verbatim from the issue body, placed as the
  view's own `empty:area_text_custom` (this view is not shell-driven, so the shell's
  own empty-copy for its `trending` tab, `"Nothing is trending right now. Check back
  soon."`, is never reachable from this route — see §6 discrepancy note below).
- No CTA link/button in this empty state (unlike `following_feed`'s empty state,
  which links to `/stream` because following requires a user action to populate).
  Trending has no equivalent "go do something" action — it becomes non-empty purely
  via cron + site activity. A bare message is truthful; do not invent a CTA.
- Markup: `<div class="gc-empty"><p class="gc-empty__title">Nothing trending
  yet.</p></div>` (single `<p>`, no secondary `<p class="gc-empty__text">` — no
  supporting sentence is warranted here, unlike following's two-line empty state).

---

## 3. One-row state

Same card layout as §2 with a single `stream-card-wrapper` rendered; pager area
either absent (Views omits the pager chrome when `pager count <= items_per_page` and
there's only 1 page) or shows disabled/inactive Prev/Next — standard Views "full"
pager behavior, unmodified.

## 4. Error state

No custom error state is introduced by this view. A database/query failure is a
platform-level 500 (Drupal's standard exception page), out of this story's scope —
no bespoke "something went wrong" card is being designed here, and none is implied by
the acceptance criteria.

---

## 5. Anonymous vs authenticated — MUST be identical

```
Anonymous:                          Authenticated:
┌───────────────────┐               ┌───────────────────┐
│ <h1>Trending</h1>  │               │ <h1>Trending</h1>  │
│ [same cards]       │      ==       │ [same cards]       │
│ [same pager]       │               │ [same pager]       │
└───────────────────┘               └───────────────────┘
```

- `access.type: none` (public, per brief Step 1) — there is no role gate, no
  per-viewer personalization, no "sign in to see more" interstitial. The exposed
  content-type filter, sort, and pager behave identically regardless of
  authentication state. This is a deliberate contrast with `following_feed`
  (`access: role: authenticated` + per-user scope filter) — trending has NO
  per-user scope, so there is nothing that could legitimately differ between an
  anonymous and an authenticated visitor.
- Confirms brief's `access.type: none` decision is wireframe-consistent: nothing in
  this design depends on `$user`.

---

## 6. Ranking indicator — single-rank, no pill (justification)

**No "Recent / Hot" ranking-control pill is shown on `/trending`.** This is
intentional, not an omission:

- The shell's `ranking_control` construct (`README.md` §"Shell contract") exists
  because shell-driven views (My Feed, Following, Global — the shell's OWN
  `?scope=` tabs) let ONE user toggle ranking across MULTIPLE scopes via a shared
  contextual argument (`$view->args[0]`).
- `/trending` is architecturally the opposite: it is a **standalone, single-rank
  view** — score DESC is baked into its `sorts:` block at config time (cloned from
  `hot_content.yml`), not driven by a contextual argument. There is no second
  ranking to toggle to; showing a pill with one permanently-active, non-clickable
  option would be a disabled/dead control — worse UX than omitting it, and contrary
  to WCAG's "don't render inert interactive-looking chrome."
- The shell's own `trending` scope-TAB (a different code path entirely — see
  README "Trending (a shell tab, not a ranking value)") is unrelated to this route;
  this story does not touch or attach to the shell, per the brief's explicit
  non-scope ("No changes to the do_streams shell").
- **Challenge captured, not silently resolved:** if a future story unifies
  `/trending` into the shell (making it shell-driven with a live ranking toggle),
  this no-pill design would need revisiting. For THIS story's scope (plain Views
  page, single fixed sort), no pill is correct. Flagged under open questions below
  for O/human confirmation, since it's a plausible point of confusion between "the
  shell's trending tab" and "this story's `/trending` route."

---

## 7. WCAG 2.2 AA notes

- **Exactly one H1** — the Views page title ("Trending"). No secondary h1 anywhere
  in stream_card rows, pager, or empty state (all use h3/h4/p per existing
  conventions already in place for `following_feed`/`hot_content`).
- **Focus order** — natural DOM order: header/nav → h1 → exposed filter (if
  rendered) → card list (each card's title is a real `<a href>`, keyboard-reachable,
  inherited from `stream_card`) → pager links (`Prev`/`Next`/page numbers are real
  `<a>` elements per Views' default pager plugin, not `<div onclick>`) → footer. No
  custom tabindex or skip-link changes introduced by this story.
- **No color-only status** — nothing on this page conveys meaning by color alone.
  The empty state is a plain text sentence (no color-coded banner). Cards do not
  surface a colored "hot" badge or heat indicator; ranking is invisible ordering
  only (§6), so there is no color-coded rank chip that could fail a color-only
  check. If a future iteration adds a visible "trending" badge, it must pair color
  with a text/icon, not rely on hue alone — noted here so F doesn't introduce one
  unreviewed.
- **Pager heading level** — `pagination_heading_level: h4` (cloned from
  `following_feed`/`hot_content`), consistent with the page's h1 → (no h2/h3 spine
  needed since page has no sections) → h4 pager label. Acceptable; matches existing
  site pattern, not a new hierarchy problem introduced here.

---

## D-gate self-review

**Ambiguity for A to resolve:**
1. **Empty-copy discrepancy** (surfaced in brief/survey, restated here for the
   record): the shell's `trending`-scope tab uses `"Nothing is trending right now.
   Check back soon."` (`DoStreamsHooks.php:499`), while this story's standalone
   `/trending` view uses the issue-body string `"Nothing trending yet."` verbatim.
   These are two different strings on two different code paths (shell tab vs.
   standalone view) that a user could plausibly land on by two different routes if
   the shell ever also exposes a `?scope=trending` URL. Recommendation (unchanged
   from brief): keep them distinct — the view's own empty area is the one that
   actually renders at `/trending`; the shell string is unreachable from this route
   today. Flag for A; if A wants ONE canonical string across both, that's a
   `do_streams` shell change, which is explicitly out of this story's scope.
2. **No ranking pill is a design decision, not an oversight** (§6) — flagged in case
   a human reviewer expects parity with the shell's ranking-pill visual language.
   Recommendation stands: omit it, this view has no second ranking to toggle.

**Drift from the issue text:** none identified. The issue's acceptance bullets
("Renders correctly... commented threads outrank zero-comment nodes... empty state
renders pre-cron... hot_content.yml unmodified... Playwright spec... HelpText...
WCAG 2.2 AA") are all represented above.

**Copy discrepancies with existing infra:** covered in ambiguity #1 above — this is
the one place copy differs across surfaces; captured for O/A, not silently resolved.

**States covered:** empty (§2a), one (§3), many (§2), error (§4, deliberately
out-of-scope/platform-default). Anonymous vs authenticated shown identical (§5).
