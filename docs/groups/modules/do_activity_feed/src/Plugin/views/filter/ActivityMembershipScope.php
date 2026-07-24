<?php

declare(strict_types=1);

namespace Drupal\do_activity_feed\Plugin\views\filter;

use Drupal\views\Attribute\ViewsFilter;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\filter\FilterPluginBase;
use Drupal\views\ViewExecutable;

/**
 * Restricts `message` rows to groups the current user is a member of.
 *
 * Issue #129 ST-7 / A-advisory #1: modeled directly on
 * {@see \Drupal\do_streams\Plugin\views\filter\MembershipScope}'s EXISTS
 * pattern, but keyed on the `message` entity's `field_group_id` (a
 * message-level entity-reference field, NOT a group_node relationship) —
 * `message_field_data` has no relationship to `group_relationship_field_data`
 * of its own, so this filter joins through the field's OWN dedicated
 * `message__field_group_id` table (the standard Drupal storage table for a
 * single-cardinality entity_reference field on a non-revisionable entity
 * type) rather than the `group_node:%`-plugin join MembershipScope uses.
 *
 * Concrete SQL shape (A-advisory #1's required explicit docblock):
 *
 * @code
 * EXISTS (
 *   SELECT 1 FROM {message__field_group_id} do_activity_feed_message_group
 *   INNER JOIN {group_relationship_field_data} do_activity_feed_gr_member
 *     ON do_activity_feed_gr_member.gid = do_activity_feed_message_group.field_group_id_target_id
 *   WHERE do_activity_feed_message_group.entity_id = message_field_data.mid
 *     AND do_activity_feed_gr_member.plugin_id = 'group_membership'
 *     AND do_activity_feed_gr_member.entity_id = :current_uid
 * )
 * @endcode
 *
 * A message with an EMPTY `field_group_id` (the three templates the brief's
 * "Which templates surface on /activity" table marks "no") has no row at all
 * in `message__field_group_id`, so the EXISTS is unsatisfiable for it —
 * those templates are excluded from `/activity` as a direct consequence of
 * this join, with no separate `template IN (...)` filter needed for that
 * purpose (the view's own `template` filter, set separately, narrows to the
 * three aggregable/social templates for other reasons — see
 * config/install/views.view.activity_feed.yml).
 *
 * Per-user, covers every group the user belongs to (not a single gid), and a
 * user in zero groups sees zero rows — identical guarantee to
 * MembershipScope.
 *
 * @ingroup views_filter_handlers
 */
#[ViewsFilter('do_activity_feed_membership_scope')]
class ActivityMembershipScope extends FilterPluginBase {

  /**
   * {@inheritdoc}
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);
    // No exposed value/operator UI — this filter is a fixed, always-on
    // restriction to the current viewing user's membership scope, matching
    // MembershipScope's own posture exactly.
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
    // Synthetic filter-only field (do_activity_feed_membership_scope is not
    // a real column) — reference the base table's own base field (mid)
    // explicitly, mirroring MembershipScope's identical rationale.
    $base_field = $this->view->storage->get('base_field');
    $message_ref = "$this->tableAlias.$base_field";

    $this->query->addWhereExpression(
      $this->options['group'],
      "EXISTS (
        SELECT 1 FROM {message__field_group_id} do_activity_feed_message_group
        INNER JOIN {group_relationship_field_data} do_activity_feed_gr_member
          ON do_activity_feed_gr_member.gid = do_activity_feed_message_group.field_group_id_target_id
        WHERE do_activity_feed_message_group.entity_id = $message_ref
          AND do_activity_feed_gr_member.plugin_id = :do_activity_feed_group_membership_plugin
          AND do_activity_feed_gr_member.entity_id = :do_activity_feed_current_uid
      )",
      [
        ':do_activity_feed_group_membership_plugin' => 'group_membership',
        ':do_activity_feed_current_uid' => $uid,
      ],
    );
  }

}
