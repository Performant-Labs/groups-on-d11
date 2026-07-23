import { test, expect } from '@playwright/test';

/**
 * E2E for #140 MC-1 "Links & Resources" — group Full display.
 *
 * Verifies the rendered group page for anonymous visitors:
 *   - shows a visible "Links & Resources" section, and
 *   - lists at least one known seeded link title under that section, and
 *   - every SEEDED external link title (matched by accessible name, not CSS
 *     descendant scoping) carries rel="noopener" (optionally
 *     "noopener noreferrer").
 *
 * Seed titles are picked here and MUST be seeded verbatim by F in
 * `docs/groups/scripts/step_700_demo_data.php` (see handoff-T-red.md /
 * decisions.md for the canonical list). This suite targets the
 * "DrupalCon Portland 2026" group (label unique in step_700 group_defs) via
 * the /all-groups directory, since gids are not stable across re-seeds.
 *
 * Runs against a fully seeded site (assemble -> site:install -> cim -> seed
 * step_700_demo_data.php -> runserver), per WAVE-EXECUTION-HANDOFF.md §6-6.
 *
 * CI-regression fix for #140 (PR#154, second F pass): the previous CSS
 * descendant-scoped locator (`.field--name-field-group-links
 * a[href^="http"]`) still matched Olivero's own footer "Powered by Drupal"
 * link (`<a href="https://www.drupal.org">`) in CI's rendered HTML, failing
 * this test with an empty `rel` attribute — that footer link is core theme
 * chrome, not this story's field output, and asserting on it is out of
 * scope for this feature. Rather than continue chasing CSS-class theory
 * (which is sensitive to exactly how/where the render wrapper nests in a
 * given theme/region), this test now looks up each SEEDED link by its
 * accessible name (`getByRole('link', { name: title, exact: true })`) drawn
 * from `SEEDED_LINK_TITLES` below. A role+name lookup can only ever match
 * the anchor(s) this story actually seeds — it cannot over-match onto
 * unrelated theme chrome, regardless of DOM nesting/CSS class shape.
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

  test('every seeded external link carries rel="noopener"', async ({ page, baseURL }) => {
    await page.goto('/all-groups');
    const card = page.locator('.gc-directory-card', { hasText: GROUP_LABEL }).first();
    await card.locator('.gc-directory-card__title a').first().click();
    await page.waitForURL(/\/group\/\d+/);

    const origin = new URL(baseURL ?? 'http://localhost').origin;
    let externalChecked = 0;

    for (const title of SEEDED_LINK_TITLES) {
      const anchor = page.getByRole('link', { name: title, exact: true }).first();
      // The link must exist (seeded); if not, that's the "anonymous sees"
      // test's failure to report — skip here rather than double-report.
      const count = await anchor.count();
      if (count === 0) {
        continue;
      }
      const href = await anchor.getAttribute('href');
      if (!href || href.startsWith(origin)) {
        continue;
      }
      externalChecked++;
      const rel = (await anchor.getAttribute('rel')) ?? '';
      expect(rel, `Seeded external link "${title}" (${href}) carries rel="noopener".`).toMatch(
        /noopener/,
      );
    }

    expect(
      externalChecked,
      `At least one seeded external link must exist to check rel="noopener" against (from titles: ${SEEDED_LINK_TITLES.join(', ')}).`,
    ).toBeGreaterThan(0);
  });
});
