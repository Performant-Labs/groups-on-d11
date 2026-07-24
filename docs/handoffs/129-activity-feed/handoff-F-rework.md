# Handoff-F: Rework round - do_activity_feed (fix U's Phase 8 REWORK defects)

**Date:** 2026-07-23
**Branch:** 129-activity-feed-render
**Issue:** #129

## What was done

Fixed both production defects U reported at Phase 8 (see `decisions.md`'s "U — Phase 8" entry), and
added the two regression tests O's routing decision assigned to F for this rework round.

- `docs/groups/modules/do_activity_feed/do_activity_feed.install` (new) — defensive `hook_install()`
  belt-and-suspenders self-heal for Defect 1. Force-creates `views.view.activity_feed` from this
  module's own shipped `config/install/` if it is ever found missing post-install, mirroring
  `do_group_membership_install()`'s idempotent, `$is_syncing`-respecting pattern.
- `docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php` (modified) — `buildRow()`
  now precomputes `actor_url`/`group_url` (plain URL strings, or NULL) via a new private `entityUrl()`
  helper (`$entity->toUrl()->toString()`), fixing Defect 2 at its source.
- `docs/groups/modules/do_activity_feed/templates/activity-row--social.html.twig`,
  `activity-row--aggregated.html.twig`, `activity-row--content.html.twig` (all modified) — replaced
  every `path('entity.user.canonical', {'user': row.actor.id})` /
  `path('entity.group.canonical', {'group': row.group.id})` call with the precomputed
  `row.actor_url` / `row.group_url` strings. Also fixed a **second, closely-related** defect found
  while verifying the first: `row.group.label` (bare magic-attribute access) hits the identical
  FieldItemList trap as `.id` did, since `Group` entities carry a real `label` base field — changed
  to `row.group.label()` (explicit method-call parens) in all three templates.
- `docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedViewInstallTest.php` (new,
  **untracked/unstaged** — see "Staging" note below) — regression coverage for Defect 1: a real
  `module_installer->install()` call (not `enableModules()`, which explicitly skips `hook_install()`),
  asserting `views.view.activity_feed` exists in active config storage, plus U's exact
  `pmu`/`en` repro cycle.
- `docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedRowRenderTest.php` (new,
  **untracked/unstaged** — see "Staging" note below) — regression coverage for Defect 2: renders each
  of the three row-shape theme hooks through the real `$renderer->renderRoot()`, the one path that
  would have caught the Twig crash (every pre-existing kernel test asserts only the plain row-model
  array, never renders through Twig).

## Design decisions

- **Defect 1 (view-install): did not reproduce under three independent controlled attempts** — manual
  `drush pmu`/`drush en` CLI cycling (twice, including the exact `cr && pmu && en` sequence from the
  routing instructions), and a kernel-level `module_installer->install()` call. Read
  `ConfigInstaller::installDefaultConfig()`/`ModuleInstaller.php` source directly: `config/install/*`
  (REQUIRED default config, which is where this view already correctly lives) installs
  unconditionally in dependency order — no `validateDependencies()` filtering applies to it (that
  filtering is `config/optional`-only). No schema violation found (`$typed->createFromNameAndData(...)
  ->validate()` against the live config returned 0 violations). Given I could not reproduce the defect
  and found no code-level cause, I implemented the safe, precedented fix option (c) from the routing
  instructions (defensive `hook_install()` self-heal) rather than "fixing" a hypothesis I couldn't
  confirm — most plausible root cause is a stale `web/modules/custom/do_activity_feed/config/install/`
  copy at the exact moment U's `drush en` ran (`assemble-config.sh`'s own rm-then-copy per module,
  lines 102-103, is not atomic).
- **Did NOT move the view to `config/optional/`** (fix option (b)) — this would risk making a
  possibly-phantom problem real: optional config IS subject to `validateDependencies()` filtering
  (silently dropped if the checker decides a dependency "cannot be met"), which is the exact failure
  mode Defect 1 describes. Moving a working `config/install/` entry to `config/optional/` for a
  problem I couldn't reproduce would trade a hypothesis for a real new risk.
