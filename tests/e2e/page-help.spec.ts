import { test, expect, Page } from '@playwright/test';

/**
 * #126 SD-1 — Page-level ⓘ tooltips, E2E.
 *
 * Reference docs (read before editing this spec):
 *   - docs/planning/handoffs/126-page-tooltips/brief.md (10-key route map,
 *     5 live routes, default-deny gate, copy deck, acceptance criteria)
 *   - docs/planning/handoffs/126-page-tooltips/decisions.md
 *
 * Selector contract this spec pins (F implements `PageHelp.php` against
 * these; T-GREEN re-runs verbatim):
 *   - `.do-chrome-info.page-help-info` — the ⓘ trigger span, injected via
 *     `hook_preprocess_page_title`'s `title_suffix` slot, so it renders as a
 *     sibling immediately after `h1.page-title` on `page-title.html.twig`.
 *   - `[aria-label]` non-empty, `data-do-tooltip` non-empty (tippy.js source
 *     text, per the existing do_chrome/tooltips behaviour already attached
 *     globally — no new library needed here).
 *   - `tabindex="0"` + `role="note"` (GroupTypeContentHelp::infoTrigger()
 *     shape, per brief.md's skip-D justification).
 *
 * RED (Phase 4, before F): `Drupal\do_chrome\Hook\PageHelp` does not exist,
 * HelpText has no `page.*` keys, and `hook_preprocess_page_title` is not
 * implemented anywhere in do_chrome — every assertion below fails because
 * `.page-help-info` has ZERO count on every page today (the feature
 * assertion fails; there is no missing-import/setup failure masking this).
 *
 * Seed data: this spec resolves group URLs via `/all-groups` (per
 * group-type-homepage.spec.ts's own `groupUrlByLabel` convention) rather than
 * hard-coding a group id, since this shared DDEV instance accumulates
 * fixture groups across repeated runs.
 */

const TOOLTIP_TRIGGER = '.do-chrome-info.page-help-info';

/**
 * Resolve any seeded group's canonical `/group/{gid}` URL from the
 * `/all-groups` directory's first result card — this spec does not care
 * WHICH group, only that a real group with a Stream tab exists.
 */
async function firstGroupUrl(page: Page): Promise<string> {
  await page.goto('/all-groups');
  const card = page.locator('.gc-directory-card').first();
  await expect(card).toBeVisible();
  const link = card.locator('.gc-directory-card__title a').first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  expect(href).toBeTruthy();
  return href as string;
}

test.describe('#126 SD-1 — Anonymous: site-wide stream page ⓘ', () => {
  test('the ⓘ renders after the H1 with correct copy on /stream', async ({
    page,
  }) => {
    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);

    const title = page.locator('h1.page-title');
    await expect(title).toBeVisible();

    const trigger = page.locator(TOOLTIP_TRIGGER);
    await expect(trigger).toHaveCount(1);

    // The trigger must be a SIBLING that follows the H1 in the title_suffix
    // slot — assert it appears after the H1 in document order.
    const titleSuffixSibling = title.locator(
      `xpath=following-sibling::*//*[contains(concat(" ", normalize-space(@class), " "), " page-help-info ")] | following-sibling::*[contains(concat(" ", normalize-space(@class), " "), " page-help-info ")]`,
    );
    await expect(titleSuffixSibling.first()).toBeVisible();

    const ariaLabel = await trigger.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
    expect((ariaLabel ?? '').length).toBeGreaterThan(0);
    expect(ariaLabel).toMatch(/site-wide activity stream/i);

    const tooltipData = await trigger.getAttribute('data-do-tooltip');
    expect(tooltipData).toBeTruthy();
    expect(tooltipData).toMatch(/site-wide activity stream/i);

    // Hover triggers the tippy.js tooltip (shared do_chrome/tooltips
    // behaviour, already globally attached).
    await trigger.hover();
    const tippyBox = page.locator('[data-tippy-root] .tippy-content, .tippy-box .tippy-content');
    await expect(tippyBox.first()).toBeVisible();
    await expect(tippyBox.first()).toContainText(/site-wide activity stream/i);
  });
});

