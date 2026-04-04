# Drupal Best Practices

Established patterns and guidelines for building and maintaining Drupal sites in the Performant Labs ecosystem.

---

## Config sync key ordering

### The symptom

After a fresh `ddev post-install`, running `drush config:status` shows hundreds of configs marked as "Different" even though the site works correctly and the data is identical. Repeatedly running `drush config:import` does not resolve it — the same configs show as "Different" every time.

### The cause

Drupal has two separate code paths that write config data, and they produce different key orderings:

1. **Entity API** (`::save()`, `::create()`) — used when you configure the site through the admin UI, or when `hook_install` runs during `drush site:install`. Produces key order from the entity's `toArray()` method.
2. **Typed config system** (`config:import`) — normalises key order to match the sequence defined in the module's `*.schema.yml` file.

If these two orderings differ for a given config type, the YAML file exported from the live site (which reflects the Entity API order) will not match what `config:import` stores in the database on a fresh install (which reflects the schema order). Drupal's `StorageComparer` does a direct serialisation comparison, so even a key-order-only difference is reported as "Different".

This happened with 291 base configs that Open Social's `hook_install` created via the Entity API. The key orderings were frozen in the YAML files when those configs were first exported, and every subsequent fresh install produced a different ordering — causing a permanent "Different" loop.

### The fix (when it recurs)

1. Do a fresh install on a test site using the normal instructions.
2. After `ddev post-install` completes, run `drush config:export -y` on the test site. This writes the schema-normalised active config to the test site's `config/sync` directory.
3. Rsync those normalised YAMLs back to the source repo:
   ```bash
   rsync -av --exclude='.htaccess' \
     ~/Sites/<project>-test/config/sync/ \
     ~/Sites/<project>/config/sync/
   ```
4. On the source site, import the normalised configs to update its active storage:
   ```bash
   cd ~/Sites/<project>
   ddev drush config:import -y
   ```
5. Verify both sites are clean:
   ```bash
   ddev drush config:status   # should say "No differences"
   ```
6. Commit the updated YAML files.

### How to prevent it

When making config changes on the source site, use a double export to normalise key ordering before committing:

```bash
ddev drush config:export -y          # capture UI changes
ddev drush config:import -y          # normalise key order in active config
ddev drush config:export -y          # re-export with normalised order
git add config/sync && git commit -m "..."
```

The intermediate `config:import` forces the typed config system to normalise all key orderings in the database, so the subsequent export produces YAML that will match exactly what a fresh install produces.

### Scope

The 291 configs fixed in commit `2b42002` are now stable. This issue can only recur when **new** configs are added through the UI and exported without the double-export step. When it does recur, the site continues to work correctly — the only symptom is a noisy `drush config:status` output.

---

## MentionsFilter PHP 8 compatibility patch

### The symptom

During `config:import`, a fatal PHP error fires:

```
Typed property Drupal\mentions\Plugin\Filter\MentionsFilter::$textFormat
must not be accessed before initialization
```

This crashes the import mid-run and rolls back the current config transaction.

### The cause

Open Social's `MentionsFilter` class declares `private ?string $textFormat;` without a default value. PHP 8 treats an uninitialized typed property as inaccessible even when the type is nullable. The error fires because `config:import` deleting a block content type triggers `hook_entity_extra_field_info` → `MessageTemplate->getText()` → the filter pipeline → `MentionsFilter`, before `setTextFormat()` has been called.

### The fix

A one-line patch (`patches/mentions-filter-php8-fix.patch`) changes the declaration to `private ?string $textFormat = null;`. It is applied automatically via `cweagans/composer-patches` during `composer install`.

If the patch ever stops applying (e.g. after an Open Social update that modifies that file), re-generate it from the current file:

```bash
# Inside the DDEV web container
cd /var/www/html/web/profiles/contrib/open_social
git diff modules/custom/mentions/src/Plugin/Filter/MentionsFilter.php \
  > /var/www/html/patches/mentions-filter-php8-fix.patch
```

---

## Missing field storages after `site:install`

### The symptom

`config:import` crashes with a database error referencing a non-existent column, typically from `activity_creator`'s `hook_entity_update` or `hook_entity_delete` querying a field that hasn't been created yet.

### The cause

Two field storages are present in `config/sync` but are **not** installed by `drush site:install social`:

- `field.storage.activity.field_activity_entity`
- `field.storage.group_content.field_grequest_message`

These must exist before `config:import` runs, because Open Social fires hooks during import that query them. Additionally, if `config:import` crashes mid-run, its transaction is rolled back — which removes any config entries for these field storages that were imported in that run. This means they must be pre-created **before every `config:import` attempt**, not just once.

### The fix

The `ddev post-install` script pre-creates both field storages via `FieldStorageConfig::create()` inside the retry loop, before each `config:import` call. See `.ddev/commands/web/post-install`.
