import { test, expect, Page, BrowserContext } from '@playwright/test';

/**
 * Desktop nav fit / no-flicker regression suite.
 *
 * Background. Olivero's `nav-resize.js` watches the primary-nav <ul> with a
 * ResizeObserver. If the list wraps to a second line it adds
 * `is-always-mobile-nav` to <body>, which collapses the whole desktop nav to a
 * hamburger. Because that script only runs once the footer JS has executed,
 * the desktop nav paints first and *then* vanishes — the "menu flickers on
 * refresh" report.
 *
 * #300 made the ANONYMOUS nav genuinely fit. This suite locks in the
 * AUTHENTICATED fix (see "Desktop nav fit, part 2" in
 * groups_chrome/css/chrome.css) and guards both against the two ways it could
 * regress:
 *
 *   1. the nav starts flickering again (it wraps and gets collapsed), or
 *   2. someone "fixes" the flicker the way #287 did — by force-adding
 *      `is-always-mobile-nav` server-side, which kills the flicker by
 *      permanently HIDING the desktop nav. Test 2 below fails on that.
 *
 * Method. A probe is installed at document_start and samples, every 4ms across
 * a cold load, whether the nav is on screen and whether <body> carries
 * `is-always-mobile-nav`. Any change in either value is a transition; a
 * flicker-free load has zero transitions.
 *
 * Not covered on purpose: Olivero core has a rare first-paint race inside
 * nav-resize.js that can collapse the nav once even when it fits. It is
 * upstream and not something this theme can fix; the assertions below run
 * against the real fitted layout, where it has not been observed.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

/** Widths at which Olivero uses the DESKTOP nav (its breakpoint is 75rem). */
const DESKTOP_WIDTHS = [1440, 1280, 1200];

/** Widths at which Olivero uses the mobile drawer + hamburger. */
const MOBILE_WIDTHS = [1100, 900, 375];

/** Level-1 primary-nav <ul> — deliberately NOT the contextual-links <ul>. */
const NAV_UL = 'ul.primary-nav__menu--level-1';

interface Sample {
  t: number;
  amn: boolean;
  onScreen: boolean;
  ulH: number;
  itemH: number;
}

/**
 * Installs the high-frequency sampler. Must be added before any navigation.
 */
async function installProbe(context: BrowserContext): Promise<void> {
  await context.addInitScript(() => {
    const SEL = 'ul.primary-nav__menu--level-1';
    (window as unknown as { __navSamples: unknown[] }).__navSamples = [];
    const samples = (window as unknown as { __navSamples: unknown[] })
      .__navSamples;
    const t0 = performance.now();
    const tick = () => {
      if (document.body) {
        const ul = document.querySelector(SEL);
        const item = document.querySelector('.primary-nav__menu-item');
        if (ul) {
          const r = ul.getBoundingClientRect();
          samples.push({
            t: +(performance.now() - t0).toFixed(1),
            amn: document.body.classList.contains('is-always-mobile-nav'),
            onScreen:
              !!(ul as HTMLElement).offsetParent &&
              r.width > 0 &&
              r.height > 0 &&
              r.right > 0 &&
              r.left < window.innerWidth &&
              r.bottom > 0 &&
              r.top < window.innerHeight,
            ulH: ul.clientHeight,
            itemH: item ? item.clientHeight : 0,
          });
        }
      }
      if (performance.now() - t0 < 2500) setTimeout(tick, 4);
    };
    tick();
  });
}

/** Loads `path` cold and returns every sample taken during and after load. */
async function sampleLoad(page: Page, path: string): Promise<Sample[]> {
  await page.goto(path, { waitUntil: 'load' });
  await page.waitForTimeout(1200);
  return page.evaluate(
    () => (window as unknown as { __navSamples: Sample[] }).__navSamples,
  );
}

/** Points at which the nav's visibility or the body class changed. */
function transitions(samples: Sample[]): string[] {
  const out: string[] = [];
  for (let i = 1; i < samples.length; i++) {
    const a = samples[i - 1];
    const b = samples[i];
    if (a.onScreen !== b.onScreen || a.amn !== b.amn) {
      out.push(
        `@${b.t}ms onScreen ${a.onScreen}->${b.onScreen}, ` +
          `is-always-mobile-nav ${a.amn}->${b.amn}`,
      );
    }
  }
  return out;
}

async function login(page: Page): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(ADMIN_USER);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

