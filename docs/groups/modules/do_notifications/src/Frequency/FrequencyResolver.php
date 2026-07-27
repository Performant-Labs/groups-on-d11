<?php

declare(strict_types=1);

namespace Drupal\do_notifications\Frequency;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves a recipient's notification frequency and next send time.
 *
 * Part of the Notifications epic #237, N-5 (#233). Given a recipient uid,
 * returns the `(frequency, send_at)` pair {@see \Drupal\do_notifications\
 * Subscription\SubscriptionRouter} stamps onto that recipient's queue
 * payload: the recipient's own `field_notification_frequency` value (falling
 * back to the site-wide `do_notifications.settings:default_frequency` when
 * the user is unloadable or the field is empty/missing), paired with the
 * unix timestamp at which a delivery/digest worker should pick up the entry.
 *
 * This is a single-responsibility service deliberately split out of
 * `SubscriptionRouter` (see brief docs/planning/handoffs/
 * 233-n5-frequency-queue/brief.md, "Design — FrequencyResolver"): the
 * frequency policy (per-user preference + boundary time math) is a distinct
 * concern from routing, is cleanly unit-testable in isolation, and is
 * expected to be reused by future digest workers' next-run scheduling
 * (N-6/N-7 may want the same "next 2 AM UTC" / "next Sunday 19:00 UTC"
 * boundary helpers).
 *
 * Fail-soft by design: any Throwable while loading the user, or a NULL user,
 * or a missing/empty `field_notification_frequency`, or an unrecognized
 * frequency value, silently falls back to the site-wide default (itself
 * defaulting to `immediately` when unset) -- matching the router's own
 * "never fail routing on a bad user" contract. No logger is injected; this
 * mirrors the resolver's pure-function feel (the router still logs its own
 * top-level catch), per handoff-A finding #1.
 */
class FrequencyResolver {

  /**
   * The frequency value used when no per-user or site-wide value applies.
   */
  private const DEFAULT_FREQUENCY = 'immediately';

  /**
   * Seconds in a day, used for `daily`/`weekly` boundary rollover math.
   */
  private const SECONDS_PER_DAY = 86400;

  /**
   * The hour (UTC, 24h) at which the daily digest boundary falls.
   */
  private const DAILY_SEND_HOUR = '02:00:00';

  /**
   * The hour (UTC, 24h) at which the weekly digest boundary falls.
   */
  private const WEEKLY_SEND_HOUR = '19:00:00';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Resolves the frequency and next send time for a given recipient.
   *
   * @param int $uid
   *   The recipient's user id.
   *
   * @return array
   *   An array shaped:
   *   - frequency: (string) one of 'immediately', 'daily', 'weekly' (or
   *     whatever site-wide default is configured/falls back to).
   *   - send_at: (int) the unix timestamp at which a delivery/digest worker
   *     should include this entry.
   */
  public function resolve(int $uid): array {
    $frequency = $this->resolveFrequency($uid);
    return [
      'frequency' => $frequency,
      'send_at' => $this->computeSendAt($frequency),
    ];
  }

  /**
   * Resolves the recipient's frequency string, falling back on any failure.
   *
   * @param int $uid
   *   The recipient's user id.
   *
   * @return string
   *   The recipient's `field_notification_frequency` value, or the site-wide
   *   default when the user is unloadable, the field is missing/empty, or
   *   loading throws.
   */
  private function resolveFrequency(int $uid): string {
    try {
      $user = $this->entityTypeManager->getStorage('user')->load($uid);
    }
    catch (\Throwable $e) {
      return $this->siteDefaultFrequency();
    }

    if ($user === NULL) {
      return $this->siteDefaultFrequency();
    }

    if (!$user->hasField('field_notification_frequency') || $user->get('field_notification_frequency')->isEmpty()) {
      return $this->siteDefaultFrequency();
    }

    return (string) $user->get('field_notification_frequency')->value;
  }

  /**
   * Reads the site-wide default frequency, itself defaulting to immediately.
   *
   * @return string
   *   The value of `do_notifications.settings:default_frequency`, or
   *   'immediately' when that config key is unset.
   */
  private function siteDefaultFrequency(): string {
    return $this->configFactory->get('do_notifications.settings')->get('default_frequency') ?? self::DEFAULT_FREQUENCY;
  }

  /**
   * Computes the `send_at` unix timestamp for a given frequency value.
   *
   * Any unrecognized/malformed frequency value is treated as 'immediately'
   * (defensive fallback; never throws, never logs).
   *
   * @param string $frequency
   *   The resolved frequency string.
   *
   * @return int
   *   The unix timestamp at which a worker should pick up the entry.
   */
  private function computeSendAt(string $frequency): int {
    $now = $this->time->getRequestTime();

    return match ($frequency) {
      'daily' => $this->nextDailyBoundary($now),
      'weekly' => $this->nextWeeklyBoundary($now),
      default => $now,
    };
  }

  /**
   * Computes the next 02:00 UTC strictly after `$now`.
   *
   * @param int $now
   *   The reference unix timestamp.
   *
   * @return int
   *   Today's 02:00 UTC if that is strictly after `$now`, otherwise
   *   tomorrow's 02:00 UTC.
   */
  private function nextDailyBoundary(int $now): int {
    $today_boundary = strtotime(gmdate('Y-m-d', $now) . ' ' . self::DAILY_SEND_HOUR . ' UTC');
    return $today_boundary > $now ? $today_boundary : $today_boundary + self::SECONDS_PER_DAY;
  }

  /**
   * Computes the next Sunday 19:00 UTC strictly after `$now`.
   *
   * @param int $now
   *   The reference unix timestamp.
   *
   * @return int
   *   This week's Sunday 19:00 UTC if that is strictly after `$now`,
   *   otherwise next week's Sunday 19:00 UTC.
   */
  private function nextWeeklyBoundary(int $now): int {
    // gmdate('w', $now) returns 0 for Sunday .. 6 for Saturday.
    $day_of_week = (int) gmdate('w', $now);
    $this_sunday_boundary = strtotime(gmdate('Y-m-d', $now) . ' ' . self::WEEKLY_SEND_HOUR . ' UTC') - ($day_of_week * self::SECONDS_PER_DAY);
    return $this_sunday_boundary > $now ? $this_sunday_boundary : $this_sunday_boundary + (7 * self::SECONDS_PER_DAY);
  }

}
