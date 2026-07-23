# Wireframe — #134 SC-7 Private groups (visibility axis)

**Mode:** (a) generated low-fi, ASCII-only.
**Scope:** ONE net-new UI surface — a "Private" badge + tooltip, rendered in two
existing locations (directory card, group canonical header). No new components;
both locations reuse the landed `gc-badge` pattern and the landed
`data-do-tooltip` trigger contract (see `group--full.html.twig` lines 113-127,
the `#122` lead-section ⓘ precedent).

---

## 1. `/all-groups` directory grid — private card vs public card

Reuses `views-view-fields--all-groups.html.twig`'s existing
`.gc-directory-card__badges` row (currently: type badge + visibility badge).
The privacy badge is a THIRD badge appended to that same row when
`field_group_privacy = private`. It does NOT replace the existing
`field_group_visibility` badge (Open/Moderated/Invite Only) — the two axes
stay visually distinct, matching the two-axis model #121/#134 established.

```
PUBLIC CARD (contrast — Elena's session, e.g. "Drupal NorCal")           PRIVATE CARD (Elena's session — "Security Team")
.------------------------------------------------.                      .------------------------------------------------.
| [Geographical] [Open]                          |                      | [Working group] [Invite Only] [ⓘ Private]      |
|                                                 |                      |                                                 |
| Drupal NorCal                                   |                      | Security Team                                   |
| Bay Area Drupal meetups & sprints.              |                      | Coordinated disclosure + security review group. |
|                                                 |                      |                                                 |
| (o) 42 members   (o) Active  3 days ago         |                      | (o) 3 members   (o) Active  1 day ago            |
|                                                 |                      |                                                 |
| [ View group ]                                 |                      | [ View group ]                                 |
'------------------------------------------------'                      '------------------------------------------------'
```

Badge markup (append to the existing badges row, AFTER the visibility badge,
matching how `type_label` -> `visibility_label` already order left-to-right):

```
<span class="gc-badge gc-badge--warning gc-directory-card__privacy"
      tabindex="0" role="note"
      aria-label="{{ gc_directory.privacy_help_copy }}"
      data-do-tooltip="{{ gc_directory.privacy_help_copy }}">
  Private
</span>
```

- Label: **"Private"** (plain text, no icon glyph needed inside the badge
  itself — the badge IS the trigger, same pattern as the existing
  `gc-badge` elements, which carry no icon today). Do not add a separate ⓘ
  span here; unlike the lead-section heading (which has plain text next to
  its ⓘ), the badge text itself is short enough to be the whole trigger.
  This matches the acceptance-criteria selector `.gc-privacy-badge[data-do-tooltip]`
  — use `gc-privacy-badge` as an ADDITIONAL class alongside `gc-badge` so
  both the generic badge styling and the specific test hook apply:
  `class="gc-badge gc-badge--warning gc-directory-card__privacy gc-privacy-badge"`.
- Behavior: hover/focus shows the tooltip (do_chrome/tooltips, already
  globally attached — no new library attach needed, per the #122 precedent
  at `group--full.html.twig` line 118-120).
- Renders ONLY when `field_group_privacy == 'private'`. Public and Unlisted
  groups show NO privacy badge at all (silent, matches the archive-badge /
  lead-section "no badge when not applicable" convention already in this
  theme) — an absent badge is the honest default-state signal, not an
  "empty state" needing copy.
- Disabled state: N/A — this is a read-only indicator, not a control. It is
  never interactive beyond the tooltip trigger (no click action, no href).

### Directory states (empty / one / many / error)

