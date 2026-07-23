# Handoff-A: Phase 3 — #121 SC-2 Membership models enforced (up-front plan review)

**Date:** 2026-07-22
**Branch:** 121-req2join
**Brief reviewed:** `docs/planning/handoffs/121-req2join/brief.md`
**Addendum (authoritative):** `docs/planning/handoffs/121-req2join/brief-response.md`
**Reuse map:** `docs/planning/handoffs/121-req2join/survey.md`
**Wireframe:** N/A (survey references future wireframe.md at handoff locations; not present yet — the plan is UI-simple enough that D-phase output is deferred; not blocking here)
**Verdict:** **BLOCK**

## Summary

The plan is overall coherent — bespoke request-to-join on the customized `group_membership`
relationship, extension of `GroupMembershipManager`, and a hook-based create gate against
`field_group_visibility` — all fit the established patterns in `do_group_membership` and honor
the coordinator's "keep composite visibility field" direction. However there are **two BLOCK
issues that require the brief/addendum to be amended before T writes tests**: (1) the "pending
requests queue" the plan calls for as a NEW `/group/{group}/members/pending` route + local task
+ controller method **already exists inside `ManageMembersForm`** (pending rows already render
Approve/Deny submit buttons wired to `approvePending()`/`denyPending()` — see
`ManageMembersForm.php:214-234`); adding a second surface is a parallel path, not an extension.
(2) The brief-response §2 asserts the new route uses `_group_permission: 'administer members'`
in routing YAML, but every existing `do_group_membership.*` route uses
`_custom_access: '\Drupal\do_group_membership\Controller\ManageMembersController::access'`
(`do_group_membership.routing.yml:1-51`) — the plan silently forks the access idiom.

A third smaller BLOCK: several `GroupMembershipManager` surface additions the survey proposes
(`countPendingFor`, `getPendingFor`) become unnecessary once the queue is folded back into
`ManageMembersForm`; the plan should not commit to them.

Everything else — the `#[Hook('group_relationship_create_access')]` gate, seed idempotency,
HelpText copy edits, AC-15/AC-16 access ACs, no-role-on-approval, personas — is architecturally
sound and consistent with neighboring code.

## BLOCK findings

### [A-1] Pending-queue route is a parallel path over an already-live surface

**Problem.** The survey (§Files this phase will touch, items 5–7) and brief AC-4 propose:
- new route `do_group_membership.pending_queue` at `/group/{group}/members/pending`
- new local task "Pending requests" (`do_group_membership.links.task.yml`)
- new controller method `ManageMembersController::pendingQueue()`
- (implicit) either a new `PendingRequestActionForm` or an inline row-action render on the new
  queue.

But `ManageMembersForm` **already renders pending memberships in the same table** and already
exposes per-row `Approve` / `Deny` submit buttons that call
`GroupMembershipManager::approvePending()` / `denyPending()` — see
`docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:134-136` (pending row
tagging), `:169-185` (pending badge), `:214-234` (Approve/Deny row buttons), `:296-329`
(approve/deny submit callbacks). The organizer queue for a pending request seeded by
`step_700_demo_data.php` is therefore ALREADY visible on `/group/{group}/members` the moment
a `field_membership_status = pending` relationship exists.

Building a second page at `/…/members/pending` that renders the same data through a different
controller/form is exactly the "parallel path where the map named an extension" pattern the
Phase 7 dup gate exists to catch — better to catch it now in Phase 3.

**Evidence.**
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70` (intro copy
  literally says "Pending requests need your approval").
- Same file, `:134`, `:214-234`, `:296-329` (pending rows + Approve/Deny already wired).
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:183-216`
  (`approvePending`/`denyPending` already exist and are already called from the existing form).
- Survey §"Files this phase will touch" item 2 under NEW itself says *"Prefer reuse … do NOT
  create a full FormBase … This mirrors the existing ManageMembers row-action pattern"* —
  it acknowledges the reuse but then still proposes items 5/6/7 that duplicate the surface.

**Prescribed remedy.** Amend the brief and survey to REMOVE:
- the new route `do_group_membership.pending_queue`,
- the new local task `Pending requests`,
- the new `ManageMembersController::pendingQueue()` method,
- the new form file `PendingRequestActionForm.php`.

Re-scope AC-4 (and AC-15/AC-16) to target the **existing** `/group/{group}/members` surface:
- AC-4: "…organizer on Leadership Council sees the pending request from Sophie in the
  member table with a `Pending` status badge; the Approve button flips it to Active and it
  shows on the member list normally; Deny deletes the relationship."
- AC-15/AC-16: gate points remain `/group/{group}/members` and its existing submit
  handlers (approve/deny buttons on the ManageMembersForm) — already 403 for
  non-organizers via `ManageMembersController::access` (verified). AC-15/AC-16 then
  become regression tests on existing routes, not new-route acceptance tests.

