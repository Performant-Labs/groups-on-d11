# Handoff-T-green: Phase 6 - #112 ST-3 /my-feed/events (Events + My RSVPs)

**Date:** 2026-07-23
**Branch:** `112-events-rsvps`
**Issue:** #112
**Handoff-F reviewed:** No standalone `handoff-F.md` was written; F's implementation notes live in
the commit message of `836a884` and in `decisions.md`'s "F — Phase 5" entry (both read in full).
**Handoff-T-red:** `docs/planning/handoffs/112-events-rsvps/handoff-T-red.md`

## Verdict: BLOCKED — one production-code bug found, must return to F

All three authored suites now pass their *own* assertions (Kernel 5/5, Functional 4/4, E2E 4/5).
The one E2E failure surfaced a **real production bug**: the shared `do-streams-shell` template
unconditionally renders its own generic 4-tab scope nav (`Global / My Feed / Following /
Trending`, `<span>`s) AND a dead `Ranking: Recent/Hot` control on `/my-feed/events`, stacked
directly above `MyEventsController`'s own correct, wireframe-specified 2-tab `Global / My Groups`
toggle (real `<a>` links). Both tab sets share the identical `data-testid="do-streams-shell-tab"`
+ `data-scope-id="global"` pair, so any test (or assistive-tech user, or automated tooling) that
queries by that testid hits a strict-mode collision on this page. This is not a test-authoring
problem — I fixed a genuine test bug in the same test (see below) and the failure persisted for a
different, code-side reason.

## GREEN confirmation

### Kernel — `MyEventsViewTest` (5/5 pass)

```
ddev exec "SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8080' \
  SYMFONY_DEPRECATIONS_HELPER=disabled php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  --testdox web/modules/custom/do_streams/tests/src/Kernel/MyEventsViewTest.php"
```
```
✔ View exists with both displays
✔ Upcoming display contract
✔ My rsvps display contract
⚠ Upcoming display executes and returns only event bundle nodes   (deprecation warning only)
✔ Chip cache metadata
Tests: 5, Assertions: 160, Deprecations: 14. OK, but there were issues!
```

### Functional — `MyEventsRouteTest` (4/4 pass)

