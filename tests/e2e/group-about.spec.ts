import { test, expect } from '@playwright/test';

/**
 * E2E for #141 MC-2 "About" section — group Full display.
 *
 * Verifies the rendered group page for anonymous visitors:
 *   - a seeded group WITH About prose shows a visible "About" field label +
 *     non-empty prose body text under it, and
 *   - a seeded group WITH NO About prose shows NO About field wrapper at all
 *     (empty-state suppression, per brief AC-6 / A warn #4).
 *
 * Seed reality (per brief §"Test-first outline" + step_700_demo_data.php
 * survey): F has not yet written the About seed setter at RED-authoring
 * time, so this suite does NOT pin a specific group label or a specific
 * prose phrase. Instead it discovers groups via the public directory
 * (`/all-groups`, mirroring group-links.spec.ts's navigation convention) and
 * asserts the PRESENCE/ABSENCE contract against whichever groups F actually
 * seeds — at least one group must have About, at least one must not,
 * matching the brief's "set About prose on 2-3 seeded groups" scope (8
 * groups total in step_700, so an about-free remainder is guaranteed).
 *
 * T-green correction (post-F, live-site verification): the RED-time spec
 * asserted `getByRole('heading', { name: /^About$/i })`, assuming the field
 * label rendered as an accessible heading. Verified against the real
 * rendered markup (`curl` a seeded group page) that Drupal's default
 * `field--label-above` template renders the label as a plain
 * `<div class="field__label">About</div>` — NOT a heading element — exactly
 * mirroring the sibling `field_group_links` field (see group-links.spec.ts,
 * which likewise asserts via `getByText`, not `getByRole('heading', ...)`).
 * The group page ALSO has an unrelated "About" **tab link** in the group nav
 * (`gc-group-tabs__link`, present on every group regardless of About
 * content), which a bare unscoped `getByText('About')` would collide with.
 * Fixed by asserting PRESENCE/ABSENCE of the `.field--name-field-group-about`
 * wrapper directly (the same idiom group-links.spec.ts uses for its own
 * field), then asserting the field's own label text and body text inside
 * that scoped wrapper — this is a test-authorship fix, not a production
 * change; the shipped markup is correct Drupal field-template output.
 *
 * TODO(F): if a future revision of this spec needs to pin an exact seeded
 * phrase (the way group-links.spec.ts pins SEEDED_LINK_TITLES), coordinate
 * the canonical group label + About text here AND in
 * docs/groups/scripts/step_700_demo_data.php, per the #140 precedent
 * recorded in handoff-T-red.md "Seed link titles". No such canonical text
 * exists yet, so this suite intentionally asserts structure (wrapper
 * present/absent + non-empty body) rather than exact copy.
 *
 * Runs against a fully seeded site (assemble -> site:install -> cim -> seed
 * step_700_demo_data.php -> runserver), per WAVE-EXECUTION-HANDOFF.md §6-6.
 */

// Community groups seeded by step_700_demo_data.php (Step 730 $group_defs).
// Mirrors the full label set so "at least one has / at least one lacks
// About" can be checked without assuming which subset F picks.
const SEEDED_GROUP_LABELS = [
  'DrupalCon Portland 2026',
  'Drupal France',
  'Core Committers',
  'Thunder Distribution',
  'Leadership Council',
  'Camp Organizers EMEA',
  'Drupal Deutschland',
  'Legacy Infrastructure',
];

/**
 * Navigates to a seeded group's Full display page via the public directory.
 */
async function gotoGroupByLabel(page: import('@playwright/test').Page, label: string): Promise<boolean> {
  await page.goto('/all-groups');
  const card = page.locator('.gc-directory-card', { hasText: label }).first();
  const visible = await card.isVisible().catch(() => false);
  if (!visible) {
    return false;
  }
  const link = card.locator('.gc-directory-card__title a').first();
  await link.click();
  await page.waitForURL(/\/group\/\d+/);
  return true;
}

test.describe('Group About section (#141 MC-2)', () => {
  test('at least one seeded group shows an About section with non-empty prose', async ({ page }) => {
    let foundWithAbout = false;

    for (const label of SEEDED_GROUP_LABELS) {
      const navigated = await gotoGroupByLabel(page, label);
      if (!navigated) {
        continue;
      }

      // The field wrapper (`.field--name-field-group-about`) is the ONLY
      // reliable presence signal — the field's own label renders as a plain
      // <div class="field__label">, not a heading, and the group nav ALSO
      // has an unrelated "About" tab link present on every group page.
      const wrapper = page.locator('.field--name-field-group-about').first();
      const hasWrapper = await wrapper.isVisible().catch(() => false);
      if (!hasWrapper) {
        continue;
      }

      foundWithAbout = true;

      await expect(wrapper).toBeVisible();
      await expect(wrapper.locator('.field__label')).toHaveText('About');
      const bodyText = (await wrapper.innerText()).trim();
      expect(
        bodyText.length,
        `The About field wrapper on "${label}" contains non-empty prose text.`,
      ).toBeGreaterThan('About'.length);

      break;
    }

    expect(
      foundWithAbout,
      `At least one of the seeded groups (${SEEDED_GROUP_LABELS.join(', ')}) must show a visible About section with prose.`,
    ).toBe(true);
  });

  test('at least one seeded group with no About prose shows no About section', async ({ page }) => {
    let foundWithoutAbout = false;

    for (const label of SEEDED_GROUP_LABELS) {
      const navigated = await gotoGroupByLabel(page, label);
      if (!navigated) {
        continue;
      }

      const wrapper = page.locator('.field--name-field-group-about').first();
      const hasWrapper = await wrapper.isVisible().catch(() => false);
      if (hasWrapper) {
        continue;
      }

      foundWithoutAbout = true;
      await expect(wrapper).not.toBeVisible();
      break;
    }

    expect(
      foundWithoutAbout,
      `At least one of the seeded groups (${SEEDED_GROUP_LABELS.join(', ')}) must show NO About section (empty-state).`,
    ).toBe(true);
  });
});
