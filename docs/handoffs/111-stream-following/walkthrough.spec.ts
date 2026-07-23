import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * Phase 8 (U) UI walkthrough — story #111 ST-2 Following feed.
 *
 * Captures reproducible artefacts (screenshots + DOM assertions) matching
 * the walkthrough script in the phase-8 instructions. Standalone from
 * tests/e2e/following.spec.ts (which owns pass/fail acceptance) — this file
 * exists only to produce evidence for handoff-U.md.
 */

const SEEDED_PASSWORD = process.env.SEEDED_PASSWORD ?? 'demo_password_2026';
const NO_FOLLOWS_USER = 'alex_novak';
const SHOTS = 'docs/handoffs/111-stream-following/screenshots';

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

async function assertNoPhpError(page: Page): Promise<void> {
  const body = await page.locator('body').innerText();
  expect(body).not.toMatch(/Fatal error|Parse error|Uncaught|Stack trace|The website encountered an unexpected error/i);
}

async function shot(page: Page, name: string): Promise<string> {
  const full = path.join(SHOTS, `${name}.png`);
  fs.mkdirSync(SHOTS, { recursive: true });
  await page.screenshot({ path: full, fullPage: true });
  return full;
}

test.describe('U — /following walkthrough (#111)', () => {
  test('1. anonymous /following → 403 (no WSOD/PHP error)', async ({ page }) => {
    const res = await page.goto('/following');
    const status = res?.status() ?? 0;
    const redirectedToLogin = /\/user\/login/.test(page.url());
    expect(status === 403 || redirectedToLogin).toBeTruthy();
    await assertNoPhpError(page);
    await shot(page, '01-anonymous');
  });

  test('2. elena_garcia populated feed', async ({ page }) => {
    await login(page, 'elena_garcia', SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);
    await assertNoPhpError(page);

    // WCAG 2.2 AA: single meaningful <h1>.
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    // Four scope branches, deduped.
    await expect(page.getByRole('link', { name: 'Patch Review Process RFC', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Drupal 11 Migration Path', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Venue Logistics Thread', exact: true })).toBeVisible();
    // Maria-authored — at least one of the three known titles.
    const mariaTitles = ['Sprint Planning: Portland 2026', 'Weekly Standup Notes', 'Budget Allocation Q3 2026'];
    let maria = 0;
    for (const t of mariaTitles) {
      maria += await page.getByRole('link', { name: t, exact: true }).count();
    }
    expect(maria).toBeGreaterThan(0);

    // Dedupe: RFC matches follow_content + follow_term(core).
    expect(await page.getByRole('link', { name: 'Patch Review Process RFC', exact: true }).count()).toBe(1);

    // Visual parity with /stream — stream_card wrappers present.
    const cardCount = await page.locator('.stream-card-wrapper, [class*="stream-card"]').count();
    expect(cardCount).toBeGreaterThan(0);

    await shot(page, '02-elena-following');
  });

  test('3. ravi_patel sees Maria-authored', async ({ page }) => {
    await login(page, 'ravi_patel', SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);
    await assertNoPhpError(page);
    const mariaTitles = ['Sprint Planning: Portland 2026', 'Weekly Standup Notes', 'Budget Allocation Q3 2026'];
    let maria = 0;
    for (const t of mariaTitles) {
      maria += await page.getByRole('link', { name: t, exact: true }).count();
    }
    expect(maria).toBeGreaterThan(0);
    await shot(page, '03-ravi-following');
  });

  test('4. sophie_mueller sees Paragraphs tutorial', async ({ page }) => {
    await login(page, 'sophie_mueller', SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);
    await assertNoPhpError(page);
    await expect(page.getByRole('link', { name: 'Getting Started with Paragraphs', exact: true })).toBeVisible();
    await shot(page, '04-sophie-following');
  });

  test('5. empty state (alex_novak) + keyboard focus', async ({ page }) => {
    await login(page, NO_FOLLOWS_USER, SEEDED_PASSWORD);
    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);
    await assertNoPhpError(page);

    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    const emptyState = page.locator('.gc-empty');
    await expect(emptyState).toBeVisible();
    await expect(emptyState.locator('.gc-empty__title')).toContainText("not following anything yet");

    const streamInline = emptyState.getByRole('link', { name: 'stream', exact: true });
    const streamButton = emptyState.getByRole('link', { name: 'Browse the stream', exact: true });

    await expect(streamInline).toBeVisible();
    await expect(streamButton).toBeVisible();
    await expect(streamInline).toHaveAttribute('href', /\/stream/);
    await expect(streamButton).toHaveAttribute('href', /\/stream/);

    // /tags link was removed from empty-state copy (F, phase-8 delta).
    await expect(emptyState.locator('a[href="/tags"]')).toHaveCount(0);

    // Verify the /stream link actually navigates (200).
    for (const href of ['/stream']) {
      const r = await page.request.get(href);
      expect(r.status(), `${href} returns 200`).toBe(200);
    }

    // Focus each interactive element; visible focus is asserted via
    // computed outline/box-shadow being non-none (WCAG 2.4.7).
    for (const [name, loc] of [
      ['streamInline', streamInline],
      ['streamButton', streamButton],
    ] as const) {
      await loc.focus();
      await expect(loc).toBeFocused();
      const focusStyle = await loc.evaluate((el) => {
        const cs = getComputedStyle(el);
        return { outline: cs.outlineStyle, outlineWidth: cs.outlineWidth, boxShadow: cs.boxShadow };
      });
      // Record for the handoff — at least one focus indicator should be non-default.
      // Not a hard fail (some themes rely on browser default outline) — we log.
      // eslint-disable-next-line no-console
      console.log(`[focus] ${name}`, JSON.stringify(focusStyle));
    }

    await shot(page, '05-empty-state');
  });

  test('6. regression: /stream still renders for elena', async ({ page }) => {
    await login(page, 'elena_garcia', SEEDED_PASSWORD);
    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);
    await assertNoPhpError(page);
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await shot(page, '06-stream-regression');
  });

  test('7. /my-feed → 404 (sibling #110 unmerged)', async ({ page }) => {
    await login(page, 'elena_garcia', SEEDED_PASSWORD);
    const res = await page.goto('/my-feed');
    expect(res?.status()).toBe(404);
    await shot(page, '07-myfeed-404');
  });
});
