# Handoff-A: Phase 3 — #140 MC-1 Links & Resources  (up-front plan review)

**Date:** 2026-07-22
**Branch:** 140-links   **Worktree:** ~/Projects/_worktrees/groups-links
**Brief reviewed:** docs/planning/handoffs/140-links/brief.md
**Reuse map:** docs/planning/handoffs/140-links/survey.md §"Reuse & Analogous-Feature map"
**Wireframe:** N/A (D skipped per decisions.md; convention-bound render)
**Verdict:** PASS (with 3 warns to close during T RED authoring)

## Summary

The plan is architecturally correct on every dimension I audit. Extending `do_group_extras`
(rather than a new module) matches the reuse rule and the module's charter — its existing hooks
already run on the community_group form + preprocess_group render, so the render tweak lands on
the exact seam it belongs on. Field-type choice (core `link`, cardinality -1, `link_default`
widget, core `link` formatter) mirrors `field_group_description`'s shape and is the idiomatic
Drupal 11 answer — no earned complexity for a paragraph or entity-ref detour. The plan correctly
identifies that no `entity_view_display.group.community_group.default.yml` exists today, so
this story creates the shared file and #141 rebases onto it. The Full-display coordination scheme
(commented section markers + weights 0/10/20) is a defensible answer to the one real merge-risk
surface between #140 and #141. Three items need pinning down during T's RED pass, but none are
BLOCK-worthy.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | pass | Extend `do_group_extras` | reuse / layering | Correct home. Module already owns community_group form_alter + preprocess_group; adding link-render polish there is the same-seam extension the reuse map calls for. Not a parallel path. | — |
| 2 | pass | Field type = core `link`, cardinality -1 | abstraction level | Native title+URL per delta; matches `field_group_description`'s config-only shape. Paragraph/entity-ref would be over-abstraction for a POC "list of links". | — |
| 3 | pass | Create `core.entity_view_display.group.community_group.default.yml` in this story | route/config collision | Confirmed no such file exists in `docs/groups/config/` today (only `.group_relationship.community_group-group_membership.default.yml` exists). Group 4.x contrib does not ship an `entity_view_display.group.*.default` in `config/optional` for arbitrary bundles — display is materialized on-demand by the entity display system when a bundle is created. Unlike #138's `views.view.group_members` trap (WAVE §183), there is no shipped view-display config to strip. **No hook_install strip needed**; the plan is right to omit one. | Cheap belt-and-suspenders: during F GREEN, `drush cex --diff` after `drush en link do_group_extras` and confirm the file created is exactly the one we shipped. |
| 4 | warn | Reserved-weight scheme Desc=0, About=10, Links=20 with commented section markers | pattern consistency / merge-safety | YAML `content:` is a dict keyed by field_name, so #141 inserts a new key (`field_group_about`) at a different line — three-way merge on the yml is trivially clean **as long as neither story reorders sibling keys or edits hidden/dependencies blocks around the About slot**. The commented `# --- Section: Links & Resources (MC-1 #140) ---` markers are cosmetic to YAML parsers and won't survive `drush cex` round-trips (Drupal rewrites config export without preserving comments). They help human reviewers reading the source-tree yml but disappear the moment anyone re-exports. | (a) State plainly in the yml header comment that markers are source-tree-only and are stripped on `drush cex` — so no one panics when they vanish. (b) Also list `field_group_links` in the `dependencies.config` block sorted alphabetically so #141 inserting `field.field.group.community_group.field_group_about` doesn't require re-sorting siblings. (c) Do not touch `hidden:` unless a field must be hidden; leaving it empty removes another line #141 could conflict on. |
| 5 | warn | `rel="noopener"` via core `link` formatter | cross-cutting / hedge specificity | Drupal 11 core `LinkFormatter` (`core/modules/link/src/Plugin/Field/FieldFormatter/LinkFormatter.php`) does honor a `rel` setting and emits it on external URLs when configured, but the setting shape is a single string (historically only `nofollow` was documented) and the formatter renders it verbatim via `$element['#options']['attributes']['rel']`. In practice `rel: noopener` yields `rel="noopener"` on external anchors. `target: _blank` works the same way. The plan's hedge ("if not, add a preprocess fallback") is fine but under-specified. | Have T author the kernel test against the **observable HTML** (`rel="noopener"` attribute present on external anchor; absent or benign on internal), not against the formatter config shape. Then F is free to satisfy it via formatter settings first and only fall back to a `preprocess_field__field_group_links` (or a `#[Hook('preprocess_field')]` method on `DoGroupExtrasHooks`) if the formatter output is wrong. Do NOT add the preprocess hook speculatively — earn it via a red test. |
| 6 | warn | Empty state hidden via "hide_empty on entity_view_display" | pattern consistency | The core `link` field's formatter does emit nothing when the field has zero deltas, but the render array around it (the field label wrapper) is controlled by the display's `label` setting on that field — with `label: hidden` there is no bare header. However the plan describes a `<section><h2>Links & Resources</h2>` wrapper, which is **not** produced by the field formatter itself. If the intended H2 comes from the field label (`label: above`), then an empty field still renders nothing because Drupal's field render suppresses the whole wrapper when items are empty. If the H2 is meant to come from a template/preprocess **outside** the field, that wrapper would render even when empty and needs its own guard. | Pick one and encode it in the kernel test. Recommended: use the field's own label (`label: above`, text "Links & Resources") — then empty-state is free, no template override needed, and the acceptance criterion ("no bare header when empty") holds by construction. If the design later demands a `<section>` wrapper beyond the field label, add it via `hook_preprocess_group` with an `if (!$group->get('field_group_links')->isEmpty())` guard and cover the empty case in kernel. |
| 7 | pass | Test tiers: kernel for shape/rel/empty; E2E for seeded-visible | test-first shape | Right split. Kernel covers config presence, formatter output HTML, and empty-state suppression cheaply against a bare group entity. E2E covers the seeded-through-install path (WAVE §6-6). No functional-test tier needed — the form-widget presence is a config assertion (kernel-level), and there's no complex auth/access matrix that would justify a BrowserTestBase run. Unit tests would have no target (no service class introduced). | — |
| 8 | pass | Anti-duplication scan | drift risk | `do_streams`, `do_showcase`, and `do_chrome` all operate on separate surfaces (stream/views, tour ribbon, tooltip copy) — none owns the group Full display. HelpText.php in `do_chrome` is a tooltip copy source, not a generic user-facing-surface registry; the brief line "HelpText append-only if any user-facing surface added" is a conditional and does NOT apply here (Links & Resources renders inline content with visible titles, not a tooltip surface). Skipping HelpText for this story is correct. | Confirm in F's handoff that no HelpText.php entry was added. |

## Notes for O

None — verdict is PASS. Pass the three warns (#4, #5, #6) to T as "encode the observable
behavior in RED tests; do not lock the mechanism". They are guardrails, not corrections.

Two small housekeeping asks for T/F to keep in mind:

- Kernel test's `$modules` array must include `link`, `field`, `text`, `user`, `group`,
  `do_group_extras` (mirror `GroupExtrasBehaviorTest`).
- The `do_group_extras.info.yml` `dependencies:` addition should be `- drupal:link` (matching
  the existing `- drupal:taxonomy` / `- drupal:group` style), not bare `- link`.

## Patterns referenced

- `docs/groups/config/field.storage.group.field_group_description.yml` — storage shape analogue
- `docs/groups/config/field.field.group.community_group.field_group_description.yml` — instance shape analogue
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` — existing `#[Hook]` attribute pattern and preprocess_group seam
- `docs/groups/modules/do_group_extras/do_group_extras.info.yml` — dependency style precedent
- `docs/groups/modules/do_chrome/src/HelpText.php` — confirms HelpText is a tooltip registry, not general-purpose
- `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` lines 177-183, 315 — #138 group_members trap; assembled-layout gotcha
