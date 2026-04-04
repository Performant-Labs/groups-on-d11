<?php
/**
 * Step 640: Multilingual infrastructure (languages + negotiation + content translation).
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_640.php
 */

// Add 14 languages
echo "=== Adding languages ===\n";
$langs = ["de","es","fr","it","ja","ko","nl","pl","pt-br","ru","tr","uk","zh-hans","ar"];
$storage = \Drupal::entityTypeManager()->getStorage("configurable_language");
foreach ($langs as $langcode) {
  if (!$storage->load($langcode)) {
    $storage->create(["id" => $langcode])->save();
    echo "Added: $langcode\n";
  } else {
    echo "Exists: $langcode\n";
  }
}
echo "Total languages: " . count($storage->loadMultiple()) . "\n";

// Configure language negotiation
echo "\n=== Configuring language negotiation ===\n";
$config = \Drupal::configFactory()->getEditable("language.types");
$config->set("negotiation.language_interface.enabled", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->set("negotiation.language_interface.method_weights", [
  "language-user" => -10,
  "language-group" => -5,
  "language-url" => -4,
  "language-selected" => 0,
]);
$config->save();
echo "Language negotiation configured\n";

// Enable content translation for group-postable types
echo "\n=== Enabling content translation ===\n";
foreach (["forum", "documentation", "event", "post", "page"] as $type) {
  $config = \Drupal::configFactory()->getEditable("language.content_settings.node." . $type);
  $config->set("third_party_settings.content_translation.enabled", TRUE);
  $config->save();
  echo "Enabled content translation for: $type\n";
}
