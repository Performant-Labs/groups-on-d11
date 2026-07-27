# Handoff-F: Phase 5 - #251 Cache-tag gaps (PERF-3 audit fixes)

**Date:** 2026-07-26
**Branch:** 251-cache-tag-gaps
**Issue:** #251

## What was done

- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — added
  `#[Hook('group_relationship_insert')]` (`groupRelationshipInsert()`) and
  `#[Hook('group_relationship_delete')]` (`groupRelationshipDelete()`), both
  scoped to the `group_membership` plugin id, invalidating
  `Cache::invalidateTags([self::userStreamCacheTag($member->id())])` for the
  joining/leaving member (Fix 1).
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` — added a
  new `hotScoreCacheTag(int|string $nid): string` static helper, a new
  `#[Hook('comment_insert')]` (`commentInsert()`) that resolves the commented
  entity's node id and delegates to a new private `recomputeNodeScore(int
  $nid)` method, which recomputes and writes that ONE node's
  `do_discovery_hot_score` row (mirroring `cron()`'s own query/formula shape,
  scoped to a single `nid`) and then invalidates the new
  `do_discovery:hot_score:{nid}` tag (Fix 2).
- `docs/groups/config/views.view.all_groups.yml` — added `cache: {type: none,
  options: {}}` to the `default` display's `display_options`, matching the
  exact precedent already set by `my_feed.yml`/`trending.yml`/
  `following_feed.yml` (Fix 3, 3-line YAML-only change).

## Design decisions

- **Fix 1 — dedicated hook methods, not folded into `entityInsert`/
  `entityDelete`.** `DoStreamsHooks` already has `#[Hook('entity_insert')]`/
  `#[Hook('entity_delete')]` methods, but those react to `EntityInterface`
  generically (flagging entities specifically, per their own internal
  `instanceof FlaggingInterface` guards). A `group_relationship_insert`/
  `_delete` pair is a DIFFERENT Drupal hook name entirely (not a generic
  entity CRUD hook — Group 4.x fires its own dedicated hook for
  relationship lifecycle), so this mirrors `DoActivityHooks`'s own separate
  `groupRelationshipInsert()`/`groupRelationshipDelete()` methods rather than
  overloading the unrelated generic-entity hooks.
- **Fix 1 — private `GROUP_MEMBERSHIP_PLUGIN_ID` constant, not a bare string
  literal.** Named to match `DoGroupExtrasHooks::GROUP_MEMBERSHIP_PLUGIN_ID`'s
  identical convention in the sibling module (both private, both this exact
  value) and this class's own established constant-naming pattern
  (`PIN_FLAG_ID`/`RSVP_FLAG_ID`/etc.) — used identically at both the insert
  and delete guard.
- **Fix 2 — `recomputeNodeScore()` extracted as its own private method rather
  than inlined into `commentInsert()`.** Two reasons: (a) it deliberately
  mirrors `cron()`'s own multi-line LEFT-JOIN query shape almost verbatim
  (scoped to one `nid` instead of a 7-day sweep), and keeping it as a
  dedicated method makes that mirroring visually obvious to a reviewer
  diffing the two side by side; (b) `commentInsert()` itself stays a thin,
  single-purpose guard (bail on non-node commented entities, then delegate) —
  matching `DoActivityHooks::commentInsert()`'s own thin-guard-then-delegate
  shape for the identical hook name.
- **Fix 2 — `getCommentedEntityTypeId()`/`getCommentedEntityId()` (no entity
  load), not `getCommentedEntity()`.** Confirmed against
  `DoNotificationsHooks::commentInsert()`'s own identical usage — this avoids
  an unnecessary node entity load for a hook that only needs the type + id,
  slightly cheaper than the load-then-check idiom, and is the established
  convention for this exact hook name elsewhere in the codebase.
