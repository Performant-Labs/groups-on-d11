import { test, expect, Page } from '@playwright/test';

/**
 * Issue #121 (SC-2) — Membership models enforced: request-to-join
 * (Leadership Council) + invite-only (Core Committers).
 *
 * Walks all three join-policy flows against the FULLY SEEDED demo site
 * (docs/groups/scripts/step_700_demo_data.php), per WAVE-EXECUTION-HANDOFF
 * §6 gotcha 6 — E2E must run against the seeded site, not an isolated
 * fixture. Uses seeded personas (sophie_mueller / alex_novak — NOT Elena,
 * who is already a member of both closed groups per the survey) and seeded
 * groups (Drupal France = open, Leadership Council = moderated, Core
 * Committers = invite_only). No fresh registration.
 *
 * RED (Phase 4, authored by T before F implements): at RED time this spec
 * cannot be run against a live seeded site in this session (no seeded
 * install is up), so RED verification for E2E happens at T-green (Phase 6)
 * once F has implemented the routes/forms/seed changes and a seeded site is
 * spun up, per the task instructions. This file is authored now so its
 * selectors and flow are locked in as the contract F implements against.
 *
 * Locator note (WAVE-EXECUTION-HANDOFF §6 gotcha 9 / G9): Drupal's
 * `#type => submit` renders `<input type="submit">`, not `<button>`. The
 * "Request to join" control is located with BOTH an accessible-role query
 * AND a raw attribute-selector fallback (belt-and-braces), matching the
 * brief's explicit AC-10 requirement.
 */

const SOPHIE_USER = process.env.SOPHIE_USER ?? 'sophie_mueller';
const SOPHIE_PASS = process.env.SOPHIE_PASS ?? 'demo_password_2026';
const ALEX_USER = process.env.ALEX_USER ?? 'alex_novak';
const ALEX_PASS = process.env.ALEX_PASS ?? 'demo_password_2026';

const OPEN_GROUP_NAME = 'Drupal France';
const MODERATED_GROUP_NAME = 'Leadership Council';
const INVITE_ONLY_GROUP_NAME = 'Core Committers';

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

/**
 * Navigates to a seeded group's canonical page by its label, via the
 * /all-groups directory listing (avoids hard-coding a group id, which is
 * seed-order-dependent).
 */
async function goToGroupByName(page: Page, name: string): Promise<void> {
  await page.goto('/all-groups', { waitUntil: 'domcontentloaded' });
  await page.getByRole('link', { name, exact: true }).click();
  await page.waitForURL(/\/group\/\d+(\/|$)/);
}

/**
 * Locates the "Request to join" control on the current page, using BOTH
 * an accessible-role query and a raw input[type=submit] fallback (G9
 * belt-and-braces — Drupal #type=>submit renders <input>, not <button>).
 */
function requestToJoinControl(page: Page) {
  return page
    .getByRole('button', { name: /Request to join/i })
    .or(page.locator('input[type="submit"][value*="Request"]'));
}

function joinControl(page: Page) {
  return page
    .getByRole('button', { name: /^Join$/i })
    .or(page.locator('input[type="submit"][value*="Join"]'));
}

test.describe('Membership models enforced (#121 SC-2)', () => {
  test('sophie_mueller joins Drupal France (open) instantly', async ({ page }) => {
    await login(page, SOPHIE_USER, SOPHIE_PASS);
    await goToGroupByName(page, OPEN_GROUP_NAME);

    const join = joinControl(page);
    await expect(join).toBeVisible();
    await join.click();

    await expect(page.locator('body')).toContainText(/now a member|joined/i);
  });

  test('sophie_mueller sees "Request to join" on Leadership Council (moderated) and requests it', async ({ page }) => {
    await login(page, SOPHIE_USER, SOPHIE_PASS);
    await goToGroupByName(page, MODERATED_GROUP_NAME);

    const request = requestToJoinControl(page);
    await expect(request).toBeVisible();

    // WCAG smoke: the control is keyboard-focusable and receives visible
    // focus (basic focus-outline check — full axe scanning is U's remit).
    await request.focus();
    const isFocused = await request.evaluate((el) => el === document.activeElement);
    expect(isFocused, 'The "Request to join" control is keyboard-focusable.').toBe(true);

    await request.click();

    await expect(page.locator('body')).toContainText(/pending|request.*sent|awaiting approval/i);
  });

  test('sophie_mueller sees NO Join or Request-to-join control on Core Committers (invite_only)', async ({ page }) => {
    await login(page, SOPHIE_USER, SOPHIE_PASS);
    await goToGroupByName(page, INVITE_ONLY_GROUP_NAME);

    await expect(joinControl(page)).toHaveCount(0);
    await expect(requestToJoinControl(page)).toHaveCount(0);
  });

  test('alex_novak (second non-member persona) also sees no join path on Core Committers', async ({ page }) => {
    await login(page, ALEX_USER, ALEX_PASS);
    await goToGroupByName(page, INVITE_ONLY_GROUP_NAME);

    await expect(joinControl(page)).toHaveCount(0);
    await expect(requestToJoinControl(page)).toHaveCount(0);
  });
});
