# Decision Journal — #110 ST-1 My Feed

## O Phase 1 (survey + brief)
- **Decided:** Wrapping strategy = custom Controller (`MyFeedController`) that calls `views_embed_view('my_feed','default')` and returns a `#theme => do_streams_shell` render array with the embedded view as `#results`. Rejected: hook_views_pre_render swap (fragile) and page display + attachment (harder to layer the shell chrome deterministically).
- **Decided:** Nav link "My Feed" auth-visibility relies on Drupal's default menu-link-access filter, which hides links whose target route access is denied — route `_role: authenticated` yields correct hide-for-anon behavior with no extra code. If in practice this misses the auth check (some access managers evaluate lazily), fall back to explicit `hook_menu_local_tasks_alter` or block-level visibility.
- **Decided:** Shell theme hook extended with optional `empty_cta` render-array variable (default `[]`) — forward-compat for #111-#115 that all will render empty states with different CTAs.
- **Assumed:** `views_embed_view()` on a default display returns a render array whose access is handled by the view's own access plugin (`role: authenticated`) — combined with the route-level auth requirement, anonymous can never reach the view render path.
- **Assumed:** Seed's Elena membership set is stable (5 groups incl. DrupalCon Portland 2026 where "Sprint Planning: Portland 2026" lives and is pinned). Verified via grep of step_700.
- **Hedged:** If the view's `use_ajax: true` (inherited from activity_stream template) conflicts with the controller wrap, T/F should set it to `false` — the shell doesn't set up an AJAX target region.
- **Evidence:** Read entire `DoStreamsHooks.php`, shell twig, `MembershipScope.php`, `activity_stream.yml`, `step_780_nav_menu.php` tail, seed refs for Elena's groups and pinned content. Confirmed #109 shipped merged (main HEAD 49fe585).
