import { test, expect, Page } from '@playwright/test';

/**
 * Issue #144 (MC-6) — Create-Group flow E2E: creator auto-becomes Organizer
 * + guided preview page.
 *
 * RED (Phase 4, authored by T before F implements). Walks: log in as a
 * persona with create-group permission -> navigate to
 * /group/add/community_group -> complete the wizard (creator_wizard: true
 * makes this a REAL multi-step wizard on the assembled site, per the brief's
 * "IMPORTANT" section) -> land on /group/{group}/created -> assert the
 * guided-preview content renders -> click "Manage members" CTA -> assert the
 * creator is listed with Organizer role on the manage-members page.
 *
 * WIZARD STEP UNCERTAINTY (flagged, not guessed): the exact wizard step
 * count/labels were not empirically verified at RED-author time (no running
 * DDEV site available to T). `completeWizard()` below defensively submits
 * whatever primary action button each step presents (in priority order),
 * matching the same defensive-walk approach as the sibling functional test
 * `CreateGroupWizardOrganizerTest::advanceThroughWizard()`. This is expected
 * to need one round of empirical correction once run against a real
 * assembled+seeded site (F/T-green's job, per the brief).
 *
 * Locator conventions (per this project's established gotchas, matching
 * manage-members.spec.ts): `#type => submit` renders `<input type=submit>`
 * NOT `<button>` — `getByRole('button', {name})` still matches it via ARIA
 * role mapping, which is used throughout. `getByLabel(/.../)`  can
 * strict-mode-collide on a seeded page — locators are scoped to the
 * relevant form/section where collision risk exists.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

async function login(page: Page, user = ADMIN_USER, pass = ADMIN_PASS): Promise<void> {
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

/**
 * Defensively advances through the community_group add-form's wizard steps
 * (creator_wizard: true), submitting whatever primary action button each
 * step presents, until the URL leaves `/group/add/`.
 *
 * Mirrors `CreateGroupWizardOrganizerTest::advanceThroughWizard()`'s
 * priority-ordered button-label search so the two suites converge on the
 * same empirical correction if the real wizard step sequence differs from
 * what's anticipated here.
 */
async function completeWizard(page: Page, maxSteps = 6): Promise<void> {
  const primaryPatterns: RegExp[] = [
    /^Create Community Group and become a member$/,
    /^Create Community Group$/,
    /^Save and continue$/,
    /^Next$/,
    /^Save$/,
  ];

  for (let step = 0; step < maxSteps; step++) {
    let clicked = false;
    for (const pattern of primaryPatterns) {
      const button = page.getByRole('button', { name: pattern });
      if (await button.first().isVisible({ timeout: 1000 }).catch(() => false)) {
        await button.first().click();
        clicked = true;
        break;
      }
    }

    if (!clicked) {
      const allButtons = await page
        .locator('input[type="submit"], button')
        .allInnerTexts();
      throw new Error(
        `completeWizard(): no recognized primary action button found at wizard step ${step + 1}. ` +
        `Buttons present: [${allButtons.join(', ')}]. This is the empirical correction the brief ` +
        `anticipates (handoff-A.md Q3) — update the primaryPatterns list once the real wizard is observed.`,
      );
    }

    await page.waitForLoadState('domcontentloaded');
    const path = new URL(page.url()).pathname;
    if (!path.startsWith('/group/add/')) {
      return;
    }
  }

  throw new Error('completeWizard(): exceeded maxSteps without leaving /group/add/ — the wizard may have more steps than anticipated.');
}

test.describe('Create-group flow (#144 MC-6)', () => {
  test('creator becomes Organizer, lands on guided preview, and reaches manage-members', async ({
    page,
  }) => {
    await login(page);

    const groupName = `OrganizerFlowG ${Date.now()}`;

    await page.goto('/group/add/community_group');
    await page.getByLabel('Title', { exact: false }).fill(groupName);

    await completeWizard(page);

    // AC-3: the final wizard step redirects to /group/{group}/created, NOT
    // the group canonical page.
    await expect(page).toHaveURL(/\/group\/\d+\/created$/);
    const gid = page.url().match(/\/group\/(\d+)\/created/)![1];

    // AC-4/AC-5: guided-preview content renders — h1 naming the group, a
    // paragraph mentioning Organizer, an h2, and three CTA links (per the
    // wireframe's h1 -> p -> h2 -> ul>li>a DOM order).
    const heading = page.getByRole('heading', { level: 1 });
    await expect(heading).toBeVisible();
    await expect(heading).toContainText(groupName);

    await expect(page.locator('p', { hasText: /Organizer/i })).toBeVisible();

    const subheading = page.getByRole('heading', { level: 2 });
    await expect(subheading).toBeVisible();
    await expect(page.locator('h3')).toHaveCount(0);

    const ctaList = page.locator('ul').filter({ has: page.locator('a') }).first();
    const ctaLinks = ctaList.locator('a');
    await expect(ctaLinks).toHaveCount(3);

    // Click through to manage-members via its CTA (never a bare "click here").
    const manageMembersLink = page.getByRole('link', { name: /Manage members/i });
    await expect(manageMembersLink).toBeVisible();
    await manageMembersLink.click();

    await page.waitForURL(new RegExp(`/group/${gid}/members`));

    // The creator is listed with the Organizer role on the manage-members
    // page (AC-1's end-to-end proof, AC-7).
    const creatorRow = page.locator('tr', { hasText: ADMIN_USER });
    await expect(creatorRow).toBeVisible();
    await expect(creatorRow).toContainText(/Organizer/i);
  });
});
