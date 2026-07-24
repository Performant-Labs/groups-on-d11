# Handoff-S: Phase 6 — #193 SD-4 tooltip consumers (chrome.stream_switcher)

**Date:** 2026-07-24
**Branch:** 193-sd4-tooltip-consumers
**Issue:** #193 (triage-corrected scope, Option A)
**Handoff-T reviewed:** `docs/planning/handoffs/193-sd4-tooltip-consumers/handoff-T-red.md`
**Handoff-F reviewed:** `docs/planning/handoffs/193-sd4-tooltip-consumers/handoff-F.md`
**A precondition:** N/A for this cycle (POC-lean pipeline; A was consulted at plan time — see decisions.md phase-4 note).
**T precondition:** Confirmed — T reported valid RED (both new tests fail for the single correct reason: `chrome.stream_switcher` has no consumer), no unresolved blockers.

## Verification I re-ran

```
$ ddev exec bash scripts/ci/assemble-config.sh
==> assemble-config: repo root = /var/www/html
==> config: copied 138 file(s), excluded 7 env-specific file(s)
==> modules: copied 15 custom module(s) into web/modules/custom/
==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled

$ ddev exec 'SIMPLETEST_DB=... SIMPLETEST_BASE_URL=... php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
    web/modules/custom/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php \
    web/modules/custom/do_streams/tests/src/Kernel/StreamSwitcherHooksTest.php'

OK, but there were issues!
Tests: 9, Assertions: 249, Deprecations: 12, PHPUnit Deprecations: 8.
```

All 9 tests GREEN (2 new coverage tests + 7 pre-existing switcher tests). 12 deprecations are pre-existing core / Views / annotation issues (missing `#[RunTestsInSeparateProcesses]` on the older test class, `@ViewsSort` deprecation, `group_nodes` view table-class update) — none reference this diff or `do_streams`' own production code.

## Spec compliance (Option A corrected acceptance)

| Acceptance criterion | Status | Evidence |
|---|---|---|
| 1. `data-do-tooltip` consumer wired on stream-switcher chrome for `HelpText::get('chrome.stream_switcher')` | PASS | `stream-switcher.html.twig:52-58` renders `<span class="do-chrome-info" ... data-do-tooltip="{{ switcher_help_copy }}">ⓘ</span>` inside `{% if switcher_help_copy %}`; `StreamSwitcherHooks::preprocessViewsView()` sets `#switcher_help_copy => HelpText::get('chrome.stream_switcher')` on the render array. |
| 2. Add `do_streams → do_chrome` module dependency | PASS | `do_streams.info.yml:17` adds `- do_chrome:do_chrome`. |
| 3. Regression test — every authored HelpText key has ≥1 consumer somewhere | PASS | `HelpTextConsumerCoverageTest::testEveryHelpTextKeyHasAConsumer()` iterates `HelpText::all()`, scans production files (docs/groups + web/themes/custom/groups_chrome, tests + HelpText.php excluded), fails on any un-whitelisted orphan. GREEN today; would fail if a future story added an orphaned key. |

## End-to-end wire verification (my re-trace)

`HelpText::get('chrome.stream_switcher')` → returns the plain string literal at `HelpText.php:420` → `StreamSwitcherHooks::preprocessViewsView()` assigns it as `#switcher_help_copy` on the `#theme => stream_switcher` render array (`StreamSwitcherHooks.php:250-253`) → `DoStreamsHooks::theme()` declares `switcher_help_copy => ''` in `stream_switcher`'s `variables` map (`DoStreamsHooks.php:720`) so `ThemeManager::render()` propagates it → `stream-switcher.html.twig` guards on `{% if switcher_help_copy %}` and renders `data-do-tooltip="{{ switcher_help_copy }}"`. Chain complete.

Library attach: verified `DoChromeHooks::pageAttachments()` at `DoChromeHooks.php:45-46` unconditionally appends `do_chrome/tooltips` on every page request. No per-view attach needed; F's "no library attach here" decision correctly avoids breaking `StreamSwitcherHooksTest::testPreprocessViewsViewIsNoOpForGroupContentStream()`'s "no `#attached.library` for non-switcher view" contract (test still GREEN in my re-run).

