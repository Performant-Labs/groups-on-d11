# Handoff-T-green-2: Phase 10 — GREEN re-verify after F fix1

Date: 2026-07-23
Branch: 123-discovery-three-ways
Re-verifies: F's fix1 for U's blocking finding F-U-1 (discovery.ranking tab click/Enter did not swap embed or update URL).

## Verdict

PASS. All eight acceptance criteria remain GREEN; criterion 6 (live click/Enter swap + URL update — the exact defect U caught) is now GREEN and independently reproduced. Zero regressions vs Phase 6 baseline (357/357). Route to **U for re-walkthrough**.

## Harness gotcha honored

F recorded: `web/modules/custom/do_showcase/` is served; `docs/groups/modules/` is source. After any JS edit, must run `scripts/ci/assemble-config.sh` + `drush cache:rebuild` or Playwright tests stale code.

- `diff docs/groups/modules/do_showcase/js/do_showcase.switcher.js web/modules/custom/do_showcase/js/do_showcase.switcher.js` → identical. F's assemble stuck. Ran `drush cache:rebuild` before the Playwright pass belt-and-braces.

## Environment

- DDEV project `gm123-discovery` persistently installed from U Phase 8; not re-installed.
- URL: http://gm123-discovery.ddev.site (admin/admin).
- Worktree: C:/Users/aange/Projects/_worktrees/groups-sc4-discovery-123.

## Per-command results

### 1. Playwright — discovery-compare.spec.ts (F-U-1 target)

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/discovery-compare.spec.ts --reporter=list`

```
Running 11 tests using 1 worker
  ok  1  … renders an H2 heading and a switcher with three options (6.1s)
  ok  2  … clicking each tab updates the URL to ?discovery=<id> and changes the embedded content (4.3s)
  ok  3  … the Promoted tab shows exactly the two seeded promoted nodes (438ms)
  ok  4  … the Hot tab shows commented threads … (464ms)
  ok  5  … deep-linking to /showcase?discovery=hot pre-selects the Hot tab (435ms)
  ok  6  … role="radiogroup" and a non-empty aria-label (478ms)
  ok  7  … exactly ONE wrapper-level tooltip trigger (415ms)
  ok  8  … both switchers coexist: ?variant=cards&discovery=hot (1.1s)
  ok  9  … WCAG-adjacent smoke: keyboard-operable (679ms)
  ok 10  … /hot standalone page is unaffected (1.1s)
  ok 11  … directory.layout stub switcher still renders unaffected (421ms)
  11 passed (17.0s)
```

Tests 2 (mouse click) and 9 (keyboard Enter) — the two that failed at U-time — are GREEN. F-U-1 fixed and independently confirmed.

### 2. Playwright — directory-toggle.spec.ts (SC-5 non-regression)

`BASE_URL=http://gm123-discovery.ddev.site npx playwright test tests/e2e/directory-toggle.spec.ts --reporter=list`

```
Running 12 tests using 1 worker
  ok  1..3, -   4 (skipped, seed-conditional page-2), ok  5..12
  1 skipped
  11 passed (9.8s)
```

Identical shape to pre-fix. The mirror-driven / preventDefault-in-place contract survives — F's `usesMirrorModel()` discriminator correctly routes `directory.layout` down the original path.

### 3. PHPUnit — do_showcase Unit + Kernel (non-regression)

`ddev exec "cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db:3306/db ../vendor/bin/phpunit -c core modules/custom/do_showcase/tests/src/Unit modules/custom/do_showcase/tests/src/Kernel"`

```
OK, but there were issues!  (deprecation notices only)
Tests: 77, Assertions: 625, Deprecations: 6, PHPUnit Deprecations: 77.
```

Byte-identical to Phase 6 baseline. `DirectoryTogglePreRenderTest` 8/8 GREEN — 3-arg BC callers unaffected.

### 4. PHPUnit — do_showcase Functional DiscoveryRankingControllerTest

`ddev exec "cd /var/www/html/web && … phpunit … modules/custom/do_showcase/tests/src/Functional/DiscoveryRankingControllerTest.php"`

```
OK, but there were issues!
Tests: 8, Assertions: 94, Deprecations: 23, PHPUnit Deprecations: 9.
```

