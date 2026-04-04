<?php
/**
 * Step 760: Multilingual demo data (group languages + French/German content).
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_760.php
 */

$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
$tag_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");

// Set group languages
echo "=== Setting group languages ===\n";
$group_languages = [
  "Drupal France" => "fr",
  "Drupal Deutschland" => "de",
];
foreach ($group_languages as $label => $langcode) {
  $groups = $group_storage->loadByProperties(["label" => $label]);
  $group = reset($groups);
  if (!$group) { echo "ERROR: Group not found: $label\n"; continue; }
  if (!$group->hasField("field_group_language")) { echo "ERROR: field_group_language missing\n"; continue; }
  $group->set("field_group_language", $langcode);
  $group->save();
  echo "Set $label language to $langcode\n";
}

// Add field_group_language to form display (if not already done)
$form_display = \Drupal::entityTypeManager()->getStorage("entity_form_display")->load("group.community_group.default");
if ($form_display && !$form_display->getComponent("field_group_language")) {
  $form_display->setComponent("field_group_language", [
    "type" => "language_select",
    "weight" => 20,
    "region" => "content",
  ])->save();
  echo "Added field_group_language to form display\n";
}

// Get forum taxonomy term
$forums = $tag_storage->loadByProperties(["vid" => "forums"]);
$forum_tid = $forums ? reset($forums)->id() : 1;

$elena = user_load_by_name("elena_garcia");
$sophie = user_load_by_name("sophie_mueller");

// French content
echo "\n=== French content ===\n";
$groups = $group_storage->loadByProperties(["label" => "Drupal France"]);
$france = reset($groups);
if ($france) {
  $french_topics = [
    ["Bienvenue dans Drupal France", $elena ? $elena->id() : 1,
     "Bienvenue dans notre communauté francophone ! Présentez-vous et partagez vos projets Drupal."],
    ["Organisation du DrupalCamp Paris 2026", $sophie ? $sophie->id() : 1,
     "Discussion autour de l'organisation du DrupalCamp Paris. Nous cherchons des bénévoles et des sponsors."],
    ["Traduction de Drupal 11 en français", $elena ? $elena->id() : 1,
     "Coordination de l'effort de traduction pour Drupal 11. Rejoignez l'équipe de traduction sur localize.drupal.org."],
  ];
  foreach ($french_topics as [$title, $uid, $body]) {
    $existing = $node_storage->loadByProperties(["title" => $title]);
    if ($existing) { echo "Exists: $title\n"; continue; }
    $node = $node_storage->create([
      "type" => "forum", "title" => $title, "uid" => $uid, "status" => 1,
      "langcode" => "fr",
      "body" => ["value" => $body, "format" => "basic_html"],
      "taxonomy_forums" => $forum_tid,
    ]);
    $node->save();
    try { $france->addRelationship($node, "group_node:forum"); } catch (\Exception $e) {}
    echo "French topic: nid=" . $node->id() . " $title\n";
  }
}

// German content
echo "\n=== German content ===\n";
$groups = $group_storage->loadByProperties(["label" => "Drupal Deutschland"]);
$deutschland = reset($groups);
if ($deutschland) {
  $german_topics = [
    ["Willkommen bei Drupal Deutschland", $sophie ? $sophie->id() : 1,
     "Herzlich willkommen in der deutschsprachigen Drupal-Community! Stellt euch vor und teilt eure Projekte."],
    ["Barrierefreiheit in Drupal-Themes", $sophie ? $sophie->id() : 1,
     "Best Practices für barrierefreie Drupal-Themes. Diskutiert WCAG-Konformität und Screenreader-Kompatibilität."],
  ];
  foreach ($german_topics as [$title, $uid, $body]) {
    $existing = $node_storage->loadByProperties(["title" => $title]);
    if ($existing) { echo "Exists: $title\n"; continue; }
    $node = $node_storage->create([
      "type" => "forum", "title" => $title, "uid" => $uid, "status" => 1,
      "langcode" => "de",
      "body" => ["value" => $body, "format" => "basic_html"],
      "taxonomy_forums" => $forum_tid,
    ]);
    $node->save();
    try { $deutschland->addRelationship($node, "group_node:forum"); } catch (\Exception $e) {}
    echo "German topic: nid=" . $node->id() . " $title\n";
  }
}

// Verify
echo "\n=== Verification ===\n";
foreach (["Drupal France" => "fr", "Drupal Deutschland" => "de"] as $label => $expected) {
  $groups = $group_storage->loadByProperties(["label" => $label]);
  $group = reset($groups);
  if ($group && $group->hasField("field_group_language")) {
    $actual = $group->get("field_group_language")->value;
    echo "$label language: $actual " . ($actual === $expected ? "OK" : "MISMATCH") . "\n";
  }
}
foreach (["fr", "de"] as $lang) {
  $count = \Drupal::entityQuery("node")->condition("langcode", $lang)->accessCheck(FALSE)->count()->execute();
  echo "$lang-language nodes: $count\n";
}
