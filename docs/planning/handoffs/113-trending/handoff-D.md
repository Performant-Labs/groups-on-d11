# Handoff-D: Phase 2 - Trending surface (/trending)

**Date:** 2026-07-23
**Branch:** 113-trending
**Mode:** (a) generated low-fi
**Wireframe:** `docs/planning/handoffs/113-trending/wireframe.md`

## Screens & states covered
- **Page skeleton** — inherited site chrome (header/nav/footer unchanged); one
  `<h1>Trending</h1>` from the Views page title; optional inherited exposed
  content-type filter; pager (Views "full", h4 heading level, 10/page).
- **Many-state (card list)** — `stream_card` rows, inherited verbatim from
  `following_feed`/`hot_content` conventions, no redesign. Ordered by hot score
  DESC / created DESC, invisibly (no on-card score badge or "trending" chip).
- **Empty state** — `"Nothing trending yet."` (issue-body copy verbatim), single
  `<p class="gc-empty__title">` in `.gc-empty`, no CTA (unlike following's
  two-line + link empty state — trending has no equivalent "go do this" action).
- **One-row state** — same card layout, pager omitted/inactive per standard Views
  behavior.
- **Error state** — none designed; platform-default 500, out of scope.
- **Anonymous vs authenticated** — confirmed identical; no role gate, no
  per-viewer personalization (`access.type: none`).
- **Ranking indicator** — deliberately absent; single fixed sort, no toggle to
  show. Justified against the do_streams shell contract (this route is not a
  shell consumer).

## Existing components/patterns reused
- `views.view.following_feed.yml` — page display shape, `stream_card` row,
  `use_ajax`, `css_class`, empty `area_text_custom` structure.
- `views.view.hot_content.yml` — `score` DESC / `created` DESC sort block.
- `do_streams/css/following.css` + `do_streams.libraries.yml` — CSS scoping and
  library-declaration pattern, cloned for `trending.css` / `trending:` block.
- `do_streams/README.md` "Shell contract" — grounds the no-shell-chrome, no-pill
  decision.

## Open questions for approval
1. **Empty-copy discrepancy**: the do_streams shell's own `trending`-scope tab
   uses a different string ("Nothing is trending right now. Check back soon.")
   than this story's standalone view ("Nothing trending yet."). Two different
   code paths today (shell tab vs. plain view), so no fix proposed, but flagged
   for A/human awareness — see wireframe.md's D-gate self-review §1.
2. **No ranking-control pill** on `/trending` is a design decision (this view has
   only one fixed sort), not an oversight — flagged in case a reviewer expects
   visual parity with the shell's Recent/Hot pill. See wireframe.md §6.

## Approval
[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]
