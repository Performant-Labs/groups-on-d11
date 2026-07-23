# Brief — #142 MC-3 Directory Location + Primary-language Filters (v2, post-A amend)

**Review rigor:** `none` (POC posture per issue body).

## Objective
Add two combinable exposed filters to `/all-groups`:
1. **Location** — free-text "contains" against a new `field_group_location_text` string field on group.community_group. (Renamed from `field_group_location` to avoid collision with #125 SC-6 which owns that name for a **geofield**.)
2. **Primary language** — select against existing `field_group_language` (language field, already on origin/main). Uses views filter `plugin_id: language`.

Both must combine with each other and with the existing `search` label filter. Group access-control (archived/unlisted/private hidden) MUST be preserved.

## Amendments applied from A's BLOCK review
- **Field renamed:** `field_group_location` → `field_group_location_text`. Filenames become `field.storage.group.field_group_location_text.yml` + `field.field.group.community_group.field_group_location_text.yml`. #125's geofield-typed `field_group_location` stays untouched.
- **Language field choice:** use baseline `field_group_language` (already on origin/main). #139 owns a *separate* `field_group_primary_language` — overnight-mode decision: proceed with `field_group_language`. #139's issue text itself says "verify vs `do_group_language`; reuse if it already provides this" — consistent with this call. Documented as an Open Assumption for the Chain Summary.
- **Filter plugin_id pinned:** language filter uses `plugin_id: language` (Drupal core Language filter).
- **Access-safety test:** kernel test runs as anonymous user (never UID 1) when asserting archived/unlisted/private exclusion.
- **Form/view display collisions with #125:** dissolved by the rename.

## Inputs
- Issue: `gh issue view 142 --repo Performant-Labs/groups-on-d11`
- Survey: `docs/planning/handoffs/142-directory-filters/survey.md`
- A handoff: `docs/planning/handoffs/142-directory-filters/handoff-A-plan.md`
- Existing view: `docs/groups/config/views.view.all_groups.yml`
- Existing language field: `docs/groups/config/field.storage.group.field_group_language.yml` + instance

## Acceptance criteria (each backed by a T-authored test)
- [ ] `views.view.all_groups.yml` declares an exposed filter on `field_group_language` using `plugin_id: language`, exposed.
- [ ] `views.view.all_groups.yml` declares an exposed filter `location` on `field_group_location_text` (operator `contains`, exposed, empty = all).
- [ ] New `field_group_location_text` string field storage + instance on group.community_group.
- [ ] Kernel test asserts view loads, has both exposed filters with correct plugin_ids, executes as **anonymous user**, and archived/unlisted/private groups do NOT appear in the base result set.
- [ ] Playwright e2e (`tests/e2e/directory-filters.spec.ts`) navigates `/all-groups`, seeds ≥3 groups with distinct locations and languages, applies each filter, verifies filtered result, applies BOTH combined, verifies intersection.
- [ ] `phpcs` clean on new PHP files.
- [ ] WCAG 2.2 AA: both new form controls labeled, keyboard operable, visible focus.
- [ ] Existing suite still green.

## Handoff locations
`docs/planning/handoffs/142-directory-filters/`

## Branch
`142-directory-filters` (worktree `~/Projects/_worktrees/groups-directory-filters`).

## D phase
N/A — no new UI surface.
