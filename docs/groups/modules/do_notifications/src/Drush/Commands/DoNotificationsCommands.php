<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\do_notifications\Email\DigestRenderer;
use Drupal\do_notifications\Email\EmailRenderer;
use Drupal\do_notifications\Queue\QueueBackendInterface;
use Drupal\message\MessageInterface;
use Drupal\message_notify\Plugin\Notifier\Manager as MessageNotifierManager;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drains `do_notifications.queue` and delivers via the `log_mailer` notifier.
 *
 * Part of the Notifications epic #237, N-3 (#231). This is the wiring story:
 * it does not implement queueing (N-1/N-2, #229/#230), rendering (N-4/N-8,
 * #232/#236), or delivery (N-1, #229) itself — it drains the queue backend,
 * calls the two renderers, and invokes the `log_mailer` `message_notify`
 * plugin, per the brief's Reuse map (extend, do not duplicate, each of those
 * four seams).
 *
 * Frequency handling: `--type=all` (the default) drains the ENTIRE queue in
 * one call ({@see QueueBackendInterface::drain()} with no argument) and then
 * groups the drained items by their own `frequency` field, so a single run
 * still delivers every frequency in one atomic drain rather than three
 * separate drain() calls that could interleave with a concurrent enqueue.
 * `--type=immediately|daily|weekly` instead drains ONLY that frequency,
 * leaving every other frequency's entries queued for a later run (brief AC
 * #2).
 *
 * Delivery shapes (brief AC #3):
 *   - `immediately`: each drained entry is its own delivery — load the
 *     Message + recipient, call {@see EmailRenderer::render()} (the payload
 *     is discarded; `log_mailer` never reads rendered content, only the
 *     `{uid, mid, template}` identifiers — see
 *     {@see self::deliverImmediately()}), then invoke `log_mailer` once per
 *     entry.
 *   - `daily` / `weekly`: entries are grouped by recipient uid; for each
 *     recipient, every surviving Message they have queued for that window is
 *     loaded and handed to {@see DigestRenderer::render()} as ONE call, then
 *     `log_mailer` is invoked ONCE per recipient — never once per message —
 *     using the FIRST surviving message id as the log identifier (brief AC
 *     #3's "mid: first-mid" contract; A's handoff finding #2 notes this is a
 *     lossy collapse for a future digest-audit query, deferred — see this
 *     class's own {@see self::deliverDigestGroup()} docblock).
 *
 * A drained entry whose Message or recipient no longer loads is skipped (not
 * failed) with a `do_notifications` watchdog WARNING (brief AC #5) — see
 * {@see self::loadMessage()} / {@see self::loadRecipient()}. An empty drain
 * is a clean no-op (brief AC #4): the grouping/delivery loops below simply
 * never execute when `drain()` returns `[]`.
 *
 * OPTIONAL `$notifierManager` — #231 (N-3) discovery: `message_notify` IS a
 * hard `.info.yml` dependency of `do_notifications` (so on a real site
 * `plugin.message_notify.notifier.manager` always exists), but several of
 * this module's OWN kernel test suites (`DatabaseQueueBackendTest`,
 * `DigestRendererTest`, `EmailRendererTest`) deliberately enable
 * `do_notifications` WITHOUT enabling `message_notify`, to keep their own
 * fixture footprint minimal — they never touch this command. Symfony's DI
 * container validates every registered service's references at COMPILE
 * time, regardless of whether that service is ever instantiated, so
 * registering this class with a hard (non-`?`) reference to
 * `plugin.message_notify.notifier.manager` in `do_notifications.services.yml`
 * breaks container compilation for every one of those sibling suites, not
 * just ones that use this command. Declaring the constructor parameter
 * nullable + referencing it as `'@?plugin.message_notify.notifier.manager'`
 * (do_notifications.services.yml) lets the container tolerate that module's
 * absence for suites that never call {@see self::deliver()}; `deliver()`
 * itself still guards against a genuinely NULL manager (see
 * {@see self::notifierManagerOrFail()}), which can only actually be reached
 * on a mis-provisioned site missing its own declared hard dependency.
 */
class DoNotificationsCommands extends DrushCommands {

  /**
   * The `--type` option values this command accepts.
   */
  private const VALID_TYPES = ['all', 'immediately', 'daily', 'weekly'];

  /**
   * The queue frequencies that deliver individually (one entry = one email).
   */
  private const INDIVIDUAL_FREQUENCY = 'immediately';

  /**
   * The queue frequencies that deliver as a grouped digest (one per uid).
   *
   * Maps each digest frequency to the `DigestRenderer::render()` `$window`
   * argument it corresponds to — both currently equal the frequency name
   * itself, but the map keeps the two concepts (queue `frequency` column vs.
   * digest `$window` parameter) named separately at the one call site that
   * bridges them, rather than assuming they will always be spelled alike.
   */
  private const DIGEST_WINDOWS = [
    'daily' => 'daily',
    'weekly' => 'weekly',
  ];

  /**
   * Constructs the do_notifications:deliver command.
   *
   * @param \Drupal\do_notifications\Queue\QueueBackendInterface $queue
   *   The notification queue backend.
   * @param \Drupal\do_notifications\Email\EmailRenderer $emailRenderer
   *   The per-event email renderer.
   * @param \Drupal\do_notifications\Email\DigestRenderer $digestRenderer
   *   The digest email renderer.
   * @param \Drupal\message_notify\Plugin\Notifier\Manager|null $notifierManager
   *   The message_notify notifier plugin manager, used to invoke log_mailer.
   *   Nullable — see class docblock "OPTIONAL `$notifierManager`". Always
   *   non-NULL on a real site (message_notify is a hard `.info.yml`
   *   dependency); only NULL in a test container that enables
   *   `do_notifications` without `message_notify`.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load Messages and recipient Users.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $notificationsLogger
   *   The `do_notifications` watchdog logger channel. Named distinctly from
   *   `$logger` (not `$logger`) because the parent `DrushCommands` class
   *   already declares its OWN non-readonly `$logger` property via
   *   `Psr\Log\LoggerAwareTrait` (Drush's own console-output logger) — a
   *   constructor-promoted `$logger` here would fatal
   *   ("Cannot redeclare non-readonly property ... as readonly") rather than
   *   override it.
   */
  public function __construct(
    private readonly QueueBackendInterface $queue,
    private readonly EmailRenderer $emailRenderer,
    private readonly DigestRenderer $digestRenderer,
    private readonly ?MessageNotifierManager $notifierManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerChannelInterface $notificationsLogger,
  ) {
    parent::__construct();
  }

  /**
   * Drains queued notifications and delivers each via the log_mailer plugin.
   *
   * @param array $options
   *   The command options.
   *
   * @throws \InvalidArgumentException
   *   If `$options['type']` is not one of 'all', 'immediately', 'daily', or
   *   'weekly'.
   * @throws \RuntimeException
   *   If the `message_notify` notifier plugin manager is unavailable (a
   *   mis-provisioned site missing its own declared hard dependency — see
   *   class docblock "OPTIONAL `$notifierManager`").
   */
  #[CLI\Command(name: 'do_notifications:deliver', aliases: ['do:notif:deliver'])]
  #[CLI\Option(name: 'type', description: "One of 'all', 'immediately', 'daily', 'weekly'. Defaults to 'all'.")]
  public function deliver(array $options = ['type' => 'all']): void {
    $type = $options['type'] ?? 'all';
    if (!in_array($type, self::VALID_TYPES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        "Invalid --type '%s'; expected one of: %s.",
        $type,
        implode(', ', self::VALID_TYPES),
      ));
    }

    $frequency_filter = $type === 'all' ? NULL : $type;
    $drained = $this->queue->drain($frequency_filter);
    if (empty($drained)) {
      // Brief AC #4 (idempotency): nothing queued -> nothing to do.
      return;
    }

    // Fail fast, before any delivery work, if the notifier manager is
    // genuinely unavailable — see class docblock "OPTIONAL
    // `$notifierManager`". Checked here (once, up front) rather than at
    // every individual createInstance() call site.
    $notifier_manager = $this->notifierManagerOrFail();

    $by_frequency = $this->groupByFrequency($drained);

    if (isset($by_frequency[self::INDIVIDUAL_FREQUENCY])) {
      foreach ($by_frequency[self::INDIVIDUAL_FREQUENCY] as $entry) {
        $this->deliverImmediately($entry, $notifier_manager);
      }
    }

    foreach (self::DIGEST_WINDOWS as $frequency => $window) {
      if (!isset($by_frequency[$frequency])) {
        continue;
      }
      $this->deliverDigestGroup($by_frequency[$frequency], $window, $notifier_manager);
    }
  }

  /**
   * Returns the notifier manager, or throws if it is unavailable.
   *
   * @return \Drupal\message_notify\Plugin\Notifier\Manager
   *   The non-NULL notifier manager.
   *
   * @throws \RuntimeException
   *   If the injected notifier manager is NULL.
   */
  private function notifierManagerOrFail(): MessageNotifierManager {
    if ($this->notifierManager === NULL) {
      throw new \RuntimeException(
        "The 'plugin.message_notify.notifier.manager' service is unavailable — the message_notify module (a hard dependency of do_notifications) does not appear to be enabled.",
      );
    }
    return $this->notifierManager;
  }

  /**
   * Splits drained entries into per-frequency buckets, preserving order.
   *
   * @param array[] $drained
   *   The entries {@see QueueBackendInterface::drain()} returned.
   *
   * @return array<string, array[]>
   *   Entries keyed by their own `frequency` field, each bucket in the same
   *   relative order `drain()` returned them in.
   */
  private function groupByFrequency(array $drained): array {
    $by_frequency = [];
    foreach ($drained as $entry) {
      $by_frequency[$entry['frequency']][] = $entry;
    }
    return $by_frequency;
  }

  /**
   * Delivers one `immediately`-frequency queue entry as its own email.
   *
   * Brief AC #3: load the Message, load the recipient, call
   * {@see EmailRenderer::render()}, then invoke `log_mailer` with the
   * `{uid, mid, template}` output payload. `render()`'s return value is
   * intentionally discarded — `log_mailer` (this project's placeholder
   * transport) never reads rendered subject/body content, only the
   * identifying fields it logs (see
   * {@see \Drupal\do_notifications\Plugin\Notifier\LogMailer::deliver()}).
   * The call still happens unconditionally for every surviving entry so this
   * worker genuinely exercises the rendering pipeline it wires together, not
   * merely the identifiers that happen to flow through it.
   *
   * @param array $entry
   *   One drained queue entry (`uid`, `mid`, `template`, `frequency`, `day`).
   * @param \Drupal\message_notify\Plugin\Notifier\Manager $notifierManager
   *   The (already NULL-checked) notifier manager.
   */
  private function deliverImmediately(array $entry, MessageNotifierManager $notifierManager): void {
    $message = $this->loadMessage((int) $entry['mid'], (int) $entry['uid']);
    if ($message === NULL) {
      return;
    }
    $recipient = $this->loadRecipient((int) $entry['uid'], (int) $entry['mid']);
    if ($recipient === NULL) {
      return;
    }

    $this->emailRenderer->render($message, $recipient);

    $notifier = $notifierManager->createInstance('log_mailer', [], $message);
    $notifier->deliver([
      'uid' => (int) $entry['uid'],
      'mid' => (int) $entry['mid'],
      'template' => $entry['template'],
    ]);
  }

  /**
   * Delivers one recipient's grouped digest for a batch of same-uid entries.
   *
   * Brief AC #3 (digest): groups the given SAME-frequency entries by
   * recipient uid, loads that recipient's user account once, loads every
   * surviving Message queued for them, calls
   * {@see DigestRenderer::render()} ONCE with the whole list, then invokes
   * `log_mailer` ONCE per recipient (never once per message) — the digest is
   * one logical email.
   *
   * `mid` identifier choice: uses the FIRST SURVIVING message id (the first
   * entry, in enqueue order, whose Message actually loaded) rather than the
   * first entry's mid unconditionally — a recipient whose very first queued
   * entry references a since-deleted Message must not have their entire
   * digest's log identifier point at a message log_mailer never rendered
   * anything for. A's handoff (finding #2) already flags that collapsing N
   * message ids down to one is inherently lossy for a future digest-audit
   * query once a real mailer replaces log_mailer; that trade-off is accepted
   * for this POC and unaffected by this "surviving" refinement.
   *
   * @param array[] $entries
   *   All entries of ONE frequency (e.g. every drained 'daily' entry, any
   *   recipient) — this method does its own per-recipient grouping.
   * @param string $window
   *   The {@see DigestRenderer::render()} `$window` argument ('daily' |
   *   'weekly') this frequency maps to.
   * @param \Drupal\message_notify\Plugin\Notifier\Manager $notifierManager
   *   The (already NULL-checked) notifier manager.
   */
  private function deliverDigestGroup(array $entries, string $window, MessageNotifierManager $notifierManager): void {
    $by_uid = [];
    foreach ($entries as $entry) {
      $by_uid[(int) $entry['uid']][] = $entry;
    }

    foreach ($by_uid as $uid => $uid_entries) {
      $recipient = $this->entityTypeManager->getStorage('user')->load($uid);
      if (!$recipient instanceof UserInterface) {
        $this->notificationsLogger->warning(
          'Skipping delivery for uid=@uid mid=@mid: @reason',
          [
            '@uid' => $uid,
            '@mid' => $uid_entries[0]['mid'],
            '@reason' => 'recipient user no longer exists',
          ],
        );
        continue;
      }

      $messages = [];
      $first_surviving_mid = NULL;
      foreach ($uid_entries as $entry) {
        $mid = (int) $entry['mid'];
        $message = $this->loadMessage($mid, $uid);
        if ($message === NULL) {
          continue;
        }
        $messages[] = $message;
        if ($first_surviving_mid === NULL) {
          $first_surviving_mid = $mid;
        }
      }

      if (empty($messages)) {
        // Every entry in this recipient's group referenced a Message that no
        // longer loads — loadMessage() already logged one warning per
        // missing entry; there is nothing left to digest for them.
        continue;
      }

      $this->digestRenderer->render($messages, $recipient, $window);

      $notifier = $notifierManager->createInstance('log_mailer', [], $messages[0]);
      $notifier->deliver([
        'uid' => $uid,
        'mid' => $first_surviving_mid,
        'template' => 'digest_' . $window,
      ]);
    }
  }

  /**
   * Loads a Message by id, logging + returning NULL when it no longer exists.
   *
   * Brief AC #5: a drained entry whose Message no longer exists must be
   * skipped (not failed), with a `do_notifications` watchdog WARNING.
   *
   * @param int $mid
   *   The Message id to load.
   * @param int $uid
   *   The recipient uid this entry was queued for — included in the warning
   *   for operator triage; not used to load the Message itself.
   *
   * @return \Drupal\message\MessageInterface|null
   *   The loaded Message, or NULL if it no longer exists.
   */
  private function loadMessage(int $mid, int $uid): ?MessageInterface {
    $message = $this->entityTypeManager->getStorage('message')->load($mid);
    if (!$message instanceof MessageInterface) {
      $this->notificationsLogger->warning(
        'Skipping delivery for uid=@uid mid=@mid: @reason',
        ['@uid' => $uid, '@mid' => $mid, '@reason' => 'message no longer exists'],
      );
      return NULL;
    }
    return $message;
  }

  /**
   * Loads a recipient User by uid, logging + returning NULL when missing.
   *
   * Brief AC #5: a drained entry whose recipient no longer exists must be
   * skipped (not failed), with a `do_notifications` watchdog WARNING.
   *
   * @param int $uid
   *   The recipient uid to load.
   * @param int $mid
   *   The Message id this entry was queued for — included in the warning for
   *   operator triage; not used to load the user itself.
   *
   * @return \Drupal\user\UserInterface|null
   *   The loaded User, or NULL if it no longer exists.
   */
  private function loadRecipient(int $uid, int $mid): ?UserInterface {
    $recipient = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$recipient instanceof UserInterface) {
      $this->notificationsLogger->warning(
        'Skipping delivery for uid=@uid mid=@mid: @reason',
        ['@uid' => $uid, '@mid' => $mid, '@reason' => 'recipient user no longer exists'],
      );
      return NULL;
    }
    return $recipient;
  }

}