test.describe('Desktop nav fit — logged-in', () => {
  test.beforeEach(async ({ context, page }) => {
    await login(page);
    await installProbe(context);
  });

  for (const width of DESKTOP_WIDTHS) {
    test(`no flicker at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const samples = await sampleLoad(page, '/');

      expect(samples.length, 'probe collected samples').toBeGreaterThan(50);
      expect(
        transitions(samples),
        `nav changed state mid-load at ${width}px`,
      ).toEqual([]);
    });

    test(`nav stays visible (not collapsed) at ${width}px`, async ({ page }) => {
      // The #287 failure mode: no flicker, because the desktop nav is simply
      // gone. Assert the opposite — it is on screen and NOT force-collapsed.
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/', { waitUntil: 'load' });
      await page.waitForTimeout(800);

      await expect(page.locator('body')).not.toHaveClass(
        /is-always-mobile-nav/,
      );
      await expect(page.locator(NAV_UL)).toBeVisible();
      await expect(
        page.locator(NAV_UL).getByRole('link', { name: 'My Groups' }),
      ).toBeVisible();

      // Olivero's own wrap test: one line means ul height == item height.
      const rows = await page.evaluate((sel) => {
        const ul = document.querySelector(sel)!;
        const item = document.querySelector('.primary-nav__menu-item')!;
        return { ulH: ul.clientHeight, itemH: item.clientHeight };
      }, NAV_UL);
      expect(
        rows.ulH,
        `nav wrapped to a second line at ${width}px (${rows.ulH}px tall vs a ${rows.itemH}px item)`,
      ).toBeLessThanOrEqual(rows.itemH);
    });
  }

  for (const width of MOBILE_WIDTHS) {
    test(`hamburger still opens the drawer at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/', { waitUntil: 'load' });

      const nav = page.locator(NAV_UL);
      const toggle = page.locator('.mobile-nav-button');
      await expect(toggle).toBeVisible();
      await expect(toggle).toHaveAttribute('aria-expanded', 'false');

      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true');
      await expect(nav).toBeInViewport();
      await expect(nav.getByRole('link', { name: 'My Groups' })).toBeVisible();
    });
  }

  test('My Groups is still one click away', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/', { waitUntil: 'load' });
    await page
      .locator(NAV_UL)
      .getByRole('link', { name: 'My Groups' })
      .click();
    await page.waitForURL('**/my-groups');
    expect(new URL(page.url()).pathname).toBe('/my-groups');
  });
});

test.describe('Desktop nav fit — anonymous (no regression from #300)', () => {
  test.beforeEach(async ({ context }) => {
    await installProbe(context);
  });

  for (const width of DESKTOP_WIDTHS) {
    test(`anonymous nav does not flicker at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      const samples = await sampleLoad(page, '/');

      expect(transitions(samples)).toEqual([]);
      await expect(page.locator('body')).not.toHaveClass(
        /is-always-mobile-nav/,
      );
      await expect(page.locator(NAV_UL)).toBeVisible();
    });
  }
});

test.describe('Compact header search (desktop)', () => {
  test('collapsed at rest, expands on focus without reflowing the nav', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 1200, height: 900 });
    await page.goto('/', { waitUntil: 'load' });

    const input = page.locator(
      '#block-groups-chrome-search-form-wide .form-search',
    );
    await expect(input).toBeVisible();

    const resting = (await input.boundingBox())!.width;
    expect(resting, 'search is icon-sized at rest').toBeLessThan(64);

    await input.focus();
    const expanded = (await input.boundingBox())!.width;
    expect(expanded, 'search expands to a usable field on focus').toBeGreaterThan(
      200,
    );

    // Expanding must not push the nav into wrapping — that would re-trigger
    // the very collapse this whole change exists to prevent.
    await expect(page.locator('body')).not.toHaveClass(/is-always-mobile-nav/);
    const rows = await page.evaluate((sel) => {
      const ul = document.querySelector(sel)!;
      const item = document.querySelector('.primary-nav__menu-item')!;
      return { ulH: ul.clientHeight, itemH: item.clientHeight };
    }, NAV_UL);
    expect(rows.ulH).toBeLessThanOrEqual(rows.itemH);
  });

  test('still submits to the search page', async ({ page }) => {
    await page.setViewportSize({ width: 1200, height: 900 });
    await page.goto('/', { waitUntil: 'load' });

    const input = page.locator(
      '#block-groups-chrome-search-form-wide .form-search',
    );
    await input.focus();
    await input.fill('barcelona');
    await Promise.all([
      page.waitForURL(/\/search\/node/),
      input.press('Enter'),
    ]);
    expect(page.url()).toContain('keys=barcelona');
  });
});
