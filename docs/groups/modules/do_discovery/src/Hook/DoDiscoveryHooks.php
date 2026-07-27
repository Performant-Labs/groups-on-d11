<?php

declare(strict_types=1);

namespace Drupal\do_discovery\Hook;

use Drupal\comment\CommentInterface;
use Drupal\Core\Cache\Cache;
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
   * Builds the per-node hot-score cache tag (#251 Fix 2).
   *
   * Audit `cache-audit-2026-07-24.md` §4 Recommendation 2(a): a new,
   * node-scoped tag `/trending`'s consuming render (or a future controller
   * wrapper) can attach to invalidate exactly the affected node's own
   * hot-score-derived render, without over-invalidating every OTHER node's
   * unrelated score. Mirrors `DoStreamsHooks::rsvpChipCacheTag()`'s identical
   * per-node, synthetic-tag convention (a dedicated module-owned tag rather
   * than a bare entity list tag, to avoid over-invalidating unrelated
   * content). Invalidated by {@see self::recomputeNodeScore()} whenever this
   * node's `do_discovery_hot_score` row changes value.
   *
   * @param int|string $nid
   *   The scored node's id.
   *
   * @return string
   *   The scoped cache tag, e.g. `do_discovery:hot_score:203`.
   */
  public static function hotScoreCacheTag(int|string $nid): string {
    return 'do_discovery:hot_score:' . $nid;
  }

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

  /**
   * Recomputes the commented node's hot score immediately (#251 Fix 2).
   *
   * Audit `cache-audit-2026-07-24.md` §2 Scenario 4 / §4 Recommendation 2:
   * before this fix, `do_discovery_hot_score.score` was written ONLY by
   * {@see self::cron()} — a comment posted on a forum node had NO effect on
   * `/trending`'s ranking until the next cron run (this module's own
   * `HotScoreForumCommentTest` proved this in its own docblock/test body,
   * manually invoking `$hooks->cron()` rather than asserting the score
   * updates on its own). `/trending`'s Views display already declares
   * `cache: type: none` (`views.view.trending.yml`), so this is NOT a
   * render/page-cache-invalidation gap — the view always re-queries; the
   * actual gap is that the underlying SCORE ROW itself never changed, so a
   * fresh re-query still returned stale ordering.
   *
   * Only acts on a `node`-commented entity (the only entity type
   * `do_discovery_hot_score` scores) — a comment on any other commentable
   * entity type is a no-op, mirroring `DoActivityHooks::commentInsert()`'s
   * own "left empty when there is no applicable context" defensive
   * convention rather than fabricating a nonsensical node id.
   *
   * Delegates the actual score arithmetic to {@see self::recomputeNodeScore()}
   * — the SAME single-row query/formula shape {@see self::cron()} already
   * uses (LEFT JOIN comment_entity_statistics + optional node_counter,
   * comment_count × 3 + view_count × 0.5), scoped to exactly this one node
   * via a `nid` condition, so this fix never duplicates or drifts from the
   * cron path's own formula — a future formula change only has one place to
   * update.
   *
   * @param \Drupal\comment\CommentInterface $comment
   *   The just-inserted comment.
   */
  #[Hook('comment_insert')]
  public function commentInsert(CommentInterface $comment): void {
    if ($comment->getCommentedEntityTypeId() !== 'node') {
      return;
    }
    $this->recomputeNodeScore((int) $comment->getCommentedEntityId());
  }

  /**
   * Recomputes and writes a single node's hot_score row, then invalidates it.
   *
   * Mirrors {@see self::cron()}'s own per-node query shape (LEFT JOIN
   * comment_entity_statistics for the comment count; LEFT JOIN the optional
   * `node_counter` table for the view count when it exists; formula
   * comment_count × 3 + view_count × 0.5) — scoped to exactly ONE node via a
   * `nid` condition rather than cron's "every node changed in the last 7
   * days" sweep, since this is triggered per-comment, not on a schedule.
   *
   * A no-op (never a fatal) when the `do_discovery_hot_score` table itself
   * does not exist — mirrors {@see self::cron()} and
   * {@see self::nodeInsert()}'s identical defensive guard for a context
   * where this module's schema was never installed (e.g. a Kernel test
   * that enables `do_discovery` without calling `installSchema()`).
   *
   * After the row is written, invalidates {@see self::hotScoreCacheTag()}
   * for this node — the new, node-scoped tag a future `/trending` render
   * consumer (controller wrapper or Views handler) can attach to. `/trending`
   * itself declares `cache: type: none` today (confirmed by direct read of
   * `views.view.trending.yml`), so this invalidation call is currently a
   * no-op for that specific view (there is no cache entry carrying this tag
   * yet) but is NOT dead code: it is the exact mechanism the audit's own
   * recommendation names ("a new, node-scoped tag... `/trending`'s
   * controller/view would need to consume"), and it correctly future-proofs
   * any OTHER render (a node's own Full-page hot-score badge, a future
   * controller wrapper) that later attaches this tag, without this module
   * needing a second change when that consumer is built.
   *
   * @param int $nid
   *   The node id to recompute.
   */
  private function recomputeNodeScore(int $nid): void {
    if (!$this->database->schema()->tableExists('do_discovery_hot_score')) {
      return;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->fields('n', ['nid']);
    $query->condition('n.nid', $nid);

    $query->leftJoin(
      'comment_entity_statistics',
      'ces',
      'ces.entity_id = n.nid AND ces.entity_type = :node_type',
      [':node_type' => 'node'],
    );
    $query->addExpression('COALESCE(ces.comment_count, 0)', 'comment_count');

    if ($this->database->schema()->tableExists('node_counter')) {
      $query->leftJoin('node_counter', 'nc', 'nc.nid = n.nid');
      $query->addExpression('COALESCE(nc.totalcount, 0)', 'view_count');
    }
    else {
      $query->addExpression('0', 'view_count');
    }

    $row = $query->execute()->fetchObject();
    if ($row === FALSE) {
      // The commented node either does not exist or is not published
      // (node_field_data still carries unpublished nodes, so this is really
      // "no such nid" — e.g. a stale/deleted node id) — nothing to score.
      return;
    }

    $score = ($row->comment_count * 3) + ($row->view_count * 0.5);
    $this->database->merge('do_discovery_hot_score')
      ->key('nid', $nid)
      ->fields([
        'score' => $score,
        'computed' => \Drupal::time()->getRequestTime(),
      ])
      ->execute();

    Cache::invalidateTags([self::hotScoreCacheTag($nid)]);
  }

}
