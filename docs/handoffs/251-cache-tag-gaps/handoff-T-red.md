# Handoff-T-red: Phase 4 - #251 Cache-tag gaps (PERF-3 audit fixes)

**Date:** 2026-07-26
**Branch:** 251-cache-tag-gaps
**Brief / wireframe reviewed:** `docs/handoffs/251-cache-tag-gaps/brief.md`, `docs/handoffs/251-cache-tag-gaps/handoff-A.md` (PASSED, no wireframe — no UI surface)

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`). All three fixes extend the
correct analogous objects; no drift, no duplication, no BLOCK findings. Proceeding to author tests.

## Environment setup (not part of the deliverable, recorded for reproducibility)

The worktree had no `web/core`/`vendor` yet. Brought up DDEV project `gm251-cache` (renamed from a
stale `gm145-wcag` config; also found and removed a leftover `.ddev/config.gm139.yaml` override
file with `override_config: true` that was silently forcing the project name to `gm139-multilang-rtl`
— renamed to `.bak`, not deleted, in case another workflow depends on it). `docker network prune -f`
was required once (predefined address-pool exhaustion from many stopped sibling DDEV projects).
`ddev composer install` then `bash scripts/ci/assemble-config.sh` (run via `ddev exec`, since `php`
is not on the host PATH — the host-side invocation partially fails at the `core.extension.yml` patch
step but the config/module copy still succeeds; `ddev exec bash scripts/ci/assemble-config.sh`
completes cleanly end-to-end and is what every test run below used).

Functional (BrowserTestBase) tests need `SIMPLETEST_DB` + `SIMPLETEST_BASE_URL` + a served docroot,
mirroring `.github/workflows/test.yml`'s functional job exactly: `php -S 127.0.0.1:8080 -t web
"$PWD/web/.ht.router.php"` backgrounded inside the DDEV web container, then
`SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080"`. Kernel tests need
only `SIMPLETEST_DB`. Per the task's instruction, all RED-confirmation runs below use
`web/core/phpunit.xml.dist` (not the project's `phpunit.functional.xml`, which the real CI Functional
job actually uses to suppress unrelated framework-deprecation noise) — this surfaces extra
"risky"/deprecation chatter on every run (documented per-test below) but does not change any
assertion's pass/fail outcome; cross-checked with `phpunit.functional.xml` for the two multi-file runs
to confirm identical pass/fail results.

## Deviation from the brief's literal test shape (read this before the individual tests below)

The brief's Fix 1/Fix 3 test shapes are "join a group -> content appears without cache clear" as a
literal two-request HTTP content-diff. **Three separate diagnostic probes (not committed — built,
run, and deleted during authoring) established that this shape cannot produce a valid RED in this
harness for an AUTHENTICATED viewer**, independent of any of the three fixes:

- A real `drupalGet('/my-feed')` round trip for a logged-in user returns
  `X-Drupal-Dynamic-Cache: UNCACHEABLE (poor cacheability)` and
  `Cache-Control: must-revalidate, no-cache, private` on **every** request, fix or no fix —
  BrowserTestBase's authenticated session is never eligible for Drupal's page/dynamic cache layers
  here. A content-diff assertion across two authenticated requests therefore passes (shows fresh
  content) regardless of whether the invalidation hook exists, because no cross-request cache is
  ever actually consulted for that session either way. This would be a **false-negative "RED"** —
  green today for a reason unrelated to the bug, and would stay green after F's fix for the same
  unrelated reason, proving nothing.
- Directly probing `MyFeedController::buildShell()`'s own render array confirmed why: it declares
  `#cache[contexts]`/`#cache[tags]` but no `#cache[keys]` — Drupal's RenderCache only writes/reads a
  bin entry for an element carrying `keys` (contexts/tags alone only bubble metadata to a PARENT
  cache entry). A synthetic render array WITH explicit `keys` was probed separately and DID reproduce
  a genuine render-cache HIT/stale-content bug pattern, confirming the mechanism the audit describes
  is real — just not observable via `MyFeedController`'s specific, keys-less render array shape in a
  Functional round-trip.
