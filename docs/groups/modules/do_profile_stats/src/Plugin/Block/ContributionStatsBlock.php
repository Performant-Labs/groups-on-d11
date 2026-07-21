<?php

namespace Drupal\do_profile_stats\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Contribution Stats' block.
 *
 * @Block(
 *   id = "do_contribution_stats",
 *   admin_label = @Translation("Contribution Stats"),
 *   category = @Translation("Custom")
 * )
 */
class ContributionStatsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a ContributionStatsBlock.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $user = $this->getContextUser();
    if (!$user) {
      return [];
    }

    $uid = $user->id();

    return [
      '#theme' => 'do_contribution_stats',
      '#topics' => $this->countNodes($uid, 'forum'),
      '#events' => $this->countNodes($uid, 'event'),
      '#comments' => $this->countComments($uid),
      '#groups' => $this->countGroups($uid),
      '#days_active' => floor((time() - $user->getCreatedTime()) / 86400),
    ];
  }

  /**
   * Gets the user from route context.
   */
  protected function getContextUser(): ?UserInterface {
    $user = \Drupal::routeMatch()->getParameter('user');
    if ($user instanceof UserInterface) {
      return $user;
    }
    if (is_numeric($user)) {
      return \Drupal\user\Entity\User::load($user);
    }
    return NULL;
  }

  /**
   * Counts nodes of a given type by user.
   */
  protected function countNodes(int $uid, string $type): int {
    return (int) \Drupal::entityQuery('node')
      ->condition('uid', $uid)
      ->condition('type', $type)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Counts all comments by user.
   */
  protected function countComments(int $uid): int {
    return (int) \Drupal::entityQuery('comment')
      ->condition('uid', $uid)
      ->condition('status', 1)
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Counts groups the user is a member of.
   */
  protected function countGroups(int $uid): int {
    try {
      // Group 4.x compatibility: this reads the relationship data table
      // directly rather than via the membership API. The table
      // `group_relationship_field_data` and its `gid`/`entity_id`/`type` columns
      // are the v4 names (the `group_content` -> `group_relationship` rename
      // landed in v2). The v4 `content_plugin` -> `relation_type` rename (CR
      // 2026-06-19) is a config property on the GroupRelationshipType entity and
      // does NOT rename the data-table `type` column nor the `group_membership`
      // plugin-ID suffix matched below, so this filter stays valid. No
      // membership-loader `$roles` argument or permission calculation is used
      // here, so the Access Policy / `$roles`-array deltas do not apply.
      //
      // The member is filtered on `gr.entity_id` — the account the membership
      // relationship *references* — NOT `gr.uid`, which in Group 4.x is the
      // membership record's *author* (the group owner, for API/admin-added
      // members). Filtering `gr.uid` would count groups whose membership rows a
      // user authored rather than the groups they belong to, reporting 0 for a
      // member someone else added and over-crediting the owner (issue #63).
      $query = $this->database->select('group_relationship_field_data', 'gr')
        ->condition('gr.entity_id', $uid)
        ->condition('gr.type', '%group_membership', 'LIKE');
      $query->addExpression('COUNT(DISTINCT gr.gid)', 'group_count');
      return (int) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 3600;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    // build() derives its output from the `user` route parameter
    // (getContextUser()), so the rendered block varies by URL. Without the
    // `url` cache context the first render — which may be on a page with no
    // user route parameter, yielding an empty build — gets cached and reused
    // for every page, so the block silently fails to appear on /user/{uid}.
    // Add `url` so each route caches its own build.
    return Cache::mergeContexts(parent::getCacheContexts(), ['url']);
  }

}
