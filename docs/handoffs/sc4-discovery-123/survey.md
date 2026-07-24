# Survey — #123 SC-4 Discovery three ways

## Existing surfaces (do NOT modify behavior)
- `docs/groups/config/views.view.activity_stream.yml` — sitewide reverse-chronological content feed ("Recent" source).
- `docs/groups/config/views.view.hot_content.yml` — comment/view score ranking ("Hot" source).
- `docs/groups/config/views.view.promoted_content.yml` — filtered by `promote_homepage` flag ("Promoted" source).
- `docs/groups/config/flag.flag.promote_homepage.yml` — flag drives `promoted_content`. Two seeded promoted nodes exist per acceptance.

## SC-F1 framework already in place (#119, merged)
- `Drupal\do_showcase\VariantSwitcher` (`do_showcase.variant_switcher` service) — labeled radiogroup, `?variant=<id>` deep link, WCAG 2.2 AA compliant (role="radiogroup", aria-checked, non-color `●` glyph, ⓘ tooltip via `HelpText::get('showcase.switcher.<instance_id>')`, "(soon)" for unavailable, first-available fallback).
- `Drupal\do_showcase\Controller\ShowcaseController::page()` — `/showcase` catalog page, already lists a `discovery-ranking` entry with `status: live`, `route: do_showcase.showcase`, decision sentence: "Compares three ways to surface groups: Recent, Hot, Promoted — the decision: how much editorial curation vs. raw recency."
- `Drupal\do_showcase\ShowcaseCatalog` — the catalog. `discovery-ranking` entry already present; deep-link is `do_showcase.showcase` (this surface will render on `/showcase`).
- `HelpText::get('showcase.switcher.<instance_id>')` — tooltip copy source; append-only in `do_chrome`.

## Reuse & Analogous-Feature map (DEFAULT: extend)
- **Analogous feature:** `#124 SC-5 directory-toggle` — a labeled `VariantSwitcher` instance rendered inline via a hook (`DoShowcaseHooks::viewsPreRender()`), swapping Views display class on `/all-groups`. Same shape SC-4 needs.
- **Recommendation — EXTEND, not new:**
  - Reuse `VariantSwitcher::build()` as-is. No new switcher primitive.
  - Reuse `activity_stream`, `hot_content`, `promoted_content` views as-is. Do NOT fork ranking.
  - The "one surface" is `/showcase` itself: below the existing stub `directory.layout` switcher, add a NEW `discovery.ranking` switcher instance whose three options (recent / hot / promoted) render the corresponding view via `views_embed_view()`. The switch is a URL query param (`?discovery=recent|hot|promoted`), following the same `?variant=` deep-link pattern the framework already ships.
  - `views.view.discovery_compare.yml` (per issue "Owns"): a NEW single view with three DISPLAYS (recent / hot / promoted), each a thin wrapper/passthrough embedding the ranking of the existing three views — OR simpler: three named displays whose sort/filter mirror the three sources but published under one view id so a single `views_embed_view('discovery_compare', $display_id)` call powers the switcher. This keeps a single "owned" config file.
  - Alternative simpler read: `discovery_compare.yml` holds three displays that each embed one of the existing views by relationship-less passthrough, so no ranking is duplicated. See A/plan gate.

## Existing test scaffolding
- Playwright specs in `tests/e2e/*.spec.ts` — `directory-toggle.spec.ts`, `persona-switcher.spec.ts` are the closest patterns for cycling a `VariantSwitcher` instance and asserting URL/DOM change.
- Kernel test locations: `docs/groups/modules/do_showcase/tests/src/Kernel/`.

## Forward-compat check
- No downstream story depends on `discovery_compare` view structure beyond the current issue. SD-6 (#133) HelpText backstop is append-only.
- `VariantSwitcher` already supports multiple simultaneous instances (SC-5 proves this: `/showcase` stub + `/all-groups` inline both live).

## Deviations / risks
- Two switchers on `/showcase` — must NOT collide on query params. `directory.layout` uses `?variant=`; new instance should use a DISTINCT query key (`?discovery=`) OR the framework's per-instance keying (verify in D/A).
- `activity_stream` `base_table: node_field_data` and `hot_content` `base_table: node_field_data` — same base, safe to share display-level switching.
- Promoted requires flag + 2 seeded nodes. `flag.flag.promote_homepage` present; seed data acceptance said "two nodes seeded" — verify seed exists.
