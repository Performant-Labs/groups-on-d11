# Brief — #123 SC-4 Discovery three ways

**Issue:** Performant-Labs/groups-on-d11#123
**Branch:** `123-discovery-three-ways`
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-sc4-discovery-123`
**Review rigor:** none (POC lean pipeline)
**UI surface?** YES — extends `/showcase`.

## Objective
Add a "Discovery ranking" comparison surface on `/showcase` presenting Recent / Hot / Promoted as three labeled tabs via the existing SC-F1 `VariantSwitcher`. Each tab renders the corresponding existing view (`activity_stream` / `hot_content` / `promoted_content`) without forking any ranking logic. Deep-linkable via a distinct query parameter. Ships with HelpText tooltip entry + WCAG 2.2 AA + Playwright spec.

## Reuse map summary (A-amended)
- REUSE existing three views AS-IS — do NOT create `views.view.discovery_compare.yml`. Controller calls `views_embed_view('activity_stream'|'hot_content'|'promoted_content', 'default')` based on `?discovery=` value. (A Phase 3, path b — the only path that satisfies "do not fork ranking".)
- EXTEND `VariantSwitcher::build()` with an optional 4th param `string $query_key = 'variant'` (BC-safe: 3-arg callers unchanged). Also update its internal `#cache['contexts']` bubble from hardcoded `url.query_args:variant` to `url.query_args:<query_key>` so callers don't hand-roll cache context per instance. (A Phase 3, resolves D-blocker #1.)
- EXTEND `ShowcaseController::page()` — insert a new "Discovery ranking" section below the existing catalog + stub `directory.layout` switcher, hosting the new `discovery.ranking` switcher and the embedded view chosen by `?discovery=`.

## Acceptance criteria (from issue)
- [ ] All three variants render non-empty from seed (Promoted shows 2 seeded nodes; Hot shows commented threads on top after cron).
- [ ] Switcher labels + tooltips present; a single wrapper tooltip covers the decisions the three tabs represent (per-option tooltips are out-of-scope framework surgery — POC path).
- [ ] Deep links land pre-switched from `/showcase` (query-string driven: `?discovery=recent|hot|promoted`).
- [ ] `/hot` and existing promoted views behavior UNCHANGED.
- [ ] Existing suite stays green.
- [ ] New Playwright spec `tests/e2e/discovery-compare.spec.ts` cycles the three tabs.
- [ ] HelpText entry appended for the new switcher instance: key `showcase.switcher.discovery.ranking` (append-only, do_chrome).
- [ ] WCAG 2.2 AA — labels, keyboard operability, visible focus, contrast, non-color status.

## Owned files (disjoint per issue, A-amended)
- ~~`docs/groups/config/views.view.discovery_compare.yml`~~ **DROPPED per A** — would fork ranking.
- `docs/groups/modules/do_showcase/css/discovery-compare.css` (new)
- `tests/e2e/discovery-compare.spec.ts` (new)
- Small extends:
  - `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — add `string $query_key = 'variant'` 4th param + update cache-context bubble. (Cross-story extension to a #119 primitive, deliberate, recorded in decisions.md.)
  - `docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php` — new render block.
  - HelpText copy in `do_chrome` (append-only) — key `showcase.switcher.discovery.ranking`, and any per-embedded-view help keys the wireframe defines.

## Handoff locations
- Survey: `docs/handoffs/sc4-discovery-123/survey.md`
- Wireframe: `docs/handoffs/sc4-discovery-123/wireframe.md`
- Design handoff: `docs/handoffs/sc4-discovery-123/handoff-D.md`
- A-plan handoff: `docs/handoffs/sc4-discovery-123/handoff-A-plan.md`
- Decision journal: `docs/handoffs/sc4-discovery-123/decisions.md`

## Key constraints
- Two switchers on `/showcase` must NOT share `?variant=` query key — new one uses `?discovery=`. Page cache-context must include `url.query_args:discovery` (in addition to `url.query_args:variant`).
- Ships as ONE PR. Self-merge on CI-green + mergeable per POC lean pipeline.
