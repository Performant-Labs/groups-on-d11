# Handoff-T-green: Phase 6 - Issue #138 (MC-7) Organizer manage-members UI + group roles + Groups Moderate

**Date:** 2026-07-22
**Branch:** 0138-mc7-manage-members
**Issue:** #138
**Handoff-F reviewed:** `docs/handoffs/0138-mc7-manage-members/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/0138-mc7-manage-members/handoff-T-red.md`

## ADJUDICATION: `scope: insider` → `scope: outsider` — F is RIGHT

F changed `docs/groups/config/group.role.community_group-groups_moderate.yml` from the brief's
locked `scope: insider` to `scope: outsider`. I did **not** accept this on F's word — I
independently re-derived it from real Group 4.0.x source and confirmed it empirically on a real DB.

**Source citation** (fetched directly from `git.drupalcode.org/project/group` @ `4.0.x`, not F's
paraphrase):

```php
// src/Access/GroupPermissionChecker.php
public function hasPermissionInGroup($permission, AccountInterface $account, GroupInterface $group) {
  $calculated_permissions = $this->groupPermissionCalculator->calculateFullPermissions($account);
  $item = $calculated_permissions->getItem(PermissionScopeInterface::INDIVIDUAL_ID, $group->id());
  if ($item && $item->hasPermission($permission)) { return TRUE; }

  // Then check their synchronized access depending on if they are a member.
  if (GroupMembership::loadSingle($group, $account)) {
    $item = $calculated_permissions->getItem(PermissionScopeInterface::INSIDER_ID, $group->bundle());
  }
  else {
    $item = $calculated_permissions->getItem(PermissionScopeInterface::OUTSIDER_ID, $group->bundle());
  }
  return $item && $item->hasPermission($permission);
}
```

```php
// src/PermissionScopeInterface.php
const OUTSIDER_ID = 'outsider';  // people who do NOT belong to a group
const INSIDER_ID = 'insider';    // people who DO belong to a group
const SYNCHRONIZED_IDS = [self::OUTSIDER_ID, self::INSIDER_ID];  // both valid global_role scopes
```

