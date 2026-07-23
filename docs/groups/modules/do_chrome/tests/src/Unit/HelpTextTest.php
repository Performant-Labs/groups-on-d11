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
   * The #88 per-option visibility copy exists, is plain text, and is honest.
   *
   * Reconciled with the #81 deck + its CH-F4 (#95) update: Open is enforced
   * (present as live), while Moderated and Invite Only remain unenforced labels
   * and must say so.
   *
   * @covers ::get
   */
  public function testVisibilityCopyIsPresentPlainTextAndHonest(): void {
    foreach (['visibility.field', 'visibility.open', 'visibility.moderated', 'visibility.invite_only'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Tooltip copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }

    // Open is wired by #95 — present it as live/joinable.
    $this->assertMatchesRegularExpression('/\bjoin\b/i', HelpText::get('visibility.open'), 'Open copy must describe joining.');

    // Moderated and Invite Only are NOT enforced — the copy must not over-claim.
    $this->assertStringContainsString('Not yet enforced', HelpText::get('visibility.moderated'), 'Moderated copy must flag it is not enforced.');
    $this->assertStringContainsString('Not yet enforced', HelpText::get('visibility.invite_only'), 'Invite Only copy must flag it is not enforced.');
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
   * The #91 permission-matrix panel copy exists and is plain text.
   *
   * @covers ::get
   */
  public function testPermissionMatrixPanelCopyIsPresent(): void {
    foreach (['permissions.panel.intro', 'permissions.panel.footnote'] as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('Panel copy for "%s" must exist.', $key));
      $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    }
  }

  /**
   * #122 (SC-3): the group-type-homepage tooltip copy names all three
   * lead-section variants (events / discussion / documentation) truthfully.
   *
   * `HelpText::all()` is a fixed literal array — the suite does NOT auto-cover
   * a freshly-appended key (unlike e.g. a config-driven copy source), so this
   * is a TARGETED assertion for the new `group_type.homepage_adapts` key,
   * mirroring the existing per-surface test pattern in this file (e.g.
   * `testGroupTypeFieldCopyNamesAllTypes`). RED reason: F has not yet
   * appended this key to `HelpText::all()`, so `HelpText::get()` returns the
   * unknown-key default `''` and every assertion below fails.
   *
   * @covers ::get
   */
  public function testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants(): void {
    $copy = HelpText::get('group_type.homepage_adapts');
    $this->assertNotSame('', $copy, 'The group_type.homepage_adapts tooltip copy must exist.');
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (allowHTML is disabled).');
    // Wireframe §3: the copy must name the three concrete lead-section
    // variants so a first-time reader understands what "adapts" means.
    foreach (['events', 'discussion', 'documentation'] as $variant) {
      $this->assertStringContainsString($variant, $copy, "Copy must name the '$variant' variant.");
    }
    // The key must actually be present in the literal array (not merely
    // resolvable via some other fallback path).
    $this->assertArrayHasKey('group_type.homepage_adapts', HelpText::all());
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
