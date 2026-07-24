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
 * #123 SC-4 (Phase 4, T-RED): extends this file with coverage for the new
 * OPTIONAL 4th `string $query_key = 'variant'` parameter (handoff-A-plan.md
 * Risk 1 resolution) — a caller-supplied key so a SECOND simultaneous
 * switcher instance on the same page (e.g. `/showcase`'s `discovery.ranking`
 * instance alongside the existing `directory.layout` stub) does not collide
 * on `?variant=`. Every option's `href` must read `?<query_key>=<id>` instead
 * of the hardcoded `?variant=<id>`, and the render array's own
 * `#cache['contexts']` must bubble `url.query_args:<query_key>` (not a
 * hardcoded `url.query_args:variant`) so Dynamic Page Cache varies correctly
 * per THIS instance's own query key. The 3-arg call form (omitting
 * $query_key) must remain fully BC — every existing assertion above (which
 * calls build() with exactly 3 args) must keep passing unchanged.
 *
 * #125 SC-6 (Phase 4, T-RED): extends this file with coverage that the
 * `map` entry in `directoryLayoutOptions()`'s returned shape is now a LIVE
 * option (no `available` key, or `available: TRUE`) — the same shape
 * `compact`/`cards` already carry. See
 * `testDirectoryLayoutOptionsMapEntryIsNowAvailable()` below.
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
    // matching PermissionMatrixTest does.
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
   * The three-option discovery.ranking instance (#123 SC-4): Recent / Hot /
   * Promoted, all available (no "(soon)" option — brief.md acceptance:
   * "all three variants render non-empty from seed").
   *
   * @return array<int, array{id: string, label: string}>
   */
  private function discoveryOptions(): array {
    return [
      ['id' => 'recent', 'label' => 'Recent'],
      ['id' => 'hot', 'label' => 'Hot'],
      ['id' => 'promoted', 'label' => 'Promoted'],
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


  /**
   * Roving-tabindex initial state: exactly ONE option (the currently
   * selected/available one) carries tabindex="0"; every other AVAILABLE
   * option carries tabindex="-1" (wireframe.md lines 29-31: "one option in
   * tab order at a time"; line 271 comparison table: "roving-tabindex
   * radiogroup, arrow keys"). This is the structural gap named in
   * handoff-T-green.md's BLOCKER: the shipped code currently sets
   * tabindex="0" on EVERY available option (VariantSwitcher.php line 92,
   * `$available ? '0' : '-1'`), which is Tab-only / all-in-tab-order, not
   * roving. This test must fail against that code because more than one
   * option (both "compact" and "cards", the two available options) will
   * carry tabindex="0" simultaneously.
   *
   * @covers ::build
   */
  public function testExactlyOneAvailableOptionHasRovingTabindexZero(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $items = $build['#options'];

    $zeroTabindex = array_values(array_filter(
      $items,
      static fn (array $item): bool => (string) ($item['tabindex'] ?? '') === '0',
    ));
    $this->assertCount(
      1,
      $zeroTabindex,
      'Roving-tabindex pattern (wireframe.md lines 29-31, 271): exactly ONE option may carry tabindex=0 at a time — the rest of the radiogroup must be reachable only via arrow keys, not Tab. Got ' . count($zeroTabindex) . ' options with tabindex=0.',
    );
    $this->assertSame(
      'cards',
      $zeroTabindex[0]['id'],
      'The single tabindex=0 option must be the currently SELECTED option (roving tabindex tracks selection, not just availability).',
    );

    // Every other AVAILABLE option (here: "compact") must be tabindex=-1,
    // not tabindex=0 — this is the exact defect handoff-T-green.md names:
    // "every AVAILABLE option carries tabindex=0 ... ALL available options
    // are simultaneously in the tab order, not roving."
    $compact = current(array_filter($items, static fn (array $i): bool => $i['id'] === 'compact'));
    $this->assertNotFalse($compact);
    $this->assertSame(
      '-1',
      (string) ($compact['tabindex'] ?? '0'),
      'A non-selected AVAILABLE option ("compact") must be tabindex=-1 under roving tabindex — it is reachable via arrow keys once focus enters the radiogroup, not via Tab directly.',
    );
  }

  /**
   * When the roving tabindex moves (selection changes to a different
   * available option), the single tabindex=0 slot moves WITH it — never
   * stays pinned to the first option or duplicates across two options.
   * Pins the same wireframe.md contract using a different $current so the
   * assertion is not coincidentally true only for the stub's default
   * ("cards").
   *
   * @covers ::build
   */
  public function testRovingTabindexZeroFollowsSelectionToADifferentOption(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'compact');
    $items = $build['#options'];

    $zeroTabindex = array_values(array_filter(
      $items,
      static fn (array $item): bool => (string) ($item['tabindex'] ?? '') === '0',
    ));
    $this->assertCount(1, $zeroTabindex, 'Exactly one tabindex=0 option regardless of which option is selected.');
    $this->assertSame('compact', $zeroTabindex[0]['id'], 'The roving tabindex=0 slot must follow $current, not stay pinned to a fixed option.');

    $cards = current(array_filter($items, static fn (array $i): bool => $i['id'] === 'cards'));
    $this->assertSame('-1', (string) ($cards['tabindex'] ?? '0'), 'The previously-selected-by-default option ("cards") must roll to tabindex=-1 once selection moves elsewhere.');
  }

  /**
   * The unavailable option is never the roving tabindex=0 target — it stays
   * tabindex=-1 regardless of selection (already covered structurally by
   * testUnavailableOptionCarriesDisabledMarkers, restated here to make the
   * roving-vs-disabled distinction explicit: an unavailable option must
   * never accidentally become the "exactly one" tabindex=0 slot even though
   * it is excluded from the available-options roving set).
   *
   * @covers ::build
   */
  public function testUnavailableOptionIsNeverTheRovingTabindexTarget(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $items = $build['#options'];
    $map = current(array_filter($items, static fn (array $i): bool => $i['id'] === 'map'));
    $this->assertSame('-1', (string) ($map['tabindex'] ?? '0'), 'Unavailable option ("map") must never be the roving tabindex=0 target.');
  }

  /**
   * Forward-compat: the "exactly one tabindex=0" roving invariant holds for
   * an arbitrary option count too (brief.md Acceptance criterion #2 — the
   * render-array contract must hold for SC-4/SC-5/SC-6/ST-8's own option
   * sets, not just this story's 3-option stub).
   *
   * @covers ::build
   */
  public function testRovingTabindexInvariantHoldsForArbitraryOptionCount(): void {
    $five = [
      ['id' => 'a', 'label' => 'A'],
      ['id' => 'b', 'label' => 'B'],
      ['id' => 'c', 'label' => 'C'],
      ['id' => 'd', 'label' => 'D', 'available' => FALSE],
      ['id' => 'e', 'label' => 'E'],
    ];
    $build = $this->switcher->build('persona.switcher', $five, 'e');
    $items = $build['#options'];

    $zeroTabindex = array_values(array_filter(
      $items,
      static fn (array $item): bool => (string) ($item['tabindex'] ?? '') === '0',
    ));
    $this->assertCount(1, $zeroTabindex, 'Exactly one tabindex=0 option must hold for a 5-option instance, not just the 3-option stub.');
    $this->assertSame('e', $zeroTabindex[0]['id']);
  }

  /**
   * The render array declares the url.query_args:variant cache context.
   *
   * Build()'s own render-array CONTENT (`#options`' `aria_checked`,
   * `tabindex`, and selection state) is a pure function of the caller-
   * supplied $current argument. This module's one caller
   * (ShowcaseController::page()) derives $current directly from the
   * `variant` query string, so if this render array does not declare the
   * `url.query_args:variant` cache context, Drupal's Dynamic Page Cache
   * has no way to know the array's content varies by that query argument
   * and will serve a stale, wrong-variant-selected render to a different
   * `?variant=` request once any variant has been cached for the page
   * (the exact live defect handoff-T-green2.md reproduced via curl +
   * X-Drupal-Dynamic-Cache headers, fixed in handoff-F3.md).
   *
   * This pins the MECHANISM (the declared cache metadata), not just the
   * symptom: reverting F3's added `'#cache' => ['contexts' =>
   * ['url.query_args:variant']]` entry from VariantSwitcher::build() makes
   * this assertion fail (`#cache` key absent / contexts empty), while the
   * render-array CONTENT assertions elsewhere in this file (e.g.
   * testExactlyOneOptionMarkedSelected) would still pass — content
   * correctness and cache-context correctness are independent contracts,
   * and only this test guards the latter.
   *
   * @covers ::build
   */
  public function testBuildDeclaresUrlQueryArgsVariantCacheContext(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');

    $this->assertArrayHasKey(
      '#cache',
      $build,
      'The render array must declare #cache metadata so Drupal\'s render/page cache layers know this output varies by request context.',
    );
    $contexts = $build['#cache']['contexts'] ?? [];
    $this->assertContains(
      'url.query_args:variant',
      $contexts,
      'build()\'s render array must carry the url.query_args:variant cache context: its own content (#options aria_checked/tabindex) is a pure function of $current, which this module\'s one caller derives from the variant query string. Without this context, Drupal\'s Dynamic Page Cache cannot distinguish a /showcase?variant=map render from a /showcase?variant=compact render and will serve a stale cross-variant HIT (the defect handoff-T-green2.md reproduced live).',
    );
  }

  /**
   * #123 SC-4 (handoff-A-plan.md Risk 1): calling build() with the NEW 4th
   * `$query_key` parameter set to a non-default value ('discovery') produces
   * every option's `href` as `?discovery=<id>` — NOT `?variant=<id>`.
   *
   * RED reason: `VariantSwitcher::build()` does not yet declare a 4th
   * parameter at all — this call is a fatal ArgumentCountError against the
   * current 3-parameter signature, which is the RIGHT failure (missing
   * feature), not an import/typo error.
   *
   * @covers ::build
   */
  public function testBuildWithCustomQueryKeyEmitsThatKeyInEveryOptionHref(): void {
    $build = $this->switcher->build('discovery.ranking', $this->discoveryOptions(), 'hot', 'discovery');

    foreach ($build['#options'] as $item) {
      $href = $item['href'] ?? '';
      $this->assertStringContainsString(
        'discovery=' . $item['id'],
        $href,
        "Option \"{$item['id']}\" must carry a ?discovery= fallback link (the caller-supplied query_key), not ?variant=.",
      );
      $this->assertStringNotContainsString(
        'variant=',
        $href,
        "Option \"{$item['id']}\"'s href must NOT contain 'variant=' when a distinct \$query_key is supplied — a shared key would let this instance's link silently override a co-resident directory.layout switcher's own selection.",
      );
    }
  }

  /**
   * #123 SC-4 (handoff-A-plan.md Risk 1, BC guard): omitting the 4th
   * parameter (the existing 3-arg call form every current caller uses)
   * still produces `?variant=<id>` hrefs — the default value preserves
   * every existing call site exactly. This is the explicit non-regression
   * pin for the BC-safety claim the plan makes; it duplicates none of the
   * assertions above (they exercise CONTENT correctness, not the specific
   * "3-arg call form still defaults to variant" contract) — it directly
   * guards against a future edit that makes $query_key non-optional or
   * changes its default.
   *
   * @covers ::build
   */
  public function testBuildWithoutQueryKeyStillDefaultsToVariantForBackwardCompatibility(): void {
    $build = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    foreach ($build['#options'] as $item) {
      $href = $item['href'] ?? '';
      $this->assertStringContainsString('variant=' . $item['id'], $href, 'The 3-arg call form (no $query_key) must keep producing ?variant= hrefs — BC-safe default.');
    }
  }

  /**
   * #123 SC-4 (handoff-A-plan.md Risk 1 + Spot-check finding #1): the
   * render array's own `#cache['contexts']` bubbles `url.query_args:
   * <query_key>` — NOT a hardcoded `url.query_args:variant` — when a custom
   * $query_key is supplied. Without this, Drupal's Dynamic Page Cache has no
   * way to know THIS instance's render content varies by `?discovery=`, and
   * would serve a stale discovery-tab render across different `?discovery=`
   * requests (the same defect class as the existing
   * testBuildDeclaresUrlQueryArgsVariantCacheContext test guards for the
   * default key).
   *
   * @covers ::build
   */
  public function testBuildWithCustomQueryKeyBubblesMatchingCacheContext(): void {
    $build = $this->switcher->build('discovery.ranking', $this->discoveryOptions(), 'hot', 'discovery');

    $contexts = $build['#cache']['contexts'] ?? [];
    $this->assertContains(
      'url.query_args:discovery',
      $contexts,
      'build() called with $query_key="discovery" must bubble url.query_args:discovery in its own #cache[\'contexts\'] — not the hardcoded url.query_args:variant.',
    );
    $this->assertNotContains(
      'url.query_args:variant',
      $contexts,
      'When a custom $query_key is supplied, the stale url.query_args:variant context must NOT also be present — the cache context must reflect the ACTUAL query key this instance reads, not a hardcoded leftover.',
    );
  }

  /**
   * #123 SC-4: two simultaneous instances (directory.layout with the default
   * key, discovery.ranking with 'discovery') can coexist on one call site
   * without either's option hrefs leaking the other's query key — proves the
   * two switchers on `/showcase` truly preserve each other's state per the
   * wireframe's own justification ("a shared key would force one switcher's
   * selection to silently override the other's on every link").
   *
   * @covers ::build
   */
  public function testTwoSimultaneousInstancesWithDistinctQueryKeysDoNotCollide(): void {
    $directoryBuild = $this->switcher->build('directory.layout', $this->stubOptions(), 'cards');
    $discoveryBuild = $this->switcher->build('discovery.ranking', $this->discoveryOptions(), 'hot', 'discovery');

    foreach ($directoryBuild['#options'] as $item) {
      $this->assertStringContainsString('variant=' . $item['id'], $item['href'] ?? '');
    }
    foreach ($discoveryBuild['#options'] as $item) {
      $this->assertStringContainsString('discovery=' . $item['id'], $item['href'] ?? '');
    }
  }

  /**
   * ST-8 (#130): `streamModelOptions()` returns the two-option `stream.model`
   * machine spec, matching `directoryLayoutOptions()`'s shape 1:1 — Content
   * view (unavailable, "(soon)") + Activity view (available), labels
   * translated via $this->t() (brief.md scope item #5).
   *
   * RED reason (Phase 4): `VariantSwitcher::streamModelOptions()` does not
   * exist yet — this call fails with a fatal \Error ("Call to undefined
   * method"), not a assertion failure, until F implements it.
   *
   * @covers ::streamModelOptions
   */
  public function testStreamModelOptions(): void {
    $options = $this->switcher->streamModelOptions();

    $this->assertSame(
      [
        ['id' => 'content', 'label' => 'Content view', 'available' => FALSE],
        ['id' => 'activity', 'label' => 'Activity view'],
      ],
      $options,
      'streamModelOptions() must return exactly two entries, in order: content (unavailable) then activity (available), matching directoryLayoutOptions() own shape.'
    );
  }

  /**
   * #125 SC-6 (Phase 4, T-RED): `directoryLayoutOptions()`'s `map` entry is
   * now a LIVE option — either no `available` key at all (defaults TRUE via
   * `normalizeOptions()`), or an explicit `available: TRUE` — the same shape
   * `compact` and `cards` already carry (neither of THOSE two entries sets
   * `available` at all in `directoryLayoutOptionIds()`, per
   * VariantSwitcher.php lines 82-86).
   *
   * RED reason: `directoryLayoutOptionIds()` (VariantSwitcher.php line 85)
   * currently hardcodes `['id' => 'map', 'available' => FALSE]` — this
   * assertion fails against that unchanged source because the map entry's
   * `available` key is FALSE, not absent/TRUE. #125 (SC-6) flips this flag
   * in exactly one place (this method's own docblock at line 67 names this
   * story as the one that performs the flip).
   *
   * @covers ::directoryLayoutOptions
   */
  public function testDirectoryLayoutOptionsMapEntryIsNowAvailable(): void {
    $options = $this->switcher->directoryLayoutOptions();
    $map = current(array_filter($options, static fn (array $option): bool => $option['id'] === 'map'));

    $this->assertNotFalse($map, 'The map option must still be present in directoryLayoutOptions().');
    $this->assertTrue(
      $map['available'] ?? TRUE,
      '#125 (SC-6): the map option must now be a LIVE option — "available" must be TRUE or the key must be absent entirely (defaulting TRUE), matching the shape compact/cards already carry. Currently VariantSwitcher.php line 85 hardcodes available => FALSE, so this assertion must fail against unchanged source.',
    );
  }

}
