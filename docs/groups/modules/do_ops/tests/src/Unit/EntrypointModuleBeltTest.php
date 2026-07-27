<?php

declare(strict_types=1);

namespace Drupal\Tests\do_ops\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #250 — Regression guard for the deployment gap that took /showcase dark.
 *
 * Root cause (2026-07-27): the live Coolify container installs a fresh site
 * via `deploy/entrypoint.sh`, which contains a "belt-and-suspenders"
 * `drush en` list to ensure every custom demo module ends up enabled even
 * if the imported `core.extension.yml` did not turn them all on. That belt
 * list drifted — do_showcase, do_streams, do_activity, do_activity_feed,
 * do_group_membership, and do_ops were added to the codebase but never
 * added to the belt. Because the entrypoint's install/seed block is
 * skipped on an existing DB, redeploys never enabled the newly-added
 * modules, and /showcase 404'd on the live env even though every kernel/
 * functional test passed and every other core page returned 200.
 *
 * This test enumerates `docs/groups/modules/do_*` at repo scan time and
 * asserts every non-testing module appears in the entrypoint belt. It
 * would have caught #250 the moment do_showcase was added, and it will
 * catch the same class of drift for every future demo module without
 * anyone having to remember. `do_tests` is intentionally excluded (Testing
 * package, not for production).
 *
 * Placed under do_ops (the operational-concerns module — REL-4 #213)
 * because entrypoint hygiene is an ops concern; no other module owns this.
 *
 * @group do_ops
 * @group deployment
 */
final class EntrypointModuleBeltTest extends TestCase {

  /**
   * Modules that must NOT appear in the production entrypoint belt.
   *
   * `do_tests` is a `package: Testing` module (see do_tests.info.yml) —
   * enabling it in production would expose test-only fixtures.
   */
  private const EXCLUDED_FROM_PROD = ['do_tests'];

  /**
   * Every non-testing `do_*` module must be enabled by the entrypoint belt.
   */
  public function testEntrypointBeltCoversAllProdModules(): void {
    $repoRoot = $this->repoRoot();
    $entrypoint = $repoRoot . '/deploy/entrypoint.sh';
    $modulesDir = $repoRoot . '/docs/groups/modules';

    self::assertFileExists($entrypoint, 'deploy/entrypoint.sh must exist');
    self::assertDirectoryExists($modulesDir, 'docs/groups/modules/ must exist');

    // Enumerate all custom do_* modules.
    $found = [];
    foreach (scandir($modulesDir) as $name) {
      if ($name === '.' || $name === '..' || !str_starts_with($name, 'do_')) {
        continue;
      }
      if (is_dir($modulesDir . '/' . $name)) {
        $found[] = $name;
      }
    }
    sort($found);
    self::assertNotEmpty($found, 'expected at least one do_* module under docs/groups/modules/');

    $requiredInBelt = array_values(array_diff($found, self::EXCLUDED_FROM_PROD));

    $script = file_get_contents($entrypoint);
    self::assertNotFalse($script);

    $missing = [];
    foreach ($requiredInBelt as $module) {
      // Word-boundary match so `do_group` doesn't accidentally match
      // `do_group_extras`, and vice versa.
      if (!preg_match('/\b' . preg_quote($module, '/') . '\b/', $script)) {
        $missing[] = $module;
      }
    }

    self::assertSame(
      [],
      $missing,
      sprintf(
        "deploy/entrypoint.sh is missing these modules from the drush-en belt: %s.\n" .
        "Every non-testing do_* module must be enabled by the entrypoint so a redeploy against an existing DB " .
        "picks up newly-added modules (this is the class of bug that took /showcase dark on prod — #250).",
        implode(', ', $missing)
      )
    );
  }

  /**
   * The belt-and-suspenders `drush en` block must run on EVERY boot,
   * not only inside the fresh-DB install/seed conditional.
   *
   * On an existing DB the install/seed conditional is skipped, so any
   * `drush en` inside it never runs — which is exactly why do_showcase
   * stayed disabled on prod after being added to the codebase. The fix
   * moves the belt-en outside the `fresh DB` branch so it runs (idempotently)
   * on every container start.
   */
  public function testEntrypointBeltRunsOnEveryBoot(): void {
    $repoRoot = $this->repoRoot();
    $entrypoint = $repoRoot . '/deploy/entrypoint.sh';
    $script = file_get_contents($entrypoint);
    self::assertNotFalse($script);

    // Locate the fresh-DB conditional. On the buggy version it wraps the
    // whole install/seed/belt block; on the fixed version the belt lives
    // outside it.
    $freshBlockPos = strpos($script, 'Fresh database — installing site');
    self::assertNotFalse($freshBlockPos, 'expected the fresh-DB install marker in entrypoint.sh');

    // Find where the fresh-DB block ends. The current entrypoint closes it
    // with `echo "[entrypoint] Install + seed complete"` immediately before
    // `fi`. The fixed entrypoint must have `do_showcase` mentioned AFTER
    // that closing `fi`.
    $freshBlockEnd = strpos($script, 'Install + seed complete', $freshBlockPos);
    self::assertNotFalse(
      $freshBlockEnd,
      'expected the "Install + seed complete" close marker for the fresh-DB block'
    );

    $tail = substr($script, $freshBlockEnd);
    self::assertStringContainsString(
      'do_showcase',
      $tail,
      "do_showcase (a canary for the always-on belt) must be referenced AFTER the fresh-DB block's " .
      "'Install + seed complete' marker, so the belt runs on every boot — not only fresh installs. " .
      "This is the fix for the #250 root cause: on an existing DB the install/seed conditional is skipped, " .
      "so any drush-en inside it is a no-op — new modules never get enabled."
    );
  }

  /**
   * Resolve the repository root.
   */
  private function repoRoot(): string {
    // This file lives at:
    //   <repo>/docs/groups/modules/do_ops/tests/src/Unit/EntrypointModuleBeltTest.php
    // Walk up seven levels.
    return dirname(__DIR__, 7);
  }

}