If the wireframe insists on a *distinct* pending-only page (a badge-linked shortcut) the
correct extension is a **Views filter or a query-string filter on the existing route**
(`/group/{group}/members?status=pending`) — not a second controller. That would be a Warn,
not a Block; but the current plan is a full duplicate surface.

### [A-2] Route access idiom drift: `_group_permission` vs. established `_custom_access`

**Problem.** `brief-response.md` §2 line 18 says the new pending-queue route (and, by
implication, any new approve/deny endpoints if AC-16 is read as demanding *dedicated*
approve/deny URLs) is gated by `_group_permission: 'administer members'` in `routing.yml` —
"same gate as the existing `/group/{group}/members` route in
`do_group_membership.routing.yml`". That is **factually incorrect**: the existing route uses
`_custom_access` pointing to `ManageMembersController::access`
(`do_group_membership.routing.yml:6-7`; `ManageMembersController.php:38-45`), which combines
`$group->hasPermission('administer members', $account)` OR the site-admin
`administer group` escape hatch, and layers on cache metadata (`addCacheableDependency`,
`cachePerPermissions`, `cachePerUser`, `addCacheContexts(['url.path'])`). No route in this
module uses the `_group_permission` YAML enhancer.

Introducing a new route with a different access idiom (missing the site-admin escape hatch,
missing the cache metadata contract) is a drift from the established pattern.

**Evidence.**
- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml:1-51` (all four
  routes use `_custom_access`, none use `_group_permission`).
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php:38-45`
  (the shared callback + its cache contract).

**Prescribed remedy.** If A-1 is accepted, this collapses — no new routes are added and the
question is moot. If for any reason a new route survives (e.g., a POST-only `request_join`
endpoint), the brief-response §2 wording must be corrected to reuse `_custom_access`
(referencing either `ManageMembersController::access` or a new peer callback matching its
shape, including the cache-metadata additions). Do not introduce `_group_permission` YAML
gating as a "same gate" — it is not the same, either behaviorally or in cacheability.

