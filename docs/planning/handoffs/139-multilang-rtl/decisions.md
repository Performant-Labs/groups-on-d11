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

## Phase 3 — A (up-front plan review), round 1: BLOCK

**Decided (by A)**
- CONCUR with reuse of `field_group_language`. Creating
  `field_group_primary_language` would be an unjustified parallel path
  → A-dup BLOCK in Phase 7.
- CONCUR with `hook_entity_view` for the `full` view mode and CSS
  co-located in `do_group_language`.
- CONCUR with `getDirection()` API + mixed-direction nesting via `dir`
  on `<span>`.

**Blocked**
- Finding #1 (BLOCK): `views.view.all_groups.yml` line 128 is
  `row: type: fields`. `hook_entity_view` on view_mode `teaser` will
  never fire on `/all-groups`. The Playwright "teaser indicator on
  /all-groups" assertion is architecturally impossible under the
  proposed render approach. Resolution chosen: add `field_group_language`
  as a Views field to `all_groups` (strongest MC-3 forward-compat) and
  drop the `teaser` branch of `hook_entity_view`.

**Advisories rolled in**
- #4: null-language guard when `getLanguage($langcode)` returns NULL.
- #5: step_760 currently only sets language on pre-existing groups; it
  does NOT create groups. New pattern for that file needs explicit
  idempotency contract in the brief.
- #6: Kernel test must declare `field_group_language` as `type: language`
  (production shape), not `type: string` (which is what
  `GroupLanguageNegotiationTest` uses for narrower purposes).

**Actions (O)**
- Amended brief v2: full view mode only; new Views-field deliverable on
  `all_groups`; step_760 idempotency contract spelled out; Kernel test
  storage type pinned to `language`; null-language guard added to
  non-negotiables.
- Re-spawning A on the amended brief.

**Evidence**
- `views.view.all_groups.yml:128` (`row: type: fields`)
- `step_760.php:17-25` (sets language on pre-existing fr/de groups; no
  group creation in file today)
- `GroupLanguageNegotiationTest.php:63-67` (`type: string` for narrower
  test purposes)

---
