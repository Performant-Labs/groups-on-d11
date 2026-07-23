# Decisions — #126 SD-1 Page tooltips

## O — Phase 1 (brief)
- **Decided:** Skip D. Story is highly patterned (identical ⓘ affordance to #89 GroupTypeContentHelp already shipped). Wireframe would be trivially "an ⓘ span after the H1". Overnight-lean-POC judgment.
- **Decided:** Injection via `hook_preprocess_page_title` + `title_suffix` slot — zero template overrides, ⓘ lands adjacent to H1 by core convention.
- **Decided:** Route→key map is the source of truth. Unknown-key/missing-copy returns empty (silent), matching HelpText::get() contract.
- **Decided:** Not extracting a shared `TooltipTrigger` helper — existing B-stories (#88/#89/#90) each ship trivial per-hook `infoTrigger()` methods; matching that convention beats a cross-cutting refactor in scope.
- **Assumed:** `view.group_members.page_1` is the correct route id (path `group/{group}/members`). If verification finds a different id (e.g. `page_2`), F/T adjust.
- **Evidence:** brief.md; grep of views.view.*.yml showing all 5 target views have `display_plugin: page` with `id: page_1`.

## A — Phase 3 (up-front plan review)
- **Verdict:** PASS with 2 documentation warns; both absorbed into brief.
- **Decided (warn #1 absorption):** Default-deny is now explicit in brief §Design and Acceptance #6 — the route map is the allowlist; no admin-theme fallback needed.
- **Decided (warn #2 absorption):** `aria-label` retained over `aria-describedby` for baseline parity with #89/#122. Divergence would be marginally more correct semantically but breaks consistency with 5 shipped B-stories.
- **Assumed:** Olivero (or the active theme) preserves the `title_suffix` render slot in `page-title.html.twig`. If a custom override strips it, F re-checks; groups_chrome theme is not currently known to override page-title.html.twig.
- **Evidence:** handoff-A dated 2026-07-23; A read GroupTypeContentHelp.php + DoChromeHooks.php + HelpText.php + issue #126.
