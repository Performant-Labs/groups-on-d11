import { test, expect, Page } from '@playwright/test';

/**
 * Phase-3 E2E — group content & stream — groups-on-d11
 * (Drupal 11.4 + drupal/group 4.x).
 *
 * Self-contained (own login + seed helpers, copied from the phase-1 pattern),
 * self-seeding (unique group/post names per run), and independent. Does not
 * import phase1.spec.ts or modify playwright.config.ts.
 *
 * WHAT THE REAL UI EXPOSES (explored against the assembled build):
 *   - Group content is created at
 *       /group/{gid}/content/create/group_node%3A<type>
 *     for types documentation|event|forum|page|post. The create form's submit
 *     is "Save"; it redirects to the new /node/{nid}.
 *   - The per-group stream is the Views page `group_content_stream` at
 *       /group/{gid}/stream
 *     (an AJAX view, `created DESC`). A node created in a group appears there.
 *   - do_multigroup surfaces a "Group Audience" fieldset on the node form as
 *     `group_ids[N]` checkboxes (one per group the user belongs to; the group
 *     you post FROM is pre-checked). This spec asserts that UI surface renders.
 *
 * KNOWN-BROKEN, DELIBERATELY NOT ASSERTED AS PASSING (a finding, not a test
 * gap — see the report / a follow-up issue):
 *   - do_multigroup CROSS-POST persistence through the form is broken in this
 *     build. Ticking a second group's checkbox on the group-node CREATE form
 *     (verified checked pre-submit) does NOT add the node to that second group
 *     — the saved node lands only in its origin group. On the node EDIT form
 *     the custom submit handler mis-syncs and can even drop the node from its
 *     origin group. do_multigroup's `nodeFormSubmit` handler is not wired
 *     correctly for the Group 4.x group-content form object. The `#39` kernel
 *     test exercises the create() API path, not this UI form path, so this UI
 *     regression is uncaught there. This spec therefore does NOT assert a
 *     working cross-post (that would be faking green); it asserts only the
 *     fieldset surface, and the broken behavior is reported for an issue.
 *
 * NO MEANINGFUL E2E SURFACE (documented, not faked):
 *   - field_group_tags / the /tags/{tid} aggregation view: field_group_tags is
 *     defined in config but is NOT placed on any node form display in the
 *     assembled build, so there is no UI to set a tag on a node, and the
 *     tags_aggregation view takes a taxonomy term-id argument with no seedable
 *     content. Tag filtering therefore has no drivable E2E surface here (its
 *     query is covered at the kernel layer).
 *   - stream_card view mode: the group_content_stream view renders a `fields`
 *     row, not a node "card" view mode, so there is no stream_card render to
 *     assert in this build.
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
  await desc.pressSequentially(`Stream seed group: ${title}.`);
  await page
    .locator('input[name="field_group_visibility"][value="open"]')
    .check();
  await page.getByRole('button', { name: /Create Community Group/i }).click();
  await page.waitForURL(/\/group\/\d+(\/|$)/);
  return page.url().match(/\/group\/(\d+)/)![1];
}

/** Create a `post` group_node in group `gid`. Returns the node id. */
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

/** Titles rendered in a group's stream, in DOM order (top = first). */
async function streamTitles(page: Page, gid: string): Promise<string[]> {
  await page.goto(`/group/${gid}/stream`, { waitUntil: 'networkidle' });
  return page
    .locator('.views-row .views-field-title a')
    .allInnerTexts();
}

test.describe('Phase 3 — Group content & stream', () => {
  test('content created in a group appears in that group stream', async ({
    page,
  }) => {
    await login(page);
    const gid = await createGroup(page, `StreamG ${Date.now()}`);
    const postTitle = `Stream Post ${Date.now()}`;
    await createPost(page, gid, postTitle);

    const titles = await streamTitles(page, gid);
    expect(titles).toContain(postTitle);
  });

  test('do_multigroup renders the group-audience fieldset on the node form', async ({
    page,
  }) => {
    // do_multigroup exposes a "Group Audience" fieldset with one checkbox per
    // group the author belongs to; the group the content is created FROM is
    // pre-checked. We assert this UI surface renders (the cross-post
    // *persistence* through this form is broken in this build and is reported
    // as a finding, not asserted here — see the file header).
    await login(page);
    const stamp = Date.now();
    const groupA = `AudA ${stamp}`;
    const groupB = `AudB ${stamp}`;
    const gidA = await createGroup(page, groupA);
    await createGroup(page, groupB);

    await page.goto(`/group/${gidA}/content/create/group_node%3Apost`, {
      waitUntil: 'domcontentloaded',
    });
    // The multigroup checkboxes render, one per group.
    const boxes = page.locator('input[name^="group_ids"]');
    expect(await boxes.count()).toBeGreaterThanOrEqual(2);
    // The origin group (A) is pre-checked; the just-created group B is offered
    // as an additional, unchecked audience option.
    await expect(page.getByLabel(groupA, { exact: false })).toBeChecked();
    await expect(page.getByLabel(groupB, { exact: false })).not.toBeChecked();
  });

  test('a group stream page loads for its group', async ({ page }) => {
    await login(page);
    const gid = await createGroup(page, `StreamLoad ${Date.now()}`);
    const res = await page.goto(`/group/${gid}/stream`);
    expect(res?.status()).toBe(200);
    // The stream is a rendered Views page in the group's main region.
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('body')).not.toContainText('Access denied');
  });
});
