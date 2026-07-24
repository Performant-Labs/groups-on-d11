# Handoff-F: Phase 5 - #191 seed pipeline cascade fix

**Date:** 2026-07-24
**Branch:** 191-seed-cascade-fix
**Issue:** #191

## What was done

- `docs/groups/scripts/step_640.php` — ported the three-layer defensive fix
  verbatim from the reference implementation on `origin/139-multilang-rtl`
  (final state after commits `8d535ab`, `1ba0eab`, `838ab6f` are squashed
  together):
  1. Install `language` module if not already enabled (idempotent guard on
     `moduleExists()`).
  2. Install `content_translation` module if not already enabled (needed for
     the third-party-setting API below to have any effect).
  3. Backfill the `und`/`zxx` locked `configurable_language` entities if
     missing — the exact gap `drush config:import` leaves when `language` is
     already listed in the ACTIVE `core.extension.yml` (which
     `scripts/ci/assemble-config.sh:123-126,140` explicitly does for this
     codebase — confirmed by reading that script, not assumed).
  4. Switch the 14-custom-language creation from `$storage->create(['id' =>
     $langcode])` to `ConfigurableLanguage::createFromLangcode($langcode)` —
     this was in the reference diff (not called out separately in the brief's
     3-layer list, but load-bearing for Arabic/RTL direction being set
     correctly rather than silently defaulting to LTR) — ported as-is since
     it's part of the tested-good reference and required no separate
     justification.
  5. Switch content-translation-per-bundle from a bare `configFactory()
     ->getEditable(...)->set(...)->save()` write to
     `ContentLanguageSettings::loadByEntityTypeBundle('node', $type)` +
     `setThirdPartySetting('content_translation', 'enabled', TRUE)` +
     `save()` — populates `target_entity_type_id`/`target_bundle`, which the
     bare config write omitted.
- `.github/workflows/test.yml` — inserted a new dedicated step, "Seed
  languages + content translation (step_640)", in the `e2e` job, between the
  "Install Drupal + import assembled config" step (ends line 523, `drush
  status` diagnostic) and "Seed full demo data" (starts what is now line
  545 post-insert) — per A's correction to the brief's aspirational
  "step_620c" wording (no such step exists on main). Follows the
  `php:script "$PWD/docs/groups/scripts/..."` absolute-path idiom the
  neighboring "Seed full demo data" step already documents (relative
  `require` paths break because `drush php:script` resolves `getcwd()` to
  the docroot, not the workspace root).

## Design decisions

- **Verbatim port, no re-architecture.** The brief and A's plan both said
  "port, don't rewrite" — the reference commits already compile, match the
  diagnosed root cause exactly, and T's RED run confirmed the current
  (unfixed) main script fails T's tests for the right reason. No changes
  needed beyond copying the final state.
