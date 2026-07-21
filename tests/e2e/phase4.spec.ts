import { test, expect, Page } from '@playwright/test';

/**
 * Phase-4/5 E2E — surfacing features: pins, mission & contribution-stats
 * blocks, and per-post notification controls — groups-on-d11
 * (Drupal 11.4 + drupal/group 4.x). Phase 5 is folded in here (the follow /
 * notification-suppress surfaces below) rather than a separate phase5.spec.ts.
 *
 * Self-contained (own login + seed helpers), self-seeding (unique names /
 * block ids per run), independent. Does not import phase1.spec.ts or modify
 * playwright.config.ts. The whole file runs serially (config workers=1,
 * fullyParallel=false).
 *
 * WHAT THE REAL UI EXPOSES (explored against the assembled build):
 *   - do_group_pin: a "Pin in group" flag link on a node's full page
 *     (/node/{nid}); flagging toggles the link to "Unpin". Pinned nodes LEAD
 *     the per-group stream (/group/{gid}/stream) ahead of newer-but-unpinned
 *     nodes — this validates the #52 pinned-first ordering fix END-TO-END
 *     through the rendered view (kernel-tested SQL; here we prove the DOM order
 *     on the real page).
 *   - do_group_mission block (plugin `do_group_mission`): renders the group's
 *     description as `.group-mission` on a group page.
 *   - do_profile_stats "Contribution Stats" block (plugin
 *     `do_contribution_stats`): renders `.pl-contribution-stats` (Forum Topics
 *     / Events / Comments / Groups / Days Active) on a /user/{uid} page.
 *   - do_notifications: a "Do not send notifications for this post" checkbox
 *     (name `do_notifications_suppress`) on the group-node create form.
 *   - Follow flag (`follow_content`): a "Follow content" flag link on a node,
 *     toggling to "Unfollow content".
 *
 * BLOCK PLACEMENT NOTE (why these tests place blocks first): in the assembled
 * config the mission and contribution-stats blocks are placed ONLY in the
 * `bluecheese` theme, which is not installed — the active default front-end
 * theme is `groups_chrome` (the #80 community subtheme) — so neither block
 * renders in the default UI as shipped. To validate the block PLUGINS through
 * the real theme/block layer, each test first places its block into the active
 * front-end theme via the core block-placement admin form (a real, self-seeding
 * UI step with a unique machine name), then asserts the render. This is a
 * genuine E2E validation of the plugin output; it does not modify the repo's
 * committed block config.
 *
 * NO MEANINGFUL E2E SURFACE (documented, per #43, not faked):
 *   - do_discovery iCal feeds — already covered by kernel tests (#40); the feed
 *     is a non-interactive XML/text response with no UI journey to drive.
 *   - do_notifications delivery — a queue worker with no user-facing UI beyond
 *     the per-post suppress checkbox asserted below; there is no notification
 *     inbox/settings journey in this build to exercise.
 */

const ADMIN_USER = process.env.ADMIN_USER ?? 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS ?? 'admin';

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

async function createGroup(page: Page, title: string): Promise<string> {
  await page.goto('/group/add/community_group');
  await page.getByLabel('Title', { exact: false }).fill(title);
  const desc = page.locator('.ck-editor__editable').first();
  await desc.click();
  await desc.pressSequentially(`Mission text for ${title}.`);
  await page
    .locator('input[name="field_group_visibility"][value="open"]')
    .check();
  await page.getByRole('button', { name: /Create Community Group/i }).click();
  await page.waitForURL(/\/group\/\d+(\/|$)/);
  return page.url().match(/\/group\/(\d+)/)![1];
}

async function createPost(
  page: Page,
  gid: string,
  title: string,
): Promise<string> {
  await page.goto(`/group/${gid}/content/create/group_node%3Apost`, {
    waitUntil: 'domcontentloaded',
  });
  await page.getByLabel('Title', { exact: false }).first().fill(title);
  await Promise.all([
    page.waitForURL(/\/node\/\d+/),
    page.getByRole('button', { name: /^Save/ }).click(),
  ]);
  return page.url().match(/\/node\/(\d+)/)![1];
}

// Front-end theme the site actually serves. groups_chrome (the #80 community
// subtheme) is the default theme, so blocks must be placed into IT to render on
// public pages — placing into olivero would save fine but never appear, since
// olivero is no longer the active front-end theme. Overridable for parity with
// BASE_URL/ADMIN_* if a run targets a different default.
const FRONTEND_THEME = process.env.FRONTEND_THEME ?? 'groups_chrome';

/**
 * Place a custom block plugin into the active front-end theme's `content`
 * region via the core block-placement admin form, using a unique machine name
 * so re-runs never collide. `contextGroupRoute` maps the block's group context
 * to the route (needed by the mission block). Returns the rendered block
 * wrapper id; asserts the save succeeded.
 */
async function placeBlockInFrontendTheme(
  page: Page,
  pluginId: string,
  contextGroupRoute = false,
): Promise<string> {
  const machineId = `e2e_${pluginId}_${Date.now()}`.replace(/[^a-z0-9_]/g, '_');
  await page.goto(`/admin/structure/block/add/${pluginId}/${FRONTEND_THEME}`, {
    waitUntil: 'domcontentloaded',
  });
  await page.locator('select[name="region"]').selectOption('content');
  if (contextGroupRoute) {
    await page
      .locator('select[name="settings[context_mapping][group]"]')
      .selectOption('@group.group_route_context:group');
  }
  // The block machine name is a JS "machine-name" widget whose <input name=id>
  // is hidden; set its value directly and fire input/change so Drupal accepts
  // it on submit (a reliable alternative to driving the reveal/Edit button).
  await page.evaluate((v) => {
    const el = document.querySelector<HTMLInputElement>('input[name="id"]');
    if (el) {
      el.value = v;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }, machineId);
  await page.getByRole('button', { name: 'Save block' }).click();
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('.messages--error')).toHaveCount(0);
  // Drupal renders the block wrapper id with dashes: block-<machine-with-dashes>.
  return `block-${machineId.replace(/_/g, '-')}`;
}

