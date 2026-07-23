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