## Copy honesty ("Global, My Feed, Following, and Trending are views over the same underlying content ...")

Verified each named scope exists as a real route:
- `global` → `/stream` (activity_stream view, LIVE)
- `my_feed` → `/my-feed` (do_streams.my_feed controller route, #110 ST-1, merged)
- `following` → `/following` (following_feed view, #111 ST-2, merged)
- `trending` → `/trending` (trending view, #113 ST-4, merged)

All four are declared in `StreamSwitcherHooks::SCOPE_ROUTES` (lines 111-114) and their views/controllers are shipped and merged per prior story references. Copy accurately describes shipped reality.

## Whitelist audit (T's `whitelistedKeys()` — 20 entries across 2 categories)

**Category 1 (7 entries — genuinely wired via two-part-literal concatenation):** Manually re-verified `VariantSwitcher::build()` and `ShowcaseController::page()` do exactly the concatenation T describes; the literal ids at the call sites (ShowcaseController.php:229/359, DoShowcaseHooks.php:510, ModelToggleHooks.php:215, ShowcaseCatalog::all()) match. Honest.

**Category 2 (13 entries — claimed pre-existing orphans, out of scope):** Ran a precise per-key literal-string sweep across `docs/groups/` and `web/themes/custom/`:

- Genuinely orphan (0 production consumers, whitelist honest): `privacy.public`, `privacy.unlisted`, `privacy.vs_invite_only`, `stream.my_feed`, `stream.my_feed.empty`, `stream.my_feed_events.rsvp_chip`, `stream.activity_row.social/aggregated/comment`, `stream.model_toggle`, `profile_activity.section`, `page.activity`. Matches whitelist claims exactly.
- **Whitelist over-conservative (6 entries — non-blocking advisory):** `privacy.private` has 2 real consumers in `groups_chrome.theme:490,889`; `page.my_feed`, `page.following`, `page.trending`, `page.my_feed_events`, `page.profile_stream` each appear as literal values in `PageHelp::getRouteMap()` (`PageHelp.php:77-100`). These would pass the scanner without the whitelist entry — the whitelist doesn't hide a real bug (the keys are wired), it just misdescribes them as unwired. Whitelist Category-2 comments for these 6 keys should be updated (or the entries removed) in a follow-up hygiene pass, but this does NOT block #193's PR: the regression sweep's primary guard behaviour (detecting new un-whitelisted orphans) is unaffected.

## Scope discipline

Confirmed: F touched only 5 files, all under `docs/groups/` (T's new test file plus 4 production files under `docs/groups/modules/do_streams/`). Nothing staged under `web/modules/custom/` or `config/sync/` (those are gitignored build artifacts).

## Quality audit

| Area | Result | Notes |
|---|---|---|
| Spec compliance | PASS | All 3 corrected acceptance criteria met and re-verified. |
| API consistency | N/A | No API surface changed. |
| Error handling | PASS | `{% if switcher_help_copy %}` guard degrades to "no trigger" on empty copy — matches this module's defensive-degradation convention (`preprocessNodeEventStreamCard()`). |
| UI/UX match to spec | PASS | Trigger shape mirrors `PageHelp::infoTrigger()` verbatim (do-chrome-info span, tabindex=0, role=note, aria-label + data-do-tooltip, ⓘ glyph) — the codebase's own documented convention per PageHelp.php's docblock. |
| Accessibility | PASS | `tabindex="0"` (keyboard focusable), `role="note"`, `aria-label` mirrors tooltip copy so SR users get the same information without hover. Matches every other B-story tooltip trigger in do_chrome. |
| Architecture | PASS | New cross-module dep (`do_streams → do_chrome`) is the exact dep `HelpText.php:414-415` docblock anticipated. Theme-hook variable registered on the sole `DoStreamsHooks::theme()` per the module's documented `LogicException`-avoidance pattern. No new class, service, route, or schema. |
| Code organization | PASS | Extended existing trio (StreamSwitcherHooks + DoStreamsHooks::theme + stream-switcher.html.twig). No parallel path. Diff localized (11 insertions to DoStreamsHooks.php confined to `theme()`; ~11 lines to StreamSwitcherHooks preprocess; 20 lines to twig; +1 line to info.yml). |
| Security | PASS | Copy is passed through twig auto-escaping in attribute context — no `\|raw` filter. `HelpText::get()` returns plain string from a static array (not user input). No new attack surface. |
| Performance | PASS | One extra `HelpText::get()` call per switcher-attached view render (a static array lookup, effectively free). No new query, no new library load. |
| Naming consistency | PASS | `switcher_help_copy` matches sibling naming (`empty_copy`, `scope_tabs`, `ranking_control`). `chrome.stream_switcher` key follows namespaced HelpText convention. |
| Test quality (`testing/test-quality.md` §7) | PASS with 1 advisory | See below. |

### Test-quality audit

Per-test:
- `testChromeStreamSwitcherHasConsumer()` — names one behaviour, fails in isolation for the right reason (asserted via T's RED sanity check), sits at Kernel tier (cheapest sufficient — needs do_chrome autoloaded), asserts behaviour (consumer exists) not implementation (doesn't require specific twig markup). PASS.
- `testEveryHelpTextKeyHasAConsumer()` — regression sweep, names one behaviour (no orphan keys), fails for a listable reason (specific unwhitelisted key), catches future regressions. PASS.

Per-suite: 2 tests + 36 assertions is proportionate — one pins the specific acceptance criterion, one is the regression sweep the acceptance criterion #3 explicitly asks for. No coverage padding, no duplicate signal, no snapshot-everything, no mock-shaped tests. Both tests would fail in isolation for the right reason (T verified RED).

**Advisory (non-blocking):** the whitelist's Category-2 comments for 6 entries (`privacy.private`, `page.my_feed/following/trending/my_feed_events/profile_stream`) claim "genuinely NOT wired" but those keys DO have production consumers (see whitelist audit above). Recommend either removing them from the whitelist (they'd pass without it) or rewriting their comments to describe their real consumer surface. Not a #193 blocker — the regression sweep's guard behaviour is unaffected either way.

## Hidden-breakage spot-check

Ran full `StreamSwitcherHooksTest.php` (7 tests, 213 assertions): all GREEN. In particular:
- `testPreprocessViewsViewAttachesSwitcherOnActivityStream` still GREEN — the new `#switcher_help_copy` render-array property is compatible with existing `header` merge assertions.
- `testPreprocessViewsViewIsNoOpForGroupContentStream` still GREEN — no library was added to the non-switcher path.

Also spot-checked: the `switcher_help_copy => ''` variable declaration in `DoStreamsHooks::theme()` is additive; no other theme hook (`do_streams_shell`, `node__event__stream_card`) was touched.

## phpcs

Deferred to F's Tier 1 self-check (see handoff-F.md): 3 of 4 edited files clean under `--standard=Drupal`; the 1 error on `DoStreamsHooks.php:644` is verified pre-existing (F checked out `HEAD` version and reproduced the same error before any #193 edits). Not introduced here.

## Scope check

F delivered exactly the corrected Option A scope — no more, no less. No touch to stream-card templates (correctly, since `stream.card.*` keys don't exist).

## Verdict

**PASS** — all 3 corrected acceptance criteria met, spec-compliant, quality acceptable. Ready for O to commit.

### Advisory notes (non-blocking, follow-up hygiene)

1. `HelpTextConsumerCoverageTest::whitelistedKeys()` Category-2 misdescribes 6 keys (`privacy.private`, `page.my_feed`, `page.following`, `page.trending`, `page.my_feed_events`, `page.profile_stream`) as "genuinely NOT wired" — they each have ≥1 production consumer (`PageHelp::getRouteMap()` and `groups_chrome.theme`). Either remove them from the whitelist (they'd pass without it) or rewrite their inline comments. Cosmetic — regression-sweep behaviour is unaffected.
2. Repo-hygiene: no committed `phpcs.xml`/`.phpcs.xml.dist` pinning `--standard=Drupal` (F noted this too). Out of #193's scope; worth a dedicated cleanup story eventually.
