# Handoff-D: Phase 2 - Design (#123 SC-4 Discovery three ways)

**Date:** 2026-07-23
**Branch:** 123-discovery-three-ways
**Mode:** (a) generated low-fi
**Wireframe:** `docs/handoffs/sc4-discovery-123/wireframe.md`

## Screens & states covered
- `/showcase` new "Discovery ranking: Recent / Hot / Promoted" H2 section, placed
  below the existing catalog entry list and existing `directory.layout` stub switcher.
- The new `discovery.ranking` `VariantSwitcher` instance (3 tabs: Recent/Hot/Promoted),
  deep-linked via `?discovery=recent|hot|promoted`.
- Per-variant embedded-view states: many (expected seeded case for all three),
  empty (fallback copy defined for Recent/Hot; Promoted must not hit this per
  acceptance but fallback copy is defined anyway), error (view/display missing).
- Keyboard/focus: reuses `VariantSwitcher`'s existing radiogroup + roving-tabindex
  as-is, no new behavior.
- WCAG 2.2 AA notes: labels, focus-visible, non-color status glyphs/text, contrast
  floor callout.
- Cache-context callout: `url.query_args:discovery` added alongside existing
  `url.query_args:variant`.

## Existing components/patterns reused
- `VariantSwitcher::build()` render array + `.do-showcase-variant-switcher` CSS
  (no new switcher primitive).
- `.do-showcase-catalog-entry` / `.do-showcase-status-badge` visual vocabulary for
  section framing, consistent with the catalog list above it.
- `ⓘ` HelpText tooltip trigger markup (`data-do-tooltip`, `role="note"`,
  `tabindex="0"`) — identical shape to the existing map-help/per-entry help triggers
  in `ShowcaseController::page()`.
- `views_embed_view()` embed pattern for rendering the three rankings.

## Open questions for approval
1. Per-tab tooltip vs. one shared tooltip: `VariantSwitcher` today supports only ONE
   tooltip per switcher wrapper, not per-option. Wireframe assumes one shared tooltip
   covering all three decisions. If the issue intends three distinct per-tab tooltip
   strings, that's new `VariantSwitcher` surface — flag to A before implementation.
2. Query-key parameterization: `VariantSwitcher::build()` currently hardcodes
   `'href' => '?variant=' . rawurlencode($id)'` (VariantSwitcher.php:155) with no
   per-instance query-key parameter. The wireframe's `?discovery=` deep-link requires
   either extending `build()`'s signature or having the controller rewrite
   `#options[*]['href']` post-build. This is a plan-time (A) decision, not a D one —
   surfaced here so A resolves it before F implements.

## Approval
Auto-approved per POC lean pipeline (no human sign-off gate for this run).
