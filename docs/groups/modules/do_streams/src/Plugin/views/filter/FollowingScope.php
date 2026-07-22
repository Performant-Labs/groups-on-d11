<?php

declare(strict_types=1);

namespace Drupal\do_streams\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Restricts the node base table to content the current user follows.
 *
 * Issue #109 / brief [B-4]/[B-9]/[W-1] / survey.md §Following-scope: ORs
 * three independently-verified EXISTS branches against the `flagging` table
 * (each guarded by `entity_type = 'node'|'user'|'taxonomy_term'` so the
 * flagging table's shared/global storage never cross-matches the wrong
 * entity type):
 *  - follow_content: the node itself is flagged (`flagging.entity_id =
 *    node.nid`, `entity_type = 'node'`).
 *  - follow_user: the node's AUTHOR is flagged (`flagging.entity_id =
 *    node.uid`, `entity_type = 'user'`) — per [B-9], the viewer follows the
 *    author, not the node.
 *  - follow_term: a term on the node's `field_group_tags` (per [B-4], NOT
 *    `field_tags`) is flagged (`flagging.entity_id = <term id>`,
 *    `entity_type = 'taxonomy_term'`).
 *
 * Each branch is an EXISTS subquery (per [W-1], avoiding LEFT JOIN fan-out
 * entirely for scope), OR'd together in a single WHERE clause, so a node
 * matching more than one branch still contributes exactly one row — no
 * GROUP BY dedupe is needed for scope (only ranking joins need that, per
 * [B-6]).
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('do_streams_following_scope')]
class FollowingScope extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);
    $this->no_operator = TRUE;
    $this->alwaysMultiple = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function canExpose() {
    return FALSE;
  }

  /**
   * Adds the following-scope OR-of-3-EXISTS condition to the query.
   */
  public function query() {
    $this->ensureMyTable();
    $uid = (int) \Drupal::currentUser()->id();
    // This is a SYNTHETIC filter-only field (do_streams_following_scope is
    // not a real column) — $this->realField would resolve to that synthetic
    // field name, not an actual node column, so the base table's own base
    // field (nid) is referenced explicitly instead.
    $base_field = $this->view->storage->get('base_field');
    $node_ref = "$this->tableAlias.$base_field";

    $follow_content = "EXISTS (
      SELECT 1 FROM {flagging} do_streams_fc
      WHERE do_streams_fc.flag_id = :do_streams_follow_content_flag
        AND do_streams_fc.entity_type = :do_streams_entity_type_node
        AND do_streams_fc.uid = :do_streams_current_uid_fc
        AND do_streams_fc.entity_id = $node_ref
    )";

    $follow_user = "EXISTS (
      SELECT 1 FROM {flagging} do_streams_fu
      WHERE do_streams_fu.flag_id = :do_streams_follow_user_flag
        AND do_streams_fu.entity_type = :do_streams_entity_type_user
        AND do_streams_fu.uid = :do_streams_current_uid_fu
        AND do_streams_fu.entity_id = $this->tableAlias.uid
    )";

    $follow_term = "EXISTS (
      SELECT 1 FROM {node__field_group_tags} do_streams_ngt
      INNER JOIN {flagging} do_streams_ft
        ON do_streams_ft.entity_id = do_streams_ngt.field_group_tags_target_id
        AND do_streams_ft.flag_id = :do_streams_follow_term_flag
        AND do_streams_ft.entity_type = :do_streams_entity_type_term
        AND do_streams_ft.uid = :do_streams_current_uid_ft
      WHERE do_streams_ngt.entity_id = $node_ref
    )";

    $this->query->addWhereExpression(
      $this->options['group'],
      "($follow_content) OR ($follow_user) OR ($follow_term)",
      [
        ':do_streams_follow_content_flag' => 'follow_content',
        ':do_streams_entity_type_node' => 'node',
        ':do_streams_current_uid_fc' => $uid,
        ':do_streams_follow_user_flag' => 'follow_user',
        ':do_streams_entity_type_user' => 'user',
        ':do_streams_current_uid_fu' => $uid,
        ':do_streams_follow_term_flag' => 'follow_term',
        ':do_streams_entity_type_term' => 'taxonomy_term',
        ':do_streams_current_uid_ft' => $uid,
      ],
    );
  }

}
