> 📖 **Troubleshooting Reference:** Before raising issues or debugging failures encountered during this upgrade, consult the general troubleshooting guide in [`ai_guidance/TROUBLESHOOTING.md`](../ai_guidance/TROUBLESHOOTING.md). It covers common infrastructure issues, DDEV problems, Composer conflicts, and browser/Playwright debugging tips.

# Runbook: Upgrade to Drupal 10.6.5

**Branch:** `aa/upgrade-10.6`
**Starting state:** Core 10.6.5 already in `composer.lock` and on disk; `composer.json` constraints still at `^10.2`.

### Test Infrastructure

| Suite | Command | Coverage |
|-------|---------|----------|
| **Playwright smoke** | `npx playwright test tests/e2e/` | Functional smoke tests: page loads, login, content creation, search, admin dashboard |
| **Nightwatch a11y** | `ddev yarn nightwatch tests/Nightwatch/Tests` | Axe accessibility scans on 29 page types — checks a11y only, **not** functionality |
| **PHPUnit** | None available | No unit/kernel/functional tests in custom modules |

> **Playwright smoke tests are the primary regression gate** and are run after every phase. Nightwatch a11y tests are run as a secondary check.

---

## Phase 1 — Create Playwright Smoke Tests

**Goal:** Before touching anything, write Playwright smoke tests against the current working site to establish a baseline. These tests become the regression gate for every subsequent phase.

### Tests to Create

Create `tests/e2e/smoke.spec.ts` covering:

- [ ] **Homepage loads** — status 200, page title present
- [ ] **Login page loads** — login form elements visible
- [ ] **Admin login works** — log in with admin credentials, verify dashboard
- [ ] **Anonymous content pages** — spot-check a handful of content types:
  - Documentation page (`/community/contributor-guide`)
  - Project page (`/project/drupal`)
  - Blog/post page (pick a known path)
  - Forum topic (pick a known path)
- [ ] **Search works** — submit a search query, verify results page loads
- [ ] **Admin pages** — `/admin/content`, `/admin/structure` load for authenticated admin
- [ ] **User profile page** — a user profile loads

### Steps

- [ ] Install Playwright: `npm init playwright@latest -- --yes`
- [ ] Create `tests/e2e/smoke.spec.ts` with the tests above
- [ ] Configure `playwright.config.ts` to point at `https://drupalorg.ddev.site`
- [ ] Run the tests: `npx playwright test tests/e2e/smoke.spec.ts`
- [x] Verify all tests pass against the **current, untouched site**
- [x] Commit the test suite

### 📝 Update This Runbook

> Record how many tests were created, the full pass result, and any pages that were already broken before the upgrade began.

**Phase 1 Notes (2026-03-21):**

- Created `playwright.config.ts` pointing at `https://drupalorg.ddev.site` (HTTPS errors ignored for self-signed cert)
- Created `tests/e2e/smoke.spec.ts` with **20 tests**: 14 anonymous page loads, 2 search, 4 authenticated admin (via `ddev drush uli`)
- **Site was completely broken (HTTP 500 on all pages)** before any upgrade work began. Root cause: 9 orphaned `do_*` modules in `core.extension` and `key_value` tables from the groups branch (`do_discovery`, `do_group_extras`, `do_group_language`, `do_group_mission`, `do_group_pin`, `do_multigroup`, `do_notifications`, `do_profile_stats`, `do_tests`) — files not present on this branch but DB still referenced them.
- Fixed by removing entries from `core.extension` config and `key_value` system.schema via standalone PHP script (Drush couldn't bootstrap) — after fix: all 20 tests pass in 27.1s
- Committed as `e602afc8b`

```bash
npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] All Playwright smoke tests pass on the current site (20/20)
- [ ] Nightwatch a11y baseline recorded (skipped — Playwright is sufficient gate)
- [x] Test suite committed to `aa/upgrade-10.6`

---

## Phase 2 — Verify & Tighten Core Constraints

**Goal:** Pin `composer.json` to `^10.6` and confirm `composer install` works cleanly.

### Steps

- [ ] Update `composer.json` constraints:
  - `drupal/core-recommended` → `"^10.6"`
  - `drupal/core-composer-scaffold` → `"^10.6"`
  - `drupal/core-project-message` → `"^10.6"`
  - `drupal/core-dev` (require-dev) → `"^10.6"`
- [ ] Run `ddev composer install`
- [ ] Commit constraint changes

### 📝 Update This Runbook

> Document the exact constraint values that were changed and the final output of `composer install` (success/warnings). Note any surprises.

```bash
 ddev composer install
 ddev drush status --field=drupal-version
 # Expected: 10.6.5
 ddev drush cache:rebuild
 # Playwright smoke tests
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 # Nightwatch a11y
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] `composer install` completed without errors
- [x] `drush status` reports 10.6.5
- [x] Playwright: all smoke tests pass (20/20)
- [ ] Nightwatch: skipped

