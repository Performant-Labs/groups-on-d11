# Runbook — Groups for Standard Drupal (pl-drupalorg)

> [!CAUTION]
> **THIS RUNBOOK IS THE PRIMARY DELIVERABLE.** It will be used for a clean-room install on a fresh site. Every command must be copy-paste ready and produce the documented result.
>
> **When you discover ANY deviation during implementation** — a wrong service name, a character limit, an extra module that must be enabled, a property name correction — **you must update this runbook IMMEDIATELY, before moving to the next step.** Do not defer runbook updates. Do not report deviations only in chat. The runbook must always reflect what actually works.
>
> **If it's not in the runbook, it didn't happen.**

> [!IMPORTANT]
> **All changes must be made via config entities** — create or modify config YAML files, then import them with `ddev drush cim` or `ddev drush php:eval`. **Never make changes through the Drupal admin UI or browser automation (Playwright, etc.).**
>
> "Navigate to `/admin/...`" in **implementation steps** describes *what* is being configured — the actual implementation must create or modify the corresponding config YAML and import it. In **verification steps**, "Navigate to" is fine — visit the admin UI to visually confirm the result.
>
> **Workflow**: `php:eval` creates the entity → `config:export` captures it as YAML → commit the YAML. The exported YAML is the deliverable, not the `php:eval` command.

> [!CAUTION]
> **Config import sequencing.** Within each phase, the order is:
> 1. **Install modules** (`ddev drush en <module> -y`) — registers the module in `core.extension`
> 2. **Run setup scripts** (`ddev drush php:script ...`) — creates entities programmatically
> 3. **Export config** (`ddev drush config:export -y`) — captures new entities as YAML
> 4. **Copy pre-made YAML** from `docs/groups/config/` to `config/sync/` only when the runbook explicitly says to
> 5. **Import config** (`ddev drush cim -y`) — only when the runbook explicitly says to
>
> Never run `cim` before the required modules are installed — Drupal will reject YAML that depends on uninstalled modules.
>
> All `docs/groups/config/` YAML files are reference copies. The scripts create the same entities. You only need the YAML for verification or to restore state.

> [!IMPORTANT]
> **Checkpoint after every phase.** After completing all steps and verification for a phase, stop and check in with the user before proceeding to the next phase.

> [!IMPORTANT]
> **Prefer contributed modules over custom code.** Before porting any custom module from Open Social, search [drupal.org/project](https://www.drupal.org/project/project_module) for an existing contributed module that provides the same functionality. Only write custom code when no suitable contributed module exists or when the contributed options are unmaintained / incompatible with Drupal 11 + Group 4.x. Document the search results and rationale for each decision.

> [!IMPORTANT]
> **Copy module source before enabling.** Custom module source files live in `docs/groups/modules/`. Before running `ddev drush en <module>` for any `do_*` module, copy its directory into `web/modules/custom/`:
> ```bash
> cp -r docs/groups/modules/do_group_extras web/modules/custom/
> ```
> Each phase notes which module to copy. Do this before the `ddev drush en` command for that module.

> [!IMPORTANT]
> **All custom modules must follow the Services over Hooks pattern.** Before writing or porting any custom module, read [`playbook/frameworks/drupal/best-practices.md`](../playbook/frameworks/drupal/best-practices.md). Procedural hook-based modules are not acceptable in this project.

This runbook documents the step-by-step process for adding groups functionality to the standard Drupal 11 codebase (pl-drupalorg).

> [!IMPORTANT]
> **Group 4.x target.** This runbook targets **Drupal `^11.2`** (epic target 11.4) and **`drupal/group:^4`** (alpha). Group 4.x drops the `variationcache`, `flexible_permissions`, and `entity` (Entity API) contrib dependencies — VariationCache and the Access Policy API are now in core, and Group 4.x uses core's revision UI. Per-group permission calculation moves from the contrib **Flexible Permissions** module to **Drupal core's Access Policy API** (`access_policy`-tagged services). The `GroupRelationshipType` config property `content_plugin` is renamed to `relation_type`. See [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md) for the full 3→4 API/config delta.
> <!-- VERIFY on build: which Group 4.x alpha to pin (alpha1 vs alpha2). The `content_plugin`→`relation_type` rename lands in 4.0.0-alpha2, so prefer the newest alpha available. -->
> <!-- VERIFY on build: exact core version pinned in the site composer.json (must be ≥ 11.2; epic target 11.4). -->


See [GROUPS_CONVERSION_PLAN.md](GROUPS_CONVERSION_PLAN.md) for the gap analysis, key differences between the projects, and the overall phase plan.


---

## Prerequisites (all phases)

- DDEV running (`ddev status` shows all services healthy)
- Site accessible at `https://pl-groups-on-d11.ddev.site:8493`
- Git working tree clean on `aa/initial-plan` branch

> [!IMPORTANT]
> Before running any commands or tests, review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) for known gotchas with DDEV, Playwright, Drupal configuration, and process cleanup. Many of the issues documented there (opcode cache staleness, port variability, `networkidle` hangs, config import locks) apply to this project.

---

## Contributed Module Dependencies

All contributed modules referenced in this runbook, verified on drupal.org:

