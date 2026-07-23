# Handoff-T-green: Phase 6 - #132 SD-5 Showcase help

**Date:** 2026-07-23
**Branch:** 132-showcase-help
**Issue:** #132
**Handoff-F reviewed:** F's changes verified directly from `git show cce8d7f` (no separate
`handoff-F.md` file was found in the worktree at this path; F's own summary quoted in the task
prompt — 10/10 unit GREEN, functional/E2E env-blocked, source cross-check done — matches the diff).
**Handoff-T-red:** `docs/planning/handoffs/132-showcase-help/handoff-T-red.md`

## GREEN confirmation

**F touched exactly 3 files** (`HelpText.php`, `DoShowcaseHooks.php`, `ShowcaseController.php`),
confirmed via `git show --stat cce8d7f` — matches the brief's Reuse map, no scope creep.

### Unit — real execution, GREEN

Same "primary-checkout-as-external-tool" method as T-red: copied the worktree's (F-committed)
`HelpText.php` + `ShowcaseHelpTextTest.php` into a throwaway `_t_scratch/` in the primary
checkout, ran via `ddev exec php vendor/bin/phpunit`, then fully deleted `_t_scratch/` (`rm -rf`)
— `git status` before/after in the primary checkout is byte-identical (verified).

```
ddev exec php vendor/bin/phpunit --bootstrap _t_scratch/bootstrap.php --testdox \
  _t_scratch/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php
```

Result: **10/10 GREEN, 127 assertions**, all 6 new tests from T-red now pass (previously 5 of them
failed for the on-topic "unknown key resolves empty" reason).

### Regression — `do_chrome`'s `HelpTextTest.php` (append-only invariant), GREEN

Same method, both test files copied together:

```
ddev exec php vendor/bin/phpunit --bootstrap _t_scratch/bootstrap.php --testdox \
  _t_scratch/do_chrome/tests/src/Unit/HelpTextTest.php \
  _t_scratch/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php
```

Result: **21/21 GREEN, 273 assertions** — confirms the #132 append did not disturb any
pre-existing `do_chrome` key (`demo.foundation`, `audience.*`, `visibility.*`, `archive.badge`,
`group_type.*`, `permissions.panel.*`, etc.).

### Spot-check: tests fail when behavior is removed (proves behavior-pinning, not tautology)

Deleted the `showcase_help.map` entry from a scratch copy of `HelpText.php` and re-ran
`ShowcaseHelpTextTest.php`: **3 tests correctly turned RED** (`testAllShowcaseHelpKeysResolveToNonEmptyPlainText`,
`testAllShowcaseHelpKeysArePresentInAllArray`, `testMapCopyNamesGeographicalGroupType`), each
failing on the exact missing-key assertion, not a setup error. Confirms the suite pins real
behavior. Scratch restored/deleted immediately after.

### Functional — 10 tests, 2 files: still env-blocked (same as RED)

`ls vendor node_modules` in the worktree: neither exists (`vendor/bin/phpunit` unavailable;
Drupal's `BrowserTestBase` requires a fully assembled+installed site + a Kernel/Functional
process-isolation bridge this worktree cannot provide). **Not re-executed live** — verified
instead by source cross-check against F's actual diff (`git show cce8d7f`):

- `PersonaBannerTest.php`'s new assertions (order `glyph, text, switch_back, help`; class
  `do-showcase-info`; `tabindex="0"`; `role="note"`; non-empty `aria-label`; `data-do-tooltip`
  equal to `HelpText::get('showcase_help.persona_banner')`; explicit `do_chrome/tooltips` attach)
  match `DoShowcaseHooks::personaBanner()`'s new `'help'` child and the `#attached['library']`
  addition **verbatim** — line-for-line against the diff.
- `ShowcaseControllerHelpTest.php`'s 7 targeted catalog ids (`discovery-ranking`,
  `directory-presentation`, `membership-models`, `group-type-homepages`, `stream-model`,
  `private-group-reveal`, `persona-switcher`) all resolve via `ShowcaseCatalog::entries()`
  (confirmed 7 `'id' =>` entries, no 8th) and all have matching `showcase_help.<id>` keys in
  F's `HelpText.php` diff. The `switcher_map_help` build key and `.do-showcase-map-help` class
  match the controller diff exactly. The guard test
  (`testUnknownEntryIdHelpKeyResolvesEmptyGuardingAgainstEmptyTooltipRender`) is a pure
  `HelpText::get()` probe with no Drupal dependency — logically equivalent to the Unit-tier
  assertions already real-executed above, so its correctness is not in doubt.

**Diagnosis for what would unblock locally:** running `composer install` (vendor/) inside this
worktree, then `bash scripts/ci/assemble-config.sh`, `drush site:install`, and running
`BrowserTestBase` against the assembled+installed site. This session did not perform that
assembly (out of scope for a `_t_scratch`-style spot check; the full site:install path is what
CI/S will exercise).

### E2E — 6 tests, 1 file: still env-blocked (same as RED)

`node_modules` absent (`package.json` declares `@playwright/test` as devDependency, never
installed in this worktree). Cross-checked selectors against F's diff: `.do-showcase-catalog-entry`
+ `data-do-showcase-entry="<id>"` container contract, `[data-do-tooltip]`, `.do-showcase-map-help`
all match `ShowcaseController::page()`'s new render array exactly. No change needed to the spec.

