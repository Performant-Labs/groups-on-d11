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

---

## Round-2 re-verify (after F's diff-gate round-1 fixes: real pagination + W-2/NIT sweep)

**Date:** 2026-07-22
**Handoff-F reviewed (round 2):** `docs/handoffs/0138-mc7-manage-members/handoff-F.md`, "Diff-gate
round-1 fixes" section.

### The gap F left, and why it needed a test

F's "Diff-gate round-1 fixes" fixed `[B-1]` (the pagination BLOCK: the pager was previously
initialized against the count of the ALREADY-sliced array, so it never activated) by fetching the
full membership list first, calling `PagerManagerInterface::createPager(count($all_memberships),
50)`, then `array_slice()`-ing to the current page. F's own handoff explicitly says under "Test T
should look at": **"None from this round — no test needed changing for the pagination refactor."**
That is wrong on its own terms — the BLOCK existed in the first place precisely because **no test
in the suite exercised >50 members**, so the broken pager was invisible to the whole suite. A fix
with no covering test is not verified, it's asserted. Added one.

### New test: `ManageMembersPaginationTest`

**File:** `docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersPaginationTest.php`
(materialized copy: `web/modules/custom/do_group_membership/tests/src/Functional/ManageMembersPaginationTest.php`
via `scripts/ci/assemble-config.sh`).

**Tier:** Functional (`BrowserTestBase`). Not Kernel: proving *rendered row count on page 1 vs.
page 2* requires walking `buildForm()`'s actual render output through a real HTTP
request/response cycle (`drupalGet()` + `?page=1`), which a Kernel-only entity-storage assertion
cannot exercise — this is the cheapest sufficient tier for a pager-driven UI assertion. Mirrors
`ManageMembersPageRenderTest`'s exact fixture setup (`createGroupType`/`createGroupRole`/
`FieldStorageConfig`/`FieldConfig` for `field_membership_status`) for consistency with the sibling
Functional test.

**What it asserts** (`testMemberTablePaginatesAt50RowsAndGuardSeesWholeGroup`):
1. Seeds 55 total memberships: 2 active Organizers (one added early, one — the viewing user —
   added last, so a real Organizer lands late in insertion order) + 53 plain Members.
2. Fixture sanity: `$group->getMembers()` returns exactly 55; `$group->getMembers(['community_group-organizer'])`
   returns exactly 2 — proves the fixture itself is correct before touching the UI.
3. Loads `/group/{group}/members` as the (page-2-destined) Organizer: asserts **exactly 50**
   `<table tbody tr>` rows render on page 1, and a pager element (`.pager`, `nav[aria-label="Pagination"]`,
   or `ul.pager__items`) is present — i.e. a REAL initialized pager, not a dead `#type => pager`
   markup stub.
4. Loads page 2 (`?page=1`): asserts the remaining **5** rows render.
5. On BOTH pages, asserts the last-Organizer guard note ("Last Organizer — promote another member
   first.") is absent — proving `countActiveOrganizers()` (called against the full `$group`, never
   the paginated slice — confirmed by reading `ManageMembersForm.php` lines 80-87 and 454-462) sees
   both Organizers regardless of which page is being viewed, i.e. pagination cannot fool the guard
   into thinking there's only one Organizer just because the other is off-page.

This directly pins the defect class the `[B-1]` BLOCK was about: a >50-member fixture whose
guard-relevant count silently depends on how many rows happen to be rendered on the current page.

**Runnable locally or CI:** **env-blocked locally**, for the confirmed, pre-existing reason (not a
new defect). Ran it for real against a fresh `gm138-mysql` Docker MySQL 8 container + a fresh
`drush site:install` + `php -S 127.0.0.1:8080 web/.ht.router.php`:

```
InvalidArgumentException: The configuration property settings.allowed_values.0.label.0 doesn't exist.
  at web/core/lib/Drupal/Core/Config/Schema/ArrayElement.php:100
  ... ConfigEntityStorage.php:269 -> EntityStorageBase.php:540 -> ConfigEntityBase.php:643
  -> ManageMembersPaginationTest.php:96 (the FieldStorageConfig::create([...])->save() call in setUp())
```