`GroupMembership::loadSingle($group, $account)` truthy → `INSIDER_ID` scope item is consulted.
Falsy (never joined) → `OUTSIDER_ID` scope item is consulted. A Groups-Moderate account is, by
design (brief's own OQ-4), never a member of the group it moderates — no `group_relationship`
exists. Therefore `scope: insider` can **never** grant it `administer members`, regardless of
`admin: true`; only `scope: outsider` can.

**Empirical confirmation** (real MySQL 8 Docker container, real Kernel PHPUnit run, this worktree):
flipped `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined()`'s `setUp()`
group-role creation from `PermissionScopeInterface::INSIDER_ID` to `OUTSIDER_ID` with zero other
change:

- `INSIDER_ID`: **FAILS** — `Failed asserting that false is true.` at
  `ManageMembersAccessTest.php:140`.
- `OUTSIDER_ID`: **PASSES** (deprecation-only ⚠, no assertion failure).

**Verdict: F is correct.** The brief's Round-1 [B-5] resolution and its locked AC-13/§B-5 text
(`scope: insider`) are empirically wrong. This is not F guessing — it is provable from the module's
own access-checking source, and I reproduced the proof independently rather than trusting F's
report.

**Tests corrected (my tests, documented reason, inline citation in the file):**
- `docs/groups/modules/do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php` —
  `testGroupsModerateRoleConfigShape()` now asserts `scope: outsider`.
- `docs/groups/modules/do_group_membership/tests/src/Kernel/ManageMembersAccessTest.php` —
  `setUp()`'s `community_group-groups_moderate` group-role creation now uses
  `PermissionScopeInterface::OUTSIDER_ID`.

Both files carry an inline doc comment citing the Group source + the empirical result, per the
"legitimate test-correction, not a weakened test" standard. **Flagging for O:** the brief's
AC-13/[B-5] text should be corrected from `scope: insider` to `scope: outsider` to match reality.

## GREEN confirmation

Real execution only — no static validation this phase. Environment: worktree's own `composer
install` (PHP 8.3.31, Drupal core 11.4.4, PHPUnit 11.5.56), a dedicated `gm138-mysql` Docker MySQL
8 container (port 13306), `scripts/ci/assemble-config.sh` run to materialize
`web/modules/custom/do_group_membership` + `config/sync/*`, and a `php -S 127.0.0.1:8080
web/.ht.router.php` server for the Functional tier (mirrors `.github/workflows/test.yml` exactly).

```
export SIMPLETEST_DB='mysql://root:root@127.0.0.1:13306/drupal'
export SIMPLETEST_BASE_URL='http://127.0.0.1:8080'
export SYMFONY_DEPRECATIONS_HELPER='disabled'
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/{Unit,Kernel,Functional}
```

**Unit tier: 16/16 real GREEN.**
```
Group Membership Manager
 ✔ Add member creates active relationship
 ⚠ Change role mutates only group roles field
 ⚠ Change status blocks active member
 ⚠ Change status unblocks member
 ✔ Remove member deletes relationship
 ⚠ Approve pending sets active status
 ✔ Deny pending deletes relationship
 ✔ Approve pending on already resolved request is no op
 ✔ Deny pending on already resolved request is no op
Group Role Config Shape
 ✔ Organizer role config shape
 ✔ Moderator role config shape
 ✔ Member role config unchanged
 ✔ Groups moderate role config shape
Membership Status Field Config Shape
 ✔ Field storage shape
 ✔ Field instance attached to membership bundle
 ✔ No new joined date field added
```
(⚠ = deprecation-only, still a pass — 0 assertion failures.)

**Kernel tier: `ManageMembersAccessTest` 4/4 real GREEN.**
```
Manage Members Access
 ✔ Organizer has administer members
 ✔ Moderator has administer members
 ✔ Plain member lacks administer members
 ⚠ Groups moderate user manages group they never joined
```
`GroupMembershipManagerKernelTest`: **0/10** — blocked by a confirmed pure-core config-schema bug
(see below), not a code or test-authorship defect. Kernel total: **4/14 real GREEN**, 10 env-blocked
(not disproven).

**Functional tier:** `ManageMembersRouteAccessTest` **0/4** (confirmed pre-existing env limitation,
see below); `ManageMembersPageRenderTest` **0/3** (same core bug as Kernel). Functional total:
**0/7 real GREEN**, all 7 env-blocked (not disproven).

**Spot-check that tests still fail if behavior is removed:** confirmed directly by the adjudication
process itself — `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined()`
demonstrably fails when the config value it pins (`scope: outsider`) is wrong (reverted to
`insider`), and passes only when the real behavior is correct. This is not a vacuous assertion.

## Independently confirmed: the two env-blocking issues are real, not code defects

### 1. Pure Drupal-core `list_string` config-schema bug (blocks 13 tests: 10 Kernel + 3 Functional)

Reproduced with a throwaway scratch Kernel test (`ScratchListStringBugTest`, deleted after
confirming) containing **zero** `do_group_membership` code — only `options` + `field` core
modules, a bare `list_string` field storage on the stock `user` entity type:

```
InvalidArgumentException: The configuration property settings.allowed_values.0.label.0 doesn't exist.
```
at `web/core/lib/Drupal/Core/Config/Schema/ArrayElement.php:100`, identical stack trace to all 13
blocked tests. This confirms F's own diagnosis: a genuine Drupal 11.4.4 (released 2026-07-15, one
week old at the time of this run) + `options` module config-schema issue on this exact
composer-resolved package set, not a defect in `do_group_membership`'s production code or its
tests' authorship. All 13 blocked tests fail at identical `setUp()`-level stack traces — uniform,
not a mix of real+fake failures.

### 2. `drupalLogin()` environment limitation (blocks `ManageMembersRouteAccessTest`, 4 tests)

Ran the **untouched sibling** `do_tests/tests/src/Functional/GroupAccessEnforcementTest.php` for
real (not assumed) — it fails identically:

```
User efvg1lx7 successfully logged in.
Failed asserting that false is true.
at web/core/tests/Drupal/Tests/UiHelperTrait.php:190
```

Same failure mode, same line, on a test this story never touched. Confirms F's claim: a genuine,
pre-existing, code-independent `BrowserTestBase`/cookie-session limitation in this sandbox
(non-DDEV `php -S` webserver), not a `do_group_membership` defect.

## Test corrections made (T's own tests, documented)

1. **`GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape`** (Unit) — `insider` → `outsider`
   per the adjudication above. Legitimate correction (empirically wrong locked value), not a
   weakened assertion.
2. **`ManageMembersAccessTest::setUp()`** (Kernel) — same correction, `INSIDER_ID` → `OUTSIDER_ID`.
3. **`GroupMembershipManagerTest::testAddMemberCreatesActiveRelationship`** (Unit) — mocked
   `$account` as `AccountInterface`, but real `GroupInterface::addMember(UserInterface $account,
   ...)` (confirmed against 4.0.x source) declares a strict `UserInterface` parameter; PHPUnit
   enforces the real method's type on a mocked call regardless of implementation. Fixed the mock to
   `UserInterface`. Genuine T-authorship bug (too-loose mock type), not F's code — this test now
   passes for real, confirming F's `addMember()` implementation is correct.
4. **`ManageMembersRouteAccessTest::testUnprivilegedAuthenticatedUserGetsAccessDenied`**
   (Functional) — a redundant `createRole([], RoleInterface::AUTHENTICATED_ID)` call threw
   `EntityStorageException: 'user_role' entity with ID 'authenticated' already exists`.
   `BrowserTestBase`'s own site install already creates this role; no sibling Functional test in
   this repo makes this call (checked). Removed the redundant call and the now-unused
   `RoleInterface` import. Genuine T-authorship bug (copied from the Kernel-tier convention where it
   is legitimately needed). This test now fails uniformly with its 3 siblings on the confirmed
   `drupalLogin()` env limitation instead of a spurious setup error — closes out the class cleanly
   (all 4 blocked for the same, single, confirmed-external reason).

I did **not** edit F's production code anywhere.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| phpcs (src/) | `vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_group_membership/src` | 0 errors | 0 errors, 0 warnings | PASS |
| phpstan level 1 (src/) | `vendor/bin/phpstan analyse --level=1 docs/groups/modules/do_group_membership/src` | 0 real findings | 4 findings, all standard Drupal `new static()` factory pattern (matches every sibling `create()`) | PASS |
| phpunit (module suite) | see above | new + existing tests green | Unit 16/16, Kernel 4/4 (`ManageMembersAccessTest`) + 10 env-blocked, Functional 0/7 env-blocked | PARTIAL — see Blocking issues |
| Module install | `drush en do_group_membership -y` | clean success | `[success] Module do_group_membership has been installed.` (independently re-run against a fresh `drush site:install`) | PASS |
| `scripts/ci/assemble-config.sh` | run multiple times | clean, no drift | `copied 95 file(s), excluded 7`, `copied 11 custom module(s)`, no missing-file warnings | PASS |
| Docs checks | N/A | N/A | This story touches no `docs/src/content/` Astro/Keystatic content | N/A |

phpcs on `tests/` surfaced pre-existing (Phase-4-authored, not introduced this phase) docblock
short-description-wrapping nits in `GroupMembershipManagerKernelTest.php` (untouched by my
adjudication) — advisory only, does not touch production code, not gating.

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage per AC | Cross-checked all 15 ACs against the 41 authored tests (handoff-T-red.md) | Every AC has at least one test; AC-5/AC-8/AC-9/AC-10 are currently env-blocked (not code-disproven) at Kernel tier |
| Test quality | Reviewed each test for behavior-not-implementation assertions, tier placement, duplication | Clean — no redundant tests found; AC-9's Kernel-only placement (not unit-mocked) remains the correct call (avoids over-mocking a query chain) |
| Access/security | `ManageMembersAccessTest` proves permitted roles (Organizer/Moderator/Groups-Moderate) pass and the negative case (plain Member) is denied, at the real `Group::hasPermission()` layer the route's `_custom_access` calls | PASS — real positive + negative cases, not `_access: 'TRUE'` |
| Config/schema | `field.storage.group_relationship.field_membership_status.yml` ships with the field storage; the config entity round-trips via `FieldStorageConfig::create()->save()` (blocked only by the confirmed core bug, not disproven) | PASS (config shape verified at Unit tier; entity persistence env-blocked) |
| Data integrity | `testAddMemberRejectsExistingMembershipAnyStatus`/`testAddMemberRejectsBlockedUserAccount`/`testApprovePendingRaceIsNoOp` cover duplicate-key and vanished-record edge cases | Authored, env-blocked at Kernel tier (not disproven) |
| Migration/update safety | No `hook_update_N` in this story (new module + new config, no schema migration of existing data) | N/A |
| Deprecations | phpstan on `src/` shows only the standard `new static()` pattern; no deprecated Drupal API use flagged | PASS |

## Acceptance criteria status

| AC | Backed by | Status |
|---|---|---|
| AC-1 | `GroupRoleConfigShapeTest::testOrganizerRoleConfigShape` | PASS (real GREEN) |
| AC-2 | `GroupRoleConfigShapeTest::testModeratorRoleConfigShape` | PASS (real GREEN) |
| AC-3 | `GroupRoleConfigShapeTest::testMemberRoleConfigUnchanged` | PASS (real GREEN) |
| AC-4 | `MembershipStatusFieldConfigShapeTest` (2 tests) | PASS (real GREEN) |
| AC-5 | `GroupMembershipManagerTest` (Unit, mocked transitions) + `GroupMembershipManagerKernelTest` (real entities) | PASS at Unit tier (real GREEN); Kernel-tier real-entity confirmation env-blocked (not disproven) |
| AC-6 | `ManageMembersAccessTest::testOrganizerHasAdministerMembers`/`testModeratorHasAdministerMembers` (Kernel) + `ManageMembersRouteAccessTest` (Functional, HTTP-level) | PASS at Kernel tier (real GREEN); Functional HTTP-level confirmation env-blocked (not disproven) |
| AC-7 | `ManageMembersPageRenderTest::testMemberListRendersAsRealTableWithScopedHeaders` | Authored, env-blocked (core bug), not disproven |
| AC-8 | `GroupMembershipManagerKernelTest::testAddMemberRejects*` (2 tests) | Authored, env-blocked (core bug), not disproven |
| AC-9 | `GroupMembershipManagerKernelTest::testRemoveMemberRefusesLastOrganizer`/`testChangeRoleRefusesToDemoteLastOrganizer`/`testRemoveMemberAllowedWhenAnotherOrganizerRemains` | Authored, env-blocked (core bug), not disproven |
| AC-10 | `GroupMembershipManagerTest::testApprovePendingOnAlreadyResolvedRequestIsNoOp`/`testDenyPendingOnAlreadyResolvedRequestIsNoOp` (Unit) + `GroupMembershipManagerKernelTest::testApprovePendingRaceIsNoOp` (Kernel) | PASS at Unit tier (real GREEN); Kernel-tier confirmation env-blocked (not disproven) |
| AC-11 | `ManageMembersAccessTest::testPlainMemberLacksAdministerMembers` (Kernel) + `ManageMembersRouteAccessTest` negative cases (Functional) | PASS at Kernel tier (real GREEN); Functional confirmation env-blocked (not disproven) |
| AC-12 | `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined` | **PASS (real GREEN)** — the exact adjudicated mechanism, empirically confirmed |
| AC-13 | `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape` (corrected to `outsider`) | PASS (real GREEN) |
| AC-14 | `GroupMembershipManagerTest` (9 Unit tests, all core API methods) + Playwright spec (not run this phase — Node/Playwright execution is out of PHP Tier-1 scope, per T-red's own scoping) | PASS at Unit tier (real GREEN); E2E not exercised this phase |
| AC-15 | Real `<table>`/badge markup (Functional, env-blocked); `:focus-visible` CSS (module CSS, present, not independently re-verified by me this phase); axe-core scan (not automated — see below) | **Open** — hands to U per F's documented decision |

## Blocking issues

**None that route back to F.** The two env-blocking conditions (core `list_string` schema bug;
`drupalLogin()` sandbox limitation) are independently confirmed, in both cases, to reproduce with
**zero** `do_group_membership` code involved — they are not F's defects and not routable back to F.
They should be flagged to O as environment/infrastructure issues to resolve (a clean DDEV or CI run,
where the sibling test suite already passes per `.github/workflows/test.yml`'s green history) before
final merge confidence on the 13 Kernel + 4 Functional tests currently env-blocked.

**AC-15 remains open** pending U's manual/documented-exception axe-core pass — this is expected
per F's documented, pre-authorized decision (no `@axe-core/playwright` dependency exists in this
repo; independently confirmed absent from `package.json`), not a defect.

## Advisory notes

- `tests/src/Kernel/GroupMembershipManagerKernelTest.php` carries pre-existing (Phase-4) phpcs
  docblock-wrapping nits (9 findings) unrelated to this phase's edits — cosmetic, non-blocking,
  worth a quick cleanup pass before merge but does not affect correctness or coverage.
- The 13 Kernel/Functional tests blocked by the core `list_string` bug are a real coverage gap in
  *this specific sandbox* only — they are correctly authored, correctly RED-then-implemented, and
  will very likely pass unmodified in a clean DDEV/CI environment (the CI workflow already runs a
  fresh `composer install` against a pinned `composer.lock`, which may or may not carry the same
  `drupal/core: 11.4.4` resolution that triggered this bug here — worth confirming on the next CI
  run rather than assuming).
- Recommend O re-run the full suite once in actual CI (or a fresh DDEV bring-up) before merge, to
  close the loop on the 17 env-blocked tests with a second, independent real-execution data point.

## Docker hygiene

Created only `gm138-mysql` (raw Docker MySQL 8, port 13306 — not DDEV; the DDEV project
`pl-groups-on-d11` is bound to a different, non-worktree directory `~/Sites/pl-groups-on-d11` and
was left untouched/not started). Removed only `gm138-mysql` on teardown. `docker ps -a` before and
after this session shows every pre-existing container (including all `ddev-*` siblings) unchanged.
