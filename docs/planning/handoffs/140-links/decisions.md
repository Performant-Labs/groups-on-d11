# Decisions — #140 MC-1 Links & Resources

Append-only. Every phase adds an entry.

---

## Phase 1 — O (survey + brief)

**Decided**
- Extend `do_group_extras` for any PHP tweaks; do NOT create a new module (Reuse rule; earned complexity).
- Field type = core `link` (native title+URL per delta) with cardinality `-1`.
- Formatter uses core `link` formatter's built-in `rel: noopener` + `target: _blank` settings — no PHP-side rewrite.
- Create the group Full display YAML in this story (none exists) with named section markers + spaced weights (Description=0, About=10 reserved for #141, Links=20).
- Add `link` to `do_group_extras.info.yml` `dependencies` so assemble-config picks it up for CI.

**Assumed**
- The site-installer + assemble-config path enables `link` module transitively via `do_group_extras`'s dependency; confirm in T's GREEN pass.
- Core link formatter's `rel` setting is a comma-separated list; `noopener` is a valid entry that Drupal emits verbatim on external URLs.

**Hedged**
- If the core `link` formatter does NOT emit `rel="noopener"` on external URLs out-of-box (behavior varies by version), fall back to a minimal `preprocess_field__field_group_links` in `do_group_extras.module` (or hook attribute) that appends `rel="noopener noreferrer"` to `<a>` items with an external absolute URL. Decide at T's RED authoring: test the observable outcome (attribute present in rendered HTML) regardless of mechanism.

**Skipped**
- **D (Designer):** No novel UI. The design is "render a `<section><h2>Links & Resources</h2><ul>...</ul></section>` under Description on the group Full page." Convention-bound; visual polish comes from subtheme CSS (basic list). Wireframe adds no signal; skipping per O's judgment latitude. Recorded here per pipeline rules.

**Evidence**
- `docs/planning/handoffs/140-links/survey.md`
- `docs/planning/handoffs/140-links/brief.md`
- `gh issue view 140 --repo Performant-Labs/groups-on-d11` (title "MC-1: Links & Resources field + rendering", owns disjoint files list matches survey)
- Analogue verified: `docs/groups/config/field.{storage,field}.group{,.community_group}.field_group_description.yml`

---

## Phase 3 — A (up-front plan review)

**Verdict:** PASS with 3 warns (encoded as observable-behavior tests for T, not blockers).

**Decided (from A)**
- Extend `do_group_extras` confirmed correct home.
- Core `link` field cardinality -1 confirmed idiomatic.
- No `hook_install` strip needed — Group 4.x contrib does NOT ship `entity_view_display.group.*.default` in `config/optional`. Belt-and-suspenders: F runs `drush cex --diff` after `drush en` to confirm.
- Section-marker comments are cosmetic only (stripped on `drush cex`); use them as source-tree signposts with a header note.
- **H2 source = field's own `label: above` setting** (not a template `<section>` wrapper). Core field-render suppresses the entire wrapper when zero deltas, satisfying empty-state by construction.
- HelpText append is N/A (HelpText.php is tooltip registry, not general ledger).

**T authoring instructions (WARNs)**
- Assert `rel="noopener"` against **observable rendered HTML** on external `<a>`, not formatter config shape. F picks formatter-settings first; add `preprocess_field` fallback only if red.
- Cover empty-state: seeded group with zero links renders NO section header and NO wrapper markup.
- `.info.yml` dep line: `- drupal:link` (match existing style).
- Kernel `$modules`: `link`, `field`, `text`, `user`, `group`, `do_group_extras`.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-A-plan.md`

---

## Phase 4 — T (author tests, RED)

**Decided**
- Kernel test `GroupLinksFieldTest` authored at
  `docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php`, 7 tests covering
  storage, instance, view-display, form-display, external-link rel/target (observable HTML per A's
  warn #5), internal-link rendering, and empty-state suppression (per A's warn #6).
- `rel="noopener"`/`target="_blank"` and empty-state assertions target rendered HTML via
  `EntityTypeManager::getViewBuilder('group')->view()` + `Renderer::renderRoot()`, never formatter
  config shape — F is free to choose formatter settings or a preprocess fallback.
- E2E `tests/e2e/group-links.spec.ts` authored (2 tests) against the `DrupalCon Portland 2026`
  seeded group, found via `/all-groups` directory (not a hardcoded gid).
- Canonical seed titles/URLs picked and recorded in handoff-T-red.md — F must seed exactly these
  6 (2 per group x 3 groups: DrupalCon Portland 2026, Core Committers, Thunder Distribution).
- Added `#[RunTestsInSeparateProcesses]` (current Drupal 11.3+ kernel-test convention, deprecation
  otherwise).

