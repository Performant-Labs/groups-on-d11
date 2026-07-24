<?php

declare(strict_types=1);

namespace Drupal\Tests\do_streams\Kernel;

use Drupal\do_chrome\HelpText;
use Drupal\do_streams\Hook\DoStreamsHooks;
use Drupal\Tests\do_tests\Kernel\GroupsKernelTestBase;

/**
 * Behavioral test for #194's profile_activity.section tooltip consumer.
 *
 * Issue #194, brief.md's acceptance criteria: the "Recent posts" profile
 * activity block (`views_block:user_activity-block_1`, guarded by
 * DoStreamsHooks::USER_ACTIVITY_BLOCK_PLUGIN_ID) must wire the orphaned
 * `profile_activity.section` HelpText key (authored by ST-5 #114 at
 * HelpText.php:403 but never consumed) onto its outer wrapper as a
 * `data-do-tooltip` trigger + `tabindex="0"` (keyboard reachability), and
 * attach the `do_chrome/tooltips` library so the tippy.js binder
 * (js/do_chrome.tooltips.js:20, which binds globally to any
 * `[data-do-tooltip]` element) actually fires. Mirrors the analogous
 * PermissionMatrixPanel / do-chrome-permission-matrix.html.twig:27 consumer
 * pattern (wrapper-level attribute + library attach), applied here via
 * DoStreamsHooks::preprocessBlock() rather than a twig override.
 *
 * `DoStreamsHooks::preprocessBlock()` (DoStreamsHooks.php:612-620) currently
 * only attaches the `.do-streams-profile-activity` wrapper class and the
 * `do_streams/profile_activity` library — it does not yet read HelpText, set
 * `data-do-tooltip`/`tabindex`, or attach `do_chrome/tooltips`. This is the
 * intended RED: the three new assertions below fail against the
 * pre-#194 method body, while the two pre-existing-behavior assertions
 * (wrapper class + profile_activity library) already pass, pinning that #194
 * must not regress #114.
 *
 * Follows StreamsShellTest's established convention of invoking a
 * `#[Hook]`-tagged preprocess method directly (`new DoStreamsHooks()`) against
 * a hand-built `$variables` array, bypassing the theme/block render pipeline
 * entirely, since only the preprocess CONTRACT (the mutated array), not any
 * markup string, is being pinned.
 *
 * @group do_streams
 * @group do_tests
 */
class ProfileActivityTooltipTest extends GroupsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'do_streams',
    'do_chrome',
    'do_group_pin',
    'do_discovery',
    'do_showcase',
    'flag',
    'views',
    'field',
    'text',
    'filter',
    'datetime',
    'comment',
    'taxonomy',
    'system',
  ];

  /**
   * Invokes DoStreamsHooks::preprocessBlock() directly against a hand-built
   * $variables array shaped as the real block render pipeline would supply
   * it for the "Recent posts" (`views_block:user_activity-block_1`) block.
   *
   * One render pass: every assertion in
   * self::testProfileActivityBlockWrapperCarriesTooltipAndPreservesExistingBehavior()
   * reads off this single $variables mutation, per the brief's "Kernel test
   * asserts all four attributes/attachments in one render pass" acceptance
   * criterion.
   */
  public function testProfileActivityBlockWrapperCarriesTooltipAndPreservesExistingBehavior(): void {
    $variables = [
      'plugin_id' => DoStreamsHooks::USER_ACTIVITY_BLOCK_PLUGIN_ID,
      'attributes' => [],
      '#attached' => ['library' => []],
    ];

    (new DoStreamsHooks())->preprocessBlock($variables);

    // --- New #194 behavior -----------------------------------------------
    $this->assertArrayHasKey(
      'data-do-tooltip',
      $variables['attributes'],
      'The profile-activity block wrapper carries a data-do-tooltip attribute.'
    );
    $this->assertSame(
      HelpText::get('profile_activity.section'),
      $variables['attributes']['data-do-tooltip'] ?? NULL,
      'The data-do-tooltip attribute value is exactly the profile_activity.section HelpText copy.'
    );

    $this->assertSame(
      '0',
      $variables['attributes']['tabindex'] ?? NULL,
      'The wrapper carries tabindex="0" for keyboard reachability of the tooltip trigger.'
    );

    $this->assertContains(
      'do_chrome/tooltips',
      $variables['#attached']['library'],
      'The do_chrome/tooltips library (the tippy.js [data-do-tooltip] binder) is attached.'
    );

    // --- Preserved #114 behavior (no regression) --------------------------
    $this->assertContains(
      'do-streams-profile-activity',
      $variables['attributes']['class'] ?? [],
      'The pre-existing do-streams-profile-activity wrapper class is preserved.'
    );
    $this->assertContains(
      'do_streams/profile_activity',
      $variables['#attached']['library'],
      'The pre-existing do_streams/profile_activity library attachment is preserved.'
    );
  }

}
