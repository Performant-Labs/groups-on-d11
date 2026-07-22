# Handoff-T-green-ci-fix: CI rework — module-local fixture paths (issue #109)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Working directory:** `/Users/andreangelantoni/Projects/_worktrees/groups-109-do-streams`
**Root cause (confirmed by O):** `StreamsScopeTest.php` and `StreamsRankingTest.php` read config
fixtures via a SOURCE-tree relative `FileStorage(__DIR__ . '/../../../../../config')`. That
resolves to `docs/groups/config/` from the source-tree module location (files exist -> passes
locally), but resolves to the non-existent `web/modules/custom/config/` from the CI-ASSEMBLED
location (`web/modules/custom/do_streams/`). `FileStorage::read()` then returns `FALSE`, which
PHPUnit's deprecation trap converts to an array-cast error / `TypeError` in
`EntityStorageBase::create()`, erroring every test in both classes in CI while passing locally —
exactly the discrepancy PR #147's Kernel job hit.

## Fix summary

1. **Copied the config fixtures the two tests read into the module-local fixtures dir**
   `docs/groups/modules/do_streams/tests/fixtures/config/` (alongside the already-correct
   `views.view.do_streams_demo.yml`):
   - `flag.flag.follow_content.yml`, `flag.flag.follow_user.yml`, `flag.flag.follow_term.yml` —
     copied byte-identical from `docs/groups/config/` (verified via `diff`, zero delta).
   - `field.storage.node.field_group_tags.yml`, `field.field.node.page.field_group_tags.yml` —
     copied byte-identical from `docs/groups/config/` (verified via `diff` against both
     `docs/groups/config/` and the shipped `config/sync/`, zero delta in all three).
   - `flag.flag.pin_in_group.yml` — copied from
     `docs/groups/modules/do_group_pin/tests/fixtures/config/flag.flag.pin_in_group.yml` (the
     already-existing, already-schema-valid fixture copy `do_group_pin`'s own
     `PinnedStreamOrderingTest` uses via the identical module-local pattern — confirmed byte-
     identical to `do_group_pin`'s own shipped `config/optional/flag.flag.pin_in_group.yml` via
     `diff`, zero delta).
2. **Repointed both `FileStorage` calls** in `StreamsScopeTest.php:94` and
   `StreamsRankingTest.php:98` from the fragile 5-level-up source-tree path to
   `new FileStorage(__DIR__ . '/../../fixtures/config')` — the SAME module-local dir the view
   fixture already used. Also collapsed the now-redundant second `$view_fixtures` FileStorage
   instance in each file into the single `$fixtures` instance (both pointed at the identical dir),
   trimming a duplicate object construction — no behavior change.
3. **Preserved the existing `access_author`-unset workaround** in `StreamsScopeTest.php` verbatim
   (the pre-existing config-schema gap noted in `handoff-T-red.md`) — the `unset($values['flagTypeConfig']['access_author'])`
   strip-on-read still runs, now against the module-local copy.
4. **No production code changed. No test assertions changed.** Only fixture location + the
   `FileStorage` constructor argument + comments explaining the fix. Diff of both test files
   confirms this (see below).

## RE-VERIFICATION IN THE ASSEMBLED LAYOUT (the point of this rework)

Ran an isolated DDEV project (`t109ci-do-streams`, distinct from the shared checkout's
`pl-groups-on-d11`), then reproduced CI's exact assembly + invocation:

```sh
ddev config --project-name=t109ci-do-streams   # isolated project name, this worktree only
ddev start
ddev composer install --no-interaction --no-progress --prefer-dist
ddev exec bash scripts/ci/assemble-config.sh   # same script CI's "Assemble config/sync + custom
                                                # modules" step runs — copies docs/groups/modules/*
                                                # into web/modules/custom/, docs/groups/config/*
                                                # into config/sync/
```

