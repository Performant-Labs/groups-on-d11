<?php
/**
 * Step 700-750: ALL Phase 7 demo data in one script.
 * Creates users, groups, content, comments, flags, and subscriptions.
 * Usage: ddev drush php:script docs/groups/do_runbook/scripts/step_700_demo_data.php
 */

// ===== Step 700: Create demo users =====
echo "=== Step 700: Users ===\n";
$users = [
  ["maria_chen",   "maria.chen@example.com",   "Maria Chen — DrupalCon organizer and sprint lead"],
  ["james_okafor", "james.okafor@example.com", "James Okafor — Core committer, infrastructure lead"],
  ["elena_garcia", "elena.garcia@example.com", "Elena Garcia — Module maintainer, community organizer"],
  ["ravi_patel",   "ravi.patel@example.com",   "Ravi Patel — Thunder distribution contributor"],
  ["sophie_mueller","sophie.mueller@example.com","Sophie Mueller — UX designer, frontend lead"],
  ["alex_novak",   "alex.novak@example.com",   "Alex Novak — Camp organizer, community builder"],
];

foreach ($users as [$username, $email, $note]) {
  if (user_load_by_name($username)) { echo "Exists: $username\n"; continue; }
  $user = \Drupal\user\Entity\User::create([
    "name" => $username,
    "mail" => $email,
    "status" => 1,
    "pass" => "demo_password_2026",
  ]);
  $user->save();
  echo "Created uid=" . $user->id() . " $username ($note)\n";
}

// ===== Step 710: User profiles =====
echo "\n=== Step 710: Profiles ===\n";
$profiles = [
  "maria_chen" => ["field_first_name" => "Maria", "field_last_name" => "Chen"],
  "james_okafor" => ["field_first_name" => "James", "field_last_name" => "Okafor"],
  "elena_garcia" => ["field_first_name" => "Elena", "field_last_name" => "Garcia"],
  "ravi_patel" => ["field_first_name" => "Ravi", "field_last_name" => "Patel"],
  "sophie_mueller" => ["field_first_name" => "Sophie", "field_last_name" => "Mueller"],
  "alex_novak" => ["field_first_name" => "Alex", "field_last_name" => "Novak"],
];
foreach ($profiles as $username => $fields) {
  $user = user_load_by_name($username);
  if (!$user) { echo "SKIP: $username not found\n"; continue; }
  foreach ($fields as $field => $value) {
    if ($user->hasField($field)) { $user->set($field, $value); }
  }
  $user->save();
  echo "Profile saved: $username\n";
}

// ===== Step 720: Taxonomy tags =====
echo "\n=== Step 720: Tags ===\n";
$tags = ["sprint","drupalcon","logistics","theme","frontend","process","core","roadmap","distribution","paragraphs","tutorial","drupalcamp","recap","budget","migration","d10","welcome","community","policy","standup"];
$term_storage = \Drupal::entityTypeManager()->getStorage("taxonomy_term");
foreach ($tags as $tag) {
  $existing = $term_storage->loadByProperties(["vid" => "tags", "name" => $tag]);
  if (!$existing) {
    $term_storage->create(["vid" => "tags", "name" => $tag])->save();
    echo "Created tag: $tag\n";
  }
}
echo "Total tags: " . count($term_storage->loadByProperties(["vid" => "tags"])) . "\n";

// ===== Step 730: Groups =====
echo "\n=== Step 730: Groups ===\n";
$group_storage = \Drupal::entityTypeManager()->getStorage("group");
$group_defs = [
  ["DrupalCon Portland 2026", "Planning committee for DrupalCon Portland 2026. Everyone is welcome to contribute."],
  ["Drupal France", "Communauté Drupal francophone. Échangez sur les projets et participez aux événements."],
  ["Core Committers", "Coordinate core development sprints, review patch queues, and discuss architectural decisions."],
  ["Thunder Distribution", "Build and maintain the Thunder CMS distribution. Discuss roadmap and contribute modules."],
  ["Leadership Council", "Board-level discussions on platform strategy, governance, and organizational planning."],
  ["Camp Organizers EMEA", "Coordinate European and Middle Eastern DrupalCamps. Share resources and lessons learned."],
  ["Drupal Deutschland", "Die deutschsprachige Drupal-Community. Austausch über Projekte, Veranstaltungen und Best Practices."],
  ["Legacy Infrastructure", "Archived: Drupal 7 module maintenance coordination. This group is no longer active."],
];
foreach ($group_defs as [$label, $desc]) {
  $existing = $group_storage->loadByProperties(["label" => $label]);
  if ($existing) { echo "Exists: $label\n"; continue; }
  $group = \Drupal\group\Entity\Group::create([
    "type" => "community_group",
    "uid" => 1,
    "status" => 1,
    "label" => $label,
    "field_group_description" => ["value" => $desc, "format" => "basic_html"],
  ]);
  $group->save();
  // Add admin with the group admin role so Group 3.x access policy grants full access.
  // NOTE: bypass_node_access alone does NOT override Group 3.x entity access policies.
  $admin_user = \Drupal\user\Entity\User::load(1);
  $group->addMember($admin_user, ['group_roles' => ['community_group-admin']]);
  echo "Created gid=" . $group->id() . " $label\n";
}

