# Handoff-F: Rework round 2 - do_activity_feed (fix S's Phase 9 REWORK defects)

**Date:** 2026-07-23
**Branch:** 129-activity-feed-render
**Issue:** #129

## What was done

Fixed both production defects S reported at Phase 9 (see `docs/planning/handoffs/129-activity-feed/decisions.md`'s "S — Phase 9 (spec audit) — REWORK" entry), and added the two regression tests O's routing decision assigned to F for this rework round.

- `docs/groups/modules/do_activity_feed/src/Controller/ActivityFeedController.php` (modified) —
  `buildAggregatedRow()` now captures `actor_url`/`group_url` from each bucket member's row via the
  same `??=` pattern already used for `actor`/`group` (one line below), and adds both to the returned
  'aggregated' row array. Fixes Defect 1: the returned array was previously missing both keys
  entirely, so `{{ row.actor_url }}`/`{{ row.group_url }}` in the aggregated-row template rendered
  empty strings.
- `docs/groups/modules/do_activity_feed/templates/activity-feed.html.twig` (modified) — the `<ol>`
  loop now prints `{{ row }}` bare, with no wrapping `<li>` of its own (option (a) per the routing
  instructions). Fixes Defect 2 at the shell level.
- `docs/groups/modules/do_activity_feed/templates/activity-row--content.html.twig` (modified) — root
  element changed from `<article class="activity-row--content">` to
  `<li class="activity-row--content">` (inner `<article class="card">` unchanged). This addresses a
  premise gap in the routing instructions: they stated "every row template is already `<li>`," but
  the content-card template was actually rooted on `<article>`. Confirmed against the approved
  wireframe (`wireframe.html` L324) that content rows are ALSO meant to root on `<li>`, with
  `<article class="card">` nested one level inside — so this is the fix that makes the shell change
  in (a) actually valid (otherwise, with the shell's `<li>` wrapper removed, a bare `<article>` would
  be left as a non-`<li>` direct child of `<ol>`).
- `docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedRowRenderTest.php` (modified,
  **F's own file from round 1, extended per this round's explicit O routing exception** — see
  "Staging note" below) — added 2 new test methods:
  - `testAggregatedRowFromRealControllerHasNonEmptyActorAndGroupHrefs()` — the direct regression for
    Defect 1. Drives `ActivityFeedController::renderFeed('my_groups')` for real (the same entry point
    a live request uses), not a hand-built row array — asserts the controller's own returned
    aggregated row has non-empty `actor_url`/`group_url`, then renders that exact row through the real
    Twig theme hook and asserts non-empty rendered `href` attributes.
  - `testFeedShellNeverProducesNestedListItems()` — the structural regression for Defect 2. Renders
    the FULL `#theme => activity_feed` shell (one row of each shape, via the real controller) and
    asserts no `<li>` is immediately followed by another `<li>`, plus exactly 3 row-root
    `<li data-testid="activity-row-*">` elements (counted by testid rather than raw `<li` count, since
    the aggregated row's own `<details>` children list legitimately contains further plain `<li>`
    elements that are correct markup, not a symptom of either defect).
  - Added a small `pruneHookNoiseMessages()` helper to this class (duplicated from
    `ActivityFeedRenderTest`'s own `protected` method of the same name, since that method lives on a
    sibling test class, not the shared base — mirrors the existing precedent exactly rather than
    adding a cross-test-class dependency).

## Design decisions

- **Defect 1 fixed by mirroring the EXISTING `??=` pattern, not inventing a new one** — `$actor` and
  `$group` were already captured from each member's `buildRow()` output via
  `$actor ??= $memberRow['actor']`; the fix adds the parallel `$actorUrl ??= $memberRow['actor_url']`
  and `$groupUrl ??= $memberRow['group_url']` immediately alongside them, and both are added to the
  returned array. Purely additive — no existing key removed, no method signature changed.
- **Defect 2: chose the routing instructions' recommended option (a)** (shell prints `{{ row }}` bare)
  rather than option (b) (rows switch to `<div>/<article>`, shell keeps its `<li>`). Option (a) is the
  smaller diff and matches the wireframe's stated intent of an `<ol>` of pre-emitted `<li>` rows.
- **Discovered the routing note's premise was inaccurate for one of the three row templates, and
  fixed the underlying issue rather than just following the letter of the instruction.** The routing
  instructions asserted "every row template is already `<li>`, verify this" — on verification,
  `activity-row--content.html.twig` was actually rooted on `<article>`. Rather than leave a bare
  `<article>` as an invalid direct `<ol>` child once the shell's `<li>` wrapper was removed, I checked
  the approved wireframe directly (`wireframe.html` L324:
  `<li class="activity-row--content"><article class="card">...`) and confirmed content rows were
  ALWAYS meant to be `<li>`-rooted, with the `<article class="card">` nested one level inside — so I
  changed the outer tag from `<article>` to `<li>`, keeping the inner `<article class="card">`
  untouched. This is a one-tag-name rename, not a restructuring — the `.activity-row--content .card`
  CSS descendant selector (the only CSS rule targeting this class) is a descendant selector and
  matches regardless of the outer tag, so there is zero visual-regression risk.
- **Did not touch `do_activity_feed.install`, `ActivityRowBuilder::buildRow()`,
  `activity-row--social.html.twig`, or `activity-row--aggregated.html.twig`** — none of round 1's
  fixes for U's Phase 8 defects needed any change for these two new defects; those templates were
  already correctly `<li>`-rooted and already correctly received `actor_url`/`group_url` for their
  OWN (non-aggregated) row builds via `ActivityRowBuilder::buildRow()`. This round's bug was narrowly
  scoped to the controller's separate aggregation-row-construction code path
  (`buildAggregatedRow()`), which does not call `buildRow()` for the aggregate itself (only for each
  underlying member), and to one template's root tag.
- **Regression test counts the shell's row-root `<li>`s by `data-testid` attribute, not a raw `<li`
  tag count** — my first draft of `testFeedShellNeverProducesNestedListItems()` asserted a flat count
  of 3 raw `<li` tags for a 3-row fixture and failed (actual: 5), because I had not accounted for the
  aggregated row's own `<details>` disclosure containing 2 further plain `<li>` elements for its
  `children` list (correct, expected markup per `activity-row--aggregated.html.twig`, unrelated to
  either defect). Fixed by scoping the count to `<li ... data-testid="activity-row-...">`, which
  uniquely identifies row-root elements and correctly excludes the aggregate's own child `<li>`s.

## Reuse / extend-vs-new

No new objects created this round — both fixes extend existing files
(`ActivityFeedController::buildAggregatedRow()`, `activity-feed.html.twig`,
`activity-row--content.html.twig`) and the one new test coverage extends F's own existing
`ActivityFeedRowRenderTest.php` file from round 1 (adding 2 methods + 1 helper), rather than creating
a new test class.

## Architecture notes for A

- **No new dependency edges, no schema changes, no service-signature changes.** The aggregated row
  array gained two new keys (`actor_url`, `group_url`) — purely additive, mirroring exactly what round
  1 already did for `ActivityRowBuilder::buildRow()`'s return array. No T-authored kernel test asserts
  an exact key set on the returned row array (confirmed: all use point-lookups like `$row['type']`),
  so this is safe.
- **`activity-row--content.html.twig`'s root tag rename (`<article>` → `<li>`) is template-only** — no
  controller/service call changed as a result. The template's own inner structure
  (`<article class="card"><p class="card__meta">...{{ row.card }}</article>`) is completely unchanged;
  only the outermost wrapping tag's name changed, with its `class`/`data-testid` attributes carried
  over unchanged.
- **`activity-feed.html.twig`'s change removes one level of markup, not any variable/logic** — the
  `{% for row in rows %}` loop body changed from `<li>{{ row }}</li>` to `{{ row }}`; no other part of
  the shell template changed.

## Deviations from spec / wireframe

None — both fixes bring the implementation INTO alignment with the approved wireframe (specifically,
`activity-row--content.html.twig`'s `<li>` root now matches `wireframe.html` L324 exactly, which it
previously did not).

## Tier 1 self-check (incl. tests now GREEN)

**Assemble:**
```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 128 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag as enabled
==> assemble-config: done
```

**phpcs** (production files only):
```
$ ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install,twig \
    docs/groups/modules/do_activity_feed/src/Controller/ActivityFeedController.php \
    docs/groups/modules/do_activity_feed/templates/activity-feed.html.twig \
    docs/groups/modules/do_activity_feed/templates/activity-row--content.html.twig
(no output; EXIT: 0)
```
0 errors, 0 warnings on all 3 modified production files.

**Kernel suite** (`do_activity_feed`, all 19 tests, including my 2 new test methods):
```
$ ddev exec "SIMPLETEST_DB='mysql://db:db@db/db#kt' php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
    --testdox web/modules/custom/do_activity_feed/tests/src/Kernel"
Tests: 19, Assertions: 586, Deprecations: 30 (pre-existing noise, unrelated to this change).
```
19/19 GREEN, run twice consecutively for determinism (both clean, exit 0 both times). Isolated
`ActivityFeedRowRenderTest.php` run alone: 5/5 clean (Tests: 5, Assertions: 177).

**Regression — sibling modules unaffected:**
```
do_activity:          23/23 GREEN
do_streams:            25/25 GREEN
do_group_membership:   26/26 GREEN
(combined: Tests: 74, Assertions: 2199, 0 failures)
```
Unchanged from round 1's baseline.

**Live verification** (the actual acceptance bar for this rework round):
- `ddev drush cr` — cache cleared.
- `/activity` as elena_garcia (curl with a real one-time-login session cookie): **HTTP 200**, 35 rows.
  Inside `<ol class="activity-feed__list">`: **0** occurrences of `href=""`, **0** occurrences of the
  `<li><li` nested pattern, exactly 35 row-root `<li>` elements (one per row), 48 total `<li>`
  open/close tags (35 row roots + 13 aggregated-child `<li>`s across the page's 5 aggregated rows —
  correct, expected markup).
- `/activity/group/6` as elena_garcia: **HTTP 200**, 7 rows, 0 empty hrefs, 0 nesting.
- `/activity` anonymous: **HTTP 200**.
- `ddev drush watchdog:show` after all of the above: zero new PHP errors (last error entry, id 104,
  predates this session — the only new entries are benign `user`/Info one-time-login records from my
  own verification requests).
- The one stray `href=""` found in the raw page HTML (outside the `<ol>`) was an unrelated
  `<li class="action-links-item"><a href="" class="button button-action">Add</a></li>` — a core
  action-link element, nothing to do with `do_activity_feed`'s own row templates.

## Tests that look wrong (for T)

None new this round. The pre-existing flake (`ActivityFeedRenderTest::testContentRowOmittedWhenNodeNotViewable`,
flagged in round 1's handoff) did not trigger in either of this round's 2 consecutive full-suite runs
— consistent with its previously-documented ~1-in-5 non-deterministic rate. Not touched.

## Known issues

None beyond the pre-existing flaky test noted above (unrelated to this round's two fixes). Both
S-reported defects are fixed and live-verified.

## Staging note

Per my mandate I stage no test files — the 3 production files (`ActivityFeedController.php`,
`activity-feed.html.twig`, `activity-row--content.html.twig`) are staged by explicit path. The test
file (`ActivityFeedRowRenderTest.php`) — a pre-existing file from round 1 that O's routing decision
for THIS round again explicitly assigned F to extend (an exception to F's normal no-test-authoring
rule) — is left unstaged, consistent with round 1's precedent: staging decisions on F-authored test
content are left to the operator/T rather than presumed.

## Files changed

- `docs/groups/modules/do_activity_feed/src/Controller/ActivityFeedController.php` (modified)
- `docs/groups/modules/do_activity_feed/templates/activity-feed.html.twig` (modified)
- `docs/groups/modules/do_activity_feed/templates/activity-row--content.html.twig` (modified)

Test file (extended per this round's specific O routing exception, left unstaged — see "Staging
note"): `docs/groups/modules/do_activity_feed/tests/src/Kernel/ActivityFeedRowRenderTest.php`.
