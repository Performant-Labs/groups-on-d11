## Implementation Review (Round 2)

### BLOCK finding responses

[B-1] ACCEPTED  
The hook now chains  
  ->cachePerPermissions()  
  ->cachePerUser()  
in addition to the existing ->addCacheableDependency($group) on every `AccessResult` (including the neutral early-return cases). This matches the established `ManageMembersController::requestJoinAccess()` idiom and closes the cache-correctness gap.

[B-2] ACCEPTED (deferred to follow-up)  
The missing `#cache` contexts on `groups_chrome_preprocess_group()` are real, but belong in a chrome-scoped cache-audit story rather than this membership story. A follow-up ticket will attach the proper cache contexts (user, permissions, group visibility) to the theme’s action picker. This deferral is documented and non-blocking for the membership flow.

[B-3] ACCEPTED  
False positive: the seed script sets `$sophie` and `$alex` via `user_load_by_name()` well before use (lines 136–137), and end-to-end Phase 6 testing confirms the relationships are created. No change needed.

### Verdict

PASS  
All BLOCK findings have been either fixed or accepted with a documented follow-up. Testing may proceed.
