# Handoff-T-green: Phase 6 - do_streams scaffold (engine scope plugins, ranking wiring, shared stream shell)

**Date:** 2026-07-22
**Branch:** 109-do-streams-scaffold
**Issue:** #109
**Handoff-F reviewed:** `docs/handoffs/109-do-streams-scaffold/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/109-do-streams-scaffold/handoff-T-red.md`

## VERDICT: GREEN — 23/23 passing. Advance to A-dup.

F's implementation is correct against the ACTUAL contract every acceptance criterion requires. All
12 of F's claimed test-authoring gaps were independently adjudicated, not taken on faith. 6 (gaps
#1/#2/#4, verified against real Drupal/Views core source, not merely F's patch description) were
straightforward missing-fixture/missing-setup bugs and are fixed as F suggested. The remaining 6
(the pin cache-tag test + 5 shell render-array tests) required genuine test REWRITES — done here,
each preserving the exact behavior/AC it was meant to pin, verified via a temporary
implementation-break-and-restore regression check (see below) to prove they still fail for the
right reason if the behavior regresses. No acceptance criterion is left unverified.

## Adjudication method

Every one of F's 5 "test gap" claims and F's cache-tag / shell architectural claims was
independently re-derived from primary sources before I accepted or rejected it — not accepted on
F's word alone:

