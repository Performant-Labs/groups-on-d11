# Decision journal — #191 seed pipeline cascade fix

## O — Phase 1 (survey + brief)

- **Decided:** Port the three defensive fixes already authored on `139-multilang-rtl`
  (`8d535ab`, `1ba0eab`, `838ab6f`) to `main` as a standalone story, plus add a
  regression smoke test. This is safer than continuing to iterate on #162 which
  is now conflict-blocked by six commits of drift.
- **Decided:** Scope is `docs/groups/scripts/step_640.php` +
  `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php` + a new test.
  Also add a CI hook that runs step_640 in the seed pipeline so future
  regressions surface here, not in the next MC-4 close-out attempt.
- **Assumed:** #139's three fixes are correct in *shape* (locked-language backfill,
  ContentLanguageSettings entity API, defensive module install) — this story
  hardens/idempotencifies them and adds test coverage, but does not re-architect.
- **Assumed:** No 4th cascade layer will surface once step_640 runs cleanly. If it
  does, per issue #191's advisory-hold policy the run pauses to report.
- **Evidence:** park-note-r2.md, three fix commits on origin/139-multilang-rtl,
  `.github/workflows/test.yml:488-497` (established do_activity_feed workaround).
- **Hedged:** step_795 currently doesn't exhibit its own bug — it only exposed
  step_640's malformed config. Fix may be step_640-only + a defensive load-check
  early in step_795. F to decide during implementation.

## A — Phase 3 (up-front plan review)

- **Verdict:** PASS. Plan extends the correct object (step_640), uses entity API
  where a config write was wrong, does not create parallel paths, and matches
  the established do_activity_feed workaround mental model.
- **Correction (non-blocking):** brief says "insert between step_620c and step_700"
  but no `step_620c` exists in `.github/workflows/test.yml` on main. Correct
  insertion point is between line 523 (`drush status` diagnostic, end of the
  config:import+en step) and line 525 (start of "Seed full demo data"), as a
  new dedicated step. F to resolve during implementation.
- **Recommendation to T:** Kernel test at
  `web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php`.
  Rationale in handoff-A-plan.md §4.
- **Latent risks flagged (not in scope):** raw config write to `language.types`
  (line 22-36); step_700 nodes on translatable bundles without explicit
  `langcode`. F to glance at step_700 but not fix.
- **Report:** docs/planning/handoffs/191-seed-cascade-fix/handoff-A-plan.md

## T — Phase 4 (RED)

- **Decided:** Author a Kernel test at
  `docs/groups/modules/do_group_language/tests/src/Kernel/SeedStep640Test.php` per A's
  recommendation — a lightweight `KernelTestBase` (not `GroupsKernelTestBase`; step_640 has
  nothing to do with groups), mirroring `ActivityFeedViewInstallTest`'s established precedent of
  using a real module-enable path to reproduce a config-install-order gap.
