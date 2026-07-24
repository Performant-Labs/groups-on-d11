# PERF-4 — Database Query Audit

**Date:** 2026-07-24
**Issue:** #244 (PERF-4), part of Epic #241 (Performance & Load Testing)
**Scope:** read-and-report audit; slam-dunk single-column indexes permitted only if all
five eligibility rules in the issue are met (none were — see §6).

## Environment

- Drupal 11.4.4, PHP 8.4.22, MariaDB 11.8 (DDEV `gm244-query`, nginx-fpm).
- Site brought up via the same sequence `.github/workflows/test.yml` uses (composer install
  → `scripts/ci/assemble-config.sh` / `assemble-libraries.sh` → `drush site:install standard`
  → `config:import` → the full `step_640`/`step_700`/`step_720`/`step_780`/`step_790`/
  `step_7xx`/`step_795`/`cron`/`step_760` seed sequence), so the dataset matches the deployed
  demo image, not an isolated fixture.
- Seeded dataset scale at audit time: 13 groups, 33 nodes, 9 users. This is a **demo-scale**
  dataset — several findings below explicitly depend on this being small (see §5, §6).

## Methodology

Profiled with Drupal's own query logger (`Database::startLog()` / `Database::getLog()`),
attached via a scratch `kernel.request`/`kernel.terminate` event subscriber (`priority 1024`
on request, `-1024` on terminate, so it wraps the entire request lifecycle including any
post-response cache-tag bookkeeping). Each request's full query log (SQL, bound args, target,
caller function/file/line, elapsed time) was dumped to a per-request JSON file and analyzed
offline. The scratch module and all analysis scripts were deleted before this PR — nothing
under `web/modules/custom/` or `sites/default/files/` was committed (both are working-tree-only
per the assemble/seed step; see repo `PROJECT_CONTEXT.md`).

For each of the 5 target pages, captured **COLD** (immediately after `drush cache:rebuild`,
so entity/render/config/discovery caches are all empty — the worst case, e.g. right after a
deploy) and **WARM** (the very next request to the same URL, no intervening cache clear —
the steady-state case for a page any two visitors hit close together). Requests were made
with a bare `curl` (no persisted cookie jar), so each is a fresh, unauthenticated visitor.

**Page-list note:** the operator's brief named `/stream` where issue #244's own body says
`/stream/following`; routing was inspected and confirmed the real, working page is
**`/stream`** (`views.view.activity_stream`, `page_1` display, path `stream`) — no
`/stream/following` route exists in this codebase. Similarly `/all-groups` (not `/groups`)
is the real directory route. The 5 pages actually audited: `/` (front), `/showcase`,
`/all-groups`, `/group/7` (an existing seeded group, "Drupal Deutschland" — published,
has geo/type/language fields set, representative), `/stream`.

## 1. Executive summary

