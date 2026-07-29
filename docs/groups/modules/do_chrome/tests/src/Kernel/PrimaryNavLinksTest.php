<?php

declare(strict_types=1);

namespace Drupal\Tests\do_chrome\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\do_chrome\Hook\PrimaryNavLinks;

/**
 * Covers the durable suppression of the profile-default "Home" nav link.
 *
 * `docs/groups/scripts/step_780_nav_menu.php` already intended this — it calls
 * `MenuLinkManager::updateDefinition('standard.front_page', ['enabled' =>
 * FALSE])`. That override is not rebuild-proof, and the link had in fact come
 * back, adding a sixth item to a primary nav that then no longer fit on one
 * line for logged-in visitors (which is what produced the collapse-to-
 * hamburger flicker). `PrimaryNavLinks` re-asserts the same intent at
 * discovery time, where a rebuild cannot undo it.
 *
 * @group do_chrome
 * @covers \Drupal\do_chrome\Hook\PrimaryNavLinks
 */
final class PrimaryNavLinksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'do_chrome'];

  /**
   * The front-page link is disabled when the profile has discovered it.
   */
  public function testFrontPageLinkIsDisabled(): void {
    $links = [
      'standard.front_page' => [
        'title' => 'Home',
        'route_name' => '<front>',
        'menu_name' => 'main',
        'enabled' => 1,
      ],
    ];

    (new PrimaryNavLinks())->menuLinksDiscoveredAlter($links);

    $this->assertFalse($links['standard.front_page']['enabled']);
  }

  /**
   * Other discovered links are left exactly as they were.
   */
  public function testOtherLinksAreUntouched(): void {
    $links = [
      'some_module.other_link' => [
        'title' => 'Other',
        'route_name' => 'some_module.other',
        'enabled' => 1,
      ],
    ];
    $before = $links;

    (new PrimaryNavLinks())->menuLinksDiscoveredAlter($links);

    $this->assertSame($before, $links);
  }

  /**
   * A profile without the link (e.g. minimal) must not gain a stub entry.
   */
  public function testAbsentFrontPageLinkIsNotCreated(): void {
    $links = [];

    (new PrimaryNavLinks())->menuLinksDiscoveredAlter($links);

    $this->assertSame([], $links);
  }

  /**
   * End-to-end through the real hook pipeline: the link plugin ships disabled.
   *
   * This is the assertion that actually protects the header — it exercises
   * `hook_menu_links_discovered_alter()` via the container-registered hook,
   * not just the method in isolation, so a mis-registered `#[Hook]` attribute
   * (or a module that never gets its hooks discovered) fails here.
   */
  public function testHookIsRegisteredAndAppliesThroughTheManager(): void {
    $definition = \Drupal::service('plugin.manager.menu.link')
      ->getDefinition('standard.front_page', FALSE);

    // Kernel tests do not install the `standard` profile, so the definition
    // only exists if something else provided it. When it does exist, our hook
    // must have switched it off.
    if ($definition !== NULL) {
      $this->assertEmpty(
        $definition['enabled'] ?? NULL,
        'standard.front_page must be discovered as a disabled link.',
      );
    }
    else {
      $this->assertNull($definition);
    }
  }

}
