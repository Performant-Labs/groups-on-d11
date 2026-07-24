# Handoff-T-red: Phase 4 - #193 SD-4 tooltip consumers (chrome.stream_switcher)

**Date:** 2026-07-24
**Branch:** 193-sd4-tooltip-consumers
**Brief / wireframe reviewed:** Issue #193 (triage-corrected scope, coordinator-approved "Option A")

## A precondition

Confirmed: Architecture review passed the plan with the scope correction — the issue's original
list of 6 `stream.card.*` keys does not exist in `HelpText.php` at all (verified directly against
`HelpText::all()` before authoring any test — there is no `stream.card.*` namespace in the file).
The only key in the real `HelpText::all()` with a documented, verified-absent consumer is
`chrome.stream_switcher` (`docs/groups/modules/do_chrome/src/HelpText.php:420`) — its own docblock
(lines 404-419) says so explicitly: "No consuming markup is wired to this key YET ... #131 (SD-4)
is the explicit, already-planned backstop that sweeps EVERY #108 surface's element-level tooltips,
including wiring a consumer for this key."

## Tests authored

**File:** `docs/groups/modules/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php` (new)

Both tests are Kernel tests (module `do_chrome`, `KernelTestBase` + `#[RunTestsInSeparateProcesses]`,
modeled on the existing `HelpTextStreamKeysTest.php`) because they need the real `do_chrome` module
autoloaded from the assembled tree, but exercise no Drupal service beyond `HelpText`'s plain
static methods plus filesystem scanning — a Kernel test is the cheapest sufficient tier that
guarantees the class loads from the actual installed/assembled module (a PHPUnit `Unit` test would
work too, but the project's existing sibling test — `HelpTextStreamKeysTest` — sets the Kernel
precedent for this exact class, so this test follows suit for consistency).

1. **`testChromeStreamSwitcherHasConsumer()`**
   Pins the acceptance criterion: `chrome.stream_switcher` must have at least one real consumer
   wiring it into a `data-do-tooltip` trigger. Asserts the key's copy is non-empty (guard), then
   asserts `keyHasConsumer()` finds the literal key string (or its literal copy) in a production
   file under `docs/groups/` or `web/themes/custom/groups_chrome/`, excluding test files and
   `HelpText.php` itself.

2. **`testEveryHelpTextKeyHasAConsumer()`**
   Regression sweep: iterates every key in `HelpText::all()` and asserts each has a detectable
   production consumer, except a documented whitelist (see below). Guards against a *future* story
   adding an orphaned key silently. Both tests fail today for the identical, singular reason:
   `chrome.stream_switcher` has no consumer.

### Detection strategy and why (important context for F and for reviewing this test)

The scanner does **not** try to pattern-match `HelpText::get('<key>')` call syntax. During
authoring, the codebase turned out to use at least **five distinct indirection styles** for
resolving a `HelpText` key at a call site:

1. Direct call: `HelpText::get('exact.key')`.
2. Inline concatenation: `HelpText::get('namespace.' . $entry['id'])`.
3. Concatenation via an intermediate variable: `$tooltip_key = 'namespace.' . $id; ...
   HelpText::get($tooltip_key)` (`VariantSwitcher::build()`).
4. A class-constant lookup map: `VisibilityTooltip::OPTION_COPY_KEYS = ['open' =>
   'visibility.open', ...]`, then `HelpText::get($map[$option])`.
5. **Two-part-literal concatenation across two different files** — the namespace prefix is
   baked into a shared helper (`VariantSwitcher::build()`, `ShowcaseController::page()`) and the
   per-instance/per-entry id is a *separate* literal string supplied at each call site
   (`ShowcaseController.php:229` passes `'directory.layout'`; `ShowcaseCatalog.php:40` defines
   `'id' => 'discovery-ranking'`). **No single file ever contains the full key as one literal
   string** in this style.

