<?php

declare(strict_types=1);

namespace Drupal\do_multigroup\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Hook implementations for do_multigroup.
 *
 * Allows content to be posted in multiple groups simultaneously.
 */
class DoMultigroupHooks {

  /**
   * Content types that support multi-group posting.
   */
  public const CONTENT_TYPES = [
    'forum',
    'documentation',
    'event',
    'post',
    'page',
  ];

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Returns the relationship type ID for a given bundle.
   *
   * 'documentation' is abbreviated to 'doc' due to the 32-char ID limit.
   *
   * NOTE (Group 4.x): this returns a group_relationship *type* (config entity) ID
   * — e.g. 'community_group-group_node-forum' — which is generated per group type
   * and is UNCHANGED by the 4.x `content_plugin` → `relation_type` rename. That
   * rename only touched the entity *property* that stores the relation plugin ID on
   * the type; the type's own machine name / ID pattern is stable. So the IDs
   * hard-coded here carry over as-is. This module never reads the renamed property,
   * so no `content_plugin`/`relation_type` code change is required.
   */
  public static function relationshipTypeId(string $bundle): string {
    // TODO(group4-VERIFY): confirm the '<group_type>-group_node-<bundle>' relationship
    // type IDs (and the 'community_group-group_membership' ID below) still resolve on
    // the installed Group 4.x — relationship type IDs are generated per group type, so
    // verify against the site's actual group.relationship_type.* config after the
    // updatedb runs (they should be identical to 3.x; the rename was property-only).
    return 'community_group-group_node-' . ($bundle === 'documentation' ? 'doc' : $bundle);
  }

  /**
   * Returns all community_group memberships for the given account.
   *
   * Memberships are group_relationship entities where entity_id = uid and the type
   * is the group's membership relationship type. This behaviour is the same in
   * Group 3.x and 4.x: loadByProperties on group_relationship storage is not affected
   * by the 4.x deltas (no `$roles` filter is used here — this loads ALL of the
   * account's community_group memberships, so the "$roles filter must be an array"
   * CR does not apply).
   */
  private function getUserMemberships(AccountInterface $account): array {
    return $this->entityTypeManager
      ->getStorage('group_relationship')
      ->loadByProperties([
        'entity_id' => $account->id(),
        'type' => 'community_group-group_membership',
      ]);
  }

