# Handoff-T-green: Phase 6 - #127 SD-2 Card ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 127-card-tooltips
**Issue:** #127
**Handoff-F reviewed:** `docs/planning/handoffs/127-card-tooltips/handoff-F.md` (commit `d42f716`)
**Handoff-T-red:** `docs/planning/handoffs/127-card-tooltips/handoff-T-red.md`

## GREEN confirmation

Assembled fresh (`bash scripts/ci/assemble-config.sh` via `ddev exec` — no drift, 95 config files / 13 custom modules, matches F's report).

**Unit** — `ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php --testdox`:
```
Tests: 12, Assertions: 163, PHPUnit Deprecations: 13.
✔ Card tooltip copy is present and plain text
✔ All returns string map
(12/12 GREEN)
```

**E2E (target)** — `BASE_URL=https://gm127-card-tooltips.ddev.site npx playwright test tests/e2e/element-tooltips.spec.ts`:
```
7 passed (3.8s)
```

**Behavior-pinning spot-check:** mutated `card.directory.type`'s value to `''` in the assembled `HelpText.php` and re-ran the unit suite — it correctly failed (`Failed asserting that two strings are not identical`, HelpTextTest.php:248). Restored the file; back to 12/12 GREEN. Confirms the test pins the actual copy value, not merely key existence. File restored is untracked (assembled artifact); the tracked source under `docs/groups/modules/do_chrome/` was never touched — `git status` confirms no source diff.

E2E assertions (`toHaveCount(1)` per adjacent trigger, `toHaveCount(3)` per card) are inherently behavior-pinning: T-red already proved 0 elements / 7 failures with no triggers present, so any regression removing a trigger reproduces that exact RED.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Fresh assembly, no drift | `bash scripts/ci/assemble-config.sh` | clean copy, no errors | 95 files, 13 modules, done | PASS |
| Unit target suite | `phpunit ... HelpTextTest.php --testdox` | 12/12 | 12/12, 163 assertions | PASS |
| E2E target spec | `playwright test element-tooltips.spec.ts` | 7/7 | 7/7 (3.8s) | PASS |
| Full do_chrome Unit sweep | `phpunit ... do_chrome/tests/src/Unit/ --testdox` | all green | 16/16 (HelpText 12 + PermissionMatrix 4) | PASS |
| do_chrome Kernel sweep | `find ... -path '*/tests/src/Kernel'` | (n/a if none exist) | no Kernel dir exists for do_chrome — confirmed via find, nothing to run | N/A (correctly absent) |

do_chrome has no Kernel tests; the Unit directory is the module's full automated-PHP surface, and it is fully green.

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Adjacent e2e regression (directory-cards, showcase) | `playwright test directory-cards.spec.ts showcase.spec.ts` | 23/23 GREEN |
| Cross-cutting sample (nav, persona-switcher, manage-members) | `playwright test nav.spec.ts persona-switcher.spec.ts manage-members.spec.ts` | 13 passed, 1 skipped (pre-existing conditional `test.skip()` inside `manage-members.spec.ts`, unrelated to this story — read the skip condition, confirmed environment-gated, not caused by card-tooltip changes), 0 failed |
| Lint baseline comparison | `phpcs` on current `HelpText.php` vs. `git show origin/main:...HelpText.php` (linted as renamed copy) | Current: 18 errors/8 warnings, lines ≤178. Baseline: 19 errors/8 warnings (the +1 is a class-name-vs-filename false positive from the rename needed to lint a detached blob, confirmed by reading the extra finding's text — not a real difference). **F's claim confirmed: zero new lint findings from the 32 appended lines.** |
| Fixture stability | `drush eval "print count(\Drupal\group\Entity\Group::loadMultiple())"` before/after `element-tooltips.spec.ts` | 8 groups both times — stable, no zombie fixtures created by this spec |
| A11y spot check | One-off Playwright check (not committed): `tabindex="0"`, `role="note"`, non-empty `aria-label`, plus actual `.focus()` + `document.activeElement` confirmation | PASS — stronger than attribute presence alone; corroborates the spec's own `expectTooltipTrigger()` assertions |
| Test quality (§7) | Manual review of all 8 new/changed tests | Each names a distinct behavior, correct tier (e2e for rendered DOM/JS, unit for copy-source), no duplication across directory/stream describe blocks, suite proportionate to 5 new keys + 6 new DOM triggers across 2 surfaces — nothing flagged for deletion |

## Acceptance criteria status

1. Type/visibility/members ⓘ on `/all-groups` — **PASS** (test: `type, visibility, and member-count triggers carry the full tooltip contract`)
2. Byline/type/comments ⓘ on `/stream` — **PASS** (test: `byline, content-type, and comments triggers carry the full tooltip contract`)
3. No double-tooltip — **PASS** (tests: `no double-tooltip: card/stream triggers are scoped inside .gc-card, not duplicated`, both assert exactly 3 per card)
4. Visibility reuse single-sourced — **PASS** (test: `visibility ⓘ copy is single-sourced from the reused visibility.* HelpText key`, pins exact `visibility.open` string; unit test `testVisibilityCopyIsPresentPlainTextAndHonest` covers the source side, not duplicated)
5. Existing suite green — **PASS** (`directory-cards.spec.ts` 3/3, `HelpTextTest.php` 12/12)
6. `element-tooltips.spec.ts` 7/7 — **PASS**
7. WCAG 2.2 AA (tabindex/role/aria-label) — **PASS** (asserted in every contract test + independently spot-checked with real `.focus()`)

## Blocking issues

None.

## Advisory notes

- The `element-tooltips.spec.ts` `.first()`-based locator is coupled to the `all_groups` view's `created DESC` sort order — any future test suite that creates untyped fixture groups can transiently perturb it (already diagnosed and resolved once during F's self-check, IDs 9-12). Not blocking; noted for awareness, matches the existing pattern `directory-cards.spec.ts` already relies on.
- No CSS was added; F deferred visual-rendering closeness (spacing/alignment nits) to S/U's Tier 3 pass. Headless checks confirm DOM presence/contract only — U should specifically eyeball spacing of the 6 ⓘ triggers against their badges/stats/anchors on both `/all-groups` and `/stream`.