// ===== Step 730a: Memberships =====
echo "\n=== Step 730a: Memberships ===\n";
$memberships = [
  "DrupalCon Portland 2026" => ["maria_chen", "james_okafor", "elena_garcia", "ravi_patel", "sophie_mueller"],
  "Core Committers" => ["james_okafor", "elena_garcia", "maria_chen"],
  "Thunder Distribution" => ["ravi_patel", "sophie_mueller"],
  "Leadership Council" => ["james_okafor", "maria_chen", "elena_garcia"],
  "Camp Organizers EMEA" => ["elena_garcia", "alex_novak"],
  "Drupal France" => ["elena_garcia"],
  "Drupal Deutschland" => ["sophie_mueller"],
];
foreach ($memberships as $group_label => $usernames) {
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if (!$group) { echo "SKIP: Group not found: $group_label\n"; continue; }
  foreach ($usernames as $username) {
    $user = user_load_by_name($username);
    if (!$user) { continue; }
    if (!$group->getMember($user)) {
      $group->addMember($user);
      echo "  $username joined $group_label\n";
    }
  }
}

// ===== Step 740a: Forum topics =====
echo "\n=== Step 740a: Forum topics ===\n";
$node_storage = \Drupal::entityTypeManager()->getStorage("node");
function get_tag_id_demo($name) {
  $terms = \Drupal::entityTypeManager()->getStorage("taxonomy_term")
    ->loadByProperties(["vid" => "tags", "name" => $name]);
  return $terms ? reset($terms)->id() : NULL;
}

$forums_vocab = $term_storage->loadByProperties(["vid" => "forums"]);
$forum_tid = $forums_vocab ? reset($forums_vocab)->id() : 1;

$maria = user_load_by_name("maria_chen");
$james = user_load_by_name("james_okafor");
$elena = user_load_by_name("elena_garcia");
$ravi = user_load_by_name("ravi_patel");
$sophie = user_load_by_name("sophie_mueller");
$alex = user_load_by_name("alex_novak");

$topics = [
  ["Sprint Planning: Portland 2026", $maria->id(), "Let us coordinate the sprint sessions for DrupalCon Portland 2026.", "DrupalCon Portland 2026", ["sprint", "drupalcon", "logistics"]],
  ["Venue Logistics Thread", $james->id(), "This thread is for discussing venue logistics — AV setup, room assignments, catering, and signage.", "DrupalCon Portland 2026", ["drupalcon", "logistics"]],
  ["Keynote Speaker Suggestions", $elena->id(), "Share your suggestions for keynote speakers.", "DrupalCon Portland 2026", ["drupalcon", "community"]],
  ["Patch Review Process RFC", $james->id(), "Proposing changes to how we handle patch review queues.", "Core Committers", ["core", "process", "roadmap"]],
  ["Drupal 11 Migration Path", $elena->id(), "Discussion about the upgrade path from Drupal 10 to 11.", "Core Committers", ["core", "migration", "d10"]],
  ["Weekly Standup Notes", $maria->id(), "Rolling thread for weekly standup notes.", "Core Committers", ["core", "standup"]],
  ["Getting Started with Paragraphs", $ravi->id(), "A tutorial on setting up the Paragraphs module.", "Thunder Distribution", ["tutorial", "paragraphs"]],
  ["Thunder 7 Roadmap Discussion", $sophie->id(), "Planning the next major release.", "Thunder Distribution", ["roadmap", "distribution"]],
  ["Community Code of Conduct", $james->id(), "Proposed community code of conduct for all Drupal groups.", "Leadership Council", ["community", "policy"]],
  ["Budget Allocation Q3 2026", $maria->id(), "Review and approve Q3 budget allocations.", "Leadership Council", ["budget", "policy"]],
  ["DrupalCamp Barcelona Recap", $elena->id(), "Recap and lessons learned from DrupalCamp Barcelona.", "Camp Organizers EMEA", ["drupalcamp", "recap"]],
  ["Shared Resources for Camp Organizers", $alex->id(), "Collection of templates and checklists for organizing a DrupalCamp.", "Camp Organizers EMEA", ["drupalcamp", "logistics"]],
];

