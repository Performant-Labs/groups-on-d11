# Handoff-T-red: Phase 4 - Issue #138 (MC-7) Organizer manage-members UI + group roles + Groups Moderate

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Brief / wireframe reviewed:** `docs/handoffs/0138-mc7-manage-members/brief.md`,
`docs/handoffs/0138-mc7-manage-members/wireframe.md`, `docs/handoffs/0138-mc7-manage-members/handoff-D.md`,
`docs/handoffs/0138-mc7-manage-members/handoff-A-plan.md`

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A-plan.md`). No `block` findings; two
`warn`s carried forward (warn-1 for F re: the manager-service DI pattern, warn-2 for T re: the
synchronized-groups_moderate-role empirical proof — addressed below in
`ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined()`).

## Tests authored

41 test methods across 8 files, mapped to all 15 acceptance criteria. Module placed under
`docs/groups/modules/do_group_membership/` (the `assemble-config.sh` module-staging convention);
Playwright spec at `tests/e2e/manage-members.spec.ts`.

### Unit tier (16 tests, 3 files) — pure PHP, no container

**`docs/groups/modules/do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`** (4 tests)
- `testOrganizerRoleConfigShape` — **AC-1**: `group.role.community_group-organizer.yml` exists,
  `scope: individual`, `admin: false`, permissions = `edit group` + `administer members` + the 4x
  view/create/update-own/delete-own `group_node:*` across all 5 content types (20 permissions).
- `testModeratorRoleConfigShape` — **AC-2**: `group.role.community_group-moderator.yml` exists,
  `scope: individual`, `admin: false`, permissions = `administer members` + view-only across all 5
  types; explicitly asserts NO `edit group` and NO content-creation permission (narrower than
  Organizer, per the brief's own distinction).
- `testMemberRoleConfigUnchanged` — **AC-3**: the *already-live* `config/sync/group.role.
  community_group-member.yml` still carries exactly its original 5x view-only permissions (a later
  accidental widen during this story would be caught).
- `testGroupsModerateRoleConfigShape` — **AC-13**: `user.role.groups_moderate.yml` +
  `group.role.community_group-groups_moderate.yml` exist with `scope: insider`, `admin: true`,
  `global_role: groups_moderate`.

Reads config YAML directly off disk (`docs/groups/config/`) using the exact ascend-from-`__DIR__`
technique already proven in `do_tests/tests/src/Functional/GroupAddFormFieldsTest.php::
locateFormDisplayYaml()` — the cheapest sufficient tier for "does this exact file exist with this
exact shape"; no Drupal bootstrap needed.

**`docs/groups/modules/do_group_membership/tests/src/Unit/MembershipStatusFieldConfigShapeTest.php`** (3 tests)
- `testFieldStorageShape` — **AC-4**: `field.storage.group_relationship.field_membership_status.yml`
  is `list_string` with exactly `active`/`pending`/`blocked`.
- `testFieldInstanceAttachedToMembershipBundle` — **AC-4**: the field instance is attached
  specifically to the `community_group-group_membership` bundle.
- `testNoNewJoinedDateFieldAdded` — **[B-4]** (guards against scope creep): asserts NO
  `field_joined_date` storage config exists — "joined date" reuses the relationship's base `created`
  field per the brief's locked resolution, so a new field here would be an over-build.

**`docs/groups/modules/do_group_membership/tests/src/Unit/GroupMembershipManagerTest.php`** (9 tests)
- `testAddMemberCreatesActiveRelationship` — **AC-14/[B-6]**: `addMember()` defaults new memberships
  to `active` status (not `pending` — that's the join-request territory, out of scope).
- `testChangeRoleMutatesOnlyGroupRolesField` — **AC-5/[B-3]**: role change never touches
  `field_membership_status` (the orthogonality rule).
- `testChangeStatusBlocksActiveMember` / `testChangeStatusUnblocksMember` — **AC-5**: `active ⇄
  blocked` is symmetric, entity is never deleted.
- `testRemoveMemberDeletesRelationship` — **AC-5**: `<any> → <deleted>`.
- `testApprovePendingSetsActiveStatus` — **AC-5**: `pending → active`.
- `testDenyPendingDeletesRelationship` — **AC-5**: `pending → <deleted>` (NOT `blocked` — the exact
  distinction [B-3] locks: deny means "never joined," blocked means "was in, now banned").
- `testApprovePendingOnAlreadyResolvedRequestIsNoOp` / `testDenyPendingOnAlreadyResolvedRequestIsNoOp`
  — **AC-10**: a `NULL` (already-resolved/vanished) relationship is a no-op returning `FALSE`, not a
  fatal error — the concurrent-organizer race.

Mocked `EntityTypeManagerInterface` + `GroupInterface`/`GroupRelationshipInterface` per the
mandatory Services-over-Hooks Unit-testing pattern (`docs/playbook/frameworks/drupal/best-practices.md`).
AC-9's last-Organizer guard is deliberately **NOT** unit-tested with mocks — it needs a real "count
active Organizers" query, and mocking that chain would encode implementation, not behavior; it is
covered end-to-end at Kernel tier instead (see below), which is both cheaper to keep correct and a
stronger assertion. This tier/scope decision is recorded in the test file's own doc comment.

### Kernel tier (14 tests, 2 files) — bootstrapped container, DB, config, entities

**`docs/groups/modules/do_group_membership/tests/src/Kernel/ManageMembersAccessTest.php`** (4 tests)
- `testOrganizerHasAdministerMembers` / `testModeratorHasAdministerMembers` — **AC-6**: the
  `administer members` permission the route's `_custom_access` callback checks resolves TRUE for
  both roles on their own group.
- `testPlainMemberLacksAdministerMembers` — **AC-11**: the negative case backing access-denied.
- `testGroupsModerateUserManagesGroupTheyNeverJoined` — **AC-12 + Handoff-A warn-2**: the empirical
  closure A's Finding #2 asked for. Creates a user holding ONLY the site-level `groups_moderate`
  role (via `UserCreationTrait::createRole()` + `addRole()`), asserts `$group->getMember($account)`
  is falsy (no `group_relationship` entity at all — genuinely never joined), THEN asserts
  `$group->hasPermission('administer members', $account)` is TRUE — proving the synchronized
  `scope: insider` + `global_role: groups_moderate` + `admin: true` config entity alone grants the
  bypass, with zero per-group membership. This is exactly the mechanism the brief's [B-5] correction
  and A's warn-2 needed closed at GREEN.

Reuses `GroupsKernelTestBase` (do_tests) per the task instructions — no group-type bootstrap
reinvented.

**`docs/groups/modules/do_group_membership/tests/src/Kernel/GroupMembershipManagerKernelTest.php`** (10 tests)

Behavioral Kernel-tier proof that the Unit-tier mocked contract is backed by real
`group_relationship` entity persistence:
- `testApprovePendingTransitionsToActive`, `testDenyPendingDeletesTheRelationship`,
  `testBlockAndUnblockAreSymmetricAndPreserveRelationship`, `testChangeRoleDoesNotTouchMembershipStatus`
  — **AC-5**, real-entity versions of the Unit-mocked transitions above.
- `testRemoveMemberRefusesLastOrganizer` — **AC-9**: the real, query-backed last-Organizer guard
  (deliberately NOT unit-mocked, see above) — refuses with `LastOrganizerGuardException`, membership
  still exists afterward.
- `testRemoveMemberAllowedWhenAnotherOrganizerRemains` — **AC-9**: the guard is "last remaining," not
  "any" — removal succeeds when a second active Organizer exists.
- `testChangeRoleRefusesToDemoteLastOrganizer` — **AC-9**: the guard also covers demotion via
  `changeRole()`, not just outright removal, per the brief's "remove or demote" wording.
- `testApprovePendingRaceIsNoOp` — **AC-10**: genuinely deletes a relationship mid-flow (simulating a
  concurrent deny) then confirms `approvePending()` on the vanished id returns `FALSE`, not a fatal
  error.
- `testAddMemberRejectsExistingMembershipAnyStatus` — **AC-8**: rejects (throws
  `DuplicateMembershipException`) adding a user who already has a `blocked`-status relationship (not
  just `active` — "any status" per the brief).
- `testAddMemberRejectsBlockedUserAccount` — **AC-8**: rejects (throws `BlockedAccountException`) a
  Drupal-blocked (`$account->isBlocked()`) target account.

### Functional tier (7 tests, 2 files) — full HTTP request, browser-less

**`docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteAccessTest.php`** (4 tests)
Mirrors `do_tests/tests/src/Functional/GroupAccessEnforcementTest.php`'s enforcement-on-the-wire
pattern (Kernel proves the calculated permission; Functional proves the route is actually gated):
- `testOrganizerCanAccessManageMembers` — **AC-6**: real 200.
- `testPlainMemberGetsAccessDenied` — **AC-11**: real 403.
- `testSiteAdminEscapeHatchGrantsAccess` — **AC-6**: the `administer group` OR-branch, mirroring the
  RUNBOOK's `/admin/groups/pending` precedent cited in [B-2].
- `testUnprivilegedAuthenticatedUserGetsAccessDenied` — **AC-11**: baseline negative case (logged in,
  no group role, no site escape hatch).

**`docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersPageRenderTest.php`** (3 tests)
- `testMemberListRendersAsRealTableWithScopedHeaders` — **AC-7/AC-15**: a real `<table>` with at
  least one `<th scope="col">`, not a div-grid.
- `testStatusBadgeCarriesVisibleTextNotColorAlone` — **AC-15**: a `[data-state="active"]` badge
  element's own text content includes the word "Active" — meaning present in accessible text, not
  only a CSS class.
- `testEmptyGroupShowsGuidingEmptyStateCopy` — **AC-7 / wireframe Screen 2**: the truthful "This
  group has no members yet…" copy renders for a zero-relationship group.

### Playwright (4 tests, 1 file) — `tests/e2e/manage-members.spec.ts`

- `organizer changes a member role via the Manage-members page` — **AC-14**: end-to-end role-change
  flow (add a member, open `[Role ▾]`, check Moderator, Save, assert the row updates).
- `organizer approves a pending membership request` — **AC-14**: end-to-end approval flow. **Flagged
  limitation:** this story has no pending-request *seeding* UI of its own (join-request UI is #121's
  territory per the brief's non-goals) — the test looks for a `tr[data-status="pending"]` row and
  `test.skip()`s with an explicit, named reason if none exists in a fresh build, rather than silently
  passing or failing for the wrong reason. AC-5/AC-10's approve/deny behavior is already fully pinned
  at Kernel/Unit tier regardless.
- `every row action is a real, keyboard-reachable button` — **AC-7/AC-15**: every Actions-column
  control is a real `<button>` DOM element (not a div click-handler), and Tab moves focus to a real
  interactive element.
- `status badge text is visible (non-color-alone) on the rendered page` — **AC-15**: same assertion
  as the Functional badge test, exercised in a live browser.

**Explicitly flagged gap (not silently skipped):** AC-15's "axe-clean (or documented exceptions)"
WCAG scan is **not automated** in this Playwright suite — `package.json` carries no
`@axe-core/playwright` dependency (checked; only `@playwright/test` is a devDependency), and adding
a new tooling dependency is outside T's remit (T authors tests, not production/tooling deps). Either
F adds the dependency in this story, or this becomes an explicit manual/documented-exception pass by
**U** (UI Walkthrough). Recorded here and in the spec's own file-level comment so it reaches O.

## RED confirmation

**Mode used: MIXED — real execution for the Unit tier, static validation for Kernel/Functional.**

### Real execution (Unit tier, all 16 tests)

No `vendor/` exists in this worktree (confirmed absent — matches Handoff-A's Finding #2, which
already noted this for the whole story). A real, read-only-safe local vendor tree DOES exist in the
sibling reference checkout `~/Projects/groups-on-d11` (its own composer-installed `vendor/`,
untouched by this run — I only *invoked* its `vendor/bin/phpunit` binary as an external tool,
pointing `-c` at its `web/core/phpunit.xml.dist` and the test *targets* at this worktree's files; no
file in that reference repo was created, edited, or deleted).

```
cd ~/Projects/groups-on-d11
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  /Users/.../groups-0138-mc7-manage-members/docs/groups/modules/do_group_membership/tests/src/Unit/
```

Result: **16 tests, 9 errors, 5 failures, 2 passes** — every failing test fails for the RIGHT reason:

- 9x `Error: Class "Drupal\do_group_membership\GroupMembershipManager" not found` (all of
  `GroupMembershipManagerTest` except the 2 no-op tests, which pass because they only assert
  `assertFalse($manager->approvePending(NULL))` … wait, those also need the class — see below) — the
  manager service genuinely does not exist yet (F has written zero production code). This is the
  expected first-RED for a brand-new module: the class-not-found IS the on-topic assertion (the exact
  symbol the brief specifies), not a bootstrap/typo error.
- 5x `Failed asserting that null is not null` on `assertNotNull($file, "... exists.")` in
  `GroupRoleConfigShapeTest` (3x: organizer, moderator, groups_moderate role configs) and
  `MembershipStatusFieldConfigShapeTest` (2x: field storage + field instance configs) — the exact
  config YAML files AC-1/AC-2/AC-4/AC-13 require do not exist yet.
- 2x genuine PASS: `testMemberRoleConfigUnchanged` (the pre-existing `community_group-member.yml`
  already matches its expected unchanged shape — correctly green with zero new code, since AC-3 is
  "leave this alone") and `testNoNewJoinedDateFieldAdded` (correctly green because no
  `field_joined_date` file exists yet — the assertion IS "this file must NOT exist," so its current
  absence is the correct state both before and after F's work; this is not a mis-authored RED, it's a
  guard against future scope creep that should stay green through GREEN too).

(Re-checking the 9-error breakdown: `GroupMembershipManagerTest` has 9 test methods, all fail on
`new GroupMembershipManager(...)` in `makeManager()` before their mocked-dependency assertions can
even run — including the two no-op tests, since `makeManager()` is called in every test method. All
9 are class-not-found errors, confirmed in the full run output below.)

Full failing output (excerpt, `GroupRoleConfigShapeTest`):
```
✘ Organizer role config shape
  │ docs/groups/config/group.role.community_group-organizer.yml exists.
  │ Failed asserting that null is not null.
