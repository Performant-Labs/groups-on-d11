# Handoff-S: Phase 7 — #191 seed pipeline cascade fix (spec audit)

**Date:** 2026-07-24
**Branch:** 191-seed-cascade-fix (staged changes on top of `d014e29`)
**Issue:** #191
**Handoff-F reviewed:** `docs/planning/handoffs/191-seed-cascade-fix/handoff-F.md`
**Handoff-A reviewed:** `docs/planning/handoffs/191-seed-cascade-fix/handoff-A-plan.md`
**Handoff-T-red:** `docs/planning/handoffs/191-seed-cascade-fix/handoff-T-red.md`
**Handoff-T-green:** `docs/planning/handoffs/191-seed-cascade-fix/handoff-T-green.md`

## A precondition

PASS — A returned PASS with one non-blocking CI-insertion-point correction, which F applied.

## T precondition

PASS — T-green reports zero blocking issues (6/6 GREEN, 12/12 in the full `do_group_language`
Kernel dir, 109 tests across three coupled modules with zero failures/errors).

## AC-by-AC coverage (from issue #191)

| Acceptance criterion (from issue body) | Status | Evidence |
|---|---|---|
| Layer 1 fix — `language` module install guard before `ConfigurableLanguage::save()` | PASS | step_640.php:11-13 (`moduleExists('language')` → `install(['language'])`). Backing: `testStep640BackfillsLockedLanguagesAndCompletesWithoutFatal` (step completes without fatal). |
| Layer 2 fix — `und`/`zxx` locked-language entity backfill when `drush config:import` skipped `installDefaultConfig()` | PASS | step_640.php:29-40 (foreach `["und","zxx"]` with idempotent `$storage->load()` guard, direction/locked/weight per core defaults). Backing: `testStep640BackfillsLockedLanguagesAndCompletesWithoutFatal` (RED on main, GREEN post-fix). |
| Layer 3 fix — `ContentLanguageSettings::loadByEntityTypeBundle()` + `setThirdPartySetting()` in place of bare config write | PASS | step_640.php:96-98 (entity API replaces `configFactory()->getEditable(...)->set(...)->save()`). Backing: `testStep640ContentLanguageSettingsAreWellFormedPerBundle` (asserts `target_entity_type_id`/`target_bundle` populated). |
| `content_translation` module install guard | PASS | step_640.php:16-18. Backing: `testStep640InstallsContentTranslationModule`. |
| 14 custom languages added idempotently, `createFromLangcode()` (not `$storage->create(['id'=>...])`) so RTL direction is set correctly | PASS | step_640.php:52 uses `ConfigurableLanguage::createFromLangcode($langcode)->save()` inside idempotent guard. Backing: `testStep640AddsAllFourteenCustomLanguages` + `testStep640IsIdempotent`. |
| `step_795_activity_feed_e2e_fixture.php` continues to succeed after step_640 fix | PASS (no change to step_795 needed) | F's `testStep640IsIdempotent` reproduces and confirms the exact malformed-config-then-reload fatal step_795 hit downstream is gone once step_640 is correct. Consistent with survey's own expectation ("step_795 has no intrinsic bug"). |
| Smoke test authored by T, RED on main, GREEN after fix | PASS | `SeedStep640Test.php` (6 tests), handoff-T-red.md documents 3/6 RED on main for the right reason, handoff-T-green.md documents 6/6 GREEN post-fix. |
| CI wires step_640 into E2E seed pipeline (before demo-content steps) | PASS | `.github/workflows/test.yml:525-544` new step "Seed languages + content translation (step_640)" between config-import step and "Seed full demo data" — matches A's identified insertion point. |
| No 4th cascade layer surfaced; ≤4 production files changed | PASS | 2 production files touched (`step_640.php`, `test.yml`) + 1 new test file. Under advisory-hold thresholds. |
| Fresh CI E2E job completes without cascade fatals | DEFERRED to CI post-PR | Playwright/E2E runs post-PR per pipeline conventions; RED→GREEN + Kernel regression sweep is the local proxy. |

## Findings

