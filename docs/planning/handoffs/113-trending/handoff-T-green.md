# Handoff-T-green: Phase 6 - #113 ST-4 Trending surface (/trending)

**Date:** 2026-07-23
**Branch:** 113-trending
**Issue:** #113
**Handoff-F reviewed:** `docs/planning/handoffs/113-trending/handoff-F.md`
**Handoff-T-red:** `docs/planning/handoffs/113-trending/handoff-T-red.md`

## Environment constraint (unchanged from F's phase)
No local DDEV instance is running in this worktree (sibling worktrees hold the
DDEV project names) and `node_modules` is not installed here (`npx playwright
test ... --list` fails with `Cannot find module '@playwright/test'` while
resolving `playwright.config.ts` — a pre-existing environment gap in this
worktree, not something F's diff caused or something specific to
`trending.spec.ts`; the same failure occurs for any spec in this repo run
from this checkout). Per the task's explicit brief, this makes CI the
authoritative GREEN surface. This handoff's verdict is therefore **GREEN
pending CI** — every check available to run headless from this environment
(YAML validity, structural PHP check, byte-identical assembled output,
regression-guard diffs, cross-cutting file scope) passes; the actual
Playwright execution against a seeded, cron'd site must be confirmed by CI.

## GREEN confirmation (inspection-level, CI pending for execution-level)
Static/parse-level check performed instead of live execution:
- `git status --porcelain tests/e2e/trending.spec.ts` → `??` (untracked, new
  file, byte-content matches what T authored in Phase 4 — F did not modify
  it; confirmed by direct `Read` comparison against `handoff-T-red.md`'s
  described 6 tests, same names/order/assertions).
- Read the full spec file end-to-end: all 6 tests still assert *behavior*
  (200+H1, top-10 ordering, card presence, `/hot` regression, library-attach
  presence on both routes, WCAG h1/pager-name) — none reference
  implementation internals (no reference to `preprocessViewsView()`,
  `TRENDING_VIEW_ID`, or any PHP symbol), so the suite is implementation-
  agnostic and did not have to change now that F's mechanism (map-lookup
  inside the existing `preprocessViewsView()`) is known. Spot-check: if the
  library attach were removed, test 5's `expect(trendingHtml).toContain('trending.css')`
  would fail — the test still fails if behavior is removed. If the sort
  order were reversed, test 2's `firstTenCards.getByRole(...)` scoped locator
  would fail — same spot-check property holds.
- No test needed to change from RED to make this a valid suite for F to
  target; T made zero edits to `trending.spec.ts` during this phase (T's own
  rule: T does not touch the suite unless a test is wrong — none were).

**CI is the authoritative execution-level GREEN** — deferred, per environment
constraint above. Concern for whoever reads CI's result: confirm all 6 tests
in `tests/e2e/trending.spec.ts` report pass (not skip), and confirm the
existing `following.spec.ts` / `page-help.spec.ts` (HelpText regression path)
still pass in the same run.

## Tier 1 results

| Check | Command | Expected | Actual | Verdict |
|---|---|---|---|---|
| `hot_content.yml` untouched | `git diff docs/groups/config/views.view.hot_content.yml` | empty | empty | PASS |
| `do_chrome/` untouched | `git diff docs/groups/modules/do_chrome/` | empty | empty | PASS |
| `views.view.trending.yml` YAML validity | `python -c "import yaml; yaml.safe_load(open(...))"` | exit 0 | `trending.yml YAML OK` | PASS |
| `test.yml` YAML validity (post-insertion) | same, on `.github/workflows/test.yml` | exit 0 | `test.yml YAML OK` | PASS |
| PHP structural check (`php` unavailable) | brace/paren/bracket count on `DoStreamsHooks.php` | balanced | 35/35, 184/184, 92/92 | PASS |
| Playwright spec parse | `npx playwright test tests/e2e/trending.spec.ts --list` | 6 tests listed | **environment failure**: `Cannot find module '@playwright/test'` (no `node_modules` in this worktree) — pre-existing gap, not a spec defect (spec was read directly and is syntactically valid TS: correct imports, balanced braces, `test.describe`/`test(...)` blocks all closed) | DEFERRED to CI (env gap, not a test/code defect) |
| Assembled config matches source | `diff docs/groups/config/views.view.trending.yml config/sync/views.view.trending.yml` | identical | `IDENTICAL: trending.yml` | PASS |
| Assembled CSS matches source | `diff docs/groups/modules/do_streams/css/trending.css web/modules/custom/do_streams/css/trending.css` | identical | `IDENTICAL: trending.css` | PASS |
| Assembled libraries.yml matches source | `diff ... do_streams.libraries.yml ...` | identical | `IDENTICAL: libraries.yml` | PASS |
| Assembled DoStreamsHooks.php matches source | `diff ... DoStreamsHooks.php ...` | identical | `IDENTICAL: DoStreamsHooks.php` | PASS |

