# Handoff-S: Phase 9 — #124 SC-5 Directory compact-list vs cards toggle (final spec audit)

**Date:** 2026-07-23
**Branch:** `124-directory-toggle`
**Worktree:** `C:\Users\aange\Projects\_worktrees\groups-directory-toggle-124`
**DDEV project:** `gm124-directory`
**Handoffs reviewed (in order):** issue #124, `brief.md`, `wireframe.md`, `survey.md`, `decisions.md`,
`handoff-D.md`, `handoff-A-plan.md`, `handoff-T-red.md`, `handoff-F.md`, `handoff-T-green.md`,
`handoff-U.md`, plus the actual staged diff.

**Verdict:** **PASS.** Ready for rebase + PR.

---

## 1. AC-coverage matrix (brief.md ↔ evidence)

| # | brief.md AC (paraphrased) | Backing test / walkthrough | Status |
|---|---|---|---|
| 1 | Switcher renders at top of `/all-groups`, "Viewing:", 3 options in order (Compact list / Cards default / Map "(soon)" + `aria-disabled`) | Kernel `testSwitcherInjectedWithThreeOptionsInOrder` (repaired via `renderViewToHtml()`); E2E "switcher renders with three options…"; U Surface 1 (all attributes captured live) | PASS |
| 2 | Selecting Compact list switches to dense rows, no full reload, filters/paging preserved, same URL/query | E2E "clicking Compact list flips the wrapper attribute live"; E2E "filters are preserved across a toggle both ways"; U Surface 2 (0 network requests on toggle; URL unchanged; row count 7↔7 with `?search=a`) | PASS |
| 3 | Selecting Cards restores card presentation byte-identically (no wrapper class / no compact CSS matches when `="cards"`) | E2E toggle-back; `directory-cards.spec.ts` 100% green; U "s2-cards-back.png" (snippet re-appears, wrapper `="cards"`) | PASS |
| 4 | Session persistence via SC-F1 sessionStorage key `doShowcase.variant.directory.layout` | E2E "session persistence: compact selection survives a reload; a fresh session defaults to cards"; U reload + incognito checks | PASS |
| 5 | `?variant=` wins over sessionStorage (no-JS fallback) | E2E "`?variant=compact` URL wins over a stale sessionStorage value of cards"; U fallback table (4 URL variants all correct) | PASS |
| 6 | Compact rows show same access-filtered set (pure presentation, no query change) | Kernel `testCompactQueryParamSetsWrapperToCompact` (attribute-only); E2E row-count identical; U (row count 7 both ways) | PASS |
| 7 | ⓘ tooltip renders shipped `showcase.switcher.directory.layout` HelpText copy | Reused verbatim from #119; U Surface 1 confirms `aria-label` + `data-do-tooltip` both carry the exact shipped copy | PASS |
| 8 | `/showcase` catalog entry `directory-presentation` flips `coming → live`, routes to `view.all_groups.page_1` | Unit `testDirectoryPresentationEntryIsLive`, `testDirectoryPresentationEntryRoutesToAllGroupsPage`; U regression walk (3 live / 4 coming, was 2/5) | PASS |
| 9 | `tests/e2e/directory-toggle.spec.ts` toggles both ways, preserves filters, persists via reload | 12 tests (11 pass + 1 correct conditional skip) | PASS |
| 10 | Existing suites stay green (`directory-cards`, `directory-filters`) | Full E2E regression 40/40 (39 pass + 1 skip); kernel+unit full do_showcase 68/68 | PASS |
| 11 | WCAG 2.2 AA — labels, keyboard, focus, contrast, non-color status | E2E WCAG-adjacent smoke; U Surface 1 keyboard walk + focus ring + role/aria; U contrast measurement (see §5) | PASS w/ advisory (§5) |
| 12 | HelpText backstop honored — no new entry needed | By construction — copy already shipped in #119 | PASS |
| 13 | Namespaced-docker + Playwright green before PR | F Tier 1 self-check; T-green Tier 1 table; U live walkthrough | PASS |

**Coverage holes:** none. Every brief AC has either an automated test or an evidence-anchored
walkthrough item (usually both).

## 2. Issue #124 → brief translation faithfulness

