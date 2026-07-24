# Handoff-F: Phase 5 rework round 2 - AC-9 deterministic pin-first ordering

**Date:** 2026-07-23
**Branch:** 110-stream-110
**Issue:** #110

## What was done
- `docs/groups/config/views.view.my_feed.yml` — added a `flag_relationship` (scoped to the
  `pin_in_group` flag, `required: false`, LEFT JOIN semantics) and a `pin_sort` sort entry
  (`flag_sort` plugin over the relationship's `flagging.flagged` field, `order: DESC`) as the
  PRIMARY sort key, with the existing `created DESC` sort unchanged and now the secondary
  tiebreaker. Added `flag` to `dependencies.module` and `flag.flag.pin_in_group` to
  `dependencies.config`. The round-1 cache fix (`cache: type: none`) is untouched.

No other production files were touched. No controller, hook, or seed-script changes — the
task's hard constraint ("sort-chain change in view YAML only") is honored.

## Design decisions
- **Root cause confirmed live before touching anything:** all 20 seeded nodes share one
  byte-identical bulk-seed `created`/`changed` timestamp (`1784829022`, confirmed via
  `drush sql-query` against `node_field_data`). The round-1 cache fix (`type: tag` → `type: none`)
  correctly forces every request to re-run the query, which surfaced this pre-existing
  non-determinism (previously masked by whichever arbitrary order the first post-clear query
  happened to produce, frozen by the buggy shared cache).
- **Pinning mechanism traced to `step_700_demo_data.php:344-353`** ("Step 750: Flags" block):
  `Sprint Planning: Portland 2026` (nid=1) is flagged via the `flag` contrib module's
  `pin_in_group` flag — a GLOBAL flag (`global: true`, `entity_type: node`, confirmed via
  `docs/groups/config/flag.flag.pin_in_group.yml`), not a node field. This is the SAME flag
  `do_group_pin` and `do_streams` already read for their OWN views — but both modules'
  `viewsQueryAlter()` hooks are hardcoded to their own view id
  (`DoGroupPinHooks::STREAM_VIEW_ID = 'group_content_stream'`,
  `DoStreamsHooks::DEMO_VIEW_ID = 'do_streams_demo'`) and `return` early for any other view id, so
  neither hook fires for `my_feed`. Read both classes in full to confirm this.
- **Rejected the task's option 2 (`changed DESC` tiebreaker) with evidence:** `changed` is
  byte-identical to `created` for all 20 nodes (confirmed live) — flagging a node via the `flag`
  module creates a separate `flagging` entity and does NOT touch the node's own `changed` column.
  Zero discriminating power; would not have fixed AC-9.
- **Rejected the task's option 3 (`nid DESC` tiebreaker) with evidence:** nid=1 ("Sprint Planning:
  Portland 2026") has the LOWEST nid of all 20 seeded nodes (the very first node created in the
  seed script), so `nid DESC` sorts it LAST among ties — the opposite of what AC-9 requires. This
  option would have actively broken AC-9, not just been "less semantic."
