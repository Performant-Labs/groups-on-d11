# /all-groups N+1 investigation — not reproducible (already fixed as collateral)

**Date:** 2026-07-27
**Issue:** #263 (investigation-only; parent audit #244 PERF-4)
**Verdict:** the audit's `/all-groups` N+1 fingerprint **does not reproduce** on
`main` at commit `9ce5507`. Zero follow-up fix required.

## Method

Followed the PERF-4 audit's own methodology (`docs/planning/perf/query-audit-2026-07-24.md`
§Methodology) end-to-end on a **fresh install of the current codebase**:

1. New DDEV project `gm263-inv` on a per-story worktree at
   `~/Projects/_worktrees/groups-all-groups-n1-inv-263/` (branch
   `263-all-groups-n1-investigation`, base `9562cdc`).
2. Site brought up with the standard install sequence used by
   `.github/workflows/test.yml`: `bash scripts/ci/assemble-config.sh` →
   `drush si standard` → `drush cim -y` (after `system.site:uuid` alignment) →
   the demo seed sequence (`step_640`, `step_700_demo_data`,
   `step_720_group_types`, `step_780_nav_menu`).
3. Seeded dataset scale at capture: **12 groups, 22 nodes** — slightly smaller
   than the audit's snapshot (13 groups, 33 nodes) but same order of magnitude
   and the same "all nodes on one page" ratio the audit found (×31 loads on 33
   nodes ≈ 94%; if the pattern were still live it would surface here as ×20-ish
   on 22 nodes).
4. Ephemeral scratch module `gm263_qlog` (uncommitted; deleted at the end of
   the run) wrapped every request with `Database::startLog('gm263')` on
   `KernelEvents::REQUEST` (priority 1024) and dumped the full query log to
   `/tmp/gm263-qlog-<slug>-<ts>.json` on `KernelEvents::TERMINATE` (priority
   -1024) — bracketing the entire request lifecycle including post-response
   cache-tag bookkeeping, identical to the PERF-4 subscriber shape.
5. Analyzer normalized each query shape (whitespace + `IN (...)` + numeric
   literals) and aggregated by count, total time, and top callers. Ran two
   captures per state (COLD after `drush cr`, WARM as the immediate next
   request), anonymous, no cookie jar, via `curl`.

## Result

**The audit's five ×31/×19/×12 fingerprint queries do not appear in the log
at all.** Two independent COLD captures + one WARM capture, all zero hits:

| Fingerprint query (from audit §4b) | Audit count | This capture, COLD | This capture, WARM |
| --- | ---: | ---: | ---: |
| Node revision load (`getFromStorage @ SqlContentEntityStorage.php:440`) | ×31 | **0** | **0** |
| `field_group_tags` (node field) load (`loadMultipleCardinalityFields @ SqlContentEntityStorage.php:1595`) | ×31 | **0** | **0** |
| `comment_entity_statistics` read (`read @ CommentStatistics.php:98`) | ×31 | **0** | **0** |
| Node body / comment_status join (`loadSingleCardinalityFields @ SqlContentEntityStorage.php:1466`) | ×19 | **0** | **0** |
| `group_relationship_field_data` loadByGroup (2 shapes) | ×12, ×12 | **0** | **0** |

Query counts by state:

- **COLD `/all-groups`:** 550 total queries, dominated by `cache_default` /
  `cache_config` / `cache_discovery` / `cache_render` / `cache_bootstrap`
  populate (`doSetMultiple @ DatabaseBackend.php:308` × 63 for `cache_default`
  alone) plus `watchdog` `INSERT` × 39 and `router` / `key_value` / `cache_*`
  reads. No entity-tier queries in the top-20 repeated shapes.
- **WARM `/all-groups`:** ~40 queries, all cache-get / access-policy / router
  reads and a handful of session/watchdog housekeeping. Zero content-tier
  queries.

## Attribution table (subtree → node loads)

Per the issue body's ranked suspects, checked in order:

