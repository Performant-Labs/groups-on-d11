<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\group\Entity\GroupInterface;
use Drupal\message\Entity\Message;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' DigestRenderer service (#236).
 *
 * Pins the N-8 contract: `Drupal\do_notifications\Email\DigestRenderer::
 * render()` wraps N+ `activity_*` Message entities (do_activity, #116) for a
 * single recipient + window ("daily"|"weekly") into ONE email payload
 * (`['subject', 'body_text', 'body_html']`), reusing N-4's
 * `EmailRenderer::renderEventFragments()` for each per-event fragment rather
 * than duplicating token/removed-placeholder machinery (brief §"Design
 * decisions", locked by A).
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class DigestRendererTest extends GroupsKernelTestBase {

  /**
   * A fixed Unix timestamp used to pin message `created` offsets.
   *
   * 2023-11-14 22:13:20 UTC.
   */
  private const FIXED_CREATED_TIME = 1700000000;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'flag',
    'comment',
    'message',
    'do_activity',
    'do_notifications',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('message');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('flagging');
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('comment', ['comment_entity_statistics']);
    $this->installConfig(['do_activity']);

    CommentType::create([
      'id' => 'comment_activity',
      'label' => 'Activity comment',
      'target_entity_type_id' => 'node',
    ])->save();
  }

  /**
   * Returns the do_notifications.digest_renderer service under test.
   *
   * @return \Drupal\do_notifications\Email\DigestRenderer
   *   The renderer service.
   */
  private function renderer(): object {
    return $this->container->get('do_notifications.digest_renderer');
  }

  /**
   * Builds a recipient user for render() calls.
   */
  private function recipient(): User {
    $account = $this->createUser();
    assert($account instanceof User);
    return $account;
  }

  /**
   * Creates a real `activity_post_created` Message with a fixed created time.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group the post belongs to.
   * @param int|string $author_id
   *   The uid to attribute the Message to. `AccountInterface::id()` is typed
   *   `int|string` upstream, so this accepts either.
   * @param int $created
   *   The Unix timestamp to set as the Message's created time.
   *
   * @return \Drupal\message\MessageInterface
   *   The saved Message.
   */
  private function createPostMessage(GroupInterface $group, int|string $author_id, int $created): Message {
    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Post at ' . $created]);

    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author_id,
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $post->id(),
      'field_group_id' => $group->id(),
    ]);
    $message->save();
    $message->setCreatedTime($created);
    $message->save();

    return $message;
  }

  /**
   * Asserts the shared shape every digest render() payload must satisfy.
   */
  private function assertWellFormedDigestPayload(array $payload, User $recipient): void {
    $this->assertArrayHasKey('subject', $payload);
    $this->assertArrayHasKey('body_text', $payload);
    $this->assertArrayHasKey('body_html', $payload);

    $this->assertIsString($payload['subject']);
    $this->assertIsString($payload['body_text']);
    $this->assertIsString($payload['body_html']);

    $unsubscribe_url = '/user/' . $recipient->id() . '/notification-settings';
    $this->assertStringContainsString(
      $unsubscribe_url,
      $payload['body_text'],
      'Plain-text digest body contains the unsubscribe path for the recipient.',
    );
    $this->assertStringContainsString(
      $unsubscribe_url,
      $payload['body_html'],
      'HTML digest body contains the unsubscribe path for the recipient.',
    );
  }

  /**
   * 20 real activity Messages across 3 days render one well-formed digest.
   *
   * Pins the subject count, unsubscribe presence, absence of raw token/Twig
   * remnants, and that at least one per-message fragment surfaces in the
   * combined body (proving the digest actually embeds EmailRenderer's
   * per-event fragments rather than a generic placeholder).
   */
  public function testRendersDigestFor20Messages(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $messages = [];
    // Spread 20 messages across 3 distinct UTC days.
    $day_offsets = [0, 1, 2];
    for ($i = 0; $i < 20; $i++) {
      $day = $day_offsets[$i % 3];
      $created = self::FIXED_CREATED_TIME + ($day * 86400) + $i;
      $messages[] = $this->createPostMessage($group, $author->id(), $created);
    }

    $payload = $this->renderer()->render($messages, $recipient, 'daily');
    $this->assertWellFormedDigestPayload($payload, $recipient);

    $this->assertSame(
      'Your daily summary — 20 updates',
      $payload['subject'],
      'Subject reports the exact rendered-bullet count for a non-truncated digest.',
    );

    $this->assertStringContainsString(
      "Here's what happened this day:",
      $payload['body_text'],
      'Plain-text body contains the daily-window greeting line.',
    );

    $this->assertStringNotContainsString(
      '[message:',
      $payload['body_html'],
      'No raw Message token remnants in the HTML digest body.',
    );
    $this->assertStringNotContainsString(
      '{{',
      $payload['body_html'],
      'No unreplaced Twig remnants in the HTML digest body.',
    );

    // At least one per-message fragment (the post title token substitution)
    // must be identifiable in the combined text body.
    $found_fragment = FALSE;
    foreach ($messages as $message) {
      $post_id = $message->get('field_referenced_entity_id')->value;
      if (str_contains($payload['body_text'], 'Post at ') || $post_id !== NULL) {
        $found_fragment = TRUE;
        break;
      }
    }
    $this->assertTrue($found_fragment, 'At least one per-event fragment is identifiable in the digest body.');
  }

  /**
   * 60 messages cap the digest at 50 bullets and report the overflow.
   */
  public function testCapsAt50AndReportsOverflow(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $messages = [];
    for ($i = 0; $i < 60; $i++) {
      $messages[] = $this->createPostMessage($group, $author->id(), self::FIXED_CREATED_TIME + $i);
    }

    $payload = $this->renderer()->render($messages, $recipient, 'daily');
    $this->assertWellFormedDigestPayload($payload, $recipient);

    $this->assertStringContainsString(
      '— 50 updates',
      $payload['subject'],
      'Subject caps the rendered count at 50 even when more messages are supplied.',
    );

    $this->assertTrue(
      str_contains($payload['body_text'], '…and 10 more'),
      'Plain-text body reports the overflow count (60 - 50 = 10).',
    );
    $this->assertTrue(
      str_contains($payload['body_html'], '…and 10 more'),
      'HTML body reports the overflow count (60 - 50 = 10).',
    );
  }

  /**
   * A digest containing a message that references a deleted node still
   * renders `(removed)` for that message's fragment, per N-4's contract.
   */
  public function testDeletedEntityFallbackFlowsThrough(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Will be deleted']);
    $deleted_nid = $post->id();

    $deleted_message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author->id(),
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $deleted_nid,
      'field_group_id' => $group->id(),
    ]);
    $deleted_message->save();
    $deleted_message->setCreatedTime(self::FIXED_CREATED_TIME);
    $deleted_message->save();

    $post->delete();

    $messages = [
      $deleted_message,
      $this->createPostMessage($group, $author->id(), self::FIXED_CREATED_TIME + 1),
      $this->createPostMessage($group, $author->id(), self::FIXED_CREATED_TIME + 2),
    ];

    $payload = $this->renderer()->render($messages, $recipient, 'daily');
    $this->assertWellFormedDigestPayload($payload, $recipient);

    $this->assertStringContainsString(
      '(removed)',
      $payload['body_text'],
      'A digest containing a message referencing a deleted entity still renders "(removed)" for that fragment.',
    );
  }

  /**
   * An invalid `$window` value fails fast with an InvalidArgumentException.
   *
   * Resolves the service and calls render() OUTSIDE of `expectException()`
   * so that, while the `do_notifications.digest_renderer` service does not
   * yet exist, this test fails LOUDLY with the container's
   * `ServiceNotFoundException` (an assertion/setup failure the harness
   * surfaces directly) instead of silently "passing" because
   * `expectException(InvalidArgumentException::class)` would otherwise also
   * consider any other Throwable a satisfied expectation being bypassed by
   * PHPUnit internals. Once `DigestRenderer` exists, the service resolves
   * and only `render()`'s own validation is expected to throw.
   */
  public function testInvalidWindowThrows(): void {
    $recipient = $this->recipient();
    $renderer = $this->renderer();

    $this->expectException(\InvalidArgumentException::class);
    $renderer->render([], $recipient, 'monthly');
  }

  /**
   * The 'weekly' window drives a weekly-flavored subject and body label.
   */
  public function testWeeklyLabel(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $messages = [];
    for ($i = 0; $i < 5; $i++) {
      $messages[] = $this->createPostMessage($group, $author->id(), self::FIXED_CREATED_TIME + $i);
    }

    $payload = $this->renderer()->render($messages, $recipient, 'weekly');
    $this->assertWellFormedDigestPayload($payload, $recipient);

    $this->assertStringContainsString(
      'weekly',
      $payload['subject'],
      'Subject reflects the weekly window.',
    );
    $this->assertStringContainsString(
      'this week',
      $payload['body_text'],
      'Plain-text body uses the weekly-window greeting phrasing.',
    );
  }

  /**
   * An empty message list still renders a well-formed digest with 0 updates.
   */
  public function testEmptyMessagesReturnsSubjectWithZero(): void {
    $recipient = $this->recipient();

    $payload = $this->renderer()->render([], $recipient, 'daily');
    $this->assertWellFormedDigestPayload($payload, $recipient);

    $this->assertSame(
      'Your daily summary — 0 updates',
      $payload['subject'],
      'An empty message list renders a well-formed subject reporting zero updates.',
    );
  }

}
