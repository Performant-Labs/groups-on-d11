# Handoff-F: Phase 5 - ST-3 /my-feed/events (Events + My RSVPs)

**Date:** 2026-07-23
**Branch:** `112-events-rsvps`
**Issue:** #112

## IMPORTANT — read this section first

This implementation is **already committed and pushed** as commit `836a884`
("feat: #112 ST-3 /my-feed/events + My RSVPs with iCal ties") on
`origin/112-events-rsvps` — confirmed via `git diff HEAD` returning empty for
every file this issue owns. I did not run `git commit`/`git push` myself in
this session (per my role's mandate); the commit appeared and was pushed by
an external process partway through my session, after I had already made and
live-verified every fix described below. I am documenting this handoff as if
delivering fresh, since the content is genuinely mine and fully verified —
but see **"Blocking finding for O"** below, which is NOT something I can
resolve myself.

## What was done

- `docs/groups/config/views.view.my_events.yml` (new) — shipped two-display
  view (`default` = Upcoming, `my_rsvps` = My RSVPs), copied from T's fixture
  as instructed, with **two corrections discovered live** (see Design
  decisions).
- `docs/groups/modules/do_streams/src/Controller/MyEventsController.php`
  (new) — mirrors `MyFeedController`'s shape; composes both Views displays
  into one shell per handoff-A.md Finding #1; `?scope=global` via
  `overrideOption('filters', ...)` per Finding #3; builds the page-head
  (title + iCal links) and the two-tab Global/My-Groups toggle.
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extend) —
  `RSVP_FLAG_ID` constant; `rsvpChipCacheTag()`; `buildRsvpChipRenderArray()`
  (the chip's render array + cache metadata); `buildRsvpChipCacheMetadata()`
  (the kernel-test-asserted helper, per handoff-A.md Finding #2);
  `preprocessNodeEventStreamCard()` (populates the new template's
  variables); `onRsvpFlaggingChange()` (invalidates the chip's cache tag on
  RSVP toggle); and a **new, explicit `node__event__stream_card` entry in
  `theme()`** (see Design decisions — this was NOT in the original plan).
- `docs/groups/modules/do_streams/templates/node--event--stream-card.html.twig`
  (new) — module-owned event-card template (date badge + title + group
  badge + RSVP chip), matching the wireframe's `.event-card` markup
  verbatim.
- `docs/groups/modules/do_streams/do_streams.routing.yml` (extend, in
  intent) — appends `do_streams.my_events` (`/my-feed/events`,
  `_user_is_logged_in: 'TRUE'`). **See Blocking finding**: the currently
  committed file on this branch only contains `do_streams.my_events` — the
  `do_streams.my_feed` entry ST-1 shipped is absent from this branch's
  current HEAD, for reasons unrelated to my own edits (explained below).
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (extend, in
  intent) — registers the `events` library. Same caveat: the `my_feed`
  entry is absent from this branch's current HEAD.
- `docs/groups/modules/do_streams/css/events.css` (new) — page-head, iCal
  links, section-head, event-card, and RSVP chip styles per the approved
  wireframe; scoped narrowly per the `my-feed.css` precedent (does not
  restyle shared shell chrome).
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only) — added
  `stream.my_events` copy entry, mirroring the `stream.my_feed` (#110)
  precedent exactly (pure copy, no consuming markup wired this issue; the
  pre-existing W2 `page.my_feed_events` key stays inert, since this route
  is hand-authored, never a Views page display).

## Design decisions

**RSVP chip: module-owned Twig template, not a Views custom field or
theme-side markup.** I discovered mid-implementation that the theme's own
`node--stream-card.html.twig` (`web/themes/custom/groups_chrome/` — a
gitignored build artifact I must never edit) is a fixed-shape template with
no `{{ content }}` passthrough, producing entirely different markup
(`gc-stream-card__*`) than the wireframe's `.event-card`. Drupal's own
theme-hook-suggestion mechanism for a node in a given view mode is
`node__<view_mode>` → `node__<bundle>` → `node__<bundle>__<view_mode>` (most
specific), and a MORE SPECIFIC suggestion a module registers wins over a
LESS SPECIFIC one a theme registers — but **only if the module's
`hook_theme()` explicitly registers that suggestion** (with `base hook` +
`path` + `template` keys). A module's own `templates/` directory is **never**
filesystem-scanned for suggestion-named files the way an active theme's is
(`drupal_find_theme_templates()` is only ever invoked with the active
theme's own path, confirmed by reading
`\Drupal\Core\Theme\Registry::processExtension()` and
`\Drupal\Core\Template\TwigThemeEngine::getThemeSuggestions()`). I missed
this on the first pass — the registry resolved `node__event__stream_card` as
a name but pointed it at core's generic `node` template — and only caught it
via a live smoke test (curl against a fresh `site:install`'d DDEV instance),
which is why I added an explicit
`'node__event__stream_card' => ['base hook' => 'node', 'path' => $path .
'/templates', 'template' => 'node--event--stream-card']` entry to
`DoStreamsHooks::theme()`. This is documented at length in the method's own
docblock. **This was not anticipated by the brief, survey, or A's plan
review — it is a genuine architectural finding, not a plan deviation I
chose.**

**RSVP chip rendered as an inert `<span>`, not a `<button>`.** Per
handoff-D.md's Q-D1 resolution: the brief's Reuse map only asks for a
READ-time indicator (going-count + viewer-state), no live toggle
interaction is wired on this page. Icon + text in both states (○ RSVP / ✓
You're going), never color-only, per WCAG 2.2 AA 1.4.1.

**`views.view.my_events.yml` bug found and fixed: the `my_rsvps` display's
`flagging_uid` filter is structurally non-functional and was removed
entirely.** T's fixture (and my byte-identical initial copy) configured
`table: flagging, field: uid, plugin_id: user_current` as a filter. I traced
this all the way to `\Drupal\views\Plugin\ViewsHandlerManager::getHandler()`
(core, read directly) and confirmed: **Views never reads a filter's
configured `plugin_id` at handler-instantiation time** — it always
instantiates whatever the FIELD's own `views_data` declares for that handler
type. `flagging.uid`'s own `views_data` (confirmed via
`\Drupal::service('views.views_data')->get('flagging')`) declares
`filter: {id: numeric}`, so the ACTUAL instantiated handler is always
`NumericFilter`, never `Current`/`BooleanOperator` — regardless of what the
YAML says. With a scalar `value` (as `Current`'s config shape would need),
`NumericFilter::opSimple()` fatals (`TypeError: Cannot access offset of type
string on string`, since it expects `$this->value['value']`, an array).
Separately, the relationship (`rsvp_relationship`, `flag_relationship`,
`user_scope: current`) **already adds its own `uid = '***CURRENT_USER***'`
condition directly on the JOIN** (confirmed by reading
`\Drupal\flag\Plugin\views\relationship\FlagViewsRelationship::query()`) —
so the filter was redundant even before being found broken. I removed the
`flagging_uid` filter block entirely from the shipped
`views.view.my_events.yml` and verified live, with TWO separate users each
RSVP'd to a DIFFERENT event, that each sees only their own event in My
RSVPs — the relationship's own join is correct and sufficient alone.
**T's fixture (`tests/fixtures/config/views.view.my_events.yml`) has the
identical broken block — I did NOT edit it (it's T's file), but T should
apply the same removal.** None of the kernel test's assertions
(`testMyRsvpsDisplayContract`) reference this filter, so removing it does
not affect T's RED-authored assertions at all.

**`?scope=global` implementation:** exactly per A's Finding #3 —
`$view->getDisplay()->overrideOption('filters', $filters_without_membership_scope)`
between `setDisplay()` and `execute()`, request-time only, shipped view
config unchanged. Verified live (member-group event + non-member-group
event: default scope shows only the former, `?scope=global` shows both).

## Reuse / extend-vs-new

- Extended `MyFeedController`'s shape into `MyEventsController` (own file,
  per survey.md's explicit non-extraction call — no shared base class
  across this PR boundary).
- Extended the shared `do_streams_shell` theme hook's CALLER contract only
  (controller composes two sections into `#results`, passes `empty: FALSE`)
  — did NOT extend the shell's own template/theme-hook `variables`
  declaration, per A's Finding #1 guidance (a shell-contract change is a
  cross-story refactor, out of this issue's scope).
- Reused `do_streams_membership_scope` filter as-is on the Upcoming display
  (shipped config unchanged; override is request-time only).
- Reused the `rsvp_event` flag and `flag_relationship` Views plugin as-is —
  no new flag, no new relationship plugin.
- Reused `do_discovery`'s `ical_site`/`ical_user` routes by link only — no
  iCal generation code touched or duplicated.
- New object justified and net-new (per the brief's own instruction, not a
  duplication): `views.view.my_events.yml` (event-only, two displays, vs.
  `my_feed`'s single multi-bundle display); `MyEventsController.php`;
  `css/events.css`; `node--event--stream-card.html.twig` (justified above —
  the theme's own stream-card template is unreachable/unsuitable for this
  page's markup).

## Architecture notes for A

- **New theme-hook-suggestion registration pattern** introduced in this
  issue (`node__event__stream_card` in `DoStreamsHooks::theme()`) — the
  FIRST time this codebase registers a module-owned node view-mode
  suggestion. Worth a look at Phase-7 anti-duplication/consistency gate:
  is this the right place for a "card partial" going forward, or should a
  future story extract a more general do_streams card-template convention?
  I did not extract one — this issue owns exactly one card shape.
- **`?scope=global` via `overrideOption('filters', ...)`** — first use of
  this exact core Views API in this codebase (A flagged this as new-to-code
  in advisory #3). Verified it does NOT touch the shipped view config file,
  only the in-memory `ViewExecutable` instance for that one request.
- **RSVP chip cache metadata** (`user` context + `flagging_list:node:<nid>`
  tag) is attached both on the individual chip render array AND (separately)
  invalidated via `entity_insert`/`entity_delete` hooks scoped to
  `rsvp_event` flaggings only — mirrors the EXISTING `pin_in_group`
  cache-invalidation pattern in the same class (`onFlaggingChange()`), now
  joined by a sibling `onRsvpFlaggingChange()`.
- **No new dependency injection issues**: `DoStreamsHooks` remains
  `autowire: false` (a `hook_implementations`-tagged service), so the new
  `\Drupal::service('flag')` / `\Drupal::entityTypeManager()` /
  `\Drupal::currentUser()` calls in the new chip-building methods follow
  the EXACT same documented constraint `do_notifications`'s own
  `commentInsert()` hook already established ("FlagServiceInterface cannot
  be DI-injected on hook_implementations services") — not a new pattern,
  consistent application of an existing one.

## Deviations from spec / wireframe

- **Theme-hook-suggestion registration** (documented above) — not spec'd,
  discovered as a load-bearing gap during implementation. No visual
  deviation from the approved wireframe; the fix is purely about WHERE the
  markup comes from, not what it looks like.
- **`flagging_uid` filter removed from the shipped view** (documented
  above) — a bug fix, not a design deviation; the acceptance criterion ("My
  RSVPs lists elena's three RSVPs") could not be met with the filter left
  in as T's fixture wrote it.
- No deviation from the approved wireframe's visual contract otherwise —
  every class name, testid, and copy string matches `wireframe.html`
  verbatim (confirmed via a live smoke test's rendered HTML, not just
  static code review).

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
bash scripts/ci/assemble-config.sh
```
Exits 0 for the copy steps (config + modules); the final `core.extension.yml`
patch step fails on the HOST only because bare `php` isn't on the host PATH
(DDEV-only PHP) — ran the same script via `ddev exec` for the patch step,
which succeeds. Not a defect in the script or my changes.

**phpcs** (`--standard=Drupal,DrupalPractice`, the correct explicit standard
— note: `php vendor/bin/phpcs` with NO `--standard` flag falls back to
PHPCS's own default (PSR2), not Drupal's, because no `default_standard` is
configured anywhere in this repo; I re-ran with the explicit flag once I
noticed the mismatch):
- `MyEventsController.php`: 0 errors, 0 warnings.
- `views.view.my_events.yml`, `do_streams.routing.yml`,
  `do_streams.libraries.yml`, the new template: 0 errors, 0 warnings.
- `DoStreamsHooks.php`: 0 errors; 8 warnings, all
  `\Drupal calls should be avoided in classes, use dependency injection
  instead` — 4 of these are PRE-EXISTING (confirmed via a byte-for-byte
  baseline diff against the file's state before my edit), the other 4 are
  new calls in my own added methods, following the SAME documented,
  necessary `hook_implementations`-service constraint the pre-existing 4
  already establish. Not fixed (this is the correct idiom here, not debt).
- `HelpText.php`: 26 violations (18 errors + 8 warnings), ALL at lines
  ≤178 — confirmed via line-number extraction that every single one is
  pre-existing (before my line-289+ append), zero introduced by my edit.
  Not fixed (out of scope — a >300-line file shared across 15+ prior
  stories, all matching this same non-Drupal-standard indentation
  convention; reformatting it whole would be an out-of-scope drive-by).

**Kernel (`MyEventsViewTest`):** 5/5 pass (`testUpcomingDisplayExecutesAndReturnsOnlyEventBundleNodes`
shows a pre-existing `flag.views_execution.inc` deprecation warning, not a
failure — matches T's own RED report's warning-marker convention for this
exact test).

**Functional (`MyEventsRouteTest`):** 4/4 pass.

**Full `do_streams` regression:** 30 kernel + 12 functional = 42 tests, all
pass, zero regressions (re-run twice, identical result both times:
"Tests: 69, Assertions: 1392" for the combined `do_streams` + `do_chrome`
run — deprecations/risky markers only, no failures/errors).

**Full `do_chrome` regression:** 27 tests, all pass (confirms the
`HelpText.php` append didn't break `HelpTextPageKeysTest`,
`MyFeedHelpTextTest`, `PageHelpRouteMapTest`, or any of the other 24 tests
in that module).

**Live smoke test (beyond the standard Tier-1 bar, done because the theme-
hook-suggestion gap above would NOT have surfaced from static code review
or from T's own kernel/functional assertions alone):** fresh
`drush site:install minimal` + `pm:enable do_streams do_discovery do_chrome
do_tests flag group gnode datetime` + manually seeded event/group/RSVP
fixtures + real HTTP session via a one-time login link. Confirmed:
`.event-card` markup renders (not the theme's generic node markup); RSVP
chip shows correct icon/text/count/data-attributes for both the
"not-going" and "going" viewer states; page title, iCal hrefs, and the
Global/My-Groups toggle all render with correct real routes; My RSVPs
correctly scopes per-viewing-user across two distinct test users, each
RSVP'd to a different event. Cleaned up all throwaway smoke-test scripts
afterward (none committed).

## Blocking finding for O (not something F can resolve)

**This branch's actual current HEAD (`836a884`) is missing ST-1's own
`do_streams` content** (`MyFeedController.php`, `css/my-feed.css`, the
`do_streams.my_feed` route, and the `my_feed` library entry), even though
the brief instructs "Stacked on `110-stream-110` (PR #173)" and my own
`MyEventsController`/routing/libraries edits were written as append-only
extensions assuming ST-1's content would already be present (mirroring it
in commentary, never hard-coupling to it in executable code — confirmed no
`use`/`extends`/class-reference to `MyFeedController` anywhere).

I traced this precisely rather than guessing:
- `origin/110-stream-110` (fetched directly, tip `c4ea97d`) DOES have the
  `my_feed` route/controller/CSS — ST-1's work is real and complete on its
  own branch.
- `origin/110-stream-110` is confirmed **NOT an ancestor** of this branch's
  current HEAD (`git merge-base --is-ancestor origin/110-stream-110 HEAD`
  → NO), despite the reflog showing an attempted
  `rebase (start): checkout origin/110-stream-110` followed by a
  `rebase (finish)` and then later `reset: moving to 01f49a5` — the reset
  appears to have discarded the rebased-in ST-1 commits before the `#112`
  commit (`836a884`) was made on top of the post-reset state.
- Separately, and likely the deeper root cause: **PR #173 (ST-1) has not
  been merged into `main` at all** — `origin/main`'s tip has no
  `docs/groups/modules/do_streams/` content whatsoever (`git show
  origin/main:docs/groups/modules/do_streams/do_streams.routing.yml` →
  "does not exist"). The brief's premise ("stacked on 110-stream-110 (PR
  #173)") describes the INTENDED stacking relationship, but that PR's own
  merge had not actually landed by the time this branch's history took its
  current shape.
- I did **not** attempt any git repair (no rebase, reset, or cherry-pick) —
  per the git-safety mandate, branch-topology surgery affecting another
  story's (ST-1's) commits is an operator/O decision, not F's to make
  unilaterally, especially since the affected commits are already pushed
  to a shared remote branch other work may depend on.
- **Practical consequence right now:** `/my-feed` (ST-1's own route) 404s
  on this branch as it currently stands; `MyFeedRouteTest.php` and
  `MyFeedNavLinkTest.php` do not even exist in the current working tree
  (confirmed via `find` — only `MyEventsRouteTest.php` is present under
  `tests/src/Functional/`). This issue's own #112 work (`my_events`) is
  fully self-contained and works correctly independent of this gap — but
  the branch as a whole is not currently a complete, mergeable superset of
  ST-1 + ST-3 as the brief intended.

**Recommended next step (O's call, not mine):** re-fetch/re-verify PR
#173's actual merge status, then either (a) properly re-rebase
`112-events-rsvps` onto the CURRENT `origin/110-stream-110` tip (or onto
`main` once #173 lands) to restore ST-1's content, or (b) if #173 is
intentionally still open, hold #112's own PR until #173 merges, per the
brief's own stated stacking order ("Rebase onto main before opening" —
brief.md's Objective section).

## Tests that look wrong (for T)

- **`tests/fixtures/config/views.view.my_events.yml`'s `my_rsvps` display
  has the same broken `flagging_uid` filter** documented in "Design
  decisions" above (`plugin_id: user_current` on `table: flagging, field:
  uid` — silently ignored by Views' handler resolution in favor of the
  field's own `numeric` default, which then fatals with a scalar `value`).
  This does not currently fail any of T's own kernel assertions (none of
  them assert on this filter), but it means the fixture's structural
  contract is subtly wrong in a way that only surfaces at real query
  execution (which the kernel test's `testMyRsvpsDisplayContract` never
  does — it only inspects the display's static `options` array). Recommend
  T remove the `flagging_uid` filter block from the fixture (matching my
  shipped-config fix) — the `rsvp_relationship`'s own `user_scope: current`
  join condition already scopes results to the current user correctly and
  sufficiently; T could also add a kernel/functional assertion that
  executes the `my_rsvps` display with a real flagging and confirms exactly
  one row for the flagging user (not zero, not other users' flaggings) —
  this specific defect would have been caught immediately by such an
  assertion.

## Known issues

None beyond the blocking branch-topology finding above (which is an
environment/history issue, not a defect in this issue's own deliverables).

## Files changed

- `docs/groups/config/views.view.my_events.yml` (new)
- `docs/groups/modules/do_streams/src/Controller/MyEventsController.php` (new)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (extend)
- `docs/groups/modules/do_streams/templates/node--event--stream-card.html.twig` (new)
- `docs/groups/modules/do_streams/do_streams.routing.yml` (extend — see
  Blocking finding for the topology caveat on what else this file should
  also contain)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (extend — same
  caveat)
- `docs/groups/modules/do_streams/css/events.css` (new)
- `docs/groups/modules/do_chrome/src/HelpText.php` (append-only)
