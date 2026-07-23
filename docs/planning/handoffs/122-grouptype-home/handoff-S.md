# Handoff-S: Phase 9 — #122 SC-3 Group-type homepages (Spec Audit)

**Date:** 2026-07-22
**Branch:** `122-grouptype-home` @ `7de5d7b`
**Issue:** #122 (`Performant-Labs/groups-on-d11`)
**Handoff-A-dup reviewed:** `docs/planning/handoffs/122-grouptype-home/handoff-A-dup.md`
**Handoff-T-green reviewed:** `docs/planning/handoffs/122-grouptype-home/handoff-T-green.md`
**Handoff-F reviewed:** `docs/planning/handoffs/122-grouptype-home/handoff-F.md`
**Handoff-U reviewed:** `docs/planning/handoffs/122-grouptype-home/handoff-U.md`
**Brief:** `docs/planning/handoffs/122-grouptype-home/brief.md`

## Preconditions

- **A precondition:** PASS. A-dup returned PASS (cache-tag contract ✓, extend-not-parallel ✓, zero drift from Phase-3 binding guidance).
- **T precondition:** PASS. T-green reported zero blocking issues for #122; single remaining full-suite failure is `directory-cards.spec.ts` `#84` (pre-existing, unrelated, last touched in #105 — confirmed via `git log`).
- **U precondition:** PASS. Live walkthrough covered all 4 states + tooltip a11y + responsive 600px + fallback CSS-payload isolation at raw-aggregate-file level.
- **Visual-diff-tool precondition:** N/A — story review rigor is **`none`** per issue tail. Wireframe is text-only (ASCII); no reference PNG to diff against. U's screenshot evidence + T-green's DOM contract inspection are the agreed verification path for this rigor tier.

## Per-AC verification

