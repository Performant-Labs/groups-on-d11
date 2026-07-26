<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\flag\FlagInterface;
use Drupal\message\Entity\Message;
use Drupal\message\MessageInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for per-recipient frequency routing (#233).
 *
 * Part of Notifications epic #237, N-5 (see
 * docs/planning/handoffs/233-n5-frequency-queue/brief.md). Pins
 * `Drupal\do_notifications\Frequency\FrequencyResolver` as consulted by
 * `SubscriptionRouter` per recipient: each enqueued payload's `frequency` and
 * `send_at` keys are derived from the RECIPIENT's own
 * `field_notification_frequency` (not a single site-wide value stamped on
 * every entry, as today's `SubscriptionRouter` does).
 *
 * These are RED (Phase 4) tests: `FrequencyResolver` does not exist yet, and
 * `SubscriptionRouter` does not yet inject/consult it (it currently reads one
 * site-wide `default_frequency` for every recipient and never sets
 * `send_at`). F implements against this suite.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class FrequencyRoutingTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
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
    // N-1 (#229) merged: do_notifications.queue service now resolves to
    // DatabaseQueueBackend, which requires the do_notifications_queue table.
    $this->installSchema('do_notifications', ['do_notifications_queue']);
    $this->installConfig(['field']);
    // do_activity's Message templates (activity_post_created et al.) are
    // config/install, never auto-installed by enableModules() alone. See
    // SubscriptionRouterTest::setUp() for the full note on why this can also
    // silently auto-create do_notifications' `mute_group_notifications` flag
    // via createSiteOptionalConfig() -- the loop below is load-or-create for
    // the same reason.
    $this->installConfig(['do_activity']);

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

    // `field_notification_frequency` on the user entity: installed here as a
    // module-local test fixture (byte-identical copies of the shipped
    // docs/groups/config/field.storage.user.* + field.field.user.user.*
    // YAMLs), mirroring the flag-fixture pattern above rather than reading
    // config/sync directly -- the shipped YAML lives outside any module's
    // config/install, so it is never auto-installed by installConfig(). Load
    // -or-create in case a prior test/module already installed it.
    //
    // NOTE: FieldStorageConfig::create() expects `settings.allowed_values`
    // in the RUNTIME (simplified) shape `['value' => 'label', ...]`, not
    // the on-disk config-export shape `[['value' => ..., 'label' => ...],
    // ...]` the shipped YAML uses (ListItemBase::storageSettingsToConfigData
    // / ::storageSettingsFromConfigData round-trip between the two on
    // config import/export -- a raw ::create() call bypasses that and
    // double-transforms the structured array if given the export shape).
    // Convert here so ::create() receives the format it actually expects.
    if (!FieldStorageConfig::loadByName('user', 'field_notification_frequency')) {
      $storage_values = $fixtures->read('field.storage.user.field_notification_frequency');
      unset($storage_values['uuid']);
      $simplified = [];
      foreach ($storage_values['settings']['allowed_values'] as $allowed_value) {
        $simplified[$allowed_value['value']] = $allowed_value['label'];
      }
      $storage_values['settings']['allowed_values'] = $simplified;
      FieldStorageConfig::create($storage_values)->save();
    }
    if (!FieldConfig::loadByName('user', 'user', 'field_notification_frequency')) {
      $field_values = $fixtures->read('field.field.user.user.field_notification_frequency');
      unset($field_values['uuid']);
      FieldConfig::create($field_values)->save();
    }
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
  private function flagAs(string $flag_id, $entity, UserInterface $account): void {
    $this->container->get('flag')->flag($this->loadFlag($flag_id), $entity, $account);
  }

  /**
   * Creates a user with a given `field_notification_frequency` value.
   *
   * @param string|null $frequency
   *   The frequency value to set ('immediately'|'daily'|'weekly'), or NULL to
   *   leave the field empty (exercising the site-default fallback path).
   *
   * @return \Drupal\user\UserInterface
   *   The saved user.
   */
  private function createUserWithFrequency(?string $frequency): UserInterface {
    $user = $this->createUser();
    if ($frequency !== NULL) {
      $user->set('field_notification_frequency', $frequency);
      $user->save();
    }
    return $user;
  }

  /**
   * Creates a node followed via follow_content, authored by a fresh user.
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node.
   */
  private function createFollowableNode(): NodeInterface {
    $author = $this->createUser();
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Frequency routing post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Creates and saves an `activity_post_created` Message referencing a node.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The referenced node.
   * @param int $author_uid
   *   The Message's owner (the "author" the router must never notify).
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  private function createPostMessage(NodeInterface $node, int $author_uid): MessageInterface {
    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author_uid,
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => (int) $node->id(),
    ]);
    $message->save();
    return $message;
  }

  /**
   * Computes the expected `send_at` for a given frequency, from `$now`.
   *
   * Deliberately reimplemented here (not delegated to FrequencyResolver)
   * so this test pins the CONTRACT (next 2 AM / next Sunday 19:00 UTC,
   * strictly after `$now`), not FrequencyResolver's own internal math.
   *
   * @param string $frequency
   *   One of 'immediately', 'daily', 'weekly'.
   * @param int $now
   *   The reference unix timestamp (the request time).
   *
   * @return int
   *   The expected `send_at` unix timestamp.
   */
  private function expectedSendAt(string $frequency, int $now): int {
    if ($frequency === 'immediately') {
      return $now;
    }

    if ($frequency === 'daily') {
      $today_2am = strtotime(gmdate('Y-m-d', $now) . ' 02:00:00 UTC');
      return $now < $today_2am ? $today_2am : $today_2am + 86400;
    }

    // weekly: next Sunday 19:00 UTC, strictly after $now.
    // gmdate('w', $now) returns 0 for Sunday .. 6 for Saturday.
    $dow = (int) gmdate('w', $now);
    $today_midnight = strtotime(gmdate('Y-m-d', $now) . ' 00:00:00 UTC');
    $days_until_sunday = (7 - $dow) % 7;
    $candidate = $today_midnight + ($days_until_sunday * 86400) + (19 * 3600);
    return $now < $candidate ? $candidate : $candidate + (7 * 86400);
  }

  /**
   * An `immediately` recipient's payload is stamped with the request time.
   */
  public function testImmediateFrequencyStampsRequestTimeSendAt(): void {
    $recipient = $this->createUserWithFrequency('immediately');
    $node = $this->createFollowableNode();
    $this->flagAs('follow_content', $node, $recipient);

    $author = $this->createUser();
    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);
    $this->assertSame(1, $count, 'route() reports one entry written.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();
    $this->assertCount(1, $items);

    $item = $items[0];
    $this->assertSame((int) $recipient->id(), $item['uid']);
    $this->assertSame('immediately', $item['frequency'], 'The immediate recipient is stamped with frequency=immediately.');
    $this->assertSame($now, $item['send_at'], 'send_at equals the request time for an immediate recipient.');
  }

  /**
   * A `daily` recipient's payload is stamped with the next 2 AM UTC.
   */
  public function testDailyFrequencyStampsNext2amUtc(): void {
    $recipient = $this->createUserWithFrequency('daily');
    $node = $this->createFollowableNode();
    $this->flagAs('follow_content', $node, $recipient);

    $author = $this->createUser();
    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();
    $expected_send_at = $this->expectedSendAt('daily', $now);

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $router->route($message);

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();
    $this->assertCount(1, $items);

    $item = $items[0];
    $this->assertSame('daily', $item['frequency'], 'The daily recipient is stamped with frequency=daily.');
    $this->assertSame($expected_send_at, $item['send_at'], 'send_at is the next 2 AM UTC strictly after the request time.');
    $this->assertGreaterThan($now, $item['send_at'], 'send_at is strictly greater than now (boundary discipline).');
  }

  /**
   * A `weekly` recipient's payload is stamped with the next Sunday 19:00 UTC.
   */
  public function testWeeklyFrequencyStampsNextSundayEveningUtc(): void {
    $recipient = $this->createUserWithFrequency('weekly');
    $node = $this->createFollowableNode();
    $this->flagAs('follow_content', $node, $recipient);

    $author = $this->createUser();
    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();
    $expected_send_at = $this->expectedSendAt('weekly', $now);

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $router->route($message);

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();
    $this->assertCount(1, $items);

    $item = $items[0];
    $this->assertSame('weekly', $item['frequency'], 'The weekly recipient is stamped with frequency=weekly.');
    $this->assertSame($expected_send_at, $item['send_at'], 'send_at is the next Sunday 19:00 UTC strictly after the request time.');
    $this->assertGreaterThan($now, $item['send_at'], 'send_at is strictly greater than now (boundary discipline).');
  }

  /**
   * A recipient with no frequency value falls back to the site default.
   *
   * `do_notifications.settings:default_frequency` defaults to 'immediately'
   * when unset (see brief), so a recipient whose field is empty is stamped
   * exactly as an explicit `immediately` recipient would be.
   */
  public function testMissingFrequencyFieldFallsBackToSiteDefault(): void {
    $recipient = $this->createUserWithFrequency(NULL);
    $node = $this->createFollowableNode();
    $this->flagAs('follow_content', $node, $recipient);

    $author = $this->createUser();
    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $router->route($message);

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();
    $this->assertCount(1, $items);

    $item = $items[0];
    $this->assertSame('immediately', $item['frequency'], 'A recipient with an empty frequency field falls back to the site default (immediately).');
    $this->assertSame($now, $item['send_at'], 'The fallback default (immediately) stamps send_at with the request time.');
  }

  /**
   * Three recipients with three frequencies produce three distinct payloads.
   *
   * Exercises a single route() call fanning out to three recipients, one per
   * frequency.
   */
  public function testMixedRecipientsProduceDistinctPayloads(): void {
    $immediate_recipient = $this->createUserWithFrequency('immediately');
    $daily_recipient = $this->createUserWithFrequency('daily');
    $weekly_recipient = $this->createUserWithFrequency('weekly');

    $node = $this->createFollowableNode();
    $this->flagAs('follow_content', $node, $immediate_recipient);
    $this->flagAs('follow_content', $node, $daily_recipient);
    $this->flagAs('follow_content', $node, $weekly_recipient);

    $author = $this->createUser();
    $message = $this->createPostMessage($node, (int) $author->id());

    /** @var \Drupal\Component\Datetime\TimeInterface $time */
    $time = $this->container->get('datetime.time');
    $now = $time->getRequestTime();

    /** @var \Drupal\do_notifications\Subscription\SubscriptionRouter $router */
    $router = $this->container->get('do_notifications.subscription_router');
    $count = $router->route($message);
    $this->assertSame(3, $count, 'route() reports three entries written.');

    /** @var \Drupal\do_notifications\Queue\QueueBackendInterface $queue */
    $queue = $this->container->get('do_notifications.queue');
    $items = $queue->drain();
    $this->assertCount(3, $items, 'Three distinct recipients produce three distinct payloads.');

    $by_uid = [];
    foreach ($items as $item) {
      $by_uid[$item['uid']] = $item;
    }
    $this->assertCount(3, $by_uid, 'The three payloads correspond to three distinct uids.');

    $expected = [
      (int) $immediate_recipient->id() => [
        'frequency' => 'immediately',
        'send_at' => $this->expectedSendAt('immediately', $now),
      ],
      (int) $daily_recipient->id() => [
        'frequency' => 'daily',
        'send_at' => $this->expectedSendAt('daily', $now),
      ],
      (int) $weekly_recipient->id() => [
        'frequency' => 'weekly',
        'send_at' => $this->expectedSendAt('weekly', $now),
      ],
    ];

    foreach ($expected as $uid => $expectation) {
      $this->assertArrayHasKey($uid, $by_uid, "A payload exists for uid $uid.");
      $this->assertSame($expectation['frequency'], $by_uid[$uid]['frequency'], "uid $uid's payload carries its own frequency.");
      $this->assertSame($expectation['send_at'], $by_uid[$uid]['send_at'], "uid $uid's payload carries its own send_at.");
    }
  }

}
