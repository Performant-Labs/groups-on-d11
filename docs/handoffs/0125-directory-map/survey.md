# Survey — #125 SC-6 Directory map view

## Goal
Add a **Map** variant to the `/all-groups` directory switcher (currently Cards | Compact list) that plots
groups with a `field_group_location` (geofield) on a Leaflet map, using **locally-vendored** Leaflet
assets (zero external CDN calls). One marker per group, click → group page. Four seeded groups: Drupal
France (Paris), Drupal Deutschland (Berlin), Camp Organizers EMEA (Brussels), DrupalCon Portland 2026
(Portland, OR).

## Reuse & Analogous-Feature map

The pipeline documented in [`DoShowcaseHooks`](../../modules/do_showcase/src/Hook/DoShowcaseHooks.php)
already establishes **every extension seam** this story needs. The task is almost purely additive:

| Concern | Existing seam (extend, don't fork) | What this story adds |
|---|---|---|
| Switcher option list | `VariantSwitcher::directoryLayoutOptionIds()` — machine spec, currently `[compact, cards, map (available=FALSE)]`. Comment on this method explicitly names this story: *"#125 (SC-6) flips `map`'s `available` flag to `TRUE` in exactly one place."* | Remove `'available' => FALSE` from the `map` entry. That single edit makes the switcher option live on both `/all-groups` and `/showcase` (both call the same shared method). |
| Tooltip copy | `HelpText::get('showcase.switcher.directory.layout')` already reads *"Compact list favors scanning many groups fast; Cards shows more per-group detail; Map plots groups geographically."* — final map-mode wording is already shipped. | None. Zero HelpText additions. |
| Wrapper attribute | `DoShowcaseHooks::viewsPreRender()` already sets `data-do-directory-variant` on `.views-element-container` from `resolveCurrent()` — a `?variant=map` request will already flip the attribute to `"map"` once available. | None. Pure CSS/JS keys off the attribute. |
| Client-side switching | `do_showcase.switcher.js`'s mirror-driven model already swaps the attribute in place without navigation. Filters/pager survive a toggle (`directory-toggle.spec.ts` pins this). | None. Map presentation reacts to the attribute exactly like `directory-compact.css` does. |
| CSS library (per-variant, attached conditionally) | `do_showcase/directory-compact` library pattern. Attached by `viewsPreRender()`, dependency on `do_showcase/switcher`, scoped selectors under `.views-element-container[data-do-directory-variant="compact"]`. | Add **`do_showcase/directory-map`** library (CSS + JS + local Leaflet assets), following the exact same shape. Attached by the same hook. |
| Group-directory Views row markup | `views-view-fields--all-groups.html.twig` renders each group as `.gc-directory-card` — used by both `cards` and `compact` variants. | None. Map mode reads the same rendered rows; JS extracts `data-do-location-lat/lng` from a **wrapping element added by this story** (see below) or from a hidden field. |
| Field precedent | `field_group_location_text` (string) already exists on `community_group`; SC-5 uses `field_group_description`, `field_group_language` as models. `field.storage.*.yml` + `field.field.*.yml` pair is the convention. | Add `field_group_location` (geofield type) storage + instance YAML, dependency on `geofield` module. |
| Seed data | `step_700_demo_data.php` already creates the four target groups (Portland/Paris/Brussels/Berlin) and sets `field_group_location_text`. **Append-only** convention documented across the file. | Append lat/lng to a new `$group_locations` array and set `field_group_location` on each of the four groups after they're loaded/created. |
| E2E precedent | `directory-toggle.spec.ts` (variant switching, cache-context, filters+pager survive), `directory-cards.spec.ts` (row rendering) | Add `directory-map.spec.ts` — new file per the issue's "Owned" list. |

**Extend-vs-new recommendation: EXTEND every existing seam.** Only genuinely new artifacts:
1. `field.storage.group.field_group_location.yml` + `field.field.group.community_group.field_group_location.yml`
2. `web/libraries/leaflet/` (Leaflet 1.9.x runtime, vendored)
3. `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js` (init map on attach, one marker per row)
4. `docs/groups/modules/do_showcase/css/directory-map.css` (map container sizing, non-map elements hidden in map mode)
5. `tests/e2e/directory-map.spec.ts`
6. Two config-append additions: (a) all_groups view — expose lat/lng per row (either as a Views field on the geofield rendered as `latlon`, or as a `data-*` attribute injected via a preprocess); (b) `field_group_location` picked up in preprocess.

Everything else is a **single-line flip** (`available: TRUE`) or an **append** (seed lat/lng, add module dep, add library entry, add hook attach).

## Key architectural decision (Coordinator resolved this — no re-ask)

- **Leaflet only, no geofield_map formatter.** geofield_map ships a CDN-fetching profile; this repo's epic #78 posture is strict no-CDN. Approach: use the `geofield` **module** for storage/typing only (`\Drupal\geofield\Plugin\Field\FieldType\GeofieldItem` gives us `lat/lon` columns), skip `geofield_map`'s Views formatter entirely, and drive Leaflet directly from JS. This also sidesteps geofield_map's `~11.1` composer contract (allowed) but avoids importing its runtime.
- **How JS reads coordinates:** The all_groups view will expose the geofield's `latlon` (as a `latitude` and `longitude` pair via geofield's built-in field formatter — no custom formatter, no new dependency). The template wrapping each row already carries `.gc-directory-card`; a `hook_preprocess_views_view_fields()` (or a simple `hook_preprocess_views_view_field()` on the geofield) will attach `data-do-location-lat` and `data-do-location-lng` to the row wrapper. In `map` mode, JS collects every `[data-do-location-lat]` under the container, plots one marker per row, opens `card__title a`'s href on click.
- **Groups without location:** the row simply carries no `data-do-location-*` attrs; JS skips it silently. The switcher's own machinery already handles "showing groups with a location" tooltip framing (HelpText copy).

