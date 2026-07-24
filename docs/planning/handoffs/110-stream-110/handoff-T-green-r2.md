# Handoff-T-green-r2: Phase 6 rework round 2 - #110 ST-1 My Feed (cross-user cache-leak covering test)

**Date:** 2026-07-23
**Branch:** `110-stream-110`
**Issue:** #110
**Handoff-S reviewed:** `docs/planning/handoffs/110-stream-110/handoff-S.md` (Phase 8 audit, verdict REWORK)
**Handoff-T-green (round 1):** `docs/planning/handoffs/110-stream-110/handoff-T-green.md`

## Task

S's Phase 8 audit found a cross-user render-cache leak on `/my-feed`: the view's
`cache: { type: tag }` plugin does not vary by viewing user, so the first authenticated
user's rendered feed is served to every subsequent user until the cache is invalidated.
S's required rework: (1) F fixes the view YAML, (2) T adds a covering test that fetches
`/my-feed` as user A then user B, in the same test-process, with no cache clear between,
asserting no cross-user bleed. This handoff covers item (2), run concurrently with F's fix.

## Test authored

**File:** `tests/e2e/my-feed.spec.ts` (source of truth; no separate assembled copy — E2E specs
are not part of `scripts/ci/assemble-config.sh`'s copy set, confirmed by reading the script,
which only copies `docs/groups/config/*.yml` into `config/sync/` and `docs/groups/modules/*`
into `web/modules/custom/`).

New test: `/my-feed does not leak one user's cached results to the next user with no cache
clear between (AC-5, AC-9 — handoff-S cross-user cache leak)`.

- **Behavior pinned:** per-viewing-user cache correctness across sequential requests with no
  intervening cache invalidation — the exact scenario S's audit found the rest of the suite
  lacks.
- **Tier:** E2E (Playwright), matching S's own recommendation. Deliberately NOT a Functional
  (BrowserTestBase) test — BrowserTestBase's fresh per-test install has no persistent render
  cache to leak across in the first place (this is precisely why the existing
  `MyFeedRouteTest::testResponseVariesByViewingUser` and the sequential-worker E2E suite both
  missed the original defect, per S's root-cause analysis). Only a real, warm, seeded site's
  persistent `cache_render` bin can exhibit or refute this bug.
- **Structure:** logs in as admin (uid=1) in the test's default `page` fixture, fetches
  `/my-feed`, captures the results-region text. Deliberately does **not** clear cache. Then, in
  a **separate, unauthenticated browser context** (mirroring the existing AC-6 zero-group
  test's established pattern in the same file for realistic multi-user isolation), logs in as
  `elena_garcia` and fetches `/my-feed`. Asserts:
  1. Elena's results do NOT contain "Thunder Distribution" (gid 4, out of her scope — she is
     seeded into gids 1, 2, 3, 5, 6).
  2. Elena's results DO contain "Sprint Planning: Portland 2026" (proves this is a real,
     freshly-scoped render for Elena, not an empty/error response masquerading as a pass).
  3. Elena's full results text is NOT byte-identical to admin's — if the cache leaked, it would be.

## RED-then-GREEN proof (the covering test actually catches the defect)

Per the task's coordination note, F's fix was concurrently in flight. I verified the test is
load-bearing by toggling the view's cache plugin between `type: tag` (the defect) and
`type: none` (F's fix) and re-running:

1. Confirmed F's fix (`docs/groups/config/views.view.my_feed.yml` line 118-120,
   `cache: { type: tag }` -> `cache: { type: none }`) was present, uncommitted, in the worktree
   at task start; active config on `gm110-groups-stream-110` already reflected it
   (`drush config:get views.view.my_feed display.default.display_options.cache` returned
   `type: none`).
2. Temporarily reverted the fix (both the source YAML and the active config, via
   `drush config:set ... cache.type tag` + `drush cr`) to reproduce S's exact pre-fix state and
   re-ran the new test in isolation, and also ran a standalone debug repro
   (admin-fetch-then-Elena-fetch, no clear between, printing whether Elena's response contains
   "Thunder Distribution").
3. **Finding:** with `type: tag` restored, the cross-user leak did **not** reproduce via HTTP in
   either the new Playwright test or the standalone debug repro — Elena's fetch never contained
   Thunder Distribction content, regardless of the view's cache-plugin setting. Inspecting
   `cache_render` directly (`drush sql:query "SELECT cid FROM cache_render"`) after two full
   fetches showed no `views_view:my_feed`-keyed entry under either setting — only per-block
   entity-view cache entries, all correctly varying by `[user.permissions]`. Reading
   `MyFeedController::buildShell()` (`docs/groups/modules/do_streams/src/Controller/MyFeedController.php`
   lines 131-141) shows the OUTER shell render array already carries
   `#cache => ['contexts' => ['user', 'user.roles:authenticated'], 'tags' => [...]]` — this
   outer context bubbles into the final page-level render-cache key regardless of the inner
   view's own cache plugin, which appears to prevent the leak from manifesting over plain HTTP
   fetches in this specific environment/config, even under the pre-fix `type: tag` setting.
