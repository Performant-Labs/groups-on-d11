<?php

declare(strict_types=1);

namespace Drupal\do_group_pin\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;

/**
 * Hook implementations for do_group_pin.
 *
 * Content pinning within groups via the pin_in_group flag.
 */
class DoGroupPinHooks {

  /**
   * Attaches the pin CSS library globally.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'do_group_pin/do_group_pin';
  }

  /**
   * Adds pin badge and CSS class to pinned nodes in the group stream.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    $node = $variables['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      return;
    }
    try {
      $flag_count = \Drupal::service('flag.count');
      $counts = $flag_count->getEntityFlagCounts($node);
      if (!empty($counts['pin_in_group']) && $counts['pin_in_group'] > 0) {
        $variables['pinned'] = TRUE;
        $variables['attributes']['class'][] = 'node--pinned';
        $variables['title_suffix']['pin_badge'] = [
          '#markup' => '<span class="pin-badge">' . t('Pinned') . '</span>',
          '#weight' => -100,
        ];
      }
    }
    catch (\Exception $e) {
      // flag.count service may not be available — silent fallback.
    }
  }

  /**
   * LEFT JOINs the flagging table to group_content_stream for pin-first sort.
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, mixed $query): void {
    // The stream view keeps the machine name 'group_content_stream' under
    // Group 4.x — the id is a stable config machine name and was not renamed by
    // the GroupContent → GroupRelationship rework. Verified against the shipped
    // config/views.view.group_content_stream.yml (base_table: node_field_data,
    // relationship plugin_id: group_relationship).
    if ($view->id() !== 'group_content_stream') {
      return;
    }

    // Group 4.x compatibility: the flag join hangs off the view's NODE base
    // table (node_field_data.nid), NOT off Group's relationship storage. The
    // group_content_stream view has base_table: node_field_data and reaches
    // Group data through the 'group_relationship' Views relationship, so the
    // flagging LEFT JOIN below is independent of Group's storage rename
    // (group_content_field_data → group_relationship_field_data in 4.x). No
    // table-alias change is required here for 4.x.
    $definition = [
      'type' => 'LEFT',
      'table' => 'flagging',
      'field' => 'entity_id',
      'left_table' => 'node_field_data',
      'left_field' => 'nid',
      'extra' => [
        ['field' => 'flag_id', 'value' => 'pin_in_group'],
        ['field' => 'entity_type', 'value' => 'node'],
      ],
    ];

    $join = \Drupal::service('plugin.manager.views.join')
      ->createInstance('standard', $definition);

    $query->addRelationship('pin_flagging', $join, 'flagging');

    // Pin-first sort: pinned items must LEAD the stream, not merely break ties.
    //
    // hook_views_query_alter runs AFTER the view registers its own sorts, so the
    // view's `created DESC` sort is already the first entry in $query->orderby.
    // Appending the pin order-by (the historical bug, #52) made `pin_sort` a
    // SECONDARY key — `ORDER BY created DESC, pin_sort DESC` — so a pinned older
    // node never led. We must make the pin the PRIMARY key while preserving the
    // view's remaining sorts (created DESC) as the secondary ordering.
    //
    // Group 4.x note: the view sets `distinct: true` and reaches Group through
    // the `group_relationship` relationship. The pin_flagging LEFT JOIN's alias
    // survives the DISTINCT rewrite because the CASE formula references it, so
    // the pin-first ordering holds on the real generated SQL (verified via the
    // executed-view kernel test PinnedStreamOrderingTest).
    //
    // Add the pin order-by, then move it to the FRONT of the orderby list so it
    // sorts before `created DESC`. array_unshift on $query->orderby (rather than
    // clear-and-rebuild) keeps every other registered sort intact and in order.
    $query->addOrderBy(
      NULL,
      'CASE WHEN pin_flagging.id IS NOT NULL THEN 1 ELSE 0 END',
      'DESC',
      'pin_sort',
    );
    $pin_order = array_pop($query->orderby);
    array_unshift($query->orderby, $pin_order);
  }

}
