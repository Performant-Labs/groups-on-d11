# Wireframe — #143 MC-5 Group archiving RESTORE action

Run slug: `143-archive-restore`. Mode: (a) generated low-fi.

Matches the `do_group_membership` form-per-action pattern (`RemoveMemberForm` +
`ManageMembersController`): every action is a `ConfirmFormBase`-derived form
(real `<button type="submit">`), gated by a single shared `_custom_access`
callback, exposed as a local task tab on the group canonical.

---

## Surface 1 — "Restore group" local task tab

Tab order on `entity.group.canonical` mirrors `do_group_membership.links.task.yml`
(`weight: 20` for Manage members). Restore is a new tab, weighted after Members
so archived-group actions read left-to-right as: View / Edit / Members / Restore.

```
+----------------------------------------------------------------------+
|  Legacy Infrastructure  [Archived]                                   |
|  --------------------------------------------------------------      |
|  [ View ] [ Edit ] [ Members ] [ Restore group ]                     |
+----------------------------------------------------------------------+
```

- **Tab label:** "Restore group" — route `do_group_extras.restore`, `base_route:
  entity.group.canonical`, `weight: 30` (after Members' 20).
- **Behavior:** clicking navigates to `/group/{gid}/restore`.
- **Visibility / disabled state:** tab does not render (not merely disabled) when:
  - the current user fails the access check (403) — non-privileged users never
    see it, matching `ManageMembersController::access` precedent;
  - the group's `field_group_type` term is NOT "Archive" — nothing to restore,
    so an active group (e.g. "Working group"-typed) shows only View / Edit /
    Members.
- Non-archived-group state (for contrast, not a new surface — existing tabs only):

```
+----------------------------------------------------------------------+
|  Weekly Standup                                                       |
|  --------------------------------------------------------------      |
|  [ View ] [ Edit ] [ Members ]                                        |
+----------------------------------------------------------------------+
```

---

## Surface 2 — Restore confirmation form at `/group/{gid}/restore`

`RestoreGroupForm extends ConfirmFormBase` — no custom markup invented; the
confirm/cancel actions are core's native rendering (real `<button type="submit">`
for confirm, `<a>` for cancel per `ConfirmFormBase` convention, matching
`RemoveMemberForm`).

```
+----------------------------------------------------------------------+
|  Restore the archived group 'Legacy Infrastructure'?                 |  <- page title (getQuestion)
|                                                                        |
|  <p id="do-group-extras-restore-description">                        |
|  This group is currently archived (type: Archive). Restoring it      |
|  returns it to the group directory (/all-groups), lets members       |
|  create content again, and removes the "Archived" badge.             |
|  </p>                                                                 |
|                                                                        |
|  Set group type to *                                                  |
|  [ Working group                                    v ]  <select>    |  <- focus lands here first
|  Archiving is expressed by group type, so restoring requires          |
|  choosing a non-Archive type. ("Archive" is excluded from this list.) |
|                                                                        |
|  [ Restore group ]   Cancel                                          |  <- real <button type="submit"> + <a>
+----------------------------------------------------------------------+
```

- **Page title / question (`getQuestion`):** "Restore the archived group
  '{Group label}'?" — names the group and implies its current archived state.
- **Description (`getDescription`):** one paragraph, `id` set so the confirm
  button's `aria-describedby` can point to it (see Surface 3). States the
  current archived fact ("This group is currently archived...") and the three
  consequences: reappears in `/all-groups`, members regain content-create,
  badge disappears.
- **Target-type select** — `#type => 'select'`, `#title => 'Set group type to'`,
  options = all `group_type` vocabulary terms **except** "Archive"
  (Geographical, Working group, Distribution, Event planning per survey.md),
  `#default_value` = the "Working group" term id. Helper text below the select
  (`#description`) explains the exclusion rationale.
- **Confirm button (`getConfirmText`):** "Restore group" — rendered as
  `<button type="submit">` by `ConfirmFormBase` natively (no override needed;
  call out explicitly per AC-6/AC-4, same as `RemoveMemberForm` which only adds
  a CSS class, not custom markup).
- **Cancel link (`getCancelUrl`):** "Cancel" → `entity.group.canonical` for
  this group (mirrors `RemoveMemberForm::getCancelUrl`, adjusted target).
- **Success flash + redirect:** on submit, `field_group_type` is reassigned to
  the chosen term; `messenger()->addStatus("Group '{label}' has been restored
  and set to type '{new type}'.")`; `$form_state->setRedirectUrl()` →
  `entity.group.canonical`.