| Issue #124 scope/AC bullet | Brief translation | Drift? |
|---|---|---|
| "Add compact list display alongside existing cards, toggled by SC-F1 switcher" | AC #1, #2 | None |
| "'Viewing: Cards \| Compact list' (Map arrives with SC-6)" | Expanded to 3 options day 1 with Map `available: false` for merge-order safety | **Expansion, but justified** — decisions.md logs this explicitly, and it makes #125 a one-line flip (§4). Issue actually says "Map arrives with SC-6 and slots into the same switcher" — 3-day-1 is a strict superset of the AC and is the only clean way to satisfy "slots into the same switcher" without cross-story hook edits. |
| "Dense rows: name, type, member count, visibility badge" | AC #2 + wireframe Surface 2 + F reuse-of-`.gc-directory-card` decision (all four fields already in `groups_chrome` markup) | None |
| "Information density vs browsability decision stated in switcher tooltip" | AC #7 — shipped HelpText copy reads exactly this trade-off | None |
| "Listed on `/showcase`" | AC #8 — `directory-presentation` entry flips to `live` with route to `/all-groups` | None |
| "Toggle switches presentation on `/all-groups` without losing filters/paging; choice persists per session" | ACs #2, #4 | None |
| "Compact rows show the same access-filtered group set as cards" | AC #6 | None |
| "Existing card display unchanged; existing suite stays green; Playwright spec toggles both ways" | ACs #3, #9, #10 | None |
| "Ships with HelpText entry (append-only)" | AC #12 — already shipped in #119, no new entry needed | None |
| "WCAG 2.2 AA (MVP NFR #3578793) — labels, keyboard, focus, contrast, non-color status; #145 backstop" | AC #11 | None |
| "Owns: `views.view.all_groups.yml` (edit — sole owner)" | Brief "Owned files" preserves this exclusivity; F design decision "no `#header` area edit in the YAML" means the file is untouched even by us | None (see §4 for merge-order significance) |
| "Owns: `directory-compact.css` (new)" | AC-driven, delivered | None |
| "Owns: `directory-toggle.spec.ts` (new)" | AC #9, delivered | None |

**Verdict:** no silent scope narrowing. One documented expansion (3 options day 1) that
strictly satisfies the issue and is the correct forward-compat move; O explicitly approved it
in the Phase 2 gate.

## 3. Deviations audit (wireframe → implementation)

F documented two deviations. My assessment:

1. **Attach point `.views-element-container` instead of `.view-content`.** F traced this
   through Views core (`Element/View::preRenderViewElement()` adds a
   `#theme_wrappers => ['container']` wrapper) and confirmed via live `curl` + Playwright
   inspection that `$view->element['#attributes']` lands on `.views-element-container` —
   the inner `.view-content` in Olivero's template is a hard-coded unattributed `<div>`. The
   wireframe's `.view-content` was a naming assumption from reading the CSS class, not from
   inspecting the render pipeline. F's fix preserves the wireframe **contract** (attribute name
   `data-do-directory-variant`, values `cards`/`compact`, absent-or-`cards` = no compact CSS
   matches) — only the attach-point *name* changes. **Empirically necessitated, not a design
   choice.** Not routing back to D was correct: D would have arrived at the same answer via the
   same trace, and a re-run costs a cycle for zero user-visible delta.

2. **Two hooks (`viewsPreRender()` + `preprocessViewsView()`) instead of the brief's single
   `viewsPreRender()`.** F traced `DisplayPluginBase::elementPreRender()` — a queued
   `#pre_render` callback that unconditionally overwrites `#header` after `hook_views_pre_render`
   fires but before `hook_preprocess_views_view` runs. Writing `#header` in
   `viewsPreRender()` alone is silently discarded on real page loads (Kernel test's
   `$view->preview()` doesn't run `#pre_render`, so RED tests could not observe the gap; F
   discovered it via live-site inspection). The split keeps `viewsPreRender()` for `#cache` /
   `#attributes` / `#attached` (which survive) and moves the switcher injection into
   `preprocessViewsView()` (which runs after the overwrite). Both hooks share the same
   `isDirectoryView()` scoping guard and `requestedVariant()` helper — no logic duplication.
   **Empirically necessitated, not a design choice.** Same call — not routing back to D was
   correct.

Both deviations are correctly documented in F handoff, in the class docblock (per F), and in
the decisions.md journal (Phase 5). The class docblock note is important for the future SC-6 /
ST-8 stories that will embed switchers into other views.

## 4. Merge-order confirmation for #125

**Confirmed clean.** The single source-of-truth file at
`docs/groups/config/views.view.all_groups.yml` is **not staged and not modified** (`git status`
returns "nothing to commit, working tree clean" for that specific path).

The only staged path matching `views.view.all_groups.yml` is:

- `docs/groups/modules/do_showcase/tests/fixtures/config/views.view.all_groups.yml` — module-local
  test fixture (a trimmed synthetic copy for `DirectoryTogglePreRenderTest`), never assembled
  into `config/sync/`. Zero merge collision risk with #125.

