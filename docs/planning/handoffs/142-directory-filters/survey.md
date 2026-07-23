# Survey — #142 Directory location + language filters

## Files touched
- `docs/groups/config/views.view.all_groups.yml` — add two exposed filters (language, location) and add `field_group_location` field display column.
- `docs/groups/config/field.storage.group.field_group_location.yml` — NEW. String storage.
- `docs/groups/config/field.field.group.community_group.field_group_location.yml` — NEW. Instance.
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` — add `field_group_location` widget (if this file already tracks it; else edit-in-place minimal).
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` — add field display (optional; if not shown by default, skip and only expose in view).
- `tests/e2e/directory-filters.spec.ts` — NEW.
- `docs/groups/modules/do_tests/tests/src/Kernel/…` — NEW kernel test asserting view has both exposed filters and access-safety preserved.

## Reuse & Analogous-Feature map
- **Analogous feature: existing `search` exposed filter on `label`** in the same view. **RECOMMEND EXTEND:** copy that structure to add `language` (select from active languages) and `location_op` (contains, on field_group_location).
- **Analogous field: `field_group_description`** (existing string-ish field on group.community_group). **RECOMMEND EXTEND** pattern (storage + instance yml pair) for `field_group_location`.
- **Analogous kernel test: any existing view kernel test under `do_tests/tests/src/Kernel/`** — mirror shape (load view, assert filters present, run and assert results).
- **Analogous e2e: `tests/e2e/directory-cards.spec.ts`** (already exercises `/all-groups`) — same nav/setup, add filter interactions.

## Downstream forward-compat
- SC-5 #124 and SC-6 #125 also edit `views.view.all_groups.yml` per issue body. Merge-order note: this story adds filter blocks + field display; it does NOT alter existing style/format/row plugin. Additive within `filters:` and `fields:` sections. Non-conflicting if others land first (they add sorts / display variants).

## Key findings
- `field_group_language` storage + instance already on origin/main; no #139 blocker.
- Location per issue = free-text; use string field, filter `operator: contains`, `expose: true`.
- Group access grants handle archived/unlisted/private exclusion — filter additions don't touch access.
- Existing view already has `status = 1` filter; keep it.

## Non-goals for this story
- Map/radius/distance UX (phase-2, out of MVP).
- Geocoding.
- Language-negotiation changes (that's #139).
