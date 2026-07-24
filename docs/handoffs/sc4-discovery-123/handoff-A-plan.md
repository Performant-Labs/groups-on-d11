# Handoff-A: Phase 3 - #123 SC-4 Discovery three ways  (up-front plan review)

**Date:** 2026-07-23
**Branch:** 123-discovery-three-ways
**Brief reviewed:** `docs/handoffs/sc4-discovery-123/brief.md`
**Reuse map:** `docs/handoffs/sc4-discovery-123/survey.md`
**Wireframe:** `docs/handoffs/sc4-discovery-123/wireframe.md`
**Verdict:** PASS (with three binding directives on the D-flagged open questions, plus two `warn` findings)

## Summary

The plan correctly extends `VariantSwitcher` + `ShowcaseController::page()` with a second labeled
instance rendering existing views via `views_embed_view()` — no ranking is forked and no new
switcher primitive is introduced. Directives below resolve the three D-flagged risks so F does not
re-derive them under time pressure:

1. **Query-key knob** → extend `VariantSwitcher::build()` with an optional 4th param
   `string $query_key = 'variant'` (BC-safe).
2. **Tooltip** → single wrapper tooltip (POC scope). Do NOT extend `VariantSwitcher` for per-option
   tooltips.
3. **`discovery_compare` view** → do NOT create a new views config file. Instead, embed the three
   EXISTING views directly from the controller. The issue's "Owns" line is amended accordingly (see
   Notes for O).

## D-flagged risk resolutions

### Risk 1 — `VariantSwitcher::build()` hardcodes `?variant=` at line 155

**Decision: (a) extend `build()` signature with `string $query_key = 'variant'` as an optional 4th
parameter.**

Justification:
- **BC preservation.** Default value preserves every existing call site (`ShowcaseController::page()`
  line 139-143 and `DoShowcaseHooks::preprocessViewsView()` lines 492-496 both pass 3 args and both
  legitimately want `?variant=`). The existing `VariantSwitcherTest` render-array contract stays
  intact.
- **DRY / forward-compat.** ST-8 and any future story that mounts a second switcher on any page
  already-hosting `directory.layout` faces the identical collision. Encoding the knob at the seam
  means those callers say `build($id, $opts, $current, 'streams')` — one line — instead of each
  hand-rolling an `#options[*]['href']` rewrite loop. Path (b) (controller post-processes) leaks
  render-array shape knowledge into every future caller and duplicates a rewrite pattern.