| State | What renders | Truthful copy |
|---|---|---|
| **Anonymous, many public/unlisted groups, zero private groups visible to them** | Normal grid, no privacy badges anywhere (none of these groups are private FROM THIS SESSION's view — Security Team is entity-access-forbidden so it never reaches the view/render pass at all). | No copy needed — Security Team's absence IS the feature (AC #3). |
| **Elena (member), many groups incl. Security Team** | Security Team card renders with the badge (above). | Badge tooltip text (see COPY section below). |
| **Elena, zero groups (hypothetical/edge)** | Out of scope for this story — existing `/all-groups` empty-state copy is unchanged by #134. | N/A — not this story's surface. |
| **Error (view fails to load)** | Out of scope — existing Views error handling unchanged. | N/A. |

---

## 2. Group canonical header — `/group/{group}` (Security Team, Elena's session)

Reuses the EXISTING `.gc-group-header__badges` row in
`group--full.html.twig` (currently: type badge, visibility badge, member-count
badge). The privacy badge is inserted between the visibility badge and the
member-count badge — same row, same styling, third badge:

```
Elena's session — /group/{security_team_id} (200 OK)
.--------------------------------------------------------------------.
|  <-- page-title block renders <h1>Security Team</h1> above this -->|
|                                                                     |
|  [Working group]  [Invite Only]  [Private]ⓘ   (o) 3 members        |
|                                                          [ Leave ]  |
|                                                                     |
|  (Elena) (Maria) (James)                                           |
|                                                                     |
|  [ Stream ] [ Events ] [ Members ] [ About ]                        |
'--------------------------------------------------------------------'

  Hover/focus on [Private] -->
  .--------------------------------------------------------------.
  | Hidden from everyone except members — anyone else gets a     |
  | "not found" page. Unlike Invite Only, non-members can't even  |
  | see this group exists.                                        |
  '--------------------------------------------------------------'
```

```
Anonymous session — /group/{security_team_id}
=> 403 Forbidden (Drupal's standard access-denied page). No wireframe
   surface: nothing group-specific renders (no title, no badges, no leak
   of the group name in the page <title> or breadcrumb — T asserts this
   per decisions.md's "Assumed" risk note). This is INVISIBLE BY DESIGN,
   matching the story's framing; no ASCII box needed for "nothing".
```

Badge markup (inserted into the existing header badges block):

```
{% if gc_group.privacy_label == 'Private' %}
  <span class="gc-badge gc-badge--warning gc-group-header__privacy gc-privacy-badge"
        tabindex="0" role="note"
        aria-label="{{ gc_group.privacy_help_copy }}"
        data-do-tooltip="{{ gc_group.privacy_help_copy }}">
    Private
  </span>
{% endif %}
```

- Behavior: same read-only tooltip trigger as the directory-card badge —
  identical copy source (`HelpText::get('privacy.private')`), same class
  (`gc-privacy-badge`) so the AC #9 functional-test selector
  (`.gc-privacy-badge[data-do-tooltip]`) matches BOTH surfaces with one
  assertion helper.
- Placement: same row as type/visibility badges, not a separate line — a
  private Invite-Only group like Security Team shows all three badges
  side by side, teaching the two axes are independent (a group can be
  Invite Only AND Private at once, or Open AND Private, etc.).
- Disabled: N/A, read-only indicator only.

### Canonical-header states

| State | What renders | Truthful copy |
|---|---|---|
| **Elena/Maria/James (members) viewing Security Team** | Badge present (above). | Tooltip copy, see COPY section. |
| **Any non-member, logged in, viewing Security Team by direct URL** | 403 — same as anonymous. No badge, no header, no leak. (Covered by AC #2's 403 assertion; the brief scopes the functional test to anonymous, but the access hook is member-vs-non-member, not anonymous-only — noting this for T's benefit even though it's not a new wireframe state.) | N/A. |
| **Anonymous viewing Security Team** | 403, no wireframe surface (see box above). | N/A. |
| **Member viewing a PUBLIC or UNLISTED group's header** | No privacy badge — silent, matches directory-card convention. | N/A. |

---

## 3. Teaching-copy callout (verbatim tooltip text)

All four strings below go into `HelpText::all()` (append-only, per the
established contract) exactly as written. Character counts included (all
comfortably under 200, matching the `visibility.*` keys' own length range
so tooltips stay visually consistent across the two axes).

```
COPY — proposed HelpText keys (F consumes verbatim)
====================================================

'privacy.public' =>
  "Public: visible to everyone, including anonymous visitors — listed in
  the group directory and searchable. This is the default for every group."
  (152 chars)

'privacy.unlisted' =>
  "Unlisted: reachable only by direct link — left out of the group
  directory. Seeded on the demo for reference; hiding from the directory
  listing isn't enforced by this build yet."
  (191 chars)

'privacy.private' =>
  "Private: hidden from everyone except members — non-members get a
  \"not found\" page and it never appears in the directory or search.
  This is live and enforced on the demo."
  (177 chars)

'privacy.vs_invite_only' =>
  "Private vs Invite Only: Private HIDES the group entirely from
  non-members (404-style). Invite Only keeps the group fully visible —
  it only closes the join/request path."
  (176 chars)
```

Notes on honesty (per brief's "POC scope" instruction):
- `privacy.public` and `privacy.private` both say "live" / state enforced
  behavior plainly, matching AC #2-#5 (403, directory omission, node
  hiding) which this story actually ships and tests.
- `privacy.unlisted` is the one key that must NOT claim enforcement — the
  brief only enforces `private`; `unlisted` is a seeded/allowed value with
  no access-hook or view-filter behavior wired in this story. Copy says so
  explicitly ("isn't enforced by this build yet") rather than implying a
  directory-hiding behavior that doesn't exist, mirroring the honesty
  pattern already used in `visibility.invite_only`'s "This is live on the
  demo" phrasing (stated only when true).
- `privacy.vs_invite_only` is the teaching key AC #8 requires — it
  explicitly contrasts the two axes so a reader doesn't conflate "Invite
  Only" (join-axis, `field_group_visibility`) with "Private" (view-axis,
  `field_group_privacy`), which is precisely the confusion #134's two-axis
  model exists to prevent.

## Existing components/patterns reused

- `.gc-badge` / `.gc-badge--{variant}` (already used for type + visibility
  badges in both the directory card and the group header) — no new badge
  component invented; `gc-badge--warning` chosen as the variant since no
  existing variant maps to "restricted/hidden" and `warning` is the closest
  existing semantic (used nowhere else yet per `grep`, confirmed available).
- `data-do-tooltip` + `do-chrome-info`-style trigger contract (span,
  `tabindex="0"`, `role="note"`, `aria-label`) — copied verbatim from the
  `#122` lead-section ⓘ precedent (`group--full.html.twig` lines 113-127);
  the do_chrome/tooltips JS library is already globally attached, so no new
  library attach is needed.
- `HelpText::all()` append-only array — same file, same pattern as every
  prior B-story/SC-story addition.
- Card badge row (`.gc-directory-card__badges`) and header badge row
  (`.gc-group-header__badges`) — both pre-existing; the privacy badge is a
  third sibling span in each, not a new row/section.

## Open questions for approval

1. **Badge variant color** — I chose `gc-badge--warning` for Private (no
   existing "restricted" semantic in `chrome.css`/`primitives.css` token
   set). If the human prefers reusing `gc-badge--primary` (matching the
   type badge) or a new low-fi neutral, that's a one-line CSS-class swap in
   F's implementation — flagging so O can confirm before F builds, since I
   did not invent a new token, only picked among existing ones.
2. **`gc-privacy-badge` as an additional class vs. the sole class** — AC #9
   specifies selector `.gc-privacy-badge[data-do-tooltip]`. I've kept
   `gc-badge` + `gc-directory-card__privacy` / `gc-group-header__privacy`
   alongside it for BEM/visual consistency with the existing badge system.
   If F finds this collides with existing `gc-badge` CSS in an unwanted
   way, dropping the extra classes and keeping only `gc-privacy-badge` (styled
   standalone) is an equally valid implementation — noting as an open
   question rather than dictating F's CSS.

No other open questions — the copy is fully specified, both render
locations reuse landed markup patterns exactly, and the empty/absent state
(no badge on non-private groups) requires no separate design.