Trying to regex-match every one of these styles is an unbounded arms race with the code, not a
stable contract. The chosen strategy: search for the key's **full literal quoted string** (e.g.
`'chrome.stream_switcher'`) anywhere in a production file — this catches styles 1-4 without
needing their exact syntax. Style 5 cannot be caught this way (by construction — the full string
never appears in one place), so those specific keys are whitelisted with a comment pointing at the
manually-verified call sites (see `whitelistedKeys()` Category 1 in the test file).

Two source locations are scanned:
- `docs/groups/` — the primary do_* module source tree.
- `web/themes/custom/groups_chrome/` — discovered during authoring: this theme is **git-tracked**
  (NOT a build artifact like the gitignored `web/modules/custom/` — verified `git ls-files
  web/themes/custom/groups_chrome` returns 19 tracked files), and `HelpText.php`'s own #127
  docblock says the `card.*` keys are consumed via `groups_chrome`'s preprocess functions
  (verified directly: `groups_chrome.theme` calls `HelpText::get('card.stream.byline')` /
  `HelpText::get('card.directory.type')`).

Test files are excluded from the scan entirely (`->exclude('tests')` on the Finder). This was
discovered to be necessary during authoring: `HelpTextTest.php` (a **unit test**, not a
production consumer) directly calls `HelpText::get('privacy.unlisted')` /
`HelpText::get('privacy.vs_invite_only')` to assert their copy content — counting that as a
"consumer" masked a real, pre-existing gap (those two keys, plus `privacy.public` /
`privacy.private`, have **no production tooltip trigger anywhere**).

### Whitelist (`whitelistedKeys()`), two categories, both out of #193's scope

**Category 1 — genuinely wired, undetectable via literal-string search (style 5 above), manually
verified by reading the consuming code:**
- `showcase.switcher.directory.layout`, `showcase.switcher.discovery.ranking`,
  `showcase.switcher.stream.model` — `VariantSwitcher::build($instance_id, ...)` concatenates
  `'showcase.switcher.' . $instance_id`; each instance id is a separate literal at
  `ShowcaseController.php:229/359`, `DoShowcaseHooks.php:510`, `ModelToggleHooks.php:215`.
- `showcase_help.discovery-ranking`, `showcase_help.directory-presentation`,
  `showcase_help.membership-models`, `showcase_help.group-type-homepages`,
  `showcase_help.stream-model`, `showcase_help.private-group-reveal`,
  `showcase_help.persona-switcher` — `ShowcaseController::page()` does `HelpText::get(
  'showcase_help.' . $entry['id'])`; each `id` is a separate literal in
  `ShowcaseCatalog::all()`.

**Category 2 — genuinely NOT wired anywhere in production, pre-existing, out of #193's scope
(documented so this sweep doesn't silently hide them, but doesn't fail #193 for gaps #193 wasn't
asked to close):**
- `stream.my_feed` — `HelpText.php`'s own docblock (lines 329-346) says this key is "reserved for
  the shared do_streams_shell's own 'My Feed' scope-tab tooltip ... this story ships only the
  copy entry itself." Verified: no `do_streams` file references it.
- `privacy.public`, `privacy.private`, `privacy.unlisted`, `privacy.vs_invite_only` — the #134
  (SC-7) privacy axis. Verified via full-repo search (tests + `HelpText.php` excluded): no
  production consumer anywhere. `do_group_extras` (the owning module) has no `do_chrome`
  dependency and wires no tooltip trigger for this field today.
- `page.my_feed`, `page.following`, `page.trending`, `page.my_feed_events`,
  `page.profile_stream` — #126 SD-1's 5 W2 pre-registered `page.*` keys; their routes don't exist
  yet (`HelpText.php` lines 251-253).
