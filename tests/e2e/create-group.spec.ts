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
 * Locator conventions (per this project's established gotchas, matching
 * manage-members.spec.ts + the working phase1.spec.ts create-group probe):
 * `#type => submit` renders `<input type=submit>` NOT `<button>` —
 * `getByRole('button', {name})` still matches it via ARIA role mapping,
 * which is used throughout. `getByLabel(/.../)`  can strict-mode-collide on
 * a seeded page — locators are scoped to the relevant form/section where
 * collision risk exists.
 *
 * CI-cycle-1 diagnosis (2026-07-23): the original RED assumed
 * `field_group_description` was a plain textarea and the visibility field
 * was optional; both wrong on the assembled community_group type.
 * `field_group_description` is a **CKEditor 5** field (hidden textarea
 * proxied by a contenteditable `.ck-editor__editable`; `.fill()` on the
 * hidden textarea does NOT propagate to CKEditor's model, so the form
 * submits with an empty value and re-renders on the same URL with a
 * validation error — which caused completeWizard() to loop forever). And
 * `field_group_visibility` is a REQUIRED radio group. The working create-
 * group probe in `phase1.spec.ts:84` (which PASSED in the same CI run) shows
 * the correct fill pattern — mirrored below.
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
 * what's anticipated here. On the assembled community_group type, F's
 * empirical finding (handoff-F.md "Empirical decisions") confirmed the
 * wizard resolves as effectively single-step: this loop exits after 1
 * iteration.
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

    // field_group_description is a REQUIRED **CKEditor 5** field on the real
    // assembled community_group type — NOT a plain textarea. The `.fill()`
    // on the hidden textarea does NOT propagate to CKEditor's model, so we
    // click the contenteditable and type real keystrokes so CKEditor syncs
    // to the hidden textarea on submit. Pattern mirrors the working
    // phase1.spec.ts:84 "authenticated user can create group" probe (see
    // this file's header for CI-cycle-1 diagnosis).
    const descEditor = page.locator('.ck-editor__editable').first();
    await descEditor.waitFor({ state: 'visible' });
    await descEditor.click();
    await descEditor.pressSequentially('An E2E-created group for #144 verification.');

    // field_group_visibility is a REQUIRED radio group on the real assembled
    // community_group type — mirror the phase1 probe: check the 'open' radio
    // by name+value (never by label — the label may collide on a seeded
    // page).
    await page
      .locator('input[name="field_group_visibility"][value="open"]')
      .check();

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

    // Scope by accessible name — the page has 6 h2s from theme chrome
    // (toolbar, main-menu, status-message, breadcrumb, footer-menu) on a
    // fully-seeded site; an unscoped getByRole('heading',{level:2}) strict-
    // mode-collides. Our preview's h2 has stable copy "What's next?" set
    // in GroupCreatedPreviewController::view() (line 99).
    const subheading = page.getByRole('heading', { level: 2, name: /What.s next\?/ });
    await expect(subheading).toBeVisible();

    // The preview ul is uniquely identifiable by the .do-group-membership--next-steps
    // class F ships on it (GroupCreatedPreviewController::view() line 105).
    // Scope the CTA-list assertions there rather than to "first ul with a link"
    // (which on the seeded/themed page might match the primary nav or footer nav).
    const ctaList = page.locator('ul.do-group-membership--next-steps');
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
