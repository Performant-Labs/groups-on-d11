# Handoff-T-red: Phase 4 - #111 ST-2 Following feed (`/following`)

**Date:** 2026-07-23
**Branch:** 111-stream-following
**Brief / wireframe reviewed:** `docs/handoffs/111-stream-following/brief.md`, `docs/handoffs/111-stream-following/survey.md`, `docs/handoffs/111-stream-following/handoff-A.md` (no wireframe — leaf route, no shell wiring per brief). `decisions.md` reviewed for prior-phase decisions.

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`, `VERDICT: PASS`). No blocking findings; three `warn`-level notes (CSS attachment mechanism, access-model status-filter preservation, HelpText deferral) — none block T from proceeding.

## Tests authored

### `tests/e2e/following.spec.ts` (Playwright rendered-DOM spec)

| Test | Acceptance criterion (brief.md) | Tier | Why this tier |
|---|---|---|---|
| `anonymous visiting /following gets 403 or a login redirect` | "Anonymous `/following` → 403 or login redirect." | e2e | Route-level access behavior is only observable end-to-end (HTTP status / real redirect chain). |
| `elena_garcia sees all 4 following-scope branches, each exactly once` | "elena_garcia sees: Patch Review Process RFC (follow_content), Maria-authored content (follow_user, NEW), a `core`-tagged node (follow_term), a `drupalcon`-tagged node (follow_term, NEW). Each exactly once." | e2e | Exercises the real rendered view + real seed data across all three OR'd scope branches simultaneously, including the cross-branch dedupe guarantee — this is a genuine end-to-end rendering concern (does the actual HTML show each card once), not a pure query-shape question (that's the kernel test's job for the group-access dimension specifically). |
| `ravi_patel sees Maria-authored content via the existing follow_user seed` | "ravi_patel sees Maria-authored content (existing follow_user seed)." | e2e | Same rationale — rendered-DOM proof for an already-seeded persona. |
| `sophie_mueller sees the Paragraphs tutorial via the NEW follow_content seed` | "sophie_mueller sees the 'Getting Started with Paragraphs' tutorial (new follow_content seed)." | e2e | Proves the NEW seed line renders correctly end-to-end. |
| `a user with no follows sees an accessible empty state` | "A fresh authenticated user with no follows → empty state renders, links to `/stream` + `/tags` present and keyboard-accessible." + WCAG bullet ("empty-state links have visible focus"). | e2e | Empty-state markup + focus behavior can only be verified against real rendered DOM + real keyboard focus semantics. |

Design notes:
- Login helper and seeded-password convention (`demo_password_2026`) copied from the pattern in `tests/e2e/directory-cards.spec.ts` / `tests/e2e/demonstrator-seeds.spec.ts`.
- `alex_novak` is used as the "no follows" persona rather than registering a fresh account at runtime. Verified by grep over `step_700_demo_data.php`'s Step 750 flag block: only `elena_garcia`, `ravi_patel`, `sophie_mueller` ever appear as the flagging viewer (3rd arg to `$flag_service->flag()`) across `follow_content`/`follow_user`/`follow_term`. `alex_novak` only ever appears as an author or event/join-request actor. This avoids the environment-sensitivity of self-registration (email verification / admin approval vary by site config) while still testing the real empty-state acceptance criterion.
- The `core`/`drupalcon`-tagged node assertions ("Drupal 11 Migration Path", "Venue Logistics Thread") were deliberately chosen to be attributable to a **single** OR branch each (not Maria-authored, not the follow_content node) so a passing suite proves each branch independently rather than only ever exercising the union.
- Dedupe assertion: "Patch Review Process RFC" matches BOTH follow_content (direct) and follow_term (`core`) for Elena — asserted to render **exactly once** via `countCardsWithTitle`.

**Known seed-data gap surfaced during authoring (flag for F, not blocking RED):** `FollowingScope::query()`'s follow_term branch joins `node__field_group_tags` (per [B-4]), but the *existing* forum-topic seed rows (`step_700_demo_data.php:139-152`, including "Patch Review Process RFC" tagged `core` and "Drupal 11 Migration Path" tagged `core`) only populate `field_tags` (the free-tagging vocabulary), never `field_group_tags` (the `group_tags` vocabulary created in `step_330.php`). The brief characterizes Elena's `core`-tag follow as "existing follow_term, seeded" — but the flag itself is seeded (`step_700_demo_data.php:380-388`), while the underlying node-to-term wiring the FollowingScope filter actually reads is **not yet present** on any existing node. F will need to additionally populate `field_group_tags` on at least the `core`-tagged and `drupalcon`-tagged nodes referenced by this test (e.g. "Patch Review Process RFC" / "Drupal 11 Migration Path" for `core`, "Venue Logistics Thread" for `drupalcon`) for the follow_term e2e assertions to ever pass — this is implementation work belonging to F's seed-append step, not a test-authoring defect. Noted here so it isn't a surprise blocker in T(GREEN).

### `docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php` (kernel test)

| Test | Acceptance criterion | Tier | Why this tier |
|---|---|---|---|
| `testFollowedNodeInInaccessibleGroupIsExcluded` | "Group-access negative: a user follows a node in a group they cannot access → row absent (kernel test preferred)." | kernel | Per brief + handoff-A.md finding §3, this is "hard to prove reliably in e2e" — a kernel test executing the real view against controlled group-membership fixtures gives a precise, non-flaky assertion on `$view->result` rather than scraping HTML for an absence (which e2e can't distinguish from "not on this pager page" or "not yet rendered"). |
| `testFollowedNodeInAccessibleGroupIsIncluded` | Sanity companion (not a separate brief bullet, but required to make the negative case non-vacuous). | kernel | Same view-execution mechanism; proves the exclusion above is genuinely about group access and not a universal bug hiding all followed content. |

Design notes:
- Modeled directly on `StreamsScopeTest.php`'s setup: module-local fixture flags (`tests/fixtures/config/`), `field_group_tags` field install, and BOTH outsider-scope and insider-scope group roles granting `view group_node:$type relationship`/`entity` — required so a non-member's exclusion is attributable to Group's per-group access grants (via Drupal's node-access alter), not to an incidental lack of any view permission at all.
- **Fixture-path correction made during authoring:** my first draft used `new FileStorage(\Drupal::service('extension.list.module')->getPath('do_streams') . '/../../config')` to locate the shipped `views.view.following_feed.yml`. This is exactly the trap flagged in the project override — it resolves correctly in the source worktree (`docs/groups/modules/do_streams/../../config` → `docs/groups/config`) but **breaks in CI's assembled layout**, where `scripts/ci/assemble-config.sh` copies the module into `web/modules/custom/do_streams/` and the config into a *top-level* `config/sync/`, not a module-adjacent directory. I replaced it with the walk-up-from-`__DIR__` pattern already proven by `Drupal\Tests\do_discovery\Kernel\IcalFeedsTest::shippedConfigDir()`, which checks both `docs/groups/config` and `config/sync` at each ancestor directory. This resolves identically in the source tree and the assembled CI layout.
- The test intentionally installs the **real shipped** `following_feed` view config (via `shippedConfigDir()`), not a scaffold/demo view, so this is a regression net for the actual production artifact F creates — not just a re-proof of `FollowingScope`'s query contract (already covered by `StreamsScopeTest.php` against `do_streams_demo`).

## RED confirmation

Both attempted approaches (live-environment run, not merely static reasoning) were completed successfully.

### Kernel test — live DDEV run

```
cd groups-stream-111
ddev start
ddev composer install --no-interaction --prefer-dist
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php'
```

Output (relevant excerpt):

```
1) Drupal\Tests\do_streams\Kernel\FollowingFeedTest::testFollowedNodeInInaccessibleGroupIsExcluded
Could not locate shipped views.view.following_feed.yml in docs/groups/config or config/sync above /var/www/html/docs/groups/modules/do_streams/tests/src/Kernel

