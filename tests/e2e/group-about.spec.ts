import { test, expect } from '@playwright/test';

/**
 * E2E for #141 MC-2 "About" section — group Full display.
 *
 * Verifies the rendered group page for anonymous visitors:
 *   - a seeded group WITH About prose shows a visible "About" heading and
 *     non-empty prose body text under it, and
 *   - a seeded group WITH NO About prose shows NO "About" heading
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
 * TODO(F): if a future revision of this spec needs to pin an exact seeded
 * phrase (the way group-links.spec.ts pins SEEDED_LINK_TITLES), coordinate
 * the canonical group label + About text here AND in
 * docs/groups/scripts/step_700_demo_data.php, per the #140 precedent
 * recorded in handoff-T-red.md "Seed link titles". At RED time no such
 * canonical text exists yet, so this suite intentionally asserts structure
 * (heading present/absent + non-empty body) rather than exact copy.
 *
 * Runs against a fully seeded site (assemble -> site:install -> cim -> seed
 * step_700_demo_data.php -> runserver), per WAVE-EXECUTION-HANDOFF.md §6-6.
 */

const ABOUT_HEADING = /^About$/i;
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
  test('at least one seeded group shows an About heading with non-empty prose', async ({ page }) => {
    let foundWithAbout = false;

    for (const label of SEEDED_GROUP_LABELS) {
      const navigated = await gotoGroupByLabel(page, label);
      if (!navigated) {
        continue;
      }

      const heading = page.getByRole('heading', { name: ABOUT_HEADING });
      const hasHeading = await heading.isVisible().catch(() => false);
      if (!hasHeading) {
        continue;
      }

      foundWithAbout = true;

      // The About field wrapper carries non-empty prose text below the
      // heading — assert against the field wrapper class (shared idiom with
      // .field--name-field-group-links in group-links.spec.ts), not a pinned
      // phrase (none is canonical yet at RED time; see TODO above).
      const wrapper = page.locator('.field--name-field-group-about').first();
      await expect(wrapper).toBeVisible();
      const bodyText = (await wrapper.innerText()).trim();
      expect(
        bodyText.length,
        `The About field wrapper on "${label}" contains non-empty prose text.`,
      ).toBeGreaterThan(0);

      break;
    }

    expect(
      foundWithAbout,
      `At least one of the seeded groups (${SEEDED_GROUP_LABELS.join(', ')}) must show a visible "About" heading with prose.`,
    ).toBe(true);
  });

  test('at least one seeded group with no About prose shows no About heading', async ({ page }) => {
    let foundWithoutAbout = false;

    for (const label of SEEDED_GROUP_LABELS) {
      const navigated = await gotoGroupByLabel(page, label);
      if (!navigated) {
        continue;
      }

      const heading = page.getByRole('heading', { name: ABOUT_HEADING });
      const hasHeading = await heading.isVisible().catch(() => false);
      if (hasHeading) {
        continue;
      }

      foundWithoutAbout = true;
      await expect(heading).not.toBeVisible();
      break;
    }

    expect(
      foundWithoutAbout,
      `At least one of the seeded groups (${SEEDED_GROUP_LABELS.join(', ')}) must show NO "About" heading (empty-state).`,
    ).toBe(true);
  });
});
