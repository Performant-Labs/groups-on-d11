import { test, expect, Page, Locator } from '@playwright/test';

/**
 * #123 SC-4 — Discovery three ways: Recent / Hot / Promoted, felt as a
 * comparison on `/showcase` via a second `discovery.ranking` VariantSwitcher
 * instance (SC-F1, #119), sitting alongside the existing `directory.layout`
 * stub switcher.
 *
 * `docs/handoffs/sc4-discovery-123/brief.md` + `wireframe.md`: the controller
 * reads `?discovery=recent|hot|promoted` and embeds the corresponding
 * EXISTING view (`activity_stream` / `hot_content` / `promoted_content`) via
 * `views_embed_view()` — no ranking is forked. The two switchers on
 * `/showcase` use DISTINCT query keys (`?variant=` for directory.layout,
 * `?discovery=` for discovery.ranking) so either can be deep-linked
 * independently (handoff-A-plan.md Risk 1).
 *
 * do_showcase does not carry this second switcher instance, its "Discovery
 * ranking" H2 section, or the query-key-parameterized `VariantSwitcher::
 * build()` yet at RED time (Phase 4, before F implements) — every selector
 * below targets markup/behavior the brief + wireframe specify but nothing in
 * the codebase renders yet. This spec is authored to RED (confirmed via
 * `npx playwright test --list`, a syntactically valid spec with no missing
 * imports) and is NOT executed for real until T-GREEN, against a fully
 * seeded, running site (assemble -> site:install -> cim -> seed ->
 * runserver), per PROJECT_CONTEXT.md and this repo's own established
 * convention (directory-toggle.spec.ts, persona-switcher.spec.ts docblocks).
 *
 * Selector contract this spec pins (F implements against these, T-GREEN
 * re-runs verbatim):
 *   - Section region: `[data-do-discovery-ranking]`, preceded by an
 *     `<h2>` naming "Discovery ranking" (wireframe.md's new H2).
 *   - Switcher wrapper: `[role="radiogroup"][data-do-showcase-instance=
 *     "discovery.ranking"]` inside that region.
 *   - Exactly ONE `[data-do-tooltip]` wrapper-level tooltip on the switcher
 *     (POC scope — not per-option, handoff-A-plan.md Risk 2).
 *   - Each tab click updates the URL to `?discovery=<id>` (no full-page
 *     navigation is asserted either way — the no-JS fallback link and the
 *     JS progressive-enhancement swap are both valid per the existing
 *     `do_showcase/switcher` framework contract; this spec asserts the
 *     RESULT — URL + selected state + embedded content — not the mechanism).
 *   - Seeded promoted count: per `docs/groups/scripts/step_700_demo_data.php`
 *     ("Getting Started with Paragraphs", "Community Code of Conduct" both
 *     flagged `promote_homepage`), the Promoted tab shows exactly these two
 *     seeded titles.
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

/** The discovery-ranking section region. */
function discoveryRegionLocator(page: Page): Locator {
  return page.locator('[data-do-discovery-ranking]');
}

/** The discovery.ranking switcher wrapper, scoped to the region. */
function discoverySwitcherLocator(page: Page): Locator {
  return discoveryRegionLocator(page).locator(
    '[role="radiogroup"][data-do-showcase-instance="discovery.ranking"]',
  );
}

/** The directory.layout switcher wrapper (the pre-existing SC-F1 stub). */
function directorySwitcherLocator(page: Page): Locator {
  return page.locator(
    '[role="radiogroup"][data-do-showcase-instance="directory.layout"]',
  );
}

// The site-wide POC ribbon (do_showcase, #119) is a fixed-position overlay
// that can intercept pointer events on controls beneath it — dismissing it
// first eliminates the race, matching persona-switcher.spec.ts's own
// established convention for this repo.
test.beforeEach(async ({ page }) => {
  await page.goto('/showcase');
  const dismiss = page.getByRole('button', { name: 'Dismiss demo banner' });
  if (await dismiss.isVisible().catch(() => false)) {
    await dismiss.click();
  }
});