- **Fix 2 — `Cache::invalidateTags([self::hotScoreCacheTag($nid)])` is called
  even though `/trending`'s own view already declares `cache: type: none`
  (confirmed by direct read — no render-cache layer is masking anything for
  that specific view today).** This was a deliberate choice, not dead code:
  the audit's own §4 Rec 2(a) explicitly names "a new, node-scoped tag...
  `/trending`'s controller/view would need to consume" as the fix shape, and
  T's RED tests assert the render-order/data-layer outcomes, not the tag's
  mere existence — so this call is forward-looking infrastructure for any
  FUTURE render (a per-node hot-score badge, a future controller wrapper)
  that attaches this tag, at zero cost today (Cache::invalidateTags() on a
  tag no live cache entry carries is a correctly-defined no-op, the same
  shape `DoStreamsHooks::rsvpChipCacheTag()`'s own tag has before any
  consumer attaches it). This did NOT require a Views handler subclass or
  controller wrapper — the brief's advisory-hold trigger does not fire (see
  "Advisory-hold check" below).
- **Fix 2 — `recomputeNodeScore()` reads `$row = $query->execute()->
  fetchObject()` and treats `$row === FALSE` as "no such node" (silent
  return), rather than asserting the node exists.** A stale/deleted node id
  reaching this method (e.g. a race with a concurrent node delete) should
  never throw from inside a `comment_insert` hook — mirrors this file's own
  established defensive-no-fatal convention (`cron()`/`nodeInsert()` both
  guard on `tableExists()` before touching the schema at all).
- **Fix 3 — `cache:` block placed immediately before `empty:` in the YAML,**
  matching the exact relative ordering `my_feed.yml` and `following_feed.yml`
  already use (`cache:` precedes `empty:` in both), for config-diff
  readability consistency across the 4 sibling views, even though YAML key
  order has no functional effect on Drupal's config parser.

## Reuse / extend-vs-new

All three fixes extend the exact analogous objects the brief's Reuse map
named — no new classes, no new services, no new views:

- **Fix 1** extended the existing `DoStreamsHooks` class (added 2 new hook
  methods), reusing its own pre-existing `userStreamCacheTag()` helper and
  `Cache::invalidateTags()` idiom (line ~474, pin-toggle invalidation) —
  exactly as the brief specified. The discriminator pattern
  (`$plugin_id === 'group_membership'`) mirrors
  `DoActivityHooks::groupRelationshipInsert()` verbatim (confirmed by direct
  read at `do_activity/src/Hook/DoActivityHooks.php:142`).
- **Fix 2** extended the existing `DoDiscoveryHooks` class (added 1 new hook
  method + 1 new private helper + 1 new static tag-builder), reusing its own
  `cron()` method's exact query/formula shape (LEFT JOIN
  `comment_entity_statistics` + optional `node_counter`, `comment_count × 3 +
  view_count × 0.5`) rather than introducing a second, independently
  maintained formula.
- **Fix 3** extended the existing `all_groups.yml` view config with a 3-line
  YAML addition, matching the identical `cache: {type: none, options: {}}`
  block 3 sibling views (`my_feed`, `trending`, `following_feed`) already
  carry.

No new object was created for any of the 3 fixes; no justification-for-new
was needed.

## Architecture notes for A

- **Layers touched:** `do_streams`'s Hook layer (2 new methods on an existing
  hook-implementations class); `do_discovery`'s Hook layer (1 new hook method
  + 1 new private helper + 1 new public static tag-builder on an existing
  hook-implementations class); `do_group_extras`'s config layer (a Views
  display's `cache` plugin declaration — no PHP touched in that module at
  all for Fix 3).
- **No new dependencies.** `do_streams.info.yml` already declared
  `drupal:group` (needed for the new `GroupRelationshipInterface` type hint)
  — confirmed before adding the `use` import. No `.services.yml` change was
  needed for either module (neither new method required a new constructor
  dependency; both classes' existing constructor-injected services —
  `Connection`/`EntityTypeManagerInterface` on `DoDiscoveryHooks`, the
  nullable `ModelToggleHooks` on `DoStreamsHooks` — are unrelated to the new
  hooks' own logic, which only calls static helpers + `Cache::` +
  `\Drupal::time()`).
