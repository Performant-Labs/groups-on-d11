<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * Pins the SC-F1 (#119) HelpText keys do_showcase must APPEND (not replace)
 * to \Drupal\do_chrome\HelpText.
 *
 * The brief's Reuse map and Operating rules require the switcher's tooltip
 * copy to come from the existing, single, editable copy store (append-only —
 * "A parallel tooltip/copy mechanism is an anti-duplication BLOCK"). This
 * test pins the SPECIFIC keys this story ships:
 *   - `showcase.switcher.<instance_id>` for the stub switcher instance's ⓘ
 *     tooltip (wireframe.md Surface 1: "what differs between these variants").
 *   - existing do_chrome keys (e.g. demo.foundation) must remain resolvable
 *     unchanged — append-only means no existing key's contract regresses.
 *
 * This test does NOT duplicate HelpTextTest.php (which pins do_chrome's own
 * existing keys) — it pins only the NEW keys this story's Reuse map commits
 * to shipping, plus one non-regression check that appending doesn't disturb
 * an existing key.
 *
 * @group do_showcase
 */
final class ShowcaseHelpTextTest extends TestCase {

  /**
   * The stub switcher instance id this story wires (wireframe.md example).
   */
  private const STUB_INSTANCE_ID = 'directory.layout';

  /**
   * do_showcase appends a resolvable, non-empty, plain-text tooltip key for
   * its stub switcher instance.
   */
  public function testSwitcherTooltipKeyResolves(): void {
    $key = 'showcase.switcher.' . self::STUB_INSTANCE_ID;
    $copy = HelpText::get($key);
    $this->assertNotSame('', $copy, sprintf('The appended HelpText key "%s" for the stub switcher instance must resolve to non-empty copy.', $key));
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (do_chrome.tooltips.js renders with allowHTML disabled).');
  }

  /**
   * The switcher tooltip copy states what differs between the variants (per
   * the issue's own phrasing, wireframe.md: "what differs between these
   * variants") — a truthful, non-generic description, not filler text.
   */
  public function testSwitcherTooltipCopyDescribesWhatDiffers(): void {
    $copy = HelpText::get('showcase.switcher.' . self::STUB_INSTANCE_ID);
    // The stub instance's options are Compact list / Cards / Map (wireframe
    // example) — the copy must name at least the concept of differing
    // presentation, not just restate the instance id.
    $this->assertMatchesRegularExpression(
      '/compact|cards|map|layout|view/i',
      $copy,
      'Tooltip copy must describe what differs between the switcher options, not generic filler.'
    );
  }

  /**
   * Appending do_showcase's key(s) does not disturb an existing do_chrome
   * key's resolution (append-only contract — no parallel/overwritten store).
   */
  public function testExistingDoChromeKeyStillResolvesUnchanged(): void {
    $copy = HelpText::get('demo.foundation');
    $this->assertNotSame('', $copy, 'Existing do_chrome keys must remain resolvable after do_showcase appends its own.');
    $this->assertStringContainsString('tippy.js', $copy, 'The existing demo.foundation copy text must be unchanged.');
  }

  /**
   * An unknown key still resolves to empty string (do_chrome's existing
   * contract), proving do_showcase did not change HelpText::get()'s
   * fallback behavior.
   */
  public function testUnknownKeyStillReturnsEmptyString(): void {
    $this->assertSame('', HelpText::get('no.such.showcase.surface'));
  }

}
