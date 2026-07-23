# Handoff-S: Phase 9 — #141 MC-2 About section (final spec audit)

**Date:** 2026-07-23
**Branch:** 141-about   **Worktree:** ~/Projects/_worktrees/groups-about
**HEAD:** 3c2d248
**Issue:** #141 (Epic #137, MVP conformance)
**Handoffs reviewed:** brief.md, survey.md, decisions.md, handoff-A-plan.md,
handoff-T-red.md, handoff-F.md, handoff-T-green.md, handoff-U.md
**Spec (source of truth):** `gh issue view 141` — POC bar (4 lines) + brief's 10 operational ACs

## Verdict

**PASS** — story ready to PR.

## Summary

Every one of the 10 operational ACs from the brief is backed by a named test with
independently-reproduced evidence in the T-green and U handoffs. The shipped diff is tight
(6 commits, 141-only slice = 9 production/handoff files + 145 lines of E2E + 329 lines of
kernel test + 3 seeded groups of prose + 10 screenshots), source-only (zero
`web/modules/custom/**`, zero `config/sync/**`, zero `.ddev/config.yaml`), and the pattern
mirrors the merged #140 sibling faithfully. Two deviations were flagged upstream and are
both defensible on their merits:

1. **Form-display weight = 10** (not brief's fallback "3 or 4"). Brief's fallback assumed
   description weight = 0, but F actually read the file and found description = 1, and 2/3/4
   were already occupied by siblings the brief forbids touching. No AC pins form weight; only
   AC-3 pins view-display weight (=10, correctly). Deviation is from brief's literal
   fallback text, not from any AC or from intent. **Accepted.**
2. **About renders as `<div class="field__label">`, not `<h2>`.** This is the site-wide
   Drupal convention for `label: above` field rendering; the merged #140 Links & Resources
   field uses the exact same shape and its S audit accepted it. Axe raised zero
   heading-order / empty-heading / landmark violations against either the with-About or
   without-About pages. The one axe finding (color-contrast on the `.gc-badge--success`
   status badge) is pre-existing site chrome present identically on both pages, not a
   #141-introduced regression. If a true semantic `<h2>` for field-label sections is
   desired, that is a cross-cutting decision affecting both #140 and #141 equally and
   belongs in #145 (WCAG audit), not this story. **Accepted as advisory carry-forward, not
   blocking.**

Diff shape confirmed source-only: `git diff 28d6980^..HEAD` for the 141-only slice touches
`docs/groups/config/**` (3 files: 2 new field YAMLs + 2 shared display file edits + 1 new
CSS + 1 libraries edit + 1 hooks edit + 1 seed edit), `docs/groups/modules/do_group_extras/**`
(4 files), `docs/groups/scripts/step_700_demo_data.php` (+32 append-only lines),
`tests/e2e/group-about.spec.ts` (new, 145 lines), the kernel test (new, 329 lines), and the
`docs/planning/handoffs/141-about/**` handoff docs — nothing else.

## A precondition

Confirmed — `handoff-A-plan.md` returned **PASS** with 3 warns (all folded into brief and
resolved by T's RED tests). No BLOCK.

## T precondition

Confirmed — `handoff-T-green.md` reports **zero blocking issues**. All Tier 1 and Tier 2
checks pass. Advisory items are the div-vs-h2 note (adjudicated above) and dev-DB hygiene
(pre-existing, unrelated to #141).

## Visual-diff-tool precondition

**Skipped** — this is a Drupal 11 field-add story audited under the project's
`PROJECT_CONTEXT.md` override, not a Node/npm visual-regression story. U ran axe + full
DOM/screenshot walkthrough live on the seeded DDEV site (10 screenshots at desktop +
360-px mobile), which is the appropriate rigor for this surface. No Playwright VR baselines
exist for this project; per the project override, seeded-site E2E + axe is the equivalent
gate.

## Per-AC audit

| # | AC text (abridged) | Test coverage | Evidence | Verdict |
|---|---|---|---|---|
| 1 | `field_group_about` storage: `text_long`, cardinality 1, translatable | `GroupAboutFieldTest::testStorageExists` (kernel) | T-green table row 1; storage YAML verified: `type: text_long`, `cardinality: 1`, `translatable: true` (`field.storage.group.field_group_about.yml` lines 11, 15, 16) | **PASS** |
| 2 | Instance on `community_group`, label "About", not required, translatable | `testInstanceExists` (kernel) | T-green table row 2; instance YAML verified: `bundle: community_group`, `label: About`, `required: false`, `translatable: true` (`field.field.group.community_group.field_group_about.yml` lines 13-17) | **PASS** |
| 3 | Full display: `weight: 10`, `label: above`, formatter `text_default` | `testFullDisplayShowsField` (kernel) | T-green table row 3 + `curl group/1` confirmation showing `field--label-above` wrapper; view-display YAML: weight 10, label above, type text_default (lines 55-61 of shipped file) | **PASS** |
| 4 | Form display: widget `text_textarea`, non-hidden | `testFormDisplayShowsField` (kernel) | T-green table row 4; form-display YAML: `type: text_textarea, weight: 10` (see Deviation #1 for weight choice) | **PASS** |
| 5 | Formatted body via `basic_html` produces sanitized rich HTML (`<strong>`) in wrapper | `testRendersFormattedBody` (kernel) + live curl on group/1 | T-green table row 5; T-green "Rendered-HTML confirmation" block shows literal `<strong>This group coordinates the planning committee's work</strong>` in the field wrapper | **PASS** |
| 6 | Empty state (both shapes: never-set and `[value=>'',format=>'basic_html']`): no "About" label, no bare wrapper | `testEmptyStateRendersNothingWhenFieldNeverSet` + `testEmptyStateRendersNothingWhenValueExplicitlyEmpty` (kernel) + live check on group/2 and group/5 | T-green table row 6; U handoff §"Non-seeded groups" table shows wrapper count = 0 and "About" label absent on 2 unseeded groups (the sole "About" match is the unrelated `gc-group-tabs__link` nav tab) | **PASS** |
| 7 | E2E: anonymous visitor sees About + prose on seeded group | `tests/e2e/group-about.spec.ts` (test 1) + U live walkthrough on 3 seeded groups | T-green E2E results: 2/2 green after T's own selector fix (getByRole → wrapper-scoped, matching #140 sibling convention); U handoff table shows all 3 seeded groups render wrapper + label + bold emphasis | **PASS** |
| 8 | WCAG 2.2 AA: heading structure, contrast, no empty landmarks | U walkthrough (axe-core 4.10.2, wcag2a/aa/22aa tags) | U handoff §"AC-8": 0 axe violations attributable to About; scoped re-run against `.field--name-field-group-about` = 0 violations; no empty About landmark (correctly absent when empty); div-vs-h2 note recorded as advisory (see Deviation #2) | **PASS** (advisory carry-forward) |
| 9 | Existing kernel + functional + E2E suites remain green | Full `do_group_extras` kernel (27/27) + full E2E single-pass (70/71, 1 pre-existing skip) | T-green Tier 1 table + independent re-run matching F's numbers exactly | **PASS** |
| 10 | Source-only commits (no `web/modules/custom/`, no `config/sync/`) | O verification | `git diff main...HEAD` for 141-only slice (via `28d6980^..HEAD`): zero forbidden paths; F/T handoffs both confirm; independently re-verified during this audit | **PASS** |

## Deviations flagged

| # | Item | Verdict | Rationale |
|---|---|---|---|
| 1 | Form-display weight = 10 (brief fallback said 3-or-4) | **Accept** | Brief's fallback was based on a wrong assumption (description form weight = 0; actual = 1, and 2/3/4 occupied by siblings the brief forbids touching). No AC pins form weight. F read the file first, chose 10 to mirror view-display's semantic "weight 10 = About" and place About last on the edit form without touching siblings. Documented in handoff-F.md, decisions.md, and re-verified in shipped YAML. Correct engineering judgment; brief text was the defect, not F's choice. |
| 2 | About renders as `<div class="field__label">`, not `<h2>` | **Accept as advisory carry-forward** | Site-wide Drupal convention for `label: above` field rendering. Merged #140 Links field uses the same shape; its S audit accepted it. Axe raises no heading-order / empty-heading / landmark violation. If semantic `<h2>` is desired, it is a cross-cutting decision belonging in #145 (WCAG audit), affecting both #140 and #141 equally. NOT REWORK for this story. |

## Missed opportunities / holes

None identified. Reviewed against issue POC bar (4 acceptance lines) and brief's 10 ACs:

- Anonymous visitor sees formatted About on seeded group: **yes** (E2E + U live).
- Empty state renders nothing jarring: **yes** (both kernel shapes + 2 live no-About groups).
- WCAG 2.2 AA: **yes** (axe clean for About surface).
- Existing suites green + Playwright About assertion: **yes** (70/71 full E2E, 27/27 module kernel).

Coverage is proportionate: 8 kernel tests (7 shape/render + 1 library-attach behavior guard
that was the actual RED signal), 2 E2E tests (positive + negative visibility on live seeded
site). No test-quality smells — every test names one behavior, every test would fail in
isolation for the right reason (RED-time evidence proves this for the library-attach test;
the config/render tests would fail without shipped YAML/preprocess, proven by their being
RED before F's commit landed). No delete-or-merge findings.

## Notes for O before PR

1. Story is a tight, on-pattern extension of the #140 Links precedent. No architectural
   surprises. No forbidden-file leaks. Pre-existing phpcs debt in
   `step_700_demo_data.php` and 4 pre-existing `DoGroupExtrasHooks.php` warnings are
   correctly out of scope for this story (F confirmed via `git show HEAD:` isolation).
2. Two deviations both accepted above; neither warrants rework and neither is a spec/preview
   defect that would trigger ADVISORY-HOLD (Deviation #1 is a defect in the brief's
   fallback text that F correctly reasoned around; Deviation #2 is a site-wide precedent
   already accepted at #140 merge).
3. The `.ddev/config.yaml` project-name rename (`gm141-about`) remains uncommitted per the
   established convention across the wave — leave it that way.
4. When #145 (WCAG audit) opens, consider whether field-label div → semantic `<h2>` is a
   platform-wide improvement worth making across `field_group_links`, `field_group_about`,
   and any future `label: above` field. Would be a one-line preprocess or template override
   touching both #140 and #141 uniformly.

## Recommendation

Merge on green CI. The story is done in letter and spirit.
