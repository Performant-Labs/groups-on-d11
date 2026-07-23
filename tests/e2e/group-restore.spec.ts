import { test, expect, Page } from '@playwright/test';

/**
 * Issue #143 (MC-5) — Group archiving RESTORE action, e2e round-trip.
 *
 * Runs against the real seeded site (docs/groups/scripts/step_720_group_types.php
 * tags "Legacy Infrastructure" as Archive-typed; step_700 seeds the group
 * itself — this spec does NOT touch either seed step, per the brief's
 * "coordinate, do NOT edit" boundary with SD-3 #128).
 *
 * Follows the wireframe's Surface 4 explicit 5-step sequence (also AC-8):
 *   1. Assert Legacy Infrastructure is Archive-typed (badge visible, Restore
 *      tab visible).
 *   2. Navigate /group/{gid}/restore, keep default "Working group", submit.
 *   3. Assert redirect to canonical, success flash, badge gone, Restore tab
 *      gone.
 *   4. Navigate /group/{gid}/edit, set Group Type back to "Archive" via the
 *      existing widget, save.
 *   5. Assert badge returns and Restore tab returns.
 *
 * Persona: site admin (`administer group` site-wide escape hatch) — the
 * seeded site's ADMIN_USER/ADMIN_PASS, matching manage-members.spec.ts's own
 * persona choice absent a dedicated seeded-Organizer login helper.
 *
 * Self-contained: no imports from other specs, own login/lookup helpers.
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

/**
 * Resolves the seeded "Legacy Infrastructure" group's numeric id.
 *
 * T-green fix (2026-07-22): the original implementation searched the public
 * `/all-groups` directory (the `all_groups` View), but that View hard-filters
 * `status = 1` (published-only) per
 * `docs/groups/config/views.view.all_groups.yml` lines 82-93. step_700's
 * archive simulation sets Legacy Infrastructure's `status` to 0 (unpublished)
 * to model "archived" visually, so the group is — correctly, by design —
 * absent from that public listing even for an authenticated admin, since the
 * View's filter is a hardcoded value, not an access-check. Asserting the
 * group would appear there was a test-authorship bug in this spec, not a
 * site/production defect: `/admin/group` (the Group module's own admin
 * collection, unfiltered by publish status) is the correct, robust lookup
 * surface, and is stable across seed-order changes exactly as the original
 * intent required.
 */
async function findLegacyInfrastructureGid(page: Page): Promise<string> {
  await page.goto('/admin/group');
  const link = page.getByRole('link', { name: /Legacy Infrastructure/i }).first();
  await expect(link).toBeVisible();
  const href = await link.getAttribute('href');
  const gid = href?.match(/\/group\/(\d+)/)?.[1];
  expect(gid, 'Legacy Infrastructure has a numeric group id').toBeTruthy();
  return gid as string;
}

test.describe('Group archiving RESTORE action (#143 MC-5)', () => {
  test('archive -> restore -> archive round-trip on the seeded Legacy Infrastructure group', async ({
    page,
  }) => {
    await login(page);
    const gid = await findLegacyInfrastructureGid(page);

    // --- Step 1: preconditions (Archive-typed) ---------------------------
    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/Archived/i).first()).toBeVisible();
    await expect(page.locator('span.group__archived-badge')).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();

    // #143 AC-8 (per O adjudication 2026-07-22, ruling c): archived-state
    // observability asserted via badge + Restore-tab visibility rather than
    // node-create denial. The site-wide "add-content-on-archived" enforcement
    // runs through drupal/group's _group_relationship_create_any_entity_access,
    // which does not invoke hook_node_access, so DoGroupExtrasHooks::nodeAccess()
    // is not consulted on that route. Pre-existing gap unrelated to #143;
    // out-of-scope per POC posture (documented in the PR body).

    // --- Step 2: restore, keeping default "Working group" ----------------
    await page.goto(`/group/${gid}/restore`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByLabel(/Set group type to/i)).toHaveValue(/.*/);
    await page.getByRole('button', { name: /Restore group/i }).click();

    // --- Step 3: post-restore assertions ---------------------------------
    await page.waitForURL(new RegExp(`/group/${gid}$`));
    await expect(page.locator('.messages--status')).toBeVisible();
    await expect(page.locator('.messages--status')).toContainText(/restored/i);
    await expect(page.getByText(/Archived/i)).toHaveCount(0);
    await expect(page.locator('span.group__archived-badge')).toHaveCount(0);
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toHaveCount(0);

    // --- Step 4: re-archive via the existing group edit form --------------
    await page.goto(`/group/${gid}/edit`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel(/Group Type/i).selectOption({ label: 'Archive' });
    await page.getByRole('button', { name: /^Save$/i }).click();

    // --- Step 5: badge + tab return (observable-swap mirror, see Step 1) ---
    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText(/Archived/i).first()).toBeVisible();
    await expect(page.locator('span.group__archived-badge')).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();
  });
});
