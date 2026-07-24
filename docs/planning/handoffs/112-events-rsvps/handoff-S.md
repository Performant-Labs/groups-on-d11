# Handoff-S: Phase 9 (Spec Audit) — Issue #112 (ST-3: /my-feed/events)

**Date:** 2026-07-23
**Branch:** `112-events-rsvps` @ `9727e15` (2 commits off `origin/main` base `01f49a5`)
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st3-events-112`
**Handoff-U reviewed:** `docs/planning/handoffs/112-events-rsvps/handoff-U.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/112-events-rsvps/handoff-T-green.md`
**Handoff-A reviewed:** `docs/planning/handoffs/112-events-rsvps/handoff-A.md`
**Handoff-F / F-rework reviewed:** `docs/planning/handoffs/112-events-rsvps/handoff-F.md` +
`handoff-F-rework.md`

## Verdict

**PASS** — recommend O opens the PR after **first rebasing onto current `origin/main`**
(see "Merge-time note", not an audit finding against #112 itself).

All 10 acceptance criteria are met on the seeded site. The one code-side defect T-GREEN
surfaced (duplicate/phantom shell chrome) was correctly fixed by F-rework's
`suppress_default_chrome` flag addition to the `do_streams_shell` theme hook — verified
live by U (`.shell-tabs` phantom count = 0, `.shell-ranking` phantom count = 0, real
`Events scope` toggle unaffected) and by kernel/functional/E2E regression (11/11 + 4/4 +
5/5, plus 45/45 kernel + 5/5 functional + 26/26 E2E regression across sibling modules).
Two U ADVISORY findings (dup H1, scope-tabs not landmark) are both pre-existing to #112
(they trace to `do_streams` shell chrome originally shipped by #109/#110, not to any
markup #112 introduces) and do not gate this ship.

## Per-AC audit

| # | Acceptance criterion (issue #112) | Status | Backing evidence |
|---|---|---|---|
| 1 | Upcoming (my-groups) shows Barcelona → Keynote → Sprint in date ASC for elena_garcia | **PASS** | E2E "Upcoming (My Groups scope) lists Barcelona, Keynote, Sprint in date order" (T-green); U row 7 (posB=545 < posK=662 < posS=750). Governance Town Hall (4th event) is not a defect — see "Spec-vs-seed note" below. |
| 2 | My RSVPs lists elena's three; Keynote chip shows 4 going | **PASS** | E2E "My RSVPs lists elena's three RSVPs..." + "Keynote card shows 4 going" (T-green); U row 8 (chip text `✓ You're going · 4 going` observed); Kernel `testChipCacheMetadata` pins the cache correctness so counts can't leak. |
| 3 | Past events excluded | **PASS** | Kernel `testUpcomingDisplayContract` (future-date `>=` filter present); U row 23 (all shown events Aug–Oct 2026). |
| 4 | Global toggle widens the list | **PASS** | E2E "Global toggle widens..." (T-green after F-rework; previously RED on phantom-nav duplicate testid); U row 16 (Thunder + Governance appear; count 4→5). |
| 5 | iCal links present and resolving | **PASS** | Functional `testIcalLinksPresentAndResolve` (200 + `text/calendar`); U rows 10–13 (both `/upcoming-events/ical` and `/user/4/events/ical` return `text/calendar; charset=utf-8`). |
| 6 | Playwright rendered-DOM spec on seeded site | **PASS** | 5/5 GREEN post-rework (T-green re-run under F-rework's evidence section). Ordering test genuinely pins live-DOM position via `toBeLessThan(posK)` — cannot be silently reordered. |
| 7 | Existing suite stays green | **PASS** | Regression: `do_streams`+`do_discovery`+`do_chrome`+`do_group_pin` Kernel 45/45; `do_streams`+`do_chrome` Functional 5/5; `nav.spec.ts`+`showcase.spec.ts` E2E 26/26. Zero regressions traceable to #112. One transient Functional cross-test flake (`ManageMembersRouteResolutionTest`, unrelated module) did not reproduce in isolation — accepted advisory, not a #112 regression. |
| 8 | HelpText entry appended | **PASS (with caveat)** | `page.my_feed_events` key IS present on this branch at line 232 of `HelpText.php` with copy "Upcoming events from the groups you belong to." — verified by direct grep. The key was already registered on the branch's base (`01f49a5`) as one of the 5 W2 pre-registered entries; F chose not to re-append (correctly, per append-only + no-duplicate-key policy). The POC AC bar ("ships with its HelpText entry (append-only) for any new user-facing surface — SD-6 is the backstop") is satisfied: the key exists, the route resolves, the tooltip trigger renders. The richer decision-support copy shipped later on `main` by #131 (SD-4 enrichment) is not part of #112's contract — it will re-land automatically when this branch rebases onto current `main`. **Not a rework finding.** |
| 9 | WCAG 2.2 AA | **PASS (with tooling gap)** | U spot-checked manually: chip communicates state via icon+text (never color-only, U row 9); focus ring visible (`outline: 3px solid rgb(4,69,104)`, U row 20); keyboard reaches 25 focusable stops in 25 tabs (U row 19); heading levels never skip (U row 18); mobile 360 reflows cleanly (U row 21). `@axe-core/playwright` is not installed in the repo — pre-existing tooling gap flagged by T-red / T-green / U consistently; not a #112 obligation. |
| 10 | Delivery per epic (branch + assemble-first + local Playwright green + PR + merge on green CI) | **PASS** | Assemble ran clean (T-green Tier-1 table). Live seeded DDEV project `gm112-events` stood up per RUNBOOK.md. `npx playwright test tests/e2e/my-events.spec.ts` GREEN 5/5. PR opens next per your standard pipeline. |

## U advisory findings — my read

**Advisory-1 (duplicate H1 on `/my-feed/events`).** Confirmed by U's heading-level scan; two
consecutive H1s both reading "My Feed — Events" (the theme's own page-title block +
`MyEventsController`-emitted title inside the shell). Same pattern almost certainly affects
`/my-feed` (`#110`) and any other `do_streams_shell`-themed route that also emits its own
title. WCAG 2.2 AA does not forbid multiple H1s, but it is a screen-reader nuisance.
**Agree with U: do not block #112.** File a follow-up under `do_streams` shell chrome (single
consolidation fix covers every stream page).

