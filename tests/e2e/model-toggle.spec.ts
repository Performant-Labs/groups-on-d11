import { test, expect, Page } from '@playwright/test';

/**
 * E2E for ST-8 (#130) — Content view / Activity view toggle on /stream.
 *
 * `docs/handoffs/st8-model-toggle-130/brief.md` + `wireframe.md` mount the
 * SC-F1 (#119) variant switcher over `/stream` (view `activity_stream`,
 * `page_1`) as the view's `#header`, with TWO options — Content view
 * (soon, unavailable) / Activity view (default, selected) — sharing the
 * `stream.model` instance id. Toggling (once #129 ships a real Content
 * view) will flip a `data-do-stream-model` attribute on the view's
 * `.views-element-container` wrapper; today, with only one available
 * option, every reachable URL renders the same page (wireframe.md Surface 1
 * "Documented explicitly because it looks like a no-op").
 *
 * `do_streams`'s `ModelToggleHooks` and `model-toggle.css` do not exist yet
 * at RED time (Phase 4, before F implements) — every selector below targets
 * markup/behavior the brief + wireframe specify but nothing in the codebase
 * renders yet. This spec is authored to RED (assert the file registers via
 * `npx playwright test tests/e2e/model-toggle.spec.ts --list`) and is NOT
 * executed for real until T-GREEN, against a fully seeded, running site
 * (assemble -> site:install -> cim -> seed -> runserver), per
 * PROJECT_CONTEXT.md.
 *
 * Selector contract (mirrors directory-toggle.spec.ts's radiogroup pattern,
 * scoped to the /stream instance):
 *   - Switcher wrapper: [role="radiogroup"][data-do-showcase-instance="stream.model"]
 *   - Wrapper model attribute: .views-element-container[data-do-stream-model]
 *     on the activity_stream view's own content wrapper (wireframe.md
 *     Surface 2).
 *   - ⓘ tooltip trigger: [data-do-tooltip] / [role="note"][tabindex="0"]
 *     inside the switcher wrapper (do_chrome house pattern, one per
 *     instance).
 *
 * Auth/login helper mirrors directory-toggle.spec.ts / showcase.spec.ts's
 * existing convention (real /user/login form, admin/admin) — /stream itself
 * requires no auth (public activity feed), but the /showcase catalog
 * click-through test reuses the same helper for consistency with sibling
 * specs.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

async function login(page: Page, user: string, pass: string): Promise<void> {
  await page.goto('/user/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(pass);
  await Promise.all([
    page.waitForURL(/\/user(\/\d+)?/),
    page.getByRole('button', { name: 'Log in' }).click(),
  ]);
  await expect(page.locator('body')).not.toContainText(
    'Unrecognized username or password',
  );
}

/** The switcher wrapper locator, scoped to the stream.model instance. */
function switcherLocator(page: Page) {
  return page.locator(
    '[role="radiogroup"][data-do-showcase-instance="stream.model"]',
  );
}

/** The view's model-attributed content wrapper. */
function streamWrapperLocator(page: Page) {
  return page.locator('.views-element-container[data-do-stream-model]');
}

