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
 * #128 T-green fix (2026-07-23): the `/admin/group` workaround from #143's
 * T-green (2026-07-22) is reverted. That workaround existed only because
 * step_700's archive simulation set Legacy Infrastructure's `status` to 0
 * (unpublished), which the `all_groups` View hard-filters out
 * (`status = 1`, `docs/groups/config/views.view.all_groups.yml` lines
 * 82-93) regardless of viewer permissions. #128 removes that redundant
 * `set('status', 0)` line: Legacy Infrastructure is now PUBLISHED +
 * Archive-typed (the correct runbook semantic — Archived = visible +
 * read-only + badge, distinct from Unpublished = hidden), so it appears on
 * `/all-groups` like any other seeded group. The lookup is simplified back
 * to the anonymous surface, matching #128's own AC-1 assertion path
 * (demonstrator-seeds.spec.ts) exactly. The admin login below is still
 * needed for Step 4's edit-form path, so it is kept, but is no longer a
 * precondition for THIS lookup — the lookup now happens on the public
 * surface, before login, matching how an anonymous visitor would actually
 * find the group.
 */
async function findLegacyInfrastructureGid(page: Page): Promise<string> {
  await page.goto('/all-groups');
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
    // Lookup happens on the public /all-groups surface, unauthenticated,
    // BEFORE login — this is #128's point: an anonymous visitor can find
    // this group without ever logging in.
    const gid = await findLegacyInfrastructureGid(page);

    await login(page);

    // --- Step 1: preconditions (Archive-typed) ---------------------------
    // NOTE (T-green #128, 2026-07-23): the free-text `getByText(/Archived/i)`
    // assertion that used to sit here has been REMOVED. Legacy Infrastructure's
    // own seeded `field_group_description` ("Archived: Drupal 7 module
    // maintenance coordination...", step_700_demo_data.php:75) permanently
    // contains the literal word "Archived" regardless of archive/restore
    // state, so any unscoped `getByText(/Archived/i)` match against this
    // group's canonical page is ambiguous between that static description
    // copy and the real `span.group__archived-badge`. The badge locator
    // below is the correct, unambiguous signal for this precondition; the
    // free-text check added nothing beyond it and is dropped rather than
    // reworded, per test-quality guidance against duplicate assertions of
    // the same behavior.
    await page.goto(`/group/${gid}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('span.group__archived-badge')).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();

    // --- Step 1b: positive enforcement assertion (#128 AC-1c hardening) ---
    // #128's T-RED (demonstrator-seeds.spec.ts) empirically determined that
    // `/group/{gid}/content/create/group_node%3Aforum` IS gated by the
    // archive state for an authenticated non-Organizer user (403 on this
    // Archive-typed group; 200 on a non-archived control group for the same
    // user/route) — contrary to the earlier #143-era assumption (see the
    // superseded comment this replaces) that this route bypassed
    // `hook_node_access` entirely. The site admin used in THIS spec has the
    // `administer group` bypass, so it is NOT the right persona to prove
    // the *enforced* (non-bypassed) case — that positive assertion lives in
    // demonstrator-seeds.spec.ts's AC-1c test with the elena_garcia
    // non-Organizer persona. This step instead re-confirms the ADMIN-side
    // observable (badge + Restore-tab visibility) is what this spec has
    // always asserted above, and notes where the enforcement proof lives so
    // a future reader doesn't need to rediscover it:
    // -> demonstrator-seeds.spec.ts, test "AC-1c: content-create is denied
    //    on the archived group but allowed on a non-archived control group".

    // --- Step 2: restore, keeping default "Working group" ----------------
    await page.goto(`/group/${gid}/restore`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByLabel(/Set group type to/i)).toHaveValue(/.*/);
    await page.getByRole('button', { name: /Restore group/i }).click();

    // --- Step 3: post-restore assertions ---------------------------------
    // NOTE (T-green #128, 2026-07-23): same fix as Step 1 — the free-text
    // `getByText(/Archived/i)).toHaveCount(0)` assertion that used to sit
    // here was a FALSE assertion, not a flake: this group's
    // `field_group_description` always renders the word "Archived" on its
    // canonical page regardless of archive/restore state (see Step 1's
    // note), so `toHaveCount(0)` could never legitimately pass and the test
    // failed deterministically every run at this line, aborting before
    // Step 4 ever re-archived the group (leaving the seed in a corrupted
    // "Working group" state for the next run — a compounding failure).
    // The badge-scoped assertion below is the correct, sufficient check
    // that the archived state is gone post-restore.
    await page.waitForURL(new RegExp(`/group/${gid}$`));
    await expect(page.locator('.messages--status')).toBeVisible();
    await expect(page.locator('.messages--status')).toContainText(/restored/i);
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
    await expect(page.locator('span.group__archived-badge')).toBeVisible();
    await expect(
      page.getByRole('link', { name: /Restore group/i }),
    ).toBeVisible();
  });
});
