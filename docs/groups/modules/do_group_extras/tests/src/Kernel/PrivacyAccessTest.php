<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_extras\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\PermissionScopeInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * #134 SC-7 — Private groups (view-access axis).
 *
 * RED (Phase 4, authored by T before F implements). Kernel-level coverage of
 * `field_group_privacy` (AC-1), the group-level and node-level view-access
 * gates for private groups (AC-2/AC-4 kernel halves), the #121 join-policy
 * regression guard (AC-6), and A's Phase-3 advisory #2 (cache invalidation
 * on join). Mirrors the fixture-provisioning shape of
 * {@see \Drupal\Tests\do_group_membership\Kernel\RequestJoinFlowTest} and the
 * hook-object pattern of
 * {@see \Drupal\Tests\do_group_extras\Kernel\GroupExtrasBehaviorTest}.
 *
 * This suite asserts on BEHAVIOR through the public entity-access API
 * (`$group->access('view', $account, TRUE)` and
 * `$node->access('view', $account, TRUE)`), not on a specific hook name or
 * class-internal method signature — deliberately, per the same rationale
 * RequestJoinFlowTest documents (brief-response-v2 §A-W2 analogue): F is free
 * to implement `hook_group_access` and extend `DoGroupExtrasHooks::nodeAccess()`
 * however is architecturally sound, so long as the observable access result is
 * correct. `isForbidden()` is asserted specifically (not merely `!isAllowed()`)
 * so a NEUTRAL default (the state before F writes any code) does not make an
 * assertion trivially pass.
 *
 * `field_group_privacy` is installed here via the storage API (not read from
 * `config/sync`), matching this test base's established convention.
 *
 * @group do_group_extras
 * @group group
 */
