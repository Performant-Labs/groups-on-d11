import { test, expect, Page } from '@playwright/test';

/**
 * Issue #134 (SC-7) — Private groups (view-access axis): "Security Team".
 *
 * Walks the persona-switcher demo reveal against the FULLY SEEDED demo site
 * (docs/groups/scripts/step_700_demo_data.php Step 795), per
 * WAVE-EXECUTION-HANDOFF §6 gotcha 6 — E2E must run against the seeded site,
 * not an isolated fixture. Uses the seeded `elena_garcia` persona (required
 * #120-catalog member of Security Team per the brief) — NOT a fresh
 * registration, mirroring `membership-models.spec.ts`'s login() helper and
 * goToGroupByName() navigation pattern VERBATIM (do not invent a new login
 * flow).
 *
 * RED (Phase 4, authored by T before F implements): at RED time this spec
 * CANNOT be run against a live seeded site in this session — no seeded
 * install is up, and Step 795 (F's seed addition) does not exist yet. This is
 * EXPECTED RED for E2E (T's task instructions explicitly waive blocking on
 * this); RED/GREEN verification for this file happens at T-green (Phase 6)
 * once F has implemented the field/hooks/seed/theme and a seeded site is
 * spun up. This file is authored now so its selectors and flow are locked in
 * as the contract F implements against.
 *
 * Locator note (WAVE-EXECUTION-HANDOFF §6 gotcha 9 / G9): Drupal's
 * `#type => submit` renders `<input type="submit">`, not `<button>` —
 * irrelevant to this spec's own controls (no form submission here), but the
 * `getByText()` queries below use `exact: true` where a partial match could
 * collide with another seeded card/heading, per the same gotcha's strict-mode
 * gotcha for `getByLabel`/`getByText` on a seeded page.
 */

const ELENA_USER = process.env.ELENA_USER ?? 'elena_garcia';
const ELENA_PASS = process.env.ELENA_PASS ?? 'demo_password_2026';

const SECURITY_TEAM_NAME = 'Security Team';

/**
 * Login helper — copied verbatim (module path aside) from
 * `membership-models.spec.ts`'s `login()`, per this story's instruction to
 * reuse that flow rather than invent a new one.
 */
async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(pass);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

test.describe.serial('Private groups enforced (#134 SC-7)', () => {
  test('anonymous does not see Security Team in /all-groups', async ({ page }) => {
    await page.goto('/all-groups', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(SECURITY_TEAM_NAME, { exact: true })).not.toBeVisible();
  });

  test('elena_garcia (member) sees Security Team in /all-groups and can open it', async ({ page }) => {
    await login(page, ELENA_USER, ELENA_PASS);

    await page.goto('/all-groups', { waitUntil: 'domcontentloaded' });
    const card = page.getByText(SECURITY_TEAM_NAME, { exact: true });
    await expect(card).toBeVisible();

    await page.getByRole('link', { name: SECURITY_TEAM_NAME, exact: true }).click();
    await page.waitForURL(/\/group\/\d+(\/|$)/);

    await expect(page.locator('h1')).toContainText(SECURITY_TEAM_NAME);
  });
});
