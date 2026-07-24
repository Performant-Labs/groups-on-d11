<?php

declare(strict_types=1);

namespace Drupal\Tests\do_activity_feed\Kernel;

/**
 * Feed render — interleaved rows, access scoping, content-row access (#129).
 *
 * Pins AC-1, AC-3, AC-4 from the brief
 * (docs/planning/handoffs/129-activity-feed/brief.md) against
 * `ActivityFeedController::renderFeed(string $scope, ?GroupInterface $group =
 * NULL): array` — the exact contract survey.md's "Forward-compat check"
 * section commits to for ST-8 (#130) reuse.
 *
 * RED reason: neither `do_activity_feed` (the module/info.yml) nor
 * `ActivityFeedController` exist yet, so `enableModules()` throws (module
 * not found) before any assertion runs — the right-for-the-wrong-code
 * failure this suite is authored to require F to resolve, not a test-authored
 * typo. Once F ships the controller, every assertion below exercises the
 * real render-array contract.
 *
 * Layer choice: kernel. The row-shape/scoping/access-omission behavior here
 * is pure PHP + Views-query behavior — no client interaction, no HTTP
 * response shape to assert — so kernel is the cheapest sufficient tier
 * (cheaper than Functional/E2E, which would only add HTTP/routing overhead
 * without pinning anything this tier cannot already prove).
 *
 * Fixture note (T-green fixture repair): `GroupsKernelTestBase::addNode()`/
 * `addMember()` relate the fixture via `Group::addRelationship()`, which
 * fires `do_activity`'s own LIVE `#[Hook('group_relationship_insert')]` as a
 * side effect — creating ADDITIONAL, uncontrolled `Message`s attributed to
 * whichever user is `\Drupal::currentUser()` at that moment (see
 * handoff-F.md's "Tests that look wrong" #3). This only actually corrupts an
 * assertion in `testFeedRendersInterleavedRowTypes()` (the aggregation COUNT
 * assertion is sensitive to extra same-actor/window Messages); the other two
 * tests assert node-id PRESENCE/ABSENCE, which the hook noise does not
 * falsify (the noise references the SAME nodes already in scope). See
 * {@see self::pruneHookNoiseMessages()}.
 *
 * @group do_activity_feed
 * @group do_tests
 */
class ActivityFeedRenderTest extends ActivityFeedKernelTestBase {