- For `/all-groups`, the SAME authenticated-session limitation applies (`UNCACHEABLE (request
  policy)`), but ANONYMOUS requests DO get real Internal Page Cache HITs (confirmed via
  `X-Drupal-Cache: MISS` then `HIT`) — however, a private group is never visible to anonymous
  regardless of membership, so the brief's literal "member vs non-member" scenario is inherently an
  authenticated-only axis and cannot be pinned via the one cache layer that IS observable here.
  Separately, a naive "flip privacy field public" mutation was probed and found to ALSO produce a
  false-negative RED, because `$group->save()` invalidates the group's own `group:{id}` entity cache
  tag as an unrelated side effect, busting the page regardless of the query-alter's own gap.

**Resolution applied per fix** (the brief explicitly names this as an acceptable alternative:
"assertion via cache-tag observation... is acceptable as an alternative to a full render-diff"):

- **Fix 1** — pinned via the `cache_tags.invalidator.checksum` service directly: does a REAL "Join
  group" form submission (not an API shortcut) change the checksum for
  `do_streams:user_stream:{uid}`? This is the exact mechanism every real cache backend (page cache,
  dynamic page cache, a future render-cache-keyed consumer) checks before serving a HIT, independent
  of whether THIS harness's own response ever reaches cacheable status.
- **Fix 2** — `/trending`'s view already declares `cache: type: none` (confirmed by direct read), so
  this is NOT a render-cache-invalidation gap at all — the view always re-queries. The actual gap is
  that the underlying `do_discovery_hot_score.score` value itself is never recomputed on
  `comment_insert` (only on cron), so a fresh re-query still returns stale ordering. This IS
  observable via a real HTTP round-trip (no cache layer to fight), so Fix 2's primary test is a
  genuine render-order diff, no deviation needed there — plus a data-layer fallback test per the
  brief's own "assert the invalidation happens even if render-order isn't wired yet" instruction.