- **Chose option 1 (pin-field-aware primary sort), implemented entirely in view YAML — no hook
  needed.** Confirmed the two existing pin-aware query-alter hooks in this codebase
  (`do_group_pin`, `do_streams`) both require PHP because there is no Views-native declarative
  sort field for a CUSTOM computed CASE expression — but the Flag CONTRIB module itself ships a
  genuine, first-class Views sort plugin for exactly "sort by flagged-or-not":
  `flag_sort` (`web/modules/contrib/flag/src/Plugin/views/sort/FlagViewsSortFlagged.php`),
  registered against a synthetic `flagging.flagged` field
  (`web/modules/contrib/flag/src/FlaggingViewsData.php:59-77`, `real field: uid`). Its `query()`
  method does `$this->query->addOrderBy(NULL, "$this->tableAlias.uid", $this->options['order'])`
  — with `order: DESC` ("Flagged first" per the plugin's own `sortOptions()`), a flagged row's
  non-NULL `uid` sorts before a NULL/absent one under `ORDER BY ... DESC` (NULLs sort last under
  DESC in both MySQL and Postgres). This is config-schema-valid (`flag_sort` has no dedicated
  `views.sort.*` schema entry, so it validates against the generic `views.sort.*` / `views_sort`
  wildcard base type — the same fallback the existing `created` sort's `date` plugin does NOT need
  but every other unlisted sort plugin id does) and never used anywhere else in this codebase
  (confirmed via a repo-wide grep before writing the YAML), but IS shipped, documented, and has a
  live example in the `flag` module itself
  (`web/modules/contrib/flag/modules/flag_bookmark/config/install/views.view.flag_bookmark.yml`),
  which is where the exact relationship + sort key shapes used here were confirmed against.
- **`required: false` on the relationship is deliberate** (not the `flag_bookmark` example's
  `required: true`) — a required relationship would INNER JOIN and drop every unpinned node from
  the feed entirely, which is correct only for a bookmarks-only view, not a general feed.
- **`user_scope: any`** is correct-but-inert for a global flag: read
  `FlagViewsRelationship::query()` directly — it only adds a `uid = current_user` extra join
  condition `if (!$flag->isGlobal())`, so `user_scope` has zero effect on `pin_in_group` (a global
  flag). Set to `any` rather than `current` so the config's stated intent stays honest regardless.
- **Sort ordering achieved by YAML map declaration order, not a hook.** Views compiles `sorts:`
  entries in the order they appear in the config map. Putting `pin_sort` FIRST and `created`
  SECOND achieves the exact "front of orderby, not appended" correctness that `do_group_pin`/
  `do_streams` both needed a PHP `array_unshift` fix for (their #52/#56 history) — here it is free,
  because there is no query-alter step reordering anything after the view registers its sorts.
- **Fan-out ruled out, not just assumed:** confirmed live via `drush sql-query` that exactly ONE
  `flagging` row exists for `pin_in_group` site-wide (`SELECT COUNT(*), entity_id FROM flagging
  WHERE flag_id='pin_in_group' GROUP BY entity_id` → count=1), consistent with the flag's own
  `global: true` semantics (at most one flagging row per node). A LEFT JOIN scoped to a global
  flag cannot fan a node out into duplicate rows; `distinct: true` (already set, unchanged) is an
  additional safety net regardless.

## Reuse / extend-vs-new
Extended the existing `my_feed` view object (the only object touched) using a first-class,
already-shipped Views plugin from the `flag` contrib module the codebase already depends on and
already uses elsewhere (`do_group_pin`, `do_streams` both consume the same `pin_in_group` flag,
just via a different mechanism for their own views). No new module, no new hook, no new PHP class.
This is the minimal-footprint extension the task's constraints called for.

## Architecture notes for A
- One new Views relationship (`flag_relationship`, plugin `flag_relationship` from `flag` contrib)
  and one new Views sort (`pin_sort`, plugin `flag_sort` from `flag` contrib) added to
  `views.view.my_feed.yml`. Both are stock Flag-module Views plugins, not custom code.
- New config dependency: `flag.flag.pin_in_group` (added to `dependencies.config`), new module
  dependency: `flag` (added to `dependencies.module`) — both required because
  `FlagViewsRelationship::calculateDependencies()` adds the flag's own config dependency, and
  because the relationship/sort plugin classes live in the `flag` module.
- No PHP touched. No route, controller, hook, or seed-script changes.
- The `do_streams` module-local test fixture
  (`docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_feed.yml`) is now stale
  relative to the production view (still has the pre-round-1 `cache: type: tag` and lacks this
  round's relationship/sort) — flagged for T below, not touched (test fixture, T's territory).

## Deviations from spec / wireframe
None — this is a config-only ordering fix with no UI/wireframe surface change. The task's explicit
preference order (pin-field-aware sort > `changed DESC` > `nid DESC`) was followed exactly, with
options 2 and 3 formally rejected on evidence (see Design decisions) before implementing option 1.

## Tier 1 self-check (incl. tests now GREEN)

### Config assembled + imported cleanly
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 104 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done

$ MSYS_NO_PATHCONV=1 ddev exec drush config:import --partial --source=/var/www/html/.scoped-import-110-scratch -y
+------------+--------------------+-----------+
| Collection | Config             | Operation |
+------------+--------------------+-----------+
|            | views.view.my_feed | Update    |
+------------+--------------------+-----------+
 [success] The configuration was imported successfully.

$ ddev exec drush cr
 [success] Cache rebuild complete.
```
Scoped import touched ONLY `views.view.my_feed` — verified no other config entity was pulled in
(the many other `config/sync`/`web/modules/custom` diffs visible in `git status` are pre-existing
build-artifact regeneration from `assemble-config.sh` itself, unrelated to this change).

### Live query verification (before E2E)
Executed the view directly as `elena_garcia` (uid=4), both pager-capped (10, the production
default) and uncapped (14, her real full scope):
```
Result count: 10
1. nid=1 title="Sprint Planning: Portland 2026"
2. nid=14 title="Code Sprint: Migrate API"
...
Full result count (no pager cap): 14
1. nid=1 title="Sprint Planning: Portland 2026"
...
14. nid=17 title="Governance Town Hall"
```
"Sprint Planning: Portland 2026" leads in both cases; zero Thunder Distribution / Drupal
Deutschland nids appear anywhere in the full 14-row scoped set. (Diagnostic `drush php:script`
files used for this check were deleted immediately after — not shipped.)

### E2E — full my-feed suite, run twice for stability
```
$ BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/my-feed.spec.ts --reporter=list
Running 7 tests using 1 worker

  ok 1 ... anonymous GET /my-feed is denied or redirected to login (AC-1) (3.8s)
  ok 2 ... anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8) (2.2s)
  ok 3 ... authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8) (4.0s)
  ok 4 ... elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4) (2.1s)
  ok 5 ... elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9) (1.4s)
  ok 6 ... a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6) (5.9s)
  ok 7 ... /my-feed does not leak one user's cached results to the next user with no cache clear between (AC-5, AC-9) (3.0s)

  7 passed (23.2s)
