<?php

/**
 * @file
 * Step 795 (#129 ST-7): activity feed E2E fixture seed.
 *
 * `tests/e2e/activity-feed.spec.ts` (AC-6) needs a deterministic, guaranteed
 * mix of all three feed row shapes — social, aggregated, content — visible
 * to the Elena persona. Per the brief's §"Which templates surface on
 * /activity" table, only `activity_post_created`, `activity_comment_created`,
 * and `activity_membership_created` carry `field_group_id` and therefore
 * ever appear on `/activity` at all; `do_activity`'s own live hooks +
 * step_7xx_backfill_activity.php already produce SOME of these organically
 * from step_700_demo_data.php's seeded content, comments, and memberships —
 * but the exact SHAPE AC-6 needs (>=1 aggregated run of >=2 same-actor posts
 * within the 6h window, on a group Elena belongs to) is not guaranteed by
 * incidental seed timing (step_700's nodes are all created back-to-back in
 * one script run, so their `created` timestamps could coincidentally land
 * >6h apart or not, depending on execution wall-clock time — never
 * something an E2E spec should flake on per the brief's own AC-6 wording:
 * "do NOT flake on incidental seed data").
 *
 * This script explicitly, idempotently, seeds:
 *   1. A membership_created row for elena_garcia on "Camp Organizers EMEA"
 *      (her own seeded membership from step_700 — this script does not
 *      recreate the membership, it backfills/confirms the Message exists
 *      with a controlled, recent `created` timestamp so it reliably sorts
 *      near the top of the /activity feed).
 *   2. THREE activity_post_created Messages by alex_novak in "Camp
 *      Organizers EMEA", each exactly 2h apart (well within the 6h
 *      aggregation window) — guarantees >=1 activity-row-aggregated with
 *      count=3 ("Alex Novak posted 3 topics").
 *   3. ONE standalone activity_post_created by elena_garcia in the same
 *      group, deliberately >6h from anything else — guarantees >=1
 *      activity-row-content that renders alone (not folded into Alex's
 *      run).
 *
 * IDEMPOTENCY: mirrors step_7xx_backfill_activity.php's existence-check
 * cadence — before creating a Message, checks for an existing Message
 * matching (template, field_referenced_entity_type, field_referenced_entity_id,
 * created) and skips if found, so re-running this script (e.g. on every CI
 * run against a freshly-installed site, or twice against a persistent dev
 * volume) never duplicates rows.
 *
 * Usage: ddev drush scr docs/groups/scripts/step_795_activity_feed_e2e_fixture.php
 * Wired into the same seed sequence as step_790_persona_switcher.php (CI
 * job / container entrypoint), run AFTER step_7xx_backfill_activity.php so
 * this script's own idempotency check sees any backfilled rows already in
 * place.
 */

echo "\n=== Step 795: Activity feed E2E fixture (#129 ST-7 AC-6) ===\n";

$entity_type_manager = \Drupal::entityTypeManager();
$message_storage = $entity_type_manager->getStorage('message');
$group_storage = $entity_type_manager->getStorage('group');
$node_storage = $entity_type_manager->getStorage('node');

$elena = user_load_by_name('elena_garcia');
$alex = user_load_by_name('alex_novak');
if (!$elena || !$alex) {
  echo "SKIP: elena_garcia and/or alex_novak not found (expected from step_700_demo_data.php) — activity feed E2E fixture skipped\n";
  return;
}

$groups = $group_storage->loadByProperties(['label' => 'Camp Organizers EMEA']);
$group = reset($groups);
if (!$group) {
  echo "SKIP: seeded group \"Camp Organizers EMEA\" not found — activity feed E2E fixture skipped\n";
  return;
}

/**
 * Returns TRUE if a Message already exists for this exact idempotency key.
 *
 * @param \Drupal\Core\Entity\EntityStorageInterface $storage
 *   The message entity storage.
 * @param string $template
 *   The message_template bundle id.
 * @param string $referenced_entity_type
 *   The referenced entity type.
 * @param int $referenced_entity_id
 *   The referenced entity id.
 * @param int $created
 *   The created timestamp to match.
 *
 * @return bool
 *   TRUE if a matching Message already exists.
 */
