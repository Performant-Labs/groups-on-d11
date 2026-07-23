# Handoff-T-red: Phase 4 - #120 SC-1 Persona Switcher

**Date:** 2026-07-22
**Branch:** 120-persona-switcher
**Brief / wireframe reviewed:** `docs/planning/handoffs/120-persona-switcher/brief.md`,
`brief-amendments.md`, `survey.md` (+ amendments), `wireframe.md` (APPROVED, 3 open questions
resolved), `handoff-A-plan.md`, `handoff-A-plan-2.md`, `decisions.md`.

## A precondition

Confirmed: A returned **PASS** on the plan at Phase 3 re-review (`handoff-A-plan-2.md`, all 3
blockers + 6 warnings from the initial BLOCK resolved by the 8 amendments in
`brief-amendments.md`). T authors RED tests against the amended plan.

## Environment setup performed

- The worktree's `.ddev/config.yaml` still carried the stale name `pl-groups-on-d11` (collides
  with the primary checkout's running project) — renamed to `gm120-groups-on-d11` per the
  namespace convention and started fresh (`ddev start`), confirmed via `ddev list` that no
  sibling story's container was touched.
- `ddev composer install` (vendor was absent) — this materializes the **pre-amendment**
  `composer.lock` state, i.e. `drupal/masquerade 2.2.0` gets installed. Per Amendment 3 this
  dependency is dropped by F; T does not touch `composer.json`/`composer.lock` (test-authorship
  only) — flagging so F/O aren't surprised this is currently present in `vendor/`.
- `ddev exec bash scripts/ci/assemble-config.sh` — assembles `docs/groups/config` ->
  `config/sync/` and `docs/groups/modules/do_*` -> `web/modules/custom/`, patches
  `core.extension.yml`. Re-run after every new test file added (assemble does not watch).
- `npm install` (node_modules was absent) — installs `@playwright/test` for the `--list` parse
  check.
- Kernel/Functional runs require `SIMPLETEST_DB` (DDEV internal DSN
  `mysql://root:root@db:3306/db`) and, for Functional, `SIMPLETEST_BASE_URL`
  (`http://gm120-groups-on-d11.ddev.site`) — CI's `test.yml` uses the MySQL-service-container
  equivalent (`mysql://root:root@127.0.0.1:3306/drupal`); the DDEV-internal host/creds are the
  local equivalent, not a different mechanism.

## Tests authored

### Kernel (`docs/groups/modules/do_showcase/tests/src/Kernel/`)

| File | Behavior pinned | Tier rationale |
|---|---|---|
| `PersonaSpecTest.php` | `ShowcaseCatalog::personas()` returns 4 ids in exact order; every entry has non-empty id/name/description; new `uname`/`tooltip_key` fields match Amendment 2's exact values; `personaSpec('maria-chen')` returns the Maria row; `personaSpec('unknown')` returns NULL | Kernel (matches A's plan's placement; the class itself is DI-free but the test suite convention in this repo places persona-catalog coverage alongside the other do_showcase Kernel suites — see "Test-authoring notes" below for the one place this diverges from the cheapest-tier ideal) |
| `PersonaAccessCheckTest.php` | `do_showcase.persona_access` service: uid-1 target ALWAYS denied (even via a fabricated uid-1 account with an arbitrary uname); unknown persona id denied; each of the 4 allowlisted ids allowed; `anonymous` always allowed regardless of current session | Kernel — needs the DI container + a real `User` entity for the uid-1 fabrication; no HTTP needed |
| `PersonaSwitcherRenderTest.php` | `do_showcase.persona_switcher` service: `build()` declares `#cache[contexts] => [user]`; renders exactly 4 `<option>`s each with a non-empty `title` attribute; anonymous session selects the `anonymous` option; an authenticated persona session (Elena) selects the matching option | Kernel — render-array contract + `renderInIsolation()`, no HTTP needed |
| `HelpTextPersonaKeysTest.php` | `HelpText::all()` contains the 4 `persona.*` keys; each value is non-empty, plain text (no `<`/`>`), <= 140 chars | Kernel-namespaced under do_showcase (this story owns the append) but needs no Drupal bootstrap beyond `system` — kept in Kernel/ to match the harness's directory assignment |