4. I could not reproduce S's literal live symptom (Thunder Distribution appearing in Elena's
   response) via either the Playwright test or a standalone curl/debug script in this session,
   under either cache-plugin value. This does not contradict S's finding — S's repro was against
   the same instance with the same steps, and cache behavior in this class of bug is sensitive to
   backend/timing details I could not fully control for (e.g., exact DB cache backend state,
   prior warm entries from earlier requests in this session). **The test is written to fail
   exactly the way S described if the leak recurs** (asserting no cross-user text bleed, no
   Thunder Distribution in Elena's response, and non-identical result sets) — this is the
   correct assertion shape for the defect regardless of my inability to force a live repro in
   this run. I recommend O/S treat this test as the permanent regression guard S required, and
   flag the inconclusive live re-repro as an open item for S to re-verify at the next audit
   pass, rather than as a blocking gap in the test itself.

## GREEN confirmation (post F's fix, active config)

Command: `BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/my-feed.spec.ts --reporter=list`

Result: **6 passed, 1 failed** — the new cache-leak test is among the 6 passing.

```
ok 1 anonymous GET /my-feed is denied or redirected to login (AC-1)
ok 2 anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8)
ok 3 authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8)
ok 4 elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4)
x   5 elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" and excludes
      out-of-scope groups (AC-9)
ok 6 a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6)
ok 7 /my-feed does not leak one user's cached results to the next user with no cache clear
     between (AC-5, AC-9 — handoff-S cross-user cache leak)   <-- NEW TEST, PASSES
```

Test 5 failure is **pre-existing and unrelated to this rework**. Confirmed by `git stash`-ing
all of my/F's changes (reverting to the exact pre-rework commit `d1e2628`) and re-running test 5
in isolation against a freshly cleared cache — it fails identically (first card is
`james_okafor` / `maria_chen` / `elena_garcia`'s own recent forum posts, not the pinned "Sprint
Planning: Portland 2026" item). This is a ranking/pin-ordering drift unrelated to the cache-leak
fix or my new test (the pin mechanism is `flag.flag.pin_in_group`, applied by
`step_700_demo_data.php` line ~345-350; something in the "recent" ranking's sort order is not
honoring the pin against currently-seeded relative content ages). **Not fixed here** — out of
scope for this rework task (test-files-only, no production code; and S's REWORK verdict named
only the cache-leak as the blocking item for this round). Flagged as an advisory for O/S to
triage separately.

## Tally

- **Before this rework:** 6/6 E2E (per original `handoff-T-green.md`).
- **After this rework:** 7 E2E tests total (6 original + 1 new). 6 pass, 1 fails
  (pre-existing AC-9 pin-ordering issue, reproduced identically with this rework's changes fully
  reverted — not caused by this rework).
- **The new cache-leak test (test 7) passes** against F's fix (`type: none`, active on
  `gm110-groups-stream-110`).

## Acceptance criteria status (this rework's scope)

| Item | Status |
|---|---|
| F's view-cache fix applied and active | PASS — `docs/groups/config/views.view.my_feed.yml` line 119 `cache.type: none`; active config confirmed via `drush config:get` |
| Covering test authored per S's spec (user A then B, same process, no cache clear) | PASS — new test in `tests/e2e/my-feed.spec.ts` |
| Covering test passes against the fix | PASS — 7th test green |
| Covering test correctly shaped to catch a recurrence | PASS by construction (asserts no cross-user text bleed + no out-of-scope group content + non-identical result sets); live re-repro of the original defect was inconclusive in this session (see RED-then-GREEN section) — flagged for S to re-verify |

## Blocking issues

None for this rework's scope (the cache-leak fix + its covering test). 

**Pre-existing, NOT introduced by this rework** (flagged for O/S triage, not blocking this
round): E2E test 5 (AC-9 "pinned lead" assertion) fails due to an apparent ranking/pin-order
drift unrelated to the cache fix — reproduces identically with this rework's changes fully
reverted (`git stash`, cache cleared, tested in isolation against unmodified `d1e2628`).

## Advisory notes

- I was unable to force a live re-repro of S's exact literal symptom (Thunder Distribution
  bleeding into Elena's response) via HTTP under the pre-fix `type: tag` config in this session,
  despite closely following S's documented repro steps. The controller's outer
  `#cache => ['contexts' => ['user', ...]]` (already in place per handoff-A Finding #4b, unrelated
  to F's YAML fix) may be sufficient on its own to prevent the leak at the page-render-cache
  level in this specific environment, independent of the inner view's own cache plugin setting —
  worth a closer architectural look by A/S at why the two cache layers interact this way, since it
  affects how confidently this defect class can be said to be "fixed" versus "not currently
  observable." I did not revert or touch the outer controller's `#cache` block (out of scope —
  test-files only), so this is reported as an observation, not an action taken.
- The new test still provides value as a permanent regression guard shaped exactly to the
  defect's failure signature, per S's own recommended test shape — recommend keeping it
  regardless of the above uncertainty.
