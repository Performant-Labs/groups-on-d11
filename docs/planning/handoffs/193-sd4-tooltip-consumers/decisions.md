# Decisions log: #193 SD-4 tooltip consumers (chrome.stream_switcher)

## Phase 4 (T-red) — 2026-07-24

**Decided:**
- Scope is Option A (coordinator-approved triage correction): only
  `chrome.stream_switcher` is a real, currently-orphaned key. The issue's original 6
  `stream.card.*` keys do not exist in `HelpText.php` and are not asserted against.
- Test location: `docs/groups/modules/do_chrome/tests/src/Kernel/HelpTextConsumerCoverageTest.php`,
  a Kernel test (module `do_chrome`), modeled on the existing `HelpTextStreamKeysTest.php`.
- Consumer-detection strategy: search for the key's full literal quoted string anywhere in a
  non-test, non-`HelpText.php` production file (plus a literal-copy-value fallback), rather than
  trying to pattern-match `HelpText::get(...)` call syntax — the codebase uses at least 5 distinct
  key-resolution indirection styles, and matching each one precisely is unbounded.
- Whitelist two categories of keys the scanner cannot/should not flag: (1) genuinely wired via a
  two-part-literal-concatenation style no single file spells out in full (manually verified by
  reading the consuming code), and (2) genuinely unwired, pre-existing, documented, out-of-scope
  gaps (privacy.* axis, W2 pre-registered page.* keys, sibling-story stream.* keys, etc).
- Scan both `docs/groups/` and `web/themes/custom/groups_chrome/` — the latter is a real,
  git-tracked theme (not a build artifact), discovered during authoring to be the actual consumer
  location for the `card.*` keys per `HelpText.php`'s own #127 docblock.

**Assumed:**
- F will wire `chrome.stream_switcher`'s consumer into `StreamSwitcherHooks.php` /
  `stream-switcher.html.twig`, per `HelpText.php`'s own docblock pointer (lines 404-419) — T does
  not prescribe the exact markup beyond "a `data-do-tooltip` trigger carrying this key's copy",
  consistent with the existing SD-pattern used elsewhere in `do_chrome`.

**Hedged:**
- The literal-quoted-string detection heuristic is not bulletproof against a 6th indirection style
  a future story might introduce (e.g. a key built from string interpolation `"chrome.{$suffix}"`
  rather than concatenation). If F's implementation uses such a style and the regression sweep
  test produces a false negative, that is a test-authorship gap to fix in a future T pass, not a
  reason to weaken this RED's validity today (the RED is confirmed valid via the sanity-check
  below).

**Evidence:**
- RED confirmed: `php vendor/bin/phpunit` on the assembled tree, both new tests fail for the
  single correct reason (`chrome.stream_switcher` has no consumer); 36 assertions total, zero
  unrelated failures.
- Sanity check: temporarily added a throwaway matching literal to
  `stream-switcher.html.twig`, re-ran — both tests went GREEN, confirming the detection logic
  fires correctly on a real fix. Reverted via `git checkout --`; `git status --short` confirms
  the file is clean afterward.
- Full RED output and reproduction commands are in `handoff-T-red.md` in this directory.

## Phase 5 (F-green) — 2026-07-24