Matches Phase 6 baseline (8/8, 94 assertions).

### 5. Full custom-module non-regression sweep

Ran in the same split pattern as Phase 6 (Kernel+Unit together; Functional split three ways for harness reliability). Every custom-module test dir covered.

- **Kernel + Unit, all 14 custom modules (17 test dirs):** `phpunit -c core <17 explicit paths>` → **279 tests, 5544 assertions, 0 failures/errors** (31 test-level + 254 PHPUnit deprecations).
- **Functional, `do_showcase`:** **37/37, 260 assertions, 0 failures.**
- **Functional, `do_tests` + `do_group_extras` + `do_chrome`:** **17/17, 159 assertions, 0 failures.**
- **Functional, `do_group_membership` + `do_multigroup`:** **24/24, 219 assertions, 0 failures.**
- **Grand total: 357 tests / 0 failures/errors — identical to Phase 6.**

Trap recorded: first pass I tried `phpunit --testsuite=unit,kernel modules/custom` (simpler invocation). It reported 78 errors — the `--testsuite` flag was ignored on directory args and pulled Functional tests into a Kernel-only DB context, which fails at `KernelTestBase.php:221`. Not a regression; harness gotcha. Re-ran with the Phase 6 explicit-path recipe → clean. Recorded so a future T does not chase the wrong signal.

### 6. phpcs — production files

Per Phase 6 pattern, ran `phpcs --standard=Drupal,DrupalPractice` against the source paths under `docs/groups/modules/`.

| File | Result | Notes |
|---|---|---|
| `docs/groups/modules/do_showcase/src/VariantSwitcher.php` | 0 errors, 0 warnings | Untouched by F this phase. |
| `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` | 0 errors, 0 warnings | Untouched by F this phase. |
| `docs/groups/modules/do_chrome/src/HelpText.php` | 18 errors, 8 warnings | **Pre-existing, identical count to Phase 6.** Untouched by F this phase; not introduced by this fix. |
| `docs/groups/modules/do_showcase/css/discovery-compare.css` | 0 errors | |
| `docs/groups/modules/do_showcase/do_showcase.libraries.yml` | 0 errors | |
| `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` | 6 errors | All "TRUE/FALSE/NULL must be uppercase" under Drupal standard. **Pre-existing convention in the file** — verified via `git diff HEAD~1 -- …js` that F's added `true`/`null` tokens duplicate the pre-existing pattern already in the file (see e.g. `select(…, true)` at lines that also existed pre-fix). Zero new violations. |

**Zero new phpcs violations introduced by F's fix.**

## Acceptance criteria table

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 1 | H2 section + 3-option switcher renders on /showcase | PASS | spec 1 |
| 2 | Deep link `?discovery=recent|hot|promoted` renders correct embed | PASS | spec 5 + Functional 8/8 |
| 3 | Two seeded Promoted titles present on Promoted tab | PASS | spec 3 |
| 4 | Hot ranking shows commented threads above uncommented | PASS | spec 4 |
| 5 | Distinct query key `?discovery=` (no collision with `?variant=`) | PASS | spec 8 |
| 6 | **Interactive: click/Enter swaps embed AND updates URL** | **PASS (was FAIL at U-time)** | specs 2, 9 (re-run after fix1) |
| 7 | Exactly one wrapper-level tooltip (POC scope) | PASS | spec 7 |
| 8 | WCAG-adjacent (focus/contrast/keyboard/non-color status) | PASS | spec 9 + U's Phase 8 measurements |
| Non-reg | /hot standalone + directory.layout stub unaffected | PASS | specs 10, 11 + directory-toggle 11/11 |
| Non-reg | Full custom-module PHPUnit sweep | PASS | 357/357 identical to Phase 6 |
| Non-reg | phpcs — no new violations | PASS | file-by-file table above |

## Constraints honored

- No production or test file modified — verification only.
- Did not trust F's counts; re-ran every gate from scratch.

## Handoff to next role

**U for re-walkthrough** — F-U-1 is fixed; all Tier-1 gates green; the live-browser interactive contract that U owns needs a fresh eyes-on-glass confirmation before spec-audit / merge.

Nothing to hand back to F — no regressions found.