**Fix correctness (diff shape vs reference).** The staged step_640.php diff matches the union of
the three reference commits on `origin/139-multilang-rtl` (`8d535ab` module-install guard,
`1ba0eab` locked-language backfill, `838ab6f` ContentLanguageSettings entity API) plus the
`createFromLangcode()` swap that shipped in the same reference tree — verified by direct read of
both diffs. No re-architecture; verbatim port as the brief mandated.

**Scope integrity.** Changes limited to `docs/groups/scripts/step_640.php`,
`.github/workflows/test.yml`, and one new test file
(`docs/groups/modules/do_group_language/tests/src/Kernel/SeedStep640Test.php` mirrored to
`web/modules/custom/...` via `assemble-config.sh`). No drive-by touches to unrelated seed scripts,
no re-arch of step_795, no phpcs reformat. Matches brief and A's plan.

**CI wiring.** New step is placed correctly (between `drush status` diagnostic ending the
config-import step and the start of "Seed full demo data"), uses the same
`php vendor/drush/drush/drush.php php:script "$PWD/..."` absolute-path idiom as the neighbouring
demo-seed step, and correctly omits the uid-1 wrapper (F's rationale: step_640 creates no
content, so no presave hooks are user-sensitive — consistent with the workflow's other pre-demo
`drush` invocations).

**Regression risk.** Zero identified. `do_group_language`'s pre-existing
`GroupLanguageNegotiationTest` (12/12 in dir) plus the coupled-modules subset run
(`do_activity_feed` + `do_group_extras` + `do_streams`: 109 tests, 3184 assertions, zero
failures/errors) — all pre-existing "issues" are deprecation notices in geofield /
ViewsConfigUpdater unrelated to this change.

**Test quality (rubric per `testing/test-quality.md` §7).** 6 tests, each names one distinct
behavior (backfill / 14-language add / negotiation config / module install / well-formed
per-bundle settings / idempotency). Each fails in isolation for the right reason (T-red confirms
3/6 fail on main with correct assertion messages). Tests assert on persisted config/entity state
and thrown exceptions — behavior, not implementation. Suite is proportionate: 6 tests for a
3-layer cascade + CI wiring is not excessive. No "delete or merge this" findings.

**A/T conflicts.** None. Quality findings are consistent with A's PASS verdict.

**phpcs.** 53 errors / 3 warnings on the fixed file, but F/T both verified against
`origin/main`'s unmodified `step_640.php` (23 errors, identical categories) — pre-existing
whole-directory tolerance for `docs/groups/scripts/`, no new violation category introduced.
Reformat would be an unplanned drive-by per repo convention. Accepted.

**Advisory-hold triggers.** None fired. 2 production files (< 4), no 4th cascade layer, no
scope creep, spec/preview not defective (spec = issue #191 body, which correctly describes the
three diagnosed layers).

**Documentation.** No RUNBOOK update needed — this is a bugfix restoring intended behavior of an
existing script, not a new feature. Handoff chain (brief/survey/A/T-red/F/T-green/S) is the
record.

## Advisory notes (non-blocking)

1. **Full 15-directory aggregate Kernel run has a pre-existing environment resource ceiling** in
   this DDEV/worktree setup (T observed OOM/timeout kill at ~70% in two independent sessions,
   one predating this story). T substituted with two targeted runs (121 tests total, zero
   failures). Worth a separate ticket if CI ever needs the aggregate as a single invocation.
   Not a #191 regression.
2. **Latent risks A flagged (not in scope):** raw config write to `language.types` (step_640.php
   lines 22-36) is architecturally similar to Layer 3 and could recur if the schema tightens;
   step_700 may create nodes on translatable bundles without explicit `langcode`. Neither
   surfaced in this cycle; worth a future audit story if E2E reveals either.
3. **Branch is 2 commits behind `origin/main`.** Fast-forwardable per `git status`. O should
   rebase before opening the PR to keep CI clean.

## Verdict

**PASS** — all acceptance criteria in issue #191 are addressed by shipped code + backing tests,
scope is tight (2 production files + 1 test), ported diff faithfully mirrors the reference
implementation, no 4th cascade layer surfaced, no regression in coupled modules. Ready for O to
commit, rebase onto latest `origin/main`, and open the PR.
