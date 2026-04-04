<?php
/**
 * Step 120b: Create fields on community_group group type.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_120b.php
 */

// field_group_description
$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName("group", "field_group_description");
if (!$fs) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_description",
    "entity_type" => "group",
    "type" => "text_with_summary",
    "cardinality" => 1,
  ])->save();
}
$fi = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_description");
if (!$fi) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_description",
    "entity_type" => "group",
    "bundle" => "community_group",
    "label" => "Description",
    "required" => TRUE,
  ])->save();
}
echo "field_group_description ok\n";

// field_group_visibility
$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName("group", "field_group_visibility");
if (!$fs) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_visibility",
    "entity_type" => "group",
    "type" => "list_string",
    "cardinality" => 1,
    "settings" => ["allowed_values" => ["open" => "Open", "moderated" => "Moderated", "invite_only" => "Invite Only"]],
  ])->save();
}
$fi = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_visibility");
if (!$fi) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_visibility",
    "entity_type" => "group",
    "bundle" => "community_group",
    "label" => "Visibility",
    "required" => TRUE,
    "default_value" => [["value" => "open"]],
  ])->save();
}
echo "field_group_visibility ok\n";

// field_group_image
$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName("group", "field_group_image");
if (!$fs) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_image",
    "entity_type" => "group",
    "type" => "image",
    "cardinality" => 1,
  ])->save();
}
$fi = \Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", "field_group_image");
if (!$fi) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_group_image",
    "entity_type" => "group",
    "bundle" => "community_group",
    "label" => "Group Image",
    "required" => FALSE,
  ])->save();
}
echo "field_group_image ok\n";

// Verify
foreach (["field_group_description", "field_group_visibility", "field_group_image"] as $f) {
  echo "$f: " . (\Drupal\field\Entity\FieldConfig::loadByName("group", "community_group", $f) ? "OK" : "MISSING") . "\n";
}
