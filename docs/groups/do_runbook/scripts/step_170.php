<?php
/**
 * Step 170: Create event_types taxonomy vocabulary and terms.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_170.php
 */

// Create vocabulary
$vocab = \Drupal::entityTypeManager()->getStorage("taxonomy_vocabulary")->load("event_types");
if (!$vocab) {
  \Drupal\taxonomy\Entity\Vocabulary::create([
    "vid" => "event_types",
    "name" => "Event Types",
    "description" => "Classification of events (DrupalCon, Sprint, Camp, etc.)",
  ])->save();
  echo "Created vocabulary: event_types\n";
} else {
  echo "event_types vocabulary already exists\n";
}

// Create terms
foreach ([
  "User group meeting",
  "Drupalcamp or Regional Summit",
  "DrupalCon",
  "Online meeting",
  "Training",
  "Sprint",
  "Related event (not Drupal-specific)",
] as $name) {
  $existing = \Drupal::entityTypeManager()->getStorage("taxonomy_term")
    ->loadByProperties(["vid" => "event_types", "name" => $name]);
  if (!$existing) {
    \Drupal\taxonomy\Entity\Term::create(["vid" => "event_types", "name" => $name])->save();
    echo "Created term: $name\n";
  } else {
    echo "Exists: $name\n";
  }
}

echo count(\Drupal::entityTypeManager()->getStorage("taxonomy_term")->loadByProperties(["vid" => "event_types"])) . " event_types terms total\n";
