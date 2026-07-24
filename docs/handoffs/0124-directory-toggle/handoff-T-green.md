# Handoff-T-green: Phase 6 - #124 SC-5 Directory compact-list vs cards toggle

**Date:** 2026-07-23
**Branch:** 124-directory-toggle
**Issue:** #124
**Handoff-F reviewed:** `docs/handoffs/0124-directory-toggle/handoff-F.md`
**Handoff-T-red:** `docs/handoffs/0124-directory-toggle/handoff-T-red.md`

## Summary

F implemented against the Phase-4 RED suite and flagged two test-authorship findings for T to
repair (production code stays as F wrote it — both findings were confirmed correct diagnoses of
**test** defects, not production defects). During re-verification, T also found and fixed two
additional test-authorship defects in the same E2E spec (not previously flagged), all sharing the
same root cause as Finding 2: an assumption baked into the Phase-4 RED test that didn't match the
actual, live, reused markup. All fixes are test-only. Full suite is GREEN.

## Fixes applied

### Finding 1 — Kernel test render-pipeline gap

**File:** `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php`

- Added `renderViewToHtml()` (new helper, ~15 lines): calls `$view->buildRenderable()` then
  `\Drupal::service('renderer')->renderRoot($element)`, forcing Drupal's Renderer to actually run
  the queued `#pre_render` callback (`DisplayPluginBase::elementPreRender()`) AND the subsequent
  `preprocess_views_view` theme hook, in their real order — matching a live page request. Chosen
  over a new Functional test class because `PersonaSwitcherRenderTest.php` (same module) already
  establishes the render-to-HTML-string pattern (`renderInIsolation()`); this reuses that
  precedent rather than adding a new test layer/file.
- Rewrote `testSwitcherInjectedWithThreeOptionsInOrder()` (was: inspect
  `$view->element['#header']['switcher']['#options']` as an array) to instead: call
  `renderViewToHtml()`, then assert against the resulting HTML string —
  `data-do-showcase-instance="directory.layout"` present; `data-do-showcase-id` values appear in
  order `[compact, cards, map]` (via `preg_match_all`); the map option's own `<a>` tag (isolated
  via `preg_match` on `<a\b[^>]*data-do-showcase-id="map"[^>]*>` — attribute order within the tag
  is not assumed) carries `aria-disabled="true"`; visible labels `Compact list` / `Cards` /
  `Map (soon)` all present.
- `renderView()` (the original array-level helper) is unchanged and still used by the other 7
  tests, whose assertions (`#cache['contexts']`, `#attributes['data-do-directory-variant']`,
  `#attached['library']`) genuinely survive `DisplayPluginBase::buildRenderable()`'s unmodified
  pass-through of `$view->element` and do not need a full render pass.
- Updated the class docblock to explain the render-pipeline gap and the fix (matches this
  project's convention of documenting non-obvious test-harness reasoning in the class docblock,
  as the existing `SessionNotFoundException` fix from Phase 4 already does).

**Spot-check (proves the fix pins real behavior, not a tautology):** temporarily short-circuited
`DoShowcaseHooks::preprocessViewsView()` to `return;` immediately (before the
`isDirectoryView()`/switcher-build logic), re-ran the single test — it failed (1 failure: missing
`data-do-showcase-instance="directory.layout"` string, 25 assertions run before the failing one).
Restored the production file via `git restore docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php`
(the file was `MM` in git status — F's implementation already staged, my temporary mutation was
the unstaged layer on top; `git restore` cleanly dropped only the mutation, confirmed via
`git diff` showing an empty unstaged diff afterward). Re-ran the full kernel suite — 8/8 GREEN
again.

### Finding 2 — E2E selector (one-line)

**File:** `tests/e2e/directory-toggle.spec.ts`

`directoryWrapperLocator()`'s selector changed from `.view-content[data-do-directory-variant]` to
`.views-element-container[data-do-directory-variant]`, per F's live-DOM verification (the
attribute lands on the `.views-element-container` wrapper `\Drupal\views\Element\View::
preRenderViewElement()` adds, not the inner, unattributed `.view-content` div). The doc-comment
reference to the selector (in the file's header block) updated identically by the same
string-replace.

### Two additional test-authorship defects found during GREEN verification (not previously
flagged by F — same root-cause family as Finding 2: a Phase-4 assumption that didn't match the
real, live, reused markup)