#[RunTestsInSeparateProcesses]
class PrivacyAccessTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'do_group_extras',
    'do_group_membership',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Install the node_access schema; node-access checks (AC-4) query it
    // during $node->access('view', ...) and fail with 'no such table:
    // node_access' without this. Deliberately do NOT call
    // node_access_rebuild(): with no grant-realm module installed in this
    // kernel harness, a rebuild writes a fallback-deny row that makes
    // even PUBLIC-group nodes forbidden for non-admin accounts, which
    // breaks the AC-4 negative case
    // (testNonMemberNotForbiddenFromViewingNodeInPublicGroup). An empty
    // node_access table lets Drupal fall back to its built-in "no module
    // implements grants -> allow" path, which is exactly what that
    // negative-case assertion expects.
    $this->installSchema('node', ['node_access']);

    $this->createGroupRole([
      'id' => 'community_group-member',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::INDIVIDUAL_ID,
      'admin' => FALSE,
      'permissions' => ['view group', 'view group_node:post entity'],
    ]);
    // Baseline outsider grant mirrors the real seeded
    // group.role.community_group-outsider_view.yml — outsiders (including
    // anonymous) can view PUBLIC/UNLISTED groups by default; the private-group
    // gate under test must override this default for `private` groups only.
    $this->createGroupRole([
      'id' => 'community_group-outsider_view',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'authenticated',
      'admin' => FALSE,
      'permissions' => ['view group', 'view group_node:post entity'],
    ]);
    $this->createGroupRole([
      'id' => 'community_group-anon_view',
      'group_type' => static::GROUP_TYPE_ID,
      'scope' => PermissionScopeInterface::OUTSIDER_ID,
      'global_role' => 'anonymous',
      'admin' => FALSE,
      'permissions' => ['view group', 'view group_node:post entity'],
    ]);

    // AC-1: field_group_privacy storage + field config, self-installed the
    // same way field_group_visibility is self-installed in the sibling
    // #121 Kernel suite (RequestJoinFlowTest::createGroupWithVisibility()).
    FieldStorageConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'public' => 'Public',
          'unlisted' => 'Unlisted',
          'private' => 'Private',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_privacy',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Privacy',
      'default_value' => [['value' => 'public']],
    ])->save();
  }

  /**
   * Creates a saved group with the given `field_group_privacy` value.
   *
   * @param string $privacy
   *   One of 'public' | 'unlisted' | 'private'.
   * @param string $label
   *   The group label.
   *
   * @return \Drupal\group\Entity\GroupInterface
   *   The saved group.
   */
  protected function createGroupWithPrivacy(string $privacy, string $label = ''): GroupInterface {
    $group = $this->createGroup([
      'label' => $label ?: $this->randomMachineName(),
    ]);
    $group->set('field_group_privacy', $privacy);
    $group->save();
    return $group;
  }

  /**
   * AC-1: field_group_privacy storage config exists with the correct
   * allowed_values.
   */
  public function testFieldStorageExistsWithAllowedValues(): void {
    $storage = FieldStorageConfig::load('group.field_group_privacy');
    $this->assertNotNull($storage, 'field_group_privacy storage config exists on the group entity type.');
    $this->assertSame('list_string', $storage->getType());

    $allowed_values = $storage->getSetting('allowed_values');
    $this->assertSame(
      ['public', 'unlisted', 'private'],
      array_keys($allowed_values),
      'allowed_values are exactly public/unlisted/private, in that order.',
    );
  }

  /**
   * AC-1: field_group_privacy field config exists on community_group with a
   * default value of 'public'.
   */
  public function testFieldConfigExistsWithPublicDefault(): void {
    $field = FieldConfig::loadByName('group', static::GROUP_TYPE_ID, 'field_group_privacy');
    $this->assertNotNull($field, 'field_group_privacy field config exists on community_group.');

    $default = $field->getDefaultValueLiteral();
    $this->assertNotEmpty($default, 'field_group_privacy has a configured default value.');
    $this->assertSame('public', $default[0]['value'], "The field's default value is 'public'.");

    // A freshly-created group with no explicit value takes the default.
    $group = $this->createGroup(['label' => 'Defaults Test Group']);
    $this->assertSame('public', $group->get('field_group_privacy')->value, 'A new group defaults to public privacy.');
  }

  /**
   * AC-2 (kernel half): a non-member is FORBIDDEN from viewing a private
   * group.
   */
  public function testNonMemberForbiddenFromViewingPrivateGroup(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team');
    $outsider = $this->createUser();

    $access = $group->access('view', $outsider, TRUE);
    $this->assertInstanceOf(AccessResultInterface::class, $access);
    $this->assertTrue($access->isForbidden(), 'A non-member is forbidden (not merely un-allowed) from viewing a private group.');
  }

  /**
   * AC-2 (kernel half): anonymous is FORBIDDEN from viewing a private group.
   */
  public function testAnonymousForbiddenFromViewingPrivateGroup(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team Anon');
    $anonymous = $this->createUser([], NULL, FALSE, ['uid' => 0]);

    $access = $group->access('view', $anonymous, TRUE);
    $this->assertTrue($access->isForbidden(), 'Anonymous is forbidden from viewing a private group.');
  }

  /**
   * AC-2 (kernel half): a MEMBER of a private group is allowed/neutral (not
   * forbidden) to view it.
   */
  public function testMemberNotForbiddenFromViewingPrivateGroup(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team Member');
    $member = $this->createUser();
    $group->addMember($member, ['group_roles' => ['community_group-member']]);

    $access = $group->access('view', $member, TRUE);
    $this->assertFalse($access->isForbidden(), 'A member is never forbidden from viewing their own private group.');
  }

  /**
   * Negative: anonymous is NOT forbidden from viewing a PUBLIC group — the
   * private-group gate must not over-apply to other privacy values.
   */
  public function testAnonymousNotForbiddenFromViewingPublicGroup(): void {
    $group = $this->createGroupWithPrivacy('public', 'Drupal NorCal');
    $anonymous = $this->createUser([], NULL, FALSE, ['uid' => 0]);

    $access = $group->access('view', $anonymous, TRUE);
    $this->assertFalse($access->isForbidden(), 'Anonymous is not forbidden from viewing a public group.');
  }

  /**
   * Negative: anonymous is NOT forbidden from viewing an UNLISTED group — per
   * the brief/wireframe, `unlisted` only affects directory listing (not
   * enforced this story), never view access.
   */
  public function testAnonymousNotForbiddenFromViewingUnlistedGroup(): void {
    $group = $this->createGroupWithPrivacy('unlisted', 'Quiet Roundtable');
    $anonymous = $this->createUser([], NULL, FALSE, ['uid' => 0]);

    $access = $group->access('view', $anonymous, TRUE);
    $this->assertFalse($access->isForbidden(), 'Anonymous is not forbidden from viewing an unlisted group (directory-hide only, not view-gated).');
  }

  /**
   * AC-4 (kernel half): a non-member is FORBIDDEN from viewing a node inside
   * a private group (content hidden from streams/search).
   */
  public function testNonMemberForbiddenFromViewingNodeInPrivateGroup(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team Node');
    $node = $this->addNode($group, 'post', ['title' => 'Coordinated disclosure process']);
    $outsider = $this->createUser();

    $access = $node->access('view', $outsider, TRUE);
    $this->assertTrue($access->isForbidden(), 'A non-member is forbidden from viewing a node inside a private group.');
  }

  /**
   * AC-4 (kernel half): a MEMBER of the private group is NEUTRAL (not
   * forbidden) when viewing that group's node — the gate does not itself
   * grant access, it only removes it for non-members.
   */
  public function testMemberNotForbiddenFromViewingNodeInPrivateGroup(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team Node Member');
    $node = $this->addNode($group, 'post', ['title' => 'Q3 advisory review']);
    $member = $this->createUser();
    $group->addMember($member, ['group_roles' => ['community_group-member']]);

    $access = $node->access('view', $member, TRUE);
    $this->assertFalse($access->isForbidden(), 'A member is not forbidden from viewing content in their own private group.');
  }

  /**
   * Negative: a node inside a PUBLIC group is not forbidden for a
   * non-member/anonymous — the node-hide gate must not over-apply.
   */
  public function testNonMemberNotForbiddenFromViewingNodeInPublicGroup(): void {
    $group = $this->createGroupWithPrivacy('public', 'Public Node Group');
    $node = $this->addNode($group, 'post', ['title' => 'Public discussion']);
    $outsider = $this->createUser();

    $access = $node->access('view', $outsider, TRUE);
    $this->assertFalse($access->isForbidden(), 'A non-member is not forbidden from viewing content in a public group.');
  }

  /**
   * AC-6 (regression, #121): `GroupMembershipManager::joinPolicyFor()` is
   * UNCHANGED by this story's introduction of `field_group_privacy` — it
   * still classifies all three existing `field_group_visibility` values
   * correctly. `field_group_visibility` is installed here the same way the
   * #121 Kernel suite installs it (RequestJoinFlowTest::createGroupWithVisibility()),
   * since this story must not depend on that module's fixtures.
   */
  public function testJoinPolicyForRegressionAcrossAllThreeVisibilityValues(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_group_visibility',
      'entity_type' => 'group',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'open' => 'Open',
          'moderated' => 'Moderated',
          'invite_only' => 'Invite Only',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_visibility',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Visibility',
    ])->save();
    $this->entityTypeManager->clearCachedDefinitions();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

    /** @var \Drupal\do_group_membership\GroupMembershipManager $manager */
    $manager = $this->container->get('do_group_membership.manager');
    $this->assertInstanceOf(GroupMembershipManager::class, $manager);

    $open = $this->createGroup(['label' => 'Open Regression Group']);
    $open->set('field_group_visibility', 'open');
    $open->save();
    $moderated = $this->createGroup(['label' => 'Moderated Regression Group']);
    $moderated->set('field_group_visibility', 'moderated');
    $moderated->save();
    $invite_only = $this->createGroup(['label' => 'Invite Regression Group']);
    $invite_only->set('field_group_visibility', 'invite_only');
    $invite_only->save();

    $this->assertSame('open', $manager->joinPolicyFor($open), '#134 must not change the "open" classification.');
    $this->assertSame('request', $manager->joinPolicyFor($moderated), '#134 must not change the "moderated" classification.');
    $this->assertSame('invite', $manager->joinPolicyFor($invite_only), '#134 must not change the "invite_only" classification.');
  }

  /**
   * A advisory #2 (Phase 3, cache invalidation on join/leave): after
   * `$group->addMember()`, a FRESH `access('view')` call for that same
   * account returns allowed — no stale-cache leak from the earlier forbidden
   * result. Mirrors core's own pattern of resetting the access-result cache
   * between two access() calls that must observe different underlying state
   * (see `EntityAccessControlHandler::resetCache()`).
   */
  public function testAccessResultDoesNotLeakStaleCacheAcrossMembershipChange(): void {
    $group = $this->createGroupWithPrivacy('private', 'Security Team Cache');
    $account = $this->createUser();

    // Prime the access-result cache with a FORBIDDEN result (non-member).
    $before = $group->access('view', $account, TRUE);
    $this->assertTrue($before->isForbidden(), 'Precondition: non-member is forbidden before joining.');

    $group->addMember($account, ['group_roles' => ['community_group-member']]);

    // Clear the entity/access-result caches the way core's own tests do
    // after a state change that access results are cacheable against —
    // access results must carry the group as a cacheable dependency so this
    // invalidation actually takes effect (A advisory #2's ask for F).
    \Drupal::entityTypeManager()->getAccessControlHandler('group')->resetCache();
    \Drupal::entityTypeManager()->getStorage('group')->resetCache([$group->id()]);

    $after = $group->access('view', $account, TRUE);
    $this->assertFalse($after->isForbidden(), 'A fresh access(\'view\') call after addMember() returns allowed — no stale-cache leak (A advisory #2).');
  }

}
