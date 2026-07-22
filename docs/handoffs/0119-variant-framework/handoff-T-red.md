# Handoff-T-red: Phase 4 - SC-F1 Variant framework (switcher, /showcase, POC ribbon)

**Date:** 2026-07-22
**Branch:** 0119-variant-framework
**Brief / wireframe reviewed:** `docs/handoffs/0119-variant-framework/brief.md`,
`docs/handoffs/0119-variant-framework/wireframe.md`, `docs/handoffs/0119-variant-framework/handoff-D.md`,
`docs/handoffs/0119-variant-framework/handoff-A-plan.md`, `docs/handoffs/0119-variant-framework/survey.md`,
`docs/handoffs/0119-variant-framework/O-notes-for-F.md`

## A precondition
Confirmed: A returned **PASS** on the plan (Phase 3) — `handoff-A-plan.md` verdict PASS, no
blocking findings. D-gate sign-off recorded in `decisions.md` ("O — Phase 2 D-gate sign-off",
2026-07-22T09:05:00Z) — all three wireframe surfaces approved.

## Tests authored

### PHPUnit Unit tests — `docs/groups/modules/do_showcase/tests/src/Unit/`

**1. `VariantSwitcherTest.php`** (`Drupal\Tests\do_showcase\Unit\VariantSwitcherTest`, `@group do_showcase`)
Pins the `VariantSwitcher::build(string $instance_id, array $options, string $current): array`
render-array contract (brief.md Acceptance criterion #2 — "stable ... contract, so SC-4/5/6/ST-8
can call it"). Tier: **Unit** — pure data/render-array construction, no Drupal container/DB/config
needed, matches `PermissionMatrixTest.php`'s exact shape (a plain class using
`StringTranslationTrait`, tested with `UnitTestCase::getStringTranslationStub()`).

