<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\do_group_membership\GroupMembershipManager;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipTypeInterface;

/**
 * Entity-create access gate for `group_membership` relationships (#121 SC-2).
 *
 * Implements `hook_group_relationship_create_access()` — confirmed against
 * `web/core/lib/Drupal/Core/Entity/EntityAccessControlHandler::createAccess()`
 * (per brief-response-v2 §A-W2: F verifies the exact hook signature at
 * implementation time). Core's `createAccess()` invokes BOTH
 * `hook_entity_create_access()` and `hook_{entity_type_id}_create_access()`
 * — for the `group_relationship` entity type, the type-specific hook is
 * `hook_group_relationship_create_access(AccountInterface $account, array
 * $context, $entity_bundle): AccessResultInterface`, where `$context` carries
 * `'group' => GroupInterface` (see
 * `GroupRelationshipAccessControlHandler::checkCreateAccess()`, which reads
 * `$context['group']` the same way).
 *
 * `AccessResult::orIf()` semantics (core's merge across all hook results,
 * then `orIf()` against the plugin's own default permission check) mean
 * "forbidden wins over allowed; neutral loses to either" — a `forbidden()`
 * result here SHORT-CIRCUITS the group_membership relation plugin's own
 * default `join group`-permission check
 * (`GroupMembershipAccessControl`/`AccessControlTrait::relationshipCreateAccess()`),
 * while a `neutral()` result here falls through to that default check
 * unchanged. This is why only the `invite_only` branch needs to actively
 * deny: `open` and `moderated` both already pass the default `join group`
 * permission grant (held by the `outsider_view` role) on their own, so a
 * `neutral()` there is correct — this hook exists specifically to CLOSE the
 * `invite_only` gap that permission grant would otherwise leave open, not
 * to duplicate the `open`/`moderated` allow path.
 *
 * Per brief §Addendum-1 / v2 §"Files to touch": no existing group_access
 * hook exists in `do_group_membership` — this is the first, justified new
 * hook class in this module (survey.md "NEW (justified)" #3).
 */
class GroupAccessHook {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly GroupMembershipManager $manager,
  ) {}

  /**
   * Gates `group_membership` relationship creation per join policy.
   *
   * - `plugin_id !== 'group_membership'` -> neutral (don't interfere with
   *   any other relation plugin's create access, e.g. `group_node:*`).
   * - visibility `open` -> neutral (allow via the existing `join group`
   *   permission grant — see class docblock).
   * - visibility `moderated` -> allowed (permits the pending
   *   request-to-join relationship creation, AC-2).
   * - visibility `invite_only` -> forbidden for everyone EXCEPT holders of
   *   `administer members` on this group (an organizer using the existing
   *   `AddMemberForm` must still be able to add members to an invite_only
   *   group — AC-3's "organizer's AddMember still works").
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account attempting to create the relationship.
   * @param array $context
   *   The create-access context; `$context['group']` carries the target
   *   \Drupal\group\Entity\GroupInterface.
   * @param string|null $entity_bundle
   *   The `group_relationship_type` config entity id (e.g.
   *   `community_group-group_membership`).
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  #[Hook('group_relationship_create_access')]
  public function groupRelationshipCreateAccess(AccountInterface $account, array $context, ?string $entity_bundle): AccessResultInterface {
    $group = $context['group'] ?? NULL;
    if (!$group instanceof GroupInterface || $entity_bundle === NULL) {
      return AccessResult::neutral();
    }

    if (!$this->isGroupMembershipBundle($entity_bundle)) {
      return AccessResult::neutral();
    }

    if ($group->hasPermission('administer members', $account)) {
      // Organizers/Moderators bypass every visibility gate — the existing
      // AddMemberForm path (AC-3's "organizer's AddMember still works") is
      // never blocked by this hook, on any visibility value.
      return AccessResult::neutral()->addCacheableDependency($group)->cachePerPermissions()->cachePerUser();
    }

    $policy = $this->manager->joinPolicyFor($group);

    return match ($policy) {
      'request' => AccessResult::allowed()->addCacheableDependency($group)->cachePerPermissions()->cachePerUser(),
      'invite' => AccessResult::forbidden('This group is invite-only; only an organizer can add members.')->addCacheableDependency($group)->cachePerPermissions()->cachePerUser(),
      default => AccessResult::neutral()->addCacheableDependency($group)->cachePerPermissions()->cachePerUser(),
    };
  }

  /**
   * Checks whether a relationship-type id is the `group_membership` plugin.
   *
   * @param string $relationship_type_id
   *   The `group_relationship_type` config entity id.
   *
   * @return bool
   *   TRUE if this relationship type's plugin id is `group_membership`.
   */
  private function isGroupMembershipBundle(string $relationship_type_id): bool {
    $relationship_type = $this->entityTypeManager
      ->getStorage('group_relationship_type')
      ->load($relationship_type_id);

    return $relationship_type instanceof GroupRelationshipTypeInterface
      && $relationship_type->getPluginId() === 'group_membership';
  }

}
