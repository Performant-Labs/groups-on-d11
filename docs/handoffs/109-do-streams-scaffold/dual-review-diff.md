## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** DoStreamsHooks.php:preprocessDoStreamsShell()  
Description: The `scope_tabs` array elements include only `id`, `label`, and `active` but omit the required `url_or_param` property.  
Why it blocks: The brief’s shell contract [B-3] mandates each tab entry carry a `url_or_param` (either a URL or query-parameter mapping) so downstream stories can wire up navigation without editing Twig. Without it, the shell is not wireable and cannot satisfy the “no hardcoded routes” criterion.  
Remediation: In `preprocessDoStreamsShell()`, add a `url_or_param` (e.g. a path or query-string parameter) to each `scope_tabs` item, derived from its `id`.

**[B-2]** DoStreamsHooks.php:viewsQueryAlter()  
Description: The implementation assumes that `hook_views_query_alter()` is invoked *after* the view’s own sort definitions have been registered so that a call to `array_unshift($query->orderby, …)` will promote the plugin’s ORDER BY expression to the primary sort key.  
Why it blocks: That execution ordering is a runtime behavior hypothesis borrowed from the do_group_pin precedent but has not been verified against the Views core source. If the hook is invoked earlier or later, the pinned-first logic may not take effect or may break existing sorts.  
Remediation: Verify in Drupal’s Views core (`views_query` plugin and `views_query_alter` dispatch) that sorts are added before the `views_query_alter` hook runs; if not, switch to a hook (`views_pre_build` or similar) that guarantees the correct ordering.

### WARN findings

**[W-1]** DoStreamsHooks.php:queryViewsDoStreamsDemoAlter()  
Description: The list of join-side tables to aggregate (`do_streams_comment_stats`, `do_streams_hot_score`, `do_streams_pin_flagging`) is hardcoded.  
Recommendation: If additional joins are ever added (e.g. a future follow-scope join via LEFT JOIN), the aggregation logic will need to be updated. Consider discovering join tables dynamically from `$query->getTables()` rather than maintaining a static list.

**[W-2]** DoStreamsHooks.php:onFlaggingChange()  
Description: The cache-tag invalidation only clears the flagger’s own user-stream tag (`do_streams:user_stream:<uid>`).  
Recommendation: Confirm that this per-flagger invalidation scope matches the UX requirements. If the intent is to invalidate other viewers’ streams (e.g. all group members), the logic must be extended accordingly.

### NIT findings

**[NIT-1]** DoStreamsHooks.php:theme()  
Description: The hook returns only the new `do_streams_shell` definition and does not merge in the incoming `$existing` array.  
Suggestion: For clarity, `return $existing + [ 'do_streams_shell' => … ];` so that existing theme hook definitions passed in remain untouched.

**[NIT-2]** FollowingScope.php:query()  
Description: The raw SQL expressions use multiline string literals with inconsistent indentation.  
Suggestion: Align indentation and whitespace in the SQL snippets for readability and easier maintenance.

**[NIT-3]** README.md  
Description: The “Engine contract” section documents the parameters but does not show a sample mapping of `url_or_param`.  
Suggestion: Add a brief example of how `scope_tabs[n].url_or_param` should be structured (e.g. `'?scope=following'`) to guide downstream implementers.

**[NIT-4]** MembershipScope.php:init()  
Description: The docblock shows the reference SQL shape; the code correctly uses `storage->get('base_field')`.  
Suggestion: Note in comments that `storage->get('base_field')` is required because the field is synthetic, to aid future readers.

### Verdict

BLOCK — 2 blocking finding(s); must resolve before testing starts.
