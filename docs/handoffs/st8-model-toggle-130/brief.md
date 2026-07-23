# Brief — ST-8 Model comparison toggle (#130)

**Issue:** Performant-Labs/groups-on-d11#130 (ST-8, Epic #108, cross-epic dep on #119/#124)
**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st8-modeltoggle-130`
**Branch:** `130-model-comparison-toggle` (base `01f49a51`)
**Review rigor:** `none` (per issue text). POC lean pipeline.
**Survey:** `docs/handoffs/st8-model-toggle-130/survey.md`

## Objective

Mount the SC-F1 (`VariantSwitcher`) as a **"Content view / Activity view"** labeled toggle over
`/stream` (view `activity_stream`, display `page_1`). Ship it as a `live` entry on `/showcase`
with a tooltip explaining the two-model comparison and `?variant=` deep linking that lands
pre-switched. `/my-feed` mounting deferred pending #110 merge; hook design anticipates the
one-line add.

## Scope (locked)

- **In scope (this PR):**
  1. `do_streams/src/Hook/ModelToggleHooks.php` — two hooks (`viewsPreRender`,
     `preprocessViewsView`) targeting `activity_stream:page_1`, mirroring #124's
     `DoShowcaseHooks::viewsPreRender` + `preprocessViewsView` pattern exactly.
  2. `do_streams/css/model-toggle.css` — CSS-only visibility scoping by
     `[data-do-stream-model]` attribute (no-op for now since only Activity view is available;
     lands the selector for #129's future Content view).
  3. `do_streams/do_streams.libraries.yml` — new `model-toggle` library.
  4. `do_streams/do_streams.services.yml` — register `do_streams.model_toggle_hooks` with
     `@do_showcase.variant_switcher` + `@request_stack`.
  5. `do_showcase/src/VariantSwitcher.php` — new `streamModelOptions()` method returning
     `[{id: 'content', label: t('Content view'), available: FALSE}, {id: 'activity',
     label: t('Activity view')}]`. Matches existing `directoryLayoutOptions()` shape 1:1.
  6. `do_showcase/src/ShowcaseCatalog.php` — `stream-model` entry flips: `status: 'live'`,
     `route: 'view.activity_stream.page_1'`, `decision_sentence` updated to reflect the actual
     comparison ("node-content model vs activity-log model").
  7. `do_chrome/src/HelpText.php` — append `showcase.switcher.stream.model` tooltip copy.
  8. `do_streams/tests/src/Kernel/StreamModelTogglePreRenderTest.php` — clone of
     `DirectoryTogglePreRenderTest`: positive fire on activity_stream:page_1, negative view-id
     no-op, wrapper attribute lands, cache context bubbles.
  9. `do_chrome/tests/src/Unit/StreamModelHelpTextTest.php` — pins the tooltip copy.
  10. `do_showcase/tests/src/Unit/VariantSwitcherTest.php` — add case for `streamModelOptions()`.
  11. `do_showcase/tests/src/Unit/ShowcaseCatalogTest.php` — update assertion: `stream-model`
      is now `live` with the new route + decision_sentence.
  12. `tests/e2e/model-toggle.spec.ts` — clone of `directory-toggle.spec.ts`: navigates to
      `/stream`, asserts switcher rendered, clicks Content (disabled, no-op), clicks Activity
      (already active), asserts `data-do-stream-model="activity"` on wrapper, session persistence
      via `?variant=` deep-link + reload.

- **Out of scope (deferred, tracked here):**
  - The actual Content view of `/stream` (a new view or view display over `node_field_data`) —
    belongs to #129. Content option ships `available: FALSE ("soon")` per SC-F1's truthful-copy
    rule (same as #119's `map` and #133's `membership-models`).
  - `/my-feed` mount — `views.view.my_feed.yml` does not exist in main (blocked on #110).
    Hook targets a SET of `{view_id => display_id}` tuples (initially just one) so #110's
    merge unlocks a **one-line addition**: `'my_feed' => 'page_1'` in the target set.
  - Any edits to `views.view.activity_stream.yml` or `views.view.my_feed.yml` YAMLs — the SC-F1
    mount attaches via hooks on the render pipeline (per #124's proven pattern); no view-config
    edits needed. Contradicts the issue's "Owns" bullet about `views.view.*.yml` attachment
    edits, but that bullet is superseded by the working #124 precedent (verified: #124 shipped
    zero view-config edits and works). Flag for A-review confirmation.

## Acceptance criteria (map to tests)

- **AC-1** On `/stream`, the switcher renders with two options: "Content view (soon)" (disabled)
  and "Activity view" (selected). E2E + Kernel.
- **AC-2** Wrapper carries `data-do-stream-model="activity"` on default load. Kernel + E2E.
- **AC-3** `?variant=content` → switcher still resolves to `activity` (first available fallback,
  the SC-F1 rule) and wrapper carries `data-do-stream-model="activity"`. Kernel + E2E.
- **AC-4** `?variant=activity` deep-link — page renders with `activity` selected, no reload
  flash. E2E.
- **AC-5** `/showcase` lists the `stream-model` entry as `live` with a working link to
  `view.activity_stream.page_1` (i.e. `/stream`). Unit (ShowcaseCatalogTest) + E2E.
- **AC-6** Switcher tooltip renders with `HelpText::get('showcase.switcher.stream.model')` copy
  explaining what differs (social rows / aggregation / comment activity / lean-model note per
  issue). Unit (HelpText) + E2E (visible on hover/focus of the ⓘ trigger).
- **AC-7** Content option is `aria-disabled="true"`, `tabindex="-1"`, label suffixed " (soon)".
  Kernel (render-array shape) + E2E.
- **AC-8** WCAG 2.2 AA: switcher is a real `role="radiogroup"` with keyboard operability, visible
  focus, non-color selection cue (leading `●` glyph). U walkthrough (Playwright-MCP).
- **AC-9** Cache context `url.query_args:variant` bubbles up to the page's dynamic cache key
  (no cross-variant cache pollution). Kernel.
- **AC-10** Existing test suite stays green — no regression on #124's directory toggle, no
  regression on #116's activity_stream rendering. Kernel + Functional + E2E.

## Anti-duplication guardrails (Reuse map summary — see survey.md §Reuse map)

- **DO reuse** `VariantSwitcher::build()` / `resolveCurrent()` / `directoryLayoutOptions()`-style
  method. **DO NOT** duplicate the render-array assembly or the fallback rule.
- **DO reuse** `do_showcase/switcher` JS library — its mirror pattern is already
  attribute-agnostic (reads `data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-
  selector` off the wrapper). **DO NOT** write a second JS toggle.
- **DO reuse** #124's two-hook shape (viewsPreRender + preprocessViewsView + shared
  id/display guard + shared requestedVariant helper). **DO NOT** invent a third rendering seam.
- **DO reuse** `HelpText::get()` for tooltip copy — appended, never modified.
- **DO reuse** `.views-element-container` as the mirror selector (proven in #124 by live-DOM
  inspection). **DO NOT** invent a different selector.

## Handoff locations

`docs/handoffs/st8-model-toggle-130/` — survey, brief, wireframe, decisions.md, and each
phase's handoff (handoff-D, handoff-A, handoff-T-red, handoff-F, handoff-T-green, handoff-U,
handoff-S).

## Pipeline order (POC lean)

O(brief) → **D** (wireframe) → **D-gate** (auto-approve unless human intervenes) → **A** (plan
review) → **T(RED)** → **F** → **T(GREEN)** → **diff-gate** (o4-mini via dual-review.sh) → **U**
(Playwright-MCP live walkthrough) → **S** (spec-auditor) → **rebase-and-CI-check** → PR →
CI-green → self-merge.

Cuts (POC): SKIP brief-gate. SKIP A-dup. NO pre-PR hold.

## Notes for D

- Look at `docs/handoffs/0124-directory-toggle/wireframe.html` (if present) — same shape.
- Two options total; Content is `(soon)`. Wireframe must show BOTH the default state and the
  `?variant=content` fallback state (still lands on Activity). Wireframe must show the ⓘ trigger
  positioning and the tooltip copy proposal.
- Decision-sentence copy for `/showcase` catalog entry — D should propose the new sentence
  (currently: "single combined activity stream vs per-content-type streams" — wrong. Should
  reflect the actual comparison: "node-content model vs activity-log model", or similar).

## Amendment 1 (post-D)

D flagged a same-feature stale key: `HelpText::get('showcase_help.stream-model')` (line 284 of
HelpText.php) — the `/showcase` tour orientation copy for the stream-model catalog card, in a
different key namespace than the switcher tooltip. Currently reads: "One combined activity
stream vs. separate streams per content type..." — same wrong decision as the old
decision_sentence. **In scope for this PR** (append-only edit to fix the string; single source
of truth for the same catalog entry the brief already flips to `live`). Update to match D's
approved decision_sentence copy.

D wireframe approved (auto-approved per POC lean pipeline, no operator intervention required).
Tooltip copy for `showcase.switcher.stream.model`: "Activity view aggregates everything happening
in this scope — posts, comments, flags, pins, and membership changes — as one chronological feed
of message rows. Content view (coming soon) will show just the posts themselves: a leaner model
with no aggregated activity noise."
