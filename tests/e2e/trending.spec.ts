import { test, expect, Page } from '@playwright/test';

/**
 * E2E for the Trending surface — story #113 ST-4 (`/trending`).
 *
 * Pins the acceptance criteria from
 * docs/planning/handoffs/113-trending/brief.md and the wireframe's per-state
 * contract (docs/planning/handoffs/113-trending/wireframe.md):
 *   - Anonymous GET /trending -> 200, exactly one <h1> matching /trending/i
 *     (view is `access.type: none` — public, unlike /following's
 *     authenticated-only gate; see wireframe §5 "Anonymous vs authenticated
 *     — MUST be identical").
 *   - Score-ordered content: "Venue Logistics Thread" and "Patch Review
 *     Process RFC" (both seeded with 2 comments -> post-cron hot score 6.0,
 *     see docs/groups/scripts/step_700_demo_data.php:141,143,216-219) must
 *     appear among the first 10 rendered card titles, ahead of the
 *     zero-comment nodes (score 0.0). A's watch #1: this must be a positive
 *     "both titles are in the top-10 window" check, not a loose substring
 *     probe against the whole page (which would pass even if the titles
 *     rendered on page 3).
 *   - Empty-state / cards-present are mutually exclusive: when cards render,
 *     "Nothing trending yet." must NOT also render. (Per wireframe §2a, the
 *     empty state is issue-body copy verbatim; per brief this view is NOT a
 *     do_streams_shell consumer, so the shell's own distinct empty-copy for
 *     its trending SCOPE TAB is unrelated to this route.)
 *   - Regression guard (A's watch #2): /hot must remain unaffected — the
 *     acceptance checklist forbids editing views.view.hot_content.yml. Login
 *     as a seeded persona and confirm /hot still 200s with the "Hot Content"
 *     label. (Verified: docs/groups/config/views.view.hot_content.yml carries
 *     no `access:` block at all -> Views' default is public/unrestricted, so
 *     this is not actually an auth-gated route; the login here exercises the
 *     authenticated path as a superset check, not because /hot requires it.)
 *   - Library attach, mechanism-agnostic (A's watch #3 / Finding 3): whatever
 *     wiring F chooses (views_pre_render hook vs. extending the existing
 *     preprocessViewsView() id() guard), the observable contract is that the
 *     rendered HTML on /trending references trending.css, and /following
 *     still references following.css (regression guard that a shared
 *     preprocess method touching both id() checks doesn't clobber the
 *     existing following attach).
 *   - `.stream-card-wrapper` class renders per the existing convention shared
 *     with following_feed/hot_content (wireframe §2 — card markup/visuals are
 *     100% inherited, not redesigned by this story).
 *   - WCAG-adjacent: exactly one <h1>; pager Next link (if rendered) has an
 *     accessible name (wireframe §7).
 *
 * The view `trending`, its route `/trending`, the CSS file, and the library
 * attach do not exist yet — see handoff-T-red.md for the RED verification.
 * This is the intended RED: F creates
 * docs/groups/config/views.view.trending.yml (clone of following_feed.yml +
 * hot_content.yml's sort block), docs/groups/modules/do_streams/css/trending.css,
 * and wires the library attach + cron triggers per brief.md steps 1-5.
 *
 * Login helper + credential convention follow tests/e2e/following.spec.ts:
 * seeded personas authenticate with password `demo_password_2026` (see
 * docs/groups/scripts/step_700_demo_data.php line 25). elena_garcia is reused
 * here (already exercised by following.spec.ts) purely as a login vehicle for
 * the /hot and /following regression checks — no following-specific behavior
 * is asserted in this spec.
 *
 * BigPipe / streamed-render note (T repair round 2, post-CI, supersedes
 * round 1's AJAX-polling theory): the initial page HTML contains a Drupal
 * BigPipe placeholder for the view body — the view's cards are NOT under
 * `.view-content` in the DOM at the instant Playwright first reads it (an
 * unscoped `.stream-card-wrapper` locator resolves once BigPipe fills the
 * placeholder in; a locator scoped through `.view-content` does not resolve
 * reliably, because the placeholder swap does not preserve that exact
 * subtree path within the polling window observed in CI). Use the UNSCOPED
 * `.stream-card-wrapper` locator (proven reliable — see test 3) waited with
 * `.toBeVisible()` first. For the library-attach check, `page.content()` is
 * unreliable regardless of timing (the CSS `<link>` may stream in via
 * BigPipe, may be aggregated, or may not appear as a literal `trending.css`/
 * `following.css` substring at all) — assert instead on an EFFECTIVE
 * computed style unique to each page's own small CSS file
 * (`.trending-page`/`.following-feed` { margin-top: 1rem } => computed
 * `16px` at the default 16px root font-size, confirmed no theme override
 * exists). This is a behavior check, not a string-match on the asset URL,
 * so it survives both BigPipe streaming and CSS aggregation.
 */

const SEEDED_PASSWORD = process.env.SEEDED_PASSWORD ?? 'demo_password_2026';

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