**File:** `tests/e2e/directory-toggle.spec.ts`

1. **Anchored `/^Cards/i` radio-name matcher (3 occurrences)** — fails whenever "Cards" is the
   currently-selected option, because `VariantSwitcher::build()` (pre-existing #119 code,
   unchanged by this story) prepends a non-color `●` selection glyph directly into the radio's
   accessible name with no `aria-hidden` (confirmed live via curl: the rendered `<span
   data-do-showcase-label="true">` reads `● Cards`, not `Cards`). The established, already-green
   convention in `showcase.spec.ts` (11 occurrences, same switcher component) uses unanchored
   `/Cards/i` for exactly this reason. My Phase-4 RED test should have followed that precedent.
   Fixed: removed the `^` anchor in all 3 occurrences (test-file-wide replace, verified only 3
   existed and all were in radio-name-matcher context).
2. **Wireframe-literal bracket notation + assumed Drupal field class (2 assertions)** — one
   asserted `/\[(Open|Moderated|Invite only)\]/` against the row text, the other asserted
   `.field--name-field-group-description` was hidden. Neither exists in the real rendered page:
   the visibility badge (reused verbatim from `groups_chrome`'s `.gc-directory-card__visibility`
   markup, confirmed via curl against a running seeded page) renders as plain, unbracketed text
   (`Open` / `Moderated` / `Invite Only`, matching `VisibilityTooltip.php`'s three stored-value
   labels) — the wireframe's `[Open]` was illustrative ASCII-art notation, not a literal
   rendered-text contract. Similarly, the description snippet carries the class
   `.gc-directory-card__snippet` (confirmed against `directory-compact.css`'s own selector list,
   which explicitly hides `.gc-directory-card__snippet` in compact mode) — never a Drupal
   `field--name-*` class, because F's handoff documents the compact CSS reuses
   `groups_chrome`'s existing card markup verbatim rather than adding new Views fields. Fixed
   both assertions to match the real markup, with inline comments explaining the gap so it does
   not recur in a future edit to this spec.

All fixes above are **test-only**. No production file (`src/`, `js/`, `css/`, `.libraries.yml`,
`.services.yml`) was edited — confirmed via `git status`/`git diff` on every production path
listed in F's "Files changed" section; the only production-file touch during this phase was the
temporary Finding-1 spot-check mutation, reverted via `git restore` before finalizing.

## GREEN confirmation

**Kernel** (`ddev exec "SIMPLETEST_DB=mysql://db:db@db:3306/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php"`):

```
 ⚠ Switcher injected with three options in order   (passes; ⚠ = pre-existing Twig/Symfony deprecation notice, not an assertion failure)
 ✔ View declares url query args variant cache context directly
 ✔ No query param defaults wrapper to cards
 ✔ Compact query param sets wrapper to compact
 ✔ Unavailable map query param falls back to compact
 ✔ Unknown query param falls back to compact
 ✔ Switcher and directory compact libraries attached
 ✔ Hook does not fire for a different view id
Tests: 8, Assertions: 202, Deprecations: 5, PHPUnit Deprecations: 9.
```

8/8 GREEN (0 failures; the "OK, but there were issues!" summary line reflects only
framework-level deprecation notices already present before this story, unrelated to this test's
own assertions).

**E2E** (`BASE_URL="https://gm124-directory.ddev.site" npx playwright test tests/e2e/directory-toggle.spec.ts --reporter=list`):

```
11 passed, 1 skipped (5.2s)
```

