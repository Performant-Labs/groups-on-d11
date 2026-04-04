<?php
/**
 * Step 315: Create stream_card view mode and configure displays.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_315.php
 */

// Create view mode
$vm_storage = \Drupal::entityTypeManager()->getStorage("entity_view_mode");
if (!$vm_storage->load("node.stream_card")) {
  $vm_storage->create([
    "id" => "node.stream_card",
    "label" => "Stream Card",
    "targetEntityType" => "node",
  ])->save();
  echo "Created view mode: node.stream_card\n";
} else {
  echo "View mode exists\n";
}

// Enable stream_card for all group-postable content types
$types = ["forum", "documentation", "event", "post", "page"];
foreach ($types as $type) {
  $display = \Drupal::entityTypeManager()
    ->getStorage("entity_view_display")
    ->load("node.$type.stream_card");
  if (!$display) {
    \Drupal\Core\Entity\Entity\EntityViewDisplay::create([
      "targetEntityType" => "node",
      "bundle" => $type,
      "mode" => "stream_card",
      "status" => TRUE,
    ])->save();
    echo "Enabled stream_card for: $type\n";
  } else {
    echo "stream_card already enabled for: $type\n";
  }
}

// Configure displays
foreach ($types as $type) {
  $display = \Drupal::entityTypeManager()
    ->getStorage("entity_view_display")
    ->load("node.$type.stream_card");
  if (!$display) { echo "SKIP: $type (no stream_card display)\n"; continue; }

  // Trimmed body
  $display->setComponent("body", [
    "type" => "text_trimmed",
    "label" => "hidden",
    "settings" => ["trim_length" => 300],
    "weight" => 1,
  ]);

  // Author
  $display->setComponent("uid", [
    "type" => "author",
    "label" => "hidden",
    "weight" => 0,
  ]);

  // Created date
  $display->setComponent("created", [
    "type" => "timestamp",
    "label" => "hidden",
    "settings" => ["date_format" => "medium"],
    "weight" => 2,
  ]);

  // Tags
  $field_defs = \Drupal::service("entity_field.manager")->getFieldDefinitions("node", $type);
  if (isset($field_defs["field_tags"])) {
    $display->setComponent("field_tags", [
      "type" => "entity_reference_label",
      "label" => "hidden",
      "settings" => ["link" => TRUE],
      "weight" => 3,
    ]);
  }

  // Comments
  $comment_field = NULL;
  foreach ($field_defs as $fname => $fdef) {
    if ($fdef->getType() === "comment") { $comment_field = $fname; break; }
  }
  if ($comment_field) {
    $display->setComponent($comment_field, [
      "type" => "comment_default",
      "label" => "hidden",
      "settings" => ["pager_id" => 0],
      "weight" => 10,
    ]);
  }

  $display->save();
  echo "Configured stream_card display: $type\n";
}

// Verify
$vm = \Drupal::entityTypeManager()->getStorage("entity_view_mode")->load("node.stream_card");
echo "\nstream_card view mode: " . ($vm ? "OK" : "MISSING") . "\n";
