# Handoff-F: Phase 5 - #142 MC-3 Directory Location + Primary-language Filters

**Date:** 2026-07-23
**Branch:** 142-directory-filters
**Issue:** #142

## What was done
- `docs/groups/config/field.storage.group.field_group_location_text.yml` — new string field storage on `group` (cardinality 1), matching T's fixture shape exactly.
- `docs/groups/config/field.field.group.community_group.field_group_location_text.yml` — instance on `community_group`, label "Location".
- `docs/groups/config/language.entity.fr.yml` — **new** `ConfigurableLanguage` entity (French). Not in the brief/task instructions verbatim, but a required production prerequisite — see "Design decisions" below.
- `docs/groups/config/views.view.all_groups.yml` — added exposed `location` filter (`plugin_id: string`, `operator: contains`, table `group__field_group_location_text`) and exposed `field_group_language` filter (`plugin_id: language`, table `group__field_group_language`); added `field.storage.group.field_group_language` + `field.storage.group.field_group_location_text` as `dependencies.config`. Preserved the existing `search`/`status` filters, sorts, pager, style, and empty-text verbatim.
- `docs/groups/modules/do_group_language/src/Hook/DoGroupLanguageHooks.php` — **new**, `hook_field_views_data_alter()` implementation scoped to `group.field_group_language`, rewriting the Views-data `filter.id` for that field's dedicated-table column from core's default (`string`) to `language`. Required for `plugin_id: language` to actually resolve to a real handler — see "Design decisions."
- `docs/groups/modules/do_group_language/do_group_language.module` — updated docblock to point at the new Hooks class (matches this project's established `#[Hook]`-attribute convention; every other `do_*` module's `.module` file is a docblock-only pointer, verified across all 10).
- `docs/groups/scripts/step_700_demo_data.php` — appended idempotent Step 736: seeds the 3 canonical groups (`Filter Test Berlin English` / en / Berlin, `Filter Test Paris French` / fr / Paris, `Filter Test Berlin French` / fr / Berlin), published/public, admin as member — verbatim per `handoff-T-red.md`'s seed table.

## Design decisions

**1. Added `language.entity.fr.yml` (not explicitly requested, but required).**
Core's `LanguageFilter::access()` (the `plugin_id: language` Views filter) hard-gates on `LanguageManager::isMultilingual()` (`count(getLanguages(STATE_CONFIGURABLE)) > 1`). This repo had **zero** `language.entity.*.yml` config anywhere — despite `field_group_language` already storing `'fr'`/`'de'` string values (Step 760, an existing but CI-unused script). Without a second configured language, the filter is unconditionally hidden from the exposed form and excluded from query execution — the brief's language-filter acceptance criteria are structurally unsatisfiable without this. Verified empirically: before this file, `\Drupal::languageManager()->isMultilingual()` returned `false` on a freshly assembled+imported site; after, `true`. Picked French (not German) since that's the langcode the brief's own seed data, T's kernel test, and the e2e spec all use.

