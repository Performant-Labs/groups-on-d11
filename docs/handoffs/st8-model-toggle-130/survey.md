# Survey — ST-8 model comparison toggle (#130)

**Worktree:** `C:/Users/aange/Projects/_worktrees/groups-st8-modeltoggle-130`
**Branch:** `130-model-comparison-toggle` (base `01f49a51`)

## Objective (from issue)

Mount a **"Content view / Activity view"** labeled variant switcher on `/stream` and `/my-feed`
using the SC-F1 variant framework (#119). Content view = existing node-model stream. Activity
view = ST-7 activity feed at the same scope. Switcher tooltip explains the difference; per-session
persistence via SC-F1; deep links from `/showcase` land pre-switched; list on `/showcase`.

## Current base state (facts, not plan)

- `views.view.activity_stream.yml` is IN main (from #116). Mounted at `/stream`, id
  `activity_stream`, display `page_1`. Uses `stream_card` view mode. This is the **Activity view**.
- `views.view.my_feed.yml` is **NOT in main** — lives on open PR #110 (unreviewed, awaiting merge).
  `/my-feed` route does not exist in main.
- No sibling PR for "Content view on /stream" (#129) is open. There is no separate content-model
  view mounted at `/stream` in main — `/stream` currently serves the activity model only.
- `docs/groups/modules/do_streams/` exists with the shell, filters, ranking hooks. No routing.yml
  yet in main.
- `showcase-catalog` entry for `stream-model` is currently `status: 'coming'`, route: NULL.

## Reuse & Analogous-Feature map

**Closest analogous feature (near-perfect match):** #124 SC-5 directory toggle (compact vs cards
on `/all-groups`). Same shape: SC-F1 `VariantSwitcher` mounted over a Views page via a pair of
cooperating hooks.

- `docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`:
  - `viewsPreRender()` — sets `#cache['contexts'] += url.query_args:variant`, wrapper
    `data-do-<instance>-variant` attribute, attaches libraries.
  - `preprocessViewsView()` — injects `VariantSwitcher::build()` render array into
    `$variables['header']['switcher']`, with `data-do-showcase-mirror-attribute` +
    `data-do-showcase-mirror-selector` wiring for the JS toggle callback.
  - Both hooks share a private `isDirectoryView()` id/display guard and `requestedVariant()`
    helper (single source of truth for scope + query read).
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` (SC-F1 #119) — the render-array
  producer. Already exposes:
  - `build(instance_id, options, current)` — returns render array with role="radiogroup",
    non-color selection cue, `?variant=` fallback links, HelpText tooltip, `url.query_args:variant`
    cache context, `do_showcase/switcher` library.
  - `resolveCurrent(options, current)` — public thin wrapper on private
    `resolveSelection()` so callers can compute the resolved variant before calling `build()`.
  - `directoryLayoutOptions()` — precedent for a service-method-per-instance pattern that keeps
    literal `t()` labels in the switcher class (phpcs-clean).
- `docs/groups/modules/do_showcase/js/do_showcase.switcher.js` — generic, data-driven client-side
  toggle that reads `data-do-showcase-mirror-attribute` + `data-do-showcase-mirror-selector` off
  the radiogroup wrapper. NO changes needed here — the mirror pattern already parametrizes over
  attribute + selector, so a new instance is purely a data-configuration exercise.
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — entries[] list; `stream-model`
  currently `coming/NULL`. Flip to `live` + route → `view.activity_stream.page_1`.
- `docs/groups/modules/do_chrome/src/HelpText.php` — append-only tooltip copy: add
  `showcase.switcher.stream.model` key with the "content vs activity" copy.
- `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php` — the
  precedent Kernel test for the pre-render + preprocess wiring (positive + negative view-id).
- `tests/e2e/directory-toggle.spec.ts` — the precedent E2E: navigates to page, clicks each
  variant option, asserts wrapper attribute + variant persistence.

**Extend-vs-new recommendation (default: extend):**

- **EXTEND `VariantSwitcher`** by adding a `streamModelOptions()` method (mirror of the existing
  `directoryLayoutOptions()`) so the two literal `t()` labels ("Content view", "Activity view")
  live in the switcher class alongside its peers. **No new object.**
- **NEW hook methods on `DoShowcaseHooks`** (`viewsPreRenderStreamModel()`,
  `preprocessViewsViewStreamModel()`) — or, better, GENERALIZE the existing `viewsPreRender()` /
  `preprocessViewsView()` into a table of instances (view_id/display_id → {instance_id,
  options_method, attribute}) and iterate. **Preferred: refactor to table-driven** so #124's two
  hooks + this story's two hooks don't duplicate structurally.
  - Trade-off: refactoring the existing hooks risks regressing #124. Safer: add sibling private
    helpers (`isStreamModelView()`, `requestedStreamVariant()`) mirroring the existing pattern,
    accepting duplication of two guard methods. Two hooks total, matching #124's shape 1:1.
  - **Recommendation:** add sibling hooks + helpers for now (low-risk, matches merged precedent
    exactly). A future dedup pass can factor the two into a table when a third instance arrives.
- **NEW CSS library `do_streams/model-toggle`** for `data-do-stream-model="..."`-scoped visibility
  toggles (hide the non-selected view). Files per issue scope: `do_streams/css/model-toggle.css`.
- **NEW Kernel test** `StreamModelTogglePreRenderTest` — clone of `DirectoryTogglePreRenderTest`
  with view id `activity_stream` and instance id `stream.model`.
- **NEW E2E** `tests/e2e/model-toggle.spec.ts` — clone of `directory-toggle.spec.ts`.

**Scope reality check (ADVISORY-HOLD candidate for D-gate):**

The issue's premise is "flip one switch, see the difference on `/stream` AND `/my-feed`". But:
- `/my-feed` doesn't exist in main (blocked on #110 which is open, awaiting human review).
- `/stream` in main serves ONE view (activity_stream). There is no "Content view" mounted at
  `/stream` today — the Content view for the sitewide stream would be a NEW second view or a
  second display of `activity_stream`. #129 was to deliver this but has no open PR.

**Two viable scoping options for the brief-gate:**

- **Option A (framework + activity surface only):** Ship the SC-F1 mount on `/stream`
  (activity_stream view) with the switcher rendered, both options visible in the UI. "Content
  view" option is rendered `available: FALSE` (soon) — truthful-copy rule, exactly how #119/#124
  handle unavailable options today. Update `ShowcaseCatalog` entry to `live` pointing at
  `view.activity_stream.page_1`. `/my-feed` mount deferred (attaches automatically when #110
  merges via view-id targeting; see Forward-compat section).
- **Option B (mount both surfaces, both variants):** Requires creating the missing Content view
  for `/stream` in this PR (new second view or second page display). Out of scope for #130 per
  issue text ("Owns (disjoint files): switcher mount/config for the two surfaces" — the *mount*,
  not the underlying content view).

**Recommendation: Option A.** The issue's "Owns" section names only the mount/config file, the
CSS, and the E2E spec — plus **append-only** attachment edits to the two view YAMLs. Content
views themselves are owned by sibling stories. Building the mount + rendering the Content option
as "(soon)" is faithful to the SC-F1 truthful-copy pattern and delivers the framework value
without depending on unmerged siblings.

## Forward-compat check

- `/my-feed`: when #110 merges (which will land `views.view.my_feed.yml` id `my_feed`, display
  `page_1`), the hook's view-id guard should ALSO fire for `my_feed`. Design the guard as a
  small SET of {view_id => display_id} tuples so adding `my_feed` there when the sibling merges
  is a one-line edit, not a hook duplication.
- `stream-model` catalog entry: flipping from `coming/NULL` to `live/view.activity_stream.page_1`
  advances the `/showcase` tour to full coverage. Deep-link from `/showcase` lands on `/stream`
  with the switcher visible.
- No other downstream phase consumes the model-toggle instance today. Safe to add.

## Test coverage baseline

- `DirectoryTogglePreRenderTest` (Kernel) — 3 test methods: positive fires, negative view-id
  no-op, wrapper attribute lands. Direct template for `StreamModelTogglePreRenderTest`.
- `tests/e2e/directory-toggle.spec.ts` — 4 tests: default variant, ?variant=X switch, click each
  option, session persistence. Direct template for `model-toggle.spec.ts`.
- `VariantSwitcherTest` (Unit) — pins the render-array contract shape. May need one added case
  for `streamModelOptions()` if we add that method.
- `ShowcaseCatalogTest` (Unit) — verifies catalog shape. Will need an update when `stream-model`
  entry flips from `coming` to `live`.

## Files this story will touch

**New (F writes):**
- `docs/groups/modules/do_streams/css/model-toggle.css`
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (new `model-toggle` library entry)
- `docs/groups/modules/do_streams/src/Hook/ModelToggleHooks.php` — per issue "Owns" section.
  (Alternative considered: extend `DoShowcaseHooks` — rejected because issue explicitly names
  a do_streams-owned file and this keeps do_showcase from growing every time a new instance
  arrives; the switcher SERVICE and JS stay in do_showcase.)
- `docs/groups/modules/do_streams/do_streams.services.yml` — register `ModelToggleHooks` with
  `@do_showcase.variant_switcher`, `@request_stack` args.
- `docs/groups/modules/do_streams/tests/src/Kernel/StreamModelTogglePreRenderTest.php`
- `docs/groups/modules/do_chrome/tests/src/Unit/StreamModelHelpTextTest.php` (append-only helper)
- `tests/e2e/model-toggle.spec.ts`

**Modified (append-only):**
- `docs/groups/modules/do_showcase/src/VariantSwitcher.php` — add `streamModelOptions()` method.
- `docs/groups/modules/do_showcase/src/ShowcaseCatalog.php` — flip `stream-model` entry to
  `live` with route `view.activity_stream.page_1`.
- `docs/groups/modules/do_chrome/src/HelpText.php` — append `showcase.switcher.stream.model` key.
- `docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php` — one added case.
- `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogTest.php` — update for the
  catalog entry status flip.

**NOT modified in this PR (deferred with note in brief):**
- `views.view.my_feed.yml` — does not exist in main. Hook design accommodates it via a
  view-id set, one-line edit when #110 merges.
- `views.view.activity_stream.yml` — the issue's "append-only attachment edits" language
  suggests attachment displays; but the SC-F1 mount pattern (#124) attaches via HOOKS on the
  render pipeline, not by editing the view config itself. No edit to this YAML expected —
  confirm at D-gate.

## Key architecture decisions to lock in the brief

1. Hook location: `do_streams/src/Hook/ModelToggleHooks.php` (matches issue "Owns" section).
2. Instance id: `stream.model` → HelpText key `showcase.switcher.stream.model`.
3. Wrapper attribute: `data-do-stream-model` (mirrors `data-do-directory-variant`).
4. Options: `content` (available: FALSE — "(soon)"), `activity` (available: TRUE, default).
   Rationale: activity_stream is what `/stream` already renders; content stream needs #129.
5. Selector for JS mirror: `.views-element-container` (same as #124).
6. Target view/display set: `{'activity_stream' => 'page_1'}` today; add `{'my_feed' => 'page_1'}`
   when #110 lands.
7. Catalog: `stream-model` flips to `live`, route `view.activity_stream.page_1`.

## Risks / open items for D-gate

- **Truthful-copy question for the D:** with Content option rendered `available: FALSE (soon)`,
  does the switcher's payoff described in the issue ("Elena's My Feed shows the §6b contrast
  live — ghost-row content in Content view's absence vs social rows in Activity view") still
  hold? The issue's payoff sentence assumes BOTH views are toggleable. If D insists on both
  being live to honor the epic payoff, we escalate to operator as scope-change (adds Content
  view creation, which belongs to #129 not #130). Otherwise proceed with Option A.
- **`stream-model` catalog copy** currently reads "single combined activity stream vs
  per-content-type streams" — that's a DIFFERENT decision than "content-node model vs activity-
  log model". Copy needs to change when we flip status. D to approve the new decision_sentence.
