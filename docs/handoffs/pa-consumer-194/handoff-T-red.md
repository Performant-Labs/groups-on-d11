# Handoff-T-red: Phase 4 - #194 profile_activity.section consumer wiring

**Date:** 2026-07-24
**Branch:** 194-profile-activity-consumer
**Brief / wireframe reviewed:** `docs/handoffs/pa-consumer-194/brief.md` (no wireframe — attribute-only augmentation of an existing wrapper, no new UI element per brief's Non-scope)

## A precondition
Confirmed: A returned PASS on the plan (Phase 3) — see `docs/handoffs/pa-consumer-194/decisions.md`, "A — Phase 3" entry, zero block findings.

## Tests authored
One Kernel test, one method, asserting in a single render pass (per brief's explicit "Kernel test asserts all four attributes/attachments in one render pass" acceptance criterion):

- **`ProfileActivityTooltipTest::testProfileActivityBlockWrapperCarriesTooltipAndPreservesExistingBehavior`**
  (`docs/groups/modules/do_streams/tests/src/Kernel/ProfileActivityTooltipTest.php`)
  - Tier: **Kernel**, using the direct-invocation convention already established by `StreamsShellTest` (bypasses the block/theme render pipeline; instantiates `DoStreamsHooks` directly and calls `preprocessBlock()` against a hand-built `$variables` array, asserting on the mutated array). Cheapest sufficient tier — the hook's own contract is a pure array mutation guarded by `plugin_id`; no page render, routing, or entity fixtures are needed to pin it.
  - Pins, in one pass:
    1. `attributes['data-do-tooltip'] === HelpText::get('profile_activity.section')` (AC1)
    2. `attributes['tabindex'] === '0'` (AC2)
    3. `#attached['library']` contains `do_chrome/tooltips` (AC3)
    4. No-regression: `attributes['class']` contains `do-streams-profile-activity` AND `#attached['library']` contains `do_streams/profile_activity` (AC4, preserves #114)

## RED confirmation

Setup note: this worktree's DDEV project (`.ddev/config.yaml`) had a stale `name: gm145-wcag` colliding with another running worktree — renamed to `gm194-paconsumer` and started fresh (environment-only fix, no test/source content touched). `vendor/` only exists inside the container; PHP/PHPUnit run via `ddev exec` with `SIMPLETEST_DB`/`SIMPLETEST_BASE_URL` supplied inline (phpunit.xml.dist ships both empty).

```
cd C:/Users/aange/Projects/_worktrees/groups-profile-activity-consumer-194
ddev exec "bash scripts/ci/assemble-config.sh"
ddev exec "SIMPLETEST_DB='mysql://db:db@db/db' SIMPLETEST_BASE_URL='http://gm194-paconsumer.ddev.site' php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/ProfileActivityTooltipTest.php"
```

Output (assertion excerpt):
```
F                                                                   1 / 1 (100%)

Profile Activity Tooltip (Drupal\Tests\do_streams\Kernel\ProfileActivityTooltip)
 ✘ Profile activity block wrapper carries tooltip and preserves existing behavior
   │
   │ The profile-activity block wrapper carries a data-do-tooltip attribute.
   │ Failed asserting that an array has the key 'data-do-tooltip'.
   │
   │ /var/www/html/web/modules/custom/do_streams/tests/src/Kernel/ProfileActivityTooltipTest.php:88

Tests: 1, Assertions: 26, Failures: 1, Deprecations: 11 (pre-existing core/contrib deprecations, unrelated to this change).
```

This is a genuine RED for the right reason: 26 assertions executed before the failure (module loaded, class instantiated, `plugin_id` guard passed, existing-behavior assertions for `do-streams-profile-activity`/`do_streams/profile_activity` — order-dependent PHPUnit runs assertions in source order, so the new AC1 assertion is simply reached and fails first). `DoStreamsHooks::preprocessBlock()` pre-#194 genuinely does not set `data-do-tooltip`, so the failure is the feature assertion itself, not an import/setup/harness error.

## Ready for F
Confirmed RED is valid. F may implement against this test. Implementation surface per brief: `DoStreamsHooks::preprocessBlock()` (DoStreamsHooks.php:612-620) — add the three lines (`data-do-tooltip`, `tabindex`, `do_chrome/tooltips` attach) + `use Drupal\do_chrome\HelpText;` import + `do_chrome:do_chrome` in `do_streams.info.yml` dependencies.
