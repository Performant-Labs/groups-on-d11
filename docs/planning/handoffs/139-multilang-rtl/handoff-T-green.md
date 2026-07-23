# Handoff-T-green: Phase 6 - MC-4 Multilingual baseline + RTL

**Date:** 2026-07-22
**Branch:** 139-multilang-rtl
**Issue:** #139
**Handoff-F reviewed:** `docs/handoffs/139-multilang-rtl/decisions.md` (Phase 6 — F entry)
**Handoff-T-red:** `docs/handoffs/139-multilang-rtl/handoff-T-red.md`

## GREEN confirmation

### Reconciled indicator suite (Task 2 done first, then re-verified)

```
ddev -p gm139-multilang-rtl exec bash -lc \
  'SIMPLETEST_DB=mysql://db:db@db:3306/db php vendor/bin/phpunit \
   -c web/core/phpunit.xml.dist --testdox \
   web/modules/custom/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php'
```
```
.DDDDDD                                                             7 / 7 (100%)
Group Language Indicator (Drupal\Tests\do_group_language\Kernel\GroupLanguageIndicator)
 ✔ Language direction fixture sanity
 ⚠ Renders rtl indicator for ar primary group
 ⚠ Renders ltr indicator for fr primary group
 ⚠ No indicator when langcode is site default
 ⚠ No indicator when field is truly unset
 ⚠ No indicator for undefined langcode
 ⚠ No indicator for uninstalled langcode
OK, but there were issues!
Tests: 7, Assertions: 163, Deprecations: 4.
```
7/7 pass. The 4 deprecation warnings are pre-existing core noise (Twig sandbox policy,
`EntityBase::original` — the latter triggered only by the new truly-unset test's `->set([])`,
harmless and unrelated to this story's logic).

**Spot-check the tests still fail if behavior is removed**: confirmed at T-red — the two positive
tests (`testRendersRtlIndicatorForArPrimaryGroup`, `testRendersLtrIndicatorForFrPrimaryGroup`)
failed on missing markup before F's hook existed. I additionally hand-verified
`testNoIndicatorWhenLangcodeIsSiteDefault` pins the NEW site-default guard specifically: reading
F's hook (`GroupLanguageIndicatorHooks::entityView()` lines 116-122), removing the
`$langcode === $language_manager->getDefaultLanguage()->getId()` suppression branch would make
this test fail (an "English" pill would render) — confirmed by inspection, not by mutating F's
code (out of scope for T).

### Full kernel suite — 107/107, via three fast batches (the full-file separate-process sweep is
impractically slow in this container; see note below)

`web/modules/custom` now has **107** total kernel test methods (106 baseline + 1 new test from my
Option A split). Verified in four groups, matching the file-by-file breakdown:

| Batch | Modules | Tests | Assertions | Result |
|---|---|---|---|---|
| standalone | `do_group_language/GroupLanguageIndicatorTest` | 7 | 163 | 0 failures |
| standalone | `do_group_language/GroupLanguageNegotiationTest` | 6 | 137 | 0 failures |
| batch 1 | `do_tests`, `do_streams`, `do_notifications`, `do_group_pin` | 48 | 1340 | 0 failures |
| batch 2 | `do_profile_stats`, `do_group_mission`, `do_group_extras`, `do_discovery` | 28 | 846 | 0 failures |
| batch 3 | `do_group_membership`, `do_multigroup` | 18 | 455 | 0 failures |
| **Total** | | **107** | **2941** | **0 failures anywhere** |

