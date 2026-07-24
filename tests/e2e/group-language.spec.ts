import { test, expect, Page } from '@playwright/test';

/**
 * E2E for MC-4 Multilingual baseline + RTL — story #139.
 *
 * Verifies the seeded RTL-primary group ("Drupal العربية", field_group_language
 * = 'ar', added to step_760.php) renders right-to-left end to end, that the
 * existing French group renders left-to-right, and that the /all-groups
 * directory exposes the primary language via a new Views field (core's
 * `language` formatter, which emits the language NAME — e.g. "Arabic" /
 * "French" — not the raw langcode).
 *
 * RED reason at T-red time: neither the Arabic group seed, the
 * `do_group_language` render hook (`.do-group-language` indicator), nor the
 * `field_group_language` Views field on `all_groups` exist yet. Every
 * assertion below targets markup/data this story's F phase introduces.
 * F has not run yet; per the task instructions this spec is NOT executed
 * live against the site at T-red (no seed, no hook, no Views field) — only
 * `--list`-checked for syntactic validity. It will be re-run for real at
 * T-green after F implements + the site is reseeded.
 *
 * No auth needed — all three cases are anonymous, matching the brief's
 * acceptance criteria (RTL rendering + directory language column are both
 * anonymous-visible surfaces).
 */

const ARABIC_GROUP_LABEL = 'Drupal العربية';
const FRENCH_GROUP_LABEL = 'Drupal France';

/**
 * Resolves a seeded group's canonical path by finding its link on the
 * /all-groups directory listing (mirrors the "no hardcoded gid" convention
 * used elsewhere in this suite — directory-cards.spec.ts locates entities
 * via rendered directory markup rather than a fixed id).
 */
async function resolveGroupPath(page: Page, label: string): Promise<string> {
  await page.goto('/all-groups');
  const row = page.locator('tr, .views-row').filter({ hasText: label });
  const link = row.getByRole('link', { name: label, exact: true });
  const href = await link.getAttribute('href');
  expect(href, `expected a link href for group "${label}" on /all-groups`).toBeTruthy();
  return href as string;
}

test.describe('MC-4 — Multilingual baseline + RTL (#139)', () => {
  test('RTL Arabic group renders dir="rtl" with language indicator', async ({
    page,
  }) => {
    const groupPath = await resolveGroupPath(page, ARABIC_GROUP_LABEL);

    const res = await page.goto(groupPath);
    expect(res?.status()).toBe(200);

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('.do-group-language[lang="ar"]')).toBeVisible();
  });

  test('LTR French group renders dir="ltr" with language indicator', async ({
    page,
  }) => {
    const groupPath = await resolveGroupPath(page, FRENCH_GROUP_LABEL);

    const res = await page.goto(groupPath);
    expect(res?.status()).toBe(200);

    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('.do-group-language[lang="fr"]')).toBeVisible();
  });

  test('directory /all-groups shows language column', async ({ page }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    // The core Views `language` formatter emits the language NAME (e.g.
    // "Arabic", "French"), not the raw langcode — per brief.md v3's warn
    // about the anonymous English UI's rendering.
    const arabicRow = page
      .locator('tr, .views-row')
      .filter({ hasText: ARABIC_GROUP_LABEL });
    await expect(arabicRow).toContainText('Arabic');

    const frenchRow = page
      .locator('tr, .views-row')
      .filter({ hasText: FRENCH_GROUP_LABEL });
    await expect(frenchRow).toContainText('French');
  });
});
