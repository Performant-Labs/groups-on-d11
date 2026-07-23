<?php

/**
 * Step 7xx: One-time backfill of do_activity Message rows (#116 ST-F2).
 *
 * "Backfill-after-seed" architecture (amended brief, resolved per A's
 * question #3): do_activity's live hooks (src/Hook/DoActivityHooks.php) are
 * ALWAYS enabled — they are never disabled during seed/backfill — so this
 * script is safe to run against a site that already has some or all of its
 * activity Messages logged live. Its ONLY job is to catch entities whose
 * CREATION predates do_activity being enabled (or predates a given channel's
 * hook shipping) — e.g. content seeded before this module existed.
 *
 * IDEMPOTENCY KEY: before creating a Message, this script queries for an
 * existing Message matching ALL FOUR of: (template,
 * field_referenced_entity_type, field_referenced_entity_id, created) and
 * skips (echo "Exists: ..."; continue;) if one is found — mirroring the
 * per-row existence-check cadence used throughout
 * docs/groups/scripts/step_700_demo_data.php (lines 20-29). Matching on
 * `created` (not just the entity pair) is deliberate: it is what proves a
 * live-hook-created Message (whose created time is the source entity's own
 * getCreatedTime(), per the timestamp contract below) is recognised as
 * "already logged" rather than being duplicated.
 *
 * TIMESTAMP CONTRACT: every Message::create()->setCreatedTime() call below
 * uses the SOURCE entity's own getCreatedTime() — never
 * \Drupal::time()->getRequestTime() — so a backfilled Message is
 * indistinguishable in time-order from one the live hook would have created
 * at the entity's actual creation moment.
 *
 * Usage: ddev drush scr docs/groups/scripts/step_7xx_backfill_activity.php
 *   (or `drush scr` directly in CI/deployed contexts — see
 *   deploy/entrypoint.sh and .github/workflows/test.yml's
 *   "do_activity step_7xx" marker sections).
 *
 * This script is a plain drush-script include (no bootstrap of its own,
 * matching step_700_demo_data.php's convention) — it is `require`d from
 * within an already-bootstrapped Drupal request (a live drush invocation, or
 * — as BackfillIdempotencyTest does — directly inside a running kernel test).
 *
 * MULTI-REQUIRE SAFETY: BackfillIdempotencyTest deliberately `require`s this
 * script TWICE in the same PHP process (to prove the idempotency key holds
 * across repeat runs), so every top-level function/const declaration below is
 * guarded with function_exists()/defined() — PHP fatals on redeclaring a
 * function or const, even though re-`require`ing the same PATH is otherwise
 * legal (this file intentionally uses plain `require`, matching
 * step_700_demo_data.php's own convention, not `require_once`).
 */

if (!defined('DO_ACTIVITY_PIN_FLAG_ID')) {
  define('DO_ACTIVITY_PIN_FLAG_ID', 'pin_in_group');
}

if (!function_exists('do_activity_backfill_exists')) {

  /**
   * Returns TRUE if a Message already exists for this exact idempotency key.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $message_storage
   *   The message entity storage.
   * @param string $template
   *   The message_template bundle id.
   * @param string $referenced_entity_type
   *   The referenced entity type.
   * @param int $referenced_entity_id
   *   The referenced entity id.
   * @param int $created
   *   The source entity's created timestamp.
   *
   * @return bool
   *   TRUE if a matching Message already exists.
   */
  function do_activity_backfill_exists($message_storage, string $template, string $referenced_entity_type, int $referenced_entity_id, int $created): bool {
    $ids = $message_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('template', $template)
      ->condition('field_referenced_entity_type', $referenced_entity_type)
      ->condition('field_referenced_entity_id', $referenced_entity_id)
      ->condition('created', $created)
      ->range(0, 1)
      ->execute();
    return !empty($ids);
  }

}

