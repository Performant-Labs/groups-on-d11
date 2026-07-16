import { defineConfig, devices } from '@playwright/test';

/**
 * Phase-1 E2E smoke suite for groups-on-d11 (Drupal 11.4 + drupal/group 4.x).
 *
 * Runs headless against the live DDEV site. The base URL defaults to the local
 * DDEV HTTPS URL but can be overridden with the BASE_URL env var, e.g.:
 *
 *   BASE_URL="https://groups-on-d11-build.ddev.site:8493" npm run test:e2e
 *
 * DDEV serves over a local self-signed cert, so `ignoreHTTPSErrors` is on.
 */
const baseURL =
  process.env.BASE_URL ?? 'https://groups-on-d11-build.ddev.site:8493';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  reporter: [['list']],
  timeout: 30_000,
  expect: { timeout: 10_000 },
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    headless: true,
    actionTimeout: 10_000,
    navigationTimeout: 20_000,
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