`assemble-config.sh` output confirmed the assembled layout was built:
```
==> assemble-config: repo root = /var/www/html
==> config: copied 89 file(s), excluded 7 env-specific file(s)
==> modules: copied 11 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

Confirmed the module now lives at `web/modules/custom/do_streams/` and the module-local fixtures
traveled with it:
```
$ ls web/modules/custom/do_streams/tests/fixtures/config/
field.field.node.page.field_group_tags.yml
field.storage.node.field_group_tags.yml
flag.flag.follow_content.yml
flag.flag.follow_term.yml
flag.flag.follow_user.yml
flag.flag.pin_in_group.yml
views.view.do_streams_demo.yml
```

Prepared `sites/default` for kernel bootstrap the same way the CI job's "Prepare Drupal
sites/default for kernel bootstrap" step does (hash salt + `config_sync_directory` setting, no
installed site required), then ran the do_streams Kernel suite FROM THE ASSEMBLED LOCATION with
the same phpunit invocation CI's `web/core/phpunit.xml.dist`-based `--testdox` run uses:

```sh
SIMPLETEST_DB='mysql://db:db@db/db' \
SIMPLETEST_BASE_URL='https://t109ci-do-streams.ddev.site' \
SYMFONY_DEPRECATIONS_HELPER='disabled' \
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_streams/tests/src/Kernel/
```

**Result: 23/23 GREEN.**

```
Streams Install (Drupal\Tests\do_streams\Kernel\StreamsInstall)
 [warn] Module installs with zero schema changes
 [warn] Module uninstalls cleanly

Streams Ranking (Drupal\Tests\do_streams\Kernel\StreamsRanking)
 [warn] Recent ranking orders by created desc
 [warn] Last activity ranking prefers recent comment over newer creation
 [warn] Last activity ranking falls back to changed when no comments
 [warn] Hot ranking orders by score desc
 [warn] Hot ranking includes nodes with no score row
 [warn] Pinned ranking leads as primary key not tiebreaker
 [warn] Pinned ranking dedupes relationship fan out
 [pass] Pin toggle invalidates user stream cache tag without flush

Streams Scope (Drupal\Tests\do_streams\Kernel\StreamsScope)
 [warn] Membership scope returns only member groups nodes
 [warn] Membership scope covers all of the users groups
 [warn] Membership scope is empty for non member
 [warn] Following scope follow content branch
 [warn] Following scope follow user branch
 [warn] Following scope follow term branch
 [warn] Following scope ors and dedupes

Streams Shell (Drupal\Tests\do_streams\Kernel\StreamsShell)
 [pass] Scope tabs contract all four present with correct active flag
 [pass] Ranking control contract both pills present with correct active flag
 [pass] Trending scope does not disable the recent ranking pill
 [pass] Empty flag reflects result count
 [pass] Empty copy is distinct per scope
 [warn] No hardcoded route paths in rendered tab markup

OK, but there were issues!
Tests: 23, Assertions: 723, Deprecations: 23, PHPUnit Deprecations: 27.
```

("[warn]" = PHPUnit testdox's own mark for a passing test that also triggered a pre-existing,
unrelated core/contrib deprecation notice under `SYMFONY_DEPRECATIONS_HELPER=disabled` — e.g.
`#[RunTestsInSeparateProcesses]`, `flag.views_execution.inc` hook-autoloading, `EntityBase::original`
— none of which are new or related to this fix. "OK, but there were issues!" refers exclusively to
those deprecation counts; the run reports **zero Failures, zero Errors**.)

Also ran the FULL custom-module Kernel suite (all 11 assembled modules, not just do_streams) from
the same assembled layout, confirming no collateral breakage:
```
Tests: 86, Assertions: 2432, Deprecations: 28, PHPUnit Deprecations: 69.
OK, but there were issues!
```
Zero failures/errors across the entire assembled-layout kernel suite.

### Regression check — proves the fix, not something else, causes the pass

