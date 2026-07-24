# Handoff-T-red: Phase 4 - #112 ST-3 /my-feed/events (Events + My RSVPs)

**Date:** 2026-07-23
**Branch:** `112-events-rsvps` (stacked on `110-stream-110`, PR #173)
**Brief / wireframe reviewed:** `docs/planning/handoffs/112-events-rsvps/brief.md`,
`survey.md`, `handoff-D.md` + `wireframe.html`, `handoff-A.md`, issue #112.

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3), with 3 advisory (`warn`, non-blocking)
findings F must address in-plan. Per handoff-A.md's own recommendation ("spawning T(RED) now"),
all three findings are directly reflected in the tests below:

- **Finding #1** (two-section shell contract) → `MyEventsRouteTest::testAuthenticatedUserSeesBothSections`
  asserts on the controller-composed per-section testids
  (`upcoming-events-results`/`-empty`, `my-rsvps-results`/`-empty`), never on the shell's own
  single `results`/`empty` slot.
- **Finding #2** (RSVP chip cache metadata) → `MyEventsViewTest::testChipCacheMetadata` pins the
  exact obligation (flagging cache tag + `user` context).
- **Finding #3** (`?scope=global` filter-override) → `MyEventsRouteTest::testGlobalScopeWidensUpcomingBeyondMemberships`
  asserts the OBSERVABLE outcome only (a non-member event appears), never the
  `overrideOption()` mechanism itself, per A's own guidance not to pin implementation.

## Tests authored

### Kernel — `docs/groups/modules/do_streams/tests/src/Kernel/MyEventsViewTest.php`

Layer: Kernel (`GroupsKernelTestBase`) — asserting on the executed view's own display
configuration/query-result shape and a render array's `#cache` metadata is the "kernel tests
assert query results and render-array shape" layer (survey.md's own testing-approach
convention, echoed in `StreamsShellTest`'s class docblock) — cheaper than a full
`BrowserTestBase` round-trip for these particular assertions.

Installs a **module-local fixture** (`tests/fixtures/config/views.view.my_events.yml`, written
by this handoff — never a source-relative path into `docs/groups/config/`, per the
assembled-vs-source gotcha) via the same `FileStorage` + `getStorage('view')->create()->save()`
pattern `MyFeedRouteTest` established for the sibling `my_feed` view.

| Test | Behavior / criterion pinned |
|---|---|
| `testViewExistsWithBothDisplays` | The shipped `my_events` view has exactly `default` (Upcoming) + `my_rsvps` displays (survey.md Reuse map row 2; handoff-A.md "Cross-checks that PASS §Two-display view design"). |
| `testUpcomingDisplayContract` | Upcoming: bundle filter = `event` only; `field_date_of_event` ASC sort; `do_streams_membership_scope` filter present (REUSE as-is); a future-only date filter (`>=` operator) present (brief AC "Past events excluded"); row = `entity:node` @ `stream_card` view mode. |
| `testMyRsvpsDisplayContract` | My RSVPs: bundle filter = `event` only; `field_date_of_event` ASC sort; a `flag_relationship` to `rsvp_event` scoped to the CURRENT viewing user (`user_scope: current`, never a fixed uid); row = `entity:node` @ `stream_card`. |
| `testUpcomingDisplayExecutesAndReturnsOnlyEventBundleNodes` | Defense-in-depth: the Upcoming display's EXECUTED query result contains only `event`-bundle nodes. |
| `testChipCacheMetadata` | handoff-A.md Finding #2 (binding): the RSVP chip's cache metadata MUST carry BOTH a `user` cache context AND a flagging-scoped cache tag for the given node — asserted via a directly-callable `DoStreamsHooks::buildRsvpChipCacheMetadata()` helper, mirroring `StreamsShellTest`'s own precedent of invoking a hook method directly rather than depending on the full theme-render pipeline. |

### Functional — `docs/groups/modules/do_streams/tests/src/Functional/MyEventsRouteTest.php`

Layer: Functional (`BrowserTestBase`) — a real HTTP request/response is the only way to assert
the route's access-control behavior end-to-end and the two-section DOM composition, mirroring
`MyFeedRouteTest`'s own precedent for the sibling `/my-feed` route.

