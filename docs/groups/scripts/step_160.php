<?php
/**
 * Step 160: Verify content type field configuration.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_160.php
 */

echo "=== Content type field verification ===\n";
foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $fields = \Drupal::service("entity_field.manager")->getFieldDefinitions("node", $type);
  $body = isset($fields["body"]) ? "YES" : "NO";
  $files = isset($fields["field_files"]) ? "YES" : "NO";
  $tags = isset($fields["field_tags"]) ? "YES" : "NO";
  $comment = "";
  foreach ($fields as $name => $def) {
    if ($def->getType() === "comment") { $comment = $name; break; }
  }
  echo "$type: body=$body files=$files tags=$tags comment=" . ($comment ?: "NONE") . "\n";
}

echo "\n=== Text format ===\n";
$format = \Drupal::config("filter.format.full_html");
echo "full_html status: " . ($format->get("status") ? "enabled" : "DISABLED") . "\n";

echo "\n=== Attachment field limits ===\n";
foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $field = \Drupal\field\Entity\FieldConfig::loadByName("node", $type, "field_files");
  if ($field) {
    echo "$type: max_filesize=" . $field->getSetting("max_filesize") . " extensions=" . $field->getSetting("file_extensions") . "\n";
  } else {
    echo "$type: field_files NOT FOUND\n";
  }
}
