import { test, expect } from '@playwright/test';

/**
 * #132 SD-5 (Showcase help) — E2E, against the FULL SEEDED demo site
 * (assemble -> site:install -> config:import -> seed incl.
 * step_790_persona_switcher.php -> runserver -> playwright), matching
 * `persona-switcher.spec.ts`'s own established convention for this repo
 * ("E2E runs against the FULL SEEDED demo site ... not an isolated
 * fixture" — WAVE-EXECUTION-HANDOFF.md §6.6).
 *
 * Covers the three surfaces brief.md's own acceptance criterion names for
 * this spec:
 *   - the persona-banner ⓘ help trigger (Elena or Groups-Moderate),
 *   - a tour-page catalog-entry ⓘ help trigger (around-the-switcher
 *     orientation copy),
 *   - the map-orientation ⓘ adjacent to the stub switcher.
 *
 * Selector contract this spec pins (F implements against these, T-GREEN
 * re-runs verbatim):
 *   - Persona banner: `aside[role="status"].do-showcase-persona-banner`
 *     (unchanged from persona-switcher.spec.ts) containing one
 *     `[data-do-tooltip]` element.
 *   - Tour-page catalog entries: `.do-showcase-catalog-entry` (per
 *     `ShowcaseController::page()`'s existing
 *     `data-do-showcase-entry="<id>"` container, which also carries the
 *     `do-showcase-catalog-entry` class), each containing a
 *     `[data-do-tooltip]` node.
 *   - Map orientation: `.do-showcase-map-help[data-do-tooltip]`, whose
 *     tooltip text contains "map" (case-insensitive) and "Geographical"
 *     (case-sensitive substring, proving it is the map-specific copy and
 *     not generic filler).
 *
 * Seeded accounts (docs/groups/scripts/step_790_persona_switcher.php, same
 * as persona-switcher.spec.ts):
 *   - `elena_garcia`  (Elena Garcia — Member)
 *   - `groups_moderate_demo` (Groups-Moderate)
 *
 * do_showcase does not carry this help-copy wiring yet at RED time — every
 * `[data-do-tooltip]` selector below targets markup nothing in the codebase
 * renders yet. This spec is authored and confirmed to `--list` cleanly at
 * RED (structural/static validation — this environment has no live seeded
 * DDEV site reachable from this sandbox; see handoff-T-red.md for the
 * RED-by-construction reasoning per case, matching `persona-switcher.spec.ts`'s
 * own documented approach). It is executed for real against the seeded site
 * at T-GREEN.
 */

const SWITCHER_SELECT = 'select[name="persona"]';
const BANNER_SELECTOR = 'aside[role="status"].do-showcase-persona-banner';

test.describe('#132 SD-5 — Persona banner ⓘ help trigger', () => {
  // The site-wide POC ribbon (do_showcase, #119) is a fixed-position
  // overlay that intercepts pointer events on the persona-switcher "Go"
  // <button> immediately after form submit redirects — Playwright's
  // post-click stability re-check then re-targets the (new page's) Go
  // button under the ribbon and times out (CI cycle 3 diagnostic on
  // PR#157: TimeoutError, "<div id=do-showcase-ribbon> intercepts pointer
  // events"). Dismissing the ribbon first eliminates the race. Matches
  // the dismiss pattern already used by showcase.spec.ts:346.
  test.beforeEach(async ({ page }) => {
    await page.goto('/');
    const dismiss = page.getByRole('button', { name: 'Dismiss demo banner' });
    if (await dismiss.isVisible().catch(() => false)) {
      await dismiss.click();
    }
  });

  test('Elena Garcia: banner ⓘ is visible, keyboard-focusable, non-empty aria-label', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await expect(select).toBeVisible();
    await select.selectOption({ label: 'Elena Garcia — Member' });

    const goButton = page.getByRole('button', { name: /go/i });
    if (await goButton.isVisible().catch(() => false)) {
      await goButton.click();
    }
    await page.waitForLoadState('networkidle');

    const banner = page.locator(BANNER_SELECTOR);
    await expect(banner).toBeVisible();

    const help = banner.locator('[data-do-tooltip]');
    await expect(help).toHaveCount(1);
    await expect(help).toHaveAttribute('tabindex', '0');
    await expect(help).toHaveAttribute('role', 'note');

    const ariaLabel = await help.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();

    // Keyboard-reachable: focusing the element directly (matching
    // persona-switcher.spec.ts's WCAG focus-outline test convention) proves
    // it is a real focusable node, not merely tabindex-decorated dead markup.
    await help.focus();
    await expect(help).toBeFocused();
  });

  test('Groups-Moderate: banner ⓘ is visible with non-empty tooltip copy', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await select.selectOption({ label: 'Groups-Moderate' });

    const goButton = page.getByRole('button', { name: /go/i });
    if (await goButton.isVisible().catch(() => false)) {
      await goButton.click();
    }
    await page.waitForLoadState('networkidle');

    const banner = page.locator(BANNER_SELECTOR);
    await expect(banner).toBeVisible();

    const help = banner.locator('[data-do-tooltip]');
    await expect(help).toHaveCount(1);
    const tooltip = await help.getAttribute('data-do-tooltip');
    expect(tooltip?.trim().length).toBeGreaterThan(0);
  });
});

test.describe('#132 SD-5 — Tour page around-the-switcher ⓘ', () => {
  test('each catalog entry on /showcase contains a help-trigger node', async ({
    page,
  }) => {
    const res = await page.goto('/showcase');
    expect(res?.status()).toBe(200);

    const entries = page.locator('.do-showcase-catalog-entry');
    await expect(entries).not.toHaveCount(0);

    const count = await entries.count();
    for (let i = 0; i < count; i++) {
      const entry = entries.nth(i);
      const help = entry.locator('[data-do-tooltip]');
      await expect(help).toHaveCount(1);
      const tooltip = await help.getAttribute('data-do-tooltip');
      expect(tooltip?.trim().length).toBeGreaterThan(0);
    }
  });

  test('the discovery-ranking entry help trigger is keyboard-reachable', async ({
    page,
  }) => {
    await page.goto('/showcase');
    const entry = page.locator('[data-do-showcase-entry="discovery-ranking"]');
    const help = entry.locator('[data-do-tooltip]');
    await expect(help).toBeVisible();
    await expect(help).toHaveAttribute('tabindex', '0');
    await expect(help).toHaveAttribute('role', 'note');
    const ariaLabel = await help.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
  });
});

test.describe('#132 SD-5 — Map orientation ⓘ', () => {
  test('the map help trigger names the map view and the Geographical group type', async ({
    page,
  }) => {
    await page.goto('/showcase');

    const mapHelp = page.locator('.do-showcase-map-help[data-do-tooltip]');
    await expect(mapHelp).toBeVisible();

    const tooltip = await mapHelp.getAttribute('data-do-tooltip');
    expect(tooltip).toBeTruthy();
    expect(tooltip?.toLowerCase()).toContain('map');
    expect(tooltip).toContain('Geographical');
  });

  test('the map help trigger is keyboard-reachable', async ({ page }) => {
    await page.goto('/showcase');
    const mapHelp = page.locator('.do-showcase-map-help[data-do-tooltip]');
    await expect(mapHelp).toHaveAttribute('tabindex', '0');
    await expect(mapHelp).toHaveAttribute('role', 'note');
  });
});
