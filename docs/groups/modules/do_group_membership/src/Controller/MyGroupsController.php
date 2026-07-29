<?php

declare(strict_types=1);

namespace Drupal\do_group_membership\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists the groups the current user belongs to ("My Groups").
 *
 * The main-menu "My Groups" link used to point at `/user` — the visitor's own
 * profile, which shows a group COUNT (via do_profile_stats' contribution
 * stats block) but never lists the groups themselves. A link labelled "My
 * Groups" that lands on a number rather than a list is the kind of copy/
 * destination mismatch #133's honesty sweep exists to catch, so this
 * controller supplies the destination the label always implied.
 *
 * Deliberately a controller rather than a view: the "groups this account is a
 * member of" relationship is a `group_relationship` traversal, which
 * `GroupMembershipLoader` already resolves correctly (including membership
 * status), whereas expressing it in Views config would mean hand-authoring a
 * relationship + filter chain that duplicates that same logic. Rendering
 * reuses the group entity's own `teaser`-style view builder, so cards stay
 * visually consistent with the rest of the site without this controller
 * owning any markup of its own.
 */
final class MyGroupsController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
    );
  }

  /**
   * Builds the "My Groups" listing.
   *
   * @return array
   *   A render array: the visitor's groups, or a truthful empty state naming
   *   the concrete next step (browse the directory) rather than a bare "no
   *   results".
   */
  public function page(): array {
    $groups = $this->loadMyGroups();

    if ($groups === []) {
      return [
        'empty' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['gc-empty']],
          'title' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['gc-empty__title']],
            '#value' => $this->t('You have not joined any groups yet'),
          ],
          'text' => [
            '#type' => 'html_tag',
            '#tag' => 'p',
            '#attributes' => ['class' => ['gc-empty__text']],
            '#value' => $this->t('Browse the directory to find a community to join.'),
          ],
          'cta' => [
            '#type' => 'link',
            '#title' => $this->t('Browse all groups'),
            '#url' => Url::fromRoute('view.all_groups.page_1'),
            '#attributes' => ['class' => ['gc-button', 'gc-button--primary']],
          ],
        ],
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['group_relationship_list'],
        ],
      ];
    }

    $view_builder = $this->entityTypeManager()->getViewBuilder('group');

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['do-my-groups']],
      // Cache per user (the list IS the user's membership) and invalidate
      // whenever any membership changes — joining or leaving a group must
      // change this page immediately.
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['group_relationship_list'],
      ],
    ];

    foreach ($groups as $group) {
      $build[$group->id()] = $view_builder->view($group, 'teaser');
    }

    return $build;
  }

  /**
   * Loads the groups the current user is a member of.
   *
   * Reads the relationship data table directly, mirroring
   * `do_profile_stats`' `ContributionStatsBlock::countGroups()` — Group 4.x
   * ships no `group.membership_loader` service (verified: the container has
   * no such service), so that block's query IS this codebase's established
   * way to resolve "the groups this account belongs to".
   *
   * Filtering is on `gr.entity_id` — the account the membership relationship
   * REFERENCES — not `gr.uid`, which in Group 4.x is the membership record's
   * AUTHOR. Filtering `uid` would list groups whose membership rows the user
   * happened to author rather than the groups they belong to, returning
   * nothing for a member an admin added (the same trap issue #63 fixed in
   * the stats block).
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   The groups, keyed by group id.
   */
  private function loadMyGroups(): array {
    $gids = $this->database->select('group_relationship_field_data', 'gr')
      ->fields('gr', ['gid'])
      ->condition('gr.entity_id', (int) $this->currentUser()->id())
      ->condition('gr.type', '%group_membership', 'LIKE')
      ->distinct()
      ->execute()
      ->fetchCol();

    if ($gids === []) {
      return [];
    }

    $groups = [];
    foreach ($this->entityTypeManager()->getStorage('group')->loadMultiple($gids) as $group) {
      // Respect access: a membership row does not by itself entitle the
      // viewer to see the group (e.g. it was archived/unpublished after they
      // joined), so each is access-checked before it is listed.
      if ($group instanceof GroupInterface && $group->access('view')) {
        $groups[$group->id()] = $group;
      }
    }
    return $groups;
  }

}
