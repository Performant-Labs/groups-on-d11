# Handoff-T-red: Phase 4 - #129 ST-7 Activity feed rendering

**Date:** 2026-07-23
**Branch:** 129-activity-feed-render
**Brief / wireframe reviewed:**
- `docs/planning/handoffs/129-activity-feed/brief.md`
- `docs/planning/handoffs/129-activity-feed/survey.md`
- `docs/planning/handoffs/129-activity-feed/wireframe.html` (approved)

## A precondition

Review rigor for this story is `none` per the brief ("Review rigor: `none` (per issue). No
brief-gate. No diff-gate. Skip A-dup (POC lean pipeline)."). No A gate applies to Phase 4 for this
story — proceeding directly per the brief's explicit instruction.

## Tests authored

All under `docs/groups/modules/do_activity_feed/tests/` (module itself does not exist yet — this
tree establishes it) and `tests/e2e/activity-feed.spec.ts`.

1. **`ActivityFeedKernelTestBase.php`** — shared fixture base (not a test itself), mirroring
   `do_activity`'s own `ActivityKernelTestBase` (same module stack + `installConfig(['do_activity'])`
   step for the six message templates). Adds `do_activity_feed` to `$modules` and three helpers
   (`createPostMessage`, `createMembershipMessage`, `createCommentMessage`) building real `Message`/
   `Comment`/`Node` fixtures other tests reuse.

2. **`ActivityFeedRenderTest.php`** (Kernel) — AC-1, AC-3, AC-4:
   - `testFeedRendersInterleavedRowTypes` (AC-1): seeds one `activity_membership_created` (social),
     one standalone `activity_post_created` (content), and a 3-run of `activity_post_created` <=5h
     apart (aggregated, count=3); asserts `ActivityFeedController::renderFeed('my_groups')` returns
     all three row types.
   - `testAccessScopingRestrictsToViewersGroups` (AC-3): a Group-A member sees only Group A's rows;
     a user in zero groups sees an empty result.
   - `testContentRowOmittedWhenNodeNotViewable` (AC-4): an unpublished node's row is dropped
     entirely (not an access-denied placeholder); a viewable sibling row from the same author still
     appears, isolating the omission to the access check.
   - Tier: kernel — pure PHP/Views-query row-shape and scoping behavior; no HTTP/client layer needed.

3. **`ActivityAggregationTest.php`** (Kernel) — AC-2a/2b/2c, directly against `ActivityAggregator`:
   - `testTwoPostsFiveHoursApartAggregateWithCountTwo` (AC-2a): baseline fold, count=2.
   - `testThreePostsWithGapPastWindowSplitsIntoTwoBuckets` (AC-2b): t=0/t=5h/t=13h — first two fold,
     third (8h gap from its predecessor) opens a new bucket.
   - `testThreePostsChainWithinPairwiseWindowAggregateAsOneBucket` (AC-2c): t=0/t=5h/t=10h — all
     three chain into ONE bucket via pairwise-consecutive comparison (t=0-to-t=10h alone is 10h,
     which would fail an anchor-based window — this is the test that actually distinguishes
     "pairwise consecutive" from "all-pairs-within-window," per the brief's A-advisory #5).
   - `testDifferentActorsDoNotAggregateTogether`: guards against a naive elapsed-time-only fold that
     ignores the `(actor, template, group)` bucket key.
   - Tier: kernel, invoking the aggregation service directly with hand-built Message timestamps —
     cheaper and more precise than exercising this through the controller/render array for exact
     boundary math.

4. **`ActivityCommentSnippetTest.php`** (Kernel) — AC-5, directly against `ActivityRowBuilder`:
   - `testShortCommentBodyPassesThroughUntruncated`: baseline passthrough (proves the snippet is a
     real transform, not a stub).
   - `testLongCommentBodyIsTruncatedToOneEightyChars`: >180-char body truncated to <=180 chars.
   - `testHtmlTagsAreStrippedFromSnippet`: HTML (including `<script>`) stripped, inner text preserved.
   - Tier: kernel — a real `Comment` + `Message` fixture is needed to prove the snippet reads the
     actual `comment_body` field, but no rendering/HTTP layer is required.

5. **`ActivityViewsFilterTest.php`** (Kernel) — A-advisory #3, `hook_views_data` registration:
   - `testActivityMembershipScopeIsRegisteredOnMessageFieldData`: `views.views_data` for
     `message_field_data` contains `do_activity_feed_membership_scope` with
     `filter.id => 'do_activity_feed_membership_scope'`.
   - `testActivityMembershipScopeFilterPluginIsDiscoverable`: the Views filter plugin manager can
     resolve the plugin id at all.
   - Deliberately small/standalone per the brief's own framing ("small, standalone test — critical
     because without it the view config cannot load") — does NOT re-execute a full view (AC-3
     already covers scoping behavior end-to-end in `ActivityFeedRenderTest`), avoiding duplicate
     coverage of the same acceptance criterion at a more expensive tier.

