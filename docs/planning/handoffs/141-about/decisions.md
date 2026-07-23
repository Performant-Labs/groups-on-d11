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

## A — Phase 3 (up-front plan review)

**Date:** 2026-07-23  **Verdict:** PASS with 3 T-warns + 1 brief copyedit.

**Decided (confirmed by A):**
- New-field decision (Finding #1 PASS).
- Extend `do_group_extras` (Finding #2 PASS).
- Weight-10 slot + marker convention + dependency alphabetization (Finding #3 PASS).
- Test-tier split (kernel + E2E, no functional, no unit) — Finding #7 PASS.
- Anti-duplication clean across sibling modules — Finding #8 PASS.
- Forward-compat clean across #142/#143/#144/#145 — Finding #9 PASS.

**Warns folded into brief (encoded for T's RED):**
- **#4** — empty-state test covers BOTH shapes: never-set AND explicit `[value=>'', format=>'basic_html']`.
- **#5** — AC-5 asserts observable HTML (`<strong>` present); T materializes minimal `basic_html`
  FilterFormat in setUp (kernel doesn't install site config). Added `filter` to $modules.
- **#6** — F code-shape hint: About library attach nests INSIDE the same outer `bundle && view_mode`
  conditional links uses. Optional kernel library-attach assertion — E2E covers observable side.

**Brief copyedit (A finding #10):** fixed self-referencing "Coordination with #140" dependency-block
sentence — corrected to "insert as first entry in dependencies.config — sorts alphabetically above
field_group_description".

**Also decided (A confirmed):** do NOT edit `do_group_extras.info.yml`. Core `text` is universally
available; field.storage YAML declares its own `module: text` dep.

**Assumed:** `basic_html` allowed_html can be minimal (`<p><strong>`) for the T fixture — actual
site format is broader, but the test only needs to prove sanitization does NOT strip these tags.

**Evidence:** `handoff-A-plan.md`.

---