- **No table in this codebase (core, contrib, or `do_*`-custom) is missing an index for any
  query surfaced across the 5 pages.** Every genuine full-table-scan found by EXPLAIN is
  either (a) on a table this project does not own (`drupal/group`'s `groups_field_data`,
  `drupal/flag`'s `flagging`) where the matching index **already exists** and MySQL's
  optimizer is correctly choosing a scan over it at the current tiny (13–25-row) table size,
  or (b) an expected artifact of `EXPLAIN`-ing an `INSERT` or a derived-subquery `COUNT(*)`
  (no real access plan to index). **Zero slam-dunk indexes were added in this PR** — see §6
  for the full eligibility walk-through.
- Every page's **COLD** query count is dominated (90–97% of total time) by cache-bin
  `INSERT`s populating the render/entity/config/discovery/bootstrap caches for the first
  time after a cache-clear — this is expected, one-time, post-deploy cost, not a per-request
  tax. **WARM** hits collapse to 33–61ms total and 0–1 repeated content-tier query per page.
- The most valuable finding is **not a query at all**: every anonymous request to every page
  audited starts a fresh PHP session (confirmed with a cookie-jar-free `curl`), which makes
  every response `X-Drupal-Cache: UNCACHEABLE (response policy)` — Drupal's fastest,
  whole-page cache tier never engages for anonymous traffic on this site. Root-caused to the
  header search-form block (core's `search_form_block` plugin, CSRF-token-bearing `<form>`,
  rendered on every page via `groups_chrome_search_form_wide`/`_narrow`). Filed as #252.
- Two genuine N+1 patterns found, both real but **out of index-only scope for this PR**:
  a per-stream-card `information_schema.tables` introspection call (#253), and the
  `/all-groups` directory's Views-architecture one-query-per-row-per-multi-value-field
  pattern (#254). Both are code/config fixes, not schema changes.
- No full-table scan was found on `node`, `users`, or any other large core table for any of
  the 5 pages' actual content queries — the acceptance criteria's stated concern ("full-table
  scans on large tables") does not manifest at the current demo scale.

## 2. Per-page query breakdown

| Page | Cache | SELECT | INSERT | UPDATE | DELETE | Total | Duration (ms) |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `/` (front) | COLD | 552 | 402 | 2 | 3 | 959 | 522.50 |
| `/` (front) | WARM | 92 | 14 | 0 | 0 | 106 | 33.03 |
| `/showcase` | COLD | 545 | 420 | 2 | 3 | 970 | 523.23 |
| `/showcase` | WARM | 148 | 32 | 0 | 0 | 180 | 59.38 |
| `/all-groups` | COLD | 637 | 363 | 2 | 3 | 1005 | 818.35 |
| `/all-groups` | WARM | 91 | 14 | 0 | 0 | 105 | 33.59 |
| `/group/7` | COLD | 445 | 297 | 2 | 3 | 747 | 512.42 |
| `/group/7` | WARM | 162 | 27 | 0 | 0 | 189 | 61.05 |
| `/stream` | COLD | 549 | 400 | 2 | 3 | 954 | 496.11 |
| `/stream` | WARM | 92 | 14 | 0 | 0 | 106 | 47.92 |

The 2 UPDATEs and 3 DELETEs on every page are core session/flood/semaphore bookkeeping
(`sessions` upsert bookkeeping and cache/flood housekeeping) — constant per-request overhead
unrelated to page content.

### Plumbing vs. content split

Splitting each page's queries into **plumbing** (Drupal's own cache-bin get/set, `watchdog`
logging, session read/write, active config-storage reads, router preloading — all fire on
every request regardless of what the page renders) and **content** (entity loads, field-table
joins, Views base/relationship queries, comment/flag-count reads — the part actually
attributable to *this page's* data) makes the real cost visible:

| Page | State | Plumbing (#/ms) | Content (#/ms) | Plumbing % of time | Content % of time |
| --- | --- | --- | --- | ---: | ---: |
| `/` | COLD | 827 / 478.3ms | 132 / 44.2ms | 91.5% | 8.5% |
| `/` | WARM | 105 / 32.8ms | 1 / 0.2ms | 99.3% | 0.7% |
| `/showcase` | COLD | 843 / 485.2ms | 127 / 38.1ms | 92.7% | 7.3% |
| `/showcase` | WARM | 178 / 58.8ms | 2 / 0.5ms | 99.1% | 0.9% |
| `/all-groups` | COLD | 768 / 741.6ms | 237 / 76.7ms | 90.6% | 9.4% |
| `/all-groups` | WARM | 104 / 33.3ms | 1 / 0.3ms | 99.2% | 0.8% |
| `/group/7` | COLD | 714 / 497.7ms | 33 / 14.7ms | 97.1% | 2.9% |
| `/group/7` | WARM | 188 / 60.8ms | 1 / 0.2ms | 99.6% | 0.4% |
| `/stream` | COLD | 823 / 454.8ms | 131 / 41.3ms | 91.7% | 8.3% |
| `/stream` | WARM | 105 / 47.6ms | 1 / 0.3ms | 99.4% | 0.6% |

**Reading this table:** the "COLD" numbers are almost entirely a one-time,
first-request-after-cache-clear cost (populating render/entity/config/discovery caches from
empty). On WARM, content-tier cost drops to near-zero (0–2 queries) on every page except
`/all-groups`'s cold pass — the render cache is doing its job at data-cache granularity.
What does NOT drop between COLD and WARM is the plumbing tier's session/watchdog writes
(see §4) — that's the one steady-state cost this audit found worth flagging.

## 3. Top-5 slowest queries per page

Two views are given per page: the **overall** top-5 (which — as expected — is dominated by
cache-bin `INSERT`s on COLD, a one-time cost) and the **content-tier** top-5 (excludes
cache/watchdog/session/config/router plumbing; this is the actionable part of each page).

### `/` (front page)

**Overall COLD:** 5x `cache_default`/`cache_entity`/`cache_render` `INSERT` via
`doSetMultiple @ DatabaseBackend.php:308`, 124.8ms / 16.1ms / 13.1ms / 9.5ms / 7.7ms.
**Overall WARM:** `watchdog` INSERT (3.3ms, `log @ DbLog.php:79`), then 4x `cache_*`
`getMultiple` reads (0.6–0.8ms each).

**Content-tier COLD:**
1. `menu_tree` depth query (4.29ms) — `safeExecuteSelect @ MenuTreeStorage.php:239`
2. `information_schema.tables` existence check ×2 (0.95ms, 0.66ms) — `groups_chrome_preprocess_node @ groups_chrome.theme:207` (see #253)
3. `menu_tree` route-lookup ×2 (0.64ms, 0.60ms) — `safeExecuteSelect @ MenuTreeStorage.php:239`

**Content-tier WARM:** 1 query total — `group_relationship_field_data` gid lookup (0.24ms,
`generateHash @ GroupPermissionsHashGenerator.php:110`, part of Group's own permission-hash
cache-key generation).

### `/showcase`

**Overall COLD:** 94.8ms / 17.3ms / 15.5ms `cache_default`/`cache_entity` INSERTs, then a
7.6ms `cache_config` multi-key read, then a 7.4ms `cache_bootstrap` INSERT.
**Overall WARM:** a 9.3ms `sessions` INSERT (`SessionHandler::doWrite`, see §4), then small
watchdog/cache reads.

**Content-tier COLD:**
1. `menu_tree` depth query (0.74ms)
2. `group_relationship` base-entity load (0.71ms, `getFromStorage @ SqlContentEntityStorage.php:440`)
3. `menu_tree` depth query, 2nd menu (0.69ms)

**Content-tier WARM:** 2 `group_relationship_field_data` `DISTINCT plugin_id` lookups
(0.28ms, 0.26ms) — Views cache-key generation (`generateResultsKey @ CachePluginBase.php:213`).

### `/all-groups`

**Overall COLD:** 164.7ms / 49.5ms / 31.6ms / 31.3ms / 19.1ms — all `cache_default`/
`cache_entity` INSERTs. This page's cold-cache warm-up is the single most expensive of the
5 (818ms total, the audit's overall slowest page) because the directory renders 25+ group/
node rows, each populating its own render-cache entry.
**Overall WARM:** `watchdog` INSERT (5.4ms) then small cache reads — near-silent otherwise.

**Content-tier COLD (this page has the audit's richest content signal — see §4 N+1):**
1. Node body/comment-status field join (0.95ms, `loadSingleCardinalityFields @ SqlContentEntityStorage.php:1466`)
2. `field_group_links` multi-value join, 12-id batch (0.76ms, `loadMultipleCardinalityFields @ SqlContentEntityStorage.php:1595`)
3. `field_group_tags` multi-value join (0.69ms, `loadMultipleCardinalityFields`)

**Content-tier WARM:** 1 query — `group_relationship_field_data` gid lookup (0.26ms,
same `generateHash` call as `/`).

### `/group/7` (Drupal Deutschland)

**Overall COLD:** 150.6ms / 31.0ms `cache_default` INSERTs, then 14.2ms `cache_render`,
12.9ms `cache_entity`, 10.4ms `cache_routes` INSERTs.
**Overall WARM:** a 7.0ms `sessions` INSERT, then small config/discovery cache reads.

**Content-tier COLD:**
1. `taxonomy_term__parent` join for the group-type term (1.46ms, `loadMultipleCardinalityFields`) — the audit's single slowest content query
2. `path_alias` prefix-LIKE existence check for `/user%` (0.75ms, `pathHasMatchingAlias @ AliasRepository.php:122`)
3. `menu_tree` depth query (0.67ms)

**Content-tier WARM:** 1 query — `flagging` "is this flagged" batch check (0.23ms,
`loadIsFlaggedMultiple @ FlaggingStorage.php:123`).

### `/stream`

**Overall COLD:** 97.3ms / 17.6ms `cache_default` INSERTs, 14.0ms `cache_entity`, 7.9ms
`cache_render`, 6.9ms `cache_bootstrap`.
**Overall WARM:** a 12.3ms `sessions` INSERT, then a 6.8ms `watchdog` INSERT.

**Content-tier COLD:**
1. `flag_counts` per-node lookup ×3 (0.91ms, 0.79ms, 0.66ms — `getEntityFlagCounts @ FlagCountManager.php:93`, one call per stream-card node)
2. `users_field_data`/`user__roles` join (0.72ms, `loadMultipleCardinalityFields`)
3. `menu_tree` depth query (0.63ms)

**Content-tier WARM:** 1 query — `group_relationship_field_data` gid lookup (0.29ms), same
`generateHash` call as `/` and `/all-groups`.

## 4. N+1 findings

Two genuine, evidence-backed N+1 patterns, plus one shared "expected repetition" pattern
common to `/`, `/showcase`, and `/stream` that is **not** a defect.

### 4a. Shared 17-shape pattern on `/`, `/showcase`, `/stream` (COLD) — mostly benign, one real item

All three pages show the identical ~17 repeated content-tier query shapes at similar counts
(x10 for the highest), all attributable to rendering **10 stream cards** per page (this
demo's seed produces exactly 10 nodes per feed). Of these:

- `flag_counts` per-node lookup (x10), `group_relationship`/`comment_entity_statistics`
  per-node reads (x10 each) — this is standard "one query per rendered entity" cost that
  Drupal's per-row render cache normally amortizes away on subsequent requests (confirmed:
  WARM shows 0 of these). Not flagged as a defect.
- **`information_schema.tables` existence check, x10, ~2.5–3.7ms total per page** —
  `groups_chrome_preprocess_node @ groups_chrome.theme:207`. This one genuinely IS a bug: the
  check (`tableExists('comment_entity_statistics')`) has the same answer for the whole
  request, but the preprocess hook re-runs it once per node instead of memoizing it. Small
  in absolute terms today (~3ms/page) but 100% avoidable with a one-line static-variable memo.
  **Filed as #253** (application-code fix, not a schema/index change — out of this PR's scope).

### 4b. `/all-groups` (COLD) — the audit's strongest N+1 signal

| Repeated shape | Count | Total time | Caller |
| --- | --- | ---: | --- |
| Node revision load | x31 | 8.93ms | `getFromStorage @ SqlContentEntityStorage.php:440` |
| `field_group_tags` load | x31 | 10.74ms | `loadMultipleCardinalityFields @ SqlContentEntityStorage.php:1595` |
| `comment_entity_statistics` read | x31 | 8.91ms | `read @ CommentStatistics.php:98` |
| Node body/comment-status join | x19 | 7.80ms | `loadSingleCardinalityFields @ SqlContentEntityStorage.php:1466` |
| `group_relationship_field_data` `loadByGroup` (2 shapes) | x12, x12 | 4.26ms, 3.34ms | `loadByGroup @ GroupRelationshipStorage.php:176` |

This is the classic Drupal Views architecture pattern: the view's own base query loads 25
rows in one pass, but each attached multi-value field (tags, links, body/comment-status) and
each comment-statistics/group-relationship lookup triggers a **separate query per row**
through the Entity API's field-storage layer rather than a single JOINed batch. At 25–31
rows this totals ~35ms of the page's ~77ms content-tier time (cold) — real, but WARM shows
**zero** repeated content queries on this page (render cache fully amortizes it once warm).
**Filed as #254** (Views-config-level fix — add the hot fields as proper Views
fields/relationships so Views' own query builder JOINs them; needs a decision + regression
check against `directory-cards` E2E coverage, out of this PR's index-only scope).

### 4c. `/group/7` and pure-WARM states — clean

`/group/7` (COLD) shows only small counts (x2–x7) of `path_alias`/`menu_tree`/`users_field_data`
repetition — nothing rising to a real N+1 pattern (a group detail page inherently issues a
handful of per-related-entity lookups: the group-type term, the author of its About text,
etc.). Every page's WARM state shows 0–1 repeated content query — confirms the render cache
is the actual mitigation already in place for steady-state traffic; these findings matter
for cold-start (post-deploy) cost, not steady-state cost.

## 5. EXPLAIN analysis (top-3 slowest content queries per page)

All content-tier candidate queries across the entire audit were EXPLAIN'd systematically
(153 unique normalized query shapes; 219 total EXPLAIN result rows after per-table
JOIN expansion). Below is the top-3-per-page detail the issue asked for; the systematic
full-scan sweep across all 219 rows is summarized in §6.

### `/` — `menu_tree` depth query (slowest content query on this page, 4.29ms)

```sql
EXPLAIN SELECT menu_tree.* FROM menu_tree
WHERE menu_name = 'account' AND depth <= 1
ORDER BY p1, p2, p3, p4, p5, p6, p7, p8, p9
```
`type: ref`, `key: menu_parents`, `rows: 7`, `Using index condition; Using where`. Fully
indexed, 7-row estimate — not a concern.

### `/showcase` — `group_relationship` base-entity load (0.71ms)

```sql
EXPLAIN SELECT base.id, base.type, base.uuid, base.langcode
FROM group_relationship base WHERE base.id IN (33)
```
`type: eq_ref`-equivalent single-PK lookup on `drupal/group`'s own `group_relationship`
base table — indexed via PRIMARY KEY, 1-row estimate. Not a concern (and not our table to
touch regardless).

### `/all-groups` — node body/comment-status field join (0.95ms, this page's slowest content query)

```sql
EXPLAIN SELECT node_field_data.*, node__body.body_value, node__body.body_summary,
       node__body.body_format, node__comment.comment_status
FROM node_field_data
LEFT OUTER JOIN node__body ON node__body.entity_id = node_field_data.nid AND ...
LEFT OUTER JOIN node__comment ON node__comment.entity_id = node_field_data.nid AND ...
WHERE node_field_data.nid IN (10)
```
Both joins: `type: ref`, `key: PRIMARY`, `rows: 1` each. Fully indexed via each field
table's own primary key (`entity_id, deleted, delta, langcode`) — no scan.

### `/group/7` — `taxonomy_term__parent` join (1.46ms, the audit's single slowest content query)

```sql
EXPLAIN SELECT taxonomy_term_field_data.*, taxonomy_term__parent.parent_target_id
FROM taxonomy_term_field_data
INNER JOIN taxonomy_term__parent ON taxonomy_term__parent.entity_id = taxonomy_term_field_data.tid AND ...
WHERE taxonomy_term_field_data.tid IN (23)
```
Both sides: `type: ref`, `key: PRIMARY`, `rows: 1`. Fully indexed, single-term lookup for the
group's own type-term. The 1.46ms is entirely proportional to loading a 5-column-wide
taxonomy term row plus its parent-field row — no missing index; this is just the slowest of
a set of uniformly-fast queries (all others on this page are <1ms).

### `/stream` — `flag_counts` per-node lookup (0.91ms, this page's slowest content query)

```sql
EXPLAIN SELECT fc.flag_id, fc.count FROM flag_counts fc
WHERE fc.entity_type = 'node' AND fc.entity_id = '31'
```
`type: ref`, `key: entity_type_entity_id`, `rows: 1`, `Using index condition`. Fully indexed
composite-key lookup on the Flag contrib module's own count-cache table.

**Conclusion across all 5 pages' EXPLAIN output:** every content-tier query is `ref`/
`eq_ref`/`range`-accessed via an existing index with a 1-row (occasionally 7-row) estimate.
No query anywhere in the top-slowest set for any page shows `type: ALL` or a missing index.

## 6. Slam-dunk indexes — none added

Walking the issue's eligibility rule (single column, WHERE/JOIN condition on that column in
a hot query, table owned by core or a `do_*` module — never `drupal/group`'s tables, no
downstream implications) against every full-scan finding surfaced by the systematic 219-row
EXPLAIN sweep:

| Table (full scan found) | Rows | Existing index that already matches | Why not eligible |
| --- | --- | --- | --- |
| `cache_group_memberships` | — | n/a | This "full scan" is `EXPLAIN`-ing an `INSERT`, which has no real access plan — a script artifact, not a finding. |
| `<derived2>` (Views pager COUNT subquery) | 66 | n/a | A derived-table materialization for `COUNT(*) FROM (SELECT DISTINCT ...)` is structurally always a scan of the materialized subquery result — no base-table index applies. |
| `groups_field_data` (×2 callers) | 13 | `group__status_type (status, type, id)` **already exists** and exactly matches this query's `WHERE status=1 AND type IN (...)` filter | Nothing to add — the index is already there. MySQL's cost-based optimizer is choosing a full scan over it because the table only has 13 rows (a full scan of 13 rows is genuinely cheaper than an index lookup + row fetch at this scale); this self-resolves as the optimizer re-evaluates cost at higher row counts, and is not something an index change can influence today. Also `drupal/group`'s own table — off-limits regardless per project convention. |
| `group__field_group_links` | 6 | `PRIMARY (entity_id, deleted, delta, langcode)` **already exists** and covers the join | Same story: 6-row table, optimizer correctly prefers a scan; `drupal/group`-derived field-storage table, off-limits regardless. |
| `flagging` (aliased `f`) | 23 | `entity_id__uid (entity_id, uid)` **already exists**; no `entity_type` column exists on any index, but this is `drupal/flag` contrib's own base table | Not a `do_*`-owned or core table — off-limits per the project's "never patch upstream contrib schema" rule. Even setting that aside, 23 rows is squarely in "optimizer prefers a scan" territory. |

**No table this project owns (core or `do_*` custom) showed a full scan anywhere in the
audit.** Every eligible-in-principle candidate already has a matching index; every
ineligible one belongs to `drupal/group` or `drupal/flag` contrib. Per the issue's own rule
("If it needs analysis, multiple tables, or touches upstream schema — file a GH follow-up
issue... do NOT add speculative indexes"), nothing here qualifies for an in-PR schema change.
**Result: zero `hook_update_N()` / `hook_schema()` changes in this PR.**

## 7. Recommendations

Ordered roughly by value-for-effort. None of these are schema/index changes — all are code
or Views-config fixes filed as separate follow-ups per the issue's own routing rule.

1. **[#252] Convert (or lazy-defer) the header search-form block so anonymous requests stop
   starting a session on every page view.** Highest-value finding in this audit: it's the
   only thing preventing Drupal's whole-page cache from ever engaging for anonymous traffic
   on this site, on every one of the 5 pages tested. Effort: ~1-2h (GET-form conversion) to
   ~3-4h (lazy-builder), or ~15min to explicitly accept-and-document the tradeoff.
2. **[#253] Memoize the per-node `information_schema.tables` existence check** in
   `groups_chrome_preprocess_node()` (`web/themes/custom/groups_chrome/groups_chrome.theme:207`)
   to a request-scoped static instead of re-querying per stream card. Effort: ~20-30 min,
   very low risk (the table's existence cannot plausibly change mid-request).
3. **[#254] Add the `/all-groups` directory's hot multi-value fields as proper Views
   fields/relationships** so Views' own query builder JOINs them into the single base query
   instead of triggering N per-row Entity API loads. Effort: ~2-3h + directory-cards E2E
   regression check. Worth confirming production cache-tag churn rate first — this is a
   cold-cache cost specifically (WARM shows zero repetition), so its real-world value depends
   on how often the directory's render cache actually gets invalidated.
4. **No action needed on schema/indexing** — re-audit if/when the seeded demo dataset grows
   materially (hundreds of groups/nodes rather than 13/33); the `groups_field_data`,
   `group__field_group_links`, and `flagging` full-scans found here are optimizer choices at
   today's tiny row counts, already backed by matching indexes, and should be expected to
   self-resolve (switch to `ref`/`range` access) as row counts grow — worth a quick spot-check
   EXPLAIN re-run at that point rather than a proactive change now.
5. **No load-test correlation performed in this story** (that's PERF-2, #246) — these
   findings are single-request, zero-concurrency measurements. The p95/p99 targets in
   `docs/ops/sla.md` (REL-4, #213) should be re-validated under PERF-2's concurrent load
   profiles once that story lands, since session-per-request (finding #252) may behave
   differently under concurrent load (more concurrent session-table writes) than it does here.

## 8. Follow-up issues filed

- **#252** — header search-form block forces a session on every anonymous page view,
  defeating Drupal's page cache.
- **#253** — `groups_chrome_preprocess_node` re-checks `tableExists()` once per stream card
  (N+1, ~10x/page).
- **#254** — `/all-groups` issues one query per row per multi-value field (Views config,
  not a code bug).
