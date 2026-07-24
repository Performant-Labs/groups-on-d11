# Handoff-T-red: Phase 4 - #124 SC-5 Directory compact-list vs cards toggle

**Date:** 2026-07-23
**Branch:** 124-directory-toggle
**Brief / wireframe reviewed:** `docs/handoffs/0124-directory-toggle/brief.md`,
`docs/handoffs/0124-directory-toggle/wireframe.md`,
`docs/handoffs/0124-directory-toggle/handoff-A-plan.md` (PASS + 7 advisories),
`docs/handoffs/0124-directory-toggle/decisions.md`, `survey.md`.

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3) — no architectural blockers.
Advisories #1 (explicit `#cache['contexts']` on the view's own render array), #4
(route id `view.all_groups.page_1`), and #7 (three-option literal) are reflected
in the tests below.

## Tests authored

### Kernel — `docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php`

Layer choice: kernel view-execution against a real `ViewExecutable` (mirrors
`DirectoryFiltersTest`'s `Views::getView()` + fixture pattern) — the cheapest
tier that can actually invoke `hook_views_pre_render`, a Views-runtime hook a
pure Unit test cannot exercise and a Functional/E2E test would exercise
redundantly for what is a server-side render-array/cache-metadata contract.

| Test | Criterion / behavior pinned |
|---|---|
| `testSwitcherInjectedWithThreeOptionsInOrder` | `#header['switcher']` is a `VariantSwitcher::build()` array with exactly `[compact, cards, map]` in order, map `available: false` (brief "three options, not two"). |
| `testViewDeclaresUrlQueryArgsVariantCacheContextDirectly` | The view's OWN `#cache['contexts']` carries `url.query_args:variant` (A-advisory #1 — not relying solely on the switcher child metadata). |
| `testNoQueryParamDefaultsWrapperToCards` | No `?variant=` -> wrapper resolves to `cards` (page default). |
| `testCompactQueryParamSetsWrapperToCompact` | `?variant=compact` -> wrapper resolves to `compact`. |
| `testUnavailableMapQueryParamFallsBackToCompact` | `?variant=map` (unavailable) -> falls back to `compact` (first available), never blank. |
| `testUnknownQueryParamFallsBackToCompact` | `?variant=bogus` (unknown id) -> also falls back to `compact`. |
| `testSwitcherAndDirectoryCompactLibrariesAttached` | Both `do_showcase/switcher` and `do_showcase/directory-compact` libraries attached. |
| `testHookDoesNotFireForADifferentViewId` | The hook is scoped ONLY to `all_groups.page_1` — a second view (`activity_stream`, minimal synthetic fixture) gets neither a switcher nor the cache context. |

### Unit — `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogDirectoryLiveTest.php`

No existing catalog test covers this specific entry's flip (checked
`ShowcaseCatalogTest.php` first — it pins the catalog's *general* shape rules,
not this one entry's values), so this is a new, narrow companion file.

| Test | Criterion pinned |
|---|---|
| `testDirectoryPresentationEntryIsLive` | `directory-presentation` entry status flips `coming` -> `live`. |
| `testDirectoryPresentationEntryRoutesToAllGroupsPage` | Its `route` is `view.all_groups.page_1` (A-advisory #4's confirmed route id). |

### Unit — `docs/groups/modules/do_showcase/tests/src/Unit/DoShowcaseHooksViewsPreRenderRegistrationTest.php`

No existing test in this codebase reflects on `#[Hook(...)]` attributes (grepped
`do_streams`/`do_group_pin` for a precedent — none exists), so this is a new,
narrow pattern for this one hook, per the brief's explicit request for a
defensive "the new hook shouldn't break the existing theme registration" check.

| Test | Criterion pinned |
|---|---|
| `testViewsPreRenderMethodExistsAndCarriesHookAttribute` | `DoShowcaseHooks::viewsPreRender()` exists and carries `#[Hook('views_pre_render')]`. |
| `testExistingThemeHookRegistrationIsUndisturbed` | The pre-existing `theme()` hook registration is untouched (defensive regression pin — passes today by construction). |

### E2E — `tests/e2e/directory-toggle.spec.ts` (new, 12 tests)

Not executed for real at RED (needs the running feature); validated via
`npx playwright test --list` (syntax + test enumeration only). Covers:
switcher renders with 3 options/Cards default/wrapper=cards; live toggle to
compact (no navigation, row-shape assertions, description hidden, reverts to
cards byte-identically); filters preserved both ways; pager preserved
(conditional skip if seed has no page 2); session persistence across reload +
fresh-context default; `?variant=compact` beats stale sessionStorage;
`?variant=map` graceful fallback; cross-page persistence from `/showcase`;
keyboard-operable + non-color visibility badge; 3 non-regression checks
mirroring `directory-cards.spec.ts` / `directory-filters.spec.ts` /
`showcase.spec.ts`'s existing assertions.

## RED confirmation

Assembled first: `bash scripts/ci/assemble-config.sh` (run via `ddev exec` since
the Bash tool's shell has no `php` on PATH — the container does). DDEV project
`gm124-directory` started fresh (was stopped); `ddev composer install` run once
to populate `vendor/` (assemble-config requires it to patch `core.extension.yml`).

**Kernel** — `ddev exec "SIMPLETEST_DB=mysql://db:db@db:3306/db php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php"`:

```
✘ Switcher injected with three options in order
  Failed asserting that an array has the key '#header'.
✘ View declares url query args variant cache context directly
  Failed asserting that an array contains 'url.query_args:variant'.
✘ No query param defaults wrapper to cards
  Failed asserting that null is identical to 'cards'.
✘ Compact query param sets wrapper to compact
  Failed asserting that null is identical to 'compact'.
✘ Unavailable map query param falls back to compact
  Failed asserting that null is identical to 'compact'.
✘ Unknown query param falls back to compact
  Failed asserting that null is identical to 'compact'.
✘ Switcher and directory compact libraries attached
  Failed asserting that an array contains 'do_showcase/switcher'.
✔ Hook does not fire for a different view id   (correctly passes — negative/defensive assertion, vacuously true before the feature exists; not a positive-behavior case)
Tests: 8, Assertions: 194, Failures: 7.
```

Every failing case fails on the RIGHT reason: a missing render-array key or a
`null` value where a resolved variant string is expected — never a setup
error, missing import, or PHP fatal. (First run without `SIMPLETEST_DB` set
correctly surfaced a setup-only error for all 8 cases; corrected by exporting
`SIMPLETEST_DB=mysql://db:db@db:3306/db` matching DDEV's `settings.ddev.php`
DB credentials — this is an environment/invocation detail, not a test defect.)
One harness-level defect was found and fixed during RED authoring: pushing a
`Request` onto `request_stack` without an attached `Session` made
`KernelTestBase::tearDown()`'s own session cleanup throw
`SessionNotFoundException` for the LAST test in the class run order (the error
was intermittent-looking because it only manifested on tearDown, after the
test's own assertions had already recorded pass/fail) — fixed by adding a
`pushRequestWithSession()` helper that attaches a `MockArraySessionStorage`
before pushing, mirroring what a real Drupal request cycle already does. This
is a test-harness fix, not a change to what's being asserted.

**Unit (ShowcaseCatalog)** — `ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/ShowcaseCatalogDirectoryLiveTest.php"`:

```
✘ Directory presentation entry is live
  Failed asserting that two strings are identical.
  - 'live'
  + 'coming'
✘ Directory presentation entry routes to all groups page
  Failed asserting that null is identical to 'view.all_groups.page_1'.
```

Both fail on the exact current (pre-F) values (`'coming'` / `NULL`) — the
right reason.

**Unit (hook registration)** — `ddev exec "php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_showcase/tests/src/Unit/DoShowcaseHooksViewsPreRenderRegistrationTest.php"`:

```
✘ Views pre render method exists and carries hook attribute
  Failed asserting that false is true.
✔ Existing theme hook registration is undisturbed
Tests: 4, Assertions: 8, Failures: 3, PHPUnit Deprecations: 4.
```

(The 4-count/3-failure summary above is from the combined run with the catalog
test file; run individually the hook-registration file is 2 tests, 1 fail / 1
pass.) `viewsPreRender()` genuinely does not exist yet — `hasMethod()` returns
`false` — the right reason; the defensive `theme()` pin correctly passes
unchanged.

**E2E** — `npx playwright test --list tests/e2e/directory-toggle.spec.ts`
(local `@playwright/test` was not yet installed in this worktree; `npm install`
run once first):

```
Total: 12 tests in 1 file
```

All 12 cases enumerate with no syntax/import errors.

## Fixture additions

- `docs/groups/modules/do_showcase/tests/fixtures/config/views.view.all_groups.yml`
  — module-local fixture (per PROJECT_CONTEXT.md: never a source-relative
  `__DIR__/../../../../../config` path). A trimmed copy of the real
  `docs/groups/config/views.view.all_groups.yml` (label/pager/exposed-form/
  sorts/status-filter/empty-region/style/row/page_1 — trimmed of the
  location/language exposed filters and the description/created fields, which
  are irrelevant to this story's assertions and would otherwise require
  installing two extra field storages just to load the view).
- `docs/groups/modules/do_showcase/tests/fixtures/config/views.view.activity_stream.yml`
  — a deliberately MINIMAL synthetic view (base table `node_field_data`, a
  `fields` row, one field, one filter), NOT a copy of the real
  `docs/groups/config/views.view.activity_stream.yml` (which uses the heavier
  `entity:node` row plugin + `stream_card` view mode + comment-module
  dependencies). It exists solely to prove
  `DoShowcaseHooks::viewsPreRender()` does not fire for a different view id —
  using the real, heavier view would pull in unrelated setup cost for a
  negative assertion that doesn't need it.

## Anything F needs to know that isn't already in the brief/wireframe

1. **Kernel test invocation needs `SIMPLETEST_DB`.** Running
   `phpunit -c web/core/phpunit.xml.dist` under `ddev exec` requires
   `SIMPLETEST_DB=mysql://db:db@db:3306/db` (DDEV's own DB creds, from
   `web/sites/default/settings.ddev.php`) exported in the same command, or
   every kernel test errors with "no database connection" instead of running.
2. **`hook_views_pre_render` fires from the render pipeline, not `execute()`.**
   `$view->execute()` alone never invokes `hook_views_pre_render` — the tests
   call `$view->preview()` (via the `renderView()` helper) to force the full
   render pipeline so the hook's side effects (`#header`, `#cache`, wrapper
   attributes) actually appear on `$view->element`. If F's implementation
   relies on `execute()` finishing for state it needs, note this ordering.
3. **Wrapper attribute lookup is deliberately flexible in the kernel test.**
   `wrapperVariantAttribute()` checks three plausible array paths
   (`#attributes`, `#content_attributes`, `#view_content_attributes`) for
   `data-do-directory-variant` — the wireframe fixes the ATTRIBUTE NAME and
   the ELEMENT it lives on (the view's `.view-content` wrapper) but not the
   exact `$view->element` array key F's implementation surfaces it under.
   Whichever key F actually uses, this test will find it as long as the value
   is set directly on one of those three keys at the top level of
   `$view->element`. If F's implementation nests it somewhere else entirely
   (e.g. only inside a preprocess variable, not on `$view->element` at all),
   this test will need a path added — flag it back to T rather than
   reshaping F's own implementation around the test's current lookup list.
4. **`viewsPreRender()` must read `?variant=` off the CURRENT request**, not a
   parameter — the kernel tests push a `Request` (with query string and a
   mock session) onto `request_stack` before building/rendering the view,
   matching how a real request would already have one in place.
5. Per A-advisory #7 (optional, non-blocking): the three-option literal
   (`compact`/`cards`/`map(unavailable)`) appears both in
   `ShowcaseController::page()` and will appear again in this story's new
   hook. The kernel test asserts the OUTCOME (three options, in order, map
   unavailable) without caring whether F hoists this to a shared
   const/static — either implementation choice satisfies the test.

## Ready for F

Confirmed RED is valid — every new positive-behavior test fails for the right
reason (a missing render-array key, a `null`/wrong value, or a missing method/
attribute), never a setup error or unrelated exception. F may implement
against these tests.
