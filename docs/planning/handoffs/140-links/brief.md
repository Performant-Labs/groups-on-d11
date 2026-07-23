# Brief — #140 MC-1 Links & Resources field + rendering

**Epic:** #137 MVP conformance (POC). **Branch:** `140-links`. **Worktree:** `~/Projects/_worktrees/groups-links`. **Review rigor:** none (diff-gate only per overnight mode).

## Objective

Add a multi-value `field_group_links` (Drupal core `link` field: title + URL) on the `community_group` group entity, expose it on the create/edit form, render a "Links & Resources" section on the group Full display, seed 2–4 plausible links on ~3 seeded groups, ensure external links open safely with `rel="noopener"`, and render nothing (no bare header) when empty.

## Survey

`docs/planning/handoffs/140-links/survey.md`. **Reuse recommendation: EXTEND** `do_group_extras` for any minimal PHP; use `field_group_description` as shape analogue for storage/instance. **No new module.**

## Inputs

- Spec: `gh issue view 140 --repo Performant-Labs/groups-on-d11` (re-read every phase)
- Wave handoff: `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §4, §6, §7
- Analogue: `docs/groups/config/field.{storage,field}.group.{,community_group.}field_group_description.yml`
- Seed pattern: `docs/groups/scripts/step_700_demo_data.php` (group creation lines 70–110)
- Kernel-test base: `GroupsKernelTestBase` used by `GroupExtrasBehaviorTest`

## Acceptance criteria (each testable)

- [ ] `field_group_links` storage exists (entity_type=group, type=link, cardinality=-1) — kernel asserts.
- [ ] `field_group_links` instance on `community_group` bundle (label "Links & Resources", not required, title required per delta) — kernel asserts.
- [ ] Organizer/editor form shows the field (form display) — functional or kernel asserts widget config.
- [ ] Group Full display renders a "Links & Resources" section with the field — kernel asserts formatter config; E2E asserts DOM.
- [ ] External links in rendered output carry `rel="noopener"` and `target="_blank"` — kernel or E2E asserts.
- [ ] Empty state renders nothing (no bare "Links & Resources" header) — kernel asserts rendered output on a group with no links has no section markup.
- [ ] Seeded demo groups (≥3) show ≥2 links each — E2E asserts a known link title/href on a known seeded group.
- [ ] WCAG 2.2 AA: links have discernible names (title used as accessible name; not "click here") — U walkthrough verifies.
- [ ] Existing kernel + functional suites remain green (assembled layout).
- [ ] `bash scripts/ci/assemble-config.sh` exits 0 and includes the new files.

## Handoff locations

- Decisions journal (append every phase): `docs/planning/handoffs/140-links/decisions.md`
- Per-phase handoffs: `docs/planning/handoffs/140-links/handoff-<phase>.md`
- Kept post-merge: `survey.md`, `brief.md`, `decisions.md`

## Coordination

- **#141 About** will edit the same `core.entity_view_display.group.community_group.default.yml` — create it with clearly marked section blocks and spaced weights (Description=0, About=10 reserved, Links=20) so #141 rebases cleanly.
- **HelpText append-only** if any user-facing surface added: add one entry in `do_chrome/src/HelpText.php` (append at end of relevant map — do not reorder).

## Model discipline

D=N/A (skipped, see decisions.md). T, F, U = `sonnet` explicitly. A, S inherit Opus from frontmatter.

## Non-goals

- Not touching #141's `field_group_about`.
- Not doing production hardening (POC).
- Not adding a new module.
- No PR-comment / review bot (repo has none).
