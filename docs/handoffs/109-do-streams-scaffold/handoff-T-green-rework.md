# Handoff-T-green-rework: url_or_param covering-assertion repair (diff-gate [B-1])

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Issue:** #109
**Dual-review diff response reviewed:** docs/handoffs/109-do-streams-scaffold/dual-review-diff-response.md
**Handoff-F-rework reviewed:** docs/handoffs/109-do-streams-scaffold/handoff-F-rework.md

## What was repaired

`StreamsShellTest::testScopeTabsContractAllFourPresentWithCorrectActiveFlag`'s docblock cited the
brief's [B-3] shell contract (`scope_tabs` = array of `{id, label, url_or_param, active}`), but the
assertion body only checked `id`/`label`/`active` — `url_or_param` went unpinned. This let F's
original implementation ship the field missing entirely, undetected until the o4-mini diff gate's
[B-1] finding. F has since added `url_or_param => '?scope=' . $id` to every `scope_tabs` entry and
surfaced it in the Twig template as `data-url-or-param="{{ tab.url_or_param }}"`. This handoff adds
the covering assertion T was routed to write.

## Assertions added

1. **`testScopeTabsContractAllFourPresentWithCorrectActiveFlag`** (extended, not replaced): for
   each of the 4 scope ids (`global`, `my_feed`, `following`, `trending`):
   - `assertArrayHasKey($scopeId, $urlOrParamByScope, ...)` — the field is present.
   - `assertSame('?scope=' . $scopeId, $urlOrParamByScope[$scopeId], ...)` — the exact expected
     query-parameter value.
   - `assertStringStartsNotWith('/', $urlOrParamByScope[$scopeId], ...)` — positively pins "this is
     a query-parameter mapping, not a hardcoded route path" (the acceptance criterion's actual
     concern), not just a literal-value match.
2. **`testNoHardcodedRoutePathsInRenderedTabMarkup`** (extended): on the same `renderRoot()`
   markup already under test —
   - `assertStringContainsString('data-url-or-param="?scope=global"', $markup, ...)` — the value
     reaches the rendered DOM, not just the preprocess `$variables` array.
   - `assertStringNotContainsString('data-url-or-param=""', $markup, ...)` — no tab renders the
     attribute empty (catches a partial-population regression).

Both additions live inside the two existing test methods (not a new method) — `url_or_param` is one
more field of the same contracts those tests already exercise (render-array shape and rendered
markup, respectively); a new method would duplicate the same fixture setup for no isolation
benefit.

## Non-vacuity proof (break-and-restore, empirical)

Rather than relying on reasoning alone, I proved this by execution:

1. Backed up `DoStreamsHooks.php` and `do-streams-shell.html.twig` to the scratchpad.
2. Removed the line `'url_or_param' => '?scope=' . $id,` from
   `DoStreamsHooks::preprocessDoStreamsShell()`'s `scope_tabs` loop (reproducing the pre-rework
   shape the diff gate flagged).
3. Re-ran `StreamsShellTest` (`--testdox`). Both new assertion sites failed for the right reason:
   - `testScopeTabsContractAllFourPresentWithCorrectActiveFlag`:
     ```
     ✘ Scope tabs contract all four present with correct active flag
       The 'global' scope_tabs entry carries a url_or_param key.
       Failed asserting that an array has the key 'global'.
     ```
   - `testNoHardcodedRoutePathsInRenderedTabMarkup`:
     ```
     ✘ No hardcoded route paths in rendered tab markup
       The rendered Global tab surfaces its url_or_param as a data-url-or-param attribute...
       Failed asserting that '...data-url-or-param=""...' contains "data-url-or-param=\"?scope=global\"".
     ```
4. Restored `DoStreamsHooks.php` from the scratchpad backup, verified byte-identical via `diff`
   (no output) and `git status --porcelain` (clean, no diff against the tracked committed state).
5. Re-ran the full suite: back to 23/23 GREEN.

This confirms both new assertions are load-bearing — they fail when the pinned behavior is absent
and pass only when it is present, exactly the "would fail against pre-rework code" proof the task
asked for, verified by execution rather than assumption.

## GREEN confirmation

Isolated DDEV project `t109c-do-streams` (`ddev config --project-name t109c-do-streams`,
`ddev start`, `ddev composer install`, `bash scripts/ci/assemble-config.sh`).

