# Handoff-A: Phase 3 — #191 seed cascade fix (up-front plan review)

**Date:** 2026-07-24
**Branch:** 191-seed-cascade-fix
**Brief:** docs/planning/handoffs/191-seed-cascade-fix/brief.md
**Reuse map:** docs/planning/handoffs/191-seed-cascade-fix/survey.md
**Wireframe:** N/A (backend seed script)
**Verdict:** PASS

## Summary

Plan is sound and consistent with established seed-script patterns. Porting the three
`origin/139-multilang-rtl` commits verbatim onto `main`'s step_640, adding a smoke test,
and wiring step_640 into CI is the right shape — it extends the analogous object, uses
the entity API where a config write was wrong, and matches the reasoning already codified
by the do_activity_feed pmu/en workaround at test.yml:502-511. One CI-wiring
correction and one test-level recommendation below; neither blocks.

## Answers to O's questions

1. **Plan sound?** Yes. The three fix commits address each cascade layer at the right
   abstraction (module install; entity backfill; entity API instead of raw config).
   Idempotency guards are already present in the diffs. No new debt introduced.
2. **Reuse map correct?** Yes — extend step_640; no shared helper (single call site);
   new Kernel test (no analogous test exists); step_795 change only if F sees a
   concrete need. Agree with the survey's judgement that step_795 has no intrinsic
   bug and a defensive preflight is optional.
3. **CI wiring — right level?** Yes, E2E job is correct (step_640 is a runtime
   seed script, not testable at unit level). **However**: the brief says "between
   step_620c and step_700" but grep shows no `step_620c` in `.github/workflows/test.yml`
   on main — only the do_activity_feed workaround (line 502-511) then the demo seed
   sequence starting at line 543. The correct insertion point is a **new step
   between the existing "config:import + module enable" step (ends line 523) and
   the "Seed full demo data" step (starts line 525)**. F should insert it there,
   after `cache:rebuild` and before demo seeds so languages/content-translation
   exist before step_700 creates any translatable nodes. Non-blocking; F can
   resolve during implementation, but O should note.
4. **Test level recommendation:** **Kernel test** in
   `web/modules/custom/do_group_language/tests/src/Kernel/`. Rationale:
   (a) the failure mode is entity-API level (`ContentLanguageSettings` malformed
   config, locked-language entities missing) — Kernel gives real entity storage
   without Functional's install-profile cost; (b) F can `require` the actual
   `step_640.php` from the Kernel test to exercise the real code path; (c) the
   RED test needs to simulate "language module already listed in
   core.extension.yml but locked entities missing" — a Kernel `setUp()` can install
   `language` then delete `und`/`zxx` to reproduce cheaply. Functional adds cost
   without payoff here; the CI E2E job is regression coverage but too slow/coarse
   for RED/GREEN iteration.
5. **Fourth-layer risk:** Two candidates worth flagging (not blocking):
   (a) `language.negotiation` — step_640 writes to `language.types` (lines 22-36)
   with a raw config write; if the schema tightens or a later Drupal patch requires
   entity API for negotiation weights the same class of bug recurs. Low probability.
   (b) step_700_demo_data.php creates nodes on translatable bundles AFTER step_640
   enables translation — if any of those nodes are created without an explicit
   `langcode`, entity save could fatal on missing default language on a fresh
   install where the site default hasn't been set. Worth F glancing at
   step_700 but not in scope to fix here.
6. **Anti-duplication forward look:** No parallel paths. The survey correctly
   rejects a shared helper (single call site). The plan explicitly references
   (does not copy) the do_activity_feed workaround pattern.

## Notes for O / F

- CI wiring insertion point is between line 523 and line 525 of test.yml, NOT
  "between step_620c and step_700". Brief wording is aspirational — no step_620c
  exists in the workflow.
- Recommend Kernel test level. Path suggestion:
  `web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php`.
- Advisory-hold trigger (4th layer / >4 files) remains in force per brief.

## Patterns referenced

- `.github/workflows/test.yml:488-523` (do_activity_feed pmu/en workaround — same
  mental model, different mechanism)
- `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` (established
  idempotency + `loadByProperties` cadence for seed scripts)
- Three fix commits `8d535ab`, `1ba0eab`, `838ab6f` (reference implementation)
- `docs/groups/scripts/step_640.php` (current broken state on main)