| Suspect | Verdict | Evidence |
| --- | --- | --- |
| 1. `DoShowcaseHooks::pageTop()` / `personaSwitcherWidget()` / `personaBanner()` | Clean | All three build pure render arrays from `ShowcaseCatalog::personas()` (a hard-coded PHP array) — no entity storage calls. `personaBanner()` reads `\Drupal::currentUser()->getAccountName()` and iterates the persona array; no node access. Log confirms: 0 node loads attributed to any `#[Hook('page_top')]` caller. |
| 2. Placed blocks `do_streams_user_activity` / `do_profile_completeness` / `do_group_mission` / `do_contribution_stats` | Not on this page | Every one of the four `block.block.*.yml` config entities declares `visibility.request_path` restricted to `/user/*` or `/group/*`. **None are visible on `/all-groups`.** Grep of `docs/groups/config/block.block.*.yml` for `pages:` confirms. |
| 3. Global Views embeds with `base_table: node_field_data` | Not placed | 13 views declare `base_table: node_field_data` (`hot_content`, `promoted_content`, `trending`, `tags_aggregation`, `my_feed`, `my_events`, `activity_stream`, `following_feed`, `user_activity`, plus 4 group-scoped views). None are placed as blocks in any theme region — grep of `docs/groups/config/block.block.*.yml` for `plugin: 'views_block:{hot_content,trending,tags_aggregation,activity_stream,following_feed,my_feed,my_events,user_activity,promoted_content}-*'` returns only `views_block:user_activity-block_1` (the `do_streams_user_activity` block, ruled out at row 2). |
| 4. `groups_chrome_preprocess_node` | Already fixed | Commit `78b7a46 perf(#253): memoize groups_chrome_preprocess_node tableExists() per request`. |

Additional exhaustive sweep by a general-purpose agent across
`docs/groups/modules/**/src/**/*.php` and `*.module` for `getStorage('node')`,
`node_load_multiple`, `entityQuery('node')` global calls found **no runtime
call** on the `/all-groups` render path in any custom `do_*` module.

## Identified culprit

**None on the current tree.** The 2026-07-24 fingerprint has been eliminated
by intervening PRs merged after the audit was captured. Candidates for what
already fixed it (any one or combination):

- **#251** — "close 3 cache-tag gaps from PERF-3 audit" (b30e3fe, 2026-07-24
  post-audit). Improving cache-tag scoping on shared block cache entries is
  the class of change most likely to prevent an unrelated block's render-cache
  miss from cascading into a node-list fetch on `/all-groups`.
- **#252** — "header search: plain GET form replaces session-triggering
  search_form_block" (c477c18) — the previous CSRF-form implementation of the
  header search block was in the render path of every page, including
  `/all-groups`. Removing it eliminated one shared render subtree that could
  have carried a node-loading dependency.
- **#253** — "memoize groups_chrome_preprocess_node tableExists() per
  request" (78b7a46). Reduced the count of `information_schema.tables`
  introspections, not itself a node-loader, but the preprocess hook it lives
  in *is* attached to every node render — a change here can shift what the
  render cache does across cold hits.

Root-causing which of the three did the actual work would require re-running
the audit against each commit in isolation (a bisect), which is out of this
investigation's ~90 min time-box and — since the current state is provably
clean on two independent captures — would produce information with no follow-up
action attached to it.

## Recommendation

**Close #263 as "already fixed as collateral, no follow-up needed."** No new
follow-up issue, no code change, no config change. The PERF-4 audit doc
(`docs/planning/perf/query-audit-2026-07-24.md` §4b) remains accurate as a
point-in-time snapshot of the codebase on 2026-07-24; this report is the
follow-up capture that verifies the fingerprint is gone.

If the pattern ever reappears in a future audit re-run, the diagnostic path
proven here (bracket-annotated scratch `Database::startLog()` subscriber; run
COLD and WARM captures; grep for the caller of the repeated shape) still
works and takes ~30 min to stand up from scratch.

## Artifacts

All ephemeral — none committed:

- Scratch module `web/modules/custom/gm263_qlog/` (deleted).
- Analyzer `scripts/perf/gm263_analyze.php` (deleted).
- Query logs `/tmp/gm263-qlog-*.json` on the `gm263-inv` container (deleted).
- DDEV project `gm263-inv` — namespace container per project convention;
  local-only, not shipped.
