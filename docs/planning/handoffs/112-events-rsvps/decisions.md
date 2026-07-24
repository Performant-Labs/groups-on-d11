# Decision journal — Issue #112 (ST-3)

Append-only. Every phase logs Decided / Assumed / Hedged / Evidence.

## O — Phase 1 (survey + brief)

- **Decided:** Stack on `110-stream-110` (PR #173). Rebase completed;
  branch tip is now `112-events-rsvps` on top of ST-1's HEAD.
- **Decided:** Extend, don't duplicate. New `MyEventsController` +
  `views.view.my_events.yml` (own file), reusing existing shell,
  membership_scope filter, rsvp_event flag, do_discovery iCal routes.
- **Decided:** No new scope plugin — `?scope=global` handled by
  controller-level filter-override, per do_streams [B-8].
- **Decided:** Route access `_user_is_logged_in: 'TRUE'` (ST-1 pattern),
  not the issue's literal `_role: authenticated` (Drupal 11 core).
- **Decided:** RSVP chip via preprocess of stream_card render (or Views
  custom field — F picks whichever passes T's tests with less code).
- **Assumed:** Seed already produces the demo data the issue names
  (verified against `step_700_demo_data.php` lines 300-317: Keynote has
  4 RSVPs, elena has 3, Barcelona/Keynote/Sprint dates in that order).
- **Assumed:** `field_date_of_event` is populated for the 5 seeded
  events (verified in seed script line 193-195; if a fresh install
  fails, that's #60 territory — flag, don't fix).
- **Hedged:** Skipping brief-gate + A-dup per POC lean pipeline.
- **Evidence:** Read `MyFeedController.php`, `views.view.my_feed.yml`
  (from ST-1 commit), `IcalController.php`, `DoStreamsHooks.php`,
  `flag.flag.rsvp_event.yml`, `step_700_demo_data.php`, and the issue
  text. See survey.md for Reuse map.

## D — Phase 2 (wireframe)

- **Decided:** Both displays (Upcoming events, My RSVPs) render inside ONE
  `do_streams_shell` invocation on `/my-feed/events`, separated by plain
  `<h2>` section headers with distinct `data-testid`s
  (`upcoming-events-results`/`-empty`, `my-rsvps-results`/`-empty`) — not
  two shells, not two toggles.
- **Decided:** RSVP chip communicates state via icon + text in both the
  outline "RSVP . N going" and filled "You're going . N going" states
  (never color alone), per WCAG 2.2 AA 1.4.1; carries
  `data-going-count`/`data-viewer-state` for T's assertions.
- **Decided:** Two distinct empty-copy strings — Upcoming's names the
  membership prerequisite + a Global escape hatch; My RSVPs' points at the
  other section on the same page instead of repeating a join-a-group CTA.
  Only Upcoming carries a CTA button.
- **Decided:** iCal links (`do_discovery.ical_site`, `.ical_user`) are
  real, resolving `<a href>`s in the page header, not query-param mocks —
  the one intentional exception to the shell's "no hardcoded hrefs"
  convention, since the issue requires linking (not reimplementing) these
  stable existing routes.
- **Assumed (Q-D1):** RSVP chip rendered as a clickable `<button>` in the
  wireframe; the issue only asks for a read-time chip. F may render it as
  inert markup if a live toggle interaction is out of this issue's scope
  — flagged for operator confirmation, not blocking.
- **Assumed (Q-D2):** No 4th wireframe state for the #60 missing-date
  fallback (all 5 seeded events have dates today) — documented as a
  defensive implementation note (omit the date badge) rather than a
  designed state.
- **Evidence:** Rendered `wireframe.html` via headless Chromium
  (`npx playwright screenshot`) and visually inspected the full-page PNG
  before handoff — all icons/chips/badges confirmed complete, centered,
  on-canvas; no malformed glyphs. Reused #109/#110's exact shell markup,
  classes, testids, and design tokens (`:root` vars) verbatim; extended
  only the `.card` pattern into `.event-card` and added the chip/iCal/
  header partials as net-new, issue-owned pieces.

## A — Phase 3 (up-front plan review)

- **Decided:** PASS (with 3 advisory `warn` findings; no `block`). Reuse map is
  correct, no parallel paths, no premature abstraction. Cache-tag + route-access +
  Views API + iCal-link reuse patterns all consistent with ST-1.
- **Decided (advisory #1):** Two-section rendering is a real ambiguity — the shell
  has ONE `results` slot. Recommend controller-composes-two-sections (single shell
  invocation, `empty: FALSE` at shell, per-section markup inside `#results`). F to
  pick and note in F handoff before T writes tests.
- **Decided (advisory #2):** RSVP chip (preprocess OR Views custom field) MUST attach
  a flagging cache tag AND `user` cache context or it will leak per-user viewer-state
  across renders. T should add a small kernel assertion for the chip's cache metadata.
- **Decided (advisory #3):** `overrideOption('filters', ...)` is new-to-this-codebase
  but legitimate core Views API — F to use it request-time, keep shipped view config
  unchanged, and NOT reach for a new filter plugin (brief non-goal already enforces).
- **Assumed:** #60 flag-don't-fix is safe (field storage + field config both present
  in `docs/groups/config/`; seed populates per decisions.md O-Phase-1 entry).
- **Assumed:** Two-Views-displays design (`default` + `my_rsvps`, both non-page) is
  fine — one route on `do_streams.routing.yml`, controller `setDisplay()`s twice; no
  route collisions.
- **Hedged:** If F escalates advisory #1 to a shell-contract change (extend
  `do_streams_shell` to accept multiple sections), that's a cross-story refactor —
  operator scope call, not A's.
- **Evidence:** Read MyFeedController.php, do_streams.routing.yml, DoStreamsHooks.php,
  do-streams-shell.html.twig, views.view.my_feed.yml, IcalController.php,
  do_discovery.routing.yml, flag.flag.rsvp_event.yml, brief.md, survey.md,
  handoff-D.md, and grepped for `overrideOption` (zero hits) + `field_date_of_event`
  (storage + field config both present).

## T — Phase 4 (RED)

- **Decided:** Authored 3 test files against the 3 acceptance-criteria clusters (view
  contract + chip cache metadata at Kernel; route/access/sections/iCal/scope-widen at
  Functional; ordering/chip/toggle/iCal at E2E), each sized to A's 3 binding advisory
  findings (two-section composition, chip cache metadata, scope-widen as observable
  outcome only).
- **Decided:** Wrote a module-local fixture (`tests/fixtures/config/views.view.my_events.yml`)
  encoding the exact display contract (bundle=event, field_date_of_event ASC, membership_scope
  + future-date filter on Upcoming, rsvp_event flag_relationship scoped to the current user on
  My RSVPs, stream_card row) — mirrors `MyFeedRouteTest`'s established fixture convention for
  the sibling `my_feed` view.
- **Decided:** `testChipCacheMetadata` asserts against a directly-callable
  `DoStreamsHooks::buildRsvpChipCacheMetadata()` helper (does not exist yet) rather than the
  full theme-render pipeline, mirroring `StreamsShellTest`'s own precedent — the render
  pipeline does not reliably expose per-call cache metadata for spot assertion otherwise.
- **Assumed:** F may implement the RSVP chip via preprocess OR a Views custom field (brief
  allows either) — the kernel test's assertion targets a stable, implementation-agnostic
  method name so either choice can satisfy it.
- **Hedged:** The E2E suite's RED is inferred by construction (no seeded/running site stood up
  in this session) rather than confirmed by a live Playwright run — flagged explicitly in the
  handoff for T-GREEN to execute for real before reporting GREEN.
- **Self-corrected:** The Functional suite's first run fatal-errored at setUp() (flag entity
  dependency resolved at view-SAVE time, not execute time) — fixed by installing the real
  shipped `flag.flag.rsvp_event.yml` before the view fixture, re-confirmed RED is now the
  intended 404 on all 4 methods.
- **Evidence:** Verified RED live against a namespaced throwaway DDEV project (`gm112-events`)
  — `bash scripts/ci/assemble-config.sh` then `phpunit -c web/core/phpunit.xml.dist` against
  the assembled `web/modules/custom/do_streams/tests/...` layout, per the mandated
  assemble-first verification path. Kernel: 5 tests, 1 failure (chip cache metadata, right
  reason). Functional: 4 tests, 4 failures (all genuine 404s, right reason). E2E: statically
  listed (5 tests), not yet executed live — see handoff-T-red.md's coverage-gap note.

## F — Phase 5 (implementation, GREEN)

- **Decided:** Shipped `docs/groups/config/views.view.my_events.yml` as a direct copy of
  T's fixture's display contract (bundle/sort/filters/relationship/row), per the issue's
  explicit instruction that the fixture is the source of truth for the display shape —
  with two corrections discovered live (see below).
- **Decided:** RSVP chip implemented as a module-owned Twig template
  (`do_streams/templates/node--event--stream-card.html.twig`), populated by a new
  `preprocess_node__event__stream_card` hook + `buildRsvpChipRenderArray()` /
  `buildRsvpChipCacheMetadata()` helpers on `DoStreamsHooks` — NOT a Views custom field.
  Rendered as an inert `<span>` (never a `<button>`/`#type=>submit`), per handoff-D's Q-D1
  resolution: the brief only asks for a READ-time indicator, no live toggle wired here.
- **Decided (architectural finding, not in the plan):** The theme's own
  `node--stream-card.html.twig` (`web/themes/custom/groups_chrome/` — a gitignored build
  artifact, never editable) is a FIXED-shape template with no `{{ content }}` passthrough
  and produces entirely different markup (`gc-stream-card__*`) than the wireframe's
  `.event-card`. A module CAN supply a MORE SPECIFIC theme-hook suggestion
  (`node__event__stream_card`, per core's own `NodeThemeHooks::themeSuggestionsNode()`
  hierarchy) that wins over the theme's less-specific `node__stream_card` template — but
  ONLY if the module explicitly registers it in its own `hook_theme()` (`base hook` +
  `path` + `template` keys). A module's `templates/` directory is NEVER filesystem-scanned
  for suggestion-named files the way an active THEME's is
  (`drupal_find_theme_templates()` is only ever invoked with the theme's own path, per
  `\Drupal\Core\Theme\Registry::processExtension()`'s theme-vs-module handling). Missed
  this on the first pass (registry resolved the suggestion name but pointed at core's
  generic `node` template); fixed by adding an explicit `node__event__stream_card` entry to
  `DoStreamsHooks::theme()`. Confirmed live via a smoke-tested site before and after the fix.
- **Decided (bug found + fixed, not in the plan):** The `my_rsvps` display's `flagging_uid`
  filter (`table: flagging`, `field: uid`, `plugin_id: user_current`) is structurally
  non-functional — `\Drupal\views\Plugin\ViewsHandlerManager::getHandler()` NEVER reads a
  handler's configured `plugin_id`; it always instantiates whatever the FIELD's OWN
  `views_data` registration declares for that handler type (confirmed:
  `flagging.uid`'s own `views_data` declares `filter: {id: numeric}` — `NumericFilter`, not
  `Current`/`BooleanOperator`). The configured `user_current` plugin_id is silently ignored;
  the filter fatals (500, `TypeError` in `NumericFilter::opSimple()`) once its `value` is a
  scalar (as `Current`'s config shape expects) rather than the `NumericFilter`-expected
  `['value' => ...]` array. Root-caused AND fixed by REMOVING the `flagging_uid` filter
  entirely from the shipped config: `FlagViewsRelationship::query()` (the `rsvp_relationship`
  relationship handler, `user_scope: current`) already adds its OWN
  `uid = '***CURRENT_USER***'` condition directly on the relationship's JOIN — the filter was
  redundant even before being found broken. Verified live with TWO users each RSVP'd to a
  DIFFERENT event: each saw only their own event, confirming the relationship's join alone
  is correct and sufficient. T's fixture (`tests/fixtures/config/views.view.my_events.yml`)
  has the IDENTICAL `flagging_uid` block — flagged in handoff-F for T to apply the same
  removal there; F did NOT edit the fixture.
- **Assumed:** `?scope=global` implemented via `$view->getDisplay()->overrideOption('filters',
  $filters_without_membership_scope)` between `setDisplay()` and `execute()`, exactly as A's
  Finding #3 specified — verified live (member-group event + non-member-group event: default
  scope shows only the former, `?scope=global` shows both).
- **Assumed:** `HelpText.php`'s new `stream.my_events` key follows the exact
  `stream.my_feed` (#110) precedent — pure copy, no consuming markup wired this issue (the
  pre-existing W2-pre-registered `page.my_feed_events` key stays inert, since this route is
  hand-authored, not a Views page display, mirroring `page.my_feed`'s own already-accepted gap).
- **Hedged:** phpcs (`--standard=Drupal,DrupalPractice`) reports pre-existing violations in
  `HelpText.php` (26, all at lines <=178, before this issue's own line-289+ append) and
  8 `\Drupal calls should be avoided` warnings in `DoStreamsHooks.php` (4 pre-existing + 4 new,
  all following the SAME documented `hook_implementations`-service DI constraint
  `do_notifications` already established) — confirmed via a byte-for-byte baseline diff that
  none of this is new debt introduced by this issue; not fixed (out of scope, pre-existing).
- **Evidence:** `bash scripts/ci/assemble-config.sh` (clean copy). `php vendor/bin/phpcs
  --standard=Drupal,DrupalPractice` on every touched file (0 new errors; pre-existing baseline
  isolated by diff). Kernel (`MyEventsViewTest`): 5/5 pass. Functional (`MyEventsRouteTest`):
  4/4 pass. Full `do_streams` regression (30 kernel + 12 functional): all pass, zero
  regressions. Full `do_chrome` regression (27 tests): all pass. Live smoke test against a
  freshly `site:install`'d + module-enabled DDEV instance (not the seeded demo site — that is
  T-GREEN's/U's job): real HTTP session as an authenticated user, confirmed `.event-card` +
  `rsvp-chip` markup, correct going-count/viewer-state text and data-attributes, correct
  iCal hrefs, correct page title, and correct per-user My-RSVPs scoping across two distinct
  users each RSVP'd to a different event.

## T — Phase 6 (GREEN + Tier 2)

- **Decided:** BLOCKED, not GREEN. Kernel (5/5) and Functional (4/4) suites pass fully. E2E passes
  4/5 — the 5th ("Global toggle widens Upcoming beyond elena's memberships") surfaces a genuine
  production bug, not a test defect: `do-streams-shell.html.twig` unconditionally renders its own
  generic 4-tab `scope_tabs` nav + `ranking_control` pills regardless of the calling controller's
  intent, so `/my-feed/events` shows BOTH the shared shell's dead 4-tab nav AND
  `MyEventsController`'s own correct 2-tab toggle, sharing identical
  `data-testid="do-streams-shell-tab"` + `data-scope-id="global"` pairs (strict-mode collision).
  handoff-A.md Finding #1 anticipated exactly this risk; F's mitigation (documented in
  `DoStreamsHooks.php`'s "Issue #112 note") correctly reasoned that `empty`/`empty_copy` are
  harmless dead weight on this route but incorrectly assumed the same of `scope_tabs`/
  `ranking_control`, which DO render, visibly, with no guard.
- **Decided:** Fixed one test-authoring bug in my own `tests/e2e/my-events.spec.ts` before
  concluding the toggle test's failure was code-side: the test's "before" assertion wrongly
  claimed elena is not a member of EITHER Thunder Distribution OR Leadership Council — she IS a
  Leadership Council member per `step_700_demo_data.php`'s own Step 730a seed, so "Governance Town
  Hall" legitimately appears under the default scope. Scoped the assertion to "Thunder Editorial
  Workshop" only (the one genuinely non-member event). This fix resolved the "before" half of the
  test but not the toggle-click half, which is the real production bug above.
- **Decided:** Removed the broken `flagging_uid` filter block from my own kernel fixture
  (`tests/fixtures/config/views.view.my_events.yml`), matching F's identical fix to the shipped
  config (F flagged this exact gap in decisions.md's Phase-5 entry and correctly left my fixture
  untouched for me to fix).
- **Decided:** Ran Tier 1 regression broadly: do_streams+do_discovery+do_chrome+do_group_pin
  Kernel (45/45 pass), do_streams+do_chrome Functional (5/5), do_showcase+do_group_membership+
  do_multigroup+do_group_extras+do_tests Functional (69/69, one transient cross-test error on a
  single combined run that did not reproduce on isolation or re-run — flaky, not a regression),
  E2E nav.spec.ts + showcase.spec.ts (26/26, after fixing an admin-password mismatch caused by MY
  OWN fresh site:install, not a code issue).
- **Decided:** Verified RSVP chip cache metadata at REAL render time (not just kernel-mocked) via
  `drush php:script` against the live seeded Keynote node: `contexts: [user]`,
  `tags: [flagging_list:node:13]` — matches handoff-A.md Finding #2's obligation exactly.
- **Assumed:** The Axe/WCAG automated scan the task requested could not run — `@axe-core/playwright`
  is not installed in this repo (confirmed via `npm ls`), consistent with T-red's own already-
  documented gap. Flagged as advisory for U/S, not a suite failure.
- **Hedged:** phpcs on `DoStreamsHooks.php` shows 1 error / 8 warnings vs. a pre-#112 baseline of
  1 error / 4 warnings — diffed and confirmed the delta is exactly 4 new `\Drupal calls should be
  avoided` warnings, the SAME pre-existing DI-constraint pattern F's own hedge in Phase-5 already
  named (not new debt category, just more instances of an accepted pattern).
- **Evidence:** DDEV `gm112-events` (worktree-local `.ddev/config.yaml` had a stale `name:` from a
  prior worktree copy — fixed). Fresh `site:install --existing-config --profile=standard` (had to
  add `$settings['config_sync_directory']` to `web/sites/default/settings.php` per RUNBOOK.md Step
  105 — gitignored, not part of the diff). Seeded via `step_700_demo_data.php`. BrowserTestBase's
  webserver started manually (`php -S 127.0.0.1:8080 -t web web/.ht.router.php`, backgrounded
  inside the DDEV container) since `ddev exec` doesn't serve one by default. All commands and raw
  output captured in `handoff-T-green.md`.

## Blocking issue for F (re-open Phase 5)

The shared `do_streams_shell` template's unconditional `scope_tabs`/`ranking_control` rendering
must be gated so `/my-feed/events` shows ONLY `MyEventsController`'s own 2-tab toggle, not both.
See `handoff-T-green.md`'s "Blocking issues" section for the two suggested fix shapes. Re-run A
if the fix touches the shell's template contract (Finding #1 territory), then re-run T (Kernel/
Functional/E2E) before U/S.

## F — Phase 5 rework (resolves the Phase 6 blocker above)

- **Decided:** Implemented option (a) from handoff-T-green.md's suggested fix shapes — a small,
  backward-compatible `do_streams_shell` template-contract addition — rather than option (b)
  (bypassing the shared shell entirely). Added `suppress_default_chrome` (bool, default `FALSE`)
  to the theme hook's `variables` declaration; `MyEventsController::buildShell()` now passes
  `'#suppress_default_chrome' => TRUE`; `preprocessDoStreamsShell()` honors it by leaving
  `scope_tabs`/`ranking_control` both empty instead of building the default 4-tab/2-pill lists;
  the Twig template gates the `<nav>`/ranking `<div>` wrappers behind `{% if %}` so nothing
  renders at all (not just zero `<span>` children inside an empty-but-present landmark).
- **Decided (deviation from the task's literal instruction, load-bearing):** The task instructed
  implementing this as "respect a caller-supplied `#scope_tabs => []` verbatim" (an emptiness
  check on the render-array property), not a new dedicated flag. Traced
  `\Drupal\Core\Theme\ThemeManager::render()` directly (lines ~190-213) before implementing that
  literally, and found it is NOT implementable that way: the render pipeline's own
  `array_key_exists("#$name", $element)` + `$variables += $info['variables']` merge mechanics mean
  a caller who explicitly sets `#scope_tabs => []` and a caller who never sets `#scope_tabs` at all
  are BOTH observed as `$variables['scope_tabs'] === []` by the time the preprocess hook runs —
  indistinguishable. Confirmed this is not theoretical: `StreamsShellTest`'s own pre-existing
  Kernel-test harness (`preprocessShellVariables()`) ALSO pre-seeds `scope_tabs: []` before
  invoking the hook directly, on the explicit expectation that the hook overwrites that empty seed
  with the full list regardless — an emptiness-based implementation would have broken all 6 of
  that suite's contract tests to fix the one E2E test, a straight regression trade. Implemented a
  dedicated boolean flag instead (defaults `FALSE`, untouched by every existing caller including
  that Kernel test's own harness) — achieves the identical OBSERVABLE outcome the task asked for
  (shell chrome suppressed on `/my-feed/events`) via a mechanism that is actually correct against
  the real render pipeline and the pre-existing test suite.
- **Decided:** Left T's test fixups (fixture, `my-events.spec.ts`) untouched — reviewed both,
  found no issues, no further changes needed from this rework.
- **Assumed:** None beyond what F-Phase-5 already assumed; this rework is scoped to exactly the
  one blocker T-GREEN identified.
- **Hedged:** None — the fix was verified live (curl with an authenticated session,
  `grep -c`/`grep -o` on the rendered HTML) before running the automated suites, not merely
  inferred from the code change.
- **Evidence:** Re-ran Kernel (`MyEventsViewTest` + `StreamsShellTest`, since the change touches
  shared preprocess logic the latter also exercises): 11/11 pass, twice (before and after a
  deliberate `git stash`/`assemble-config.sh` re-run/`git stash pop` cycle used to obtain an
  apples-to-apples phpcs baseline comparison — identical result both times). Functional
  (`MyEventsRouteTest`): 4/4 pass, twice. E2E (`tests/e2e/my-events.spec.ts` against the live
  seeded `http://gm112-events.ddev.site`): 5/5 pass, twice, including the previously-blocked
  "Global toggle widens Upcoming beyond elena's memberships" test. Full regression re-run:
  do_streams+do_discovery+do_chrome+do_group_pin Kernel 45/45, do_streams+do_chrome Functional
  5/5, nav.spec.ts+showcase.spec.ts E2E 26/26 — all matching T-green's own prior counts exactly,
  zero regressions from the shared-shell change. phpcs: `MyEventsController.php` and
  `do-streams-shell.html.twig` both 0 errors/0 warnings; `DoStreamsHooks.php` 1 error/8 warnings,
  byte-verified identical in violation SET (not just count) to the pre-rework baseline via a
  `git stash` + `assemble-config.sh` re-run comparison (baseline: same single docblock error +
  same 8 `\Drupal calls should be avoided` warnings, only absolute line numbers shifted from the
  added docblock content) — zero net-new violations. See `handoff-F-rework.md` for full detail.
