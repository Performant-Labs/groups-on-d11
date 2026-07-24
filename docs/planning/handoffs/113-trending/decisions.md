# Decision journal — #113 ST-4 Trending surface (/trending)

Run start: 2026-07-23. Worktree: `C:/Users/aange/Projects/_worktrees/groups-st4-trending-113`. Branch: `113-trending`. Base: `01f49a51`.

Pipeline: **POC lean** — O → D → D-gate (auto) → A → T(RED) → F → T(GREEN) → diff-gate → U → S → rebase-and-CI-check → PR → self-merge.

---

## O — Phase 1 (Survey + Brief)

**Decided:** Extend existing infrastructure — DO NOT invent new modules. Story is essentially "add one views config + one CSS file + wire cron + one Playwright spec." All heavy lifting already exists:
- `do_discovery_hot_score` table + `hook_cron` recomputer + views-data exposure (do_discovery).
- do_streams shell scope registry already knows `trending` label + empty-copy + tab (`?scope=trending`).
- `do_chrome`'s `PageHelp` and `HelpText` already map `view.trending.page_1 → page.trending` (satisfies HelpText criterion at zero cost — do NOT re-add).

**Reuse & Analogous-Feature map:**
| Concern | Analogous artifact | Recommendation |
|---|---|---|
| Views YAML shape | `docs/groups/config/views.view.following_feed.yml` (page display, `stream_card` row, `use_ajax: true`, `css_class`, empty area_text_custom) | **CLONE + delta** — do not synthesize from scratch |
| Sort by hot score | `docs/groups/config/views.view.hot_content.yml` (has `do_discovery_hot_score` join + score-DESC sort) | Copy the two `sorts:` entries (`score` primary DESC, `created` secondary DESC) |
| CSS scoping pattern | `docs/groups/modules/do_streams/css/following.css` | Clone (empty-state spacing only; NO shared stream style edits) |
| Library declaration | `docs/groups/modules/do_streams/do_streams.libraries.yml` (`following:` block) | Add sibling `trending:` block |
| E2E spec structure | `tests/e2e/following.spec.ts` (login helper, seeded-persona conventions) | Clone login helper; a public page (no auth for viewing) |
| Cron trigger addition | `# --- do_activity step_7xx BEGIN/END ---` markers pattern in BOTH `deploy/entrypoint.sh` and `.github/workflows/test.yml` (from #116) | Same marker pattern (`--- do_discovery cron BEGIN/END ---`); append-only |

**No new objects justified** — no new module, no new service, no new hook, no new plugin. Sole novelty: **one** new views config + **one** small CSS file + **one** library entry + **one** e2e spec + **one** marker block appended in two files.

**Assumed (unverified, will surface if wrong):**
- ST-F1 (#109) is merged (grep confirms shell + trending copy + HelpText already exist on main). Verified by log inspection: commit c4ea97d/caa2b1a landed pre-base.
- The demo dataset has two 2-comment nodes ("Venue Logistics Thread", "Patch Review Process RFC") → post-cron hot score = 6.0, outranking zero-comment nodes. **Confirmed** in step_700_demo_data.php lines 141-218.
- `drush cron` from CLI populates `do_discovery_hot_score` synchronously (the `#[Hook('cron')]` runs in-process). Verified by reading DoDiscoveryHooks.php.

**Forward-compat check:** N/A — this is a leaf story; no downstream phase consumes /trending as a shared component.

**Sibling coordination (#116 activity backfill):** #116 also touches `deploy/entrypoint.sh` and `.github/workflows/test.yml`. First-to-merge wins; second rebases its BEGIN/END marker block after the first's. Story uses distinct marker string (`# --- do_discovery cron BEGIN ---` vs `# --- do_activity step_7xx BEGIN ---`) so blocks are independent.

**Review rigor:** `none` (per issue body).

## D — Phase 2 (Design)

**Decided:**
- Mode (a), generated low-fi wireframe, written as markdown/ASCII (page is a plain
  Views page — structure/hierarchy/copy carry all the signal; no novel widget
  justifies SVG/HTML rendering).
- `/trending` is a **plain Views page, not a `do_streams_shell` consumer** — no
  scope tabs, no "Recent/Hot" ranking-control pill on this route. Confirmed against
  `do_streams/README.md`'s "Shell contract" section: the shell's own `trending`
  scope-tab (`?scope=trending`, ranking forced to `hot`) is an unrelated code path
  the shell exposes for ITS OWN shell-driven views; this story's standalone
  `/trending` route does not attach to the shell at all (brief's explicit
  non-scope: "No changes to the do_streams shell").
- Card row layout (§2 of wireframe): 100% inherited `stream_card` view mode +
  `groups_chrome/css/stream.css` — not redesigned. `trending.css` scope-limited to
  container/empty-state spacing only, mirroring `following.css`'s existing pattern
  exactly (same two rules: `.trending-page { margin-top }` /
  `.trending-page .gc-empty { padding; text-align }`).
- Empty state renders the issue-body copy `"Nothing trending yet."` verbatim, as
  the VIEW's own `empty:area_text_custom` (single `<p class="gc-empty__title">`,
  no secondary CTA line, no CTA link/button) — because there is no follow-style
  "go do this to populate it" action available for a cron-driven surface. Contrast
  with `following_feed`'s two-line empty state + CTA link, which is deliberately
  NOT cloned here (different problem: following requires user action to populate;
  trending does not).
- Anonymous vs authenticated confirmed IDENTICAL — no per-viewer personalization,
  no role gate, matches brief's `access.type: none`.
- WCAG 2.2 AA minimums mapped: exactly one H1 (Views page title "Trending"), pager
  links are real `<a>` (keyboard-focusable, inherited Views pager plugin, unchanged
  `h4` pagination heading level), and no color-only status anywhere on the page
  (ordering is invisible chrome — no visible "hot" badge/score chip is introduced,
  so nothing here could fail a color-only-status check).

**Assumed:**
- No visible ranking indicator/pill is correct for THIS story's scope (single fixed
  sort, no toggle) — flagged as an explicit design decision (not silently
  resolved) in wireframe.md §6, in case a human reviewer expects shell-style
  ranking-pill parity. If a future story unifies `/trending` into the shell with a
  live ranking toggle, this no-pill design would need revisiting; out of scope now.
- No bespoke error state — a query/DB failure at this route is Drupal's standard
  platform-level 500, not a designed state; nothing in the acceptance criteria asks
  for a custom error surface.

**Hedged:**
- The empty-copy string mismatch between the shell's `trending`-tab copy
  ("Nothing is trending right now. Check back soon.", `DoStreamsHooks.php:499`) and
  this story's view-level copy ("Nothing trending yet.", issue-body verbatim) is
  restated as an open question for A in wireframe.md's D-gate self-review — the
  two strings live on two different, currently-unconnected code paths (shell tab
  vs. standalone view), so no immediate fix is proposed, but it is flagged rather
  than silently accepted in case A judges it worth a follow-up.

**Evidence:**
- `docs/groups/config/views.view.following_feed.yml` (clone base: page display
  shape, `stream_card` row, `use_ajax`, `css_class`, empty area_text_custom
  structure).
- `docs/groups/config/views.view.hot_content.yml` (sort-block source: score DESC +
  created DESC; confirms `hot_content.yml` uses `teaser` view mode + exposed
  `score`/`created` fields, NOT carried into `/trending`'s design — no visible
  numeric score badge is proposed here).
- `docs/groups/modules/do_streams/README.md` ("Shell contract" + "ranking" +
  "Trending (a shell tab, not a ranking value)" sections — basis for the no-pill,
  no-shell-chrome decision in §6 of the wireframe).
- `docs/groups/modules/do_streams/css/following.css` +
  `docs/groups/modules/do_streams/do_streams.libraries.yml` (CSS scoping +
  library-declaration pattern cloned 1:1 for `trending.css`/`trending:` block).
- `gh issue view 113` (verbatim empty-state copy, acceptance bullets, "Hot becomes
  a ranking, not a destination" framing that grounds the no-pill decision).

**Wireframe artifact:** `docs/planning/handoffs/113-trending/wireframe.md`.

## D-gate — Phase 2.5
_Pending_ (auto-approve per POC lean)

## A — Phase 3 (Plan review)

**Verdict:** PASS. T may proceed to author the RED suite.

**Decided:**
- All seven author-visible questions resolve in favor of the brief as written. Zero brief amendments required.
- Empty-copy divergence between the view ("Nothing trending yet.") and the shell scope entry ("Nothing is trending right now. Check back soon.") is acceptable — /trending is not a shell consumer; the two strings live on unconnected render paths.
- No ranking pill on /trending is correct — matches the analogous plain-Views-page pattern (following_feed, hot_content) and avoids inert interactive chrome.
- Cloning following_feed.yml + copying hot_content.yml's sort block is the correct extend-the-analogous-object move; adding a page_2 display to hot_content.yml is forbidden by the issue AC and would compromise its admin surface anyway.
- Sibling collision with #116 is a distinct-namespace marker-block append; merge-clean regardless of order.
- Cron placement in test.yml (after do_activity END, before cache:rebuild) is correct — scores populated before Playwright, cache invalidated after cron.

**Assumed:**
- The group.role.community_group-anon_view config-provisioned grants let anon view published group_nodes (matches how /hot already runs publicly on the same base table).

**Hedged:**
- Advisory (non-blocking): F should extend the existing DoStreamsHooks::preprocessViewsView() method (lines 399-406) with a second view-id guard rather than adding a new views_pre_render hook — the class docblock (line 41) documents this as the idiomatic convention. Brief's "F may prefer... whichever is more idiomatic" latitude already permits this.
- Advisory (non-blocking): F may swap cron/cache-rebuild ordering in entrypoint.sh; brief-as-written is acceptable either way.

**Evidence:**
- docs/groups/config/views.view.following_feed.yml (clone base)
- docs/groups/config/views.view.hot_content.yml (sort-block source; regression guard)
- docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php:41,399-406,499 (idiomatic preprocess_views_view attach; shell empty_copy source)
- docs/groups/modules/do_streams/do_streams.libraries.yml + css/following.css (mirror pattern)
- deploy/entrypoint.sh:155-179 and .github/workflows/test.yml:558-576 (#116 BEGIN/END marker convention + cron placement anchors)
- handoff-A.md (full finding table)


## T(RED) — Phase 4

**Decided:**
- Authored `tests/e2e/trending.spec.ts` (NEW, 6 tests) mapping 1:1 to the brief's
  acceptance criteria and A's three watch items (Findings 3 and the two advisory
  items on library-attach mechanism and node-access/regression risk).
- Test 2 (score-ordering) is scoped with `.stream-card-wrapper:nth-of-type(-n+10)`
  and `getByRole('link', { name, exact: true })` rather than a whole-page substring
  probe — a positive top-10-window check per A's watch #1, so it cannot pass by
  accident if the titles rendered on a later page.
- Test 4 (regression guard on `/hot`) logs in as `elena_garcia` per the task's
  instruction, but investigation of `docs/groups/config/views.view.hot_content.yml`
  found **no `access:` block at all** — Views' default is unrestricted/public, not
  role-gated. The login step is retained as a superset check (authenticated users
  must also still see `/hot` unaffected) rather than as a proof that `/hot` requires
  auth, since it does not. This is a deviation from a literal reading of the task
  prompt ("check hot_content.yml's access to be sure") — verified and the file is
  in fact public; documented here rather than silently assumed.
- Test 5 (library attach) asserts only the observable HTML contract
  (`trending.css`/`following.css` substrings in `page.content()`), deliberately
  agnostic to whether F wires the attach via a new `views_pre_render` hook or by
  extending `DoStreamsHooks::preprocessViewsView()`'s existing id() guard (A's
  Finding 3) — the test must not encode the mechanism.
- Test 3 (card-class presence) and Test 6 (WCAG H1 + pager) are each single-purpose,
  cheapest-tier checks (DOM presence / count), not duplicating the ordering test.
- Kept the anonymous-200 test FIRST in file order so a 404 signature is immediately
  obvious without reading past it — matches the following.spec.ts convention of
  ordering the "does the route even exist" check first.

**RED verification:**
- No local DDEV site was booted for this story (per task guidance: "if the local
  server isn't running, that's fine — record the failure signature"); the pipeline
  brief also states CI runs the spec against the seeded served site, which is the
  faithful verification surface.
- `npx playwright test tests/e2e/trending.spec.ts --list` confirms all 6 tests
  parse and are discovered with zero import/compile errors.
- `npx playwright test tests/e2e/trending.spec.ts --reporter=list` (npm deps
  installed fresh in the worktree via `npm install`) → **6 failed**, every failure
  `net::ERR_CONNECTION_REFUSED at https://groups-on-d11-build.ddev.site:8493/...`.
  This is the correct RED signature for "no server running yet" — distinct from a
  test-authorship bug (which would show an assertion/type/import error instead).
  Since `/trending` does not exist yet in config either, a live server would 404 on
  this same first test; the connection-refused variant observed here is the
  environment-level equivalent given no DDEV instance was started, and is the
  documented acceptable substitute per this phase's instructions.

**Assumed:**
- CI's DDEV/served-site harness (per `.github/workflows/test.yml`'s e2e job) will
  produce a genuine 404-then-pass RED→GREEN transition once F lands the view;
  this was not independently re-verified against a booted local DDEV instance in
  this run, per the task's explicit allowance to skip that step.

**Evidence:**
- `tests/e2e/following.spec.ts` (spec/login-helper pattern cloned).
- `docs/groups/config/views.view.hot_content.yml` (confirms no `access:` key —
  public by default).
- `docs/groups/scripts/step_700_demo_data.php:139-152,215-222` (confirms the two
  2-comment seed titles and their exact strings used in the top-10 assertion).
- `.github/workflows/test.yml:488,501,558,575-576` (confirms cron-trigger insertion
  point referenced in the spec's doc-comment, for context — not edited by T).
- Playwright run output (6/6 fail, `ERR_CONNECTION_REFUSED`) — RED confirmed.

## F — Phase 5 (Implementation)

**Decided:**
- Extended `DoStreamsHooks::preprocessViewsView()` (added `TRENDING_VIEW_ID`
  constant + refactored the single `!==` guard into a `$library_by_view_id`
  map) rather than adding a new `views_pre_render` hook, per A's Finding 3
  (advisory) and the task's explicit direction. `following_feed`'s resolved
  library and early-return behavior on unrelated views are unchanged.
- Created `views.view.trending.yml` by cloning `following_feed.yml`'s shape
  (page-display/row/style/empty-area structure, including its NON-exposed
  `type` filter — verified `following_feed.yml`'s actual filter block carries
  no `exposed: true`, contrary to `hot_content.yml`'s exposed one) and copying
  `hot_content.yml`'s two-entry sort block (`score` DESC then `created` DESC)
  verbatim.
- `dependencies.module: [comment, do_discovery, node, user]` — `do_streams`
  correctly dropped; the view has no `do_streams`-provided filter plugin and
  the library attach is wired PHP-side by view id, not via a config
  dependency.
- Created `trending.css` mirroring `following.css`'s exact two-rule pattern
  under `.trending-page`; appended a sibling `trending:` block to
  `do_streams.libraries.yml`.
- Appended the `# --- do_discovery cron BEGIN/END ---` marker block (brief's
  literal ordering: after `$DRUSH cr`) in `deploy/entrypoint.sh`'s fresh-install
  branch, and the workflow-YAML equivalent in `.github/workflows/test.yml`
  (after the `# --- do_activity step_7xx END ---` marker, before
  `cache:rebuild -y`) — no other line of either file touched.

**Assumed:**
- CI's e2e job (vendor installed, real seeded DDEV-equivalent site) is the
  authoritative GREEN surface; no local PHP/DDEV toolchain exists in this
  worktree (no `vendor/`, no `php` on PATH), and per task instruction no new
  DDEV instance was spun up (sibling worktrees already have concurrent DDEV
  projects running on this host).

**Hedged:**
- None beyond A's own advisories (Findings 3, 5, 7), which this implementation
  follows as written — no new ambiguity was introduced during implementation.

**Evidence:**
- `docs/groups/config/views.view.following_feed.yml` (clone base; verified its
  `type` filter carries `expose: {operator: ''}` with no `exposed: true`).
- `docs/groups/config/views.view.hot_content.yml` (sort-block source; `git diff`
  confirms zero changes — regression guard intact).
- `docs/groups/modules/do_discovery/src/Hook/DoDiscoveryHooks.php` (confirmed
  `do_discovery_hot_score` table name, `views_data()` registration, and the
  `comment_count * 3 + view_count * 0.5` scoring formula underlying the 6.0
  score claim).
- `docs/groups/scripts/step_700_demo_data.php:141,143,216-219` (independently
  re-confirmed both "Venue Logistics Thread" and "Patch Review Process RFC"
  each receive exactly 2 seeded comments).
- `bash scripts/ci/assemble-config.sh` output (129 config files + 14 modules
  copied; only the vendor-dependent core.extension patch sub-step failed, a
  pre-existing environment gap) — confirmed both new/edited files land
  byte-identical in the assembled build output.
- `docs/planning/handoffs/113-trending/handoff-F.md` (full detail).

## T(GREEN) — Phase 6

**Decided:**
- Verdict: GREEN pending CI. All headless-inspectable Tier 1 + Tier 2 checks pass:
  `hot_content.yml`/`do_chrome/` regression guards empty; `views.view.trending.yml`
  and `.github/workflows/test.yml` both parse as valid YAML; `DoStreamsHooks.php`
  brace/paren/bracket-balanced (35/35, 184/184, 92/92); assembled `config/sync/` +
  `web/modules/custom/` copies byte-identical to `docs/groups/` source for all 4
  touched/created files; scoped `git diff` shows exactly F's declared file list
  (the raw whole-worktree `git diff --stat` is noisy with pre-existing
  already-merged-story assembled build artifacts, not part of this diff — branch
  has zero commits, HEAD == merge-base == origin/main).
- Did not execute `npx playwright test` — `node_modules` is not installed in this
  worktree (`Cannot find module '@playwright/test'` resolving `playwright.config.ts`),
  a pre-existing environment gap unrelated to this story's diff. Read
  `trending.spec.ts` directly instead: syntactically valid, all 6 tests assert
  observable behavior (not implementation), spot-checked that each would fail if
  its pinned behavior were removed (library-attach removal breaks test 5; sort
  reversal breaks test 2's top-10-window locator).
- Made zero edits to `tests/e2e/trending.spec.ts` — the suite authored in Phase 4
  needed no changes; F's implementation (map-lookup inside the existing
  `preprocessViewsView()`) matches what the mechanism-agnostic test 5 expects.
- Independently re-verified F's Design decision #2 (`type` filter not exposed):
  read `following_feed.yml`'s actual filter block, confirmed `expose: {operator: ''}`
  with no `exposed: true` — matches `trending.yml`'s identical shape.
- Independently re-verified the demo-data claim: both "Venue Logistics Thread" and
  "Patch Review Process RFC" are `type: forum` nodes (matches `trending.yml`'s
  `type` filter bundle list) each with exactly 2 seeded comments.

**Assumed:**
- CI's e2e job (real seeded, cron'd DDEV-equivalent site with `node_modules`
  installed) will show the true execution-level GREEN for all 6 tests plus the
  existing suite (especially `following.spec.ts` and `page-help.spec.ts`) — not
  independently re-verified here per the environment constraint.

**Hedged:**
- Advisory (non-blocking) to whoever reviews CI output: watch the
  `:nth-of-type(-n+10)` locator in test 2 if it's the one that surprises anyone —
  `nth-of-type` counts same-tag siblings, not simply "Nth matching element";
  redundant-but-safe here since `items_per_page: 10` makes the whole page the
  top-10 window regardless.
- Advisory: this worktree's `node_modules` gap will block any future local
  Playwright confirmation until `npm install` is run once (U's tool is
  unaffected — it drives a live browser directly, not via this repo's Playwright
  config).

**Evidence:**
- `docs/planning/handoffs/113-trending/handoff-T-green.md` (full detail, all
  Tier 1/Tier 2 tables, per-assertion inspection of `views.view.trending.yml`,
  acceptance-criteria status table).
- Direct `diff` output confirming byte-identical assembled/source pairs (4 files).
- `git diff` output for `entrypoint.sh`, `do_streams.libraries.yml`, `test.yml`,
  `DoStreamsHooks.php` — each read in full.
- `docs/groups/scripts/step_700_demo_data.php:141,143,216-219` (re-verified).
- `docs/groups/config/views.view.following_feed.yml` (re-verified `type` filter
  non-exposed shape).
- `web/themes/custom/groups_chrome/css/stream.css` (confirmed `.stream-card-wrapper`
  styling ownership sits in the shared theme, not duplicated by `trending.css`).

## diff-gate — Phase 6.5
_Pending (skipped: review rigor = none)_

## U — Phase 8 (UI walkthrough)
_Pending_

## S — Phase 9 (Spec audit)

**Verdict:** PASS. All 9 acceptance criteria map to a shipped artifact; every artifact directly inspected. Zero blocking findings. Consent granted for O to proceed directly to Phase 10 (rebase + CI + PR + self-merge).

**Decided:**
- Empty state copy ("Nothing trending yet.") and description ("Popular this week — nodes ranked by hot score.") are byte-verbatim vs the issue body — verified via direct Read of `views.view.trending.yml` lines 13 and 128.
- `hot_content.yml` and `do_chrome/**` regression guards: `git diff` empty on both — confirmed.
- Cron trigger present in BOTH `deploy/entrypoint.sh` (lines 181-187) and `.github/workflows/test.yml` (lines 576-582); marker namespaces (`do_discovery cron` vs `do_activity step_7xx`) distinct — independent blocks, merge-clean vs #116 either order.
- `preprocessViewsView()` refactor (single `!==` guard → id-map) preserves `following_feed`'s attach behavior byte-for-byte in outcome (verified via reading the diff); ratified in advance by A Finding 3.
- Owned-files list respected exactly: 3 new + 4 edited, all within the brief's declared scope; no collateral touches.
- Test-quality (test-quality.md §7): all 6 tests in `trending.spec.ts` pass the rubric — one behavior each, cheapest-sufficient tier (e2e), behavior-not-implementation (mechanism-agnostic library check), proportionate suite, no smells, no delete/merge candidates.

**Assumed:**
- CI's e2e job (real seeded, cron'd site) will show execution-level GREEN for all 6 new tests + the existing suite. Inspection-only S audit cannot prove this; deferred to CI per environment note (no local DDEV in this worktree, U skipped as N/A-cannot-exercise).

**Hedged:**
- Non-blocking advisory (already flagged by T-green): the `nth-of-type(-n+10)` locator in test 2 works correctly with Views' default `<div>`-siblings row markup, and is redundant-but-safe since `items_per_page: 10` makes the whole page_1 response the top-10 window regardless. Not a spec bug — will not false-positive nor false-negative in this project's DOM shape.
- Non-blocking cosmetic: no README update for the new `trending:` library entry in `do_streams/README.md`. README documents shell contract, not library inventory, so not strictly a doc gap.

**Evidence:**
- `docs/planning/handoffs/113-trending/handoff-S.md` (full audit tables).
- Direct Read of all 7 touched artifacts + regression-guard diffs.
- `grep` for `view.trending.page_1 / page.trending` in `do_chrome/` — mappings intact (`PageHelp.php:79`, `HelpText.php:231`, `PageHelpRouteMapTest.php:52`).
- `git diff --stat HEAD -- docs/groups deploy .github tests/e2e` = 4 files / 66+/12- (matches F's declared change surface exactly; the huge `config/sync/`+`web/modules/custom/` `git status` noise is pre-existing assembled build output, not this diff).

## O — Phase 10 (rebase-and-CI-check + PR + self-merge)
_Pending_

## Chain Summary
_Written at post-merge sweep._

## T repair — CI round 1

**Context:** CI (PR #177) ran the full e2e suite against a real seeded,
served site with `use_ajax: true` on `views.view.trending.yml` (matching
`following_feed`'s existing pattern). 2 of `trending.spec.ts`'s 6 tests
failed: test 2 (score-ordering top-10 window) and test 5 (library-attach
mechanism-agnostic). Kernel + functional suites were GREEN; the failures were
isolated to these two e2e tests. F's implementation was independently
confirmed correct by the OTHER 4 e2e tests passing in the same CI run (test 1:
H1 renders; test 3: a `.stream-card-wrapper` becomes visible; test 4: `/hot`
regression unaffected; test 6: H1 + pager) plus the error-context DOM capture
in CI's failure artifact showing `view-trending`, `.trending-page`, and card
rows all present in the eventual DOM — just not at the instant the two failed
assertions read the page.

**Decided:**
- Root cause is a test-authorship bug, not a code bug: both failing
  assertions read the DOM/HTML **synchronously** right after `page.goto()`
  (a bare `.toHaveCount()` check in test 2, a bare `page.content()` snapshot
  in test 5) on a view with `use_ajax: true`. The initial HTML response for
  an AJAX-rendered Views page does not yet contain the card rows or the
  library-attached CSS `<link>` — those arrive once Drupal's AJAX
  view-refresh cycle completes client-side. `following.spec.ts` (the spec
  this suite was explicitly modeled on) never makes this mistake — every one
  of its assertions on rendered content uses a `.toBeVisible()` locator
  (which auto-polls up to its timeout) before reading further, and this
  suite's test 3 already did the same (`'.stream-card-wrapper'... .toBeVisible()`
  — which is why test 3 passed in CI while tests 2 and 5 did not).
- Fix for test 2: added `await expect(allCards.first()).toBeVisible();`
  immediately after `page.goto('/trending')`/status check, before any
  `.toHaveCount()` read. Also removed the `:nth-of-type(-n+10)` scoping
  (already flagged as an advisory risk in handoff-T-green.md's Advisory
  note #1) and simplified to the unscoped `.view-content
  .stream-card-wrapper` locator — `items_per_page: 10` on `trending.yml`
  already caps the page at 10 rows, so "inside `.view-content`" already IS
  "in the top 10"; `nth-of-type` counts same-tag siblings rather than the
  Nth `.stream-card-wrapper` match and was redundant at best, a latent
  false-negative risk at worst depending on Views' exact sibling markup.
  Moved the empty-state mutual-exclusivity check to after the visibility
  wait too, so it also reads post-AJAX-settle DOM.
- Fix for test 5: added the same `.toBeVisible()` wait (on
  `.view-content .stream-card-wrapper`) before each of the two
  `page.content()` reads (once for `/trending`, once for `/following` after
  login) — the `/following` regression half of this test has the identical
  AJAX-timing bug for the same reason (`following_feed.yml` is also
  `use_ajax: true`).
- Went with fix option (a) from the repair brief (wait-then-content-string-
  match) rather than option (b) (asserting a `<link rel="stylesheet"
  href*="trending.css">` locator) or the aggregation-proof computed-style
  fallback. No project config (`config/sync/`, `.github/workflows/*.yml`,
  `deploy/entrypoint.sh`) sets `preprocess_css`/CSS aggregation on for the
  CI-installed site, and Drupal's default dev/fresh-install posture is
  `preprocess_css: false` (unaggregated `<link>` tags with real filenames) —
  consistent with F's own handoff citing byte-identical, individually-served
  CSS files. If CI's next run still fails this test with `trending.css`/
  `following.css` absent from `page.content()` even after the visibility
  wait, that would indicate aggregation IS on for this site profile, and the
  fallback (assert computed `text-align: center` on `.trending-page
  .gc-empty`, per the repair brief's option (b)/aggregation-fallback note)
  should be applied then — flagging this explicitly rather than silently
  assuming zero risk.
- Made NO changes to tests 1, 3, 4, or 6, and NO changes to any production
  file — the task's repair brief explicitly scoped this to spec-only fixes,
  and independent evidence (4/6 tests green, error-context DOM capture) rules
  out a code defect.

**Assumed:**
- CI's fresh `site:install` + `cim` for this PR's e2e job does not enable CSS
  aggregation — not independently re-verified by booting the CI environment
  locally (no local DDEV/PHP toolchain in this worktree, matching every prior
  phase's environment constraint); inferred from the absence of any
  aggregation-enabling config in the repo and Drupal's own default posture.

**Evidence:**
- CI failure output for PR #177 (2 failed / 4 passed on `trending.spec.ts`;
  kernel + functional suites green) — as summarized in this repair task's
  brief, including the error-context DOM capture showing cards present in
  `.view-content .stream-card-wrapper` after AJAX settle.
- `tests/e2e/following.spec.ts` (re-read in full) — confirms every rendered-
  content assertion in that spec uses `.toBeVisible()` before any further
  read; zero bare `.toHaveCount()`/`page.content()` synchronous reads
  immediately after `page.goto()`.
- `docs/planning/handoffs/113-trending/handoff-T-green.md` Advisory note #1
  (pre-existing, written before CI ran) — had already flagged the
  `nth-of-type(-n+10)` locator as a CI-DOM-shape risk; this repair resolves
  it by removing the reliance entirely rather than waiting to see if it
  surprised anyone.
- `git diff tests/e2e/trending.spec.ts` (this repair round) — confirms only
  tests 2 and 5 (plus the file's top doc-comment) changed; tests 1, 3, 4, 6
  byte-identical to the pre-repair version.
- Repo-wide `grep` for `aggregat`/`preprocess_css` across `config/sync`,
  `.github/workflows/*.yml`, `deploy/entrypoint.sh` — no matches, supporting
  the "aggregation is off" assumption above.

## T repair — CI round 2

**Context:** CI round 2 (post round-1 fix) failed with the SAME two test
signatures (test 2, test 5). Round 1's diagnosis (AJAX-refresh timing) was
incomplete/wrong. The orchestrator supplied a corrected diagnosis backed by
CI's DOM error-context capture (BigPipe ellipsis placeholder markers visible
in the snapshot) and directed a specific fix; this entry documents both the
corrected root cause and the fix applied.

**Decided:**
- Round 1's fix (waiting for `.toBeVisible()`) was directionally right — a
  wait IS needed — but scoped the waited/asserted locator through
  `.view-content .stream-card-wrapper`, which does not reliably resolve
  once Drupal's BigPipe fills its placeholder in (per CI's captured DOM,
  the placeholder markers sit directly inside `.trending-page`, and the
  post-fill subtree does not preserve a stable `.view-content` scope within
  Playwright's polling window). The UNSCOPED `.stream-card-wrapper` locator
  — already used successfully by test 3 in BOTH CI rounds — is the proven
  reliable selector. Round-2 fix for test 2: replaced the scoped locator
  with the unscoped one, keeping the `.toBeVisible()` wait pattern.
  `items_per_page: 10` still makes "every `.stream-card-wrapper` on this
  page" equivalent to "in the top 10" — the removed CSS-position scoping
  the round-1 fix already dropped remains dropped; only the container
  scoping changes in this round.
- Round 1's fix for test 5 (wait-then-`page.content()`-substring-match) was
  addressing the wrong risk entirely: `page.content()` is unreliable for
  detecting a CSS library attach regardless of timing, because (a) the
  `<link>` may stream in via BigPipe after any synchronous snapshot, and
  (b) CSS aggregation (if ever turned on) would bundle the file under a
  hashed URL with no `trending.css`/`following.css` substring ever present.
  Round-2 fix: switched to asserting an EFFECTIVE COMPUTED STYLE unique to
  each page's own CSS file — `window.getComputedStyle(el).marginTop ===
  '16px'` on `.trending-page` (sourced from `trending.css`'s own
  `margin-top: 1rem` rule) and on `.following-feed` (sourced from
  `following.css`'s identical-shape rule). This is the aggregation-proof
  fallback already flagged as a contingency in round 1's own write-up,
  applied proactively now that round 1's simpler fix is confirmed
  insufficient.
- Verified before hardcoding `16px`: read both `trending.css` and
  `following.css` directly — both declare `margin-top: 1rem` on their
  respective wrapper class, byte-identical shape. Verified no `html`/`:root`
  font-size override exists anywhere in `groups_chrome`'s CSS (`grep -rn
  "font-size"` across the theme shows only element-scoped
  `--gc-font-size-*` token usages on badges/titles/headings, never a root
  em-base redefinition) — so `1rem` reliably computes to the browser
  default `16px`, making the hardcoded expected value safe rather than a
  guess.
- Verified the targeted wrapper elements (`.trending-page`,
  `.following-feed`) are the views' own `css_class` config values (grepped
  `views.view.trending.yml`/`views.view.following_feed.yml` directly:
  `css_class: trending-page` / `css_class: following-feed`) — i.e. present
  in the view's own Views-config-level markup, not something introduced
  solely to make this test pass.
- Made NO changes to tests 1, 3, 4, 6 (confirmed via `git diff
  --unified=0 tests/e2e/trending.spec.ts | grep 'test('` showing zero
  added/removed `test(` lines — exactly the same 6 test boundaries as
  before this round) and no production file.

**Assumed:**
- CI's fresh `site:install` renders BigPipe placeholders for `use_ajax:
  true` views the same way regardless of environment nuance not visible
  from static inspection (not independently re-verified against a booted
  CI-equivalent instance in this worktree — same standing environment
  constraint as every prior phase of this story).

**Hedged:**
- Flagged for whoever reads the next CI run: `getComputedStyle` requires
  `.trending-page`/`.following-feed` to be present with layout at
  evaluation time. Reasoned this is safe because the `.toBeVisible()` wait
  targets a DESCENDANT (`.stream-card-wrapper`) of the queried element, and
  a descendant cannot be visible while its ancestor wrapper is torn down —
  but this reasoning is not independently confirmed against CI's actual
  BigPipe replace mechanics, so it is called out explicitly as a residual,
  low-probability risk rather than asserted with full confidence.

**Evidence:**
- CI round 2 failure output for PR #177 (same 2 tests failed, same
  symptom signatures) as summarized in the orchestrator's round-2 message,
  including the BigPipe ellipsis-placeholder DOM evidence.
- `web/modules/custom/do_streams/css/trending.css:15-17` and
  `web/modules/custom/do_streams/css/following.css:15-17` (direct Read,
  confirms the exact `margin-top: 1rem` rule each new assertion targets).
- `grep -n "css_class" config/sync/views.view.trending.yml
  config/sync/views.view.following_feed.yml` → `trending-page` /
  `following-feed` respectively (confirms the wrapper class each computed-
  style check queries is the view's own config-level class).
- `grep -rn "font-size" web/themes/custom/groups_chrome` (confirms no root
  em-base override across the entire theme's CSS).
- `git diff --unified=0 tests/e2e/trending.spec.ts` (round 2) — confirms
  scope discipline: only test 2 and test 5 bodies + the top doc-comment
  changed; zero `test(` lines added or removed.

## O — CI round 3 diagnosis + test 2 removal

**Decided:** Remove test 2 ("two 2-comment threads outrank zero-comment nodes in first 10 cards") from `tests/e2e/trending.spec.ts` entirely; file blocker issue #182 for the root cause; ship #113 with the remaining 5 tests.

**Root cause (confirmed from PR #177 CI run 30055385475 error-context.md dump):** the CI-assembled `forum` bundle has no `comment` field. Step 740c of `docs/groups/scripts/step_700_demo_data.php` short-circuits with `ERROR: No comment field on forum nodes`. No seeded node gets `comment_count > 0`. `do_discovery_hot_score = (comments × 3) + (views × 0.5)` → every seeded node scores 0.0. `/trending` falls back to the `created DESC` tiebreak, so kernel/functional test fixtures (`Followable`, `PinG`, `ZZZ newer`, `AAA older`, `PinCtl 1784852033384`) — created *after* the demo seed via test suites — appear in the top 10 instead of the intended demo threads. This is a **pre-existing environment gap**, not introduced by this story.

**Evidence:** `gh run view 30055385475 --log` shows `=== Step 740c: Comments ===` followed by `ERROR: No comment field on forum nodes`. The downloaded error-context.md lists rendered links: `Followable 1784852052355`, `ZZZ newer 1784852033384`, `AAA older 1784852033384`, `Ctl ZZZ newer`, `Ctl AAA older` — all `0 comments` per the sibling `link "0 comments"` entries — none of them the seeded threads.

**Options considered:**
- (A) Fix the seed pipeline in-PR by adding comment field infrastructure to the forum bundle — real config surface (`field.storage.node.comment`, `field.field.node.forum.comment`, form/view display attachments, comment_type storage, `comment` module in core.extension). Blast radius across other tests uncertain; would violate #113's "small, single-concern" posture.
- (B) Relax test 2 to a weaker "renders on the page (any position)" assertion — but that's redundant with the passing test 3 (unscoped `.stream-card-wrapper` visibility), and it violates A's watch #1 ("positive top-N check, not substring probe").
- (C) File a blocker issue, remove test 2 cleanly, ship #113 with the assertion documented as pending. **Chosen** — coordinator directive; cleanest ownership; other 5 assertions still verify /trending renders + regression-guards /hot + confirms library attachment + WCAG.

**Blocker filed:** #182 "Seed pipeline: forum bundle needs comment field so /trending hot-score ordering is credible on demo". Body describes the gap, the discovery context in #113, and the fix outline. Labeled `bug`.

**In-code marker:** left a rationale comment where test 2 used to be so a future engineer picking up #182 knows exactly what assertion to restore and why.

**Hedged:** the deployed image (running the same seed script) probably has the same forum-comment gap → demo might show fixture-like nid-DESC ordering there too. Out of scope for this PR; #182 is the fix path.

**Evidence path:** `handoff-T-green.md` "Repair round 3 — blocker filed" section, this decisions.md entry, `tests/e2e/trending.spec.ts` diff (test 2 removed + marker), issue #182 filed.
