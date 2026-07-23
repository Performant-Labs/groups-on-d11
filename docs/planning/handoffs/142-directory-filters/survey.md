# Survey — #142 Directory location + language filters (v2, post-A amend)

## Files touched
- `docs/groups/config/views.view.all_groups.yml` — add two exposed filters (language via `plugin_id: language`, location via string contains) and optional field display columns.
- `docs/groups/config/field.storage.group.field_group_location_text.yml` — NEW. String storage. (Renamed to avoid #125 geofield collision.)
- `docs/groups/config/field.field.group.community_group.field_group_location_text.yml` — NEW. Instance.
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` — add `field_group_location_text` widget entry (if the file exists in docs/groups/config; else skip).
- `tests/e2e/directory-filters.spec.ts` — NEW.
- `docs/groups/modules/do_tests/tests/src/Kernel/DirectoryFiltersTest.php` — NEW kernel test.

## Reuse & Analogous-Feature map
- **Analogous feature: existing `search` exposed filter on `label`** in the view. EXTEND: add `language` (`plugin_id: language`) and `location` (string, operator `contains`, on `field_group_location_text`).
- **Analogous field: `field_group_description`** (existing string field on group.community_group). EXTEND yml pair pattern.
- **Analogous kernel test:** any view kernel test under `do_tests/tests/src/Kernel/`. Install modules + config, seed groups, switch to anonymous user, execute view, assert.
- **Analogous e2e: `tests/e2e/directory-cards.spec.ts`** already navigates `/all-groups`.

## Downstream forward-compat
- **#125 SC-6 (map view)** owns `field_group_location` (geofield) — resolved by renaming to `field_group_location_text`.
- **#139 MC-4 (multilingual baseline)** owns `field_group_primary_language`. Baseline already has `field_group_language`. #142 uses `field_group_language`; #139 must reconcile.
- **#124 SC-5 (variant switcher)** also edits `views.view.all_groups.yml` — additive within `filters:`/`fields:`.

## Key findings
- `field_group_language` storage + instance already on origin/main; no #139 blocker.
- Location = free-text per issue phase-1; string field, `contains`.
- Group access grants handle exclusion; kernel test must run as anonymous.
- Views language filter plugin is `plugin_id: language`.

## Non-goals
- Map/radius/distance UX (#125).
- Geocoding.
- Language-negotiation changes (#139).
