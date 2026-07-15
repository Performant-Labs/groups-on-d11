<?php
/**
 * Step 630d: Create field_group_language on community_group.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_630d.php
 */

$field_storage = \Drupal::entityTypeManager()->getStorage("field_storage_config")->load("group.field_group_language");
if (!$field_storage) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_language",
    "entity_type" => "group",
    "type" => "language",
    "cardinality" => 1,
  ])->save();
  echo "Created field storage: field_group_language\n";
} else {
  echo "Field storage exists\n";
}

$field = \Drupal::entityTypeManager()->getStorage("field_config")->load("group.community_group.field_group_language");
if (!$field) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_language",
    "entity_type" => "group",
    "bundle" => "community_group",
    "label" => "Group Language",
    "required" => FALSE,
    "settings" => ["language_override" => "und"],
  ])->save();
  echo "Created field instance: community_group.field_group_language\n";
} else {
  echo "Field instance exists\n";
}

// Add to form display
$form_display = \Drupal::entityTypeManager()->getStorage("entity_form_display")->load("group.community_group.default");
if ($form_display && !$form_display->getComponent("field_group_language")) {
  $form_display->setComponent("field_group_language", [
    "type" => "language_select",
    "weight" => 20,
    "region" => "content",
  ])->save();
  echo "Added to form display\n";
}