**Decided:**
- Extended (not duplicated) the existing `StreamSwitcherHooks` / `stream-switcher.html.twig` /
  `DoStreamsHooks::theme()` trio exactly per T's pointer and `HelpText.php`'s own #115 docblock —
  no new class, hook, or template. Added `do_chrome:do_chrome` to `do_streams.info.yml`'s
  `dependencies` (the dependency `HelpText.php`'s docblock explicitly noted as missing).
- The trigger markup mirrors `PageHelp::infoTrigger()`'s exact canonical shape verbatim: a
  `do-chrome-info` span, `tabindex="0"`, `role="note"`, `aria-label` = copy, `data-do-tooltip` =
  copy, U+24D8 "ⓘ" glyph — the same shape every existing B-story surface
  (#88-#92)/`PageHelp`/`GroupTypeContentHelp::infoTrigger()` already uses, per brief.md's
  documented Reuse convention of duplicating this tiny shape rather than extracting a shared
  helper.
- No library attach was added for the tooltip binding itself: read `DoChromeHooks
  ::pageAttachments()` directly and confirmed `do_chrome/tooltips` (the tippy.js binding that
  reacts to `data-do-tooltip`) is already attached GLOBALLY on every page request — attaching it
  again per-view would be redundant and would risk breaking
  `StreamSwitcherHooksTest::testPreprocessViewsViewIsNoOpForGroupContentStream()`'s existing
  "no `#attached.library` key at all for a non-switcher view" assertion (verified this test still
  passes unmodified after the change).
- Threaded the copy through as a plain string render-array property
  (`#switcher_help_copy` -> `switcher_help_copy` theme variable), not a nested render array —
  `HelpText::get()` already returns a plain string, and the template only needs to place it
  directly into two attribute values (`aria-label`, `data-do-tooltip`), so a render array would
  add indirection with no benefit. Declared `switcher_help_copy => ''` as the new default in
  `DoStreamsHooks::theme()`'s `stream_switcher` entry's `variables` array (required, not
  cosmetic — a `#`-prefixed render-array property only reaches `ThemeManager::render()`'s output
  if its bare name is declared here, per this same file's own pre-existing
  `preprocessDoStreamsShell()` docblock explaining the identical contract for `scope_tabs`/
  `ranking_control`).
- The ⓘ span in `stream-switcher.html.twig` is wrapped in `{% if switcher_help_copy %}` — an
  unexpectedly empty/missing HelpText entry degrades to "no tooltip trigger at all", never an
  empty-but-present affordance and never a fatal, matching this module's established
  defensive-degradation convention elsewhere (`DoStreamsHooks
  ::preprocessNodeEventStreamCard()`'s date/group-badge guards).
- Registered the new theme-hook variable on `DoStreamsHooks::theme()` (NOT on
  `StreamSwitcherHooks` itself) — `stream_switcher`'s `#[Hook('theme')]` registration is already
  consolidated there per this module's own documented `LogicException`-avoidance pattern
  ("Module do_streams should not implement theme more than once"); adding a second `theme()`
  method anywhere in this module would immediately break that established constraint.

**Assumed:**
- None beyond what T's handoff already scoped — the implementation surface (which file gets the
  new consumer, what markup shape to use) was explicit in the task brief and cross-verified
  directly against `PageHelp.php`/`DoChromeHooks.php` source before writing any code.

**Hedged:**
- None on the implementation's correctness claims — every claim (global tooltip library
  attach, theme-hook variable-declaration contract,
  `testPreprocessViewsViewIsNoOpForGroupContentStream`'s exact assertion shape) was verified by
  reading the actual source, not inferred, and every verification command below reproduces
  GREEN. Process note, not a hedge on correctness: the first pass at editing
  `DoStreamsHooks.php` (full-file rewrite, no line-level edit tool available) accidentally
  deleted a pre-existing docblock instead of appending to it — caught via a routine
  "is this diff the size I expect" check (`git diff --cached` showed ~54 lines of churn for
  what should have been ~10), fixed by restoring the original content and re-verified GREEN
  end-to-end. See handoff-F.md's Known issues section for the full account.

**Evidence:**
- `HelpTextConsumerCoverageTest.php`: both tests GREEN (`OK (2 tests, 36 assertions)`) after the
  change — the same tests T confirmed RED for the single, correct reason
  (`chrome.stream_switcher` has no consumer).
- `StreamSwitcherHooksTest.php` (pre-existing, not authored by F): all 7 tests still GREEN
  (`Tests: 7, Assertions: 213`), including `testPreprocessViewsViewIsNoOpForGroupContentStream` —
  confirms the "no library attached for a non-switcher view" contract is untouched. The 12
  deprecation notices in that run are pre-existing core/Views/annotation deprecations unrelated to
  this change (confirmed: none reference `do_streams`' own code).
- `StreamsShellTest.php` + `StreamsInstallTest.php` (sanity check, not required by the task, run
  because both touch the shared `DoStreamsHooks::theme()` file this change also edits): all 8
  tests GREEN (`Tests: 8, Assertions: 197`) — `testModuleInstallsWithZeroSchemaChanges` and
  `testModuleUninstallsCleanly` in particular confirm the new `do_chrome` dependency + theme-hook
  variable addition introduce no schema drift.
- `phpcs --standard=Drupal` on the 4 edited files: 3 of 4 clean (exit 0, zero output);
  `DoStreamsHooks.php` carries exactly 1 error (line 644, "Doc comment short description must
  be on a single line") — verified PRE-EXISTING by checking out HEAD's (pre-#193) version of
  the same file into place and re-running the identical phpcs command: same line, same error,
  before any of this story's edits existed. Not introduced by this diff.
  The bare `phpcs docs/groups/modules/do_streams/ docs/groups/modules/do_chrome/` invocation (no
  `--standard` flag) falls back to PHP_CodeSniffer's own default PEAR standard (confirmed via
  `phpcs --config-show`: no `default_standard` is pinned, and no `phpcs.xml` exists at the repo
  root) — under that unrelated default standard, EVERY file in both modules fails identically
  (e.g. `HelpText.php`: 408 errors under PEAR vs. 18 under `--standard=Drupal`, and files this
  story never touched, like `MyEventsController.php` and `PermissionMatrix.php`, also fail in the
  hundreds) — a pre-existing tooling/environment gap (no committed ruleset pinning the standard),
  not a signal introduced by this diff. Full detail in handoff-F.md.

## Phase 6 (S) — 2026-07-24

**Decided:**
- Verdict PASS. All 3 corrected Option A acceptance criteria met, spec-compliant, no
  hidden breakage. Re-ran assembly + both target tests in ddev: 9 tests / 249 assertions
  GREEN (2 new coverage tests + 7 pre-existing switcher tests). Only pre-existing core /
  Views deprecation notices; none reference this diff.
- End-to-end wire verified by re-tracing HelpText::get('chrome.stream_switcher') →
  StreamSwitcherHooks preprocess → #switcher_help_copy → theme-variable declaration →
  twig render of `data-do-tooltip`. Global tooltip library confirmed attached at
  DoChromeHooks.php:45-46 (unconditional pageAttachments append).
- Copy honesty confirmed: all four scopes named in the tooltip copy (Global / My Feed /
  Following / Trending) resolve to real, shipped routes (SCOPE_ROUTES lines 111-114;
  #110/#111/#113 all merged).
- Scope discipline confirmed: 5 files touched, all under docs/groups/; nothing under
  web/modules/custom/ or config/sync/.

**Hedged:**
- Whitelist over-conservative for 6 Category-2 entries — `privacy.private`,
  `page.my_feed`, `page.following`, `page.trending`, `page.my_feed_events`,
  `page.profile_stream`. Each has ≥1 real production consumer (`PageHelp::getRouteMap()`
  and `groups_chrome.theme`), so they'd pass the scanner without a whitelist entry. This
  is a documentation-drift issue, NOT a hidden-bug issue: the whitelist doesn't shield an
  orphan — it just misdescribes wired keys as unwired. Regression-sweep guard behaviour
  is unaffected; not a #193 blocker; flagged as follow-up hygiene in handoff-S.md's
  advisory notes.

**Evidence:**
- Full re-verification output and per-criterion trace in handoff-S.md in this directory.
