<?php

namespace Drupal\do_profile_stats\Plugin\Block;

use Drupal\Core\Block\BlockBase;
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
      $query = $this->database->select('group_relationship_field_data', 'gr')
        ->condition('gr.uid', $uid)
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

}
