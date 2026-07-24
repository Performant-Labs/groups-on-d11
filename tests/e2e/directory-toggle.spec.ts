import { test, expect, Page } from '@playwright/test';

/**
 * E2E for #124 SC-5 — Directory compact-list vs cards toggle on /all-groups.
 *
 * `docs/handoffs/0124-directory-toggle/brief.md` + `wireframe.md` mount the
 * SC-F1 (#119) variant switcher over `/all-groups` (view `all_groups`,
 * `page_1`) as the view's `#header`, with three options — Compact list /
 * Cards (default) / Map (soon, unavailable) — sharing the SAME instance id
 * (`directory.layout`) and sessionStorage key as `/showcase`'s own stub
 * switcher (SC-F1). Toggling flips a `data-do-directory-variant` attribute
 * on the view's `.view-content` wrapper (wireframe.md Surface 3) WITHOUT a
 * full page reload, WITHOUT changing the URL/query params, so filters and
 * paging are preserved.
 *
 * do_showcase's `viewsPreRender()` hook and `directory-compact.css` do not
 * exist yet at RED time (Phase 4, before F implements) — every selector
 * below targets markup/behavior the brief + wireframe specify but nothing in
 * the codebase renders yet. This spec is authored to RED (assert file
 * exists + is syntactically valid via `npx playwright test --list`) and is
 * NOT executed for real until T-GREEN, against a fully seeded, running site
 * (assemble -> site:install -> cim -> seed -> runserver), per
 * PROJECT_CONTEXT.md.
 *
 * Selector contract (mirrors showcase.spec.ts's radiogroup pattern, scoped
 * to the /all-groups instance rather than /showcase's):
 *   - Switcher wrapper: [role="radiogroup"][data-do-showcase-instance="directory.layout"]
 *     (the SAME instance id as /showcase's stub — intentional, per
 *     wireframe.md "Interaction with the SC-F1 /showcase stub").
 *   - Wrapper variant attribute: .views-element-container[data-do-directory-variant]
 *     on the all_groups view's own content wrapper (wireframe.md Surface 3).
 *   - Compact row shape: .gc-directory-compact-row (or equivalent) carrying
 *     name link + type badge + member-count text + visibility badge inline,
 *     with NO description text visible.
 *
 * Auth/login helper mirrors directory-cards.spec.ts / showcase.spec.ts's
 * existing convention (real /user/login form, admin/admin).
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

/** The switcher wrapper locator, scoped to the directory.layout instance. */
function switcherLocator(page: Page) {
  return page.locator(
    '[role="radiogroup"][data-do-showcase-instance="directory.layout"]',
  );
}

/** The view's variant-attributed content wrapper. */
function directoryWrapperLocator(page: Page) {
  return page.locator('.views-element-container[data-do-directory-variant]');
}

