# Decision Journal — #141 MC-2 About section

**Run started:** 2026-07-23  **Branch:** 141-about  **Base:** origin/main @ 49fe585

---

## O — Phase 1 (survey + brief)

**Date:** 2026-07-23

**Decided:**
- **NEW field `field_group_about`** (text_long) rather than reuse of `field_group_description`.
  Justification: spec says "richer content **beyond** the one-line description"; seed reality
  confirms descriptions are one-liners (~10–15 words). Reuse would break description's teaser
  role or leave About semantically identical to description.
- **Extend `do_group_extras`** (module), not new module. #140's exact pattern is the model.
- Weight 10 (already reserved by #140 with a comment marker) is the display slot.
- Formatter: `text_default` (matches description). Widget: `text_textarea` (formatted, no summary).
- Empty-state strategy: use field's own `label: above` ("About") so Drupal core suppresses the
  whole wrapper on zero deltas — mirrors #140 warn #6 pattern; no template override needed.
- D skipped per LEAN POC pipeline judgment (trivial field-add with no user-facing design choice
  beyond "About heading + formatted body below description"). Recorded here so A can flag if it
  disagrees.

**Assumed:**
- The reserved-weight-10 slot rendered position (between visibility=1, image=2, links=20) yields
  the intended narrative flow: description → visibility badge → image → About body → Links CTA.
  If UX judgment says About should render immediately below description (before image), the
  weight is a one-line change — T's kernel test should pin weight=10 either way.
- `basic_html` filter format exists in the seeded site (used by description seed already).

**Hedged:**
- None — every mechanism above has a direct precedent in #140 or the existing description field.

**Evidence:**
- `docs/planning/handoffs/141-about/survey.md`
- `docs/planning/handoffs/141-about/brief.md`
- `docs/groups/scripts/step_700_demo_data.php:85` — description seed shape confirms one-liner
- `docs/groups/config/core.entity_view_display.group.community_group.default.yml:50` — `# (weight 10 reserved for #141 About)` placeholder
- `docs/planning/handoffs/140-links/handoff-A-plan.md` — full analogue plan review

---

## A — Phase 3 (up-front plan review)

**Date:** 2026-07-23  **Verdict:** PASS with 3 T-warns + 1 brief copyedit.

**Decided (confirmed by A):**
- New-field decision (Finding #1 PASS).
- Extend `do_group_extras` (Finding #2 PASS).
- Weight-10 slot + marker convention + dependency alphabetization (Finding #3 PASS).
- Test-tier split (kernel + E2E, no functional, no unit) — Finding #7 PASS.
- Anti-duplication clean across sibling modules — Finding #8 PASS.
- Forward-compat clean across #142/#143/#144/#145 — Finding #9 PASS.

**Warns folded into brief (encoded for T's RED):**
- **#4** — empty-state test covers BOTH shapes: never-set AND explicit `[value=>'', format=>'basic_html']`.
- **#5** — AC-5 asserts observable HTML (`<strong>` present); T materializes minimal `basic_html`
  FilterFormat in setUp (kernel doesn't install site config). Added `filter` to $modules.
- **#6** — F code-shape hint: About library attach nests INSIDE the same outer `bundle && view_mode`
  conditional links uses. Optional kernel library-attach assertion — E2E covers observable side.

**Brief copyedit (A finding #10):** fixed self-referencing "Coordination with #140" dependency-block
sentence — corrected to "insert as first entry in dependencies.config — sorts alphabetically above
field_group_description".

**Also decided (A confirmed):** do NOT edit `do_group_extras.info.yml`. Core `text` is universally
available; field.storage YAML declares its own `module: text` dep.

**Assumed:** `basic_html` allowed_html can be minimal (`<p><strong>`) for the T fixture — actual
site format is broader, but the test only needs to prove sanitization does NOT strip these tags.

**Evidence:** `handoff-A-plan.md`.

---

## T — Phase 4 (RED)

**Date:** 2026-07-23  **Verdict:** RED valid, 1/8 kernel tests fails for the right reason.

**Decided:**
- Authored `GroupAboutFieldTest.php` mirroring `GroupLinksFieldTest.php`'s programmatic-fixture
  shape exactly (storage/instance/displays built in `setUp()`, since kernel tests never
  auto-install a not-yet-shipped module's `config/install/`).
- Included the optional library-attach assertion (A warn #6) — cheap given the render helper
  already existed; it is the test that actually fails at RED time (production `preprocessGroup`
  hasn't been extended to attach `do_group_extras/group-about` yet).
- Chose the `basic_html` FilterFormat route (not `plain_text`) for AC-5's fixture per A warn #5,
  with minimal `allowed_html: '<p> <strong>'`.
- E2E spec (`group-about.spec.ts`) deliberately does NOT pin a specific seeded group label/phrase
  (unlike `group-links.spec.ts`'s `SEEDED_LINK_TITLES`) because F has not yet written the About
  seed setter — asserts structurally (heading present + non-empty body on at least one seeded
  group; heading absent on at least one other) instead, iterating the full 8-label seeded roster.
  Left a `TODO(F)` inviting a future pinned-phrase revision if desired.

**Assumed:**
- The 7 non-failing kernel tests (config shape, render, empty-state) are a VALID RED posture even
  though they pass today, because they pass only via T's own programmatic fixture standing in for
  not-yet-shipped config — confirmed this is the established, already-merged convention by running
  the sibling `GroupLinksFieldTest` as a baseline (identical ✔/⚠-only, zero-failures result).

**Hedged:**
- None for the authored tests themselves. Renamed this worktree's `.ddev/config.yaml` project name
  from `pl-groups-on-d11` to `gm141-about` to avoid a DDEV project-name collision with the already-
  running main checkout — a necessary environment fix, not a test-content decision, done without
  touching the sibling project's containers.

**Evidence:**
- `docs/planning/handoffs/141-about/handoff-T-red.md` — full RED command + output + interpretation.
- Kernel run: `ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web php vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/do_group_extras/tests/src/Kernel/GroupAboutFieldTest.php --testdox'` → `Tests: 8, Assertions: 191, Failures: 1`.
- Baseline sanity check: `GroupLinksFieldTest.php` (already merged/GREEN) run identically →
  `Tests: 7, Assertions: 166, Deprecations: 2`, zero failures — confirms the ⚠-only pattern is
  expected/benign, not evidence of an invalid RED.
- E2E: `npx playwright test --list tests/e2e/group-about.spec.ts` → `Total: 2 tests in 1 file`.

---

## F — Phase 5 (implement to GREEN)

**Date:** 2026-07-23  **Verdict:** 8/8 kernel tests GREEN (0 failures); 27/27 do_group_extras kernel
suite GREEN (no regression on `GroupLinksFieldTest`/`GroupRestoreTest`/`GroupExtrasBehaviorTest`).

**Decided:**
- Storage/instance YAML mirror `field_group_description`'s shape byte-for-byte on every key except
  `field_name`/`id`/`label`/`uuid` (per brief). `allowed_formats: {}` on the instance, matching
  description exactly (not `field_group_links`'s `link_type`/`title` settings shape).
- View-display edit: replaced the `# (weight 10 reserved for #141 About)` placeholder with a
  `# --- Section: About (weight 10) ---` marker (mirroring the Links section's own comment-block
  convention verbatim: rationale sentence about `label: above` being the H2 source + suppression-
  on-empty). Inserted `field.field.group.community_group.field_group_about` as the FIRST entry in
  `dependencies.config` (confirmed it sorts alphabetically above `field_group_description`, per A
  finding #10). `text` was already present in `dependencies.module` — no addition needed.
- Form-display weight: brief's fallback guidance ("if description is 0, use 3 or 4") didn't match
  reality — description's form weight is `1`, not `0`, and `2`/`3`/`4` are all already occupied by
  visibility/image/links respectively, and the brief forbids touching those siblings. Chose weight
  `10` (mirrors the view-display's semantic "weight 10 = About" convention) so About tabs last on
  the edit form without renumbering or tying with any sibling weight.
- Preprocess hook (A warn #6): restructured to ONE outer `bundle === 'community_group' && view_mode
  === 'default'` conditional, with the pre-existing links guard and a new sibling About guard as
  TWO independent inner `if` blocks — not merged into a single combined condition and not two
  separate outer conditionals. Confirmed via diff: this is a pure refactor-in-place of the existing
  block (no behavior change to the Links attach), plus a genuinely new sibling block for About.
- Seed data (Step 736): set About prose on the SAME 3 groups Step 735 already seeded Links for
  (DrupalCon Portland 2026, Core Committers, Thunder Distribution) — reusing well-known "flagship"
  groups keeps the prose thematically coherent and the E2E's positive case unambiguous. The other 5
  groups are left without About so the E2E negative case has real candidates. Idempotency guard
  checks `isEmpty()` (not existence), mirroring Step 735's exact idiom, so a re-run never duplicates
  or overwrites prose.
- Library attach test (`testLibraryAttachedOnlyWhenAboutNonEmpty`) is the ONE test that was RED at
  T's handoff and is now GREEN — confirms the `preprocessGroup` extension is the correct/complete
  fix for the RED signal T identified.

**Assumed:**
- None beyond what A/T already assumed — this pass had no open judgment calls left unresolved by
  the brief, A's plan review, or T's fixture; every YAML key value was read directly off T's
  `setUp()` fixture (the contract) rather than guessed.

**Hedged:**
- Form-display weight choice (10, not a value literally "between 1 and 3") is a deliberate deviation
  from the brief's literal fallback text, justified above — flagged here for O/A visibility even
  though it satisfies AC-4 (widget presence) without touching any AC that pins a specific form
  weight (no AC pins form weight; only the view-display's weight=10 is asserted, AC-3).

**Encountered but NOT fixed (out of scope, pre-existing):**
- `docs/groups/scripts/step_700_demo_data.php` has never been phpcs-clean (230 pre-existing errors
  at baseline vs. 240 after my +32-line append — the file-wide single-line-brace idiom this script
  has always used, not a style regression I introduced; proportionally my addition is at LOWER
  error density than the file's existing average, 0.31 err/line vs. 0.44 err/line baseline).
  Confirmed via isolated phpcs run against the pre-edit `git show HEAD:` copy in a scratchpad temp
  file (not committed). Not in this story's scope to clean up a pre-existing file-wide style debt.
- `DoGroupExtrasHooks.php` carries 4 pre-existing phpcs WARNINGS (2x `t()`-in-class at lines 42/45,
  2x `\Drupal::`-static-call-in-class at lines 162/164) — confirmed present at HEAD before my edit
  via `git show HEAD:...| grep`. I introduced and then fixed 1 NEW error (multi-line docblock short
  description) during this pass; 0 errors remain, only the 4 pre-existing warnings.

**Evidence:**
- `docs/planning/handoffs/141-about/handoff-F.md` — full command + output + diff summary.
- Kernel (target): `Tests: 8, Assertions: 192, Deprecations: 2` — 0 failures (was `Failures: 1` at
  T's RED handoff).
- Kernel (regression, full do_group_extras suite): `Tests: 27, Assertions: 809, Deprecations: 4` —
  0 failures, stable across both the pre- and post-docblock-fix runs.
- phpcs: `DoGroupExtrasHooks.php` → 0 errors / 4 pre-existing warnings (exit 1, warnings-only).
  `do_group_extras.libraries.yml` → 0 errors / 0 warnings (exit 0). Seed script → pre-existing
  file-wide style debt, not introduced by this change (proportional-density comparison above).

---
## T — Phase 6 (GREEN + Tier 2)

**Date:** 2026-07-23  **Verdict:** GREEN — 27/27 kernel (independently re-verified, identical to F's
numbers), 2/2 targeted E2E, 2/2 sibling E2E regression, 70/71 full E2E single-pass (1 pre-existing
unrelated skip). No blocking issues.

**Decided:**
- Fixed a genuine RED-time E2E spec bug (mine to own): `getByRole('heading', { name: /^About$/i })`
  never matched anything because Drupal's `field--label-above` template renders the field label as
  a plain `<div class="field__label">`, not a heading — confirmed against BOTH the live rendered
  markup (`curl` group/1) and the merged sibling spec's own precedent (`group-links.spec.ts` asserts
  via `getByText`, not `getByRole('heading', ...)`). Also discovered an unrelated "About" **tab
  link** in the group nav present on every group page regardless of About content, which an
  unscoped `getByText('About')` would have collided with. Rewrote both tests in
  `group-about.spec.ts` to detect presence/absence via the `.field--name-field-group-about` wrapper
  directly (matching the `group-links.spec.ts` idiom), then assert label + body text scoped inside
  that wrapper. This is a test-authorship fix, not a production defect — F's shipped markup is
  correct and idiomatic.
- Followed the canonical seeded-E2E sequence from `.github/workflows/test.yml`'s `e2e` job
  (site:install standard → uuid fix → config_sync_directory override → config:import → enable
  modules → seed step_700/720/790 as uid 1 → cache:rebuild), adapted from bare `php
  vendor/drush/drush/drush.php` to `ddev exec drush` since DDEV already serves the site (no separate
  `runserver` needed).
- Resolved a self-inflicted DB-state issue from repeated full-suite Playwright runs (accumulated
  fixture groups pushed seeded groups off page 1 of `/all-groups`) via a full fresh
  `site:install`+reseed, not partial SQL cleanup — a first attempt at raw `DELETE FROM groups WHERE
  id > 8` left orphaned rows in dependent field tables (`groups_field_data`, `groups_revision`),
  confirming entity-API-only deletion or a fresh install are the only safe cleanup paths for Group
  entities' multi-table storage.

**Assumed:**
- The brief's AC-7 wording ("About heading") is shorthand for "visible About section", not a literal
  requirement for an `<h2>` element — the established sibling convention (`field_group_links`) uses
  the same non-heading `field__label` div, and AC-8 (the actual heading-structure/WCAG requirement)
  is explicitly U's job, not T's. Flagged as an advisory note for S/U rather than treated as an AC-7
  failure.

**Hedged:**
- None — the E2E fix was verified by directly inspecting rendered HTML (not guessed), and the
  kernel re-verification was independent of F's reported numbers (matched exactly).

**Evidence:**
- `docs/planning/handoffs/141-about/handoff-T-green.md` — full command + output for kernel,
  seed sequence, E2E (before/after fix), Tier 1/2 tables, AC-by-AC status.
- Kernel: `Tests: 27, Assertions: 809, Deprecations: 4` — 0 failures, re-run twice (matches F
  exactly both times).
- E2E target: `tests/e2e/group-about.spec.ts` → 2/2 pass after fix (was 0/2 with the RED-time
  `getByRole` assertion, against the REAL seeded site — the RED-time E2E spec had never actually
  been run against a live site before this phase, per T-red's own note).
- E2E sibling regression: `tests/e2e/group-links.spec.ts` → 2/2 pass.
- E2E full single-pass: 70 passed / 1 skipped (pre-existing) / 0 failed.
- Diff: `tests/e2e/group-about.spec.ts` (+39/-23), no production file touched.

---
