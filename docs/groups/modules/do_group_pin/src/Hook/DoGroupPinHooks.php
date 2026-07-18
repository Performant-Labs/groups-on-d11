<?php

declare(strict_types=1);

namespace Drupal\do_group_pin\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\flag\FlaggingInterface;
use Drupal\node\NodeInterface;
use Drupal\views\ViewExecutable;

/**
 * Hook implementations for do_group_pin.
 *
 * Content pinning within groups via the pin_in_group flag.
 */
class DoGroupPinHooks {

  /**
   * The machine name of the pin flag.
   */
  public const PIN_FLAG_ID = 'pin_in_group';

  /**
   * The id of the group stream view whose render cache tracks the pin order.
   */
  public const STREAM_VIEW_ID = 'group_content_stream';

  /**
   * Builds the per-group stream cache tag invalidated on a pin toggle.
   *
   * The group_content_stream view renders per group (its `gid` contextual
   * argument), so pinning inside one group must invalidate only that group's
   * stream render — never the whole site or every group's stream. This custom
   * tag scopes the invalidation to a single group.
   *
   * @param int|string $gid
   *   The group id.
   *
   * @return string
   *   The scoped cache tag, e.g. `do_group_pin:group_stream:42`.
   */
  public static function streamCacheTag(int|string $gid): string {
    return 'do_group_pin:group_stream:' . $gid;
  }

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
    if ($view->id() !== self::STREAM_VIEW_ID) {
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

  /**
   * Tags the rendered group stream with its per-group pin cache tag.
   *
   * The stream view's render (and results) cache does NOT depend on any tag
   * that a pin toggle invalidates: pinning creates/deletes a `flagging` entity,
   * which does not touch `node_list` or any tag the view already carries, so a
   * stream rendered before a pin toggle serves the stale order until the render
   * cache expires (#69). Here we add a scoped custom tag —
   * `do_group_pin:group_stream:<gid>` — to the view's render cache metadata, and
   * {@see self::onFlaggingChange()} invalidates that exact tag on flag/unflag so
   * the new order shows immediately.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view being rendered.
   */
  #[Hook('views_post_render')]
  public function viewsPostRender(ViewExecutable $view, mixed &$output, mixed $cache): void {
    if ($view->id() !== self::STREAM_VIEW_ID) {
      return;
    }
    // The stream is per group via its `gid` contextual argument ($view->args[0]).
    // Tag only that group's stream; a numeric gid guards against the argument's
    // "not found" / wildcard states so we never emit a malformed or over-broad
    // tag.
    $gid = $view->args[0] ?? NULL;
    if ($gid === NULL || !is_numeric($gid)) {
      return;
    }
    $view->element['#cache']['tags'] = Cache::mergeTags(
      $view->element['#cache']['tags'] ?? [],
      [self::streamCacheTag($gid)],
    );
  }

  /**
   * Invalidates the affected group streams when a node is pinned.
   *
   * Flag 4.x fires no dedicated (un)flag hook or event — it simply saves a
   * `flagging` entity — so we react to the flagging entity's own insert.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->onFlaggingChange($entity);
  }

  /**
   * Invalidates the affected group streams when a node is unpinned.
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    $this->onFlaggingChange($entity);
  }

  /**
   * Invalidates the pin stream cache tag for each group holding the node.
   *
   * Scoped by design: only the streams of the groups the flagged node actually
   * belongs to are invalidated (via the per-group
   * {@see self::streamCacheTag()}), never the whole site or unrelated groups. A
   * node not related to any group invalidates nothing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity that was inserted or deleted; a no-op unless it is a
   *   `pin_in_group` flagging.
   */
  protected function onFlaggingChange(EntityInterface $entity): void {
    if (!$entity instanceof FlaggingInterface || $entity->getFlagId() !== self::PIN_FLAG_ID) {
      return;
    }
    $node = $entity->getFlaggable();
    if (!$node instanceof NodeInterface) {
      return;
    }

    // Group's group_relationship storage may be absent (e.g. the group module
    // is not installed in a given context); fail safe rather than fatal.
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('group_relationship');
    }
    catch (\Exception $e) {
      return;
    }

    $tags = [];
    foreach ($storage->loadByEntity($node) as $relationship) {
      $tags[self::streamCacheTag($relationship->getGroupId())] = TRUE;
    }
    if ($tags) {
      Cache::invalidateTags(array_keys($tags));
    }
  }

}
