<?php
/**
 * Step 330: Create group_tags vocabulary and field_group_tags on all content types.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_330.php
 */

// Create group_tags vocabulary
$vocab = \Drupal::entityTypeManager()->getStorage("taxonomy_vocabulary")->load("group_tags");
if (!$vocab) {
  \Drupal\taxonomy\Entity\Vocabulary::create([
    "vid" => "group_tags",
    "name" => "Group Tags",
    "description" => "Tags for group content",
  ])->save();
  echo "Created: group_tags\n";
} else {
  echo "group_tags vocabulary already exists\n";
}

// Create sitewide tags vocabulary if missing
$tags = \Drupal\taxonomy\Entity\Vocabulary::load("tags");
if (!$tags) {
  \Drupal\taxonomy\Entity\Vocabulary::create([
    "vid" => "tags",
    "name" => "Tags",
    "description" => "Free tagging vocabulary for content classification",
  ])->save();
  echo "Created: tags\n";
} else {
  echo "tags vocabulary exists\n";
}

// Create field_group_tags storage
$fs = \Drupal\field\Entity\FieldStorageConfig::loadByName("node", "field_group_tags");
if (!$fs) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    "field_name" => "field_group_tags",
    "entity_type" => "node",
    "type" => "entity_reference",
    "cardinality" => -1,
    "settings" => ["target_type" => "taxonomy_term"],
  ])->save();
  echo "Created field storage\n";
} else {
  echo "field_group_tags storage exists\n";
}

// Add to each group-postable content type
foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $fi = \Drupal\field\Entity\FieldConfig::loadByName("node", $type, "field_group_tags");
  if (!$fi) {
    \Drupal\field\Entity\FieldConfig::create([
      "field_name" => "field_group_tags",
      "entity_type" => "node",
      "bundle" => $type,
      "label" => "Group Tags",
      "required" => FALSE,
      "settings" => [
        "handler" => "default:taxonomy_term",
        "handler_settings" => [
          "target_bundles" => ["group_tags" => "group_tags"],
          "auto_create" => TRUE,
        ],
      ],
    ])->save();
    echo "Created: $type.field_group_tags\n";
  } else {
    echo "Exists: $type.field_group_tags\n";
  }
}
