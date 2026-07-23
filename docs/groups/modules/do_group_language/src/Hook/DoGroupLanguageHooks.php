<?php

declare(strict_types=1);

namespace Drupal\do_group_language\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\field\FieldStorageConfigInterface;

/**
 * Hook implementations for do_group_language.
 *
 * #142 MC-3 (directory location + primary-language exposed filters): core's
 * generic {@see \Drupal\views\FieldViewsDataProvider} default field
 * implementation has no special case for the `language` field type — its
 * per-column filter/argument/sort dispatch switches on the raw SQL COLUMN
 * type only (`FieldViewsDataProvider::defaultFieldImplementation()` line
 * ~338), and `language`-typed fields store a `varchar_ascii` column, which
 * falls through to the generic `string` filter plugin. This differs from a
 * BASE field of type `language` (e.g. `langcode`), which core's
 * {@see \Drupal\views\EntityViewsData::mapSingleFieldViewsData()} DOES
 * special-case to the `language` filter plugin — but `field_group_language`
 * is a bundle-attached configurable field on `group.community_group`, not a
 * base field, so that special case never applies to it.
 *
 * The story's own acceptance criteria (and A's approved plan) pin the
 * exposed filter on `field_group_language` to `plugin_id: language` (core's
 * {@see \Drupal\views\Plugin\views\filter\LanguageFilter}, which renders a
 * language-name select and — on a multilingual site — filters by langcode).
 * A view's stored `plugin_id` config key is schema/admin-UI metadata only;
 * the REAL handler class Views instantiates at runtime is resolved purely
 * from {@see \Drupal\views\Plugin\ViewsHandlerManager::getHandler()} reading
 * the Views-data `filter.id` for the target table/column (verified by
 * tracing `ViewsHandlerManager::getHandler()` — the stored config `plugin_id`
 * key is never passed as its `$override_plugin_id` argument, which is
 * reserved for Views' "Aggregation"/Group-By feature only). So the exposed
 * filter in `docs/groups/config/views.view.all_groups.yml` can declare
 * `plugin_id: language` all it wants — without this alter, Views will still
 * silently instantiate `StringFilter` for it.
 *
 * This hook rewrites just the one Views-data key the language filter
 * actually uses (`field_group_language_value`, the dedicated-table column
 * {@see \Drupal\views\Plugin\ViewsHandlerManager::getHandler()} resolves),
 * scoped to this exact field on this exact entity type, matching core's own
 * targeted per-field alter pattern in `views.api.php`'s
 * `hook_field_views_data_alter()` example.
 */
class DoGroupLanguageHooks {

  /**
   * Wires field_group_language's exposed filter to the real Language plugin.
   *
   * Scoped narrowly to `group.field_group_language` — this hook fires for
   * every field storage in every module, so an unscoped alter would corrupt
   * Views data for unrelated fields.
   */
  #[Hook('field_views_data_alter')]
  public function fieldViewsDataAlter(array &$data, FieldStorageConfigInterface $field_storage): void {
    if ($field_storage->getTargetEntityTypeId() !== 'group' || $field_storage->getName() !== 'field_group_language') {
      return;
    }

    foreach ($data as $table_name => $table_data) {
      if (isset($data[$table_name]['field_group_language_value']['filter'])) {
        $data[$table_name]['field_group_language_value']['filter']['id'] = 'language';
      }
    }
  }

}
