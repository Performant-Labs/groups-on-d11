# Handoff-T-red: Phase 4 - Issue #190 Harness Gaps

**Date:** 2026-07-24
**Branch:** 190-harness-gaps
**Worktree:** C:/Users/aange/Projects/_worktrees/groups-harness-gaps-190
**Brief / wireframe reviewed:** Issue #190 (harness gaps: kernel node_access realm setup, functional /all-groups 404)

## A precondition
Task assumes A already approved the plan (kernel Gap-1 spike option vs. drop; functional view-install
gap). No new architecture decisions made here — this phase only un-skips existing authored tests.

## Tests un-skipped (markTestSkipped lines removed)
1. `PrivacyAccessTest::testMemberNotForbiddenFromViewingNodeInPrivateGroup` (Kernel) — AC-4: a member
   is NOT forbidden from viewing a node in their own private group.
2. `PrivacyAccessTest::testNonMemberNotForbiddenFromViewingNodeInPublicGroup` (Kernel) — AC-4 negative:
   a non-member is NOT forbidden from viewing a node in a public group.
3. `PrivacyDirectoryTest::testAnonymousAllGroupsOmitsSecurityTeamLiterally` (Functional) — AC-3:
   anonymous `/all-groups` omits "Security Team" literally.
4. `PrivacyDirectoryTest::testAnonymousStillSeesPublicGroup` (Functional) — negative baseline: public
   group still visible to anonymous.
5. `PrivacyDirectoryTest::testMemberSeesPrivateGroupInDirectoryAndCanonical` (Functional) — AC-5:
   member sees private group in directory + canonical 200.

Confirmed: all 5 `$this->markTestSkipped(...)` lines removed; no other lines touched (verified via
`grep -n markTestSkipped` on both files — zero matches remain). No production code or `setUp()`
logic modified.

## RED confirmation

Environment setup required to run at all (worktree had no vendor/, stale `.ddev/config.yaml` name
`gm145-wcag` copied from source worktree — renamed to `gm190-harness`, `ddev start`, `ddev composer
install`, `ddev exec bash scripts/ci/assemble-config.sh`). Kernel run needed
`SIMPLETEST_DB=mysql://db:db@db:3306/db`.

**Kernel** (`php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox
web/modules/custom/do_group_extras/tests/src/Kernel/PrivacyAccessTest.php --filter '...'`):

- `testMemberNotForbiddenFromViewingNodeInPrivateGroup`: **RED**
  ```
  A member is not forbidden from viewing content in their own private group.
  Failed asserting that true is false.
  PrivacyAccessTest.php:275
  ```
- `testNonMemberNotForbiddenFromViewingNodeInPublicGroup`: **RED**
  ```
  A non-member is not forbidden from viewing content in a public group.
  Failed asserting that true is false.
  PrivacyAccessTest.php:288
  ```
  Both fail on the real assertion (access IS forbidden when it shouldn't be) — right reason, not a
  setup/import error. `Tests: 2, Assertions: 46, Failures: 2.`

**Functional** (`php vendor/bin/phpunit -c phpunit.functional.xml
web/modules/custom/do_group_extras/tests/src/Functional/PrivacyDirectoryTest.php --filter '...'`),
run via `ddev exec` against the assembled BrowserTestBase harness:

- `testAnonymousAllGroupsOmitsSecurityTeamLiterally`: **RED**
  ```
  Current response status code is 404, but 200 expected.
  PrivacyDirectoryTest.php:204
  ```
- `testAnonymousStillSeesPublicGroup`: **RED**
  ```
  The text "Drupal NorCal" was not found anywhere in the text of the current page.
  PrivacyDirectoryTest.php:218
  ```
- `testMemberSeesPrivateGroupInDirectoryAndCanonical`: **RED**
  ```
  Current response status code is 404, but 200 expected.
  PrivacyDirectoryTest.php:229
  ```
  `Tests: 3, Assertions: 11, Failures: 3.` All three fail exactly as their former skip messages
  predicted (`/all-groups` 404 — view-install gap), confirming the documented harness gap is real
  and not stale.

## Ready for F
Confirmed: all 5 tests are valid RED, failing for the right reason (real assertions against
missing/incomplete harness setup, not import/typo errors). F may implement against these —
kernel tests need the node_access realm/grants setup (Gap-1); functional tests need the
`/all-groups` view installed in the BrowserTestBase harness (Gap-2). No SURPRISINGLY GREEN cases,
so Gap-1's "drop the spike" option (plan option c) does not apply — F should proceed with the
harness fix.