- **Fixed `row.group.label` alongside the `.id` fix, not filed separately** — same defect class
  (Twig's magic-attribute resolution racing a ContentEntity's real base field before its method), same
  three files, discovered only because `ActivityFeedRowRenderTest` is the first test to actually push
  a row through Twig (confirmed empirically: `$group->label` returns a `FieldItemList`; only
  `$group->label()` returns the scalar). Left unfixed, this would have caused the exact same class of
  500 the moment the `.id` crash was patched — it is the literal next line of the same templates.
- **`ActivityFeedRowRenderTest` needed its own `installConfig(['system'])`** (not added to the shared
  `ActivityFeedKernelTestBase`) — `format_date('short')` needs `date_format.short` config, which none
  of the OTHER tests in the suite need since none of them render through Twig. Scoped the fix to my
  own new test class rather than the shared base, to avoid any risk to the passing tests already
  depending on that base's current `setUp()` contract.
- **`ActivityFeedViewInstallTest` needed `installSchema('user', ['users_data'])` +
  `installEntitySchema('flagging')`** in its own `setUp()` — identical precedent to `do_streams`' own
  `StreamsInstallTest::setUp()` docblock (lazily-created core tables an install/uninstall cycle needs;
  unrelated to do_activity_feed's own schema, which is empty).

## Reuse / extend-vs-new

No new objects created this round — both fixes extend existing files (`ActivityRowBuilder`, the three
row templates) or add a new file following a **named, precedented pattern already in this codebase**:
`do_activity_feed.install`'s `hook_install()` self-heal mirrors `do_group_membership.install` almost
verbatim (confirmed by reading it first); `ActivityFeedViewInstallTest`'s
`module_installer->install()` kernel-test pattern mirrors `do_streams`' own `StreamsInstallTest.php`
almost verbatim (confirmed by reading it first); `ActivityFeedRowRenderTest`'s
`$renderer->renderRoot()` pattern mirrors `do_streams`' own `StreamsShellTest.php` almost verbatim.

## Architecture notes for A

- **No new dependency edges, no schema changes, no service-signature changes.** `ActivityRowBuilder::
  buildRow()`'s return array gained two new keys (`actor_url`, `group_url`) — purely additive, no
  existing key removed or renamed, no method signature changed. Confirmed no T-authored kernel test
  asserts an exact key set on the returned row array (all use `$row['type']`/`$row['count']`/etc.
  point-lookups, never `assertSame($expectedFullArray, $row)`), so this is safe.
- **`do_activity_feed.install`'s `hook_install()`** touches `\Drupal::entityTypeManager()`,
  `\Drupal::service('config.installer')`, and `\Drupal::service('router.builder')` — all standard,
  already-declared-dependency services (`views`, `group`... wait, `router.builder` and
  `config.installer` are core, always available). No new module dependency needed.
- **Both templates' fixes are template-only** — no controller/service call signature changed as a
  result of the URL fix beyond `ActivityRowBuilder::buildRow()`'s additive new array keys.

## Deviations from spec / wireframe

None. Both fixes are internal correctness/robustness changes with zero visible markup/copy/behavior
change from the approved wireframe — the rendered HTML structure, testids, and text are unchanged;
only the previously-crashing `href` attributes now resolve to real URLs instead of 500ing.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 128 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

**phpcs** (production files only):
```
$ ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,twig \
    docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php \
    docs/groups/modules/do_activity_feed/do_activity_feed.install \
    docs/groups/modules/do_activity_feed/templates/activity-row--{social,aggregated,content}.html.twig
FOUND 0 ERRORS AND 1 WARNING AFFECTING 1 LINE   (do_activity_feed.install line 35,
  "Hook implementations should not duplicate @param documentation" — SAME warning class as the
  do_group_membership.install precedent this file mirrors; not a new pattern)
```

**Kernel suite** (`do_activity_feed`, all 17 tests, including my 2 new files):
```
$ ddev exec "SIMPLETEST_DB='mysql://db:db@db/db#kt' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
    --testdox web/modules/custom/do_activity_feed/tests/src/Kernel"
Tests: 17, Assertions: 510, Deprecations: 30 (pre-existing noise, unrelated to this change).
```
17/17 GREEN on a clean run. See "Known issues" below for a pre-existing FLAKY (not deterministically
failing) test unrelated to either defect, found while stress-testing my own new files' determinism.

**do_activity_feed row-render test isolated (proves Defect 2 fixed, deterministic across 5 runs):**
```
Tests: 5, Assertions: 110  (5/5 clean, 5 consecutive runs)
```

**Regression — sibling modules unaffected:**
```
do_activity:          23/23 GREEN
do_streams:            25/25 GREEN
do_group_membership:   26/26 GREEN
(combined: Tests: 74, Assertions: 2199, 0 failures)
```

**Live verification** (the actual acceptance bar for this rework round — see reply for full detail):
- `ddev drush cr && ddev drush pmu do_activity_feed -y && ddev drush en do_activity_feed -y` — view
  installs correctly (`drush config:get views.view.activity_feed` succeeds).
- `ddev drush php:script docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` — fixture seeded.
- `/activity` as elena_garcia (uid 4, curl with a real one-time-login session cookie): **HTTP 200**,
  35 rows rendered (23 social / 7 content / 5 aggregated), zero error text in body, `href="/user/N"`
  and `href="/group/N"` present with real numeric ids (not empty/malformed).
- `/activity/group/6` (Camp Organizers EMEA) as elena_garcia: **HTTP 200**, 7 rows, no errors.
- `/activity` anonymous: **HTTP 200**, no errors.
- `drush watchdog:show` after all of the above: zero new PHP-type errors (only pre-existing entries
  from U's original repro session and my own diagnostic `drush eval` probe, both from before the fix).

## Tests that look wrong (for T)

**One pre-existing flaky test, unrelated to either of U's defects, found while stress-testing.**
`ActivityFeedRenderTest::testContentRowOmittedWhenNodeNotViewable` (T-authored, untouched by me) fails
non-deterministically (~1-in-5 runs observed) with `Failed asserting that an array contains 2` on the
"published, viewable node" assertion. **Confirmed this predates my changes and is unrelated to my two
new files**: reproduced running ONLY the original 4 T-authored test files (no new files at all) —
same ~1-in-5 failure rate. Reproduced running ONLY my 2 new files in isolation 5 times — 5/5 clean,
zero flakiness. This is very likely the same class of `do_activity`-live-hook-noise /
`\Drupal::currentUser()`-attribution issue T's own class docblock already documents for
`testFeedRendersInterleavedRowTypes()` (`pruneHookNoiseMessages()`), just not (yet) applied to this
third test in the same file. **Not fixed here** — flagging per instructions rather than editing T's
test. Suggested direction for T: apply the same `pruneHookNoiseMessages()` treatment (or an equivalent
explicit-actor filter) to `testContentRowOmittedWhenNodeNotViewable`'s own node-id assertions.

## Known issues

None beyond the pre-existing flaky test flagged above. Both of U's reported defects are fixed and
live-verified; the `row.group.label` defect (found during this rework, same class as Defect 2) is also
fixed.

## Staging note

Per my mandate I stage no test files — the 5 production files (do_activity_feed.install,
ActivityRowBuilder.php, and the 3 twig templates) are staged by explicit path. The 2 new test files
(`ActivityFeedViewInstallTest.php`, `ActivityFeedRowRenderTest.php`) are **left untracked/unstaged**:
O's routing decision for this specific rework round explicitly assigned F to author these two
regression tests (an exception to F's normal no-test-authoring rule), but my general mandate is still
to stage no tests — leaving that staging decision to the operator/T rather than presuming it.

## Files changed

- `docs/groups/modules/do_activity_feed/do_activity_feed.install` (new)
- `docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php` (modified)
- `docs/groups/modules/do_activity_feed/templates/activity-row--social.html.twig` (modified)
- `docs/groups/modules/do_activity_feed/templates/activity-row--aggregated.html.twig` (modified)
- `docs/groups/modules/do_activity_feed/templates/activity-row--content.html.twig` (modified)

Test files (authored per this round's specific O routing exception, left unstaged — see "Staging
note"): `docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedViewInstallTest.php`,
`docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedRowRenderTest.php`.