**Assumed**
- DDEV is the correct local stand-in for CI's native-PHP runner; used `ddev exec` for all
  `php`/`composer` invocations since this worktree had no host-PATH `php` and no vendor/ checked
  out yet.
- The empty-state test (`testEmptyStateRendersNothing`) is a valid RED even though it currently
  PASSES (there's no field yet, so "renders nothing" is trivially true) — it is not vacuous
  because the acceptance criterion is about the POST-implementation empty-state behavior, and
  T-green will re-verify it still holds once the field exists and hide-empty-field logic is
  actually exercised.

**Hedged**
- E2E RED was not executed this session (no node_modules, no seeded/running site) — recorded as
  a non-gating gap per task instructions; kernel RED is the gate for this phase.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-T-red.md`
- RED run: `ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c web/core/phpunit.xml.dist --testdox web/modules/custom/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php'` — 6/7 FAIL for the right reason (missing config), 1 passes correctly (empty state, pre-feature).

---

## Phase 5 — F (implement)

**Decided**
- No `preprocess_field` fallback needed for `rel="noopener"`/`target="_blank"` — verified by
  reading core's `LinkFormatter::buildUrl()` (`rel`/`target` settings write verbatim into
  `$options['attributes']`, and `AttributeXss::sanitizeAttributes()` whitelists `rel` from
  protocol filtering) AND empirically via a throwaway diagnostic kernel test — formatter settings
  alone produce the exact required HTML. This resolves A's hedge/warn #5 in favor of the simpler
  mechanism.
- `link_type: 17` confirmed against `LinkItemInterface`'s bitmask constants (`LINK_INTERNAL=0x01
  | LINK_EXTERNAL=0x10 = LINK_GENERIC=0x11=17`), not assumed.
- `field.field_settings.title: 2` (`LinkTitleVisibility::Required`), NOT the task prompt's literal
  `title: 1` — the brief's acceptance criterion and survey.md both say "title required per delta"
  in writing; `1` is `LinkTitleVisibility::Optional`, which would contradict both that criterion
  and the WCAG-AA discernible-name criterion. Flagged explicitly in handoff-F.md for a human
  double-check; one-line revert if `1` was actually intended (no test asserts this value).
- Attached the `group-links` CSS library by extending the EXISTING `preprocessGroup()` method in
  `DoGroupExtrasHooks.php` (same seam that already attaches the archived-group library) rather
  than adding a new hook method — unconditional for `community_group` bundle, not view-mode-scoped
  (no existing precedent for view-mode scoping in this hook).
- Built the new `core.entity_view_display.group.community_group.default.yml` with ALL existing
  group fields represented (description/visibility/image at weights 0/1/2, type/language hidden)
  since no prior Full display existed anywhere to diff against or mirror — a first-principles
  reasonable default per field's own formatter, not a guess at unexported settings.

**Assumed**
- `assemble-config.sh`'s core.extension-patching step does not walk a custom module's `.info.yml`
  `dependencies:` to transitively enable declared deps (confirmed by reading the script) — a
  non-issue for this story since `link` was already enabled in the committed baseline
  `core.extension.yml` (`git show HEAD:config/sync/core.extension.yml` confirms `link: 0`
  pre-existing), but flagged for O/A in case a future story's dependency isn't already enabled at
  baseline.

**Hedged**
- `drush cex --diff` belt-and-suspenders check (A's suggestion) was NOT run — the worktree's site
  is not installed (no full `site:install` this session), and the task marks this check optional.
  Substituted a cheaper, still-meaningful equivalent: a throwaway diagnostic kernel test (built via
  the same `FieldStorageConfig::create()`/`FieldConfig::create()`/`EntityViewDisplay::create()`/
  `EntityFormDisplay::create()` API calls Drupal's real config-import path uses internally) proved
  the exact production settings values round-trip correctly (all `->save()` calls succeeded,
  meaning core's own config validation accepted the shape) and render the required HTML. Deleted
  immediately after use; never staged.

**Test-authorship gap found (NOT fixed — flagged for T, per pipeline rule)**
- `GroupLinksFieldTest::setUp()` is missing the programmatic `FieldStorageConfig`/`FieldConfig`/
  `EntityViewDisplay`/`EntityFormDisplay` construction calls that this exact module's two sibling
  kernel tests (`GroupExtrasBehaviorTest`, `GroupRestoreTest`) already use for their own
  config-only field (`field_group_type`). Root-caused precisely (not hand-waved): kernel tests
  never auto-install a listed module's `config/install/` directory or invoke `hook_install()` for
  modules named in `static::$modules` — confirmed by reading `KernelTestBase::bootKernel()` and
  `DrupalKernel::updateModules()` directly. Without this setup, 6 of the test's 7 methods cannot
  pass under ANY production code, by construction. Proved F's production code is correct via a
  throwaway diagnostic copy (never staged, deleted after use) that adds the one missing ingredient
  — all 7 pass identically to the real test's assertions. Full root-cause + recommended fix in
  `handoff-F.md` "Tests that look wrong (for T)". F did NOT edit the real test file.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-F.md`