Identical stack trace, identical trigger line shape (`FieldStorageConfig::create()->save()` on a
`list_string` field), to the 13 tests already confirmed blocked by the pure Drupal-core config-schema
bug documented in the Round-1 GREEN confirmation above. This fails for the **right** reason — an
environment/core config-schema defect independently reproduced with zero `do_group_membership`
code — not a bootstrap error, missing class, or wrong `$modules` list. `phpcs`
(`Drupal,DrupalPractice`) and `phpstan` (level 1) both report **0 findings** on the file, and
`php -l` passes, confirming the test itself is well-formed and will run in a clean CI/DDEV
environment where this core bug does not reproduce (per the existing green `.github/workflows/test.yml`
history for sibling suites).

### Unit re-verify (regression check for W-2's `UserInterface` type change + the pagination refactor)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/Unit
```

**Result: 16/16 real GREEN** (63 assertions, 7 PHPUnit deprecation notices, 0 failures, 0 errors) —
identical to the Phase-6 GREEN result. No regression from `addMember()`'s `AccountInterface` →
`UserInterface` parameter-type change (`[W-2]`) or the `access()` cache-context addition (`[W-1]`):
neither touches the Unit-mocked manager-method contracts the 16 tests pin, and the pagination
refactor touches only `ManageMembersForm` (a Form, not covered at Unit tier — covered by the new
Functional test above and the existing `ManageMembersPageRenderTest`).

### Updated env-blocked test count: 18 (was 17)

| # | Test | Tier | Reason |
|---|---|---|---|
| 1-10 | `GroupMembershipManagerKernelTest` (10 tests) | Kernel | Core `list_string` config-schema bug |
| 11 | `ManageMembersAccessTest::testGroupsModerateUserManagesGroupTheyNeverJoined` | Kernel | Same core bug (via its `setUp()`'s field creation) — Note: this one is otherwise adjudicated GREEN when run in isolation per Round-1 (`⚠` deprecation-only); listed here only if bundled with the full-file `setUp()` cost; see Round-1 section for the isolated PASS |
| 12-14 | `ManageMembersPageRenderTest` (3 tests) | Functional | Same core bug |
| 15-18 | `ManageMembersRouteAccessTest` (4 tests) | Functional | `drupalLogin()` sandbox limitation (independently reproduced on an untouched sibling test) |
| **19 (18th distinct)** | **`ManageMembersPaginationTest::testMemberTablePaginatesAt50RowsAndGuardSeesWholeGroup`** | **Functional** | **Same core `list_string` bug — newly authored this round** |

(Table row count above reads as 19 line items because row 11 double-counts a test already resolved
GREEN in Round-1's isolated run — the actual net-new env-blocked count added this round is **+1**,
bringing the total distinct env-blocked tests from **17 to 18**, per the task's framing.)

### Confirmation nothing regressed

- Unit: 16/16, unchanged from Round-1.
- `phpcs`/`phpstan` on all round-2 changed PHP (`ManageMembersForm.php`, `GroupMembershipManager.php`,
  `AddMemberForm.php`, `ManageMembersController.php`, plus this round's new test file): 0
  errors/warnings, 0 real findings (only the pre-existing standard `new static()` factory pattern).
- `scripts/ci/assemble-config.sh`: ran clean, `copied 95 file(s), excluded 7`, `copied 11 custom
  module(s)`, materialized the new test file correctly into `web/modules/custom/`.
- Module install: not re-run this round (no config/schema changes in F's round-2 diff; F's own
  round-2 verification already confirmed `assemble-config.sh` clean).
- **Spot-check — test still fails if behavior is removed:** by construction, this test's own
  failure history *is* the spot-check: it is the direct regression test for the exact bug
  (`createPager()` called against the wrong, already-sliced count) that the diff-gate BLOCK
  caught. Manually confirmed by reading `ManageMembersForm.php` pre-fix (round-1 commit
  `5ecf7c1`) vs. post-fix (`5b8b08a`): pre-fix, `createPager(count($memberships), 50)` where
  `$memberships` was already limited — a 55-member fixture would have shown `count($memberships)`
  == the already-truncated size, the pager would compute 1 total page, and this test's page-1
  assertion (`assertCount(50, ...)`) would still incidentally pass (the same silent-truncation bug
  the BLOCK flagged) — but the pager-element-present assertion and the page-2 assertion
  (`assertCount(5, ...)` on a `?page=1` request) would fail: with the pager computing only 1 page,
  Drupal's pager renders no pager links/element at all, and `?page=1` would either 404 or replay
  page-1 content, not the distinct 5-row page-2 set. This test is therefore NOT vacuous against the
  specific defect class it targets.

### Docker hygiene (round 2)

Created only `gm138-mysql` (fresh Docker MySQL 8, port 13306) for this round's real-execution
attempt. `docker ps -a` confirmed before creation: no `gm138-*` container existed (prior round's
had already been torn down). Removed only `gm138-mysql` on teardown (`docker rm -f gm138-mysql`).
Confirmed `o119t2-mysql` (a sibling, unrelated container) present both before and after, untouched.
No other container was created, stopped, or removed this round.

---

## Route-collision covering test (Phase 8 rework — U-REWORK response)

**Date:** 2026-07-22
**Trigger:** U's live walkthrough (`handoff-U.md`) found `do_group_membership.manage_members` and
the pre-existing `views.view.group_members` `page_1` display both claiming
`/group/{group}/members`; the router resolved the View for every GET, so the entire new
Manage-members steady-state UI never rendered. Root cause of the miss: **no test asserted route
RESOLUTION** — `ManageMembersRouteAccessTest` only proves 200-vs-403, and the shadowing View
legitimately also returns 200 for a permitted user, so it could not have caught this defect class.

### New test file

`docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php`
(materialized copy confirmed identical at
`web/modules/custom/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php`
via `scripts/ci/assemble-config.sh`).

**Tier:** Functional (`BrowserTestBase`). Route resolution against a real router service AND
rendered-DOM marker presence/absence can only be proven end to end through a real HTTP
request/response cycle — a Kernel test can inspect the route collection but cannot prove which
route a real request actually resolves to through the full router middleware stack, nor what a
browser-facing response actually renders. Mirrors `ManageMembersPageRenderTest`'s/
`ManageMembersRouteAccessTest`'s fixture setup (`createGroupType`/`createGroupRole` via
`GroupTestTrait`) for consistency with the sibling Functional tests in this module.

**Three test methods, each pinning a distinct facet of the defect class:**

1. **`testRouterResolvesToModuleRoute`** — the most direct, framework-level assertion. Calls
   `router.route_provider` to confirm the module route is even a candidate for the path (sanity
   check), then calls `router.no_access_checks`'s `matchRequest()` on a real `Request` object for
   `/group/{group}/members` and asserts the resolved `_route` is
   `do_group_membership.manage_members`, not `view.group_members.page_1`. This mirrors U's own
   diagnostic technique exactly (`handoff-U.md`'s "Reproducible" section), turned into an automated
   assertion.
2. **`testPageServesNewFormNotOldView`** — asserts the rendered HTML. POSITIVE: a real `<form>`
   wrapping `table.do-group-membership__table` (the module's own CSS class — the View can never
   emit it) and the "+ Add member" primary-action link. NEGATIVE: the OLD View's own markers are
   ABSENT — no `views-view-table` string in the page content, no `.view-group-members` /
   `.views-element-container` wrapper element, and no "View member" dropdown link text (the View's
   `entity_link` field on each row). Both directions matter: a page could coincidentally contain
   the new markup while the View ALSO still rendered underneath/alongside in some other collision
   shape, so absence-of-old is checked independently of presence-of-new.
3. **`testLocalTaskNavigatesToNewRoute`** — mirrors U's own navigated-path check (as opposed to a
   direct GET), since that is how the defect actually manifested live: clicking the "Manage
   members" tab from the group canonical page. Asserts the tab's href, then actually follows it
   (`clickLink`) and re-asserts the new-form marker on the destination page.

### RED confirmation — the test is REAL-EXECUTED and currently RED, for the right reason

Ran for real against a fresh `gm138-mysql` Docker MySQL 8 container, a real `drush site:install`
+ `config:import` (using this project's own `config/sync/views.view.group_members.yml`, which
already has `page_1` removed by F's concurrent, uncommitted config edit) + `php -S
127.0.0.1:8080 web/.ht.router.php`:

```
export SIMPLETEST_DB='mysql://root:root@127.0.0.1:13306/drupal'
export SIMPLETEST_BASE_URL='http://127.0.0.1:8080'
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php
```

**Result: 3/3 FAIL, for the SAME root cause the test targets** — this is a valid RED, not a
bootstrap/authorship error:

```
✘ Router resolves to module route
  Failed asserting that two strings are identical.
  -'do_group_membership.manage_members'
  +'view.group_members.page_1'

