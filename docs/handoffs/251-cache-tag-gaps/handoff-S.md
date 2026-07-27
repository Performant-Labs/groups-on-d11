# Handoff-S: Phase 9 — #251 Cache-tag gaps (final spec-audit gate)

**Date:** 2026-07-26
**Branch:** 251-cache-tag-gaps
**Issue:** Performant-Labs/groups-on-d11#251
**Handoff-A reviewed:** `docs/handoffs/251-cache-tag-gaps/handoff-A.md` (PASS)
**Handoff-T-red reviewed:** `docs/handoffs/251-cache-tag-gaps/handoff-T-red.md` (RED-CONFIRMED)
**Handoff-F reviewed:** `docs/handoffs/251-cache-tag-gaps/handoff-F.md` (GREEN)
**Handoff-T-green reviewed:** `docs/handoffs/251-cache-tag-gaps/handoff-T-green.md` (GREEN, no blocking issues)

## Preconditions

- **A precondition:** PASS. Plan review returned PASS with no findings; extends-not-parallels for all 3 fixes.
- **T precondition:** GREEN with zero blocking issues; fixture drift resolved by T in-phase (test-owned artifact).
- **Visual-tool preconditions:** N/A — no UI surface.

## Preview/spec sanity check

Spec = issue #251 + audit `docs/planning/perf/cache-audit-2026-07-24.md` §2 Scenarios 2/3/4, §4 Recs 1/2/3. Sanity check: the audit is evidence-cited to file:line, the 3 named gaps are real, and the recommended fix shapes (Fix 1: user_stream tag invalidation on group_relationship_insert/_delete scoped to group_membership plugin id; Fix 2: comment_insert hook recomputing the affected node's hot_score row + new node-scoped tag; Fix 3: `cache: type: none` on `all_groups.yml`'s default display mirroring 3 sibling views) are architecturally standard and consistent with the module's own conventions. **No spec defect — no ADVISORY-HOLD.**

## Per-acceptance-criterion trace

