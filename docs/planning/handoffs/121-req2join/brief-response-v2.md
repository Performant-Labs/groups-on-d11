# Brief amendment v2 — response to A (Phase 3 plan review)

Reviewer: A (`docs/planning/handoffs/121-req2join/handoff-A-plan.md`) — verdict BLOCK (3 findings).
Author: O. All three BLOCKs ACCEPTED. Amendments below supersede any conflicting text in
`brief.md` / `survey.md` / `brief-response.md`.

## Adjudication

### [A-1] Pending-queue parallel path — ACCEPTED

`ManageMembersForm` already renders pending memberships with Approve/Deny row buttons wired to
`GroupMembershipManager::approvePending()` / `denyPending()` — verified in
`docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php:70,80,214-234,296-329`.
Adding a second `/group/{group}/members/pending` route + local task + `pendingQueue()` controller
+ `PendingRequestActionForm` is a parallel path over an already-live surface, exactly the class
of duplication the anti-dup gate exists to catch. Caught here in Phase 3 (no code yet) — free fix.

### [A-2] Route access idiom drift — ACCEPTED

Confirmed by inspection of `do_group_membership.routing.yml:1-51`: every existing route uses
`_custom_access: '\Drupal\do_group_membership\Controller\ManageMembersController::access'`, not
`_group_permission`. Brief-response §2's "same gate" claim was factually wrong. Corrected below.

### [A-3] Speculative manager surface — ACCEPTED

Once A-1 folds, `countPendingFor` and `getPendingFor` have zero consumers; `ManageMembersForm`
already loads via `$group->getMembers()` with inline status filter. Dropping them.

## Amendments (supersede prior text)

### §A-1 fix — REMOVE parallel pending-queue surface

Remove from the plan (survey §"Files this phase will touch" and brief AC-4):
- new route `do_group_membership.pending_queue` at `/group/{group}/members/pending` — DELETE.
- new local task `Pending requests` (`do_group_membership.links.task.yml` add) — DELETE.
- new controller method `ManageMembersController::pendingQueue()` — DELETE.
- new form `PendingRequestActionForm.php` — DELETE.

The seeded pending relationship (sophie_mueller → Leadership Council with
`field_membership_status=pending`) surfaces automatically in the **existing** `ManageMembersForm`
member table on `/group/{group}/members` with its existing Pending badge and Approve/Deny row
buttons. Nothing new to build for the organizer surface.

**AC-4 restated (authoritative):**

> Organizer on Leadership Council navigates to `/group/{group}/members` and sees Sophie's request
> row with the `Pending` status badge; clicking the row's `Approve` button flips
> `field_membership_status` to `active` and Sophie appears normally in the active-member list;
> clicking `Deny` on a pending request deletes the relationship (Kernel + Functional).

**AC-15 restated:** anonymous or plain-member direct GET to `/group/{group}/members` on
Leadership Council returns 403 (via existing `ManageMembersController::access` — regression AC,
not a new gate).

**AC-16 restated:** anonymous or plain-member direct POST to the ManageMembersForm's approve OR
deny submit endpoints on a pending row returns 403 (via existing `ManageMembersController::access`
— regression AC on the existing form submit).

### §A-2 fix — request-join route uses `_custom_access`

The new **request-join route** (the ONE surviving new route from the original plan) is:

```yaml
do_group_membership.request_join:
  path: '/group/{group}/join-request'
  defaults:
    _form: '\Drupal\do_group_membership\Form\RequestJoinForm'
    _title: 'Request to join'
  requirements:
    _custom_access: '\Drupal\do_group_membership\Controller\ManageMembersController::requestJoinAccess'
  options:
    parameters:
      group:
        type: entity:group
```

Add a peer method `ManageMembersController::requestJoinAccess(GroupInterface $group, AccountInterface $account): AccessResultInterface` matching the shape of the existing `::access()`
callback — same cache metadata contract (`addCacheableDependency($group)`,
`cachePerPermissions()`, `cachePerUser()`, `addCacheContexts(['url.path'])`). Logic:

- Authenticated user is not already a member of `$group`, AND
- `$group->get('field_group_visibility')->value === 'moderated'`
- → `AccessResult::allowed()`; else `AccessResult::forbidden()`.

