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
