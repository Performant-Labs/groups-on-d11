import { test, expect } from '@playwright/test';

/**
 * E2E for #140 MC-1 "Links & Resources" — group Full display.
 *
 * Verifies the rendered group page for anonymous visitors:
 *   - shows a visible "Links & Resources" section, and
 *   - lists at least one known seeded link title under that section, and
 *   - every external link (href starts with http and is not same-origin)
 *     carries rel="noopener" (optionally "noopener noreferrer").
 *
 * Seed titles are picked here and MUST be seeded verbatim by F in
 * `docs/groups/scripts/step_700_demo_data.php` (see handoff-T-red.md /
 * decisions.md for the canonical list). This suite targets the
 * "DrupalCon Portland 2026" group (label unique in step_700 group_defs) via
 * the /all-groups directory, since gids are not stable across re-seeds.
 *
 * Runs against a fully seeded site (assemble -> site:install -> cim -> seed
 * step_700_demo_data.php -> runserver), per WAVE-EXECUTION-HANDOFF.md §6-6.
 * Expected RED today: no field_group_links exists yet, so no seeded links
 * render and this suite fails (or the site itself cannot be seeded with
 * links at all) — see handoff-T-red.md for how RED was confirmed for this
 * file specifically.
 */

const GROUP_LABEL = 'DrupalCon Portland 2026';
// Canonical seed titles for this group (F must seed exactly these two —
// see handoff-T-red.md "Seed link titles" section).
const SEEDED_LINK_TITLES = ['Conference schedule', 'Sponsorship info'];

test.describe('Group Links & Resources (#140 MC-1)', () => {
  test('anonymous sees a Links & Resources section with a known seeded link', async ({
    page,
  }) => {
    // Find the group via the public directory rather than assuming a fixed
    // gid (gids are not stable across re-seeds of the demo data).
    await page.goto('/all-groups');
    const card = page.locator('.gc-directory-card', { hasText: GROUP_LABEL }).first();
    await expect(card).toBeVisible();
    const link = card.locator('.gc-directory-card__title a').first();
    await link.click();
    await page.waitForURL(/\/group\/\d+/);

    await expect(page.getByText('Links & Resources', { exact: false })).toBeVisible();

    // At least one of the two canonical seeded link titles must render as a
    // visible <a> on the page.
    let anyVisible = false;
    for (const title of SEEDED_LINK_TITLES) {
      const visible = await page
        .getByRole('link', { name: title, exact: true })
        .isVisible()
        .catch(() => false);
      if (visible) {
        anyVisible = true;
        break;
      }
    }
    expect(
      anyVisible,
      `At least one of the seeded link titles (${SEEDED_LINK_TITLES.join(', ')}) renders as a visible link on the ${GROUP_LABEL} page.`,
    ).toBe(true);
  });

  test('every external link on the group page carries rel="noopener"', async ({
    page,
    baseURL,
  }) => {
    await page.goto('/all-groups');
    const card = page.locator('.gc-directory-card', { hasText: GROUP_LABEL }).first();
    await card.locator('.gc-directory-card__title a').first().click();
    await page.waitForURL(/\/group\/\d+/);

    const origin = new URL(baseURL ?? 'http://localhost').origin;
    const anchors = page.locator('a[href^="http"]');
    const count = await anchors.count();
    let externalChecked = 0;

    for (let i = 0; i < count; i++) {
      const href = await anchors.nth(i).getAttribute('href');
      if (!href || href.startsWith(origin)) {
        continue;
      }
      externalChecked++;
      const rel = (await anchors.nth(i).getAttribute('rel')) ?? '';
      expect(rel, `External link ${href} carries rel="noopener".`).toMatch(/noopener/);
    }

    expect(
      externalChecked,
      'At least one external link exists on the group page to check rel="noopener" against.',
    ).toBeGreaterThan(0);
  });
});
