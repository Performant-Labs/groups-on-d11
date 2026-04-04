<?php
/**
 * Step 300: Set entity_cardinality=0 on all relationship types (multi-group posting).
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_300.php
 */

$storage = \Drupal::entityTypeManager()->getStorage("group_relationship_type");
$types = [
  "community_group-group_node-forum",
  "community_group-group_node-doc",
  "community_group-group_node-event",
  "community_group-group_node-post",
  "community_group-group_node-page",
];
foreach ($types as $id) {
  $type = $storage->load($id);
  if ($type) {
    $config = $type->get("plugin_config") ?: [];
    $config["entity_cardinality"] = 0;
    $type->set("plugin_config", $config);
    $type->save();
    echo "Set entity_cardinality=0: $id\n";
  } else {
    echo "NOT FOUND: $id\n";
  }
}
