<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Email;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\Url;
use Drupal\message\MessageInterface;
use Drupal\user\UserInterface;

/**
 * Wraps N per-event Message fragments into one digest email payload.
 *
 * #236 (N-8): a sibling to {@see \Drupal\do_notifications\Email\EmailRenderer}
 * (N-4, #232) — reuses that class's
 * {@see \Drupal\do_notifications\Email\EmailRenderer::renderEventFragments()}
 * helper for each per-event fragment rather than duplicating its token
 * substitution / `(removed)`-placeholder machinery (brief §"Design
 * decisions" #3, locked by A). Produces the same
 * `['subject', 'body_text', 'body_html']` shape `EmailRenderer::render()`
 * does, but aggregates multiple `activity_*` Message entities (do_activity,
 * #116) for a single recipient + window ("daily"|"weekly") rather than one.
 *
 * Non-goals (brief): cron/scheduling, queue integration, mailer sends (N-1).
 * The caller (N-9, a future scheduler) supplies `$messages`; this class only
 * renders them.
 *
 * DUAL RENDER PATH — same constraint as EmailRenderer (see that class's
 * docblock "DUAL RENDER PATH"): the `digest_html` theme hook renders through
 * the normal `#theme` + `renderInIsolation()` pipeline, while
 * `digest.txt.twig` is loaded directly through the `twig` environment
 * service (bypassing the theme registry), because Drupal's Twig theme engine
 * hardcodes the `.html.twig` extension and can never load a `.txt.twig` file
 * via the `#theme` pipeline.
 */
class DigestRenderer {

  use StringTranslationTrait;

  /**
   * The maximum number of per-event fragments rendered as bullets.
   *
   * Extra incoming messages beyond this cap are collapsed into a single
   * "…and N more" overflow line rather than rendered (brief §"Cap").
   */
  private const MAX_EVENTS = 50;

  /**
   * The two window values `render()` accepts; anything else fails fast.
   */
  private const VALID_WINDOWS = ['daily', 'weekly'];

  /**
   * Maps a window value to its body-copy noun ('day' | 'week').
   */
  private const WINDOW_LABELS = [
    'daily' => 'day',
    'weekly' => 'week',
  ];

  public function __construct(
    private readonly EmailRenderer $emailRenderer,
    private readonly TwigEnvironment $twig,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Renders a digest payload aggregating N Messages for one recipient.
   *
   * @param \Drupal\message\MessageInterface[] $messages
   *   The activity_* Messages to aggregate. Caller-supplied (a future N-9
   *   scheduler assembles this list); this method does not query for them.
   * @param \Drupal\user\UserInterface $recipient
   *   The user this digest is being rendered for; drives the unsubscribe URL.
   * @param string $window
   *   Either 'daily' or 'weekly'. Any other value throws.
   *
   * @return array
   *   An array with keys `subject`, `body_text`, `body_html` — all strings.
   *
   * @throws \InvalidArgumentException
   *   If `$window` is not 'daily' or 'weekly'.
   */
  public function render(array $messages, UserInterface $recipient, string $window): array {
    if (!in_array($window, self::VALID_WINDOWS, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        "Invalid digest window '%s'; expected one of: %s.",
        $window,
        implode(', ', self::VALID_WINDOWS),
      ));
    }

    $overflow = max(0, count($messages) - self::MAX_EVENTS);
    $capped_messages = array_slice($messages, 0, self::MAX_EVENTS);

    $fragments = $this->collectFragments($capped_messages);
    $day_groups = $this->groupByDay($fragments);

    $window_label = self::WINDOW_LABELS[$window];
    $unsubscribe_url = $this->unsubscribeUrl($recipient);
    $greeting = (string) $this->t('Hi @name,', ['@name' => $recipient->getDisplayName()]);
    $overflow_line = $overflow > 0
      ? (string) $this->t('…and @count more', ['@count' => $overflow])
      : NULL;

    return [
      'subject' => $this->renderSubject($window, count($fragments)),
      'body_text' => $this->renderTextBody($day_groups, $overflow_line, $unsubscribe_url, $window_label, $greeting),
      'body_html' => $this->renderHtmlBody($day_groups, $overflow_line, $unsubscribe_url, $window_label, $greeting),
    ];
  }

  /**
   * Renders each capped Message into its fragment, skipping failures.
   *
   * Per the brief's "Deleted entities" section: EmailRenderer's `(removed)`
   * fallback already covers a Message whose OWN referenced entity has been
   * deleted (it returns a fragment containing the placeholder rather than
   * throwing). This defensive per-message try/catch instead guards against
   * an unrelated Throwable during fragment rendering (e.g. a Message whose
   * bundle/template itself is unresolvable in some other unforeseen way) —
   * a single bad Message must not fail the whole digest.
   *
   * @param \Drupal\message\MessageInterface[] $messages
   *   The already-capped Messages to render.
   *
   * @return array[]
   *   A list of fragment arrays (`text_line`, `html_body`, `created`), one
   *   per successfully rendered Message, in no particular order (grouping/
   *   sorting happens in {@see self::groupByDay()}).
   */
  private function collectFragments(array $messages): array {
    $fragments = [];
    foreach ($messages as $message) {
      if (!$message instanceof MessageInterface) {
        continue;
      }
      try {
        $fragments[] = $this->emailRenderer->renderEventFragments($message);
      }
      catch (\Throwable $e) {
        // Defensive: skip a fragment that fails to render rather than
        // failing the entire digest for every other recipient/message.
        continue;
      }
    }
    return $fragments;
  }

