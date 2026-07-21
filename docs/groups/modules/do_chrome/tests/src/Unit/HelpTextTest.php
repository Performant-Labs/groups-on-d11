<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the centralized tooltip copy source.
 *
 * CH-F1 (#79). HelpText is the single, editable copy source every tooltip
 * surface (#88-#92) reads from. This pins the foundation contract:
 *  - the foundation demo key resolves to non-empty plain text,
 *  - unknown keys resolve to '' (so a mis-keyed surface renders no tooltip,
 *    never a PHP warning),
 *  - all() returns a string=>string map.
 *
 * @coversDefaultClass \Drupal\do_chrome\HelpText
 */
final class HelpTextTest extends TestCase {

  /**
   * @covers ::get
   */
  public function testFoundationDemoCopyIsPresent(): void {
    $copy = HelpText::get('demo.foundation');
    $this->assertNotSame('', $copy, 'The foundation demo tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
  }

  /**
   * @covers ::get
   */
  public function testUnknownKeyReturnsEmptyString(): void {
    $this->assertSame('', HelpText::get('no.such.surface'));
  }

  /**
   * The #90 multi-group audience copy is present and plain text.
   *
   * @covers ::get
   */
  public function testAudienceCopyIsPresent(): void {
    $copy = HelpText::get('audience.fieldset');
    $this->assertNotSame('', $copy, 'The #90 audience tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    $this->assertStringContainsString('more than one group', $copy, 'Copy must match the #81 deck (section D).');
  }

  /**
   * The #92 archive/pin/promote/follow copy exists and is plain text.
   *
   * Only WIRED controls ship copy (verified in the #81 spike): archive badge,
   * pin badge, promote (`promote_homepage` -> Promoted Content listing), follow
   * (`follow_content` -> notifications).
   *
   * @covers ::get
   */
  public function testArchivePinControlCopyIsPresentAndPlainText(): void {
    foreach (['archive.badge', 'pin.badge', 'promote.control', 'follow.control'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * No "flag/report" control copy ships — no such moderation target exists.
   *
   * The #81 copy deck listed a "Flag" (report-to-admins) control marked
   * verify-before-ship; the demo has no report/abuse flag, so its copy is
   * intentionally omitted rather than describing unwired behavior.
   *
   * @covers ::get
   */
  public function testReportFlagControlCopyIsOmitted(): void {
    $all = HelpText::all();
    foreach (array_keys($all) as $key) {
      $this->assertStringNotContainsString('report', $key, 'No report/moderation flag tooltip should ship.');
    }
    $this->assertSame('', HelpText::get('flag.control'));
    $this->assertSame('', HelpText::get('report.control'));
  }

  /**
   * #89 (CH-B2): the Group Type field ⓘ names every seeded group type.
   *
   * @covers ::get
   */
  public function testGroupTypeFieldCopyNamesAllTypes(): void {
    $copy = HelpText::get('group_type.field');
    $this->assertNotSame('', $copy, 'The group_type.field tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    foreach (['Geographical', 'Working group', 'Distribution', 'Event planning', 'Archive'] as $type) {
      $this->assertStringContainsString($type, $copy, "Group Type help must name the '$type' type.");
    }
  }

  /**
   * #89 (CH-B2): the content-type field ⓘ names every group content type.
   *
   * @covers ::get
   */
  public function testContentTypeFieldCopyNamesAllTypes(): void {
    $copy = HelpText::get('content_type.field');
    $this->assertNotSame('', $copy, 'The content_type.field tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    foreach (['Forum', 'Documentation', 'Event', 'Post', 'Page'] as $type) {
      $this->assertStringContainsString($type, $copy, "Content-type help must name the '$type' type.");
    }
  }

  /**
   * @covers ::all
   */
  public function testAllReturnsStringMap(): void {
    $all = HelpText::all();
    $this->assertArrayHasKey('demo.foundation', $all);
    foreach ($all as $key => $value) {
      $this->assertIsString($key);
      $this->assertIsString($value);
    }
  }

}
