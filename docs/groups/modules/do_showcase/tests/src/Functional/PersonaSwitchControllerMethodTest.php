<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * #120 SC-1 Persona Switcher — HTTP-method discipline on
 * `do_showcase.persona_switch` (brief-amendments.md Amendment 4 / A's
 * finding #10): `methods: [GET, POST]` on the route, the controller branches
 * `persona=anonymous` (GET allowed — the banner's switch-back link is a
 * plain `<a>`) vs any other persona (POST-only, state-changing — a crafted
 * GET must never log a visitor into an account).
 *
 * RED reason: the route does not exist yet — every request 404s, not the
 * specific 405/redirect status this test pins.
 *
 * @group do_showcase
 */
final class PersonaSwitchControllerMethodTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * GET on a real (non-anonymous) persona target must 405 — switching INTO
   * a persona is a state change and must never happen via a plain link/GET
   * (CSRF-adjacent safety: a crafted `<img src="/persona-switch/moderator">`
   * must not silently log the visitor in).
   */
  public function testGetOnNonAnonymousPersonaIs405(): void {
    $this->drupalCreateUser([], 'maria_chen');

    $this->drupalGet('/persona-switch/maria-chen');
    $this->assertSession()->statusCodeEquals(405);
  }

  /**
   * POST on a real (non-anonymous) persona target succeeds — a redirect
   * after the login (302/303).
   */
  public function testPostOnNonAnonymousPersonaRedirects(): void {
    $this->drupalCreateUser([], 'maria_chen');

    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $this->buildUrl('/persona-switch/maria-chen'));
    $status = $client->getInternalResponse()->getStatusCode();
    $this->assertContains($status, [302, 303], 'POST to a real persona target must redirect (successful login), not 405/403.');
  }

  /**
   * GET on `persona=anonymous` (switch-back) is explicitly ALLOWED — the
   * banner's switch-back control is a real `<a href="/persona-switch/
   * anonymous">` link (wireframe.md §2), which issues a GET.
   */
  public function testGetOnAnonymousRedirects(): void {
    $this->drupalGet('/persona-switch/anonymous');
    // A GET to the switch-back target must not 405 (it's explicitly
    // permitted) — expect a redirect (session logout + redirect back).
    $status_code = $this->getSession()->getStatusCode();
    $this->assertContains($status_code, [302, 303], 'GET on persona=anonymous (switch-back) must redirect, never 405.');
  }

}
