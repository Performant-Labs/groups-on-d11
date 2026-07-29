import { test, expect, Page } from '@playwright/test';

/**
 * CH-A1 (#83) E2E — community header navigation — groups-on-d11.
 *
 * The `groups_chrome` theme places a main-menu block in the `primary_menu`
 * region (block.block.groups_chrome_main_menu) and an account-menu block in
 * `secondary_menu` (block.block.groups_chrome_account_menu). Issue #83 seeds
 * the four community `main` menu links so that block renders real navigation:
 *
 *   Groups        -> /all-groups
 *   Activity      -> /stream
 *   My Feed       -> /my-feed                (added by #110)
 *   My Groups     -> /my-groups              (real page since #295)
 *   Create Group  -> /group/add/community_group
 *
 * Drupal hides a menu link when the current user cannot access its target, so:
 *   - Anonymous visitors see the publicly-accessible links (Groups, Activity).
 *   - Authenticated members additionally see My Feed, My Groups, Create Group.
 * This access-aware filtering is expected behaviour, not a regression — the
 * tests assert each state explicitly.
 *
 * The `standard` install profile's plugin-derived "Home" link is deliberately
 * NOT part of this nav: the site logo and site name already link to the front
 * page, and the duplicate cost 84px of a header that has none to spare (see
 * do_chrome's PrimaryNavLinks hook and the "Desktop nav fit" rules in
 * groups_chrome/css/chrome.css). The anonymous test asserts its absence.
 *
 * Auth uses the real /user/login form with admin/admin (matches phase1.spec).
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

/** The placed primary-nav block; its links are what this story owns. */
const NAV = '#block-groups-chrome-main-menu';

/** Log in via the /user/login form. */
async function login(page: Page): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(ADMIN_USER);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

test.describe('CH-A1 — Header navigation (#83)', () => {
  test('primary-nav block renders on the home page', async ({ page }) => {
    await page.goto('/');
    // The main-menu block is placed and rendered in the primary_menu region.
    await expect(page.locator(NAV)).toBeVisible();
  });

  test('anonymous visitor sees the public community links', async ({ page }) => {
    await page.goto('/');
    const nav = page.locator(NAV);

    // Publicly-accessible links resolve for anonymous users.
    const groups = nav.getByRole('link', { name: 'Groups', exact: true });
    await expect(groups).toBeVisible();
    await expect(groups).toHaveAttribute('href', '/all-groups');

    const activity = nav.getByRole('link', { name: 'Activity', exact: true });
    await expect(activity).toBeVisible();
    await expect(activity).toHaveAttribute('href', '/stream');

    // The profile-default "Home" link is suppressed — the branding block
    // already links to the front page twice (logo + site name).
    await expect(nav.getByRole('link', { name: 'Home', exact: true })).toHaveCount(
      0,
    );
  });

  test('authenticated member sees all the community links', async ({ page }) => {
    await login(page);
    await page.goto('/');
    const nav = page.locator(NAV);

    const expected: Array<[string, string]> = [
      ['Groups', '/all-groups'],
      ['Activity', '/stream'],
      ['My Feed', '/my-feed'],
      ['My Groups', '/my-groups'],
      ['Create Group', '/group/add/community_group'],
    ];
    for (const [name, href] of expected) {
      const link = nav.getByRole('link', { name, exact: true });
      await expect(link).toBeVisible();
      await expect(link).toHaveAttribute('href', href);
    }
  });

  test('nav links resolve to their target pages', async ({ page }) => {
    // Publicly-reachable targets return 200.
    expect((await page.goto('/all-groups'))?.status()).toBe(200);
    expect((await page.goto('/stream'))?.status()).toBe(200);

    // Create Group requires the create permission; logged-in admin reaches it.
    await login(page);
    expect((await page.goto('/group/add/community_group'))?.status()).toBe(200);
  });

  test('subtheme not regressed — page title H1 still renders', async ({
    page,
  }) => {
    // The groups_chrome subtheme must keep rendering the page-title block.
    await page.goto('/all-groups');
    await expect(
      page.getByRole('heading', { level: 1, name: 'All Groups' }),
    ).toBeVisible();
  });

  test('account menu block renders (Log in for anonymous)', async ({ page }) => {
    await page.goto('/');
    const account = page.locator('#block-groups-chrome-account-menu');
    await expect(account).toBeVisible();
    await expect(
      account.getByRole('link', { name: 'Log in', exact: true }),
    ).toBeVisible();
  });
});