## Tier 2 results

| Check | Method | Verdict |
|---|---|---|
| No collateral edits beyond F's declared file list | `git diff --name-only HEAD -- docs/groups deploy .github tests/e2e` scoped to source paths (the raw `git diff --stat` against working tree includes hundreds of unrelated already-merged-story artifacts in `config/sync/`/`web/modules/custom/` that are pre-existing assembled build output from prior runs in this worktree, not part of this diff — confirmed via `git merge-base HEAD origin/main` == `HEAD`, i.e. zero commits on this branch yet, so all "diff" noise is uncommitted assembled-build cruft, not F's change) | PASS — scoped diff shows exactly the 4 files F declared as edited (`test.yml`, `entrypoint.sh`, `do_streams.libraries.yml`, `DoStreamsHooks.php`) + 3 new untracked files (`views.view.trending.yml`, `trending.css`, `trending.spec.ts`) |
| `entrypoint.sh` marker block placement | Read diff | PASS — 8-line append after `$DRUSH cr`, before `echo "[entrypoint] Install + seed complete"` / closing `fi`, exactly per brief Step 4 |
| `test.yml` marker block placement, no other line touched | Read diff | PASS — 7-line append after `# --- do_activity step_7xx END ---`, before `cache:rebuild -y`; diff shows ONLY these 7 added lines, zero other line touched/reindented |
| `do_streams.libraries.yml` — new `trending:` block | Read diff | PASS — sibling block to `following:`, same shape (`version: 1.x`, `css.theme` referencing `css/trending.css`) |
| `preprocessViewsView()` refactor preserves `following_feed` behavior | Read full diff of `DoStreamsHooks.php` | PASS — old single-guard `!==` replaced by a `$library_by_view_id` map with `FOLLOWING_FEED_VIEW_ID => 'do_streams/following'` unchanged in value, `TRENDING_VIEW_ID => 'do_streams/trending'` added; early-return preserved when `$view` isn't a `ViewExecutable` or id isn't in the map — byte-identical outcome for `following_feed` and every unrelated view id |
| `views.view.trending.yml` structural assertions (6 from T-red + wireframe) | Read full YAML | See per-assertion table below | PASS (all) |
| `PageHelpRouteMapTest` safety net still present | `grep 'view.trending.page_1'` in `do_chrome/tests/src/Kernel/PageHelpRouteMapTest.php` and `PageHelp.php` | PASS — both present, untouched (do_chrome dir diff is empty) |
| Demo-data claim (2-comment threads are forum-bundle nodes) | Read `step_700_demo_data.php:141,143,216-219` | PASS — both "Venue Logistics Thread" and "Patch Review Process RFC" created with `"type" => "forum"`, each receiving exactly 2 seeded comments — consistent with `trending.yml`'s `type` filter including the `forum` bundle and the 6.0 post-cron score the ordering test depends on |
| `type` filter non-exposed matches clone source | Read `following_feed.yml`'s `type` filter block | PASS — `following_feed.yml`'s `type` filter carries `expose: {operator: ''}` with no `exposed: true` key (i.e. genuinely not exposed); `trending.yml`'s `type` filter is byte-identical in shape. F's Design decision #2 rationale confirmed against the actual file, not just F's claim |
| `.stream-card-wrapper` styling ownership | `grep` for `stream-card-wrapper` across the tree | PASS — styled by the shared theme (`web/themes/custom/groups_chrome/css/stream.css`), confirming `trending.css` correctly does NOT duplicate card styling (additive-only, per brief Step 2) |

### Per-assertion inspection of `views.view.trending.yml`