✘ Page serves new form not old view
  ElementNotFoundException: Element matching css "form table.do-group-membership__table" not found.

✘ Local task navigates to new route
  Current response status code is 403, but 200 expected.
```

**Root-cause diagnosis (important, distinct from F's fix so far):** `BrowserTestBase`'s `setUp()`
enables `group` + `views` (both required by this test's own `$modules`), which triggers Drupal's
`ConfigInstaller` to install the `group` **contrib** module's own **optional config**
(`web/modules/contrib/group/config/optional/views.view.group_members.yml`) fresh into the test
database — confirmed directly:

```
$ grep -n page_1 web/modules/contrib/group/config/optional/views.view.group_members.yml
708:  page_1:
709:    id: page_1
715:      path: group/%group/members
```

This file **still contains the `page_1` display** and is entirely independent of this project's
own `docs/groups/config/views.view.group_members.yml` / `config/sync/views.view.group_members.yml`
(which F has already edited in the working tree to remove `page_1` — confirmed via `git diff`,
0 remaining `page_1:` matches in either file). F's fix addresses the *site's own shipped config*
(what `config:import` installs on a real deploy) but does **not** address the *contrib module's
own optional config* that `BrowserTestBase`/a fresh `drush en group` re-materializes — these are
two independent sources of the identical collision. **This is a real, additional finding, not a
test-authorship defect**: the RED is failing for exactly the reason the test asserts (the route
still resolves to the View), just via a second collision source F has not yet closed. Flagging
this explicitly for F/O — the fix likely needs either (a) this project's own module to
programmatically prevent/override the contrib-shipped optional config on install (e.g. via
`hook_install()` deleting the `page_1` display, or a `config/install`/`config/optional` override
in `do_group_membership` itself), or (b) accept that the collision is closed for the real deployed
site (config-imported) but remains open specifically in the isolated PHPUnit
bootstrap-from-`$modules` path — which the pipeline itself should not accept silently, since it
means the same collision at the same path in the same site would happen in ANY fresh Group 4.x
site install (e.g. `groupsdrupalorg`'s own reference-checkout convention, or any new demo/CI
build) that never runs this project's specific `config:import` before enabling `views`.

**phpcs / phpstan / php -l:** all clean (0 findings) on the new test file, confirming it is
well-formed — namespace `Drupal\Tests\do_group_membership\Functional`, extends `BrowserTestBase`,
uses `GroupTestTrait`, `#[Group('do_group_membership')]`-equivalent `@group` annotations, correct
method signatures — the RED is a genuine behavioral failure, not a class-not-found/bootstrap error.

