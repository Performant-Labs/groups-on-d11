# Handoff-A: Phase 3 - #144 MC-6 Create-Group flow (up-front plan review)

**Date:** 2026-07-23
**Branch:** 144-auto-organizer
**Brief reviewed:** docs/planning/handoffs/144-auto-organizer/brief.md
**Reuse map:** docs/planning/handoffs/144-auto-organizer/survey.md
**Wireframe:** docs/planning/handoffs/144-auto-organizer/wireframe.md
**Verdict:** PASS

## Summary
The plan correctly EXTENDS `do_group_membership` (no new module), reuses the exact
`group_relationship_insert` precedent already shipped in `DoNotificationsHooks`, and adds a
non-destructive `ensureRole()` rather than reusing the role-erasing `changeRole()`/step_790
replace pattern. Layering, DI, hook-registration, and route/controller idioms all match
neighbouring code. PASS with two warns F must resolve empirically at implementation time — the
`creator_wizard: true` interaction and the redirect ordering — both already correctly delegated to
F as "verify, don't guess," which is the right disposition.

## Answers to the three "Open questions for A"

**Q1 — `ensureRole()` vs generalizing `changeRole()`: CONFIRMED, `ensureRole()` is correct.**
`changeRole()` (line 194) does `->setValue(array_values($roles))` — a wholesale REPLACE that would
erase the Admin role `creator_roles` just granted, and it also runs the last-Organizer guard, which
is irrelevant to an additive grant. A new idempotent `ensureRole(GroupRelationshipInterface, string
$role_id): void` that (a) early-returns if `hasRole()` is already true, (b) appends the role to the
existing `group_roles` set, (c) saves, is the right seam and keeps "services over hooks" discipline.
To reuse `hasRole()` (currently `protected`, line 287) from the new method that is fine — it is
called from within the same class, no visibility change needed. Do NOT copy step_790's
`set('group_roles', [ORGANIZER])` shape into the manager: that is a REPLACE and would drop Admin.
ensureRole must read the existing values and append.

**Q2 — one hook class or two: CONFIRMED, ONE class with two `#[Hook]` methods.**
Both methods serve the single "create-group flow" concern and neither is independently reusable.
This matches the codebase norm (`DoNotificationsHooks` holds many unrelated `#[Hook]` methods in one
class; `GroupAccessHook` is single-concern). Do NOT fold into `GroupAccessHook` — that class is
scoped to create-ACCESS gating (`group_relationship_create_access`), a different hook and a
different concern; mixing role-mutation + redirect into it would blur its single responsibility. A
new `CreateGroupOrganizerHook` is the right home. Two separate classes would be over-abstraction.

**Q3 — form_id + OrderAfter: best-effort answer, F verifies empirically (no vendor access).**
I cannot confirm vendor internals — `drupal/group` is not in this worktree's `vendor/`; I judged
from the Drupal form-API idioms proven in `DoMultigroupHooks`. Guidance for F:
- The `{entity_type}_{bundle}_{operation}_form` convention gives a likely `group_community_group_add_form`,
  BUT `group.type.community_group.yml` has **`creator_wizard: true`** (line 10). The creator wizard
  changes the add flow into a multi-step form; the form_id and the step at which the group is saved
  and the membership exists may differ from the non-wizard case. F must assert the actual `$form_id`
  (and confirm which wizard step owns the final save/redirect) via a functional test, not a guess.
- On ordering: `DoMultigroupHooks::formAlterEnsureSubmitLast` (lines 168-187) exists precisely
  because Group's `CreateFormEnhancer` appends a submit handler and order matters. The redirect
  handler here does NOT depend on the membership row existing (it only needs the saved group id from
  `$form_state->getFormObject()->getEntity()`), so plain appending is likely sufficient — UNLESS
  Group's own submit handler calls `setRedirect()` after ours and clobbers it. F verifies: if the
  landing page is the group canonical rather than `/created`, adopt the proven
  `OrderAfter(modules: ['group'])` + submit-array-reorder fallback. The fallback is already named in
  the plan, so no plan change is needed.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | form_alter redirect | correctness | `creator_wizard: true` (group.type.community_group.yml:10) means the add form is a multi-step wizard; form_id and the save/redirect step may differ from the plain add-form assumption. Plan says "F confirms form_id" but does not name the wizard specifically. | F must confirm behaviour on the ACTUAL wizard flow, and T(RED) must drive the E2E through every wizard step to the final submit, not a single-page submit. Add this note to the brief's form_id line. |
| 2 | warn | `group_relationship_insert` owner filter | correctness | Filter `member-uid === group->getOwnerId()` is correct for the creator, but a same-request batch that creates the owner's membership AND other memberships would fire the hook per-row; the owner-equality guard makes only the owner's row match, so it does NOT misfire on the others. `ensureRole()` idempotency (early-return on `hasRole`) also makes a re-fire harmless. Confirmed sound; flagging so T adds a kernel test asserting a NON-owner membership created in the same request does NOT receive Organizer. | Keep the two-part filter (`plugin_id === 'group_membership'` AND owner-equality). T covers the non-owner negative case. |
| 3 | warn | preview page status message | pattern/WCAG | Wireframe renders confirmation only as page content. For a user who lands then navigates away, nothing persists — acceptable for a POC, but a `messenger()->addStatus()` set in the redirect submit handler would match Drupal's post-save convention and reinforce the confirmation. Not required for AC-5 (h1-first satisfies focus order). | Optional: F may add a status message in the redirect handler. Not a blocker; O decides if worth a follow-up. |

## Notes for O
No BLOCK — no brief amendment required. Recommend adding one sentence to the brief's form_alter
line making the `creator_wizard: true` multi-step nature explicit (finding #1) so F/T don't rediscover
it late. The other two are warns for F/T discretion.

## Verified against other stories (duplication check)
- **#36** (creator-membership): plan runs strictly AFTER Group's form-save and only ADDS a role —
  does not fork the form-only `creator_membership`/`creator_roles` mechanism. Clean.
- **#121** (join-policy): `GroupAccessHook` is untouched; new hook targets a different hook name
  (`group_relationship_insert` vs `group_relationship_create_access`). No overlap.
- **#138** (manage-members / Organizer role): reuses the existing `community_group-organizer` role
  and `administer members` permission; adds no role/config. Preview page's access callback mirrors
  `ManageMembersController::access()`. No duplication.

## Patterns referenced
- docs/groups/modules/do_group_membership/src/GroupMembershipManager.php (changeRole:186, hasRole:287)
- docs/groups/modules/do_notifications/src/Hook/DoNotificationsHooks.php:165-198 (insert-hook precedent)
- docs/groups/modules/do_multigroup/src/Hook/DoMultigroupHooks.php:168-187 (form_alter/OrderAfter)
- docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php + .services.yml:19-23 (FQCN-keyed hook registration)
- docs/groups/config/group.type.community_group.yml:9-12 (creator_wizard/creator_roles) + group.role.community_group-organizer.yml
