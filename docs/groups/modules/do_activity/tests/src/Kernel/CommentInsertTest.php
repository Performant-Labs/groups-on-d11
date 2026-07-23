<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity\Kernel;

use Drupal\comment\Entity\Comment;

/**
 * Log point 2/6 — "comment created" (#116 amended brief, log points item 2).
 *
 * Pins `#[Hook('comment_insert')]`. Refs = comment + commented entity, and
 * (if the commented entity is in a group context) the group. A comment on an
 * entity with NO group context records an empty group_ids reference — the
 * hook must not error or fabricate a group.
 *
 * Template id under test: `activity_comment_created`.
 *
 * @group do_activity
 * @group do_tests
 */
class CommentInsertTest extends ActivityKernelTestBase {

  /**
   * A comment on a node IN a group records one Message with the group ref.
   */
  public function testCommentOnGroupNodeRecordsMessageWithGroupRef(): void {
    $group = $this->createGroup();
    $author = $this->createUser();
    $commenter = $this->createUser();
    $node = $this->addNode($group, 'post', ['title' => 'Thread starter', 'uid' => $author->id()]);

    // Drain the post-created message so this test isolates the comment event.
    $this->setCurrentUser($commenter);
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => static::COMMENT_FIELD_NAME,
      'uid' => $commenter->id(),
      'comment_type' => 'comment',
      'subject' => 'Re: Thread starter',
      'comment_body' => ['value' => 'A reply.', 'format' => 'plain_text'],
      'status' => 1,
    ]);
    $comment->save();

    $messages = $this->messagesByTemplate('activity_comment_created');
    $this->assertCount(1, $messages, 'Exactly one activity_comment_created Message is recorded per comment.');

    $message = reset($messages);
    $this->assertSame(
      (int) $commenter->id(),
      (int) $message->getOwnerId(),
      'The Message actor is the commenter.'
    );
    $this->assertSame('comment', $message->get('field_referenced_entity_type')->value);
    $this->assertSame((int) $comment->id(), (int) $message->get('field_referenced_entity_id')->value);
    $this->assertSame(
      (int) $group->id(),
      (int) $message->get('field_group_id')->target_id,
      'The comment message carries the group of the commented-on node.'
    );
  }

  /**
   * A comment on a node with NO group context records an empty group ref.
   *
   * The hook must resolve group context safely — no group relationship
   * exists here, so the message must be created with an empty/null group
   * reference rather than erroring or omitting the message entirely.
   */
  public function testCommentOnUngroupedNodeRecordsMessageWithNoGroupRef(): void {
    $author = $this->createUser();
    $commenter = $this->createUser();
    $this->setCurrentUser($author);

    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => 'Ungrouped post',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $this->setCurrentUser($commenter);
    $comment = Comment::create([
      'entity_type' => 'node',
      'entity_id' => $node->id(),
      'field_name' => static::COMMENT_FIELD_NAME,
      'uid' => $commenter->id(),
      'comment_type' => 'comment',
      'subject' => 'Re: Ungrouped',
      'comment_body' => ['value' => 'A reply.', 'format' => 'plain_text'],
      'status' => 1,
    ]);
    $comment->save();

    $messages = $this->messagesByTemplate('activity_comment_created');
    $this->assertCount(1, $messages, 'A comment on an ungrouped node still records exactly one Message.');
    $message = reset($messages);
    $this->assertTrue(
      $message->get('field_group_id')->isEmpty(),
      'A comment on an ungrouped node carries an empty group reference (never a fabricated group).'
    );
  }

}
