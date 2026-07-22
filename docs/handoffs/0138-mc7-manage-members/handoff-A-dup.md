# Handoff-A-dup: Phase 7 - Issue #138 (MC-7) Organizer manage-members UI + group roles + Groups Moderate  (anti-duplication gate)

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Diff base:** `origin/main` (c18f417)   **Diff head:** `ddcba91` (working tree)
**Reuse map:** `docs/handoffs/0138-mc7-manage-members/survey.md` §"Reuse & Analogous-Feature map"
**Verdict:** PASS

## Summary

F extended the analogous objects the Reuse map named; no parallel path was created. Group roles:
`community_group-organizer`/`community_group-moderator` are new siblings in the existing
`group.role.community_group-*` family, and `community_group-member.yml` is untouched (confirmed:
not present in the diff at all). Membership status is one new field
(`field_membership_status`) on the existing `community_group-group_membership` relationship type,
kept orthogonal to the pre-existing `group_roles` field — no second copy of role data, no parallel
relationship type. Groups-Moderate reuses Group 4.x's built-in synchronized-global-role mechanism
(`GroupRoleStorage`) via a plain config entity, not a hand-rolled access check. CRUD/status-
transition logic is centralized in one `GroupMembershipManager` service (per warn-1's mandate to
follow the playbook's `MyModuleManager` shape, not `do_notifications`'s inline-logic anti-pattern);
controller is access-only, forms are thin delegators that call `$this->manager->*()` for every
mutation. Scope discipline held: no changes to `do_chrome/PermissionMatrix.php`, no vestigial-role
files touched, no `grequest`/join-request flow added. One `warn`: a small "count active
Organizers" read-helper is copy-pasted from the manager into `ManageMembersForm` for UI-only
disable-before-attempt logic, because the manager's equivalent method is `protected`. This is
presentation-layer read duplication, not a second enforcement path (the manager's
`assertNotLastOrganizer()` remains the sole authoritative, server-side guard on every mutation) —
does not rise to a `block`.

## Findings

| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| 1 | warn | `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:418-423,454-462` | `relationshipStatus()` and the active-Organizer counting loop are copy-pasted from `GroupMembershipManager::relationshipStatus()`/`assertNotLastOrganizer()` (both `protected` there, so not reusable across classes as written). This is read-only UI logic (badge display, disable-before-attempt hint) — the manager's `assertNotLastOrganizer()` is still the one server-side enforcement point called on every mutation (`changeRole()`, `removeMember()`), so there is no duplicate enforcement path, only a duplicate read helper. | Low-priority follow-up: promote `relationshipStatus()` (and optionally a public `countActiveOrganizers(GroupInterface, string $roleId): int` convenience) to `public` on `GroupMembershipManager` and have `ManageMembersForm` call the service instead of re-implementing. Not required before merge. |

No parallel-path duplication found. Extension is clean.

## Verification against the 6 mandated checks

1. **Group roles** — `community_group-organizer.yml` and `community_group-moderator.yml` are new
   siblings with the same schema shape (`scope`, `admin`, `group_type`, `permissions`) as the
   pre-existing `admin`/`member` roles. `community_group-member.yml` does not appear in
   `git diff origin/main...HEAD -- docs/groups/` at all — confirmed unchanged. No parallel role
   scheme invented.
2. **Membership status** — `field.storage.group_relationship.field_membership_status.yml` +
   the matching `field.field.*` instance extend the existing
   `community_group-group_membership` relationship type (both declare
   `group.relationship_type.community_group-group_membership` as a config dependency). No new
   relationship type. `GroupMembershipManager::changeRole()` only ever touches the `group_roles`
   field; `changeStatus()`/`approvePending()`/`denyPending()` only ever touch
   `field_membership_status` — confirmed orthogonal, no cross-writes in either direction.
3. **Manager service centralization** — `ManageMembersController` holds only the shared `access()`
   callback (47 lines, no CRUD). All four Forms (`ManageMembersForm`, `AddMemberForm`,
   `ChangeRoleForm`, `RemoveMemberForm`) inject `GroupMembershipManager` and call
   `$this->manager->{addMember,changeRole,changeStatus,removeMember,approvePending,denyPending}()`
   for every state-changing action — confirmed via grep across all four form files. No
   controller/form re-implements a save/delete/status-transition independently of the manager.
4. **Groups-Moderate** — `group.role.community_group-groups_moderate.yml` sets `admin: true`,
   `global_role: groups_moderate`, `scope: outsider`, `group_type: community_group`, which is
   Group 4.x's own `GroupRoleStorage` synchronized-role config shape (not a custom
   `hook_group_access`/event subscriber). `ManageMembersController::access()` checks
   `$group->hasPermission('administer members', $account)`, Group's own permission-checking API —
   no hand-rolled parallel access-check. (The `scope: outsider` vs. brief-locked `scope: insider`
   correction is a T/S-level empirical-correctness question already documented in F's handoff and
   the brief's tests-that-look-wrong list — out of this gate's mandate per the task's own framing.)
5. **Scope discipline** — `git diff --stat` restricted to `docs/groups/` shows zero touches to
   `do_chrome/PermissionMatrix.php`, `group.role.community_group-{anon_view,insider_view,
   outsider_view}.yml`, or any stale `{anonymous,outsider}.yml` file. No `grequest` reference
   anywhere in the diff. Confirmed clean.
6. **Other duplication** — status-badge rendering (`ManageMembersForm::renderStatusBadge()`, not
   shown above but present in the file) is a single private method used once per row, not
   duplicated elsewhere. Permission lists across the three individual-scope roles
   (`organizer`/`moderator`/`member`) are deliberately different sets per role (not copy-paste of
   an identical list under three IDs) — Organizer = Member's view perms + edit/create/update/
   delete + `administer members`; Moderator = Member's view perms + `administer members` only;
   `member` untouched. This is intentional differentiation, not drift.

## Notes for F

None required (PASS). The finding-#1 `warn` is optional low-priority cleanup, not a blocking
condition — no respawn needed.