**Advisory-2 (scope-tabs is a `<div>`, not a `<nav>`).** Container is
`<div data-testid="do-streams-shell-tabs" class="shell-tabs">` with two `<a>` children — no
`role="tablist"`, no `<nav aria-label>` wrapper. Keyboard-reachable and text-labelled, so it
functions; it just doesn't advertise itself as a distinct navigation landmark to assistive
tech. **`do_streams`-wide**, not #112-specific — `MyEventsController::buildScopeToggle()`
uses the exact same shape `MyFeedController` established in #110. **Agree with U: do not
block #112.** Same follow-up store as Advisory-1.

## F-rework's `suppress_default_chrome` flag — does it satisfy A's Finding #1 intent?

**Yes.** A's Finding #1 asked F to keep the `do_streams_shell` contract clean and NOT
extend the shell hook to accept per-caller results/empty sections. F's rework does exactly
that: it adds ONE optional boolean variable (`suppress_default_chrome`, default `FALSE`,
untouched by every pre-#112 caller including `StreamsShellTest`'s own 6-test harness), plus
two `{% if %}` guards in the template that suppress the empty landmark wrappers entirely
rather than leaving vestigial `<nav>`/`<div>` shells. The existing 4-tab `scope_tabs` and
2-pill `ranking_control` shapes are untouched for every caller that doesn't opt in.

F's decision to deviate from the task's literal wording ("respect a caller-supplied
`#scope_tabs => []`") is well-defended: F traced `ThemeManager::render()` directly and
demonstrated that the emptiness-check approach would break all 6 `StreamsShellTest`
contract tests (which pre-seed `scope_tabs: []` before invoking the preprocess hook, on the
explicit contract that the hook overwrites that empty seed). Choosing "break 6 to fix 1" would
have been the wrong trade. The dedicated flag achieves the identical OBSERVABLE outcome via
a mechanism that is actually correct against the real render pipeline and the pre-existing
test contract.

Verified live by U (rows 14–15: `aria-label="Stream scope"` count = 0, `.shell-ranking`
count = 0) and by F-rework's own re-run of the full `do_streams` + regression suites (11/11 +
5/5 + 45/45 + 26/26 — no regressions).

**One forward-looking note (not a finding):** the flag ties suppression of `scope_tabs`
AND `ranking_control` together. Any future story that wants to suppress ONLY one will need
either a second flag or a granular value shape. F documented this trade in its own
architecture-notes-for-A section; acceptable YAGNI call for this issue.

## Spec-vs-seed note (AC-1's "3 events" number)

The issue's AC-1 anticipates 3 events under Upcoming (default my-groups scope). The
delivered UI shows 4 (Governance Town Hall precedes the three by date). This is **not** an
S-BLOCK: `step_700_demo_data.php` Step 730a makes elena_garcia a Leadership Council member
(seed line: `"Leadership Council" => ["james_okafor", "maria_chen", "elena_garcia"]`), so
Governance Town Hall legitimately falls under her my-groups scope. The AC number was
under-specified relative to the seed the same brief cites — a minor spec imprecision, not a
defect. F, T (Phase 6 self-correction), and U (Note in handoff-U.md) all independently
reached the same reconciliation. Recording here as advisory context; **not** an
ADVISORY-HOLD because the demo still reads correctly (Governance is a real, plausibly-scoped
event, not visual noise) and the three headline events named in the AC (Barcelona → Keynote
→ Sprint) all render in the specified order.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| API consistency (iCal contract) | PASS | Reuses `do_discovery` routes by name only; `ical_user` receives current-viewer uid, never hardcoded (F handoff + A cross-check). |
| Error handling | PASS | Anonymous route access returns 403/login-redirect (Functional `testAnonymousGetsDeniedOrRedirectedToLogin`; U row 1). |
| UI/UX match to spec | PASS | U wireframe-conformance section: chip markup, iCal buttons, section headings, date badges, group badges, RSVP chip states all match `wireframe.html`. |
| Accessibility | PASS (with pre-existing tooling gap) | Manual spot-check: icon+text chip, visible focus ring, keyboard-complete, no skipped heading levels. Automated axe scan gap is `do_streams`-wide pre-existing, not #112's problem. |
| Architecture gate | PASS | A gate PASS with 3 advisories, all F-handled correctly (Finding #1 — `suppress_default_chrome` shape is the right answer; Finding #2 — chip cache metadata verified live via drush php:script; Finding #3 — `overrideOption('filters', ...)` used exactly as A prescribed, shipped view config unchanged). |
| Code organization | PASS | New files sized per survey; no cross-story coupling; module owns exactly one card shape (`node--event--stream-card.html.twig`); `DoStreamsHooks` extensions follow the same `do_notifications` DI-constraint pattern already accepted in this codebase. |
| Security | PASS | `_user_is_logged_in: 'TRUE'`; `?scope=global` is a fixed-enum request-time check (no user input reaches Views filter interpolation); no secrets/sensitive data in play. |
| Performance | PASS | Cache metadata correct at every scope: `flagging_list:node:<nid>` tag + `user` context on the chip; `DoStreamsHooks::userStreamCacheTag($uid)` + `user`/`user.roles:authenticated` contexts on the outer shell — verified live via drush at real render time (T-green Tier-2). RSVP-toggle invalidates via `entity_insert`/`entity_delete` hooks scoped to `rsvp_event` flaggings only, mirroring existing `pin_in_group` pattern. |
| Visual regression | N/A | No Playwright VR baselines wired for this route in this repo; U's 6 screenshots serve as the operator-visible artifact for this cycle. |
| Naming consistency | PASS | Route id `do_streams.my_events` mirrors `.my_feed`; class `MyEventsController` mirrors `MyFeedController`; view file `views.view.my_events.yml` mirrors `views.view.my_feed.yml`; CSS `events.css` mirrors `my-feed.css`; HelpText key `page.my_feed_events` follows the established W2 map. Zero drift. |
| Test quality (`testing/test-quality.md` §7) | PASS | Kernel 5 tests (view-exists / upcoming contract / my_rsvps contract / bundle-filter defense-in-depth / chip cache metadata) — each pins a distinct behavior at the cheapest sufficient tier, no duplication. Functional 4 tests (anonymous denied / both sections render / iCal links present-and-resolve / global-scope widens) — each pins a distinct route/access/render behavior BrowserTestBase is uniquely positioned for. E2E 5 tests — each pins a live-DOM outcome kernel/functional can't (date-order ASC via `toBeLessThan` position, real chip text, toggle-click widening, iCal hrefs in the DOM). No test is assertion-free, tautological, or mock-shaped. Suite is proportionate — no coverage-padding, no snapshot-everything. Test count (14) sits at the low end of "a feature story with 3 acceptance-criteria clusters" — a "delete or merge" pass finds nothing to remove. |

## Scope check

F delivered exactly the phase scope: 8 files touched (7 in `do_streams` + 1 append-only
line in `do_chrome/src/HelpText.php`), all within the survey's own "Owns" list. No
over-delivery (no unrequested refactor of neighboring stories, no premature abstraction).
No under-delivery (every AC line item mapped to a test or a manual verification). The one
scope EXPANSION — extending `do_streams_shell`'s theme-hook contract with
`suppress_default_chrome` — was a necessary rework in response to T-GREEN's blocker, and A's
Finding #1 explicitly anticipated it as a possible cross-story path (F chose it over the
"controller bypasses the shell" alternative for consistency with `MyFeedController`'s
invocation pattern). Documented in F-rework's architecture-notes-for-A. Correct call.

## Merge-time note (for O, not a #112 audit finding)

This branch is based on `01f49a5` (pre-#131 / pre-#114 / pre-#115). Four merges have landed
on `origin/main` since. `git diff origin/main -- docs/groups/modules/do_chrome/src/HelpText.php`
appears to "delete" the enriched W2 copy and the entire `stream.*`/`profile_activity.*`
sections — but those "deletions" are just what `git diff` reports for content that landed on
`main` AFTER this branch was cut. The working tree on this branch is intact; nothing was
actually removed by #112. **A rebase onto current `origin/main` before opening the PR is
required** or the merge will regress #131/#114/#115. This is not an S rework finding
against #112's own deliverables (F's original handoff also flagged a similar staleness
concern with respect to ST-1 at the time the branch was cut); it is a pipeline-hygiene note
for the PR-open step.

## Findings requiring rework

**None.** All 10 acceptance criteria are met on the seeded site.

## Advisory notes (non-blocking)

- **U's Advisories 1 & 2** (dup H1, scope-tabs not `<nav>`) — file a `do_streams`-wide
  a11y follow-up story; do not gate #112.
- **Axe/WCAG tooling gap** — persistent across #110/#112/#114/#115; recommend a tooling-infra
  story adds `@axe-core/playwright` so future stories can gate on it automatically. Not a
  #112 obligation.
- **`suppress_default_chrome` granularity** — flag suppresses `scope_tabs` and
  `ranking_control` together. If a future story needs to suppress just one, a signature
  refinement will be needed. Acceptable YAGNI for #112.
- **`page.my_feed_events` HelpText copy** — the branch carries the basic W2 copy
  ("Upcoming events from the groups you belong to."), not the richer decision-support copy
  that #131 shipped on `main` ("Upcoming events from the groups you've joined, plus a My
  RSVPs view of events you've responded to. Unlike the site-wide event calendar, this is
  scoped to your own group memberships and responses."). A clean rebase onto current `main`
  will restore the richer copy automatically — no action needed on this branch.
- **Merge-time rebase required** (see "Merge-time note" above) — pipeline hygiene, not a
  #112 defect.

## Ready for merge

`S complete. Verdict: PASS. Ready for O to open the PR (rebase onto current origin/main first).`