- **No schema/contract changes.** `do_discovery_hot_score`'s existing table
  schema is unchanged — Fix 2 writes to the SAME columns (`nid`, `score`,
  `computed`) `cron()`/`nodeInsert()` already write, via the same `merge()`
  API shape.
- **New public API surface, both additive:**
  `DoDiscoveryHooks::hotScoreCacheTag(int|string $nid): string` (a new public
  static method, mirroring `DoStreamsHooks::userStreamCacheTag()`/
  `rsvpChipCacheTag()`'s own public-static-tag-builder convention exactly —
  intentionally public so a future `/trending` render consumer outside this
  class can build the same tag string without duplicating the format).
  `DoStreamsHooks::GROUP_MEMBERSHIP_PLUGIN_ID` is a new PRIVATE constant
  (internal to the discriminator logic only, mirrors
  `DoGroupExtrasHooks::GROUP_MEMBERSHIP_PLUGIN_ID`'s identical private
  visibility in the sibling module).
- **Advisory-hold check (per the brief's explicit trigger):** Fix 2's
  render-order assertion (T's `testCommentOnLowerScoredNodeMovesItAheadIn
  Trending`) turned GREEN from the hook + `recomputeNodeScore()` alone — NO
  Views handler subclass or controller wrapper was needed, because
  `/trending`'s view already re-queries on every request (`cache: type:
  none`), so a fresh SQL query against the now-updated `do_discovery_hot_
  score` row is sufficient; there was never a render-cache layer standing
  between the score update and the next request's rendered order. The
  advisory-hold trigger does NOT fire. Confirmed by test result, not merely
  by design intent.

## Deviations from spec / wireframe

None from the brief's Reuse map / advisory-hold instructions — the plan
executed exactly as scoped. Two implementation-level notes, both
non-blocking:

1. **`do_group_extras`'s OWN module-local test fixture**
   (`docs/groups/modules/do_group_extras/tests/fixtures/config/
   views.view.all_groups.yml`, used by `AllGroupsMembershipCacheInvalidation
   Test`) does **NOT** carry Fix 3's `cache: type: none` change — it is a
   snapshot T authored/copied from `docs/groups/config/` at RED time, before
   my Fix 3 edit. Per my role boundary, this is a **test fixture** (T's
   authored artifact under `tests/fixtures/`), not a production file, so I
   did not touch it. I verified (not merely assumed) this is not a
   regression: both tests in that file still pass unmodified after Fix 3
   (see Tier-1 self-check below), because that test suite's own docblock
   explains it pins the QUERY-ALTER's live per-request re-evaluation
   (`testMemberVsNonMemberSeesPrivateGroupWithoutCacheClear`) and a genuine
   anonymous-cache negative control that specifically wants the DEFAULT Views
   `tag` cache plugin's HIT/MISS behavior
   (`testAnonymousViewIsUnaffectedByMembershipChangesOnPrivateGroups`) —
   both are independent of the shipped `all_groups.yml`'s own cache-plugin
   choice. **Flagged for T/S:** this fixture now diverges from the shipped
   config it was copied from; T may want to decide in Phase 6 whether to
   refresh it to keep fixture-vs-shipped drift from growing on a future
   story, but it is not something F should silently "fix" by editing a test
   fixture.
2. **PHPCS invocation.** The issue's literal instruction (`php vendor/bin/
   phpcs docs/groups/modules/do_streams docs/groups/modules/do_discovery
   docs/groups/modules/do_group_extras`, no `--standard` flag) produces 8218
   unrelated errors across every file in all 3 modules (including files
   nobody touched) because this repo has no root `phpcs.xml`/`phpcs.xml.dist`
   and PHPCS's bare invocation falls back to a generic PEAR-style default
   standard, not Drupal's. I used the project's own established convention
   instead — `--standard=Drupal,DrupalPractice` (confirmed against a prior
   F handoff's own phpcs invocation and result framing,
   `docs/handoffs/0119-variant-framework/handoff-F.md:168-170`, and
   `docs/groups/TESTING_STRATEGY.md`'s documented `--standard=phpcs.xml.dist`
   convention) — and scoped it to the 2 production PHP files I changed (Fix
   3 is YAML-only, not phpcs-lintable). See the Tier-1 self-check below for
   the exact before/after comparison this produced.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 139 file(s), excluded 7 env-specific file(s)
==> modules: copied 16 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```
Exit 0.

**Fix 3 — Kernel RED -> GREEN:**
```
$ ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" php vendor/bin/phpunit \
    -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_group_extras/tests/src/Kernel/AllGroupsViewCachePluginTest.php
All Groups View Cache Plugin (Drupal\Tests\do_group_extras\Kernel\AllGroupsViewCachePlugin)
 ✔ All groups default display declares cache type none
OK, but there were issues!
Tests: 1, Assertions: 3, Deprecations: 1, PHPUnit Deprecations: 2.
```
(The 1 deprecation is the pre-existing, framework-wide `KernelTestBase` `#[RunTestsInSeparateProcesses]` notice, unrelated to this change — same class of noise T's own RED confirmation reported.)

**Fix 1 — Functional RED -> GREEN:**
```
$ ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
    SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_streams/tests/src/Functional/MyFeedGroupJoinCacheInvalidationTest.php
My Feed Group Join Cache Invalidation (Drupal\Tests\do_streams\Functional\MyFeedGroupJoinCacheInvalidation)
 ⚠ Group join invalidates user stream cache tag
 ⚠ Group join does not invalidate unrelated users tag
OK, but there were issues!
Tests: 2, Assertions: 18, Deprecations: 44, PHPUnit Deprecations: 3.
```
(⚠ = pre-existing framework deprecation noise per T's own documented finding when running against `phpunit.xml.dist` instead of the project's `phpunit.functional.xml` — no `✘` failure marker.)

**Fix 2 — Functional RED -> GREEN:**
```
$ ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
    SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_discovery/tests/src/Functional/TrendingCommentInvalidationTest.php
Trending Comment Invalidation (Drupal\Tests\do_discovery\Functional\TrendingCommentInvalidation)
 ⚠ Comment on lower scored node moves it ahead in trending
 ✔ Comment insert recomputes hot score row without cron
OK, but there were issues!
Tests: 2, Assertions: 28, Deprecations: 27, PHPUnit Deprecations: 3.
```

**Fix 3 sibling — already-green Functional tests, confirmed still GREEN (non-regression):**
```
$ ddev exec env SIMPLETEST_DB="mysql://db:db@db/db" SIMPLETEST_BASE_URL="http://127.0.0.1:8080" \
    SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_group_extras/tests/src/Functional/AllGroupsMembershipCacheInvalidationTest.php
All Groups Membership Cache Invalidation (Drupal\Tests\do_group_extras\Functional\AllGroupsMembershipCacheInvalidation)
 ⚠ Member vs non member sees private group without cache clear
 ⚠ Anonymous view is unaffected by membership changes on private groups
OK, but there were issues!
Tests: 2, Assertions: 20, Deprecations: 15, PHPUnit Deprecations: 3.
```

**All 4 Functional test files, combined final run (6 tests total):**
```
Tests: 6, Assertions: 66, Deprecations: 49, PHPUnit Deprecations: 9.
```
All 6 show ⚠ or ✔, zero ✘, no `FAILURES` section — matches individual runs above.

**Kernel regression sweep — every Kernel test in the 3 touched modules (99 tests, T's own RED baseline was 99 tests / 1 intended failure):**
```
$ ddev exec "env SIMPLETEST_DB='mysql://db:db@db/db' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
    --testdox \$(find web/modules/custom/do_streams web/modules/custom/do_discovery \
    web/modules/custom/do_group_extras -type d -path '*/tests/src/Kernel')"
OK, but there were issues!
Tests: 99, Assertions: 2859, Deprecations: 40, PHPUnit Deprecations: 73.
```
99 tests, **zero failures** (T's RED baseline: 99 tests, 1 failure = the Fix 3 test). All 98 pre-existing + the now-passing Fix 3 test are green.

**Functional regression sweep — every Functional test in the 3 touched modules (34 tests):**
```
Tests: 34, Assertions: 277, Deprecations: 52, PHPUnit Deprecations: 42, Risky: 3.
```
Zero `✘` markers anywhere in the full output (grep-confirmed), no `FAILURES` section. The 3 "risky" tests are `MyFeedNavLinkTest::testMyFeedNavLinkIsSeeded` and 2 others in files I never touched, flagged only for unexpected stdout printing during the test (a pre-existing test-authorship quirk unrelated to this change, not a `#251` test).

**PHPCS — before/after comparison on the 2 changed production PHP files (`--standard=Drupal,DrupalPractice`, the project's established convention; Fix 3 is YAML-only, not phpcs-lintable):**

| File | Baseline (pre-edit, in-place) | After my edit | Net new |
|---|---|---|---|
| `DoDiscoveryHooks.php` | 13 warnings, 0 errors | 14 warnings, 0 errors | +1 warning (`\Drupal::time()` in `recomputeNodeScore()` — same pattern as 2 pre-existing calls in the same file; a line-length warning I introduced was found and fixed during this pass, see below) |
| `DoStreamsHooks.php` | 8 warnings, 1 error | 8 warnings, 1 error | 0 net new (all 9 findings are the identical pre-existing ones, shifted down by my insertion — confirmed by exact line-shift arithmetic, e.g. 244→258, 659→752) |

0 net-new errors on either file (matches this project's own established
"0 errors" phpcs bar per prior F handoffs). 1 net-new warning
(`DoDiscoveryHooks.php` line 275, `\Drupal::time()`), consistent with this
file's own pre-existing style (2 identical unflagged-as-blocking calls
already exist in the same file at lines 60/85). A line-length warning
(81 chars, docblock prose) was found during this same pass and fixed by
reflowing the sentence — confirmed fixed by re-running phpcs
(0 errors, 14 warnings after the fix, matching the "+1 warning" total above).

```
$ ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php -n \
    docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php \
    docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php"
FILE: .../do_streams/src/Hook/DoStreamsHooks.php
FOUND 1 ERROR AFFECTING 1 LINE
 752 | ERROR | Doc comment short description must be on a single line, further text should be a separate paragraph
```
(`-n` = errors only, no warnings. `do_discovery`'s file shows 0 errors. The 1 error shown is pre-existing — verified at its exact source location, the `theme()` method's docblock, a method I never touched — confirmed by exact line-shift arithmetic against the git-stashed baseline: baseline line 659 -> edited line 752, a shift of +93 lines matching my insertion's exact size.)

## Tests that look wrong (for T)

None. All 3 RED-authored test files and the 1 already-green sibling test
file behaved exactly as T's own handoff described and predicted.

## Known issues

None against the stated acceptance criteria. One non-blocking observation
(not a defect in my own changes) already flagged above under "Deviations":
`do_group_extras`'s module-local test fixture for `AllGroupsMembershipCache
InvalidationTest` now diverges from the shipped `all_groups.yml` it was
copied from at RED time (fixture lacks Fix 3's `cache: type: none`). Both
tests in that file still pass correctly (verified, not assumed) because they
pin behavior independent of that specific config value — flagged for T/S
awareness in Phase 6, not something F edited (test fixtures are T's authored
artifacts).

## Files changed

- `C:/Users/aange/Projects/_worktrees/groups-cache-tag-gaps-251/docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
- `C:/Users/aange/Projects/_worktrees/groups-cache-tag-gaps-251/docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php`
- `C:/Users/aange/Projects/_worktrees/groups-cache-tag-gaps-251/docs/groups/config/views.view.all_groups.yml`

All 3 staged by explicit path (`git add <path>` per file, never `git add .`
or `git add -A`). No test files staged or modified by F.

VERDICT: GREEN
