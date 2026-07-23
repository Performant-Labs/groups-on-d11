## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php:groupRelationshipDelete  
Description: The `groupRelationshipDelete()` method, when handling a `group_membership` deletion, calls  
```php
$this->deleteMessagesReferencing('user', (int) $member->id());
```  
which will delete *every* message whose `field_referenced_entity_type` is `'user'` and whose ID matches, including unrelated flagging‐created messages (e.g. `activity_flagging_created` for `follow_user`).  
Why it blocks: This contradicts the deletion‐hygiene contract (should only remove the membership-created messages) and will purge legitimate notifications (e.g. “follow_user”) when a membership is deleted.  
Remediation: Restrict the deletion to the membership-template only, for example:  
```php
$message_storage->getQuery()
  ->condition('template', 'activity_membership_created')
  ->condition('field_referenced_entity_type', 'user')
  ->condition('field_referenced_entity_id', $user_id)
  …
```
or extend `deleteMessagesReferencing()` to accept an optional template filter.

**[B-2]** docs/groups/modules/do_activity/do_activity.services.yml:3  
Description: The service declaration tags the hook class with `hook_implementations`. This is an unverified claim—core’s hook‐discovery machinery may expect a different tag name (for example, `hook_implementation` or another convention).  
Why it blocks: If the tag is wrong, none of the `#[Hook(...)]` methods will ever be registered or invoked, and the entire logging functionality will silently fail.  
Remediation: Confirm the exact service-tag name from Drupal core’s `HookDiscovery` implementation (or mirror exactly the tag used in `do_notifications.services.yml` if that is known to work), and update the tag accordingly.

### WARN findings

**[W-1]** docs/groups/modules/do_activity/tests/src/Kernel/PinTogglePinTest.php &  
docs/groups/modules/do_activity/tests/src/Kernel/FlaggingInsertTest.php  
Description: There are no tests verifying deletion hygiene for non‐pin flags (e.g. `rsvp_event`, `follow_user`) when they are unflagged.  
Recommendation: Add a `flagging_delete` test for at least one non‐pin flag to ensure that messages created by `activity_flagging_created` are removed on unflagging, in accordance with the `hook_flagging_delete` deletion‐hygiene requirement.

**[W-2]** docs/groups/modules/do_activity/src/Hook/DoActivityHooks.php vs.  
docs/groups/scripts/step_7xx_backfill_activity.php  
Description: The flag-ID is defined in two places (`private const PIN_FLAG_ID` and a global `DO_ACTIVITY_PIN_FLAG_ID`).  
Recommendation: Consolidate to a single shared constant or import, to avoid drift if the flag ID ever changes.

### NIT findings

**[NIT-1]** docs/groups/modules/do_activity/config/install/message.template.*.yml  
Description: The YAML uses inconsistent quoting (e.g. `settings:` → `'token options':` → `'token replace':`).  
Suggestion: Standardize quoting to the minimum needed (only quote keys containing special characters) for readability.

**[NIT-2]** docs/groups/scripts/step_7xx_backfill_activity.php  
Description: Missing a `@file` doc-tag at the top and inline FQCNs (`\Drupal\user\Entity\User`) without `use` statements.  
Suggestion: Add an `@file` docblock and consider importing classes for consistency with other scripts, though this category of script is exempt from the normal lint gate.

### Verdict

BLOCK — 2 blocking finding(s); must resolve before testing starts.