**Runnable locally:** YES — unlike the other 18 CI-pinned tests (blocked by the unrelated core
`list_string` config-schema bug), this test hit NO environment blocker; it ran to completion and
failed on its actual assertions. It is **not** added to the env-blocked list. It will re-run
locally (not just in CI) once the root-cause fix above lands, and I will re-verify it for real,
locally, before the next GREEN sign-off — no CI dependency needed for this one.

### Updated env-blocked test count: still 18 (unchanged)

The new `ManageMembersRouteResolutionTest` (3 test methods) is **not** env-blocked — it is a valid,
real-executed RED against a real defect, distinct from the 18 CI-pinned tests blocked by the core
`list_string`/`drupalLogin` sandbox limitations documented in the Round-1/Round-2 sections above.
It does not become "the 19th CI-pinned test" as originally anticipated in the task framing — it is
instead a genuine, locally-runnable RED that routes back to F with a MORE PRECISE root-cause
finding than U's original report (the contrib-shipped optional config, not just this project's own
config export).

### Collateral check — no other existing test needed changing

Grepped all three pre-existing Functional test files
(`ManageMembersPageRenderTest.php`, `ManageMembersRouteAccessTest.php`,
`ManageMembersPaginationTest.php`) for any dependency on the View's own markup, `clickLink()`, or
local-task navigation (`views-view`, `view-group-members`, `linkExists`, `linkByHref`, `clickLink`):
**zero matches in any of the three files.** All three only ever call `drupalGet()` directly on the
route path and assert HTTP status codes / the module's own DOM markers — none of them render or
assert anything about the View, so F's `page_1`-display removal (in either direction, present or
absent) cannot regress any assertion in those three files. No collateral test changes were needed
or made.

