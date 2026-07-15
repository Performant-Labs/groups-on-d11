<?php

declare(strict_types=1);

namespace Drupal\do_discovery\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for do_discovery.
 *
 * Hot content scoring, Views integration, and new-node score seeding.
 */
class DoDiscoveryHooks {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Recomputes hot scores for nodes changed in the last 7 days.
   *
   * Score = (comment_count × 3) + (view_count × 0.5)
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!$this->database->schema()->tableExists('do_discovery_hot_score')) {
      return;
    }

    $cutoff = \Drupal::time()->getRequestTime() - (7 * 86400);

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.status', 1);
    $query->condition('n.changed', $cutoff, '>=');

    // LEFT JOIN comment statistics for comment counts.
    $query->leftJoin(
      'comment_entity_statistics',
      'ces',
      'ces.entity_id = n.nid AND ces.entity_type = :node_type',
      [':node_type' => 'node'],
    );
    $query->addExpression('COALESCE(ces.comment_count, 0)', 'comment_count');

    // LEFT JOIN statistics node_counter for view counts (optional module).
    if ($this->database->schema()->tableExists('node_counter')) {
      $query->leftJoin('node_counter', 'nc', 'nc.nid = n.nid');
      $query->addExpression('COALESCE(nc.totalcount, 0)', 'view_count');
    }
    else {
      $query->addExpression('0', 'view_count');
    }

    $now = \Drupal::time()->getRequestTime();
    foreach ($query->execute() as $row) {
      $score = ($row->comment_count * 3) + ($row->view_count * 0.5);
      $this->database->merge('do_discovery_hot_score')
        ->key('nid', $row->nid)
        ->fields(['score' => $score, 'computed' => $now])
        ->execute();
    }
  }

  /**
   * Exposes do_discovery_hot_score table to Views.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    return [
      'do_discovery_hot_score' => [
        'table' => [
          'group' => t('Hot Content'),
          'provider' => 'do_discovery',
          'join' => [
            'node_field_data' => [
              'left_field' => 'nid',
              'field' => 'nid',
            ],
          ],
        ],
        'nid' => [
          'title' => t('Node ID'),
          'help' => t('The node ID with a hot score.'),
          'field' => ['id' => 'numeric'],
          'filter' => ['id' => 'numeric'],
          'sort' => ['id' => 'standard'],
          'relationship' => [
            'title' => t('Scored Content'),
            'help' => t('Relate hot score to the node.'),
            'base' => 'node_field_data',
            'base field' => 'nid',
            'id' => 'standard',
            'label' => t('Node'),
          ],
        ],
        'score' => [
          'title' => t('Hot Score'),
          'help' => t('The computed hot content score.'),
          'field' => ['id' => 'numeric'],
          'filter' => ['id' => 'numeric'],
          'sort' => ['id' => 'standard'],
        ],
        'computed' => [
          'title' => t('Score Computed'),
          'help' => t('When the hot score was last computed.'),
          'field' => ['id' => 'date'],
          'filter' => ['id' => 'date'],
          'sort' => ['id' => 'date'],
        ],
      ],
    ];
  }

  /**
   * Seeds a score = 0 row so new nodes appear in hot content Views immediately.
   */
  #[Hook('node_insert')]
  public function nodeInsert(NodeInterface $node): void {
    if (!$node->isPublished()) {
      return;
    }
    if (!$this->database->schema()->tableExists('do_discovery_hot_score')) {
      return;
    }
    $this->database->merge('do_discovery_hot_score')
      ->key('nid', $node->id())
      ->fields([
        'score' => 0,
        'computed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();
  }

}
