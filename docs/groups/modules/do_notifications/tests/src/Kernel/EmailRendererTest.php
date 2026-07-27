<?php

declare(strict_types=1);

namespace Drupal\Tests\do_notifications\Kernel;

use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\comment\Entity\Comment;
use Drupal\message\Entity\Message;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_notifications' EmailRenderer service (#232).
 *
 * Pins the N-4 contract: `Drupal\do_notifications\Email\EmailRenderer::render()`
 * turns a real `activity_*` Message (do_activity, #116) into a two-part email
 * payload (subject + plain-text body + minimal HTML body), delegating token
 * substitution to the Message module's own `\Drupal::token()->replace()`
 * boundary (brief v2, "Rendering pipeline" section) rather than re-implementing
 * it, and never throwing when a referenced entity has been deleted.
 *
 * @group do_notifications
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class EmailRendererTest extends GroupsKernelTestBase {

  /**
   * A fixed Unix timestamp used to pin the rendered timestamp line.
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

    // Comment field on 'post' nodes, minimally, so comment entities can be
    // created and loaded (activity_comment_created references a comment).
    if (!FieldStorageConfig::loadByName('comment', 'comment_body')) {
      // Not required: comment module ships its own comment_body storage via
      // its own config; we only need the comment entity type + a bundle.
    }
    CommentType::create([
      'id' => 'comment_activity',
      'label' => 'Activity comment',
      'target_entity_type_id' => 'node',
    ])->save();
  }

  /**
   * Returns the do_notifications.email_renderer service under test.
   *
   * @return \Drupal\do_notifications\Email\EmailRenderer
   *   The renderer service.
   */
  private function renderer(): object {
    return $this->container->get('do_notifications.email_renderer');
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
   * Asserts the shared shape/invariants every render() payload must satisfy.
   *
   * @param array $payload
   *   The render() return value.
   * @param \Drupal\user\Entity\User $recipient
   *   The recipient passed to render(), so the unsubscribe URL can be checked.
   */
  private function assertWellFormedPayload(array $payload, User $recipient): void {
    $this->assertArrayHasKey('subject', $payload);
    $this->assertArrayHasKey('body_text', $payload);
    $this->assertArrayHasKey('body_html', $payload);

    $this->assertIsString($payload['subject']);
    $this->assertNotSame('', trim($payload['subject']), 'Subject is non-empty.');

    $this->assertIsString($payload['body_text']);
    $this->assertNotSame('', trim($payload['body_text']), 'Plain-text body is non-empty.');
    $this->assertStringNotContainsString(
      '[message:',
      $payload['body_text'],
      'No unreplaced Message token remnants in the plain-text body.',
    );
    $this->assertStringNotContainsString(
      '{{',
      $payload['body_text'],
      'No unreplaced Twig remnants in the plain-text body.',
    );

    $this->assertIsString($payload['body_html']);
    $this->assertNotSame('', trim($payload['body_html']), 'HTML body is non-empty.');
    $unsubscribe_url = '/user/' . $recipient->id() . '/notification-settings';
    $this->assertStringContainsString(
      $unsubscribe_url,
      $payload['body_html'],
      'HTML body contains the unsubscribe URL for the recipient.',
    );
    $this->assertStringContainsString(
      $unsubscribe_url,
      $payload['body_text'],
      'Plain-text body contains the unsubscribe URL for the recipient.',
    );

    $expected_timestamp = \Drupal::service('date.formatter')->format(
      self::FIXED_CREATED_TIME,
      'custom',
      'D, j M Y - H:i',
    );
    $this->assertStringContainsString(
      $expected_timestamp,
      $payload['body_text'],
      'Plain-text body contains the event timestamp, formatted like core\'s "medium" date format.',
    );
    $this->assertStringContainsString(
      $expected_timestamp,
      $payload['body_html'],
      'HTML body contains the event timestamp, formatted like core\'s "medium" date format.',
    );
  }

  /**
   * All 6 activity_* Message templates render a well-formed email payload.
   *
   * Each case instantiates the Message with a REAL referenced entity (the
   * templates require one — see do_activity's field.field.message.* config)
   * so token replacement has real values to substitute, then calls render()
   * and asserts the shared payload invariants.
   */
  public function testRendersAllSixEventTypes(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'A post']);

    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $post->id(),
      'field_name' => 'comment_activity',
      'comment_type' => 'comment_activity',
      'subject' => 'A comment',
      'uid' => $author->id(),
    ]);
    $comment->save();

    $member = $this->createUser();
    $this->addMember($group, $member);

    $cases = [
      'activity_post_created' => [
        'field_referenced_entity_type' => 'node',
        'field_referenced_entity_id' => $post->id(),
        'field_group_id' => $group->id(),
      ],
      'activity_comment_created' => [
        'field_referenced_entity_type' => 'comment',
        'field_referenced_entity_id' => $comment->id(),
        'field_group_id' => $group->id(),
      ],
      'activity_membership_created' => [
        'field_group_id' => $group->id(),
        'uid' => $member->id(),
      ],
      'activity_group_created' => [
        'field_referenced_entity_type' => 'group',
        'field_referenced_entity_id' => $group->id(),
      ],
      'activity_flagging_created' => [
        'field_referenced_entity_type' => 'node',
        'field_referenced_entity_id' => $post->id(),
      ],
      'activity_pin_toggled' => [
        'field_referenced_entity_type' => 'node',
        'field_referenced_entity_id' => $post->id(),
      ],
    ];

    foreach ($cases as $template => $values) {
      $message = Message::create($values + [
        'template' => $template,
        'uid' => $author->id(),
      ]);
      $message->save();
      $message->setCreatedTime(self::FIXED_CREATED_TIME);
      $message->save();

      $payload = $this->renderer()->render($message, $recipient);
      $this->assertWellFormedPayload($payload, $recipient);
    }
  }

  /**
   * A Message referencing a DELETED entity renders `(removed)`, never throws.
   *
   * Creates a post-referencing activity_post_created Message, then deletes
   * the underlying node before calling render() — pinning the brief's
   * "(removed) semantics live at the token-substitution boundary" contract
   * (v2 brief, Rendering pipeline §2): a missing referenced entity must not
   * raise an exception, and the string `(removed)` must appear in the
   * plain-text body in its place.
   */
  public function testMissingReferencedEntityRendersRemoved(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();
    $recipient = $this->recipient();

    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'Will be deleted']);
    $deleted_nid = $post->id();

    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author->id(),
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $deleted_nid,
      'field_group_id' => $group->id(),
    ]);
    $message->save();

    $post->delete();

    $payload = $this->renderer()->render($message, $recipient);

    $this->assertStringContainsString(
      '(removed)',
      $payload['body_text'],
      'A Message referencing a deleted entity renders "(removed)" rather than throwing.',
    );
  }

  /**
   * `renderEventFragments()` returns the exact locked fragment shape (#236).
   *
   * Pins the N-8 extract-method contract (brief §"Design decisions" #3, A
   * warn #1): the new public helper `EmailRenderer::renderEventFragments()`
   * — which `DigestRenderer` consumes to embed per-event fragments into a
   * combined digest — must return an array with EXACTLY the keys
   * `text_line`, `html_body`, `created`, so a digest can slot the fragment
   * into its own shell without re-deriving the per-event body pair.
   */
  public function testRenderEventFragmentsReturnsLockedShape(): void {
    $group = $this->createGroup();
    $author = $this->getCurrentUser();

    $post = $this->addNode($group, 'post', ['status' => 1, 'title' => 'A post']);

    $message = Message::create([
      'template' => 'activity_post_created',
      'uid' => $author->id(),
      'field_referenced_entity_type' => 'node',
      'field_referenced_entity_id' => $post->id(),
      'field_group_id' => $group->id(),
    ]);
    $message->save();
    $message->setCreatedTime(self::FIXED_CREATED_TIME);
    $message->save();

    $fragments = $this->renderer()->renderEventFragments($message);

    $this->assertIsArray($fragments);
    $this->assertArrayHasKey('text_line', $fragments);
    $this->assertArrayHasKey('html_body', $fragments);
    $this->assertArrayHasKey('created', $fragments);

    $this->assertIsString($fragments['text_line']);
    $this->assertNotSame('', trim($fragments['text_line']), 'text_line is a non-empty string.');

    $this->assertIsString($fragments['html_body']);
    $this->assertNotSame('', trim($fragments['html_body']), 'html_body is a non-empty string.');

    $this->assertIsInt($fragments['created']);
    $this->assertSame(
      $message->getCreatedTime(),
      $fragments['created'],
      'created matches the Message entity\'s own created timestamp.',
    );
  }

}
