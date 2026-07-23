<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * #120 SC-1 Persona Switcher — the persistent active-persona banner.
 *
 * wireframe.md §2: rendered via a sibling `#[Hook('page_top')] personaBanner()`
 * method (never folded into the ribbon's `pageTop()` — Amendment 5). Absent
 * entirely from the DOM (not hidden via CSS) when the session is anonymous;
 * present with `role="status"`, the exact per-persona copy, and a real
 * `<a href="/persona-switch/anonymous">` switch-back link when a persona is
 * active.
 *
 * This test asserts the RENDER path only (per the harness prompt: "the
 * switcher-controller flow is covered by e2e") — it logs in as Elena
 * directly via `drupalLogin()` (BrowserTestBase self-installs with no demo
 * seed, so Elena is created here, not seeded) rather than driving the
 * dropdown/controller, since `personaBanner()`'s render condition is "the
 * current session's uname is an allowlisted persona uname", which
 * `drupalLogin()` satisfies identically to a real persona switch.
 *
 * RED reason: `DoShowcaseHooks::personaBanner()` does not exist yet — no
 * `<aside role="status">` renders under any session, so the "banner absent
 * when anonymous" assertion trivially/vacuously would pass for the wrong
 * reason if authored alone; the paired "banner present when persona active"
 * assertion is the one that fails for the RIGHT reason (missing markup).
 *
 * @group do_showcase
 */
final class PersonaBannerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Anonymous session: no banner markup anywhere in the DOM (truthful-empty-
   * state rule — wireframe.md §2: "not hidden via CSS — truly absent").
   */
  public function testAnonymousSessionHasNoBanner(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->elementNotExists('css', 'aside[role="status"].do-showcase-persona-banner');
  }

  /**
   * Elena's session (uname `elena_garcia`, an allowlisted persona uname):
   * the banner renders with `role="status"`, the exact copy, and a real
   * switch-back `<a>` link.
   */
  public function testElenaSessionShowsBannerWithExactCopyAndSwitchBackLink(): void {
    $elena = $this->drupalCreateUser([], 'elena_garcia');
    $this->drupalLogin($elena);

    $this->drupalGet('<front>');

    $banner = $this->assertSession()->elementExists('css', 'aside[role="status"].do-showcase-persona-banner');
    $this->assertStringContainsString(
      "You're browsing as Elena Garcia — Member — switch back",
      $banner->getText(),
      'Banner copy must match the exact issue phrasing for Elena.'
    );

    $link = $this->assertSession()->elementExists('css', 'a[href="/persona-switch/anonymous"]', $banner);
    $this->assertNotEmpty($link->getText(), 'The switch-back link must be a real, non-empty <a> element.');
  }

}