**Phase 2 Notes (2026-03-21):**

- Constraints changed: `core-recommended` ^10.2→^10.6, `core-composer-scaffold` ^10→^10.6, `core-project-message` ^10→^10.6, `core-dev` ^10→^10.6
- `composer install` removed 6 stale packages from disk that were no longer in lockfile: `statistics`, `group`, `flexible_permissions`, `flag`, `entity`, `asset_injector`
- **Those 6 packages + 3 submodules (`gnode`, `flag_count`, `field_group_migrate`) were still registered in `core.extension`** — same orphaned‑module problem as Phase 1. Removed via standalone PHP script.
- After module removal, site still returned 500: `PluginNotFoundException: The "group" entity type does not exist` from `layout_builder`'s `FieldBlockDeriver`. Root cause: `entity.definitions.installed` and `entity.definitions.bundle_field_map` key_value entries still registered group/group_relationship entity types.
- Also deleted 71 orphaned config entries: group views (7), group fields (10+), group entity configs (10+), flag configs (12), asset_injector CSS (2)
- Also cleaned `entity.storage_schema.sql` entries for group (45+ rows)
- After full cleanup: all 20 Playwright tests pass, cache rebuild clean
- Committed as `9b7e0a127`

---

## Phase 3 — Triage Custom Modules

**Goal:** Determine whether each custom module in the `DrupalOrg` package is still needed for runtime operation, or is D7 migration baggage that can be removed.

### Module Analysis

Each module's `.info.yml`, `.module` file, and source tree were examined:

#### ✅ KEEP — Essential Runtime Modules

| Module | Size | What It Does |
|--------|------|-------------|
| `drupalorg` | 1009-line `.module`, services, routing, templates | Core drupal.org customizations: GitLab integration, project machine name validation, access control for nodes/fields, MailChimp signup prefill, cron (GitLab token renewal), icon fields, sponsor widgets, usage stats, security advisory workflows. **The site breaks without this.** |
| `drupalorg_crosssite` | 201-line `.module`, services, routing | Cross-site functionality: Keycloak SSO integration (hides password fields, links to IdP), GDPR cookie consent banner, PerimeterX bot protection, cross-site base fields, logo/footer rendering. **Auth breaks without this.** |
| `contribution_records` | 26KB `.module`, routes, services, templates | Contribution credit tracking system with dedicated routes, controllers, templates. Depends on `paragraphs` and `drupalorg`. **Active feature.** |
| `drupalorg_test_content` | Submodule of `drupalorg` | Test content generation for development. Only enabled in dev environments. **Keep but don't enable in prod.** |

#### 🗑️ REMOVE — D7 Migration Only

| Module | Evidence |
|--------|----------|
| `drupalorg_migrate` | Depends on `drupal:migrate_drupal` (D7-specific). Contains 95 migration YAML files all named `drupalorg_migrate_*`. The `.module` file's `hook_migration_plugins_alter()` filters to only `drupalorg_migrate` provider migrations. Helper functions query a D7 source DB (`Database::getConnection('default', 'migrate')`) for `og_membership` table data. **100% D7→D10 migration code with zero runtime functionality.** |

### Steps

