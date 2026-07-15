<?php

namespace Drupal\do_group_mission\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;

/**
 * Provides a 'Group Mission' block.
 *
 * @Block(
 *   id = "do_group_mission",
 *   admin_label = @Translation("Group Mission"),
 *   category = @Translation("Custom"),
 *   context_definitions = {
 *     "group" = @ContextDefinition("entity:group", required = FALSE, label = @Translation("Group"))
 *   }
 * )
 */
class GroupMissionBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $group = $this->getGroup();
    if (!$group) {
      return [];
    }

    // Check if group has a description field.
    if (!$group->hasField('field_group_description') || $group->get('field_group_description')->isEmpty()) {
      return [];
    }

    $description = $group->get('field_group_description')->value;

    // Truncate to 300 chars with word boundary.
    $truncated = $description;
    $show_more = FALSE;
    if (mb_strlen(strip_tags($description)) > 300) {
      $truncated = mb_substr(strip_tags($description), 0, 300);
      // Find last space for word boundary.
      $last_space = mb_strrpos($truncated, ' ');
      if ($last_space !== FALSE) {
        $truncated = mb_substr($truncated, 0, $last_space);
      }
      $truncated .= '…';
      $show_more = TRUE;
    }

    $build = [
      '#markup' => '<div class="group-mission"><p>' . $truncated . '</p>',
    ];

    if ($show_more) {
      $url = Url::fromRoute('entity.group.canonical', ['group' => $group->id()]);
      $build['#markup'] .= '<a href="' . $url->toString() . '" class="read-more">' . $this->t('Read more') . '</a>';
    }

    $build['#markup'] .= '</div>';

    return $build;
  }

  /**
   * Gets the group entity from context or route.
   */
  protected function getGroup(): ?GroupInterface {
    // Try context first.
    try {
      $group = $this->getContextValue('group');
      if ($group instanceof GroupInterface) {
        return $group;
      }
    }
    catch (\Exception $e) {
      // Context not available, try route.
    }

    // Try route parameter.
    $group = \Drupal::routeMatch()->getParameter('group');
    if ($group instanceof GroupInterface) {
      return $group;
    }
    if (is_numeric($group)) {
      return \Drupal::entityTypeManager()->getStorage('group')->load($group);
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $group = $this->getGroup();
    if ($group) {
      return Cache::mergeTags(parent::getCacheTags(), $group->getCacheTags());
    }
    return parent::getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

}
