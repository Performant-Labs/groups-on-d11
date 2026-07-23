# Handoff-A: Phase 3 — Model comparison toggle plan review (ST-8 / #130)

**Date:** 2026-07-23
**Branch:** 130-model-comparison-toggle
**Brief reviewed:** `docs/handoffs/st8-model-toggle-130/brief.md` (incl. Amendment 1)
**Reuse map:** `docs/handoffs/st8-model-toggle-130/survey.md` §Reuse & Analogous-Feature map
**Wireframe:** `docs/handoffs/st8-model-toggle-130/wireframe.md`
**Verdict:** PASS

## Summary

Plan is a faithful, low-risk clone of #124's SC-5 directory-toggle pattern applied to
`activity_stream:page_1`, with the extension points that already exist in `VariantSwitcher`
(new `streamModelOptions()` method mirroring `directoryLayoutOptions()`) and the shared JS
mirror contract reused verbatim. Every architecturally load-bearing decision (hook location,
two-hook shape, wrapper attribute contract, cache context, catalog entry flip, HelpText
tooltip key) matches the merged precedent. No parallel path is proposed. No blocking
concerns.

## Findings

| # | Severity | Plan element | Drift dimension | Finding | Suggested fix |
|---|---|---|---|---|---|
| — | — | — | — | Plan is consistent with existing patterns. | — |

## Advisories (soft, do not block)

1. **`do_streams/src/Hook/ModelToggleHooks.php` location is correct.** The switcher SERVICE
   (`VariantSwitcher`), template (`do_showcase_variant_switcher`), and JS
   (`do_showcase.switcher.js`) remain in do_showcase as the reusable framework. The CALLER
   (the hook set that mounts the switcher onto a page owned by another module) belongs with
   the module that owns that page. do_streams owns `/stream` (via `activity_stream` and its
   ranking/shell hooks in `DoStreamsHooks`); therefore the mount hook belongs in do_streams.
   This is also what the issue's "Owns" bullet names. **Cross-module dependency direction is
   fine:** do_streams already can depend on do_showcase (do_showcase is the lower-level
   framework/UX module; do_streams is a feature surface). The reverse would be a violation.

2. **Two-hook clone-now, refactor-later is the right call.** The survey correctly identified
   the option to refactor `DoShowcaseHooks` into a table-driven multi-instance dispatcher.
   That refactor is out of scope for a POC story with `review rigor: none` and would put
   #124's shipped tests at regression risk for zero user-visible gain. The clone reuses the
   only object that MUST be single-sourced (`VariantSwitcher`); the two hook methods per
   instance are a shallow structural duplication (private guard + private `requestedVariant`
   helper), not a semantic one. The refactor becomes justified when a third instance arrives
   (e.g. SC-6 map view flip, or #129 mounting the Content view). Track it as latent debt —
   but per the POC "no follow-ups for merged-story latent debt" memory, do NOT file a GH
   issue; surface it in an audit later if it starts to bite.

3. **Deferred `/my-feed` mount + Content option as `(soon)` is correct precedent-fit.** SC-F1
   truthful-copy is exactly this pattern: `available: FALSE` renders the option with
   "(soon)" suffix, `aria-disabled="true"`, `tabindex="-1"`, and no click activation. #124
   ships `map` this way; #133 ships `membership-models` this way. The framework can be
   proven with one-available-plus-one-soon: AC-1/AC-2/AC-3/AC-7/AC-9 all exercise the
   framework's server-side resolution + wrapper attribute + cache context; AC-4 exercises
   the deep-link path. The "click Content is no-op" assertion in the E2E is what proves the
   disabled option's contract. Framework value is fully demonstrable without a real second
   view.

4. **No edits to `views.view.activity_stream.yml` needed — brief is correct.** Verified
   against `DoShowcaseHooks::viewsPreRender()` + `preprocessViewsView()`: #124 attaches the
   switcher, sets `#header['switcher']`, sets `#attributes['data-do-directory-variant']`,
   sets the cache context, attaches libraries — ALL on `$view->element` and
   `$variables['header']`, without touching `views.view.all_groups.yml`. The class docblock
   explicitly documents WHY `preprocessViewsView` (not `viewsPreRender`) is the seam that
   survives Views' `elementPreRender` overwrite of `#header`. The pattern is view-config
   agnostic by design. The issue's "Owns: append-only attachment edits" bullet is
   superseded — flag this in the PR description so the reviewer isn't confused.

   *One case where view YAML WOULD need editing*: if `activity_stream:page_1` declared a
   `header:` area handler and we needed to REPLACE it (rather than sit alongside it). It
   does not. `preprocessViewsView` adds `$variables['header']['switcher']` as an additional
   key, coexisting with any (currently none) existing area handlers — same as #124's
   comment on `preprocessViewsView` explicitly says.

5. **Cache-context is complete.** Adding `url.query_args:variant` to
   `$view->element['#cache']['contexts']` matches #124's precedent exactly. Persona (`user`)
   is NOT needed because the rendered view CONTENT is identical across personas today (only
   Activity view is available; its rows are personalized by existing view-level access, not
   by the switcher). The `user` context is used elsewhere (`personaBanner`,
   `PersonaSwitcher::build()`) only for fragments whose CONTENT actually varies by session,
   which the switcher render does not. If #129 later mounts a Content view whose row set
   varies by persona, THAT view's own `#cache['contexts']` handles it — orthogonal to the
   switcher plumbing. No cross-persona pollution risk from this story.

6. **No duplication of existing switcher methods.** `streamModelOptions()` is a NEW method
   with no analog under a different name; `resolveCurrent()` and `build()` are reused
   verbatim from the switcher service. `.views-element-container` mirror selector reused.
   No JS added. No CSS/library naming collision — `do_streams/model-toggle` is a new
   library key in a distinct module namespace, not a clone of `do_showcase/switcher` or
   `do_showcase/directory-compact`. This IS the A-dup check performed up-front — clean.

7. **Test coverage is sufficient; skipping Functional is consistent with precedent.** #124
   shipped Kernel (view execution + render metadata) + Unit (switcher render-array shape) +
   E2E (click-through + persistence) with no Functional test. The Kernel test proves the
   hook fires under a real `ViewExecutable` render, and the E2E proves the assembled DOM +
   client-side behavior. A Functional test would be a redundant mid-tier, and the POC
   "review rigor: none" issue does not warrant expanding coverage beyond precedent. Same
   shape here is right.

8. **Amendment 1 (`showcase_help.stream-model` fix) is in-scope, not scope creep.** It is a
   same-feature append-safe string edit to the SAME catalog entry the brief already flips
   from `coming` to `live`. Leaving the tour-page tooltip carrying the stale "combined
   stream vs. per-content-type" framing while the catalog entry itself now flips to a
   different (correct) decision_sentence would ship a self-contradictory `/showcase` state
   in a single PR. Fixing both keys in step is the minimum-cohesive change. It's a
   two-line HelpText edit and one pinned-string test update — not a new subsystem.

## Notes for O

None (PASS). Proceed to T(RED).

## Patterns referenced

- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php` — #124 two-hook precedent
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — build/resolveCurrent contract
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — catalog entry shape
- `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php` — Kernel test template
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — do_streams hook cadence + services layout
