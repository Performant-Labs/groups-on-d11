# Survey — #253 preprocess_node N+1 tableExists()

## File under change
- `web/themes/custom/groups_chrome/groups_chrome.theme` (tracked directly, NOT assembled from `docs/groups/`)
- Function: `groups_chrome_preprocess_node()` lines 106–219
- Offending call: line 207 — `$database->schema()->tableExists('comment_entity_statistics')` inside per-node hook

## Context
- Runs once per node rendered in `stream_card` view mode
- Cross-ref PERF-4 audit: `docs/planning/perf/query-audit-2026-07-24.md` — measured 10 SELECTs on `information_schema.tables` on `/`, `/showcase`, `/stream` cold
- Table `comment_entity_statistics` existence cannot change mid-request under any real code path

## Reuse & Analogous-Feature map
- No existing static-cache helper in this theme. `drupal_static(__FUNCTION__ . '_has_table')` is the idiomatic per-request cache in preprocess hooks — extends the same `\Drupal::database()->schema()->tableExists()` call, just memoized.
- **Recommendation: extend in place.** Wrap the existing `if ($database->schema()->tableExists(...))` line with a `drupal_static` guard. No new function, no new service.

## Test location
- Themes cannot host Kernel tests; companion module `do_chrome` already hosts kernel tests at `docs/groups/modules/do_chrome/tests/src/Kernel/`.
- New test: `PreprocessNodeTableExistsCacheTest.php` there.
- Strategy: install `node` + `comment` + render 10 stream_card nodes; count `tableExists('comment_entity_statistics')` calls via a query-log listener OR by wrapping the schema. Simplest: use `Database::getLog()` filtered by pattern `information_schema.tables%comment_entity_statistics%`, or install a logging connection.

## Risk
- Very low. `static` in a preprocess hook is a well-worn Drupal idiom. Only behavioral change is per-request memoization.

## Downstream forward-compat
- N/A — no downstream stories consume this hook's shape.
