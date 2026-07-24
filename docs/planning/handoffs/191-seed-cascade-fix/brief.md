# Brief — #191 seed pipeline: step_640 + step_795 language + content-language-settings cascade bugs

**Branch:** `191-seed-cascade-fix` (worktree at
`C:/Users/aange/Projects/_worktrees/groups-seed-cascade-191`)
**Base:** `d014e29` (origin/main)
**Review rigor:** `none` (POC lean pipeline)
**Pipeline:** O → A → T(RED) → F → T(GREEN) → S → PR → CI-green → self-merge
(no D, no U, no A-dup, no brief-gate)

## Objective

Make the seed-pipeline scripts `step_640.php` and `step_795_activity_feed_e2e_fixture.php`
defensive and idempotent end-to-end, so a fresh CI install completes without
the three-layer cascade documented in
`docs/planning/handoffs/139-multilang-rtl/park-note-r2.md`. Unblock #139
(MC-4 multilingual) for later re-close-out.

## Background — the three surfaced layers

Detailed diagnosis is in park-note-r2.md; summary:

1. **Layer 1 (step_640:1-19):** `ConfigurableLanguage::save()` fatals
   `setWeight() on null` if the `language` module isn't enabled first. A naive
   `moduleExists()` guard is a no-op in CI because `language` is already listed
   in the assembled `core.extension.yml`.
2. **Layer 2 (step_640:1-19):** The `und` / `zxx` locked-language entities that
   `language/config/install/language.entity.*.yml` ships are never installed
   when `drush config:import` sees `language` already active — so `save()`
   still fatals on the missing entities.
3. **Layer 3 (step_640:39-46):** Writing bare
   `third_party_settings.content_translation.enabled=TRUE` on
   `language.content_settings.node.$type` produces a config record without
   `target_entity_type_id` / `target_bundle`. Any later entity load fatals
   `Attempt to create content language settings without a target_entity_type_id`
   (surfaced in step_795 when saving a group_content_message on a translatable
   bundle).

## Reuse & analogous-feature map

- **Analogous, established fix pattern:** `.github/workflows/test.yml:488-509`,
  the `do_activity_feed` `pmu` + `en` workaround for the same class of
  config-import-skipped-installDefaultConfig bug. F should reference (not
  copy — the shape here is different: entity-level backfill + entity API, not
  module reinstall) so the mental model matches.
- **Existing fix attempts on `139-multilang-rtl`:** three commits `8d535ab`,
  `1ba0eab`, `838ab6f` already contain the diff shape. **Default: port those
  fixes, then harden with the smoke test T authors.** F may amend if T's RED
  test surfaces gaps.
- **Extend, don't create new:** step_640.php + step_795_activity_feed_e2e_fixture.php
  are the objects to modify. Do NOT introduce a new step_ script or helper
  module. If a shared helper is genuinely warranted (e.g. a
  `_do_ensure_locked_languages()` reused elsewhere), justify in writing —
  otherwise inline in step_640.

## Acceptance criteria

- [ ] `step_640.php` completes without fatal on a fresh install where the
      assembled `core.extension.yml` already lists `language` (the CI
      scenario). Specifically:
    - [ ] `language` module is installed if absent (defensive; idempotent)
    - [ ] `content_translation` module is installed if absent (defensive)
    - [ ] `und` and `zxx` locked-language entities are backfilled if missing
    - [ ] The 14 custom languages are added idempotently
    - [ ] Content translation for `forum`, `documentation`, `event`, `post`,
          `page` is enabled via
          `ContentLanguageSettings::loadByEntityTypeBundle()` +
          `setThirdPartySetting()` — NOT via bare config writes
- [ ] `step_795_activity_feed_e2e_fixture.php` continues to succeed after
      step_640 has run (its Message saves no longer trip the malformed
      `language.content_settings.node.*` config). Add a defensive
      early-return / preflight if F identifies one is warranted.
- [ ] Smoke test authored by T (Kernel or Functional — T decides) that:
    - [ ] Simulates the failure precondition (language module already
          enabled but locked entities missing / bare config written) OR
          simply runs the step_640 script end-to-end against a fresh
          test site
    - [ ] Would have failed on `main` before this fix (RED verified)
    - [ ] Passes after fix (GREEN)
- [ ] CI wires step_640 into the E2E seed pipeline (between step_620c and
      step_700) so future regressions surface here, not in #139's next
      close-out.
- [ ] Fresh CI E2E job (`npx playwright test`) completes without cascade
      fatals through the full seed sequence.

## Handoff locations

- `docs/planning/handoffs/191-seed-cascade-fix/decisions.md` (append-only)
- `docs/planning/handoffs/191-seed-cascade-fix/handoff-A-plan.md`
- `docs/planning/handoffs/191-seed-cascade-fix/handoff-T-red.md`
- `docs/planning/handoffs/191-seed-cascade-fix/handoff-F.md`
- `docs/planning/handoffs/191-seed-cascade-fix/handoff-T-green.md`
- `docs/planning/handoffs/191-seed-cascade-fix/handoff-S.md`

## Constraints

- **Advisory-hold trigger:** if a 4th cascade layer surfaces after fixes
  1-3, OR the fix touches >4 files, PAUSE and report to O — do not push
  through another cascade.
- **DDEV project name (if used):** `gm191-seed`.
- Namespace containers `gm191-*` to isolate from concurrent stories.
- Never touch `web/modules/custom/` or `config/sync/` directly — they're
  gitignored build artifacts; work in `docs/groups/scripts/` only.

## Files in scope (expected)

- `docs/groups/scripts/step_640.php` (primary)
- `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` (secondary,
  possibly no change)
- `.github/workflows/test.yml` (wire step_640 into seed sequence)
- `web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php`
  or similar (new test — T decides exact path/scope)

Anything outside those requires justification.
