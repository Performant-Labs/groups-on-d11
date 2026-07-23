<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * #126 SD-1 page-level ⓘ tooltips — HelpText `page.*` key coverage.
 *
 * Pins the append-only HelpText contract (per file docblock) for the 10
 * `page.*` surface keys named in brief.md: 5 LIVE (rendered now by PageHelp on
 * the 5 covered routes) + 5 W2 pre-registered (inert until their route
 * exists, per brief.md "Entries whose route does not resolve at request time
 * render nothing"). Also re-confirms the existing silent-empty-string
 * contract for an unknown key (HelpText::get() already guards this — see
 * HelpTextTest::testUnknownKeyReturnsEmptyString() — this adds a
 * page.-namespaced example so the #126 suite is self-contained).
 *
 * RED reason: HelpText::all() has no `page.*` entries yet, so every
 * HelpText::get('page.*') call below falls through to the unknown-key
 * default `''` and every assertNotSame('', ...) fails.
 *
 * @coversDefaultClass \Drupal\do_chrome\HelpText
 */
final class HelpTextPageKeysTest extends TestCase {

  /**
   * The 5 keys backing routes that must render live now (brief.md §Scope).
   *
   * @covers ::get
   */
  public function testLiveRoutePageKeysReturnNonEmptyString(): void {
    foreach ([
      'page.stream',
      'page.all_groups',
      'page.group.stream',
      'page.group.events',
      'page.group.members',
    ] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for live-route key "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * The 5 W2 pre-registered keys (brief.md: "map entry present, inert until
   * route exists"). Copy must exist NOW even though no route resolves to
   * these keys yet — the map's completeness is the point (AC-2: "the map is
   * complete so W2 stories don't need to edit do_chrome").
   *
   * @covers ::get
   */
  public function testW2PreRegisteredPageKeysReturnNonEmptyString(): void {
    foreach ([
      'page.my_feed',
      'page.following',
      'page.trending',
      'page.my_feed_events',
      'page.profile_stream',
    ] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for W2 pre-registered key "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * An unrecognized `page.*` key resolves to '' — the silent-empty contract
   * that lets PageHelp's default-deny gate render nothing rather than warn.
   *
   * @covers ::get
   */
  public function testNonexistentPageKeyReturnsEmptyString(): void {
    $this->assertSame('', HelpText::get('page.nonexistent'));
  }

  /**
   * All 10 `page.*` keys must be present as literal keys in HelpText::all()
   * (not merely resolvable via some other fallback), so a future `page.*`
   * addition follows the same append-only, enumerable contract.
   *
   * @covers ::all
   */
  public function testAllTenPageKeysArePresentInAll(): void {
    $all = HelpText::all();
    foreach ([
      'page.stream',
      'page.all_groups',
      'page.group.stream',
      'page.group.events',
      'page.group.members',
      'page.my_feed',
      'page.following',
      'page.trending',
      'page.my_feed_events',
      'page.profile_stream',
    ] as $key) {
      $this->assertArrayHasKey($key, $all, sprintf('HelpText::all() must contain the "%s" key.', $key));
    }
  }

}
