# Handoff-T-red: Phase 4 - MC-4 Multilingual baseline + RTL

**Date:** 2026-07-22
**Branch:** 139-multilang-rtl
**Brief / wireframe reviewed:** `docs/planning/handoffs/139-multilang-rtl/brief.md` (v3),
`docs/planning/handoffs/139-multilang-rtl/survey.md`,
`docs/planning/handoffs/139-multilang-rtl/decisions.md`. No wireframe — brief marks
Phase 2 (D) N/A: no meaningful UI surface beyond an inline text indicator + a Views column.

## A precondition

Confirmed: A returned PASS (round 2) on the plan in `decisions.md` — "Advance to T(red) on
brief v3. No further A pre-code cycles required."

## Tests authored

### Kernel: `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`

Extends `GroupsKernelTestBase` (mirrors `GroupLanguageNegotiationTest`'s module list and
`#[RunTestsInSeparateProcesses]` convention), declares `field_group_language` as `type: language`
(production shape, per brief non-negotiable — NOT `type: string` like the negotiation test).

| Test | Criterion / behavior pinned | Tier | Why this tier |
|---|---|---|---|
| `testLanguageDirectionFixtureSanity` | Fixture guard: `ar` resolves `DIRECTION_RTL`, `fr` resolves `DIRECTION_LTR` via core `ConfigurableLanguage` | Kernel | Not a behavioral test of the module — a guard so a misconfigured fixture doesn't mask a real failure in the render tests below. Cheapest possible check (no render pipeline needed). |
| `testRendersRtlIndicatorForArPrimaryGroup` | Acceptance: "seeded RTL-primary group renders right-to-left correctly" + "WCAG 2.2 AA lang attributes correct" | Kernel | Exercises `hook_entity_view` render output directly via the view builder — no HTTP/browser needed to pin the markup contract (`class`, `lang`, `dir`). |
| `testRendersLtrIndicatorForFrPrimaryGroup` | Same acceptance, LTR control case (proves the hook is direction-aware, not hardcoded) | Kernel | Same reasoning; a hardcoded `dir="rtl"` implementation would pass the ar case and fail this one. |
| `testNoIndicatorWhenFieldEmpty` | Non-negotiable: "No output when the field is empty" | Kernel | Cheapest tier to verify a render-suppression contract. |
| `testNoIndicatorForUndefinedLangcode` | Non-negotiable: no output for `und`/`zxx`/empty-string sentinels | Kernel | Same — three sentinel values in one loop, no duplication of the empty-field case above (different code path: field has a value, but it's a sentinel). |
| `testNoIndicatorForUninstalledLangcode` | Non-negotiable (A advisory #4): null-language guard when `getLanguage($langcode)` returns NULL for a bogus/uninstalled langcode | Kernel | Pins the specific defensive branch A's Finding required; distinct from the sentinel case (a real-looking but uninstalled code, not a known sentinel). |

Each render test asserts on the raw HTML string from
`\Drupal::service('renderer')->renderRoot($build)` after `getViewBuilder('group')->view($group, 'full')`,
per the brief's exact verification recipe.

### E2E: `tests/e2e/group-language.spec.ts`

Three tests, anonymous only, mirroring `directory-cards.spec.ts` / `showcase.spec.ts` conventions
(env-driven `baseURL` via `playwright.config.ts`, no hardcoded gid — resolves the group path
dynamically from `/all-groups`, same "don't hardcode entity ids" pattern already used elsewhere
in this suite).

| Test | Criterion pinned | Tier |
|---|---|---|
| `RTL Arabic group renders dir="rtl" with language indicator` | Acceptance: seeded RTL group renders `html[dir="rtl"]` end-to-end (real negotiation + real hook on a live page) | E2E — this is the one criterion Kernel cannot reach: it requires the `language-group` negotiation plugin, the interface-language subsystem, and the assembled page template all wired together on a served response. |
| `LTR French group renders dir="ltr" with language indicator` | Same acceptance, LTR control | E2E |
| `directory /all-groups shows language column` | Acceptance: directory exposes the language column (for MC-3); Views `language` formatter emits the language NAME not the raw code | E2E — a Views-rendered field on a live page; Kernel has no cheaper way to reach the assembled `all_groups` view's rendered HTML. |

## RED confirmation

Environment note: this worktree needed its own isolated DDEV instance (`gm139-multilang-rtl`,
`.ddev/config.gm139.yaml`, `override_config: true`) because the shared `pl-groups-on-d11` DDEV
project is mutagen-synced from the main checkout only (`~/Projects/groups-on-d11`), not this
worktree — confirmed via `docker inspect` mount list. `ddev composer install` +
`bash scripts/ci/assemble-config.sh` (run via `ddev exec`, since no host PHP exists) were run
first; `npm install` was run for Playwright.

Kernel run (`SIMPLETEST_DB=mysql://db:db@db:3306/db`, DDEV's own DB creds):
```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox \
  web/modules/custom/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php
```
Result: `Tests: 6, Assertions: 135, Failures: 2, Deprecations: 2.`

```
Group Language Indicator (Drupal\Tests\do_group_language\Kernel\GroupLanguageIndicator)
 ✔ Language direction fixture sanity
 ✘ Renders rtl indicator for ar primary group
   │ Failed asserting that '<div class="group group--full group--community-group">
   │ ...(bare group markup, no language_indicator element)...
   │ </div>
   │ ' [ASCII](length: 238) contains "class="do-group-language"" [ASCII](length: 25).
 ✘ Renders ltr indicator for fr primary group
   │ (same failure shape, fr fixture)
 ⚠ No indicator when field empty
 ⚠ No indicator for undefined langcode
 ⚠ No indicator for uninstalled langcode
```

This is a valid RED: the fixture-sanity guard passes (core's own `ar`/`fr` direction defaults are
correct — not what we're testing), the three "no indicator" tests pass **trivially and correctly**
(the hook doesn't exist yet, so of course nothing is emitted — same behavior the finished feature
must also exhibit), and the two positive-assertion tests fail on the exact missing-markup
assertion (`do-group-language` class absent from the rendered `<div>`), not on a missing
class/import/setup error. No fatal errors, no skipped tests.

Playwright (`--list`, no live run per instructions — F has not seeded the Arabic group or built
the hook/Views field yet):
```
npx playwright test tests/e2e/group-language.spec.ts --list
```
```
Listing tests:
  [chromium] › group-language.spec.ts:46:7 › MC-4 — Multilingual baseline + RTL (#139) › RTL Arabic group renders dir="rtl" with language indicator
  [chromium] › group-language.spec.ts:58:7 › MC-4 — Multilingual baseline + RTL (#139) › LTR French group renders dir="ltr" with language indicator
  [chromium] › group-language.spec.ts:70:7 › MC-4 — Multilingual baseline + RTL (#139) › directory /all-groups shows language column
Total: 3 tests in 1 file
```
Syntactically valid, all three names list correctly. RED status: will fail at first F+seed run —
no live E2E run performed at T-red (per task instructions; the Arabic group, the render hook, and
the Views field don't exist yet, so a live run would 404/timeout on `resolveGroupPath()` rather
than fail on a meaningful assertion — deferring the live run to T-green is the correct call here).

**Existing suite regression check:** full kernel suite
(`find web/modules/custom -type d -path '*/tests/src/Kernel'`) —
`Tests: 106, Assertions: 2913, Failures: 2, Deprecations: 28, PHPUnit Deprecations: 85.`
The only 2 failures in the entire 106-test suite are our own two new RED tests above; every
previously-green kernel test (including `GroupLanguageNegotiationTest` and all `do_tests` kernel
coverage) remains green. Deprecation counts are pre-existing core/contrib noise (Twig sandbox
policy, `@Block` annotation, `EntityBase::original`), unrelated to this story.

**Lint:** `php vendor/bin/phpcs --standard=Drupal,DrupalPractice
docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php` — 0
violations (clean). Note: the brief's literal `php vendor/bin/phpcs
docs/groups/modules/do_group_language` command (no `--standard`) falls back to phpcs's PEAR
default and flags ~90 indentation/brace violations on **both** the new file and the pre-existing
`GroupLanguageNegotiationTest.php` identically — this is a phpcs invocation-default issue, not a
real violation; the project's actual coding standard is Drupal/DrupalPractice (confirmed
installed via `php vendor/bin/phpcs -i`), and against that standard the new file is clean. Under
Drupal,DrupalPractice the sibling `GroupLanguageNegotiationTest.php` has one pre-existing,
out-of-scope violation (line 42, doc-comment capitalization) — not introduced by this change, not
fixed here (not my file to touch).

## Ready for F

Confirmed RED is valid; F may implement `do_group_language.module` (`hook_entity_view`),
`do_group_language.libraries.yml`, `css/group-language.css`, the `all_groups` Views-field
addition, and the `step_760.php` Arabic-group seed against these tests.