test.describe('Phase 4/5 — pins, blocks & notification controls', () => {
  test('pinned content leads the group stream (#52)', async ({ page }) => {
    await login(page);

    // CONTROL group (never pinned): proves the default stream order is
    // `created DESC` — newest first. A separate group so its render cache is
    // independent of the pinned group's.
    const stamp = Date.now();
    const ctlGid = await createGroup(page, `PinCtl ${stamp}`);
    await createPost(page, ctlGid, `Ctl AAA older ${stamp}`);
    await page.waitForTimeout(1100);
    const ctlNewer = `Ctl ZZZ newer ${stamp}`;
    await createPost(page, ctlGid, ctlNewer);
    await page.goto(`/group/${ctlGid}/stream`, { waitUntil: 'networkidle' });
    expect(
      (await page.locator('.views-row .views-field-title a').allInnerTexts())[0],
      'without pinning, the newest post leads (created DESC)',
    ).toBe(ctlNewer);

    // PINNED group: older post first, newer post second.
    const gid = await createGroup(page, `PinG ${stamp}`);
    const olderTitle = `AAA older ${stamp}`;
    const older = await createPost(page, gid, olderTitle);
    await page.waitForTimeout(1100); // ensure a distinct, later `created`.
    const newerTitle = `ZZZ newer ${stamp}`;
    await createPost(page, gid, newerTitle);

    // Pin the OLDER post via its node-page flag link — BEFORE ever loading this
    // group's stream, so the stream's first (and only) render is a cache-miss
    // that already reflects the pin. (do_group_pin does not invalidate the
    // stream view's render cache on a pin toggle, so a stream cached pre-pin
    // would otherwise be served stale — see the report's cache-lag finding.)
    await page.goto(`/node/${older}`, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      page.waitForURL(new RegExp(`/node/${older}`)),
      page.getByRole('link', { name: 'Pin in group' }).first().click(),
    ]);
    await expect(
      page.getByRole('link', { name: 'Unpin' }).first(),
    ).toBeVisible();

    // The older-but-pinned post now LEADS the stream ahead of the newer
    // unpinned post — the #52 pinned-first ordering, on the real rendered view.
    await page.goto(`/group/${gid}/stream`, { waitUntil: 'networkidle' });
    const titles = await page
      .locator('.views-row .views-field-title a')
      .allInnerTexts();
    expect(titles[0], 'pinned older post leads the stream').toBe(olderTitle);
    expect(titles).toContain(newerTitle);
    expect(
      titles.indexOf(olderTitle),
      'pinned post precedes the newer post',
    ).toBeLessThan(titles.indexOf(newerTitle));
  });

  test('group mission block renders the group description', async ({ page }) => {
    await login(page);
    const gid = await createGroup(page, `MissionG ${Date.now()}`);
    const blockId = await placeBlockInFrontendTheme(page, 'do_group_mission', true);

    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    // Scope to the block this test just placed (the shared site may carry other
    // mission blocks from other tests). The block renders the group's
    // description inside `.group-mission`.
    const mission = page.locator(`#${blockId} .group-mission`);
    await expect(mission).toBeVisible();
    await expect(mission).toContainText('Mission text for MissionG');
  });

  test('contribution-stats block renders on a user profile', async ({
    page,
  }) => {
    await login(page);
    const blockId = await placeBlockInFrontendTheme(
      page,
      'do_contribution_stats',
      false,
    );

    // The block reads the profile owner from the `user` route parameter, so it
    // renders on /user/{uid}. (Its build now declares the `url` cache context —
    // see ContributionStatsBlock::getCacheContexts — so each route caches its
    // own build instead of reusing an empty first render.)
    await page.goto('/user/1', { waitUntil: 'domcontentloaded' });
    // Scope to the block this test placed (site may carry other stats blocks).
    const stats = page.locator(`#${blockId} .pl-contribution-stats`);
    await expect(stats).toBeVisible();
    // The template renders a fixed set of stat labels.
    await expect(stats.getByText('Groups', { exact: true })).toBeVisible();
    await expect(stats.getByText('Days Active', { exact: true })).toBeVisible();
  });

  test('per-post "do not send notifications" checkbox is on the create form', async ({
    page,
  }) => {
    await login(page);
    const gid = await createGroup(page, `NotifG ${Date.now()}`);
    await page.goto(`/group/${gid}/content/create/group_node%3Apost`, {
      waitUntil: 'domcontentloaded',
    });
    const suppress = page.getByLabel(/Do not send notifications/i);
    await expect(suppress).toBeVisible();
    // It is a togglable checkbox, unchecked by default (notifications on).
    await expect(suppress).not.toBeChecked();
    await suppress.check();
    await expect(suppress).toBeChecked();
  });

  test('follow-content flag toggles on a node', async ({ page }) => {
    await login(page);
    const gid = await createGroup(page, `FollowG ${Date.now()}`);
    const nid = await createPost(page, gid, `Followable ${Date.now()}`);

    await page.goto(`/node/${nid}`, { waitUntil: 'domcontentloaded' });
    await Promise.all([
      page.waitForURL(new RegExp(`/node/${nid}`)),
      page.getByRole('link', { name: 'Follow content' }).first().click(),
    ]);
    // After following, the flag link flips to its unflag label.
    await expect(
      page.getByRole('link', { name: 'Unfollow content' }).first(),
    ).toBeVisible();
  });
});