6. **`tests/e2e/activity-feed.spec.ts`** (E2E, Playwright) — AC-6:
   - my_groups scope: >=1 social row, >=1 aggregated row, >=1 content row for the Elena persona;
     plus a keyboard-operability check on the aggregated row's native `<details>/<summary>`.
   - Group-scope variant (`/activity/group/<gid>`): discovers the gid from the my_groups feed's own
     rendered link (never hardcoded), asserts every rendered row's group link points at that one gid.
   - Empty state: the default/anonymous persona (no seeded group membership) sees
     `[data-testid="activity-feed-empty"]`, zero rows of any type.
   - **Fixture approach (required by the brief):** a new drush-scr seed script,
     `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php`, run as part of the normal seed
     sequence (after `step_7xx_backfill_activity.php` / `step_790_persona_switcher.php`). Chose a
     drush-scr script over an HTTP pre-test hook because (a) it matches every existing fixture
     convention in this repo (step_700/770/790 are all drush-scr includes with their own
     idempotency keys — no HTTP-fixture convention exists to extend instead), and (b) it can pin
     EXACT `Message` `created` offsets (Alex's 3-post run each 2h apart, guaranteed <=6h apart
     regardless of seed-script wall-clock time), which an HTTP-driven UI flow could not guarantee
     precisely enough to avoid flaking on the aggregation shape — see the spec's own header comment
     and the script's docblock for the full rationale.

## RED confirmation

Environment: DDEV (`gm129-activity`), assembled via `bash scripts/ci/assemble-config.sh` run
**inside the DDEV web container** (the host checkout's `vendor/` was stale/incomplete at session
start — `ddev composer install` was run first; assemble-config's own hard-fail on a missing
`vendor/autoload.php` is a real, intentional guard, not a bug). Kernel suite invoked via:

```
ddev exec "cd /var/www/html && SIMPLETEST_DB='mysql://db:db@db:3306/db' \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_activity_feed/tests/src/Kernel/ActivityFeedRenderTest.php"
```

Result (identical for every one of the four test files):

```
An error occurred inside PHPUnit.

Message:  Class "Drupal\Tests\do_activity_feed\Kernel\ActivityFeedKernelTestBase" not found
Location: /var/www/html/web/modules/custom/do_activity_feed/tests/src/Kernel/ActivityFeedRenderTest.php:32
```

**Why this is the right-for-the-wrong-code RED, not a test-authoring bug:** Drupal's PHPUnit
bootstrap (`web/core/tests/bootstrap.php`, `drupal_phpunit_find_extension_directories()`) registers
a module's `Drupal\Tests\<module>\*` namespace with the classloader ONLY by finding a real
`<module>.info.yml` on disk. `do_activity_feed.info.yml` does not exist (F has not yet created the
module — correctly; that is F's job, not mine), so the entire `Drupal\Tests\do_activity_feed\*`
namespace is unregistered and PHPUnit's `require_once` on any file in that namespace fails at
class-not-found before a single assertion runs. Confirmed this is an environment/module-absence
signal (not a mistake in my fixture code) by:
- `php -l` on all five test/base files — no syntax errors.
- Running `do_streams`' own equivalent kernel suite (`StreamsScopeTest.php`, a real, working,
  merged module) with the identical `SIMPLETEST_DB` invocation — 7/7 pass cleanly, proving the
  harness/DB/assemble pipeline itself is sound and the failure is specific to `do_activity_feed`'s
  absence, not a broken test environment.

