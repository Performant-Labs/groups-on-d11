## Implementation Review (Round 1)

### BLOCK findings

**[B-1]** docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php  
Use of the PHP attribute `#[Hook('preprocess_group')]` to register `preprocessGroup()` is unverified.  Drupal’s attribute‐based hook discovery is only available in recent versions and not all “preprocess_group” invocations are guaranteed to fire for the group full‐view pipeline.  If the hook never runs, the `group‐links` library will never be attached and the “Links & Resources” section will not receive its CSS.  
Why it blocks: we cannot be sure at merge time that any CSS or JS the feature depends on will ever be loaded.  
Remediation: confirm by inspecting Drupal core’s hook discovery implementation that `#[Hook('preprocess_group')]` is recognized for the “group” theme hook, or fall back to a known‐to‐work mechanism (for example, implementing `hook_preprocess_entity__group()` or `hook_entity_view_alter()` and checking `$view_mode === 'default'`).

**[B-2]** docs/groups/config/*.yml  
All of your new field and display configuration files live under `docs/groups/config/`, not in the site’s `config/sync/` tree or in the module’s `config/install/` folder.  It is not obvious from the repository that your build (or CI) process will actually import `docs/groups/config` into Drupal’s active configuration.  
Why it blocks: if those YAML never get applied, nothing you wrote in tests or code will ever take effect—no storage, no widget, no formatter.  
Remediation: either move these files into the module’s `config/install/` directory so they are installed when the module is enabled, or update (and document) your `scripts/ci/assemble-config.sh` (or equivalent) to copy `docs/groups/config/*` into the site’s `config/sync/` before import.

### WARN findings

**[W-1]** docs/groups/modules/do_group_extras/tests/src/Kernel/GroupLinksFieldTest.php:testInstanceExists()  
You assert label and field-level “required” but never assert that `FieldConfig::getSetting('title')` is `LinkTitleVisibility::Required` (2).  The spec says “title required per delta”—you should add a `$this->assertSame(2, $instance->getSetting('title'))` so a future drift to optional titles will fail the test.

**[W-2]** docs/groups/modules/do_group_extras/src/Hook/DoGroupExtrasHooks.php  
You currently attach the `do_group_extras/group-links` library on _every_ render of any community_group, regardless of view mode or whether there are links to render.  It’s harmless CSS-by-class, but for speed and clarity you may want to scope the attach to `if ($variables['view_mode'] === 'default' && !$group->get('field_group_links')->isEmpty())` (or at least test the field exists).

### NIT findings

**[NIT-1]** The new seed‐data step echoes directly to STDOUT.  If you ever string multiple seed scripts together you may prefer Drupal’s logger or an ordered console API.

**[NIT-2]** In your `core.entity_form_display.group.community_group.default.yml` you added `region: content`.  That key is only used by Layout Builder; if you’re not using that, you can remove it to keep your display config lean.

### Verdict

BLOCK — there are 2 blocking issues that must be resolved before proceeding to testing.
