# Brief-gate dual-review Round-1 response — #121

Reviewer: o4-mini (`docs/planning/handoffs/121-req2join/dual-review-brief.md`).
Response author: O.

## BLOCK adjudication

### [B-1] Enforcement hook mechanism — ACCEPTED, brief amended

The group module *does* provide the hook surface: `hook_group_relationship_access($group_relationship, $operation, $account)` (invoked via `GroupRelationshipAccessControlHandler`) and, for the create case, `hook_group_relationship_create_access($group, $plugin, $account)`. These are the correct extension points for gating `group_membership.create` per the group's `field_group_visibility`.

**Amendment (see Addendum §1):** `GroupAccessHook` implements `hook_group_relationship_create_access()` (and the peer access hook for the pending queue is *not* needed — the pending queue is a controller route gated by `_group_permission: 'administer members'`, not a plugin-access hook).

### [B-2] "Organizer" role/permission — ACCEPTED, brief amended

Organizer = group role `community_group-organizer` (see `docs/groups/config/group.role.community_group-organizer.yml`), which holds the `administer members` group permission. This is the existing gate used by ManageMembers (#138).

**Amendment (see Addendum §2):** the pending-queue route and the approve/deny inline actions are gated by `_group_permission: 'administer members'` — same gate as the existing `/group/{group}/members` route in `do_group_membership.routing.yml`. No new permission introduced.

### [B-3] Guard on approve/deny — ACCEPTED, ACs added

Adding **AC-15** and **AC-16** (see Addendum §3): direct HTTP requests to the approve/deny endpoints by anonymous users, plain members, and non-members return 403; verified in `JoinPolicyEnforcementTest` (Functional).

### [B-4] Default role on approval — ACCEPTED, brief amended

An approved membership gets `group_roles = []` (the group's outsider→member baseline is granted implicitly by having the relationship; no explicit role is assigned unless an organizer later promotes). This matches `addMember()`'s optional `$roles=[]` behavior and mirrors how #95's open-join path already works — no divergence.

**Amendment (see Addendum §4):** `approvePending()` MUST NOT assign any `group_roles`; the AC-4 assertion is extended to check `$approved->get('group_roles')->isEmpty() === TRUE`.

### [B-5] Seed idempotency — ACCEPTED, spelled out

**Amendment (see Addendum §5):** the seed appends use existence checks: `$group->get('field_group_visibility')->value !== 'moderated'` guards the moderated assignment; the pending relationship guards on `!empty($group->getRelationshipsByEntity($sophie, 'group_membership'))`. Same idempotency pattern as the existing `step_700_demo_data.php` sections (seed script is written to re-run cleanly).

## WARN — recorded, not blocking

- **[W-1]** composite `field_group_visibility` — already explicitly hedged in the brief (§Objective + Reuse map) and confirmed by coordinator ("keep the composite; axis-independence AC is future-work for #134"). Documented in the PR body per coordinator direction.
- **[W-2]** automated axe in CI — out of scope for this story; UI phase U runs axe manually. Filing a follow-up would be premature until #145 lands.
- **[W-3]** locator brittleness — AC-10 already specifies both `role=button,name=/Request to join/i` OR `input[type=submit][value*=Request]`. Sufficient.
- **[W-4]** copy substring tests — brittleness is *desired* here; the story exists specifically to change wrong copy. `HelpTextTest` intentionally asserts on substring so that a future regression to "not yet enforced" or "hidden" fails loudly.

## NIT — noted, not amended (cosmetic).

---

## Addendum to brief (authoritative)

### §1 Enforcement mechanism (resolves B-1)

`docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` implements:

- `#[Hook('group_relationship_create_access')]` — gates `plugin_id === 'group_membership'` per the target group's `field_group_visibility`:
  - `open` → neutral (allow via existing permission grant).
  - `moderated` → allow (permits the request-to-join relationship creation with `field_membership_status=pending`).
  - `invite_only` → **forbidden** for non-organizers; organizers (who hold `administer members`) bypass.

The pending-queue controller route uses `_group_permission: 'administer members'` in the routing YAML — no hook needed; the group module's default `_group_permission` route enhancer handles it.

### §2 Organizer definition (resolves B-2)

- Role: `community_group-organizer` (existing).
- Permission gating queue view + approve/deny actions: `administer members` (existing, held by Organizer only in the seeded role config).
- The Manage-members local task (#138) already renders only for holders of `administer members`; the "Pending requests" tab reuses the same visibility rule.

### §3 Approve/deny access ACs (resolves B-3)

- **AC-15** Anonymous or plain-member direct POST/GET to `/group/{group}/members/pending` returns 403 (Functional).
- **AC-16** Anonymous or plain-member direct POST to the approve endpoint AND to the deny endpoint on a pending relationship returns 403 (Functional).

### §4 Post-approval role assignment (resolves B-4)

`GroupMembershipManager::approvePending()` flips `field_membership_status` from `pending` → `active` and MUST NOT mutate `group_roles`. AC-4 extended: after approval, assert `$approved->get('group_roles')->isEmpty() === TRUE` and `$approved->get('field_membership_status')->value === 'active'`.

### §5 Seed idempotency (resolves B-5)

Every appended block in `step_700_demo_data.php` uses an existence guard:

```php
if ($lc->get('field_group_visibility')->value !== 'moderated') {
  $lc->set('field_group_visibility', 'moderated')->save();
}
if (empty($lc->getRelationshipsByEntity($sophie, 'group_membership'))) {
  $lc->addMember($sophie, [
    'group_roles' => [],
    'field_membership_status' => [['value' => 'pending']],
  ]);
}
```

Same shape as the existing group/user creation guards in `step_700_demo_data.php`.

### §6 New ACs (authoritative — T MUST cover)

- **AC-15** Non-organizer HTTP GET/POST `/group/{group}/members/pending` → 403.
- **AC-16** Non-organizer HTTP POST to approve OR deny endpoint on a pending relationship → 403.
- **AC-4 (extended)** After approval, `field_membership_status = 'active'` AND `group_roles` is empty.

---

**Verdict expectation:** all 5 BLOCKs are resolved by the addendum above. WARN + NIT recorded, none acted on.
