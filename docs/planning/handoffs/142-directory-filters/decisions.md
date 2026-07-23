# Decision Journal — #142 Directory location + language filters

Run slug: `142-directory-filters` · Started 2026-07-22 · Overnight autonomous mode.

## O — Phase 1 (initial survey + brief)
Decided: skip D; location = free-text; language = `field_group_language`; new `field_group_location`.
Assumed: no #139 blocker.
Evidence: issue text, existing view yml, baseline field yml.

## A — Phase 3 (up-front plan review, r1) → BLOCK
Blocking:
1. `field_group_location` collides with #125 (geofield). Rename to `field_group_location_text`.
2. Language-field authority unresolved vs #139 (`field_group_primary_language`).
Warns:
3. Pin views language filter `plugin_id: language`.
4. Kernel test must run anonymous, not UID 1.
5. Form/view-display collision — dissolves with rename.

Handoff: `handoff-A-plan.md`.

## O — Phase 3.5 (amend, overnight-mode adjudication)
Decided:
- Rename to `field_group_location_text` (accept A #1).
- Use baseline `field_group_language` (already on origin/main). #139 issue text itself says "verify vs `do_group_language`; reuse if it already provides this" — this decision is *consistent with* #139's own reuse preference, so overnight-mode picks forward progress.
- Pin `plugin_id: language`. Kernel test runs anonymous.

Assumed (Open — surface in Chain Summary):
- #139 owner accepts reusing `field_group_language` rather than introducing a parallel `field_group_primary_language`.

Evidence: A handoff `handoff-A-plan.md`; #139 issue body; #125 issue body confirms geofield ownership.

Hedged: if S ultimately blocks on field-name choice, we would either rename baseline (invasive) or add an alias — for POC we ship and let #139 adapt.

## T — Phase 4 (author RED)
Decided:
- Kernel test (`DirectoryFiltersTest`) installs `views.view.all_groups.yml` + the new location field's storage/instance from MODULE-LOCAL fixtures (`do_tests/tests/fixtures/config/`), following `do_group_pin`'s `PinnedStreamOrderingTest` pattern, since none of these ship in a module's `config/install`.
- Added an OUTSIDER-scope `view group` grant to the ANONYMOUS role in test setUp — without it, `GroupsKernelTestBase`'s minimal group type has no synchronized roles at all, so an anonymous session sees zero groups regardless of archived status, which would make the exclusion assertion pass for the wrong reason.
- e2e suite (`directory-filters.spec.ts`) requires F to seed 3 specific groups (`Filter Test Berlin English/Paris French/Berlin French`) into `step_700_demo_data.php` rather than self-seeding via the live add-group form, because `field_group_language` is deliberately absent from that form today (per `GroupAddFormFieldsTest`) and the new location field is unlikely to be added to it either.

Assumed:
- T's own field-config fixtures (storage + instance for `field_group_location_text`) are a reference shape only — F's real `docs/groups/config/*.yml` is the contract; T re-syncs fixtures from F's actual shipped files at Phase 6 GREEN.

Fixed during RED authoring (T's own test-authorship bug, not F's):
- `selectOption({ label: /french/i })` is invalid Playwright API (label must be a string) — corrected to `selectOption('fr')`, selecting by langcode value rather than label text.

Evidence:
- Kernel RED: 2 of 4 tests fail for the right reason (missing exposed filters); the other 2 legitimately pass pre-F (one validates T's own fixture, one pins a PRESERVED pre-existing invariant — see handoff-T-red.md for the full justification).
- e2e RED: all 5 tests fail for the right reason (missing labeled controls / missing seed data), confirmed against a fully assembled + installed + config-imported + seeded DDEV instance reproducing the CI e2e job's prerequisite chain.

Hedged:
- This worktree's `.ddev/config.yaml` project name was changed locally (`pl-groups-on-d11` -> `gm142-directory-filters`) to avoid colliding with the primary checkout's running DDEV project, per PROJECT_CONTEXT's container-namespacing rule. This change is NOT committed (local-only convenience); a later phase may need to redo this setup in its own shell.

## F — Phase 5 (implement against RED)
Decided:
- Extended `docs/groups/config/views.view.all_groups.yml` (analogous object named by brief) rather than creating a new view. Extended `do_group_language` (already owns field_group_language-specific behavior) with a new Views-data-alter hook rather than creating a new module.
- Filter `field:` config key uses the `_value`-suffixed Views-data name (`field_group_location_text_value`, `field_group_language_value`), not the bare field name T's kernel-test fixture/assertions use — verified live that the bare name resolves to `Drupal\views\Plugin\views\filter\Broken` on a real assembled site; the suffixed name is what a genuine Drupal "Add filter" UI action would itself store.
- Added `docs/groups/config/language.entity.fr.yml` (not in the brief's literal text) — required production prerequisite: core's `LanguageFilter::access()` hard-gates on `LanguageManager::isMultilingual()` (>1 configured language), and this repo had zero non-English `ConfigurableLanguage` entities anywhere despite `field_group_language` already storing 'fr'/'de' values. Without it, the language filter acceptance criteria are structurally unsatisfiable on any real site.
- Added `docs/groups/modules/do_group_language/src/Hook/DoGroupLanguageHooks.php` (`hook_field_views_data_alter()`, not in the brief's literal text) — required because a view's stored `plugin_id:` config key is schema/admin-UI metadata only; the real handler class Views instantiates at runtime comes exclusively from Views-data's own `filter.id` for the target table/column (traced through `ViewsHandlerManager::getHandler()` end-to-end). Core's generic `FieldViewsDataProvider::defaultFieldImplementation()` has no `language`-type special case for bundle-attached fields (that only exists for BASE fields, a different class entirely, `EntityViewsData`), so `field_group_language`'s dedicated-table column defaults to the generic `string` filter plugin. Without this hook, `plugin_id: language` in the view config is silently ignored and the exposed control never renders (Broken handler → dropped by `initHandlers()`).

Assumed:
- None beyond what's already logged upstream — the two production additions above are evidenced findings, not open assumptions (each independently verified via live `drush php:eval` inspection against the real assembled+imported DDEV site, cited in handoff-F.md).

Fixed during implementation (F's own diagnostic, not a test edit):
- Confirmed via full core-source trace + live inspection that a bundle-attached `language`-type field's exposed filter does NOT resolve to `LanguageFilter` by declaring `plugin_id: language` alone — this required the new `hook_field_views_data_alter()` (see above). Not a test-authorship bug; a genuine, non-obvious Drupal-core-behavior gap the brief's acceptance criteria assumed away.

Evidence:
- Tier-1 self-check: `DirectoryFiltersTest` 2/4 pass (the 2 failures are T's own stale RED-state fixture, explicitly flagged as T's Phase-6 responsibility in T's own handoff-T-red.md); full 141-test cross-module kernel sweep shows the identical 2 failures and zero new regressions (2 independent runs, consistent). Real assembled site: both filters render with correct WCAG labels, filter/combine/reset correctly, archived-group exclusion preserved — all verified via direct HTTP curl against the seeded DDEV site, not just kernel-test inspection.

Hedged:
- Flagged (did not edit) two likely T test-authorship issues for Phase 6: (1) `testViewDeclaresBothExposedFilters`'s bare-name `field:` assertions need the `_value` suffix to match a working config; (2) `directory-filters.spec.ts`'s reset-button locator uses `role: 'link'` but core renders `<input type="submit">` (role "button") — see handoff-F.md "Tests that look wrong" for full evidence on both.
