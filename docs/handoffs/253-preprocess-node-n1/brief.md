# Brief — #253

## Objective
Fix N+1: `groups_chrome_preprocess_node` calls `$database->schema()->tableExists('comment_entity_statistics')` on every node render (~10×/page for stream). Memoize per request.

## Change (F)
In `web/themes/custom/groups_chrome/groups_chrome.theme` around line 207, wrap the existing `tableExists` check with `drupal_static(__FUNCTION__ . '_has_comment_stats')`. No behavioral change beyond memoization. Preserve the surrounding try/catch and the fetchField path.

## Acceptance criteria
- [ ] `tableExists('comment_entity_statistics')` is called at most once per request regardless of how many stream_card nodes render.
- [ ] Comment count still populates correctly for nodes with comments (existing behavior preserved).
- [ ] Kernel regression test lives at `docs/groups/modules/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php`, asserts count of `information_schema.tables ... comment_entity_statistics` queries == 1 after 10 renders.

## Review rigor
`none` (mechanical memoization, ~20-line diff, high test coverage from the query-count assertion itself).

## Pipeline
POC lean: O → A → T(RED) → F → T(GREEN) → diff-gate → S → PR → CI-green → self-merge. No D, no U (no UI surface change).

## Files touched
- `web/themes/custom/groups_chrome/groups_chrome.theme` (F)
- `docs/groups/modules/do_chrome/tests/src/Kernel/PreprocessNodeTableExistsCacheTest.php` (T, new)
