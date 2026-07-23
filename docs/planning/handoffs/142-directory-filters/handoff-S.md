# Handoff-S: Phase 9 - #142 MC-3 Directory Location + Primary-language Filters

**Date:** 2026-07-23
**Branch:** 142-directory-filters
**Issue:** #142
**Handoff-U reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-U.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-T-green.md`
**Handoff-F reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-F.md`
**Handoff-A reviewed:** `docs/planning/handoffs/142-directory-filters/handoff-A-plan-r2.md` (PASS after r1 BLOCK amendments applied)

## Preconditions

- A precondition: **PASS** (r2 verdict, all r1 blocks resolved).
- T precondition: **PASS** (T-green reports zero blocking issues; 4/4 kernel + 5/5 e2e GREEN, 141-test cross-module sweep 0 regressions).
- U precondition: **PASS** (all 8 walkthrough tasks pass; only a pre-existing `.gc-badge--success` contrast advisory unrelated to #142).
- Review rigor per issue body: **`none` (POC posture)** — no visual-diff / pixel-comparator gate is warranted (issue explicitly bounds this as POC MVP-conformance work). S runs spec compliance + scope discipline + quality audit against the handoffs and the diff.

## Diff scope check (source-only, no build artifacts)

`git diff --stat 49fe585..HEAD` (branch base) shows changes confined to:
- `docs/groups/config/*.yml` — 4 files (field storage + instance for `field_group_location_text`, `language.entity.fr.yml`, view edit)
- `docs/groups/modules/do_group_language/**` — 2 files (new Hooks class + docblock update on `.module`)
- `docs/groups/scripts/step_700_demo_data.php` — appended Step 736 (3 seed groups)
- `docs/groups/modules/do_tests/tests/**` — kernel test + fixtures (T-owned)
- `tests/e2e/directory-filters.spec.ts` — e2e suite (T-owned)
- `docs/planning/handoffs/142-directory-filters/**` — brief/survey/decisions/handoffs + 6 screenshots

**No committed build artifacts.** `web/modules/custom/` and `config/sync/` at repo root are unchanged from the branch base (both are `.gitignore`'d assemble outputs; local worktree has them dirty but they are not staged and not part of the branch's committed history). Verified via `git log origin/main..HEAD --stat`: only source-tree paths appear across all 9 commits.

## Issue #142 acceptance criteria — per-criterion evaluation

| # | Issue acceptance criterion | Status | Evidence |
|---|---|---|---|
| 1 | Both filters appear on `/all-groups`, work, and combine with existing filters + each other | PASS | Kernel `testViewDeclaresBothExposedFilters` + `testExposedFormIsNonEmpty`; U walkthrough steps 2–4 (Location=Berlin narrows to 2; Language=fr narrows to 2; combined narrows to 1); e2e `directory-filters.spec.ts` 5/5. Screenshot `01-initial-load-desktop.png` visually confirms all three controls (Search groups, Location, Primary language) render inline. |
| 2 | Access-safe: archived/unlisted/private never appear regardless of filter | PASS | Kernel `testAnonymousExecutionExcludesArchivedGroup` (runs as anonymous, not UID 1, per brief); U step 6 confirmed no "Legacy Infrastructure" archived group in the unfiltered anonymous view. |
| 3 | WCAG 2.2 AA (labeled form controls, keyboard operable) | PASS | Rendered HTML shows `<label for="edit-location">Location</label>` + `<label for="edit-field-group-language">Primary language</label>` (real `for`/`id` binding, not proximity). Playwright accessibility resolution via `getByLabel` passes. U axe scan of the exposed-form scope returned 0 violations. Focus-ring visible in screenshot 01 (Olivero 6px double outline). Theme CSS has zero `outline: none`/`outline: 0` matches. |
| 4 | Existing suite green; Playwright exercises a combined filter | PASS | 141-test cross-module kernel sweep 0 regressions (2 pre-existing failures were T's own RED-state, both GREEN in T-green). Playwright `combined location + language filter` test in `directory-filters.spec.ts` passes and asserts the 1-group intersection. |
| 5 | Delivery per epic: branch → namespaced DDEV rendered-DOM check → local `npx playwright test` green → PR → merge | PARTIAL | Everything upstream of "PR" done (namespaced site `gm142-directory-filters.ddev.site`, curl-verified rendered DOM, e2e 5/5 GREEN). PR step is O's next action after this handoff. |

## Brief (v2) acceptance criteria — per-criterion evaluation

Each brief criterion is backed by an authored test (T-green Acceptance table). Spot-verified against the actual test file names:

| # | Brief criterion | Backing test | Status |
|---|---|---|---|
| 1 | View declares exposed language filter, `plugin_id: language` | `testViewDeclaresBothExposedFilters` | PASS |
| 2 | View declares `location` filter, operator `contains` | `testViewDeclaresBothExposedFilters` | PASS |
| 3 | New `field_group_location_text` field storage + instance | `testLocationTextFieldIsAttachedToGroupBundle` | PASS |
| 4 | Kernel: view loads, filters present, anonymous, archived/unlisted/private excluded | `testExposedFormIsNonEmpty` + `testAnonymousExecutionExcludesArchivedGroup` | PASS |
| 5 | Playwright seeds ≥3 groups, applies each filter, combines both, verifies intersection | `directory-filters.spec.ts` (5 cases) | PASS |
| 6 | phpcs clean on new PHP files | Verified twice (F + T) — 0 errors on `DoGroupLanguageHooks.php`, `.module` docblock, `DirectoryFiltersTest.php` | PASS |
| 7 | WCAG 2.2 AA controls labeled, keyboard operable, visible focus | `getByLabel` test + U axe form-scoped scan + focus-ring screenshot | PASS |
| 8 | Existing suite still green | 141-test sweep 0 regressions | PASS |

## Scope discipline — F additions beyond the brief text

Two files were added that the brief does not literally enumerate:

1. **`docs/groups/config/language.entity.fr.yml`** — core's `LanguageFilter::access()` unconditionally gates on `LanguageManager::isMultilingual()` (i.e. ≥2 configured `ConfigurableLanguage` entities). Repo had zero non-English language entities. Without this file, the brief's own criterion #1 (language filter appears on `/all-groups`) is structurally impossible to satisfy. F verified empirically (`isMultilingual()` false→true after adding). **Verdict: NOT scope creep.** Necessary production prerequisite; the smallest possible fix (single language entity, French, matching the langcode already used by seed data and both test suites).

2. **`docs/groups/modules/do_group_language/src/Hook/DoGroupLanguageHooks.php`** (new class implementing `hook_field_views_data_alter()`) — a view's stored `plugin_id:` config key is admin-UI/schema metadata only; Views' real handler resolution reads `filter.id` from Views-data at query time, which for a bundle-attached `language`-type field defaults to `string` (core's `FieldViewsDataProvider::defaultFieldImplementation()` has no `language` case for bundle fields — only for base fields in a different class). F traced this end-to-end through Drupal 11.4.4 core source and verified empirically (`get_class($view->filter['field_group_language'])` = `Broken` before, `LanguageFilter` after). T independently re-derived the same mechanism while diagnosing `testExposedFormIsNonEmpty`. **Verdict: NOT scope creep.** Extended the existing `do_group_language` module (which already owns `field_group_language`-specific behavior) rather than creating a new module — right layering choice. Without this hook, brief criterion #1 is silently broken at runtime (filter dropped from the exposed form as a `Broken` handler).

Both additions are documented in F's handoff-F.md "Design decisions" #1 and #2 with the evidence trail, and F flagged them as candidate architecture-doc material for future stories in this repo. This is the kind of "smallest-necessary-implicit-prereq" that a POC-rigor issue expects an F to solve without a rework loop.

## Open Assumption — #139 language-field reconciliation

The decision journal (O Phase 3.5, r1 amendment) logs an Open Assumption:

> *#139 owner accepts reusing `field_group_language` rather than introducing a parallel `field_group_primary_language`.*

**Assessment:** Acceptable to ship with this outstanding. Grounds:
- #139's own issue body says "verify vs `do_group_language`; reuse if it already provides this" — this decision is *consistent* with #139's own stated preference for reuse, not a violation of it.
- A already reviewed and PASSED this hedge (r2 verdict) explicitly for POC/overnight mode.
- The hedged fallback (rename baseline OR add alias) is documented in decisions.md if #139 later rejects the reuse — reversible, not a one-way door.
- POC posture per issue body ("Review rigor: none") supports forward progress over blocking on a cross-story coordination that #139 itself invites.

**Not blocking.** Surface in the PR description / Chain Summary at merge time so the human operator can decide whether to ping #139's owner explicitly.

## Advisory — pre-existing `.gc-badge--success` color-contrast

Confirmed **out of scope for #142**:
- Element (`.gc-badge--success`, the green "Open" visibility badge on every directory card) originates from `web/themes/custom/groups_chrome/css/primitives.css:39`, last touched by story #84 (card styling) and #121/#122 (visibility badge chrome).
- #142 does not touch any theme CSS, does not add/modify badge markup, and does not modify `.gc-badge--success` styling.
- U's form-scoped re-scan (`.include()` on just the exposed-filter form) returned 0 axe violations of any severity — the *new* surface is clean.

**Do NOT block on this.** POC posture, unrelated code, pre-existing. Per project instructions, do NOT file a GitHub follow-up issue; it can live as an in-doc backlog note if the operator wants.

## Test-quality audit (rubric per `testing/test-quality.md` §7)

- **Per-test validity:** All 4 kernel tests name a single behavior each and sit at the cheapest sufficient tier:
  - `testViewDeclaresBothExposedFilters` — static config inspection (cheapest); pins filter declaration.
  - `testExposedFormIsNonEmpty` — handler-init only; pins actual runtime handler resolution (deliberately at a different layer than the static check, because F's decisions #2–#3 demonstrate the two can diverge silently).
  - `testLocationTextFieldIsAttachedToGroupBundle` — static field-config inspection; pins field-storage-instance shape.
  - `testAnonymousExecutionExcludesArchivedGroup` — real view execution as anonymous; pins the access-control invariant (the ONE thing that requires full query execution).
- **Suite proportionality:** 4 kernel + 5 e2e = 9 tests for 8 brief criteria; no fan-out, no duplicate signal, no coverage padding. The two "seemingly overlapping" kernel tests (declares vs. exposed) probe deliberately different layers — this is exactly the case the rubric names as "probe layer X and layer Y where X and Y can diverge in production." T-green's justification is sound.
- **Failure sensitivity:** T-green's spot-check confirms the tests fail for the right reason (before F's work, exactly these assertions failed; after, all pass). Not vacuously true.
- **No smells:** No assertion-free tests, no mock-shaped tests, no snapshot-everything, no unreachable outcomes.

**Verdict: PASS.** No "delete or merge" findings. No missing coverage.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| Spec compliance (issue #142) | PASS | All 4 acceptance bullets satisfied (see per-criterion table above); item 5 (PR + merge) is O's next action. |
| Spec compliance (brief v2) | PASS | 8/8 criteria backed by tests, all GREEN. |
| Test quality (`testing/test-quality.md` §7) | PASS | Layer discipline + proportionality both hold; no delete/merge findings. |
| Architecture gate (A) | PASS | r2 PASS with all r1 blocks resolved. |
| Code organization | PASS | New hook class placed in existing owning module (`do_group_language`); no new module. Field naming disambiguated (`field_group_location_text` vs. #125's geofield `field_group_location`). |
| Naming consistency | PASS | Filter identifiers (`location`, `field_group_language`), field names (`field_group_location_text`), and language filter's `plugin_id: language` all match brief and #125-collision-avoidance rename. |
| Accessibility | PASS | Real `<label for>` binding verified in rendered HTML; axe form-scoped scan 0 violations; focus ring preserved (no theme CSS suppression). |
| Security | PASS | Both filters are read-only GET-param filters against core handler classes (`StringFilter`, `LanguageFilter`); no new input-validation surface. Anonymous access-control invariant explicitly tested. |
| Performance | PASS | No new joins on hot paths; both new filters query dedicated field tables already indexed by entity_id per Drupal's default field-storage schema. |
| Scope discipline | PASS | Two non-brief-verbatim additions (`language.entity.fr.yml`, `DoGroupLanguageHooks.php`) are necessary production prerequisites for the brief's explicit criteria to hold at runtime, not scope creep. |
| Diff hygiene (no build artifacts committed) | PASS | Only `docs/…` + `tests/e2e/…` in `git log origin/main..HEAD --stat`. |
| POC posture respected | PASS | No over-engineering (extended existing module rather than creating new; single language entity rather than a language-management subsystem; smallest-possible seed-data extension). No follow-up GH issues filed. |

## Verdict

**PASS** — all issue and brief acceptance criteria met, spec-compliant, quality acceptable, scope disciplined, source-only diff, POC posture respected. Ready for O to open PR and drive to merge.

## Notes for O at PR time

- Surface the Open Assumption in the PR description / Chain Summary: *"#142 ships with `field_group_language` (baseline) as the filter target; #139 owner should confirm this reuse or arrange a rename during that story."*
- Advisory (do NOT block PR): pre-existing `.gc-badge--success` color-contrast (`primitives.css:39`, from #84/#121/#122). Out of scope for #142; note it in the PR description for visibility only.
- Field-key naming subtlety (F decision #3) — filter config uses `_value`-suffixed Views-data keys (`field_group_location_text_value`, `field_group_language_value`), not bare field names. Worth mentioning in the PR body since it's non-obvious and future filter-adding stories in this repo will hit the same gotcha.
