# Wireframe — #144 MC-6 Guided Preview page (`/group/{group}/created`)

Mode: (a) generated low-fi ASCII wireframe.

## Context

Single new screen. A group creator lands here immediately after submitting
`/group/add/community_group`. Not a wizard — one confirmation/nudge screen.
Reuses plain Drupal admin-theme conventions already used by the Manage Members
surface in `do_group_membership` (headings, paragraphs, plain link lists via
`#type => 'link'`) — no custom design system, no new components.

There is only ONE data state to design (see "States covered" below) because
the page's precondition (group + creator's Organizer membership) is guaranteed
to exist by the hooks that ran moments earlier in the same request lifecycle
(AC-1/AC-3). The "what if someone else hits this URL" case is an access-denied
(403) case, out of scope for this wireframe per the brief — noted below only
so T/F don't have to guess it was overlooked.

---

## Screen: Guided preview ("Your group is ready")

Route: `/group/{group}/created`
Page `<title>` (from routing.yml `_title`, override-able by controller):
`Your group is ready`

```
+--------------------------------------------------------------------+
| [ Drupal admin theme masthead / primary nav / breadcrumb — reused, |
|   no changes ]                                                     |
|   Breadcrumb: Home > Groups > {Group Name}                         |
+--------------------------------------------------------------------+
|                                                                     |
|  <h1>  Your group "{Group Name}" is ready!                   </h1> |  <- FIRST content
|                                                                     |     element after
|  <p>   You're the Organizer of this group, which means you    </p> |     landmarks; no
|        can edit its details, manage who joins, and moderate        |     JS focus-forcing
|        its content.                                                |     needed — DOM
|                                                                     |     order alone
|  <h2>  What's next?                                          </h2> |     satisfies AC-5's
|                                                                     |     "focus lands
|  <ul class="do-group-membership--next-steps">                     |     sensibly" clause.
|    <li> <a href="{edit_form}">                                     |
|           Edit "{Group Name}" details               </a>      </li>|
|         one-line: opens the group's edit form (entity.group.edit_  |
|         form) — add description, links, image, etc.                |
|                                                                     |
|    <li> <a href="{manage_members}">                                |
|           Manage members of "{Group Name}"          </a>      </li>|
|         one-line: opens the Manage Members screen (do_group_       |
|         membership.manage_members) — invite/approve/assign roles.  |
|                                                                     |
|    <li> <a href="{canonical}">                                     |
|           View "{Group Name}"                       </a>      </li>|
|         one-line: opens the group's normal canonical/front page —  |
|         "see what members will see."                               |
|  </ul>                                                             |
|                                                                     |
+--------------------------------------------------------------------+
| [ Drupal admin theme footer — reused, no changes ]                 |
+--------------------------------------------------------------------+
```

### Heading hierarchy (AC-5)
- `h1` = the confirmation heading (`Your group "{Group Name}" is ready!`). This
  is the page's ONLY h1 and the first content element in the DOM after the
  theme's standard header/nav/breadcrumb landmarks — satisfies "focus lands
  sensibly on redirect" without any JS focus-forcing, per the brief's note
  that Drupal's default admin theme does none.
- `h2` = "What's next?" — groups the three CTAs under a labelled section,
  one level below h1. No h3+ needed; this is a one-section page.
- No heading level is skipped.

### Link text (AC-5 — no bare "click here")
Every CTA repeats the group name and states the destination/action in the
visible link text itself (not in surrounding prose only), so each link is
unambiguous when read out of context (screen-reader "list all links" mode):
- "Edit "{Group Name}" details"
- "Manage members of "{Group Name}""
- "View "{Group Name}""

These render as plain Drupal `#type => 'link'` render elements — same idiom
already used elsewhere in this module (e.g. Manage Members action links) —
not raw `<a>` markup, not buttons (these are navigations, not form submits).

