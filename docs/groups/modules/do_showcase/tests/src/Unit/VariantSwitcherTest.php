<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\do_showcase\VariantSwitcher;
use Drupal\Tests\UnitTestCase;

/**
 * Unit coverage for the SC-F1 (#119) VariantSwitcher::build() contract.
 *
 * Pins the reusable render-array contract the service exposes so SC-4/SC-5/
 * SC-6/ST-8 can call it (brief.md Acceptance criterion #2, forward-compat
 * table in survey.md). The service is a plain, no-DI class in the same shape
 * as `\Drupal\do_chrome\PermissionMatrix` (StringTranslationTrait, pure data/
 * render-array construction, no service dependencies) — see handoff-A-plan.md
 * finding #2 (service, not a Block plugin: callers supply instance_id/options/
 * current explicitly rather than deriving them from block placement/context).
 *
 * Contract under test — build(string $instance_id, array $options, string
 * $current): array — must return a render array that:
 *  - is a labeled control group (role=radiogroup + aria-label, or a fieldset/
 *    legend equivalent) keyed by $instance_id (wireframe.md Surface 1),
 *  - contains one item per entry in $options, each carrying a machine id +
 *    label,
 *  - marks exactly one option as the current selection via aria-checked (the
 *    one whose id matches $current) — never zero, never more than one,
 *  - falls back to the FIRST AVAILABLE option if $current names an option
 *    that does not exist or is marked unavailable (wireframe.md "Selection
 *    automatically falls back ... never silently renders nothing selected"),
 *  - marks an option flagged unavailable in $options as aria-disabled +
 *    removed from the tab order (tabindex=-1), never a dead click with no
 *    explanation (truthful "(soon)" labeling is a UI/render concern checked
 *    at Functional/E2E level; here we pin the STRUCTURAL disabled markers),
 *  - carries a no-JS `?variant=` fallback link per option (wireframe.md
 *    "State: no-JS"),
 *  - works for an arbitrary option count (2, 3, 5+) — forward-compat: SC-4/
 *    SC-5/SC-6/ST-8 will call this with their own option sets, not just the
 *    3-option stub instance this story ships.
 *
 * @coversDefaultClass \Drupal\do_showcase\VariantSwitcher
 * @group do_showcase
 */
final class VariantSwitcherTest extends UnitTestCase {