- Kernel run (real, unmodified `GroupLinksFieldTest.php`): 6/7 FAIL, identical to T's RED (no
  change from Phase 4 — confirms F's production code did not accidentally make it worse, and the
  gap is the test's setup, not F's implementation).
- Diagnostic run (throwaway copy, deleted after use): 7/7 pass — proves F's config/CSS/hook code
  is correct in isolation.
- No-regression run: `Tests: 118, Assertions: 3249, Failures: 6` across all 11 custom modules'
  Kernel suites — the 6 failures are exactly and only `GroupLinksFieldTest`'s 6, zero regressions
  elsewhere.
- phpcs (`--standard=Drupal,DrupalPractice`): 0 errors on F's edited/new PHP; the handful of
  warnings are all on pre-existing lines F did not touch (confirmed via `git diff --stat`).
- `bash scripts/ci/assemble-config.sh`: exits 0; all 4 new/edited config files present in the
  assembled `config/sync/`.

---

## Phase 6 — T (verify, GREEN + Tier 2)

**Decided**
- Fixed the test-authorship bug F flagged (not F's to fix): added programmatic
  `FieldStorageConfig`/`FieldConfig`/`EntityViewDisplay`/`EntityFormDisplay` construction to
  `GroupLinksFieldTest::setUp()`, mirroring `GroupExtrasBehaviorTest`/`GroupRestoreTest`'s
  established convention for this module's config-only fields. Every setting value copied verbatim
  from F's shipped YAML so the kernel fixture cannot drift from real config.
- Found and fixed a SECOND test-authorship bug during E2E verification:
  `tests/e2e/group-links.spec.ts`'s rel="noopener" test swept every `a[href^="http"]` on the whole
  page and failed against Olivero's own unrelated "Powered by Drupal" footer link. Rescoped the
  locator to `.field--name-field-group-links a[href^="http"]` — the feature's own field wrapper —
  so the test only asserts on links this story's field renders.
- Ran the FULL E2E path this session (not deferred to CI-only): installed the site
  (`drush site:install`), imported assembled config, seeded all 3 demo-data scripts (mirroring
  `.github/workflows/test.yml`'s exact sequence), `npm install` + `playwright install chromium`,
  and ran `tests/e2e/group-links.spec.ts` against the live seeded DDEV site — both tests GREEN.
- Ran a mutation-sensitivity spot-check on the kernel suite (mutated `rel: noopener` ->
  `rel: mutated-none` in the test's own fixture, confirmed FAIL, reverted, confirmed GREEN again)
  to prove the test pins real behavior, not vacuous config presence.

**Assumed**
- `config/sync/` churn from `site:install`/`config:import` in this session is expected
  build-pipeline noise (mirrors what CI's e2e job does in its own ephemeral runner) and is NOT part
  of this story's diff — not staged, flagged to O not to stage it.

**Hedged**
- None — both RED->GREEN transitions for the two test-authorship bugs found were fully resolved
  and re-verified in this session, not deferred.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-T-green.md`
- Kernel (story): 7/7 GREEN, 165 assertions, identical shape to F's diagnostic run.
- Kernel (no-regression, 11 modules, exact task command): `Tests: 118, Assertions: 3258,
  Deprecations: 28` — zero `Failures:` line (F's Phase 5 run on the same command showed
  `Failures: 6`, all in `GroupLinksFieldTest`; those 6 are now gone).
- E2E (`group-links.spec.ts`): 2/2 GREEN against a live, fully seeded DDEV site.
- E2E full-suite sweep: 63 passed, 1 unrelated pre-existing failure in `group-restore.spec.ts`
  (#143's own story — confirmed via `git diff --stat` that neither `RestoreGroupForm.php` nor
  `group-restore.spec.ts` were touched by #140's diff), 1 skipped.
- phpcs on the edited test file: 8 pre-existing doc-comment-style findings, confirmed identical on
  the original committed version — zero NEW issues from the `setUp()` edit.
- YAML parse: all 4 config files parse cleanly via `Symfony\Component\Yaml\Yaml::parseFile()`.
- `assemble-config.sh`: exits 0; all 4 config files present in assembled `config/sync/`.

**Verdict:** T-green complete, no blocking issues. Ready for U (UI surface — group Full display
page).
