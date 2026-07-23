# Handoff-S: Phase 9 — #127 SD-2 Card ⓘ tooltips

**Date:** 2026-07-23
**Branch:** 127-card-tooltips
**Issue:** #127
**Handoff-A reviewed:** decisions.md § Phase 3 (PASS)
**Handoff-T-green reviewed:** `docs/planning/handoffs/127-card-tooltips/handoff-T-green.md` (GREEN, 0 blockers)
**Handoff-F reviewed:** `docs/planning/handoffs/127-card-tooltips/handoff-F.md`
**Handoff-U reviewed:** `docs/planning/handoffs/127-card-tooltips/handoff-U.md` (PASS)
**Diff-gate reviewed:** `docs/planning/handoffs/127-card-tooltips/diff-gate-round1.md` (PASS; W-3 forwarded)

## Preconditions
- A precondition: **PASS** — A returned PASS in Phase 3.
- T precondition: **PASS** — T-green reported zero blocking issues.
- Visual-tool precondition: not required — the pattern is DOM-additive-only with no new visual pattern (same ⓘ shape as #89/#122/#126). U did live-browser walkthrough at 1280 and 360 with no visual regressions; that suffices for a non-visual-change story.

## Story diff scope (verified)
Computed against `git merge-base HEAD origin/main` (`7dd8368`) — not raw `git diff origin/main`, because the branch is behind main and a raw diff shows unrelated `origin/main`-only deletions (140/144/etc.). The story's own diff is 13 files, +941/−0, exactly matching the brief's owned-files list:
- `docs/groups/modules/do_chrome/src/HelpText.php` (+32, append-only)
- `docs/groups/modules/do_chrome/tests/src/Unit/HelpTextTest.php` (+63, one new test method)
- `web/themes/custom/groups_chrome/groups_chrome.theme` (+37, extending 2 preprocess funcs)
- `web/themes/custom/groups_chrome/templates/content/views-view-fields--all-groups.html.twig` (+17)
- `web/themes/custom/groups_chrome/templates/content/node--stream-card.html.twig` (+16)
- `tests/e2e/element-tooltips.spec.ts` (+194, NEW)
- 7 handoff docs under `docs/planning/handoffs/127-card-tooltips/`

## Per-AC verdict table

| # | Acceptance criterion (issue #127) | Verdict | Evidence |
|---|---|---|---|
| 1 | Hovering each listed element as anonymous shows a sensible tooltip; no double-tooltip near page-level (#126) | **PASS** | U live walkthrough rows 2–4, 9–11 confirmed correct copy on hover for all 6 elements on `/all-groups` and `/stream`; row 6 confirmed badge itself has no `title` and does not fire tippy (no double-tooltip). #126 unmerged on main; card triggers are inside `.gc-card` DOM, page-level is disjoint. |
| 2 | Reused copy stays single-sourced | **PASS** | Visibility ⓘ resolves via `HelpText::get('visibility.' . $gc['visibility_value'])` in `groups_chrome.theme`; T-red pinned exact `visibility.open` string in e2e spec; U row 3 confirmed byte-identical to `HelpText::get('visibility.open')`. Zero new visibility copy added — only `card.*` keys are new. |
| 3 | Existing suite green; Playwright spec asserts tooltip on one card field + one stream-card element | **PASS** | T-green: 12/12 unit, 7/7 e2e target, 3/3 directory-cards regression, 20/20 showcase, 6/6 nav, 4/4 persona-switcher, do_chrome Unit sweep 16/16. E2E spec asserts contract on all 6 triggers (exceeds "one + one" bar). |
| 4 | WCAG 2.2 AA (labels, keyboard, visible focus, AA contrast, non-color status) | **PASS** | `tabindex="0"` + non-empty `aria-label` on all 6; U row 5/12 confirmed `.focus()` lands + tippy shows on focus + visible 2px solid rgb(0,103,184) outline; U row 15 measured contrast 5.78:1 (above 4.5:1 bar); glyph + text tooltip is non-color signal. See W-3 formal ruling below re: role choice. |
| 5 | Owns disjoint files (theme templates + HelpText append-only + new e2e spec) | **PASS** | Diff-scope check above confirms exactly the brief's owned-files set. HelpText append-only verified by inspecting the raw diff hunk — insertion begins immediately after `persona.moderator` on line 210; zero pre-existing lines touched. `groups_chrome.theme` is theme code (legit-committed per PROJECT_CONTEXT); extension of 2 existing preprocess funcs is A-approved (decisions.md Finding #1). Disjoint from #126 (in-flight, unmerged): #126 owns `Hook/PageHelp.php` + `page.*` keys; this story owns 2 twigs + `card.*` keys. Only overlap is `HelpText.php`, safe under append-only + disjoint namespaces. |
| 6 | No behavior changes — markup + copy only | **PASS** | No route, entity, field, or schema changes. Preprocess extension is pure data-passthrough (reads existing `field_group_visibility` value that was already resolved on the very next line for `visibility_label`; adds `tooltips` sub-array + one derived `visibility_value` key). Twig adds `<span>` siblings only, each `{% if %}`-guarded. F reported no behavior changes; T-green regression sweep confirms. |

## W-3 formal ruling — `role="note"` on the ⓘ trigger

**Ruling: PASS this story. Advisory forwarded to #145 (WCAG backstop) as a pattern-wide question, not a story-127 blocker.**

WCAG 2.2 AA requirements for the trigger, evaluated per SC:
- **1.1.1 Non-text content / 4.1.2 Name-Role-Value**: element has a full-sentence, non-empty `aria-label` — accessible name present. ✓
- **2.1.1 Keyboard**: `tabindex="0"` + real `.focus()` verified by U (row 5). ✓
- **2.4.7 Focus Visible**: 2px solid rgb(0,103,184) outline verified by U. ✓
- **1.4.11 Non-text Contrast / 1.4.3 Contrast**: 5.78:1 on the trigger vs. its background, well above 4.5:1 bar. ✓
- **1.4.1 Use of Color**: glyph (ⓘ) + text tooltip; not color-only. ✓
- **1.3.1 Info & Relationships**: `role="note"` communicates "supplementary annotation" honestly — it does not misrepresent an interactive control as passive or vice versa. The element is not a button (it triggers a supplementary display, not an action). ✓

The idiomatic ARIA tooltip pattern (WAI-ARIA APG) is `role="button" aria-describedby="tooltip-id"` on the trigger + `role="tooltip"` on the tippy content. That pattern is **recommended, not required** by any WCAG SC. `role="note"` on a passive annotation is not a violation; it simply is not the *most idiomatic* choice. All measurable AA success criteria are met.

**This story does not introduce the pattern** — it is inherited from #89 (`GroupTypeContentHelp::infoTrigger()`), #122 (`groups_chrome_preprocess_group()`), and #126 (`PageHelp::preprocessPageTitle()`), all already shipped/in-flight with the same DOM shape. Changing it in this story alone would (a) diverge from the four-story-established pattern for no functional gain, and (b) preempt #145's own remit (WCAG backstop is the correct place for a pattern-wide ARIA sweep).

**Recommendation**: pass this story; carry the "revisit trigger ARIA pattern-wide" question forward to #145's docket as an advisory (not a defect). If #145 later decides to migrate to `role="button" aria-describedby`, one search-and-replace across the four call sites covers it.

## Honest-omissions ruling — language chip, pinned badge, event-date chip

**Ruling: correct call — not an AC-1 partial miss.**

The issue text mentions these three surfaces as candidates ("language chip … pinned badge … event-date chip"). None of the three has backing data in the current preprocess arrays (verified in survey.md §"What's actually in the templates today"):
- Directory card `gc_directory` does not carry a language field (no `field_language` populated in `groups_chrome_preprocess_views_view_fields__all_groups()`).
- Stream card `gc_stream` does not carry pinned or event-date data (pin is handled by a separate view in #92; event dates are not exposed on the stream row).

Wiring ⓘ triggers to nonexistent data would either (a) require new preprocess variables + new field reads, which is the "no new fields" scope guardrail violation the brief and A both name, or (b) render invisible triggers with empty tooltips, which would fail AC-1's "sensible tooltip" bar and pollute the DOM.

The brief and A both explicitly endorsed the omission (survey lines 49, 57; decisions.md A Finding #6: "Honest omission is the right call. If a follow-up story adds pinned/event/language data, append `card.stream.pinned` / `card.stream.event_date` / `card.directory.language` under the same `card.*` namespace."). Forward-compatible namespace decision preserves the option.

Issue-word "~8 new keys" → shipped 5 = under-count is explained by (3 reused visibility keys) + (3 honest omissions). Delivered scope matches deliverable scope.

## Test-quality audit (playbook testing/test-quality.md §7)

| Dimension | Assessment |
|---|---|
| Per-test naming one behavior | 8 tests, each names a distinct behavior (per-element contract × 2 surfaces; hover × 2; no-double-tooltip × 2; visibility reuse × 1; unit copy-source × 1). No fan-outs re-proving one branch. |
| Fails-in-isolation-for-the-right-reason | T-red baseline confirmed each e2e failure resolved to "0 elements" — right reason (missing DOM), not a setup/typo. Unit RED failed on missing key at the correct assertion line. |
| Cheapest sufficient tier | E2E for rendered DOM + tippy JS (correct — no cheaper way to observe hover-triggered JS render). Unit for copy-source contract (correct — no Drupal bootstrap needed). No Kernel/Functional inflation. |
| Behavior not implementation | Assertions target `data-do-tooltip` presence + `aria-label` non-empty + tippy visible on hover + copy byte-identical to `HelpText::get(...)`. No assertions on class-name choice, template file path, or preprocess function name. |
| Suite proportionality | 8 tests for 5 new keys + 6 new DOM triggers across 2 surfaces. Proportionate. Nothing flagged for deletion or merge. |
| Smells | None: no assertion-free, tautological, unreachable-outcome, snapshot-everything, mock-shaped, or coverage-padding tests. |

## Quality audit table

| Area | Result | Notes |
|---|---|---|
| API consistency | N/A | No API changes. |
| Error handling | PASS | `{% if %}` guards on each trigger — missing copy degrades one trigger silently, not the whole cluster. |
| UI/UX match to spec | PASS | U live-verified all 6 elements render + hover correctly across both surfaces + both viewports (1280, 360) + both personas. |
| Accessibility | PASS | See AC-4 evidence + W-3 formal ruling. Every measurable AA SC met with margin. |
| Architecture gate | PASS | A returned PASS; preprocess extension endorsed as the neighboring pattern (#122 precedent). |
| Code organization | PASS | `tooltips` sub-array grouping (per A soft-warning #1) keeps `gc_directory`/`gc_stream` shapes scannable. Bare `HelpText::` call matches file convention. |
| Security | N/A | No new input surfaces. HelpText values are plain-text static strings, rendered via Twig auto-escape. |
| Performance | PASS | Preprocess adds ≤4 static `HelpText::get()` calls per rendered directory row (5 total in `gc_directory`) and 3 per stream card. Negligible; HelpText is a static array. |
| Visual regression | N/A | No new visual pattern; DOM-additive only. U confirmed no visual regression at 1280 and 360. |
| Naming consistency | PASS | `card.directory.*` / `card.stream.*` namespace matches existing HelpText convention; disjoint from every other prefix. |
| Test quality (§7) | PASS | See table above. |
| Lint | PASS | Zero new phpcs findings from the 32 appended lines; T-green independently reproduced F's baseline comparison. All flagged lines predate this story. |

## Scope check
F delivered exactly the phase scope. No over-delivery (no CSS drive-by, no drive-by ARIA refactor across sibling stories, no #145 preemption). No under-delivery (5 keys + 6 triggers + 8 tests match the brief). Honest omissions (3 surfaces) are documented, endorsed by A, and forward-compatible.

## Diff-gate follow-ups (o4-mini round 1)
- **W-1** (unqualified `HelpText::` call): false positive — `use Drupal\do_chrome\HelpText;` is imported at the top of `groups_chrome.theme` (matches #122's existing convention at line 665). No action.
- **W-2** (`preprocess_node` unconditional): F's implementation branches on `$variables['view_mode'] === 'stream_card'` (verified in the theme diff). Overhead is a single string compare per node render — not worth splitting into a suggestion-specific hook when the existing function already carries other view-mode branches. No action.
- **W-3** (role="note"): ruled above — PASS with #145 advisory.
- **W-4** (handoff docs in main repo): matches the established project convention (`docs/planning/handoffs/` for every story). Out of scope for this story to reverse. No action.
- **NIT-1** through **NIT-4**: cosmetic; not blocking. Optional cleanup by F on request.

## Advisory notes
1. Carry W-3 forward to #145 as a pattern-wide question: consider migrating the ⓘ trigger DOM across #89/#122/#126/#127 to `role="button" aria-describedby="tooltip-id"` + `role="tooltip"` on tippy content. Not required by WCAG; would align with WAI-ARIA APG. One search-and-replace across the four call sites.
2. The `.first()`-based directory-card locator in `element-tooltips.spec.ts` is coupled to `all_groups` view sort order (`created DESC`); any future test that seeds untyped groups can transiently perturb it. Already diagnosed once during F's self-check. Non-blocking; matches the existing `directory-cards.spec.ts` pattern.
3. HelpText keys are raw English (NIT-3 from diff-gate). Project has no `t()`-wrapping in `HelpText.php` today; this is a project-wide localization question (not story-scope).

## Verdict

**PASS** — all 6 acceptance criteria met, spec-compliant, WCAG 2.2 AA confirmed, honest omissions endorsed, W-3 ruled non-blocking with advisory to #145. Ready for O to open PR.
