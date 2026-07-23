## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php:1-127  
Description: Every `AccessResult` returned by `groupRelationshipCreateAccess()` has only the group’s cache tags via `addCacheableDependency($group)`, but no cache contexts for the current user or their permissions.  
Why it blocks: Without `cachePerUser()` and `cachePerPermissions()`, Drupal may reuse an access decision across users, allowing or denying membership creation incorrectly.  
Remediation: On each `AccessResult` in `groupRelationshipCreateAccess()`, chain  
```php
  ->cachePerUser()
  ->cachePerPermissions()
```  
in addition to `addCacheableDependency($group)`.

**[B-2]** web/themes/custom/groups_chrome/groups_chrome.theme: lines 330-370  
Description: The new “Request to join” branch in `groups_chrome_preprocess_group()` gates rendering by calling `Url::fromRoute(...)->access($current_user)` but does not attach any render-caching metadata to vary by user, permissions, or group state.  
Why it blocks: Drupal’s page caching or BigPipe can serve a stale header action to the wrong user or after the group’s visibility changes.  
Remediation: Wrap the action link in a render array that specifies cache contexts, for example:  
```php
$gc['action']['#cache']['contexts'][] = 'user';
$gc['action']['#cache']['contexts'][] = 'user.permissions';
$gc['action']['#cache']['contexts'][] = 'url.path';
```
or add them to `$variables['#cache']` so the link is recalculated per user and per path.

**[B-3]** docs/groups/scripts/step_700_demo_data.php: around line 337  
Description: The new seed block checks `$sophie` and `$alex` but does not show where those variables are initialized.  
Why it blocks: If `$sophie` or `$alex` are undefined, the pending‐request seeding will never run (or trigger PHP notices), and E2E against the seeded site will fail to find any pending rows.  
Remediation: Within this block, explicitly load the user entities, e.g.  
```php
$user_storage = \Drupal::entityTypeManager()->getStorage('user');
$sophie = reset($user_storage->loadByProperties(['name' => 'sophie_mueller']));
$alex   = reset($user_storage->loadByProperties(['name' => 'alex_novak']));
```
or assert earlier in the script that those variables are defined.

### WARN findings

**[W-1]** docs/groups/modules/do_group_membership/src/Form/RequestJoinForm.php: buildForm()  
Description: Embedding raw `<p>` tags in a translatable string passed to `#markup` can lead to unsafe HTML if translations aren’t audited.  
Recommendation: Use a render array with `#type => 'html_tag'` (or `#prefix`/`#suffix`) around a plain-text `t()` string, so the markup and translation remain distinct and safe.

**[W-2]** docs/groups/modules/do_group_membership/src/Hook/GroupAccessHook.php: isGroupMembershipBundle()  
Description: Each call to `groupRelationshipCreateAccess()` loads the `group_relationship_type` config entity by ID, which may incur overhead on high-traffic sites.  
Recommendation: Cache the result per bundle in a static property or in the service, so the lookup happens once per request rather than on every access check.

**[W-3]** docs/groups/modules/do_group_membership/src/GroupMembershipManager.php: joinPolicyFor()  
Description: Unknown or malformed `field_group_visibility` values silently fall back to `'open'`, potentially re-enabling join on a misconfigured group.  
Recommendation: Log a warning or throw a configuration error if the field value is not one of the expected keys, to catch seed or config drift.

### NIT findings

**[NIT-1]** docs/groups/modules/do_group_membership/src/GroupMembershipManager.php  
The private helper `createMembership()` could be declared `protected` to facilitate subclassing or mocking in tests, if that ever becomes necessary.

**[NIT-2]** docs/groups/modules/do_group_membership/src/Routing/RouteSubscriber.php  
Consider using the PHP callable notation via `ManageMembersController::class . '::joinRouteAccess'` rather than a hard-coded string.

**[NIT-3]** tests/e2e/membership-models.spec.ts  
The two locator functions (`joinControl()` and `requestToJoinControl()`) share a lot of logic. You might extract a small helper library or class to DRY up role/selector combinations.

### Verdict

BLOCK — 3 blocking finding(s); these must be addressed before proceeding to formal testing.
