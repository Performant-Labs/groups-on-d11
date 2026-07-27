# Handoff-T-green: Phase 6 - #251 Cache-tag gaps (PERF-3 audit fixes)

**Date:** 2026-07-26
**Branch:** 251-cache-tag-gaps
**Issue:** #251
**Handoff-F reviewed:** `docs/handoffs/251-cache-tag-gaps/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/251-cache-tag-gaps/handoff-T-red.md`

## Environment

DDEV project `gm251-cache`, already provisioned. `bash scripts/ci/assemble-config.sh` (via `ddev
exec`) run fresh before every test run below — exit 0, `139 file(s)` config copied, `16 custom
module(s)` copied, matching F's own reported assemble output exactly. PHP built-in server
(`php -S 127.0.0.1:8080`) confirmed already running inside the web container and responsive
(302 redirect, expected pre-bootstrap).

## GREEN confirmation

### Fix 1 — `MyFeedGroupJoinCacheInvalidationTest`

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedGroupJoinCacheInvalidationTest.php
```
Exit code: 0.
```
✔/⚠ Group join invalidates user stream cache tag
✔/⚠ Group join does not invalidate unrelated users tag
Tests: 2, Assertions: 18, Deprecations: 44, PHPUnit Deprecations: 3.
```
Both tests pass (⚠ markers are pre-existing framework-deprecation noise from running against
`phpunit.xml.dist` instead of the project's `phpunit.functional.xml`, per T-red's own documented
finding — no `✘` failure marker, no `FAILURES` section). The RED (`Failed asserting that 0 is not
identical to 0`) is gone; the checksum now genuinely changes on a real "Join group" form
submission.

### Fix 2 — `TrendingCommentInvalidationTest`

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_discovery/tests/src/Functional/TrendingCommentInvalidationTest.php
```
Exit code: 0.
```
Tests: 2, Assertions: 28, Deprecations: 27, PHPUnit Deprecations: 3.
```
Zero `✘`, no `FAILURES` section. Both the render-order test (previously `Failed asserting that
2569 is less than 2030`) and the data-layer fallback test (previously `Failed asserting that 0.0
is greater than 0.0`) now pass.