foreach ($topics as [$title, $uid, $body, $group_label, $tag_names]) {
  $existing = $node_storage->loadByProperties(["title" => $title]);
  if ($existing) { echo "Exists: $title\n"; continue; }
  $tag_refs = [];
  foreach ($tag_names as $tname) {
    $tid = get_tag_id_demo($tname);
    if ($tid) { $tag_refs[] = ["target_id" => $tid]; }
  }
  $node = $node_storage->create([
    "type" => "forum", "title" => $title, "uid" => $uid, "status" => 1,
    "body" => ["value" => $body, "format" => "basic_html"],
    "taxonomy_forums" => $forum_tid,
  ]);
  if ($node->hasField("field_tags")) { $node->set("field_tags", $tag_refs); }
  $node->save();
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if ($group) {
    try { $group->addRelationship($node, "group_node:forum"); } catch (\Exception $e) {}
  }
  echo "Topic nid=" . $node->id() . " in $group_label\n";
}

// ===== Step 740b: Events =====
echo "\n=== Step 740b: Events ===\n";
$events = [
  ["DrupalCon Portland Keynote", $james->id(), "Opening keynote for DrupalCon Portland 2026.", "DrupalCon Portland 2026", 90],
  ["Code Sprint: Migrate API", $elena->id(), "Focused sprint on the Migrate API.", "DrupalCon Portland 2026", 91],
  ["DrupalCamp Barcelona", $elena->id(), "Annual DrupalCamp Barcelona.", "Camp Organizers EMEA", 60],
  ["Thunder Editorial Workshop", $sophie->id(), "Hands-on workshop for content editors.", "Thunder Distribution", 45],
  ["Governance Town Hall", $james->id(), "Open town hall to discuss governance changes.", "Leadership Council", 30],
];
foreach ($events as [$title, $uid, $body, $group_label, $offset]) {
  $existing = $node_storage->loadByProperties(["title" => $title]);
  if ($existing) { echo "Exists: $title\n"; continue; }
  $node = $node_storage->create([
    "type" => "event", "title" => $title, "uid" => $uid, "status" => 1,
    "body" => ["value" => $body, "format" => "basic_html"],
  ]);
  if ($node->hasField("field_date_of_event")) {
    $node->set("field_date_of_event", date("Y-m-d\TH:i:s", strtotime("+$offset days")));
  }
  $node->save();
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if ($group) {
    try { $group->addRelationship($node, "group_node:event"); } catch (\Exception $e) {}
  }
  echo "Event nid=" . $node->id() . " in $group_label (+$offset days)\n";
}

// ===== Step 740c: Comments =====
echo "\n=== Step 740c: Comments ===\n";
$comment_storage = \Drupal::entityTypeManager()->getStorage("comment");
$fields_def = \Drupal::service("entity_field.manager")->getFieldDefinitions("node", "forum");
$comment_field = NULL;
foreach ($fields_def as $name => $def) {
  if ($def->getType() === "comment") { $comment_field = $name; break; }
}
if (!$comment_field) { echo "ERROR: No comment field on forum nodes\n"; }
else {
  $comments_data = [
    ["Venue Logistics Thread", $elena->id(), "I can help with AV setup. What equipment do we need?"],
    ["Venue Logistics Thread", $ravi->id(), "Room C has poor Wi-Fi coverage — we should test before the event."],
    ["Patch Review Process RFC", $sophie->id(), "I support this proposal. The current backlog is unsustainable."],
    ["Patch Review Process RFC", $elena->id(), "Can we add automated checks to reduce manual review time?"],
    ["Getting Started with Paragraphs", $sophie->id(), "Great tutorial! One suggestion: add a section on nested paragraphs."],
    ["Community Code of Conduct", $elena->id(), "This is excellent. I suggest we add a section on online conduct during sprints."],
  ];
  foreach ($comments_data as [$title, $uid, $body]) {
    $nodes = $node_storage->loadByProperties(["title" => $title]);
    $node = reset($nodes);
    if (!$node) { continue; }
    $comment = $comment_storage->create([
      "entity_type" => "node", "entity_id" => $node->id(), "field_name" => $comment_field,
      "uid" => $uid, "comment_type" => "comment",
      "subject" => substr($body, 0, 60),
      "comment_body" => ["value" => $body, "format" => "basic_html"], "status" => 1,
    ]);
    $comment->save();
    echo "Comment cid=" . $comment->id() . " on \"$title\"\n";
  }
}

