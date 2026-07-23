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
