# Decisions journal — #132 SD-5 Showcase help

## Phase 1 (O) — brief

- **Decided**: Skip Designer. Trivial visual (append one `<span>` ⓘ into an existing `<aside>`); verbatim reuse of two live patterns (`PersonaSwitcher::build()` and `GroupTypeContentHelp::infoTrigger()`).
- **Decided**: Skip brief-gate o4-mini, A-dup, pre-PR hold — lean POC pipeline per overnight-mode authorization.
- **Decided**: New namespace `showcase_help.*` (nine keys); disjoint from every other consumer's namespace — no merge-order coordination required.
- **Decided**: Do NOT touch `do_group_membership`'s bespoke request-to-join flow — scope guardrail. Copy for future consumers via `showcase_help.membership-models`, surfaces on the tour page.
- **Decided**: Do NOT touch `VariantSwitcher::build()` for a per-option ⓘ. Instead a single `showcase_help.map` ⓘ adjacent to the switcher in `ShowcaseController::page()`.
- **Assumed**: `.do-showcase-info` CSS from #120 works on any bearer. If contrast fails audit, F extends same file.
- **Hedged**: Tour catalog = 7 entries. Data-driven `showcase_help.<id>` keys — future 8th entry auto-renders its ⓘ.
- **Evidence**: `HelpText.php:186`, `PersonaSwitcher.php:162`, `ShowcaseController.php:87`, `DoShowcaseHooks.php:285`.

## Phase 3 (A) — up-front plan review: **PASS** (with two soft findings, folded into brief-amendments.md)

- **A1 (soft)**: Persona banner ⓘ placement corrected — order `glyph, text, switch_back, help`.
- **A2 (soft)**: `do_chrome/tooltips` must be attached explicitly on `personaBanner()` `#attached['library']`.
- **A confirmed**: Namespace disjoint, no parallel-path, scope guardrail honored on both compensations.
- **Evidence**: A review output; `brief-amendments.md`.

## Phase 4 (T) — author tests (RED)

- **Decided**: 4 test surfaces authored — Unit (`ShowcaseHelpTextTest.php` extended, 6 new methods),
  Functional (`PersonaBannerTest.php` extended, 4 new methods; new `ShowcaseControllerHelpTest.php`,
  6 methods), E2E (`tests/e2e/showcase-help.spec.ts`, new, 6 tests). 26 test methods total mapped to
  the brief's acceptance criteria + both amendments (A1 DOM order, A2 explicit library attach).
- **Decided**: `ShowcaseControllerHelpTest` is a NEW file, not an extension of an existing one — no
  prior controller-level test existed (`ShowcaseCatalogTest`/`VariantSwitcherTest` are Unit-only,
  data/render-array shape, not the live HTTP response).
- **Assumed**: the unknown-entry-id guard is validated via a direct `HelpText::get()` probe (task's
  explicitly-acceptable alternative) rather than wiring a fake catalog entry into `ShowcaseCatalog`
  — avoids a framework change the brief's scope guardrail forbids.
- **Hedged**: E2E spec validated statically only (no `node_modules` in this worktree or the
  reference checkout) — selectors cross-checked against `ShowcaseController::page()`'s real DOM
  contract and against `persona-switcher.spec.ts`'s proven selector conventions, not executed.
  Functional tests also RED-by-static-validation (no `vendor/` in this worktree; Kernel/Functional
  process-isolation limitation per the `0138` precedent) — Unit tier was real-executed via the
  primary checkout's DDEV container as an external tool, then that checkout was fully restored
  (`git checkout --`, `git clean -fd`, `rm -rf web/modules/custom`) to its pre-session state.
- **Evidence**: `docs/handoffs/132-showcase-help/handoff-T-red.md` — full RED output, per-tier
  execution mode, and source cross-checks (`DoShowcaseHooks.php`, `ShowcaseController.php`,
  `do_chrome.libraries.yml`).

## Phase 6 (T) — verify tests (GREEN) + Tier 2

- **Decided**: Unit tier (10 target + 11 do_chrome regression = 21 tests) real-executed via the
  primary-checkout-as-external-tool method (identical to T-red), all GREEN. Spot-checked suite
  validity by deleting `showcase_help.map` from a scratch copy — 3 tests correctly turned RED,
  confirming behavior-pinning not tautology.
- **Decided**: Functional (10 tests, 2 files) and E2E (6 tests, 1 file) remain env-blocked in this
  worktree (no `vendor/`, no `node_modules` — unchanged since T-red). Verified instead by line-for-
  line cross-check of every assertion against F's actual diff (`git show cce8d7f`) — no
  discrepancy found; all 7 catalog ids, the DOM child order (`glyph, text, switch_back, help`), the
  explicit `do_chrome/tooltips` attach, and the empty-copy guard match exactly.
- **Assumed**: No `handoff-F.md` existed on disk in the worktree at the expected path; F's summary
  (quoted in the T-green task prompt) was treated as authoritative alongside the commit diff.
- **Hedged**: Functional/E2E GREEN is inferred from source cross-check, not live execution — this
  is a coverage hole flagged explicitly for **U** to close via live walkthrough of `/showcase` and
  the persona banner across all 4 personas.
- **Evidence**: `docs/handoffs/132-showcase-help/handoff-T-green.md` — full GREEN output, spot-
  check output, and per-file diff cross-checks.

