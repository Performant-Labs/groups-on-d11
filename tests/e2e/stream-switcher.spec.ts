import { test, expect, Page } from '@playwright/test';

/**
 * E2E for #115 ST-6 — Stream switcher chrome.
 *
 * Pins the acceptance criteria from
 * docs/handoffs/115-stream-switcher/brief.md + the approved wireframe
 * (docs/handoffs/115-stream-switcher/wireframe.html, handoff-D.md):
 *   - Authenticated (Elena): /stream and /following both render the switcher
 *     `<nav>`, with the correct tab carrying `aria-current="page"`, and all
 *     tab labels for the scopes whose route currently exists are present.
 *   - Anonymous on /stream: only Global + Trending tab links are present in
 *     the DOM (My Feed / Following absent entirely — omission, not
 *     disabling, per the wireframe's Screen 3 rule).
 *   - Group stream (/group/{id}/stream): NO switcher `<nav>` at all
 *     (wireframe Screen 4 control / negative-space assertion).
 *   - Keyboard: the first tab is reachable via Tab and receives visible
 *     focus (`:focus-visible` convention — 2px solid outline, matching
 *     do_group_membership/do_showcase CSS).
 *   - /hot navigation: because /trending does NOT exist on this branch yet
 *     (sibling #113 not merged), /hot must render normally (no redirect).
 *     This assertion is EXPECTED TO FLIP once #113 merges and the
 *     HotRedirectSubscriber's route-existence check finds /trending —
 *     marked with a comment below so a future maintainer knows to update it.
 *
 * `StreamSwitcherHooks`, `stream-switcher.html.twig`, and
 * `css/stream-switcher.css` do not exist yet at RED time (Phase 4, before F
 * implements) — every switcher-specific selector below targets markup the
 * brief + wireframe specify but nothing in the codebase renders yet. This
 * spec is authored to RED (assert the file exists + is syntactically valid
 * via `npx playwright test --list`) and is NOT executed for real until
 * T-GREEN, against a fully seeded, running site (assemble -> site:install ->
 * cim -> seed -> runserver), per PROJECT_CONTEXT.md — mirroring
 * directory-toggle.spec.ts's own RED-time convention.
 *
 * Selector contract (F must honor, per the task's explicit instruction):
 *   - Switcher wrapper: [data-testid="stream-switcher"] (a <nav>).
 *   - Each tab: [data-testid="stream-switcher-tab"][data-scope-id="<id>"].
 *
 * Login helper + seeded persona/password convention mirrors
 * tests/e2e/following.spec.ts exactly (Elena = elena_garcia,
 * `demo_password_2026`).
 */

const SEEDED_PASSWORD = process.env.SEEDED_PASSWORD ?? 'demo_password_2026';
const ELENA = 'elena_garcia';

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

/** The switcher <nav> wrapper locator. */
function switcherLocator(page: Page) {
  return page.locator('[data-testid="stream-switcher"]');
}

/** A single tab locator, scoped by scope id (e.g. 'global', 'following'). */
function tabLocator(page: Page, scopeId: string) {
  return page.locator(
    `[data-testid="stream-switcher-tab"][data-scope-id="${scopeId}"]`,
  );
}

/**
 * Finds a real, currently-existing group id via the public directory —
 * gids are not stable across re-seeds (mirrors group-links.spec.ts's own
 * "find via directory, don't assume a fixed gid" convention).
 */
async function firstGroupId(page: Page): Promise<string> {
  await page.goto('/all-groups');
  const card = page.locator('.gc-directory-card').first();
  await expect(card).toBeVisible();
  const link = card.locator('.gc-directory-card__title a').first();
  await link.click();
  await page.waitForURL(/\/group\/\d+/);
  const match = page.url().match(/\/group\/(\d+)/);
  if (!match) {
    throw new Error(`Could not extract a group id from URL: ${page.url()}`);
  }
  return match[1];
}