**2. Added `DoGroupLanguageHooks::fieldViewsDataAlter()` (not requested, but required for `plugin_id: language` to mean anything at runtime).**
This was the single largest and most surprising finding of the implementation pass, verified by tracing live Drupal 11.4.4 core source end-to-end (not guessed):
- A view's stored `plugin_id:` key on a filter is **schema/admin-UI metadata only**. The REAL handler class Views instantiates comes exclusively from `ViewsHandlerManager::getHandler()` reading `$data[$table][$field]['filter']['id']` from the **generated Views-data**, using the filter's own stored `table`/`field` config keys as the lookup. The stored `plugin_id` is never read there (`$override_plugin_id` is populated only from Views' Aggregation/"Group By" feature, an unrelated mechanism).
- For **bundle-attached configurable fields with a dedicated `{entity}__{field}` table** (both `field_group_language` and `field_group_location_text`), the Views-data generator is `FieldViewsDataProvider::defaultFieldImplementation()` (dispatched via `hook_field_views_data()`/`hook_views_data()`, NOT `EntityViewsData::mapSingleFieldViewsData()` — that class only ever processes BASE entity-key fields like `label`/`status`/`created`, confirmed by reading its calling loop, which iterates `getBaseFieldDefinitions()` only).
- `FieldViewsDataProvider::defaultFieldImplementation()`'s filter/argument/sort dispatch is a `switch` on the raw SQL **column type** (`varchar_ascii`, `int`, etc.) — it has **no case for the `language` field type at all**, and a `language` field's `varchar_ascii` column falls through to the generic `string` filter, same as any other short-text column. Verified live: `\Drupal::service('views.views_data')->get('group__field_group_language')['field_group_language_value']['filter']['id']` was `string` on a completely fresh, fully-imported site.
- I added a narrowly-scoped `hook_field_views_data_alter()` in `do_group_language` (the module that already owns `field_group_language`-specific behavior, extending it rather than creating a parallel module) that flips just that one key to `language` for `group.field_group_language` only. Verified fix empirically: `get_class($view->filter['field_group_language'])` went from `Drupal\views\Plugin\views\filter\Broken` to `Drupal\views\Plugin\views\filter\LanguageFilter` after this change, and the exposed form now renders a real `<select>` with `<option value="fr">French</option>` / `<option value="en">English</option>`.

**3. `field:` config key uses the `_value`-suffixed Views-data name, not the bare field name.**
Also discovered via the same trace: `ViewsHandlerManager::getHandler()` looks up `$data[$field]['filter']` using the filter's stored `field:` config value verbatim. For a dedicated-table field, Views-data generates the **bare** field name key with ONLY a `field` (formatter) sub-key — the `filter`/`argument`/`sort` sub-keys live exclusively under the **`_value`-suffixed** key (`field_group_location_text_value`, `field_group_language_value`). Using the bare name (as T's own test asserts — see "Tests that look wrong" below) makes Views fall through to `Drupal\views\Plugin\views\filter\Broken` — empirically confirmed: with `field: field_group_location_text` (bare), the handler class was `Broken`; with `field: field_group_location_text_value`, it correctly resolved to `StringFilter`. I shipped the `_value`-suffixed names because that is the only value that produces a working filter on a real site — this is what a genuine Drupal admin's "Add filter" UI action would itself store.

**4. `identifier`/`expose.label` shape mirrors the existing `search`/`label` filter exactly** (`operator_id`, `label`, `identifier`, `remember: false`), so both new controls get a real `<label for>` via `ViewsThemeHooks::preprocessViewsExposedForm()`'s `$form[$info['value']]['#title'] = $info['label']` re-attachment (verified in rendered HTML — see Tier-1 self-check).

**5. No display field columns added for the two new fields.** The brief marks this "Optional." `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` explicitly does not print the raw Views `fields` render array at all (only derived `gc_directory.*` metadata from `groups_chrome_preprocess_views_view_fields__all_groups()`); adding field columns would have zero visible effect and would be dead weight, so I skipped it to avoid unnecessary scope.

## Reuse / extend-vs-new
Extended `docs/groups/config/views.view.all_groups.yml` (the analogous/target object named by the brief) rather than creating a new view. Extended `do_group_language` (the module that already owns `field_group_language`-specific behavior — `LanguageNegotiationGroup`) with the new Views-data-alter hook, rather than creating a new module for it. No new module was created.