### Docker hygiene (this round)

Created only `gm138-mysql` (Docker MySQL 8, port 13306) for this round's real-execution attempt,
twice (once for the initial RED confirmation, torn down, then re-verified the "clean before/after"
state). `docker ps -a` confirmed before each creation: no `gm138-*` container existed. Removed only
`gm138-mysql` on teardown both times (`docker rm -f gm138-mysql`). Confirmed all 40 pre-existing
containers (24 `ddev-*` siblings plus 16 other unrelated project containers, including
`ddev-pl-groups-on-d11-*`) present and unchanged before and after this round — none created,
stopped, or removed. The local `php -S 127.0.0.1:8080` test webserver process was started and
killed via its captured PID only.

## Route-resolution GREEN verify (Phase 8 REWORK round 3, after F's hook_install v2)

**Date:** 2026-07-22

### Environment stood up for real execution

Fresh Docker MySQL 8 container `gm138-mysql` (port 33061), a real `drush site:install` against it,
`web/sites/default/settings.php` (gitignored build output) pointed at that DB, and a `php -S
127.0.0.1:8138` webserver serving `web/` — the same shape prior T/F rounds used, standing up
`SIMPLETEST_BASE_URL`/`SIMPLETEST_DB`/`BROWSERTEST_OUTPUT_DIRECTORY` for a real `vendor/bin/phpunit
-c web/core` run (not `ddev phpunit` — this worktree's own composer/vendor tree from earlier
rounds, no working DDEV project bound to this worktree). `scripts/ci/assemble-config.sh` was run
first and reported clean (`copied 95 file(s)`, `copied 11 custom module(s)`, `.install` file present
byte-identical in the materialized `web/modules/custom/do_group_membership/` copy).

### 1. `ManageMembersRouteResolutionTest` — 3/3 methods: STILL RED (not GREEN). Real root cause found.

Ran for real (no static validation, no environment blocker — the test executes to completion):

```
SIMPLETEST_BASE_URL="http://127.0.0.1:8138" SIMPLETEST_DB="mysql://root:root@127.0.0.1:33061/drupal" \
BROWSERTEST_OUTPUT_DIRECTORY="/tmp/gm138-browsertest-output" vendor/bin/phpunit -c web/core \
  docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php --testdox
```

```
FFF   3 / 3 (100%)

✘ Router resolves to module route
  Failed asserting that two strings are identical.
  --- Expected: 'do_group_membership.manage_members'
  +++ Actual:   'view.group_members.page_1'
  at ManageMembersRouteResolutionTest.php:120

✘ Page serves new form not old view
  Behat\Mink\Exception\ElementNotFoundException: Element matching css
  "form table.do-group-membership__table" not found.
  at ManageMembersRouteResolutionTest.php:148

✘ Local task navigates to new route
  Behat\Mink\Exception\ExpectationException: Current response status code is 403, but 200 expected.
  at ManageMembersRouteResolutionTest.php:179

Tests: 3, Assertions: 12, Failures: 3.
```

