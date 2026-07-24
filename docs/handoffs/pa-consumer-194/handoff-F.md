# Handoff-F: Phase 5 - #194 profile_activity.section consumer wiring

**Date:** 2026-07-24
**Branch:** 194-profile-activity-consumer
**Issue:** #194

## What was done
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`:
  - Added `use Drupal\do_chrome\HelpText;` import, alphabetically ordered between
    `Drupal\Core\StringTranslation\TranslatableMarkup` and `Drupal\do_group_pin\Hook\DoGroupPinHooks`.
  - Extended `preprocessBlock()`'s docblock with a short "#194 (SD-6)" paragraph naming the
    `profile_activity.section` HelpText key, the `data-do-tooltip`/`tabindex`/`do_chrome/tooltips`
    wiring, and the PermissionMatrixPanel precedent it mirrors.
  - Added three lines to `preprocessBlock()`'s body, after the existing wrapper-class + library-attach
    lines: `$variables['attributes']['data-do-tooltip'] = HelpText::get('profile_activity.section');`,
    `$variables['attributes']['tabindex'] = '0';`, `$variables['#attached']['library'][] = 'do_chrome/tooltips';`.
- `docs/groups/modules/do_streams/do_streams.info.yml`:
  - Added `- do_chrome:do_chrome` to `dependencies:`, placed immediately after the pre-existing
    `do_group_pin:do_group_pin` line.

## Design decisions
- **Dependency placement:** the pre-existing custom-module deps (`do_group_pin`, `do_discovery`,
  `do_showcase`) are not mutually alphabetized in this file, so I placed `do_chrome` right after
  `do_group_pin` (the brief's suggested anchor) rather than re-sorting the whole block — a re-sort
  would be an unrelated, unspecified diff against three lines this issue does not own.
- **No twig/markup change:** per brief's non-scope, the whole block wrapper (already carrying
  `attributes` via `block.html.twig`) is the trigger; no new badge/glyph element was added.

## Reuse / extend-vs-new
Extended `DoStreamsHooks::preprocessBlock()` — the exact analogous consumer the brief named
(mirrors `PermissionMatrixPanel` + `do-chrome-permission-matrix.html.twig:27`'s wrapper-level
`data-do-tooltip` + `tabindex="0"` + `do_chrome/tooltips` attach pattern, applied via preprocess
hook instead of a twig override, since the existing method is already a plugin_id-guarded array
mutation). No new class, method, or template created.

## Architecture notes for A
- One new inbound dependency: `do_streams` -> `do_chrome` (already anticipated/pre-approved per
  brief + A's Phase 3 PASS; `do_chrome` itself has zero dependencies, so no cycle risk).
- No schema, contract, or shared-component changes. `HelpText::get()` is a pure static read;
  `do_chrome/tooltips` library was already shipped and globally binds to `[data-do-tooltip]`
  (js/do_chrome.tooltips.js:20) — no library definition edited.
- Sibling #193 will also add `do_chrome:do_chrome` to this same info.yml — per the brief's
  documented rebase-conflict resolution (union, add once), no action needed from me.

## Deviations from spec / wireframe
None. Implemented exactly the three body lines + import + dependency line the issue specified,
matching the brief's rationale and the approved analogous pattern verbatim.

## Tier 1 self-check (incl. tests now GREEN)

Assembled config/modules inside the ddev container (host has no `php` on PATH):
```
ddev exec 'bash scripts/ci/assemble-config.sh'
==> assemble-config: repo root = /var/www/html
==> config: copied 138 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

Target test, GREEN:
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://gm194-paconsumer.ddev.site php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/ProfileActivityTooltipTest.php'

Profile Activity Tooltip (Drupal\Tests\do_streams\Kernel\ProfileActivityTooltip)
 ✔ Profile activity block wrapper carries tooltip and preserves existing behavior

Tests: 1, Assertions: 31, Deprecations: 11, PHPUnit Deprecations: 2.
```
(31 assertions vs. T's RED run's 26 — all 5 new-behavior + no-regression assertions now execute
and pass; the 11 deprecations are pre-existing core/contrib notices, identical class T already
flagged as unrelated.)

Full `do_streams` Kernel suite (regression check), GREEN:
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://gm194-paconsumer.ddev.site php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel'

OK, but there were issues!
Tests: 52, Assertions: 1541, Deprecations: 38, PHPUnit Deprecations: 62.
```
Zero failures/errors across all 52 do_streams Kernel tests; "issues" = pre-existing deprecation
notices only (geofield annotations, ViewsConfigUpdater view-schema updates, etc.), none touching
this change's surface.

PHPCS (`Drupal,DrupalPractice`) on both touched files:
```
ddev exec 'php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_streams/src/Hook/DoStreamsHooks.php web/modules/custom/do_streams/do_streams.info.yml'

FOUND 1 ERROR AND 8 WARNINGS AFFECTING 9 LINES  (DoStreamsHooks.php only; info.yml: clean, 0 output)
```
Investigated and confirmed **pre-existing, not introduced by this diff**: swapped the pre-#194
baseline (via `git show HEAD:...`) into the real file path and re-ran PHPCS — the identical 1
error (`Doc comment short description must be on a single line`, a pre-existing docblock
elsewhere in the class, now shifted from line 644 to 659 by my insertions) and 8 warnings
(`DrupalPractice.Objects.GlobalDrupal`, pre-existing `\Drupal::service(...)` calls in unrelated
methods `applyLastActivityRanking`/`applyHotRanking`/`applyPinnedRanking`/`viewsPostRender`/
`buildRsvpChipRenderArray`, now shifted by the same offset) appear on the untouched baseline at
the real path. Diffed baseline vs. modified file directly to confirm the only delta is my
intended 15-line addition. Zero net-new PHPCS findings from this change; left the pre-existing
issues untouched (out of this issue's scope — fixing them would be an unrelated drive-by edit).

## Tests that look wrong (for T)
None. The authored test matched the implementation surface exactly; no adjustment needed.

## Known issues
None. All acceptance criteria met:
- [x] `data-do-tooltip` on block wrapper, value = `HelpText::get('profile_activity.section')`.
- [x] `tabindex="0"` on the same wrapper.
- [x] `do_chrome/tooltips` in `#attached['library']`.
- [x] Pre-existing `do-streams-profile-activity` class + `do_streams/profile_activity` library
  preserved (no regression on #114).
- [x] `do_chrome` present in `do_streams.info.yml` dependencies.
- [x] Kernel test asserts all four attributes/attachments in one render pass (T's test, now GREEN).

## Files changed
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
- `docs/groups/modules/do_streams/do_streams.info.yml`
