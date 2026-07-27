<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\do_notifications\Email\DigestRenderer;
use Drupal\do_notifications\Queue\DigestQueueBackendInterface;
use Drupal\do_notifications\Queue\QueueBackendInterface;
use Drupal\message\MessageInterface;
use Drush\Attributes\Command;
use Drush\Commands\DrushCommands;

/**
 * The `do_notifications:digest-daily` worker (#234, N-6).
 *
 * Part of the Notifications epic #237. Nightly (default 02:00 UTC — see
 * `wire via` note below), aggregates every `frequency='daily'` entry queued
 * in `do_notifications_queue` older than a configurable window (default
 * 24h) per recipient, renders one aggregated digest per user via N-8's
 * {@see DigestRenderer} (reused verbatim — its own 50-fragment cap already
 * applies), and enqueues one row per user into the `do_notifications_digest_
 * queue` table for the (not-yet-merged) N-3 delivery worker to eventually
 * consume and send. Deletes the consumed source rows from
 * `do_notifications_queue` once each user's digest is successfully
 * rendered/enqueued — see {@see QueueBackendInterface}'s class docblock for
 * why the claim (`claimDaily()`) and the delete (`deleteByIds()`) are
 * separate calls (mid-run failure idempotency).
 *
 * Uses `Drush\Attributes\Command` (the "annotated command" attribute, method
 * signature `digestDaily(): array` with no `Input`/`Output` parameters) —
 * deliberately NOT the newer `Symfony\Component\Console\Attribute\AsCommand`
 * class-level style Drush core itself has migrated to. That style requires
 * an `execute(InputInterface, OutputInterface): int` method whose return
 * value is a shell exit code, with any result data written to `$output`
 * rather than returned — awkward to kernel-test directly (a kernel test has
 * no drush process/IO boundary) and a needless layer for a command whose
 * only "output" this story cares about is a plain summary array a kernel
 * test (and a human running `drush do_notifications:digest-daily`, via
 * Drush's own default array-pretty-printer) can consume directly. The
 * `#[Command]` attribute is deprecated in favor of `#[AsCommand]` but
 * remains fully functional in this Drush version — see
 * `vendor/drush/drush/src/Attributes/Command.php`.
 *
 * Registered in BOTH `do_notifications.services.yml`
 * (`do_notifications.digest_command`, so `\Drupal::service()` resolves it —
 * required for this class to be kernel-testable and for any future
 * non-CLI caller) AND `drush.services.yml` (tagged `drush.command`, so
 * `drush do_notifications:digest-daily` is discoverable on the command
 * line). See both files' inline comments — `drush.services.yml`'s legacy
 * DI mechanism instantiates into a container Drush never merges back into
 * Drupal's own, so a service registered there alone is invisible to
 * `\Drupal::service()`; registering in only `do_notifications.services.yml`
 * would conversely be invisible to Drush's own CLI command discovery
 * (`DrupalBoot8::bootstrap()` only scans `drush.services.yml`-sourced
 * services for the `drush.command` tag).
 *
 * Non-goals (brief): no email sending (N-3's job), no weekly digest (N-7,
 * a future story reusing this same `do_notifications_digest_queue` table
 * with `window='weekly'`), no cron entry (an ops concern — wire this command
 * up with `drush cron:add do_notifications:digest-daily "0 2 * * *"`), no
 * re-render at delivery time (the rendered body is stored at digest time),
 * no user-timezone offset (UTC only, per brief).
 */
class DigestCommands extends DrushCommands {

  /**
   * The default digest window, in seconds: 24 hours.
   *
   * Overridable via
   * `state.set('do_notifications.digest_daily.window_seconds', N)` (AC9) —
   * tests use this to compress the window without waiting real time.
   */
  private const DEFAULT_WINDOW_SECONDS = 86400;

