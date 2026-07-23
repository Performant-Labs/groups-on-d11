import { test, expect } from '@playwright/test';

/**
 * E2E for #142 MC-3 — Directory location + primary-language exposed filters.
 *
 * `docs/planning/handoffs/142-directory-filters/brief.md` (v2) adds two
 * combinable exposed filters to `/all-groups`:
 *   1. Location — free-text "contains" against the new
 *      `field_group_location_text` string field.
 *   2. Primary language — select against `field_group_language`, exposed via
 *      the core Views `language` filter plugin.
 * Both must combine with each other and with the existing "search" label
 * filter, and Group access-control (archived/unlisted/private hidden) must be
 * preserved (pinned separately by the kernel test
 * `DirectoryFiltersTest::testAnonymousExecutionExcludesArchivedGroup`).
 *
 * SEED DEPENDENCY (mirrors `group-links.spec.ts`'s pattern): the language
 * field is deliberately absent from the `/group/add/community_group` create
 * form (`GroupAddFormFieldsTest` — "not user-picked at creation"), and the new
 * location field is unlikely to be added to that form either (out of the
 * brief's scope). So this suite does NOT self-seed via the live UI form;
 * instead it requires F to seed the following THREE groups verbatim in
 * `docs/groups/scripts/step_700_demo_data.php` (published/public, so they are
 * visible to an anonymous visitor regardless of this story's access-safety
 * behavior):
 *
 *   | Label (must be unique + stable across re-seeds) | Location  | Language |
 *   |--------------------------------------------------|-----------|----------|
 *   | 'Filter Test Berlin English'                      | Berlin    | en       |
 *   | 'Filter Test Paris French'                         | Paris     | fr       |
 *   | 'Filter Test Berlin French'                        | Berlin    | fr       |
 *
 * This 2x2-minus-one arrangement lets the suite prove each filter
 * independently AND their intersection: "Berlin" matches 2 of the 3
 * (English + French), "fr" matches 2 of the 3 (Paris + Berlin), and
 * "Berlin" + "fr" together match exactly 1 (Filter Test Berlin French).
 *
 * If the seed labels above are not present when this suite runs, the first
 * assertion in each test (locating the seeded card) fails clearly, rather
 * than silently passing on an empty result set — this is a coverage
 * precondition, not a false pass.
 *
 * Runs against a fully seeded site (assemble -> site:install -> cim -> seed
 * step_700_demo_data.php -> runserver), per WAVE-EXECUTION-HANDOFF.md §6-6.
 */

const SEED_GROUPS = {
  berlinEnglish: 'Filter Test Berlin English',
  parisFrench: 'Filter Test Paris French',
  berlinFrench: 'Filter Test Berlin French',
};

test.describe('Directory location + language filters (#142 MC-3)', () => {
  test('both exposed filter controls are present and labeled', async ({ page }) => {
    await page.goto('/all-groups');

    // WCAG: both controls must be reachable via their accessible label, not
    // merely present in the DOM.
    const locationInput = page.getByLabel(/location/i);
    await expect(locationInput).toBeVisible();

    const languageInput = page.getByLabel(/language/i);
    await expect(languageInput).toBeVisible();

    // Keyboard operability: both controls can receive focus.
    await locationInput.focus();
    await expect(locationInput).toBeFocused();
    await languageInput.focus();
    await expect(languageInput).toBeFocused();
  });

  test('the location filter narrows results to matching groups', async ({ page }) => {
    await page.goto('/all-groups');

    // Sanity: before filtering, all three seeded groups are visible.
    for (const label of Object.values(SEED_GROUPS)) {
      await expect(
        page.locator('.gc-directory-card', { hasText: label }),
      ).toBeVisible();
    }

    const locationInput = page.getByLabel(/location/i);
    await locationInput.fill('Berlin');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]location=Berlin/i);

    // Both Berlin groups (English + French) are present; Paris is excluded.
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.berlinEnglish }),
    ).toBeVisible();
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.berlinFrench }),
    ).toBeVisible();
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.parisFrench }),
    ).toHaveCount(0);
  });

  test('the language filter narrows results to a matching langcode', async ({ page }) => {
    await page.goto('/all-groups');

    const languageInput = page.getByLabel(/language/i);
    // The exposed Language filter renders as a select whose option values are
    // langcodes (core LanguageFilter::getValueOptions() keys by langcode); pick
    // French by its 'fr' value rather than by visible label text, which core
    // may render as "French" or the native "français" depending on locale.
    await languageInput.selectOption('fr');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]field_group_language(\[\])?=fr/i);

    // Both French groups (Paris + Berlin) are present; the English one is
    // excluded.
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.parisFrench }),
    ).toBeVisible();
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.berlinFrench }),
    ).toBeVisible();
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.berlinEnglish }),
    ).toHaveCount(0);
  });

  test('combining location + language yields the intersection', async ({ page }) => {
    await page.goto('/all-groups');

    const locationInput = page.getByLabel(/location/i);
    const languageInput = page.getByLabel(/language/i);

    await locationInput.fill('Berlin');
    await languageInput.selectOption('fr');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]location=Berlin/i);

    // Only the group matching BOTH filters (Berlin + French) remains.
    const cards = page.locator('.gc-directory-card');
    await expect(cards).toHaveCount(1);
    await expect(
      page.locator('.gc-directory-card', { hasText: SEED_GROUPS.berlinFrench }),
    ).toBeVisible();
  });

  test('the reset button clears both filters and restores the full result set', async ({
    page,
  }) => {
    await page.goto('/all-groups');

    const locationInput = page.getByLabel(/location/i);
    const languageInput = page.getByLabel(/language/i);

    await locationInput.fill('Berlin');
    await languageInput.selectOption('fr');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]location=Berlin/i);

    await page.getByRole('link', { name: 'Reset' }).click();
    await page.waitForURL((url) => !url.search.includes('location'));

    await expect(locationInput).toHaveValue('');

    // All three seeded groups are visible again.
    for (const label of Object.values(SEED_GROUPS)) {
      await expect(
        page.locator('.gc-directory-card', { hasText: label }),
      ).toBeVisible();
    }
  });
});