if (!function_exists('do_activity_backfill_create')) {

  /**
   * Creates one activity Message, honoring the timestamp contract.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $message_storage
   *   The message entity storage.
   * @param string $template
   *   The message_template bundle id.
   * @param int $actor_uid
   *   The uid to attribute the Message to.
   * @param string $referenced_entity_type
   *   The referenced entity type.
   * @param int $referenced_entity_id
   *   The referenced entity id.
   * @param int|null $group_id
   *   (optional) The group context, or NULL for none.
   * @param int $created
   *   The source entity's created timestamp.
   */
  function do_activity_backfill_create($message_storage, string $template, int $actor_uid, string $referenced_entity_type, int $referenced_entity_id, ?int $group_id, int $created): void {
    $values = [
      'template' => $template,
      'uid' => $actor_uid,
      'field_referenced_entity_type' => $referenced_entity_type,
      'field_referenced_entity_id' => $referenced_entity_id,
    ];
    if ($group_id !== NULL) {
      // Explicit ['target_id' => ...] form — see the identical note in
      // DoActivityHooks::createMessage(); a bare scalar is interpreted by
      // EntityReferenceItem::setValue() as an ENTITY object assignment, not a
      // target_id shorthand, and silently fails to resolve.
      $values['field_group_id'] = ['target_id' => $group_id];
    }
    $message = $message_storage->create($values);
    $message->setCreatedTime($created);
    $message->save();
  }

}

$message_storage = \Drupal::entityTypeManager()->getStorage('message');
$group_storage = \Drupal::entityTypeManager()->getStorage('group');
$group_relationship_storage = \Drupal::entityTypeManager()->getStorage('group_relationship');
$comment_storage = \Drupal::entityTypeManager()->getStorage('comment');
$flagging_storage = \Drupal::entityTypeManager()->getStorage('flagging');

$counts = [
  'groups' => 0,
  'memberships' => 0,
  'nodes_in_groups' => 0,
  'comments' => 0,
  'flaggings' => 0,
];

// ===== step_7xx.1: Backfilling group_created messages =====
echo "=== step_7xx.1: Backfilling group_created messages ===\n";
foreach ($group_storage->loadMultiple() as $group) {
  $gid = (int) $group->id();
  $created = (int) $group->getCreatedTime();
  if (do_activity_backfill_exists($message_storage, 'activity_group_created', 'group', $gid, $created)) {
    echo "Exists: group $gid\n";
    continue;
  }
  do_activity_backfill_create(
    $message_storage,
    'activity_group_created',
    (int) $group->getOwnerId(),
    'group',
    $gid,
    NULL,
    $created,
  );
  $counts['groups']++;
  echo "Backfilled: group $gid\n";
}

// ===== step_7xx.2: Backfilling membership messages =====
echo "\n=== step_7xx.2: Backfilling membership messages ===\n";
$membership_relationship_ids = $group_relationship_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('plugin_id', 'group_membership')
  ->execute();
foreach ($group_relationship_storage->loadMultiple($membership_relationship_ids) as $relationship) {
  $member = $relationship->getEntity();
  if ($member === NULL) {
    continue;
  }
  $uid = (int) $member->id();
  $gid = (int) $relationship->getGroup()->id();
  // Memberships have no natural "created" timestamp of their own on the
  // relationship in every Group 4.x plugin configuration; use the
  // relationship's own created time when the base field exists, falling back
  // to the request time only as a last resort (this mirrors the live hook,
  // which also has no better timestamp to key on for a membership event).
  $created = $relationship->hasField('created') && !$relationship->get('created')->isEmpty()
    ? (int) $relationship->get('created')->value
    : \Drupal::time()->getRequestTime();
  if (do_activity_backfill_exists($message_storage, 'activity_membership_created', 'user', $uid, $created)) {
    echo "Exists: membership uid=$uid gid=$gid\n";
    continue;
  }
  do_activity_backfill_create(
    $message_storage,
    'activity_membership_created',
    $uid,
    'user',
    $uid,
    $gid,
    $created,
  );
  $counts['memberships']++;
  echo "Backfilled: membership uid=$uid gid=$gid\n";
}