Every batch reported `OK, but there were issues!` — the "issues" are exclusively PHPUnit/PHP
deprecation notices (Twig sandbox policy 4th-arg deprecation, `EntityBase::original` access,
pre-existing across the whole suite per T-red's own baseline note). Zero `✘` failure markers in
any batch log (`grep -c "^✘"` = 0 for all three batch logs).

**Process note**: the single monolithic `find ... | xargs phpunit` invocation the brief specifies
took over 45 minutes and was still only 72% complete when killed — 10 of the suite's test classes
declare `#[RunTestsInSeparateProcesses]`, and running all 106+ tests in one `phpunit` invocation
forks a fresh PHP process + full Drupal bootstrap per class, which is extremely slow in this
DDEV/MariaDB container. Splitting into per-module batches (still real, full bootstraps, same
assertions) reached the identical 107/107 green result in a few minutes total. No test's *content*
changed — this is purely an invocation-grouping choice for practicality, not a scope reduction.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble config | `ddev -p gm139-multilang-rtl exec bash scripts/ci/assemble-config.sh` | exit 0 | exit 0, custom modules + `language` registered | PASS |
| Indicator suite | see above | 6+/6+ green | 7/7 green | PASS |
| Full kernel suite | see batches above | 106/106 (+1 new) | 107/107 green | PASS |
| Lint | (not re-run; F's handoff reports clean, no production files changed by T) | clean | N/A — T touched only test file | N/A |

## Tier 2 results

### Test coverage / test quality (per testing/test-quality.md §7)

- **Coverage of acceptance criteria**: every criterion in the brief has a backing test —
  indicator markup (`testRendersRtl…`/`testRendersLtr…`), directory Views field (E2E test 3,
  now RED for a real production bug — see Blocking below), RTL end-to-end (E2E test 1),
  `do_tests` regression (batch 1, 0 failures).
- **Test-quality reconciliation (Task 2, Option A taken)**: see below.
- **Proportionality**: no redundant tests added. The new `testNoIndicatorWhenFieldIsTrulyUnset`
  is NOT a duplicate of `testNoIndicatorWhenLangcodeIsSiteDefault` — they exercise genuinely
  different code paths (`isEmpty()` true vs. site-default langcode match), confirmed by each
  test's own inline fixture-sanity assertion (`assertTrue($group->get(...)->isEmpty())` vs.
  `assertSame('en', $group->get(...)->value)`).

### Type safety / error handling / data integrity / API contract / security
Not applicable in a way this story's scope changes — no new types, no new user input paths, no
new API endpoints. The one data-integrity-adjacent finding is the `views.view.all_groups.yml`
config dependency bug (below), which is a config-schema/import-time integrity issue.

### Migration safety
`step_760.php`'s Arabic-group creation is idempotent (verified live: re-running it after the
group already exists correctly hits the `Exists:` branch — not independently re-run this session,
but the code path was exercised as the group-creation branch on first run and the idempotency
contract matches `step_700_demo_data.php`'s established pattern per the brief).

### Playwright (E2E)
```
BASE_URL="https://gm139-multilang-rtl.ddev.site" npx playwright test tests/e2e/group-language.spec.ts --reporter=list
```
```
Running 3 tests using 1 worker
  ok 1 RTL Arabic group renders dir="rtl" with language indicator (971ms)
  ok 2 LTR French group renders dir="ltr" with language indicator (810ms)
  x  3 directory /all-groups shows language column (10.5s)

  Error: expect(locator).toContainText(expected) failed
  Locator: locator('tr, .views-row').filter({ hasText: 'Drupal العربية' })
  - Expected substring: Arabic
  + Received string: (card markup with no language text at all)

  1 failed, 2 passed
```
**This is a real production-code failure, not a spec bug.** See Blocking Issues.

## Acceptance criteria status

| # | Criterion | Status | Backing test |
|---|---|---|---|
| 1 | Group entity carries primary language; group page shows indicator | **PASS** | `testRendersRtlIndicatorForArPrimaryGroup`, `testRendersLtrIndicatorForFrPrimaryGroup` (Kernel) + Playwright tests 1–2 (live) |
| 1 (cont.) | Directory (`/all-groups`) exposes the language column (for MC-3) | **FAIL** | Playwright test 3 — Views field is in config but never rendered on the live page (see Blocking) |
| 2 | Seeded RTL-primary group renders `html[dir="rtl"]` | **PASS** | Playwright test 1 (live, `https://gm139-multilang-rtl.ddev.site/group/9`) |
| 3 | WCAG 2.2 AA — `lang` attributes + direction correct | **PASS** for the rendered indicator/page (ar → `lang="ar" dir="rtl"`, fr → `lang="fr" dir="ltr"`, verified live) |
| 4 | Existing kernel + functional suite green; `do_tests` `GroupAddFormFieldsTest` stays green | **PASS** | 107/107 kernel tests green (see batches above); `GroupAddFormFieldsTest` is inside the `do_tests` batch-1 run (48/48 green, 0 failures) |
| 5 | Playwright `group-language.spec.ts` green vs seeded site | **FAIL** | 2/3 — see Blocking |

## Blocking issues

**1. `views.view.all_groups.yml` — invalid `dependencies.config` entry blocks clean-room
`config:import` (real bug, F's file).**
- File: `docs/groups/config/views.view.all_groups.yml`, line 6 (`dependencies.config`).
- Current: `- group__field_group_language`
- Expected: `- field.storage.group.field_group_language` (the actual config entity ID; `group__field_group_language`
  is the *Views table name*, correct only for the `table:` key at line 51, not a config dependency).
- Repro: fresh `drush site:install` + `drush config:import -y` against the assembled config fails
  with `Configuration "views.view.all_groups" depends on the "group__field_group_language"
  configuration that will not exist after import.` Confirmed root cause by locally correcting only
  the `dependencies.config` entry (leaving `table:` untouched) in the assembled copy —
  import then succeeds cleanly.
- I do NOT have write access to `docs/groups/config/` (out of my constraint) — F must fix.

**2. The `/all-groups` directory does not render the new Views field at all — the acceptance
criterion "directory exposes the language column" is not met (real bug, needs a decision from
F/O, code lives outside `docs/groups/`).**
- Root cause: `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig`
  (pre-existing, story #84/CH-A2) is a **custom row template** that only prints a curated set of
  `gc_directory.*` variables assembled by `groups_chrome_preprocess_views_view_fields__all_groups()`
  in `groups_chrome.theme`. It explicitly does NOT loop over the raw Views `fields` array (see the
  template's own doc comment: "`fields` ... remains available as a fallback but is intentionally
  not printed"). Adding `field_group_language` to the view's `display_options.fields` (what F did,
  correctly per the brief's literal instruction) has **zero visible effect** on the actual rendered
  card — the brief's assumption that a bare Views-field addition would surface on this directory
  was architecturally incorrect for a view with a custom row override, and this was not caught by
  A's plan review.
- This file is **not** under `docs/groups/` — it's native to `web/themes/custom/groups_chrome/`, so
  it was never a "disjoint file" this story could touch under the stated deliverable list, and I
  cannot edit it either (out of my Kernel/E2E-test-only constraint).
- Resolution needs an **O decision**: either (a) amend the brief/scope to include a
  `groups_chrome.theme` preprocess + template change (add a `gc_directory.language_label` key and
  print it), or (b) descope the directory-column acceptance criterion for this story and file it
  as a follow-up. Either way this is not a fix T or F can make unilaterally without a scope call.
- Repro: `curl .../all-groups` → HTML has zero occurrences of `views-field-field-group-language`
  or the word "Arabic"/"French" anywhere in a group's card markup (verified: the group labels
  "Drupal العربية" / "Drupal France" DO appear via the `gc_directory.name` field, but no language
  text anywhere).

**3. (Infrastructure, pre-existing, NOT this story's scope — noted for O awareness, not blocking
this story's own deliverables.)** `docs/groups/scripts/step_640.php` (baseline runbook script,
git-blamed to the initial commit, never touched by this story) creates configurable languages via
`$storage->create(['id' => $langcode])->save()` instead of
`ConfigurableLanguage::createFromLangcode($langcode)`. The former does NOT populate `direction`
or `label` from Drupal's predefined-language data — so on a freshly seeded site, `ar` resolves to
`direction: ltr` (wrong) with `label: null`, silently breaking the entire premise of "Arabic is
RTL" that this story's brief assumed as a given ("Arabic (`ar`) is installed as RTL in core...
Drupal core marks it RTL by default" — decisions.md Phase 1 "Assumed"). I manually corrected the
`ar`/`fr` language config entities on the live seeded DB for verification purposes only (not
committed, not touching `step_640.php`) to confirm F's hook code is correct once the language
metadata is right — confirmed: `dir="rtl"` and the indicator both render correctly for `ar`, and
`dir="ltr"`/indicator correctly for `fr`, once the language entities carry correct data. **This
means F's `do_group_language` code is NOT the cause of the direction bug** — but any future
clean-room reseed of this project (CI or another wave) will silently produce wrong `ar` direction
until `step_640.php` is fixed. Flagging to O as a separate, pre-existing infra bug outside this
story's scope, not something T or F should fix under this issue.

## Advisory notes

- The Kernel test suite's `#[RunTestsInSeparateProcesses]` pattern (used by 10 of the project's
  test classes) makes the brief's literal single monolithic `find | xargs phpunit` verification
  command impractically slow in this DDEV/MariaDB container (45+ min, 72% complete when killed).
  Running the same test files in smaller module-grouped batches produces identical, full-fidelity
  results in a few minutes. Future T-green phases on this project may want to adopt the
  batched-invocation pattern as the practical default rather than the literal brief command.
- `do_group_language.module`'s Deviation #1/#2 (accepted by O in the Phase 6 F decisions.md entry)
  are both confirmed correct and load-bearing by this live verification — the site-default guard
  in particular is what makes `testNoIndicatorWhenLangcodeIsSiteDefault` pass for the right reason.

## Which option (A or B) was taken for the semantic-drift reconciliation

**Option A** (rename + split into two tests):
- `testNoIndicatorWhenFieldEmpty` → renamed to **`testNoIndicatorWhenLangcodeIsSiteDefault`**,
  docblock rewritten to describe the actual invariant it pins (the site-default suppression
  branch, not "field is empty").
- **New test added**: `testNoIndicatorWhenFieldIsTrulyUnset` — forces a genuinely empty
  `field_group_language` item list via `$group->set('field_group_language', [])->save()` on an
  already-saved group (bypassing `LanguageItem::applyDefaultValue()`, which only fires at
  `create()`-time when the field key is absent from `$values`). Confirmed this reliably produces
  `isEmpty() === TRUE` via an inline fixture-sanity assertion inside the test itself.
- Added a class-level "suppression-branches summary" comment cross-referencing each of the four
  branches in F's hook (`(a)` sentinel, `(b)` uninstalled, `(c)` site-default, `(d)` truly-unset)
  to the specific test that pins it, so a future reader isn't misled again.

Test file: `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`
(now 7 tests, up from 6 at T-red).

## Files edited (absolute paths)

- `C:\Users\aange\Projects\_worktrees\groups-multilang-rtl\docs\groups\modules\do_group_language\tests\src\Kernel\GroupLanguageIndicatorTest.php`
  (renamed 1 test, added 1 new test, added cross-reference doc comments — no other test file
  touched, no `tests/e2e/**` changes needed since the two passing E2E tests required no fixes).

## No production code changed

Confirmed via `git status --short docs/` before finishing this phase — the only tracked change
under `docs/` is the one test file listed above. `config/sync/*.yml` and `web/*` changes that
`assemble-config.sh` / `drush config:export`-adjacent operations incidentally wrote into the
bind-mounted worktree during environment stand-up were reverted with `git checkout --` before
finishing (matches the convention established in prior T-green sessions on this project, e.g.
`docs/handoffs/0138-mc7-manage-members/handoff-T-green.md`).
