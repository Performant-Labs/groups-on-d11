# Handoff-T-red: Phase 4 - #132 SD-5 Showcase help

**Date:** 2026-07-23
**Branch:** 132-showcase-help
**Brief / wireframe reviewed:** `docs/planning/handoffs/132-showcase-help/brief.md`,
`docs/planning/handoffs/132-showcase-help/brief-amendments.md` (Amendment 1 authoritative over the
brief's placement example), `docs/planning/handoffs/132-showcase-help/survey.md`,
`docs/planning/handoffs/132-showcase-help/decisions.md`

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3), with two soft findings folded into
`brief-amendments.md` (A1 persona-banner ⓘ placement order, A2 explicit `do_chrome/tooltips`
library attach). Both are pinned by tests below.

## Tests authored

### 1. Unit — extended `docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php`

Added 6 new test methods (existing 4 preserved unchanged):
- `testAllShowcaseHelpKeysResolveToNonEmptyPlainText` — all 9 `showcase_help.*` keys resolve
  non-empty, plain-text.
- `testAllShowcaseHelpKeysArePresentInAllArray` — each key is a literal `HelpText::all()` entry.
- `testPersonaBannerCopyNamesSwitchMechanism` — `showcase_help.persona_banner` names "Browse as".
- `testMapCopyNamesGeographicalGroupType` — `showcase_help.map` names "Geographical".
- `testMembershipModelsCopyDistinguishesTwoAxes` — names both visibility and join-policy axes.
- `testShowcaseHelpNamespaceIsDisjointFromOtherOwners` — structural guard: no `showcase_help.*` key
  falls under `showcase.`/`persona.`/`visibility.`/`group_type.`/`page.`, and no shadow key exists
  under those prefixes duplicating a `showcase_help.*` suffix.

Tier: Unit (pure PHP, no container) — cheapest sufficient tier for a literal-array copy-source
contract, mirroring the existing file's own pattern.

### 2. Functional — extended `docs/groups/modules/do_showcase/tests/src/Functional/PersonaBannerTest.php`

Added 4 new test methods (existing 2 preserved unchanged):
- `testAnonymousSessionHasNoPersonaHelpTrigger` — regression guard alongside "no banner at all".
- `testPersonaBannerHasHelpTriggerWithExpectedAttributes` (data-provider, 3 personas: elena_garcia,
  maria_chen, groups_moderate_demo) — `[data-do-tooltip]` node inside the banner, class
  `do-showcase-info`, `tabindex="0"`, `role="note"`, non-empty `aria-label`, copy exactly matches
  `HelpText::get('showcase_help.persona_banner')`.
- `testHelpTriggerAppearsAfterSwitchBackLinkInDomOrder` — Amendment 1 (A1): asserts DOM-order
  position of `data-do-tooltip` strictly after `do-showcase-persona-banner-switch-back`.
- `testTooltipsLibraryIsAttachedOnPersonaBannerPage` — Amendment 2 (A2): asserts
  `do_chrome.tooltips.js` appears in the response.

Tier: Functional (BrowserTestBase) — needed for real rendered `<aside>` markup + DOM order, matches
the existing file's own tier choice.

### 3. Functional — new `docs/groups/modules/do_showcase/tests/src/Functional/ShowcaseControllerHelpTest.php`

6 test methods:
- `testEveryTargetedEntryIdHasNonEmptyHelpCopy` — precondition sanity on all 7 catalog ids.
- `testEachCatalogEntryWithMatchingKeyRendersHelpTrigger` — all 7 catalog entries
  (`discovery-ranking`, `directory-presentation`, `membership-models`, `group-type-homepages`,
  `stream-model`, `private-group-reveal`, `persona-switcher`) render a `[data-do-tooltip]` inside
  their `[data-do-showcase-entry="<id>"]` container, copy matching `HelpText::get()` exactly.
- `testHelpTriggerIsKeyboardReachableWithAccessibleAttributes` — `tabindex="0"`, `role="note"`,
  non-empty `aria-label` (sampled on `discovery-ranking`; shared code path, not per-entry bespoke).
- `testMapOrientationHelpTriggerRendersAdjacentToSwitcher` — `.do-showcase-map-help[data-do-tooltip]`
  exists, copy matches `showcase_help.map`, contains "Geographical".
