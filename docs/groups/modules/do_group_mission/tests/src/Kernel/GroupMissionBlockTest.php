<?php

declare(strict_types=1);

namespace Drupal\Tests\do_group_mission\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\group\Entity\GroupInterface;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Behavioral coverage for do_group_mission's mission block.
 *
 * Issue #42 (Wave C / C4), epic #31. Renders
 * {@see \Drupal\do_group_mission\Plugin\Block\GroupMissionBlock} against a real
 * saved group and asserts the actual rendered markup:
 *
 * - A short description renders verbatim inside `.group-mission`, with NO
 *   "Read more" affordance and no ellipsis.
 * - A long description (> 300 visible chars) is truncated at a WORD boundary
 *   (never mid-word), gets an ellipsis, and a "Read more" link to the group's
 *   canonical page.
 * - HTML tags are stripped before the 300-char length is measured.
 * - An empty / missing description, and no group in context, render nothing.
 *
 * The block is instantiated through the block plugin manager with the group
 * supplied on its `group` context — the same context the block declares — so
 * the real build() path runs (no route stubbing needed).
 *
 * @group do_group_mission
 * @group do_tests
 */
#[RunTestsInSeparateProcesses]
class GroupMissionBlockTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * do_group_mission (module under test) + block (its hard dependency) +
   * field, on top of the group/gnode/node base stack.
   */
  protected static $modules = [
    'group',
    'gnode',
    'options',
    'node',
    'field',
    'block',
    'do_group_mission',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    FieldStorageConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'type' => 'string_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_group_description',
      'entity_type' => 'group',
      'bundle' => static::GROUP_TYPE_ID,
      'label' => 'Group description',
    ])->save();
  }

  /**
   * Builds the mission block with $group on its context and returns markup.
   *
   * @param \Drupal\group\Entity\GroupInterface|null $group
   *   The group to place on the block's `group` context, or NULL for none.
   *
   * @return string
   *   The block's `#markup`, or '' when the block returns an empty build.
   */
  private function renderBlock(?GroupInterface $group): string {
    $context = new Context(new EntityContextDefinition('entity:group', 'Group', FALSE), $group);
    /** @var \Drupal\Core\Block\BlockManagerInterface $manager */
    $manager = $this->container->get('plugin.manager.block');
    /** @var \Drupal\do_group_mission\Plugin\Block\GroupMissionBlock $block */
    $block = $manager->createInstance('do_group_mission', []);
    $block->setContext('group', $context);

    $build = $block->build();
    return isset($build['#markup']) ? (string) $build['#markup'] : '';
  }

  /**
   * A short description renders verbatim with no Read-more affordance.
   */
  public function testShortDescriptionRendersInFull(): void {
    $description = 'A friendly place to talk about accessibility.';
    $group = $this->createGroup(['field_group_description' => $description]);

    $markup = $this->renderBlock($group);

    $this->assertStringContainsString('class="group-mission"', $markup);
    $this->assertStringContainsString($description, $markup, 'The full short description is rendered.');
    $this->assertStringNotContainsString('Read more', $markup, 'No Read-more link for a short description.');
    $this->assertStringNotContainsString('…', $markup, 'No ellipsis for a short description.');
  }

  /**
   * A long description is truncated at a word boundary with a Read-more link.
   */
  public function testLongDescriptionTruncatesAtWordBoundaryWithReadMore(): void {
    // 60 words of 9 chars ("wordwords" = 9) + spaces => ~600 visible chars,
    // comfortably over the 300-char threshold and with clear word boundaries.
    $words = array_fill(0, 60, 'wordwords');
    $description = implode(' ', $words);
    $group = $this->createGroup(['field_group_description' => $description]);

    $markup = $this->renderBlock($group);

    $this->assertStringContainsString('…', $markup, 'A truncated description gets an ellipsis.');
    $this->assertStringContainsString('Read more', $markup, 'A truncated description gets a Read-more link.');

    // The link targets the group's canonical page.
    $this->assertStringContainsString('/group/' . $group->id(), $markup);
    $this->assertStringContainsString('class="read-more"', $markup);

    // Word boundary: the visible text before the ellipsis is a whole-number
    // sequence of "wordwords" tokens — it never ends mid-word. (The final
    // retained word is directly followed by the ellipsis, with no trailing
    // space, because truncation cuts at the last space then appends "…".)
    $this->assertMatchesRegularExpression(
      '/<p>(wordwords )+wordwords…<\/p>/u',
      $markup,
      'Truncation lands on a word boundary, never inside a word.',
    );
    // And it is actually shorter than the original (something was cut).
    $this->assertStringNotContainsString($description, $markup, 'The full text is not rendered when truncated.');
  }

  /**
   * HTML tags are stripped before the 300-char length is measured.
   *
   * A description whose RAW length exceeds 300 only because of markup, but
   * whose VISIBLE (tag-stripped) text is short, must render in full with no
   * Read-more — proving the length test runs on strip_tags(), not raw value.
   */
  public function testLengthMeasuredOnStrippedText(): void {
    // ~30 visible chars wrapped in padding markup that pushes the RAW string
    // well past 300 characters.
    $padding = str_repeat('<span class="x"></span>', 40);
    $visible = 'Short mission, lots of markup.';
    $description = $padding . $visible;
    $this->assertGreaterThan(300, mb_strlen($description), 'Precondition: raw string exceeds 300 chars.');

    $group = $this->createGroup(['field_group_description' => $description]);
    $markup = $this->renderBlock($group);

    $this->assertStringNotContainsString('Read more', $markup, 'Short visible text does not trigger truncation.');
    $this->assertStringNotContainsString('…', $markup);
  }

  /**
   * An empty description renders nothing (empty build).
   */
  public function testEmptyDescriptionRendersNothing(): void {
    $group = $this->createGroup(['field_group_description' => '']);

    $this->assertSame('', $this->renderBlock($group), 'An empty description yields an empty build.');
  }

  /**
   * A group with no description value at all renders nothing.
   */
  public function testMissingDescriptionRendersNothing(): void {
    $group = $this->createGroup();

    $this->assertSame('', $this->renderBlock($group));
  }

  /**
   * No group in context renders nothing.
   */
  public function testNoGroupContextRendersNothing(): void {
    $this->assertSame('', $this->renderBlock(NULL));
  }

}
