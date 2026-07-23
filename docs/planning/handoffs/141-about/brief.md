# Brief — #141 MC-2 About section

**Story:** [#141 MC-2: About section](https://github.com/Performant-Labs/groups-on-d11/issues/141) — Epic #137 MVP conformance
**Branch:** `141-about`  **Worktree:** `~/Projects/_worktrees/groups-about`
**Base:** `origin/main` @ 49fe585 (post-#140 MC-1 Links)
**Review rigor:** none (per issue "Review rigor: none"; overnight POC mode also skips brief-gate o4-mini)
**Survey:** `docs/planning/handoffs/141-about/survey.md`
**Reuse map:** survey §"Reuse & Analogous-Feature map" — verdict: **NEW field `field_group_about`**

## Objective

Add an "About" section on the group Full display: a formatted-text body field
(`field_group_about`) rendered below the existing description/image and above Links & Resources.
Seed real About prose on a few groups. Empty state renders nothing.

## Design decision recorded

- **NEW field, not reuse of `field_group_description`.** Description is a required one-liner
  (~10–15 words in seed reality); the spec calls for "richer content beyond the one-line
  description." Overloading description would break its listing/teaser role. New field cost is
  minimal (one YAML pair + display append + seed setter) and lives in `do_group_extras` (no
  module sprawl). See survey §"Decision".

## Scope (Owns — disjoint files)

**New files:**
- `docs/groups/config/field.storage.group.field_group_about.yml` — text_long storage, cardinality 1
- `docs/groups/config/field.field.group.community_group.field_group_about.yml` — instance, label "About", not required, translatable
- `docs/groups/modules/do_group_extras/css/group-about.css` — subtheme CSS for the About section
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php` — mirrors GroupLinksFieldTest
- `tests/e2e/group-about.spec.ts` — anonymous visitor sees About prose; empty state renders no heading

**Edited files (append-only in the reserved slot):**
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` — append `field_group_about` component at reserved weight 10, `label: above`, formatter `text_default`; add dep alphabetically.
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` — append `field_group_about` widget `text_textarea` (formatted); add dep alphabetically.
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` — add `group-about:` library entry mirroring `group-links`.
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — extend `preprocessGroup` to conditionally attach `do_group_extras/group-about` (mirrors the exact links-attach block).
- `docs/groups/scripts/step_700_demo_data.php` — append-only block: set About prose on 2–3 seeded groups with idempotent empty-guard.

**Must NOT touch:**
- `web/modules/custom/**` or `config/sync/**` (gitignored build artifacts — assemble script generates them)
- Any file `do_streams`, `do_showcase`, `do_chrome`, `do_group_membership`, or `do_tests` owns
- `field_group_description`, `field_group_image`, `field_group_links`, `field_group_visibility`, `field_group_type` (siblings; #141 is disjoint)
- The `hidden:` block in the view display
- Section-marker comments for OTHER sections in the display files

## Acceptance criteria (each must be backed by a test T authors)

- [ ] **AC-1** `field_group_about` storage exists as `text_long`, cardinality 1, translatable — **kernel**
- [ ] **AC-2** Instanced on `community_group` bundle with label "About", not required, translatable — **kernel**
- [ ] **AC-3** Group Full display exposes `field_group_about` at weight 10 with `label: above` and formatter `text_default` — **kernel**
- [ ] **AC-4** Group form display exposes `field_group_about` with widget `text_textarea` (non-hidden) — **kernel**
- [ ] **AC-5** Rendering a group with formatted About body (e.g. `<p><strong>bold</strong></p>` in `basic_html`) produces the sanitized rich HTML inside the rendered field wrapper — **kernel**
- [ ] **AC-6** Empty state: a group with NO About body set renders NEITHER the "About" text label NOR a bare `<h2>`/`<label>` wrapper — **kernel** (holds by construction via field's own `label: above`)
- [ ] **AC-7** E2E: anonymous visitor on a seeded group's page sees an "About" heading + prose — **playwright**
- [ ] **AC-8** WCAG 2.2 AA: heading structure (`<h2>` About renders after description, before Links — logical order); readable contrast; no empty landmarks — **U walkthrough (axe)**
- [ ] **AC-9** Existing kernel + functional + E2E suites remain green — **T green pass, CI**
- [ ] **AC-10** Source-only commits (no `web/modules/custom/` or `config/sync/`) — **O verifies pre-PR**

## Test-first outline (T authors RED before F)

**Kernel `GroupAboutFieldTest.php`** (mirrors `GroupLinksFieldTest`):
1. `testStorageExists` — text_long, cardinality 1, translatable
2. `testInstanceExists` — label "About", not required
3. `testFullDisplayShowsField` — component present on group.community_group.default view display, `type=text_default`, `label=above`, weight=10
4. `testFormDisplayShowsField` — widget `text_textarea`
5. `testRendersFormattedBody` — set `[value=>'<p><strong>Hello</strong> world.</p>', format=>'basic_html']`; render; assert `<strong>Hello</strong>` present in HTML
6. `testEmptyStateRendersNothing` — no About set; render; assert no "About" heading

**E2E `group-about.spec.ts`** (mirrors `group-links.spec.ts`):
1. Anonymous visits a seeded group with About prose; asserts About heading + a distinctive phrase from the seeded body
2. Anonymous visits a seeded group with NO About; asserts no About heading

## Coordination with #140 (already merged)

- Display config file is shared: append at weight 10, follow #140's marker convention (`# --- Section: About (weight 10) ---`), replace the `# (weight 10 reserved for #141 About)` placeholder.
- Do NOT reorder other components. Do NOT edit the `hidden:` block.
- Dependencies block: insert `field.field.group.community_group.field_group_about` alphabetically (between the `- field.field.group.community_group.field_group_about` and `- field.field.group.community_group.field_group_description` lines).

## Input documents

- SPEC: `gh issue view 141 --repo Performant-Labs/groups-on-d11`
- Wave handoff: `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md`
- Project context: `docs/workflow/PROJECT_CONTEXT.md`
- Direct plan analogue: `docs/planning/handoffs/140-links/handoff-A-plan.md`
- Direct test analogue: `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php`

## Handoff locations

`docs/planning/handoffs/141-about/`:
- `survey.md` (done)
- `brief.md` (done)
- `decisions.md` (this run's decision journal — append as phases complete)
- `handoff-A-plan.md`, `handoff-T-red.md`, `handoff-F.md`, `handoff-T-green.md`, `handoff-U.md`, `handoff-S.md`

## Namespacing

- Branch: `141-about`
- Worktree: `~/Projects/_worktrees/groups-about`
- Containers (if F/T/U spin any): `gm141-*` — NEVER `docker rm` a sibling's container
