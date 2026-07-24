# Handoff-T-red: Phase 4 - #191 seed pipeline cascade fix

**Date:** 2026-07-24
**Branch:** 191-seed-cascade-fix
**Brief / wireframe reviewed:** `docs/planning/handoffs/191-seed-cascade-fix/brief.md`,
`docs/planning/handoffs/191-seed-cascade-fix/survey.md`,
`docs/planning/handoffs/191-seed-cascade-fix/handoff-A-plan.md` (no wireframe — backend seed
script, no UI surface).

## A precondition

Confirmed: A returned PASS on the plan (Phase 3). A recommended a Kernel test at
`web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php` (source-tree
equivalent authored at `docs/groups/modules/do_group_language/tests/src/Kernel/SeedStep640Test.php`,
per this project's fixture-must-be-module-local / assemble-first convention).

## Tests authored

One Kernel test class, `SeedStep640Test`, six test methods — one per acceptance-criterion /
cascade layer, at the cheapest sufficient tier (Kernel, per A's recommendation; no Functional/E2E
needed since the failure mode is entity-API level):

1. **`testStep640BackfillsLockedLanguagesAndCompletesWithoutFatal`** — pins Layers 1+2: after
   step_640 runs, the `und`/`zxx` locked `configurable_language` entities exist (backfilled).
   Tier: Kernel (entity storage only).
2. **`testStep640AddsAllFourteenCustomLanguages`** — pins the 14-custom-language AC. Tier: Kernel.
3. **`testStep640ConfiguresLanguageNegotiation`** — pins the `language.types` negotiation AC.
   Tier: Kernel (config only).
4. **`testStep640InstallsContentTranslationModule`** — pins the defensive `content_translation`
   module-install AC. Tier: Kernel.
5. **`testStep640ContentLanguageSettingsAreWellFormedPerBundle`** — pins Layer 3: for each of
   `forum`/`documentation`/`event`/`post`/`page`, asserts the RAW active-config-storage record
   has `target_entity_type_id`/`target_bundle` populated (the only way to observe the Layer-3
   defect — `ContentLanguageSettings::loadByEntityTypeBundle()` alone masks a missing/malformed
   record by returning a fresh default-value entity per its own docblock), AND that
   `ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)` loads correctly with
   `content_translation.enabled` = TRUE via `getThirdPartySetting()`. Tier: Kernel.
6. **`testStep640IsIdempotent`** — runs step_640 twice; asserts no duplicate languages and
   settings remain well-formed. Tier: Kernel.

No duplication: each test targets a distinct behavior/AC: none overlap.

## Precondition simulation (the CI cascade gap)

`language` + `content_translation` are declared in `protected static $modules` and enabled via
`KernelTestBase`'s own container boot — a real module-enable path, matching "language already
listed in the assembled `core.extension.yml`" for the rest of the test. `setUp()` then explicitly
`delete()`s the `und`/`zxx` locked `configurable_language` entities that `installConfig(['language'])`
seeded, reproducing the exact gap CI's `drush config:import` leaves (it skips
`installDefaultConfig()`, hence `language/config/install/language.entity.{und,zxx}.yml`, for a
module already active in `core.extension.yml`). Five node bundles (`forum`, `documentation`,
`event`, `post`, `page`) are created via `NodeType::create()->save()` in `setUp()` — real bundle
config entities, not stubs, so `ContentLanguageSettings::calculateDependencies()` has a genuine
bundle-config dependency to resolve.

`runStep640()` executes the real `docs/groups/scripts/step_640.php` via `require`, per A's
recommendation, so the test exercises the actual production code path rather than a
reimplementation. Its own progress `echo`es are suppressed with a local `ob_start()`/`ob_end_clean()`
pair (PHPUnit's `beStrictAboutOutputDuringTests` would otherwise mark every test "risky" — nested
buffering resolves cleanly since PHPUnit reads its own outer buffer via `ob_get_clean()` after ours
has already discarded).

## RED confirmation

Assembled first (`bash scripts/ci/assemble-config.sh` via `ddev exec`, since local `php` isn't on
PATH outside DDEV), then ran:

```
ddev exec bash -lc 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist \
  web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php --testdox'
```

Result against current `main` step_640 (broken):

```
F...FE                                                              6 / 6 (100%)

 ✘ Step 640 backfills locked languages and completes without fatal
   │ und locked language entity is backfilled by step_640.
   │ Failed asserting that null is not null.
   │ .../SeedStep640Test.php:166

 ✔ Step 640 adds all fourteen custom languages
 ✔ Step 640 configures language negotiation
 ✔ Step 640 installs content translation module

 ✘ Step 640 content language settings are well formed per bundle
   │ language.content_settings.node.forum has target_entity_type_id = 'node' (not a bare config write missing this key).
   │ Failed asserting that null is identical to 'node'.
   │ .../SeedStep640Test.php:233

 ✘ Step 640 is idempotent
   │ Drupal\language\ContentLanguageSettingsException: Attempt to create content language settings without a target_entity_type_id.
   │ web/core/modules/language/src/Entity/ContentLanguageSettings.php:108
   │ .../SeedStep640Test.php:264

Tests: 6, Assertions: 100, Errors: 1, Failures: 2.
```

Three tests fail for the exact right reason:
- **Layer 2** (`testStep640BackfillsLockedLanguagesAndCompletesWithoutFatal`): `und` is never
  backfilled by current step_640 — the assertion the feature must satisfy fails, cleanly (no
  fatal here because step_640's own `ConfigurableLanguage::create(['id' => ...])->save()` calls
  on the 14 custom languages tolerate the missing locked entities in this exact code path — see
  "Deviation" note below — but the AC itself is unmet, which is what this test pins).
- **Layer 3** (`testStep640ContentLanguageSettingsAreWellFormedPerBundle`): the raw config record
  written by current step_640 has no `target_entity_type_id`/`target_bundle` — assertion fails
  exactly as the Layer-3 diagnosis predicts.
- **Layer 3 downstream** (`testStep640IsIdempotent`): the second `step_640` run's
  `ContentLanguageSettings::loadByEntityTypeBundle()` call throws
  `ContentLanguageSettingsException` loading the malformed record left by the first run — this is
  the literal step_795-class fatal from the brief, reproduced directly.

The other three tests pass already (14-language add, negotiation config, defensive module-install)
because current step_640 happens to satisfy those specific ACs even though the overall script is
broken — this is expected and correct: they will remain green after F's fix (no regression risk),
and are retained for AC coverage / regression-proofing, not padding.

## Deviation from A's expectation

A's handoff anticipated the `setWeight()` on `null` fatal (Layer 1/2) would reproduce directly.
It does not fatal in this precise Kernel fixture — Drupal 11's
`ConfigurableLanguageManager::updateLockedLanguageWeights()` tolerates a missing locked entity by
skipping it silently rather than fataling, in this exact call path (real behavior may still fatal
under the full CI `config:import` + `drush scr` invocation, which the brief's own diagnosis
observed). The **assertion still fails** — the ACs "und/zxx backfilled" and "idempotent re-run"
are unmet on `main`, which is what T is required to pin — so RED is valid regardless: F's fix is
still required to satisfy these assertions, and the test still fails for the right reason (the
feature is unimplemented), not a setup/import error. Noted for F/O; not a blocker.

## Ready for F

Confirmed RED is valid — 3 of 6 authored tests fail against `main`'s step_640, each for the
correct behavioral reason (Layers 2 and 3 of the diagnosed cascade), the other 3 already pass and
will regression-guard after the fix. F may implement against these tests now.