✘ Moderator role config shape
  │ docs/groups/config/group.role.community_group-moderator.yml exists.
  │ Failed asserting that null is not null.
✔ Member role config unchanged
✘ Groups moderate role config shape
  │ user.role.groups_moderate.yml exists.
  │ Failed asserting that null is not null.
```

`GroupMembershipManagerTest` (excerpt):
```
✘ Add member creates active relationship
  │ Error: Class "Drupal\do_group_membership\GroupMembershipManager" not found
✘ Change role mutates only group roles field
  │ Error: Class "Drupal\do_group_membership\GroupMembershipManager" not found
[... same error for all 9 methods ...]
```

`MembershipStatusFieldConfigShapeTest`:
```
✘ Field storage shape
  │ field.storage.group_relationship.field_membership_status.yml exists.
  │ Failed asserting that null is not null.
✘ Field instance attached to membership bundle
  │ The field instance config exists on the community_group-group_membership bundle.
  │ Failed asserting that null is not null.
✔ No new joined date field added
```

### Static validation (Kernel + Functional tiers, 21 tests)

Kernel tests could not be executed: this worktree has no `vendor/`, and a real attempt to run them
against the reference checkout's `vendor/bin/phpunit` failed for an infrastructure reason, not a test
authorship reason — `Class "Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase" not found`, because
Drupal-core Kernel tests execute in a separate PHP process PHPUnit spawns per test, so a custom
`auto_prepend_file` autoloader registered in the parent process does not propagate; the reference
checkout's own generated autoloader has no knowledge of classes living only in this worktree's
`web/modules/custom` tree. This is a sandboxing/process-isolation limitation, confirmed by directly
observing the failure (not assumed) — not something a test-authorship fix can address.

Given that, every Kernel/Functional test was validated **statically**, by cross-checking every real
Drupal/Group API call against the actual `drupal/group` 4.0.x source present in the reference
checkout (`~/Projects/groups-on-d11/web/modules/contrib/group/src/Entity/Group.php`,
`web/core/modules/user/tests/src/Traits/UserCreationTrait.php`, `web/core/tests/Drupal/KernelTests/
Core/Entity/EntityKernelTestBase.php`, `web/modules/contrib/group/tests/src/Traits/GroupTestTrait.php`):

- `Group::hasPermission($permission, AccountInterface $account)` — signature confirmed.
- `Group::addMember(UserInterface $account, $values = [])` — confirmed it forwards `$values`
  (including arbitrary field names like `field_membership_status`) straight into
  `addRelationship()`, so `['group_roles' => [...], 'field_membership_status' => [...]]` is valid.
- `Group::getMember(AccountInterface $account)` — confirmed it returns `GroupMembership::
  loadSingle()`, which is **falsy (not literal NULL)** for a non-member; my assertions use
  `assertEmpty()`/`assertFalse((bool) ...)` accordingly, not `assertNull()`.
- `Group::getRelationshipsByEntity($entity, $plugin_id)` — confirmed, and the membership plugin id is
  confirmed as `'group_membership'` (`GroupMembership.php` plugin annotation).
- `GroupTestTrait::createGroupRole(array $values = [])` — confirmed it passes `$values` straight to
  `group_role` entity `create()`, so `id`/`group_type`/`scope`/`admin`/`permissions`/`global_role`
  keys (already verified against the real assembled YAML shape) are all valid.
- `UserCreationTrait::createRole(array $permissions, $rid = NULL, ...)` — confirmed it **returns a
  string** role id, not an object. **This caught a real bug**: `ManageMembersAccessTest` originally
  called `$moderate_role->id()` on the return value; fixed to use the string directly
  (`$account->addRole('groups_moderate')`) before finalizing this handoff.
- `EntityKernelTestBase::$modules` already includes `field`, so `FieldStorageConfig`/`FieldConfig`
  entity types are installable without redeclaring `field` in the test's own `$modules` (Drupal's
  KernelTestBase merges `$modules` across the full class hierarchy — confirmed this is the standing
  pattern already used by `GroupsKernelTestBase` itself, which redeclares `['group', 'gnode',
  'options', 'node']` rather than the full inherited list).
- `EntityStorageInterface::loadUnchanged($id)` — confirmed exists on the interface.

All 8 Kernel/Functional test files additionally pass `php -l` (PHP 8.3.31) with zero syntax errors.

**Why this static-validation mode is legitimate RED evidence, not a fake run:** every assertion in
these 21 tests targets a symbol that provably does not exist yet in this worktree (no
`do_group_membership.info.yml`, no `GroupMembershipManager` class, no route, no controller) — the
SAME "class/route/permission not found" failure mode the Unit tier's real run already demonstrated
for the sibling tests in the same brand-new module. There is no plausible mechanism by which these
21 tests would pass against the current (nonexistent) production code; the only open question was
whether they'd fail for an ON-TOPIC reason (missing symbol) vs. an AUTHORING bug (wrong API usage),
which the byte-for-byte source cross-check above closes — and which caught one real bug pre-emptively
(the `createRole()` return-type fix).

## Ready for F

**Confirmed RED is valid.** F may implement `do_group_membership` against these 41 tests. Note for
F (relayed from Handoff-A's warn-1): base the manager service's class + `*.services.yml` wiring on
the playbook's own `MyModuleManager` example shape (constructor-injected `EntityTypeManagerInterface`
at minimum — additional DI is fine), not `do_notifications`'s inline-logic controller. The manager's
public contract these tests pin: `addMember(GroupInterface, AccountInterface, array $roles):
GroupRelationshipInterface`, `changeRole(GroupRelationshipInterface, array $roles): void`,
`changeStatus(GroupRelationshipInterface, string $status): void`,
`removeMember(GroupRelationshipInterface): void`, `approvePending(?GroupRelationshipInterface): bool`,
`denyPending(?GroupRelationshipInterface): bool`, throwing `Drupal\do_group_membership\Exception\
{LastOrganizerGuardException, DuplicateMembershipException, BlockedAccountException}` as appropriate.

## Flags for O (not silently skipped)

1. **AC-15's axe-core WCAG scan is not automated** — no `@axe-core/playwright` dependency in this
   repo. Needs an F-added dependency or a manual/documented-exception pass by U. See the Playwright
   spec's own file comment.
2. **The pending-approval Playwright test self-skips** if no pending-request fixture path exists in
   the build (this story doesn't own join-request seeding UI, #121 does) — AC-5/AC-10's actual
   approve/deny behavior is fully covered at Kernel/Unit tier regardless, so this is not a coverage
   gap, only a named E2E-activation gap for a future story.
3. **Kernel/Functional tests are RED-by-static-validation, not RED-by-real-execution**, due to this
   sandbox's environment limits (no vendor/ in the worktree; process-isolated Kernel tests can't be
   bridged with a custom autoloader). At Phase 6 (GREEN), once F's branch has a real `composer
   install` (or DDEV) available, these MUST be executed for real — the static validation here is
   sufficient to unblock F's start, not a substitute for the real GREEN run.
