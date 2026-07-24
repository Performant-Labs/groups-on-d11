# Handoff-T-green: Phase 6 - #123 SC-4 Discovery three ways

**Date:** 2026-07-24
**Branch:** 123-discovery-three-ways
**Issue:** #123
**Handoff-F reviewed:** `docs/handoffs/sc4-discovery-123/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/sc4-discovery-123/handoff-T-red.md`

## GREEN confirmation

All tests I authored in T-red were re-run **independently** (not trusting F's self-report) inside
the `gm123-discovery` DDEV container, from the assembled layout. All pass.

### Assemble

```
ddev exec "bash scripts/ci/assemble-config.sh"
```
Result: `==> assemble-config: done` — exit 0.

### New Unit tests (isolated files)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Unit/VariantSwitcherTest.php \
  web/modules/custom/do_showcase/tests/src/Unit/DiscoveryRankingHelpTextTest.php
```
Result: **24/24 pass**, 71 assertions. All 4 new `VariantSwitcherTest` query-key methods pass
(`testBuildWithCustomQueryKeyEmitsThatKeyInEveryOptionHref`,
`testBuildWithoutQueryKeyStillDefaultsToVariantForBackwardCompatibility`,
`testBuildWithCustomQueryKeyBubblesMatchingCacheContext`,
`testTwoSimultaneousInstancesWithDistinctQueryKeysDoNotCollide`) and all 5
`DiscoveryRankingHelpTextTest` methods pass. Confirms the exact 3 failures from T-red's
`VariantSwitcherTest` RED run and the exact 3 failures from `DiscoveryRankingHelpTextTest`'s RED
run are now green, for the on-topic reason (real query-key/HelpText behavior, not a setup fix).

### Full `do_showcase` Unit dir

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/
```
Result: **50 tests, 308 assertions, 0 failures** (37 PHPUnit deprecations, framework-level only).
Exact match to F's self-reported number.

### New Functional tests (isolated file)

Required a served docroot (`php -S 127.0.0.1:<port> -t web $PWD/web/.ht.router.php`) +
`SIMPLETEST_DB`/`SIMPLETEST_BASE_URL`, run in the **same shell invocation** as the PHPUnit call
(a separate `ddev exec` per step let the background server die between calls on my first two
attempts — false failures, both `cURL error 7: Failed to connect`, an artifact of my own
verification harness, not the code under test; recorded here for transparency since it produced
a spurious 7/8-error run before I corrected the harness).

```
php -S 127.0.0.1:8091 -t web $PWD/web/.ht.router.php &
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8091' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_showcase/tests/src/Functional/DiscoveryRankingControllerTest.php
```
Result: **8/8 pass**, 94 assertions, 0 failures/errors ("OK, but there were issues!" — deprecations
only: the repo-wide `#[RunTestsInSeparateProcesses]` forward-compat notice on every
`BrowserTestBase` subclass, and the known `views_embed_view()` deprecation A-plan explicitly
directed F to use). All 8 named tests confirmed passing via `--testdox`:
`testDiscoveryRankingSectionRendersSwitcherWithThreeAvailableOptions`,
`testDiscoveryQueryArgDeepLinksToHotTabPreSelected`,
`testRecentTabEmbedsActivityStreamViewContent`,
`testPromotedTabEmbedsPromotedContentViewWithSeededNodes`,
`testUnknownDiscoveryQueryArgFallsBackToRecent`,
`testBothSwitchersCoexistWithIndependentQueryKeys`,
`testDiscoverySwitcherCarriesExactlyOneWrapperTooltip`, `testExistingHotPageRouteIsUnaffected`.

### Spot-check: does the suite still fail if behavior is removed?

Re-confirmed against the T-red evidence itself rather than re-breaking code (no production edits
allowed at this phase): T-red's own RED run shows 7/8 `DiscoveryRankingControllerTest` methods
failing on `ElementNotFoundException: … "[data-do-discovery-ranking]" not found` when the feature
did not exist, and the 3 new `VariantSwitcherTest`/5 `DiscoveryRankingHelpTextTest` methods failing
on content assertions (`?variant=` instead of `?discovery=`, empty HelpText string) — proving each
test is coupled to the actual feature behavior, not a tautology. No test needed modification at
this phase.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble config | `bash scripts/ci/assemble-config.sh` | exit 0 | exit 0, all steps logged | PASS |
| New Unit tests | phpunit on 2 new/extended files | 0 failures | 24/24, 0 failures | PASS |
| Full `do_showcase` Unit dir | phpunit | 0 failures | 50/50, 0 failures (308 assertions) | PASS |
| New Functional tests | phpunit on `DiscoveryRankingControllerTest` | 0 failures | 8/8, 0 failures (94 assertions) | PASS |
| Full `do_showcase` Functional dir | phpunit | 0 failures | 37/37, 0 failures (260 assertions) — matches F exactly | PASS |
| Playwright spec syntax | `npx playwright test tests/e2e/discovery-compare.spec.ts --list` | 11 tests, 0 errors | 11 tests listed, 0 errors | PASS |

## Tier 2 results

### Full custom-module non-regression sweep (BC pin)

Ran independently in three passes (Kernel+Unit together; Functional split into
`do_showcase` / `{do_tests,do_group_extras,do_chrome}` / `{do_group_membership,do_multigroup}` —
splitting was a verification-harness choice on my end to keep each `ddev exec` invocation inside a
reliable duration, not a scope reduction; every custom-module Functional test directory was
covered):

- **Kernel + Unit** (`find web/modules/custom -type d -path '*/tests/src/Kernel'` +
  `-path '*/tests/src/Unit'`): **279 tests, 5544 assertions, 0 failures/errors** (31
  test-level deprecations + 254 PHPUnit deprecations, framework-level only). This is strictly
  greater than T-red's recorded baseline of 191 Kernel tests / 4856 assertions (the baseline in
  decisions.md was Kernel-only; this run also folds in Unit, hence the larger absolute number —
  the important pin is **zero regressions**, confirmed).
