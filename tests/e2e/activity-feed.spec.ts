import { test, expect } from '@playwright/test';

/**
 * #129 ST-7 Activity feed rendering — E2E (AC-6).
 *
 * Runs against the FULL SEEDED demo site (assemble -> site:install ->
 * config:import -> seed -> runserver -> playwright), per
 * WAVE-EXECUTION-HANDOFF.md §6.6 and persona-switcher.spec.ts's own
 * established convention.
 *
 * FIXTURE APPROACH (brief's AC-6 explicitly requires one): this spec relies
 * on `docs/groups/scripts/step_795_activity_feed_e2e_fixture.php`, a
 * dedicated, idempotent drush-scr seed script (NOT a pre-test HTTP call),
 * run as part of the site's normal seed sequence (after
 * step_7xx_backfill_activity.php and step_790_persona_switcher.php). A
 * drush-scr seed script was chosen over an HTTP pre-test hook because:
 *   (a) it matches this repo's established pattern for every other
 *       persona/demo-data fixture (step_700/step_770/step_790, all plain
 *       drush-scr includes with their own idempotency keys) — no HTTP
 *       fixture-setup convention exists anywhere else in this test suite
 *       to extend instead;
 *   (b) it can set EXACT, deterministic Message `created` timestamps (each
 *       of Alex's 3 posts pinned to a controlled offset, guaranteed <=6h
 *       apart) — an HTTP-driven UI flow creating real posts one-by-one
 *       could not control `created` precisely enough to guarantee the
 *       aggregation shape without also being a flaky, slow multi-step UI
 *       script in its own right;
 *   (c) per the brief's own wording, "do NOT flake on incidental seed
 *       data" — the safest way to guarantee >=1 aggregated row (a run of
 *       >=2 same-actor same-group posts within the 6h window) is to seed it
 *       explicitly rather than hope step_700's organic demo content happens
 *       to land in that shape.
 *
 * Fixture guarantees on the "Camp Organizers EMEA" group (elena_garcia is
 * already a seeded member per step_700_demo_data.php):
 *   - >=1 activity_membership_created row for elena_garcia (social row).
 *   - >=1 run of 3 activity_post_created rows by alex_novak, each 2h apart
 *     (aggregated row, "Alex Novak posted 3 topics").
 *   - >=1 standalone activity_post_created row by elena_garcia, >6h from
 *     anything else (content row, unaggregated).
 *
 * Selector contract (from the wireframe,
 * docs/planning/handoffs/129-activity-feed/wireframe.html): row shapes are
 * `data-testid="activity-row-social"`, `"activity-row-aggregated"`,
 * `"activity-row-content"`; the shell wrapper is
 * `data-testid="activity-feed-shell"`; the empty state is
 * `data-testid="activity-feed-empty"`.
 *
 * do_activity_feed does not exist yet at RED time — every selector below
 * targets markup/routes nothing in the codebase renders yet (no
 * do_activity_feed.routing.yml, no /activity route at all: a fresh site
 * 404s here). This spec is authored and confirmed to `--list` cleanly at
 * RED (see handoff-T-red.md); it is executed for real against the fully
 * seeded site at T-GREEN, once F ships the module + the seed sequence
 * above has run.
 */

const SHELL_SELECTOR = '[data-testid="activity-feed-shell"]';
const SOCIAL_ROW_SELECTOR = '[data-testid="activity-row-social"]';
const AGGREGATED_ROW_SELECTOR = '[data-testid="activity-row-aggregated"]';
const CONTENT_ROW_SELECTOR = '[data-testid="activity-row-content"]';

// Dismiss the site-wide POC ribbon (do_showcase, #119) before navigating —
// mirrors persona-switcher.spec.ts's own established workaround for the
// ribbon intercepting pointer events on interactive controls immediately
// after a page load/redirect.
test.beforeEach(async ({ page }) => {
  await page.goto('/');
  const dismiss = page.getByRole('button', { name: 'Dismiss demo banner' });
  if (await dismiss.isVisible().catch(() => false)) {
    await dismiss.click();
  }
});

/**
 * Switches to the Elena Garcia persona via the existing #120 persona
 * switcher (`select[name="persona"]`), matching persona-switcher.spec.ts's
 * own selection + fallback-button pattern exactly.
 */