## Files I will read/write (paths absolute in worktree root)

**Read-only (already validated in survey):**
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php`
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js`
- `docs/groups/modules/do_showcase/css/directory-compact.css`
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml`
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php`
- `docs/groups/modules/do_chrome/src/HelpText.php`
- `docs/groups/config/views.view.all_groups.yml`
- `docs/groups/config/field.field.group.community_group.field_group_location_text.yml`
- `docs/groups/config/field.storage.group.field_group_language.yml` (analogous storage YAML)
- `docs/groups/scripts/step_700_demo_data.php`
- `tests/e2e/directory-toggle.spec.ts` (as spec model)

**Will edit:**
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — remove `'available' => FALSE` from the `map` entry
- `docs/groups/modules/do_showcase/do_showcase.libraries.yml` — add `directory-map` library entry
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — attach `do_showcase/directory-map` in `viewsPreRender()` (peer with `directory-compact`); add a `preprocess_views_view_field` (or `views_view_fields` alter) to project lat/lng onto the row wrapper
- `docs/groups/config/views.view.all_groups.yml` — append a `field_group_location` field display (geofield "latitude/longitude" formatter) so preprocess has data
- `docs/groups/scripts/step_700_demo_data.php` — append coordinate seed for the 4 groups
- `docs/groups/modules/do_showcase/do_showcase.info.yml` — add `geofield` as a module dep so the field module is guaranteed enabled by the time the config imports

**Will create:**
- `docs/groups/config/field.storage.group.field_group_location.yml`
- `docs/groups/config/field.field.group.community_group.field_group_location.yml`
- `docs/groups/modules/do_showcase/js/do_showcase.directory-map.js`
- `docs/groups/modules/do_showcase/css/directory-map.css`
- `web/libraries/leaflet/leaflet.js` (vendored 1.9.x runtime, minified)
- `web/libraries/leaflet/leaflet.css`
- `web/libraries/leaflet/images/marker-icon.png`, `marker-icon-2x.png`, `marker-shadow.png` (Leaflet's default marker sprites)
- `tests/e2e/directory-map.spec.ts`
- One config-install install script step for the new field storage/instance (or rely on the `docs/groups/config/` layer + assemble script; the pattern is already established for the other field.storage.*.yml files).

## Forward-compat check
This is the last consumer of `VariantSwitcher::directoryLayoutOptionIds()` planned in the epic — nothing downstream depends on `map` remaining unavailable. No forward-compat risk.

## Assumptions to verify during implementation
1. **Geofield module install path in the assembled config.** If `assemble-config.sh` picks up module dependencies from module `.info.yml` files, adding `geofield` to `do_showcase.info.yml` will cause Drupal to auto-enable it on module install — but I need to check if geofield_map (which is also in composer) would come along transitively. Likely fine: adding only `geofield` as a dependency does NOT enable `geofield_map`.
2. **Leaflet 1.9.4 marker-image resolution.** Leaflet's default marker icons resolve via `L.Icon.Default.imagePath`. When served from `/libraries/leaflet/images/` I'll need to set `L.Icon.Default.imagePath = '/libraries/leaflet/images/'` in our init JS to avoid it guessing based on the script's location (which becomes wrong when the JS is minified/bundled).
3. **Views preprocess seam for adding data-attributes to row wrappers.** May need `hook_preprocess_views_view_unformatted__all_groups__page_1()` (or similar templating) rather than a field-level preprocess.