**E2E:** `npx playwright test --list tests/e2e/activity-feed.spec.ts` — lists all 4 tests cleanly,
no parse error:
```
[chromium] › activity-feed.spec.ts:93:7 › ... shows at least one social row, one aggregated row, and one content row
[chromium] › activity-feed.spec.ts:113:7 › ... the aggregated row is a native <details>/<summary> disclosure, keyboard-operable
[chromium] › activity-feed.spec.ts:136:7 › ... /activity/group/<gid> shows only that group's rows
[chromium] › activity-feed.spec.ts:172:7 › ... a user in no groups sees the empty state, not an error or generic "no results"
Total: 4 tests in 1 file
```
Per the task instructions, the E2E spec is not executed for real at RED time (the site isn't seeded
with the module yet); it will run against the fully seeded, assembled site at T-GREEN.

## Ready for F

RED is valid — every kernel test fails because the module genuinely does not exist yet (confirmed
against a known-good sibling suite), and the E2E spec parses cleanly with no markup/route assumption
resolvable yet. F may implement `do_activity_feed` against this suite.

## Deviations from the brief

- **DDEV rename**: `.ddev/config.yaml` was still pointed at a stale sibling worktree's project name
  (`gm124-directory`); renamed to `gm129-activity` per the brief's own "DDEV rename" section and
  staged as part of this handoff (local-only; unaffected by CI, as the brief notes).
- **New file, not in the brief's explicit owned-files list**:
  `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` — required by the brief's own AC-6
  wording ("If the seeded demo data doesn't naturally produce this shape, the spec MUST set up its
  fixtures via ... a `step_7xx_backfill_activity.php` companion + a small pre-test seed hook") and
  by the task instructions' explicit menu of options. Numbered 795 (after 790, before the next
  unclaimed step) per the existing append-only step-script convention.
- **`ActivityMembershipScope`'s exact filter/plugin id** (`do_activity_feed_membership_scope`) was
  not explicit in the brief beyond "the concrete SQL shape (like MembershipScope does)" — I derived
  the id from do_streams' own `do_streams_membership_scope` naming convention
  (`<module>_membership_scope`) for consistency; F should treat `ActivityViewsFilterTest`'s asserted
  id as the pinned contract, not merely a suggestion, since the view config's filter can only resolve
  by this exact id.
- **Service ids** (`do_activity_feed.aggregator`, `do_activity_feed.row_builder`) and their public
  method names (`ActivityAggregator::aggregate(array $messages): array` returning bucket arrays with
  a `count` key; `ActivityRowBuilder::buildCommentSnippet($message): string`) were not specified
  verbatim in the brief/survey — I chose the minimal shape needed to pin AC-2/AC-5 directly rather
  than only through the controller's full render array, matching survey.md's own
  `do_activity_feed.services.yml` (`ActivityAggregator`, `ActivityRowBuilder`) naming. F is free to
  add additional methods but must preserve these two signatures (or the exact behavior they pin) so
  T-green's re-run is a real GREEN, not a rewritten test.

## Spec ambiguity for F (non-blocking, flagged per instructions)

- The brief's row-model vocabulary (survey.md) uses `type` values `content_card | social_join |
  social_rsvp | social_comment | social_group_created | social_pin | aggregated`, but the wireframe's
  `data-testid` attributes only distinguish three CSS/testid buckets:
  `activity-row-content` (maps to `content_card`), `activity-row-social` (maps to `social_join` /
  `social_rsvp` / `social_comment` / `social_group_created` / `social_pin` — all five collapse to one
  testid), and `activity-row-aggregated`. My kernel tests assert the finer-grained `type` values
  (`social_join`, `content_card`, `aggregated`) on the render-array row model directly, since that is
  the more precise underlying contract; the E2E spec asserts only the three coarser `data-testid`
  buckets, matching what the wireframe actually renders. This is not a contradiction — just two
  different layers of the same contract — but flagging in case F expects the render array's `type`
  key to already be collapsed to the three testid buckets rather than the six granular values
  survey.md's row model section names.
- AC-2's brief wording says aggregation is "reserved for a later story" for
  `activity_flagging_created`, but is otherwise silent on whether `ActivityAggregator::aggregate()`
  should be handed ALL messages (all templates) and internally filter to the two aggregable
  templates, or whether callers are expected to pre-filter (as my tests do, passing only
  `activity_post_created` messages). My tests exercise the pre-filtered-input shape; if F's
  aggregator instead expects the full mixed-template result set, the two `aggregate()`-calling test
  files must be revisited together with F (not a silent T-side test rewrite).