- **Decided:** Simulate the CI cascade precondition by declaring `language` +
  `content_translation` in `static::$modules` (real enable, matching "already listed in
  core.extension.yml"), then explicitly deleting the `und`/`zxx` locked-language entities that
  `installConfig()` seeded — reproducing the exact gap `drush config:import` leaves for a module
  already active. Confirmed against ContentLanguageSettings's own source
  (`web/core/modules/language/src/Entity/ContentLanguageSettings.php:108`) and the three reference
  fix commits on `origin/139-multilang-rtl` (`8d535ab`, `1ba0eab`, `838ab6f`).
- **Decided:** `runStep640()` `require`s the real `docs/groups/scripts/step_640.php` (not a
  reimplementation), wrapped in a local `ob_start()`/`ob_end_clean()` to suppress its progress
  `echo`es under PHPUnit's `beStrictAboutOutputDuringTests`.
- **Assumed:** Five real `NodeType::create()` bundles (forum/documentation/event/post/page) in
  `setUp()` are cheap enough at Kernel tier to avoid the "downgrade to config-loads-without-
  exception" fallback the task description offered — no justification needed for that downgrade.
- **Hedged:** A anticipated the Layer-1/2 `setWeight()` on `null` fatal would reproduce directly.
  It does not in this exact Kernel fixture (Drupal 11's
  `updateLockedLanguageWeights()` tolerates the missing locked entity silently in this call path)
  — but the underlying ACs ("und/zxx backfilled", "idempotent re-run") still fail on `main`,
  so RED remains valid for the right reason. See handoff-T-red.md "Deviation" section.
- **Evidence:** RED run — 3/6 tests fail (Layers 2 + 3 of the diagnosed cascade), 3/6 already
  pass (unaffected ACs, retained as regression guards). Full output in handoff-T-red.md.
- **Note (process):** Local worktree required `ddev start` (DDEV project name in `.ddev/config.yaml`
  was stale — copied from another worktree as `gm145-wcag`; corrected to `gm191-seed` per brief),
  `ddev composer install`, and `ddev exec bash scripts/ci/assemble-config.sh` (re-run after every
  test-file edit — the assembled `web/modules/custom` copy is NOT auto-synced) before PHPUnit could
  run. `SIMPLETEST_DB="mysql://db:db@db:3306/db"` is DDEV's default DB connection string, needed
  since `web/core/phpunit.xml.dist` requires it for Kernel tests.

## F — Phase 5 (implement against RED)

- **Decided:** Port the reference implementation from `origin/139-multilang-rtl`
  verbatim (final squashed state of `8d535ab` + `1ba0eab` + `838ab6f`) into
  `docs/groups/scripts/step_640.php`, including the `createFromLangcode()`
  swap for the 14-custom-language loop (present in the reference diff, not
  separately called out in the brief's 3-layer list, but load-bearing for
  RTL/Arabic direction — kept as part of the tested-good reference, no
  separate justification needed since it ships in the reference commit).
- **Decided:** No change to `step_795_activity_feed_e2e_fixture.php`.
  `testStep640IsIdempotent` (T's test) exercises exactly the malformed-
  config-then-reload fatal step_795 would hit downstream; with step_640
  fixed, that path no longer fatals — confirmed empirically, not assumed.
  No concrete defensive-preflight need surfaced, consistent with the
  survey's own expectation that step_795 has no intrinsic bug.
- **Decided:** Wire step_640 into `.github/workflows/test.yml`'s `e2e` job as
  a new dedicated step between the "Install Drupal + import assembled
  config" step (ends line 523) and "Seed full demo data" (was line 525) —
  per A's correction of the brief's aspirational "step_620c" wording (no
  such step exists on main). Plain `php:script` invocation, no uid-1
  wrapper (step_640 creates no content, unlike step_700/720/780/790/7xx/795
  which do and therefore need the uid-1 wrapper for presave-hook
  correctness).
- **Assumed:** The reference commits' diff shape needed no amendment — T's
  RED run and my own GREEN re-run confirm all 6 tests pass against the
  verbatim port with zero deviation.
- **Hedged:** `phpcs` reports 53 errors/3 warnings on the fixed
  `step_640.php` (bare invocation, no project ruleset pins a standard for
  `docs/groups/scripts/`). Verified this is pre-existing, whole-directory
  tolerance by direct comparison: the original unmodified main file already
  reported 23 errors of the identical categories (2-space indent, bare
  TRUE/FALSE, missing docblock tags), and the untouched sibling
  `step_795_activity_feed_e2e_fixture.php` reports 104 fixable violations of
  the same kind. Not a regression; reformatting the whole file to 4-space
  Drupal-standard indentation would be an unplanned drive-by unrelated to
  the fix and was not done.
- **Evidence:** `php vendor/bin/phpunit -c web/core/phpunit.xml.dist
  web/modules/custom/do_group_language/tests/src/Kernel/SeedStep640Test.php
  --testdox` → `OK (6 tests, 134 assertions)`, all 6 GREEN (3 were RED
  pre-fix per handoff-T-red.md). Full `do_group_language` Kernel directory
  (both test classes) also GREEN: `OK (12 tests, 271 assertions)` — no
  regression in the pre-existing `GroupLanguageNegotiationTest`.
  `bash scripts/ci/assemble-config.sh` exits 0. `.github/workflows/test.yml`
  re-parses cleanly via `Symfony\Component\Yaml\Yaml::parseFile()` after
  edit, new step confirmed present in the `e2e` job.
- **Report:** docs/planning/handoffs/191-seed-cascade-fix/handoff-F.md

## T — Phase 6 (GREEN)

- **Decided:** Re-ran the authored 6-test `SeedStep640Test` suite against F's implementation —
  all 6 GREEN (`OK (6 tests, 134 assertions)`), including the 3 that were RED against `main`
  (backfill, well-formed per-bundle settings, idempotent re-run). Spot-checked against the RED
  run's exact assertion failures to confirm the tests still pin behavior, not implementation.
- **Decided:** Full `do_group_language` Kernel directory (12 tests, both test classes) GREEN —
  no regression in the pre-existing `GroupLanguageNegotiationTest`.
- **Decided:** Substituted the brief's full 15-directory aggregate custom-module Kernel run with
  two targeted, completed runs after confirming the aggregate has a pre-existing environment
  resource ceiling in this DDEV/worktree setup (observed OOM/timeout kill at ~70% completion in
  both a live attempt today and an unrelated prior session's log,
  `scratchpad/full_kernel3.log`, predating this story). Ran (1) `do_group_language` alone — GREEN,
  and (2) `do_activity_feed` + `do_group_extras` + `do_streams` together (the modules most
  plausibly coupled to a language/content_translation module-state change) — `OK, but there were
  issues!` with 109 tests / 3184 assertions, **zero failures, zero errors** (issues = pre-existing
  deprecation notices, geofield `@ViewsArgument` + `ViewsConfigUpdater`, unrelated to step_640).
- **Decided:** phpcs on `step_640.php` (53 errors/3 warnings) verified against `origin/main`'s
  unmodified file (23 errors) — identical violation categories (indentation, bare TRUE/FALSE,
  multi-line call formatting), no new category. Matches F's self-check; not a regression.
- **Decided:** YAML re-parsed independently, new step confirmed present in `e2e` job at the
  correct insertion point (post-A-correction, not the brief's nonexistent "step_620c").
- **Assumed:** The two-module-subset substitution for the full aggregate gives equivalent
  regression signal to the brief's implied "run everything" check, since the substituted set
  targets exactly the modules that share config/entity state with `language`/`content_translation`
  (the only shared surface this change touches).
- **Evidence:** This handoff, `handoff-T-green.md`, full command output inline.
- **Report:** docs/planning/handoffs/191-seed-cascade-fix/handoff-T-green.md

## S — Phase 7 (spec audit)

- **Verdict:** PASS. All #191 acceptance criteria are backed by shipped code and passing tests
  (see AC-by-AC coverage table in handoff-S.md). Ported diff matches the reference implementation
  on `origin/139-multilang-rtl` verbatim (three fix commits `8d535ab` + `1ba0eab` + `838ab6f`,
  plus the `createFromLangcode()` refinement that shipped with them). Scope stayed tight — 2
  production files + 1 new Kernel test. No 4th cascade layer, no advisory-hold trigger fired.
  Zero regression across the coupled-module Kernel subset (109 tests, zero failures/errors).
- **Advisory (non-blocking):** branch is 2 commits behind `origin/main`; fast-forwardable. Rebase
  before PR. Latent risks A flagged (raw config write to `language.types`; step_700 langcode
  handling) remain out of scope — worth a future audit story if E2E surfaces either.
- **Report:** docs/planning/handoffs/191-seed-cascade-fix/handoff-S.md
