<?php

declare(strict_types=1);

namespace Drupal\do_activity_feed\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Hook implementations for do_activity_feed (#129, ST-7).
 *
 * Registers the three row-shape theme hooks + the overall feed wrapper
 * theme hook, exposes the ActivityMembershipScope Views filter plugin to
 * Views as a synthetic message field (A-advisory #3 — required; without
 * this hook_views_data registration the `views.view.activity_feed.yml`
 * filter config cannot resolve the plugin at load time), and translates
 * each raw row-model array `\Drupal\do_activity_feed\Controller\
 * ActivityFeedController::renderFeed()` builds into a themed sub-render-
 * array (the `#theme` selection ActivityFeedController itself does not
 * make — it only produces plain data arrays, per the layering
 * ActivityFeedRenderTest's own kernel assertions rely on, reading
 * `$row['type']` directly rather than a render array's `#theme` key).
 */
class DoActivityFeedHooks {

  /**
   * Message-template row types that map to the shared social-row template.
   *
   * Every one of these renders through `activity_row_social` — the
   * wireframe collapses join / RSVP / comment / group-created / pin to a
   * single `data-testid="activity-row-social"` CSS/testid bucket (see
   * handoff-T-red.md's own "Spec ambiguity for F" note); the finer-grained
   * `type` value selects sentence WORDING inside the shared template, not a
   * different theme hook.
   */
  private const SOCIAL_ROW_TYPES = [
    'social_join',
    'social_rsvp',
    'social_comment',
    'social_group_created',
    'social_pin',
  ];

  /**
   * Registers the do_activity_feed_membership_scope synthetic filter field.
   *
   * Mirrors {@see \Drupal\do_streams\Hook\DoStreamsHooks::viewsData()}
   * exactly: Views resolves a handler by the field's OWN `views_data`
   * registration (table + field), not merely by a config item's
   * `plugin_id` — so `do_activity_feed_membership_scope` must be a
   * synthetic (filter-only, no underlying column) field on
   * `message_field_data`, resolving to
   * {@see \Drupal\do_activity_feed\Plugin\views\filter\ActivityMembershipScope}
   * in the shipped view's filter config.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    return [
      'message_field_data' => [
        'do_activity_feed_membership_scope' => [
          'title' => new TranslatableMarkup('Activity Feed: Membership scope'),
          'help' => new TranslatableMarkup('Restricts activity messages to groups the current user is a member of.'),
          'filter' => ['id' => 'do_activity_feed_membership_scope'],
        ],
      ],
    ];
  }

  /**
   * Registers the four theme hooks this module's templates implement.
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    return $existing + [
      'activity_row_social' => [
        'variables' => [
          'row' => [],
        ],
        'template' => 'activity-row--social',
      ],
      'activity_row_content' => [
        'variables' => [
          'row' => [],
        ],
        'template' => 'activity-row--content',
      ],
      'activity_row_aggregated' => [
        'variables' => [
          'row' => [],
        ],
        'template' => 'activity-row--aggregated',
      ],
      'activity_feed' => [
        'variables' => [
          'rows' => [],
          'empty' => TRUE,
          'empty_copy' => '',
        ],
        'template' => 'activity-feed',
      ],
    ];
  }

  /**
   * Translates each raw row-model array into a themed sub-render-array.
   *
   * `ActivityFeedController::renderFeed()` populates `$variables['rows']`
   * with PLAIN row-model arrays (no `#theme` key) — this preprocess is the
   * ONE place that decides which of the three row-shape theme hooks a
   * given `type` value renders through, so
   * `activity-feed.html.twig`'s `{{ row }}` print statement auto-renders
   * the correct template per row.
   */
  #[Hook('preprocess_activity_feed')]
  public function preprocessActivityFeed(array &$variables): void {
    $rows = $variables['rows'] ?? [];
    $themed = [];

    foreach ($rows as $row) {
      $type = $row['type'] ?? NULL;

      if ($type === 'content_card') {
        $themed[] = [
          '#theme' => 'activity_row_content',
          '#row' => $row,
        ];
        continue;
      }

      if ($type === 'aggregated') {
        $themed[] = [
          '#theme' => 'activity_row_aggregated',
          '#row' => $row,
        ];
        continue;
      }

      if (in_array($type, self::SOCIAL_ROW_TYPES, TRUE)) {
        $themed[] = [
          '#theme' => 'activity_row_social',
          '#row' => $row,
        ];
      }
    }

    $variables['rows'] = $themed;
  }

}
