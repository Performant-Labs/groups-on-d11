# Survey — #140 MC-1 Links & Resources

## Files the phase will touch (all NEW except *)

**New source config**
- `docs/groups/config/field.storage.group.field_group_links.yml` (new — Link field storage; cardinality -1)
- `docs/groups/config/field.field.group.community_group.field_group_links.yml` (new — bundle instance)
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` *(edit if exists — currently exists at that path for the form display; add the widget for `field_group_links`)*
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` (new — group Full display, currently NONE exists; #141 About will append here)

**do_group_extras module** (existing — extend, don't create a new module — Reuse rule)
- `docs/groups/modules/do_group_extras/do_group_extras.info.yml` — add `link` module dependency
- `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` — add `group-links` library
- `docs/groups/modules/do_group_extras/css/group-links.css` (new)
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — attach library on group Full display via `preprocess_group` or theme_group; add `rel="noopener noreferrer"` + `target="_blank"` to external link items

**Seed data**
- `docs/groups/scripts/step_700_demo_data.php` — append-only "Step 735: seed group links" section (2–4 links on ~3 seeded groups)

**Tests**
- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` (new — asserts storage/instance shape, empty state renders nothing, external link gets `rel="noopener"`)
- `tests/e2e/group-links.spec.ts` (new — anonymous visits a seeded group's Full page and asserts the Links section + a known link title/href)

## Layout inheritance / neighbors

- Group entity `community_group` bundle exists (created via `docs/groups/config/group.type.community_group.yml`).
- Existing group fields for shape reference: `field_group_description` (text_long), `field_group_image`, `field_group_type` (taxonomy), `field_group_visibility` (list_string), `field_group_language`.
- **NO group Full display file exists yet** — this story creates it. Downstream #141 About will ALSO edit it. We must lay it out with clearly-marked section blocks (`# --- Section: Links & Resources (MC-1 #140) ---`) so #141 can slot About above/below cleanly.
- `do_group_extras` module already implements `hook_form_alter` / `hook_entity_presave` for community groups — the natural home for the render adjustment.
- Existing library pattern: `do_group_membership/css/manage-members.css` attached via `#attached` in a controller/preprocess.

## Reuse & Analogous-Feature map

**Closest analogue:** `field_group_description` — a bundle-level field on the `community_group` group entity. Same shape (storage yml + instance yml), same rendering surface (Full display).
- **Extend, don't create:** use existing `do_group_extras` module for the small render tweak (rel/noopener). Do NOT spin a new `do_group_links` module — no earned complexity.
- **Field type:** `link` (core Drupal `link` module) — provides both `uri` and `title` per delta natively. Cardinality `-1` (unlimited).
- **Widget:** `link_default` (title required; URL required; external allowed).
- **Formatter:** `link` (formatter type `link`) with `trim_length: 80`, `url_only: false`, `url_plain: false`, `rel: noopener`, `target: _blank`. `rel: noopener` is Drupal-core's built-in setting — we prefer this over PHP-side manipulation.

## Forward-compat check — #141 About

#141 About adds `field_group_about` (text_long) on the same bundle, also rendered on the group Full display. The Full display yml is the **only** shared file. Solution: create it with explicit numbered/named region markers so #141 can insert a stanza without touching MC-1's stanza. Sections comment blocks + stable field weights spaced 10 apart (Description=0, Links=20 — About expected at 10). #141 rebases on merged #140.

## Key findings / risks

1. **`link` module** must be enabled — add to `core.extension` via `do_group_extras.info.yml` dependency (assemble-config auto-registers this).
2. `do_tests`/GroupsKernelTestBase pattern (see `GroupExtrasBehaviorTest`) is the right base for the kernel test; add `field`, `link`, `text` to `$modules`.
3. E2E must run against seeded data — assemble → install → import → seed → runserver (per WAVE §6-6). Playwright asserts on a known seeded group's rendered link (title text + href).
4. **CI runs assembled layout** (WAVE §6-1). Kernel fixtures must be module-local; source-relative reads of `docs/groups/config/*` break.
5. The Full display file, when new, has no route collision (unlike #138's group_members) — no hook_install strip needed.
6. `rel="noopener"` — core link formatter has a `rel` setting; setting to `noopener` yields `rel="noopener"` on external URLs. External detection is core's `LinkFormatter::viewElements()` behavior.