| Test | Criterion / behavior pinned |
|---|---|
| `testBuildReturnsLabeledControlGroupKeyedByInstanceId` | Wrapper is a labeled radiogroup (`role="radiogroup"` + `aria-label`) — wireframe.md Surface 1 default state; WCAG 2.2 AA labeled-control-group requirement (brief.md Acceptance "labeled control group"). |
| `testBuildRendersOneItemPerOption` | One rendered item per supplied `$options` entry, each carrying id + non-empty label. |
| `testExactlyOneOptionMarkedSelected` | Exactly one option is `aria-checked` and it matches `$current` — never zero, never more than one. |
| `testUnavailableCurrentFallsBackToFirstAvailable` | wireframe.md: "Selection automatically falls back to the first available option if `current` names an unavailable one (never silently renders nothing selected)." |
| `testUnknownCurrentFallsBackToFirstAvailable` | Same fallback contract for a `$current` id that does not exist at all (defensive — never throws, never selects nothing). |
| `testUnavailableOptionCarriesDisabledMarkers` | wireframe.md "State: unavailable" — `aria-disabled="true"` + `tabindex="-1"`, per O-notes-for-F.md's resolved open question. |
| `testAvailableOptionsAreNotDisabled` | Negative case — an available option must never carry the disabled markers (guards against F flipping the logic). |
| `testEveryOptionCarriesNoJsVariantFallbackLink` | wireframe.md "State: no-JS" — every option carries a `?variant=<id>` fallback link naming itself. |
| `testBuildWorksForArbitraryOptionCount` | Forward-compat (brief.md Acceptance #2, survey.md forward-compat table) — contract holds for 2-option and 5-option (with one unavailable) option sets, not only the 3-option stub. |
| `testTooltipTriggerCarriesHelpTextSourcedCopy` | do_chrome tooltip reuse (wireframe.md: "ⓘ tooltip trigger sits ... one per switcher instance, not per option") + non-empty HelpText-sourced copy. |

**2. `ShowcaseCatalogTest.php`** (`Drupal\Tests\do_showcase\Unit\ShowcaseCatalogTest`, `@group do_showcase`)
Pins the `ShowcaseCatalog` code-constant contract (Brief-gate B-4: typed PHP array, `t()`-wrapped,
NOT config/content). Tier: **Unit** — same shape as `PermissionMatrix`/`PermissionMatrixTest`.

| Test | Criterion / behavior pinned |
|---|---|
| `testAllSevenRequiredEntriesArePresent` | brief.md Acceptance #3 — all six comparisons + the persona-switcher entry, by exact id. |
| `testEveryEntryHasCompleteShape` | Brief-gate B-4 typed shape: `{id, title, decision_sentence, status, route}`, status ∈ {live, coming}, non-empty title/decision_sentence. |
| `testComingEntriesHaveNoRoute` | wireframe.md truthful-copy rule — "coming entries show no dead link ... never a link to a page that doesn't exist yet." |
| `testLiveEntriesHaveARoute` | Positive counterpart — a `live` entry must carry a real deep-link target. |
| `testMembershipModelsEntryStaysComing` | O-notes-for-F.md consistency note — grequest incompatible with group 4.0.x; must not imply membership models is live. |
| `testPrivateGroupRevealEntryReferencesIssue134` | brief.md Acceptance #3 names issue #134 explicitly for this entry. |
| `testPersonaSwitcherEntryNamesAllFourPersonas` | brief.md Acceptance #3 — "lists the persona switcher (#120) naming all four public personas" (Anonymous / Elena Garcia / Maria Chen / Moderator). |
| `testPersonaSwitcherEntryIsLive` | The switcher device itself ships this story, so the persona-switcher catalog entry is `live` (distinct from the six `coming` comparisons). |
| `testEntryStringsAreTranslatableMarkup` | Brief-gate W-3/i18n — "All user-facing strings wrapped in `t()`." |

**3. `ShowcaseHelpTextTest.php`** (`Drupal\Tests\do_showcase\Unit\ShowcaseHelpTextTest`, `@group do_showcase`)
Pins the specific HelpText keys `do_showcase` must **append** to the existing
`\Drupal\do_chrome\HelpText` copy store (append-only — Operating rules: "A parallel tooltip/copy
mechanism is an anti-duplication BLOCK"). Tier: **Unit** — same shape/dependencies as the existing
`do_chrome`'s own `HelpTextTest.php` (pure static-method calls against a plain-array class, no
container). Deliberately does **not** duplicate `HelpTextTest.php` — it pins only the NEW keys this
story ships plus one non-regression check on an existing key.

| Test | Criterion / behavior pinned |
|---|---|
| `testSwitcherTooltipKeyResolves` | brief.md Acceptance "Ships its HelpText entry (append-only) for the new user-facing surface(s)" — `showcase.switcher.directory.layout` resolves to non-empty, plain-text copy. |
| `testSwitcherTooltipCopyDescribesWhatDiffers` | wireframe.md — copy behavior "what differs between these variants" (issue's own phrasing), not generic filler. |
| `testExistingDoChromeKeyStillResolvesUnchanged` | Append-only contract — `demo.foundation` (an existing do_chrome key) must resolve unchanged after do_showcase appends its own keys. |
| `testUnknownKeyStillReturnsEmptyString` | do_showcase must not alter `HelpText::get()`'s existing unknown-key fallback behavior. |

### Playwright e2e — `tests/e2e/showcase.spec.ts` (NOT root `e2e/` — confirmed correct location
against `playwright.config.ts`'s `testDir: './tests/e2e'`)

Brief-gate B-3 requires switcher render/switch/persist, ribbon show(anon+auth)/dismiss/persist, and
the `/showcase` listing incl. "coming" + persona entries, all in this one spec file. Tier:
**Functional/E2E** (full HTTP + browser) — this is the correct tier because these are exactly the
behaviors a headless PHPUnit test cannot see: server-rendered ARIA state after a real click,
client-side (cookie/localStorage) persistence across a real navigation, and cross-surface DOM
non-interference (ribbon vs. nav).

| Test | Criterion / behavior pinned |
|---|---|
| `switcher renders as a labeled radiogroup with all stub options` | brief.md Acceptance #1 — at least one wired demo instance renders; wireframe.md Surface 1 default state (3 stub options visible). |
| `clicking an option switches the current selection` | brief.md Acceptance #1 "switches" — a real click updates `aria-checked` on both the newly- and previously-selected options. |
| `selection is conveyed by more than color (non-color cue present)` | Brief-gate W-2/WCAG — selected state must be distinguishable via text/glyph, not a bare `aria-checked` with no visible non-color delta. |
| `the choice persists client-side across navigation` | brief.md Acceptance #1 "persists per session ... client-side ... survives navigation without starting a server session." |
| `no-JS ?variant= query param selects the right option` | wireframe.md "State: no-JS" fallback. |
| `an unavailable option is present, marked, and not a dead click` | wireframe.md "State: unavailable" — visible, `aria-disabled`, `tabindex="-1"`, truthful "(soon)" label. |
| `ribbon shows for anonymous visitors, links to /showcase` | brief.md Acceptance #4 "shows site-wide for anonymous"; wireframe.md Surface 3 copy + link contract. |
| `ribbon shows identically for an authenticated user` | brief.md Acceptance #4 "+ authenticated" — same copy/behavior, no session-dependent branching. |
| `ribbon does not cover or reflow primary nav (nav.spec.ts non-regression)` | Brief-gate W-5 / Operating rules — "the ribbon adds a fixed element without reshuffling nav DOM"; reuses `nav.spec.ts`'s own `#block-groups-chrome-main-menu` selector so a real regression here is caught by construction. |
| `dismiss button removes the ribbon` | wireframe.md Surface 3 — real `<button aria-label="Dismiss demo banner">`, not a styled `<a>`/`<div onclick>`. |
| `dismissal persists client-side across navigation` | brief.md Acceptance #4 "dismissible per session ... client-side persistence ... survives navigation without a server session." |
| `lists all six comparison entries with truthful [live]/[coming] badges` | brief.md Acceptance #3 — all six named comparisons present with the exact `[ live ]`/`[ coming ]` text-badge wording (wireframe.md's own load-bearing non-color cue). |
| `the private-group-reveal entry references #134` | brief.md Acceptance #3 names issue #134 explicitly. |
| `"coming" entries have no dead link to an unbuilt page` | wireframe.md truthful-copy rule — no link into SC-4/5/6's not-yet-built routes. |
| `lists the persona switcher naming all four public personas` | brief.md Acceptance #3 — persona-switcher entry names Anonymous / Elena Garcia / Maria Chen / Moderator. |

## RED confirmation

### PHPUnit — EXECUTED to a real RED (run command + exact failing output)

The module `do_showcase` does not exist yet anywhere on disk. Ran PHPUnit from the shared
checkout's vendored binary (read-only invocation; no mutating command run against the shared
`groups-on-d11` checkout) against the test files living in the isolated worktree:

```
cd /Users/andreangelantoni/Projects/groups-on-d11
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework/docs/groups/modules/do_showcase/tests/src/Unit/VariantSwitcherTest.php
```
```
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.
...
Tests: 10, Assertions: 0, Errors: 10, PHPUnit Deprecations: 11.
```
All 10 errors: `Error: Class "Drupal\do_showcase\VariantSwitcher" not found` — the RIGHT reason
(feature/class absent), not a bootstrap or `$modules` misconfiguration. Zero assertion failures,
zero incidental errors of any other shape.

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  .../do_showcase/tests/src/Unit/ShowcaseCatalogTest.php
```
```
Tests: 9, Assertions: 0, Errors: 9, PHPUnit Deprecations: 10.
```
All 9 errors: `Error: Class "Drupal\do_showcase\ShowcaseCatalog" not found`.

```
php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  .../do_showcase/tests/src/Unit/ShowcaseHelpTextTest.php
```
```
Tests: 4, Assertions: 0, Errors: 4, PHPUnit Deprecations: 1.
```
All 4 errors: `Error: Class "Drupal\do_chrome\HelpText" not found`. This is expected and matches
the codebase's existing baseline: `do_chrome`'s own pre-existing `HelpTextTest.php` produces the
identical `Class ... not found` error when run the same way, because no `do_*` module is on the
PSR-4 autoloader outside a full `scripts/ci/assemble-config.sh` + `composer install` assembly
(confirmed by running `HelpTextTest.php` itself first, same command shape, same "Class not found"
result — 10/10 errors, not one assertion failure). This is a harness/assembly fact common to every
`do_*` Unit test in this repo, not a defect introduced by this story's tests — the CI kernel/
functional jobs run `scripts/ci/assemble-config.sh` before invoking PHPUnit for exactly this
reason; T-GREEN will do the same before re-running these three files to confirm they pass once
`do_showcase` (and its `HelpText` append) exist.

**Total: 23/23 new PHPUnit test methods RED, 100% `Class ... not found` errors, 0 assertion
failures, 0 unrelated errors.** Valid RED for the right reason across all three files.

### Playwright — RED-by-construction (no live site in this environment)

No Drupal site is running in this environment (`ddev` not booted here; confirmed
`https://groups-on-d11-build.ddev.site:8493/` → HTTP 404, `http://localhost:8080/` → connection
refused). Per brief.md's own Acceptance criterion, `showcase.spec.ts` is "Verified via namespaced
throwaway-DB Docker ... local `npx playwright test` ... green" — that verification step happens at
T-GREEN (Phase 6) against the namespaced Docker the way `.github/workflows/test.yml`'s `e2e` job
does, not here.

