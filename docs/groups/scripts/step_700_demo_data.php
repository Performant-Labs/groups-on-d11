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

// ===== Step 740d: Documentation pages =====
// Added for #122 (SC-3, group-type-driven homepages): the docs-first
// exemplar (Thunder Distribution, field_group_type = "Distribution") had ZERO
// seeded documentation-type nodes anywhere, so the docs-first lead section had
// no real content to render against — every prior E2E assertion for this
// exemplar degraded vacuously to the empty-state/fallback path (see
// docs/planning/handoffs/122-grouptype-home/handoff-T-red.md, "RED-validity
// note" on the Thunder Distribution test). These 3 nodes give the docs-first
// path real content to render, matching the same idiomatic seed shape as the
// forum/event blocks above (idempotent title-existence guard).
//
// Group::addRelationship($node, "group_node:documentation") is NOT used here
// (unlike the forum/event blocks above) because it is broken for THIS
// specific plugin on THIS group type: GroupRelationshipTypeStorage::
// getRelationshipTypeId() always re-derives the "preferred" bundle id as
// "{group_type}-{plugin_id with : -> -}" (here, the 40-char
// "community_group-group_node-documentation"), but Drupal bundle ids are
// capped at EntityTypeInterface::BUNDLE_MAX_LENGTH (32), so the entity that
// was ACTUALLY provisioned at group-type-creation time silently got a
// different (truncated) id ("community_group-group_node-doc" here). The
// re-derivation never consults the DB for that fallback id, so
// addRelationship() throws (an AssertionError, not \Exception — verified via
// GroupRelationshipStorage::getEntityClass()) for any group_node plugin
// whose "preferred" id would exceed 32 chars. "forum" (32 chars exactly) and
// "event" (32 chars exactly) both happen to fit; "documentation" (40 chars)
// does not. Resolved here by looking up the ACTUAL relationship-type
// entity by matching getPluginId() (idempotent, safe if re-run) rather than
// recomputing the id, then creating the group_relationship entity directly
// — the same two steps createForEntityInGroup() performs internally, minus
// its broken id recomputation.
$doc_relationship_type_id = NULL;
$community_group_relationship_types = \Drupal::entityTypeManager()
  ->getStorage("group_relationship_type")
  ->loadByProperties(["group_type" => "community_group"]);
