<?php

declare(strict_types=1);

namespace Drupal\Tests\do_multigroup\Functional;

use Drupal\do_multigroup\Hook\DoMultigroupHooks;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\Tests\group\Traits\NodeTypeCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for the do_multigroup cross-post NODE FORM path.
 *
 * Issue #68, epic #31. The #39 (C1) kernel test exercises only the
 * {@see \Drupal\group\Entity\GroupRelationship}::create() API path (which
 * works). This test drives the real Group 4.x group-content node CREATE and
 * EDIT forms end to end and asserts the persisted `group_relationship` state:
 *
 * - CREATE with a second group ticked in the "Group Audience" fieldset relates
 *   the node to BOTH groups (the origin + the ticked one).
 * - EDIT changing the ticked set re-syncs to match — adding/removing to the
 *   selection WITHOUT dropping the origin unless it is explicitly un-ticked.
 * - A single-group CREATE still relates the node to exactly its origin group.
 *
 * The node form on Group 4.x is served by
 * {@see \Drupal\group\Entity\Controller\GroupRelationshipController::createForm()}
 * as an *entity* (node) create form enhanced by
 * {@see \Drupal\group\Form\CreateFormEnhancer}; the form object's entity is the
 * NODE, and the origin relationship is written by the enhancer's own submit
 * handler. do_multigroup's {@see DoMultigroupHooks::nodeFormSubmit()} must read
 * the "Group Audience" selection from the submitted form state and apply it
 * against that node entity for every ticked group.
 *
 * @group do_multigroup
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class CrossPostFormTest extends BrowserTestBase {

  use GroupTestTrait;
  use NodeTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field_ui',
    'do_multigroup',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The bundle exercised by this test.
   */
  protected const BUNDLE = 'post';

  /**
   * The community_group group type (the module hard-codes this id).
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * The group_relationship storage.
   *
   * @var \Drupal\Core\Entity\ContentEntityStorageInterface
   */
  protected $relStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->relStorage = $this->entityTypeManager->getStorage('group_relationship');

    // Do not use user 1.
    $this->createUser();

    // The module hard-codes 'community_group-*' relationship type ids, so the
    // group type MUST be 'community_group' for them to resolve.
    $this->createNodeType(['type' => self::BUNDLE, 'name' => 'Post']);
    $this->groupType = $this->createGroupType([
      'id' => 'community_group',
      'label' => 'Community Group',
      'creator_membership' => TRUE,
    ]);

    // Install the group_node:post relationship type with unlimited cardinality
    // (mirrors the assembled community_group config).
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rt_storage */
    $rt_storage = $this->entityTypeManager->getStorage('group_relationship_type');
    $rt_storage->save($rt_storage->createFromPlugin(
      $this->groupType,
      'group_node:' . self::BUNDLE,
      ['entity_cardinality' => 0],
    ));

    // Grant the community_group member role the permissions needed to reach and
    // submit the group-content node create/edit forms.
    $this->createGroupRole([
      'group_type' => 'community_group',
      'scope' => 'insider',
      'global_role' => 'authenticated',
      'permissions' => [
        'access group_node overview',
        'create group_node:' . self::BUNDLE . ' entity',
        'update any group_node:' . self::BUNDLE . ' entity',
        'view group_node:' . self::BUNDLE . ' entity',
      ],
    ]);
  }

  /**
   * Loads every group_relationship linking a node via the post relationship.
   *
   * @param int|string $node_id
   *   The related node id.
   *
   * @return int[]
   *   The group ids the node is related to, sorted ascending.
   */
  protected function relatedGids(int|string $node_id): array {
    $this->relStorage->resetCache();
    $relationships = $this->relStorage->loadByProperties([
      'type' => DoMultigroupHooks::relationshipTypeId(self::BUNDLE),
      'entity_id' => $node_id,
    ]);
    $gids = array_map(
      static fn ($r): int => (int) $r->getGroup()->id(),
      $relationships,
    );
    sort($gids);
    return $gids;
  }

  /**
   * Creates a community_group whose creator is the given account.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The creator (and, via creator_membership, a member).
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The saved group.
   */
  protected function createCommunityGroup($account): GroupInterface {
    $group = $this->createGroup([
      'type' => 'community_group',
      'label' => $this->randomMachineName(),
      'uid' => $account->id(),
    ]);
    // Ensure the account is a member (creator_membership normally handles this
    // on form save; add explicitly for the programmatic create()).
    if (!$group->getMember($account)) {
      $group->addMember($account);
    }
    return $group;
  }

  /**
   * Creates a member user in two community groups and logs them in.
   *
   * @return array
   *   [account, groupA, groupB].
   */
  protected function setUpMemberInTwoGroups(): array {
    $account = $this->createUser([
      'access content',
      'access group overview',
    ]);
    $group_a = $this->createCommunityGroup($account);
    $group_b = $this->createCommunityGroup($account);
    $this->drupalLogin($account);
    return [$account, $group_a, $group_b];
  }

  /**
   * The "Group Audience" checkbox name for a given group id.
   */
  protected function audienceField(int|string $gid): string {
    return "group_ids[$gid]";
  }

  /**
   * CREATE with a second group ticked relates the node to BOTH groups.
   */
  public function testCreateCrossPostsToTickedGroups(): void {
    [, $group_a, $group_b] = $this->setUpMemberInTwoGroups();

    // Go to the group-content node create form for group A (the origin).
    $this->drupalGet("group/{$group_a->id()}/content/create/group_node:" . self::BUNDLE);
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);

    // The Group Audience fieldset lists both groups; the origin is pre-ticked.
    $assert->fieldExists($this->audienceField($group_a->id()));
    $assert->fieldExists($this->audienceField($group_b->id()));
    $assert->checkboxChecked($this->audienceField($group_a->id()));

    // Tick the SECOND group and submit.
    $title = $this->randomMachineName();
    $this->submitForm([
      'title[0][value]' => $title,
      $this->audienceField($group_b->id()) => (string) $group_b->id(),
    ], 'Save');

    // The node was created and is related to BOTH groups.
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['title' => $title]);
    $this->assertCount(1, $nodes, 'The node was created.');
    $node = reset($nodes);

    $this->assertSame(
      [(int) $group_a->id(), (int) $group_b->id()],
      $this->relatedGids($node->id()),
      'A create with a second group ticked cross-posts the node to BOTH groups.',
    );
  }

  /**
   * A single-group CREATE relates the node to exactly its origin group.
   */
  public function testCreateSingleGroupStillWorks(): void {
    [, $group_a, $group_b] = $this->setUpMemberInTwoGroups();

    $this->drupalGet("group/{$group_a->id()}/content/create/group_node:" . self::BUNDLE);
    $this->assertSession()->statusCodeEquals(200);

    // Leave only the origin ticked; explicitly ensure group B stays un-ticked.
    $title = $this->randomMachineName();
    $this->submitForm([
      'title[0][value]' => $title,
      $this->audienceField($group_b->id()) => FALSE,
    ], 'Save');

    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['title' => $title]);
    $this->assertCount(1, $nodes, 'The node was created.');
    $node = reset($nodes);

    $this->assertSame(
      [(int) $group_a->id()],
      $this->relatedGids($node->id()),
      'A single-group create relates the node to exactly its origin group.',
    );
  }

  /**
   * EDIT re-syncs the set and does NOT drop the origin unless un-ticked.
   */
  public function testEditReSyncsWithoutDroppingOrigin(): void {
    [, $group_a, $group_b] = $this->setUpMemberInTwoGroups();

    // Create the node in group A (origin) cross-posted to group B as well.
    $this->drupalGet("group/{$group_a->id()}/content/create/group_node:" . self::BUNDLE);
    $title = $this->randomMachineName();
    $this->submitForm([
      'title[0][value]' => $title,
      $this->audienceField($group_b->id()) => (string) $group_b->id(),
    ], 'Save');

    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['title' => $title]);
    $node = reset($nodes);
    $this->assertSame(
      [(int) $group_a->id(), (int) $group_b->id()],
      $this->relatedGids($node->id()),
      'Precondition: the node is in both groups after create.',
    );

    // Add a THIRD group and edit: un-tick group B, keep origin A, add group C.
    $group_c = $this->createCommunityGroup($this->loggedInUser);

    $this->drupalGet("node/{$node->id()}/edit");
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    // Existing relationships pre-tick their checkboxes.
    $assert->checkboxChecked($this->audienceField($group_a->id()));
    $assert->checkboxChecked($this->audienceField($group_b->id()));

    $this->submitForm([
      // Origin A stays ticked (not touched here means it keeps its default);
      // set it explicitly to be unambiguous.
      $this->audienceField($group_a->id()) => (string) $group_a->id(),
      $this->audienceField($group_b->id()) => FALSE,
      $this->audienceField($group_c->id()) => (string) $group_c->id(),
    ], 'Save');

    // The set now matches {A, C}: origin kept, B removed, C added.
    $this->assertSame(
      [(int) $group_a->id(), (int) $group_c->id()],
      $this->relatedGids($node->id()),
      'Edit re-syncs to the ticked set: origin kept, de-selected removed, new added.',
    );
  }

  /**
   * EDIT that un-ticks the origin DOES remove it (explicit un-tick honored).
   */
  public function testEditUntickingOriginRemovesIt(): void {
    [, $group_a, $group_b] = $this->setUpMemberInTwoGroups();

    $this->drupalGet("group/{$group_a->id()}/content/create/group_node:" . self::BUNDLE);
    $title = $this->randomMachineName();
    $this->submitForm([
      'title[0][value]' => $title,
      $this->audienceField($group_b->id()) => (string) $group_b->id(),
    ], 'Save');

    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['title' => $title]);
    $node = reset($nodes);

    // Edit: explicitly un-tick the origin A, keep B.
    $this->drupalGet("node/{$node->id()}/edit");
    $this->submitForm([
      $this->audienceField($group_a->id()) => FALSE,
      $this->audienceField($group_b->id()) => (string) $group_b->id(),
    ], 'Save');

    $this->assertSame(
      [(int) $group_b->id()],
      $this->relatedGids($node->id()),
      'Explicitly un-ticking the origin removes only that relationship.',
    );
  }

}