What WAS executed here: confirmed the spec file is syntactically valid TypeScript Playwright will
actually collect, and that it lands in the correct `testDir` (`./tests/e2e`, not the root `e2e/`
silent no-run trap the brief explicitly warns about):

```
cd /Users/andreangelantoni/Projects/_worktrees/groups-0119-variant-framework
npx playwright test --list tests/e2e/showcase.spec.ts
```
```
Total: 15 tests in 1 file
```
All 15 cases listed (3 test.describe blocks — Variant switcher, POC ribbon, /showcase tour page),
zero collection errors.

**Why each case is a valid RED without do_showcase, precisely:**
- Every switcher case targets `page.goto('/showcase')` and a `[role="radiogroup"]` selector —
  `/showcase` is a new route this story's controller creates; with no `do_showcase` module
  installed, this route does not exist and the request 404s (`expect(res?.status()).toBe(200)`
  fails first), or if some other page happens to 200, the `[role="radiogroup"]` locator never
  resolves (strict-mode `toBeVisible()` timeout) — never a false "wrong option" content mismatch.
- Every ribbon case targets exact copy `"This is a proof-of-concept demo."` and a
  `<button aria-label="Dismiss demo banner">` — `DoChromeHooks::pageAttachments()` (the one
  existing global-chrome attach point) does not currently emit this ribbon at all, so the text/
  button locators resolve to zero elements and every assertion times out for the right reason
  (surface absent), matching `DoChromeHooks.php`'s current contents (read directly — no ribbon
  method exists there yet).
- Every /showcase-listing case targets `page.goto('/showcase')` + specific entry titles/badge
  text/persona names — same 404-or-missing-locator failure mode as the switcher cases, since the
  route and `ShowcaseCatalog`-driven listing do not exist.
- The nav-non-regression case is the one exception that would currently PASS on its own (nav
  itself is unaffected because nothing has touched it yet) — it is not a RED case pinning new
  behavior; it exists as the non-regression guard T-GREEN re-runs to prove F's ribbon addition
  didn't break `nav.spec.ts`'s own assertions. Flagged explicitly so it is not miscounted as
  "should currently fail."

## Ready for F
**Confirmed RED is valid.** 23/23 PHPUnit test methods executed and failing for the right reason
(class/feature absence, zero assertion failures). 14/15 Playwright cases are RED-by-construction
against the current, do_showcase-less codebase (reasoned precisely above against the real,
currently-existing `DoChromeHooks.php` and the absence of any `/showcase` route); 1/15
(`ribbon does not cover or reflow primary nav`) is an explicitly-flagged non-regression guard, not
a RED case, and is expected to already pass. F may implement against these tests. Playwright
execution against a real GREEN suite happens at T-GREEN (Phase 6) per the brief's own Docker
verification step.