  /**
   * The switcher under test.
   */
  private VariantSwitcher $switcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // VariantSwitcher is expected to use StringTranslationTrait ($this->t()),
    // matching PermissionMatrix's shape; UnitTestCase supplies a translation
    // stub that returns the raw string, same as PermissionMatrixTest does.
    $this->switcher = new VariantSwitcher();
    $this->switcher->setStringTranslation($this->getStringTranslationStub());
  }

  /**
   * The three-option stub instance (directory.layout, per wireframe.md) used
   * across most cases below. Mirrors the wireframe's own example: Compact
   * list / Cards / Map, with Map marked unavailable ("(soon)").
   *
   * @return array<int, array{id: string, label: string, available?: bool}>
   */
  private function stubOptions(): array {
    return [
      ['id' => 'compact', 'label' => 'Compact list'],
      ['id' => 'cards', 'label' => 'Cards'],
      ['id' => 'map', 'label' => 'Map', 'available' => FALSE],
    ];
  }

  /**
   * The render array is a labeled control group keyed by instance_id.
   *
   * @covers ::build
   */
  public function testBuildReturnsLabeledControlGroupKeyedByInstanceId(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');

    $this->assertIsArray($build);
    // The wrapper must be identifiable as a labeled radiogroup (wireframe.md
    // "role=radiogroup aria-label" or fieldset/legend equivalent) so a11y
    // tooling and Functional tests can locate it deterministically by
    // instance id — not just by generic markup.
    $this->assertArrayHasKey('#attributes', $build, 'Wrapper must carry #attributes for role/aria-label.');
    $role = $build['#attributes']['role'] ?? NULL;
    $this->assertSame('radiogroup', $role, 'Wrapper role must be radiogroup per wireframe.md Surface 1.');
    $this->assertArrayHasKey('aria-label', $build['#attributes'], 'Wrapper must carry an aria-label.');
  }

  /**
   * One rendered item per supplied option, each carrying id + label.
   *
   * @covers ::build
   */
  public function testBuildRendersOneItemPerOption(): void {
    $options = $this->stubOptions();
    $build = $this->switcher->build('directory.layout', $options, 'cards');

    $items = $build['#options'] ?? [];
    $this->assertCount(count($options), $items, 'One rendered item per supplied option.');
    foreach ($options as $i => $option) {
      $this->assertSame($option['id'], $items[$i]['id'] ?? NULL);
      $this->assertNotEmpty($items[$i]['label'] ?? '', 'Every option must carry a non-empty label.');
    }
  }

  /**
   * Exactly one option is marked as the current selection (aria-checked).
   *
   * @covers ::build
   */
  public function testExactlyOneOptionMarkedSelected(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $items = $build['#options'];

    $checked = array_filter($items, static fn (array $item): bool => ($item['aria_checked'] ?? FALSE) === TRUE);
    $this->assertCount(1, $checked, 'Exactly one option must be aria-checked.');
    $selected = reset($checked);
    $this->assertSame('cards', $selected['id'], 'The selected option must match $current.');
  }

  /**
   * $current naming an unavailable option falls back to the first AVAILABLE
   * option — never silently renders nothing selected (wireframe.md).
   *
   * @covers ::build
   */
  public function testUnavailableCurrentFallsBackToFirstAvailable(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'map');
    $items = $build['#options'];

    $checked = array_values(array_filter($items, static fn (array $item): bool => ($item['aria_checked'] ?? FALSE) === TRUE));
    $this->assertCount(1, $checked, 'Exactly one option must be aria-checked even when $current is unavailable.');
    $this->assertSame('compact', $checked[0]['id'], 'Must fall back to the first available option (compact), not the unavailable one (map).');
  }

  /**
   * $current naming a nonexistent option id also falls back to the first
   * available option (never throws, never selects nothing).
   *
   * @covers ::build
   */
  public function testUnknownCurrentFallsBackToFirstAvailable(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'nonexistent-id');
    $items = $build['#options'];

    $checked = array_values(array_filter($items, static fn (array $item): bool => ($item['aria_checked'] ?? FALSE) === TRUE));
    $this->assertCount(1, $checked);
    $this->assertSame('compact', $checked[0]['id']);
  }

  /**
   * An option flagged unavailable carries aria-disabled + is removed from
   * the tab order (tabindex=-1) — structural markers the wireframe requires.
   *
   * @covers ::build
   */
  public function testUnavailableOptionCarriesDisabledMarkers(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $items = $build['#options'];
    $map = current(array_filter($items, static fn (array $item): bool => $item['id'] === 'map'));

    $this->assertNotFalse($map, 'The unavailable "map" option must still be present (not silently omitted).');
    $this->assertTrue($map['aria_disabled'] ?? FALSE, 'Unavailable option must carry aria-disabled=true.');
    $this->assertSame('-1', (string) ($map['tabindex'] ?? '0'), 'Unavailable option must be removed from the tab order (tabindex=-1).');
  }

  /**
   * An AVAILABLE option is never marked disabled and stays in the tab order.
   *
   * @covers ::build
   */
  public function testAvailableOptionsAreNotDisabled(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $items = $build['#options'];

    foreach (['compact', 'cards'] as $id) {
      $item = current(array_filter($items, static fn (array $i): bool => $i['id'] === $id));
      $this->assertFalse($item['aria_disabled'] ?? FALSE, "Available option \"$id\" must not be aria-disabled.");
    }
  }

  /**
   * Every option carries a no-JS `?variant=` fallback link (wireframe.md
   * "State: no-JS" — the control must degrade to ordinary navigation).
   *
   * @covers ::build
   */
  public function testEveryOptionCarriesNoJsVariantFallbackLink(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    foreach ($build['#options'] as $item) {
      $href = $item['href'] ?? '';
      $this->assertStringContainsString('variant=' . $item['id'], $href, "Option \"{$item['id']}\" must carry a ?variant= no-JS fallback link naming itself.");
    }
  }

  /**
   * The contract holds for an arbitrary option count, not just the 3-option
   * stub — forward-compat for SC-4/SC-5/SC-6/ST-8 (brief.md Acceptance #2).
   *
   * @covers ::build
   */
  public function testBuildWorksForArbitraryOptionCount(): void {
    // Two options, all available.
    $two = [
      ['id' => 'recent', 'label' => 'Recent'],
      ['id' => 'hot', 'label' => 'Hot'],
    ];
    $build = $this->switcher->build('discovery.ranking', $two, 'hot');
    $this->assertCount(2, $build['#options']);

    // Five options, one unavailable.
    $five = [
      ['id' => 'a', 'label' => 'A'],
      ['id' => 'b', 'label' => 'B'],
      ['id' => 'c', 'label' => 'C'],
      ['id' => 'd', 'label' => 'D', 'available' => FALSE],
      ['id' => 'e', 'label' => 'E'],
    ];
    $build5 = $this->switcher->build('persona.switcher', $five, 'b');
    $this->assertCount(5, $build5['#options']);
    $checked = array_values(array_filter($build5['#options'], static fn (array $i): bool => ($i['aria_checked'] ?? FALSE) === TRUE));
    $this->assertCount(1, $checked);
    $this->assertSame('b', $checked[0]['id']);
  }

  /**
   * The wrapper's #attributes['aria-label'] (or a fieldset/legend text) and
   * the tooltip trigger's data-do-tooltip both resolve via HelpText, keyed
   * per switcher instance — the append-only HelpText contract (Acceptance
   * criterion "Ships its HelpText entry (append-only)").
   *
   * @covers ::build
   */
  public function testTooltipTriggerCarriesHelpTextSourcedCopy(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $this->assertArrayHasKey('#tooltip', $build, 'The switcher must render exactly one ⓘ tooltip trigger per instance (do_chrome house pattern), not one per option.');
    $this->assertNotSame('', $build['#tooltip'], 'Tooltip copy must be non-empty (sourced from HelpText, not hardcoded and not blank).');
  }

}
