<?php

declare(strict_types=1);

namespace Drupal\do_ops\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * REL-4 (#213) — the /healthz JSON probe.
 *
 * Answers three questions Coolify's healthcheck and Grafana's blackbox probe
 * both need in a single unauthenticated round-trip:
 *   - `db`               — is the primary database reachable?
 *   - `cache`            — is the default cache backend read/writeable?
 *   - `modules_checksum` — has the enabled-modules set drifted since deploy?
 *                          (a stable short digest of the sorted module list;
 *                          alerts flag when this value changes unexpectedly
 *                          between two successive probes on the same host)
 *
 * Overall `status` collapses to `"ok"` when every check passes, `"down"` when
 * DB fails (site is effectively unusable), otherwise `"degraded"`. HTTP status
 * follows: 200 for `ok`, 503 for anything else — so a naive `curl -sf` probe
 * that only inspects the status code still catches degradation, while the JSON
 * body carries per-check detail for Grafana.
 *
 * DI: constructor-inject the three services (@database, @cache.default,
 * @module_handler) via ::create(). NOTE: this controller intentionally does
 * NOT extend ControllerBase — ControllerBase already declares a
 * (non-readonly) `$moduleHandler` property, so a promoted `readonly
 * ModuleHandlerInterface $moduleHandler` here would fatal at class load with
 * "Cannot redeclare non-readonly property … as readonly". Implementing
 * `ContainerInjectionInterface` directly gives the same ::create()
 * container-injection contract without the property collision, and this class
 * has no need for ControllerBase's helper methods (`t()`, `currentUser()`,
 * etc.) — it emits a plain JsonResponse.
 *
 * The route is opted out of the page cache (routing.yml: no_cache: TRUE) so a
 * stale healthy response can never be served after a subsystem degrades.
 */
final class HealthzController implements ContainerInjectionInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('cache.default'),
      $container->get('module_handler'),
    );
  }

  /**
   * Executes the probe and returns the JSON response.
   */
  public function probe(): JsonResponse {
    $checks = [
      'db' => $this->checkDatabase(),
      'cache' => $this->checkCache(),
      'modules_checksum' => $this->modulesChecksum(),
    ];

    // `db` failure is fatal (site is down); any other failure is degraded.
    if ($checks['db'] !== 'ok') {
      $status = 'down';
    }
    elseif ($checks['cache'] !== 'ok') {
      $status = 'degraded';
    }
    else {
      $status = 'ok';
    }

    $body = [
      'status' => $status,
      'checks' => $checks,
      // ISO-8601 UTC timestamp — matches the format the docs/ops/sla.md
      // examples cite and Grafana's default JSON-path parser expects.
      'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
    ];

    $http = $status === 'ok' ? 200 : 503;
    $response = new JsonResponse($body, $http);
    // Belt-and-braces: even if a downstream reverse proxy ignores the route's
    // no_cache option, these headers force a fresh probe every time.
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    return $response;
  }

  /**
   * Round-trips a trivial SELECT to confirm the primary DB is reachable.
   */
  private function checkDatabase(): string {
    try {
      $result = $this->database->query('SELECT 1')->fetchField();
      return ((string) $result === '1') ? 'ok' : 'down';
    }
    catch (\Throwable) {
      return 'down';
    }
  }

  /**
   * Set/get/delete a scratch key on the default cache backend.
   */
  private function checkCache(): string {
    try {
      $key = 'do_ops.healthz.probe';
      $expected = uniqid('probe_', TRUE);
      $this->cache->set($key, $expected, CacheBackendInterface::CACHE_PERMANENT);
      $item = $this->cache->get($key);
      $this->cache->delete($key);
      return ($item && $item->data === $expected) ? 'ok' : 'degraded';
    }
    catch (\Throwable) {
      return 'degraded';
    }
  }

  /**
   * Short stable digest of the sorted enabled-modules list.
   *
   * Two probes on the same deploy return the identical value; a drift (unplanned
   * module enable/disable, partial config import) surfaces as a changed digest
   * — the alert rule in docs/ops/sla.md keys off that.
   */
  private function modulesChecksum(): string {
    try {
      $modules = array_keys($this->moduleHandler->getModuleList());
      sort($modules);
      // First 12 hex chars is enough to detect drift in a fleet of <100 modules
      // (2^48 collision space) while keeping the JSON body compact.
      return substr(hash('sha256', implode(',', $modules)), 0, 12);
    }
    catch (\Throwable) {
      return 'unknown';
    }
  }

}
