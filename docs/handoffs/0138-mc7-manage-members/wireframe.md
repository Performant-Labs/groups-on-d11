# Wireframe — Issue #138 (MC-7): Manage-members page

Run slug: `0138-mc7-manage-members`
Route: `do_group_membership.manage_members` at `/group/{group}/members`
Mode: (a) generated low-fi
Format: ASCII (Drupal admin page, structure/hierarchy only — no production CSS/color system)

> Locked decisions this wireframe does NOT re-open (per brief.md): route path, permission gate,
> `field_membership_status` values (`active`/`pending`/`blocked`), the `pending → active` /
> `pending → <deleted>` / `active ⇄ blocked` / `<any> → <deleted>` transition rules, "Change role"
> mutating `group_roles` only, add-member field set (user autocomplete + role checkboxes default
> Member), the last-Organizer guard (AC-9), and 50-row pagination (AC-15/W-2). This wireframe is the
> **visual/interaction layer** on top of that locked model: table layout, badge rendering, button
> placement, confirm-step UI, disabled-state affordances, and copy.

## Conventions reused from the survey (do not reinvent)

- **Table:** Drupal render-array `#type => 'table'` idiom, matching
  `do_notifications/src/Controller/NotificationSettingsController.php::page()` — `#header` array of
  `<th scope="col">` cells, `#rows`, `#empty` string for the zero-row case. Real `<table>`, not a
  div-grid.
- **Badge / non-color-alone pattern:** matches `do_chrome`'s existing
  `do-chrome-permission-matrix.html.twig` cell pattern — a glyph span marked `aria-hidden="true"`
  paired with a `visually-hidden` (or always-visible, see badge spec below) text label, plus a
  `data-state="…"` / modifier class carrying the color. Also matches the existing
  `.group__archived-badge` / `.pin-badge` precedent (gray badge, white text, focusable if
  interactive) in `do_chrome/css/do_chrome.css`.
- **Buttons:** `button` / `button--primary` / `button--danger` classes, matching
  `NotificationSettingsController`'s `cancel_all` (`button--danger`) and `enable_link`
  (`button--primary`) actions.
- **Status messages:** `messages messages--warning` / `messages messages--status` /
  `messages messages--error` div wrapper, matching the notifications-disabled banner in the same
  controller. Drupal core's own `#type => 'status_messages'` renders these automatically after any
  form submit / redirect — reused as-is, not restyled.
- **CSS ownership:** module-owned, `do_group_membership/css/manage-members.css` +
  `.libraries.yml`, attached only on this route — per every existing UI-bearing `do_*` module
  (do_chrome, do_group_extras, do_multigroup, do_profile_stats). No `groups_chrome` theme edits.
- **BEM-ish class prefix:** `do-group-membership__*` (mirrors `do-chrome-perm-matrix__*`).

---

