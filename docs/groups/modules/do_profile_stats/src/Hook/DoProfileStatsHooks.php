<?php

declare(strict_types=1);

namespace Drupal\do_profile_stats\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for do_profile_stats.
 *
 * Defines Twig templates and attaches CSS on user profile pages.
 */
class DoProfileStatsHooks {

  /**
   * Registers Twig templates for the contribution stats and completeness blocks.
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return [
      'do_contribution_stats' => [
        'variables' => [
          'topics' => 0,
          'events' => 0,
          'comments' => 0,
          'groups' => 0,
          'days_active' => 0,
        ],
        'template' => 'pl-contribution-stats',
      ],
      'do_profile_completeness' => [
        'variables' => [
          'percentage' => 0,
          'missing_fields' => [],
        ],
        'template' => 'pl-profile-completeness',
      ],
    ];
  }

  /**
   * Attaches the CSS library on user canonical profile pages.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $route = \Drupal::routeMatch()->getRouteName();
    if ($route === 'entity.user.canonical') {
      $attachments['#attached']['library'][] = 'do_profile_stats/do_profile_stats';
    }
  }

}
