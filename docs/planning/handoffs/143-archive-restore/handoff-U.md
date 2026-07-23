# Handoff-U: Phase 8 - #143 MC-5 Group archiving RESTORE action

**Date:** 2026-07-22
**Branch:** 143-archive-restore (worktree `_worktrees/groups-archive-restore`), commit 116e85d
**Live site:** `https://gm143-groups-on-d11.ddev.site` (DDEV instance `gm143-groups-on-d11`)
**Handoff-T-green reviewed:** `docs/planning/handoffs/143-archive-restore/handoff-T-green.md`
**Wireframe reviewed:** `docs/planning/handoffs/143-archive-restore/wireframe.md`

## Environment

- `ddev describe gm143-groups-on-d11` confirmed `running` before starting.
- Site responded 200 at `/user/login` before driving.
- Session obtained via `ddev drush uli` one-time login link (fresh link generated per use;
  one-time links are single-use, so subsequent script runs reused a saved Playwright
  `storageState` rather than re-consuming the link).
- Driven with a throwaway Node/Playwright script (`.u-walk-all.mjs`, deleted after the run) in
  the worktree root, run headless via `chromium.launch()`, since no interactive Playwright-MCP
  browser tool was available in this session -- mechanics are equivalent to the required
  contract (real headless browser, real navigation, no code edited).
- Target group: gid=8, "Legacy Infrastructure", confirmed via `drush php:eval` (`status=0`,
  `field_group_type` = "Archive") before the walkthrough, and restored to that same state
  afterward (no lasting data change from this verification pass).

## Step 1 -- Archived-state observability (before restore)

- `/admin/group` -> "Legacy Infrastructure" link visible: **PASS**.
- `/group/8` canonical:
  - `span.group__archived-badge` present, text "Archived", with a `data-do-tooltip` attribute
    explaining the read-only state and `tabindex="0"` (accessible, focusable). **PASS.**
    Screenshot: `01-admin-group.png`, `02-group-canonical-archived.png`, closeup `11-badge-closeup.png`.
  - **Observation (not a defect):** the wireframe's Surface 1 ASCII mockup shows the badge
    inline next to the `<h1>` ("Legacy Infrastructure [Archived]"). The live badge instead
    renders as a gray "ARCHIVED" pill lower on the page, near the Stream/Events/Members/About
    sub-tabs -- this is pre-existing `do_chrome`/`ArchivePinHooks` placement, not something
    #143 introduced (T's handoff already spot-checked and confirmed this same rendering).
    The element itself (selector, text, tooltip) matches spec; only the visual position
    diverges from the schematic mockup. Flagging for S's visual-conformance judgment, not
    blocking here per U's charter (behavior, not pixel layout).
  - Tab strip: `View / Edit / Delete / All entities / Manage members / Revisions / Nodes /
    Restore group` -- "Restore group" is the last tab, consistent with weight 30 (highest).
    **PASS.** Screenshot: `03-tab-strip.png`.
  - Keyboard reachability: Tab-key traversal reached "Restore group" tab; screenshot shows a
    clear visible blue focus ring around the tab. **PASS.** Screenshot: `04-restore-tab-focused.png`.

## Step 2 -- Restore confirmation form (`/group/8/restore`)

All assertions verified via DOM/attribute inspection (locator/evaluate calls), screenshot
`05-restore-form.png` (full page) and `06-restore-form-detail.png`:

- Page title / `<h1>`: `Restore the archived group 'Legacy Infrastructure'?` -- **PASS**, exact match.
- Description `<p>`: text matches wireframe verbatim ("This group is currently archived (type:
  Archive). Restoring it returns it to the group directory (/all-groups), lets members create
  content again, and removes the "Archived" badge."). **PASS.**
- `<p id="do-group-extras-restore-desc-8">` -- id present exactly as specified (gid-scoped).
  **PASS.**
- `<select id="edit-group-type">` -- options: Distribution, Event planning, Geographical,
  Working group (4 options); "Archive" is **not** present. Default selected = "Working group"
  (tid 22). Label "Set group type to" correctly associated via `<label for>`. Helper text below
  select present and matches wireframe copy. **PASS** on all counts.
- Confirm button: `<button type="submit">`, text "Restore group", `aria-describedby` =
  `do-group-extras-restore-desc-8` -- matches the description `<p>`'s id exactly (AC-6 wiring
  confirmed programmatically, not just visually). **PASS.** Zero `<input type="submit">`
  elements found on the page (`inputSubmitCount: 0`). **PASS** -- confirms real `<button>`, not
  a native `<input>` fallback.
