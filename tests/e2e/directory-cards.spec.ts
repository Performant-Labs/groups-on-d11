import { test, expect, Page } from '@playwright/test';

/**
 * E2E for the group directory cards — story #84 / CH-A2.
 *
 * Verifies the /all-groups view (view `all_groups`) renders as rich
 * `.gc-directory-card` cards (not a bare list), each carrying:
 *   - group name + link, a type badge, a visibility badge,
 *   - a member-count stat, and
 *   - a Join affordance whose state depends on the viewer:
 *       * anonymous            -> "View group" (no join)
 *       * logged-in non-member -> "Join group" (open groups; wired by CH-F4)
 *       * member               -> "Member" note (no join)
 *
 * Runs against the seeded demo site (8 groups, one archived), so at least the
 * 7 published groups render. All data is read from existing entities — no
 * schema changes (epic #78).
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

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

test.describe('CH-A2 — Group directory cards (#84)', () => {
  test('anonymous sees cards with type + visibility badges and member counts', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    // Subtheme not regressed: the page-title H1 still renders.
    await expect(
      page.getByRole('heading', { level: 1, name: 'All Groups' }),
    ).toBeVisible();

    // Cards render (one per published group).
    const cards = page.locator('.gc-directory-card');
    const cardCount = await cards.count();
    expect(cardCount).toBeGreaterThan(0);

    const first = cards.first();
    // Every card carries a visibility badge (the field is required, defaults
    // to Open) and a member-count stat with a numeric value.
    await expect(
      first.locator('.gc-directory-card__visibility'),
    ).toBeVisible();
    const memberValue = first.locator(
      '.gc-directory-card__stat--members .gc-directory-card__stat-value',
    );
    await expect(memberValue).toBeVisible();
    await expect(memberValue).toHaveText(/^\d+$/);
    // The card title links to the group.
    await expect(
      first.locator('.gc-directory-card__title a'),
    ).toBeVisible();
    // The seeded demo groups are tagged with a group type (field_group_type),
    // so at least one card shows a type badge.
    expect(
      await page.locator('.gc-directory-card__type').count(),
    ).toBeGreaterThan(0);
  });

  test('anonymous gets a "View group" affordance, never a Join button', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    await expect(
      page.locator('.gc-directory-card__join').first(),
    ).toHaveText(/View group/);
    // No join route links are exposed to anonymous visitors.
    await expect(
      page.locator('.gc-directory-card a[href*="/join"]'),
    ).toHaveCount(0);
  });

  test('a logged-in member sees the "Member" note on groups they belong to', async ({
    page,
  }) => {
    // admin (uid 1) is the creator/member of every seeded group.
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/all-groups');
    const memberNotes = page.locator('.gc-directory-card__member-note');
    expect(await memberNotes.count()).toBeGreaterThan(0);
    await expect(memberNotes.first()).toContainText('Member');
  });
});