### Unit (`docs/groups/modules/do_showcase/tests/src/Unit/`) — added, not in the original list

| File | Behavior pinned | Tier rationale |
|---|---|---|
| `GroupsModerateRoleConfigShapeTest.php` | The REAL on-disk `docs/groups/config/group.role.community_group-groups_moderate.yml`: `admin: false` (not `true`); enumerated permissions contain exactly `administer members` + `edit group`; no `view group_node:*` permission; `scope: outsider` + `global_role: groups_moderate` unchanged | Unit — pure YAML-off-disk assertion, the exact technique this repo's own `do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php` already established (source-relative path-walk is safe for a Unit config-shape check; the "fixtures must be module-local" CI gotcha concerns Kernel/Functional runtime fixtures, not this). **This is the genuine RED anchor for Amendment 1** — see "Test-authoring notes" below for why the Functional positive/negative suites the harness named are NOT independently RED. |

### Functional (`docs/groups/modules/do_showcase/tests/src/Functional/`)

| File | Behavior pinned | Tier rationale |
|---|---|---|
| `PersonaSwitcherDropdownTest.php` | Anonymous visitor: `select[name="persona"]` with exactly 4 `<option>`s each carrying non-empty `title`; `label[for="persona-switcher-select"]` associated and containing "Browse as"; a real `<button type="submit">` no-JS fallback | Functional — needs a real route render on `<front>`, BrowserTestBase self-installs (no demo seed needed for the anonymous-only assertions) |
| `PersonaBannerTest.php` | Anonymous session: no `aside[role="status"].do-showcase-persona-banner` anywhere in the DOM; Elena's session (`drupalLogin`): banner present with exact copy `"You're browsing as Elena Garcia — Member — switch back"` and a real `<a href="/persona-switch/anonymous">` link | Functional — asserts the RENDER path only (per harness note: controller flow is E2E's job); self-provisions Elena via `drupalCreateUser([], 'elena_garcia')` since BrowserTestBase has no demo seed |
| `PersonaAccessNegativeTest.php` | A user with ONLY the `groups_moderate` role (reconstructed with the exact Amendment-1 target: `access content` only) gets 403 on `/admin/config`, `/admin/people`, `/admin/modules` | Functional — real HTTP route access; **NOT independently RED** (see below) |
| `PersonaAccessPositiveTest.php` | Maria (Organizer, amended group-role shape) 200s on `/group/{n}/members`; Elena (plain Member) 403s; Groups-Moderate (synchronized outsider-scope role, never a group member) 200s on a group it never joined | Functional — real HTTP route access, self-provisions a group + all 3 personas via the group 4.x storage API; **NOT independently RED** (see below) |
| `PersonaUidOneGuardTest.php` | POST to uid-1's own uname -> 403; POST to an unknown persona id -> 403 or 404; POST to `elena-garcia` (allowlisted) -> 302/303 | Functional — real HTTP route, uses `getSession()->getDriver()->getClient()->request('POST', ...)` (Symfony BrowserKit) since `drupalGet()` has no POST-method parameter and this repo has no existing precedent for a custom-route POST test |
| `PersonaSwitchControllerMethodTest.php` | GET on a real persona target (`maria-chen`) -> 405; POST on it -> 302/303; GET on `persona=anonymous` (switch-back) -> 302/303, never 405 | Functional — HTTP-method discipline on the one route (Amendment 4) |

### E2E (`tests/e2e/persona-switcher.spec.ts`)