Temporarily reverted the assembled copy's `FileStorage` path back to the fragile
`__DIR__ . '/../../../../../config'` (touching ONLY the assembled `web/modules/custom/do_streams`
copy, never the source tree) and re-ran the same two test classes from the assembled location:

```
ERRORS!
Tests: 15, Assertions: 0, Errors: 15, Deprecations: 13, PHPUnit Deprecations: 17.
```
First error, reproducing the exact CI failure mode described in the root cause:
```
1) .../StreamsScopeTest.php:98
Automatic conversion of false to array is deprecated
```
This confirms `FileStorage::read()` returns `FALSE` from the wrong-in-CI path exactly as O
diagnosed, and that the module-local fixture path is what fixes it (not an incidental change).
Reverted the assembled copy back to the fix immediately after (`cp` from the source-tree fixed
files); this artifact was later fully removed in the mandatory cleanup below regardless.

## Clean tree confirmation

All build artifacts reverted per the mandatory cleanup step:
```sh
git checkout -- .ddev/config.yaml config/sync/ web/.htaccess web/example.gitignore web/index.php \
  web/robots.txt web/update.php
git clean -fd config/sync/ web/modules/custom/ web/autoload_runtime.php .ddev/traefik/
```

Final `git status --porcelain`:
```
 M docs/groups/modules/do_streams/tests/src/Kernel/StreamsRankingTest.php
 M docs/groups/modules/do_streams/tests/src/Kernel/StreamsScopeTest.php
?? docs/groups/modules/do_streams/tests/fixtures/config/field.field.node.page.field_group_tags.yml
?? docs/groups/modules/do_streams/tests/fixtures/config/field.storage.node.field_group_tags.yml
?? docs/groups/modules/do_streams/tests/fixtures/config/flag.flag.follow_content.yml
?? docs/groups/modules/do_streams/tests/fixtures/config/flag.flag.follow_term.yml
?? docs/groups/modules/do_streams/tests/fixtures/config/flag.flag.follow_user.yml
?? docs/groups/modules/do_streams/tests/fixtures/config/flag.flag.pin_in_group.yml
```
No build artifacts, no production-code changes, no `config/sync/` drift, no `web/modules/custom/`
leftovers, no `.ddev/` project-name drift. The isolated DDEV project (`t109ci-do-streams`) was
stopped and unlisted (`ddev stop --unlist`); it never touched the shared `pl-groups-on-d11` DDEV
instance or the shared `~/Sites/pl-groups-on-d11` checkout.

## Tier 1 note

`vendor/bin/phpcs` was attempted but the repo carries no project-level `phpcs.xml.dist` (only
`web/core/phpcs.xml.dist`, Drupal core's own irrelevant ruleset), so a bare `phpcs` run reports
~285 PSR2-default violations unrelated to this change and is not a meaningful signal here. This
matches the O rework brief's scope, which asks specifically for module-local fixtures + repointed
paths + assembled-layout verification — not a general Tier-1 lint pass. Treated as N/A for this
rework; not a blocker.

## No production code / no assertion changes

Confirmed via `git diff` on both edited test files: the only changes are (a) the `FileStorage`
constructor argument, (b) collapsing the redundant `$view_fixtures` variable into `$fixtures`
(both already pointed at the same dir), and (c) updated inline comments. No `assert*()` call,
fixture content expectation, or production `src/` file was touched.

## Outcome

23/23 GREEN in the CI-ASSEMBLED layout (`web/modules/custom/do_streams/`), reproducing CI's exact
`assemble-config.sh` + `phpunit -c web/core/phpunit.xml.dist --testdox` invocation. Regression
check confirms the fixture-path fix (not something incidental) is what resolves the failure. Full
86-test custom-module Kernel suite also green from the same assembled layout — no collateral
breakage. Tree is clean; only the intended fixture YAML + two edited test files remain staged for
commit.

**Ready for O to push to the fork / update PR #147 and re-trigger CI.**