### Color / contrast (AC-5)
No new hex values. `create-group.css` (new file, same convention as the
existing `manage-members.css` + library-entry pattern) should style spacing/
layout only (e.g. list marker removal, vertical rhythm between the three CTA
items) and reuse the active subtheme's existing color/link/focus tokens.
Wireframe intentionally specifies NO colors.

### Copy notes
- Group name is interpolated with the group's actual label (not a slug) and
  wrapped in the same quoting style consistently (heading + all three links)
  so a screen-reader user hears "Your group Foo is ready" then later "Edit Foo
  details" etc. — reinforces which group these actions apply to on a page
  that could otherwise be reached to review multiple groups over time (e.g.
  the user bookmarks it, or an Organizer of several groups compares tabs).
- The Organizer statement is a factual confirmation, not a call to action —
  kept in its own `<p>`, separate from the `<h2>`/CTA list, so a screen reader
  user gets "you are now Organizer" as one discrete announcement before the
  list of things they can do about it.
- No dismiss/close control and no "skip" link — this is a one-way landing
  page reached by redirect only; the three CTAs (plus normal site nav) are
  the only ways to leave it. Nothing to design as "empty state" here since
  there's no listing/collection UI on this page at all.

---

## States covered

| State | Applicable? | Notes |
|---|---|---|
| Normal (only state) | Yes | As drawn above. Group name, Organizer statement, and three CTAs always present — this page has no data list, so there is no empty/one/many state to design. |
| Empty | N/A | No collection is rendered on this page. |
| Error / access-denied | Out of scope for this wireframe | Per brief: only the group's owner, an Organizer (`administer members`), or a site admin may load this route. Anyone else gets Drupal's standard 403 access-denied page — no custom copy or layout for that path is in scope here. Flagging this explicitly so F/T don't read its absence as an oversight. |

---

## Handoff note

**What was designed:** one new low-fi screen, the guided-preview landing page
at `/group/{group}/created`, reached once per group-creation via the
brief's AC-3 redirect. ASCII wireframe (ratified format is acceptable per D's
own rules for a simple single-screen layout — no icons/glyphs needed at all
for this screen, so the SVG-glyph guidance in the playbook doesn't apply
here).

**What was reused:** Drupal's plain admin-theme render-array conventions
already established by this module's Manage Members surface — `#type =>
'link'` CTA elements, a plain `<ul>` action list, headings + paragraphs, and
the existing `manage-members.css` + `libraries.yml` attach pattern (mirrored
here as `create-group.css` / a new `group_created_preview` library entry, per
the survey's Files-to-touch list). No new visual language, no new components.

**WCAG-relevant annotations:** h1-then-h2 hierarchy with the h1 as the very
first content element (satisfies "focus lands sensibly on redirect" without
JS); all three CTA link texts are self-descriptive (repeat the group name +
action, never "click here"); no new colors specified — contrast is inherited
from existing subtheme tokens via the new CSS file, which should only add
spacing/list-reset rules.

**Open questions for the architecture-reviewer / feature-implementor:**
1. Confirm whether the controller should render via an inline render array or
   a new Twig template (survey/brief leave this as F's call) — this wireframe
   is agnostic to that choice; the DOM structure it specifies (h1 -> p -> h2
   -> ul>li>a, in that order) should hold either way.
2. Confirm the exact three route names to link to
   (`entity.group.edit_form`, `do_group_membership.manage_members`,
   `entity.group.canonical`) resolve as expected for a community_group — this
   wireframe assumes the survey's route list (section 6) is accurate but D
   did not independently re-verify route names against `do_group_membership.routing.yml`.
3. None of the above block approval of the wireframe's layout/copy/hierarchy
   itself — they're implementation-detail confirmations, not open design
   questions.

## Approval
Approved by operator (O, autonomous overnight run per aangelinsf 2026-07-22 authorization) at 2026-07-23T07:43:07Z. Sound wireframe: correct heading hierarchy, descriptive link text, no new color tokens, reuses existing Manage Members conventions. Auto-approved per lean-POC-pipeline D-gate rule (human D-gate skipped for this run).
