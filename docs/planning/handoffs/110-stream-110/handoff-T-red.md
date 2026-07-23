# Handoff-T-red: Phase 4 - #110 ST-1 My Feed at `/my-feed`

**Date:** 2026-07-23
**Branch:** 110-stream-110
**Brief / wireframe reviewed:** `docs/planning/handoffs/110-stream-110/brief.md`,
`docs/planning/handoffs/110-stream-110/wireframe.html`, `docs/planning/handoffs/110-stream-110/handoff-D.md`,
`docs/planning/handoffs/110-stream-110/handoff-A.md`, `docs/planning/handoffs/110-stream-110/survey.md`

## A precondition

Confirmed: A (`handoff-A.md`) returned **PASS** (with 8 soft advisories, no BLOCK findings) on
the plan (brief + approved wireframe). Every T-facing advisory named in that handoff is honored
below (anon nav-link DOM-absence assertion, AC-1 403-or-302 tolerance, empty-state CTA assertion,
pager coverage note — see "Deferred / partial coverage").

## Tests authored

All four files below are NEW; no existing test file was rewritten.

### 1. `docs/groups/modules/do_streams/tests/src/Functional/MyFeedRouteTest.php`

BrowserTestBase (Functional) — a real HTTP request/response is the only way to prove route-level
access control end to end, mirroring `ManageMembersRouteAccessTest`'s pattern per the story's own
spec instruction. `setUp()` builds a `community_group`-shaped group type + `group_node:page`
relationship and grants outsider+insider view-permission group roles (mirroring
`StreamsScopeTest`'s setUp, per A's Finding #6 advisory), so Group's own access layer never masks
the membership-scope filter's own behavior.

| Test | AC / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testAnonymousGetsDeniedOrRedirectedToLogin` | AC-1: anon `GET /my-feed` -> 403 or login redirect | Functional | Route access control is only provable end-to-end over real HTTP |
| `testAuthenticatedUserSeesShellWithMyFeedAndRecentActive` | AC-2, AC-3, AC-4: 200 + shell chrome + `my_feed` tab `is-active`/`aria-current` + `recent` pill `is-active` | Functional | Asserts rendered HTML from a real controller/theme-hook response |
| `testMembershipScopeResultsExcludeNonMemberGroupContent` | AC-5: member-group node appears, non-member-group node does not | Functional | Full-stack proof (view + membership filter + controller + render), reusing the already-tested `MembershipScope` filter as-is |
| `testZeroGroupUserSeesEmptyStateWithCta` | AC-6: empty state + new `empty_cta` slot linking to `/all-groups` | Functional | Rendered-HTML assertion of a new theme-hook variable |
| `testResponseVariesByViewingUser` | Cache correctness (handoff-A Finding #4): response must not leak one user's scoped content to another | Functional | Only a real HTTP round-trip through the page cache/render pipeline proves per-user cache-context bubbling |

### 2. `docs/groups/modules/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php`

Unit (mirrors the existing `HelpTextTest.php` per-surface pattern) — `HelpText::get()`/`all()` are
pure functions; no Drupal bootstrap needed, cheapest sufficient tier. A NEW file (not an edit to
the shared `HelpTextTest.php`) both per this story's non-negotiables and because
`WAVE-EXECUTION-HANDOFF.md` §3 flags `HelpText.php` as shared-file contention across many
concurrent stories — a dedicated file avoids suite-file merge collisions.

| Test | AC / behavior pinned | Tier |
|---|---|---|
| `testStreamMyFeedCopyIsPresentAndPlainText` | AC-10: `stream.my_feed` resolves to non-empty plain text | Unit |
| `testStreamMyFeedKeyPresentAndFoundationKeyUnchanged` | AC-10 append-only guard: key literally present + a pre-existing key's copy is byte-identical (proxy for "no existing entries mutated") | Unit |

### 3. `docs/groups/modules/do_streams/tests/src/Functional/MyFeedNavLinkTest.php`

Functional (BrowserTestBase self-installs a full site, giving a live environment to `require` the
bare procedural seed script against, exactly as the runbook/CI run it) — new file, no existing nav
test edited.

| Test | AC / behavior pinned | Tier |
|---|---|---|
| `testMyFeedNavLinkIsSeeded` | AC-8: a `menu_link_content` keyed `st1-nav-my-feed`, `uri: internal:/my-feed`, titled "My Feed" exists after seeding | Functional |
| `testNavLinkWeightsAreIntegersAndOrderedCorrectly` | AC-8 + handoff-A Finding #8: every weight is a plain PHP integer (not the wireframe's literal `1.5`, which core would silently coerce) and imposes Groups < Activity < My Feed < My Groups < Create Group | Functional |
| `testReSeedingDoesNotDuplicateMyFeedLink` | Idempotency: re-running `step_780_nav_menu.php` does not create a duplicate `st1-nav-my-feed` entry | Functional |

### 4. `tests/e2e/my-feed.spec.ts`

Playwright E2E against the full seeded site (assemble -> site:install -> config:import -> enable
modules -> seed -> serve), per the story's own instruction and `WAVE-EXECUTION-HANDOFF.md` §6.6 —
an isolated fixture is not representative of the real seeded CI job. Mirrors
`manage-members.spec.ts` / `nav.spec.ts`'s self-contained login-helper conventions.

| Test | AC / behavior pinned | Tier |
|---|---|---|
| `anonymous GET /my-feed is denied or redirected to login (AC-1)` | AC-1, DOM-level: no shell chrome ever renders for a denied/redirected anon request | E2E |
| `anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8, handoff-A Finding #1)` | AC-8 negative case: nav-link ABSENT from the DOM (not merely hidden) for anon | E2E |
| `authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8)` | AC-8 positive case: accessible-name "My Feed" link with `href` ending `/my-feed` | E2E |
| `elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4)` | Same as MyFeedRouteTest but proving real client-rendered DOM (class/attr assertions via Playwright locators) | E2E |
| `elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9)` | AC-9: real seeded content, pinned-leading assertion + negative assertion against Thunder Distribution / Drupal Deutschland | E2E |
| `a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6)` | AC-6 against a freshly self-provisioned 0-group user (not a specific seeded account, so it stays valid across future seed changes) | E2E |

Pager (AC-7) is **not** covered here — see "Deferred / partial coverage" below.

## RED confirmation

All commands run from the assembled layout, against a real DDEV instance
(`gm110-groups-stream-110`, container-namespaced per this story's isolation rule) with
`vendor/`, `web/modules/custom/`, and `config/sync/` populated by `assemble-config.sh`.

### Assemble

```
$ bash scripts/ci/assemble-config.sh   # (composer install first: ddev composer install)
==> assemble-config: repo root = /var/www/html
==> config: copied 98 file(s), excluded 7 env-specific file(s)
==> modules: copied 13 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

### Kernel/Functional (PHPUnit, assembled layout)

```
$ ddev exec "SIMPLETEST_DB='mysql://db:db@db/db' \
    SIMPLETEST_BASE_URL='http://gm110-groups-stream-110.ddev.site' \
    BROWSERTEST_OUTPUT_DIRECTORY=/tmp/browsertest-output \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_streams/tests/src/Functional/MyFeedRouteTest.php"
```

```
My Feed Route (Drupal\Tests\do_streams\Functional\MyFeedRoute)
 ✘ Anonymous gets denied or redirected to login
   │ Anonymous GET /my-feed must be denied (403) or redirected to the login form; got status 404
   │   at http://gm110-groups-stream-110.ddev.site/my-feed.
   │ Failed asserting that false is true.
 ✘ Authenticated user sees shell with my feed and recent active
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
 ✘ Membership scope results exclude non member group content
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
 ✘ Zero group user sees empty state with cta
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
 ✘ Response varies by viewing user
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.

Tests: 5, Assertions: 24, Failures: 5.
```

Every failure is a **404** (Drupal has no route matching `/my-feed` — `do_streams.routing.yml`,
`MyFeedController`, and `views.view.my_feed.yml` do not exist yet, exactly as the brief names
them). This is RED for the right reason: the assertion under test ("the route responds correctly")
cannot be reached because the route itself is absent — not a typo, import error, or setup bug.

```
$ ddev exec "... phpunit ... web/modules/custom/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php"
```

```
My Feed Help Text (Drupal\Tests\do_chrome\Unit\MyFeedHelpText)
 ✘ Stream my feed copy is present and plain text
   │ The stream.my_feed tooltip copy must exist (AC-10).
   │ Failed asserting that two strings are not identical.
 ✘ Stream my feed key present and foundation key unchanged
   │ stream.my_feed must be a literal key in HelpText::all().
   │ Failed asserting that an array has the key 'stream.my_feed'.

Tests: 2, Assertions: 2, Failures: 2.
```

`HelpText::get('stream.my_feed')` correctly falls through to the unknown-key default `''` — the
key has not been appended to `HelpText::all()` yet. Valid RED.

```
$ ddev exec "... phpunit ... web/modules/custom/do_streams/tests/src/Functional/MyFeedNavLinkTest.php"
```

```
My Feed Nav Link (Drupal\Tests\do_streams\Functional\MyFeedNavLink)
 ✘ My feed nav link is seeded
   │ A menu_link_content entity keyed st1-nav-my-feed exists after seeding.
   │ Failed asserting that an array has the key 'st1-nav-my-feed'.
 ✘ Nav link weights are integers and ordered correctly
   │ A menu_link_content entity keyed st1-nav-my-feed exists after seeding.
   │ Failed asserting that an array has the key 'st1-nav-my-feed'.
 ✘ Re seeding does not duplicate my feed link
   │ Re-running the seed script does not create a duplicate st1-nav-my-feed link.
   │ Failed asserting that actual size 0 matches expected size 1.

Tests: 3, Assertions: 11, Failures: 3.
```

The seed script (correctly `require`d and executed twice, confirmed idempotent for the 4 EXISTING
links via its own "Exists: ..." echo output) genuinely seeds only `ch83-nav-groups` /
`ch83-nav-activity` / `ch83-nav-my-groups` / `ch83-nav-create-group` today — no `st1-nav-my-feed`
key exists in `step_780_nav_menu.php` yet. Valid RED.

**Two self-corrections made before reporting RED valid** (T's own authoring bugs, not F's, fixed
before this handoff — no implementation code was written to fix them):
1. `MyFeedNavLinkTest`'s original `setUp()` called `$this->installEntitySchema(...)`, a
   `KernelTestBase`-only method undefined on `BrowserTestBase` (which self-installs a full site,
   entity schemas included) — this failed with "Call to undefined method" *before* reaching any
   real assertion, an invalid RED masking the intended one. Removed.
2. The same file's repo-root path resolution used `dirname(__DIR__, 6)`, landing one level short
   (`.../web` instead of the repo root) in the assembled layout. Corrected to `dirname(__DIR__, 7)`,
   verified against both the assembled path
   (`web/modules/custom/do_streams/tests/src/Functional/`) and the source path
   (`docs/groups/modules/do_streams/tests/src/Functional/`) — both are exactly 7 levels below the
   repo root.

### E2E (Playwright, against the full seeded site)

Seeded per `WAVE-EXECUTION-HANDOFF.md` §6.6 / the CI E2E job's own recipe, replicated manually
inside the same `gm110-groups-stream-110` DDEV instance: `drush site:install standard` ->
`config:import` (98 assembled config files, incl. `views.view.activity_stream`,
`group.role.community_group-*`) -> `drush en do_tests do_group_extras ... do_streams do_chrome`
-> `step_700_demo_data.php` + `step_720_group_types.php` + `step_780_nav_menu.php` +
`step_790_persona_switcher.php` (as uid 1) -> `cache:rebuild`.

```
$ BASE_URL="http://gm110-groups-stream-110.ddev.site" npx playwright test tests/e2e/my-feed.spec.ts --reporter=list
```

```
Running 6 tests using 1 worker

  x  1 anonymous GET /my-feed is denied or redirected to login (AC-1) (1.3s)
  ok 2 anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8, handoff-A Finding #1) (496ms)
  x  3 authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8) (12.5s)
  x  4 elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4) (2.0s)
  x  5 elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9) (11.9s)
  x  6 a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6) (8.4s)

  1) anonymous GET /my-feed is denied or redirected to login (AC-1)
     Error: Anonymous /my-feed must be 403 or redirect to /user/login; got status 404 ...

  3) authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8)
     Error: expect(locator).toBeVisible() failed
     Locator: locator('#block-groups-chrome-main-menu').getByRole('link', { name: 'My Feed', exact: true })
     Error: element(s) not found

  4) elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4)
     Error: expect(received).toBe(expected)  Expected: 200  Received: 404

  5) elena_garcia's feed leads with pinned "Sprint Planning: Portland 2026" ...
     Error: expect(locator).toBeVisible() failed
     Locator: locator('[data-testid="do-streams-shell-results"]')
     Error: element(s) not found

  6) a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6)
     Error: expect(received).toBe(expected)  Expected: 200  Received: 404

  5 failed, 1 passed (42.7s)
