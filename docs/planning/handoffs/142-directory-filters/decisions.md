# Decision Journal — #142 Directory location + language filters

Run slug: `142-directory-filters` · Started 2026-07-22 · Overnight autonomous mode.

## O — Phase 1 (survey + brief)

**Decided**
- Skip D. Story adds two exposed filters to an existing view; no new UI surface — reuses the existing exposed-filter chrome (search input). Lean POC pipeline judgment.
- Location filter = phase-1 free-text "contains" per issue text ("phase-1 free-text 'contains' filter (per MVP location strategy)"). NOT geofield-based, despite geofield being in composer. Free-text matches the acceptance bar, is simpler, and defers the map/radius UX to a later phase.
- New field: `field_group_location` (string, cardinality 1, on group entity, community_group bundle). Add storage + instance + form/view display entries.
- Language filter uses existing `field_group_language` (baseline field, storage on origin/main). No dependency on #139 merge.
- Both filters exposed on `/all-groups`, combinable with the existing `search` label filter.

**Assumed**
- Existing Group access-control (archive/unlisted/private exclusion) applies through the view's base_table + status filter + Group's access grants; no filter change alters that. Verified by A/T.
- `field_group_language` is populated on the demo groups seeded by `do_tests` / `do_showcase` fixtures — if not, T seeds it.

**Evidence**
- Issue #142 body (Acceptance + Scope).
- `docs/groups/config/views.view.all_groups.yml` (current shape, one exposed `search` filter on label).
- `docs/groups/config/field.storage.group.field_group_language.yml` + `field.field.group.community_group.field_group_language.yml` present on origin/main (baseline).
- #139 worktree at `~/Projects/_worktrees/groups-multilang-rtl` adds a view **field** display for language and other multilingual scaffolding — its wiring does not conflict with adding a view **filter** on the same field.

**Hedged**
- If S/A reject free-text and demand geofield distance-radius, that is a scope expansion beyond POC phase-1 per issue — escalate.
