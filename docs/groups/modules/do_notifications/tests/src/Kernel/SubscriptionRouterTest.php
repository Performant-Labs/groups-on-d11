<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Entity\EntityInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\FlagInterface;
use Drupal\message\Entity\Message;
use Drupal\message\MessageInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' subscribe event routing (#230).
 *
 * Part of Notifications epic #237, N-2 (see
 * docs/planning/handoffs/230-n2-subscribe-routing/brief.md). Pins
 * `Drupal\do_notifications\Subscription\SubscriptionRouter::route()`: given an
 * activity Message (do_activity's `activity_post_created` template exercises
 * the referenced-node path used throughout this suite), the router resolves
 * the union of interested subscribers across `follow_content` (node),
 * `follow_user` (author), and `follow_term` (tagged terms), deduplicates a
 * single user's overlapping subscriptions to the same Message into ONE queue
 * entry, and applies three suppression filters: never notify the Message's own
 * author, respect `mute_group_notifications` on the Message's group, and
 * respect the per-post `do_notifications_suppress_<nid>` State flag.
 *
 * These are RED (Phase 4) tests: `SubscriptionRouter` and the
 * `do_notifications.subscription_router` / `do_notifications.queue` services
 * do not exist yet. F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class SubscriptionRouterTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `message` + `do_activity` provide the six activity Message templates
   * (this suite exercises `activity_post_created`); `flag` + `taxonomy`
   * provide the three subscription flag types; `do_notifications` is the
   * module under test.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'flag',
    'taxonomy',
    'message',
    'do_activity',
    'do_notifications',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('message');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('node', ['node_access']);
    // N-1 (#229) swapped do_notifications.queue to a real DB-backed
    // implementation (DatabaseQueueBackend); this suite calls the queue's
    // drain()/count() directly, so the table must exist.
    $this->installSchema('do_notifications', ['do_notifications_queue']);
    $this->installConfig(['field']);
    // do_activity's Message templates (activity_post_created et al.) are
    // config/install, never auto-installed by enableModules() alone. NOTE:
    // installConfig()'s default DefaultConfigMode::All also triggers
    // ConfigInstaller::createSiteOptionalConfig(), which scans EVERY already
    // -enabled module's own config/optional/ (not just do_activity's) for
    // config whose dependencies are now satisfiable -- so this call can
    // silently auto-create do_notifications' `mute_group_notifications` flag
    // (the one flag among our four with no `access_author` key, so it alone
    // passes schema validation) before the loop below ever runs. The loop is
    // therefore load-or-create, not blind create.
    $this->installConfig(['do_activity']);

    // do_notifications' four subscription/mute flags (follow_content,
    // follow_user, follow_term, mute_group_notifications) are installed as
    // module-local test fixtures (byte-identical copies of the shipped
    // docs/groups/modules/do_notifications/config/optional/*.yml), NOT via
    // installConfig(['do_notifications']), and each is stripped of
    // `flagTypeConfig.access_author` before saving. That key has no matching
    // config schema in the `flag` contrib module's flag.schema.yml (a
    // pre-existing gap in the shipped config, unrelated to this story) and
    // trips strict config-schema checking under KernelTestBase. This mirrors
    // the identical, already-established workaround in do_streams'
    // StreamsScopeTest / FollowingFeedTest for the SAME shipped flag
    // definitions, keeping strict config schema ON while exercising the
    // real flag definitions the router queries against. load-or-create: skip
    // any flag id already present (see the note on installConfig() above --
    // mute_group_notifications may already have been auto-installed).
    $fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $flag_storage = $this->entityTypeManager->getStorage('flag');
    foreach ([
      'flag.flag.follow_content',
      'flag.flag.follow_user',
      'flag.flag.follow_term',
      'flag.flag.mute_group_notifications',
    ] as $config_name) {
      $values = $fixtures->read($config_name);
      if ($flag_storage->load($values['id'])) {
        continue;
      }
      unset($values['flagTypeConfig']['access_author']);
      $flag_storage->create($values)->save();
    }

    // A taxonomy vocabulary + term so follow_term has something to flag, and
    // a field on the `post` bundle to tag nodes with it.
    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => -1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tags',
      'entity_type' => 'node',
      'bundle' => 'post',
      'label' => 'Tags',
    ])->save();
  }

  /**
   * Loads a flag entity by id, asserting it exists (from do_notifications).
   *
   * @param string $flag_id
   *   The flag machine name.
   *
   * @return \Drupal\flag\FlagInterface
   *   The loaded flag.
   */
  private function loadFlag(string $flag_id): FlagInterface {
    /** @var \Drupal\flag\FlagInterface|null $flag */
    $flag = $this->entityTypeManager->getStorage('flag')->load($flag_id);
    $this->assertNotNull($flag, "The $flag_id flag fixture is installed.");
    return $flag;
  }

  /**
   * Flags an entity on behalf of a user via the real `flag` service.
   *
   * @param string $flag_id
   *   The flag machine name.
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to flag.
   * @param \Drupal\user\UserInterface $account
   *   The flagging user.
   */
  private function flagAs(string $flag_id, EntityInterface $entity, UserInterface $account): void {
    $this->container->get('flag')->flag($this->loadFlag($flag_id), $entity, $account);
  }

  /**
   * Creates and saves an `activity_post_created` Message referencing a node.
   *
   * Mirrors DoActivityHooks::createMessage()'s field shape (field_
   * referenced_entity_type / _id, optional field_group_id) so the router is
   * exercised against the SAME Message shape do_activity actually produces.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The referenced node.
   * @param int $author_uid
   *   The Message's owner (the "author" the router must never notify).
   * @param int|null $group_id
   *   (optional) The group context.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  private function createPostMessage(NodeInterface $node, int $author_uid, ?int $group_id = NULL): MessageInterface {
    $values = [
      'template' => 'activity_post_created',
      'uid' => $author_uid,
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => (int) $node->id(),
    ];
    if ($group_id !== NULL) {
      $values['field_group_id'] = ['target_id' => $group_id];
    }
    $message = Message::create($values);
    $message->save();
    return $message;
  }

  /**
   * The 3-Messages x 5-subscribers dedup scenario (brief acceptance case).
   *
   * Three activity_post_created Messages, each authored by a different user
   * and referencing a different node. Five subscribers:
   *   - User A follows Node1 via follow_content AND follows a term Node1 also
   *     carries via follow_term — an OVERLAPPING double-subscription to the
   *     SAME Message, which must collapse to exactly ONE queue entry.
   *   - User B follows Node2 via follow_content (one Message, one entry).
   *   - User C follows Node3's author via follow_user (one Message, one entry).
   *   - User D follows a term on Node2 via follow_term (one Message, one entry
   *     — distinct user from B, same Message).
   *   - User E follows Node3 via follow_content (one Message, one entry).
   *
   * After routing all three Messages: exactly 5 queue entries total (A's
   * overlap collapses two candidate subscriptions into one).
   */
  public function testRouterDedupsAcrossFollowFlags(): void {
    $author1 = $this->createUser();
    $author2 = $this->createUser();
    $author3 = $this->createUser();

    $node1 = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Node One',
      'uid' => $author1->id(),
      'status' => 1,
    ]);
    $node1->save();

    $node2 = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Node Two',
      'uid' => $author2->id(),
      'status' => 1,
    ]);
    $node2->save();

    $node3 = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Node Three',
      'uid' => $author3->id(),
      'status' => 1,
    ]);
    $node3->save();

    $term1 = Term::create(['vid' => 'tags', 'name' => 'Node1 Tag']);
    $term1->save();
    $node1->set('field_tags', [['target_id' => $term1->id()]]);
    $node1->save();

    $term2 = Term::create(['vid' => 'tags', 'name' => 'Node2 Tag']);
    $term2->save();
    $node2->set('field_tags', [['target_id' => $term2->id()]]);
    $node2->save();

    $userA = $this->createUser();
    $userB = $this->createUser();
    $userC = $this->createUser();
    $userD = $this->createUser();
    $userE = $this->createUser();

    // User A: overlapping subscription to node1 (content + term).
    $this->flagAs('follow_content', $node1, $userA);
    $this->flagAs('follow_term', $term1, $userA);

    // User B: follow_content on node2.
    $this->flagAs('follow_content', $node2, $userB);

    // User C: follow_user on node3's author.
    $this->flagAs('follow_user', $author3, $userC);

    // User D: follow_term on node2's term.
    $this->flagAs('follow_term', $term2, $userD);

    // User E: follow_content on node3.
    $this->flagAs('follow_content', $node3, $userE);

    $message1 = $this->createPostMessage($node1, (int) $author1->id());
    $message2 = $this->createPostMessage($node2, (int) $author2->id());
    $message3 = $this->createPostMessage($node3, (int) $author3->id());

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $router->route($message1);
    $router->route($message2);
    $router->route($message3);

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();

    $this->assertCount(
      5,
      $items,
      'Five distinct subscribers are enqueued once each; User A\'s overlapping '
      . 'follow_content + follow_term subscription to the same Message '
      . 'collapses to one entry, not two.',
    );

    $uids = array_map(static fn (array $item): int => $item['uid'], $items);
    sort($uids);
    $expected = array_map(static fn (UserInterface $u): int => (int) $u->id(), [$userA, $userB, $userC, $userD, $userE]);
    sort($expected);
    $this->assertSame($expected, $uids, 'The exact five subscribing users are enqueued.');

    foreach ($items as $item) {
      $this->assertArrayHasKey('uid', $item);
      $this->assertArrayHasKey('mid', $item);
      $this->assertArrayHasKey('template', $item);
      $this->assertArrayHasKey('frequency', $item);
      $this->assertArrayHasKey('day', $item);
      $this->assertSame('activity_post_created', $item['template']);
    }
  }

  /**
   * The Message's own author is never enqueued for their own Message.
   */
  public function testAuthorNeverEnqueued(): void {
    $author = $this->createUser();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Self-authored post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    // The author follows their own post's content.
    $this->flagAs('follow_content', $node, $author);

    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);

    $this->assertSame(0, $count, 'route() reports zero entries written.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $this->assertSame(0, $queue->count(), 'The author is never enqueued for their own Message.');
  }

  /**
   * Muting a group suppresses that subscriber's notifications for it.
   *
   * The mute_group_notifications flag on the Message's group suppresses the
   * subscriber.
   */
  public function testMuteGroupSuppresses(): void {
    $group = $this->createGroup();
    $author = $this->createUser();
    $subscriber = $this->createUser();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Group post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $this->flagAs('follow_content', $node, $subscriber);
    $this->flagAs('mute_group_notifications', $group, $subscriber);

    $message = $this->createPostMessage($node, (int) $author->id(), (int) $group->id());

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);

    $this->assertSame(0, $count, 'route() reports zero entries written.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $this->assertSame(0, $queue->count(), 'A subscriber who muted the Message\'s group is suppressed.');
  }

  /**
   * The per-post suppression State flag blocks routing for that node.
   *
   * Suppresses all routing for the node, even for an otherwise-eligible
   * follow_content subscriber.
   */
  public function testPerPostStateSuppression(): void {
    $author = $this->createUser();
    $subscriber = $this->createUser();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Suppressed post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $this->flagAs('follow_content', $node, $subscriber);

    $this->container->get('state')->set('do_notifications_suppress_' . $node->id(), TRUE);

    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);

    $this->assertSame(0, $count, 'route() reports zero entries written.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $this->assertSame(0, $queue->count(), 'Per-post suppression state blocks all routing for that node.');
  }

  /**
   * A Message whose referenced entity nobody follows produces zero entries.
   */
  public function testNoSubscribersProducesZero(): void {
    $author = $this->createUser();

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Unfollowed post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);

    $this->assertSame(0, $count, 'route() returns 0 when nobody follows the referenced entity.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $this->assertSame(0, $queue->count(), 'The queue remains empty.');
  }

}
