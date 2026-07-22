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

## Route-collision v3 verify (independent re-verification, 2026-07-22)

F's v3 fix (`docs/handoffs/0138-mc7-manage-members/handoff-F.md`, "Route-collision fix v3 (router
rebuild + views guard)") addresses the two BLOCKs from the previous T round: (1) the router staying
stale within a single install request (fix: `router.builder->rebuild()` inside the strip helper,
only when a strip occurred), (2) `getStorage('view')` crashing on views-less sites (fix: an early
`moduleHandler()->moduleExists('views')` guard). F verified via a one-off in-request PHP script
(`module_installer->install(...)` in a single bootstrap). This round independently re-confirms via a
REAL PHPUnit run of the actual authored suite (`BrowserTestBase`/`KernelTestBase`, not a bespoke
script), which is the strongest form of confirmation since it's the exact harness CI will use.

### Environment stood up this round

- `vendor/` in this worktree was thin (missing `web/core`'s lib tree resolution artifacts and
  contrib packages) at session start — PHP 8.3 (`brew`-linked) can't satisfy `doctrine/instantiator
  2.1.0`'s `^8.4` constraint from `composer.lock`. Ran `composer install` with the unlinked **PHP
  8.5.6** binary at `/opt/homebrew/opt/php@8.5/bin/php` (matching F's own PHP version, confirmed in
  `handoff-F.md`) — succeeded cleanly, full `web/core` + `web/modules/contrib/group` materialized.
- Own Docker MySQL 8 container **`gm138t-mysql`** (port 33091), created and removed only by me this
  round. `docker ps -a` before this round showed zero `gm138-*` containers (prior rounds' had already
  been torn down); the pre-existing sibling `o119u1-mysql` (a different, concurrently-running task's
  container) was present before I started and confirmed **untouched** — `docker ps -a` diffed
  before/after teardown shows only `gm138t-mysql` added then removed, every other container
  byte-identical.
- A local PHP 8.5 built-in webserver (`php -S 127.0.0.1:8993 .ht.router.php` from `web/`) for
  `BrowserTestBase`'s `SIMPLETEST_BASE_URL`, killed at teardown.
- `SIMPLETEST_DB=mysql://root@127.0.0.1:33091/drupal`, `SIMPLETEST_BASE_URL=http://127.0.0.1:8993`,
  `BROWSERTEST_OUTPUT_DIRECTORY` pointed at the session scratchpad.
- `web/sites/simpletest/` (gitignored/untracked BrowserTestBase scaffolding) removed at teardown;
  confirmed untracked (`git ls-files` empty) before removal, so no git-visible impact.

### 1. `ManageMembersRouteResolutionTest` — 3/3 GREEN (real PHPUnit execution)

First run (unmodified test file) surfaced **2 genuine T-authorship bugs in the test's own `setUp()`**
— NOT defects in F's v3 fix — that must be fixed before the suite can prove anything:

- `testLocalTaskNavigatesToNewRoute` initially failed with `Current response status code is 403,
  but 200 expected` on `drupalGet($this->group->toUrl())` itself (before even reaching the tab
  click). Root cause: this test's `setUp()` grants the `community_group-organizer` role only
  `['administer members']` — it never granted `'view group'`, so the Organizer fixture couldn't even
  view the group canonical page. The real, shipped `docs/groups/config/group.role.community_group-
  organizer.yml` carries a much richer permission set (`edit group` + 20 content permissions);
  the test's minimal fixture role was simply missing a permission the production role always has.
  **Fix:** added `'view group'` to the fixture role's `permissions` array (confirmed `'view group'`
  is a real Group-4.x-generated permission string via `GroupAccessControlHandler::checkAccess()`'s
  `case 'view'` branch — not invented).
- After that fix, the same test failed differently: `No link containing href /group/1/members found`
  on the group canonical page. Root cause: this test declares `protected $defaultTheme = 'stark'`,
  and `stark` ships no `page.html.twig`/default block layout — a fresh `BrowserTestBase` install
  places **zero blocks**, so no local-tasks block (and therefore no "Manage members" tab link) was
  ever rendered on the page, regardless of the route/menu-link registration being entirely correct.
  This is page-chrome test setup, not a module defect. **Fix:** added `block` to `$modules`, used
  `BlockCreationTrait::placeBlock('local_tasks_block')` in `setUp()` (standard Drupal-core testing
  convention; no sibling test in this suite had needed a block before because none of them assert
  against a *navigated* tab link, only direct GETs).

