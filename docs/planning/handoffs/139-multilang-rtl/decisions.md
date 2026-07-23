# Decision Journal — #139 MC-4 Multilingual baseline + RTL

Append-only. One entry per phase.

---

## Phase 1 — O (survey + brief)

**Decided**
- Reuse existing `field_group_language` (type=language) on
  `community_group`. Do NOT create `field_group_primary_language`. Story
  text mandates extend/align.
- Own the language-indicator render in `do_group_language` via
  `hook_entity_view` (no view-display YAML in `docs/groups/config/` today;
  adding one would race with the assembled site).
- Own CSS at `do_group_language/css/group-language.css` (co-located with
  owning module) rather than `do_chrome/css/`; equivalent, better scoped.
- Seed the RTL-primary group by appending to `step_760.php` (idempotent,
  the runbook step that already seeds fr/de groups).
- Use core `LanguageInterface::getDirection()` — never hardcode
  `dir="rtl"`.
- Review rigor: **none** (per issue text). Skip dual-review gates.

**Assumed**
- The assembled/seeded site's Full + teaser view displays render fields
  the module hook targets (view_mode == 'full' or 'teaser'). If the site
  is currently using a custom template that swallows extra render array
  keys, the hook needs a different injection point; T's kernel test will
  catch this before F ships.
- Arabic (`ar`) is installed as RTL in core — verified via `step_640.php`
  which adds `ar`, and Drupal core marks it RTL by default.

**Hedged**
- If `hook_entity_view` on `full`/`teaser` fails to reach the rendered
  page (e.g. minimal group template omits `#pre_render` output), fall
  back to a small `do_group_language_theme_suggestions_group()` +
  template override, or a preprocess. Decision deferred to F if T
  demonstrates the hook is invisible.

**Evidence**
- `docs/planning/handoffs/139-multilang-rtl/survey.md`
- `field.field.group.community_group.field_group_language.yml`
- `do_group_language/src/Plugin/LanguageNegotiation/LanguageNegotiationGroup.php`
- `scripts/step_760.php`, `scripts/step_640.php`

---