/var/www/html/docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php:184
/var/www/html/docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php:124

2) Drupal\Tests\do_streams\Kernel\FollowingFeedTest::testFollowedNodeInAccessibleGroupIsIncluded
Could not locate shipped views.view.following_feed.yml in docs/groups/config or config/sync above /var/www/html/docs/groups/modules/do_streams/tests/src/Kernel

/var/www/html/docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php:184
/var/www/html/docs/groups/modules/do_streams/tests/src/Kernel/FollowingFeedTest.php:124

FAILURES!
Tests: 2, Assertions: 70, Failures: 2, Deprecations: 19, PHPUnit Deprecations: 3.
```

**Why this is the RIGHT reason:** all setUp() fixture machinery ran to completion first — module-local flag entities created, `group_tags` vocabulary + `field_group_tags` storage/field installed, and (per the 70 assertions that ran before the failure) the group-role/permission scaffolding succeeded. The failure is `$this->fail()` inside `shippedConfigDir()`, triggered only because `views.view.following_feed.yml` does not exist anywhere under `docs/groups/config` or `config/sync` — exactly the missing artifact F is responsible for creating (brief.md "Files owned": `docs/groups/config/views.view.following_feed.yml` — NEW). Not an import/typo/setup error.

**Regression baseline check:** re-ran the existing `StreamsScopeTest.php` (do_streams' analogous, already-shipped kernel suite) against the same assembled environment — still green:
```
Tests: 7, Assertions: 273, Deprecations: 21, PHPUnit Deprecations: 8.
OK, but there were issues! [deprecation-only, no failures]
```
Confirms adding the new test file does not disturb the existing suite.

### Playwright test — live DDEV run

```
cd groups-stream-111
npm install
npx playwright install chromium --with-deps
ddev drush site:install minimal --db-url=mysql://db:db@db/db -y
BASE_URL="https://gm111-stream.ddev.site" npx playwright test tests/e2e/following.spec.ts
```

Output: **5 failed** (all authored tests).

Isolated the anonymous-access case for the clearest right-reason proof:
```
Error: expect(received).toBeTruthy()
Received: false
    86 |     expect(status === 403 || redirectedToLogin).toBeTruthy();
