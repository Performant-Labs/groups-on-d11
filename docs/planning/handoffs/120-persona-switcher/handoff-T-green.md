# Handoff-T-green: Phase 6 - #120 SC-1 Persona Switcher

**Date:** 2026-07-23
**Branch:** 120-persona-switcher
**Issue:** #120
**Handoff-F reviewed:** `docs/planning/handoffs/120-persona-switcher/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/120-persona-switcher/handoff-T-red.md`

## Task 1: Repaired the 3 flagged test-authorship bugs

### Bug A — `PersonaSpecTest::testEveryPersonaHasExpectedUname`

File: `docs/groups/modules/do_showcase/tests/src/Kernel/PersonaSpecTest.php`

Before (line 102):
```php
$expected = self::EXPECTED_UNAME[$persona['id']] ?? '__unexpected__';
```
PHP's `??` treats the `anonymous` key (whose correct expected value IS `NULL`) as absent, always
falling to the `'__unexpected__'` fallback regardless of what `ShowcaseCatalog::personas()` ships.

After:
```php
$this->assertArrayHasKey($persona['id'], self::EXPECTED_UNAME, sprintf('Persona id "%s" is not one of the expected personas.', $persona['id'] ?? '?'));
$expected = self::EXPECTED_UNAME[$persona['id']];
```
`array_key_exists`-equivalent guard (`assertArrayHasKey`) preserves the "reject an unexpected persona
id" safety net while correctly indexing a real `NULL` expected value for `anonymous`.

### Bug B/C — Mink `followRedirects(true)` default

Files:
- `docs/groups/modules/do_showcase/tests/src/Functional/PersonaSwitchControllerMethodTest.php`
  (`testPostOnNonAnonymousPersonaRedirects`, `testGetOnAnonymousRedirects`)
- `docs/groups/modules/do_showcase/tests/src/Functional/PersonaUidOneGuardTest.php` (shared
  `postAndGetStatus()` helper — fixes `testPostToAllowlistedPersonaSucceeds` and the other 2
  methods that call it)

Added `$client->followRedirects(false);` immediately after `$client = $this->getSession()->getDriver()->getClient();`
in all 3 call sites, before the `->request()` call, so `getInternalResponse()->getStatusCode()`
reflects the actual first response (302/303) instead of the followed destination's 200.

`testGetOnAnonymousRedirects` originally used `$this->drupalGet(...)` (no raw-client access) —
switched it to the same `getClient()->followRedirects(false)` + `request('GET', ...)` technique used
elsewhere in the suite, since `drupalGet()` has no "don't follow redirects" mode.

None of the tests' fixture data or module setup changed — only the redirect-following behavior of
the underlying BrowserKit client.

## Task 2: Full GREEN + Tier 2 verification

All commands run from the assembled layout (`bash scripts/ci/assemble-config.sh`) inside DDEV
(`gm120-groups-on-d11`).

### Kernel — story-scoped
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel"
```
**19/19 GREEN** (incl. the fixed `testEveryPersonaHasExpectedUname`). "OK, but there were issues" is
2 PHP 8.4/Drupal-core deprecation notices (`installSchema()`, `#[RunTestsInSeparateProcesses]`) — not
failures.