```

All 5 tests that target the not-yet-built feature fail for the right reason: `/my-feed` returns
404 (no route), the nav link does not exist (no seed entry), and the shell selectors are never
found (no controller/theme render). Test 2 (anon nav-link absence) correctly **passes already** —
it asserts a negative that needs no new code (the link genuinely does not exist yet), which is the
expected/valid state for that specific assertion; it does not indicate an invalid RED.

**One self-correction made before reporting RED valid**: the AC-6 (zero-group user) test originally
logged the admin OUT via a bare `page.goto('/user/logout')` in the same page/session, then tried
to log back in as the fresh user. Drupal 10.3+'s logout route is CSRF-protected (a plain GET does
not end the session the way the UI's tokenized "Log out" link does), so the admin session
persisted and the subsequent `login()` call timed out waiting for a `/user/login` form it never
reached (still on `/user/1`) — an invalid RED (failing for a session-management bug in the test,
not the intended "route doesn't exist yet" reason). Fixed by logging the zero-group user in via a
**separate browser context** (its own unauthenticated cookie jar), sidestepping the logout route's
CSRF mechanics entirely and mirroring a realistic second, independent visitor. Also corrected
`ELENA_PASS`'s default from the username itself to the real seeded password
(`demo_password_2026`, confirmed by reading `step_700_demo_data.php` directly — every demo user
shares this one password).

## Deferred / partial coverage

- **AC-7 (pager renders when results > 10)** is **not** directly asserted by any test in this
  suite. Seeding an in-scope user with >10 published nodes across their membership groups was
  judged disproportionate for RED (it would require either a bespoke 11-node fixture in the
  Functional test or relying on the demo seed's exact per-group node counts, which are not
  currently guaranteed to exceed 10 for any single seeded user). Flagging this as a coverage gap
  for O/A to weigh: either (a) accept it as a visual/manual check for U (the wireframe's own State
  1 already depicts the pager schematically, annotated "shown here schematically only — not
  shell-owned, standard core theme"), or (b) I add a dedicated Functional test with an 11-node
  fixture before T-green if this is judged in-scope-required. Recommend (a): the pager itself is
  Drupal core's own `full` pager style, already used verbatim by `activity_stream.yml`, so its
  correctness is a core-Views concern, not new logic this story introduces.
- **AC-12 (WCAG 2.2 AA / axe-core)** is not covered by any test in this suite — per this project's
  established convention (see `manage-members.spec.ts`'s own docblock), the repo carries no
  `@axe-core/playwright` dependency, so full automated axe scanning is out of T's remit to add
  (a tooling dependency decision for O/F). U's UI Walkthrough and S's spec audit are the intended
  backstops for AC-12, consistent with how prior UI stories in this repo have handled it.
- **Cache-tag widening** (handoff-A Finding #4a: whether F widens `viewsPostRender`'s
  `DEMO_VIEW_ID` allowlist to include `my_feed` or merges the per-user stream tag directly in the
  controller) is an **F implementation choice**, not independently tested here — `testResponseVariesByViewingUser`
  proves the OBSERVABLE outcome (no cross-user leakage) regardless of which mechanism F picks,
  which is the correct level to pin per "assert behavior, not implementation."

## Ready for F

**Confirmed RED is valid.** Every authored test fails for the right reason — a missing route,
missing view, missing controller, missing theme render, missing HelpText key, or missing nav-link
seed entry — never an import/setup/typo error. F may implement against these tests now.

Test files (all NEW, none rewritten):
- `docs/groups/modules/do_streams/tests/src/Functional/MyFeedRouteTest.php`
- `docs/groups/modules/do_streams/tests/src/Functional/MyFeedNavLinkTest.php`
- `docs/groups/modules/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php`
- `tests/e2e/my-feed.spec.ts`

## Reproduce

```bash
# From the repo root, inside a project-scoped DDEV instance (or any environment
# with composer install + a MySQL/MariaDB DB reachable as SIMPLETEST_DB):
bash scripts/ci/assemble-config.sh

php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedRouteTest.php \
  web/modules/custom/do_streams/tests/src/Functional/MyFeedNavLinkTest.php \
  web/modules/custom/do_chrome/tests/src/Unit/MyFeedHelpTextTest.php

# E2E — requires a fully installed + config-imported + seeded + served site
# (see the "E2E" section above, or .github/workflows/test.yml's e2e job):
BASE_URL="<served-site-base-url>" npx playwright test tests/e2e/my-feed.spec.ts
```