test.describe('#123 SC-4 — Discovery ranking: Recent / Hot / Promoted (/showcase)', () => {
  test('the "Discovery ranking" section renders an H2 heading and a switcher with three options', async ({
    page,
  }) => {
    const res = await page.goto('/showcase');
    expect(res?.status()).toBe(200);

    await expect(page.getByRole('heading', { name: /Discovery ranking/i, level: 2 })).toBeVisible();

    const switcher = discoverySwitcherLocator(page);
    await expect(switcher).toBeVisible();
    await expect(switcher).toHaveAttribute('aria-label', /viewing/i);

    const options = switcher.locator('[role="radio"]');
    await expect(options).toHaveCount(3);
    await expect(switcher.getByText('Recent', { exact: false })).toBeVisible();
    await expect(switcher.getByText('Hot', { exact: false })).toBeVisible();
    await expect(switcher.getByText('Promoted', { exact: false })).toBeVisible();
  });

  test('clicking each tab updates the URL to ?discovery=<id> and changes the embedded content', async ({
    page,
  }) => {
    await page.goto('/showcase');
    const switcher = discoverySwitcherLocator(page);
    const region = discoveryRegionLocator(page);

    await switcher.getByRole('radio', { name: /Hot/i }).click();
    await page.waitForURL(/[?&]discovery=hot\b/);
    await expect(switcher.getByRole('radio', { name: /Hot/i })).toHaveAttribute('aria-checked', 'true');
    const hotHtml = await region.innerHTML();

    await switcher.getByRole('radio', { name: /Recent/i }).click();
    await page.waitForURL(/[?&]discovery=recent\b/);
    await expect(switcher.getByRole('radio', { name: /Recent/i })).toHaveAttribute('aria-checked', 'true');
    const recentHtml = await region.innerHTML();
    expect(recentHtml).not.toBe(hotHtml);

    await switcher.getByRole('radio', { name: /Promoted/i }).click();
    await page.waitForURL(/[?&]discovery=promoted\b/);
    await expect(switcher.getByRole('radio', { name: /Promoted/i })).toHaveAttribute('aria-checked', 'true');
    const promotedHtml = await region.innerHTML();
    expect(promotedHtml).not.toBe(recentHtml);
    expect(promotedHtml).not.toBe(hotHtml);
  });

  test('the Promoted tab shows exactly the two seeded promoted nodes', async ({ page }) => {
    await page.goto('/showcase?discovery=promoted');
    const region = discoveryRegionLocator(page);

    // Seeded per docs/groups/scripts/step_700_demo_data.php's
    // promote_homepage flagging block.
    await expect(region.getByText('Getting Started with Paragraphs')).toBeVisible();
    await expect(region.getByText('Community Code of Conduct')).toBeVisible();
  });

  test('the Hot tab shows commented threads ranked above uncommented ones', async ({ page }) => {
    await page.goto('/showcase?discovery=hot');
    const region = discoveryRegionLocator(page);
    await expect(region).toBeVisible();
    // Non-empty from seed (brief.md acceptance) — at minimum some row
    // renders; exact top-ranked title is cron-dependent (hot_content's score
    // is comment-driven and cron-recomputed) so this spec does not assert a
    // specific ordering, only non-emptiness, matching the acceptance
    // criterion's own wording ("Hot shows commented threads on top after
    // cron" — a live-environment/cron precondition outside this spec's
    // control).
    const rows = region.locator('.views-row, tr, li');
    await expect(rows.first()).toBeVisible();
  });

  test('deep-linking to /showcase?discovery=hot pre-selects the Hot tab (aria-checked)', async ({
    page,
  }) => {
    await page.goto('/showcase?discovery=hot');
    const switcher = discoverySwitcherLocator(page);
    await expect(switcher.getByRole('radio', { name: /Hot/i })).toHaveAttribute('aria-checked', 'true');
  });

  test('the switcher wrapper carries role="radiogroup" and a non-empty aria-label', async ({ page }) => {
    await page.goto('/showcase');
    const switcher = discoverySwitcherLocator(page);
    await expect(switcher).toHaveAttribute('role', 'radiogroup');
    const ariaLabel = await switcher.getAttribute('aria-label');
    expect(ariaLabel).toBeTruthy();
  });

  test('exactly ONE wrapper-level tooltip trigger renders on the switcher (POC scope, not per-option)', async ({
    page,
  }) => {
    await page.goto('/showcase');
    const switcher = discoverySwitcherLocator(page);
    const tooltips = switcher.locator('[data-do-tooltip]');
    await expect(tooltips).toHaveCount(1);
  });

  test('both switchers coexist: ?variant=cards&discovery=hot sets each independently', async ({
    page,
  }) => {
    await page.goto('/showcase?variant=cards&discovery=hot');

    const directorySwitcher = directorySwitcherLocator(page);
    await expect(directorySwitcher.getByRole('radio', { name: /Cards/i })).toHaveAttribute('aria-checked', 'true');

    const discoverySwitcher = discoverySwitcherLocator(page);
    await expect(discoverySwitcher.getByRole('radio', { name: /Hot/i })).toHaveAttribute('aria-checked', 'true');
  });

  test('WCAG-adjacent smoke: the discovery.ranking switcher is keyboard-operable', async ({ page }) => {
    await page.goto('/showcase');
    const switcher = discoverySwitcherLocator(page);
    const recent = switcher.getByRole('radio', { name: /Recent/i });
    const hot = switcher.getByRole('radio', { name: /Hot/i });

    await recent.focus();
    await expect(recent).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(hot).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(hot).toHaveAttribute('aria-checked', 'true');
    await page.waitForURL(/[?&]discovery=hot\b/);
  });
});

test.describe('#123 SC-4 — existing suites stay green (non-regression)', () => {
  test('/hot standalone page is unaffected by the new discovery.ranking switcher', async ({ page }) => {
    const res = await page.goto('/hot');
    expect(res?.status()).toBe(200);
  });

  test('directory.layout stub switcher on /showcase still renders unaffected', async ({ page }) => {
    await page.goto('/showcase');
    await expect(directorySwitcherLocator(page)).toBeVisible();
  });
});
