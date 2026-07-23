<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\do_chrome\HelpText;
use Drupal\Tests\BrowserTestBase;

/**
 * #132 SD-5 (Showcase help) — the `/showcase` tour page's per-entry ⓘ help
 * triggers and the map-orientation ⓘ adjacent to the stub switcher.
 *
 * brief.md's Reuse map item 4/5: `ShowcaseController::page()` is extended
 * (inside the existing per-entry loop) to render an optional
 * `data-do-tooltip` ⓘ span next to each catalog entry whose id has a
 * matching `showcase_help.<id>` HelpText key, GUARDED on non-empty copy
 * (survey.md failure-mode note: "only render the tour-page ⓘ span when
 * HelpText::get(...) !== ''" — never an empty `data-do-tooltip=""`, which
 * would render an empty tippy popover on hover). A single
 * `showcase_help.map` ⓘ trigger, carrying class `do-showcase-map-help`,
 * renders adjacent to the switcher (brief.md's approved alternative to
 * touching `VariantSwitcher::build()` for a per-option ⓘ, which is
 * out-of-scope framework surgery).
 *
 * This is a NEW file (no prior `ShowcaseControllerTest` exists for this
 * controller) rather than an extension of an existing one — see
 * `ShowcaseCatalogTest.php` (Unit, catalog data only) and
 * `VariantSwitcherTest.php` (Unit, switcher render-array only); neither
 * exercises the CONTROLLER's actual `/showcase` HTTP response, which is what
 * these help-trigger assertions need (real request, real route, real
 * rendered markup — Functional tier, cheapest sufficient tier for "does the
 * live route's HTML contain this element").
 *
 * RED reason: `ShowcaseController::page()` does not yet append the `help`
 * child to any catalog-entry `$item`, nor the `switcher_map_help` build key —
 * these assertions fail on missing `[data-do-tooltip]` elements, not an
 * unrelated symptom. `HelpText::get('showcase_help.<id>')` calls in this
 * test file itself succeed/fail per `ShowcaseHelpTextTest`'s own RED (the
 * keys are appended in the same PR, but this file's assertions target the
 * CONSUMING markup, not the copy source — the two are deliberately
 * disjoint tests, not a duplicate).
 *
 * @group do_showcase
 */
final class ShowcaseControllerHelpTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   *
   * `node` is required because the do_showcase.showcase route requires
   * "access content" permission, which is provided by node_permission().
   * On a minimal BrowserTestBase install with only "do_showcase" enabled,
   * the anonymous role holds no permissions and /showcase returns 403 —
   * CI Functional-tier failure diagnosed 2026-07-23 on PR#157.
   * The full CI install pipeline (`bash scripts/ci/assemble-config.sh`
   * + `drush cim`) enables node as part of the standard config sync, so
   * this only matters for the isolated BrowserTestBase install path.
   * "system" and "user" are auto-enabled by BrowserTestBase; "block"
   * and "do_chrome" pull in via do_showcase.info.yml dependencies.
   */
  protected static $modules = ['do_showcase', 'node'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Every catalog entry id that currently has a matching `showcase_help.*`
   * HelpText key (per brief.md's "New keys to add" — all seven of the
   * current `ShowcaseCatalog::entries()` ids have one).
   *
   * @return array<int, string>
   */
  private function entryIdsWithHelpCopy(): array {
    return [
      'discovery-ranking',
      'directory-presentation',
      'membership-models',
      'group-type-homepages',
      'stream-model',
      'private-group-reveal',
      'persona-switcher',
    ];
  }

  /**
   * Sanity precondition: confirms every id this test targets really does
   * have non-empty `showcase_help.<id>` copy (fails loudly, not silently,
   * if the catalog's entry ids and this test's expectations ever drift).
   */
  public function testEveryTargetedEntryIdHasNonEmptyHelpCopy(): void {
    foreach ($this->entryIdsWithHelpCopy() as $id) {
      $copy = HelpText::get('showcase_help.' . $id);
      $this->assertNotSame('', $copy, sprintf('showcase_help.%s must resolve to non-empty copy (precondition for this test file\'s render assertions).', $id));
    }
  }

  /**
   * The `/showcase` page renders a `[data-do-tooltip]` ⓘ trigger inside
   * EACH catalog entry's own container
   * (`[data-do-showcase-entry="<id>"]`, per `ShowcaseController::page()`'s
   * existing DOM contract) for every entry that has a matching
   * `showcase_help.<id>` key.
   */
  public function testEachCatalogEntryWithMatchingKeyRendersHelpTrigger(): void {
    $this->drupalGet('/showcase');
    $this->assertSession()->statusCodeEquals(200);

    foreach ($this->entryIdsWithHelpCopy() as $id) {
      $entry = $this->assertSession()->elementExists('css', sprintf('[data-do-showcase-entry="%s"]', $id));
      $trigger = $this->assertSession()->elementExists('css', '[data-do-tooltip]', $entry);
      $this->assertNotEmpty($trigger->getAttribute('data-do-tooltip'), sprintf('Entry "%s" help trigger must carry non-empty data-do-tooltip.', $id));

      $expected_copy = HelpText::get('showcase_help.' . $id);
      $this->assertSame($expected_copy, $trigger->getAttribute('data-do-tooltip'), sprintf('Entry "%s" help trigger copy must match HelpText::get(\'showcase_help.%s\') exactly.', $id, $id));
    }
  }

  /**
   * Every per-entry help trigger is keyboard-reachable and carries the
   * accessible-name/role contract (WCAG 2.2 AA, brief.md acceptance
   * criterion) — checked once on the `discovery-ranking` entry (the live
   * entry) as a representative sample; the per-entry copy-match loop above
   * already exercises every entry's presence, so this is not a duplicate,
   * it is the one ATTRIBUTE-SHAPE check that does not need repeating per
   * entry (the markup-building code path is shared, not per-entry
   * bespoke).
   */
  public function testHelpTriggerIsKeyboardReachableWithAccessibleAttributes(): void {
    $this->drupalGet('/showcase');
    $entry = $this->assertSession()->elementExists('css', '[data-do-showcase-entry="discovery-ranking"]');
    $trigger = $this->assertSession()->elementExists('css', '[data-do-tooltip]', $entry);

    $this->assertSame('0', $trigger->getAttribute('tabindex'), 'Help trigger must be keyboard-reachable (tabindex="0").');
    $this->assertSame('note', $trigger->getAttribute('role'), 'Help trigger must carry role="note".');
    $this->assertNotEmpty($trigger->getAttribute('aria-label'), 'Help trigger must carry a non-empty aria-label.');
  }

  /**
   * The `showcase_help.map` ⓘ trigger renders adjacent to the switcher,
   * carrying the `do-showcase-map-help` class (brief.md's exact approved
   * markup for the map orientation note).
   */
  public function testMapOrientationHelpTriggerRendersAdjacentToSwitcher(): void {
    $this->drupalGet('/showcase');
    $trigger = $this->assertSession()->elementExists('css', '.do-showcase-map-help[data-do-tooltip]');

    $expected_copy = HelpText::get('showcase_help.map');
    $this->assertNotSame('', $expected_copy, 'Precondition: showcase_help.map must resolve to non-empty copy.');
    $this->assertSame($expected_copy, $trigger->getAttribute('data-do-tooltip'), 'Map help trigger copy must match HelpText::get(\'showcase_help.map\') exactly.');
    $this->assertStringContainsString('Geographical', (string) $trigger->getAttribute('data-do-tooltip'), 'Map help copy must name the Geographical group type it filters on.');
  }

  /**
   * The `do_chrome/tooltips` library is attached on `/showcase` — needed
   * for EVERY `[data-do-tooltip]` trigger on the page (the per-entry
   * triggers, the map trigger, and the pre-existing switcher tooltip) to
   * actually initialize as a tippy popover. `ShowcaseController::page()`
   * already attaches this library (pre-#132) for the switcher's own
   * tooltip — this test is a non-regression guard that the new #132
   * triggers do not accidentally ship on a page that has stopped attaching
   * it (e.g. a refactor that moves the attach call).
   */
  public function testTooltipsLibraryIsAttachedOnShowcasePage(): void {
    $this->drupalGet('/showcase');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('do_chrome.tooltips.js');
  }

  /**
   * Guard test (task's alternative-acceptable form): a catalog entry id
   * with NO matching `showcase_help.<id>` key resolves to empty copy via
   * `HelpText::get()`'s existing unknown-key fallback — the SAME guard
   * `ShowcaseController::page()`'s `if ($help_copy !== '')` branches on to
   * skip rendering. Probed directly against HelpText (per the task's
   * explicit "alternative acceptable" instruction) rather than wiring a
   * fake catalog entry into `ShowcaseCatalog` (which would be a framework
   * change / parallel path this story's scope guardrail forbids) — this
   * proves the GUARD CONDITION itself is sound, which is the behavior that
   * actually prevents an empty-tooltip render for any future 8th catalog
   * entry that ships without help copy yet.
   */
  public function testUnknownEntryIdHelpKeyResolvesEmptyGuardingAgainstEmptyTooltipRender(): void {
    $this->assertSame('', HelpText::get('showcase_help.unknown-entry'), 'An id with no matching showcase_help.* key must resolve to empty string — the guard ShowcaseController::page() branches on to skip rendering an empty-tooltip ⓘ.');
  }

}
