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

## Repair round 1 (post-CI, PR #177)

**Trigger:** CI ran the full e2e suite against a real seeded, served site
(the execution-level GREEN this handoff's original verdict deferred to). 4 of
6 `trending.spec.ts` tests passed (test 1: H1; test 3: card visible; test 4:
`/hot` regression; test 6: H1 + pager). 2 failed: test 2 (score-ordering
top-10 window) and test 5 (library-attach mechanism-agnostic). Kernel and
functional suites reported GREEN in the same CI run.

### Root cause: AJAX-timing bug in the test suite, not F's implementation

`views.view.trending.yml` has `use_ajax: true` — same as `following_feed`, per
the brief's clone-source and D's wireframe. On a view with AJAX enabled, the
HTML returned by the initial `page.goto()` navigation does NOT yet contain
the rendered card rows or the library-attached `<link rel="stylesheet">` —
Drupal's Views AJAX behavior swaps those in once the AJAX cycle completes
client-side. Both failing assertions read the DOM/HTML **synchronously**,
immediately after `page.goto()`, with no wait for that cycle to settle:

- Test 2, old code: `await expect(allCards).not.toHaveCount(0);` —
  `.toHaveCount()` auto-polls, but only for ~5s by default, which was not
  reliably enough time on the CI runner for the AJAX response to land.
- Test 5, old code: `const trendingHtml = await page.content();` — this is a
  one-shot synchronous snapshot with **no polling at all**; it captures
  whatever HTML exists at that exact instant, which on an AJAX view is the
  pre-swap markup.

This is confirmed as a test bug, not an implementation bug, by:
1. Test 3 in this same file already does this correctly
   (`page.locator('.stream-card-wrapper').first()).toBeVisible()` — a
   locator assertion that polls up to its full default timeout, ~30s) and it
   passed in CI.
2. `tests/e2e/following.spec.ts` — the spec this suite was explicitly modeled
   on, and which exercises the same `use_ajax: true` `following_feed` view —
   uses `.toBeVisible()` for every rendered-content assertion, never a bare
   `.toHaveCount()` or `page.content()` read straight after navigation.
3. CI's error-context capture for the failing tests shows the cards
   present in `.view-content .stream-card-wrapper` in the DOM — just
   arriving after Playwright had already read/counted.

### Fixes applied (spec-only; production code untouched)

**Test 2** (`tests/e2e/trending.spec.ts`):
- Added `await expect(allCards.first()).toBeVisible();` immediately after the
  status check, before any count/text assertion — waits (auto-polling up to
  30s) for the AJAX cycle to settle.
- Removed the `:nth-of-type(-n+10)` locator scoping. This was already
  flagged as a risk in this handoff's original Advisory note #1
  (`nth-of-type` counts same-tag siblings, not "the Nth `.stream-card-wrapper`
  match," and depends on Views' exact sibling markup shape). Since
  `items_per_page: 10` already caps the page at 10 rows, "inside
  `.view-content`" already IS "the top-10 window" — the extra CSS scoping
  was redundant and a latent flakiness source, not a needed guarantee.
  Simplified to the unscoped `.view-content .stream-card-wrapper` locator.
- Moved the empty-state mutual-exclusivity check
  (`not.toContainText('Nothing trending yet.')`) to after the visibility
  wait, so it also reads post-AJAX-settle DOM.

**Test 5** (`tests/e2e/trending.spec.ts`):
- Added `await expect(page.locator('.view-content .stream-card-wrapper')
  .first()).toBeVisible();` before each of the two `page.content()` reads —
  once for `/trending`, once for `/following` (post-login) — since
  `following_feed` is also `use_ajax: true` and has the identical
  timing risk on its half of this regression-guard test.
- Kept the assertion shape (`expect(html).toContain('trending.css' /
  'following.css')`) unchanged — chose repair-brief option (a) over option
  (b) (asserting a `link[href*=...]` locator) or the CSS-aggregation-proof
  computed-style fallback.

**Why option (a), and the residual CSS-aggregation risk:** no config in this
repo (`config/sync/`, `.github/workflows/*.yml`, `deploy/entrypoint.sh`) sets
CSS aggregation on for the CI-installed site, and Drupal's default
fresh-install posture is `preprocess_css: false` (individual, unaggregated
`<link>` tags with real filenames in their `href`) — consistent with F's own
handoff describing byte-identical, individually-served CSS files, and with
the fact that test 5 previously failed on a *timing* signature (empty/stale
snapshot) rather than a *missing-filename* signature (aggregated bundle URL
with no `trending.css` substring at all). If CI's next run still fails this
test with `trending.css`/`following.css` absent from `page.content()` **even
after** the visibility wait, that would point to aggregation actually being
on for this site profile — the fallback in that case is to switch to
asserting an effective computed style unique to `trending.css` (e.g.
`text-align: center` on `.trending-page .gc-empty`), which survives
aggregation because it's a behavior check rather than a string-match on the
asset URL. Flagging this now rather than silently assuming zero risk.

**Not touched:** tests 1, 3, 4, 6 (byte-identical to pre-repair); no
production file (views config, CSS, PHP hook, library YAML, cron markers) —
F's implementation is independently confirmed correct by the passing subset
of this same CI run plus the error-context DOM capture.

### Verification in this environment

`node_modules` is still not installed in this worktree (`npx playwright test
tests/e2e/trending.spec.ts --list` → `Cannot find module '@playwright/test'`
resolving `playwright.config.ts` — the same pre-existing environment gap
noted in this handoff's original Tier 1 table and in F's handoff). Verified
statically instead:
- `git diff --stat tests/e2e/trending.spec.ts` → 41 insertions / 10 deletions,
  scoped entirely to test 2, test 5, and the file's top doc-comment (new AJAX-
  timing note). Confirmed via full diff read that tests 1, 3, 4, 6 are
  byte-unchanged.
- Balanced-braces/parens check on the rewritten file (23/23 braces, 142/142
  parens) and a `test count: 6` regex check — file still declares exactly 6
  tests, all syntactically closed.
- No assembled/module-local duplicate of `trending.spec.ts` exists elsewhere
  in the tree (`tests/e2e/` is the sole location; e2e specs are not part of
  the `assemble-config.sh` module-copy step) — nothing else needed updating.

**Verdict: repair round 1 complete.** CI re-run is the authoritative
execution-level confirmation (per this environment's standing constraint);
static verification here gives no reason to expect either fixed test to fail
for a NEW reason. Flag to whoever reads the next CI run: if test 5 fails
again with the SAME `trending.css`-absent-from-`page.content()` signature
after this fix, escalate to the aggregation-fallback approach described
above rather than re-attempting the wait-only fix a third time.

## Repair round 2 (post-CI, PR #177, round 2)

**Trigger:** Round 1's fix (waiting for `.toBeVisible()` on a
`.view-content .stream-card-wrapper` locator, plus a `page.content()`
substring match for the library-attach check) did NOT resolve CI — the SAME
two tests (test 2 and test 5) failed again, with the same underlying
symptoms (card locator resolves to 0, CSS filename absent from
`page.content()`).

### Round 1's diagnosis was wrong; corrected diagnosis

Round 1 attributed the failure to AJAX-refresh timing (`use_ajax: true`
swapping in the view body client-side after the initial response). The
orchestrator's round-2 diagnosis, backed by CI's DOM error-context capture
showing BigPipe ellipsis placeholder markers (`··············`) immediately
inside `.trending-page`, is the correct root cause: **Drupal's BigPipe**
streams the view body into the page as a placeholder-then-replace, and the
placeholder-fill does not preserve the `.view-content` parent scope reliably
within Playwright's polling window — the CSS-scoped locator
(`.view-content .stream-card-wrapper`) kept resolving to 0 matches even
after a `.toBeVisible()` wait, while the UNSCOPED `.stream-card-wrapper`
locator (test 3's pattern) resolves correctly once BigPipe settles. Round 1
fixed the *wait* but kept the wrong *selector scope*, which is why it still
failed identically.

Separately, and independent of the BigPipe timing question: `page.content()`
is not a reliable way to detect a CSS library attach at all, regardless of
when it's read — the `<link>` may be delivered via BigPipe after any given
snapshot, or the site's CSS may be aggregated (bundled under a hashed URL
with no `trending.css`/`following.css` substring ever present in the
served HTML). Round 1's fix (waiting, then still reading `page.content()`)
could not have worked reliably either way.

### Fixes applied in round 2 (spec-only; production code untouched)

**Test 2:** Replaced the `.view-content .stream-card-wrapper` locator with
the plain, unscoped `.stream-card-wrapper` locator — the same selector test
3 already uses and which CI has proven reliable (test 3 passed in BOTH CI
rounds). `items_per_page: 10` on `trending.yml` still caps the page at 10
rows, so every `.stream-card-wrapper` matched on `/trending` already IS "in
the top 10" — no CSS-position scoping is needed to make that claim true, and
none was reliable against BigPipe-streamed markup regardless.

**Test 5:** Replaced the `page.content()` substring match with an assertion
on an EFFECTIVE COMPUTED STYLE unique to each page's own scoped CSS file:
- `/trending`: `window.getComputedStyle(page.locator('.trending-page'))
  .marginTop` must equal `'16px'` — sourced from `trending.css`'s own
  `.trending-page { margin-top: 1rem }` rule (verified by direct Read of
  `web/modules/custom/do_streams/css/trending.css:15-17`).
- `/following` (regression half): same pattern against `.following-feed`,
  sourced from `following.css`'s `.following-feed { margin-top: 1rem }`
  (verified by direct Read of
  `web/modules/custom/do_streams/css/following.css:15-17`).
- Verified no `html`/`:root` font-size override exists anywhere in
  `web/themes/custom/groups_chrome/css/*.css` (`grep -rn "font-size"`
  across the theme shows only element-scoped `--gc-font-size-*` custom-
  property usages — badges, card titles, section headings — never a root
  em-base redefinition), so `1rem` reliably computes to the browser default
  `16px` for this assertion.
- This is a behavior check (does the loaded stylesheet's rule take visible
  effect on the element it targets?), not a string-match on the delivery
  mechanism, so it holds regardless of BigPipe streaming order or CSS
  aggregation — directly addressing the concern flagged (but not yet acted
  on) in round 1's own write-up.
- Kept the `.toBeVisible()` wait on the unscoped `.stream-card-wrapper`
  locator before evaluating computed style, so the assertion still only
  runs once BigPipe has settled and `.trending-page`/`.following-feed` (the
  view's own outer wrapper, present from the view's `css_class` config) has
  its CSS applied.

**Not touched:** tests 1, 3, 4, 6 (still byte-identical — confirmed via
`git diff --unified=0` showing no `test(` line added/removed, i.e. exactly
the same 6 test boundaries as before); no production file.

### Verification in this environment

Same environment gap as rounds 1 and the original phase (`node_modules` not
installed in this worktree — cannot execute Playwright locally). Verified
statically:
- Balanced braces/parens (26/26, 146/146) and `test count: 6` — file still
  parses as 6 complete tests.
- `git diff --unified=0 ... | grep 'test('` shows zero added/removed `test(`
  lines — confirms only bodies of tests 2 and 5 changed, same as round 1's
  scope discipline.
- Direct Read of both `trending.css` and `following.css` confirms the exact
  `margin-top: 1rem` rule each assertion depends on.
- Direct Read + grep of `views.view.trending.yml` / `views.view.following_feed.yml`
  confirms `css_class: trending-page` / `css_class: following-feed`
  respectively — the wrapper element the computed-style check targets is
  the view's own outer class, present in the view's Views-config-level
  markup (not something F added specifically for this test).

**Concern flagged for whoever reads the next CI run:** `getComputedStyle`
requires the queried element to actually be attached with layout at
evaluation time — if BigPipe's placeholder-to-content swap ever detaches and
re-creates `.trending-page` itself (as opposed to only the inner
`.view-content`/card markup), the `.toBeVisible()` wait on
`.stream-card-wrapper` (a descendant) should still guarantee `.trending-page`
is present and stable by the time the computed-style read happens, since a
descendant can't be visible while its ancestor is torn down. This is
considered a low-risk, standard-Playwright-idiom concern rather than a
known failure mode, but is called out explicitly per the task's request
rather than assumed away.

**Verdict: repair round 2 complete.** CI re-run is the authoritative
execution-level confirmation. If test 5 fails a third time with a
computed-style mismatch (not `'16px'`), that would mean either the theme
introduces a root font-size override this inspection missed, or the CSS
file genuinely isn't loading — at that point escalate with the actual
observed computed-style value from CI's error context rather than
re-guessing.

## Repair round 3 — blocker filed, test 2 removed

**CI round 3 (post-rebase, run 30055385475):** test 5 (library attach) now GREEN via the getComputedStyle repair. Test 2 STILL fails, but not for the reason theorized in rounds 1-2. Downloading the error-context artifact and reading the page-snapshot yaml showed the actual top-10 rendered cards on `/trending` are ALL kernel/functional-test fixture nodes (`Followable 1784852052355`, `PinG 1784852033384`, `ZZZ newer`, `AAA older`, `Ctl ZZZ newer`, `Ctl AAA older`, `PinCtl`, all with `link "0 comments"`), not the seeded demo forum threads.

**Root cause is upstream of the test:** the CI seed pipeline itself never creates the comments the assertion depends on. `docs/groups/scripts/step_700_demo_data.php` Step 740c short-circuits on `if (!$comment_field)` because the `forum` bundle has no comment field attached in the assembled config. With zero comments seeded, every node's `do_discovery_hot_score = 0.0` and `/trending` degenerates to the `created DESC` tiebreak — which puts fixture nodes on top.

**Coordinator decision (option 3 of 3 escalated):** file a blocker for the seed gap, remove test 2 in-PR, ship #113. Rationale:
- (B) "relax to render-anywhere" is redundant with test 3 (`.stream-card-wrapper` visibility) and violates A's watch #1.
- (A) "fix seed in-PR" needs comment-module + comment_type + field storage + field + form/view display + core.extension change — real cross-cutting surface, out of #113's scope.
- (C) file + defer keeps this PR small and single-concern.

**Blocker filed:** #182 "Seed pipeline: forum bundle needs comment field so /trending hot-score ordering is credible on demo".

**Change to spec:** test 2 removed from `tests/e2e/trending.spec.ts` (was lines ~110-154). A comment marker replaces it, pointing at #182 and describing the assertion to restore once the seed gap is fixed. Suite drops from 6 tests to 5.

**Post-fix suite (5 tests, all expected to pass on next CI):**
1. Anonymous GET /trending → 200 + exactly one `<h1>` matching /trending/i.
2. At least one `.stream-card-wrapper` card renders on /trending.
3. Regression guard: /hot → 200 + "Hot Content" label (views.view.hot_content.yml untouched).
4. Library attach mechanism-agnostic (getComputedStyle on `.trending-page` + `.following-feed`).
5. WCAG-adjacent: exactly one `<h1>`; pager Next link (if present) has accessible name.