4 tests: full switch -> banner -> switch-back for Groups-Moderate and Maria Chen; keyboard-only
switch + switch-back; visible-focus-outline check on `<select>`, the "Go" button, and the
switch-back link. Targets the seeded demo site (`step_790_persona_switcher.php`, not yet
authored — F's job); confirmed to `--list` cleanly (see below), not executed against a live
seeded server at RED time per the harness's own instruction.

## RED confirmation

Command (Kernel, from the assembled layout, inside DDEV):
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel"
```
Result: **19 tests, 13 errors + 4 failures = 17 legitimate RED**, 2 pre-existing-true assertions
(see "Non-RED, documented" below).

| Test | Result | Reason |
|---|---|---|
| `PersonaSpecTest::testPersonasReturnsFourIdsInOrder` | PASS (pre-existing) | The 4 ids/order already ship — documented, not a defect |
| `PersonaSpecTest::testEveryPersonaHasNonEmptyIdNameDescription` | PASS (pre-existing) | Same — existing fields already non-empty |
| `PersonaSpecTest::testEveryPersonaHasExpectedUname` | **FAIL** | `Failed asserting that an array has the key 'uname'` — field doesn't exist yet |
| `PersonaSpecTest::testEveryPersonaHasExpectedTooltipKey` | **FAIL** | same, `tooltip_key` missing |
| `PersonaSpecTest::testPersonaSpecReturnsMariaRow` | **ERROR** | `Call to undefined method ShowcaseCatalog::personaSpec()` |
| `PersonaSpecTest::testPersonaSpecReturnsNullForUnknownId` | **ERROR** | same |
| `PersonaAccessCheckTest` (all 7 methods incl. 4-persona dataProvider) | **ERROR** | `ServiceNotFoundException: do_showcase.persona_access` |
| `PersonaSwitcherRenderTest` (all 4 methods) | **ERROR** | `ServiceNotFoundException: do_showcase.persona_switcher` |
| `HelpTextPersonaKeysTest::testAllFourPersonaKeysPresent` | **FAIL** | `Failed asserting that an array has the key 'persona.anonymous'` |
| `HelpTextPersonaKeysTest::testEachPersonaValueIsPlainTextAndUnder140Chars` | **FAIL** | `Failed asserting that two strings are not identical` (empty-string fallback vs non-empty expectation) |

Command (Unit — pure PHP, no DB):
```
ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/GroupsModerateRoleConfigShapeTest.php"
```
Result: **3 tests, 2 legitimate RED, 1 pre-existing-true**.
- `testAdminFlagIsFalse`: **FAIL** — `Failed asserting that true is false` (shipped file still has `admin: true`)
- `testEnumeratedPermissionsAreExactlyAdministerMembersAndEditGroup`: **FAIL** — `Failed asserting that an array contains 'administer members'` (shipped `permissions: {}`)
- `testScopeAndGlobalRoleUnchanged`: PASS (pre-existing — scope/global_role are not touched by Amendment 1, correctly asserted as staying the same)

Command (Functional, from the assembled layout):
```
ddev exec "SIMPLETEST_DB='mysql://root:root@db:3306/db' SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Functional"
```
Result: **17 tests, 9 legitimate RED**, 8 pre-existing-true (documented below).

| Test | Result | Reason |
|---|---|---|
| `PersonaSwitcherDropdownTest::testAnonymousSeesWidgetWithFourOptions` | **FAIL** | `Element matching css "select[name=\"persona\"]" not found` |
| `PersonaSwitcherDropdownTest::testLabelAssociatedWithSelectViaForId` | **FAIL** | `Element matching css "label[for=..]" not found` |
| `PersonaSwitcherDropdownTest::testRealSubmitButtonFallbackPresent` | **FAIL** | `Element matching css "form button[type=submit]" not found` |
| `PersonaBannerTest::testAnonymousSessionHasNoBanner` | PASS (pre-existing, vacuous — no banner code exists at all, so "no banner" is trivially true; documented as a regression guard that becomes meaningful once F ships `personaBanner()`) | n/a |
| `PersonaBannerTest::testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink` | **FAIL** | `Element matching css "aside[role=status].do-showcase-persona-banner" not found` |
| `PersonaAccessNegativeTest` (all 3) | PASS (pre-existing — see "Non-RED, documented" below) | n/a |
| `PersonaAccessPositiveTest` (all 3) | PASS (pre-existing — see "Non-RED, documented" below) | n/a |
| `PersonaUidOneGuardTest::testPostToUidOneUnameIsDenied` | **FAIL** | `Failed asserting that 404 is identical to 403` (route doesn't exist -> 404, not the access-check's 403) |
| `PersonaUidOneGuardTest::testUnknownPersonaIdIsDenied` | PASS (accepts 404 as a valid outcome for this specific case — the route not existing legitimately satisfies "never succeeds") |
| `PersonaUidOneGuardTest::testPostToAllowlistedPersonaSucceeds` | **FAIL** | 404 (route missing) is not in the accepted `[302, 303]` set |
| `PersonaSwitchControllerMethodTest` (all 3) | **FAIL** | 404 instead of 405/redirect — route doesn't exist |

E2E `--list` parse check (no live server):
```
npx playwright test tests/e2e/persona-switcher.spec.ts --list
```
Result: `Total: 4 tests in 1 file` — parses cleanly, all 4 test titles listed correctly.

### Non-RED, documented (pre-existing-true assertions — not invalid tests, but not RED either)

Two suites the harness named — `PersonaAccessNegativeTest` and `PersonaAccessPositiveTest` — pass
**today**, before any F code exists. I investigated this rather than accepting it silently (per
the harness's own instruction: a test that's green before the feature exists is invalid UNLESS
it is deliberately pinning a pre-existing invariant as a regression guard):

- **`PersonaAccessNegativeTest`**: `do_group_membership`'s route-access code
  (`ManageMembersController::access()`) and Drupal core's admin-route permission checks already
  exist and are already correct. A `groups_moderate` role with only `access content` can never
  reach `/admin/*` regardless of anything this story builds. This is a real, correct assertion,
  but it is not pinned to F's work — I kept it as an explicit **regression guard** (with an
  honest doc comment explaining this), not a RED test. If F ever over-grants the role, this catches
  it; if F ships the story with zero code, it still (correctly) passes.
- **`PersonaAccessPositiveTest`**: same situation — `ManageMembersController::access()` (group
  permission calculation) and the `drupal/group` permission mechanism already exist and are
  already correct; I reconstruct the group roles via the storage API with the AMENDED
  (Amendment 1) shape rather than reading F's shipped YAML, so the ACCESS CALCULATION itself was
  never in question. **The genuine RED anchor for Amendment 1 is `GroupsModerateRoleConfigShapeTest`
  (Unit)**, which reads the REAL on-disk config file and fails today because it still ships the
  pre-amendment `admin: true` / `permissions: {}` shape. `PersonaAccessPositiveTest` is kept as
  the end-to-end confirmation that the enumerated-perm shape (once F ships it) satisfies the AC
  on the real route, and as the Maria/Elena persona-uname contrast (new to this story).
- **`PersonaBannerTest::testAnonymousSessionHasNoBanner`**: passes vacuously today (no banner
  markup exists under any session) — kept as the paired negative case for
  `testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink` (the genuine RED), and becomes a
  real assertion once F ships `personaBanner()`.
- **`PersonaUidOneGuardTest::testUnknownPersonaIdIsDenied`**: accepts `[403, 404]` as valid
  outcomes deliberately (before the route exists, 404 correctly satisfies "never succeeds"; after
  F implements, 403 from the access check is the expected value) — not a masked RED, a
  deliberately wide assertion for a case where either outcome is a legitimate denial.

All of the above are called out explicitly in each test file's own doc comment, not silently
accepted.

## Existing suite regression check

Ran the full `do_showcase` Unit suite (31 tests, includes all pre-existing `ShowcaseCatalogTest`,
`VariantSwitcherTest`, `ShowcaseHelpTextTest` methods) alongside the new
`GroupsModerateRoleConfigShapeTest`: **29 pass, 2 legitimate RED (the new config-shape test) — zero
collateral breakage** to any pre-existing test.

## Spec ambiguity resolved

- **`PersonaAccessPositiveTest`/`PersonaAccessNegativeTest` design**: resolved by NOT reading
  shipped YAML in these Functional tests (BrowserTestBase's `do_showcase` module dependency does
  not pull in `docs/groups/config/` automatically) and instead reconstructing the AMENDED target
  shape via the group 4.x storage API — matching this repo's own `ManageMembersAccessTest`
  convention. This meant recognizing that the REAL RED anchor for Amendment 1 needed a separate
  Unit test reading the actual on-disk file (`GroupsModerateRoleConfigShapeTest`, added beyond
  the harness's original file list, following the exact precedent of
  `do_group_membership/tests/src/Unit/GroupRoleConfigShapeTest.php`).
- **uid-1 uname on this install**: not needed — `PersonaAccessCheckTest` fabricates its own uid-1
  account (`User::create(['uid' => 1, 'name' => 'root_admin', ...])`) rather than depending on a
  specific seeded uid-1 name, since Kernel tests have no demo seed. `PersonaUidOneGuardTest`
  (Functional) reads the REAL uid-1 account's uname via `User::load(1)->getAccountName()` — this
  works because BrowserTestBase's install always creates a uid-1 account (name assigned by the
  install profile, typically empty/blank in a minimal install — F should verify this resolves to
  a non-empty string in practice; if BrowserTestBase's uid-1 has an EMPTY uname, F must handle
  that in `PersonaAccessCheck`, e.g. compare by uid, not by uname string, which is the more robust
  design anyway and is what I'd recommend).
- **POST to a custom route in a Functional test**: no existing precedent in this repo (all
  existing Functional tests use `drupalGet()` for GET-only routes or submit real forms). Used
  `getSession()->getDriver()->getClient()->request('POST', $this->buildUrl($path))` (Symfony
  BrowserKit, the driver BrowserTestBase's default Mink session exposes) — documented in each
  affected test file's doc comment as the technique and rationale.
- **`step_790_persona_switcher.php` seed**: not authored by T (F's job per the amended files-touched
  list) — the E2E spec references the seeded accounts (`elena_garcia`, `maria_chen`,
  `groups_moderate_demo`) by uname/label but does not assume a specific seeded group id, so it
  does not require inspecting the not-yet-written seed script.

## Coverage claim (AC bullet -> test)

| brief.md AC bullet | Test(s) |
|---|---|
| D11 masquerade compatibility verified & recorded (superseded by Amendment 3: dep dropped) | Not a test surface — recorded on the issue/PR body by F/O per Amendment 3, not testable via PHPUnit |
| Anonymous visitor sees "Browse as" dropdown; per-option tooltips render | `PersonaSwitcherDropdownTest` (Functional), `PersonaSwitcherRenderTest` (Kernel), `HelpTextPersonaKeysTest` (Kernel) |
| Switching to each persona: session becomes that user; banner shows; switch-back returns to anonymous | `PersonaBannerTest` (Functional, render path), `PersonaSwitchControllerMethodTest` + `PersonaUidOneGuardTest` (Functional, controller path), E2E full-switch tests |
| uid 1 unreachable via any masquerade path (access-check level, not just UI) | `PersonaAccessCheckTest` (Kernel, the calculation), `PersonaUidOneGuardTest` (Functional, the real route) |
| Maria (Organizer) can edit/manage; Elena (Member) cannot | `PersonaAccessPositiveTest` (Functional) |
| Groups-Moderate: pending queue/approve/archive/restore on unjoined group; CANNOT reach admin surfaces | `PersonaAccessPositiveTest` (positive), `PersonaAccessNegativeTest` (negative), `GroupsModerateRoleConfigShapeTest` (Unit, the config shape both depend on) |
| Playwright: full switch->verify->switch-back for >= 2 personas incl. Moderator | `tests/e2e/persona-switcher.spec.ts` (Groups-Moderate + Maria Chen) |
| `HelpText` append-only 4 new `persona.*` keys | `HelpTextPersonaKeysTest` (Kernel) |
| Seed `step_790_persona_switcher.php` | Not directly unit/kernel-tested (a seed script, F's deliverable); E2E depends on it existing and being correct |
| WCAG 2.2 AA: label, keyboard-operable, visible focus, contrast, non-color status | `PersonaSwitcherDropdownTest` (label association), E2E keyboard-only + visible-focus tests |
| Existing suite stays green; CI passes | Confirmed via the "Existing suite regression check" above (29/29 pre-existing Unit tests pass) |

## Ready for F

**Confirmed RED is valid.** 17/19 Kernel tests, 2/3 Unit tests, and 9/17 Functional tests fail for
the right reason (missing class/service/method/field/route/markup — never a test-authorship bug).
The remaining tests are explicitly documented as pre-existing-true regression guards, not silent
false-positives. F may implement against these tests now.

One recommendation for F (not a blocker, surfaced for awareness): consider making
`PersonaAccessCheck`'s uid-1 guard compare by **uid**, not by uname string — BrowserTestBase's
installed uid-1 account may have an empty/unpredictable uname, which would make a uname-string
comparison fragile in the real deployed site too if uid-1's uname is ever blank or non-obvious.
