<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Theme hook registrations for do_notifications' email rendering (#232).
 *
 * A sibling to {@see \Drupal\do_notifications\Hook\DoNotificationsHooks},
 * kept separate so that class stays content-event focused (per the brief's
 * "Extend vs new" — a NEW sibling Hook class keeps email theme registration
 * cohesive). Declares the 14 theme hooks
 * {@see \Drupal\do_notifications\Email\EmailRenderer} builds against:
 *   - `email_shell_html` / `email_shell_text`: the two wrapper shells
 *     (unsubscribe link + body slot).
 *   - `email_<event>_html` / `email_<event>_text`: one body-line partial
 *     pair per `activity_*` Message template event (see EVENTS below).
 *
 * Every hook declares an explicit `path` (`templates/emails`, per the
 * brief's "Templates directory" section — NOT the module's default
 * `templates/` root) and an explicit `template` matching the brief's exact
 * filenames (`email-shell`, `email-<event>` with hyphens, per "Twig filename
 * convention: hyphens replace underscores"). Both overrides are required:
 * Drupal's default template-name derivation would otherwise look in
 * `templates/` (not `templates/emails/`) and would derive
 * `email-post-created-html` (not `email-post-created`) from a hook literally
 * named `email_post_created_html`.
 *
 * The `_text` hooks are registered here for completeness/documentation, but
 * {@see \Drupal\do_notifications\Email\EmailRenderer} never actually calls
 * `theme()`/`render()` for them — it loads the `.txt.twig` files directly
 * through the `twig` environment service instead, because Drupal's Twig
 * theme engine hardcodes the `.html.twig` extension
 * ({@see \Drupal\Core\Template\TwigThemeEngine::EXTENSION}) and can never
 * load a `.txt.twig` file via the normal `#theme` render pipeline (see
 * EmailRenderer's class docblock, "DUAL RENDER PATH"). They are still
 * registered here so the theme registry accurately reflects every email
 * template file that ships with this module.
 *
 * #236 (N-8): also registers `digest_html`, the digest wrapper
 * {@see \Drupal\do_notifications\Email\DigestRenderer} builds against. Its
 * `.txt.twig` counterpart (`digest.txt.twig`) is loaded directly through the
 * `twig` environment service, same dual-render precedent as the shells
 * above — NOT registered as a `digest_text` theme hook, since nothing ever
 * calls `theme()`/`render()` for it.
 */
class DoNotificationsEmailHooks {

  /**
   * Message template events with a rendered email partial pair.
   *
   * Matches {@see \Drupal\do_notifications\Email\EmailRenderer}'s own
   * EVENT_THEME_KEYS values exactly, so theme hook names line up with the
   * ones EmailRenderer builds (`email_<key>_html`) / loads directly
   * (`email-<key>.txt.twig`).
   */
  private const EVENTS = [
    'post_created',
    'comment_created',
    'membership_created',
    'group_created',
    'flagging_created',
    'pin_toggled',
  ];

  /**
   * Registers the 14 email theme hooks + the digest hook (#236).
   */
  #[Hook('theme')]
  public function theme(array $existing, string $type, string $theme, string $path): array {
    $templates_path = $path . '/templates/emails';

    $hooks = [
      'email_shell_html' => [
        'variables' => [
          'body' => NULL,
          'unsubscribe_url' => '',
          'timestamp' => NULL,
        ],
        'path' => $templates_path,
        'template' => 'email-shell',
      ],
      'email_shell_text' => [
        'variables' => [
          'body' => '',
          'unsubscribe_url' => '',
          'timestamp' => NULL,
        ],
        'path' => $templates_path,
        'template' => 'email-shell',
      ],
      'digest_html' => [
        'variables' => [
          'day_groups' => [],
          'overflow_line' => NULL,
          'unsubscribe_url' => '',
          'window_label' => '',
          'greeting' => '',
        ],
        'path' => $templates_path,
        'template' => 'digest',
      ],
    ];

    foreach (self::EVENTS as $event) {
      $filename = 'email-' . str_replace('_', '-', $event);

      $hooks['email_' . $event . '_html'] = [
        'variables' => [
          'body_line' => '',
        ],
        'path' => $templates_path,
        'template' => $filename,
      ];
      $hooks['email_' . $event . '_text'] = [
        'variables' => [
          'body_line' => '',
        ],
        'path' => $templates_path,
        'template' => $filename,
      ];
    }

    return $existing + $hooks;
  }

}
