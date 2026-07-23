# Decision Journal — #141 MC-2 About section

**Run started:** 2026-07-23  **Branch:** 141-about  **Base:** origin/main @ 49fe585

---

## O — Phase 1 (survey + brief)

**Date:** 2026-07-23

**Decided:**
- **NEW field `field_group_about`** (text_long) rather than reuse of `field_group_description`.
  Justification: spec says "richer content **beyond** the one-line description"; seed reality
  confirms descriptions are one-liners (~10–15 words). Reuse would break description's teaser
  role or leave About semantically identical to description.
- **Extend `do_group_extras`** (module), not new module. #140's exact pattern is the model.
- Weight 10 (already reserved by #140 with a comment marker) is the display slot.
- Formatter: `text_default` (matches description). Widget: `text_textarea` (formatted, no summary).
- Empty-state strategy: use field's own `label: above` ("About") so Drupal core suppresses the
  whole wrapper on zero deltas — mirrors #140 warn #6 pattern; no template override needed.
- D skipped per LEAN POC pipeline judgment (trivial field-add with no user-facing design choice
  beyond "About heading + formatted body below description"). Recorded here so A can flag if it
  disagrees.

**Assumed:**
- The reserved-weight-10 slot rendered position (between visibility=1, image=2, links=20) yields
  the intended narrative flow: description → visibility badge → image → About body → Links CTA.
  If UX judgment says About should render immediately below description (before image), the
  weight is a one-line change — T's kernel test should pin weight=10 either way.
- `basic_html` filter format exists in the seeded site (used by description seed already).

**Hedged:**
- None — every mechanism above has a direct precedent in #140 or the existing description field.

**Evidence:**
- `docs/planning/handoffs/141-about/survey.md`
- `docs/planning/handoffs/141-about/brief.md`
- `docs/groups/scripts/step_700_demo_data.php:85` — description seed shape confirms one-liner
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml:50` — `# (weight 10 reserved for #141 About)` placeholder
- `docs/planning/handoffs/140-links/handoff-A-plan.md` — full analogue plan review

---
