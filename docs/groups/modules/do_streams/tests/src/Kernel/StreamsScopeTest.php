<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\Core\Config\FileStorage;
use Drupal\flag\Entity\Flag;
use Drupal\group\PermissionScopeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use Drupal\user\RoleInterface;
use Drupal\views\Views;

/**
 * Behavioral test for do_streams' membership-scope and following-scope plugins.
 *
 * Issue #109 (epic #108), acceptance criteria 1-2 / brief [B-9] (membership) and
 * survey §Testing approach items 1-2 (following). Pins the query-result CONTRACT
 * of the two Views scope plugins the issue mandates:
 *  - do_streams_membership_scope (a Views FILTER plugin): returns nodes with a
 *    `group_relationship` row (`plugin_id LIKE 'group_node:%'`) in ANY group the
 *    CURRENT viewing user belongs to (a group_membership relationship), per
 *    [B-9]'s EXISTS-shaped reference semantics. Nodes in a group the current
 *    user is NOT a member of are excluded.
 *  - do_streams_following_scope (a Views FILTER plugin): ORs three independently
 *    verified branches — `follow_content` (the node itself flagged),
 *    `follow_user` (the node's author flagged by the viewer), `follow_term`
 *    (a term on the node's `field_group_tags` flagged by the viewer, per [B-4]
 *    — NOT `field_tags`) — deduped so a node matching >1 branch appears exactly
 *    once.
 *
 * Neither plugin exists yet (`find` over the codebase returns zero
 * `Plugin/views/filter/` results — see survey.md). Executing the fixture
 * `do_streams_demo` view, which references these two plugin ids in its filter
 * config, throws a PluginNotFoundException until F implements them — this is
 * the intended RED: the assertion under test is "the scoped query returns the
 * right rows," and that assertion cannot even be reached without the plugin
 * class existing.
 *
 * Layer choice: kernel view-execution, mirroring
 * {@see \Drupal\Tests\do_group_pin\Kernel\PinnedStreamOrderingTest} — the
 * scope plugins' behavior is entirely in the compiled SQL a Views filter
 * contributes; executing the real view and reading `$view->result` asserts
 * row-membership far more precisely than scraping rendered HTML.
 *
 * @group do_streams
 * @group do_tests
 */
class StreamsScopeTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_group_pin',
    'do_discovery',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('flagging');
    $this->installEntitySchema('comment');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('flag', ['flag_counts']);
    $this->installSchema('do_discovery', ['do_discovery_hot_score']);

    // Following-scope needs the follow_* flags + the field_group_tags field
    // ([B-4]: NOT field_tags) on at least one NODE_BUNDLES bundle so the
    // follow_term join has somewhere to attach. Install the real shipped
    // config fixtures byte-identical to docs/groups/config/ (mirrors
    // PinnedStreamOrderingTest's fixture-install pattern), stripping the one
    // key with no matching config schema (`flagTypeConfig.access_author` on
    // follow_content — a pre-existing gap in the shipped config, unrelated
    // to do_streams / this story, that trips strict config-schema checking).
    // This keeps strict config schema ON while exercising the real flag
    // definitions do_streams' following-scope plugin joins against.
    $fixtures = new FileStorage(__DIR__ . '/../../../../../config');
    $entity_type_manager = $this->container->get('entity_type.manager');
    foreach (['flag.flag.follow_content', 'flag.flag.follow_user', 'flag.flag.follow_term'] as $config_name) {
      $values = $fixtures->read($config_name);
      unset($values['flagTypeConfig']['access_author']);
      $entity_type_manager->getStorage('flag')->create($values)->save();
    }

    // taxonomy vocabulary + the field_group_tags storage/field-config the
    // follow_term join reads (per [B-4], scoped to the 'page' bundle here —
    // sufficient to prove the join shape without installing all 5 bundles).
    \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->create([
      'vid' => 'group_tags',
      'name' => 'Group Tags',
    ])->save();
    $this->installConfig(['field']);
    $field_storage_config = $fixtures->read('field.storage.node.field_group_tags');
    $entity_type_manager->getStorage('field_storage_config')->create($field_storage_config)->save();
    $field_config = $fixtures->read('field.field.node.page.field_group_tags');
    $entity_type_manager->getStorage('field_config')->create($field_config)->save();

    // Install the do_streams_demo fixture view (Kernel-level "test view" per
    // [B-7]; NOT shipped config).
    $view_fixtures = new FileStorage(__DIR__ . '/../../fixtures/config');
    $entity_type_manager->getStorage('view')
      ->create($view_fixtures->read('views.view.do_streams_demo'))
      ->save();

    // Outsider-scope group role granting view permission on every
    // group_node:* bundle, mirroring PinnedStreamOrderingTest, so the
    // non-member current user's membership-scope EXCLUSION is asserted
    // against Group's own access layer letting the query through (the
    // exclusion under test must come from do_streams' own scope filter, not
    // from Group's access policy incidentally hiding the row).
    $permissions = [];
    foreach (static::NODE_BUNDLES as $node_type) {
      $permissions[] = "view group_node:$node_type relationship";
      $permissions[] = "view group_node:$node_type entity";
    }
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);

    // Insider-scope group role granting the SAME view permission set to
    // group MEMBERS. Group 4.x's own QueryAccess layer
    // (PluginBasedQueryAlterBase::addSynchronizedConditions()) grants the
    // outsider-scope role's permissions ONLY when the membership join column
    // is NULL (`isNull("$membership_alias.entity_id")` when
    // scope === OUTSIDER_ID) -- i.e. only to users who are NOT already a
    // group member. A viewing user who IS a member of the group under test
    // (testMembershipScopeReturnsOnlyMemberGroupsNodes /
    // testMembershipScopeCoversAllOfTheUsersGroups) therefore has ZERO view
    // access on their own group's content without a separate insider-scope
    // grant, which would make Group's own access layer (not do_streams' own
    // filter) the reason those tests' assertions fail -- masking the
    // behavior under test. Confirmed by reading
    // group/src/QueryAccess/PluginBasedQueryAlterBase.php directly.
    $this->createGroupRole([
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INSIDER_ID,
      'global_role' => RoleInterface::AUTHENTICATED_ID,
      'permissions' => $permissions,
    ]);
  }

  /**
   * Executes a do_streams_demo display and returns its result rows.
   *
   * @param string $display_id
   *   The view display id to execute.
   *
   * @return \Drupal\views\ResultRow[]
   *   The ordered result rows (post-DISTINCT).
   */
  protected function executeDemo(string $display_id): array {
    $view = Views::getView('do_streams_demo');
    $this->assertNotNull($view, 'The do_streams_demo fixture view loaded.');
    $view->setDisplay($display_id);
    $view->preExecute();
    $view->execute();
    return $view->result;
  }

  /**
   * Maps an ordered result set to the node ids in that order.
   *
   * @param \Drupal\views\ResultRow[] $rows
   *   The result rows.
   *
   * @return int[]
   *   Node ids in result order.
   */
  protected function nidsInOrder(array $rows): array {
    return array_map(static fn ($row) => (int) $row->nid, $rows);
  }

  /**
   * Membership scope returns nodes in a group the current user belongs to.
   *
   * Acceptance criterion 1 / [B-9]. The current viewing user is made a member
   * of group A only. Group A's node must appear; group B's node (a DIFFERENT
   * group the user does NOT belong to) must be excluded, even though Group's
   * own access layer (granted via the outsider role in setUp()) would
   * otherwise let a non-member VIEW group B's content — proving the exclusion
   * is do_streams' own scope filter, not incidental access denial.
   */
  public function testMembershipScopeReturnsOnlyMemberGroupsNodes(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $memberGroup = $this->createGroup();
    $this->addMember($memberGroup, $viewer);
    $memberNode = $this->addNode($memberGroup, 'page', ['title' => 'In my group']);

    $otherGroup = $this->createGroup();
    $otherNode = $this->addNode($otherGroup, 'page', ['title' => 'Not my group']);

    $rows = $this->executeDemo('page_1');
    $nids = $this->nidsInOrder($rows);

    $this->assertContains(
      (int) $memberNode->id(),
      $nids,
      'A node in a group the current user belongs to is included in membership scope.'
    );
    $this->assertNotContains(
      (int) $otherNode->id(),
      $nids,
      'A node in a group the current user does NOT belong to is excluded from membership scope.'
    );
  }

  /**
   * Membership scope covers every group the user belongs to, not just one.
   *
   * [B-9]'s EXISTS join is per-user, not per-group — a user in two groups
   * sees both groups' content, ruling out an implementation that only checks
   * a single (e.g. first) membership row.
   */
  public function testMembershipScopeCoversAllOfTheUsersGroups(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $groupOne = $this->createGroup();
    $this->addMember($groupOne, $viewer);
    $nodeOne = $this->addNode($groupOne, 'page', ['title' => 'Group One Post']);

    $groupTwo = $this->createGroup();
    $this->addMember($groupTwo, $viewer);
    $nodeTwo = $this->addNode($groupTwo, 'page', ['title' => 'Group Two Post']);

    $nids = $this->nidsInOrder($this->executeDemo('page_1'));

    $this->assertContains((int) $nodeOne->id(), $nids, 'Content from the first membership group appears.');
    $this->assertContains((int) $nodeTwo->id(), $nids, 'Content from the second membership group also appears.');
  }

  /**
   * Membership scope returns zero rows for a user in no groups.
   *
   * A negative-space guard: a user with no group_membership relationship at
   * all must see nothing via membership scope, even though unrelated nodes
   * exist and are group-content elsewhere.
   */
  public function testMembershipScopeIsEmptyForNonMember(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);

    $group = $this->createGroup();
    $this->addNode($group, 'page', ['title' => 'Someone elses content']);

    $rows = $this->executeDemo('page_1');
    $this->assertCount(0, $rows, 'A user in no groups sees no rows via membership scope.');
  }

  /**
   * follow_content alone surfaces the flagged node.
   *
   * Acceptance criterion 2 (following scope), branch 1 of 3. Flagging a node
   * directly via follow_content must alone be sufficient for it to appear —
   * no follow_user/follow_term flag is set in this test.
   */
  public function testFollowingScopeFollowContentBranch(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);
    $group = $this->createGroup();
    $node = $this->addNode($group, 'page', ['title' => 'Followed directly']);
    $unfollowed = $this->addNode($group, 'page', ['title' => 'Not followed']);

    $flagService = $this->container->get('flag');
    $flag = Flag::load('follow_content');
    $this->assertNotNull($flag, 'The follow_content flag is installed.');
    $flagService->flag($flag, $node, $viewer);

    $nids = $this->nidsInOrder($this->executeDemo('page_following'));
    $this->assertContains((int) $node->id(), $nids, 'A node flagged via follow_content appears in following scope.');
    $this->assertNotContains((int) $unfollowed->id(), $nids, 'An unflagged, unfollowed node does not appear.');
  }

  /**
   * follow_user alone surfaces every node authored by the followed user.
   *
   * Acceptance criterion 2, branch 2 of 3: the viewer follows the AUTHOR
   * (flags the user entity), not the node itself.
   */
  public function testFollowingScopeFollowUserBranch(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);
    $author = $this->createUser();
    $group = $this->createGroup();
    $node = $this->addNode($group, 'page', ['title' => 'By a followed author', 'uid' => $author->id()]);

    $strangerAuthor = $this->createUser();
    $strangerNode = $this->addNode($group, 'page', ['title' => 'By a stranger', 'uid' => $strangerAuthor->id()]);

    $flagService = $this->container->get('flag');
    $flag = Flag::load('follow_user');
    $this->assertNotNull($flag, 'The follow_user flag is installed.');
    $flagService->flag($flag, $author, $viewer);

    $nids = $this->nidsInOrder($this->executeDemo('page_following'));
    $this->assertContains((int) $node->id(), $nids, "A node authored by a followed user appears via follow_user.");
    $this->assertNotContains((int) $strangerNode->id(), $nids, 'A node by a non-followed author does not appear.');
  }

  /**
   * follow_term alone surfaces every node tagged with the followed term.
   *
   * Acceptance criterion 2, branch 3 of 3, [B-4]: joins via
   * `field_group_tags` (NOT `field_tags`, which does not exist on the
   * group_node:* bundles).
   */
  public function testFollowingScopeFollowTermBranch(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);
    $group = $this->createGroup();

    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create([
      'vid' => 'group_tags',
      'name' => 'Followed Tag',
    ]);
    $term->save();

    $taggedNode = $this->addNode($group, 'page', [
      'title' => 'Tagged with the followed term',
      'field_group_tags' => [['target_id' => $term->id()]],
    ]);
    $untaggedNode = $this->addNode($group, 'page', ['title' => 'No tags']);

    $flagService = $this->container->get('flag');
    $flag = Flag::load('follow_term');
    $this->assertNotNull($flag, 'The follow_term flag is installed.');
    $flagService->flag($flag, $term, $viewer);

    $nids = $this->nidsInOrder($this->executeDemo('page_following'));
    $this->assertContains((int) $taggedNode->id(), $nids, 'A node tagged with a followed term appears via follow_term.');
    $this->assertNotContains((int) $untaggedNode->id(), $nids, 'An untagged node does not appear.');
  }

  /**
   * The three following branches OR together and dedupe a double match.
   *
   * Acceptance criterion 2's dedupe guarantee: a node matching MORE THAN ONE
   * follow branch (its author is followed AND it is itself directly
   * followed) must appear in the results exactly ONCE, not twice. Also
   * proves the OR: three independently-followed nodes (one per branch) all
   * appear together in a single query execution.
   */
  public function testFollowingScopeOrsAndDedupes(): void {
    $viewer = $this->createUser();
    $this->setCurrentUser($viewer);
    $group = $this->createGroup();
    $flagService = $this->container->get('flag');

    // Double-matched node: followed directly AND its author is followed.
    $doubleAuthor = $this->createUser();
    $doubleNode = $this->addNode($group, 'page', ['title' => 'Double match', 'uid' => $doubleAuthor->id()]);
    $flagService->flag(Flag::load('follow_content'), $doubleNode, $viewer);
    $flagService->flag(Flag::load('follow_user'), $doubleAuthor, $viewer);

    // Single-matched node via follow_term only.
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->create([
      'vid' => 'group_tags',
      'name' => 'Only Term Match',
    ]);
    $term->save();
    $termNode = $this->addNode($group, 'page', [
      'title' => 'Term match only',
      'field_group_tags' => [['target_id' => $term->id()]],
    ]);
    $flagService->flag(Flag::load('follow_term'), $term, $viewer);

    // Unrelated node matching no branch.
    $this->addNode($group, 'page', ['title' => 'Matches nothing']);

    $rows = $this->executeDemo('page_following');
    $nids = $this->nidsInOrder($rows);

    $this->assertSame(
      $nids,
      array_values(array_unique($nids)),
      'No node id is duplicated even though the double-match node satisfies two OR branches.'
    );
    $this->assertContains((int) $doubleNode->id(), $nids, 'The doubly-matched node appears (at least once).');
    $this->assertContains((int) $termNode->id(), $nids, 'The follow_term-only node also appears (the OR includes it).');
    $this->assertCount(
      1,
      array_filter($nids, static fn ($nid) => $nid === (int) $doubleNode->id()),
      'The doubly-matched node appears EXACTLY once, not twice.'
    );
  }

}
