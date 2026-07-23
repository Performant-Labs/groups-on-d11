# Handoff-T-green: Phase 6 - #140 MC-1 Links & Resources

**Date:** 2026-07-23
**Branch:** 140-links
**Issue:** #140
**Handoff-F reviewed:** `docs/planning/handoffs/140-links/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/140-links/handoff-T-red.md`

## Test-authorship fix applied (T's own bug, per pipeline rule)

`GroupLinksFieldTest::setUp()` only called `installConfig(['field'])`, which — per F's
root-caused finding in handoff-F.md ("Tests that look wrong (for T)") — can never install
`do_group_extras`'s own field config: kernel tests never auto-install a listed module's
`config/install/` directory or invoke `hook_install()` for modules in `static::$modules`
(confirmed against `KernelTestBase::bootKernel()` / `DrupalKernel::updateModules()`), and
`installConfig(['field'])` is itself redundant with the grandparent
`EntityKernelTestBase::setUp()`, which already calls it. This is a test-authorship bug (mine, as
T) — F correctly did not touch it.

**Fix:** added programmatic `FieldStorageConfig::create()` / `FieldConfig::create()` /
`EntityViewDisplay::create()->setComponent()` / `EntityFormDisplay::create()->setComponent()`
calls to `setUp()`, mirroring the exact convention `GroupExtrasBehaviorTest` and `GroupRestoreTest`
(this module's two sibling kernel tests) already use for their own config-only field
(`field_group_type`). Every setting value was copied verbatim from F's shipped YAML so the kernel
fixture can never drift from the real shipped config:
- `docs/groups/config/field.storage.group.field_group_links.yml` → storage (`type: link`,
  `cardinality: -1`, `translatable: true`)
- `docs/groups/config/field.field.group.community_group.field_group_links.yml` → instance
  (`label: 'Links & Resources'`, `required: false`, `settings.link_type: 17`, `settings.title: 2`)
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml` → the
  `field_group_links` component only (`label: above`, `type: link`, `weight: 20`, formatter
  settings `trim_length: 80`, `url_only: false`, `url_plain: false`, `rel: noopener`,
  `target: _blank`)
- `docs/groups/config/core.entity_form_display.group.community_group.default.yml` → the
  `field_group_links` component only (`type: link_default`, `weight: 4`, `placeholder_url: ''`,
  `placeholder_title: ''`)

No production code was edited. Only the test file's `setUp()` (plus an updated class docblock
explaining the fixture rationale) changed.

## Second test-authorship bug found and fixed during E2E verification

While running the E2E suite (see "E2E" below), the second test in
`tests/e2e/group-links.spec.ts` — "every external link on the group page carries
rel=\"noopener\"" — failed against a REAL page load, but for the wrong reason: it swept **every**
`a[href^="http"]` anchor on the entire rendered group page, not just the feature's own
`field_group_links` output. Olivero's core theme renders its own unrelated footer link
(`<a href="https://www.drupal.org">Drupal</a>`, "Powered by Drupal", no `rel`/`target` at all, by
core theme design) which the unscoped locator caught and failed against — a false positive
against unrelated theme chrome, not a defect in F's `field_group_links` rendering.

**Fix:** rescoped the locator from `page.locator('a[href^="http"]')` to
`page.locator('.field--name-field-group-links a[href^="http"]')` — the field's own render wrapper
class, confirmed via `curl` against the live seeded page
(`<div class="field field--name-field-group-links field--type-link field--label-above">`). Also
renamed the test title to "every external link **in the Links & Resources field**..." to make the
scope explicit. Re-ran: both E2E tests now pass.

## GREEN confirmation

```
cd ~/Projects/_worktrees/groups-links
ddev exec bash scripts/ci/assemble-config.sh   # exit 0
ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php'
```

```
Group Links Field (Drupal\Tests\do_group_extras\Kernel\GroupLinksField)
 ✔ Storage exists
 ✔ Instance exists
 ✔ Full display shows field
 ✔ Form display shows field
 ⚠ Renders external link with rel noopener   (passes; ⚠ = pre-existing Twig-sandbox deprecation)
 ⚠ Internal link rendered                     (same)
 ⚠ Empty state renders nothing                (same)

Tests: 7, Assertions: 165, Deprecations: 2.
OK, but there were issues!
```

**All 7/7 GREEN** — identical assertion count/shape to F's throwaway diagnostic run (165
assertions, 2 deprecations), confirming the fixture now exercises exactly the production code path
F built.

**Behavior-sensitivity spot-check (mutation test):** temporarily changed the test's own fixture
`'rel' => 'noopener'` to `'rel' => 'mutated-none'` in `setUp()`, re-assembled, re-ran
`testRendersExternalLinkWithRelNoopener` alone — it FAILED (`Failures: 1`) as expected. Reverted
the mutation, re-assembled, re-ran the full 7-test file — back to 7/7 GREEN. This proves the test
is pinned to observable behavior (the `rel` attribute in rendered HTML), not vacuously true.

## Tier 1 results

| Check | Command | Expected | Actual | Result |
|---|---|---|---|---|
| Kernel suite (story) | `phpunit ... GroupLinksFieldTest.php --testdox` | 7/7 pass | 7/7 pass, 165 assertions | PASS |
| Kernel suite (no-regression, 11 modules) | `phpunit ... do_discovery ... do_tests` (exact task command) | 0 failures | `Tests: 118, Assertions: 3258, Deprecations: 28` — **no `Failures:` line at all** | PASS |
| assemble-config.sh | `ddev exec bash scripts/ci/assemble-config.sh` | exit 0 | `==> assemble-config: done`, exit 0 | PASS |
| Config files present in assembled `config/sync/` | `ls config/sync/{field.storage...,field.field...,core.entity_view_display...,core.entity_form_display...}` | all 4 present | all 4 present (with correct sizes/timestamps) | PASS |

## Tier 2 results

| Check | Method | Result |
|---|---|---|
| Test coverage vs acceptance criteria | Cross-referenced each of the 7 kernel + 2 E2E tests against handoff-T-red.md's criteria table | All criteria covered (storage, instance, both displays, external-link rel/target, internal-link render, empty-state, seeded-anonymous-view, live-page rel="noopener") | PASS |
| Test quality (test-quality.md §7) | Reviewed all 7 kernel + 2 E2E tests: each names a specific behavior, sits at the cheapest sufficient tier (kernel for config/render-in-isolation, E2E only for the full seeded-install path), asserts rendered HTML/config shape not implementation mechanism, no duplication between kernel and E2E (E2E doesn't re-assert the rel mechanism against 3 different groups — by design, per T-red's own proportionality note) | PASS — suite is proportionate, no redundant tests found, none flagged for deletion |
| Type safety | Read the full edited test file; all `FieldStorageConfig`/`FieldConfig`/`EntityViewDisplay`/`EntityFormDisplay` calls use typed core APIs with correct scalar/array types (`-1` int, `TRUE`/`FALSE` bool, string enums) | PASS — no `any`/untyped casts |
| Error handling | N/A — this story has no new error paths (config-only + CSS attach); empty-state IS the "error"/edge-case path and is explicitly tested (`testEmptyStateRendersNothing`) | PASS |
| Data integrity | Kernel `setUp()` fixture values verified byte-for-byte against F's shipped YAML (see "Test-authorship fix" above); no drift between fixture and shipped config | PASS |
| API contract | Field/display component shapes match `docs/groups/config/*.yml` exactly (settings keys, values, widget/formatter machine names) | PASS |
| Security | `rel="noopener"` + `target="_blank"` (tabnabbing mitigation for external links) explicitly asserted on rendered HTML in both kernel and E2E tiers | PASS |
| Migration safety | N/A — no schema migration, only new field config (additive, non-destructive) | N/A |
| phpcs (edited test file) | `ddev exec php vendor/bin/phpcs --standard=Drupal,DrupalPractice --extensions=php,module,install docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` | 8 errors — **all confirmed pre-existing** by running phpcs against the original committed version too (9 errors incl. one scratch-filename artifact; the other 8 are byte-identical doc-comment-style findings present before my edit). Zero NEW warnings introduced by the `setUp()` change. | PASS (no new issues) |
| YAML parse (all 4 config files) | `Symfony\Component\Yaml\Yaml::parseFile()` on all 4 new/edited config YAMLs via a throwaway scratch script (deleted after use, never staged) | All 4 parse cleanly | PASS |
| Playwright E2E | `npx playwright test tests/e2e/group-links.spec.ts` against a fully installed + config-imported + seeded local DDEV site (see "E2E" below) | 2/2 pass after the scoping fix | PASS |
| Playwright full-suite no-regression | `npx playwright test` (all specs) | 0 unrelated regressions from #140 | 1 pre-existing failure in `group-restore.spec.ts` (#143's own story, unrelated file — see "Advisory notes") |

## E2E — ran to completion (not CI-only this pass)

Unlike F's session, this worktree had DDEV already running with a DB. I installed the site,
imported the assembled config, seeded demo data (all 3 seed scripts:
`step_700_demo_data.php`, `step_720_group_types.php`, `step_780_nav_menu.php`, matching the exact
CI sequence in `.github/workflows/test.yml`), `npm install`, `npx playwright install chromium`,
then ran:

```
BASE_URL="http://gm140-groups-links.ddev.site" npx playwright test tests/e2e/group-links.spec.ts
```

Result (after the scoping fix described above):
```
ok 1 › anonymous sees a Links & Resources section with a known seeded link (421ms)
ok 2 › every external link in the Links & Resources field carries rel="noopener" (404ms)
2 passed (1.5s)
```

Both of #140's acceptance criteria that require the full seeded-install path are now
independently confirmed against a real, running, seeded Drupal 11 site — not just kernel-level
isolation.

## Acceptance criteria status

| # | Criterion (from brief) | Test | Status |
|---|---|---|---|
| 1 | `field_group_links` storage exists, core `link` field, cardinality -1 | `testStorageExists` (kernel) | PASS |
| 2 | Instanced on `community_group`, label "Links & Resources", not required, title required per delta | `testInstanceExists` (kernel) | PASS |
| 3 | Group Full display exposes the field | `testFullDisplayShowsField` (kernel) | PASS |
| 4 | Group edit/add form exposes the field with a link widget | `testFormDisplayShowsField` (kernel) | PASS |
| 5 | External links render `rel="noopener"` + `target="_blank"` (observable HTML) | `testRendersExternalLinkWithRelNoopener` (kernel) + E2E test 2 | PASS |
| 6 | Internal links render correctly, not forced into external-only treatment | `testInternalLinkRendered` (kernel) | PASS |
| 7 | Empty state: no "Links & Resources" text/heading when zero links | `testEmptyStateRendersNothing` (kernel) | PASS |
| 8 | ≥3 seeded groups show ≥2 links each (demo data) | Verified directly: `Step 735` output shows DrupalCon Portland 2026, Core Committers, Thunder Distribution each seeded with 2 links; E2E test 1 confirms anonymous-visible rendering for the first | PASS |

## Blocking issues

None. All 7 kernel tests + 2 E2E tests for this story are GREEN, no regressions in the 11-module
kernel no-regression sweep (118 tests, 0 failures), phpcs clean (no new issues), all 4 config YAMLs
parse, assemble-config exits 0.

## Advisory notes

- **Pre-existing, unrelated failure found in `tests/e2e/group-restore.spec.ts` (#143 MC-5, a
  DIFFERENT story) during the full-suite Playwright no-regression sweep.** The
  archive→restore→archive round-trip test fails at the post-restore assertion
  (`expect(page.getByText(/Archived/i)).toHaveCount(0)` — finds 2 elements instead of 0) even after
  manually resetting the seeded group's `field_group_type` back to the Archive term and a full
  `cache:rebuild`. Confirmed via `git status`/`git diff --stat` that neither
  `RestoreGroupForm.php` nor `group-restore.spec.ts` were touched by this story's diff — this is
  pre-existing behavior in #143's own test/feature, not a regression introduced by #140. Not
  investigated further (out of this story's scope); flagging for O/a future pass on #143.
- The DDEV site in this worktree is now a fully installed, seeded Drupal instance (not just a
  kernel-test DB). This is a durable local artifact if a later phase (U/S) wants to walk the UI —
  the site is live at `http://gm140-groups-links.ddev.site`, admin/admin.
- `config/sync/` shows extensive untracked/modified churn from the `site:install` +
  `config:import` I ran for E2E — this mirrors the CI e2e job's own behavior (CI does the same
  install+import in an ephemeral runner) and is expected build-pipeline noise, not part of this
  story's 9-file production diff or my 2-file test diff. Not staged; O should not stage
  `config/sync/*` changes from this session.
- Confirmed (again, independently of F) that the `title: 2` vs `title: 1` deviation F flagged is
  correct per the brief's literal "title required per delta" criterion — no test asserts the
  literal settings value, so this remains F's/O's call, not a T blocker.

## Files touched by T this phase

- `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php` (edited —
  `setUp()` fixture + docblock)
- `tests/e2e/group-links.spec.ts` (edited — scoped the rel="noopener" locator to
  `.field--name-field-group-links`)

No production code was edited.