### Fix 3 — `AllGroupsViewCachePluginTest` (Kernel)

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_group_extras/tests/src/Kernel/AllGroupsViewCachePluginTest.php
```
Exit code: 0.
```
✔ All groups default display declares cache type none
Tests: 1, Assertions: 3, Deprecations: 1, PHPUnit Deprecations: 2.
```
The RED (`Failed asserting that null is of type array`) is gone — `views.view.all_groups.yml`'s
default display now declares `cache: {type: none, options: {}}`.

**Spot-check that these still fail if behavior is removed:** confirmed by construction, not
re-run — each assertion targets the exact absent-today mechanism T-red's RED output pinned
(checksum delta, score/order delta, presence of a `cache` key), and F's diff (reviewed below)
adds precisely that mechanism and nothing else; reverting any one of the 3 production edits would
reproduce the exact RED failure text T-red recorded, since no other code path could satisfy these
assertions.

## Sibling-test sweep (regression check)

**Kernel** — every Kernel test across `do_streams`, `do_discovery`, `do_group_extras`:
```
ddev exec "env SIMPLETEST_DB='mysql://db:db@db/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox $(find web/modules/custom/{do_streams,do_discovery,do_group_extras} -type d -path '*/tests/src/Kernel')"
```
`Tests: 99, Assertions: 2859, Deprecations: 40, PHPUnit Deprecations: 73.` Exit 0. Grep-confirmed
0 `✘` markers anywhere in the raw output. 99/99 green (T-red's own RED baseline was 99 tests / 1
intended failure — that failure is now the Fix-3 GREEN above; no other test moved).

**Functional** — every Functional test across the same 3 modules:
```
ddev exec "env SIMPLETEST_DB='mysql://db:db@db/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8080' \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  $(find web/modules/custom/{do_streams,do_discovery,do_group_extras} -type d -path '*/tests/src/Functional')"
```
`Tests: 34, Assertions: 277, Deprecations: 53, PHPUnit Deprecations: 42, Risky: 3.` PHPUnit's own
process exit code was 1, but this is caused solely by the 3 pre-existing "Risky" tests (unrelated
files: `MyFeedNavLinkTest` + 2 others never touched by this story, flagged only for unexpected
stdout during the test — matches F's own report verbatim). Grep-confirmed **0 `✘` markers** and
no `FAILURES` section in the raw output — 34/34 tests pass their own assertions. Cross-checked
against F's reported combined-run totals (`Tests: 6, Assertions: 66` for the 4 new/touched test
files; `Tests: 34, Assertions: 277` for the full sweep) — identical.

Sweep count: **133 tests run (99 Kernel + 34 Functional), 133 green, 0 failures.**

## Fixture-drift finding

F flagged that `do_group_extras/tests/fixtures/config/views.view.all_groups.yml` (used by
`AllGroupsMembershipCacheInvalidationTest`) was copied from `docs/groups/config/` at T-red
authoring time, before Fix 3's `cache: type: none` edit, and was not updated because it is a
T-owned test artifact under F's role boundary.

**(a) Is the "still passes" claim empirically true?** Yes — verified directly above: both tests
in `AllGroupsMembershipCacheInvalidationTest` pass (34/34 in the full Functional sweep, 2/2 for
this file specifically per F's own isolated run reproduced in the sweep). Read of both test
bodies confirms *why*, independent of re-running with a patched fixture:
- `testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear` exercises an **authenticated** session.
  Per this suite's own class docblock and T-red's independently-documented probe finding, every
  authenticated `/all-groups` response in this harness is marked `UNCACHEABLE (request policy)`
  regardless of the Views display's own `cache` plugin choice — so whether the fixture's view
  declares the default `tag` plugin or `type: none` is irrelevant to this test's outcome.
- `testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups` relies on **Internal Page
  Cache** (a layer above and independent of the Views-internal render-cache plugin) for its
  MISS-then-HIT precondition, and asserts a negative (no leak) that holds regardless of which
  Views cache plugin is in effect — a membership grant here never saves the group entity itself,
  so nothing invalidates the anonymous page-cache entry under either `tag` or `none`.

Both tests are therefore genuinely independent of the exact fixture value F identified — this is
not a lucky coincidence, it follows directly from what each test's own docblock says it is
pinning.

**(b) Is the fixture drift a real problem?** Yes, as a latent risk, though non-blocking today.
The fixture's `cache:` key was the ONE line where the module-local fixture and the shipped
config diverged, and nothing currently exercised that specific divergence — but this is exactly
the kind of drift PROJECT_CONTEXT.md's fixture-locality rule exists to prevent silently
accumulating. If a future story adds a THIRD `AllGroupsMembershipCacheInvalidationTest`-style test
that DOES depend on the Views cache plugin (e.g. a render-cache HIT/MISS assertion for an
authenticated multi-request scenario once some future change makes authenticated `/all-groups`
cacheable), it would silently test against the pre-Fix-3 cache-plugin shape and could produce a
false pass/fail unrelated to the code under test.

**Disposition: updated the fixture for parity (action taken, not just recommended).** The fix is
a 3-line YAML addition identical to production (`cache: {type: none, options: {}}`), carries no
risk (both tests are proven independent of the value, so this cannot introduce a new failure), and
closes the drift before it can compound. This is a **test-fixture edit**, i.e. T's task, not F's —
applied directly as part of Phase 6 test-suite upkeep (the test-quality duty to keep the authored
suite accurate), not routed back to F. Re-ran
`AllGroupsMembershipCacheInvalidationTest` after the edit to confirm no regression:

```
ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_extras/tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php
```
`Tests: 2, Assertions: 20, Deprecations: 14, PHPUnit Deprecations: 3.` Exit 0, 0 `✘`, still GREEN
— confirms the two tests are genuinely independent of this value, as claimed. Staged by explicit
path:
`docs/groups/modules/do_group_extras/tests/fixtures/config/views.view.all_groups.yml`.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `ddev exec bash scripts/ci/assemble-config.sh` | exit 0 | exit 0, 139 config files / 16 modules copied | PASS |
| Fix 1 test | `phpunit ... MyFeedGroupJoinCacheInvalidationTest.php` | 2/2 green | 2/2, 0 ✘ | PASS |
| Fix 2 test | `phpunit ... TrendingCommentInvalidationTest.php` | 2/2 green | 2/2, 0 ✘ | PASS |
| Fix 3 test | `phpunit ... AllGroupsViewCachePluginTest.php` | 1/1 green | 1/1, 0 ✘ | PASS |
| Kernel regression (3 modules) | `phpunit ... find .../Kernel` | 99/99 green | 99/99, 0 ✘ | PASS |
| Functional regression (3 modules) | `phpunit ... find .../Functional` | 34/34 green | 34/34, 0 ✘ (exit 1 from pre-existing unrelated Risky tests only) | PASS |
| Fixture-parity re-check | `phpunit ... AllGroupsMembershipCacheInvalidationTest.php` (post fixture edit) | 2/2 green | 2/2, 0 ✘ | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage per AC | Each of 3 fixes has a dedicated authored test pinning its exact acceptance criterion (checksum delta / score+order delta / structural config key) | PASS |
| Test quality (§7) | Each test names a single behavior, sits at cheapest sufficient tier (Kernel for Fix 3's pure config assertion, Functional only where a real hook-trigger or HTTP round-trip is required), asserts outcome not implementation (checksum value, rendered order, DB row value, YAML key) — no redundant tests found; the 2 Fix-3 Functional tests are non-duplicative acceptance-criterion coverage (correctness + negative-scope control), not a RED gate duplicate | PASS |
| Type safety | `commentInsert(CommentInterface $comment)`, `groupRelationshipInsert(GroupRelationshipInterface $relationship)`, `recomputeNodeScore(int $nid)`, `hotScoreCacheTag(int\|string $nid): string` — all typed, no `any`/mixed casts introduced | PASS |
| Error handling | `recomputeNodeScore()` guards missing table (`tableExists()`) and missing row (`fetchObject() === FALSE`) with silent no-fatal returns, mirroring the file's own established defensive convention; `groupRelationshipInsert/Delete` guard `$member === NULL` | PASS |
| Data integrity | Fix 2 writes via `merge()` keyed on `nid` (no duplicate-row risk); no schema change; single-node scope avoids cross-node interference | PASS |
| API contract | No new external API surface beyond 2 new public static tag-builders (additive only), matching sibling naming conventions | PASS |
| Security | No new user input paths; comment/relationship entities are framework-validated before these hooks fire | PASS |
| Migration safety | N/A — no schema migration, YAML-only + additive-hook changes | N/A |
| Playwright | N/A — project has no UI surface for this story (no Playwright tests touched) | N/A |
| PHPCS (structural lint) | `phpcs --standard=Drupal,DrupalPractice` on the 2 changed PHP files, before/after comparison | `DoStreamsHooks.php`: 1 pre-existing error (line-shifted, confirmed same source as baseline), 0 net-new. `DoDiscoveryHooks.php`: 0 errors, 1 net-new warning (`\Drupal::time()` at line 275, matches 2 pre-existing identical unflagged calls in the same file at lines 60/85). Matches F's reported "0 net-new errors, 1 net-new warning" exactly | PASS |
| Audit shape spot-check | Diff review of both hook classes + the YAML change against audit §4's recommended shape | Hook names correct (`group_relationship_insert`/`_delete`, `comment_insert`); plugin-id discriminator (`GROUP_MEMBERSHIP_PLUGIN_ID`) mirrors sibling `DoGroupExtrasHooks` constant exactly; cache-tag naming (`do_streams:user_stream:{uid}`, `do_discovery:hot_score:{nid}`) matches the module's existing tag-builder convention; `views.view.all_groups.yml` diff is the exact 3-line `cache: {type: none, options: {}}` block matching the 3 sibling views | PASS |

## Acceptance criteria status

| Criterion | Backing test | Status |
|---|---|---|
| Fix 1: joining a group invalidates the joining user's `do_streams:user_stream:{uid}` tag | `testGroupJoinInvalidatesUserStreamCacheTag` | PASS |
| Fix 1: invalidation is scoped to the joining member, not a blanket flush | `testGroupJoinDoesNotInvalidateUnrelatedUsersTag` | PASS |
| Fix 2: a new comment changes `/trending`'s rendered order without cron | `testCommentOnLowerScoredNodeMovesItAheadInTrending` | PASS |
| Fix 2: `do_discovery_hot_score` row recomputes on `comment_insert` without cron | `testCommentInsertRecomputesHotScoreRowWithoutCron` | PASS |
| Fix 3: `all_groups.yml`'s default display declares `cache: type: none` | `testAllGroupsDefaultDisplayDeclaresCacheTypeNone` | PASS |
| Fix 3: a member sees a private group in `/all-groups` immediately after joining (no cache-clear needed) | `testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear` | PASS (already-correct behavior, non-regression) |
| Fix 3: an unrelated anonymous viewer's cached listing never leaks a private group on someone else's membership change | `testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups` | PASS (already-correct behavior, non-regression) |

## Blocking issues

None.

## Advisory notes

- The fixture-drift finding above was resolved directly (fixture updated, re-verified GREEN) rather
  than left as a note for S — this keeps the T-authored suite proportionate and accurate per the
  test-quality duty, and removes a latent divergence before it could mask a future regression.
- F's forward-looking `Cache::invalidateTags([self::hotScoreCacheTag($nid)])` call in Fix 2 is
  currently a no-op (no live consumer of `do_discovery:hot_score:{nid}` exists yet) — this matches
  the audit's own §4 Rec 2(a) framing and is not tested directly (no consumer to observe), but is
  harmless and correctly documented as forward infrastructure, not dead code requiring a test.
- The 3 "Risky" Functional tests flagged by PHPUnit's exit code are pre-existing and unrelated to
  this story (`MyFeedNavLinkTest` + 2 others, unexpected-stdout warnings) — not a #251 regression,
  no action needed here.

VERDICT: GREEN
