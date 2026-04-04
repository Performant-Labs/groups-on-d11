<?php
/**
 * Step 520: Create field_notification_frequency on user entity.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_520.php
 */

$field_storage = \Drupal::entityTypeManager()->getStorage("field_storage_config")->load("user.field_notification_frequency");
if (!$field_storage) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_notification_frequency",
    "entity_type" => "user",
    "type" => "list_string",
    "cardinality" => 1,
    "settings" => ["allowed_values" => [
      "immediately" => "Immediately",
      "daily" => "Daily digest",
      "weekly" => "Weekly digest",
    ]],
  ])->save();
  echo "Created field storage: field_notification_frequency\n";
} else {
  echo "Field storage exists\n";
}

$field = \Drupal\field\Entity\FieldConfig::loadByName("user", "user", "field_notification_frequency");
if (!$field) {
  \Drupal\field\Entity\FieldConfig::create([
    "field_name" => "field_notification_frequency",
    "entity_type" => "user",
    "bundle" => "user",
    "label" => "Notification frequency",
    "required" => TRUE,
    "default_value" => [["value" => "immediately"]],
  ])->save();
  echo "Created field instance: user.field_notification_frequency\n";
} else {
  echo "Field instance exists\n";
}