```
Second run (stability confirmation): 7 passed (13.7s), same 7/7 result.

### Regression checks
```
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db#simpletest" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel'
OK, but there were issues!
Tests: 23, Assertions: 723, Deprecations: 23, PHPUnit Deprecations: 27.
```
23/23 pass, byte-identical to T's Phase 6 recorded baseline (`Tests: 23, Assertions: 723`). The
"issues" are pre-existing deprecation noise (flag annotation-to-attribute migration, Twig sandbox
interface warnings) already documented in this journal's F Phase 5 entry, unrelated to this change.

`SIMPLETEST_DB` had to be exported manually (`mysql://db:db@db:3306/db#simpletest`, DDEV's standard
internal credentials, confirmed against `web/sites/default/settings.ddev.php`) — this env var is
not baked into `.ddev/config.yaml`'s `web_environment` and resets between shell sessions.

```
$ BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/nav.spec.ts --reporter=list
6 passed (8.3s)
```
No regression.

```
$ ddev exec 'SIMPLETEST_DB="..." php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome'
(run 1) ERRORS! Tests: 27, ... Errors: 1  -> PageHelpRouteMapTest::testRouteMapContainsExactlyTenEntries (and 3 siblings) flagged
(run 2) ERRORS! Tests: 27, ... Errors: 1  -> PermissionMatrixPanelTest::testPermissionMatrixPanelRenders flagged
(run 3) ERRORS! Tests: 27, ... Errors: 1  -> PermissionMatrixPanelTest::testPermissionMatrixPanelRenders flagged again
```
A DIFFERENT test failed across 3 consecutive full-suite runs (`PageHelpRouteMapTest` once, clean
otherwise; `PermissionMatrixPanelTest` twice, erroring) — the rotating-symptom signature of a
pre-existing Functional+Kernel process-isolation flake in this mixed-layer test suite, NOT a
regression from this change. Confirmed by re-running `PermissionMatrixPanelTest` in COMPLETE
isolation, where it passes cleanly:
```
$ ddev exec 'SIMPLETEST_DB="..." SIMPLETEST_BASE_URL="http://gm110-groups-stream-110.ddev.site" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Functional/PermissionMatrixPanelTest.php'
OK, but there were issues!
Tests: 1, Assertions: 5, Deprecations: 4, PHPUnit Deprecations: 2.
```
Neither rotating failure has ANY code path through `views.view.my_feed.yml`, `flag`, or
`pin_in_group` — `do_chrome` only ever references the string `'view.my_feed.page_1'` as an inert,
never-matched `HelpText`/`PageHelp` route-map key (documented in F's original Phase 5 entry in
this journal), and never loads or executes the `my_feed` view.

## Tests that look wrong (for T)
None new this round. The `do_streams` module-local test fixture
(`docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_feed.yml`, used by
`MyFeedRouteTest`) is now stale relative to the production view — it still shows the pre-round-1
`cache: type: tag` and lacks this round's relationship/sort. This is not a failing test today
(`MyFeedRouteTest` does not currently assert on AC-9's ordering), so nothing is RED because of
this staleness — flagging it purely so T is aware, in case equivalent PHPUnit-layer coverage for
pin-first ordering is ever wanted (would need a byte-copy refresh of the fixture from the
production file).

## Known issues
None. All 7 authored E2E tests pass, both this round's target (AC-9, test #5) and the prior
round's regression guard (cross-user cache leak, test #7) verified green together in the same run.

## Files changed
- `docs/groups/config/views.view.my_feed.yml` — added `flag_relationship` relationship and
  `pin_sort` sort (via the `flag` contrib module's stock `flag_relationship`/`flag_sort` Views
  plugins) as the primary ordering key ahead of the existing `created DESC` secondary sort; added
  `flag` module dependency and `flag.flag.pin_in_group` config dependency.
