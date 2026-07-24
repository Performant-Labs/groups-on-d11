<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * Targeted unit coverage for the new `stream.my_feed` HelpText entry.
 *
 * Issue #110 (ST-1 My Feed at /my-feed), acceptance criterion AC-10:
 * "HelpText entry `stream.my_feed` exists (append-only; no existing entries
 * mutated)."
 *
 * A NEW file (mirroring the existing `HelpTextTest.php`'s per-surface test
 * pattern, e.g. `testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants()`)
 * rather than editing the shared `HelpTextTest.php` — this story's own
 * non-negotiables forbid rewriting existing test files, and
 * `docs/planning/handoffs/WAVE-EXECUTION-HANDOFF.md` §3 flags
 * `do_chrome/src/HelpText.php` as shared-file contention across many
 * concurrent stories, so a dedicated file avoids merge collisions on the
 * shared suite.
 *
 * RED reason: `stream.my_feed` is not yet appended to `HelpText::all()` (the
 * brief names it as new, appended by F in this story), so `HelpText::get()`
 * falls through to the unknown-key default `''` and every assertion below
 * fails.
 *
 * @coversDefaultClass \Drupal\do_chrome\HelpText
 */
final class MyFeedHelpTextTest extends TestCase {

  /**
   * AC-10: `stream.my_feed` resolves to a non-empty, plain-text string.
   *
   * @covers ::get
   */
  public function testStreamMyFeedCopyIsPresentAndPlainText(): void {
    $copy = HelpText::get('stream.my_feed');
    $this->assertNotSame('', $copy, 'The stream.my_feed tooltip copy must exist (AC-10).');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
  }

  /**
   * AC-10 (append-only guard): the key is present in the literal array, not
   * merely resolvable via some fallback, and every PRE-EXISTING key this
   * suite already knows about is untouched (proxy for "no existing entries
   * mutated" — a full diff is F's/A's job, but this is a cheap regression
   * guard any test tier can carry).
   *
   * @covers ::all
   */
  public function testStreamMyFeedKeyPresentAndFoundationKeyUnchanged(): void {
    $all = HelpText::all();
    $this->assertArrayHasKey('stream.my_feed', $all, 'stream.my_feed must be a literal key in HelpText::all().');

    // Append-only spot check: the pre-existing foundation key's copy string
    // must be byte-identical to what HelpTextTest.php already pins, proving
    // this story did not mutate an existing entry while appending its own.
    $this->assertSame(
      'do_chrome is active: this tooltip is served by the locally-bundled tippy.js library (no CDN).',
      HelpText::get('demo.foundation'),
      'Appending stream.my_feed must not mutate the pre-existing demo.foundation entry.',
    );
  }

}