- **Fix 3** — pinned two ways: (a) a Kernel-tier structural assertion that `all_groups.yml`'s default
  display declares `cache: type: none` (the audit's own recommended, config-only smallest-diff fix —
  this IS the valid RED for Fix 3), backed by (b) two Functional regression tests that are
  **already GREEN today** (member-vs-non-member correctness; a negative anonymous-leak control) —
  kept as legitimate acceptance-criterion coverage per the brief's stated test list, but explicitly
  NOT presented as RED (see "Fix 3" section below for why, and why they remain valuable regression
  pins once F's fix lands).

No fix required a Views handler subclass, controller refactor, or `hook_entity_predelete` beyond the
brief's own analogous-object map — **no advisory-hold trigger fires**.

## Tests authored

### Fix 1 — `do_streams` (`docs/groups/modules/do_streams/tests/src/Functional/MyFeedGroupJoinCacheInvalidationTest.php`)

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testGroupJoinInvalidatesUserStreamCacheTag` | AC: joining a group via the real "Join group" form invalidates `do_streams:user_stream:{uid}` (audit §2 Scenario 2 / §4 Rec 1). **This is the RED.** | Functional | The real `group_relationship_insert` production trigger only fires end-to-end through the actual `entity.group.join` route + form submission (mirrors `JoinPolicyEnforcementTest`'s own pattern) — a Kernel-level `$group->addMember()` API shortcut would not prove the REAL production trigger path fires the hook. |
| `testGroupJoinDoesNotInvalidateUnrelatedUsersTag` | Negative control: invalidation is scoped to the joining member only, never a blanket flush (mirrors `DoStreamsHooks::onFlaggingChange()`'s existing scoped-tag discipline). | Functional | Same real-trigger requirement as above; a bystander's tag must provably NOT change. |

### Fix 2 — `do_discovery` (`docs/groups/modules/do_discovery/tests/src/Functional/TrendingCommentInvalidationTest.php`, new `tests/src/Functional` + `tests/fixtures/config` dirs)

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testCommentOnLowerScoredNodeMovesItAheadInTrending` | AC: `/trending` reflects a new comment's effect on ranking without cron (audit §2 Scenario 4 / §4 Rec 2) — the VIEWER-OBSERVABLE contract (real render order). **This is a RED.** | Functional | `/trending`'s `cache: type: none` means no cache layer masks the gap — a real HTTP round-trip is the only way to observe the actual rendered row order a viewer sees, and is not fragile here (2 comments vs. a 5.0 baseline is an unambiguous flip, not coupled to exact formula constants). |
| `testCommentInsertRecomputesHotScoreRowWithoutCron` | Data-layer fallback per the brief's own instruction ("assert the invalidation happens even if render-order isn't wired yet"): the `do_discovery_hot_score` row itself must change value on comment_insert, independent of Views rendering. **This is a RED.** | Functional (thin data assertion, no rendering) | Kept as a Functional test (not demoted to Kernel) because it shares the exact same real HTTP-reachable comment-posting setup as the sibling test above and is not worth a second, parallel Kernel fixture — but it asserts ONLY the database row, no HTML parsing, making it the cheaper of the two despite the tier label. |

### Fix 3 — `do_group_extras` (`docs/groups/modules/do_group_extras/tests/src/Kernel/AllGroupsViewCachePluginTest.php` + `tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php`)

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testAllGroupsDefaultDisplayDeclaresCacheTypeNone` | AC (structural): `all_groups.yml`'s default display must declare `cache: type: none`, matching the audit's own named smallest-diff fix and the exact precedent 3 sibling views already set. **This is the RED for Fix 3.** | Kernel (pure config read via `FileStorage`, no Drupal bootstrap strictly required for the assertion itself but reuses the established `shippedConfigDir()` pattern) | Fix 3 is a 1-line, config-only change per the brief — a Functional round-trip is unnecessary to pin the fix ITSELF; this is the cheapest-sufficient tier for a structural config assertion, mirroring `HotScoreForumCommentTest`'s own directory-walk convention. |
| `testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear` | AC (behavioral, already correct today): a member sees a private group in `/all-groups`, a non-member does not — pins the query-alter's live per-request re-evaluation. **Passes today (GREEN) — not a RED**, see "Fix 3 test-design note" below. | Functional | Real HTTP round-trip needed to exercise the authenticated access/listing path end to end; kept as acceptance-criterion coverage per the brief's stated test list even though it does not currently fail. |
| `testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups` | Negative scope control: an unrelated anonymous viewer's cached listing must never start showing a private group because someone else joined it (no over-broad flush). **Passes today (GREEN) — not a RED.** | Functional | Uses the one cache layer (anonymous Internal Page Cache) that IS observably HIT/MISS in this harness, to prove a negative that must hold regardless of Fix 3's exact implementation shape. |

**Fix 3 test-design note:** the brief's literal "add U as member -> G IS in listing, without cache
clear" scenario, and a naive "flip the private flag to public" scenario, were both PROBED first and
found to be invalid REDs in this harness for the reasons in the deviation section above (authenticated
sessions are `UNCACHEABLE` regardless of the fix; entity-save side effects bust the page for an
unrelated reason). The two Functional tests above pin genuinely valid acceptance-criterion behavior
(and would catch a REAL regression if a future change broke either), but are not the RED gate for Fix
3 — `testAllGroupsDefaultDisplayDeclaresCacheTypeNone` is. This is disclosed rather than silently
presented as 3 REDs for Fix 3.

## RED confirmation

Environment for every run below: `ddev exec` inside `gm251-cache`, `bash scripts/ci/assemble-config.sh`
run immediately before each run.

### Fix 1

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedGroupJoinCacheInvalidationTest.php
```
Exit code: 1 (FAILURES).
```
✘ Group join invalidates user stream cache tag
  Fix 1: joining a group via the real "Join group" form must invalidate the joining user's own
  cache tag (do_streams:user_stream:2) — no hook does this today (audit §2 Scenario 2 / §4 Rec 1),
  so this checksum is unchanged before F implements the fix.
  Failed asserting that 0 is not identical to 0.
✔ Group join does not invalidate unrelated users tag
Tests: 2, Assertions: 18, Failures: 1
```
Valid RED: fails because the checksum genuinely did not change (`0` before, `0` after) — the exact
absence of invalidation the audit documents, not a setup/import error.

### Fix 2

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_discovery/tests/src/Functional/TrendingCommentInvalidationTest.php
```
Exit code: 1 (FAILURES).
```
✘ Comment on lower scored node moves it ahead in trending
  Fix 2: after 2 comments (score 6.0) are posted on "Commented Topic", it must render AHEAD of
  "Higher Score Baseline" (score 5.0) in /trending — no comment_insert hook recomputes the score
  today (audit §2 Scenario 4 / §4 Rec 2), so the order is unchanged before F implements the fix.
  Failed asserting that 2569 is less than 2030.
✘ Comment insert recomputes hot score row without cron
  Fix 2: posting a comment must recompute that node's hot_score WITHOUT a cron run — today the
  score stays at its seeded baseline (0.0) until the next cron cycle (audit §2 Scenario 4).
  Failed asserting that 0.0 is greater than 0.0.
Tests: 2, Assertions: (partial before failure), Failures: 2
```
Valid RED (both): the baseline-order assertion (established BEFORE the comment) passed correctly in
an isolated debug run, confirming the failure is specifically "order did not change after commenting"
— the actual gap — not a fixture/setup problem. The second test fails asserting `0.0 > 0.0`, i.e. the
score literally never left its seeded baseline, exactly matching the audit's own finding.

### Fix 3

Kernel (the RED):
```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_group_extras/tests/src/Kernel/AllGroupsViewCachePluginTest.php
```
Exit code: 1 (FAILURES).
```
✘ All groups default display declares cache type none
  Fix 3: views.view.all_groups's default display must declare a `cache` key at all — today it
  declares none (audit §1 coverage table / §4 Rec 3(a)), silently defaulting to Views core's `tag`
  plugin, which cannot see rows the query-alter excluded.
  Failed asserting that null is of type array.
Tests: 1, Assertions: 2, Failures: 1
```
Valid RED: fails because the shipped `views.view.all_groups.yml` genuinely has no `cache` key today
(confirmed independently by direct `grep` — matches the audit's own citation).

Functional (both currently GREEN, disclosed above as non-RED acceptance-criterion pins):
```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_extras/tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php
```
```
✔ Member vs non member sees private group without cache clear
✔ Anonymous view is unaffected by membership changes on private groups
Tests: 2, Assertions: 20, Failures: 0
```

## Regression check (existing suites, unaffected)

Ran every existing Kernel test in the three touched modules (`do_streams`, `do_discovery`,
`do_group_extras`) together with the new tests:
```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox $(find web/modules/custom/{do_streams,do_discovery,do_group_extras} -type d -path '*/tests/src/Kernel')
```
`Tests: 99, Assertions: 2858, Failures: 1` — the single failure is
`AllGroupsViewCachePluginTest::testAllGroupsDefaultDisplayDeclaresCacheTypeNone` (the intended RED);
all 98 pre-existing tests still pass. Also re-ran `PrivacyDirectoryTest.php` (Fix 3's sibling
suite) standalone: `Tests: 6, Assertions: 45, Failures: 0` — unaffected.

## Deviations from the plan + rationale

1. **Fix 1/Fix 3 test mechanism** — cache-tag-checksum / structural-config assertion instead of an
   HTTP render-diff, per the reasons and probes documented above. Explicitly sanctioned by the task's
   own "acceptable alternative" clause.
2. **Fix 3 produces 1 RED (Kernel, structural) + 2 already-green Functional acceptance tests**,
   rather than 3 REDs — disclosed rather than silently presented as full RED coverage. The 2 green
   tests remain genuine, valuable regression coverage (they would catch a real correctness regression
   if a future change broke either the positive or negative case) and satisfy the brief's literal test
   list; they are just not gating F's implementation the way the Kernel test is.
3. **New `do_discovery/tests/src/Functional` and `tests/fixtures/config` directories created** — did
   not exist before this story. Fixture files (`views.view.trending.yml`, `comment.type.comment.yml`,
   `field.storage.node.comment.yml`, `field.field.node.forum.comment.yml`) copied module-locally per
   PROJECT_CONTEXT.md's fixture-locality rule, sourced from `config/sync/` (comment baseline) and
   `docs/groups/config/` (trending view) respectively — never a source-relative path.
4. **No advisory-hold trigger fired.** All three fixes stayed within the brief's named analogous
   objects: Fix 1 is a hook addition on the existing `DoStreamsHooks` class; Fix 2 is a hook addition
   on the existing `DoDiscoveryHooks` class (no Views handler subclass or controller wrapper needed —
   confirmed by design, since `/trending` already has `cache: type: none` and no render-cache
   consumption gap exists to wire); Fix 3 is the audit's own named 1-line YAML change (no
   `hook_entity_predelete` or query-alter refactor needed).

## Ready for F

RED confirmed valid for all three fixes (Fix 1: Functional cache-tag-checksum test; Fix 2: Functional
render-order + data-layer tests; Fix 3: Kernel structural config test). F may implement against these
tests. The two Fix-3 Functional tests, though currently green, should remain green after F's change —
F should re-run them as a non-regression check, not treat them as tests to "make pass."

**VERDICT: RED-CONFIRMED**