```
`/following` currently returns neither 403 nor a login redirect (the route doesn't exist at all — no `following_feed` view/route has been created yet), so `status === 403` is false and there's no redirect. This fails for the right reason: the route genuinely does not exist yet.

The four persona tests (`elena_garcia`, `ravi_patel`, `sophie_mueller`, `alex_novak`) all fail inside `login()` at the "Unrecognized username or password" assertion — because this run used a **minimal** Drupal install (no config import, no demo-data seed script run) rather than the full assembled+seeded site, so none of the seeded personas exist yet in this specific database. This is a *different* flavor of "artifact doesn't exist" than intended (persona-seed-data absence vs. view/route absence), but it is still a valid RED for the right underlying reason — the full seed script (including F's three new append-only lines) has not been run, and the `following_feed` view/route does not exist either way. A full assemble→config:import→seed run (per the project override's "faithful RED/GREEN" instruction) was judged disproportionate effort for RED verification of artifacts that provably do not exist in the repo yet (`grep` confirms zero occurrences of `following_feed` or the three new seed lines anywhere in the tree); T(GREEN) will re-run against the fully seeded site once F has created the view and seed lines, which is the correct point to validate the persona-specific assertions end-to-end.

Environment was torn down after verification (`ddev stop`) to leave the worktree clean for F.

## Ready for F

**RED confirmed valid.** Both suites fail for the correct underlying reason (the view YAML, its route, and the new seed lines do not exist yet), verified via live execution (not merely static reasoning) against the assembled config + a running DDEV instance for the kernel suite, and against a running (unseeded) Drupal instance for the Playwright suite's anonymous-access case. F may implement against these tests.

**Note to F:** see the "Known seed-data gap" callout above — the `core`/`drupalcon` follow_term e2e assertions require populating `field_group_tags` (not just `field_tags`) on the relevant nodes; this is additional work beyond the brief's literal three `flag()` lines and belongs in the seed-append step.

STATUS: RED_CONFIRMED
