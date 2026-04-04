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
> **Prefer contributed modules over custom code.** Before porting any custom module from Open Social, search [drupal.org/project](https://www.drupal.org/project/project_module) for an existing contributed module that provides the same functionality. Only write custom code when no suitable contributed module exists or when the contributed options are unmaintained / incompatible with Drupal 10 + Group 3.x. Document the search results and rationale for each decision.

> [!IMPORTANT]
> **Copy module source before enabling.** Custom module source files live in `docs/groups/modules/`. Before running `ddev drush en <module>` for any `do_*` module, copy its directory into `web/modules/custom/`:
> ```bash
> cp -r docs/groups/modules/do_group_extras web/modules/custom/
> ```
> Each phase notes which module to copy. Do this before the `ddev drush en` command for that module.

> [!IMPORTANT]
> **All custom modules must follow the Services over Hooks pattern.** Before writing or porting any custom module, read [`ai_guidance/drupal/BEST_PRACTICES.md`](../../ai_guidance/drupal/BEST_PRACTICES.md). Procedural hook-based modules are not acceptable in this project.

This runbook documents the step-by-step process for adding groups functionality to the standard Drupal 10 codebase (pl-drupalorg).


See [GROUPS_CONVERSION_PLAN.md](GROUPS_CONVERSION_PLAN.md) for the gap analysis, key differences between the projects, and the overall phase plan.


---

## Prerequisites (all phases)

- DDEV running (`ddev status` shows all services healthy)
- Site accessible at `https://drupalorg.ddev.site`
- Git working tree clean on `aa/initial-plan` branch

> [!IMPORTANT]
> Before running any commands or tests, review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) for known gotchas with DDEV, Playwright, Drupal configuration, and process cleanup. Many of the issues documented there (opcode cache staleness, port variability, `networkidle` hangs, config import locks) apply to this project.

---

## Contributed Module Dependencies

All contributed modules referenced in this runbook, verified on drupal.org:

| Module | Composer package | drupal.org | Used in | Notes |
|---|---|---|---|---|
| Group | `drupal/group` | [drupal.org/project/group](https://www.drupal.org/project/group) | Phase 1 | Core dependency; use `^3.0` for `group_relationship` API |
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

**Goal**: Install the Drupal Group module and establish the base group type.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 3, FEATURES.md (removed) Groups section

## Existing Content Types in pl-drupalorg

pl-drupalorg has **30 content types**:

| Category | Content types |
|---|---|
| **Projects** | `project_core`, `project_module`, `project_theme`, `project_theme_engine`, `project_distribution`, `project_drupalorg`, `project_general`, `project_translation`, `project_release` |
| **Contributor Guide** | `contributor_role`, `contributor_skill`, `contributor_task`, `contribution_record` |
| **Content** | `post`, `page`, `documentation`, `guide`, `book_listing`, `section`, `landing_page` |
| **Community** | `event`, `forum`, `casestudy`, `organization`, `hosting_listing` |
| **Admin/Meta** | `changenotice`, `sa` (security advisory), `logo`, `site_template` |

### Content types likely relevant to groups

| Content type | Reason |
|---|---|
| `forum` | Groups.drupal.org groups were essentially discussion forums |
| `documentation` | Working groups produce documentation |
| `event` | Groups organize events |
| `post` | Blog-style posts within a group context |
| `page` | General pages within a group |

> ✅ **Confirmed**: `forum`, `documentation`, `event`, `post`, `page` are the group-postable content types.

## Step 100 — Create Baseline Snapshot

> [!IMPORTANT]
> This must be the **first step** before ANY changes. It captures the pristine state of the site for rollback.

```bash
mkdir -p backups
ddev export-db --file=backups/pre-phase1-$(date +%Y%m%d-%H%M).sql.gz
git add -A && git status
```

Verify the backup file exists and note its filename in the rollback table above.

## Step 110 — Install the Group Module

```bash
ddev composer require drupal/group
```

Standard Drupal Group module (not Open Social's bundled version). API versions:
- **1.x**: `GroupContent` entities (Drupal 8/9)
- **2.x/3.x**: `GroupRelationship` entities (Drupal 10+)

> ✅ **Resolved**: `drupal/group` 3.3.5 is already installed. Requires Drupal `^10.3 || ^11`.

## Step 120 — Enable the Module

```bash
ddev drush en group -y
ddev drush en gnode -y
ddev drush cr
```

> [!IMPORTANT]
> The `gnode` (Group Node) sub-module must be enabled separately. It provides the `group_node:*` relationship type plugins that allow content types to be posted to groups. Without it, no `group_node` plugins are available.

Verify:
```bash
ddev drush pm:list --filter=group --status=enabled
# Should show: group, gnode (both enabled)
```

## Step 130 — Create Group Type

Create the `community_group` group type config entity *(admin UI: `/admin/group/types/add`)*:

| Setting | Value | Notes |
|---|---|---|
| **Label** | Community Group | Matches groups.drupal.org purpose |
| **Machine name** | `community_group` | Used in all config and code |
| **Description** | A community group for collaboration, discussion, and coordination | |
| **Creator is member** | Yes | Creator auto-joins |
| **Creator role** | Group Admin | Creator gets admin role |

### 130a — Create the group type and admin role

```bash
ddev drush php:script docs/groups/scripts/step_120a.php
```

> [!CAUTION]
> **`Group::create()` does NOT auto-join the creator.** The `creator_membership` setting on the group type only applies when groups are created through the form UI. When creating groups programmatically, you must explicitly add the creator as a member:
> ```php
> $group->addMember(\Drupal\user\Entity\User::load($uid));
> ```

### 130b — Add fields to the group type

| Field | Type | Machine name | Required |
|---|---|---|---|
| Description | Text (formatted, long) | `field_group_description` | Yes |
| Visibility | List (text) | `field_group_visibility` | Yes |
| Image | Image | `field_group_image` | No |
| Group Type | Entity reference | `field_group_type` | No (Phase 2) |
| Language | Language | `field_group_language` | No (Phase 6) |

> [!NOTE]
> `field_group_type` and `field_group_language` are created in later phases. Only the first three are created now.

**Script**: [step_120b.php](scripts/step_120b.php) — creates field storage + instances for description, visibility, image

```bash
ddev drush php:script docs/groups/scripts/step_120b.php
ddev drush config:export -y
```

## Step 140 — Configure Group-Node Relationships

Install the following relationship type plugins for `community_group` *(admin UI: `/admin/group/types/manage/community_group/content`)*:

| Plugin | Relationship type ID | What it does |
|---|---|---|
| **Group membership** | `community_group-group_membership` | Users join/leave (installed by default) |
| **Group node (forum)** | `community_group-group_node-forum` | Forum topics posted to group |
| **Group node (documentation)** | `community_group-group_node-doc` | Documentation posted to group |
| **Group node (event)** | `community_group-group_node-event` | Events posted to group |
| **Group node (post)** | `community_group-group_node-post` | Posts posted to group |
| **Group node (page)** | `community_group-group_node-page` | Pages posted to group |

> [!CAUTION]
> **32-character ID limit.** Drupal enforces a maximum of 32 characters for `group_relationship_type` IDs. The full pattern `community_group-group_node-documentation` is 40 characters, so the `documentation` relationship uses the abbreviated ID `community_group-group_node-doc`. All other content type names fit within the limit.

> [!IMPORTANT]
> **Entity property name**: When creating relationship types programmatically, use `content_plugin` (not `plugin_id`) as the property name for the plugin reference.

**Script**: [step_130.php](scripts/step_130.php) — creates 5 relationship types with idempotent checks

```bash
ddev drush php:script docs/groups/scripts/step_130.php
ddev drush config:export -y
```

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

```bash
ddev drush config:export -y
```

## Step 160 — Create Group Listing Page

Create a `groups` View config entity *(admin UI: `/admin/structure/views/add`)*:

| Setting | Value |
|---|---|
| **View name** | Groups |
| **Machine name** | `groups` |
| **Show** | Group (entity type) |
| **Page path** | `/groups` |
| **Format** | Unformatted list of teasers (or table) |

Filters: Published = Yes. Sort: Created, newest first.

Fields (if table format): Group name (linked), Description (trimmed 200), Member count, Created date.

**Config**: [views.view.groups.yml](config/views.view.groups.yml)

Import the config:
```bash
ddev drush config:import -y
```

Verify: navigate to `/groups` — should show an empty list (no groups created yet).

```bash
ddev drush config:export -y
```

## Step 170 — Verify Content Type Field Configuration

> [!IMPORTANT]
> pl-drupalorg already has 30 content types. Before proceeding, verify the group-postable content types have the required fields.

### Required fields per content type

| Content Type | Body field | Attachments | Tags | Comment field |
|---|---|---|---|---|
| `forum` | ✅ Verify | ✅ Verify `field_files` | ✅ Verify `field_tags` | ✅ Verify field name |
| `documentation` | ✅ Verify | ✅ Verify `field_files` | ✅ Verify `field_tags` | ✅ Verify field name |
| `event` | ✅ Verify | ✅ Verify `field_files` | ✅ Verify `field_tags` | ✅ Verify field name |
| `post` | ✅ Verify | ✅ Verify `field_files` | ✅ Verify `field_tags` | ✅ Verify field name |
| `page` | ✅ Verify | ✅ Verify `field_files` | ✅ Verify `field_tags` | ✅ Verify field name |

**Script**: [step_160.php](scripts/step_160.php) — checks body, field_files, field_tags, comment fields, text format, attachment limits

```bash
ddev drush php:script docs/groups/scripts/step_160.php
```

### Text format configuration

> [!NOTE]
> If `full_html` is disabled or `linkit` module is not installed:
> ```bash
> ddev composer require drupal/linkit --no-interaction
> ddev drush en linkit -y
> ```

### Update attachment field limits (if needed)

**Script**: [step_160b.php](scripts/step_160b.php) — sets 15 MB limit and expanded extensions

```bash
ddev drush php:script docs/groups/scripts/step_160b.php
```

## Step 180 — Create `event_types` Taxonomy

> [!IMPORTANT]
> pl-drupalorg's event content type may need an event type classification. The old runbook creates 7 event type terms. Check if this vocabulary already exists:

**Script**: [step_170.php](scripts/step_170.php) — creates vocabulary and 7 terms with idempotent checks

```bash
ddev drush php:script docs/groups/scripts/step_170.php
```

> [!NOTE]
> If the event content type has `field_event_type`, verify its `handler_settings.target_bundles` points to `event_types`.

```bash
ddev drush config:export -y
```

## Step 190 — Phase 1 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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

- [ ] `drupal/group` in `composer.json` and `composer.lock`
- [ ] `drush pm:list --filter=group --status=enabled` shows enabled
- [ ] `community_group` group type exists at `/admin/group/types`
- [ ] Group type has fields: description, visibility, image
- [ ] Test group can be created at `/group/add/community_group`
- [ ] Group-node relationships configured for chosen content types
- [ ] Permissions set for member/admin/outsider
- [ ] Group listing at `/groups` works
- [ ] Content type fields verified (body, field_files, field_tags, comment field) per Step 170
- [ ] `full_html` text format enabled and properly configured
- [ ] Attachment field limits set to 15 MB with expanded extensions
- [ ] `event_types` vocabulary exists with 7 terms
- [ ] Config exported: `ddev drush config:status` clean
- [ ] `ddev drush cr` — no PHP errors
- [ ] Nightwatch tests pass: `composer nightwatch`

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
2. ~~Which version of `drupal/group`?~~ ✅ Resolved: 3.3.5 already installed
3. ~~Group creation — open to all authenticated users, or restricted role?~~ ✅ Confirmed: any authenticated user can create groups

---

# Phase 2 — Group Types & Membership Models

**Goal**: Configure group types, membership models, group directory, and the `do_group_extras` module.

**Source**: IMPLEMENTATION_PLAN.md (removed) Phase 3

## Pre-Phase 2 Snapshot

```bash
ddev export-db --file=backups/pre-phase2-$(date +%Y%m%d-%H%M).sql.gz
```

## Step 200 — Create `group_type` Taxonomy Vocabulary

Create the `group_type` taxonomy vocabulary config entity *(admin UI: `/admin/structure/taxonomy/add`)*:

| Setting | Value |
|---|---|
| **Name** | Group Type |
| **Machine name** | `group_type` |


**Script**: [step_200.php](scripts/step_200.php) — creates vocabulary and 5 terms with idempotent checks

```bash
ddev drush php:script docs/groups/scripts/step_200.php
```

> [!NOTE]
> Terms live in the database, not config YAML. Tids will vary per environment.

```bash
ddev drush config:export -y
```

## Step 210 — Add `field_group_type` to Group Entity

Add a `field_group_type` field to `community_group` *(admin UI: `/admin/group/types/manage/community_group/fields/add`)*:

| Setting | Value |
|---|---|
| **Field type** | Entity reference |
| **Label** | Group Type |
| **Machine name** | `field_group_type` |
| **Reference type** | Taxonomy term |
| **Vocabulary** | Group Type (`group_type`) |
| **Required** | No |
| **Cardinality** | 1 |

**Script**: [step_220.php](scripts/step_220.php) — creates field storage and instance with idempotent checks

```bash
ddev drush php:script docs/groups/scripts/step_220.php
```

> [!IMPORTANT]
> Verify `handler_settings.target_bundles` is set to `group_type` in the YAML. Mismatch causes empty dropdowns.

```bash
ddev drush config:export -y
```

## Step 220 — Configure Membership Models

The `field_group_visibility` field controls join behaviour:

| Membership model | `field_group_visibility` value | Join behaviour |
|---|---|---|
| **Open** | `open` | Instant join |
| **Moderated** | `moderated` | Request → approval |
| **Invite Only** | `invite_only` | Admin-managed |

Group-level permissions for each model are configured at `/admin/group/types/manage/community_group/permissions`:

| Permission | Member | Admin | Outsider |
|---|---|---|---|
| Join group | — | — | ✅ (open groups) |
| Request group membership | — | — | ✅ (moderated groups) |
| Administer members | ❌ | ✅ | ❌ (invite-only) |

```bash
ddev drush config:export -y
```

## Step 230 — Build Group Directory View

Create an `all_groups` View config entity *(admin UI: `/admin/structure/views/add`)*:

| Setting | Value |
|---|---|
| **View name** | All Groups |
| **Machine name** | `all_groups` |
| **Show** | Group (entity type) |
| **Page path** | `/all-groups` |
| **Format** | Unformatted list |

**Fields**: Group name (linked), Description (trimmed 200), Created date.

**Exposed filters**: Keyword search (title contains).

**Non-exposed filters**: Published = Yes.

**Sort**: Created, newest first.

**Config**: [views.view.all_groups.yml](config/views.view.all_groups.yml)

```bash
ddev drush config:import -y
```

Verify: navigate to `/all-groups` — should show an empty directory with a search filter.

```bash
ddev drush config:export -y
```

Archived groups appear only when "Archive" is selected from the Group Type filter.

```bash
ddev drush config:export -y
```

## Step 240 — Enable `do_group_extras` Module

Archive enforcement, submission guidelines, and moderation defaults for groups.

**Source files** (already in repo):
- [do_group_extras.info.yml](modules/do_group_extras/do_group_extras.info.yml)
- [do_group_extras.module](modules/do_group_extras/do_group_extras.module) — hooks for archive, guidelines, moderation
- [do_group_extras.libraries.yml](modules/do_group_extras/do_group_extras.libraries.yml)
- [css/do_group_extras.css](modules/do_group_extras/css/do_group_extras.css)

### Enable

> [!CAUTION]
> **Order of operations matters.** You must `config:export` after enabling a new module BEFORE running `config:import` to add View YAML. If you add View YAML to `config/sync/` and run `config:import` before exporting the module's extension entry, the import will **uninstall the module** because the sync directory doesn't know about it yet.

```bash
# Step 1: Enable module
ddev drush en do_group_extras -y
# Step 2: Restart to flush opcode cache (required for new modules with plugin classes)
ddev restart
# Step 3: Export config — this captures the module in core.extension.yml
ddev drush config:export -y
# Step 4: NOW it's safe to add View YAML files and run config:import
ddev drush cr
```

Module hooks:
- `hook_form_alter()` — submission guidelines on group form; blocks content creation in archived groups
- `hook_entity_presave()` — non-admin groups default to unpublished
- `hook_entity_insert()` — queues notification for `site_moderator` users when group is pending (actual email delivery is handled by an external system)
- `hook_preprocess_group()` — "Archived" badge
- `hook_node_access()` — denies content creation in archive groups

## Step 250 — Create Pending Groups View

Create a `pending_groups` View config entity *(admin UI: `/admin/structure/views/add`)*:

| Setting | Value |
|---|---|
| **View name** | Pending Groups |
| **Machine name** | `pending_groups` |
| **Show** | Group (entity type) |
| **Page path** | `/admin/groups/pending` |
| **Format** | Table |
| **Access** | Permission: `administer group` |

**Fields**: Group name (linked), Author, Created date.

**Filters**: Published = No (shows only unpublished/pending groups).

**Sort**: Created, oldest first (FIFO moderation queue).

**Config**: [views.view.pending_groups.yml](config/views.view.pending_groups.yml)

> [!IMPORTANT]
> Only run `config:import` for this View **after** you have exported `do_group_extras` in Step 240. Otherwise the import will uninstall the module.

```bash
ddev drush config:import -y
```

Verify: navigate to `/admin/groups/pending` — should show an empty table (requires `administer group` permission).

```bash
ddev drush config:export -y
```

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
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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

1. Does `drupal/group` 3.x natively support request/approval, or require `group_membership_request` sub-module? ⏳ **Deferred** — will research the installed Group 3.3.5 code when we reach this step
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

Edit each relationship type config to set `entity_cardinality: 0` *(admin UI: `/admin/group/types/manage/community_group/content`)*:

| Relationship type | Entity cardinality → |
|---|---|
| `community_group-group_node-forum` | 0 (unlimited) |
| `community_group-group_node-doc` | 0 (unlimited) |
| `community_group-group_node-event` | 0 (unlimited) |
| `community_group-group_node-post` | 0 (unlimited) |
| `community_group-group_node-page` | 0 (unlimited) |

> [!IMPORTANT]
> Note the `doc` abbreviation for the documentation relationship type — this was established in Step 140 due to the 32-character ID limit.

> [!CAUTION]
> **Breaking change**: `entity_cardinality: 0` allows a node to belong to unlimited groups. Ensure all group stream Views use `distinct: true`.

**Script**: [step_300.php](scripts/step_300.php) — sets `entity_cardinality=0` on all 5 relationship types

> [!CAUTION]
> **`ddev drush cim` resets cardinality.** Importing View YAML in Steps 310–320 will overwrite the relationship type configs with their YAML defaults, resetting `entity_cardinality` back to 1. You **must** re-run this script after ALL `cim` imports in Phase 3:
> ```bash
> # Run FIRST to set cardinality:
> ddev drush php:script docs/groups/scripts/step_300.php
> # ... do all Steps 310-340 imports ...
> # Re-run LAST to fix cardinality:
> ddev drush php:script docs/groups/scripts/step_300.php
> ddev drush config:export -y
> ```

```bash
ddev drush php:script docs/groups/scripts/step_300.php
ddev drush config:export -y
```

## Step 310 — Build Group Stream Views

### Group Content Stream

| Setting | Value |
|---|---|
| **View name** | Group Content Stream |
| **Machine name** | `group_content_stream` |
| **Show** | Content (nodes) |
| **Format** | Unformatted list of teasers |

**Relationship**: Group relationship → Entity type = Content, Group type = Community Group.

**Contextual filter**: Group ID from URL.

**Filters**: Published = Yes, Content type = forum, documentation, event, post, page.

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

> [!IMPORTANT]
> This is the **homepage equivalent** of Open Social's "continuous post wall" — a reverse-chronological feed of all group content with the last 2-3 comments shown inline on each card. In Open Social this is powered by `Activity` entities; in standard Drupal we build it as a View of nodes sorted by last activity.

### 320a — Create `stream_card` view mode

The stream card view mode controls how each node renders in the activity stream: author info, trimmed body, group badge, tags, and inline comments.

**Script**: [step_315.php](scripts/step_315.php) — creates view mode, enables it for all 5 content types, configures field displays (body, author, date, tags, comments)

> [!CAUTION]
> **Must run before importing `activity_stream` view.** The activity stream view depends on the `stream_card` view mode. Either run this script first, or import [core.entity_view_mode.node.stream_card.yml](config/core.entity_view_mode.node.stream_card.yml) before importing the view:
> ```bash
> cp docs/groups/config/core.entity_view_mode.node.stream_card.yml config/sync/
> ddev drush cim -y
> ```

```bash
ddev drush php:script docs/groups/scripts/step_315.php
ddev drush config:export -y
```

### 320b — (Intentional gap — no step 320b)

### 320c — Create the Activity Stream View

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
> Adaptation difficulty: 🔴 High. Deeply uses `group_relationship` API; plugin IDs change with group type.

**Source files** (already in repo):
- [do_multigroup.info.yml](modules/do_multigroup/do_multigroup.info.yml)
- [do_multigroup.module](modules/do_multigroup/do_multigroup.module) — form alter, submit handler, preprocess (283 lines)
- [do_multigroup.libraries.yml](modules/do_multigroup/do_multigroup.libraries.yml)
- [css/do_multigroup.css](modules/do_multigroup/css/do_multigroup.css)

> [!CAUTION]
> **`group_content` → `group_relationship` rename**: In Group 3.x the entity was renamed. The module uses `group_relationship` throughout. Using the old name causes `EntityStorageException`.

Module hooks:
- `hook_form_node_form_alter()` — "Group Audience" fieldset with checkboxes
- `do_multigroup_node_form_submit()` — syncs group_relationship entries post-save
- `hook_preprocess_node()` — "Posted in" (full) and "Cross-posted from" (teaser)
- `hook_page_attachments()` — attaches CSS

### Enable

```bash
ddev drush en do_multigroup -y
ddev restart
ddev drush cr
```

## Step 340 — Enable Tags on Group Content

### Verify tags vocabulary

**Script**: [step_330.php](scripts/step_330.php) — creates `group_tags` vocabulary, `tags` vocabulary (if missing), and `field_group_tags` on all 5 content types

> [!WARNING]
> **Opcode cache after `ddev restart`.** If Step 330 required a `ddev restart`, the PHP opcode cache is cold. The script may report "Created" but the entities may not persist. **Verify** with:
> ```bash
> ddev drush php:eval '\Drupal\field\Entity\FieldStorageConfig::loadByName("node","field_group_tags") ? print "OK" : print "MISSING";'
> ```
> If MISSING, re-run the script:
> ```bash
> ddev drush php:script docs/groups/scripts/step_330.php
> ```

```bash
ddev drush php:script docs/groups/scripts/step_330.php
ddev drush config:export -y
```

## Step 350 — Tags Aggregation View

| Setting | Value |
|---|---|
| **View name** | Tags |
| **Machine name** | `tags_aggregation` |
| **Show** | Content |
| **Page path** | `/tags/[term-name]` (contextual argument) |

**Contextual filter**: Taxonomy term name from URL, vocabulary = Group Tags. **Sort**: Created, newest first.

```bash
ddev drush config:export -y
```

## Step 360 — Phase 3 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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
web/modules/custom/do_multigroup/
├── css/do_multigroup.css
├── do_multigroup.info.yml
├── do_multigroup.libraries.yml
└── do_multigroup.module
```

### Open Questions (Phase 3)

1. Does Group 3.x use `group_relationship_type.{group_type}-group_node-{bundle}.yml` format? ⏳ **Deferred** — verify from exported config at implementation time
2. ~~Should all 5 content types support multi-group posting, or only a subset?~~ ✅ Confirmed: all 5 (`forum`, `documentation`, `event`, `post`, `page`)
3. ~~Do any content types already have a tags field?~~ ✅ Resolved: `documentation` and `guide` already have `field_tags` → sitewide `tags` vocabulary. Groups will use a **separate** `group_tags` vocabulary with `field_group_tags` field.
4. Does Group 3.x still provide `group.membership_loader` service? ⏳ **Deferred** — check installed code at implementation time

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

## Step 400 — Enable Statistics Module

```bash
ddev drush en statistics -y
ddev drush cr
```

Statistics provides the `node_counter` table with page view counts, which feeds the hot content scoring formula.

> [!WARNING]
> **Deprecation**: `statistics` was deprecated in Drupal 10.3.0 and removed from core in 11.0.0. On Drupal 10.3+ or 11.x, install from contrib instead:
> ```bash
> ddev composer require drupal/statistics
> ddev drush en statistics -y
> ```
> See [drupal.org/project/statistics](https://www.drupal.org/project/statistics).

Verify:
```bash
ddev drush pm:list --filter=statistics --status=enabled
```

## Step 410 — Install Flag Module

```bash
ddev composer require drupal/flag
ddev drush en flag flag_count -y
ddev drush cr
```

Flag provides the flagging API used for "Promote to homepage", content following, and content pinning (Phase 6).

Verify:
```bash
ddev drush pm:list --filter=flag --status=enabled
```

## Step 420 — Enable `do_discovery` Module

Hot content scoring, iCal feeds, promoted content, and per-group RSS.

**Source files** (already in repo):
- [do_discovery.info.yml](modules/do_discovery/do_discovery.info.yml)
- [do_discovery.install](modules/do_discovery/do_discovery.install) — creates `do_discovery_hot_score` table
- [do_discovery.module](modules/do_discovery/do_discovery.module) — `hook_cron()` for score computation, `hook_views_data()`, `hook_node_insert()`
- [do_discovery.routing.yml](modules/do_discovery/do_discovery.routing.yml) — iCal endpoints
- [IcalController.php](modules/do_discovery/src/Controller/IcalController.php) — site/group/user iCal feeds (212 lines)
- [flag.flag.promote_homepage.yml](modules/do_discovery/config/install/flag.flag.promote_homepage.yml) — bundled flag config

**Hot score formula**: `Score = (comment_count × 3) + (view_count × 0.5)`

**Database table**: `do_discovery_hot_score` (columns: `nid`, `score`, `computed`)

**iCal routes**:

| Route | Path |
|---|---|
| Site events | `/upcoming-events/ical` |
| Group events | `/group/{group}/events/ical` |
| User events | `/user/{user}/events/ical` |

> [!CAUTION]
> **`loadUserEvents()` rewrite**: Open Social's `event_enrollment` entity does not exist in standard Drupal. The controller uses Flag module's `rsvp_event` flag to find events the user has RSVP'd to.

> [!NOTE]
> **Event date field**: pl-drupalorg uses `field_date_of_event` (not `field_event_date`). The controller has been adapted for this.

### Enable

```bash
ddev drush en do_discovery -y
ddev restart
ddev drush cr
```

> [!CAUTION]
> `ddev restart` required after enabling custom modules with controller classes (`src/Controller/`) to flush the PHP opcode cache.

Verify the database table was created:
```bash
ddev drush sqlq "SHOW TABLES LIKE 'do_discovery%'"
```
Expected: `do_discovery_hot_score`

## Step 430 — Create RSVP Flag (for iCal user events)

If event enrollment via Flag is the chosen approach (see Step 420e), create an RSVP flag:

Create an `rsvp_event` flag config entity *(admin UI: `/admin/structure/flags/add`)*:

| Setting | Value |
|---|---|
| **Label** | RSVP for event |
| **Machine name** | `rsvp_event` |
| **Flag type** | Content (Node) |
| **Bundles** | Event only |
| **Global** | No (per-user flagging) |
| **Flag short** | RSVP |
| **Unflag short** | Cancel RSVP |

Grant flag/unflag permissions to `authenticated` role.

```bash
ddev drush config:export -y
```

## Step 440 — Grant Flag Permissions

Grant promote_homepage flag permissions to appropriate roles:

```bash
ddev drush role:perm:add content_administrator "flag promote_homepage,unflag promote_homepage"
ddev drush role:perm:add site_moderator "flag promote_homepage,unflag promote_homepage"
```

> [!NOTE]
> pl-drupalorg uses `content_administrator` and `site_moderator` roles, not Open Social's `contentmanager` and `sitemanager`.

```bash
ddev drush config:export -y
```

## Step 450 — Hot Content View

Create a `hot_content` View config entity *(admin UI: `/admin/structure/views/add`)*:

| Setting | Value |
|---|---|
| **View name** | Hot Content |
| **Machine name** | `hot_content` |
| **Show** | Content |
| **Page path** | `/hot` |
| **Format** | Unformatted list of teasers |

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

Create a View:

| Setting | Value |
|---|---|
| **View name** | Promoted Content |
| **Machine name** | `promoted_content` |
| **Show** | Content |
| **Page display** | `/admin/content/promoted` (admin management page) |
| **Block display** | Front page promoted content block |

### Page Display (admin)

**Relationship**: Flag — `promote_homepage` (required = yes, limits to flagged nodes).

**Fields**: Title (linked), Content type, Flagged date, Author, Operations.

**Sort**: Flagged date descending.

**Access**: Permission `flag promote_homepage`.

### Block Display (front page)

**Relationship**: Same flag relationship.

**Fields**: Title (linked), Teaser body (trimmed 200), Created date.

**Sort**: Flagged date descending.

**Items to display**: 5.

**Block visibility**: Front page only (or configure in block placement).

```bash
ddev drush config:export -y
```

## Step 470 — Group RSS Feed

Create a View:

| Setting | Value |
|---|---|
| **View name** | Group RSS Feed |
| **Machine name** | `group_rss_feed` |
| **Show** | Content |

### Default Display

**Relationship**: Group relationship (entity type = Content, group type = Community Group).

**Contextual filter**: Group ID (`gid`), from URL.

**Fields**: Title, Created date.

**Filter criteria**: Published = Yes.

**Sort**: Created descending.

### Feed Display

| Setting | Value |
|---|---|
| **Display type** | Feed |
| **Path** | `/group/%/stream/feed` |
| **Row style** | Content (RSS) |
| **Sitename title** | Yes |

**Contextual filter**: Same `gid` from URL, position 2 (matches `%` in path).

> [!NOTE]
> The Open Social `group_rss_feed` View uses the `group_relationship` Views plugin to join nodes to groups. Verify this plugin exists in your `drupal/group` version. The relationship should join `node_field_data` → `group_relationship_field_data.entity_id` with a contextual filter on `group_relationship_field_data.gid`.

```bash
ddev drush config:export -y
```

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
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

### PHPUnit — Module: `do_discovery`

Create `web/modules/custom/do_discovery/tests/src/Kernel/DiscoveryTest.php`:

- `testModuleEnabled()` — `do_discovery` module enabled; `do_discovery_hot_score` table exists
- `testHotScoreCalculation()` — create node with views/comments, run cron, verify hot score > 0
- `testICalRoute()` — `/group/{gid}/events/ical` route is registered
- `testICalController()` — controller returns valid iCal response for group events


### PHPUnit — Integration (`do_tests`)

Create `web/modules/custom/do_tests/tests/src/Kernel/Phase4Test.php`:

- `testFlagsExist()` — `rsvp_event`, `promote_to_homepage` flags exist
- `testHotContentView()` — `hot_content` View config exists with expected filters
- `testStatisticsEnabled()` — `statistics` module enabled

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
web/modules/custom/do_discovery/
├── config/
│   └── install/
│       └── flag.flag.promote_homepage.yml
├── src/
│   └── Controller/
│       └── IcalController.php    (212 lines, 3 endpoints)
├── do_discovery.info.yml
├── do_discovery.install          (hook_schema — 42 lines)
├── do_discovery.module           (hook_cron, hook_views_data, hook_node_insert — 135 lines)
└── do_discovery.routing.yml      (3 iCal routes — 24 lines)
```

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

1. ~~**Event date field type**~~: ✅ Resolved — keep existing `field_date_of_event` (`datetime`) for current functionality. Use `drupal/smart_date` module for new group event features (provides start+end in one field, all-day toggle, recurring events, better formatting).
2. **Event RSVP mechanism**: Should event RSVP use the Flag module (`rsvp_event` flag), the Registration module (`drupal/registration`), or a custom entity? Flag is simplest and already installed.
3. ~~**Hot content "In my groups" filter**~~: ✅ Confirmed: implement as a custom Views filter plugin.
4. ~~**Statistics module deprecation**~~: ✅ Resolved — deprecation warning added in Step 400. On Drupal ≤10.2, `statistics` is still in core.

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

## Step 510 — Install Flag Module Follow Flags

Flag (from Phase 4) is already installed. Create the follow flags:

### 510a — Follow Content flag

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

**Source files** (already in repo):
- [do_notifications.info.yml](modules/do_notifications/do_notifications.info.yml)
- [do_notifications.module](modules/do_notifications/do_notifications.module) — per-post opt-out checkbox, submit handler
- [do_notifications.routing.yml](modules/do_notifications/do_notifications.routing.yml) — user settings + admin defaults routes
- [do_notifications.links.task.yml](modules/do_notifications/do_notifications.links.task.yml) — "Notifications" tab on user profiles
- [NotificationSettingsController.php](modules/do_notifications/src/Controller/NotificationSettingsController.php) — subscription management page (229 lines)
- [CancelAllSubscriptionsForm.php](modules/do_notifications/src/Form/CancelAllSubscriptionsForm.php) — confirmation form (110 lines)
- [NotificationDefaultsForm.php](modules/do_notifications/src/Form/NotificationDefaultsForm.php) — admin defaults form
- [do_notifications.settings.yml](modules/do_notifications/config/install/do_notifications.settings.yml) — default config

**Bundled flag configs** (in `config/install/`):
- [follow_content.yml](modules/do_notifications/config/install/flag.flag.follow_content.yml)
- [follow_term.yml](modules/do_notifications/config/install/flag.flag.follow_term.yml)
- [follow_user.yml](modules/do_notifications/config/install/flag.flag.follow_user.yml)
- [mute_group_notifications.yml](modules/do_notifications/config/install/flag.flag.mute_group_notifications.yml)

**Routes**:

| Route | Path |
|---|---|
| Notification settings | `/user/{user}/notification-settings` |
| Cancel all | `/user/{user}/notification-settings/cancel-all` |
| Admin defaults | `/admin/config/people/notification-defaults` |

### Enable

```bash
ddev drush en do_notifications -y
ddev restart
ddev drush cr
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

Add a `field_notification_frequency` field to the `user` entity *(admin UI: `/admin/config/people/accounts/fields/add`)*:

| Setting | Value |
|---|---|
| **Field type** | List (text) |
| **Label** | Notification frequency |
| **Machine name** | `field_notification_frequency` |
| **Allowed values** | `immediately\|Immediately`, `daily\|Daily digest`, `weekly\|Weekly digest` |
| **Default value** | Inherited from `do_notifications.settings.default_frequency` |
| **Required** | Yes |

**Script**: [step_520.php](scripts/step_520.php) — creates field storage and instance with idempotent checks

```bash
ddev drush php:script docs/groups/scripts/step_520.php
```

The per-user settings page (`/user/{uid}/notification-settings`) displays this field alongside the subscription management UI. When the user has not explicitly set a preference, the admin default is used.

```bash
ddev drush config:export -y
```

## Step 550 — Implement Notification Event Recording

> [!IMPORTANT]
> These hooks go in `do_notifications.module`. They only record what happened — **no follower lookups, no suppression checks, no email.**

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
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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
web/modules/custom/do_notifications/
├── config/
│   └── install/
│       ├── do_notifications.settings.yml        (admin defaults: frequency, auto-subscribe)
│       ├── flag.flag.follow_content.yml
│       ├── flag.flag.follow_user.yml
│       ├── flag.flag.follow_term.yml
│       └── flag.flag.mute_group_notifications.yml
├── src/
│   ├── Controller/
│   │   └── NotificationSettingsController.php  (229 lines, subscription management)
│   └── Form/
│       ├── CancelAllSubscriptionsForm.php      (110 lines, confirm form)
│       └── NotificationDefaultsForm.php        (admin defaults config form)
├── do_notifications.info.yml
├── do_notifications.links.task.yml             (user profile tab)
├── do_notifications.module                     (form alter + event recording)
└── do_notifications.routing.yml                (3 routes: settings, cancel, admin defaults)
```

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

> [!IMPORTANT]
> Adaptation difficulty: 🟡 Medium. ContributionStatsBlock has one bundle name change (`topic` → `forum`). ProfileCompletenessBlock was rewritten to use `user` entity instead of Open Social's `profile` entity.

**Source files** (already in repo):
- [do_profile_stats.info.yml](modules/do_profile_stats/do_profile_stats.info.yml)
- [do_profile_stats.module](modules/do_profile_stats/do_profile_stats.module) — theme hooks and CSS attachment (42 lines)
- [do_profile_stats.libraries.yml](modules/do_profile_stats/do_profile_stats.libraries.yml)
- [ContributionStatsBlock.php](modules/do_profile_stats/src/Plugin/Block/ContributionStatsBlock.php) — counts topics, events, comments, groups, days active
- [ProfileCompletenessBlock.php](modules/do_profile_stats/src/Plugin/Block/ProfileCompletenessBlock.php) — profile completion percentage
- [pl-contribution-stats.html.twig](modules/do_profile_stats/templates/pl-contribution-stats.html.twig)
- [pl-profile-completeness.html.twig](modules/do_profile_stats/templates/pl-profile-completeness.html.twig)
- [do_profile_stats.css](modules/do_profile_stats/css/do_profile_stats.css)

> [!WARNING]
> **Profile completeness fields**: The `$fields_to_check` array in `ProfileCompletenessBlock.php` must be finalized after research (600e-pre). Current placeholder list may not match actual user fields.

### 600h — Place blocks in bluecheese

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
'
```

### Enable

```bash
ddev drush en do_profile_stats -y
ddev restart
ddev drush cr
```

```bash
ddev drush config:export -y
```

## Step 610 — Enable `do_group_pin` Module

Content pinning within groups. Allows group managers to pin topics above the chronological stream.

> [!IMPORTANT]
> Adaptation difficulty: 🟡 Medium. The Views query alter targets `group_content_stream` (was `group_topics` in Open Social).

**Source files** (already in repo):
- [do_group_pin.info.yml](modules/do_group_pin/do_group_pin.info.yml)
- [do_group_pin.module](modules/do_group_pin/do_group_pin.module) — preprocess for pin badge, Views query alter for pin sorting (106 lines)
- [do_group_pin.libraries.yml](modules/do_group_pin/do_group_pin.libraries.yml)
- [css/do_group_pin.css](modules/do_group_pin/css/do_group_pin.css)
- [flag.flag.pin_in_group.yml](modules/do_group_pin/config/install/flag.flag.pin_in_group.yml) — bundled flag config

Module hooks:
- `hook_page_attachments()` — attaches CSS library globally
- `hook_preprocess_node()` — adds `pinned` variable and `node--pinned` CSS class when flagged
- `hook_views_query_alter()` — LEFT JOINs `flagging` table on `group_content_stream` View for pin-first sorting

> [!CAUTION]
> **View ID must match**: The `hook_views_query_alter()` checks `$view->id()` against a specific View machine name. Open Social uses `group_topics`; pl-drupalorg's equivalent View (from Phase 3 Step 310) is `group_content_stream`. Verify the exact View ID:
> ```bash
> ddev drush config:list | grep views.view.group
> ```

### 610c — Create `pin_in_group` flag config

Create `web/modules/custom/do_group_pin/config/install/flag.flag.pin_in_group.yml`:

```yaml
langcode: en
status: true
dependencies:
  module:
    - node
id: pin_in_group
label: 'Pin in group'
bundles: {}
entity_type: node
global: true
weight: 0
flag_short: 'Pin in group'
flag_long: 'Pin this content to the top of the group stream'
flag_message: 'Content pinned'
unflag_short: Unpin
unflag_long: 'Remove pin from this content'
unflag_message: 'Content unpinned'
unflag_denied_text: ''
flag_type: 'entity:node'
link_type: reload
flagTypeConfig:
  show_in_links: {}
  show_as_field: true
  show_on_form: false
  show_contextual_link: false
linkTypeConfig: {}
```

> [!NOTE]
> The flag is `global: true` (any user with permission can pin/unpin). Grant permissions only to group admins and site moderators:
> ```bash
> ddev drush role:perm:add site_moderator "flag pin_in_group,unflag pin_in_group"
> ddev drush role:perm:add content_administrator "flag pin_in_group,unflag pin_in_group"
> ```

### 610d — CSS, libraries

Create `web/modules/custom/do_group_pin/do_group_pin.libraries.yml`:

```yaml
do_group_pin:
  version: 1.x
  css:
    theme:
      css/do_group_pin.css: {}
```

Create `web/modules/custom/do_group_pin/css/do_group_pin.css`:

- [css/do_group_pin.css](modules/do_group_pin/css/do_group_pin.css) — generic `.pin-badge` and `.node--pinned` styles

### Enable

```bash
ddev drush en do_group_pin -y
ddev restart
ddev drush cr
```

```bash
ddev drush config:export -y
```

## Step 620 — Enable `do_group_mission` Module

Group mission statement sidebar block. Displays a summary of the group description on all group pages.

> [!IMPORTANT]
> Adaptation difficulty: 🟢 Low. Block plugin is entirely Drupal-generic. Only block *placement* needs the bluecheese region name.

**Source files** (already in repo):
- [do_group_mission.info.yml](modules/do_group_mission/do_group_mission.info.yml)
- [GroupMissionBlock.php](modules/do_group_mission/src/Plugin/Block/GroupMissionBlock.php) — context-aware block (129 lines)

Features:
- Context-aware (works via block context or route parameter)
- Reads `field_group_description`, truncates to 300 chars
- Graceful fallback when group or description missing
- Cache tags from group entity

> [!NOTE]
> **Prerequisite**: The group entity must have a `field_group_description` field. This should have been created in Phase 1 when setting up the `community_group` type. Verify:
> ```bash
> ddev drush php:eval '
> $field = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_description");
> echo $field ? "field_group_description exists\n" : "field_group_description MISSING\n";
> '
> ```

### 620c — Place block in bluecheese sidebar

**Script**: [step_620c.php](scripts/step_620c.php) — places block in `sidebar_first` with `/group/*` visibility

```bash
ddev drush php:script docs/groups/scripts/step_620c.php
```

> [!NOTE]
> Open Social places this block in the `complementary_bottom` region (socialblue theme). bluecheese uses `sidebar_first` for equivalent sidebar content.

### Enable

```bash
ddev drush en do_group_mission -y
ddev restart
ddev drush cr
```

```bash
ddev drush config:export -y
```

## Step 630 — Enable `do_group_language` Module

> **Decision needed**: Is group-level language switching needed for drupal.org groups?

> [!IMPORTANT]
> Adaptation difficulty: 🟢 Low. The language negotiation plugin is entirely Drupal-generic. **No code changes needed.**

**Source files** (already in repo):
- [do_group_language.info.yml](modules/do_group_language/do_group_language.info.yml)
- [do_group_language.services.yml](modules/do_group_language/do_group_language.services.yml) — registers plugin as negotiation method
- [LanguageNegotiationGroup.php](modules/do_group_language/src/Plugin/LanguageNegotiation/LanguageNegotiationGroup.php) — negotiation plugin (67 lines)

Plugin logic:
1. Parses `$request->getPathInfo()` with regex `#^/group/(\d+)#` to extract group ID
2. Loads the group entity
3. Reads `field_group_language` value
4. Returns the langcode (falls through if `und` or `zxx`)
5. Weight: 5 (runs before URL/session negotiation but after browser)

> [!IMPORTANT]
> **Why raw path parsing?** Language negotiation runs **before** Drupal's route matching. `\Drupal::routeMatch()->getParameter('group')` is always NULL at negotiation time. This is by design.

### 630d — Prerequisites: Create `field_group_language`

Check if the field exists, create if missing:

**Script**: [step_630d.php](scripts/step_630d.php) — creates field storage, instance, and form display component

```bash
ddev drush php:script docs/groups/scripts/step_630d.php
```

> [!NOTE]
> The field uses `type: language` (core Language field type), not an entity reference. The `language_override` setting defaults to `und` (undefined), meaning groups inherit the site default unless explicitly set.

After enabling, configure language negotiation order at `/admin/config/regional/language/detection`:
- Set "Group language" weight to 5 (above URL but below Session)

### 630e — Enable (if needed)

```bash
ddev drush en do_group_language -y
ddev drush cr
```

```bash
ddev drush config:export -y
```

## Step 640 — Multilingual Infrastructure

> [!IMPORTANT]
> Multilingual support is **required** for groups (group-level language switching, translated content, multilingual demo data). The `do_group_language` module must be enabled.

### 640a — Enable core translation modules

```bash
ddev drush en language locale interface_translation config_translation content_translation -y
ddev drush cr
```

> [!NOTE]
> Open Social has a `social_language` convenience module that enables all 4 translation modules and grants Site Manager translation permissions in one step. Standard Drupal requires enabling them individually.

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

```bash
ddev drush role:perm:add site_moderator "translate any entity,create content translations,update content translations,delete content translations"
ddev drush role:perm:add content_administrator "translate any entity,create content translations,update content translations,delete content translations"
```

```bash
ddev drush config:export -y
```

## Step 650 — Phase 6 Tests

> [!WARNING]
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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
> Review [TROUBLESHOOTING.md](../../ai_guidance/TROUBLESHOOTING.md) before running tests. Kill zombie processes first: `ddev exec kill-zombies.sh`

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
| **Module** | `language`, `locale`, `interface_translation`, `config_translation`, `content_translation` | Core multilingual stack |

### Custom module files (Phase 6)

```
web/modules/custom/do_profile_stats/
├── css/
│   └── do_profile_stats.css              (97 lines)
├── src/
│   └── Plugin/
│       └── Block/
│           ├── ContributionStatsBlock.php (140 lines — 1 bundle change)
│           └── ProfileCompletenessBlock.php (123 lines — profile→user rewrite)
├── templates/
│   ├── pl-contribution-stats.html.twig   (26 lines)
│   └── pl-profile-completeness.html.twig (20 lines)
├── do_profile_stats.info.yml
├── do_profile_stats.libraries.yml
└── do_profile_stats.module               (42 lines)

web/modules/custom/do_group_pin/
├── config/
│   └── install/
│       └── flag.flag.pin_in_group.yml
├── css/
│   └── do_group_pin.css                  (18 lines)
├── do_group_pin.info.yml
├── do_group_pin.libraries.yml
└── do_group_pin.module                   (106 lines — 1 view ID change)

web/modules/custom/do_group_mission/
├── src/
│   └── Plugin/
│       └── Block/
│           └── GroupMissionBlock.php      (129 lines — no changes)
└── do_group_mission.info.yml

web/modules/custom/do_group_language/
├── src/
│   └── Plugin/
│       └── LanguageNegotiation/
│           └── LanguageNegotiationGroup.php (67 lines — no changes)
├── do_group_language.info.yml
└── do_group_language.services.yml
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

1. **Profile completeness fields**: Which pl-drupalorg user fields should be checked? ⏳ **Deferred** — research step 600e-pre added: investigate current groups.drupal.org and Open Social before deciding.
2. ~~**Contribution stats expansion**~~: ✅ Confirmed: count all 5 group-postable content types (forum, documentation, event, post, page) plus comments, groups, and days active.
3. ~~**Group mission field**~~: ✅ Resolved: `field_group_description` is defined in Step 130 as a required field on `community_group`. The mission block reads from this field.
4. ~~**Group language**~~: ✅ Confirmed: multilingual is **required** for groups. Enable `do_group_language` and add `field_group_language` to the group entity.
5. ~~**Pin permissions**~~: ✅ Confirmed: group admins (`community_group-admin` role) can pin/unpin in their own groups, in addition to site-wide `site_moderator` and `administrator`.

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

## Steps 700–750 — Create Demo Data

**Script**: [step_700_demo_data.php](scripts/step_700_demo_data.php) — comprehensive demo data script (all-in-one)

Creates:
- **6 users**: maria_chen, james_okafor, elena_garcia, ravi_patel, sophie_mueller, alex_novak
- **User profiles**: first/last name on each
- **20 taxonomy tags**: sprint, drupalcon, logistics, core, roadmap, etc.
- **8 groups**: DrupalCon Portland 2026, Drupal France, Core Committers, Thunder Distribution, Leadership Council, Camp Organizers EMEA, Drupal Deutschland, Legacy Infrastructure
- **Memberships**: users assigned to appropriate groups
- **12 forum topics**: distributed across groups with tags
- **5 events**: with future dates
- **6 comments**: threaded on forum topics
- **Flags**: pin (Sprint Planning), promote (2 topics), follow content/term/user
- **RSVP**: event enrollments via `rsvp_event` flag
- **Archive**: Legacy Infrastructure group set to unpublished

All operations are idempotent (safe to re-run).

```bash
ddev drush php:script docs/groups/scripts/step_700_demo_data.php
```

> [!NOTE]
> **Architecture change**: Open Social uses a custom `EventEnrollment` entity type for event signups. pl-drupalorg uses the `rsvp_event` Flag (from Phase 4 Step 430) for the same purpose.

## Step 710 — Multilingual Demo Data

> [!IMPORTANT]
> The multilingual infrastructure was set up in Phase 6 (Step 640), but it must be exercised with demo data. Without this step, the 14 languages, `do_group_language` module, and content translation settings remain untested.

**Script**: [step_760.php](scripts/step_760.php) — sets group languages, creates French/German content, verifies all multilingual data

```bash
ddev drush php:script docs/groups/scripts/step_760.php
```

> [!NOTE]
> Nodes are created with `langcode => "fr"`/`"de"` so they are natively in that language. When visiting `/group/{gid}`, the `do_group_language` plugin switches the interface language.

### 710e — Download locale translation strings

```bash
ddev drush locale:check
ddev drush locale:update
ddev drush cr
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

1. **Profile fields**: Does pl-drupalorg have `field_organization` or equivalent for job title/org?
2. **Tag vocabulary**: What is the correct taxonomy vocabulary machine name for content tags?
3. **Comment field name**: What is the comment field machine name on the `forum` content type?
4. ~~**Event date field type**~~: ✅ Resolved — keep existing `datetime`, use Smart Date for group events.
5. **Group visibility**: How is group access control configured in `community_group` (Group module access plugins vs. custom field)?

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
| 7 | Events listing | `/all-events` or equivalent | Event cards with dates, event type facets |

> [!NOTE]
> `/all-topics` and `/all-events` listing Views are not created in this runbook. Before Phase 8, either create them as new Views (machine names `all_topics` and `all_events`, similar to `all_groups`) or substitute with existing Views (e.g. the activity stream at `/stream` for topics, and the site events iCal-backed page for events). Update the screenshot table above once decided.
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
| Drupal core | 10.x |
| Group module | 3.x |
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
| **Entity type** | `group_content` | `group_relationship` (Group 3.x) |
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
| `group` | `community_group` | 1 | Via `drupal/group ^3.0` |
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