- Cancel: `<a>` with text "Cancel", `href="/group/8"` (group canonical). **PASS.**
- Focus management: JS-focusing the select first, then two `Tab` presses, produced DOM/keyboard
  order **select -> "Restore group" button -> "Cancel" link** -- matches wireframe Surface 3
  exactly. **PASS.**
- DOM focusable order within the form (`select, button, a[href], input`) confirms select first,
  then 4 hidden inputs (Drupal's form-build-id/token/form_id -- expected, not focusable/tabbable
  since `type=hidden`), then the submit button, then the Cancel link. **PASS** -- no stray
  focusable elements interrupt the intended tab order.

## Step 3 -- Submit restore (default "Working group")

- Clicked "Restore group"; redirect landed on `/group/8` (canonical). **PASS.**
- Flash message: `Group 'Legacy Infrastructure' has been restored and set to type 'Working
  group'.` rendered as a `role=status` message region. **PASS**, exact copy match.
- `span.group__archived-badge` no longer visible. **PASS.**
- "Restore group" tab no longer in the tab strip (remaining: View / Edit / Delete / All
  entities / Manage members / Revisions / Nodes). **PASS.**
- Group-type chip on the canonical now reads "Working group" (visual confirmation).
  Screenshot: `07-post-restore-canonical.png`.

## Step 4 -- Re-archive via existing edit form

- `/group/8/edit` -> found "Group Type" `<select id="edit-field-group-type">` (label "Group
  Type"), selected "Archive", clicked the edit form's native Save button (`<input
  id="edit-submit">` -- this form's Save control is a core `input[type=submit]`, an existing,
  pre-#143 form, out of this story's scope). Redirect landed on `/group/8`. **PASS.**
- Flash message: "Community Group Legacy Infrastructure has been updated." (pre-existing core
  copy, not part of #143). **PASS** (informational, not a #143 assertion).
- `span.group__archived-badge` back ("ARCHIVED" pill visible). **PASS.**
- "Restore group" tab back in the tab strip. **PASS.**
  Screenshots: `08-edit-form-before-rearchive.png`, `09-post-rearchive-canonical.png`.

## Step 5 -- Anonymous persona spot-check

- Logged out; visited `/group/8/restore` unauthenticated -> HTTP 403, rendered Drupal's native
  "Access denied" / "You are not authorized to access this page." page, no redirect to login.
  Header shows "Log In" link confirming anonymous state. **PASS** (matches brief AC-3/AC-5:
  403 for non-privileged users; either 403 or login-redirect was acceptable -- got a clean 403).
  Screenshot: `10-anon-restore-403.png`.

## Console errors

`page.on('console')` (error-level) and `page.on('pageerror')` listeners were attached for the
entire authenticated walkthrough (steps 1-4, all pages visited: `/admin/group`, `/group/8`,
`/group/8/restore` x2, `/group/8/edit`, plus keyboard-navigation passes). **Zero console errors
or page errors observed** across the whole round-trip.

## Post-walkthrough state verification

`drush php:eval` confirms gid=8 ended the session at `status=0`, `field_group_type=Archive` --
identical to its pre-walkthrough seeded state. No lasting data changes from this verification
pass (the mid-walkthrough restore->re-archive cycle is exactly the round-trip AC-8 requires, and
the final drush check confirms it round-tripped cleanly back to the seed's baseline).

## Discrepancies vs. wireframe

1. **Badge visual placement** (see Step 1 observation above) -- cosmetic/layout only, element
   itself matches spec exactly (selector, text, ARIA affordances). Not a behavioral defect;
   flagged for S.
2. No other discrepancies found. DOM structure, ARIA wiring, focus order, button semantics,
   copy strings, and redirect targets all matched the wireframe and brief precisely.

## Verdict: PASS

All AC-relevant UI behavior verified live in a real headless browser along the actual user
path (admin group list -> group canonical -> Restore tab -> confirmation form -> submit ->
restored canonical -> edit form -> re-archived canonical -> anonymous 403). Zero console
errors. One cosmetic (non-blocking) observation about badge placement relative to the
wireframe's schematic mockup, carried forward for S's visual/WCAG judgment -- does not affect
the PASS verdict since the badge element itself is correct, accessible, and functions exactly
as specified.

Ready for S.
