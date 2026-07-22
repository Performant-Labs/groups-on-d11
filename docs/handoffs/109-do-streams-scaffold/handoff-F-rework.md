# Handoff-F: Phase 5 rework - diff-gate [B-1] + NITs

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Issue:** #109

## What was done

- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
  - **[B-1] BLOCK fix:** `preprocessDoStreamsShell()`'s `scope_tabs` loop now adds
    `'url_or_param' => '?scope=' . $id` to every entry — a query-PARAMETER mapping derived purely
    from the tab's own `id`, never a hardcoded route path. Docblock updated to describe the field.
  - **[NIT-1]:** `theme()` now returns `$existing + ['do_streams_shell' => [...]]` instead of only
    the new definition, so it no longer silently drops any other `hook_theme()` implementer's
    entries.
  - **[W-1] evaluated, SKIPPED (documented):** added an `@todo` comment on
    `queryViewsDoStreamsDemoAlter()`'s static `$join_side_tables` list explaining why a
    dynamic-discovery refactor is deferred (compiled `SelectInterface` doesn't expose a stable
    "which joins came from this hook" signal without added bookkeeping; risking #56 dedupe
    correctness for a non-blocking NIT is not warranted). No behavior change.
- `docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`
  - Added `data-url-or-param="{{ tab.url_or_param }}"` to each rendered scope-tab `<span>`, so
    downstream stories (#110-#115) can read the parameter mapping off the DOM. Still a plain
    non-linking `<span>`, no `<a href>` introduced. Updated the file's docblock to document the new
    `scope_tabs[n].url_or_param` field and the `data-url-or-param` attribute.
- `docs/groups/modules/do_streams/README.md`
  - **[NIT-3]:** Shell-contract section's `scope_tabs` bullet now documents `url_or_param`'s shape
    with a one-line example (`'?scope=following'`) and restates the "never a hardcoded route path"
    rule. The "No hardcoded routes" paragraph now also notes the `data-url-or-param` surfacing
    mechanism.

## Design decisions

- **`url_or_param` value shape:** chose the literal string `'?scope=' . $id` (e.g. `?scope=global`,
  `?scope=my_feed`, `?scope=following`, `?scope=trending`) rather than any other encoding. This is
  the simplest possible parameter mapping that (a) is derivable purely from `id` with no lookup
  table, (b) can never collide with a real route path (starts with `?`, not `/`), and (c) matches
  the rework brief's own example values verbatim.
- **DOM surfacing via `data-url-or-param` rather than `<a href>`:** the rework brief explicitly
  forbids introducing `<a href="/literal/path">`. A `data-*` attribute lets downstream stories read
  the parameter mapping without the shell itself becoming a navigable link, preserving "ships
  inert."
- **[W-1] skipped, not attempted:** the brief allows skipping if the refactor isn't "genuinely
  small, safe" — judged that deriving the join-table list from `$query->getTables()` or similar
  at the compiled-query stage would need new bookkeeping (e.g. tracking added relationship aliases
  across the `viewsQueryAlter()` -> `queryViewsDoStreamsDemoAlter()` boundary) to correctly
  distinguish "joins this hook added" from any other join a future ranking branch or another
  module might add, and doing that hastily risked the #56 dedupe correctness the WARN itself is
  protecting. Left a `@todo` instead.

## Reuse / extend-vs-new

No new objects introduced in this rework — pure edits to the existing `DoStreamsHooks` class, the
existing `do-streams-shell.html.twig` template, and the existing `README.md`. Extends the same
`scope_tabs` contract established in the original Phase-5 pass; no parallel path created.

## Architecture notes for A

- No new services, routes, permissions, or schema/config changes.
- `scope_tabs` array shape changed (new `url_or_param` key added to each entry) — this is the
  exact shape the diff gate's [B-1] finding required; downstream stories (#110-#115) should expect
  this field going forward.
- Twig template gained one new `data-*` attribute on an existing element; no new render logic,
  no new theme hook variables beyond what `hook_theme()` already declared.
- No shared/other-agent-owned code touched — everything edited is do_streams' own module code from
  the original Phase-5 F pass.

## Deviations from spec / wireframe

None. The wireframe's tab markup convention (`scope_tabs[n].id` annotated per element) is
preserved; `data-url-or-param` is additive DOM instrumentation, not a wireframe-visible change (no
new visible control, no new state).

## Tier 1 self-check (incl. tests now GREEN)

Isolated DDEV project `f109-do-streams` (distinct from the shared `pl-groups-on-d11` and from any
other agent's `t*-do-streams` project), stood up via `ddev config --project-name f109-do-streams`,
`ddev start`, `ddev composer install`, `bash scripts/ci/assemble-config.sh`, then torn down after
(`ddev stop --unlist && ddev delete -O -y`).

**PHPUnit** (`vendor/bin/phpunit -c web/core docs/groups/modules/do_streams/tests/src/Kernel/`):

```
OK, but there were issues!
Tests: 23, Assertions: 709, Deprecations: 23, PHPUnit Deprecations: 27.
```

23/23 tests pass, 0 failures, 0 errors. The 23 "Deprecations" are pre-existing Drupal/Twig core
deprecation notices (flag module's `.views_execution.inc` autoloading, `TwigSandboxPolicy`
interface signature) unrelated to this rework's changes — not new, not test failures.

**phpcs** (`vendor/bin/phpcs --standard=Drupal,DrupalPractice` on all 3 changed PHP-relevant
files — `DoStreamsHooks.php`, `MembershipScope.php`, `FollowingScope.php`; `README.md`/`.twig` are
not PHP-standard-checkable and produced no phpcs output):

```
FOUND 0 ERRORS AND 4 WARNINGS AFFECTING 4 LINES  (DoStreamsHooks.php, lines 114/143/175/292)
```

All 4 warnings are the pre-existing `\Drupal calls should be avoided...use dependency injection`
pattern, on lines this rework did not touch (the `\Drupal::service('plugin.manager.views.join')`
/ `\Drupal::currentUser()` calls in `applyLastActivityRanking()`, `applyHotRanking()`,
`applyPinnedRanking()`, `onFlaggingChange()` — all pre-existing from the original F pass). No new
warnings on the edited lines (`scope_tabs` loop, `theme()` merge). `MembershipScope.php` /
`FollowingScope.php` produced the same 1-warning-each pre-existing DI pattern, no new findings —
these files were read for [NIT-4] verification only, not edited.

**phpstan** (`vendor/bin/phpstan analyse --level=1 --no-progress` on the same 3 files):

```
Found 6 errors
```

Identical set to phpcs's 4 + the 2 pre-existing findings in MembershipScope.php:65 /
FollowingScope.php:61 (`globalDrupalDependencyInjection.useDependencyInjection`) — all pre-existing,
none on lines this rework edited.

**Install/enable:** the Kernel test suite installs `do_streams` (plus `do_group_pin`,
`do_discovery`, `flag`, `views`, and other deps) fresh in every test's `setUp()` and all 23 pass —
strong evidence the module still installs/enables cleanly. No separate full-site
`drush site:install` + module-enable pass was additionally run (not required by the self-check
list beyond the Kernel-test evidence already gathered).

## Tests that look wrong (for T)

None newly identified in this rework. (Per O's diff-gate adjudication, T's repair of
`testScopeTabsContractAllFourPresentWithCorrectActiveFlag` to assert `url_or_param` is T's own
next-phase task, not something F edits.) The current suite does not yet assert `url_or_param`
presence/shape — confirmed per the rework brief's note, this stayed GREEN with the field added
(additive change, no existing assertion broken).

## Known issues

None. All required [B-1] + NIT items addressed; [W-1] explicitly evaluated and skipped with
reasoning documented in-code (`@todo`) and here.

## Files changed

- `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams/docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
- `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams/docs/groups/modules/do_streams/templates/do-streams-shell.html.twig`
- `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams/docs/groups/modules/do_streams/README.md`

No test files edited. Git tree verified clean of build artifacts post-teardown
(`git status --porcelain` shows exactly these 3 production files, nothing else).