Both fixes are test-only (T's own authorship, not production code, not a route-back to F — matches
this role's mandate: "if a test is wrong, T fixes the test").

**Real GREEN, this run:**
```
$ SIMPLETEST_DB=mysql://root@127.0.0.1:33091/drupal SIMPLETEST_BASE_URL=http://127.0.0.1:8993 \
  vendor/bin/phpunit -c web/core \
  docs/groups/modules/do_group_membership/tests/src/Functional/ManageMembersRouteResolutionTest.php
DDD                                                                 3 / 3 (100%)
Time: 00:31.066, Memory: 16.00 MB
OK, but there were issues!
Tests: 3, Assertions: 22, Deprecations: 3, PHPUnit Deprecations: 4.
```
(`D` = deprecation-only, not a failure marker; PHPUnit's dot-report shows no `F`/`E`. All 3 methods
— `testRouterResolvesToModuleRoute`, `testPageServesNewFormNotOldView`,
`testLocalTaskNavigatesToNewRoute` — pass.) The 3 deprecation notices are pre-existing Drupal-core/
Twig deprecations unrelated to this module (confirmed identical wording/location to deprecations
seen in every other test file run this round).

This directly confirms both v3 fixes work for real, in the same harness CI uses:
- `testRouterResolvesToModuleRoute` passing proves the router is NOT stale within the single install
  request `BrowserTestBase` performs — FIX 1 (the `router.builder->rebuild()` call) closes the gap.
- All 3 methods running to completion with `views` enabled (this test's `$modules` includes `views`)
  and no `PluginNotFoundException` anywhere confirms the views-guard doesn't regress the
  views-enabled path either (FIX 2 is additive/guarded, not a behavior change when views IS present).

### 2. The 3 previously-erroring pre-existing Functional/Kernel test files — regression closed

Per the mandate, ran the exact files whose 8 methods F's v2 regression made ERROR with
`PluginNotFoundException` (none of these declare `views` in `$modules`):

```
$ vendor/bin/phpunit -c web/core .../Functional/ManageMembersRouteAccessTest.php
Tests: 4, Assertions: 18, Deprecations: 2, PHPUnit Deprecations: 5.   -- OK, 4/4 GREEN (real)

$ vendor/bin/phpunit -c web/core .../Functional/ManageMembersPageRenderTest.php
Tests: 3, Assertions: 0, Errors: 3, Deprecations: 2, PHPUnit Deprecations: 4.  -- ERROR, 0/3

$ vendor/bin/phpunit -c web/core .../Functional/ManageMembersPaginationTest.php
Tests: 1, Assertions: 0, Errors: 1, Deprecations: 2, PHPUnit Deprecations: 2.  -- ERROR, 0/1

$ vendor/bin/phpunit -c web/core .../Kernel/GroupMembershipManagerKernelTest.php
Tests: 10, Assertions: 0, Errors: 10, Deprecations: 1, PHPUnit Deprecations: 11. -- ERROR, 0/10

$ vendor/bin/phpunit -c web/core .../Kernel/ManageMembersAccessTest.php
Tests: 4, Assertions: 98, Deprecations: 2, PHPUnit Deprecations: 5.  -- OK, 4/4 GREEN (real)
```

**Regression check (the specific thing that must be proven):** grepped every ERROR's stack trace —
`grep -i "PluginNotFound"` across all 5 runs returns **zero matches**. Every ERROR is the identical,
already-diagnosed, pre-existing, code-independent core bug: `InvalidArgumentException: The
configuration property settings.allowed_values.0.label.0 doesn't exist.` at
`FieldStorageConfig::create([...'list_string'...])->save()` — same exception class, same message,
same call site shape documented and root-caused in Phase 6 (reproduced there with a throwaway
zero-`do_group_membership`-code scratch test on both `group_relationship` and `user` entity types,
PHP 8.3 and PHP 8.5, Drupal 11.4.4). **FIX 2 (the `views`-existence guard) closes the regression: no
test in this run crashed with the v2-introduced `PluginNotFoundException` on `view` entity-type
storage.** The 14 methods across these 3 files (`ManageMembersPageRenderTest` ×3,
`ManageMembersPaginationTest` ×1, `GroupMembershipManagerKernelTest` ×10) are back to their prior
GREEN-except-for-the-pre-existing-core-bug status, exactly as expected — not a new install-time
crash class.

**New, favorable finding beyond the mandate:** `ManageMembersRouteAccessTest` (4 methods) and
`ManageMembersAccessTest` (Kernel, 4 methods) are BOTH **real GREEN** in this environment — this
contradicts the prior round's characterization of `ManageMembersRouteAccessTest` as blocked by a
"pre-existing `drupalLogin()`/`BrowserTestBase` cookie-session sandbox limitation." That limitation
does not reproduce in this session's environment (PHP 8.5.6 + a fresh Docker MySQL 8 + a fresh
built-in PHP webserver) — most likely an artifact specific to the prior sandbox's networking/cookie
handling, not a defect of any kind. Flagging as a genuine, favorable tally correction, not silently
absorbed: **the "18 env-blocked" figure from earlier rounds is stale.** See the corrected tally
below.

### 3. Install-hook-fires + router-rebuild confirmation (in-request, real harness)

`ManageMembersRouteResolutionTest::testRouterResolvesToModuleRoute()` IS the install-hook-fires
confirmation, run for real in `BrowserTestBase`'s own install lifecycle (not a bespoke script): the
test's `$modules` array includes `do_group_membership`, so `BrowserTestBase::setUp()`'s module
installer runs `hook_install()`/`hook_modules_installed()` for real as part of bootstrapping the
test site, in the exact same single request the assertions execute in. The test asserts, in that
same request, that `router.route_provider->getRoutesByPattern()` returns ONLY the module's route as
a candidate and `router.no_access_checks->matchRequest()` resolves to it — this is only possible if
(a) the `page_1` strip actually ran during install (removing the View's route as a candidate) AND
(b) the router was rebuilt within that same request afterward. Passing proves both empirically, in
the real BrowserTestBase bootstrap path — the identical lifecycle CI's Functional tier uses.

### 4. Unit tier — 16/16 GREEN (regression sweep)

```
$ vendor/bin/phpunit -c web/core docs/groups/modules/do_group_membership/tests/src/Unit/
.DDD.D..........                                                  16 / 16 (100%)
Tests: 16, Assertions: 63, PHPUnit Deprecations: 7.
```
No failures/errors — confirms the `.install`-only change (no `src/` PHP touched this round) has zero
effect on the Unit-mocked manager/config-shape tests, as expected.

### 5. #109 lesson recheck (source-relative fixture reads) — still holds

```
grep -rn "__DIR__" docs/groups/modules/do_group_membership/tests/src/Unit/*.php
```
Both `GroupRoleConfigShapeTest.php` and `MembershipStatusFieldConfigShapeTest.php` still use the
walk-up-from-`__DIR__`-searching-for-`docs/groups/config/*.yml` pattern (matching `do_tests`'
`GroupAddFormFieldsTest::locateFormDisplayYaml()` precedent), not a hardcoded source-relative or
checkout-relative literal path. No new fixture-reading code was added this round (the only test file
touched, `ManageMembersRouteResolutionTest.php`, reads no fixtures at all — it's Functional-tier,
driving real HTTP against a real installed site). Confirmed still compliant; no fix needed.

### Corrected final test tally (real execution, this round)

| Tier | File | Methods | Result |
|---|---|---|---|
| Unit | `GroupMembershipManagerTest` + `GroupRoleConfigShapeTest` + `MembershipStatusFieldConfigShapeTest` | 16 | **16/16 GREEN** (real) |
| Kernel | `ManageMembersAccessTest` | 4 | **4/4 GREEN** (real — not env-blocked this round) |
| Kernel | `GroupMembershipManagerKernelTest` | 10 | 0/10 — env-blocked (core `list_string` schema bug) |
| Functional | `ManageMembersRouteResolutionTest` | 3 | **3/3 GREEN** (real, NOT env-blocked by design) |
| Functional | `ManageMembersRouteAccessTest` | 4 | **4/4 GREEN** (real — not env-blocked this round) |
| Functional | `ManageMembersPageRenderTest` | 3 | 0/3 — env-blocked (same core bug) |
| Functional | `ManageMembersPaginationTest` | 1 | 0/1 — env-blocked (same core bug) |

**Env-blocked count is now 14** (all `GroupMembershipManagerKernelTest`'s 10 + `ManageMembersPage
RenderTest`'s 3 + `ManageMembersPaginationTest`'s 1 — all one root cause, the pre-existing Drupal
11.4.4 `list_string` config-schema bug), **down from the previously-reported 18**. The 4-method drop
is `ManageMembersRouteAccessTest`, previously counted as env-blocked via a `drupalLogin()` sandbox
limitation that did not reproduce in this session's environment — a real, favorable correction, not
a defect fix. All 14 remaining env-blocked methods are CI-pinned (expected to pass in a clean
CI/DDEV environment where the core bug's specific package-resolution quirk doesn't reproduce,
consistent with this repo's green `.github/workflows/test.yml` history for sibling Functional
suites) — this is an assumption carried forward unchanged from Phase 6, not re-verified in a second
environment this round.

**Real-executed, real-GREEN total this round (these 7 files): 27/27 GREEN** (16 Unit + 4
`ManageMembersAccessTest` + 3 `ManageMembersRouteResolutionTest` + 4 `ManageMembersRouteAccessTest`),
plus **14 env-blocked/CI-pinned** (`GroupMembershipManagerKernelTest` ×10 + `ManageMembersPageRenderTest`
×3 + `ManageMembersPaginationTest` ×1) = **41 total PHPUnit methods across these 7 files**, all
accounted for as either real-GREEN or CI-pinned-for-the-known-core-bug — none unexplained, none newly
broken.

### Docker hygiene (this round)

`docker ps -a` before this round: no `gm138-*` container of any kind existed (prior rounds' were
already torn down); `o119u1-mysql` (a different, concurrently-running task's container) was present
and is confirmed **untouched** — before/after container name-set diff shows only `gm138t-mysql`
added then removed, everything else byte-identical. Local PHP webserver on port 8993 killed at
teardown. `web/sites/simpletest/` (untracked scaffolding) removed at teardown.

### Verdict

**GREEN.** Both v3 fixes (router rebuild + views guard) independently confirmed via real PHPUnit
execution in the actual BrowserTestBase/KernelTestBase harness:
- Route-resolution suite: **3/3 GREEN** (was 3/3 RED before F's original REWORK fix; confirmed not
  env-blocked, runs for real).
- Views-less-site regression: **closed** — zero `PluginNotFoundException` across all 5 re-run files;
  every remaining failure is the identical pre-existing core bug, not a new crash class.
- Install-hook-fires + same-request router-rebuild: confirmed empirically via the real
  `BrowserTestBase` bootstrap lifecycle, not just F's bespoke script.
- Unit: 16/16 GREEN, no regression.
- #109 lesson: still holds, no violation.
- 2 T-authorship bugs found and fixed in `ManageMembersRouteResolutionTest.php`'s own `setUp()`
  (missing `'view group'` permission on the fixture role; missing `local_tasks_block` placement for
  the `stark` theme) — neither traces to F's code, both are test-only fixes per this role's mandate.

**No route-back to F.** Ready for A (anti-duplication re-check on the test-only diff, if warranted)
then back into the U (UI Walkthrough) re-walk of the now-unblocked steady-state Manage-members
surface, per the original Phase 8 REWORK sequencing.

## list_string CI-red fix (pre-S CI-red risk, O's Phase 8.5 diagnosis) — T

O diagnosed (Phase 8.5, `decisions.md`) that ~13-14 Kernel/Functional tests ERROR at `setUp()` with
`InvalidArgumentException: The configuration property settings.allowed_values.0.label.0 doesn't
exist`, and that `composer.lock` pins core to the exact 11.4.4 build that reproduces this, so CI's
Kernel job would go RED on merge — not just "env-blocked locally." O's proposed fix was
`protected $strictConfigSchema = FALSE;` on the 3 affected test classes. I implemented that fix
first, then **independently re-diagnosed the root cause during real-execution verification and found
it is NOT a core/schema bug at all** — it is a T-authorship bug in these same 3 test files' own
`FieldStorageConfig::create()` calls. Reporting both steps for the record, since the initial fix
attempt (as specified) does not actually resolve the error, and the real fix is different from what
was prescribed.

### Step 1 — applied `strictConfigSchema = FALSE` as instructed; it did NOT fix the error

Set `protected $strictConfigSchema = FALSE;` (untyped, to match the untyped parent property
declaration in `KernelTestBase`/`BrowserTestBase` on this core version — a `bool`-typed override
throws a PHP `Fatal error: Type ... must be omitted to match the parent definition`) on
`GroupMembershipManagerKernelTest`, `ManageMembersPageRenderTest`, `ManageMembersPaginationTest` (the
3 classes confirmed — by grep for `field_membership_status`/`FieldStorageConfig::create` across every
Kernel/Functional file — to install the field). Re-ran `GroupMembershipManagerKernelTest` for real
against a fresh `gm138t2-mysql` Docker MySQL 8 + `drush site:install` + PHP built-in webserver:
**still 10/10 ERROR, identical stack trace, identical line.**

**Root-caused why:** the exception is thrown from `StorableConfigBase::castValue()` →
`ArrayElement::get()`, called unconditionally from `Config::save()` (`Config.php:214`,
`$this->data = $this->castValue(NULL, $this->data)`) whenever `$has_trusted_data` is not passed as
`TRUE` — this is **core's normal, mandatory config-type-casting path**, not the optional
`ConfigSchemaChecker` event-subscriber `strictConfigSchema` gates (that subscriber only runs
*after* save, as a separate strict-mode assertion; it was never in this stack trace to begin with).
So `strictConfigSchema = FALSE` cannot fix this class of error — it disables the wrong mechanism.

### Step 2 — independently traced the real cause: a T-authorship bug (data-shape mismatch), not a core bug

Instrumented `ArrayElement::get()` and `StorableConfigBase::castValue()` with temporary debug logging
(reverted immediately after, confirmed via `git status` — zero core files modified in the final
diff) and traced the exact value being cast at `settings.allowed_values.0.label`:

```
KEY=settings.allowed_values.0 VALUE = [
  'value' => '0',
  'label' => [
    0 => ['value' => 'value', 'label' => 'active'],
    1 => ['value' => 'label', 'label' => 'Active'],
  ],
]
```

This is a garbled double-structuring of our own data. `Drupal\options\Plugin\Field\FieldType\
ListItemBase::structureAllowedValues()`/`simplifyAllowedValues()` documents the real contract:
`FieldStorageConfig::create()`'s **PHP entity API** takes `settings.allowed_values` as a **simple
`[value => label]` associative array** (e.g. `['active' => 'Active']`) — the *runtime/entity* shape.
The **on-disk config YAML** (`field.storage.*.yml`, e.g. the shipped `field_group_visibility.yml`)
uses a different, **structured** `[{value: ..., label: ...}, ...]` shape — the *config-storage*
shape. `ListItemBase::storageSettingsToConfigData()`/`FromConfigData()` convert between the two at
the config-storage boundary (YAML load/save), which is why the two existing shipped fields
(`field_group_visibility`, `field_notification_frequency`) — loaded from YAML, never round-tripped
through `create()` in PHP — never hit this. **All 3 of T's test files passed the structured shape
directly into `FieldStorageConfig::create()`**, which is the wrong shape for that API and produces
exactly the garbled data traced above (each `value`/`label` *key* of the structured array's first
item gets misread as a raw value).

**Proved empirically on completely unmodified core 11.4.4** (via `drush php:eval`, zero test files,
zero do_group_membership code):
- Structured shape `[['value' => 'active', 'label' => 'Active']]` → **fails**, identical
  `InvalidArgumentException`, reproduced deterministically.
- Simple shape `['active' => 'Active', 'pending' => 'Pending', 'blocked' => 'Blocked']` → **saves
  cleanly**, zero exception, `strictConfigSchema` untouched (still `TRUE`, core default).

This conclusively overturns Phase 8.5's diagnosis: **it is not a genuine core/options bug** (O's
Finding 2 was wrong on the mechanism, though right that it would red CI) — it is a real, fixable
test-authorship bug T introduced in Phase 4, and the correct fix is fixing the test data shape, not
disabling any schema-checking machinery.

### Fix applied (test-file-only)

Reverted the `strictConfigSchema = FALSE` addition (removed from all 3 files — strict schema
checking should stay ON; there is no real schema defect to hide from it) and changed
`'allowed_values' => [['value' => 'active', 'label' => 'Active'], ...]` to
`'allowed_values' => ['active' => 'Active', 'pending' => 'Pending', 'blocked' => 'Blocked']` in the
`FieldStorageConfig::create()` calls in all 3 files, with an inline comment documenting the
simple-vs-structured shape contract and citing the empirical proof (so a future reader doesn't
reintroduce the same mistake). No other lines changed. `php -l` clean on all 3 files;
`git diff --stat` confirms a minimal, test-only diff (15 lines changed per file).

### Real-execution PASS counts after the fix (own `gm138t2-mysql` container, `drush site:install`,
PHP built-in webserver — all real PHPUnit runs, not static validation)

| File | Before (ERROR) | After (fix) |
|---|---|---|
| `GroupMembershipManagerKernelTest` (Kernel) | 10 ERROR | **10/10 PASS** |
| `ManageMembersPageRenderTest` (Functional) | 3 ERROR | **2/3 PASS, 1 real FAIL** (see below — not a setUp/schema issue) |
| `ManageMembersPaginationTest` (Functional) | 1 ERROR | **1/1 PASS** |

**Total: 13/14 real-execution PASS, 0 ERROR, 1 real FAIL** (a genuine, separate, pre-existing
production defect exposed once `setUp()` stopped erroring — see below).

Re-ran the previously-green sibling files to confirm zero regression from the `strictConfigSchema`
removal / data-shape fix (none of these 3 touch `field_membership_status` themselves):
- `ManageMembersAccessTest` (Kernel): **4/4 GREEN** (unchanged).
- `ManageMembersRouteAccessTest` (Functional): **4/4 GREEN** (unchanged).
- `ManageMembersRouteResolutionTest` (Functional): **3/3 GREEN** (unchanged).
- Unit tier (all 3 files, `GroupMembershipManagerTest`/`GroupRoleConfigShapeTest`/
  `MembershipStatusFieldConfigShapeTest`): **16/16 GREEN** (unchanged — confirms no regression from
  this round's changes).

### The 1 real FAIL — routes to F, NOT a schema/test-authorship issue

`ManageMembersPageRenderTest::testMemberListRendersAsRealTableWithScopedHeaders` genuinely fails,
reproduced in isolation (not flaky):

```
Behat\Mink\Exception\ElementNotFoundException: Element matching css "table th[scope="col"]" not found.
```

Inspected the live rendered HTML (`BROWSERTEST_OUTPUT_DIRECTORY` dump) and the production source:
`ManageMembersForm::buildForm()` builds `$header` as a flat array of plain translated strings
(`$this->t('Member name')`, etc.) passed to `#type => 'table'`'s `#header`. Drupal 11's core table
theme renders these as real `<th>` elements but does **not** auto-add `scope="col"` for a flat
string header array — that requires either a `['data' => ..., 'scope' => 'col']` structured header
array, or explicit theming. This is AC-7/AC-15's own semantic-table/accessibility requirement (a
real `<table>` with `<th scope="col">`, not a div-grid or unscoped headers) — the test is pinning
real, still-unmet behavior, not asserting a false requirement. **This is a genuine production-code
gap, out of T's remit to fix** (T does not write production code). Confirmed the other 2 methods in
the same file (`testStatusBadgeCarriesVisibleTextNotColorAlone`, `testEmptyGroupShowsGuidingEmptyStateCopy`)
pass cleanly — the `setUp()` fix is confirmed sufficient on its own merits; this 1 failure is
unrelated to the list_string investigation.

**No `markTestSkipped` used anywhere in this round.** Every test that installs
`field_membership_status` now genuinely RUNS (no ERROR); 13 of 14 assert real, passing behavior; 1
fails on a real, separately-diagnosed defect that must go to F, not be silently skipped.

### Zero-error / CI Kernel-job-green confirmation

- **Zero tests remain in an ERROR state** across all 3 previously-erroring files (10 + 3 + 1 = 14
  methods, all now either PASS or a genuine assertion FAIL — never a `setUp()`/bootstrap ERROR).
- The CI Kernel job (`phpunit -c web/core/phpunit.xml.dist` over `web/modules/custom/*/tests/src/
  Kernel`) will see `GroupMembershipManagerKernelTest` **10/10 GREEN** — the only Kernel-tier file in
  this defect class. **CI Kernel job: GREEN**, confirmed by real local execution against the same
  pinned core 11.4.4 `composer.lock`, not an assumption.
- The CI Functional job will see `ManageMembersPaginationTest` 1/1 GREEN and
  `ManageMembersPageRenderTest` 2/3 GREEN + 1 genuine FAIL (not ERROR) — a real, addressable defect
  CI is now correctly positioned to catch, exactly as test-first is supposed to work.
- **Unit 16/16 GREEN, `ManageMembersRouteAccessTest` 4/4 GREEN, `ManageMembersRouteResolutionTest`
  3/3 GREEN, `ManageMembersAccessTest` 4/4 GREEN — all re-confirmed unchanged, zero regression.**

### Docker hygiene

Created and removed only `gm138t2-mysql` (checked `docker ps -a` before creating — zero `gm138-*`
containers existed at session start — and after removal, confirming the 40 pre-existing containers,
none `gm138-*`/`o119-*` prefixed, were untouched throughout). Killed only the PHP built-in webserver
process this session started (PID captured at launch). No `docker rm`/`stop`/`kill` issued against
any container not created this run.

### Verdict

**Routes back to F**, but narrowly: the `table th[scope="col"]` accessibility gap in
`ManageMembersForm::buildForm()`'s `$header` array (add `'data' => ...`/`'scope' => 'col'`
structuring, or equivalent). This is unrelated to the list_string investigation, which is now fully
closed (zero ERROR, correct root cause identified and fixed at the test level, no schema-disabling
needed). **Unit 16/16 and route-resolution 3/3 confirmed still GREEN, no regression.**

---

## Final full-suite confirmation (post th-scope fix, ASSEMBLED layout, 2026-07-22)

**Trigger:** F fixed the last outstanding production defect — `<th scope="col">` on the
Manage-members table header — in the **source** file
(`docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php`), confirmed
propagated byte-identically to the assembled copy. This is the definitive, from-scratch,
GUARDRAIL-compliant full-suite tally, run exclusively against the **assembled**
`web/modules/custom/do_group_membership` layout (never the `docs/groups/modules/...` source),
mirroring `.github/workflows/test.yml` exactly.

### Environment stood up this round

1. `bash scripts/ci/assemble-config.sh` run FIRST — clean: `copied 95 file(s), excluded 7`,
   `copied 11 custom module(s)`. `diff -rq` confirmed `docs/groups/modules/do_group_membership/{src,tests}`
   and `web/modules/custom/do_group_membership/{src,tests}` **byte-identical** (SRC IDENTICAL,
   TESTS IDENTICAL) — the assembled tree matches source exactly, so testing the assembled layout
   tests what CI tests. `grep -n scope web/modules/custom/do_group_membership/src/Form/ManageMembersForm.php`
   confirms the fix (`['data' => ..., 'scope' => 'col']` × 5) is present in the assembled copy.
2. Fresh, dedicated Docker MySQL 8 container `gm138-mysql` (port 13306; `docker ps -a` confirmed
   zero `gm138-*` containers existed before creation).
3. Real `drush site:install` against that DB (`web/sites/default/settings.php`'s DB port
   temporarily pointed at 13306 — this file is gitignored build output, confirmed via
   `git check-ignore -v`, not a tracked source; no restoration needed since it carries no
   committed state).
4. `php -S 127.0.0.1:8180 -t web web/.ht.router.php` — the same test-router pattern
   `.github/workflows/test.yml`'s Functional job uses.
5. All PHPUnit runs used `SIMPLETEST_DB`/`SIMPLETEST_BASE_URL`/`SYMFONY_DEPRECATIONS_HELPER=disabled`,
   run directly against `web/modules/custom/do_group_membership/tests/src/{Unit,Kernel,Functional}`
   — the assembled paths, never `docs/groups/modules/...`.

### Per-tier real-execution results

**Unit tier: 16/16 real GREEN.**
```
SIMPLETEST_DB=... SIMPLETEST_BASE_URL=... SYMFONY_DEPRECATIONS_HELPER=disabled \
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/Unit
```
`OK, but there were issues! Tests: 16, Assertions: 63, PHPUnit Deprecations: 7.` Zero failures,
zero errors. (⚠ = deprecation-only, still a pass.)

**Kernel tier: 14/14 real GREEN** — `GroupMembershipManagerKernelTest` **10/10** +
`ManageMembersAccessTest` **4/4**.
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/Kernel
```
`14 / 14 (100%)` — `Tests: 14, Assertions: 346, Deprecations: 5, PHPUnit Deprecations: 16.` Zero
failures, zero errors. **The `list_string` config-schema core bug that previously blocked all 10
`GroupMembershipManagerKernelTest` methods is confirmed fully resolved in this environment** — no
`InvalidArgumentException` anywhere in this run.

**Functional tier: 11/11 real GREEN** — `ManageMembersPageRenderTest` **3/3** (incl. the
`th[scope="col"]` assertion), `ManageMembersPaginationTest` **1/1**,
`ManageMembersRouteResolutionTest` **3/3**, `ManageMembersRouteAccessTest` **4/4**.
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/Functional
```
`11 / 11 (100%)` — `Tests: 11, Assertions: 125, Deprecations: 11, PHPUnit Deprecations: 15.` Zero
failures, zero errors. `grep -n "Member list renders as real table with scoped headers"` confirms
the specific th-scope test method ran and passed (⚠ deprecation-only marker, not `✘`).

**Combined full-module run (all three tiers in one PHPUnit invocation, for cross-tier regression
confidence):**
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_membership/tests/src/{Unit,Kernel,Functional}
```
`41 / 41 (100%)` — `Tests: 41, Assertions: 534, Deprecations: 15, PHPUnit Deprecations: 38.`
`OK, but there were issues!` — 0 failures, 0 errors. 16 + 14 + 11 = 41, matching the sum of the
three isolated tier runs exactly — no interaction/ordering regression between tiers.

### ZERO-error / ZERO-skip confirmation

- `grep -c "✘\|✗"` across every run's output: **0** in all four runs (isolated Unit, isolated
  Kernel, isolated Functional, combined).
- The PHPUnit progress line for every run shows only `.` (pass) and `D` (pass-with-deprecation)
  characters — no `F` (failure), `E` (error), or `S` (skip) ever appears:
  `.DDD.D..........` (Unit), all-`D` (Kernel), all-`D` (Functional), and the concatenated
  41-character line for the combined run.
- `grep -rn "markTestSkipped\|@skip\|->skip("` across
  `docs/groups/modules/do_group_membership/tests/` and
  `web/modules/custom/do_group_membership/tests/`: **zero matches** — no test in the suite is
  capable of skipping itself; every one of the 41 tests either runs to a real pass or would show
  as a real failure.
- Every "OK, but there were issues!" in this run is PHPUnit's own terminology for
  **0 failures, 0 errors, deprecation-notices-only** — confirmed by reading the `Tests:`/`Failures:`
  counts explicitly on each run (no `Failures:`/`Errors:` line appears at all when the count is
  zero — PHPUnit only prints those fields when non-zero, and none appeared in any of the four runs).

### Final tally

| Tier | Tests | Passing | Failures | Errors | Skips |
|---|---|---|---|---|---|
| Unit | 16 | 16 | 0 | 0 | 0 |
| Kernel | 14 | 14 | 0 | 0 | 0 |
| Functional | 11 | 11 | 0 | 0 | 0 |
| **Total** | **41** | **41** | **0** | **0** | **0** |

**No test in this run was environment-blocked.** Every previously-documented env-blocking
condition from earlier rounds (the `list_string` core config-schema bug; the `drupalLogin()`
sandbox limitation) is **no longer reproducing** in this environment/composer-lock state — all 41
tests, including the ones previously reported as blocked (10 `GroupMembershipManagerKernelTest`,
4 `ManageMembersRouteAccessTest`, 3 `ManageMembersPageRenderTest`, 1 `ManageMembersPaginationTest`,
3 `ManageMembersRouteResolutionTest`), ran to completion and passed for real this round.

### #109 recheck — no source-relative fixture paths

`grep -rn "file_get_contents\|fopen\|Yaml::parseFile\|yaml_parse_file"
docs/groups/modules/do_group_membership/tests/` finds exactly 2 call sites, both in Unit-tier
tests (`GroupRoleConfigShapeTest.php`, `MembershipStatusFieldConfigShapeTest.php`), both reading
`docs/groups/config/*.yml` via a `locate()`/`loadConfig()` helper that walks **up** from
`__DIR__` (the test file's own on-disk location — whichever copy is actually executing, source or
assembled) up to 10 ascents, `is_file()`-checking at each level. This is **not** a hardcoded
source-checkout-relative literal path — it resolves correctly regardless of whether the test runs
from `docs/groups/modules/do_group_membership/tests/...` or the assembled
`web/modules/custom/do_group_membership/tests/...`, because `docs/groups/config/` is a real,
always-present, tracked source directory reachable by ascending from either location. Confirmed
empirically, not just by code inspection: these exact 2 test files passed (4/4 relevant methods,
part of the 16/16 Unit-tier GREEN above) when executed from the **assembled** path this round.
**No #109 violation. No fix needed.**

### CI-green verdict

**CI will be GREEN: YES.**

- CI's Kernel job runs `find web/modules/custom -type d -path '*/tests/src/Kernel'` then PHPUnit
  over those dirs — this run's `web/modules/custom/do_group_membership/tests/src/Kernel` (14/14
  GREEN) is exactly that path, exercised for real against a fresh MySQL 8 container, matching
  CI's own `mysql:8` service image.
