# Handoff-T-red: Phase 4 - ST-5 Profile activity stream on `/user/{uid}` (#114)

**Date:** 2026-07-23
**Branch:** 114-profile-activity
**Brief / wireframe reviewed:** `docs/planning/handoffs/st5-profile-114/brief.md`, `docs/planning/handoffs/st5-profile-114/wireframe.md`, `docs/planning/handoffs/st5-profile-114/decisions.md`

## A precondition

Confirmed: A returned PASS on the plan (Phase 3) — see decisions.md "A — Phase 3 (up-front plan review)": *"Verdict: PASS -> proceed to T-RED."*

## Tests authored

### Kernel — `docs/groups/modules/do_streams/tests/src/Kernel/UserActivityViewTest.php`

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testPublishedOnlyExcludesUnpublishedNode` | (a) Published-only: an unpublished node authored by the profile owner never appears, even to a viewer who is a group member (rules out access-scoping as the cause). | Kernel | The `status = 1` filter is a Views query-config assertion — executing the compiled view and reading `$view->result` is exact; DOM-scraping would conflate this with rendering concerns. |
| `testAuthorScopingReturnsOnlyProfileOwnersNodes` | (b) Author scoping via the `uid` contextual argument: another author's node in the same group is excluded; the owner's own node is included. | Kernel | Same reasoning — the contextual-argument binding is a query-shape fact. |
| `testAccessScopingExcludesPrivateGroupNodeForNonMember` | (c) Access-scoping negative: a node in a private (non-outsider-granted) group is absent for a non-member viewer. | Kernel | Mirrors `FollowingFeedTest`'s negative case — proves Drupal's node_access + Group's own grants (not a do_streams-specific filter) are what strip the row, which is the exact reliance the brief/A-gate calls out. |
| `testAccessScopingIncludesPrivateGroupNodeForMember` | (c) Sanity companion: the same node IS visible to a viewer who IS a member. | Kernel | Rules out the negative test passing vacuously (e.g. a bug hiding every node). |
| `testResultsOrderNewestFirst` | (d) created DESC ordering (A-gate hedge). | Kernel | Ordering is a query/sort-config fact; cheapest to assert via `$view->result` order. |
| `testDuplicateGroupRelationshipsYieldOneRowPerNode` | (e) `distinct` / no relationship-join fan-out (A-gate hedge). | Kernel | Fan-out is a SQL-shape fact (JOIN + DISTINCT), exactly what kernel view-execution is for. |

### E2E — `tests/e2e/profile-activity.spec.ts`

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `"Recent posts" section renders on Maria's profile...` | Brief checkbox: Playwright spec asserts on the seeded site — "Recent posts" `<h2>` present, Maria's 3 seeded titles listed, newest-first DOM order. | E2E | This is a rendered-DOM / real-page acceptance criterion (heading semantics, actual link text, visual order) that a kernel test cannot observe — the correct, non-duplicating tier for "does the block actually render on the page." |
| `an anonymous visitor to Maria's profile still sees her public-group topic...` | Wireframe's access-safe empty/partial-state requirement: an outsider viewer must see a truthfully scoped (not blanked) section — her public-group title visible, her two private-group titles absent. | E2E | Exercises the real anonymous browsing session end-to-end (login/logout boundary, real node_access enforcement over HTTP) — the DOM-visibility half of criterion (c), complementing the kernel test's SQL-level proof. |

No duplication: the kernel suite pins every query-contract acceptance criterion exactly once; the e2e suite pins only the two facts that require a real rendered page (heading semantics + DOM order; anonymous-session access-safety), not a re-assertion of the SQL-level scoping already proven by the kernel suite.

## RED confirmation

**Environment note:** this worktree's `.ddev/config.yaml` (`name: gm124-directory`) collided with the already-running `groups-directory-toggle-124` project (pre-existing staleness shared by several older worktrees, unrelated to this story). Locally renamed to `gm114-profile` for verification only, then reverted (`git checkout -- .ddev/config.yaml`) — not part of this diff.

**Kernel:**
```
bash scripts/ci/assemble-config.sh   # via `ddev exec`, after `ddev composer install`
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/UserActivityViewTest.php'
```
Output (all 6 tests, identical failure point):
```
User Activity View (Drupal\Tests\do_streams\Kernel\UserActivityView)
 ✘ Published only excludes unpublished node
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above /var/www/html/web/modules/custom/do_streams/tests/src/Kernel
 ✘ Author scoping returns only profile owners nodes
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above ...
 ✘ Access scoping excludes private group node for non member
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above ...
 ✘ Access scoping includes private group node for member
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above ...
 ✘ Results order newest first
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above ...
 ✘ Duplicate group relationships yield one row per node
   │ Could not locate shipped views.view.user_activity.yml in docs/groups/config or config/sync above ...

Tests: 6, Assertions: 108, Failures: 6.
```
Right reason: `views.view.user_activity.yml` genuinely does not exist anywhere in the repo (`grep -rn user_activity docs/groups/config docs/groups/modules/do_streams` returns zero hits outside the new test file). This is the F-owned artifact (brief.md "Files touched") the assertion-under-test needs — the same `$this->fail()`-in-setUp() pattern `FollowingFeedTest.php` uses for the identical reason.

**E2E:**
```
npx playwright test --list tests/e2e/profile-activity.spec.ts
```
```
Listing tests:
  [chromium] › profile-activity.spec.ts:73:7 › ST-5 — Profile activity stream (#114) › "Recent posts" section renders on Maria's profile with her seeded topics, newest first
  [chromium] › profile-activity.spec.ts:123:7 › ST-5 — Profile activity stream (#114) › an anonymous visitor to Maria's profile still sees her public-group topic in "Recent posts"
Total: 2 tests in 1 file
```
Valid spec structure confirmed. For a live-DOM RED: ran a **minimal** `drush site:install` (no config-import, no demo-data seed — same proportionality call as `docs/handoffs/111-stream-following/handoff-T-red.md` for the identical class of "artifact provably absent" RED) and executed:
```
ddev drush site:install minimal --db-url=mysql://db:db@db/db -y
npx playwright install chromium --with-deps
BASE_URL="https://gm114-profile.ddev.site" npx playwright test tests/e2e/profile-activity.spec.ts
```
Result: both tests fail inside `loginAndGetUid()` ("Unrecognized username or password") because `maria_chen` does not exist in this unseeded DB — a different trigger than the missing "Recent posts" heading, but a valid RED for the same underlying cause (the view, block, and CSS this story delivers are provably absent from the tree; a full assemble→config:import→seed run was judged disproportionate effort for RED-only verification, matching the precedent in `docs/handoffs/111-stream-following/handoff-T-red.md`). **T(GREEN) will re-run against the fully assembled + config-imported + seeded site** (per the project override's "faithful RED/GREEN" instruction) once F has created `views.view.user_activity.yml`, `block.block.do_streams_user_activity.yml`, and `profile-activity.css` — that is the correct point to validate the DOM-level assertions (heading, three titles, newest-first order, anonymous partial-access render) for real.

## Ready for F

Confirmed RED is valid on both fronts:
- Kernel: 6/6 tests fail for the correct reason (missing production artifact at the exact integration seam the assertions target), not an import/typo/setup error unrelated to the feature.
- E2E: spec is structurally valid (lists correctly) and produces a genuine browser-level RED against a live Drupal instance; the specific trigger (persona absence in an unseeded DB) is documented above as a proportionality call, not a defect in the test authorship — the artifacts under test remain provably nonexistent regardless of seed state.

F may implement against these tests.
