# Wireframe — #114 ST-5 Profile activity stream on `/user/{uid}`

**Mode:** (b) no user-supplied wireframe — ASCII, low-fi, D-authored.
**Scope of new UI:** ONE block ("Recent posts") inserted into the existing
`/user/{uid}` profile page, below the existing contribution-stats block.
Nothing else on the page is redesigned.

## Screen: `/user/{uid}` — Maria Chen's profile (MANY state, the demo case)

```
┌──────────────────────────────────────────────────────────────────┐
│  /user/42                                                    ⚑   │
├──────────────────────────────────────────────────────────────────┤
│  [avatar]  Maria Chen                                             │
│            Member since Jan 2025                                  │
│                                                                    │
│  ── Contribution stats ────────────────────────────────────────   │  <- existing block, unchanged
│    Posts: 12    Comments: 34    Groups: 3                          │
│                                                                    │
│  ── Recent posts ──────────────────────────────────────────  <h2> │  <- NEW block (this story)
│                                                                    │
│    Sprint Planning: Portland 2026                                 │
│    Topic · 2 days ago                                              │
│    ------------------------------------------------------------   │
│    Weekly Standup Notes                                           │
│    Topic · 5 days ago                                              │
│    ------------------------------------------------------------   │
│    Budget Allocation Q3 2026                                      │
│    Topic · 1 week ago                                              │
│                                                                    │
│    (up to 10 rows total; newest first; no pager chrome shown      │
│     for the POC — items_per_page=10 is the effective cap)         │
└──────────────────────────────────────────────────────────────────┘
```

**Row anatomy (one `stream_card` view-mode render per row):**

```
  <row>
    [Title text, as a link]   <- control: link to node/{nid}; canonical <a href>,
                                  keyboard-focusable, underlined per theme link style
    [Type] · [relative date]  <- plain text, non-interactive, secondary/muted color
                                  (still meets AA contrast — not decorative-only gray)
  </row>
```

- Title link: navigates to the node. Never disabled — if a row renders, the
  viewer already has `node_access view` grant (enforced server-side by the
  view's `node_access` filter), so the link is always safe to show and follow.
- No per-row action buttons (no edit/delete/follow controls here) — this is a
  read-only activity list. Out-of-scope actions (RSVP, join) belong to #116.

## Screen: `/user/{uid}` — ONE state (single authored post)

Same block, same heading, exactly one row:

```
  ── Recent posts ──────────────────────────────────────────  <h2>
    Weekend Hike Planning
    Event · yesterday
```

No "see more" / pager link needed at 1 item.

## Screen: `/user/{uid}` — EMPTY state (viewer sees nothing the owner authored)

Triggered when: the profile owner has authored nothing published, OR
everything they authored is in groups the current viewer cannot access, OR
(non-viewer-specific) they've authored nothing at all. The copy must stay
truthful across all three causes — it must NOT claim "hasn't posted" when the
real reason is access-scoping, and must NOT reveal group existence to an
outsider.

```
  ── Recent posts ──────────────────────────────────────────  <h2>
    No posts yet.
```

**Copy decision:** `"No posts yet."` — one line, no CTA, no group-membership
hint. Honest for all three trigger causes above (an outsider can't
distinguish "never posted" from "posted somewhere I can't see," which is the
correct access-safe framing — the empty state must not leak that
inaccessible content exists).

## Screen: `/user/{uid}` — ERROR / degraded state

Not applicable as a distinct UI state for this story: the view has no
external service dependency (pure DB query against `node_field_data` +
`node_access`). If the view itself is broken/misconfigured, Drupal's block
render simply omits the block (standard Views-block behavior) rather than
showing a user-facing error — no new error copy needed for this story.

## WCAG 2.2 AA notes

- **Heading level:** the block's own title ("Recent posts") renders as an
  `<h2>`, nested correctly under the page's `<h1>` (username) and *after*
  the existing contribution-stats block's heading (also `<h2>`, sibling —
  not nested inside it). No heading level is skipped.
- **Keyboard/focus:** each row's title is a native `<a href>` inherited from
  `stream_card`'s existing render — already keyboard-reachable via Tab,
  already gets the theme's visible focus ring (outline), nothing new to
  implement. No custom JS interaction (no expand/collapse, no AJAX-only
  content) in this story's scope, so no new focus-trap or ARIA-live
  surface is introduced.
- **Contrast:** the "Type · relative date" secondary text line is the one
  new visual element (`profile-activity.css`). It must meet 4.5:1 (normal
  text) against the block background — do not drop below the theme's
  existing "muted/secondary text" token used elsewhere (e.g. same shade as
  `group_content_stream`'s date column, if styled) — do not introduce a new,
  lighter gray. Verify computed contrast once the CSS lands (T/U check).
- **Non-color status:** there is no status-by-color in this surface (no
  red/green badges) — type + date are plain text, so no color-only-meaning
  risk exists here by construction.
- **Empty state:** "No posts yet." is plain text inside the same `<h2>`
  section — no icon-only or color-only signal.
