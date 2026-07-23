# Handoff-F: Phase 5 - #120 SC-1 Persona Switcher

**Date:** 2026-07-23
**Branch:** 120-persona-switcher
**Issue:** #120

## What was done

New files (all under `docs/groups/`):
- `modules/do_showcase/src/Persona/PersonaSwitcher.php` — the "Browse as" widget render-array
  builder service (`do_showcase.persona_switcher`).
- `modules/do_showcase/src/Access/PersonaAccessCheck.php` — route-level `access_check`-tagged
  service (`do_showcase.persona_access`) enforcing the uid-1 guard + persona allowlist.
- `modules/do_showcase/src/Controller/PersonaSwitchController.php` — the
  `do_showcase.persona_switch` route callback (logout+login flow, no masquerade dep).
- `modules/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php` — optional, explicitly-placeable
  Block-plugin wrapper around the same `PersonaSwitcher::build()` (id `persona_switcher`).
- `modules/do_showcase/css/persona-switcher.css` — widget + banner styling (`#4da3ff` focus token,
  ribbon-family dark/light banner contrast pairing).
- `scripts/step_790_persona_switcher.php` — seed: `groups_moderate` role + `groups_moderate_demo`
  account, grants Maria the Organizer group role on her existing seeded group, creates one pending
  `group_relationship` via `GroupMembershipManager::STATUS_PENDING`. Idempotent (verified — see
  below).

Edited files:
- `modules/do_showcase/src/ShowcaseCatalog.php` — extended `personas()` with `uname` +
  `tooltip_key` per entry; added `personaSpec(string $id): ?array`.
- `modules/do_showcase/src/Hook/DoShowcaseHooks.php` — added a constructor (`PersonaSwitcher`
  injected) + TWO sibling `#[Hook('page_top')]` methods: `personaSwitcherWidget()` (renders the
  widget everywhere, no Block-placement dependency) and `personaBanner()` (renders the active-
  persona banner). `pageTop()` (ribbon) is completely untouched.
- `modules/do_showcase/do_showcase.routing.yml` — added `do_showcase.persona_switch` at
  `/persona-switch/{persona}`, `methods: [GET, POST]`, `requirements: {_persona_access: 'TRUE'}`.
