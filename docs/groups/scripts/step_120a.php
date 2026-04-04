<?php
/**
 * Step 120a: Create community_group group type and admin role.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_120a.php
 */

$gt_storage = \Drupal::entityTypeManager()->getStorage("group_type");
if ($gt_storage->load("community_group")) {
  echo "community_group already exists\n";
}
else {
  $gt_storage->create([
    "id" => "community_group",
    "label" => "Community Group",
    "description" => "A community group for collaboration, discussion, and coordination",
    "creator_membership" => TRUE,
    "creator_roles" => ["community_group-admin"],
  ])->save();
  echo "Created group type: community_group\n";
}

$role_storage = \Drupal::entityTypeManager()->getStorage("group_role");
if (!$role_storage->load("community_group-admin")) {
  $role_storage->create([
    "id" => "community_group-admin",
    "label" => "Admin",
    "weight" => 0,
    "group_type" => "community_group",
    "scope" => "individual",
    "admin" => TRUE,
  ])->save();
  echo "Created role: community_group-admin\n";
}