// ===== step_7xx.3: Backfilling nodes-in-groups (post-created) messages =====
echo "\n=== step_7xx.3: Backfilling nodes-in-groups messages ===\n";
$group_node_relationship_ids = $group_relationship_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('plugin_id', 'group_node:', 'STARTS_WITH')
  ->execute();
foreach ($group_relationship_storage->loadMultiple($group_node_relationship_ids) as $relationship) {
  $node = $relationship->getEntity();
  if (!$node instanceof \Drupal\node\NodeInterface) {
    continue;
  }
  $nid = (int) $node->id();
  $gid = (int) $relationship->getGroup()->id();
  $created = (int) $node->getCreatedTime();
  if (do_activity_backfill_exists($message_storage, 'activity_post_created', 'node', $nid, $created)) {
    echo "Exists: node $nid in group $gid\n";
    continue;
  }
  do_activity_backfill_create(
    $message_storage,
    'activity_post_created',
    (int) $node->getOwnerId(),
    'node',
    $nid,
    $gid,
    $created,
  );
  $counts['nodes_in_groups']++;
  echo "Backfilled: node $nid in group $gid\n";
}

// ===== step_7xx.4: Backfilling comment messages =====
echo "\n=== step_7xx.4: Backfilling comment messages ===\n";
foreach ($comment_storage->loadMultiple() as $comment) {
  $cid = (int) $comment->id();
  $created = (int) $comment->getCreatedTime();
  if (do_activity_backfill_exists($message_storage, 'activity_comment_created', 'comment', $cid, $created)) {
    echo "Exists: comment $cid\n";
    continue;
  }

  $group_id = NULL;
  $commented_entity = $comment->getCommentedEntity();
  if ($commented_entity !== NULL) {
    $relationships = $group_relationship_storage->loadByEntity($commented_entity);
    if ($relationships) {
      $relationship = reset($relationships);
      $group_id = (int) $relationship->getGroup()->id();
    }
  }

  do_activity_backfill_create(
    $message_storage,
    'activity_comment_created',
    (int) $comment->getOwnerId(),
    'comment',
    $cid,
    $group_id,
    $created,
  );
  $counts['comments']++;
  echo "Backfilled: comment $cid\n";
}

// ===== step_7xx.5: Backfilling flagging messages =====
echo "\n=== step_7xx.5: Backfilling flagging messages ===\n";
foreach ($flagging_storage->loadMultiple() as $flagging) {
  $flaggable_type = $flagging->getFlaggableType();
  $flaggable_id = (int) $flagging->getFlaggableId();
  $created = (int) $flagging->getCreatedTime();
  $template = $flagging->getFlagId() === DO_ACTIVITY_PIN_FLAG_ID
    ? 'activity_pin_toggled'
    : 'activity_flagging_created';

  if (do_activity_backfill_exists($message_storage, $template, $flaggable_type, $flaggable_id, $created)) {
    echo "Exists: flagging {$flagging->getFlagId()} on $flaggable_type $flaggable_id\n";
    continue;
  }

  do_activity_backfill_create(
    $message_storage,
    $template,
    (int) $flagging->getOwnerId(),
    $flaggable_type,
    $flaggable_id,
    NULL,
    $created,
  );
  $counts['flaggings']++;
  echo "Backfilled: flagging {$flagging->getFlagId()} on $flaggable_type $flaggable_id\n";
}

// ===== Summary =====
echo "\n=== step_7xx: Backfill summary ===\n";
echo "Groups backfilled: {$counts['groups']}\n";
echo "Memberships backfilled: {$counts['memberships']}\n";
echo "Nodes-in-groups backfilled: {$counts['nodes_in_groups']}\n";
echo "Comments backfilled: {$counts['comments']}\n";
echo "Flaggings backfilled: {$counts['flaggings']}\n";