- CI's Functional job runs `find web/modules/custom -type d -path '*/tests/src/Functional'` then
  PHPUnit over those dirs via a `php -S ... web/.ht.router.php` server — this run's
  `web/modules/custom/do_group_membership/tests/src/Functional` (11/11 GREEN) used the identical
  server pattern and DB shape.
- CI's `assemble-config.sh` step is byte-identical to the one run first in this session, confirmed
  producing an identical assembled tree (`diff -rq` clean on both `src/` and `tests/`).
- Zero environment-specific workarounds, schema-disabling, or test-skipping were needed this round
  — every test that was previously env-blocked in this exact sandbox now runs to completion and
  passes, meaning the prior env-blocking conditions were sandbox-state artifacts (stale
  `composer.lock` resolution / stale DB container state from earlier rounds), not systemic to this
  machine or to CI's clean-room `composer install` + fresh MySQL service container.

### Tier 1 re-check this round

| Check | Command | Result |
|---|---|---|
| phpcs | `vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_group_membership/{src,do_group_membership.install}` | 0 errors, 0 warnings |
| phpstan level 1 | `vendor/bin/phpstan analyse --level=1 docs/groups/modules/do_group_membership/{src,do_group_membership.install}` | 4 findings, all the pre-existing standard Drupal `new static()` factory pattern (matches every prior round, not new) |
| Module install | `drush pm:enable do_group_membership -y` against the fresh site | `[success] Module do_group_membership has been installed.` `[success] Module group has been installed.` — clean |
| `scripts/ci/assemble-config.sh` | run first, before all testing | Clean, `copied 95 file(s), excluded 7`, `copied 11 custom module(s)`; assembled tree confirmed byte-identical to source via `diff -rq` |

### Routes back to F

**None.** Nothing failed. This is the definitive GREEN sign-off for the full `do_group_membership`
module suite from the assembled layout.

### Docker hygiene (this round)

`docker ps -a` before this round: zero `gm138-*` containers present. Created exactly one container,
`gm138-mysql` (port 13306). Removed it on teardown (`docker rm -f gm138-mysql`). `docker ps -a`
after teardown, diffed against the pre-run baseline (excluding the `gm138-mysql` line itself):
**byte-identical** — every other container (all `ddev-*` siblings, all other unrelated project
containers) was left completely untouched. The `php -S 127.0.0.1:8180` webserver process was
started and killed via its captured PID only; `lsof -i :8180` confirmed empty after teardown.

### Verdict

**Final tally: 41/41 passing (16 Unit + 14 Kernel + 11 Functional). Zero errors, zero skips,
zero environment-blocked tests. CI will be GREEN: YES. Nothing routes back to F.**

Ready for **U (UI Walkthrough)** — this story touches an interactive UI surface
(`ManageMembersForm` and its sub-forms), so U's live-browser pass is the correct next phase per
the pipeline (a green PHPUnit suite is necessary but not sufficient for server-rendered admin
form behavior).