## Screen 1 — Manage-members page, MANY rows (primary/steady state)

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  Breadcrumb: Home › Groups › Astronomy Enthusiasts                                  │
│  Local tasks: [ View ] [ Edit ] [*Manage members*] [ Content ] [ ... ]               │
│                                                                                       │
│  H1: Manage members — Astronomy Enthusiasts                                         │
│  <p class="do-group-membership__intro">                                             │
│    Add, remove, or change the role of anyone in this group. Pending requests need   │
│    your approval before the person can access group content.                        │
│  </p>                                                                                │
│                                                                                       │
│  ── Add a member ──────────────────────────────────────────────────────────────     │
│  (see Screen 3 — Add-member form, collapsible <details>/inline block, closed by      │
│   default when the list is non-empty, matching the do_notifications "actions"       │
│   block placement below the summary)                                                │
│  [ + Add member ]  (button--primary, toggles the form open; see Screen 3)           │
│                                                                                       │
│  ── Members (42) ──────────────────────────────────────────────────────────────     │
│                                                                                       │
│  ┌────────────────────┬───────────────┬───────────────┬─────────────────┬─────────┐│
│  │ Member name         │ Role(s)       │ Status        │ Joined/Requested│ Actions ││
│  │ <th scope="col">    │ <th>          │ <th>          │ <th>            │ <th>    ││
│  ├────────────────────┼───────────────┼───────────────┼─────────────────┼─────────┤│
│  │ Priya Shah          │ Organizer     │ ✓ Active      │ Joined:         │ [Role ▾]││
│  │ (link to user)      │               │ (green badge) │ 2025-03-11      │ [Remove]││
│  ├────────────────────┼───────────────┼───────────────┼─────────────────┼─────────┤│
│  │ Devon Okafor        │ Moderator     │ ✓ Active      │ Joined:         │ [Role ▾]││
│  │                     │               │               │ 2025-04-02      │ [Remove]││
│  ├────────────────────┼───────────────┼───────────────┼─────────────────┼─────────┤│
│  │ Lee Marsh           │ Member        │ ✓ Active      │ Joined:         │ [Role ▾]││
│  │                     │               │               │ 2025-06-19      │ [Remove]││
│  ├────────────────────┼───────────────┼───────────────┼─────────────────┼─────────┤│
│  │ Sam Okonkwo         │ Member        │ ⏳ Pending    │ Requested:      │[Approve]││
│  │                     │ (requested)   │ (amber badge) │ 2026-07-20      │ [Deny]  ││
│  ├────────────────────┼───────────────┼───────────────┼─────────────────┼─────────┤│
│  │ Robin Vance         │ Member        │ ⛔ Blocked    │ Joined:         │ [Role ▾]││
│  │                     │               │ (red badge)   │ 2025-01-08      │ [Unblock│
│  │                     │               │               │                 │ [Remove]││
│  └────────────────────┴───────────────┴───────────────┴─────────────────┴─────────┘│
│                                                                                       │
│  Pager:  « Previous   1  [2]  3  4  Next »        (50 rows/page, AC-15/W-2)          │
└────────────────────────────────────────────────────────────────────────────────────┘
```

### Column-by-column behavior

**Member name** — plain text + link to the user's profile (`/user/{uid}`), not an action control.

**Role(s)** — display text only in this column (e.g. "Organizer", "Moderator", "Member"; a
`pending` row shows the role(s) the request was made/added for, suffixed "(requested)" so it reads
correctly before approval — the role takes effect only once `field_membership_status` is `active`).
Changing role is a separate control in the Actions column (`[Role ▾]`), not inline-editable text —
this keeps the destructive/consequential action (which can trigger the last-Organizer guard) behind
an explicit control, not an accidental inline edit.

**Status badge** — see dedicated spec below.

**Joined/Requested date** — label switches per AC-4: `"Joined: <created>"` for `active`/`blocked`
rows, `"Requested: <created>"` for `pending` rows. Same `created` base-field value in both cases,
different label text only (no new field, per B-4).

**Actions** — one or more real `<button>` elements (form submits), never a link with a JS
click-handler:
- Active/blocked row: `[Role ▾]` (opens change-role sub-form, Screen 4) + `[Remove]` (opens confirm
  step, Screen 5). Blocked row additionally gets `[Unblock]` (button, `active ⇄ blocked` is
  symmetric per AC-5) ahead of `[Remove]`.
- Pending row: `[Approve]` (button--primary) + `[Deny]` (button--danger) — no `[Role ▾]` or
  `[Remove]` on a pending row (nothing to demote/remove yet; deny already achieves "remove the
  request").
- Every button is keyboard-reachable via normal tab order (real `<button>`, not `<div
  onclick>`), and carries a visible `:focus-visible` outline (WCAG 2.2 AA) — see Focus states
  section below.

### Status badge spec (WCAG 2.2 AA — non-color-alone)

Each badge = one glyph (decorative, `aria-hidden="true"`) + one always-visible text word (not
`visually-hidden` — the brief explicitly wants "badge with color AND text/icon", i.e. both must be
perceivable without relying on color, and a screen-reader user benefits from the text being in the
normal accessibility tree without extra markup, so the text label is genuinely visible, not
sr-only). Class carries the color; the text/glyph pair carries the meaning:

| Status    | Glyph (aria-hidden) | Visible text | Modifier class                              | Color (indicative only, F/theme finalizes exact hex) |
|-----------|---------------------|---------------|----------------------------------------------|--------------------------------------------------------|
| `active`  | `✓`                 | "Active"      | `do-group-membership__badge--active`         | green background, white/dark text (sufficient contrast) |
| `pending` | `⏳` (or `●` if font coverage is a concern — F's call, either satisfies non-color-alone) | "Pending" | `do-group-membership__badge--pending` | amber/gold background, dark text |
| `blocked` | `⛔`                 | "Blocked"     | `do-group-membership__badge--blocked`        | red background, white text |

Markup shape (mirrors the `do_chrome` permission-matrix cell pattern):

```html
<span class="do-group-membership__badge do-group-membership__badge--active" data-state="active">
  <span aria-hidden="true">✓</span> Active
</span>
```

This satisfies AC-7/AC-15's "badge with color AND text/icon label" requirement and matches the
existing `do-chrome-perm-matrix__cell` glyph+label convention already proven in this codebase.

---

## Screen 2 — Manage-members page, EMPTY state

Only reachable in practice if a group somehow has zero relationships at all (edge case — Group's
own `creator_membership: true` normally guarantees at least the creator/Organizer is present; shown
for completeness per the empty/one/many/error state requirement).

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  H1: Manage members — Astronomy Enthusiasts                                         │
│  [ + Add member ]                                                                    │
│                                                                                       │
│  ── Members (0) ───────────────────────────────────────────────────────────────      │
│  <table>'s #empty slot renders:                                                      │
│                                                                                       │
│    "This group has no members yet. Add the first member below."                     │
│    (plain text row spanning the table, not a separate component — matches           │
│     #type => 'table' #empty convention already used in NotificationSettingsController)│
└────────────────────────────────────────────────────────────────────────────────────┘
```

Truthful copy: guides to the prerequisite action ("Add the first member below") rather than a bare
"No members." `[+ Add member]` stays enabled (it's the only way out of this state).

## Screen 2b — Manage-members page, ONE row

Same table shell as Screen 1 with a single row. No special-casing needed — included to confirm the
last-Organizer guard is visible even at n=1 (see Screen 6): if that one row is the sole Organizer,
its `[Role ▾]` and `[Remove]` controls are disabled per the guard, not merely validated on submit
(discoverable-before-you-try, not fail-after-click) — see Screen 6 for the exact treatment and the
open question about which disabling strategy to use.

---

## Screen 3 — Add-member form (expanded)

Toggled open by `[+ Add member]`; collapses back on cancel or successful submit. Renders inline
above the table (matches `do_notifications`'s "actions" block placement — a `<container>` render
element directly under the summary/count).

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  ── Add a member ──────────────────────────────────────────────  [ ✕ Cancel ]       │
│                                                                                       │
│  User *                                                                              │
│  [ Start typing a name or email...                              ] (autocomplete)    │
│  ^ Drupal core entity_reference autocomplete widget against the `user` entity.       │
│                                                                                       │
│  Role(s) *                                                                           │
│  [x] Member      (checked by default)                                               │
│  [ ] Moderator                                                                       │
│  [ ] Organizer                                                                       │
│                                                                                       │
│  [ Add member ]  (button--primary, submit)      [ Cancel ]  (button, secondary)      │
└────────────────────────────────────────────────────────────────────────────────────┘
```

### Add-member validation states (per AC-8)

**Duplicate-membership error** (user already has a relationship, any status):

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  <div class="messages messages--error">                                             │
│    This user is already a member of this group.                                     │
│  </div>                                                                              │
│  (form re-renders with the User field's value preserved, focus moved to the User     │
│   field per Drupal core's standard form-error-summary + field-focus behavior)        │
└────────────────────────────────────────────────────────────────────────────────────┘
```

**Blocked-account error:**

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  <div class="messages messages--error">                                             │
│    This user's site account is blocked.                                             │
│  </div>                                                                              │
└────────────────────────────────────────────────────────────────────────────────────┘
```

**Success:**

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  <div class="messages messages--status">                                            │
│    Sam Okonkwo has been added to this group as Member.                              │
│  </div>                                                                              │
│  (add-member form collapses; table re-renders with the new row, status = Active     │
│   per B-6's "default status on add: active")                                        │
└────────────────────────────────────────────────────────────────────────────────────┘
```

Both `[Add member]` and `[Cancel]` are real `<button>` elements; `[Add member]` is disabled while
the User field is empty (client-side convenience only — the server-side validation above is the
authoritative guard, so a JS-disabled failure never blocks submission if JS is off).

---

## Screen 4 — Change-role control (per row)

`[Role ▾]` opens an inline sub-form (details/disclosure widget, not a full-page navigation) scoped
to that row:

```
│ Devon Okafor        │ Moderator ▾  │ ✓ Active │ Joined: 2025-04-02 │ [Save] [Cancel] ││
│                      │ [ ] Organizer│          │                    │                 ││
│                      │ [x] Moderator│          │                    │                 ││
│                      │ [ ] Member   │          │                    │                 ││
```

- Checkboxes (role is a multi-value field per the existing `group_roles` cardinality — brief
  doesn't restrict to single-select, so the wireframe keeps checkboxes, matching the add-member
  form's own control type for consistency. **See open question OQ-1** below — if the product intent
  is "exactly one role per member," this should be radio buttons instead; recommending checkboxes
  as the default since `group_roles` is itself multi-valued.)
- `[Save]` (button--primary) commits (`changeRole()`); `[Cancel]` (button) collapses without saving.
- If this save would remove the group's last Organizer, the row's `[Save]` is blocked server-side
  and returns to Screen 6's guard-message state instead of committing.

---

## Screen 5 — Remove-member confirm step (per row)

`[Remove]` never fires an instant destructive action (WCAG 2.2 AA + AC-7's own confirm-step
requirement). It navigates to (or opens, F's implementation choice) Drupal core's standard
`ConfirmFormBase` pattern:

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  H1: Remove member?                                                                 │
│                                                                                       │
│  Are you sure you want to remove Lee Marsh from Astronomy Enthusiasts?               │
│  This deletes their membership. They will lose access to group content and will      │
│  need to be re-added or re-request access to rejoin.                                 │
│                                                                                       │
│  [ Remove member ]  (button--danger, submit — the confirming action)                 │
│  [ Cancel ]          (button, link back to the Manage-members table, no change)      │
└────────────────────────────────────────────────────────────────────────────────────┘
```

On success: redirect back to `/group/{group}/members` with
`messages messages--status`: `"Lee Marsh has been removed from this group."`

---

## Screen 6 — Last-remaining-Organizer guard (AC-9)

Two places this can be triggered: `[Remove]` on an Organizer row, or `[Role ▾] → Save` demoting the
group's last Organizer to Moderator/Member.

**Recommended treatment (see OQ-2): disable-with-explanation, not fail-after-submit.** When a row IS
the group's last Organizer, its destructive controls render disabled with an inline explanatory
note, so the guard is discoverable before the user attempts the action, not only as a server
validation error after clicking:

```
│ Priya Shah          │ Organizer     │ ✓ Active │ Joined: 2025-03-11 │ [Role ▾] (disabled)│
│                      │               │          │                    │ [Remove] (disabled)│
│                      │               │          │                    │ ⓘ Last Organizer — │
│                      │               │          │                    │   promote another  │
│                      │               │          │                    │   member first.    │
```

- Disabled buttons still render as real `<button disabled>` elements (not removed from the DOM),
  so assistive tech announces them as present-but-unavailable, with the `ⓘ` note as the
  machine-readable reason (`aria-describedby` linking the button to the note).
- If somehow submitted anyway (e.g. a race — two tabs, second Organizer removed between page loads),
  the **server-side validation error is still the authoritative backstop**:

```
┌────────────────────────────────────────────────────────────────────────────────────┐
│  <div class="messages messages--error">                                             │
│    A group must have at least one Organizer.                                        │
│  </div>                                                                              │
└────────────────────────────────────────────────────────────────────────────────────┘
```

Groups-Moderate accounts are exempt from this floor (not counted as the group's own Organizer) —
their own row, if they ever appear in this table (they typically won't, since they're not a group
member — see Open Questions OQ-4), would never show this disabled state for themselves.

---

## Screen 7 — Approve/deny a pending row, including the race (AC-10)

Normal path — `[Approve]`:

```
<div class="messages messages--status">
  Sam Okonkwo's request to join has been approved. They are now an active Member.
</div>
```

Normal path — `[Deny]` (relationship deleted, not blocked, per B-3):

```
<div class="messages messages--status">
  Sam Okonkwo's request to join has been denied.
</div>
```

Race condition (AC-10) — a second organizer already approved/denied the same request before this
click resolves:

```
<div class="messages messages--warning">
  This request was already handled.
</div>
```

No fatal error, no white screen — the table simply re-renders in its current (already-resolved)
state; the row that no longer has a pending request simply isn't shown as pending anymore (or is
gone entirely, if it was denied).

---

## Focus / keyboard-operability notes (WCAG 2.2 AA)

- Every interactive control on this page is a real `<button>` (submit) or `<a>`/`<button>` pair —
  no `<div onclick>` / JS-only handlers anywhere on this surface (AC-7).
- Tab order follows DOM/reading order: page-level `[+ Add member]` → add-member form fields (when
  open) → table, row by row, left-to-right through each row's action buttons → pager controls.
- Every focusable element gets a visible `:focus-visible` outline distinct from `:hover` styling
  (module CSS, `do-group-membership.libraries.yml`) — 2px solid outline, offset, sufficient
  contrast against both the default and badge backgrounds; this is a concrete CSS deliverable for F,
  not left to browser default-only (Safari/Chrome UA-agent defaults are inconsistent enough that an
  explicit rule is safer for AA in this codebase, matching the `do-chrome-info:focus` precedent
  already in `do_chrome.css`).
- Disabled buttons (last-Organizer guard) are `disabled` attribute (removed from tab order per
  spec, which is correct — a disabled control should not be a tab stop) but remain visible with the
  explanatory note associated via `aria-describedby`, so a screen-reader user tabbing past the row
  still gets the reason once they reach whichever control is still enabled/focusable in that row.
- The inline `[Role ▾]` disclosure and `[Remove]` confirm step are keyboard-operable to open/close
  (native `<details>` or an ARIA `aria-expanded` button pattern — F's implementation choice, no
  visual difference to this wireframe).
- Pager links (`« Previous`, page numbers, `Next »`) are real `<a href>` elements per Drupal core's
  pager theme — already accessible by default, no new work.

---

## Empty / one / many / error state summary (recap for T/U)

| State | Screen | Truthful copy | Primary action availability |
|---|---|---|---|
| Empty (0 members) | Screen 2 | "This group has no members yet. Add the first member below." | `[+ Add member]` enabled |
| One member (edge, e.g. sole Organizer) | Screen 2b + Screen 6 | Guard note disables Role/Remove for the sole Organizer | `[+ Add member]` enabled; row's destructive actions disabled with reason |
| Many (paginated) | Screen 1 | Row-per-member table | Pager appears past 50 rows (AC-15/W-2) |
| Add-member duplicate error | Screen 3 | "This user is already a member of this group." | Form re-shown, no relationship created |
| Add-member blocked-account error | Screen 3 | "This user's site account is blocked." | Form re-shown, no relationship created |
| Remove confirm | Screen 5 | Explicit consequence sentence, named user + group | Confirm vs. Cancel, no instant-fire |
| Last-Organizer guard (attempted anyway) | Screen 6 | "A group must have at least one Organizer." | Blocked, no relationship/role change committed |
| Approve/deny race | Screen 7 | "This request was already handled." | No-op, no fatal error |

---

## Open questions for approval

**OQ-1 — Change-role control type: checkboxes (multi-role) vs. radio (single-role)?**
The brief's add-member form explicitly uses checkboxes (Organizer/Moderator/Member, default
Member), and `group_roles` is a multi-valued field, so a member could technically hold more than
one role simultaneously. **Recommended default: checkboxes**, consistent with the add-member form
and the underlying field cardinality — the last-Organizer guard already handles the "can't remove
the only Organizer" case regardless of how many roles a row shows. If the product intent is
strictly one-role-per-member, this is a one-line change to radio buttons with no other wireframe
impact.

**OQ-2 — Last-Organizer guard: disable-before-attempt vs. fail-after-submit only?**
The brief's AC-9 only requires "blocked with a form validation error," which is satisfied by a
fail-after-submit-only approach (simpler for F to implement — no need to compute "is this the last
Organizer" on every page render, only on submit). This wireframe's Screen 6 recommends the richer
disable-before-attempt treatment because it is more WCAG-2.2-AA-friendly (discoverable without a
failed round trip) and matches this codebase's existing pattern of disabling/hiding an action
proactively rather than only validating on submit (e.g. do_chrome's tooltip-driven affordances).
**Recommended default: disable-before-attempt**, with the server-side error as an unconditional
backstop for races — but this does add a small amount of controller logic (computing "is this row
the group's sole active Organizer" per render). If F/O prefer the simpler fail-after-submit-only
approach for this MVP, that is also fully AC-9-compliant; flag explicitly for approval since it's
the one place this wireframe adds scope beyond the bare acceptance criterion.

**OQ-3 — Add-member form placement: inline-toggle vs. separate page.**
This wireframe places Add-member as an inline expand/collapse block above the table (Screen 3),
matching Drupal admin-UI convention for "add an item to the list you're looking at" (e.g. core's
own "Add role" / "Add field" patterns) and avoiding an extra full-page round trip. **Recommended
default: inline toggle**, as wireframed. A separate `/group/{group}/members/add` page is an
equally valid alternative (arguably simpler for F — it's just another `FormBase` + route, same
shape as the confirm-form route) if inline expand/collapse proves annoying to implement without a
JS library; either satisfies AC-7's requirements identically from a UX standpoint, so this is
F's implementation call, not a re-open of the wireframe's information architecture.

**OQ-4 — Does a Groups-Moderate user ever appear as a row in this table?**
Per B-5, Groups-Moderate access is a synchronized global role, not a per-group membership
relationship — so a Groups-Moderate user managing a group they don't belong to will **not** have a
row in this table at all (there's no `group_relationship` entity for them). This wireframe assumes
that is correct and intentional (they're an administrator of the page, not a row in it) and does
not add any UI to represent "the Groups-Moderate users who can also manage this group" — that
would require enumerating all `groups_moderate`-role site accounts, which is out of scope per the
brief's non-goals. **Recommended default: no change** — flagging only so O/human sign-off
explicitly confirms this is the intended (and, per the brief, only correct) reading, not an
oversight.

**OQ-5 — Pending-row role display before approval.**
Screen 1 shows a pending row's Role(s) column as "Member (requested)" to make clear the role hasn't
taken effect yet. An alternative is to show only "—" (role not yet assigned) until approval.
**Recommended default: show the requested role with the "(requested)" qualifier**, as wireframed —
it gives the approving Organizer the information they need (what role was requested) without an
extra click into the row, and costs nothing extra to implement (the `group_roles` value already
exists on the pending relationship per the add-member/join-request flow, per B-3).

## No unrendered/unverified glyphs

This wireframe is plain-text ASCII with inline Unicode glyphs (`✓ ⏳ ⛔ ▾ ✕ ⓘ «» ●`) used only as
illustrative placeholders for the eventual badge/icon treatment — no SVG geometry was hand-authored,
and no icon set was consulted since this is a pure ASCII/text artifact (not an SVG/HTML render). F
should source the actual glyphs/icons from the project's existing icon usage (the `do_chrome`
permission-matrix's `✓` / `—` / `·` glyph set is the nearest existing precedent) rather than treating
the specific emoji shown here as final.
