# Handoff-F: Phase 5 - #193 SD-4 tooltip consumers (chrome.stream_switcher)

**Date:** 2026-07-24
**Branch:** 193-sd4-tooltip-consumers
**Issue:** #193

## What was done

- `docs/groups/modules/do_streams/do_streams.info.yml` — added `do_chrome:do_chrome` under
  `dependencies` (the dependency `HelpText.php`'s own docblock, lines 414-415, explicitly noted as
  missing).
- `docs/groups/modules/do_streams/src/Hook/StreamSwitcherHooks.php` —
  `preprocessViewsView()` now resolves `HelpText::get('chrome.stream_switcher')` and sets it as
  `#switcher_help_copy` on the `#theme => stream_switcher` render array it prepends to the view's
  header. No library attach added (see Design decisions).
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — `theme()`'s `stream_switcher`
  entry's `variables` declaration gains `switcher_help_copy => ''` (required for the `#`-prefixed
  render-array property to survive `ThemeManager::render()` into the template), plus one new
  docblock paragraph documenting it (see Known issues re: a self-caught authoring slip while
  making this edit).
- `docs/groups/modules/do_streams/templates/stream-switcher.html.twig` — renders a ⓘ info-trigger
  span after the tab list, guarded by `{% if switcher_help_copy %}`, using the exact
  `do-chrome-info` + `tabindex="0"` + `role="note"` + `aria-label` + `data-do-tooltip` shape
  `PageHelp::infoTrigger()` establishes elsewhere in `do_chrome`.

## Design decisions

1. **Extend the existing trio, no new class/template.** T's handoff and `HelpText.php`'s own
   #115 docblock both name the exact consumer surface (`StreamSwitcherHooks` /
   `stream-switcher.html.twig`) — implemented there verbatim, no parallel path.

2. **Matched `PageHelp::infoTrigger()`'s markup shape exactly**, rather than inventing a new
   tooltip-trigger shape. Read `PageHelp.php` and `GroupTypeContentHelp.php` first; both already
   establish `do-chrome-info` span + `tabindex="0"` + `role="note"` + `aria-label` + `data-do-tooltip`
   + the U+24D8 "ⓘ" glyph as the project's own documented convention ("every existing B-story
   surface ships its own trivial `infoTrigger()`... matching that established convention beats a
   cross-cutting refactor"). This story follows the same convention rather than extracting a
   shared helper, consistent with that stated precedent.

3. **No new library attach.** Read `DoChromeHooks::pageAttachments()` directly (not assumed) and
   confirmed `$attachments['#attached']['library'][] = 'do_chrome/tooltips'` is unconditional —
   attached on every page request, globally. Attaching it again per-view in
   `StreamSwitcherHooks::preprocessViewsView()` would be redundant, and more importantly would
   risk breaking the pre-existing
   `StreamSwitcherHooksTest::testPreprocessViewsViewIsNoOpForGroupContentStream()` assertion that
   a non-switcher view gets **no** `#attached.library` key at all — verified this test still
   passes unmodified (see Tier 1 self-check below).

4. **Plain string, not a render array, for `switcher_help_copy`.** `HelpText::get()` already
   returns a plain string; the template only drops it into two attribute values (`aria-label`,
   `data-do-tooltip`). A render array would add indirection with zero benefit — the existing
   `PageHelp::infoTrigger()` precedent itself builds a plain `#value`/`#attributes` array, not a
   nested structure.

5. **Fail-safe empty-copy guard.** `{% if switcher_help_copy %}` means an unexpectedly
   empty/missing HelpText entry silently omits the ⓘ trigger entirely — never an empty-but-present
   affordance, never a fatal. Matches this module's own established defensive-degradation pattern
   (`DoStreamsHooks::preprocessNodeEventStreamCard()`'s date/group-badge guards, which the class's
   own docblocks document explicitly).