The unstaged diff on `config/sync/views.view.all_groups.yml` is a **build artifact** from
`bash scripts/ci/assemble-config.sh` and is not staged for commit (confirmed by
`git diff --cached --name-only | grep -E "^(web/modules/custom|config/sync)/"` returning
empty). CI regenerates this from the source in `docs/groups/config/`.

**#125 can append its `page_2` display to `docs/groups/config/views.view.all_groups.yml`
cleanly with zero conflict against this story.**

## 5. Duplication / parallel-paths check

F fully realized A-advisory #7 (not merely tolerated it):

- `VariantSwitcher::directoryLayoutOptions()` — line 91, the single source of truth for the
  `[compact, cards, map]` option list (with proper `$this->t()` translation of literal
  strings, satisfying phpcs).
- `VariantSwitcher::resolveCurrent()` — line 276, public wrapper around the existing private
  `resolveSelection()` so `viewsPreRender()` can compute the resolved variant without
  duplicating the fallback rule.
- `ShowcaseController::page()` — line 107 now calls
  `$this->switcher->directoryLayoutOptions()` instead of its own literal.
- `DoShowcaseHooks::preprocessViewsView()` — calls the same shared method.

**One source of truth confirmed.** #125's map-flip is a single-file, single-method edit inside
`VariantSwitcher::directoryLayoutOptions()`.

No parallel/duplicate paths introduced.

## 6. WCAG advisory disposition

U flagged `.gc-badge--success` ("Open" visibility badge) at 3.68:1 contrast (AA-normal
requires 4.5:1). F handoff explicitly states this story reused the pre-existing `groups_chrome`
`--gc-color-success` / `--gc-color-success-050` token pair verbatim from card mode; the
compact variant introduces zero new color pairings. Grep confirms both tokens are defined in
`web/themes/custom/groups_chrome/css/tokens.css` (lines 37-38) and were shipped by an earlier
story.

**Disposition:** deferring is the right call.

- AC #11 says "AA contrast on compact rows and the visibility badge" — the badge is
  **reused, not introduced by this story**. The story-level AC intent is "don't ship a new
  contrast failure," which is satisfied.
- #145 (WCAG backstop, late-wave) is the correct locus for the fix — it will remediate all
  pre-existing token-pair debt across `groups_chrome` in one pass rather than piecemeal.
- Per the operator's POC-no-follow-ups convention (memory `feedback_poc_no_follow_ups.md`),
  no GH follow-up filed.

Not ADVISORY-HOLD: the spec/wireframe are correct (they both explicitly reuse the existing
tokens, and the non-color-status contract — badge is a **text** label, not a color-only cue —
is fully satisfied, so the contrast miss does not compound into a WCAG failure category
this story is responsible for).

## 7. Handoffs directory cleanup

Current contents of `docs/handoffs/0124-directory-toggle/`:

- `brief.md`, `handoff-D.md`, `handoff-A-plan.md`, `handoff-T-red.md`, `handoff-F.md`,
  `handoff-T-green.md`, `handoff-U.md`, `handoff-S.md` (this file), `screenshots/`
  → ephemeral, safe to delete post-merge.
- `survey.md`, `wireframe.md`, `decisions.md` → **keep** (per project convention: survey =
  reuse-map, wireframe = design contract, decisions = journal).

No action required in this story — cleanup is O's post-merge task.

## 8. CI / PR readiness check