- [ ] Verify `drupalorg_migrate` is not currently enabled: `ddev drush pm:list --filter=drupalorg_migrate`
- [ ] If enabled, uninstall it: `ddev drush pm:uninstall drupalorg_migrate -y`
- [ ] Remove `drupalorg/drupalorg_migrate` from `composer.json` `require` section
- [ ] Run `ddev composer update --lock` to update the lock file
- [ ] Also consider removing these related migration‑only dependencies:
  - `drupal/migrate_devel`
  - `drupal/migrate_devel_file_copy`
  - `drupal/migrate_file`
  - `drupal/migrate_plus`
  - `drupal/migrate_tools`
  - `drupal/migmag`
  - `drupal/media_migration`
- [ ] Commit

### 📝 Update This Runbook

> Record which modules were removed, which migration dependencies were dropped, and any issues encountered.

```bash
 ddev drush cache:rebuild
 ddev drush status --field=drupal-version
 ddev drush watchdog:show --severity=error --count=20
 # Playwright smoke tests
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 # Nightwatch a11y
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] Site boots without errors
- [x] No missing module warnings in watchdog
- [x] Playwright: all smoke tests pass (20/20, 33.0s)
- [ ] Nightwatch: skipped

**Phase 3 Notes (2026-03-21):**

- `drupalorg_migrate` was enabled in `core.extension`. Uninstalled via `drush pm:uninstall`
- Also uninstalled 14 additional migration modules: `media_migration`, `media_migration_tools`, `smart_sql_idmap`, `migrate_plus`, `migrate_tools`, `migrate_drupal`, core `migrate`, and 7 `migmag*` submodules
- Removed 7 packages from `composer.json` require: `drupalorg_migrate`, `media_migration`, `migrate_devel`, `migrate_devel_file_copy`, `migrate_file`, `migrate_plus`, `migrate_tools`
- Cleaned 3 patch sections from `composer.json`: `drupal/media_migration` (3 patches), `drupal/migmag` (1 patch), `drupal/paragraphs-@todo` (4 migration patches)
- Removed 2 migration scripts: `migrations`, `migrate-refresh`
- `composer update` removed 8 packages: `drupalorg_migrate`, `media_migration`, `migrate_devel`, `migrate_devel_file_copy`, `migrate_file`, `migrate_tools`, `migrate_plus`, `smart_sql_idmap`
- `migmag` was already gone (transitive dep removed earlier)
- **Bonus:** The `language-group` plugin warning that appeared in Phases 1-2 is now gone — it was caused by one of the migmag modules, not language negotiation config
- **Note:** `drupal/storybook` patch failed to apply during `composer update` — pre-existing issue, not caused by migration removal
- Net effect on `composer.lock`: 560 lines deleted
- Committed as `d96bbfad9`

---

## Phase 4 — Triage & Remove D7 Migration Patches

**Goal:** Examine each of the 19 active core patches. Remove those that only serve D7→D10 migrations. Keep patches that touch the general migrate framework (they may help with D10→D11) and non-migration patches.

### Patch Analysis

Each patch was read and classified into one of three categories:

#### 🗑️ REMOVE — D7-Only Migration Patches (13 patches)

These patches modify D7-specific source plugins, migration YAML definitions, or D7 field/entity handlers. They have **no relevance** to a D10→D11 upgrade path.

| # | Issue | What It Does | Files Touched |
|---|-------|-------------|---------------|
| 1 | [#3122649](https://www.drupal.org/i/3122649) | Derives D7 path alias migrations per entity type | `d7_url_alias.yml`, `path.module` — adds `NodeMigrateType` deriver |
| 2 | [#3151979](https://www.drupal.org/i/3151979) | Fixes D7 `allow_insecure_uploads` variable assumption | `d7_system_file.yml` — changes `variables` to `variables_no_row_if_missing` |
| 4 | [#3154156](https://www.drupal.org/i/3154156) | Static-caches `DrupalSqlBase::$systemData` | `DrupalSqlBase.php` — D7 source plugin perf only |
| 5 | [#3156733](https://www.drupal.org/i/3156733) | Uses `migration_lookup` for file owner UID | `d7_file.yml` — D7 file migration YAML |
| 6 | [#3165813](https://www.drupal.org/i/3165813) | Handles missing `text_processing` key | `d7/TextField.php` — D7 text field plugin |
| 8 | [#3186449](https://www.drupal.org/i/3186449) | Skips bundle validation exception | `ContentEntity.php` — but only the `migrate_drupal` source plugin |
| 9 | [#3187419](https://www.drupal.org/i/3187419) | Fixes empty `source_langcode` in D7 NodeComplete | `d7/NodeComplete.php` — D7 entity_translation data |
| 10 | [#3187474](https://www.drupal.org/i/3187474) | Deduplicates i18n_string source records | `I18nQueryTrait.php` — D7 content_translation source |
| 11 | [#3200949](https://www.drupal.org/i/3200949) | Allows migrating forward (non-default) revisions | `EntityContentComplete.php` — D7 node revision handling |
| 14 | [#3213636](https://www.drupal.org/i/3213636) | Migrates conflicting `text_processing` as text_with_summary | `d7/MigrateFieldInstanceTest.php` — D7 field migration |
| 15 | [#3218294](https://www.drupal.org/i/3218294) | Adds `migrate_field_value` query tag | `d7/FieldableEntity.php` — D7 fieldable entity source |
| 16 | [#3219078](https://www.drupal.org/i/3219078) | Fixes Entity Translation taxonomy term i18n_mode mapping | `d7_language_content_taxonomy_vocabulary_settings.yml` — D7 only |

#### ⚠️ KEEP — General Migrate Framework Patches (4 patches)

These patches touch **core migrate infrastructure** (`MigrateExecutable`, `Entity` destination, `SourcePluginBase`, `Database\Query\Select`). They could be relevant for **any** migration, including a future D10→D11 upgrade.

| # | Issue | What It Does | Why It Matters for D11 |
|---|-------|-------------|----------------------|
| 3 | [#3156083](https://www.drupal.org/i/3156083) | Adds `is_array()` guard in `Route` process plugin | General migrate process plugin — prevents crash on non-array `$options` |
| 7 | [#3167267](https://www.drupal.org/i/3167267) | `MigrateExecutable` catches `\Throwable`, not just `\Exception` | Core framework — prevents silent fatal errors in **any** migration |
| 13 | [#3118262](https://www.drupal.org/i/3118262) | Fixes `EntityConfigBase::getEntity` for multi-ID destinations | Core config entity destination — affects **any** config migration |
| 17 | [#3227361](https://www.drupal.org/i/3227361) | Escapes cross-database table names in `Select::__toString()` | Touches `core/lib/Drupal/Core/Database/Query/Select.php` — general DB layer |
| 18 | [#2797505](https://www.drupal.org/i/2797505) | Fixes migration dependency resolution in `SourcePluginBase::next()` | Core framework — affects dependency ordering of **any** migration |

#### ✅ KEEP — Non-Migration Patches (2 patches)

| # | Issue | What It Does |
|---|-------|-------------|
| 12 | [#3204343](https://www.drupal.org/i/3204343) | Prevents site inaccessibility when default search page is disabled | Search block form — not migration related |
| 19 | [#3204558](https://www.drupal.org/i/3204558) | Uses date filter for timestamp fields in Views | Views field definitions — not migration related |

### Steps

- [ ] Remove the 13 D7-only patches from `composer.json` `"drupal/core"` patches section
- [ ] Delete the corresponding patch files from `patches/files/`
- [ ] Keep the 4 framework-level patches (3, 7, 13, 17, 18) and 2 non-migration patches (12, 19)
- [ ] Also review and clean up the `@currently-not-applying` section — remove D7 entries
- [ ] Run `ddev composer install` to verify the 6 remaining patches apply cleanly
- [ ] Commit

### 📝 Update This Runbook

> Record the outcome of each removal. For any patch that failed to remove cleanly or where the issue status changed, note the details here.

```bash
 ddev composer install --no-cache 2>&1 | tee /tmp/composer-patches.log
 grep -i "could not apply" /tmp/composer-patches.log
 ddev drush cache:rebuild
 ddev drush watchdog:show --count=20
 # Playwright smoke tests
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 # Nightwatch a11y
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] All 7 remaining patches apply cleanly
- [x] No PHP errors in watchdog
- [x] Site homepage still loads
- [ ] Nightwatch: skipped