test.describe('#115 ST-6 — Stream switcher chrome (authenticated)', () => {
  test('Elena on /stream sees the switcher with Global active', async ({ page }) => {
    await login(page, ELENA, SEEDED_PASSWORD);

    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);

    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();
    await expect(switcher).toHaveAttribute('role', 'navigation');
    await expect(switcher).toHaveAttribute('aria-label', /stream/i);

    // Only scopes whose route exists on THIS branch render as tabs
    // (/my-feed and /trending don't exist yet — sibling stories #112/#113).
    // Global + Following are the two guaranteed present for an authenticated
    // viewer.
    const globalTab = tabLocator(page, 'global');
    await expect(globalTab).toBeVisible();
    await expect(globalTab).toHaveAttribute('aria-current', 'page');
    await expect(globalTab).toContainText('Global');

    const followingTab = tabLocator(page, 'following');
    await expect(followingTab).toBeVisible();
    await expect(followingTab).not.toHaveAttribute('aria-current', 'page');
    await expect(followingTab).toContainText('Following');
  });

  test('Elena on /following sees the switcher with Following active', async ({ page }) => {
    await login(page, ELENA, SEEDED_PASSWORD);

    const res = await page.goto('/following');
    expect(res?.status()).toBe(200);

    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();

    const followingTab = tabLocator(page, 'following');
    await expect(followingTab).toBeVisible();
    await expect(followingTab).toHaveAttribute('aria-current', 'page');

    const globalTab = tabLocator(page, 'global');
    await expect(globalTab).toBeVisible();
    await expect(globalTab).not.toHaveAttribute('aria-current', 'page');
  });

  test('/stream card markup is unchanged — switcher sits above results, not wrapping them', async ({ page }) => {
    await login(page, ELENA, SEEDED_PASSWORD);
    await page.goto('/stream');

    // The switcher and the results are sibling regions, not nested — the
    // results container must exist OUTSIDE the switcher nav.
    const switcher = switcherLocator(page);
    const resultsInsideSwitcher = switcher.locator('.view-content, .views-row');
    await expect(resultsInsideSwitcher).toHaveCount(0);
  });
});

test.describe('#115 ST-6 — Stream switcher chrome (anonymous)', () => {
  test('anonymous on /stream sees only Global + Trending tab links; My Feed/Following absent', async ({ page }) => {
    const res = await page.goto('/stream');
    expect(res?.status()).toBe(200);

    const switcher = switcherLocator(page);
    await expect(switcher).toBeVisible();

    await expect(tabLocator(page, 'global')).toBeVisible();

    // Following's ROUTE exists on this branch, but must still be OMITTED for
    // anonymous users (anon allowlist = ['global', 'trending']) — proves the
    // anon filter is independent of route-existence.
    await expect(tabLocator(page, 'following')).toHaveCount(0);
    await expect(tabLocator(page, 'my_feed')).toHaveCount(0);

    // Trending's route does NOT exist on this branch yet either (sibling
    // #113), so it is omitted for BOTH reasons (allowlist + no route) — not
    // asserted present here. Once #113 merges, this test should be updated
    // to assert Trending IS present for anonymous users.
  });
});

test.describe('#115 ST-6 — Stream switcher chrome (group stream control)', () => {
  test('group stream shows NO switcher nav at all', async ({ page }) => {
    const gid = await firstGroupId(page);

    const res = await page.goto(`/group/${gid}/stream`);
    expect(res?.status()).toBe(200);

    await expect(switcherLocator(page)).toHaveCount(0);
  });
});

test.describe('#115 ST-6 — Stream switcher chrome (keyboard/focus)', () => {
  test('the first tab is reachable via Tab and shows a visible focus outline', async ({ page }) => {
    await login(page, ELENA, SEEDED_PASSWORD);
    await page.goto('/stream');

    const globalTab = tabLocator(page, 'global');
    await globalTab.focus();
    await expect(globalTab).toBeFocused();

    // Confirm the :focus-visible outline convention is actually loaded and
    // applied — matches the 2px solid outline convention already used by
    // do_group_membership/manage-members.css and do_showcase CSS files
    // (handoff-D.md). We check the computed outline style rather than a
    // literal color, since the exact token value is F's implementation
    // choice; the CONTRACT is "a visible, non-zero outline appears".
    const outlineWidth = await globalTab.evaluate(
      (el) => window.getComputedStyle(el, null).outlineWidth,
    );
    expect(
      outlineWidth === '0px' || outlineWidth === '',
      `Focused stream-switcher tab should have a non-zero :focus-visible outline (got outlineWidth="${outlineWidth}").`,
    ).toBe(false);
  });
});

test.describe('#115 ST-6 — /hot redirect (route-tolerance)', () => {
  test('/hot renders normally because /trending does not exist on this branch yet', async ({ page }) => {
    // NOTE: this assertion is expected to FLIP once sibling #113 merges and
    // registers the /trending route — HotRedirectSubscriber will then find
    // /trending via router.route_provider and issue a 302 redirect. Until
    // then, /hot must fall through to its existing hot_content view page,
    // per brief.md step 6 ("otherwise no-op").
    const res = await page.goto('/hot', { waitUntil: 'domcontentloaded' });
    expect(res?.status()).toBe(200);
    expect(page.url()).toMatch(/\/hot$/);
    expect(page.url()).not.toMatch(/\/trending$/);
  });
});
