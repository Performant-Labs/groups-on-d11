<?php
/**
 * Step 220: Add field_group_type to community_group.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_220.php
 */

$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName("group", "field_group_type");
if (!$fs) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_type",
    "entity_type" => "group",
    "type" => "entity_reference",
    "cardinality" => 1,
    "settings" => ["target_type" => "taxonomy_term"],
  ])->save();
  echo "storage ok\n";
} else {
  echo "storage exists\n";
}

$fi = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_type");
if (!$fi) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_type",
    "entity_type" => "group",
    "bundle" => "community_group",
    "label" => "Group Type",
    "required" => FALSE,
    "settings" => [
      "handler" => "default:taxonomy_term",
      "handler_settings" => ["target_bundles" => ["group_type" => "group_type"]],
    ],
  ])->save();
  echo "instance ok\n";
} else {
  echo "instance exists\n";
}
