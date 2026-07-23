# Handoff-T-green: Phase 6 - #142 MC-3 Directory Location + Primary-language Filters

**Date:** 2026-07-23
**Branch:** 142-directory-filters
**Issue:** #142
**Handoff-F reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/142-directory-filters/handoff-T-red.md`

## F-flagged issues — verified-truth verdicts

F flagged two likely test-authorship issues in "Tests that look wrong (for T)" and explicitly did
not edit either (correct discipline — F writes no tests).

### Issue 1: `field:` assertions use bare field names, real Views-data needs `_value` suffix

**Verdict: F is correct.** Verified empirically against the real assembled + config-imported site
(not just F's claim):

```
$ ddev drush php:eval "..."
group__field_group_location_text keys: [table, field_group_location_text, field_group_location_text_value]
  field_group_location_text.filter.id       => MISSING (no filter key on bare name)
  field_group_location_text_value.filter.id => string
group__field_group_language keys: [table, field_group_language, field_group_language_value]
  field_group_language.filter.id       => MISSING (no filter key on bare name)
  field_group_language_value.filter.id => language
```

And confirming the view actually resolves to real (non-`Broken`) handlers with F's shipped
`field:` values:

```
$ ddev drush php:eval "... \$view->initHandlers(); ..."
status               => Drupal\views\Plugin\views\filter\BooleanOperator (exposed: no)
label                 => Drupal\views\Plugin\views\filter\StringFilter (exposed: yes)
location              => Drupal\views\Plugin\views\filter\StringFilter (exposed: yes)
field_group_language  => Drupal\views\Plugin\views\filter\LanguageFilter (exposed: yes)
```

**What T did:** Updated `DirectoryFiltersTest::testViewDeclaresBothExposedFilters()`'s two
`assertSame()` calls to expect `field_group_language_value` / `field_group_location_text_value`
(the `_value`-suffixed Views-data keys), matching what a genuine Drupal "Add filter" UI action
itself stores and what F's real shipped config correctly uses. Documented the empirical trace in
a class-level "PHASE 6 REPAIR NOTE" doc-comment so a future reader doesn't reintroduce the bare-name
bug. Also re-synced the fixture (`tests/fixtures/config/views.view.all_groups.yml`) from F's real,
GREEN-state `docs/groups/config/views.view.all_groups.yml` (byte-identical minus the two
pre-documented render-only field-formatter keys — `fields.label.settings.link_to_entity`,
`fields.created.date_format` — that require full entity-field Views integration to resolve their
config schema; same reduction `PinnedStreamOrderingTest`'s fixture already establishes as
precedent), so the fixture now carries the `_value`-suffixed `field:` keys too.

### Issue 2: e2e reset-button locator uses `role: 'link'`, core renders `<input type="submit">`

**Verdict: F is correct.** Verified against the real rendered page:

```
$ curl -sL ".../all-groups?location=Berlin" | grep -i reset
<input data-drupal-selector="edit-reset" type="submit" id="edit-reset" name="op" value="Reset" class="button js-form-submit form-submit" />
```

ARIA role for `<input type="submit">` is "button" (accessible name "Reset" from the `value`
attribute), not "link". Confirmed no `groups_chrome` theme override changes this.

**What T did:** Changed `directory-filters.spec.ts`'s reset test from
`page.getByRole('link', { name: 'Reset' })` to `page.getByRole('button', { name: 'Reset' })`.
Added an inline comment + file-header note citing the verified rendered markup, matching
PROJECT_CONTEXT's documented `#type => submit` gotcha.

## A third issue found during GREEN verification (not flagged by F, T's own repair)

After fixing issue 1, `testExposedFormIsNonEmpty()` still failed (`2` exposed widgets instead of
`3`, not the originally-failing assertion). Root-caused via a throwaway debug clone of the test
class dumping each filter handler's class + `isExposed()`: `field_group_language`'s filter handler
resolved to `Drupal\views\Plugin\views\filter\Broken` (never exposed) inside the kernel test's
isolated DB, because `DirectoryFiltersTest::setUp()` never installed
`field_group_language`'s own field storage/instance, and `do_group_language` (the module owning
the `hook_field_views_data_alter()` that rewrites this field's Views-data `filter.id` from `string`
to `language` — see F's handoff-F.md "Design decisions" #2) was not in the test's `$modules` list.
Additionally, core's `LanguageFilter::access()` hard-gates on `LanguageManager::isMultilingual()`
(>1 configured language), which the kernel test's isolated DB also lacked.

**What T did:** In `setUp()`, added:
1. `FieldStorageConfig`/`FieldConfig::create()` calls installing `field_group_language` directly
   (not a fixture — this is the pre-existing baseline field, installed the same way
   `Drupal\Tests\do_group_language\Kernel\GroupLanguageNegotiationTest::setUp()` already installs
   it for its own test).