## Architecture notes for A
- **Layers touched:** config (field storage/instance, language entity, view), one new production PHP hook class in an existing module, one seed-script append. No new routes, no new services beyond the hook (no `.services.yml` change needed — `#[Hook]` attribute classes are auto-discovered).
- **New dependency:** `docs/groups/config/views.view.all_groups.yml` now declares `field.storage.group.field_group_language` + `field.storage.group.field_group_location_text` as `dependencies.config` (previously only `dependencies.module: [group]`). This documents the view's real data dependency; `config:import` does not itself validate/require this (only a UI "Save" of the view auto-computes it), so it was hand-added to match what a real re-export would produce.
- **Shared/other-agent-owned code:** none touched. `views-view-fields--all-groups.html.twig` (theme, story #84/CH-A2's ownership) was read but not modified — confirmed it has no interaction with filters at all (fields only).
- **A worth flagging for review:** the `plugin_id`-is-metadata-only / Views-data-alter-required finding (decision #2 above) is a genuinely non-obvious Drupal-core behavior that could recur on any future story adding an exposed filter on a bundle-attached field whose default Views-data filter plugin isn't what the story wants. Worth considering whether this deserves a note in a shared architecture doc for future stories in this repo.

## Deviations from spec / wireframe
- D phase is N/A per brief (no new UI surface) — confirmed no wireframe exists to compare against.
- No other deviations from the brief's acceptance criteria. The `language.entity.fr.yml` and `DoGroupLanguageHooks.php` additions are not literal line items in the brief text, but both are necessary production prerequisites for the brief's own explicit `plugin_id: language` criterion to be true at runtime, not scope-creep beyond it — see "Design decisions" #1–2 above for the evidence trail.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 101 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```
Exit 0.

**phpcs (new/changed PHP only):**
```
$ ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
    docs/groups/modules/do_group_language/src/Hook/DoGroupLanguageHooks.php \
    docs/groups/modules/do_group_language/do_group_language.module
(no output — 0 errors, 0 warnings)
```
`docs/groups/scripts/step_700_demo_data.php` (my new Step 736 block only): 3 pre-existing-idiom findings (one `if (...) { ...; continue; }` one-liner, two fully-qualified-classname-instead-of-`use`-statement) — these exactly match the established, pre-existing style used throughout the rest of this same file (e.g. Step 730/750's identical `\Drupal\group\Entity\Group::create()` / `\Drupal\user\Entity\User::load(1)` idiom). The whole file has 243 pre-existing phpcs findings that predate this change; `phpcs` is not part of the actual CI gate (not invoked in `.github/workflows/test.yml`). I did not reformat the pre-existing file (out of scope, drive-by risk) but did tighten my one long comment-banner line to fit 80 cols.

**Kernel — `DirectoryFiltersTest` (my story's own suite):**
```
$ SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_tests/tests/src/Kernel/DirectoryFiltersTest.php
 ✘ View declares both exposed filters
 ✘ Exposed form is non empty
 ✔ Location text field is attached to group bundle
 ✔ Anonymous execution excludes archived group
Tests: 4, Assertions: 111, Failures: 2
```
The 2 failures are **expected and not mine to fix**: both read T's own `tests/fixtures/config/views.view.all_groups.yml`, which T's own handoff states is intentionally the RED-state (no-filters) fixture — *"F must land the real filters in `docs/groups/config/views.view.all_groups.yml`; T will re-sync this fixture (adding back the two filters, verbatim) at Phase 6 GREEN."* I've landed the real filters (see "Tests that look wrong" below for the ONE correction T's re-sync needs to make, beyond just adding the filters back).

**Existing suite regression check (full custom-module kernel sweep, 2 independent runs, consistent):**
```
$ SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox $(find web/modules/custom -type d -path '*/tests/src/Kernel')
Tests: 141, Assertions: 3485, Failures: 2, Deprecations: 29, PHPUnit Deprecations: 129.
```
Failures: 2 — the exact same two `DirectoryFiltersTest` cases above (confirmed via an isolated `do_tests`-only run: `Tests: 17, Failures: 2` with the identical two `✘` names). **Zero new failures introduced anywhere across the other 12 custom modules.** All 29 deprecation notices are pre-existing core `EntityBase::getOriginal()`/`RunTestsInSeparateProcesses` deprecations in `do_group_extras`/`do_group_membership`/`do_showcase`, unrelated to this change.

**Real assembled-site behavior (end-to-end, beyond the kernel fixture — confirms the actual shipped feature works):**
```
$ ddev drush config:import -y   # creates field.storage/field.field/language.entity.fr, updates views.view.all_groups
$ ddev drush cache:rebuild -y
$ php:script .ddev/seed-demo.php   # (CI's exact uid-1 wrapper) — creates gid=9,10,11
```
- `curl /all-groups` → both controls render with real `<label for>`: `<label for="edit-location">Location</label><input ... name="location">` and `<label for="edit-field-group-language">Primary language</label><select name="field_group_language">...<option value="fr">French</option>...`.
- `curl /all-groups?location=Berlin` → exactly `Filter Test Berlin English` + `Filter Test Berlin French` (Paris excluded).
- `curl /all-groups?field_group_language=fr` → exactly `Filter Test Paris French` + `Filter Test Berlin French` (English excluded).
- `curl /all-groups?location=Berlin&field_group_language=fr` → exactly `Filter Test Berlin French` (the 1-group intersection).
- `curl /all-groups` (no filters) → archived `Legacy Infrastructure` group correctly absent (0 matches); all published groups incl. the 3 new seed groups present.
- Re-ran the seed script a second time: all 3 groups reported `Exists:` — idempotent, no duplicates.

## Tests that look wrong (for T)

1. **`DirectoryFiltersTest::testViewDeclaresBothExposedFilters` asserts `field:` as the bare field name** (`'field_group_location_text'` at line 153, `'field_group_language'` at line 144). Empirically, on a real assembled+imported site, a filter config with the bare `field:` name resolves to `Drupal\views\Plugin\views\filter\Broken` (verified live — see "Design decisions" #3 above for the full trace). The correct, working value — and what I shipped in `docs/groups/config/views.view.all_groups.yml` — is the `_value`-suffixed Views-data key (`field_group_location_text_value`, `field_group_language_value`). Recommend updating both assertions (lines 144/153) to the suffixed names when T re-syncs the fixture at Phase 6, and updating `views.view.all_groups.yml`'s fixture copy in `docs/groups/modules/do_tests/tests/fixtures/config/` to match my real shipped `field:` values (not just re-adding the filter blocks verbatim as currently planned — the values themselves need the suffix too, or the fixture will re-introduce the same `Broken`-handler bug at Phase 6, even though this specific test (`getOption('filters')`, static config inspection) would still pass either way since it never triggers `initHandlers()`).
2. **`directory-filters.spec.ts`'s reset test uses `page.getByRole('link', { name: 'Reset' })`** (line 156). Verified live: Drupal core's `ExposedFormPluginBase::exposedFormAlter()` builds the reset control as `'#type' => 'submit'`, which renders as `<input type="submit" ... value="Reset">` (confirmed in rendered HTML: `<input data-drupal-selector="edit-reset" type="submit" id="edit-reset" name="op" value="Reset" class="button js-form-submit form-submit" />`) — ARIA role "button", not "link". No theme override in `groups_chrome` changes this. This matches PROJECT_CONTEXT's own documented gotcha #5 (`#type => submit` renders `<input>`, not `<button>`) — the fix is the same class, just for `role: 'link'` → `role: 'button'`. Recommend `page.getByRole('button', { name: 'Reset' })`.

Neither of these was edited by me — both are flagged here per my role's "do not edit tests" instruction.

## Known issues
None against the acceptance criteria as shipped. The two items above are T's to correct at Phase 6; once corrected, I expect a full GREEN on both the kernel suite and the e2e suite based on the manual end-to-end verification already performed against the real assembled site (see Tier-1 section above).

## Files changed
- `docs/groups/config/field.storage.group.field_group_location_text.yml` (new)
- `docs/groups/config/field.field.group.community_group.field_group_location_text.yml` (new)
- `docs/groups/config/language.entity.fr.yml` (new)
- `docs/groups/config/views.view.all_groups.yml` (modified)
- `docs/groups/modules/do_group_language/src/Hook/DoGroupLanguageHooks.php` (new)
- `docs/groups/modules/do_group_language/do_group_language.module` (modified)
- `docs/groups/scripts/step_700_demo_data.php` (modified)

No test files were created or modified (T owns all tests). Local-only, uncommitted convenience files in this worktree (not staged, not part of this handoff): `.ddev/config.yaml` (DDEV project namespacing, T's prior change), `.ddev/run-full-kernel.sh` and `.ddev/seed-demo.php` (my own throwaway verification helpers).
