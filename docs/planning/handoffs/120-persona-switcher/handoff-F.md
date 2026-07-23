# Handoff-F: Phase [N] - #120 SC-1 Persona Switcher

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

---

## Phase 5-fix (2026-07-23): personaBanner label bug repair

**Bug:** `DoShowcaseHooks::personaBanner()` rendered the Groups-Moderate persona's banner as
"You're browsing as Moderator — switch back" instead of the wireframe/AC-locked "You're browsing
as Groups-Moderate — switch back", because it read `$active_persona['name']` (`'Moderator'`)
directly instead of sharing the display-string logic `PersonaSwitcher::optionLabel()` already
independently hardcoded (`'Groups-Moderate'` for the same persona id) — two divergent sources for
one visible copy. Caught by `tests/e2e/persona-switcher.spec.ts`'s Groups-Moderate full-switch
test (`toContainText("You're browsing as Groups-Moderate — switch back")`).

**Option chosen: B** (add a `'label'` field to `ShowcaseCatalog::personas()`), not A (a new
`ShowcaseCatalog::personaLabel()` method). Rationale: `ShowcaseCatalogTest::
testPersonaSwitcherEntryNamesAllFourPersonas()` — a pre-existing, currently-GREEN Unit test —
already pins `$p['name']` to the plain persona name (`'Moderator'`), and
`ShowcaseController::build()`'s `/showcase` tour listing (`@name — @description`) is a fourth,
independent consumer that genuinely needs that same plain `name`, not the display label. `name`
could not be repurposed without breaking both. A new fifth field, data-driven exactly like this
method's existing `name`/`description` per-entry fields, is the minimal additive fix and avoids
adding a second method whose `match` logic could diverge from the first all over again (the exact
failure mode that created this bug in the first place).

**Files edited** (all under `docs/groups/`, no test files):
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — added `'label' => (string) $this->t(...)`
  to each of the 4 `personas()` entries; updated the `@return` array-shape docblocks on `personas()`
  and `personaSpec()` to include `label: string`.
- `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php` — `optionLabel()` reduced to
  `return $persona['label'];` (was an independent `match ($persona['id'])` hardcoding the same 4
  strings); updated its `@param` docblock and `resolveCurrentPersonaId()`'s `@param` docblock to
  include `label: string`.
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — `personaBanner()`'s `$lead_text`
  now reads `$active_persona['label']` directly (was `$active_persona['name']` plus an independent
  `$role_suffix = match ($active_persona['id']) {...}` table, which is dropped entirely — `label`
  already carries the role suffix for Elena/Maria and reads correctly as-is for Moderator/
  Anonymous). Also fixed a `phpcs` "avoid backslash escaping" warning introduced by the initial
  edit: switched the `t()` call from single-quoted (`'You\'re browsing as @label — '`) to
  double-quoted (`"You're browsing as @label — "`), matching both `phpcs`'s recommendation and the
  pre-fix code's own quoting convention.

**Verify:**

Kernel + Unit (do_showcase-scoped, the exact command from the task):
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_showcase/tests/src/Kernel web/modules/custom/do_showcase/tests/src/Unit
```
Result: **50/50 pass** (19/19 Kernel + 31/31 Unit) — exit code 0, "OK, but there were issues!" (2
pre-existing deprecation notices only — Kernel test process-isolation / `installSchema('sequences')`
deprecations, both present before this fix, unrelated to it). Zero failures. Includes
`ShowcaseCatalogTest::testPersonaSwitcherEntryNamesAllFourPersonas` (confirms `name` still reads
`'Moderator'`, untouched) and all 6 `PersonaSpecTest` methods (confirms the additive `label` field
does not break the existing `personas()`/`personaSpec()` shape assertions).

Functional:
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' \
  SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Functional
```
Result: **17/17 pass** — exit code 0. Includes
`PersonaBannerTest::testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink`, which exercises the
exact `personaBanner()` code path this fix changed.

Full custom-module Kernel regression (re-run for safety, matching the prior handoff-F's own
baseline check):
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  $(find web/modules/custom -type d -path '*/tests/src/Kernel')
```
Result: **123/123 pass** (the previously-flagged `PersonaSpecTest` uname bug is now fixed upstream
by T's Phase 6 — this full suite is 100% green, zero regressions anywhere in the codebase).

E2E parse check:
```
npx playwright test tests/e2e/persona-switcher.spec.ts --list
```
Result: `Total: 4 tests in 1 file` — unchanged, parses cleanly. (Full E2E execution against the
seeded site, including the Groups-Moderate banner-copy assertion this fix targets, is T's job in
the next verify pass, per the task's own instruction.)

Lint:
```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_showcase/src/ShowcaseCatalog.php \
  docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php \
  docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php