  public function __construct(
    private readonly QueueBackendInterface $queue,
    private readonly DigestQueueBackendInterface $digestQueue,
    private readonly DigestRenderer $digestRenderer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {
    parent::__construct();
  }

  /**
   * Aggregates queued daily notifications into one digest email per user.
   *
   * @return array
   *   A summary shaped `['users_digested' => int, 'items_consumed' => int,
   *   'digests_enqueued' => int]`:
   *   - users_digested: the count of distinct users who received at least
   *     one enqueued digest row.
   *   - items_consumed: the count of ORIGINAL `do_notifications_queue` rows
   *     deleted, including orphaned rows whose `mid` no longer resolves to a
   *     Message (garbage-collected rather than left to retry forever).
   *   - digests_enqueued: the count of rows inserted into
   *     `do_notifications_digest_queue`.
   */
  #[Command(name: 'do_notifications:digest-daily')]
  public function digestDaily(): array {
    $window_seconds = (int) $this->state->get('do_notifications.digest_daily.window_seconds', self::DEFAULT_WINDOW_SECONDS);
    $threshold = $this->time->getRequestTime() - $window_seconds;

    $rows = $this->queue->claimDaily($threshold);

    $rows_by_uid = [];
    foreach ($rows as $row) {
      $rows_by_uid[$row['uid']][] = $row;
    }

    $users_digested = 0;
    $digests_enqueued = 0;
    $consumed_ids = [];

    foreach ($rows_by_uid as $uid => $user_rows) {
      $consumed_ids = array_merge($consumed_ids, $this->digestUser((int) $uid, $user_rows, $users_digested, $digests_enqueued));
    }

    $this->queue->deleteByIds($consumed_ids);

    return [
      'users_digested' => $users_digested,
      'items_consumed' => count($consumed_ids),
      'digests_enqueued' => $digests_enqueued,
    ];
  }

  /**
   * Digests a single user's claimed rows, mutating the two running tallies.
   *
   * Loads each row's Message by `mid`, dropping (and marking for deletion)
   * any row whose Message no longer exists — an orphaned row does not block
   * digesting the user's other valid messages. If zero valid messages
   * remain (all rows orphaned), the user is skipped entirely (no empty
   * digest), but the orphaned rows are still returned for deletion so they
   * do not accumulate forever.
   *
   * @param int $uid
   *   The recipient user id.
   * @param array[] $rows
   *   This user's claimed queue rows (see
   *   {@see \Drupal\do_notifications\Queue\QueueBackendInterface::claimDaily()}
   *   for the row shape).
   * @param int $users_digested
   *   Running tally of users who received a digest; incremented by
   *   reference when this user gets one.
   * @param int $digests_enqueued
   *   Running tally of digest rows enqueued; incremented by reference when
   *   this user gets one.
   *
   * @return int[]
   *   The queue row ids to delete for this user: every row whose Message
   *   was found and successfully digested, PLUS every orphaned row,
   *   regardless of whether a digest was ultimately produced. Empty only
   *   when the user had zero claimed rows to begin with (never actually
   *   called in that case, since {@see self::digestDaily()} only calls this
   *   for uids present in the claimed rows).
   */
  private function digestUser(int $uid, array $rows, int &$users_digested, int &$digests_enqueued): array {
    $message_storage = $this->entityTypeManager->getStorage('message');

    $mids = array_map(static fn(array $row): int => $row['mid'], $rows);
    /** @var \Drupal\message\MessageInterface[] $loaded_messages */
    $loaded_messages = $message_storage->loadMultiple($mids);

    $valid_messages = [];
    $row_ids_to_delete = [];
    foreach ($rows as $row) {
      $row_ids_to_delete[] = $row['id'];
      $message = $loaded_messages[$row['mid']] ?? NULL;
      if ($message instanceof MessageInterface) {
        // A Message freshly loaded from storage can surface its `created`
        // timestamp field's `->value` as a numeric STRING rather than an
        // int (a real, pre-existing quirk of core's 'timestamp' TypedData
        // property definition on a DB-round-tripped value — confirmed by
        // reading web/core/lib/Drupal/Core/Field/Plugin/Field/FieldType/
        // TimestampItem.php; an in-memory-only Message that was never
        // reloaded, as every existing DigestRenderer/EmailRenderer test
        // fixture happens to use, never surfaces this because `set()` with
        // an int argument keeps it an int for that object's lifetime).
        // DigestRenderer -> EmailRenderer::renderEventFragments() passes
        // this value straight into `gmdate()`, which requires `?int` and
        // throws a TypeError on a string. This command is the first caller
        // to hand DigestRenderer a genuinely storage-reloaded Message, so
        // normalize it here at the boundary this story owns, rather than
        // editing the shipped N-4/N-8 rendering classes (out of this
        // story's scope; brief: "REUSE VERBATIM"). setCreatedTime() only
        // mutates the in-memory typed-data value on this transient,
        // never-saved object — no persistence side effect.
        $message->setCreatedTime((int) $message->getCreatedTime());
        $valid_messages[] = $message;
      }
      // Else: orphaned row (mid no longer resolves to a Message) — dropped
      // from the digest, but its id is still queued for deletion above so
      // it is garbage-collected rather than retried forever.
    }

    if (empty($valid_messages)) {
      // Zero valid messages for this user (either every row was orphaned,
      // or — unreachable via digestDaily()'s own grouping, but defensive
      // regardless — there were no rows at all): no empty-digest email.
      return $row_ids_to_delete;
    }

    $user_storage = $this->entityTypeManager->getStorage('user');
    $recipient = $user_storage->load($uid);
    if ($recipient === NULL) {
      // The recipient itself is unloadable: nothing sensible to render a
      // digest for, but the claimed rows are still consumed (garbage
      // collection applies here too — a user that no longer exists will
      // never become loadable again).
      return $row_ids_to_delete;
    }

    $digest = $this->digestRenderer->render($valid_messages, $recipient, 'daily');

    $this->digestQueue->enqueue(
      $uid,
      'daily',
      $digest['subject'],
      $digest['body_text'],
      $digest['body_html'],
      $this->time->getRequestTime(),
    );

    $users_digested++;
    $digests_enqueued++;

    return $row_ids_to_delete;
  }

}
