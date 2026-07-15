<?php
/**
 * Step 620c: Place do_group_mission block in bluecheese sidebar.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_620c.php
 */

$block_storage = \Drupal::entityTypeManager()->getStorage("block");
if (!$block_storage->load("do_group_mission")) {
  $block_storage->create([
    "id" => "do_group_mission",
    "plugin" => "do_group_mission",
    "region" => "sidebar_first",
    "theme" => "bluecheese",
    "weight" => 5,
    "settings" => [
      "id" => "do_group_mission",
      "label" => "Group Mission",
      "label_display" => "0",
      "provider" => "do_group_mission",
    ],
    "visibility" => [
      "request_path" => [
        "id" => "request_path",
        "pages" => "/group/*",
        "negate" => FALSE,
      ],
    ],
  ])->save();
  echo "Group Mission block placed in sidebar_first\n";
} else {
  echo "Block already placed\n";
}