Also: the `request_join` route (`POST /group/{group}/join-request`) is fine to add — no
duplication — but its access **must not** be `_group_permission: 'administer members'`
either (obvious — it's an outsider-facing route). The correct gate is a new `_custom_access`
callback that delegates to the same `hook_group_relationship_create_access` result via
`GroupMembershipManager::joinPolicyFor($group)`, OR simply performs the create and lets the
hook return `Forbidden` at entity-op time. Please pick one and name it in the brief.

### [A-3] Speculative manager surface

**Problem.** Survey §"Manager surface changes (proposed)" lists four new methods:
`requestJoin`, `joinPolicyFor`, `countPendingFor`, `getPendingFor`. If A-1 is accepted
(pending queue is the existing ManageMembers table), `countPendingFor` and `getPendingFor`
have no consumer — `ManageMembersForm::buildForm()` already calls `$group->getMembers()` and
filters by status inline (`ManageMembersForm.php:80` + row-level status derivation `:130`).
Adding manager methods with no consumer is speculative surface — YAGNI, and drifts from
the manager's current "one method per state transition" cadence
(`GroupMembershipManager.php:36-297`).

**Evidence.**
- `ManageMembersForm.php:80` (loads memberships directly via `$group->getMembers()`).
- Neighboring manager methods (`addMember`, `changeRole`, `changeStatus`, `removeMember`,
  `approvePending`, `denyPending`) are all state-transition verbs, not query helpers.

**Prescribed remedy.** Drop `countPendingFor` and `getPendingFor` from the surface. Keep
`requestJoin(GroupInterface, UserInterface): GroupRelationshipInterface` (state transition
— fits the cadence, reuses the `DuplicateMembershipException` guard from `addMember`) and
`joinPolicyFor(GroupInterface): string` (single-purpose classifier consumed by the create-access
hook and by any UI conditional). If a badge count later proves needed (e.g. on the group
canonical local task label), add it then.

## WARN findings

### [A-W1] Manager `requestJoin` vs. `addMember` — duplication risk if not deliberately shared

`requestJoin` and `addMember` both call `$group->addMember($account, [...])` with the same
duplicate/blocked-account guards and only differ in the `field_membership_status` value and the
`group_roles` default. F should factor a shared private helper (e.g.
`protected function createMembership(GroupInterface $group, UserInterface $account, string $status, array $roles): GroupRelationshipInterface`)
so both public methods delegate to it. This keeps the "Services over Hooks" cadence and
prevents the two branches from diverging on future edge cases (e.g. cache tags, event
dispatching). Not blocking — F can decide the extraction shape — but the brief should flag it.

### [A-W2] `hook_group_relationship_create_access` on Group 4.0.x — validate at F time

The Group contrib module is composer-installed and not present in the source worktree, so I
could not verify the hook signature against upstream source. The reasoning in the addendum §1
is sound and the R2 dual-reviewer accepted it, but F must confirm the exact signature
(`hook_group_relationship_create_access(GroupInterface $group, GroupRelationTypeInterface|string $plugin_id, AccountInterface $account)` — 4.0.x variant) at implementation time by inspecting
`vendor/drupal/group` after `composer install`. Record the exact signature in `decisions.md`.
If the hook does not exist on 4.0.x-dev (name changed from d10-era `hook_group_content_*`),
fall back to a `GroupRelationshipAccessControlHandler` alter or an entity_access hook keyed on
`group_relationship` + `plugin_id === 'group_membership'`. Not blocking here (the plan can
still land with either), but T must not commit to the exact hook name in tests until F
confirms.

### [A-W3] `visibility.field` copy — AC-7 assertion should be broadened

AC-7 requires the invite_only copy to contain "visible". Reasonable. But the current
`visibility.field` field-level intro (HelpText.php:84) says *"every group stays readable to
anyone"* — which is the same claim (viewable ≠ joinable). The updated copy must NOT drop that
distinction from the field-level intro either, otherwise the demo starts implying
`invite_only == hidden`. The plan should add: `visibility.field` copy MUST retain a phrase
that separates "viewing" from "joining" (e.g. "who can *join*"). Non-blocking; a copy-edit
guard rail.

### [A-W4] AC-14 "source-only commits" — surface path guard rail

The brief's AC-14 says source-only feature commits in `docs/groups/…`. Confirming F: no
implementation file may be committed under `web/modules/custom/*` or `config/sync/*` (both
gitignored per PROJECT_CONTEXT.md; assembled by `scripts/ci/assemble-config.sh`). The tests T
authors also live under `docs/groups/modules/do_group_membership/tests/…`, not
`web/modules/custom/*/tests`. This is standard and the brief already says so; flagging only
because HelpText serialization (this story is leader) makes explicit-path staging especially
important.

## NIT

- Brief AC-9 says "creates one pending relationship (sophie_mueller → Leadership Council)" and
  addendum §5 shows the guard. Consider seeding a *second* pending relationship (alex_novak
  → Leadership Council) so the queue demonstrates >1 pending row — improves U's walkthrough
  and covers the "row-action isolated to one row" case cheaply. Cosmetic.
- `do_group_membership.info.yml` description will need a one-line update once request-to-join
  is live ("…group roles… request-to-join, and…"). Trivial.

## Notes for O (what to amend)

To move to PASS on re-review, the brief + brief-response need three surgical amendments:

1. **A-1 fix.** Remove the parallel `/…/members/pending` surface from the plan. Restate
   AC-4/AC-15/AC-16 to target the existing `/group/{group}/members` route and the existing
   `ManageMembersForm` Approve/Deny row buttons. Delete survey items 5b (pending_queue route),
   6 (local task), 7 (`pendingQueue()` controller method), and NEW-item-2
   (`PendingRequestActionForm`). Add a survey note that the seeded pending relationship
   surfaces automatically in the existing member table.
2. **A-2 fix.** Correct brief-response §2 to state the new `request_join` route uses
   `_custom_access` (matching the existing four routes) — not `_group_permission`. Name the
   access callback (either extend `ManageMembersController::access` with a
   `requestJoinAccess()` peer method that returns allowed-for-authenticated-non-members, OR
   simply rely on the `hook_group_relationship_create_access` gate and give the route
   `_permission: 'access content'` since the real gate is at entity-create).
3. **A-3 fix.** Trim the manager surface in the survey to `requestJoin` + `joinPolicyFor`
   only. Note that pending counts/reads use `$group->getMembers()` + inline status filter, as
   in `ManageMembersForm`.

Once amended, no re-spawn needed for F/T (no code exists). Re-review is a same-day pass.

## Patterns referenced

- `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` — established
  `_custom_access` idiom.
- `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php` —
  access callback + cache metadata contract.
- `docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,134-136,214-234,296-329` —
  pending rows + approve/deny already integrated on the existing manage-members page.
- `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php:36-297` — manager
  cadence (state-transition verbs, DuplicateMembershipException, `addMember` shape to reuse).
- `docs/groups/modules/do_chrome/src/HelpText.php:84-87` — visibility copy edit target.
- `docs/groups/config/field.storage.group.field_group_visibility.yml:12-23` — composite
  values `open` | `moderated` | `invite_only` (unchanged by this story per coordinator
  direction).
- `docs/groups/config/group.role.community_group-outsider_view.yml:16` — the `join group`
  permission that the visibility-hook must gate at entity-create rather than by editing this
  role.
