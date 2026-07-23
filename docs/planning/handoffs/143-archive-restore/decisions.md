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