- **Race / error state:** if the group is no longer Archive-typed by the time
  `submitForm` runs (e.g. two admins, one already restored it), do NOT reassign
  the type again. Instead:

```
+----------------------------------------------------------------------+
|  [!] This group is no longer archived — no changes were made.        |
+----------------------------------------------------------------------+
```

  `messenger()->addWarning("This group is no longer archived — no changes
  were made.")`, then redirect to the group canonical (same redirect target as
  the success path; no state change).

---

## Surface 3 — Accessibility annotations (WCAG 2.2 AA, AC-6)

- **Visible labels:** the select's `#title` renders as a real `<label
  for="...">`, not a placeholder — Drupal Form API default, no override needed.
- **`aria-describedby`:** the confirm `<button>`'s `#attributes['aria-describedby']`
  is set to the description paragraph's `id` (e.g.
  `do-group-extras-restore-description`) so screen readers announce the
  consequences paragraph when the button receives focus. This is additive to
  `ConfirmFormBase`'s default markup — set it in `buildForm()` after calling
  `parent::buildForm()`, same pattern `RemoveMemberForm::buildForm()` uses to
  add a CSS class to `$form['actions']['submit']`.
- **Keyboard / tab order:** target-type `<select>` -> Confirm `<button>` ->
  Cancel `<a>`. This is the natural DOM order `ConfirmFormBase` produces once
  the select is added to `$form` before the parent's `actions` build — no
  custom `tabindex` needed.
- **Escape:** triggers the same action as Cancel (native browser/OS behavior
  for a same-page form with a Cancel link is not automatic — call out that if
  a JS "Escape closes/cancels" affordance is desired, it must invoke the
  Cancel link's `href`; default expectation is browser-native focus/Escape
  handling on the `<select>` only, no modal is involved since this is a full
  page, not a dialog. **No special Escape-key JS is required** unless the
  human wants one; flagging as an open question below.)
- **Focus ring:** relies on the subtheme's default focus-visible styling
  (already applied globally); this wireframe does not introduce new CSS.
- **Real `<button type="submit">`:** explicitly confirmed — `ConfirmFormBase`
  renders `$form['actions']['submit']` as `#type => 'submit'`, which Drupal's
  form renderer emits as `<button type="submit">` (Drupal 10/11 core does NOT
  use `<input type="submit">` for actions built via `Actions` element trays in
  the active theme's forms; verify against the live `do_group_membership`
  render during F, but the pattern is identical/inherited). NOT a link styled
  as a button; NOT `<input>`.
- **Empty-vocabulary edge case:** if the `group_type` vocabulary contained only
  the "Archive" term (so the filtered select would have zero options),
  `RestoreGroupForm::buildForm()` must refuse to render the normal form and
  instead show:

```
+----------------------------------------------------------------------+
|  Restore is unavailable: no non-Archive group type exists to          |
|  restore this group to. Add a group type via /admin/... first.       |
+----------------------------------------------------------------------+
```

  This will not occur at runtime given `step_720` seeds 4 non-Archive terms,
  but the form must defend against it rather than rendering a broken empty
  `<select>`.

---

## Surface 4 — Round-trip note (for T/F)

No new "Archive" action ships in this story. Re-archiving a restored group uses
the **existing group edit form's Group Type widget** (already surfaced by
`step_720`) to set the type back to "Archive". The e2e round-trip
(`tests/e2e/group-restore.spec.ts`) exercises:

1. Seeded state: "Legacy Infrastructure" is Archive-typed (badge visible,
   Restore tab visible, node-create denied).
2. Visit `/group/{gid}/restore`, select "Working group" (default), submit.
3. Assert redirect to canonical, success message, badge gone, Restore tab
   gone, node-create allowed.
4. Visit `/group/{gid}/edit`, set Group Type back to "Archive", save
   (existing widget, no new UI).
5. Assert badge returns, Restore tab returns.

---

## Open questions for approval

1. Escape-key behavior on the confirm form: default to no custom JS (relying on
   native `<select>`/browser Escape semantics) since this is a full page, not a
   modal dialog — confirm this reading is acceptable, or specify a JS
   Cancel-on-Escape affordance.
2. Exact confirm-button `#attributes['aria-describedby']` wiring point (in
   `buildForm()` post-`parent::buildForm()`, mirroring `RemoveMemberForm`'s
   button-class pattern) — flagging for A to validate against the live render,
   not a design ambiguity.
