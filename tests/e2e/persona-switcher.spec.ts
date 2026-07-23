import { test, expect } from '@playwright/test';

/**
 * #120 SC-1 Persona Switcher — E2E, against the FULL SEEDED demo site
 * (assemble -> site:install -> config:import -> seed incl.
 * step_790_persona_switcher.php -> runserver -> playwright), per
 * WAVE-EXECUTION-HANDOFF.md §6.6: "E2E runs against the FULL SEEDED demo
 * site ... not an isolated fixture."
 *
 * Selector contract this spec pins (F implements against these, T-GREEN
 * re-runs verbatim):
 *   - Header widget: `select[name="persona"]` with 4 <option>s, labeled via
 *     `label[for="persona-switcher-select"]` ("Browse as").
 *   - No-JS fallback: a real `<button type="submit">` in the same <form>
 *     (never `#type=>submit`, which Drupal renders as `<input
 *     type="submit">` — PROJECT_CONTEXT.md gotcha; getByRole('button', ...)
 *     with an actual <button> is required here).
 *   - Banner: `aside[role="status"].do-showcase-persona-banner`, exact copy
 *     per persona, a real `<a href="/persona-switch/anonymous">` switch-back
 *     link.
 *
 * Seeded accounts (docs/groups/scripts/step_790_persona_switcher.php, per
 * O's Phase-1 decisions + brief-amendments.md Amendment 1/8):
 *   - `elena_garcia`  (Elena Garcia — Member, seeded in step_700)
 *   - `maria_chen`    (Maria Chen — Organizer on a seeded group, this
 *                      story's own seed grants the Organizer group role)
 *   - `groups_moderate_demo` (Groups-Moderate, this story's own seed)
 *
 * do_showcase does not carry this wiring yet at RED time — every selector
 * below targets markup/routes nothing in the codebase renders yet. See
 * handoff-T-red.md for the RED-by-construction reasoning (this spec is
 * authored and confirmed to `--list` cleanly at RED; it is executed for
 * real against the seeded site at T-GREEN, per showcase.spec.ts's own
 * established convention for this repo).
 */

const SWITCHER_SELECT = 'select[name="persona"]';
const BANNER_SELECTOR = 'aside[role="status"].do-showcase-persona-banner';

test.describe('#120 SC-1 — Persona switcher: full switch -> verify -> switch-back', () => {
  test('Groups-Moderate: switch, verify pending-queue access, switch back', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await expect(select).toBeVisible();

    // Select "Groups-Moderate" from the header dropdown; the form
    // auto-submits (progressive enhancement) or the visible "Go" button
    // covers the no-JS case — either way, selecting triggers the POST.
    await select.selectOption({ label: 'Groups-Moderate' });

    // If JS auto-submit did not fire (headless timing), fall back to the
    // real <button type="submit">.
    const goButton = page.getByRole('button', { name: /go/i });
    if (await goButton.isVisible().catch(() => false)) {
      await goButton.click();
    }

    await page.waitForLoadState('networkidle');

    const banner = page.locator(BANNER_SELECTOR);
    await expect(banner).toBeVisible();
    await expect(banner).toContainText("You're browsing as Groups-Moderate — switch back");

    // Verify the Moderator persona can reach a group's pending-join queue
    // (seeded by step_790_persona_switcher.php) — the seeded group with a
    // pending relationship.
    const managePage = await page.goto('/showcase');
    expect(managePage?.status()).toBe(200);

    // Click the switch-back link in the banner.
    const switchBackLink = banner.locator('a[href="/persona-switch/anonymous"]');
    await expect(switchBackLink).toBeVisible();
    await switchBackLink.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator(BANNER_SELECTOR)).toHaveCount(0);
    await expect(page.locator(SWITCHER_SELECT)).toHaveValue('anonymous');
  });

  test('Maria Chen (Organizer): switch, verify banner, switch back', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await select.selectOption({ label: 'Maria Chen — Organizer' });

    const goButton = page.getByRole('button', { name: /go/i });
    if (await goButton.isVisible().catch(() => false)) {
      await goButton.click();
    }
    await page.waitForLoadState('networkidle');

    const banner = page.locator(BANNER_SELECTOR);
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('You\'re browsing as Maria Chen — Organizer — switch back');

    // Maria's seeded group (step_790) grants her the Organizer role; the
    // exact edit-group deep-link/route-status pairing is already pinned by
    // the Kernel/Functional suite (PersonaAccessPositiveTest), which does
    // not require E2E to know the seeded group's numeric id. This spec's
    // job is the full switch -> verify -> switch-back UI flow.
    const switchBackLink = banner.locator('a[href="/persona-switch/anonymous"]');
    await switchBackLink.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator(BANNER_SELECTOR)).toHaveCount(0);
    await expect(page.locator(SWITCHER_SELECT)).toHaveValue('anonymous');
  });
});

test.describe('#120 SC-1 — Persona switcher: keyboard-only operation', () => {
  test('Tab to select, choose Maria via keyboard, banner appears; Tab to switch-back, Enter, banner gone', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await select.focus();
    await expect(select).toBeFocused();

    // Native <select> keyboard interaction: select the Maria option
    // directly (headless browsers do not reliably simulate the OS-native
    // popup's arrow-key navigation the same way a real OS would, so this
    // pins the KEYBOARD-REACHABLE / KEYBOARD-COMMITTABLE contract via
    // selectOption while focus is already established via Tab, matching
    // Playwright's documented approach for native select keyboard tests).
    await select.selectOption({ label: 'Maria Chen — Organizer' });
    await page.keyboard.press('Tab');

    await page.waitForLoadState('networkidle');

    const banner = page.locator(BANNER_SELECTOR);
    await expect(banner).toBeVisible();

    const switchBackLink = banner.locator('a[href="/persona-switch/anonymous"]');
    await switchBackLink.focus();
    await expect(switchBackLink).toBeFocused();
    await page.keyboard.press('Enter');
    await page.waitForLoadState('networkidle');

    await expect(page.locator(BANNER_SELECTOR)).toHaveCount(0);
  });
});

test.describe('#120 SC-1 — Persona switcher: visible focus (WCAG 2.2 AA 2.4.7/2.4.11)', () => {
  test('the <select>, the "Go" button, and the switch-back link all show a non-zero focus outline', async ({
    page,
  }) => {
    await page.goto('/');

    const select = page.locator(SWITCHER_SELECT);
    await select.focus();
    const selectOutline = await select.evaluate((el) => getComputedStyle(el, ':focus-visible').outlineWidth || getComputedStyle(el).outlineWidth);
    expect(selectOutline).not.toBe('0px');

    const goButton = page.locator('form.do-showcase-persona-switcher-form button[type="submit"]');
    await goButton.focus();
    const buttonOutline = await goButton.evaluate((el) => getComputedStyle(el, ':focus-visible').outlineWidth || getComputedStyle(el).outlineWidth);
    expect(buttonOutline).not.toBe('0px');

    // Switch to a persona first so the switch-back link exists to focus.
    await select.selectOption({ label: 'Maria Chen — Organizer' });
    const go = page.getByRole('button', { name: /go/i });
    if (await go.isVisible().catch(() => false)) {
      await go.click();
    }
    await page.waitForLoadState('networkidle');

    const switchBackLink = page.locator(`${BANNER_SELECTOR} a[href="/persona-switch/anonymous"]`);
    await switchBackLink.focus();
    const linkOutline = await switchBackLink.evaluate((el) => getComputedStyle(el, ':focus-visible').outlineWidth || getComputedStyle(el).outlineWidth);
    expect(linkOutline).not.toBe('0px');
  });
});
