<?php
/**
 * Step 130: Create group-node relationship types.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_130.php
 */

$storage = \Drupal::entityTypeManager()->getStorage("group_relationship_type");
$mappings = [
  "community_group-group_node-forum" => "group_node:forum",
  "community_group-group_node-doc"   => "group_node:documentation",
  "community_group-group_node-event" => "group_node:event",
  "community_group-group_node-post"  => "group_node:post",
  "community_group-group_node-page"  => "group_node:page",
];
foreach ($mappings as $id => $plugin) {
  if ($storage->load($id)) {
    echo "exists: $id\n";
    continue;
  }
  $storage->create([
    "id" => $id,
    "group_type" => "community_group",
    "content_plugin" => $plugin,
    "plugin_config" => [
      "group_cardinality" => 0,
      "entity_cardinality" => 1,
      "use_creation_wizard" => FALSE,
    ],
  ])->save();
  echo "created: $id\n";
}

// Verify
$all = $storage->loadMultiple();
foreach ($all as $rt) {
  echo $rt->id() . " plugin=" . $rt->getPluginId() . "\n";
}
echo "Total: " . count($all) . " relationship types\n";
