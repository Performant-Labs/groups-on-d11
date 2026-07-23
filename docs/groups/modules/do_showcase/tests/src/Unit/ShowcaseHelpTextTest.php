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
 * #132 SD-5 (Showcase help) appends NINE more keys under a disjoint
 * `showcase_help.*` namespace — help copy for the persona banner ⓘ, six
 * tour-page catalog-entry orientation notes, the persona-switcher entry
 * note, and the map-view orientation note. `showcase_help.*` is deliberately
 * a DIFFERENT namespace from this file's own `showcase.switcher.*` (SC-F1)
 * so the two stories' keys never collide or get confused with one another —
 * `showcase.*` is the per-switcher-instance tooltip; `showcase_help.*` is the
 * meta-comparison orientation copy the tour page and persona banner render.
 *
 * RED reason: none of the nine `showcase_help.*` keys exist in
 * `HelpText::all()` yet (F has not appended them) — every
 * `HelpText::get('showcase_help.*')` call below resolves via the
 * unknown-key fallback to `''`, so `assertNotSame('', ...)` fails for the
 * on-topic reason (missing key), never an import/setup error.
 *
 * @group do_showcase
 */
final class ShowcaseHelpTextTest extends TestCase {

  /**
   * The stub switcher instance id this story wires (wireframe.md example).
   */
  private const STUB_INSTANCE_ID = 'directory.layout';

  /**
   * The nine `showcase_help.*` keys #132 SD-5 commits to shipping (brief.md
   * "New keys to add").
   */
  private const SHOWCASE_HELP_KEYS = [
    'showcase_help.persona_banner',
    'showcase_help.discovery-ranking',
    'showcase_help.directory-presentation',
    'showcase_help.membership-models',
    'showcase_help.group-type-homepages',
    'showcase_help.stream-model',
    'showcase_help.private-group-reveal',
    'showcase_help.persona-switcher',
    'showcase_help.map',
  ];

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

  /**
   * #132 SD-5: all nine `showcase_help.*` keys resolve to non-empty,
   * plain-text copy.
   *
   * One assertion loop (not nine separate test methods) — every key shares
   * the identical contract (non-empty, no HTML), so a single parametrized-
   * style loop is the cheapest sufficient pin; per-key content assertions
   * (e.g. "must name three variants") belong to their own targeted tests
   * below where the copy has a specific truthfulness claim to verify.
   */
  public function testAllShowcaseHelpKeysResolveToNonEmptyPlainText(): void {
    foreach (self::SHOWCASE_HELP_KEYS as $key) {
      $copy = HelpText::get($key);
      $this->assertNotSame('', $copy, sprintf('The appended HelpText key "%s" must resolve to non-empty copy.', $key));
      $this->assertStringNotContainsString('<', $copy, sprintf('Copy for "%s" must be plain text (allowHTML is disabled).', $key));
    }
  }

  /**
   * #132 SD-5: every `showcase_help.*` key is actually present as a literal
   * array key in `HelpText::all()` (not merely resolvable via some other
   * fallback path) — mirrors the existing
   * `testGroupTypeHomepageAdaptsCopyIsPresentAndNamesVariants` pattern in
   * `HelpTextTest.php` for a freshly-appended key.
   */
  public function testAllShowcaseHelpKeysArePresentInAllArray(): void {
    $all = HelpText::all();
    foreach (self::SHOWCASE_HELP_KEYS as $key) {
      $this->assertArrayHasKey($key, $all, sprintf('"%s" must be a literal key in HelpText::all().', $key));
    }
  }

  /**
   * #132 SD-5: the persona-banner ⓘ copy must name the "Browse as" dropdown
   * (the mechanism used to switch back) so a first-time reader understands
   * how to leave the current persona.
   */
  public function testPersonaBannerCopyNamesSwitchMechanism(): void {
    $copy = HelpText::get('showcase_help.persona_banner');
    $this->assertMatchesRegularExpression('/browse as/i', $copy, 'Persona banner help copy must name the "Browse as" dropdown mechanism.');
  }

  /**
   * #132 SD-5: the map orientation copy must name "Geographical" (the
   * seeded group_type this view actually filters on) — a truthful,
   * non-generic claim, not filler ("map view" alone would pass a naive
   * non-empty check but say nothing true about this specific demo).
   */
  public function testMapCopyNamesGeographicalGroupType(): void {
    $copy = HelpText::get('showcase_help.map');
    $this->assertStringContainsString('Geographical', $copy, 'Map help copy must name the "Geographical" group type it filters on.');
  }

  /**
   * #132 SD-5: the membership-models copy must distinguish the two axes
   * (visibility vs. join policy) named in the brief/survey — the same
   * "two axes kept distinct" teaching point issue #132 calls for.
   */
  public function testMembershipModelsCopyDistinguishesTwoAxes(): void {
    $copy = HelpText::get('showcase_help.membership-models');
    $this->assertMatchesRegularExpression('/visibility/i', $copy, 'Membership-models help copy must name the visibility axis.');
    $this->assertMatchesRegularExpression('/join/i', $copy, 'Membership-models help copy must name the join-policy axis.');
  }

  /**
   * #132 SD-5 namespace-disjointness guard: no `showcase_help.*` key may
   * collide with a key already owned by another story
   * (`showcase.*` — SC-F1, `persona.*` — #120, `visibility.*` — #121,
   * `group_type.*` — #122, `page.*` — #126). The brief's own "Reuse map"
   * requires this disjointness so #132 never edits or shadows another
   * consumer's key. This test fails if a future edit ever introduces a
   * `showcase_help.*` key whose bare suffix (after stripping the prefix)
   * accidentally duplicates an existing key from one of those namespaces —
   * i.e. it is a structural collision guard, not a duplicate of the
   * presence/content tests above.
   */
  public function testShowcaseHelpNamespaceIsDisjointFromOtherOwners(): void {
    $all = HelpText::all();
    $other_owned_prefixes = ['showcase.', 'persona.', 'visibility.', 'group_type.', 'page.'];

    foreach (self::SHOWCASE_HELP_KEYS as $key) {
      foreach ($other_owned_prefixes as $prefix) {
        $this->assertStringStartsNotWith($prefix, $key, sprintf('"%s" must not fall under the "%s" namespace owned by another story.', $key, $prefix));
      }
    }

    // Sweeping check: no key anywhere in the full HelpText::all() map
    // literally equals one of our showcase_help keys with the
    // 'showcase_help.' prefix stripped and re-prefixed with another
    // namespace (i.e. no accidental "shadow" entry was introduced under a
    // different prefix for the same concept).
    foreach (self::SHOWCASE_HELP_KEYS as $key) {
      $suffix = substr($key, strlen('showcase_help.'));
      foreach ($other_owned_prefixes as $prefix) {
        $shadow_key = $prefix . $suffix;
        if ($shadow_key === $key) {
          continue;
        }
        $this->assertArrayNotHasKey($shadow_key, $all, sprintf('Found an unexpected shadow key "%s" duplicating "%s" under another namespace.', $shadow_key, $key));
      }
    }
  }

}