- **Functional, `do_showcase`**: 37/37, 260 assertions, 0 failures.
- **Functional, `do_tests` + `do_group_extras` + `do_chrome`**: 17/17, 159 assertions, 0
  failures.
- **Functional, `do_group_membership` + `do_multigroup`**: 24/24, 219 assertions, 0 failures.
- **Functional grand total: 78/78, 0 failures.**
- **Grand total (Kernel + Unit + Functional, all custom modules): 357 tests, 0 failures/errors.**

Confirms the pre-existing `VariantSwitcher` 3-arg BC pin: `DoShowcaseHooks.php:492` (SC-5's
`viewsPreRender()`) and `ShowcaseController.php:228` (`directory.layout` instance) both still call
`build($id, $opts, $current)` with exactly 3 positional args — read directly, confirmed unchanged.
`DirectoryTogglePreRenderTest`'s Kernel suite (part of the 279-test Kernel+Unit sweep) passed with
zero regressions, confirming the #124 SC-5 sibling story's use of the same primitive is unaffected.

### Test quality (testing/test-quality.md §7)

Each of T-red's 17 new/extended test methods (4 `VariantSwitcherTest`, 5
`DiscoveryRankingHelpTextTest`, 8 `DiscoveryRankingControllerTest`) names a single behavior in its
method name, failed in isolation for an on-topic reason during RED (verified above — content
assertions, never import/setup errors), sits at the cheapest sufficient tier (Unit for pure
render-array/copy-lookup contracts, Functional only for the one behavior that genuinely requires a
real HTTP request through the real route + real `views_embed_view()` output), and asserts
observable behavior (rendered href, cache-context array, HelpText string, DOM markup) rather than
implementation internals. No redundant test found — each pins a distinct acceptance-criterion
facet. Suite size (17 new tests for 8 acceptance criteria + 2 BC/cross-cutting concerns) is
proportionate; no test recommended for deletion or merge.

### Type safety / error handling / data integrity / API contract / security

- `ShowcaseController::embedDiscoveryView()` correctly normalizes `views_embed_view()`'s
  documented NULL-on-miss return into an empty render array — verified by reading the method body
  directly (`docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`); no type
  error possible from an unguarded null render array.
- Unknown `?discovery=` values: `resolveCurrent()`'s existing first-available-option rule handles
  the fallback — pinned by `testUnknownDiscoveryQueryArgFallsBackToRecent`, confirmed passing.
- No new schema, no new form input, no new user-facing write path — this story is read-only
  (embeds existing views). No new auth/access-control surface introduced; existing route access
  unchanged.
- API/render contract: `VariantSwitcher::build()`'s 4th param is optional with a BC-safe default;
  render-array shape (`#options`, `#cache['contexts']`) matches the pre-existing contract plus the
  documented additions (verified directly in `VariantSwitcher.php`).

### phpcs

