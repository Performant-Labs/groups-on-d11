import { test, expect, Page } from '@playwright/test';

/**
 * Issue #110 (ST-1 My Feed at /my-feed) E2E.
 *
 * Exercises the new authenticated `/my-feed` route end to end against the
 * FULL SEEDED demo site (WAVE-EXECUTION-HANDOFF.md §6.6 — this suite must
 * run against `docs/groups/scripts/step_700_demo_data.php` +
 * `step_780_nav_menu.php`'s real seed, not an isolated fixture), mirroring
 * `manage-members.spec.ts` / `nav.spec.ts`'s self-contained login-helper
 * conventions.
 *
 * Covers (see docs/planning/handoffs/110-stream-110/handoff-D.md "For T"):
 *  - AC-1: anonymous GET /my-feed -> login redirect or 403 (no shell rendered).
 *  - AC-8 / handoff-A.md Finding #1: anonymous main nav shows Groups/Activity
 *    but the "My Feed" link is ABSENT FROM THE DOM (not merely hidden) for
 *    anonymous users; present (with an accessible name "My Feed" and an href
 *    resolving to /my-feed) for authenticated users.
 *  - AC-2/AC-3/AC-4: authenticated elena_garcia sees the shell chrome with
 *    the my_feed tab + recent ranking pill both active.
 *  - AC-9: elena_garcia's feed leads with "Sprint Planning: Portland 2026"
 *    (pinned, from DrupalCon Portland 2026 — one of her 5 seeded groups) and
 *    shows NO content from groups she is not a member of (Thunder
 *    Distribution / Drupal Deutschland).
 *  - AC-6: a fresh 0-group authenticated user sees the empty state AND the
 *    new empty_cta slot linking to /all-groups.
 *
 * None of the route/controller/view exist yet (this story's own brief names
 * them as NEW files) — every test below is intended to fail at its first
 * navigation/assertion (404 or a shell-selector timeout) until F implements
 * against this suite. This is the deliberate RED.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

// elena_garcia is seeded by docs/groups/scripts/step_700_demo_data.php with
// 5 group memberships (DrupalCon Portland 2026, Core Committers, Leadership
// Council, Camp Organizers EMEA, Drupal France) and is NOT a member of
// Thunder Distribution / Drupal Deutschland (per survey.md's confirmed grep).
// Password corrected to match step_700_demo_data.php's shared seeded-user
// password ("demo_password_2026" for every demo user, confirmed by reading
// the seed script directly) — NOT the username, which the original revision
// mistakenly assumed.
const ELENA_USER = 'elena_garcia';
const ELENA_PASS = process.env.ELENA_PASS ?? 'demo_password_2026';

const NAV = '#block-groups-chrome-main-menu';

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

test.describe('My Feed (#110 ST-1)', () => {
  test('anonymous GET /my-feed is denied or redirected to login (AC-1)', async ({
    page,
  }) => {
    const response = await page.goto('/my-feed');
    const status = response?.status() ?? 0;
    const onLoginPage = /\/user\/login/.test(page.url());

    expect(
      status === 403 || onLoginPage,
      `Anonymous /my-feed must be 403 or redirect to /user/login; got status ${status} at ${page.url()}`,
    ).toBe(true);

    // No shell chrome ever renders for the denied/redirected anonymous request.
    await expect(page.locator('[data-testid="do-streams-shell"]')).toHaveCount(0);
  });

  test('anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8, handoff-A Finding #1)', async ({
    page,
  }) => {
    await page.goto('/');
    const nav = page.locator(NAV);

    await expect(nav.getByRole('link', { name: 'Groups', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Activity', exact: true })).toBeVisible();

    // Absence from the DOM, not merely display:none — count must be 0.
    await expect(nav.getByRole('link', { name: 'My Feed', exact: true })).toHaveCount(0);
  });

  test('authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8)', async ({
    page,
  }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/');
    const nav = page.locator(NAV);

    const myFeedLink = nav.getByRole('link', { name: 'My Feed', exact: true });
    await expect(myFeedLink).toBeVisible();
    await expect(myFeedLink).toHaveAttribute('href', /\/my-feed$/);
  });

  test('elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4)', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    const response = await page.goto('/my-feed');
    expect(response?.status()).toBe(200);

    const shell = page.locator('[data-testid="do-streams-shell"]');
    await expect(shell).toBeVisible();

    const myFeedTab = page.locator(
      '[data-testid="do-streams-shell-tab"][data-scope-id="my_feed"]',
    );
    await expect(myFeedTab).toHaveClass(/is-active/);
    await expect(myFeedTab).toHaveAttribute('aria-current', 'true');

    const recentPill = page.locator(
      '[data-testid="do-streams-shell-ranking-pill"][data-ranking-id="recent"]',
    );
    await expect(recentPill).toHaveClass(/is-active/);
  });

  test('elena_garcia\'s feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9)', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto('/my-feed');

    const results = page.locator('[data-testid="do-streams-shell-results"]');
    await expect(results).toBeVisible();

    await expect(results).toContainText('Sprint Planning: Portland 2026');

    // The pinned leading card is the FIRST card in the results region.
    const firstCard = results.locator('article, .card').first();
    await expect(firstCard).toContainText('Sprint Planning: Portland 2026');

    // Negative assertion: no content from groups Elena is NOT a member of.
    await expect(results).not.toContainText('Thunder Distribution');
    await expect(results).not.toContainText('Drupal Deutschland');
  });

  test('a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6)', async ({
    page,
  }) => {
    // Self-provision a fresh 0-group user via the admin UI (no group
    // membership is ever granted), rather than relying on a specific seeded
    // account remaining group-less across future seed changes.
    await login(page, ADMIN_USER, ADMIN_PASS);
    const username = `myfeed_zero_${Date.now()}`;
    const password = 'ZeroGroupPass123!';

    await page.goto('/admin/people/create');
    await page.getByLabel('Username', { exact: true }).fill(username);
    await page.getByLabel('Email address', { exact: true }).fill(`${username}@example.com`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password', { exact: true }).fill(password);
    const statusActive = page.locator('input[name="status"][value="1"]');
    if (await statusActive.count()) {
      await statusActive.check();
    }
    await page.getByRole('button', { name: /Create new account/i }).click();
    await expect(page.locator('.messages--status, .messages--error')).toBeVisible();

    // Log out the admin, log in as the fresh zero-group user.
    await page.goto('/user/logout');
    await login(page, username, password);

    const response = await page.goto('/my-feed');
    expect(response?.status()).toBe(200);

    const empty = page.locator('[data-testid="do-streams-shell-empty"]');
    await expect(empty).toBeVisible();

    const cta = page.locator('[data-testid="do-streams-shell-empty-cta"]');
    await expect(cta).toBeVisible();
    await expect(cta).toHaveAttribute('href', /\/all-groups/);
  });
});