  /**
   * AC-1: the feed interleaves social, content, and aggregated row types.
   *
   * Seeds: (a) one activity_membership_created (Elena joins group A) — a
   * social row; (b) one standalone activity_post_created by Alex in group A
   * — a content row (count 1, not aggregated); (c) a run of THREE
   * activity_post_created by Maria in group A, each <=5h apart — folds into
   * ONE aggregated row with count=3, per the brief's pairwise-consecutive
   * window rule (also covered in depth by ActivityAggregationTest).
   *
   * Elena is a member of group A, so `renderFeed('my_groups')` for Elena
   * must surface all three row shapes in one call.
   */
  public function testFeedRendersInterleavedRowTypes(): void {
    $groupA = $this->createGroup();
    // UserCreationTrait::createUser()'s real signature is
    // createUser(array $permissions = [], $name = NULL, $admin = FALSE,
    // array $values = []) — the FIRST positional param is a PERMISSIONS
    // array, not a values array (T-green fixture repair; see handoff-F.md's
    // "Tests that look wrong" #2). Empty permissions array, name as the 2nd
    // positional arg.
    $elena = $this->createUser([], 'elena_test');
    $alex = $this->createUser([], 'alex_test');
    $maria = $this->createUser([], 'maria_test');

    $this->addMember($groupA, $elena);

    $now = \Drupal::time()->getRequestTime();

    // (a) Social row: Elena's own membership_created message.
    $elenaMessage = $this->createMembershipMessage($elena, $groupA, $now - 3600);

    // (b) Content row: Alex's standalone post, alone in time (>6h from
    // anything else), so it renders as a single content_card, not
    // aggregated.
    $alexNode = $this->addNode($groupA, 'post', ['title' => 'Alex standalone post', 'uid' => $alex->id()]);
    $alexMessage = $this->createPostMessage($alex, $groupA, $alexNode, $now - 20 * 3600);

    // (c) Aggregated row: Maria's run of 3 posts, each <=5h apart.
    $mariaNode1 = $this->addNode($groupA, 'post', ['title' => 'Maria topic 1', 'uid' => $maria->id()]);
    $mariaNode2 = $this->addNode($groupA, 'post', ['title' => 'Maria topic 2', 'uid' => $maria->id()]);
    $mariaNode3 = $this->addNode($groupA, 'post', ['title' => 'Maria topic 3', 'uid' => $maria->id()]);
    $mariaMessage1 = $this->createPostMessage($maria, $groupA, $mariaNode1, $now - 15 * 3600);
    $mariaMessage2 = $this->createPostMessage($maria, $groupA, $mariaNode2, $now - 10 * 3600);
    $mariaMessage3 = $this->createPostMessage($maria, $groupA, $mariaNode3, $now - 5 * 3600);

    // Prune the do_activity hook-noise Messages the addNode()/addMember()
    // calls above fired as an uncontrolled side effect (see class docblock),
    // keeping ONLY the Messages this test explicitly authored — the
    // aggregation-count assertion below is sensitive to stray same-window
    // Messages the hook attributes to whatever the current user happened to
    // be at fixture-setup time.
    $this->pruneHookNoiseMessages([
      (int) $elenaMessage->id(),
      (int) $alexMessage->id(),
      (int) $mariaMessage1->id(),
      (int) $mariaMessage2->id(),
      (int) $mariaMessage3->id(),
    ]);

    $this->setCurrentUser($elena);

    /** @var \Drupal\do_activity_feed\Controller\ActivityFeedController $controller */
    $controller = \Drupal::classResolver('Drupal\do_activity_feed\Controller\ActivityFeedController');
    $build = $controller->renderFeed('my_groups');

    $rows = $build['#rows'] ?? [];
    $this->assertNotEmpty($rows, 'renderFeed() returns at least one row.');

    $types = array_column($rows, 'type');
    $this->assertContains('social_join', $types, 'A social join row (activity_membership_created) is present.');
    $this->assertContains('content_card', $types, "Alex's standalone post renders as a single content_card row.");
    $this->assertContains('aggregated', $types, "Maria's run of 3 posts folds into one aggregated row.");

    $aggregatedRow = current(array_filter($rows, static fn (array $row): bool => $row['type'] === 'aggregated'));
    $this->assertNotFalse($aggregatedRow, 'An aggregated row was found in the render array.');
    $this->assertSame(3, $aggregatedRow['count'], "Maria's aggregated row reports count=3.");
  }

  /**
   * AC-3: access scoping — a user only sees rows in groups they belong to.
   *
   * A user who is a member of Group A ONLY must see Group A's rows and never
   * Group B's, even though Group B has its own activity. A user in NO
   * groups sees an entirely empty result.
   */
  public function testAccessScopingRestrictsToViewersGroups(): void {
    $groupA = $this->createGroup();
    $groupB = $this->createGroup();

    $memberA = $this->createUser();
    $this->addMember($groupA, $memberA);

    $nodeA = $this->addNode($groupA, 'post', ['title' => 'Group A post', 'uid' => $memberA->id()]);
    $this->createPostMessage($memberA, $groupA, $nodeA, \Drupal::time()->getRequestTime());

    $otherUser = $this->createUser();
    $this->addMember($groupB, $otherUser);
    $nodeB = $this->addNode($groupB, 'post', ['title' => 'Group B post', 'uid' => $otherUser->id()]);
    $this->createPostMessage($otherUser, $groupB, $nodeB, \Drupal::time()->getRequestTime());

    $this->setCurrentUser($memberA);
    $controller = \Drupal::classResolver('Drupal\do_activity_feed\Controller\ActivityFeedController');
    $build = $controller->renderFeed('my_groups');
    $rows = $build['#rows'] ?? [];

    $referencedNodeIds = array_filter(array_map(
      static fn (array $row): ?int => $row['referenced_entity_id'] ?? NULL,
      $rows,
    ));
    $this->assertContains((int) $nodeA->id(), $referencedNodeIds, "Group A's member sees Group A's own row.");
    $this->assertNotContains((int) $nodeB->id(), $referencedNodeIds, "Group A's member never sees Group B's row.");

    // A user in NO groups sees an entirely empty result.
    $lonelyUser = $this->createUser();
    $this->setCurrentUser($lonelyUser);
    $emptyBuild = $controller->renderFeed('my_groups');
    $this->assertEmpty($emptyBuild['#rows'] ?? [], 'A user in no groups sees an empty feed result.');
  }

