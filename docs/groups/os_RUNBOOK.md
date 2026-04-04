# Build Log — pl-opensocial

> [!CAUTION]
> **READ FIRST**: Before executing ANY commands in this log, read [os_HANGING_PROCESSES.md](file:///Users/andreangelantoni/Sites/pl-opensocial/documents/os_HANGING_PROCESSES.md). It documents 21 types of hangs encountered during this build — including silent agent approval gates, `networkidle` freezes, PHP opcache stale classes, and DDEV port conflicts. Every one of these has caused lost time.

This log records every change made to the Open Social site across all implementation phases.
All configuration referenced below is exported to `config/sync/` and can be reproduced with `ddev drush cim -y`.
Custom module code lives in `web/modules/custom/`.

Step numbering uses BASIC-style sparse numbering: Phase 1 = 0-90, Phase 2 = 100s, Phase 3 = 300s, Phase 4 = 500s.
Steps increment by 10 to allow inserting new steps without renumbering.

---

## Config Import Method

Phase configs are stored in `config/phaseN/` directories (where N is the phase number). Individual configs are imported via `ddev drush php:eval` because `ddev drush cim --partial` validates ALL active config (not just the partial import), and pre-existing dependency errors block it.

```php
ddev drush php:eval '
$configs = ["config.name.one", "config.name.two"];
foreach ($configs as $name) {
  $yaml = file_get_contents("/var/www/html/config/phaseN/$name.yml");
  $data = \Drupal\Component\Serialization\Yaml::decode($yaml);
  \Drupal::configFactory()->getEditable($name)->setData($data)->save();
  echo "Imported: $name\n";
}
'
```

---

> [!CAUTION]
> **DDEV commands do NOT hang.** All `ddev drush`, `ddev exec`, and `ddev export-db` commands complete in seconds (typically 2-15s). If a terminal session appears "stuck" on a DDEV command, the problem is a **zombie shell session** from a cancelled tool call — not a stuck command. Verify by running `docker ps` to ensure containers are healthy, then re-run the command in a new terminal. Do NOT use the `timeout` command with `ddev` — it sends SIGINT (exit code 130) which kills the process prematurely.

---

# Phase 1 — Clean-Room Initialization

> [!IMPORTANT]
> **DDEV Pre-Flight Check**: Before starting ANY phase:
> 1. **Verify DDEV is running**: `ddev describe` — if it fails, run `ddev start`
> 2. **Stop conflicting projects**: `ddev list` — if `pl-opensocial` (or any other project) is running, stop it: `cd ~/Sites/pl-opensocial && ddev stop`
> 3. **Confirm the port**: Check the HTTPS URL in `ddev describe` output. If the port differs from `8493`, update `tests/playwright.config.ts` to match.
> 4. **Kill zombies**: `bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh`

**Goal**: Establish a fresh Open Social 13.0.0 environment with correct port pinning and private file system configuration.

**Step 010** — Create and enter the project directory
```bash
mkdir pl-opensocial-rework && cd pl-opensocial-rework
ddev config --project-type=drupal10 --docroot=web --php-version=8.3
ddev start
rm -f .DS_Store
```

**Step 020** — Download Open Social via Composer
```bash
ddev composer create-project drupal/recommended-project:^10 . --no-interaction --stability=dev
ddev composer config repositories.asset-packagist '{"type": "composer", "url": "https://asset-packagist.org"}'
ddev composer config allow-plugins.tbachert/spi true
ddev composer config allow-plugins.cweagans/composer-patches true
ddev composer config allow-plugins.oomphinc/composer-installers-extender true
ddev composer config --json extra.enable-patching true
ddev composer require goalgorilla/open_social:^13 drush/drush -W --no-interaction
```
> [!IMPORTANT]
> **Resource Intensive**: Step 020 is heavy on RAM/CPU. If the session crashes or hangs, try `ddev composer install` to resume the download without recalculating the dependency tree.

**Step 030** — Configure the private directory
```bash
mkdir -p private
chmod +w web/sites/default/settings.php
echo "\n\$settings['file_private_path'] = '/var/www/html/private';" >> web/sites/default/settings.php
```
> [!IMPORTANT]
> **Installer Requirement**: The `file_private_path` MUST be set in `settings.php` BEFORE running the site installer in Step 040. If missing, the Open Social installer will fail.

**Step 040** — Site Install and Launch
```bash
ddev drush -y site-install social
ddev drush php:eval 'node_access_rebuild();'
ddev launch
```

---

# Phase 2 — Content Types & Text Formats

> [!IMPORTANT]
> **Zombie cleanup**: Before starting this phase, run the cleanup script:
> ```bash
> bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh
> ```

**Goal**: Configure Topic, Event, and Page content types to match g.d.o's feature set.

> All fields listed below are **Open Social defaults** — no new fields were created.
> Phase 2 work consisted entirely of reconfiguring existing fields and text formats.

> [!IMPORTANT]
> **linkit Dependency**: The `full_html` text format configuration references the `linkit` filter. The `linkit` module is NOT bundled with Open Social and must be installed via Composer first:
> ```bash
> ddev composer require drupal/linkit --no-interaction
> ddev drush en linkit -y
> ```

> [!NOTE]
> **Why it might look "stuck"**:
> 1. **Config Imports**: If a dependency (like a module or another config) is missing, Drush might hang or output cryptic errors. Always enable dependent modules first.
> 2. **Playwright Installation**: `npx playwright install` downloads large browser binaries (~100MB+). It will look stuck while downloading; please allow 1-3 minutes for completion.
> 3. **Playwright Tests**: If browsers aren't installed or the `baseURL` in `playwright.config.ts` is wrong/unreachable (e.g., wrong port), tests will hang until they hit their 30s timeout.

## Topic → Discussion

**Step 100** — Body field + text format: enable Full HTML and Markdown; remove redundant display filters stripping HTML on render
- Config: [filter.format.full_html.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/filter.format.full_html.yml)

**Step 110** — Body field: enable summary/teaser toggle
- Config: [core.entity_form_display.node.topic.default.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/core.entity_form_display.node.topic.default.yml)

**Step 120** — Attachment field: 15 MB limit, expanded extensions (`pdf doc docx xls xlsx ppt pptx txt rtf odt ods odp zip gz tar`)
- Config: [field.field.node.topic.field_files.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/field.field.node.topic.field_files.yml)

**Step 130** — Wiki-links (`[[title]]`) support
- Copy module: `cp -R ~/Sites/pl-opensocial/web/modules/custom/pl_opensocial_wiki web/modules/custom/`
- Enable: `ddev drush en pl_opensocial_wiki -y`
- `ddev drush cr`
- **`ddev restart`** (required to flush PHP opcode cache so the web process can find the new class)

> [!CAUTION]
> The `cp -R` command MUST place the entire directory at `web/modules/custom/pl_opensocial_wiki/` (not flat files in `web/modules/custom/`). Verify with `ls web/modules/custom/pl_opensocial_wiki/src/Plugin/Filter/WikiLinkFilter.php`. If the module is installed with the wrong directory structure, the entire site will crash with `PluginException: class does not exist`.
>
> After enabling the module, you MUST run `ddev restart` to flush the PHP opcode cache. A `ddev drush cr` alone is not sufficient — the web process caches class paths separately from Drush CLI.

## Event Enhancements

**Step 140** — Enable Event Type sub-module and its field
- `ddev drush en social_event_type -y`
- Config: [field.field.node.event.field_event_type.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/field.field.node.event.field_event_type.yml)

> [!IMPORTANT]
> The module MUST be enabled BEFORE importing its field config. If you import the field YAML without the module active, the import will silently fail or error.

> [!CAUTION]
> **target_bundles mismatch**: The imported `field.field.node.event.field_event_type.yml` references `target_bundles: {event_type: event_type}` (singular), but the actual vocabulary machine name is `event_types` (plural). This causes the Event Type dropdown to appear **empty** even with correct terms and permissions. Fix after importing:
> ```bash
> ddev drush php:eval '
> $field = \Drupal\field\Entity\FieldConfig::loadByName("node", "event", "field_event_type");
> $settings = $field->getSetting("handler_settings");
> $settings["target_bundles"] = ["event_types" => "event_types"];
> $field->setSetting("handler_settings", $settings);
> $field->save();
> echo "Fixed: target_bundles now points to event_types\n";
> '
> ```

**Step 145** — Import the Event form display (places event_type, event_managers, and other fields on the Event form)
- Config: [core.entity_form_display.node.event.default.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/core.entity_form_display.node.event.default.yml)

> [!CAUTION]
> This config is REQUIRED. Without it, the Event Type dropdown (`#edit-field-event-type`) will not appear on the Event creation form, causing Test 3 to fail. This config must be imported AFTER enabling `social_event_type` and `social_event_managers`.

**Step 150** — Enable Event Managers sub-module and its field
- `ddev drush en social_event_managers -y`
- Config: [field.field.node.event.field_event_managers.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/field.field.node.event.field_event_managers.yml)

> [!IMPORTANT]
> Same as Step 140: enable the module first, then import config.

**Step 160** — Event attachments: 15 MB limit, expanded extensions
- Config: [field.field.node.event.field_files.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/field.field.node.event.field_files.yml)

**Step 170** — Create Event Type taxonomy terms
- Vocabulary: `event_types` (**plural**) — [taxonomy.vocabulary.event_type.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/taxonomy.vocabulary.event_type.yml)

> [!WARNING]
> `ddev drush term:create` does not exist in the Drush version shipped with Open Social. Use `php:eval` with `Term::create()` instead.

```php
ddev drush php:eval '
foreach ([
  "User group meeting",
  "Drupalcamp or Regional Summit",
  "DrupalCon",
  "Online meeting (e.g. IRC meeting)",
  "Training (free or commercial)",
  "Sprint",
  "Related event (not Drupal-specific)",
] as $name) {
  $term = \Drupal\taxonomy\Entity\Term::create(["vid" => "event_types", "name" => $name]);
  $term->save();
  echo "Created: $name (tid=" . $term->id() . ")\n";
}
'
```

> [!CAUTION]
> The vocabulary machine name is `event_types` (**plural**), NOT `event_type`. Using the wrong vid will silently create orphaned terms that don't appear in any select widget.
> Terms live in the database, not config YAML. Tids may differ from original (5–11).

> [!CAUTION]
> **taxonomy_access_fix**: Open Social ships with the `taxonomy_access_fix` module, which overrides the default entity reference selection handler. Without the `select terms in event_types` permission, the Event Type dropdown will appear **empty** (no options) even though the terms exist in the database. This MUST be granted after creating terms:
> ```bash
> ddev drush role:perm:add authenticated "select terms in event_types"
> ddev drush role:perm:add administrator "select terms in event_types"
> ```

**Step 180** — Verify event_types terms exist
```bash
ddev drush php:eval 'echo count(\Drupal::entityTypeManager()->getStorage("taxonomy_term")->loadByProperties(["vid" => "event_types"])) . " event_types terms\n";'
```
> Expected: 7 event_types terms

## Stability & Infrastructure (Critical)

**Step 182** — Fix "Unexpected Error" (WSOD) on Event pages
If Event pages crash with `SQLSTATE[42S02]: Table 'db.node__field_event_url' doesn't exist`, manually synchronize the storage definition:
```bash
ddev drush php:eval '\Drupal::entityDefinitionUpdateManager()->installFieldStorageDefinition("field_event_url", "node", "node", \Drupal\field\Entity\FieldStorageConfig::loadByName("node", "field_event_url"));'
```

**Step 184** — Restore ALL Frontend Libraries

> [!CAUTION]
> The `socialblue` / `social_base` theme depends on ~30 frontend libraries (Bootstrap JS, diff, jquery.caret, FontAwesome, select2, photoswipe, etc.). Copying only `node-waves` and `autosize` will cause `file_get_contents` warnings on most pages. Copy the **entire** libraries folder from the source project:

```bash
mkdir -p web/libraries
# Copy ALL libraries from source in one shot:
cp -R ~/Sites/pl-opensocial/web/libraries/* web/libraries/
# Verify count (should be ~30):
ls web/libraries/ | wc -l
```

> [!WARNING]
> The `diff` library ships with `diff.js` but **not** `diff.min.js`. Drupal's asset pipeline references the minified filename, causing `file_get_contents` warnings on every page. Fix immediately after copying libraries:

```bash
cp web/libraries/diff/dist/diff.js web/libraries/diff/dist/diff.min.js
```

> [!NOTE]
> These libraries are NOT managed by Composer — they must be tracked in git. Ensure `/web/libraries/` is **not** in `.gitignore`.

**Step 186** — Disable problematic HTML filters
The `markdown` filter in `full_html` can cause HTML escaping issues (rendering `<strong>` as plain text).
- Go to `/admin/config/content/formats/manage/full_html`
- Ensure **Markdown** is UNCHECKED.

**Step 188** — Enable Enrollment & Permissions
- `ddev drush en social_event_an_enroll social_event_max_enroll -y`
- Fix permissions (Note: machine names include spaces):
  - `ddev drush role:perm:add authenticated "add event enrollment entities"`
  - `ddev drush role:perm:add authenticated "manage everything enrollments"`
  - `ddev drush role:perm:add authenticated "view published event enrollment entities"`
  - `ddev drush role:perm:add anonymous "add event enrollment entities"`

> Note: `field_event_url` and `field_event_enroll` are OS defaults and require no config changes.

## Page → Wiki Page

**Step 190** — Edit permissions for authenticated users
- Config: [user.role.authenticated.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/user.role.authenticated.yml)

**Step 200** — Revision log enabled by default
- Config: [node.type.page.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/node.type.page.yml)

**Step 205** — Import the Page form display (places revision log and other fields on the Page form)
- Config: [core.entity_form_display.node.page.default.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/core.entity_form_display.node.page.default.yml)

> [!CAUTION]
> This config is REQUIRED. Without it, the revision log field (`#edit-revision-log-0-value`) will not appear on the Page edit form, causing Test 4 to fail.

**Step 210** — Page attachments: 15 MB limit, expanded extensions
- Config: [field.field.node.page.field_files.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/field.field.node.page.field_files.yml)

**Step 220** — Clear caches after Phase 2 config imports
- `ddev drush cr`

## Phase 2 Tests

> [!IMPORTANT]
> **Test Environment Setup**:
> 1. **Copy Tests**: `cp -r ~/Sites/pl-opensocial/tests ~/Sites/pl-opensocial-rework/tests`
> 2. **Update Config**: Edit `tests/playwright.config.ts`:
>    - Set `baseURL: 'https://pl-opensocial-rework.ddev.site:8493'`.
>    - Set `timeout: 30000` (Global) and `expect: { timeout: 5000 }` (Assertion). These "fail-fast" timeouts prevent long hangs if elements are missing.
> 3. **Install Dependencies**: Run `npm install` in the `tests/` directory.
> 4. **Install Browsers**: Run `npx playwright install chromium`.

> [!CAUTION]
> **`networkidle` causes permanent hangs**: The test `beforeEach` hook MUST use `waitForLoadState('load')`, NOT `waitForLoadState('networkidle')`. Open Social has perpetual background AJAX (heartbeat/polling) that prevents `networkidle` from ever resolving. This single line causes **every test to hang forever**. If tests appear stuck during login, check this setting first.

> [!IMPORTANT]
> **Test Visibility**: Always use `--reporter=list` or `--reporter=line` when running tests to monitor progress. Do NOT suppress output. If a test appears stuck, check the reporter output for the specific step where it is waiting.

> [!NOTE]
> **Open Social 13 Selector Updates**:
> All test selectors in `phase1-content-types.spec.ts` MUST be scoped to `main` (e.g., `page.locator('main h1')`, `page.locator('main button:has-text("Enroll")')`) to avoid matching hidden admin toolbar elements. Without `main` scoping, selectors like `a[href$="/edit"]` will match toolbar links (e.g., "Edit profile") instead of page content.

**Step 230** — Run (from the `tests/` directory): `npx playwright test e2e/phase2-content-types.spec.ts --reporter=list`

---

**Step 240** — Pre-Phase 3 backup: `ddev export-db --file=backups/phase3-pre.sql.gz`

> [!NOTE]
> `ddev export-db` typically completes in **10-15 seconds**. If it appears stuck, check that DDEV containers are healthy with `docker ps`. Do NOT cancel and retry without checking — the export may be running normally.
> Always create the `backups/` directory first: `mkdir -p backups`

# Phase 3 — Group Structure & Membership

> [!IMPORTANT]
> **Zombie cleanup**: Before starting this phase, run the cleanup script:
> ```bash
> bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh
> ```

**Goal**: Configure Open Social's `flexible_group` to replicate g.d.o's group types, membership models, archive enforcement, moderation queue, and submission guidelines.

## Group Type Taxonomy Terms

Vocabulary: `group_type` (Open Social default) — [taxonomy.vocabulary.group_type.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/taxonomy.vocabulary.group_type.yml)

> [!WARNING]
> `ddev drush term:create` does not exist in this Drush version. Use `php:eval` with `Term::create()`.

**Step 300** — Create group_type terms
```php
ddev drush php:eval '
$terms = [
  ["Geographical", "Local user groups by city/region"],
  ["Working group", "Module, feature, or initiative coordination"],
  ["Distribution", "Drupal distribution projects"],
  ["Event planning", "DrupalCon and camp organising"],
  ["Archive", "Inactive groups (read-only)"],
];
foreach ($terms as [$name, $desc]) {
  $term = \Drupal\taxonomy\Entity\Term::create(["vid" => "group_type", "name" => $name, "description" => ["value" => $desc, "format" => "plain_text"]]);
  $term->save();
  echo "Created: $name (tid=" . $term->id() . ")\n";
}
'
```
> Terms live in the database, not config YAML. Tids may differ from original (12–16).

## Membership Models

**Step 310** — No configuration changes needed. Open Social's `flexible_group` already supports all g.d.o models:

| g.d.o model | `field_group_allowed_join_method` | `field_flexible_group_visibility` |
|---|---|---|
| Open | `direct` | `public` |
| Moderated | `request` | `public` or `community` |
| Invite only | `invite` | `secret` |
| Closed | `invite` | `secret` |

## Group Directory

**Step 320** — Modify `newest_groups` view: filter out secret groups by default
- Config: [views.view.newest_groups.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.newest_groups.yml)

## Custom Module: `pl_group_extras`

**Step 330** — Copy module to `web/modules/custom/pl_group_extras/`
- `cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_group_extras web/modules/custom/`
- Contents: `pl_group_extras.info.yml`, `pl_group_extras.module`, `pl_group_extras.libraries.yml`, `css/pl_group_extras.css`

**Step 340** — Enable: `ddev drush en pl_group_extras -y`

Hooks implemented:
- `hook_form_alter()` — blocks content creation in archived groups; injects submission guidelines
- `hook_entity_presave()` — sets non-admin groups to unpublished (moderation queue)
- `hook_entity_insert()` — sends email notification for pending groups
- `hook_preprocess_group()` — adds "Archived" badge
- `hook_node_access()` — denies content creation in archived groups

## Pending Groups View

**Step 350** — Import `pending_groups` view at `/admin/groups/pending`
- Config: [views.view.pending_groups.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.pending_groups.yml)
- Note: the `operations` field was removed after initial deployment due to a broken `entity_operations` plugin. The exported YAML already has this fix.

**Step 360** — Clear caches after Phase 3 config imports
- `ddev drush cr`

## Phase 3 Tests

**Step 370** — Run (from the `tests/` directory): `./node_modules/.bin/playwright test e2e/phase3-groups.spec.ts --reporter=list`
- 13 tests: group creation, directory filtering, archiving, moderation queue, guidelines.

---

**Step 380** — Pre-Phase 4 backup: `ddev export-db --file=backups/phase4-pre.sql.gz`

> [!NOTE]
> Same timing as Step 240 — expect **10-15 seconds**.

# Phase 4 — Content Discovery & Aggregation

> [!IMPORTANT]
> **Zombie cleanup**: Before starting this phase, run the cleanup script:
> ```bash
> bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh
> ```

**Goal**: Implement tags, events calendar, iCal feeds, hot content scoring, promoted content, and RSS feeds.

## Enable Contrib Modules

**Step 500** — `ddev drush en social_tagging -y`

**Step 510** — `ddev drush en statistics -y`

**Step 520** — `ddev drush en flag -y`

**Step 530** — `ddev drush en flag_count -y`

Config: [core.extension.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/core.extension.yml) (lists all enabled modules)

## Tags Aggregation View

**Step 540** — Import `tags_aggregation` view at `/tags`
- Config: [views.view.tags_aggregation.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.tags_aggregation.yml)

## Custom Module: `pl_discovery`

**Step 550** — Copy module to `web/modules/custom/pl_discovery/`
- `cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_discovery web/modules/custom/`

**Step 560** — Enable: `ddev drush en pl_discovery -y`
- Creates DB table `pl_discovery_hot_score` via `hook_schema()`
- Schema: `nid` INT PK, `score` FLOAT, `computed` INT

Hooks:
- `hook_cron()` — recomputes hot scores
- `hook_views_data()` — exposes `pl_discovery_hot_score` to Views
- `hook_node_insert()` — seeds hot score entries

## Hot Content View

**Step 570** — Import `hot_content` view at `/hot`
- Config: [views.view.hot_content.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.hot_content.yml)

## Promoted Content

**Step 580** — Import `promote_homepage` flag
- Config: [flag.flag.promote_homepage.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/flag.flag.promote_homepage.yml)

**Step 590** — Import `promoted_content` view at `/admin/content/promoted`
- Config: [views.view.promoted_content.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.promoted_content.yml)

**Step 600** — Grant flag permissions to `contentmanager` and `sitemanager` roles
- Config: [user.role.contentmanager.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/user.role.contentmanager.yml), [user.role.sitemanager.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/user.role.sitemanager.yml)

## Group RSS Feed

**Step 610** — Import `group_rss_feed` view for group activity RSS
- Config: [views.view.group_rss_feed.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/views.view.group_rss_feed.yml)

**Step 620** — Clear caches after Phase 4 config imports
- `ddev drush cr`

## Phase 4 Tests

**Step 630** — Run (from the `tests/` directory): `./node_modules/.bin/playwright test e2e/phase4-discovery.spec.ts --reporter=list`
- 14 tests: tags, events calendar/iCal, hot content, promoted content, group RSS.

> [!NOTE]
> **Event Type filter**: The `social_event_type` module renders the "What type of events do you want to see?" dropdown on `/community-events` but does NOT populate it with `event_type` taxonomy terms. The select has only "- Any -". The test (`events.filter.type`) handles this by checking the option count before attempting to select.

> [!WARNING]
> **`ddev drush` intermittent SIGINT**: `ddev drush` commands sometimes receive SIGINT (exit code 130) for no apparent reason. Use `ddev exec drush` as a more reliable alternative. Both execute the same Drush binary inside the container.

---

# How to Reproduce This Site

Starting from a fresh environment:

1. **Phase 1 Base**: Follow Steps 010–040. **Password must be `admin`**.
2. **Infrastructure Fixes**: Follow Steps 182–188 (Storage sync, Libraries, HTML filters, Enrollment).
3. **Custom Code**: Copy all modules from `web/modules/custom/` in the source project.
4. **Configuration**:
   ```bash
   ddev drush cim -y
   # If cim fails due to dependencies, use the php:eval method in Step 100+
   ```
5. **Taxonomy & Metadata**:
   ```bash
   ddev drush php:eval '
   foreach (["Geographical", "Working group", "Distribution", "Event planning", "Archive"] as $name) {
     \Drupal\taxonomy\Entity\Term::create(["vid" => "group_type", "name" => $name])->save();
   }
   foreach (["User group meeting", "Drupalcamp or Regional Summit", "DrupalCon", "Online meeting (e.g. IRC meeting)", "Training (free or commercial)", "Sprint", "Related event (not Drupal-specific)"] as $name) {
     \Drupal\taxonomy\Entity\Term::create(["vid" => "event_types", "name" => $name])->save();
   }
   '
   ddev drush cr
   ```
6. **Verification**: Follow Step 230 to run Phase 2 tests.

---

# Schema Inventory

## Entity Fields

> **No custom Drupal fields have been added.** All fields on Topic, Event, Page, and Group are Open Social defaults. Inspect `field.field.*` and `field.storage.*` in `config/sync/`.

## Group Relationship Types (all Open Social defaults)

| Machine name | Plugin | Purpose |
|---|---|---|
| `flexible_group-group_membership` | `group_membership` | User membership |
| `flexible_group-group_node-event` | `group_node:event` | Event nodes in groups |
| `flexible_group-group_node-topic` | `group_node:topic` | Topic nodes in groups |
| `flexible_group-group_invitation` | `group_invitation` | Group invitations |
| `group_content_type_7fcb76fdf61a9` | `group_membership_request` | Membership requests |

---

# Phase 5 — Multi-Group Posting

> [!IMPORTANT]
> **Zombie cleanup**: Before starting this phase, run the cleanup script:
> ```bash
> bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh
> ```

**Goal**: Allow a single Topic or Event to appear in multiple groups simultaneously — the defining feature of g.d.o's posting model. Uses the Group module's native `group_relationship` system rather than a separate entity reference field.

## Pre-Phase Backup

**Step 700** — Pre-Phase backup
```bash
mkdir -p backups
ddev export-db --file=backups/phase5-pre.sql.gz
```

## Configuration Changes

**Step 710** — Copy and import config files for unlimited group relationships per node:

```bash
cp ~/Sites/pl-opensocial/config/sync/group.content_type.flexible_group-group_node-topic.yml config/sync/
cp ~/Sites/pl-opensocial/config/sync/group.content_type.flexible_group-group_node-event.yml config/sync/
```

Both set `entity_cardinality: 0` (unlimited groups per node), changed from `1`.

- Config: [group.content_type.flexible_group-group_node-topic.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/group.content_type.flexible_group-group_node-topic.yml)
- Config: [group.content_type.flexible_group-group_node-event.yml](file:///Users/andreangelantoni/Sites/pl-opensocial/config/sync/group.content_type.flexible_group-group_node-event.yml)

> [!CAUTION]
> **Breaking change**: `entity_cardinality: 0` allows unlimited group relationships per node. Any downstream code that assumes a 1:1 node-to-group relationship may need review. The existing `group_topics` and `group_events` Views already have `distinct: true`, so no duplicate entries will appear in group streams.

**Step 720** — Import the updated configs
```php
ddev drush php:eval '
$configs = [
  "group.content_type.flexible_group-group_node-topic",
  "group.content_type.flexible_group-group_node-event",
];
foreach ($configs as $name) {
  $yaml = file_get_contents("/var/www/html/config/sync/$name.yml");
  $data = \Drupal\Component\Serialization\Yaml::decode($yaml);
  \Drupal::configFactory()->getEditable($name)->setData($data)->save();
  echo "Imported: $name\n";
}
'
```

## Custom Module: `pl_multigroup`

**Step 730** — Copy module to `web/modules/custom/pl_multigroup/`
- `cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_multigroup web/modules/custom/`
- Contents: `pl_multigroup.info.yml`, `pl_multigroup.module`, `pl_multigroup.libraries.yml`, `css/pl_multigroup.css`

**Step 740** — Enable: `ddev drush en pl_multigroup -y`

> [!IMPORTANT]
> After enabling the module, run `ddev restart` to flush the PHP opcode cache so the web process can find the new classes. A `ddev drush cr` alone may not be sufficient.

Hooks implemented:
- `hook_form_node_form_alter()` — Adds "Group Audience" collapsible fieldset with checkboxes to Topic and Event create/edit forms. Only shows groups the current user is a member of. Pre-checks groups the node already belongs to.
- Custom submit handler `pl_multigroup_node_form_submit()` — Runs **after** the node save handler; creates/removes `group_relationship` entries to match the selected group checkboxes.
- `hook_preprocess_node()` — On **full view**: displays "Posted in: Group A, Group B" with linked group names. On **teasers**: displays "Cross-posted from Group X" badge when viewing from a secondary group.
- `hook_page_attachments()` — Attaches the CSS library.

> [!CAUTION]
> **`drupal_static` timing bug (FIXED 2026-03-13)**: The original module used `drupal_static('pl_multigroup_selected_groups')` to pass group selections from the form submit handler to `hook_node_insert()`/`hook_node_update()`. **This does NOT work** because `hook_node_insert` fires during node save, BEFORE the custom submit handler runs. The submit handler is appended via `$form['actions']['submit']['#submit'][]`, so it executes AFTER the default node save handler.
>
> **The fix**: Call `_pl_multigroup_sync_group_relationships()` directly in the submit handler (which runs post-save and has access to both the saved node and the form values). The `hook_node_insert`/`hook_node_update` hooks were removed entirely. Programmatic node saves (without the form) will not trigger group audience changes — this is intentional.

## Group Stream Behaviour

**Step 750** — No View modifications needed. The existing `group_topics` and `group_events` Views query `group_relationship_field_data` by `gid` (contextual argument). When a node has multiple `group_relationship` entries (one per group), it automatically appears in each group's stream. The `distinct: true` setting on these Views prevents duplicate entries.

## Cache Clear

**Step 755** — Clear caches after Phase 5 config imports and module enable
```bash
ddev drush cr
```

## Phase 5 Tests

> [!IMPORTANT]
> **Test Setup**: Copy updated tests to the rework directory:
> ```bash
> cp ~/Sites/pl-opensocial/tests/e2e/phase5-multigroup.spec.ts ~/Sites/pl-opensocial-rework/tests/e2e/
> ```

**Step 760** — Run (from the `tests/` directory): `./node_modules/.bin/playwright test e2e/phase5-multigroup.spec.ts --reporter=list`
- 7 tests: group audience fieldset, cross-posting, duplicates, cross-post badge, "Posted in" display, non-member exclusion, event cross-posting.
- **All 7 tests pass** (executed 2026-03-13, ~1.1 minutes total).

> [!NOTE]
> **Group Audience fieldset**: The fieldset only appears if the current user is a member of at least one group. The test creates groups as admin first, which automatically makes admin a member. Non-member users will not see the fieldset.
>
> **Cross-posted badge**: The "Cross-posted from" badge appears on teasers rendered via the `group_topics` or `group_events` Views, which render `group_content` entities in `teaser` mode. If Open Social uses a different view mode for the group stream, the badge may render differently.

> [!CAUTION]
> **Checkbox label interception**: Open Social's theme renders `<label>` elements that overlay `<input type="checkbox">` in form elements. Playwright's default click fails because the label intercepts pointer events. All checkbox clicks in the multigroup audience fieldset must use `{ force: true }` to bypass the interception check.
>
> **Duplicate count locator**: The "no duplicates" test uses `main h4 a:has-text("title")` to count topic card headings. Using a broader selector like `main a:has-text()` will match "Read more about …" secondary links and fail with a count of 3 instead of 1.


# Phase 6 — Notifications & Subscriptions

> [!IMPORTANT]
> **Zombie cleanup**: Before starting this phase, run the cleanup script:
> ```bash
> bash ~/Sites/pl-opensocial/scripts/kill-zombies.sh
> ```

**Goal**: Enable email subscriptions (group/thread/author/tag), per-post opt-out, configurable frequency (immediate/daily/weekly), and subscription management page. ~80% built into Open Social's existing `activity_send_email` pipeline.

## Enable Sub-Modules

**Step 800** — Enable notification sub-modules:
```bash
ddev drush en social_follow_tag social_follow_user message_notify -y
ddev drush cr
```
Also enables `social_follow_taxonomy` (auto-dependency).

**Step 805** — Grant follow permissions:
```bash
ddev drush role:perm:add authenticated "flag follow_content,unflag follow_content"
```

## Custom Module: `pl_notifications`

**Step 810** — Copy module to `web/modules/custom/pl_notifications/`
```bash
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_notifications web/modules/custom/
```
Contents: `pl_notifications.info.yml`, `pl_notifications.module`, `pl_notifications.routing.yml`, `pl_notifications.links.task.yml`, `src/Controller/NotificationSettingsController.php`, `src/Form/CancelAllSubscriptionsForm.php`

**Step 820** — Enable: `ddev drush en pl_notifications -y && ddev restart`

Features:
- `hook_form_node_form_alter()` — Adds "Do not send notifications" checkbox to Topic/Event/Page forms.
- Submit handler stores suppression flag in State API; `hook_activity_insert()` unpublishes the activity to prevent email dispatch.
- `/user/{uid}/notification-settings` — Subscription management page listing all followed content/users.
- Toggle disable/enable all notifications (State API).
- Cancel all subscriptions (unflag all flaggings) with confirmation form.
- "Notifications" tab on user profile pages.

> [!CAUTION]
> **Toggle disable caching**: The page uses `#cache => ['max-age' => 0]` to prevent stale renders. The toggle handler runs at the TOP of `page()` (before building the render array) so the redirect fires cleanly before any content is built.

## Cache Clear

**Step 840** — Clear caches: `ddev drush cr`

## Phase 6 Tests

> [!IMPORTANT]
> **Test Setup**: Copy tests to the rework directory:
> ```bash
> cp ~/Sites/pl-opensocial/tests/e2e/phase6-notifications.spec.ts ~/Sites/pl-opensocial-rework/tests/e2e/
> ```

**Step 850** — Run (from `tests/`): `./node_modules/.bin/playwright test e2e/phase6-notifications.spec.ts --reporter=list`
- 8 tests: follow content flag, opt-out checkbox, opt-out post, management page, disable-all toggle, follow-shows-in-settings, cancel-all, on-site notifications.
- **All 8 tests pass** (executed 2026-03-13, ~35 seconds total).

> [!NOTE]
> **Existing notification infrastructure**: Open Social includes email frequency plugins (Immediately/Daily/Weekly/None), `ActivityDigestWorker` for digest emails, and `social_advanced_queue` for job tracking. No custom implementation needed for these.
>
> **Follow content flag**: The `social_follow_content` module renders follow/unfollow links on nodes. CSS classes vary by theme; tests use broad selectors with fallback.

# Phase 7 — User Profiles & History Stats

**Goal**: Populate user profiles, add contribution stats block and profile completeness indicator to profile pages.

## Custom Module: `pl_profile_stats`

**Step 915** — Copy module to `web/modules/custom/pl_profile_stats/`
```bash
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_profile_stats web/modules/custom/
```

Contents:
- `ContributionStatsBlock.php` — Counts topics, events, comments, groups, days active
- `ProfileCompletenessBlock.php` — Checks 9 key profile fields, shows fill percentage
- Twig templates + CSS for stats grid and progress bar

**Step 920** — Enable: `ddev drush en pl_profile_stats -y`

**Step 925** — Place blocks in main content area (idempotent — safe to re-run):
```bash
ddev drush php:eval '
$block_storage = \Drupal::entityTypeManager()->getStorage("block");
if (!$block_storage->load("pl_contribution_stats")) {
  $block_storage->create([
    "id" => "pl_contribution_stats",
    "plugin" => "pl_contribution_stats",
    "region" => "content",
    "theme" => "socialblue",
    "weight" => 50,
    "settings" => ["id" => "pl_contribution_stats", "label" => "Contribution Stats", "label_display" => "0", "provider" => "pl_profile_stats"],
    "visibility" => ["request_path" => ["id" => "request_path", "pages" => "/user/*", "negate" => FALSE]],
  ])->save();
  echo "Contribution Stats block placed\n";
} else {
  echo "Contribution Stats block already exists\n";
}
if (!$block_storage->load("pl_profile_completeness")) {
  $block_storage->create([
    "id" => "pl_profile_completeness",
    "plugin" => "pl_profile_completeness",
    "region" => "content",
    "theme" => "socialblue",
    "weight" => 51,
    "settings" => ["id" => "pl_profile_completeness", "label" => "Profile Completeness", "label_display" => "0", "provider" => "pl_profile_stats"],
    "visibility" => ["request_path" => ["id" => "request_path", "pages" => "/user/*", "negate" => FALSE]],
  ])->save();
  echo "Profile Completeness block placed\n";
} else {
  echo "Profile Completeness block already exists\n";
}
'
```

## Populate Admin Profile

**Step 930** — Set sample profile data for admin (uid 1):
```bash
ddev drush php:eval '
$profiles = \Drupal::entityTypeManager()->getStorage("profile")->loadByProperties(["uid" => 1, "type" => "profile"]);
$profile = reset($profiles);
$profile->set("field_profile_first_name", "Site");
$profile->set("field_profile_last_name", "Administrator");
$profile->set("field_profile_organization", "Open Social Foundation");
$profile->set("field_profile_function", "Platform Admin");
$profile->set("field_profile_self_introduction", ["value" => "Managing the Open Social platform.", "format" => "basic_html"]);
$profile->set("field_profile_summary", "Platform administrator with full access");
$profile->save();
'
```

**Step 940** — Clear caches: `ddev drush cr`

## Phase 7 Tests

**Step 950** — Run (from `tests/`): `./node_modules/.bin/playwright test e2e/phase7-profiles.spec.ts --reporter=list`
- 8 tests: profile page, field display, profile edit, stats block, completeness block, numeric stats, missing fields, multi-user profiles.
- **All 8 tests pass** (executed 2026-03-13, ~31 seconds total).

> [!NOTE]
> **Existing profile fields**: Open Social already defines 17 profile fields (name, image, banner, organization, function, expertise, interests, address, phone, bio, summary). Only contribution stats and completeness computation needed custom code.

> [!CAUTION]
> **Errata (original instructions)**: Step 925 originally used `$block_storage->create(...)->save()` without checking if the block already existed. On re-run this throws `'block' entity with ID already exists`. Fixed by adding `$block_storage->load()` guard. Step ordering was also corrected: module copy/enable (915/920) must come before profile population (930). Test copy step was missing.

---

# Phase 8 — Content Moderation & Group Admin

**Goal**: Add content pinning, homepage promotion, group-level language, organizer sidebar, and mission statement block.

## Custom Modules

**Step 1010** — Copy modules to `web/modules/custom/`:
```bash
# pl_group_pin — Flag-based content pinning with Views sort + badge
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_group_pin web/modules/custom/

# pl_group_language — Language negotiation from group field
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_group_language web/modules/custom/

# pl_group_mission — Mission statement sidebar block
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_group_mission web/modules/custom/

# pl_group_extras — Homepage promotion view config
cp -r ~/Sites/pl-opensocial/web/modules/custom/pl_group_extras web/modules/custom/
```

Contents:
- `pl_group_pin`: `hook_views_query_alter` (LEFT JOIN for pin sort), `hook_preprocess_node` (adds "Pinned" badge), CSS
- `pl_group_language`: `LanguageNegotiationGroup` plugin — parses group ID from URL path (not route params, since language negotiation runs before routing)
- `pl_group_mission`: `GroupMissionBlock` plugin — renders `field_group_description` in sidebar
- `pl_group_extras`: Views config for homepage promoted content

> [!IMPORTANT]
> **Language negotiation timing**: Drupal resolves language **before** route matching. The `LanguageNegotiationGroup` plugin uses `$request->getPathInfo()` with regex `#^/group/(\d+)#` — NOT `\Drupal::routeMatch()->getParameter('group')` which is always NULL at negotiation time.

**Step 1015** — Enable `social_language` (Open Social's multilingual foundation):
```bash
ddev drush en social_language -y
```

> [!IMPORTANT]
> `social_language` is an optional module included in the Open Social distribution. Enabling it:
> - **Auto-enables** all 4 core translation modules (`language`, `interface_translation`, `config_translation`, `content_translation`) in one step
> - **Grants Site Manager** permissions to translate content, menus, account settings, and system info
> - **Makes the activity stream and notification emails translation-aware** — items render in each user's preferred language
> - **Pre-configures** translation file storage
>
> It does **not** add languages, download translations, or configure negotiation order — those are handled in Steps 1040–1070 below.

**Step 1020** — Enable custom modules:
```bash
ddev drush en pl_group_pin pl_group_language pl_group_mission pl_group_extras -y
```

## Flag Configuration

**Step 1030** — Create "Pin in group" and "Promote to homepage" flags (idempotent):
```bash
ddev drush php:eval '
$flag_storage = \Drupal::entityTypeManager()->getStorage("flag");

if (!$flag_storage->load("pin_in_group")) {
  $flag_storage->create([
    "id" => "pin_in_group",
    "label" => "Pin in group",
    "entity_type" => "node",
    "flag_type" => "entity:node",
    "link_type" => "reload",
    "flagTypeConfig" => [],
    "linkTypeConfig" => [],
    "flag_short" => "Pin in group",
    "unflag_short" => "Unpin",
    "global" => TRUE,
  ])->save();
  echo "Created pin_in_group flag\n";
} else {
  echo "pin_in_group flag already exists\n";
}

if (!$flag_storage->load("promote_homepage")) {
  $flag_storage->create([
    "id" => "promote_homepage",
    "label" => "Promote to homepage",
    "entity_type" => "node",
    "flag_type" => "entity:node",
    "link_type" => "reload",
    "flagTypeConfig" => [],
    "linkTypeConfig" => [],
    "flag_short" => "Promote to homepage",
    "unflag_short" => "Remove from homepage",
    "global" => TRUE,
  ])->save();
  echo "Created promote_homepage flag\n";
} else {
  echo "promote_homepage flag already exists\n";
}
'
```

## Language Setup

**Step 1040** — Add 12 languages:
```bash
ddev drush php:eval '
$langs = ["ca","zh-hans","da","nl","fr","de","nb","pt-br","ru","es","sv","tr"];
$storage = \Drupal::entityTypeManager()->getStorage("configurable_language");
foreach ($langs as $langcode) {
  if (!$storage->load($langcode)) {
    $storage->create(["id" => $langcode])->save();
    echo "Added: $langcode\n";
  } else {
    echo "Exists: $langcode\n";
  }
}
'
```

**Step 1045** — Enable the Locale module (required for translation downloads):
```bash
ddev drush en locale -y
```

**Step 1050** — Download translations:
```bash
ddev drush locale:check
ddev drush locale:update
```
> [!NOTE]
> This downloads ~139k translation strings across 12 languages. French alone has ~14,400 strings. Takes 1-2 minutes.

**Step 1060** — Enable group language negotiation:
```bash
ddev drush php:eval '
$config = \Drupal::configFactory()->getEditable("language.types");
$config->set("negotiation.language_interface.enabled", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->set("negotiation.language_interface.method_weights", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->save();
echo "Language negotiation configured\n";
'
```

## Group Language Field

**Step 1070** — Add `field_group_language` to `flexible_group` (idempotent):
```bash
ddev drush php:eval '
$field_storage = \Drupal::entityTypeManager()->getStorage("field_storage_config")->load("group.field_group_language");
if (!$field_storage) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_language",
    "entity_type" => "group",
    "type" => "language",
    "cardinality" => 1,
  ])->save();
  echo "Created field storage\n";
}

$field = \Drupal::entityTypeManager()->getStorage("field_config")->load("group.flexible_group.field_group_language");
if (!$field) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_language",
    "entity_type" => "group",
    "bundle" => "flexible_group",
    "label" => "Group Language",
    "required" => FALSE,
    "settings" => ["language_override" => "und"],
  ])->save();
  echo "Created field instance\n";
}

// Add to form display.
$form_display = \Drupal::entityTypeManager()->getStorage("entity_form_display")->load("group.flexible_group.default");
if ($form_display && !$form_display->getComponent("field_group_language")) {
  $form_display->setComponent("field_group_language", [
    "type" => "language_select",
    "weight" => 20,
    "region" => "content",
  ])->save();
  echo "Added to form display\n";
}
'
```

## Mission Block Placement

**Step 1080** — Place group mission block in sidebar (idempotent):
```bash
ddev drush php:eval '
$block_storage = \Drupal::entityTypeManager()->getStorage("block");
if (!$block_storage->load("pl_group_mission")) {
  $block_storage->create([
    "id" => "pl_group_mission",
    "plugin" => "pl_group_mission",
    "region" => "complementary_bottom",
    "theme" => "socialblue",
    "weight" => 10,
    "settings" => ["id" => "pl_group_mission", "label" => "About this group", "label_display" => "visible", "provider" => "pl_group_mission"],
    "visibility" => ["request_path" => ["id" => "request_path", "pages" => "/group/*", "negate" => FALSE]],
  ])->save();
  echo "Mission block placed\n";
} else {
  echo "Mission block already exists\n";
}
'
```

**Step 1090** — Clear caches: `ddev drush cr`

## Phase 8 Tests

**Step 1100** — Run (from `tests/`): `./node_modules/.bin/playwright test e2e/phase8-moderation.spec.ts --reporter=list`
- 9 tests: pin/unpin/badge, member cannot pin, homepage promote, organizer sidebar, group language (French), user language override, mission statement.
- **All 9 tests pass** (executed 2026-03-13, ~1.8 minutes total).

> [!NOTE]
> **Drush-based test setup**: Phase 8 tests use `execSync('ddev drush php:eval ...')` for all entity creation (groups, topics, users, flags). Playwright only handles login, navigation, and GUI assertions. This avoids CKEditor and hidden form field issues.

> [!IMPORTANT]
> **PIN SQL join fix**: The `pl_group_pin` module's `hook_views_query_alter` must dynamically look up the correct node table alias for the `group_topics` view. The alias is `node_field_data_group_relationship_field_data` (not the default `node_field_data`). Using a hardcoded alias causes a SQL error.

---

# Post-Test Cleanup & Solr Setup

## Clean Up Test Data

**Step 1200** — Delete all test entities created by the Playwright test suite:
```bash
ddev drush php:eval '
$storage = \Drupal::entityTypeManager();

// Delete all nodes
$nodes = $storage->getStorage("node")->loadMultiple();
$storage->getStorage("node")->delete($nodes);
echo "Deleted " . count($nodes) . " nodes\n";

// Delete all groups
$groups = $storage->getStorage("group")->loadMultiple();
$storage->getStorage("group")->delete($groups);
echo "Deleted " . count($groups) . " groups\n";

// Delete test users (uid > 1)
$users = $storage->getStorage("user")->loadMultiple();
$test_users = [];
foreach ($users as $u) {
  if ($u->id() > 1) {
    $test_users[] = $u;
  }
}
if ($test_users) {
  $storage->getStorage("user")->delete($test_users);
}
echo "Deleted " . count($test_users) . " test users\n";

// Delete flaggings and enrollments
$flaggings = $storage->getStorage("flagging")->loadMultiple();
if ($flaggings) { $storage->getStorage("flagging")->delete($flaggings); }
echo "Deleted " . count($flaggings) . " flaggings\n";

try {
  $enrollments = $storage->getStorage("event_enrollment")->loadMultiple();
  if ($enrollments) { $storage->getStorage("event_enrollment")->delete($enrollments); }
  echo "Deleted " . count($enrollments) . " enrollments\n";
} catch (\Exception $e) { echo "No enrollments\n"; }
'
```

**Step 1210** — Clear caches: `ddev drush cr`

## Solr Search Setup

**Step 1220** — Install the DDEV Solr add-on:
```bash
ddev add-on get ddev/ddev-solr
ddev restart
```

> [!NOTE]
> This pulls in Solr 9 with embedded ZooKeeper (SolrCloud mode). The first restart downloads the Solr Docker image (~400 MB).

**Step 1230** — Set Solr version to 9 in the search_api server config:
```bash
ddev drush php:eval '
$server = \Drupal\search_api\Entity\Server::load("social_solr");
$config = $server->getBackendConfig();
$config["connector_config"]["solr_version"] = "9";
$server->setBackendConfig($config);
$server->save();
echo "Set Solr version to 9\n";
'
```

**Step 1240** — Generate and upload the Drupal configset to Solr:
```bash
# Generate configset zip from Drupal
ddev drush search-api-solr:get-server-config social_solr /tmp/solr-config.zip

# Extract inside web container
ddev exec bash -c 'mkdir -p /tmp/solr-configset && cd /tmp/solr-configset && unzip -o /tmp/solr-config.zip'

# Copy to Solr container and upload to ZooKeeper
docker cp ddev-pl-opensocial-rework-web:/tmp/solr-configset /tmp/solr-configset
docker cp /tmp/solr-configset ddev-pl-opensocial-rework-solr:/tmp/solr-configset
ddev solr zk upconfig -n drupal -d /tmp/solr-configset
```

**Step 1250** — Create the `drupal` collection:
```bash
ddev solr create -c drupal -n drupal
```

**Step 1260** — Index all content:
```bash
ddev drush search-api:index
```

**Step 1270** — Verify: Visit `/search/all` and confirm search works without Solr errors.

## Final Backup

**Step 1280** — Final database backup:
```bash
ddev export-db --file=backups/build-complete.sql.gz
```

---

# Demo Data

After the build is complete, populate the site with realistic demo content by following the [Demo Data Runbook](file:///Users/andreangelantoni/Sites/pl-opensocial/documents/DEMO_DATA_PLAN.md). The runbook creates users, groups, topics, events, pages, comments, enrollments, flags, and content translations — all via `ddev drush php:eval` for full reproducibility.
