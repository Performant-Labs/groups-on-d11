## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_group_membership/src/Form/ManageMembersForm.php: buildForm() — no actual pagination of the membership list  
Description: The code unconditionally fetches and renders every membership (`$memberships = $group->getMembers()`) and then drops in a `<pager>`, but never slices the list or initializes the pager (e.g. via `pager_default_initialize()` or a select-extender) to show only 50 rows per page.  
Why it blocks: AC-15/W-2 require the member table to paginate at 50 rows; without limiting the dataset to the current page, the pager UI won’t work and the acceptance criterion is not met.  
Remediation: Before looping over `$memberships`, invoke `pager_default_initialize(count($memberships), 50)` (or use a `PagerSelectExtender`) and slice `$memberships` to the current page’s portion (e.g. `array_slice($memberships, $current_page * 50, 50)`).

### WARN findings

**[W-1]** docs/groups/modules/do_group_membership/src/Controller/ManageMembersController.php: access() — incomplete cache contexts  
Description: The access result is varied by user permissions and the group entity, but does not explicitly vary by route (group id) or by user identity.  
Recommendation: Add `->cacheContext('url')` or `->cachePerUser()` (in addition to `cachePerPermissions()`) so that Drupal’s page cache correctly varies by the URL (group parameter) and by the specific user.

**[W-2]** docs/groups/modules/do_group_membership/src/GroupMembershipManager.php: addMember() signature — overly broad account type  
Description: The method accepts a generic `AccountInterface` and then guards for `isBlocked()` via `method_exists()`, but `GroupInterface::addMember()` really expects a `UserInterface`.  
Recommendation: Change the parameter type to `\Drupal\user\UserInterface` to make the contract explicit (or document that callers must pass a `UserInterface`), so you don’t risk passing arbitrary `AccountInterface` implementations that aren’t real user entities.

### NIT findings

**[NIT-1]** docs/groups/modules/do_group_membership/css/manage-members.css: comment refers to `groups_chrome` theme edits (line 4)  
Description: The header note mentions avoiding “groups_chrome theme edits,” which is confusing in a module-owned CSS file.  
Recommendation: Remove or reword that reference to keep the comment focused on this module’s CSS.

**[NIT-2]** docs/groups/modules/do_group_membership/libraries.yml: version: 1.x (line 2)  
Description: The `version:` property in a module library is not commonly used and can be omitted.  
Recommendation: Remove the `version:` line or align it with the module’s actual versioning convention.

**[NIT-3]** docs/groups/modules/do_group_membership/src/Form/AddMemberForm.php: trailing comma in argument list (around line 61)  
Description: The `static function create()` call ends its argument list with an extra comma.  
Recommendation: Remove the trailing comma.

**[NIT-4]** docs/groups/config/group.role.community_group-groups_moderate.yml: weight: 0 (line 6)  
Description: All three new roles (organizer, moderator, groups_moderate) use the same weight.  
Recommendation: Review the tab ordering and adjust the `weight` to position “Groups Moderate” appropriately relative to Organizer and Moderator in the UI.

### Verdict

BLOCK — 1 blocking finding (pagination) must be resolved before testing may proceed.
