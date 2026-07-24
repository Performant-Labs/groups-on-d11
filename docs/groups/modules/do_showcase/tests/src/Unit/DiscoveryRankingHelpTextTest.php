<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_chrome\HelpText;
use PHPUnit\Framework\TestCase;

/**
 * #123 SC-4 (Discovery three ways) — the append-only HelpText key for the
 * `discovery.ranking` VariantSwitcher instance's ⓘ tooltip.
 *
 * `VariantSwitcher::build()` resolves an instance's tooltip via
 * `HelpText::get('showcase.switcher.<instance_id>')` (see
 * `VariantSwitcher.php` docblock + `ShowcaseHelpTextTest`'s own precedent for
 * the `directory.layout` instance's key). This is a SEPARATE, NEW file
 * (rather than an edit to `ShowcaseHelpTextTest.php`) so this story's own
 * test authorship stays disjoint from #119/#132's existing test files —
 * mirrors the "each story tests its own append" convention `HelpText.php`'s
 * class docblock itself documents ("No B-story edits another's entry").
 *
 * Deliberately distinct from the ALREADY-PRESENT `showcase_help.discovery-
 * ranking` key (added by #132 SD-5, HelpText.php line ~280): that key is the
 * tour-page catalog-entry ⓘ (the `showcase_help.*` meta-comparison
 * namespace); THIS key, `showcase.switcher.discovery.ranking`, is the
 * per-switcher-instance tooltip (the `showcase.switcher.*` namespace #119
 * established) — the two must never collide or be confused for one another.
 *
 * RED reason: `showcase.switcher.discovery.ranking` does not exist in
 * `HelpText::all()` yet — `HelpText::get()` resolves it via the documented
 * unknown-key fallback to `''`, so `assertNotSame('', ...)` fails for the
 * on-topic reason (missing key), never an import/setup error.
 *
 * @group do_showcase
 */
final class DiscoveryRankingHelpTextTest extends TestCase {

  private const KEY = 'showcase.switcher.discovery.ranking';

  /**
   * The key resolves to non-empty, plain-text copy (do_chrome.tooltips.js
   * renders with allowHTML disabled — the same house rule every other
   * HelpText key in this file's sibling tests enforces).
   */
  public function testDiscoveryRankingSwitcherTooltipKeyResolvesToNonEmptyPlainText(): void {
    $copy = HelpText::get(self::KEY);
    $this->assertNotSame('', $copy, sprintf('The appended HelpText key "%s" must resolve to non-empty copy.', self::KEY));
    $this->assertStringNotContainsString('<', $copy, 'Copy must be plain text (do_chrome.tooltips.js renders with allowHTML disabled).');
  }

  /**
   * The tooltip copy states the DECISION each tab represents — the issue's
   * own phrasing ("chronological vs engagement-ranked vs editorially
   * curated") — naming all three variants, not generic filler.
   */
  public function testDiscoveryRankingSwitcherTooltipCopyNamesAllThreeVariantsAndTheirDecisions(): void {
    $copy = HelpText::get(self::KEY);
    $this->assertMatchesRegularExpression('/recent/i', $copy, 'Tooltip copy must name "Recent".');
    $this->assertMatchesRegularExpression('/hot/i', $copy, 'Tooltip copy must name "Hot".');
    $this->assertMatchesRegularExpression('/promot/i', $copy, 'Tooltip copy must name "Promoted".');
    $this->assertMatchesRegularExpression('/chronological|newest/i', $copy, 'Tooltip copy must convey the Recent tab\'s chronological decision.');
    $this->assertMatchesRegularExpression('/engagement|comment/i', $copy, 'Tooltip copy must convey the Hot tab\'s engagement-ranked decision.');
    $this->assertMatchesRegularExpression('/editorial|curated|hand-picked|hand picked/i', $copy, 'Tooltip copy must convey the Promoted tab\'s editorial-curation decision.');
  }

  /**
   * The key is a literal entry in `HelpText::all()` (not merely resolvable
   * via some other fallback path) — same pattern `ShowcaseHelpTextTest`'s
   * own `testAllShowcaseHelpKeysArePresentInAllArray()` uses for a freshly-
   * appended key.
   */
  public function testDiscoveryRankingSwitcherTooltipKeyIsPresentInAllArray(): void {
    $all = HelpText::all();
    $this->assertArrayHasKey(self::KEY, $all, sprintf('"%s" must be a literal key in HelpText::all().', self::KEY));
  }

  /**
   * Disjointness guard: this key must not collide with the ALREADY-PRESENT
   * `showcase_help.discovery-ranking` key (#132 SD-5) — the two are
   * deliberately separate namespaces (`showcase.switcher.*` vs.
   * `showcase_help.*`) serving different consumers (VariantSwitcher's
   * per-instance tooltip vs. the tour-page catalog-entry ⓘ).
   */
  public function testDiscoveryRankingKeyIsDistinctFromTheExistingShowcaseHelpKey(): void {
    $this->assertNotSame(
      HelpText::get(self::KEY),
      HelpText::get('showcase_help.discovery-ranking'),
      'showcase.switcher.discovery.ranking (this switcher instance\'s tooltip) must carry copy distinct from showcase_help.discovery-ranking (#132\'s tour-page catalog-entry ⓘ) — the two are different namespaces for different consumers, not accidental duplicates of the same string.',
    );
  }

  /**
   * Appending this key does not disturb the append-only contract for an
   * existing, unrelated key (non-regression, mirrors
   * `ShowcaseHelpTextTest::testExistingDoChromeKeyStillResolvesUnchanged()`).
   */
  public function testExistingDirectoryLayoutSwitcherKeyStillResolvesUnchanged(): void {
    $copy = HelpText::get('showcase.switcher.directory.layout');
    $this->assertNotSame('', $copy, 'Appending showcase.switcher.discovery.ranking must not disturb the existing showcase.switcher.directory.layout key.');
  }

}
