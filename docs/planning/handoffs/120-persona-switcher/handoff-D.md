# Handoff-D: Phase 2 - Persona Switcher wireframe

**Date:** 2026-07-22
**Branch:** 120-persona-switcher
**Mode:** (a) generated low-fi
**Wireframe:** docs/planning/handoffs/120-persona-switcher/wireframe.md

## Screens & states covered

- **Header dropdown — collapsed:** native `<select>` labeled "Browse as", pre-selected to current
  session persona; visible always (anon + authenticated-as-persona); one wrapper-level `ⓘ` tooltip.
- **Header dropdown — expanded:** all 4 options (Anonymous / Elena Garcia — Member / Maria Chen —
  Organizer / Groups-Moderate) with per-option native `title=` hover text derived from the same
  HelpText keys as the wrapper tooltip. No disabled/"coming" option — all 4 are fully public.
- **Banner — absent (Anonymous):** truthful-empty state, no DOM markup at all when no persona active.
- **Banner — active (one state per persona):** exact-copy banner "You're browsing as {name} —
  switch back", `role="status"`, non-color `▶` glyph, real `<a>` switch-back link.
- **Switch flow (5 sub-states):** anon→Elena, anon→Maria, anon→Moderator, persona→anonymous
  (switch-back), and persona-A→persona-B direct re-selection — each specifies session/URL/banner
  transitions.
- **Narrow-screen/responsive:** dropdown degrades free via native OS picker; label may visually
  collapse but must stay in the accessibility tree (sr-only, never display:none); banner wraps
  inline rather than needing a distinct mobile layout.

## Existing components/patterns reused

- Visual family: `.do-showcase-*` class prefix, `#4da3ff` focus-ring token, dark-bg/white-text
  banner pairing already AA-checked in `do_showcase.css` (`.do-showcase-ribbon`).
- `hook_page_top` idiom (`DoShowcaseHooks::pageTop()`) reused for the persistent banner —
  same "single global attach point, visible markup" shape as the existing POC ribbon.
- do_chrome `HelpText::get()` / `data-do-tooltip` / tippy.js wiring reused verbatim for the
  wrapper-level combined tooltip (one-tooltip-per-widget convention already established by
  `VariantSwitcher`).
- Non-color state cue convention (glyph + text, never color-only) carried over from
  `VariantSwitcher`'s `●` selection marker.

## Open questions for approval

1. Per-option tooltip interpretation: native `<select>` can't host live tippy tooltips per
   `<option>` — proposed one wrapper `ⓘ` (do_chrome/tippy) + native `title=` per option as the
   closest achievable interpretation of "each option carries a tooltip." Needs explicit sign-off
   since it's a reinterpretation of the issue's literal wording.
2. Banner position: proposed inline (scrolls with content), not fixed/sticky. Flagged as a
   tradeoff vs. always-visible switch-back link.
3. Post-switch redirect when the destination page 403s for the new persona: proposed redirect to
   `<front>` as a fallback; simpler alternative (always redirect to prior URL, let 403 show) also
   offered as acceptable POC-simplest default.

## Approval

[To be filled by O: "Approved by operator <ISO timestamp>"]
