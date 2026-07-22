<?php

declare(strict_types=1);

namespace Drupal\do_streams\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Restricts nodes to groups the current user is a member of.
 *
 * Issue #109 / brief [B-9]: adds an EXISTS-shaped condition (no relationship,
 * no JOIN, so no fan-out/dedupe concerns) restricting `node_field_data` rows
 * to nodes with a `group_relationship_field_data` row whose `plugin_id LIKE
 * 'group_node:%'` and whose `gid` also has a `group_relationship_field_data`
 * row of `plugin_id = 'group_membership'` for the CURRENT viewing user
 * ([B-9]'s reference SQL shape):
 *
 * @code
 * EXISTS (
 *   SELECT 1 FROM group_relationship_field_data gr_node
 *   INNER JOIN group_relationship_field_data gr_member
 *     ON gr_member.gid = gr_node.gid
 *   WHERE gr_node.entity_id = node_field_data.nid
 *     AND gr_node.plugin_id LIKE 'group_node:%'
 *     AND gr_member.plugin_id = 'group_membership'
 *     AND gr_member.entity_id = :current_uid
 * )
 * @endcode
 *
 * Per-user, covers every group the user belongs to (not a single gid), and a
 * user in zero groups sees zero rows.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('do_streams_membership_scope')]
class MembershipScope extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);
    // No exposed value/operator UI — this filter is a fixed, always-on
    // restriction to the current viewing user's membership scope.
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
   * Adds the membership-scope EXISTS condition to the query.
   */
  public function query() {
    $this->ensureMyTable();
    $uid = (int) \Drupal::currentUser()->id();
    // This is a SYNTHETIC filter-only field (do_streams_membership_scope is
    // not a real column) — $this->realField would resolve to that synthetic
    // field name, not an actual node column, so the base table's own base
    // field (nid) is referenced explicitly instead.
    $base_field = $this->view->storage->get('base_field');
    $node_ref = "$this->tableAlias.$base_field";

    $this->query->addWhereExpression(
      $this->options['group'],
      "EXISTS (
        SELECT 1 FROM {group_relationship_field_data} do_streams_gr_node
        INNER JOIN {group_relationship_field_data} do_streams_gr_member
          ON do_streams_gr_member.gid = do_streams_gr_node.gid
        WHERE do_streams_gr_node.entity_id = $node_ref
          AND do_streams_gr_node.plugin_id LIKE :do_streams_group_node_prefix
          AND do_streams_gr_member.plugin_id = :do_streams_group_membership_plugin
          AND do_streams_gr_member.entity_id = :do_streams_current_uid
      )",
      [
        ':do_streams_group_node_prefix' => 'group_node:%',
        ':do_streams_group_membership_plugin' => 'group_membership',
        ':do_streams_current_uid' => $uid,
      ],
    );
  }

}
