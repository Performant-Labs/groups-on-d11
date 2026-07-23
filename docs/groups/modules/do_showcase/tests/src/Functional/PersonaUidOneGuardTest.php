<?php

declare(strict_types=1);

namespace Drupal\Tests\do_showcase\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;

/**
 * #120 SC-1 Persona Switcher — "uid 1 is unreachable via any masquerade
 * path" (brief.md AC), on the real HTTP route, not just at the access-check
 * unit level (PersonaAccessCheckTest covers the Kernel-tier calculation;
 * this Functional suite proves the ROUTE itself is gated end to end).
 *
 * `do_showcase.persona_switch` at `/persona-switch/{persona}`
 * (brief-amendments.md Amendment 3/4). uid 1's account name must NEVER be a
 * valid switch target, an unknown persona id must be denied, and each real
 * allowlisted persona id must succeed as anonymous.
 *
 * POST requests are issued via the Mink session's underlying BrowserKit
 * client (`getSession()->getDriver()->getClient()->request()`) since
 * `drupalGet()` supports GET only — this repo has no existing precedent for
 * a state-changing custom-route POST test, so this is the standard
 * Symfony BrowserKit technique BrowserTestBase's default driver exposes.
 *
 * RED reason: the route `do_showcase.persona_switch` does not exist yet —
 * every request below 404s (no route), not 403/302 — so the negative
 * assertions fail on the wrong status code (404 instead of 403) and the
 * positive assertion fails on 404 instead of a redirect.
 *
 * @group do_showcase
 */
final class PersonaUidOneGuardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_showcase'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Issues a POST request to a path via the Mink session's BrowserKit
   * client and returns the HTTP status code.
   *
   * T's fix (Phase 6, Bug B/C): Mink's `BrowserKitDriver` sets
   * `$client->followRedirects(true)` on the client by default, so without
   * disabling it the client transparently follows a 302/303 to its
   * destination BEFORE `getInternalResponse()` is populated — every caller
   * of this helper would observe the FINAL page's 200, never the redirect's
   * own status. Must call `followRedirects(false)` before issuing the
   * request so `getInternalResponse()` reflects the actual first response.
   *
   * @param string $path
   *   The site-relative path (e.g. '/persona-switch/root').
   *
   * @return int
   *   The response status code.
   */
  private function postAndGetStatus(string $path): int {
    $client = $this->getSession()->getDriver()->getClient();
    $client->followRedirects(false);
    $client->request('POST', $this->buildUrl($path));
    return $client->getInternalResponse()->getStatusCode();
  }

  /**
   * uid 1's own account name is never a valid switch target — POST to it as
   * anonymous must be denied (403), never `user_login_finalize`d.
   */
  public function testPostToUidOneUnameIsDenied(): void {
    $uid1_name = User::load(1)->getAccountName();

    $status = $this->postAndGetStatus('/persona-switch/' . $uid1_name);
    $this->assertSame(403, $status, 'POST to uid 1\'s own uname must 403 — never succeed as a switch target.');
  }

  /**
   * An unrecognized persona id is denied (never a successful login).
   */
  public function testUnknownPersonaIdIsDenied(): void {
    $status = $this->postAndGetStatus('/persona-switch/nonexistent');
    $this->assertContains($status, [403, 404], 'An unknown persona id must never succeed (403 from the access check, or 404 if the route itself does not match on that parameter).');
  }

  /**
   * POST to a real, allowlisted persona id (as anonymous) succeeds (a
   * redirect, not a 403/404) — the positive counterpart proving the guard is
   * scoped to bad targets only, not to the whole route.
   */
  public function testPostToAllowlistedPersonaSucceeds(): void {
    // Elena must exist for the switch target to resolve to a real account.
    $this->drupalCreateUser([], 'elena_garcia');

    $status = $this->postAndGetStatus('/persona-switch/elena-garcia');
    $this->assertContains($status, [302, 303], 'Switching to an allowlisted persona must redirect (succeed), not 403/404.');
  }

}
