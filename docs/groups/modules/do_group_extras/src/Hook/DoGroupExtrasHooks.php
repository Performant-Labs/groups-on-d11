<?php

declare(strict_types=1);

namespace Drupal\do_group_extras\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\Storage\GroupRelationshipStorageInterface;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Hook implementations for do_group_extras.
 *
 * Provides archive enforcement, submission guidelines, moderation defaults,
 * and (#134 SC-7) the private-group view-access gate for community groups.
 */
class DoGroupExtrasHooks {

  /**
   * The `all_groups` directory view id (#134 A advisory #1 / F deliverable).
   */
  private const ALL_GROUPS_VIEW_ID = 'all_groups';

  /**
   * The `group_membership` relationship plugin id.
   */
  private const GROUP_MEMBERSHIP_PLUGIN_ID = 'group_membership';

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly QueueFactory $queueFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Adds submission guidelines to group forms.
   */
  #[Hook('form_alter')]
  public function formAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    if (str_contains($form_id, 'group_community_group')) {
      $form['submission_guidelines'] = [
        '#type' => 'details',
        '#title' => t('Submission Guidelines'),
        '#open' => TRUE,
        '#weight' => -100,
        '#markup' => t('<p>Please review community guidelines before creating a group. All new groups created by non-administrators require moderator approval before they become visible.</p>'),
      ];
    }
  }

  /**
   * Defaults new groups created by non-admins to unpublished (pending review).
   */
  #[Hook('entity_presave')]
  public function entityPresave(mixed $entity): void {
    if (!($entity instanceof GroupInterface) || !$entity->isNew()) {
      return;
    }
    if (
      $this->currentUser->hasPermission('administer group') ||
      $this->currentUser->hasPermission('administer groups')
    ) {
      return;
    }
    $entity->set('status', 0);
  }

  /**
   * Queues a moderator notification when a new group is pending approval.
   */
  #[Hook('entity_insert')]
  public function entityInsert(mixed $entity): void {
    if (!($entity instanceof GroupInterface) || $entity->isPublished()) {
      return;
    }
    $this->notifyModerators($entity);
  }

  /**
   * Adds the "Archived" class/library and the section libraries.
   *
   * Attaches the Links & Resources library (#140) and the About library
   * (#141) on the Full display when their respective fields are non-empty.
   */
  #[Hook('preprocess_group')]
  public function preprocessGroup(array &$variables): void {
    /** @var \Drupal\group\Entity\GroupInterface $group */
    $group = $variables['group'];

    // #140 W-2 / #141 A warn #6: attach the Links & Resources and About
    // section styling only on the Full (default) view mode, and only when
    // each field actually has values — avoids attaching either library on
    // empty-state pages or on other view modes (e.g. Teaser) where the
    // fields aren't rendered. One outer bundle/view-mode guard, two sibling
    // inner field-existence guards (do NOT split into separate outer
    // conditionals — see handoff-A-plan.md #141 warn #6).
    if (
      $group->bundle() === 'community_group'
      && ($variables['view_mode'] ?? '') === 'default'
    ) {
      if (
        $group->hasField('field_group_links')
        && !$group->get('field_group_links')->isEmpty()
      ) {
        $variables['#attached']['library'][] = 'do_group_extras/group-links';
      }

      if (
        $group->hasField('field_group_about')
        && !$group->get('field_group_about')->isEmpty()
      ) {
        $variables['#attached']['library'][] = 'do_group_extras/group-about';
      }
    }

    if (!$group->hasField('field_group_type')) {
      return;
    }
    $group_type_ref = $group->get('field_group_type')->entity;
    if ($group_type_ref && $group_type_ref->getName() === 'Archive') {
      $variables['attributes']['class'][] = 'group--archived';
      $variables['archived'] = TRUE;
      $variables['#attached']['library'][] = 'do_group_extras/do_group_extras';
    }
  }

  /**
   * Denies node creation in archived groups; denies viewing nodes hidden by
   * a private group (#134 SC-7).
   */
  #[Hook('node_access')]
  public function nodeAccess(
    NodeInterface $node,
    string $op,
    AccountInterface $account,
  ): AccessResult {
    if ($op === 'create') {
      $group = $this->routeMatch->getParameter('group');
      if (!($group instanceof GroupInterface) || !$group->hasField('field_group_type')) {
        return AccessResult::neutral();
      }
      $group_type_ref = $group->get('field_group_type')->entity;
      if ($group_type_ref && $group_type_ref->getName() === 'Archive') {
        return AccessResult::forbidden('This group is archived. No new content can be created.')
          ->addCacheableDependency($group);
      }
      return AccessResult::neutral();
    }

    if ($op === 'view') {
      // A node can relate to more than one group (cross-posting, #68); this
      // gate forbids viewing as soon as ANY owning group is private and the
      // account is not a member of that specific group. A node is hidden
      // from streams/search this way regardless of route context — unlike
      // the create-access branch above, this cannot rely on
      // routeMatch->getParameter('group') because content is viewed from
      // many non-group-scoped routes (/node/{n}, search, stream views).
      $result = AccessResult::neutral();
      foreach ($this->relationshipStorage()->loadByEntity($node) as $relationship) {
        $owning_group = $relationship->getGroup();
        if ($owning_group instanceof GroupInterface && $this->isPrivateForNonMember($owning_group, $account)) {
          return AccessResult::forbidden('This content lives in a private group. Only members can view it.')
            ->addCacheableDependency($owning_group)
            ->cachePerPermissions()
            ->cachePerUser();
        }
        if ($owning_group instanceof GroupInterface) {
          $result = $result->addCacheableDependency($owning_group);
        }
      }
      return $result;
    }

    return AccessResult::neutral();
  }

  /**
   * Forbids viewing a private group for anyone who is not a member (#134 SC-7).
   *
   * Every group stays viewable by default (via the seeded
   * outsider_view/anon_view roles' "view group" permission grant) — this
   * hook overrides that default ONLY when `field_group_privacy == 'private'`
   * and the account holds no membership relationship on the group. A
   * `forbidden()` result here short-circuits
   * `EntityAccessControlHandler::checkAccess()` (the permission-grant check)
   * per core's `access()` merge order — see
   * `Drupal\Core\Entity\EntityAccessControlHandler::access()`.
   *
   * `public`/`unlisted` groups are untouched (neutral) — only `private`
   * enforces a view-access gate this story (per the wireframe's honesty
   * notes: `unlisted` is directory-hide-only, not yet enforced).
   */
  #[Hook('group_access')]
  public function groupAccess(GroupInterface $group, string $op, AccountInterface $account): AccessResult {
    if ($op !== 'view' || $this->isPrivateForNonMember($group, $account) === FALSE) {
      return AccessResult::neutral();
    }
    return AccessResult::forbidden('This group is private. Only members can view it.')
      ->addCacheableDependency($group)
      ->cachePerPermissions()
      ->cachePerUser();
  }

  /**
   * Excludes private groups from the `/all-groups` directory for non-members
   * (#134 SC-7 AC-3 / A advisory #1).
   *
   * `hook_group_access`/`hook_node_access` gate the entity-access API (direct
   * canonical requests, `$entity->access()` calls), but Views' own listing
   * query does NOT consult those hooks — `Drupal\group\Hook\QueryHooks
   * ::viewsQueryAlter()` re-derives row visibility purely from the
   * PERMISSION-GRANT calculator (`GroupQueryAlter`), which has no concept of
   * `field_group_privacy` at all (confirmed by reading
   * `Drupal\group\QueryAccess\GroupQueryAlter::doAlter()` — it only inspects
   * calculated group-role permissions, never entity fields). Without this
   * hook, every seeded group — including a private one — passes that
   * permission-only filter equally, because the seeded outsider_view/
   * anon_view roles grant "view group" unconditionally. This is the same
   * class of gap A's Phase-3 advisory #1 anticipated (verify
   * `disable_sql_rewrite`, else add a scoped `hook_views_query_alter` in
   * `do_group_extras`) — `disable_sql_rewrite` itself is absent/false on
   * `all_groups.yml` (verified), so the remedy is this scoped query alter,
   * triggered by the permission-calculator gap rather than a SQL-rewrite
   * toggle.
   *
   * Scoped to the `all_groups` view ONLY (view-id guard, mirroring
   * `DoGroupPinHooks::viewsQueryAlter()`'s `STREAM_VIEW_ID` guard in the same
   * module family) — no other view's query is touched.
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, mixed $query): void {
    if ($view->id() !== self::ALL_GROUPS_VIEW_ID || !($query instanceof Sql)) {
      return;
    }

    $definition = [
      'type' => 'LEFT',
      'table' => 'group__field_group_privacy',
      'field' => 'entity_id',
      'left_table' => 'groups_field_data',
      'left_field' => 'id',
      'extra' => [
        ['field' => 'deleted', 'value' => 0],
      ],
    ];
    $join = \Drupal::service('plugin.manager.views.join')->createInstance('standard', $definition);
    $query->addRelationship('do_group_extras_privacy', $join, 'groups_field_data');

    // Exclude a row only when BOTH: (a) it is explicitly marked private, AND
    // (b) the current account has no group_membership relationship to that
    // specific group. Rows with no field_group_privacy value at all (a group
    // created before this field existed) are treated as public — matching
    // the field's own 'public' default_value, so an un-migrated legacy group
    // is never accidentally hidden.
    $query->addWhereExpression(
      0,
      "do_group_extras_privacy.field_group_privacy_value IS NULL "
      . "OR do_group_extras_privacy.field_group_privacy_value <> :do_group_extras_private "
      . "OR EXISTS ("
      . "SELECT 1 FROM {group_relationship_field_data} do_group_extras_membership "
      . "WHERE do_group_extras_membership.gid = groups_field_data.id "
      . "AND do_group_extras_membership.plugin_id = :do_group_extras_plugin_id "
      . "AND do_group_extras_membership.entity_id = :do_group_extras_uid"
      . ")",
      [
        ':do_group_extras_private' => 'private',
        ':do_group_extras_plugin_id' => self::GROUP_MEMBERSHIP_PLUGIN_ID,
        ':do_group_extras_uid' => $this->currentUser->id(),
      ],
    );
  }

  /**
   * Whether a group is private and the account holds no membership on it.
   *
   * Shared by {@see self::groupAccess()} and {@see self::nodeAccess()} to
   * avoid duplicating the "private AND non-member" predicate in two places
   * (#134 SC-7 brief instruction). Guards for the field's absence (a group
   * type/bundle that never got `field_group_privacy` attached) by returning
   * FALSE — neutral, never forbidden, when the axis does not apply.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group to check.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account attempting to view the group (or its content).
   *
   * @return bool
   *   TRUE if the group is `private` and $account is not one of its members.
   */
  private function isPrivateForNonMember(GroupInterface $group, AccountInterface $account): bool {
    if (!$group->hasField('field_group_privacy')) {
      return FALSE;
    }
    if ($group->get('field_group_privacy')->value !== 'private') {
      return FALSE;
    }
    return $group->getMember($account) === FALSE;
  }

  /**
   * Returns the group_relationship entity storage.
   */
  private function relationshipStorage(): GroupRelationshipStorageInterface {
    $storage = $this->entityTypeManager->getStorage('group_relationship');
    assert($storage instanceof GroupRelationshipStorageInterface);
    return $storage;
  }

  /**
   * Queues a pending-group notification for site_moderator users.
   *
   * Records to a queue only — actual email delivery is handled externally.
   * Logger uses \Drupal::logger() — acceptable for a private helper method.
   */
  private function notifyModerators(GroupInterface $group): void {
    $queue = $this->queueFactory->get('do_group_extras_pending_notification');
    $queue->createItem([
      'group_id' => $group->id(),
      'group_label' => $group->label(),
      'author_uid' => $group->getOwnerId(),
      'timestamp' => \Drupal::time()->getRequestTime(),
    ]);
    \Drupal::logger('do_group_extras')->notice(
      'Pending group notification queued for group %label (gid=%gid)',
      ['%label' => $group->label(), '%gid' => $group->id()],
    );
  }

}
