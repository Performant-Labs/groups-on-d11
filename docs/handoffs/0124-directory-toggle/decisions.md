# Decision journal — #124 SC-5

## Phase 1 (O) — Survey + Brief

- **Decided:** Single Page display + wrapper CSS class drives variant (no
  `page_2`).
- **Decided:** Ship three switcher options day 1 (`compact`/`cards`/`map(soon)`) for
  clean forward-compat with #125.
- **Decided:** Reuse `directory.layout` instance_id verbatim → free tooltip + shared
  sessionStorage key with `/showcase`'s stub switcher.
- **Decided:** Inject switcher via a new `#[Hook('views_pre_render')]` method on
  existing `DoShowcaseHooks` (extend, not new file).
- **Assumed:** Member count field is achievable via Views' aggregation; degrade to
  type+visibility if it forces schema/relationship changes (flagged to F).
- **Assumed:** `directory-presentation` catalog entry flips to `live` pointing at
  `view.all_groups.page_1` — F to verify route id.
- **Hedged:** WCAG contrast on the visibility badge — leave to D wireframe + S
  audit; SC-F1 focus styles reused.
- **Evidence:** survey.md; HelpText.php:171; ShowcaseController.php:78-95;
  DoShowcaseHooks.php pageTop pattern; ShowcaseCatalog.php entries.

## Phase 2 (D) — Wireframe

- **Decided:** Wrapper CSS-variant attribute (`data-do-directory-variant="cards"|"compact"`)
  lives on the view's `.view-content` element — the one element `hook_views_pre_render` can
  reliably annotate without restructuring row/style plugins. Absent/`="cards"` = today's cards
  exactly; only `="compact"` matches new CSS.
- **Decided:** Compact row field order: name (linked) / type badge / member count / visibility
  badge. Visibility badge is a TEXT label (`[Open]`/`[Moderated]`/`[Invite only]`, sourced from
  the same three stored values `VisibilityTooltip.php` already keys on), matching the `/showcase`
  catalog's non-color `[ live ]`/`[ coming ]` badge convention — never color-only.
  Compact rows keep card-mode's base type scale (no separate smaller font) — density comes from
  dropping the description/created columns and per-row card chrome, not from shrinking text.
  Both variants' fields render for both variants (per brief); CSS alone hides/reflows per state.
- **Decided:** No re-design of the switcher device itself (radiogroup, `●` glyph, roving
  tabindex, ⓘ trigger, focus ring, no-JS fallback) — reused verbatim from
  `docs/handoffs/0119-variant-framework/wireframe.md` Surface 1. This wireframe supplies only the
  delta: option set/order on `/all-groups`, mount point (view `#header`), compact-row layout, and
  the wrapper-attribute contract.
