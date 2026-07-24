## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_streams/do_streams.routing.yml — use of `_user_is_logged_in: 'TRUE'`  
Description: The routing file claims that `_user_is_logged_in: 'TRUE'` is a valid Drupal-11 route requirement.  
Why it blocks: If Drupal core does not recognize that requirement key/value pair, anonymous requests will not be prevented and AC-1 (403 or login redirect) will fail silently.  
Remediation: Verify in core source (e.g. `\Drupal\user\Access\LoginStatusCheck`) that `_user_is_logged_in` is a supported requirement key with a string-boolean value. If it is not, switch to the correct requirement (for example `_access: 'TRUE'` with a custom access check, or a proper service-based access requirement) so that anonymous users are denied.

**[B-2]** docs/groups/modules/do_streams/src/Controller/MyFeedController.php:build — silent fallback on missing view  
Description: When `Views::getView('my_feed')` returns `NULL`, the controller quietly renders the empty state.  
Why it blocks: A mis-named or missing view configuration will be hidden as a “no content” page rather than surface a deployment error, making it impossible to detect a failed config import in production.  
Remediation: Change the `if ($view === NULL)` branch to throw an exception (e.g. `throw new \RuntimeException("The my_feed view is not installed.")`) or log a high-severity error so that missing view config is caught at deploy time.

**[B-3]** scripts/ci/assemble-config.sh (not shown) — unverified inclusion of `docs/groups/config`  
Description: The new view definition lives under `docs/groups/config/views.view.my_feed.yml`, but it must be copied into Drupal’s `config/sync` directory by your assemble script.  
Why it blocks: If `assemble-config.sh` does not include `docs/groups/config` in its copy list, the view will never be imported and the feed will always render empty in CI and production.  
Remediation: Confirm and, if necessary, update `scripts/ci/assemble-config.sh` to include the entire `docs/groups/config` directory so that `views.view.my_feed.yml` ends up in the active config sync before import.

### WARN findings

**[W-1]** docs/groups/modules/do_streams/src/Controller/MyFeedController.php:build — missing view-access check  
Recommendation: Before calling `$view->execute()`, invoke `$view->access('default')` (and handle an access denial) to respect the view’s own access plugin as a defense-in-depth measure, even though the route gate already restricts access to authenticated users.

**[W-2]** docs/groups/modules/do_streams/src/Controller/MyFeedController.php:buildShell — cache metadata layering  
Recommendation: Verify that the nested `#type => 'view'` element’s cache contexts and tags are preserved when the outer `#cache` is applied. If not, explicitly merge the nested view’s cache metadata into the shell render array so that node-level cache tags still invalidate the page when content changes.

**[W-3]** docs/groups/modules/do_streams/css/my-feed.css — missing shell chrome styling  
Recommendation: The shell-chrome classes (`.shell`, `.shell-tabs`, `.gc-empty` etc.) are still unstyled in production. Confirm that a follow-on story or existing theme will provide those styles; otherwise, document in the UI-walkthrough that `/my-feed` will render unstyled shell chrome until shell CSS is shipped.

**[W-4]** docs/groups/scripts/step_780_nav_menu.php — `echo` output in seed script  
Recommendation: The seed script always emits `echo` messages, which PHPUnit flags as “risky” when it’s `require`d by tests. Wrap those `echo` calls in a `if (PHP_SAPI === 'cli')` guard or a `$verbose` flag so that tests do not produce unexpected stdout.

### NIT findings

**[NIT-1]** docs/groups/config/views.view.my_feed.yml — view dependencies  
Suggestion: Double-check that all modules referenced by the view (e.g. `group`, `gnode`) are listed under `dependencies.module` so that a missing module triggers a clear import error rather than a broken view.

**[NIT-2]** docs/groups/modules/do_streams/src/Hook/DoStreamsHooks.php — docblock detail  
Suggestion: Add a `@var array $empty_cta` annotation to the theme hook variables docblock to improve IDE support and self-documentation.

**[NIT-3]** docs/groups/modules/do_chrome/src/HelpText.php — indentation style  
Minor: the new lines use the file’s existing 2-space indentation, but the project’s Drupal standard is 4 spaces. Consider normalizing in a separate clean-up pass.

**[NIT-4]** docs/groups/modules/do_streams/src/Controller/MyFeedController.php — Controller::create()  
Minor: the overridden `create()` method does not call `parent::create()`. Although no services are injected now, calling `parent::create()` is forward-compatible if dependencies are added later.

### Verdict

BLOCK — critical issues around route requirement validation, silent view-missing fallback, and config import must be resolved before proceeding to testing.
