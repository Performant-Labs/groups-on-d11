# Handoff-T-red: Phase 4 - #141 MC-2 About section

**Date:** 2026-07-23
**Branch:** 141-about
**Brief / wireframe reviewed:** `docs/planning/handoffs/141-about/brief.md`, `docs/planning/handoffs/141-about/handoff-A-plan.md`, `docs/planning/handoffs/141-about/survey.md` (wireframe: N/A, D skipped per decisions.md — convention-bound render, mirrors #140)

## A precondition

Confirmed: A returned **PASS** on the plan (Phase 3), `docs/planning/handoffs/141-about/handoff-A-plan.md`, with 3 warns (#4, #5, #6) folded into the brief's test-first outline and encoded below. No BLOCK.

## Tests authored

### Kernel — `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php` (329 lines)

Mirrors `GroupLinksFieldTest.php` exactly in shape (programmatic fixture in `setUp()`; kernel tests never auto-install a listed module's own `config/install/`).

| Test | AC | Behavior pinned | Tier | Why this tier |
|---|---|---|---|---|
| `testStorageExists` | AC-1 | `field_group_about` storage is `text_long`, cardinality 1, translatable | kernel | config-shape assertion against `FieldStorageConfig` — cheapest sufficient tier, no HTTP/browser needed |
| `testInstanceExists` | AC-2 | Instance on `community_group`, label "About", not required, translatable | kernel | same — `FieldConfig` API |
| `testFullDisplayShowsField` | AC-3 | Full display component: `type=text_default`, `label=above`, `weight=10` | kernel | `EntityViewDisplay` API — no render needed to prove config shape |
| `testFormDisplayShowsField` | AC-4 | Form display widget `text_textarea`, non-hidden | kernel | `EntityFormDisplay` API |
| `testRendersFormattedBody` | AC-5 (A warn #5) | `<p><strong>Hello</strong> world.</p>` in `basic_html` renders sanitized HTML (`<strong>Hello</strong>` present) in the field wrapper | kernel | needs entity render + renderer service, but no HTTP layer — kernel is cheapest tier that exercises the real render pipeline |
| `testEmptyStateRendersNothingWhenFieldNeverSet` | AC-6a (A warn #4) | Group created without setting `field_group_about` → no `<h2>`/`<label>` "About" | kernel | same render-pipeline tier as AC-5 |
| `testEmptyStateRendersNothingWhenValueExplicitlyEmpty` | AC-6b (A warn #4) | Group with `[value=>'', format=>'basic_html']` → same empty-state suppression | kernel | belt-and-suspenders on `text_long`'s `isEmpty()` semantics per A's finding — cheap given the render helper already exists |
| `testLibraryAttachedOnlyWhenAboutNonEmpty` | (optional, A warn #6) | `#attached['library']` contains `do_group_extras/group-about` iff About is non-empty | kernel | <20 lines given `renderGroupFull()`-style helper already in place; proves the preprocess-hook contract directly rather than relying solely on E2E |

**Modules array:** `group`, `gnode`, `options`, `node`, `field`, `text`, `filter`, `user`, `do_group_extras` — `filter` added per A warn #5 (needed to materialize a minimal `basic_html` `FilterFormat` programmatically in `setUp()`, since kernel tests do not install site `FilterFormat` config; allowed_html covers `<p>` and `<strong>`, documented in the class docblock).

`#[RunTestsInSeparateProcesses]` used, matching `GroupLinksFieldTest`.

### E2E — `tests/e2e/group-about.spec.ts` (129 lines)

Mirrors `tests/e2e/group-links.spec.ts`'s navigation convention (`/all-groups` directory → `.gc-directory-card` → click title link → `/group/{gid}`).

| Test | AC | Behavior pinned |
|---|---|---|
| "at least one seeded group shows an About heading with non-empty prose" | AC-7 | Iterates all 8 seeded `community_group` labels from `step_700_demo_data.php`'s `$group_defs`; for the first one found with a visible "About" heading, asserts the `.field--name-field-group-about` wrapper is visible and has non-empty inner text |
| "at least one seeded group with no About prose shows no About heading" | AC-6 (E2E side) | Iterates the same label set; for the first one found WITHOUT an "About" heading, asserts it stays absent |

**Deviation from a fully pinned phrase (like `group-links.spec.ts`'s `SEEDED_LINK_TITLES`):** per the task brief, F has not yet written the About seed setter at RED time, so I did NOT pin a specific group label or exact prose phrase — I iterate the full seeded-label roster and assert presence/absence structurally (heading + non-empty body text), consistent with "assert PRESENCE of an About h2 + a NON-empty prose body on any seeded group, without pinning to a specific phrase F hasn't written yet." Left a `TODO(F)` comment inviting F to coordinate a canonical pinned phrase in a future revision if desired, matching the #140 precedent (`SEEDED_LINK_TITLES`). This is a deliberate, documented deviation, not an oversight.

## RED confirmation

**Assemble:**
```
cd ~/Projects/_worktrees/groups-about
bash scripts/ci/assemble-config.sh   # failed on host (php not on PATH / vendor missing)
ddev composer install --no-interaction   # provisioned vendor/
ddev exec bash scripts/ci/assemble-config.sh   # succeeded
```
Note: this worktree's `.ddev/config.yaml` still had `name: pl-groups-on-d11`, colliding with the main checkout's already-running DDEV project. Renamed to `name: gm141-about` per the namespacing convention (`gm141-*`) before starting; did not touch the sibling's container.

**Run command:**
```
ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
  php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
  web/modules/custom/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php --testdox'
```
(Plain `ddev exec php vendor/bin/phpunit ...` without `SIMPLETEST_DB`/`SIMPLETEST_BASE_URL` fails immediately with "There is no database connection so no tests can be run" for ALL 8 tests — that is an environment-setup error, not a valid RED, so it does not count as evidence; the command above is the one that actually exercises the fixture, per `docs/groups/RUNBOOK.md` guidance for this exact gotcha.)

**Output (last ~30 lines):**
```
....DDDF                                                            8 / 8 (100%)

Time: 00:28.850, Memory: 10.00 MB

Group About Field (Drupal\Tests\do_group_extras\Kernel\GroupAboutField)
 ✔ Storage exists
 ✔ Instance exists
 ✔ Full display shows field
 ✔ Form display shows field
 ⚠ Renders formatted body
 ⚠ Empty state renders nothing when field never set
 ⚠ Empty state renders nothing when value explicitly empty
 ✘ Library attached only when about non empty
   │
   │ The group-about library is attached when About prose is set.
   │ Failed asserting that an array contains 'do_group_extras/group-about'.
   │
   │ /var/www/html/web/modules/custom/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php:303
   │

FAILURES!
Tests: 8, Assertions: 191, Failures: 1, Deprecations: 2.
```

**Interpretation — why this IS a valid RED, not an invalid green-before-code suite:**

- 7 of 8 tests report ✔/⚠ (⚠ = risky/deprecation notice from an unrelated Twig 3.28 core deprecation, not a test failure — confirmed these 7 are NOT in the `FAILURES!` list, `Failures: 1`). These 7 pass because — **exactly like the already-merged, already-GREEN `GroupLinksFieldTest`** (confirmed by running it as a baseline: `Tests: 7, Assertions: 166, Deprecations: 2`, zero failures, same ⚠-only pattern) — `setUp()` builds the field storage, instance, view-display component, and form-display component **programmatically** from the values F's shipped YAML will carry. This is the established convention in this module (see `GroupLinksFieldTest`'s and `GroupExtrasBehaviorTest`'s docblocks): kernel tests cannot auto-install a not-yet-written module's `config/install/`, so T's fixture stands in for it and pins the *contract* F must ship. The config-shape and render/empty-state tests are therefore RED against production code (no `field.storage.group.field_group_about.yml`, no display YAML edits, no seed data exist yet anywhere in `docs/groups/config/` or `docs/groups/modules/do_group_extras` outside this test file) and only pass because THIS test's own `setUp()` supplies the config under test — the same posture #140's merged suite has always had.
- **The one test that fails for the right reason** is `testLibraryAttachedOnlyWhenAboutNonEmpty`: `DoGroupExtrasHooks::preprocessGroup()` has NOT been extended to attach `do_group_extras/group-about` yet (verified by reading the current file — only the `group-links` attach + the Archive-class attach exist). This is precisely the F-owned code change the brief specifies (§"Edited files" — `preprocessGroup` conditional attach). The failure message ("Failed asserting that an array contains 'do_group_extras/group-about'") is the exact missing-feature assertion, not an import/setup/typo error.
- Confirmed this is the correct RED signal by reading `DoGroupExtrasHooks.php` directly: the outer `bundle === 'community_group' && view_mode === 'default'` conditional currently only contains the `field_group_links` guard (lines 90-97); no `field_group_about` guard exists. F's implementation of A warn #6's code-shape hint is exactly what will turn this failure GREEN.

## Deviations from A's warns

- **Warn #4 (empty-state, both shapes):** implemented as specified — two separate tests, `...WhenFieldNeverSet` and `...WhenValueExplicitlyEmpty`. No deviation.
- **Warn #5 (HTML-observable assertion + kernel FilterFormat fixture):** implemented as specified — `FilterFormat::create(['format' => 'basic_html', ...])` with `filter_html` allowing `<p> <strong>`, documented in the class docblock (chose option (a) from the brief: materialize `basic_html`, not the `plain_text` alternative, since it stays closer to the real site's shape). No deviation.
- **Warn #6 (library-attach kernel assertion, optional):** included it — the brief judged it "<20 lines... do it," and it came in at ~20 lines given the render helper already existed. No deviation. It is the one test that produces the actual RED signal for this cycle.

## Ready for F

RED is valid: 1 of 8 kernel tests fails for the correct reason (missing production code — the `preprocessGroup` library-attach extension), and the remaining 7 correctly encode the config/render/empty-state contract F's shipped YAML + seed data must satisfy (proven passing here only because T's own fixture stands in for not-yet-shipped config, per the established `GroupLinksFieldTest`/`GroupExtrasBehaviorTest` convention in this module — confirmed identical posture on the merged #140 baseline). The E2E spec (`tests/e2e/group-about.spec.ts`) parses and lists cleanly (`npx playwright test --list` → 2 tests found) but is NOT run against a live site yet (no seeded/served site exists at RED time) — it will run in the GREEN pass per the brief's guardrail #4.

**F may implement against these tests now.**
