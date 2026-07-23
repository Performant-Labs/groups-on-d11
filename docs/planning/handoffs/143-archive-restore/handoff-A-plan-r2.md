# Handoff-A: Phase 3 round 2 — #143 MC-5 Group archiving RESTORE action (up-front plan review, delta)

**Date:** 2026-07-22
**Branch:** 143-archive-restore
**Brief reviewed:** `docs/planning/handoffs/143-archive-restore/brief.md`
**Reuse map:** `docs/planning/handoffs/143-archive-restore/survey.md`
**Wireframe:** `docs/planning/handoffs/143-archive-restore/wireframe.md`
**Verdict:** PASS

## Summary
Targeted verify of the r1 BLOCK #1 fix + NIT foldings. All four r2 checks pass.
No new BLOCKs introduced by the amendment.

## Verified

1. **r1 BLOCK #1 fixed (perm string).** §Design outline / Access now reads
   `$group->hasPermission('edit group', $account) || $account->hasPermission('administer group')`.
   Confirmed against `docs/groups/config/group.role.community_group-organizer.yml`: Organizer
   grants `'edit group'` + `'administer members'` (no `'administer group'`). AC-1 now
   satisfiable.
2. **Site-admin escape hatch preserved.** `$account->hasPermission('administer group')` remains
   as the site-wide (`user.permissions`) fallback — same shape as MMC. Valid site-wide perm
   held by User 1 / admin roles.
3. **NITs folded reasonably.**
   - NIT #4 (task key rename): plugin key is now `do_group_extras.restore` (no `_tab` suffix),
     matches MMC precedent `do_group_membership.manage_members`.
   - NIT #5 (DI note): direct `entity_type.manager` injection called out as minimum-abstraction
     choice; explicitly flagged for A-dup so no false-positive later.
   - NIT #6 (aria-describedby id mechanism): both wiring paths spec'd — post-`parent::buildForm()`
     wrapper injection with id `do-group-extras-restore-desc-{gid}` OR embed `<p id=…>` inside
     `getDescription()`'s TranslatableMarkup. F picks. Acceptable.
4. **No new BLOCKs.** Rationale paragraphs and A r1 outcome section are documentary only; no
   architecture drift introduced.

## Findings
Plan is now consistent with existing patterns. Zero blocks, zero new warns.

## Patterns referenced
- `docs/groups/config/group.role.community_group-organizer.yml` (perm grant verification)
- `docs/groups/modules/do_group_membership/{do_group_membership.links.task.yml, src/Form/RemoveMemberForm.php}` (task-key + form patterns)