Required a real webserver for BrowserTestBase's test router — DDEV's `ddev exec` doesn't serve one
by default, so I started `php -S 127.0.0.1:8080 -t web web/.ht.router.php` inside the container
(same router CI's `functional` job uses) before running:
```
SIMPLETEST_DB='mysql://db:db@db:3306/db' SIMPLETEST_BASE_URL='http://127.0.0.1:8080' \
  BROWSERTEST_OUTPUT_DIRECTORY='/tmp/browsertest-output' SYMFONY_DEPRECATIONS_HELPER=disabled \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_streams/tests/src/Functional/MyEventsRouteTest.php
```
```
Tests: 4, Assertions: 39, Deprecations: 29. OK, but there were issues!
```
All 4 (`testAnonymousGetsDeniedOrRedirectedToLogin`, `testAuthenticatedUserSeesBothSections`,
`testIcalLinksPresentAndResolve`, `testGlobalScopeWidensUpcomingBeyondMemberships`) pass.

### E2E — `tests/e2e/my-events.spec.ts` (4/5 pass; 1 fails on a real production bug)

Fully seeded, live run against `http://gm112-events.ddev.site` (assemble → site:install
--existing-config → `step_700_demo_data.php`), per the brief's own delivery instruction.

```
ok  Upcoming (My Groups scope) lists Barcelona, Keynote, Sprint in date order
ok  My RSVPs lists elena's three RSVPs in date order
ok  Keynote card shows "4 going" and viewer-state "You're going"
x   Global toggle widens Upcoming beyond elena's memberships   <-- production bug, see below
ok  both iCal links render with correct hrefs
```

**T-authored-test bug found and fixed first (not the blocker):** the test originally asserted
neither "Thunder Editorial Workshop" nor "Governance Town Hall" appears under the default
(My-Groups) scope. `step_700_demo_data.php`'s Step 730a membership seed
(`"Leadership Council" => ["james_okafor", "maria_chen", "elena_garcia"]`) makes elena a
Leadership Council **member**, so Governance Town Hall (owned by Leadership Council) legitimately
appears pre-toggle — my own test's precondition assumption was wrong for that event. Fixed by
scoping the assertion to Thunder Editorial Workshop only (Thunder Distribution's members are
`ravi_patel`/`sophie_mueller` — elena genuinely is not one). Re-ran: this fix resolved the
"before" half of the assertion (correctly `false` now) but the test still fails at the
`globalTab` locator itself — a different, code-side cause (below). Documented in the spec's own
docblock (T-GREEN self-correction note) so this isn't re-litigated at S.

**Production bug (blocking):** `[data-testid="do-streams-shell-tab"][data-scope-id="global"]`
resolves to **2 elements** on `/my-feed/events`:
```
1) <span data-scope-id="global" class="shell-tabs__item" data-url-or-param="?scope=global"
     data-testid="do-streams-shell-tab">Global</span>
   — from the shared do-streams-shell.html.twig's OWN unconditional `scope_tabs` loop
     (the generic 4-tab Global/My Feed/Following/Trending set, #109/#110's shell), rendered
     as a page-snapshot nav labelled "Stream scope": "Global My Feed Following Trending".
2) <a data-scope-id="global" class="shell-tabs__item" data-url-or-param="?scope=global"
     data-testid="do-streams-shell-tab" href="/my-feed/events?scope=global">Global</a>
   — MyEventsController::buildScopeToggle()'s OWN, correct 2-tab Global/My Groups toggle
     (labelled "Events scope" in the page snapshot).
```
Both render on the same page, stacked, with identical testids. handoff-A.md Finding #1 flagged
this exact ambiguity in advance ("the shell has a single results/empty slot... F must decide now
which of the two legitimate options applies"). F correctly built the controller's own two-tab
markup (option a, per A's recommendation) and correctly reasoned in `DoStreamsHooks.php`'s
"Issue #112 note" docblock that `empty`/`empty_copy` are harmless dead weight on this route
(never read by the page's own template fragment) — but **missed that `scope_tabs` and
`ranking_control` are NOT dead weight**: `do-streams-shell.html.twig` renders `{% for tab in
scope_tabs %}` and `{% for pill in ranking_control %}` **unconditionally**, with no guard, so
they render as VISIBLE markup on every page using `#theme => do_streams_shell` regardless of
whether the controller "intends" to use them. The result on `/my-feed/events`:
- A confusing, non-functional 4-tab "Stream scope" nav (Global/My Feed/Following/Trending) that
  does nothing useful on this page (My Feed/Following/Trending have no meaning here) stacked
  above the page's real, correct 2-tab toggle.
- A dead "Ranking: Recent/Hot" control with no wiring on this route.
- Duplicate `data-testid="do-streams-shell-tab"` + duplicate `data-scope-id="global"` values,
  breaking any automated tooling (Playwright, axe, screen-reader landmark navigation) that
  queries by testid — this is also a genuine WCAG 2.2 AA concern (confusing duplicate
  navigation landmarks / redundant, non-functional controls), not just a test-tooling
  inconvenience.

I verified this live (not just in the Playwright trace) via the page snapshot: the rendered page
literally shows `navigation "Stream scope": Global My Feed Following Trending` immediately above
`generic "Events scope": Global My Groups`.

**Spot-check that tests still fail if behavior is removed:** confirmed structurally — the
Kernel `testChipCacheMetadata` fails immediately if `buildRsvpChipCacheMetadata()` is removed
(method-exists assertion), and the E2E ordering tests are keyed to literal seeded titles/dates,
so a broken sort silently reordering the DOM would fail the `toBeLessThan` assertions. The
blocked "Global toggle" test is itself proof the suite pins real behavior, not something vacuous
— it caught a genuine defect.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble config | `ddev exec "bash scripts/ci/assemble-config.sh"` | exit 0 | exit 0, 129 config files + 14 modules copied, core.extension patched | PASS |
| Kernel suite (this story) | see above | 5/5 pass | 5/5 pass | PASS |
| Functional suite (this story) | see above | 4/4 pass | 4/4 pass | PASS |
| E2E suite (this story) | `npx playwright test tests/e2e/my-events.spec.ts` | 5/5 pass | 4/5 pass | **FAIL (prod bug)** |
| do_streams+do_discovery+do_chrome+do_group_pin Kernel regression | `phpunit ... web/modules/custom/{do_streams,do_discovery,do_chrome,do_group_pin}/tests/src/Kernel` | all pass | 45/45 pass (23 deprecations, 0 failures) | PASS |
| do_streams+do_chrome Functional regression | `phpunit ... web/modules/custom/{do_streams,do_chrome}/tests/src/Functional` | all pass | 5/5 pass | PASS |
| do_showcase+do_group_membership+do_multigroup+do_group_extras+do_tests Functional regression | `phpunit ...` | all pass | 69/69 pass (1 flaky error on a combined multi-suite run, reproduced 0/0 in isolation — see Advisory) | PASS |
| E2E `nav.spec.ts` + `showcase.spec.ts` regression | `npx playwright test tests/e2e/nav.spec.ts tests/e2e/showcase.spec.ts` | all pass | 26/26 pass (after fixing MY OWN environment's admin password — see Advisory) | PASS |
| API smoke: `/upcoming-events/ical`, `/user/<uid>/events/ical` | via `MyEventsRouteTest::testIcalLinksPresentAndResolve` + live E2E | 200, `text/calendar` | 200, `text/calendar`, confirmed both live and in PHPUnit | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage vs. acceptance criteria | Walked brief.md's 10 acceptance bullets against the 3 suites (see below) | 9/10 covered by an automated test; 1 (AA) is E2E/S/U's remit, consistent with T-red's own noted gap | PASS (with noted gap, non-blocking) |
| Test quality (proportionate, names a behavior, fails for the right reason, cheapest tier) | Reviewed all 3 files' docblocks + assertions; found and fixed 1 test-authoring bug in my own E2E spec (see GREEN confirmation) | Kernel/Functional/E2E each pin distinct, non-duplicated behavior at the right tier; no bloat found | PASS |
| Type safety | N/A — PHP (no static types beyond `declare(strict_types=1)`, present in all 3 test files) and TS (`tests/e2e/my-events.spec.ts` — no `any`, typed `Page` param) | No issues | PASS |
| Error handling | `testAnonymousGetsDeniedOrRedirectedToLogin` (403/login-redirect) | PASS | PASS |
| Data integrity | `testChipCacheMetadata` (cache tag/context correctness); `testUpcomingDisplayExecutesAndReturnsOnlyEventBundleNodes` (bundle-filter defense-in-depth); confirmed live via `drush php:script` that the REAL Keynote node's chip cache metadata is `contexts: [user]`, `tags: [flagging_list:node:13]` | PASS | PASS |
| API contract | iCal hrefs match spec (`/upcoming-events/ical`, `/user/<uid>/events/ical`, built from the CURRENT viewing user's uid, never hardcoded) | PASS | PASS |
| Security | Route access `_user_is_logged_in: 'TRUE'`; `?scope=global` is a request-time filter-override, never a stored/persisted setting; no user input reaches the view's filter values unsanitized (scope is a fixed enum check, not interpolated) | PASS | PASS |
| Migration/config safety | `views.view.my_events.yml` ships with correct `dependencies.config`/`dependencies.module`; no schema migration involved (new view, additive) | PASS | PASS |
| phpcs (`Drupal,DrupalPractice`) | `php vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/do_streams docs/groups/config/views.view.my_events.yml` | `MyEventsController.php` + `events.css`: 0 violations. `DoStreamsHooks.php`: 1 error / 8 warnings — diffed against the pre-#112 baseline (`01f49a5`): baseline was 1 error / 4 warnings, so exactly 4 new warnings, all `\Drupal calls should be avoided` (pre-existing DI-constraint pattern per `do_notifications`'s established precedent, not new debt category). Pre-existing test files (`StreamsScopeTest`, `StreamsRankingTest`, `FollowingFeedTest`, `StreamsShellTest`, `StreamsInstallTest`) carry pre-existing violations unrelated to #112. `MyEventsViewTest.php`/`MyEventsRouteTest.php` (new test files) carry minor doc-comment/line-length style violations (not functional) — advisory, not blocking. | PASS (advisory notes only) |
| Axe/WCAG 2.2 AA scan | Attempted per task instructions; `@axe-core/playwright` is **not installed** in this repo (`npm ls @axe-core/playwright` → empty), confirming T-red's own already-documented coverage gap ("this repo has no @axe-core/playwright dependency"). Could not run an automated axe scan. | **NOT RUN — environment gap, not a suite failure** (see Advisory) |
| RSVP chip cache metadata at real render time | `drush php:script` invoking `DoStreamsHooks::buildRsvpChipCacheMetadata()` against the REAL seeded Keynote node (nid=13) | `contexts: [user]`, `tags: [flagging_list:node:13]` — confirmed live, not just kernel-mocked | PASS |

## Acceptance criteria status (brief.md)

| # | Criterion | Status | Backing test |
|---|---|---|---|
| 1 | Upcoming lists Barcelona → Keynote → Sprint, date ASC | PASS | E2E "Upcoming (My Groups scope) lists..." |
| 2 | My RSVPs lists elena's three RSVPs | PASS | E2E "My RSVPs lists elena's three RSVPs..." |
| 3 | Keynote chip shows "4 going" + viewer-state | PASS | E2E "Keynote card shows..."; Kernel `testChipCacheMetadata` (cache correctness) |
| 4 | Past events excluded | PASS | Kernel `testUpcomingDisplayContract` (future-date `>=` filter present); confirmed no past events rendered live |
| 5 | Global toggle widens Upcoming beyond memberships | **FAIL** | E2E "Global toggle widens..." — blocked by the duplicate-testid production bug above; Functional `testGlobalScopeWidensUpcomingBeyondMemberships` PASSES (it asserts page CONTENT, not the toggle CONTROL, so it doesn't hit the collision) |
| 6 | iCal links present on both displays, resolve 200 | PASS | Functional `testIcalLinksPresentAndResolve`; E2E "both iCal links..." |
| 7 | Playwright rendered-DOM spec green on seeded site | **FAIL** | 4/5 — see #5 |
| 8 | Existing e2e + kernel + functional suites stay green | PASS | do_streams/do_discovery/do_chrome/do_group_pin Kernel (45/45); do_streams/do_chrome/do_showcase/do_group_membership/do_multigroup/do_group_extras/do_tests Functional (74/74 total across runs); nav.spec.ts + showcase.spec.ts (26/26, after an environment-only admin-password fix on my side, see Advisory) |
| 9 | HelpText entry appended for /my-feed/events | Not independently re-verified by a dedicated test in this suite (no test asserts on `HelpText.php`'s content) — F's decisions.md claims this was done as a pure `stream.my_feed` copy. **Advisory: spot-check this at S**, not blocking GREEN. | — |
| 10 | WCAG 2.2 AA | **Partially assessed.** The RSVP chip's dual icon+text (never color-only) is confirmed live in the page snapshot (`○ RSVP · 0 going` / `✓ You're going · N going`). The duplicate-nav bug above (#5) is ALSO an AA concern (confusing redundant navigation landmarks). Full AA verification (keyboard, focus-visible, contrast) is U's/S's remit per pipeline contract; no automated axe tooling available in this repo. | See Advisory |

## Blocking issues

1. **Duplicate/dead scope-tabs + ranking-control markup on `/my-feed/events`** (production bug,
   not a test bug). `do-streams-shell.html.twig` unconditionally renders its own generic 4-tab
   `scope_tabs` nav and `ranking_control` pills regardless of what the calling controller intends;
   `MyEventsController` correctly built its own 2-tab toggle in `#results` but this does not
   suppress the shell's own unconditional loops. F's own `DoStreamsHooks.php` docblock ("Issue
   #112 note") is factually correct about `empty`/`empty_copy` being harmless dead weight but
   **incorrect** in implication for `scope_tabs`/`ranking_control` — those ARE rendered, visibly,
   causing:
   - A confusing, non-functional 4-tab nav stacked above the real 2-tab toggle.
   - A dead "Recent/Hot" ranking control with no purpose on this page.
   - Duplicate `data-testid="do-streams-shell-tab"` + `data-scope-id="global"` pairs — breaks
     `tests/e2e/my-events.spec.ts`'s "Global toggle" test and is itself a WCAG 2.2 AA concern
     (redundant/non-functional navigation landmarks).

   **Suggested fix (F's call on exact shape):** either (a) extend `do_streams_shell`'s Twig
   template with a guard — e.g. only render `scope_tabs`/`shell-ranking` when the caller supplies
   a non-empty override, or a new boolean variable (`hide_default_chrome`) the controller sets —
   or (b) have `MyEventsController` NOT invoke `#theme => do_streams_shell` at all and instead
   render its own, page-owned wrapper (skipping the shared shell's tab/ranking chrome entirely,
   reusing only the `.shell`/CSS tokens). Option (a) is a small, backward-compatible template
   change (guard an existing unconditional loop); option (b) is F's own alternative "invoke the
   theme twice" idea from handoff-A.md Finding #1, inverted. Either way, this is a one-line-of-
   reasoning decision A should re-check at Phase 7 (a template contract change is exactly what
   Finding #1 anticipated).

   This blocks E2E acceptance criterion #5/#7 and partially affects #10 (AA). All other
   acceptance criteria and regression tiers are clean.

## Advisory notes (non-blocking)

- **Axe/WCAG tooling gap:** `@axe-core/playwright` is not installed in this repo. T-red already
  flagged this gap; it persists at T-GREEN. Recommend O decide whether to add the dependency in
  this story or defer to a tooling-infra story — either way, U's live walkthrough should
  hand-verify keyboard/focus-visible/contrast for this page in the meantime.
- **HelpText.php spot-check:** no test in this suite asserts on the new `stream.my_events` HelpText
  key's content; F's decisions.md claims a pure `stream.my_feed`-precedent copy. Recommend S
  spot-check this string during spec audit.
- **Environment-only friction encountered (not code issues), recorded for the next T/F/O who
  reuses this worktree:**
  - `.ddev/config.yaml`'s `name:` was stale (`gm124-directory`, leaked from a prior worktree copy)
    — fixed to `gm112-events` per the brief's own naming.
  - `web/sites/default/settings.php` (gitignored, so this doesn't show in the diff) needed
    `$settings['config_sync_directory'] = '../config/sync';` added BEFORE the DDEV
    auto-generated include block, per RUNBOOK.md Step 105 — without it, DDEV's own
    `settings.ddev.php` silently falls back to `sites/default/files/sync` (empty), and
    `drush cim`/`site:install --existing-config` both fail/threaten to delete all config.
  - A fresh `site:install --existing-config` requires `--profile=standard` (the config's own
    stored profile), not `minimal`.
  - BrowserTestBase's HTTP round-trip needs a real webserver at `SIMPLETEST_BASE_URL`; inside
    DDEV this must be started manually (`php -S 127.0.0.1:8080 -t web web/.ht.router.php`,
    backgrounded) — it is NOT part of `ddev start`.
  - The fresh `site:install` reset the DB, including `admin`'s auto-generated password — the
    pre-existing `nav.spec.ts`/`showcase.spec.ts` E2E specs hardcode `admin`/`admin` as a
    fallback; had to `drush user:password admin admin` before those regressions would pass. This
    was purely an artifact of MY reinstall, not a code regression — flagging so a future agent
    doesn't mistake it for one.
- **One flaky PHPUnit error** seen on a single combined multi-suite Functional run
  (`ManageMembersRouteResolutionTest`, unrelated `do_group_membership` module) that did NOT
  reproduce when that file was run in isolation (3/3 pass) or in the full 69-test combined re-run
  (69/69 pass). Consistent with known BrowserTestBase cross-test DB-state flakiness on large
  combined runs, not a #112 regression — noted for awareness, not blocking.
- **T fixed one bug in my own fixture, matching F's own flagged note:** F's decisions.md
  documents removing a structurally-broken `flagging_uid` filter (`plugin_id: user_current`,
  silently ignored by Views' handler-manager, would 500) from the SHIPPED
  `views.view.my_events.yml`, and explicitly flagged that my kernel fixture
  (`tests/fixtures/config/views.view.my_events.yml`) had the identical broken block. I removed it
  from the fixture to match (the `my_rsvps` display's `flag_relationship`'s own
  `user_scope: current` join already scopes results correctly; the filter was redundant even
  before being broken). Kernel test assertions were unaffected (neither test executes the
  `my_rsvps` display's query directly), but the Functional test's full-page render DOES exercise
  `my_rsvps`, so this fix was necessary for `testAuthenticatedUserSeesBothSections` to pass at
  all.

## Files touched by T at Phase 6

- `docs/groups/modules/do_streams/tests/fixtures/config/views.view.my_events.yml` — removed the
  broken `flagging_uid` filter block (lines 194–204), matching F's shipped-config fix.
- `tests/e2e/my-events.spec.ts` — fixed a test-authoring bug (the "Global toggle" test's
  precondition wrongly assumed elena is not a Leadership Council member) and added a
  T-GREEN self-correction note to the class docblock; the test still correctly fails, now for the
  right (production) reason.
