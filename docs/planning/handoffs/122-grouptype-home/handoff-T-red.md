# Handoff-T-red: Phase 4 - #122 SC-3 Group-type homepages

**Date:** 2026-07-22
**Branch:** 122-grouptype-home
**Brief / wireframe reviewed:**
- `docs/planning/handoffs/122-grouptype-home/brief.md`
- `docs/planning/handoffs/122-grouptype-home/survey.md`
- `docs/planning/handoffs/122-grouptype-home/wireframe.md`
- `docs/planning/handoffs/122-grouptype-home/handoff-A.md`
- `docs/planning/handoffs/122-grouptype-home/decisions.md`

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3, `handoff-A.md`). Both of D's open
questions were resolved definitively (see-all target = `/group/{gid}/nodes?type=forum|documentation`;
ⓘ contrast confirmed AA with `.do-chrome-info` as-is, no override needed).

## Environment setup (load-bearing — read before re-running)

No `vendor/`, `php`, or `composer` were available on the host shell in this worktree. To get
CI-faithful RED evidence (not just reasoning about what *should* fail), I stood up a real
seeded site:

1. Renamed this worktree's `.ddev/config.yaml` project name from `pl-groups-on-d11` to
   `gm122-groups-on-d11` (namespaced per the story's own container-naming guardrail) so it
   doesn't collide with the primary checkout's already-running DDEV project of the same
   default name. **This rename is still in place** — F/T-green/U should reuse
   `gm122-groups-on-d11` (or re-rename back before merge; flagging for O).
2. `ddev start`, `ddev composer install`.
3. `ddev exec bash scripts/ci/assemble-config.sh` (assembles `docs/groups/config` →
   `config/sync`, `docs/groups/modules` → `web/modules/custom`, per the mandated CI-faithful
   path).
4. `drush site:install standard` + `drush config:set system.site uuid <src-uuid>` +
   `config_sync_directory` setting append + `drush config:import -y` (mirrors
   `.github/workflows/test.yml`'s own install steps).
5. `drush theme:enable groups_chrome -y` + set as default theme.
6. Seeded via the shared scripts, as uid 1, in the mandated order:
   `docs/groups/scripts/step_700_demo_data.php` then `step_720_group_types.php`.
7. `drush cache:rebuild`. Site served directly via DDEV's own webserver at
   `http://gm122-groups-on-d11.ddev.site` (no `drush runserver` needed — DDEV already serves).
8. `npm install` + `npx playwright install chromium`, then
   `BASE_URL="http://gm122-groups-on-d11.ddev.site" npx playwright test ...`.

Sanity-checked the seed: `directory-cards.spec.ts` (existing suite) passes 3/3 against this
site, and `curl .../all-groups` confirms all four exemplar group labels (DrupalCon Portland
2026, Core Committers, Thunder Distribution, Drupal France) render — so test failures below are
real (missing feature), not broken locators or a bad seed.

**Left running for T-green:** the `gm122-groups-on-d11` DDEV project stays up with this
install/config/seed state so F's implementation can be verified without re-doing all of the
above. No source files outside this story's ownership list were touched — only DDEV
config-name + the transient `config/sync` / `web/modules/custom` assembled artifacts (which are
themselves rebuilt by `assemble-config.sh` and never staged/committed by me).

## Tests authored

### E2E — `tests/e2e/group-type-homepage.spec.ts` (new, 10 tests across 5 describe blocks)

| # | Test | AC bullet pinned | Tier | Why this tier |
|---|---|---|---|---|
| 1 | Events-first: DrupalCon Portland 2026 leads with "Upcoming events", item links → `/node/{nid}`, "See all events" → `/group/{gid}/events` | "DrupalCon Portland 2026 → visibly leads with events" | E2E | Full-stack render (preprocess + twig + CSS attach); no cheaper tier exercises the actual rendered DOM contract. |
| 2 | Discussion-first: Core Committers leads with "Recent discussions", item links → `/node/{nid}`, "See all discussions" → `/group/{gid}/nodes?type=forum` | "Core Committers → visibly leads with forum topics" + A's Q1 resolution (type-filtered see-all path) | E2E | Same reasoning; also pins A's definitive Q1 answer (not the unfiltered-stream fallback). |
| 3 | Thunder Distribution: no `.gc-group-lead`, no `group-type-homepage` library reference, tabs unchanged | "Thunder Distribution → visibly leads with documentation" **— see RED-validity note below** | E2E | Same reasoning, but see caveat: this exemplar has **no seeded documentation-type nodes anywhere** (verified: `step_700_demo_data.php` seeds `forum`/`event` nodes only — no `"type" => "documentation"` create call exists in any seed script). Per the wireframe's own empty-state decision (§2), zero qualifying nodes means the section must NOT render at all — identical to the fallback contract. This test therefore currently PASSES vacuously (nothing renders because nothing exists yet, full stop) and will continue to pass once F ships correctly-gated empty-state logic; it does **not** independently prove docs-first rendering ever works. **Flagged for F/U:** the only way to get a real green-path assertion for Distribution-typed groups is to add a documentation-type node to the seed (out of this story's file-ownership list) or accept this gap and rely on manual/U verification if F wants to visually confirm the docs-first path once real content exists. I did not modify seed scripts (not owned by this story) — flagging rather than silently declaring full coverage. |
| 4 | Fallback: Drupal France (Geographical/unmapped) — no `.gc-group-lead`, no new library, no `data-do-tooltip` referencing the new key, tabs unchanged | "An unmapped-type or untyped group renders identically to today" | E2E | Wireframe §7's own DOM-diff assertion, used verbatim. |
| 5 | Tooltip: ⓘ trigger on DrupalCon Portland 2026's lead heading is `tabindex="0"`, `role="note"`, non-empty `aria-label` matching "adapts to the group's type", `data-do-tooltip` present with the same copy, and is reachable via `.focus()` | "section header carries a tooltip" + a11y AC (focusable trigger, accessible name) | E2E | Needs the fully rendered page + real DOM attributes; not unit-testable without a browser context. |
| 6 | axe-core dependency gap is explicitly pinned (not silently skipped) | WCAG 2.2 AA AC | E2E (stub) | See "AA / axe" section below — this repo has no `@axe-core/playwright` devDependency (confirmed: `package.json` only lists `@playwright/test`); `manage-members.spec.ts` already established this exact documented-gap pattern for this repo, reused verbatim rather than silently adding a new tooling dependency (out of T's remit). |
| 7-10 | Regression guard: all four exemplar/fallback groups still show `Stream / Events / Members / About` tabs in that exact order | "Existing E2E specs stay green" + fallback tab-order AC | E2E | Direct regression pin per wireframe §7; cheapest way to prove tab wiring is untouched across every state. |

### PHPUnit — `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (extended, +1 test)

| Test | AC bullet pinned | Tier | Why this tier |
|---|---|---|---|
| `testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants` | "section header carries the append-only HelpText tooltip (`group_type.homepage_adapts`)" | Unit | `HelpText::get()`/`all()` are pure static methods with no Drupal bootstrap dependency — Unit is the cheapest sufficient tier, matching every other test in this file. |

**HelpText auto-coverage claim — VERIFIED FALSE.** The task brief asked me to verify the claim
that `HelpTextTest` "auto-covers" the appended key. I read the full test file: `HelpText::all()`
is a fixed literal PHP array, and the existing tests each assert specific, named keys (e.g.
`testGroupTypeFieldCopyNamesAllTypes` asserts `group_type.field` specifically). The only
"all-keys" test, `testAllReturnsStringMap()`, merely asserts every key/value in `all()` is a
`string` type — it does **not** assert that any particular expected key exists, so a missing
`group_type.homepage_adapts` key would NOT fail that test. There is no loop that iterates
"every key referenced elsewhere" and checks presence. **Conclusion: NOT auto-covered.** I added
a targeted test following the established per-surface pattern (mirrors
`testGroupTypeFieldCopyNamesAllTypes`, `testPermissionMatrixPanelCopyIsPresent`, etc.) rather
than silently missing this coverage.

### Kernel — none authored (decision, documented)

No Kernel test targets `groups_chrome_preprocess_group()` or any other `groups_chrome.theme`
procedural function. Searched `web/themes/custom/groups_chrome/` for any existing test
directory — none exists (theme `.theme` files have no established Kernel-test harness in this
codebase; the only Kernel tests under `docs/groups/modules/*/tests/src/Kernel` target `do_*`
modules, none of which cover theme preprocessing). Building a fresh Kernel harness for a THEME
preprocess function (bootstrapping the theme layer, rendering a `group--full` template inside
a KernelTestBase) is non-trivial new test infrastructure that neither the brief nor A's
"Test-writability" section asked for — A's own review explicitly lists the *E2E* assertions as
the sufficient coverage plan (`handoff-A.md`, "Test-writability" section: "T can: Assert
`.gc-group-lead` presence... Assert `.gc-group-lead` count === 0 on an unmapped-typed
group... Assert tab bar text + order unchanged... Assert tooltip ⓘ presence... Run axe against
all four states" — all E2E-level assertions, no Kernel-level ask).

**Decision: rely on E2E for preprocess-level behavioral coverage,** per the brief's own hedge
("If no such kernel test exists... rely on E2E for coverage... document your choice"). This
keeps the suite proportionate — a bespoke Kernel harness would duplicate exactly what the E2E
suite already pins (leading_section mapping observable only through rendered DOM anyway, since
the mapping has no public-facing accessor other than the render).

## RED confirmation

### E2E suite (primary)

Command:
```
BASE_URL="http://gm122-groups-on-d11.ddev.site" npx playwright test tests/e2e/group-type-homepage.spec.ts
```

Result: **7 passed, 3 failed** — the 3 failures are exactly the ones that require the
unbuilt feature to exist; the 7 passes are (a) tests that assert *absence* of new markup
(correctly true both before and after this exemplar's own case, or vacuously true at RED for
Thunder Distribution per the note above), (b) the documented axe-gap stub, and (c) the 4
tab-order regression guards (nothing has touched tabs yet, so naturally still green — these
become meaningful once F's change lands and could regress the order).

Failing output (the 3 genuine REDs):

```
1) SC-3 — Events-first lead section (#122, DrupalCon Portland 2026) › leads with an "Upcoming events" section...
   Error: expect(locator).toBeVisible() failed
   Locator: locator('.gc-group-lead')
   Expected: visible
   Error: element(s) not found

2) SC-3 — Discussion-first lead section (#122, Core Committers) › leads with a "Recent discussions" section...
   Error: expect(locator).toBeVisible() failed
   Locator: locator('.gc-group-lead')
   Expected: visible
   Error: element(s) not found

3) SC-3 — Tooltip accessibility (#122) › the ⓘ trigger... keyboard-focusable with a non-empty accessible name...
   Error: expect(locator).toBeVisible() failed
   Locator: locator('.gc-group-lead').locator('.gc-group-lead__help')
   Expected: visible
   Error: element(s) not found
```

Each fails on the **feature assertion** (`.gc-group-lead` doesn't exist) — not on a missing
route (`page.goto` returned 200 in every case, confirmed separately), not on a bad selector for
an unrelated element, and not on a login/setup failure. This is a valid RED: F implementing the
lead-section render is exactly what flips these three to green.

Sanity check that the 7 "passing" tests are not false positives: confirmed via `curl` that all
four exemplar group labels render on `/all-groups`, and re-ran the pre-existing
`directory-cards.spec.ts` suite against the same seeded site (3/3 pass) to confirm the seed and
site are healthy, not merely returning errors that happen to satisfy loose assertions.

### PHPUnit — HelpText Unit test

Commands:
```
ddev exec bash scripts/ci/assemble-config.sh
ddev exec php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php
```

Result: **10 passed, 1 failed** (the pre-existing 10 tests stay green; only the new test fails):

```
✘ Group type homepage adapts copy is present and names variants
   │
   │ The group_type.homepage_adapts tooltip copy must exist.
   │ Failed asserting that two strings are not identical.
   │
   │ /var/www/html/web/modules/custom/do_chrome/tests/src/Unit/HelpTextTest.php:168

Tests: 11, Assertions: 92, Failures: 1, PHPUnit Deprecations: 12.
```

Fails on the exact right assertion (`HelpText::get('group_type.homepage_adapts')` returns `''`,
the documented unknown-key default, because F hasn't appended the key to `HelpText::all()` yet)
— not on a PHP fatal, not on a missing class/namespace, not on an unrelated pre-existing test
regressing. The 11 PHPUnit deprecation notices are pre-existing (unrelated to this change;
present in the baseline 10-passing run too).

## Lint note (informational, not a test)

Ran `phpcs` against my modified test file as a courtesy (not required by T's scope — phpcs runs
standalone per the task instructions). Pre-existing style violations already present in this
file (lines 71, 112, 126 — short-description capitalization on prior `#89`/`#88`-prefixed
docblocks) are untouched by me. My own added docblock (lines 152-165) follows the identical
existing convention (`#122 (SC-3): ...` prefix) and phpcs flags it the same way it flags the
pre-existing ones — consistent with, not worse than, the file's current baseline. F's own
production-file changes must still keep `phpcs` clean per the brief's AC; this note is scoped
only to the test file I authored.

## Assumptions made

1. **Exemplar group resolution by label, not hard-coded gid.** `groupUrlByLabel()` navigates
   `/all-groups` and follows the directory card's own link, rather than assuming `gid=1` etc. —
   gids are creation-order-dependent and not guaranteed stable across a fresh seed run.
2. **"See all" query-string form asserted as `type=forum` OR `type[]=forum`** (regex
   `\?type(\[\])?=forum`) per A's own hedge in `handoff-A.md` ("F should verify the exact
   query-string form... either is acceptable if it filters correctly").
3. **Item link target asserted generically as `/node/\d+`**, not a specific node id, since the
   "top N" selection/sort algorithm is F's implementation detail (mirroring the
   entityQuery-via-relationships mechanism A specified) — the test pins "links to real nodes",
   not "links to this exact node".

## Items declined to test, with justification

1. **Full WCAG 2.2 AA axe scan** (all four rendered pages) — declined to automate a
   full `@axe-core/playwright` scan because that dependency does not exist in this repo's
   `package.json` (`manage-members.spec.ts` already established and documented this exact
   gap). Adding a new devDependency is a tooling decision outside T's remit per the pipeline's
   own model-tier/scope boundaries. I instead: (a) authored the keyboard/focus/aria-label test
   above (test #5), which covers the parts a headless browser CAN prove without axe; (b) added
   an explicit stub test that pins the *absence* of the axe-core dependency so a future
   `npm install @axe-core/playwright` self-flags this test for replacement with a real scan;
   (c) noted the standalone tool `docs/playbook/pipelines/website-frontend/core/tools/axe-check.cjs`
   remains available for a manual/documented-exception pass by U if the operator wants a real
   axe run before merge.
2. **Rendered docs-first content assertion for Thunder Distribution** — declined (see test #3's
   note above): no documentation-type node exists anywhere in the current seed data, and adding
   one would mean editing `docs/groups/scripts/step_700_demo_data.php` or a sibling seed script,
   which is outside this story's file-ownership list (`brief.md`'s "Files this story owns" /
   "does NOT touch" sections do not list any seed script). Flagging this as a coverage hole for
   F/U rather than silently declaring full exemplar coverage.
3. **Kernel test for the preprocess mapping** — declined; see "Kernel — none authored" above.

## Ready for F

**Confirmed RED is valid.** Both the E2E suite (3 genuine feature-dependent failures, all
failing on the correct assertion) and the PHPUnit HelpText addition (1 genuine failure on the
correct assertion) are real REDs — none is a setup/import/typo failure. F may implement against
these tests now.

**Environment note for F:** the `gm122-groups-on-d11` DDEV project in this worktree is left
running, installed, config-imported, themed, and seeded — F can iterate directly against
`http://gm122-groups-on-d11.ddev.site` without repeating the site-install steps. If F changes
`docs/groups/modules/do_chrome/src/HelpText.php` or any theme file, re-run
`ddev exec bash scripts/ci/assemble-config.sh` then `ddev exec drush cache:rebuild` before
re-testing (theme files are copied as-is from git, not assembled, so a live theme-file edit
takes effect on the next request without re-running assemble — only `do_chrome`'s
module-owned `HelpText.php` needs the assemble step to reach `web/modules/custom/`).