  /**
   * Groups fragments by their UTC calendar day, newest-day-first.
   *
   * Within a day, fragments are ordered newest-first (brief §"Grouping").
   * Fragment shape matches EmailRenderer's own `renderEventFragments()`
   * return value (see that method's docblock).
   *
   * @param array[] $fragments
   *   Fragment arrays keyed by `text_line`, `html_body`, `created`.
   *
   * @return array[]
   *   A list of `['label' => string, 'events' => array[]]` groups, ordered
   *   newest-day-first. `events` holds the fragment arrays for that day,
   *   newest-first.
   */
  private function groupByDay(array $fragments): array {
    $by_day = [];
    foreach ($fragments as $fragment) {
      $day_key = gmdate('Y-m-d', $fragment['created']);
      $by_day[$day_key][] = $fragment;
    }

    // Newest day first.
    krsort($by_day);

    $groups = [];
    foreach ($by_day as $day_key => $day_fragments) {
      // Newest fragment first within the day.
      usort($day_fragments, static fn(array $a, array $b): int => $b['created'] <=> $a['created']);

      // Midday UTC anchor for the day label so DST/offset concerns never
      // shift the calendar date the label describes (this class only ever
      // formats a day key it derived from `gmdate('Y-m-d', ...)` above).
      $day_timestamp = strtotime($day_key . ' 12:00:00 UTC');
      $label = $this->dateFormatter->format($day_timestamp, 'custom', 'l, F j, Y', 'UTC');

      $groups[] = [
        'label' => $label,
        'events' => $day_fragments,
      ];
    }

    return $groups;
  }

  /**
   * Derives the digest subject line.
   *
   * @param string $window
   *   Either 'daily' or 'weekly'.
   * @param int $count
   *   The cap-clamped count of fragments actually rendered as bullets.
   */
  private function renderSubject(string $window, int $count): string {
    return (string) $this->t('Your @window summary — @count updates', [
      '@window' => $window,
      '@count' => $count,
    ]);
  }

  /**
   * Builds the absolute unsubscribe URL for the recipient.
   *
   * Identical pattern to
   * {@see \Drupal\do_notifications\Email\EmailRenderer}'s own (private)
   * `unsubscribeUrl()` — duplicated here (rather than exposed as a shared
   * helper) since it is a single `Url::fromRoute()` call with no other logic
   * to reuse.
   */
  private function unsubscribeUrl(UserInterface $recipient): string {
    return Url::fromRoute('do_notifications.notification_settings', ['user' => $recipient->id()])
      ->setAbsolute()
      ->toString();
  }

  /**
   * Renders the HTML digest body via the theme registry.
   *
   * Uses the standard `#theme` + `renderInIsolation()` pipeline — see class
   * docblock "DUAL RENDER PATH".
   */
  private function renderHtmlBody(array $day_groups, ?string $overflow_line, string $unsubscribe_url, string $window_label, string $greeting): string {
    $build = [
      '#theme' => 'digest_html',
      '#day_groups' => $day_groups,
      '#overflow_line' => $overflow_line,
      '#unsubscribe_url' => $unsubscribe_url,
      '#window_label' => $window_label,
      '#greeting' => $greeting,
    ];

    return (string) $this->renderer()->renderInIsolation($build);
  }

  /**
   * Renders the plain-text digest body directly through Twig.
   *
   * Bypasses the theme registry entirely (see class docblock "DUAL RENDER
   * PATH" — Drupal's Twig theme engine hardcodes `.html.twig` and can never
   * load a `.txt.twig` file). Loads `digest.txt.twig` directly via the
   * `twig` environment service, using a module-root-relative path so Twig's
   * filesystem loader (rooted at the Drupal app root) resolves it the same
   * way theme-registry templates are resolved.
   */
  private function renderTextBody(array $day_groups, ?string $overflow_line, string $unsubscribe_url, string $window_label, string $greeting): string {
    $templates_path = $this->moduleExtensionList->getPath('do_notifications') . '/templates/emails';

    return $this->twig->load($templates_path . '/digest.txt.twig')
      ->render([
        'day_groups' => $day_groups,
        'overflow_line' => $overflow_line,
        'unsubscribe_url' => $unsubscribe_url,
        'window_label' => $window_label,
        'greeting' => $greeting,
      ]);
  }

  /**
   * Returns the render service.
   *
   * `RendererInterface` is not injected as a constructor promoted property
   * because the constructor signature is locked by the brief (`EmailRenderer`,
   * `TwigEnvironment`, `ModuleExtensionList`, `DateFormatterInterface` only —
   * no `RendererInterface`); `#theme`-driven HTML rendering instead borrows
   * the globally available renderer service directly, matching the pattern
   * `\Drupal::service()` calls use elsewhere in this codebase (e.g.
   * `DoNotificationsHooks::commentInsert()`) when a dependency is not part
   * of a class's locked constructor signature.
   *
   * @return \Drupal\Core\Render\RendererInterface
   *   The renderer service.
   */
  private function renderer(): RendererInterface {
    return \Drupal::service('renderer');
  }

}