function _do_activity_feed_e2e_exists($storage, string $template, string $referenced_entity_type, int $referenced_entity_id, int $created): bool {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('template', $template)
    ->condition('field_referenced_entity_type', $referenced_entity_type)
    ->condition('field_referenced_entity_id', $referenced_entity_id)
    ->condition('created', $created)
    ->range(0, 1)
    ->execute();
  return !empty($ids);
}

$now = \Drupal::time()->getRequestTime();

// ===== 1. Elena's membership_created row, timestamped recently =====
$elena_membership_created = $now - 3600;
if (_do_activity_feed_e2e_exists($message_storage, 'activity_membership_created', 'user', (int) $elena->id(), $elena_membership_created)) {
  echo "Exists: elena_garcia membership_created row\n";
}
else {
  $message = $message_storage->create([
    'template' => 'activity_membership_created',
    'uid' => $elena->id(),
    'field_referenced_entity_type' => 'user',
    'field_referenced_entity_id' => $elena->id(),
    'field_group_id' => ['target_id' => $group->id()],
  ]);
  $message->setCreatedTime($elena_membership_created);
  $message->save();
  echo "Created: elena_garcia membership_created row on \"Camp Organizers EMEA\"\n";
}

// ===== 2. Alex's 3-post run, each 2h apart (aggregates to count=3) =====
$alex_post_titles = [
  'E2E fixture: Site logistics update A',
  'E2E fixture: Site logistics update B',
  'E2E fixture: Site logistics update C',
];
$alex_post_offsets = [3 * 3600, 5 * 3600, 7 * 3600];
foreach ($alex_post_titles as $i => $title) {
  $existing_nodes = $node_storage->loadByProperties(['title' => $title]);
  $node = reset($existing_nodes);
  if (!$node) {
    $node = $node_storage->create([
      'type' => 'post',
      'title' => $title,
      'uid' => $alex->id(),
      'status' => 1,
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:post');
    echo "Created node + group relationship: $title\n";
  }
  else {
    echo "Exists: node \"$title\"\n";
  }

  $created = (int) $node->getCreatedTime();
  if (_do_activity_feed_e2e_exists($message_storage, 'activity_post_created', 'node', (int) $node->id(), $created)) {
    echo "Exists: activity_post_created for \"$title\"\n";
    continue;
  }
  $message = $message_storage->create([
    'template' => 'activity_post_created',
    'uid' => $alex->id(),
    'field_referenced_entity_type' => 'node',
    'field_referenced_entity_id' => $node->id(),
    'field_group_id' => ['target_id' => $group->id()],
  ]);
  // Deliberately override to a controlled offset (NOT the node's own
  // getCreatedTime(), which reflects wall-clock seed-run time) so the three
  // rows are guaranteed <=6h apart from each other regardless of how long
  // the seed script itself took to run.
  $message->setCreatedTime($now - $alex_post_offsets[$i]);
  $message->save();
  echo "Created: activity_post_created Message for \"$title\" (offset -{$alex_post_offsets[$i]}s)\n";
}

// ===== 3. Elena's standalone post, deliberately >6h from Alex's run =====
$standalone_title = 'E2E fixture: Elena standalone post';
$existing_nodes = $node_storage->loadByProperties(['title' => $standalone_title]);
$standalone_node = reset($existing_nodes);
if (!$standalone_node) {
  $standalone_node = $node_storage->create([
    'type' => 'post',
    'title' => $standalone_title,
    'uid' => $elena->id(),
    'status' => 1,
  ]);
  $standalone_node->save();
  $group->addRelationship($standalone_node, 'group_node:post');
  echo "Created node + group relationship: $standalone_title\n";
}
else {
  echo "Exists: node \"$standalone_title\"\n";
}

$standalone_created = $now - 20 * 3600;
if (_do_activity_feed_e2e_exists($message_storage, 'activity_post_created', 'node', (int) $standalone_node->id(), $standalone_created)) {
  echo "Exists: activity_post_created for \"$standalone_title\"\n";
}
else {
  $message = $message_storage->create([
    'template' => 'activity_post_created',
    'uid' => $elena->id(),
    'field_referenced_entity_type' => 'node',
    'field_referenced_entity_id' => $standalone_node->id(),
    'field_group_id' => ['target_id' => $group->id()],
  ]);
  $message->setCreatedTime($standalone_created);
  $message->save();
  echo "Created: activity_post_created Message for \"$standalone_title\" (offset -20h, well outside the 6h window)\n";
}

echo "=== Step 795 complete ===\n";