- **Test surface.** One new unit assertion on `VariantSwitcher` ("passing $query_key emits
  `?<key>=<id>` in every option `href`") vs. one behavioral assertion per caller under path (b).
- **Also update the internal `#cache['contexts']` (VariantSwitcher.php:195-197)** to include
  `url.query_args:<query_key>` (currently hardcoded to `url.query_args:variant`). Same defect class
  as #119 fix-loop round 3: fix it at the seam, not per caller.

`build()`'s docblock must be updated to document the new parameter and the cache-context bubble.

### Risk 2 — Tooltip granularity

**Decision: (a) single wrapper tooltip, POC-scope. Adopt D's default.**

Justification:
- The issue's "each tab's tooltip states the decision it represents" is satisfied at the
  informational level by ONE tooltip that names all three decisions in one sentence — D's copy
  ("Recent = chronological (newest first). Hot = engagement-ranked… Promoted = editorially
  curated…") does exactly this.
- Extending `VariantSwitcher` for per-option tooltip strings would break the "one tooltip per widget
  wrapper" house pattern documented at `VariantSwitcher.php:35-37` and force schema/render-contract
  changes to `#options[]`. That is framework surgery for a phrasing gain, and POC review rigor is
  `none`.
- HelpText key: `showcase.switcher.discovery.ranking` — confirmed correct per the
  `showcase.switcher.<instance_id>` convention (`VariantSwitcher.php:159`, `HelpText::get()` call).
  Append-only in `do_chrome`.

### Risk 3 — `views.view.discovery_compare.yml` shape

**Decision: (b) do NOT create `discovery_compare.yml`. Embed the three existing views directly.**

Justification:
- Path (a) — one view with 3 displays whose sort/filter mirror the three sources — is a **literal
  fork of ranking**. It copies `activity_stream`'s comment-timestamp sort, `hot_content`'s hot-score
  sort, and `promoted_content`'s flag filter into a new YAML. Any subsequent tuning of the real
  three views (a filter change, a pager cap, a sort tiebreak) silently diverges. This is exactly
  what the issue's "do **not** fork their ranking logic" and the brief's "NOT a duplicate ranking
  pipeline" prohibit.
- Path (c) — thin per-variant `embed` displays hanging off one of the three existing views — cannot
  work: no single existing view is a superset of the other two (different sorts, different filters,
  different base joins). Any "embed" display would still hand-roll the other two rankings.
- Path (b) — the controller reads `?discovery=` and calls one of
  `views_embed_view('activity_stream', 'default')` / `views_embed_view('hot_content', 'default')` /
  `views_embed_view('promoted_content', 'default')` — routes the request to the ORIGINAL config
  with zero duplication. Ranking stays owned by the three source views. `/hot` and existing promoted
  behavior are literally the same code path.
- **Consequence for the issue's "Owns" list:** the "New view displays / config for the comparison
  surface: `docs/groups/config/views.view.discovery_compare.yml` (new)" bullet is dropped. This
  changes the disjoint-files claim; O must record the amendment (see Notes for O). The two other
  owned files (`css/discovery-compare.css`, `tests/e2e/discovery-compare.spec.ts`) remain.
- If a caller-facing selection helper is wanted (mirroring `VariantSwitcher::directoryLayoutOptions()`),
  add a small `discoveryRankingOptions(): array` method on `VariantSwitcher` so the `recent`/`hot`/
  `promoted` id-to-view-id-to-display-id mapping lives in ONE place. This is optional but idiomatic
  given SC-5's precedent for hoisting shared option specs into the service.

## Spot-check findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| 1 | warn | `#cache` on `/showcase` | cross-cutting (caching) | Wireframe correctly calls for adding `url.query_args:discovery` beside `url.query_args:variant` in `ShowcaseController::page()`. Ensure `build()` (per Risk 1 resolution) also bubbles `url.query_args:discovery` from its own `#cache['contexts']` so the defect is fixed at the seam, not just at the top-level page render. | F adds one line to `page()` AND uses the new 4th param on `build()`, which auto-bubbles the correct context. |
| 2 | warn | Two switchers, two hooks, one library | pattern consistency | The wireframe's markup shape uses the same `role="radiogroup"` + `?<key>=<id>` no-JS fallback link pattern as SC-5. Progressive-enhancement JS `do_showcase/switcher` was written against `?variant=` when it intercepts clicks — F must verify (`do_showcase.switcher.js`) that the JS handler reads the option `href` verbatim rather than assuming the query key, and if it hard-codes `variant`, that fix is in-scope for this story. | F reviews `js/do_showcase.switcher.js`; if it hard-codes `variant`, generalize it to read from the anchor's own `href` (which the new 4th-param `build()` already writes correctly). |

WCAG 2.2 AA reuse assumptions are correct — the plan reuses the existing radiogroup semantics,
non-color glyphs, roving-tabindex, and focus-visible outline from `VariantSwitcher` verbatim; the
new `discovery-compare.css` is scoped to layout/meta-line typography only per wireframe §"WCAG 2.2
AA notes". No new interaction code proposed.

No anti-pattern spotted vs. `docs/planning/` precedents or neighboring `do_showcase` code.

## Notes for O

Two brief/issue amendments to record in `decisions.md` before F starts:

1. **Drop `docs/groups/config/views.view.discovery_compare.yml` from the "Owns" list.** The
   comparison surface routes to the three existing views directly via `views_embed_view()`; no new
   view config is created. This is the only way to satisfy "do NOT fork ranking" honestly. Update
   brief.md line 28 accordingly and note the amendment in decisions.md.
2. **Extend `VariantSwitcher::build()`** with a 4th param `string $query_key = 'variant'` and bubble
   `url.query_args:<query_key>` from `#cache['contexts']`. This is a small, BC-safe cross-story
   change to a #119 primitive; it is the correct extension per the "extend the analogous object"
   discipline. Record it in decisions.md as an explicit cross-story amendment so a future audit does
   not read it as drive-by drift.

No BLOCK. F can proceed once these two amendments are recorded.

## Patterns referenced

- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (build signature, cache-context bubble,
  house tooltip pattern, HelpText key convention)
- `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` (page(), cache-context
  add, stub switcher wiring)
- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` (SC-5 second-instance precedent:
  viewsPreRender + preprocessViewsView pair)
- `docs/groups/config/views.view.{activity_stream,hot_content,promoted_content}.yml` (base tables,
  ranking-owning sorts/filters — not to be duplicated)
- `docs/handoffs/sc4-discovery-123/{brief,survey,wireframe,handoff-D}.md`