  /**
   * AC-4: a content row referencing a node the viewer cannot view is omitted.
   *
   * Per the brief's Access section: "Content-row builder additionally
   * checks $node->access('view') before rendering the stream_card; on deny,
   * drop the row (feed skips it)" — NOT an "access denied" placeholder row.
   */
  public function testContentRowOmittedWhenNodeNotViewable(): void {
    $group = $this->createGroup();
    $viewer = $this->createUser();
    $author = $this->createUser();
    $this->addMember($group, $viewer);
    $this->addMember($group, $author);

    // An unpublished node the viewer (a plain member, no bypass/edit-others
    // permission) cannot view.
    $unpublishedNode = $this->addNode($group, 'post', [
      'title' => 'Unpublished — viewer cannot see this',
      'uid' => $author->id(),
      'status' => 0,
    ]);
    $this->createPostMessage($author, $group, $unpublishedNode, \Drupal::time()->getRequestTime());

    // A published node from the same author, viewable, to prove the feed
    // isn't simply empty for unrelated reasons.
    $publishedNode = $this->addNode($group, 'post', [
      'title' => 'Published and viewable',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $this->createPostMessage($author, $group, $publishedNode, \Drupal::time()->getRequestTime() - 3600);

    $this->assertFalse(
      $unpublishedNode->access('view', $viewer),
      'Precondition: the plain member viewer genuinely cannot view the unpublished node.'
    );

    $this->setCurrentUser($viewer);
    $controller = \Drupal::classResolver('Drupal\do_activity_feed\Controller\ActivityFeedController');
    $build = $controller->renderFeed('my_groups');
    $rows = $build['#rows'] ?? [];

    $referencedNodeIds = array_filter(array_map(
      static fn (array $row): ?int => $row['referenced_entity_id'] ?? NULL,
      $rows,
    ));
    $this->assertNotContains(
      (int) $unpublishedNode->id(),
      $referencedNodeIds,
      'The row referencing a node the viewer cannot view is dropped entirely (not an access-denied placeholder).'
    );
    $this->assertContains(
      (int) $publishedNode->id(),
      $referencedNodeIds,
      'The viewable node from the same author still appears — proving the omission is access-specific, not a blanket empty feed.'
    );
  }

  /**
   * Deletes every Message NOT in the given explicit id list.
   *
   * Fixture cleanup for do_activity's uncontrolled group_relationship_insert
   * hook side effect (see class docblock) — removes only the Messages this
   * test did NOT itself explicitly author, never touching the Messages under
   * test.
   *
   * @param int[] $keepMessageIds
   *   The mids this test explicitly created and wants preserved.
   */
  protected function pruneHookNoiseMessages(array $keepMessageIds): void {
    $storage = $this->entityTypeManager->getStorage('message');
    $storage->resetCache();
    $all = $storage->loadMultiple();
    $noise = array_filter(
      $all,
      static fn ($message): bool => !in_array((int) $message->id(), $keepMessageIds, TRUE),
    );
    if ($noise) {
      $storage->delete($noise);
    }
  }

}
