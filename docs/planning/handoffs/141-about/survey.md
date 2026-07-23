# Survey — #141 MC-2 About section

**Date:** 2026-07-23  **Branch:** 141-about  **Worktree:** ~/Projects/_worktrees/groups-about
**Base:** origin/main @ 49fe585 (post #140 MC-1 Links merge)

## Files I read

- `gh issue view 141` — spec
- `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §4–7 — pipeline + gotchas
- `docs/workflow/PROJECT_CONTEXT.md`
- `docs/planning/handoffs/140-links/handoff-A-plan.md` — direct analogue plan review
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` (post-#140) — collision surface
- `docs/groups/config/field.storage.group.field_group_description.yml` — analogue for a text_long storage
- `docs/groups/config/field.field.group.community_group.field_group_description.yml` — analogue instance
- `docs/groups/scripts/step_700_demo_data.php` — seed reality (descriptions are ~10-15 word one-liners)
- `docs/groups/modules/do_group_extras/` — all files (module owns the display and preprocess seam)
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` — direct T template
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — existing #[Hook] pattern

## Current state (post-#140)

- `field_group_description` (text_long, required, cardinality 1) exists at weight 0 on the group Full
  display, `label: hidden`. Seed populates it with **one-line** blurbs (~10–15 words).
- `field_group_links` (link, cardinality -1) added by #140 at weight 20, `label: above` ("Links &
  Resources").
- **Weight 10 is explicitly reserved for #141 About** — comment marker already in place at line 50.
- Display file dependencies block already lists field_group_description, field_group_image,
  field_group_links (alphabetized). #141 must insert `field.field.group.community_group.field_group_about`.
- `do_group_extras.info.yml` already depends on `drupal:link`, `drupal:group`, `drupal:taxonomy`.
  `drupal:text` is available via core; the storage `.yml` will declare `module: text` dependency.

## Reuse & Analogous-Feature map

| Concern | Closest analogue | Extend or new? | Justification |
|---|---|---|---|
| Field storage | `field.storage.group.field_group_description.yml` (text_long) | **new** (`field_group_about`) | Spec explicitly calls for "richer content **beyond the one-line description**." Seed reality: descriptions ARE one-liners. Overloading description would break its one-line semantics. |
| Field instance | `field.field.group.community_group.field_group_description.yml` | **new** (mirror shape) | Same. Label "About", `required: false`, `translatable: true`. |
| Full display component | `field_group_description` @ weight 0, and `field_group_links` @ weight 20 (label: above, section H2 by construction) | **extend** the existing display file | Only one Full display file — must APPEND at reserved weight 10, mirror #140's `label: above` "About" strategy so empty-state renders nothing by construction. |
| Form widget | text_default widget on `field_group_description` (implied — form display config) | **extend** form display | Add `field_group_about` widget (text_textarea, formatted). |
| Hook seam (render) | `DoGroupExtrasHooks::preprocessGroup` (attaches libraries conditionally on `default` view mode + non-empty field) | **extend** the same method | Attach `do_group_extras/group-about` library only on Full display when `field_group_about` non-empty (mirrors #140's exact pattern). |
| CSS | `do_group_extras/css/group-links.css` + library entry | **new sibling file** `css/group-about.css` + library entry `do_group_extras/group-about` | Same shape as #140. |
| Kernel test | `tests/src/Kernel/GroupLinksFieldTest.php` | **new sibling** `GroupAboutFieldTest.php` | Direct template — copy-adapt each test (storage exists, instance shape, Full/form display present, formatted body renders sanitized, empty state renders nothing). |
| E2E test | `tests/e2e/group-links.spec.ts` | **new sibling** `tests/e2e/group-about.spec.ts` | Same shape. |
| Seed data | `step_700_demo_data.php` (already writes descriptions per-group) | **extend append-only** | Add one more setter per group in a new append block that assigns About prose to a subset (2–3) of the seeded groups; idempotent guard: only set if empty. |

## Decision: NEW field `field_group_about` (not reuse of description)

**Verdict: NEW field.** Spec text: *"richer descriptive content **beyond** the one-line description."*
Seed reality confirms description is a one-liner. Reusing it would either:
(a) force description to become long-form (breaking its role in listings/teasers), or
(b) leave the About section semantically identical to description with no visible difference.

New field, cost: one YAML pair + one form/display append + one seed setter. Field sprawl concern
noted in issue is addressed by keeping this in `do_group_extras` (not a new module) and mirroring
#140's exact idiom.

## Forward-compat check

Downstream stories that may touch the same display file:
- **#142** directory location/language filters — touches Views, not the group Full display. No conflict.
- **#143** archive-restore — touches `field_group_type` handling and restore action, not display component list. No conflict.
- **#144** create→auto-organizer — touches membership/role wiring on create, not display. No conflict.
- **#145** WCAG audit — will read the assembled display; About section must pass axe (heading level, contrast).

No forward-compat conflicts. About stays at weight 10; the reserved slot #140 left for it.

## Coordination with #140

- **Same file edit:** `docs/groups/config/core.entity_view_display.group.community_group.default.yml`.
- **Marker convention (from #140):**
  - `# --- Section: <name> (weight N) ---` comment above each field component.
  - `# (weight N reserved for #<issue> <name>)` placeholder comments for future stories.
  - Comments strip on `drush cex` — treat source-tree YAML as the ordering signal of truth.
- **Ordering:** description=0, about=10, visibility=1 (kept above about — pre-existing), image=2, links=20.
  Because weight 10 is between visibility (1) and image (2)? Yes — check: 0, 1, 2, 10, 20. About at 10
  renders BELOW image. That is correct per the description-first / image-hero / about-body / links-CTA
  narrative flow. Keep it.
- **Dependencies block:** insert `field.field.group.community_group.field_group_about` alphabetically
  (after `field_group_about` comes before `field_group_description`).

## Key findings / warns to encode in T RED

1. **Body body markup sanitization** — text_long via `basic_html` format runs through Filter; assert
   the rendered anchor from a sanitized `<p><strong>...</strong></p>` fixture appears in HTML.
2. **Empty state by construction** — mirror #140's warn #6: use field's own `label: above` "About"
   so Drupal suppresses the entire wrapper on zero deltas. Kernel test asserts no "About" heading in
   an empty-field render.
3. **Preprocess library attach guard** — mirror #140 exactly: `view_mode === 'default'`,
   `hasField('field_group_about')`, `!->isEmpty()`.
4. **Form widget** — `text_textarea` widget (not `text_textarea_with_summary` — description doesn't
   use summary either). Kernel test asserts widget type.
5. **Formatter** — `text_default` (mirrors description). NOT `text_summary_or_trimmed` (would defeat
   the "richer content" purpose).

## Test tiers

- **Kernel** (`GroupAboutFieldTest.php`, mirrors GroupLinksFieldTest): storage shape, instance shape,
  Full display component, form display widget type, formatted rendering with sanitization, empty state.
- **E2E** (`group-about.spec.ts`, mirrors group-links): anonymous visitor sees About prose on a
  seeded group; empty-state group renders no About heading.
- **No Functional tier needed** — form widget presence is a config assertion (kernel).
- **No Unit tier** — no service class introduced.