  /**
   * Adds a "Group Audience" fieldset to node create/edit forms.
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    $node = $form_state->getFormObject()->getEntity();
    $bundle = $node->bundle();

    if (!in_array($bundle, self::CONTENT_TYPES, TRUE)) {
      return;
    }

    $memberships = $this->getUserMemberships($this->currentUser);
    if (empty($memberships)) {
      return;
    }

    $options = [];
    $default_values = [];

    foreach ($memberships as $membership) {
      $group = $membership->getGroup();
      if (!$group || $group->bundle() !== 'community_group') {
        continue;
      }
      $gid = $group->id();
      $options[$gid] = $group->label();

      if (!$node->isNew()) {
        $existing = $this->entityTypeManager
          ->getStorage('group_relationship')
          ->loadByProperties([
            'type' => self::relationshipTypeId($bundle),
            'entity_id' => $node->id(),
            'gid' => $gid,
          ]);
        if (!empty($existing)) {
          $default_values[] = $gid;
        }
      }
    }

    if (empty($options)) {
      return;
    }

    $group_from_route = $this->routeMatch->getParameter('group');
    if ($group_from_route && isset($options[$group_from_route->id()])) {
      $default_values[] = $group_from_route->id();
    }

    $form['do_multigroup'] = [
      '#type' => 'details',
      '#title' => t('Group Audience'),
      '#open' => TRUE,
      '#weight' => 90,
      '#group' => 'advanced',
    ];
    $form['do_multigroup']['group_ids'] = [
      '#type' => 'checkboxes',
      '#title' => t('Post to groups'),
      '#options' => $options,
      '#default_value' => array_unique($default_values),
      '#description' => t('Select the groups this content should be posted to.'),
    ];

    $form['actions']['submit']['#submit'][] = [static::class, 'nodeFormSubmit'];
  }

  /**
   * Submit handler: syncs group_relationship entries after node save.
   *
   * Static so it can be serialised into form state.
   */
  public static function nodeFormSubmit(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $node = $form_state->getFormObject()->getEntity();
    $bundle = $node->bundle();

    if (!in_array($bundle, self::CONTENT_TYPES, TRUE)) {
      return;
    }

    $selected_gids = array_filter(
      $form_state->getValue(['do_multigroup', 'group_ids'], []),
    );
    $relationship_type_id = self::relationshipTypeId($bundle);
    $storage = \Drupal::entityTypeManager()->getStorage('group_relationship');

    $existing = $storage->loadByProperties([
      'type' => $relationship_type_id,
      'entity_id' => $node->id(),
    ]);

    $existing_gids = [];
    foreach ($existing as $relationship) {
      $existing_gids[$relationship->getGroup()->id()] = $relationship;
    }

    foreach ($selected_gids as $gid) {
      if (!isset($existing_gids[$gid])) {
        $group = \Drupal::entityTypeManager()->getStorage('group')->load($gid);
        if ($group) {
          // Group 4.x: creating the group_relationship directly (rather than
          // Group::addRelationship()) is unaffected by the "add entity to group no
          // longer resaves the entity" change (CR 2025-05-23) — this code never
          // relied on the node being resaved as a side effect. The create() array
          // keys used here (type/gid/entity_id) are the same v3/v4 group_relationship
          // fields.
          // TODO(group4-VERIFY): confirm group_relationship::create() did not gain a
          // new required field / changed key in 4.x (diff group/src/Entity/
          // GroupRelationship* 3.x↔4.x). The three keys below were valid in 3.x.
          $storage->create([
            'type' => $relationship_type_id,
            'gid' => $gid,
            'entity_id' => $node->id(),
          ])->save();
        }
      }
    }

    foreach ($existing_gids as $gid => $relationship) {
      if (!in_array($gid, $selected_gids, TRUE)) {
        $relationship->delete();
      }
    }
  }

  /**
   * Adds "Posted in" and "Cross-posted" badges to nodes.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $variables['node'];
    $bundle = $node->bundle();

    if (!in_array($bundle, self::CONTENT_TYPES, TRUE)) {
      return;
    }

    $relationships = $this->entityTypeManager
      ->getStorage('group_relationship')
      ->loadByProperties([
        'type' => self::relationshipTypeId($bundle),
        'entity_id' => $node->id(),
      ]);

    if (empty($relationships)) {
      return;
    }

    $groups = [];
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if ($group) {
        $groups[] = [
          'label' => $group->label(),
          'url' => $group->toUrl()->toString(),
        ];
      }
    }

    if (empty($groups)) {
      return;
    }

    $variables['posted_in_groups'] = $groups;
    $variables['#attached']['library'][] = 'do_multigroup/do_multigroup';

    if ($variables['view_mode'] === 'full') {
      $variables['content']['posted_in'] = [
        '#theme' => 'item_list',
        '#title' => t('Posted in'),
        '#items' => array_map(
          static fn($g) => [
            '#type' => 'link',
            '#title' => $g['label'],
            '#url' => Url::fromUri('internal:' . $g['url']),
          ],
          $groups,
        ),
        '#weight' => -100,
        '#attributes' => ['class' => ['do-multigroup-posted-in']],
      ];
    }

    if ($variables['view_mode'] === 'teaser' && count($groups) > 1) {
      $variables['content']['cross_posted'] = [
        '#markup' => '<span class="do-multigroup-cross-posted">' . t('Cross-posted') . '</span>',
        '#weight' => -100,
      ];
    }
  }

}
