## Implementation Review (Round 1)

### BLOCK findings  
None.

### WARN findings  
**[W-1]** docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php:185  
When `$group` fails to load in `submitForm()`, the code logs an error message but does not issue a redirect. Users will remain on a broken form.  
– Why it blocks: in that error path, the user is stranded on a stale form with no way forward.  
– Recommendation: after adding the error message, call `$form_state->setRedirectUrl($this->getCancelUrl());` (or another sensible default) so the user is taken to a valid page.

**[W-2]** docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php:31  
The “archived” check does `$term->label() === 'Archive'`. If the Archive term label is ever translated or renamed, the logic will break.  
– Why it blocks: state detection becomes fragile and untestable under non-English locales.  
– Recommendation: centralize the Archive‐term ID (e.g. in a config constant or module setting) or load the term by a stable machine name or field value rather than by its human‐readable label.

**[W-3]** docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php:266  
Inside `catch(\Exception)`, the code uses `\Drupal::logger('do_group_extras')` rather than an injected logger service.  
– Why it blocks: relying on the static logger factory makes unit testing harder and introduces hidden dependencies.  
– Recommendation: inject `LoggerChannelFactoryInterface` (or `LoggerChannelInterface`) into the form via the constructor and use `$this->logger->error(...)`.

### NIT findings  
**[NIT-1]** docs/groups/modules/do_group_extras/src/Form/RestoreGroupForm.php:43  
The docblock for `preRenderAsButtonTag()` claims that core’s default `#type => 'submit'` already emits a `<button>`. In fact it emits `<input>`. It would help future maintainers if the docblock noted explicitly that this callback is correcting a core behavior, or if a short inline comment pointed to the relevant core template (`input.html.twig`).

**[NIT-2]** docs/groups/modules/do_group_extras/src/Controller/RestoreGroupAccess.php:37  
The access result is cache-tagged via `addCacheableDependency($group)` and scoped to user and permissions. You may consider whether a cache context on the group’s `field_group_type` value (e.g. `cacheContext('url.path')`) is needed for more fine-grained invalidation, though the entity tag alone will bust on save.

**[NIT-3]** docs/groups/modules/do_group_extras/tests/src/Functional/GroupRestoreAccessTest.php:52  
The test for non-privileged users only asserts a 403 response. AC-3 also calls for “Non-archived groups also return 403.” You have separate tests for non-archived cases, but you might collapse them or add comments to make it obvious that 403 is the intended outcome in both scenarios.

**[NIT-4]** tests/e2e/group-restore.spec.ts:64  
Step 2 only checks that the select has some value (`toHaveValue(/.*/)`). AC-8 calls out that the default should be “Working group.” Consider tightening this assertion to `expect(page.getByLabel(/Set group type to/i)).toHaveValue(workingGroupTid)` or to check for the visible “Working group.”

**[NIT-5]** tests/e2e/group-restore.spec.ts:25  
The `findLegacyInfrastructureGid()` helper scrapes `/admin/group` looking for the link text “Legacy Infrastructure.” If the admin UI changes, this test will break. Consider adding a machine-readable data attribute or using a direct API call to look up the seeded group’s ID.

**[NIT-6]** docs/groups/modules/do_group_extras/do_group_extras.links.task.yml:2  
The local-task plugin is declared without an explicit `type: task` or `provider:` entry. While Drupal infers it from the file name, adding the explicit keys makes the intent clearer and guards against future YAML schema changes.

### Verdict  
PASS — no BLOCK findings; implementation is ready for the testing phase.