| # | Assertion | Result |
|---|---|---|
| 1 | Anonymous 200 + H1 | `access.type: none`; `display_options.title: 'Trending'` on the `default` display (renders as page H1 via Views' default title-to-h1 template) | PASS |
| 2 | Score-ordered content | `sorts.score` (table `do_discovery_hot_score`, field `score`, `order: DESC`) then `sorts.created` (table `node_field_data`, `order: DESC`) tiebreak; `pager.options.items_per_page: 10`; `filters.type.value` includes `forum` (and `documentation`, `event`, `post`, `page`) — Venue Logistics Thread + Patch Review Process RFC are forum nodes, confirmed in seed script | PASS |
| 3 | Empty state string exact | `empty.area_text_custom.content: '<div class="gc-empty"><p class="gc-empty__title">Nothing trending yet.</p></div>'` — exact match to brief/issue-body copy | PASS |
| 4 | `/hot` regression | `git diff docs/groups/config/views.view.hot_content.yml` empty | PASS |
| 5 | Library attach | `do_streams.libraries.yml` has `trending:` block referencing `css/trending.css`; `DoStreamsHooks::preprocessViewsView()` attaches `do_streams/trending` for `view->id() === 'trending'` via the `$library_by_view_id` map; `following_feed` entry unchanged, still resolves to `do_streams/following` | PASS |
| 6 | Card class | `style.options.row_class: stream-card-wrapper` | PASS |
| 7 | WCAG | View has a `title` (renders as one H1); `pager.type: full` provides Views' standard accessible next/prev pager markup (existing core/contrib pattern, same as `following_feed.yml`'s pager) | PASS |

## Acceptance criteria status

| # | Criterion (brief.md) | Status | Backing test |
|---|---|---|---|
| 1 | `/trending` renders 200 for anonymous with `stream_card` cards | PASS (inspection) / pending CI execution | Test 1 (`anonymous GET /trending returns 200...`), Test 3 (`.stream-card-wrapper` renders) |
| 2 | Post-seed+cron: both threads in first 10 cards | PASS (inspection: config + seed data verified consistent) / pending CI execution | Test 2 |
| 3 | Empty state renders "Nothing trending yet." when no rows | PASS (inspection: exact string present in config) — not independently forced empty per brief instruction | Test 2 (mutual-exclusivity assertion), string verified directly in config |
| 4 | `views.view.hot_content.yml` unchanged | PASS | `git diff` (empty) + Test 4 |
| 5 | `tests/e2e/trending.spec.ts` green in CI; other specs still green | PENDING CI (cannot execute Playwright in this worktree — see environment constraint) | All 6 tests + existing suite |
| 6 | `do_chrome` HelpText mapping for `view.trending.page_1` resolves | PASS | `PageHelpRouteMapTest.php:52` present, untouched |
| 7 | Both cron triggers landed (BEGIN/END markers) | PASS | Direct diff read, both files |
| 8 | WCAG 2.2 AA minimums | PASS (inspection) / pending CI execution | Test 6 |
| 9 | Rebase onto latest `origin/main` clean; CI green on rebased head | N/A at this phase (O's Phase 10 responsibility) — note: branch currently equals `origin/main`'s merge-base with zero commits, so no rebase conflict risk from this diff alone | — |

## Blocking issues
None found via inspection. All Tier 1/Tier 2 checks available in this
environment pass. The two items genuinely deferred to CI (Playwright
execution-level GREEN for all 6 tests; confirmation the existing suite,
especially `following.spec.ts` and `page-help.spec.ts`, still passes
alongside this change) are environment-imposed, not evidence of a defect —
no reason found in inspection to expect either to fail.

## Advisory notes
1. **Watch in CI: the `nth-of-type(-n+10)` locator in Test 2.** `nth-of-type`
   counts among siblings sharing the same tag name within their parent, not
   simply "the Nth element matching the selector." If Drupal's Views markup
   wraps each `.stream-card-wrapper` in a `<div>` sibling-of-`<div>` structure
   (as is conventional for Views' unformatted/default row output), this holds
   correctly; if any other markup pattern interleaves different tag types
   between cards, the locator could behave unexpectedly. Since
   `items_per_page: 10` means the whole page IS the top-10 window in this
   story's case, the assertion is redundant-but-safe even if `nth-of-type`
   under/over-selects slightly — not a blocking concern, just worth a glance
   at CI's actual DOM if this specific test is the one that surprises anyone.
2. **`node_modules` absence blocks any local Playwright confirmation in this
   worktree for future phases too (U's UI walkthrough tool differs — it
   drives a live browser directly, not via this repo's Playwright config —
   so U is unaffected).** Flagging for O: if a future story needs local
   Playwright execution before CI, this worktree needs `npm install` (or an
   equivalent) run once.

## Ready for next phase
GREEN pending CI. UI surface (a new public route `/trending`) — **U is
required**, not N/A. Recommend O route this to U for a live-browser
walkthrough of `/trending` (anonymous + authenticated), `/hot` regression,
and `/following` regression, then to S for spec compliance, gated on CI
reporting all 6 new tests + the existing suite green.