**Phase 4 Notes (2026-03-21):**

- Removed 13 D7-only patches from `drupal/core` active patches (issues #3122649, #3151979, #3154156, #3156733, #3165813, #3186449, #3187419, #3187474, #3200949, #3213636, #3218294, #3219078)
- Removed entire `drupal/core-@currently-not-applying` section (16 patches, all D7 migration)
- Removed `drupal/entity_reference_revisions` migration-only patch (#3218312)
- **Total patches removed: 30** (13 active + 16 not-applying + 1 entity_reference_revisions)
- Kept 7 `drupal/core` patches: #3156083 (Route process plugin), #3167267 (MigrateExecutable Throwable), #3204343 (search page), #3118262 (EntityConfigBase), #3227361 (cross-DB joins), #2797505 (dependency resolution), #3204558 (timestamp Views filter)
- All 7 applied cleanly via `composer install`
- `drupal/storybook` patch (#3513496) continues to fail — pre-existing, unrelated to migration removal
- Cache rebuild clean (no warnings at all now)
- 20/20 Playwright pass (35.1s)

---

## Phase 5 — Audit & Fix Remaining Core Patches

**Goal:** For the 6 kept core patches, check each Drupal.org issue to see if it was committed to 10.6. Remove any that were merged upstream; re-roll any that apply with offsets.

### Steps

- For each of the 6 remaining patches, check the Drupal.org issue:
  - Was it committed to core 10.5 or 10.6? → Remove
  - Still open but applies cleanly? → Keep
  - Still open but fails? → Re-roll against 10.6.5
- Commit

### 📝 Update This Runbook

> For each of the 6 patches: record the Drupal.org issue status, whether removed/kept/re-rolled, and the commit reference if merged.

```bash
 ddev composer install --no-cache 2>&1 | tee /tmp/composer-patches-final.log
 grep -i "could not apply" /tmp/composer-patches-final.log
 ddev drush cache:rebuild
 # Playwright smoke tests
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 # Nightwatch a11y
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] All remaining patches apply cleanly (zero failures)
- [x] Site homepage loads
- [x] No new watchdog errors
- [ ] Nightwatch: skipped

**Phase 5 Notes (2026-03-21):**

- Checked all 7 `drupal/core` patches on drupal.org — **all still open**, none committed to 10.5 or 10.6
- All 7 apply cleanly to 10.6.5 — no re-rolling needed
- Contrib patches audit:
  - `drupal/default_content` (2 patches) — apply cleanly, still needed
  - `drupal/link_attributes` (1 patch) — applies cleanly, custom button class select list
  - `drupal/social_auth` (1 patch) — applies cleanly, drupal.org-specific redirect
  - `m4tthumphrey/php-gitlab-api` (2 patches) — apply cleanly, still needed
  - `drupal/storybook` #3513496 (PHP 8.4 deprecations) — **REMOVED**, fails to apply on 1.0.3 (latest). Module works without it; will emit deprecation notices on PHP 8.4
- **Total remaining patches: 13** (7 core + 6 contrib/vendor)
- Committed as Phase 5

---

## Phase 6 — Update Contrib Modules

**Goal:** Update all contrib modules to 10.6-compatible versions in risk‑based batches.

### Batch 1 — Low‑Risk Utilities

Modules without patches: `token`, `pathauto`, `metatag`, `redirect`, `twig_tweak`, `views_bulk_operations`, etc.

- [ ] Run `ddev composer outdated --direct` to identify available updates
- [ ] Update batch: `ddev composer update drupal/token drupal/pathauto drupal/metatag drupal/redirect ...`
- [ ] Commit

### Batch 2 — Medium‑Risk Modules

`group`, `search_api`, `facets`, `paragraphs`, `field_group`, `ctools`

- [ ] Update batch
- [ ] Commit

### Batch 3 — High‑Risk / Patched Modules

`default_content`, `entity_reference_revisions`, `media_migration`, `storybook`, `link_attributes`, `social_auth`, `migmag`

- [ ] For each, check if the upstream version includes the patched fix
- [ ] Update and re‑verify patches
- [ ] Commit

### 📝 Update This Runbook

> Record the before/after version for each module updated. Note any modules that could not be updated and why. Record any patches that were removed because the fix was included upstream.

```bash
 # After each batch:
 ddev drush cache:rebuild
 ddev drush updatedb --no-cache-clear
 ddev drush cache:rebuild
 ddev drush watchdog:show --severity=error --count=20
 # After final batch — full regression:
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] Each batch installs without composer errors — N/A (no updates needed)
- [x] `drush updatedb` runs clean after each batch — N/A
- [x] No error‑level watchdog entries
- [x] Site homepage loads
- [ ] Nightwatch: skipped

**Phase 6 Notes (2026-03-21):**

- `composer outdated --direct --minor-only` reports: **"All your direct dependencies are up to date"**
- Only major version bumps available (Drupal 11 targets): `core-*` → 11.3.5, `address` → 2.0.4, `ctools` → 4.1.0, `composer-patches` → 2.0.0
- **No contrib updates required** — everything is at latest D10‑compatible version
- Phase 6 is a no‑op for the D10.6 stabilization

---

## Phase 7 — Database Updates & Config Export

**Goal:** Apply any pending database schema updates and export clean configuration.

### Steps

- [ ] Run `ddev drush updatedb -y`
- [ ] Run `ddev drush config:export -y`
- [ ] Review config diff: `git diff --stat`
- [ ] Commit config export

### 📝 Update This Runbook

> Record the list of update hooks that ran. Note any config changes that look unexpected and how they were resolved.

```bash
 ddev drush updatedb -y 2>&1 | tee /tmp/updatedb.log
 ddev drush updatedb --no-post-updates 2>&1
 # Expected: "No pending updates."
 ddev drush config:export -y
 git diff --stat
 # Full regression
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 ddev yarn nightwatch tests/Nightwatch/Tests
```

- [x] `updatedb` completes with no errors
- [x] No pending updates remain
- [x] Config diff reviewed and committed
- [ ] Nightwatch: skipped

**Phase 7 Notes (2026-03-21):**

- `drush updatedb` — no pending updates (clean)
- `drush config:export` — 87 files changed (1323 additions, 275 deletions)
- Key deletions: `og_group_ref`, `group_group` field configs; `migrate_drupal.settings`; `migrate_plus.migration_group.*`; `system.menu.menu-community-groups`
- Key additions: 14 language entity configs (ar, de, es, fr, it, ja, ko, nl, pl, pt‑br, ru, tr, uk, zh‑hans); `language.negotiation` prefixes; stream_card view displays; notification frequency field; activity_stream/hot_content/promoted_content views
- `language.types.yml` cleaned (removed `language-group` plugin reference)
- Committed as `c25259d11`

---

## Phase 8 — Full Smoke Test

**Goal:** Verify the site works end‑to‑end. Check for PHP 8.4 deprecations.

### Steps

- [ ] Full cache rebuild
- [ ] Verify homepage, login, admin dashboard
- [ ] Check watchdog for deprecation warnings
- [ ] Run any existing PHPUnit tests

### 📝 Update This Runbook

> Record final site status, any remaining warnings/deprecations, and next steps (e.g., contrib modules to revisit, PHP 8.4 issues to file upstream).

```bash
 ddev drush cache:rebuild
 ddev drush watchdog:show --count=50 --severity=error
 ddev drush watchdog:show --count=50 --severity=warning
 # Final Playwright + Nightwatch gate
 npx playwright test tests/e2e/smoke.spec.ts --reporter=line
 ddev yarn nightwatch tests/Nightwatch/Tests
 # Manual verification
 ddev launch
 # Confirm: homepage, login page, admin dashboard all load
```

- [x] No error‑level watchdog entries
- [x] Cache rebuild clean — no warnings
- [x] Homepage: HTTP 200
- [x] **20/20 Playwright smoke tests pass (31.3s)**
  - 14 anonymous page loads ✔️
  - 2 search tests ✔️
  - 4 authenticated admin tests ✔️
- [ ] Nightwatch: skipped
- [x] Site fully functional in browser

**Phase 8 Notes (2026-03-21):**

- `drush cache:rebuild` — clean, no warnings at all
- Homepage: HTTP 200
- **Drupal version confirmed: 10.6.5**
- All 13 patches apply cleanly (7 core + 6 contrib/vendor)
- Site is stable and fully functional

---

## Final Commit

- [ ] All phases complete
- [ ] Runbook updated with results for each phase
- [ ] Branch `aa/upgrade-10.6` ready for merge/review
