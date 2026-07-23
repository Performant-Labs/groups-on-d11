# Handoff-A-dup: Phase 7 — #140 MC-1 Links & Resources  (anti-duplication gate)

**Date:** 2026-07-23
**Branch:** 140-links   **Worktree:** ~/Projects/_worktrees/groups-links
**Diff base:** origin/main   **Diff head:** HEAD (140-links)
**Reuse map:** docs/planning/handoffs/140-links/survey.md §"Reuse & Analogous-Feature map"
**Verdict:** PASS

## Summary

F extended the analogous object the Reuse map named — every seam is the one the map
called for. No parallel path was introduced: the render-time hook change lands on the
same `#[Hook('preprocess_group')]` method (`DoGroupExtrasHooks::preprocessGroup`) that
already exists for the archived-group branch, the field shape mirrors
`field_group_description` file-for-file, the Full display file is genuinely new (no
prior file to shadow), and the new `group-links` library is a separately-attachable
sibling under the same `do_group_extras.libraries.yml` — the correct shape for the
conditionally-attached scope O accepted from o4-mini W-2. No build artifacts staged.
No shared-surface drive-by edits. Coordination for #141 About is clean.

## Findings

| # | Severity | File:line | Finding | Suggested fix |
|---|---|---|---|---|
| 1 | pass | `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php:79-99` | `preprocessGroup()` extended in place — the same method already handling the archived-group library attach now also attaches `do_group_extras/group-links` on the community_group Full view with a non-empty field. This is the exact seam the Reuse map named ("attach library on group Full display via preprocess_group … in do_group_extras"). No parallel hook, no new hook class, no `hook_preprocess_field__*` fallback. W-2 scope condition (view_mode + hasField + !isEmpty) is faithfully applied. | — |
| 2 | pass | `docs/groups/modules/do_group_extras/css/group-links.css` (new) | Selector namespace is `.field--name-field-group-links` (Drupal-core-emitted field wrapper class) — no overlap with the existing `docs/groups/modules/do_group_extras/css/do_group_extras.css`, which only targets `.group--archived*`. No pre-existing style covers this surface in `do_chrome`, `do_group_extras`, or the subtheme (grep confirmed: only 2 CSS files in `docs/groups` touch `.field--name-field-group-*` and they are disjoint). Not a duplicate. | — |
| 3 | pass | `docs/groups/modules/do_group_extras/do_group_extras.libraries.yml` (+5) | New `group-links` library added as a sibling under the same file, not a new `.libraries.yml`. Correct grouping. Folding it into the always-attached `do_group_extras` bundle would defeat the W-2 conditional-attach optimization O accepted — keeping it separate is the right call, not over-fragmentation. | — |
| 4 | pass | `docs/groups/config/core.entity_view_display.group.community_group.default.yml` (new) | Confirmed via `git show origin/main:...` there is no prior file at this path — this is a genuinely new authored file, not a shadow of a shipped/optional contrib config. #138's `views.view.group_members` hook_install-strip trap does not apply (that trap is only for `views.view.*` shipped in `drupal/group` `config/optional`; core does not ship entity_view_display defaults for site-authored bundles). Layout matches survey.md: description=0, visibility=1, image=2, `# (weight 10 reserved for #141 About)` marker, links=20. `label: above` on `field_group_links` supplies the section H2 — no external wrapper — so empty-state suppression is by construction. | — |
| 5 | pass | `docs/groups/config/core.entity_view_display.group.community_group.default.yml` — coordination with #141 | YAML `content:` is a dict keyed by field_name. #141 will add one new key `field_group_about:` (weight 10) between the `field_group_image` block (line ~50) and the `# --- Section: Links & Resources` comment (line ~59), and one line in `dependencies.config` — both are pure insertions in alphabetized/sorted contexts. Zero sibling keys need reordering. `hidden:` block is left minimal (only `field_group_language`, `field_group_type`) — no line #141 will conflict on. Section-marker comment prefix is clearly labeled cosmetic in the header comment, which forestalls the drush-cex-round-trip footgun A's Phase 3 warn #4 raised. | — |
| 6 | pass | `docs/groups/modules/do_group_extras/do_group_extras.info.yml` (+2/-1) | `drupal:link` added, list alphabetized (`group`, `link`, `taxonomy`). Style matches existing precedent A cited (Phase 3 housekeeping ask). | — |
| 7 | pass | `docs/groups/config/field.{storage,field}.group.*field_group_links*.yml` (new) | Shapes mirror the named analogue `field_group_description` (storage: `entity_type: group`, `cardinality: -1`, no indexes; instance: bundle=community_group, translatable, required=false, no default_value). `link` field-type + `link_type: 17` (GENERIC bitmask) + `title: 2` (Required) match the brief's acceptance criterion #2 ("title required per delta") — F's `title: 2` reading (not `title: 1`) is architecturally correct against `\Drupal\link\LinkTitleVisibility`, and O's diff-review-response is silent on it (acceptance stands as-shipped). | — |
| 8 | pass | `docs/groups/modules/do_chrome/src/HelpText.php` (untouched) | Confirmed via `git diff --stat`: not in the diff. Phase 3 A finding #8 explicitly cleared HelpText as not-applicable to this story (Links & Resources is inline content, not a tooltip). F correctly did not touch it. | — |
| 9 | pass | Build-artifact isolation | `git diff --stat origin/main...HEAD -- 'web/modules/custom/' 'config/sync/'` returns empty. No gitignored assembled artifacts leaked into the diff. The throwaway `ZZDiagLinksFieldTest.php` F used in the gitignored `web/modules/custom/` tree per handoff-F is correctly not staged. | — |
| 10 | pass | Shared-surface / drive-by scan | Files touched are exactly: 4 config yml (2 new, 1 edited, 1 new), 1 CSS (new), 1 `.info.yml` (edit), 1 `.libraries.yml` (edit), 1 `.php` hook file (+16 lines, no method removed or reshaped), 1 seed script (append-only step 735), plus new test files (T-owned) and handoff docs. No other-agent-owned surface (do_streams, do_showcase, do_group_membership, do_chrome/HelpText.php, do_multigroup, subtheme) touched. Fully within `do_group_extras`'s charter. | — |

No duplication; extension is clean.

## Notes for F

None — no rework required. Proceed to U (WCAG/visual walkthrough) and S (spec conformance).

## Patterns referenced

- `docs/groups/modules/do_chrome/src/Hook/ArchivePinHooks.php:54` — established `#[Hook('preprocess_group')]` precedent (O cited in diff-review-response).
- `docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php` (pre-diff base) — the existing `preprocessGroup()` method F extended in place.
- `docs/groups/modules/do_group_extras/css/do_group_extras.css` — confirmed disjoint selector namespace vs. new `group-links.css`.
- `docs/groups/config/field.{storage,field}.group.{,community_group.}field_group_description.yml` — the shape analogue for the new field files.
- `docs/planning/handoffs/140-links/handoff-A-plan.md` — Phase 3 findings the diff faithfully honors.