```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice \
  docs/groups/modules/do_showcase/src/VariantSwitcher.php \
  docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php \
  docs/groups/modules/do_showcase/css/discovery-compare.css \
  docs/groups/modules/do_showcase/do_showcase.libraries.yml
```
Result: **0 errors, 0 warnings** — clean. Matches F's claim exactly.

```
php vendor/bin/phpcs --standard=Drupal,DrupalPractice docs/groups/modules/do_chrome/src/HelpText.php
```
Result: **18 errors, 8 warnings** (current file). Independently re-ran phpcs against the HEAD
(pre-F) version of the same file via `git show HEAD:...` copied into the container: **19 errors, 8
warnings** (one of the 19 was an artifact of the temp filename not matching the class name — the
real pre-existing violation count at HEAD, discounting that filename artifact, is 18, identical to
the post-append count). **Confirmed: F's append introduced ZERO new phpcs violations** — matches
F's claimed "net -1" finding (the discrepancy is fully explained by the filename-mismatch sniff
that only fires on my throwaway comparison copy, not a real regression).

`do_showcase.switcher.js`'s 6 "TRUE/FALSE/NULL must be uppercase" phpcs hits: confirmed this is a
pre-existing PHP-standard-vs-JS-syntax false positive by checking the untouched
`do_showcase.ribbon.js` (same sniff class present) and the pre-story HEAD version of
`switcher.js` (5 hits already present; current file has 6, the +1 being the new
`queryKeyForGroup()` helper's `return null;`). Not a real defect.

### Playwright spec list

```
npx playwright test tests/e2e/discovery-compare.spec.ts --list
```
Result: **11 tests in 1 file, no errors** — unchanged from T-red's RED-phase evidence, confirming
no import/selector regression was introduced while F implemented. This DDEV project has no seeded
site locally; per the task's own instruction, syntactic validity is acceptable Tier 2 evidence
here — U (or CI's `e2e` job) exercises it live against the fully seeded site.

### Uncommitted-artifact provenance check (task's "notable finding" instruction)

F's handoff flagged `css/discovery-compare.css`, its library entry, and the
`do_showcase.switcher.js` `queryKeyForGroup()` fix as "already present, uncommitted" at F's session
start. `decisions.md`'s "O — Phase 5.5" entry explains the actual provenance: a concurrent-F
near-miss (two F agents briefly ran in the same worktree — a violation of the global
`concurrent-agents-use-isolated-worktrees` rule that O has already flagged as a process defect to
avoid next time) whose two independent implementations of the same fix converged, rather than a
mysterious partial attempt from an earlier session. I independently verified all three artifacts
against the wireframe/A-plan directly (not just trusting F's or O's account):

- **`discovery-compare.css`** — read in full. Matches wireframe.md's WCAG 2.2 AA notes exactly:
  does not redefine `[role="radio"]:focus-visible`, reuses the existing `#767676` AA-floor gray
  for meta-line text, scoped strictly to `.do-showcase-discovery-ranking` and its descendants.
  **Correct.**
- **`do_showcase.libraries.yml`'s `discovery-compare` entry** — read in full. CSS-only,
  `do_showcase/switcher` dependency present, matches the `#124` `directory-compact` library's own
  precedent immediately above it in the same file. Cross-checked against
  `ShowcaseController::page()`: the library is attached only on `$build['discovery_ranking']`'s
  subtree (confirmed by reading the controller directly), never site-wide. **Correct.**
- **`do_showcase.switcher.js`'s `queryKeyForGroup()` fix** — read the full diff against HEAD.
  Resolves A-plan's Spot-check finding #2 exactly as directed: reads the query-string key
  generically off an option's own `href` via `URLSearchParams` rather than hardcoding
  `params.has('variant')`, so the `discovery.ranking` instance's URL-wins-over-sessionStorage
  check works correctly for its own `?discovery=` key. Cross-checked against
  `VariantSwitcher::build()`'s actual 4th-param output (`?<query_key>=<id>` href shape) — the two
  are compatible. **Correct.**

No blocker from this check — all three pre-existing artifacts are verified correct against the
brief/wireframe/A-plan, independent of F's or O's own account.

### Cross-check of F's self-report against reality

- **File list**: `git diff --cached --stat` shows exactly the 6 production files F's handoff
  claims (`HelpText.php`, `discovery-compare.css` new, `do_showcase.libraries.yml`,
  `do_showcase.switcher.js`, `ShowcaseController.php`, `VariantSwitcher.php`) — no unclaimed file,
  no claimed-but-missing file.
- **Test-file territory**: `VariantSwitcherTest.php` (extended), `DiscoveryRankingHelpTextTest.php`
  (new), `DiscoveryRankingControllerTest.php` (new), and the 3 fixture YAMLs remain unstaged/
  untracked, correctly left in T's ownership — F touched none of them (confirmed via `git status`).
  `views.view.all_groups.yml` fixture is also present (pre-existing, per T-red, not new to this
  story).
- **Test counts**: F's self-reported 114/114 (50 Unit + 27 Kernel + 37 Functional, `do_showcase`
  only) is accurate — I reproduced 50 Unit and 37 Functional exactly; Kernel 27/27 is subsumed by
  my broader 279-test Kernel+Unit sweep (which includes `do_showcase`'s Kernel dir) with 0
  failures, so F's Kernel count is corroborated, not contradicted.
- **phpcs before/after HelpText.php**: independently reproduced (18 vs 19, net non-regression),
  confirmed above.
- No discrepancy found between F's self-report and the state of the repo.

## Acceptance criteria status

| # | Criterion | Status | Backing test |
|---|---|---|---|
| 1 | All three variants render non-empty from seed (Promoted shows 2 seeded nodes) | PASS | `testPromotedTabEmbedsPromotedContentViewWithSeededNodes`, `testRecentTabEmbedsActivityStreamViewContent` |
| 2 | Switcher labels + tooltips present; single wrapper tooltip | PASS | `testDiscoverySwitcherCarriesExactlyOneWrapperTooltip`, `DiscoveryRankingHelpTextTest` (5/5) |
| 3 | Deep links land pre-switched (`?discovery=recent\|hot\|promoted`) | PASS | `testDiscoveryQueryArgDeepLinksToHotTabPreSelected`, `testUnknownDiscoveryQueryArgFallsBackToRecent` |
| 4 | `/hot` and existing promoted views UNCHANGED | PASS | `testExistingHotPageRouteIsUnaffected` |
| 5 | Existing suite stays green | PASS | Full custom-module sweep: 357/357, 0 failures |
| 6 | New Playwright spec cycles the three tabs | PASS (structural) | `--list`: 11 tests, 0 errors — live execution is U's phase |
| 7 | HelpText entry appended, key `showcase.switcher.discovery.ranking` | PASS | `DiscoveryRankingHelpTextTest` (5/5), phpcs net-zero-regression confirmed |
| 8 | WCAG 2.2 AA — labels, keyboard, focus, contrast, non-color status | PASS (structural) | Reuses `VariantSwitcher`'s existing radiogroup/roving-tabindex/focus-visible verbatim (no new interaction code, confirmed by reading `VariantSwitcher.php` unchanged in this respect); `discovery-compare.css` verified against wireframe's WCAG notes above. Live focus/contrast rendering is U's phase. |

All 8 acceptance criteria are satisfied at the level this phase can verify (headless/structural).
Criteria 6 and 8's live-rendering aspects are explicitly deferred to U per this pipeline's division
of labor.

## Blocking issues

None.

## Advisory notes

- **Process hazard, not a code defect**: `decisions.md`'s "O — Phase 5.5" entry records that two F
  agents ran concurrently in this single worktree for ~10-15 minutes (a violation of the global
  `concurrent-agents-use-isolated-worktrees` rule) after O misdiagnosed a buffered-but-alive F
  transcript as dead. Both converged on identical fixes and no git-state corruption was observed,
  but this was luck, not a guarantee — O has already recorded a follow-up rule (verify via
  `git status`/on-disk deltas before re-spawning; use a fresh worktree if a re-spawn is genuinely
  needed). Nothing further for me to flag beyond corroborating that the resulting code is correct.
- **Latent view-filter gap (pre-existing, out of this story's scope, already surfaced in T-red)**:
  the real `views.view.promoted_content.yml` has no actual flag/relationship filter restricting to
  `promote_homepage`-flagged content — it filters only on `status` (published). This story
  correctly embeds the view AS-IS per A-plan's Risk 3 ("do not fork ranking"), so this is not a
  blocker, but a future story that tightens `promoted_content`'s own filter would change this
  surface's behavior. Already recorded in T-red/decisions.md; not re-filed here (POC "no
  follow-ups for latent debt" convention).
- **`views_embed_view()` deprecation**: F's handoff correctly notes this is per A-plan's explicit
  directive, not a choice F made independently, and flags it for a future Drupal-13 migration
  story. No action needed at this phase.
- Cleaned up 7 leftover `php -S` dev-server processes inside the `gm123-discovery` DDEV container
  that had accumulated across my own verification attempts (harness hygiene, not a code issue).