// ===== Step 750: Flags =====
echo "\n=== Step 750: Flags ===\n";
$flag_service = \Drupal::service("flag");

// Pin content
$pin_flag = $flag_service->getFlagById("pin_in_group");
if ($pin_flag) {
  $nodes = $node_storage->loadByProperties(["title" => "Sprint Planning: Portland 2026"]);
  $node = reset($nodes);
  if ($node) {
    try { $flag_service->flag($pin_flag, $node, \Drupal\user\Entity\User::load(1)); echo "Pinned: Sprint Planning\n"; }
    catch (\Exception $e) { echo "Already pinned or error\n"; }
  }
}

// Promote to homepage
$promo_flag = $flag_service->getFlagById("promote_homepage");
if ($promo_flag) {
  foreach (["Getting Started with Paragraphs", "Community Code of Conduct"] as $title) {
    $nodes = $node_storage->loadByProperties(["title" => $title]);
    $node = reset($nodes);
    if ($node) {
      try { $flag_service->flag($promo_flag, $node, \Drupal\user\Entity\User::load(1)); echo "Promoted: $title\n"; }
      catch (\Exception $e) {}
    }
  }
}

// Follow content
$follow_content = $flag_service->getFlagById("follow_content");
if ($follow_content) {
  $nodes = $node_storage->loadByProperties(["title" => "Patch Review Process RFC"]);
  $node = reset($nodes);
  if ($node && $elena) {
    try { $flag_service->flag($follow_content, $node, $elena); echo "elena follows: Patch Review Process RFC\n"; }
    catch (\Exception $e) {}
  }
}

// Follow term
$follow_term = $flag_service->getFlagById("follow_term");
if ($follow_term) {
  $terms = $term_storage->loadByProperties(["vid" => "tags", "name" => "core"]);
  $core_term = reset($terms);
  if ($core_term && $elena) {
    try { $flag_service->flag($follow_term, $core_term, $elena); echo "elena follows tag: core\n"; }
    catch (\Exception $e) {}
  }
}

// Follow user
$follow_user = $flag_service->getFlagById("follow_user");
if ($follow_user && $ravi && $maria) {
  try { $flag_service->flag($follow_user, $maria, $ravi); echo "ravi follows user: maria_chen\n"; }
  catch (\Exception $e) {}
}

// Archive Legacy Infrastructure
$groups = $group_storage->loadByProperties(["label" => "Legacy Infrastructure"]);
$g = reset($groups);
if ($g) { $g->set("status", 0); $g->save(); echo "Legacy Infrastructure archived\n"; }

// RSVP for events
$rsvp_flag = $flag_service->getFlagById("rsvp_event");
if ($rsvp_flag) {
  $enrollments = [
    ["DrupalCon Portland Keynote", ["elena_garcia", "ravi_patel", "sophie_mueller", "alex_novak"]],
    ["Code Sprint: Migrate API", ["elena_garcia", "ravi_patel"]],
    ["DrupalCamp Barcelona", ["elena_garcia", "ravi_patel", "alex_novak"]],
  ];
  foreach ($enrollments as [$title, $usernames]) {
    $nodes = $node_storage->loadByProperties(["title" => $title]);
    $node = reset($nodes);
    if (!$node) { continue; }
    foreach ($usernames as $username) {
      $user = user_load_by_name($username);
      if ($user) {
        try { $flag_service->flag($rsvp_flag, $node, $user); echo "RSVP: $username → $title\n"; }
        catch (\Exception $e) {}
      }
    }
  }
}

echo "\n=== Demo data complete ===\n";
