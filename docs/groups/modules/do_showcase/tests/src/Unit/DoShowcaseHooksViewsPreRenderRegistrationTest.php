<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Unit;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\do_showcase\Hook\DoShowcaseHooks;
use Drupal\Tests\UnitTestCase;

/**
 * #124 SC-5: defensive registration pin for `DoShowcaseHooks::viewsPreRender()`.
 *
 * A single, cheap reflection-based check that the new hook method exists and
 * carries the `#[Hook('views_pre_render')]` PHP attribute — guarding against
 * the class's existing `theme()`/`pageTop()`/`personaSwitcherWidget()`/
 * `personaBanner()` registrations accidentally breaking (e.g. a stray syntax
 * error, a misnamed attribute, or the new method never actually being wired
 * up as a hook) without needing to boot a full Views-execution kernel test
 * for a pure "is this method registered" question — that behavioral
 * assertion belongs to `DirectoryTogglePreRenderTest` (Kernel), not here.
 *
 * This codebase has no existing "hook-attribute-via-reflection" test file to
 * mirror verbatim (grepped `#[Hook('views_post_render')]` /
 * `#[Hook('views_pre_render')]` across `do_streams`/`do_group_pin` — no Unit
 * test asserts those attributes reflectively either); this is a new, narrow
 * pattern for this one hook, added because the brief explicitly asks for a
 * "defensive — the new hook shouldn't break the existing theme registration"
 * check.
 *
 * RED-by-construction: `DoShowcaseHooks` has no `viewsPreRender()` method
 * yet, so `ReflectionClass::hasMethod('viewsPreRender')` is FALSE — this
 * test fails on the missing method, the right reason (the hook does not
 * exist yet), not on an attribute-shape mismatch.
 *
 * @group do_showcase
 */
final class DoShowcaseHooksViewsPreRenderRegistrationTest extends UnitTestCase {

  /**
   * `viewsPreRender()` exists on DoShowcaseHooks and carries
   * `#[Hook('views_pre_render')]`.
   */
  public function testViewsPreRenderMethodExistsAndCarriesHookAttribute(): void {
    $reflection = new \ReflectionClass(DoShowcaseHooks::class);
    $this->assertTrue(
      $reflection->hasMethod('viewsPreRender'),
      'DoShowcaseHooks must declare a viewsPreRender() method (brief.md "Also touched": ADD one method, #[Hook(\'views_pre_render\')] viewsPreRender(ViewExecutable $view)).'
    );

    $method = $reflection->getMethod('viewsPreRender');
    $attributes = $method->getAttributes(Hook::class);
    $this->assertNotEmpty(
      $attributes,
      'viewsPreRender() must carry a #[Hook(...)] PHP attribute so Drupal\'s hook-attribute discovery registers it.'
    );

    $hook = $attributes[0]->newInstance();
    $this->assertSame(
      'views_pre_render',
      $hook->hook,
      'viewsPreRender() must be registered for the "views_pre_render" hook, not a different hook name.'
    );
  }

  /**
   * Defensive: the PRE-EXISTING `theme()` hook registration (which this
   * story's new method must not disturb) still carries its
   * `#[Hook('theme')]` attribute.
   */
  public function testExistingThemeHookRegistrationIsUndisturbed(): void {
    $reflection = new \ReflectionClass(DoShowcaseHooks::class);
    $this->assertTrue($reflection->hasMethod('theme'), 'The pre-existing theme() hook method must still exist.');

    $method = $reflection->getMethod('theme');
    $attributes = $method->getAttributes(Hook::class);
    $this->assertNotEmpty($attributes, 'theme() must still carry its #[Hook(...)] attribute.');
    $hook = $attributes[0]->newInstance();
    $this->assertSame('theme', $hook->hook, 'theme() must still be registered for the "theme" hook.');
  }

}