2. `do_group_language` added to `$modules` so its Views-data-alter hook actually runs.
3. `ConfigurableLanguage::createFromLangcode('fr')->save()` so `isMultilingual()` is satisfied.

This is a repair to T's own test setup (a RED-state gap, not an F production bug) — the assertion
itself (`testExposedFormIsNonEmpty`) was always correct; its supporting fixture data was
incomplete.

## GREEN confirmation

```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: done  (exit 0)

$ ddev exec "SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_tests/tests/src/Kernel/DirectoryFiltersTest.php --testdox"
Directory Filters (Drupal\Tests\do_tests\Kernel\DirectoryFilters)
 ✔ View declares both exposed filters
 ✔ Exposed form is non empty
 ✔ Location text field is attached to group bundle
 ✔ Anonymous execution excludes archived group
Tests: 4, Assertions: 116, Deprecations: 3 (pre-existing, non-fatal), PHPUnit Deprecations: 5.
```

**Spot-check — tests still fail if behavior is removed:** confirmed by construction: before this
repair pass, with F's real shipped config in place but the STALE bare-name fixture, exactly these
same 2 assertions failed (`2/4` GREEN before this pass, matching F's own reported Tier-1 result
verbatim). The tests are therefore proven sensitive to the actual filter-declaration/exposure
behavior, not vacuously true.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `ddev exec bash scripts/ci/assemble-config.sh` | exit 0 | exit 0, 101 config + 13 modules copied | PASS |
| Kernel (story suite) | `phpunit ... DirectoryFiltersTest.php --testdox` | 4/4 GREEN | 4/4 GREEN, 116 assertions | PASS |
| Kernel (do_tests isolated) | `phpunit ... web/modules/custom/do_tests/tests/src/Kernel --testdox` | 17/17 GREEN | 17/17 GREEN, 422 assertions | PASS |
| Kernel (full cross-module sweep) | `phpunit ... $(find web/modules/custom -type d -path '*/tests/src/Kernel')` | 141/141 GREEN, 0 regressions vs F's 141-test/2-failure baseline | 141 tests, 0 failures, 3490 assertions, only pre-existing deprecations | PASS |
| Playwright e2e | `BASE_URL=http://gm142-directory-filters.ddev.site npx playwright test tests/e2e/directory-filters.spec.ts` | 5/5 GREEN | 5/5 passed (7.5s) | PASS |
| phpcs (repaired test file) | `phpcs --standard=Drupal,DrupalPractice DirectoryFiltersTest.php` | 0 errors | 0 errors, 0 warnings (exit 0) | PASS |
| phpcs (F's production files, re-check) | `phpcs --standard=Drupal,DrupalPractice DoGroupLanguageHooks.php do_group_language.module` | 0 errors | 0 errors, 0 warnings (exit 0) | PASS |

## Tier 2 results

- **Test coverage:** All 8 brief acceptance criteria backed by an authored test (see "Acceptance
  criteria status" below) — no gaps.
- **Test quality:** All 9 tests (4 kernel + 5 e2e) each name a single behavior, sit at the cheapest
  sufficient tier (static config inspection where possible, handler-init only where required,
  real view-execution only for the access-control invariant, e2e only for rendered-DOM/WCAG/
  combination behavior no lower tier can observe), and none duplicate another — `
  testViewDeclaresBothExposedFilters` (static config) and `testExposedFormIsNonEmpty` (handler-init)
  deliberately probe different layers of the same feature (declared vs. actually resolved/exposed),
  which is proportionate, not redundant, given this story's central risk (F's handoff shows the
  declared `plugin_id` can silently fail to produce a working handler). Suite size (4 kernel + 5
  e2e = 9 tests) is proportionate to the acceptance criteria — no test was added or kept beyond
  what a criterion requires.
- **Type safety:** N/A — no new TypeScript/PHP type-hint gaps found; `DirectoryFiltersTest.php`
  uses `declare(strict_types=1)` throughout, `directory-filters.spec.ts` has no `any` casts.
- **Error handling:** Kernel test's `testAnonymousExecutionExcludesArchivedGroup` exercises the
  primary error/access-denial path (archived group hidden from anonymous). No new user-facing
  error states are introduced by this story (filters degrade to "no results," not an error).
- **Data integrity:** N/A — no new write paths; both filters are read-only exposed query filters.
  Field storage cardinality (1) and type (string/language) verified via
  `testLocationTextFieldIsAttachedToGroupBundle` and F's shipped config.
- **API contract:** View's exposed-filter shape (identifier, operator, plugin_id) matches the
  brief's acceptance criteria exactly, verified both statically (kernel) and against real rendered
  HTML (curl + Playwright).
- **Security:** Both filters are read-only GET-parameter query filters against existing Views
  handler classes (`StringFilter`, `LanguageFilter`) — no new input-validation surface introduced
  beyond what core's own filter plugins already sanitize. Access-control preservation (archived/
  unlisted/private hidden) is explicitly pinned by `testAnonymousExecutionExcludesArchivedGroup`.