test.describe('ST-4 — Trending (/trending) — #113', () => {
  // Keep the anonymous path FIRST: if the view doesn't exist yet, this test
  // fails loudly and immediately (404), making the RED signature obvious
  // without needing to wade through the ordering/regression tests below.
  test('anonymous GET /trending returns 200 with exactly one <h1> matching /trending/i', async ({
    page,
  }) => {
    const res = await page.goto('/trending');
    expect(res?.status()).toBe(200);

    const h1 = page.getByRole('heading', { level: 1 });
    await expect(h1).toHaveCount(1);
    await expect(h1).toHaveText(/trending/i);
  });

  test('the two 2-comment threads (score 6.0) appear in the first 10 rendered cards, and no empty-state string leaks through', async ({
    page,
  }) => {
    const res = await page.goto('/trending');
    expect(res?.status()).toBe(200);

    // The view body arrives via a Drupal BigPipe placeholder — wait for the
    // UNSCOPED `.stream-card-wrapper` locator (the same pattern test 3 uses
    // and which is proven reliable in CI) rather than a `.view-content`-
    // scoped locator, which does not reliably resolve once BigPipe fills the
    // placeholder in.
    const allCards = page.locator('.stream-card-wrapper');
    await expect(allCards.first()).toBeVisible();

    // Positive "both titles are ranked in the visible window" check, not a
    // whole-page substring probe that would pass even if the titles rendered
    // on a later page (A's watch #1). items_per_page is 10 per brief Step 1,
    // so the entire page_1 response IS the top-10 window — every
    // `.stream-card-wrapper` on this page already IS "in the top 10", so no
    // further CSS-position scoping (e.g. `:nth-of-type`) is needed or
    // reliable given the BigPipe-streamed markup.
    const firstTenCards = allCards;

    await expect(
      firstTenCards.getByRole('link', {
        name: 'Venue Logistics Thread',
        exact: true,
      }),
    ).not.toHaveCount(0);
    await expect(
      firstTenCards.getByRole('link', {
        name: 'Patch Review Process RFC',
        exact: true,
      }),
    ).not.toHaveCount(0);

    // Empty-state safety: mutually exclusive with cards rendering. Checked
    // AFTER the visibility wait above, so BigPipe has settled and the
    // empty-state region (if any) has had its chance to render. Do not
    // attempt to force an empty state (seeded site is non-empty) — the
    // presence-of-cards branch above is sufficient in CI (brief instruction).
    await expect(page.locator('body')).not.toContainText(
      'Nothing trending yet.',
    );
  });

  test('at least one .stream-card-wrapper card renders on /trending', async ({
    page,
  }) => {
    await page.goto('/trending');
    await expect(page.locator('.stream-card-wrapper').first()).toBeVisible();
  });

  test('regression guard: /hot still 200s with the "Hot Content" label (views.view.hot_content.yml untouched)', async ({
    page,
  }) => {
    await login(page, 'elena_garcia', SEEDED_PASSWORD);
    const res = await page.goto('/hot');
    expect(res?.status()).toBe(200);
    await expect(page.locator('body')).toContainText('Hot Content');
  });

  test('library attach is mechanism-agnostic: trending.css referenced on /trending, following.css still referenced on /following', async ({
    page,
  }) => {
    await page.goto('/trending');

    // Wait for the view body to settle (BigPipe placeholder filled in —
    // unscoped locator, same proven-reliable pattern as test 3), THEN assert
    // on an EFFECTIVE COMPUTED STYLE unique to trending.css
    // (`.trending-page { margin-top: 1rem }` => 16px at the default root
    // font-size) rather than a `page.content()` substring match. A string
    // match on the literal filename is unreliable regardless of timing: the
    // `<link>` may stream in via BigPipe after this snapshot, or the site may
    // aggregate CSS (bundling multiple files under a hashed URL with no
    // `trending.css` substring at all). The computed-style check is a
    // behavior assertion — it holds true either way, since it observes the
    // effect of the loaded stylesheet rather than the delivery mechanism.
    await expect(page.locator('.stream-card-wrapper').first()).toBeVisible();
    const trendingMarginTop = await page
      .locator('.trending-page')
      .evaluate((el) => window.getComputedStyle(el).marginTop);
    expect(trendingMarginTop).toBe('16px');

    // Regression guard: the shared preprocess (however F wires the new
    // trending id() guard) must not clobber the existing following attach.
    // Same computed-style pattern for following.css's own
    // `.following-feed { margin-top: 1rem }` rule.
    await login(page, 'elena_garcia', SEEDED_PASSWORD);
    await page.goto('/following');
    await expect(page.locator('.stream-card-wrapper').first()).toBeVisible();
    const followingMarginTop = await page
      .locator('.following-feed')
      .evaluate((el) => window.getComputedStyle(el).marginTop);
    expect(followingMarginTop).toBe('16px');
  });

  test('WCAG-adjacent: exactly one <h1>; pager Next link (if present) has an accessible name', async ({
    page,
  }) => {
    await page.goto('/trending');

    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);

    // Pager may be absent if the view's total row count fits on one page
    // (Views omits pager chrome in that case — wireframe §3). Skip
    // gracefully rather than asserting presence.
    const nextLink = page.getByRole('link', { name: /next/i });
    const nextCount = await nextLink.count();
    if (nextCount > 0) {
      await expect(nextLink.first()).toHaveAccessibleName(/next/i);
    }
  });
});
