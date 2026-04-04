<?php
/**
 * Step 200/210: Create group_type vocabulary and terms.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_200.php
 */

// Create vocabulary
$vocab = \Drupal::entityTypeManager()->getStorage("taxonomy_vocabulary")->load("group_type");
if (!$vocab) {
  \Drupal\taxonomy\Entity\Vocabulary::create([
    "vid" => "group_type",
    "name" => "Group Type",
    "description" => "Categorises groups (geographical, working group, distribution, etc.)",
  ])->save();
  echo "Created vocabulary: group_type\n";
} else {
  echo "group_type vocabulary already exists\n";
}

// Create terms
$terms = [
  ["Geographical", "Local user groups by city/region"],
  ["Working group", "Module, feature, or initiative coordination"],
  ["Distribution", "Drupal distribution projects"],
  ["Event planning", "DrupalCon and camp organising"],
  ["Archive", "Inactive groups (read-only)"],
];
foreach ($terms as [$name, $desc]) {
  $existing = \Drupal::entityTypeManager()->getStorage("taxonomy_term")
    ->loadByProperties(["vid" => "group_type", "name" => $name]);
  if (!$existing) {
    $term = \Drupal\taxonomy\Entity\Term::create([
      "vid" => "group_type",
      "name" => $name,
      "description" => ["value" => $desc, "format" => "plain_text"],
    ]);
    $term->save();
    echo "Created: $name (tid=" . $term->id() . ")\n";
  } else {
    echo "Exists: $name\n";
  }
}

echo count(\Drupal::entityTypeManager()->getStorage("taxonomy_term")->loadByProperties(["vid" => "group_type"])) . " group_type terms total\n";