- `modules/do_showcase/do_showcase.services.yml` — registered `do_showcase.showcase_catalog`,
  `do_showcase.persona_switcher`, `do_showcase.persona_access` (tagged `access_check`,
  `applies_to: '_persona_access'`), a `PersonaSwitcher` class-name alias (needed for `#[Hook]`
  auto-discovery's own autowiring — see Design decisions), and updated `do_showcase.hooks`'
  `arguments:`.
- `modules/do_showcase/do_showcase.info.yml` — added `drupal:block` + `do_chrome:do_chrome`
  dependencies (the latter was a real pre-existing gap from #119 — see Design decisions).
- `modules/do_showcase/do_showcase.libraries.yml` — added the `persona-switcher` CSS-only library.
- `modules/do_chrome/src/HelpText.php` — **appended only** (git diff: 16 insertions, 0 deletions)
  4 new `persona.*` keys (62/116/97/127 chars — all ≤ 140).
- `config/group.role.community_group-groups_moderate.yml` — `admin: true` → `admin: false`;
  `permissions: {}` → `['administer members', 'edit group']` (the third "archive perm" bullet
  from Amendment 1 was dropped — confirmed redundant by reading `RestoreGroupAccess::access`,
  which only checks `edit group` or `administer group`, per A's own non-blocking note).
- `config/user.role.groups_moderate.yml` — `permissions: {}` → `['access content']`.

## Design decisions

1. **Widget renders via a THIRD sibling `page_top` hook, not solely via the Block plugin.**
   `PersonaSwitcherDropdownTest` (`$modules = ['do_showcase']`, no config import) requires the
   widget on `<front>` from module-enable alone. No module in this repo self-installs
   `block.block.*` placement via `config/install/`, so a Block-plugin-only path can never satisfy
   that test (or a real deployment without manual Block Layout placement). Discovered via the RED
   loop: the widget rendered fine at the Kernel-service level but was absent from the actual page
   until this hook was added. `PersonaSwitcherBlock` still ships as an optional, explicitly-
   placeable alternative — both consumers share the ONE `do_showcase.persona_switcher` service.

2. **`Markup::create()` wraps both the widget's `<form>` and the banner's `<aside>`.** Drupal's
   renderer XSS-filters raw `#markup` against `Xss::getAdminTagList()` by default, which does not
   include `form`/`select`/`option`/`button`/`aside` — every one of those tags was silently
   stripped until fixed (surfaced as a real bug during the Kernel-render test: markup rendered as
   bare text with no `<select>` at all). This is the exact gotcha
   `RestoreGroupForm::preRenderAsButtonTag()` already documents in this repo for its own real
   `<button>`. Every dynamic value is `htmlspecialchars()`-escaped (widget) or run through
   `Attribute`/`renderInIsolation()` (banner) before assembly, so both strings are safe.

3. **`'#type' => 'container'` cannot produce `PersonaBannerTest`'s required
   `aside[role="status"]`** — `container.html.twig` hardcodes `<div>` regardless of `#attributes`.
   Fixed by pre-rendering the banner's children (glyph/text/link) via `renderInIsolation()`, then
   hand-assembling `<aside {Attribute}>{children}</aside>` wrapped in `Markup::create()`.

4. **`DoShowcaseHooks` needed a constructor + a class-name service alias.** Drupal's `#[Hook]`
   attribute discovery (`HookCollectorPass`) auto-registers any Hook-namespaced class as ITS OWN
   autowired service — this resolves constructor arguments by class name, not by reading my
   explicit `arguments:` list in `services.yml`. Since `PersonaSwitcher` had no class-name alias,
   autowiring threw "Cannot autowire service ... references class ... but no such service exists."
   Fixed by adding `Drupal\do_showcase\Persona\PersonaSwitcher: '@do_showcase.persona_switcher'`,
   matching core's own pattern (`Drupal\Core\Entity\EntityTypeManagerInterface: '@entity_type.manager'`
   in `core.services.yml`).

5. **uid-1 guard compares by resolved account `id()`, not uname string** — per T's own
   recommendation in `handoff-T-red.md` (BrowserTestBase's installed uid-1 uname is unpredictable).
   `PersonaAccessCheck` loads the target persona's `uname`, and if that resolves to an existing
   user whose `(int) $user->id() === 1`, denies unconditionally — defense-in-depth layered on top
   of (not instead of) the allowlist check, which is what actually catches T's
   `testUidOneTargetIsAlwaysDenied` (the fabricated uid-1 uname `root_admin` isn't an allowlisted
   persona id at all, so the allowlist alone already denies it; the uid-comparison guards against a
   *future* allowlist edit that might accidentally point a persona at uid 1).

6. **`do_showcase.info.yml` needed a `do_chrome:do_chrome` dependency I found missing.** This is a
   pre-existing gap from #119 (`VariantSwitcher.php` already calls `Drupal\do_chrome\HelpText`
   with no formal `.info.yml` dependency declared) that never surfaced before because no prior test
   exercised `do_showcase`-only-enabled code calling into `HelpText` inside an isolated
   BrowserTestBase install. My `PersonaSwitcher::build()` was the first to do so on `<front>`,
   producing a real 500 (`Class "Drupal\do_chrome\HelpText" not found`) until fixed. Not a
   drive-by fix of unrelated code — this is a load-bearing dependency for the very code I wrote,
   surfaced by my own new test-exercised path.

7. **Form action + no-JS fallback:** the `<select>`'s form action starts pointing at the
   CURRENTLY-selected persona's own `/persona-switch/<id>` path (always a safe, valid, self-
   consistent default per `PersonaAccessCheck`'s allowlist); an inline `onchange` handler
   (`this.form.action='/persona-switch/'+encodeURIComponent(this.value);this.form.submit();`)
   rewrites the action to the newly-picked option and submits. This needs no external JS file for
   the auto-submit behavior itself (only the CSS library is external). A real
   `<button type="submit">Go</button>` (never `#type => submit`, which renders `<input>`) is the
   visible no-JS fallback, matching the wireframe and the PROJECT_CONTEXT.md gotcha.

## Reuse / extend-vs-new

Extended `ShowcaseCatalog::personas()` (the brief's own Reuse map target, per Amendment 2) rather
than creating a parallel `PersonaRegistry` — added `uname`/`tooltip_key` fields plus
`personaSpec()`, consumed by all three new classes (`PersonaSwitcher`, `PersonaAccessCheck`,
`PersonaSwitchController`) via the same single lookup. `PersonaSwitcher` follows
`VariantSwitcher`'s exact service shape (`autowire: false`, `StringTranslationTrait` via `calls:`).
`PersonaSwitcherBlock` follows `GroupMissionBlock`'s exact `@Block` annotation convention.
`PersonaAccessCheck` follows core's own `access_check` tag shape. No new parallel object created
anywhere the brief named an extension target.

## Architecture notes for A

- **Layers touched:** persona render-array service (new), route access-check service (new,
  `access_check` tag), controller (new), Block plugin (new, optional), hook-implementation class
  (extended with 2 new sibling methods + a constructor), config-shape edits (2 role YAMLs), append-
  only copy store (`HelpText.php`), a new seed script.
- **New service dependency:** `do_showcase.showcase_catalog` — `ShowcaseCatalog` was previously
  only ever `new`'d directly by `ShowcaseController::create()`; now also registered as a shared
  service so `PersonaSwitcher`/`PersonaAccessCheck` inject the SAME instance rather than each
  `new`-ing their own copy. `ShowcaseController` itself is unchanged (still `new ShowcaseCatalog()`
  directly — not touched, out of this story's scope).
- **Schema/contract changes:** none (no new fields; reuses `field_membership_status` from #138).
- **Shared components changed:** `HelpText.php` (append-only, per its own contract),
  `do_showcase.info.yml` dependencies. Neither is another agent's exclusive territory per the
  epic's own "help/tooltips ship WITH the feature" rule.
- **Cache contexts:** both the widget (`PersonaSwitcher::build()`) and the banner
  (`personaBanner()`) declare `#cache['contexts'] => ['user']`, asserted by T's Kernel test and
  confirmed correct end-to-end (Elena's Functional-test session shows Elena's banner, not a stale
  one).

## Deviations from spec / wireframe

- **No `templates/persona-banner.html.twig` file** — the banner is hand-assembled via
  `Markup::create()` + pre-rendered children (see Design decision #3) rather than a Twig theme
  hook, because `'#type' => 'container'` cannot produce the required `<aside>` tag and a Twig
  template would need the same pre-render-then-wrap technique anyway for no net simplification.
  Functionally identical output; if a Twig-template form is preferred, it is a mechanical follow-up
  (extract the current inline-HTML assembly into a `.html.twig` + `hook_theme()` entry).
- **Route action-URL mechanism**: per the brief's own "pick whichever works AND passes
  `PersonaSwitchControllerMethodTest`" instruction — implemented via inline `onchange` JS
  rewriting `form.action` per-selection (see Design decision #7), rather than a static
  switch-endpoint-reads-POST-body design. Satisfies both the direct-POST Functional tests (which
  hit `/persona-switch/{persona}` directly) and the E2E spec's `selectOption()` flow.
- **Third "archive perm" bullet dropped from Amendment 1's group-role edit** — confirmed redundant
  by reading `RestoreGroupAccess::access` (only `edit group` or `administer group` gate restore),
  matching A's own non-blocking note. `group.role.community_group-groups_moderate.yml` ships
  exactly `['administer members', 'edit group']`.
- **`composer.json`/`composer.lock`/`core.extension.yml` left untouched** — per the brief's own
  "simplest: leave alone" branch. Verified `core.extension.yml` is a generated file (not tracked
  under `docs/groups/config/`) and its assembled copy already carries no `masquerade` line, so
  both of Amendment 3's file-touch items resolve to a true no-op.

## Tier 1 self-check (incl. tests now GREEN)

All commands run from the assembled layout inside DDEV (`gm120-groups-on-d11`), after
`bash scripts/ci/assemble-config.sh`.

**Kernel (do_showcase-scoped, the 4 files this issue names):**
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_showcase/tests/src/Kernel
```
Result: **18/19 pass** — every test GREEN except `PersonaSpecTest::testEveryPersonaHasExpectedUname`,
which is a confirmed test-authorship bug (see "Tests that look wrong" below), NOT a defect in
`ShowcaseCatalog`.

**Unit:**
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/
```
Result: **31/31 pass** (includes the 3 `GroupsModerateRoleConfigShapeTest` methods, now GREEN
against the amended `group.role.community_group-groups_moderate.yml`, plus all 28 pre-existing
`ShowcaseCatalogTest`/`ShowcaseHelpTextTest`/`VariantSwitcherTest` methods, zero regressions).

**Functional:**
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Functional
```
Result: **14/17 pass.** The 3 failures are ALL the same confirmed test-authorship bug (missing
`$client->followRedirects(false)` — see below), verified via a scratch (uncommitted) diagnostic
that my controller's actual HTTP behavior is a genuine 302 in every case.

**Existing suite regression check (full custom-module Kernel suite):**
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  $(find web/modules/custom -type d -path '*/tests/src/Kernel')
```
Result: **122/123 pass** (the 1 failure is the same `PersonaSpecTest` bug counted once above; every
other custom module's suite — `do_group_extras`, `do_group_membership`, `do_group_pin`,
`do_multigroup`, `do_notifications`, `do_discovery`, `do_streams`, `do_tests`, etc. — is
unchanged, zero collateral breakage). Ran this full check 4 times across the fix loop; identical
result each time.

**Seed script (`step_790_persona_switcher.php`) verified via a scratch (uncommitted) Functional
diagnostic** (not part of the committed test suite — T authors any permanent seed-verification
test): confirmed it runs with zero PHP errors, creates `groups_moderate_demo` with the
`groups_moderate` role, grants Maria the Organizer group role on "DrupalCon Portland 2026",
creates the pending `group_relationship` on "Core Committers", AND is idempotent (a second
`require` of the same script hit its "Exists" branches with no error or duplication).

**E2E `--list` parse check:**
```
npx playwright test tests/e2e/persona-switcher.spec.ts --list
```
Result: `Total: 4 tests in 1 file` — unchanged, parses cleanly.

**Lint:**
```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  web/modules/custom/do_showcase/src/Persona/ \
  web/modules/custom/do_showcase/src/Controller/PersonaSwitchController.php \
  web/modules/custom/do_showcase/src/Access/PersonaAccessCheck.php \
  web/modules/custom/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php \
  web/modules/custom/do_showcase/src/Hook/DoShowcaseHooks.php \
  web/modules/custom/do_showcase/src/ShowcaseCatalog.php \
  web/modules/custom/do_chrome/src/HelpText.php \
  docs/groups/scripts/step_790_persona_switcher.php
```
Result: **0 errors, 0 warnings** on every file I authored/edited EXCEPT:
- `DoShowcaseHooks.php`: 0 errors, 11 `DrupalPractice` WARNINGS (raw `t()` / `\Drupal::` calls) —
  all inside my new `personaSwitcherWidget()`/`personaBanner()` methods, but matching the EXACT
  pre-existing style of the untouched `pageTop()` method in the same class (which already uses raw
  `t()`, no `StringTranslationTrait`, no DI). Verified via `git diff` line-mapping that this is
  stylistic consistency with the class's established convention, not new debt introduced by a
  different pattern.
- `HelpText.php`: 18 errors + 8 warnings — **100% pre-existing**, confirmed via `git diff --stat`
  (16 insertions, 0 deletions — append-only) and manual line-mapping: every flagged line (21, 43,
  57, 76, 101, 105, 110-120, 125, 128-139, 161) is in code from #119/#122, entirely outside my
  4-key append at the file's end. My own added lines are individually lint-clean.

## Tests that look wrong (for T)

1. **`PersonaSpecTest::testEveryPersonaHasExpectedUname`** (`docs/groups/modules/do_showcase/tests/src/Kernel/PersonaSpecTest.php:102`):
   `self::EXPECTED_UNAME[$persona['id']] ?? '__unexpected__'` — PHP's `??` treats an array key
   whose VALUE is `NULL` as if the key were absent (verified via a direct PHP repro:
   `['anonymous' => NULL]['anonymous'] ?? 'x'` returns `'x'`, not `NULL`). Since
   `EXPECTED_UNAME['anonymous'] = NULL` is itself the CORRECT expected value (per Amendment 2:
   "uname (NULL for anonymous...)"), this expression always resolves to the `'__unexpected__'`
   fallback for the anonymous case, regardless of what `ShowcaseCatalog::personas()` actually
   returns. Confirmed my implementation ships `'uname' => NULL` for `anonymous` exactly as spec'd
   (verified via a source-string grep, independent of the test). **Suggested fix:** index directly
   (`self::EXPECTED_UNAME[$persona['id']]`, since every real persona id from `personas()` is
   guaranteed present in the map) or use `array_key_exists()` instead of `??`.

2. **`PersonaSwitchControllerMethodTest::testPostOnNonAnonymousPersonaRedirects`** and
   **`::testGetOnAnonymousRedirects`**, plus **`PersonaUidOneGuardTest::testPostToAllowlistedPersonaSucceeds`**
   (via its shared `postAndGetStatus()` helper): all three assert a `[302, 303]` status via
   `$client->getInternalResponse()->getStatusCode()` on a request issued through
   `$this->getSession()->getDriver()->getClient()`, WITHOUT first calling
   `$client->followRedirects(false)`. Mink's `BrowserKitDriver` sets
   `$this->client->followRedirects(true)` on that SAME client instance by default (verified:
   `vendor/behat/mink-browserkit-driver/src/BrowserKitDriver.php:64`), so the client transparently
   follows the 302 to its destination BEFORE `getInternalResponse()` is populated — the test
   observes the FINAL page's 200, never the redirect's own 302, no matter what the controller
   actually returns. Verified my controller's real behavior is correct: a scratch (uncommitted)
   diagnostic that called `$client->followRedirects(false);` before the exact same requests these
   tests make confirmed a genuine `status=302` in both the GET-anonymous and POST-maria-chen
   cases, with correct `Location` headers. **Suggested fix:** add
   `$client->followRedirects(false);` immediately after obtaining the client, before `->request()`,
   in all three places.

Did not edit any of these — flagging per the pipeline contract for T to fix in Phase 6.

## Known issues

- **CI wiring gap (out of my edit scope):** `.github/workflows/test.yml`'s E2E job's `drush en`
  list and its 3 named seed-script invocations do not yet include `step_790_persona_switcher.php`.
  `do_showcase`/`do_chrome` DO get enabled via the earlier `config:import` step (assemble-config.sh
  patches every custom module into the assembled `core.extension.yml`), so the E2E job's modules
  should already be correctly enabled — but the E2E spec's dependency on `groups_moderate_demo` +
  Maria's Organizer-role grant + the pending relationship will not exist until `step_790` is
  invoked alongside the other 3 named scripts. This file lives outside `docs/groups/` (a
  `.github/workflows/` infrastructure file), so it is out of my edit scope per the pipeline's
  file-ownership rule — flagged for O/T to add the invocation.
- Everything else described above (the 2 flagged tests) is a test-authorship issue, not a
  production-code defect.

## Files changed

New:
- `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php`
- `docs/groups/modules/do_showcase/src/Access/PersonaAccessCheck.php`
- `docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php`
- `docs/groups/modules/do_showcase/src/Plugin/Block/PersonaSwitcherBlock.php`
- `docs/groups/modules/do_showcase/css/persona-switcher.css`
- `docs/groups/scripts/step_790_persona_switcher.php`

Edited:
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`
- `docs/groups/modules/do_showcase/do_showcase.routing.yml`
- `docs/groups/modules/do_showcase/do_showcase.services.yml`
- `docs/groups/modules/do_showcase/do_showcase.info.yml`
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml`
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `docs/groups/config/group.role.community_group-groups_moderate.yml`
- `docs/groups/config/user.role.groups_moderate.yml`

No test files touched (T's territory). No `web/modules/custom/` or `config/sync/` artifacts staged
(gitignored, regenerated by `assemble-config.sh`).
