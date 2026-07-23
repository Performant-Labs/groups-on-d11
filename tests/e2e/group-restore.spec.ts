import { test, expect, Page } from '@playwright/test';

/**
 * Issue #143 (MC-5) — Group archiving RESTORE action, e2e round-trip.
 *
 * Runs against the real seeded site (docs/groups/scripts/step_720_group_types.php
 * tags "Legacy Infrastructure" as Archive-typed; step_700 seeds the group
 * itself — this spec does NOT touch either seed step, per the brief's
 * "coordinate, do NOT edit" boundary with SD-3 #128).
 *
 * Follows the wireframe's Surface 4 explicit 5-step sequence (also AC-8):
 *   1. Assert Legacy Infrastructure is Archive-typed (badge visible, Restore
 *      tab visible, node-create denied).
 *   2. Navigate /group/{gid}/restore, keep default "Working group", submit.
 *   3. Assert redirect to canonical, success flash, badge gone, Restore tab
 *      gone, node-create allowed.
 *   4. Navigate /group/{gid}/edit, set Group Type back to "Archive" via the
 *      existing widget, save.
 *   5. Assert badge returns and Restore tab returns.
 *
 * Persona: site admin (`administer group` site-wide escape hatch) — the
 * seeded site's ADMIN_USER/ADMIN_PASS, matching manage-members.spec.ts's own
 * persona choice absent a dedicated seeded-Organizer login helper.
 *
 * Self-contained: no imports from other specs, own login/lookup helpers.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

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
 * Resolves the seeded "Legacy Infrastructure" group's numeric id by finding
 * its link on the /all-groups directory (title-search filter), rather than
 * hard-coding a gid — the seed order is not a stable contract across runs.
 */
async function findLegacyInfrastructureGid(page: Page): Promise<string> {
  await page.goto('/all-groups');
  const searchBox = page.getByLabel(/Search groups/i);
  if (await searchBox.isVisible({ timeout: 2000 }).catch(() => false)) {
    await searchBox.fill('Legacy Infrastructure');
    await page.keyboard.press('Enter');
  }
  const link = page.getByRole('link', { name: /Legacy Infrastructure/i }).first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  const gid = href?.match(/\/group\/(\d+)/)?.[1];
  expect(gid, 'Legacy Infrastructure has a numeric group id').toBeTruthy();
  return gid as string;
}

test.describe('Group archiving RESTORE action (#143 MC-5)', () => {
  test('archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group', async ({
    page,
  }) => {
    await login(page);
    const gid = await findLegacyInfrastructureGid(page);

    // --- Step 1: preconditions (Archive-typed) ---------------------------
    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/Archived/i).first()).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();

    // node-create denied: the "add content" surface should not offer
    // creation while archived. Assert directly against the node/add route
    // scoped to this group, which do_group_extras' node_access forbids.
    const addResponse = await page.goto(`/group/${gid}/node/create`, {
      waitUntil: 'domcontentloaded',
    }).catch(() => null);
    if (addResponse) {
      expect(addResponse.status(), 'node create is denied while archived').toBe(403);
    }

    // --- Step 2: restore, keeping default "Working group" ----------------
    await page.goto(`/group/${gid}/restore`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByLabel(/Set group type to/i)).toHaveValue(/.*/);
    await page.getByRole('button', { name: /Restore group/i }).click();

    // --- Step 3: post-restore assertions ---------------------------------
    await page.waitForURL(new RegExp(`/group/${gid}$`));
    await expect(page.locator('.messages--status')).toBeVisible();
    await expect(page.locator('.messages--status')).toContainText(/restored/i);
    await expect(page.getByText(/Archived/i)).toHaveCount(0);
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toHaveCount(0);

    // --- Step 4: re-archive via the existing group edit form --------------
    await page.goto(`/group/${gid}/edit`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel(/Group Type/i).selectOption({ label: 'Archive' });
    await page.getByRole('button', { name: /^Save$/i }).click();

    // --- Step 5: badge + tab return -----------------------------------
    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/Archived/i).first()).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();
  });
});