**This is NOT env-blocked** — same conclusion as the prior round: the test runs to completion and
fails on its actual assertions. `views.view.group_members.page_1` still wins the router match in a
real BrowserTestBase-style fresh bootstrap.

### 2. Root cause isolated precisely (not "the fix doesn't work" — "the fix works but too late")

Independently reproduced the EXACT collision, byte-for-byte, via a raw `module_installer->install()`
call matching `BrowserTestBase::installModulesFromClassProperty()`'s own single-batch invocation
(`$container->get('module_installer')->install($modules, TRUE)`), with zero `drush cr` in between —
the same conditions PHPUnit's bootstrap creates:

```php
$mi = \Drupal::service('module_installer');
$mi->install(['group', 'do_group_membership', 'field', 'options', 'views'], TRUE);
$view = \Drupal::entityTypeManager()->getStorage('view')->load('group_members');
echo implode(',', array_keys($view->get('display')));   // => "default"  (page_1 correctly stripped!)
$matched = \Drupal::service('router.no_access_checks')->matchRequest(Request::create('/group/1/members'));
echo $matched['_route'];   // => "view.group_members.page_1"  (but the ROUTER still resolves the OLD route)
```

Then, with NO other change, an explicit extra `\Drupal::service('router.builder')->rebuild()` call
immediately fixes it:

```php
\Drupal::service('router.builder')->rebuild();
$matched = \Drupal::service('router.no_access_checks')->matchRequest($req);
echo $matched['_route'];   // => "do_group_membership.manage_members"  (correct, once rebuilt)
```

**Root cause, traced through `web/core/lib/Drupal/Core/Extension/ModuleInstaller.php`'s actual
`install()`/`doInstall()` code (read directly, not inferred):**

1. `doInstall($modules, ...)` runs `hook_install()` for every module in the batch (including
   `do_group_membership_install()` — line ~445 loop) **BEFORE** any *optional* config is installed
   (the `Optional`/`SiteOptional` `installDefaultConfig()` loops run afterward, lines ~451-461). So
   when `do_group_membership_install()` fires, `group`'s own optional
   `views.view.group_members.yml` (carrying `page_1`) either doesn't exist yet or hasn't been
   (re)materialized — the strip finds nothing useful to do at this point in a fresh single-batch
   install.
2. Still inside `install()` (not `doInstall()`), **immediately after** the `doInstall()` loop
   finishes for all module groups: `\Drupal::service('router.builder')->rebuild()` (or
   `rebuildIfNeeded()`) runs — line 245. At THIS point `page_1` DOES exist (optional config was
   installed inside `doInstall()`), so the router table is built WITH the collision.
3. **Only after that** does `$this->moduleHandler->invokeAll('modules_installed', [$module_list,
   $sync_status])` fire (line 257) — this is where `do_group_membership_modules_installed()`
   correctly, finally, strips `page_1` from the view's config entity. But the router was already
   rebuilt in step 2, using the pre-strip state, and **nothing rebuilds it again afterward**.

Net effect: the view's config entity ends up correct (`display: {default}` only, confirmed via
direct load), but the **router route table is permanently stale** for the rest of the
request/test/session — exactly the symptom the 3 failing assertions caught. A subsequent, unrelated
cache-clear (e.g. `drush cr`, or any other code path that happens to trigger
`router.builder->rebuild()`) would silently "fix" it, which is presumably why F's own `drush
php:eval` verification (which calls `router.route_provider->getRoutesByPattern()` and
`router.no_access_checks->matchRequest()` in a FRESH `drush` process — itself always doing a full
container/cache bootstrap on each invocation) did not catch this: a fresh `drush` process's router
service is lazily built fresh from current config, not a same-request stale cache. **The defect only
manifests within a single long-running request/process where `install()` runs and something is
served without an intervening full rebuild — exactly BrowserTestBase's and, more importantly, a real
end user's live HTTP request during the same PHP-FPM/OPcache-warm request that first triggers module
install.**

### Verdict: route back to F (not a test-authorship issue)

`hook_modules_installed()` must call `\Drupal::service('router.builder')->rebuild()` (or at minimum
`setRebuildNeeded()`) after stripping the display, so the router table reflects the corrected view
config. This is a one-line-class fix inside `_do_group_membership_strip_group_members_page_display()`
— only rebuild when a strip actually happened (skip on the common no-op path where `page_1` was
already absent, to avoid an unconditional extra rebuild on every module-install batch).

### Second, independent defect found (regression, blocks module install without `views`)

Re-ran the 3 previously-authored Functional test files that do NOT have F's fix's target scenario as
their focus (`ManageMembersRouteAccessTest`, `ManageMembersPageRenderTest`,
`ManageMembersPaginationTest`) — regression sweep, not the route-resolution test itself:

```
SIMPLETEST_BASE_URL=... SIMPLETEST_DB=... vendor/bin/phpunit -c web/core \
  .../ManageMembersRouteAccessTest.php .../ManageMembersPageRenderTest.php .../ManageMembersPaginationTest.php
