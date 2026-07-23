# Decisions — #126 SD-1 Page tooltips

## O — Phase 1 (brief)
- **Decided:** Skip D. Story is highly patterned (identical ⓘ affordance to #89 GroupTypeContentHelp already shipped). Wireframe would be trivially "an ⓘ span after the H1". Overnight-lean-POC judgment.
- **Decided:** Injection via `hook_preprocess_page_title` + `title_suffix` slot — zero template overrides, ⓘ lands adjacent to H1 by core convention.
- **Decided:** Route→key map is the source of truth. Unknown-key/missing-copy returns empty (silent), matching HelpText::get() contract.
- **Decided:** Not extracting a shared `TooltipTrigger` helper — existing B-stories (#88/#89/#90) each ship trivial per-hook `infoTrigger()` methods; matching that convention beats a cross-cutting refactor in scope.
- **Assumed:** `view.group_members.page_1` is the correct route id (path `group/{group}/members`). If verification finds a different id (e.g. `page_2`), F/T adjust.
- **Evidence:** brief.md; grep of views.view.*.yml showing all 5 target views have `display_plugin: page` with `id: page_1`.
