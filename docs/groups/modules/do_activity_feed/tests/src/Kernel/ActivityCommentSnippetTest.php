<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

/**
 * Comment snippet — truncation + tag-stripping (#129 AC-5).
 *
 * Pins survey.md's "Comment snippet" key finding: "load comment via
 * (field_referenced_entity_type='comment', id), take comment_body.value,
 * strip tags, truncate to 180 chars." Exercised against
 * `ActivityRowBuilder` directly (the service survey.md's owned-files list
 * names as owning row-model construction) rather than the full controller,
 * since the snippet-shaping rule is a pure string-transform unit, not a
 * scoping/interleaving behavior — ActivityFeedRenderTest already covers the
 * end-to-end row-type contract, so this file does not duplicate that; it
 * isolates the ONE transform AC-5 names.
 *
 * RED reason: `ActivityRowBuilder`
 * (docs/groups/modules/do_activity_feed/src/Service/ActivityRowBuilder.php)
 * does not exist yet — the service is absent, so resolving
 * `do_activity_feed.row_builder` from the container fails at
 * service-not-found, not on a truncation-length miscalculation.
 *
 * Layer choice: kernel — a real Comment entity + a real Message referencing
 * it are needed to prove the snippet is read off the ACTUAL comment_body
 * field (not a stub string), but no HTTP/rendering layer is required beyond
 * that.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityCommentSnippetTest extends ActivityFeedKernelTestBase {

  /**
   * Loads the ActivityRowBuilder service, asserting it resolves.
   *
   * @return object
   *   The do_activity_feed.row_builder service.
   */
  protected function rowBuilder(): object {
    $service = \Drupal::service('do_activity_feed.row_builder');
    $this->assertNotNull($service, 'The do_activity_feed.row_builder service resolves.');
    return $service;
  }

  /**
   * A short comment body passes through verbatim (no truncation needed).
   *
   * Establishes the baseline before the truncation-boundary test below —
   * proves the snippet is a REAL passthrough of the comment body, not a
   * hardcoded placeholder that happens to also satisfy the length ceiling.
   */
  public function testShortCommentBodyPassesThroughUntruncated(): void {
    $group = $this->createGroup();
    $author = $this->createUser();
    $commenter = $this->createUser();
    $node = $this->addNode($group, 'post', ['title' => 'Thread starter', 'uid' => $author->id()]);

    $body = 'Room C has poor Wi-Fi coverage.';
    $message = $this->createCommentMessage($commenter, $group, $node, $body, \Drupal::time()->getRequestTime());

    $snippet = $this->rowBuilder()->buildCommentSnippet($message);

    $this->assertSame($body, $snippet, 'A short comment body is returned verbatim.');
    $this->assertLessThanOrEqual(180, strlen($snippet), 'The snippet never exceeds 180 characters.');
  }

  /**
   * A comment body over 180 chars is truncated to <=180 chars.
   */
  public function testLongCommentBodyIsTruncatedToOneEightyChars(): void {
    $group = $this->createGroup();
    $author = $this->createUser();
    $commenter = $this->createUser();
    $node = $this->addNode($group, 'post', ['title' => 'Thread starter', 'uid' => $author->id()]);

    // 250 plain-text characters — comfortably over the 180-char ceiling.
    $body = str_repeat('This sentence is exactly forty chars long. ', 6);
    $this->assertGreaterThan(180, strlen($body), 'Precondition: the raw fixture body exceeds 180 chars.');

    $message = $this->createCommentMessage($commenter, $group, $node, $body, \Drupal::time()->getRequestTime());
    $snippet = $this->rowBuilder()->buildCommentSnippet($message);

    $this->assertLessThanOrEqual(180, strlen($snippet), 'A long comment body is truncated to at most 180 characters.');
    $this->assertStringStartsWith(
      substr($body, 0, 50),
      $snippet,
      'The truncated snippet still starts with the original body text (a real truncation, not a placeholder).'
    );
  }

  /**
   * HTML tags in the comment body are stripped from the rendered snippet.
   *
   * Guards against an implementation that truncates raw HTML byte-for-byte,
   * which could truncate mid-tag and leave a dangling `<sc` fragment, or
   * simply render markup as literal text in the snippet quote.
   */
  public function testHtmlTagsAreStrippedFromSnippet(): void {
    $group = $this->createGroup();
    $author = $this->createUser();
    $commenter = $this->createUser();
    $node = $this->addNode($group, 'post', ['title' => 'Thread starter', 'uid' => $author->id()]);

    $body = '<p>Room C has <strong>poor</strong> Wi-Fi coverage.</p><script>alert(1)</script>';
    $message = $this->createCommentMessage($commenter, $group, $node, $body, \Drupal::time()->getRequestTime());
    $snippet = $this->rowBuilder()->buildCommentSnippet($message);

    $this->assertStringNotContainsString('<p>', $snippet, 'No <p> tag survives in the snippet.');
    $this->assertStringNotContainsString('<strong>', $snippet, 'No <strong> tag survives in the snippet.');
    $this->assertStringNotContainsString('<script>', $snippet, 'No <script> tag survives in the snippet.');
    $this->assertStringContainsString('Room C has', $snippet, 'The underlying text content is preserved after tag-stripping.');
    $this->assertStringContainsString('poor', $snippet, 'Text that was wrapped in an inline tag is preserved, only the tag itself is stripped.');
  }

}
