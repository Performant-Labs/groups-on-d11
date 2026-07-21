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