```

**All 8 methods across the 3 files ERROR (not fail) identically:**

```
Drupal\Component\Plugin\Exception\PluginNotFoundException: The "view" entity type does not exist.
  at .../do_group_membership.install:88   (_do_group_membership_strip_group_members_page_display)
  at .../do_group_membership.install:32   (do_group_membership_install)
  at ModuleInstaller.php:815 / 451 / 229 / FunctionalTestSetupTrait.php:516
```

**Root cause:** none of these 3 test files' `$modules` arrays include `views`
(`ManageMembersRouteAccessTest`: `['group', 'do_group_membership']`;
`ManageMembersPageRenderTest`/`ManageMembersPaginationTest`: `['group', 'do_group_membership',
'field', 'options']`). `do_group_membership.info.yml` does NOT declare `views` as a dependency
either. `_do_group_membership_strip_group_members_page_display()` calls
`\Drupal::entityTypeManager()->getStorage('view')` **unconditionally**, with no
`\Drupal::moduleHandler()->moduleExists('views')` (or `hasDefinition('view')`) guard first. On any
site/test that installs `do_group_membership` without `views` already enabled, `hook_install()`
itself now hard-fatals with `PluginNotFoundException` — **the fix intended to resolve a route
collision instead breaks installability of the module on any non-`views` site**, which is strictly
worse than the bug it fixes (the old code had no such crash). This is a genuine regression introduced
by the `.install` file, confirmed by real execution, not env-blocked and not a test-authorship
artifact — these 3 test files' `$modules` lists were correct and unchanged before this round; F's new
`.install` code is what broke them.

**Verdict: route back to F.** Add a guard —
`if (!\Drupal::moduleHandler()->moduleExists('views')) { return; }` (or equivalent
`hasDefinition('view')` check) — at the top of `_do_group_membership_strip_group_members_page_display()`,
before calling `entityTypeManager()->getStorage('view')`. This is safe: if `views` isn't installed,
`views.view.group_members` cannot exist as a routable collision in the first place, so skipping is
correct, not just crash-avoidance.

### 3. Regression sweep

- **Unit tier: 16/16 GREEN**, real execution, no regression from the `.install` file (Unit tests
  don't install modules). `vendor/bin/phpunit -c web/core docs/groups/modules/do_group_membership/tests/src/Unit --testdox`
  → `OK, but there were issues!  Tests: 16, Assertions: 63, PHPUnit Deprecations: 7.` (pre-existing
  deprecation noise, unrelated to this change).
- **`ManageMembersRouteAccessTest`/`ManageMembersPageRenderTest`/`ManageMembersPaginationTest`:**
  8/8 methods ERROR — see "second, independent defect" above. This is a NEW regression (these files
  were previously env-blocked on the core `list_string` schema bug and the `drupalLogin()` sandbox
  limitation, per the Phase-6 decisions; they now fail earlier, at `hook_install()`, for a new and
  different, code-caused reason). Flagging clearly: this is not the same env-blocked class as
  before — it is a real, locally-reproduced fatal caused by this round's `.install` file.
- **`ManageMembersRouteResolutionTest`:** 3/3 still RED, root cause isolated above (router-rebuild
  ordering), distinct from the `views`-dependency crash.

### 4. #109 lesson check — source-relative fixture paths

Grepped `docs/groups/modules/do_group_membership/tests/` for any fixture/config read:

```
grep -rn "file_get_contents\|fopen\|Yaml::parseFile\|yaml_parse_file" docs/groups/modules/do_group_membership/tests/
```

Found 4 call sites, all in `GroupRoleConfigShapeTest.php` and `MembershipStatusFieldConfigShapeTest.php`
(Unit tier), reading `docs/groups/config/*.yml`. **Not a #109 violation.** Both files use a
`locate()`/`loadConfig()` helper that walks UP from `__DIR__` (the test file's own on-disk location)
searching for `docs/groups/config/<file>.yml`, up to 10 ascents — NOT a hardcoded
source-checkout-relative or repo-root-relative literal path. This is the exact, already-in-production
precedent from `do_tests/tests/src/Functional/GroupAddFormFieldsTest.php::locateFormDisplayYaml()`
(confirmed by direct read: identical `__DIR__`-ascend-and-`is_file()` logic, with its own doc comment
explicitly citing the reason — "the module is authored under `docs/groups/modules/do_tests` but the
runbook copies `do_*` into `web/modules/custom/`... walk up from here until the canonical YAML is
found," because the whole repo (both `docs/groups/config/` AND either module location) is mounted
into the test container in both source and CI-assembled layouts). Confirmed `docs/groups/config/` is
a real, always-present SOURCE directory (not a build-generated-only artifact under `.gitignore`), so
this ascend-and-locate pattern resolves correctly regardless of which of the two supported module
layouts (`docs/groups/modules/...` or `web/modules/custom/...`) the test happens to run from. No
fix needed; no violation found.

### Docker hygiene (this round)

Baseline `docker ps -a` before starting: 33 pre-existing containers (`ddev-*` × 22, plus 11 other
unrelated project containers — `o119t4-mysql` from a concurrent sibling task was present at the very
start of this session but had already been torn down by its own owning session before I created
anything; I never touched it). Created only `gm138-mysql` (Docker MySQL 8, port 33061) this round.
Removed only `gm138-mysql` on teardown (`docker rm -f gm138-mysql`). Post-teardown `docker ps -a`
name list is byte-identical to the pre-run baseline (all 33 containers, `o119t4-mysql` absent in
both, as expected since I never created or removed it). The local `php -S 127.0.0.1:8138` webserver
was started and killed via `pkill -f "php -S 127.0.0.1:8138"` (only matches the process this round
started).

### Verdict

**NOT GREEN — 3/3 route-resolution tests still RED**, plus a NEW regression (8/8 methods across 3
other Functional test files now ERROR on install, not just still-env-blocked). Both are real,
locally-reproduced, root-caused defects in `do_group_membership.install`, not test-authorship issues
and not environment artifacts:

1. **[BLOCK] Router never rebuilt after the retroactive strip.** `do_group_membership_modules_installed()`'s
   strip runs correctly (config entity ends up right) but too late relative to
   `ModuleInstaller::install()`'s own router rebuild, and nothing rebuilds the router again
   afterward — fix: call `router.builder->rebuild()` (or `setRebuildNeeded()`) inside the helper,
   only on the branch where a strip actually happened.
2. **[BLOCK, regression] Unconditional `getStorage('view')` crashes on any non-`views` site.**
   `_do_group_membership_strip_group_members_page_display()` needs a
   `\Drupal::moduleHandler()->moduleExists('views')` guard before touching the `view` entity
   storage — currently a hard `PluginNotFoundException` on `hook_install()` for
   `ManageMembersRouteAccessTest`/`ManageMembersPageRenderTest`/`ManageMembersPaginationTest` (none
   of which enable `views`), which is worse than the collision being fixed.

Route back to F for both. T then re-runs `ManageMembersRouteResolutionTest` (must go 3/3 RED→GREEN)
AND the 3 previously-authored Functional files (must return to their prior env-blocked-only state,
not a new install-time crash) before U re-walks.
