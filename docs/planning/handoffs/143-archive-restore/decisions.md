# Decisions — #143 archive restore

Append-only.

## O — Phase 1 (survey + brief)

**Decided.**
- Archive mechanism confirmed = `field_group_type` taxonomy term ref, term "Archive". Not a boolean.
- RESTORE ships as a new form + route in `do_group_extras` (module already owns archive enforcement), analogous to `do_group_membership`'s form-per-action pattern.
- Confirmation form extends `ConfirmFormBase` — guarantees real `<button>` submit natively; simplest WCAG-compliant path.
- Target-type on restore: user picks via `<select>` prefilled `Working group`. No shadow field to remember prior type.

**Assumed (to verify in A).**
- The Organizer group role holds `administer group` permission on `community_group` (or an equivalent perm covering group settings). If not, A adjusts the access check.
- BrowserTestBase functional test can self-install `group_type` vocab + Archive term + community_group; verified path since step_720 does this at seed time via entity API (portable).

**Hedged.**
- Whether to add a HelpText tooltip key for the restore button. Default: skip (in-form label + description suffice for POC); reconsider if S flags it.

**Evidence.**
- `docs/groups/scripts/step_720_group_types.php` (term model + widget surfacing).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (Archive detection + enforcement).
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` (badge chrome — leave untouched; will re-render automatically).
- `docs/groups/modules/do_group_membership/{routing.yml,src/Controller/ManageMembersController.php}` (analogous access pattern).
- `docs/groups/config/group.role.community_group-groups_moderate.yml` (synchronized outsider role).
- #78 / #92 / #128 issue bodies read for context.

## D — Phase 2 (wireframe)

**Decided.**
- Wireframe covers exactly 4 surfaces: (1) "Restore group" local task tab, visible only when archived + access-permitted; (2) restore confirmation form at `/group/{gid}/restore` extending `ConfirmFormBase` (question/description/target-type select/confirm/cancel/success/race-error states); (3) WCAG 2.2 AA annotations (labels, `aria-describedby`, tab order, real `<button type="submit">`, empty-vocab guard); (4) round-trip note clarifying re-archive uses the existing group edit form, no new Archive action in this story.
- Tab weight set to `30` (after Members' `20`) so tab order reads View / Edit / Members / Restore group.
- Confirm form mirrors `RemoveMemberForm` exactly: `parent::buildForm()` first, then post-hoc `$form['actions']['submit']['#attributes']` additions (class for Remove, `aria-describedby` for Restore) — no custom markup invented.
- Race-condition state (group un-archived before submit completes) added as an explicit warning-message + no-op-redirect state, since AC-6/AC-9 imply server-side correctness beyond the happy path.
- Empty-vocabulary edge case (all-Archive vocab) specified as a hard refusal-to-render state per instructions, even though step_720 seeding makes it unreachable at runtime.

**Assumed.**
- `ConfirmFormBase`'s default submit action renders as `<button type="submit">` in this theme (matches `RemoveMemberForm` precedent) — flagged for A to double check against a live render rather than re-verified here.
- No custom Escape-key JS is needed (full-page form, not a modal) — listed as an open question rather than decided outright.

**Hedged.**
- Whether a JS Cancel-on-Escape affordance is wanted — left as an open question for human sign-off (Surface 3 / Open questions section).

**Evidence.**
- `docs/groups/modules/do_group_membership/do_group_membership.links.task.yml`, `.routing.yml`, `src/Form/RemoveMemberForm.php`, `src/Controller/ManageMembersController.php` (tab/route/form/access pattern mirrored).
- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php` (confirmed badge auto-disappears once `field_group_type` no longer resolves to "Archive"; no chrome changes needed).
- Wireframe: `docs/planning/handoffs/143-archive-restore/wireframe.md`.

## O — D-gate approval (2026-07-22)

**Decided.**
- Wireframe APPROVED by operator (via coordinator relay) 2026-07-22.
- Q1 (Escape-key JS): SKIP — matches `RemoveMemberForm` precedent; POC bar. No custom JS ships.
- Q2 (aria-describedby wiring point): flagged for A to validate against live render; not a design-gate concern.

**Evidence.**
- `docs/planning/handoffs/143-archive-restore/wireframe.md` (unmodified from D output).