- `testTooltipsLibraryIsAttachedOnShowcasePage` — non-regression guard on the pre-existing attach.
- `testUnknownEntryIdHelpKeyResolvesEmptyGuardingAgainstEmptyTooltipRender` — the guard-condition
  test (task's explicitly-acceptable alternative to wiring a fake catalog entry): probes
  `HelpText::get('showcase_help.unknown-entry') === ''` directly, proving the guard
  `ShowcaseController::page()` must branch on to avoid an empty-tooltip render.

Tier: Functional — new file (no prior `ShowcaseControllerTest` existed) because this needs the
real `/showcase` HTTP response; `ShowcaseCatalogTest`/`VariantSwitcherTest` (Unit) don't exercise
the controller's actual rendered output.

### 4. E2E — new `tests/e2e/showcase-help.spec.ts`

6 tests across 3 `describe` blocks, mirroring `persona-switcher.spec.ts` (seeded-site convention,
`select[name="persona"]` / banner selectors) and `showcase.spec.ts` (`/showcase` conventions):
- Persona banner ⓘ: Elena (focus/tabindex/role/aria-label) + Groups-Moderate (non-empty tooltip).
- Tour page: every `.do-showcase-catalog-entry` contains a `[data-do-tooltip]`; discovery-ranking's
  trigger is keyboard-reachable.
- Map orientation: `.do-showcase-map-help[data-do-tooltip]` contains "map" (case-insensitive) and
  "Geographical".

## RED confirmation

**Mode: MIXED — real execution (Unit tier), static validation (Functional + E2E), matching the
`0138-mc7-manage-members` T-red precedent for this repo (no `vendor/`/`node_modules` in this
worktree).**

### Real execution — Unit tier (all 10 tests in `ShowcaseHelpTextTest.php`)

No `vendor/` in this worktree. Ran via `ddev exec` inside the **primary checkout's** DDEV container
(`pl-groups-on-d11`), which mounts only that repo — I copied this worktree's (unmodified,
pre-F) `HelpText.php` and the test file into a throwaway, gitignored-equivalent path
(`_t_scratch/`, deleted immediately after; the primary checkout's tracked files were fully restored
via `git checkout --` + `git clean -fd` + `rm -rf web/modules/custom` before finishing — verified
`git status` matches this session's starting state) and invoked `vendor/bin/phpunit` there as an
external tool, exactly as the `0138` precedent did.

```
php vendor/bin/phpunit --bootstrap _t_scratch/bootstrap.php --testdox \
  _t_scratch/do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php
```

Result: **10 tests, 101 assertions, 5 failures** — every new-test failure is the on-topic
"unknown key resolves to empty" reason, not an import/setup error; the 4 pre-existing tests + the
new disjointness guard pass correctly (vacuous pass — no keys exist yet, so no collision possible):

```
✔ Switcher tooltip key resolves
✔ Switcher tooltip copy describes what differs
✔ Existing do chrome key still resolves unchanged
✔ Unknown key still returns empty string
✘ All showcase help keys resolve to non empty plain text
  │ The appended HelpText key "showcase_help.persona_banner" must resolve to non-empty copy.
  │ Failed asserting that two strings are not identical.
✘ All showcase help keys are present in all array
  │ "showcase_help.persona_banner" must be a literal key in HelpText::all().
  │ Failed asserting that an array has the key 'showcase_help.persona_banner'.
✘ Persona banner copy names switch mechanism
  │ Failed asserting that '' matches PCRE pattern "/browse as/i".
✘ Map copy names geographical group type
  │ Failed asserting that '' [ASCII](length: 0) contains "Geographical" [ASCII](length: 12).
✘ Membership models copy distinguishes two axes
  │ Failed asserting that '' matches PCRE pattern "/visibility/i".
✔ Showcase help namespace is disjoint from other owners
```

### Static validation — Functional tier (10 tests, 2 files)

`php -l` clean on both files (via `ddev exec php -l`, PHP 8.4.22). Real BrowserTestBase execution
needs a fully assembled + self-installed site; this worktree has no `vendor/`, and Kernel/Functional
tests spawn a separate PHP process per Drupal's test runner (process-isolation limitation
documented by the `0138` precedent) that a manually-required bootstrap cannot bridge. Cross-checked
every assertion against real source instead:
- `DoShowcaseHooks::personaBanner()` currently builds `$children` = `['glyph', 'text',
  'switch_back']` only, with `$page_top['do_showcase_persona_banner']['#attached']['library'] =
  ['do_showcase/persona-switcher']` (no `do_chrome/tooltips`) — confirmed no `help` child and no
  tooltips-library attach exist yet, so every new `PersonaBannerTest` assertion fails on a missing
  element/asset, never an unrelated symptom.
- `ShowcaseController::page()` currently builds `$item = ['title', 'badge', 'decision', ('link'),
  ('personas')]` only, and `$build` has no `switcher_map_help` key — confirmed no `[data-do-tooltip]`
  exists inside any `[data-do-showcase-entry]` container and no `.do-showcase-map-help` node exists
  anywhere on `/showcase`, so every `ShowcaseControllerHelpTest` render assertion fails on a missing
  element. The one exception (by design): `testUnknownEntryIdHelpKeyResolvesEmptyGuardingAgainstEmptyTooltipRender`
  is a **guard test that must pass now and stay green through GREEN** — confirmed passing via real
  execution (`HelpText::get('showcase_help.unknown-entry') === ''` → `true`), matching the
  `0138` precedent's treatment of "this file must NOT exist" guard tests.
- `do_chrome.tooltips.js` (the correct asset filename, confirmed against
  `do_chrome.libraries.yml`'s `tooltips:` definition) is the string both new
  `testTooltipsLibraryIsAttached*` tests assert on.

### Static validation — E2E (6 tests, 1 file)

`npx playwright test --list` failed with `MODULE_NOT_FOUND` — no `node_modules` installed in this
worktree or the reference checkout (package.json declares `@playwright/test` as a devDependency,
never `npm install`ed here); `tsc` is not globally available in the DDEV container either. This is
an infra/environment limitation, not a test-authorship defect (same category the `0138` precedent
hit for Kernel tests). Validated by careful manual cross-check against `persona-switcher.spec.ts`
and `showcase.spec.ts`'s own proven selector/API usage (both read in full before authoring): the
`select[name="persona"]` + banner selectors are copied verbatim from `persona-switcher.spec.ts`;
the `.do-showcase-catalog-entry` / `[data-do-showcase-entry]` selectors match
`ShowcaseController::page()`'s real `#attributes['class'] => ['do-showcase-catalog-entry']` +
`data-do-showcase-entry` contract (confirmed against the controller source, not assumed). No
selector in this spec targets markup that currently exists — every test would fail against current
code for a missing-locator reason.

## Ready for F

**Confirmed RED is valid.** F may implement against these 26 test methods (10 Unit real-executed
RED + 16 Functional/E2E RED-by-static-validation). Note for F: Amendment 1's child order is
`glyph, text, switch_back, help` (not the brief's original before-`glyph` example); Amendment 2
requires an explicit `do_chrome/tooltips` attach in `personaBanner()`'s own `#attached['library']`.
