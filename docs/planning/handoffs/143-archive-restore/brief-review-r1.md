## Brief Review (Round 1)

### BLOCK findings

**[B-1] Unverified access‐control assumptions**  
The brief says “mirror ManageMembersController” by allowing access if `$group->hasPermission('administer group', $account) || $account->hasPermission('administer group')`. We have not verified ManageMembersController’s exact implementation or ConfirmFormBase’s default access behavior. If those differ, we risk exposing the restore action to unintended personas or hiding it from the intended ones.  
– Why it blocks: incorrect or inconsistent permission checks could allow unauthorized restores or block legitimate ones.  
– What must be clarified or fixed: inspect the Drupal core ManageMembersController and ConfirmFormBase implementations; codify exactly which permission strings and methods are required; adjust RestoreGroupAccess::access() to match verified logic.

**[B-2] Missing form‐input validation and error‐handling paths**  
The plan assumes the `<select>` will always contain valid, non‐Archive term IDs. It does not address:  
  • What happens if the Archive term is deleted or renamed, so the vocabulary has no “Archive” term?  
  • What if an attacker tampers with the form payload and submits an invalid or empty term ID?  
  • What if the entity save fails (validation exceptions, database errors)?  
– Why it blocks: without defined behavior for these edge cases, we may leave the site in an inconsistent state or expose user‐unfriendly errors.  
– What must be clarified or fixed: define and test form validation handlers for missing/invalid TIDs, guard against a missing Archive term, catch save exceptions, and render appropriate error messages or HTTP status codes.

**[B-3] Undefined behavior when group is not archived**  
The brief says “deny access when the group is NOT Archive-typed,” but does not specify whether that should return a 403 or a 404. Different clients and tests may expect different responses.  
– Why it blocks: inconsistent HTTP status codes can break the e2e tests (AC-3) and violate REST principles.  
– What must be clarified or fixed: decide whether non-archived groups get 403 Forbidden (no permission) or 404 Not Found (resource not available), document this in the story, and cover it in the access plugin and tests.

**[B-4] Ambiguous round-trip in AC-8 (“archive → restore → archive”)**  
AC-8 says the E2E test “asserts archive → restore → archive round-trip” but does not specify how to perform the re-archive step: via the existing edit form, a separate “Archive” action, or an API call. Without precise steps, implementers will write divergent tests and risk flakiness.  
– Why it blocks: unclear test workflow leads to inconsistent coverage, leaving regressions undetected.  
– What must be clarified or fixed: update AC-8 to spell out the exact UI/API calls for the re-archive step (e.g. navigate to `/group/{id}/edit`, set `field_group_type` back to Archive, submit form, then assert UI state).

**[B-5] No strategy for handling save() failures**  
The submit handler plan simply calls `$group->save()` and adds a success message. There is no catch for potential exceptions or API errors.  
– Why it blocks: if the save fails (e.g. locking conflict, validation issue), users see no feedback or a raw exception.  
– What must be clarified or fixed: define error‐handling behavior on save failure, wrap the save in try/catch, log the error, show user‐friendly feedback, and write tests for that path.

**[B-6] Missing dependency‐injection spec for services**  
The outline does not describe how services (entity_type.manager, messenger, database, logger, etc.) will be injected into RestoreGroupForm and RestoreGroupAccess. Relying on ContainerAwareBase or static calls can lead to hard‐to‐test code.  
– Why it blocks: without a DI strategy, maintenance and testing will be harder, and code may violate Drupal best practices.  
– What must be clarified or fixed: specify constructor signatures for the form and the access class, list required services, and register them in the module’s service definitions.

### WARN findings

**[W-1] Recommend explicit term‐storage loading**  
Instead of calling `$group->set('field_group_type', $tid)` blindly, use the term storage service to load and validate the target term exists in the correct vocabulary. This avoids orphaning the field value if the term is invalid.

**[W-2] Local‐task visibility edge case**  
The local task tab is hidden when access is denied, but Drupal will still generate the tab link in the page template. Consider adding a `#only_shown_on_access` flag or using `hook_menu_local_tasks_alter()` to cleanly remove the tab when the group is not archived, preventing placeholder tabs or tooling confusion.

**[W-3] E2E test isolation**  
When writing `tests/e2e/group-restore.spec.ts`, ensure each persona’s session is fully isolated (cookies, localStorage) so that login/logout flows don’t leak state between test cases.

### NIT findings

**[NIT-1] Route and service naming consistency**  
Consider renaming the route to `do_group_extras.group.restore` and the service IDs to `do_group_extras.restore_group_form` for consistency with other group routes (`do_group_extras.group.archive`).

**[NIT-2] Translations and strings**  
Be sure all user‐visible strings in the form (`getQuestion()`, `getDescription()`, button labels) are wrapped in `t()` for localization.

**[NIT-3] ARIA attributes clarity**  
When implementing `aria-describedby` on the confirm button, ensure that the ID you point to is unique on the page and test it with a screen reader to confirm it announces the summary.

### Verdict

BLOCK — 6 blocking finding(s); must resolve before implementation starts.
