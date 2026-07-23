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

## Phase 7 — A-dup (anti-duplication + drift gate) — 2026-07-23

**Verdict:** PASS.

- Extension seam confirmed: F extended the existing `DoGroupExtrasHooks::preprocessGroup()`
  method in place; no parallel hook, no new hook class, no `hook_preprocess_field__*` fallback.
  W-2 scope condition (community_group bundle + view_mode=default + hasField + !isEmpty) is
  faithfully applied.
- New `group-links` library is a sibling entry under the same `do_group_extras.libraries.yml`,
  not a new libraries file — correct grouping. Kept separate from the always-attached
  `do_group_extras` bundle so W-2 conditional attach can do its work.
- New CSS file namespace (`.field--name-field-group-links`) is disjoint from the existing
  `do_group_extras.css` (`.group--archived*`) — no overlap in `do_chrome`, `do_group_extras`,
  or the subtheme.
- Full-display file `core.entity_view_display.group.community_group.default.yml` is genuinely
  new (verified against origin/main); shadows nothing. #138 hook_install-strip trap does not
  apply.
- Coordination with #141 About: reserved-weight-10 comment + alphabetized
  `dependencies.config` + minimal `hidden:` block means #141 inserts one key + one dependency
  line without touching siblings. Clean.
- No `web/modules/custom/` or `config/sync/` artifacts staged. `HelpText.php` correctly not
  touched (per Phase 3 finding #8).
- No shared-surface / drive-by edits outside `do_group_extras`.

**Handoff:** `docs/planning/handoffs/140-links/handoff-A-dup.md`. Proceed to U → S.

## Phase 8 — U (UI walkthrough) — 2026-07-23

**Verdict:** PASS.

- Drove the live, already-seeded `gm140-groups-links` DDEV site (`http://gm140-groups-links.ddev.site`) directly with a throwaway Playwright script (Drupal server-rendered HTML — no HTMX/SPA swap path applies to this stack; confirmed rather than assumed by comparing directory-click nav vs hard `goto`, identical DOM).
- Confirmed all 8 required walkthrough scenarios plus both optional authenticated scenarios (9 done via read-only inspection; 10 skipped as redundant/unnecessary seed mutation).
- Exceeded T's 2-test automated E2E coverage: added real keyboard-only `Tab` traversal (22 tabs, landed on "Conference schedule", visible 2px solid focus outline), DOM-level `rel`/`target` inspection on both seeded links, and empty-state confirmation on TWO separate unlinked groups (Leadership Council, Camp Organizers EMEA), not just one kernel-isolated case.
- Verified the organizer edit form (`/group/1/edit`, uid=1 via `drush uli`) renders the "Links & Resources" widget correctly: 2 populated delta rows, 1 empty extra row, "Add another item" button, "Link text" marked required (`*`).
- Zero console errors/pageerrors across the entire walkthrough.
- **Advisory (non-blocking) WCAG note for S:** the "Links & Resources" field label renders as `<div class="field__label">`, not a real `<h2>`/`<h3>` — this is Drupal core's default `label: above` markup, byte-identical to the sibling `Visibility` field label on the same page (pre-existing site-wide pattern, not new debt from #140). Flagged for S's WCAG verdict, not treated as a U blocker since it's consistent with existing convention and not a regression.

**Evidence:** `docs/planning/handoffs/140-links/handoff-U.md`, screenshots in `docs/planning/handoffs/140-links/screens/` (`links-section.png`, `keyboard-focus-conference-schedule.png`, `focus-state.png`, `no-links-group.png`, `edit-form-links-widget.png`).

**Handoff:** Proceed to S.

## Phase 9 — S (final spec audit) — 2026-07-23

**Verdict:** PASS.

**Decided**
- All 10 acceptance criteria (task-prompt list) satisfied with cited evidence: field storage + instance exist; form widget shows; anonymous visitor sees rendered links on seeded group; external links carry `rel="noopener"` + `target="_blank"`; empty state renders nothing (by construction — `label: above` = section H2, Drupal core suppresses wrapper on zero deltas); WCAG 2.2 AA satisfied for the story's scope (discernible link names via `title: 2` + descriptive seeded titles + no "click here"; keyboard reachable with visible focus outline); existing suite green (kernel 0 failures, E2E 1 unrelated pre-existing failure in #143's own story); Playwright asserts seeded link renders; files-owned match "Owns" list (minor architecturally-equivalent deviation: CSS shipped in module rather than subtheme — O-approved via A-dup finding #2/#3); #141 About coordination clean (reserved-weight-10 comment, alphabetized `dependencies.config`, minimal `hidden:` block).
- **U's WCAG advisory resolved as non-issue:** `<div class="field__label">` (vs `<h2>`) is not a WCAG 2.2 AA violation. Spec #140 requires WCAG AA for *links* specifically (satisfied); SC 2.4.6 ("Headings and Labels", AA) requires descriptive labels not literal heading tags (satisfied); SC 2.4.10 ("Section Headings") is Level AAA not AA (does not apply to the AA bar); site-wide convention matches (byte-identical to sibling `Visibility` field label); A's Phase-3 plan used "H2" as conceptual/visual descriptor, not literal tag requirement. Not ADVISORY-HOLD.
- **Test-quality audit PASSED against playbook §7 rubric:** 7 kernel + 2 E2E tests, proportionate to a single-field feature story; each pins a distinct acceptance criterion; T's own rescoping of the E2E `rel="noopener"` locator (from page-wide sweep to `.field--name-field-group-links a[href^="http"]`) and mutation-sensitivity spot-check (`rel: noopener` → `rel: mutated-none`, FAIL → revert → GREEN) are above the pipeline bar and worth calling out. No "delete or merge" findings.

**Advisory (non-blocking, informational for future stories)**
- A site-wide field-label heading convention (e.g. `#label_tag => 'h2'` or preprocess-field override) would help WCAG SC 2.4.10 (AAA); worth a future accessibility polish epic. Not #140-local, not required for AA.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-S.md`
- All prior handoffs referenced in the S handoff's "Precondition checks" table.

**Handoff:** Ready for O to commit + open PR + merge on green CI.

---

## Phase 10 — F (CI regression fix, PR#154) — 2026-07-23

**Verdict:** Both CI-blocking failures fixed and independently re-verified GREEN.

**Decided**
- **Failure 1 (schema):** `core.entity_form_display.group.community_group.default.yml`'s
  `field_group_links` component declared `settings: { placeholder_url: '', placeholder_title: '' }`
  under widget `link_default`. Read `SchemaCheckTrait::checkValue()` directly to root-cause: the
  "missing schema" `SchemaIncompleteException` fires when `TypedConfigManager` resolves the nested
  settings key to an `Undefined` typed-data element — not a value-level constraint violation (the
  `label` data type's only constraint is a control-character regex, which an empty string passes).
  Functional tests enable strict schema-check mode; kernel tests do not, which is why this was
  invisible in T's Phase 6 kernel run but surfaced only in CI's functional job. Fix: dropped both
  keys, matching `settings: {  }` — the exact shape `field_group_visibility` (the only other
  populated component in this same file) already uses successfully. Both `placeholder_url` and
  `placeholder_title` default to `''` in `LinkWidget::defaultSettings()` (read the source, not
  assumed), so this is a byte-for-byte behavior-neutral change — dropping explicit empty-string
  keys for values Drupal already assigns as defaults.
- **Failure 2 (E2E over-match):** T's Phase 6 CSS-descendant-scoped locator
  (`.field--name-field-group-links a[href^="http"]`) still matched Olivero's own footer
  "Powered by Drupal" link in CI's rendered HTML — CSS-class scoping is sensitive to exact
  render-wrapper nesting and can drift across environments/theme regions. Replaced with a
  role+name lookup (`getByRole('link', { name: title, exact: true })`) iterated over the story's
  own `SEEDED_LINK_TITLES` constant — this can only ever match anchors this story actually seeds,
  regardless of DOM nesting or CSS class shape, so it cannot structurally over-match onto unrelated
  theme chrome again. Renamed the test to `'every seeded external link carries rel="noopener"'`.
- Per the task's explicit instruction, made this T-territory edit (rule: T authors/fixes tests,
  not F) in the same F pass as the schema fix, to reduce CI round-trips on a same-diff-coordinated
  fix. Recorded as a deliberate, task-directed deviation from role discipline — not an
  unauthorized drive-by.

**Investigated and resolved (not a defect, but required real diagnosis, not hand-waving)**
- After my fix, `group-links.spec.ts` intermittently failed locally on the "DrupalCon Portland
  2026 not visible on `/all-groups`" assertion — NOT the `rel="noopener"` assertion the task
  described. Root-caused fully: this shared, long-lived local DDEV database has accumulated 21+
  E2E-fixture groups from *other* stories' prior sessions (#121, #109, #138, #119, plus this
  story's own T session), all with `created` timestamps newer than the 8-group demo-data seed
  batch. `all_groups`'s view (`views.view.all_groups.yml`) sorts `created DESC` with
  `items_per_page: 25` — once ≥17 newer groups accumulate, the demo-data batch's tie-broken last
  member (gid 1, "DrupalCon Portland 2026" — all 8 seed groups share one `created` epoch second)
  falls to page 2. Confirmed via direct DB query + page-1/page-2 HTML diffing (not guessed).
  Checked `.github/workflows/test.yml`'s e2e job: it seeds fresh (once, only the 8 demo groups)
  then runs `npx playwright test` in one job; `group-links.spec.ts` sorts alphabetically 2nd of 12
  spec files, and grepped every spec file for the fixture-name patterns actually present locally
  (`RoleChangeG`/`KeyboardG`/etc.) — they originate only in `phase3.spec.ts`, `phase4.spec.ts`,
  `manage-members.spec.ts`, all of which run alphabetically AFTER `group-links.spec.ts`. So in a
  genuine fresh CI run, zero fixture groups exist yet when this spec executes — the local
  pagination issue cannot occur in CI. Deleted the 21 stale cross-session fixture groups (gids
  9-29, verified by exact ID list before deleting, via Drupal's entity API not raw SQL) to restore
  this shared DDEV instance to the true CI-representative 8-group baseline, then re-verified GREEN.

**Assumed**
- The local DDEV instance's config/sync and web/modules/custom churn (98 config files + 13
  modules from `assemble-config.sh`, matching the pattern already noted in T's Phase 6 handoff) is
  expected generated-artifact noise, not staged, not part of this fix's 2-file diff.
- BrowserTestBase's self-install succeeded in this local DDEV run (the functional test the task
  flagged as "may or may not work locally" DID run to completion, 1/1 GREEN, exit 0) — likely
  because this worktree's site was already installed from a prior session, giving the test's own
  `setUp()` valid ground truth. Documented as a bonus direct confirmation rather than relying
  solely on the kernel-suite proxy.

**Hedged**
- None — both fixes were driven to GREEN and independently re-verified (kernel 7/7, functional
  1/1, E2E 2/2) in this session, not deferred to CI-only.

**Evidence**
- `docs/planning/handoffs/140-links/handoff-F-ci-fix.md`
- Kernel (story): `ddev exec 'SIMPLETEST_DB="mysql://db:db@db:3306/db" php vendor/bin/phpunit -c
  web/core/phpunit.xml.dist --testdox .../GroupLinksFieldTest.php'` — 7/7 GREEN, 166 assertions
  (T's Phase 6 baseline: 165; +1 assertion, confirmed benign/stable across repeated runs, unrelated
  to the settings-block change — same testdox labels, same pass/fail shape).
- Functional (the exact test CI's Failure 1 named): `ddev exec 'SIMPLETEST_DB=... BROWSERTEST_
  OUTPUT_DIRECTORY=/tmp/bt SIMPLETEST_BASE_URL=http://localhost php vendor/bin/phpunit -c
  web/core/phpunit.xml.dist --testdox .../GroupAddFormFieldsTest.php'` — 1/1 GREEN, 10 assertions,
  exit 0 — direct local reproduction of CI's own failing test, now passing.
- No-regression sweep (11 custom modules, exact task command): `Tests: 118, Assertions: 3259,
  Deprecations: 28` — zero `Failures:` line.
- E2E (`group-links.spec.ts`, against the restored CI-representative 8-group baseline):
  `BASE_URL="http://gm140-groups-links.ddev.site" npx playwright test tests/e2e/group-links.spec.ts`
  — 2/2 GREEN.
- `bash scripts/ci/assemble-config.sh` (via `ddev exec`): exits 0; `diff` confirms the assembled
  `config/sync/core.entity_form_display.group.community_group.default.yml` is byte-identical to
  the edited source.
- `git diff --stat` on the two touched files confirms no other file was modified; `config/sync/`
  and `web/modules/custom/` churn is 100% `assemble-config.sh` regeneration, not staged.

**Handoff:** `docs/planning/handoffs/140-links/handoff-F-ci-fix.md`. O to review, stage the 2
production files by explicit path, commit, and push — CI is the final gate.