`setUp()` provisions three site-level (non-module-shipped) config artifacts programmatically,
exactly as `MyFeedRouteTest` does for `views.view.my_feed.yml`: `field_date_of_event` (field
storage + field config), the REAL shipped `flag.flag.rsvp_event.yml` (read via `FileStorage`
from `docs/groups/config/`, not a fixture copy — this is a dependency the view consumes, not a
contract this story's own test pins), and the module-local `views.view.my_events.yml` fixture.

| Test | Behavior / criterion pinned |
|---|---|
| `testAnonymousGetsDeniedOrRedirectedToLogin` | Anonymous `GET /my-feed/events` → 403 or login redirect (mirrors `MyFeedRouteTest`'s AC-1 shape). |
| `testAuthenticatedUserSeesBothSections` | Authenticated `GET /my-feed/events` → 200, rendering EITHER the results or empty variant of BOTH sections (handoff-A.md Finding #1). |
| `testIcalLinksPresentAndResolve` | Both iCal `<a href>`s present (site feed + the CURRENT user's feed, built from the viewing user's own uid) AND both resolve to 200 with a `text/calendar` content-type — REUSE-only, per the brief's non-goal. |
| `testGlobalScopeWidensUpcomingBeyondMemberships` | `?scope=global` widens Upcoming: a non-member group's event is absent under the default scope, present under `?scope=global` (handoff-A.md Finding #3 — observable outcome only). |

### E2E — `tests/e2e/my-events.spec.ts`

Mirrors `showcase.spec.ts`'s / `manage-members.spec.ts`'s conventions (real `/user/login` form,
no session injection). Runs against elena_garcia's real seeded RSVP/membership state
(`step_700_demo_data.php` lines 179-320).

| Test | Behavior / criterion pinned |
|---|---|
| Upcoming lists Barcelona → Keynote → Sprint | Date-ASC DOM order in the Upcoming (My Groups scope) section. |
| My RSVPs lists elena's three RSVPs in date order | Same 3 events, date ASC, in the My RSVPs section. |
| Keynote shows "4 going" + "You're going" | The RSVP chip's `data-going-count`/`data-viewer-state` attributes AND visible text both match the seeded state. |
| Global toggle widens Upcoming | Clicking the Global tab reveals at least one event (Thunder Editorial Workshop / Governance Town Hall) from a group elena is not a member of. |
| Both iCal links render with correct hrefs | `ical-link-site` → `/upcoming-events/ical`; `ical-link-user` → `/user/<uid>/events/ical` for the CURRENT viewing user. |

## RED confirmation

Verified for real against a namespaced throwaway DDEV project (`gm112-events`, per the brief's
own `DDEV project (if needed)` naming), NOT statically inferred — `bash scripts/ci/assemble-config.sh`
was run first, then PHPUnit against the assembled layout, per the mandated verification path.

### Kernel (`MyEventsViewTest`)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Kernel/MyEventsViewTest.php
```

```
Tests: 5, Assertions: 156, Failures: 1.
 ✔ View exists with both displays
 ✔ Upcoming display contract
 ✔ My rsvps display contract
 ⚠ Upcoming display executes and returns only event bundle nodes
 ✘ Chip cache metadata
   │ DoStreamsHooks exposes a buildRsvpChipCacheMetadata() (or equivalently-named) method
   │ the chip render path calls to attach cache metadata (handoff-A.md Finding #2).
   │ Failed asserting that false is true.
```

**RED-validity note:** `testViewExistsWithBothDisplays` / `testUpcomingDisplayContract` /
`testMyRsvpsDisplayContract` pass at RED because they assert against THIS test's own
module-local fixture (`tests/fixtures/config/views.view.my_events.yml`), which encodes the
display CONTRACT F must reproduce in the real, SHIPPED `docs/groups/config/views.view.my_events.yml`
— exactly the same "kernel structural assertions pass against T's own fixture" shape
`MyFeedRouteTest`'s class docblock documents for its sibling `my_feed` fixture. The genuine
kernel-layer RED is `testChipCacheMetadata`, which fails because `DoStreamsHooks::buildRsvpChipCacheMetadata()`
does not exist at all — a "call to undefined method" style failure (surfaced here as
`assertTrue(method_exists(...))` failing first, so the failure message is unambiguous rather
than a raw fatal). This is the RIGHT reason: no chip-cache-metadata behavior exists yet, not a
test-authoring defect.

### Functional (`MyEventsRouteTest`)

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_streams/tests/src/Functional/MyEventsRouteTest.php
```

```
Tests: 4, Assertions: n/a (each fails before reaching an assertion), Failures: 4.
 ✘ Anonymous gets denied or redirected to login
   │ Anonymous GET /my-feed/events must be denied (403) or redirected to the login form;
   │ got status 404 at http://gm112-events.ddev.site/my-feed/events.
 ✘ Authenticated user sees both sections
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
 ✘ Ical links present and resolve
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
 ✘ Global scope widens upcoming beyond memberships
   │ Behat\Mink\Exception\ExpectationException: Current response status code is 404, but 200 expected.
```

**RED-validity note:** every failure is a genuine 404 — `do_streams.routing.yml` has no
`/my-feed/events` entry, `MyEventsController` does not exist, and the shipped
`views.view.my_events.yml` does not exist. This is the intended RED, mirroring
`MyFeedRouteTest`'s own documented RED reasoning for the sibling `/my-feed` route.

**T-red self-correction (fixed before RED was reported valid):** the FIRST run of this suite
fatal-errored at `setUp()` ("Call to a member function getConfigDependencyName() on null"),
BEFORE any HTTP assertion — an invalid RED masking the intended one. Root cause: the
`my_rsvps` display's `flag_relationship` handler
(`FlagViewsRelationship::calculateDependencies()`) resolves the `rsvp_event` flag ENTITY the
moment the view is SAVED (not merely executed), and a fresh `BrowserTestBase` install never
config-imports `flag.flag.rsvp_event.yml` (a site-level artifact, same gap
`views.view.my_feed.yml` has). Fixed by installing the REAL shipped
`docs/groups/config/flag.flag.rsvp_event.yml` (via `FileStorage`, same convention as the view
fixture) before the `my_events` view fixture is saved. Re-ran after the fix; all 4 tests now
fail on the intended 404, confirmed above.

### E2E (`my-events.spec.ts`)

Statically verified (well-formed, parses, lists correctly) — a fully seeded, running site
(assemble → site:install → cim → seed `step_700_demo_data.php` → runserver) was not stood up in
this session, so the suite was not executed live. Per the same RED-by-construction convention
`showcase.spec.ts`'s class docblock documents ("every selector below targets markup/routes the
wireframe specifies but nothing in the codebase renders yet"): `/my-feed/events` does not exist,
`data-testid="upcoming-events-results"` / `"my-rsvps-results"` / `"rsvp-chip"` /
`"ical-link-site"` / `"ical-link-user"` render nothing anywhere in the codebase, and
`data-testid="do-streams-shell-tab"][data-scope-id="global"]` — while it DOES exist on the
sibling `/my-feed` shell — is not reachable from a route that 404s. Every test in this spec is
therefore expected to fail on a 404 navigation or a locator timeout, never a false
"wrong content" mismatch.

```
npx playwright test tests/e2e/my-events.spec.ts --list
```

```
Listing tests:
  [chromium] › my-events.spec.ts:63:7 › ... › Upcoming (My Groups scope) lists Barcelona, Keynote, Sprint in date order
  [chromium] › my-events.spec.ts:92:7 › ... › My RSVPs lists elena's three RSVPs in date order
  [chromium] › my-events.spec.ts:114:7 › ... › Keynote card shows "4 going" and viewer-state "You're going"
  [chromium] › my-events.spec.ts:134:7 › ... › Global toggle widens Upcoming beyond elena's memberships
  [chromium] › my-events.spec.ts:161:7 › ... › both iCal links render with correct hrefs
Total: 5 tests in 1 file
```

T-GREEN must execute this suite live against a fully seeded site before reporting GREEN — the
`--list` above only confirms the spec is well-formed, not that it fails/passes for the right
reason at runtime.

## Coverage gaps noted (non-blocking, for T-GREEN / S to track)

- No dedicated Kernel/Functional test asserts the WCAG 2.2 AA acceptance criterion (labels,
  keyboard, focus visible, contrast, non-color status) beyond the E2E chip's dual
  icon+text/attribute assertion — full AA verification is U's/S's remit per the pipeline
  contract (this repo has no `@axe-core/playwright` dependency, mirroring the gap
  `manage-members.spec.ts`'s own docblock already flags).
- The empty-state variants (0 memberships, 0 RSVPs; handoff-D.md Screen 2) are not
  independently pinned by a Kernel/Functional/E2E test in this suite — `MyEventsRouteTest::testAuthenticatedUserSeesBothSections`
  only asserts "results OR empty" is present for each section, not the distinct empty COPY
  strings handoff-D.md specifies. Flagged as a coverage hole for U's live walkthrough to spot-check.
- The `#60` missing-date defensive fallback (hide the date badge, don't 500) is not tested here
  — per survey.md/handoff-A.md, this is accepted advisory territory ("flag, don't fix") since all
  5 seeded events have dates.

## Ready for F

Confirmed RED is valid for the Kernel and Functional suites (verified live against the
`gm112-events` DDEV throwaway, assembled config). The E2E suite is well-formed and its RED is
inferred by construction (no route/markup exists yet) pending a live run at T-GREEN. F may
implement against these tests now.