```
(Run against the assembled `web/modules/custom/do_showcase/...` paths inside DDEV, which is what
`phpcs` actually lints.) Result: **0 errors** on all 3 files.
`ShowcaseCatalog.php` and `PersonaSwitcher.php`: 0 warnings. `DoShowcaseHooks.php`: 9
`DrupalPractice` warnings (raw `t()`/`\Drupal::` calls, no DI/`StringTranslationTrait`) — confirmed
via a before/after diff (linted a scratch copy of the pre-fix file inside DDEV) that these are
1-for-1 pre-existing, present in the original file in the same established style as the untouched
`pageTop()` method; not new debt from this fix. The one warning this fix's initial pass DID
introduce (backslash-escaping in a translatable string) was found and fixed during this same pass
(see "Files edited" above) — confirmed by re-lint (10 warnings → 9, matching the pre-fix baseline
exactly).

**Staged** (by explicit path, no test files, no `git add .`):
`docs/groups/modules/do_showcase/src/ShowcaseCatalog.php`,
`docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`,
`docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php`. Not committed (F does not
commit/push/PR).

## Phase 6.5 (2026-07-23): diff-gate BLOCK repair

o4-mini's diff review (`docs/planning/handoffs/120-persona-switcher/diff-review-o4mini.md`)
returned 3 BLOCK findings. All 3 fixed; all 3 WARN findings (W-1 inline-onchange/CSP, W-2
`user_logout()` deprecation, W-3 `\Drupal::service` statics) deliberately left as-is per the task's
own explicit "do NOT fix in this pass" instruction.

- **B-1 (hardcoded `/persona-switch/` URLs)** — `src/Persona/PersonaSwitcher.php`. Both the
  form's initial `action` and the JS-usable base path the `onchange` handler concatenates onto are
  now generated from `Url::fromRoute('do_showcase.persona_switch', [...])`, never a hand-written
  literal. The initial `action` is a direct per-selection `Url::fromRoute(...)->toString()` call.
  The JS-usable **prefix** is derived by generating the SAME route's URL for a sentinel persona id
  (`self::PERSONA_ID_SENTINEL = '__PERSONA_ID_SENTINEL__'` — all-uppercase/underscores, so it can
  never collide with a real allowlisted persona id, and contains no character the URL generator or
  `rawurlencode()` would alter) and stripping the sentinel back out of the generated string via
  `str_replace()`. Manually verified via a `drush php:script` scratch check (not committed) that
  this produces the exact same real-world URLs as the old hardcoded literal on this site's config
  (`/persona-switch/elena-garcia`, prefix `/persona-switch/`), while now correctly adapting on a
  subdirectory/language-prefix/path-alias install where the hardcoded literal would have silently
  pointed at the wrong path. Confirmed the actual rendered `<form>`/`onchange` markup via `curl`
  against the live DDEV front page — byte-identical shape to the pre-fix hardcoded version.

- **B-2 (open redirect in `redirectBack()`)** — `src/Controller/PersonaSwitchController.php`.
  `redirectBack()` no longer trusts the raw `Referer` header unconditionally. Added a private
  `isSameOriginReferer(string $referer, Request $request): bool` helper that parses the Referer's
  scheme/host/port via `parse_url()` and compares each component against the CURRENT request's own
  `getScheme()`/`getHost()`/`getPort()` (with default-port normalization — 80 for http, 443 for
  https — so an implicit vs. explicit default port on either side still compares equal). An
  off-site, malformed, or scheme/host-mismatched Referer now falls back to `<front>` instead of
  being followed, closing the open-redirect vector. Compared by parsed components rather than a
  string-prefix/`str_starts_with()` check specifically so a Referer like
  `https://example.com.attacker.test/` (which merely starts with the real host as a substring)
  is correctly rejected, not accidentally trusted.