foreach ($community_group_relationship_types as $rel_type) {
  if ($rel_type->getPluginId() === "group_node:documentation") {
    $doc_relationship_type_id = $rel_type->id();
    break;
  }
}
if (!$doc_relationship_type_id) {
  echo "ERROR: no community_group relationship type wired for group_node:documentation — skipping Step 740d\n";
}
else {
  echo "\n=== Step 740d: Documentation pages ===\n";
  $group_relationship_storage = \Drupal::entityTypeManager()->getStorage("group_relationship");
  $docs = [
    ["Getting Started with Thunder", $sophie->id(), "A walkthrough for spinning up a fresh Thunder Distribution install and understanding its editorial workflow basics.", "Thunder Distribution"],
    ["Upgrading from Thunder 7 to 8", $ravi->id(), "Step-by-step guidance for migrating an existing Thunder 7 site to the Thunder 8 codebase, including breaking-change notes.", "Thunder Distribution"],
    ["Media Library Configuration Guide", $sophie->id(), "Reference documentation for configuring Thunder's media library — bundles, view displays, and the editorial embed workflow.", "Thunder Distribution"],
  ];
  foreach ($docs as [$title, $uid, $body, $group_label]) {
    // Idempotency note: the node-existence check and the relationship
    // creation are DELIBERATELY separate (unlike the simpler "exists ->
    // continue" pattern in the forum/event blocks above), so a prior run
    // that created the node but failed before the relationship save (e.g.
    // the AssertionError this exact code fixes) self-heals on a re-run
    // instead of leaving a permanently orphaned node.
    $existing_nodes = $node_storage->loadByProperties(["title" => $title]);
    $node = reset($existing_nodes);
    if ($node) {
      echo "Exists: $title\n";
    }
    else {
      $node = $node_storage->create([
        "type" => "documentation", "title" => $title, "uid" => $uid, "status" => 1,
        "body" => ["value" => $body, "format" => "basic_html"],
      ]);
      $node->save();
      echo "Doc nid=" . $node->id() . " in $group_label\n";
    }

    $groups = $group_storage->loadByProperties(["label" => $group_label]);
    $group = reset($groups);
    if (!$group) {
      continue;
    }
    $already_related = FALSE;
    foreach ($group->getRelationships("group_node:documentation") as $rel) {
      if ($rel->getEntity() && (int) $rel->getEntity()->id() === (int) $node->id()) {
        $already_related = TRUE;
        break;
      }
    }
    if ($already_related) {
      continue;
    }
    try {
      $relationship = $group_relationship_storage->create([
        "type" => $doc_relationship_type_id,
        "gid" => $group->id(),
        "entity_id" => $node->id(),
      ]);
      $relationship->save();
      echo "  Related nid=" . $node->id() . " to $group_label\n";
    }
    catch (\Exception $e) {
      echo "  WARNING: relationship create failed for $title: " . $e->getMessage() . "\n";
    }
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

// ===== Step 780: Header navigation (CH-A1, #83) =====
// Seed the primary-nav menu links so the groups_chrome main-menu block renders
// the community navigation. Kept in its own file (issue #83 owns the nav).
$__nav_step = __DIR__ . '/step_780_nav_menu.php';
if (is_file($__nav_step)) {
  require $__nav_step;
}

// ===== Step 790: Join-policy visibility + seeded pending requests (#121 SC-2) =====
// Append-only, idempotent (brief-response.md §5 / brief-response-v2.md §NIT):
// sets Leadership Council to `moderated` and Core Committers to
// `invite_only` (every other seeded group keeps the field's own `open`
// default_value, already applied at group-creation time in Step 730 — see
// docs/groups/config/field.field.group.community_group.field_group_visibility.yml).
// Seeds TWO pending group_membership requests on Leadership Council
// (sophie_mueller AND alex_novak, per the accepted NIT) so the organizer's
// EXISTING /group/{group}/members page (ManageMembersForm, #138) demos more
// than one pending row and row-action isolation (approve one, deny the
// other) — no new organizer surface is created for this story (v2 §A-1).
echo "\n=== Step 790: Join-policy visibility + pending requests (#121) ===\n";
$visibility_by_label = [
  "Leadership Council" => "moderated",
  "Core Committers" => "invite_only",
];
foreach ($visibility_by_label as $group_label => $visibility) {
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if (!$group) { echo "SKIP: Group not found: $group_label\n"; continue; }
  if (!$group->hasField("field_group_visibility")) { echo "SKIP: field_group_visibility missing on $group_label\n"; continue; }
  if ($group->get("field_group_visibility")->value !== $visibility) {
    $group->set("field_group_visibility", $visibility);
    $group->save();
    echo "Set $group_label visibility -> $visibility\n";
  }
  else {
    echo "$group_label already $visibility\n";
  }
}

$leadership_council_groups = $group_storage->loadByProperties(["label" => "Leadership Council"]);
$leadership_council = reset($leadership_council_groups);
if ($leadership_council && $sophie && $alex) {
  foreach ([["sophie_mueller", $sophie], ["alex_novak", $alex]] as [$username, $requester]) {
    if (empty($leadership_council->getRelationshipsByEntity($requester, "group_membership"))) {
      $leadership_council->addMember($requester, [
        "group_roles" => [],
        "field_membership_status" => [["value" => "pending"]],
      ]);
      echo "  $username requested to join Leadership Council (pending)\n";
    }
    else {
      echo "  $username already has a Leadership Council membership/request\n";
    }
  }
}
else {
  echo "SKIP: pending-request seed — Leadership Council group or sophie/alex user not found\n";
}

// ===== Step 735: Group Links & Resources (#140 MC-1) =====
// Append-only, idempotent (skip a group already carrying field_group_links
// values so a re-run never duplicates deltas). Titles/URLs are the EXACT
// canonical set recorded in
// docs/planning/handoffs/140-links/handoff-T-red.md "Seed link titles" —
// tests/e2e/group-links.spec.ts asserts against the "DrupalCon Portland 2026"
// row verbatim, and the brief's "≥3 seeded groups show ≥2 links each"
// acceptance criterion is satisfied by all three rows below.
echo "\n=== Step 735: Group Links & Resources (#140) ===\n";
$group_links_by_label = [
  "DrupalCon Portland 2026" => [
    ["title" => "Conference schedule", "uri" => "https://events.drupal.org/portland2026/schedule"],
    ["title" => "Sponsorship info", "uri" => "https://events.drupal.org/portland2026/sponsors"],
  ],
  "Core Committers" => [
    ["title" => "Core Gitlab", "uri" => "https://git.drupalcode.org/project/drupal"],
    ["title" => "Core issue queue", "uri" => "https://drupal.org/project/issues/drupal"],
  ],
  "Thunder Distribution" => [
    ["title" => "Thunder homepage", "uri" => "https://thunder.org"],
    ["title" => "Thunder repo", "uri" => "https://github.com/thunder/thunder-distribution"],
  ],
];
foreach ($group_links_by_label as $group_label => $links) {
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if (!$group) { echo "SKIP: Group not found: $group_label\n"; continue; }
  if (!$group->hasField("field_group_links")) { echo "SKIP: field_group_links missing on $group_label\n"; continue; }
  if (!$group->get("field_group_links")->isEmpty()) {
    echo "$group_label already has links; skipping\n";
    continue;
  }
  $group->set("field_group_links", $links);
  $group->save();
  echo "Set $group_label links -> " . implode(", ", array_column($links, "title")) . "\n";
}

// ===== Step 736: Group About section (#141 MC-2) =====
// Append-only, idempotent (skip a group whose field_group_about is already
// non-empty so a re-run never overwrites hand-edited or previously-seeded
// prose — the guard checks `isEmpty()`, not existence, matching Step 735's
// field_group_links idiom). Prose is set on 3 of the 8 seeded groups
// (DrupalCon Portland 2026, Core Committers, Thunder Distribution) using
// `basic_html`, matching field_group_description's seeded format. The other
// 5 seeded groups (Drupal France, Leadership Council, Camp Organizers EMEA,
// Drupal Deutschland, Legacy Infrastructure) are DELIBERATELY left without
// field_group_about so tests/e2e/group-about.spec.ts's negative case (a
// seeded group with no About heading) has real candidates — see brief
// AC-6/AC-7 and handoff-T-red.md.
echo "\n=== Step 736: Group About (#141) ===\n";
$group_about_by_label = [
  "DrupalCon Portland 2026" => "<p>DrupalCon Portland 2026 is the flagship North American gathering for the Drupal community, bringing together developers, site builders, and project leads for a week of sessions, sprints, and networking. <strong>This group coordinates the planning committee's work</strong> — from session tracks to venue logistics to sponsorship outreach. Whether you are a first-time contributor or a seasoned core committer, there is a sprint table with your name on it.</p>",
  "Core Committers" => "<p>Core Committers is the working group for maintainers with commit access to Drupal core and its most widely used contributed modules. Membership here is about coordination, not gatekeeping: <strong>weekly standups, patch review triage, and architectural RFCs</strong> all happen in this space. Expect deep technical discussion and a strong bias toward shipping stable, well-tested releases.</p>",
  "Thunder Distribution" => "<p>Thunder is a publishing-focused Drupal distribution built for newsrooms and content-heavy sites that need a fast, opinionated editorial workflow out of the box. This group tracks the <strong>distribution's roadmap, module contributions, and migration guidance</strong> for teams upgrading between major versions. Documentation authors, module maintainers, and site builders running Thunder in production are all welcome to pitch in.</p>",
];
foreach ($group_about_by_label as $group_label => $about_html) {
  $groups = $group_storage->loadByProperties(["label" => $group_label]);
  $group = reset($groups);
  if (!$group) { echo "SKIP: Group not found: $group_label\n"; continue; }
  if (!$group->hasField("field_group_about")) { echo "SKIP: field_group_about missing on $group_label\n"; continue; }
  if (!$group->get("field_group_about")->isEmpty()) {
    echo "$group_label already has an About body; skipping\n";
    continue;
  }
  $group->set("field_group_about", ["value" => $about_html, "format" => "basic_html"]);
  $group->save();
  echo "Set $group_label About body\n";
}

echo "\n=== Demo data complete ===\n";
