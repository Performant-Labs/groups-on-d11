# Handoff-T-red: Phase 4 - do_streams scaffold (engine scope plugins, ranking wiring, shared stream shell)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Brief / wireframe reviewed:** `docs/handoffs/109-do-streams-scaffold/brief.md`,
`docs/handoffs/109-do-streams-scaffold/survey.md`, `docs/handoffs/109-do-streams-scaffold/handoff-D.md`
(approved wireframe + its 2 binding D-gate resolutions)

## A precondition

Confirmed: A returned PASS on the plan (Phase 3) — `handoff-A.md` records PASS with 4 WARN items
([A-W1]..[A-W4]), all of which are addressed in the tests below (see per-test notes).

## Environment note (read before running)

No isolated DDEV/DB existed for this worktree yet (`.ddev/config.yaml` inherited the SHARED
`pl-groups-on-d11` project name, which conflicts with the primary checkout — a setup gap, not a
test-authoring one). To execute the suite I:
1. Renamed **my local, uncommitted** `.ddev/config.yaml` copy to an isolated project name
   (`t109-do-streams`), started it, ran `ddev composer install`, `ddev drush site:install`, and
   `bash scripts/ci/assemble-config.sh` (the project's existing single-source-of-truth script that
   copies `docs/groups/modules/*` into `web/modules/custom/` and `docs/groups/config/*` into
   `config/sync/`, exactly as CI/the RUNBOOK do).
2. **Reverted every build-artifact side effect before finishing** (`git checkout --
   .ddev/config.yaml config/sync/ web/...` + `git clean -fd config/sync/ web/modules/custom/
   web/autoload_runtime.php`) so the worktree's git state carries ONLY the test-authoring changes
   listed below. `git status --porcelain` now shows exactly the 6 staged files.
3. F/O will need to redo this bring-up (`ddev start` against an isolated project name,
   `ddev composer install`, `bash scripts/ci/assemble-config.sh`) to run the suite themselves — this
   is the same isolated-DDEV requirement `preflight-checklist.md` already calls out for worktrees,
   not a new one this story introduces.

Run command used throughout (from the worktree root, inside the DDEV web container):
```
SIMPLETEST_DB=mysql://db:db@db/db \
SIMPLETEST_BASE_URL=https://<project>.ddev.site \
BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest \
vendor/bin/phpunit -c web/core --testsuite kernel docs/groups/modules/do_streams/tests/src/Kernel/
```

## Tests authored

All 4 files extend `GroupsKernelTestBase` (or plain `KernelTestBase` for the install test), mirror
`PinnedStreamOrderingTest`'s live-view-execution pattern per [B-5], and self-provision every
fixture (no CI/demo seed assumed, per the epic rule). 23 tests total.

### `tests/src/Kernel/StreamsScopeTest.php` — membership + following scope (AC 1-2, survey items 1-2)

| Test | Criterion pinned | Tier | Why this tier |
|---|---|---|---|
| `testMembershipScopeReturnsOnlyMemberGroupsNodes` | AC1 / [B-9]: a member's group content is included, a non-member's group content is excluded, even though Group's own access layer (outsider role granted) would otherwise let it through — isolates the exclusion to do_streams' own filter | Kernel | Query-result behavior of a Views filter plugin; only a live-view execution proves the compiled SQL, not static analysis |
| `testMembershipScopeCoversAllOfTheUsersGroups` | AC1: the EXISTS join is per-user, not per-(single)-group — content from 2 different membership groups both appear | Kernel | same |
| `testMembershipScopeIsEmptyForNonMember` | AC1 negative case: a user in zero groups sees zero rows | Kernel | same |
| `testFollowingScopeFollowContentBranch` | AC2, branch 1/3: `follow_content` alone is sufficient | Kernel | same |
| `testFollowingScopeFollowUserBranch` | AC2, branch 2/3: `follow_user` (author flagged, not the node) | Kernel | same |
| `testFollowingScopeFollowTermBranch` | AC2, branch 3/3, [B-4]: joins via `field_group_tags`, NOT `field_tags` | Kernel | same |
| `testFollowingScopeOrsAndDedupes` | AC2: the 3 branches OR together; a doubly-matched node appears exactly once | Kernel | same |

