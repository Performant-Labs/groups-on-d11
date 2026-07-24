# Handoff-T-green: Phase 6 - #198 Docs parity: map-copy fix

**Date:** 2026-07-24
**Branch:** 198-map-copy-fix
**Issue:** #198
**Handoff-F reviewed:** `docs/handoffs/0198-map-copy-fix/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/0198-map-copy-fix/handoff-T-red.md`

## GREEN confirmation
Assembled fresh in the worktree (`bash scripts/ci/assemble-config.sh` via `ddev exec` — running
it directly under Git Bash failed with `php: command not found`; PATH issue only, not a project
problem). Assemble succeeded: 138 config files, 15 custom modules, core.extension patched.

Target test:
```
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php --testdox
Tests: 13, Assertions: 115, PHPUnit Deprecations: 14.
```
All 13/13 pass including `testDirectoryPresentationEntryNamesMapVariant`. Confirms F's own result.

Spot-check (test still pins behavior, not implementation): read the assembled source directly —
`web/modules/custom/do_showcase/src/ShowcaseCatalog.php:52` now reads "Compares list, cards, and
a Map that plots groups geographically for the group directory — the decision: information
density vs. visual scannability vs. geographic browsing." This is exactly the string T-red's RED
evidence documented as absent ("Compares list vs. card layouts…", no "Map", no "geograph").
`git diff` (per F's handoff) confirms only this line + the controller comment line changed — no
other file touched, so the test's assertions are the only thing standing between RED and GREEN.

## Tier 1 results
| Check | Command | Result |
|---|---|---|
| Assemble | `bash scripts/ci/assemble-config.sh` (via ddev exec) | PASS |
| Target Unit test | phpunit `ShowcaseCatalogTest.php` | PASS (13/13) |
| phpcs (Drupal standard) | `phpcs --standard=Drupal` on both touched files | PASS (exit 0, no output) |

## Tier 2 results
**Wider do_showcase Kernel + Unit sweep** (86 tests: 13 target Unit + 73 other Kernel/Unit specs
covering DirectoryMap, DirectoryToggle, HelpText, PersonaAccess, PersonaSpec, PersonaSwitcher):
```
ddev exec env SIMPLETEST_DB='mysql://db:db@db:3306/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist docs/groups/modules/do_showcase/tests/src/Kernel docs/groups/modules/do_showcase/tests/src/Unit --testdox
Tests: 86, Assertions: 726, Deprecations: 6, PHPUnit Deprecations: 87.
```
Zero failures, zero errors. **Note:** the bare invocation (no `SIMPLETEST_DB`) errors all Kernel
tests with "There is no database connection" — this is a pre-existing local-runner requirement
(CI's `test.yml` sets `SIMPLETEST_DB: 'mysql://root:root@mysql:3306/drupal'` for its service
container; ddev's equivalent is `mysql://db:db@db:3306/db`), not a regression from this change.
Flagging for the record since the task instructions' invocation omitted it — future local Kernel
runs in this worktree need it set explicitly.

**Grep-guard:**
```
MSYS_NO_PATHCONV=1 grep -rn "list vs. card layouts\|Map unavailable" docs/groups/
```
One match: `ShowcaseCatalogTest.php:298`, inside the test's own docblock (`RED reason: ...
decision_sentence ('Compares list vs. card layouts ...')`), documenting the pre-fix string as
history for future readers. Not stale production copy — the source file and controller comment
are both clean. PASS (zero stale references in shipped code).

**Test quality:** the single new test (`testDirectoryPresentationEntryNamesMapVariant`) names a
behavior (directory-presentation decision_sentence names the Map variant + geographic axis),
fails in isolation for the right reason (confirmed at T-red), sits at Unit tier (cheapest
sufficient — pure data-class assertion, no DB/render needed), and doesn't duplicate the adjacent
`testStreamModelEntryIsLive...` test (different entry, different assertion). Proportionate:
one test for one two-string fix. No redundant tests to prune.

## Acceptance criteria status
| Criterion | Status | Backing test |
|---|---|---|
| `ShowcaseCatalog.php` decision_sentence names all three variants + geographic axis | PASS | `testDirectoryPresentationEntryNamesMapVariant` |
| `ShowcaseController.php` stale "Map unavailable" comment removed | PASS | grep-guard (zero matches in source); no test needed — comment has no runtime effect, matches brief scope |
| No regression to existing do_showcase suite | PASS | 86/86 wider sweep, 13/13 target |

## Blocking issues
None.

## Advisory notes
- Local Kernel-test runs in this worktree require `SIMPLETEST_DB=mysql://db:db@db:3306/db`
  (ddev's db service) — not committed anywhere as a documented convention for this repo; worth
  adding to RUNBOOK.md or TEST_PLAN.md if this keeps tripping up ad hoc local sweeps.
- No UI surface touched (pure string edits to a PHP data class and a code comment) — U is N/A
  per this issue's brief; routing straight to S.