| # | AC (from brief.md) | Status | Backing evidence |
|---|---|---|---|
| 1 | `preprocess_group()` derives `leading_section` from `field_group_type` term label per mapping | PASS | `groups_chrome.theme` — helper `_groups_chrome_leading_section_for_type()` at :225–232; called from preprocess :474. T-green DOM checks confirm correct mapping per exemplar. Fallback (Drupal France/Geographical) resolves to `null` — U verified count=0. |
| 2 | `theme_suggestions_group_alter()` returns `group__community_group__{x}_first` when set and `view_mode==='full'` | PASS | F's direct-hook-invocation self-check (definitive: temporary marker in ONE suggestion twig appeared only on DrupalCon page). Teaser view-mode correctly returns none. |
| 3 | Lead-section block renders ABOVE tab bar when set; nothing when null | PASS | `group--full.html.twig` — `.gc-group-lead` inserted as sibling between `.gc-group-header` and `.gc-group-tabs`; tabs `<nav>` reparented for true-sibling positioning (wireframe §2 explicit requirement). Fallback: T-green raw-HTML grep = 0 `.gc-group-lead`, 0 library reference. U verified byte-level. |
| 4 | Tooltip carries append-only HelpText `group_type.homepage_adapts`, focusable + AA-contrast | PASS | HelpText append-only: one key added at end of `all()` array, zero existing keys mutated (git diff verified). Tooltip DOM: `tabindex="0"`, `role="note"`, non-empty `aria-label`, `data-do-tooltip` — U focused it live, visible blue focus ring rendered. Contrast: reuses `.do-chrome-info` (#0067b8 on #ffffff = 5.36:1, ≥4.5:1 AA). |
| 5 | DrupalCon Portland 2026 leads with events | PASS | E2E events-first test GREEN; U live: "Upcoming events" heading + 2 event items + "See all events →" → `/group/1/events`. (2 items not 3 = seed count, correctly ≤ N=3.) |
| 6 | Core Committers leads with forum topics | PASS | E2E discussion-first test GREEN; U live: "Recent discussions" heading + 3 forum items + "See all discussions →" → `/group/3/nodes?type=forum` (matches A's Q1 resolution). |
| 7 | Thunder Distribution leads with documentation | PASS | E2E docs-first test GREEN (T-green rewrote from vacuous empty-state to positive assertion after F seeded 3 real doc nodes via Step 740d); U live: "Documentation" heading + 3 items + `/group/4/nodes?type=documentation`. |
| 8 | Unmapped/untyped group renders identically to today (no lead section, no library, tabs unchanged) | PASS | E2E fallback test GREEN; T-green raw-HTML grep: 0 `.gc-group-lead`, 0 `group-type-homepage` reference, tab-order `Stream/Events/Members/About` unchanged. U verified raw CSS-aggregate level (different hash on fallback page, contains ZERO `.gc-group-lead` rules — genuine conditional library-attach, not URL-string coincidence). |
| 9 | Existing E2E specs stay green | PASS (with advisory) | After T-green's `groupUrlByLabel()` `?search=` fix: 56 passed, 1 skipped (pre-existing `manage-members.spec.ts` #138 concern), 1 failed (`directory-cards.spec.ts` `#84` — last touched in commit `843a5b1`/PR#105, has never been touched by this branch, docblock-assumption bug unrelated to #122). Confirmed not a #122 regression. |
| 10 | New spec covers all 4 states + tooltip a11y | PASS | `tests/e2e/group-type-homepage.spec.ts` (344 lines) — 10/10 GREEN, one describe block per exemplar + fallback + tooltip focus/aria/tabindex check + 4 tab-order regression guards. Tests are non-tautological: T-red confirmed they failed at RED for the right reason (`.gc-group-lead` count=0 pre-feature). |
| 11 | WCAG 2.2 AA on new surface | PASS (with advisory) | Focus ring (visible, U-verified live), `tabindex="0"`, `role="note"`, non-empty `aria-label` (screen-reader announces full copy pre-JS), keyboard operability (plain `<a href>` items, no custom widgets), heading hierarchy (page `<h1>` untouched, lead `<h2>` is the only H2 — U verified), AA contrast reuses existing `.do-chrome-info` styling. **Advisory:** no automated `@axe-core/playwright` scan available (repo-wide gap, not #122's to introduce); manual + structural checks are the agreed verification path for `review rigor: none`. Given the surface is small (one new section + one tooltip trigger reusing an established a11y pattern) and every dimension has explicit human/DOM evidence, coverage is adequate. |
| 12 | phpcs passes on modified files | PASS | `groups_chrome.theme` fully clean (exit 0). `HelpText.php` net-improved (-1 error) vs. pre-#122 baseline; +2 warnings match established file style. Seed script (`step_700_demo_data.php`) not linted by CI (verified: no phpcs invocation in `.github/workflows/*.yml`) and follows sibling-array style. |
| 13 | Kernel/Functional PHPUnit stays green | PASS | 100/100 Kernel, 0 failures/errors (matches F's pre-implementation baseline). 59/59 Unit incl. the new `HelpTextTest` assertion for `group_type.homepage_adapts`. |

**All 13 AC bullets PASS.**

## "Owns (disjoint files)" spec compliance

`git diff origin/main...HEAD --name-only` inspected. Every source-code change lies inside declared "Owns":

- `web/themes/custom/groups_chrome/groups_chrome.theme` ✓ (owned)
- `web/themes/custom/groups_chrome/groups_chrome.libraries.yml` ✓ (owned; ONE new entry `group_type_homepage`)
- `web/themes/custom/groups_chrome/templates/group/group--full.html.twig` ✓ (owned)
- `.../group--community-group--{events,discussion,docs}-first.html.twig` ✓ (owned, new)
- `web/themes/custom/groups_chrome/css/group-type-homepage.css` ✓ (owned, new)
- `docs/groups/modules/do_chrome/src/HelpText.php` ✓ (owned, append-only)
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` ✓ (T-authored test for new key)
- `tests/e2e/group-type-homepage.spec.ts` ✓ (owned, new)
- `docs/groups/scripts/step_700_demo_data.php` — **not in "Owns" nor "does NOT touch"** — seed-script decision documented in F's handoff, accepted by T ("Prefer (a)") and A-dup (contained contrib-bug workaround, well-documented). Ships real Thunder-docs content so exemplar 3 has a non-vacuous positive assertion. Falls outside strict Owns but within brief's silence-zone; acceptable trade-off.
- `docs/planning/handoffs/122-grouptype-home/**` — handoff/screenshot artifacts, expected.

No stray touches: no other themes, no other Do modules, no gitignored artifacts committed (`web/modules/custom/**` and `config/sync/**` untracked in worktree, correctly not staged per instructions).

## Hard-constraint verification ("NO new routes, NO new fields")

- `git diff origin/main...HEAD -- '*.routing.yml' 'field.field.*' 'field.storage.*'` → **empty diff**. Constraint honored.
- No `.info.yml` module dependency additions in the diff.

## HelpText append-only rule

Diff on `HelpText.php` inspected: exactly ONE key appended (`group_type.homepage_adapts`), inserted at the tail of the `all()` array, guarded by rich `#122 (SC-3)` comment header. Zero existing keys mutated, zero deletions. Contract honored.

## Fallback contract (wireframe §7)

U's raw-CSS-aggregate check (not just URL string matching) is the strongest possible verification short of a formal DOM diff:
- Events-first page's `delta=1` CSS aggregate: contains `.gc-group-lead` rules.
- Fallback page's `delta=1` CSS aggregate: **different hash entirely**, contains ZERO `.gc-group-lead` matches.

This proves the library-attach conditional genuinely gates on `!empty($gc['lead_items'])`, not just that the page URL happens to differ. Byte-identity of fallback DOM confirmed by both T-green grep and U live inspection.

## Existing-suite / directory-cards flake

Verified pre-existing, not a #122 regression:
- `tests/e2e/directory-cards.spec.ts` last touched in `843a5b1` (PR #105, "feat: #84 group directory cards"). This branch does not touch that file (`git diff origin/main...HEAD -- tests/e2e/directory-cards.spec.ts` → empty).
- Root cause is the shared long-lived `gm122-groups-on-d11` DDEV instance accumulating ~93 fixture groups from repeated full-suite runs, invalidating `#84`'s docblock assumption ("8 groups, no pagination"). CI runs a fresh `site:install` each run so the vector doesn't exist in CI.
- Independently reproducible with zero #122 changes in play.

**Advisory (non-blocking, for O's tracking):** `#84` test needs a small fix (either `?search=` scoping like T applied to `groupUrlByLabel()`, or a per-run reseed) — belongs to a separate ticket, not #122's remit.

## Test-quality audit (rubric per `testing/test-quality.md` §7)

`tests/e2e/group-type-homepage.spec.ts` (10 tests, 344 lines):
- **Per test:** each names one behavior (per-exemplar lead-section content; fallback silence; tooltip a11y; per-page tab-order regression guard). Fail-in-isolation for the right reason: T-red confirmed all failed at RED because `.gc-group-lead` was absent, not because of setup/import errors. Sit at the right tier (E2E for a rendered-DOM story). Assert behavior (rendered content, keyboard focus, DOM absence), not implementation.
- **Per suite:** 10 tests proportionate to the story (4 states × ~1.5 tests + regression guards + a11y). Not over-produced; not padded.
- **Non-vacuous:** the Thunder test was correctly rewritten from a weak `toHaveCount(0)` empty-state pin to a positive docs-first assertion after F seeded real content — exactly the trade-off T-red flagged in advance.
- **Unit-level:** new `HelpTextTest` assertion for the new key is one focused test, asserts the copy names all three variants (not just "key exists"), non-tautological.

No "delete or merge" findings. No mock-shaped or snapshot-everything smells.

## Scope check

F delivered exactly the phase scope:
- All 4 render states (events / discussion / docs / fallback) ✓
- Tooltip with append-only HelpText key ✓
- Preprocess + theme suggestions only, zero new routes/fields ✓
- E2E spec ✓
- Kernel/Unit stays green ✓

Only minor scope-adjacent addition: the Step 740d seed script block, which is a functional necessity for AC #7 (docs-first exemplar) to have real content to render. T-authored E2E updates + T-green's `groupUrlByLabel()` `?search=` hardening are within T's remit.

## Advisories (non-blocking, for O)

1. **`directory-cards.spec.ts` #84 flake** — separate ticket, not #122's; either apply `?search=` scoping to that spec's helpers or reseed the shared instance between runs. Doesn't block #122 merge.
2. **`.ddev/config.yaml` project-name rename to `gm122-groups-on-d11`** — still uncommitted per task instructions. O decides whether to restore before PR or accept as-is.
3. **Uncommitted gitignored/local artifacts in worktree** (`web/modules/custom/**`, `config/sync/*.yml`, `.editorconfig`, etc.) — expected assembled-config artifacts; not to be committed. Confirmed not staged.
4. **No automated axe scan** — repo-wide tooling gap, out of #122 scope. AA verification satisfied by structural + manual evidence per `review rigor: none`. If #145 (the WCAG backstop story) later introduces `@axe-core/playwright`, this surface should be re-scanned then.
5. **Group 4.0.x contrib bug workaround** in the seed script is well-documented and contained; worth adding to the project's Group-4.0.x gotcha list (F flagged this in handoff-F.md).

## Verdict

**PASS.**

All 13 acceptance criteria are met, all hard constraints ("no new routes, no new fields, append-only HelpText") honored, "Owns (disjoint files)" respected, fallback contract byte-identical, extend-not-parallel discipline maintained (A-dup PASS), cache-metadata contract complete (A-dup PASS), tests are non-tautological and proportionate to scope, WCAG 2.2 AA structurally met on the new surface, existing suite stable (the one full-suite failure is a pre-existing #84 flake independently reproducible without #122).

Ready for O to open the PR against `main`. Human (aangelinsf) merges.
