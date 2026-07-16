import { test, expect, Page } from '@playwright/test';

/**
 * Phase-1 E2E smoke suite — groups-on-d11 (Drupal 11.4.4 + drupal/group 4.x).
 *
 * Covers the four Phase-1 acceptance checks from docs/groups/RUNBOOK.md:
 *   1. Group listing page loads              (/all-groups, view `all_groups`)
 *   2. Authenticated user can create a group (/group/add/community_group)
 *   3. Group type add-form fields render     (/group/add/community_group)
 *   4. Permissions enforced                  (anonymous -> 403 on group create)
 *
 * Auth strategy: log in through the real Drupal /user/login form with the
 * admin/admin credentials set by the runbook
 * (`ddev drush user:password admin 'admin'`). No drush/session injection is
 * needed at runtime, so the suite is self-contained.
 *
 * The group listing lives at /all-groups (Views page `all_groups`), NOT the
 * /groups path the runbook prose mentions — /all-groups is the assembled-config
 * reality and what these tests target.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

/** Log in via the /user/login form. Leaves the page on the post-login screen. */
async function login(page: Page): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(ADMIN_USER);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  // Sanity: a logged-in session exposes the "Log out" link/route.
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

test.describe('Phase 1 — Groups smoke', () => {
  test('group listing page loads', async ({ page }) => {
    const response = await page.goto('/all-groups');
    expect(response?.status()).toBe(200);

    // Page title (browser tab) and the on-page H1 both read "All Groups".
    await expect(page).toHaveTitle(/All Groups/);
    await expect(
      page.getByRole('heading', { level: 1, name: 'All Groups' }),
    ).toBeVisible();
  });

  test('group type fields render', async ({ page }) => {
    await login(page);
    const response = await page.goto('/group/add/community_group');
    expect(response?.status()).toBe(200);

    await expect(
      page.getByRole('heading', { level: 1, name: 'Add group' }),
    ).toBeVisible();

    // Title is the required field the community_group add form renders.
    const title = page.getByLabel('Title', { exact: false });
    await expect(title).toBeVisible();
    await expect(title).toBeEditable();

    // The create/submit action is present.
    await expect(
      page.getByRole('button', { name: /Create Community Group/i }),
    ).toBeVisible();
  });

  test('authenticated user can create group', async ({ page }) => {
    await login(page);
    await page.goto('/group/add/community_group');

    const groupTitle = `E2E Smoke Group ${Date.now()}`;
    await page.getByLabel('Title', { exact: false }).fill(groupTitle);
    await page
      .getByRole('button', { name: /Create Community Group/i })
      .click();

    // A created group redirects to its canonical route /group/{id}.
    await page.waitForURL(/\/group\/\d+(\/|$)/);
    expect(page.url()).toMatch(/\/group\/\d+/);

    // The new group's title is shown on the created group page.
    await expect(
      page.getByRole('heading', { name: groupTitle }),
    ).toBeVisible();
  });

  test('permissions enforced — anonymous cannot create a group', async ({
    page,
  }) => {
    // Fresh context (no login) — anonymous access to group creation is denied.
    const response = await page.goto('/group/add/community_group');
    const status = response?.status();

    // Drupal returns 403 for denied access; some site configs redirect
    // anonymous users to the login form (200 on /user/login). Accept either.
    if (status === 200) {
      await expect(page).toHaveURL(/\/user\/login/);
    } else {
      expect(status).toBe(403);
      await expect(
        page.getByRole('heading', { level: 1, name: 'Access denied' }),
      ).toBeVisible();
    }
  });
});