- **No uid-1 wrapper in the CI step.** Sibling seed steps
  (step_700/720/780/790/7xx/795) wrap their `require` in a heredoc that sets
  `\Drupal::currentUser()` to uid 1 before running, because they create
  content (nodes, groups) whose presave hooks check the acting user (e.g.
  do_group_extras' unpublish-on-create hook). step_640 only installs modules
  and writes config/language entities — no content is created and no hook in
  its call path is user-sensitive — so the plain `php:script` invocation
  (no uid-1 wrapper) is sufficient and matches the simpler style used
  earlier in the same job for `config:import`/`en`/`cache:rebuild`, which
  also don't wrap.
- **No change to `step_795_activity_feed_e2e_fixture.php`.** Confirmed via
  T's `testStep640IsIdempotent` test (which exercises exactly the malformed-
  config-then-second-load fatal that would surface downstream in step_795)
  that once step_640 itself is fixed, `ContentLanguageSettings::
  loadByEntityTypeBundle()` loads cleanly on every subsequent call — there is
  no residual defect for step_795 to defend against. Per the survey/brief's
  own framing ("step_795 has no intrinsic bug... add a defensive preflight
  only if F identifies one is warranted"), no concrete need surfaced, so no
  change was made. Adding a speculative preflight against a defect that no
  longer exists would be unjustified surface area.
- **Did not reformat step_640.php to Drupal coding-standard indentation.**
  `phpcs` (bare invocation, no ruleset pinned in this repo) reports 53
  errors/3 warnings against my fixed file — but the *original* unmodified
  main file already reported an equivalent violation set (23 errors, same
  categories: 2-space indent vs 4-space, bare `TRUE`/`FALSE`, missing
  docblock tags), and the untouched sibling `step_795_...php` reports 104
  fixable violations of the identical kind. This is pre-existing,
  whole-directory tolerance for `docs/groups/scripts/` (2-space indentation
  is this directory's established convention), not something introduced by
  my change or a standard this file was ever held to. Reformatting the whole
  file would be an unplanned drive-by unrelated to the fix and would balloon
  the diff. Self-check item 2 ("clean, or matches existing tolerance")
  is satisfied under the "matches existing tolerance" branch — verified by
  direct comparison, not assumed.

## Reuse / extend-vs-new

Extended `docs/groups/scripts/step_640.php` (the brief's Reuse map: "Extend
— direct target of the fix cascade") and extended `.github/workflows/test.yml`'s
seed sequence ("Extend — insert step_640 invocation"). No new script, no new
helper module, no shared helper function introduced — single call site, so
inlining in step_640 (as the survey/A required) is what was done. Did not
touch `step_795_...php` (survey: "Extend if needed" — no concrete need
found, so left as-is, which the survey/A both anticipated as the likely
outcome).

## Architecture notes for A

- No new dependencies, no schema changes. `step_640.php` now uses
  `\Drupal::service('module_installer')` and
  `Drupal\language\Entity\ContentLanguageSettings` — both already
  core-provided; `content_translation` and `language` are core modules this
  codebase already depends on (assemble-config.sh already lists `language`
  as a hard dependency of `do_group_language`).
  `Drupal\language\Entity\ConfigurableLanguage::createFromLangcode()` is
  core API, no new use.
- CI change is additive only — a new step in the `e2e` job's existing
  sequence, no changes to job triggers, matrix, or other jobs (`kernel`,
  `functional` untouched).
- No shared/other-agent-owned code touched. `step_640.php` and the `e2e` job
  in `test.yml` are both named as this story's direct targets in the brief.

## Deviations from spec / wireframe

None. No wireframe applies (backend seed script, no UI surface). Followed
A's correction on the CI insertion point (brief said "step_620c", which
doesn't exist; used A's identified line 523/525 boundary instead — A's
handoff explicitly authorized this resolution: "F should insert it there...
F can resolve during implementation").

## Tier 1 self-check (incl. tests now GREEN)

1. `bash scripts/ci/assemble-config.sh` (via `ddev exec`, local `php` not on
   PATH outside DDEV, matching T's noted environment constraint) — exit 0:
   ```
   ==> assemble-config: repo root = /var/www/html
   ==> config: copied 138 file(s), excluded 7 env-specific file(s)
   ==> modules: copied 15 custom module(s) into web/modules/custom/
   ==> core.extension: registered custom do_* modules + flag/geofield/language/message/message_notify as enabled
   ==> assemble-config: done
   ```
2. `php vendor/bin/phpcs docs/groups/scripts/step_640.php` — reports 53
   errors/3 warnings, but matches this directory's pre-existing tolerance
   (see "Design decisions" above; original main file: 23 errors same
   categories; untouched sibling step_795: 104 fixable violations). No
   project `phpcs.xml` ruleset pins a standard for `docs/groups/scripts/`.
3. `php vendor/bin/phpunit -c web/core/phpunit.xml.dist
   web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php
   --testdox`:
   ```
   ......                                                              6 / 6 (100%)
   Seed Step640 (Drupal\Tests\do_group_language\Kernel\SeedStep640)
    ✔ Step 640 backfills locked languages and completes without fatal
    ✔ Step 640 adds all fourteen custom languages
    ✔ Step 640 configures language negotiation
    ✔ Step 640 installs content translation module
    ✔ Step 640 content language settings are well formed per bundle
    ✔ Step 640 is idempotent
   OK (6 tests, 134 assertions)
   ```
   All 6 GREEN (3 were RED pre-fix per T's handoff-T-red.md; 3 were already
   passing and remain so — no regression).
4. Also ran the full `do_group_language` Kernel directory (both test classes)
   as a wider regression check: `OK (12 tests, 271 assertions)` — no
   regression in the pre-existing `GroupLanguageNegotiationTest`.
5. `.github/workflows/test.yml` re-parsed via
   `Symfony\Component\Yaml\Yaml::parseFile()` after edit — parses cleanly,
   3 jobs, new step confirmed present in the `e2e` job with the expected
   `run:` block.

## Tests that look wrong (for T)

None. All 6 authored tests are correct as written — implemented against them
without modification.

## Known issues

None. All acceptance criteria addressed:
- `language`/`content_translation` install guards: done.
- `und`/`zxx` backfill: done, test-confirmed.
- 14 custom languages added idempotently: done (pre-existing behavior,
  regression-guarded).
- Content translation via `ContentLanguageSettings` entity API: done,
  test-confirmed.
- step_795 preflight: no concrete need identified (see Design decisions) —
  no change made, consistent with survey's own expectation.
- CI wiring: done, in the `e2e` job between the correct steps (per A's
  correction).
- No 4th cascade layer surfaced. No file-count trigger hit (2 production
  files changed, well under the >4 advisory-hold threshold).

## Files changed

- `docs/groups/scripts/step_640.php` (modified — 3-layer fix ported)
- `.github/workflows/test.yml` (modified — new `e2e`-job step wiring
  step_640 into the seed sequence)