12/12 (11 pass + 1 correctly self-skipping conditional pager test, per its own `test.skip()` gate
on seed size).

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Assemble | `ddev exec "bash scripts/ci/assemble-config.sh"` | exit 0, modules+config copied | `==> assemble-config: done` | PASS |
| Kernel — directory-toggle test | see above | 8/8 pass | 8/8 pass | PASS |
| Kernel + Unit — full do_showcase regression | `ddev exec "SIMPLETEST_DB=... php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_showcase/tests/src/Kernel web/modules/custom/do_showcase/tests/src/Unit"` | 68/68 pass | `Tests: 68, Assertions: 597, ... ` (0 failures) | PASS |
| Lint — modified Kernel test file | `ddev exec "php vendor/bin/phpcs --standard=Drupal,DrupalPractice ...DirectoryTogglePreRenderTest.php"` | no NEW errors vs. the Phase-4 staged version | 14 errors, all 14 confirmed pre-existing (diffed byte-for-byte against `git show :<path>` piped through phpcs via stdin) — 0 new | PASS |
| E2E — directory-toggle.spec.ts | see above | 12/12 (11 pass + 1 correct skip) | 12/12 | PASS |
| E2E — full regression (4 spec files) | `BASE_URL="https://gm124-directory.ddev.site" npx playwright test tests/e2e/directory-toggle.spec.ts tests/e2e/directory-cards.spec.ts tests/e2e/directory-filters.spec.ts tests/e2e/showcase.spec.ts --reporter=list` | 40/40 (39 pass + 1 skip) | `39 passed (16.0s)`, 1 skipped | PASS |
| Site smoke | `curl -sk -o /dev/null -w "%{http_code}\n" https://gm124-directory.ddev.site/all-groups` | 200 | `200` | PASS |

## Tier 2 results

- **Test coverage vs. acceptance criteria:** every brief.md AC is backed by a specific
  Kernel/Unit/E2E test (mapped below in "Acceptance criteria status"). No coverage hole found.
- **Test quality (test-quality.md §7):**
  - Each test names a specific behavior (method names + docblocks: e.g.
    `testUnavailableMapQueryParamFallsBackToCompact` pins exactly one fallback path).
  - Each fails in isolation for the right reason: spot-checked the repaired kernel test (see
    "GREEN confirmation" spot-check above) and confirmed via the Phase-4 RED handoff that every
    other test in the suite was already confirmed to fail for the right reason before F
    implemented (missing method/render-array key/null value, never a setup error).
  - Cheapest-sufficient tier: Kernel for the Views-runtime hook contract (cannot be exercised at
    Unit tier; would be redundant/expensive at Functional/E2E tier for a server-side
    render-array/cache-metadata concern) — confirmed still correct after the Finding-1 repair
    (the repair changed HOW the Kernel test observes the behavior, not WHICH tier it belongs at).
    E2E for the actual click-through/session-persistence/keyboard-operability behaviors a Kernel
    test cannot observe.
  - No duplication found: the 3 layers (Kernel/Unit/E2E) each pin a disjoint concern — Kernel
    pins the server-side render-array/cache/hook-scoping contract, Unit pins the catalog-entry
    flip and the hook-registration reflection, E2E pins the live click-through/persistence/a11y
    behaviors. Proportionate to the change (8 Kernel + 2+2 Unit + 12 E2E = 24 new tests for a
    5-file production change spanning a hook, a service, a controller, a CSS file, and a JS
    behavior).
  - **Repaired defects were genuinely invalid tests, not passable-either-way tests:** all 4 fixes
    this phase (Finding 1, Finding 2, and the 2 additional defects) were tests that would have
    FAILED against correct production code for reasons unrelated to the behavior they claimed to
    pin (a render-pipeline gap in the test harness; a stale/incorrect selector; an anchored regex
    that breaks on the pre-existing `●` glyph; an assumed class name that never existed) — none
    were "redundant coverage" to prune, all were repairs to make the assertion match its own
    stated intent.
- **Type safety:** no `any`/untyped casts introduced; `DirectoryTogglePreRenderTest.php` and
  `directory-toggle.spec.ts` both remain fully typed (`declare(strict_types=1)` in the PHP file;
  TypeScript throughout the spec, `Page` type imported and used consistently).
- **Error handling / data integrity:** `testHookDoesNotFireForADifferentViewId` (unchanged, still
  GREEN) continues to pin that a different view/display gains neither the switcher nor the cache
  context — a defensive negative case protecting against over-broad hook scoping.
- **API contract:** the switcher render-array contract (`#options`, each with `id`/`available`)
  and the wrapper-attribute contract (`data-do-directory-variant` on the resolved value) are both
  still asserted, now correctly observed via the real render pipeline instead of a pre-render
  snapshot.
- **Security:** N/A — no new user input handling beyond the existing `?variant=` query-arg read
  (already covered: unavailable/unknown values fall back gracefully, never produce a broken/blank
  render — `testUnavailableMapQueryParamFallsBackToCompact` /
  `testUnknownQueryParamFallsBackToCompact`).