Full do_streams Kernel suite:

```
SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://t109c-do-streams.ddev.site \
BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest \
vendor/bin/phpunit -c web/core --testdox docs/groups/modules/do_streams/tests/src/Kernel/
```

```
OK, but there were issues!
Tests: 23, Assertions: 723, Deprecations: 23, PHPUnit Deprecations: 27.
```

**23/23 GREEN**, 0 failures, 0 errors. (Same 23 pre-existing core/Twig/flag-module deprecation
notices F's own handoff documented — none new.)

`StreamsShellTest` alone: 6/6 GREEN, 186 assertions (up from 178 before this repair — the 8 new
assertions: 4x `assertArrayHasKey` + 4x `assertSame` + 4x `assertStringStartsNotWith` in the first
method minus overlap, + 2 in the second — net +8 reflected in the assertion-count delta).

## Tier 1 results (test-file scope)

| Check | Command | Result |
|---|---|---|
| phpcs | `vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/tests/src/Kernel/StreamsShellTest.php` | 3 errors + 1 warning, **identical set before and after my edit** (verified via `git stash`/`git stash pop` comparison — all 4 findings sit on pre-existing docblock lines I did not touch, e.g. long-description capitalization on lines shifted by my insertions but not authored by me). **No new findings.** |
| phpstan | `vendor/bin/phpstan analyse --level=1 --no-progress docs/groups/modules/do_streams/tests/src/Kernel/StreamsShellTest.php` | ~30 "undefined method assert*()" / "undefined property $container" errors — a known tooling artifact of analysing a Kernel test file standalone without Drupal/PHPUnit stub configuration (no repo-level `phpstan.neon` wires this for test files; F's own Tier-1 self-check likewise scoped phpstan to production files only). Not a real defect; PHPUnit resolves these methods/properties from `KernelTestBase` at runtime. |
| phpunit (module suite) | see above | 23/23 GREEN |
| Install | Kernel test `setUp()` installs `do_streams` + deps fresh in every test; all 23 pass | PASS |

## Tier 2 results

- **Test quality:** both extended assertions name a specific behavior (`url_or_param` presence,
  exact value, non-route-path shape; DOM surfacing of the same), fail in isolation for the right
  reason (proven by break-and-restore above), sit at the cheapest sufficient tier (Kernel, matching
  the rest of the suite — no new HTTP/Functional test needed), and assert behavior (the actual
  string value and its shape) not implementation detail. No duplication introduced — both additions
  extend existing test methods that already exercise the same fixtures.
- **Coverage:** the diff gate's [B-1] gap (an acceptance-criterion field cited in a docblock but
  never asserted) is now closed at both the render-array level and the rendered-markup level.
- **Proportionality:** no new test method added; the fix stays inside the two tests whose
  docblocks/scope already covered this contract, keeping the suite size unchanged (23 tests, more
  assertions).

## Acceptance criteria status

- **[B-3] shell contract, `scope_tabs[n].url_or_param` field:** PASS — backed by
  `testScopeTabsContractAllFourPresentWithCorrectActiveFlag`'s new assertions.
- **"No hardcoded routes" criterion, `url_or_param` is a query-parameter mapping not a route
  path:** PASS — backed by the `assertStringStartsNotWith('/', ...)` assertion (render-array level)
  and the extended `testNoHardcodedRoutePathsInRenderedTabMarkup` (rendered-markup level).
- **`url_or_param` value reaches the DOM (`data-url-or-param` attribute):** PASS — backed by the
  extended `testNoHardcodedRoutePathsInRenderedTabMarkup`.

## Blocking issues

None.

## Advisory notes

- The phpstan "undefined method" noise on Kernel test files analysed standalone is pre-existing
  tooling friction, not introduced by this change — flagging for awareness only, no action taken
  (out of scope for this targeted repair; F's own self-check made the same scoping choice).
- Git tree verified clean of build artifacts post-teardown: `git status --porcelain` shows exactly
  one modified file, `docs/groups/modules/do_streams/tests/src/Kernel/StreamsShellTest.php`. No
  production code was edited by T in this rework.

## Files changed

- `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams/docs/groups/modules/do_streams/tests/src/Kernel/StreamsShellTest.php`
