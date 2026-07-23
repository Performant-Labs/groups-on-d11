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