- **Migration safety:** N/A — no schema/migration in this story.
- **Playwright suite structural health:** `npx playwright test` exits 0 across all 4 spec files
  (40 tests, 39 pass + 1 correctly-conditional skip). No coverage hole flagged for U — every
  surface the brief/wireframe describes (switcher render, live toggle, filter/pager
  preservation, session persistence, URL-wins, map-fallback, cross-page persistence, keyboard
  operability, non-color badge) has a passing automated test; U should still independently
  confirm client-side Alpine/HTMX-adjacent init and visual polish per its own mandate (headless
  Tier 1/2 checks cannot observe visual/interactive nuance).

## Acceptance criteria status

| Brief.md AC | Backing test | Status |
|---|---|---|
| Switcher renders at top of `/all-groups`, "Viewing:", 3 options in order (Cards default / Compact list / Map soon+aria-disabled) | Kernel `testSwitcherInjectedWithThreeOptionsInOrder` (repaired); E2E `switcher renders with three options, Cards selected by default...` | PASS |
| Selecting Compact list switches to dense rows, no full reload, filters/page preserved | E2E `clicking Compact list flips the wrapper attribute live...`; E2E `filters are preserved across a toggle both ways`; E2E `the pager position is preserved...` (conditional skip, seed has no page 2) | PASS |
| Selecting Cards restores exact card presentation, no wrapper class/no compact CSS match | E2E same test (toggle back to Cards) + `directory-cards.spec.ts default cards view is unaffected` | PASS |
| Choice persists via SC-F1 sessionStorage key | E2E `session persistence: compact selection survives a reload; a fresh session defaults to cards` | PASS |
| `?variant=` wins over sessionStorage | E2E `?variant=compact URL wins over a stale sessionStorage value of cards` | PASS |
| Compact rows show same access-filtered set (pure presentation) | E2E `filters are preserved across a toggle both ways` (row count identical across variants) | PASS |
| ⓘ tooltip renders shipped HelpText copy | Reused verbatim from #119 (`VariantSwitcher::build()`'s `#tooltip`); no new test needed — pre-existing `VariantSwitcherTest` coverage still green | PASS (unchanged) |
| `/showcase` catalog entry flips to live, routes to `/all-groups` | Unit `testDirectoryPresentationEntryIsLive` / `testDirectoryPresentationEntryRoutesToAllGroupsPage` | PASS |
| `tests/e2e/directory-toggle.spec.ts` toggles both ways, filters preserved, session persistence | Full spec file, 12 tests | PASS |
| Existing suites stay green (`directory-cards.spec.ts`, `directory-filters.spec.ts`) | Full regression run, 28/28 | PASS |
| WCAG 2.2 AA (labels, keyboard, focus, contrast, non-color status) | E2E `WCAG-adjacent smoke: switcher is keyboard-operable and the visibility badge is text, not color-only` (repaired) | PASS (automated smoke only — S/U's visual-contrast judgment still required per their own mandate) |
| No new HelpText entry needed | Confirmed by F; no test needed (absence-of-change) | PASS (by construction) |

## Blocking issues

None.

## Advisory notes

- The `●` selection-glyph-in-accessible-name behavior (no `aria-hidden`) is pre-existing #119
  code, unrelated to this story's changes, and already the convention `showcase.spec.ts` works
  around with unanchored name matchers. Not a new defect introduced here; flagging only so a
  future story doesn't rediscover the same "anchored regex breaks on selected state" trap a
  third time.
- `testSwitcherInjectedWithThreeOptionsInOrder`'s HTML-string assertions are slightly more
  brittle than array-level assertions (a Twig template whitespace/attribute-order change could
  require updating the regex), which is an inherent trade-off of testing at the render-HTML
  layer rather than the pre-render array layer — accepted here because it is the ONLY layer at
  which this specific behavior (the `preprocess_views_view`-injected switcher, post-`#pre_render`-
  overwrite) can be correctly observed at all.

## Staged files

```
git add \
  "docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php" \
  "tests/e2e/directory-toggle.spec.ts"
```

(Both already carried Phase-4 staged content; this phase's fixes are layered on top of the same
paths, now re-staged with the repairs included. No other file staged by T this phase — production
files remain exactly as F staged them.)