## Phase 8 (U) — UI walkthrough (PASS)

- **Decided**: Stood up a dedicated DDEV project (`gm132-showcase-help`, renamed from
  `pl-groups-on-d11` in `.ddev/config.yaml` to avoid colliding with the primary checkout's
  already-running project) since no `gm132-*` container existed yet; full assemble ->
  site:install -> config_sync_directory fix + system.site UUID match -> cim -> module enable ->
  4-step seed (`step_700`/`step_720`/`step_780`/`step_790`) -> cache:rebuild, mirroring
  `.github/workflows/test.yml` exactly (this worktree's copy of the workflow already includes
  the persona seed step).
- **Decided**: This closes the coverage hole T flagged — ran the real
  `tests/e2e/showcase-help.spec.ts` live (6/6 PASS) plus regression
  `showcase.spec.ts` + `persona-switcher.spec.ts` (24/24 PASS), 30/30 total, 0 console errors.
  Supplemented with manual Playwright-driven DOM/hover/keyboard checks confirming: DOM child
  order `glyph, text, switch_back, help` (A1), explicit tippy tooltip actually renders on hover
  for all 9 ⓘ triggers (A2), anonymous session has no banner, switch-back removes it, and all 3
  personas (Elena, Maria, Groups-Moderate) show correct copy.
- **Assumed**: Per the project override (Drupal/DDEV, not HTMX/SPA), the `u-drive.mjs` canonical
  helper does not apply — drove standard Playwright directly against the seeded DDEV site.
- **Evidence**: `docs/planning/handoffs/132-showcase-help/handoff-U.md`.

## Phase 9 (S) — spec audit (PASS)

- **Decided**: PASS — all 8 issue acceptance criteria verified against F's diff (cce8d7f),
  T's Unit real-execution (21/21 GREEN), and U's live 30/30 E2E + manual DOM/keyboard/hover
  walk. Zero scope-guardrail violations; append-only contract honored; namespace fully disjoint.
- **Decided**: The join-flow acceptance criterion is met via `showcase_help.membership-models`
  copy on the tour page rather than a `do_group_membership` in-flow tooltip. Accepted as a
  scope-honoring trade-off — the guardrail forbids `do_group_membership` edits, and the
  teaching content is delivered + Playwright-covered on the tour surface. PR body carries an
  audit note explaining this trade-off for the reviewer.
- **Decided**: SC-F1 duplication check clears — `showcase.switcher.directory.layout` (per-
  switcher tooltip) and `showcase_help.directory-presentation` (tour-page orientation) share
  a topic but not wording, and serve distinct render sites. Documented inline in HelpText.php.
- **Evidence**: `docs/planning/handoffs/132-showcase-help/handoff-S.md`.

## Phase 10 (O) — CI cycle 1 fix

- **Failed**: 4 tests in `ShowcaseControllerHelpTest`, all hitting `/showcase` and receiving **403 not 200**.
- **Root cause**: This is the first-ever Functional test to load the `/showcase` route. The route requires `_permission: 'access content'`, which is provided by `node_permission()`. Test class declared `$modules = ['do_showcase']` only — no `node`, so the anonymous role held no permissions on the minimal BrowserTestBase install, and every `drupalGet('/showcase')` 403'd.
- **Not env-flaky / not a core bug / not seed-dependent**: strictly a test-authoring omission. `/showcase` works everywhere else (kernel-parity DDEV install, local Playwright U walkthrough, CI E2E) because those pipelines run the full config sync which enables `node`.
- **Fix**: Add `'node'` to `$modules` in `ShowcaseControllerHelpTest`; leave `PersonaBannerTest` unchanged (it hits `<front>`, which anonymous can reach even without `node`). Tester-authoring correction, made by O per role note ("you may edit the test if it's obviously wrong per the brief").
- **Evidence**: `gh run 30002392033 --log-failed`; full log at `~/.claude/projects/.../full-log.txt` lines 1092–1120 (four `✘` markers, three `Current response status code is 403, but 200 expected`, one dependent selector-not-found on the 403 body).
- **Non-blocking noise in same log**: 1 pre-existing error in `CreateGroupWizardOrganizerTest` (`link` field-type plugin missing — belongs to another module, out of #132 scope); 15 Drupal 11.2 deprecation warnings (all `⚠`, not failures).

## Phase 11 (O) — CI cycle 2 verification

- **Result**: `Tests: 70, Errors: 1, Failures: 0` — my node-module fix (986ea46) cleared all 4 `ShowcaseControllerHelpTest` failures. Assertion count rose 491→527 (my tests running to completion).
- **Only remaining failure**: `CreateGroupWizardOrganizerTest::testWizardCreateGrantsOrganizerAndRedirectsToPreview` — `PluginNotFoundException: Unable to determine class for field type 'link' found in field.storage.group.field_group_links`. This is a `do_tests` module test, not #132; the `link` field-type module (`drupal/link`, Drupal core module) isn't enabled by the test class. Inherited from main; coordinator has opened hotfix PR#160.
- **Status**: PARKED awaiting PR#160 merge to main. When it merges, this branch either rebases onto new main or is retriggered via empty commit, then auto-merges on green.
- **Kernel + E2E**: still GREEN through both cycles.