### Kernel — full custom-module regression
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Kernel')"
```
**123/123 GREEN** (was 122/123 per F's handoff; the +1 is `testEveryPersonaHasExpectedUname` now
passing). Zero collateral breakage across `do_group_extras`, `do_group_membership`, `do_group_pin`,
`do_multigroup`, `do_notifications`, `do_discovery`, `do_streams`, `do_tests`, etc.

### Unit — full
```
ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Unit')"
```
**62/62 GREEN.** This run originally surfaced a genuine 63rd-test failure not caused by F or by any
of the 3 flagged bugs — see "Additional test-authorship bug found + fixed" below.

### Functional — story-scoped
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Functional"
```
**17/17 GREEN** (was 14/17 per F's handoff; the +3 are the Bug B/C fixes). Testdox marks some passes
with `⚠` (deprecation warnings from twig/twig 3.28 and `EntityBase::getOriginal()`), not failures —
confirmed via the summary line `Tests: 17, Assertions: 63, ... ` with zero `Failures:`/`Errors:`.

### Functional — full custom-module regression
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Functional')"
```
**46/46 GREEN.** Zero regressions elsewhere (`do_group_extras`, `do_group_membership` route/access
suites all unaffected).

### E2E parse check
```
npx playwright test tests/e2e/persona-switcher.spec.ts --list
```
`Total: 4 tests in 1 file` — unchanged, parses cleanly after the E2E fixes (see Task 3).

## Additional test-authorship bug found + fixed (beyond the 3 flagged)

**`GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape`**
(`docs/groups/modules/do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`, a
**pre-existing test from #138**, not authored this story) asserted
`$this->assertTrue($data['admin'] ?? NULL, ...)` against
`group.role.community_group-groups_moderate.yml`. #120's own approved `brief-amendments.md`
Amendment 1 (A-approved at Phase 3, `handoff-A-plan-2.md`) explicitly and deliberately flips this
SAME file from `admin: true` (blanket bypass) to `admin: false` + an enumerated permission set
(`administer members`, `edit group`) — see Amendment 1's own rationale ("currently admin: true,
permissions: {} — bypass mode; hides scope-limit test"). This story's own
`do_showcase/tests/src/Unit/GroupsModerateRoleConfigShapeTest.php` already pins the NEW shape
correctly and was GREEN. The #138 test was asserting the OLD, now-superseded shape and would have
regressed the Unit suite the moment F's approved config edit landed.

This is a genuine test-authorship staleness bug (an older test's invariant invalidated by this
story's approved, in-scope amendment) — squarely T's territory ("if the test is wrong, T fixes it"),
not a production defect and not F's to touch.

**Fix** (surgical, 3 edit points only — doc comment + 1 assertion + additive permission checks):
- Updated the doc comment (was: "scope: outsider, admin: true, ..."; now: "scope: outsider,
  global_role: groups_moderate (admin flag corrected below)"), with a "SECOND CORRECTION at #120
  T-green" note explaining the supersession and pointing at the authoritative
  `GroupsModerateRoleConfigShapeTest`.
- Replaced `assertTrue($data['admin'] ?? NULL, ...)` with `assertFalse(...)` plus the same
  enumerated-permission assertions (`administer members`, `edit group`, no `view group_node:*`) that
  `GroupsModerateRoleConfigShapeTest` already independently pins — kept both tests rather than
  deleting one, since they exercise the same invariant from two different module suites'
  perspectives (this is the ONE case in this story where near-duplicate coverage is intentional: the
  #138 suite's own AC-13 test needs to keep asserting *some* correct shape for that file, not go
  silently stale).
- Did **not** touch `testOrganizerRoleConfigShape`, `testModeratorRoleConfigShape`,
  `testMemberRoleConfigUnchanged`, or the `expectedContentPermissions()`/`expectedViewOnlyPermissions()`
  helpers — confirmed via `git diff` that only the 3 named spots changed.

Verified via `git diff --stat`: 1 file changed, +19/-4 lines (surgical, not a rewrite).

Re-ran the full Unit suite after this fix: **62/62 GREEN** (see Task 2 above).

## Task 3: E2E run against a seeded stack — MANDATORY GREEN

Ran the full local-seed sequence mirroring `.github/workflows/test.yml`'s E2E job (fresh install,
not reusing any prior DB state):

```
ddev drush site:install standard --db-url='mysql://root:root@db:3306/db' --account-pass=admin -y
ddev drush config:set system.site uuid <assembled-config's system.site.yml uuid> -y   # required: fresh
                                                                                        # install's UUID
                                                                                        # never matches
                                                                                        # the assembled
                                                                                        # config's, and
                                                                                        # cim rejects an
                                                                                        # apparent
                                                                                        # "delete all
                                                                                        # config" import
                                                                                        # otherwise
ddev drush cim --source=../config/sync -y
ddev drush user:password admin admin
ddev drush cache:rebuild -y
# Seed scripts, in order (each wrapped to run as uid 1, matching the entrypoint convention):
ddev drush php:script <wrapper requiring step_700_demo_data.php>
ddev drush php:script <wrapper requiring step_720_group_types.php>
ddev drush php:script <wrapper requiring step_780_nav_menu.php>
ddev drush php:script <wrapper requiring step_790_persona_switcher.php>
ddev drush cache:rebuild -y
ddev drush user:information groups_moderate_demo   # confirms seed applied
```

Confirmed seed applied — `groups_moderate_demo` (uid 8) exists with the `groups_moderate` role;
`step_790`'s own console output confirmed Maria's Organizer grant on "DrupalCon Portland 2026" and
the pending relationship (`ravi_patel` -> "Core Committers").

```
BASE_URL='http://gm120-groups-on-d11.ddev.site' npx playwright test tests/e2e/persona-switcher.spec.ts --reporter=list
```

**Result: 3/4 PASS, 1 FAIL (a real production defect — see below).** Two rounds of this run
(before and after the E2E test-authorship fixes below) both landed on the same 3-pass/1-fail split,
confirming the result is stable, not flaky.

### E2E test-authorship bugs found + fixed (all in `tests/e2e/persona-switcher.spec.ts`, T's own file)

1. **`selectOption({ label: /Maria Chen/i })` (3 occurrences)** — Playwright's `selectOption`
   `label` matcher requires an exact string, not a `RegExp` (`Error: options[0].label: expected
   string, got object`). Fixed by using the exact rendered option-label string
   (`'Maria Chen — Organizer'`, confirmed against the real DOM via `curl`) in all 3 tests that
   select Maria.
2. **`page.locator('form button[type="submit"]')` in the visible-focus test** — resolved to 3
   elements on the seeded front page (the persona-switcher "Go" button PLUS 2 unrelated search-form
   submit buttons elsewhere on the page), triggering a Playwright strict-mode violation. Fixed by
   scoping to the persona-switcher form's actual class:
   `page.locator('form.do-showcase-persona-switcher-form button[type="submit"]')`.

After these 2 fixes, tests 2, 3, 4 (Maria Chen full-switch, keyboard-only, visible-focus) all PASS.
Test 1 (Groups-Moderate full-switch) still fails — see the production defect below; its expected
banner copy (`"You're browsing as Groups-Moderate — switch back"`) is unchanged and correct per the
wireframe, so no further test-authorship fix applies here.

### Production defect found (escalated to O — NOT edited)

**`DoShowcaseHooks::personaBanner()`** (`docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`,
line 247: `'@name' => $active_persona['name']`) renders the Groups-Moderate persona's banner as
**"You're browsing as Moderator — switch back"**, but `wireframe.md` line 153 explicitly locks this
copy as **"You're browsing as Groups-Moderate — switch back"** (also lines 87, 92-93, 191-192 —
the wireframe consistently uses "Groups-Moderate" as this persona's display name everywhere, never
"Moderator").

Root cause: `ShowcaseCatalog::personas()`'s `moderator` entry has `'name' => 'Moderator'`
(`ShowcaseCatalog.php:134`), and `personaBanner()` consumes `$persona['name']` directly. But
`PersonaSwitcher::optionLabel()` (`PersonaSwitcher.php:181-188`) does NOT trust `$persona['name']`
for this same persona — it hardcodes a `match` returning `'Groups-Moderate'` for `'moderator'`,
correctly matching the wireframe. Confirmed via `curl` against the live seeded page: the dropdown
`<option value="moderator" ...>Groups-Moderate</option>` renders correctly, while the banner (once
switched) renders "Moderator". This is an internal inconsistency: two different code paths reading
the same catalog entry disagree on the moderator persona's display name, and the banner path is the
one that's wrong relative to the wireframe.

**This is a production-code defect, not a test-authorship issue** — my E2E assertion
(`toContainText("You're browsing as Groups-Moderate — switch back")`) correctly pins the wireframe's
locked copy. Per the pipeline contract (T never edits production code; F writes no tests), I did
not touch `DoShowcaseHooks.php` or `ShowcaseCatalog.php`. **Escalating to O**: either (a) change
`ShowcaseCatalog::personas()`'s `moderator.name` from `'Moderator'` to `'Groups-Moderate'` (simplest —
makes the catalog itself match the wireframe, and `PersonaSwitcher::optionLabel()`'s existing
hardcoded `match` would keep working unchanged since it doesn't consume `name` for this branch
anyway), or (b) change `personaBanner()` to use the same per-id `match`-based display-name logic
`PersonaSwitcher::optionLabel()` already has instead of trusting `$persona['name']`. Option (a) is
the smaller, single-source-of-truth fix.

## Task 4: CI workflow gap fixed

File: `.github/workflows/test.yml`

- Added `PERSONA_SCRIPT="$PWD/docs/groups/scripts/step_790_persona_switcher.php"` alongside the 3
  existing `*_SCRIPT` variable declarations in the "Seed full demo data" step.
- Added a 4th `cat > /tmp/seed-persona.php <<PHP ... PHP` + `php vendor/drush/drush/drush.php
  php:script /tmp/seed-persona.php` block, matching the exact existing pattern (uid-1 impersonation
  wrapper) used for the demo/types/nav scripts, placed after the nav-seed invocation and before the
  final `cache:rebuild -y`.
- Renamed the step's own name from "Seed full demo data (groups, content, types, nav) — mirror the
  deployed image" to "...(groups, content, types, nav, personas) — mirror the deployed image" and
  extended its doc comment to mention `persona-switcher.spec.ts`'s dependency on this seed.
- Did not touch the `drush en` module list in the earlier "Install Drupal + import assembled
  config" step — confirmed (per F's own note) that `do_showcase`/`do_chrome` are already enabled via
  the `config:import` step (assemble-config.sh patches every custom `do_*` module into the assembled
  `core.extension.yml`), so no change was needed there.

## Acceptance criteria status

| brief.md AC bullet | Status | Backing test |
|---|---|---|
| D11 masquerade compatibility (superseded: dep dropped, Amendment 3) | N/A (recorded on issue/PR, not testable) | — |
| Anonymous visitor sees "Browse as" dropdown; per-option tooltips render | PASS | `PersonaSwitcherDropdownTest` (Functional), `PersonaSwitcherRenderTest` (Kernel), `HelpTextPersonaKeysTest` (Kernel) |
| Switching to Elena/Maria: session becomes that user; banner shows correct copy; switch-back returns to anonymous | PASS | `PersonaBannerTest`, `PersonaSwitchControllerMethodTest`, `PersonaUidOneGuardTest` (all Functional); E2E Maria Chen full-switch test |
| Switching to Groups-Moderate: session becomes that user; banner shows; switch-back returns to anonymous | **FAIL (production defect)** | E2E Groups-Moderate full-switch test — banner text wrong ("Moderator" not "Groups-Moderate"); underlying Functional/Kernel suites don't pin this specific persona's exact banner copy today (a coverage gap this defect also exposes — see Advisory notes) |
| uid 1 unreachable via any masquerade path | PASS | `PersonaAccessCheckTest` (Kernel), `PersonaUidOneGuardTest` (Functional) |
| Maria (Organizer) can edit/manage; Elena (Member) cannot | PASS | `PersonaAccessPositiveTest` (Functional) |
| Groups-Moderate: pending queue/approve/archive/restore on unjoined group; CANNOT reach admin surfaces | PASS | `PersonaAccessPositiveTest`, `PersonaAccessNegativeTest`, `GroupsModerateRoleConfigShapeTest` (Unit) |
| Playwright: full switch->verify->switch-back for >=2 personas incl. Moderator | **FAIL (production defect above)** | `tests/e2e/persona-switcher.spec.ts` — Maria Chen PASSES, Groups-Moderate FAILS on banner copy |
| `HelpText` append-only 4 new `persona.*` keys | PASS | `HelpTextPersonaKeysTest` (Kernel) |
| Seed `step_790_persona_switcher.php` | PASS | Confirmed via live `drush user:information groups_moderate_demo` + script's own console output during the Task-3 seeded run |
| WCAG 2.2 AA: label, keyboard-operable, visible focus, contrast, non-color status | PASS | `PersonaSwitcherDropdownTest` (label), E2E keyboard-only + visible-focus tests (both now GREEN after the E2E test-authorship fixes) |
| Existing suite stays green; CI passes | PASS (PHPUnit) / **BLOCKED (E2E)** | Full Kernel 123/123, Unit 62/62, Functional 46/46 all GREEN; E2E job would fail on the Groups-Moderate banner-copy assertion until the production defect is fixed |

## Blocking issues

**One blocking issue: the Groups-Moderate persona banner production defect described above.**
`DoShowcaseHooks::personaBanner()` renders "You're browsing as Moderator — switch back" instead of
the wireframe-mandated "You're browsing as Groups-Moderate — switch back". This fails:
- E2E test 1 (`persona-switcher.spec.ts:41`, "Groups-Moderate: switch, verify pending-queue access,
  switch back")
- By extension, the brief's own AC bullet "Playwright: full switch->verify->switch-back for >= 2
  personas incl. Moderator" (Maria Chen's equivalent test passes; Groups-Moderate's does not)

This must go back to **F** (production code fix — either the `ShowcaseCatalog::personas()` catalog
entry or `personaBanner()`'s name-resolution logic, per the "Task 3" section above for the two
candidate fixes and their tradeoffs). Per pipeline contract, **T does not fix production code** — I
did not touch `DoShowcaseHooks.php` or `ShowcaseCatalog.php`. After F's fix, T should be re-run
(just the E2E spec's Groups-Moderate test needs re-verification; the PHPUnit suites are unaffected
since none of them assert this specific string).

**Everything else is unblocked**: all 3 flagged test-authorship bugs are fixed and verified GREEN;
the 1 additional stale-test bug found (`GroupRoleConfigShapeTest`) is fixed and verified GREEN; the
CI workflow gap is fixed; full PHPUnit regression (Kernel + Unit + Functional) is 100% GREEN with
zero collateral breakage; 3 of 4 E2E tests pass against a genuinely seeded site.

## Advisory notes

- The Groups-Moderate banner-copy defect exposes a real coverage gap: no Functional test today
  asserts the EXACT banner text for the Groups-Moderate persona the way `PersonaBannerTest` does for
  Elena (`testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink`). Once F fixes the defect,
  consider whether a parallel `PersonaBannerTest` method for the Moderator persona would have
  caught this earlier (at PHPUnit tier, cheaper than E2E) — flagging for O/F's judgment, not adding
  it myself here since Phase 6 is verify-only for previously-authored tests, not new-test authorship
  against a still-open defect.
- The near-duplicate assertion now shared between `GroupRoleConfigShapeTest::testGroupsModerateRoleConfigShape`
  (do_group_membership, #138) and `GroupsModerateRoleConfigShapeTest` (do_showcase, #120) is a
  deliberate, documented exception to "don't duplicate another test" — both suites have a legitimate
  reason to independently pin the same config file's shape (the #138 suite's own AC-13 regression
  guard vs. #120's Amendment-1 RED anchor). Not flagged for deletion.
- Numerous PHP 8.4 / Drupal 11.4 deprecation notices appear across the full regression run
  (`KernelTestBase::installSchema()`, `#[RunTestsInSeparateProcesses]`, `EntityBase::getOriginal()`,
  a twig/twig 3.28 sandbox-policy signature notice) — all pre-existing across the whole test suite,
  not introduced by this story, and `SYMFONY_DEPRECATIONS_HELPER=disabled` in CI already prevents
  them from failing the build. Not actionable for this story.