- **Migration safety:** N/A — no schema migrations; new field config entities are additive
  (`field.storage.group.field_group_location_text`, `field.field.group.community_group.field_group_location_text`,
  `language.entity.fr`), no existing field is altered or removed.
- **Playwright:** `npx playwright test tests/e2e/directory-filters.spec.ts` exits 0, 5/5 passed.
  No skipped tests — this story's UI surface (the two exposed filter controls + reset) is fully
  exercised by the authored suite; no coverage hole to flag for U.

## WCAG check (headless, Tier 2 — not a substitute for U's live walkthrough)

Verified via the passing Playwright test `both exposed filter controls are present and labeled`
(uses `page.getByLabel(/location/i)` / `page.getByLabel(/language/i)` — Playwright's accessible-name
resolution, which requires a real `<label for>` association, not just DOM proximity) plus direct
inspection of the rendered HTML:

```html
<label for="edit-location" class="form-item__label">Location</label>
<input data-drupal-selector="edit-location" type="text" id="edit-location" name="location" ... />

<label for="edit-field-group-language" class="form-item__label">Primary language</label>
<select data-drupal-selector="edit-field-group-language" id="edit-field-group-language" name="field_group_language" ...>
```

Both controls have a real `<label for>` (WCAG 1.3.1, 4.1.2). Keyboard operability confirmed by the
same test's `.focus()` + `toBeFocused()` assertions on both controls. Visible focus: confirmed no
`groups_chrome` theme CSS sets `outline: none`/`outline: 0` on any selector (`grep -rn "outline:\s*none\|outline:\s*0"` returned zero matches across all theme CSS files), so core/Olivero's default
visible focus-ring applies unmodified to both new controls. Full interactive-focus-ring visual
confirmation (screenshot-level) is U's job per the pipeline split — this Tier 2 check confirms the
structural precondition (labeled, focusable, no focus-suppressing CSS) is met.

## Acceptance criteria status

| # | Criterion | Status | Backing test |
|---|---|---|---|
| 1 | Exposed filter on `field_group_language`, `plugin_id: language` | PASS | `testViewDeclaresBothExposedFilters` |
| 2 | Exposed filter `location` on `field_group_location_text`, operator `contains` | PASS | `testViewDeclaresBothExposedFilters` |
| 3 | New `field_group_location_text` string field storage + instance on `group.community_group` | PASS | `testLocationTextFieldIsAttachedToGroupBundle` |
| 4 | Kernel test: view loads, both filters correct plugin_ids, executes as anonymous, archived/unlisted/private excluded | PASS | `testExposedFormIsNonEmpty` + `testAnonymousExecutionExcludesArchivedGroup` |
| 5 | Playwright e2e: seeds ≥3 groups, applies each filter, verifies filtered result, combines both, verifies intersection | PASS | all 5 tests in `directory-filters.spec.ts` |
| 6 | `phpcs` clean on new PHP files | PASS | `DoGroupLanguageHooks.php` + `do_group_language.module` (F's production files) + `DirectoryFiltersTest.php` (T's test file) — all 0 errors/warnings |
| 7 | WCAG 2.2 AA: both controls labeled, keyboard operable, visible focus | PASS | `both exposed filter controls are present and labeled` + theme-CSS focus-suppression check above |
| 8 | Existing suite still green | PASS | full 141-test cross-module kernel sweep, 0 failures, 0 new regressions vs. F's own reported baseline |

## Blocking issues

None.

## Advisory notes

- F's finding that `plugin_id:` on a Views filter is admin-UI/schema metadata only, and the real
  handler resolution depends entirely on Views-data's own `filter.id` (requiring a
  `hook_field_views_data_alter()` for a bundle-attached `language`-type field), is genuinely
  non-obvious and worth a shared architecture-doc note per F's own flag in handoff-F.md — T
  independently re-derived and confirmed this exact mechanism while diagnosing the
  `testExposedFormIsNonEmpty` gap, which is corroborating evidence this is a real, recurring-risk
  Drupal-core behavior for this repo, not a one-off.
- `docs/groups/scripts/step_700_demo_data.php`'s pre-existing phpcs findings (243, per F's handoff)
  are out of scope for this story and were not touched.
- Ready for **U** (UI Walkthrough) — this story adds a real UI surface (two exposed filter
  controls + reset on `/all-groups`) that a headless Tier 1/2 pass cannot fully validate
  (client-side rendering nuances, visual focus-ring appearance, real-browser interaction feel).