test.describe('ST-8 (#130) — Content/Activity model toggle (/stream)', () => {
  test('switcher renders with two options, Activity view selected by default, wrapper starts in activity mode', async ({
    page,
  }) => {
    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);

    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();

    const options = switcher.locator('[role="radio"]');
    await expect(options).toHaveCount(2);
    await expect(switcher.getByText('Content view', { exact: false })).toBeVisible();
    await expect(switcher.getByText('Activity view', { exact: false })).toBeVisible();

    const activity = switcher.getByRole('radio', { name: /Activity view/i });
    await expect(activity).toHaveAttribute('aria-checked', 'true');

    const wrapper = streamWrapperLocator(page);
    await expect(wrapper).toHaveAttribute('data-do-stream-model', 'activity');
  });

  test('Content view option is aria-disabled, tabindex=-1, and carries the "(soon)" suffix', async ({ page }) => {
    await page.goto('/stream');
    const switcher = switcherLocator(page);
    const content = switcher.getByRole('radio', { name: /Content view/i });

    await expect(content).toHaveAttribute('aria-disabled', 'true');
    await expect(content).toHaveAttribute('tabindex', '-1');
    await expect(content).toContainText(/soon/i);
  });

  test('Activity view option is aria-checked, tabindex=0, and carries the leading ● glyph', async ({ page }) => {
    await page.goto('/stream');
    const switcher = switcherLocator(page);
    const activity = switcher.getByRole('radio', { name: /Activity view/i });

    await expect(activity).toHaveAttribute('aria-checked', 'true');
    await expect(activity).toHaveAttribute('tabindex', '0');
    // Non-color selection cue: a leading "●" glyph on the selected option's
    // visible text (VariantSwitcher::build()'s display_label contract).
    await expect(activity).toContainText('●');
  });

  // NOTE: The former "clicking Content view is a no-op (disabled, no navigation,
  // no attribute change)" test was dropped in the post-CI fix pass. The design
  // intentionally allows the browser's native <a href="?variant=content">
  // fallback link to fire when the JS skips disabled options
  // (do_showcase/js/do_showcase.switcher.js lines 166-168 return early on
  // aria-disabled === 'true', attaching no click handler — the anchor navigates
  // normally). The URL DOES change to /stream?variant=content and the server
  // then resolves the unavailable 'content' variant back to 'activity' via
  // VariantSwitcher::resolveCurrent(); the URL-doesn't-change assertion was
  // wrong on its own terms. The merged precedent in showcase.spec.ts
  // ("an unavailable option is present, marked, and not a dead click", line
  // 185) asserts ONLY the disabled-marking contract (aria-disabled, tabindex,
  // "(soon)" copy) — which is already covered by the aria-disabled test
  // above — and the server-side fallback is already covered by the
  // "?variant=content deep link" test below (AC-3). No new assertion needed.

  test('/showcase catalog lists the stream-model entry as live, linking through to /stream', async ({ page }) => {
    await page.goto('/showcase');

    // Entry container is uniquely keyed by data-do-showcase-entry (see
    // ShowcaseController::page() line ~172). "Stream model" is the entry's
    // <h3> title; the actual link inside is titled "View this comparison"
    // (ShowcaseController::page() line 229) — so we scope by the entry
    // container, then click its inner link, matching the merged precedent
    // for live-catalog-entry click-through.
    const entry = page.locator('[data-do-showcase-entry="stream-model"]');
    await expect(entry).toBeVisible();
    await expect(entry).toContainText(/Stream model/);
    await expect(entry).not.toContainText(/coming/i);

    await entry.getByRole('link', { name: /View this comparison/i }).click();
    await page.waitForURL(/\/stream/);

    await expect(switcherLocator(page)).toBeVisible();
  });

  test('?variant=content deep link still renders with data-do-stream-model="activity" (fallback, AC-3)', async ({ page }) => {
    await page.goto('/stream?variant=content');
    await expect(streamWrapperLocator(page)).toHaveAttribute('data-do-stream-model', 'activity');
    // Not a blank/broken render — the existing activity_stream rows still
    // show (wireframe.md: "renders the page IDENTICALLY to the default
    // state above, not a blank/broken content-only view").
    await expect(page.locator('.views-row').first()).toBeVisible();
  });

  test('?variant=activity deep link renders with no reload flash (AC-4, server-resolved)', async ({ page }) => {
    const res = await page.goto('/stream?variant=activity');
    expect(res?.status()).toBe(200);
    await expect(streamWrapperLocator(page)).toHaveAttribute('data-do-stream-model', 'activity');
    await expect(switcherLocator(page).getByRole('radio', { name: /Activity view/i })).toHaveAttribute('aria-checked', 'true');
  });

  test('ⓘ tooltip trigger is present, keyboard-focusable, and its copy matches HelpText', async ({ page }) => {
    await page.goto('/stream');
    const switcher = switcherLocator(page);
    const trigger = switcher.locator('[role="note"][tabindex="0"]');

    await expect(trigger).toBeVisible();
    await trigger.focus();
    await expect(trigger).toBeFocused();

    // Copy matches HelpText::get('showcase.switcher.stream.model') — the
    // exact D-approved string (handoff-D.md), checked via the aria-label /
    // data-do-tooltip attribute the trigger carries (VariantSwitcher::build()
    // contract, same as every other switcher instance's ⓘ trigger).
    const tooltipCopy = await trigger.getAttribute('aria-label');
    expect(tooltipCopy).toMatch(/leaner model/i);
    expect(tooltipCopy).toMatch(/coming soon/i);
  });

  test('existing activity_stream row rendering is unaffected (non-regression, #116)', async ({ page }) => {
    await page.goto('/stream');
    await expect(page.locator('.views-row').first()).toBeVisible();
  });
});

test.describe('ST-8 (#130) — existing suites stay green (non-regression)', () => {
  test('directory-toggle.spec.ts /all-groups switcher still renders (unaffected sibling instance)', async ({ page }) => {
    await page.goto('/all-groups');
    await expect(
      page.locator('[role="radiogroup"][data-do-showcase-instance="directory.layout"]'),
    ).toBeVisible();
  });

  test('showcase.spec.ts /showcase stub switcher still renders (shared framework, unaffected)', async ({ page }) => {
    await page.goto('/showcase');
    await expect(
      page.locator('[role="radiogroup"][data-do-showcase-instance="directory.layout"]'),
    ).toBeVisible();
  });
});
