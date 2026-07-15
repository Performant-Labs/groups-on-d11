<?php
/**
 * Step 770: Create Solr search server and index config.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_770.php
 */

// Check existing search config
echo "=== Existing search configuration ===\n";
try {
  $servers = \Drupal::entityTypeManager()->getStorage("search_api_server")->loadMultiple();
  foreach ($servers as $s) {
    echo "Server: " . $s->id() . " backend=" . $s->getBackendId() . " status=" . ($s->status() ? "enabled" : "disabled") . "\n";
  }
  $indexes = \Drupal::entityTypeManager()->getStorage("search_api_index")->loadMultiple();
  foreach ($indexes as $i) {
    echo "Index: " . $i->id() . " server=" . $i->getServerId() . " status=" . ($i->status() ? "enabled" : "disabled") . "\n";
  }
} catch (\Exception $e) {
  echo "search_api not installed\n";
}

// Create Solr server if missing
if (!\Drupal\search_api\Entity\Server::load("solr_server")) {
  \Drupal\search_api\Entity\Server::create([
    "id" => "solr_server",
    "name" => "Solr Server",
    "backend" => "search_api_solr",
    "backend_config" => [
      "connector" => "solr_cloud",
      "connector_config" => [
        "scheme" => "http",
        "host" => "solr",
        "port" => 8983,
        "path" => "/",
        "core" => "",
        "collection" => "drupal",
        "solr_version" => "9",
      ],
    ],
    "status" => TRUE,
  ])->save();
  echo "Created solr_server\n";
} else {
  echo "solr_server already exists\n";
}
