<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\do_group_extras\Hook\DoGroupExtrasHooks;
use Drupal\group\Entity\GroupInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_group_extras' Archive + moderation behavior.
 *
 * Issue #42 (Wave C / C4), epic #31. do_group_extras is NOT a thin module: it
 * owns two of the group section's 4.x-affected behaviors and this test pins
 * both against real installed state:
 *
 * 1. node_access DENY — {@see DoGroupExtrasHooks::nodeAccess()} forbids the
 *    `create` op when the current route's `group` parameter is an *Archive*
 *    group, and is neutral for any other op, for non-Archive groups, and when
 *    no group is on the route. "Archive" is identified by the referenced
 *    `field_group_type` taxonomy TERM's name being exactly `Archive` (the term
 *    entity's getName()), NOT a group subtype or a raw field value — so the
 *    fixtures here build a real taxonomy vocabulary + terms and reference them.
 *
 * 2. entity_presave MODERATION default — {@see DoGroupExtrasHooks::entityPresave()}
 *    forces a *new* group to `status = 0` (unpublished / pending review) unless
 *    the acting user holds `administer group` or `administer groups`, in which
 *    case the group keeps its published status. Only new groups are touched;
 *    an update never re-unpublishes.
 *
 * The node_access method reads the route match, which a kernel test cannot
 * populate through a real request, so the hook object is constructed directly
 * with a controllable route match — the deterministic way to exercise that
 * method's Archive branch. entity_presave is driven through the REAL enabled
 * hook + a real Group::save(), varying only the current user's permissions.
 *
 * @group do_group_extras
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupExtrasBehaviorTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * do_group_extras (module under test) + taxonomy (its field_group_type
   * dependency) + field, on top of the group/gnode/node base stack.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'taxonomy',
    'do_group_extras',
  ];

  /**
   * The taxonomy term representing an "Archive" group type.
   */
  protected Term $archiveTerm;

  /**
   * A taxonomy term representing an ordinary (non-Archive) group type.
   */
  protected Term $normalTerm;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);

    // A "group type" taxonomy vocabulary with an Archive term and a normal
    // term. The module identifies Archive by the TERM's name, so both terms
    // are real saved entities and only differ by name.
    Vocabulary::create(['vid' => 'group_type', 'name' => 'Group type'])->save();
    $this->archiveTerm = Term::create(['vid' => 'group_type', 'name' => 'Archive']);
    $this->archiveTerm->save();
    $this->normalTerm = Term::create(['vid' => 'group_type', 'name' => 'Working Group']);
    $this->normalTerm->save();

    // Attach field_group_type (taxonomy reference) to the community_group type.
    FieldStorageConfig::create([
      'field_name' => 'field_group_type',
      'entity_type' => 'group',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_type',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Group type',
    ])->save();
  }

  /**
   * Builds the hook object with a route match whose `group` param is $group.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The group to expose on the route, or NULL to simulate no group on route.
   */
  private function hooksWithRouteGroup(?GroupInterface $group): DoGroupExtrasHooks {
    $route_match = $this->createMock('Drupal\Core\Routing\RouteMatchInterface');
    $route_match->method('getParameter')
      ->with('group')
      ->willReturn($group);
    return new DoGroupExtrasHooks(
      $this->container->get('current_user'),
      $this->container->get('queue'),
      $route_match,
      $this->container->get('entity_type.manager'),
    );
  }

  /**
   * Creates a saved node (not yet in any group) for the access checks.
   */
  private function makeNode(): \Drupal\node\NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'post',
      'title' => $this->randomMachineName(),
      'uid' => $this->getCurrentUser()->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * node_access forbids `create` when the routed group is an Archive group.
   */
  public function testNodeAccessForbidsCreateInArchiveGroup(): void {
    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);
    $node = $this->makeNode();

    $result = $this->hooksWithRouteGroup($group)
      ->nodeAccess($node, 'create', $this->getCurrentUser());

    $this->assertInstanceOf(AccessResultForbidden::class, $result, 'create is forbidden in an Archive group.');
    $this->assertTrue($result->isForbidden());
  }

  /**
   * node_access forbids `create` for EVERY role in an Archive group.
   *
   * The Archive deny is unconditional — it does not consult group membership
   * or role, so it must hold for an anonymous account, an ordinary member, and
   * a group admin alike. Asserting across roles pins that there is no
   * permission-based bypass of the archive lock.
   */
  public function testNodeAccessForbidsCreateAcrossRolesInArchiveGroup(): void {
    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);
    $node = $this->makeNode();
    $hooks = $this->hooksWithRouteGroup($group);

    $anon = $this->createUser([], NULL, FALSE, ['uid' => 0]);
    $member = $this->createUser();
    $group->addMember($member);
    $siteAdmin = $this->createUser(['administer group']);

    foreach (['anonymous' => $anon, 'member' => $member, 'site admin' => $siteAdmin] as $label => $account) {
      $result = $hooks->nodeAccess($node, 'create', $account);
      $this->assertTrue($result->isForbidden(), "create is forbidden for the $label in an Archive group.");
    }
  }

  /**
   * node_access is NEUTRAL for `create` in a normal (non-Archive) group.
   */
  public function testNodeAccessNeutralInNormalGroup(): void {
    $group = $this->createGroup(['field_group_type' => $this->normalTerm->id()]);
    $node = $this->makeNode();

    $result = $this->hooksWithRouteGroup($group)
      ->nodeAccess($node, 'create', $this->getCurrentUser());

    $this->assertInstanceOf(AccessResultNeutral::class, $result, 'create is neutral in a non-Archive group.');
    $this->assertFalse($result->isForbidden());
  }

  /**
   * node_access is NEUTRAL for a non-`create` op even in an Archive group.
   */
  public function testNodeAccessNeutralForNonCreateOpInArchiveGroup(): void {
    $group = $this->createGroup(['field_group_type' => $this->archiveTerm->id()]);
    $node = $this->makeNode();
    $hooks = $this->hooksWithRouteGroup($group);

    foreach (['view', 'update', 'delete'] as $op) {
      $result = $hooks->nodeAccess($node, $op, $this->getCurrentUser());
      $this->assertInstanceOf(AccessResultNeutral::class, $result, "op '$op' is not governed by the archive lock.");
      $this->assertFalse($result->isForbidden());
    }
  }

  /**
   * node_access is NEUTRAL for `create` when no group is on the route.
   */
  public function testNodeAccessNeutralWithNoGroupOnRoute(): void {
    $node = $this->makeNode();

    $result = $this->hooksWithRouteGroup(NULL)
      ->nodeAccess($node, 'create', $this->getCurrentUser());

    $this->assertInstanceOf(AccessResultNeutral::class, $result);
    $this->assertFalse($result->isForbidden());
  }

  /**
   * entity_presave lands a new non-admin group at status = 0 (pending review).
   *
   * The base's current user is a non-privileged account, so the real enabled
   * entity_presave hook fires and forces the group unpublished at save time,
   * regardless of any published value requested.
   */
  public function testEntityPresaveUnpublishesNewGroupForNonAdmin(): void {
    $this->assertFalse(
      $this->getCurrentUser()->hasPermission('administer group'),
      'Precondition: the acting user is not a group administrator.',
    );

    $group = $this->createGroup(['status' => 1]);

    $this->assertFalse($group->isPublished(), 'A new group created by a non-admin is unpublished (pending review).');
    $this->assertSame(0, (int) $group->get('status')->value);
  }

  /**
   * entity_presave leaves a new group published for a user who may bypass.
   *
   * A user holding `administer group` (or `administer groups`) is exempt: the
   * hook returns early and the requested published status is preserved.
   */
  public function testEntityPresaveKeepsNewGroupPublishedForAdmin(): void {
    $this->setCurrentUser($this->createUser(['administer group']));
    $this->assertTrue($this->getCurrentUser()->hasPermission('administer group'));

    $group = $this->createGroup(['status' => 1]);

    $this->assertTrue($group->isPublished(), 'An admin-created group keeps its published status.');
    $this->assertSame(1, (int) $group->get('status')->value);
  }

  /**
   * entity_presave only touches NEW groups — an update never re-unpublishes.
   *
   * The hook guards on isNew(). A group that becomes published later (e.g. a
   * moderator approving it) must stay published across subsequent saves even
   * while a non-admin is the acting user.
   */
  public function testEntityPresaveDoesNotUnpublishExistingGroupOnUpdate(): void {
    // Create as admin so it starts published, then act as a non-admin.
    $this->setCurrentUser($this->createUser(['administer group']));
    $group = $this->createGroup(['status' => 1]);
    $this->assertTrue($group->isPublished());

    $this->setCurrentUser($this->createUser());
    $group->set('label', 'Renamed after approval');
    $group->save();

    $this->assertTrue($group->isPublished(), 'An existing published group is not re-unpublished on update.');
  }

}
