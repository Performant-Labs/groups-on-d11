# Survey — #121 SC-2 Membership models enforced

## Files this phase will touch (reuse map)

### EDIT (extend existing)

1. `docs/groups/modules/do_chrome/src/HelpText.php` (~L84–87)
   - **Analogous feature:** the existing #88 visibility copy block — pre-existing tooltip surface.
   - **Rec:** EDIT the three visibility copy strings (`visibility.field`, `visibility.moderated`, `visibility.invite_only`); keep `visibility.open` intact (already correct + live).
   - **New keys:** append `visibility.moderated.hint`, `visibility.invite_only.hint` only if the wireframe surfaces them separately; otherwise fold updated text into the existing three keys.
   - **Corrected copy must:** describe live enforcement (no "not yet enforced"), and — critically — the `invite_only` copy MUST contain the word **"visible"** (Invite Only = *visible* but closed to joining; hidden is Private/#134).

2. `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (~L61–74)
   - **Rec:** UPDATE `testVisibilityCopyIsPresentPlainTextAndHonest()` — replace the "Not yet enforced" assertions with the new corrected-copy assertions (request/approval for moderated; "visible" + closed-to-joining for invite_only).

3. `docs/groups/modules/do_group_membership/src/GroupMembershipManager.php`
   - **Analogous feature:** already has `addMember()` (active), `approvePending()`, `denyPending()`, `changeStatus()`.
   - **Rec:** EXTEND — add `requestJoin(GroupInterface, UserInterface): GroupRelationshipInterface`. Same shape as `addMember()` but sets `field_membership_status = pending` and `group_roles = []`. Reuses the `DuplicateMembershipException` guard so re-request is idempotent-blocked.

4. `docs/groups/scripts/step_700_demo_data.php`
   - **Rec:** APPEND-ONLY. Append at end: `field_group_visibility` assignment for the 8 groups (open by default, Leadership Council→moderated, Core Committers→invite_only) + one pending relationship (sophie_mueller → Leadership Council with status=pending) so the organizer queue demos non-empty.

5. `docs/groups/modules/do_group_membership/do_group_membership.routing.yml`
   - **Rec:** EXTEND — add two routes: `do_group_membership.request_join` (POST /group/{group}/join-request) and `do_group_membership.pending_queue` (GET /group/{group}/members/pending) — the organizer approval queue.

6. `docs/groups/modules/do_group_membership/do_group_membership.links.task.yml`
   - **Rec:** EXTEND — add "Pending requests" local task under `entity.group.canonical` visible only when count>0 (or unconditionally with a badge — decide in D).

7. `docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php`
   - **Rec:** EXTEND with `pendingQueue()` render callback + `access()` for organizers.

### NEW (justified)

1. `docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php`
   - **Justification:** no existing form handles the outsider-facing single-click "Request to join" submission. `AddMemberForm` is organizer-facing (adds another user); the request flow is self-service. Distinct actor + distinct redirect flow — new form.

2. `docs/groups/modules/do_group_membership/src/Form/PendingRequestActionForm.php` OR reuse `ChangeRoleForm` pattern
   - **Prefer reuse:** two small buttons (Approve / Deny) inside the pending queue render — inline routes calling manager methods directly. Do NOT create a full FormBase per request; render approve/deny as buttons on the queue table row. This mirrors the existing ManageMembers row-action pattern.

3. `docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php` (new hook class)
   - **Justification:** need to gate `group_membership.create` per group's `field_group_visibility`:
     - `open` → allow (as today).
     - `moderated` → allow (creation of `pending` relationship = the request flow).
     - `invite_only` → deny (only organizers via `AddMemberForm` can create memberships).
   - Also gate the "Join group" button UI so it renders "Request to join" for moderated / hides entirely for invite_only.
   - **Extend vs new:** do_group_membership has no existing group_access hook — new file justified. But keep the hook class thin: it delegates to `GroupMembershipManager::joinPolicyFor(GroupInterface): string` (new helper on the manager).

4. `docs/groups/modules/do_group_membership/tests/src/Kernel/RequestJoinFlowTest.php` (T authors)
   - Kernel-level: requestJoin creates pending; approvePending flips to active; denyPending deletes; duplicate re-request throws; invite_only denies via the hook.

5. `docs/groups/modules/do_group_membership/tests/src/Functional/JoinPolicyEnforcementTest.php` (T authors)
   - Functional (BrowserTestBase, self-provisions): non-member logs in → open group shows Join → moderated shows Request to join and creates pending → invite_only shows no join path. Organizer sees pending queue populated; approve flips to active membership visible on manage-members.

6. `tests/e2e/membership-models.spec.ts` (T authors)
   - Playwright vs seeded site: sophie_mueller logs in → walks all three flows against Leadership Council / Core Committers / an open group (e.g. Drupal France).

### DO NOT TOUCH (out of scope for this story)

- `docs/groups/config/group.role.community_group-outsider_view.yml` — the `join group` grant stays; the per-group visibility gate goes in the hook, not by splitting the role. (Prevents a shared config churn that #134 would then have to reconcile.)
- Any `web/modules/custom/*` or `config/sync/*` file — assembled artifacts.
- The two-field split (visibility axis + join_policy axis). Reserving that for a later, scoped story if #134's private-group work requires it.

## Downstream forward-compat check

Read #126, #127, #128, #132 (all HelpText-touching). They ONLY APPEND to HelpText.php (never edit). Their appended keys are unrelated to `visibility.*` — no collision with this story's EDIT of the visibility copy block. #134 (private group) will add a new value to the `field_group_visibility` allowed_values list — its work is *additive* and does not conflict with #121's copy update or join-policy hook.

The gate on `group_membership.create` (open/moderated/invite_only) is designed to be extended (visibility=private in #134 → deny non-member view via existing group access; join is already denied for invite_only path so the extension is trivial).

## Test personas (verified against `step_700_demo_data.php`)

- **sophie_mueller** — member of DrupalCon Portland 2026, Thunder Distribution, Drupal Deutschland. NOT a member of Leadership Council or Core Committers. **Use for request-to-join flow on Leadership Council** and for confirming Core Committers has no join path.
- **alex_novak** — member of Camp Organizers EMEA only. Same non-member profile for Leadership Council & Core Committers. **Second persona.**
- **elena_garcia** — MUST NOT be used. Already a member of both closed groups.

## Two-axes reconciliation (this story)

The MVP #3578785 "two axes" model (visibility × join_policy) is honored *semantically* by keeping the existing `field_group_visibility` field as a composite key for now:

| stored value | visibility axis | join_policy axis | this story's enforcement |
|---|---|---|---|
| `open` | public | open | already live (join button, #95) |
| `moderated` | public | request | NEW — Request to join button + pending queue |
| `invite_only` | visible-but-closed | invite | NEW — no direct join path; organizer AddMember still works |
| `private` | (#134) | (#134) | out of scope here |

If A wants a hard two-field split now, that's a scope-cap; escalate. The composite is defensible: `field_group_visibility` semantically means "how do outsiders relate to this group" — which IS the composite.

## Gotchas relevant to this story

- **G3 (grequest):** unusable on Group 4.0.x — go bespoke. ✅ Plan is bespoke.
- **G4 (route collisions with `drupal/group` optional config):** the new routes `/group/{group}/join-request` and `/group/{group}/members/pending` don't collide with any stock views (verified — `views.view.group_members` only ships `page_1` at `/group/%group/members`, which #138 already stripped). No new hook_install strip needed.
- **G1 (assembled layout):** all kernel/functional tests run from `web/modules/custom/*`. Assemble before verifying.
- **G6 (E2E vs seeded site):** the spec walks Sophie against seeded groups — validate against a full seeded install, not an isolated fixture.
- **G9 (`#type=>submit` renders `<input>`):** the E2E locator for "Request to join" must match `input[type=submit][value*=Request]` or use `role=button, name=/Request to join/`.

## Manager surface changes (proposed)

```php
// GroupMembershipManager (extension)
public function requestJoin(GroupInterface $group, UserInterface $account): GroupRelationshipInterface;
public function joinPolicyFor(GroupInterface $group): string; // 'open' | 'request' | 'invite'
public function countPendingFor(GroupInterface $group): int;  // for the badge/queue
public function getPendingFor(GroupInterface $group): array;  // for the queue render
```

All four are thin. `requestJoin` reuses the `addMember` shape but sets `field_membership_status=pending` and `group_roles=[]`.
