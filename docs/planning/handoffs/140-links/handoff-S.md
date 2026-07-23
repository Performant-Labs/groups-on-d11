# Handoff-S: Phase 9 — #140 MC-1 Links & Resources (final spec audit)

**Date:** 2026-07-23
**Branch:** 140-links
**Worktree:** ~/Projects/_worktrees/groups-links
**Diff base:** origin/main   **Diff head:** HEAD (140-links)
**Issue:** #140 (Epic #137, MVP source #3578787)

**Verdict:** **PASS**

## Precondition checks

| # | Precondition | Status | Evidence |
|---|---|---|---|
| 1 | A (plan) verdict = PASS | OK | `handoff-A-plan.md` — "PASS (with 3 warns to close during T RED authoring)". All 3 warns (#4/#5/#6) tracked and satisfied: W-4 (comment header + alphabetized dependencies), W-5 (rendered-HTML assertion, mechanism-free), W-6 (`label: above` = section H2, empty-state by construction). |
| 2 | A-dup (anti-duplication) verdict = PASS | OK | `handoff-A-dup.md` — all 10 findings PASS; extension seam confirmed on `preprocessGroup()`; no parallel path; new Full-display file confirmed genuinely new; no `web/modules/custom` or `config/sync` artifacts staged. |
| 3 | T (structural / GREEN) has zero blocking issues | OK | `handoff-T-green.md` — kernel 7/7 GREEN (165 assertions); no-regression 118 tests, 3258 assertions, 0 failures; E2E 2/2 GREEN on live seeded DDEV; full-suite E2E 63 passed with 1 unrelated pre-existing failure in `group-restore.spec.ts` (#143's story, not touched by #140 diff); phpcs 0 new issues; assemble-config.sh exits 0. Two test-authorship bugs T found + fixed in-phase (not deferred). |
| 4 | U (UI walkthrough) verdict = PASS | OK | `handoff-U.md` — all 8 required + 1 optional scenarios PASS on live seeded DDEV, zero console errors. One WCAG advisory (heading semantics) flagged for S — resolved below in "U advisory resolution". |

Skipping browser-tool + visual-diff-tool preconditions: this is a spec-conformance audit on a story already driven live by U with screenshots. No further pixel-diff needed at S — U already ran the rendered surface end-to-end.

## Preview / spec sanity check

Re-read `gh issue view 140` against the delivered scope. Spec is internally consistent, matches Drupal 11 conventions (core `link` field, cardinality -1, `label: above`, formatter-owned rel/target), and does not contradict the MVP upstream (`#3578787` — Links & Resources as a native field on the group entity). No spec-defect found; not returning ADVISORY-HOLD.

## Verdict per acceptance criterion

Numbered per the task prompt's 10-criterion list.

| # | Acceptance criterion | Verdict | Evidence |
|---|---|---|---|
| 1 | `field_group_links` storage + instance exist | **PASS** | Storage: `docs/groups/config/field.storage.group.field_group_links.yml` — `type: link`, `cardinality: -1`, `entity_type: group`. Instance: `field.field.group.community_group.field_group_links.yml` — `bundle: community_group`, `label: 'Links & Resources'`, `required: false`, `settings: {link_type: 17, title: 2}`. Kernel `testStorageExists`, `testInstanceExists` pin these values (7/7 GREEN). |
| 2 | Organizer/editor form shows the field | **PASS** | `core.entity_form_display.group.community_group.default.yml` — `field_group_links` component present, `type: link_default`, `weight: 4`, `region: content`. Kernel `testFormDisplayShowsField` asserts widget type. U walkthrough scenario 9 verified live: 2 populated URL/Link-text delta rows + 1 empty extra row + "Add another item" button + "Link text *" required marker on `/group/1/edit` (uid=1). |
| 3 | Anonymous visitor sees rendered links on a seeded group | **PASS** | E2E `group-links.spec.ts` test 1 passes GREEN against live seeded DDEV — `/all-groups` → click DrupalCon Portland 2026 card → asserts "Links & Resources" text visible + at least one of the canonical seeded titles renders as an `<a>`. U walkthrough scenario 2 verified live with both titles visible (`screens/links-section.png`). |
| 4 | External links carry `rel="noopener"` + safe target | **PASS** | Formatter settings in Full display: `rel: noopener`, `target: _blank`. Kernel `testRendersExternalLinkWithRelNoopener` asserts observable HTML (`<a … href="https://external.example.com" … rel="noopener…" … target="_blank">`). E2E test 2 scopes locator to `.field--name-field-group-links a[href^="http"]` (correctly rescoped by T from a page-wide sweep that had caught Olivero's own "Powered by Drupal" footer link — that fix is real evidence T pinned the right surface). U walkthrough scenario 6 verified via `querySelectorAll(...)` on both live seeded links: both `rel="noopener"`, both `target="_blank"`. Mechanism check: verified by F reading core `LinkFormatter::buildUrl()` — `rel`/`target` settings write verbatim into `$options['attributes']` and `AttributeXss::sanitizeAttributes()` whitelists `rel`. No `preprocess_field` fallback needed; the simpler formatter-settings mechanism holds. |
| 5 | Empty state renders nothing (no bare header) | **PASS** | Kernel `testEmptyStateRendersNothing` asserts `Links & Resources` text NOT in rendered HTML on a group with no links, and no `<h2>Links` / `<label>Links` regex match. U walkthrough scenario 7 verified live on TWO separate unlinked groups (Leadership Council + Camp Organizers EMEA) — no heading, no wrapper markup, no visual gap (`screens/no-links-group.png`). Holds by construction: `label: above` IS the section heading; Drupal core suppresses the whole field wrapper (label included) when zero deltas exist — per A's plan warn #6. |
| 6 | WCAG 2.2 AA: discernible names + keyboard reachable | **PASS** | Discernible names: `title: 2` (LinkTitleVisibility::Required) enforces a title per delta at field-config level; kernel `testInstanceExists` pins this (regression guard added per O's W-1 acceptance). Seeded data uses descriptive titles ("Conference schedule", "Sponsorship info", "Core Gitlab", etc.) — no "click here" / raw-URL text. U walkthrough scenario 4 verified live. Keyboard reachable: U scenario 5 drove real `Tab` keypresses (22 tabs from page top → landed on "Conference schedule"), confirmed visible focus outline (`outline: 2px solid rgb(27,154,228)` — Olivero token) with screenshot `screens/keyboard-focus-conference-schedule.png` and `screens/focus-state.png`. `.field--name-field-group-links a:focus-visible` rule in `group-links.css` provides a defensive 2px `#005fcc` outline as belt-and-suspenders behind Olivero's token. See "U advisory resolution" below for the heading-semantics question. |
| 7 | Existing suite green | **PASS** | Kernel no-regression: `Tests: 118, Assertions: 3258, Deprecations: 28` across all 11 custom modules — zero `Failures:` line. F's Phase 5 run on the same command showed `Failures: 6` (all in `GroupLinksFieldTest`); T's setUp fix eliminated all 6, zero new regressions elsewhere. E2E full-suite: 63 passed, 1 unrelated pre-existing failure in `group-restore.spec.ts` (#143's story — confirmed via `git diff --stat` that neither `RestoreGroupForm.php` nor `group-restore.spec.ts` are in #140's diff), 1 skipped. |
| 8 | Playwright asserts seeded link renders | **PASS** | `tests/e2e/group-links.spec.ts` — 2 tests, both GREEN against live seeded DDEV. Test 1 asserts the seeded title renders as a visible `<a>` on the DrupalCon Portland 2026 group page. Test 2 asserts `rel="noopener"` on all external links inside the field wrapper. |
| 9 | Files-owned match the "Owns (disjoint files)" list | **PASS** | Issue's owned list vs diff: (a) `field.storage.group.field_group_links.yml` new ✔; (b) `field.field.group.community_group.field_group_links.yml` new ✔; (c) group Full display config — `core.entity_view_display.group.community_group.default.yml` new (coordinated for #141: reserved-weight-10 comment + alphabetized dependencies + minimal `hidden:` block) ✔; (d) subtheme CSS — actually shipped as `docs/groups/modules/do_group_extras/css/group-links.css` (module CSS instead of subtheme CSS — architecturally equivalent, keeps the styling in the module that owns the field's render seam; O-approved via A-dup finding #2/#3) ✔; (e) `step_700_demo_data.php` append-only step 735 ✔; (f) `tests/e2e/group-links.spec.ts` new ✔. Additionally touched (not on the "owns" list but permitted extensions in `do_group_extras`): `do_group_extras.info.yml` (+`drupal:link` alphabetized), `do_group_extras.libraries.yml` (+`group-links` sibling), `src/Hook/DoGroupExtrasHooks.php` (+13 lines, extending the existing `preprocessGroup()` seam in place — not a new hook), plus new `tests/src/Kernel/GroupLinksFieldTest.php` and handoff docs. A-dup finding #10 confirmed all edits fully within `do_group_extras`'s charter; no shared-surface drive-by. |
| 10 | #141 About coordination: shared Full display authored for clean insertion | **PASS** | `core.entity_view_display.group.community_group.default.yml` explicitly reserves weight 10 via `# (weight 10 reserved for #141 About)` comment between weight-2 image and weight-20 links. `dependencies.config` is alphabetized so #141 inserts one line for `field.field.group.community_group.field_group_about` at the correct sort position without touching siblings. `hidden:` block is minimal (only `field_group_language`, `field_group_type`). Header comment marks section-marker comments as source-tree-only (stripped on `drush cex`) — forestalls the drush-cex round-trip footgun A's Phase 3 warn #4 raised. A-dup finding #5 explicitly verified: "Zero sibling keys need reordering." |

## Test-quality audit (rubric per playbook §7)

| Test | Names one behavior? | Cheapest sufficient tier? | Asserts behavior not implementation? | Would fail if reverted? |
|------|---|---|---|---|
| `testStorageExists` | Y — storage config presence + type + cardinality | Y — kernel is the cheapest for config shape | Y — asserts type/cardinality, not YAML file bytes | Y — deleting the storage YAML would fail this |
| `testInstanceExists` | Y — instance config presence + label + required + title setting | Y | Y — asserts the observable field config values | Y — W-1 pinning of `title: 2` guards the acceptance-criterion deviation from the task-prompt literal |
| `testFullDisplayShowsField` | Y — the field is a non-hidden component on default view display | Y | Y | Y |
| `testFormDisplayShowsField` | Y — form widget presence + type | Y | Y — asserts widget type name (`link_default`), the meaningful behavior | Y |
| `testRendersExternalLinkWithRelNoopener` | Y — external anchor carries the two required attributes | Y — kernel render is cheaper than E2E for HTML-shape assertion | Y — asserts on rendered HTML regex, mechanism-agnostic per A's warn #5 | Y — mutation spot-check by T (`rel: noopener` → `rel: mutated-none`) confirmed FAIL then GREEN on revert |
| `testInternalLinkRendered` | Y — internal link renders with title text in an `<a>` | Y | Y | Y |
| `testEmptyStateRendersNothing` | Y — group with no links has no Links & Resources markup | Y | Y — asserts rendered HTML negative | Y — if `hide_empty` behavior regressed, this would fail |
| `group-links.spec.ts` test 1 (E2E) | Y — anonymous can see section + seeded link | Y — E2E is the right tier for end-to-end anonymous rendering on seeded data | Y — asserts visible section text + role="link" name | Y |
| `group-links.spec.ts` test 2 (E2E) | Y — external links in field wrapper carry `rel="noopener"` | Y — E2E confirms observable rendered attribute on the live seeded page | Y — scoped to `.field--name-field-group-links` (T's fix — not a page-wide sweep) | Y |

**Suite proportionality:** 7 kernel tests + 2 E2E tests for a feature story that adds one field, one Full display, one form display, one seed-data step, and one library. Ratio is proportionate — each test guards a distinct acceptance criterion; no fan-out re-proving the same branch; no assertion-free/tautological tests; no snapshot-everything patterns. No "delete or merge" findings.

**Notable test-quality strengths worth calling out:**
- T re-scoped the E2E `rel="noopener"` locator from a page-wide sweep to the field wrapper — caught a real defect in T's own test (which had been failing against Olivero's "Powered by Drupal" footer link) and fixed it in-phase rather than deferring. That is exactly the right instinct.
- T ran a mutation-sensitivity spot-check on the kernel suite (`rel: noopener` → `rel: mutated-none`, confirmed FAIL, reverted) — proves the tests pin real behavior, not vacuous config presence. This is above the pipeline bar.
- W-1 (`assertSame(2, $instance->getSetting('title'))`) is a targeted regression pin on the exact explicit deviation from the task prompt (which said `title: 1`) — small cost, high signal.

## Quality audit

| Area | Result | Notes |
|------|--------|-------|
| API consistency | N/A | No new API surface. |
| Error handling | PASS | Seed step 735 is idempotent (skips groups already carrying links); missing-group/missing-field paths log and continue. |
| UI/UX match to spec | PASS | Matches the "render a Links & Resources section on the group Full display" spec; positioned at weight 20 (below Description=0, Visibility=1, Image=2, About-reserved=10). |
| Accessibility | PASS | See criterion #6 above + U advisory resolution below. |
| Architecture gate | PASS | A-plan PASS + A-dup PASS. No re-litigation needed at S. |
| Code organization | PASS | `preprocessGroup()` extended in place at the exact seam that already attaches the archived-group library — same conditional-attach pattern. Section-marker comments in the Full-display YAML labeled as source-tree-only. |
| Security | PASS | External links carry `rel="noopener"` (window.opener isolation) + `target="_blank"`. `title: 2` (required) prevents empty-title links whose accessible name would leak the raw URL. No user-supplied HTML rendered outside core's link-field XSS filtering. |
| Performance | PASS | W-2 conditional attach: library is attached only on `community_group` + `view_mode=default` + `hasField` + `!isEmpty` — no CSS on empty-state pages or Teaser view mode. No new render-path cost beyond core's field render. |
| Visual regression | N/A | POC story; no VR baselines in the project. U walkthrough with screenshots substitutes at this tier. |
| Naming consistency | PASS | `field_group_links` matches sibling `field_group_description`, `field_group_visibility`, `field_group_image` naming. `group-links` library / `group-links.css` sibling names track the field name. |
| Test quality (playbook §7) | PASS | See rubric table above. |

## Scope check

Delivered exactly the phase scope. No under-delivery (all acceptance criteria satisfied with evidence). No over-delivery — F did not add a preprocess-field fallback speculatively (formatter settings sufficed); no HelpText.php entry added (correctly, per A's finding #8); no new module; no touched `field_group_about` (#141's territory).

The one minor deviation from the issue's "Owns" list is delivering the CSS as **module CSS** (`docs/groups/modules/do_group_extras/css/group-links.css`) rather than **subtheme CSS**. This is architecturally equivalent (keeps the styling in the module that owns the field's render seam via `preprocessGroup()`), was approved through A-dup finding #2/#3, and does not affect any acceptance criterion. Noted for the record, not a defect.

## U advisory resolution — WCAG heading semantics

U flagged: "Links & Resources" field label renders as `<div class="field__label">`, not `<h2>`/`<h3>`. Question: does this violate WCAG 2.2 AA?

**Resolution: not a WCAG 2.2 AA violation. Consistent with S PASS verdict.**

Reasoning:
1. **Spec (#140) does not require a semantic heading.** The acceptance criterion reads: "WCAG 2.2 AA for the added UI (**links** have discernible names; keyboard reachable)." It specifies *link* accessibility, not section-heading semantics. Both link-level criteria are satisfied (discernible names via `title: 2` required + descriptive seeded titles; keyboard reachable via real `Tab` traversal + visible focus outline).
2. **WCAG 2.2 AA does not require section headings.** SC 2.4.6 ("Headings and Labels", Level AA) requires labels/headings to *describe topic or purpose* — the field-label text "Links & Resources" satisfies this. SC 2.4.10 ("Section Headings") is **Level AAA, not AA**, so it does not apply to the "WCAG 2.2 AA" spec bar.
3. **SC 1.3.1 ("Info and Relationships", Level A)** is not violated: the label's association with the list is programmatically determinable through DOM proximity within the same `field__` wrapper — this is standard Drupal field-render markup relied on by screen readers across the ecosystem.
4. **Site-wide convention, not #140 regression.** U confirmed the `<div class="field__label">` markup is byte-identical to the sibling `Visibility` field label on the same page, and no other field label on the group page (Description, Visibility) uses a real heading tag either. This is Drupal core's default `label: above` markup, not new debt introduced by this story.
5. **A's Phase-3 plan used "H2" as a conceptual/visual descriptor.** A's warn #6 explicitly said "H2 source = field's own `label: above` setting" — meaning the field's own label is the visual/conceptual section marker, not that a literal `<h2>` element is required.

If a future story wants heading-level semantic markup for field labels site-wide (e.g. add `#label_tag => 'h2'` or a preprocess-field override), that is a separate epic and a site-wide theme change, not a #140 blocker. **Not ADVISORY-HOLD** — verdict stands as PASS.

## Advisory notes (non-blocking, informational for future stories)

1. **Consider a site-wide field-label heading convention.** WCAG SC 2.4.10 (AAA) would be aided by semantic headings on major group-page sections (Description, Links & Resources, About). This is a site-wide theme change (not #140-local), and current AA compliance is intact without it. Worth considering as a future accessibility polish epic.
2. **#141 coordination is set up cleanly** — no action needed, just calling out that the reserved-weight-10 slot and alphabetized `dependencies.config` mean #141 can insert its `field_group_about` component + one dependency line without touching any siblings.
3. **Seed idempotency pattern** (step 735 skips groups already carrying links) is a good template for future append-only demo-data steps. Worth borrowing.

## Verdict

**PASS** — all 10 acceptance criteria met with cited evidence, spec-compliant, quality acceptable, WCAG 2.2 AA satisfied for the story's scope. Ready for O to commit.
