# Handoff-F: Phase 5 - #113 ST-4 Trending surface (/trending)

**Date:** 2026-07-23
**Branch:** 113-trending
**Issue:** #113

## What was done
- CREATED `docs/groups/config/views.view.trending.yml` — new public Views page at
  `/trending`, cloned from `views.view.following_feed.yml`'s shape with the
  `score DESC, created DESC` sort block copied verbatim from
  `views.view.hot_content.yml`.
- CREATED `docs/groups/modules/do_streams/css/trending.css` — scoped
  container/empty-state spacing rules, mirroring `css/following.css`'s exact
  two-rule pattern (`.trending-page` container + `.trending-page .gc-empty`).
- EDITED `docs/groups/modules/do_streams/do_streams.libraries.yml` — appended a
  `trending:` library block sibling to the existing `following:` block.
- EDITED `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` — added
  `const TRENDING_VIEW_ID = 'trending'` alongside `FOLLOWING_FEED_VIEW_ID`, and
  refactored `preprocessViewsView()` from a single `!==` guard into a
  view-id-to-library map covering both views (see Design decisions below).
  Updated both docblocks (the constant's and the method's) to describe the
  now-dual-view scope.
- EDITED `deploy/entrypoint.sh` — appended the `# --- do_discovery cron
  BEGIN/END ---` marker block (7 lines incl. the guarded `$DRUSH cron` call)
  inside the fresh-install branch, directly after the existing `$DRUSH cr`
  line and before the "Install + seed complete" echo / closing `fi`.
- EDITED `.github/workflows/test.yml` — appended the same-named 7-line marker
  block in the e2e job's demo-data seed step, directly after the
  `# --- do_activity step_7xx END ---` marker and before the trailing
  `cache:rebuild -y` line. No other line of this file was touched.

## Design decisions
1. **Library attach: extended `preprocessViewsView()`, did NOT add a new hook.**
   Per A's Finding 3 (advisory, within the brief's own "F may prefer... whichever
   is more idiomatic" latitude) and the task's explicit instruction, I refactored
   the existing single-view `!==` guard into a `$library_by_view_id` associative
   array (`FOLLOWING_FEED_VIEW_ID => 'do_streams/following'`,
   `TRENDING_VIEW_ID => 'do_streams/trending'`), looked up by `$view->id()`, with
   a single early return when the id isn't in the map. This preserves the
   existing behavior for `following_feed` exactly (still resolves to
   `do_streams/following`, still a no-op on every unrelated view) while adding
   `trending` as a second entry rather than a second hook implementation,
   keeping the class's documented "one lightweight preprocess method, guard on
   view id" convention (see the class's own docblock, now updated) intact for
   a third/fourth view if one is ever added.
2. **`views.view.trending.yml`'s `type` (content-bundle) filter is NOT exposed.**
   The brief's Step 1 prose says "exposed content-type filter" and separately
   instructs "Not exposed unless following_feed exposes it — verify." I read
   `following_feed.yml`'s actual `type` filter block: it carries
   `expose: {operator: ''}` with no `exposed: true` key, i.e. it is NOT actually
   exposed to end users despite `hot_content.yml`'s analogous filter being
   exposed (`exposed: true`, with a full `expose:` block). Since the brief's
   clone-base is `following_feed.yml` for the filter/style/row shape (only the
   *sort* block comes from `hot_content.yml`), I copied `following_feed.yml`'s
   non-exposed `type` filter verbatim rather than `hot_content.yml`'s exposed
   one. This matches wireframe §1's parenthetical ("optional inherited exposed
   content-type filter") loosely but is the literal, verified state of the
   file the brief told me to clone.
3. **`dependencies.module` for the new view:** `[comment, do_discovery, node,
   user]`, exactly as the brief specified — `do_streams` deliberately dropped
   since this view is not a shell consumer and does not reference any
   `do_streams`-provided filter plugin (unlike `following_feed`, which depends
   on `do_streams` for its `do_streams_following_scope` filter). The library
   attach itself is wired via a `do_streams`-side hook keyed on view id, not
   via a config-level dependency, so no `do_streams` config dependency is
   needed on the view side.
