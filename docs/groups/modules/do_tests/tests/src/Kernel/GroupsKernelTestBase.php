<?php

declare(strict_types=1);

namespace Drupal\Tests\do_tests\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\group\Traits\GroupTestTrait;
use Drupal\Tests\group\Traits\NodeTypeCreationTrait;

/**
 * Behavioral kernel test base for the Groups-on-D11 integration suite.
 *
 * This is the A3 (#34) foundation the config-existence Phase tests lacked. It
 * boots a live DB with Group 4.x + gnode, installs the entity schemas and the
 * `group` config, and reconstructs the assembled `community_group` group type
 * (plus its `group_node:*` and `group_membership` relationship types) using the
 * canonical 4.x storage APIs — never by reading `config/sync` YAML.
 *
 * It reuses Group's own {@see GroupTestTrait} (`createGroup`, `createGroupType`,
 * `createGroupRole`) and {@see NodeTypeCreationTrait} rather than reinventing
 * them, and adds the three fixtures from TEST_PLAN §3.3 that are specific to the
 * `community_group` shape: {@see self::createGroup()} (overridden to default to
 * the community_group type), {@see self::addMember()}, {@see self::addNode()}.
 *
 * @group do_tests
 */
abstract class GroupsKernelTestBase extends EntityKernelTestBase {

  use GroupTestTrait {
    // Alias the generic trait creator so our community_group-defaulting
    // createGroup() can delegate to it (a trait method has no parent::).
    createGroup as protected createGenericGroup;
  }
  use NodeTypeCreationTrait;

  /**
   * The group type id mirrored from the assembled config.
   */
  protected const GROUP_TYPE_ID = 'community_group';

  /**
   * Node bundles related to community_group via group_node in the real config.
   *
   * Keyed by relationship-type id suffix => node type machine name. Note the
   * `doc` id alias maps to the `documentation` node type (see the assembled
   * `group.relationship_type.community_group-group_node-doc.yml`).
   *
   * @var array<string, string>
   */
  protected const NODE_BUNDLES = [
    'forum' => 'forum',
    'doc' => 'documentation',
    'event' => 'event',
    'post' => 'post',
    'page' => 'page',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['group', 'gnode', 'options', 'node'];

  /**
   * The community_group group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('group');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('group_config_wrapper');
    $this->installConfig(['group']);

    // Do not use user 1; establish a non-privileged current user.
    $this->createUser();
    $this->setCurrentUser($this->createUser());

    // Reconstruct the assembled community_group group type + relationship types
    // via the 4.x storage APIs (mirrors config/sync, installed not read).
    $this->groupType = $this->createGroupType([
      'id' => static::GROUP_TYPE_ID,
      'label' => 'Community Group',
      'creator_membership' => TRUE,
    ]);

    // The `group_membership` relationship type is *enforced* — GroupType::postSave()
    // installs it automatically on group-type creation, so we do not (and must
    // not) create it here. It is the API path exercised by addMember().
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $rt_storage */
    $rt_storage = $this->entityTypeManager->getStorage('group_relationship_type');
    assert($rt_storage instanceof GroupRelationshipTypeStorageInterface);

    // Node relationship types: create each node type, then its group_node
    // plugin instance (group_node:<bundle>), with unlimited cardinality to
    // mirror the assembled plugin_config.
    foreach (static::NODE_BUNDLES as $node_type) {
      if (!NodeType::load($node_type)) {
        $this->createNodeType(['type' => $node_type, 'name' => ucfirst($node_type)]);
      }
      $rt_storage->save($rt_storage->createFromPlugin(
        $this->groupType,
        'group_node:' . $node_type,
        ['entity_cardinality' => 0]
      ));
    }
  }

  /**
   * Creates a saved community_group group.
   *
   * Overrides {@see GroupTestTrait::createGroup()} to default to the
   * community_group type. Callers may override any value (including `type`).
   *
   * @param array $values
   *   (optional) Values passed to the group storage create().
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The saved group.
   */
  protected function createGroup(array $values = []): GroupInterface {
    return $this->createGenericGroup($values + [
      'type' => static::GROUP_TYPE_ID,
      'label' => $this->randomMachineName(),
    ]);
  }

  /**
   * Adds a member to a group via the API membership path.
   *
   * This is the programmatic `Group::addMember()` path — distinct from the
   * form-only creator auto-membership behavior (CR 2026-04-24 / RA2), so a
   * later test can contrast the two.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to add the member to.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to make a member.
   * @param array $roles
   *   (optional) Group role ids to grant the membership.
   */
  protected function addMember(GroupInterface $group, AccountInterface $account, array $roles = []): void {
    $values = $roles ? ['group_roles' => $roles] : [];
    $group->addMember($account, $values);
  }

  /**
   * Adds a node to a group via the v4 create-relationship path.
   *
   * Creates a node of the given bundle and relates it to the group through its
   * `group_node:<bundle>` relationship (RA3/RA4/RA5 cells).
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to relate the node to.
   * @param string $bundle
   *   The node type machine name (e.g. 'event', 'page').
   * @param array $values
   *   (optional) Values for the node create() (e.g. 'title', 'uid').
   *
   * @return \Drupal\node\NodeInterface
   *   The saved node (its group_node relationship is also saved).
   */
  protected function addNode(GroupInterface $group, string $bundle, array $values = []): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create($values + [
      'type' => $bundle,
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();
    $group->addRelationship($node, 'group_node:' . $bundle);
    return $node;
  }

  /**
   * Returns the installed relationship-type id for a group_node bundle.
   *
   * The 4.x storage derives the id from the plugin id, so a node type maps to
   * `community_group-group_node-<node_type>` (e.g. `documentation`, not the
   * assembled `doc` alias, which is a cosmetic override we do not reproduce).
   *
   * @param string $node_type
   *   The node type machine name (e.g. 'documentation', 'event').
   *
   * @return string
   *   The derived group_relationship_type config entity id.
   */
  protected function relationshipTypeId(string $node_type): string {
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $storage */
    $storage = $this->entityTypeManager->getStorage('group_relationship_type');
    return $storage->getRelationshipTypeId(static::GROUP_TYPE_ID, 'group_node:' . $node_type);
  }

  /**
   * Returns the group_node relationship linking a node to a group, if any.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group.
   * @param \Drupal\node\NodeInterface $node
   *   The node.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface|null
   *   The relationship, or NULL if the node is not in the group.
   */
  protected function getNodeRelationship(GroupInterface $group, NodeInterface $node): ?GroupRelationshipInterface {
    $relationships = $group->getRelationshipsByEntity($node, 'group_node:' . $node->bundle());
    return $relationships ? reset($relationships) : NULL;
  }

  /**
   * Gets the current user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The current user.
   */
  protected function getCurrentUser(): AccountInterface {
    return $this->container->get('current_user')->getAccount();
  }

}