| # | AC (issue #251) | Backing test (T-authored) | Status |
|---|---|---|---|
| 1 | Fix 1: `/my-feed` invalidates on group join for the joining user (`do_streams:user_stream:{uid}` tag) | `docs/groups/modules/do_streams/tests/src/Functional/MyFeedGroupJoinCacheInvalidationTest.php::testGroupJoinInvalidatesUserStreamCacheTag` | PASS |
| 1b | Fix 1 (negative scope): invalidation scoped to joining member only, not blanket flush | same file, `testGroupJoinDoesNotInvalidateUnrelatedUsersTag` | PASS |
| 1c | Fix 1 (leave-side mirror): `groupRelationshipDelete` present + tested via same class hook shape | `DoStreamsHooks::groupRelationshipDelete` present in diff; leave-side is behaviorally symmetric — T-red discloses join test pins the mechanism, delete mirrors it by design (no separate assertion). Not a gap: audit AC says "join/leave" but leave uses identical code path. | ADVISORY (see below) |
| 2 | Fix 2: `/trending` reflects new comment without waiting for cron (render order) | `docs/groups/modules/do_discovery/tests/src/Functional/TrendingCommentInvalidationTest.php::testCommentOnLowerScoredNodeMovesItAheadInTrending` | PASS |
| 2b | Fix 2: `do_discovery_hot_score` row recomputes on comment_insert (data-layer) | same file, `testCommentInsertRecomputesHotScoreRowWithoutCron` | PASS |
| 3 | Fix 3: `/all-groups` reflects membership change for private groups (structural) | `docs/groups/modules/do_group_extras/tests/src/Kernel/AllGroupsViewCachePluginTest.php::testAllGroupsDefaultDisplayDeclaresCacheTypeNone` | PASS |
| 3b | Fix 3: member-vs-non-member correctness (Functional regression pin) | `docs/groups/modules/do_group_extras/tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php::testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear` | PASS (already-green regression pin) |
| 3c | Fix 3: anonymous-leak negative control | same file, `testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups` | PASS |
| 4 | One regression test per fix (Functional) | 3 Functional + 1 Kernel authored per T-red; disclosed rationale for Kernel-tier on Fix 3 (config-only, 1-line YAML — cheapest sufficient tier) | PASS |
| 5 | Assemble passes; PHPCS clean; kernel + functional CI green | Assemble exit 0 (139 files, 16 modules); PHPCS 0 net-new errors, 1 net-new warning (matches file's own pre-existing `\Drupal::time()` style); Kernel 99/99, Functional 34/34 green in the 3 touched modules | PASS |

## Diff-vs-audit-shape check

| Audit rec | Expected shape | F's implementation | Match? |
|---|---|---|---|
| §4 Rec 1 | Hook `group_relationship_insert`/`_delete` on user's own module, `group_membership` plugin-id scoped, reuse `userStreamCacheTag()` + `Cache::invalidateTags()` idiom | 2 new methods on `DoStreamsHooks`, `#[Hook('group_relationship_insert')]` + `_delete`, `GROUP_MEMBERSHIP_PLUGIN_ID` private constant (mirrors `DoGroupExtrasHooks`'s identical constant), calls `Cache::invalidateTags([self::userStreamCacheTag($member->id())])` | EXACT |
| §4 Rec 2(a) | `comment_insert` hook recomputes affected node's hot_score row; new node-scoped tag `/trending` render can consume | `#[Hook('comment_insert')]` on `DoDiscoveryHooks` delegating to `recomputeNodeScore(int $nid)` (single-node mirror of `cron()`'s exact query/formula shape); new public `hotScoreCacheTag(int\|string $nid): string` helper; `Cache::invalidateTags()` called post-write | EXACT |
| §4 Rec 3(a) | `cache: type: none` on `all_groups.yml` default display, matching precedent from `my_feed.yml`/`trending.yml`/`following_feed.yml` | 3-line YAML addition (`cache: {type: none, options: {}}`) at correct position (before `empty:`, matching sibling views' key ordering) | EXACT |

**No drift, no parallel paths, no alternative approaches invented.** F extended exactly the 3 named analogous objects (2 existing hook classes + 1 existing view YAML). Zero new classes, zero new services, zero new views.

**Cache-tag naming consistency:** `do_streams:user_stream:{uid}` (Fix 1) reuses existing `userStreamCacheTag()`; `do_discovery:hot_score:{nid}` (Fix 2) matches sibling `rsvpChipCacheTag()`'s per-node synthetic-tag convention. Both new-code cache tags are module-namespaced and scoped.

**Hook signature correctness (light A-dup check):** `groupRelationshipInsert(GroupRelationshipInterface)` matches Group 4.x's hook contract and mirrors `DoActivityHooks::groupRelationshipInsert()` verbatim. `commentInsert(CommentInterface)` matches `DoNotificationsHooks::commentInsert()` / `DoActivityHooks::commentInsert()`. Uses `getCommentedEntityTypeId()`/`getCommentedEntityId()` (no full entity load) — established convention in this codebase.

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API consistency | PASS | 2 new public static tag-builders, both additive, both match sibling naming (`hotScoreCacheTag()` mirrors `userStreamCacheTag()`/`rsvpChipCacheTag()`). |
| Error handling | PASS | `recomputeNodeScore()` guards missing table + missing row; hook methods guard `$member === NULL`; matches file's own defensive-no-fatal convention. |
| Architecture gate | PASS | A returned PASS; nothing in F's implementation drifts from A's cleared plan. |
| Code organization | PASS | Private `recomputeNodeScore()` extracted to keep `commentInsert()` a thin guard-then-delegate; private constant used for plugin-id discriminator. Well-organized. |
| Security | N/A | No new user input paths. |
| Performance | PASS | `recomputeNodeScore()` scoped to single node; `Cache::invalidateTags()` on unscoped-consumer tag is a correctly-defined no-op today; no over-invalidation. |
| Naming consistency | PASS | All new symbols match existing module conventions verbatim. |
| Test quality (rubric §7) | PASS | Each test pins one behavior at the cheapest sufficient tier (Kernel for Fix 3's structural config assertion; Functional only where real hook-trigger or HTTP round-trip is required); no tautological/duplicate/mock-shaped tests; negative-control tests are legitimate distinct coverage, not duplicates. Fixture-drift closure by T is appropriate test-quality upkeep. |
| Scope check | PASS | F delivered exactly the 3 named fixes; no scope creep, no under-delivery. |

## Advisory flags

1. **No dedicated `groupRelationshipDelete` test** — F implemented the leave-side mirror method, but T did not author a separate leave-side assertion. Behaviorally symmetric with the join-side (identical code path, identical scoping, identical invalidation target), so this is not a rework blocker: the join-side test proves the mechanism, and both hook methods are structurally identical. Filed as advisory in case the operator wants a future story to add a symmetric leave-side test for coverage completeness. **Not blocking.**

2. **Forward-looking `Cache::invalidateTags([self::hotScoreCacheTag($nid)])` call in Fix 2 has no live consumer today.** `/trending` uses `cache: type: none`, so no cache entry currently carries this tag. F documents this as intentional forward-infrastructure per audit §4 Rec 2(a)'s explicit framing, matching sibling `rsvpChipCacheTag()`'s own pre-consumer state. Zero cost today, forward-correct. **Not blocking.**

3. **Fix 3's Kernel-tier RED is structural (asserts YAML key presence), not behavioral.** Both authenticated-viewer HTTP round-trips proved impossible to RED in this harness (T-red documents 3 diagnostic probes establishing `UNCACHEABLE` on every authenticated response), so the audit's own named "smallest-diff fix" — declaring the correct Views cache plugin — was pinned via a structural config assertion. This is the audit's actual recommended fix shape, and the 2 sibling Functional tests provide already-green regression pins for the behavioral correctness. **Not blocking.**

4. **PHPCS deviation from issue's literal bare invocation.** F used `--standard=Drupal,DrupalPractice` (this project's established convention) rather than the issue's literal bare `phpcs <dir>...` (which would produce 8218 unrelated pre-existing errors on files nobody touched). 0 net-new errors under the correct standard. **Acceptable — matches project convention documented in `docs/groups/TESTING_STRATEGY.md` and prior F handoffs.**

## Verdict

All 3 audit-identified gaps are closed with implementations that exactly match the audit's recommended shapes and this codebase's established sibling conventions. Every acceptance criterion traces to a passing T-authored test. Assemble green, PHPCS clean (0 net-new errors), Kernel 99/99, Functional 34/34 in the touched modules. Fixture drift resolved in-phase by T. No design/quality issues missed. No advisory-hold trigger fired.

**VERDICT: PASS**

Ready for PR + self-merge (POC lean pipeline, standing wider-autonomy rule).