- `stream.my_feed.empty`, `stream.my_feed_events.rsvp_chip`, `stream.activity_row.social`,
  `stream.activity_row.aggregated`, `stream.activity_row.comment`, `stream.model_toggle` — #131
  SD-4's own docblock (lines 358-377) says these are wired by SIBLING wave stories
  (#112-#115, #129, #130) into their own host templates, out of #193's do_chrome-only scope.
- `profile_activity.section` — wired by a sibling module (`do_activity_feed`), out of scope.
- `page.activity` — its `PageHelp::getRouteMap()` wiring is explicitly deferred to SD-6 (#133),
  per `HelpText.php`'s own docblock (lines 424-430).

## RED confirmation

**Environment note:** this worktree's `.ddev/config.yaml` had a stale `name:` (copied from another
worktree, `gm145-wcag`), which collided with an already-running ddev project. Renamed to
`gm193-tooltipcons` (local dev-environment change only, not staged/committed) so `ddev start` could
bring up an independent container. `composer install` had not been run in this worktree; ran via
`ddev composer install`. PHPUnit also needed `SIMPLETEST_DB` / `SIMPLETEST_BASE_URL` env vars
(matching `.github/workflows/test.yml`'s CI values, pointed at ddev's own `db` service) — not set
by default in the ddev container.

**Commands to reproduce (from the worktree root, run inside the ddev web container):**

```bash
ddev exec bash scripts/ci/assemble-config.sh
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SIMPLETEST_BASE_URL="http://localhost" \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php'
```

**Exact failing output:**

```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.22
Configuration: /var/www/html/web/core/phpunit.xml.dist

FF                                                                  2 / 2 (100%)

Time: 00:04.839, Memory: 10.00 MB

Help Text Consumer Coverage (Drupal\Tests\do_chrome\Kernel\HelpTextConsumerCoverage)
 ✘ Chrome stream switcher has consumer
   │
   │ "chrome.stream_switcher" must have at least one PRODUCTION consumer — its literal key
   │ string must appear in a wiring call site (docs/groups/ or web/themes/custom/groups_chrome/),
   │ or its literal copy must be rendered as a data-do-tooltip value. None found (test files and
   │ HelpText.php excluded from the scan).
   │ Failed asserting that false is true.
   │
   │ /var/www/html/web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php:326
   │
 ✘ Every help text key has a consumer
   │
   │ The following HelpText::all() keys have no PRODUCTION consumer and are not in the
   │ whitelist: chrome.stream_switcher
   │ Failed asserting that two arrays are identical.
   │ --- Expected
   │ +++ Actual
   │ @@ @@
   │ -Array &0 []
   │ +Array &0 [
   │ +    0 => 'chrome.stream_switcher',
   │ +]
   │
   │ /var/www/html/web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php:362
   │

FAILURES!
Tests: 2, Assertions: 36, Failures: 2.
```

**Both failures are for the identical, correct reason:** `chrome.stream_switcher` has zero
production consumers. No import error, no setup error, no unrelated key flagged — every other key
in `HelpText::all()` (36 assertions covering keys + whitelist checks) already resolves cleanly.

**Sanity check that this is a valid, behavior-pinned RED (not a tautology):** temporarily appended
a throwaway `{# 'chrome.stream_switcher' sanity-check placeholder #}` comment line to
`docs/groups/modules/do_streams/templates/stream-switcher.html.twig`, re-assembled, and re-ran —
both tests went GREEN (`OK (2 tests, 36 assertions)`), confirming the detection logic actually
fires on a real consumer appearing. Reverted immediately via `git checkout --` (confirmed clean:
`git status --short` shows no diff on that file). No production file was left modified by this
verification step.

## Ready for F

Confirmed RED is valid — both tests fail for the single, correct reason (chrome.stream_switcher
has no consumer), and the sanity check confirms the tests actually detect a real fix when one is
present. F may implement against these tests.

**F's task (do NOT touch as T):** wire a `data-do-tooltip` consumer for `chrome.stream_switcher`
into `StreamSwitcherHooks.php` / `stream-switcher.html.twig` (per `HelpText.php`'s own docblock
pointer, lines 404-419) — e.g. render `HelpText::get('chrome.stream_switcher')` as a
`data-do-tooltip` attribute on the switcher's chrome wrapper, matching the existing SD-pattern
`data-do-tooltip` + `do-chrome-info` span markup used elsewhere in `do_chrome`.
