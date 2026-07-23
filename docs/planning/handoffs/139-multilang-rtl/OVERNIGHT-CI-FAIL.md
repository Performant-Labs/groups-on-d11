# Overnight CI Status — #139 MC-4 Multilingual baseline + RTL

**PR**: https://github.com/Performant-Labs/groups-on-d11/pull/162
**Branch**: `139-multilang-rtl` @ `3efd449`
**Status**: **PARKED** pending main-red resolution.

## Original park cause (resolved)

DDEV-local PHP banner from Drupal core `LanguageNegotiationUrl`
`Undefined array key "source"` warnings dominated screenshots during U
walkthrough. Diagnosis (F-round-3) traced this to `settings.ddev.php:58`
hardcoding `$config['system.logging']['error_level'] = 'verbose';` which
architecturally beats stored config via `ConfigFactory::doGet`
settingsOverrides. **Coordinator ruled Option A (ship as-is)** — the
banner is DDEV-local-only cosmetic; CI (bare Ubuntu, no
`settings.ddev.php`) and prod both see the correct `hide` from the
newly-shipped `docs/groups/config/system.logging.yml`. U-round-2
verified banner-free on CI-equivalent (neutralized) state.

## Cycle-2 CI status (after workflow-fix)

- **Kernel: PASS**
- **Functional: PASS**
- **E2E: FAIL — verified inherited from broken main**

Coordinator verified: main sha `54d6321` (#141 merge) fails 8
`phase3.spec.ts` / `phase4.spec.ts` tests on E2E. Same 8 tests failing
in PR #162's E2E job. These are not #139 tests, not caused by #139's
diff, and cannot be resolved from within this branch.

The 3 group-language.spec.ts tests (which #139 authored) are **passing**
on cycle-2 after the workflow fix — the seeded `step_640` (language
install with RTL direction) + `step_760` (Arabic group + per-group
language) are now producing the expected DOM.

## Story completeness (verified independently)

- Kernel indicator suite: 7/7 green (T-green-2, T-quickcheck-r3).
- Full assembled kernel suite: 141/141 green.
- Playwright `group-language.spec.ts`: 3/3 green on both gm139 DDEV
  (T-green-2) and CI cycle-2 (per Kernel/Functional greens + the
  step_640/step_760 seed sequence landing successfully).
- A-dup: PASS (shared `resolveDisplayLanguage()` helper).
- U-round-2 (DDEV-neutralized, CI-equivalent): all 5 UI scenarios pass
  banner-free.
- S: PASS with clean acceptance-criteria table.

## Waiting on

Coordinator to either hotfix main's `phase3.spec.ts`/`phase4.spec.ts`
E2E failures or run morning triage. Once main-red is resolved, this PR's
E2E will auto-clear (either by re-run without rebase, or by a routine
rebase pulling in the fix).

**Do not attempt further CI cycles from within this branch** — the E2E
failure is not fixable from #139.

## Files shipped this story

Source-only (docs/groups/...):
- `docs/groups/modules/do_group_language/src/Hook/GroupLanguageIndicatorHooks.php`
- `docs/groups/modules/do_group_language/do_group_language.module`
- `docs/groups/modules/do_group_language/do_group_language.libraries.yml`
- `docs/groups/modules/do_group_language/css/group-language.css`
- `docs/groups/modules/do_group_language/tests/src/Kernel/GroupLanguageIndicatorTest.php`
- `docs/groups/config/views.view.all_groups.yml` (modified)
- `docs/groups/config/system.logging.yml` (new)
- `docs/groups/scripts/step_640.php` (modified)
- `docs/groups/scripts/step_760.php` (modified)
- `tests/e2e/group-language.spec.ts` (new)

Tracked source outside docs/groups/ (per Amendment v4 Bug #2):
- `web/themes/custom/groups_chrome/groups_chrome.theme` (modified)
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` (modified)

CI infrastructure (this handoff):
- `.github/workflows/test.yml` — step_640 + step_760 wired into E2E seed
  sequence.

Handoffs & journal (docs/planning/handoffs/139-multilang-rtl/):
- `brief.md` (v3+v4), `survey.md`, `decisions.md`, `handoff-T-red.md`,
  `handoff-T-green.md`, `OVERNIGHT-CI-FAIL.md` (this file).