test.describe('#124 SC-5 — Directory compact/cards toggle (/all-groups)', () => {
  test('switcher renders with three options, Cards selected by default, wrapper starts in cards mode', async ({
    page,
  }) => {
    const res = await page.goto('/all-groups');
    expect(res?.status()).toBe(200);

    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();

    const options = switcher.locator('[role="radio"]');
    await expect(options).toHaveCount(3);
    await expect(switcher.getByText('Compact list')).toBeVisible();
    await expect(switcher.getByText('Cards', { exact: false })).toBeVisible();
    await expect(switcher.getByText('Map', { exact: false })).toBeVisible();

    const cards = switcher.getByRole('radio', { name: /Cards/i });
    await expect(cards).toHaveAttribute('aria-checked', 'true');

    const wrapper = directoryWrapperLocator(page);
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'cards');
  });

  test('clicking Compact list flips the wrapper attribute live (no navigation) and changes row shape', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    const wrapper = directoryWrapperLocator(page);
    const urlBefore = page.url();

    await switcher.getByRole('radio', { name: /Compact list/i }).click();

    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'compact');
    // No navigation occurred — same URL, same query params.
    expect(page.url()).toBe(urlBefore);

    const firstRow = page.locator('.views-row').first();
    // Compact row shape: name link + type badge + member-count text +
    // visibility badge, all inline in one row; description NOT visible.
    // Badge/description selectors corrected to match the REUSED
    // groups_chrome card markup this story's CSS restyles (per F's
    // handoff: '.gc-directory-card' markup reused verbatim, no new
    // Views fields) rather than the wireframe's illustrative bracket
    // notation or an assumed Drupal field--name-* class -- the
    // visibility badge text is the plain label (Open/Moderated/
    // Invite Only, see VisibilityTooltip.php), and the description
    // snippet carries .gc-directory-card__snippet (directory-compact.css
    // hides exactly that class in compact mode).
    await expect(firstRow.locator('a').first()).toBeVisible();
    await expect(firstRow).toContainText(/member/i);
    await expect(firstRow).toContainText(/Open|Moderated|Invite Only/);
    await expect(firstRow.locator('.gc-directory-card__snippet')).toBeHidden();

    // Switching back to Cards restores the description and the byte-
    // identical card presentation.
    await switcher.getByRole('radio', { name: /Cards/i }).click();
    await expect(wrapper).toHaveAttribute('data-do-directory-variant', 'cards');
    await expect(page.locator('.gc-directory-card').first()).toBeVisible();
  });

  test('filters are preserved across a toggle both ways', async ({ page }) => {
    await page.goto('/all-groups');
    const searchInput = page.getByLabel(/search groups/i);
    await searchInput.fill('Book');
    await page.getByRole('button', { name: 'Filter' }).click();
    await page.waitForURL(/[?&]search=Book/i);

    const urlAfterFilter = page.url();
    const rowCountCards = await page.locator('.gc-directory-card').count();

    const switcher = switcherLocator(page);
    await switcher.getByRole('radio', { name: /Compact list/i }).click();
    expect(page.url()).toBe(urlAfterFilter);
    const rowCountCompact = await page.locator('.views-row').count();
    expect(rowCountCompact).toBe(rowCountCards);
    await expect(page.getByLabel(/search groups/i)).toHaveValue('Book');

    await switcher.getByRole('radio', { name: /Cards/i }).click();
    expect(page.url()).toBe(urlAfterFilter);
    await expect(page.getByLabel(/search groups/i)).toHaveValue('Book');
    expect(await page.locator('.gc-directory-card').count()).toBe(rowCountCards);
  });

  test('the pager position is preserved across a toggle (page 2)', async ({ page }) => {
    await page.goto('/all-groups?page=1');
    // Conditional: only asserted if the seeded demo data has a second page.
    const pagerNextExists = await page.locator('.pager__item--next a, a[href*="page=2"]').count();
    test.skip(pagerNextExists === 0, 'Not enough seeded groups for a second page — pager-preservation assertion is conditional on seed size.');

    const urlOnPage2 = page.url();
    const switcher = switcherLocator(page);
    await switcher.getByRole('radio', { name: /Compact list/i }).click();
    expect(page.url()).toBe(urlOnPage2);
  });

  test('session persistence: compact selection survives a reload; a fresh session defaults to cards', async ({
    page,
    context,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    await switcher.getByRole('radio', { name: /Compact list/i }).click();

    await page.reload();
    const wrapperAfterReload = directoryWrapperLocator(page);
    await expect(wrapperAfterReload).toHaveAttribute('data-do-directory-variant', 'compact');
    await expect(switcherLocator(page).getByRole('radio', { name: /Compact list/i })).toHaveAttribute('aria-checked', 'true');

    // A fresh incognito-style session (new context, no shared sessionStorage)
    // defaults back to cards.
    const freshPage = await context.browser()!.newContext({ ignoreHTTPSErrors: true }).then((c) => c.newPage());
    await freshPage.goto('/all-groups');
    await expect(directoryWrapperLocator(freshPage)).toHaveAttribute('data-do-directory-variant', 'cards');
    await freshPage.close();
  });

  test('?variant=compact URL wins over a stale sessionStorage value of cards', async ({ page }) => {
    await page.goto('/all-groups');
    await page.evaluate(() => {
      window.sessionStorage.setItem('doShowcase.variant.directory.layout', 'cards');
    });
    await page.goto('/all-groups?variant=compact');
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'compact');
    await expect(switcherLocator(page).getByRole('radio', { name: /Compact list/i })).toHaveAttribute('aria-checked', 'true');
  });

  test('?variant=map (unavailable) falls back gracefully to compact, never blank', async ({ page }) => {
    await page.goto('/all-groups?variant=map');
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'compact');
    await expect(page.locator('.views-row').first()).toBeVisible();
  });

  test('cross-page persistence: selecting Compact on /showcase carries over to /all-groups', async ({
    page,
  }) => {
    await page.goto('/showcase');
    const showcaseSwitcher = switcherLocator(page);
    await showcaseSwitcher.getByRole('radio', { name: /Compact list/i }).click();

    await page.goto('/all-groups');
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'compact');
  });

  test('WCAG-adjacent smoke: switcher is keyboard-operable and the visibility badge is text, not color-only', async ({
    page,
  }) => {
    await page.goto('/all-groups');
    const switcher = switcherLocator(page);
    const cards = switcher.getByRole('radio', { name: /Cards/i });
    const compact = switcher.getByRole('radio', { name: /Compact list/i });

    await cards.focus();
    await expect(cards).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await expect(compact).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(compact).toHaveAttribute('aria-checked', 'true');
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'compact');

    const firstRow = page.locator('.views-row').first();
    const badgeText = await firstRow.textContent();
    // Plain text label (Open/Moderated/Invite Only), not bracketed --
    // see the row-shape test above for why (reused groups_chrome badge
    // markup, confirmed live; the wireframe's [Open] notation was
    // illustrative low-fi ASCII, not a literal rendered-text contract).
    expect(badgeText).toMatch(/Open|Moderated|Invite Only/);
  });
});

test.describe('#124 SC-5 — existing suites stay green (non-regression)', () => {
  test('directory-cards.spec.ts default cards view is unaffected by the new switcher', async ({ page }) => {
    await page.goto('/all-groups');
    await expect(page.locator('.gc-directory-card').first()).toBeVisible();
    // No compact CSS matches in the default (cards) state.
    await expect(directoryWrapperLocator(page)).toHaveAttribute('data-do-directory-variant', 'cards');
  });

  test('directory-filters.spec.ts exposed filters still render alongside the switcher', async ({ page }) => {
    await page.goto('/all-groups');
    await expect(page.getByLabel(/location/i)).toBeVisible();
    await expect(page.getByLabel(/language/i)).toBeVisible();
  });

  test('showcase.spec.ts /showcase stub switcher still renders (shared instance, unaffected)', async ({ page }) => {
    await page.goto('/showcase');
    await expect(switcherLocator(page)).toBeVisible();
  });
});
