<?php

declare(strict_types=1);

namespace Drupal\Tests\do_ops\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * REL-4 (#213) — /healthz endpoint behavioral coverage.
 *
 * Layer choice: Functional (BrowserTestBase) because the acceptance criteria
 * key off the REAL HTTP response — status code (200 vs 503), Content-Type
 * (application/json), Cache-Control (no-store), and JSON body shape — which a
 * Kernel test on the controller class alone would not observe. The endpoint
 * is anonymous and has no UI surface, so BrowserTestBase (no Playwright) is
 * the cheapest tier that covers the actual contract.
 *
 * @group do_ops
 */
final class HealthzEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['do_ops', 'system'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Healthy site returns HTTP 200 + JSON body with status=ok.
   */
  public function testHealthyProbeReturns200AndOk(): void {
    $this->drupalGet('/healthz');
    $this->assertSession()->statusCodeEquals(200);

    $body = $this->getSession()->getPage()->getContent();
    $response = $this->getSession()->getDriver()->getClient()->getResponse();
    $this->assertStringContainsString('application/json', $response->getHeader('Content-Type'));
    // no-store header, so probes never observe a cached healthy response after
    // a subsystem has degraded.
    $this->assertStringContainsString('no-store', (string) $response->getHeader('Cache-Control'));

    $data = json_decode($body, TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame('ok', $data['status']);
    $this->assertSame('ok', $data['checks']['db']);
    $this->assertSame('ok', $data['checks']['cache']);
    $this->assertNotEmpty($data['checks']['modules_checksum']);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $data['checks']['modules_checksum']);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['timestamp']);
  }

  /**
   * Response is anonymous-accessible (no auth required for probes).
   */
  public function testProbeIsAnonymouslyAccessible(): void {
    // Explicitly not logging in — Coolify's healthcheck is unauthenticated.
    $this->drupalGet('/healthz');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Modules checksum is stable across two immediate probes on the same site.
   *
   * A drifting checksum on unchanged modules would render the alert rule in
   * docs/ops/sla.md useless (constant false-positive drift alerts), so pin it.
   */
  public function testModulesChecksumIsStableAcrossProbes(): void {
    $this->drupalGet('/healthz');
    $first = json_decode($this->getSession()->getPage()->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->drupalGet('/healthz');
    $second = json_decode($this->getSession()->getPage()->getContent(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame($first['checks']['modules_checksum'], $second['checks']['modules_checksum']);
  }

}