The **hook_group_relationship_create_access** gate (Addendum §1) remains the source of truth at
entity-create time (defense in depth). The `_custom_access` callback is the route-level short
circuit so an `invite_only` or `open` group returns 403 at the URL, not at form submit.

**Do NOT introduce `_group_permission` YAML gating anywhere in this story.** Every route stays
on `_custom_access` — the established idiom in `do_group_membership`.

### §A-3 fix — trim manager surface

Manager additions in this story are exactly TWO methods:

```php
public function requestJoin(GroupInterface $group, UserInterface $account): GroupRelationshipInterface;
public function joinPolicyFor(GroupInterface $group): string; // 'open' | 'request' | 'invite'
```

Drop `countPendingFor` and `getPendingFor` from the plan — no consumer. If a future badge on the
group canonical local task label needs a count, add then (YAGNI now).

Per A's [A-W1] WARN: F should factor a private helper (e.g. `createMembership(GroupInterface,
UserInterface, string $status, array $roles)`) that both `addMember()` and `requestJoin()`
delegate to, so blocked/duplicate guards + entity-create shape stay unified. Non-blocking; F
picks the exact shape.

### §A-W2 note — hook signature verification

F confirms `hook_group_relationship_create_access` signature by inspecting
`vendor/drupal/group/` at implementation time and records the exact signature in `decisions.md`
under Phase 4 (F). If the hook does not exist on 4.0.x-dev under that name, F falls back to
`hook_entity_create_access` keyed on `group_relationship` + `plugin_id === 'group_membership'`.
T must not commit to a specific hook name in RED tests — assert on behavior (creating a
pending relationship on invite_only is forbidden), not on the hook identifier.

### §A-W3 note — visibility.field copy guard rail

Updated `visibility.field` intro copy MUST retain wording that separates viewing from joining
(e.g. `"who can *view*"` vs `"who can *join*"`), so the invite_only correction ("visible but
closed to joining") reads consistently with the field-level intro. F adds an assertion in
`HelpTextTest` that `visibility.field` copy contains either "join" or "view" (belt-and-braces
against a copy edit that flattens the distinction).

### §A-W4 note — source-only path guard

Reiterated: F commits only under `docs/groups/…`. T's new test files live at
`docs/groups/modules/do_group_membership/tests/…`, not `web/modules/custom/*/tests`. HelpText
serialization discipline is especially important since #121 is the leader of the
#126/#127/#128/#132 rebase chain.

## NIT accepted (cosmetic)

- Seed a **second** pending relationship (alex_novak → Leadership Council) so the queue row
  demonstrates >1 pending row and row-action isolation. Adds one guarded `if empty()` block to
  `step_700_demo_data.php`. AC-9 amended to require both pending rows.
- `do_group_membership.info.yml` description gets a one-line update reflecting request-to-join.

## Files to touch after amendment — corrected list

**EDIT:**
1. `docs/groups/modules/do_chrome/src/HelpText.php` (visibility copy — unchanged from original plan).
2. `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (assertions).
3. `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php` (add `requestJoin` + `joinPolicyFor` + private `createMembership` helper).
4. `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php` (add `requestJoinAccess()` peer method).
5. `docs/groups/modules/do_group_membership/do_group_membership.routing.yml` (add ONE route: `do_group_membership.request_join`).
6. `docs/groups/modules/do_group_membership/do_group_membership.info.yml` (one-line description update).
7. `docs/groups/scripts/step_700_demo_data.php` (append-only, idempotent guards — 2 pending rows).

**NEW:**
1. `docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php` (outsider-facing single-click form).
2. `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` (hook_group_relationship_create_access gate).
3. `docs/groups/modules/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php` (T authors).
4. `docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php` (T authors — covers AC-3/AC-11/AC-15/AC-16).
5. `tests/e2e/membership-models.spec.ts` (T authors).

**DELETED from original plan** (per A-1):
- `do_group_membership.pending_queue` route.
- `do_group_membership.links.task.yml` "Pending requests" entry.
- `ManageMembersController::pendingQueue()` method.
- `PendingRequestActionForm.php`.

---

**Requested verdict on re-review:** PASS (all 3 BLOCKs surgically resolved; no code exists to
respawn).
