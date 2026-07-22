# Handoff-D: Phase 2 - Manage-members page wireframe

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Mode:** (a) generated low-fi
**Wireframe:** `docs/handoffs/0138-mc7-manage-members/wireframe.md`

## Screens & states covered

- **Screen 1 — Many rows (steady state):** real `<table>` with `<th scope="col">` headers (Member
  name / Role(s) / Status / Joined-Requested date / Actions); active, pending, and blocked rows
  side by side to show all three status badges and their differing action sets in one view.
- **Screen 2 — Empty (0 members):** truthful copy via the table's `#empty` slot ("This group has no
  members yet. Add the first member below."), `[+ Add member]` stays enabled.
- **Screen 2b — One row:** confirms the last-Organizer guard renders correctly even at n=1.
- **Screen 3 — Add-member form (+ its own error/success states):** user autocomplete + role
  checkboxes (default Member); duplicate-membership error, blocked-account error, and success
  messages, each with truthful, specific copy (not a generic "Error").
- **Screen 4 — Change-role inline sub-form:** row-scoped checkbox disclosure, Save/Cancel.
- **Screen 5 — Remove-member confirm step:** a real confirm page (never instant-fire), named
  user/group in the copy, danger-styled confirming button.
- **Screen 6 — Last-remaining-Organizer guard (AC-9):** disabled Role/Remove controls with an
  inline reason on the guarded row, plus the server-side validation-error backstop for races.
- **Screen 7 — Approve/deny + the concurrent-race no-op (AC-10):** success copy for both approve
  and deny, and the "This request was already handled." warning for the race case.
- **Pagination (AC-15/W-2):** 50-row pager noted on Screen 1, using Drupal core's pager theme
  (already accessible, no new work).
- **Focus/keyboard notes:** every control is a real `<button>`/`<a>`, explicit `:focus-visible`
  requirement called out for F's CSS (not left to browser defaults), disabled buttons keep
  `aria-describedby` reasoning rather than disappearing.

## Existing components/patterns reused

- Drupal render-array `#type => 'table'` (`#header`/`#rows`/`#empty`) — matches
  `do_notifications/src/Controller/NotificationSettingsController.php::page()` exactly.
- Badge = glyph (`aria-hidden`) + always-visible text label + modifier class carrying color —
  matches `do_chrome`'s `do-chrome-permission-matrix.html.twig` cell pattern (`✓`/`—`/`·` glyphs +
  `visually-hidden` label) and the existing `.group__archived-badge`/`.pin-badge` badge precedent
  in `do_chrome/css/do_chrome.css`.
- `button` / `button--primary` / `button--danger` classes — matches
  `NotificationSettingsController`'s `cancel_all`/`enable_link` actions.
- `messages messages--status/--warning/--error` — matches the same controller's disabled-
  notifications banner; Drupal core's `#type => 'status_messages'` renders these automatically.
- Module-owned CSS (`do_group_membership/css/manage-members.css` + `.libraries.yml`), attached
  only on this route — matches every existing UI-bearing `do_*` module (do_chrome,
  do_group_extras, do_multigroup, do_profile_stats); no `groups_chrome` theme edits.
- BEM-ish class prefix `do-group-membership__*`, mirroring `do-chrome-perm-matrix__*`.

## Open questions for approval

1. **OQ-1 (change-role control type):** checkboxes (multi-role, matches add-member form and
   `group_roles` cardinality) vs. radio (strictly one role per member). **Recommended: checkboxes.**
2. **OQ-2 (last-Organizer guard UX):** disable-before-attempt (richer, more AA-friendly, small
   added controller logic) vs. fail-after-submit-only (simpler, still AC-9-compliant).
   **Recommended: disable-before-attempt**, server-side error as unconditional backstop for races.
3. **OQ-3 (add-member form placement):** inline expand/collapse above the table vs. a separate
   `/group/{group}/members/add` page. **Recommended: inline toggle**, as wireframed; either is
   UX-equivalent, this is F's implementation call if inline proves awkward without JS tooling.
4. **OQ-4 (Groups-Moderate rows):** confirming a Groups-Moderate user never appears as a row in
   this table (no `group_relationship` entity for them, per B-5's synchronized-role mechanism) —
   **recommended: no change**, flagged only so sign-off explicitly confirms this is intentional,
   not an oversight.
5. **OQ-5 (pending-row role display):** show "Member (requested)" vs. "—" before approval.
   **Recommended: show the requested role with the qualifier** — no extra implementation cost,
   more useful to the approving Organizer.

None of these block approval of the overall screen/state coverage — each has a clear recommended
default that preserves current scope; they're surfaced because they're genuine, defensible design
choices a human should confirm rather than a gap in the wireframe.

## Approval

**Approved by operator 2026-07-22** (relayed by coordinator). All five open questions resolved:
- OQ-1: **checkboxes** (multi-valued `group_roles`, matches add-member form). Confirmed.
- OQ-2: **disable-before-attempt** with the server-side validation error as an unconditional
  backstop for races. Keep the per-render check trivial — a single "count active Organizers in this
  group" query, not an elaborate mechanism.
- OQ-3: **inline toggle** as wireframed; F may fall back to a separate `/group/{group}/members/add`
  route if inline expand/collapse is awkward without JS — either is AC-compliant.
- OQ-4: **confirmed intentional — no Groups-Moderate row.** A synchronized global role has no
  `group_relationship`, so those users administer the page, not appear as rows. Do NOT add UI
  enumerating site-level moderators (out of scope).
- OQ-5: **show the requested role with the "(requested)" qualifier.** Confirmed.
