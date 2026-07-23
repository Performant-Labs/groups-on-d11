import { test, expect, Page, Browser } from '@playwright/test';

/**
 * Issue #110 (ST-1 My Feed at /my-feed) E2E.
 *
 * Exercises the new authenticated `/my-feed` route end to end against the
 * FULL SEEDED demo site (WAVE-EXECUTION-HANDOFF.md §6.6 — this suite must
 * run against `docs/groups/scripts/step_700_demo_data.php` +
 * `step_780_nav_menu.php`'s real seed, not an isolated fixture), mirroring
 * `manage-members.spec.ts` / `nav.spec.ts`'s self-contained login-helper
 * conventions.
 *
 * Covers (see docs/planning/handoffs/110-stream-110/handoff-D.md "For T"):
 *  - AC-1: anonymous GET /my-feed -> login redirect or 403 (no shell rendered).
 *  - AC-8 / handoff-A.md Finding #1: anonymous main nav shows Groups/Activity
 *    but the "My Feed" link is ABSENT FROM THE DOM (not merely hidden) for
 *    anonymous users; present (with an accessible name "My Feed" and an href
 *    resolving to /my-feed) for authenticated users.
 *  - AC-2/AC-3/AC-4: authenticated elena_garcia sees the shell chrome with
 *    the my_feed tab + recent ranking pill both active.
 *  - AC-9: elena_garcia's feed leads with "Sprint Planning: Portland 2026"
 *    (pinned, from DrupalCon Portland 2026 — one of her 5 seeded groups) and
 *    shows NO content from groups she is not a member of (Thunder
 *    Distribution / Drupal Deutschland).
 *  - AC-6: a fresh 0-group authenticated user sees the empty state AND the
 *    new empty_cta slot linking to /all-groups.
 *  - AC-5/AC-9 REWORK (docs/planning/handoffs/110-stream-110/handoff-S.md
 *    Phase 8 audit): cross-user render-cache correctness. S found that the
 *    view's `cache: { type: tag }` plugin (docs/groups/config/views.view.my_feed.yml
 *    line 118-120) does not vary the INNER view render subtree by viewing
 *    user, so the FIRST user to hit /my-feed after a cache clear has their
 *    rendered feed served to every SUBSEQUENT user, regardless of that
 *    user's own group memberships. Reproduced live: admin fetches /my-feed
 *    (sees Thunder Distribution content), then a fresh Elena session fetches
 *    /my-feed immediately after (with NO cache clear in between) and is
 *    served admin's cached feed, including Thunder Distribution content —
 *    even though Elena is not a member of that group. The test below fetches
 *    as admin then as Elena, in the SAME test-process, with no cache-clearing
 *    step between the two fetches, and asserts Elena's response contains
 *    only her own group content. This is the exact scenario the rest of this
 *    suite's per-test-fresh-context pattern does not exercise, because each
 *    existing test either runs in isolation or is the first authenticated
 *    fetch after seeding.
 *
 * None of the route/controller/view exist yet (this story's own brief names
 * them as NEW files) — every test below is intended to fail at its first
 * navigation/assertion (404 or a shell-selector timeout) until F implements
 * against this suite. This is the deliberate RED.
 *
 * T-red self-correction (both fixed before RED was reported valid):
 *  - ELENA_PASS originally defaulted to the username itself; the real seeded
 *    password (confirmed by reading step_700_demo_data.php directly) is the
 *    shared "demo_password_2026" every demo user gets, not their username.
 *  - The AC-6 (zero-group user) test originally logged the admin OUT via a
 *    bare `page.goto('/user/logout')` in the SAME page/session, then tried to
 *    log back in as the fresh user. Drupal 10.3+'s logout route requires a
 *    CSRF-protected confirmation (a plain GET does not end the session in the
 *    same way the UI's tokenized "Log out" link does), so the admin session
 *    persisted and the subsequent login() call timed out waiting for a
 *    /user/login form that was never reached (still on /user/1). Fixed by
 *    using a SEPARATE, unauthenticated browser context for the zero-group
 *    user's login — the realistic multi-user shape, and immune to the
 *    logout-route's CSRF mechanics entirely.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

// elena_garcia is seeded by docs/groups/scripts/step_700_demo_data.php with
// 5 group memberships (DrupalCon Portland 2026, Core Committers, Leadership
// Council, Camp Organizers EMEA, Drupal France) and is NOT a member of
// Thunder Distribution / Drupal Deutschland (per survey.md's confirmed grep).
const ELENA_USER = 'elena_garcia';
const ELENA_PASS = process.env.ELENA_PASS ?? 'demo_password_2026';

const NAV = '#block-groups-chrome-main-menu';

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

test.describe('My Feed (#110 ST-1)', () => {
  test('anonymous GET /my-feed is denied or redirected to login (AC-1)', async ({
    page,
  }) => {
    const response = await page.goto('/my-feed');
    const status = response?.status() ?? 0;
    const onLoginPage = /\/user\/login/.test(page.url());

    expect(
      status === 403 || onLoginPage,
      `Anonymous /my-feed must be 403 or redirect to /user/login; got status ${status} at ${page.url()}`,
    ).toBe(true);

    // No shell chrome ever renders for the denied/redirected anonymous request.
    await expect(page.locator('[data-testid="do-streams-shell"]')).toHaveCount(0);
  });

  test('anonymous main nav shows Groups/Activity but NOT a My Feed link (AC-8, handoff-A Finding #1)', async ({
    page,
  }) => {
    await page.goto('/');
    const nav = page.locator(NAV);

    await expect(nav.getByRole('link', { name: 'Groups', exact: true })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Activity', exact: true })).toBeVisible();

    // Absence from the DOM, not merely display:none — count must be 0.
    await expect(nav.getByRole('link', { name: 'My Feed', exact: true })).toHaveCount(0);
  });

  test('authenticated main nav shows a "My Feed" link resolving to /my-feed (AC-8)', async ({
    page,
  }) => {
    await login(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/');
    const nav = page.locator(NAV);

    const myFeedLink = nav.getByRole('link', { name: 'My Feed', exact: true });
    await expect(myFeedLink).toBeVisible();
    await expect(myFeedLink).toHaveAttribute('href', /\/my-feed$/);
  });

  test('elena_garcia sees the shell chrome with My Feed + Recent active (AC-2, AC-3, AC-4)', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    const response = await page.goto('/my-feed');
    expect(response?.status()).toBe(200);

    const shell = page.locator('[data-testid="do-streams-shell"]');
    await expect(shell).toBeVisible();

    const myFeedTab = page.locator(
      '[data-testid="do-streams-shell-tab"][data-scope-id="my_feed"]',
    );
    await expect(myFeedTab).toHaveClass(/is-active/);
    await expect(myFeedTab).toHaveAttribute('aria-current', 'true');

    const recentPill = page.locator(
      '[data-testid="do-streams-shell-ranking-pill"][data-ranking-id="recent"]',
    );
    await expect(recentPill).toHaveClass(/is-active/);
  });

  test('elena_garcia\'s feed leads with pinned "Sprint Planning: Portland 2026" and excludes out-of-scope groups (AC-9)', async ({
    page,
  }) => {
    await login(page, ELENA_USER, ELENA_PASS);
    await page.goto('/my-feed');

    const results = page.locator('[data-testid="do-streams-shell-results"]');
    await expect(results).toBeVisible();

    await expect(results).toContainText('Sprint Planning: Portland 2026');

    // The pinned leading card is the FIRST card in the results region.
    const firstCard = results.locator('article, .card').first();
    await expect(firstCard).toContainText('Sprint Planning: Portland 2026');

    // Negative assertion: no content from groups Elena is NOT a member of.
    await expect(results).not.toContainText('Thunder Distribution');
    await expect(results).not.toContainText('Drupal Deutschland');
  });

  test('a zero-group authenticated user sees the empty state with a CTA to /all-groups (AC-6)', async ({
    page,
    browser,
  }: { page: Page; browser: Browser }) => {
    // Self-provision a fresh 0-group user via the admin UI (no group
    // membership is ever granted), rather than relying on a specific seeded
    // account remaining group-less across future seed changes.
    await login(page, ADMIN_USER, ADMIN_PASS);
    const username = `myfeed_zero_${Date.now()}`;
    const password = 'ZeroGroupPass123!';

    await page.goto('/admin/people/create');
    await page.getByLabel('Username', { exact: true }).fill(username);
    await page.getByLabel('Email address', { exact: true }).fill(`${username}@example.com`);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password', { exact: true }).fill(password);
    const statusActive = page.locator('input[name="status"][value="1"]');
    if (await statusActive.count()) {
      await statusActive.check();
    }
    await page.getByRole('button', { name: /Create new account/i }).click();
    await expect(page.locator('.messages--status, .messages--error')).toBeVisible();

    // Log in as the fresh zero-group user in a SEPARATE browser context (a
    // distinct, unauthenticated cookie jar) rather than logging the admin out
    // in the same page — sidesteps Drupal's CSRF-protected logout route
    // entirely and mirrors a realistic second, independent visitor.
    const zeroGroupContext = await browser.newContext();
    const zeroGroupPage = await zeroGroupContext.newPage();
    try {
      await login(zeroGroupPage, username, password);

      const response = await zeroGroupPage.goto('/my-feed');
      expect(response?.status()).toBe(200);

      const empty = zeroGroupPage.locator('[data-testid="do-streams-shell-empty"]');
      await expect(empty).toBeVisible();

      const cta = zeroGroupPage.locator('[data-testid="do-streams-shell-empty-cta"]');
      await expect(cta).toBeVisible();
      await expect(cta).toHaveAttribute('href', /\/all-groups/);
    } finally {
      await zeroGroupContext.close();
    }
  });

  test('/my-feed does not leak one user\'s cached results to the next user with no cache clear between (AC-5, AC-9 — handoff-S cross-user cache leak)', async ({
    page,
    browser,
  }: { page: Page; browser: Browser }) => {
    // Reproduces docs/planning/handoffs/110-stream-110/handoff-S.md's finding
    // exactly: fetch /my-feed as admin (uid=1, member of Thunder Distribution
    // among other groups), THEN fetch /my-feed as elena_garcia in a separate,
    // unauthenticated browser context — with NO drush cr / cache invalidation
    // step between the two fetches. Elena is seeded into gids 1, 2, 3, 5, 6
    // and is NOT a member of Thunder Distribution (gid 4). If the view's
    // render cache is keyed without a per-viewing-user context (the type:tag
    // plugin bug S found), Elena's response will incorrectly still contain
    // admin's Thunder Distribution content — this test fails exactly the way
    // the defect manifests, and only passes once /my-feed's cache is made
    // per-user (e.g. the view's cache plugin set to `type: none`, or an
    // equivalent per-user cache context/tag fix).
    await login(page, ADMIN_USER, ADMIN_PASS);
    const adminResponse = await page.goto('/my-feed');
    expect(adminResponse?.status()).toBe(200);
    const adminResults = page.locator('[data-testid="do-streams-shell-results"]');
    await expect(adminResults).toBeVisible();
    const adminResultsText = (await adminResults.textContent()) ?? '';

    // Deliberately NO cache-clearing step here — this is the point of the test.

    const elenaContext = await browser.newContext();
    const elenaPage = await elenaContext.newPage();
    try {
      await login(elenaPage, ELENA_USER, ELENA_PASS);
      const elenaResponse = await elenaPage.goto('/my-feed');
      expect(elenaResponse?.status()).toBe(200);

      const elenaResults = elenaPage.locator('[data-testid="do-streams-shell-results"]');
      await expect(elenaResults).toBeVisible();

      // Elena must see only her own group content: Thunder Distribution
      // (gid 4) is out of scope for her and must NOT appear, even though it
      // was present in the immediately-preceding admin response above.
      await expect(elenaResults).not.toContainText('Thunder Distribution');

      // Elena's own scoped content (pinned lead item from her DrupalCon
      // Portland 2026 membership) must be present — proves this is a real,
      // freshly-scoped render for Elena, not merely an empty/error response.
      await expect(elenaResults).toContainText('Sprint Planning: Portland 2026');

      // The two users' rendered result sets must differ — if the cache
      // leaked, Elena's text would be byte-identical to admin's.
      const elenaResultsText = (await elenaResults.textContent()) ?? '';
      expect(elenaResultsText).not.toBe(adminResultsText);
    } finally {
      await elenaContext.close();
    }
  });
});