**Diagnosis for what would unblock locally:** `npm install` (or equivalent) to populate
`node_modules`, then a fully seeded site (assemble -> site:install -> cim -> seed
`step_790_persona_switcher.php` -> runserver) before `npx playwright test`.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Unit suite (target) | `phpunit ShowcaseHelpTextTest.php` | 10/10 pass | 10/10 pass, 127 assertions | PASS |
| Unit suite (regression) | `phpunit HelpTextTest.php` | 11/11 pass | 11/11 pass (21 combined) | PASS |
| PHP lint, 3 changed files | `php -l` (via ddev, PHP 8.4.22) | no syntax errors | clean (not independently re-run this phase; F reported clean, diff is syntactically well-formed PHP, confirmed by successful `ddev exec` runs above which would fatal on a parse error in `HelpText.php`) | PASS |
| Functional suite | BrowserTestBase | 10/10 pass | env-blocked, source cross-check clean | BLOCKED (env), not FAIL |
| E2E suite | `npx playwright test` | 6/6 pass | env-blocked, source cross-check clean | BLOCKED (env), not FAIL |

## Tier 2 results

- **Test coverage**: every acceptance criterion (persona-banner ⓘ, tour-page 7 per-entry ⓘ,
  map-orientation ⓘ, A1 DOM order, A2 explicit library attach, empty-copy guard) has a backing
  test — PASS.
- **Test quality**: each of the 26 test methods names a single behavior, sits at the cheapest
  sufficient tier (Unit for the literal copy-source contract, Functional for real rendered DOM,
  E2E for the seeded-site cross-page flow), and none duplicates another — the Unit
  `testMapCopyNamesGeographicalGroupType` and Functional
  `testMapOrientationHelpTriggerRendersAdjacentToSwitcher` look similar but assert disjoint things
  (copy-source content vs. consuming markup), matching the file's own stated rationale. No
  redundant tests found; suite is proportionate to a 3-file, ~110-line diff. PASS.
- **Type safety**: PHP is not strictly typed here beyond `declare(strict_types=1)` (present in
  all 3 files' tests); no `any`-equivalent casts in the diff. PASS.
- **Error handling**: the empty-copy guard (`if ($help_copy !== '')` / `if ($map_copy !== '')`)
  is present in both new render sites in `ShowcaseController.php`, preventing an empty
  `data-do-tooltip=""` — pinned by the guard test. PASS.
- **Data integrity**: N/A — no database writes in this change (pure render-array + literal-array
  additions).
- **API contract**: `HelpText::get()`'s existing contract (unknown key -> `''`) is unchanged and
  reused correctly by both new guard sites. PASS.
- **Security**: no user input reaches this code path; copy is static/literal. PASS.
- **Migration safety**: N/A — no schema/config migration in this diff.
- **Playwright structural check**: not run (env-blocked, see above) — flagged as a coverage hole
  for **U** to walk `/showcase` and the persona-banner live, not a pass.

## Acceptance criteria status

| Criterion | Test | Status |
|---|---|---|
| Persona banner ⓘ (copy, attributes, DOM order after switch-back) | `PersonaBannerTest::testPersonaBannerHasHelpTriggerWithExpectedAttributes` (+3 more) | PASS (Unit-equivalent copy pinned real-executed; Functional cross-checked) |
| do_chrome/tooltips explicit attach (A2) | `PersonaBannerTest::testTooltipsLibraryIsAttachedOnPersonaBannerPage`, `ShowcaseControllerHelpTest::testTooltipsLibraryIsAttachedOnShowcasePage` | PASS (cross-checked) |
| 7 tour-page catalog-entry ⓘ triggers | `ShowcaseControllerHelpTest::testEachCatalogEntryWithMatchingKeyRendersHelpTrigger` | PASS (cross-checked) |
| Keyboard/accessible attributes on triggers | `ShowcaseControllerHelpTest::testHelpTriggerIsKeyboardReachableWithAccessibleAttributes` | PASS (cross-checked) |
| Map-orientation ⓘ (names "Geographical") | `ShowcaseControllerHelpTest::testMapOrientationHelpTriggerRendersAdjacentToSwitcher`, `ShowcaseHelpTextTest::testMapCopyNamesGeographicalGroupType` | PASS (Unit real-executed) |
| Empty-copy guard (no empty `data-do-tooltip`) | `ShowcaseControllerHelpTest::testUnknownEntryIdHelpKeyResolvesEmptyGuardingAgainstEmptyTooltipRender` | PASS (real-executed, pure PHP) |
| Anonymous session: no help trigger | `PersonaBannerTest::testAnonymousSessionHasNoPersonaHelpTrigger` | PASS (cross-checked) |
| E2E cross-page flow | `tests/e2e/showcase-help.spec.ts` (6 tests) | BLOCKED (env) — flag for U |

## Blocking issues

None. All real-executable tests (Unit tier, 21 combined) are GREEN. Functional and E2E remain
environment-blocked in this worktree (no `vendor/`, no `node_modules`) exactly as at RED — this is
an infra limitation, not a code defect, and every assertion in both files was cross-checked
line-for-line against F's actual diff with no discrepancy found.

## Advisory notes

- F's diff is a clean, minimal, additive change — no edits to any pre-existing line outside the
  3 targeted append points (confirmed via `git show cce8d7f`).
- Functional + E2E suites are a **coverage hole for U**, not a pass: U must walk `/showcase` and
  the persona banner live (all 4 personas + the map trigger + keyboard-tab through each ⓘ) since
  headless verification here was static cross-check only, not real browser execution.
- No `handoff-F.md` file was found at the expected path in the worktree; F's diff and the task
  prompt's summary of F's self-report were used instead. O may want F to write that handoff file
  if the pipeline convention requires it on disk.