async function switchToElena(page: import('@playwright/test').Page): Promise<void> {
  const select = page.locator('select[name="persona"]');
  await expect(select).toBeVisible();
  await select.selectOption({ label: 'Elena Garcia — Member' });

  const goButton = page.getByRole('button', { name: /go/i });
  if (await goButton.isVisible().catch(() => false)) {
    await goButton.click();
  }
  await page.waitForLoadState('networkidle');
}

test.describe('#129 ST-7 — Activity feed: my_groups scope (Elena persona)', () => {
  test('shows at least one social row, one aggregated row, and one content row', async ({ page }) => {
    await switchToElena(page);

    const response = await page.goto('/activity');
    expect(response?.status()).toBe(200);

    const shell = page.locator(SHELL_SELECTOR);
    await expect(shell).toBeVisible();

    await expect(page.locator(SOCIAL_ROW_SELECTOR).first()).toBeVisible();
    await expect(page.locator(AGGREGATED_ROW_SELECTOR).first()).toBeVisible();
    await expect(page.locator(CONTENT_ROW_SELECTOR).first()).toBeVisible();

    // AC-1's aggregated-row disclosure contract: the aggregated row's count
    // reads as text ("3 topics"), never color-only, per the WCAG non-color
    // status requirement.
    const aggregatedRow = page.locator(AGGREGATED_ROW_SELECTOR).first();
    await expect(aggregatedRow).toContainText(/\d+\s+topics?/i);
  });

  test('the aggregated row is a native <details>/<summary> disclosure, keyboard-operable', async ({ page }) => {
    await switchToElena(page);
    await page.goto('/activity');

    const aggregatedRow = page.locator(AGGREGATED_ROW_SELECTOR).first();
    const details = aggregatedRow.locator('details');
    await expect(details).toHaveCount(1);

    const summary = details.locator('summary');
    await expect(summary).toBeVisible();

    // Closed by default.
    await expect(details).not.toHaveAttribute('open', '');

    // Keyboard-operable: Tab to the summary, Enter toggles it open.
    await summary.focus();
    await expect(summary).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(details).toHaveAttribute('open', '');
  });
});

test.describe('#129 ST-7 — Activity feed: group scope variant', () => {
  test('/activity/group/<gid> shows only that group\'s rows', async ({ page }) => {
    await switchToElena(page);

    // Discover Elena's "Camp Organizers EMEA" group id via the my_groups
    // feed's own rendered link (never hardcoding a numeric gid, which would
    // be brittle across re-seeded environments) — the content row's group
    // link href carries the canonical /group/{gid} path.
    await page.goto('/activity');
    const groupLink = page.locator(`${CONTENT_ROW_SELECTOR} a[href^="/group/"]`).first();
    await expect(groupLink).toBeVisible();
    const href = await groupLink.getAttribute('href');
    expect(href).toBeTruthy();
    const gidMatch = href?.match(/\/group\/(\d+)/);
    expect(gidMatch).not.toBeNull();
    const gid = gidMatch?.[1];

    const response = await page.goto(`/activity/group/${gid}`);
    expect(response?.status()).toBe(200);

    const shell = page.locator(SHELL_SELECTOR);
    await expect(shell).toBeVisible();

    // Every row rendered scopes to this one group — no row's group link
    // points anywhere else.
    const rowGroupLinks = page.locator(
      `${SOCIAL_ROW_SELECTOR} a[href^="/group/"], ${CONTENT_ROW_SELECTOR} a[href^="/group/"], ${AGGREGATED_ROW_SELECTOR} a[href^="/group/"]`,
    );
    const count = await rowGroupLinks.count();
    expect(count).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
      await expect(rowGroupLinks.nth(i)).toHaveAttribute('href', new RegExp(`^/group/${gid}(?:[/?]|$)`));
    }
  });
});

test.describe('#129 ST-7 — Activity feed: empty state', () => {
  test('a user in no groups sees the empty state, not an error or generic "no results"', async ({ page }) => {
    // The anonymous/default persona (no persona switch) is not a member of
    // any group in the seeded demo data.
    const response = await page.goto('/activity');
    expect(response?.status()).toBe(200);

    const empty = page.locator('[data-testid="activity-feed-empty"]');
    await expect(empty).toBeVisible();
    await expect(page.locator(SOCIAL_ROW_SELECTOR)).toHaveCount(0);
    await expect(page.locator(CONTENT_ROW_SELECTOR)).toHaveCount(0);
    await expect(page.locator(AGGREGATED_ROW_SELECTOR)).toHaveCount(0);
  });
});
