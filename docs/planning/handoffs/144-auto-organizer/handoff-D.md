# Handoff-D: Phase 2 - Guided Preview page wireframe (#144 MC-6)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer
**Mode:** (a) generated low-fi
**Wireframe:** `docs/planning/handoffs/144-auto-organizer/wireframe.md`

## Screens & states covered
- **Guided preview page** (`/group/{group}/created`) — the only screen in scope. Single "normal"
  state: confirmation `h1` naming the group, an Organizer-status `<p>`, an `h2` "What's next?"
  label, and a plain `<ul>` of three CTA links (edit group, manage members, view group). Copy for
  this state is truthful and specific (group name interpolated into every heading/link, not just
  the page title).
- **Empty/one/many:** not applicable — this page renders no collection/list of data, only a fixed
  set of three CTAs that are always present.
- **Error/access-denied:** explicitly out of scope per the brief (standard Drupal 403 for a
  non-owner/non-organizer/non-admin visitor) — noted in wireframe.md so its absence reads as
  intentional, not overlooked.

## Existing components/patterns reused
- Plain Drupal admin-theme render-array conventions already used by this module's Manage Members
  surface: headings + paragraphs, a plain `<ul>` action list, `#type => 'link'` CTA elements (not
  raw `<a>`, not buttons — these are navigations).
- `ManageMembersController`'s DI (`ContainerInjectionInterface::create()`) and access-callback
  shape as the structural precedent for the new controller (read directly, not redesigned).
- CSS/library-attach convention identical to `manage-members.css` +
  `do_group_membership.libraries.yml` (new `create-group.css` + `group_created_preview` library
  entry), styling spacing/list-reset only — no new colors, contrast inherited from existing
  subtheme tokens.

## WCAG 2.2 AA annotations
- `h1` (confirmation heading) is the first content element in DOM order after the theme's
  standard header/nav/breadcrumb landmarks — satisfies "focus lands sensibly on redirect" (AC-5)
  without any JS focus-forcing, matching the brief's note that the admin theme does none.
- `h2` ("What's next?") is one level below `h1`, no level skipped; page has no further heading
  levels.
- All three CTA link texts are self-descriptive out of context (repeat group name + action) —
  no "click here" anywhere.
- No new hex/color values specified anywhere in the wireframe; new CSS should only add
  spacing/list-reset rules and inherit color/focus/contrast from the active subtheme.

## Open questions for approval
1. Controller render style (inline render array vs. new Twig template) is left as F's call per
   the brief/survey — the wireframe's DOM order (h1 -> p -> h2 -> ul>li>a) should hold either way.
   Not a design ambiguity, just noted so F knows the wireframe doesn't dictate the answer.
2. D did not independently re-verify the three target route names
   (`entity.group.edit_form`, `do_group_membership.manage_members`, `entity.group.canonical`)
   against `do_group_membership.routing.yml` — relied on survey §6. Flagging for F/A to confirm
   at implementation time; does not block wireframe approval (implementation detail, not
   layout/copy/hierarchy).
3. None of the above block sign-off on the wireframe's layout, copy, or heading hierarchy itself.

## Approval
[To be filled by O: "Approved by operator <ISO timestamp>" — D does not self-approve.]
