# Decisions journal — #132 SD-5 Showcase help

## Phase 1 (O) — brief

- **Decided**: Skip Designer. Trivial visual (append one `<span>` ⓘ into an existing `<aside>`); verbatim reuse of two live patterns (`PersonaSwitcher::build()` and `GroupTypeContentHelp::infoTrigger()`).
- **Decided**: Skip brief-gate o4-mini, A-dup, pre-PR hold — lean POC pipeline per overnight-mode authorization.
- **Decided**: New namespace `showcase_help.*` (nine keys); disjoint from every other consumer's namespace — no merge-order coordination required.
- **Decided**: Do NOT touch `do_group_membership`'s bespoke request-to-join flow to attach a `data-do-tooltip` to the pending state — scope guardrail forbids framework changes. Copy is authored for future consumers via `showcase_help.membership-models` and surfaces on the tour page instead.
- **Decided**: Do NOT touch `VariantSwitcher::build()` to add a per-option ⓘ on the disabled `Map` option (framework change). Instead render a single `showcase_help.map` ⓘ adjacent to the switcher in `ShowcaseController::page()`.
- **Assumed**: `.do-showcase-info` CSS class from #120 is inherited/reused by any element carrying it — verified by grep (`docs/groups/modules/do_showcase/css/persona-switcher.css`). If contrast fails audit, F extends CSS in the same file.
- **Hedged**: Tour-page catalog now shows 7 entries (6 comparisons + persona switcher). Naming keys after `$entry['id']` (dashes intact) keeps rendering trivially data-driven — a future 8th entry only needs a matching HelpText key to auto-render its ⓘ.
- **Evidence**: `docs/groups/modules/do_chrome/src/HelpText.php:186` (infoTrigger reuse call-out), `docs/groups/modules/do_showcase/src/Persona/PersonaSwitcher.php:162` (verbatim ⓘ span template), `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php:87` (per-entry loop insertion point), `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php:285` (personaBanner children render-array).