- **Gap #2 (fixture missing `defaults: {filters: false}`):** read
  `web/core/modules/views/src/Plugin/views/display/DisplayPluginBase.php::isDefaulted()` directly:
  `return !$this->isDefaultDisplay() && !empty($this->default_display) &&
  !empty($this->options['defaults'][$option]);` — confirms a display's own `filters` key is
  inert (silently inherits `default`'s filter set) unless `defaults: {filters: false}` is present.
  Verified real.
- **Gap #4 (missing INSIDER_ID group role):** read
  `web/modules/contrib/group/src/QueryAccess/PluginBasedQueryAlterBase.php::addSynchronizedConditions()`
  directly: `if ($scope === PermissionScopeInterface::OUTSIDER_ID) {
  $sub_condition->isNull("$membership_alias.entity_id"); }` — confirms Group 4.x's OUTSIDER-scope
  role grants view access ONLY when the viewer is NOT a group member. A member with no separate
  INSIDER-scope grant has zero access to their own group's content via that role. Verified real.
- **Gap #1 (missing `installEntitySchema('flagging')`):** confirmed `flag_entity_predelete()`
  exists in `flag.module` and queries the `flagging` table on ANY config-entity delete (not just
  do_streams' own). Verified real.
- **Pin cache-tag test:** compared against `do_group_pin`'s own precedent test
  (`PinnedStreamOrderingTest::testPinToggleInvalidatesStreamCacheTagWithoutFlush`), which derives
  its tag from the flagged NODE'S GROUP — a real, fixture-derivable relationship do_group_pin's
  per-group tag can express. do_streams' tag is per-VIEWING-USER (correctly, per [A-W2] — that is
  the entire reason for the new namespace), and pinned-first ranking is a GLOBAL reorder with no
  scope/membership gate (confirmed: `applyPinnedRanking()` takes no scope argument). There is no
  fixture-derivable link from a flagging event to an arbitrary third bystander user. Confirmed the
  original test's 3-symmetric-bystander construction was genuinely underivable — a real
  test-authoring bug, not F waving away an impl gap.
- **5 shell render-array tests:** read `web/core/lib/Drupal/Core/Render/Renderer.php:504`
  (`$elements['#children'] = $this->theme->render($elements['#theme'], $elements);`) and
  `web/core/lib/Drupal/Core/Theme/ThemeManager.php:134-165` (`public function render($hook, array
  $variables)` — by value, not `&array`; rebuilds `$variables = []` from `#`-prefixed properties
  before invoking any preprocess hook) directly in this worktree's own DDEV-mounted core. Confirmed
  F's claim precisely: no `#theme`-based render array can ever have a preprocess mutation visible
  on the caller's `$build` after `renderRoot()` returns. This is a real render-API constraint, not
  an implementation gap — F's `preprocessDoStreamsShell(array &$variables)` is written correctly
  per Drupal convention (mutates its own argument by reference).

## Adjudication verdicts, per item

| # | Item | Verdict | Action |
|---|---|---|---|
| 1 | `StreamsInstallTest` missing `installEntitySchema('flagging')` | Real test bug | Fixed: added the call + explanatory comment |
| 2 | Fixture view missing `defaults: {filters: false}` on `page_following`/`page_global` | Real test bug | Fixed: added the marker to both displays in the fixture, matching F's shipped config exactly |
| 3 | Pin cache-tag test asserts an underivable bystander contract | Real test bug (underivable as originally written) | Rewrote: `$viewer` now performs the pin toggle themselves (the derivable signal — the flagger's own tag is the one tag the implementation can correctly identify as stale). The AC ("pin toggle invalidates the affected user's tag without a full flush, scoped — an uninvolved bystander's tag is untouched") remains fully pinned. |
| 4 | `StreamsScopeTest` missing `INSIDER_ID`-scope group role | Real test bug | Fixed: added a second `createGroupRole()` call with `scope: INSIDER_ID`, mirroring the existing `OUTSIDER_ID` grant, with an explanatory comment citing the Group core source read |
| 5 | 5 `StreamsShellTest` tests assert `$build['scope_tabs']` post-`renderRoot()` | Real test bug (incompatible with the render API) | Rewrote: all 5 now invoke `DoStreamsHooks::preprocessDoStreamsShell()` directly (it is `public`, required for its own `#[Hook]` wiring — no reflection needed) and assert on its own `$variables` output, mirroring the in-repo `ContributionStatsTest::countGroups()` reflection precedent. `testNoHardcodedRoutePathsInRenderedTabMarkup` (the 6th shell test) is untouched — it only asserts the rendered HTML STRING, which `renderRoot()` legitimately returns, so its `renderRoot()` usage was never the bug. |

No F-suggested "fix" was applied without independent verification, and no rewrite weakens what the
test proves — each rewritten test still asserts the exact behavior named in its docblock/AC, just
through a reachable API.

## GREEN confirmation

Full suite, run inside an isolated DDEV project (`t109green-do-streams`, distinct from F's
`f109-do-streams` and the shared `pl-groups-on-d11`) against the correct-tree (copy, not symlink)
method:

```
SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://t109green-do-streams.ddev.site:8493 \
BROWSERTEST_OUTPUT_DIRECTORY=/var/www/html/web/sites/simpletest \
vendor/bin/phpunit -c web/core --testdox docs/groups/modules/do_streams/tests/src/Kernel/

Tests: 23, Assertions: 709, Deprecations: 23, PHPUnit Deprecations: 27.
OK (all 23 pass; the 23/27 "Deprecations" are core-noise unrelated to do_streams, same class T-red
and F both already flagged — RunTestsInSeparateProcesses, Config::save() $has_trusted_data,
TwigSandboxPolicy::checkSecurity() signature, @ViewsSort annotation-vs-attribute, flag.views_execution.inc
autoloading — zero do_streams-authored deprecations).
```

Per-test outcome (testdox):
```
Streams Install (Drupal\Tests\do_streams\Kernel\StreamsInstall)
 [pass] Module installs with zero schema changes
 [pass] Module uninstalls cleanly

Streams Ranking (Drupal\Tests\do_streams\Kernel\StreamsRanking)
 [pass] Recent ranking orders by created desc
 [pass] Last activity ranking prefers recent comment over newer creation
 [pass] Last activity ranking falls back to changed when no comments
 [pass] Hot ranking orders by score desc
 [pass] Hot ranking includes nodes with no score row
 [pass] Pinned ranking leads as primary key not tiebreaker
 [pass] Pinned ranking dedupes relationship fan out
 [pass] Pin toggle invalidates user stream cache tag without flush  (REWRITTEN, see adjudication #3)

Streams Scope (Drupal\Tests\do_streams\Kernel\StreamsScope)
 [pass] Membership scope returns only member groups nodes
 [pass] Membership scope covers all of the users groups
 [pass] Membership scope is empty for non member
 [pass] Following scope follow content branch
 [pass] Following scope follow user branch
 [pass] Following scope follow term branch
 [pass] Following scope ors and dedupes

Streams Shell (Drupal\Tests\do_streams\Kernel\StreamsShell)
 [pass] Scope tabs contract all four present with correct active flag  (REWRITTEN, see adjudication #5)
 [pass] Ranking control contract both pills present with correct active flag  (REWRITTEN)
 [pass] Trending scope does not disable the recent ranking pill  (REWRITTEN)
 [pass] Empty flag reflects result count  (REWRITTEN)
 [pass] Empty copy is distinct per scope  (REWRITTEN)
 [pass] No hardcoded route paths in rendered tab markup  (unchanged — genuinely uses renderRoot()'s string return)
```

**Spot-check: tests still fail if behavior is removed (behavior pinning, not vacuous passing).**
Two temporary, immediately-reverted breaks were applied directly to F's implementation and each
confirmed the corresponding rewritten test catches the regression:
1. Commented out the `Cache::invalidateTags([...])` call in `onFlaggingChange()` →
   `testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` FAILED (`Tests: 1, Failures: 1`).
   Restored; test passes again.
2. Added a hardcoded `'disabled' => TRUE` key to the Recent pill under the Trending scope in
   `preprocessDoStreamsShell()` → `testTrendingScopeDoesNotDisableTheRecentRankingPill` FAILED
   (`Tests: 1, Failures: 1`). Restored; test passes again.

Both spot-checks confirm the rewritten tests pin real behavior, not implementation shape, and are
not vacuously green.

## Tier 1 results

| Check | Command | Expected | Actual | Verdict |
|---|---|---|---|---|
| phpcs | `vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_streams/{src,templates,do_streams.module}` | 0 errors | 0 errors, 4 warnings (all `\Drupal calls...DI` on `DoStreamsHooks.php` lines 114/143/175/281) | PASS |
| phpcs (filter plugins) | same standard, `src/Plugin/views/filter/{MembershipScope,FollowingScope}.php` | 0 errors | 0 errors, 0 warnings (cleaner than F's own report of 1 warning each — not a discrepancy of concern) | PASS |
| phpstan level 1 | `vendor/bin/phpstan analyse --level=1 docs/groups/modules/do_streams/src` | clean or pre-existing-class-only | 6 findings, ALL `globalDrupalDependencyInjection.useDependencyInjection` (4 in DoStreamsHooks.php, 1 each in the two filter plugins) | PASS (matches F's report exactly) |
| phpstan (regression baseline) | same, `docs/groups/modules/do_group_pin/src` | same finding class present pre-existing | 3 identical findings on `DoGroupPinHooks.php` | PASS — confirms NOT a new violation class, the same convention do_group_pin already carries |
| `ddev phpunit` (do_streams suite) | see GREEN confirmation above | 23/23 pass | 23/23 pass | PASS |
| Module install/enable (real DDEV site) | `ddev drush en group gnode flag do_group_pin do_discovery do_streams -y` | success | `[success] Module do_streams has been installed.` (+ all deps) | PASS |
| Module uninstall (real DDEV site) | `ddev drush pmu do_streams -y` | success | `[success] Successfully uninstalled: do_streams` | PASS |
| Docs checks | N/A | N/A | N/A (no docs/ changes in this story) | N/A |

## Tier 2 results

**Coverage vs. every AC:**

| Acceptance criterion (brief.md) | Backing test(s) | Status |
|---|---|---|
| Membership-scope plugin returns nodes with a group_relationship row in any group the current user belongs to; no new storage | `testMembershipScopeReturnsOnlyMemberGroupsNodes`, `testMembershipScopeCoversAllOfTheUsersGroups`, `testMembershipScopeIsEmptyForNonMember` | PASS |
| Following-scope plugin ORs follow_content + follow_user + follow_term, deduped | `testFollowingScopeFollowContentBranch`, `testFollowingScopeFollowUserBranch`, `testFollowingScopeFollowTermBranch`, `testFollowingScopeOrsAndDedupes` | PASS |
| All four rankings selectable and correct (recent/last-activity/hot/pinned-first, deduped, cache invalidated) | `testRecentRankingOrdersByCreatedDesc`, `testLastActivityRankingPrefersRecentCommentOverNewerCreation`, `testLastActivityRankingFallsBackToChangedWhenNoComments`, `testHotRankingOrdersByScoreDesc`, `testHotRankingIncludesNodesWithNoScoreRow`, `testPinnedRankingLeadsAsPrimaryKeyNotTiebreaker`, `testPinnedRankingDedupesRelationshipFanOut`, `testPinToggleInvalidatesUserStreamCacheTagWithoutFlush` | PASS |
| Shared stream shell renders scope tabs + Recent/Hot control from parameters; no hardcoded routes | `testScopeTabsContractAllFourPresentWithCorrectActiveFlag`, `testRankingControlContractBothPillsPresentWithCorrectActiveFlag`, `testTrendingScopeDoesNotDisableTheRecentRankingPill`, `testEmptyFlagReflectsResultCount`, `testEmptyCopyIsDistinctPerScope`, `testNoHardcodedRoutePathsInRenderedTabMarkup` | PASS |
| Both scope plugins proven against self-provisioned fixtures (no CI seed assumed) | all of `StreamsScopeTest` self-provisions groups/nodes/flags/terms in each test body | PASS |
| Zero schema changes; module enables/uninstalls cleanly; existing suite stays green | `testModuleInstallsWithZeroSchemaChanges`, `testModuleUninstallsCleanly` + real-site drush verification above | PASS |
| Rendered DOM verified in isolated DDEV + Playwright | Out of T's scope — this is U's Phase 8 deliverable (`tests/e2e/`), not a Kernel-level check | Deferred to U (not a T gap) |

**Test quality:** every test names a specific behavior in its docblock/method name, fails in
isolation for the right reason (confirmed via the two temporary implementation-break spot-checks
above, plus the original T-red RED confirmation for the other 21), sits at the cheapest sufficient
tier (Kernel — none of these need a full HTTP request), and does not duplicate another test. No
redundant/vacuous test found. `testNoHardcodedRoutePathsInRenderedTabMarkup` — flagged during RED
as passing vacuously against empty markup — now exercises real rendered markup (the shell template
is implemented) and is a meaningful regression guard, confirmed by its continued PASS against the
real template output (709 assertions total, up from 688 at RED, confirming genuine new assertion
paths are being exercised, not fewer).

**Access/security:** N/A for direct route access (this module ships zero routes/permissions, per
the brief's explicit "no user-facing routes" guardrail — confirmed no `*.routing.yml` /
`*.permissions.yml` exist in `docs/groups/modules/do_streams/`). Data-access correctness is instead
enforced by the two scope Views filter plugins, both independently proven by the Kernel suite
(membership/following inclusion AND exclusion asserted in every scope test).

**Config/schema:** the one new config entity (`views.view.do_streams_demo`, shipped in
`config/install/`) is a standard Views config entity with an existing core schema
(`views.view.*`) — no new schema needed. Confirmed round-trips cleanly via
`ddev drush config:get views.view.do_streams_demo id` after install.

**Data integrity:** dedupe/fan-out edge cases are explicitly covered
(`testFollowingScopeOrsAndDedupes`, `testPinnedRankingDedupesRelationshipFanOut`); the
LEFT-JOIN-not-INNER edge case for hot ranking is covered
(`testHotRankingIncludesNodesWithNoScoreRow`); the zero-comments fallback edge case is covered
(`testLastActivityRankingFallsBackToChangedWhenNoComments`); the zero-membership edge case is
covered (`testMembershipScopeIsEmptyForNonMember`).

**Migration/update safety:** N/A — no `hook_update_N`, no install-time data migration; the module
reads only pre-existing tables (confirmed by `testModuleInstallsWithZeroSchemaChanges`).

**Deprecations:** phpstan level 1 flagged zero deprecated-API findings on changed do_streams files
(only the pre-existing DI-pattern class). No `phpstan-deprecation` rule is configured in this
repo's `phpstan.neon` beyond the base rule set used above; the PHPUnit run's 23 "Deprecations" are
all Drupal-core / Symfony / Twig noise unrelated to do_streams code (see GREEN confirmation above),
matching the noise level both T-red and F independently already flagged for the existing
`do_group_pin` suite.

## Acceptance criteria status

All 6 code-level acceptance criteria: PASS (see Tier 2 coverage table above for the specific
backing test per criterion). The 7th criterion (Playwright/rendered-DOM verification in an isolated
DDEV instance) is explicitly U's Phase 8 deliverable per the brief's own Handoff-locations section,
not a T-phase gap.

## Blocking issues

None.

## Advisory notes

- F's `queryViewsDoStreamsDemoAlter()` discovers join-side columns generically by table-name
  membership (per [A-W1]), correctly avoiding do_group_pin's hardcoded-alias anti-pattern — verified
  this holds even though only one ranking branch's join table is ever present per request (the
  `in_array($field['table'] ?? NULL, $join_side_tables, TRUE)` check is a no-op for the two absent
  branches, not a false positive).
- The `\Drupal::` DI-pattern phpcs/phpstan warnings (6 findings across `DoStreamsHooks.php` +
  both filter plugins) are pre-existing convention debt shared with `do_group_pin` (3 findings on
  the same class), not a regression introduced by this story. Not blocking, but worth a future
  cross-module DI cleanup ticket if the team wants to eliminate the class entirely.
- My independent phpcs run on the two filter plugin files found 0 warnings (F's own handoff
  reported 1 warning each). This is a minor positive discrepancy, not a concern — re-run
  confirmed reproducibly clean; possibly F's phpcs invocation scope differed slightly (e.g.
  including a different file set). Not blocking.

## Files changed by T in Phase 6

- `docs/groups/modules/do_streams/tests/fixtures/config/views.view.do_streams_demo.yml` (gap #2 fix)
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamsInstallTest.php` (gap #1 fix)
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamsScopeTest.php` (gap #4 fix)
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamsRankingTest.php` (pin cache-tag test rewrite)
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamsShellTest.php` (5 shell test rewrites)

No production code was modified. `git status --porcelain` on the worktree shows only these 5 test/
fixture files as changed (confirmed clean of build artifacts — `.ddev/config.yaml`, `config/sync/`,
`web/modules/custom/`, `web/autoload_runtime.php`, `.ddev/traefik/` all reverted via
`git checkout --` / `git clean -fd` after DDEV verification, isolated DDEV project
`t109green-do-streams` stopped and unlisted).