- **B-3 (`personaBanner()` does `new ShowcaseCatalog()`)** — `src/Hook/DoShowcaseHooks.php` +
  `do_showcase.services.yml`. Added `ShowcaseCatalog $catalog` as a second constructor-promoted
  parameter on `DoShowcaseHooks`, threaded the same way `PersonaSwitcher $personaSwitcher` already
  is. `personaBanner()` now reads `$this->catalog->personas()` instead of instantiating its own
  throwaway `ShowcaseCatalog`. Inspected the sibling `personaSwitcherWidget()` method per the
  task's instruction — it already delegates entirely to `$this->personaSwitcher->build()` with no
  `new` anywhere, so no further change was needed there. `do_showcase.services.yml`'s
  `do_showcase.hooks` entry gained a second argument (`@do_showcase.showcase_catalog`). Because
  `DoShowcaseHooks` is auto-registered as an AUTOWIRED service by Drupal's `#[Hook]` attribute
  discovery (which resolves constructor args by CLASS NAME, not by this file's `arguments:` list —
  the exact issue the pre-existing `PersonaSwitcher` class-name alias already documents), a second
  class-name alias (`Drupal\do_showcase\ShowcaseCatalog: '@do_showcase.showcase_catalog'`) was
  added alongside the existing `PersonaSwitcher` one, for the identical reason.

**Verify:**

Kernel + Unit (do_showcase-scoped):
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_showcase/tests/src/Kernel web/modules/custom/do_showcase/tests/src/Unit
```
Result: **50/50 pass** (19/19 Kernel + 31/31 Unit) — exit code 0, "OK, but there were issues!" (2
pre-existing deprecation notices only, unchanged from every prior phase's baseline). Zero failures.

Functional:
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' \
  SIMPLETEST_BASE_URL='http://gm120-groups-on-d11.ddev.site' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Functional
```
Result: **17/17 pass** — exit code 0. Confirmed via full-output grep that zero "Failed asserting" /
"FAILURES!" / "ERRORS!" strings appear anywhere in the run; the progress line reads
`17 / 17 (100%)`. `PersonaBannerTest` (asserts `a[href="/persona-switch/anonymous"]` for the
switch-back link) and the three redirect-status tests
(`PersonaSwitchControllerMethodTest`/`PersonaUidOneGuardTest`) all still pass unchanged — none of
them assert the exact `<form action>` string or the exact redirect Location, only status codes and
the (already-`Url::fromRoute`-generated, untouched-by-this-pass) banner switch-back `href`.

Full custom-module Kernel regression:
```
SIMPLETEST_DB='mysql://root:root@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  $(find web/modules/custom -type d -path '*/tests/src/Kernel')
```
Result: **123/123 pass**, zero failures/errors (confirmed via grep — 0 occurrences of "Failed
asserting"/"FAILURES!"/"ERRORS!"; progress line `123 / 123 (100%)`, all markers `D` (deprecation)
or `.` (pass), none `F`/`E`).

Lint:
```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php \
  docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php \
  docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php
```
Result: **0 errors** on all 3 files. `PersonaSwitcher.php` and `PersonaSwitchController.php`:
exit code 0, zero output — 0 errors, 0 warnings. `DoShowcaseHooks.php`: 0 errors, 9
`DrupalPractice` warnings (raw `t()`/`\Drupal::` calls) — confirmed via a before/after lint
comparison (linted a temporary renamed copy of the pre-Phase-6.5 file inside DDEV, then deleted it)
that these are the SAME 9 pre-existing warnings at the same lines, present before this pass;
Phase 6.5 added zero new lint findings to this file. Matches the task's own W-3 deferral note
("matches pre-existing pageTop() convention; deferred").

**Manual URL-generation spot check** (via a scratch, uncommitted `drush php:script`, deleted after
use): confirmed `Url::fromRoute('do_showcase.persona_switch', ['persona' => 'elena-garcia'])` →
`/persona-switch/elena-garcia`; the sentinel-strip prefix technique → `/persona-switch/`; and
`curl`-ing the live front page confirmed the rendered `<form action="/persona-switch/anonymous">`
and `onchange="this.form.action='/persona-switch/'+encodeURIComponent(this.value);this.form.submit();"`
are byte-identical in shape to the pre-fix hardcoded version (only the *mechanism* generating that
string changed, not its value on this site's config).

**Staged** (by explicit path, no test files, no `git add .`):
`docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php`,
`docs/groups/modules/do_showcase/src/Controller/PersonaSwitchController.php`,
`docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`,
`docs/groups/modules/do_showcase/do_showcase.services.yml`. Not committed (F does not
commit/push/PR).
