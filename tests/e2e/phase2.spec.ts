import { test, expect, Page } from '@playwright/test';

/**
 * Phase-2 E2E — group directory & discovery — groups-on-d11
 * (Drupal 11.4 + drupal/group 4.x).
 *
 * Self-contained and self-seeding: this spec logs in with its own helper, mints
 * its own uniquely-named groups through the real /group/add/community_group
 * form, and asserts against the assembled build's REAL directory UI — it does
 * not import phase1.spec.ts or touch playwright.config.ts.
 *
 * WHAT THE REAL UI EXPOSES (explored, not assumed — the runbook prose is
 * aspirational here):
 *   - The directory lives at /all-groups (Views page `all_groups`), title
 *     "All Groups". Its ONLY exposed filter is a text "Search groups" box
 *     (identifier `search`, a `contains` filter on the group label). There is
 *     NO exposed group-type or visibility filter in the assembled all_groups
 *     view, so "filter by type / visibility" has no directory surface — the
 *     search box is the filter we exercise.
 *   - /admin/groups/pending (Views page `pending_groups`) is the moderation
 *     surface for unpublished groups; it is admin-gated (anonymous -> 403).
 *
 * NO MEANINGFUL E2E SURFACE (documented, deliberately not faked):
 *   - Self-serve JOIN / REQUEST / pending-approval membership flow: the
 *     community_group `outsider` role is granted only `view … entity`
 *     permissions — it has no `join group` (or membership-request) permission
 *     in the assembled config, so an outsider is offered no join/request
 *     operation in the UI and there is no pending-approval state to drive. This
 *     is a config reality of the build, not a test gap. The admin-side
 *     pending-groups moderation view IS asserted below instead.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

/** Log in via the /user/login form; leaves the page post-login. */
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

/**
 * Create a community_group through the real add form and return its numeric id.
 * Fills Title + Description (CKEditor 5) + a Visibility radio — all three are
 * required on the #44 add-form display, mirroring phase1.spec.ts exactly.
 */
async function createGroup(
  page: Page,
  title: string,
  visibility: 'open' | 'moderated' | 'invite_only' = 'open',
): Promise<string> {
  await page.goto('/group/add/community_group');
  await page.getByLabel('Title', { exact: false }).fill(title);
  const desc = page.locator('.ck-editor__editable').first();
  await desc.click();
  await desc.pressSequentially(`Directory seed group: ${title}.`);
  await page
    .locator(`input[name="field_group_visibility"][value="${visibility}"]`)
    .check();
  await page.getByRole('button', { name: /Create Community Group/i }).click();
  await page.waitForURL(/\/group\/\d+(\/|$)/);
  const gid = page.url().match(/\/group\/(\d+)/)?.[1];
  expect(gid, 'created group should have a numeric id').toBeTruthy();
  return gid as string;
}

test.describe('Phase 2 — Group directory & discovery', () => {
  test('directory lists created groups with cards', async ({ page }) => {
    await login(page);
    const marker = `Dir${Date.now()}`;
    const title = `${marker} Alpha Group`;
    await createGroup(page, title);

    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);
    await expect(
      page.getByRole('heading', { level: 1, name: 'All Groups' }),
    ).toBeVisible();

    // The just-created group renders as a directory card (a link to its
    // canonical /group/{id} route) with its label.
    await expect(
      page.getByRole('link', { name: title }),
    ).toBeVisible();
    // There is at least one directory row.
    expect(await page.locator('.views-row').count()).toBeGreaterThan(0);
  });

  test('search filter narrows the directory to a matching group', async ({
    page,
  }) => {
    await login(page);
    // Two groups with disjoint, unique tokens so the search is unambiguous.
    const stamp = Date.now();
    const needleTitle = `Zephyr${stamp} Search Target`;
    const otherTitle = `Quokka${stamp} Other Group`;
    await createGroup(page, needleTitle);
    await createGroup(page, otherTitle);

    await page.goto('/all-groups');
    // Baseline: both are present before filtering.
    await expect(page.getByRole('link', { name: needleTitle })).toBeVisible();
    await expect(page.getByRole('link', { name: otherTitle })).toBeVisible();

    // The one exposed filter is the "Search groups" text box (identifier
    // `search`, contains-match on the label). Filter to the Zephyr token.
    await page.getByLabel('Search groups').fill(`Zephyr${stamp}`);
    await Promise.all([
      page.waitForURL(/search=Zephyr/),
      page.getByRole('button', { name: /^Filter$/ }).click(),
    ]);

    // The list narrows: the target remains, the other group is filtered out.
    await expect(page.getByRole('link', { name: needleTitle })).toBeVisible();
    await expect(page.getByRole('link', { name: otherTitle })).toHaveCount(0);
  });

  test('pending-groups moderation view is admin-gated', async ({ page }) => {
    // Anonymous: the pending moderation surface is denied.
    const anon = await page.goto('/admin/groups/pending');
    expect(anon?.status()).toBe(403);

    // Admin: the pending_groups Views page loads (HTTP 200) and renders.
    await login(page);
    const res = await page.goto('/admin/groups/pending');
    expect(res?.status()).toBe(200);
    // A Views admin page renders a main region with a page title; assert the
    // route resolved to a real view page rather than an access-denied shell.
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Access denied');
  });
});
