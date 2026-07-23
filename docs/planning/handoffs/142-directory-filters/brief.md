# Brief — #142 MC-3 Directory Location + Primary-language Filters

**Review rigor:** `none` (POC posture per issue body).

## Objective
Add two combinable exposed filters to `/all-groups`:
1. **Location** — free-text "contains" against a new `field_group_location` string field on group.community_group.
2. **Primary language** — select against existing `field_group_language` (language field, already on origin/main).

Both must combine with each other and with the existing `search` label filter. Group access-control (archived/unlisted/private hidden) MUST be preserved.

## Inputs
- Issue: `gh issue view 142 --repo Performant-Labs/groups-on-d11`
- Survey: `docs/planning/handoffs/142-directory-filters/survey.md`
- Existing view: `docs/groups/config/views.view.all_groups.yml`
- Existing language field: `docs/groups/config/field.storage.group.field_group_language.yml` + instance yml

## Acceptance criteria (each backed by a T-authored test)
- [ ] `views.view.all_groups.yml` declares an exposed filter on `field_group_language` (select, allow multiple OK, empty = all).
- [ ] `views.view.all_groups.yml` declares an exposed filter `location` on `field_group_location` (operator `contains`, exposed, empty = all).
- [ ] New `field_group_location` string field storage + instance on group.community_group.
- [ ] Kernel test asserts view loads, has both exposed filters, executes without error, and archived/unlisted/private groups are NOT in the base result set.
- [ ] Playwright e2e (`tests/e2e/directory-filters.spec.ts`) navigates `/all-groups`, seeds ≥3 groups with distinct locations and languages, applies each filter, verifies filtered result, applies BOTH combined, verifies intersection.
- [ ] `phpcs` clean on all edited/new PHP files.
- [ ] WCAG 2.2 AA: both new form controls have `<label for>`, keyboard operable, visible focus. (Views exposed filters render labeled inputs by default; verify.)
- [ ] Existing suite still green.

## Handoff locations
- `docs/planning/handoffs/142-directory-filters/` — survey, decisions, wireframe (N/A), brief, T-red, F, T-green, U, S.

## Branch
`142-directory-filters` (worktree at `~/Projects/_worktrees/groups-directory-filters`).

## D phase
**N/A — no new UI surface.** Reuses existing exposed-filter chrome. Documented in decisions.md.
