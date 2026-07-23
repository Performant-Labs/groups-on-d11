# Handoff-S: Phase 9 - #113 ST-4 Trending surface (/trending)

**Date:** 2026-07-23
**Branch:** 113-trending
**Issue:** #113
**Handoff-T reviewed:** `docs/planning/handoffs/113-trending/handoff-T-green.md`
**Handoff-A reviewed:** `docs/planning/handoffs/113-trending/handoff-A.md`
**Handoff-F reviewed:** `docs/planning/handoffs/113-trending/handoff-F.md`
**Operator-facing report:** N/A (inspection-only audit; no live UI walkthrough — U was
N/A-cannot-exercise per environment constraint; CI's e2e job is the authoritative
live gate.)

## Verdict: **PASS**

All 9 acceptance criteria from brief.md map to a shipped artifact; every artifact
was directly inspected. No blocking findings. Two non-blocking advisories (below).

## A precondition
Confirmed: A returned PASS on the plan (handoff-A.md, Phase 3).

## T precondition
Confirmed: T-green reported zero blocking issues, all Tier 1/Tier 2 inspection
checks pass, execution-level GREEN deferred to CI per environment constraint
(node_modules not installed in this worktree).

## Visual-diff-tool precondition
N/A — no rendered UI walkthrough is being performed at S (per operator's
environment note, U was skipped as N/A-cannot-exercise; CI's e2e job with a real
seeded/cron'd site is the authoritative live gate). This audit is
inspection-level, consistent with T-green's own inspection-only Tier 1/2 verdict.

## Acceptance-criteria audit (issue #113 body + brief.md checklist)

| # | Criterion | Backing artifact (verified by direct read) | Status |
|---|---|---|---|
| 1 | Renders + demo-credibly (commented threads outrank zero-comment); empty-state pre-cron | `views.view.trending.yml` sorts: `score DESC` from `do_discovery_hot_score` then `created DESC`; `filters.type.value` includes `forum`; seed script `step_700_demo_data.php:141,143,216-219` — both "Venue Logistics Thread" and "Patch Review Process RFC" are forum-bundle nodes with 2 comments each (→ hot score 6.0 per DoDiscoveryHooks formula) | PASS |
| 2 | `views.view.hot_content.yml` unmodified | `git diff docs/groups/config/views.view.hot_content.yml` → empty | PASS |
| 3 | Playwright rendered-DOM spec on seeded site | `tests/e2e/trending.spec.ts` (6 tests, mechanism-agnostic library-attach check, top-10 window score ordering, mutual-exclusivity empty-state, /hot regression, WCAG H1 + pager). Not excluded from Playwright config (no exclusion patterns in repo). | PASS |
| 4 | Existing suite stays green | Deferred to CI — inspection cannot prove non-regression; T-green's byte-identical assembled/source diffs + `preprocessViewsView()` map preserving FOLLOWING_FEED_VIEW_ID branch verbatim make regression unlikely | DEFERRED (CI) |
| 5 | HelpText entry for `/trending` intact | `do_chrome/src/Hook/PageHelp.php:79` and `HelpText.php:231` present (verified via grep); `PageHelpRouteMapTest.php:52` still asserts `view.trending.page_1 → page.trending`; `git diff docs/groups/modules/do_chrome/` empty | PASS |
| 6 | WCAG 2.2 AA baseline (H1, keyboard, focus, contrast, non-color status) | View config renders one `<h1>` from `title: 'Trending'`; pager `type: full` with `pagination_heading_level: h4` (inherited real `<a>` links); no color-only status introduced by trending.css (empty-state text-align/padding only); Test 6 pins H1 count + accessible pager Next-link name | PASS (inspection) |
| 7 | Namespaced-docker throwaway-DB DOM check | Deferred to CI e2e job (per environment note) | DEFERRED (CI) |
| 8 | Cron trigger in BOTH `deploy/entrypoint.sh` AND `.github/workflows/test.yml` | `entrypoint.sh:181-187` (`# --- do_discovery cron BEGIN/END ---` around `$DRUSH cron` in the fresh-install branch, after `$DRUSH cr`, before "Install + seed complete"); `test.yml:576-582` (same-named block, after `# --- do_activity step_7xx END ---`, before `cache:rebuild -y`) | PASS |
| 9 | Marker-section rule vs #116 (distinct names → independent) | Verified `entrypoint.sh` markers 155/177 = `do_activity step_7xx`; 181/187 = `do_discovery cron`; `test.yml` markers 558/575 vs 576/582 — distinct namespaces, independent blocks, merge-clean either order | PASS |

## Owned-files-respected audit

Created (new): `docs/groups/config/views.view.trending.yml`, `docs/groups/modules/do_streams/css/trending.css`, `tests/e2e/trending.spec.ts`.
Edited (append/scoped): `docs/groups/modules/do_streams/do_streams.libraries.yml` (5-line append), `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (const + preprocessViewsView refactor to id-map), `deploy/entrypoint.sh` (marker block), `.github/workflows/test.yml` (marker block, 7 lines).
Untouched (regression guards): `docs/groups/config/views.view.hot_content.yml`, `docs/groups/modules/do_chrome/**` — verified empty diff.

Scoped `git diff --stat HEAD -- docs/groups deploy .github tests/e2e` = 4 files, 66 insertions, 12 deletions — matches F's declared change surface exactly. The
huge `config/sync/` + `web/modules/custom/` noise in raw `git status` is
pre-existing assembled build output from prior in-worktree runs, not F's diff
(consistent with T-green's Tier 2 analysis).

## Copy-verbatim audit

| String | Where required | Shipped | Match |
|---|---|---|---|
| `Nothing trending yet.` | Issue body empty-state | `views.view.trending.yml` line 128, empty area_text_custom | EXACT |
| `Popular this week — nodes ranked by hot score.` | Issue body description | `views.view.trending.yml` line 13 | EXACT (em-dash preserved) |
| `Trending` label | Issue body | `views.view.trending.yml` line 11 (label) + 24 (title) | EXACT |

## Test-quality audit (test-quality.md §7)

- **Per test:** each of the 6 tests in `trending.spec.ts` names ONE behavior, fails in isolation for the right reason, sits at the e2e tier (the cheapest sufficient tier for cross-cutting route/access/library-attach checks — no lower tier can observe a rendered DOM against a seeded DB), and asserts behavior not implementation (mechanism-agnostic library-attach check, no reference to `preprocessViewsView()` or `TRENDING_VIEW_ID`).
- **Per suite:** 6 tests is proportionate to a feature story spanning (a) new route, (b) sort correctness, (c) card presence, (d) `/hot` regression, (e) library-attach + `/following` regression, (f) WCAG. No duplication, no coverage-padding.
- **Smells:** none detected — no assertion-free tests, no snapshot-everything, no mock-shaped tests, no unreachable outcomes. Test 6's conditional pager check is a legitimate DOM-shape hedge (wireframe §3), not a coverage dodge.
- **Delete/merge candidates:** none.

## Quality audit

| Area | Result | Notes |
|---|---|---|
| API/config consistency | PASS | `views.view.trending.yml` clone shape matches `following_feed.yml` conventions; sort block copied verbatim from `hot_content.yml`; `dependencies.module` correctly drops `do_streams` (no do_streams filter plugin used) |
| Error handling | N/A | View is declarative config; entrypoint cron uses `\|\| echo WARNING` guard to prevent non-zero exit from failing the deploy |
| UI/UX match to spec | PASS (inspection) | Empty-state markup + copy verbatim; no ranking pill (design decision in wireframe §6, ratified in decisions.md/A Finding 2); anonymous == authenticated (wireframe §5 + `access.type: none`) |
| Accessibility | PASS (inspection) | Exactly one H1 (Views title); real `<a>` pager (Views core); no color-only status |
| Architecture gate | PASS | A returned PASS with 3 advisories, all followed by F |
| Code organization | PASS | `preprocessViewsView()` refactor from single `!==` guard to id-map is a clean extension consistent with the class's documented convention |
| Security | N/A | Public view of published nodes only; access is `type: none`; the same access model `/hot` already runs under |
| Performance | PASS | Fresh JOIN per render (per A Finding 7 analysis) — hot_score not per-request cached, so cron placement after cr in entrypoint is acceptable |
| Visual regression | DEFERRED | Inspection-only audit; CI e2e is live gate |
| Naming consistency | PASS | `TRENDING_VIEW_ID` const mirrors `FOLLOWING_FEED_VIEW_ID`; library name `do_streams/trending` mirrors `do_streams/following`; CSS scope `.trending-page` mirrors `.following-feed` |
| Test quality (test-quality.md §7) | PASS | See above |

## Scope check

F delivered exactly the phase scope defined in the brief. No over-reach detected
— every touched file appears in the brief's "Owned files" list. No under-delivery
— every AC has a backing artifact. `DoStreamsHooks.php` was edited beyond the
literal brief (which said "author F may prefer"), but the refactor to an id-map
was ratified in advance by A's Finding 3 advisory and by the task instruction.

## Advisory notes (non-blocking)

1. **`nth-of-type(-n+10)` locator in test 2 (raised by T-green).** As T-green
   flagged, `nth-of-type` counts among siblings sharing the same tag name
   within their parent, not "Nth element matching the selector." In Views'
   default unformatted row markup, each `.stream-card-wrapper` is a
   sibling-`<div>`, so this works correctly. Since `items_per_page: 10` makes
   the whole page_1 response the top-10 window regardless, the assertion is
   redundant-but-safe even if `nth-of-type` under/over-selects slightly. Not a
   spec bug — will not false-positive nor false-negative in this project's DOM
   shape. Watch in CI's first run only.

2. **No README update for the new `trending:` library entry** in
   `do_streams/README.md`. The README documents the shell contract, not the
   full library inventory, so this is not strictly a doc gap — but a future
   contributor scanning the README for "what libraries does this module ship"
   won't find `trending`. Cosmetic-only, not REWORK-worthy.

## Recommended next step

**Proceed directly to Phase 10 (O): rebase-and-CI-check → PR → self-merge.**

Consent granted for O to proceed without further pipeline cycles. If CI's e2e
job surfaces a real failure (Playwright execution against seeded/cron'd site),
O should route back to T(GREEN)/F as appropriate — but no inspection-level
issue in this diff predicts such a failure.
