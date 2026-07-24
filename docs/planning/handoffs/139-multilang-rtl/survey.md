# Survey — #139 MC-4 Multilingual baseline + RTL

Branch: `139-multilang-rtl` · Worktree: `~/Projects/_worktrees/groups-multilang-rtl`
Spec: `gh issue view 139 --repo Performant-Labs/groups-on-d11`

## Existing state (verified in repo)

### Field — REUSE, do NOT create `field_group_primary_language`

`field_group_language` already exists as a `type: language` field on
`community_group`, cardinality 1, translatable, module `core`:

- `docs/groups/config/field.storage.group.field_group_language.yml`
- `docs/groups/config/field.field.group.community_group.field_group_language.yml`
- Form-display component wired (`step_760.php`, `language_select` widget, weight 20)
- Also asserted deliberately-off on the group ADD form (`do_tests/tests/src/Functional/GroupAddFormFieldsTest.php:213`)
  — added post-creation via the edit form, not on the add form.

Consumers already relying on it:
- `do_group_language\Plugin\LanguageNegotiation\LanguageNegotiationGroup` (weight -5)
  reads it from `/group/{gid}` paths.
- `step_760.php` sets `Drupal France => fr`, `Drupal Deutschland => de`.
- `feature_tour_drupalorg/FEATURE_TOUR.md:154` and `RUNBOOK.md` document it as
  "the group's primary language".

Renaming or duplicating this field would fork the negotiation plugin, break
step_760 assertions, and duplicate what MC-3 will filter on. **Reuse it.**

### Languages installed

`step_640.php` adds 14 languages including `ar`. `ar` is RTL, so the site
already has an RTL langcode wired — no new language install needed.

### Language negotiation

`language.types.yml` has `language-group` weight -5 (below user, above URL).
The plugin is registered by `do_group_language`. Interface direction on
`/group/{gid}` will follow `field_group_language`. Kernel test:
`do_group_language/tests/src/Kernel/GroupLanguageNegotiationTest.php`.

### Displays

No `core.entity_view_display.group.community_group.*` exists in
`docs/groups/config/` — the Full + teaser view displays live only in the
running site's active config (assembled/seeded). Adding view-display YAML
would race with the assembly. Instead we render the language indicator via
`hook_entity_view()` on the `group` entity, keyed on view_mode `full` /
`teaser`.

### CSS home

`docs/groups/modules/do_chrome/css/` contains `do_chrome.css`, `tippy.css`.
Attaching a new library `do_group_language/indicator` (with a CSS file inside
`do_group_language/css/group-language.css`) keeps the RTL rules co-located
with the module that owns the field — cleaner than adding to `do_chrome`.
Story text mentions "subtheme CSS `.../css/group-language.css` (new; RTL
rules)"; owning it in `do_group_language` is equivalent and better scoped.

### Seeds

`step_760.php` seeds French + German groups. Append an RTL-primary group
("Drupal العربية") with `field_group_language = ar` in `step_760.php`
(append-only edit). Add 1–2 Arabic forum posts for realistic body content.

### E2E baseline

`tests/e2e/*.spec.ts` uses Playwright with `page.goto()` against the seeded
site, `ADMIN_USER`/`ADMIN_PASS` env vars, and reads DOM. New spec
`group-language.spec.ts` follows this shape.

## Reuse & Analogous-Feature Map

| Concern | Analogous object | Extend / New | Justification |
|---|---|---|---|
| Group-primary-language field | `field_group_language` (existing) | **Extend (reuse as-is)** | Already exists, seeded, and consumed by `LanguageNegotiationGroup`. Renaming/duplicating breaks step_760, tests, and MC-3. |
| Language indicator render | none — new render surface | **New** (`hook_entity_view` in `do_group_language`) | No display config exists in source; hook keeps it declarative in the same module that owns the field. |
| RTL styles | none in `do_chrome` | **New** (`do_group_language/css/group-language.css`) | New surface (the indicator span + dir="rtl" scoping). Co-located with owning module. |
| Seed content | `step_760.php` (existing) | **Extend (append)** | Story requires append-only seed edit. Same file already seeds fr/de. |
| Playwright coverage | `tests/e2e/*.spec.ts` pattern | **New spec** (`group-language.spec.ts`) | Story-owned file; convention matches existing specs. |
| Kernel coverage | `GroupLanguageNegotiationTest.php` (existing) | **New sibling test** | Existing test covers negotiation. Add `GroupLanguageIndicatorTest.php` for the new `hook_entity_view` render output — separate concern. |

## Forward-compat check

- **MC-3 directory language filter** (blocked by this story): consumes
  `field_group_language` on teaser render. Our hook_entity_view emits the
  indicator on both `full` and `teaser` view modes → MC-3 can either read
  the field directly (Views filter) or scrape the indicator. Compatible.
- **Assembled layout** (`scripts/ci/assemble-config.sh`): source lives
  under `docs/groups/modules/do_group_language/` and `docs/groups/scripts/`.
  Assembly copies to `web/modules/custom/` — no changes to sync layout.

## Gotchas caught

- `do_tests/tests/src/Functional/GroupAddFormFieldsTest.php:213` asserts
  `field_group_language` is NOT on the ADD form. Our changes MUST NOT
  regress that; the field is edit-form-only. Do not touch the group-add
  form display.
- `language-group` plugin must remain registered — do not restructure the
  negotiation plugin.
- `step_640.php` `language.content_settings` malformed-config trap
  (RUNBOOK §2578) is orthogonal; do not touch step_640.
- `ar` in Drupal is a right-to-left language (core-defined). Its `direction`
  is `LanguageInterface::DIRECTION_RTL` — do NOT hardcode `dir="rtl"` in
  the hook; read from `\Drupal::languageManager()->getLanguage($langcode)
  ->getDirection()` so mislabeled langcodes fall back correctly.
- Existing Kernel test creates a group programmatically without saving
  field storage first — it declares storage inline. Follow that pattern
  for the new render test.

## Deliverables to produce

1. `docs/groups/modules/do_group_language/do_group_language.module` (**new**)
   — `hook_entity_view` for `group` entity, view_modes `full` + `teaser`,
   emitting `<span class="do-group-language" lang="{code}"
   dir="{direction}">{native_name}</span>`. Attaches
   `do_group_language/indicator` library.
2. `docs/groups/modules/do_group_language/do_group_language.libraries.yml`
   (**new**) — declares `indicator` library pointing at CSS.
3. `docs/groups/modules/do_group_language/css/group-language.css` (**new**)
   — base indicator styles + RTL scoping.
4. `docs/groups/scripts/step_760.php` (**append**) — add an Arabic-primary
   community group + 1–2 Arabic forum posts.
5. `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`
   (**new**) — asserts hook_entity_view render output for RTL and LTR.
6. `tests/e2e/group-language.spec.ts` (**new**) — RTL/LTR assertions.