| Concern | Check | Result |
|---|---|---|
| Assemble script clean | F ran `bash scripts/ci/assemble-config.sh` → `==> assemble-config: done` | PASS |
| phpcs clean on touched production files | F confirmed 0 errors on all 6 touched PHP/JS/CSS + 2 YAML files; warnings on `DoShowcaseHooks.php` and "errors" on `do_showcase.switcher.js` reproduced against committed HEAD → pre-existing, not introduced | PASS |
| Kernel + Unit regression | 68/68 GREEN (T-green Tier 1) | PASS |
| E2E regression | 40/40 (39 pass + 1 conditional skip) across all 4 spec files | PASS |
| No build artifacts staged (`web/modules/custom/`, `config/sync/`) | `git diff --cached --name-only \| grep -E "^(web/modules/custom\|config/sync)/"` returns empty | PASS |
| Sole-owner file untouched (merge-order for #125) | `docs/groups/config/views.view.all_groups.yml` — `git status` clean; only staged fixture is module-local test fixture | PASS |
| No secrets / credentials staged | Staged paths inspected — none | PASS |
| Nothing already committed (fresh diff for PR) | `git diff --stat origin/main...HEAD` empty; all work is staged, awaiting O's commit | Note: O must commit before pushing / creating PR |

**Staged file list (14 files):**

```
docs/groups/modules/do_showcase/css/directory-compact.css                              (new)
docs/groups/modules/do_showcase/do_showcase.libraries.yml                              (edit)
docs/groups/modules/do_showcase/do_showcase.services.yml                               (edit)
docs/groups/modules/do_showcase/js/do_showcase.switcher.js                             (edit)
docs/groups/modules/do_showcase/src/Controller/ShowcaseController.php                  (edit)
docs/groups/modules/do_showcase/src/Hook/DoShowcaseHooks.php                           (edit)
docs/groups/modules/do_showcase/src/ShowcaseCatalog.php                                (edit)
docs/groups/modules/do_showcase/src/VariantSwitcher.php                                (edit)
docs/groups/modules/do_showcase/tests/fixtures/config/views.view.activity_stream.yml   (new)
docs/groups/modules/do_showcase/tests/fixtures/config/views.view.all_groups.yml        (new)
docs/groups/modules/do_showcase/tests/src/Kernel/DirectoryTogglePreRenderTest.php      (new)
docs/groups/modules/do_showcase/tests/src/Unit/DoShowcaseHooksViewsPreRenderRegistrationTest.php (new)
docs/groups/modules/do_showcase/tests/src/Unit/ShowcaseCatalogDirectoryLiveTest.php    (new)
tests/e2e/directory-toggle.spec.ts                                                     (new)
```

## 9. Test-quality audit (test-quality.md §7)

- **Per test — one behavior:** each Kernel/Unit test name pins exactly one behavior
  (`testCompactQueryParamSetsWrapperToCompact`, `testUnavailableMapQueryParamFallsBackToCompact`,
  etc). E2E specs each pin one flow.
- **Fails in isolation for the right reason:** T-green performed a spot-check mutation on
  `preprocessViewsView()` and confirmed `testSwitcherInjectedWithThreeOptionsInOrder` fails on
  the missing `data-do-showcase-instance` string, not a setup error. Phase-4 RED handoff
  confirmed every other test failed for the right reason (missing method / render-array key /
  null value) before F implemented.
- **Cheapest sufficient tier:** Kernel for the hook-registration + render-array + cache
  contract (Unit can't invoke Views-runtime hooks; Functional/E2E would duplicate). Unit for the
  static catalog data flip + reflection over `#[Hook(...)]`. E2E for the actual click/keyboard/
  persistence behaviors. No tier duplication.
- **Suite proportionality:** 8 Kernel + 4 Unit + 12 E2E = 24 tests for a change spanning a
  hook, a service, a controller, a data class, one CSS, one JS behavior, and the shared
  library manifest. Ratio is appropriate — not padding, not skimpy.
- **Smells:** none found. `testExistingThemeHookRegistrationIsUndisturbed` (Unit) and
  `testHookDoesNotFireForADifferentViewId` (Kernel) are defensive negative-case tests that
  each pin a specific regression path (unrelated existing hook + hook-scoping breadth).

## 10. Verdict

**PASS.** Ready for rebase + PR (O to commit + push).

- All 13 brief ACs satisfied with matching test or walkthrough evidence.
- Issue #124 scope faithfully translated (one documented, O-approved expansion for #125
  forward-compat).
- Both wireframe deviations empirically necessitated by Drupal Views' actual render pipeline
  (verified against Views-core source + live-site inspection), correctly documented, correctly
  not routed back to D.
- Merge order for #125 preserved: source `docs/groups/config/views.view.all_groups.yml`
  untouched.
- Zero parallel/duplicate paths — A-advisory #7 fully realized (single source of truth for
  the option list in `VariantSwitcher::directoryLayoutOptions()`).
- WCAG advisory (pre-existing badge contrast 3.68:1) correctly deferred to #145 per operator
  convention.
- CI-clean: no build artifacts staged, phpcs pre-existing-only, kernel+unit 68/68, E2E 40/40.

## 11. Advisory notes (non-blocking)

- Two additional pre-existing-debt items surfaced during this cycle: (a) narrow-viewport
  (375px) horizontal overflow of ~35px on compact rows (U Surface 2), inherited from
  `.gc-directory-card` padding; (b) `●` selection-glyph is inside the radio's accessible name
  (no `aria-hidden`), a #119 pattern that requires unanchored regex matchers in E2E specs.
  Both are pre-existing groups_chrome / SC-F1 concerns, not introduced by this story, correctly
  not filed per POC-no-follow-ups.
- Class-docblock note on `DoShowcaseHooks` about the `elementPreRender()`-overwrite gap is
  valuable orientation for future switcher-embedding stories (SC-6, ST-8) — F correctly
  documented it there rather than losing it in the decisions journal.
