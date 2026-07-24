# Handoff-D: Phase 2 - Design (/my-feed wireframe, issue #110)

**Date:** 2026-07-23
**Branch:** 110-stream-110
**Mode:** (a) generated low-fi
**Wireframe:** `docs/planning/handoffs/110-stream-110/wireframe.html`

## Screens & states covered

1. **State 1 of 4 — Populated** (Elena Garcia, `/my-feed`, My Feed scope + Recent ranking
   active). Shell chrome inherited verbatim from #109 (`.shell.do-streams-shell`,
   `nav.shell-tabs`, `.shell-ranking`, `.shell-results`). 5 `stream_card`s shown — a pinned
   "Sprint Planning: Portland 2026" leading, then mixed event/forum/post/page cards from
   Elena's other 4 groups (Core Committers, Leadership Council, Camp Organizers EMEA, Drupal
   France) — plus a pager (illustrating AC-7, >10 total results). No card from
   Thunder Distribution / Drupal Deutschland (AC-9 negative case, annotated).
2. **State 2 of 4 — Empty** (authenticated user, 0 group memberships). Same shell chrome,
   `.gc-empty[data-testid="do-streams-shell-empty"]` block with the existing truthful copy
   ("You haven't joined any groups yet. Join a group to see its content here.") plus the
   **new** `empty_cta` slot: a button-shaped link `data-testid="do-streams-shell-empty-cta"`,
   href `/all-groups`, label "→ Browse all groups". Disabled/absent framing: N/A — this CTA is
   always present when the empty state renders (no partial-disabled variant needed for MVP).
3. **State 3 of 4 — Anonymous** (annotation only, no visual). `GET /my-feed` unauthenticated
   never reaches a rendered shell — route `_role: authenticated` yields a 403/login-redirect
   before any Twig renders. Documented as intentionally blank per the brief's own framing
   ("N/A — anonymous gets 403").
4. **State 4 of 4 — Nav-link strip.** Two side-by-side mockups: authenticated main-menu strip
   (Groups | Activity | **My Feed** | My Groups | Create Group, new link highlighted only in
   this document for clarity — production styling is identical to the other 4 links) and
   anonymous strip (My Feed link fully absent from the DOM, not disabled/greyed).

## Existing components/patterns reused

- Shell chrome CSS/markup/classes/`data-testid`s: **byte-for-byte inherited** from the approved
  `docs/handoffs/109-do-streams-scaffold/wireframe.html` (design tokens, `.shell-tabs__item`,
  `.shell-ranking__btn`, `.gc-empty`, `.card` shapes). Not redesigned.
- `.gc-empty` empty-state block: reused exactly as #109 shipped it; only addition is the new
  `.gc-empty__cta-link` slot rendered conditionally inside it.
- Nav-strip visual (`.nav-strip`, `.nav-strip__link`) is new-but-minimal chrome scaffolding for
  this document only — it mocks the existing `groups_chrome_main_menu` block's rendered output,
  it does not introduce a new production component.

## Rendering verification

Rendered headlessly via Edge (`msedge --headless --screenshot`) and inspected the full-page
capture plus targeted crops of: the populated-state pinned card (pushpin glyph renders intact,
centered), the empty-state CTA button (arrow glyph `→` renders correctly, button is centered
and fully on-canvas with visible padding), and the nav-strip states. No hand-authored SVG paths
were used anywhere — icons are Unicode glyphs (`→`, the CSS `content: "\1F4CC"` pushpin
already established by #109) or plain text/boxed labels. All div/article tags balance
(30/30, 5/5) confirming no broken markup.

## Open questions for approval

Both resolved with a recommendation, per the brief's own instruction to proceed rather than
block:

- **Q-D1** (CTA placement): block-level link below the empty-state body copy, styled as a
  filled button-shaped link. Adopted — see State 2.
- **Q-D2** (nav weight): weight **1.5**, between Activity (1) and My Groups (2). Adopted — see
  State 4. Rationale: Activity/My Feed read as a natural related pair; existing links'
  weights (0/1/2/3) are untouched, no renumbering needed.

No other open questions — the wireframe is otherwise unambiguous and ready for operator
approval.

## For T (selectors to assert against)

- `[data-testid="do-streams-shell"]` — shell root, present on `GET /my-feed` (200, authenticated).
- `[data-testid="do-streams-shell-tab"][data-scope-id="my_feed"]` — has class `is-active` and
  `aria-current="true"` (AC-3).
- `[data-testid="do-streams-shell-ranking-pill"][data-ranking-id="recent"]` — has class
  `is-active` and `aria-pressed="true"` (AC-4).
- `[data-testid="do-streams-shell-results"]` — contains rendered `stream_card` nodes; assert
  "Sprint Planning: Portland 2026" text present and leading; assert no Thunder Distribution /
  Drupal Deutschland titles present (AC-5, AC-9).
- Pager: assert Drupal core pager markup present when result count > 10 (AC-7) — not
  shell-owned, standard core theme.
- `[data-testid="do-streams-shell-empty"]` — present for a 0-group user; text contains "Join a
  group to see its content here." (AC-6).
- `[data-testid="do-streams-shell-empty-cta"]` — present inside the empty block, `href="/all-groups"`
  (or resolves to that path), visible (not `display:none`), focus-visible outline present
  (AC-12 keyboard/focus check).
- Nav: assert a link with accessible name "My Feed" and `href` resolving to `/my-feed` exists in
  the main menu for an authenticated user (AC-8); assert it is ABSENT (not just hidden) from the
  DOM for an anonymous user.
- Anonymous `GET /my-feed`: assert HTTP 403 or a redirect to the login form (AC-1) — no DOM
  assertions needed for this state.
- axe-core: run against the populated and empty states of `/my-feed` (AC-12).

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>"]