4. **Cron placement in `entrypoint.sh`:** kept the brief's as-written ordering
   (`$DRUSH cr` then the new cron block), per A's Finding 7 explicitly noting
   this ordering is acceptable ("hot_score is not cached-per-request; the view
   executes a fresh JOIN each render") and offering the cron-before-cr swap only
   as an optional, non-blocking alternative. No reason to deviate from the
   brief's literal ordering when A already validated it.
5. **`test.yml` insertion mechanics:** the file is 654 lines; rather than
   rewriting the whole file via `Write` (transcription risk on a huge file), I
   used a precise single-line `sed` insertion anchored on the exact
   `do_activity step_7xx END` marker text, then verified the resulting diff is
   the intended 7-line append and nothing else, and re-validated the file still
   parses as YAML.

## Reuse / extend-vs-new
Extended two existing objects, created zero new modules/services/plugins/hooks:
- **View config**: cloned `views.view.following_feed.yml` (page-display shape,
  `stream_card` row, `use_ajax`, filters, empty-area structure) + copied
  `views.view.hot_content.yml`'s sort block (score DESC / created DESC) — per
  the brief's Reuse map, both are "clone + delta," not new invention.
- **Library attach mechanism**: extended
  `DoStreamsHooks::preprocessViewsView()` (the SAME method already handling
  `/following`'s attach) rather than creating a new `views_pre_render` hook —
  per A's Finding 3, this was the brief's own recommended default and the task
  instruction's explicit direction. This is the "extend the analogous object"
  path the pipeline defaults to; no parallel path was created.
- **CSS scoping pattern**: mirrored `css/following.css`'s exact two-rule shape
  under a new `.trending-page` selector — additive, no shared
  `groups_chrome/css/stream.css` styles touched.
- **Cron marker-block convention**: reused the `#116`-established BEGIN/END
  marker pattern verbatim (same shape, distinct namespace
  `do_discovery cron` vs. `do_activity step_7xx`), in both `entrypoint.sh` and
  `test.yml`, at the exact brief-specified insertion points.

No new object was created anywhere in this story; every artifact is either a
config clone-with-deltas, a CSS mirror, a marker-block append, or an extension
of an existing PHP method.

## Architecture notes for A
- **Layers touched**: Views config (new), CSS (new), one PHP hook-class method
  (extended, not replaced), two shell-script/workflow-YAML append points. No
  new services, no new routes beyond the one the Views page-display plugin
  registers automatically (`view.trending.page_1`, already anticipated by
  `do_chrome`'s existing HelpText mapping — confirmed present, untouched).
- **New dependencies**: none beyond the view's own `dependencies.module` list
  (`comment`, `do_discovery`, `node`, `user` — all already-enabled modules per
  the survey).
- **Schema/contract changes**: none. `do_discovery_hot_score` table and its
  `views_data()` registration are pre-existing (verified by reading
  `DoDiscoveryHooks.php`); this story only consumes the already-registered
  `table: do_discovery_hot_score, field: score` sort handler, exactly as
  `hot_content.yml` already does.
- **Shared components changed**: `DoStreamsHooks::preprocessViewsView()` is
  shared with `/following`'s existing attach. The refactor (guard → map lookup)
  preserves `following_feed`'s resolved library and its early-return behavior
  on every other view id byte-for-byte in outcome; verified by re-reading the
  diff and confirming the map's `following_feed` entry is unchanged from the
  original literal string.
- **Local patterns followed**: the class's existing "guard on view/display id,
  return immediately otherwise" convention (documented in the class docblock,
  now updated to reflect two consuming views); the project's established
  BEGIN/END marker-block convention from #116 for append-only shell-script and
  workflow-YAML edits.

## Deviations from spec / wireframe
None. One clarification worth flagging (not a deviation): the brief's Step 1
prose literally says "exposed content-type filter," but the verified,
clone-source file (`following_feed.yml`) does not actually expose that filter
— see Design decision #2 above. I followed the brief's own instruction to
"verify" against `following_feed.yml` rather than the word "exposed" in the
prose, since the brief explicitly flagged this as something to check rather
than take as a hard requirement.

## Tier 1 self-check (incl. tests now GREEN)
No local PHP/DDEV toolchain is available in this worktree (no `vendor/`, no
`php` on PATH), and per the task's explicit instruction I did not spin up a new
DDEV instance (other DDEV projects are already running concurrently on this
host for sibling worktrees) — CI is the authoritative GREEN surface for this
story. Self-checks performed instead:

1. **`bash scripts/ci/assemble-config.sh`** — ran successfully through both the
   config-copy step (129 files copied, 7 env-specific excluded, matching the
   expected count) and the module-copy step (14 custom modules copied); only
   the final `core.extension.yml`-patching sub-step failed, and only because
   `vendor/autoload.php` doesn't exist in this worktree (a pre-existing
   environment gap unrelated to this diff — CI's own vendor is installed via
   composer before this script runs).
   ```
   ==> assemble-config: repo root = C:/Users/aange/Projects/_worktrees/groups-st4-trending-113
   ==> config: copied 129 file(s), excluded 7 env-specific file(s)
   ==> modules: copied 14 custom module(s) into web/modules/custom/
   ERROR: .../vendor/autoload.php missing — run 'composer install' before assemble-config
   ```
   Confirmed the two files this story creates/modifies landed correctly in the
   assembled build output and are byte-identical to their `docs/groups/` source
   (`diff docs/groups/... web/modules/custom/...` / `config/sync/...` →
   `IDENTICAL` both times).
2. **YAML validity** — `docs/groups/config/views.view.trending.yml` parses
   cleanly via `yaml.safe_load`; every brief gotcha was individually asserted
   against the parsed structure (uuid null, dependencies list, `access.type:
   none`, `css_class`, `path`, `use_ajax`/`distinct` true, row type/view_mode,
   `row_class`, exact empty-state HTML string, sort order
   score-then-created both DESC, filters present with `type` NOT exposed,
   pager full/h4/10-per-page) — all matched.
3. **`.github/workflows/test.yml`** still parses as valid YAML after the
   `sed`-based insertion (`yaml.safe_load` → `YAML OK`); `git diff` confirms
   the change is exactly the intended 7-line append with zero other lines
   touched.
4. **PHP structural check** — no local `php` binary available, so I confirmed
   brace/paren/bracket balance programmatically (35/35, 184/184, 92/92 — all
   balanced) on the full rewritten `DoStreamsHooks.php`, and confirmed the
   assembled copy in `web/modules/custom/` is byte-identical to the
   `docs/groups/` source. The edit was a full-file rewrite starting from the
   exact previously-Read file content with two scoped insertions (one constant
   block, one method + its docblock) — no partial/fuzzy edits.
5. **Regression guard** — `git diff docs/groups/config/views.view.hot_content.yml`
   and `git status --porcelain docs/groups/modules/do_chrome/` both produced
   empty output, confirming zero changes to either non-scope path.
6. **Spec-to-artifact trace** — walked all 6 tests in
   `tests/e2e/trending.spec.ts` against the shipped artifacts (title string,
   sort block, row_class, hot_content.yml diff, libraries.yml entries, Views'
   own page-title/pager mechanisms) — every assertion maps to something
   concretely shipped in this diff. Independently re-verified the seed data's
   "2-comment thread" claim by reading `step_700_demo_data.php` lines 141-143
   and 216-219: both "Venue Logistics Thread" and "Patch Review Process RFC"
   each receive exactly 2 comments, consistent with the 6.0 post-cron score
   the ordering test depends on.

**T's actual `npx playwright test` GREEN run is intentionally NOT executed by
F** per the task's instructions ("Do NOT run `npx playwright test` here to
declare GREEN — leave that to T(GREEN) phase") — this is T's Phase 6, not F's.

## Tests that look wrong (for T)
None. All 6 tests in `tests/e2e/trending.spec.ts` map cleanly to shipped
artifacts; no test-authorship issue was found during implementation.

## Known issues
None. All acceptance criteria in the brief appear satisfied by this diff;
final confirmation is CI's e2e job (T's Phase 6 GREEN run against the real
seeded, cron'd site).

## Files changed
- `docs/groups/config/views.view.trending.yml` (NEW)
- `docs/groups/modules/do_streams/css/trending.css` (NEW)
- `docs/groups/modules/do_streams/do_streams.libraries.yml` (EDITED — appended
  `trending:` block)
- `docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php` (EDITED —
  added `TRENDING_VIEW_ID` constant; refactored `preprocessViewsView()` to a
  view-id-to-library map covering both `following_feed` and `trending`;
  updated both docblocks)
- `deploy/entrypoint.sh` (EDITED — appended `# --- do_discovery cron
  BEGIN/END ---` marker block after `$DRUSH cr`, before the closing `fi`)
- `.github/workflows/test.yml` (EDITED — appended the same-named marker block
  after `# --- do_activity step_7xx END ---`, before `cache:rebuild -y`; no
  other line touched)