- **Decided:** Fallback behavior is explicitly a hook/Twig responsibility, not JS-only — the
  server must resolve `?variant=` to the correct wrapper attribute on first render (mirroring
  `VariantSwitcher::resolveSelection()`'s first-available-fallback rule), carrying the same
  `url.query_args:variant` cache context SC-F1 already established.
- **Assumed:** Shared sessionStorage key (`doShowcase.variant.directory.layout`) causing
  `/showcase` → `/all-groups` cross-page persistence is intended UX, per the brief. Documented
  explicitly so U tests for it rather than treating it as a defect.
- **Hedged:** The exact mechanism connecting the switcher's click/keydown selection to the
  `.view-content` wrapper attribute (new small JS behavior vs. CSS `:has()` selector) is left as
  an F implementation choice — flagged as an open question since it determines whether this
  story touches the SHARED `do_showcase.switcher.js` file.
- **Hedged:** Filtered-to-zero-results empty copy (view currently has one empty-region string,
  possibly misleading when groups exist but none match filters) — flagged as an open question,
  not fixed in this wireframe (pre-existing behavior, out of this story's stated scope unless O
  says otherwise).
- **Hedged:** Member-count "unavailable" vs. "zero" distinction — recommend accepting "0 members"
  as a simplification if Views aggregation can't tell the difference, pending O confirmation.
- **Evidence:** VariantSwitcher.php (build()/resolveSelection()/normalizeOptions()); do-showcase-
  variant-switcher.html.twig; do_showcase.switcher.js (STORAGE_PREFIX, select(), moveSelection());
  HelpText.php:171 (showcase.switcher.directory.layout copy, already shipped); VisibilityTooltip.php
  (OPTION_COPY_KEYS: open/moderated/invite_only); views.view.all_groups.yml (current fields: label/
  field_group_description/created, no page_2 display, single empty area_text_custom region);
  field.storage.group.field_group_type.yml (entity_reference to taxonomy_term, not a list field);
  docs/handoffs/0119-variant-framework/wireframe.md Surface 1 (switcher device spec, reused
  verbatim).

## Phase 2 (D) — Wireframe
See wireframe.md and handoff-D.md.

## Phase 2 gate (O) — Design approval
- **Decided:** APPROVED by operator 2026-07-22.
- **Decided (O resolutions of D's open questions):**
  1. JS wiring = extend `do_showcase.switcher.js` with a generic wrapper-mirror callback (not CSS `:has()`).
  2. Filtered-empty copy = out of scope, no follow-up (POC-no-follow-ups).
  3. Member count = accept "0 members" for both zero and unavailable.
- **Evidence:** operator message 2026-07-22.

## Phase 3 (A) — Up-front plan review
- **Decided:** PASS. Plan extends every established seam (`DoShowcaseHooks`, `VariantSwitcher::build('directory.layout',…)`, `do_showcase.switcher.js`, `ShowcaseCatalog::entries()`, `views.view.all_groups.yml`) rather than forking parallel objects.
- **Confirmed:** `#[Hook('views_pre_render')]` is the right seam (matches `DoStreamsHooks`/`DoGroupPinHooks` `viewsPostRender()` precedent for view-level attach + decorate). F should also set `url.query_args:variant` on `$view->element['#cache']` directly, not rely only on the child metadata `VariantSwitcher::build()` already declares.
- **Confirmed:** Route id `view.all_groups.page_1` is correct (`PageHelp.php:72`, `PageHelpRouteMapTest.php:46`); F can flip `directory-presentation` to `status:'live'` with confidence.
- **Confirmed:** No existing `preprocess_views_view_fields__all_groups()` implementation (only a docblock mention in `HelpText.php:239`) — no conflict with the "fields render in both variants" plan.
- **Confirmed:** Shared sessionStorage key across `/showcase` and `/all-groups` is safe — page-load reselection uses `persist=false`, only user clicks write.
- **Advised:** JS extension should be data-driven (`data-do-showcase-mirror-attribute`/`-selector` on the radiogroup wrapper) rather than an instance-id branch, since this codebase has zero CustomEvent precedent to reuse and future switcher instances (SC-4/SC-6/ST-8) will benefit.
- **Advised:** Optional — hoist the three-option `[compact, cards, map(unavailable)]` literal to a shared const/static so #125 flips the map option in one place (currently duplicated between `ShowcaseController::page()` and this story's new hook).
- **Evidence:** handoff-A-plan.md.

## Phase 4 (T) — Author tests (RED)
- **Decided:** Kernel test (`DirectoryTogglePreRenderTest`) is the cheapest tier that can
  actually invoke `hook_views_pre_render` (a Views-runtime hook) — a pure Unit test cannot
  exercise it, and Functional/E2E would duplicate a server-side render-array/cache-metadata
  contract at much higher cost. Uses `$view->preview()` (not `execute()`) to force the render
  pipeline so the hook's side effects appear on `$view->element`.
- **Decided:** Fixtures are module-local (`do_showcase/tests/fixtures/config/`), never a
  source-relative path — `views.view.all_groups.yml` is a trimmed copy of the real config;
  `views.view.activity_stream.yml` is a deliberately MINIMAL synthetic view (not the real,
  heavier `entity:node`/comment-dependent one), used only to pin the "hook does not fire for a
  different view id" negative case cheaply.
- **Decided:** `ShowcaseCatalogDirectoryLiveTest` is a new, narrow companion file (not appended
  to the existing SC-F1 `ShowcaseCatalogTest`) — that file pins the catalog's general shape
  rules, not this one entry's specific flip.
- **Decided:** `DoShowcaseHooksViewsPreRenderRegistrationTest` is a new pattern (reflection over
  `#[Hook(...)]` attributes) — no existing test in this codebase does this; added per the brief's
  explicit request for a defensive "shouldn't break existing theme registration" check.
- **Fixed (test-harness, not a test-defect):** pushing a `Request` onto `request_stack` without
  an attached `Session` made `KernelTestBase::tearDown()`'s own cleanup throw
  `SessionNotFoundException`. Added `pushRequestWithSession()` helper (attaches a
  `MockArraySessionStorage`) mirroring a real Drupal request cycle.
- **Assumed:** The exact `$view->element` array path for the `data-do-directory-variant`
  wrapper attribute is an F implementation choice — `wrapperVariantAttribute()` checks three
  plausible keys (`#attributes`, `#content_attributes`, `#view_content_attributes`); flagged to F
  to report back if none match their implementation.
- **Evidence:** handoff-T-red.md; RED run output (kernel: 7 fail / 1 correctly-passing negative
  case; unit: 3 fail / 1 correctly-passing defensive case; e2e: 12 tests enumerate via
  `--list`, not executed for real at RED).

## Phase 5 (F) — Implement (GREEN)

- **Decided:** TWO cooperating hooks, not the brief's single `viewsPreRender()` method, mount
  the switcher — `viewsPreRender()` keeps the `#cache`/`#attributes`/`#attached` writes (these
  DO survive Views' render pipeline unchanged, since `DisplayPluginBase::buildRenderable()`
  returns `$view->element` directly), and a NEW `preprocessViewsView()`
  (`#[Hook('preprocess_views_view')]`) injects the switcher into `$variables['header']`. Root
  cause (traced through Views core, then confirmed empirically against a live installed site):
  `DisplayPluginBase::elementPreRender()` — a `#pre_render` callback queued on the render array
  `Page::execute()` builds — unconditionally overwrites `$element['#header']` with the real
  (empty, since `views.view.all_groups.yml` declares no `header:` area) `renderArea('header', …)`
  output, and that overwrite runs strictly AFTER `hook_views_pre_render` but strictly BEFORE
  `hook_preprocess_views_view`. Writing `#header` inside `viewsPreRender()` alone is therefore
  silently discarded on a real page load — a genuine gap the Kernel test's
  `$view->preview()` + direct-`$view->element`-read pattern cannot observe (it never triggers
  `#pre_render` at all). Confirmed both from source (`ViewExecutable.php`,
  `DisplayPluginBase.php`, `Page.php`, `Element/View.php`) and empirically (curl + a direct
  throwaway Playwright script against a fully installed/seeded local DDEV site).
- **Decided:** The wrapper attribute (`data-do-directory-variant`) lands on
  `.views-element-container` on a live page, NOT `.view-content` (the wireframe's named attach
  point, which turned out to be a hard-coded, un-attributed div in Olivero's
  `views-view.html.twig` — `groups_chrome` inherits it unmodified) and NOT `.view.view-id-all_groups`
  (which gets its OWN, separate `attributes` Twig variable that does not inherit
  `$element['#attributes']`). `.views-element-container` is the wrapper
  `\Drupal\views\Element\View::preRenderViewElement()` adds via `#theme_wrappers => ['container']`
  around the themed view output — confirmed by `curl`-inspecting the live rendered DOM, not
  assumed from class-name convention. `directory-compact.css` and the JS mirror-selector both
  target it. Documented as a deviation from the wireframe's literal attach-point NAME (not its
  contract: attribute name/values/behavior are all unchanged).
- **Decided:** `VariantSwitcher::directoryLayoutOptions()` (non-static, `$this->t()`-translating
  via a literal per-id `match()`) replaces a first-pass static `directoryLayoutOptionSpecs()` +
  caller-side `t($variable)` pattern, after `phpcs --standard=Drupal,DrupalPractice` correctly
  flagged "Only string literals should be passed to t() where possible" at both call sites.
  `ShowcaseController::page()` now also calls this shared method (A-advisory #7 fully realized —
  not just an optional literal-duplication tolerance, but one genuine source of truth).
  `resolveCurrent()` (public wrapper around the existing private `resolveSelection()`) lets
  `viewsPreRender()` independently compute the resolved variant without a hand-duplicated
  fallback rule.
- **Confirmed (not fixed — flagged to T):** Kernel test
  `testSwitcherInjectedWithThreeOptionsInOrder` fails because its `renderView()` helper never
  triggers Drupal's Renderer (`#pre_render` never runs), so it cannot observe the
  `elementPreRender()` overwrite `preprocessViewsView()` is specifically built to survive.
  Verified this is the ONLY kernel-test failure (7/8 pass; 67/68 across the full do_showcase
  Kernel+Unit suite) and that production behavior is correct via live-site inspection. Did not
  edit the test.
- **Confirmed (not fixed — flagged to T):** `tests/e2e/directory-toggle.spec.ts`'s
  `directoryWrapperLocator()` helper targets `.view-content[data-do-directory-variant]`, which
  never matches in this theme's rendered DOM (see above). All 8 of the spec's failures share
  this one root cause; independently verified (direct Playwright script, corrected selector)
  that every underlying behavior (default state, live toggle, session persistence, URL-wins,
  map-fallback, cross-page persistence, keyboard operability) works correctly. 3 tests that
  don't use this selector pass outright; 1 conditionally skips correctly. The 3 regression
  suites (`directory-cards.spec.ts`, `directory-filters.spec.ts`, `showcase.spec.ts`) — 28
  tests — are 100% green, confirming zero regression from this story's changes, including to
  the `/showcase` stub switcher after the `VariantSwitcher`/`ShowcaseCatalog` shared-method
  refactor. Did not edit the test.
- **Verified (lint):** `phpcs --standard=Drupal,DrupalPractice` — 0 errors on all 6 touched
  PHP/JS/CSS production files. 9 warnings on `DoShowcaseHooks.php` confirmed pre-existing
  (inside untouched `pageTop()`/`personaBanner()` bodies, reproduced against the committed HEAD
  copy). 5 "errors" on `do_showcase.switcher.js` confirmed a pre-existing phpcs false-positive
  (PHP-oriented TRUE/FALSE/NULL-uppercase sniff misapplied to valid lowercase JS keywords;
  reproduced identically against the committed HEAD copy of this same file). 0 errors/warnings
  on both edited YAML files.
- **Evidence:** handoff-F.md; live curl/Playwright inspection against a fully installed +
  config-imported + seeded local DDEV site (`gm124-directory`, mirroring
  `.github/workflows/test.yml`'s E2E job's exact install/seed sequence); Views-core source trace
  (`ViewExecutable.php`, `DisplayPluginBase.php`, `Page.php`, `Element/View.php`,
  `views-view.html.twig` in both `web/core/modules/views/templates/` and
  `web/core/themes/olivero/templates/views/`); kernel/unit/phpcs/E2E run output pasted in
  handoff-F.md's Tier 1 self-check.

## Phase 6 (T) — Verify tests (GREEN) + Tier 2

- **Decided (Finding 1 repair):** `testSwitcherInjectedWithThreeOptionsInOrder`'s original
  `renderView()`-based array inspection of `$view->element['#header']` was invalid — it never
  ran the array through Drupal's Renderer, so the queued `#pre_render` callback
  (`DisplayPluginBase::elementPreRender()`) never fired, meaning the test's snapshot of `#header`
  was NOT what a real page render produces (F's finding, confirmed independently by reading
  `DisplayPluginBase::render()`/`elementPreRender()` directly: `#header` is unconditionally
  overwritten by a queued `#pre_render` callback that runs strictly after `hook_views_pre_render`
  but strictly before `hook_preprocess_views_view`, where `DoShowcaseHooks::preprocessViewsView()`
  actually injects the switcher). Fixed by adding `renderViewToHtml()` — calls
  `$view->buildRenderable()` then `\Drupal::service('renderer')->renderRoot($element)` — forcing
  BOTH the `#pre_render` overwrite and the `preprocess_views_view` injection to run in their real
  order, then asserting against the resulting HTML string (`data-do-showcase-instance`,
  `data-do-showcase-id` order, `aria-disabled` on the map option's own `<a>` tag, "(soon)" suffix,
  correct labels) rather than the pre-render array. Chose Kernel+Renderer over a new Functional
  test class since `PersonaSwitcherRenderTest` already establishes this exact
  render-to-HTML-string pattern in this module (`renderInIsolation()`); this reuses that
  precedent rather than introducing a new test-layer/file. `renderView()` (the original
  array-level helper) is kept, unchanged, for the other 7 tests whose assertions (`#cache`,
  `#attributes`, `#attached`) genuinely survive `buildRenderable()`'s unmodified pass-through and
  do not need a full render pass.
- **Fixed (spot-check, not a repair):** confirmed the repaired test still fails for the right
  reason when the behavior is removed — temporarily short-circuited
  `DoShowcaseHooks::preprocessViewsView()` to an early `return`, re-ran, observed the test fail
  (25 assertions run, 1 failure on the missing `data-do-showcase-instance` string), then restored
  the production file via `git restore` (file was `MM` — F's changes staged, my mutation
  unstaged; restore cleanly dropped only the mutation). Confirms the test pins real behavior, not
  a tautology.
- **Decided (Finding 2 repair):** `directoryWrapperLocator()`'s selector changed from
  `.view-content[data-do-directory-variant]` to `.views-element-container[data-do-directory-variant]`,
  per F's live-DOM verification. One-line fix, as F characterized it.
- **Fixed (two additional test-authorship defects found during GREEN verification, not flagged
  by F, same root cause as Finding 2 — a Phase-4 test-authorship gap between the wireframe's
  illustrative notation/assumed class names and the actual reused `groups_chrome` markup):**
  1. `directory-toggle.spec.ts` used `getByRole('radio', { name: /^Cards/i })` (anchored) in 3
     places — fails whenever "Cards" is the currently-selected option, because
     `VariantSwitcher::build()` prepends a non-color `●` selection glyph directly into the
     radio's accessible name (`● Cards`, confirmed live via curl) with no `aria-hidden` on the
     glyph — pre-existing #119 behavior, unchanged by this story. The established, already-green
     convention in `showcase.spec.ts` (11 occurrences) uses unanchored `/Cards/i`; my Phase-4 RED
     test should have followed that precedent and did not. Fixed by removing the `^` anchor in
     all 3 occurrences, matching `showcase.spec.ts`.
  2. Two assertions used the wireframe's illustrative ASCII-art bracket notation
     (`/\[(Open|Moderated|Invite only)\]/`) and an assumed Drupal field CSS class
     (`.field--name-field-group-description`) as if they were literal rendered-output contracts.
     Neither exists in the actual rendered page: the visibility badge (reused verbatim from
     `groups_chrome`'s existing `.gc-directory-card__visibility` markup, confirmed via curl) is
     plain, unbracketed text (`Open`/`Moderated`/`Invite Only`, per `VisibilityTooltip.php`), and
     the description snippet carries `.gc-directory-card__snippet` (confirmed in
     `directory-compact.css`'s own selector list), never a `field--name-*` class — F's handoff
     explicitly documents the compact CSS reuses `groups_chrome`'s card markup verbatim rather
     than new Views fields, which these 2 assertions did not account for. Fixed both regexes/
     selectors to match the real, live, reused markup; added inline comments explaining why
     (avoids the same gap recurring in a future edit).
- **Verified:** all three fixes are test-only — zero production-file edits (confirmed via
  `git status`/`git diff` on every `src/`/`js/`/`css/`/`.libraries.yml`/`.services.yml` path: no
  changes beyond the temporary spot-check mutation, which was reverted via `git restore` before
  finalizing).
- **Evidence:** handoff-T-green.md; kernel run output (8/8, then 68/68 full regression); E2E run
  output (12/12 directory-toggle.spec.ts incl. 1 correct skip, then 40/40 across all 4 spec
  files incl. the same 1 skip); live curl inspection of `/all-groups` and `/all-groups?variant=compact`
  confirming real rendered markup for the switcher, visibility badge, and description snippet;
  `PersonaSwitcherRenderTest.php` (precedent for the renderRoot/renderInIsolation-to-HTML-string
  pattern); `DisplayPluginBase.php` lines 2208-2314 (render()/elementPreRender()) confirming F's
  root-cause trace independently.

---

## Phase 8 — U (Playwright UI Walkthrough) — 2026-07-23

**Verdict:** PASS.

Drove `https://gm124-directory.ddev.site` in headless chromium end-to-end,
covering all six brief surfaces (switcher render, compact-row layout,
wrapper-attr contract, `?variant=` fallbacks, cross-page persistence,
filter/paging preservation), plus the `/showcase` regression walk and a
WCAG 2.2 AA smoke pass (radiogroup ARIA, keyboard operation with
Enter/Space/arrows and Map correctly skipped, visible focus ring,
non-color status badges, contrast measurement).

Zero console errors, zero network requests on the client-side toggle,
zero flash-of-cards on `?variant=compact` direct load. Session
persistence works both within `/all-groups` (reload) and across the
`/showcase`↔`/all-groups` boundary via the shared
`doShowcase.variant.directory.layout` sessionStorage key. Fresh incognito
context correctly defaults to cards.

One advisory (NOT blocking): `.gc-badge--success` ("Open" visibility badge)
measures 3.68:1 contrast — pre-existing `groups_chrome` token pair
(`--gc-color-success` #218d5b on `--gc-color-success-050` #e6f4ec, defined
in `web/themes/custom/groups_chrome/css/tokens.css:37-38`), not
introduced by this story. Explicitly documented in F handoff as reused
verbatim from card mode. Per POC no-follow-ups convention, flagged for a
future groups_chrome cleanup, not filed as a blocker.

Handoff: `docs/handoffs/0124-directory-toggle/handoff-U.md` (+ 6
screenshots under `screenshots/`).

## Phase 9 (S) — Final spec audit — 2026-07-23

**Verdict:** PASS. Ready for rebase + PR.

- **Confirmed:** All 13 brief ACs backed by Kernel/Unit/E2E test + U walkthrough evidence (matrix in handoff-S.md §1). No coverage holes.
- **Confirmed:** Issue #124 → brief translation faithful. Single documented, O-approved expansion (3 options day 1 for #125 forward-compat) is a strict superset of the issue AC, not scope drift.
- **Confirmed:** Both F-documented wireframe deviations (`.views-element-container` attach point + two cooperating hooks) are empirically necessitated by Drupal Views' actual render pipeline (verified against Views-core source + live-site DOM). Correctly NOT routed back to D — a re-run would arrive at the same answer for zero user-visible delta.
- **Confirmed:** Merge order for #125 preserved — `docs/groups/config/views.view.all_groups.yml` (the sole source-of-truth file) is `git status`-clean. Only staged file matching that name is the module-local test fixture at `docs/groups/modules/do_showcase/tests/fixtures/config/`. Zero collision risk.
- **Confirmed:** No parallel/duplicate paths — `VariantSwitcher::directoryLayoutOptions()` is the single source of truth for the three-option list, and `ShowcaseController::page()` line 107 now calls it (A-advisory #7 fully realized, not merely tolerated). #125's map-flip is one edit in one method.
- **Confirmed:** WCAG advisory disposition correct — `.gc-badge--success` 3.68:1 contrast is pre-existing `groups_chrome` token debt, reused verbatim from card mode; #145 is the correct backstop; POC-no-follow-ups convention honored, no GH issue filed.
- **Confirmed:** CI-clean — no build artifacts staged under `web/modules/custom/` or `config/sync/`, phpcs errors on touched files reproducibly pre-existing, kernel+unit 68/68 GREEN, E2E 40/40 GREEN.
- **Evidence:** handoff-S.md; `git diff --cached --name-only`; grep of `VariantSwitcher.php:91,276` + `ShowcaseController.php:107`; `git status` on `docs/groups/config/views.view.all_groups.yml`.
