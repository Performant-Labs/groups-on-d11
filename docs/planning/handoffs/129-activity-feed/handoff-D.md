# Handoff-D: Phase 2 - Activity feed rendering (#129)

**Date:** 2026-07-23
**Branch:** 129-activity-feed-render
**Mode:** (a) generated low-fi
**Wireframe:** `docs/planning/handoffs/129-activity-feed/wireframe.html`

## Screens & states covered

Single route surface, two states, both rendered as static HTML sections in the one file
(matches the #109 precedent of one multi-state file over several small files, for legibility):

1. **State 1 — `/activity`, My Groups scope, populated (persona Elena).** All six row shapes the
   brief requires appear interleaved in one feed, top to bottom:
   - `activity-row-content` — content card: "Posted by Alex Novak in Camp Organizers EMEA · 2h
     ago" meta strip wrapping a black-boxed `stream_card` node render (title + snippet).
   - `activity-row-social` (join) — "Alex Novak joined Camp Organizers EMEA · 3h ago".
   - `activity-row-social` (RSVP) — "Alex Novak RSVP'd to DrupalCon Portland Keynote · 4h ago".
   - `activity-row-social` (comment) — "Alex Novak commented on 'Wi-Fi feedback' · 5h ago" plus a
     truncated italic quote line: "Room C has poor Wi-Fi coverage…" (illustrates the ≤180-char,
     tags-stripped snippet acceptance criterion).
   - `activity-row-aggregated` — native `<details>/<summary>`: "Maria Chen posted 3 topics in
     Drupal Enthusiasts ▸", expanding to 3 linked topic titles. The count ("3 topics") is textual,
     not color-only, per the non-color-status NFR.
   - `activity-row-social` (group-created) — "Sam Rivera created group Drupal Bay Area · 1d ago".
   - Truthful copy: every row states actor, action, target, and relative time; nothing is a
     placeholder.
2. **State 2 — Empty state (viewer in no groups, or a zero-activity group scope).** Reuses the
   existing `.gc-empty` pattern from the do_streams shell wireframe (#109) rather than inventing a
   new one. Copy: "You're not a member of any group yet, so there's no activity to show. Join a
   group to see posts, comments, and updates from its members here." — names the concrete
   prerequisite (join a group) per acceptance criterion 3, rather than a generic "no results."

The group-scope variant (`/activity/group/<id>`) is **not drawn as a third state** — it is the
identical row markup filtered server-side to one group, called out as a linked note under the
scope-tab strip in both states ("Group-scope variant: view this group's activity only"). Per the
task instructions this was an explicit call, not an oversight.

"Trending" appears as a second (inert) tab in the scope-tab strip, matching the do_streams shell's
visual convention, but is explicitly annotated in the legend as **illustrative of future ST-8
mounting only** — this story's scope is `my_groups` alone; there is no working Trending control
here. Flagged under Open questions below in case the operator wants it omitted rather than shown
inert.

## Existing components/patterns reused

- **Design tokens, `.shell`, `.card`, `.gc-empty`** — copied verbatim (same CSS variable names and
  values) from the approved `docs/handoffs/109-do-streams-scaffold/wireframe.html`, so this surface
  reads as the same visual family as the existing stream shell rather than a new one.
- **Scope-tab strip** (`.shell-tabs` / `.shell-tabs__item.is-active`) — same markup/CSS shape as
  the do_streams shell's scope tabs (`aria-current="true"` on the active tab, `aria-label` on the
  `<nav>`).
- **`stream_card`-shaped content row** — the content-card row's interior (title, snippet) is
  black-boxed exactly as #109's wireframe treats it: "not a redesign," owned by the real
  `node--stream-card.html.twig` / `groups_chrome_preprocess_node()`. Only the new `card__meta`
  strip ("Posted by … in … · time") is this story's own addition, per the brief's
  `activity-row--content.html.twig` spec.
- **`.gc-empty` / `.gc-empty__title` / `.gc-empty__text`** — reused unchanged for the empty state.

## New patterns introduced (net-new, no existing analog)

- **Social row** (`.activity-row--social`, `.row__sentence`, `.row__snippet-quote`) — no existing
  compact one-line activity-sentence component exists in the codebase; built from the same tokens
  (font sizes, muted-text color, spacing scale) as the reused card component so it reads as the
  same design system, but the shape itself (avatar + sentence + trailing timestamp, optionally a
  quoted snippet line) is new, as the brief specifies.
- **Aggregated row** (`.activity-row--aggregated`, native `<details>/<summary>`) — no existing
  disclosure pattern in this codebase; uses the browser's native semantics for keyboard operability
  rather than a custom expand/collapse control. The disclosure glyph is the Unicode character `▸`
  (rotated via CSS `transform: rotate(90deg)` on `[open]`), not hand-drawn SVG geometry, per the
  icon-glyph rule.

## Accessibility notes (WCAG 2.2 AA)

- Contrast: darkened `--gc-color-text-muted` from #109's `#5c6b7a` to `#4c5a68` — the original
  value does not clear 4.5:1 against white for the meta/timestamp text at this font size; the
  darkened value does (verified informally against the small-text AA threshold; F/U should confirm
  with a contrast checker against the final production palette, since this is a low-fi
  approximation of `groups_chrome`'s real tokens, not the tokens themselves).
- Focus: every interactive element (`<a>`, `<summary>`) gets an explicit `:focus-visible` outline
  (`2px solid var(--gc-color-primary-700)`, 2px offset) matching the `do_group_membership`
  precedent (`manage-members.css`'s `:focus-visible` rule), since browser UA defaults are not
  reliably visible across this codebase's supported browsers per that module's own docblock.
- Keyboard operability: the aggregated row's disclosure is native `<details>/<summary>` — no
  custom JS, Tab-focusable, Enter/Space toggles by default browser behavior.
- Non-color status: the aggregated count ("3 topics") is text inside the `<summary>`, not a
  color-only badge or icon.
- Semantic list: the feed is an ordered list (`<ol class="activity-feed__list">`) so screen readers
  announce row count/position; each row is an `<li>`.
- `aria-hidden="true"` on the decorative avatar-initial circles (they are not meaningful
  alternative content; the actor name is already present as text in the row).

## Open questions for approval

1. **Should the "Trending" tab appear at all in this story's UI**, given it has no working scope
   behind it yet (ST-8/#130 territory)? I drew it inert + annotated, matching the shell's visual
   family, but omitting it entirely (single-tab strip: "My Groups" only) is equally defensible for
   a POC-scoped story. Operator's call.
2. **Group-scope variant's empty-state copy** is not separately mocked — State 2's copy ("join a
   group") is specific to the no-group-membership case for `/activity`. A viewer IN a group with
   zero activity in it (visiting `/activity/group/<gid>`) needs different copy ("No activity in
   this group yet" — nothing to join, they're already in it). The brief doesn't specify per-scope
   empty copy for this story (unlike #109, which had an explicit per-scope-copy resolution). Flag
   for F to branch on scope when supplying `empty_copy`, or for operator to rule now.

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]
