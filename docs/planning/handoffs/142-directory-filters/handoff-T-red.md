# Handoff-T-red: Phase 4 - #142 MC-3 Directory Location + Primary-language Filters

**Date:** 2026-07-23
**Branch:** 142-directory-filters
**Brief / wireframe reviewed:** `docs/planning/handoffs/142-directory-filters/brief.md` (v2), `survey.md`, `decisions.md`, `handoff-A-plan-r2.md` (D phase N/A per brief)

## A precondition
Confirmed: A returned **PASS** on the plan (Phase 3, r2) — `handoff-A-plan-r2.md` line 9. All r1 BLOCK/WARN findings resolved (field rename to `field_group_location_text`, `field_group_language` reuse, `plugin_id: language` pinned, anonymous kernel-test requirement). Proceeding to author tests.

## Tests authored

### 1. Kernel — `docs/groups/modules/do_tests/tests/src/Kernel/DirectoryFiltersTest.php`
Extends `GroupsKernelTestBase` (mirrors `AccessPolicyEnforcementTest` for anonymous-session discipline and `do_group_pin`'s `PinnedStreamOrderingTest` for the "install a views.view.* config that lives outside any module's `config/install`, from a **module-local fixture** in `tests/fixtures/config/`" pattern — `views.view.all_groups.yml` is not shipped by any module).

| Test | Criterion pinned | Tier | Why this tier |
|---|---|---|---|
| `testViewDeclaresBothExposedFilters` | `views.view.all_groups.yml` declares an exposed `field_group_language` filter (`plugin_id: language`) AND an exposed `location` filter (`field_group_location_text`, `operator: contains`) | Kernel (config inspection) | Cheapest sufficient tier — this is a static config-shape assertion, no query execution needed. |
| `testExposedFormIsNonEmpty` | The view has at least one non-empty `exposed_form` section, and specifically ≥3 exposed filter widgets (pre-existing `search` + the 2 new ones) | Kernel (`ViewExecutable::initHandlers()` + `isExposed()`) | Coarse structural check distinct from the per-filter shape assertion above — catches "filter declared but not actually exposed at handler-init time." |
| `testLocationTextFieldIsAttachedToGroupBundle` | New `field_group_location_text` field storage + instance exist on `group.community_group` | Kernel (entity storage load) | Direct API check, cheapest possible tier. |
| `testAnonymousExecutionExcludesArchivedGroup` | Kernel test executes the view as **anonymous** (never UID 1) and asserts an archived/unpublished group is excluded from the base result set, while 3 published groups (varying location + language) remain visible | Kernel (real view execution against a live DB) | Access-control correctness requires exercising Group's real query-alter/permission pipeline — not mockable at a lower tier; this is exactly the layer choice `PinnedStreamOrderingTest` and `AccessPolicyEnforcementTest` use for the same class of risk. |

**Access-safety note:** the base `GroupsKernelTestBase` group type carries no synchronized roles by default (it constructs `community_group` via the storage API, not by reading assembled config), so an anonymous session would see *zero* groups regardless of archived/published status — a false-positive risk for the exclusion assertion. Setup now grants an OUTSIDER-scope `view group` permission to the ANONYMOUS role (mirroring `PinnedStreamOrderingTest::setUp()`'s identical need for the OUTSIDER-scope grant on stream content), so the archived-exclusion assertion is isolated from "anonymous can see nothing at all."

**Fixtures authored (T's own best-effort, in `docs/groups/modules/do_tests/tests/fixtures/config/`):**
- `field.storage.group.field_group_location_text.yml` — string field storage, mirrors `field.storage.group.field_group_description.yml`'s shape.
- `field.field.group.community_group.field_group_location_text.yml` — instance, mirrors `field.field.group.community_group.field_group_description.yml`'s shape.
- `views.view.all_groups.yml` — a byte-copy of the CURRENT (pre-F) `docs/groups/config/views.view.all_groups.yml`, with two render-only field settings stripped (`fields.label.settings.link_to_entity`, `fields.created.date_format`) whose config schema is only resolvable with the full entity-field Views integration and which have zero effect on the query/filter behavior under test — the exact same reduction `PinnedStreamOrderingTest`'s fixture comment documents for `views.view.group_content_stream.yml`. **This fixture currently has NO new filters — it is intentionally the RED-state view.** F must land the real filters in `docs/groups/config/views.view.all_groups.yml`; T will re-sync this fixture (adding back the two filters, verbatim) at Phase 6 GREEN.

### 2. Playwright e2e — `tests/e2e/directory-filters.spec.ts`
5 tests, all navigating the live `/all-groups` page:

| Test | Criterion pinned |
|---|---|
| `both exposed filter controls are present and labeled` | WCAG: `getByLabel(/location/i)` / `getByLabel(/language/i)` visible + keyboard-focusable |
| `the location filter narrows results to matching groups` | Location "contains" filter narrows to the 2 Berlin-labeled seeded groups, excludes Paris |
| `the language filter narrows results to a matching langcode` | Language filter (`selectOption('fr')` — by langcode value, not label text, since core's `LanguageFilter` renders `<option>` values as langcodes) narrows to the 2 French groups, excludes English |
| `combining location + language yields the intersection` | Both filters together yield exactly 1 group (Berlin ∩ French) |
| `the reset button clears both filters and restores the full result set` | Reset restores all 3 seeded groups and clears the location input's value |

**Seed dependency (documented in the spec's file header, mirroring `group-links.spec.ts`'s pattern):** the language field is deliberately absent from the `/group/add/community_group` create form (per `GroupAddFormFieldsTest`'s comment — "not user-picked at creation"), and the new location field is unlikely to be added to that form either (out of the brief's scope). So the suite does **not** self-seed via the live UI form (unlike `phase4.spec.ts`'s `createGroup()` helper); it requires F to add 3 groups verbatim to `docs/groups/scripts/step_700_demo_data.php`:

| Label | Location | Language |
|---|---|---|
| `Filter Test Berlin English` | Berlin | en |
| `Filter Test Paris French` | Paris | fr |
| `Filter Test Berlin French` | Berlin | fr |

This 2×2-minus-one arrangement proves each filter independently and their intersection in one fixed data set.

## RED confirmation

**Environment setup performed** (see "Environment observations" below for why this was needed): assembled config, a namespaced DDEV project (`gm142-directory-filters`), `composer install`, `drush site:install standard`, `config_sync_directory` pointed at the assembled `config/sync` (mirrors `.github/workflows/test.yml`'s e2e job), `drush config:import`, and the full `step_700`/`step_720`/`step_780`/`step_790` seed chain — reproducing the CI E2E job's prerequisite chain locally.

### Kernel
```
ddev exec bash scripts/ci/assemble-config.sh
ddev exec "SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_tests/tests/src/Kernel/DirectoryFiltersTest.php"
```
Result: **2 failures (valid RED), 2 passes (valid GREEN-before-F, explained below).**
```
Directory Filters (Drupal\Tests\do_tests\Kernel\DirectoryFilters)
 ✘ View declares both exposed filters
   │ An exposed filter using plugin_id "language" is declared on the default display.
   │ Failed asserting that null is not null.
 ✘ Exposed form is non empty
   │ The view exposes at least 3 filters: the pre-existing "search" label filter plus the new language and location filters.
   │ Failed asserting that 1 is equal to 3 or is greater than 3.
 ✔ Location text field is attached to group bundle
 ✔ Anonymous execution excludes archived group

Tests: 4, Assertions: 111, Failures: 2, Deprecations: 3 (PHPUnit 11.3+ #[RunTestsInSeparateProcesses] notice — non-fatal, matches AccessPolicyEnforcementTest's existing style), PHPUnit Deprecations: 5.
```

**Why 2 of 4 pass before F writes code (this is legitimate, not an invalid RED):**
- `testLocationTextFieldIsAttachedToGroupBundle` passes because it validates T's OWN fixture (installed in `setUp()`), which already declares the field per the acceptance criteria's exact shape. This test's real job is to catch a *regression* — if F's real `docs/groups/config/field.storage.group.field_group_location_text.yml` differs in type/cardinality from what's asserted, or if F forgets to ship it and the assembled site lacks it, this test's counterpart in the real (non-fixture) assembled tree would fail. At Phase 6 GREEN, T re-syncs the fixture from F's actual shipped config (not T's authored guess), so this test still exercises F's real work, just not adversarially at RED time.
- `testAnonymousExecutionExcludesArchivedGroup` passes because it pins a *pre-existing* behavior (the view's `status` filter, already in `docs/groups/config/views.view.all_groups.yml` today) that the story must **preserve**, not introduce. A regression test for a preserved invariant is correctly GREEN before the change and must **stay** GREEN after — the assertion is a genuine acceptance criterion ("Group access-control ... MUST be preserved," brief line 10) and belongs in this suite so Phase 6 catches a regression if F's filter additions accidentally interact with the base query (e.g. an `OR` instead of `AND` between filters, or a broken `status` filter).

### Playwright e2e
Full CI-equivalent chain reproduced locally against a namespaced DDEV instance (`http://gm142-directory-filters.ddev.site`), then:
```
BASE_URL="http://gm142-directory-filters.ddev.site" npx playwright test tests/e2e/directory-filters.spec.ts
```
Result: **5 failed, 0 passed — all fail for the right reason** (the exposed controls and seed groups do not exist yet):
```
1) both exposed filter controls are present and labeled
   Error: expect(locator).toBeVisible() failed — getByLabel(/location/i) — element(s) not found

2) the location filter narrows results to matching groups
   Error: expect(locator).toBeVisible() failed — '.gc-directory-card' with text 'Filter Test Berlin English' — element(s) not found

3) the language filter narrows results to a matching langcode
   TimeoutError: locator.selectOption — waiting for getByLabel(/language/i) — not found

4) combining location + language yields the intersection
   TimeoutError: locator.fill — waiting for getByLabel(/location/i) — not found

5) the reset button clears both filters and restores the full result set
   TimeoutError: locator.fill — waiting for getByLabel(/location/i) — not found
```
Every failure traces to either the missing exposed-filter controls or the missing seed groups — never a Playwright/test-authorship error. (One authorship bug WAS caught and fixed during this RED pass: `selectOption({ label: /french/i })` is invalid — Playwright's `selectOption` `label` option requires a string, not a RegExp. Fixed to `selectOption('fr')`, selecting by the langcode value core's `LanguageFilter` uses for its `<option>` values, documented inline in the spec.)

## Environment observations
- `bash scripts/ci/assemble-config.sh` **failed initially** with `vendor/autoload.php missing` — this worktree had never run `composer install`. Not a RED signal in itself (a fresh worktree precondition), but worth flagging: **the worktree's `.ddev/config.yaml` had `name: pl-groups-on-d11`**, colliding with the primary checkout's already-running DDEV project of the same name. Per PROJECT_CONTEXT ("Namespace any extra containers per story"), renamed this worktree's DDEV project to `gm142-directory-filters` (uncommitted local change — not staged) and started it fresh; `composer install` then succeeded.
- After assembling, the FIRST kernel run failed with `SIMPLETEST_DB` unset (env-blocked, not a real RED) — resolved with `SIMPLETEST_DB='mysql://db:db@db:3306/db'` (DDEV's internal mariadb creds), matching CI's `SIMPLETEST_DB` usage in `.github/workflows/test.yml` line 57/179, just with DDEV's own DB host/creds instead of CI's `root:root@127.0.0.1`.
- The SECOND kernel run failed with `SchemaIncompleteException` on the raw copied `views.view.all_groups.yml` fixture (`fields.label.settings.link_to_entity`, `fields.created.date_format` — missing schema outside full entity-field Views integration) — this is the exact, pre-documented gotcha `PinnedStreamOrderingTest`'s own fixture comment warns about for `group_content_stream`. Fixed by stripping those two render-only keys from the fixture (not the real source config — see `docs/groups/config/views.view.all_groups.yml`, untouched).
- For the e2e chain, `drush cim -y` initially failed ("This import is empty... would delete all of your configuration") because DDEV's default `settings.ddev.php` points `config_sync_directory` at `sites/default/files/sync` (a separate, empty, hashed directory), not the assembled `config/sync` at the repo root. Resolved by appending `$settings['config_sync_directory'] = '../config/sync';` to `settings.php` — this is the exact line `.github/workflows/test.yml`'s e2e job (line 454) already adds for the same reason; **this is a DDEV-local wiring gap, not a CI gap** (CI does not use DDEV's settings.ddev.php).
- No config/field/seed issue blocked test AUTHORING itself — the acceptance criteria's shape (field name, plugin_id, operator) was fully specified in the brief, so I did not need to stop at "can't even seed a group without a missing upstream field." The location field and its instance are net-new per this story's own scope, so T fixture-authoring them (rather than blocking) is the correct call — F is expected to ship the byte-identical (or compatible) real files in `docs/groups/config/`.

## Ready for F
**Confirmed RED is valid.** F may implement against these tests:
1. Add exposed filters (`plugin_id: language` on `field_group_language`; `operator: contains` on new `field_group_location_text`) to `docs/groups/config/views.view.all_groups.yml`.
2. Ship `docs/groups/config/field.storage.group.field_group_location_text.yml` + `docs/groups/config/field.field.group.community_group.field_group_location_text.yml` (T's fixtures in `docs/groups/modules/do_tests/tests/fixtures/config/` are a reference shape, not a contract F must byte-match — T will re-sync fixtures from F's real shipped config at Phase 6).
3. Seed the 3 `Filter Test *` groups verbatim (label + location + language) into `docs/groups/scripts/step_700_demo_data.php`.
4. Ensure the new controls render as real `<label>`-associated form elements (WCAG) reachable via `getByLabel(/location/i)` / `getByLabel(/language/i)`.