6. **New theme-hook variable registered on `DoStreamsHooks::theme()`, not on
   `StreamSwitcherHooks`.** The `stream_switcher` theme hook's `#[Hook('theme')]` registration is
   already consolidated on `DoStreamsHooks` per this module's own documented
   `LogicException`-avoidance history ("Module do_streams should not implement theme more than
   once" — confirmed via a prior kernel-test regression, per that method's own docblock). Adding a
   second `theme()` method anywhere in this module, including on `StreamSwitcherHooks`, would
   immediately reintroduce that exact bug.

## Reuse / extend-vs-new

Extended the existing `StreamSwitcherHooks` class, `stream-switcher.html.twig` template, and
`DoStreamsHooks::theme()` registration — exactly the objects T's handoff and `HelpText.php`'s own
docblock (lines 404-419) name as the intended, already-planned consumer surface for this key. No
new class, hook, template, or library was created. The trigger *markup* itself duplicates the tiny
`infoTrigger()` shape (rather than extracting one shared helper across `do_chrome`/`do_streams`),
which matches this codebase's own pre-existing, explicitly-documented convention (see
`PageHelp.php`'s docblock: "this duplicates that tiny private method rather than extracting a
shared helper... matching that established convention beats a cross-cutting refactor outside this
story's scope").

## Architecture notes for A

- **Layers touched:** module dependency manifest (`do_streams.info.yml`), one hook-implementation
  class (`StreamSwitcherHooks`), one shared hook-registration class (`DoStreamsHooks::theme()`),
  one twig template. No new service, route, schema, or config entity.
- **New cross-module dependency:** `do_streams` now depends on `do_chrome` (previously it had
  none) — this is the dependency `HelpText.php`'s own docblock already anticipated and named as
  the reason no consumer existed yet ("do_streams has no existing dependency on do_chrome;
  introducing one plus a live tooltip trigger is a second decision beyond this story's own
  scope" — that "second decision" is exactly what #193 authorizes and this change makes).
- **Shared file edited:** `DoStreamsHooks.php` is a shared hub file (many hooks for many prior
  stories live in it). The final diff touches only the `theme()` method's docblock (one appended
  paragraph) and its `stream_switcher` sub-array (one new key, `switcher_help_copy => ''`); every
  other hook implementation in that file is byte-for-byte unchanged (confirmed via `git diff HEAD`
  on the final state — 11 insertions, 0 deletions, both localized to that one method). Verified via
  `StreamsShellTest`/`StreamsInstallTest` (sanity-check run, not required by the task) — both
  suites, which also depend on this file, remain fully GREEN.
- **No local pattern deviation.** Everything above follows an already-established, already-
  documented convention in this codebase (info-trigger shape, theme-variable declaration
  requirement, global-tooltip-library-already-attached fact, view-id-guarded preprocess
  delegation) — none of it required inventing a new pattern.

## Deviations from spec / wireframe

None. This story has no UI wireframe of its own to deviate from (the issue is a copy/consumer-
wiring bug fix, not a new UI surface) — the exact markup shape it introduces is itself pinned to
an existing, already-approved pattern (`PageHelp::infoTrigger()`), not a new design.

## Tier 1 self-check (incl. tests now GREEN)

**Assemble config** (inside ddev, per T's handoff — host `php` is not on PATH in this environment):

```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 138 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
==> assemble-config: done
```

**Target test — `HelpTextConsumerCoverageTest.php` — GREEN (final, post-fix run):**

```
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SIMPLETEST_BASE_URL="http://localhost" \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php'

PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
..                                                                  2 / 2 (100%)
Time: 00:02.106, Memory: 10.00 MB

Help Text Consumer Coverage (Drupal\Tests\do_chrome\Kernel\HelpTextConsumerCoverage)
 ✔ Chrome stream switcher has consumer
 ✔ Every help text key has a consumer

OK (2 tests, 36 assertions)
```

**Regression check — `StreamSwitcherHooksTest.php` (pre-existing) — GREEN (final, post-fix run):**

```
$ ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" SIMPLETEST_BASE_URL="http://localhost" \
    php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_streams/tests/src/Kernel/StreamSwitcherHooksTest.php'

DDDDDDD                                                             7 / 7 (100%)
Time: [~30s], Memory: 10.00 MB

Stream Switcher Hooks (Drupal\Tests\do_streams\Kernel\StreamSwitcherHooks)
 ✔ Scope registry returns four scopes in order with labels
 ✔ Tab list omits scopes without an existing route for authenticated user
 ✔ Tab list omits non allowlisted scopes for anonymous user
 ✔ Active tab flag matches current route path
 ✔ Active tab flag defaults to global on stream path
 ✔ Preprocess views view attaches switcher on activity stream
 ✔ Preprocess views view is no op for group content stream

7 tests triggered 12 deprecations:
OK, but there were issues!
Tests: 7, Assertions: 213, Deprecations: 12, PHPUnit Deprecations: 8.
```

(All 12 deprecation notices are pre-existing core/Views/annotation deprecations — missing
`#[RunTestsInSeparateProcesses]` on this test class, `@ViewsSort` annotation deprecation, etc. —
none reference `do_streams`' own production code and none are new; verified by reading the full
deprecation list, not merely the summary line.)

**Extra sanity check (not required by the task) — `StreamsShellTest.php` +
`StreamsInstallTest.php`, both of which also read `DoStreamsHooks::theme()` — GREEN:**

```
DDDDDDDD                                                            8 / 8 (100%)
Time: 00:21.493, Memory: 10.00 MB

Streams Install (Drupal\Tests\do_streams\Kernel\StreamsInstall)
 ⚠ Module installs with zero schema changes
 ⚠ Module uninstalls cleanly

Streams Shell (Drupal\Tests\do_streams\Kernel\StreamsShell)
 ✔ Scope tabs contract all four present with correct active flag
 ✔ Ranking control contract both pills present with correct active flag
 ✔ Trending scope does not disable the recent ranking pill
 ✔ Empty flag reflects result count
 ✔ Empty copy is distinct per scope
 ⚠ No hardcoded route paths in rendered tab markup

Tests: 8, Assertions: 197, Deprecations: 36, PHPUnit Deprecations: 10.
```

(`⚠` markers are deprecation-triggering-but-passing tests, not failures — all 8 tests pass;
deprecations are pre-existing core/geofield ones, unrelated to this change. Confirms the new
`do_chrome` dependency + `switcher_help_copy` theme-variable addition introduce zero schema drift.)

**phpcs — clean on 3 of 4 edited files; 1 pre-existing error on the 4th, verified NOT introduced
by this change:**

```
$ ddev exec php vendor/bin/phpcs --standard=Drupal \
    docs/groups/modules/do_streams/do_streams.info.yml \
    docs/groups/modules/do_streams/src/Hook/StreamSwitcherHooks.php \
    docs/groups/modules/do_streams/templates/stream-switcher.html.twig
(no output — exit code 0)

$ ddev exec php vendor/bin/phpcs --standard=Drupal \
    docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php
FILE: .../docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php
FOUND 1 ERROR AFFECTING 1 LINE
 644 | ERROR | Doc comment short description must be on a single line, further
     |       | text should be a separate paragraph
```

Verified this single error is **pre-existing, not introduced by this story**: checked out
`HEAD`'s (pre-#193) version of this exact file into place and ran the identical `phpcs
--standard=Drupal` command against it — same file, same line 644, same single error, before any
of this story's edits existed. It flags a pre-existing docblock (the "Registers the shared stream
shell theme hook..." comment, originally authored for #110/#111/#112) whose short-description
sentence happens to wrap across its first two lines. My `#193` change appends a new paragraph
inside that same, already-imperfect docblock (see Known issues below for the authoring detail) but
does not create this particular sniff violation — it was already there.

**Important caveat on the bare `phpcs` invocation** (task instructions specified `php vendor/bin/phpcs
docs/groups/modules/do_streams/ docs/groups/modules/do_chrome/`, no `--standard` flag): that
invocation reports thousands of errors across **every file in both modules**, including files this
story never touched (e.g. `HelpText.php`: 408 errors, `MyEventsController.php`: 317 errors,
`PermissionMatrix.php`: 66 errors — pre-existing files, untouched by #193). Investigated before
assuming my diff was the cause:

- `ddev exec php vendor/bin/phpcs --config-show` shows no `default_standard` pinned, and no
  `phpcs.xml`/`.phpcs.xml.dist` exists at the repo root.
- Without an explicit `--standard=` flag or a ruleset file, PHP_CodeSniffer falls back to its own
  built-in default (PEAR — 85-char line limit, strict multi-line-call indentation), which is
  **not** the standard this project is actually written to.
- Running the identical two-module sweep with `--standard=Drupal` explicit drops the count from
  6189 errors / 423 warnings (default/PEAR) to 139 errors / 51 warnings (Drupal) — none of the
  edited files' new content is among the remaining 139/51 (only the one pre-existing line-644
  error above, isolated and confirmed pre-existing).
- Conclusion: the bare-invocation flood is a pre-existing tooling/environment gap in the repo (no
  committed ruleset pinning `--standard=Drupal`), not a regression introduced by this diff.

## Tests that look wrong (for T)

None. Both authored tests in `HelpTextConsumerCoverageTest.php` are correct as written — they
detect the fix precisely as designed (literal key string `'chrome.stream_switcher'` now appears in
`StreamSwitcherHooks.php` via the `HelpText::get('chrome.stream_switcher')` call, independently
satisfying the detection strategy) and both went GREEN with no test edits needed.

## Known issues

None outstanding. One self-caught-and-fixed authoring slip during this phase, disclosed for
transparency:

My first pass at `DoStreamsHooks.php` (using a full-file rewrite, since no line-level edit tool was
available in this session) accidentally **deleted** a large pre-existing docblock — the file has
two back-to-back doc-comments immediately above `#[Hook('theme')]` (an existing quirk: only the
second one is the method's real PHPDoc; the first is effectively orphaned prose), and my rewrite
replaced the *second* one's content with my new `#193` note instead of appending to it, losing the
original documentation of `empty_cta`/`node__event__stream_card`/`suppress_default_chrome`.

Caught this during a routine `git diff --cached` review before finalizing (the diff showed ~54
lines of churn on a change that should have been ~10 lines — a "reasonableness of diff size" flag,
not a test failure). Fixed by restoring the original docblock content verbatim and appending my
`#193` paragraph inside it (matching the file's own established pattern of appending dated notes
to existing hook docblocks, e.g. the "Issue #112 (ST-3) follow-up" paragraph already there).
Re-verified: re-assembled config, re-ran both the target test and the regression suite (both still
GREEN, see Tier 1 self-check above, "final, post-fix run"), and confirmed via `git diff HEAD` that
the final diff on this file is exactly 11 insertions / 0 deletions, both localized to the one
method. No production behavior was ever affected (docblocks are inert PHP comments), but the
history/documentation loss would have been a real regression if committed uncaught.

Separately, the bare `phpcs` (no `--standard`) tooling gap (see Tier 1 self-check above) is a
pre-existing repo-hygiene item, not a defect in this story — flagged for A/O awareness only, not
blocking.

## Files changed

- `docs/groups/modules/do_streams/do_streams.info.yml`
- `docs/groups/modules/do_streams/src/Hook/StreamSwitcherHooks.php`
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php`
- `docs/groups/modules/do_streams/templates/stream-switcher.html.twig`

(No test files touched — `HelpTextConsumerCoverageTest.php` is T's file, unmodified by this
handoff.)