| Module | Composer package | drupal.org | Used in | Notes |
|---|---|---|---|---|
| Group | `drupal/group` | [drupal.org/project/group](https://www.drupal.org/project/group) | Phase 1 | Core dependency; use `^4` for the `group_relationship` API. No longer depends on `variationcache` / `flexible_permissions` / `entity` (all folded into core). |
| Group Node (gnode) | sub-module of `drupal/group` | — | Phase 1 | Sub-module bundled with Group; enables `group_node:*` relationship plugins |
| Flag | `drupal/flag` | [drupal.org/project/flag](https://www.drupal.org/project/flag) | Phase 4 | Includes `flag_count` sub-module (not a separate package) |
| Linkit | `drupal/linkit` | [drupal.org/project/linkit](https://www.drupal.org/project/linkit) | Phase 1 | Optional — inline autocomplete linking |
| Search API | `drupal/search_api` | [drupal.org/project/search_api](https://www.drupal.org/project/search_api) | Phase 7 | Required by Search API Solr |
| Search API Solr | `drupal/search_api_solr` | [drupal.org/project/search_api_solr](https://www.drupal.org/project/search_api_solr) | Phase 7 | Optional — only if using Solr backend |
| Message | `drupal/message` | [drupal.org/project/message](https://www.drupal.org/project/message) | Phase 5 | Optional — structured notification queue entities |
| Message Notify | `drupal/message_notify` | [drupal.org/project/message_notify](https://www.drupal.org/project/message_notify) | Phase 5 | Optional — pluggable notifier framework (delivery handled by external system) |

> [!WARNING]
> **`statistics` module**: Deprecated in Drupal 10.3.0, removed in Drupal 11.0.0. If on Drupal 10.3+, install from contrib: `ddev composer require drupal/statistics` ([drupal.org/project/statistics](https://www.drupal.org/project/statistics)). On Drupal ≤10.2, use `ddev drush en statistics -y` (still in core).

> [!CAUTION]
> **`flag_count` is NOT a separate Composer package.** It is a sub-module bundled inside `drupal/flag`. Do NOT run `ddev composer require drupal/flag_count` — it will fail. Instead, require `drupal/flag` and enable the sub-module with `ddev drush en flag_count -y`.

---

## Rollback Strategy

A database snapshot is taken **before** each phase begins. To roll back to the start of any phase:

1. **Code**: `git log --oneline` → find the commit before the phase → `git checkout <commit>`
2. **Database**: `ddev import-db --file=backups/pre-phaseN-YYYYMMDD-HHMM.sql.gz`
3. **Cache**: `ddev drush cr`

> [!TIP]
> After every `ddev drush config:export -y`, commit the exported YAML to git. This creates the rollback points listed above.

| Snapshot file | Taken before |
|---|---|
| `pre-phase1-*.sql.gz` | Phase 1 (Foundation) |
| `pre-phase2-*.sql.gz` | Phase 2 (Group Types) |
| `pre-phase3-*.sql.gz` | Phase 3 (Content) |
| `pre-phase4-*.sql.gz` | Phase 4 (Discovery) |
| `pre-phase5-*.sql.gz` | Phase 5 (Notifications) |
| `pre-phase6-*.sql.gz` | Phase 6 (Profiles) |
| `pre-phase7-*.sql.gz` | Phase 7 (Demo Data) |
| `pre-phase8-*.sql.gz` | Phase 8 (Feature Tour) |
| `demo-empty-*.sql.gz` | After clean slate (Step 700) |
| `demo-complete-*.sql.gz` | After full demo data (Step 730) |

---

# Phase 1 — Foundation & Module Installation

**Goal**: Install the Drupal Group module, create the base group type, and establish the 5 group-postable content types.

> [!IMPORTANT]
> **YAML-first policy.** All Drupal configuration in this project is managed as YAML config entities, not PHP scripts. Config entities (content types, field storage, field instances, group types, relationship types, vocabularies, views) live in `docs/groups/config/`. Data entities (taxonomy terms, users, demo content) use scripts in `docs/groups/scripts/`.

## Group-Postable Content Types

This project creates the following 5 content types from scratch. They do not pre-exist.

| Content type | Purpose | Config file |
|---|---|---|
| `forum` | Community discussion threads | `node.type.forum.yml` |
| `documentation` | Long-form reference docs | `node.type.documentation.yml` |
| `event` | Community events | `node.type.event.yml` |
| `post` | Short-form posts | `node.type.post.yml` |
| `page` | General static pages | `node.type.page.yml` |

## Step 100 — Create Baseline Snapshot

> [!IMPORTANT]
> This must be the **first step** before ANY changes. It captures the pristine state of the site for rollback.

```bash
mkdir -p backups
ddev export-db --file=backups/pre-phase1-$(date +%Y%m%d-%H%M).sql.gz
git add -A && git status
```

Verify the backup file exists and note its filename in the rollback table above.

## Step 105 — Configure config sync directory

DDEV defaults config sync to `sites/default/files/sync`. Override it to `config/sync` (outside the webroot, under version control).

```bash
mkdir -p config/sync
```

In `web/sites/default/settings.php`, uncomment and set:
```php
$settings['config_sync_directory'] = '../config/sync';
```

Capture the initial baseline config:
```bash
ddev drush config:export -y
git add config/sync && git commit -m "chore: initial config export"
```

> [!IMPORTANT]
> The `config_sync_directory` line in `settings.php` must appear **before** `settings.ddev.php` is included. DDEV's `settings.ddev.php` sets this to `sites/default/files/sync` as a fallback — your explicit setting overrides it.

## Step 110 — Install the Group Module

Group 4.x is an alpha release, so Composer needs to accept alpha stability. Pin the constraint to an alpha (`^4.0@alpha`) or set `minimum-stability: alpha` in `composer.json`:

```bash
# Allow the symfony/runtime Composer plugin (required by Drupal 11 / Group 4.x)
ddev composer config allow-plugins.symfony/runtime true
# Require Group 4.x (fresh install — nothing to upgrade from).
ddev composer require "drupal/group:^4.0@alpha"
```

> [!NOTE]
> **Removed contrib dependencies — not present on a clean build.** Group 4.x no longer depends on `drupal/variationcache`, `drupal/flexible_permissions`, or `drupal/entity` (Entity API) — VariationCache and the Access Policy API are now in Drupal core (≥ 10.2 / ≥ 10.3), and Group 4.x uses core's revision UI. On this clean-room build these were never required, so a fresh `composer install` never pulls them — there is nothing to remove or uninstall. (The remove/uninstall dance only applies when *upgrading an existing v3 site*.)

Standard Drupal Group module (not Open Social's bundled version):
- **4.x**: `GroupRelationship` entities on **Drupal ^11.2** (epic target 11.4); creator auto-membership is now **form-only**, and "add to group" invalidates cache tags instead of resaving the related entity.

> <!-- VERIFY on build: exact Group 4.x alpha/beta pinned in the site composer.json (prefer the newest alpha available, since the content_plugin→relation_type rename lands in 4.0.0-alpha2). -->
> <!-- VERIFY on build: exact core version in the site composer.json (must be ≥ 11.2; epic target 11.4). -->

## Step 115 — (skipped: no update hooks on a clean-room build)

> [!NOTE]
> This runbook installs Group 4.x **fresh** — there is **no existing v3 database to migrate**, so there is **no `drush updatedb` / config-migration step**. The `config/sync` YAML is already in v4 `relation_type` format and imports directly onto Group 4.x during the normal config import.
>
> _Upgrading an existing v3 site instead? That path — running `ddev drush updatedb` to execute Group's `content_plugin`→`relation_type` migration hooks (dry-run on a DB copy first) — is **out of scope for this clean-room build**._

> [!NOTE]
> On a **clean-room install** (no existing 3.x data) there is nothing to migrate — the `config/sync` YAML already ships the `relation_type:` key. This step matters only when upgrading an existing 3.x site.

## Step 120 — Enable All Required Modules

Enable the group modules plus all Drupal core modules needed for Phase 1 field types (`variationcache`, `flexible_permissions`, and `entity` are **not** enabled — they are dropped in 4.x):

```bash
ddev drush en group gnode image taxonomy views views_ui text -y
```

> [!IMPORTANT]
> **Export immediately after enabling modules.** This updates `core.extension` in `config/sync` to reflect the newly enabled modules. If you skip this export and add custom YAML configs first, `config:import` will fail because `core.extension` won't list the modules as dependencies.

```bash
ddev drush config:export -y
```

Verify:
```bash
ddev drush pm:list --status=enabled | grep -E "group|gnode"
# Should show: group, gnode (both enabled)
```

## Step 125 — Create the 5 Group-Postable Content Types

These content types do not exist on a clean install. Create them via YAML config import.

```bash
cp docs/groups/config/node.type.forum.yml config/sync/
cp docs/groups/config/node.type.documentation.yml config/sync/
cp docs/groups/config/node.type.event.yml config/sync/
cp docs/groups/config/node.type.post.yml config/sync/
cp docs/groups/config/node.type.page.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify:
```bash
ddev drush php:eval 'foreach (\Drupal::entityTypeManager()->getStorage("node_type")->loadMultiple() as $t) echo $t->id() . "\n";'
# Should show: documentation, event, forum, page, post
```

## Step 130 — Create Group Type and Fields

Import the group type, admin role, and group fields via YAML:

```bash
cp docs/groups/config/group.type.community_group.yml config/sync/
cp docs/groups/config/group.role.community_group-admin.yml config/sync/
cp docs/groups/config/group.settings.yml config/sync/
cp docs/groups/config/group.relationship_type.community_group-group_membership.yml config/sync/
cp docs/groups/config/field.storage.group.field_group_description.yml config/sync/
cp docs/groups/config/field.storage.group.field_group_visibility.yml config/sync/
cp docs/groups/config/field.storage.group.field_group_image.yml config/sync/
cp docs/groups/config/field.storage.group_relationship.group_roles.yml config/sync/
cp docs/groups/config/field.field.group.community_group.field_group_description.yml config/sync/
cp docs/groups/config/field.field.group.community_group.field_group_visibility.yml config/sync/
cp docs/groups/config/field.field.group.community_group.field_group_image.yml config/sync/
cp docs/groups/config/field.field.group_relationship.community_group-group_membership.group_roles.yml config/sync/
cp docs/groups/config/core.entity_form_display.group_relationship.community_group-group_membership.default.yml config/sync/
cp docs/groups/config/core.entity_view_display.group_relationship.community_group-group_membership.default.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

**Group type settings:**

| Setting | Value |
|---|---|
| Label | Community Group |
| Machine name | `community_group` |
| Creator is member | Yes |
| Creator role | `community_group-admin` |

**Fields added to group type:**

| Field | Type | Machine name |
|---|---|---|
| Description | Text (formatted, long) | `field_group_description` |
| Visibility | List (text) | `field_group_visibility` |
| Image | Image | `field_group_image` |

> [!NOTE]
> `field_group_type` and `field_group_language` are added in later phases.

> [!CAUTION]
> **`Group::create()` does NOT auto-join the creator (Group 4.x).** In Group 4.x, creator auto-membership is **form-only** — programmatically created groups (migrations, tests, demo-data scripts, any module that spins up groups via the API) **no longer** add the creator as a member, even with the `creator_membership` group type setting enabled. That setting applies only to groups created through the form UI. When creating groups programmatically, explicitly add the creator as a member:
> ```php
> $group->addMember(\Drupal\user\Entity\User::load($uid));
> ```
> This is a **behavioral change from 3.x** (CR 2026-04-24). Any Phase 7 demo-data or test fixture that assumed "create a group → creator is a member" must now add the membership explicitly.

## Step 140 — Configure Group-Node Relationships

Import the 5 group-node relationship type configs:

```bash
cp docs/groups/config/group.relationship_type.community_group-group_node-forum.yml config/sync/
cp docs/groups/config/group.relationship_type.community_group-group_node-doc.yml config/sync/
cp docs/groups/config/group.relationship_type.community_group-group_node-event.yml config/sync/
cp docs/groups/config/group.relationship_type.community_group-group_node-post.yml config/sync/
cp docs/groups/config/group.relationship_type.community_group-group_node-page.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

| Relationship type ID | Content type |
|---|---|
| `community_group-group_node-forum` | forum |
| `community_group-group_node-doc` | documentation |
| `community_group-group_node-event` | event |
| `community_group-group_node-post` | post |
| `community_group-group_node-page` | page |

> [!CAUTION]
> **32-character ID limit.** `community_group-group_node-documentation` is 40 chars. Use the abbreviated ID `community_group-group_node-doc`.

## Step 150 — Set Up Basic Permissions

### Drupal-level permissions

Grant the following Drupal-level permissions *(admin UI: `/admin/people/permissions`)*:

| Permission | Roles |
|---|---|
| `create community_group group` | Authenticated users |
| `access group overview` | Anonymous + Authenticated users |

```bash
ddev drush role:perm:add authenticated "create community_group group"
ddev drush role:perm:add anonymous "access group overview"
ddev drush role:perm:add authenticated "access group overview"
```

### Group-level permissions

Configure the following group-level permissions *(admin UI: `/admin/group/types/manage/community_group/permissions`)*:

| Permission | Member | Admin | Outsider |
|---|---|---|---|
| View group | ✅ | ✅ | ✅ |
| Edit group | ❌ | ✅ | ❌ |
| Delete group | ❌ | ✅ | ❌ |
| Administer members | ❌ | ✅ | ❌ |
| Join group | — | — | ✅ |
| Leave group | ✅ | ✅ | — |
| Create content in group | ✅ | ✅ | ❌ |
| Edit own content in group | ✅ | ✅ | ❌ |
| Edit any content in group | ❌ | ✅ | ❌ |
| Delete own content in group | ✅ | ✅ | ❌ |
| Delete any content in group | ❌ | ✅ | ❌ |

> [!NOTE]
> Group-level permissions are configured via the Group module's permission system, not Drupal's core role:perm system. These are set via the admin UI or by importing `group.role.community_group-*.yml` config files after `config:export`.

> [!IMPORTANT]
> **Group 4.x uses core's Access Policy API, not Flexible Permissions.** The *declaration* of group permissions (the role config above, and each relation plugin's permission provider) is unchanged. What changed is the *calculation* side: in Group 3.x, per-group permissions were computed by the contrib **Flexible Permissions** module (`permission_calculator` service, `PermissionCalculatorInterface`). In Group 4.x that machinery is gone — the equivalent is **Drupal core's [Access Policy API](https://www.drupal.org/docs/develop/drupal-apis/access-policy-api)** (core ≥ 10.3). Group registers its scopes (historically `group_outsider` / `group_insider` / `group_individual`) as `access_policy`-tagged services extending `Drupal\Core\Session\AccessPolicyBase`, and the aggregated result is an **immutable** `CalculatedPermissions` object. No site-level config change is needed for the stock roles here, but **any custom module that implemented a `permission_calculator` service must be re-implemented as an `access_policy` service** (this is the `do_multigroup` re-home in Phase 3, and any similar custom calculator). See [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md) §2.
> <!-- VERIFY on build: the exact scope constant names/values Group 4.x ships (read group/src/Access/*) and the exact core FQCNs for the upstreamed permission classes (CalculatedPermissions, CalculatedPermissionsItem, RefinableCalculatedPermissionsInterface, AccessPolicyBase). -->

```bash
ddev drush config:export -y
```

## Step 160 — Create Group Listing View + Tag Taxonomy

Import the All Groups view and the `event_types` and `group_tags` vocabularies:

```bash
cp docs/groups/config/views.view.all_groups.yml config/sync/
cp docs/groups/config/taxonomy.vocabulary.event_types.yml config/sync/
cp docs/groups/config/taxonomy.vocabulary.group_tags.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: navigate to `/all-groups` — should load (empty list, no groups yet).

## Step 170 — Add Basic Permissions

```bash
ddev drush role:perm:add authenticated "create community_group group"
ddev drush role:perm:add authenticated "access group overview"
ddev drush role:perm:add anonymous "access group overview"
ddev drush config:export -y
```

**Group-level permissions** *(configured via `/admin/group/types/manage/community_group/permissions`)*:

| Permission | Member | Admin | Outsider |
|---|---|---|---|
| View group | ✅ | ✅ | ✅ |
| Edit group | ❌ | ✅ | ❌ |
| Delete group | ❌ | ✅ | ❌ |
| Administer members | ❌ | ✅ | ❌ |
| Join group | — | — | ✅ |
| Leave group | ✅ | ✅ | — |
| Create content in group | ✅ | ✅ | ❌ |
| Edit own content in group | ✅ | ✅ | ❌ |
| Edit any content | ❌ | ✅ | ❌ |
| Delete own content | ✅ | ✅ | ❌ |
| Delete any content | ❌ | ✅ | ❌ |

> [!NOTE]
> Group-level permissions are set via the Group module's permission system, not `drush role:perm`. Configure via admin UI or import `group.role.community_group-*.yml` configs after `config:export`.

## Step 180 — Add Required Fields to Content Types

Add `body`, `field_group_tags`, and `field_event_type` (events only) to all 5 content types:

```bash
cp docs/groups/config/field.storage.node.body.yml config/sync/
cp docs/groups/config/field.field.node.forum.body.yml config/sync/
cp docs/groups/config/field.field.node.documentation.body.yml config/sync/
cp docs/groups/config/field.field.node.event.body.yml config/sync/
cp docs/groups/config/field.field.node.post.body.yml config/sync/
cp docs/groups/config/field.field.node.page.body.yml config/sync/
cp docs/groups/config/field.storage.node.field_group_tags.yml config/sync/
cp docs/groups/config/field.field.node.forum.field_group_tags.yml config/sync/
cp docs/groups/config/field.field.node.documentation.field_group_tags.yml config/sync/
cp docs/groups/config/field.field.node.event.field_group_tags.yml config/sync/
cp docs/groups/config/field.field.node.post.field_group_tags.yml config/sync/
cp docs/groups/config/field.field.node.page.field_group_tags.yml config/sync/
cp docs/groups/config/field.storage.node.field_event_type.yml config/sync/
cp docs/groups/config/field.field.node.event.field_event_type.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify with the field check script:
```bash
ddev drush php:script docs/groups/scripts/step_160.php
```

Expected output:
```
=== Content type field verification ===
forum: body=YES files=NO tags=YES comment=NONE
documentation: body=YES files=NO tags=YES comment=NONE
event: body=YES files=NO tags=YES comment=NONE
post: body=YES files=NO tags=YES comment=NONE
page: body=YES files=NO tags=YES comment=NONE
```

> [!NOTE]
> `files=NO` and `comment=NONE` are expected at this stage. File attachments and comments are Phase 2+ concerns.

## Step 190 — Phase 1 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

> [!IMPORTANT]
> **Provision the test tooling first.** A production `composer install` does not pull
> PHPUnit or Drupal's test base classes. Before any `phpunit` step, add the dev
> dependency once:
> ```bash
> ddev composer require --dev "drupal/core-dev:^11.4" -W
> ```
> Then invoke the vendored binary with the standard env (DDEV's `ddev exec phpunit`
> shim may not be present):
> ```bash
> ddev exec 'SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=https://web \
>   php vendor/bin/phpunit -c web/core/phpunit.xml.dist \
>   web/modules/custom/do_tests/tests/src/Kernel/'
> ```

> [!NOTE]
> **Clean-room vs. target-environment config.** A few `docs/groups/config/*.yml`
> entries carry drupal.org-environment dependencies and are **not** imported on a
> standard-profile clean-room build (they are placed in the real environment per
> Step 600h): the three `block.block.do_*` blocks (depend on the `bluecheese`
> theme), `pathauto.pattern.group_relationship` (needs `pathauto`), and
> `user.role.community` + its two `system.action.user_*_role_action.community`
> actions (reference Contact and drupal.org-only node types). Exclude these seven
> from `config/sync` when validating the clean-room `config:import`.

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase1Test.php`:

- `testGroupTypeExists()` — `community_group` group type config entity loads
- `testRelationshipTypesExist()` — 5 relationship types: `community_group-group_node-{forum,doc,event,post,page}`
- `testGroupMembershipType()` — `community_group-group_membership` relationship type exists
- `testGroupFields()` — `field_group_description`, `field_group_visibility`, `field_group_image` on `community_group`
- `testEventTypesVocabulary()` — `event_types` vocabulary exists with expected terms

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase1Test.php
```

> [!NOTE]
> Phase 1 has no custom modules yet — all tests are integration tests in `do_tests`.

### Playwright (E2E)

Create `tests/e2e/phase1.spec.ts`:

- `test('group listing page loads')` — navigate to `/groups`, verify 200 and page title
- `test('authenticated user can create group')` — log in, create group, verify redirect
- `test('group type fields render')` — verify form fields on `/group/add/community_group`
- `test('group-node relationship works')` — create group, post forum topic, verify in group stream
- `test('permissions enforced')` — anonymous gets 403 on group create

```bash
npx playwright test tests/e2e/phase1.spec.ts
```

## Phase 1 — Verification

- [ ] `drupal/group` at `^4` (alpha) in `composer.json` and `composer.lock`; core `^11.2`
- [ ] `variationcache`, `flexible_permissions`, and `entity` are **absent** from `composer.json` `require` and from enabled modules (`ddev drush pm:list --status=enabled` shows none of them)
- [ ] `ddev drush pm:list --status=enabled | grep -E "group|gnode"` shows both enabled
- [ ] `allow-plugins.symfony/runtime` is `true` in `composer.json`
- [ ] All `group.relationship_type.*.yml` use the `relation_type:` key (not `content_plugin:`)
- [ ] `config/sync/` exists and `ddev drush config:status` shows clean (no differences)
- [ ] 5 content types exist: `forum`, `documentation`, `event`, `post`, `page`
- [ ] `community_group` group type exists at `/admin/group/types`
- [ ] Group type has fields: `field_group_description`, `field_group_visibility`, `field_group_image`
- [ ] 5 group-node relationship types exist (`community_group-group_node-{forum,doc,event,post,page}`)
- [ ] Group listing view accessible at `/all-groups`
- [ ] `event_types` and `group_tags` vocabularies exist
- [ ] `ddev drush php:script docs/groups/scripts/step_160.php` shows `body=YES tags=YES` for all 5 types
- [ ] `ddev drush cr` — no PHP errors
- [ ] DB snapshot in `backups/phase1-complete-*.sql.gz`

### Schema Changes (Phase 1)

| Type | Name | Notes |
|---|---|---|
| **Entity type** | `group` (via `drupal/group`) | Core dependency |
| **Group type** | `community_group` | Custom group type |
| **Group roles** | `community_group-{admin,member,outsider}` | 3 roles |
| **Group relationships** | `community_group-group_node-{forum,doc,event,post,page}` | 5 content types (`doc` = abbreviated `documentation` due to 32-char ID limit) |
| **Group relationship** | `community_group-group_membership` | User membership |
| **Field (group)** | `field_group_description` | Text (formatted, long) |
| **Field (group)** | `field_group_visibility` | List (public/community/secret) |
| **Field (group)** | `field_group_image` | Image |
| **Taxonomy** | `event_types` | 7 terms (DrupalCon, Sprint, etc.) |
| **View** | `groups` at `/groups` | Group listing page |
| **Module** | `linkit` | Optional — inline linking |

### Open Questions (Phase 1)

1. ~~Which content types should be postable to groups?~~ ✅ Confirmed: `forum`, `documentation`, `event`, `post`, `page`
2. ~~Which version of `drupal/group`?~~ ✅ Resolved: **`^4` (alpha)** for this epic (was 3.3.5). Requires Drupal `^11.2`. <!-- VERIFY on build: exact alpha/beta pinned. -->
3. ~~Group creation — open to all authenticated users, or restricted role?~~ ✅ Confirmed: any authenticated user can create groups

---

# Phase 2 — Group Types & Membership Models

**Goal**: Configure group types, membership models, group directory, and the `do_group_extras` module.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 3

## Pre-Phase 2 Snapshot

```bash
ddev export-db --file=backups/pre-phase2-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 200 — Create `group_type` Taxonomy Vocabulary + Terms

`group_type` is a config entity (vocabulary) plus data entities (terms). Import the vocabulary via YAML, then create terms via script:

```bash
cp docs/groups/config/taxonomy.vocabulary.group_type.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y

# Create the 5 terms (data entities — script is appropriate here)
ddev drush php:script docs/groups/scripts/step_200.php
```

Expected output:
```
group_type vocabulary already exists
Created: Geographical (tid=1)
Created: Working group (tid=2)
Created: Distribution (tid=3)
Created: Event planning (tid=4)
Created: Archive (tid=5)
5 group_type terms total
```

> [!NOTE]
> Terms are data entities (not config YAML). TIDs will vary per environment. The script is idempotent — safe to re-run.

## Step 210 — Add `field_group_type` to Group Entity

Import the field storage and instance via YAML:

```bash
cp docs/groups/config/field.storage.group.field_group_type.yml config/sync/
cp docs/groups/config/field.field.group.community_group.field_group_type.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

| Setting | Value |
|---|---|
| Field type | Entity reference |
| Machine name | `field_group_type` |
| Reference type | Taxonomy term → `group_type` vocabulary |
| Required | No |
| Cardinality | 1 |

> [!IMPORTANT]
> Verify `handler_settings.target_bundles` is set to `group_type` in `field.field.group.community_group.field_group_type.yml`. Mismatch causes empty dropdowns.

## Step 220 — Configure Membership Models

The `field_group_visibility` field (added in Phase 1 via YAML) controls join behaviour:

| Membership model | `field_group_visibility` value | Join behaviour |
|---|---|---|
| **Open** | `open` | Instant join |
| **Moderated** | `moderated` | Request → approval |
| **Invite Only** | `invite_only` | Admin-managed |

Group-level permissions for each model are configured at `/admin/group/types/manage/community_group/permissions`.

## Step 230 — Build Group Directory View

> ✅ **Already done in Phase 1 Step 160.** `views.view.all_groups.yml` was imported at `/all-groups`. No action needed here.

## Step 240 — Enable `do_group_extras` Module

Archive enforcement, submission guidelines, and moderation defaults for groups.

**Source files** (in repo at `docs/groups/modules/do_group_extras/`, deployed to `web/modules/custom/do_group_extras/`):
- `do_group_extras.info.yml`
- `do_group_extras.services.yml` — registers `DoGroupExtrasHooks` service
- `src/Hook/DoGroupExtrasHooks.php` — all hook implementations as `#[Hook]` attributed methods
- `do_group_extras.module` — empty (just `@file` docblock)
- `do_group_extras.libraries.yml`
- `css/do_group_extras.css`

> [!IMPORTANT]
> This module uses the **Drupal 11 `#[Hook]` attribute system** (OOP service-based hooks). All hook logic is in `src/Hook/DoGroupExtrasHooks.php`. The `.module` file is intentionally empty. This complies with BEST_PRACTICES.md.

### Enable

> [!CAUTION]
> **Order of operations matters.** Enable the module and export BEFORE adding any View YAMLs. If you add View YAML to `config/sync/` and run `config:import` before exporting the module's extension entry, the import will **uninstall the module**.

```bash
# Step 1: Copy module from docs to web
cp -r docs/groups/modules/do_group_extras web/modules/custom/
# Step 2: Enable module
ddev drush en do_group_extras -y
# Step 3: Export to capture module in core.extension.yml
ddev drush config:export -y
# Step 4: Clear cache
ddev drush cr
```

Hook implementations (all in `DoGroupExtrasHooks`):
- `formAlter` — submission guidelines on group create/edit forms
- `entityPresave` — non-admin groups default to unpublished
- `entityInsert` — queues pending notification for `site_moderator`
- `preprocessGroup` — "Archived" badge on archived groups
- `nodeAccess` — denies content creation in archived groups

## Step 250 — Create Pending Groups View

```bash
cp docs/groups/config/views.view.pending_groups.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: navigate to `/admin/groups/pending` — should show an empty table (requires `administer group` permission).

## Step 260 — Evaluate `do_wiki` Module

> **Decision needed**: Should `[[Title]]` wiki-link syntax be supported in pl-drupalorg?

The `do_wiki` module provides a text filter that converts `[[Title]]` patterns into clickable links to nodes with matching titles. It is a single-class module:

- `src/Plugin/Filter/WikiLinkFilter.php` — extends `FilterBase`, implements `process()` to find `[[...]]` patterns and replace with `<a>` links
- No dependencies beyond `drupal:filter`

> [!NOTE]
> **Adaptation difficulty**: 🟢 Low. The filter is entirely Drupal-generic. Only the `.info.yml` metadata needs updating.

If needed:
```bash
mkdir -p web/modules/custom/do_wiki/src/Plugin/Filter
```

> [!WARNING]
> `do_wiki` is **not** in `docs/groups/modules/` — it was not ported as part of this project's runbook. If needed, source it from the `pl-opensocial` repo or write it from scratch using the description above. No code changes are needed from the Open Social version beyond updating the `.info.yml` metadata.

```bash
ddev drush en do_wiki -y
ddev restart  # Required to flush PHP opcode cache
ddev drush cr
```

> [!CAUTION]
> After enabling any new module with plugin classes, run `ddev restart` (not just `ddev drush cr`) to flush the PHP opcode cache so the web process can discover the new class.

Then add the WikiLink filter to `full_html` text format at `/admin/config/content/formats/manage/full_html`.

## Step 270 — Phase 2 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_group_extras`

Create `web/modules/custom/do_group_extras/tests/src/Kernel/GroupExtrasTest.php`:

- `testModuleEnabled()` — `do_group_extras` module is enabled
- `testArchiveHookAccess()` — archived group denies node creation
- `testGuidelinesFormAlter()` — form alter hook fires on group create form

```bash
ddev exec phpunit web/modules/custom/do_group_extras/tests/src/Kernel/GroupExtrasTest.php
```

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase2Test.php`:

- `testGroupTypeVocabulary()` — `group_type` vocabulary exists with expected terms
- `testFieldGroupType()` — `field_group_type` exists on `community_group`
- `testPendingGroupsView()` — `pending_groups` View config exists

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase2Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase2.spec.ts`:

- `test('group directory displays groups')` — navigate to `/groups`, verify group cards with type badges
- `test('group type filter works')` — filter by group type, verify results narrow
- `test('pending group visible to site_moderator')` — create unpublished group, verify pending view
- `test('archived group is read-only')` — archive a group, verify content creation blocked
- `test('do_wiki WikiLink filter')` — create node with `[[wiki link]]`, verify renders as link

```bash
npx playwright test tests/e2e/phase2.spec.ts
```

## Phase 2 — Verification

- [ ] `group_type` vocabulary exists at `/admin/structure/taxonomy/manage/group_type/overview`
- [ ] 5 terms: Geographical, Working group, Distribution, Event planning, Archive
- [ ] `field_group_type` exists on `community_group`
- [ ] Group directory at `/all-groups` with exposed filters
- [ ] Archived groups hidden from default `/all-groups` listing
- [ ] `do_group_extras` enabled: `drush pm:list --filter=do_group_extras`
- [ ] Submission guidelines on group creation form
- [ ] Non-admin group creation defaults to unpublished
- [ ] Notification queued on pending group submission (email delivery handled by external system)
- [ ] Pending groups at `/admin/groups/pending`
- [ ] Archive enforcement blocks content creation
- [ ] "Archived" badge on archived groups
- [ ] Membership model tests pass (open/moderated/invite-only)
- [ ] `do_wiki` evaluated; enabled if wiki-link `[[Title]]` syntax is needed
- [ ] If enabled: WikiLink filter added to `full_html` text format
- [ ] Config exported clean; `ddev drush cr` — no errors; Nightwatch passes

### Schema Changes (Phase 2)

| Type | Name | Notes |
|---|---|---|
| **Taxonomy** | `group_type` | 5 terms (Geographical, Working group, Distribution, Event planning, Archive) |
| **Field (group)** | `field_group_type` | Entity reference → `group_type` vocabulary |
| **View** | `all_groups` at `/all-groups` | Directory with exposed filters |
| **View** | `pending_groups` at `/admin/groups/pending` | Moderation queue |
| **Module** | `do_group_extras` | Archive, guidelines, moderation queue |
| **Module** | `do_wiki` | Optional — `[[Title]]` wiki links |

### Open Questions (Phase 2)

1. Does `drupal/group` 4.x natively support request/approval, or require `group_membership_request` sub-module? ⏳ **Deferred** — will research the installed Group 4.x code when we reach this step. Note the **two-step membership wizard was removed** in 4.0.0-alpha1 (CR 2026-04-24). <!-- VERIFY on build: the removed two-step-wizard route name, if any module references it. -->
2. What is the exact `form_id` for `community_group` create/edit form? ⏳ **Deferred** — inspect at implementation time
3. ~~Notification role — `site_moderator`, `content_moderator`, or `content_administrator`?~~ ✅ Confirmed: `site_moderator` (maps to OS `sitemanager`)
4. ~~Archived groups — unpublish or keep published but read-only?~~ ✅ Confirmed: **Both**. Archived = published + read-only (visible with badge, no new content). Unpublished = completely hidden (Group entity `status` field). Two separate mechanisms.

---

# Phase 3 — Content in Groups

**Goal**: Enable posting content to groups, build group streams, port multi-group posting, enable tags.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 5

## Pre-Phase 3 Snapshot

```bash
ddev export-db --file=backups/pre-phase3-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 300 — Configure Relationship Cardinality for Multi-Group Posting

> ✅ **Already done in Phase 1.** All 5 relationship type YAMLs were written with `entity_cardinality: 0` from the start. No script needed.

Verify:
```bash
ddev drush php:eval 'echo \Drupal::entityTypeManager()->getStorage("group_relationship_type")->load("community_group-group_node-forum")->getPlugin()->getConfiguration()["entity_cardinality"] . "\n";'
```
Expected: `0`

## Step 310 — Build Group Stream Views

All three view YAMLs exist in `docs/groups/config/`. Batch-import:

```bash
cp docs/groups/config/views.view.group_content_stream.yml config/sync/
cp docs/groups/config/views.view.group_events.yml config/sync/
cp docs/groups/config/views.view.group_members.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

| View | Machine name | URL |
|---|---|---|
| Group Content Stream | `group_content_stream` | `/group/{gid}/stream` |
| Group Events | `group_events` | `/group/{gid}/events` |
| Group Members | `group_members` | `/group/{gid}/members` |

**Sort**: Created, newest first. **Settings**: `distinct: true`, Use AJAX = Yes.

### Group Events View

| Setting | Value |
|---|---|
| **Machine name** | `group_events` |
| **Show** | Content — type: Event |
| **Format** | Table |

Fields: Title (linked), Event date, Location. Same relationship and contextual filter.

### Group Members View

| Setting | Value |
|---|---|
| **Machine name** | `group_members` |
| **Show** | Group relationship (membership type) |
| **Format** | Grid / table |

Fields: Username (linked), Avatar, Group role, Joined date.

```bash
ddev drush config:export -y
```

## Step 320 — Sitewide Activity Stream (Post Wall)

### 320a — Create `stream_card` view mode

```bash
cp docs/groups/config/core.entity_view_mode.node.stream_card.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

> [!CAUTION]
> **Must import `stream_card` view mode BEFORE importing `activity_stream` view.** The view depends on the view mode existing.

### 320b — (Intentional gap)

### 320c — Create the Activity Stream View

> [!IMPORTANT]
> Enable `comment` module BEFORE importing this view — the view has a comment join.
>
> ```bash
> ddev drush en comment -y
> ddev drush config:export -y
> ```

```bash
cp docs/groups/config/views.view.activity_stream.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

| Setting | Value |
|---|---|
| **View name** | Activity Stream |
| **Machine name** | `activity_stream` |
| **Show** | Content (nodes) |
| **Page path** | `/stream` |
| **Format** | Unformatted list |
| **Row** | Content \| `stream_card` view mode |

**Sort**: Last comment timestamp DESC *(falls back to node created if no comments)*. Uses the `comment_entity_statistics` table which core creates automatically for any content type with a comment field.

**Filters**:
- Published = Yes
- Content type IN (`forum`, `documentation`, `event`, `post`, `page`)

**Settings**: Distinct = true, Use AJAX = Yes, Pager = Full (10 items per page).

**Config**: [views.view.activity_stream.yml](config/views.view.activity_stream.yml) — page at `/stream` (10 items) + block display (5 items)

```bash
cp docs/groups/config/views.view.activity_stream.yml config/sync/
ddev drush cim -y
```

> [!TIP]
> To make the activity stream the site's **front page**:
> ```bash
> ddev drush config:set system.site page.front /stream -y
> ```
> Alternatively, keep the existing front page and place the stream View as a block.

### 320d — Add "Group badge" to stream cards

Each stream card should show which group(s) the content belongs to. Create a Views field or custom preprocess function that queries `group_relationship` for each node and renders the group name(s) as linked badges.

**Option 1: Views relationship** — Add a Group relationship to the `activity_stream` View and include the Group label field. This shows the group name for each node but may cause duplicate rows if a node is in multiple groups. Use `distinct: true` and `GROUP_CONCAT` or a custom Views field plugin.

**Option 2: Preprocess** — In `do_group_extras.module`, add:

```php
/**
 * Implements hook_preprocess_node().
 */
function do_group_extras_preprocess_node(&$variables) {
  if ($variables['view_mode'] !== 'stream_card') {
    return;
  }
  $node = $variables['node'];
  $relationships = \Drupal::entityTypeManager()
    ->getStorage('group_relationship')
    ->loadByProperties(['entity_id' => $node->id()]);

  $group_links = [];
  foreach ($relationships as $rel) {
    $group = $rel->getGroup();
    if ($group) {
      $group_links[] = [
        'label' => $group->label(),
        'url' => $group->toUrl()->toString(),
      ];
    }
  }
  $variables['group_badges'] = $group_links;
}
```

Then in `templates/node--stream-card.html.twig` (theme template):
```twig
{% if group_badges %}
  <div class="stream-card__groups">
    {% for badge in group_badges %}
      <a href="{{ badge.url }}" class="stream-card__group-badge">{{ badge.label }}</a>
    {% endfor %}
  </div>
{% endif %}
```

### 320e — Verify the activity stream

```bash
# Verify view mode exists:
ddev drush php:eval '
$vm = \Drupal::entityTypeManager()->getStorage("entity_view_mode")->load("node.stream_card");
echo "stream_card view mode: " . ($vm ? "OK" : "MISSING") . "\n";
'

# Verify /stream page responds (after demo data exists):
# ddev drush php:eval '
# echo \Drupal::httpClient()->get("https://drupalorg.ddev.site/stream")->getStatusCode() . "\n";
# '
```

> [!CAUTION]
> **Key differences from Open Social's stream**:
> - **No Activity entities** — we show nodes directly, not Activity wrappers
> - **No "post composer"** — OS has an inline quick-post widget at the top of the stream; add this in Phase 4 if needed
> - **No activity grouping** — OS groups related activities ("Maria posted 3 topics"); our stream shows each node individually
> - **Comment rendering** — OS uses a custom AJAX comment loader; we use core's comment display with a per-view-mode override for count limiting

```bash
ddev drush config:export -y
```

## Step 330 — Enable `do_multigroup` Module

Allows content to be posted in multiple groups simultaneously.

> [!IMPORTANT]
> Adaptation difficulty: 🔴 High (moved to ✅ Done). Deeply uses `group_relationship` API; `group.membership_loader` service **does not exist** in Group 3.x — replaced with a direct `group_relationship` entity storage query.

**Source files** (in repo at `docs/groups/modules/do_multigroup/`, deployed to `web/modules/custom/do_multigroup/`):
- `do_multigroup.info.yml`
- `do_multigroup.services.yml` — registers `DoMultigroupHooks` service
- `src/Hook/DoMultigroupHooks.php` — all hook implementations as `#[Hook]` attributed methods
- `do_multigroup.module` — empty (`@file` docblock only)
- `do_multigroup.libraries.yml`
- `css/do_multigroup.css`

> [!IMPORTANT]
> **Group 3.x migration note**: `group.membership_loader` service was removed in Group 3.x (and stays removed in 4.x). User memberships are now loaded by querying `group_relationship` storage directly:
> ```php
> // Load memberships for current user:
> $memberships = $this->entityTypeManager
>   ->getStorage('group_relationship')
>   ->loadByProperties([
>     'entity_id' => $account->id(),
>     'type' => 'community_group-group_membership',
>   ]);
> ```

> [!CAUTION]
> **Group 4.x deltas for `do_multigroup`** (see [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md) §2–§3). This module is the most likely to touch permission calculation and relation types, so on the 4.x port confirm:
> - **Permission calculation** — if `do_multigroup` (or any custom module) registers a `permission_calculator` service (Flexible Permissions), re-implement it as an **`access_policy`**-tagged service extending `Drupal\Core\Session\AccessPolicyBase`, and repoint any `use Drupal\flexible_permissions\...` imports to the core equivalents under `Drupal\Core\Session\`. Multi-group permission aggregation must still resolve under the **immutable** `CalculatedPermissions`.
> - **`content_plugin` → `relation_type`** — any code that reads `$group_relationship_type->content_plugin` (or the YAML key) when filtering by relation type must use `relation_type`.
> - **`$roles` filter must be an array** — any membership-loading helper that passed a single role ID as a string must pass `['role_id']` (CR 2025-02-19).
> - **"Add to group" no longer resaves the entity** — it invalidates cache tags instead (CR 2025-05-23); do not rely on a full entity `save()` (and its `hook_entity_update` side effects) firing when a relationship is added.
> <!-- VERIFY on build: whether do_multigroup ships a permission_calculator service to re-home, and the exact core FQCNs / scope constants used. -->

> [!IMPORTANT]
> This module uses the **Drupal 11 `#[Hook]` attribute system**. All hook logic is in `src/Hook/DoMultigroupHooks.php`. The `.module` file is intentionally empty.

### Enable

```bash
# Step 1: Copy module from docs to web
cp -r docs/groups/modules/do_multigroup web/modules/custom/
# Step 2: Enable module
ddev drush en do_multigroup -y
# Step 3: Export config
ddev drush config:export -y
# Step 4: Clear cache
ddev drush cr
```

## Step 340 — Enable Tags on Group Content

> ✅ **Already done in Phase 1.** `taxonomy.vocabulary.group_tags` and `field_group_tags` on all 5 content types were imported via YAML in Steps 170–180.

Verify:
```bash
ddev drush php:eval '\Drupal\field\Entity\FieldStorageConfig::loadByName("node", "field_group_tags") ? print "OK\n" : print "MISSING\n";'
```

## Step 350 — Tags Aggregation View

```bash
cp docs/groups/config/views.view.tags_aggregation.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: `/tags/{term-name}` returns content tagged with that term.

## Step 360 — Phase 3 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_multigroup`

Create `web/modules/custom/do_multigroup/tests/src/Kernel/MultigroupTest.php`:

- `testModuleEnabled()` — `do_multigroup` module enabled
- `testGroupAudienceFormAlter()` — form alter adds group audience fieldset
- `testCrossPostBadge()` — `hook_preprocess_node()` adds cross-posted badge to multi-group content

```bash
ddev exec phpunit web/modules/custom/do_multigroup/tests/src/Kernel/MultigroupTest.php
```

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase3Test.php`:

- `testMultiGroupCardinality()` — relationship types have `entity_cardinality: 0`
- `testGroupTagsVocabulary()` — `group_tags` vocabulary exists
- `testFieldGroupTags()` — `field_group_tags` exists on all 5 content types
- `testGroupContentStreamView()` — `group_content_stream` View config exists
- `testStreamCardViewMode()` — `node.stream_card` view mode exists
- `testStreamCardDisplayEnabled()` — `stream_card` display enabled for all 5 group-postable types
- `testActivityStreamView()` — `activity_stream` View config exists with path `/stream`

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase3Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase3.spec.ts`:

- `test('content appears in group stream')` — create content in group, verify in group stream
- `test('multi-group posting')` — post to Group A and B, verify in both streams
- `test('cross-posted badge')` — multi-group content shows badge in teaser
- `test('group audience fieldset')` — as group member, verify fieldset on node create form
- `test('tag aggregation page')` — tagged content at `/tags/{term-name}`
- `test('activity stream page loads')` — navigate to `/stream`, verify HTTP 200 and page title
- `test('stream shows recent content')` — create a node in a group, navigate to `/stream`, verify node title appears
- `test('stream shows group badge')` — verify group name badge appears on stream cards
- `test('stream shows inline comments')` — post with comments shows comment text in the stream card
- `test('stream pagination')` — verify AJAX pager loads next page of content

```bash
npx playwright test tests/e2e/phase3.spec.ts
```

## Phase 3 — Verification

- [ ] Relationship types configured for: forum, documentation, event, post, page
- [ ] Each relationship type has `entity_cardinality: 0`
- [ ] Content can be created within group context
- [ ] Group content stream displays posted content
- [ ] Group events and members Views work
- [ ] `distinct: true` prevents duplicates
- [ ] `do_multigroup` enabled: `drush pm:list --filter=do_multigroup`
- [ ] "Group Audience" fieldset appears when user is a group member
- [ ] Fieldset shows only user's groups
- [ ] Content posted to Groups A & B appears in both streams (once each)
- [ ] Full view shows "Posted in: Group A, Group B" with links
- [ ] Teaser shows "Cross-posted from Group X" badge
- [ ] `group_tags` vocabulary exists
- [ ] `field_group_tags` on all 5 content types
- [ ] Tags aggregation page at `/tags/{term-name}` works
- [ ] **Activity Stream** (Step 320):
  - [ ] `stream_card` view mode exists
  - [ ] `stream_card` display enabled for `forum`, `documentation`, `event`, `post`, `page`
  - [ ] `activity_stream` View exists at `/stream`
  - [ ] `/stream` renders content sorted by last activity (newest first)
  - [ ] Stream cards show trimmed body, author, date, tags
  - [ ] Stream cards show group badge(s) linking to source group(s)
  - [ ] Stream cards show last 2-3 inline comments
  - [ ] AJAX pagination works on stream page
- [ ] Config exported clean; `ddev drush cr` — no errors; Nightwatch passes

### Schema Changes (Phase 3)

| Type | Name | Notes |
|---|---|---|
| **Config change** | `group_relationship_type.*` | Set `entity_cardinality: 0` (unlimited groups per node) |
| **Vocabulary** | `group_tags` | Group-scoped tags (separate from sitewide `tags`) |
| **Field (node)** | `field_group_tags` on 5 content types | Entity reference → `group_tags` vocabulary |
| **View** | `group_content_stream` | Group-scoped content stream |
| **View** | `group_events` | Group-scoped events |
| **View** | `group_members` | Group membership listing |
| **View** | `tags_aggregation` at `/tags` | Sitewide tag cloud/list |
| **View mode** | `node.stream_card` | Stream card display for activity feed |
| **View** | `activity_stream` at `/stream` | Sitewide activity stream (post wall) |
| **Module** | `do_multigroup` | Multi-group posting UI + submit handler |

### Custom module files (Phase 3)

```
docs/groups/modules/do_multigroup/   ← source of truth
├── src/Hook/DoMultigroupHooks.php   ← all #[Hook] logic
├── do_multigroup.services.yml
├── do_multigroup.info.yml
├── do_multigroup.libraries.yml
├── do_multigroup.module             ← empty (@file docblock)
└── css/do_multigroup.css

web/modules/custom/do_multigroup/    ← deployed copy (synced via cp -r)
```

### Open Questions (Phase 3)

1. ~~Does Group use `group_relationship_type.{group_type}-group_node-{bundle}.yml` format?~~ ✅ Confirmed: yes, exact format. In **4.x** the relation plugin ID is stored under the `relation_type:` key (renamed from `content_plugin:`, CR 2026-06-19).
2. ~~Should all 5 content types support multi-group posting, or only a subset?~~ ✅ Confirmed: all 5 (`forum`, `documentation`, `event`, `post`, `page`)
3. ~~Do any content types already have a tags field?~~ ✅ Resolved: `documentation` has `field_tags` → sitewide `tags`. Groups use a **separate** `group_tags` vocabulary with `field_group_tags`.
4. ~~Does Group still provide `group.membership_loader` service?~~ ✅ **No.** Removed in Group 3.x (still absent in 4.x). Use `group_relationship` storage query: `loadByProperties(['entity_id' => $uid, 'type' => 'community_group-group_membership'])` instead. Note: in 4.x any `$roles` filter passed to membership-loading helpers must be an **array**, not a bare string (CR 2025-02-19).

---

# Phase 4 — Discovery & Feeds

**Goal**: Document hot content scoring, promoted content, RSS feeds, and iCal feeds.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 4

> [!IMPORTANT]
> Adaptation difficulty: 🟢 Low for the core scoring module. 🟡 Medium for the iCal controller (Open Social-specific event fields and enrollment entity must be adapted).

## Pre-Phase 4 Snapshot

```bash
ddev export-db --file=backups/pre-phase4-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 400 — Statistics Module

> ⚠️ **Removed from Drupal 11 core.** The `statistics` module was deprecated in Drupal 10.3 and removed from core in 11.0.
>
> `do_discovery` checks for `node_counter` table gracefully (using `tableExists()`) and falls back to `view_count = 0` if the table is missing. For now, view counting is deactivated and only comment counts drive hot scores.
>
> If view counting is needed, install the ––––– contrib module:
> ```bash
> ddev composer require drupal/statistics
> ddev drush en statistics -y
> ```

Note: `drupal:statistics` was removed from the `do_discovery.info.yml` hard-dependency list so the module can be enabled on Drupal 11 without the statistics contrib module.

## Step 410 — Install Flag Module

> [!CAUTION]
> **Pre-flight**: If `composer.json` has `drush/drush: ^12` but the lock file has `13.x`, Composer will refuse to install new packages. Fix the constraint first:
> ```bash
> sed -i '' 's/"drush\/drush": "\^12"/"drush\/drush": "^13"/' composer.json
> ```

```bash
ddev composer require "drupal/flag:^4.0" --no-interaction
ddev drush en flag flag_count -y
ddev drush config:export -y
```

Version installed: `drupal/flag` 4.0.0-beta7 (compatible with Drupal 11).

Verify:
```bash
ddev drush pm:list --filter=flag --status=enabled
```

## Step 420 — Enable `do_discovery` Module

Hot content scoring, iCal feeds, promoted content, and per-group RSS.

**Source files** (in repo at `docs/groups/modules/do_discovery/`, deployed to `web/modules/custom/do_discovery/`):
- `do_discovery.info.yml` — `core_version_requirement: ^10 || ^11`; depends on `drupal:views`, `drupal:node`, `drupal:taxonomy`, `drupal:flag` (NOT `drupal:statistics` — removed, handled gracefully)
- `do_discovery.install` — `hook_schema()` creates `do_discovery_hot_score` table
- `do_discovery.services.yml` — registers `DoDiscoveryHooks` service
- `src/Hook/DoDiscoveryHooks.php` — `hook_cron`, `hook_views_data`, `hook_node_insert` via `#[Hook]`
- `do_discovery.module` — empty (`@file` docblock)
- `do_discovery.routing.yml` — 3 iCal routes
- `src/Controller/IcalController.php` — 3 iCal endpoints
- `config/install/flag.flag.promote_homepage.yml` — bundled flag (auto-imports on enable)

> [!IMPORTANT]
> **Enable `comment` module BEFORE enabling `do_discovery`.** The `activity_stream` view (already in config/sync) has a comment join; if comment is not enabled, `config:import` will fail. Enable in this sequence:
> ```bash
> ddev drush en comment -y && ddev drush config:export -y
> ```

> [!IMPORTANT]
> This module uses the **Drupal 11 `#[Hook]` attribute system**. All hook logic is in `src/Hook/DoDiscoveryHooks.php`.

### Enable

```bash
# Step 1: Copy module from docs to web
cp -r docs/groups/modules/do_discovery web/modules/custom/
# Step 2: Enable module (also auto-installs flag.flag.promote_homepage)
ddev drush en do_discovery -y
# Step 3: Export config (captures module + promote_homepage flag)
ddev drush config:export -y
# Step 4: Verify hot_score table was created
ddev drush sqlq "SHOW TABLES LIKE 'do_discovery%'"
```
Expected: `do_discovery_hot_score`

## Step 430 — Create RSVP Flag (for iCal user events)

`rsvp_event` flag YAML exists in `docs/groups/config/`. Import it alongside its system actions after enabling `do_discovery`:

```bash
cp docs/groups/config/flag.flag.rsvp_event.yml config/sync/
cp docs/groups/config/system.action.flag_action.rsvp_event_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.rsvp_event_unflag.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

**Flag spec**:

| Setting | Value |
|---|---|
| Label | RSVP for event |
| Machine name | `rsvp_event` |
| Flag type | Content (Node) |
| Bundles | Event only |
| Global | No (per-user) |

Grant permissions:
```bash
ddev drush role:perm:add authenticated "flag rsvp_event,unflag rsvp_event"
ddev drush config:export -y
```

## Step 440 — Grant Flag Permissions

Promote homepage flag permissions were auto-imported with `do_discovery` (bundled config). Grant them to the appropriate roles:

```bash
ddev drush role:perm:add content_administrator "flag promote_homepage,unflag promote_homepage"
ddev drush role:perm:add site_moderator "flag promote_homepage,unflag promote_homepage"
ddev drush config:export -y
```

## Step 450 — Hot Content View

```bash
cp docs/groups/config/views.view.hot_content.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: navigate to `/hot`.

### Relationship

Add a relationship to `do_discovery_hot_score` (exposed by `hook_views_data()`):
- **Table**: Hot Content
- **Field**: Node ID → Scored Content relationship
- **Required**: No (nodes without scores should still appear)

### Fields

| Field | Configuration |
|---|---|
| Title | Linked to node |
| Content type | Machine name label |
| Hot Score | Numeric, from relationship |
| Created date | Medium format |
| Comment count | From `comment_entity_statistics` |

### Sort Criteria

- **Hot Score**: Descending (primary sort)
- **Created date**: Descending (secondary, for nodes with equal scores)

### Filter Criteria (non-exposed)

| Filter | Value |
|---|---|
| Published | Yes |
| Created date | Last 7 days (rolling window) |

### Exposed Filters

| Filter | Type | Notes |
|---|---|---|
| **Content type** | Select list | Options: forum, documentation, event, post, page |
| **"In my groups"** | Boolean | Requires a `group_relationship` join — only show content from groups the current user is a member of |

> [!NOTE]
> The "In my groups" exposed filter requires a Views relationship to `group_relationship_field_data` and a contextual filter comparing `gid` to the current user's group memberships. This is complex in Views UI — consider implementing it as a custom Views filter plugin in a future phase.

```bash
ddev drush config:export -y
```

## Step 460 — Promoted Content View

```bash
cp docs/groups/config/views.view.promoted_content.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: `/admin/content/promoted` (page) and front page block.

## Step 470 — Group RSS Feed

```bash
cp docs/groups/config/views.view.group_rss_feed.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

Verify: `/group/{gid}/stream/feed` returns valid RSS XML.

## Step 480 — iCal Feed Verification

After enabling `do_discovery`, verify the iCal endpoints:

### Site-wide iCal
```bash
ddev drush php:eval '
echo \Drupal::httpClient()
  ->get("https://drupalorg.ddev.site/upcoming-events/ical", ["verify" => FALSE])
  ->getStatusCode() . "\n";
'
```
Expected: `200` with `Content-Type: text/calendar`

### Group iCal
```bash
# Replace {gid} with a test group ID:
curl -k https://drupalorg.ddev.site/group/{gid}/events/ical
```
Expected: `BEGIN:VCALENDAR` ... `END:VCALENDAR`

### User iCal
```bash
# Replace {uid} with a test user ID:
curl -k https://drupalorg.ddev.site/user/{uid}/events/ical
```

## Step 490 — Phase 4 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_discovery`

Create `web/modules/custom/do_discovery/tests/src/Kernel/DiscoveryTest.php`:

- `testModuleEnabled()` — `do_discovery` module enabled; `do_discovery_hot_score` table exists
- `testHotScoreCalculation()` — create node with views/comments, run cron, verify hot score > 0
- `testICalRoute()` — `/group/{gid}/events/ical` route is registered
- `testICalController()` — controller returns valid iCal response for group events


### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase4Test.php`:

- `testFlagsExist()` — `rsvp_event`, `promote_homepage` flags exist (note: RUNBOOK previously used `promote_to_homepage` — correct machine name is `promote_homepage`)
- `testHotContentView()` — `hot_content` View config exists with expected filters
- ~~`testStatisticsEnabled()`~~ — **Skip** — statistics module not available for Drupal 11

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase4Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase4.spec.ts`:

- `test('hot content page loads')` — navigate to `/hot`, verify content sorted by hot score
- `test('promoted content page loads')` — navigate to `/promoted`, verify promoted content
- `test('event RSVP toggle')` — flag/unflag event, verify toggle
- `test('RSS feed valid XML')` — fetch `/group/{gid}/rss.xml`, verify valid RSS
- `test('iCal feed valid')` — fetch `/group/{gid}/events/ical`, verify `BEGIN:VCALENDAR`
- `test('statistics tracks views')` — visit node, verify view count increments

```bash
npx playwright test tests/e2e/phase4.spec.ts
```

## Phase 4 — Verification

- [ ] Statistics module enabled: `drush pm:list --filter=statistics`
- [ ] Flag + flag_count modules enabled: `drush pm:list --filter=flag`
- [ ] `do_discovery` enabled: `drush pm:list --filter=do_discovery`
- [ ] `do_discovery_hot_score` table exists: `ddev drush sqlq "SHOW TABLES LIKE 'do_discovery%'"`
- [ ] `promote_homepage` flag exists at `/admin/structure/flags`
- [ ] `rsvp_event` flag exists (if implementing event RSVP via Flag)
- [ ] Flag permissions granted to `content_administrator` and `site_moderator`
- [ ] `/hot` page loads with content sorted by score
- [ ] Hot content View has exposed filters for content type
- [ ] Promoted content block on front page works
- [ ] `/admin/content/promoted` lists flagged content
- [ ] iCal endpoint `/upcoming-events/ical` returns `text/calendar`
- [ ] iCal endpoint `/group/{gid}/events/ical` returns group-scoped events
- [ ] iCal endpoint `/user/{uid}/events/ical` returns user's RSVP'd events
- [ ] Group RSS feed at `/group/{gid}/stream/feed` returns valid RSS XML
- [ ] Config exported clean: `ddev drush config:status`
- [ ] `ddev drush cr` — no PHP errors
- [ ] Existing Nightwatch tests still pass: `composer nightwatch`

### Schema Changes (Phase 4)

| Type | Name | Notes |
|---|---|---|
| **DB table** | `do_discovery_hot_score` | `nid` INT PK, `score` FLOAT, `computed` INT |
| **DB table** | `node_counter` | Page view counts (via `statistics` module) |
| **Flag** | `promote_homepage` | Global, entity:node |
| **Flag** | `rsvp_event` | Per-user, entity:node (event RSVP) |
| **View** | `hot_content` at `/hot` | Score-sorted content with exposed filters |
| **View** | `promoted_content` at `/admin/content/promoted` | Flagged content management |
| **View** | `group_rss_feed` | Per-group RSS at `/group/{gid}/rss.xml` |
| **Route** | `/user/{user}/events/ical` | User's RSVP'd events iCal feed |
| **Route** | `/group/{group}/events/ical` | Group events iCal feed |
| **Route** | `/upcoming-events/ical` | All events iCal feed |
| **Module** | `statistics` | Core (10.2) or contrib (10.3+) |
| **Module** | `flag` + `flag_count` | Flagging API + counts sub-module |
| **Module** | `do_discovery` | Hot scores, iCal, Views data |

### Custom module files (Phase 4)

```
docs/groups/modules/do_discovery/   ← source of truth
├── config/install/
│   └── flag.flag.promote_homepage.yml  ← auto-imported on module enable
├── src/
│   ├── Controller/
│   │   └── IcalController.php         ← 3 iCal endpoints (site/group/user)
│   └── Hook/
│       └── DoDiscoveryHooks.php       ← #[Hook] cron, views_data, node_insert
├── do_discovery.info.yml
├── do_discovery.install            ← hook_schema (hot_score table)
├── do_discovery.module             ← empty (@file docblock)
├── do_discovery.routing.yml        ← 3 iCal routes
└── do_discovery.services.yml       ← registers DoDiscoveryHooks service

web/modules/custom/do_discovery/    ← deployed copy (synced via cp -r)
```

> [!NOTE]
> **DI autowire gotcha (Drupal 11)**: Do NOT use `Psr\Log\LoggerInterface` or `Drupal\Core\Logger\LoggerChannelInterface` as constructor argument type hints in `hook_implementations`-tagged services — Drupal's `DefinitionErrorExceptionPass` validates the type hint even when `autowire: false` is set. Use `\Drupal::logger()` statically in private helper methods instead.

### Key Adaptations from Open Social (Phase 4)

| Aspect | Open Social (pl-opensocial) | Standard Drupal (pl-drupalorg) |
|---|---|---|
| **Event date field** | `field_event_date` | `field_date_of_event` |
| **Event end date** | `field_event_date_end` | `field_date_of_event` end_value (daterange) |
| **Event enrollment** | `event_enrollment` entity | Flag: `rsvp_event` (per-user) |
| **Promote bundles** | `topic`, `event`, `page` | `forum`, `documentation`, `event`, `post`, `page` |
| **Flag roles** | `contentmanager`, `sitemanager` | `content_administrator`, `site_moderator` |
| **Group content table** | `group_content_field_data` | `group_relationship_field_data` |

### Open Questions (Phase 4)

1. ~~**Event date field type**~~: ✅ Resolved — keep existing `field_date_of_event` (`datetime`).
2. ~~**Event RSVP mechanism**~~: ✅ Resolved — Flag module (`rsvp_event` flag). Simple, already installed.
3. ~~**Hot content "In my groups" filter**~~: ✅ Confirmed — implement as a custom Views filter plugin (Phase 6 scope).
4. ~~**Statistics module deprecation**~~: ✅ Resolved — removed as hard dep. `do_discovery` handles missing `node_counter` table gracefully with a `tableExists()` guard.

---

# Phase 5 — Notifications & Subscriptions

**Goal**: Document follow/subscription infrastructure, per-post notification opt-out, subscription management page, and mute capabilities.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 6

> [!NOTE]
> **Drupal does NOT send email.** Drupal only records notification events ("what happened"). An external system reads the queue and handles all recipient resolution, suppression checks, frequency batching, and email delivery.

> [!IMPORTANT]
> Adaptation difficulty: 🟢 Low. The subscription management UI (controller, form) uses Flag service API which is portable. The notification event recording is a trivial queue insert — all complexity lives in the external system.

## Pre-Phase 5 Snapshot

```bash
ddev export-db --file=backups/pre-phase5-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 500 — Evaluate Existing Notification Infrastructure

Before implementing, check what pl-drupalorg already has:

```bash
# Check for message/notification modules:
ddev drush pm:list --filter=message --status=enabled
ddev drush pm:list --filter=rabbitmq --status=enabled
ddev drush pm:list --filter=symfony_mailer --status=enabled

# Check mail configuration:
ddev drush config:get system.mail interface

# Check if RabbitMQ is configured in DDEV:
ddev describe | grep -i rabbit
```

> [!NOTE]
> pl-drupalorg has **RabbitMQ** available as a DDEV service. **Email is never sent by Drupal.** Drupal only enqueues notification items (queue table or RabbitMQ). An external system consumes the queue and handles actual email delivery.

## Step 510 — Import Follow Flags

Flag (from Phase 4) is already installed. All flag YAMLs exist in `docs/groups/config/`. Batch-import:

```bash
cp docs/groups/config/flag.flag.follow_content.yml config/sync/
cp docs/groups/config/flag.flag.follow_user.yml config/sync/
cp docs/groups/config/flag.flag.follow_term.yml config/sync/
cp docs/groups/config/flag.flag.mute_group_notifications.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_content_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_content_unflag.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_user_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_user_unflag.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_term_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.follow_term_unflag.yml config/sync/
cp docs/groups/config/system.action.flag_action.mute_group_notifications_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.mute_group_notifications_unflag.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

> [!CAUTION]
> **`entity:profile` → `entity:user`**: `follow_user` uses `entity:user` (not `entity:profile`) because pl-drupalorg uses standard Drupal user entities, not the contributed `profile` module.

> [!CAUTION]
> **`show_in_links` view mode adaption**: `mute_group_notifications` uses `full: full, teaser: teaser` (not `hero: hero, teaser: teaser`) because the `hero` view mode doesn't exist in Olivero/Claro.

### 510e — Grant flag permissions

```bash
ddev drush role:perm:add authenticated "flag follow_content,unflag follow_content"
ddev drush role:perm:add authenticated "flag follow_user,unflag follow_user"
ddev drush role:perm:add authenticated "flag follow_term,unflag follow_term"
ddev drush role:perm:add authenticated "flag mute_group_notifications,unflag mute_group_notifications"
ddev drush config:export -y
```

Create `web/modules/custom/do_notifications/config/install/flag.flag.follow_content.yml`:

```yaml
langcode: en
status: true
dependencies:
  module:
    - node
id: follow_content
label: 'Follow content'
bundles: {}
entity_type: node
global: false
weight: 0
flag_short: 'Follow content'
flag_long: ''
flag_message: ''
unflag_short: 'Unfollow content'
unflag_long: ''
unflag_message: ''
unflag_denied_text: ''
flag_type: 'entity:node'
link_type: ajax_link
flagTypeConfig:
  show_in_links:
    full: full
  show_as_field: true
  show_on_form: false
  show_contextual_link: false
  extra_permissions: {}
  access_author: ''
linkTypeConfig: {}
```

No changes from the Open Social version — `entity:node` per-user flag with AJAX link.

### 510b — Follow User flag

Create `web/modules/custom/do_notifications/config/install/flag.flag.follow_user.yml`:

```yaml
langcode: en
status: true
dependencies:
  module:
    - user
id: follow_user
label: 'Follow user'
bundles: {}
entity_type: user
global: false
weight: 0
flag_short: Follow
flag_long: ''
flag_message: ''
unflag_short: Unfollow
unflag_long: ''
unflag_message: ''
unflag_denied_text: ''
flag_type: 'entity:user'
link_type: ajax_link
flagTypeConfig:
  show_in_links: {}
  show_as_field: true
  show_on_form: false
  show_contextual_link: false
  extra_permissions:
    owner: '0'
linkTypeConfig: {}
```

> [!CAUTION]
> **Key change from Open Social version**: The `entity_type` changes from `profile` to `user`, and the `flag_type` changes from `entity:profile` to `entity:user`. Open Social uses the contributed `profile` module for user profiles; pl-drupalorg uses standard Drupal user entities. The `social_follow_user` enforced dependency is also removed.

### 510c — Follow Term flag

Create `web/modules/custom/do_notifications/config/install/flag.flag.follow_term.yml`:

```yaml
langcode: en
status: true
dependencies:
  module:
    - taxonomy
id: follow_term
label: 'Follow term'
bundles: {}
entity_type: taxonomy_term
global: false
weight: 0
flag_short: Follow
flag_long: ''
flag_message: ''
unflag_short: Unfollow
unflag_long: ''
unflag_message: ''
unflag_denied_text: ''
flag_type: 'entity:taxonomy_term'
link_type: ajax_link
flagTypeConfig:
  show_in_links: {}
  show_as_field: false
  show_on_form: false
  show_contextual_link: false
  extra_permissions: {}
linkTypeConfig: {}
```

No changes — `entity:taxonomy_term` is standard.

### 510d — Mute Group Notifications flag

Create `web/modules/custom/do_notifications/config/install/flag.flag.mute_group_notifications.yml`:

```yaml
langcode: en
status: true
dependencies:
  module:
    - group
id: mute_group_notifications
label: 'Mute Group Notifications'
bundles: {}
entity_type: group
global: false
weight: 0
flag_short: 'Mute group'
flag_long: 'Muting the group notifications will result in their notifications not being sent to you anymore.'
flag_message: ''
unflag_short: 'Unmute group'
unflag_long: 'Receive the notifications of this group again.'
unflag_message: ''
unflag_denied_text: ''
flag_type: 'entity:group'
link_type: ajax_link
flagTypeConfig:
  show_in_links:
    full: full
    teaser: teaser
  show_as_field: true
  show_on_form: false
  show_contextual_link: false
  extra_permissions:
    owner: '0'
linkTypeConfig: {}
```

> [!NOTE]
> Changed `show_in_links` from `hero: hero, teaser: teaser` (Open Social view modes) to `full: full, teaser: teaser` (standard Drupal view modes). The `hero` view mode doesn't exist in bluecheese.

### 510e — Grant flag permissions

```bash
ddev drush role:perm:add authenticated "flag follow_content,unflag follow_content"
ddev drush role:perm:add authenticated "flag follow_user,unflag follow_user"
ddev drush role:perm:add authenticated "flag follow_term,unflag follow_term"
ddev drush role:perm:add authenticated "flag mute_group_notifications,unflag mute_group_notifications"
```

```bash
ddev drush config:export -y
```

## Step 520 — Enable `do_notifications` Module

Per-post opt-out, follow subscriptions, and subscription management page.

> [!IMPORTANT]
> **Enable `comment` module before enabling `do_notifications`.** The module's `commentInsert` hook depends on comment entities. (Comment was already enabled in Phase 4 for activity_stream view.)

**Source files** (in repo at `docs/groups/modules/do_notifications/`, deployed to `web/modules/custom/do_notifications/`):
- `do_notifications.info.yml` — `core_version_requirement: ^10 || ^11`; depends on `drupal:flag`, `drupal:node`, `drupal:user`
- `do_notifications.services.yml` — registers `DoNotificationsHooks` service
- `src/Hook/DoNotificationsHooks.php` — `hook_form_node_form_alter`, `hook_node_insert`, `hook_comment_insert` via `#[Hook]`
- `do_notifications.module` — empty (`@file` docblock)
- `do_notifications.routing.yml` — 3 routes (settings, cancel, admin defaults)
- `do_notifications.links.task.yml` — "Notifications" tab on user profiles
- `src/Controller/NotificationSettingsController.php` — subscription management page
- `src/Form/CancelAllSubscriptionsForm.php` — cancel-all confirmation form
- `src/Form/NotificationDefaultsForm.php` — admin defaults config form
- `config/optional/do_notifications.settings.yml` — default config (frequency, auto-subscribe)
- `config/optional/flag.flag.*.yml` — follow flags (skipped if already active)

> [!IMPORTANT]
> This module uses the **Drupal 11 `#[Hook]` attribute system**. All hook logic is in `src/Hook/DoNotificationsHooks.php`.

> [!CAUTION]
> **`config/optional/` vs `config/install/`**: Flag configs are in `config/optional/` (not `config/install/`). If the flags are already in active config (imported globally in Step 510), `config/install/` would throw a `PreExistingConfigException`. `config/optional/` silently skips them.

> [!CAUTION]
> **DI type-check gotcha**: `FlagServiceInterface` cannot be injected into `hook_implementations`-tagged services — Drupal's `DefinitionErrorExceptionPass` rejects interface types it can't alias. Use `\Drupal::service('flag')` statically in the method body. This is a **known pattern** documented in the DI autowire gotcha note in Phase 4.

### Enable

```bash
# Step 1: Copy module from docs to web
rm -rf web/modules/custom/do_notifications
cp -r docs/groups/modules/do_notifications web/modules/custom/
# Step 2: Enable module
ddev drush en do_notifications -y
# Step 3: Export config
ddev drush config:export -y
```

Verify:
```bash
ddev drush pm:list --filter=do_notifications --status=enabled
```

## Step 530 — Notification Event Recording Architecture

> [!NOTE]
> **Drupal only records what happened.** On entity insert/update, Drupal writes a lightweight event item to a queue. The external system reads the queue and handles everything else: recipient resolution (who follows what), suppression/mute checks, frequency batching, email rendering, and delivery.

### What Drupal records (one item per triggering event)

| Field | Value | Example |
|---|---|---|
| `event` | What happened | `node_created`, `comment_created`, `group_membership_added` |
| `entity_type` | The entity that triggered it | `node`, `comment` |
| `entity_id` | Entity ID | `42` |
| `bundle` | Entity bundle | `forum`, `event`, `documentation` |
| `author_uid` | Who triggered it | `5` |
| `group_ids` | Groups this entity belongs to | `[1, 3]` (from `group_relationship`) |
| `timestamp` | When it happened | `1742300000` |

### What the external system handles

- Reads `flagging` table to find followers (`follow_content`, `follow_user`, `follow_term`)
- Reads `do_notifications_suppress_{nid}` State API key for per-post opt-out
- Reads `do_notifications_disabled_{uid}` State API key for user-level disable
- Reads `mute_group_notifications` flaggings for group muting
- Reads `field_notification_frequency` on user entity for batching
- Renders email content and delivers

## Step 540 — Notification Settings (Two-Tier Design)

> [!NOTE]
> Notification settings use a **two-tier design**: a site-wide admin form sets defaults, and each user can override on their own notification settings page.

### 540a — Admin defaults form

The admin form and default config are included in the `do_notifications` module files (see Step 520):
- [NotificationDefaultsForm.php](modules/do_notifications/src/Form/NotificationDefaultsForm.php)
- [do_notifications.settings.yml](modules/do_notifications/config/install/do_notifications.settings.yml)

| Setting | Config key | Default |
|---|---|---|
| Default frequency | `do_notifications.settings.default_frequency` | `immediately` |
| Auto-subscribe on comment | `do_notifications.settings.auto_subscribe_comment` | `true` |

Access: `administer site configuration` permission.

### 540b — Per-user notification frequency field

YAML exists in `docs/groups/config/`. Import it alongside the flag configs in Step 510:

```bash
cp docs/groups/config/field.storage.user.field_notification_frequency.yml config/sync/
cp docs/groups/config/field.field.user.user.field_notification_frequency.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

| Setting | Value |
|---|---|
| **Field type** | List (text) |
| **Label** | Notification frequency |
| **Machine name** | `field_notification_frequency` |
| **Allowed values** | `immediately\|Immediately`, `daily\|Daily digest`, `weekly\|Weekly digest` |

Verify:
```bash
ddev drush php:eval '\Drupal\field\Entity\FieldStorageConfig::loadByName("user", "field_notification_frequency") ? print "OK\n" : print "MISSING\n";'
```

## Step 550 — Notification Event Recording

> [!IMPORTANT]
> All hook logic is in `DoNotificationsHooks` via `#[Hook]`. The `.module` file is intentionally empty. The hooks only record what happened — **no follower lookups, no suppression checks, no email.**

**Hooks implemented in `DoNotificationsHooks`**:
- `formNodeFormAlter` — adds "Do not send notifications" checkbox to group-postable node forms
- `nodeFormSubmit` (static) — stores suppression flag per-node in State API
- `nodeInsert` — queues `node_created` event for published group-postable content
- `commentInsert` — queues `comment_created` event; auto-subscribes commenter via `follow_content` flag

> [!CAUTION]
> **Group 4.x: "add to group" no longer resaves the related entity** (CR 2025-05-23). Adding an entity to a group now **invalidates the entity's cache tags** instead of calling `save()` on it. Any notification trigger that relied on a node's `hook_entity_update` firing *because it was added to a group* will **no longer fire** on that path. This runbook records the `node_created` event on `hook_node_insert` (unaffected), but if a future trigger keys off "node added to group," record it from a `group_relationship` insert hook (or the cache-tag path), **not** from a node resave. See [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md) §3.
> <!-- VERIFY on build: confirm do_notifications' triggers fire on the 4.x cache-tag path; none of the recorded events should depend on an add-to-group resave. -->

**Drupal only records what happened** (one queue item per event, not per recipient):

```php
/**
 * Implements hook_node_insert().
 *
 * Records a notification event. External system handles delivery.
 */
function do_notifications_node_insert(NodeInterface $node) {
  _do_notifications_record_event('node_created', $node);
}

/**
 * Records a notification event to the queue.
 *
 * One item per triggering event (not per recipient).
 * External system resolves recipients from flags/subscriptions.
 */
function _do_notifications_record_event(string $event, $entity): void {
  $item = [
    'event'       => $event,
    'entity_type' => $entity->getEntityTypeId(),
    'entity_id'   => $entity->id(),
    'bundle'      => $entity->bundle(),
    'author_uid'  => $entity->getOwnerId(),
    'group_ids'   => _do_notifications_get_group_ids($entity),
    'timestamp'   => \Drupal::time()->getRequestTime(),
  ];
  \Drupal::queue('do_notifications')->createItem($item);
}

/**
 * Returns group IDs this entity belongs to (via group_relationship).
 */
function _do_notifications_get_group_ids($entity): array {
  if ($entity->getEntityTypeId() !== 'node') {
    return [];
  }
  $relationships = \Drupal::entityTypeManager()
    ->getStorage('group_relationship')
    ->loadByProperties([
      'entity_id' => $entity->id(),
      // Step 140 abbreviated documentation→doc for the 32-char ID limit.
      'type' => 'community_group-group_node-' . ($entity->bundle() === 'documentation' ? 'doc' : $entity->bundle()),
    ]);
  return array_map(fn($r) => $r->getGroup()->id(), $relationships);
}
```

> [!NOTE]
> This creates **one queue item per event**, not one per recipient. The external system reads the `do_notifications` queue, resolves recipients from the `flagging` and `group_relationship` tables, checks suppression/mute/frequency preferences, and delivers.

## Step 560 — Phase 5 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_notifications`

Create `web/modules/custom/do_notifications/tests/src/Kernel/NotificationsTest.php`:

- `testModuleEnabled()` — `do_notifications` module enabled
- `testEventRecording()` — create a node, verify queue item with correct structure (`event`, `entity_type`, `entity_id`, `bundle`, `author_uid`, `group_ids`, `timestamp`)
- `testPerPostSuppression()` — set `do_notifications_suppress_{nid}` via State API, verify it reads back
- `testAdminDefaultsConfig()` — `do_notifications.settings` config exists with `default_frequency` and `auto_subscribe_comment` keys
- `testCommentAutoSubscribe()` — create comment, verify `follow_content` flag auto-set

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase5Test.php`:

- `testFollowFlags()` — `follow_content`, `follow_user`, `follow_term` flags exist with correct entity types
- `testMuteFlag()` — `mute_group_notifications` flag exists, targets `group` entity
- `testNotificationQueue()` — queue `do_notifications` exists
- `testNotificationFrequencyField()` — `field_notification_frequency` exists on user entity with correct allowed values

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase5Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase5.spec.ts`:

- `test('follow content toggle')` — log in, navigate to a node, click Follow, verify flagged
- `test('follow user toggle')` — navigate to user profile, click Follow User
- `test('mute group notifications')` — navigate to group, click Mute
- `test('per-post opt-out checkbox')` — create node, verify "Disable notifications" checkbox
- `test('notification settings page')` — navigate to `/user/{uid}/notification-settings`, verify UI
- `test('admin defaults form')` — log in as admin, verify `/admin/config/people/notification-defaults`
- `test('comment auto-subscribes')` — comment on a node, verify auto-follow

```bash
npx playwright test tests/e2e/phase5.spec.ts
```

## Phase 5 — Verification

- [ ] Follow flags exist: `follow_content`, `follow_user`, `follow_term`, `mute_group_notifications`
- [ ] Flag permissions granted to authenticated users
- [ ] `do_notifications` enabled: `drush pm:list --filter=do_notifications`
- [ ] "Notifications" tab appears on user profile pages
- [ ] `/user/{uid}/notification-settings` page loads for own account
- [ ] Page shows "Active Subscriptions: 0" when no flags
- [ ] Follow a node → subscription count increments to 1
- [ ] Subscriptions table shows Type, Title, Remove action
- [ ] "Remove" link unflaqs the entity (count drops)
- [ ] "Temporarily disable all" toggle works (warning message appears)
- [ ] "Re-enable all" toggle reverses the disable
- [ ] "Cancel all subscriptions" shows confirmation form
- [ ] Confirming cancellation removes all flaggings (count = 0)
- [ ] "Do not notify" checkbox on node create/edit forms
- [ ] Checkbox stores suppression flag in State API (`do_notifications_suppress_{nid}`)
- [ ] `field_notification_frequency` exists on user entity
- [ ] `hook_node_insert()` records event to `do_notifications` queue
- [ ] Queue items contain: event, entity_type, entity_id, bundle, author_uid, group_ids, timestamp
- [ ] Config exported clean: `ddev drush config:status`
- [ ] `ddev drush cr` — no PHP errors
- [ ] Existing Nightwatch tests still pass: `composer nightwatch`

### Schema Changes (Phase 5)

| Type | Name | Notes |
|---|---|---|
| **Flag** | `follow_content` | Per-user, entity:node |
| **Flag** | `follow_user` | Per-user, entity:user |
| **Flag** | `follow_term` | Per-user, entity:taxonomy_term |
| **Flag** | `mute_group_notifications` | Per-user, entity:group |
| **Field (user)** | `field_notification_frequency` | List (immediate/daily/weekly) |
| **Route** | `/user/{uid}/notification-settings` | Subscription management page |
| **Route** | `/user/{uid}/notification-settings/cancel` | Cancel all subscriptions form |
| **Module** | `do_notifications` | Subscriptions, opt-out, event recording |

### Custom module files (Phase 5)

```
docs/groups/modules/do_notifications/   ← source of truth
├── config/
│   └── optional/               ← skipped if flags already in active config
│       ├── do_notifications.settings.yml
│       ├── flag.flag.follow_content.yml
│       ├── flag.flag.follow_user.yml
│       ├── flag.flag.follow_term.yml
│       └── flag.flag.mute_group_notifications.yml
├── src/
│   ├── Controller/
│   │   └── NotificationSettingsController.php
│   ├── Form/
│   │   ├── CancelAllSubscriptionsForm.php
│   │   └── NotificationDefaultsForm.php
│   └── Hook/
│       └── DoNotificationsHooks.php    ← #[Hook] form_alter, node_insert, comment_insert
├── do_notifications.info.yml
├── do_notifications.links.task.yml
├── do_notifications.module             ← empty (@file docblock)
├── do_notifications.routing.yml
└── do_notifications.services.yml       ← registers DoNotificationsHooks service

web/modules/custom/do_notifications/    ← deployed copy (synced via rm -rf + cp -r)
```

> [!NOTE]
> **`config/optional/` vs `config/install/`**: Use `config/optional/` for flag configs that may already exist in active config. `config/install/` would throw `PreExistingConfigException` if the flags were imported globally before the module was enabled.

### Key Adaptations from Open Social (Phase 5)

| Aspect | Open Social (pl-opensocial) | Standard Drupal (pl-drupalorg) |
|---|---|---|
| **Follow user entity** | `entity:profile` (profile module) | `entity:user` (core user) |
| **Follow user dependency** | `social_follow_user` (enforced) | None (Flag is sufficient) |
| **Notification approach** | `activity` entity + `activity_send_email` pipeline + `ActivityDigestWorker` | Event recording only — one queue item per event; external system resolves recipients and delivers |
| **Content types** | `topic`, `event`, `page` | `forum`, `documentation`, `event`, `post`, `page` |
| **View mode for mute** | `hero`, `teaser` (OS view modes) | `full`, `teaser` (standard view modes) |

### Open Questions (Phase 5)

1. ~~**Notification sending**~~: ✅ Resolved — Drupal enqueues notification items only; an external system handles email delivery.
2. ~~**Digest implementation**~~: ✅ Resolved — external delivery system is responsible for batching by frequency preference.
3. ~~**Group notification triggers**~~: ✅ Confirmed: only when explicitly following (group membership alone does not trigger notifications)
4. ~~**Comment notifications**~~: ✅ Confirmed: commenting auto-subscribes the commenter to the thread (`follow_content` flag auto-set on comment insert)
5. ~~**Notification frequency UI**~~: ✅ Confirmed: two-tier design. Admin form at `/admin/config/people/notification-defaults` sets site-wide defaults; per-user settings page at `/user/{uid}/notification-settings` lets users override.

---

# Phase 6 — User Profiles & Group Admin

**Goal**: Port profile contribution stats, content pinning within groups, group mission sidebar, and group-level language negotiation.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phases 7–8

## Pre-Phase 6 Snapshot

```bash
ddev export-db --file=backups/pre-phase6-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 600 — Enable `do_profile_stats` Module

Contribution stats block and profile completeness indicator for user profiles.

**Source files** (in repo at `docs/groups/modules/do_profile_stats/`, deployed to `web/modules/custom/do_profile_stats/`):
- `do_profile_stats.info.yml` — `core_version_requirement: ^10 || ^11`
- `do_profile_stats.services.yml` — registers `DoProfileStatsHooks` service
- `src/Hook/DoProfileStatsHooks.php` — `hook_theme`, `hook_page_attachments` via `#[Hook]`
- `do_profile_stats.module` — empty (`@file` docblock)
- `do_profile_stats.libraries.yml`
- `src/Plugin/Block/ContributionStatsBlock.php` — counts topics, events, comments, groups, days active (`topic` → `forum`)
- `src/Plugin/Block/ProfileCompletenessBlock.php` — uses `user` entity (not Open Social `profile`)
- `templates/pl-contribution-stats.html.twig`
- `templates/pl-profile-completeness.html.twig`
- `css/do_profile_stats.css`

> [!WARNING]
> **Profile completeness fields**: Update the `$fields_to_check` array in `ProfileCompletenessBlock.php` after researching which user fields actually exist on this site.

> [!NOTE]
> **Group 4.x check**: `ContributionStatsBlock` counts group memberships. If it calls a membership-loading helper with a `$roles` filter, pass an **array** (`['role_id']`), not a bare string (CR 2025-02-19). Lower risk than `do_multigroup`, but sweep for the same tokens (`content_plugin`, `flexible_permissions`, `permission_calculator`) — see [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md) §4.

### Enable

```bash
# Step 1: Copy module from docs to web
cp -r docs/groups/modules/do_profile_stats web/modules/custom/
# Step 2: Enable module
ddev drush en do_profile_stats -y
# Step 3: Export config
ddev drush config:export -y
```

### 600h — Place blocks in bluecheese

> [!NOTE]
> Replace `"theme" => "bluecheese"` with the actual active theme name if different.

```bash
ddev drush php:eval '
$block_storage = \Drupal::entityTypeManager()->getStorage("block");

// Contribution Stats block.
if (!$block_storage->load("do_contribution_stats")) {
  $block_storage->create([
    "id" => "do_contribution_stats",
    "plugin" => "do_contribution_stats",
    "region" => "content",
    "theme" => "bluecheese",
    "weight" => 50,
    "settings" => [
      "id" => "do_contribution_stats",
      "label" => "Contribution Stats",
      "label_display" => "0",
      "provider" => "do_profile_stats",
    ],
    "visibility" => [
      "request_path" => [
        "id" => "request_path",
        "pages" => "/user/*",
        "negate" => FALSE,
      ],
    ],
  ])->save();
  echo "Contribution Stats block placed\n";
}

// Profile Completeness block (visible only to the profile owner).
if (!$block_storage->load("do_profile_completeness")) {
  $block_storage->create([
    "id" => "do_profile_completeness",
    "plugin" => "do_profile_completeness",
    "region" => "content",
    "theme" => "bluecheese",
    "weight" => 10,
    "settings" => [
      "id" => "do_profile_completeness",
      "label" => "Profile Completeness",
      "label_display" => "0",
      "provider" => "do_profile_stats",
    ],
    "visibility" => [
      "request_path" => [
        "id" => "request_path",
        "pages" => "/user/*",
        "negate" => FALSE,
      ],
    ],
  ])->save();
  echo "Profile Completeness block placed\n";
}
```

ddev drush config:export -y

## Step 610 — Enable `do_group_pin` Module

Content pinning within groups. Allows group managers to pin topics above the chronological stream.

**Source files** (in repo at `docs/groups/modules/do_group_pin/`, deployed to `web/modules/custom/do_group_pin/`):
- `do_group_pin.info.yml` — `core_version_requirement: ^10 || ^11`; depends on `drupal:flag`, `drupal:views`
- `do_group_pin.services.yml` — registers `DoGroupPinHooks` service
- `src/Hook/DoGroupPinHooks.php` — `hook_page_attachments`, `hook_preprocess_node`, `hook_views_query_alter` via `#[Hook]`
- `do_group_pin.module` — empty (`@file` docblock)
- `do_group_pin.libraries.yml`
- `css/do_group_pin.css` — `.pin-badge` and `.node--pinned` styles
- `config/optional/flag.flag.pin_in_group.yml` — bundled flag (skipped if already in active config)

> [!CAUTION]
> **View ID must match**: `DoGroupPinHooks::viewsQueryAlter()` checks `$view->id() === 'group_content_stream'`. Open Social used `group_topics`; this implementation already targets `group_content_stream` (from Phase 3 Step 310). ✅ Already correct.

> [!NOTE]
> **Group 4.x check**: if `do_group_pin` inspects relation types (e.g. filtering by relation plugin), use the `relation_type` key/accessor, not `content_plugin`. If it creates relationships or pins entities to groups, remember that "add to group" now invalidates cache tags instead of resaving the entity (§3 of [`GROUP_4X_MIGRATION.md`](GROUP_4X_MIGRATION.md)).
> <!-- VERIFY on build: whether do_group_pin programmatically creates groups (creator-membership is now form-only in 4.x) or reads content_plugin. -->

> [!CAUTION]
> **`config/optional/`**: `pin_in_group` flag is imported globally before the module is enabled (from `docs/groups/config/`). Module uses `config/optional/` to avoid `PreExistingConfigException`. Same pattern as Phases 4 and 5.

### 610c — Import `pin_in_group` flag

```bash
cp docs/groups/config/flag.flag.pin_in_group.yml config/sync/
cp docs/groups/config/system.action.flag_action.pin_in_group_flag.yml config/sync/
cp docs/groups/config/system.action.flag_action.pin_in_group_unflag.yml config/sync/
ddev drush config:import -y
ddev drush config:export -y
```

### 610e — Grant permissions

```bash
# Remove role:perm add for roles that don't exist yet:
# site_moderator and content_administrator are NOT yet created.
# Permissions will be assigned after roles are created in Phase 7.
# For now, only administrator can pin.
ddev drush config:export -y
```

> [!IMPORTANT]
> **Roles**: Only `anonymous` and `authenticated` roles exist in this installation. `content_administrator` and `site_moderator` were referenced in the RUNBOOK but never created. Create them before assigning pin permissions:
> ```bash
> ddev drush role:create content_administrator 'Content Administrator'
> ddev drush role:create site_moderator 'Site Moderator'
> ddev drush role:perm:add content_administrator "flag pin_in_group,unflag pin_in_group"
> ddev drush role:perm:add site_moderator "flag pin_in_group,unflag pin_in_group"
> ddev drush config:export -y
> ```

### Enable

```bash
# Step 1: Copy module from docs to web
cp -r docs/groups/modules/do_group_pin web/modules/custom/
# Step 2: Enable module
ddev drush en do_group_pin -y
# Step 3: Export config
ddev drush config:export -y
```
## Step 620 — Enable `do_group_mission` Module

Group mission statement sidebar block. Displays a summary of the group description on all group pages.

**Source files** (in repo at `docs/groups/modules/do_group_mission/`, deployed to `web/modules/custom/do_group_mission/`):
- `do_group_mission.info.yml` — `core_version_requirement: ^10 || ^11`; depends on `drupal:group`
- `src/Plugin/Block/GroupMissionBlock.php` — context-aware block, reads `field_group_description` (129 lines, no changes from OS version)

> [!NOTE]
> **Prerequisite**: `field_group_description` must exist on `community_group` (created in Phase 1 Step 130).

```bash
ddev drush php:eval '
$field = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_description");
echo $field ? "field_group_description exists\n" : "field_group_description MISSING\n";
'
```

### Enable

```bash
cp -r docs/groups/modules/do_group_mission web/modules/custom/
ddev drush en do_group_mission -y
ddev drush config:export -y
```

### 620c — Place block in sidebar

```bash
ddev drush php:script docs/groups/scripts/step_620c.php
ddev drush config:export -y
```
## Step 630 — Enable `do_group_language` Module (optional)

> **Deferred**: Group-level language switching — confirm whether drupal.org groups need this feature before enabling.

**Source files** (already copied to `web/modules/custom/do_group_language/` but not yet enabled):
- `do_group_language.info.yml`
- `do_group_language.services.yml` — registers `LanguageNegotiationGroup` plugin
- `src/Plugin/LanguageNegotiation/LanguageNegotiationGroup.php` — raw path parsing for group ID (67 lines, no changes needed)

> [!IMPORTANT]
> **Why raw path parsing?** Language negotiation runs **before** Drupal's route matching. `\Drupal::routeMatch()->getParameter('group')` is always NULL at negotiation time. This is by design.

### 630d — Create `field_group_language` (if enabling)

```bash
ddev drush php:script docs/groups/scripts/step_630d.php
ddev drush config:export -y
```

### 630e — Enable (when confirmed needed)

```bash
cp -r docs/groups/modules/do_group_language web/modules/custom/
ddev drush en do_group_language -y
ddev drush config:export -y
```

After enabling, configure language negotiation at `/admin/config/regional/language/detection` — set "Group language" weight to 5.
## Step 640 — Multilingual Infrastructure

> [!IMPORTANT]
> Multilingual support is **required** for groups (group-level language switching, translated content, multilingual demo data). The `do_group_language` module must be enabled.

### 640a — Enable core translation modules

> [!CAUTION]
> **`interface_translation` does not exist** as a standalone Drupal 11 module. The correct module list is: `language`, `locale`, `config_translation`, `content_translation`. The `locale` module provides interface translation.

```bash
ddev drush en language locale config_translation content_translation -y
ddev drush config:export -y
```

### 640b — Add languages

Add languages matching g.d.o's multilingual needs:

**Script**: [step_640.php](scripts/step_640.php) — adds 14 languages, configures negotiation chain, enables content translation for all 5 types

```bash
ddev drush php:script docs/groups/scripts/step_640.php
```

Expected: 15 languages total (14 + English)

### 640c — Download translations

```bash
ddev drush locale:check
ddev drush locale:update
```

> [!NOTE]
> Downloads translation strings for all enabled languages (~139k strings, 1-2 minutes).

### 640e — Grant translation permissions

> [!IMPORTANT]
> **Roles do not yet exist.** At this point only `anonymous` and `authenticated` roles are in the site. `content_administrator` and `site_moderator` must be created first:
> ```bash
> ddev drush role:create content_administrator 'Content Administrator'
> ddev drush role:create site_moderator 'Site Moderator'
> ```

```bash
ddev drush role:perm:add site_moderator "translate any entity,create content translations,update content translations,delete content translations"
ddev drush role:perm:add content_administrator "translate any entity,create content translations,update content translations,delete content translations"
ddev drush config:export -y
```

## Step 650 — Phase 6 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_profile_stats`

Create `web/modules/custom/do_profile_stats/tests/src/Kernel/ProfileStatsTest.php`:

- `testContributionStatsBlock()` — block plugin exists; counts all 5 content types
```bash
ddev exec phpunit web/modules/custom/do_profile_stats/tests/src/Kernel/ProfileStatsTest.php
```

### PHPUnit — Module: `do_group_pin`

Create `web/modules/custom/do_group_pin/tests/src/Kernel/GroupPinTest.php`:
- `testPinnedSortOrder()` — pinned content sorts above unpinned

```bash
ddev exec phpunit web/modules/custom/do_group_pin/tests/src/Kernel/GroupPinTest.php
```

### PHPUnit — Module: `do_group_mission`

Create `web/modules/custom/do_group_mission/tests/src/Kernel/GroupMissionTest.php`:

- `testMissionBlock()` — block plugin exists; reads `field_group_description`
- `testTruncation()` — description truncated at 300 chars with "Read more" link

```bash
ddev exec phpunit web/modules/custom/do_group_mission/tests/src/Kernel/GroupMissionTest.php
```

### PHPUnit — Module: `do_group_language`

Create `web/modules/custom/do_group_language/tests/src/Kernel/GroupLanguageTest.php`:

- `testLanguageNegotiationPlugin()` — `language-group` negotiation method registered
- `testGroupLanguageField()` — `field_group_language` exists on `community_group`

```bash
ddev exec phpunit web/modules/custom/do_group_language/tests/src/Kernel/GroupLanguageTest.php
```

> [!WARNING]
> Review [TROUBLESHOOTING.md](../playbook/agent/troubleshooting.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase6Test.php`:

- `testContentTranslation()` — content translation enabled for all 5 group-postable types
- `testLanguagesConfigured()` — at least 15 languages configured (14 + English)
- `testBlockPlacements()` — all blocks placed in `bluecheese` theme regions

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase6Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase6.spec.ts`:

- `test('contribution stats render')` — navigate to `/user/{uid}`, verify all 8 stat items
- `test('profile completeness percentage')` — verify completeness block with missing fields list
- `test('pin content in group')` — log in as group admin, pin a post, verify badge and top placement
- `test('unpin content')` — unpin, verify normal sort order
- `test('group mission sidebar')` — verify mission block with truncated text and "Read more"
- `test('group language switching')` — set group language to French, verify interface changes
- `test('site_moderator and group admin can pin')` — verify both roles see Pin action

```bash
npx playwright test tests/e2e/phase6.spec.ts
```

## Phase 6 — Verification

- [ ] `do_profile_stats` enabled: `drush pm:list --filter=do_profile_stats`
- [ ] Contribution Stats block visible on `/user/{uid}` profile pages
- [ ] Stats show correct counts (forum topics, events, comments, groups, days)
- [ ] Profile Completeness block visible in sidebar on own profile
- [ ] Completeness percentage updates when fields are filled
- [ ] Missing fields list shown in collapsible details element
- [ ] `do_group_pin` enabled: `drush pm:list --filter=do_group_pin`
- [ ] `pin_in_group` flag exists at `/admin/structure/flags`
- [ ] Pin/Unpin action visible to site_moderator and content_administrator
- [ ] Pinned content shows "Pinned" badge
- [ ] Pinned content appears above chronological content in group stream
- [ ] `.node--pinned` CSS class applied (orange left border)
- [ ] `do_group_mission` enabled: `drush pm:list --filter=do_group_mission`
- [ ] Mission block visible in sidebar on `/group/{gid}` pages
- [ ] Mission text truncated at 300 chars with "Read more" link
- [ ] "Read more" links to `/group/{gid}/about`
- [ ] Block not visible on non-group pages
- [ ] `do_group_language` enabled: `drush pm:list --filter=do_group_language`
- [ ] If enabled: language negotiation order configured at `/admin/config/regional/language/detection`
- [ ] All blocks placed in `bluecheese` theme regions (`content`, `sidebar_first`)
- [ ] **Multilingual** (Step 640):
- [ ] 5 core translation modules enabled (language, locale, interface_translation, config_translation, content_translation)
- [ ] 15 languages configured (14 + English)
- [ ] Translation strings downloaded (`drush locale:update`)
- [ ] Language negotiation order: user → group → URL → selected
- [ ] Translation permissions granted to `site_moderator` and `content_administrator`
- [ ] Content translation enabled for forum, documentation, event, post, page
- [ ] `field_group_language` exists on `community_group` with form display widget
- [ ] Config exported clean: `ddev drush config:status`
- [ ] `ddev drush cr` — no PHP errors
- [ ] Existing Nightwatch tests still pass: `composer nightwatch`

### Schema Changes (Phase 6)

| Type | Name | Notes |
|---|---|---|
| **Block** | `do_contribution_stats` | Profile page — topics, events, comments, groups, days |
| **Block** | `do_profile_completeness` | Profile page — field fill percentage |
| **Block** | `do_group_mission` | Group sidebar — description block |
| **Flag** | `pin_in_group` | Global, entity:node |
| **Field (group)** | `field_group_language` | Language type field |
| **Config change** | `language.types` | Negotiation order: user → group → URL → selected |
| **Languages** | 14 added | de, es, fr, it, ja, ko, nl, pl, pt-br, ru, tr, uk, zh-hans, ar |
| **Module** | `do_profile_stats` | Stats + completeness blocks |
| **Module** | `do_group_pin` | Pin flag + Views query alter + badge |
| **Module** | `do_group_mission` | Mission sidebar block |
| **Module** | `do_group_language` | Language negotiation plugin |
| **Module** | `language`, `locale`, `config_translation`, `content_translation` | Core multilingual stack (`interface_translation` does not exist in D11) |

### Custom module files (Phase 6)

```
docs/groups/modules/do_profile_stats/   ← source of truth
├── css/do_profile_stats.css
├── src/
│   ├── Hook/
│   │   └── DoProfileStatsHooks.php     ← #[Hook] theme, page_attachments
│   └── Plugin/Block/
│       ├── ContributionStatsBlock.php  ← topic → forum adaptation
│       └── ProfileCompletenessBlock.php ← profile → user entity
├── templates/
│   ├── pl-contribution-stats.html.twig
│   └── pl-profile-completeness.html.twig
├── do_profile_stats.info.yml
├── do_profile_stats.libraries.yml
├── do_profile_stats.module             ← empty (@file docblock)
└── do_profile_stats.services.yml       ← registers DoProfileStatsHooks

docs/groups/modules/do_group_pin/       ← source of truth
├── config/optional/
│   └── flag.flag.pin_in_group.yml      ← global node flag (skipped if already active)
├── css/do_group_pin.css
├── src/Hook/
│   └── DoGroupPinHooks.php             ← #[Hook] page_attachments, preprocess_node, views_query_alter
├── do_group_pin.info.yml
├── do_group_pin.libraries.yml
├── do_group_pin.module                 ← empty (@file docblock)
└── do_group_pin.services.yml           ← registers DoGroupPinHooks

docs/groups/modules/do_group_mission/   ← source of truth
├── src/Plugin/Block/
│   └── GroupMissionBlock.php           ← no changes from OS version
└── do_group_mission.info.yml

docs/groups/modules/do_group_language/  ← source of truth (not yet enabled)
├── src/Plugin/LanguageNegotiation/
│   └── LanguageNegotiationGroup.php    ← raw path parsing (no changes)
├── do_group_language.info.yml
└── do_group_language.services.yml

web/modules/custom/{do_profile_stats,do_group_pin,do_group_mission,do_group_language}/
  ← deployed copies (synced via cp -r)
```

### Key Adaptations from Open Social (Phase 6)

| Aspect | Open Social (pl-opensocial) | Standard Drupal (pl-drupalorg) |
|---|---|---|
| **Profile entity** | `profile` module entity | Standard `user` entity |
| **Profile fields** | `field_profile_first_name`, etc. | `field_first_name`, `field_bio`, etc. |
| **Content type for stats** | `topic` | `forum` |
| **Pin View ID** | `group_topics` | `group_content_stream` |
| **Mission block region** | `complementary_bottom` (socialblue) | `sidebar_first` (bluecheese) |
| **Block theme** | `socialblue` | `bluecheese` |
| **Flag dependency notation** | `flag:flag` | `drupal:flag` |

### Open Questions (Phase 6)

1. ~~**Profile completeness fields**~~: ⏳ Still deferred — research which `user` fields this site actually uses and update `$fields_to_check` in `ProfileCompletenessBlock.php`.
2. ~~**Contribution stats expansion**~~: ✅ Confirmed: count all 5 group-postable content types (forum, documentation, event, post, page) plus comments, groups, and days active.
3. ~~**Group mission field**~~: ✅ Resolved: `field_group_description` defined in Step 130 as a required field on `community_group`.
4. ~~**Group language**~~: ⏳ Deferred — `do_group_language` copied to web but NOT enabled pending decision on whether d.o. groups need per-group language switching.
5. ~~**Pin permissions**~~: ✅ Note: only `anonymous` and `authenticated` roles currently exist. `content_administrator` and `site_moderator` must be created before assigning pin permissions. Role creation added to Step 640e.
6. ~~**`interface_translation` module**~~: ✅ Resolved — this module does **not** exist in Drupal 11. Correct list: `language locale config_translation content_translation`.

---

# Phase 7 — Demo Data

**Goal**: Populate the site with realistic test data — users, groups, content, comments, flags — to validate all features from Phases 1–6.

**Source**: DEMO_DATA_PLAN.md (removed — was Open Social version, 1,334 lines)

> [!WARNING]
> The existing `DEMO_DATA_PLAN.md` (removed) was written for Open Social's data model. It must be adapted for pl-drupalorg's entity types, field names, content type names, and relationship patterns. This section documents the required adaptations phase by phase.

## Pre-Phase 7 Snapshot

```bash
ddev export-db --file=backups/pre-phase7-$(date +%Y%m%d-%H%M).sql.gz
```

## Pre-Phase 7 Prerequisites

> [!CAUTION]
> **`language.content_settings` malformed config**: `step_640.php` stores content language settings without `target_entity_type_id`, which crashes Drupal during bootstrap. Fix BEFORE creating roles or running demo data:
>
> ```bash
> for bundle in forum documentation event post page; do
> cat > config/sync/language.content_settings.node.${bundle}.yml << YAML
> langcode: en
> status: true
> dependencies:
>   config:
>     - node.type.${bundle}
>   module:
>     - content_translation
> id: node.${bundle}
> target_entity_type_id: node
> target_bundle: ${bundle}
> default_langcode: site_default
> language_alterable: false
> third_party_settings:
>   content_translation:
>     enabled: true
> YAML
> done
> ddev mysql -e "DELETE FROM config WHERE name LIKE 'language.content_settings%'"
> ddev mysql -e "TRUNCATE TABLE cache_bootstrap; TRUNCATE TABLE cache_config; TRUNCATE TABLE cache_container; TRUNCATE TABLE cache_data; TRUNCATE TABLE cache_default; TRUNCATE TABLE cache_discovery; TRUNCATE TABLE cache_entity; TRUNCATE TABLE cachetags;"
> ddev drush config:import -y
> ```

> [!CAUTION]
> **`language-group` plugin not found**: `language.types` negotiation config references `language-group` (set by step_640.php) but `do_group_language` must be enabled first. Enable it BEFORE creating roles:
> ```bash
> ddev drush en do_group_language -y
> ```

> [!CAUTION]
> **Roles don't exist yet**: `content_administrator` and `site_moderator` must be created BEFORE running `step_700_demo_data.php`. Create them and export to config/sync first — otherwise config:import after demo data will delete them:
> ```bash
> ddev drush role:create content_administrator "Content Administrator"
> ddev drush role:create site_moderator "Site Moderator"
> ddev drush role:perm:add content_administrator "flag pin_in_group,unflag pin_in_group,flag promote_homepage,unflag promote_homepage,translate any entity,create content translations,update content translations,delete content translations"
> ddev drush role:perm:add site_moderator "flag pin_in_group,unflag pin_in_group,flag promote_homepage,unflag promote_homepage,translate any entity,create content translations,update content translations,delete content translations"
> ddev drush config:export -y
> ```

## Steps 700–750 — Create Demo Data

**Script**: `docs/groups/scripts/step_700_demo_data.php` — all-in-one idempotent demo data

```bash
ddev drush php:script docs/groups/scripts/step_700_demo_data.php
```

> [!NOTE]
> **Known miss**: `ERROR: No comment field on forum nodes` is expected. Forum content type does not have a comment field configured yet. Comments work on other content types.

Creates (all idempotent):
- **6 users**: maria_chen, james_okafor, elena_garcia, ravi_patel, sophie_mueller, alex_novak
- **20 taxonomy tags**: sprint, drupalcon, logistics, core, roadmap, etc.
- **8 groups**: DrupalCon Portland 2026, Drupal France, Core Committers, Thunder Distribution, Leadership Council, Camp Organizers EMEA, Drupal Deutschland, Legacy Infrastructure
- **~17 nodes**: forum topics + events across groups
- **Flags**: pin (Sprint Planning), promote (2 topics), follow content/term/user, 9 RSVPs
- **Archive**: Legacy Infrastructure group set to unpublished

## Step 710 — Multilingual Demo Data

> [!IMPORTANT]
> `do_group_language` **must be enabled** before Step 710 or the language negotiation plugin (`language-group`) registered by step_640.php will be missing, causing a Drupal bootstrap crash.

```bash
ddev drush php:script docs/groups/scripts/step_760.php
```

### 710e — Download locale translation strings

```bash
ddev drush locale:check
ddev drush locale:update
ddev drush config:export -y
```

> [!NOTE]
> This downloads translation strings for all 17 enabled languages (~139k strings, 1-2 minutes).

### 710f — Verify multilingual demo data

```bash
# Verify group languages are set:
ddev drush php:eval '
foreach (["Drupal France" => "fr", "Drupal Deutschland" => "de"] as $label => $expected) {
  $groups = \Drupal::entityTypeManager()->getStorage("group")->loadByProperties(["label" => $label]);
  $group = reset($groups);
  $actual = $group->get("field_group_language")->value;
  echo "$label language: $actual " . ($actual === $expected ? "OK" : "MISMATCH (expected $expected)") . "\n";
}
'

# Verify French and German content exists:
ddev drush php:eval '
foreach (["fr", "de"] as $lang) {
  $count = \Drupal::entityQuery("node")->condition("langcode", $lang)->accessCheck(FALSE)->count()->execute();
  echo "$lang-language nodes: $count\n";
}
'

# Verify translations downloaded:
ddev drush php:eval '
$count = \Drupal::database()->select("locales_target", "lt")->countQuery()->execute()->fetchField();
echo "Translation strings: $count\n";
'
```

Expected results:
- Drupal France language: `fr`
- Drupal Deutschland language: `de`
- fr-language nodes: ≥3
- de-language nodes: ≥2
- Translation strings: >0 (typically thousands)

## Step 720 — Search Setup (Solr or Core)

> [!IMPORTANT]
> pl-drupalorg may already have a search backend configured. Check current status before setting up Solr.

### 720a — Check existing search configuration

**Script**: [step_770.php](scripts/step_770.php) — checks existing config and creates Solr server

```bash
ddev drush php:script docs/groups/scripts/step_770.php
```

Generate and upload Drupal configset to Solr:
```bash
ddev drush search-api-solr:get-server-config solr_server /tmp/solr-config.zip
ddev exec bash -c 'mkdir -p /tmp/solr-configset && cd /tmp/solr-configset && unzip -o /tmp/solr-config.zip'
# Copy to Solr container and upload
docker cp $(ddev describe -j | python3 -c "import sys,json; print(json.load(sys.stdin)['raw']['name'])")-web:/tmp/solr-configset /tmp/solr-configset
ddev solr zk upconfig -n drupal -d /tmp/solr-configset
ddev solr create -c drupal -n drupal
```

### 720c — Option B: Core Search (minimal setup)

If Solr is not needed, enable core search:
```bash
ddev drush en search -y
ddev drush search:index
```

### 720d — Index all content

```bash
ddev drush cron
ddev drush search-api:index  # if using search_api
ddev drush cr
```

Verify search works:
```bash
ddev drush search-api:status  # should show 0 items remaining
```

## Step 730 — Final Snapshot

Snapshot complete demo database:
```bash
ddev export-db --file=backups/demo-complete-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 740 — Phase 7 Tests

### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase7Test.php`:

- `testDemoUsersExist()` — 6 demo users created with expected roles
- `testDemoGroupsExist()` — 8 demo groups created with correct group types and membership
- `testDemoContentExists()` — expected node counts per content type (including French and German)
- `testDemoFlagsSet()` — follow_content, follow_user, pin_in_group flags on expected entities
- `testSearchIndexPopulated()` — search index has entries for demo content

> [!NOTE]
> Phase 7 is demo data — all tests are integration tests in `do_tests`. No custom module changes.

```bash
ddev exec phpunit web/modules/custom/do_tests/tests/src/Kernel/Phase7Test.php
```

### Playwright (E2E)

Create `tests/e2e/phase7.spec.ts`:

- `test('demo user can log in')` — log in as each demo user, verify profile loads
- `test('demo groups listed')` — navigate to `/groups`, verify all 7 groups
- `test('demo content in group streams')` — navigate to demo group, verify content
- `test('pinned content at top')` — verify pinned demo content above other content
- `test('promoted content visible')` — navigate to `/promoted`, verify demo content
- `test('search returns results')` — search for demo content title
- `test('RSVP flag on demo events')` — verify demo events have RSVP counts

```bash
npx playwright test tests/e2e/phase7.spec.ts
```

## Phase 7 — Verification

- [ ] 6 demo users exist with correct roles: `ddev drush user:list --format=table`
- [ ] sophie_mueller preferred language = `de`, elena_garcia = `es`
- [ ] ravi_patel profile is intentionally incomplete (no bio, no country)
- [ ] alex_novak has no profile photo
- [ ] 8 groups created as `community_group` type
- [ ] Membership matrix matches plan (Portland=6+, Council=2, etc.)
- [ ] 12+ forum topics created across groups (including 3 French)
- [ ] 5 events created with correct date fields
- [ ] Thunder 7.0 Roadmap cross-posted to 2 groups
- [ ] Weekly Standup cross-posted to 2 groups
- [ ] 8 comments on correct topics (Venue=3, RFC=4, Roadmap=1)
- [ ] `pin_in_group` flagging: 1 (Sprint Planning)
- [ ] `promote_homepage` flaggings: 2
- [ ] `follow_content` flaggings: ≥2
- [ ] `follow_term` flagging: 1 (elena → core)
- [ ] `follow_user` flagging: 1 (ravi → maria)
- [ ] `rsvp_event` flaggings: ≥7
- [ ] Legacy Infrastructure archived (status=0)
- [ ] **Multilingual** (Step 710):
  - [ ] Drupal France `field_group_language` = `fr`
  - [ ] Drupal Deutschland `field_group_language` = `de`
  - [ ] `field_group_language` widget visible on group add/edit form
  - [ ] ≥3 French-language nodes in Drupal France group
  - [ ] ≥2 German-language nodes in Drupal Deutschland group
  - [ ] Locale translation strings downloaded (`drush locale:update`)
  - [ ] Visiting Drupal France group page switches interface to French
  - [ ] Visiting Drupal Deutschland group page switches interface to German
- [ ] Database snapshot saved
- [ ] Search configured (Solr or core search)
- [ ] If Solr: `ddev drush search-api:status` shows 0 items remaining
- [ ] `ddev drush cr` — no PHP errors

### Schema Changes (Phase 7)

| Type | Name | Notes |
|---|---|---|
| **Data** | 6 users | admin + 5 demo users with roles |
| **Data** | 8 groups | Community groups (1 archived, 2 multilingual) |
| **Data** | ~17 nodes | 12 English + 3 French + 2 German forum topics + 5 events |
| **Data** | ~8 comments | Threaded discussions |
| **Data** | ~20 flaggings | follow_content, follow_term, follow_user, rsvp_event, pin_in_group, promote_homepage |
| **Data** | ~25 group_relationships | Users + content assigned to groups |
| **Data** | ~22 taxonomy terms | Tags on content |
| **Data** | Translation strings | Downloaded via `drush locale:update` for 17 languages |
| **Config** | `field_group_language` form widget | Language select on group add/edit form |
| **Config** | Search API server + index | If Solr chosen (Step 720) |
| **Snapshot** | `demo-empty-*.sql.gz` | After clean slate |
| **Snapshot** | `demo-complete-*.sql.gz` | After full population |

### Key Adaptations from Open Social (Phase 7)

| Aspect | Open Social DEMO_DATA_PLAN | pl-drupalorg adaptation |
|---|---|---|
| **Profile storage** | `profile` entity + `field_profile_*` | `user` entity + `field_*` |
| **Roles** | `contentmanager`, `sitemanager` | `content_administrator`, `site_moderator` |
| **Group type** | `flexible_group` | `community_group` |
| **Group role** | `flexible_group-group_manager` | `community_group-admin` |
| **Content type** | `topic` | `forum` |
| **Tag vocabulary** | `social_tagging` | `tags` |
| **Tag field** | `social_tagging` | `field_group_tags` |
| **Content visibility** | `field_content_visibility` | Not available (remove) |
| **Event dates** | `field_event_date` + `field_event_date_end` | `field_date_of_event` |
| **Event enrollment** | `EventEnrollment` entity | `rsvp_event` flag |
| **Group relationship** | `group_content` + `group_node:topic` | `group_relationship` + `group_node:forum` |
| **Follow user target** | `profile` entity | `user` entity |

### Open Questions (Phase 7)

1. ~~**Profile fields**~~: ⏳ Still open — does pl-drupalorg have `field_organization` or equivalent? Check via `ddev drush php:eval '\Drupal\field\Entity\FieldStorageConfig::loadByName("user", "field_organization") ? print "yes" : print "no";'`
2. ~~**Tag vocabulary**~~: ✅ Confirmed — machine name is `tags` (sitewide) and `group_tags` (group-specific). Demo data uses `group_tags`.
3. ~~**Comment field name**~~: ✅ Confirmed — **no comment field on forum nodes**. `ERROR: No comment field on forum nodes` in demo script is expected. Comment field not yet configured for forum content type.
4. ~~**Event date field type**~~: ✅ Resolved — `field_date_of_event` (`datetime`).
5. ~~**Group visibility**~~: ⏳ Deferred — group access control via Group module policies, not custom field.
6. ~~**`language.content_settings` malformed**~~: ✅ Fixed — step_640.php stored only `third_party_settings`; corrected YAMLs add required `target_entity_type_id`, `target_bundle`, `default_langcode`, `language_alterable` keys.
7. ~~**Roles bootstrap issue**~~: ✅ Fixed — create roles AFTER clearing all caches + fixing content settings, BEFORE running config:import.

---

## Step 750 — Install Asset Injector

Install the `asset_injector` module to allow adding custom CSS and JS snippets via the admin UI. This is useful for quick theming adjustments (e.g. hiding the drupal.org mega-menu on a development instance) without modifying theme files.

```bash
ddev composer require drupal/asset_injector
ddev drush en asset_injector -y
ddev drush cr
```

**Navigate to** `/admin/config/development/asset-injector` to verify the module is available.

> [!TIP]
> Use CSS injector snippets at `/admin/config/development/asset-injector/css` to hide or restyle elements without touching the bluecheese theme.

---

# Phase 8 — Feature Tour

**Goal**: Create a visual feature tour document with screenshots from the bluecheese theme, modelled on the Open Social Feature Tour (reference only — not in this repo).

**Source**: feature_tour/FEATURE_TOUR.md (reference only — not in this repo) (245 lines, 7 screenshots), bluecheese theme

> [!NOTE]
> The existing feature tour at `feature_tour/FEATURE_TOUR.md` documents the **Open Social** (socialblue theme) platform. The new tour will document the **pl-drupalorg** (bluecheese theme) platform with updated screenshots and adapted descriptions.

## Pre-Phase 8 Snapshot

```bash
ddev export-db --file=backups/pre-phase8-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 790 — Create All Topics and All Events Views

> [!IMPORTANT]
> These views are prerequisites for Phase 8 screenshots. They were intentionally deferred from earlier phases. Create them now via config import before capturing screenshots.

The YAML files are already in `config/sync/`:
- `views.view.all_topics.yml` — Forum topic listing at `/all-topics`
- `views.view.all_events.yml` — Event listing at `/all-events`

```bash
ddev drush cim -y
ddev drush cr
```

**Verify:**
- `/all-topics` loads and shows forum nodes
- `/all-events` loads and shows event nodes sorted by date

## Step 800 — Capture Screenshots

Take screenshots of the bluecheese-themed site using the demo data from Phase 7.

### Screenshots to capture

| # | Page | URL | Key elements to show |
|---|---|---|---|
| 1 | Homepage | `/` | Activity stream, sidebar widgets, bluecheese header |
| 2 | Logged-in homepage | `/` (authenticated) | Admin toolbar, quick-post composer, follow buttons |
| 3 | Group directory | `/all-groups` | Group cards, search, type filter |
| 4 | Group page | `/group/{portland_gid}` | Member count, tabs, mission sidebar, pinned content |
| 5 | Forum topics listing | `/all-topics` or equivalent | Topic cards, filters, multilingual content |
| 6 | Topic detail | `/node/{nid}` | Pinned badge, tags, comments, follow button |
| 7 | Events listing | `/all-events` | Event cards with dates, event type facets |

> [!NOTE]
> `/all-topics` and `/all-events` are created in Step 790 (Pre-Phase 8). Ensure Step 790 has been run before capturing these screenshots.
| 8 | User profile | `/user/{maria_uid}` | Contribution stats, completeness, follow button |
| 9 | Notification settings | `/user/{uid}/notification-settings` | Subscriptions table, disable toggle |
| 10 | Archived group | `/group/{legacy_gid}` | Archive badge, read-only indicator |
| 11 | Multilingual group (French) | `/group/{france_gid}` | French interface, French forum topics, `field_group_language` badge |
| 12 | Multilingual group (German) | `/group/{deutschland_gid}` | German interface, German forum topics, language switching |

```bash
# Get IDs for screenshot URLs:
ddev drush php:eval '
$groups = \Drupal::entityTypeManager()->getStorage("group")->loadMultiple();
foreach ($groups as $g) echo "gid=" . $g->id() . " " . $g->label() . "\n";
$maria = user_load_by_name("maria_chen");
echo "maria uid=" . $maria->id() . "\n";
'
```

> [!TIP]
> Use the browser tool to capture screenshots at 1280×800 resolution. Log in as admin for authenticated views.

## Step 810 — Write Feature Tour Document

Create `docs/groups/feature_tour_drupalorg/FEATURE_TOUR.md` modelled on the Open Social version but adapted for pl-drupalorg:

### Sections to include

**1. Homepage & Activity Stream**
- Describe the bluecheese theme layout
- Note differences from socialblue (header, sidebar regions, styling)
- Annotate: activity stream, sidebar widgets, navigation

**2. Forum Topics** (was "Topics" in OS tour)
- Note content type rename: `topic` → `forum`
- Content creation, tagging, sorting
- Multilingual content examples

**3. Events**
- Event date fields (`field_date_of_event`)
- RSVP via Flag (replaces `event_enrollment`)
- Cross-group events

**4. Groups**
- Group type: `community_group` (not `flexible_group`)
- Membership management
- Mission sidebar (do_group_mission)
- Content pinning (do_group_pin)
- Archive enforcement (do_group_extras)

**5. Multi-Group Posting**
- Same as OS tour — `do_multigroup` module
- Show cross-posted content in Thunder + Portland groups

**6. User Profiles**
- Profile data on user entity (not profile entity)
- Contribution stats block (do_profile_stats)
- Profile completeness indicator
- Follow user flag

**7. Discovery & Content Surfacing**
- Hot content scoring (do_discovery)
- Promoted content (promote_homepage flag)
- iCal/RSS feeds

**8. Notifications & Subscriptions**
- Follow flags: content, user, term
- Mute group notifications
- Notification settings page
- Per-post opt-out

**9. Technical Stack**

| Component | Version |
|---|---|
| Drupal core | 11.x (^11.2; epic target 11.4) |
| Group module | 4.x (alpha) |
| PHP | 8.3 |
| Theme | bluecheese |
| Deployment | DDEV |

### Custom modules summary table

| Module | Purpose | Phase |
|---|---|---|
| `do_group_extras` | Archive, guidelines, moderation | 2 |
| `do_multigroup` | Multi-group posting | 3 |
| `do_discovery` | Hot scores, promoted content, iCal/RSS | 4 |
| `do_notifications` | Subscriptions, event recording | 5 |
| `do_profile_stats` | Contribution stats + completeness | 6 |
| `do_group_pin` | Pin content in group streams | 6 |
| `do_group_mission` | Group mission sidebar | 6 |
| `do_group_language` | Group-level language negotiation | 6 |

## Step 820 — Generate Annotated Screenshots

For each screenshot, annotate key features using numbered callouts:

```bash
mkdir -p docs/groups/feature_tour_drupalorg
```

Use the `generate_image` tool to create annotated versions of each screenshot with numbered callouts and a legend below.

## Step 830 — Review and Publish

```bash
# Verify all images referenced in the markdown exist:
grep -oP '!\[.*?\]\((.*?)\)' docs/groups/feature_tour_drupalorg/FEATURE_TOUR.md | while read -r line; do
  file=$(echo "$line" | grep -oP '\(.*?\)' | tr -d '()')
  if [ ! -f "docs/groups/feature_tour_drupalorg/$file" ]; then
    echo "MISSING: $file"
  fi
done
```

### Schema Changes (Phase 8)

| Type | Name | Notes |
|---|---|---|
| **Directory** | `docs/groups/feature_tour_drupalorg/` | Screenshots + markdown |
| **Document** | `FEATURE_TOUR.md` | 9-section visual walkthrough |
| **Files** | ≥8 PNG screenshots | Bluecheese theme UI captures |

> [!NOTE]
> Phase 8 makes no database or config changes. All output is documentation files committed to git.

## Phase 8 — Verification

- [ ] `feature_tour_drupalorg/` directory created
- [ ] `FEATURE_TOUR.md` document written with all 9 sections
- [ ] ≥8 screenshots captured from bluecheese theme
- [ ] Screenshots annotated with numbered callouts
- [ ] All image files referenced in markdown exist
- [ ] Document accurately describes pl-drupalorg features (not Open Social)
- [ ] Custom modules table matches actual installed modules
- [ ] Content type names correct: `forum` (not `topic`), `community_group` (not `flexible_group`)
- [ ] No references to Open Social-specific features (activity_send_email, socialblue, etc.)
- [ ] Committed to Git

---

# Key Adaptations from Open Social (Reference)

| Aspect | Open Social (pl-opensocial) | Standard Drupal (pl-drupalorg) |
|---|---|---|
| **Group bundle** | `flexible_group` | `community_group` |
| **Entity type** | `group_content` | `group_relationship` (Group 3.x+, incl. 4.x) |
| **Relationship pattern** | `flexible_group-group_node-{bundle}` | `community_group-group_node-{bundle}` |
| **Content types** | `topic`, `event`, `page` | `forum`, `documentation`, `event`, `post`, `page` |
| **Admin role** | `sitemanager` | `site_moderator` |
| **Module dependency** | `social_group:social_group` | `drupal:group` |
| **Theme** | `socialblue` | `bluecheese` |
| **Theme regions** | `complementary_bottom/top` | `sidebar_first`, `sidebar_second`, `content` |
| **Tags** | `social_tagging` (OS-specific) | `group_tags` vocabulary + `field_group_tags` |
| **Notifications** | `activity_send_email` pipeline | Queue-only — Drupal enqueues; external system delivers |

---

# Custom Modules Summary

| Module | Phase | Difficulty | Status |
|---|---|---|---|
| `do_group_extras` | 2 | 🟡 Medium | Archive, guidelines, moderation |
| `do_multigroup` | 3 | 🔴 High | Multi-group posting |
| `do_discovery` | 4 | 🟢 Low | Hot content scoring |
| `do_notifications` | 5 | 🟢 Low | Subscriptions, event recording |
| `do_profile_stats` | 6 | 🟡 Medium | Contribution stats |
| `do_group_pin` | 6 | 🟡 Medium | Content pinning |
| `do_group_mission` | 6 | 🟢 Low | Mission sidebar |
| `do_group_language` | 6 | 🟡 Medium | Group language switching |
| `do_wiki` | — | 🟢 Low | `[[Title]]` wiki links (evaluate need) |

---

# Cumulative Schema Inventory

Everything added across all 8 phases:

## Entity Types & Bundles

| Entity type | Bundle | Phase | Notes |
|---|---|---|---|
| `group` | `community_group` | 1 | Via `drupal/group ^4` (alpha) |
| `group_role` | `community_group-{admin,member,outsider}` | 1 | 3 roles |
| `group_relationship_type` | `community_group-group_node-{forum,doc,event,post,page}` | 1 | 5 content types |
| `group_relationship_type` | `community_group-group_membership` | 1 | User membership |

## Fields

| Entity | Field | Type | Phase |
|---|---|---|---|
| `group` | `field_group_description` | Text (formatted, long) | 1 |
| `group` | `field_group_visibility` | List (string) | 1 |
| `group` | `field_group_image` | Image | 1 |
| `group` | `field_group_type` | Entity reference → taxonomy | 2 |
| `group` | `field_group_language` | Language | 6 |
| `node` (5 types) | `field_group_tags` | Entity reference → `group_tags` taxonomy | 3 |
| `user` | `field_notification_frequency` | List (immediate/daily/weekly) | 5 |

## Taxonomies

| Vocabulary | Terms | Phase |
|---|---|---|
| `event_types` | 7 (DrupalCon, Sprint, Camp, etc.) | 1 |
| `group_type` | 5 (Geographical, Working group, etc.) | 2 |
| `tags` | User-created | 3 |

## Views

| View | Path | Phase |
|---|---|---|
| `groups` | `/groups` | 1 |
| `all_groups` | `/all-groups` | 2 |
| `pending_groups` | `/admin/groups/pending` | 2 |
| `group_content_stream` | (group context) | 3 |
| `group_events` | (group context) | 3 |
| `group_members` | (group context) | 3 |
| `tags_aggregation` | `/tags` | 3 |
| `hot_content` | `/hot` | 4 |
| `promoted_content` | `/admin/content/promoted` | 4 |
| `group_rss_feed` | `/group/{gid}/rss.xml` | 4 |

## Flags

| Flag ID | Scope | Entity | Phase |
|---|---|---|---|
| `promote_homepage` | Global | node | 4 |
| `rsvp_event` | Per-user | node | 4 |
| `follow_content` | Per-user | node | 5 |
| `follow_user` | Per-user | user | 5 |
| `follow_term` | Per-user | taxonomy_term | 5 |
| `mute_group_notifications` | Per-user | group | 5 |
| `pin_in_group` | Global | node | 6 |

## Custom DB Tables

| Table | Columns | Phase |
|---|---|---|
| `do_discovery_hot_score` | `nid` INT PK, `score` FLOAT, `computed` INT | 4 |
| `node_counter` | `nid`, `totalcount`, `daycount`, `timestamp` (via `statistics`) | 4 |

## Custom Routes

| Path | Controller | Phase |
|---|---|---|
| `/user/{user}/events/ical` | `IcalController::userEvents` | 4 |
| `/group/{group}/events/ical` | `IcalController::groupEvents` | 4 |
| `/upcoming-events/ical` | `IcalController::siteEvents` | 4 |
| `/user/{uid}/notification-settings` | `NotificationSettingsController::page` | 5 |
| `/user/{uid}/notification-settings/cancel` | `CancelAllSubscriptionsForm` | 5 |

## Block Placements

| Block ID | Region | Theme | Phase |
|---|---|---|---|
| `do_contribution_stats` | `content` | `bluecheese` | 6 |
| `do_profile_completeness` | `content` | `bluecheese` | 6 |
| `do_group_mission` | `sidebar_first` | `bluecheese` | 6 |

## Languages (Phase 6)

15 total: `en` (default) + `de`, `es`, `fr`, `it`, `ja`, `ko`, `nl`, `pl`, `pt-br`, `ru`, `tr`, `uk`, `zh-hans`, `ar`

## Contributed Modules

| Package | Module(s) enabled | Phase |
|---|---|---|
| `drupal/group` | `group`, `gnode` | 1 |
| `drupal/linkit` | `linkit` | 1 (optional) |
| `drupal/flag` | `flag`, `flag_count` | 4 |
| `drupal/statistics` | `statistics` | 4 |
| `drupal/search_api` | `search_api` | 7 (optional) |
| `drupal/search_api_solr` | `search_api_solr` | 7 (optional) |
| `drupal/message` | `message` | 5 (optional) |
| `drupal/message_notify` | `message_notify` | 5 (optional) |
| `drupal/asset_injector` | `asset_injector` | 7 |
| (core) | `language`, `locale`, `interface_translation`, `config_translation`, `content_translation` | 6 |

## Custom Modules

| Module | Phase | Source |
|---|---|---|
| `do_group_extras` | 2 | Ported from Open Social |
| `do_wiki` | 2 | Ported (optional) |
| `do_multigroup` | 3 | Ported from Open Social |
| `do_discovery` | 4 | Ported from Open Social |
| `do_notifications` | 5 | Ported from Open Social |
| `do_profile_stats` | 6 | Ported (major rewrite: profile→user) |
| `do_group_pin` | 6 | Ported from Open Social |
| `do_group_mission` | 6 | Ported from Open Social |
| `do_group_language` | 6 | Ported from Open Social |