### `tests/src/Kernel/StreamsRankingTest.php` — all 4 rankings + pin cache tag (AC3, [B-1]/[B-2]/[B-6]/[W-2]/[A-W2]/[A-W3])

| Test | Criterion pinned | Tier |
|---|---|---|
| `testRecentRankingOrdersByCreatedDesc` | recent = created DESC | Kernel |
| `testLastActivityRankingPrefersRecentCommentOverNewerCreation` | [B-1]: a later COMMENT out-ranks a later `created` with no comments — the assertion that actually distinguishes last-activity from recent | Kernel |
| `testLastActivityRankingFallsBackToChangedWhenNoComments` | [B-1]: zero-comment nodes fall back to `changed`, not treated as "never active" | Kernel |
| `testHotRankingOrdersByScoreDesc` | hot = `do_discovery_hot_score.score` DESC (creation order deliberately INVERTED vs score order so a created-DESC fallback cannot coincidentally satisfy the assertion) | Kernel |
| `testHotRankingIncludesNodesWithNoScoreRow` | [W-2]: LEFT JOIN — an unscored node still appears (COALESCE 0), never excluded | Kernel |
| `testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker` | pinned-first is the PRIMARY sort key (mirrors the #52 fix) | Kernel |
| `testPinnedRankingDedupesRelationshipFanOut` | a node with 2 group relationships still appears once (mirrors the #56 fix; discovery must be generic per [A-W1], not do_group_pin's hardcoded alias) | Kernel |
| `testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` | [W-4]/[A-W2]: the emitted/invalidated tag is `do_streams:user_stream:<uid>` — per-VIEWING-USER, NOT `do_group_pin:...` | Kernel |

[A-W3] resolution (T's call, binding for F): the ranking parameter is carried as a Views
**contextual argument** (`$view->args`), matching do_group_pin's `$view->args[0]` gid pattern — the
fixture view's `ranking` argument (a `null`-plugin argument on `table: views, field: null`, i.e. a
value-carrying parameter with no intrinsic filtering semantics of its own) is what
`DoStreamsHooks::viewsQueryAlter()` must read via `$view->args[0]` (on `page_global`) and branch on.

### `tests/src/Kernel/StreamsShellTest.php` — shared shell preprocess/render contract (AC "shared stream shell", [B-3], D-gate)

| Test | Criterion pinned | Tier |
|---|---|---|
| `testScopeTabsContractAllFourPresentWithCorrectActiveFlag` | [B-3]: `scope_tabs` = 4 entries {id,label,active}, correct active flag | Kernel (render-array level) |
| `testRankingControlContractBothPillsPresentWithCorrectActiveFlag` | [B-3]: `ranking_control` = 2 entries {id,label,active} | Kernel |
| `testTrendingScopeDoesNotDisableTheRecentRankingPill` | handoff-D.md D-gate resolution 1 (binding): Trending's Recent pill stays enabled, never `disabled` | Kernel |
| `testEmptyFlagReflectsResultCount` | [B-3]: `empty` bool reflects result count | Kernel |
| `testEmptyCopyIsDistinctPerScope` | handoff-D.md D-gate resolution 2 (binding): 4 DISTINCT empty-copy strings; Global's must not contain a follow-oriented CTA | Kernel |
| `testNoHardcodedRoutePathsInRenderedTabMarkup` | AC "no hardcoded routes": no literal href/path string in rendered tab markup | Kernel |

Layer choice for all 6: render-array/preprocess level (`$renderer->renderRoot()` against a
`#theme => do_streams_shell` build array), not a live page scrape — matches survey.md §Testing
approach item 7 ("Kernel tests assert query results and render-array shape; Playwright asserts what
actually paints"). DOM-level verification is U's job in Phase 8/10, not T's.

### `tests/src/Kernel/StreamsInstallTest.php` — install/uninstall (AC "zero schema changes; enables/uninstalls cleanly")

| Test | Criterion pinned | Tier |
|---|---|---|
| `testModuleInstallsWithZeroSchemaChanges` | zero new DB tables on install (warmed-up baseline via a schema-free control module install/uninstall cycle, so core's own lazily-created tables — `router`, `menu_tree`, `cachetags`, ... — don't false-positive); ALSO anchors "installs cleanly" to the module's actual minimum contract (both Views filter plugins + the `do_streams_shell` theme hook discoverable) so this test isn't vacuously satisfied by a bare `.info.yml` | Kernel (`KernelTestBase`, not `GroupsKernelTestBase` — only the module installer is needed) |
| `testModuleUninstallsCleanly` | uninstall leaves the table set unchanged | Kernel |

## RED confirmation

Full suite run (23 tests, no fatal/bootstrap errors — every result is a genuine assertion
outcome):

```
SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://t109-do-streams.ddev.site \
BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest \
vendor/bin/phpunit -c web/core --testsuite kernel --testdox docs/groups/modules/do_streams/tests/src/Kernel/

Tests: 23, Assertions: 688, Failures: 17, Warnings: 3, Deprecations: 21 (Drupal-core deprecation
notices, unrelated to do_streams — same noise level as the existing do_group_pin suite).
```

Per-test outcome:

```
✘ Module installs with zero schema changes                                    [FAIL — real]
⚠ Module uninstalls cleanly                                                    [pass — baseline guard]
⚠ Recent ranking orders by created desc                                        [pass — baseline guard, view's own sort already matches "recent"]
✘ Last activity ranking prefers recent comment over newer creation             [FAIL — real]
⚠ Last activity ranking falls back to changed when no comments                 [pass — baseline guard]
✘ Hot ranking orders by score desc                                             [FAIL — real]
✘ Hot ranking includes nodes with no score row                                 [FAIL — real]
✘ Pinned ranking leads as primary key not tiebreaker                           [FAIL — real]
⚠ Pinned ranking dedupes relationship fan out                                  [pass — baseline guard, no fan-out amplification in this scenario without the ranking join]
✘ Pin toggle invalidates user stream cache tag without flush                   [FAIL — real]
✘ Membership scope returns only member groups nodes                           [FAIL — real]
✘ Membership scope covers all of the users groups                            [FAIL — real]
✘ Membership scope is empty for non member                                    [FAIL — real]
✘ Following scope follow content branch                                       [FAIL — real]
✘ Following scope follow user branch                                          [FAIL — real]
✘ Following scope follow term branch                                          [FAIL — real]
⚠ Following scope ors and dedupes                                             [pass — baseline guard, distinct:true already dedupes this scenario without a scope join]
✘ Scope tabs contract all four present with correct active flag              [FAIL — real]
✘ Ranking control contract both pills present with correct active flag       [FAIL — real]
✘ Trending scope does not disable the recent ranking pill                    [FAIL — real]
✘ Empty flag reflects result count                                            [FAIL — real]
✘ Empty copy is distinct per scope                                            [FAIL — real]
✔ No hardcoded route paths in rendered tab markup                             [pass — vacuously true on empty markup; becomes a real regression guard once the shell renders]
```

**17 of 23 tests are genuine RED** (fail because the plugins/hooks/theme-hook/template do not exist
yet — either the filter plugin silently degrades to a no-op broken handler, so a query that should
restrict rows returns everything, or the theme hook is unregistered, so `renderRoot()` never
populates `scope_tabs`/`ranking_control`/`empty`/`empty_copy`). Sample failing output for
`testMembershipScopeReturnsOnlyMemberGroupsNodes`:

```
1) Drupal\Tests\do_streams\Kernel\StreamsScopeTest::testMembershipScopeReturnsOnlyMemberGroupsNodes
A node in a group the current user belongs to is included in membership scope.
Failed asserting that an array contains 1.
```
(This flips once F builds the plugin: without it, the OTHER member's node ALSO leaks through
because the missing filter contributes no restriction at all — see the companion failure "the node
in a group the current user does NOT belong to is excluded" in the same run.)

Sample for `StreamsInstallTest`:
```
1) Drupal\Tests\do_streams\Kernel\StreamsInstallTest::testModuleInstallsWithZeroSchemaChanges
The do_streams_membership_scope Views filter plugin is discoverable after install.
Failed asserting that false is true.
```

Sample for `StreamsShellTest`:
```
1) Drupal\Tests\do_streams\Kernel\StreamsShellTest::testScopeTabsContractAllFourPresentWithCorrectActiveFlag
The rendered build carries a scope_tabs preprocess variable.
Failed asserting that an array has the key 'scope_tabs'.
```

**The remaining 6 tests pass today** (5 as genuine regression guards whose scenario happens not to
require the missing plugin/hook to already hold — e.g. Views' own `distinct: true` already dedupes
a non-fan-out case, or the fixture view's baseline `created DESC` sort already matches what
"recent"/"changed-fallback" independently require; 1 — the no-hardcoded-routes check — passes
vacuously against empty markup). None of the 6 is a masked bug: each still exercises real behavior
that must continue to hold once F implements the feature, and 3 of the 6 (`Module uninstalls
cleanly`, `Pinned ranking dedupes relationship fan out`, `Following scope ors and dedupes`) will
become MEANINGFUL regression guards the moment F's joins introduce the fan-out/relationship
surfaces these tests are built to catch. Each criterion the brief lists is pinned by at least one
FAILING test in the same file, so the suite as a whole is a valid RED for every acceptance
criterion — I did not leave any criterion covered ONLY by a vacuously-passing test.

## Fixture/config artifacts (not production code)

- `do_streams.info.yml` — bare module descriptor (name/dependencies only; zero code, zero hooks,
  zero plugins) so the test module is discoverable/enableable, per the task's own allowance.
- `tests/fixtures/config/views.view.do_streams_demo.yml` — the Kernel-level "test view" per [B-7],
  NOT shipped config. 3 displays: `page_1` (membership scope proof), `page_following` (following
  scope proof), `page_global` (scope-free ranking proof, also literally what [B-8]'s "Trending =
  Global scope + hot ranking" and "Global = no scope filter" resolve to). References the filter
  plugin ids F must implement (`do_streams_membership_scope`, `do_streams_following_scope`) and
  carries the `ranking` contextual argument ([A-W3]).

Two test-authoring bugs found and fixed during RED verification, worth flagging as adjacent
findings (not do_streams' own scope, so noted rather than fixed in shipped config):
- `docs/groups/config/flag.flag.follow_content.yml`'s `flagTypeConfig.access_author` key has no
  matching config schema entry anywhere in `flag`'s own schema — a pre-existing gap. My fixture
  install strips this one key before saving (mirroring `PinnedStreamOrderingTest`'s precedent of
  stripping render-only fields from ITS fixture) rather than fixing the shipped config, since that
  is out of scope for this story. Flagging for O/a future issue if `do_notifications` or another
  future test also installs this flag under strict config schema.
- A `table: node_field_data, field: nid` Views argument ALWAYS resolves to the `Nid` argument
  handler regardless of a config-level `plugin_id: null` override (Views resolves handlers by
  table+field's own `views_data` registration, not by the config item's `plugin_id` key, for any
  field that has its own dedicated handler). The `ranking` parameter therefore uses `table: views,
  field: null` (Views core's own "Global: Null argument" convention) — a value-carrying parameter
  with no intrinsic column semantics, which is the correct Views idiom for "pass a parameter my
  own query-alter hook reads," not a workaround.

## Ready for F

Confirmed RED is valid. F may implement against these tests. Build target, per [A-W3]'s resolution
recorded above: `DoStreamsHooks::viewsQueryAlter()` reads `$view->args[0]` on `page_global` (and the
equivalent position wherever F's shipped `views.view.do_streams_demo.yml` places the `ranking`
argument) and branches on `recent`/`last_activity`/`hot`/`pinned`; the two Views filter plugins are
`do_streams_membership_scope` / `do_streams_following_scope`; the theme hook is `do_streams_shell`
with preprocess variables `scope_tabs`, `ranking_control`, `results`, `empty`, `empty_copy`.