test.describe('#126 SD-1 — Anonymous: all-groups directory page ⓘ', () => {
  test('the ⓘ renders after the H1 with correct copy on /all-groups', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    const title = page.locator('h1.page-title');
    await expect(title).toBeVisible();

    const trigger = page.locator(TOOLTIP_TRIGGER);
    await expect(trigger).toHaveCount(1);

    const ariaLabel = await trigger.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
    expect(ariaLabel).toMatch(/Every community group/i);

    const tooltipData = await trigger.getAttribute('data-do-tooltip');
    expect(tooltipData).toBeTruthy();
    expect(tooltipData).toMatch(/Every community group/i);

    await trigger.hover();
    const tippyBox = page.locator('[data-tippy-root] .tippy-content, .tippy-box .tippy-content');
    await expect(tippyBox.first()).toBeVisible();
    await expect(tippyBox.first()).toContainText(/Every community group/i);
  });
});

test.describe('#126 SD-1 — Authed (Elena via persona switcher): group Stream tab ⓘ', () => {
  test('the ⓘ renders on a seeded group\'s Stream tab with correct copy', async ({
    page,
  }) => {
    // Switch to Elena Garcia (Member persona) via the persona-switcher route
    // (#120 SC-1; machine name 'elena-garcia' per PersonaSpecTest.php).
    await page.goto('/persona-switch/elena-garcia');
    await page.waitForLoadState('networkidle');

    const groupUrl = await firstGroupUrl(page);
    const groupRes = await page.goto(`${groupUrl}/stream`);
    expect(groupRes?.status()).toBe(200);

    const title = page.locator('h1.page-title');
    await expect(title).toBeVisible();

    const trigger = page.locator(TOOLTIP_TRIGGER);
    await expect(trigger).toHaveCount(1);

    const ariaLabel = await trigger.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
    expect(ariaLabel).toMatch(/group's activity/i);

    const tooltipData = await trigger.getAttribute('data-do-tooltip');
    expect(tooltipData).toBeTruthy();
    expect(tooltipData).toMatch(/group's activity/i);

    // Switch back to anonymous so this test doesn't leak persona state into
    // later tests in the same worker (workers: 1 / fullyParallel: false,
    // per playwright.config.ts, but session state persists across `test()`
    // blocks unless explicitly reset).
    await page.goto('/persona-switch/anonymous');
  });
});

test.describe('#126 SD-1 — Default-deny: no ⓘ on unregistered routes', () => {
  test('the login page carries zero .page-help-info triggers', async ({
    page,
  }) => {
    const res = await page.goto('/user/login');
    expect(res?.status()).toBe(200);

    await expect(page.locator('.page-help-info')).toHaveCount(0);
  });

  test('/admin (anonymous, likely redirected to login) still carries zero .page-help-info triggers', async ({
    page,
  }) => {
    // Whether anonymous /admin resolves to a 403/login redirect or renders
    // directly is not this test's concern — the point (brief.md AC-6) is
    // that the ⓘ must never appear on an unregistered route regardless of
    // where the request lands.
    await page.goto('/admin');
    await expect(page.locator('.page-help-info')).toHaveCount(0);
  });
});

test.describe('#126 SD-1 — Keyboard: ⓘ is reachable via Tab with visible focus', () => {
  test('Tab through /stream until focus lands on .page-help-info', async ({
    page,
  }) => {
    await page.goto('/stream');

    const trigger = page.locator(TOOLTIP_TRIGGER);
    await expect(trigger).toHaveCount(1);

    // Tab a bounded number of times (guards against an infinite loop if the
    // trigger is never reached, which would itself be a real failure to
    // surface rather than hang the suite).
    let focused = false;
    for (let i = 0; i < 30; i++) {
      await page.keyboard.press('Tab');
      // eslint-disable-next-line no-await-in-loop
      focused = await trigger.evaluate((el) => el === document.activeElement);
      if (focused) {
        break;
      }
    }
    expect(focused).toBe(true);
    await expect(trigger).toBeFocused();

    const outline = await trigger.evaluate(
      (el) =>
        getComputedStyle(el, ':focus-visible').outlineWidth ||
        getComputedStyle(el).outlineWidth,
    );
    expect(outline).not.toBe('0px');
  });
});
